<?php
declare(strict_types=1);

namespace App\ML;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Market\MarketDataService;

/**
 * Phase 3, 5 & 7 — Model Lifecycle, Walk-Forward Training, Versioning, Promotion & Rollback.
 */
final class ModelManager
{
    private DatasetGenerator $datasetGen;

    public function __construct(?DatasetGenerator $datasetGen = null)
    {
        $this->datasetGen = $datasetGen ?? new DatasetGenerator();
    }

    /**
     * Train and validate a new model version on chronological data.
     *
     * @param string $symbol Instrument symbol (e.g. 'XAUUSD')
     * @param string $timeframe Execution timeframe (e.g. 'M15')
     * @param array $candles Historical candles array
     * @param array $hyperparams Hyperparameters for fitting and splitting
     * @return array Training results including model version ID and metrics
     */
    public function train(
        string $symbol = 'XAUUSD',
        string $timeframe = 'M15',
        array $candles = [],
        array $hyperparams = []
    ): array {
        $symbol = MarketDataService::normalizeSymbol($symbol);
        $timeframe = strtoupper(trim($timeframe)) ?: 'M15';

        if (empty($candles)) {
            $market = new MarketDataService(null, $symbol);
            $candles = $market->candles($timeframe, 600);
        }

        $nCandles = count($candles);
        if ($nCandles < 90) {
            throw new \RuntimeException("Insufficient historical candles for training ($nCandles provided, minimum 90 required).");
        }

        $trainSplitRatio = (float)($hyperparams['train_split'] ?? 0.70);
        $splitIdx = (int)($nCandles * $trainSplitRatio);

        $trainCandles = array_slice($candles, 0, $splitIdx);
        $valCandles = array_slice($candles, $splitIdx);

        $trainFrom = gmdate('Y-m-d H:i:s', (int)$trainCandles[0]['t']);
        $trainTo = gmdate('Y-m-d H:i:s', (int)end($trainCandles)['t']);
        $valFrom = gmdate('Y-m-d H:i:s', (int)$valCandles[0]['t']);
        $valTo = gmdate('Y-m-d H:i:s', (int)end($valCandles)['t']);

        // 1. Generate In-Sample and Out-of-Sample Datasets (zero look-ahead bias)
        $trainData = $this->datasetGen->generate($trainCandles, $symbol, $timeframe, $hyperparams);
        $valData = $this->datasetGen->generate($valCandles, $symbol, $timeframe, $hyperparams);

        $XTrain = array_column($trainData['samples'], 'features');
        $yTrain = array_column($trainData['samples'], 'label');
        $rTrain = array_column($trainData['samples'], 'return_r');

        $XVal = array_column($valData['samples'], 'features');
        $yVal = array_column($valData['samples'], 'label');
        $rVal = array_column($valData['samples'], 'return_r');

        if (empty($XTrain) || empty($XVal)) {
            throw new \RuntimeException('Dataset generator did not find sufficient trade setups in given range. Try a larger candle range.');
        }

        // 2. Fit Probabilistic Model on In-Sample Data
        $model = new ProbabilisticModel();
        $fitRes = $model->fit($XTrain, $yTrain, $hyperparams);

        // 3. Evaluate on In-Sample and Out-of-Sample sets
        $evalTrain = $model->evaluate($XTrain, $yTrain);
        $evalVal = $model->evaluate($XVal, $yVal);

        // Quantitative Trading Metrics
        $trainWins = array_sum(array_map(static fn($r) => $r > 0 ? $r : 0, $rTrain));
        $trainLoss = abs(array_sum(array_map(static fn($r) => $r < 0 ? $r : 0, $rTrain)));
        $trainPf = $trainLoss > 0.001 ? round($trainWins / $trainLoss, 3) : 3.0;
        $trainExp = count($rTrain) > 0 ? round(array_sum($rTrain) / count($rTrain), 3) : 0.0;
        $trainWr = count($yTrain) > 0 ? round((array_sum($yTrain) / count($yTrain)) * 100, 2) : 0.0;

        $valWins = array_sum(array_map(static fn($r) => $r > 0 ? $r : 0, $rVal));
        $valLoss = abs(array_sum(array_map(static fn($r) => $r < 0 ? $r : 0, $rVal)));
        $valPf = $valLoss > 0.001 ? round($valWins / $valLoss, 3) : 2.5;
        $valExp = count($rVal) > 0 ? round(array_sum($rVal) / count($rVal), 3) : 0.0;
        $valWr = count($yVal) > 0 ? round((array_sum($yVal) / count($yVal)) * 100, 2) : 0.0;

        // Calculate Out-of-Sample Max Drawdown
        $peakEquity = 0.0;
        $currentEquity = 0.0;
        $maxDdPct = 0.0;
        foreach ($rVal as $r) {
            $currentEquity += $r;
            if ($currentEquity > $peakEquity) $peakEquity = $currentEquity;
            $dd = $peakEquity - $currentEquity;
            if ($dd > $maxDdPct) $maxDdPct = $dd;
        }

        // 4. Version Tag Generation
        $vCount = (int)Database::value('SELECT COUNT(*) FROM ml_model_versions') + 1;
        $versionTag = sprintf('xauusd-v%d.%d-%s', 1 + intdiv($vCount, 10), $vCount % 10, gmdate('Ymd'));

        $serialized = $model->serialize();
        $nowSql = gmdate('Y-m-d H:i:s');

        // Check if this is the first model, automatically make it active
        $hasActive = (bool)Database::value("SELECT 1 FROM ml_model_versions WHERE is_active = 1 LIMIT 1");
        $isActive = !$hasActive ? 1 : 0;
        $status = $isActive ? 'active' : 'validated';

        $versionId = Database::insert('ml_model_versions', [
            'version_tag'             => $versionTag,
            'model_type'              => 'trade_filter',
            'status'                  => $status,
            'algorithm'               => 'Regularized Logistic Regression (Ridge L2) + Platt Calibration',
            'hyperparameters'         => json_encode($hyperparams),
            'weights_json'            => json_encode($serialized),
            'feature_names'           => json_encode(FeatureExtractor::FEATURE_NAMES),
            'train_from'              => $trainFrom,
            'train_to'                => $trainTo,
            'val_from'                => $valFrom,
            'val_to'                  => $valTo,
            'train_sample_count'      => count($XTrain),
            'val_sample_count'        => count($XVal),
            'in_sample_win_rate'      => $trainWr,
            'in_sample_profit_factor' => $trainPf,
            'in_sample_expectancy_r'  => $trainExp,
            'oos_win_rate'            => $valWr,
            'oos_profit_factor'       => $valPf,
            'oos_expectancy_r'        => $valExp,
            'oos_max_drawdown_pct'    => round($maxDdPct, 2),
            'brier_score'             => $evalVal['brier_score'],
            'expected_calib_error'    => $evalVal['ece'],
            'sharpe_ratio'            => round($valExp / max(0.1, ($maxDdPct / 2.0)), 2),
            'confusion_matrix'        => json_encode($evalVal['confusion_matrix']),
            'is_active'               => $isActive,
            'notes'                   => sprintf('Automated train split %.0f/%.0f on %s %s', $trainSplitRatio * 100, (1 - $trainSplitRatio) * 100, $symbol, $timeframe),
            'trained_at'              => $nowSql,
            'validated_at'            => $nowSql,
            'activated_at'            => $isActive ? $nowSql : null,
            'created_at'              => $nowSql,
            'updated_at'              => $nowSql,
        ]);

        // 5. Store Reliability Calibration Bins
        foreach ($evalVal['calibration_bins'] as $bin) {
            Database::insert('ml_calibration_bins', [
                'model_version_id'   => $versionId,
                'bin_index'          => $bin['bin'],
                'prob_min'           => $bin['min_prob'],
                'prob_max'           => $bin['max_prob'],
                'prob_mean'          => $bin['mean_prob'],
                'empirical_win_rate' => $bin['actual_rate'],
                'sample_count'       => $bin['count'],
                'created_at'         => $nowSql,
            ]);
        }

        return [
            'ok'               => true,
            'model_version_id' => $versionId,
            'version_tag'      => $versionTag,
            'status'           => $status,
            'is_active'        => (bool)$isActive,
            'in_sample'        => [
                'samples'       => count($XTrain),
                'win_rate'      => $trainWr,
                'profit_factor' => $trainPf,
                'expectancy_r'  => $trainExp,
                'accuracy'      => $evalTrain['accuracy'],
            ],
            'out_of_sample'    => [
                'samples'       => count($XVal),
                'win_rate'      => $valWr,
                'profit_factor' => $valPf,
                'expectancy_r'  => $valExp,
                'brier_score'   => $evalVal['brier_score'],
                'ece'           => $evalVal['ece'],
                'accuracy'      => $evalVal['accuracy'],
                'max_dd_r'      => round($maxDdPct, 2),
                'confusion'     => $evalVal['confusion_matrix'],
            ],
        ];
    }

