<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;
use App\SMC\IctEngine;

$symbol = isset($_GET['symbol']) ? Validator::str($_GET['symbol'], 1, 16) : 'XAUUSD';
$symbol = MarketDataService::normalizeSymbol($symbol);
$chartTf = isset($_GET['tf']) ? Validator::str($_GET['tf'], 1, 8) : 'M15';

$layout = renderHeader('Live Market', 'live');
$market = new MarketDataService(null, $symbol);
$quote = $market->quote(false);
$livePrice = $quote['available'] ? (float)$quote['price'] : 2400.0;
$spread = $quote['available'] ? (float)($quote['ask'] - $quote['bid']) : 0.30;

$now = time();
$kz = IctEngine::killzones($now);
$macro = IctEngine::macroWindows($now);
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>⚡</span>
            <span><?= I18n::t('nav_live') ?> — Real-Time Execution Hub</span>
        </div>
        <div class="vision-hero-sub">
            Continuous real-time institutional feed for <?= Validator::e($symbol) ?> with live spread monitoring, ICT killzones, and interactive charting.
        </div>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <select class="form-select" onchange="window.location.href='live.php?symbol=' + this.value + '&tf=<?= $chartTf ?>'">
            <option value="XAUUSD" <?= $symbol === 'XAUUSD' ? 'selected' : '' ?>>XAU/USD (Spot Gold)</option>
            <option value="BTCUSD" <?= $symbol === 'BTCUSD' ? 'selected' : '' ?>>BTC/USD (Bitcoin)</option>
        </select>
        <span class="badge badge-live">● <?= I18n::t('live_feed') ?></span>
        <a href="vision.php?symbol=<?= $symbol ?>" class="btn btn-sm btn-primary">📷 Analyze Chart Screenshot</a>
    </div>
</div>

<!-- Price & Session Stats Bar -->
<div class="levels-card-grid" style="margin-bottom: 20px;">
    <div class="level-card">
        <div class="level-card-label">Spot Price</div>
        <div class="level-card-val mono text-gold" id="liveSpotPrice">$<?= number_format($livePrice, 2) ?></div>
    </div>
    <div class="level-card">
        <div class="level-card-label">Spread</div>
        <div class="level-card-val mono" id="liveSpreadVal">$<?= number_format(max(0.1, $spread), 2) ?></div>
    </div>
    <div class="level-card">
        <div class="level-card-label">Active Kill Zone</div>
        <div class="level-card-val" style="font-size: 13px; color: #c084fc;">
            <?= $kz['active'] ?: 'Off-Hours' ?>
        </div>
    </div>
    <div class="level-card">
        <div class="level-card-label">Macro Time Window</div>
        <div class="level-card-val" style="font-size: 13px; color: #22d3ee;">
            <?= $macro['active_macro'] ?: 'Inactive' ?>
        </div>
    </div>
    <div class="level-card">
        <div class="level-card-label">New York Time</div>
        <div class="level-card-val mono"><?= $kz['ny_time'] ?></div>
    </div>
</div>

<!-- Embedded TradingView Chart Container -->
<div class="calc-card" style="padding: 16px; margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <strong style="font-size: 15px;"><?= $symbol ?> TradingView Station</strong>
            <span class="muted" style="font-size: 12px;">Candle Feed</span>
        </div>
        <div class="vision-tabs" style="border: none; margin: 0;">
            <?php foreach (['M5','M15','H1','H4','D1'] as $tf): ?>
            <a href="live.php?symbol=<?= $symbol ?>&tf=<?= $tf ?>" class="vision-tab-btn <?= $chartTf === $tf ? 'active' : '' ?>">
                <?= $tf ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Chart Iframe / Widget -->
    <div style="height: 580px; width: 100%; border-radius: var(--radius); overflow: hidden; background: #000; border: 1px solid var(--border);">
        <iframe
            src="https://s.tradingview.com/widgetembed/?frameElementId=tradingview_widget&symbol=<?= $symbol === 'XAUUSD' ? 'OANDA%3AXAUUSD' : 'BINANCE%3ABTCUSDT' ?>&interval=<?= $chartTf === 'M5' ? '5' : ($chartTf === 'M15' ? '15' : ($chartTf === 'H1' ? '60' : ($chartTf === 'H4' ? '240' : 'D'))) ?>&hidesidetoolbar=0&symboledit=1&saveimage=1&toolbarbg=f1f3f6&studies=%5B%5D&theme=dark&style=1&timezone=Etc%2FUTC&studies_overrides=%7B%7D&overrides=%7B%7D&enabled_features=%5B%5D&disabled_features=%5B%5D&locale=en&utm_source=localhost"
            style="width: 100%; height: 100%; border: none;">
        </iframe>
    </div>
</div>

<!-- Quick Links Footer -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
    <div class="calc-card">
        <h4 style="font-size: 14px; font-weight: 800; margin-bottom: 8px;">📷 AI Chart Vision Analyzer</h4>
        <p class="muted" style="font-size: 12.5px; line-height: 1.5; margin-bottom: 12px;">
            Have a chart screenshot from your phone, MT5, or TradingView? Upload it to generate complete ICT/SMC trade setups with entry, SL, and TP.
        </p>
        <a href="vision.php?symbol=<?= $symbol ?>" class="btn btn-sm btn-primary">Launch Vision Analyzer →</a>
    </div>

    <div class="calc-card">
        <h4 style="font-size: 14px; font-weight: 800; margin-bottom: 8px;">🧮 Position Size &amp; Risk Calculator</h4>
        <p class="muted" style="font-size: 12.5px; line-height: 1.5; margin-bottom: 12px;">
            Calculate exact lot size, monetary risk in USD, and profit targets for Gold based on your account balance and stop loss distance.
        </p>
        <a href="calculator.php?symbol=<?= $symbol ?>" class="btn btn-sm btn-ghost">Open Risk Calculator →</a>
    </div>

    <div class="calc-card">
        <h4 style="font-size: 14px; font-weight: 800; margin-bottom: 8px;">📚 ICT Strategy Library</h4>
        <p class="muted" style="font-size: 12.5px; line-height: 1.5; margin-bottom: 12px;">
            Learn and configure Silver Bullet, 2022 Mentorship Model, Unicorn, Judas Swing, and OTE models with live active detection.
        </p>
        <a href="strategies.php" class="btn btn-sm btn-ghost">View Strategy Library →</a>
    </div>
</div>

<?php
renderFooter();
