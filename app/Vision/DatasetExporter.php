<?php
declare(strict_types=1);

namespace App\Vision;

use App\Core\Database;

/**
 * Section 3 & 4 — Standardized Dataset Exporter (JSONL & COCO formats).
 *
 * Exports verified and expert-corrected chart examples for vision fine-tuning,
 * computer vision object detection, and reproducible model evaluation.
 */
final class DatasetExporter
{
    /**
     * Export reviewed examples in Vision-Language JSONL format.
     * Each line is a self-contained training sample: {id, image, conversation: [{role, content}]}.
     */
    public function exportJsonl(?string $datasetVersion = null): string
    {
        $sql = "SELECT e.* FROM vision_training_examples e WHERE e.review_status IN ('verified', 'expert_corrected')";
        $params = [];
        if ($datasetVersion !== null && $datasetVersion !== 'all') {
            $sql .= " AND e.dataset_version = ?";
            $params[] = $datasetVersion;
        }
        $sql .= " ORDER BY e.id ASC";

        $rows = Database::fetchAll($sql, $params);
        $lines = [];

        foreach ($rows as $row) {
            $exId = (int)$row['id'];
            $annotations = Database::fetchAll(
                "SELECT pattern_type, direction, box_x, box_y, box_w, box_h, price_top, price_bottom, price_level
                 FROM vision_annotations
                 WHERE example_id = ? AND source = 'expert_ground_truth'",
                [$exId]
            );

            $sample = [
                'id'              => $row['example_uuid'],
                'image_path'      => $row['image_path'],
                'instrument'      => $row['symbol'],
                'timeframe'       => $row['timeframe'],
                'timestamp'       => $row['chart_timestamp'],
                'market_regime'   => $row['market_regime'],
                'trend_direction' => $row['trend_direction'],
                'annotations'     => array_map(static fn($a) => [
                    'pattern'  => $a['pattern_type'],
                    'dir'      => $a['direction'],
                    'bbox'     => [(float)$a['box_x'], (float)$a['box_y'], (float)$a['box_w'], (float)$a['box_h']],
                    'price'    => $a['price_level'] ? (float)$a['price_level'] : null,
                    'price_top'=> $a['price_top'] ? (float)$a['price_top'] : null,
                    'price_bot'=> $a['price_bottom'] ? (float)$a['price_bottom'] : null,
                ], $annotations),
                'ground_truth_decision' => [
                    'direction'  => $row['trade_decision'],
                    'entry'      => $row['entry_price'] ? (float)$row['entry_price'] : null,
                    'sl'         => $row['stop_loss'] ? (float)$row['stop_loss'] : null,
                    'tp1'        => $row['tp1'] ? (float)$row['tp1'] : null,
                    'tp2'        => $row['tp2'] ? (float)$row['tp2'] : null,
                    'tp3'        => $row['tp3'] ? (float)$row['tp3'] : null,
                    'rr'         => $row['risk_reward'] ? (float)$row['risk_reward'] : null,
                ],
                'verified_outcome' => [
                    'outcome'    => $row['outcome'],
                    'realized_r' => (float)($row['realized_r'] ?? 0.0),
                    'mfe_r'      => (float)($row['max_favorable_r'] ?? 0.0),
                    'mae_r'      => (float)($row['max_adverse_r'] ?? 0.0),
                ],
                'dataset_split'   => $row['dataset_split'],
                'dataset_version' => $row['dataset_version'],
            ];

            $lines[] = json_encode($sample, JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    /**
     * Export bounding box annotations in COCO Object Detection format.
     */
    public function exportCoco(?string $datasetVersion = null): array
    {
        $sql = "SELECT e.* FROM vision_training_examples e WHERE e.review_status IN ('verified', 'expert_corrected')";
        $params = [];
        if ($datasetVersion !== null && $datasetVersion !== 'all') {
            $sql .= " AND e.dataset_version = ?";
            $params[] = $datasetVersion;
        }
        $rows = Database::fetchAll($sql, $params);

        $categories = [];
        $catIndex = 1;
        foreach (VisionAnnotationEngine::PATTERN_TYPES as $code => $name) {
            $categories[] = ['id' => $catIndex++, 'name' => $code, 'supercategory' => 'smc_pattern'];
        }
        $catMap = array_column($categories, 'id', 'name');

        $images = [];
        $cocoAnns = [];
        $annId = 1;

        foreach ($rows as $row) {
            $exId = (int)$row['id'];
            $images[] = [
                'id'        => $exId,
                'file_name' => basename($row['image_path']),
                'width'     => 1280,
                'height'    => 720,
                'symbol'    => $row['symbol'],
                'timeframe' => $row['timeframe'],
            ];

            $annotations = Database::fetchAll(
                "SELECT * FROM vision_annotations WHERE example_id = ? AND source = 'expert_ground_truth'",
                [$exId]
            );

            foreach ($annotations as $a) {
                $pType = (string)$a['pattern_type'];
                $catId = $catMap[$pType] ?? 1;

                // Convert normalized 0-1 to 1280x720 pixels
                $px = (float)$a['box_x'] * 1280.0;
                $py = (float)$a['box_y'] * 720.0;
                $pw = (float)$a['box_w'] * 1280.0;
                $ph = (float)$a['box_h'] * 720.0;

                $cocoAnns[] = [
                    'id'          => $annId++,
                    'image_id'    => $exId,
                    'category_id' => $catId,
                    'bbox'        => [round($px, 1), round($py, 1), round($pw, 1), round($ph, 1)],
                    'area'        => round($pw * $ph, 1),
                    'iscrowd'     => 0,
                ];
            }
        }

        return [
            'info'        => ['description' => 'GOLD SMC AI PRO Vision Training Dataset', 'version' => '2.0', 'year' => 2026],
            'categories'  => $categories,
            'images'      => $images,
            'annotations' => $cocoAnns,
        ];
    }
}
