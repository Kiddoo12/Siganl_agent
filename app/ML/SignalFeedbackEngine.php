<?php
declare(strict_types=1);

namespace App\ML;

use App\Core\Database;
use App\Core\Logger;

/**
 * Phase 4 — Closed Trade Feedback, Root-Cause Error Analysis & Retraining Diagnostics.
 */
final class SignalFeedbackEngine
{
    public const ERR_NONE                  = 'NONE';
    public const ERR_REGIME_MISMATCH        = 'REGIME_MISMATCH';
    public const ERR_LIQUIDITY_RUN          = 'LIQUIDITY_RUN';
    public const ERR_DISPLACEMENT_FAILURE  = 'DISPLACEMENT_FAILURE';
    public const ERR_NEWS_SLIPPAGE          = 'NEWS_SLIPPAGE';
    public const ERR_PROBABILITY_DRIFT      = 'PROBABILITY_DRIFT';
    public const ERR_PREMATURE_TRIGGER      = 'PREMATURE_TRIGGER';
    public const ERR_NORMAL_STOP            = 'NORMAL_STOP';

    /**
     * Record a point-in-time feature snapshot when a signal is evaluated or generated.
     */
    public function recordSnapshot(
        ?int $signalId,
        string $symbol,
        string $timeframe,
        int $snapshotTs,
        float $price,
        string $regime,
        string $session,
        array $features,
        ?float $predProb,
        ?float $expectedNetR,
        string $decision,
        string $reason = '',
        ?int $modelVersionId = null
    ): int {
        $nowSql = gmdate('Y-m-d H:i:s');
        $snapSql = gmdate('Y-m-d H:i:s', $snapshotTs);

        return (int)Database::insert('ml_feature_snapshots', [
            'signal_id'          => $signalId,
            'symbol'             => $symbol,
            'timeframe'          => $timeframe,
            'snapshot_time'      => $snapSql,
            'price'              => $price,
            'regime'             => $regime,
            'session'            => $session,
            'features_json'      => json_encode($features),
            'predicted_win_prob' => $predProb,
            'expected_net_r'     => $expectedNetR,
            'decision'           => in_array($decision, ['BUY', 'SELL', 'NO_TRADE'], true) ? $decision : 'NO_TRADE',
            'decision_reason'    => mb_substr($reason, 0, 250),
            'model_version_id'   => $modelVersionId,
            'created_at'         => $nowSql,
        ]);
    }

    /**
     * Diagnose a closed trade outcome and persist an audit report.
     */
    public function recordClosedTrade(
        int $signalId,
        string $outcome,
        float $realizedR,
        float $mfeR,
        float $maeR,
        float $frictionR = 0.05,
        ?int $modelVersionId = null
    ): array {
        $sig = Database::fetch('SELECT * FROM trading_signals WHERE id = ?', [$signalId]);
        if (!$sig) {
            return ['error' => "Signal #$signalId not found"];
        }

        $snapshot = Database::fetch(
            'SELECT * FROM ml_feature_snapshots WHERE signal_id = ? ORDER BY id DESC LIMIT 1',
            [$signalId]
        );
        $features = $snapshot ? json_decode((string)$snapshot['features_json'], true) : [];

        // Diagnose root cause
        $diagnosis = $this->diagnoseFailure($outcome, $realizedR, $mfeR, $maeR, $sig, $features);

        $nowSql = gmdate('Y-m-d H:i:s');
        Database::insert('signal_error_reports', [
            'signal_id'             => $signalId,
            'model_version_id'      => $modelVersionId ?? ($snapshot['model_version_id'] ?? null),
            'outcome'               => $outcome,
            'failure_category'      => $diagnosis['category'],
            'realized_r'            => $realizedR,
            'max_adverse_r'         => $maeR,
            'max_favorable_r'       => $mfeR,
            'friction_cost_r'       => $frictionR,
            'root_cause_analysis'   => $diagnosis['root_cause'],
            'remedy_recommendation' => $diagnosis['recommendation'],
            'created_at'            => $nowSql,
        ]);

        return $diagnosis;
    }

