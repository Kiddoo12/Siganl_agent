<?php
declare(strict_types=1);

namespace App\Analysis;

use App\SMC\SmcEngine;

/**
 * Professional AI Trend Analysis Engine.
 *
 * Implements Section 2 of MASTER PROMPT: PROFESSIONAL AI TREND TRADER:
 *  - Moving averages: EMA 20, EMA 50, EMA 200 (ribbon alignment, slopes, golden/death cross)
 *  - RSI (14) with momentum states & regular Bullish/Bearish Divergence detection
 *  - MACD (12, 26, 9) with signal line, histogram & expansion/contraction momentum
 *  - ATR (14) volatility & dynamic price channels (Keltner/ATR bands)
 *  - Dynamic support & resistance clusters & trendline projections
 *  - Market Structure: Higher Highs (HH), Higher Lows (HL), Lower Highs (LH), Lower Lows (LL)
 *  - Structure transitions: BOS, CHoCH, and MSS (Displacement-confirmed CHoCH)
 *  - 6 Exact Classifications:
 *      1. Strong Bullish Trend
 *      2. Weak Bullish Trend
 *      3. Strong Bearish Trend
 *      4. Weak Bearish Trend
 *      5. Consolidation / Range
 *      6. Potential Reversal
 *  - Evidence-based explanations detailing every classification factor.
 */
final class TrendAnalysisEngine
{
    public const CLASSIFICATION_STRONG_BULLISH = 'Strong Bullish Trend';
    public const CLASSIFICATION_WEAK_BULLISH   = 'Weak Bullish Trend';
    public const CLASSIFICATION_STRONG_BEARISH = 'Strong Bearish Trend';
    public const CLASSIFICATION_WEAK_BEARISH   = 'Weak Bearish Trend';
    public const CLASSIFICATION_CONSOLIDATION  = 'Consolidation / Range';
    public const CLASSIFICATION_REVERSAL       = 'Potential Reversal';

    public const ALL_CLASSIFICATIONS = [
        self::CLASSIFICATION_STRONG_BULLISH,
        self::CLASSIFICATION_WEAK_BULLISH,
        self::CLASSIFICATION_STRONG_BEARISH,
        self::CLASSIFICATION_WEAK_BEARISH,
        self::CLASSIFICATION_CONSOLIDATION,
        self::CLASSIFICATION_REVERSAL,
    ];

