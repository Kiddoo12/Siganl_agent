<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\Validator;

Auth::requirePage('admin');
$ctx = renderHeader('Admin Control Center', 'admin');

$sources = Database::fetchAll('SELECT * FROM market_data_sources ORDER BY id ASC');
$users = Database::fetchAll('SELECT id, email, display_name, role, is_active, last_login_at, created_at FROM users ORDER BY id ASC');
$auditLogs = Database::fetchAll(
    'SELECT a.*, u.email AS user_email
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT 40'
);
$appLogs = Logger::tail(40);
$overrides = Settings::overrides();

// Key tunable parameters exposed in the Admin dashboard
$tunableKeys = [
    'market.entry_tf'                => 'Entry Timeframe (M15 / M30 / H1 / M5)',
    'market.poi_tf'                  => 'POI / Dealing Range Timeframe (H1 / H4)',
    'smc.swing_left'                 => 'Swing Pivot Left Lookback Candles',
    'smc.swing_right'                => 'Swing Pivot Right Confirmation Candles',
    'smc.break_buffer_atr'           => 'BOS / CHOCH Close Buffer (x ATR)',
    'smc.displacement_body_ratio'    => 'Displacement Minimum Body/Range Ratio',
    'smc.displacement_atr_mult'      => 'Displacement Minimum Range (x ATR)',
    'smc.fvg_min_atr'                => 'Minimum Fair Value Gap Size (x ATR)',
    'smc.ob_lookback'                => 'Order Block Search Lookback Candles',
    'smc.ob_max_age'                 => 'Order Block Expiry Age (Candles)',
    'smc.sweep_min_atr'              => 'Liquidity Sweep Min Penetration (x ATR)',
    'signal.min_score'               => 'Minimum Setup Quality Score (0–100)',
    'signal.strong_score'            => 'Strong Setup Threshold Score (0–100)',
    'signal.min_rr'                  => 'Minimum Required Target R:R',
    'signal.max_spread'              => 'Maximum Allowed Spread ($/oz)',
    'signal.assumed_spread'          => 'Assumed Spread when Bid/Ask Unavailable ($/oz)',
    'signal.news_block_before_min'   => 'High-Impact News Block Before (minutes)',
    'signal.news_block_after_min'    => 'High-Impact News Block After (minutes)',
    'risk.max_risk_pct'              => 'Hard Cap Maximum Risk % per Trade',
    'tracking.partial_at_tp1'        => 'Fraction Closed at TP1 (0.0 – 1.0)',
];
?>

<section class="stat-strip">
    <div class="stat-card">
        <div class="stat-label">Configured Market Provider</div>
        <div class="stat-value"><?= Validator::e(strtoupper((string)Env::get('MARKET_PROVIDER', 'demo'))) ?></div>
        <div class="stat-sub">Twelve Data Key: <?= Env::get('TWELVEDATA_API_KEY') ? 'Configured' : 'Not set' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">AI Explanation Provider</div>
        <div class="stat-value"><?= Validator::e(strtoupper((string)Env::get('AI_PROVIDER', 'none'))) ?></div>
        <div class="stat-sub">Model: <?= Validator::e((string)Env::get('AI_MODEL', 'rule-engine')) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Stored Market Candles</div>
        <div class="stat-value"><?= number_format((int)Database::value('SELECT COUNT(*) FROM market_candles')) ?></div>
        <div class="stat-sub">Analyses: <?= number_format((int)Database::value('SELECT COUNT(*) FROM market_analysis')) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Parameter Overrides</div>
        <div class="stat-value"><?= count($overrides) ?></div>
        <div class="stat-sub">Users: <?= count($users) ?></div>
    </div>
</section>

