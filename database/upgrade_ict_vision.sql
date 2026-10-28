-- =============================================================
-- GOLD SMC AI PRO - ICT Strategy & AI Chart Vision Upgrade Migration
-- =============================================================

USE gold_smc_ai;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. AI Chart Vision Analyses Table
CREATE TABLE IF NOT EXISTS chart_vision_analyses (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NULL,
    symbol              VARCHAR(16) NOT NULL DEFAULT 'XAUUSD',
    timeframe           VARCHAR(8) NOT NULL DEFAULT 'M15',
    image_path          VARCHAR(255) NOT NULL,
    thumbnail_path      VARCHAR(255) NULL,
    manual_price        DECIMAL(12,3) NULL,
    broker              VARCHAR(64) NULL,
    chart_tz            VARCHAR(64) NULL,
    market_bias         ENUM('Bullish','Bearish','Neutral') NOT NULL DEFAULT 'Neutral',
    detected_strategy   VARCHAR(100) NULL,
    entry_zone_low      DECIMAL(12,3) NULL,
    entry_zone_high     DECIMAL(12,3) NULL,
    stop_loss           DECIMAL(12,3) NULL,
    tp1                 DECIMAL(12,3) NULL,
    tp2                 DECIMAL(12,3) NULL,
    tp3                 DECIMAL(12,3) NULL,
    risk_reward         DECIMAL(6,2) NULL,
    confidence_score    TINYINT UNSIGNED NOT NULL DEFAULT 50,
    analysis_json       JSON NOT NULL,
    overlay_json        JSON NULL,
    ai_model            VARCHAR(64) NOT NULL DEFAULT 'heuristic-vision',
    created_at          DATETIME NOT NULL,
    KEY idx_cva_user (user_id, created_at),
    KEY idx_cva_symbol (symbol, timeframe),
    CONSTRAINT fk_cva_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2. ICT Strategies Configuration Table
CREATE TABLE IF NOT EXISTS ict_strategies (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(40) NOT NULL UNIQUE,
    name                VARCHAR(100) NOT NULL,
    category            ENUM('ICT','SMC','HYBRID') NOT NULL DEFAULT 'ICT',
    description         TEXT NOT NULL,
    rules_summary       TEXT NOT NULL,
    is_enabled          TINYINT(1) NOT NULL DEFAULT 1,
    weight              TINYINT UNSIGNED NOT NULL DEFAULT 15,
    timeframes_json     JSON NOT NULL,
    params_json         JSON NULL,
    updated_at          DATETIME NOT NULL
) ENGINE=InnoDB;

-- Pre-populate ICT strategies if empty
INSERT INTO ict_strategies (code, name, category, description, rules_summary, is_enabled, weight, timeframes_json, params_json, updated_at)
VALUES
('ict_2022_model', 'ICT 2022 Mentorship Model', 'ICT', 'High-probability model based on liquidity sweep, sharp displacement creating Market Structure Shift (MSS) with Fair Value Gap (FVG), entering on the FVG retracement.', '1. Sweep BSL or SSL -> 2. Displacement breaking structure (MSS) -> 3. Retest displacement FVG -> 4. Target opposing liquidity.', 1, 20, '["M5","M15","H1"]', '{"min_rr":2.0,"require_sweep":true}', UTC_TIMESTAMP()),

('ict_silver_bullet', 'ICT Silver Bullet', 'ICT', 'Time-based killzone setup active during specific 60-minute windows. Seeks a liquidity sweep and first displacement FVG targeting 10-15+ handles on Gold.', 'Active London 03:00-04:00 NY, NY AM 10:00-11:00 NY, NY PM 14:00-15:00 NY. Retest of first FVG after liquidity raid.', 1, 20, '["M5","M15"]', '{"target_handles":12.0,"require_killzone":true}', UTC_TIMESTAMP()),

('ict_unicorn', 'ICT Unicorn Model', 'ICT', 'One of the highest-conviction ICT setups: formed when a Breaker Block overlaps with a fresh Fair Value Gap (FVG) at the identical price range.', 'Confluence of Breaker Block (failed order block after sweep) + displacement FVG overlapping at the entry zone.', 1, 25, '["M5","M15","H1"]', '{"overlap_min_pct":0.3}', UTC_TIMESTAMP()),

('ict_ote', 'ICT Optimal Trade Entry (OTE)', 'ICT', 'Fibonacci retracement framework targeting entry between 61.8% and 78.6% (sweet spot 70.5%) of a displacement impulse leg in discount/premium.', 'Measure impulse leg from swing low to high. Entry at 0.618 - 0.786 retracement. Stop beyond swing extreme.', 1, 15, '["M15","H1","H4"]', '{"fib_levels":[0.618,0.705,0.786]}', UTC_TIMESTAMP()),

('ict_judas_swing', 'ICT Judas Swing', 'ICT', 'False rally or drop engineered during session open (London or NY Open) that hunts resting liquidity before reversing into the true daily bias.', 'Engineered fakeout in first 30-45 mins of session sweeping Asian range or PDH/PDL, followed by sharp reversal candle.', 1, 15, '["M5","M15"]', '{"max_session_min":45}', UTC_TIMESTAMP()),

('ict_power_of_three', 'ICT Power of Three (AMD)', 'ICT', 'Daily & intraday cycle consisting of Accumulation (consolidation), Manipulation (false breakout/Judas), and Distribution (expansion move to target).', 'Accumulation in Asian/early session -> Manipulation against bias -> Distribution expansion to target liquidity.', 1, 15, '["M15","H1","D1"]', '{"phases":["accumulation","manipulation","distribution"]}', UTC_TIMESTAMP()),

('ict_turtle_soup', 'ICT Turtle Soup (Liquidity Raid)', 'ICT', 'Counter-trend liquidity sweep where price pierces a 20-candle swing extreme or key level by a few pips, fails to close beyond, and rejects immediately.', 'Wick penetration beyond key high/low without body close, followed by candle close back inside previous range.', 1, 15, '["M15","H1"]', '{"min_wick_ratio":0.30}', UTC_TIMESTAMP()),

('ict_smt_divergence', 'ICT SMT Divergence', 'ICT', 'Inter-market smart money divergence: Gold makes a higher high while correlated asset (or inverse DXY) fails to confirm, signaling institutional distribution.', 'Non-confirmation between correlated market structures at key liquidity pools.', 1, 10, '["H1","H4","D1"]', '{"correlated_asset":"BTCUSD"}', UTC_TIMESTAMP()),

('smc_breaker_block', 'SMC Breaker Block', 'SMC', 'Order block that failed after taking liquidity and was broken through with displacement, now serving as dynamic support or resistance on retest.', 'Bearish Breaker: prior high took liquidity, displaced down breaking swing low. Retest of the broken bullish candle.', 1, 15, '["M15","H1"]', '{"require_sweep":true}', UTC_TIMESTAMP()),

('smc_inverse_fvg', 'Inverse Fair Value Gap (IFVG)', 'SMC', 'A Fair Value Gap that price completely closed through, inverting its polarity from support to resistance (or vice-versa) for future retests.', 'Bullish FVG closed below by a candle body flips to Bearish IFVG resistance. Retest confirms entry.', 1, 15, '["M5","M15","H1"]', '{"invert_on_close":true}', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE name=VALUES(name);

SET FOREIGN_KEY_CHECKS = 1;