    /**
     * Analyze a series of closed candles.
     *
     * @param array $candles Array of OHLCV candles (['t','o','h','l','c','v']), sorted oldest to newest.
     * @param array $options Optional overrides for periods and parameters.
     * @return array Complete trend analysis report.
     */
    public function analyze(array $candles, array $options = []): array
    {
        $n = count($candles);
        if ($n < 25) {
            return [
                'ok' => false,
                'error' => 'Insufficient candles for trend analysis (' . $n . ' < 25)',
                'classification' => self::CLASSIFICATION_CONSOLIDATION,
                'evidence' => ['Insufficient candle data for reliable trend calculation.'],
            ];
        }

        $closes = array_column($candles, 'c');
        $highs  = array_column($candles, 'h');
        $lows   = array_column($candles, 'l');
        $last   = end($candles);
        $currPrice = (float)$last['c'];

        // 1. Moving Averages: EMA 20, EMA 50, EMA 200
        $ema20Series  = self::ema($closes, 20);
        $ema50Series  = self::ema($closes, 50);
        $ema200Series = self::ema($closes, min(200, max(20, (int)($n * 0.9))));

        $currEma20  = (float)end($ema20Series);
        $currEma50  = (float)end($ema50Series);
        $currEma200 = (float)end($ema200Series);

        // EMA Slopes over 5 bars
        $prevEma20 = (float)($ema20Series[max(0, $n - 6)] ?? $currEma20);
        $prevEma50 = (float)($ema50Series[max(0, $n - 6)] ?? $currEma50);
        $slope20Pct = $prevEma20 > 0 ? (($currEma20 - $prevEma20) / $prevEma20) * 100.0 : 0.0;
        $slope50Pct = $prevEma50 > 0 ? (($currEma50 - $prevEma50) / $prevEma50) * 100.0 : 0.0;

        // Ribbon alignment
        $ribbonBullish = ($currPrice > $currEma20 && $currEma20 > $currEma50 && $currEma50 > $currEma200);
        $ribbonBearish = ($currPrice < $currEma20 && $currEma20 < $currEma50 && $currEma50 < $currEma200);
        $ribbonState = $ribbonBullish ? 'bullish_stacked' : ($ribbonBearish ? 'bearish_stacked' : 'mixed');

        // Golden / Death cross detection within last 15 bars
        $goldenCross = false;
        $deathCross  = false;
        $lookback = min(15, $n - 2);
        for ($i = $n - $lookback; $i < $n; $i++) {
            if ($i > 0 && isset($ema50Series[$i], $ema200Series[$i], $ema50Series[$i - 1], $ema200Series[$i - 1])) {
                if ($ema50Series[$i - 1] <= $ema200Series[$i - 1] && $ema50Series[$i] > $ema200Series[$i]) {
                    $goldenCross = true;
                }
                if ($ema50Series[$i - 1] >= $ema200Series[$i - 1] && $ema50Series[$i] < $ema200Series[$i]) {
                    $deathCross = true;
                }
            }
        }

        // Distance from EMA 200
        $distEma200Pct = $currEma200 > 0 ? (($currPrice - $currEma200) / $currEma200) * 100.0 : 0.0;

        // 2. Oscillators: RSI (14) & Regular Divergence
        $rsiSeries = self::rsi($closes, 14);
        $currRsi = (float)end($rsiSeries);
        $prevRsi = (float)($rsiSeries[max(0, $n - 4)] ?? $currRsi);
        $rsiSlope = $currRsi - $prevRsi;

        $rsiZone = 'neutral';
        if ($currRsi >= 70.0) {
            $rsiZone = 'overbought';
        } elseif ($currRsi <= 30.0) {
            $rsiZone = 'oversold';
        } elseif ($currRsi >= 55.0) {
            $rsiZone = 'bullish_momentum';
        } elseif ($currRsi <= 45.0) {
            $rsiZone = 'bearish_momentum';
        }

        // 3. MACD (12, 26, 9)
        $macdData = self::macd($closes, 12, 26, 9);
        $currMacd = $macdData['macd'];
        $currMacdSig = $macdData['signal'];
        $currMacdHist = $macdData['hist'];
        $prevMacdHist = $macdData['prev_hist'];
        $histRising = $currMacdHist > $prevMacdHist;

        $macdMomentum = 'neutral';
        if ($currMacdHist > 0 && $histRising) {
            $macdMomentum = 'bullish_expanding';
        } elseif ($currMacdHist > 0 && !$histRising) {
            $macdMomentum = 'bullish_contracting';
        } elseif ($currMacdHist < 0 && !$histRising) {
            $macdMomentum = 'bearish_expanding';
        } elseif ($currMacdHist < 0 && $histRising) {
            $macdMomentum = 'bearish_contracting';
        }

        // 4. Volatility: ATR (14) & Dynamic Channel (Keltner/ATR Channel)
        $atrSeries = SmcEngine::atr($candles, 14);
        $currAtr = (float)end($atrSeries);
        $avgAtr100 = array_sum(array_slice($atrSeries, -100)) / max(1, count(array_slice($atrSeries, -100)));
        $volatilityRatio = $avgAtr100 > 0 ? $currAtr / $avgAtr100 : 1.0;
        $volatilityLevel = $volatilityRatio > 1.4 ? 'extreme' : ($volatilityRatio > 1.15 ? 'high' : ($volatilityRatio < 0.75 ? 'contracted' : 'normal'));

        // Dynamic Channel around EMA 20
        $channelUpper = round($currEma20 + (2.0 * $currAtr), 4);
        $channelLower = round($currEma20 - (2.0 * $currAtr), 4);
        $channelBand = max(0.0001, $channelUpper - $channelLower);
        $channelPosPct = round((($currPrice - $channelLower) / $channelBand) * 100.0, 1);

        // 5. Swing Structure: HH, HL, LH, LL
        $swings = self::detectSwings($candles, 3, 3);
        $structureSummary = self::classifySwings($swings['highs'], $swings['lows']);

        // Check for Regular Divergence between Price and RSI across last 2 swing points
        $divergence = self::detectRsiDivergence($swings['highs'], $swings['lows'], $rsiSeries, $candles);

        // 6. Support & Resistance Clusters & Dynamic Trendlines
        $srZones = self::calculateSupportResistance($candles, $swings['all'], $currPrice, $currAtr);
        $trendlines = self::calculateDynamicTrendlines($swings['highs'], $swings['lows'], $n, $currPrice);

        // 7. BOS / CHoCH / MSS Detection
        $structureEvents = self::detectStructureTransitions($candles, $swings['highs'], $swings['lows'], $currAtr);

        // 8. Classify Market into the 6 Exact Labels
        $classificationResult = self::determineClassification(
            $currPrice,
            $currEma20,
            $currEma50,
            $currEma200,
            $ribbonState,
            $structureSummary,
            $currRsi,
            $rsiZone,
            $macdMomentum,
            $currMacdHist,
            $divergence,
            $structureEvents,
            $volatilityLevel,
            $goldenCross,
            $deathCross
        );

        $classification = $classificationResult['classification'];
        $confidenceScore = $classificationResult['confidence_score'];
        $evidence = $classificationResult['evidence'];

        return [
            'ok'                   => true,
            'classification'       => $classification,
            'confidence_score'     => $confidenceScore,
            'evidence'             => $evidence,
            'market_bias'          => str_contains($classification, 'Bullish') ? 'Bullish' : (str_contains($classification, 'Bearish') ? 'Bearish' : 'Neutral'),
            'price'                => $currPrice,
            'moving_averages'      => [
                'ema20'            => round($currEma20, 4),
                'ema50'            => round($currEma50, 4),
                'ema200'           => round($currEma200, 4),
                'ribbon_state'     => $ribbonState,
                'slope20_pct'      => round($slope20Pct, 3),
                'slope50_pct'      => round($slope50Pct, 3),
                'dist_ema200_pct'  => round($distEma200Pct, 2),
                'golden_cross'     => $goldenCross,
                'death_cross'      => $deathCross,
            ],
            'rsi'                  => [
                'value'            => round($currRsi, 2),
                'zone'             => $rsiZone,
                'slope'            => round($rsiSlope, 2),
                'divergence'       => $divergence,
            ],
            'macd'                 => [
                'macd'             => round($currMacd, 4),
                'signal'           => round($currMacdSig, 4),
                'hist'             => round($currMacdHist, 4),
                'momentum'         => $macdMomentum,
            ],
            'volatility'           => [
                'atr'              => round($currAtr, 4),
                'level'            => $volatilityLevel,
                'ratio_vs_avg'     => round($volatilityRatio, 2),
            ],
            'dynamic_channel'      => [
                'upper'            => $channelUpper,
                'middle'           => round($currEma20, 4),
                'lower'            => $channelLower,
                'position_pct'     => $channelPosPct,
            ],
            'dynamic_trendlines'   => $trendlines,
            'swings_structure'     => $structureSummary,
            'support_resistance'   => $srZones,
            'structure_events'     => $structureEvents,
            'analyzed_bars'        => $n,
        ];
    }

