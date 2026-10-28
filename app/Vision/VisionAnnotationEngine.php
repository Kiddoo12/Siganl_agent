<?php
declare(strict_types=1);

namespace App\Vision;

/**
 * Standardized Chart Pattern Annotation & Spatial Matching Engine.
 *
 * Implements:
 *  - Normalized coordinate bounding boxes [x, y, w, h] (0.0000 - 1.0000).
 *  - Intersection-over-Union (IoU) spatial matching between AI detections and Ground Truth.
 *  - Price-level calibration and localization error calculation.
 *  - Pattern category matching for BOS, CHoCH, MSS, FVGs, Order Blocks, Liquidity, etc.
 */
final class VisionAnnotationEngine
{
    public const PATTERN_TYPES = [
        'BOS'             => 'Break of Structure (BOS)',
        'CHOCH'           => 'Change of Character (CHoCH)',
        'MSS'             => 'Market Structure Shift (MSS)',
        'FVG_BULL'        => 'Bullish Fair Value Gap (FVG)',
        'FVG_BEAR'        => 'Bearish Fair Value Gap (FVG)',
        'IFVG'            => 'Inverse Fair Value Gap (IFVG)',
        'OB_BULL'         => 'Bullish Order Block (OB)',
        'OB_BEAR'         => 'Bearish Order Block (OB)',
        'BREAKER'         => 'Breaker Block',
        'MITIGATION'      => 'Mitigation Block',
        'LIQUIDITY_SWEEP' => 'Liquidity Sweep / Stop Hunt',
        'BSL'             => 'Buy-Side Liquidity (BSL)',
        'SSL'             => 'Sell-Side Liquidity (SSL)',
        'EQH'             => 'Equal Highs (EQH)',
        'EQL'             => 'Equal Lows (EQL)',
        'DISPLACEMENT'    => 'Displacement Candle',
        'OTE'             => 'Optimal Trade Entry (OTE 62%-79%)',
        'ENTRY'           => 'Execution Entry Zone',
        'SL'              => 'Stop Loss (SL)',
        'TP'              => 'Take Profit (TP)',
    ];

    /**
     * Compute visual Intersection-over-Union (IoU) between two normalized bounding boxes.
     *
     * @param array{x: float, y: float, w: float, h: float} $boxA
     * @param array{x: float, y: float, w: float, h: float} $boxB
     * @return float IoU between 0.0000 and 1.0000
     */
    public static function computeIou(array $boxA, array $boxB): float
    {
        $ax1 = (float)$boxA['x'];
        $ay1 = (float)$boxA['y'];
        $ax2 = $ax1 + max(0.0001, (float)$boxA['w']);
        $ay2 = $ay1 + max(0.0001, (float)$boxA['h']);

        $bx1 = (float)$boxB['x'];
        $by1 = (float)$boxB['y'];
        $bx2 = $bx1 + max(0.0001, (float)$boxB['w']);
        $by2 = $by1 + max(0.0001, (float)$boxB['h']);

        $interX1 = max($ax1, $bx1);
        $interY1 = max($ay1, $by1);
        $interX2 = min($ax2, $bx2);
        $interY2 = min($ay2, $by2);

        $interW = max(0.0, $interX2 - $interX1);
        $interH = max(0.0, $interY2 - $interY1);
        $interArea = $interW * $interH;

        $areaA = ($ax2 - $ax1) * ($ay2 - $ay1);
        $areaB = ($bx2 - $bx1) * ($by2 - $by1);
        $unionArea = $areaA + $areaB - $interArea;

        if ($unionArea <= 0.00001) {
            return 0.0;
        }

        return max(0.0, min(1.0, $interArea / $unionArea));
    }

