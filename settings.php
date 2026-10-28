<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Env;
use App\Core\Notifier;
use App\Core\Settings;
use App\Core\Validator;

$ctx = renderHeader('Account, Risk & Alert Settings', 'settings');
$user = $ctx['user'];
$s = $ctx['settings'];
$botToken = Notifier::botToken();
$tgChatValue = !empty($s['telegram_chat_id']) ? (string)$s['telegram_chat_id'] : '@i_am_Theaa_Rithhh';

$events = !empty($s['notify_events']) ? json_decode((string)$s['notify_events'], true) : ['signal' => true, 'news' => true];
$commonTimezones = [
    'UTC', 'Asia/Bangkok', 'Asia/Tokyo', 'Asia/Singapore', 'Asia/Dubai',
    'Europe/London', 'Europe/Zurich', 'Europe/Berlin',
    'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney',
];
if (!in_array($s['timezone'] ?? 'UTC', $commonTimezones, true)) {
    array_unshift($commonTimezones, $s['timezone']);
}
?>

<div class="grid-2">
    <!-- User Profile, Risk & Alert Preferences -->
    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">Trader Profile, Risk &amp; Notification Channels</div>
        </div>
        <div class="panel-body">
            <form id="profileForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="stName">Display Name</label>
                        <input type="text" id="stName" value="<?= Validator::e($user['display_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="stEmail">Email Address</label>
                        <input type="email" id="stEmail" value="<?= Validator::e($user['email']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label for="stTz">Display Timezone (Storage is UTC)</label>
                        <select id="stTz">
                            <?php foreach ($commonTimezones as $tz): ?>
                            <option value="<?= Validator::e($tz) ?>" <?= ($s['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= Validator::e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stTheme">Terminal Theme</label>
                        <select id="stTheme">
                            <option value="dark" <?= ($s['theme'] ?? 'dark') === 'dark' ? 'selected' : '' ?>>Dark Institutional</option>
                            <option value="light" <?= ($s['theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light Clean</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stBalance">Default Account Balance ($ USD)</label>
                        <input type="number" step="0.01" id="stBalance" value="<?= Validator::e((string)($s['account_balance'] ?? 10000)) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="stRisk">Default Risk % per Trade (Max <?= Validator::e((string)Settings::get('risk.max_risk_pct', 2.0)) ?>%)</label>
                        <input type="number" step="0.05" id="stRisk" value="<?= Validator::e((string)($s['risk_percent'] ?? 0.5)) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="stMinScore">Minimum Signal Alert Quality Score (&ge; 85 Confirmed Only)</label>
                        <input type="number" id="stMinScore" value="<?= (int)($s['min_alert_score'] ?? 85) ?>" min="85" max="100" required>
                    </div>
                    <div class="form-group">
                        <label for="stTgChat">Telegram Account / Chat ID</label>
                        <input type="text" id="stTgChat" value="<?= Validator::e($tgChatValue) ?>" placeholder="@i_am_Theaa_Rithhh or numeric Chat ID">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="stTgToken">Telegram Bot Token (from <code>@BotFather</code> — sends alerts to <code>@i_am_Theaa_Rithhh</code>)</label>
                        <input type="text" id="stTgToken" value="<?= Validator::e($botToken) ?>" placeholder="Paste BotFather token e.g. 1234567890:AAH... (leave blank if set in .env)">
                    </div>
                </div>

                <div class="stat-label" style="margin:12px 0 6px;">Alert Delivery Channels</div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stNotifyInapp" <?= !empty($s['notify_inapp']) ? 'checked' : '' ?>> In-App Feed
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stNotifyBrowser" <?= !empty($s['notify_browser']) ? 'checked' : '' ?>> Browser Desktop Notifications
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stNotifyTelegram" <?= !empty($s['notify_telegram']) ? 'checked' : '' ?>> Telegram Bot
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stNotifyEmail" <?= !empty($s['notify_email']) ? 'checked' : '' ?>> Email
                    </label>
                </div>

                <div class="stat-label" style="margin:12px 0 6px;">Alert Event Types</div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stEvPrepare" <?= ($events['prepare'] ?? true) ? 'checked' : '' ?>> Prepare-for-Entry Heads-Up (Near Confirmation &amp; Score 85–100%)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stEvSignal" <?= ($events['signal'] ?? true) ? 'checked' : '' ?>> Confirmed BUY / SELL Setups (&ge; 85–100%)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stEvTpSl" <?= ($events['tp_sl'] ?? true) ? 'checked' : '' ?>> Take Profit (TP1 / TP2 / TP3) &amp; Stop Loss (SL / BE) Hits
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="stEvNews" <?= ($events['news'] ?? true) ? 'checked' : '' ?>> High-Impact USD News Warnings
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </section>

    <!-- Channel Verification & Password Update -->
    <div>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Verify Notification Channels</div>
            </div>
            <div class="panel-body">
                <p class="muted" style="margin-bottom:10px;font-size:12px;">
                    Telegram target account: <strong><code><?= Validator::e($tgChatValue) ?></code></strong>
                    (Bot Token status: <span class="<?= $botToken !== '' ? 'text-bull' : 'text-gold' ?>"><strong><?= $botToken !== '' ? 'Configured ✅' : 'Paste @BotFather token on the left & click Save Settings' ?></strong></span>).
                </p>
                <div class="signal-banner wait" style="margin-bottom:12px;font-size:12px;padding:10px 12px;">
                    <strong>How Automatic Telegram Delivery Works for <code>@i_am_Theaa_Rithhh</code>:</strong>
                    <ol style="margin:6px 0 0 18px;">
                        <li>Open <strong><code>@BotFather</code></strong> on Telegram, type <code>/newbot</code> (or copy your bot's HTTP API Token) and paste it into <strong>Telegram Bot Token</strong> on the left, then click <strong>Save Settings</strong>.</li>
                        <li>From your Telegram account <strong><code>@i_am_Theaa_Rithhh</code></strong>, open your bot and press <strong>START (<code>/start</code>)</strong>.</li>
                        <li>Click <strong>✈️ Test Telegram</strong> below — the system will automatically link <code>@i_am_Theaa_Rithhh</code>'s Chat ID and send every qualified <code>XAUUSD</code> &amp; <code>BTCUSD</code> <code>BUY</code>/<code>SELL</code> signal to your Telegram!</li>
                    </ol>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-sm test-notif-btn" data-channel="inapp">🔔 Test In-App</button>
                    <button type="button" class="btn btn-sm btn-primary test-notif-btn" data-channel="telegram">✈️ Test Telegram (@i_am_Theaa_Rithhh)</button>
                    <button type="button" class="btn btn-sm" id="testPrepAlertBtn">⏳ Test Prepare-Entry Pop-Up &amp; Telegram</button>
                    <button type="button" class="btn btn-sm test-tpsl-btn" data-event="tp1_hit">✅ Test TP Hit Alert</button>
                    <button type="button" class="btn btn-sm test-tpsl-btn" data-event="sl_hit">🛑 Test SL Hit Alert</button>
                    <button type="button" class="btn btn-sm test-notif-btn" data-channel="email">✉️ Test Email</button>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Change Account Password</div>
            </div>
            <div class="panel-body">
                <form id="passwordForm">
                    <div class="form-group">
                        <label for="pwCurrent">Current Password</label>
                        <input type="password" id="pwCurrent" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="pwNew">New Password (minimum 10 characters)</label>
                        <input type="password" id="pwNew" required minlength="10" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('profileForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            if (document.getElementById('stNotifyBrowser').checked && 'Notification' in window && Notification.permission === 'default') {
                await Notification.requestPermission();
            }
            await GoldSmc.Api.post('api/settings.php', {
                action: 'user_profile',
                display_name: document.getElementById('stName').value,
                timezone: document.getElementById('stTz').value,
                theme: document.getElementById('stTheme').value,
                account_balance: document.getElementById('stBalance').value,
                risk_percent: document.getElementById('stRisk').value,
                min_alert_score: document.getElementById('stMinScore').value,
                telegram_chat_id: document.getElementById('stTgChat').value,
                telegram_bot_token: document.getElementById('stTgToken').value,
                notify_inapp: document.getElementById('stNotifyInapp').checked,
                notify_browser: document.getElementById('stNotifyBrowser').checked,
                notify_telegram: document.getElementById('stNotifyTelegram').checked,
                notify_email: document.getElementById('stNotifyEmail').checked,
                notify_events: {
                    prepare: document.getElementById('stEvPrepare').checked,
                    signal: document.getElementById('stEvSignal').checked,
                    tp_sl: document.getElementById('stEvTpSl').checked,
                    news: document.getElementById('stEvNews').checked,
                },
            });
            GoldSmc.toast('Profile and alert settings saved.');
            setTimeout(() => location.reload(), 600);
        } catch (err) {
            GoldSmc.toast(err.message, 'error');
        }
    });

    document.getElementById('passwordForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await GoldSmc.Api.post('api/settings.php', {
                action: 'change_password',
                current_password: document.getElementById('pwCurrent').value,
                new_password: document.getElementById('pwNew').value,
            });
            document.getElementById('passwordForm').reset();
            GoldSmc.toast('Password updated.');
        } catch (err) {
            GoldSmc.toast(err.message, 'error');
        }
    });

    document.querySelectorAll('.test-notif-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await GoldSmc.Api.post('api/notifications.php', {
                    action: 'test',
                    channel: btn.dataset.channel,
                });
                GoldSmc.toast(`Test ${btn.dataset.channel.toUpperCase()} notification sent.`);
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });

    document.querySelectorAll('.test-tpsl-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await GoldSmc.Api.post('api/notifications.php', {
                    action: 'test_tp_sl',
                    event_type: btn.dataset.event,
                });
                GoldSmc.toast(`Test ${btn.dataset.event.toUpperCase()} alert sent to Telegram (@i_am_Theaa_Rithhh).`);
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });

    document.getElementById('testPrepAlertBtn')?.addEventListener('click', async () => {
        try {
            const res = await GoldSmc.Api.post('api/notifications.php', {
                action: 'test_prepare',
                symbol: 'XAUUSD',
            });
            if (GoldSmc.checkSignalReadiness && res.payload) {
                GoldSmc.checkSignalReadiness(res.payload, 'XAUUSD', true);
            }
            GoldSmc.toast('Prepare-for-Entry pop-up & Telegram alert sent (@i_am_Theaa_Rithhh).');
        } catch (err) {
            GoldSmc.toast(err.message, 'error');
        }
    });
});
</script>

<?php renderFooter(); ?>
