<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Settings;
use App\Market\MarketDataService;

/**
 * Position sizing and R-multiple calculator for XAU/USD (spot gold) and BTC/USD (Bitcoin).
 * Enforces configured broker lot steps and maximum risk percentage caps.
 */
final class RiskCalculator
{
    private array $cfg;
    private string $symbol;
    private string $unit;

    public function __construct(?array $cfg = null, string $symbol = 'XAUUSD')
    {
        $this->symbol = MarketDataService::normalizeSymbol($symbol);
        $meta = MarketDataService::symbolMeta($this->symbol);
        $this->unit = (string)($meta['unit'] ?? ($this->symbol === 'BTCUSD' ? 'BTC' : 'oz'));
        $baseCfg = array_merge([
            'contract_size'              => 100.0,
            'min_lot'                    => 0.01,
            'lot_step'                   => 0.01,
            'max_lot'                    => 50.0,
            'default_risk_pct'           => 0.5,
            'max_risk_pct'               => 2.0,
            'max_daily_loss_pct'         => 2.0,
            'max_portfolio_exposure_pct' => 2.0,
            'max_open_positions'         => 2,
            'commission_per_lot'         => 3.50,
            'emergency_stop'             => false,
            'anti_martingale_enforced'   => true,
        ], $cfg ?? (array)Settings::get('risk', []));
        if ($this->symbol === 'BTCUSD' && !isset($cfg['contract_size'])) {
            $baseCfg['contract_size'] = (float)($meta['contract_size'] ?? 1.0);
        }
        $this->cfg = $baseCfg;
    }

