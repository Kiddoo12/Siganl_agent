<?php
declare(strict_types=1);

namespace App\Dataset;

use App\SMC\IctEngine;
use App\SMC\SmcEngine;

/**
 * Deterministic Preliminary Pattern Labeler for Chart Windows.
 *
 * Automatically generates rule-based candidate annotations for:
 *  - Swing Highs / Lows (pivots with confirmed confirmation window)
 *  - Break of Structure (BOS) & Change of Character (CHoCH)
 *  - Fair Value Gaps (Bullish & Bearish FVGs with displacement)
 *  - Order Blocks (Bullish & Bearish OBs preceding displacement)
 *  - Liquidity Sweeps (BSL & SSL wick rejections)
 *  - Premium / Discount Dealing Range (50% Equilibrium)
 *
 * CRITICAL COMPLIANCE RULE:
 *  All generated annotations are tagged with is_preliminary = true and source = 'ai_predicted'.
 *  They are strictly flagged as preliminary candidates and NEVER recorded as ground-truth
 *  until verified and approved by a human analyst.
 */
final class PreliminaryPatternLabeler
{
    private SmcEngine $smc;
    private IctEngine $ict;

    public function __construct(?SmcEngine $smc = null, ?IctEngine $ict = null)
    {
        $this->smc = $smc ?? new SmcEngine();
        $this->ict = $ict ?? new IctEngine();
    }