    /**
     * Categorize why a trade failed or succeeded.
     */
    public function diagnoseFailure(
        string $outcome,
        float $realizedR,
        float $mfeR,
        float $maeR,
        array $sig,
        array $features
    ): array {
        if ($outcome === 'win' || $realizedR > 0.2) {
            return [
                'category'       => self::ERR_NONE,
                'root_cause'     => 'Trade followed institutional order flow. Reached target with controlled drawdown.',
                'recommendation' => 'Reinforce current model feature weights.',
            ];
        }

        if ($outcome === 'breakeven') {
            return [
                'category'       => self::ERR_NONE,
                'root_cause'     => 'Trade reached TP1, locked initial profit, and closed at breakeven on pullback.',
                'recommendation' => 'Consider adjusting TP2 runner trailing rules or partial close percentage.',
            ];
        }

        $session = (string)($sig['session'] ?? '');
        $regime = (string)($sig['regime'] ?? ($features['volatility_regime_num'] ?? 0));
        $hasDisplacement = (int)($features['has_displacement'] ?? 1);
        $predProb = (float)($sig['predicted_win_prob'] ?? ($features['predicted_win_prob'] ?? 0.55));
        $spreadCost = (float)($features['spread_cost_ratio'] ?? 0.05);

        // 1. News or High Slippage Check
        if ($spreadCost > 0.20 || !empty($sig['warnings']) && stripos(json_encode($sig['warnings']), 'news') !== false) {
            return [
                'category'       => self::ERR_NEWS_SLIPPAGE,
                'root_cause'     => 'Execution friction or high-impact news event caused spread widening and slippage.',
                'recommendation' => 'Expand news blackout filter buffer from 30m to 45m before and after high-impact USD events.',
            ];
        }

        // 2. Regime Mismatch Check (e.g. trend strategy executed in ranging/chop)
        if ($regime === 'ranging_consolidation' || (is_numeric($regime) && (float)$regime === 0.0)) {
            return [
                'category'       => self::ERR_REGIME_MISMATCH,
                'root_cause'     => 'Trend continuation model deployed during low-volatility ranging consolidation.',
                'recommendation' => 'Increase ADX / trend strength filter threshold before activating breakout models.',
            ];
        }

        // 3. Liquidity Run (Deep sweep beyond SL before reversal)
        if ($maeR > 1.0 && $mfeR > 1.5) {
            return [
                'category'       => self::ERR_LIQUIDITY_RUN,
                'root_cause'     => 'Stop loss placed inside resting liquidity pool. Price swept stop before reversing towards original target.',
                'recommendation' => 'Anchor stop loss strictly beyond external swing liquidity points plus 0.4 ATR buffer.',
            ];
        }

        // 4. Displacement Failure (Breakout lacked institutional volume)
        if ($hasDisplacement === 0) {
            return [
                'category'       => self::ERR_DISPLACEMENT_FAILURE,
                'root_cause'     => 'Breakout candle lacked minimum displacement body size (>1.4x ATR). Probable liquidity trap.',
                'recommendation' => 'Reject setups where breakout leg body is smaller than 1.4x ATR.',
            ];
        }

        // 5. Probability Drift
        if ($predProb < 0.52) {
            return [
                'category'       => self::ERR_PROBABILITY_DRIFT,
                'root_cause'     => "Model calibrated win probability ($predProb) was below safety threshold.",
                'recommendation' => 'Retrain model weights and enforce strict min_prob >= 0.52 gate.',
            ];
        }

        // 6. Normal Stop (Variance)
        return [
            'category'       => self::ERR_NORMAL_STOP,
            'root_cause'     => 'Setup met all institutional criteria. Stopped out due to normal market variance.',
            'recommendation' => 'Maintain position sizing discipline (0.5% max risk per trade). No parameter curve-fitting.',
        ];
    }

    /**
     * Generate an aggregate error report grouped by failure category, regime, and session.
     */
    public function generateReport(?string $symbol = null): array
    {
        $symbol = $symbol ?? 'XAUUSD';

        $records = Database::fetchAll(
            'SELECT e.*, s.symbol, s.entry_tf, s.setup_type, s.session, s.direction, s.quality_score
             FROM signal_error_reports e
             JOIN trading_signals s ON s.id = e.signal_id
             WHERE s.symbol = ?
             ORDER BY e.id DESC LIMIT 500',
            [$symbol]
        );

        $byCategory = [];
        $bySession = [];
        $bySetupType = [];
        $totalLosses = 0;
        $totalLossR = 0.0;

        foreach ($records as $r) {
            $cat = (string)$r['failure_category'];
            $sess = (string)($r['session'] ?: 'Unknown');
            $setup = (string)($r['setup_type'] ?: 'Standard SMC');
            $realR = (float)$r['realized_r'];

            if ($realR < 0) {
                $totalLosses++;
                $totalLossR += abs($realR);
            }

            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            $bySession[$sess] = ($bySession[$sess] ?? 0) + 1;
            $bySetupType[$setup] = ($bySetupType[$setup] ?? 0) + 1;
        }

        arsort($byCategory);
        arsort($bySession);
        arsort($bySetupType);

        return [
            'total_analyzed'     => count($records),
            'total_losses'       => $totalLosses,
            'total_loss_r'       => round($totalLossR, 2),
            'by_category'        => $byCategory,
            'by_session'         => $bySession,
            'by_setup_type'      => $bySetupType,
            'recent_diagnostics' => array_slice($records, 0, 15),
        ];
    }
}
