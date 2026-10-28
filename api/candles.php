<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AnalysisService;
use App\Analysis\SessionService;
use App\Analysis\SignalTracker;
use App\Core\Response;
use App\Core\Settings;
use App\Market\MarketDataService;
use App\Market\Timeframe;
use App\SMC\SmcEngine;

Response::apiInit('trader', 120);

$tf = strtoupper(trim((string)($_GET['tf'] ?? 'M15')));
if (!Timeframe::valid($tf)) {
    Response::error('Invalid timeframe: ' . $tf, 400);
}
$symbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');
$limit = isset($_GET['limit']) ? max(50, min(500, (int)$_GET['limit'])) : 220;

$market = new MarketDataService(null, $symbol);
$forceLive = !empty($_GET['live']);
$market->ingest($tf, $forceLive);
$candles = $market->candles($tf, $limit + 50);
if (count($candles) < 40) {
    $market->ingest($tf, true);
    $candles = $market->candles($tf, $limit + 50);
}

$now = time();
$quote = $market->quote($forceLive);
if ($forceLive) {
    (new SignalTracker())->updateOpenSignals($market, $now);
}
$closed = Timeframe::closedOnly($tf, $candles, $now);
$engine = new SmcEngine((array)Settings::get('smc', []));
$smc = $engine->analyze($closed);
$sessions = new SessionService();
$service = new AnalysisService($market);

$displayCandles = array_slice($candles, -$limit);
if ($displayCandles && !empty($quote['available']) && !empty($quote['price'])) {
    $lp = round((float)$quote['price'], 2);
    $lastIdx = count($displayCandles) - 1;
    $displayCandles[$lastIdx]['c'] = $lp;
    $displayCandles[$lastIdx]['h'] = max($displayCandles[$lastIdx]['h'], $lp);
    $displayCandles[$lastIdx]['l'] = min($displayCandles[$lastIdx]['l'], $lp);
}

Response::ok([
    'tf'              => $tf,
    'symbol'          => $market->symbol(),
    'data_mode'       => $market->mode(),
    'quote'           => $quote,
    'market_status'   => MarketDataService::marketStatus($now, $market->symbol()),
    'active_session'  => $sessions->label($now),
    'server_time_utc' => $now,
    'candles'         => $displayCandles,
    'smc'             => $service->publicSmc($smc),
    'session_ranges'  => $sessions->ranges($closed, $tf, $now),
]);
