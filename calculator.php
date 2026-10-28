<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Analysis\RiskCalculator;
use App\Core\Auth;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;

$symbol = isset($_GET['symbol']) ? Validator::str($_GET['symbol'], 1, 16) : 'XAUUSD';
$symbol = MarketDataService::normalizeSymbol($symbol);

$layout = renderHeader('Risk Calculator', 'calculator');
$market = new MarketDataService(null, $symbol);
$quote = $market->quote(false);
$livePrice = $quote['available'] ? (float)$quote['price'] : 2400.0;

$uSettings = $layout['settings'] ?? [];
$accBalance = (float)($uSettings['account_balance'] ?? 10000.0);
$riskPct = (float)($uSettings['risk_percent'] ?? 0.5);
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>🧮</span>
            <span><?= I18n::t('nav_calculator') ?> — Institutional Lot Size &amp; Capital Guard</span>
        </div>
        <div class="vision-hero-sub">
            Precision position sizing for Gold (XAUUSD) &amp; Bitcoin (BTCUSD). Aligns lot sizes strictly to your risk tolerance, account balance, and stop loss distance.
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <select class="form-select" onchange="window.location.href='calculator.php?symbol=' + this.value">
            <option value="XAUUSD" <?= $symbol === 'XAUUSD' ? 'selected' : '' ?>>XAU/USD (Spot Gold)</option>
            <option value="BTCUSD" <?= $symbol === 'BTCUSD' ? 'selected' : '' ?>>BTC/USD (Bitcoin)</option>
        </select>
        <span class="badge badge-live">Live Ref: $<?= number_format($livePrice, 2) ?></span>
    </div>
</div>

