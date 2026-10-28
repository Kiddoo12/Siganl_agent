<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Notifier;
use App\Core\Response;

$user = Response::apiInit('trader', 60);
$uid = (int)$user['id'];

if (Response::method() === 'GET') {
    $rows = Database::fetchAll(
        'SELECT * FROM notification_logs WHERE user_id = ? ORDER BY id DESC LIMIT 50',
        [$uid]
    );
    $unread = (int)Database::value(
        "SELECT COUNT(*) FROM notification_logs WHERE user_id = ? AND channel = 'inapp' AND is_read = 0",
        [$uid]
    );
    Response::ok(['notifications' => $rows, 'unread' => $unread]);
}

$in = Response::input();
$action = (string)($in['action'] ?? 'mark_read');

if ($action === 'mark_read') {
    if (!empty($in['id'])) {
        Database::update('notification_logs', ['is_read' => 1], 'id = ? AND user_id = ?', [(int)$in['id'], $uid]);
    } else {
        Database::update('notification_logs', ['is_read' => 1], 'user_id = ?', [$uid]);
    }
    Response::ok();
}

if ($action === 'test') {
    $channel = strtolower(trim((string)($in['channel'] ?? 'inapp')));
    if (!in_array($channel, ['inapp', 'telegram', 'email'], true)) {
        Response::error('Invalid notification channel', 422);
    }
    $res = Notifier::sendTest($uid, $channel);
    if (!$res['ok']) {
        Response::error($res['error'] ?? 'Notification test failed', 422, ['result' => $res]);
    }
    Response::ok(['result' => $res]);
}

if ($action === 'send_signal') {
    $symbol = strtoupper(trim((string)($in['symbol'] ?? 'XAUUSD')));
    $row = Database::fetch(
        "SELECT * FROM trading_signals WHERE symbol = ? AND direction IN ('BUY','SELL') ORDER BY id DESC LIMIT 1",
        [$symbol]
    );
    if (!$row) {
        Response::error("No active BUY/SELL signal found for {$symbol}", 422);
    }
    $sigPayload = [
        'asset'         => $row['symbol'],
        'data_mode'     => $row['data_mode'],
        'direction'     => $row['direction'],
        'label'         => $row['signal_label'],
        'setup_status'  => $row['setup_status'],
        'setup_type'    => $row['setup_type'],
        'entry_tf'      => $row['entry_tf'],
        'entry_low'     => $row['entry_low'],
        'entry_high'    => $row['entry_high'],
        'entry'         => $row['entry_price'],
        'sl'            => $row['stop_loss'],
        'tp1'           => $row['tp1'],
        'rr1'           => $row['rr1'],
        'tp2'           => $row['tp2'],
        'rr2'           => $row['rr2'],
        'quality_score' => $row['quality_score'],
        'data_ref'      => strtotime($row['data_ref'] . ' UTC'),
    ];
    $sent = Notifier::notifySignal($sigPayload, (int)$row['id'], true);
    if ($sent <= 0) {
        $lastErr = Database::value(
            "SELECT error FROM notification_logs WHERE user_id = ? AND channel = 'telegram' ORDER BY id DESC LIMIT 1",
            [$uid]
        );
        Response::error($lastErr ?: 'Telegram notification could not be sent. Verify your Bot Token in Settings.', 422);
    }
    Response::ok(['sent' => $sent, 'message' => "Signal sent to Telegram (@i_am_Theaa_Rithhh)"]);
}

