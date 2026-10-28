<?php
declare(strict_types=1);

namespace App\Market;

/** Timeframe codes and helpers. */
final class Timeframe
{
    public const ALL = ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1', 'W1', 'MN1'];
    public const SECONDS = [
        'M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600,
        'H4' => 14400, 'D1' => 86400, 'W1' => 604800, 'MN1' => 2592000,
    ];
    public const TWELVEDATA = [
        'M1' => '1min', 'M5' => '5min', 'M15' => '15min', 'M30' => '30min', 'H1' => '1h',
        'H4' => '4h', 'D1' => '1day', 'W1' => '1week', 'MN1' => '1month',
    ];

    public static function valid(string $tf): bool
    {
        return in_array($tf, self::ALL, true);
    }

    public static function seconds(string $tf): int
    {
        return self::SECONDS[$tf];
    }

    /** UTC close timestamp of a candle opened at $openTs. */
    public static function closeTime(string $tf, int $openTs): int
    {
        if ($tf === 'MN1') {
            return (int)gmmktime(0, 0, 0, (int)gmdate('n', $openTs) + 1, 1, (int)gmdate('Y', $openTs));
        }
        return $openTs + self::SECONDS[$tf];
    }

    /** Keep only candles fully closed at $now (prevents using an in-progress candle). */
    public static function closedOnly(string $tf, array $candles, int $now): array
    {
        while ($candles && self::closeTime($tf, end($candles)['t']) > $now) {
            array_pop($candles);
        }
        return $candles;
    }
}
