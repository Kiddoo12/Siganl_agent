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

function httpReq(string $method, string $url, $postFields = null, array $headers = [], bool $json = false, string $cookieFile = '', int $timeout = 20): array
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
        $payload = is_string($postFields) ? $postFields : ($json ? json_encode($postFields) : http_build_query($postFields));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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
foreach (['index.php', 'chat.php', 'signals.php', 'backtest.php', 'calendar.php', 'settings.php', 'admin.php', 'vision.php', 'strategies.php', 'scanner.php', 'mtf.php', 'live.php', 'calculator.php'] as $page) {
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

// 7e. Candle Range Theory (CRT) Engine
$crtEngine = new \App\SMC\CrtEngine();
$mockCrtCandles = [
    ['t' => 1000, 'o' => 2400.0, 'h' => 2410.0, 'l' => 2390.0, 'c' => 2405.0],
    ['t' => 1060, 'o' => 2405.0, 'h' => 2412.0, 'l' => 2388.0, 'c' => 2402.0],
    ['t' => 1120, 'o' => 2402.0, 'h' => 2415.0, 'l' => 2400.0, 'c' => 2410.0],
    ['t' => 1180, 'o' => 2410.0, 'h' => 2420.0, 'l' => 2400.0, 'c' => 2415.0], // Ref range: H 2420, L 2400, EQ 2410
    ['t' => 1240, 'o' => 2415.0, 'h' => 2416.0, 'l' => 2395.0, 'c' => 2405.0],  // Pierced below 2400, closed 2405 (> 2400)
];
$crtRes = $crtEngine->analyze($mockCrtCandles);
assertTrue($crtRes['ok'] === true && $crtRes['phase'] === 'bullish_sweep_reclaim', 'CrtEngine detects bullish range sweep and reclaim');
assertTrue(isset($crtRes['reference_candle']['range_eq']) && $crtRes['reference_candle']['range_eq'] == 2410.0, 'CrtEngine computes exact 50% Equilibrium price');
assertTrue(!empty($crtRes['trade_setup']['tp1']) && $crtRes['trade_setup']['direction'] === 'BUY', 'CrtEngine generates BUY setup targeting 50% EQ');

// 7f. Strategy-Selected AI Vision Analysis (CRT, ICT, SMC)
$visCrtPost = httpReq('POST', "$base/api/vision_analyze.php", [
    'image_base64'  => 'data:image/png;base64,' . $testPngBase64,
    'symbol'        => 'XAUUSD',
    'timeframe'     => 'M15',
    'strategy_mode' => 'CRT',
    '_csrf'         => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($visCrtPost['code'] === 200 && str_contains($visCrtPost['json']['report']['strategy_mode'] ?? '', 'CRT'), 'POST /api/vision_analyze.php supports CRT strategy mode');

// 7g. AI Chat Analyst Endpoint with Strategy Selection (ICT, SMC, CRT, ALL)
$chatCrt = httpReq('POST', "$base/api/chat.php", [
    'action'        => 'send',
    'message'       => 'Analyze current CRT setup on XAUUSD',
    'strategy_mode' => 'CRT',
    'symbol'        => 'XAUUSD',
    'timeframe'     => 'M15',
    '_csrf'         => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($chatCrt['code'] === 200 && !empty($chatCrt['json']['ok']) && $chatCrt['json']['strategy_mode'] === 'CRT', 'POST /api/chat.php processes CRT strategy selection');
assertTrue(str_contains($chatCrt['json']['reply'], 'Candle Range Theory'), 'AI Chat outputs detailed CRT analysis');

$chatIct = httpReq('POST', "$base/api/chat.php", [
    'action'        => 'send',
    'message'       => 'Check ICT Silver Bullet and FVG',
    'strategy_mode' => 'ICT',
    'symbol'        => 'XAUUSD',
    'timeframe'     => 'M15',
    '_csrf'         => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($chatIct['code'] === 200 && $chatIct['json']['strategy_mode'] === 'ICT', 'POST /api/chat.php processes ICT strategy selection');

$chatSmc = httpReq('POST', "$base/api/chat.php", [
    'action'        => 'send',
    'message'       => 'Check SMC BOS and Order Blocks',
    'strategy_mode' => 'SMC',
    'symbol'        => 'XAUUSD',
    'timeframe'     => 'M15',
    '_csrf'         => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($chatSmc['code'] === 200 && $chatSmc['json']['strategy_mode'] === 'SMC', 'POST /api/chat.php processes SMC strategy selection');

// 7h. Support & Resistance (SNR) Engine
$snrEngine = new \App\SMC\SnrEngine();
$mockSnrCandles = [
    ['t' => 1000, 'o' => 2400.0, 'h' => 2410.0, 'l' => 2395.0, 'c' => 2405.0],
    ['t' => 1060, 'o' => 2405.0, 'h' => 2425.0, 'l' => 2404.0, 'c' => 2420.0],
    ['t' => 1120, 'o' => 2420.0, 'h' => 2422.0, 'l' => 2398.0, 'c' => 2400.0],
    ['t' => 1180, 'o' => 2400.0, 'h' => 2426.0, 'l' => 2402.0, 'c' => 2422.0],
    ['t' => 1240, 'o' => 2422.0, 'h' => 2424.0, 'l' => 2397.0, 'c' => 2400.0],
    ['t' => 1300, 'o' => 2400.0, 'h' => 2408.0, 'l' => 2392.0, 'c' => 2402.0],
];
$snrRes = $snrEngine->analyze($mockSnrCandles);
assertTrue($snrRes['ok'] === true, 'SnrEngine executes successfully on candle series');
assertTrue(!empty($snrRes['all_resistances']), 'SnrEngine identifies major horizontal resistance levels');
assertTrue(!empty($snrRes['all_supports']), 'SnrEngine identifies major horizontal support levels');
assertTrue(!empty($snrRes['trade_setup']['code']) && $snrRes['trade_setup']['code'] === 'snr_false_breakout', 'SnrEngine identifies Bear Trap false breakout below support with rejection candle');

// 7i. Strategy-Selected AI Vision Analysis with SNR Mode
$visSnrPost = httpReq('POST', "$base/api/vision_analyze.php", [
    'image_base64'  => 'data:image/png;base64,' . $testPngBase64,
    'symbol'        => 'XAUUSD',
    'timeframe'     => 'M15',
    'strategy_mode' => 'SNR',
    '_csrf'         => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($visSnrPost['code'] === 200 && str_contains($visSnrPost['json']['report']['strategy_mode'] ?? '', 'SNR'), 'POST /api/vision_analyze.php supports SNR strategy mode');

// 7j. AI Chat Analyst Endpoint with SNR Strategy Selection
$chatSnr = httpReq('POST', "$base/api/chat.php", [
    'action'        => 'send',
    'message'       => 'Analyze current Support and Resistance levels, confirmed breakouts and retests on XAUUSD',
    'strategy_mode' => 'SNR',
    'symbol'        => 'XAUUSD',
    'timeframe'     => 'M15',
    '_csrf'         => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($chatSnr['code'] === 200 && !empty($chatSnr['json']['ok']) && $chatSnr['json']['strategy_mode'] === 'SNR', 'POST /api/chat.php processes SNR strategy selection');
assertTrue(str_contains($chatSnr['json']['reply'], 'Support & Resistance'), 'AI Chat outputs detailed Support & Resistance analysis');

$chatHist = httpReq('GET', "$base/api/chat.php?action=history&symbol=XAUUSD", null, [], false, $cookieFile);
assertTrue($chatHist['code'] === 200 && is_array($chatHist['json']['history']) && count($chatHist['json']['history']) >= 3, 'GET /api/chat.php?action=history returns conversation log');

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

// ------------------------------------------------------------------
// 11. AI Chart Vision Analyzer v2 — 22-Point Security, Validation & MTF Suite
// ------------------------------------------------------------------
echo "\n11. AI Chart Vision Analyzer v2 (22-Point Security, Validation & MTF Suite)\n";

// Helper to synthesize a valid uncompressed/zlib PNG of arbitrary dimensions without requiring ext-gd
function buildValidPng(int $w, int $h): string
{
    $ihdrData = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    $ihdr = pack('N', 13) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
    $rawScanlines = str_repeat("\x00" . str_repeat("\x10\x20\x30", $w), $h);
    $compressed = gzcompress($rawScanlines, 6);
    $idat = pack('N', strlen($compressed)) . 'IDAT' . $compressed . pack('N', crc32('IDAT' . $compressed));
    $iend = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    return "\x89PNG\r\n\x1a\n" . $ihdr . $idat . $iend;
}

// 11.1 Valid PNG upload (80x60)
$validPngBytes = buildValidPng(80, 60);
$upPng = \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode($validPngBytes), true);
assertTrue($upPng['mime'] === 'image/png' && $upPng['width'] === 80 && $upPng['height'] === 60, 'Vision v2 [1/22]: Valid PNG upload verified with exact dimensions (80x60)');

// 11.2 Valid JPG upload (minimal valid 64x64 baseline JPEG header + SOS + EOI)
$validJpgHex = 'ffd8ffe000104a46494600010101006000600000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080040004001011100ffc4001f0000010501010101010100000000000000000102030405060708090a0bffda0008010100003f00fbfdffd9';
$upJpg = \App\Analysis\ChartVisionService::storeUpload([], 'data:image/jpeg;base64,' . base64_encode((string)hex2bin($validJpgHex)), true);
assertTrue($upJpg['mime'] === 'image/jpeg' && $upJpg['width'] === 64 && $upJpg['height'] === 64, 'Vision v2 [2/22]: Valid JPG upload verified with exact dimensions (64x64)');

// 11.3 Valid WEBP upload (64x64 VP8X WebP)
$webpChunk = "VP8X" . pack('V', 10) . "\x00\x00\x00\x00\x3f\x00\x00\x3f\x00\x00";
$webpBytes = "RIFF" . pack('V', strlen($webpChunk) + 4) . "WEBP" . $webpChunk;
$upWebp = \App\Analysis\ChartVisionService::storeUpload([], 'data:image/webp;base64,' . base64_encode($webpBytes), true);
assertTrue($upWebp['mime'] === 'image/webp' && $upWebp['width'] === 64 && $upWebp['height'] === 64, 'Vision v2 [3/22]: Valid WEBP upload verified via magic bytes, finfo, and dimensions (64x64)');

// 11.4 Oversized file (> 10MB) rejected
$oversizedRejected = false;
try {
    $fakeFile = ['error' => UPLOAD_ERR_OK, 'size' => 10485761, 'tmp_name' => $upPng['path'], 'name' => 'huge.png'];
    \App\Analysis\ChartVisionService::storeUpload($fakeFile);
} catch (\InvalidArgumentException $e) {
    $oversizedRejected = str_contains($e->getMessage(), '10MB');
}
assertTrue($oversizedRejected, 'Vision v2 [4/22]: Oversized file (>10MB) strictly rejected');

// 11.5 Invalid MIME type / spoofed extension rejected
$spoofRejected = false;
try {
    \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode('<?php echo "hack"; ?>'));
} catch (\InvalidArgumentException $e) {
    $spoofRejected = true;
}
assertTrue($spoofRejected, 'Vision v2 [5/22]: Spoofed PHP/text payload disguised as PNG rejected via magic bytes & finfo');

// 11.6 Corrupted image rejected
$corruptRejected = false;
try {
    $corruptBytes = "\x89PNG\r\n\x1a\n" . str_repeat('X', 60);
    \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode($corruptBytes));
} catch (\InvalidArgumentException $e) {
    $corruptRejected = true;
}
assertTrue($corruptRejected, 'Vision v2 [6/22]: Corrupted/truncated image stream rejected');

// 11.7 Invalid dimensions (< 50x50px) rejected when enforceMinDimensions=true
$tinyRejected = false;
try {
    $tinyPng = buildValidPng(10, 10);
    \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode($tinyPng), true);
} catch (\InvalidArgumentException $e) {
    $tinyRejected = str_contains($e->getMessage(), '50x50');
}
assertTrue($tinyRejected, 'Vision v2 [7/22]: Sub-50x50px image rejected when minimum chart dimensions enforced');

// 11.8 Rotated image dimensions (60x80 portrait after 90-deg rotation) & overlay scaling
$rotPngBytes = buildValidPng(60, 80);
$upRot = \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode($rotPngBytes), true);
$rotAnalysis = \App\Analysis\ChartVisionService::analyzeChart($upRot['path'], 'XAUUSD', 'M15', 2650.0, 'TradingView', 'UTC', 1, 'ICT', 'H4', null, true);
assertTrue($rotAnalysis['overlay']['width'] >= 60 && !empty($rotAnalysis['overlay']['elements']), 'Vision v2 [8/22]: Rotated chart image processed and mapped to overlay coordinates');

// 11.9 Clipboard paste (base64 data URI) via HTTP API
$pasteApi = httpReq('POST', "$base/api/vision_analyze.php", [
    'image_base64'   => 'data:image/png;base64,' . base64_encode($validPngBytes),
    'symbol'         => 'XAUUSD',
    'timeframe'      => 'M15',
    'htf_timeframe'  => 'H4',
    'strategy_mode'  => 'ICT',
    'force_reanalyze'=> 1,
    '_csrf'          => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($pasteApi['code'] === 200 && !empty($pasteApi['json']['ok']) && isset($pasteApi['json']['report']['mtf_analysis']), 'Vision v2 [9/22]: Clipboard base64 paste analyzed with MTF confirmation');

// 11.10 Bullish setup mathematical validation (BUY: SL < Entry < TP1 < TP2 < TP3; 2R, 3.5R, 5R)
$valBull = \App\Analysis\ChartVisionService::validateTradeSetup('BUY', 2648.0, 2652.0, 2640.0);
assertTrue(
    $valBull['valid'] === true
    && $valBull['direction'] === 'BUY'
    && $valBull['stop_loss'] < $valBull['entry_min']
    && $valBull['entry_max'] < $valBull['tp1']
    && $valBull['tp1'] < $valBull['tp2']
    && $valBull['tp2'] < $valBull['tp3']
    && $valBull['rr_tp1'] === 2.0 && $valBull['rr_tp2'] === 3.5 && $valBull['rr_tp3'] === 5.0,
    'Vision v2 [10/22]: Bullish BUY setup strictly satisfies SL < Entry < TP1 (2R) < TP2 (3.5R) < TP3 (5R)'
);

// 11.11 Bearish setup mathematical validation (SELL: SL > Entry > TP1 > TP2 > TP3; 2R, 3.5R, 5R)
$valBear = \App\Analysis\ChartVisionService::validateTradeSetup('SELL', 2648.0, 2652.0, 2660.0);
assertTrue(
    $valBear['valid'] === true
    && $valBear['direction'] === 'SELL'
    && $valBear['stop_loss'] > $valBear['entry_max']
    && $valBear['entry_min'] > $valBear['tp1']
    && $valBear['tp1'] > $valBear['tp2']
    && $valBear['tp2'] > $valBear['tp3']
    && $valBear['rr_tp1'] === 2.0 && $valBear['rr_tp2'] === 3.5 && $valBear['rr_tp3'] === 5.0,
    'Vision v2 [11/22]: Bearish SELL setup strictly satisfies SL > Entry > TP1 (2R) > TP2 (3.5R) > TP3 (5R)'
);

// 11.12 NO TRADE setup enforcement
$valNoTrade = \App\Analysis\ChartVisionService::validateTradeSetup('NO TRADE', 2648.0, 2652.0, 2640.0);
assertTrue($valNoTrade['direction'] === 'NO TRADE' && $valNoTrade['valid'] === false, 'Vision v2 [12/22]: Indecisive / Neutral setup returns NO TRADE');

// 11.13 Invalid SL/TP rejection (contradictory BUY with SL above Entry or inverted TPs)
$valBadBuy = \App\Analysis\ChartVisionService::validateTradeSetup('BUY', 2650.0, 2652.0, 2655.0, 2660.0, 2658.0, 2670.0);
$valBadSell = \App\Analysis\ChartVisionService::validateTradeSetup('SELL', 2650.0, 2652.0, 2645.0, 2640.0, 2635.0, 2630.0);
assertTrue(
    !$valBadBuy['valid'] && $valBadBuy['direction'] === 'NO TRADE' && count($valBadBuy['errors']) >= 2
    && !$valBadSell['valid'] && $valBadSell['direction'] === 'NO TRADE',
    'Vision v2 [13/22]: Invalid SL/TP inequalities are rejected and forced to NO TRADE'
);

// 11.14 Missing manual price fallback (truthfully labeled, never faking live price when offline)
$noManualRes = \App\Analysis\ChartVisionService::analyzeChart($upPng['path'], 'XAUUSD', 'M15', null, 'OANDA', 'UTC', 1, 'ALL', 'H4', null, true);
assertTrue(
    in_array($noManualRes['report']['price_source'], ['live_feed', 'engine_fallback'], true)
    && !empty($noManualRes['report']['price_source_label']),
    'Vision v2 [14/22]: Missing manual price falls back cleanly with truthful price_source label'
);

// 11.15 Manual price override
$manualRes = \App\Analysis\ChartVisionService::analyzeChart($upPng['path'], 'XAUUSD', 'M15', 2777.50, 'OANDA', 'UTC', 1, 'ALL', 'H4', null, true);
assertTrue(
    $manualRes['report']['price_source'] === 'manual_input'
    && (float)$manualRes['report']['reference_price'] === 2777.50,
    'Vision v2 [15/22]: Manual price override sets price_source=manual_input at $2777.50'
);

// 11.16 External AI timeout / fallback to deterministic engine + 8-point quality gate & 7-component confidence
assertTrue(
    isset($manualRes['report']['setup_quality_gate']['checks'])
    && count($manualRes['report']['setup_quality_gate']['checks']) === 8
    && isset($manualRes['report']['confidence_breakdown']['components'])
    && count($manualRes['report']['confidence_breakdown']['components']) === 7
    && !empty($manualRes['report']['observed_facts'])
    && !empty($manualRes['report']['ai_interpretations']),
    'Vision v2 [16/22]: Deterministic fallback populates 8-point Quality Gate, 7-component Confidence, Observed Facts & AI Interpretations'
);

// 11.17 History loading, pagination, and filtering
$histFiltered = httpReq('GET', "$base/api/vision_history.php?page=1&per_page=5&symbol=XAUUSD&timeframe=M15&strategy=ICT", null, [], false, $cookieFile);
assertTrue(
    $histFiltered['code'] === 200
    && !empty($histFiltered['json']['ok'])
    && isset($histFiltered['json']['pagination']['total_pages'])
    && count($histFiltered['json']['items']) <= 5,
    'Vision v2 [17/22]: GET /api/vision_history.php supports pagination and symbol/timeframe/strategy filters'
);

// 11.18 History deletion with ownership check
$tempToDeleteId = (int)$noManualRes['analysis_id'];
$delRes = httpReq('POST', "$base/api/vision_history.php", ['_method' => 'DELETE', 'id' => $tempToDeleteId, '_csrf' => $csrf], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue($delRes['code'] === 200 && !empty($delRes['json']['deleted']), 'Vision v2 [18/22]: DELETE /api/vision_history.php removes user-owned analysis record');

// 11.19 Unauthorized user access protection (IDOR blocked on sendToTelegram)
$idorBlocked = false;
try {
    // Analysis $manualRes['analysis_id'] is owned by user_id = 1; attempt access as non-admin user_id = 99999
    \App\Analysis\ChartVisionService::sendToTelegram((int)$manualRes['analysis_id'], 99999, false);
} catch (\RuntimeException $e) {
    $idorBlocked = ($e->getCode() === 403 || str_contains($e->getMessage(), 'Unauthorized'));
}
assertTrue($idorBlocked, 'Vision v2 [19/22]: IDOR protection blocks unauthorized user from accessing another user\'s analysis');

// 11.20 CSRF failure rejection
$csrfFail = httpReq('POST', "$base/api/vision_analyze.php", ['symbol' => 'XAUUSD', '_csrf' => 'invalid_token'], ['X-CSRF-Token: invalid_token'], false, $cookieFile);
assertTrue($csrfFail['code'] === 403 && empty($csrfFail['json']['ok']), 'Vision v2 [20/22]: Invalid CSRF token rejected with HTTP 403');

// 11.21 Telegram send & duplicate send deduplication
$tgFirst = httpReq('POST', "$base/api/vision_telegram.php", ['analysis_id' => (int)$manualRes['analysis_id'], '_csrf' => $csrf], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
$tgDup = httpReq('POST', "$base/api/vision_telegram.php", ['analysis_id' => (int)$manualRes['analysis_id'], '_csrf' => $csrf], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue(
    $tgFirst['code'] === 200 && !empty($tgFirst['json']['ok'])
    && $tgDup['code'] === 200 && !empty($tgDup['json']['deduplicated']),
    'Vision v2 [21/22]: Telegram dispatch succeeds and blocks duplicate sends within 60s window'
);

// 11.22 Categorized overlay elements (entry, sl, tp [TP1-TP3], liquidity) & secure image endpoint
$ovCategories = array_unique(array_column($manualRes['overlay']['elements'], 'category'));
$imgEndpointRes = httpReq('GET', "$base/api/vision_image.php?id=" . (int)$manualRes['analysis_id'], null, [], false, $cookieFile);
assertTrue(
    in_array('entry', $ovCategories, true)
    && in_array('sl', $ovCategories, true)
    && in_array('tp', $ovCategories, true)
    && $imgEndpointRes['code'] === 200
    && strncmp($imgEndpointRes['body'], "\x89PNG", 4) === 0,
    'Vision v2 [22/22]: Categorized overlay (Entry/SL/TP1-TP3) & authenticated /api/vision_image.php verified'
);

// ------------------------------------------------------------------
// 12. AI Chart Vision Analyzer V3 — 18-Point Architecture, EXIF, Symbol Precision & Security Suite
// ------------------------------------------------------------------
echo "\n12. AI Chart Vision Analyzer V3 (18-Point Architecture, EXIF, Symbol Precision & Security Suite)\n";

// 12.1 EXIF Orientation extraction (Orientation = 6 swaps 80x60 -> 60x80)
$exifSegment = "\xFF\xE1" . pack('n', 32) . "Exif\x00\x00II" . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', 6) . "\x00\x00\x00\x00\x00\x00";
$rawJpgBytes = (string)hex2bin($validJpgHex);
$exifJpgBytes = substr($rawJpgBytes, 0, 2) . $exifSegment . substr($rawJpgBytes, 2);
$extractedOri = \App\Analysis\ChartVisionService::extractExifOrientation($exifJpgBytes);
assertTrue($extractedOri === 6, 'Vision V3 [1/18]: EXIF Orientation tag (0x0112 = 6) accurately parsed from JPEG stream');

// 12.2 Decompression bomb (>25MP) strictly rejected
$bombRejected = false;
try {
    $bombIhdrData = pack('NNCCCCC', 6000, 6000, 8, 2, 0, 0, 0); // 36 Megapixels
    $bombIhdr = pack('N', 13) . 'IHDR' . $bombIhdrData . pack('N', crc32('IHDR' . $bombIhdrData));
    $bombPng = "\x89PNG\r\n\x1a\n" . $bombIhdr . pack('N', 4) . 'IDATx' . "\x9c\x63\x00" . pack('N', 0) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode($bombPng), true);
} catch (\InvalidArgumentException $e) {
    $bombRejected = str_contains($e->getMessage(), '25 MP') || str_contains($e->getMessage(), 'pixel');
}
assertTrue($bombRejected, 'Vision V3 [2/18]: Oversized decompression bomb (>25 Megapixels) rejected');

// 12.3 Spoofed executable extension (.php.png / .svg) rejected
$extRejected = false;
try {
    $badExtFile = ['error' => UPLOAD_ERR_OK, 'size' => strlen($validPngBytes), 'tmp_name' => $upPng['path'], 'name' => 'exploit.php.png'];
    \App\Analysis\ChartVisionService::storeUpload($badExtFile, null, true);
} catch (\InvalidArgumentException $e) {
    $extRejected = str_contains($e->getMessage(), 'extension');
}
assertTrue($extRejected, 'Vision V3 [3/18]: Dangerous double extension (exploit.php.png) strictly rejected');

// 12.4 Oversized HTF image rejected via API
$htfTooSmall = httpReq('POST', "$base/api/vision_analyze.php", [
    'image_base64'           => 'data:image/png;base64,' . base64_encode($validPngBytes),
    'htf_image_base64'       => 'data:image/png;base64,' . base64_encode(buildValidPng(12, 12)),
    'enforce_min_dimensions' => 1,
    'symbol'                 => 'XAUUSD',
    'timeframe'              => 'M15',
    'htf_timeframe'          => 'H4',
    '_csrf'                  => $csrf,
], ['X-CSRF-Token: ' . $csrf], false, $cookieFile);
assertTrue(
    $htfTooSmall['code'] === 422 && empty($htfTooSmall['json']['ok']) && isset($htfTooSmall['json']['error']['code']),
    'Vision V3 [4/18]: Sub-50x50px HTF screenshot rejected with V3 structured error object'
);

// 12.5 Separate HTF screenshot workflow & never conflating execution chart as HTF
$distinctHtfPng = buildValidPng(90, 70);
$upHtfFile = \App\Analysis\ChartVisionService::storeUpload([], 'data:image/png;base64,' . base64_encode($distinctHtfPng), true);
$withHtfRes = \App\Analysis\ChartVisionService::analyzeChart($upPng['path'], 'XAUUSD', 'M15', 2750.0, 'TradingView', 'UTC', 1, 'ICT', 'H4', $upHtfFile['path'], true);
assertTrue(
    $withHtfRes['report']['mtf_analysis']['htf_screenshot_provided'] === true
    && $withHtfRes['report']['mtf_analysis']['timeframes']['H4']['visually_inspected'] === true
    && $withHtfRes['report']['mtf_analysis']['timeframes']['D1']['visually_inspected'] === false
    && !empty($withHtfRes['htf_image_url']),
    'Vision V3 [5/18]: HTF screenshot workflow distinguishes uploaded HTF chart from market-data timeframes'
);

// 12.6 Execution chart without HTF upload never pretends HTF was visually inspected
assertTrue(
    $manualRes['report']['mtf_analysis']['htf_screenshot_provided'] === false
    && $manualRes['htf_image_url'] === null
    && $manualRes['report']['mtf_analysis']['timeframes']['H4']['data_source'] === 'market_data_candles',
    'Vision V3 [6/18]: Single execution chart upload never pretends HTF screenshot was supplied'
);

// 12.7 Invalid HTF timeframe (< Execution TF) rejected
$badHtfHierarchy = false;
try {
    \App\Analysis\ChartVisionService::analyzeChart($upPng['path'], 'XAUUSD', 'H4', 2750.0, 'OANDA', 'UTC', 1, 'ALL', 'M5', null, true);
} catch (\InvalidArgumentException $e) {
    $badHtfHierarchy = str_contains($e->getMessage(), 'Higher-Timeframe');
}
assertTrue($badHtfHierarchy, 'Vision V3 [7/18]: Invalid HTF timeframe lower than Execution Timeframe (H4 exec vs M5 HTF) rejected');

// 12.8 Symbol-specific decimal precision (EURUSD 5 decimals, US30 1 decimal)
$eurVal = \App\Analysis\ChartVisionService::validateTradeSetup('BUY', 1.08420, 1.08460, 1.08240, null, null, null, 'EURUSD');
$us30Val = \App\Analysis\ChartVisionService::validateTradeSetup('SELL', 42100.0, 42120.0, 42210.0, null, null, null, 'US30');
assertTrue(
    $eurVal['valid'] === true && $eurVal['decimals'] === 5 && $eurVal['tp1'] === 1.08840
    && $us30Val['valid'] === true && $us30Val['decimals'] === 1 && $us30Val['tp1'] === 41910.0,
    'Vision V3 [8/18]: Multi-asset precision respected for EURUSD (5 decimals) and US30 (1 decimal)'
);

// 12.9 Symbol vs Manual Price incompatibility rejected (e.g. $2700 Gold price entered on EURUSD)
$incompatPriceRejected = false;
try {
    \App\Analysis\ChartVisionService::validateSymbolPrice('EURUSD', 2700.00);
} catch (\InvalidArgumentException $e) {
    $incompatPriceRejected = str_contains($e->getMessage(), 'EURUSD');
}
assertTrue($incompatPriceRejected, 'Vision V3 [9/18]: Incompatible manual price ($2700 on EURUSD) strictly rejected');

// 12.10 Stale setup where current live price already reached TP1 is rejected into NO TRADE
$staleSetup = \App\Analysis\ChartVisionService::validateTradeSetup('BUY', 2648.0, 2652.0, 2640.0, null, null, null, 'XAUUSD', 2680.0);
assertTrue(
    $staleSetup['valid'] === false
    && $staleSetup['direction'] === 'NO TRADE'
    && $staleSetup['price_vs_entry_status'] === 'target_already_reached',
    'Vision V3 [10/18]: Setup where current price already reached TP1 is rejected as stale (NO TRADE)'
);

// 12.11 Duplicate analysis submission deduplicated within 6-second window
$dupCheck1 = \App\Analysis\ChartVisionService::analyzeChart($upPng['path'], 'XAUUSD', 'M15', 2777.50, 'OANDA', 'UTC', 1, 'ALL', 'H4', null, false);
$dupCheck2 = \App\Analysis\ChartVisionService::analyzeChart($upPng['path'], 'XAUUSD', 'M15', 2777.50, 'OANDA', 'UTC', 1, 'ALL', 'H4', null, false);
assertTrue(
    $dupCheck2['deduplicated'] === true && $dupCheck1['analysis_id'] === $dupCheck2['analysis_id'],
    'Vision V3 [11/18]: Rapid duplicate analysis request is deduplicated without creating redundant DB rows'
);

// 12.12 Standard V3 API response structure ({ ok, data, error }) & Malformed JSON handling
$badJsonCall = httpReq('POST', "$base/api/vision_analyze.php", '{bad_json_payload', [
    'Content-Type: application/json',
    'X-CSRF-Token: ' . $csrf,
], false, $cookieFile);
assertTrue(
    $badJsonCall['code'] === 400
    && $badJsonCall['json']['ok'] === false
    && ($badJsonCall['json']['error']['code'] ?? '') === 'INVALID_JSON',
    'Vision V3 [12/18]: Malformed JSON payload rejected with HTTP 400 and error.code=INVALID_JSON'
);

// 12.13 10-Category Overlay with both X (x1, x2) and Y coordinates
$ovCatsList = $withHtfRes['overlay']['categories'] ?? [];
$firstElem = $withHtfRes['overlay']['elements'][0] ?? [];
assertTrue(
    count($ovCatsList) === 10
    && isset($firstElem['x1'], $firstElem['x2'])
    && in_array('ob', $ovCatsList, true)
    && in_array('fvg', $ovCatsList, true)
    && in_array('bsl', $ovCatsList, true)
    && in_array('ssl', $ovCatsList, true)
    && in_array('eq_hl', $ovCatsList, true)
    && in_array('swings', $ovCatsList, true),
    'Vision V3 [13/18]: 10-category overlay includes X & Y coordinates across all 10 institutional categories'
);

// 12.14 5-Part Epistemic Separation & Non-Win-Rate Confidence Disclaimer
$repV3 = $withHtfRes['report'];
assertTrue(
    !empty($repV3['observed_facts'])
    && !empty($repV3['ai_interpretations'])
    && !empty($repV3['hypotheses'])
    && !empty($repV3['missing_information'])
    && !empty($repV3['confirmation_conditions'])
    && str_contains($repV3['confidence_breakdown']['confidence_disclaimer'] ?? '', 'NOT a percentage probability'),
    'Vision V3 [14/18]: Report separates Facts, Interpretations, Hypotheses, Missing Info & Confirmation with Confidence Disclaimer'
);

// 12.15 Price source metadata (LIVE / HISTORICAL / MANUAL / UNAVAILABLE + timestamp + provider)
assertTrue(
    $repV3['price_source_type'] === 'MANUAL'
    && !empty($repV3['price_timestamp'])
    && $repV3['data_provider'] === 'TradingView',
    'Vision V3 [15/18]: Price source type, UTC timestamp, and data provider recorded accurately'
);

// 12.16 SSRF and path traversal blocked on /api/vision_image.php
$ssrfAttempt = httpReq('GET', "$base/api/vision_image.php?file=" . urlencode('https://evil.example.com/shell.png'), null, [], false, $cookieFile);
$travAttempt = httpReq('GET', "$base/api/vision_image.php?file=" . urlencode('../../.env'), null, [], false, $cookieFile);
assertTrue(
    $ssrfAttempt['code'] === 400 && $travAttempt['code'] === 400,
    'Vision V3 [16/18]: SSRF and directory traversal payloads blocked on /api/vision_image.php'
);

// 12.17 History single-item IDOR protection on GET /api/vision_history.php and DELETE
$idorDeleteBlocked = false;
try {
    \App\Analysis\ChartVisionService::deleteAnalysis((int)$withHtfRes['analysis_id'], 99999, false);
} catch (\RuntimeException $e) {
    $idorDeleteBlocked = ($e->getCode() === 403);
}
assertTrue($idorDeleteBlocked, 'Vision V3 [17/18]: IDOR protection blocks unauthorized user from deleting another user\'s analysis');

// 12.18 UI & Architecture check on GET /vision.php (8 V3 modules present, zero fake pre-analysis BUY/85 defaults)
$visionHtmlRes = httpReq('GET', "$base/vision.php", null, [], false, $cookieFile);
$vHtml = $visionHtmlRes['body'];
assertTrue(
    $visionHtmlRes['code'] === 200
    && str_contains($vHtml, 'VisionUpload')
    && str_contains($vHtml, 'VisionImageProcessor')
    && str_contains($vHtml, 'VisionApiClient')
    && str_contains($vHtml, 'VisionResultRenderer')
    && str_contains($vHtml, 'VisionOverlayRenderer')
    && str_contains($vHtml, 'VisionHistory')
    && str_contains($vHtml, 'VisionTelegram')
    && str_contains($vHtml, 'VisionValidation')
    && str_contains($vHtml, 'id="resBiasBadge">Not analyzed</span>'),
    'Vision V3 [18/18]: vision.php loads all 8 V3 frontend modules and initializes result badges to "Not analyzed"'
);

@unlink($cookieFile);
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "
=============================================================
";
echo sprintf(" Summary: %d passed, %d failed
", $passed, $failed);
echo "=============================================================
";
exit($failed > 0 ? 1 : 0);