    /**
     * Get currently active production model.
     */
    public function getActiveModel(): ?array
    {
        $row = Database::fetch("SELECT * FROM ml_model_versions WHERE is_active = 1 LIMIT 1");
        if (!$row) {
            // Fallback to latest validated model
            $row = Database::fetch("SELECT * FROM ml_model_versions ORDER BY id DESC LIMIT 1");
        }
        if (!$row) {
            return null;
        }

        $weightsData = json_decode((string)$row['weights_json'], true) ?: [];
        $model = ProbabilisticModel::deserialize($weightsData);

        return [
            'id'             => (int)$row['id'],
            'version_tag'    => $row['version_tag'],
            'status'         => $row['status'],
            'model_instance' => $model,
            'win_rate'       => (float)$row['oos_win_rate'],
            'profit_factor'  => (float)$row['oos_profit_factor'],
            'expectancy_r'   => (float)$row['oos_expectancy_r'],
            'brier_score'    => (float)$row['brier_score'],
            'trained_at'     => $row['trained_at'],
        ];
    }

    /**
     * Promote a specific model version to ACTIVE in production.
     */
    public function promoteModel(int $versionId): bool
    {
        $target = Database::fetch('SELECT id, version_tag FROM ml_model_versions WHERE id = ?', [$versionId]);
        if (!$target) {
            return false;
        }

        $nowSql = gmdate('Y-m-d H:i:s');
        Database::transaction(function () use ($versionId, $nowSql) {
            // Set all existing active models to 'validated'
            Database::query("UPDATE ml_model_versions SET is_active = 0, status = 'validated', updated_at = ? WHERE is_active = 1", [$nowSql]);
            // Activate selected model
            Database::query("UPDATE ml_model_versions SET is_active = 1, status = 'active', activated_at = ?, updated_at = ? WHERE id = ?", [$nowSql, $nowSql, $versionId]);
        });

        Logger::info("Promoted ML Model {$target['version_tag']} (ID: $versionId) to production ACTIVE.");
        return true;
    }

