-- =========================================================================
-- Upgrade: XAUUSD Historical Chart Dataset Builder & AI Training Pipeline
-- =========================================================================

CREATE TABLE IF NOT EXISTS `dataset_imports` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `import_uuid` VARCHAR(64) NOT NULL UNIQUE,
    `filename` VARCHAR(255) NOT NULL,
    `file_hash` VARCHAR(64) NOT NULL,
    `symbol` VARCHAR(16) NOT NULL DEFAULT 'XAUUSD',
    `timeframe` VARCHAR(8) NOT NULL DEFAULT 'M1',
    `source_type` ENUM('mt5_tab', 'mt5_csv', 'tradingview', 'generic_csv') NOT NULL,
    `tz_offset_hours` DECIMAL(4, 2) NOT NULL DEFAULT 0.00,
    `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `valid_candles` INT UNSIGNED NOT NULL DEFAULT 0,
    `rejected_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `duplicate_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `gap_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `date_start` DATETIME NULL,
    `date_end` DATETIME NULL,
    `validation_report` JSON NULL,
    `status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_di_symbol_tf` (`symbol`, `timeframe`),
    INDEX `idx_di_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dataset_versions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version_tag` VARCHAR(32) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `symbol` VARCHAR(16) NOT NULL DEFAULT 'XAUUSD',
    `total_examples` INT UNSIGNED NOT NULL DEFAULT 0,
    `train_examples` INT UNSIGNED NOT NULL DEFAULT 0,
    `val_examples` INT UNSIGNED NOT NULL DEFAULT 0,
    `test_examples` INT UNSIGNED NOT NULL DEFAULT 0,
    `purge_gap_bars` INT UNSIGNED NOT NULL DEFAULT 60,
    `label_quality_score` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    `split_hash` VARCHAR(64) NULL,
    `status` ENUM('draft', 'frozen', 'active', 'archived') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_dv_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhance vision_training_examples with candle sequence and scenario metadata if not present
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vision_training_examples' AND COLUMN_NAME = 'scenario_type');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `vision_training_examples` ADD COLUMN `scenario_type` VARCHAR(32) NOT NULL DEFAULT "trend_continuation" AFTER `market_regime`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vision_training_examples' AND COLUMN_NAME = 'structured_candles');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `vision_training_examples` ADD COLUMN `structured_candles` LONGTEXT NULL AFTER `expert_notes`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vision_training_examples' AND COLUMN_NAME = 'svg_chart_path');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `vision_training_examples` ADD COLUMN `svg_chart_path` VARCHAR(255) NULL AFTER `thumbnail_path`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vision_training_examples' AND COLUMN_NAME = 'is_purged_embargo');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `vision_training_examples` ADD COLUMN `is_purged_embargo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `dataset_split`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vision_annotations' AND COLUMN_NAME = 'is_preliminary');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `vision_annotations` ADD COLUMN `is_preliminary` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_confirmed`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