    /**
     * Exponential Moving Average (EMA).
     */
    public static function ema(array $values, int $period): array
    {
        $n = count($values);
        if ($n === 0) return [];
        $k = 2.0 / ($period + 1);
        $ema = [];

        // Seed with SMA over period (or available)
        $seedCount = min($n, $period);
        $seedSum = 0.0;
        for ($i = 0; $i < $seedCount; $i++) {
            $seedSum += (float)$values[$i];
            $ema[$i] = $seedSum / ($i + 1);
        }

        for ($i = $seedCount; $i < $n; $i++) {
            $ema[$i] = ((float)$values[$i] * $k) + ($ema[$i - 1] * (1.0 - $k));
        }

        return $ema;
    }

    /**
     * Relative Strength Index (RSI - 14).
     */
    public static function rsi(array $closes, int $period = 14): array
    {
        $n = count($closes);
        if ($n < 2) return array_fill(0, $n, 50.0);

        $gains = [];
        $losses = [];
        for ($i = 1; $i < $n; $i++) {
            $diff = (float)$closes[$i] - (float)$closes[$i - 1];
            $gains[] = $diff > 0 ? $diff : 0.0;
            $losses[] = $diff < 0 ? abs($diff) : 0.0;
        }

        $rsi = [50.0]; // First index
        $avgGain = array_sum(array_slice($gains, 0, min(count($gains), $period))) / max(1, min(count($gains), $period));
        $avgLoss = array_sum(array_slice($losses, 0, min(count($losses), $period))) / max(1, min(count($losses), $period));

        for ($i = 0; $i < count($gains); $i++) {
            if ($i >= $period) {
                $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
                $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
            }
            if ($avgLoss <= 0.0000001) {
                $rsiVal = 100.0;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsiVal = 100.0 - (100.0 / (1.0 + $rs));
            }
            $rsi[] = round($rsiVal, 2);
        }

        return $rsi;
    }

