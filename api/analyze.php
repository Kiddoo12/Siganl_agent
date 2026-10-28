<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AnalysisService;
use App\Core\Response;
use App\Market\MarketDataService;

$user = Response::apiInit('trader', 30);
if (Response::method() !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = Response::input();
$force = !empty($in['force']);
$autoAll = !empty($in['auto']);
$tf = strtoupper(trim((string)($in['tf'] ?? 'M15')));
$symbol = MarketDataService::normalizeSymbol($in['symbol'] ?? 'XAUUSD');

if ($autoAll) {
    foreach (MarketDataService::SUPPORTED_SYMBOLS as $sym) {
        if ($sym !== $symbol) {
            (new AnalysisService(null, null, null, $sym))->run(false);
        }
    }
}

$service = new AnalysisService(null, null, null, $symbol);
$service->run($force);
$state = $service->dashboardState($tf, (int)$user['id']);

Response::ok($state);