    public function calculate(
        float $balance,
        float $riskPct,
        float $entry,
        float $stopLoss,
        ?float $tp1 = null,
        ?float $tp2 = null,
        ?float $tp3 = null,
        array $portfolioCtx = []
    ): array {
        if ($balance <= 0) {
            return ['ok' => false, 'error' => 'Account balance must be greater than zero.'];
        }
        $stopDistance = abs($entry - $stopLoss);
        if ($stopDistance < 0.01) {
            return ['ok' => false, 'error' => 'Stop loss must be at least $0.01 away from entry.'];
        }

        $warnings = [];
        $maxRiskPct = (float)$this->cfg['max_risk_pct'];
        $requestedPct = $riskPct;
        if ($riskPct <= 0) {
            $riskPct = (float)$this->cfg['default_risk_pct'];
        }
        if ($riskPct > $maxRiskPct) {
            $riskPct = $maxRiskPct;
            $warnings[] = sprintf('Risk capped at %.2f%% (requested %.2f%% exceeds hard limit).', $maxRiskPct, $requestedPct);
        }

        // Anti-Martingale Rule Enforcement (Section 4):
        // Never increase position size or risk % to recover losses.
        if (!empty($this->cfg['anti_martingale_enforced'])) {
            $prevOutcome = $portfolioCtx['last_outcome'] ?? null;
            if ($prevOutcome === 'loss' && $requestedPct > (float)$this->cfg['default_risk_pct']) {
                $riskPct = (float)$this->cfg['default_risk_pct'];
                $warnings[] = sprintf(
                    'Anti-Martingale Rule enforced: Risk clamped to baseline %.2f%% after previous loss. Doubling down or loss-recovery sizing is strictly forbidden.',
                    $riskPct
                );
            }
        }

        $contractSize = max(0.01, (float)$this->cfg['contract_size']);
        $minLot = max(0.001, (float)$this->cfg['min_lot']);
        $lotStep = max(0.001, (float)$this->cfg['lot_step']);
        $maxLot = max($minLot, (float)$this->cfg['max_lot']);
        $commPerLot = max(0.0, (float)$this->cfg['commission_per_lot']);

        $targetRiskUsd = $balance * ($riskPct / 100.0);
        $lossPerLot = $stopDistance * $contractSize;
        $rawLots = $lossPerLot > 0 ? $targetRiskUsd / $lossPerLot : 0.0;

        $steps = floor(($rawLots + 1e-9) / $lotStep);
        $lots = round($steps * $lotStep, 4);
        $belowMinLot = false;

        if ($lots < $minLot) {
            $lots = $minLot;
            $belowMinLot = true;
            $minLotRiskPct = ($minLot * $lossPerLot) / $balance * 100.0;
            $warnings[] = sprintf(
                'Computed size (%.4f lot) is below broker minimum %.2f lot. Using %.2f lot (%.2f%% risk).',
                $rawLots,
                $minLot,
                $minLot,
                $minLotRiskPct
            );
        } elseif ($lots > $maxLot) {
            $lots = $maxLot;
            $warnings[] = sprintf('Position size capped at broker maximum %.2f lots.', $maxLot);
        }

        $actualRiskUsd = round($lots * $lossPerLot, 2);
        $actualRiskPct = round($actualRiskUsd / $balance * 100.0, 3);
        $commissionUsd = round($lots * $commPerLot, 2);
        $direction = $entry >= $stopLoss ? 'BUY' : 'SELL';
        $isBuy = $direction === 'BUY';

        $targets = [];
        foreach ([1 => $tp1, 2 => $tp2, 3 => $tp3] as $idx => $tp) {
            if ($tp === null || $tp <= 0) {
                $targets['tp' . $idx] = null;
                continue;
            }
            $dist = $isBuy ? ($tp - $entry) : ($entry - $tp);
            $grossUsd = $dist * $contractSize * $lots;
            $rr = $stopDistance > 0 ? $dist / $stopDistance : 0.0;
            $targets['tp' . $idx] = [
                'price'          => round($tp, 2),
                'distance'       => round($dist, 2),
                'rr'             => round($rr, 2),
                'profit_usd'     => round($grossUsd, 2),
                'net_profit_usd' => round($grossUsd - $commissionUsd, 2),
                'return_pct'     => round($grossUsd / $balance * 100.0, 2),
            ];
        }

        return [
            'ok'                 => true,
            'symbol'             => $this->symbol,
            'unit'               => $this->unit,
            'direction'          => $direction,
            'balance'            => round($balance, 2),
            'requested_risk_pct' => round($requestedPct, 2),
            'applied_risk_pct'   => round($riskPct, 2),
            'target_risk_usd'    => round($targetRiskUsd, 2),
            'entry'              => round($entry, 2),
            'stop_loss'          => round($stopLoss, 2),
            'stop_distance'      => round($stopDistance, 2),
            'stop_pips'          => round($stopDistance * ($this->symbol === 'BTCUSD' ? 1.0 : 10.0), 1),
            'contract_size'      => $contractSize,
            'raw_lots'           => round($rawLots, 4),
            'lots'               => $lots,
            'ounces'             => round($lots * $contractSize, 4),
            'notional_usd'       => round($lots * $contractSize * $entry, 2),
            'commission_usd'     => $commissionUsd,
            'actual_risk_usd'    => $actualRiskUsd,
            'actual_risk_pct'    => $actualRiskPct,
            'below_min_lot'      => $belowMinLot,
            'targets'            => $targets,
            'warnings'           => $warnings,
        ];
    }