    /**
     * Label an execution chart window with preliminary candidates.
     *
     * @param array<array{t: int, o: float, h: float, l: float, c: float, v?: float}> $windowCandles
     * @param array $htfWindows Synchronized higher timeframe windows
     * @return array{
     *   annotations: array<array>,
     *   market_regime: string,
     *   trend_direction: string,
     *   market_structure: string,
     *   dealing_range: array,
     *   candidate_setup: ?array,
     *   preliminary_summary: string
     * }
     */
    public function labelWindow(array $windowCandles, array $htfWindows = []): array
    {
        $count = count($windowCandles);
        if ($count < 25) {
            return [
                'annotations' => [],
                'market_regime' => 'insufficient_data',
                'trend_direction' => 'Neutral',
                'market_structure' => 'Undefined',
                'dealing_range' => [],
                'candidate_setup' => null,
                'preliminary_summary' => 'Insufficient candles for pattern labeling',
            ];
        }

        // Calculate window bounds for normalized coordinate mapping
        $highs = array_column($windowCandles, 'h');
        $lows = array_column($windowCandles, 'l');
        $pMax = max($highs);
        $pMin = min($lows);
        $pRange = max(0.001, $pMax - $pMin);

        // Run core SMC detection
        $smcRes = $this->smc->analyze($windowCandles);
        $atr = max(0.5, (float)$smcRes['atr']);

        $annotations = [];

        // 1. Fair Value Gaps (FVGs)
        foreach ($smcRes['fvgs'] as $fvg) {
            $fvgTop = (float)$fvg['top'];
            $fvgBottom = (float)$fvg['bottom'];
            $dir = (int)$fvg['dir']; // 1 = Bullish, -1 = Bearish
            $barIdx = (int)$fvg['i'];

            // Skip microscopic noise (< 0.15 ATR)
            if (($fvgTop - $fvgBottom) < 0.15 * $atr) {
                continue;
            }

            $box = $this->calcBox($barIdx, 12, $fvgTop, $fvgBottom, $count, $pMax, $pRange);
            $annotations[] = [
                'pattern_type' => $dir === 1 ? 'FVG_BULL' : 'FVG_BEAR',
                'direction' => $dir === 1 ? 'bullish' : 'bearish',
                'source' => 'ai_predicted',
                'is_preliminary' => 1,
                'is_confirmed' => 0,
                'box_x' => $box['x'],
                'box_y' => $box['y'],
                'box_w' => $box['w'],
                'box_h' => $box['h'],
                'price_top' => round($fvgTop, 3),
                'price_bottom' => round($fvgBottom, 3),
                'price_level' => round(($fvgTop + $fvgBottom) / 2.0, 3),
                'uncertainty_score' => $fvg['status'] === 'fresh' ? 20 : 45,
                'notes' => 'Preliminary ' . ($dir === 1 ? 'Bullish' : 'Bearish') . ' FVG imbalance (status: ' . $fvg['status'] . ')',
            ];
        }

        // 2. Order Blocks (OBs)
        foreach ($smcRes['order_blocks'] as $ob) {
            $obTop = (float)$ob['top'];
            $obBottom = (float)$ob['bottom'];
            $dir = (int)$ob['dir'];
            $barIdx = (int)$ob['i'];

            $box = $this->calcBox($barIdx, 16, $obTop, $obBottom, $count, $pMax, $pRange);
            $annotations[] = [
                'pattern_type' => $dir === 1 ? 'OB_BULL' : 'OB_BEAR',
                'direction' => $dir === 1 ? 'bullish' : 'bearish',
                'source' => 'ai_predicted',
                'is_preliminary' => 1,
                'is_confirmed' => 0,
                'box_x' => $box['x'],
                'box_y' => $box['y'],
                'box_w' => $box['w'],
                'box_h' => $box['h'],
                'price_top' => round($obTop, 3),
                'price_bottom' => round($obBottom, 3),
                'price_level' => round(($obTop + $obBottom) / 2.0, 3),
                'uncertainty_score' => $ob['status'] === 'fresh' ? 25 : 50,
                'notes' => 'Preliminary ' . ($dir === 1 ? 'Bullish' : 'Bearish') . ' Order Block (status: ' . $ob['status'] . ')',
            ];
        }

        // 3. Structure Breaks (BOS / CHoCH)
        foreach ($smcRes['events'] as $evt) {
            $evtType = (string)$evt['type']; // 'BOS' or 'CHOCH'
            $dir = (int)$evt['dir'];
            $barIdx = (int)$evt['i'];
            $level = (float)$evt['level'];

            $box = $this->calcBox(max(0, $barIdx - 4), 10, $level + 0.3 * $atr, $level - 0.3 * $atr, $count, $pMax, $pRange);
            $annotations[] = [
                'pattern_type' => $evtType,
                'direction' => $dir === 1 ? 'bullish' : 'bearish',
                'source' => 'ai_predicted',
                'is_preliminary' => 1,
                'is_confirmed' => 0,
                'box_x' => $box['x'],
                'box_y' => $box['y'],
                'box_w' => $box['w'],
                'box_h' => $box['h'],
                'price_top' => round($level + 0.3 * $atr, 3),
                'price_bottom' => round($level - 0.3 * $atr, 3),
                'price_level' => round($level, 3),
                'uncertainty_score' => 20,
                'notes' => "Preliminary {$evtType} ({$evt['text']})",
            ];
        }

        // 4. Liquidity Sweeps
        $sweeps = $smcRes['liquidity']['sweeps'] ?? $smcRes['liquidity_sweeps'] ?? [];
        foreach ($sweeps as $swp) {
            $level = (float)$swp['level'];
            $dir = (int)$swp['dir'];
            $barIdx = (int)$swp['i'];
            $isBsl = $dir === -1; // Swept high = swept BSL

            $box = $this->calcBox(max(0, $barIdx - 2), 8, $level + 0.4 * $atr, $level - 0.4 * $atr, $count, $pMax, $pRange);
            $annotations[] = [
                'pattern_type' => 'LIQUIDITY_SWEEP',
                'direction' => $dir === 1 ? 'bullish' : 'bearish',
                'source' => 'ai_predicted',
                'is_preliminary' => 1,
                'is_confirmed' => 0,
                'box_x' => $box['x'],
                'box_y' => $box['y'],
                'box_w' => $box['w'],
                'box_h' => $box['h'],
                'price_top' => round($level + 0.4 * $atr, 3),
                'price_bottom' => round($level - 0.4 * $atr, 3),
                'price_level' => round($level, 3),
                'uncertainty_score' => 30,
                'notes' => 'Preliminary Liquidity Sweep (' . ($isBsl ? 'BSL Purged' : 'SSL Purged') . ')',
            ];
        }

        // Dealing range & Equilibrium
        $eqPrice = round(($pMax + $pMin) / 2.0, 3);
        $currPrice = (float)end($windowCandles)['c'];
        $isDiscount = $currPrice < $eqPrice;

        $trendDir = $smcRes['trend'] === 1 ? 'Bullish' : ($smcRes['trend'] === -1 ? 'Bearish' : 'Neutral');
        $structure = !empty($smcRes['events']) ? end($smcRes['events'])['text'] : 'Indecisive Range';

        // Synthesize candidate preliminary trade setup
        $candidateSetup = null;
        if (!empty($smcRes['events'])) {
            $lastEvt = end($smcRes['events']);
            $setupDir = (int)$lastEvt['dir'];
            $isBuy = $setupDir === 1;

            $entry = $currPrice;
            $sl = $isBuy ? ($pMin - 0.5 * $atr) : ($pMax + 0.5 * $atr);
            $riskPts = max(1.0, abs($entry - $sl));

            $candidateSetup = [
                'decision' => $isBuy ? 'BUY' : 'SELL',
                'entry' => round($entry, 3),
                'sl' => round($sl, 3),
                'tp1' => round($isBuy ? ($entry + 1.5 * $riskPts) : ($entry - 1.5 * $riskPts), 3),
                'tp2' => round($isBuy ? ($entry + 2.5 * $riskPts) : ($entry - 2.5 * $riskPts), 3),
                'tp3' => round($isBuy ? ($entry + 4.0 * $riskPts) : ($entry - 4.0 * $riskPts), 3),
                'rr' => round(2.5, 2),
            ];
        }

        return [
            'annotations' => $annotations,
            'market_regime' => $smcRes['trend'] === 0 ? 'ranging_consolidation' : 'trending',
            'trend_direction' => $trendDir,
            'market_structure' => $structure,
            'dealing_range' => [
                'high' => round($pMax, 3),
                'low' => round($pMin, 3),
                'equilibrium' => $eqPrice,
                'zone' => $isDiscount ? 'Discount' : 'Premium',
            ],
            'candidate_setup' => $candidateSetup,
            'preliminary_summary' => count($annotations) . ' preliminary candidate patterns auto-labeled (unverified).',
        ];
    }

    /**
     * Compute normalized bounding box coordinates [x, y, w, h] clamped between 0.0 and 1.0.
     */
    private function calcBox(int $barIdx, int $durationBars, float $top, float $bottom, int $totalBars, float $pMax, float $pRange): array
    {
        $x = max(0.0, min(1.0, $barIdx / max(1, $totalBars)));
        $w = max(0.04, min(1.0 - $x, $durationBars / max(1, $totalBars)));

        // Y axis in charting is inverted (top of screen is Y=0)
        $y = max(0.0, min(1.0, ($pMax - $top) / $pRange));
        $h = max(0.02, min(1.0 - $y, ($top - $bottom) / $pRange));

        return [
            'x' => round($x, 4),
            'y' => round($y, 4),
            'w' => round($w, 4),
            'h' => round($h, 4),
        ];
    }
}
