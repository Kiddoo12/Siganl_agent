<?php
declare(strict_types=1);

namespace App\SMC;

/**
 * Candle Range Theory (CRT) Strategy Engine.
 * Implements deterministic detection of core CRT models:
 *  - Reference Candle Range High (RH), Range Low (RL), and 50% Equilibrium (EQ)
 *  - Phase 1 & 2: Range Boundary Liquidity Sweeps with Body Reclaim
 *  - Phase 3: Targeted Delivery through 50% EQ to Opposing Range Boundary
 *  - Range Expansion (External Breakout & Extension)
 *  - Inside Range Compression & Contraction (Stand Aside / Wait)
 */
final class CrtEngine
{
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = array_merge([
            'min_sweep_ratio'      => 0.10, // Wick penetration at least 10% of candle range
            'reclaim_body_buffer'  => 0.05, // Closes at least 5% inside the boundary
            'expansion_body_min'   => 0.55, // Body-to-range ratio for breakout
            'min_rr'               => 1.80, // Minimum acceptable Reward-to-Risk
        ], $cfg);
    }

    /**
     * Run Candle Range Theory analysis across a closed candle series.
     * @param array<int, array{time: int, open: float, high: float, low: float, close: float, volume?: float}> $candles
     * @return array
     */
    public function analyze(array $candles): array
    {
        $n = count($candles);
        if ($n < 5) {
            return ['ok' => false, 'error' => 'Insufficient candles for CRT analysis (min 5 required, got ' . $n . ')'];
        }

        // The reference candle is the penultimate closed candle (or HTF range established)
        $refIdx = $n - 2;
        $currIdx = $n - 1;

        $ref = $candles[$refIdx];
        $curr = $candles[$currIdx];

        $rh = (float)($ref['h'] ?? $ref['high'] ?? 0);
        $rl = (float)($ref['l'] ?? $ref['low'] ?? 0);
        $refOpen = (float)($ref['o'] ?? $ref['open'] ?? 0);
        $refClose = (float)($ref['c'] ?? $ref['close'] ?? 0);
        $refTime = (int)($ref['t'] ?? $ref['time'] ?? 0);

        $rangeSize = max(0.01, $rh - $rl);
        $eq = $rl + ($rangeSize * 0.50);
        $q25 = $rl + ($rangeSize * 0.25); // Discount quartile
        $q75 = $rl + ($rangeSize * 0.75); // Premium quartile

        $currOpen = (float)($curr['o'] ?? $curr['open'] ?? 0);
        $currHigh = (float)($curr['h'] ?? $curr['high'] ?? 0);
        $currLow = (float)($curr['l'] ?? $curr['low'] ?? 0);
        $currClose = (float)($curr['c'] ?? $curr['close'] ?? 0);
        $currTime = (int)($curr['t'] ?? $curr['time'] ?? 0);
        $currRange = max(0.01, $currHigh - $currLow);
        $currBody = abs($currClose - $currOpen);
        $currBodyRatio = $currBody / $currRange;

        $detectedStrategies = [];
        $phase = 'inside_range';
        $bias = 'Neutral';
        $tradeSetup = null;

        // 1. BULLISH CRT: Sell-Side Sweep below RL and Reclaim inside Range
        // Condition: Low pierced below RL, but Close settled back INSIDE the range (> RL)
        if ($currLow < $rl && $currClose > $rl) {
            $sweepDepth = $rl - $currLow;
            $sweepWickRatio = ($currClose - $currLow) / $currRange;

            if ($sweepWickRatio >= $this->cfg['min_sweep_ratio']) {
                $phase = 'bullish_sweep_reclaim';
                $bias = 'Bullish';
                $entry = $currClose;
                $sl = round($currLow - ($rangeSize * 0.05), 2);
                $risk = max(0.10, $entry - $sl);
                $tp1 = round($eq, 2);
                $tp2 = round($rh, 2);
                $rr1 = round(($tp1 - $entry) / $risk, 2);
                $rr2 = round(($tp2 - $entry) / $risk, 2);

                $tradeSetup = [
                    'code'            => 'crt_sweep_reclaim',
                    'name'            => 'CRT Range Sweep & Reclaim',
                    'direction'       => 'BUY',
                    'bias'            => 'Bullish',
                    'status'          => 'confirmed',
                    'entry'           => $entry,
                    'entry_zone'      => ['min' => $rl, 'max' => $entry],
                    'stop_loss'       => $sl,
                    'tp1'             => $tp1,
                    'tp2'             => $tp2,
                    'rr1'             => $rr1,
                    'rr2'             => $rr2,
                    'confidence_score'=> 84,
                    'details'         => sprintf(
                        'Bullish CRT: Swept below Range Low $%.2f (wick to $%.2f), body reclaimed inside. Targeting 50%% EQ ($%.2f) and Range High ($%.2f).',
                        $rl, $currLow, $tp1, $tp2
                    ),
                    'invalidations'   => [
                        'Candle close below sweep low ($' . number_format($sl, 2) . ')',
                        'Failure to reclaim 50% Equilibrium ($' . number_format($eq, 2) . ')'
                    ]
                ];

                $detectedStrategies[] = [
                    'code'        => 'crt_sweep_reclaim',
                    'name'        => 'CRT Bullish Sweep & Reclaim',
                    'category'    => 'CRT',
                    'direction'   => 'BUY',
                    'quality'     => 85,
                    'description' => 'Swept reference candle low and closed back inside range, delivering toward 50% EQ & Range High.'
                ];
            }
        }
        // 2. BEARISH CRT: Buy-Side Sweep above RH and Reclaim inside Range
        // Condition: High pierced above RH, but Close settled back INSIDE the range (< RH)
        elseif ($currHigh > $rh && $currClose < $rh) {
            $sweepDepth = $currHigh - $rh;
            $sweepWickRatio = ($currHigh - $currClose) / $currRange;

            if ($sweepWickRatio >= $this->cfg['min_sweep_ratio']) {
                $phase = 'bearish_sweep_reclaim';
                $bias = 'Bearish';
                $entry = $currClose;
                $sl = round($currHigh + ($rangeSize * 0.05), 2);
                $risk = max(0.10, $sl - $entry);
                $tp1 = round($eq, 2);
                $tp2 = round($rl, 2);
                $rr1 = round(($entry - $tp1) / $risk, 2);
                $rr2 = round(($entry - $tp2) / $risk, 2);

                $tradeSetup = [
                    'code'            => 'crt_sweep_reclaim',
                    'name'            => 'CRT Range Sweep & Reclaim',
                    'direction'       => 'SELL',
                    'bias'            => 'Bearish',
                    'status'          => 'confirmed',
                    'entry'           => $entry,
                    'entry_zone'      => ['min' => $entry, 'max' => $rh],
                    'stop_loss'       => $sl,
                    'tp1'             => $tp1,
                    'tp2'             => $tp2,
                    'rr1'             => $rr1,
                    'rr2'             => $rr2,
                    'confidence_score'=> 84,
                    'details'         => sprintf(
                        'Bearish CRT: Swept above Range High $%.2f (wick to $%.2f), body reclaimed inside. Targeting 50%% EQ ($%.2f) and Range Low ($%.2f).',
                        $rh, $currHigh, $tp1, $tp2
                    ),
                    'invalidations'   => [
                        'Candle close above sweep high ($' . number_format($sl, 2) . ')',
                        'Failure to reclaim 50% Equilibrium ($' . number_format($eq, 2) . ')'
                    ]
                ];

                $detectedStrategies[] = [
                    'code'        => 'crt_sweep_reclaim',
                    'name'        => 'CRT Bearish Sweep & Reclaim',
                    'category'    => 'CRT',
                    'direction'   => 'SELL',
                    'quality'     => 85,
                    'description' => 'Swept reference candle high and closed back inside range, delivering toward 50% EQ & Range Low.'
                ];
            }
        }
        // 3. BULLISH RANGE EXPANSION: Candle Closed above RH with strong body
        elseif ($currClose > $rh && $currBodyRatio >= $this->cfg['expansion_body_min']) {
            $phase = 'range_expansion_bull';
            $bias = 'Bullish';
            $entry = $currClose;
            $sl = round($eq, 2); // Stop loss at 50% EQ of reference range
            $risk = max(0.10, $entry - $sl);
            $tp1 = round($rh + ($rangeSize * 0.272), 2); // -27.2% extension
            $tp2 = round($rh + ($rangeSize * 0.618), 2); // -61.8% extension
            $rr1 = round(($tp1 - $entry) / $risk, 2);
            $rr2 = round(($tp2 - $entry) / $risk, 2);

            $tradeSetup = [
                'code'            => 'crt_range_expansion',
                'name'            => 'CRT Range Expansion (Bullish)',
                'direction'       => 'BUY',
                'bias'            => 'Bullish',
                'status'          => 'confirmed',
                'entry'           => $entry,
                'entry_zone'      => ['min' => $rh, 'max' => $entry],
                'stop_loss'       => $sl,
                'tp1'             => $tp1,
                'tp2'             => $tp2,
                'rr1'             => $rr1,
                'rr2'             => $rr2,
                'confidence_score'=> 80,
                'details'         => sprintf(
                    'CRT Bullish Expansion: Closed above Range High $%.2f with %.0f%% body ratio. Targeting extensions at $%.2f and $%.2f.',
                    $rh, $currBodyRatio * 100, $tp1, $tp2
                ),
                'invalidations'   => [
                    'Candle close back inside reference range below $%.2f' => $rh
                ]
            ];

            $detectedStrategies[] = [
                'code'        => 'crt_range_expansion',
                'name'        => 'CRT Bullish Expansion',
                'category'    => 'CRT',
                'direction'   => 'BUY',
                'quality'     => 80,
                'description' => 'Breakout candle closed above Range High with expansion body, targeting external extensions.'
            ];
        }
        // 4. BEARISH RANGE EXPANSION: Candle Closed below RL with strong body
        elseif ($currClose < $rl && $currBodyRatio >= $this->cfg['expansion_body_min']) {
            $phase = 'range_expansion_bear';
            $bias = 'Bearish';
            $entry = $currClose;
            $sl = round($eq, 2);
            $risk = max(0.10, $sl - $entry);
            $tp1 = round($rl - ($rangeSize * 0.272), 2);
            $tp2 = round($rl - ($rangeSize * 0.618), 2);
            $rr1 = round(($entry - $tp1) / $risk, 2);
            $rr2 = round(($entry - $tp2) / $risk, 2);

            $tradeSetup = [
                'code'            => 'crt_range_expansion',
                'name'            => 'CRT Range Expansion (Bearish)',
                'direction'       => 'SELL',
                'bias'            => 'Bearish',
                'status'          => 'confirmed',
                'entry'           => $entry,
                'entry_zone'      => ['min' => $entry, 'max' => $rl],
                'stop_loss'       => $sl,
                'tp1'             => $tp1,
                'tp2'             => $tp2,
                'rr1'             => $rr1,
                'rr2'             => $rr2,
                'confidence_score'=> 80,
                'details'         => sprintf(
                    'CRT Bearish Expansion: Closed below Range Low $%.2f with %.0f%% body ratio. Targeting extensions at $%.2f and $%.2f.',
                    $rl, $currBodyRatio * 100, $tp1, $tp2
                ),
                'invalidations'   => [
                    'Candle close back inside reference range above $%.2f' => $rl
                ]
            ];

            $detectedStrategies[] = [
                'code'        => 'crt_range_expansion',
                'name'        => 'CRT Bearish Expansion',
                'category'    => 'CRT',
                'direction'   => 'SELL',
                'quality'     => 80,
                'description' => 'Breakout candle closed below Range Low with expansion body, targeting external extensions.'
            ];
        }
        // 5. INSIDE RANGE / CONTRACTION: Inside reference boundaries
        else {
            $phase = 'inside_range';
            $bias = $currClose > $eq ? 'Mild Bullish (Premium)' : 'Mild Bearish (Discount)';

            $detectedStrategies[] = [
                'code'        => 'crt_inside_contraction',
                'name'        => 'CRT Inside Range Contraction',
                'category'    => 'CRT',
                'direction'   => 'WAIT',
                'quality'     => 50,
                'description' => sprintf(
                    'Price ($%.2f) oscillating inside Reference Range ($%.2f - $%.2f, EQ: $%.2f). Await boundary sweep.',
                    $currClose, $rl, $rh, $eq
                )
            ];
        }

        return [
            'ok'                  => true,
            'reference_candle'    => [
                'time'        => $refTime,
                'range_high'  => $rh,
                'range_low'   => $rl,
                'range_eq'    => $eq,
                'range_size'  => round($rangeSize, 2),
                'quartile_25' => round($q25, 2),
                'quartile_75' => round($q75, 2),
                'location'    => $currClose >= $eq ? 'Premium' : 'Discount',
            ],
            'current_candle'      => [
                'close'      => $currClose,
                'high'       => $currHigh,
                'low'        => $currLow,
                'body_ratio' => round($currBodyRatio, 2),
            ],
            'phase'               => $phase,
            'bias'                => $bias,
            'trade_setup'         => $tradeSetup,
            'detected_strategies' => $detectedStrategies,
        ];
    }
}
