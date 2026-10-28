<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Backtest\Backtester;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;

$user = Response::apiInit('trader', 30);

if (Response::method() === 'GET') {
    if (isset($_GET['emergency_status'])) {
        Response::ok([
            'emergency_stop' => (bool)Settings::get('risk.emergency_stop', false),
        ]);
    }

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $id = (int)$_GET['id'];
        $run = Database::fetch('SELECT * FROM backtest_runs WHERE id = ?', [$id]);
        if (!$run) {
            Response::error('Backtest run not found', 404);
        }
        $run['params'] = $run['params'] ? json_decode((string)$run['params'], true) : [];
        $run['stats'] = $run['stats'] ? json_decode((string)$run['stats'], true) : [];
        $trades = Database::fetchAll('SELECT * FROM backtest_trades WHERE run_id = ? ORDER BY trade_no ASC', [$id]);
        Response::ok([
            'run'            => $run,
            'trades'         => $trades,
            'emergency_stop' => (bool)Settings::get('risk.emergency_stop', false),
        ]);
    }

    $runs = Database::fetchAll('SELECT * FROM backtest_runs ORDER BY id DESC LIMIT 40');
    foreach ($runs as &$r) {
        $r['params'] = $r['params'] ? json_decode((string)$r['params'], true) : [];
        $r['stats'] = $r['stats'] ? json_decode((string)$r['stats'], true) : [];
    }
    unset($r);
    Response::ok([
        'runs'           => $runs,
        'emergency_stop' => (bool)Settings::get('risk.emergency_stop', false),
    ]);
}

$in = Response::input();
$action = (string)($in['action'] ?? 'run');

if ($action === 'emergency_stop') {
    $current = (bool)Settings::get('risk.emergency_stop', false);
    $newState = isset($in['enabled']) ? (bool)$in['enabled'] : !$current;
    Settings::set('risk.emergency_stop', $newState, (int)$user['id']);
    Logger::audit('emergency_stop_toggled', 'system_settings', null, ['emergency_stop' => $newState], (int)$user['id']);
    Response::ok([
        'emergency_stop' => $newState,
        'message'        => $newState ? 'Emergency Stop ACTIVATED. All new trade setups are halted.' : 'Emergency Stop DEACTIVATED. Normal analysis resumed.',
    ]);
}

if ($action === 'delete') {
    $id = Validator::int($in['id'] ?? 0, 'id', 1);
    Database::query('DELETE FROM backtest_runs WHERE id = ?', [$id]);
    Logger::audit('delete_backtest', 'backtest_run', $id, [], (int)$user['id']);
    Response::ok(['deleted' => $id]);
}

try {
    $bt = new Backtester();
    $res = $bt->run($in, (int)$user['id']);
    Logger::audit('run_backtest', 'backtest_run', $res['run_id'], ['timeframe' => $in['timeframe'] ?? 'M15'], (int)$user['id']);
    $run = Database::fetch('SELECT * FROM backtest_runs WHERE id = ?', [$res['run_id']]);
    $run['params'] = $run['params'] ? json_decode((string)$run['params'], true) : [];
    $run['stats'] = $run['stats'] ? json_decode((string)$run['stats'], true) : [];
    $trades = Database::fetchAll('SELECT * FROM backtest_trades WHERE run_id = ? ORDER BY trade_no ASC', [$res['run_id']]);
    Response::ok([
        'run'            => $run,
        'trades'         => $trades,
        'emergency_stop' => (bool)Settings::get('risk.emergency_stop', false),
    ]);
} catch (\Throwable $e) {
    Response::error($e->getMessage(), 422);
}
