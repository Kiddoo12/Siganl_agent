<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\SignalTracker;
use App\Core\Database;
use App\Core\Response;
use App\Market\MarketDataService;

Response::apiInit('trader', 120);

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sig = Database::fetch(
        'SELECT s.*, o.outcome, o.entry_filled_at, o.exit_at, o.exit_price, o.exit_reason,
                o.r_multiple, o.is_simulated, o.ambiguous, o.max_favorable_r, o.max_adverse_r
         FROM trading_signals s
         LEFT JOIN signal_outcomes o ON o.signal_id = s.id
         WHERE s.id = ?',
        [$id]
    );
    if (!$sig) {
        Response::error('Signal not found', 404);
    }
    foreach (['score_breakdown', 'confluences', 'reasons', 'invalidations', 'warnings'] as $jsonCol) {
        $sig[$jsonCol] = $sig[$jsonCol] ? json_decode((string)$sig[$jsonCol], true) : [];
    }
    $events = Database::fetchAll('SELECT * FROM signal_events WHERE signal_id = ? ORDER BY event_time ASC, id ASC', [$id]);
    Response::ok(['signal' => $sig, 'events' => $events]);
}

$market = new MarketDataService();
$mode = strtolower(trim((string)($_GET['data_mode'] ?? $market->mode())));
if (!in_array($mode, ['live', 'demo', 'all'], true)) {
    $mode = $market->mode();
}

$where = ['1=1'];
$params = [];
if ($mode !== 'all') {
    $where[] = 's.data_mode = ?';
    $params[] = $mode;
}
$symbolParam = isset($_GET['symbol']) && trim((string)$_GET['symbol']) !== ''
    ? MarketDataService::normalizeSymbol((string)$_GET['symbol'])
    : null;
if ($symbolParam !== null) {
    $where[] = 's.symbol = ?';
    $params[] = $symbolParam;
}
if (!empty($_GET['direction']) && in_array($_GET['direction'], ['BUY', 'SELL', 'WAIT'], true)) {
    $where[] = 's.direction = ?';
    $params[] = $_GET['direction'];
}
if (!empty($_GET['status']) && in_array($_GET['status'], ['potential', 'confirmed', 'triggered', 'invalidated', 'expired', 'closed'], true)) {
    $where[] = 's.setup_status = ?';
    $params[] = $_GET['status'];
}
if (!empty($_GET['outcome']) && in_array($_GET['outcome'], ['pending', 'open', 'win', 'loss', 'breakeven', 'invalidated', 'expired'], true)) {
    $where[] = 'o.outcome = ?';
    $params[] = $_GET['outcome'];
}
if (isset($_GET['min_score']) && is_numeric($_GET['min_score'])) {
    $where[] = 's.quality_score >= ?';
    $params[] = (int)$_GET['min_score'];
}

$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
$sql = 'SELECT s.*, o.outcome, o.entry_filled_at, o.exit_at, o.exit_price, o.exit_reason,
               o.r_multiple, o.ambiguous, o.max_favorable_r, o.max_adverse_r
        FROM trading_signals s
        LEFT JOIN signal_outcomes o ON o.signal_id = s.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.id DESC
        LIMIT ' . $limit;

$rows = Database::fetchAll($sql, $params);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gold-smc-signals-' . gmdate('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Created UTC', 'Data Mode', 'Symbol', 'TF', 'Direction', 'Label', 'Setup Type',
        'Status', 'Entry Low', 'Entry High', 'Entry Price', 'Stop Loss', 'TP1', 'TP2', 'TP3',
        'RR1', 'RR2', 'Score', 'Session', 'Outcome', 'R Multiple', 'Exit Reason', 'Exit UTC',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['data_mode'], $r['symbol'], $r['entry_tf'],
            $r['direction'], $r['signal_label'], $r['setup_type'], $r['setup_status'],
            $r['entry_low'], $r['entry_high'], $r['entry_price'], $r['stop_loss'],
            $r['tp1'], $r['tp2'], $r['tp3'], $r['rr1'], $r['rr2'], $r['quality_score'],
            $r['session'], $r['outcome'] ?? 'n/a', $r['r_multiple'], $r['exit_reason'], $r['exit_at'],
        ]);
    }
    fclose($out);
    exit;
}

foreach ($rows as &$r) {
    foreach (['score_breakdown', 'confluences', 'reasons', 'invalidations', 'warnings'] as $jsonCol) {
        $r[$jsonCol] = $r[$jsonCol] ? json_decode((string)$r[$jsonCol], true) : [];
    }
}
unset($r);

$tracker = new SignalTracker();
$statsMode = $mode === 'all' ? $market->mode() : $mode;

Response::ok([
    'signals'     => $rows,
    'performance' => $tracker->performanceStats($statsMode, $symbolParam),
]);
