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
use App\ML\ModelManager;
use App\ML\SignalFeedbackEngine;

$layout = renderHeader('AI Training Control Center', 'training');
$user = $layout['user'];
$csrf = Csrf::token();

$activeSymbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');
$modelMgr = new ModelManager();
$activeModel = $modelMgr->getActiveModel();
$versions = $modelMgr->listVersions(20);

$feedbackEngine = new SignalFeedbackEngine();
$errorReport = $feedbackEngine->generateReport($activeSymbol);

// Active model calibration bins
$calibBins = [];
if (!empty($activeModel['id'])) {
    $calibBins = Database::fetchAll(
        'SELECT bin_index, prob_min, prob_max, prob_mean, empirical_win_rate, sample_count
         FROM ml_calibration_bins
         WHERE model_version_id = ?
         ORDER BY bin_index ASC',
        [$activeModel['id']]
    );
}

// Ingested candles check
$market = new MarketDataService(null, $activeSymbol);
$candles = $market->candles('M15', 500);
$missingBars = 0;
$prevTime = null;
foreach ($candles as $c) {
    if ($prevTime !== null && ($c['t'] - $prevTime) > 900 && ($c['t'] - $prevTime) < 86400) {
        $missingBars++;
    }
    $prevTime = $c['t'];
}
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>🧠</span>
            <span>AI Training Control Center — Adaptive Institutional ML</span>
        </div>
        <div class="vision-hero-sub">
            Point-in-time machine learning with strict zero look-ahead bias, calibrated probability estimation, Expected Value (EV) filtering, closed-trade error diagnosis, and automated model rollback.
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <button type="button" class="btn btn-sm btn-ghost" onclick="rollbackModel()" title="Safely rollback to previous validated model">
            🛡 Safe Rollback
        </button>
        <button type="button" class="btn btn-sm btn-primary" onclick="openTrainModal()">
            ⚡ Train New Model
        </button>
    </div>
</div>

<!-- Active Model Performance Strip -->
<section class="stat-strip" aria-label="Active Model Performance">
    <div class="stat-card">
        <div class="stat-label">Active Production Model</div>
        <div class="stat-value text-gold" style="font-size: 17px; font-family: var(--font-mono);">
            <?= Validator::e($activeModel['version_tag'] ?? 'None Active') ?>
        </div>
        <div class="stat-sub">
            Status: <span class="badge badge-bull">ACTIVE</span> · Trained: <?= Validator::e(substr((string)($activeModel['trained_at'] ?? 'N/A'), 0, 10)) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Out-of-Sample Win Rate</div>
        <div class="stat-value <?= (!empty($activeModel['win_rate']) && $activeModel['win_rate'] >= 50) ? 'text-bull' : 'text-gold' ?>">
            <?= isset($activeModel['win_rate']) ? number_format((float)$activeModel['win_rate'], 1) . '%' : '—' ?>
        </div>
        <div class="stat-sub">
            Chronological Split: <strong>70% Train / 30% Test</strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">OOS Profit Factor &amp; EV</div>
        <div class="stat-value text-bull">
            <?= isset($activeModel['profit_factor']) ? number_format((float)$activeModel['profit_factor'], 2) : '—' ?>
        </div>
        <div class="stat-sub">
            Expectancy: <strong><?= isset($activeModel['expectancy_r']) ? sprintf('%+.2fR', (float)$activeModel['expectancy_r']) : '—' ?></strong> net / trade
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Probability Calibration (Brier)</div>
        <div class="stat-value text-cyan">
            <?= isset($activeModel['brier_score']) ? number_format((float)$activeModel['brier_score'], 4) : '—' ?>
        </div>
        <div class="stat-sub">
            Brier Score (MSE): <small style="color:var(--text-muted);">&lt;0.25 beats coin toss</small>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Dataset Health (M15 <?= Validator::e($activeSymbol) ?>)</div>
        <div class="stat-value"><?= count($candles) ?> Bars</div>
        <div class="stat-sub">
            Missing: <span class="<?= $missingBars === 0 ? 'text-bull' : 'text-gold' ?>"><?= $missingBars ?> gaps</span> · <?= Validator::e($market->provider()->label()) ?>
        </div>
    </div>
</section>

