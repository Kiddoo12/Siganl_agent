<?php
declare(strict_types=1);

namespace App\Core;

/**
 * System settings = config/defaults.php merged with admin overrides stored in
 * the `system_settings` table (key = dotted path, value = JSON).
 * Also used as a small key-value store for runtime state (e.g. "state.*").
 */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $cfg = require APP_ROOT . '/config/defaults.php';
            try {
                foreach (Database::fetchAll("SELECT `key`, `value` FROM system_settings WHERE `key` NOT LIKE 'state.%'") as $row) {
                    self::setPath($cfg, $row['key'], json_decode($row['value'], true));
                }
            } catch (\Throwable $e) {
                // DB not ready: run with defaults
            }
            self::$cache = $cfg;
        }
        return self::$cache;
    }

    public static function get(string $path, $default = null)
    {
        $node = self::all();
        foreach (explode('.', $path) as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return $default;
            }
            $node = $node[$p];
        }
        return $node;
    }

    public static function defaults(): array
    {
        return require APP_ROOT . '/config/defaults.php';
    }

    /** Admin override. Only keys present in defaults may be overridden, with matching types. */
    public static function set(string $path, $value, ?int $userId = null): void
    {
        $default = self::defaultFor($path);
        if ($default === '__missing__') {
            throw new \InvalidArgumentException("Unknown setting: $path");
        }
        $value = self::coerce($default, $value, $path);
        Database::query(
            'REPLACE INTO system_settings (`key`, `value`, updated_by, updated_at) VALUES (?, ?, ?, ?)',
            [$path, json_encode($value), $userId, gmdate('Y-m-d H:i:s')]
        );
        self::$cache = null;
    }

    public static function reset(string $path): void
    {
        Database::query('DELETE FROM system_settings WHERE `key` = ?', [$path]);
        self::$cache = null;
    }

    public static function overrides(): array
    {
        try {
            $rows = Database::fetchAll("SELECT `key`, `value`, updated_at FROM system_settings WHERE `key` NOT LIKE 'state.%' ORDER BY `key`");
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = ['value' => json_decode($r['value'], true), 'updated_at' => $r['updated_at']];
        }
        return $out;
    }

    // ---- runtime state store ----
    public static function state(string $key, $default = null)
    {
        try {
            $v = Database::value('SELECT `value` FROM system_settings WHERE `key` = ?', ['state.' . $key]);
            return $v === null ? $default : json_decode($v, true);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function setState(string $key, $value): void
    {
        Database::query(
            'REPLACE INTO system_settings (`key`, `value`, updated_at) VALUES (?, ?, ?)',
            ['state.' . $key, json_encode($value), gmdate('Y-m-d H:i:s')]
        );
    }

    private static function defaultFor(string $path)
    {
        $node = self::defaults();
        foreach (explode('.', $path) as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return '__missing__';
            }
            $node = $node[$p];
        }
        return $node;
    }

    private static function coerce($default, $value, string $path)
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($default)) {
            if (!is_numeric($value)) throw new \InvalidArgumentException("$path must be an integer");
            return (int)$value;
        }
        if (is_float($default)) {
            if (!is_numeric($value)) throw new \InvalidArgumentException("$path must be a number");
            return (float)$value;
        }
        if (is_array($default)) {
            if (is_string($value)) $value = json_decode($value, true);
            if (!is_array($value)) throw new \InvalidArgumentException("$path must be a JSON array/object");
            return $value;
        }
        return mb_substr((string)$value, 0, 500);
    }

    private static function setPath(array &$arr, string $path, $value): void
    {
        $ref = &$arr;
        foreach (explode('.', $path) as $p) {
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                $ref[$p] = $ref[$p] ?? [];
            }
            $ref = &$ref[$p];
        }
        $ref = $value;
    }
}