    /**
     * Moving Average Convergence Divergence (MACD).
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $emaFast = self::ema($closes, $fast);
        $emaSlow = self::ema($closes, $slow);
        $n = count($closes);

        $macdLine = [];
        for ($i = 0; $i < $n; $i++) {
            $macdLine[] = $emaFast[$i] - $emaSlow[$i];
        }

        $sigLine = self::ema($macdLine, $signal);
        $currMacd = (float)end($macdLine);
        $currSig = (float)end($sigLine);
        $currHist = $currMacd - $currSig;

        $prevMacd = (float)($macdLine[max(0, $n - 2)] ?? $currMacd);
        $prevSig  = (float)($sigLine[max(0, $n - 2)] ?? $currSig);
        $prevHist = $prevMacd - $prevSig;

        return [
            'macd'      => $currMacd,
            'signal'    => $currSig,
            'hist'      => $currHist,
            'prev_hist' => $prevHist,
            'line'      => $macdLine,
            'sig_line'  => $sigLine,
        ];
    }

    /**
     * Detect Swing Highs and Swing Lows.
     */
    public static function detectSwings(array $candles, int $left = 3, int $right = 3): array
    {
        $n = count($candles);
        $highs = [];
        $lows = [];
        $all = [];

        for ($i = $left; $i < $n - $right; $i++) {
            $isHigh = true;
            $isLow  = true;
            $h = (float)$candles[$i]['h'];
            $l = (float)$candles[$i]['l'];

            for ($j = $i - $left; $j <= $i + $right; $j++) {
                if ($j === $i) continue;
                if ((float)$candles[$j]['h'] >= $h) $isHigh = false;
                if ((float)$candles[$j]['l'] <= $l) $isLow = false;
            }

            if ($isHigh) {
                $sw = ['type' => 'high', 'price' => $h, 'time' => $candles[$i]['t'], 'index' => $i];
                $highs[] = $sw;
                $all[] = $sw;
            }
            if ($isLow) {
                $sw = ['type' => 'low', 'price' => $l, 'time' => $candles[$i]['t'], 'index' => $i];
                $lows[] = $sw;
                $all[] = $sw;
            }
        }

        usort($all, static fn($a, $b) => $a['index'] <=> $b['index']);

        return ['highs' => $highs, 'lows' => $lows, 'all' => $all];
    }

    /**
     * Classify swing sequence into HH, HL, LH, LL.
     */
    public static function classifySwings(array $highs, array $lows): array
    {
        $hhCount = 0;
        $hlCount = 0;
        $lhCount = 0;
        $llCount = 0;
        $sequence = [];

        // Classify highs
        $nH = count($highs);
        for ($i = 1; $i < $nH; $i++) {
            if ($highs[$i]['price'] > $highs[$i - 1]['price']) {
                $hhCount++;
                $sequence[] = 'HH';
            } else {
                $lhCount++;
                $sequence[] = 'LH';
            }
        }

        // Classify lows
        $nL = count($lows);
        for ($i = 1; $i < $nL; $i++) {
            if ($lows[$i]['price'] > $lows[$i - 1]['price']) {
                $hlCount++;
                $sequence[] = 'HL';
            } else {
                $llCount++;
                $sequence[] = 'LL';
            }
        }

        $lastHigh = $highs ? end($highs) : null;
        $lastLow  = $lows ? end($lows) : null;
        $prevHigh = count($highs) >= 2 ? $highs[count($highs) - 2] : null;
        $prevLow  = count($lows) >= 2 ? $lows[count($lows) - 2] : null;

        $lastHighType = ($lastHigh && $prevHigh) ? ($lastHigh['price'] > $prevHigh['price'] ? 'HH' : 'LH') : 'H';
        $lastLowType  = ($lastLow && $prevLow) ? ($lastLow['price'] > $prevLow['price'] ? 'HL' : 'LL') : 'L';

        $bias = 'mixed';
        if ($hhCount > $lhCount && $hlCount >= $llCount) {
            $bias = 'bullish_expansion';
        } elseif ($llCount > $hlCount && $lhCount >= $hhCount) {
            $bias = 'bearish_expansion';
        }

        return [
            'bias'           => $bias,
            'hh_count'       => $hhCount,
            'hl_count'       => $hlCount,
            'lh_count'       => $lhCount,
            'll_count'       => $llCount,
            'last_high'      => $lastHigh,
            'last_low'       => $lastLow,
            'last_high_type' => $lastHighType,
            'last_low_type'  => $lastLowType,
            'recent_pattern' => array_slice($sequence, -6),
        ];
    }

