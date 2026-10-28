<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Validator;
use App\SMC\IctEngine;

$layout = renderHeader('Signal Scanner', 'scanner');
$now = time();
$kz = IctEngine::killzones($now);
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>📡</span>
            <span><?= I18n::t('nav_scanner') ?> — Real-Time Multi-Asset SMC/ICT Scanner</span>
        </div>
        <div class="vision-hero-sub">
            Continuous background monitor scanning XAUUSD (Spot Gold) &amp; BTCUSD across multiple timeframes. Detects high-confluence institutional setups, Silver Bullet killzones, and structural breaks.
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <span class="badge <?= $kz['is_killzone'] ? 'badge-live' : '' ?>" style="background: var(--bg-elevated); border: 1px solid var(--border);">
            NY: <strong><?= Validator::e($kz['ny_time']) ?></strong>
        </span>
        <span class="badge <?= $kz['is_killzone'] ? 'badge-live' : 'badge-warn' ?>">
            <?= $kz['active'] ? '● ' . Validator::e($kz['active']) : '○ Outside Killzone' ?>
        </span>
        <button type="button" class="btn btn-sm btn-primary" id="refreshScannerBtn">🔄 Scan Now</button>
    </div>
</div>

<div class="scanner-grid" id="scannerGrid">
    <div style="grid-column: 1 / -1; text-align: center; padding: 40px;" class="muted">
        Loading real-time market scan across XAUUSD &amp; BTCUSD...
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('scannerGrid');
    const refreshBtn = document.getElementById('refreshScannerBtn');

    async function loadScanner() {
        refreshBtn.disabled = true;
        refreshBtn.textContent = '⏳ Scanning...';

        try {
            const res = await fetch('api/scanner.php');
            const data = await res.json();
            refreshBtn.disabled = false;
            refreshBtn.textContent = '🔄 Scan Now';

            if (!data.ok || !data.results) {
                grid.innerHTML = '<div class="text-bear" style="grid-column: 1 / -1; text-align: center;">Scan failed.</div>';
                return;
            }

            grid.innerHTML = '';
            data.results.forEach(r => {
                const isBuy = r.direction === 'BUY';
                const isSell = r.direction === 'SELL';
                const statusClass = isBuy ? 'status-buy' : (isSell ? 'status-sell' : 'status-wait');
                const badgeColor = isBuy ? 'badge-live' : (isSell ? 'badge-warn' : '');

                const tile = document.createElement('div');
                tile.className = `scanner-tile ${statusClass}`;
                tile.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div>
                            <strong style="font-size: 15px;">${r.symbol}</strong>
                            <span class="badge" style="background: var(--bg-elevated); font-size: 11px; margin-left: 4px;">${r.timeframe}</span>
                        </div>
                        <span class="badge ${badgeColor}" style="font-weight: 800;">${r.direction}</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px;">
                        <span class="mono" style="font-size: 18px; font-weight: 900;">$${Number(r.price).toFixed(2)}</span>
                        <span class="muted" style="font-size: 11.5px;">ATR: $${r.atr}</span>
                    </div>

                    <div style="background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-size: 12px; margin-bottom: 10px;">
                        <div style="color: var(--text-muted); font-size: 11px;">Active Strategy</div>
                        <strong style="color: #c084fc;">${r.strategy}</strong>
                        <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                            <span>Score: <strong>${r.score}/100</strong></span>
                            <span class="${isBuy ? 'text-bull' : (isSell ? 'text-bear' : 'text-gold')}">${r.status}</span>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 11.5px; font-family: var(--font-mono); margin-bottom: 12px;">
                        <div>SL: <span class="text-bear">$${Number(r.sl).toFixed(2)}</span></div>
                        <div>TP1: <span class="text-bull">$${Number(r.tp1).toFixed(2)}</span></div>
                    </div>

                    <a href="index.php?symbol=${r.symbol}&tf=${r.timeframe}" class="btn btn-xs btn-ghost" style="width: 100%; text-align: center; display: block;">
                        Open Chart Terminal →
                    </a>
                `;
                grid.appendChild(tile);
            });
        } catch (err) {
            refreshBtn.disabled = false;
            refreshBtn.textContent = '🔄 Scan Now';
            grid.innerHTML = `<div class="text-bear" style="grid-column: 1 / -1; text-align: center;">Scan error: ${err.message}</div>`;
        }
    }

    loadScanner();
    refreshBtn.addEventListener('click', loadScanner);

    // Auto refresh every 20 seconds
    setInterval(loadScanner, 20000);
});
</script>

<?php
renderFooter();