<!-- Main Grid: Left (Model Versions & Calibration), Right (Error Taxonomy & Diagnosis) -->
<div style="display: grid; grid-template-columns: 1fr 380px; gap: 16px; margin-top: 16px;">
    
    <!-- Left Column -->
    <div style="display: flex; flex-direction: column; gap: 16px;">
        
        <!-- Model Versions Comparison Table -->
        <div class="calc-result-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="font-size: 15px; font-weight: 700;">Model Version Comparison Matrix</h3>
                <span class="muted" style="font-size: 12px;"><?= count($versions) ?> versions registered</span>
            </div>

            <div style="overflow-x: auto;">
                <table class="matrix-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Version Tag</th>
                            <th>Status</th>
                            <th>Train Split</th>
                            <th>In-Sample WR</th>
                            <th>OOS Win Rate</th>
                            <th>OOS Profit Factor</th>
                            <th>OOS Expectancy</th>
                            <th>Brier Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="versionsTableBody">
                        <?php if (empty($versions)): ?>
                        <tr><td colspan="9" class="muted" style="text-align: center; padding: 18px;">No model versions found. Click 'Train New Model' to start.</td></tr>
                        <?php else: ?>
                        <?php foreach ($versions as $v): ?>
                        <tr>
                            <td><strong class="mono"><?= Validator::e($v['version_tag']) ?></strong></td>
                            <td>
                                <span class="badge <?= $v['is_active'] ? 'badge-bull' : ($v['status'] === 'rolled_back' ? 'badge-bear' : 'badge-warn') ?>">
                                    <?= $v['is_active'] ? 'ACTIVE' : strtoupper((string)$v['status']) ?>
                                </span>
                            </td>
                            <td><?= (int)$v['train_sample_count'] ?> / <?= (int)$v['val_sample_count'] ?></td>
                            <td><?= number_format((float)$v['in_sample_win_rate'], 1) ?>%</td>
                            <td><strong class="<?= (float)$v['oos_win_rate'] >= 50 ? 'text-bull' : '' ?>"><?= number_format((float)$v['oos_win_rate'], 1) ?>%</strong></td>
                            <td><?= number_format((float)$v['oos_profit_factor'], 2) ?></td>
                            <td><?= sprintf('%+.2fR', (float)$v['oos_expectancy_r']) ?></td>
                            <td class="mono"><?= number_format((float)$v['brier_score'], 4) ?></td>
                            <td>
                                <?php if ($v['is_active']): ?>
                                    <span class="muted mono">Active</span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-xs btn-primary" onclick="promoteModel(<?= (int)$v['id'] ?>)">Promote</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Reliability Diagram & Probability Calibration Curve -->
        <div class="calc-result-box">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 6px;">Probability Calibration Curve (Reliability Diagram)</h3>
            <p class="muted" style="font-size: 12px; margin-bottom: 12px;">
                Compares the model's predicted probability bin against actual observed win frequency. An ideal model follows the 45-degree diagonal.
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(65px, 1fr)); gap: 8px; margin-top: 8px;">
                <?php if (empty($calibBins)): ?>
                <div class="muted" style="grid-column: 1 / -1; text-align: center; padding: 12px;">
                    No calibration data available for active model.
                </div>
                <?php else: ?>
                <?php foreach ($calibBins as $bin): ?>
                <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: 6px; padding: 8px; text-align: center;">
                    <div class="muted" style="font-size: 10px;"><?= number_format((float)$bin['prob_min'] * 100, 0) ?>-<?= number_format((float)$bin['prob_max'] * 100, 0) ?>%</div>
                    <div style="font-size: 13px; font-weight: 700; color: var(--gold-bright); margin: 4px 0;">
                        <?= number_format((float)$bin['empirical_win_rate'] * 100, 1) ?>%
                    </div>
                    <div class="muted" style="font-size: 10px;"><?= (int)$bin['sample_count'] ?> trades</div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Error Diagnostics Table -->
        <div class="calc-result-box">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 6px;">Continuous Learning — Closed Trade Diagnostics</h3>
            <p class="muted" style="font-size: 12px; margin-bottom: 12px;">
                Automatic post-mortem audit of every completed trade to identify failure modes and proposed model updates.
            </p>

            <div style="overflow-x: auto;">
                <table class="matrix-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Signal</th>
                            <th>Setup</th>
                            <th>Outcome</th>
                            <th>Realized R</th>
                            <th>Failure Category</th>
                            <th>Root Cause Diagnosis</th>
                            <th>Recommended Remedy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($errorReport['recent_diagnostics'])): ?>
                        <tr><td colspan="7" class="muted" style="text-align: center; padding: 14px;">No diagnostic reports recorded yet. Diagnoses occur automatically as signals close.</td></tr>
                        <?php else: ?>
                        <?php foreach ($errorReport['recent_diagnostics'] as $diag): ?>
                        <tr>
                            <td><strong class="mono">#<?= (int)$diag['signal_id'] ?></strong> (<?= Validator::e($diag['direction']) ?>)</td>
                            <td><?= Validator::e($diag['setup_type'] ?? 'SMC') ?></td>
                            <td><span class="badge <?= $diag['outcome'] === 'win' ? 'badge-bull' : ($diag['outcome'] === 'loss' ? 'badge-bear' : 'badge-warn') ?>"><?= strtoupper((string)$diag['outcome']) ?></span></td>
                            <td class="<?= (float)$diag['realized_r'] >= 0 ? 'text-bull' : 'text-bear' ?> mono"><?= sprintf('%+.2fR', (float)$diag['realized_r']) ?></td>
                            <td><span class="mono" style="font-size: 11px;"><?= Validator::e($diag['failure_category']) ?></span></td>
                            <td style="max-width: 200px;"><?= Validator::e($diag['root_cause_analysis']) ?></td>
                            <td style="max-width: 200px; color: var(--gold-bright);"><?= Validator::e($diag['remedy_recommendation']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Right Column: Failure Taxonomy & Strategy Throttling -->
    <div style="display: flex; flex-direction: column; gap: 16px;">
        
        <!-- Failure Taxonomy Card -->
        <div class="calc-result-box" style="border-top: 3px solid var(--bear);">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 6px;">Error Taxonomy (Losing Trades)</h3>
            <div class="muted" style="font-size: 12px; margin-bottom: 12px;">
                Total Losing Trades: <strong><?= (int)($errorReport['total_losses'] ?? 0) ?></strong> (-<?= number_format((float)($errorReport['total_loss_r'] ?? 0), 1) ?>R)
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php
                $cats = $errorReport['by_category'] ?? [];
                if (empty($cats)): ?>
                <div class="muted" style="font-size: 12px; text-align: center; padding: 10px;">No failure records recorded yet.</div>
                <?php else: ?>
                <?php foreach ($cats as $catName => $count): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-panel); border: 1px solid var(--border); padding: 8px 12px; border-radius: 6px;">
                    <div>
                        <strong style="font-size: 12px;"><?= Validator::e($catName) ?></strong>
                    </div>
                    <span class="badge badge-bear"><?= (int)$count ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Failure by Session -->
        <div class="calc-result-box">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 8px;">Loss Distribution by Session</h3>
            <div style="display: flex; flex-direction: column; gap: 6px; font-size: 12px;">
                <?php
                $sessions = $errorReport['by_session'] ?? [];
                if (empty($sessions)): ?>
                <div class="muted" style="text-align: center;">No session breakdown yet.</div>
                <?php else: ?>
                <?php foreach ($sessions as $sName => $count): ?>
                <div class="calc-result-item">
                    <span class="muted"><?= Validator::e($sName) ?>:</span>
                    <span class="calc-result-val"><?= (int)$count ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Adaptive Strategy Safeguards -->
        <div class="calc-result-box">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 8px;">Adaptive Throttling &amp; Circuit Breakers</h3>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                <div>🛡 <strong>Expected Calibration Error (ECE) Gate:</strong> Model blocked if ECE &gt; 0.15</div>
                <div>🛡 <strong>Minimum Probability Gate:</strong> No trade approved with calibrated P(Win) &lt; 52%</div>
                <div>🛡 <strong>Expected Net Value Gate:</strong> Minimum +0.20R net return after friction</div>
                <div>🛡 <strong>Automated Circuit Breaker:</strong> Auto-pauses setup if 4 consecutive losses occur</div>
            </div>
        </div>

    </div>

