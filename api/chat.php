<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AiChatService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Validator;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'history');
    $symbol = Validator::str($_GET['symbol'] ?? 'XAUUSD', 1, 16);

    if ($action === 'history') {
        $history = AiChatService::getHistory((int)$user['id'], $symbol, 50);
        Response::json([
            'ok'      => true,
            'symbol'  => $symbol,
            'history' => $history,
        ]);
    }

    Response::json(['ok' => false, 'error' => 'Unknown action.'], 400);
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
        Response::json(['ok' => false, 'error' => 'Invalid or expired CSRF token.'], 403);
    }

    $action = (string)($input['action'] ?? 'send');
    $symbol = Validator::str($input['symbol'] ?? 'XAUUSD', 1, 16);

    if ($action === 'clear') {
        AiChatService::clearHistory((int)$user['id'], $symbol);
        Response::json(['ok' => true, 'message' => 'Chat history cleared.']);
    }

    $message = trim((string)($input['message'] ?? ''));
    if ($message === '') {
        Response::json(['ok' => false, 'error' => 'Message cannot be empty.'], 422);
    }

    $strategyMode = strtoupper(trim((string)($input['strategy_mode'] ?? 'ALL')));
    $timeframe = Validator::str($input['timeframe'] ?? 'M15', 1, 8);

    try {
        $res = AiChatService::sendMessage(
            (int)$user['id'],
            $message,
            $strategyMode,
            $symbol,
            $timeframe
        );

        Response::json($res);
    } catch (\Throwable $e) {
        Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

Response::json(['ok' => false, 'error' => 'Method not allowed.'], 405);
