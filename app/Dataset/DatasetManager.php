<?php
declare(strict_types=1);

namespace App\Dataset;

use App\Core\Database;
use App\Market\Timeframe;
use App\Vision\VisionRagService;
use PDO;

/**
 * Historical Chart Dataset Manager & Coordinator.
 *
 * Coordinates:
 *  - Automated dataset generation from imported historical candles.
 *  - Look-ahead safe multi-timeframe sliding windows.
 *  - Preliminary pattern candidate labeling.
 *  - Balanced scenario classification (Wins, Losses, BE, Ranges, Traps, News, NO_TRADE).
 *  - Standalone SVG chart image rendering and persistent structured storage.
 *  - Human-in-the-loop review, correction, and approval workflow.
 *  - Dataset versioning with purged chronological splitting.
 */
final class DatasetManager
{
    private MultiTimeframeResampler $resampler;
    private ChartWindowGenerator $windowGenerator;
    private PreliminaryPatternLabeler $patternLabeler;
    private BalancedScenarioGenerator $scenarioGenerator;
    private ChartImageGenerator $imageGenerator;
    private PurgedChronologicalSplitter $splitter;

    public function __construct(
        ?MultiTimeframeResampler $resampler = null,
        ?ChartWindowGenerator $windowGenerator = null,
        ?PreliminaryPatternLabeler $patternLabeler = null,
        ?BalancedScenarioGenerator $scenarioGenerator = null,
        ?ChartImageGenerator $imageGenerator = null,
        ?PurgedChronologicalSplitter $splitter = null
    ) {
        $this->resampler = $resampler ?? new MultiTimeframeResampler();
        $this->windowGenerator = $windowGenerator ?? new ChartWindowGenerator();
        $this->patternLabeler = $patternLabeler ?? new PreliminaryPatternLabeler();
        $this->scenarioGenerator = $scenarioGenerator ?? new BalancedScenarioGenerator();
        $this->imageGenerator = $imageGenerator ?? new ChartImageGenerator();
        $this->splitter = $splitter ?? new PurgedChronologicalSplitter();
    }