    /**
     * Institutional Risk Limits Evaluator (Section 4):
     * Evaluates emergency stop, daily loss limits, maximum open positions, and portfolio exposure.
     */
    public function evaluatePortfolioLimits(float $balance, array $portfolioCtx = []): array
    {
        $dailyLossLimitPct = (float)Settings::get('risk.max_daily_loss_pct', $this->cfg['max_daily_loss_pct'] ?? 2.0);
        $maxExposurePct    = (float)Settings::get('risk.max_portfolio_exposure_pct', $this->cfg['max_portfolio_exposure_pct'] ?? 2.0);
        $maxOpenPositions  = (int)Settings::get('risk.max_open_positions', $this->cfg['max_open_positions'] ?? 2);
        $isEmergencyStop   = (bool)Settings::get('risk.emergency_stop', !empty($this->cfg['emergency_stop'])) || !empty($portfolioCtx['emergency_stop']);

        if ($isEmergencyStop) {
            return [
                'allowed'               => false,
                'can_trade'             => false,
                'emergency_stop_active' => true,
                'daily_loss_limit_hit'  => false,
                'max_positions_reached' => false,
                'max_exposure_reached'  => false,
                'status'                => 'EMERGENCY_HALT',
                'reason'                => 'Emergency Stop is ACTIVE: All market order generation is halted by risk control.',
            ];
        }

        $todayLossPct = (float)($portfolioCtx['today_realized_loss_pct'] ?? 0.0);
        if ($todayLossPct <= 0.0 && !empty($portfolioCtx['closed_today']) && $balance > 0) {
            $lossSum = 0.0;
            foreach ($portfolioCtx['closed_today'] as $tr) {
                $pnl = (float)($tr['pnl'] ?? 0.0);
                if ($pnl < 0) {
                    $lossSum += abs($pnl);
                }
            }
            $todayLossPct = round(($lossSum / $balance) * 100.0, 2);
        }

        if ($todayLossPct >= $dailyLossLimitPct) {
            return [
                'allowed'               => false,
                'can_trade'             => false,
                'emergency_stop_active' => false,
                'daily_loss_limit_hit'  => true,
                'max_positions_reached' => false,
                'max_exposure_reached'  => false,
                'status'                => 'DAILY_LOSS_BREACHED',
                'reason'                => sprintf('Daily loss limit breached: Realized losses today (%.2f%%) reached threshold (%.2f%%). Trading suspended until next UTC day.', $todayLossPct, $dailyLossLimitPct),
            ];
        }

        $openCount = isset($portfolioCtx['open_positions_count'])
            ? (int)$portfolioCtx['open_positions_count']
            : (isset($portfolioCtx['open_positions']) && is_array($portfolioCtx['open_positions']) ? count($portfolioCtx['open_positions']) : 0);

        if ($openCount >= $maxOpenPositions) {
            return [
                'allowed'               => false,
                'can_trade'             => false,
                'emergency_stop_active' => false,
                'daily_loss_limit_hit'  => false,
                'max_positions_reached' => true,
                'max_exposure_reached'  => false,
                'status'                => 'MAX_POSITIONS_REACHED',
                'reason'                => sprintf('Maximum open positions limit reached (%d/%d). New setups blocked until existing positions close.', $openCount, $maxOpenPositions),
            ];
        }

        $openRiskPct = (float)($portfolioCtx['open_risk_pct'] ?? 0.0);
        if ($openRiskPct >= $maxExposurePct) {
            return [
                'allowed'               => false,
                'can_trade'             => false,
                'emergency_stop_active' => false,
                'daily_loss_limit_hit'  => false,
                'max_positions_reached' => false,
                'max_exposure_reached'  => true,
                'status'                => 'MAX_EXPOSURE_REACHED',
                'reason'                => sprintf('Maximum portfolio exposure reached (%.2f%% / %.2f%%). New setups blocked.', $openRiskPct, $maxExposurePct),
            ];
        }

        return [
            'allowed'               => true,
            'can_trade'             => true,
            'emergency_stop_active' => false,
            'daily_loss_limit_hit'  => false,
            'max_positions_reached' => false,
            'max_exposure_reached'  => false,
            'status'                => 'OK',
            'daily_loss_pct'        => $todayLossPct,
            'daily_limit_pct'       => $dailyLossLimitPct,
            'open_positions'        => $openCount,
            'max_positions'         => $maxOpenPositions,
            'portfolio_risk_pct'    => $openRiskPct,
        ];
    }
}
