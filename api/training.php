<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Market\MarketDataService;
use App\ML\ModelManager;
use App\ML\SignalFeedbackEngine;

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireApi();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? 'status';

    $modelMgr = new ModelManager();
    $feedbackEngine = new SignalFeedbackEngine();

    if ($method === 'GET') {
        switch ($action) {
            case 'status':
                $symbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');
                $activeModel = $modelMgr->getActiveModel();
                $versions = $modelMgr->listVersions(15);
                $errorReport = $feedbackEngine->generateReport($symbol);

                // Fetch calibration bins for active model
                $calibBins = [];
                if (!empty($activeModel['id'])) {
                    $calibBins = Database::fetchAll(
                        'SELECT bin_index, prob_min, prob_max, prob_mean, empirical_win_rate, sample_count
                         FROM ml_calibration_bins
                         WHERE model_version_id = ?
                         ORDER BY bin_index ASC',
                        [$activeModel['id']]
                    );
                }

                // Dataset health check
                $market = new MarketDataService(null, $symbol);
                $candles = $market->candles('M15', 500);
                $missingBars = 0;
                $prevTime = null;
                foreach ($candles as $c) {
                    if ($prevTime !== null && ($c['t'] - $prevTime) > 900 && ($c['t'] - $prevTime) < 86400) {
                        $missingBars++;
                    }
                    $prevTime = $c['t'];
                }

                Response::json([
                    'ok'            => true,
                    'symbol'        => $symbol,
                    'active_model'  => $activeModel,
                    'versions'      => $versions,
                    'calibration'   => $calibBins,
                    'error_report'  => $errorReport,
                    'dataset_check' => [
                        'total_candles' => count($candles),
                        'missing_bars'  => $missingBars,
                        'source'        => $market->provider()->label(),
                        'data_mode'     => $market->mode(),
                        'earliest'      => !empty($candles) ? gmdate('Y-m-d H:i:s', (int)$candles[0]['t']) : null,
                        'latest'        => !empty($candles) ? gmdate('Y-m-d H:i:s', (int)end($candles)['t']) : null,
                    ],
                ]);
                return;

            case 'error_report':
                $symbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');
                $report = $feedbackEngine->generateReport($symbol);
                Response::json(['ok' => true, 'report' => $report]);
                return;

            case 'versions':
                $versions = $modelMgr->listVersions(30);
                Response::json(['ok' => true, 'versions' => $versions]);
                return;

            default:
                Response::error("Unknown action: $action", 400);
                return;
        }
    }

    if ($method === 'POST') {
        Csrf::validate();

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        switch ($action) {
            case 'train':
                $symbol = MarketDataService::normalizeSymbol($input['symbol'] ?? 'XAUUSD');
                $timeframe = strtoupper(trim((string)($input['timeframe'] ?? 'M15')));
                $trainSplit = max(0.50, min(0.85, (float)($input['train_split'] ?? 0.70)));
                $epochs = max(30, min(300, (int)($input['epochs'] ?? 150)));
                $lr = max(0.005, min(0.20, (float)($input['learning_rate'] ?? 0.05)));
                $l2 = max(0.0001, min(0.10, (float)($input['l2_reg'] ?? 0.01)));

                $market = new MarketDataService(null, $symbol);
                $candles = $market->candles($timeframe, 600);

                $result = $modelMgr->train($symbol, $timeframe, $candles, [
                    'train_split'   => $trainSplit,
                    'epochs'        => $epochs,
                    'learning_rate' => $lr,
                    'l2_reg'        => $l2,
                ]);

                Response::json($result);
                return;

            case 'promote':
                $versionId = (int)($input['version_id'] ?? 0);
                if ($versionId <= 0) {
                    Response::error('Invalid version_id', 400);
                    return;
                }
                $ok = $modelMgr->promoteModel($versionId);
                Response::json(['ok' => $ok, 'message' => $ok ? 'Model promoted to production ACTIVE.' : 'Promotion failed']);
                return;

            case 'rollback':
                $prev = $modelMgr->rollbackModel();
                if (!$prev) {
                    Response::error('No previous stable model version found to rollback to.', 404);
                    return;
                }
                Response::json([
                    'ok'      => true,
                    'message' => "Successfully rolled back to stable version {$prev['version_tag']}.",
                    'model'   => $prev,
                ]);
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
