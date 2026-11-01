<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

$sendError = static function (string $code, string $message, int $httpStatus = 400): void {
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

$userId = (int)$user['id'];
$isAdmin = (($user['role'] ?? '') === 'admin');

$analysisId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$requestedFile = isset($_GET['file']) ? trim((string)$_GET['file']) : '';

// Prevent SSRF / external URL injection
if ($requestedFile !== '' && (preg_match('/^(https?|ftp|file|php|data|phar):/i', $requestedFile) || str_contains($requestedFile, '..') || str_contains($requestedFile, '/') || str_contains($requestedFile, '\\'))) {
    $sendError('INVALID_IMAGE_PATH', 'External URLs and directory traversal are strictly prohibited.', 400);
}

$targetFilePath = '';

if ($analysisId > 0) {
    $row = Database::fetch('SELECT user_id, image_path, thumbnail_path FROM chart_vision_analyses WHERE id = ?', [$analysisId]);
    if (!$row) {
        $sendError('NOT_FOUND', 'Image record not found.', 404);
    }
    $ownerId = $row['user_id'] !== null ? (int)$row['user_id'] : $userId;
    if (!$isAdmin && $ownerId !== $userId) {
        $sendError('FORBIDDEN', 'Unauthorized access to chart image.', 403);
    }
    $useHtf = !empty($_GET['htf']) && !empty($row['thumbnail_path']);
    $rawPath = (string)($useHtf ? $row['thumbnail_path'] : $row['image_path']);
    $targetFilePath = file_exists($rawPath) ? $rawPath : (APP_ROOT . '/storage/uploads/vision/' . basename($rawPath));
} elseif ($requestedFile !== '') {
    $safeName = basename($requestedFile);
    if (!preg_match('/^chart_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.(png|jpg|webp)$/i', $safeName)) {
        $sendError('INVALID_FILENAME', 'Invalid image filename.', 400);
    }
    $candidatePath = APP_ROOT . '/storage/uploads/vision/' . $safeName;
    $row = Database::fetch(
        'SELECT user_id FROM chart_vision_analyses WHERE image_path LIKE ? OR thumbnail_path LIKE ? ORDER BY id DESC LIMIT 1',
        ['%' . $safeName, '%' . $safeName]
    );
    if ($row && $row['user_id'] !== null && !$isAdmin && (int)$row['user_id'] !== $userId) {
        $sendError('FORBIDDEN', 'Unauthorized access to chart image.', 403);
    }
    $targetFilePath = $candidatePath;
} else {
    $sendError('MISSING_IDENTIFIER', 'Missing image identifier.', 400);
}

$baseDir = realpath(APP_ROOT . '/storage/uploads/vision');
$realTarget = realpath($targetFilePath);

if (!$baseDir || !$realTarget || strncmp($realTarget, $baseDir, strlen($baseDir)) !== 0 || !is_file($realTarget)) {
    $sendError('FILE_NOT_FOUND', 'Chart image file not found on disk.', 404);
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($realTarget);
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    $sendError('UNSUPPORTED_MEDIA', 'Unsupported image format.', 415);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($realTarget));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'');
header('Cache-Control: private, max-age=86400');
readfile($realTarget);
exit;
