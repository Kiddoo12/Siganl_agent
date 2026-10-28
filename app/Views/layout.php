<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;

/**
 * Shared layout header and footer for authenticated pages.
 */
function renderHeader(string $title, string $activeNav = 'dashboard'): array
{
    $user = Auth::requirePage('trader');
    $uSettings = Database::fetch('SELECT * FROM user_settings WHERE user_id = ?', [$user['id']]) ?: [
        'timezone'        => Env::get('APP_TIMEZONE', 'UTC'),
        'theme'           => 'dark',
        'account_balance' => 10000.00,
        'risk_percent'    => 0.50,
        'notify_browser'  => 0,
    ];
    $market = new MarketDataService();
    $mode = $market->mode();
    $providerLabel = $market->provider()->label();
    $unreadCount = (int)Database::value(
        "SELECT COUNT(*) FROM notification_logs WHERE user_id = ? AND channel = 'inapp' AND is_read = 0",
        [$user['id']]
    );

    $theme = ($uSettings['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $tz = $uSettings['timezone'] ?? 'UTC';
    $csrf = Csrf::token();
    $appName = Env::get('APP_NAME', 'GOLD SMC AI PRO');
    $cssVer = APP_VERSION . '.' . (int)@filemtime(APP_ROOT . '/assets/css/app.css');
    $curLang = I18n::lang();
    ?>
<!DOCTYPE html>
<html lang="<?= Validator::e($curLang) ?>" data-theme="<?= Validator::e($theme) ?>" data-tz="<?= Validator::e($tz) ?>" data-lang="<?= Validator::e($curLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Validator::e($csrf) ?>">
    <title><?= Validator::e($title) ?> — <?= Validator::e($appName) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= Validator::e($cssVer) ?>">
</head>
<body>
    <?php if ($mode === 'demo'): ?>
    <div class="demo-banner" role="status">
        <span class="badge badge-warn"><?= I18n::t('demo_mode') ?></span>
        <span>Running on <strong>deterministic synthetic data</strong> (<code>MARKET_PROVIDER=demo</code>). Configure <code>TWELVEDATA_API_KEY</code> and <code>MARKET_PROVIDER=twelvedata</code> in <code>.env</code> for live XAU/USD data.</span>
    </div>
    <?php endif; ?>

    <header class="topbar">
        <div class="topbar-brand">
            <a href="index.php" class="brand-link">
                <span class="brand-icon">Au</span>
                <div>
                    <div class="brand-title">GOLD SMC AI <span class="brand-pro">PRO</span></div>
                    <div class="brand-sub">XAU/USD Institutional SMC + ICT Terminal</div>
                </div>
            </a>
            <span class="badge <?= $mode === 'live' ? 'badge-live' : 'badge-warn' ?>" title="<?= Validator::e($providerLabel) ?>">
                <?= $mode === 'live' ? '● ' . I18n::t('live_feed') : '◆ ' . I18n::t('demo_mode') ?>
            </span>
        </div>

        <nav class="topbar-nav" aria-label="Primary Navigation">
            <a href="index.php" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>" title="Terminal"><?= I18n::t('nav_terminal') ?></a>
            <a href="live.php" class="nav-item <?= $activeNav === 'live' ? 'active' : '' ?>" title="Live Market"><?= I18n::t('nav_live') ?></a>
            <a href="vision.php" class="nav-item <?= $activeNav === 'vision' ? 'active' : '' ?>" title="AI Chart Vision"><span class="nav-badge-ai">AI</span> <?= I18n::t('nav_vision') ?></a>
            <a href="vision_lab.php" class="nav-item <?= $activeNav === 'vision_lab' ? 'active' : '' ?>" title="AI Chart Training Lab"><span class="nav-badge-ai">LAB</span> <?= I18n::t('nav_vision_lab') ?></a>
            <a href="dataset_builder.php" class="nav-item <?= $activeNav === 'dataset_builder' ? 'active' : '' ?>" title="XAUUSD Dataset Builder"><span class="nav-badge-ai">DATA</span> <?= I18n::t('nav_dataset_builder') ?></a>
            <a href="strategies.php" class="nav-item <?= $activeNav === 'strategies' ? 'active' : '' ?>" title="ICT Strategy Library"><?= I18n::t('nav_strategies') ?></a>
            <a href="scanner.php" class="nav-item <?= $activeNav === 'scanner' ? 'active' : '' ?>" title="Signal Scanner"><?= I18n::t('nav_scanner') ?></a>
            <a href="mtf.php" class="nav-item <?= $activeNav === 'mtf' ? 'active' : '' ?>" title="Multi-Timeframe Analysis"><?= I18n::t('nav_mtf') ?></a>
            <a href="signals.php" class="nav-item <?= $activeNav === 'signals' ? 'active' : '' ?>" title="Signals &amp; Stats"><?= I18n::t('nav_signals') ?></a>
            <a href="backtest.php" class="nav-item <?= $activeNav === 'backtest' ? 'active' : '' ?>" title="Backtester"><?= I18n::t('nav_backtest') ?></a>
            <a href="training.php" class="nav-item <?= $activeNav === 'training' ? 'active' : '' ?>" title="AI Training Control Center"><span class="nav-badge-ai">ML</span> <?= I18n::t('nav_training') ?></a>
            <a href="calendar.php" class="nav-item <?= $activeNav === 'calendar' ? 'active' : '' ?>" title="Economic Calendar"><?= I18n::t('nav_calendar') ?></a>
            <a href="calculator.php" class="nav-item <?= $activeNav === 'calculator' ? 'active' : '' ?>" title="Risk Calculator"><?= I18n::t('nav_calculator') ?></a>
            <a href="settings.php" class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>" title="Settings"><?= I18n::t('nav_settings') ?></a>
            <?php if (Auth::hasRole('admin')): ?>
            <a href="admin.php" class="nav-item <?= $activeNav === 'admin' ? 'active' : '' ?>" title="Admin"><?= I18n::t('nav_admin') ?></a>
            <?php endif; ?>
        </nav>

        <div class="topbar-actions">
            <!-- Language Switcher (EN / KM) -->
            <button type="button" class="lang-toggle-btn" id="langToggleBtn" title="Switch Language (English / ភាសាខ្មែរ)">
                <?= $curLang === 'km' ? '🇰🇭 ខ្មែរ' : '🇺🇸 EN' ?>
            </button>

            <div class="clock-pill" title="Display timezone (storage is always UTC)">
                <span id="topClockUtc">--:-- UTC</span>
                <span class="clock-sep">|</span>
                <span id="topClockLocal"><?= Validator::e($tz) ?></span>
            </div>

            <button type="button" class="icon-btn" id="notifBellBtn" title="Notifications">
                🔔
                <span class="notif-count <?= $unreadCount > 0 ? '' : 'hidden' ?>" id="notifBadge"><?= $unreadCount ?></span>
            </button>

            <button type="button" class="icon-btn" id="themeToggleBtn" title="Toggle Dark/Light Theme">🌓</button>

            <div class="user-Chip">
                <span class="user-name"><?= Validator::e($user['display_name']) ?></span>
                <span class="badge badge-role"><?= Validator::e(strtoupper($user['role'])) ?></span>
                <a href="logout.php" class="logout-link" title="Sign out">Exit</a>
            </div>
        </div>
    </header>

    <!-- Notification Drawer -->
    <aside id="notifDrawer" class="notif-drawer hidden" aria-label="Notifications">
        <div class="notif-drawer-head">
            <strong>In-App Alerts</strong>
            <div>
                <button type="button" class="btn btn-xs btn-ghost" id="markAllReadBtn">Mark all read</button>
                <button type="button" class="btn btn-xs btn-ghost" id="closeNotifBtn">✕</button>
            </div>
        </div>
        <div id="notifList" class="notif-drawer-body">
            <div class="muted">Loading alerts...</div>
        </div>
    </aside>

    <div id="toastContainer" class="toast-container"></div>
    <div id="entryPrepPopupContainer" class="entry-prep-popup-container" aria-live="polite"></div>
    <main class="main-container">
    <?php
    return ['user' => $user, 'settings' => $uSettings, 'market' => $market];
}

function renderFooter(): void
{
    $jsVer = APP_VERSION . '.' . (int)@filemtime(APP_ROOT . '/assets/js/app.js');
    ?>
    </main>
    <footer class="app-footer">
        <div>
            <strong>GOLD SMC AI PRO</strong> v<?= Validator::e(APP_VERSION) ?> — Deterministic Rule-Based Smart Money Concepts Engine (BOS · CHOCH · MSS · OB · FVG · Liquidity Sweeps · Premium/Discount).
        </div>
        <div class="muted">
            Risk Warning: Spot gold (XAU/USD) &amp; Bitcoin (BTC/USD) trading involves substantial risk of loss. Setup quality scores measure rule confluence, not win probability.
        </div>
    </footer>
    <script src="assets/js/app.js?v=<?= Validator::e($jsVer) ?>"></script>
</body>
</html>
    <?php
}
