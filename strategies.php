<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;
use App\SMC\IctEngine;
use App\SMC\SmcEngine;

$layout = renderHeader('ICT Strategy Library', 'strategies');

$now = time();
$kz = IctEngine::killzones($now);
$macro = IctEngine::macroWindows($now);

// Fetch strategies from database
$strats = Database::fetchAll('SELECT * FROM ict_strategies ORDER BY category ASC, weight DESC');

// Check live detections on XAUUSD M15
$market = new MarketDataService(null, 'XAUUSD');
$candles = $market->candles('M15', 80);
$smcRes = (new SmcEngine())->analyze($candles);
$ictRes = (new IctEngine())->analyze($candles, $smcRes, $now);

$detectedMap = [];
foreach ($ictRes['detected_strategies'] as $ds) {
    $detectedMap[$ds['code']] = $ds;
}

$csrf = Csrf::token();
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>📚</span>
            <span><?= I18n::t('nav_strategies') ?> — Institutional Model Repository</span>
        </div>
        <div class="vision-hero-sub">
            Modular ICT &amp; SMC trading models. Enable, disable, or adjust confluence weighting. Each strategy operates deterministically on market structure without forcing signals.
        </div>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <span class="badge <?= $kz['is_killzone'] ? 'badge-live' : '' ?>" style="background: var(--bg-elevated); border: 1px solid var(--border);">
            🕒 NY Time: <strong><?= Validator::e($kz['ny_time']) ?></strong>
        </span>
        <span class="badge <?= $kz['is_killzone'] ? 'badge-live' : 'badge-warn' ?>">
            <?= $kz['active'] ? '● ' . Validator::e($kz['active']) : '○ Outside Major Killzone' ?>
        </span>
        <?php if ($macro['is_macro_window']): ?>
        <span class="badge" style="background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid #c084fc;">
            ⚡ <?= Validator::e($macro['active_macro']) ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
    <div class="vision-tabs" style="border: none; margin: 0;">
        <button type="button" class="vision-tab-btn active strat-filter-btn" data-filter="all">All Models (<?= count($strats) ?>)</button>
        <button type="button" class="vision-tab-btn strat-filter-btn" data-filter="ICT">ICT Setups</button>
        <button type="button" class="vision-tab-btn strat-filter-btn" data-filter="SMC">SMC Concepts</button>
        <button type="button" class="vision-tab-btn strat-filter-btn" data-filter="active">Live Active Now (<?= count($detectedMap) ?>)</button>
    </div>
    <div class="muted" style="font-size: 12px;">
        💡 Click on toggle switches to customize which strategies participate in confluence scoring.
    </div>
</div>

<div class="strat-library-grid" id="stratGrid">
    <?php foreach ($strats as $s): 
        $code = $s['code'];
        $isLive = isset($detectedMap[$code]);
        $liveData = $isLive ? $detectedMap[$code]['data'] : null;
        $tfs = json_decode((string)$s['timeframes_json'], true) ?: ['M15'];
    ?>
    <div class="strat-card" data-category="<?= Validator::e($s['category']) ?>" data-live="<?= $isLive ? '1' : '0' ?>" id="card-<?= Validator::e($code) ?>">
        <div>
            <div class="strat-card-header">
                <span class="strat-card-cat <?= $s['category'] === 'ICT' ? 'cat-ict' : 'cat-smc' ?>">
                    <?= Validator::e($s['category']) ?>
                </span>
                <?php if ($isLive): ?>
                <span class="badge badge-live" style="font-size: 10.5px;">● ACTIVE SETUP DETECTED</span>
                <?php endif; ?>
            </div>

            <h3 class="strat-card-title"><?= Validator::e($s['name']) ?></h3>
            <p class="strat-card-desc"><?= Validator::e($s['description']) ?></p>

            <div class="strat-card-rules">
                <strong style="color: var(--gold-bright);">Institutional Rules:</strong>
                <p style="margin-top: 4px; line-height: 1.4;"><?= Validator::e($s['rules_summary']) ?></p>
            </div>

            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;">
                <span class="badge" style="background: var(--bg-elevated); font-size: 11px;">TFs: <?= implode(' · ', array_map('htmlspecialchars', $tfs)) ?></span>
                <span class="badge" style="background: var(--bg-elevated); font-size: 11px;">Weight: <?= (int)$s['weight'] ?> pts</span>
            </div>

            <?php if ($isLive && $liveData): ?>
            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; padding: 8px 10px; font-size: 12px; margin-bottom: 12px;">
                <strong class="text-bull">Live Signal Metrics:</strong>
                <div>Direction: <strong><?= Validator::e($liveData['direction'] ?? 'N/A') ?></strong></div>
                <?php if (!empty($liveData['entry_zone'])): ?>
                <div>Zone: $<?= number_format((float)$liveData['entry_zone']['bottom'], 2) ?> – $<?= number_format((float)$liveData['entry_zone']['top'], 2) ?></div>
                <?php elseif (!empty($liveData['entry'])): ?>
                <div>Ref Entry: $<?= number_format((float)$liveData['entry'], 2) ?> | SL: $<?= number_format((float)$liveData['sl'], 2) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="strat-card-footer">
            <label style="cursor: pointer; display: flex; align-items: center; gap: 6px; user-select: none;">
                <input type="checkbox" class="strat-toggle" data-code="<?= Validator::e($code) ?>" <?= !empty($s['is_enabled']) ? 'checked' : '' ?>>
                <span>Include in Confluence</span>
            </label>
            <span class="muted" style="font-size: 11px;">Engine ID: <?= Validator::e($code) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Strategy Filter Tabs
    const filterBtns = document.querySelectorAll('.strat-filter-btn');
    const cards = document.querySelectorAll('.strat-card');

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const filter = btn.dataset.filter;

            cards.forEach(card => {
                if (filter === 'all') {
                    card.style.display = 'flex';
                } else if (filter === 'active') {
                    card.style.display = card.dataset.live === '1' ? 'flex' : 'none';
                } else {
                    card.style.display = card.dataset.category === filter ? 'flex' : 'none';
                }
            });
        });
    });

    // Toggle Strategy Participation via AJAX
    document.querySelectorAll('.strat-toggle').forEach(toggle => {
        toggle.addEventListener('change', async () => {
            const code = toggle.dataset.code;
            const isEnabled = toggle.checked ? 1 : 0;

            const fd = new FormData();
            fd.append('_csrf', '<?= Validator::e($csrf) ?>');
            fd.append('code', code);
            fd.append('is_enabled', isEnabled);

            try {
                const res = await fetch('api/strategies.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': '<?= Validator::e($csrf) ?>' },
                    body: fd
                });
                const data = await res.json();
                if (!data.ok) {
                    alert('Error updating strategy: ' + (data.error || 'Server error'));
                    toggle.checked = !toggle.checked;
                }
            } catch (err) {
                alert('Network error updating strategy: ' + err.message);
                toggle.checked = !toggle.checked;
            }
        });
    });
});
</script>

<?php
renderFooter();
