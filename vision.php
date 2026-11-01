<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Analysis\ChartVisionService;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;

$layout = renderHeader('AI Chart Vision Analyzer V3', 'vision');
$market = new MarketDataService(null, 'XAUUSD');
$quote = $market->quote(false);
$providerName = (string)($quote['source'] ?? $market->provider()->label());
$liveAvailable = !empty($quote['available'])
    && (($quote['mode'] ?? '') === 'live')
    && empty($quote['stale'])
    && !empty($quote['price'])
    && (float)$quote['price'] > 0;
$livePrice = $liveAvailable ? (float)$quote['price'] : null;
$liveUpdatedTs = !empty($quote['fetched_at']) ? gmdate('Y-m-d H:i:s', (int)$quote['fetched_at']) : null;

$recentCandles = $market->candles('M15', 5);
$lastCandle = !empty($recentCandles) ? $recentCandles[count($recentCandles) - 1] : null;
$historicalClose = ($lastCandle !== null && (float)$lastCandle['c'] > 0) ? (float)$lastCandle['c'] : null;
$historicalTs = ($lastCandle !== null && !empty($lastCandle['t'])) ? gmdate('Y-m-d H:i:s', (int)$lastCandle['t']) : null;

$refDisplayPrice = $livePrice ?? $historicalClose;
$priceModeBadge = $liveAvailable
    ? sprintf('● LIVE: $%s (%s · %s UTC)', number_format((float)$livePrice, 2), $providerName, $liveUpdatedTs ?? 'now')
    : ($historicalClose !== null
        ? sprintf('◐ HISTORICAL: $%s (%s · %s UTC)', number_format($historicalClose, 2), $providerName, $historicalTs ?? 'recent')
        : '○ PRICE UNAVAILABLE (Enter Manual Reference Price)');

$csrf = Csrf::token();
$symbolSpecsJson = json_encode(ChartVisionService::SYMBOL_SPECS, JSON_UNESCAPED_SLASHES);
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>📷</span>
            <span><?= I18n::t('nav_vision') ?> — Institutional SMC / ICT / CRT / S&amp;R Engine V3</span>
        </div>
        <div class="vision-hero-sub">
            Upload or paste any chart screenshot (TradingView, MT4/MT5, cTrader) with optional Higher-Timeframe (HTF) confirmation. Enforces strict mathematical validation (2R / 3.5R / 5R), 8-point quality gating, and 10-category high-DPI chart overlays.
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <span class="badge <?= $liveAvailable ? 'badge-live' : 'badge-warn' ?>" id="headerPriceStatusBadge" title="Truthful reference price source and timestamp">
            <?= Validator::e($priceModeBadge) ?>
        </span>
        <button type="button" class="btn btn-sm btn-ghost" id="openHistoryBtn" aria-haspopup="dialog" aria-controls="historyModal">
            📁 <?= I18n::t('recent_scans') ?>
        </button>
    </div>
</div>

