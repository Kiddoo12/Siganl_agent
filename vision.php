<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;

$layout = renderHeader('AI Chart Vision Analyzer', 'vision');
$market = new MarketDataService(null, 'XAUUSD');
$quote = $market->quote(false);
$liveAvailable = !empty($quote['available']) && (($quote['mode'] ?? '') === 'live') && !empty($quote['price']) && (float)$quote['price'] > 0;
$livePrice = $liveAvailable ? (float)$quote['price'] : null;

$recentCandles = $market->candles('M15', 5);
$lastClose = !empty($recentCandles) ? (float)$recentCandles[count($recentCandles) - 1]['c'] : 2400.0;
$placeholderPrice = $livePrice ?? $lastClose;
$csrf = Csrf::token();
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>📷</span>
            <span><?= I18n::t('nav_vision') ?> — Institutional SMC / ICT / CRT / S&amp;R Engine v2</span>
        </div>
        <div class="vision-hero-sub">
            Upload or paste any chart screenshot (TradingView, MT4/MT5, cTrader) to detect Market Structure (HH/HL/LH/LL, BOS/CHoCH/MSS), Order Blocks, FVG/IFVG, Liquidity Sweeps, Multi-Timeframe alignment, and validated 2R / 3.5R / 5R setups.
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <?php if ($liveAvailable && $livePrice !== null): ?>
            <span class="badge badge-live">● Live Ref: $<?= number_format($livePrice, 2) ?></span>
        <?php else: ?>
            <span class="badge badge-warn" title="Live quote provider is offline; engine uses historical candle close or manual price input.">○ LIVE UNAVAILABLE (Historical / Manual Mode)</span>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-ghost" id="openHistoryBtn">📁 <?= I18n::t('recent_scans') ?></button>
    </div>
</div>