if ($action === 'test_tp_sl') {
    $eventType = strtolower(trim((string)($in['event_type'] ?? 'tp1_hit')));
    if (!in_array($eventType, ['tp1_hit', 'tp2_hit', 'sl_hit', 'entry_filled'], true)) {
        $eventType = 'tp1_hit';
    }
    $row = Database::fetch(
        "SELECT * FROM trading_signals WHERE direction IN ('BUY','SELL') ORDER BY id DESC LIMIT 1"
    );
    if (!$row) {
        $row = [
            'id'           => 1,
            'symbol'       => 'XAUUSD',
            'data_mode'    => 'live',
            'direction'    => 'SELL',
            'signal_label' => 'SELL SETUP',
            'entry_tf'     => 'M15',
            'entry_price'  => 4003.40,
            'stop_loss'    => 4007.85,
            'tp1'          => 3992.42,
            'rr1'          => 2.47,
            'tp2'          => 3981.33,
            'rr2'          => 4.96,
        ];
    }
    $row['data_mode'] = 'live';
    $hitPrice = match ($eventType) {
        'tp1_hit'      => (float)($row['tp1'] ?? $row['entry_price']),
        'tp2_hit'      => (float)($row['tp2'] ?? $row['tp1'] ?? $row['entry_price']),
        'sl_hit'       => (float)($row['stop_loss'] ?? $row['entry_price']),
        default        => (float)($row['entry_price']),
    };
    $meta = match ($eventType) {
        'tp1_hit' => ['rr' => (float)($row['rr1'] ?? 2.0), 'test' => true],
        'tp2_hit' => ['rr' => (float)($row['rr2'] ?? 3.5), 'final_r' => (float)($row['rr2'] ?? 3.5), 'test' => true],
        'sl_hit'  => ['is_be' => false, 'final_r' => -1.0, 'test' => true],
        default   => ['test' => true],
    };
    $sent = Notifier::notifyTradeEvent($row, $eventType, $hitPrice, $meta);
    if ($sent <= 0) {
        Response::error('Could not dispatch TP/SL notification. Check your Telegram settings.', 422);
    }
    Response::ok(['sent' => $sent, 'event_type' => $eventType]);
}

if ($action === 'test_prepare') {
    $symbol = strtoupper(trim((string)($in['symbol'] ?? 'XAUUSD')));
    $row = Database::fetch(
        "SELECT * FROM trading_signals WHERE symbol = ? ORDER BY id DESC LIMIT 1",
        [$symbol]
    );
    $entry = (float)($row['entry_price'] ?? ($symbol === 'BTCUSD' ? 80058.37 : 4003.50));
    $sl = (float)($row['stop_loss'] ?? ($symbol === 'BTCUSD' ? 80428.55 : 4007.20));
    $tp1 = (float)($row['tp1'] ?? ($symbol === 'BTCUSD' ? 79300.10 : 3992.40));
    $tp2 = (float)($row['tp2'] ?? ($symbol === 'BTCUSD' ? 78850.00 : 3981.30));
    $payload = [
        'asset'         => $symbol,
        'data_mode'     => 'live',
        'entry_tf'      => $row['entry_tf'] ?? 'M15',
        'direction'     => $row['direction'] ?? 'SELL',
        'entry_low'     => $row['entry_low'] ?? $entry,
        'entry_high'    => $row['entry_high'] ?? $entry,
        'entry'         => $entry,
        'sl'            => $sl,
        'tp1'           => $tp1,
        'rr1'           => $row['rr1'] ?? 2.5,
        'tp2'           => $tp2,
        'rr2'           => $row['rr2'] ?? 4.2,
        'quality_score' => (int)($row['quality_score'] ?? 82),
        'readiness'     => [
            'stage'          => 'nearing_confirmation',
            'ready'          => true,
            'direction'      => ($row['direction'] ?? 'WAIT') !== 'WAIT' ? $row['direction'] : 'SELL',
            'met_count'      => 7,
            'total_count'    => 8,
            'score'          => (int)($row['quality_score'] ?? 82),
            'missing_labels' => ['Final candle confirmation'],
        ],
    ];
    $sent = Notifier::notifyPrepareEntry($payload, $row ? (int)$row['id'] : null, true);
    if ($sent <= 0) {
        Response::error('Could not dispatch Prepare-for-Entry notification.', 422);
    }
    Response::ok(['sent' => $sent, 'payload' => $payload]);
}

Response::error('Unknown action', 400);
