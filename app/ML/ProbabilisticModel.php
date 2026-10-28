<?php
declare(strict_types=1);

namespace App\ML;

/**
 * Interpretable Probabilistic Classification & Calibration Model.
 *
 * Implements:
 *  - Regularized Logistic Regression with Ridge (L2) Penalty.
 *  - Gradient Descent with adaptive learning rate and momentum.
 *  - Platt-calibrated posterior probability estimation P(Win | x).
 *  - Brier score, Expected Calibration Error (ECE), AUC-ROC, and Confusion Matrix.
 */
final class ProbabilisticModel
{
    private array $weights = [];
    private float $bias = 0.0;
    private float $plattA = 1.0;
    private float $plattB = 0.0;
    private array $featureMeans = [];
    private array $featureStds = [];
    private bool $isTrained = false;

    public function __construct(array $initialWeights = [], float $initialBias = 0.0)
    {
        $this->weights = $initialWeights;
        $this->bias = $initialBias;
        if (!empty($initialWeights)) {
            $this->isTrained = true;
        }
    }

    /**
     * Fit the model weights using training features and binary labels.
     *
     * @param array<array<float>> $X Matrix of feature vectors
     * @param array<int> $y Vector of binary labels (0 or 1)
     * @param array $hyperparams Learning rate, epochs, l2_reg, etc.
     * @return array Training progress metrics
     */
    public function fit(array $X, array $y, array $hyperparams = []): array
    {
        $n = count($X);
        if ($n === 0 || count($y) !== $n) {
            throw new \InvalidArgumentException('Number of feature vectors must match number of labels.');
        }

        $d = count($X[0]);
        $epochs = (int)($hyperparams['epochs'] ?? 150);
        $lr = (float)($hyperparams['learning_rate'] ?? 0.05);
        $l2Reg = (float)($hyperparams['l2_reg'] ?? 0.01);
        $momentum = (float)($hyperparams['momentum'] ?? 0.9);

        // 1. Feature Standardization (Z-score normalization)
        $this->featureMeans = array_fill(0, $d, 0.0);
        $this->featureStds = array_fill(0, $d, 1.0);

        for ($j = 0; $j < $d; $j++) {
            $col = array_column($X, $j);
            $mean = array_sum($col) / $n;
            $this->featureMeans[$j] = $mean;

            $variance = 0.0;
            foreach ($col as $val) {
                $variance += ($val - $mean) ** 2;
            }
            $std = sqrt($variance / max(1, $n - 1));
            $this->featureStds[$j] = $std > 0.0001 ? $std : 1.0;
        }

        // Standardize X
        $XNorm = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [];
            for ($j = 0; $j < $d; $j++) {
                $row[$j] = ($X[$i][$j] - $this->featureMeans[$j]) / $this->featureStds[$j];
            }
            $XNorm[$i] = $row;
        }

        // Initialize weights if not set
        $this->weights = array_fill(0, $d, 0.0);
        $this->bias = 0.0;
        $velW = array_fill(0, $d, 0.0);
        $velB = 0.0;

        $lossHistory = [];

        // 2. Gradient Descent Optimization
        for ($ep = 0; $ep < $epochs; $ep++) {
            $gradW = array_fill(0, $d, 0.0);
            $gradB = 0.0;
            $loss = 0.0;

            for ($i = 0; $i < $n; $i++) {
                $z = $this->bias;
                for ($j = 0; $j < $d; $j++) {
                    $z += $this->weights[$j] * $XNorm[$i][$j];
                }
                $pred = 1.0 / (1.0 + exp(-max(-15.0, min(15.0, $z))));
                $target = (float)$y[$i];
                $error = $pred - $target;

                $gradB += $error;
                for ($j = 0; $j < $d; $j++) {
                    $gradW[$j] += $error * $XNorm[$i][$j];
                }

                // Cross-entropy loss
                $loss -= ($target * log(max(1e-7, $pred)) + (1.0 - $target) * log(max(1e-7, 1.0 - $pred)));
            }

            // Average gradients + L2 penalty
            $gradB /= $n;
            $l2Cost = 0.0;
            for ($j = 0; $j < $d; $j++) {
                $l2Cost += $this->weights[$j] ** 2;
                $gradW[$j] = ($gradW[$j] / $n) + ($l2Reg * $this->weights[$j]);
            }
            $loss = ($loss / $n) + (0.5 * $l2Reg * $l2Cost);

            // Update with momentum
            $velB = $momentum * $velB - $lr * $gradB;
            $this->bias += $velB;
            for ($j = 0; $j < $d; $j++) {
                $velW[$j] = $momentum * $velW[$j] - $lr * $gradW[$j];
                $this->weights[$j] += $velW[$j];
            }

            // Decay learning rate
            if ($ep % 30 === 0 && $ep > 0) {
                $lr *= 0.85;
            }

            if ($ep % 15 === 0 || $ep === $epochs - 1) {
                $lossHistory[] = ['epoch' => $ep, 'loss' => round($loss, 5)];
            }
        }