    /**
     * Build training examples from a set of raw historical candles.
     *
     * @param array $baseCandles Chronologically sorted granular candles (e.g. M1 or M5)
     * @param string $symbol Instrument symbol (default 'XAUUSD')
     * @param string $execTf Primary execution timeframe (default 'M15')
     * @param array $options Options (window_bars, forward_bars, step_bars, max_examples)
     * @return array{
     *   created_examples: int,
     *   created_annotations: int,
     *   scenarios_breakdown: array,
     *   example_uuids: array<string>
     * }
     */
    public function generateDatasetFromCandles(
        array $baseCandles,
        string $symbol = 'XAUUSD',
        string $execTf = 'M15',
        array $options = []
    ): array {
        $windowBars = (int)($options['window_bars'] ?? 60);
        $forwardBars = (int)($options['forward_bars'] ?? 40);
        $stepBars = (int)($options['step_bars'] ?? 16);
        $maxExamples = (int)($options['max_examples'] ?? 150);

        // 1. Resample base candles to all required timeframes
        $mtf = $this->resampler->resampleAll($baseCandles, 'M1', ['M5', 'M15', 'M30', 'H1', 'H4', 'D1']);
        if (!isset($mtf[$execTf]) && isset($baseCandles[0])) {
            $mtf[$execTf] = $baseCandles;
        }

        // 2. Generate sliding chart windows
        $windows = $this->windowGenerator->generateWindows($mtf, $execTf, $windowBars, $forwardBars, $stepBars);
        if (empty($windows)) {
            return [
                'created_examples' => 0,
                'created_annotations' => 0,
                'scenarios_breakdown' => [],
                'example_uuids' => [],
            ];
        }

        $pdo = Database::pdo();
        $createdExamples = 0;
        $createdAnnotations = 0;
        $scenarioCounts = [];
        $createdUuids = [];

        foreach ($windows as $idx => $win) {
            if ($createdExamples >= $maxExamples) {
                break;
            }

            $execCandles = $win['exec_window'];
            $forwardCandles = $win['forward_candles'];
            $decTime = $win['decision_time'];
            $chartTimestamp = gmdate('Y-m-d H:i:s', $decTime);

            // 3. Preliminary Pattern Labeling
            $prelim = $this->patternLabeler->labelWindow($execCandles, $win['htf_windows']);

            // 4. Balanced Scenario & Forward Outcome Evaluation
            $scen = $this->scenarioGenerator->evaluateScenario($execCandles, $forwardCandles, $prelim['candidate_setup'], $prelim);

            $scenType = $scen['scenario_type'];
            $scenarioCounts[$scenType] = ($scenarioCounts[$scenType] ?? 0) + 1;

            $uuid = 'xau_ds_' . gmdate('Ymd_His', $decTime) . '_' . bin2hex(random_bytes(3));
            $createdUuids[] = $uuid;

            // 5. Render Standalone SVG Chart Image
            $svgPath = $this->imageGenerator->renderAndSave(
                $uuid,
                $symbol,
                $execTf,
                $execCandles,
                $prelim['annotations']
            );

            // 6. Persist Example into Database
            $sqlEx = "INSERT INTO vision_training_examples 
                (example_uuid, user_id, symbol, timeframe, image_path, svg_chart_path, chart_timestamp, 
                 broker, chart_tz, ref_price, review_status, dataset_split, dataset_version, market_regime, 
                 scenario_type, trend_direction, market_structure, key_swing_high, key_swing_low, 
                 fvg_summary, order_block_summary, premium_discount, trade_decision, entry_price, 
                 stop_loss, tp1, tp2, tp3, risk_reward, ai_raw_prediction, expert_notes, structured_candles,
                 outcome, realized_r, max_favorable_r, max_adverse_r, created_at, updated_at)
                VALUES 
                (:uuid, 1, :symbol, :timeframe, :image_path, :svg_path, :chart_ts,
                 'Institutional-Historical', 'UTC', :ref_price, 'unreviewed', 'train', 'v1.0-historical', :market_regime,
                 :scenario_type, :trend_dir, :structure, :swing_h, :swing_l,
                 :fvg_sum, :ob_sum, :prem_disc, :decision, :entry,
                 :sl, :tp1, :tp2, :tp3, :rr, :ai_pred, :notes, :structured_candles,
                 :outcome, :realized_r, :mfe, :mae, NOW(), NOW())";

            $candSetup = $prelim['candidate_setup'];
            $fvgCount = count(array_filter($prelim['annotations'], static fn($a) => str_contains($a['pattern_type'], 'FVG')));
            $obCount = count(array_filter($prelim['annotations'], static fn($a) => str_contains($a['pattern_type'], 'OB')));

            $stmtEx = $pdo->prepare($sqlEx);
            $stmtEx->execute([
                ':uuid' => $uuid,
                ':symbol' => $symbol,
                ':timeframe' => $execTf,
                ':image_path' => $svgPath,
                ':svg_path' => $svgPath,
                ':chart_ts' => $chartTimestamp,
                ':ref_price' => $win['ref_price'],
                ':market_regime' => $prelim['market_regime'],
                ':scenario_type' => $scenType,
                ':trend_dir' => $prelim['trend_direction'],
                ':structure' => substr($prelim['market_structure'], 0, 64),
                ':swing_h' => $prelim['dealing_range']['high'] ?? null,
                ':swing_l' => $prelim['dealing_range']['low'] ?? null,
                ':fvg_sum' => "{$fvgCount} preliminary FVG(s) detected",
                ':ob_sum' => "{$obCount} preliminary Order Block(s) detected",
                ':prem_disc' => $prelim['dealing_range']['zone'] ?? 'Equilibrium',
                ':decision' => $scen['trade_decision'],
                ':entry' => $candSetup['entry'] ?? null,
                ':sl' => $candSetup['sl'] ?? null,
                ':tp1' => $candSetup['tp1'] ?? null,
                ':tp2' => $candSetup['tp2'] ?? null,
                ':tp3' => $candSetup['tp3'] ?? null,
                ':rr' => $candSetup['rr'] ?? null,
                ':ai_pred' => json_encode($prelim, JSON_THROW_ON_ERROR),
                ':notes' => $scen['scenario_description'],
                ':structured_candles' => json_encode($execCandles, JSON_THROW_ON_ERROR),
                ':outcome' => $scen['outcome'],
                ':realized_r' => $scen['realized_r'],
                ':mfe' => $scen['max_favorable_r'],
                ':mae' => $scen['max_adverse_r'],
            ]);

            $exampleId = (int)$pdo->lastInsertId();
            $createdExamples++;

            // 7. Persist Preliminary Annotations
            $sqlAnn = "INSERT INTO vision_annotations 
                (example_id, source, pattern_type, direction, box_x, box_y, box_w, box_h, 
                 price_top, price_bottom, price_level, is_confirmed, is_preliminary, uncertainty_score, notes, created_at)
                VALUES 
                (:eid, 'ai_predicted', :ptype, :dir, :bx, :by, :bw, :bh,
                 :ptop, :pbot, :plev, 0, 1, :unc, :notes, NOW())";

            $stmtAnn = $pdo->prepare($sqlAnn);
            foreach ($prelim['annotations'] as $ann) {
                $stmtAnn->execute([
                    ':eid' => $exampleId,
                    ':ptype' => $ann['pattern_type'],
                    ':dir' => $ann['direction'],
                    ':bx' => $ann['box_x'],
                    ':by' => $ann['box_y'],
                    ':bw' => $ann['box_w'],
                    ':bh' => $ann['box_h'],
                    ':ptop' => $ann['price_top'],
                    ':pbot' => $ann['price_bottom'],
                    ':plev' => $ann['price_level'],
                    ':unc' => $ann['uncertainty_score'] ?? 30,
                    ':notes' => $ann['notes'] ?? null,
                ]);
                $createdAnnotations++;
            }
        }

        return [
            'created_examples' => $createdExamples,
            'created_annotations' => $createdAnnotations,
            'scenarios_breakdown' => $scenarioCounts,
            'example_uuids' => $createdUuids,
        ];
    }

