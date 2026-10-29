-- Add SNR (Support & Resistance) to ict_strategies table
ALTER TABLE ict_strategies MODIFY COLUMN category ENUM('ICT','SMC','CRT','SNR','HYBRID') NOT NULL;

-- Seed Support and Resistance Strategies
INSERT INTO ict_strategies (code, name, category, description, rules_summary, is_enabled, weight, timeframes_json, params_json, updated_at)
VALUES
(
    'snr_breakout_retest',
    'S&R Confirmed Breakout & Retest',
    'SNR',
    'Trades decisive breakouts beyond major horizontal support or resistance levels followed by a confirmed pullback retest holding as role-reversal support/resistance.',
    '1. Identify major multi-touch S/R level. 2. Confirmed candle body close beyond level (+ buffer). 3. Pullback retests broken level with rejection candle. 4. Enter on retest confirmation with SL behind swing.',
    1,
    85,
    '["M15", "H1", "H4", "D1"]',
    '{"min_touches": 2, "retest_tolerance_pct": 0.20, "min_rr": 1.8}',
    NOW()
),
(
    'snr_false_breakout',
    'S&R False Breakout (Trap Reversal)',
    'SNR',
    'Identifies false breakouts and trap sweeps (Bull Trap / Bear Trap) where price pierces a major S/R level to trigger stop runs but closes back inside with an engulfing/pin-bar rejection.',
    '1. Price pierces major S/R level. 2. Fails to hold and prints strong rejection wick (>=35% of range). 3. Candle body closes back inside the level. 4. Fade fakeout targeting opposite S/R boundary.',
    1,
    85,
    '["M5", "M15", "H1", "H4"]',
    '{"min_rejection_wick": 0.35, "min_touches": 2, "min_rr": 2.0}',
    NOW()
),
(
    'snr_major_bounce',
    'S&R Major Level Bounce Rejection',
    'SNR',
    'Captures mean-reverting bounces from high-confluence horizontal support or resistance zones displaying clean rejection candle formations (Pin Bar, Hammer, Shooting Star).',
    '1. High-confluence S/R zone with >=3 historical touches. 2. Price approaches zone without breaking. 3. Rejection candle closes away from level. 4. Enter targeting range midpoint or opposing boundary.',
    1,
    80,
    '["M15", "H1", "H4"]',
    '{"min_touches": 3, "min_rr": 1.8}',
    NOW()
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    category = VALUES(category),
    description = VALUES(description),
    rules_summary = VALUES(rules_summary),
    weight = VALUES(weight),
    params_json = VALUES(params_json),
    updated_at = NOW();

-- Add SNR to ai_chat_messages table
ALTER TABLE ai_chat_messages MODIFY COLUMN strategy_mode ENUM('ICT','SMC','CRT','SNR','ALL') NOT NULL;
