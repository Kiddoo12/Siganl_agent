<?php
declare(strict_types=1);

namespace App\Core;

/** Session-based authentication with role-based access control. */
final class Auth
{
    public const ROLES = ['admin' => 3, 'analyst' => 2, 'trader' => 1];
    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('GSMCSESSID');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
        // Idle timeout: 8 hours
        if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 28800) {
            self::logout();
            session_start();
        }
        $_SESSION['last_seen'] = time();
    }

    public static function attempt(string $email, string $password): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::hit('login:' . $ip, 10, 900) || !RateLimiter::hit('login:' . strtolower($email), 5, 900)) {
            return ['ok' => false, 'error' => 'Too many login attempts. Try again in 15 minutes.'];
        }
        $user = Database::fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower(trim($email))]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Logger::audit('login_failed', 'user', null, ['email' => $email], $user['id'] ?? null);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        if (!(int)$user['is_active']) {
            return ['ok' => false, 'error' => 'Account disabled. Contact an administrator.'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Database::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        Logger::audit('login', 'user', $user['id'], [], (int)$user['id']);
        RateLimiter::clear('login:' . $ip);
        RateLimiter::clear('login:' . strtolower($email));
        return ['ok' => true];
    }

    public static function register(string $email, string $password, string $name, string $role = 'trader'): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address.'];
        }
        if (strlen($password) < 10) {
            return ['ok' => false, 'error' => 'Password must be at least 10 characters.'];
        }
        if (!isset(self::ROLES[$role])) {
            return ['ok' => false, 'error' => 'Invalid role.'];
        }
        if (Database::value('SELECT id FROM users WHERE email = ?', [$email])) {
            return ['ok' => false, 'error' => 'Email already registered.'];
        }
        $id = Database::transaction(static function () use ($email, $password, $name, $role) {
            $now = gmdate('Y-m-d H:i:s');
            $id = Database::insert('users', [
                'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => mb_substr(trim($name) ?: $email, 0, 100), 'role' => $role,
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            Database::insert('user_settings', [
                'user_id' => $id, 'timezone' => Env::get('APP_TIMEZONE', 'UTC'), 'updated_at' => $now,
            ]);
            return $id;
        });
        Logger::audit('register', 'user', $id, ['role' => $role], $id);
        return ['ok' => true, 'id' => $id];
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
        self::$user = null;
    }

    public static function id(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            self::startSession();
        }
        return (int)($_SESSION['uid'] ?? 0);
    }

    public static function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            self::startSession();
        }
        if (self::$user === null && self::id()) {
            $u = Database::fetch('SELECT id, email, display_name, role, is_active FROM users WHERE id = ?', [self::id()]);
            if (!$u || !(int)$u['is_active']) {
                self::logout();
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    public static function hasRole(string $minRole): bool
    {
        $u = self::user();
        return $u !== null && (self::ROLES[$u['role']] ?? 0) >= (self::ROLES[$minRole] ?? 99);
    }

    /** Page guard: redirect to login. */
    public static function requirePage(string $minRole = 'trader'): array
    {
        self::startSession();
        if (!self::user()) {
            header('Location: login.php');
            exit;
        }
        if (!self::hasRole($minRole)) {
            http_response_code(403);
            exit('Forbidden: insufficient role.');
        }
        return self::user();
    }

    /** API guard: JSON 401/403. */
    public static function requireApi(string $minRole = 'trader'): array
    {
        self::startSession();
        if (!self::user()) {
            Response::error('Authentication required', 401);
        }
        if (!self::hasRole($minRole)) {
            Response::error('Forbidden: insufficient role', 403);
        }
        return self::user();
    }
}
