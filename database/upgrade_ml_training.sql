-- =============================================================
-- GOLD SMC AI PRO - Machine Learning & Adaptive Training Engine Migration
-- =============================================================

USE gold_smc_ai;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Model Versions Storage
CREATE TABLE IF NOT EXISTS ml_model_versions (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_tag                 VARCHAR(50) NOT NULL UNIQUE,
    model_type                  ENUM('regime_classifier','setup_quality','trade_filter','composite_agent') NOT NULL,
    status                      ENUM('candidate','validated','active','deprecated','rolled_back') NOT NULL DEFAULT 'candidate',
    algorithm                   VARCHAR(64) NOT NULL,
    hyperparameters             JSON NOT NULL,
    weights_json                JSON NOT NULL,
    feature_names               JSON NOT NULL,
    train_from                  DATETIME NOT NULL,
    train_to                    DATETIME NOT NULL,
    val_from                    DATETIME NOT NULL,
    val_to                      DATETIME NOT NULL,
    train_sample_count          INT UNSIGNED NOT NULL DEFAULT 0,
    val_sample_count            INT UNSIGNED NOT NULL DEFAULT 0,
    in_sample_win_rate          DECIMAL(6,3) NULL,
    in_sample_profit_factor     DECIMAL(8,3) NULL,
    in_sample_expectancy_r      DECIMAL(8,3) NULL,
    oos_win_rate                DECIMAL(6,3) NULL,
    oos_profit_factor           DECIMAL(8,3) NULL,
    oos_expectancy_r            DECIMAL(8,3) NULL,
    oos_max_drawdown_pct        DECIMAL(6,2) NULL,
    brier_score                 DECIMAL(8,5) NULL,
    expected_calib_error        DECIMAL(8,5) NULL,
    sharpe_ratio                DECIMAL(6,2) NULL,
    confusion_matrix            JSON NULL,
    is_active                   TINYINT(1) NOT NULL DEFAULT 0,
    notes                       TEXT NULL,
    trained_at                  DATETIME NOT NULL,
    validated_at                DATETIME NULL,
    activated_at                DATETIME NULL,
    created_at                  DATETIME NOT NULL,
    updated_at                  DATETIME NOT NULL,
    KEY idx_ml_active (is_active),
    KEY idx_ml_type_status (model_type, status)
) ENGINE=InnoDB;

-- 2. Training Jobs & Reproducibility Audit Log
CREATE TABLE IF NOT EXISTS ml_training_jobs (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_uuid                    VARCHAR(64) NOT NULL UNIQUE,
    symbol                      VARCHAR(16) NOT NULL,
    timeframe                   VARCHAR(4) NOT NULL,
    status                      ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    dataset_size                INT UNSIGNED NOT NULL DEFAULT 0,
    train_split                 DECIMAL(4,2) NOT NULL DEFAULT 0.70,
    target_metric               VARCHAR(32) NOT NULL DEFAULT 'expectancy_r',
    model_version_id            INT UNSIGNED NULL,
    metrics_summary             JSON NULL,
    log_output                  MEDIUMTEXT NULL,
    started_at                  DATETIME NULL,
    completed_at                DATETIME NULL,
    created_at                  DATETIME NOT NULL,
    CONSTRAINT fk_job_model FOREIGN KEY (model_version_id) REFERENCES ml_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3. Point-in-Time Feature Snapshots
CREATE TABLE IF NOT EXISTS ml_feature_snapshots (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_id                   BIGINT UNSIGNED NULL,
    symbol                      VARCHAR(16) NOT NULL,
    timeframe                   VARCHAR(4) NOT NULL,
    snapshot_time               DATETIME NOT NULL,
    price                       DECIMAL(12,3) NOT NULL,
    regime                      VARCHAR(32) NOT NULL,
    session                     VARCHAR(32) NOT NULL,
    features_json               JSON NOT NULL,
    predicted_win_prob          DECIMAL(6,4) NULL,
    expected_net_r              DECIMAL(8,3) NULL,
    decision                    ENUM('BUY','SELL','NO_TRADE') NOT NULL,
    decision_reason             VARCHAR(255) NULL,
    model_version_id            INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL,
    KEY idx_snapshot_time (snapshot_time),
    KEY idx_snapshot_symbol (symbol, timeframe),
    CONSTRAINT fk_feat_sig FOREIGN KEY (signal_id) REFERENCES trading_signals(id) ON DELETE SET NULL,
    CONSTRAINT fk_feat_model FOREIGN KEY (model_version_id) REFERENCES ml_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Deep Error Taxonomy & Trade Diagnosis
CREATE TABLE IF NOT EXISTS signal_error_reports (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_id                   BIGINT UNSIGNED NOT NULL,
    model_version_id            INT UNSIGNED NULL,
    outcome                     VARCHAR(32) NOT NULL,
    failure_category            ENUM('NONE','REGIME_MISMATCH','LIQUIDITY_RUN','DISPLACEMENT_FAILURE','NEWS_SLIPPAGE','PROBABILITY_DRIFT','PREMATURE_TRIGGER','NORMAL_STOP') NOT NULL,
    realized_r                  DECIMAL(8,3) NOT NULL,
    max_adverse_r               DECIMAL(8,3) NULL,
    max_favorable_r             DECIMAL(8,3) NULL,
    friction_cost_r             DECIMAL(8,3) NOT NULL DEFAULT 0.05,
    root_cause_analysis         TEXT NOT NULL,
    remedy_recommendation       TEXT NOT NULL,
    created_at                  DATETIME NOT NULL,
    UNIQUE KEY uq_err_signal (signal_id),
    KEY idx_err_cat (failure_category),
    CONSTRAINT fk_err_sig FOREIGN KEY (signal_id) REFERENCES trading_signals(id) ON DELETE CASCADE,
    CONSTRAINT fk_err_model FOREIGN KEY (model_version_id) REFERENCES ml_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Probability Calibration Bins (Reliability Curves)
CREATE TABLE IF NOT EXISTS ml_calibration_bins (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_version_id            INT UNSIGNED NOT NULL,
    bin_index                   TINYINT UNSIGNED NOT NULL,
    prob_min                    DECIMAL(5,4) NOT NULL,
    prob_max                    DECIMAL(5,4) NOT NULL,
    prob_mean                   DECIMAL(5,4) NOT NULL,
    empirical_win_rate          DECIMAL(5,4) NOT NULL,
    sample_count                INT UNSIGNED NOT NULL,
    created_at                  DATETIME NOT NULL,
    KEY idx_calib_model (model_version_id),
    CONSTRAINT fk_calib_model FOREIGN KEY (model_version_id) REFERENCES ml_model_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
