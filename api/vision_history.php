<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(50, (int)($_GET['per_page'] ?? ($_GET['limit'] ?? 30))));

    $filters = [
        'symbol'    => isset($_GET['symbol']) ? trim((string)$_GET['symbol']) : '',
        'timeframe' => isset($_GET['timeframe']) ? trim((string)$_GET['timeframe']) : '',
        'strategy'  => isset($_GET['strategy']) ? trim((string)$_GET['strategy']) : '',
        'direction' => isset($_GET['direction']) ? trim((string)$_GET['direction']) : '',
        'date_from' => isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '',
        'date_to'   => isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '',
    ];

    $result = ChartVisionService::getPaginatedHistory((int)$user['id'], $filters, $page, $perPage);
    $items = $result['items'];

    Response::json([
        'ok'         => true,
        'count'      => count($items),
        'items'      => $items,
        'analyses'   => $items,
        'pagination' => $result['pagination'],
    ]);
}

if ($method === 'DELETE' || ($method === 'POST' && ($_POST['_method'] ?? '') === 'DELETE')) {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ($_POST['csrf_token'] ?? null));
    if (!Csrf::validate(is_string($csrfHeader) ? $csrfHeader : null)) {
        Response::json(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }
    $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
    if ($id <= 0) {
        Response::json(['ok' => false, 'error' => 'Valid ID required.'], 422);
    }

    $deleted = Database::query('DELETE FROM chart_vision_analyses WHERE id = ? AND user_id = ?', [$id, (int)$user['id']])->rowCount();
    Response::json(['ok' => true, 'deleted' => $deleted > 0]);
}

Response::json(['ok' => false, 'error' => 'Method not allowed.'], 405);
