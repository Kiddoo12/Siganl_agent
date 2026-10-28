<?php
declare(strict_types=1);

namespace App\Market;

/** Validates OHLC candles before they are stored or analysed. */
final class CandleValidator
{
    /**
     * @return array{candles: array, rejected: int, issues: string[]}
     */
    public static function validate(array $candles, string $tf): array
    {
        $out = [];
        $rejected = 0;
        $issues = [];
        $seen = [];
        $now = time();
        foreach ($candles as $c) {
            $ok = isset($c['t'], $c['o'], $c['h'], $c['l'], $c['c'])
                && is_int($c['t']) && $c['t'] > 946684800 && $c['t'] <= $now + 60;
            if ($ok) {
                foreach (['o', 'h', 'l', 'c'] as $k) {
                    if (!is_finite((float)$c[$k]) || (float)$c[$k] <= 0) {
                        $ok = false;
                    }
                }
            }
            if ($ok && ($c['h'] < max($c['o'], $c['c']) - 1e-9 || $c['l'] > min($c['o'], $c['c']) + 1e-9 || $c['h'] < $c['l'])) {
                $ok = false;
                $issues[] = 'Inconsistent OHLC at ' . gmdate('Y-m-d H:i', $c['t']);
            }
            if ($ok && isset($seen[$c['t']])) {
                $ok = false;
            }
            if (!$ok) {
                $rejected++;
                continue;
            }
            $seen[$c['t']] = true;
            $out[] = $c;
        }
        usort($out, static fn($a, $b) => $a['t'] <=> $b['t']);

        // Outlier check: a candle range > 25x median range is flagged (not silently fixed)
        if (count($out) > 20) {
            $ranges = array_map(static fn($c) => $c['h'] - $c['l'], $out);
            sort($ranges);
            $median = $ranges[intdiv(count($ranges), 2)] ?: 0.0;
            if ($median > 0) {
                foreach ($out as $c) {
                    if ($c['h'] - $c['l'] > 25 * $median) {
                        $issues[] = 'Abnormal range candle at ' . gmdate('Y-m-d H:i', $c['t']) . ' (verify with provider)';
                    }
                }
            }
        }
        return ['candles' => $out, 'rejected' => $rejected, 'issues' => array_slice(array_unique($issues), 0, 10)];
    }
}
