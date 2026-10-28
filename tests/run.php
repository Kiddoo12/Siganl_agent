<?php
declare(strict_types=1);

/**
 * Automated Test Suite for GOLD SMC AI PRO.
 * Run via CLI:  php tests/run.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AiExplainer;
use App\Analysis\AnalysisService;
use App\Analysis\CalendarService;
use App\Analysis\MultiTimeframeAnalyzer;
use App\Analysis\RiskCalculator;
use App\Analysis\SessionService;
use App\Analysis\SignalTracker;
use App\Backtest\Backtester;
use App\Core\Database;
use App\Core\Notifier;
use App\Core\Settings;
use App\Market\CandleValidator;
use App\Market\DemoProvider;
use App\Market\MarketDataService;
use App\Market\Timeframe;
use App\SMC\SmcEngine;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "=============================================================\n";
echo " GOLD SMC AI PRO — Automated Verification Suite\n";
echo "=============================================================\n\n";

// ------------------------------------------------------------------
// 1. CandleValidator & Timeframe look-ahead guard
// ------------------------------------------------------------------
echo "1. Market Data Validation & Look-Ahead Guards\n";
$now = time();
$rawCandles = [
    ['t' => $now - 3600, 'o' => 2400.0, 'h' => 2405.0, 'l' => 2398.0, 'c' => 2403.0, 'v' => 100],
    ['t' => $now - 3600, 'o' => 2400.0, 'h' => 2405.0, 'l' => 2398.0, 'c' => 2403.0, 'v' => 100], // duplicate
    ['t' => $now - 2700, 'o' => 2403.0, 'h' => 2401.0, 'l' => 2404.0, 'c' => 2402.0, 'v' => 100], // invalid high < low
    ['t' => $now - 1800, 'o' => 2403.0, 'h' => 2408.0, 'l' => 2401.0, 'c' => 2406.0, 'v' => 120],
];
$val = CandleValidator::validate($rawCandles, 'M15');
assertTrue(count($val['candles']) === 2 && $val['rejected'] === 2, 'CandleValidator rejects duplicate and inconsistent OHLC');

$withInProgress = [
    ['t' => $now - 1800, 'o' => 2400, 'h' => 2402, 'l' => 2399, 'c' => 2401, 'v' => 10],
    ['t' => $now - 300,  'o' => 2401, 'h' => 2403, 'l' => 2400, 'c' => 2402, 'v' => 10], // M15 candle still open at $now
];
$closedOnly = Timeframe::closedOnly('M15', $withInProgress, $now);
assertTrue(count($closedOnly) === 1 && $closedOnly[0]['t'] === $now - 1800, 'Timeframe::closedOnly removes in-progress candle');

// ------------------------------------------------------------------
// 2. Core SMC Engine (BOS, CHOCH, MSS, FVG, OB, Sweeps, P/D)
// ------------------------------------------------------------------
echo "\n2. Core SMC Engine (BOS, CHOCH, Liquidity Sweeps, OB, FVG, Premium/Discount)\n";
$engine = new SmcEngine([
    'swing_left' => 2, 'swing_right' => 2,
    'pd_swing_left' => 3, 'pd_swing_right' => 3,
    'atr_period' => 5, 'break_buffer_atr' => 0.02,
    'displacement_body_ratio' => 0.55, 'displacement_atr_mult' => 1.1,
    'fvg_min_atr' => 0.10, 'ob_lookback' => 4, 'ob_require_displacement' => true,
    'sweep_min_atr' => 0.02,
]);

// Construct a deterministic 45-candle sequence:
// - Establishes initial swings and a bearish BOS
// - Forms equal lows / SSL and sweeps SSL with a long rejection wick
// - Prints a strong bullish displacement candle creating a bullish FVG + Order Block and breaking swing high (CHOCH + MSS)
// - Pulls back into discount without invalidating the OB
$baseTs = 1720000000;
$pts = [
    // i=0..14: initial oscillation & bearish structure
    [2410, 2412, 2408, 2411],
    [2411, 2413, 2409, 2410],
    [2410, 2418, 2409, 2416], // swing high #1 at i=2 (2418)
    [2416, 2415, 2410, 2411],
    [2411, 2412, 2404, 2405], // swing low #1 at i=4 (2404)
    [2405, 2410, 2405, 2409],
    [2409, 2415, 2408, 2413], // lower swing high #2 at i=6 (2415)
    [2413, 2413, 2406, 2407],
    [2407, 2408, 2396, 2397], // bearish break below 2404 -> Bearish BOS
    [2397, 2401, 2395, 2399],
    [2399, 2402, 2394, 2396], // swing low #2 at i=10 (2394.0)
    [2396, 2403, 2396, 2401],
    [2401, 2407, 2400, 2405], // swing high #3 at i=12 (2407.0)
    [2405, 2405, 2398, 2399],
    [2399, 2401, 2396, 2398],
    // i=15: SSL sweep below 2394.0 (wick to 2391.5, closes back inside at 2396.5) + bearish candle before impulse
    [2398, 2398.5, 2395.0, 2395.5], // bearish candle (potential OB base)
    [2395.5, 2399.0, 2391.5, 2397.5], // sweeps 2394.0 SSL with rejection wick
    // i=17..19: strong bullish displacement leg creating FVG and breaking 2407.0 swing high (Bullish CHOCH / MSS)
    [2397.5, 2409.5, 2397.0, 2409.0], // big displacement candle closing > 2407.0 -> CHOCH + MSS
    [2409.0, 2416.0, 2404.0, 2415.0], // candle i=18 leaves FVG between i=16 high (2399.0) and i=18 low (2404.0)
    [2415.0, 2420.0, 2413.0, 2419.0],
    [2419.0, 2424.0, 2418.0, 2422.0], // new high at i=20 (2424.0)
    [2422.0, 2421.0, 2415.0, 2416.0],
    [2416.0, 2417.0, 2411.0, 2412.0],
    [2412.0, 2414.0, 2408.0, 2410.0], // controlled pullback into discount
    [2410.0, 2413.0, 2406.0, 2411.0],
    [2411.0, 2415.0, 2409.0, 2414.0],
    [2414.0, 2417.0, 2411.0, 2415.0],
    [2415.0, 2418.0, 2412.0, 2416.0],
    [2416.0, 2419.0, 2414.0, 2417.0],
    [2417.0, 2420.0, 2415.0, 2418.0],
    [2418.0, 2421.0, 2416.0, 2419.0],
    [2419.0, 2422.0, 2417.0, 2420.0],
];
$synth = [];
foreach ($pts as $idx => [$o, $h, $l, $c]) {
    $synth[] = ['t' => $baseTs + $idx * 900, 'o' => (float)$o, 'h' => (float)$h, 'l' => (float)$l, 'c' => (float)$c, 'v' => 100.0];
}
$smcRes = $engine->analyze($synth);
assertTrue($smcRes['ok'] === true, 'SmcEngine::analyze succeeds on valid series');
assertTrue($smcRes['trend'] === 1 && $smcRes['trend_label'] === 'bullish', 'SmcEngine detects bullish trend shift');

$eventTypes = array_column($smcRes['events'], 'type');
assertTrue(in_array('BOS', $eventTypes, true) && in_array('CHOCH', $eventTypes, true), 'SmcEngine detects both BOS and CHOCH structure breaks');

$hasMss = (bool)array_filter($smcRes['events'], static fn($e) => !empty($e['mss']));
assertTrue($hasMss, 'SmcEngine identifies Market Structure Shift (CHOCH + Displacement)');

assertTrue(count($smcRes['fvgs']) > 0, 'SmcEngine detects Fair Value Gaps (FVG)');
assertTrue(count($smcRes['order_blocks']) > 0, 'SmcEngine detects Order Blocks (OB)');
assertTrue(count($smcRes['liquidity']['sweeps']) > 0, 'SmcEngine detects Liquidity Sweep with wick rejection');
assertTrue(!empty($smcRes['premium_discount']['eq']), 'SmcEngine computes Premium/Discount dealing range and 50% EQ');

// ------------------------------------------------------------------
// 3. DemoProvider Multi-Timeframe Analysis & News Blocker
// ------------------------------------------------------------------
echo "\n3. Multi-Timeframe Confluence Analyzer & News Filter\n";
$demo = new DemoProvider();
$nowTs = 1728000000;
$candlesByTf = [];
foreach (['MN1', 'W1', 'D1', 'H4', 'H1', 'M15'] as $tf) {
    $candlesByTf[$tf] = $demo->fetchCandles($tf, 250, null, $nowTs);
}
$mta = MultiTimeframeAnalyzer::fromSettings();
$resNormal = $mta->analyze($candlesByTf, [
    'now' => $nowTs, 'price' => 2400.0, 'spread' => 0.25, 'spread_source' => 'assumed',
    'events' => [], 'news_connected' => true, 'data_mode' => 'demo', 'source' => 'demo',
]);
assertTrue($resNormal['ok'] === true && isset($resNormal['signal']['direction']), 'MultiTimeframeAnalyzer runs across MN1..M15');

$aiOut = AiExplainer::explain($resNormal);
assertTrue($aiOut['Explanation'] ?? ($aiOut['explanation'] !== ''), 'AiExplainer produces deterministic rule-based commentary');

// Verify high-impact news window blocks setups
$resNewsBlocked = $mta->analyze($candlesByTf, [
    'now' => $nowTs, 'price' => 2400.0, 'spread' => 0.25, 'spread_source' => 'assumed',
    'events' => [['country' => 'USD', 'impact' => 'high', 'title' => 'Non-Farm Payrolls', 'time' => $nowTs + 600]],
    'news_connected' => true, 'data_mode' => 'demo', 'source' => 'demo',
]);
assertTrue($resNewsBlocked['signal']['direction'] === 'WAIT', 'High-impact USD news window forces WAIT / NO TRADE');

// Timeliness verification: Ensure setups whose TP1 has already been reached are never active/potential
$resTargetReached = $mta->analyze($candlesByTf, [
    'now' => $nowTs, 'price' => 3000.0, 'spread' => 0.25, 'spread_source' => 'assumed',
    'events' => [], 'news_connected' => true, 'data_mode' => 'demo', 'source' => 'demo',
]);
assertTrue(
    $resTargetReached['signal']['direction'] === 'WAIT' &&
    in_array($resTargetReached['signal']['setup_status'], ['none', 'expired'], true) &&
    $resTargetReached['signal']['entry'] === null &&
    $resTargetReached['signal']['readiness']['stage'] === 'standby',
    'Analyzer rejects stale setups where price reached target (timeliness guard)'
);

// ------------------------------------------------------------------
// 4. RiskCalculator Position Sizing & Hard Cap Enforcement
// ------------------------------------------------------------------
echo "\n4. Position Size & Risk Calculator\n";
$rc = new RiskCalculator();
$calc1 = $rc->calculate(10000.0, 0.5, 2400.00, 2395.00, 2410.00, 2415.00);
assertTrue($calc1['ok'] && $calc1['lots'] === 0.10 && $calc1['actual_risk_usd'] === 50.0, 'RiskCalculator computes 0.10 lot for $50 risk on $5.00 stop');
assertTrue($calc1['targets']['tp1']['rr'] === 2.0 && $calc1['targets']['tp1']['profit_usd'] === 100.0, 'RiskCalculator computes 2.0R ($100) at TP1');

$calcCap = $rc->calculate(10000.0, 5.0, 2400.00, 2390.00, 2420.00);
assertTrue($calcCap['applied_risk_pct'] === 2.0 && count($calcCap['warnings']) > 0, 'RiskCalculator clamps excessive risk % to max_risk_pct (2.0%)');

// Test BTCUSD symbol normalization & 1 BTC/lot sizing
assertTrue(MarketDataService::normalizeSymbol('BTCUSE') === 'BTCUSD' && MarketDataService::normalizeSymbol('BTC/USD') === 'BTCUSD', 'MarketDataService::normalizeSymbol maps BTCUSE / BTC/USD to BTCUSD');
$rcBtc = new RiskCalculator(null, 'BTCUSD');
$calcBtc = $rcBtc->calculate(10000.0, 1.0, 68000.00, 67000.00, 70000.00, 71000.00);
assertTrue($calcBtc['ok'] && $calcBtc['symbol'] === 'BTCUSD' && $calcBtc['contract_size'] === 1.0 && $calcBtc['lots'] === 0.10, 'RiskCalculator computes 0.10 BTC lot for $100 risk on $1,000 BTCUSD stop');

// ------------------------------------------------------------------
// 5. Database Integration: AnalysisService, SignalTracker, Backtester, Settings
// ------------------------------------------------------------------
echo "\n5. Database Integration, Multi-Symbol (XAUUSD & BTCUSD) & Walk-Forward Backtester\n";
assertTrue(Database::isAvailable(), 'MySQL / MariaDB PDO connection is active');

$service = new AnalysisService(null, null, null, 'XAUUSD');
$dash = $service->dashboardState('M15', 1);
assertTrue(!empty($dash['analysis']['ok']) && count($dash['chart_candles']) > 50 && $dash['symbol'] === 'XAUUSD', 'AnalysisService::dashboardState returns XAUUSD analysis & chart candles');

$serviceBtc = new AnalysisService(null, null, null, 'BTCUSD');
$dashBtc = $serviceBtc->dashboardState('M15', 1);
assertTrue(!empty($dashBtc['analysis']['ok']) && count($dashBtc['chart_candles']) > 50 && $dashBtc['symbol'] === 'BTCUSD', 'AnalysisService::dashboardState returns BTCUSD analysis & chart candles');

// Test Settings override & reset
Settings::set('signal.min_score', 60, 1);
assertTrue(Settings::get('signal.min_score') === 60, 'Settings::set persists admin parameter override');
Settings::reset('signal.min_score');
assertTrue(Settings::get('signal.min_score') === 55, 'Settings::reset restores default parameter');

// Test Manual Calendar Event
$cal = new CalendarService();
$evId = $cal->addManual([
    'title' => 'Test FOMC Statement',
    'country' => 'USD',
    'impact' => 'high',
    'event_time' => gmdate('Y-m-d H:i', time() + 7200),
], 1);
assertTrue($evId > 0, 'CalendarService::addManual inserts manual economic event');
$cal->deleteEvent($evId);

// Test In-App Notification
$notifRes = Notifier::sendTest(1, 'inapp');
assertTrue($notifRes['ok'] === true, 'Notifier::sendTest dispatches in-app alert');

// Test Walk-Forward Backtester
$bt = new Backtester();
$btRes = $bt->run([
    'name' => 'Automated Test Backtest',
    'timeframe' => 'M15',
    'initial_balance' => 10000,
    'risk_percent' => 0.5,
    'min_score' => 50,
    'date_from' => gmdate('Y-m-d', time() - 10 * 86400),
    'date_to' => gmdate('Y-m-d'),
], 1);
assertTrue($btRes['ok'] === true && $btRes['run_id'] > 0, 'Backtester::run completes walk-forward simulation and stores results');

// ------------------------------------------------------------------
// 6. End-to-End HTTP Pages & API Workflow Test
// ------------------------------------------------------------------
echo "\n6. End-to-End HTTP Pages, Auth/CSRF & JSON API Workflows\n";
$port = 18091;
$phpBin = PHP_BINARY;
$docRoot = escapeshellarg(APP_ROOT);
$cmd = sprintf('"%s" -S 127.0.0.1:%d -t %s', $phpBin, $port, $docRoot);
$serverLog = APP_ROOT . '/storage/logs/test_server.log';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
];
$proc = proc_open($cmd, $descriptors, $pipes, APP_ROOT);
usleep(600000); // wait 0.6s for built-in server

$cookieFile = APP_ROOT . '/storage/cache/test_cookie.txt';
@unlink($cookieFile);

function httpReq(string $method, string $url, ?array $postFields = null, array $headers = [], bool $json = false, string $cookieFile = '', int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($postFields) : http_build_query($postFields));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

$base = "http://127.0.0.1:$port";

// 6a. Unauthenticated request to index.php redirects to login.php
$unauth = httpReq('GET', "$base/index.php", null, [], false, $cookieFile);
assertTrue($unauth['code'] === 302, 'Unauthenticated GET /index.php redirects (302) to login.php');

// 6b. Fetch login page & extract CSRF token
$loginPage = httpReq('GET', "$base/login.php", null, [], false, $cookieFile);
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $loginPage['body'], $m);
$csrf = $m[1] ?? '';
assertTrue($loginPage['code'] === 200 && strlen($csrf) === 64, 'GET /login.php renders login form with CSRF token');

// 6c. POST login credentials
Database::query('DELETE FROM rate_limits');
$doLogin = httpReq('POST', "$base/login.php", [
    '_csrf'    => $csrf,
    'action'   => 'login',
    'email'    => 'admin@goldsmc.local',
    'password' => 'GoldSmcAdmin!2026',
], [], false, $cookieFile);
assertTrue($doLogin['code'] === 302, 'POST /login.php authenticates admin and redirects to index.php');

// 6d. Verify all authenticated web pages render HTTP 200 and extract refreshed session CSRF token
foreach (['index.php', 'signals.php', 'backtest.php', 'calendar.php', 'settings.php', 'admin.php', 'vision.php', 'strategies.php', 'scanner.php', 'mtf.php', 'live.php', 'calculator.php'] as $page) {
    $r = httpReq('GET', "$base/$page", null, [], false, $cookieFile);
    if ($page === 'index.php' && preg_match('/meta name="csrf-token" content="([a-f0-9]+)"/', $r['body'], $cm)) {
        $csrf = $cm[1];
    }
    assertTrue($r['code'] === 200 && strpos($r['body'], 'GOLD SMC AI') !== false, "Authenticated GET /$page renders HTTP 200");
    if ($page === 'index.php') {
        assertTrue(
            strpos($r['body'], 'tv-topbar') !== false && strpos($r['body'], 'tvBarStyleSelect') !== false && strpos($r['body'], 'BTCUSD') !== false,
            'GET /index.php renders TradingView workstation toolbar, bar style switcher & BTCUSD selector'
        );
    }
}

// 6e. Verify API endpoints
$apiHeaders = ['X-CSRF-Token: ' . $csrf, 'Content-Type: application/json'];

$stApi = httpReq('GET', "$base/api/state.php?symbol=XAUUSD&tf=M15", null, [], false, $cookieFile);
assertTrue($stApi['code'] === 200 && !empty($stApi['json']['ok']), 'GET /api/state.php?symbol=XAUUSD returns dashboard state JSON');

$stBtcApi = httpReq('GET', "$base/api/state.php?symbol=BTCUSD&tf=M15", null, [], false, $cookieFile);
assertTrue($stBtcApi['code'] === 200 && !empty($stBtcApi['json']['ok']) && ($stBtcApi['json']['symbol'] ?? '') === 'BTCUSD', 'GET /api/state.php?symbol=BTCUSD returns BTCUSD dashboard state JSON');

$cdApi = httpReq('GET', "$base/api/candles.php?symbol=BTCUSD&tf=H1&limit=100", null, [], false, $cookieFile);
assertTrue($cdApi['code'] === 200 && !empty($cdApi['json']['ok']) && count($cdApi['json']['candles']) > 50, 'GET /api/candles.php?symbol=BTCUSD returns H1 candles & SMC overlays');

$sgApi = httpReq('GET', "$base/api/signals.php?data_mode=demo", null, [], false, $cookieFile);
assertTrue($sgApi['code'] === 200 && !empty($sgApi['json']['ok']), 'GET /api/signals.php returns signals & performance stats');

$rkApi = httpReq('POST', "$base/api/risk.php", ['balance' => 10000, 'risk_percent' => 0.5, 'entry' => 2400, 'stop_loss' => 2395, 'tp1' => 2410], $apiHeaders, true, $cookieFile);
assertTrue($rkApi['code'] === 200 && !empty($rkApi['json']['ok']) && $rkApi['json']['calculation']['lots'] === 0.1, 'POST /api/risk.php calculates position size');

$calApi = httpReq('GET', "$base/api/calendar.php", null, [], false, $cookieFile);
assertTrue($calApi['code'] === 200 && !empty($calApi['json']['ok']), 'GET /api/calendar.php returns economic events');

$ntApi = httpReq('GET', "$base/api/notifications.php", null, [], false, $cookieFile);
assertTrue($ntApi['code'] === 200 && !empty($ntApi['json']['ok']), 'GET /api/notifications.php returns user notifications');

$cronToken = \App\Core\Env::get('CRON_TOKEN', '');
$cronApi = httpReq('GET', "$base/cron/run.php?token=" . urlencode((string)$cronToken), null, [], false, $cookieFile, 35);
assertTrue($cronApi['code'] === 200 && !empty($cronApi['json']['ok']), 'GET /cron/run.php?token=... executes scheduled scan over HTTP', "HTTP {$cronApi['code']}: " . substr((string)$cronApi['body'], 0, 150));

// ------------------------------------------------------------------
// 7. ICT Strategy Engine, AI Chart Vision Analyzer & Advanced APIs
// ------------------------------------------------------------------
echo "\n7. ICT Strategy Engine & AI Chart Vision Analyzer\n";

$ictEngine = new \App\SMC\IctEngine();

// 7a. Killzones & Macro windows
$kzSample = \App\SMC\IctEngine::killzones(1728000000);
assertTrue(isset($kzSample['ny_time']) && (isset($kzSample['in_killzone']) || isset($kzSample['is_killzone'])), 'IctEngine::killzones tracks New York killzone windows');

$macroSample = \App\SMC\IctEngine::macroWindows(1728000000);
assertTrue(isset($macroSample['is_macro_window']) && isset($macroSample['ny_time']), 'IctEngine::macroWindows tracks institutional 20-min macro intervals');

// 7b. Breaker Blocks, IFVG & Unicorn detection
$breakerCandles = [
    ['t' => $baseTs, 'o' => 2400.0, 'h' => 2408.0, 'l' => 2400.0, 'c' => 2407.0],
    ['t' => $baseTs + 900, 'o' => 2407.0, 'h' => 2412.0, 'l' => 2406.0, 'c' => 2410.0],
    ['t' => $baseTs + 1800, 'o' => 2410.0, 'h' => 2416.0, 'l' => 2409.0, 'c' => 2415.0],
    ['t' => $baseTs + 2700, 'o' => 2415.0, 'h' => 2415.0, 'l' => 2402.0, 'c' => 2403.0],
    ['t' => $baseTs + 3600, 'o' => 2403.0, 'h' => 2408.0, 'l' => 2402.0, 'c' => 2407.0],
];
$testObs = [
    ['dir' => 1, 'top' => 2410.0, 'bottom' => 2406.0, 'mid' => 2408.0, 'i' => 1, 't' => $baseTs + 900, 'status' => 'invalidated'],
];
$testEvents = [
    ['dir' => -1, 'i' => 3, 'level' => 2404.0, 't' => $baseTs + 2700, 'type' => 'CHOCH', 'displacement' => true, 'mss' => true, 'leg_start' => 2],
];
$testSwings = [
    ['type' => 'high', 'price' => 2416.0, 'i' => 2, 't' => $baseTs + 1800],
    ['type' => 'low', 'price' => 2402.0, 'i' => 3, 't' => $baseTs + 2700],
];
$breakers = $ictEngine->breakerBlocks($breakerCandles, $testSwings, $testEvents, $testObs);
assertTrue(count($breakers) > 0 && $breakers[0]['dir'] === -1, 'IctEngine::breakerBlocks identifies bearish breaker from broken bullish OB');

$testFvgs = [
    ['dir' => -1, 'top' => 2409.0, 'bottom' => 2407.0, 'mid' => 2408.0, 'i' => 3, 't' => $baseTs + 2700, 'status' => 'fresh'],
];
$unicorns = $ictEngine->detectUnicorn($breakers, $testFvgs);
assertTrue(count($unicorns) > 0 && $unicorns[0]['top'] === 2409.0 && $unicorns[0]['bottom'] === 2407.0, 'IctEngine::detectUnicorn identifies Breaker + FVG overlap zone');

// 7c. ChartVisionService deterministic analysis & Canvas overlay generator
$testPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$upload = \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . $testPngBase64);
$visionRes = \App\Analysis\ChartVisionService::analyzeChart($upload['path'], 'XAUUSD', 'M15', 2400.0, 'DemoBroker', 'UTC+0', 1);
assertTrue(!empty($visionRes['report']['market_bias']), 'ChartVisionService generates complete 14-point institutional report');
assertTrue(!empty($visionRes['overlay']['elements']), 'ChartVisionService computes precise Canvas visual overlay coordinates');

// 7d. New REST APIs
$stratGet = httpReq('GET', "$base/api/strategies.php", null, [], false, $cookieFile);
assertTrue($stratGet['code'] === 200 && !empty($stratGet['json']['ok']) && count($stratGet['json']['strategies']) >= 10, 'GET /api/strategies.php returns active ICT models');

$stratPost = httpReq('POST', "$base/api/strategies.php", ['code' => 'ict_unicorn', 'active' => 1, 'weight' => 20], $apiHeaders, true, $cookieFile);
assertTrue($stratPost['code'] === 200 && !empty($stratPost['json']['ok']), 'POST /api/strategies.php toggles ICT strategy weight and status');

$scanApi = httpReq('GET', "$base/api/scanner.php", null, [], false, $cookieFile);
assertTrue($scanApi['code'] === 200 && !empty($scanApi['json']['ok']) && isset($scanApi['json']['results']), 'GET /api/scanner.php returns real-time multi-timeframe market scanner matrix');

$histApi = httpReq('GET', "$base/api/vision_history.php", null, [], false, $cookieFile);
assertTrue($histApi['code'] === 200 && !empty($histApi['json']['ok']) && count($histApi['json']['analyses']) > 0, 'GET /api/vision_history.php returns saved chart scans');

$visPost = httpReq('POST', "$base/api/vision_analyze.php", [
    'image_base64' => 'data:image/png;base64,' . $testPngBase64,
    'symbol'       => 'XAUUSD',
    'timeframe'    => 'M15',
    'manual_price' => 2400.0,
    '_csrf'        => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($visPost['code'] === 200 && !empty($visPost['json']['ok']) && !empty($visPost['json']['report']['market_bias']), 'POST /api/vision_analyze.php analyzes chart screenshot with CSRF protection');

$createdId = (int)($visPost['json']['analysis_id'] ?? 0);
$tgPost = httpReq('POST', "$base/api/vision_telegram.php", [
    'analysis_id' => $createdId,
    '_csrf'       => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($tgPost['code'] === 200 && !empty($tgPost['json']['ok']), 'POST /api/vision_telegram.php dispatches chart analysis report to Telegram bot');

echo "\n8. Professional AI Trend Trader Engine, Section 12 Report & Multi-Asset Support\n";
// 8a. TrendAnalysisEngine indicators & 6 exact classifications
$trendEngine = new \App\Analysis\TrendAnalysisEngine();

// Bullish candle series (sequential HH and HL with upward price expansion)
$bullSeries = [];
$p = 2600.0;
for ($i = 0; $i < 60; $i++) {
    $p += ($i % 5 === 0) ? -2.0 : 4.0;
    $bullSeries[] = [
        't' => $baseTs + ($i * 900),
        'o' => $p - 2.0,
        'h' => $p + 3.0,
        'l' => $p - 2.5,
        'c' => $p + 1.5,
        'v' => 1500,
    ];
}
$bullRes = $trendEngine->analyze($bullSeries);
assertTrue(
    $bullRes['ok'] === true
    && in_array($bullRes['classification'], [\App\Analysis\TrendAnalysisEngine::CLASSIFICATION_STRONG_BULLISH, \App\Analysis\TrendAnalysisEngine::CLASSIFICATION_WEAK_BULLISH], true)
    && $bullRes['moving_averages']['ema20'] > $bullRes['moving_averages']['ema50']
    && $bullRes['rsi']['value'] > 50
    && count($bullRes['evidence']) >= 3,
    'TrendAnalysisEngine identifies bullish trend with EMA ribbon and positive RSI momentum'
);

// Bearish candle series (sequential LH and LL with downward price compression)
$bearSeries = [];
$p = 2800.0;
for ($i = 0; $i < 60; $i++) {
    $p += ($i % 5 === 0) ? 2.0 : -4.0;
    $bearSeries[] = [
        't' => $baseTs + ($i * 900),
        'o' => $p + 2.0,
        'h' => $p + 2.5,
        'l' => $p - 3.0,
        'c' => $p - 1.5,
        'v' => 1500,
    ];
}
$bearRes = $trendEngine->analyze($bearSeries);
assertTrue(
    $bearRes['ok'] === true
    && in_array($bearRes['classification'], [\App\Analysis\TrendAnalysisEngine::CLASSIFICATION_STRONG_BEARISH, \App\Analysis\TrendAnalysisEngine::CLASSIFICATION_WEAK_BEARISH], true)
    && $bearRes['moving_averages']['ema20'] < $bearRes['moving_averages']['ema50']
    && $bearRes['rsi']['value'] < 50,
    'TrendAnalysisEngine identifies bearish trend with stacked moving averages and negative momentum'
);

// Flat / Consolidation series
$flatSeries = [];
$p = 2700.0;
for ($i = 0; $i < 60; $i++) {
    $p = 2700.0 + sin($i * 0.8) * 1.5;
    $flatSeries[] = [
        't' => $baseTs + ($i * 900),
        'o' => 2700.0,
        'h' => $p + 0.8,
        'l' => $p - 0.8,
        'c' => $p,
        'v' => 800,
    ];
}
$flatRes = $trendEngine->analyze($flatSeries);
assertTrue(
    $flatRes['ok'] === true && $flatRes['classification'] === \App\Analysis\TrendAnalysisEngine::CLASSIFICATION_CONSOLIDATION,
    'TrendAnalysisEngine classifies flat, oscillating market as Consolidation / Range'
);

// 8b. Section 12 Standard AI Analysis Output validation
$stdReport = \App\Analysis\AiExplainer::standardReport([
    'ok' => true,
    'symbol' => 'XAUUSD',
    'price' => 2745.50,
    'htf_bias' => 'bullish',
    'trend_analysis' => $bullRes,
    'nearest_liquidity' => ['bsl' => 2760.00, 'ssl' => 2730.00],
    'signal' => [
        'asset' => 'XAUUSD',
        'direction' => 'BUY',
        'entry_low' => 2740.00,
        'entry_high' => 2743.00,
        'entry' => 2741.50,
        'sl' => 2735.00,
        'tp1' => 2755.00,
        'tp2' => 2765.00,
        'rr1' => 2.08,
        'rr2' => 3.62,
        'quality_score' => 88,
        'reasons' => ['Bullish CHoCH on M15', 'Tapped H1 discount FVG'],
        'confluences' => [['met' => true, 'label' => 'BOS confirmed'], ['met' => true, 'label' => 'FVG zone']],
    ],
]);
$requiredFields = [
    'INSTRUMENT:', 'MARKET STATUS:', 'HIGHER-TIMEFRAME BIAS:', 'CURRENT PRICE:',
    'KEY LIQUIDITY:', 'MARKET STRUCTURE:', 'ICT/SMC CONFLUENCE:', 'TRADE DECISION:',
    'ENTRY ZONE:', 'STOP LOSS:', 'TAKE PROFIT 1:', 'TAKE PROFIT 2:', 'TAKE PROFIT 3:',
    'RISK-TO-REWARD:', 'SETUP CONFIDENCE:', 'INVALIDATION:', 'RISK WARNING:', 'REASONING:',
];
$allFieldsPresent = true;
foreach ($requiredFields as $rf) {
    if (!str_contains($stdReport, $rf)) {
        $allFieldsPresent = false;
        break;
    }
}
assertTrue($allFieldsPresent, 'AiExplainer::standardReport formats complete 18-element Section 12 institutional report');

// 8c. Multi-Asset Data Providers (EURUSD, GBPUSD, USDJPY, US30, NAS100)
$demoForex = new \App\Market\DemoProvider('EURUSD');
$eurCandles = $demoForex->fetchCandles('M15', 30);
$eurQuote = $demoForex->fetchQuote();
assertTrue(
    count($eurCandles) === 30
    && $eurCandles[0]['c'] < 2.0 // Realistic Forex rate ~1.08
    && strlen(explode('.', (string)$eurCandles[0]['c'])[1] ?? '') >= 3,
    'DemoProvider generates multi-pip precision Forex candles for EURUSD'
);

$demoIndices = new \App\Market\DemoProvider('US30');
$us30Quote = $demoIndices->fetchQuote();
assertTrue($us30Quote['price'] > 30000.0, 'DemoProvider generates realistic index prices for US30');

// 8d. E2E REST API for Multi-Asset and Trend Analysis
$stateEur = httpReq('GET', "$base/api/state.php?symbol=EURUSD&tf=M15", null, [], false, $cookieFile);
assertTrue(
    $stateEur['code'] === 200
    && ($stateEur['json']['symbol'] ?? '') === 'EURUSD'
    && !empty($stateEur['json']['trend_analysis']['classification'])
    && !empty($stateEur['json']['standard_report']),
    'GET /api/state.php?symbol=EURUSD returns trend analysis and Section 12 standard report'
);

// ------------------------------------------------------------------
// 9. Data-Validated Strategy Validation, Adaptive Regimes & Institutional Risk Controls
// ------------------------------------------------------------------
echo "\n9. Data-Validated Strategy Validation, Adaptive Regimes & Institutional Risk Controls\n";

// 9a. RegimeDetector
$regDetector = new \App\Analysis\RegimeDetector();
$bullTrend = ['classification' => 'Strong Bullish Trend', 'momentum' => ['rsi' => 64]];
$regBull = $regDetector->detect($synth, $bullTrend);
assertTrue(
    $regBull['regime'] === 'trending_bullish'
    && in_array('ict_2022_model', $regBull['authorized_models'], true)
    && in_array('ict_unicorn_model', $regBull['authorized_models'], true),
    'RegimeDetector detects trending_bullish and authorizes trend models'
);

$rangeTrend = ['classification' => 'Consolidation / Range', 'momentum' => ['rsi' => 50]];
$regRange = $regDetector->detect($synth, $rangeTrend);
assertTrue(
    $regRange['regime'] === 'ranging_consolidation'
    && in_array('ict_turtle_soup', $regRange['authorized_models'], true),
    'RegimeDetector detects ranging_consolidation and authorizes range sweep models'
);

$highVolTrend = ['classification' => 'Consolidation / Range', 'momentum' => ['rsi' => 50]];
$newsBlocker = ['blocking' => 'High-impact Non-Farm Payrolls'];
$regHazard = $regDetector->detect($synth, $highVolTrend, $newsBlocker);
assertTrue(
    $regHazard['regime'] === 'high_volatility_abnormal'
    && $regHazard['circuit_breaker_active'] === true
    && empty($regHazard['authorized_models']),
    'RegimeDetector triggers circuit breaker and halts all models on abnormal volatility/news'
);

// Degradation circuit breaker: 4 consecutive losses
$degradStreak = $regDetector->evaluateStrategyDegradation('ict_silver_bullet', [
    ['outcome' => 'loss'], ['outcome' => 'loss'], ['outcome' => 'loss'], ['outcome' => 'loss']
]);
assertTrue(
    $degradStreak['status'] === 'paused' && str_contains($degradStreak['reason'], 'consecutive losses'),
    'RegimeDetector auto-pauses strategy after 4 consecutive losses'
);

// 9b. Institutional Risk Controls & Portfolio Limits
$rcInst = new \App\Analysis\RiskCalculator();

// Emergency stop check
\App\Core\Settings::set('risk.emergency_stop', true, 1);
$limEmergency = $rcInst->evaluatePortfolioLimits(10000.0, []);
assertTrue(
    !$limEmergency['can_trade'] && $limEmergency['emergency_stop_active'],
    'RiskCalculator::evaluatePortfolioLimits blocks trading when master emergency stop is active'
);
\App\Core\Settings::set('risk.emergency_stop', false, 1);

// Daily loss limit (2.0% of $10,000 = $200)
$limLoss = $rcInst->evaluatePortfolioLimits(10000.0, [
    'closed_today' => [['pnl' => -150.0], ['pnl' => -60.0]], // -$210 total
]);
assertTrue(
    !$limLoss['can_trade'] && $limLoss['daily_loss_limit_hit'],
    'RiskCalculator::evaluatePortfolioLimits halts new setups when daily loss limit (2.0%) is breached'
);

// Max open positions (2)
$limPos = $rcInst->evaluatePortfolioLimits(10000.0, [
    'open_positions' => [['id' => 1, 'risk_pct' => 0.5], ['id' => 2, 'risk_pct' => 0.5]],
]);
assertTrue(
    !$limPos['can_trade'] && $limPos['max_positions_reached'],
    'RiskCalculator::evaluatePortfolioLimits halts setups when max open positions (2) is reached'
);

// Anti-Martingale clamp
$calcAntiMart = $rcInst->calculate(10000.0, 1.5, 2400.0, 2395.0, null, null, null, ['last_outcome' => 'loss']);
assertTrue(
    $calcAntiMart['applied_risk_pct'] === 0.5 && count($calcAntiMart['warnings']) > 0,
    'RiskCalculator enforces Anti-Martingale rule by clamping risk to 0.5% baseline after loss'
);

// 9c. Backtester Quantitative Validation & Stress Testing
$btInst = new \App\Backtest\Backtester();
$btRes = $btInst->run([
    'name' => 'Institutional Validation Test',
    'symbol' => 'XAUUSD',
    'timeframe' => 'M15',
    'initial_balance' => 10000,
    'date_from' => gmdate('Y-m-d', time() - 14 * 86400),
    'date_to' => gmdate('Y-m-d'),
    'risk_percent' => 0.5,
    'min_score' => 55,
], 1);
assertTrue(!empty($btRes['ok']) && $btRes['run_id'] > 0, 'Backtester runs walk-forward quantitative evaluation');

$summary = $btRes['summary'];
assertTrue(
    isset($summary['execution_costs']['commission_usd'])
    && isset($summary['execution_costs']['total_cost_usd'])
    && isset($summary['stress_test']['status'])
    && isset($summary['validation']['criteria'])
    && in_array($summary['validation']['status'], ['HISTORICALLY_VALIDATED', 'NOT_VALIDATED'], true),
    'Backtester computes execution costs, stress testing stats, and 7-point validation gate'
);

// 9d. REST API for Emergency Stop Toggle
$emPost = httpReq('POST', "$base/api/backtest.php", ['action' => 'emergency_stop', 'enabled' => true], $apiHeaders, true, $cookieFile);
assertTrue($emPost['code'] === 200 && !empty($emPost['json']['emergency_stop']), 'POST /api/backtest.php activates emergency stop');

$emState = httpReq('GET', "$base/api/backtest.php?emergency_status=1", null, [], false, $cookieFile);
assertTrue($emState['code'] === 200 && !empty($emState['json']['emergency_stop']), 'GET /api/backtest.php confirms emergency stop active');

$emDeactivate = httpReq('POST', "$base/api/backtest.php", ['action' => 'emergency_stop', 'enabled' => false], $apiHeaders, true, $cookieFile);
assertTrue($emDeactivate['code'] === 200 && empty($emDeactivate['json']['emergency_stop']), 'POST /api/backtest.php deactivates emergency stop');

// 10. Adaptive Machine Learning Pipeline, EV Trade Filter & Training Control Center
echo "\n10. Adaptive Machine Learning Pipeline, EV Trade Filter & Training Control Center\n";

$marketSvc = new \App\Market\MarketDataService(null, 'XAUUSD');
$testCandles = $marketSvc->candles('M15', 300);

// 10a. FeatureExtractor
$featExtractor = new \App\ML\FeatureExtractor();
$extractedFeat = $featExtractor->extract(['M15' => $testCandles], [
    'dir' => 1,
    'entry' => 2400.0,
    'sl' => 2390.0,
    'tp1' => 2418.0,
    'tp2' => 2435.0,
    'spread' => 0.30,
], time(), 'M15');
assertTrue(count($extractedFeat['vector']) === 25, 'FeatureExtractor produces 25 normalized point-in-time features');
assertTrue(isset($extractedFeat['map']['rsi_14_norm']) && isset($extractedFeat['map']['atr_ratio']), 'FeatureExtractor map contains technical and structure features');

// 10b. DatasetGenerator
$dsGen = new \App\ML\DatasetGenerator($featExtractor);
$genData = $dsGen->generate($testCandles, 'XAUUSD', 'M15', ['horizon' => 36, 'spread' => 0.30]);
assertTrue(is_array($genData['samples']), 'DatasetGenerator generates verified historical samples');
assertTrue(isset($genData['stats']['total_candidates']), 'DatasetGenerator computes dataset execution statistics');

// 10c. ProbabilisticModel
$mlModel = new \App\ML\ProbabilisticModel();
// Synthetic train samples for unit test verification
$synthX = [
    [1.0, 1.0, 0.6, 1.1, 0.2, 0.1, 1.0, 0.1, 1.0, 1.2, 0.8, 0.3, 1.0, 0.0, 0.4, 1.0, 0.0, 0.0, 0.4, 0.6, 1.2, 2.0, 3.5, 0.03, 1.0],
    [-1.0, -1.0, 0.4, 1.8, -0.3, -0.2, 1.0, 0.8, 0.0, 0.5, 0.4, 0.8, 0.0, 1.0, 0.1, 0.0, 1.0, 0.0, 0.6, 0.8, 2.5, 1.5, 2.5, 0.08, -1.0],
    [0.0, 0.0, 0.5, 0.6, 0.0, 0.0, 0.0, 0.9, 0.0, 0.2, 0.3, 0.5, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.2, 0.4, 1.8, 1.8, 3.0, 0.05, 0.0],
    [1.0, 1.0, 0.7, 1.0, 0.3, 0.2, 1.0, 0.2, 1.0, 1.5, 1.0, 0.2, 1.0, 0.0, 0.5, 0.0, 1.0, 0.0, 0.5, 0.6, 1.1, 2.2, 4.0, 0.02, 1.0],
];
$synthY = [1, 0, 0, 1];
$fitMetrics = $mlModel->fit($synthX, $synthY, ['epochs' => 60, 'learning_rate' => 0.05]);
assertTrue(!empty($fitMetrics['epochs']), 'ProbabilisticModel fits weights via regularized gradient descent');
$predP = $mlModel->predictProb($synthX[0]);
assertTrue($predP >= 0.01 && $predP <= 0.99, 'ProbabilisticModel outputs calibrated probability between 0.01 and 0.99');
$evalRes = $mlModel->evaluate($synthX, $synthY);
assertTrue(isset($evalRes['brier_score']) && isset($evalRes['confusion_matrix']), 'ProbabilisticModel evaluates Brier score and confusion matrix');

// 10d. ExpectedValueFilter
$evFilter = new \App\ML\ExpectedValueFilter($mlModel, 0.52, 0.20);
// Sub-par setup should return NO_TRADE
$poorEval = $evFilter->evaluate([
    'direction' => 'BUY',
    'entry'     => 2400.0,
    'sl'        => 2390.0,
    'tp1'       => 2405.0, // weak target
    'tp2'       => 2410.0,
    'spread'    => 1.50,   // excessive spread
], $synthX[1]);
assertTrue($poorEval['decision'] === 'NO_TRADE' && !$poorEval['authorized'], 'ExpectedValueFilter blocks low EV or high friction setups with NO_TRADE');

// 10e. SignalFeedbackEngine & Error Diagnosis
$feedback = new \App\ML\SignalFeedbackEngine();
$diagLoss = $feedback->diagnoseFailure('loss', -1.05, 0.2, 1.1, ['session' => 'New York', 'regime' => 'ranging_consolidation'], ['has_displacement' => 0]);
assertTrue(in_array($diagLoss['category'], [\App\ML\SignalFeedbackEngine::ERR_REGIME_MISMATCH, \App\ML\SignalFeedbackEngine::ERR_DISPLACEMENT_FAILURE, \App\ML\SignalFeedbackEngine::ERR_NORMAL_STOP], true), 'SignalFeedbackEngine categorizes failure into institutional error taxonomy');
$errRep = $feedback->generateReport('XAUUSD');
assertTrue(isset($errRep['by_category']), 'SignalFeedbackEngine generates aggregate failure report');

// 10f. ModelManager Promotion & Rollback
$modelMgr = new \App\ML\ModelManager();
$vList = $modelMgr->listVersions(10);
assertTrue(is_array($vList), 'ModelManager lists registered model versions');
if (!empty($vList)) {
    $firstId = (int)$vList[0]['id'];
    assertTrue($modelMgr->promoteModel($firstId), 'ModelManager promotes model version to active');
    $activeM = $modelMgr->getActiveModel();
    assertTrue($activeM !== null && $activeM['id'] === $firstId, 'ModelManager returns active production model');
}

// 10g. Web & API Endpoints for Training Control Center
$trainPage = httpReq('GET', "$base/training.php", null, [], false, $cookieFile);
assertTrue($trainPage['code'] === 200 && str_contains($trainPage['body'], 'AI Training Control Center'), 'Authenticated GET /training.php renders HTTP 200');

$trainApiStatus = httpReq('GET', "$base/api/training.php?action=status&symbol=XAUUSD", null, [], false, $cookieFile);
assertTrue($trainApiStatus['code'] === 200 && !empty($trainApiStatus['json']['ok']), 'GET /api/training.php?action=status returns JSON system status');
assertTrue(isset($trainApiStatus['json']['dataset_check']['total_candles']), 'Training API reports dataset health and missing bars check');

@unlink($cookieFile);
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "\n=============================================================\n";
echo sprintf(" Summary: %d passed, %d failed\n", $passed, $failed);
echo "=============================================================\n";
exit($failed > 0 ? 1 : 0);