<div class="vision-grid">
    <!-- Left Column: Image Upload, HTF Confirmation & Setup Calibration -->
    <div class="vision-upload-card">
        <h3 style="font-size: 15px; margin-bottom: 12px; font-weight: 700;">1. Execution Chart Screenshot Input</h3>

        <!-- Inline Error Alert -->
        <div id="visionUploadError" role="alert" aria-live="assertive" class="hidden" style="background: rgba(239, 68, 68, 0.14); border: 1px solid #ef4444; color: #fca5a5; padding: 10px 12px; border-radius: 6px; font-size: 12px; margin-bottom: 12px;"></div>

        <!-- Primary Drag & Drop Zone -->
        <div class="vision-dropzone" id="visionDropzone" tabindex="0" role="button" aria-label="Upload or drop execution chart screenshot">
            <span class="vision-dropzone-icon">📥</span>
            <div class="vision-dropzone-text"><?= I18n::t('upload_prompt') ?></div>
            <div class="vision-dropzone-hint">Supports PNG, JPG, JPEG, WEBP (Min 50×50px · Max 10MB · EXIF Auto-Oriented)</div>
            <button type="button" class="btn btn-sm btn-primary" style="margin-top: 12px;" id="triggerMainFileBtn">
                <?= I18n::t('upload_btn') ?>
            </button>
            <input type="file" id="visionFileInput" accept="image/png,image/jpeg,image/jpg,image/webp" style="display: none;">
        </div>

        <!-- Primary Preview Box (displays exact EXIF-corrected & rotated canvas output) -->
        <div class="vision-preview-container hidden" id="visionPreviewContainer">
            <img id="visionPreviewImg" class="vision-preview-img" alt="Uploaded Execution Chart Preview">
            <div id="visionPreviewMeta" class="muted" style="font-size: 11px; text-align: center; margin-top: 6px;"></div>
            <div class="vision-preview-controls">
                <button type="button" class="btn btn-xs btn-ghost" id="rotateBtn" title="Bake 90° clockwise rotation into execution chart">🔄 Rotate 90°</button>
                <button type="button" class="btn btn-xs btn-ghost" id="replaceBtn" title="Choose another execution chart">🔁 Replace</button>
                <button type="button" class="btn btn-xs btn-danger" id="removeBtn" title="Remove execution chart">✕ Remove</button>
            </div>
        </div>

        <h3 style="font-size: 15px; margin: 16px 0 12px; font-weight: 700;">2. Strategy, Symbol &amp; Multi-Timeframe Calibration</h3>

        <form id="visionForm" onsubmit="return false;" novalidate>
            <input type="hidden" name="_csrf" value="<?= Validator::e($csrf) ?>">

            <!-- Strategy Selector (ICT, SMC, CRT, SNR, ALL) -->
            <div style="margin-bottom: 14px;">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Trading Strategy Lens</span>
                    <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: var(--gold-bright); font-size: 10px;">5 Institutional Models</span>
                </label>
                <div class="strategy-selector-chips" role="radiogroup" aria-label="Select trading strategy" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-top: 4px;">
                    <button type="button" class="strat-chip-btn active" data-strat="ALL" onclick="selectVisionStrategy('ALL')">
                        <span style="display:block;font-size:12px;font-weight:750;">🌐 ALL</span>
                        <span style="font-size:9px;opacity:0.75;">Confluence</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="ICT" onclick="selectVisionStrategy('ICT')">
                        <span style="display:block;font-size:12px;font-weight:750;">⚡ ICT</span>
                        <span style="font-size:9px;opacity:0.75;">FVG / OTE</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="SMC" onclick="selectVisionStrategy('SMC')">
                        <span style="display:block;font-size:12px;font-weight:750;">🏛️ SMC</span>
                        <span style="font-size:9px;opacity:0.75;">BOS / OB</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="CRT" onclick="selectVisionStrategy('CRT')">
                        <span style="display:block;font-size:12px;font-weight:750;">🕯️ CRT</span>
                        <span style="font-size:9px;opacity:0.75;">Range Sweep</span>
                    </button>
                    <button type="button" class="strat-chip-btn" data-strat="SNR" onclick="selectVisionStrategy('SNR')">
                        <span style="display:block;font-size:12px;font-weight:750;">🧱 SNR</span>
                        <span style="font-size:9px;opacity:0.75;">S&amp;R Levels</span>
                    </button>
                </div>
                <input type="hidden" id="strategyModeInput" name="strategy_mode" value="ALL">
            </div>

            <div class="vision-form-row">
                <div>
                    <label class="form-label" for="symbolSelect">Symbol / Asset</label>
                    <select id="symbolSelect" name="symbol" class="form-select">
                        <option value="XAUUSD" selected>XAU/USD (Spot Gold · 0.01)</option>
                        <option value="BTCUSD">BTC/USD (Bitcoin · 0.50)</option>
                        <option value="EURUSD">EUR/USD (Euro · 0.00001)</option>
                        <option value="GBPUSD">GBP/USD (Pound · 0.00001)</option>
                        <option value="US30">US30 (Dow Jones · 1.0)</option>
                        <option value="NAS100">NAS100 (Nasdaq · 0.25)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="timeframeSelect">Execution Timeframe</label>
                    <select id="timeframeSelect" name="timeframe" class="form-select">
                        <option value="M1">M1 (Precision Execution)</option>
                        <option value="M5">M5 (Entry Confirmation)</option>
                        <option value="M15" selected>M15 (Setup Confirmation)</option>
                        <option value="M30">M30 (Session Structure)</option>
                        <option value="H1">H1 (Trading Bias)</option>
                        <option value="H4">H4 (Major Structure)</option>
                        <option value="D1">D1 (Overall Direction)</option>
                    </select>
                </div>
            </div>

            <!-- Dedicated Higher-Timeframe (HTF) Workflow -->
            <div style="background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <label class="form-label" for="htfSelect" style="margin: 0; font-weight: 700; color: #38bdf8;">
                        Higher-Timeframe (HTF) Confirmation
                    </label>
                    <span class="muted" style="font-size: 10.5px;">Never conflated with Execution Chart</span>
                </div>
                <div class="vision-form-row" style="margin-bottom: 6px;">
                    <div>
                        <select id="htfSelect" name="htf_timeframe" class="form-select" style="font-size: 12px;">
                            <option value="" selected>Auto HTF Partner (Recommended)</option>
                            <option value="D1">D1 (Daily Direction)</option>
                            <option value="H4">H4 (Major Swing Structure)</option>
                            <option value="H1">H1 (Intraday Bias)</option>
                            <option value="M30">M30 (Session Structure)</option>
                            <option value="M15">M15 (Setup Confirmation)</option>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="btn btn-xs btn-ghost" id="triggerHtfFileBtn" style="width: 100%; padding: 7px 8px;">
                            📎 Attach HTF Chart (Optional)
                        </button>
                        <input type="file" id="htfFileInput" accept="image/png,image/jpeg,image/jpg,image/webp" style="display: none;">
                    </div>
                </div>

                <!-- HTF Preview Container -->
                <div id="htfPreviewContainer" class="hidden" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--border);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <img id="htfPreviewImg" src="" alt="HTF Chart Preview" style="width: 64px; height: 48px; object-fit: cover; border-radius: 4px; border: 1px solid #38bdf8;">
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 11px; font-weight: 700; color: #38bdf8;">HTF Confirmation Screenshot Ready</div>
                            <div id="htfPreviewMeta" class="muted" style="font-size: 10.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></div>
                        </div>
                        <div style="display: flex; gap: 4px;">
                            <button type="button" class="btn btn-xs btn-ghost" id="htfRotateBtn" title="Rotate HTF Screenshot 90°">🔄</button>
                            <button type="button" class="btn btn-xs btn-danger" id="htfRemoveBtn" title="Remove HTF Screenshot">✕</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="vision-form-row">
                <div>
                    <label class="form-label" for="manualPriceInput" id="manualPriceLabel">
                        Reference Price (Optional Override)
                    </label>
                    <input type="number" step="0.01" id="manualPriceInput" name="manual_price" class="form-input"
                           placeholder="<?= $refDisplayPrice !== null ? 'e.g. ' . number_format($refDisplayPrice, 2, '.', '') : 'Enter current chart price' ?>" value="">
                    <div id="priceBoundsHint" class="muted" style="font-size: 10px; margin-top: 3px;">Valid XAUUSD range: $400.00 – $15,000.00</div>
                </div>
                <div>
                    <label class="form-label" for="brokerInput">Broker / Data Source</label>
                    <input type="text" id="brokerInput" name="broker" class="form-input" placeholder="TradingView / OANDA / Exness">
                </div>
            </div>

            <div style="display: flex; gap: 8px; margin-top: 6px;">
                <button type="button" class="btn btn-primary" id="analyzeBtn" style="flex: 1; padding: 12px; font-weight: 800; font-size: 14px;" disabled>
                    🚀 <?= I18n::t('analyze_chart') ?>
                </button>
                <button type="button" class="btn btn-danger hidden" id="cancelAnalyzeBtn" style="padding: 12px 16px; font-weight: 700; font-size: 13px;">
                    ✕ Cancel
                </button>
            </div>
        </form>

        <!-- Institutional Safety Notice -->
        <div style="margin-top: 16px; font-size: 11.5px; color: var(--text-muted); line-height: 1.5; border-top: 1px solid var(--border); padding-top: 12px;">
            🛡️ <strong>Strict V3 Validation Engine:</strong> Enforces <code>SL &lt; Entry &lt; TP1 (2R) &lt; TP2 (3.5R) &lt; TP3 (5R)</code> for BUY and <code>SL &gt; Entry &gt; TP1 &gt; TP2 &gt; TP3</code> for SELL. Returns <code>NO TRADE</code> whenever structure, MTF alignment, or quality checks fail.
        </div>
    </div>

    <!-- Right Column: Results, 10-Category Visual Overlay & Institutional Report -->
    <div>
        <!-- Placeholder before analysis -->
        <div id="visionPlaceholder" class="vision-report-box" style="text-align: center; padding: 60px 20px;">
            <span style="font-size: 48px; display: block; margin-bottom: 12px;">📈</span>
            <h3 style="font-size: 18px; font-weight: 800; margin-bottom: 6px;">Not Analyzed — Upload or Paste a Chart Screenshot</h3>
            <p class="muted" style="max-width: 500px; margin: 0 auto;">
                Drop a chart image or press <kbd style="background: var(--bg-elevated); padding: 2px 6px; border-radius: 4px;">Ctrl+V</kbd>. The V3 engine will verify image integrity, inspect ICT/SMC/CRT/S&amp;R structure, validate multi-timeframe alignment, and render high-DPI overlays.
            </p>
        </div>

        <!-- Honest Scanning State Loader with Upload Progress & Real Elapsed Timer -->
        <div id="visionLoader" class="vision-report-box hidden" style="text-align: center; padding: 50px 20px;">
            <div class="spinner" style="width: 40px; height: 40px; border: 4px solid rgba(245, 158, 11, 0.2); border-top-color: var(--gold); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 16px;"></div>
            <h3 style="font-size: 17px; font-weight: 800; color: var(--gold-bright);">Analyzing Chart &amp; Validating Structure...</h3>
            <p class="muted" id="visionScanStep" style="font-size: 13px; margin-top: 6px;">Uploading processed image and validating mathematical setup...</p>
            <div style="max-width: 320px; margin: 12px auto 8px; height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;">
                <div id="visionUploadProgressBar" style="height: 100%; width: 0%; background: var(--gold); transition: width 0.15s ease;"></div>
            </div>
            <div id="visionElapsedTimer" class="muted" style="font-size: 12px; font-family: monospace; margin-top: 6px;">Elapsed: 0.0s</div>
        </div>

        <!-- Result Box (Zero Fake Pre-Analysis Values: defaults to "—" / "Not analyzed") -->
        <div id="visionResultBox" class="vision-report-box hidden">
            <!-- 10-Category Visual Chart Overlay Controls -->
            <div style="margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                    <h4 style="font-size: 14px; font-weight: 800; margin: 0;">Interactive High-DPI Chart Overlay (10 Categories)</h4>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-xs btn-ghost" id="overlayShowAllBtn" style="font-size: 10px; padding: 2px 6px;">Show All</button>
                        <button type="button" class="btn btn-xs btn-ghost" id="overlayHideAllBtn" style="font-size: 10px; padding: 2px 6px;">Hide All</button>
                    </div>
                </div>
                <div id="overlayCategoryToggles" style="display: flex; gap: 10px; flex-wrap: wrap; font-size: 11px; background: var(--bg-elevated); padding: 8px 10px; border-radius: 6px; border: 1px solid var(--border);">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #00e5ff;">
                        <input type="checkbox" data-ov-cat="entry" id="toggleOverlayEntry" checked> 1. Entry Zone
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #ff3366;">
                        <input type="checkbox" data-ov-cat="sl" id="toggleOverlaySl" checked> 2. Stop Loss
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #00ff88;">
                        <input type="checkbox" data-ov-cat="tp" id="toggleOverlayTp" checked> 3. TP1–TP3
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #eab308;">
                        <input type="checkbox" data-ov-cat="structure" id="toggleOverlayStructure" checked> 4. BOS/CHoCH/MSS
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #a855f7;">
                        <input type="checkbox" data-ov-cat="ob" id="toggleOverlayOb" checked> 5. Order Blocks
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #f59e0b;">
                        <input type="checkbox" data-ov-cat="fvg" id="toggleOverlayFvg" checked> 6. FVG / IFVG
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #38bdf8;">
                        <input type="checkbox" data-ov-cat="bsl" id="toggleOverlayBsl" checked> 7. BSL
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #0ea5e9;">
                        <input type="checkbox" data-ov-cat="ssl" id="toggleOverlaySsl" checked> 8. SSL
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #fb7185;">
                        <input type="checkbox" data-ov-cat="eq_hl" id="toggleOverlayEqHl" checked> 9. Equal H/L
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px; color: #94a3b8;">
                        <input type="checkbox" data-ov-cat="swings" id="toggleOverlaySwings" checked> 10. Swing H/L
                    </label>
                </div>
            </div>

            <div class="vision-canvas-wrapper" id="canvasWrapper" style="position: relative; overflow: hidden;">
                <img id="overlayChartImg" src="" alt="Analyzed Chart" style="width: 100%; height: auto; display: block;">
                <canvas id="overlayCanvas" class="vision-overlay-canvas" style="position: absolute; top: 0; left: 0; pointer-events: none;"></canvas>
            </div>

            <!-- Badges Row & Send to Telegram Action (Placeholders before analysis) -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin: 12px 0 10px;">
                <div class="vision-badge-row" style="margin: 0; flex: 1;">
                    <span class="badge-bias" id="resBiasBadge">Not analyzed</span>
                    <span class="badge" style="font-weight: 800;" id="resDirectionBadge">—</span>
                    <span class="badge-strat" id="resStrategyBadge">—</span>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid #38bdf8;" id="resStratModeBadge">Mode: —</span>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981;" id="resScoreBadge" title="Analytical Evidence Score (0–100), NOT Win Rate">Evidence Score: —</span>
                    <span class="badge" id="resPriceSourceBadge" style="background: var(--bg-elevated); border: 1px solid var(--border);">Price Source: —</span>
                    <span class="badge" id="resModelBadge">Model: —</span>
                </div>
                <button type="button" class="btn btn-sm" id="sendTelegramBtn" style="background: #229ed9; color: #fff; border: 1px solid #229ed9; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.87 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.46c.538-.196 1.006.128.831.92z"/></svg>
                    <span id="sendTelegramText"><?= I18n::t('send_to_telegram') ?></span>
                </button>
            </div>

            <!-- Key Levels Cards Grid (Initialized to "—") -->
            <div class="levels-card-grid">
                <div class="level-card" style="border-top: 3px solid #00e5ff;">
                    <div class="level-card-label"><?= I18n::t('entry_zone') ?></div>
                    <div class="level-card-val text-gold" id="resEntryVal">—</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #ff3366;">
                    <div class="level-card-label"><?= I18n::t('stop_loss') ?></div>
                    <div class="level-card-val text-bear" id="resSlVal">—</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #00ff88;">
                    <div class="level-card-label"><?= I18n::t('tp1') ?> (2.0R)</div>
                    <div class="level-card-val text-bull" id="resTp1Val">—</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #00cc66;">
                    <div class="level-card-label"><?= I18n::t('tp2') ?> (3.5R)</div>
                    <div class="level-card-val text-bull" id="resTp2Val">—</div>
                </div>
                <div class="level-card" style="border-top: 3px solid #00994d;">
                    <div class="level-card-label"><?= I18n::t('tp3') ?> (5.0R)</div>
                    <div class="level-card-val text-bull" id="resTp3Val">—</div>
                </div>
            </div>

            <!-- Report Tabs (Accessible Tablist) -->
            <div class="vision-tabs" role="tablist" aria-label="Analysis Report Sections" style="flex-wrap: wrap;">
                <button type="button" role="tab" aria-selected="true" class="vision-tab-btn active" data-tab="tab-structure">Structure &amp; Liquidity</button>
                <button type="button" role="tab" aria-selected="false" class="vision-tab-btn" data-tab="tab-mtf">Multi-Timeframe (MTF)</button>
                <button type="button" role="tab" aria-selected="false" class="vision-tab-btn" data-tab="tab-quality">Quality Gate &amp; Confidence</button>
                <button type="button" role="tab" aria-selected="false" class="vision-tab-btn" data-tab="tab-facts">Facts, Hypotheses &amp; Missing Info</button>
                <button type="button" role="tab" aria-selected="false" class="vision-tab-btn" data-tab="tab-thesis">Strategy Thesis</button>
                <button type="button" role="tab" aria-selected="false" class="vision-tab-btn" data-tab="tab-risk">Invalidations &amp; Risk</button>
            </div>

            <!-- Tab 1: Structure & Liquidity -->
            <div id="tab-structure" role="tabpanel" class="vision-tab-content">
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
            <div id="tab-mtf" role="tabpanel" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <strong style="color: #38bdf8;">Multi-Timeframe Alignment Status:</strong>
                        <span id="resMtfStatusBadge" class="badge">—</span>
                    </div>
                    <p id="resMtfExplanation" class="muted" style="font-size: 12.5px; margin-top: 6px;"></p>
                </div>
                <div id="resMtfGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px;"></div>
            </div>

            <!-- Tab 3: 8-Point Setup Quality Gate & 7-Component Confidence Breakdown -->
            <div id="tab-quality" role="tabpanel" class="vision-tab-content hidden">
                <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); padding: 8px 12px; border-radius: 6px; font-size: 11.5px; color: var(--gold-bright); margin-bottom: 12px;" id="resConfidenceDisclaimer">
                    ℹ️ Analytical confidence score (0–100) measures structural and multi-timeframe evidence quality — NOT a percentage probability of winning.
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong style="color: #10b981; font-size: 12px;">8-Point Setup Quality Filter</strong>
                            <span id="resQualityGateBadge" class="badge" style="font-size: 10px;">—</span>
                        </div>
                        <div id="resQualityGateList" style="display: flex; flex-direction: column; gap: 6px; font-size: 11.5px;"></div>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong style="color: var(--gold-bright); font-size: 12px;">7-Component Evidence Score</strong>
                            <span id="resConfidenceTotalLabel" style="font-size: 11px; font-family: monospace;">—</span>
                        </div>
                        <div id="resConfidenceCompList" style="display: flex; flex-direction: column; gap: 7px; font-size: 11.5px;"></div>
                    </div>
                </div>
            </div>

            <!-- Tab 4: 5-Part Epistemic Separation (Facts, Interpretations, Hypotheses, Missing Info, Confirmation) -->
            <div id="tab-facts" role="tabpanel" class="vision-tab-content hidden">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #38bdf8; font-size: 12px;">1. Directly Observed Chart Facts</strong>
                        <ul id="resFactsList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.55;"></ul>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #f59e0b; font-size: 12px;">2. AI Interpretations &amp; Evidence</strong>
                        <div id="resInterpList" style="margin-top: 6px; font-size: 12px; display: flex; flex-direction: column; gap: 8px;"></div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #fca5a5; font-size: 12px;">3. Missing Information &amp; Uncertainties</strong>
                        <ul id="resMissingInfoList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.55;"></ul>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #10b981; font-size: 12px;">4. Required Confirmation Conditions</strong>
                        <ul id="resConfirmationList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.55;"></ul>
                    </div>
                </div>
            </div>

            <!-- Tab 5: Strategy Thesis -->
            <div id="tab-thesis" role="tabpanel" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: #c084fc;">Detected Model:</strong> <span id="resDetectedStratTitle">—</span>
                    <ul id="resEvidenceList" style="margin-left: 18px; margin-top: 8px; font-size: 12.5px; line-height: 1.6;"></ul>
                </div>
            </div>

            <!-- Tab 6: Invalidations & Risk -->
            <div id="tab-risk" role="tabpanel" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: #ef4444;">Setup Invalidation Conditions:</strong>
                    <ul id="resInvalidationList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.6; color: var(--bear);"></ul>
                </div>
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border);">
                    <strong style="color: var(--gold-bright);">Reasons to Avoid &amp; Risk Warnings:</strong>
                    <ul id="resAvoidList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.6;"></ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Accessible History Modal with Filters, Pagination, Load, Telegram & Delete -->
