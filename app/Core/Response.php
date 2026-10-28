<?php
declare(strict_types=1);

namespace App\Core;

/** JSON API helpers. */
final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok(array $data = []): void
    {
        self::json(['ok' => true] + $data);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /** Parse JSON body (or form-encoded fallback). */
    public static function input(): array
    {
        static $input = null;
        if ($input === null) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : $_POST;
        }
        return $input;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Standard API prologue: auth, rate limit, CSRF for state-changing methods. */
    public static function apiInit(string $minRole = 'trader', int $perMinute = 120): array
    {
        $user = Auth::requireApi($minRole);
        if (!RateLimiter::hit('api:' . $user['id'], $perMinute, 60)) {
            self::error('Rate limit exceeded. Slow down.', 429);
        }
        if (self::method() !== 'GET') {
            Csrf::verify();
        }
        return $user;
    }
}