    /**
     * Match predicted annotations against expert ground-truth annotations.
     *
     * @param array<array> $predictions AI detected annotations
     * @param array<array> $groundTruth Expert-labeled annotations
     * @param float $iouThreshold Minimum IoU overlap to consider a spatial match (default 0.35)
     * @return array{
     *   matches: array<array>,
     *   false_positives: array<array>,
     *   false_negatives: array<array>,
     *   price_mae: float,
     *   precision: float,
     *   recall: float,
     *   f1: float
     * }
     */
    public function matchAnnotations(array $predictions, array $groundTruth, float $iouThreshold = 0.35): array
    {
        $matches = [];
        $falsePositives = [];
        $matchedGtIndices = [];
        $priceErrors = [];

        foreach ($predictions as $p) {
            $pType = strtoupper(trim((string)($p['pattern_type'] ?? '')));
            $pBox = [
                'x' => (float)($p['box_x'] ?? 0.0),
                'y' => (float)($p['box_y'] ?? 0.0),
                'w' => (float)($p['box_w'] ?? 0.0),
                'h' => (float)($p['box_h'] ?? 0.0),
            ];
            $pPrice = isset($p['price_level']) ? (float)$p['price_level'] : (isset($p['price_top']) ? ((float)$p['price_top'] + (float)$p['price_bottom']) / 2.0 : null);

            $bestIou = 0.0;
            $bestGtIdx = -1;

            foreach ($groundTruth as $idx => $gt) {
                if (in_array($idx, $matchedGtIndices, true)) {
                    continue;
                }
                $gtType = strtoupper(trim((string)($gt['pattern_type'] ?? '')));
                // Type match or compatible family (e.g. FVG_BULL and FVG)
                if ($pType !== $gtType && !str_starts_with($gtType, $pType) && !str_starts_with($pType, $gtType)) {
                    continue;
                }

                $gtBox = [
                    'x' => (float)($gt['box_x'] ?? 0.0),
                    'y' => (float)($gt['box_y'] ?? 0.0),
                    'w' => (float)($gt['box_w'] ?? 0.0),
                    'h' => (float)($gt['box_h'] ?? 0.0),
                ];
                $iou = self::computeIou($pBox, $gtBox);
                if ($iou > $bestIou) {
                    $bestIou = $iou;
                    $bestGtIdx = $idx;
                }
            }

            if ($bestIou >= $iouThreshold && $bestGtIdx >= 0) {
                $matchedGt = $groundTruth[$bestGtIdx];
                $matchedGtIndices[] = $bestGtIdx;

                $gtPrice = isset($matchedGt['price_level']) ? (float)$matchedGt['price_level'] : (isset($matchedGt['price_top']) ? ((float)$matchedGt['price_top'] + (float)$matchedGt['price_bottom']) / 2.0 : null);
                if ($pPrice !== null && $gtPrice !== null) {
                    $priceErrors[] = abs($pPrice - $gtPrice);
                }

                $matches[] = [
                    'predicted'    => $p,
                    'ground_truth' => $matchedGt,
                    'iou'          => round($bestIou, 4),
                    'pattern_type' => $pType,
                ];
            } else {
                $falsePositives[] = $p;
            }
        }

        // Remaining unmatched ground truth are false negatives (missed detections)
        $falseNegatives = [];
        foreach ($groundTruth as $idx => $gt) {
            if (!in_array($idx, $matchedGtIndices, true)) {
                $falseNegatives[] = $gt;
            }
        }

        $tp = count($matches);
        $fp = count($falsePositives);
        $fn = count($falseNegatives);

        $precision = ($tp + $fp) > 0 ? ($tp / ($tp + $fp)) * 100.0 : 100.0;
        $recall = ($tp + $fn) > 0 ? ($tp / ($tp + $fn)) * 100.0 : 0.0;
        $f1 = ($precision + $recall) > 0 ? (2 * ($precision * $recall)) / ($precision + $recall) : 0.0;
        $priceMae = !empty($priceErrors) ? array_sum($priceErrors) / count($priceErrors) : 0.0;

        return [
            'matches'         => $matches,
            'false_positives' => $falsePositives,
            'false_negatives' => $falseNegatives,
            'tp'              => $tp,
            'fp'              => $fp,
            'fn'              => $fn,
            'price_mae'       => round($priceMae, 3),
            'precision'       => round($precision, 2),
            'recall'          => round($recall, 2),
            'f1'              => round($f1, 2),
        ];
    }
}
