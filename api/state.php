<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AnalysisService;
use App\Core\Response;
use App\Market\MarketDataService;

$user = Response::apiInit('trader', 120);
$tf = strtoupper(trim((string)($_GET['tf'] ?? 'M15')));
$symbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');

$service = new AnalysisService(null, null, null, $symbol);
$state = $service->dashboardState($tf, (int)$user['id']);

Response::ok($state);
