<?php
declare(strict_types=1);

namespace App\ML;

use App\Analysis\SessionService;
use App\Analysis\TrendAnalysisEngine;
use App\Market\Timeframe;
use App\SMC\SmcEngine;

/**
 * Extracts point-in-time quantitative feature vectors with STRICT ZERO LOOK-AHEAD BIAS.
 * All calculations depend solely on candles that closed at or before $now.
 */
final class FeatureExtractor
{
    public const FEATURE_NAMES = [
        'htf_bias_num',
        'entry_trend_num',
        'rsi_14_norm',
        'atr_ratio',
        'ema20_slope',
        'ema50_slope',
        'ema_stacked',
        'bos_recency_norm',
        'has_displacement',
        'fvg_size_atr',
        'ob_size_atr',
        'pd_position_pct',
        'in_discount',
        'in_premium',
        'liq_sweep_depth_atr',
        'is_london_kz',
        'is_ny_kz',
        'is_asia',
        'minute_of_day_norm',
        'day_of_week_norm',
        'stop_dist_atr',
        'target_rr1',
        'target_rr2',
        'spread_cost_ratio',
        'volatility_regime_num',
    ];

    private SessionService $sessions;

    public function __construct(?SessionService $sessions = null)
    {
        $this->sessions = $sessions ?? new SessionService();
    }