<div id="historyModal" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle" class="hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); z-index: 100; display: flex; align-items: center; justify-content: center;">
    <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: var(--radius); width: 820px; max-width: 95vw; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;">
        <div style="padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <strong id="historyModalTitle" style="font-size: 16px;">📁 <?= I18n::t('recent_scans') ?></strong>
            <button type="button" class="btn btn-xs btn-ghost" id="closeHistoryModalBtn" aria-label="Close history modal">✕</button>
        </div>

        <!-- History Filter Bar -->
        <div style="padding: 10px 20px; border-bottom: 1px solid var(--border); background: var(--bg-elevated); display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;">
            <select id="histSymbolFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;" aria-label="Filter by symbol">
                <option value="">All Symbols</option>
                <option value="XAUUSD">XAUUSD</option>
                <option value="BTCUSD">BTCUSD</option>
                <option value="EURUSD">EURUSD</option>
                <option value="GBPUSD">GBPUSD</option>
                <option value="US30">US30</option>
                <option value="NAS100">NAS100</option>
            </select>
            <select id="histTfFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;" aria-label="Filter by timeframe">
                <option value="">All Timeframes</option>
                <option value="M1">M1</option>
                <option value="M5">M5</option>
                <option value="M15">M15</option>
                <option value="M30">M30</option>
                <option value="H1">H1</option>
                <option value="H4">H4</option>
                <option value="D1">D1</option>
            </select>
            <select id="histStrategyFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;" aria-label="Filter by strategy">
                <option value="">All Strategies</option>
                <option value="ALL">ALL</option>
                <option value="ICT">ICT</option>
                <option value="SMC">SMC</option>
                <option value="CRT">CRT</option>
                <option value="SNR">SNR</option>
            </select>
            <select id="histDirectionFilter" class="form-select" style="font-size: 12px; padding: 5px 8px;" aria-label="Filter by signal direction">
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
const SYMBOL_SPECS = <?= $symbolSpecsJson ?>;

