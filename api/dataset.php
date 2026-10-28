<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Dataset\ChartTrainingPipeline;
use App\Dataset\DatasetManager;
use App\Dataset\HistoricalImporter;
use App\Dataset\MultiTimeframeResampler;

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'overview');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$datasetManager = new DatasetManager();
$importer = new HistoricalImporter();
$pipeline = new ChartTrainingPipeline();

try {
    switch ($action) {
        case 'overview':
            $overview = $datasetManager->getDatasetOverview();
            Response::json(['success' => true, 'data' => $overview]);
            break;

        case 'list_examples':
            $pdo = Database::pdo();
            $status = (string)($_GET['status'] ?? 'all');
            $split = (string)($_GET['split'] ?? 'all');
            $scenario = (string)($_GET['scenario'] ?? 'all');
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));

            $where = ['1=1'];
            $params = [];

            if ($status !== 'all') {
                $where[] = 'review_status = :status';
                $params[':status'] = $status;
            }
            if ($split !== 'all') {
                $where[] = 'dataset_split = :split';
                $params[':split'] = $split;
            }
            if ($scenario !== 'all') {
                $where[] = 'scenario_type = :scenario';
                $params[':scenario'] = $scenario;
            }

            $whereClause = implode(' AND ', $where);
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM vision_training_examples WHERE {$whereClause}");
            $stmtCount->execute($params);
            $totalCount = (int)$stmtCount->fetchColumn();

            $sql = "SELECT id, example_uuid, symbol, timeframe, svg_chart_path, image_path, chart_timestamp, 
                           ref_price, review_status, dataset_split, is_purged_embargo, market_regime, scenario_type, 
                           trend_direction, trade_decision, entry_price, stop_loss, tp1, tp2, tp3, 
                           outcome, realized_r, max_favorable_r, max_adverse_r, created_at 
                    FROM vision_training_examples 
                    WHERE {$whereClause} 
                    ORDER BY chart_timestamp DESC 
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'success' => true,
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'data' => $rows,
            ]);
            break;

        case 'get_example':
            $uuid = (string)($_GET['uuid'] ?? '');
            if ($uuid === '') {
                Response::json(['success' => false, 'error' => 'uuid parameter required'], 400);
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare("SELECT * FROM vision_training_examples WHERE example_uuid = :uuid");
            $stmt->execute([':uuid' => $uuid]);
            $example = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$example) {
                Response::json(['success' => false, 'error' => 'Example not found'], 404);
            }

            // Fetch annotations
            $annStmt = $pdo->prepare(
                "SELECT id, source, pattern_type, direction, box_x, box_y, box_w, box_h, 
                        price_top, price_bottom, price_level, is_confirmed, is_preliminary, uncertainty_score, notes 
                 FROM vision_annotations 
                 WHERE example_id = :id 
                 ORDER BY id ASC"
            );
            $annStmt->execute([':id' => (int)$example['id']]);
            $annotations = $annStmt->fetchAll(PDO::FETCH_ASSOC);

            $example['annotations'] = $annotations;
            $example['structured_candles'] = !empty($example['structured_candles'])
                ? json_decode((string)$example['structured_candles'], true)
                : [];
            $example['ai_raw_prediction'] = !empty($example['ai_raw_prediction'])
                ? json_decode((string)$example['ai_raw_prediction'], true)
                : null;

            Response::json(['success' => true, 'data' => $example]);
            break;

        case 'import_csv':
            if ($method !== 'POST') {
                Response::json(['success' => false, 'error' => 'POST method required'], 405);
            }

            $symbol = (string)($_POST['symbol'] ?? 'XAUUSD');
            $timeframe = (string)($_POST['timeframe'] ?? 'M15');
            $tzOffset = (float)($_POST['tz_offset_hours'] ?? 0.0);
            $saveToDb = (bool)($_POST['save_to_db'] ?? true);

            $csvContent = '';
            $filename = 'uploaded_data.csv';

            if (isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                $filename = $_FILES['csv_file']['name'];
                $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']) ?: '';
            } elseif (isset($_POST['csv_content'])) {
                $csvContent = (string)$_POST['csv_content'];
                $filename = (string)($_POST['filename'] ?? 'pasted_data.csv');
            }

            if (trim($csvContent) === '') {
                Response::json(['success' => false, 'error' => 'No CSV content uploaded or provided'], 400);
            }

            $importResult = $importer->import($csvContent, $symbol, $timeframe, [
                'tz_offset_hours' => $tzOffset,
                'save_to_db' => $saveToDb,
                'filename' => $filename,
            ]);

            // Don't dump entire huge candle array in JSON response
            $candlesCount = count($importResult['candles']);
            unset($importResult['candles']);
            $importResult['candles_imported'] = $candlesCount;

            Response::json(['success' => $importResult['success'], 'data' => $importResult]);
            break;

        case 'generate_windows':
            if ($method !== 'POST') {
                Response::json(['success' => false, 'error' => 'POST method required'], 405);
            }

            $symbol = (string)($_POST['symbol'] ?? 'XAUUSD');
            $execTf = (string)($_POST['timeframe'] ?? 'M15');
            $maxExamples = min(300, max(5, (int)($_POST['max_examples'] ?? 60)));

            // Fetch stored candles from database
            $pdo = Database::pdo();
            $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(open_time) as t, open as o, high as h, low as l, close as c, volume as v 
                                   FROM market_candles 
                                   WHERE symbol = :sym AND timeframe = :tf 
                                   ORDER BY open_time ASC LIMIT 4000");
            $stmt->execute([':sym' => $symbol, ':tf' => $execTf]);
            $candles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($candles) < 80) {
                // If not enough candles in DB, generate synthetic realistic baseline candles for testing
                $candles = [];
                $baseTs = time() - (200 * 900);
                $p = 2740.0;
                for ($k = 0; $k < 180; $k++) {
                    $dir = ($k % 5 === 0) ? -1 : 1;
                    $candleH = 1.5 + (sin($k / 10) * 1.8);
                    $o = $p;
                    $c = $p + ($dir * $candleH);
                    $h = max($o, $c) + (0.5 + abs(cos($k) * 0.8));
                    $l = min($o, $c) - (0.5 + abs(sin($k) * 0.8));
                    $candles[] = [
                        't' => $baseTs + ($k * 900),
                        'o' => round($o, 3),
                        'h' => round($h, 3),
                        'l' => round($l, 3),
                        'c' => round($c, 3),
                        'v' => round(100.0 + abs(sin($k) * 50), 1),
                    ];
                    $p = $c;
                }
            }

            $genResult = $datasetManager->generateDatasetFromCandles($candles, $symbol, $execTf, [
                'window_bars' => 50,
                'forward_bars' => 30,
                'step_bars' => 12,
                'max_examples' => $maxExamples,
            ]);

            Response::json(['success' => true, 'data' => $genResult]);
            break;

        case 'review_example':
            if ($method !== 'POST') {
                Response::json(['success' => false, 'error' => 'POST method required'], 405);
            }

            $uuid = (string)($_POST['uuid'] ?? '');
            $actionType = (string)($_POST['review_action'] ?? 'verify');
            $notes = (string)($_POST['notes'] ?? 'Human expert ground-truth review');
            $annJson = $_POST['annotations'] ?? null;
            $anns = is_string($annJson) ? json_decode($annJson, true) : (is_array($annJson) ? $annJson : null);

            $reviewRes = $datasetManager->reviewExample($uuid, [
                'action' => $actionType,
                'notes' => $notes,
                'annotations' => $anns,
            ]);

            Response::json($reviewRes);
            break;

        case 'create_version':
            if ($method !== 'POST') {
                Response::json(['success' => false, 'error' => 'POST method required'], 405);
            }

            $vTag = (string)($_POST['version_tag'] ?? ('v' . date('Y.m.d-His')));
            $desc = (string)($_POST['description'] ?? 'Purged chronological split dataset version');
            $trainRatio = (float)($_POST['train_ratio'] ?? 0.70);
            $valRatio = (float)($_POST['val_ratio'] ?? 0.15);

            $verRes = $datasetManager->createDatasetVersion($vTag, $desc, $trainRatio, $valRatio, 86400);
            Response::json($verRes);
            break;

        case 'train':
            if ($method !== 'POST') {
                Response::json(['success' => false, 'error' => 'POST method required'], 405);
            }

            $vTag = (string)($_POST['version_tag'] ?? ('xau_vision_v' . date('Ymd_His')));
            $lr = (float)($_POST['learning_rate'] ?? 0.05);
            $epochs = (int)($_POST['epochs'] ?? 100);

            $trainRes = $pipeline->train($vTag, [
                'learning_rate' => $lr,
                'epochs' => $epochs,
            ]);

            Response::json($trainRes);
            break;

        case 'rollback':
            if ($method !== 'POST') {
                Response::json(['success' => false, 'error' => 'POST method required'], 405);
            }

            $vTag = (string)($_POST['version_tag'] ?? '');
            if ($vTag === '') {
                Response::json(['success' => false, 'error' => 'version_tag required'], 400);
            }

            $rollRes = $pipeline->rollbackToVersion($vTag);
            Response::json($rollRes);
            break;

        default:
            Response::json(['success' => false, 'error' => "Unknown action '{$action}'"], 400);
    }
} catch (\Throwable $e) {
    Response::json([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}
