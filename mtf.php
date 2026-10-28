<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Analysis\AnalysisService;
use App\Analysis\MultiTimeframeAnalyzer;
use App\Core\Auth;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;

$symbol = isset($_GET['symbol']) ? Validator::str($_GET['symbol'], 1, 16) : 'XAUUSD';
$symbol = MarketDataService::normalizeSymbol($symbol);

$layout = renderHeader('Multi-Timeframe Analysis', 'mtf');

$market = new MarketDataService(null, $symbol);
$tfs = ['D1', 'H4', 'H1', 'M30', 'M15', 'M5'];
$cByTf = [];
foreach ($tfs as $tf) {
    $cByTf[$tf] = $market->candles($tf, 200);
}

$quote = $market->quote(false);
$curPrice = $quote['available'] ? (float)$quote['price'] : 2400.0;

$analyzer = MultiTimeframeAnalyzer::fromSettings($symbol);
$mtaRes = $analyzer->analyze($cByTf, [
    'now'            => time(),
    'price'          => $curPrice,
    'spread'         => 0.30,
    'spread_source'  => 'assumed',
    'events'         => [],
    'news_connected' => true,
    'data_mode'      => $market->mode(),
    'source'         => $market->provider()->label(),
]);

$tfData = $mtaRes['timeframes'] ?? [];
$htfBias = $mtaRes['htf_bias'] ?? 'neutral';
$votes = $mtaRes['htf_votes'] ?? [];
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>🧭</span>
            <span><?= I18n::t('nav_mtf') ?> — <?= Validator::e($symbol) ?> SMC Alignment Matrix</span>
        </div>
        <div class="vision-hero-sub">
            Hierarchical top-down analysis: D1/H4 (Institutional Bias) → H1/M30 (Dealing Range &amp; POI) → M15/M5 (Execution &amp; Trigger).
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <select class="form-select" onchange="window.location.href='mtf.php?symbol=' + this.value">
            <option value="XAUUSD" <?= $symbol === 'XAUUSD' ? 'selected' : '' ?>>XAU/USD (Spot Gold)</option>
            <option value="BTCUSD" <?= $symbol === 'BTCUSD' ? 'selected' : '' ?>>BTC/USD (Bitcoin)</option>
        </select>
        <span class="badge <?= $htfBias === 'bullish' ? 'badge-live' : ($htfBias === 'bearish' ? 'badge-warn' : '') ?>" style="font-weight: 800; padding: 6px 12px;">
            HTF BIAS: <?= strtoupper(Validator::e($htfBias)) ?>
        </span>
    </div>
</div>