<div class="vision-grid">
    <!-- Left Column: Image Upload & Setup Parameters -->
    <div class="vision-upload-card">
        <h3 style="font-size: 15px; margin-bottom: 12px; font-weight: 700;">1. Chart Screenshot Input</h3>

        <!-- Inline Error Alert -->
        <div id="visionUploadError" class="hidden" style="background: rgba(239, 68, 68, 0.14); border: 1px solid #ef4444; color: #fca5a5; padding: 10px 12px; border-radius: 6px; font-size: 12px; margin-bottom: 12px;"></div>

        <!-- Drag & Drop Zone -->
        <div class="vision-dropzone" id="visionDropzone">
            <span class="vision-dropzone-icon">📥</span>
            <div class="vision-dropzone-text"><?= I18n::t('upload_prompt') ?></div>
            <div class="vision-dropzone-hint">Supports PNG, JPG, JPEG, WEBP (Min 50×50px · Max 10MB)</div>
            <button type="button" class="btn btn-sm btn-primary" style="margin-top: 12px;" onclick="document.getElementById('visionFileInput').click()">
                <?= I18n::t('upload_btn') ?>
            </button>
            <input type="file" id="visionFileInput" accept="image/png,image/jpeg,image/jpg,image/webp" style="display: none;">
        </div>

        <!-- Preview Box (shown once image is uploaded or pasted; displays exact baked/rotated canvas output) -->
        <div class="vision-preview-container hidden" id="visionPreviewContainer">
            <img id="visionPreviewImg" class="vision-preview-img" alt="Uploaded Chart Preview">
            <div id="visionPreviewMeta" class="muted" style="font-size: 11px; text-align: center; margin-top: 6px;"></div>
            <div class="vision-preview-controls">
                <button type="button" class="btn btn-xs btn-ghost" id="rotateBtn" title="Bake 90° clockwise rotation into image">🔄 Rotate 90°</button>
                <button type="button" class="btn btn-xs btn-ghost" id="replaceBtn" title="Choose another image">🔁 Replace</button>
                <button type="button" class="btn btn-xs btn-danger" id="removeBtn" title="Remove image">✕</button>
            </div>
        </div>

        <h3 style="font-size: 15px; margin: 16px 0 12px; font-weight: 700;">2. Chart Metadata &amp; Multi-Timeframe Calibration</h3>

        <form id="visionForm" onsubmit="return false;">
            <input type="hidden" name="_csrf" value="<?= Validator::e($csrf) ?>">
            <input type="hidden" id="base64Payload" name="image_base64" value="">

            <!-- Strategy Selector (ICT, SMC, CRT, SNR, ALL) -->
            <div style="margin-bottom: 14px;">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Trading Strategy Priority</span>
                    <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: var(--gold-bright); font-size: 10px;">Select Strategy</span>
                </label>
                <div class="strategy-selector-chips" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-top: 4px;">
                    <button type="button" class="strat-chip-btn active" data-strat="ALL" onclick="selectVisionStrategy('ALL')">
                        <span style="display:block;font-size:12px;font-weight:750;">🌐 ALL</span>
                        <span style="font-size:9px;opacity:0.75;">Confluence</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="SNR" onclick="selectVisionStrategy('SNR')">
                        <span style="display:block;font-size:12px;font-weight:750;">🧱 SNR</span>
                        <span style="font-size:9px;opacity:0.75;">S&amp;R Levels</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="CRT" onclick="selectVisionStrategy('CRT')">
                        <span style="display:block;font-size:12px;font-weight:750;">🕯️ CRT</span>
                        <span style="font-size:9px;opacity:0.75;">Range Reclaim</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="ICT" onclick="selectVisionStrategy('ICT')">
                        <span style="display:block;font-size:12px;font-weight:750;">⚡ ICT</span>
                        <span style="font-size:9px;opacity:0.75;">Killzone/FVG</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="SMC" onclick="selectVisionStrategy('SMC')">
                        <span style="display:block;font-size:12px;font-weight:750;">🏛️ SMC</span>
                        <span style="font-size:9px;opacity:0.75;">BOS / OB</span>
                    </button>
                </div>
                <input type="hidden" id="strategyModeInput" name="strategy_mode" value="ALL">
            </div>

            <div class="vision-form-row">
                <div>
                    <label class="form-label" for="symbolSelect">Symbol / Asset</label>
                    <select id="symbolSelect" name="symbol" class="form-select">
                        <option value="XAUUSD" selected>XAU/USD (Spot Gold)</option>
                        <option value="BTCUSD">BTC/USD (Bitcoin)</option>
                        <option value="EURUSD">EUR/USD (Euro)</option>
                        <option value="GBPUSD">GBP/USD (Pound)</option>
                        <option value="US30">US30 (Dow Jones)</option>
                        <option value="NAS100">NAS100 (Nasdaq)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="timeframeSelect">Execution Timeframe</label>
                    <select id="timeframeSelect" name="timeframe" class="form-select">
                        <option value="M1">M1 (Precision Execution)</option>
                        <option value="M5">M5 (Entry Confirmation)</option>
                        <option value="M15" selected>M15 (Setup Confirmation)</option>
                        <option value="M30">M30 (Intraday Structure)</option>
                        <option value="H1">H1 (Trading Bias)</option>
                        <option value="H4">H4 (Major Structure)</option>
                        <option value="D1">D1 (Overall Direction)</option>
                    </select>
                </div>
            </div>

            <!-- Multi-Timeframe (MTF) Confirmation Row -->
            <div class="vision-form-row">
                <div>
                    <label class="form-label" for="htfSelect">Higher-Timeframe (HTF) Check</label>
                    <select id="htfSelect" name="htf_timeframe" class="form-select">
                        <option value="" selected>Auto HTF (Recommended)</option>
                        <option value="D1">D1 (Daily Direction)</option>
                        <option value="H4">H4 (Major Swing Structure)</option>
                        <option value="H1">H1 (Intraday Bias)</option>
                        <option value="M15">M15 (Setup Confirmation)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="htfFileInput" title="Optionally attach a second higher-timeframe screenshot">Optional HTF Screenshot</label>
                    <input type="file" id="htfFileInput" name="htf_image" accept="image/png,image/jpeg,image/webp" class="form-input" style="padding: 5px; font-size: 11px;">
                </div>
            </div>

            <div class="vision-form-row">
                <div>
                    <label class="form-label" for="manualPriceInput" title="Enter current chart price for exact calibration">
                        Reference Price ($)
                    </label>
                    <input type="number" step="0.01" id="manualPriceInput" name="manual_price" class="form-input" placeholder="e.g. <?= number_format($placeholderPrice, 2) ?>" value="">
                </div>
                <div>
                    <label class="form-label" for="brokerInput">Broker / Chart Source</label>
                    <input type="text" id="brokerInput" name="broker" class="form-input" placeholder="TradingView / OANDA / Exness">
                </div>
            </div>

            <button type="button" class="btn btn-primary" id="analyzeBtn" style="width: 100%; padding: 12px; font-weight: 800; font-size: 14px;" disabled>
                🚀 <?= I18n::t('analyze_chart') ?>
            </button>
        </form>

        <!-- Institutional Safety Notice -->
        <div style="margin-top: 16px; font-size: 11.5px; color: var(--text-muted); line-height: 1.5; border-top: 1px solid var(--border); padding-top: 12px;">
            🛡️ <strong>Strict Validation Engine:</strong> Every setup is verified against mathematical inequality rules (<code>SL &lt; Entry &lt; TP1 &lt; TP2 &lt; TP3</code> for BUY; <code>SL &gt; Entry &gt; TP1 &gt; TP2 &gt; TP3</code> for SELL) and an 8-Point Setup Quality Gate.
        </div>
    </div>

    <!-- Right Column: Results, Visual Overlay & Institutional Report -->
    <div>
        <!-- Placeholder before analysis -->
        <div id="visionPlaceholder" class="vision-report-box" style="text-align: center; padding: 60px 20px;">
            <span style="font-size: 48px; display: block; margin-bottom: 12px;">📈</span>
            <h3 style="font-size: 18px; font-weight: 800; margin-bottom: 6px;">No Chart Analyzed Yet</h3>
            <p class="muted" style="max-width: 480px; margin: 0 auto;">
                Upload a chart screenshot or press <kbd style="background: var(--bg-elevated); padding: 2px 6px; border-radius: 4px;">Ctrl+V</kbd> to paste directly from your clipboard. The institutional engine will validate setup quality, check multi-timeframe alignment, and render interactive chart overlays.
            </p>
        </div>

        <!-- Honest Scanning State Loader with Real Elapsed Timer -->
        <div id="visionLoader" class="vision-report-box hidden" style="text-align: center; padding: 60px 20px;">
            <div class="spinner" style="width: 40px; height: 40px; border: 4px solid rgba(245, 158, 11, 0.2); border-top-color: var(--gold); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 16px;"></div>
            <h3 style="font-size: 17px; font-weight: 800; color: var(--gold-bright);">Analyzing Chart &amp; Validating Structure...</h3>
            <p class="muted" id="visionScanStep" style="font-size: 13px; margin-top: 6px;">Inspecting image integrity, ICT/SMC arrays, and multi-timeframe alignment...</p>
            <div id="visionElapsedTimer" class="muted" style="font-size: 12px; font-family: monospace; margin-top: 8px;">Elapsed: 0.0s</div>
        </div>

        <!-- Result Box -->
        <div id="visionResultBox" class="vision-report-box hidden">
            <!-- Visual Chart Overlay Canvas & Category Toggles -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                <h4 style="font-size: 14px; font-weight: 800;">Visual Chart Overlay</h4>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; font-size: 11.5px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #00e5ff;">
                        <input type="checkbox" id="toggleOverlayEntry" checked> Entry Zone
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #ff3366;">
                        <input type="checkbox" id="toggleOverlaySl" checked> Stop Loss
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #00ff88;">
                        <input type="checkbox" id="toggleOverlayTp" checked> TP1–TP3
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #eab308;">
                        <input type="checkbox" id="toggleOverlayStructure" checked> BOS / EQ
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #f59e0b;">
                        <input type="checkbox" id="toggleOverlayFvgOb" checked> FVG / OB
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #38bdf8;">
                        <input type="checkbox" id="toggleOverlayLiquidity" checked> BSL / SSL
                    </label>
                </div>
            </div>

            <div class="vision-canvas-wrapper" id="canvasWrapper" style="position: relative;">
                <img id="overlayChartImg" src="" alt="Chart with overlay" style="width: 100%; display: block;">
                <canvas id="overlayCanvas" class="vision-overlay-canvas"></canvas>
            </div>

            <!-- Badges Row & Send to Telegram Action -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin: 12px 0 10px;">
                <div class="vision-badge-row" style="margin: 0; flex: 1;">
                    <span class="badge-bias" id="resBiasBadge">BULLISH</span>
                    <span class="badge" style="font-weight: 800;" id="resDirectionBadge">BUY</span>
                    <span class="badge-strat" id="resStrategyBadge">ICT 2022 Mentorship Model</span>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid #38bdf8;" id="resStratModeBadge">Mode: ALL</span>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981;" id="resScoreBadge">Confidence: 85/100</span>
                    <span class="badge" id="resPriceSourceBadge" style="background: var(--bg-elevated); border: 1px solid var(--border);">Source: Engine</span>
                    <span class="badge" id="resModelBadge">Model: deterministic</span>
                </div>
                <button type="button" class="btn btn-sm" id="sendTelegramBtn" style="background: #229ed9; color: #fff; border: 1px solid #229ed9; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.87 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.46c.538-.196 1.006.128.831.92z"/></svg>
                    <span id="sendTelegramText"><?= I18n::t('send_to_telegram') ?></span>
                </button>
            </div>

            <!-- Key Levels Cards Grid -->
            <div class="levels-card-grid">
                <div class="level-card" style="border-top: 3px solid #00e5ff;">
                    <div class="level-card-label"><?= I18n::t('entry_zone') ?></div>
                    <div class="level-card-val text-gold" id="resEntryVal">$0.00 – $0.00</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #ff3366;">
                    <div class="level-card-label"><?= I18n::t('stop_loss') ?></div>
                    <div class="level-card-val text-bear" id="resSlVal">$0.00</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #00ff88;">
                    <div class="level-card-label"><?= I18n::t('tp1') ?> (2.0R)</div>
                    <div class="level-card-val text-bull" id="resTp1Val">$0.00</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #00cc66;">
                    <div class="level-card-label"><?= I18n::t('tp2') ?> (3.5R)</div>
                    <div class="level-card-val text-bull" id="resTp2Val">$0.00</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #00994d;">
                    <div class="level-card-label"><?= I18n::t('tp3') ?> (5.0R)</div>
                    <div class="level-card-val text-bull" id="resTp3Val">$0.00</div>
                </div>
            </div>

            <!-- Report Tabs -->
            <div class="vision-tabs" style="flex-wrap: wrap;">
                <button type="button" class="vision-tab-btn active" data-tab="tab-structure">Structure &amp; Liquidity</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-mtf">Multi-Timeframe (MTF)</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-quality">Quality Gate &amp; Confidence</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-facts">Observed Facts vs AI Interpretations</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-thesis">Strategy Thesis</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-risk">Invalidations &amp; Risk</button>
            </div>

            <!-- Tab 1: Structure & Liquidity -->
            <div id="tab-structure" class="vision-tab-content">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: var(--gold-bright);">Market Structure:</strong> <span id="resStructureVal">—</span>
                    <p class="muted" id="resStructureDesc" style="font-size: 12.5px; margin-top: 4px;"></p>
                    <div id="resSwingSeqLine" style="font-size: 11.5px; font-family: monospace; color: #38bdf8; margin-top: 6px;"></div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: var(--bg-elevated); padding: 10px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: var(--bull); font-size: 12px;">Buy-Side Liquidity (BSL / Equal Highs)</strong>
                        <ul id="resBslList" style="margin-left: 16px; margin-top: 6px; font-size: 12px;"></ul>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 10px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: var(--bear); font-size: 12px;">Sell-Side Liquidity (SSL / Equal Lows)</strong>
                        <ul id="resSslList" style="margin-left: 16px; margin-top: 6px; font-size: 12px;"></ul>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Multi-Timeframe (MTF) Analysis -->
            <div id="tab-mtf" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <strong style="color: #38bdf8;">Multi-Timeframe Alignment Status:</strong>
                        <span id="resMtfStatusBadge" class="badge">Aligned</span>
                    </div>
                    <p id="resMtfExplanation" class="muted" style="font-size: 12.5px; margin-top: 6px;"></p>
                </div>
                <div id="resMtfGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(125px, 1fr)); gap: 8px;"></div>
            </div>

            <!-- Tab 3: 8-Point Setup Quality Gate & 7-Component Confidence Breakdown -->
            <div id="tab-quality" class="vision-tab-content hidden">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong style="color: #10b981; font-size: 12px;">8-Point Setup Quality Filter</strong>
                            <span id="resQualityGateBadge" class="badge" style="font-size: 10px;">8/8 Passed</span>
                        </div>
                        <div id="resQualityGateList" style="display: flex; flex-direction: column; gap: 6px; font-size: 11.5px;"></div>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong style="color: var(--gold-bright); font-size: 12px;">7-Component Confidence Score</strong>
                            <span id="resConfidenceTotalLabel" style="font-size: 11px; font-family: monospace;">85/100</span>
                        </div>
                        <div id="resConfidenceCompList" style="display: flex; flex-direction: column; gap: 7px; font-size: 11.5px;"></div>
                    </div>
                </div>
            </div>

            <!-- Tab 4: Observed Facts vs AI Interpretations -->
            <div id="tab-facts" class="vision-tab-content hidden">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #38bdf8; font-size: 12px;">Observed Chart Facts (Strictly Verified)</strong>
                        <ul id="resFactsList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.55;"></ul>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #f59e0b; font-size: 12px;">AI Interpretations (Hypotheses &amp; Evidence)</strong>
                        <div id="resInterpList" style="margin-top: 6px; font-size: 12px; display: flex; flex-direction: column; gap: 8px;"></div>
                    </div>
                </div>
            </div>

            <!-- Tab 5: Strategy Thesis -->
            <div id="tab-thesis" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: #c084fc;">Detected Model:</strong> <span id="resDetectedStratTitle">—</span>
                    <ul id="resEvidenceList" style="margin-left: 18px; margin-top: 8px; font-size: 12.5px; line-height: 1.6;"></ul>
                </div>
            </div>

            <!-- Tab 6: Invalidations & Risk -->
            <div id="tab-risk" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: #ef4444;">Setup Invalidation Conditions:</strong>
                    <ul id="resInvalidationList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.6; color: var(--bear);"></ul>
                </div>
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border);">
                    <strong style="color: var(--gold-bright);">Reasons to Avoid &amp; Warnings:</strong>
                    <ul id="resAvoidList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.6;"></ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Drawer Modal with Filters & Pagination -->
