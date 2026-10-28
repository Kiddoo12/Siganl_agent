<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\CalendarService;
use App\Core\Auth;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Settings;

$user = Response::apiInit('trader', 60);
$cal = new CalendarService();

if (Response::method() === 'GET') {
    $daysBack = isset($_GET['days_back']) ? max(0, min(30, (int)$_GET['days_back'])) : 2;
    $daysAhead = isset($_GET['days_ahead']) ? max(1, min(60, (int)$_GET['days_ahead'])) : 7;
    $impact = isset($_GET['impact']) && in_array($_GET['impact'], ['high', 'medium', 'low', 'holiday'], true) ? $_GET['impact'] : null;
    $country = isset($_GET['country']) && $_GET['country'] !== '' ? strtoupper(trim((string)$_GET['country'])) : null;

    $now = time();
    $events = $cal->eventsBetween($now - $daysBack * 86400, $now + $daysAhead * 86400, $impact, $country);
    Response::ok([
        'provider'  => $cal->providerCode(),
        'last_sync' => Settings::state('calendar.last_sync'),
        'events'    => $events,
    ]);
}

$in = Response::input();
$action = (string)($in['action'] ?? 'sync');

if ($action === 'sync') {
    $res = $cal->sync(true);
    Response::ok(['sync' => $res]);
}

if (!Auth::hasRole('analyst')) {
    Response::error('Forbidden: analyst or admin role required to modify events', 403);
}

if ($action === 'add') {
    try {
        $id = $cal->addManual($in, (int)$user['id']);
        Logger::audit('calendar_add_manual', 'economic_event', $id, ['title' => $in['title'] ?? ''], (int)$user['id']);
        Response::ok(['id' => $id]);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) {
        Response::error('Invalid event id', 422);
    }
    $cal->deleteEvent($id);
    Logger::audit('calendar_delete_event', 'economic_event', $id, [], (int)$user['id']);
    Response::ok(['deleted' => $id]);
}

Response::error('Unknown action', 400);