<div class="mtf-table-container" style="margin-bottom: 24px;">
    <table class="mtf-table">
        <thead>
            <tr>
                <th>Timeframe Role</th>
                <th>TF</th>
                <th>Trend Structure</th>
                <th>Dealing Range (P/D)</th>
                <th>50% EQ</th>
                <th>Volatility (ATR)</th>
                <th>Fresh OBs</th>
                <th>Open FVGs</th>
                <th>Last Structure Break</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $roles = [
                'D1'  => 'Macro Bias & Liquidity',
                'H4'  => 'Intermediate Bias',
                'H1'  => 'Intraday POI & Dealing Range',
                'M30' => 'Structure Confirmation',
                'M15' => 'Execution Trigger (Primary)',
                'M5'  => 'Entry Refinement',
            ];
            foreach ($tfs as $tf):
                $r = $tfData[$tf] ?? [];
                $trend = $r['trend'] ?? 'neutral';
                $pd = $r['pd'] ?? null;
                $lastEvt = $r['last_event'] ?? null;
            ?>
            <tr>
                <td><strong><?= $roles[$tf] ?? 'Analysis' ?></strong></td>
                <td><span class="badge" style="background: var(--bg-elevated); font-size: 12px;"><?= $tf ?></span></td>
                <td>
                    <span class="badge <?= $trend === 'bullish' ? 'badge-live' : ($trend === 'bearish' ? 'badge-warn' : '') ?>">
                        <?= strtoupper($trend) ?>
                    </span>
                </td>
                <td>
                    <?php if ($pd): ?>
                    <span class="badge" style="background: <?= $pd['zone'] === 'discount' ? 'rgba(16, 185, 129, 0.15)' : ($pd['zone'] === 'premium' ? 'rgba(239, 68, 68, 0.15)' : 'rgba(245, 158, 11, 0.15)') ?>; color: <?= $pd['zone'] === 'discount' ? '#10b981' : ($pd['zone'] === 'premium' ? '#ef4444' : '#f59e0b') ?>;">
                        <?= ucfirst($pd['zone']) ?> (<?= $pd['position_pct'] ?>%)
                    </span>
                    <?php else: ?>
                    <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= $pd ? '$' . number_format((float)$pd['eq'], 2) : '—' ?></td>
                <td class="mono">$<?= isset($r['atr']) ? number_format((float)$r['atr'], 2) : '—' ?></td>
                <td><?= (int)($r['fresh_obs'] ?? 0) ?> Fresh</td>
                <td><?= (int)($r['open_fvgs'] ?? 0) ?> Open</td>
                <td>
                    <?php if ($lastEvt): ?>
                    <strong><?= $lastEvt['dir'] === 1 ? 'Bullish' : 'Bearish' ?> <?= $lastEvt['type'] ?></strong>
                    <span class="muted mono">@ $<?= number_format((float)$lastEvt['level'], 2) ?></span>
                    <?php else: ?>
                    <span class="muted">None in range</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <div class="calc-card">
        <h3 style="font-size: 15px; font-weight: 800; margin-bottom: 12px;">Institutional Confluence Verdict</h3>
        <p style="font-size: 13px; line-height: 1.6; margin-bottom: 14px;">
            <?php if ($htfBias === 'bullish'): ?>
            Higher timeframes (<?= implode(', ', array_keys($votes)) ?>) are aligned <strong>BULLISH</strong>. The system seeks discount liquidity sweeps and displacement Fair Value Gaps on lower execution timeframes (M15/M5).
            <?php elseif ($htfBias === 'bearish'): ?>
            Higher timeframes (<?= implode(', ', array_keys($votes)) ?>) are aligned <strong>BEARISH</strong>. The system seeks premium buy-side liquidity raids and displacement Bearish Order Blocks / FVGs.
            <?php else: ?>
            Higher timeframes display conflicting directional trends. To preserve capital and avoid low-probability whipsaws, the institutional engine stands aside with <strong>NO TRADE / WAITING</strong>.
            <?php endif; ?>
        </p>
        <div style="display: flex; gap: 10px;">
            <a href="index.php?symbol=<?= $symbol ?>&tf=M15" class="btn btn-sm btn-primary">Open Terminal Chart →</a>
            <a href="vision.php?symbol=<?= $symbol ?>" class="btn btn-sm btn-ghost">Upload Chart Screenshot →</a>
        </div>
    </div>

    <div class="calc-card">
        <h3 style="font-size: 15px; font-weight: 800; margin-bottom: 12px;">Nearest Resting Liquidity Targets</h3>
        <?php $liq = $mtaRes['nearest_liquidity'] ?? []; ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
            <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                <span class="text-bull" style="font-weight: 700; font-size: 12px;">Nearest BSL (High)</span>
                <div class="mono" style="font-size: 17px; font-weight: 800; margin-top: 4px;">
                    <?= !empty($liq['bsl']) ? '$' . number_format((float)$liq['bsl'], 2) : 'None' ?>
                </div>
            </div>
            <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                <span class="text-bear" style="font-weight: 700; font-size: 12px;">Nearest SSL (Low)</span>
                <div class="mono" style="font-size: 17px; font-weight: 800; margin-top: 4px;">
                    <?= !empty($liq['ssl']) ? '$' . number_format((float)$liq['ssl'], 2) : 'None' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
renderFooter();