    /**
     * Extract normalized feature vector from closed candles and context.
     *
     * @param array<string, array> $closedCandlesByTf Timeframe => closed candles array
     * @param array $setupParams Candidate trade parameters (dir, entry, sl, tp1, tp2, spread)
     * @param int $now Current evaluation timestamp
     * @param string $entryTf Primary execution timeframe (e.g. 'M15')
     * @param string $poiTf Higher timeframe for structure & dealing range (e.g. 'H1')
     * @return array{vector: array<float>, map: array<string, float>}
     */
    public function extract(
        array $closedCandlesByTf,
        array $setupParams,
        int $now,
        string $entryTf = 'M15',
        string $poiTf = 'H1'
    ): array {
        $cEntry = $closedCandlesByTf[$entryTf] ?? [];
        $cPoi = $closedCandlesByTf[$poiTf] ?? [];

        $nEntry = count($cEntry);
        if ($nEntry < 30) {
            return $this->defaultVector();
        }

        $price = (float)$cEntry[$nEntry - 1]['c'];
        $dir = (int)($setupParams['dir'] ?? 1); // 1 = BUY, -1 = SELL

        // 1. Moving Averages & RSI on Entry TF
        $closes = array_map(static fn($c) => (float)$c['c'], $cEntry);
        $rsiVal = $this->computeRsi($closes, 14);
        $rsiNorm = max(0.0, min(1.0, $rsiVal / 100.0));

        $ema20 = $this->computeEma($closes, 20);
        $ema50 = $this->computeEma($closes, 50);
        $ema200 = $this->computeEma($closes, 200);

        $nCloses = count($closes);
        $ema20Slope = 0.0;
        $ema50Slope = 0.0;
        if ($nCloses >= 5 && $ema20 > 0 && $ema50 > 0) {
            $prevCloses = array_slice($closes, 0, $nCloses - 3);
            $prevEma20 = $this->computeEma($prevCloses, 20);
            $prevEma50 = $this->computeEma($prevCloses, 50);
            $ema20Slope = ($ema20 - $prevEma20) / max(0.001, $prevEma20) * 100.0;
            $ema50Slope = ($ema50 - $prevEma50) / max(0.001, $prevEma50) * 100.0;
        }

        $emaStacked = 0.0;
        if ($ema200 !== null) {
            if ($dir === 1 && $ema20 > $ema50 && $ema50 > $ema200) {
                $emaStacked = 1.0;
            } elseif ($dir === -1 && $ema20 < $ema50 && $ema50 < $ema200) {
                $emaStacked = 1.0;
            }
        }

        // 2. ATR & Volatility
        $atr = $this->computeAtr($cEntry, 14);
        $longAtr = $this->computeAtr($cEntry, 50);
        $atrRatio = $longAtr > 0 ? ($atr / $longAtr) : 1.0;
        $volatilityRegime = 0.0;
        if ($atrRatio > 1.8) {
            $volatilityRegime = -1.0; // abnormal volatility
        } elseif ($atrRatio < 0.7) {
            $volatilityRegime = 0.0;  // low volatility consolidation
        } else {
            $volatilityRegime = 1.0;  // healthy trending volatility
        }

        // 3. SMC Structure Analysis on Entry and POI TF
        $smcEngine = new SmcEngine();
        $smcEntry = $smcEngine->analyze($cEntry);
        $smcPoi = !empty($cPoi) ? $smcEngine->analyze($cPoi) : null;

        $entryTrendNum = (float)($smcEntry['trend'] ?? 0);
        $htfBiasNum = $smcPoi ? (float)($smcPoi['trend'] ?? 0) : $entryTrendNum;

        // BOS recency
        $bosRecency = 1.0;
        $hasDisplacement = 0.0;
        if (!empty($smcEntry['events'])) {
            $lastEvt = end($smcEntry['events']);
            $barsAgo = ($nEntry - 1) - (int)$lastEvt['i'];
            $bosRecency = max(0.0, min(1.0, $barsAgo / 25.0));
            $hasDisplacement = !empty($lastEvt['has_displacement']) || !empty($lastEvt['mss']) ? 1.0 : 0.0;
        }

        // FVG & OB size
        $fvgSizeAtr = 0.0;
        if (!empty($smcEntry['fvgs'])) {
            foreach (array_reverse($smcEntry['fvgs']) as $fvg) {
                if ($fvg['dir'] === $dir && in_array($fvg['status'], ['fresh', 'partial'], true)) {
                    $gap = abs((float)$fvg['top'] - (float)$fvg['bottom']);
                    $fvgSizeAtr = $atr > 0 ? min(3.0, $gap / $atr) : 0.5;
                    break;
                }
            }
        }

        $obSizeAtr = 0.0;
        if (!empty($smcEntry['order_blocks'])) {
            foreach (array_reverse($smcEntry['order_blocks']) as $ob) {
                if ($ob['dir'] === $dir && in_array($ob['status'], ['fresh', 'mitigated'], true)) {
                    $height = abs((float)$ob['top'] - (float)$ob['bottom']);
                    $obSizeAtr = $atr > 0 ? min(3.0, $height / $atr) : 0.5;
                    break;
                }
            }
        }

        // Liquidity Sweep
        $sweepDepthAtr = 0.0;
        if (!empty($smcEntry['liquidity']['sweeps'])) {
            foreach (array_reverse($smcEntry['liquidity']['sweeps']) as $sweep) {
                if ($sweep['dir'] === $dir) {
                    $depth = abs((float)$sweep['extreme'] - (float)$sweep['level']);
                    $sweepDepthAtr = $atr > 0 ? min(3.0, $depth / $atr) : 0.2;
                    break;
                }
            }
        }

        // Dealing Range (Premium vs Discount)
        $pd = $smcPoi['premium_discount'] ?? ($smcEntry['premium_discount'] ?? null);
        $pdPos = 0.5;
        $inDiscount = 0.0;
        $inPremium = 0.0;
        if ($pd && $pd['high'] > $pd['low']) {
            $pdPos = max(0.0, min(1.0, ($price - $pd['low']) / ($pd['high'] - $pd['low'])));
            $inDiscount = $pdPos <= 0.5 ? 1.0 : 0.0;
            $inPremium = $pdPos > 0.5 ? 1.0 : 0.0;
        }

        // 4. Session & Time of Day
        $hourUtc = (int)gmdate('G', $now);
        $minuteUtc = (int)gmdate('i', $now);
        $minuteOfDay = $hourUtc * 60 + $minuteUtc;
        $minOfDayNorm = $minuteOfDay / 1440.0;

        $dayOfWeek = (int)gmdate('N', $now); // 1 = Monday ... 7 = Sunday
        $dayOfWeekNorm = min(1.0, $dayOfWeek / 5.0);

        $isLondonKz = ($hourUtc >= 7 && $hourUtc <= 10) ? 1.0 : 0.0; // 07:00 - 10:00 UTC (London KZ)
        $isNyKz = ($hourUtc >= 12 && $hourUtc <= 15) ? 1.0 : 0.0;     // 12:00 - 15:00 UTC (NY AM KZ)
        $isAsia = ($hourUtc >= 0 && $hourUtc <= 6) ? 1.0 : 0.0;       // Asian session

        // 5. Setup Geometry & Costs
        $entryPrice = (float)($setupParams['entry'] ?? $price);
        $slPrice = (float)($setupParams['sl'] ?? ($dir === 1 ? $price - 1.5 * $atr : $price + 1.5 * $atr));
        $risk = abs($entryPrice - $slPrice);
        $stopDistAtr = $atr > 0 ? min(5.0, $risk / $atr) : 1.5;

        $tp1Price = (float)($setupParams['tp1'] ?? ($dir === 1 ? $entryPrice + 2.0 * $risk : $entryPrice - 2.0 * $risk));
        $tp2Price = (float)($setupParams['tp2'] ?? ($dir === 1 ? $entryPrice + 3.5 * $risk : $entryPrice - 3.5 * $risk));

        $rr1 = $risk > 0 ? min(8.0, abs($tp1Price - $entryPrice) / $risk) : 1.5;
        $rr2 = $risk > 0 ? min(12.0, abs($tp2Price - $entryPrice) / $risk) : 3.0;

        $spread = (float)($setupParams['spread'] ?? 0.30);
        $spreadCostRatio = $risk > 0 ? min(0.5, $spread / $risk) : 0.05;

        // Build key-value map
        $map = [
            'htf_bias_num'          => (float)$htfBiasNum,
            'entry_trend_num'        => (float)$entryTrendNum,
            'rsi_14_norm'            => round($rsiNorm, 4),
            'atr_ratio'              => round($atrRatio, 4),
            'ema20_slope'            => round($ema20Slope, 4),
            'ema50_slope'            => round($ema50Slope, 4),
            'ema_stacked'            => (float)$emaStacked,
            'bos_recency_norm'       => round($bosRecency, 4),
            'has_displacement'       => (float)$hasDisplacement,
            'fvg_size_atr'           => round($fvgSizeAtr, 4),
            'ob_size_atr'            => round($obSizeAtr, 4),
            'pd_position_pct'        => round($pdPos, 4),
            'in_discount'            => (float)$inDiscount,
            'in_premium'             => (float)$inPremium,
            'liq_sweep_depth_atr'    => round($sweepDepthAtr, 4),
            'is_london_kz'           => (float)$isLondonKz,
            'is_ny_kz'               => (float)$isNyKz,
            'is_asia'                => (float)$isAsia,
            'minute_of_day_norm'     => round($minOfDayNorm, 4),
            'day_of_week_norm'       => round($dayOfWeekNorm, 4),
            'stop_dist_atr'          => round($stopDistAtr, 4),
            'target_rr1'             => round($rr1, 4),
            'target_rr2'             => round($rr2, 4),
            'spread_cost_ratio'      => round($spreadCostRatio, 4),
            'volatility_regime_num'  => (float)$volatilityRegime,
        ];

        $vector = [];
        foreach (self::FEATURE_NAMES as $name) {
            $vector[] = (float)($map[$name] ?? 0.0);
        }

        return ['vector' => $vector, 'map' => $map];
    }

