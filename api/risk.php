<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\RiskCalculator;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Market\MarketDataService;

$user = Response::apiInit('trader', 120);
$in = Response::method() === 'POST' ? Response::input() : $_GET;
$symbol = MarketDataService::normalizeSymbol($in['symbol'] ?? 'XAUUSD');

$uSettings = Database::fetch('SELECT account_balance, risk_percent FROM user_settings WHERE user_id = ?', [$user['id']]);
$defaultBal = $uSettings ? (float)$uSettings['account_balance'] : 10000.0;
$defaultRisk = $uSettings ? (float)$uSettings['risk_percent'] : 0.5;

try {
    $balance = isset($in['balance']) && $in['balance'] !== ''
        ? Validator::float($in['balance'], 'balance', 1.0, 100000000.0)
        : $defaultBal;
    $riskPct = isset($in['risk_percent']) && $in['risk_percent'] !== ''
        ? Validator::float($in['risk_percent'], 'risk_percent', 0.01, 50.0)
        : $defaultRisk;
    $entry = Validator::float($in['entry'] ?? null, 'entry', 1.0, 1000000.0);
    $sl = Validator::float($in['stop_loss'] ?? ($in['sl'] ?? null), 'stop_loss', 1.0, 1000000.0);
    $tp1 = isset($in['tp1']) && $in['tp1'] !== '' ? Validator::float($in['tp1'], 'tp1', 1.0, 1000000.0) : null;
    $tp2 = isset($in['tp2']) && $in['tp2'] !== '' ? Validator::float($in['tp2'], 'tp2', 1.0, 1000000.0) : null;
    $tp3 = isset($in['tp3']) && $in['tp3'] !== '' ? Validator::float($in['tp3'], 'tp3', 1.0, 1000000.0) : null;
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

$calc = new RiskCalculator(null, $symbol);
$res = $calc->calculate($balance, $riskPct, $entry, $sl, $tp1, $tp2, $tp3);
if (!$res['ok']) {
    Response::error($res['error'], 422);
}

Response::ok(['calculation' => $res]);
