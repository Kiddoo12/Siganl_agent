<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Analysis\CalendarService;
use App\Core\Auth;
use App\Core\Settings;
use App\Core\Validator;

$ctx = renderHeader('Economic Calendar & News Filter', 'calendar');
$cal = new CalendarService();
$now = time();
$events = $cal->eventsBetween($now - 2 * 86400, $now + 10 * 86400);
$lastSync = Settings::state('calendar.last_sync');
$blockBefore = (int)Settings::get('signal.news_block_before_min', 30);
$blockAfter = (int)Settings::get('signal.news_block_after_min', 30);
?>

<div class="grid-terminal">
    <div>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Scheduled Economic Releases &amp; High-Impact Gold/USD Windows</div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="muted mono" style="font-size:11.5px;">
                        Blocker Window: -<?= $blockBefore ?>m to +<?= $blockAfter ?>m around HIGH impact USD news
                    </span>
                    <button type="button" class="btn btn-xs btn-primary" id="syncCalBtn">⟳ Sync Calendar</button>
                </div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>UTC Time</th>
                                <th>Country</th>
                                <th>Impact</th>
                                <th>Event Title</th>
                                <th>Forecast</th>
                                <th>Previous</th>
                                <th>Actual</th>
                                <th>Source</th>
                                <?php if (Auth::hasRole('analyst')): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$events): ?>
                                <tr><td colspan="9" class="muted">No economic events found in this date window.</td></tr>
                            <?php else: foreach ($events as $ev):
                                $ts = (int)strtotime($ev['event_time'] . ' UTC');
                                $isBlocking = $ev['impact'] === 'high' && $ev['country'] === 'USD'
                                    && $now >= ($ts - $blockBefore * 60) && $now <= ($ts + $blockAfter * 60);
                                $impBadge = $ev['impact'] === 'high' ? 'badge-bear' : ($ev['impact'] === 'medium' ? 'badge-warn' : 'badge-neutral');
                            ?>
                                <tr style="<?= $isBlocking ? 'background:rgba(239,68,68,0.12);' : '' ?>">
                                    <td class="mono"><?= Validator::e(substr($ev['event_time'], 0, 16)) ?> UTC</td>
                                    <td class="mono"><strong><?= Validator::e($ev['country']) ?></strong></td>
                                    <td>
                                        <span class="badge <?= $impBadge ?>"><?= Validator::e($ev['impact']) ?></span>
                                        <?php if ($isBlocking): ?><span class="badge badge-bear">ACTIVE BLOCK</span><?php endif; ?>
                                    </td>
                                    <td><strong><?= Validator::e($ev['title']) ?></strong></td>
                                    <td class="mono"><?= Validator::e($ev['forecast'] ?? '—') ?></td>
                                    <td class="mono"><?= Validator::e($ev['previous'] ?? '—') ?></td>
                                    <td class="mono"><?= Validator::e($ev['actual'] ?? '—') ?></td>
                                    <td><span class="badge badge-neutral"><?= Validator::e($ev['source']) ?></span></td>
                                    <?php if (Auth::hasRole('analyst')): ?>
                                    <td>
                                        <button type="button" class="btn btn-xs btn-danger del-ev-btn" data-id="<?= (int)$ev['id'] ?>">✕</button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Calendar Feed Health</div>
            </div>
            <div class="panel-body">
                <div class="level-box" style="margin-bottom:10px;">
                    <div class="lbl">Configured Provider</div>
                    <div class="val"><?= Validator::e(strtoupper($cal->providerCode())) ?></div>
                    <div class="muted" style="font-size:11.5px;margin-top:4px;">
                        Last Sync: <?= !empty($lastSync['ts']) ? gmdate('Y-m-d H:i:s', (int)$lastSync['ts']) . ' UTC' : 'Never' ?>
                    </div>
                </div>
                <p class="muted" style="font-size:12px;">
                    High-impact USD news releases (CPI, NFP, FOMC, Powell speeches, Retail Sales) cause extreme XAU/USD spread widening and whip-saw liquidity sweeps. The SMC engine automatically blocks new trade signals <?= $blockBefore ?> minutes before and <?= $blockAfter ?> minutes after high-impact releases.
                </p>
            </div>
        </section>

        <?php if (Auth::hasRole('analyst')): ?>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Add Manual Economic Event</div>
            </div>
            <div class="panel-body">
                <form id="addEventForm">
                    <div class="form-group">
                        <label for="evTitle">Event Title</label>
                        <input type="text" id="evTitle" required placeholder="e.g. FOMC Rate Decision">
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="evCountry">Currency / Country</label>
                            <input type="text" id="evCountry" value="USD" required maxlength="8">
                        </div>
                        <div class="form-group">
                            <label for="evImpact">Impact</label>
                            <select id="evImpact">
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                                <option value="holiday">Holiday</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="evTime">UTC Date &amp; Time (YYYY-MM-DD HH:MM)</label>
                        <input type="text" id="evTime" value="<?= gmdate('Y-m-d H:i', time() + 3600) ?>" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="evForecast">Forecast</label>
                            <input type="text" id="evForecast" placeholder="e.g. 3.2%">
                        </div>
                        <div class="form-group">
                            <label for="evPrev">Previous</label>
                            <input type="text" id="evPrev" placeholder="e.g. 3.3%">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">+ Save Economic Event</button>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const syncBtn = document.getElementById('syncCalBtn');
    if (syncBtn) {
        syncBtn.addEventListener('click', async () => {
            syncBtn.disabled = true;
            syncBtn.textContent = 'Syncing...';
            try {
                const res = await GoldSmc.Api.post('api/calendar.php', { action: 'sync' });
                if (res.sync?.ok) {
                    GoldSmc.toast(`Calendar synced (${res.sync.imported} events).`);
                    setTimeout(() => location.reload(), 700);
                } else {
                    GoldSmc.toast(res.sync?.error || 'Sync failed', 'error');
                }
            } catch (e) {
                GoldSmc.toast(e.message, 'error');
            } finally {
                syncBtn.disabled = false;
                syncBtn.textContent = '⟳ Sync Calendar';
            }
        });
    }

    const addForm = document.getElementById('addEventForm');
    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await GoldSmc.Api.post('api/calendar.php', {
                    action: 'add',
                    title: document.getElementById('evTitle').value,
                    country: document.getElementById('evCountry').value,
                    impact: document.getElementById('evImpact').value,
                    event_time: document.getElementById('evTime').value,
                    forecast: document.getElementById('evForecast').value,
                    previous: document.getElementById('evPrev').value,
                });
                GoldSmc.toast('Event added.');
                setTimeout(() => location.reload(), 500);
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    }

    document.querySelectorAll('.del-ev-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await GoldSmc.Api.post('api/calendar.php', { action: 'delete', id: btn.dataset.id });
                btn.closest('tr').remove();
                GoldSmc.toast('Event removed.');
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    });
});
</script>

<?php renderFooter(); ?>
