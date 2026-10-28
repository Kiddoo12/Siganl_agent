<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Settings;

/**
 * Market Regime Detection & Adaptive Strategy Selection.
 *
 * Implements Section 3 of MASTER PROMPT:
 *  - Detects trending, ranging, high-volatility, and abnormal-market conditions.
 *  - Enables only strategy models appropriate to the current market regime.
 *  - Monitors performance drift and automatically disables a strategy when its
 *    predefined risk or degradation limits are breached (circuit breaker).
 *  - Forbids automatic parameter curve-fitting; requires fresh quantitative validation.
 */
final class RegimeDetector
{
    public const REGIME_TRENDING_BULLISH  = 'trending_bullish';
    public const REGIME_TRENDING_BEARISH  = 'trending_bearish';
    public const REGIME_RANGING            = 'ranging_consolidation';
    public const REGIME_HIGH_VOLATILITY   = 'high_volatility_abnormal';

    public const ALL_REGIMES = [
        self::REGIME_TRENDING_BULLISH,
        self::REGIME_TRENDING_BEARISH,
        self::REGIME_RANGING,
        self::REGIME_HIGH_VOLATILITY,
    ];

    /**
     * Map of strategy models to authorized market regimes.
     */
    private const STRATEGY_REGIME_COMPATIBILITY = [
        'ict_2022_model'     => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_silver_bullet'  => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_unicorn'        => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_unicorn_model'  => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_ote'            => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_judas_swing'    => [self::REGIME_RANGING],
        'ict_power_of_three' => [self::REGIME_RANGING, self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_turtle_soup'    => [self::REGIME_RANGING],
        'ict_liquidity_raid' => [self::REGIME_RANGING],
        'ict_smt_divergence' => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH, self::REGIME_RANGING],
        'smc_breaker_block'  => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'ict_breaker_block'  => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
        'smc_inverse_fvg'    => [self::REGIME_TRENDING_BULLISH, self::REGIME_TRENDING_BEARISH],
    ];

    /**
     * Detect market regime from closed candles and indicators.
     *
     * @param array $candles Closed OHLCV candles
     * @param array|null $trendReport Optional pre-computed trend analysis report
     * @param array|null $newsCtx Context for news hazards
     * @return array Regime classification and adaptive strategy authorization
     */
    public function detect(array $candles, ?array $trendReport = null, ?array $newsCtx = null): array
    {
        if (count($candles) < 30 && $trendReport === null) {
            return [
                'regime'                 => self::REGIME_RANGING,
                'label'                  => 'Ranging / Consolidation (Insufficient Data)',
                'circuit_breaker'        => false,
                'circuit_breaker_active' => false,
                'authorized_models'      => ['ict_turtle_soup', 'ict_power_of_three', 'ict_liquidity_raid'],
                'blocked_models'         => [],
                'reasons'                => ['Insufficient candles for full regime detection; conservative mode active.'],
            ];
        }

        $trendEngine = new TrendAnalysisEngine();
        $trend = $trendReport ?? $trendEngine->analyze($candles);

        $atrRatio = (float)($trend['volatility']['ratio_vs_avg'] ?? 1.0);
        $highVolThreshold = (float)Settings::get('regimes.high_vol_atr_mult', 2.2);

        // 1. High-Volatility / Abnormal Condition Check (Circuit Breaker)
        $hasNewsHazard = !empty($newsCtx['blocking']);
        $extremeVolatility = $atrRatio >= $highVolThreshold;

        if ($extremeVolatility || $hasNewsHazard) {
            $reason = $hasNewsHazard
                ? sprintf('High-impact economic news hazard active (%s)', is_string($newsCtx['blocking']) ? $newsCtx['blocking'] : ($newsCtx['blocking']['title'] ?? 'USD Event'))
                : sprintf('Extreme volatility anomaly: Current ATR is %.2fx historical baseline (threshold: %.1fx)', $atrRatio, $highVolThreshold);

            return [
                'regime'                 => self::REGIME_HIGH_VOLATILITY,
                'label'                  => 'High-Volatility / Abnormal Hazard',
                'circuit_breaker'        => true,
                'circuit_breaker_active' => true,
                'authorized_models'      => [], // All models blocked under abnormal conditions
                'blocked_models'         => array_keys(self::STRATEGY_REGIME_COMPATIBILITY),
                'reasons'                => [
                    $reason,
                    'Regime Circuit Breaker ACTIVE: New order flow entries suspended to protect capital.',
                ],
            ];
        }

        // 2. Trending Regimes
        $class = $trend['classification'] ?? '';
        if (str_contains($class, 'Bullish')) {
            $authorized = [];
            $blocked = [];
            foreach (self::STRATEGY_REGIME_COMPATIBILITY as $mod => $regs) {
                if (in_array(self::REGIME_TRENDING_BULLISH, $regs, true)) {
                    $authorized[] = $mod;
                } else {
                    $blocked[] = $mod;
                }
            }

            return [
                'regime'                 => self::REGIME_TRENDING_BULLISH,
                'label'                  => 'Trending Bullish Regime',
                'circuit_breaker'        => false,
                'circuit_breaker_active' => false,
                'authorized_models'      => $authorized,
                'blocked_models'         => $blocked,
                'reasons'                => [
                    'EMA ribbon stacked bullish with sequential Higher Highs (HH) / Higher Lows (HL).',
                    'Authorized trend continuation models: ICT 2022, Unicorn, Breaker, OTE, and Silver Bullet.',
                    'Mean-reversion range models suspended to prevent counter-trend stopouts.',
                ],
            ];
        }

        if (str_contains($class, 'Bearish')) {
            $authorized = [];
            $blocked = [];
            foreach (self::STRATEGY_REGIME_COMPATIBILITY as $mod => $regs) {
                if (in_array(self::REGIME_TRENDING_BEARISH, $regs, true)) {
                    $authorized[] = $mod;
                } else {
                    $blocked[] = $mod;
                }
            }

            return [
                'regime'                 => self::REGIME_TRENDING_BEARISH,
                'label'                  => 'Trending Bearish Regime',
                'circuit_breaker'        => false,
                'circuit_breaker_active' => false,
                'authorized_models'      => $authorized,
                'blocked_models'         => $blocked,
                'reasons'                => [
                    'EMA ribbon stacked bearish with sequential Lower Highs (LH) / Lower Lows (LL).',
                    'Authorized short trend-following models: ICT 2022, Unicorn, Breaker, OTE, and Silver Bullet.',
                    'Counter-trend long setups suspended.',
                ],
            ];
        }

        // 3. Ranging / Consolidation Regime (Default)
        $authorized = [];
        $blocked = [];
        foreach (self::STRATEGY_REGIME_COMPATIBILITY as $mod => $regs) {
            if (in_array(self::REGIME_RANGING, $regs, true)) {
                $authorized[] = $mod;
            } else {
                $blocked[] = $mod;
            }
        }

        return [
            'regime'                 => self::REGIME_RANGING,
            'label'                  => 'Ranging / Consolidation Regime',
            'circuit_breaker'        => false,
            'circuit_breaker_active' => false,
            'authorized_models'      => $authorized,
            'blocked_models'         => $blocked,
            'reasons'                => [
                'Price oscillating in range between key dealing range levels without directional displacement.',
                'Authorized mean-reversion models: ICT Turtle Soup, Judas Swing, and Range Liquidity Sweeps.',
                'Trend continuation breakout models suspended to avoid false breakout whipsaws.',
            ],
        ];
    }

