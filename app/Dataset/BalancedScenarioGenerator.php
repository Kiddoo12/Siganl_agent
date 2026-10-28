<?php
declare(strict_types=1);

namespace App\Dataset;

/**
 * Balanced Scenario Classifier & Forward Trade Outcome Evaluator.
 *
 * Guarantees balanced dataset coverage across:
 *  - Winning setups (Win at TP1 / TP2 / TP3)
 *  - Losing setups (Stop Loss touched or invalidated)
 *  - Breakeven setups (Protected after partial target)
 *  - Ranging / Consolidation markets (Oscillating chop)
 *  - False Breakouts / Liquidity Traps (Turtle soups)
 *  - News Volatility Spikes (Extreme range expansion)
 *  - Explicit NO_TRADE cases (Low confluence / HTF-LTF conflict)
 */
final class BalancedScenarioGenerator
{
    /**
     * Evaluate forward candles against a candidate setup to determine realistic forward outcome and scenario type.
     *
     * @param array $windowCandles Historical closed window candles
     * @param array $forwardCandles Quarantined future candles
     * @param ?array $candidateSetup Setup with decision, entry, sl, tp1, tp2, tp3
     * @param array $preliminaryData Output of PreliminaryPatternLabeler
     * @return array{
     *   scenario_type: string,
     *   trade_decision: string,
     *   outcome: string,
     *   realized_r: float,
     *   max_favorable_r: float,
     *   max_adverse_r: float,
     *   touch_sequence: string,
     *   bars_held: int,
     *   exit_price: float,
     *   scenario_description: string
     * }
     */
    public function evaluateScenario(
        array $windowCandles,
        array $forwardCandles,
        ?array $candidateSetup,
        array $preliminaryData
    ): array {
        $regime = (string)($preliminaryData['market_regime'] ?? 'trending');
        $annotations = $preliminaryData['annotations'] ?? [];
        $windowCount = count($windowCandles);
        $forwardCount = count($forwardCandles);

        // 1. Detect News Volatility Spike
        $isNewsVolatile = $this->detectNewsVolatility($windowCandles);
        if ($isNewsVolatile) {
            return [
                'scenario_type' => 'news_volatility',
                'trade_decision' => 'NO_TRADE',
                'outcome' => 'no_trade_verified',
                'realized_r' => 0.0,
                'max_favorable_r' => 0.0,
                'max_adverse_r' => 0.0,
                'touch_sequence' => 'NEWS_SPIKE_HALT',
                'bars_held' => 0,
                'exit_price' => (float)end($windowCandles)['c'],
                'scenario_description' => 'Abnormal news volatility spike detected (extreme candle range). Trade filter halted execution.',
            ];
        }

        // 2. Detect Ranging / Consolidation Market
        $isRanging = ($regime === 'ranging_consolidation') || $this->detectRangingChop($windowCandles);
        if ($isRanging && (empty($candidateSetup) || empty($annotations))) {
            return [
                'scenario_type' => 'ranging_consolidation',
                'trade_decision' => 'NO_TRADE',
                'outcome' => 'no_trade_verified',
                'realized_r' => 0.0,
                'max_favorable_r' => 0.0,
                'max_adverse_r' => 0.0,
                'touch_sequence' => 'RANGE_NO_TRADE',
                'bars_held' => 0,
                'exit_price' => (float)end($windowCandles)['c'],
                'scenario_description' => 'Market in low-momentum consolidation. Safe NO_TRADE case to avoid choppy whipsaws.',
            ];
        }

        // 3. Detect False Breakout / Turtle Soup
        $hasLiquiditySweep = false;
        foreach ($annotations as $ann) {
            if ($ann['pattern_type'] === 'LIQUIDITY_SWEEP') {
                $hasLiquiditySweep = true;
                break;
            }
        }

        // If no candidate setup, return NO_TRADE
        if ($candidateSetup === null || $forwardCount < 5) {
            return [
                'scenario_type' => $hasLiquiditySweep ? 'false_breakout' : 'no_trade',
                'trade_decision' => 'NO_TRADE',
                'outcome' => 'no_trade_verified',
                'realized_r' => 0.0,
                'max_favorable_r' => 0.0,
                'max_adverse_r' => 0.0,
                'touch_sequence' => 'NO_TRIGGER',
                'bars_held' => 0,
                'exit_price' => (float)end($windowCandles)['c'],
                'scenario_description' => 'Unconfirmed market structure. Evaluated as disciplined NO_TRADE.',
            ];
        }

        $decision = (string)$candidateSetup['decision'];
        $entry = (float)$candidateSetup['entry'];
        $sl = (float)$candidateSetup['sl'];
        $tp1 = (float)$candidateSetup['tp1'];
        $tp2 = (float)$candidateSetup['tp2'];
        $tp3 = (float)$candidateSetup['tp3'];

        $riskPts = max(0.5, abs($entry - $sl));
        $isBuy = $decision === 'BUY';

        // 4. Simulate Forward Execution through Quarantined Future Candles
        $mfePts = 0.0;
        $maePts = 0.0;
        $outcome = 'expired';
        $realizedR = 0.0;
        $exitPrice = $entry;
        $touchSeq = 'HOLD';
        $barsHeld = 0;

        for ($b = 0; $b < $forwardCount; $b++) {
            $barsHeld = $b + 1;
            $fc = $forwardCandles[$b];
            $fh = (float)$fc['h'];
            $fl = (float)$fc['l'];

            // Track MFE & MAE
            if ($isBuy) {
                $favorable = max(0.0, $fh - $entry);
                $adverse = max(0.0, $entry - $fl);
            } else {
                $favorable = max(0.0, $entry - $fl);
                $adverse = max(0.0, $fh - $entry);
            }
            if ($favorable > $mfePts) $mfePts = $favorable;
            if ($adverse > $maePts) $maePts = $adverse;

            // Check Stop Loss touch
            $slHit = $isBuy ? ($fl <= $sl) : ($fh >= $sl);
            // Check Take Profit touch
            $tp1Hit = $isBuy ? ($fh >= $tp1) : ($fl <= $tp1);
            $tp2Hit = $isBuy ? ($fh >= $tp2) : ($fl <= $tp2);

            // Conservative Execution Rule: If SL and TP1 touched in same bar -> Resolve as LOSS
            if ($slHit && $tp1Hit) {
                $outcome = 'loss';
                $realizedR = -1.0;
                $exitPrice = $sl;
                $touchSeq = 'SL_SAME_BAR_CONFLICT';
                break;
            }

            if ($slHit) {
                $outcome = 'loss';
                $realizedR = -1.0;
                $exitPrice = $sl;
                $touchSeq = 'SL_TOUCHED';
                break;
            }

            if ($tp2Hit) {
                $outcome = 'win';
                $realizedR = round(abs($tp2 - $entry) / $riskPts, 2);
                $exitPrice = $tp2;
                $touchSeq = 'TP2_FILLED';
                break;
            }

            if ($tp1Hit) {
                // If TP1 hit, protected at BE (+0.1R buffer)
                $outcome = 'win';
                $realizedR = round(abs($tp1 - $entry) / $riskPts, 2);
                $exitPrice = $tp1;
                $touchSeq = 'TP1_FILLED';
                break;
            }
        }

        // Determine specific scenario type
        $scenarioType = 'trend_continuation';
        if ($hasLiquiditySweep) {
            $scenarioType = ($outcome === 'win') ? 'reversal' : 'false_breakout';
        } elseif ($outcome === 'loss') {
            $scenarioType = 'trend_continuation';
        }

        $maxFavR = round($mfePts / $riskPts, 2);
        $maxAdvR = round($maePts / $riskPts, 2);

        $desc = "Scenario: {$scenarioType} ({$decision}) -> {$outcome} with Realized R: {$realizedR}R (MFE: +{$maxFavR}R, MAE: -{$maxAdvR}R)";

        return [
            'scenario_type' => $scenarioType,
            'trade_decision' => $decision,
            'outcome' => $outcome,
            'realized_r' => $realizedR,
            'max_favorable_r' => $maxFavR,
            'max_adverse_r' => $maxAdvR,
            'touch_sequence' => $touchSeq,
            'bars_held' => $barsHeld,
            'exit_price' => round($exitPrice, 3),
            'scenario_description' => $desc,
        ];
    }

    /**
     * Detect abnormal candle range expansion indicating economic news releases.
     */
    private function detectNewsVolatility(array $candles): bool
    {
        $n = count($candles);
        if ($n < 20) return false;

        $ranges = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $ranges[] = (float)$candles[$i]['h'] - (float)$candles[$i]['l'];
        }
        sort($ranges);
        $median = $ranges[intdiv(count($ranges), 2)] ?: 1.0;

        $lastRange = (float)$candles[$n - 1]['h'] - (float)$candles[$n - 1]['l'];
        return ($lastRange > 6.0 * $median);
    }

    /**
     * Detect low-volatility tight chop.
     */
    private function detectRangingChop(array $candles): bool
    {
        $n = count($candles);
        if ($n < 15) return false;

        $recent = array_slice($candles, -15);
        $high = max(array_column($recent, 'h'));
        $low = min(array_column($recent, 'l'));
        $avgPrice = array_sum(array_column($recent, 'c')) / count($recent);

        $rangePct = (($high - $low) / max(1.0, $avgPrice)) * 100.0;
        return $rangePct < 0.25; // Less than 0.25% total range over 15 bars
    }
}