<div id="historyModal" class="hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); z-index: 100; display: flex; align-items: center; justify-content: center;">
    <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: var(--radius); width: 760px; max-width: 95vw; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;">
        <div style="padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <strong style="font-size: 16px;">📁 <?= I18n::t('recent_scans') ?></strong>
            <button type="button" class="btn btn-xs btn-ghost" id="closeHistoryModalBtn">✕</button>
        </div>

        <!-- History Filter Bar -->
        <div style="padding: 10px 20px; border-bottom: 1px solid var(--border); background: var(--bg-elevated); display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;">
            <select id="histSymbolFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;">
                <option value="">All Symbols</option>
                <option value="XAUUSD">XAUUSD</option>
                <option value="BTCUSD">BTCUSD</option>
                <option value="EURUSD">EURUSD</option>
                <option value="US30">US30</option>
            </select>
            <select id="histTfFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;">
                <option value="">All Timeframes</option>
                <option value="M1">M1</option>
                <option value="M5">M5</option>
                <option value="M15">M15</option>
                <option value="H1">H1</option>
                <option value="H4">H4</option>
                <option value="D1">D1</option>
            </select>
            <select id="histStrategyFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;">
                <option value="">All Strategies</option>
                <option value="ALL">ALL</option>
                <option value="ICT">ICT</option>
                <option value="SMC">SMC</option>
                <option value="CRT">CRT</option>
                <option value="SNR">SNR</option>
            </select>
            <select id="histDirectionFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;">
                <option value="">All Signals</option>
                <option value="BUY">BUY</option>
                <option value="SELL">SELL</option>
                <option value="NO TRADE">NO TRADE</option>
            </select>
            <button type="button" class="btn btn-xs btn-primary" id="histApplyFilterBtn">Filter</button>
        </div>

        <div id="historyListContainer" style="padding: 16px 20px; overflow-y: auto; flex: 1;">
            <div class="muted">Loading past analyses...</div>
        </div>

        <!-- Pagination Bar -->
        <div style="padding: 10px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
            <span id="histPageLabel" class="muted">Page 1 of 1</span>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-xs btn-ghost" id="histPrevBtn" disabled>← Prev</button>
                <button type="button" class="btn btn-xs btn-ghost" id="histNextBtn" disabled>Next →</button>
            </div>
        </div>
    </div>