    /**
     * Check strategy degradation and performance drift.
     * Automatically disables a strategy if predefined risk/degradation limits are breached.
     *
     * @param string $strategyCode
     * @param array $recentTrades Array of recent trade outcomes for this strategy
     * @return array Status report with degradation check
     */
    public function evaluateStrategyDegradation(string $strategyCode, array $recentTrades): array
    {
        $maxStreak = (int)Settings::get('regimes.degradation_loss_streak', 4);
        $winRateFloor = (float)Settings::get('regimes.degradation_win_rate_floor', 30.0);

        // Calculate consecutive losses on most recent closed trades
        $consecutiveLosses = 0;
        foreach ($recentTrades as $t) {
            $out = strtolower((string)($t['outcome'] ?? ''));
            if ($out === 'loss') {
                $consecutiveLosses++;
            } elseif ($out === 'win') {
                break;
            }
        }

        // Calculate rolling 10-trade win rate
        $rollingSample = array_slice($recentTrades, 0, 10);
        $resolved = 0;
        $wins = 0;
        foreach ($rollingSample as $t) {
            $out = strtolower((string)($t['outcome'] ?? ''));
            if (in_array($out, ['win', 'loss'], true)) {
                $resolved++;
                if ($out === 'win') $wins++;
            }
        }
        $rollingWinRate = $resolved >= 5 ? ($wins / $resolved) * 100.0 : 50.0;

        $isStreakBreached = $consecutiveLosses >= $maxStreak;
        $isWinRateBreached = $resolved >= 8 && $rollingWinRate < $winRateFloor;

        $degraded = $isStreakBreached || $isWinRateBreached;
        $reason = null;
        if ($isStreakBreached) {
            $reason = sprintf('Degradation limit breached: %d consecutive losses (limit: %d).', $consecutiveLosses, $maxStreak);
        } elseif ($isWinRateBreached) {
            $reason = sprintf('Performance drift: Rolling win rate %.1f%% dropped below minimum floor %.1f%%.', $rollingWinRate, $winRateFloor);
        }

        return [
            'strategy_code'       => $strategyCode,
            'is_degraded'         => $degraded,
            'status'              => $degraded ? 'paused' : 'active',
            'consecutive_losses'  => $consecutiveLosses,
            'rolling_win_rate'    => round($rollingWinRate, 1),
            'resolved_sample'     => $resolved,
            'reason'              => $reason,
            'revalidation_needed' => $degraded,
        ];
    }
}
