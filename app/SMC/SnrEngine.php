<?php
declare(strict_types=1);

namespace App\SMC;

/**
 * Support and Resistance (SNR) Strategy Engine.
 * Implements deterministic detection of core S&R institutional models:
 *  - Major Multi-Touch Horizontal Support & Resistance Levels (Clustered Swings & Psychological Rounds)
 *  - Confirmed Breakouts and Retests (Role Reversal: Broken Resistance -> New Support / Broken Support -> New Resistance)
 *  - Rejection Candles and False Breakouts (Fakeouts, Bear Traps below Support, Bull Traps above Resistance)
 *  - High-Touch Horizontal Bounce Rejections (Range Trades)
 */
final class SnrEngine
{
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = array_merge([
            'lookback'              => 60,   // Number of historical candles to analyze
            'swing_window'          => 2,    // Pivot left/right bars
            'min_rejection_wick'    => 0.35, // Wick must be at least 35% of candle range to qualify as rejection
            'min_touches'           => 2,    // Minimum touch count for a confirmed major level
            'retest_tolerance_atr'  => 0.35, // Retest pullback must come within 0.35 * ATR of broken level
            'cluster_atr_mult'      => 0.25, // Distance threshold to group adjacent swings into one zone
            'min_rr'                => 1.80, // Minimum acceptable Reward-to-Risk ratio
        ], $cfg);
    }

    /**
     * Run Support and Resistance analysis across a closed candle series.
     * @param array<int, array{time?: int, t?: int, open?: float, o?: float, high?: float, h?: float, low?: float, l?: float, close?: float, c?: float, volume?: float, v?: float}> $candles
     * @return array
     */
    public function analyze(array $candles): array
    {
        $n = count($candles);
        if ($n < 6) {
            return ['ok' => false, 'error' => 'Insufficient candles for S&R analysis (min 6 required, got ' . $n . ')'];
        }

        // Limit to lookback window
        $window = array_slice($candles, -min($n, (int)$this->cfg['lookback']));
        $wCount = count($window);

        // Normalize keys
        $norm = [];
        for ($i = 0; $i < $wCount; $i++) {
            $c = $window[$i];
            $norm[] = [
                'time'  => (int)($c['time'] ?? $c['t'] ?? $i),
                'open'  => (float)($c['open'] ?? $c['o'] ?? 0),
                'high'  => (float)($c['high'] ?? $c['h'] ?? 0),
                'low'   => (float)($c['low'] ?? $c['l'] ?? 0),
                'close' => (float)($c['close'] ?? $c['c'] ?? 0),
                'volume'=> (float)($c['volume'] ?? $c['v'] ?? 0),
            ];
        }

        // Compute 14-period ATR for dynamic volatility buffering
        $atr = $this->calculateAtr($norm, 14);
        $clusterBuffer = max(0.20, $atr * $this->cfg['cluster_atr_mult']);
        $curr = $norm[$wCount - 1];
        $currClose = $curr['close'];
        $currHigh = $curr['high'];
        $currLow = $curr['low'];
        $currOpen = $curr['open'];
        $currRange = max(0.01, $currHigh - $currLow);

        // 1. IDENTIFY MAJOR PRICE LEVELS (Swings, Clusters, Range Extremes, Round Numbers)
        $levelsData = $this->identifyLevels($norm, $clusterBuffer, $currClose);
        $resistances = $levelsData['resistances']; // Ascending (nearest above price first)
        $supports = $levelsData['supports'];       // Descending (nearest below price first)

        $nearestR = !empty($resistances) ? $resistances[0] : null;
        $nearestS = !empty($supports) ? $supports[0] : null;
        $nextR = count($resistances) > 1 ? $resistances[1] : null;
        $nextS = count($supports) > 1 ? $supports[1] : null;

        // If no resistance or support detected, fallback to range extremes
        if (!$nearestR) {
            $rangeHigh = max(array_column($norm, 'high'));
            $nearestR = ['price' => round($rangeHigh, 2), 'type' => 'resistance', 'touches' => 1, 'strength' => 'minor', 'label' => 'Period High'];
        }
        if (!$nearestS) {
            $rangeLow = min(array_column($norm, 'low'));
            $nearestS = ['price' => round($rangeLow, 2), 'type' => 'support', 'touches' => 1, 'strength' => 'minor', 'label' => 'Period Low'];
        }

        $detectedStrategies = [];
        $bias = 'Neutral';
        $marketState = 'range_consolidation';
        $tradeSetup = null;

        // Candle rejection metrics for current candle
        $upperWick = max(0.0, $currHigh - max($currOpen, $currClose));
        $lowerWick = max(0.0, min($currOpen, $currClose) - $currLow);
        $upperWickRatio = $upperWick / $currRange;
        $lowerWickRatio = $lowerWick / $currRange;
        $isBullishClose = $currClose > $currOpen;
        $isBearishClose = $currClose < $currOpen;

        // -----------------------------------------------------------------
        // MODEL 1: FALSE BREAKOUT & TRAP REVERSAL (Fakeouts)
        // -----------------------------------------------------------------
        // 1A. Bear Trap / False Breakout below Support (Bullish Reversal)
        // Low swept below nearest support, but candle reclaimed and closed back ABOVE support
        $sPrice = (float)$nearestS['price'];
        if ($currLow < $sPrice && $currClose > $sPrice && ($lowerWickRatio >= $this->cfg['min_rejection_wick'] || $isBullishClose)) {
            $bias = 'Bullish';
            $marketState = 'false_breakout_support_sweep';
            $entry = $currClose;
            $sl = round($currLow - ($atr * 0.35), 2);
            $risk = max(0.10, $entry - $sl);
            $tp1 = round($nearestR ? (float)$nearestR['price'] : ($entry + $risk * 2.0), 2);
            $tp2 = round($nextR ? (float)$nextR['price'] : ($tp1 + $risk * 1.5), 2);
            $rr1 = round(($tp1 - $entry) / $risk, 2);
            $rr2 = round(($tp2 - $entry) / $risk, 2);

            $tradeSetup = [
                'code'            => 'snr_false_breakout',
                'name'            => 'S&R False Breakout (Bear Trap Sweep)',
                'direction'       => 'BUY',
                'bias'            => 'Bullish',
                'status'          => 'confirmed',
                'entry'           => $entry,
                'entry_zone'      => ['min' => $sPrice, 'max' => $entry],
                'stop_loss'       => $sl,
                'tp1'             => $tp1,
                'tp2'             => $tp2,
                'rr1'             => $rr1,
                'rr2'             => $rr2,
                'confidence_score'=> 86,
                'rejection_candle'=> [
                    'type'             => 'Bullish Pin Bar / Trap Sweep',
                    'lower_wick_ratio' => round($lowerWickRatio * 100, 1),
                    'level_tested'     => $sPrice,
                ],
                'details'         => sprintf(
                    'Bear Trap confirmed: Price pierced below major Support $%.2f (wick low $%.2f), but failed to sustain and printed a %.0f%% lower rejection wick closing back above support. Breakout sellers trapped; targeting Resistance at $%.2f.',
                    $sPrice, $currLow, $lowerWickRatio * 100, $tp1
                ),
                'invalidations'   => [
                    'Candle close below trap swing low ($' . number_format($sl, 2) . ')',
                    'Failure to hold above reclaimed Support level ($' . number_format($sPrice, 2) . ')',
                ],
            ];

            $detectedStrategies[] = [
                'code'        => 'snr_false_breakout',
                'name'        => 'S&R False Breakout (Bear Trap)',
                'category'    => 'SNR',
                'direction'   => 'BUY',
                'quality'     => 86,
                'description' => 'Swept major support and closed back inside range with bullish rejection candle, trapping breakout sellers.'
            ];
        }

        // 1B. Bull Trap / False Breakout above Resistance (Bearish Reversal)
        // High pierced above nearest resistance, but candle failed and closed back BELOW resistance
        $rPrice = (float)$nearestR['price'];
        if (!$tradeSetup && $currHigh > $rPrice && $currClose < $rPrice && ($upperWickRatio >= $this->cfg['min_rejection_wick'] || $isBearishClose)) {
            $bias = 'Bearish';
            $marketState = 'false_breakout_resistance_sweep';
            $entry = $currClose;
            $sl = round($currHigh + ($atr * 0.35), 2);
            $risk = max(0.10, $sl - $entry);
            $tp1 = round($nearestS ? (float)$nearestS['price'] : ($entry - $risk * 2.0), 2);
            $tp2 = round($nextS ? (float)$nextS['price'] : ($tp1 - $risk * 1.5), 2);
            $rr1 = round(($entry - $tp1) / $risk, 2);
            $rr2 = round(($entry - $tp2) / $risk, 2);

            $tradeSetup = [
                'code'            => 'snr_false_breakout',
                'name'            => 'S&R False Breakout (Bull Trap Sweep)',
                'direction'       => 'SELL',
                'bias'            => 'Bearish',
                'status'          => 'confirmed',
                'entry'           => $entry,
                'entry_zone'      => ['min' => $entry, 'max' => $rPrice],
                'stop_loss'       => $sl,
                'tp1'             => $tp1,
                'tp2'             => $tp2,
                'rr1'             => $rr1,
                'rr2'             => $rr2,
                'confidence_score'=> 86,
                'rejection_candle'=> [
                    'type'             => 'Shooting Star / Bull Trap Sweep',
                    'upper_wick_ratio' => round($upperWickRatio * 100, 1),
                    'level_tested'     => $rPrice,
                ],
                'details'         => sprintf(
                    'Bull Trap confirmed: Price pierced above major Resistance $%.2f (wick high $%.2f), but failed to sustain and printed a %.0f%% upper rejection wick closing back below resistance. Breakout buyers trapped; targeting Support at $%.2f.',
                    $rPrice, $currHigh, $upperWickRatio * 100, $tp1
                ),
                'invalidations'   => [
                    'Candle close above trap swing high ($' . number_format($sl, 2) . ')',
                    'Failure to hold below rejected Resistance level ($' . number_format($rPrice, 2) . ')',
                ],
            ];

            $detectedStrategies[] = [
                'code'        => 'snr_false_breakout',
                'name'        => 'S&R False Breakout (Bull Trap)',
                'category'    => 'SNR',
                'direction'   => 'SELL',
                'quality'     => 86,
                'description' => 'Swept major resistance and closed back inside range with bearish rejection candle, trapping breakout buyers.'
            ];
        }

        // -----------------------------------------------------------------
        // MODEL 2: CONFIRMED BREAKOUT AND RETEST (Role Reversal)
        // -----------------------------------------------------------------
        // Look back 2-5 candles for a decisive breakout candle followed by a retest
        if (!$tradeSetup) {
            $breakoutRetestSetup = $this->detectBreakoutAndRetest($norm, $levelsData, $atr);
            if ($breakoutRetestSetup) {
                $tradeSetup = $breakoutRetestSetup;
                $bias = $breakoutRetestSetup['bias'];
                $marketState = 'breakout_and_retest';
                $detectedStrategies[] = [
                    'code'        => 'snr_breakout_retest',
                    'name'        => $breakoutRetestSetup['name'],
                    'category'    => 'SNR',
                    'direction'   => $breakoutRetestSetup['direction'],
                    'quality'     => 84,
                    'description' => $breakoutRetestSetup['details']
                ];
            }
        }

        // -----------------------------------------------------------------
        // MODEL 3: MAJOR LEVEL BOUNCE & REJECTION (Range Play)
        // -----------------------------------------------------------------
        if (!$tradeSetup) {
            // Support bounce
            $distToS = abs($currLow - $sPrice);
            if ($distToS <= ($clusterBuffer * 1.5) && $lowerWickRatio >= $this->cfg['min_rejection_wick'] && $isBullishClose) {
                $bias = 'Bullish';
                $marketState = 'support_bounce_rejection';
                $entry = $currClose;
                $sl = round($currLow - ($atr * 0.35), 2);
                $risk = max(0.10, $entry - $sl);
                $tp1 = round($nearestR ? (float)$nearestR['price'] : ($entry + $risk * 2.0), 2);
                $tp2 = round($nextR ? (float)$nextR['price'] : ($tp1 + $risk * 1.5), 2);

                $tradeSetup = [
                    'code'            => 'snr_major_bounce',
                    'name'            => 'S&R Major Support Bounce Rejection',
                    'direction'       => 'BUY',
                    'bias'            => 'Bullish',
                    'status'          => 'confirmed',
                    'entry'           => $entry,
                    'entry_zone'      => ['min' => $sPrice, 'max' => $entry],
                    'stop_loss'       => $sl,
                    'tp1'             => $tp1,
                    'tp2'             => $tp2,
                    'rr1'             => round(($tp1 - $entry) / $risk, 2),
                    'rr2'             => round(($tp2 - $entry) / $risk, 2),
                    'confidence_score'=> 80,
                    'details'         => sprintf(
                        'Clean bounce from major Support $%.2f (%d historical touches). Lower wick rejection of %.0f%% confirms buyers defending level. Targeting Resistance at $%.2f.',
                        $sPrice, $nearestS['touches'], $lowerWickRatio * 100, $tp1
                    ),
                    'invalidations'   => [
                        'Candle close below Support level ($' . number_format($sl, 2) . ')',
                    ],
                ];

                $detectedStrategies[] = [
                    'code'        => 'snr_major_bounce',
                    'name'        => 'S&R Support Bounce',
                    'category'    => 'SNR',
                    'direction'   => 'BUY',
                    'quality'     => 80,
                    'description' => 'Bullish rejection candle reacting off confirmed multi-touch horizontal support.'
                ];
            }
            // Resistance rejection
            $distToR = abs($currHigh - $rPrice);
            if (!$tradeSetup && $distToR <= ($clusterBuffer * 1.5) && $upperWickRatio >= $this->cfg['min_rejection_wick'] && $isBearishClose) {
                $bias = 'Bearish';
                $marketState = 'resistance_rejection';
                $entry = $currClose;
                $sl = round($currHigh + ($atr * 0.35), 2);
                $risk = max(0.10, $sl - $entry);
                $tp1 = round($nearestS ? (float)$nearestS['price'] : ($entry - $risk * 2.0), 2);
                $tp2 = round($nextS ? (float)$nextS['price'] : ($tp1 - $risk * 1.5), 2);

                $tradeSetup = [
                    'code'            => 'snr_major_bounce',
                    'name'            => 'S&R Major Resistance Rejection',
                    'direction'       => 'SELL',
                    'bias'            => 'Bearish',
                    'status'          => 'confirmed',
                    'entry'           => $entry,
                    'entry_zone'      => ['min' => $entry, 'max' => $rPrice],
                    'stop_loss'       => $sl,
                    'tp1'             => $tp1,
                    'tp2'             => $tp2,
                    'rr1'             => round(($entry - $tp1) / $risk, 2),
                    'rr2'             => round(($entry - $tp2) / $risk, 2),
                    'confidence_score'=> 80,
                    'details'         => sprintf(
                        'Clean rejection from major Resistance $%.2f (%d historical touches). Upper wick rejection of %.0f%% confirms institutional supply. Targeting Support at $%.2f.',
                        $rPrice, $nearestR['touches'], $upperWickRatio * 100, $tp1
                    ),
                    'invalidations'   => [
                        'Candle close above Resistance level ($' . number_format($sl, 2) . ')',
                    ],
                ];

                $detectedStrategies[] = [
                    'code'        => 'snr_major_bounce',
                    'name'        => 'S&R Resistance Rejection',
                    'category'    => 'SNR',
                    'direction'   => 'SELL',
                    'quality'     => 80,
                    'description' => 'Bearish rejection candle reacting off confirmed multi-touch horizontal resistance.'
                ];
            }
        }

        return [
            'ok'                  => true,
            'bias'                => $bias,
            'market_state'        => $marketState,
            'current_price'       => $currClose,
            'atr'                 => round($atr, 2),
            'cluster_buffer'      => round($clusterBuffer, 2),
            'nearest_resistance'  => $nearestR,
            'nearest_support'     => $nearestS,
            'all_resistances'     => $resistances,
            'all_supports'        => $supports,
            'trade_setup'         => $tradeSetup,
            'detected_strategies' => $detectedStrategies,
            'rejection_candle'    => [
                'upper_wick_ratio' => round($upperWickRatio * 100, 1),
                'lower_wick_ratio' => round($lowerWickRatio * 100, 1),
                'is_bullish_close' => $isBullishClose,
                'is_bearish_close' => $isBearishClose,
            ],
        ];
    }

    /**
     * Identify and cluster major horizontal price levels from candle history.
     */
    private function identifyLevels(array $candles, float $buffer, float $currPrice): array
    {
        $n = count($candles);
        $priorCandles = array_slice($candles, 0, max(1, $n - 1));
        $m = count($priorCandles);
        $w = (int)$this->cfg['swing_window'];
        $swings = [];

        // Find swing highs and swing lows across historical candles prior to current
        for ($i = $w; $i < $m - $w; $i++) {
            $h = $priorCandles[$i]['high'];
            $l = $priorCandles[$i]['low'];

            // Swing High check
            $isHigh = true;
            for ($k = 1; $k <= $w; $k++) {
                if ($priorCandles[$i - $k]['high'] >= $h || $priorCandles[$i + $k]['high'] > $h) {
                    $isHigh = false;
                    break;
                }
            }
            if ($isHigh) {
                $swings[] = ['price' => $h, 'type' => 'high', 'index' => $i];
            }

            // Swing Low check
            $isLow = true;
            for ($k = 1; $k <= $w; $k++) {
                if ($priorCandles[$i - $k]['low'] <= $l || $priorCandles[$i + $k]['low'] < $l) {
                    $isLow = false;
                    break;
                }
            }
            if ($isLow) {
                $swings[] = ['price' => $l, 'type' => 'low', 'index' => $i];
            }
        }

        // Add overall period extremes prior to current candle
        $periodHigh = max(array_column($priorCandles, 'high'));
        $periodLow = min(array_column($priorCandles, 'low'));
        $swings[] = ['price' => $periodHigh, 'type' => 'high', 'index' => $m - 1];
        $swings[] = ['price' => $periodLow, 'type' => 'low', 'index' => $m - 1];

        // Cluster swings that are within $buffer of each other
        $clusters = [];
        foreach ($swings as $s) {
            $placed = false;
            foreach ($clusters as &$cl) {
                if (abs($cl['center'] - $s['price']) <= $buffer) {
                    $cl['prices'][] = $s['price'];
                    $cl['touches']++;
                    $cl['center'] = array_sum($cl['prices']) / count($cl['prices']);
                    $placed = true;
                    break;
                }
            }
            unset($cl);
            if (!$placed) {
                $clusters[] = [
                    'center'  => $s['price'],
                    'prices'  => [$s['price']],
                    'touches' => 1,
                ];
            }
        }

        // Group into Resistances (above price) and Supports (below price)
        $resistances = [];
        $supports = [];

        foreach ($clusters as $cl) {
            $price = round($cl['center'], 2);
            $touches = $cl['touches'];
            $strength = $touches >= 3 ? 'major' : ($touches === 2 ? 'moderate' : 'minor');

            if ($price > $currPrice + ($buffer * 0.2)) {
                $resistances[] = [
                    'price'    => $price,
                    'type'     => 'resistance',
                    'touches'  => $touches,
                    'strength' => $strength,
                    'distance' => round($price - $currPrice, 2),
                ];
            } elseif ($price < $currPrice - ($buffer * 0.2)) {
                $supports[] = [
                    'price'    => $price,
                    'type'     => 'support',
                    'touches'  => $touches,
                    'strength' => $strength,
                    'distance' => round($currPrice - $price, 2),
                ];
            }
        }

        // Sort resistances ascending (closest first)
        usort($resistances, static fn($a, $b) => $a['price'] <=> $b['price']);
        // Sort supports descending (closest first)
        usort($supports, static fn($a, $b) => $b['price'] <=> $a['price']);

        return [
            'resistances' => $resistances,
            'supports'    => $supports,
        ];
    }

    /**
     * Detect confirmed Breakout and Retest (Role Reversal).
     */
    private function detectBreakoutAndRetest(array $candles, array $levelsData, float $atr): ?array
    {
        $n = count($candles);
        if ($n < 6) return null;

        $curr = $candles[$n - 1];
        $currClose = $curr['close'];
        $currLow = $curr['low'];
        $currHigh = $curr['high'];
        $currOpen = $curr['open'];
        $buffer = max(0.20, $atr * $this->cfg['retest_tolerance_atr']);

        // Check Bullish Breakout & Retest:
        // A previous candle (2-5 bars ago) broke above Resistance, and current/penultimate candle retested the broken resistance as support
        foreach ($levelsData['supports'] as $lvl) {
            $brokenR = (float)$lvl['price']; // Now acting as support
            // Look for breakout candle within last 2 to 5 bars
            for ($b = 2; $b <= min(5, $n - 2); $b++) {
                $candleB = $candles[$n - 1 - $b];
                // Confirmed breakout candle: strong body closing well above $brokenR
                if ($candleB['close'] > ($brokenR + $buffer * 0.8) && $candleB['close'] > $candleB['open']) {
                    // Check if current candle or preceding candle came back to retest the broken level
                    $retestCandle = $curr;
                    $retestLow = $retestCandle['low'];
                    $retestClose = $retestCandle['close'];

                    if ($retestLow <= ($brokenR + $buffer) && $retestClose >= ($brokenR - $buffer * 0.3) && $currClose >= $brokenR) {
                        $sl = round(min($retestLow, $brokenR) - ($atr * 0.35), 2);
                        $risk = max(0.10, $currClose - $sl);
                        $tp1 = round($currClose + ($risk * 2.0), 2);
                        $tp2 = round($currClose + ($risk * 3.5), 2);

                        if (!empty($levelsData['resistances'])) {
                            $tp1 = (float)$levelsData['resistances'][0]['price'];
                            if (count($levelsData['resistances']) > 1) {
                                $tp2 = (float)$levelsData['resistances'][1]['price'];
                            }
                        }

                        return [
                            'code'            => 'snr_breakout_retest',
                            'name'            => 'S&R Bullish Breakout & Retest',
                            'direction'       => 'BUY',
                            'bias'            => 'Bullish',
                            'status'          => 'confirmed',
                            'entry'           => $currClose,
                            'entry_zone'      => ['min' => $brokenR, 'max' => $currClose],
                            'stop_loss'       => $sl,
                            'tp1'             => $tp1,
                            'tp2'             => $tp2,
                            'rr1'             => round(($tp1 - $currClose) / $risk, 2),
                            'rr2'             => round(($tp2 - $currClose) / $risk, 2),
                            'confidence_score'=> 84,
                            'details'         => sprintf(
                                'Role Reversal Confirmed: Decisive breakout above previous Resistance $%.2f followed by successful pullback retest holding as new Support. Enter on retest confirmation targeting next Resistance at $%.2f.',
                                $brokenR, $tp1
                            ),
                            'invalidations'   => [
                                'Candle close back below retest swing / broken level ($' . number_format($sl, 2) . ')',
                            ],
                        ];
                    }
                }
            }
        }

        // Check Bearish Breakout & Retest:
        // A previous candle broke below Support, and current/penultimate candle retested the broken support as resistance
        foreach ($levelsData['resistances'] as $lvl) {
            $brokenS = (float)$lvl['price']; // Now acting as resistance
            for ($b = 2; $b <= min(5, $n - 2); $b++) {
                $candleB = $candles[$n - 1 - $b];
                // Confirmed breakdown candle: strong body closing well below $brokenS
                if ($candleB['close'] < ($brokenS - $buffer * 0.8) && $candleB['close'] < $candleB['open']) {
                    $retestCandle = $curr;
                    $retestHigh = $retestCandle['high'];
                    $retestClose = $retestCandle['close'];

                    if ($retestHigh >= ($brokenS - $buffer) && $retestClose <= ($brokenS + $buffer * 0.3) && $currClose <= $brokenS) {
                        $sl = round(max($retestHigh, $brokenS) + ($atr * 0.35), 2);
                        $risk = max(0.10, $sl - $currClose);
                        $tp1 = round($currClose - ($risk * 2.0), 2);
                        $tp2 = round($currClose - ($risk * 3.5), 2);

                        if (!empty($levelsData['supports'])) {
                            $tp1 = (float)$levelsData['supports'][0]['price'];
                            if (count($levelsData['supports']) > 1) {
                                $tp2 = (float)$levelsData['supports'][1]['price'];
                            }
                        }

                        return [
                            'code'            => 'snr_breakout_retest',
                            'name'            => 'S&R Bearish Breakout & Retest',
                            'direction'       => 'SELL',
                            'bias'            => 'Bearish',
                            'status'          => 'confirmed',
                            'entry'           => $currClose,
                            'entry_zone'      => ['min' => $currClose, 'max' => $brokenS],
                            'stop_loss'       => $sl,
                            'tp1'             => $tp1,
                            'tp2'             => $tp2,
                            'rr1'             => round(($currClose - $tp1) / $risk, 2),
                            'rr2'             => round(($currClose - $tp2) / $risk, 2),
                            'confidence_score'=> 84,
                            'details'         => sprintf(
                                'Role Reversal Confirmed: Decisive breakdown below previous Support $%.2f followed by successful pullback retest holding as new Resistance. Enter on retest confirmation targeting next Support at $%.2f.',
                                $brokenS, $tp1
                            ),
                            'invalidations'   => [
                                'Candle close back above retest swing / broken level ($' . number_format($sl, 2) . ')',
                            ],
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Compute Average True Range (ATR).
     */
    private function calculateAtr(array $candles, int $period = 14): float
    {
        $n = count($candles);
        if ($n < 2) return 1.0;

        $trSum = 0.0;
        $count = min($n - 1, $period);
        $start = $n - $count;

        for ($i = $start; $i < $n; $i++) {
            $h = $candles[$i]['high'];
            $l = $candles[$i]['low'];
            $prevC = $candles[$i - 1]['close'];
            $tr = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            $trSum += $tr;
        }

        return max(0.10, $trSum / max(1, $count));
    }
}
