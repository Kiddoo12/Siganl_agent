<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$userId = (int)$user['id'];
$isAdmin = (($user['role'] ?? '') === 'admin');

$analysisId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$requestedFile = isset($_GET['file']) ? trim((string)$_GET['file']) : '';

$targetFilePath = '';

if ($analysisId > 0) {
    $row = Database::fetch('SELECT user_id, image_path FROM chart_vision_analyses WHERE id = ?', [$analysisId]);
    if (!$row) {
        Response::json(['ok' => false, 'error' => 'Image record not found.'], 404);
    }
    $ownerId = $row['user_id'] !== null ? (int)$row['user_id'] : $userId;
    if (!$isAdmin && $ownerId !== $userId) {
        Response::json(['ok' => false, 'error' => 'Unauthorized access to chart image.'], 403);
    }
    $rawPath = (string)$row['image_path'];
    $targetFilePath = file_exists($rawPath) ? $rawPath : (APP_ROOT . '/storage/uploads/vision/' . basename($rawPath));
} elseif ($requestedFile !== '') {
    $safeName = basename($requestedFile);
    if (!preg_match('/^chart_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.(png|jpg|webp)$/i', $safeName)) {
        Response::json(['ok' => false, 'error' => 'Invalid image filename.'], 400);
    }
    $candidatePath = APP_ROOT . '/storage/uploads/vision/' . $safeName;
    $row = Database::fetch(
        'SELECT user_id FROM chart_vision_analyses WHERE image_path LIKE ? ORDER BY id DESC LIMIT 1',
        ['%' . $safeName]
    );
    if ($row && $row['user_id'] !== null && !$isAdmin && (int)$row['user_id'] !== $userId) {
        Response::json(['ok' => false, 'error' => 'Unauthorized access to chart image.'], 403);
    }
    $targetFilePath = $candidatePath;
} else {
    Response::json(['ok' => false, 'error' => 'Missing image identifier.'], 400);
}

$baseDir = realpath(APP_ROOT . '/storage/uploads/vision');
$realTarget = realpath($targetFilePath);

if (!$baseDir || !$realTarget || strncmp($realTarget, $baseDir, strlen($baseDir)) !== 0 || !is_file($realTarget)) {
    Response::json(['ok' => false, 'error' => 'Chart image file not found on disk.'], 404);
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($realTarget);
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    Response::json(['ok' => false, 'error' => 'Unsupported image format.'], 415);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($realTarget));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($realTarget);
exit;
