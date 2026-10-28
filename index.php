<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Analysis\AnalysisService;
use App\Core\Settings;
use App\Core\Validator;
use App\Market\MarketDataService;
use App\Market\Timeframe;

$activeSymbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');
$ctx = renderHeader($activeSymbol . ' SMC Terminal', 'dashboard');
$user = $ctx['user'];
$uSettings = $ctx['settings'];

$defaultTf = (string)Settings::get('market.entry_tf', 'M15');
$service = new AnalysisService(null, null, null, $activeSymbol);
$initialState = $service->dashboardState($defaultTf, (int)$user['id']);
$symDisplay = $initialState['symbol_meta']['display'] ?? ($activeSymbol === 'BTCUSD' ? 'BTC/USD' : 'XAU/USD');

$isEmergencyStop = (bool)Settings::get('risk.emergency_stop', false);
$initRegime = $initialState['analysis']['regime'] ?? 'ranging_consolidation';
$initRegimeLabel = match ($initRegime) {
    'trending_bullish' => 'Trending Bullish',
    'trending_bearish' => 'Trending Bearish',
    'high_volatility_abnormal' => 'Abnormal Volatility (Halted)',
    default => 'Ranging / Consolidation',
};
$initRegimeClass = match ($initRegime) {
    'trending_bullish' => 'badge-bull',
    'trending_bearish' => 'badge-bear',
    'high_volatility_abnormal' => 'badge-bear',
    default => 'badge-gold',
};
?>

<!-- Persistent Institutional Safety Alert Banner -->
<div id="emergencyStopBanner" style="<?= $isEmergencyStop ? 'display:flex;' : 'display:none;' ?>background:rgba(239, 68, 68, 0.15);border:1px solid #ef4444;border-radius:var(--radius);padding:10px 16px;margin-bottom:14px;align-items:center;justify-content:space-between;gap:12px;color:#ef4444;">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:18px;">⛔</span>
        <div>
            <strong>INSTITUTIONAL RISK EMERGENCY STOP ACTIVE:</strong>
            <span style="font-size:12px;opacity:0.95;">All trade signal generation, automated entries, and executions are halted across all pairs.</span>
        </div>
    </div>
    <a href="backtest.php" class="btn btn-xs btn-primary" style="white-space:nowrap;">Manage Kill Switch</a>
</div>