window.selectVisionStrategy = function(strat) {
    const input = document.getElementById('strategyModeInput');
    if (input) input.value = strat;
    document.querySelectorAll('.strat-chip-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.strat === strat);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB
    const MIN_IMAGE_DIM = 50;
    const MAX_CANVAS_DIM = 2048;
    const TIMEFRAME_RANK = { M1: 1, M5: 5, M15: 15, M30: 30, H1: 60, H4: 240, D1: 1440 };

    const errorBox = document.getElementById('visionUploadError');
    function showUploadError(msg) {
        if (!errorBox) return;
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }
    function clearUploadError() {
        if (!errorBox) return;
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    // =========================================================================
    // MODULE 1: VisionValidation — Client-side File, Symbol, Price & TF Checks
    // =========================================================================
    const VisionValidation = {
        validateFile(file) {
            if (!file) {
                throw new Error('No image file selected.');
            }
            if (!file.type || !/^image\/(png|jpe?g|webp)$/i.test(file.type)) {
                throw new Error('Unsupported image format. Only PNG, JPG, JPEG, and WEBP are allowed.');
            }
            if (file.size <= 0) {
                throw new Error('Selected image file is empty.');
            }
            if (file.size > MAX_UPLOAD_BYTES) {
                throw new Error('Image file exceeds the maximum upload size of 10MB.');
            }
        },

        validateMagicBytes(arrayBuffer) {
            const bytes = new Uint8Array(arrayBuffer);
            if (bytes.length < 24) {
                throw new Error('Corrupted image file: header is truncated or too small.');
            }
            const isPng = bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4E && bytes[3] === 0x47;
            const isJpg = bytes[0] === 0xFF && bytes[1] === 0xD8 && bytes[2] === 0xFF;
            const isWebp = bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46
                && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50;
            if (!isPng && !isJpg && !isWebp) {
                throw new Error('Invalid image binary content. Only genuine PNG, JPG, and WEBP images are accepted.');
            }
        },

        validateSymbolAndPrice(symbol, manualPriceStr) {
            const spec = SYMBOL_SPECS[symbol] || SYMBOL_SPECS.XAUUSD;
            if (manualPriceStr !== '' && manualPriceStr !== null && manualPriceStr !== undefined) {
                const val = Number(manualPriceStr);
                if (!Number.isFinite(val) || val <= 0) {
                    throw new Error('Reference price must be a positive number.');
                }
                if (val < spec.min_price || val > spec.max_price) {
                    throw new Error(`Reference price (${val}) is outside the valid range for ${symbol} (${spec.min_price} – ${spec.max_price}).`);
                }
            }
        },

        validateTimeframes(execTf, htfTf) {
            if (!htfTf) return;
            const execRank = TIMEFRAME_RANK[execTf] || 15;
            const htfRank = TIMEFRAME_RANK[htfTf] || 240;
            if (htfRank < execRank) {
                throw new Error(`Higher-Timeframe (${htfTf}) cannot be lower than Execution Timeframe (${execTf}).`);
            }
        },

        formatPrice(price, symbol = 'XAUUSD') {
            const num = Number(price);
            if (!Number.isFinite(num) || num <= 0) return '—';
            const spec = SYMBOL_SPECS[symbol] || SYMBOL_SPECS.XAUUSD;
            return (spec.prefix || '') + num.toFixed(spec.decimals);
        }
    };

    // =========================================================================
    // MODULE 2: VisionImageProcessor — EXIF Orientation, Canvas Bake & Memory
    // =========================================================================
    const VisionImageProcessor = {
        activeUrls: new Set(),

        createTrackedUrl(blob) {
            const url = URL.createObjectURL(blob);
            this.activeUrls.add(url);
            return url;
        },

        revokeTrackedUrl(url) {
            if (url && this.activeUrls.has(url)) {
                URL.revokeObjectURL(url);
                this.activeUrls.delete(url);
            }
        },

        parseExifOrientation(arrayBuffer) {
            const view = new DataView(arrayBuffer);
            if (view.byteLength < 12 || view.getUint16(0, false) !== 0xFFD8) {
                return 1;
            }
            let offset = 2;
            while (offset + 4 < view.byteLength) {
                const marker = view.getUint16(offset, false);
                offset += 2;
                if (marker === 0xFFDA || marker === 0xFFD9) break;
                const segLen = view.getUint16(offset, false);
                if (segLen < 2 || offset + segLen > view.byteLength) break;
                if (marker === 0xFFE1 && segLen >= 14 && view.getUint32(offset + 2, false) === 0x45786966) {
                    const tiffOffset = offset + 8;
                    const little = view.getUint16(tiffOffset, false) === 0x4949;
                    const firstIfd = view.getUint32(tiffOffset + 4, little);
                    const dirStart = tiffOffset + firstIfd;
                    if (dirStart + 2 > view.byteLength) return 1;
                    const entries = view.getUint16(dirStart, little);
                    for (let i = 0; i < entries; i++) {
                        const entry = dirStart + 2 + i * 12;
                        if (entry + 12 > view.byteLength) break;
                        if (view.getUint16(entry, little) === 0x0112) {
                            const val = view.getUint16(entry + 8, little);
                            return (val >= 1 && val <= 8) ? val : 1;
                        }
                    }
                    return 1;
                }
                offset += segLen;
            }
            return 1;
        },

        async processImage(fileOrBuffer, fileName, manualRotationDeg = 0) {
            let buffer = fileOrBuffer;
            if (fileOrBuffer instanceof Blob) {
                VisionValidation.validateFile(fileOrBuffer);
                buffer = await fileOrBuffer.arrayBuffer();
            }
            VisionValidation.validateMagicBytes(buffer);
            const exifOrientation = this.parseExifOrientation(buffer);
            const rawBlob = new Blob([buffer]);
            const tempUrl = this.createTrackedUrl(rawBlob);

            try {
                const img = await new Promise((resolve, reject) => {
                    const i = new Image();
                    i.onload = () => resolve(i);
                    i.onerror = () => reject(new Error('Corrupted or unreadable image stream.'));
                    i.src = tempUrl;
                });

                const rawW = img.naturalWidth || img.width;
                const rawH = img.naturalHeight || img.height;
                if (rawW < MIN_IMAGE_DIM || rawH < MIN_IMAGE_DIM) {
                    throw new Error(`Image dimensions (${rawW}×${rawH}px) are too small. Minimum chart size is ${MIN_IMAGE_DIM}×${MIN_IMAGE_DIM}px.`);
                }
                if (rawW * rawH > 25000000) {
                    throw new Error('Image pixel count exceeds 25MP decompression safety limit.');
                }

                const swap = (manualRotationDeg === 90 || manualRotationDeg === 270);
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
                ctx.rotate((manualRotationDeg * Math.PI) / 180);
                const drawW = swap ? outH : outW;
                const drawH = swap ? outW : outH;
                ctx.drawImage(img, -drawW / 2, -drawH / 2, drawW, drawH);
                ctx.restore();

                const bakedBlob = await new Promise((resolve, reject) => {
                    canvas.toBlob(b => b ? resolve(b) : reject(new Error('Failed to encode processed chart image.')), 'image/png');
                });
                if (bakedBlob.size > MAX_UPLOAD_BYTES) {
                    throw new Error('Processed chart image exceeds 10MB limit.');
                }
                const previewUrl = this.createTrackedUrl(bakedBlob);

                return {
                    buffer,
                    blob: bakedBlob,
                    previewUrl,
                    width: outW,
                    height: outH,
                    exifOrientation,
                    rotation: manualRotationDeg,
                    fileName: (fileName || 'chart').replace(/\.[^.]+$/, '') + '.png'
                };
            } finally {
                this.revokeTrackedUrl(tempUrl);
            }
        }
    };

    // =========================================================================
    // MODULE 3: VisionUpload — Independent Main & HTF Screenshot Controllers
    // =========================================================================
    const VisionUpload = {
        mainState: { buffer: null, blob: null, previewUrl: null, fileName: 'chart.png', rotation: 0, opId: 0 },
        htfState:  { buffer: null, blob: null, previewUrl: null, fileName: 'htf_chart.png', rotation: 0, opId: 0 },

        async loadMainFile(file) {
            clearUploadError();
            const opId = ++this.mainState.opId;
            try {
                const res = await VisionImageProcessor.processImage(file, file.name || 'chart.png', 0);
                if (opId !== this.mainState.opId) {
                    VisionImageProcessor.revokeTrackedUrl(res.previewUrl);
                    return;
                }
                this.applyMainState(res);
            } catch (err) {
                showUploadError(err.message);
            }
        },

        async rotateMain() {
            if (!this.mainState.buffer) return;
            clearUploadError();
            const opId = ++this.mainState.opId;
            const nextRot = (this.mainState.rotation + 90) % 360;
            try {
                const res = await VisionImageProcessor.processImage(this.mainState.buffer, this.mainState.fileName, nextRot);
                if (opId !== this.mainState.opId) {
                    VisionImageProcessor.revokeTrackedUrl(res.previewUrl);
                    return;
                }
                this.applyMainState(res);
            } catch (err) {
                showUploadError(err.message);
            }
        },

        applyMainState(res) {
            if (this.mainState.previewUrl) {
                VisionImageProcessor.revokeTrackedUrl(this.mainState.previewUrl);
            }
            this.mainState.buffer = res.buffer;
            this.mainState.blob = res.blob;
            this.mainState.previewUrl = res.previewUrl;
            this.mainState.fileName = res.fileName;
            this.mainState.rotation = res.rotation;

            const previewImg = document.getElementById('visionPreviewImg');
            const previewMeta = document.getElementById('visionPreviewMeta');
            previewImg.src = res.previewUrl;
            const kb = (res.blob.size / 1024).toFixed(1);
            previewMeta.textContent = `${res.fileName} · ${res.width}×${res.height}px · ${kb} KB` + (res.rotation ? ` · Rotated ${res.rotation}°` : '');
            document.getElementById('visionDropzone').classList.add('hidden');
            document.getElementById('visionPreviewContainer').classList.remove('hidden');
            document.getElementById('analyzeBtn').disabled = false;
        },

        clearMain() {
            this.mainState.opId++;
            if (this.mainState.previewUrl) {
                VisionImageProcessor.revokeTrackedUrl(this.mainState.previewUrl);
            }
            this.mainState = { buffer: null, blob: null, previewUrl: null, fileName: 'chart.png', rotation: 0, opId: this.mainState.opId };
            document.getElementById('visionFileInput').value = '';
            document.getElementById('visionPreviewImg').src = '';
            document.getElementById('visionPreviewMeta').textContent = '';
            document.getElementById('visionPreviewContainer').classList.add('hidden');
            document.getElementById('visionDropzone').classList.remove('hidden');
            document.getElementById('analyzeBtn').disabled = true;
            clearUploadError();
        },

        async loadHtfFile(file) {
            clearUploadError();
            const opId = ++this.htfState.opId;
            try {
                const res = await VisionImageProcessor.processImage(file, file.name || 'htf_chart.png', 0);
                if (opId !== this.htfState.opId) {
                    VisionImageProcessor.revokeTrackedUrl(res.previewUrl);
                    return;
                }
                this.applyHtfState(res);
            } catch (err) {
                showUploadError('HTF Screenshot Error: ' + err.message);
            }
        },

        async rotateHtf() {
            if (!this.htfState.buffer) return;
            clearUploadError();
            const opId = ++this.htfState.opId;
            const nextRot = (this.htfState.rotation + 90) % 360;
            try {
                const res = await VisionImageProcessor.processImage(this.htfState.buffer, this.htfState.fileName, nextRot);
                if (opId !== this.htfState.opId) {
                    VisionImageProcessor.revokeTrackedUrl(res.previewUrl);
                    return;
                }
                this.applyHtfState(res);
            } catch (err) {
                showUploadError('HTF Rotation Error: ' + err.message);
            }
        },

        applyHtfState(res) {
            if (this.htfState.previewUrl) {
                VisionImageProcessor.revokeTrackedUrl(this.htfState.previewUrl);
            }
            this.htfState.buffer = res.buffer;
            this.htfState.blob = res.blob;
            this.htfState.previewUrl = res.previewUrl;
            this.htfState.fileName = res.fileName;
            this.htfState.rotation = res.rotation;

            document.getElementById('htfPreviewImg').src = res.previewUrl;
            const kb = (res.blob.size / 1024).toFixed(1);
            document.getElementById('htfPreviewMeta').textContent = `${res.fileName} · ${res.width}×${res.height}px · ${kb} KB` + (res.rotation ? ` · ${res.rotation}°` : '');
            document.getElementById('htfPreviewContainer').classList.remove('hidden');
        },

        clearHtf() {
            this.htfState.opId++;
            if (this.htfState.previewUrl) {
                VisionImageProcessor.revokeTrackedUrl(this.htfState.previewUrl);
            }
            this.htfState = { buffer: null, blob: null, previewUrl: null, fileName: 'htf_chart.png', rotation: 0, opId: this.htfState.opId };
            document.getElementById('htfFileInput').value = '';
            document.getElementById('htfPreviewImg').src = '';
            document.getElementById('htfPreviewMeta').textContent = '';
            document.getElementById('htfPreviewContainer').classList.add('hidden');
        }
    };

    // =========================================================================
    // MODULE 4: VisionApiClient — Timeout, Cancellation, Progress & Validation
    // =========================================================================
    const VisionApiClient = {
        activeXhr: null,

        extractError(payload, fallback = 'Request failed.') {
            if (!payload) return fallback;
            if (typeof payload.error === 'object' && payload.error !== null && payload.error.message) {
                return payload.error.message;
            }
            if (typeof payload.error === 'string' && payload.error) {
                return payload.error;
            }
            if (payload.error_message) return payload.error_message;
            return fallback;
        },

        cancelActive() {
            if (this.activeXhr) {
                this.activeXhr.abort();
                this.activeXhr = null;
            }
        },

        postFormWithProgress(url, formData, onProgress, timeoutMs = 35000) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                this.activeXhr = xhr;
                xhr.open('POST', url, true);
                xhr.timeout = timeoutMs;
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable && typeof onProgress === 'function') {
                        onProgress(Math.round((e.loaded / e.total) * 100));
                    }
                };

                xhr.onload = () => {
                    this.activeXhr = null;
                    let json = null;
                    try {
                        json = JSON.parse(xhr.responseText);
                    } catch (e) {
                        reject(new Error('Invalid JSON response from server.'));
                        return;
                    }
                    if (!json || typeof json !== 'object') {
                        reject(new Error('Malformed API response structure.'));
                        return;
                    }
                    if (xhr.status >= 400 || !json.ok) {
                        reject(new Error(this.extractError(json, `HTTP ${xhr.status} error`)));
                        return;
                    }
                    resolve(json.data || json);
                };

                xhr.onerror = () => {
                    this.activeXhr = null;
                    reject(new Error('Network connection error while communicating with server.'));
                };
                xhr.ontimeout = () => {
                    this.activeXhr = null;
                    reject(new Error('Analysis request timed out after 35 seconds. Please try again.'));
                };
                xhr.onabort = () => {
                    this.activeXhr = null;
                    reject(new Error('Analysis cancelled by user.'));
                };

                xhr.send(formData);
            });
        },

        async fetchJson(url, options = {}, timeoutMs = 15000) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeoutMs);
            try {
                const res = await fetch(url, { ...options, signal: controller.signal });
                let json = null;
                try {
                    json = await res.json();
                } catch (e) {
                    throw new Error('Server returned invalid JSON.');
                }
                if (!res.ok || !json.ok) {
                    throw new Error(this.extractError(json, `Request failed (${res.status})`));
                }
                return json;
            } catch (err) {
                if (err.name === 'AbortError') {
                    throw new Error('Request timed out.');
                }
                throw err;
            } finally {
                clearTimeout(timer);
            }
        }
    };

    // =========================================================================
    // MODULE 5: VisionOverlayRenderer — High-DPI 10-Category X/Y Canvas Engine
    // =========================================================================
    const VisionOverlayRenderer = {
        isCategoryEnabled(cat, group) {
            const toggleMap = {
                entry:     'toggleOverlayEntry',
                sl:        'toggleOverlaySl',
                tp:        'toggleOverlayTp',
                structure: 'toggleOverlayStructure',
                ob:        'toggleOverlayOb',
                fvg:       'toggleOverlayFvg',
                bsl:       'toggleOverlayBsl',
                ssl:       'toggleOverlaySsl',
                eq_hl:     'toggleOverlayEqHl',
                swings:    'toggleOverlaySwings'
            };
            if (toggleMap[cat]) {
                return document.getElementById(toggleMap[cat])?.checked ?? true;
            }
            if (cat === 'fvg_ob' || group === 'fvg_ob') {
                return (document.getElementById('toggleOverlayOb')?.checked ?? true)
                    || (document.getElementById('toggleOverlayFvg')?.checked ?? true);
            }
            if (cat === 'liquidity' || group === 'liquidity') {
                return (document.getElementById('toggleOverlayBsl')?.checked ?? true)
                    || (document.getElementById('toggleOverlaySsl')?.checked ?? true);
            }
            return true;
        },

        draw(overlay) {
            const canvas = document.getElementById('overlayCanvas');
            const img = document.getElementById('overlayChartImg');
            if (!canvas || !img || !overlay) return;

            const cssW = img.clientWidth || overlay.width || 800;
            const cssH = img.clientHeight || overlay.height || 600;
            if (cssW < 10 || cssH < 10) return;

            const dpr = Math.max(1, window.devicePixelRatio || 1);
            canvas.width = Math.round(cssW * dpr);
            canvas.height = Math.round(cssH * dpr);
            canvas.style.width = cssW + 'px';
            canvas.style.height = cssH + 'px';

            const ctx = canvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, cssW, cssH);

            const srcW = Math.max(1, Number(overlay.width) || cssW);
            const srcH = Math.max(1, Number(overlay.height) || cssH);
            const scaleX = cssW / srcW;
            const scaleY = cssH / srcH;

            (overlay.elements || []).forEach(el => {
                if (!el || typeof el !== 'object') return;
                const cat = String(el.category || 'structure');
                const grp = String(el.group || '');
                if (!this.isCategoryEnabled(cat, grp)) return;

                if (el.type === 'zone') {
                    if (!Number.isFinite(Number(el.y1)) || !Number.isFinite(Number(el.y2))) return;
                    const x1 = Number.isFinite(Number(el.x1)) ? Math.max(0, Number(el.x1) * scaleX) : 0;
                    const x2 = Number.isFinite(Number(el.x2)) ? Math.min(cssW, Number(el.x2) * scaleX) : cssW;
                    const y1 = Math.max(4, Math.min(cssH - 12, Number(el.y1) * scaleY));
                    const y2 = Math.max(12, Math.min(cssH - 4, Number(el.y2) * scaleY));
                    const top = Math.min(y1, y2);
                    const boxW = Math.max(24, x2 - x1);
                    const boxH = Math.max(10, Math.abs(y2 - y1));

                    ctx.fillStyle = el.color || 'rgba(0, 229, 255, 0.20)';
                    ctx.fillRect(x1, top, boxW, boxH);
                    ctx.strokeStyle = el.stroke || '#00e5ff';
                    ctx.lineWidth = 1.5;
                    ctx.strokeRect(x1, top, boxW, boxH);

                    ctx.font = 'bold 11px JetBrains Mono, monospace';
                    const labelTxt = String(el.label || '');
                    const txtW = Math.min(cssW - 20, ctx.measureText(labelTxt).width + 14);
                    const lblX = Math.max(6, Math.min(cssW - txtW - 6, x1 + 6));
                    ctx.fillStyle = 'rgba(15, 23, 42, 0.90)';
                    ctx.fillRect(lblX, Math.max(2, top - 18), txtW, 18);
                    ctx.fillStyle = el.stroke || '#00e5ff';
                    ctx.fillText(labelTxt, lblX + 6, Math.max(14, top - 5));
                } else if (el.type === 'line') {
                    if (!Number.isFinite(Number(el.y))) return;
                    const x1 = Number.isFinite(Number(el.x1)) ? Math.max(0, Number(el.x1) * scaleX) : 0;
                    const x2 = Number.isFinite(Number(el.x2)) ? Math.min(cssW, Number(el.x2) * scaleX) : cssW;
                    const y = Math.max(14, Math.min(cssH - 10, Number(el.y) * scaleY));

                    ctx.strokeStyle = el.color || '#00ff88';
                    ctx.lineWidth = 2;
                    if (Array.isArray(el.dash)) ctx.setLineDash(el.dash); else ctx.setLineDash([]);
                    ctx.beginPath();
                    ctx.moveTo(x1, y);
                    ctx.lineTo(x2, y);
                    ctx.stroke();
                    ctx.setLineDash([]);

                    ctx.font = 'bold 11px JetBrains Mono, monospace';
                    const labelTxt = String(el.label || '');
                    const txtW = Math.min(cssW - 20, ctx.measureText(labelTxt).width + 16);
                    const boxX = Math.max(8, Math.min(cssW - txtW - 8, x2 - txtW - 6));
                    ctx.fillStyle = 'rgba(15, 23, 42, 0.90)';
                    ctx.fillRect(boxX, y - 10, txtW, 20);
                    ctx.strokeStyle = el.color || '#00ff88';
                    ctx.lineWidth = 1;
                    ctx.strokeRect(boxX, y - 10, txtW, 20);
                    ctx.fillStyle = el.color || '#00ff88';
                    ctx.fillText(labelTxt, boxX + 7, y + 4);
                }
            });
        }
    };

    // =========================================================================
    // MODULE 6: VisionResultRenderer — Safe DOM & Symbol-Aware Report Renderer
    // =========================================================================
    const VisionResultRenderer = {
        render(data) {
            if (!data || typeof data !== 'object') return;
            const report = data.report || {};
            const overlay = data.overlay || {};
            const symbol = data.symbol || report.symbol || document.getElementById('symbolSelect')?.value || 'XAUUSD';
            const fmt = (p) => VisionValidation.formatPrice(p, symbol);

            document.getElementById('visionPlaceholder')?.classList.add('hidden');
            document.getElementById('visionResultBox')?.classList.remove('hidden');

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

            document.getElementById('resScoreBadge').textContent = `Evidence Score: ${report.confidence_score ?? 50}/100`;
            document.getElementById('resModelBadge').textContent = `Model: ${data.ai_model || 'deterministic'}`;
            document.getElementById('resStratModeBadge').textContent = `Mode: ${report.strategy_mode || document.getElementById('strategyModeInput').value || 'ALL'}`;
            document.getElementById('resPriceSourceBadge').textContent = report.price_source_label || 'Historical Engine Reference';

            // Level Cards (Show "NO TRADE / Stand Aside" or formatted symbol prices)
            const ez = report.entry_zone || {};
            document.getElementById('resEntryVal').textContent = (ez.min > 0 && ez.max > 0)
                ? `${fmt(ez.min)} – ${fmt(ez.max)}`
                : '—';
            document.getElementById('resSlVal').textContent = report.stop_loss?.price > 0 ? fmt(report.stop_loss.price) : '—';
            document.getElementById('resTp1Val').textContent = report.take_profit?.tp1?.price > 0 ? fmt(report.take_profit.tp1.price) : '—';
            document.getElementById('resTp2Val').textContent = report.take_profit?.tp2?.price > 0 ? fmt(report.take_profit.tp2.price) : '—';
            document.getElementById('resTp3Val').textContent = report.take_profit?.tp3?.price > 0 ? fmt(report.take_profit.tp3.price) : '—';

            // Tab 1: Structure & Liquidity
            document.getElementById('resStructureVal').textContent = report.market_structure || 'Consolidation';
            document.getElementById('resStructureDesc').textContent = report.structure_details || '';
            const swSeq = document.getElementById('resSwingSeqLine');
            if (swSeq && report.swing_structure) {
                const ss = report.swing_structure;
                swSeq.textContent = `Swing Bounds: HH ${fmt(ss.hh)} · HL ${fmt(ss.hl)} · LH ${fmt(ss.lh)} · LL ${fmt(ss.ll)} · MSS: ${ss.mss_detected ? 'Confirmed' : 'No'} · Displacement: ${ss.displacement ? 'Yes' : 'No'}`;
            }

            const fillList = (id, items, mapFn) => {
                const ul = document.getElementById(id);
                if (!ul) return;
                ul.replaceChildren();
                (items || []).forEach(item => {
                    const li = document.createElement('li');
                    li.textContent = mapFn ? mapFn(item) : String(item);
                    ul.appendChild(li);
                });
            };

            fillList('resBslList', report.liquidity_targets?.bsl, l => `${fmt(l.level)} (${l.label})`);
            fillList('resSslList', report.liquidity_targets?.ssl, l => `${fmt(l.level)} (${l.label})`);

            // Tab 2: Multi-Timeframe (MTF) with Data Source Badges
            const mtf = report.mtf_analysis || {};
            const mtfBadge = document.getElementById('resMtfStatusBadge');
            if (mtfBadge) {
                const st = mtf.alignment_status || 'Neutral';
                mtfBadge.textContent = st;
                mtfBadge.className = 'badge ' + (st === 'Aligned' ? 'badge-live' : (st === 'Conflicting' ? 'badge-warn' : ''));
            }
            document.getElementById('resMtfExplanation').textContent = mtf.alignment_explanation || '';

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
                    bLbl.textContent = tfItem.bias || 'Neutral';
                    top.appendChild(tfLbl);
                    top.appendChild(bLbl);

                    const srcTag = document.createElement('div');
                    srcTag.style.cssText = 'font-size: 10px; margin-top: 3px; color: ' + (tfItem.visually_inspected ? '#38bdf8' : 'var(--text-muted)');
                    srcTag.textContent = tfItem.visually_inspected ? '📷 Uploaded Chart' : '📊 Market Data';

                    const sub = document.createElement('div');
                    sub.className = 'muted';
                    sub.style.cssText = 'font-size: 10.5px; margin-top: 4px; line-height: 1.35;';
                    sub.textContent = tfItem.structure || '';
                    card.appendChild(top);
                    card.appendChild(srcTag);
                    card.appendChild(sub);
                    mtfGrid.appendChild(card);
                });
            }

            // Tab 3: Quality Gate & 7-Component Confidence
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
                    lbl.title = chk.detail || '';
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
            if (cb.confidence_disclaimer) {
                document.getElementById('resConfidenceDisclaimer').textContent = 'ℹ️ ' + cb.confidence_disclaimer;
            }
            document.getElementById('resConfidenceTotalLabel').textContent = `${report.confidence_score ?? 50}/100 (Evidence Score)`;
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

            // Tab 4: 5-Part Epistemic Separation
            fillList('resFactsList', report.observed_facts);
            fillList('resMissingInfoList', report.missing_information || report.uncertainties_warnings);
            fillList('resConfirmationList', report.confirmation_conditions);

            const interpList = document.getElementById('resInterpList');
            if (interpList) {
                interpList.replaceChildren();
                (report.ai_interpretations || []).forEach(item => {
                    const box = document.createElement('div');
                    box.style.cssText = 'padding: 8px 10px; border-radius: 6px; background: rgba(255,255,255,0.03); border: 1px solid var(--border);';
                    const hLine = document.createElement('div');
                    hLine.style.fontWeight = '700';
                    hLine.style.color = 'var(--gold-bright)';
                    hLine.textContent = `${item.hypothesis || ''} [${item.confidence_level || ''}]`;

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
            }

            // Tab 5 & 6
            document.getElementById('resDetectedStratTitle').textContent = report.detected_strategy || '—';
            fillList('resEvidenceList', report.supporting_evidence);
            fillList('resInvalidationList', report.setup_invalidations);
            fillList('resAvoidList', (report.reasons_to_avoid || []).concat(report.uncertainties_warnings || []));

            // Render High-DPI Canvas Overlay
            const overlayImg = document.getElementById('overlayChartImg');
            overlayImg.onload = () => VisionOverlayRenderer.draw(overlay);
            overlayImg.src = data.image_url || VisionUpload.mainState.previewUrl || '';
        }
    };

    // =========================================================================
    // MODULE 7: VisionTelegram — Unified Reusable Telegram Dispatch Workflow
    // =========================================================================
    const VisionTelegram = {
        async send(analysisId, btnEl, textEl = null) {
            if (!analysisId || analysisId <= 0) {
                showUploadError('No valid chart analysis ID available to send.');
                return;
            }
            const targetTextEl = textEl || btnEl;
            const origText = targetTextEl.textContent;
            const origBg = btnEl.style.background;
            btnEl.disabled = true;
            targetTextEl.textContent = '⏳ Sending...';

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await VisionApiClient.fetchJson('api/vision_telegram.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ analysis_id: analysisId, _csrf: csrf })
                });
                const payload = res.data || res;
                btnEl.style.background = '#10b981';
                targetTextEl.textContent = payload.deduplicated ? '✓ Already Sent (60s)' : '✅ Sent to Telegram!';
            } catch (err) {
                btnEl.style.background = '#e11d48';
                targetTextEl.textContent = '✕ Failed';
                showUploadError('Telegram Error: ' + err.message);
            } finally {
                setTimeout(() => {
                    targetTextEl.textContent = origText;
                    btnEl.style.background = origBg || '#229ed9';
                    btnEl.disabled = false;
                }, 3200);
            }
        }
    };

    // =========================================================================
    // MODULE 8: VisionHistory — Paginated, Filterable History with Delete & Load
    // =========================================================================
    const VisionHistory = {
        page: 1,
        totalPages: 1,
        lastFocusedEl: null,

        open() {
            this.lastFocusedEl = document.activeElement;
            const modal = document.getElementById('historyModal');
            modal.classList.remove('hidden');
            document.getElementById('closeHistoryModalBtn')?.focus();
            this.load(1);
        },

        close() {
            document.getElementById('historyModal')?.classList.add('hidden');
            if (this.lastFocusedEl && typeof this.lastFocusedEl.focus === 'function') {
                this.lastFocusedEl.focus();
            }
        },

        async load(page = 1) {
            this.page = Math.max(1, page);
            const container = document.getElementById('historyListContainer');
            const prevBtn = document.getElementById('histPrevBtn');
            const nextBtn = document.getElementById('histNextBtn');
            const pageLbl = document.getElementById('histPageLabel');

            container.replaceChildren();
            const loading = document.createElement('div');
            loading.className = 'muted';
            loading.textContent = 'Loading past scans...';
            container.appendChild(loading);

            const q = new URLSearchParams({
                page: String(this.page),
                per_page: '10',
                symbol: document.getElementById('histSymbolFilter')?.value || '',
                timeframe: document.getElementById('histTfFilter')?.value || '',
                strategy: document.getElementById('histStrategyFilter')?.value || '',
                direction: document.getElementById('histDirectionFilter')?.value || ''
            });

            try {
                const res = await VisionApiClient.fetchJson('api/vision_history.php?' + q.toString());
                const payload = res.data || res;
                const items = payload.items || [];
                const pag = payload.pagination || { page: 1, total_pages: 1, total: items.length };

                this.page = pag.page || 1;
                this.totalPages = pag.total_pages || 1;
                pageLbl.textContent = `Page ${this.page} of ${this.totalPages} (${pag.total || 0} records)`;
                prevBtn.disabled = (this.page <= 1);
                nextBtn.disabled = (this.page >= this.totalPages);

                container.replaceChildren();
                if (items.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'muted';
                    empty.textContent = 'No past chart scans match your filter criteria.';
                    container.appendChild(empty);
                    return;
                }

                items.forEach(item => {
                    const row = document.createElement('div');
                    row.style.cssText = 'background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 6px; padding: 12px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px;';

                    const leftGroup = document.createElement('div');
                    leftGroup.style.cssText = 'display: flex; align-items: center; gap: 12px; min-width: 0;';

                    const thumb = document.createElement('img');
                    thumb.src = item.image_url || '';
                    thumb.alt = 'Chart Thumbnail';
                    thumb.style.cssText = 'width: 52px; height: 52px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border); flex-shrink: 0;';
                    thumb.onerror = function() {
                        this.style.display = 'none';
                    };

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
                    subLine.textContent = `${item.detected_strategy || 'SMC/ICT'} · Evidence: ${item.confidence_score}/100 · ${item.created_at} UTC`;

                    infoBox.appendChild(titleLine);
                    infoBox.appendChild(subLine);
                    leftGroup.appendChild(thumb);
                    leftGroup.appendChild(infoBox);

                    const actionGroup = document.createElement('div');
                    actionGroup.style.cssText = 'display: flex; gap: 6px; flex-shrink: 0;';

                    const loadBtn = document.createElement('button');
                    loadBtn.type = 'button';
                    loadBtn.className = 'btn btn-xs btn-primary';
                    loadBtn.textContent = 'Load';
                    loadBtn.addEventListener('click', () => {
                        this.close();
                        currentAnalysisResult = {
                            analysis_id: item.id,
                            symbol: item.symbol,
                            timeframe: item.timeframe,
                            image_url: item.image_url,
                            report: item.report,
                            overlay: item.overlay,
                            ai_model: item.ai_model
                        };
                        VisionResultRenderer.render(currentAnalysisResult);
                    });

                    const tgBtn = document.createElement('button');
                    tgBtn.type = 'button';
                    tgBtn.className = 'btn btn-xs';
                    tgBtn.style.cssText = 'background: #229ed9; color: #fff; border: none; font-weight: 600;';
                    tgBtn.textContent = '✈️ Telegram';
                    tgBtn.addEventListener('click', () => VisionTelegram.send(item.id, tgBtn));

                    const delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn btn-xs btn-danger';
                    delBtn.textContent = '🗑️ Delete';
                    delBtn.addEventListener('click', () => this.deleteItem(item.id, row));

                    actionGroup.appendChild(loadBtn);
                    actionGroup.appendChild(tgBtn);
                    actionGroup.appendChild(delBtn);

                    row.appendChild(leftGroup);
                    row.appendChild(actionGroup);
                    container.appendChild(row);
                });
            } catch (err) {
                container.replaceChildren();
                const errDiv = document.createElement('div');
                errDiv.className = 'text-bear';
                errDiv.textContent = 'Failed to load history: ' + err.message;
                container.appendChild(errDiv);
            }
        },

        async deleteItem(id, rowEl) {
            if (!window.confirm('Delete this saved chart analysis permanently?')) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const form = new URLSearchParams({ _method: 'DELETE', id: String(id), _csrf: csrf });
                await VisionApiClient.fetchJson('api/vision_history.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrf
                    },
                    body: form.toString()
                });
                if (rowEl && rowEl.parentNode) {
                    rowEl.parentNode.removeChild(rowEl);
                }
                this.load(this.page);
            } catch (err) {
                showUploadError('Delete failed: ' + err.message);
            }
        }
    };

    // =========================================================================
    // Event Wiring & Interactive Lifecycle
    // =========================================================================
    let currentAnalysisResult = null;
    let isSubmitting = false;

    const dropzone = document.getElementById('visionDropzone');
    const fileInput = document.getElementById('visionFileInput');
    const htfFileInput = document.getElementById('htfFileInput');
    const analyzeBtn = document.getElementById('analyzeBtn');
    const cancelAnalyzeBtn = document.getElementById('cancelAnalyzeBtn');

    document.getElementById('triggerMainFileBtn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        fileInput.click();
    });
    dropzone?.addEventListener('click', (e) => {
        if (e.target === dropzone) fileInput.click();
    });
    dropzone?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            fileInput.click();
        }
    });

    ['dragenter', 'dragover'].forEach(ev => {
        dropzone?.addEventListener(ev, (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(ev => {
        dropzone?.addEventListener(ev, (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone?.addEventListener('drop', (e) => {
        if (e.dataTransfer?.files?.[0]) {
            VisionUpload.loadMainFile(e.dataTransfer.files[0]);
        }
    });

    fileInput?.addEventListener('change', () => {
        if (fileInput.files?.[0]) VisionUpload.loadMainFile(fileInput.files[0]);
    });
    document.getElementById('rotateBtn')?.addEventListener('click', () => VisionUpload.rotateMain());
    document.getElementById('replaceBtn')?.addEventListener('click', () => fileInput.click());
    document.getElementById('removeBtn')?.addEventListener('click', () => VisionUpload.clearMain());

    // HTF Screenshot Controls
    document.getElementById('triggerHtfFileBtn')?.addEventListener('click', () => htfFileInput.click());
    htfFileInput?.addEventListener('change', () => {
        if (htfFileInput.files?.[0]) VisionUpload.loadHtfFile(htfFileInput.files[0]);
    });
    document.getElementById('htfRotateBtn')?.addEventListener('click', () => VisionUpload.rotateHtf());
    document.getElementById('htfRemoveBtn')?.addEventListener('click', () => VisionUpload.clearHtf());

    // Clipboard Paste (Ctrl+V)
    window.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
        if (!items) return;
        for (const item of items) {
            if (item.type && item.type.indexOf('image') !== -1) {
                const blob = item.getAsFile();
                if (blob) VisionUpload.loadMainFile(blob);
                break;
            }
        }
    });

    // Dynamic Symbol Precision & Bounds Hint
    const symbolSelect = document.getElementById('symbolSelect');
    const manualPriceInput = document.getElementById('manualPriceInput');
    const priceBoundsHint = document.getElementById('priceBoundsHint');
    symbolSelect?.addEventListener('change', () => {
        const sym = symbolSelect.value;
        const spec = SYMBOL_SPECS[sym] || SYMBOL_SPECS.XAUUSD;
        if (manualPriceInput) {
            manualPriceInput.step = String(spec.tick_size);
            manualPriceInput.placeholder = `Valid ${sym}: ${spec.min_price} – ${spec.max_price}`;
        }
        if (priceBoundsHint) {
            priceBoundsHint.textContent = `Valid ${sym} range: ${spec.prefix || ''}${spec.min_price} – ${spec.prefix || ''}${spec.max_price} (Tick: ${spec.tick_size})`;
        }
    });

    // Submit Analysis
    analyzeBtn?.addEventListener('click', async () => {
        if (isSubmitting || !VisionUpload.mainState.blob) return;
        clearUploadError();

        const symbol = symbolSelect?.value || 'XAUUSD';
        const execTf = document.getElementById('timeframeSelect')?.value || 'M15';
        const htfTf = document.getElementById('htfSelect')?.value || '';
        const manualPrice = manualPriceInput?.value || '';

        try {
            VisionValidation.validateSymbolAndPrice(symbol, manualPrice);
            VisionValidation.validateTimeframes(execTf, htfTf);
        } catch (err) {
            showUploadError(err.message);
            return;
        }

        isSubmitting = true;
        analyzeBtn.disabled = true;
        cancelAnalyzeBtn?.classList.remove('hidden');

        const formData = new FormData(document.getElementById('visionForm'));
        formData.set('chart_image', VisionUpload.mainState.blob, VisionUpload.mainState.fileName);
        if (VisionUpload.htfState.blob) {
            formData.set('htf_image', VisionUpload.htfState.blob, VisionUpload.htfState.fileName);
        } else {
            formData.delete('htf_image');
        }
        formData.set('enforce_min_dimensions', '1');

        const placeholder = document.getElementById('visionPlaceholder');
        const loader = document.getElementById('visionLoader');
        const resultBox = document.getElementById('visionResultBox');
        const progressBar = document.getElementById('visionUploadProgressBar');
        const elapsedEl = document.getElementById('visionElapsedTimer');

        placeholder.classList.add('hidden');
        resultBox.classList.add('hidden');
        loader.classList.remove('hidden');
        if (progressBar) progressBar.style.width = '5%';

        const startTs = performance.now();
        const timerInterval = setInterval(() => {
            const elapsed = ((performance.now() - startTs) / 1000).toFixed(1);
            if (elapsedEl) elapsedEl.textContent = `Elapsed: ${elapsed}s`;
        }, 100);

        try {
            const data = await VisionApiClient.postFormWithProgress(
                'api/vision_analyze.php',
                formData,
                (pct) => { if (progressBar) progressBar.style.width = `${pct}%`; },
                35000
            );
            currentAnalysisResult = data;
            VisionResultRenderer.render(data);
        } catch (err) {
            placeholder.classList.remove('hidden');
            showUploadError(err.message);
        } finally {
            clearInterval(timerInterval);
            loader.classList.add('hidden');
            cancelAnalyzeBtn?.classList.add('hidden');
            isSubmitting = false;
            analyzeBtn.disabled = !VisionUpload.mainState.blob;
        }
    });

    cancelAnalyzeBtn?.addEventListener('click', () => {
        VisionApiClient.cancelActive();
    });

    // Overlay Category Toggles
    document.querySelectorAll('#overlayCategoryToggles input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', () => {
            if (currentAnalysisResult?.overlay) VisionOverlayRenderer.draw(currentAnalysisResult.overlay);
        });
    });
    document.getElementById('overlayShowAllBtn')?.addEventListener('click', () => {
        document.querySelectorAll('#overlayCategoryToggles input[type="checkbox"]').forEach(cb => { cb.checked = true; });
        if (currentAnalysisResult?.overlay) VisionOverlayRenderer.draw(currentAnalysisResult.overlay);
    });
    document.getElementById('overlayHideAllBtn')?.addEventListener('click', () => {
        document.querySelectorAll('#overlayCategoryToggles input[type="checkbox"]').forEach(cb => { cb.checked = false; });
        if (currentAnalysisResult?.overlay) VisionOverlayRenderer.draw(currentAnalysisResult.overlay);
    });

    window.addEventListener('resize', () => {
        if (currentAnalysisResult?.overlay) VisionOverlayRenderer.draw(currentAnalysisResult.overlay);
    });
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => {
            if (currentAnalysisResult?.overlay) VisionOverlayRenderer.draw(currentAnalysisResult.overlay);
        });
        const wrapper = document.getElementById('canvasWrapper');
        if (wrapper) ro.observe(wrapper);
    }

    // Accessible Tabs (Click + Left/Right Arrow Navigation)
    const tabButtons = Array.from(document.querySelectorAll('.vision-tab-btn'));
    tabButtons.forEach((btn, idx) => {
        btn.addEventListener('click', () => {
            tabButtons.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
            const targetId = btn.dataset.tab;
            document.querySelectorAll('.vision-tab-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(targetId)?.classList.remove('hidden');
        });
        btn.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                const nextIdx = (idx + (e.key === 'ArrowRight' ? 1 : tabButtons.length - 1)) % tabButtons.length;
                tabButtons[nextIdx].focus();
                tabButtons[nextIdx].click();
            }
        });
    });

    // History Modal Events + Escape to Close
    document.getElementById('openHistoryBtn')?.addEventListener('click', () => VisionHistory.open());
    document.getElementById('closeHistoryModalBtn')?.addEventListener('click', () => VisionHistory.close());
    document.getElementById('histApplyFilterBtn')?.addEventListener('click', () => VisionHistory.load(1));
    document.getElementById('histPrevBtn')?.addEventListener('click', () => VisionHistory.load(VisionHistory.page - 1));
    document.getElementById('histNextBtn')?.addEventListener('click', () => VisionHistory.load(VisionHistory.page + 1));
    window.addEventListener('keydown', (e) => {
        const modal = document.getElementById('historyModal');
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            VisionHistory.close();
        }
    });

    // Active Report Telegram Button
    const sendTelegramBtn = document.getElementById('sendTelegramBtn');
    sendTelegramBtn?.addEventListener('click', () => {
        VisionTelegram.send(
            currentAnalysisResult?.analysis_id || 0,
            sendTelegramBtn,
            document.getElementById('sendTelegramText')
        );
    });

    // Expose V3 modules for diagnostics/inspection
    window.VisionV3 = {
        VisionValidation,
        VisionImageProcessor,
        VisionUpload,
        VisionApiClient,
        VisionOverlayRenderer,
        VisionResultRenderer,
        VisionTelegram,
        VisionHistory
    };
});
</script>

<?php
renderFooter();
