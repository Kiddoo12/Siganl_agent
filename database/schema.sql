-- =============================================================
-- GOLD SMC AI PRO - MySQL schema (MySQL 8.0+ / MariaDB 10.4+)
-- All timestamps are stored in UTC. Prices use DECIMAL.
-- Import:  mysql -u root -p < database/schema.sql
-- =============================================================

CREATE DATABASE IF NOT EXISTS gold_smc_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gold_smc_ai;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NOT NULL,
    role            ENUM('admin','analyst','trader') NOT NULL DEFAULT 'trader',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_settings (
    user_id             INT UNSIGNED PRIMARY KEY,
    timezone            VARCHAR(64) NOT NULL DEFAULT 'UTC',
    theme               ENUM('dark','light') NOT NULL DEFAULT 'dark',
    account_balance     DECIMAL(18,2) NOT NULL DEFAULT 10000.00,
    account_currency    CHAR(3) NOT NULL DEFAULT 'USD',
    risk_percent        DECIMAL(5,2) NOT NULL DEFAULT 0.50,
    notify_inapp        TINYINT(1) NOT NULL DEFAULT 1,
    notify_browser      TINYINT(1) NOT NULL DEFAULT 0,
    notify_email        TINYINT(1) NOT NULL DEFAULT 0,
    notify_telegram     TINYINT(1) NOT NULL DEFAULT 0,
    telegram_chat_id    VARCHAR(64) NULL,
    notify_events       JSON NULL,
    min_alert_score     TINYINT UNSIGNED NOT NULL DEFAULT 55,
    updated_at          DATETIME NOT NULL,
    CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_data_sources (
    id              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(32) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    type            ENUM('live','demo') NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_success_at DATETIME NULL,
    last_error_at   DATETIME NULL,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    UNIQUE KEY uq_source_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_candles (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id   SMALLINT UNSIGNED NOT NULL,
    symbol      VARCHAR(16) NOT NULL,
    timeframe   VARCHAR(4) NOT NULL,
    open_time   DATETIME NOT NULL,
    open        DECIMAL(12,3) NOT NULL,
    high        DECIMAL(12,3) NOT NULL,
    low         DECIMAL(12,3) NOT NULL,
    close       DECIMAL(12,3) NOT NULL,
    volume      DECIMAL(20,4) NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    UNIQUE KEY uq_candle (source_id, symbol, timeframe, open_time),
    KEY idx_candle_lookup (symbol, timeframe, open_time),
    CONSTRAINT fk_candle_source FOREIGN KEY (source_id) REFERENCES market_data_sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_analysis (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol          VARCHAR(16) NOT NULL,
    source_id       SMALLINT UNSIGNED NOT NULL,
    data_mode       ENUM('live','demo') NOT NULL,
    analyzed_at     DATETIME NOT NULL,
    price           DECIMAL(12,3) NULL,
    direction       ENUM('bullish','bearish','neutral') NOT NULL,
    htf_bias        ENUM('bullish','bearish','neutral') NOT NULL,
    session         VARCHAR(64) NULL,
    signal_label    VARCHAR(32) NOT NULL,
    quality_score   TINYINT UNSIGNED NULL,
    timeframes      JSON NOT NULL,
    result          JSON NOT NULL,
    data_freshness  JSON NULL,
    created_at      DATETIME NOT NULL,
    KEY idx_analysis_time (analyzed_at),
    CONSTRAINT fk_analysis_source FOREIGN KEY (source_id) REFERENCES market_data_sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS smc_structures (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_id     BIGINT UNSIGNED NOT NULL,
    timeframe       VARCHAR(4) NOT NULL,
    type            VARCHAR(12) NOT NULL,    -- BOS, CHOCH, HH, HL, LH, LL
    direction       ENUM('bullish','bearish') NOT NULL,
    price           DECIMAL(12,3) NOT NULL,
    candle_time     DATETIME NOT NULL,
    is_mss          TINYINT(1) NOT NULL DEFAULT 0,
    has_displacement TINYINT(1) NOT NULL DEFAULT 0,
    meta            JSON NULL,
    KEY idx_struct_analysis (analysis_id, timeframe),
    CONSTRAINT fk_struct_analysis FOREIGN KEY (analysis_id) REFERENCES market_analysis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS liquidity_zones (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_id     BIGINT UNSIGNED NOT NULL,
    timeframe       VARCHAR(4) NOT NULL,
    type            VARCHAR(16) NOT NULL,    -- BSL, SSL, EQH, EQL
    price           DECIMAL(12,3) NOT NULL,
    status          ENUM('active','swept','broken') NOT NULL,
    formed_at       DATETIME NOT NULL,
    event_at        DATETIME NULL,
    meta            JSON NULL,
    KEY idx_liq_analysis (analysis_id, timeframe),
    CONSTRAINT fk_liq_analysis FOREIGN KEY (analysis_id) REFERENCES market_analysis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_blocks (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_id     BIGINT UNSIGNED NOT NULL,
    timeframe       VARCHAR(4) NOT NULL,
    direction       ENUM('bullish','bearish') NOT NULL,
    zone_high       DECIMAL(12,3) NOT NULL,
    zone_low        DECIMAL(12,3) NOT NULL,
    formed_at       DATETIME NOT NULL,
    break_type      VARCHAR(8) NOT NULL,
    break_at        DATETIME NOT NULL,
    has_displacement TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('fresh','mitigated','invalidated','expired') NOT NULL,
    distance        DECIMAL(12,3) NULL,
    htf_aligned     TINYINT(1) NOT NULL DEFAULT 0,
    meta            JSON NULL,
    KEY idx_ob_analysis (analysis_id, timeframe),
    CONSTRAINT fk_ob_analysis FOREIGN KEY (analysis_id) REFERENCES market_analysis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fair_value_gaps (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_id     BIGINT UNSIGNED NOT NULL,
    timeframe       VARCHAR(4) NOT NULL,
    direction       ENUM('bullish','bearish') NOT NULL,
    gap_high        DECIMAL(12,3) NOT NULL,
    gap_low         DECIMAL(12,3) NOT NULL,
    formed_at       DATETIME NOT NULL,
    age_candles     INT UNSIGNED NOT NULL,
    fill_percent    DECIMAL(5,1) NOT NULL,
    status          ENUM('fresh','partial','filled','invalidated') NOT NULL,
    meta            JSON NULL,
    KEY idx_fvg_analysis (analysis_id, timeframe),
    CONSTRAINT fk_fvg_analysis FOREIGN KEY (analysis_id) REFERENCES market_analysis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trading_signals (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_id     BIGINT UNSIGNED NULL,
    symbol          VARCHAR(16) NOT NULL,
    data_mode       ENUM('live','demo') NOT NULL,
    direction       ENUM('BUY','SELL','WAIT') NOT NULL,
    signal_label    VARCHAR(32) NOT NULL,
    setup_status    ENUM('potential','confirmed','triggered','invalidated','expired','closed') NOT NULL,
    setup_type      VARCHAR(64) NULL,
    entry_tf        VARCHAR(4) NOT NULL,
    bias_tfs        VARCHAR(32) NOT NULL,
    entry_low       DECIMAL(12,3) NULL,
    entry_high      DECIMAL(12,3) NULL,
    entry_price     DECIMAL(12,3) NULL,
    stop_loss       DECIMAL(12,3) NULL,
    tp1             DECIMAL(12,3) NULL,
    tp2             DECIMAL(12,3) NULL,
    tp3             DECIMAL(12,3) NULL,
    rr1             DECIMAL(6,2) NULL,
    rr2             DECIMAL(6,2) NULL,
    rr3             DECIMAL(6,2) NULL,
    quality_score   TINYINT UNSIGNED NOT NULL,
    score_breakdown JSON NOT NULL,
    confluences     JSON NOT NULL,
    reasons         JSON NOT NULL,
    invalidations   JSON NOT NULL,
    warnings        JSON NOT NULL,
    ai_explanation  TEXT NULL,
    ai_provider     VARCHAR(100) NULL,
    price_at_signal DECIMAL(12,3) NOT NULL,
    data_source     VARCHAR(64) NOT NULL,
    data_ref        DATETIME NOT NULL,       -- open time of last closed entry-TF candle used
    session         VARCHAR(64) NULL,
    expires_at      DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    KEY idx_sig_created (created_at),
    KEY idx_sig_status (setup_status),
    KEY idx_sig_dir (direction),
    KEY idx_sig_score (quality_score),
    CONSTRAINT fk_sig_analysis FOREIGN KEY (analysis_id) REFERENCES market_analysis(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS signal_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_id   BIGINT UNSIGNED NOT NULL,
    event_type  VARCHAR(32) NOT NULL,
    price       DECIMAL(12,3) NULL,
    event_time  DATETIME NOT NULL,
    meta        JSON NULL,
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uq_sig_event (signal_id, event_type),
    CONSTRAINT fk_event_signal FOREIGN KEY (signal_id) REFERENCES trading_signals(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS signal_outcomes (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_id       BIGINT UNSIGNED NOT NULL,
    outcome         ENUM('pending','open','win','loss','breakeven','invalidated','expired') NOT NULL DEFAULT 'pending',
    entry_filled_at DATETIME NULL,
    exit_at         DATETIME NULL,
    exit_price      DECIMAL(12,3) NULL,
    exit_reason     VARCHAR(64) NULL,
    r_multiple      DECIMAL(8,3) NULL,
    is_simulated    TINYINT(1) NOT NULL DEFAULT 1,
    ambiguous       TINYINT(1) NOT NULL DEFAULT 0,
    max_favorable_r DECIMAL(8,3) NULL,
    max_adverse_r   DECIMAL(8,3) NULL,
    updated_at      DATETIME NOT NULL,
    UNIQUE KEY uq_outcome_signal (signal_id),
    KEY idx_outcome (outcome),
    CONSTRAINT fk_outcome_signal FOREIGN KEY (signal_id) REFERENCES trading_signals(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backtest_runs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    name            VARCHAR(120) NOT NULL,
    data_mode       ENUM('live','demo') NOT NULL,
    timeframe       VARCHAR(4) NOT NULL,
    date_from       DATE NOT NULL,
    date_to         DATE NOT NULL,
    params          JSON NOT NULL,
    status          ENUM('running','completed','failed') NOT NULL,
    total_trades    INT UNSIGNED NOT NULL DEFAULT 0,
    win_rate        DECIMAL(5,2) NULL,
    expectancy_r    DECIMAL(8,3) NULL,
    profit_factor   DECIMAL(8,3) NULL,
    max_drawdown_pct DECIMAL(6,2) NULL,
    net_r           DECIMAL(10,3) NULL,
    ending_equity   DECIMAL(18,2) NULL,
    stats           JSON NULL,
    error           VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL,
    finished_at     DATETIME NULL,
    KEY idx_bt_created (created_at),
    CONSTRAINT fk_bt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backtest_trades (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id          BIGINT UNSIGNED NOT NULL,
    trade_no        INT UNSIGNED NOT NULL,
    segment         ENUM('in_sample','out_of_sample') NOT NULL,
    direction       ENUM('BUY','SELL') NOT NULL,
    setup_type      VARCHAR(64) NULL,
    session         VARCHAR(64) NULL,
    quality_score   TINYINT UNSIGNED NOT NULL,
    signal_time     DATETIME NOT NULL,
    entry_time      DATETIME NULL,
    entry_price     DECIMAL(12,3) NOT NULL,
    stop_loss       DECIMAL(12,3) NOT NULL,
    tp1             DECIMAL(12,3) NULL,
    tp2             DECIMAL(12,3) NULL,
    exit_time       DATETIME NULL,
    exit_price      DECIMAL(12,3) NULL,
    exit_reason     VARCHAR(64) NOT NULL,
    outcome         VARCHAR(16) NOT NULL,
    r_multiple      DECIMAL(8,3) NULL,
    lots            DECIMAL(10,2) NULL,
    pnl             DECIMAL(18,2) NULL,
    equity_after    DECIMAL(18,2) NULL,
    ambiguous       TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_bt_trades_run (run_id, trade_no),
    CONSTRAINT fk_bt_trade_run FOREIGN KEY (run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS economic_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source      VARCHAR(64) NOT NULL,
    external_id CHAR(40) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    country     VARCHAR(16) NOT NULL,
    impact      ENUM('high','medium','low','holiday') NOT NULL,
    event_time  DATETIME NOT NULL,
    all_day     TINYINT(1) NOT NULL DEFAULT 0,
    forecast    VARCHAR(64) NULL,
    previous    VARCHAR(64) NULL,
    actual      VARCHAR(64) NULL,
    source_url  VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    UNIQUE KEY uq_event (source, external_id),
    KEY idx_event_time (event_time, impact)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    channel     ENUM('inapp','telegram','email') NOT NULL,
    event_type  VARCHAR(32) NOT NULL,
    title       VARCHAR(200) NOT NULL,
    message     TEXT NOT NULL,
    signal_id   BIGINT UNSIGNED NULL,
    dedupe_key  VARCHAR(128) NOT NULL,
    status      ENUM('sent','failed','skipped') NOT NULL,
    error       VARCHAR(500) NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    KEY idx_notif_user (user_id, is_read, created_at),
    KEY idx_notif_dedupe (dedupe_key, user_id, channel, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_signal FOREIGN KEY (signal_id) REFERENCES trading_signals(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,
    entity      VARCHAR(64) NULL,
    entity_id   VARCHAR(64) NULL,
    details     JSON NULL,
    ip          VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_audit_time (created_at),
    KEY idx_audit_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    `key`       VARCHAR(120) PRIMARY KEY,
    `value`     JSON NOT NULL,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
    rl_key       CHAR(64) PRIMARY KEY,
    hits         INT UNSIGNED NOT NULL,
    window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