<div class="calc-container">
    <!-- Input Form Card -->
    <div class="calc-card">
        <h3 style="font-size: 15px; font-weight: 800; margin-bottom: 16px;">Trading Parameters</h3>

        <form id="riskCalcForm" onsubmit="return false;">
            <div class="vision-form-row">
                <div>
                    <label class="form-label">Account Balance ($)</label>
                    <input type="number" step="100" id="calcBalance" class="form-input mono" value="<?= $accBalance ?>">
                </div>
                <div>
                    <label class="form-label">Risk Per Trade (%)</label>
                    <input type="number" step="0.1" min="0.1" max="5.0" id="calcRiskPct" class="form-input mono" value="<?= $riskPct ?>">
                </div>
            </div>

            <div class="vision-form-row">
                <div>
                    <label class="form-label">Trade Direction</label>
                    <select id="calcDirection" class="form-select">
                        <option value="BUY" selected>BUY (Long)</option>
                        <option value="SELL">SELL (Short)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Contract Size</label>
                    <input type="text" id="calcContractSize" class="form-input mono" value="<?= $symbol === 'XAUUSD' ? '100 oz / Lot' : '1.0 BTC / Lot' ?>" disabled>
                </div>
            </div>

            <div style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 6px;">
                <div class="vision-form-row">
                    <div>
                        <label class="form-label">Entry Price ($)</label>
                        <input type="number" step="0.01" id="calcEntry" class="form-input mono text-gold" value="<?= round($livePrice, 2) ?>">
                    </div>
                    <div>
                        <label class="form-label">Stop Loss ($)</label>
                        <input type="number" step="0.01" id="calcSl" class="form-input mono text-bear" value="<?= round($livePrice - 5.0, 2) ?>">
                    </div>
                </div>

                <div class="vision-form-row">
                    <div>
                        <label class="form-label">Take Profit 1 ($)</label>
                        <input type="number" step="0.01" id="calcTp1" class="form-input mono text-bull" value="<?= round($livePrice + 10.0, 2) ?>">
                    </div>
                    <div>
                        <label class="form-label">Take Profit 2 ($)</label>
                        <input type="number" step="0.01" id="calcTp2" class="form-input mono text-bull" value="<?= round($livePrice + 17.5, 2) ?>">
                    </div>
                </div>
            </div>

            <button type="button" class="btn btn-primary" id="recalcBtn" style="width: 100%; margin-top: 10px; font-weight: 800;">
                ⚡ Calculate Lot Sizing
            </button>
        </form>
    </div>

    <!-- Output Metrics Card -->
    <div class="calc-card">
        <!-- Big Lot Output Banner -->
        <div class="calc-highlight">
            <div style="font-size: 12px; font-weight: 700; color: var(--gold-bright); text-transform: uppercase;">
                Recommended Position Size
            </div>
            <div class="calc-big-lot" id="resLots">0.10 Lots</div>
            <div class="muted" style="font-size: 12px;" id="resUnits">Equivalent to 10.0 oz of Gold</div>
        </div>

        <div class="levels-card-grid">
            <div class="level-card">
                <div class="level-card-label">Monetary Risk</div>
                <div class="level-card-val text-bear" id="resRiskUsd">$50.00</div>
            </div>
            <div class="level-card">
                <div class="level-card-label">SL Distance</div>
                <div class="level-card-val mono" id="resSlDist">$5.00</div>
            </div>
            <div class="level-card">
                <div class="level-card-label">Profit @ TP1</div>
                <div class="level-card-val text-bull" id="resTp1Usd">$100.00 (2.0R)</div>
            </div>
            <div class="level-card">
                <div class="level-card-label">Profit @ TP2</div>
                <div class="level-card-val text-bull" id="resTp2Usd">$175.00 (3.5R)</div>
            </div>
        </div>

        <h4 style="font-size: 13px; font-weight: 700; margin: 18px 0 10px;">Drawdown &amp; Growth Simulation (Risk:Reward)</h4>
        <div style="overflow-x: auto;">
            <table class="mtf-table" style="font-size: 12px;">
                <thead>
                    <tr>
                        <th>Scenario</th>
                        <th>Outcome</th>
                        <th>Account Equity</th>
                        <th>Net Growth / DD</th>
                    </tr>
                </thead>
                <tbody id="simTableBody">
                    <!-- Populated by JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const symbol = '<?= $symbol ?>';
    const contractMultiplier = symbol === 'XAUUSD' ? 100.0 : 1.0;

    function recalculate() {
        const balance = parseFloat(document.getElementById('calcBalance').value) || 10000;
        const riskPct = parseFloat(document.getElementById('calcRiskPct').value) || 0.5;
        const dir = document.getElementById('calcDirection').value;
        const entry = parseFloat(document.getElementById('calcEntry').value) || 2400;
        const sl = parseFloat(document.getElementById('calcSl').value) || 2395;
        const tp1 = parseFloat(document.getElementById('calcTp1').value) || 2410;
        const tp2 = parseFloat(document.getElementById('calcTp2').value) || 2417.5;

        const maxRiskAllowed = balance * (riskPct / 100.0);
        const slDistance = Math.abs(entry - sl);

        if (slDistance <= 0.001) {
            return;
        }

        // Lot sizing formula: Risk USD / (SL Distance * Contract Size)
        let lots = maxRiskAllowed / (slDistance * contractMultiplier);
        lots = Math.max(0.01, Math.round(lots * 100) / 100); // 2 decimals
        const actualRiskUsd = lots * slDistance * contractMultiplier;

        const tp1Dist = Math.abs(tp1 - entry);
        const tp2Dist = Math.abs(tp2 - entry);
        const rr1 = slDistance > 0 ? (tp1Dist / slDistance).toFixed(2) : '0';
        const rr2 = slDistance > 0 ? (tp2Dist / slDistance).toFixed(2) : '0';

        const tp1Profit = lots * tp1Dist * contractMultiplier;
        const tp2Profit = lots * tp2Dist * contractMultiplier;

        document.getElementById('resLots').textContent = lots.toFixed(2) + ' Lots';
        document.getElementById('resUnits').textContent = `Equivalent to ${(lots * contractMultiplier).toFixed(1)} ${symbol === 'XAUUSD' ? 'oz Gold' : 'BTC'}`;
        document.getElementById('resRiskUsd').textContent = '$' + actualRiskUsd.toFixed(2);
        document.getElementById('resSlDist').textContent = '$' + slDistance.toFixed(2);
        document.getElementById('resTp1Usd').textContent = '$' + tp1Profit.toFixed(2) + ` (${rr1}R)`;
        document.getElementById('resTp2Usd').textContent = '$' + tp2Profit.toFixed(2) + ` (${rr2}R)`;

        // Simulation table
        const tbody = document.getElementById('simTableBody');
        tbody.innerHTML = `
            <tr>
                <td>3 Consecutive Losses</td>
                <td class="text-bear">-$${(actualRiskUsd * 3).toFixed(2)}</td>
                <td class="mono">$${(balance - actualRiskUsd * 3).toFixed(2)}</td>
                <td class="text-bear">-${((actualRiskUsd * 3 / balance) * 100).toFixed(1)}%</td>
            </tr>
            <tr>
                <td>3 Wins at TP1 (${rr1}R)</td>
                <td class="text-bull">+$${(tp1Profit * 3).toFixed(2)}</td>
                <td class="mono">$${(balance + tp1Profit * 3).toFixed(2)}</td>
                <td class="text-bull">+${((tp1Profit * 3 / balance) * 100).toFixed(1)}%</td>
            </tr>
            <tr>
                <td>5 Wins at TP2 (${rr2}R)</td>
                <td class="text-bull">+$${(tp2Profit * 5).toFixed(2)}</td>
                <td class="mono">$${(balance + tp2Profit * 5).toFixed(2)}</td>
                <td class="text-bull">+${((tp2Profit * 5 / balance) * 100).toFixed(1)}%</td>
            </tr>
        `;
    }

    recalculate();
    document.querySelectorAll('#riskCalcForm input, #riskCalcForm select').forEach(el => {
        el.addEventListener('input', recalculate);
        el.addEventListener('change', recalculate);
    });
    document.getElementById('recalcBtn').addEventListener('click', recalculate);
});
</script>

<?php
renderFooter();
