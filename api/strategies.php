<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Market\MarketDataService;
use App\SMC\IctEngine;
use App\SMC\SmcEngine;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = Database::fetchAll('SELECT * FROM ict_strategies ORDER BY category ASC, weight DESC');
    
    // Check live detection status for XAUUSD on M15
    $market = new MarketDataService(null, 'XAUUSD');
    $c = $market->candles('M15', 80);
    $activeCodes = [];
    $killzone = IctEngine::killzones(time());
    $macro = IctEngine::macroWindows(time());

    if (count($c) >= 20) {
        $smc = (new SmcEngine())->analyze($c);
        $ict = (new IctEngine())->analyze($c, $smc, time());
        if (!empty($ict['ok'])) {
            $killzone = $ict['killzone'] ?? $killzone;
            $macro = $ict['macro'] ?? $macro;
            foreach ($ict['detected_strategies'] ?? [] as $ds) {
                $activeCodes[$ds['code']] = $ds;
            }
        }
    }

    $strategies = [];
    foreach ($rows as $r) {
        $code = $r['code'];
        $isDetected = isset($activeCodes[$code]);
        $strategies[] = [
            'id'              => (int)$r['id'],
            'code'            => $code,
            'name'            => $r['name'],
            'category'        => $r['category'],
            'description'     => $r['description'],
            'rules_summary'   => $r['rules_summary'],
            'is_enabled'      => (bool)$r['is_enabled'],
            'weight'          => (int)$r['weight'],
            'timeframes'      => json_decode((string)$r['timeframes_json'], true) ?: ['M15'],
            'params'          => json_decode((string)$r['params_json'], true) ?: [],
            'is_detected_live'=> $isDetected,
            'live_data'       => $isDetected ? $activeCodes[$code]['data'] : null,
            'updated_at'      => $r['updated_at'],
        ];
    }

    Response::json([
        'ok'               => true,
        'count'            => count($strategies),
        'strategies'       => $strategies,
        'killzone'         => $killzone,
        'macro'            => $macro,
        'symbol'           => 'XAUUSD',
        'timeframe'        => 'M15',
    ]);
}

if ($method === 'POST') {
    $input = $_POST;
    if (empty($input)) {
        $raw = (string)file_get_contents('php://input');
        $jsonDec = json_decode($raw, true);
        if (is_array($jsonDec)) {
            $input = $jsonDec;
        }
    }

    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
    if (!Csrf::validate($csrfHeader)) {
        Response::json(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $code = trim((string)($input['code'] ?? ''));
    if ($code === '') {
        Response::json(['ok' => false, 'error' => 'Strategy code required.'], 422);
    }

    $update = [];
    if (isset($input['is_enabled']) || isset($input['active'])) {
        $update['is_enabled'] = (!empty($input['is_enabled']) || !empty($input['active'])) ? 1 : 0;
    }
    if (isset($input['weight']) && is_numeric($input['weight'])) {
        $update['weight'] = max(1, min(100, (int)$input['weight']));
    }
    if ($update) {
        $update['updated_at'] = gmdate('Y-m-d H:i:s');
        Database::update('ict_strategies', $update, 'code = ?', [$code]);
    }

    Response::json(['ok' => true, 'code' => $code, 'updated' => $update]);
}

Response::json(['ok' => false, 'error' => 'Method not allowed.'], 405);