        $this->isTrained = true;

        // 3. Platt Scaling Calibration Fit
        $logits = [];
        for ($i = 0; $i < $n; $i++) {
            $z = $this->bias;
            for ($j = 0; $j < $d; $j++) {
                $z += $this->weights[$j] * $XNorm[$i][$j];
            }
            $logits[] = $z;
        }
        $this->fitPlattScaling($logits, $y);

        return [
            'final_loss'   => end($lossHistory)['loss'] ?? 0.0,
            'epochs'       => $epochs,
            'loss_history' => $lossHistory,
            'weights'      => $this->weights,
            'bias'         => $this->bias,
            'platt_a'      => $this->plattA,
            'platt_b'      => $this->plattB,
        ];
    }

    /**
     * Predict calibrated probability of success P(Win | x).
     *
     * @param array<float> $x Raw feature vector
     * @return float Calibrated probability between 0.01 and 0.99
     */
    public function predictProb(array $x): float
    {
        if (!$this->isTrained) {
            return 0.50; // uninformative prior
        }

        $d = count($this->weights);
        $z = $this->bias;
        for ($j = 0; $j < $d; $j++) {
            $val = (float)($x[$j] ?? 0.0);
            $mean = (float)($this->featureMeans[$j] ?? 0.0);
            $std = (float)($this->featureStds[$j] ?? 1.0);
            $norm = ($val - $mean) / $std;
            $z += $this->weights[$j] * $norm;
        }

        // Apply Platt calibration: p = 1 / (1 + exp(A * z + B))
        $calibLogit = $this->plattA * $z + $this->plattB;
        $prob = 1.0 / (1.0 + exp(-max(-15.0, min(15.0, $calibLogit))));

        return max(0.01, min(0.99, $prob));
    }

    /**
     * Comprehensive evaluation of model on dataset.
     *
     * @param array<array<float>> $X Matrix of feature vectors
     * @param array<int> $y Vector of true labels
     * @return array Evaluation metrics
     */
    public function evaluate(array $X, array $y): array
    {
        $n = count($X);
        if ($n === 0 || count($y) !== $n) {
            return ['error' => 'Invalid evaluation set'];
        }

        $probs = [];
        $brierSum = 0.0;
        $tp = 0; $fp = 0; $tn = 0; $fn = 0;

        for ($i = 0; $i < $n; $i++) {
            $p = $this->predictProb($X[$i]);
            $probs[] = $p;
            $actual = (int)$y[$i];

            $brierSum += ($p - $actual) ** 2;

            $predClass = $p >= 0.50 ? 1 : 0;
            if ($predClass === 1 && $actual === 1) $tp++;
            elseif ($predClass === 1 && $actual === 0) $fp++;
            elseif ($predClass === 0 && $actual === 0) $tn++;
            else $fn++;
        }

        $brierScore = $brierSum / $n;
        $accuracy = ($tp + $tn) / $n;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0.0;

        // Compute Expected Calibration Error (ECE) across 10 bins
        $eceResult = $this->computeEce($probs, $y, 10);

        // Compute AUC-ROC via Wilcoxon-Mann-Whitney
        $auc = $this->computeAuc($probs, $y);

        return [
            'sample_count'     => $n,
            'accuracy'         => round($accuracy * 100, 2),
            'precision'        => round($precision * 100, 2),
            'recall'           => round($recall * 100, 2),
            'f1_score'         => round($f1, 4),
            'brier_score'      => round($brierScore, 5),
            'ece'              => round($eceResult['ece'], 5),
            'auc_roc'          => round($auc, 4),
            'confusion_matrix' => [
                'tp' => $tp,
                'fp' => $fp,
                'tn' => $tn,
                'fn' => $fn,
            ],
            'calibration_bins' => $eceResult['bins'],
        ];
    }

    private function fitPlattScaling(array $logits, array $labels): void
    {
        $n = count($logits);
        if ($n < 10) return;

        // Regularized Platt scaling via gradient ascent
        $a = 1.0;
        $b = 0.0;
        $lr = 0.02;

        for ($iter = 0; $iter < 60; $iter++) {
            $gradA = 0.0;
            $gradB = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $p = 1.0 / (1.0 + exp(-max(-15.0, min(15.0, $a * $logits[$i] + $b))));
                $diff = (float)$labels[$i] - $p;
                $gradA += $diff * $logits[$i];
                $gradB += $diff;
            }
            $a += ($lr * $gradA) / $n;
            $b += ($lr * $gradB) / $n;
        }

        $this->plattA = max(0.1, min(5.0, $a));
        $this->plattB = max(-5.0, min(5.0, $b));
    }

    private function computeEce(array $probs, array $labels, int $numBins = 10): array
    {
        $n = count($probs);
        $bins = [];
        $binSize = 1.0 / $numBins;

        for ($b = 0; $b < $numBins; $b++) {
            $minP = $b * $binSize;
            $maxP = ($b + 1) * $binSize;
            $indices = [];

            for ($i = 0; $i < $n; $i++) {
                if ($probs[$i] >= $minP && ($b === $numBins - 1 ? $probs[$i] <= $maxP : $probs[$i] < $maxP)) {
                    $indices[] = $i;
                }
            }

            $count = count($indices);
            $meanP = 0.0;
            $empWin = 0.0;
            if ($count > 0) {
                $pSum = 0.0;
                $winSum = 0;
                foreach ($indices as $idx) {
                    $pSum += $probs[$idx];
                    $winSum += $labels[$idx];
                }
                $meanP = $pSum / $count;
                $empWin = $winSum / $count;
            }

            $bins[] = [
                'bin'         => $b,
                'min_prob'    => round($minP, 3),
                'max_prob'    => round($maxP, 3),
                'mean_prob'   => round($meanP, 4),
                'actual_rate' => round($empWin, 4),
                'count'       => $count,
            ];
        }

        $ece = 0.0;
        foreach ($bins as $bin) {
            if ($bin['count'] > 0) {
                $ece += ($bin['count'] / $n) * abs($bin['mean_prob'] - $bin['actual_rate']);
            }
        }

        return ['ece' => $ece, 'bins' => $bins];
    }

    private function computeAuc(array $probs, array $labels): float
    {
        $pos = [];
        $neg = [];
        $n = count($probs);
        for ($i = 0; $i < $n; $i++) {
            if ($labels[$i] === 1) $pos[] = $probs[$i];
            else $neg[] = $probs[$i];
        }
        $nPos = count($pos);
        $nNeg = count($neg);
        if ($nPos === 0 || $nNeg === 0) return 0.50;

        $rankSum = 0.0;
        foreach ($pos as $p) {
            foreach ($neg as $q) {
                if ($p > $q) $rankSum += 1.0;
                elseif ($p == $q) $rankSum += 0.5;
            }
        }
        return $rankSum / ($nPos * $nNeg);
    }

    public function serialize(): array
    {
        return [
            'weights'       => $this->weights,
            'bias'          => $this->bias,
            'platt_a'       => $this->plattA,
            'platt_b'       => $this->plattB,
            'feature_means' => $this->featureMeans,
            'feature_stds'  => $this->featureStds,
            'is_trained'    => $this->isTrained,
        ];
    }

    public static function deserialize(array $data): self
    {
        $m = new self($data['weights'] ?? [], (float)($data['bias'] ?? 0.0));
        $m->plattA = (float)($data['platt_a'] ?? 1.0);
        $m->plattB = (float)($data['platt_b'] ?? 0.0);
        $m->featureMeans = $data['feature_means'] ?? [];
        $m->featureStds = $data['feature_stds'] ?? [];
        $m->isTrained = (bool)($data['is_trained'] ?? false);
        return $m;
    }
}