    /**
     * Retrieve global dataset overview metrics.
     */
    public function getDatasetOverview(): array
    {
        $pdo = Database::pdo();

        // 1. Total counts & review states
        $stmtStatus = $pdo->query("SELECT review_status, COUNT(*) as cnt FROM vision_training_examples GROUP BY review_status");
        $statusCounts = $stmtStatus->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $unreviewed = (int)($statusCounts['unreviewed'] ?? 0);
        $verified = (int)($statusCounts['verified'] ?? 0);
        $corrected = (int)($statusCounts['expert_corrected'] ?? 0);
        $totalExamples = $unreviewed + $verified + $corrected;

        $labelQuality = $totalExamples > 0 ? round((($verified + $corrected) / $totalExamples) * 100.0, 1) : 0.0;

        // 2. Split counts
        $stmtSplit = $pdo->query("SELECT dataset_split, COUNT(*) as cnt FROM vision_training_examples GROUP BY dataset_split");
        $splitCounts = $stmtSplit->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 3. Scenario Breakdown
        $stmtScen = $pdo->query("SELECT scenario_type, COUNT(*) as cnt FROM vision_training_examples GROUP BY scenario_type");
        $scenCounts = $stmtScen->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 4. Pattern Counts
        $stmtPatterns = $pdo->query("SELECT pattern_type, COUNT(*) as cnt FROM vision_annotations GROUP BY pattern_type");
        $patternCounts = $stmtPatterns->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 5. Outcome Breakdown
        $stmtOutcomes = $pdo->query("SELECT outcome, COUNT(*) as cnt FROM vision_training_examples GROUP BY outcome");
        $outcomeCounts = $stmtOutcomes->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 6. Dataset Versions
        $stmtVer = $pdo->query("SELECT * FROM dataset_versions ORDER BY id DESC LIMIT 5");
        $versions = $stmtVer->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_examples' => $totalExamples,
            'unreviewed_count' => $unreviewed,
            'verified_count' => $verified,
            'corrected_count' => $corrected,
            'label_quality_pct' => $labelQuality,
            'splits' => [
                'train' => (int)($splitCounts['train'] ?? 0),
                'val' => (int)($splitCounts['val'] ?? 0),
                'test' => (int)($splitCounts['test'] ?? 0),
            ],
            'scenarios' => $scenCounts,
            'patterns' => $patternCounts,
            'outcomes' => $outcomeCounts,
            'versions' => $versions,
        ];
    }

    /**
     * Human-in-the-loop review action: Approve or Correct an example.
     */
    public function reviewExample(string $exampleUuid, array $updates): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT id, review_status FROM vision_training_examples WHERE example_uuid = :uuid");
        $stmt->execute([':uuid' => $exampleUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'error' => "Example {$exampleUuid} not found"];
        }

        $exampleId = (int)$row['id'];
        $action = (string)($updates['action'] ?? 'verify'); // 'verify' or 'correct'
        $newStatus = ($action === 'verify') ? 'verified' : 'expert_corrected';

        $notes = (string)($updates['notes'] ?? 'Human verified ground-truth approval');

        // Update example status
        $sqlUp = "UPDATE vision_training_examples 
                  SET review_status = :status, expert_notes = :notes, updated_at = NOW() 
                  WHERE id = :id";
        $stmtUp = $pdo->prepare($sqlUp);
        $stmtUp->execute([
            ':status' => $newStatus,
            ':notes' => $notes,
            ':id' => $exampleId,
        ]);

        // If updated annotations are provided by human, update them
        if (isset($updates['annotations']) && is_array($updates['annotations'])) {
            // Delete old preliminary annotations
            $pdo->prepare("DELETE FROM vision_annotations WHERE example_id = :id")->execute([':id' => $exampleId]);

            $stmtInsert = $pdo->prepare(
                "INSERT INTO vision_annotations 
                 (example_id, source, pattern_type, direction, box_x, box_y, box_w, box_h, 
                  price_top, price_bottom, price_level, is_confirmed, is_preliminary, uncertainty_score, notes, created_at)
                 VALUES 
                 (:eid, 'expert_ground_truth', :ptype, :dir, :bx, :by, :bw, :bh,
                  :ptop, :pbot, :plev, 1, 0, 0, :notes, NOW())"
            );

            foreach ($updates['annotations'] as $ann) {
                $stmtInsert->execute([
                    ':eid' => $exampleId,
                    ':ptype' => $ann['pattern_type'],
                    ':dir' => $ann['direction'] ?? 'neutral',
                    ':bx' => $ann['box_x'],
                    ':by' => $ann['box_y'],
                    ':bw' => $ann['box_w'],
                    ':bh' => $ann['box_h'],
                    ':ptop' => $ann['price_top'] ?? null,
                    ':pbot' => $ann['price_bottom'] ?? null,
                    ':plev' => $ann['price_level'] ?? null,
                    ':notes' => $ann['notes'] ?? 'Expert approved ground-truth box',
                ]);
            }
        } else {
            // Mark existing preliminary annotations as verified ground-truth
            $pdo->prepare(
                "UPDATE vision_annotations 
                 SET is_confirmed = 1, is_preliminary = 0, source = 'expert_ground_truth' 
                 WHERE example_id = :id"
            )->execute([':id' => $exampleId]);
        }

        return [
            'success' => true,
            'example_uuid' => $exampleUuid,
            'new_status' => $newStatus,
            'notes' => $notes,
        ];
    }

    /**
     * Create a frozen version of the dataset using purged chronological splitting.
     */
    public function createDatasetVersion(
        string $versionTag,
        string $description = '',
        float $trainRatio = 0.70,
        float $valRatio = 0.15,
        int $embargoSeconds = 86400
    ): array {
        $pdo = Database::pdo();

        // Fetch all verified examples
        $stmt = $pdo->query(
            "SELECT id, example_uuid, chart_timestamp 
             FROM vision_training_examples 
             ORDER BY chart_timestamp ASC"
        );
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($samples)) {
            return ['success' => false, 'error' => 'No samples found to version.'];
        }

        // Run purged chronological split
        $splitResult = $this->splitter->split($samples, $trainRatio, $valRatio, $embargoSeconds);

        $trainIds = array_column($splitResult['train'], 'id');
        $valIds = array_column($splitResult['val'], 'id');
        $testIds = array_column($splitResult['test'], 'id');
        $purgedIds = array_column($splitResult['purged'], 'id');

        // Update database splits
        $pdo->beginTransaction();
        try {
            if (!empty($trainIds)) {
                $in = implode(',', array_map('intval', $trainIds));
                $pdo->exec("UPDATE vision_training_examples SET dataset_split = 'train', is_purged_embargo = 0, dataset_version = '{$versionTag}' WHERE id IN ({$in})");
            }
            if (!empty($valIds)) {
                $in = implode(',', array_map('intval', $valIds));
                $pdo->exec("UPDATE vision_training_examples SET dataset_split = 'val', is_purged_embargo = 0, dataset_version = '{$versionTag}' WHERE id IN ({$in})");
            }
            if (!empty($testIds)) {
                $in = implode(',', array_map('intval', $testIds));
                $pdo->exec("UPDATE vision_training_examples SET dataset_split = 'test', is_purged_embargo = 0, dataset_version = '{$versionTag}' WHERE id IN ({$in})");
            }
            if (!empty($purgedIds)) {
                $in = implode(',', array_map('intval', $purgedIds));
                $pdo->exec("UPDATE vision_training_examples SET is_purged_embargo = 1 WHERE id IN ({$in})");
            }

            // Record in dataset_versions
            $sqlVer = "INSERT INTO dataset_versions 
                (version_tag, description, symbol, total_examples, train_examples, val_examples, test_examples, 
                 purge_gap_bars, split_hash, status, created_at, updated_at)
                VALUES 
                (:vtag, :desc, 'XAUUSD', :total, :train, :val, :test, 
                 60, :shash, 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                total_examples = VALUES(total_examples), train_examples = VALUES(train_examples),
                val_examples = VALUES(val_examples), test_examples = VALUES(test_examples),
                split_hash = VALUES(split_hash), status = 'active', updated_at = NOW()";

            $stmtVer = $pdo->prepare($sqlVer);
            $stmtVer->execute([
                ':vtag' => $versionTag,
                ':desc' => $description,
                ':total' => count($samples),
                ':train' => count($trainIds),
                ':val' => count($valIds),
                ':test' => count($testIds),
                ':shash' => $splitResult['split_stats']['split_hash'],
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'version_tag' => $versionTag,
            'split_stats' => $splitResult['split_stats'],
        ];
    }
}
