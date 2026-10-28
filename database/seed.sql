-- =============================================================
-- GOLD SMC AI PRO - seed data
-- Contains ONLY reference/configuration rows. No market candles,
-- prices, signals, news events or performance results are seeded:
-- those must come from real providers (or the labelled demo adapter).
-- Admin users are created with:  php bin/create_admin.php
-- =============================================================
USE gold_smc_ai;

INSERT INTO market_data_sources (code, name, type, is_active, created_at, updated_at) VALUES
 ('twelvedata', 'Twelve Data (XAU/USD)', 'live', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('demo',       'DEMO synthetic generator (NOT market data)', 'demo', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), updated_at = UTC_TIMESTAMP();
