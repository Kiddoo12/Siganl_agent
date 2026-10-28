<?php
/**
 * Default analysis / risk / notification parameters.
 * Admins can override any key from the Admin dashboard; overrides are stored
 * in the `system_settings` table and merged on top of these defaults.
 * All values are configurable filters, NOT universal market laws.
 */
return [
    'market' => [
        'symbol'            => 'XAUUSD',
        'symbols'           => [
            'XAUUSD' => [
                'code'              => 'XAUUSD',
                'display'           => 'XAU/USD',
                'name'              => 'Spot Gold',
                'twelvedata_symbol' => 'XAU/USD',
                'contract_size'     => 100.0,
                'unit'              => 'oz',
                'assumed_spread'    => 0.30,
                'max_spread'        => 0.60,
                'demo_base'         => 4107.0,
                'demo_scale'        => 1.0,
                'is_24_7'           => false,
            ],
            'BTCUSD' => [
                'code'              => 'BTCUSD',
                'display'           => 'BTC/USD',
                'name'              => 'Bitcoin / US Dollar',
                'twelvedata_symbol' => 'BTC/USD',
                'contract_size'     => 1.0,
                'unit'              => 'BTC',
                'assumed_spread'    => 15.00,
                'max_spread'        => 60.00,
                'demo_base'         => 82300.0,
                'demo_scale'        => 28.0,
                'is_24_7'           => true,
            ],
            'EURUSD' => [
                'code'              => 'EURUSD',
                'display'           => 'EUR/USD',
                'name'              => 'Euro / US Dollar',
                'twelvedata_symbol' => 'EUR/USD',
                'contract_size'     => 100000.0,
                'unit'              => 'EUR',
                'assumed_spread'    => 0.00015,
                'max_spread'        => 0.00040,
                'demo_base'         => 1.0850,
                'demo_scale'        => 0.0008,
                'is_24_7'           => false,
            ],
            'GBPUSD' => [
                'code'              => 'GBPUSD',
                'display'           => 'GBP/USD',
                'name'              => 'British Pound / US Dollar',
                'twelvedata_symbol' => 'GBP/USD',
                'contract_size'     => 100000.0,
                'unit'              => 'GBP',
                'assumed_spread'    => 0.00020,
                'max_spread'        => 0.00050,
                'demo_base'         => 1.2980,
                'demo_scale'        => 0.0010,
                'is_24_7'           => false,
            ],
            'USDJPY' => [
                'code'              => 'USDJPY',
                'display'           => 'USD/JPY',
                'name'              => 'US Dollar / Japanese Yen',
                'twelvedata_symbol' => 'USD/JPY',
                'contract_size'     => 100000.0,
                'unit'              => 'USD',
                'assumed_spread'    => 0.015,
                'max_spread'        => 0.040,
                'demo_base'         => 152.40,
                'demo_scale'        => 0.15,
                'is_24_7'           => false,
            ],
            'US30' => [
                'code'              => 'US30',
                'display'           => 'US30 (Dow)',
                'name'              => 'Dow Jones Industrial Average',
                'twelvedata_symbol' => 'DJI',
                'contract_size'     => 1.0,
                'unit'              => 'pt',
                'assumed_spread'    => 2.50,
                'max_spread'        => 8.00,
                'demo_base'         => 43800.0,
                'demo_scale'        => 25.0,
                'is_24_7'           => false,
            ],
            'NAS100' => [
                'code'              => 'NAS100',
                'display'           => 'NAS100 (Nasdaq)',
                'name'              => 'Nasdaq 100 Index',
                'twelvedata_symbol' => 'NDX',
                'contract_size'     => 1.0,
                'unit'              => 'pt',
                'assumed_spread'    => 1.50,
                'max_spread'        => 5.00,
                'demo_base'         => 20450.0,
                'demo_scale'        => 18.0,
                'is_24_7'           => false,
            ],
        ],
        // Timeframe roles (internal codes: M1 M5 M15 M30 H1 H4 D1 W1 MN1)
        'context_tfs'       => ['MN1', 'W1'],
        'bias_tfs'          => ['D1', 'H4'],
        'poi_tf'            => 'H1',
        'entry_tf'          => 'M15',
        'refine_tfs'        => [],          // e.g. ['M5','M1'] (optional, costs API quota)
        'candles_per_tf'    => 500,
        // Refresh cadence in minutes per timeframe (protects provider quota)
        'refresh_minutes'   => ['M1' => 1, 'M5' => 1, 'M15' => 1, 'M30' => 2, 'H1' => 5, 'H4' => 15, 'D1' => 60, 'W1' => 240, 'MN1' => 720],
        'quote_cache_seconds' => 8,
        // Freshness: data considered stale if last closed candle is older than N x timeframe (market open only)
        'stale_multiplier'  => 3,
    ],
    'smc' => [
        'swing_left'            => 3,     // candles to the left of a pivot
        'swing_right'           => 2,     // candles to the right (confirmation delay - faster pivot detection)
        'pd_swing_left'         => 6,     // larger pivots used for dealing range
        'pd_swing_right'        => 6,
        'atr_period'            => 14,
        'break_buffer_atr'      => 0.05,  // close must exceed level by this x ATR to count as BOS/CHOCH
        'displacement_body_ratio' => 0.6, // body / range
        'displacement_atr_mult' => 1.3,   // range >= mult x ATR
        'fvg_min_atr'           => 0.15,  // minimum gap size x ATR
        'ob_lookback'           => 6,     // candles to search back from leg extreme for opposite candle
        'ob_require_displacement' => true,
        'ob_zone'               => 'wick', // wick | body
        'ob_max_age'            => 150,   // candles until an untouched OB expires
        'eq_tolerance_atr'      => 0.10,  // equal highs/lows tolerance x ATR
        'eq_lookback_swings'    => 8,
        'sweep_min_atr'         => 0.02,  // minimum wick penetration x ATR for a sweep
        'eq_band'               => 0.05,  // +/- fraction of range around 50% treated as equilibrium
        'max_items'             => 25,    // max FVG/OB/liquidity items returned per TF
    ],
    'signal' => [
        'setup_lookback'        => 16,    // entry-TF candles in which sweep/BOS must occur (max 4 hrs on M15)
        'required'              => ['htf', 'structure', 'zone', 'sl', 'rr'],
        'min_score'             => 55,
        'strong_score'          => 75,
        'min_rr'                => 2.0,   // at least one target must offer this R:R
        'tp1_min_rr'            => 1.0,
        'entry_mode'            => 'mid', // mid | proximal
        'sl_buffer_atr'         => 0.10,
        'min_sl_atr'            => 0.3,
        'max_sl_atr'            => 4.0,
        'expiry_candles'        => 12,    // entry-TF candles before an unfilled setup expires (max 3 hrs on M15)
        'max_spread'            => 0.60,  // USD per ounce
        'assumed_spread'        => 0.30,  // used when provider does not supply bid/ask
        'vol_spike_ratio'       => 2.0,   // ATR / avg ATR -> warning
        'vol_extreme_ratio'     => 3.5,   // -> blocks new setups
        'news_block_before_min' => 30,
        'news_block_after_min'  => 30,
        'news_currencies'       => ['USD'],
        'weights' => [
            'htf' => 20, 'sweep' => 15, 'structure' => 15, 'displacement' => 10,
            'poi' => 15, 'location' => 10, 'session' => 5, 'spread_vol' => 5, 'news' => 5,
        ],
    ],
    'sessions' => [
        // Local session hours in their own timezone -> DST handled by PHP timezone database
        'asia'    => ['label' => 'Asian',    'tz' => 'Asia/Tokyo',       'start' => '09:00', 'end' => '18:00', 'score' => 2],
        'london'  => ['label' => 'London',   'tz' => 'Europe/London',    'start' => '08:00', 'end' => '17:00', 'score' => 5],
        'newyork' => ['label' => 'New York', 'tz' => 'America/New_York', 'start' => '08:00', 'end' => '17:00', 'score' => 5],
    ],
    'risk' => [
        'contract_size'              => 100,   // ounces per 1.00 lot (verify with your broker)
        'min_lot'                    => 0.01,
        'lot_step'                   => 0.01,
        'max_lot'                    => 50,
        'default_risk_pct'           => 0.5,   // Conservative 0.5% default risk
        'max_risk_pct'               => 2.0,   // Hard cap enforced by the calculator
        'max_daily_loss_pct'         => 2.0,   // Hard daily limit: halts new setups when reached
        'max_portfolio_exposure_pct' => 2.0,   // Maximum aggregate risk across all open trades
        'max_open_positions'         => 2,     // Maximum concurrent open positions
        'commission_per_lot'         => 3.50,  // USD per lot per side ($7.00 round turn)
        'assumed_slippage_handles'   => 0.15,  // Slippage allowance on market fills (XAUUSD)
        'emergency_stop'             => false, // Master emergency halt switch
        'demo_required_before_live'  => true,  // Enforces demo forward validation before real money
        'anti_martingale_enforced'   => true,  // Strictly forbids doubling down or increasing size after loss
    ],
    'validation' => [
        'min_oos_trades'             => 10,    // Minimum out-of-sample trades required for validation
        'min_expectancy_r'           => 0.10,  // Minimum positive net expectancy per trade
        'min_profit_factor'          => 1.25,  // Minimum profit factor
        'min_win_rate_pct'           => 38.0,  // Minimum win rate percentage
        'max_drawdown_pct'           => 15.0,  // Maximum allowable drawdown percentage
        'max_oos_degradation_pct'    => 40.0,  // Max allowable drop between IS and OOS expectancy
        'stress_slippage_handles'    => 0.25,  // Adverse slippage added during stress testing
        'stress_spread_mult'         => 1.5,   // Spread widening multiplier during stress testing
    ],
    'regimes' => [
        'enabled'                    => true,
        'adaptive_selection'         => true,
        'high_vol_atr_mult'          => 2.2,   // Volatility multiplier triggering abnormal market regime
        'degradation_loss_streak'    => 4,     // Consecutive losses triggering strategy pause
        'degradation_win_rate_floor' => 30.0,  // Minimum 10-trade rolling win rate before auto-disabling
    ],
    'tracking' => [
        'partial_at_tp1'    => 0.5,   // fraction closed at TP1 (0 = none)
        'move_be_after_tp1' => true,
        'same_bar_policy'   => 'conservative', // conservative = SL first when SL & TP in same candle
        'breakeven_band_r'  => 0.05,  // |R| below this counts as breakeven
    ],
    'notify' => [
        'cooldown_minutes'   => 30,
        'news_alert_minutes' => 30,
        'telegram_bot_token' => '',
    ],
    'calendar' => [
        'refresh_minutes'   => 60,
    ],
];
