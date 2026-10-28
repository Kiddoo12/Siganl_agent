<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Analysis\SignalTracker;
use App\Core\Database;
use App\Core\Validator;
use App\Market\MarketDataService;

$ctx = renderHeader('Signal Log & Performance Analytics', 'signals');
$market = $ctx['market'];

$selectedMode = strtolower(trim((string)($_GET['data_mode'] ?? $market->mode())));
if (!in_array($selectedMode, ['live', 'demo'], true)) {
    $selectedMode = $market->mode();
}
$symbolFilter = strtoupper(trim((string)($_GET['symbol'] ?? '')));
if ($symbolFilter !== '') {
    $symbolFilter = MarketDataService::normalizeSymbol($symbolFilter);
}
$dirFilter = strtoupper(trim((string)($_GET['direction'] ?? '')));
$outcomeFilter = strtolower(trim((string)($_GET['outcome'] ?? '')));

$tracker = new SignalTracker();
foreach (MarketDataService::SUPPORTED_SYMBOLS as $symKey) {
    $tracker->updateOpenSignals(new MarketDataService($symKey));
}
$stats = $tracker->performanceStats($selectedMode, $symbolFilter !== '' ? $symbolFilter : null);

$where = ['s.data_mode = ?'];
$params = [$selectedMode];
if ($symbolFilter !== '') {
    $where[] = 's.symbol = ?';
    $params[] = $symbolFilter;
}
if (in_array($dirFilter, ['BUY', 'SELL', 'WAIT'], true)) {
    $where[] = 's.direction = ?';
    $params[] = $dirFilter;
}
if (in_array($outcomeFilter, ['pending', 'open', 'win', 'loss', 'breakeven', 'expired', 'invalidated'], true)) {
    $where[] = 'o.outcome = ?';
    $params[] = $outcomeFilter;
}

$signals = Database::fetchAll(
    'SELECT s.*, o.outcome, o.entry_filled_at, o.exit_at, o.exit_price, o.exit_reason,
            o.r_multiple, o.ambiguous, o.max_favorable_r, o.max_adverse_r
     FROM trading_signals s
     LEFT JOIN signal_outcomes o ON o.signal_id = s.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY s.id DESC
     LIMIT 150',
    $params
);
?>

