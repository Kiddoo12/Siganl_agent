-- =============================================================
-- GOLD SMC AI PRO - Strategy Validations & Quantitative Metrics Migration
-- =============================================================

USE gold_smc_ai;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS strategy_validations (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    strategy_code           VARCHAR(40) NOT NULL UNIQUE,
    strategy_name           VARCHAR(100) NOT NULL,
    status                  ENUM('VALIDATED','PROBATION','DEGRADED','REJECTED') NOT NULL DEFAULT 'PROBATION',
    in_sample_trades        INT UNSIGNED NOT NULL DEFAULT 0,
    in_sample_win_rate      DECIMAL(5,2) NULL,
    in_sample_expectancy_r  DECIMAL(8,3) NULL,
    in_sample_profit_factor DECIMAL(8,3) NULL,
    oos_trades              INT UNSIGNED NOT NULL DEFAULT 0,
    oos_win_rate            DECIMAL(5,2) NULL,
    oos_expectancy_r        DECIMAL(8,3) NULL,
    oos_profit_factor       DECIMAL(8,3) NULL,
    oos_max_drawdown_pct    DECIMAL(6,2) NULL,
    sharpe_ratio            DECIMAL(6,2) NULL,
    consecutive_losses      INT UNSIGNED NOT NULL DEFAULT 0,
    stress_test_status      VARCHAR(32) NOT NULL DEFAULT 'PASSED',
    last_validated_at       DATETIME NULL,
    revalidation_needed     TINYINT(1) NOT NULL DEFAULT 0,
    metrics_json            JSON NULL,
    updated_at              DATETIME NOT NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
