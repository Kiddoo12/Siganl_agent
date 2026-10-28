<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Logger;

Auth::startSession();
$uid = Auth::id();
if ($uid) {
    Logger::audit('logout', 'user', $uid, [], $uid);
}
Auth::logout();
header('Location: login.php');
exit;
