<?php
declare(strict_types=1);

/**
 * Application bootstrap: autoloader, environment, error handling.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.1.0');
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(APP_ROOT . '/.env');
date_default_timezone_set('UTC');

foreach (['storage/logs', 'storage/cache'] as $dir) {
    if (!is_dir(APP_ROOT . '/' . $dir)) {
        @mkdir(APP_ROOT . '/' . $dir, 0775, true);
    }
}

$debug = App\Core\Env::bool('APP_DEBUG', false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($debug): void {
    App\Core\Logger::error('Uncaught exception', [
        'type' => get_class($e), 'message' => $e->getMessage(),
        'file' => $e->getFile(), 'line' => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $isApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $debug ? $e->getMessage() : 'Internal server error']);
    } else {
        echo '<h1>Server error</h1><p>' . ($debug ? htmlspecialchars($e->getMessage()) : 'An unexpected error occurred. Check storage/logs.') . '</p>';
    }
});
