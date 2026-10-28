<?php
declare(strict_types=1);

/**
 * CLI utility to create or promote an administrator account.
 * Usage:
 *   php bin/create_admin.php [email] [password] [display_name] [role=admin]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$name = $argv[3] ?? 'Administrator';
$role = $argv[4] ?? 'admin';

if (!$email) {
    fwrite(STDOUT, 'Admin Email [admin@goldsmc.local]: ');
    $in = trim((string)fgets(STDIN));
    $email = $in !== '' ? $in : 'admin@goldsmc.local';
}
if (!$password) {
    fwrite(STDOUT, 'Password (min 10 chars) [GoldSmcAdmin!2026]: ');
    $in = trim((string)fgets(STDIN));
    $password = $in !== '' ? $in : 'GoldSmcAdmin!2026';
}

$email = strtolower(trim($email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: Invalid email '$email'\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Error: Password must be at least 10 characters.\n");
    exit(1);
}
if (!isset(Auth::ROLES[$role])) {
    fwrite(STDERR, "Error: Role must be one of: " . implode(', ', array_keys(Auth::ROLES)) . "\n");
    exit(1);
}

$existing = Database::fetch('SELECT id, role FROM users WHERE email = ?', [$email]);
$now = gmdate('Y-m-d H:i:s');

if ($existing) {
    Database::update('users', [
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name'  => mb_substr(trim($name) ?: $email, 0, 100),
        'role'          => $role,
        'is_active'     => 1,
        'updated_at'    => $now,
    ], 'id = ?', [$existing['id']]);
    Logger::audit('cli_update_user', 'user', $existing['id'], ['email' => $email, 'role' => $role], (int)$existing['id']);
    echo "Updated user #$existing[id] ($email) with role '$role'.\n";
} else {
    $res = Auth::register($email, $password, $name, $role);
    if (!$res['ok']) {
        fwrite(STDERR, 'Error: ' . $res['error'] . "\n");
        exit(1);
    }
    echo "Created user #$res[id] ($email) with role '$role'.\n";
}
