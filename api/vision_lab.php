<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Market\MarketDataService;
use App\Vision\DatasetExporter;
use App\Vision\MultiTimeframeVisionAnalyzer;
use App\Vision\VisionAnnotationEngine;
use App\Vision\VisionBenchmarkService;
use App\Vision\VisionRagService;

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireApi();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? 'list';

    $exporter = new DatasetExporter();
    $benchmarker = new VisionBenchmarkService();
    $annEngine = new VisionAnnotationEngine();
    $mtfVision = new MultiTimeframeVisionAnalyzer();

    if ($method === 'GET') {
        switch ($action) {
            case 'list':
                $symbol = isset($_GET['symbol']) && $_GET['symbol'] !== '' ? MarketDataService::normalizeSymbol($_GET['symbol']) : null;
                $status = $_GET['status'] ?? null;
                $limit = max(5, min(100, (int)($_GET['limit'] ?? 30)));

                $sql = 'SELECT e.*, 
                               (SELECT COUNT(*) FROM vision_annotations a WHERE a.example_id = e.id AND a.source = "expert_ground_truth") as gt_count,
                               (SELECT COUNT(*) FROM vision_annotations a WHERE a.example_id = e.id AND a.source = "ai_predicted") as ai_count
                        FROM vision_training_examples e
                        WHERE 1=1';
                $params = [];
                if ($symbol !== null) {
                    $sql .= ' AND e.symbol = ?';
                    $params[] = $symbol;
                }
                if ($status !== null && in_array($status, ['unreviewed', 'verified', 'expert_corrected'], true)) {
                    $sql .= ' AND e.review_status = ?';
                    $params[] = $status;
                }
                $sql .= ' ORDER BY e.id DESC LIMIT ?';
                $params[] = $limit;

                $examples = Database::fetchAll($sql, $params);
                Response::json(['ok' => true, 'examples' => $examples]);
                return;

            case 'get_example':
                $id = (int)($_GET['id'] ?? 0);
                $ex = Database::fetch('SELECT * FROM vision_training_examples WHERE id = ?', [$id]);
                if (!$ex) {
                    Response::error('Example not found', 404);
                    return;
                }
                $annotations = Database::fetchAll('SELECT * FROM vision_annotations WHERE example_id = ? ORDER BY id ASC', [$id]);
                $aiAnns = array_values(array_filter($annotations, static fn($a) => $a['source'] === 'ai_predicted'));
                $gtAnns = array_values(array_filter($annotations, static fn($a) => $a['source'] === 'expert_ground_truth'));

                $matchResult = $annEngine->matchAnnotations($aiAnns, $gtAnns, 0.35);

                Response::json([
                    'ok'          => true,
                    'example'     => $ex,
                    'annotations' => [
                        'ai_predicted'        => $aiAnns,
                        'expert_ground_truth' => $gtAnns,
                    ],
                    'match_evaluation' => $matchResult,
                ]);
                return;

            case 'benchmark':
                $datasetVersion = (string)($_GET['version'] ?? 'all');
                $result = $benchmarker->runBenchmark($datasetVersion);
                Response::json($result);
                return;

            case 'export':
                $format = strtolower(trim((string)($_GET['format'] ?? 'jsonl')));
                $version = $_GET['version'] ?? null;

                if ($format === 'coco') {
                    $coco = $exporter->exportCoco($version);
                    Response::json($coco);
                    return;
                }

                $jsonl = $exporter->exportJsonl($version);
                header('Content-Type: application/x-jsonlines; charset=utf-8');
                header('Content-Disposition: attachment; filename="vision_smc_groundtruth_' . gmdate('Ymd') . '.jsonl"');
                echo $jsonl;
                exit;

            default:
                Response::error("Unknown action: $action", 400);
                return;
        }
    }

    if ($method === 'POST') {
        Csrf::validate();

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        switch ($action) {
            case 'upload':
                // Handle file upload or base64 payload
                $symbol = MarketDataService::normalizeSymbol($input['symbol'] ?? 'XAUUSD');
                $timeframe = strtoupper(trim((string)($input['timeframe'] ?? 'M15')));
                $refPrice = !empty($input['ref_price']) ? (float)$input['ref_price'] : null;

                $uploadInfo = ChartVisionService::storeUpload($_FILES['chart_file'] ?? [], $input['image_base64'] ?? null);

                // Run AI analysis
                $aiAnalysis = ChartVisionService::analyzeChart(
                    $uploadInfo['path'],
                    $symbol,
                    $timeframe,
                    $refPrice,
                    null,
                    null,
                    (int)$user['id']
                );

                $exampleUuid = 'vte_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
                $nowSql = gmdate('Y-m-d H:i:s');

                $rep = $aiAnalysis['report'];
                $dir = $rep['trade_direction'] ?? 'WAIT';
                $ez = $rep['entry_zone'] ?? [];
                $sl = $rep['stop_loss']['price'] ?? null;
                $tp1 = $rep['take_profit']['tp1']['price'] ?? null;
                $tp2 = $rep['take_profit']['tp2']['price'] ?? null;
                $tp3 = $rep['take_profit']['tp3']['price'] ?? null;

                $exampleId = Database::insert('vision_training_examples', [
                    'example_uuid'        => $exampleUuid,
                    'user_id'             => (int)$user['id'],
                    'symbol'              => $symbol,
                    'timeframe'           => $timeframe,
                    'image_path'          => $uploadInfo['rel_url'],
                    'chart_timestamp'     => $nowSql,
                    'ref_price'           => $refPrice ?: ($aiAnalysis['ref_price'] ?? null),
                    'review_status'       => 'unreviewed',
                    'dataset_split'       => 'train',
                    'dataset_version'     => 'v2.0',
                    'market_regime'       => $rep['market_structure'] ?? 'ranging_consolidation',
                    'trend_direction'     => in_array($rep['market_bias'], ['Bullish', 'Bearish', 'Neutral'], true) ? $rep['market_bias'] : 'Neutral',
                    'market_structure'    => mb_substr((string)($rep['market_structure'] ?? 'Neutral'), 0, 64),
                    'key_swing_high'      => $rep['liquidity_targets']['bsl'][0]['level'] ?? null,
                    'key_swing_low'       => $rep['liquidity_targets']['ssl'][0]['level'] ?? null,
                    'trade_decision'      => in_array($dir, ['BUY', 'SELL', 'WAIT', 'NO_TRADE'], true) ? $dir : 'WAIT',
                    'entry_price'         => $ez['ref_entry'] ?? ($ez['min'] ?? null),
                    'stop_loss'           => $sl,
                    'tp1'                 => $tp1,
                    'tp2'                 => $tp2,
                    'tp3'                 => $tp3,
                    'risk_reward'         => $rep['risk_reward']['tp1'] ?? null,
                    'ai_raw_prediction'   => json_encode($aiAnalysis),
                    'created_at'          => $nowSql,
                    'updated_at'          => $nowSql,
                ]);

                // Store AI predicted bounding box annotations
                if (!empty($aiAnalysis['overlay']['elements'])) {
                    foreach ($aiAnalysis['overlay']['elements'] as $el) {
                        $pType = strtoupper(trim((string)($el['type'] ?? 'FVG')));
                        $mappedType = 'FVG_BULL';
                        if (str_contains($pType, 'OB') || str_contains($pType, 'ORDER_BLOCK')) $mappedType = 'OB_BULL';
                        elseif (str_contains($pType, 'BOS') || str_contains($pType, 'CHOCH')) $mappedType = 'BOS';
                        elseif (str_contains($pType, 'LIQUIDITY') || str_contains($pType, 'SWEEP')) $mappedType = 'LIQUIDITY_SWEEP';
                        elseif (str_contains($pType, 'ENTRY')) $mappedType = 'ENTRY';
                        elseif (str_contains($pType, 'SL')) $mappedType = 'SL';
                        elseif (str_contains($pType, 'TP')) $mappedType = 'TP';

                        $boxY = isset($el['y']) ? ($el['y'] / max(1, $uploadInfo['height'])) : 0.45;
                        $boxH = isset($el['height']) ? ($el['height'] / max(1, $uploadInfo['height'])) : 0.10;

                        Database::insert('vision_annotations', [
                            'example_id'        => $exampleId,
                            'source'            => 'ai_predicted',
                            'pattern_type'      => $mappedType,
                            'direction'         => str_contains($pType, 'BEAR') ? 'bearish' : 'bullish',
                            'box_x'             => 0.1500,
                            'box_y'             => round($boxY, 4),
                            'box_w'             => 0.7000,
                            'box_h'             => round($boxH, 4),
                            'price_top'         => $el['top'] ?? null,
                            'price_bottom'      => $el['bottom'] ?? null,
                            'price_level'       => $el['price'] ?? null,
                            'is_confirmed'      => 1,
                            'uncertainty_score' => 20,
                            'notes'             => $el['label'] ?? null,
                            'created_at'        => $nowSql,
                        ]);
                    }
                }

                Response::json([
                    'ok'          => true,
                    'example_id'  => $exampleId,
                    'uuid'        => $exampleUuid,
                    'image_url'   => $uploadInfo['rel_url'],
                    'analysis'    => $aiAnalysis,
                ]);
                return;

            case 'save_annotations':
                $exampleId = (int)($input['example_id'] ?? 0);
                $annotations = (array)($input['annotations'] ?? []);
                $expertNotes = trim((string)($input['expert_notes'] ?? ''));
                $reviewStatus = in_array($input['review_status'] ?? '', ['verified', 'expert_corrected'], true) ? $input['review_status'] : 'expert_corrected';

                $nowSql = gmdate('Y-m-d H:i:s');

                Database::transaction(function () use ($exampleId, $annotations, $expertNotes, $reviewStatus, $nowSql) {
                    // Remove prior expert annotations for this example
                    Database::query("DELETE FROM vision_annotations WHERE example_id = ? AND source = 'expert_ground_truth'", [$exampleId]);

                    foreach ($annotations as $a) {
                        $pType = strtoupper(trim((string)($a['pattern_type'] ?? 'FVG_BULL')));
                        if (!isset(VisionAnnotationEngine::PATTERN_TYPES[$pType])) {
                            $pType = 'FVG_BULL';
                        }
                        Database::insert('vision_annotations', [
                            'example_id'        => $exampleId,
                            'source'            => 'expert_ground_truth',
                            'pattern_type'      => $pType,
                            'direction'         => in_array($a['direction'] ?? '', ['bullish', 'bearish', 'neutral'], true) ? $a['direction'] : 'neutral',
                            'box_x'             => max(0.0, min(1.0, (float)($a['box_x'] ?? 0.0))),
                            'box_y'             => max(0.0, min(1.0, (float)($a['box_y'] ?? 0.0))),
                            'box_w'             => max(0.01, min(1.0, (float)($a['box_w'] ?? 0.1))),
                            'box_h'             => max(0.01, min(1.0, (float)($a['box_h'] ?? 0.1))),
                            'price_top'         => isset($a['price_top']) && $a['price_top'] !== '' ? (float)$a['price_top'] : null,
                            'price_bottom'      => isset($a['price_bottom']) && $a['price_bottom'] !== '' ? (float)$a['price_bottom'] : null,
                            'price_level'       => isset($a['price_level']) && $a['price_level'] !== '' ? (float)$a['price_level'] : null,
                            'is_confirmed'      => 1,
                            'uncertainty_score' => 0,
                            'notes'             => mb_substr(trim((string)($a['notes'] ?? '')), 0, 250),
                            'created_at'        => $nowSql,
                        ]);
                    }

                    // Update parent example
                    Database::update('vision_training_examples', [
                        'review_status' => $reviewStatus,
                        'expert_notes'  => $expertNotes ?: null,
                        'updated_at'    => $nowSql,
                    ], 'id = ?', [$exampleId]);
                });

                Response::json(['ok' => true, 'message' => 'Ground-truth annotations saved successfully. Example updated to ' . $reviewStatus]);
                return;

            case 'record_outcome':
                $exampleId = (int)($input['example_id'] ?? 0);
                $outcome = in_array($input['outcome'] ?? '', ['win', 'loss', 'breakeven', 'expired'], true) ? $input['outcome'] : 'win';
                $realizedR = (float)($input['realized_r'] ?? 0.0);
                $mfe = isset($input['max_favorable_r']) ? (float)$input['max_favorable_r'] : null;
                $mae = isset($input['max_adverse_r']) ? (float)$input['max_adverse_r'] : null;
                $notes = trim((string)($input['notes'] ?? ''));

                $nowSql = gmdate('Y-m-d H:i:s');
                Database::update('vision_training_examples', [
                    'outcome'             => $outcome,
                    'realized_r'          => $realizedR,
                    'max_favorable_r'     => $mfe,
                    'max_adverse_r'       => $mae,
                    'outcome_verified_at' => $nowSql,
                    'updated_at'          => $nowSql,
                ], 'id = ?', [$exampleId]);

                Response::json(['ok' => true, 'message' => 'Verified market outcome recorded successfully.']);
                return;

            case 'bundle_mtf':
                // Multi-Timeframe Bundle
                $symbol = MarketDataService::normalizeSymbol($input['symbol'] ?? 'XAUUSD');
                $filesByTf = [];

                if (!empty($_FILES['files']['tmp_name'])) {
                    foreach ($_FILES['files']['tmp_name'] as $tf => $tmpPath) {
                        if (is_uploaded_file($tmpPath)) {
                            $stored = ChartVisionService::storeUpload([
                                'tmp_name' => $tmpPath,
                                'size'     => $_FILES['files']['size'][$tf],
                                'name'     => $_FILES['files']['name'][$tf],
                            ]);
                            $filesByTf[$tf] = $stored['path'];
                        }
                    }
                }

                $res = $mtfVision->analyzeBundle($filesByTf, $symbol, null, (int)$user['id']);
                Response::json($res);
                return;

            default:
                Response::error("Unknown action: $action", 400);
                return;
        }
    }

    Response::error('Method not allowed', 405);
} catch (\Throwable $e) {
    Response::error($e->getMessage(), 500);
}