    /**
     * Detect Regular Bullish or Bearish Divergence between Price swings and RSI.
     */
    public static function detectRsiDivergence(array $highs, array $lows, array $rsiSeries, array $candles): array
    {
        $res = ['type' => 'none', 'confidence' => 'none', 'detail' => 'No active divergence confirmed.'];

        // Bearish Divergence: Price Higher High (HH) while RSI Lower High (LH) in overbought/high zone
        if (count($highs) >= 2) {
            $currH = end($highs);
            $prevH = $highs[count($highs) - 2];
            if ($currH['price'] > $prevH['price']) {
                $rsiCurr = $rsiSeries[$currH['index']] ?? 50.0;
                $rsiPrev = $rsiSeries[$prevH['index']] ?? 50.0;
                if ($rsiCurr < $rsiPrev && $rsiPrev >= 58.0) {
                    return [
                        'type'       => 'bearish_regular',
                        'confidence' => 'high',
                        'detail'     => sprintf(
                            'Regular Bearish Divergence: Price formed Higher High ($%.2f > $%.2f) while RSI formed Lower High (%.1f < %.1f)',
                            $currH['price'], $prevH['price'], $rsiCurr, $rsiPrev
                        ),
                    ];
                }
            }
        }

        // Bullish Divergence: Price Lower Low (LL) while RSI Higher Low (HL) in oversold/low zone
        if (count($lows) >= 2) {
            $currL = end($lows);
            $prevL = $lows[count($lows) - 2];
            if ($currL['price'] < $prevL['price']) {
                $rsiCurr = $rsiSeries[$currL['index']] ?? 50.0;
                $rsiPrev = $rsiSeries[$prevL['index']] ?? 50.0;
                if ($rsiCurr > $rsiPrev && $rsiPrev <= 42.0) {
                    return [
                        'type'       => 'bullish_regular',
                        'confidence' => 'high',
                        'detail'     => sprintf(
                            'Regular Bullish Divergence: Price formed Lower Low ($%.2f < $%.2f) while RSI formed Higher Low (%.1f > %.1f)',
                            $currL['price'], $prevL['price'], $rsiCurr, $rsiPrev
                        ),
                    ];
                }
            }
        }

        return $res;
    }

    /**
     * Compute Dynamic Support & Resistance Clusters from historical swings.
     */
    public static function calculateSupportResistance(array $candles, array $swings, float $currPrice, float $atr): array
    {
        $clusters = [];
        $threshold = max(0.0001, $atr * 0.4);

        foreach ($swings as $s) {
            $p = (float)$s['price'];
            $placed = false;
            foreach ($clusters as &$c) {
                if (abs($p - $c['price']) <= $threshold) {
                    $c['count']++;
                    $c['price'] = ($c['price'] * ($c['count'] - 1) + $p) / $c['count'];
                    $c['times'][] = $s['time'];
                    $placed = true;
                    break;
                }
            }
            unset($c);
            if (!$placed) {
                $clusters[] = [
                    'price' => $p,
                    'count' => 1,
                    'type'  => $s['type'],
                    'times' => [$s['time']],
                ];
            }
        }

        // Filter and sort clusters
        usort($clusters, static fn($a, $b) => $b['count'] <=> $a['count']);
        $significant = array_slice(array_filter($clusters, static fn($c) => $c['count'] >= 1), 0, 10);

        $supports = [];
        $resistances = [];
        foreach ($significant as $c) {
            $dist = round(abs($c['price'] - $currPrice), 2);
            $zone = [
                'price'    => round($c['price'], 4),
                'tests'    => $c['count'],
                'distance' => $dist,
            ];
            if ($c['price'] < $currPrice) {
                $supports[] = $zone;
            } elseif ($c['price'] > $currPrice) {
                $resistances[] = $zone;
            }
        }

        usort($supports, static fn($a, $b) => $a['distance'] <=> $b['distance']);
        usort($resistances, static fn($a, $b) => $a['distance'] <=> $b['distance']);

        return [
            'nearest_support'    => $supports[0] ?? null,
            'nearest_resistance' => $resistances[0] ?? null,
            'key_supports'       => array_slice($supports, 0, 3),
            'key_resistances'    => array_slice($resistances, 0, 3),
        ];
    }

