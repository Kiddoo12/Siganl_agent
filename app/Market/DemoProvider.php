<?php
declare(strict_types=1);

namespace App\Market;

use App\Core\Settings;

/**
 * DEMO adapter - generates deterministic SYNTHETIC candles for XAUUSD and BTCUSD.
 * This is NOT market data. It exists so the UI, SMC engine and backtester can be
 * exercised without a provider key. Everything produced from it is labelled
 * "DEMO" throughout the app and excluded from live performance statistics.
 *
 * Price is a stateless function P(t) (sinusoids + multi-octave value noise), so
 * all timeframes are mutually consistent and the series is stable between requests.
 */
final class DemoProvider implements MarketDataProvider
{
    private string $symbol;
    private float $base;
    private float $scale;
    private bool $is24x7;
    private int $seedOffset;
    private int $decimals;

    public function __construct(string $symbol = 'XAUUSD')
    {
        $this->setSymbol($symbol);
    }

    public function setSymbol(string $symbol): void
    {
        $symbol = strtoupper(trim($symbol));
        $meta = Settings::get("market.symbols.$symbol") ?? [
            'demo_base'  => $symbol === 'BTCUSD' ? 82300.0 : 4107.0,
            'demo_scale' => $symbol === 'BTCUSD' ? 28.0 : 1.0,
            'is_24_7'    => $symbol === 'BTCUSD',
        ];
        $this->symbol = $symbol;
        $this->base = (float)($meta['demo_base'] ?? 4107.0);
        $this->scale = (float)($meta['demo_scale'] ?? 1.0);
        $this->is24x7 = !empty($meta['is_24_7']);
        $offsets = [
            'XAUUSD' => 0,
            'BTCUSD' => 300,
            'EURUSD' => 600,
            'GBPUSD' => 900,
            'USDJPY' => 1200,
            'US30'   => 1500,
            'NAS100' => 1800,
        ];
        $this->seedOffset = $offsets[$symbol] ?? 0;
        $this->decimals = in_array($symbol, ['EURUSD', 'GBPUSD'], true) ? 4 : ($symbol === 'USDJPY' ? 3 : 2);
    }

    public function code(): string { return 'demo'; }
    public function mode(): string { return 'demo'; }
    public function label(): string { return 'DEMO synthetic data (' . $this->symbol . ' - not real prices)'; }
    public function isConfigured(): bool { return true; }

    public function fetchCandles(string $tf, int $limit, ?int $startTs = null, ?int $endTs = null): array
    {
        $sec = Timeframe::seconds($tf);
        $end = $endTs ?? time();
        $lastOpen = $this->alignOpen($tf, $end);
        $opens = [];
        $t = $lastOpen;
        while (count($opens) < $limit) {
            if ($startTs !== null && $t < $startTs) break;
            if ($this->is24x7 || !$this->isWeekendClosed($t) || $sec >= 86400) {
                $opens[] = $t;
            }
            $t = $tf === 'MN1' ? (int)gmmktime(0, 0, 0, (int)gmdate('n', $t) - 1, 1, (int)gmdate('Y', $t)) : $t - $sec;
            if ($t < 946684800) break;
        }
        $opens = array_reverse($opens);
        $out = [];
        foreach ($opens as $o) {
            $close = min(Timeframe::closeTime($tf, $o), $end);
            $steps = 24;
            $dt = max(1, ($close - $o) / $steps);
            $open = $this->price($o);
            $hi = $lo = $open;
            for ($k = 1; $k <= $steps; $k++) {
                $p = $this->price((int)($o + $k * $dt));
                $hi = max($hi, $p);
                $lo = min($lo, $p);
            }
            $c = $this->price((int)$close);
            $hi = max($hi, $c); $lo = min($lo, $c);
            $out[] = [
                't' => $o, 'o' => round($open, $this->decimals), 'h' => round($hi, $this->decimals), 'l' => round($lo, $this->decimals), 'c' => round($c, $this->decimals),
                'v' => round(500 + 400 * abs($this->noise($o / 3600.0, 77 + $this->seedOffset)) * ($sec / 900), 0),
            ];
        }
        return $out;
    }

    public function fetchQuote(): array
    {
        $now = time();
        $price = round($this->price($now), $this->decimals);
        $dayStart = (int)gmmktime(0, 0, 0, (int)gmdate('n', $now), (int)gmdate('j', $now), (int)gmdate('Y', $now));
        $prev = round($this->price($dayStart), $this->decimals);
        return [
            'price' => $price, 'prev_close' => $prev, 'change' => round($price - $prev, $this->decimals),
            'change_pct' => round(($price - $prev) / max(0.00001, $prev) * 100, 3), 'ts' => $now,
            'bid' => null, 'ask' => null, 'market_open' => $this->is24x7 ? true : !$this->isWeekendClosed($now),
        ];
    }

    /** Deterministic synthetic price function scaled for the active symbol. */
    public function price(int $t): float
    {
        $d = $t / 86400.0;
        $phase = $this->seedOffset * 0.017;
        $wave = 140 * sin(2 * M_PI * $d / 210 + $phase)
            + 55 * sin(2 * M_PI * $d / 41 + 1.3 + $phase)
            + 22 * sin(2 * M_PI * $d / 9.5 + 2.1 + $phase);
        $octaves = [[86400 * 3, 18], [86400, 11], [14400, 6], [3600, 3.2], [900, 1.6], [300, 0.8], [60, 0.35]];
        foreach ($octaves as $i => [$period, $amp]) {
            $wave += $amp * $this->noise($t / $period, $i + 1 + $this->seedOffset);
        }
        return $this->base + $this->scale * $wave;
    }

    private function noise(float $x, int $seed): float
    {
        $i = (int)floor($x);
        $f = $x - $i;
        $a = $this->hash($i, $seed);
        $b = $this->hash($i + 1, $seed);
        $s = $f * $f * (3 - 2 * $f);
        return $a + ($b - $a) * $s;
    }

    private function hash(int $i, int $seed): float
    {
        $h = crc32($seed . ':' . $i);
        return ($h / 4294967295.0) * 2 - 1;
    }

    private function alignOpen(string $tf, int $ts): int
    {
        if ($tf === 'MN1') return (int)gmmktime(0, 0, 0, (int)gmdate('n', $ts), 1, (int)gmdate('Y', $ts));
        if ($tf === 'W1') {
            $dayStart = $ts - ($ts % 86400);
            $dow = (int)gmdate('N', $dayStart); // 1 = Monday
            return $dayStart - ($dow - 1) * 86400;
        }
        $sec = Timeframe::seconds($tf);
        return $ts - ($ts % $sec);
    }

    /** Gold-like weekly closure: Fri 21:00 UTC -> Sun 22:00 UTC (approximation for demo only). */
    private function isWeekendClosed(int $t): bool
    {
        $dow = (int)gmdate('w', $t);
        $h = (int)gmdate('G', $t);
        return ($dow === 6) || ($dow === 5 && $h >= 21) || ($dow === 0 && $h < 22);
    }
}
