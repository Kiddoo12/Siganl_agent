<?php
declare(strict_types=1);

namespace App\Dataset;

use App\Market\Timeframe;

/**
 * Resamples granular historical candles (M1 or M5) into standard synchronized timeframes
 * (M5, M15, M30, H1, H4, D1) with strict boundary alignment and zero look-ahead bias.
 */
final class MultiTimeframeResampler
{
    public const SUPPORTED_TARGETS = ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'];

    /**
     * Resample a series of base candles into all requested higher timeframes.
     *
     * @param array<array{t: int, o: float, h: float, l: float, c: float, v?: float}> $baseCandles Chronologically sorted candles
     * @param string $baseTimeframe Source timeframe (typically 'M1' or 'M5')
     * @param array<string> $targetTimeframes List of timeframes to produce
     * @return array<string, array> Keyed by timeframe code
     */
    public function resampleAll(
        array $baseCandles,
        string $baseTimeframe = 'M1',
        array $targetTimeframes = self::SUPPORTED_TARGETS
    ): array {
        $result = [];
        $baseSec = Timeframe::seconds($baseTimeframe);

        foreach ($targetTimeframes as $tf) {
            $targetSec = Timeframe::seconds($tf);
            if ($targetSec < $baseSec) {
                // Cannot up-sample to a more granular timeframe
                continue;
            }

            if ($targetSec === $baseSec) {
                $result[$tf] = $baseCandles;
                continue;
            }

            $result[$tf] = $this->resampleToTimeframe($baseCandles, $tf);
        }

        return $result;
    }

    /**
     * Resample base candles to a specific higher timeframe.
     */
    public function resampleToTimeframe(array $baseCandles, string $targetTf): array
    {
        $bucketSec = Timeframe::seconds($targetTf);
        $resampled = [];
        $buckets = [];

        foreach ($baseCandles as $c) {
            $t = (int)$c['t'];
            // Proper boundary alignment in UTC
            $bucketStart = (int)(floor($t / $bucketSec) * $bucketSec);

            if (!isset($buckets[$bucketStart])) {
                $buckets[$bucketStart] = [
                    't' => $bucketStart,
                    'o' => (float)$c['o'],
                    'h' => (float)$c['h'],
                    'l' => (float)$c['l'],
                    'c' => (float)$c['c'],
                    'v' => (float)($c['v'] ?? 0.0),
                    'count' => 1,
                ];
            } else {
                $b = &$buckets[$bucketStart];
                if ((float)$c['h'] > $b['h']) {
                    $b['h'] = (float)$c['h'];
                }
                if ((float)$c['l'] < $b['l']) {
                    $b['l'] = (float)$c['l'];
                }
                $b['c'] = (float)$c['c'];
                $b['v'] += (float)($c['v'] ?? 0.0);
                $b['count']++;
            }
        }

        foreach ($buckets as $b) {
            $resampled[] = [
                't' => $b['t'],
                'o' => round($b['o'], 3),
                'h' => round($b['h'], 3),
                'l' => round($b['l'], 3),
                'c' => round($b['c'], 3),
                'v' => round($b['v'], 2),
            ];
        }

        usort($resampled, static fn($a, $b) => $a['t'] <=> $b['t']);
        return $resampled;
    }
}