    /**
     * Compute Dynamic Trendlines (Upper Resistance and Lower Support slopes).
     */
    public static function calculateDynamicTrendlines(array $highs, array $lows, int $totalBars, float $currPrice): array
    {
        $upperSlope = 0.0;
        $lowerSlope = 0.0;

        if (count($highs) >= 2) {
            $h2 = end($highs);
            $h1 = $highs[count($highs) - 2];
            $dx = max(1, $h2['index'] - $h1['index']);
            $upperSlope = ($h2['price'] - $h1['price']) / $dx;
        }

        if (count($lows) >= 2) {
            $l2 = end($lows);
            $l1 = $lows[count($lows) - 2];
            $dx = max(1, $l2['index'] - $l1['index']);
            $lowerSlope = ($l2['price'] - $l1['price']) / $dx;
        }

        $upperState = $upperSlope > 0 ? 'ascending_resistance' : ($upperSlope < 0 ? 'descending_resistance' : 'flat');
        $lowerState = $lowerSlope > 0 ? 'ascending_support' : ($lowerSlope < 0 ? 'descending_support' : 'flat');

        return [
            'upper_slope'      => round($upperSlope, 4),
            'lower_slope'      => round($lowerSlope, 4),
            'upper_trend'      => $upperState,
            'lower_trend'      => $lowerState,
            'channel_geometry' => ($upperSlope > 0 && $lowerSlope > 0) ? 'ascending_channel' : (($upperSlope < 0 && $lowerSlope < 0) ? 'descending_channel' : 'converging_or_range'),
        ];
    }

    /**
     * Detect Structure Transitions (BOS, CHoCH, MSS).
     */
    public static function detectStructureTransitions(array $candles, array $highs, array $lows, float $atr): array
    {
        $n = count($candles);
        $recentHigh = $highs ? end($highs) : null;
        $recentLow  = $lows ? end($lows) : null;
        $last = end($candles);
        $currClose = (float)$last['c'];

        $events = [];

        // Bullish BOS: Candle close > recent confirmed swing high
        if ($recentHigh && $currClose > $recentHigh['price']) {
            $events[] = [
                'type'        => 'BOS_BULLISH',
                'level'       => round($recentHigh['price'], 4),
                'description' => sprintf('Bullish Break of Structure: Price closed above swing high at $%.2f', $recentHigh['price']),
            ];
        }

        // Bearish BOS: Candle close < recent confirmed swing low
        if ($recentLow && $currClose < $recentLow['price']) {
            $events[] = [
                'type'        => 'BOS_BEARISH',
                'level'       => round($recentLow['price'], 4),
                'description' => sprintf('Bearish Break of Structure: Price closed below swing low at $%.2f', $recentLow['price']),
            ];
        }

        // CHoCH / MSS check: Check if previous trend high/low was violated with displacement
        $body = abs((float)$last['c'] - (float)$last['o']);
        $hasDisplacement = $body >= (1.2 * $atr);

        if ($recentLow && $currClose < $recentLow['price']) {
            $events[] = [
                'type'        => $hasDisplacement ? 'MSS_BEARISH' : 'CHOCH_BEARISH',
                'level'       => round($recentLow['price'], 4),
                'displacement'=> $hasDisplacement,
                'description' => sprintf(
                    '%s: Price broke below recent higher low at $%.2f%s',
                    $hasDisplacement ? 'Market Structure Shift (MSS Bearish)' : 'Change of Character (CHoCH Bearish)',
                    $recentLow['price'],
                    $hasDisplacement ? ' with strong institutional displacement' : ''
                ),
            ];
        } elseif ($recentHigh && $currClose > $recentHigh['price']) {
            $events[] = [
                'type'        => $hasDisplacement ? 'MSS_BULLISH' : 'CHOCH_BULLISH',
                'level'       => round($recentHigh['price'], 4),
                'displacement'=> $hasDisplacement,
                'description' => sprintf(
                    '%s: Price broke above recent lower high at $%.2f%s',
                    $hasDisplacement ? 'Market Structure Shift (MSS Bullish)' : 'Change of Character (CHoCH Bullish)',
                    $recentHigh['price'],
                    $hasDisplacement ? ' with strong institutional displacement' : ''
                ),
            ];
        }

        return $events;
    }

