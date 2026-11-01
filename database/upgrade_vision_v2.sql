-- Upgrade Chart Vision Analyses table for v2 Vision Engine
ALTER TABLE chart_vision_analyses
    ADD COLUMN strategy_mode VARCHAR(16) NOT NULL DEFAULT 'ALL' AFTER timeframe,
    ADD COLUMN htf_timeframe VARCHAR(8) NULL DEFAULT NULL AFTER strategy_mode,
    ADD COLUMN trade_direction VARCHAR(16) NOT NULL DEFAULT 'NO TRADE' AFTER market_bias,
    ADD COLUMN quality_gate_passed TINYINT(1) NOT NULL DEFAULT 1 AFTER confidence_score,
    ADD COLUMN image_hash VARCHAR(64) NULL DEFAULT NULL AFTER image_path;