</div>

<!-- Modal: Train New Model -->
<div class="tg-modal-backdrop" id="trainModal">
    <div class="tg-modal" style="max-width: 560px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 20px;">⚡</span>
                <strong style="font-size: 16px;">Train New Machine Learning Model</strong>
            </div>
            <button type="button" class="btn btn-xs btn-ghost" onclick="closeTrainModal()">✕</button>
        </div>

        <p class="muted" style="font-size: 12.5px; margin-bottom: 16px;">
            Extracts point-in-time features with zero look-ahead bias across historical candles. Splits data chronologically into In-Sample training and Out-of-Sample testing.
        </p>

        <form id="trainForm" onsubmit="executeTraining(event);">
            <div class="vision-form-row">
                <div>
                    <label class="form-label">Symbol / Instrument</label>
                    <select id="trainSymbol" class="form-select">
                        <option value="XAUUSD" <?= $activeSymbol === 'XAUUSD' ? 'selected' : '' ?>>XAU/USD (Spot Gold)</option>
                        <option value="BTCUSD" <?= $activeSymbol === 'BTCUSD' ? 'selected' : '' ?>>BTC/USD (Bitcoin)</option>
                        <option value="EURUSD" <?= $activeSymbol === 'EURUSD' ? 'selected' : '' ?>>EUR/USD (Euro)</option>
                        <option value="US30" <?= $activeSymbol === 'US30' ? 'selected' : '' ?>>US30 (Dow Jones)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Execution Timeframe</label>
                    <select id="trainTf" class="form-select">
                        <option value="M15" selected>M15 (15 Minutes)</option>
                        <option value="H1">H1 (1 Hour)</option>
                        <option value="H4">H4 (4 Hours)</option>
                    </select>
                </div>
            </div>

            <div class="vision-form-row" style="margin-top: 10px;">
                <div>
                    <label class="form-label">Chronological Train Split</label>
                    <select id="trainSplit" class="form-select">
                        <option value="0.70" selected>70% Train / 30% Test</option>
                        <option value="0.75">75% Train / 25% Test</option>
                        <option value="0.80">80% Train / 20% Test</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Optimization Epochs</label>
                    <input type="number" id="trainEpochs" class="form-select" value="120" min="40" max="300" step="20">
                </div>
            </div>

            <div class="vision-form-row" style="margin-top: 10px;">
                <div>
                    <label class="form-label">Learning Rate (LR)</label>
                    <input type="number" id="trainLr" class="form-select" value="0.05" min="0.005" max="0.20" step="0.01">
                </div>
                <div>
                    <label class="form-label">L2 Regularization (Ridge)</label>
                    <input type="number" id="trainL2" class="form-select" value="0.01" min="0.001" max="0.10" step="0.005">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button type="button" class="btn btn-ghost" onclick="closeTrainModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitTrain">
                    <span id="trainBtnText">🚀 Start Model Training Run</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" style="position: fixed; bottom: 20px; right: 20px; z-index: 10000; display: flex; flex-direction: column; gap: 8px;"></div>

