<?php
declare(strict_types=1);

namespace App\Dataset;

use App\Vision\VisionAnnotationEngine;

/**
 * Decoupled Evaluation & Benchmark Pipeline.
 *
 * Strictly separates:
 *  1. Visual Pattern Recognition Accuracy (Spatial IoU, Precision, Recall, F1, Price MAE)
 *  2. Trade Execution Profitability (Win Rate, Realized R Expectancy, Profit Factor, Brier Score)
 *
 * Prevents conflating visual detection success with actual trading profitability.
 */
final class DecoupledEvaluator
{
    private VisionAnnotationEngine $annotationEngine;

    public function __construct(?VisionAnnotationEngine $annotationEngine = null)
    {
        $this->annotationEngine = $annotationEngine ?? new VisionAnnotationEngine();
    }

    /**
     * Run decoupled evaluation over a dataset of verified examples.
     *
     * @param array<array> $examples Dataset examples containing predictions, ground truth, and forward outcomes
     * @param float $iouThreshold Minimum IoU for spatial match (default 0.35)
     * @return array{
     *   visual_metrics: array,
     *   trade_metrics: array,
     *   decoupled_summary: string
     * }
     */
    public function evaluate(array $examples, float $iouThreshold = 0.35): array
    {
        $totalPredictions = 0;
        $totalGroundTruth = 0;
        $totalTruePositives = 0;
        $totalFalsePositives = 0;
        $totalFalseNegatives = 0;
        $sumIoU = 0.0;
        $sumPriceMae = 0.0;
        $priceMaeCount = 0;

        $patternStats = [];

        // Trade outcome accumulators
        $tradesCount = 0;
        $wins = 0;
        $losses = 0;
        $breakevens = 0;
        $noTrades = 0;
        $grossWinR = 0.0;
        $grossLossR = 0.0;
        $realizedRList = [];
        $brierSum = 0.0;
        $brierCount = 0;

        foreach ($examples as $ex) {
            $preds = $ex['predictions'] ?? [];
            $gt = $ex['ground_truth'] ?? [];

            $totalPredictions += count($preds);
            $totalGroundTruth += count($gt);

            // Match predictions against ground truth
            $matchResult = $this->annotationEngine->matchAnnotations($preds, $gt, $iouThreshold);

            $tp = count($matchResult['matches']);
            $fp = count($matchResult['false_positives']);
            $fn = count($matchResult['false_negatives']);

            $totalTruePositives += $tp;
            $totalFalsePositives += $fp;
            $totalFalseNegatives += $fn;

            foreach ($matchResult['matches'] as $m) {
                $sumIoU += (float)$m['iou'];
                $pPrice = isset($m['predicted']['price_level']) ? (float)$m['predicted']['price_level'] : 0.0;
                $gtPrice = isset($m['ground_truth']['price_level']) ? (float)$m['ground_truth']['price_level'] : 0.0;
                $sumPriceMae += abs($pPrice - $gtPrice);
                $priceMaeCount++;

                $pType = (string)$m['pattern_type'];
                if (!isset($patternStats[$pType])) {
                    $patternStats[$pType] = ['tp' => 0, 'fp' => 0, 'fn' => 0];
                }
                $patternStats[$pType]['tp']++;
            }

            foreach ($matchResult['false_positives'] as $fpItem) {
                $pType = (string)$fpItem['pattern_type'];
                if (!isset($patternStats[$pType])) {
                    $patternStats[$pType] = ['tp' => 0, 'fp' => 0, 'fn' => 0];
                }
                $patternStats[$pType]['fp']++;
            }

            foreach ($matchResult['false_negatives'] as $fnItem) {
                $pType = (string)$fnItem['pattern_type'];
                if (!isset($patternStats[$pType])) {
                    $patternStats[$pType] = ['tp' => 0, 'fp' => 0, 'fn' => 0];
                }
                $patternStats[$pType]['fn']++;
            }

            // Trade outcome metrics
            $decision = (string)($ex['trade_decision'] ?? 'WAIT');
            $outcome = (string)($ex['outcome'] ?? 'pending');
            $realizedR = (float)($ex['realized_r'] ?? 0.0);

            if ($decision === 'NO_TRADE' || $outcome === 'no_trade_verified') {
                $noTrades++;
            } elseif (in_array($outcome, ['win', 'loss', 'breakeven'], true)) {
                $tradesCount++;
                $realizedRList[] = $realizedR;

                if ($outcome === 'win') {
                    $wins++;
                    $grossWinR += max(0.0, $realizedR);
                } elseif ($outcome === 'loss') {
                    $losses++;
                    $grossLossR += abs(min(0.0, $realizedR));
                } else {
                    $breakevens++;
                }

                // Calibration / Brier score if predicted probability available
                if (isset($ex['predicted_prob'])) {
                    $prob = (float)$ex['predicted_prob'];
                    $actualY = ($outcome === 'win') ? 1.0 : 0.0;
                    $brierSum += ($prob - $actualY) ** 2;
                    $brierCount++;
                }
            }
        }

        // 1. Visual Metrics Calculation
        $precision = ($totalTruePositives + $totalFalsePositives) > 0
            ? $totalTruePositives / ($totalTruePositives + $totalFalsePositives)
            : 0.0;

        $recall = ($totalTruePositives + $totalFalseNegatives) > 0
            ? $totalTruePositives / ($totalTruePositives + $totalFalseNegatives)
            : 0.0;

        $f1 = ($precision + $recall) > 0.0001
            ? 2.0 * (($precision * $recall) / ($precision + $recall))
            : 0.0;

        $meanIou = $totalTruePositives > 0 ? $sumIoU / $totalTruePositives : 0.0;
        $priceMae = $priceMaeCount > 0 ? $sumPriceMae / $priceMaeCount : 0.0;

        // Breakdown per pattern
        $patternBreakdown = [];
        foreach ($patternStats as $pType => $counts) {
            $pPrec = ($counts['tp'] + $counts['fp']) > 0 ? $counts['tp'] / ($counts['tp'] + $counts['fp']) : 0.0;
            $pRec = ($counts['tp'] + $counts['fn']) > 0 ? $counts['tp'] / ($counts['tp'] + $counts['fn']) : 0.0;
            $pF1 = ($pPrec + $pRec) > 0 ? 2.0 * (($pPrec * $pRec) / ($pPrec + $pRec)) : 0.0;
            $patternBreakdown[$pType] = [
                'tp' => $counts['tp'],
                'fp' => $counts['fp'],
                'fn' => $counts['fn'],
                'precision' => round($pPrec * 100.0, 1),
                'recall' => round($pRec * 100.0, 1),
                'f1' => round($pF1 * 100.0, 1),
            ];
        }

        // 2. Trade Profitability Metrics Calculation
        $winRate = $tradesCount > 0 ? ($wins / $tradesCount) * 100.0 : 0.0;
        $profitFactor = $grossLossR > 0.0001 ? round($grossWinR / $grossLossR, 2) : ($grossWinR > 0 ? 99.9 : 0.0);
        $netR = $grossWinR - $grossLossR;
        $expectancyR = $tradesCount > 0 ? round($netR / $tradesCount, 3) : 0.0;
        $brierScore = $brierCount > 0 ? round($brierSum / $brierCount, 4) : 0.0;

        $visualReport = [
            'total_predictions' => $totalPredictions,
            'total_ground_truth' => $totalGroundTruth,
            'true_positives' => $totalTruePositives,
            'false_positives' => $totalFalsePositives,
            'false_negatives' => $totalFalseNegatives,
            'precision_pct' => round($precision * 100.0, 1),
            'recall_pct' => round($recall * 100.0, 1),
            'f1_pct' => round($f1 * 100.0, 1),
            'mean_iou' => round($meanIou, 3),
            'price_mae_pts' => round($priceMae, 3),
            'pattern_breakdown' => $patternBreakdown,
        ];

        $tradeReport = [
            'total_evaluated_trades' => $tradesCount,
            'no_trade_cases' => $noTrades,
            'wins' => $wins,
            'losses' => $losses,
            'breakevens' => $breakevens,
            'win_rate_pct' => round($winRate, 1),
            'profit_factor' => $profitFactor,
            'net_realized_r' => round($netR, 2),
            'expectancy_r_per_trade' => $expectancyR,
            'brier_calibration_score' => $brierScore,
        ];

        $summary = "Decoupled Scorecard: Visual F1: {$visualReport['f1_pct']}% (MAE: {$visualReport['price_mae_pts']} pts) | Trade Win Rate: {$tradeReport['win_rate_pct']}% (PF: {$tradeReport['profit_factor']}, Exp: {$tradeReport['expectancy_r_per_trade']}R)";

        return [
            'visual_metrics' => $visualReport,
            'trade_metrics' => $tradeReport,
            'decoupled_summary' => $summary,
        ];
    }
}
