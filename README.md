# GOLD SMC AI PRO — Institutional Trading Terminal

Institutional rule-based **Smart Money Concepts (SMC)**, **Inner Circle Trader (ICT)** multi-timeframe analysis, quantitative validation backtester, AI Chart Vision analyzer, position size calculator, and economic calendar filter for **Spot Gold (`XAUUSD`)**, Crypto (`BTCUSD`), Forex (`EURUSD`, `GBPUSD`, `USDJPY`), and Major Indices (`US30`, `NAS100`).

> 🚀 **Live Interactive Web Terminal & AI Chat Workstation:** [https://kiddoo12.github.io/Siganl_agent/index.html](https://kiddoo12.github.io/Siganl_agent/index.html)

---

## 🌟 Key Architecture & Capabilities

### 1. Deterministic SMC Core (`app/SMC/SmcEngine.php`)
- **Look-Ahead Safe Swings**: Pivot highs/lows confirmed only after `swing_right` subsequent candles close (`confirmed_i = i + right`).
- **Structure Breaks (`BOS` / `CHOCH` / `MSS`)**: Requires close beyond confirmed swing + ATR buffer. Identifies **Market Structure Shift (`MSS`)** when a CHOCH leg contains displacement.
- **Liquidity Sweeps & Pools (`BSL`, `SSL`, `EQH`, `EQL`)**: Detects resting buy-side/sell-side liquidity, equal highs/lows, and wick-penetration sweeps.
- **Order Blocks (`OB`) & Fair Value Gaps (`FVG`)**: Tracks 3-candle imbalances (`fresh`, `partial`, `filled`) and displacement-backed order blocks.
- **Premium / Equilibrium / Discount Dealing Range**: Computes dealing range from major pivots and classifies price relative to the 50% EQ line.

### 2. Advanced ICT Strategy Engine (`app/SMC/IctEngine.php`)
- **ICT Silver Bullet**: Dedicated London and New York session liquidity sweep and FVG delivery models.
- **ICT 2022 Mentorship Model**: CHOCH + displacement + Fair Value Gap retracement entry.
- **ICT Unicorn Model**: Breaker Block overlapping with an active Fair Value Gap.
- **ICT Turtle Soup & Judas Swing**: False breakout sweeps at session extremes.
- **Kill Zones & Macros**: New York AM/PM, London, and Asian kill zones with 20-minute macro execution windows.

### 3. AI Chart Vision Analyzer (`app/Analysis/ChartVisionService.php`, `vision.php`)
- Upload or drag-and-drop chart screenshots from TradingView, MT4, MT5, or web browsers.
- AI vision detection mapping key market structure, liquidity targets, entry zones, SL, and TP levels.
- Interactive Canvas & SVG overlay visualization directly on the uploaded image.
- One-click Telegram bot dispatching clean trade setups (Entry, SL, all TPs).

### 4. Quantitative Strategy Backtester & Validation Gate (`app/Backtest/Backtester.php`, `backtest.php`)
- Strict candle-by-candle walk-forward replay (70% In-Sample / 30% Out-of-Sample).
- **Friction Deductions**: Realistic commissions ($3.50/lot/side), spread, and slippage allowance (0.15 handles) deducted per trade.
- **Institutional Metrics**: Annualized Sharpe ratio, Net Expectancy ($ and R), Profit Factor, Max Drawdown, Win/Loss ratio, Max loss streaks.
- **Adverse Stress Testing**: Simulates adverse slippage (+0.25 handles) and widened spread (1.5x).
- **7-Point Out-of-Sample Validation Gate**: Requires strategies to pass 7 strict quantitative criteria before deployment.

### 5. Adaptive Regime Detection & Institutional Risk (`app/Analysis/RegimeDetector.php`, `app/Analysis/RiskCalculator.php`)
- Classifies 4 market regimes: `trending_bullish`, `trending_bearish`, `ranging_consolidation`, and `high_volatility_abnormal`.
- Dynamically authorizes strategy models suited to the active regime.
- **Circuit Breaker**: Automatically pauses strategies with \(\ge 4\) consecutive losses or rolling win rate \(< 30\%\).
- Default 0.5% account equity risk per trade, broker contract sizing (100 oz/lot for Gold).
- 2.0% daily loss limit halts all new setups until next UTC day.
- Master Emergency Stop switch (`risk.emergency_stop`) and strict Anti-Martingale rule enforcement.

### 6. Multi-Asset Data Feed (`app/Market/`)
- Supports **XAUUSD**, **BTCUSD**, **EURUSD**, **GBPUSD**, **USDJPY**, **US30**, and **NAS100**.
- Twelve Data API integration, Yahoo Finance real-time fallback, and offline synthetic demo mode.

---

## 🚀 Setup Instructions (XAMPP / PHP 8.1+ / MySQL 8 or MariaDB 10.4+)

### 1. Configure Environment
Copy `.env.example` to `.env` and fill in your database credentials:
```bash
copy .env.example .env
```
Key configuration settings in `.env`:
```ini
APP_URL=http://localhost/Siganl_agent
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=gold_smc_ai
DB_USER=root
DB_PASS=your_mysql_password

MARKET_PROVIDER=yahoo
AI_PROVIDER=none
TELEGRAM_BOT_TOKEN=
```

### 2. Import Database Schema & Reference Seeds
```bash
mysql -u root -p gold_smc_ai < database/schema.sql
mysql -u root -p gold_smc_ai < database/seed.sql
mysql -u root -p gold_smc_ai < database/upgrade_ict_vision.sql
mysql -u root -p gold_smc_ai < database/upgrade_validation_regimes.sql
```

### 3. Create an Administrator Account
```bash
php bin/create_admin.php admin@goldsmc.local YourStrongPassword "Terminal Administrator" admin
```

### 4. Run Automated Verification Tests
Verify that all 83 unit and end-to-end tests pass:
```bash
php tests/run.php
```

### 5. Schedule Background Cron Scans
Set up Windows Task Scheduler or cron to run every 5 minutes:
```bash
php cron/run.php
# or via HTTP:
curl "http://localhost/Siganl_agent/cron/run.php?token=YOUR_CRON_TOKEN"
```

---

## 🛡️ License & Risk Disclosure

Simulated and quantitative trading signals carry inherent financial risk. Past performance, backtest results, and high confluence scores do not guarantee future profits. Always practice disciplined risk management and capital preservation.
