<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\RateLimiter;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sendError('METHOD_NOT_ALLOWED', 'Method not allowed. Use POST.', 405);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$input = $_POST;
if (empty($input)) {
    $raw = (string)file_get_contents('php://input');
    if (str_contains($contentType, 'application/json') && trim($raw) !== '') {
        $jsonDec = json_decode($raw, true);
        if (!is_array($jsonDec) || json_last_error() !== JSON_ERROR_NONE) {
            $sendError('INVALID_JSON', 'Malformed JSON payload.', 400);
        }
        $input = $jsonDec;
    } elseif ($raw !== '') {
        $jsonDec = json_decode($raw, true);
        if (is_array($jsonDec)) {
            $input = $jsonDec;
        }
    }
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? ($input['csrf_token'] ?? null));
if (!Csrf::validate(is_string($csrfHeader) ? $csrfHeader : null)) {
    $sendError('CSRF_INVALID', 'Invalid or expired CSRF token.', 403);
}

if (!RateLimiter::hit('vision_tg:' . $user['id'], 15, 60)) {
    $sendError('RATE_LIMITED', 'Too many Telegram dispatches. Please wait a minute before trying again.', 429);
}

$analysisId = isset($input['analysis_id']) && is_numeric($input['analysis_id']) ? (int)$input['analysis_id'] : 0;
if ($analysisId <= 0) {
    $sendError('INVALID_ID', 'Valid analysis_id is required.', 422);
}

try {
    $isAdmin = (($user['role'] ?? '') === 'admin');
    $result = ChartVisionService::sendToTelegram($analysisId, (int)$user['id'], $isAdmin);
    Response::json([
        'ok'    => true,
        'data'  => $result,
        'error' => null,
    ] + $result);
} catch (\InvalidArgumentException $e) {
    $sendError('NOT_FOUND', $e->getMessage(), 404);
} catch (\RuntimeException $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 422;
    }
    $errCode = $code === 403 ? 'FORBIDDEN' : 'TELEGRAM_DISPATCH_FAILED';
    $sendError($errCode, $e->getMessage(), $code);
} catch (\Throwable $e) {
    Logger::error('Vision Telegram error', ['analysis_id' => $analysisId]);
    $sendError('INTERNAL_ERROR', 'Failed to dispatch vision report to Telegram.', 500);
}