    private function defaultVector(): array
    {
        $map = array_fill_keys(self::FEATURE_NAMES, 0.0);
        $map['atr_ratio'] = 1.0;
        $map['pd_position_pct'] = 0.5;
        $map['rsi_14_norm'] = 0.5;
        $map['target_rr1'] = 2.0;
        $map['target_rr2'] = 3.5;
        return [
            'vector' => array_values($map),
            'map' => $map,
        ];
    }

    private function computeRsi(array $closes, int $period = 14): float
    {
        $n = count($closes);
        if ($n <= $period) return 50.0;

        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff >= 0) $gains += $diff; else $losses -= $diff;
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        for ($i = $period + 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = $diff >= 0 ? $diff : 0.0;
            $loss = $diff < 0 ? -$diff : 0.0;
            $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;
        }

        if ($avgLoss <= 0.000001) return 100.0;
        $rs = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    private function computeEma(array $closes, int $period): ?float
    {
        $n = count($closes);
        if ($n < $period) return null;
        $k = 2.0 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;
        for ($i = $period; $i < $n; $i++) {
            $ema = $closes[$i] * $k + $ema * (1.0 - $k);
        }
        return $ema;
    }

    private function computeAtr(array $candles, int $period = 14): float
    {
        $n = count($candles);
        if ($n < 2) return 1.0;
        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $h = (float)$candles[$i]['h'];
            $l = (float)$candles[$i]['l'];
            $pc = (float)$candles[$i - 1]['c'];
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        $p = min($period, count($trs));
        if ($p <= 0) return 1.0;
        return array_sum(array_slice($trs, -$p)) / $p;
    }
}