<section class="stat-strip">
    <div class="stat-card">
        <div class="stat-label">Dataset Mode</div>
        <div class="stat-value <?= $selectedMode === 'live' ? 'text-bull' : 'text-gold' ?>"><?= strtoupper($selectedMode) ?></div>
        <div class="stat-sub">Live &amp; Demo metrics are strictly separated</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Resolved Setups (W / L / BE)</div>
        <div class="stat-value"><?= (int)$stats['resolved'] ?></div>
        <div class="stat-sub">
            <span class="text-bull"><?= (int)$stats['counts']['win'] ?>W</span> ·
            <span class="text-bear"><?= (int)$stats['counts']['loss'] ?>L</span> ·
            <span><?= (int)$stats['counts']['breakeven'] ?>BE</span> ·
            <span><?= (int)$stats['counts']['open'] ?> Open</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Win Rate (Resolved)</div>
        <div class="stat-value"><?= $stats['win_rate'] !== null ? $stats['win_rate'] . '%' : '—' ?></div>
        <div class="stat-sub">Expired: <?= (int)$stats['counts']['expired'] ?> · Invalidated: <?= (int)$stats['counts']['invalidated'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Expectancy &amp; Profit Factor</div>
        <div class="stat-value <?= ($stats['expectancy_r'] ?? 0) >= 0 ? 'text-bull' : 'text-bear' ?>">
            <?= $stats['expectancy_r'] !== null ? ($stats['expectancy_r'] >= 0 ? '+' : '') . $stats['expectancy_r'] . 'R' : '—' ?>
        </div>
        <div class="stat-sub">Profit Factor: <strong><?= $stats['profit_factor'] ?? '—' ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cumulative Net R</div>
        <div class="stat-value <?= $stats['net_r'] >= 0 ? 'text-bull' : 'text-bear' ?>">
            <?= ($stats['net_r'] >= 0 ? '+' : '') . $stats['net_r'] ?>R
        </div>
        <div class="stat-sub">+<?= $stats['gross_win_r'] ?>R gross / -<?= $stats['gross_loss_r'] ?>R loss</div>
    </div>
</section>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">Cumulative R-Multiple Curve (<?= strtoupper($selectedMode) ?>)</div>
            <span class="muted" style="font-size:11.5px;"><?= Validator::e($stats['note']) ?></span>
        </div>
        <div class="panel-body">
            <canvas id="signalEquityCanvas" height="210" style="width:100%;display:block;"></canvas>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">Performance Breakdown by Session &amp; Setup Type</div>
        </div>
        <div class="panel-body">
            <div class="grid-2">
                <div>
                    <div class="stat-label" style="margin-bottom:6px;">By Session</div>
                    <table class="data-table">
                        <thead><tr><th>Session</th><th>Trades</th><th>Wins</th><th>Net R</th></tr></thead>
                        <tbody>
                            <?php if (!$stats['by_session']): ?>
                                <tr><td colspan="4" class="muted">No resolved trades yet</td></tr>
                            <?php else: foreach ($stats['by_session'] as $sess => $row): ?>
                                <tr>
                                    <td><?= Validator::e($sess) ?></td>
                                    <td class="mono"><?= (int)$row['trades'] ?></td>
                                    <td class="mono"><?= (int)$row['wins'] ?></td>
                                    <td class="mono <?= $row['net_r'] >= 0 ? 'text-bull' : 'text-bear' ?>"><?= ($row['net_r'] >= 0 ? '+' : '') . $row['net_r'] ?>R</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <div class="stat-label" style="margin-bottom:6px;">By Setup Architecture</div>
                    <table class="data-table">
                        <thead><tr><th>Setup Type</th><th>Trades</th><th>Wins</th><th>Net R</th></tr></thead>
                        <tbody>
                            <?php if (!$stats['by_setup']): ?>
                                <tr><td colspan="4" class="muted">No resolved trades yet</td></tr>
                            <?php else: foreach ($stats['by_setup'] as $st => $row): ?>
                                <tr>
                                    <td><?= Validator::e($st) ?></td>
                                    <td class="mono"><?= (int)$row['trades'] ?></td>
                                    <td class="mono"><?= (int)$row['wins'] ?></td>
                                    <td class="mono <?= $row['net_r'] >= 0 ? 'text-bull' : 'text-bear' ?>"><?= ($row['net_r'] >= 0 ? '+' : '') . $row['net_r'] ?>R</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-head">
        <div class="panel-title">Recorded Signal Log &amp; Outcome Lifecycle</div>
        <form method="get" action="signals.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select name="symbol" style="width:auto;">
                <option value="">All Symbols</option>
                <option value="XAUUSD" <?= $symbolFilter === 'XAUUSD' ? 'selected' : '' ?>>XAUUSD (Gold)</option>
                <option value="BTCUSD" <?= $symbolFilter === 'BTCUSD' ? 'selected' : '' ?>>BTCUSD (Bitcoin)</option>
            </select>
            <select name="data_mode" style="width:auto;">
                <option value="demo" <?= $selectedMode === 'demo' ? 'selected' : '' ?>>DEMO Data</option>
                <option value="live" <?= $selectedMode === 'live' ? 'selected' : '' ?>>LIVE Data</option>
            </select>
            <select name="direction" style="width:auto;">
                <option value="">All Directions</option>
                <option value="BUY" <?= $dirFilter === 'BUY' ? 'selected' : '' ?>>BUY</option>
                <option value="SELL" <?= $dirFilter === 'SELL' ? 'selected' : '' ?>>SELL</option>
                <option value="WAIT" <?= $dirFilter === 'WAIT' ? 'selected' : '' ?>>WAIT</option>
            </select>
            <select name="outcome" style="width:auto;">
                <option value="">All Outcomes</option>
                <?php foreach (['pending', 'open', 'win', 'loss', 'breakeven', 'expired', 'invalidated'] as $o): ?>
                <option value="<?= $o ?>" <?= $outcomeFilter === $o ? 'selected' : '' ?>><?= strtoupper($o) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="api/signals.php?export=csv&amp;data_mode=<?= urlencode($selectedMode) ?>&amp;symbol=<?= urlencode($symbolFilter) ?>" class="btn btn-sm">⬇ Export CSV</a>
        </form>
    </div>

    <div class="panel-body">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Time (UTC)</th>
                        <th>Symbol</th>
                        <th>Mode</th>
                        <th>TF</th>
                        <th>Signal</th>
                        <th>Setup Type</th>
                        <th>Score</th>
                        <th>Entry Zone</th>
                        <th>Entry</th>
                        <th>SL</th>
                        <th>TP1 / TP2</th>
                        <th>Status</th>
                        <th>Outcome</th>
                        <th>R-Mult</th>
                        <th>MFE / MAE</th>
                        <th>Exit Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$signals): ?>
                        <tr><td colspan="17" class="muted">No signals match the current filter.</td></tr>
                    <?php else: foreach ($signals as $s):
                        $d = $s['direction'];
                        $dBadge = $d === 'BUY' ? 'badge-bull' : ($d === 'SELL' ? 'badge-bear' : 'badge-warn');
                        $out = $s['outcome'] ?? '—';
                        $outBadge = $out === 'win' ? 'badge-bull' : ($out === 'loss' ? 'badge-bear' : 'badge-neutral');
                    ?>
                        <tr>
                            <td class="mono">#<?= (int)$s['id'] ?></td>
                            <td class="mono"><?= Validator::e(substr($s['created_at'], 0, 16)) ?></td>
                            <td class="mono"><strong><?= Validator::e($s['symbol'] ?? 'XAUUSD') ?></strong></td>
                            <td><span class="badge <?= $s['data_mode'] === 'live' ? 'badge-live' : 'badge-warn' ?>"><?= Validator::e($s['data_mode']) ?></span></td>
                            <td class="mono"><?= Validator::e($s['entry_tf']) ?></td>
                            <td><span class="badge <?= $dBadge ?>"><?= Validator::e($s['signal_label']) ?></span></td>
                            <td><?= Validator::e($s['setup_type'] ?? '—') ?></td>
                            <td class="mono"><strong><?= (int)$s['quality_score'] ?></strong>/100</td>
                            <td class="mono"><?= $s['entry_low'] ? sprintf('$%.2f–$%.2f', $s['entry_low'], $s['entry_high']) : '—' ?></td>
                            <td class="mono"><?= $s['entry_price'] ? '$' . number_format((float)$s['entry_price'], 2) : '—' ?></td>
                            <td class="mono text-bear"><?= $s['stop_loss'] ? '$' . number_format((float)$s['stop_loss'], 2) : '—' ?></td>
                            <td class="mono text-bull">
                                <?= $s['tp1'] ? '$' . number_format((float)$s['tp1'], 2) . ' (' . $s['rr1'] . 'R)' : '—' ?>
                                <?= $s['tp2'] ? ' / $' . number_format((float)$s['tp2'], 2) : '' ?>
                            </td>
                            <td><span class="badge badge-neutral"><?= Validator::e($s['setup_status']) ?></span></td>
                            <td><span class="badge <?= $outBadge ?>"><?= Validator::e((string)$out) ?></span></td>
                            <td class="mono <?= ($s['r_multiple'] ?? 0) > 0 ? 'text-bull' : (($s['r_multiple'] ?? 0) < 0 ? 'text-bear' : '') ?>">
                                <?= $s['r_multiple'] !== null ? ($s['r_multiple'] > 0 ? '+' : '') . $s['r_multiple'] . 'R' : '—' ?>
                            </td>
                            <td class="mono">
                                <?= $s['max_favorable_r'] !== null ? '+' . $s['max_favorable_r'] . 'R / -' . $s['max_adverse_r'] . 'R' : '—' ?>
                            </td>
                            <td class="muted"><?= Validator::e($s['exit_reason'] ?? '—') ?><?= !empty($s['ambiguous']) ? ' (ambiguous bar)' : '' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const curve = <?= json_encode($stats['curve'] ?? [], JSON_UNESCAPED_SLASHES) ?>;
    const pts = [{ cum_r: 0 }, ...curve];
    GoldSmc.renderEquityCurve(document.getElementById('signalEquityCanvas'), pts, 'cum_r', 'R');
});
</script>

<?php renderFooter(); ?>