<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '<?= Validator::e($csrf) ?>';

function showToast(msg, isError = false) {
    const c = document.getElementById('toastContainer');
    if (!c) return;
    const el = document.createElement('div');
    el.style.background = isError ? '#ef4444' : '#10b981';
    el.style.color = '#fff';
    el.style.padding = '10px 18px';
    el.style.borderRadius = '6px';
    el.style.fontWeight = '600';
    el.style.boxShadow = '0 6px 18px rgba(0,0,0,0.4)';
    el.textContent = msg;
    c.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function openTrainModal() {
    document.getElementById('trainModal').classList.add('show');
}

function closeTrainModal() {
    document.getElementById('trainModal').classList.remove('show');
}

async function executeTraining(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitTrain');
    const txt = document.getElementById('trainBtnText');
    btn.disabled = true;
    txt.textContent = '⏳ Generating Datasets & Training Model...';

    const payload = {
        symbol: document.getElementById('trainSymbol').value,
        timeframe: document.getElementById('trainTf').value,
        train_split: parseFloat(document.getElementById('trainSplit').value),
        epochs: parseInt(document.getElementById('trainEpochs').value, 10),
        learning_rate: parseFloat(document.getElementById('trainLr').value),
        l2_reg: parseFloat(document.getElementById('trainL2').value),
    };

    try {
        const resp = await fetch('api/training.php?action=train', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(payload),
        });
        const res = await resp.json();
        if (!resp.ok || !res.ok) {
            throw new Error(res.error || 'Training failed');
        }

        showToast(`✅ Model ${res.version_tag} trained successfully!`);
        closeTrainModal();
        setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
        showToast('Training Error: ' + err.message, true);
    } finally {
        btn.disabled = false;
        txt.textContent = '🚀 Start Model Training Run';
    }
}

async function promoteModel(versionId) {
    if (!confirm('Are you sure you want to promote this model version to production ACTIVE?')) {
        return;
    }

    try {
        const resp = await fetch('api/training.php?action=promote', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify({ version_id: versionId }),
        });
        const res = await resp.json();
        if (!resp.ok || !res.ok) {
            throw new Error(res.error || 'Promotion failed');
        }
        showToast('✅ Model promoted to production ACTIVE!');
        setTimeout(() => window.location.reload(), 1000);
    } catch (err) {
        showToast('Promotion Error: ' + err.message, true);
    }
}

async function rollbackModel() {
    if (!confirm('Safely rollback production model to previous validated version?')) {
        return;
    }

    try {
        const resp = await fetch('api/training.php?action=rollback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify({}),
        });
        const res = await resp.json();
        if (!resp.ok || !res.ok) {
            throw new Error(res.error || 'Rollback failed');
        }
        showToast('🛡 Rolled back to: ' + res.model.version_tag);
        setTimeout(() => window.location.reload(), 1000);
    } catch (err) {
        showToast('Rollback Error: ' + err.message, true);
    }
}
</script>

<?php
// Layout footer
?>
</body>
</html>
