<?php
declare(strict_types=1);

namespace App\ML;

use App\Analysis\RegimeDetector;
use App\Core\Settings;

/**
 * Phase 6 — Quantitative Trade Selection & Expected Value (EV) Decision Framework.
 *
 * Decides strictly between BUY, SELL, and NO TRADE.
 * Evaluates calibrated outcome probability, expected net return after friction,
 * market regime compatibility, and downside risk.
 */
final class ExpectedValueFilter
{
    private ProbabilisticModel $model;
    private float $minCalibratedProb;
    private float $minExpectedNetR;
    private float $defaultSpread;
    private float $defaultSlippage;
    private float $defaultCommR;

    public function __construct(
        ?ProbabilisticModel $model = null,
        float $minCalibratedProb = 0.52,
        float $minExpectedNetR = 0.20,
        float $defaultSpread = 0.30,
        float $defaultSlippage = 0.10,
        float $defaultCommR = 0.02
    ) {
        $this->model = $model ?? new ProbabilisticModel();
        $this->minCalibratedProb = (float)Settings::get('ml.min_prob', $minCalibratedProb);
        $this->minExpectedNetR = (float)Settings::get('ml.min_ev_r', $minExpectedNetR);
        $this->defaultSpread = $defaultSpread;
        $this->defaultSlippage = $defaultSlippage;
        $this->defaultCommR = $defaultCommR;
    }

    /**
     * Evaluate a candidate setup and return an authoritative decision.
     *
     * @param array $setup Candidate trade setup parameters
     * @param array $featureVector Quantified 25-feature vector from FeatureExtractor
     * @param array|null $regimeCtx Output from RegimeDetector
     * @return array{
     *   decision: string,
     *   authorized: bool,
     *   calibrated_prob: float,
     *   expected_net_r: float,
     *   target_r: float,
     *   friction_r: float,
     *   reasons: array<string>,
     *   metrics: array
     * }
     */
    public function evaluate(array $setup, array $featureVector, ?array $regimeCtx = null): array
    {
        $rawDir = strtoupper(trim((string)($setup['direction'] ?? 'WAIT')));
        $entry = (float)($setup['entry'] ?? ($setup['entry_price'] ?? 0.0));
        $sl = (float)($setup['sl'] ?? ($setup['stop_loss'] ?? 0.0));
        $tp1 = (float)($setup['tp1'] ?? 0.0);
        $tp2 = (float)($setup['tp2'] ?? 0.0);
        $risk = abs($entry - $sl);

        $rejections = [];

        // 1. Sanity Checks on Setup Geometry
        if (!in_array($rawDir, ['BUY', 'SELL'], true) || $entry <= 0 || $sl <= 0 || $risk <= 0.05) {
            return $this->noTrade('Incomplete setup parameters: Invalid entry, stop loss, or direction.', 0.0, 0.0);
        }

        // 2. Regime Filter (Phase 3 & 5)
        $regime = $regimeCtx['regime'] ?? RegimeDetector::REGIME_RANGING;
        if (!empty($regimeCtx['circuit_breaker_active']) || $regime === RegimeDetector::REGIME_HIGH_VOLATILITY) {
            $rejections[] = 'Regime Circuit Breaker Active: Abnormal volatility or high-impact news hazard.';
        }

        // 3. Friction & Target Multiples
        $spread = (float)($setup['spread'] ?? $this->defaultSpread);
        $slippage = (float)($setup['slippage'] ?? $this->defaultSlippage);
        $frictionDollars = $spread + $slippage;
        $frictionR = ($risk > 0 ? ($frictionDollars / $risk) : 0.05) + $this->defaultCommR;

        // Weighted target multiple (50% partial at TP1, 50% runner to TP2)
        $rr1 = $risk > 0 && $tp1 > 0 ? (abs($tp1 - $entry) / $risk) : 1.5;
        $rr2 = $risk > 0 && $tp2 > 0 ? (abs($tp2 - $entry) / $risk) : 3.0;
        $targetR = (0.5 * $rr1) + (0.5 * $rr2);

        // 4. Model Prediction: Calibrated Win Probability P(Win | x)
        $pWin = $this->model->predictProb($featureVector);
        $pLoss = 1.0 - $pWin;

        // 5. Expected Net Value Computation
        // Net win return = targetR - frictionR
        // Net loss return = -(1.0 + frictionR)
        $netWinR = max(0.0, $targetR - $frictionR);
        $netLossR = 1.0 + $frictionR;
        $expectedNetR = ($pWin * $netWinR) - ($pLoss * $netLossR);

        // 6. Validation Threshold Checks
        if ($pWin < $this->minCalibratedProb) {
            $rejections[] = sprintf(
                'Insufficient calibrated probability: Model estimated %.1f%% win probability (minimum required: %.1f%%).',
                $pWin * 100.0,
                $this->minCalibratedProb * 100.0
            );
        }

        if ($expectedNetR < $this->minExpectedNetR) {
            $rejections[] = sprintf(
                'Negative or sub-threshold expectancy: Expected net return is %+.2fR after execution friction (minimum required: %+.2fR).',
                $expectedNetR,
                $this->minExpectedNetR
            );
        }

        if ($targetR < 1.5) {
            $rejections[] = sprintf('Risk-to-Reward ratio below institutional floor (Target: %.2fR, floor: 1.50R).', $targetR);
        }

        // Final Trade Decision
        $authorized = empty($rejections);
        $decision = $authorized ? $rawDir : 'NO_TRADE';

        return [
            'decision'        => $decision,
            'authorized'      => $authorized,
            'calibrated_prob' => round($pWin, 4),
            'expected_net_r'  => round($expectedNetR, 4),
            'target_r'        => round($targetR, 3),
            'friction_r'      => round($frictionR, 4),
            'reasons'         => $authorized
                ? [
                    sprintf('Probabilistic edge confirmed: P(Win) = %.1f%% with EV = %+.2fR net of friction.', $pWin * 100.0, $expectedNetR),
                    sprintf('Execution targets: TP1 %.2fR, TP2 %.2fR (Effective Target %.2fR, Friction: %.2fR).', $rr1, $rr2, $targetR, $frictionR),
                ]
                : $rejections,
            'metrics'         => [
                'p_win'          => round($pWin, 4),
                'p_loss'         => round($pLoss, 4),
                'net_win_r'      => round($netWinR, 3),
                'net_loss_r'     => round($netLossR, 3),
                'expected_net_r' => round($expectedNetR, 4),
                'risk_usd'       => round($risk, 2),
                'friction_usd'   => round($frictionDollars, 2),
            ],
        ];
    }

    private function noTrade(string $reason, float $pWin, float $ev): array
    {
        return [
            'decision'        => 'NO_TRADE',
            'authorized'      => false,
            'calibrated_prob' => round($pWin, 4),
            'expected_net_r'  => round($ev, 4),
            'target_r'        => 0.0,
            'friction_r'      => 0.0,
            'reasons'         => [$reason],
            'metrics'         => [],
        ];
    }
}