<!-- Top Market Metrics Strip -->
<section class="stat-strip" aria-label="Live Market Metrics">
    <div class="stat-card">
        <div class="stat-label" id="statSymbolTitle"><?= Validator::e($symDisplay) ?> Spot Price</div>
        <div class="stat-value" id="statPrice">$<?= isset($initialState['quote']['price']) ? number_format((float)$initialState['quote']['price'], 2) : '—' ?></div>
        <div class="stat-sub" id="statChange">
            <?php if (!empty($initialState['quote']['change'])):
                $chg = (float)$initialState['quote']['change'];
                $pct = (float)($initialState['quote']['change_pct'] ?? 0);
            ?>
                <span class="<?= $chg >= 0 ? 'text-bull' : 'text-bear' ?>">
                    <?= ($chg >= 0 ? '+' : '') . number_format($chg, 2) ?> (<?= ($pct >= 0 ? '+' : '') . number_format($pct, 2) ?>%)
                </span>
            <?php else: ?>
                <span>Source: <?= Validator::e($initialState['provider_label']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">HTF Structure Bias (D1 / H4)</div>
        <?php $bias = (string)($initialState['analysis']['htf_bias'] ?? 'neutral'); ?>
        <div class="stat-value <?= $bias === 'bullish' ? 'text-bull' : ($bias === 'bearish' ? 'text-bear' : 'text-gold') ?>" id="statHtfBias">
            <?= strtoupper($bias) ?>
        </div>
        <div class="stat-sub" id="statHtfVotes">
            <?php
            $votes = [];
            foreach (($initialState['analysis']['htf_votes'] ?? []) as $tf => $v) {
                $votes[] = "$tf: " . strtoupper((string)$v);
            }
            echo Validator::e(implode(' · ', $votes) ?: 'Awaiting analysis');
            ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Signal &amp; Setup</div>
        <?php
        $sig = $initialState['analysis']['signal'] ?? [];
        $dir = $sig['direction'] ?? 'WAIT';
        ?>
        <div class="stat-value <?= $dir === 'BUY' ? 'text-bull' : ($dir === 'SELL' ? 'text-bear' : 'text-gold') ?>" id="statSignalLabel">
            <?= Validator::e($sig['label'] ?? 'WAIT / NO TRADE') ?>
        </div>
        <div class="stat-sub" id="statSignalMeta">
            Score: <strong><?= (int)($sig['quality_score'] ?? 0) ?>/100</strong> · Status: <span class="mono"><?= Validator::e(strtoupper((string)($sig['setup_status'] ?? 'none'))) ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Dealing Range (<?= Validator::e($initialState['analysis']['poi_tf'] ?? 'H1') ?>)</div>
        <?php
        $poiTf = $initialState['analysis']['poi_tf'] ?? 'H1';
        $pd = $initialState['analysis']['timeframes'][$poiTf]['pd'] ?? null;
        ?>
        <div class="stat-value" id="statPdZone">
            <?= $pd ? strtoupper((string)$pd['zone']) . ' (' . $pd['position_pct'] . '%)' : 'N/A' ?>
        </div>
        <div class="stat-sub mono" id="statPdRange">
            <?= $pd ? sprintf('L: %.2f · EQ: %.2f · H: %.2f', $pd['low'], $pd['eq'], $pd['high']) : '—' ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Session &amp; Market</div>
        <div class="stat-value" id="statSession"><?= Validator::e($initialState['active_session']) ?></div>
        <div class="stat-sub" id="statMarketOpen">
            <?= !empty($initialState['market_status']['open']) ? '🟢 ' : '🔴 ' ?>
            <?= Validator::e($initialState['market_status']['reason'] ?? '') ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Market Regime (Adaptive)</div>
        <div class="stat-value" id="statRegimeLabel" style="font-size:14px;margin-top:2px;">
            <span class="badge <?= $initRegimeClass ?>" id="statRegimeBadge"><?= Validator::e($initRegimeLabel) ?></span>
        </div>
        <div class="stat-sub" id="statRegimeDesc">
            Risk: 0.5% · Daily Cap: 2.0%
        </div>
    </div>
</section>

<div class="grid-terminal">
    <!-- Left Column: TradingView-Style SMC Chart Workstation + Multi-Timeframe Matrix + Narrative -->
    <div>
        <section class="panel tv-panel" id="tvWorkstation">
            <!-- TradingView Top Toolbar -->
            <div class="tv-topbar">
                <div class="tv-toolbar-left">
                    <!-- Multi-Asset Symbol Switcher -->
                    <div class="tv-symbol-switch" id="symbolSwitcher" role="group" aria-label="Market Symbol" style="flex-wrap:wrap;max-width:580px;">
                        <?php
                        $symIcons = [
                            'XAUUSD' => ['cls' => 'gold', 'txt' => 'Au'],
                            'BTCUSD' => ['cls' => 'btc',  'txt' => '₿'],
                            'EURUSD' => ['cls' => 'fx',   'txt' => '€'],
                            'GBPUSD' => ['cls' => 'fx',   'txt' => '£'],
                            'USDJPY' => ['cls' => 'fx',   'txt' => '¥'],
                            'US30'   => ['cls' => 'idx',  'txt' => '30'],
                            'NAS100' => ['cls' => 'idx',  'txt' => 'NQ'],
                        ];
                        foreach (($initialState['symbols'] ?? ['XAUUSD' => [], 'BTCUSD' => []]) as $sCode => $sMeta):
                            $icon = $symIcons[$sCode] ?? ['cls' => 'gold', 'txt' => substr($sCode, 0, 2)];
                            $isActive = ($activeSymbol === $sCode);
                        ?>
                            <button type="button" class="tv-sym-btn <?= $isActive ? 'active' : '' ?>" data-symbol="<?= Validator::e($sCode) ?>" title="<?= Validator::e($sMeta['name'] ?? $sCode) ?>">
                                <span class="tv-sym-icon <?= $icon['cls'] ?>"><?= $icon['txt'] ?></span> <?= Validator::e($sCode) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <span class="tv-divider"></span>

                    <!-- Timeframe Bar -->
                    <div class="tf-group" id="tfSelector" role="group" aria-label="Timeframe">
                        <?php foreach (Timeframe::ALL as $tf): ?>
                        <button type="button" class="tf-btn <?= $tf === $defaultTf ? 'active' : '' ?>" data-tf="<?= $tf ?>"><?= $tf ?></button>
                        <?php endforeach; ?>
                    </div>

                    <span class="tv-divider"></span>

                    <!-- Bar Style Selector (TradingView Candles / Hollow / OHLC / Heikin Ashi / Line / Area) -->
                    <select id="tvBarStyleSelect" class="tv-select" title="Chart Bar Style">
                        <option value="candles">🕯 Candles</option>
                        <option value="hollow">▯ Hollow Candles</option>
                        <option value="ohlc">⊢ OHLC Bars</option>
                        <option value="heikin_ashi">🕯 Heikin Ashi</option>
                        <option value="line">╱ Line</option>
                        <option value="area">⛰ Area</option>
                    </select>

                    <!-- Timezone Switcher for Live Chart -->
                    <select id="tvTzSelect" class="tv-select" title="Chart Timezone Display">
                        <option value="Asia/Bangkok">🕒 Bangkok (UTC+7)</option>
                        <option value="UTC">🕒 UTC</option>
                        <option value="America/New_York">🕒 New York (EST/EDT)</option>
                        <option value="Europe/London">🕒 London (GMT/BST)</option>
                        <option value="Asia/Tokyo">🕒 Tokyo (JST)</option>
                    </select>

                    <span class="badge <?= $initRegimeClass ?> mono" id="tvRegimeBadge" title="Adaptive Market Regime">⚡ <?= Validator::e($initRegimeLabel) ?></span>
                    <span class="badge badge-live mono" id="tvLiveClockBadge" title="Real-Time Auto-Sync Every 5s">● LIVE</span>
                </div>

                <div class="tv-toolbar-right">
                    <!-- SMC Overlays & Indicators -->
                    <div class="overlay-toggles" id="overlayToggles" role="group" aria-label="Chart Overlays">
                        <button type="button" class="toggle-chip active" data-overlay="structure" title="BOS / CHOCH / MSS">BOS/CHOCH</button>
                        <button type="button" class="toggle-chip active" data-overlay="ob" title="Order Blocks">OB</button>
                        <button type="button" class="toggle-chip active" data-overlay="fvg" title="Fair Value Gaps">FVG</button>
                        <button type="button" class="toggle-chip active" data-overlay="liquidity" title="BSL / SSL / Sweeps">Liq</button>
                        <button type="button" class="toggle-chip active" data-overlay="pd" title="Premium / Discount Dealing Range">P/D</button>
                        <button type="button" class="toggle-chip active" data-overlay="levels" title="PDH / PDL / PWH / PWL">PDH/L</button>
                        <button type="button" class="toggle-chip active" data-overlay="signal" title="Signal Entry / SL / TP">Signal</button>
                    </div>

                    <span class="tv-divider"></span>

                    <!-- Edit Chart Bar / Settings, Reset, Snapshot, Fullscreen, Scan -->
                    <button type="button" class="btn btn-xs" id="tvSettingsBtn" title="Edit Chart Bar Colors & Style">⚙ Edit Chart</button>
                    <button type="button" class="btn btn-xs" id="resetZoomBtn" title="Reset Zoom & Auto-Fit Scale">⟲ Reset</button>
                    <button type="button" class="btn btn-xs" id="tvShotBtn" title="Download Chart Snapshot PNG">📷</button>
                    <button type="button" class="btn btn-xs" id="tvFullBtn" title="Toggle Fullscreen Chart">⛶</button>
                    <button type="button" class="btn btn-xs btn-primary" id="runAnalysisBtn">⟳ Run SMC Scan</button>
                </div>
            </div>

            <!-- TradingView Edit Chart Settings Popover -->
            <div id="tvSettingsPopover" class="tv-settings-popover hidden">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;border-bottom:1px solid #363a45;padding-bottom:6px;">
                    <strong>⚙ Customize Chart &amp; Bars</strong>
                    <button type="button" class="btn btn-xs btn-ghost" id="closeTvSettingsBtn">✕</button>
                </div>

                <div class="tv-setting-row">
                    <span>Bullish Bar Color</span>
                    <input type="color" id="tvBullColor" value="#089981">
                </div>
                <div class="tv-setting-row">
                    <span>Bearish Bar Color</span>
                    <input type="color" id="tvBearColor" value="#f23645">
                </div>
                <div class="tv-setting-row">
                    <span>Bar Thickness</span>
                    <input type="range" id="tvBodyWidth" min="0.35" max="0.90" step="0.05" value="0.72">
                </div>
                <div class="tv-setting-row">
                    <span>EMA 20 &amp; EMA 50 Lines</span>
                    <input type="checkbox" id="tvShowEma" checked>
                </div>
                <div class="tv-setting-row">
                    <span>Volume Histogram</span>
                    <input type="checkbox" id="tvShowVolume" checked>
                </div>
                <div class="tv-setting-row">
                    <span>Background Grid Lines</span>
                    <input type="checkbox" id="tvShowGrid" checked>
                </div>
                <div class="tv-setting-row">
                    <span>Bar Close Countdown</span>
                    <input type="checkbox" id="tvShowCountdown" checked>
                </div>

                <div style="display:flex;justify-content:space-between;gap:8px;margin-top:10px;">
                    <button type="button" class="btn btn-xs" id="tvPresetGoldBtn">Gold Theme</button>
                    <button type="button" class="btn btn-xs" id="tvResetStyleBtn">Reset Default</button>
                </div>
            </div>

            <!-- TradingView Left Drawing Sidebar + Main Interactive Canvas -->
            <div class="tv-body">
                <div class="tv-leftbar" id="tvDrawingBar" role="toolbar" aria-label="Chart Drawing Tools">
                    <button type="button" class="tv-tool-btn active" data-tool="cursor" title="Crosshair & Pan (Drag Price/Time axes to scale)">┼</button>
                    <button type="button" class="tv-tool-btn" data-tool="trendline" title="Draw Trendline (Click 2 points)">╱</button>
                    <button type="button" class="tv-tool-btn" data-tool="hline" title="Drop Horizontal Price Level (Click 1 point)">─</button>
                    <button type="button" class="tv-tool-btn" data-tool="rect" title="Draw Supply/Demand Zone Box (Click 2 corners)">▭</button>
                    <button type="button" class="tv-tool-btn" data-tool="ruler" title="Measure Price, % & Bars (Click 2 points)">📏</button>
                    <span style="flex:1;"></span>
                    <button type="button" class="tv-tool-btn" id="tvClearDrawingsBtn" title="Clear All User Drawings">🗑</button>
                </div>

                <div class="tv-canvas-container">
                    <canvas id="smcCanvas" class="chart-canvas"></canvas>
                    <div id="chartHud" class="tv-legend">Loading candles...</div>
                </div>
            </div>
        </section>

        <!-- Multi-Timeframe Alignment Matrix -->
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Multi-Timeframe Structure &amp; Liquidity Matrix (<span id="mtfSymbolLabel"><?= Validator::e($symDisplay) ?></span>)</div>
                <span class="muted mono" id="lastAnalyzedTime">
                    Analyzed: <?= gmdate('Y-m-d H:i:s', (int)($initialState['analysis']['analyzed_at'] ?? time())) ?> UTC
                </span>
            </div>
            <div class="panel-body">
                <div class="mtf-grid" id="mtfGrid"></div>
            </div>
        </section>

        <!-- Professional Trend Analysis Engine (Section 2 & 12) -->
        <section class="panel" id="trendTraderPanel">
            <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div class="panel-title">Professional Trend &amp; Momentum Analysis (<span id="trendSymbolLabel"><?= Validator::e($symDisplay) ?></span>)</div>
                    <span class="badge" id="trendClassBadge">Loading...</span>
                    <span class="badge badge-gold" id="trendConfidenceBadge">Score: —</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <button type="button" class="btn btn-xs btn-ghost active" id="btnViewNarrative">SMC Narrative</button>
                    <button type="button" class="btn btn-xs btn-ghost" id="btnViewStandardReport">📋 Standard Report (Sec 12)</button>
                    <button type="button" class="btn btn-xs btn-ghost" id="btnCopyReport">📋 Copy</button>
                </div>
            </div>
            <div class="panel-body">
                <div class="trend-metrics-grid" id="trendMetricsGrid"></div>
                <div style="margin-top:12px;">
                    <div class="stat-label" style="margin-bottom:6px;">Trend Evidence &amp; Institutional Confluence</div>
                    <ul class="confluence-list" id="trendEvidenceList"></ul>
                </div>
            </div>
        </section>

        <!-- Institutional SMC Commentary + Key Levels & Session Ranges -->
        <div class="grid-2">
            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title" id="narrativeTitle">AI / Rule-Engine Institutional Narrative</div>
                    <span class="badge badge-role" id="aiProviderBadge"><?= Validator::e($sig['ai_provider'] ?? 'rule-engine') ?></span>
                </div>
                <div class="panel-body">
                    <div class="ai-box" id="aiExplanationBox"><?= Validator::e($sig['ai_explanation'] ?? '') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Key Liquidity Levels &amp; Session Ranges</div>
                </div>
                <div class="panel-body">
                    <div class="signal-levels-grid" id="keyLevelsBox"></div>
                    <div style="margin-top:12px;">
                        <div class="stat-label" style="margin-bottom:6px;">Session High / Low Ranges</div>
                        <div class="table-wrap">
                            <table class="data-table" id="sessionRangesTable">
                                <thead>
                                    <tr><th>Session</th><th>Low</th><th>High</th><th>Range</th><th>State</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Right Column: Active Signal Execution Card, Confluence Checklist & Position Size Calculator -->
    <div>
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Trade Setup &amp; Confluence Engine</div>
                <span class="badge badge-gold" id="entryTfBadge">Entry TF: <?= Validator::e($initialState['analysis']['entry_tf'] ?? 'M15') ?></span>
            </div>
            <div class="panel-body">
                <div id="signalCardContainer"></div>

                <div style="margin-top:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;">
                        <span>Setup Quality Score (Rule Confluence)</span>
                        <span class="mono" id="scoreValueText">0 / 100</span>
                    </div>
                    <div class="score-bar-wrap">
                        <div class="score-bar-fill" id="scoreBarFill" style="width:0%"></div>
                    </div>
                    <div class="muted" style="font-size:11px;margin-bottom:10px;">
                        Measures SMC rule alignment — not a statistical win probability.
                    </div>
                </div>

                <div class="stat-label" style="margin-bottom:6px;">8-Point SMC Confluence Checklist</div>
                <ul class="confluence-list" id="confluenceList"></ul>

                <div id="warningsBox" style="margin-top:10px;"></div>
            </div>
        </section>

        <!-- Interactive Position Size & Risk Calculator -->
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Position Size &amp; Risk Calculator (<span id="rcSymbolBadge"><?= Validator::e($activeSymbol) ?></span>)</div>
                <span class="badge badge-neutral">Max <?= Validator::e((string)Settings::get('risk.max_risk_pct', 2.0)) ?>% Risk</span>
            </div>
            <div class="panel-body">
                <form id="riskCalcForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="rcBalance">Account Balance ($)</label>
                            <input type="number" step="0.01" id="rcBalance" value="<?= Validator::e((string)($uSettings['account_balance'] ?? 10000)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="rcRiskPct">Risk % (Cap <?= Validator::e((string)Settings::get('risk.max_risk_pct', 2.0)) ?>%)</label>
                            <input type="number" step="0.05" id="rcRiskPct" value="<?= Validator::e((string)($uSettings['risk_percent'] ?? 0.5)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="rcEntry">Entry Price ($)</label>
                            <input type="number" step="0.01" id="rcEntry" value="<?= Validator::e((string)($sig['entry'] ?? $initialState['quote']['price'] ?? 3997)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="rcSl">Stop Loss ($)</label>
                            <input type="number" step="0.01" id="rcSl" value="<?= Validator::e((string)($sig['sl'] ?? ((float)($initialState['quote']['price'] ?? 3997) - 6.0))) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="rcTp1">TP1 Price ($)</label>
                            <input type="number" step="0.01" id="rcTp1" value="<?= Validator::e((string)($sig['tp1'] ?? ((float)($initialState['quote']['price'] ?? 3997) + 12.0))) ?>">
                        </div>
                        <div class="form-group">
                            <label for="rcTp2">TP2 Price ($)</label>
                            <input type="number" step="0.01" id="rcTp2" value="<?= Validator::e((string)($sig['tp2'] ?? ((float)($initialState['quote']['price'] ?? 3997) + 18.0))) ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px;">Calculate Position Size</button>
                </form>

                <div id="riskResultBox" style="margin-top:12px;"></div>
            </div>
        </section>

        <!-- Upcoming Economic Releases Widget -->
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Upcoming USD / Macro News</div>
                <a href="calendar.php" class="btn btn-xs">Full Calendar →</a>
            </div>
            <div class="panel-body" id="miniCalendarBox"></div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const state = <?= json_encode($initialState, JSON_UNESCAPED_SLASHES) ?>;
    let currentSymbol = state.symbol || 'XAUUSD';
    let currentTf = state.chart_tf || 'M15';

    const canvas = document.getElementById('smcCanvas');
    const hud = document.getElementById('chartHud');
    const chart = new GoldSmc.SmcChart(canvas, hud);

    // Sync Edit Chart UI inputs with saved chart.style
    const barStyleSelect = document.getElementById('tvBarStyleSelect');
    const bullInput = document.getElementById('tvBullColor');
    const bearInput = document.getElementById('tvBearColor');
    const widthInput = document.getElementById('tvBodyWidth');
    const emaCheck = document.getElementById('tvShowEma');
    const volCheck = document.getElementById('tvShowVolume');
    const gridCheck = document.getElementById('tvShowGrid');
    const cdCheck = document.getElementById('tvShowCountdown');

    function syncStyleInputs() {
        const st = chart.style || {};
        barStyleSelect.value = st.barStyle || 'candles';
        bullInput.value = st.bullColor || '#089981';
        bearInput.value = st.bearColor || '#f23645';
        widthInput.value = st.bodyWidthRatio || 0.72;
        emaCheck.checked = st.showEma !== false;
        volCheck.checked = st.showVolume !== false;
        gridCheck.checked = st.showGrid !== false;
        cdCheck.checked = st.showCountdown !== false;
    }
    syncStyleInputs();

    function renderDashboard(data) {
        currentSymbol = data.symbol || currentSymbol;
        const symMeta = data.symbol_meta || {};
        const disp = symMeta.display || (currentSymbol === 'BTCUSD' ? 'BTC/USD' : 'XAU/USD');
        const q = data.quote || {};
        const a = data.analysis || {};
        const sig = a.signal || {};

        document.getElementById('statSymbolTitle').textContent = disp + ' Spot Price';
        document.getElementById('mtfSymbolLabel').textContent = disp;
        document.getElementById('rcSymbolBadge').textContent = currentSymbol;

        if (q.price) {
            document.getElementById('statPrice').textContent = '$' + GoldSmc.fmtPrice(q.price);
        }
        if (q.change !== undefined && q.change !== null) {
            const c = Number(q.change);
            const p = Number(q.change_pct || 0);
            document.getElementById('statChange').innerHTML = `<span class="${c >= 0 ? 'text-bull' : 'text-bear'}">${c >= 0 ? '+' : ''}${c.toFixed(2)} (${p >= 0 ? '+' : ''}${p.toFixed(2)}%)</span>`;
        }

        const biasEl = document.getElementById('statHtfBias');
        const bias = (a.htf_bias || 'neutral').toLowerCase();
        biasEl.textContent = bias.toUpperCase();
        biasEl.className = 'stat-value ' + (bias === 'bullish' ? 'text-bull' : (bias === 'bearish' ? 'text-bear' : 'text-gold'));

        const votes = Object.entries(a.htf_votes || {}).map(([k, v]) => `${k}: ${String(v).toUpperCase()}`).join(' · ');
        document.getElementById('statHtfVotes').textContent = votes || '—';

        const sigLbl = document.getElementById('statSignalLabel');
        sigLbl.textContent = sig.label || 'WAIT / NO TRADE';
        sigLbl.className = 'stat-value ' + (sig.direction === 'BUY' ? 'text-bull' : (sig.direction === 'SELL' ? 'text-bear' : 'text-gold'));
        document.getElementById('statSignalMeta').innerHTML = `Score: <strong>${sig.quality_score ?? 0}/100</strong> · Status: <span class="mono">${String(sig.setup_status || 'none').toUpperCase()}</span>`;

        const poiTf = a.poi_tf || 'H1';
        const pd = a.timeframes?.[poiTf]?.pd;
        document.getElementById('statPdZone').textContent = pd ? `${String(pd.zone).toUpperCase()} (${pd.position_pct}%)` : 'N/A';
        document.getElementById('statPdRange').textContent = pd ? `L: ${GoldSmc.fmtPrice(pd.low)} · EQ: ${GoldSmc.fmtPrice(pd.eq)} · H: ${GoldSmc.fmtPrice(pd.high)}` : '—';
        document.getElementById('statSession').textContent = data.active_session || '—';
        document.getElementById('statMarketOpen').textContent = (data.market_status?.open ? '🟢 ' : '🔴 ') + (data.market_status?.reason || '');
        document.getElementById('lastAnalyzedTime').textContent = 'Analyzed: ' + GoldSmc.fmtTime(a.analyzed_at, true);

        // Adaptive Market Regime & Emergency Stop
        const reg = a.regime || 'ranging_consolidation';
        const regBadge = document.getElementById('statRegimeBadge');
        const tvRegBadge = document.getElementById('tvRegimeBadge');
        let regCls = 'badge-gold';
        let regTxt = 'Ranging / Consolidation';
        if (reg === 'trending_bullish') { regCls = 'badge-bull'; regTxt = 'Trending Bullish'; }
        else if (reg === 'trending_bearish') { regCls = 'badge-bear'; regTxt = 'Trending Bearish'; }
        else if (reg === 'high_volatility_abnormal') { regCls = 'badge-bear'; regTxt = 'Abnormal Volatility (Halted)'; }
        if (regBadge) {
            regBadge.className = 'badge ' + regCls;
            regBadge.textContent = regTxt;
        }
        if (tvRegBadge) {
            tvRegBadge.className = 'badge ' + regCls + ' mono';
            tvRegBadge.textContent = '⚡ ' + regTxt;
        }

        const emBanner = document.getElementById('emergencyStopBanner');
        if (emBanner && data.emergency_stop !== undefined) {
            emBanner.style.display = data.emergency_stop ? 'flex' : 'none';
        }

        // Chart update
        chart.setData(data.chart_candles || [], data.chart_smc, a.key_levels || {}, data.session_ranges || {}, sig, currentSymbol, currentTf);

        // Multi-timeframe cards
        const mtfGrid = document.getElementById('mtfGrid');
        const tfs = a.timeframes || {};
        mtfGrid.innerHTML = Object.entries(tfs).map(([tf, info]) => {
            if (info.error) {
                return `<div class="mtf-card"><div class="mtf-head"><span class="mtf-tf">${tf}</span><span class="badge badge-neutral">ERR</span></div><div class="muted">${GoldSmc.escapeHtml(info.error)}</div></div>`;
            }
            const t = info.trend || 'neutral';
            const bClass = t === 'bullish' ? 'badge-bull' : (t === 'bearish' ? 'badge-bear' : 'badge-neutral');
            const ev = info.last_event ? `${info.last_event.dir === 1 ? '▲' : '▼'} ${info.last_event.type}${info.last_event.mss ? ' (MSS)' : ''} @ ${GoldSmc.fmtPrice(info.last_event.level)}` : 'None';
            const pdTxt = info.pd ? `${info.pd.zone.toUpperCase()} (${info.pd.position_pct}%)` : '—';
            return `
                <div class="mtf-card">
                    <div class="mtf-head">
                        <span class="mtf-tf">${tf}</span>
                        <span class="badge ${bClass}">${t.toUpperCase()}</span>
                    </div>
                    <div class="mtf-row"><span>Structure:</span><strong>${ev}</strong></div>
                    <div class="mtf-row"><span>Zone:</span><strong>${pdTxt}</strong></div>
                    <div class="mtf-row"><span>Fresh OB / FVG:</span><strong>${info.fresh_obs} / ${info.open_fvgs}</strong></div>
                    <div class="mtf-row"><span>ATR(14):</span><strong>$${GoldSmc.fmtPrice(info.atr)} (${info.atr_ratio}x)</strong></div>
                </div>
            `;
        }).join('');

        // Professional Trend Analysis Rendering
        const trend = data.trend_analysis || a.trend_analysis;
        const trendSym = document.getElementById('trendSymbolLabel');
        if (trendSym) trendSym.textContent = data.symbol_meta?.display || currentSymbol;

        if (trend) {
            const classBadge = document.getElementById('trendClassBadge');
            const confBadge = document.getElementById('trendConfidenceBadge');
            if (classBadge) {
                classBadge.textContent = trend.classification || 'Consolidation / Range';
                let cCls = 'badge-trend-range';
                if (trend.classification === 'Strong Bullish Trend') cCls = 'badge-trend-strong-bull';
                else if (trend.classification === 'Weak Bullish Trend') cCls = 'badge-trend-weak-bull';
                else if (trend.classification === 'Strong Bearish Trend') cCls = 'badge-trend-strong-bear';
                else if (trend.classification === 'Weak Bearish Trend') cCls = 'badge-trend-weak-bear';
                else if (trend.classification === 'Potential Reversal') cCls = 'badge-trend-reversal';
                classBadge.className = 'badge ' + cCls;
            }
            if (confBadge) {
                confBadge.textContent = `Confidence: ${trend.confidence_score || 50}/100`;
            }

            const ma = trend.moving_averages || {};
            const rsi = trend.rsi || {};
            const macd = trend.macd || {};
            const chan = trend.dynamic_channel || {};
            const sw = trend.swings_structure || {};

            const mg = document.getElementById('trendMetricsGrid');
            if (mg) {
                mg.innerHTML = `
                    <div class="trend-metric-card">
                        <div class="trend-metric-title">EMA Ribbon (20/50/200)</div>
                        <div class="trend-metric-val ${ma.ribbon_state === 'bullish_stacked' ? 'text-bull' : (ma.ribbon_state === 'bearish_stacked' ? 'text-bear' : 'text-gold')}">
                            ${(ma.ribbon_state || 'mixed').replace('_', ' ').toUpperCase()}
                        </div>
                        <div class="trend-metric-sub">20: ${GoldSmc.fmtPrice(ma.ema20)} | 50: ${GoldSmc.fmtPrice(ma.ema50)} | 200: ${GoldSmc.fmtPrice(ma.ema200)}</div>
                    </div>
                    <div class="trend-metric-card">
                        <div class="trend-metric-title">RSI (14) Momentum</div>
                        <div class="trend-metric-val ${rsi.value >= 55 ? 'text-bull' : (rsi.value <= 45 ? 'text-bear' : '')}">
                            ${rsi.value || 50} (${(rsi.zone || 'neutral').toUpperCase()})
                        </div>
                        <div class="trend-metric-sub">${rsi.divergence?.type !== 'none' ? '⚠️ ' + String(rsi.divergence?.type).replace('_', ' ').toUpperCase() : 'No divergence'}</div>
                    </div>
                    <div class="trend-metric-card">
                        <div class="trend-metric-title">MACD (12,26,9)</div>
                        <div class="trend-metric-val ${macd.hist >= 0 ? 'text-bull' : 'text-bear'}">
                            ${(macd.momentum || 'neutral').replace('_', ' ').toUpperCase()}
                        </div>
                        <div class="trend-metric-sub">Hist: ${macd.hist || 0} · Sig: ${macd.signal || 0}</div>
                    </div>
                    <div class="trend-metric-card">
                        <div class="trend-metric-title">Structure Swings</div>
                        <div class="trend-metric-val">
                            <span class="text-bull">${sw.hh_count || 0} HH / ${sw.hl_count || 0} HL</span>
                        </div>
                        <div class="trend-metric-sub text-bear">${sw.lh_count || 0} LH / ${sw.ll_count || 0} LL (Last: ${sw.last_high_type || '—'}/${sw.last_low_type || '—'})</div>
                    </div>
                    <div class="trend-metric-card">
                        <div class="trend-metric-title">ATR Dynamic Channel</div>
                        <div class="trend-metric-val">
                            ${chan.position_pct ?? 50}% Position
                        </div>
                        <div class="trend-metric-sub">L: ${GoldSmc.fmtPrice(chan.lower)} – U: ${GoldSmc.fmtPrice(chan.upper)}</div>
                    </div>
                `;
            }

            const el = document.getElementById('trendEvidenceList');
            if (el) {
                el.innerHTML = (trend.evidence || []).map(e => `
                    <li class="confluence-item met">
                        <span class="icon">✓</span>
                        <div class="body"><span class="title">${GoldSmc.escapeHtml(e)}</span></div>
                    </li>
                `).join('') || '<li class="muted">No trend evidence available</li>';
            }
        }

        // AI / Rule explanation & Section 12 Standard Report toggling
        let isStandardView = false;
        const standardReportText = data.standard_report || a.standard_report || sig.standard_report || '';
        const setReportView = (standard) => {
            isStandardView = standard;
            const box = document.getElementById('aiExplanationBox');
            const provBadge = document.getElementById('aiProviderBadge');
            const titleEl = document.getElementById('narrativeTitle');
            const btnNarr = document.getElementById('btnViewNarrative');
            const btnRep = document.getElementById('btnViewStandardReport');

            if (isStandardView) {
                if (titleEl) titleEl.textContent = 'Standard AI Analysis Report (Section 12)';
                if (provBadge) provBadge.textContent = 'SEC 12 FORMAT';
                if (box) {
                    box.style.whiteSpace = 'pre-wrap';
                    box.style.fontFamily = 'var(--font-mono)';
                    box.style.fontSize = '12px';
                    box.textContent = standardReportText || 'Standard report not available';
                }
                btnNarr?.classList.remove('active');
                btnRep?.classList.add('active');
            } else {
                if (titleEl) titleEl.textContent = 'AI / Rule-Engine Institutional Narrative';
                if (provBadge) provBadge.textContent = sig.ai_provider || 'rule-engine';
                if (box) {
                    box.style.whiteSpace = 'normal';
                    box.style.fontFamily = 'inherit';
                    box.style.fontSize = '12.5px';
                    box.textContent = sig.ai_explanation || '';
                }
                btnNarr?.classList.add('active');
                btnRep?.classList.remove('active');
            }
        };

        document.getElementById('btnViewNarrative')?.replaceWith(document.getElementById('btnViewNarrative').cloneNode(true));
        document.getElementById('btnViewStandardReport')?.replaceWith(document.getElementById('btnViewStandardReport').cloneNode(true));
        document.getElementById('btnCopyReport')?.replaceWith(document.getElementById('btnCopyReport').cloneNode(true));

        document.getElementById('btnViewNarrative')?.addEventListener('click', () => setReportView(false));
        document.getElementById('btnViewStandardReport')?.addEventListener('click', () => setReportView(true));
        document.getElementById('btnCopyReport')?.addEventListener('click', () => {
            const txt = isStandardView ? standardReportText : (sig.ai_explanation || standardReportText);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(txt).then(() => GoldSmc.toast('Copied to clipboard!'));
            } else {
                GoldSmc.toast('Copied to clipboard!');
            }
        });

        // Initialize default view
        setReportView(false);

        // Key Levels & Nearest Liquidity
        const kl = a.key_levels || {};
        const nl = a.nearest_liquidity || {};
        document.getElementById('keyLevelsBox').innerHTML = `
            <div class="level-box"><div class="lbl">Prev Day High / Low</div><div class="val">${GoldSmc.fmtPrice(kl.pdh)} / ${GoldSmc.fmtPrice(kl.pdl)}</div></div>
            <div class="level-box"><div class="lbl">Prev Week High / Low</div><div class="val">${GoldSmc.fmtPrice(kl.pwh)} / ${GoldSmc.fmtPrice(kl.pwl)}</div></div>
            <div class="level-box"><div class="lbl">Nearest Resting BSL</div><div class="val text-bull">$${GoldSmc.fmtPrice(nl.bsl)}</div></div>
            <div class="level-box"><div class="lbl">Nearest Resting SSL</div><div class="val text-bear">$${GoldSmc.fmtPrice(nl.ssl)}</div></div>
        `;

        // Session ranges table
        const srBody = document.querySelector('#sessionRangesTable tbody');
        const sr = data.session_ranges || {};
        srBody.innerHTML = Object.values(sr).map(s => `
            <tr>
                <td><strong>${GoldSmc.escapeHtml(s.label)}</strong></td>
                <td class="mono">$${GoldSmc.fmtPrice(s.low)}</td>
                <td class="mono">$${GoldSmc.fmtPrice(s.high)}</td>
                <td class="mono">$${GoldSmc.fmtPrice(s.high - s.low)}</td>
                <td><span class="badge ${s.in_progress ? 'badge-live' : 'badge-neutral'}">${s.in_progress ? 'ACTIVE' : 'CLOSED'}</span></td>
            </tr>
        `).join('') || '<tr><td colspan="5" class="muted">No session data</td></tr>';

        // Signal Setup Card & Entry Readiness Meter (Near Confirmation + Score >= 85)
        const cardContainer = document.getElementById('signalCardContainer');
        const defaultStopOffset = currentSymbol === 'BTCUSD' ? 350 : 6;
        const rdy = sig.readiness || {};
        const metCnt = Number(rdy.met_count ?? (sig.confluences || []).filter(c => c.met).length);
        const totCnt = Number(rdy.total_count || 8);
        const compPct = Number(rdy.completion_pct ?? Math.round((metCnt / Math.max(1, totCnt)) * 100));
        const rdyHtml = rdy.ready ? `
            <div class="readiness-box ${rdy.stage === 'ready_85' ? 'ready-85' : ''}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="font-size:12.5px;" class="${rdy.stage === 'ready_85' ? 'text-bull' : 'text-gold'}">
                        ${GoldSmc.escapeHtml(rdy.title || '⏳ PREPARE FOR MARKET ENTRY')}
                    </strong>
                    <button type="button" class="btn btn-xs btn-ghost" id="previewPrepPopupBtn">🔔 Pop-Up Alert</button>
                </div>
                <div class="prep-meter-track">
                    <div class="prep-meter-fill" style="width:${Math.min(100, Math.max(compPct, Number(sig.quality_score || 0)))}%;"></div>
                </div>
                <div class="muted" style="font-size:11.5px;">${GoldSmc.escapeHtml(rdy.summary || '')}</div>
            </div>
        ` : '';
        if (sig.direction === 'BUY' || sig.direction === 'SELL') {
            const cls = sig.direction === 'BUY' ? 'buy' : 'sell';
            cardContainer.innerHTML = rdyHtml + `
                <div class="signal-banner ${cls}">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong style="font-size:15px;">${GoldSmc.escapeHtml(sig.label)}</strong>
                        <span class="badge ${sig.direction === 'BUY' ? 'badge-bull' : 'badge-bear'}">${GoldSmc.escapeHtml(sig.setup_status?.toUpperCase())}</span>
                    </div>
                    <div class="muted" style="font-size:12px;margin-top:3px;">${GoldSmc.escapeHtml(sig.setup_type || 'SMC Setup')}</div>
                    <div class="signal-levels-grid">
                        <div class="level-box"><div class="lbl">Entry Zone (${GoldSmc.escapeHtml(sig.entry_tf)})</div><div class="val">$${GoldSmc.fmtPrice(sig.entry_low)} – $${GoldSmc.fmtPrice(sig.entry_high)}</div></div>
                        <div class="level-box"><div class="lbl">Ref Entry / Stop Loss</div><div class="val">$${GoldSmc.fmtPrice(sig.entry)} / <span class="text-bear">$${GoldSmc.fmtPrice(sig.sl)}</span></div></div>
                        <div class="level-box"><div class="lbl">Take Profit 1</div><div class="val text-bull">$${GoldSmc.fmtPrice(sig.tp1)} (${sig.rr1 ?? '—'}R)</div></div>
                        <div class="level-box"><div class="lbl">Take Profit 2</div><div class="val text-bull">$${GoldSmc.fmtPrice(sig.tp2)} (${sig.rr2 ?? '—'}R)</div></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:8px;flex-wrap:wrap;">
                        <span class="muted" style="font-size:11px;">✈️ Auto-Telegram: <strong>@i_am_Theaa_Rithhh</strong></span>
                        <button type="button" class="btn btn-xs btn-primary" id="sendSignalTgBtn">✈️ Send to Telegram</button>
                    </div>
                </div>
            `;
            document.getElementById('sendSignalTgBtn')?.addEventListener('click', async (ev) => {
                const b = ev.currentTarget;
                b.disabled = true;
                b.textContent = 'Sending...';
                try {
                    const r = await GoldSmc.Api.post('api/notifications.php', { action: 'send_signal', symbol: currentSymbol });
                    GoldSmc.toast(r.message || 'Signal sent to Telegram (@i_am_Theaa_Rithhh)');
                } catch (err) {
                    GoldSmc.toast(err.message, 'error');
                } finally {
                    b.disabled = false;
                    b.textContent = '✈️ Send to Telegram';
                }
            });
            document.getElementById('rcEntry').value = sig.entry;
            document.getElementById('rcSl').value = sig.sl;
            if (sig.tp1) document.getElementById('rcTp1').value = sig.tp1;
            if (sig.tp2) document.getElementById('rcTp2').value = sig.tp2;
        } else {
            const reasonsHtml = (sig.reasons || []).map(r => `<li>${GoldSmc.escapeHtml(r)}</li>`).join('');
            const watchLevelsHtml = (sig.entry && sig.sl) ? `
                <div class="signal-levels-grid" style="margin-top:8px;">
                    <div class="level-box"><div class="lbl">Watch Zone (${GoldSmc.escapeHtml(sig.entry_tf || 'M15')})</div><div class="val">$${GoldSmc.fmtPrice(sig.entry_low)} – $${GoldSmc.fmtPrice(sig.entry_high)}</div></div>
                    <div class="level-box"><div class="lbl">Watch Entry / SL</div><div class="val">$${GoldSmc.fmtPrice(sig.entry)} / <span class="text-bear">$${GoldSmc.fmtPrice(sig.sl)}</span></div></div>
                    <div class="level-box"><div class="lbl">Projected TP1</div><div class="val text-bull">$${GoldSmc.fmtPrice(sig.tp1)} (${sig.rr1 ?? '—'}R)</div></div>
                    <div class="level-box"><div class="lbl">Projected TP2</div><div class="val text-bull">$${GoldSmc.fmtPrice(sig.tp2)} (${sig.rr2 ?? '—'}R)</div></div>
                </div>
            ` : '';
            cardContainer.innerHTML = rdyHtml + `
                <div class="signal-banner wait">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong style="font-size:14.5px;">${GoldSmc.escapeHtml(sig.setup_type || 'WAIT / NO QUALIFIED SETUP')}</strong>
                        <span class="badge badge-warn">${GoldSmc.escapeHtml((sig.setup_status || 'standby').toUpperCase())}</span>
                    </div>
                    <ul style="margin:8px 0 0 16px;font-size:12px;" class="muted">${reasonsHtml}</ul>
                    ${watchLevelsHtml}
                </div>
            `;
            const refP = Number(sig.entry || q.price || (currentSymbol === 'BTCUSD' ? 80600 : 3997));
            document.getElementById('rcEntry').value = refP.toFixed(2);
            document.getElementById('rcSl').value = Number(sig.sl || (refP - defaultStopOffset)).toFixed(2);
            document.getElementById('rcTp1').value = Number(sig.tp1 || (refP + defaultStopOffset * 2)).toFixed(2);
            document.getElementById('rcTp2').value = Number(sig.tp2 || (refP + defaultStopOffset * 3)).toFixed(2);
        }

        document.getElementById('previewPrepPopupBtn')?.addEventListener('click', () => {
            if (GoldSmc.checkSignalReadiness) {
                GoldSmc.checkSignalReadiness(sig, currentSymbol, true);
            }
        });
        if (GoldSmc.checkSignalReadiness) {
            GoldSmc.checkSignalReadiness(sig, currentSymbol, false);
        }

        // Score & Confluences
        const score = Number(sig.quality_score || 0);
        document.getElementById('scoreValueText').textContent = `${score} / 100`;
        document.getElementById('scoreBarFill').style.width = `${Math.min(100, score)}%`;

        const confList = document.getElementById('confluenceList');
        confList.innerHTML = (sig.confluences || []).map(c => `
            <li class="confluence-item">
                <span class="conf-icon ${c.met ? 'text-bull' : 'text-bear'}">${c.met ? '✓' : '✕'}</span>
                <div>
                    <div><strong>${GoldSmc.escapeHtml(c.label)}</strong></div>
                    <div class="muted" style="font-size:11.5px;">${GoldSmc.escapeHtml(c.detail)}</div>
                </div>
            </li>
        `).join('');

        const warnBox = document.getElementById('warningsBox');
        const warns = sig.warnings || [];
        warnBox.innerHTML = warns.length
            ? `<div class="signal-banner wait" style="padding:8px 10px;font-size:11.5px;"><strong>Risk Notes:</strong> ${warns.map(GoldSmc.escapeHtml).join(' · ')}</div>`
            : '';

        // Mini Calendar
        const calEvents = data.calendar?.events || [];
        const miniCal = document.getElementById('miniCalendarBox');
        if (!calEvents.length) {
            miniCal.innerHTML = '<div class="muted">No scheduled events in window.</div>';
        } else {
            miniCal.innerHTML = `<div class="table-wrap"><table class="data-table">
                <thead><tr><th>Time</th><th>Ccy</th><th>Impact</th><th>Event</th></tr></thead>
                <tbody>${calEvents.slice(0, 6).map(ev => {
                    const impBadge = ev.impact === 'high' ? 'badge-bear' : (ev.impact === 'medium' ? 'badge-warn' : 'badge-neutral');
                    return `<tr>
                        <td class="mono">${GoldSmc.fmtTime(ev.time, true)}</td>
                        <td class="mono"><strong>${GoldSmc.escapeHtml(ev.country)}</strong></td>
                        <td><span class="badge ${impBadge}">${GoldSmc.escapeHtml(ev.impact)}</span></td>
                        <td>${GoldSmc.escapeHtml(ev.title)}</td>
                    </tr>`;
                }).join('')}</tbody>
            </table></div>`;
        }
    }

    renderDashboard(state);

    // Symbol Switcher (XAUUSD <-> BTCUSD)
    document.querySelectorAll('#symbolSwitcher .tv-sym-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const sym = btn.dataset.symbol;
            if (sym === currentSymbol) return;
            document.querySelectorAll('#symbolSwitcher .tv-sym-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentSymbol = sym;
            history.replaceState(null, '', '?symbol=' + encodeURIComponent(sym));
            hud.textContent = `Loading ${sym} multi-timeframe SMC state...`;
            try {
                const updated = await GoldSmc.Api.get(`api/state.php?symbol=${encodeURIComponent(currentSymbol)}&tf=${encodeURIComponent(currentTf)}`);
                Object.assign(state, updated);
                renderDashboard(state);
                runRiskCalc();
                GoldSmc.toast(`Switched terminal to ${sym}`);
            } catch (e) {
                GoldSmc.toast(e.message, 'error');
            }
        });
    });

    // Timeframe switcher
    document.querySelectorAll('#tfSelector .tf-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            document.querySelectorAll('#tfSelector .tf-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentTf = btn.dataset.tf;
            try {
                const res = await GoldSmc.Api.get(`api/candles.php?symbol=${encodeURIComponent(currentSymbol)}&tf=${encodeURIComponent(currentTf)}`);
                chart.setData(res.candles, res.smc, state.analysis?.key_levels || {}, res.session_ranges, state.analysis?.signal, currentSymbol, currentTf);
            } catch (e) {
                GoldSmc.toast(e.message, 'error');
            }
        });
    });

    // Bar Style selector
    barStyleSelect.addEventListener('change', () => {
        chart.updateStyle({ barStyle: barStyleSelect.value });
    });

    // Timezone selector & Live real-time clock badge
    const tzSelect = document.getElementById('tvTzSelect');
    const liveClockBadge = document.getElementById('tvLiveClockBadge');
    if (tzSelect && GoldSmc.getChartTimezone) {
        tzSelect.value = GoldSmc.getChartTimezone();
        tzSelect.addEventListener('change', () => {
            GoldSmc.setChartTimezone(tzSelect.value);
            chart.draw();
            updateLiveBadge();
        });
    }
    function updateLiveBadge() {
        if (!liveClockBadge) return;
        const nowSec = Math.floor(Date.now() / 1000);
        liveClockBadge.textContent = '● LIVE ' + GoldSmc.fmtTime(nowSec, false);
    }
    updateLiveBadge();
    setInterval(updateLiveBadge, 1000);

    // Real-Time Live Price & Candle Auto-Refresh (every 5 seconds) + Auto-Signal & Telegram Scan (every 15s)
    let livePollBusy = false;
    let pollCounter = 0;
    async function pollLiveMarket() {
        if (livePollBusy) return;
        livePollBusy = true;
        pollCounter++;
        try {
            if (pollCounter % 3 === 0) {
                const updated = await GoldSmc.Api.post('api/analyze.php', { force: false, auto: true, symbol: currentSymbol, tf: currentTf });
                Object.assign(state, updated);
                renderDashboard(state);
                return;
            }
            const res = await GoldSmc.Api.get(`api/candles.php?symbol=${encodeURIComponent(currentSymbol)}&tf=${encodeURIComponent(currentTf)}&live=1`);
            if (res.quote && res.quote.price) {
                state.quote = res.quote;
                document.getElementById('statPrice').textContent = '$' + GoldSmc.fmtPrice(res.quote.price);
                if (res.quote.change !== undefined && res.quote.change !== null) {
                    const c = Number(res.quote.change);
                    const p = Number(res.quote.change_pct || 0);
                    document.getElementById('statChange').innerHTML = `<span class="${c >= 0 ? 'text-bull' : 'text-bear'}">${c >= 0 ? '+' : ''}${c.toFixed(2)} (${p >= 0 ? '+' : ''}${p.toFixed(2)}%)</span>`;
                }
            }
            if (res.active_session) {
                document.getElementById('statSession').textContent = res.active_session;
            }
            if (res.market_status) {
                document.getElementById('statMarketOpen').textContent = (res.market_status.open ? '🟢 ' : '🔴 ') + (res.market_status.reason || '');
            }
            if (Array.isArray(res.candles) && res.candles.length) {
                state.chart_candles = res.candles;
                state.chart_smc = res.smc;
                state.session_ranges = res.session_ranges;
                chart.setData(res.candles, res.smc, state.analysis?.key_levels || {}, res.session_ranges, state.analysis?.signal, currentSymbol, currentTf);
            }
        } catch (_) {
            // Silent background retry
        } finally {
            livePollBusy = false;
        }
    }
    setInterval(pollLiveMarket, 5000);

    // Edit Chart Settings Popover
    const settingsPop = document.getElementById('tvSettingsPopover');
    document.getElementById('tvSettingsBtn').addEventListener('click', () => {
        settingsPop.classList.toggle('hidden');
    });
    document.getElementById('closeTvSettingsBtn').addEventListener('click', () => {
        settingsPop.classList.add('hidden');
    });
    bullInput.addEventListener('input', () => chart.updateStyle({ bullColor: bullInput.value }));
    bearInput.addEventListener('input', () => chart.updateStyle({ bearColor: bearInput.value }));
    widthInput.addEventListener('input', () => chart.updateStyle({ bodyWidthRatio: parseFloat(widthInput.value) }));
    emaCheck.addEventListener('change', () => chart.updateStyle({ showEma: emaCheck.checked }));
    volCheck.addEventListener('change', () => chart.updateStyle({ showVolume: volCheck.checked }));
    gridCheck.addEventListener('change', () => chart.updateStyle({ showGrid: gridCheck.checked }));
    cdCheck.addEventListener('change', () => chart.updateStyle({ showCountdown: cdCheck.checked }));

    document.getElementById('tvPresetGoldBtn').addEventListener('click', () => {
        chart.updateStyle({ bullColor: '#f59e0b', bearColor: '#ef4444' });
        syncStyleInputs();
    });
    document.getElementById('tvResetStyleBtn').addEventListener('click', () => {
        chart.resetStyle();
        syncStyleInputs();
    });

    // Left Drawing Toolbar
    document.querySelectorAll('#tvDrawingBar .tv-tool-btn[data-tool]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#tvDrawingBar .tv-tool-btn[data-tool]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            chart.setTool(btn.dataset.tool);
        });
    });
    document.getElementById('tvClearDrawingsBtn').addEventListener('click', () => {
        chart.clearDrawings();
        GoldSmc.toast('Chart drawings cleared.');
    });

    // Snapshot & Fullscreen
    document.getElementById('tvShotBtn').addEventListener('click', () => chart.exportPng());
    document.getElementById('tvFullBtn').addEventListener('click', () => {
        document.getElementById('tvWorkstation').classList.toggle('tv-fullscreen');
        chart.draw();
    });

    // Overlay toggles
    document.querySelectorAll('#overlayToggles .toggle-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            chip.classList.toggle('active');
            const key = chip.dataset.overlay;
            chart.overlays[key] = chip.classList.contains('active');
            chart.draw();
        });
    });

    document.getElementById('resetZoomBtn').addEventListener('click', () => chart.resetView());

    // Run SMC Scan button
    const scanBtn = document.getElementById('runAnalysisBtn');
    scanBtn.addEventListener('click', async () => {
        scanBtn.disabled = true;
        scanBtn.textContent = 'Scanning...';
        try {
            const updated = await GoldSmc.Api.post('api/analyze.php', { force: true, symbol: currentSymbol, tf: currentTf });
            Object.assign(state, updated);
            renderDashboard(state);
            runRiskCalc();
            GoldSmc.toast(`${currentSymbol} multi-timeframe SMC scan updated.`);
        } catch (e) {
            GoldSmc.toast(e.message, 'error');
        } finally {
            scanBtn.disabled = false;
            scanBtn.textContent = '⟳ Run SMC Scan';
        }
    });

    // Position Size Calculator
    const riskForm = document.getElementById('riskCalcForm');
    async function runRiskCalc() {
        const resBox = document.getElementById('riskResultBox');
        try {
            const res = await GoldSmc.Api.post('api/risk.php', {
                symbol: currentSymbol,
                balance: document.getElementById('rcBalance').value,
                risk_percent: document.getElementById('rcRiskPct').value,
                entry: document.getElementById('rcEntry').value,
                stop_loss: document.getElementById('rcSl').value,
                tp1: document.getElementById('rcTp1').value,
                tp2: document.getElementById('rcTp2').value,
            });
            const c = res.calculation;
            const t1 = c.targets?.tp1;
            const t2 = c.targets?.tp2;
            resBox.innerHTML = `
                <div class="signal-levels-grid">
                    <div class="level-box"><div class="lbl">Recommended Lot Size</div><div class="val text-gold">${c.lots} Lots (${c.ounces} ${GoldSmc.escapeHtml(c.unit || 'oz')})</div></div>
                    <div class="level-box"><div class="lbl">Dollar Risk (${c.actual_risk_pct}%)</div><div class="val text-bear">$${GoldSmc.fmtPrice(c.actual_risk_usd)}</div></div>
                    <div class="level-box"><div class="lbl">TP1 Net Profit</div><div class="val text-bull">${t1 ? `+$${GoldSmc.fmtPrice(t1.profit_usd)} (${t1.rr}R)` : '—'}</div></div>
                    <div class="level-box"><div class="lbl">TP2 Net Profit</div><div class="val text-bull">${t2 ? `+$${GoldSmc.fmtPrice(t2.profit_usd)} (${t2.rr}R)` : '—'}</div></div>
                </div>
                ${(c.warnings || []).length ? `<div class="muted" style="margin-top:6px;font-size:11.5px;">⚠️ ${c.warnings.map(GoldSmc.escapeHtml).join(' ')}</div>` : ''}
            `;
        } catch (e) {
            resBox.innerHTML = `<div class="text-bear" style="font-size:12px;">${GoldSmc.escapeHtml(e.message)}</div>`;
        }
    }
    riskForm.addEventListener('submit', (e) => {
        e.preventDefault();
        runRiskCalc();
    });
    runRiskCalc();
});
</script>

<?php renderFooter(); ?>
