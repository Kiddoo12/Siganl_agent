<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Validator;

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

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? ($input['csrf_token'] ?? null));
if (!Csrf::validate(is_string($csrfHeader) ? $csrfHeader : null)) {
    Response::json(['ok' => false, 'error' => 'Invalid or expired CSRF token.'], 403);
}

if (!RateLimiter::hit('vision_analyze:' . $user['id'], 25, 60)) {
    Response::json(['ok' => false, 'error' => 'Rate limit exceeded. Please wait a minute before analyzing more charts.'], 429);
}

$symbol = Validator::str($input['symbol'] ?? 'XAUUSD', 1, 16);
$timeframe = Validator::str($input['timeframe'] ?? 'M15', 1, 8);
$htfTimeframe = Validator::str($input['htf_timeframe'] ?? '', 0, 8);
$strategyMode = Validator::str($input['strategy_mode'] ?? 'ALL', 1, 16);
$manualPrice = isset($input['manual_price']) && $input['manual_price'] !== '' && is_numeric($input['manual_price'])
    ? (float)$input['manual_price']
    : null;
$broker = Validator::str($input['broker'] ?? '', 0, 64);
$chartTz = Validator::str($input['chart_tz'] ?? '', 0, 64);
$base64Payload = $input['image_base64'] ?? ($input['image_data'] ?? null);
$htfBase64Payload = $input['htf_image_base64'] ?? null;
$enforceMinDim = !empty($input['enforce_min_dimensions']);
$forceReanalyze = !empty($input['force_reanalyze']);

try {
    if ($manualPrice !== null && ($manualPrice <= 0 || $manualPrice > 1000000)) {
        throw new \InvalidArgumentException('Manual reference price must be a positive number.');
    }

    if (!empty($_FILES['chart_image']) && ($_FILES['chart_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = ChartVisionService::storeUpload($_FILES['chart_image'], null, $enforceMinDim);
    } elseif ($base64Payload !== null && trim((string)$base64Payload) !== '') {
        $upload = ChartVisionService::storeUpload([], (string)$base64Payload, $enforceMinDim);
    } else {
        Response::json(['ok' => false, 'error' => 'Please select a chart screenshot file or paste an image from your clipboard.'], 422);
    }

    $htfImagePath = null;
    if (!empty($_FILES['htf_image']) && ($_FILES['htf_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $htfUpload = ChartVisionService::storeUpload($_FILES['htf_image'], null, $enforceMinDim);
        $htfImagePath = $htfUpload['path'];
    } elseif ($htfBase64Payload !== null && trim((string)$htfBase64Payload) !== '') {
        $htfUpload = ChartVisionService::storeUpload([], (string)$htfBase64Payload, $enforceMinDim);
        $htfImagePath = $htfUpload['path'];
    }

    $analysis = ChartVisionService::analyzeChart(
        $upload['path'],
        $symbol,
        $timeframe,
        $manualPrice,
        $broker ?: null,
        $chartTz ?: null,
        (int)$user['id'],
        $strategyMode,
        $htfTimeframe ?: null,
        $htfImagePath,
        $forceReanalyze
    );

    $analysis['image_width'] = $upload['width'];
    $analysis['image_height'] = $upload['height'];

    Response::json(['ok' => true] + $analysis);
} catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    Logger::error('Vision analyze internal error', ['type' => get_class($e)]);
    Response::json(['ok' => false, 'error' => 'Server error analyzing chart image. Please try again with a valid chart screenshot.'], 500);
}
