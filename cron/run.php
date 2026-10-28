<?php
declare(strict_types=1);

/**
 * Periodic cron runner (CLI or HTTP with ?token=CRON_TOKEN).
 * Ingests market candles for XAUUSD and BTCUSD, syncs the economic calendar,
 * runs multi-timeframe SMC analysis, updates open signal outcomes, and sends due notifications.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AnalysisService;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Notifier;
use App\Core\Response;
use App\Core\Settings;
use App\Market\MarketDataService;

if (PHP_SAPI !== 'cli') {
    $expected = trim((string)Env::get('CRON_TOKEN', ''));
    $given = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($expected === '' || $expected === 'change-me-to-a-long-random-string' || !hash_equals($expected, $given)) {
        Response::error('Forbidden: invalid or unconfigured CRON_TOKEN', 403);
    }
}

$t0 = microtime(true);
$symbolsReport = [];
$result = [];

foreach (MarketDataService::SUPPORTED_SYMBOLS as $sym) {
    $service = new AnalysisService(null, null, null, $sym);
    $res = $service->run(false);
    $symbolsReport[$sym] = [
        'ok'               => !empty($res['ok']),
        'price'            => $res['price'] ?? null,
        'htf_bias'         => $res['htf_bias'] ?? null,
        'signal_direction' => $res['signal']['direction'] ?? null,
        'signal_label'     => $res['signal']['label'] ?? null,
        'quality_score'    => $res['signal']['quality_score'] ?? null,
        'analysis_id'      => $res['analysis_id'] ?? null,
        'signal_id'        => $res['signal_id'] ?? null,
    ];
    if ($sym === 'XAUUSD') {
        $result = $res;
    }
}

// Check for upcoming high-impact news within notify.news_alert_minutes
$alertMin = (int)Settings::get('notify.news_alert_minutes', 30);
$now = time();
$calService = new \App\Analysis\CalendarService();
$upcoming = $calService->eventsBetween($now, $now + $alertMin * 60, 'high');
$newsAlertsSent = 0;
foreach ($upcoming as $ev) {
    $evTs = (int)strtotime($ev['event_time'] . ' UTC');
    $minsLeft = max(1, (int)round(($evTs - $now) / 60));
    $title = sprintf('High-Impact %s News in %d min: %s', $ev['country'], $minsLeft, $ev['title']);
    $msg = sprintf(
        "Event: %s (%s)\nTime: %s UTC\nForecast: %s | Previous: %s\nNote: New SMC trade setups are automatically blocked around high-impact releases.",
        $ev['title'],
        $ev['country'],
        gmdate('Y-m-d H:i', $evTs),
        $ev['forecast'] ?? 'n/a',
        $ev['previous'] ?? 'n/a'
    );
    $newsAlertsSent += Notifier::broadcastEvent('news', $title, $msg, 'news:' . $ev['id']);
}

$summary = [
    'ok'               => !empty($result['ok']),
    'elapsed_ms'       => (int)round((microtime(true) - $t0) * 1000),
    'data_mode'        => $result['data_mode'] ?? null,
    'price'            => $result['price'] ?? null,
    'htf_bias'         => $result['htf_bias'] ?? null,
    'signal_direction' => $result['signal']['direction'] ?? null,
    'signal_label'     => $result['signal']['label'] ?? null,
    'quality_score'    => $result['signal']['quality_score'] ?? null,
    'analysis_id'      => $result['analysis_id'] ?? null,
    'signal_id'        => $result['signal_id'] ?? null,
    'symbols'          => $symbolsReport,
    'news_alerts_sent' => $newsAlertsSent,
];

Logger::info('Cron run completed', $summary);

if (PHP_SAPI === 'cli') {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

Response::ok($summary);