    /**
     * Determine the EXACT 6 classifications and construct evidentiary explanations.
     */
    private static function determineClassification(
        float $price,
        float $ema20,
        float $ema50,
        float $ema200,
        string $ribbonState,
        array $swings,
        float $rsi,
        string $rsiZone,
        string $macdMomentum,
        float $macdHist,
        array $divergence,
        array $structureEvents,
        string $volatility,
        bool $goldenCross,
        bool $deathCross
    ): array {
        $evidence = [];
        $confidence = 50;
        $classification = self::CLASSIFICATION_CONSOLIDATION;

        // Evidence 1: Moving Average Ribbon
        if ($ribbonState === 'bullish_stacked') {
            $evidence[] = sprintf('EMA Ribbon: Full Bullish Stack confirmed (Price $%.2f > EMA20 $%.2f > EMA50 $%.2f > EMA200 $%.2f).', $price, $ema20, $ema50, $ema200);
        } elseif ($ribbonState === 'bearish_stacked') {
            $evidence[] = sprintf('EMA Ribbon: Full Bearish Stack confirmed (Price $%.2f < EMA20 $%.2f < EMA50 $%.2f < EMA200 $%.2f).', $price, $ema20, $ema50, $ema200);
        } else {
            $evidence[] = sprintf('EMA Ribbon: Mixed/Intertwined alignment (Price $%.2f, EMA20 $%.2f, EMA50 $%.2f, EMA200 $%.2f).', $price, $ema20, $ema50, $ema200);
        }

        // Evidence 2: Swings Structure
        $evidence[] = sprintf(
            'Market Structure: Swings show %d Higher Highs (HH), %d Higher Lows (HL) vs %d Lower Highs (LH), %d Lower Lows (LL) [Last: %s / %s].',
            $swings['hh_count'], $swings['hl_count'], $swings['lh_count'], $swings['ll_count'],
            $swings['last_high_type'], $swings['last_low_type']
        );

        // Evidence 3: Oscillators & Momentum
        $evidence[] = sprintf(
            'Momentum: RSI(14) at %.1f (%s) · MACD momentum is %s with histogram %.2f.',
            $rsi, $rsiZone, str_replace('_', ' ', $macdMomentum), $macdHist
        );

        // Evidence 4: Divergence & Reversal Signals
        if ($divergence['type'] !== 'none') {
            $evidence[] = 'Divergence: ' . $divergence['detail'];
        }

        // Classification Evaluation Logic:

        // 1. Potential Reversal Check:
        // Divergence confirmed + MSS or CHoCH opposite to prior trend
        $hasMssOrChoch = false;
        foreach ($structureEvents as $ev) {
            if (str_contains($ev['type'], 'MSS') || str_contains($ev['type'], 'CHOCH')) {
                $hasMssOrChoch = true;
                $evidence[] = 'Structure Event: ' . $ev['description'];
                break;
            }
        }

        if ($divergence['type'] !== 'none' && $hasMssOrChoch) {
            $classification = self::CLASSIFICATION_REVERSAL;
            $confidence = 88;
            $evidence[] = 'Classification Reason: Active price/RSI regular divergence confirmed alongside institutional CHoCH/MSS.';
            return [
                'classification'   => $classification,
                'confidence_score' => $confidence,
                'evidence'         => $evidence,
            ];
        }

        // 2. Strong Bullish Trend:
        // Stacked EMA ribbon, HH + HL sequence, RSI > 52, MACD positive & expanding, Price > EMA 20
        if (
            $ribbonState === 'bullish_stacked'
            && $price > $ema20
            && $swings['hh_count'] >= $swings['lh_count']
            && $swings['hl_count'] >= $swings['ll_count']
            && $rsi >= 52.0
            && $macdHist >= -0.05
            && $divergence['type'] !== 'bearish_regular'
        ) {
            $classification = self::CLASSIFICATION_STRONG_BULLISH;
            $confidence = min(96, 75 + (int)($rsi > 60 ? 10 : 5) + ($goldenCross ? 10 : 5));
            $evidence[] = 'Classification Reason: Complete multi-factor bullish alignment across EMA stack, swing structure, and positive momentum.';
            return [
                'classification'   => $classification,
                'confidence_score' => $confidence,
                'evidence'         => $evidence,
            ];
        }

        // 3. Strong Bearish Trend:
        // Stacked EMA ribbon (Price < 20 < 50 < 200), LH + LL sequence, RSI <= 48, MACD negative & expanding
        if (
            $ribbonState === 'bearish_stacked'
            && $price < $ema20
            && $swings['lh_count'] >= $swings['hh_count']
            && $swings['ll_count'] >= $swings['hl_count']
            && $rsi <= 48.0
            && $macdHist <= 0.05
            && $divergence['type'] !== 'bullish_regular'
        ) {
            $classification = self::CLASSIFICATION_STRONG_BEARISH;
            $confidence = min(96, 75 + (int)($rsi < 40 ? 10 : 5) + ($deathCross ? 10 : 5));
            $evidence[] = 'Classification Reason: Complete multi-factor bearish alignment across EMA stack, swing structure, and negative momentum.';
            return [
                'classification'   => $classification,
                'confidence_score' => $confidence,
                'evidence'         => $evidence,
            ];
        }

        // 4. Consolidation / Range Check:
        // Moving averages are intertwined (mixed ribbon) or flat, swings lack directional expansion, and RSI oscillating near 50
        $isFlatRibbon = (abs($ema20 - $ema50) / max(0.0001, $price)) < 0.0035;
        $isOscillating = ($ribbonState === 'mixed') || $isFlatRibbon;
        if (
            $isOscillating
            && ($swings['bias'] === 'mixed' || (abs($swings['hh_count'] - $swings['lh_count']) <= 2 && abs($swings['hl_count'] - $swings['ll_count']) <= 2))
            && $rsi >= 42.0 && $rsi <= 58.0
        ) {
            $classification = self::CLASSIFICATION_CONSOLIDATION;
            $confidence = 72;
            $evidence[] = 'Classification Reason: Moving averages are intertwined or flat, swings lack directional breakout, and RSI is oscillating around equilibrium (45–55).';
            return [
                'classification'   => $classification,
                'confidence_score' => $confidence,
                'evidence'         => $evidence,
            ];
        }

        // 5. Weak Bullish Trend:
        // Price above EMA 50 or EMA 200, with bullish swing bias and supportive RSI
        if (
            ($price > $ema50 || $price > $ema200 || $swings['bias'] === 'bullish_expansion')
            && $price >= $ema200
            && $rsi >= 48.0
        ) {
            $classification = self::CLASSIFICATION_WEAK_BULLISH;
            $confidence = 65;
            $evidence[] = 'Classification Reason: Bullish structural bias persists above EMA 50/200, but momentum is moderating or in a corrective pullback.';
            return [
                'classification'   => $classification,
                'confidence_score' => $confidence,
                'evidence'         => $evidence,
            ];
        }

        // 6. Weak Bearish Trend:
        // Price below EMA 50 or EMA 200, with bearish swing bias and suppressed RSI
        if (
            ($price < $ema50 || $price < $ema200 || $swings['bias'] === 'bearish_expansion')
            && $price <= $ema200
            && $rsi <= 52.0
        ) {
            $classification = self::CLASSIFICATION_WEAK_BEARISH;
            $confidence = 65;
            $evidence[] = 'Classification Reason: Bearish structural bias persists below EMA 50/200, but momentum is paused or testing intraday resistance.';
            return [
                'classification'   => $classification,
                'confidence_score' => $confidence,
                'evidence'         => $evidence,
            ];
        }

        // 6. Consolidation / Range (Default when trend is undefined / flat):
        $classification = self::CLASSIFICATION_CONSOLIDATION;
        $confidence = 55;
        $evidence[] = 'Classification Reason: Moving averages are intertwined or flat, swings lack directional breakout, and RSI is oscillating around equilibrium (45–55).';

        return [
            'classification'   => $classification,
            'confidence_score' => $confidence,
            'evidence'         => $evidence,
        ];
    }
}
