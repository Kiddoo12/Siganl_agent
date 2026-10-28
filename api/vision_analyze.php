<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
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

// CSRF check
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
if (!Csrf::validate($csrfHeader)) {
    Response::json(['ok' => false, 'error' => 'Invalid or expired CSRF token.'], 403);
}

$symbol = Validator::str($input['symbol'] ?? 'XAUUSD', 1, 16);
$timeframe = Validator::str($input['timeframe'] ?? 'M15', 1, 8);
$manualPrice = isset($input['manual_price']) && is_numeric($input['manual_price']) ? (float)$input['manual_price'] : null;
$broker = Validator::str($input['broker'] ?? '', 0, 64);
$chartTz = Validator::str($input['chart_tz'] ?? '', 0, 64);
$base64Payload = $input['image_base64'] ?? ($input['image_data'] ?? null);

try {
    if (!empty($_FILES['chart_image']['tmp_name'])) {
        $upload = ChartVisionService::storeUpload($_FILES['chart_image']);
    } elseif ($base64Payload !== null && trim($base64Payload) !== '') {
        $upload = ChartVisionService::storeUpload([], $base64Payload);
    } else {
        Response::json(['ok' => false, 'error' => 'Please select a chart screenshot file or paste an image from your clipboard.'], 422);
    }

    $analysis = ChartVisionService::analyzeChart(
        $upload['path'],
        $symbol,
        $timeframe,
        $manualPrice,
        $broker ?: null,
        $chartTz ?: null,
        (int)$user['id']
    );

    $analysis['image_url'] = $upload['rel_url'];
    $analysis['image_width'] = $upload['width'];
    $analysis['image_height'] = $upload['height'];

    Response::json(['ok' => true] + $analysis);
} catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    Response::json(['ok' => false, 'error' => 'Server error analyzing chart image: ' . $e->getMessage()], 500);
}
