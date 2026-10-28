<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Response;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

$input = $_POST;
if (empty($input)) {
    $raw = (string)file_get_contents('php://input');
    $jsonDec = json_decode($raw, true);
    if (is_array($jsonDec)) {
        $input = $jsonDec;
    }
}

// CSRF check
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
if (!Csrf::validate($csrfHeader)) {
    Response::json(['ok' => false, 'error' => 'Invalid or expired CSRF token.'], 403);
}

$analysisId = isset($input['analysis_id']) && is_numeric($input['analysis_id']) ? (int)$input['analysis_id'] : 0;
if ($analysisId <= 0) {
    Response::json(['ok' => false, 'error' => 'Valid analysis_id is required.'], 422);
}

try {
    $result = ChartVisionService::sendToTelegram($analysisId, (int)$user['id']);
    Response::json($result);
} catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (\Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
