<?php
declare(strict_types=1);

namespace App\Core;

/** Structured JSON-lines logger + audit trail. */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $line = json_encode([
            'ts' => gmdate('c'), 'level' => $level, 'msg' => $message,
            'ctx' => self::scrub($context),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents(APP_ROOT . '/storage/logs/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }

    /** Persist a security-relevant action to audit_logs (never throws). */
    public static function audit(string $action, ?string $entity = null, $entityId = null, array $details = [], ?int $userId = null): void
    {
        try {
            Database::insert('audit_logs', [
                'user_id'    => $userId ?? (Auth::id() ?: null),
                'action'     => substr($action, 0, 100),
                'entity'     => $entity,
                'entity_id'  => $entityId === null ? null : (string)$entityId,
                'details'    => json_encode(self::scrub($details)),
                'ip'         => substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            self::error('Audit write failed', ['err' => $e->getMessage(), 'action' => $action]);
        }
    }

    /** Remove secrets from log context. */
    private static function scrub(array $ctx): array
    {
        foreach ($ctx as $k => $v) {
            if (is_string($k) && preg_match('/pass|secret|token|apikey|api_key|authorization/i', $k)) {
                $ctx[$k] = '***';
            } elseif (is_array($v)) {
                $ctx[$k] = self::scrub($v);
            }
        }
        return $ctx;
    }

    public static function tail(int $lines = 200): array
    {
        $file = APP_ROOT . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
        if (!is_file($file)) {
            return [];
        }
        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_slice($all, -$lines) as $l) {
            $d = json_decode($l, true);
            if ($d) {
                $out[] = $d;
            }
        }
        return array_reverse($out);
    }
}