<div class="grid-2">
    <!-- Tunable SMC / Signal / Risk Engine Parameters -->
    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">SMC Engine, Signal &amp; Risk Parameter Overrides</div>
        </div>
        <div class="panel-body">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Parameter Key</th><th>Description</th><th>Current Value</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tunableKeys as $key => $desc):
                            $val = Settings::get($key);
                            $isOverridden = isset($overrides[$key]);
                        ?>
                        <tr>
                            <td class="mono">
                                <?= Validator::e($key) ?>
                                <?php if ($isOverridden): ?><span class="badge badge-gold">OVERRIDDEN</span><?php endif; ?>
                            </td>
                            <td class="muted"><?= Validator::e($desc) ?></td>
                            <td>
                                <input type="text" class="param-input mono" data-key="<?= Validator::e($key) ?>"
                                       value="<?= Validator::e(is_bool($val) ? ($val ? 'true' : 'false') : (string)$val) ?>"
                                       style="width:110px;padding:4px 7px;">
                            </td>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary save-param-btn" data-key="<?= Validator::e($key) ?>">Save</button>
                                <?php if ($isOverridden): ?>
                                <button type="button" class="btn btn-xs reset-param-btn" data-key="<?= Validator::e($key) ?>">Reset</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Data Sources & User Management -->
    <div>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Market Data Sources Health</div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Code</th><th>Type</th><th>Last Success UTC</th><th>Last Error</th></tr></thead>
                        <tbody>
                            <?php foreach ($sources as $src): ?>
                            <tr>
                                <td class="mono"><strong><?= Validator::e($src['code']) ?></strong></td>
                                <td><span class="badge <?= $src['type'] === 'live' ? 'badge-live' : 'badge-warn' ?>"><?= Validator::e($src['type']) ?></span></td>
                                <td class="mono"><?= Validator::e($src['last_success_at'] ?? 'Never') ?></td>
                                <td class="text-bear"><?= Validator::e($src['last_error'] ?? 'None') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">User &amp; Role Management</div>
            </div>
            <div class="panel-body">
                <form id="createUserForm" style="margin-bottom:14px;">
                    <div class="form-grid">
                        <input type="email" id="nuEmail" placeholder="Email address" required>
                        <input type="text" id="nuName" placeholder="Display name" required>
                        <input type="password" id="nuPass" placeholder="Password (min 10 chars)" required minlength="10">
                        <select id="nuRole">
                            <option value="trader">Trader</option>
                            <option value="analyst">Analyst</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" style="margin-top:8px;">+ Create User</button>
                </form>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>User</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="mono">#<?= (int)$u['id'] ?></td>
                                <td>
                                    <div><strong><?= Validator::e($u['display_name']) ?></strong></div>
                                    <div class="muted mono" style="font-size:11px;"><?= Validator::e($u['email']) ?></div>
                                </td>
                                <td>
                                    <select class="user-role-sel" data-id="<?= (int)$u['id'] ?>" style="width:auto;padding:3px 6px;">
                                        <?php foreach (['trader', 'analyst', 'admin'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= strtoupper($r) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span class="badge <?= (int)$u['is_active'] ? 'badge-bull' : 'badge-bear' ?>">
                                        <?= (int)$u['is_active'] ? 'ACTIVE' : 'DISABLED' ?>
                                    </span>
                                </td>
                                <td class="mono"><?= Validator::e($u['last_login_at'] ?? 'Never') ?></td>
                                <td>
                                    <button type="button" class="btn btn-xs toggle-user-btn"
                                            data-id="<?= (int)$u['id'] ?>"
                                            data-active="<?= (int)$u['is_active'] ? '0' : '1' ?>">
                                        <?= (int)$u['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><div class="panel-title">Security &amp; Configuration Audit Trail</div></div>
        <div class="panel-body">
            <div class="table-wrap" style="max-height:300px;overflow-y:auto;">
                <table class="data-table">
                    <thead><tr><th>UTC Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
                    <tbody>
                        <?php foreach ($auditLogs as $al): ?>
                        <tr>
                            <td class="mono"><?= Validator::e(substr($al['created_at'], 0, 19)) ?></td>
                            <td><?= Validator::e($al['user_email'] ?? 'system/cli') ?></td>
                            <td class="mono"><strong><?= Validator::e($al['action']) ?></strong></td>
                            <td class="mono"><?= Validator::e(($al['entity'] ?? '') . ($al['entity_id'] ? '#' . $al['entity_id'] : '')) ?></td>
                            <td class="mono muted"><?= Validator::e($al['ip'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div class="panel-title">Application Diagnostic Log (Today)</div></div>
        <div class="panel-body">
            <div class="table-wrap" style="max-height:300px;overflow-y:auto;">
                <table class="data-table">
                    <thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead>
                    <tbody>
                        <?php if (!$appLogs): ?>
                            <tr><td colspan="4" class="muted">No log entries recorded today.</td></tr>
                        <?php else: foreach ($appLogs as $lg): ?>
                            <tr>
                                <td class="mono"><?= Validator::e(substr((string)($lg['ts'] ?? ''), 11, 8)) ?></td>
                                <td><span class="badge <?= ($lg['level'] ?? '') === 'error' ? 'badge-bear' : (($lg['level'] ?? '') === 'warning' ? 'badge-warn' : 'badge-neutral') ?>"><?= Validator::e($lg['level'] ?? 'info') ?></span></td>
                                <td><?= Validator::e($lg['msg'] ?? '') ?></td>
                                <td class="mono muted"><?= Validator::e(json_encode($lg['ctx'] ?? [])) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.save-param-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const key = btn.dataset.key;
            const input = document.querySelector(`.param-input[data-key="${key}"]`);
            try {
                await GoldSmc.Api.post('api/settings.php', {
                    action: 'system_setting',
                    key,
                    value: input.value,
                });
                GoldSmc.toast(`Saved ${key}`);
                setTimeout(() => location.reload(), 500);
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });

    document.querySelectorAll('.reset-param-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const key = btn.dataset.key;
            try {
                await GoldSmc.Api.post('api/settings.php', {
                    action: 'system_setting',
                    key,
                    reset: true,
                });
                GoldSmc.toast(`Reset ${key} to default`);
                setTimeout(() => location.reload(), 500);
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });

    document.getElementById('createUserForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await GoldSmc.Api.post('api/settings.php', {
                action: 'user_manage',
                sub_action: 'create',
                email: document.getElementById('nuEmail').value,
                display_name: document.getElementById('nuName').value,
                password: document.getElementById('nuPass').value,
                role: document.getElementById('nuRole').value,
            });
            GoldSmc.toast('User created.');
            setTimeout(() => location.reload(), 500);
        } catch (err) {
            GoldSmc.toast(err.message, 'error');
        }
    });

    document.querySelectorAll('.user-role-sel').forEach(sel => {
        sel.addEventListener('change', async () => {
            try {
                await GoldSmc.Api.post('api/settings.php', {
                    action: 'user_manage',
                    sub_action: 'update_role',
                    user_id: sel.dataset.id,
                    role: sel.value,
                });
                GoldSmc.toast('Role updated.');
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });

    document.querySelectorAll('.toggle-user-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await GoldSmc.Api.post('api/settings.php', {
                    action: 'user_manage',
                    sub_action: 'toggle_active',
                    user_id: btn.dataset.id,
                    is_active: Number(btn.dataset.active),
                });
                setTimeout(() => location.reload(), 400);
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });
});
</script>

<?php renderFooter(); ?>
