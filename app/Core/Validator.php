<?php
declare(strict_types=1);

namespace App\Core;

/** Small input validation helpers that throw on invalid input. */
final class Validator
{
    public static function float($v, string $name, ?float $min = null, ?float $max = null): float
    {
        if (!is_numeric($v) || !is_finite((float)$v)) {
            throw new \InvalidArgumentException("$name must be a number");
        }
        $f = (float)$v;
        if ($min !== null && $f < $min) throw new \InvalidArgumentException("$name must be >= $min");
        if ($max !== null && $f > $max) throw new \InvalidArgumentException("$name must be <= $max");
        return $f;
    }

    public static function int($v, string $name, ?int $min = null, ?int $max = null): int
    {
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("$name must be an integer");
        }
        $i = (int)$v;
        if ($min !== null && $i < $min) throw new \InvalidArgumentException("$name must be >= $min");
        if ($max !== null && $i > $max) throw new \InvalidArgumentException("$name must be <= $max");
        return $i;
    }

    public static function enum($v, string $name, array $allowed): string
    {
        if (!in_array($v, $allowed, true)) {
            throw new \InvalidArgumentException("$name must be one of: " . implode(', ', $allowed));
        }
        return $v;
    }

    public static function date($v, string $name): string
    {
        $d = \DateTime::createFromFormat('Y-m-d', (string)$v, new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new \InvalidArgumentException("$name must be YYYY-MM-DD");
        }
        return $v;
    }

    public static function timezone($v): string
    {
        if (!in_array($v, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid timezone');
        }
        return $v;
    }

    public static function str($v, int $min = 0, int $max = 255): string
    {
        $s = trim((string)$v);
        $len = mb_strlen($s);
        if ($len < $min) {
            throw new \InvalidArgumentException("Value must be at least $min characters");
        }
        if ($len > $max) {
            throw new \InvalidArgumentException("Value must be at most $max characters");
        }
        return $s;
    }

    public static function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
