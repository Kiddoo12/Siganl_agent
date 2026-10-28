<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Database;
use App\Core\Settings;
use App\Core\Validator;

$ctx = renderHeader('Walk-Forward Quantitative Backtester', 'backtest');
$uSettings = $ctx['settings'];

$runs = Database::fetchAll('SELECT * FROM backtest_runs ORDER BY id DESC LIMIT 30');
$defaultFrom = gmdate('Y-m-d', time() - 14 * 86400);
$defaultTo = gmdate('Y-m-d');
$emergencyStopActive = (bool)Settings::get('risk.emergency_stop', false);
?>

<div class="grid-terminal">
    <!-- Left Column: Backtest Results, Validation Gate, Equity Curve & Trade Table -->
    <div>
        <section class="panel">
            <div class="panel-head" style="align-items:center;">
                <div class="panel-title">
                    <span>Walk-Forward Quantitative Backtester</span>
                    <span class="muted" style="font-size:11px;font-weight:normal;">(Zero Look-Ahead · Friction Deducted)</span>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <span class="badge badge-cyan" id="btRunBadge">Select or Run a Simulation</span>
                    <span class="badge badge-warn" id="btValidationBadge" style="font-weight:700;">AWAITING EVALUATION</span>
                </div>
            </div>

            <!-- Institutional Risk Kill Switch & Non-Guarantee Disclosure Strip -->
            <div style="background:var(--bg-elevated);border-bottom:1px solid var(--border);padding:8px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="font-size:12px;display:flex;align-items:center;gap:6px;">
                        <span class="muted">Risk Circuit Breaker:</span>
                        <span id="emergencyStatusPill" class="badge <?= $emergencyStopActive ? 'badge-bear' : 'badge-bull' ?>" style="font-size:11px;font-weight:700;">
                            <?= $emergencyStopActive ? '⛔ EMERGENCY STOP ACTIVE' : '🟢 NORMAL OPERATION' ?>
                        </span>
                    </div>
                    <button type="button" id="toggleEmergencyBtn" class="btn btn-xs <?= $emergencyStopActive ? 'btn-primary' : 'btn-danger' ?>" style="font-size:11px;">
                        <?= $emergencyStopActive ? '▶ Deactivate Emergency Stop' : '⚠️ Trigger Emergency Stop' ?>
                    </button>
                </div>
                <div class="muted" style="font-size:11px;max-width:560px;line-height:1.3;">
                    ⚖️ <strong>Institutional Gate:</strong> Walk-Forward 70% In-Sample / 30% Out-of-Sample split. Stress tests slippage &amp; spread widening. Positive backtests do not guarantee future profits.
                </div>
            </div>

            <div class="panel-body">
                <!-- Stat Strip 1: Core Performance -->
                <div class="stat-strip" id="btStatsStrip" style="margin-bottom:10px;">
                    <div class="stat-card">
                        <div class="stat-label">Total Trades</div>
                        <div class="stat-value" id="btTotalTrades">—</div>
                        <div class="stat-sub" id="btWinLoss">—</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Win Rate &amp; PF</div>
                        <div class="stat-value" id="btWinRate">—</div>
                        <div class="stat-sub" id="btPf">Profit Factor: —</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Expectancy / Net R</div>
                        <div class="stat-value" id="btExpR">—</div>
                        <div class="stat-sub" id="btNetR">Net R: —</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Ending Equity &amp; DD</div>
                        <div class="stat-value" id="btEndEq">—</div>
                        <div class="stat-sub" id="btMaxDd">Max DD: —</div>
                    </div>
                </div>

                <!-- Stat Strip 2: Quantitative Institutional Rigor -->
                <div class="stat-strip" style="margin-bottom:12px;">
                    <div class="stat-card">
                        <div class="stat-label">Annualized Sharpe Ratio</div>
                        <div class="stat-value text-bull" id="btSharpe">—</div>
                        <div class="stat-sub" id="btMaxLossStreak">Max Loss Streak: —</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Win / Loss Ratio</div>
                        <div class="stat-value" id="btWinLossRatio">—</div>
                        <div class="stat-sub" id="btAvgWinLoss">Avg Win / Avg Loss: —</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Net Dollar Expectancy</div>
                        <div class="stat-value" id="btExpUsd">—</div>
                        <div class="stat-sub" id="btNetProfit">Net Profit: —</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Adverse Stress Status</div>
                        <div class="stat-value" id="btStressBadge">—</div>
                        <div class="stat-sub" id="btStressSub">Slippage &amp; Spread: —</div>
                    </div>
                </div>

                <canvas id="btEquityCanvas" height="220" style="width:100%;display:block;margin-bottom:14px;"></canvas>

                <!-- Section: Quantitative Strategy Validation Gate (Section 2 & 7) -->
                <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                        <strong style="font-size:12.5px;display:flex;align-items:center;gap:6px;">
                            <span>🛡️ Institutional Strategy Validation Gate (Unseen Out-of-Sample)</span>
                        </strong>
                        <span id="btGateSummary" class="muted" style="font-size:11px;">Requires all criteria to pass before deployment</span>
                    </div>
                    <div id="btValidationChecklist" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:8px;">
                        <div class="level-box" style="padding:8px;"><div class="lbl">Awaiting Simulation</div><div class="val" style="font-size:12px;">Run backtest to evaluate gate</div></div>
                    </div>
                </div>

                <!-- In-Sample vs Out-of-Sample Split -->
                <div class="grid-2" id="btSegmentBox" style="margin-bottom:14px;"></div>

                <!-- Execution Friction & Stress Test Breakdown -->
                <div class="grid-2" style="margin-bottom:14px;">
                    <div class="level-box" style="padding:10px 12px;">
                        <div class="lbl" style="font-weight:700;color:var(--text-main);margin-bottom:6px;">Execution Costs &amp; Friction Deductions</div>
                        <div id="btExecutionBox" style="font-size:12px;display:flex;flex-direction:column;gap:5px;">
                            <div class="muted">Run simulation to view commission ($3.50/lot), spread, and slippage deductions.</div>
                        </div>
                    </div>
                    <div class="level-box" style="padding:10px 12px;">
                        <div class="lbl" style="font-weight:700;color:var(--text-main);margin-bottom:6px;">Stress Test Results (+0.25 Slippage, 1.5x Spread)</div>
                        <div id="btStressBox" style="font-size:12px;display:flex;flex-direction:column;gap:5px;">
                            <div class="muted">Run simulation to inspect survivability under widened spread and slippage.</div>
                        </div>
                    </div>
                </div>

                <!-- Performance by Market Regime -->
                <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;margin-bottom:14px;">
                    <div class="lbl" style="font-weight:700;color:var(--text-main);margin-bottom:8px;">Adaptive Regime Performance Breakdown</div>
                    <div class="table-wrap">
                        <table class="data-table" id="btRegimeTable" style="font-size:12px;">
                            <thead>
                                <tr>
                                    <th>Market Regime</th>
                                    <th>Trades</th>
                                    <th>Wins / Losses</th>
                                    <th>Win Rate</th>
                                    <th>Net R</th>
                                    <th>Net PnL ($)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="muted">No regime breakdown available yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Simulated Trade Log (Chronological Walk-Forward, Zero Look-Ahead)</div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="data-table" id="btTradesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Segment</th>
                                <th>Signal UTC</th>
                                <th>Dir</th>
                                <th>Setup</th>
                                <th>Score</th>
                                <th>Entry</th>
                                <th>SL</th>
                                <th>TP1 / TP2</th>
                                <th>Exit</th>
                                <th>Reason</th>
                                <th>Outcome</th>
                                <th>R</th>
                                <th>PnL ($)</th>
                                <th>Equity ($)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="15" class="muted">Run a new backtest or click a saved run on the right to inspect trades.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <!-- Right Column: Configure New Backtest + Saved Runs -->
    <div>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Launch Walk-Forward Simulation</div>
            </div>
            <div class="panel-body">
                <form id="btForm">
                    <div class="form-group">
                        <label for="btName">Run Label (optional)</label>
                        <input type="text" id="btName" placeholder="e.g. M15 Quantitative Confluence Test">
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="btSymbol">Market Symbol</label>
                            <select id="btSymbol">
                                <option value="XAUUSD" selected>XAUUSD (Spot Gold)</option>
                                <option value="BTCUSD">BTCUSD (Bitcoin)</option>
                                <option value="EURUSD">EURUSD (Euro)</option>
                                <option value="GBPUSD">GBPUSD (Pound)</option>
                                <option value="US30">US30 (Dow Jones)</option>
                                <option value="NAS100">NAS100 (Nasdaq)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="btTf">Entry Timeframe</label>
                            <select id="btTf">
                                <option value="M15" selected>M15 (Default)</option>
                                <option value="M30">M30</option>
                                <option value="H1">H1</option>
                                <option value="M5">M5</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="btBalance">Initial Balance ($)</label>
                            <input type="number" step="100" id="btBalance" value="<?= Validator::e((string)($uSettings['account_balance'] ?? 10000)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="btFrom">Start Date (UTC)</label>
                            <input type="date" id="btFrom" value="<?= $defaultFrom ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="btTo">End Date (UTC)</label>
                            <input type="date" id="btTo" value="<?= $defaultTo ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="btRisk">Risk per Trade (%)</label>
                            <input type="number" step="0.1" id="btRisk" value="<?= Validator::e((string)($uSettings['risk_percent'] ?? 0.5)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="btMinScore">Min Quality Score</label>
                            <input type="number" id="btMinScore" value="<?= (int)Settings::get('signal.min_score', 55) ?>" required>
                        </div>
                    </div>
                    <div class="muted" style="font-size:11.5px;margin-bottom:10px;">
                        Methodology: Strict walk-forward replay (70% In-Sample / 30% Out-of-Sample). Commissions ($3.50/lot), spread, and slippage (0.15 handles) deducted. Conservative same-bar resolution (SL first).
                    </div>
                    <button type="submit" class="btn btn-primary" id="btSubmitBtn" style="width:100%;">▶ Run Walk-Forward Backtest</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Saved Backtest Runs</div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="data-table" id="savedRunsTable">
                        <thead>
                            <tr><th>ID</th><th>Run</th><th>Trades</th><th>Net R</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$runs): ?>
                                <tr><td colspan="5" class="muted">No backtest runs yet.</td></tr>
                            <?php else: foreach ($runs as $r): ?>
                                <tr>
                                    <td class="mono">#<?= (int)$r['id'] ?></td>
                                    <td>
                                        <div><strong><?= Validator::e($r['name']) ?></strong></div>
                                        <div class="muted mono" style="font-size:11px;"><?= Validator::e($r['timeframe']) ?> · <?= Validator::e($r['data_mode']) ?></div>
                                    </td>
                                    <td class="mono"><?= (int)$r['total_trades'] ?></td>
                                    <td class="mono <?= ($r['net_r'] ?? 0) >= 0 ? 'text-bull' : 'text-bear' ?>">
                                        <?= $r['net_r'] !== null ? ($r['net_r'] >= 0 ? '+' : '') . $r['net_r'] . 'R' : '—' ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-xs load-run-btn" data-id="<?= (int)$r['id'] ?>">View</button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let currentEmergencyState = <?= $emergencyStopActive ? 'true' : 'false' ?>;

    function updateEmergencyUI(active) {
        currentEmergencyState = Boolean(active);
        const pill = document.getElementById('emergencyStatusPill');
        const btn = document.getElementById('toggleEmergencyBtn');
        if (currentEmergencyState) {
            pill.className = 'badge badge-bear';
            pill.textContent = '⛔ EMERGENCY STOP ACTIVE';
            btn.className = 'btn btn-xs btn-primary';
            btn.textContent = '▶ Deactivate Emergency Stop';
        } else {
            pill.className = 'badge badge-bull';
            pill.textContent = '🟢 NORMAL OPERATION';
            btn.className = 'btn btn-xs btn-danger';
            btn.textContent = '⚠️ Trigger Emergency Stop';
        }
    }

    const toggleBtn = document.getElementById('toggleEmergencyBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', async () => {
            const willEnable = !currentEmergencyState;
            const confirmMsg = willEnable
                ? 'Are you sure you want to trigger EMERGENCY STOP? All trade signal generation and new orders will be blocked.'
                : 'Deactivate emergency stop and resume normal market analysis?';
            if (!confirm(confirmMsg)) return;

            try {
                const res = await GoldSmc.Api.post('api/backtest.php', {
                    action: 'emergency_stop',
                    enabled: willEnable,
                });
                updateEmergencyUI(res.emergency_stop);
                GoldSmc.toast(res.message, res.emergency_stop ? 'warn' : 'success');
            } catch (err) {
                GoldSmc.toast(err.message, 'error');
            }
        });
    }

    function displayRun(run, trades) {
        const s = run.stats || {};
        document.getElementById('btRunBadge').textContent = `#${run.id} · ${run.name} (${run.data_mode.toUpperCase()})`;
        
        // Validation Gate Status Badge
        const val = s.validation || {};
        const valBadge = document.getElementById('btValidationBadge');
        if (val.is_validated) {
            valBadge.className = 'badge badge-bull';
            valBadge.textContent = '✅ HISTORICALLY VALIDATED';
        } else {
            valBadge.className = 'badge badge-warn';
            valBadge.textContent = '⚠️ NOT VALIDATED (CRITERIA UNMET)';
        }

        // Primary Performance Stats
        document.getElementById('btTotalTrades').textContent = run.total_trades ?? 0;
        document.getElementById('btWinLoss').textContent = `${s.wins ?? 0}W / ${s.losses ?? 0}L / ${s.breakevens ?? 0}BE`;
        document.getElementById('btWinRate').textContent = run.win_rate !== null ? `${run.win_rate}%` : '—';
        document.getElementById('btPf').textContent = `Profit Factor: ${run.profit_factor ?? '—'}`;
        document.getElementById('btExpR').textContent = run.expectancy_r !== null ? `${run.expectancy_r >= 0 ? '+' : ''}${run.expectancy_r}R` : '—';
        document.getElementById('btNetR').textContent = `Net R: ${run.net_r !== null ? (run.net_r >= 0 ? '+' : '') + run.net_r + 'R' : '—'}`;
        document.getElementById('btEndEq').textContent = run.ending_equity !== null ? `$${GoldSmc.fmtPrice(run.ending_equity)}` : '—';
        document.getElementById('btMaxDd').textContent = `Max Drawdown: ${run.max_drawdown_pct ?? 0}%`;

        // Quantitative Rigor Stats
        document.getElementById('btSharpe').textContent = s.sharpe_ratio !== null && s.sharpe_ratio !== undefined ? s.sharpe_ratio : 'N/A';
        document.getElementById('btMaxLossStreak').textContent = `Max Loss Streak: ${s.max_consecutive_losses ?? 0} | Max Win: ${s.max_consecutive_wins ?? 0}`;
        document.getElementById('btWinLossRatio').textContent = s.win_loss_ratio !== null && s.win_loss_ratio !== undefined ? `${s.win_loss_ratio}x` : '—';
        document.getElementById('btAvgWinLoss').textContent = `Avg: +$${s.avg_win_usd ?? 0} / -$${s.avg_loss_usd ?? 0}`;
        document.getElementById('btExpUsd').textContent = s.expectancy_usd !== null && s.expectancy_usd !== undefined ? `$${s.expectancy_usd >= 0 ? '+' : ''}${s.expectancy_usd}` : '—';
        
        const netProf = (run.ending_equity ?? 0) - (s.initial_balance ?? 10000);
        const netProfEl = document.getElementById('btNetProfit');
        netProfEl.textContent = `Net Profit: ${netProf >= 0 ? '+$' : '-$'}${GoldSmc.fmtPrice(Math.abs(netProf))}`;
        netProfEl.className = `stat-sub mono ${netProf >= 0 ? 'text-bull' : 'text-bear'}`;

        // Stress Status
        const st = s.stress_test || {};
        const stressBadge = document.getElementById('btStressBadge');
        if (st.status === 'PASSED_STRESS') {
            stressBadge.className = 'stat-value text-bull';
            stressBadge.textContent = 'PASSED';
        } else if (st.status === 'MARGINAL') {
            stressBadge.className = 'stat-value text-gold';
            stressBadge.textContent = 'MARGINAL';
        } else {
            stressBadge.className = 'stat-value text-bear';
            stressBadge.textContent = st.status ? 'FAILED' : '—';
        }
        document.getElementById('btStressSub').textContent = `Stress Net: ${st.stats ? (st.stats.net_r >= 0 ? '+' : '') + st.stats.net_r + 'R' : '—'}`;

        // Render Equity Curve
        GoldSmc.renderEquityCurve(document.getElementById('btEquityCanvas'), s.equity_curve || [], 'equity', '');

        // Validation Gate Checklist Grid
        const criteria = val.criteria || {};
        const checklistEl = document.getElementById('btValidationChecklist');
        const criteriaKeys = Object.keys(criteria);
        if (criteriaKeys.length > 0) {
            checklistEl.innerHTML = criteriaKeys.map(k => {
                const c = criteria[k];
                const passed = Boolean(c.passed);
                return `
                    <div class="level-box" style="padding:8px 10px;border-left:3px solid ${passed ? 'var(--bull)' : 'var(--bear)'};">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:11px;font-weight:700;">${GoldSmc.escapeHtml(c.name)}</span>
                            <span class="badge ${passed ? 'badge-bull' : 'badge-bear'}" style="font-size:10px;padding:1px 5px;">${passed ? 'PASS' : 'FAIL'}</span>
                        </div>
                        <div class="muted" style="font-size:11px;margin-top:2px;">Target: ${GoldSmc.escapeHtml(c.target)}</div>
                        <div class="mono" style="font-size:12px;font-weight:700;margin-top:2px;color:${passed ? 'var(--bull)' : 'var(--bear)'};">
                            Actual: ${GoldSmc.escapeHtml(String(c.actual))}
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            checklistEl.innerHTML = '<div class="muted" style="font-size:12px;">Validation criteria details unavailable for this run.</div>';
        }

        // In-Sample vs Out-of-Sample Split
        const isS = s.in_sample || {};
        const oosS = s.out_of_sample || {};
        document.getElementById('btSegmentBox').innerHTML = `
            <div class="level-box">
                <div class="lbl">In-Sample Training Segment (First 70%)</div>
                <div class="val">${isS.trades ?? 0} Trades · Win Rate: ${isS.win_rate !== null && isS.win_rate !== undefined ? isS.win_rate + '%' : '—'} · Net: ${isS.net_r ?? 0}R</div>
                <div class="muted" style="font-size:11.5px;margin-top:2px;">
                    Expectancy: ${isS.expectancy_r ?? '—'}R ($${isS.expectancy_usd ?? 0}) · PF: ${isS.profit_factor ?? '—'} · Sharpe: ${isS.sharpe_ratio ?? 'N/A'}
                </div>
            </div>
            <div class="level-box">
                <div class="lbl">Out-of-Sample Validation Segment (Last 30%)</div>
                <div class="val">${oosS.trades ?? 0} Trades · Win Rate: ${oosS.win_rate !== null && oosS.win_rate !== undefined ? oosS.win_rate + '%' : '—'} · Net: ${oosS.net_r ?? 0}R</div>
                <div class="muted" style="font-size:11.5px;margin-top:2px;">
                    Expectancy: ${oosS.expectancy_r ?? '—'}R ($${oosS.expectancy_usd ?? 0}) · PF: ${oosS.profit_factor ?? '—'} · Sharpe: ${oosS.sharpe_ratio ?? 'N/A'}
                </div>
            </div>
        `;

        // Execution Costs Breakdown
        const exec = s.execution_costs || {};
        document.getElementById('btExecutionBox').innerHTML = `
            <div style="display:flex;justify-content:space-between;">
                <span class="muted">Broker Commission ($3.50/lot):</span>
                <span class="mono">-$${GoldSmc.fmtPrice(exec.commission_usd ?? 0)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span class="muted">Spread Friction:</span>
                <span class="mono">-$${GoldSmc.fmtPrice(exec.spread_usd ?? 0)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span class="muted">Slippage Allowance (0.15h):</span>
                <span class="mono">-$${GoldSmc.fmtPrice(exec.slippage_usd ?? 0)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:4px;font-weight:700;">
                <span>Total Execution Drag:</span>
                <span class="mono text-bear">-$${GoldSmc.fmtPrice(exec.total_cost_usd ?? 0)}</span>
            </div>
        `;

        // Stress Test Box
        document.getElementById('btStressBox').innerHTML = `
            <div style="display:flex;justify-content:space-between;">
                <span class="muted">Stress Parameters:</span>
                <span class="mono">+${st.slippage_add ?? 0.25} handles slip · ${st.spread_mult ?? 1.5}x spread</span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span class="muted">Stress Net R:</span>
                <span class="mono ${st.stats && st.stats.net_r >= 0 ? 'text-bull' : 'text-bear'}">${st.stats ? (st.stats.net_r >= 0 ? '+' : '') + st.stats.net_r + 'R' : '—'}</span>
            </div>
            <div style="display:flex;justify-content:space-between;">
                <span class="muted">Stress Profit Factor:</span>
                <span class="mono">${st.stats ? (st.stats.profit_factor ?? '—') : '—'}</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:4px;font-weight:700;">
                <span>Stress Gate Outcome:</span>
                <span class="badge ${st.status === 'PASSED_STRESS' ? 'badge-bull' : (st.status === 'MARGINAL' ? 'badge-warn' : 'badge-bear')}">${st.status || 'UNTESTED'}</span>
            </div>
        `;

        // Market Regime Breakdown Table
        const byRegime = s.by_regime || {};
        const regBody = document.querySelector('#btRegimeTable tbody');
        const regKeys = Object.keys(byRegime);
        if (regKeys.length > 0) {
            regBody.innerHTML = regKeys.map(k => {
                const item = byRegime[k];
                const wr = item.trades > 0 ? ((item.wins / item.trades) * 100).toFixed(1) + '%' : '—';
                const label = k.replace(/_/g, ' ').toUpperCase();
                return `
                    <tr>
                        <td><strong>${label}</strong></td>
                        <td class="mono">${item.trades}</td>
                        <td class="mono">${item.wins}W / ${item.losses}L</td>
                        <td class="mono">${wr}</td>
                        <td class="mono ${item.net_r >= 0 ? 'text-bull' : 'text-bear'}">${item.net_r >= 0 ? '+' : ''}${item.net_r}R</td>
                        <td class="mono ${item.net_pnl >= 0 ? 'text-bull' : 'text-bear'}">${item.net_pnl >= 0 ? '+$' : '-$'}${GoldSmc.fmtPrice(Math.abs(item.net_pnl))}</td>
                    </tr>
                `;
            }).join('');
        } else {
            regBody.innerHTML = '<tr><td colspan="6" class="muted">No regime performance data logged.</td></tr>';
        }

        // Chronological Trade Table
        const tbody = document.querySelector('#btTradesTable tbody');
        if (!trades || !trades.length) {
            tbody.innerHTML = '<tr><td colspan="15" class="muted">No qualified trades triggered during this window.</td></tr>';
            return;
        }
        tbody.innerHTML = trades.map(t => `
            <tr>
                <td class="mono">${t.trade_no}</td>
                <td><span class="badge ${t.segment === 'in_sample' ? 'badge-neutral' : 'badge-cyan'}">${t.segment === 'in_sample' ? 'IS' : 'OOS'}</span></td>
                <td class="mono">${GoldSmc.escapeHtml(String(t.signal_time).slice(0, 16))}</td>
                <td><span class="badge ${t.direction === 'BUY' ? 'badge-bull' : 'badge-bear'}">${t.direction}</span></td>
                <td>${GoldSmc.escapeHtml(t.setup_type || 'SMC')}</td>
                <td class="mono">${t.quality_score}</td>
                <td class="mono">$${GoldSmc.fmtPrice(t.entry_price)}</td>
                <td class="mono text-bear">$${GoldSmc.fmtPrice(t.stop_loss)}</td>
                <td class="mono text-bull">$${GoldSmc.fmtPrice(t.tp1)}${t.tp2 ? ' / $' + GoldSmc.fmtPrice(t.tp2) : ''}</td>
                <td class="mono">$${GoldSmc.fmtPrice(t.exit_price)}</td>
                <td>${GoldSmc.escapeHtml(t.exit_reason)}${Number(t.ambiguous) ? ' ⚠️' : ''}</td>
                <td><span class="badge ${t.outcome === 'win' ? 'badge-bull' : (t.outcome === 'loss' ? 'badge-bear' : 'badge-neutral')}">${t.outcome}</span></td>
                <td class="mono ${Number(t.r_multiple) >= 0 ? 'text-bull' : 'text-bear'}">${Number(t.r_multiple) >= 0 ? '+' : ''}${t.r_multiple}R</td>
                <td class="mono ${Number(t.pnl) >= 0 ? 'text-bull' : 'text-bear'}">${Number(t.pnl) >= 0 ? '+$' : '-$'}${GoldSmc.fmtPrice(Math.abs(t.pnl))}</td>
                <td class="mono">$${GoldSmc.fmtPrice(t.equity_after)}</td>
            </tr>
        `).join('');
    }

    async function loadRun(id) {
        try {
            const res = await GoldSmc.Api.get('api/backtest.php?id=' + encodeURIComponent(id));
            if (res.emergency_stop !== undefined) {
                updateEmergencyUI(res.emergency_stop);
            }
            displayRun(res.run, res.trades);
        } catch (e) {
            GoldSmc.toast(e.message, 'error');
        }
    }

    document.querySelectorAll('.load-run-btn').forEach(btn => {
        btn.addEventListener('click', () => loadRun(btn.dataset.id));
    });

    const firstBtn = document.querySelector('.load-run-btn');
    if (firstBtn) {
        loadRun(firstBtn.dataset.id);
    }

    const btForm = document.getElementById('btForm');
    const submitBtn = document.getElementById('btSubmitBtn');
    btForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.textContent = 'Simulating Walk-Forward Candles...';
        try {
            const res = await GoldSmc.Api.post('api/backtest.php', {
                name: document.getElementById('btName').value,
                symbol: document.getElementById('btSymbol').value,
                timeframe: document.getElementById('btTf').value,
                initial_balance: document.getElementById('btBalance').value,
                date_from: document.getElementById('btFrom').value,
                date_to: document.getElementById('btTo').value,
                risk_percent: document.getElementById('btRisk').value,
                min_score: document.getElementById('btMinScore').value,
            });
            if (res.emergency_stop !== undefined) {
                updateEmergencyUI(res.emergency_stop);
            }
            displayRun(res.run, res.trades);
            GoldSmc.toast(`Backtest #${res.run.id} completed (${res.run.total_trades} trades).`);
        } catch (err) {
            GoldSmc.toast(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = '▶ Run Walk-Forward Backtest';
        }
    });
});
</script>

<?php renderFooter(); ?>
