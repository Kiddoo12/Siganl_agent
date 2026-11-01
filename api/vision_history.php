<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;

$sendError = static function (string $code, string $message, int $httpStatus = 422): void {
    Response::json([
        'ok'            => false,
        'data'          => null,
        'error'         => [
            'code'    => $code,
            'message' => $message,
        ],
        'error_message' => $message,
    ], $httpStatus);
};

Auth::startSession();
$user = Auth::user();
if (!$user) {
    $sendError('UNAUTHENTICATED', 'Authentication required.', 401);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    // Optional single-item fetch by ID with strict ownership check
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $id = (int)$_GET['id'];
        $row = Database::fetch('SELECT * FROM chart_vision_analyses WHERE id = ?', [$id]);
        if (!$row) {
            $sendError('NOT_FOUND', 'Analysis record not found.', 404);
        }
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $ownerId = $row['user_id'] !== null ? (int)$row['user_id'] : (int)$user['id'];
        if (!$isAdmin && $ownerId !== (int)$user['id']) {
            $sendError('FORBIDDEN', 'Unauthorized: You do not own this chart analysis record.', 403);
        }
        $item = [
            'id'                => (int)$row['id'],
            'symbol'            => $row['symbol'],
            'timeframe'         => $row['timeframe'],
            'strategy_mode'     => $row['strategy_mode'] ?? 'ALL',
            'htf_timeframe'     => $row['htf_timeframe'] ?? ChartVisionService::defaultHigherTimeframe((string)$row['timeframe']),
            'image_url'         => 'api/vision_image.php?id=' . (int)$row['id'],
            'market_bias'       => $row['market_bias'],
            'trade_direction'   => $row['trade_direction'] ?? 'NO TRADE',
            'detected_strategy' => $row['detected_strategy'],
            'confidence_score'  => (int)$row['confidence_score'],
            'ai_model'          => $row['ai_model'],
            'report'            => json_decode((string)$row['analysis_json'], true),
            'overlay'           => json_decode((string)$row['overlay_json'], true),
            'created_at'        => $row['created_at'],
        ];
        Response::json([
            'ok'    => true,
            'data'  => $item,
            'error' => null,
            'item'  => $item,
        ]);
    }

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

    $payload = [
        'count'      => count($items),
        'items'      => $items,
        'analyses'   => $items,
        'pagination' => $result['pagination'],
    ];

    Response::json([
        'ok'    => true,
        'data'  => $payload,
        'error' => null,
    ] + $payload);
}

$rawInput = [];
if ($method === 'DELETE' || $method === 'POST') {
    $rawBody = (string)file_get_contents('php://input');
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $rawInput = $decoded;
        }
    }
}

$effectiveMethod = $method;
if ($method === 'POST' && strtoupper((string)($_POST['_method'] ?? ($rawInput['_method'] ?? ''))) === 'DELETE') {
    $effectiveMethod = 'DELETE';
}

if ($effectiveMethod === 'DELETE') {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ($rawInput['_csrf'] ?? ($_POST['csrf_token'] ?? null)));
    if (!Csrf::validate(is_string($csrfHeader) ? $csrfHeader : null)) {
        $sendError('CSRF_INVALID', 'Invalid CSRF token.', 403);
    }

    $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : (int)($rawInput['id'] ?? 0);
    if ($id <= 0) {
        $sendError('INVALID_ID', 'Valid analysis ID is required.', 422);
    }

    try {
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $deleted = ChartVisionService::deleteAnalysis($id, (int)$user['id'], $isAdmin);
        $payload = ['deleted' => $deleted, 'id' => $id];
        Response::json([
            'ok'    => true,
            'data'  => $payload,
            'error' => null,
        ] + $payload);
    } catch (\RuntimeException $e) {
        $sendError('FORBIDDEN', $e->getMessage(), 403);
    }
}

$sendError('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