</div>

<style>
.strat-chip-btn {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    color: var(--text-main);
    padding: 8px 4px;
    border-radius: var(--radius);
    cursor: pointer;
    text-align: center;
    transition: all 0.2s ease;
}
.strat-chip-btn:hover {
    border-color: var(--border-hover);
}
.strat-chip-btn.active {
    background: rgba(245, 158, 11, 0.18);
    border-color: var(--gold);
    color: var(--gold-bright);
}
</style>

<script>
window.selectVisionStrategy = function(strat) {
    const input = document.getElementById('strategyModeInput');
    if (input) input.value = strat;
    document.querySelectorAll('.strat-chip-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.strat === strat);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    let sourceDataUrl = null;
    let processedImageBlob = null;
    let processedFileName = 'chart.png';
    let currentRotation = 0;
    let currentAnalysisResult = null;
    let isSubmitting = false;
    let histPage = 1;
    let histTotalPages = 1;

    const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB
    const MIN_IMAGE_DIM = 50;
    const MAX_CANVAS_DIM = 2048;

    const dropzone = document.getElementById('visionDropzone');
    const fileInput = document.getElementById('visionFileInput');
    const previewContainer = document.getElementById('visionPreviewContainer');
    const previewImg = document.getElementById('visionPreviewImg');
    const previewMeta = document.getElementById('visionPreviewMeta');
    const errorBox = document.getElementById('visionUploadError');
    const analyzeBtn = document.getElementById('analyzeBtn');
    const rotateBtn = document.getElementById('rotateBtn');
    const replaceBtn = document.getElementById('replaceBtn');
    const removeBtn = document.getElementById('removeBtn');

    function showUploadError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }

    function clearUploadError() {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    /**
     * Bake rotation and compression into an offscreen HTML5 Canvas so the preview image
     * and the uploaded Blob sent to AI Vision are 100% identical.
     */
    function bakeImageOnCanvas(dataUrl, origName, rotationDeg) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                const rawW = img.naturalWidth || img.width;
                const rawH = img.naturalHeight || img.height;
                if (rawW < MIN_IMAGE_DIM || rawH < MIN_IMAGE_DIM) {
                    reject(new Error(`Image dimensions (${rawW}×${rawH}px) are too small. Minimum chart size is ${MIN_IMAGE_DIM}×${MIN_IMAGE_DIM}px.`));
                    return;
                }

                const swap = (rotationDeg === 90 || rotationDeg === 270);
                let outW = swap ? rawH : rawW;
                let outH = swap ? rawW : rawH;

                if (outW > MAX_CANVAS_DIM || outH > MAX_CANVAS_DIM) {
                    const ratio = Math.min(MAX_CANVAS_DIM / outW, MAX_CANVAS_DIM / outH);
                    outW = Math.max(MIN_IMAGE_DIM, Math.round(outW * ratio));
                    outH = Math.max(MIN_IMAGE_DIM, Math.round(outH * ratio));
                }

                const canvas = document.createElement('canvas');
                canvas.width = outW;
                canvas.height = outH;
                const ctx = canvas.getContext('2d');
                ctx.save();
                ctx.translate(outW / 2, outH / 2);
                ctx.rotate((rotationDeg * Math.PI) / 180);
                const drawW = swap ? outH : outW;
                const drawH = swap ? outW : outH;
                ctx.drawImage(img, -drawW / 2, -drawH / 2, drawW, drawH);
                ctx.restore();

                const bakedUrl = canvas.toDataURL('image/png');
                canvas.toBlob((blob) => {
                    if (!blob) {
                        reject(new Error('Failed to encode chart screenshot.'));
                        return;
                    }
                    if (blob.size > MAX_UPLOAD_BYTES) {
                        reject(new Error('Processed chart image exceeds the 10MB upload limit.'));
                        return;
                    }
                    resolve({
                        dataUrl: bakedUrl,
                        blob: blob,
                        width: outW,
                        height: outH,
                        fileName: (origName || 'chart').replace(/\.[^.]+$/, '') + '.png'
                    });
                }, 'image/png');
            };
            img.onerror = () => {
                reject(new Error('Corrupted or unreadable image file. Please upload a valid PNG, JPG, or WEBP chart screenshot.'));
            };
            img.src = dataUrl;
        });
    }

    function applyBakedPreview(res) {
        processedImageBlob = res.blob;
        processedFileName = res.fileName;
        // Clear duplicate base64 input so we only upload the processed Blob once
        document.getElementById('base64Payload').value = '';
        previewImg.src = res.dataUrl;
        previewImg.style.transform = 'none';
        const kb = (res.blob.size / 1024).toFixed(1);
        previewMeta.textContent = `${res.fileName} · ${res.width}×${res.height}px · ${kb} KB` + (currentRotation ? ` · Rotated ${currentRotation}°` : '');
        dropzone.classList.add('hidden');
        previewContainer.classList.remove('hidden');
        analyzeBtn.disabled = false;
    }

    function handleFile(file) {
        clearUploadError();
        if (!file) return;
        if (!file.type || !file.type.match(/^image\/(png|jpe?g|webp)$/i)) {
            showUploadError('Unsupported image format. Only PNG, JPG, JPEG, and WEBP are allowed.');
            return;
        }
        if (file.size <= 0 || file.size > MAX_UPLOAD_BYTES) {
            showUploadError('Image file exceeds the maximum upload size of 10MB.');
            return;
        }

        currentRotation = 0;
        const reader = new FileReader();
        reader.onload = (e) => {
            sourceDataUrl = e.target.result;
            bakeImageOnCanvas(sourceDataUrl, file.name || 'chart.png', currentRotation)
                .then(applyBakedPreview)
                .catch(err => showUploadError(err.message));
        };
        reader.onerror = () => showUploadError('Failed to read image file.');
        reader.readAsDataURL(file);
    }

    // Drag & Drop
    ['dragenter', 'dragover'].forEach(ev => {
        dropzone.addEventListener(ev, (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(ev => {
        dropzone.addEventListener(ev, (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone.addEventListener('drop', (e) => {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            handleFile(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            handleFile(fileInput.files[0]);
        }
    });

    // Global Paste Listener (Ctrl+V)
    window.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
        if (!items) return;
        for (let item of items) {
            if (item.type && item.type.indexOf('image') !== -1) {
                const blob = item.getAsFile();
                if (blob) handleFile(blob);
                break;
            }
        }
    });

    rotateBtn.addEventListener('click', () => {
        if (!sourceDataUrl) return;
        currentRotation = (currentRotation + 90) % 360;
        bakeImageOnCanvas(sourceDataUrl, processedFileName, currentRotation)
            .then(applyBakedPreview)
            .catch(err => showUploadError(err.message));
    });

    replaceBtn.addEventListener('click', () => {
        fileInput.click();
    });

    removeBtn.addEventListener('click', () => {
        sourceDataUrl = null;
        processedImageBlob = null;
        fileInput.value = '';
        document.getElementById('base64Payload').value = '';
        previewImg.src = '';
        previewMeta.textContent = '';
        previewContainer.classList.add('hidden');
        dropzone.classList.remove('hidden');
        analyzeBtn.disabled = true;
        clearUploadError();
    });

    // Analyze Action (with duplicate submission guard & single-payload upload)
    analyzeBtn.addEventListener('click', async () => {
        if (isSubmitting || !processedImageBlob) return;
        clearUploadError();

        isSubmitting = true;
        analyzeBtn.disabled = true;

        const formData = new FormData(document.getElementById('visionForm'));
        formData.delete('image_base64');
        formData.set('chart_image', processedImageBlob, processedFileName);
        formData.set('enforce_min_dimensions', '1');

        const placeholder = document.getElementById('visionPlaceholder');
        const loader = document.getElementById('visionLoader');
        const resultBox = document.getElementById('visionResultBox');
        const elapsedEl = document.getElementById('visionElapsedTimer');

        placeholder.classList.add('hidden');
        resultBox.classList.add('hidden');
        loader.classList.remove('hidden');

        const startTs = performance.now();
        const timerInterval = setInterval(() => {
            const elapsed = ((performance.now() - startTs) / 1000).toFixed(1);
            if (elapsedEl) elapsedEl.textContent = `Elapsed: ${elapsed}s`;
        }, 100);

        try {
            const res = await fetch('api/vision_analyze.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: formData,
            });
            const data = await res.json();
            clearInterval(timerInterval);
            loader.classList.add('hidden');
            isSubmitting = false;
            analyzeBtn.disabled = false;

            if (!data.ok) {
                showUploadError(data.error || 'Failed to analyze chart screenshot.');
                placeholder.classList.remove('hidden');
                return;
            }

            currentAnalysisResult = data;
            renderAnalysisResult(data);
        } catch (err) {
            clearInterval(timerInterval);
            loader.classList.add('hidden');
            isSubmitting = false;
            analyzeBtn.disabled = false;
            placeholder.classList.remove('hidden');
            showUploadError('Network error while analyzing chart: ' + err.message);
        }
    });

    function renderAnalysisResult(data) {
        const report = data.report || {};
        const overlay = data.overlay || {};
        const resultBox = document.getElementById('visionResultBox');
        resultBox.classList.remove('hidden');

        // Badges
        const biasBadge = document.getElementById('resBiasBadge');
        const biasText = (report.market_bias || 'Neutral');
        biasBadge.textContent = biasText.toUpperCase();
        biasBadge.className = 'badge-bias ' + biasText.toLowerCase();

        document.getElementById('resStrategyBadge').textContent = report.detected_strategy || 'SMC/ICT Confluence';
        const dirBadge = document.getElementById('resDirectionBadge');
        const tradeDir = report.trade_direction || 'NO TRADE';
        dirBadge.textContent = tradeDir;
        dirBadge.className = 'badge ' + (tradeDir === 'BUY' ? 'badge-live' : (tradeDir === 'SELL' ? 'badge-warn' : ''));

        document.getElementById('resScoreBadge').textContent = `Confidence: ${report.confidence_score || 50}/100`;
        document.getElementById('resModelBadge').textContent = `Model: ${data.ai_model || 'deterministic'}`;
        if (document.getElementById('resStratModeBadge')) {
            document.getElementById('resStratModeBadge').textContent = `Mode: ${report.strategy_mode || document.getElementById('strategyModeInput').value || 'ALL'}`;
        }
        const psBadge = document.getElementById('resPriceSourceBadge');
        if (psBadge) {
            psBadge.textContent = report.price_source_label || 'Historical Engine Reference';
        }

        // Level Cards
        const ez = report.entry_zone || {};
        document.getElementById('resEntryVal').textContent = ez.min && ez.max
            ? `$${Number(ez.min).toFixed(2)} – $${Number(ez.max).toFixed(2)}`
            : `$${Number(ez.ref_entry || 0).toFixed(2)}`;

        document.getElementById('resSlVal').textContent = report.stop_loss ? `$${Number(report.stop_loss.price).toFixed(2)}` : '—';
        document.getElementById('resTp1Val').textContent = report.take_profit?.tp1 ? `$${Number(report.take_profit.tp1.price).toFixed(2)}` : '—';
        document.getElementById('resTp2Val').textContent = report.take_profit?.tp2 ? `$${Number(report.take_profit.tp2.price).toFixed(2)}` : '—';
        document.getElementById('resTp3Val').textContent = report.take_profit?.tp3 ? `$${Number(report.take_profit.tp3.price).toFixed(2)}` : '—';

        // Tab 1: Structure & Liquidity
        document.getElementById('resStructureVal').textContent = report.market_structure || 'Consolidation';
        document.getElementById('resStructureDesc').textContent = report.structure_details || '';
        const swSeq = document.getElementById('resSwingSeqLine');
        if (swSeq && report.swing_structure) {
            const ss = report.swing_structure;
            swSeq.textContent = `Swing Bounds: HH $${Number(ss.hh || 0).toFixed(2)} · HL $${Number(ss.hl || 0).toFixed(2)} · LH $${Number(ss.lh || 0).toFixed(2)} · LL $${Number(ss.ll || 0).toFixed(2)} · MSS: ${ss.mss_detected ? 'Confirmed' : 'No'} · Displacement: ${ss.displacement ? 'Yes' : 'No'}`;
        }

        const bslList = document.getElementById('resBslList');
        bslList.replaceChildren();
        (report.liquidity_targets?.bsl || []).forEach(l => {
            const li = document.createElement('li');
            li.textContent = `$${Number(l.level).toFixed(2)} (${l.label})`;
            bslList.appendChild(li);
        });

        const sslList = document.getElementById('resSslList');
        sslList.replaceChildren();
        (report.liquidity_targets?.ssl || []).forEach(l => {
            const li = document.createElement('li');
            li.textContent = `$${Number(l.level).toFixed(2)} (${l.label})`;
            sslList.appendChild(li);
        });

        // Tab 2: Multi-Timeframe (MTF)
        const mtf = report.mtf_analysis || {};
        const mtfBadge = document.getElementById('resMtfStatusBadge');
        if (mtfBadge) {
            const st = mtf.alignment_status || 'Neutral';
            mtfBadge.textContent = st;
            mtfBadge.className = 'badge ' + (st === 'Aligned' ? 'badge-live' : (st === 'Conflicting' ? 'badge-warn' : ''));
        }
        const mtfExp = document.getElementById('resMtfExplanation');
        if (mtfExp) mtfExp.textContent = mtf.alignment_explanation || '';

        const mtfGrid = document.getElementById('resMtfGrid');
        if (mtfGrid) {
            mtfGrid.replaceChildren();
            Object.values(mtf.timeframes || {}).forEach(tfItem => {
                const card = document.createElement('div');
                card.style.cssText = 'background: var(--bg-elevated); padding: 10px; border-radius: 6px; border: 1px solid var(--border);';
                const top = document.createElement('div');
                top.style.cssText = 'display: flex; justify-content: space-between; font-weight: 700; font-size: 12px;';
                const tfLbl = document.createElement('span');
                tfLbl.textContent = tfItem.timeframe || '';
                const bLbl = document.createElement('span');
                bLbl.style.color = tfItem.bias === 'Bullish' ? 'var(--bull)' : (tfItem.bias === 'Bearish' ? 'var(--bear)' : 'var(--text-muted)');
                bLbl.textContent = tfItem.bias || 'Ranging';
                top.appendChild(tfLbl);
                top.appendChild(bLbl);

                const sub = document.createElement('div');
                sub.className = 'muted';
                sub.style.cssText = 'font-size: 11px; margin-top: 4px;';
                sub.textContent = tfItem.structure || '';
                card.appendChild(top);
                card.appendChild(sub);
                mtfGrid.appendChild(card);
            });
        }

        // Tab 3: 8-Point Quality Gate & 7-Component Confidence Breakdown
        const qg = report.setup_quality_gate || {};
        const qgBadge = document.getElementById('resQualityGateBadge');
        if (qgBadge) {
            qgBadge.textContent = `${qg.passed_count || 0}/${qg.total_checks || 8} Passed`;
            qgBadge.className = 'badge ' + (qg.passed ? 'badge-live' : 'badge-warn');
        }
        const qgList = document.getElementById('resQualityGateList');
        if (qgList) {
            qgList.replaceChildren();
            Object.values(qg.checks || {}).forEach(chk => {
                const row = document.createElement('div');
                row.style.cssText = 'display: flex; justify-content: space-between; gap: 8px; padding: 4px 6px; border-radius: 4px; background: rgba(255,255,255,0.02);';
                const lbl = document.createElement('span');
                lbl.textContent = (chk.passed ? '✅ ' : '❌ ') + (chk.label || '');
                const st = document.createElement('span');
                st.style.color = chk.passed ? '#10b981' : '#f43f5e';
                st.style.fontFamily = 'monospace';
                st.textContent = chk.passed ? 'PASS' : 'FAIL';
                row.appendChild(lbl);
                row.appendChild(st);
                qgList.appendChild(row);
            });
        }

        const cb = report.confidence_breakdown || {};
        const cbTotal = document.getElementById('resConfidenceTotalLabel');
        if (cbTotal) cbTotal.textContent = `${report.confidence_score || 50}/100`;
        const cbList = document.getElementById('resConfidenceCompList');
        if (cbList) {
            cbList.replaceChildren();
            Object.values(cb.components || {}).forEach(comp => {
                const wrap = document.createElement('div');
                const top = document.createElement('div');
                top.style.cssText = 'display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 2px;';
                const lSpan = document.createElement('span');
                lSpan.textContent = comp.label || '';
                const vSpan = document.createElement('span');
                vSpan.style.fontFamily = 'monospace';
                vSpan.textContent = `${comp.score}/${comp.max}`;
                top.appendChild(lSpan);
                top.appendChild(vSpan);

                const barOuter = document.createElement('div');
                barOuter.style.cssText = 'height: 5px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;';
                const barInner = document.createElement('div');
                const pct = comp.max > 0 ? Math.min(100, Math.round((comp.score / comp.max) * 100)) : 0;
                barInner.style.cssText = `height: 100%; width: ${pct}%; background: var(--gold);`;
                barOuter.appendChild(barInner);

                wrap.appendChild(top);
                wrap.appendChild(barOuter);
                cbList.appendChild(wrap);
            });
        }

        // Tab 4: Observed Facts vs AI Interpretations
        const factsList = document.getElementById('resFactsList');
        factsList.replaceChildren();
        (report.observed_facts || []).forEach(f => {
            const li = document.createElement('li');
            li.textContent = f;
            factsList.appendChild(li);
        });

        const interpList = document.getElementById('resInterpList');
        interpList.replaceChildren();
        const interps = report.ai_interpretations || [];
        if (interps.length > 0) {
            interps.forEach(item => {
                const box = document.createElement('div');
                box.style.cssText = 'padding: 8px 10px; border-radius: 6px; background: rgba(255,255,255,0.03); border: 1px solid var(--border);';
                const hLine = document.createElement('div');
                hLine.style.fontWeight = '700';
                hLine.style.color = 'var(--gold-bright)';
                hLine.textContent = `${item.hypothesis || item.interpretation || ''} [${item.confidence_level || ''}]`;

                const supLine = document.createElement('div');
                supLine.style.fontSize = '11.5px';
                supLine.style.marginTop = '3px';
                supLine.textContent = '✓ Evidence: ' + (item.supporting_evidence || '');

                const oppLine = document.createElement('div');
                oppLine.style.fontSize = '11.5px';
                oppLine.style.color = '#fca5a5';
                oppLine.textContent = '⚠ Opposing Risk: ' + (item.opposing_evidence || '');

                const confLine = document.createElement('div');
                confLine.style.fontSize = '11.5px';
                confLine.style.color = '#93c5fd';
                confLine.textContent = '→ Confirmation: ' + (item.confirmation_conditions || '');

                box.appendChild(hLine);
                box.appendChild(supLine);
                box.appendChild(oppLine);
                box.appendChild(confLine);
                interpList.appendChild(box);
            });
        } else {
            (report.supporting_evidence || []).forEach(i => {
                const d = document.createElement('div');
                d.textContent = '• ' + i;
                interpList.appendChild(d);
            });
        }

        // Tab 5: Thesis
        document.getElementById('resDetectedStratTitle').textContent = report.detected_strategy || '';
        const evList = document.getElementById('resEvidenceList');
        evList.replaceChildren();
        (report.supporting_evidence || []).forEach(e => {
            const li = document.createElement('li');
            li.textContent = e;
            evList.appendChild(li);
        });

        // Tab 6: Invalidation & Risk
        const invList = document.getElementById('resInvalidationList');
        invList.replaceChildren();
        (report.setup_invalidations || []).forEach(inv => {
            const li = document.createElement('li');
            li.textContent = inv;
            invList.appendChild(li);
        });

        const avoidList = document.getElementById('resAvoidList');
        avoidList.replaceChildren();
        (report.reasons_to_avoid || []).concat(report.uncertainties_warnings || []).forEach(a => {
            const li = document.createElement('li');
            li.textContent = a;
            avoidList.appendChild(li);
        });

        // Render Canvas Overlay (prefer local baked dataUrl if active, otherwise secure image proxy URL)
        const overlayImg = document.getElementById('overlayChartImg');
        overlayImg.onload = () => {
            drawOverlay(overlay);
        };
        overlayImg.src = data.image_url || previewImg.src;
    }

    function drawOverlay(overlay) {
        const canvas = document.getElementById('overlayCanvas');
        const img = document.getElementById('overlayChartImg');
        if (!canvas || !img || !overlay) return;

        canvas.width = img.clientWidth || overlay.width || 800;
        canvas.height = img.clientHeight || overlay.height || 600;

        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const scaleY = canvas.height / (overlay.height || canvas.height);

        const showEntry = document.getElementById('toggleOverlayEntry')?.checked ?? true;
        const showSl = document.getElementById('toggleOverlaySl')?.checked ?? true;
        const showTp = document.getElementById('toggleOverlayTp')?.checked ?? true;
        const showStructure = document.getElementById('toggleOverlayStructure')?.checked ?? true;
        const showFvgOb = document.getElementById('toggleOverlayFvgOb')?.checked ?? true;
        const showLiquidity = document.getElementById('toggleOverlayLiquidity')?.checked ?? true;

        (overlay.elements || []).forEach(el => {
            const cat = el.category || (el.type === 'zone' ? 'entry' : (String(el.label).includes('STOP LOSS') ? 'sl' : 'tp'));
            if (cat === 'entry' && !showEntry) return;
            if (cat === 'sl' && !showSl) return;
            if (cat === 'tp' && !showTp) return;
            if (cat === 'structure' && !showStructure) return;
            if (cat === 'fvg_ob' && !showFvgOb) return;
            if (cat === 'liquidity' && !showLiquidity) return;

            if (el.type === 'zone') {
                const y1 = Math.max(4, Math.min(canvas.height - 12, el.y1 * scaleY));
                const y2 = Math.max(12, Math.min(canvas.height - 4, el.y2 * scaleY));
                const top = Math.min(y1, y2);
                const h = Math.max(10, Math.abs(y2 - y1));

                ctx.fillStyle = el.color || 'rgba(0, 229, 255, 0.22)';
                ctx.fillRect(0, top, canvas.width, h);
                ctx.strokeStyle = el.stroke || '#00e5ff';
                ctx.lineWidth = 1.5;
                ctx.strokeRect(0, top, canvas.width, h);

                ctx.font = 'bold 11px JetBrains Mono, monospace';
                const labelTxt = String(el.label || '');
                const txtW = Math.min(canvas.width - 20, ctx.measureText(labelTxt).width + 14);
                ctx.fillStyle = 'rgba(15, 23, 42, 0.88)';
                ctx.fillRect(10, Math.max(2, top - 18), txtW, 18);
                ctx.fillStyle = el.stroke || '#00e5ff';
                ctx.fillText(labelTxt, 16, Math.max(14, top - 5));
            }
            if (el.type === 'line') {
                const y = Math.max(14, Math.min(canvas.height - 10, el.y * scaleY));
                ctx.strokeStyle = el.color || '#00ff88';
                ctx.lineWidth = 2;
                if (el.dash) ctx.setLineDash(el.dash); else ctx.setLineDash([]);
                ctx.beginPath();
                ctx.moveTo(0, y);
                ctx.lineTo(canvas.width, y);
                ctx.stroke();
                ctx.setLineDash([]);

                ctx.font = 'bold 11px JetBrains Mono, monospace';
                const labelTxt = String(el.label || '');
                const txtW = Math.min(canvas.width - 20, ctx.measureText(labelTxt).width + 16);
                const boxX = Math.max(8, canvas.width - txtW - 10);
                ctx.fillStyle = 'rgba(15, 23, 42, 0.88)';
                ctx.fillRect(boxX, y - 10, txtW, 20);
                ctx.strokeStyle = el.color || '#00ff88';
                ctx.lineWidth = 1;
                ctx.strokeRect(boxX, y - 10, txtW, 20);
                ctx.fillStyle = el.color || '#00ff88';
                ctx.fillText(labelTxt, boxX + 7, y + 4);
            }
        });
    }

    ['toggleOverlayEntry', 'toggleOverlaySl', 'toggleOverlayTp', 'toggleOverlayStructure', 'toggleOverlayFvgOb', 'toggleOverlayLiquidity'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => {
            if (currentAnalysisResult && currentAnalysisResult.overlay) {
                drawOverlay(currentAnalysisResult.overlay);
            }
        });
    });

    window.addEventListener('resize', () => {
        if (currentAnalysisResult && currentAnalysisResult.overlay) {
            drawOverlay(currentAnalysisResult.overlay);
        }
    });
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => {
            if (currentAnalysisResult && currentAnalysisResult.overlay) {
                drawOverlay(currentAnalysisResult.overlay);
            }
        });
        const wrapper = document.getElementById('canvasWrapper');
        if (wrapper) ro.observe(wrapper);
    }

    // Tab buttons
    document.querySelectorAll('.vision-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.vision-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const targetId = btn.dataset.tab;
            document.querySelectorAll('.vision-tab-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(targetId)?.classList.remove('hidden');
        });
    });

    // History Modal with Pagination, Filters, and 100% XSS-Safe DOM Construction
    const historyModal = document.getElementById('historyModal');
    const histPrevBtn = document.getElementById('histPrevBtn');
    const histNextBtn = document.getElementById('histNextBtn');
    const histPageLabel = document.getElementById('histPageLabel');

    async function loadHistory(page = 1) {
        histPage = Math.max(1, page);
        const container = document.getElementById('historyListContainer');
        container.replaceChildren();
        const loading = document.createElement('div');
        loading.className = 'muted';
        loading.textContent = 'Loading past scans...';
        container.appendChild(loading);

        const q = new URLSearchParams({
            page: String(histPage),
            per_page: '10',
            symbol: document.getElementById('histSymbolFilter')?.value || '',
            timeframe: document.getElementById('histTfFilter')?.value || '',
            strategy: document.getElementById('histStrategyFilter')?.value || '',
            direction: document.getElementById('histDirectionFilter')?.value || ''
        });

        try {
            const res = await fetch('api/vision_history.php?' + q.toString());
            const data = await res.json();
            container.replaceChildren();

            const pag = data.pagination || { page: 1, total_pages: 1, total: (data.items || []).length };
            histPage = pag.page || 1;
            histTotalPages = pag.total_pages || 1;
            histPageLabel.textContent = `Page ${histPage} of ${histTotalPages} (${pag.total || 0} records)`;
            histPrevBtn.disabled = (histPage <= 1);
            histNextBtn.disabled = (histPage >= histTotalPages);

            if (!data.ok || !data.items || data.items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'muted';
                empty.textContent = 'No past chart scans match your filter criteria.';
                container.appendChild(empty);
                return;
            }

            data.items.forEach(item => {
                const div = document.createElement('div');
                div.style.cssText = 'background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 6px; padding: 12px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer;';

                const leftGroup = document.createElement('div');
                leftGroup.style.cssText = 'display: flex; align-items: center; gap: 12px;';

                const thumb = document.createElement('img');
                thumb.src = item.image_url || '';
                thumb.alt = 'Chart Thumbnail';
                thumb.style.cssText = 'width: 52px; height: 52px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border);';
                thumb.onerror = function() { this.style.display = 'none'; };

                const infoBox = document.createElement('div');
                const titleLine = document.createElement('div');
                const symStrong = document.createElement('strong');
                symStrong.textContent = `${item.symbol} (${item.timeframe}) `;
                const biasBadge = document.createElement('span');
                biasBadge.className = 'badge ' + (item.market_bias === 'Bullish' ? 'badge-live' : (item.market_bias === 'Bearish' ? 'badge-warn' : ''));
                biasBadge.textContent = item.market_bias || 'Neutral';

                const dirSpan = document.createElement('span');
                dirSpan.className = 'badge';
                dirSpan.style.marginLeft = '6px';
                dirSpan.textContent = item.trade_direction || (item.report?.trade_direction || 'NO TRADE');

                titleLine.appendChild(symStrong);
                titleLine.appendChild(biasBadge);
                titleLine.appendChild(dirSpan);

                const subLine = document.createElement('div');
                subLine.className = 'muted';
                subLine.style.cssText = 'font-size: 11.5px; margin-top: 3px;';
                subLine.textContent = `${item.detected_strategy || 'SMC/ICT'} · Score: ${item.confidence_score}/100 · Model: ${item.ai_model || 'deterministic'} · ${item.created_at} UTC`;

                infoBox.appendChild(titleLine);
                infoBox.appendChild(subLine);
                leftGroup.appendChild(thumb);
                leftGroup.appendChild(infoBox);

                const actionGroup = document.createElement('div');
                actionGroup.style.cssText = 'display: flex; gap: 8px; flex-shrink: 0;';

                const loadBtn = document.createElement('button');
                loadBtn.type = 'button';
                loadBtn.className = 'btn btn-xs btn-primary';
                loadBtn.textContent = 'Load Analysis';
                loadBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    historyModal.classList.add('hidden');
                    currentAnalysisResult = {
                        analysis_id: item.id,
                        image_url: item.image_url,
                        report: item.report,
                        overlay: item.overlay,
                        ai_model: item.ai_model
                    };
                    renderAnalysisResult(currentAnalysisResult);
                });

                const tgBtn = document.createElement('button');
                tgBtn.type = 'button';
                tgBtn.className = 'btn btn-xs';
                tgBtn.style.cssText = 'background: #229ed9; color: #fff; border: none; font-weight: 600;';
                tgBtn.title = 'Send this chart to Telegram';
                tgBtn.textContent = '✈️ Telegram';
                tgBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const origText = tgBtn.textContent;
                    tgBtn.disabled = true;
                    tgBtn.textContent = '⏳...';
                    try {
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const res = await fetch('api/vision_telegram.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ analysis_id: item.id, _csrf: csrf })
                        });
                        const resData = await res.json();
                        if (resData.ok) {
                            tgBtn.style.background = '#10b981';
                            tgBtn.textContent = '✅ Sent';
                            setTimeout(() => { tgBtn.textContent = origText; tgBtn.style.background = '#229ed9'; tgBtn.disabled = false; }, 3000);
                        } else {
                            alert('Telegram Error: ' + (resData.error || 'Failed to send to Telegram.'));
                            tgBtn.textContent = origText;
                            tgBtn.disabled = false;
                        }
                    } catch (err) {
                        alert('Network error: ' + err.message);
                        tgBtn.textContent = origText;
                        tgBtn.disabled = false;
                    }
                });

                actionGroup.appendChild(loadBtn);
                actionGroup.appendChild(tgBtn);

                div.appendChild(leftGroup);
                div.appendChild(actionGroup);
                container.appendChild(div);
            });
        } catch (err) {
            container.replaceChildren();
            const errDiv = document.createElement('div');
            errDiv.className = 'text-bear';
            errDiv.textContent = 'Failed to load history: ' + err.message;
            container.appendChild(errDiv);
        }
    }

    document.getElementById('openHistoryBtn').addEventListener('click', () => {
        historyModal.classList.remove('hidden');
        loadHistory(1);
    });
    document.getElementById('histApplyFilterBtn')?.addEventListener('click', () => loadHistory(1));
    histPrevBtn?.addEventListener('click', () => loadHistory(histPage - 1));
    histNextBtn?.addEventListener('click', () => loadHistory(histPage + 1));
    document.getElementById('closeHistoryModalBtn').addEventListener('click', () => {
        historyModal.classList.add('hidden');
    });

    // Send Active Analysis to Telegram Bot
    const sendTelegramBtn = document.getElementById('sendTelegramBtn');
    if (sendTelegramBtn) {
        sendTelegramBtn.addEventListener('click', async () => {
            if (!currentAnalysisResult || !currentAnalysisResult.analysis_id) {
                alert('No active chart analysis available to send. Please analyze a chart first.');
                return;
            }

            const sendTelegramText = document.getElementById('sendTelegramText');
            const origLabel = sendTelegramText ? sendTelegramText.textContent : 'Send to Telegram';
            sendTelegramBtn.disabled = true;
            sendTelegramBtn.style.opacity = '0.7';
            if (sendTelegramText) sendTelegramText.textContent = 'Sending...';

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('api/vision_telegram.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        analysis_id: currentAnalysisResult.analysis_id,
                        _csrf: csrf
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    sendTelegramBtn.style.background = '#10b981';
                    sendTelegramBtn.style.borderColor = '#10b981';
                    if (sendTelegramText) sendTelegramText.textContent = data.deduplicated ? 'Already Sent!' : 'Sent to Telegram!';
                    setTimeout(() => {
                        if (sendTelegramText) sendTelegramText.textContent = origLabel;
                        sendTelegramBtn.style.background = '#229ed9';
                        sendTelegramBtn.style.borderColor = '#229ed9';
                        sendTelegramBtn.disabled = false;
                        sendTelegramBtn.style.opacity = '1';
                    }, 3500);
                } else {
                    alert('Telegram Send Error: ' + (data.error || 'Failed to dispatch telegram notification.'));
                    if (sendTelegramText) sendTelegramText.textContent = origLabel;
                    sendTelegramBtn.disabled = false;
                    sendTelegramBtn.style.opacity = '1';
                }
            } catch (err) {
                alert('Network error sending to Telegram: ' + err.message);
                if (sendTelegramText) sendTelegramText.textContent = origLabel;
                sendTelegramBtn.disabled = false;
                sendTelegramBtn.style.opacity = '1';
            }
        });
    }
});
</script>

<?php
renderFooter();
