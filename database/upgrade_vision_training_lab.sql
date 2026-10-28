-- =============================================================
-- GOLD SMC AI PRO - Vision Training Lab & Ground-Truth Dataset Migration
-- =============================================================

USE gold_smc_ai;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Multi-Timeframe Chart Vision Bundles
CREATE TABLE IF NOT EXISTS vision_mtf_bundles (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bundle_uuid             VARCHAR(64) NOT NULL UNIQUE,
    user_id                 INT UNSIGNED NULL,
    symbol                  VARCHAR(16) NOT NULL DEFAULT 'XAUUSD',
    anchor_timestamp        DATETIME NOT NULL,
    htf_bias                ENUM('Bullish','Bearish','Neutral') NOT NULL DEFAULT 'Neutral',
    confluence_score        TINYINT UNSIGNED NOT NULL DEFAULT 50,
    has_conflict            TINYINT(1) NOT NULL DEFAULT 0,
    conflict_notes          TEXT NULL,
    overall_decision        ENUM('BUY','SELL','WAIT','NO_TRADE') NOT NULL DEFAULT 'WAIT',
    created_at              DATETIME NOT NULL,
    KEY idx_vmb_symbol_time (symbol, anchor_timestamp),
    CONSTRAINT fk_vmb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2. Ground-Truth Vision Training Examples
CREATE TABLE IF NOT EXISTS vision_training_examples (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    example_uuid            VARCHAR(64) NOT NULL UNIQUE,
    bundle_id               BIGINT UNSIGNED NULL,
    user_id                 INT UNSIGNED NULL,
    symbol                  VARCHAR(16) NOT NULL DEFAULT 'XAUUSD',
    timeframe               VARCHAR(8) NOT NULL DEFAULT 'M15',
    image_path              VARCHAR(255) NOT NULL,
    thumbnail_path          VARCHAR(255) NULL,
    chart_timestamp         DATETIME NULL,
    broker                  VARCHAR(64) NULL,
    chart_tz                VARCHAR(64) NULL,
    ref_price               DECIMAL(12,3) NULL,
    review_status           ENUM('unreviewed','verified','expert_corrected') NOT NULL DEFAULT 'unreviewed',
    dataset_split           ENUM('train','val','test') NOT NULL DEFAULT 'train',
    dataset_version         VARCHAR(32) NOT NULL DEFAULT 'v1.0',
    market_regime           VARCHAR(32) NOT NULL DEFAULT 'ranging_consolidation',
    trend_direction         ENUM('Bullish','Bearish','Neutral') NOT NULL DEFAULT 'Neutral',
    market_structure        VARCHAR(64) NOT NULL DEFAULT 'Neutral / Sideways',
    key_swing_high          DECIMAL(12,3) NULL,
    key_swing_low           DECIMAL(12,3) NULL,
    bsl_price               DECIMAL(12,3) NULL,
    ssl_price               DECIMAL(12,3) NULL,
    fvg_summary             VARCHAR(255) NULL,
    order_block_summary     VARCHAR(255) NULL,
    premium_discount        VARCHAR(64) NULL,
    trade_decision          ENUM('BUY','SELL','WAIT','NO_TRADE') NOT NULL DEFAULT 'WAIT',
    entry_price             DECIMAL(12,3) NULL,
    stop_loss               DECIMAL(12,3) NULL,
    tp1                     DECIMAL(12,3) NULL,
    tp2                     DECIMAL(12,3) NULL,
    tp3                     DECIMAL(12,3) NULL,
    risk_reward             DECIMAL(6,2) NULL,
    ai_raw_prediction       JSON NULL,
    expert_notes            TEXT NULL,
    outcome                 ENUM('pending','win','loss','breakeven','expired') NOT NULL DEFAULT 'pending',
    realized_r              DECIMAL(8,3) NULL,
    max_favorable_r         DECIMAL(8,3) NULL,
    max_adverse_r           DECIMAL(8,3) NULL,
    outcome_verified_at     DATETIME NULL,
    created_at              DATETIME NOT NULL,
    updated_at              DATETIME NOT NULL,
    KEY idx_vte_status (review_status, dataset_split),
    KEY idx_vte_symbol_tf (symbol, timeframe),
    CONSTRAINT fk_vte_bundle FOREIGN KEY (bundle_id) REFERENCES vision_mtf_bundles(id) ON DELETE SET NULL,
    CONSTRAINT fk_vte_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3. Visual Bounding Box & Keylevel Annotations (Ground Truth & AI Detections)
CREATE TABLE IF NOT EXISTS vision_annotations (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    example_id              BIGINT UNSIGNED NOT NULL,
    source                  ENUM('ai_predicted','expert_ground_truth') NOT NULL DEFAULT 'expert_ground_truth',
    pattern_type            ENUM('BOS','CHOCH','MSS','FVG_BULL','FVG_BEAR','IFVG','OB_BULL','OB_BEAR','BREAKER','MITIGATION','LIQUIDITY_SWEEP','BSL','SSL','EQH','EQL','DISPLACEMENT','OTE','ENTRY','SL','TP') NOT NULL,
    direction               ENUM('bullish','bearish','neutral') NOT NULL DEFAULT 'neutral',
    box_x                   DECIMAL(7,4) NOT NULL, -- normalized 0.0000 - 1.0000
    box_y                   DECIMAL(7,4) NOT NULL, -- normalized 0.0000 - 1.0000
    box_w                   DECIMAL(7,4) NOT NULL, -- normalized 0.0000 - 1.0000
    box_h                   DECIMAL(7,4) NOT NULL, -- normalized 0.0000 - 1.0000
    price_top               DECIMAL(12,3) NULL,
    price_bottom            DECIMAL(12,3) NULL,
    price_level             DECIMAL(12,3) NULL,
    is_confirmed            TINYINT(1) NOT NULL DEFAULT 1,
    uncertainty_score       TINYINT UNSIGNED NOT NULL DEFAULT 15,
    notes                   VARCHAR(255) NULL,
    created_at              DATETIME NOT NULL,
    KEY idx_va_example (example_id, source),
    KEY idx_va_pattern (pattern_type),
    CONSTRAINT fk_va_example FOREIGN KEY (example_id) REFERENCES vision_training_examples(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Vision Model Benchmark Runs & Accuracy Tracking
CREATE TABLE IF NOT EXISTS vision_benchmark_runs (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_uuid                VARCHAR(64) NOT NULL UNIQUE,
    model_name              VARCHAR(64) NOT NULL,
    dataset_version         VARCHAR(32) NOT NULL,
    sample_count            INT UNSIGNED NOT NULL,
    fvg_precision           DECIMAL(5,2) NULL,
    fvg_recall              DECIMAL(5,2) NULL,
    fvg_f1                  DECIMAL(5,2) NULL,
    ob_precision            DECIMAL(5,2) NULL,
    ob_recall               DECIMAL(5,2) NULL,
    ob_f1                   DECIMAL(5,2) NULL,
    bos_precision           DECIMAL(5,2) NULL,
    bos_recall              DECIMAL(5,2) NULL,
    bos_f1                  DECIMAL(5,2) NULL,
    liquidity_precision     DECIMAL(5,2) NULL,
    liquidity_recall        DECIMAL(5,2) NULL,
    liquidity_f1            DECIMAL(5,2) NULL,
    price_mae_dollars       DECIMAL(8,3) NULL,
    trade_win_rate          DECIMAL(5,2) NULL,
    trade_profit_factor     DECIMAL(8,3) NULL,
    metrics_json            JSON NOT NULL,
    evaluated_at            DATETIME NOT NULL,
    created_at              DATETIME NOT NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
