<?php
declare(strict_types=1);

namespace App\Core;

/** Synchronizer-token CSRF protection. */
final class Csrf
{
    public static function token(): string
    {
        Auth::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function valid(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function validate(?string $token): bool
    {
        return self::valid($token);
    }

    /** Verify token from header (AJAX) or form field; abort on failure. */
    public static function verify(bool $json = true): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
        if (!self::valid($token)) {
            Logger::warning('CSRF validation failed', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            if ($json) {
                Response::error('Invalid or missing CSRF token', 419);
            }
            http_response_code(419);
            exit('Session expired. Please reload the page.');
        }
    }
}