    /**
     * Rollback to the previous stable validated model version.
     */
    public function rollbackModel(): ?array
    {
        $current = Database::fetch("SELECT id, version_tag FROM ml_model_versions WHERE is_active = 1 LIMIT 1");
        $prev = Database::fetch(
            "SELECT id, version_tag FROM ml_model_versions WHERE is_active = 0 AND status IN ('validated', 'active') ORDER BY id DESC LIMIT 1"
        );

        if (!$prev) {
            return null;
        }

        $nowSql = gmdate('Y-m-d H:i:s');
        Database::transaction(function () use ($current, $prev, $nowSql) {
            if ($current) {
                Database::query("UPDATE ml_model_versions SET is_active = 0, status = 'rolled_back', updated_at = ? WHERE id = ?", [$nowSql, $current['id']]);
            }
            Database::query("UPDATE ml_model_versions SET is_active = 1, status = 'active', activated_at = ?, updated_at = ? WHERE id = ?", [$nowSql, $nowSql, $prev['id']]);
        });

        Logger::warning("Rolled back ML Model to {$prev['version_tag']} (ID: {$prev['id']}).");
        return $prev;
    }

    /**
     * List all model versions and their validation metrics.
     */
    public function listVersions(int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT id, version_tag, model_type, status, is_active,
                    train_sample_count, val_sample_count,
                    in_sample_win_rate, in_sample_profit_factor, in_sample_expectancy_r,
                    oos_win_rate, oos_profit_factor, oos_expectancy_r, oos_max_drawdown_pct,
                    brier_score, expected_calib_error, sharpe_ratio, trained_at, activated_at
             FROM ml_model_versions
             ORDER BY id DESC
             LIMIT ?',
            [$limit]
        );
    }
}
