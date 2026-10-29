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
$livePrice = $quote['available'] ? (float)$quote['price'] : 2400.0;
$csrf = Csrf::token();
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>📷</span>
            <span><?= I18n::t('nav_vision') ?> — Institutional SMC / ICT Engine</span>
        </div>
        <div class="vision-hero-sub">
            Upload or paste any chart screenshot (TradingView, MT4/MT5, cTrader) to detect Market Structure (BOS/CHoCH/MSS), Order Blocks, FVGs, Liquidity Sweeps, and ICT Execution Setups with visual chart overlays.
        </div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <span class="badge badge-live">Live Ref: $<?= number_format($livePrice, 2) ?></span>
        <button type="button" class="btn btn-sm btn-ghost" id="openHistoryBtn">📁 <?= I18n::t('recent_scans') ?></button>
    </div>
</div>

<div class="vision-grid">
    <!-- Left Column: Image Upload & Setup Parameters -->
    <div class="vision-upload-card">
        <h3 style="font-size: 15px; margin-bottom: 12px; font-weight: 700;">1. Chart Screenshot Input</h3>

        <!-- Drag & Drop Zone -->
        <div class="vision-dropzone" id="visionDropzone">
            <span class="vision-dropzone-icon">📥</span>
            <div class="vision-dropzone-text"><?= I18n::t('upload_prompt') ?></div>
            <div class="vision-dropzone-hint">Supports PNG, JPG, JPEG, WEBP up to 10MB</div>
            <button type="button" class="btn btn-sm btn-primary" style="margin-top: 12px;" onclick="document.getElementById('visionFileInput').click()">
                <?= I18n::t('upload_btn') ?>
            </button>
            <input type="file" id="visionFileInput" accept="image/png,image/jpeg,image/jpg,image/webp" style="display: none;">
        </div>

        <!-- Preview Box (shown once image is uploaded or pasted) -->
        <div class="vision-preview-container hidden" id="visionPreviewContainer">
            <img id="visionPreviewImg" class="vision-preview-img" alt="Uploaded Chart Preview">
            <div class="vision-preview-controls">
                <button type="button" class="btn btn-xs btn-ghost" id="rotateBtn" title="Rotate 90°">🔄 Rotate</button>
                <button type="button" class="btn btn-xs btn-ghost" id="replaceBtn" title="Choose another image">🔁 Replace</button>
                <button type="button" class="btn btn-xs btn-danger" id="removeBtn" title="Remove image">✕</button>
            </div>
        </div>

        <h3 style="font-size: 15px; margin: 16px 0 12px; font-weight: 700;">2. Chart Metadata (Optional for Calibration)</h3>

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
                        <option value="US30">US30 (Dow Jones)</option>
                        <option value="NAS100">NAS100 (Nasdaq)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="timeframeSelect">Chart Timeframe</label>
                    <select id="timeframeSelect" name="timeframe" class="form-select">
                        <option value="M1">M1 (1 Minute)</option>
                        <option value="M5">M5 (5 Minutes)</option>
                        <option value="M15" selected>M15 (15 Minutes)</option>
                        <option value="M30">M30 (30 Minutes)</option>
                        <option value="H1">H1 (1 Hour)</option>
                        <option value="H4">H4 (4 Hours)</option>
                        <option value="D1">D1 (Daily)</option>
                    </select>
                </div>
            </div>

            <div class="vision-form-row">
                <div>
                    <label class="form-label" for="manualPriceInput" title="Leave blank to use live feed price">
                        Current Price (Optional)
                    </label>
                    <input type="number" step="0.01" id="manualPriceInput" name="manual_price" class="form-input" placeholder="e.g. <?= number_format($livePrice, 2) ?>" value="">
                </div>
                <div>
                    <label class="form-label" for="brokerInput">Broker / Chart Source</label>
                    <input type="text" id="brokerInput" name="broker" class="form-input" placeholder="TradingView / Exness / IC Markets">
                </div>
            </div>

            <button type="button" class="btn btn-primary" id="analyzeBtn" style="width: 100%; padding: 12px; font-weight: 800; font-size: 14px;" disabled>
                🚀 <?= I18n::t('analyze_chart') ?>
            </button>
        </form>

        <!-- Institutional Safety Notice -->
        <div style="margin-top: 16px; font-size: 11.5px; color: var(--text-muted); line-height: 1.5; border-top: 1px solid var(--border); padding-top: 12px;">
            🛡️ <strong>Integrity Guard:</strong> The AI Vision analyzer extracts observed structural facts from the chart without fabricating arbitrary prices. If price scales are ambiguous, provide current price above to ground the analysis.
        </div>
    </div>

    <!-- Right Column: Results, Visual Overlay & 14-Point Report -->
    <div>
        <!-- Placeholder before analysis -->
        <div id="visionPlaceholder" class="vision-report-box" style="text-align: center; padding: 60px 20px;">
            <span style="font-size: 48px; display: block; margin-bottom: 12px;">📈</span>
            <h3 style="font-size: 18px; font-weight: 800; margin-bottom: 6px;">No Chart Analyzed Yet</h3>
            <p class="muted" style="max-width: 480px; margin: 0 auto;">
                Upload a chart screenshot or press <kbd style="background: var(--bg-elevated); padding: 2px 6px; border-radius: 4px;">Ctrl+V</kbd> to paste directly from your clipboard. The institutional engine will identify key SMC/ICT levels with interactive visual chart overlays.
            </p>
        </div>

        <!-- Scanning State Loader -->
        <div id="visionLoader" class="vision-report-box hidden" style="text-align: center; padding: 60px 20px;">
            <div class="spinner" style="width: 40px; height: 40px; border: 4px solid rgba(245, 158, 11, 0.2); border-top-color: var(--gold); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 16px;"></div>
            <h3 style="font-size: 17px; font-weight: 800; color: var(--gold-bright);">Scanning Chart with AI Vision...</h3>
            <p class="muted" id="visionScanStep" style="font-size: 13px; margin-top: 6px;">Detecting candlestick swings &amp; liquidity pools...</p>
        </div>

        <!-- Result Box -->
        <div id="visionResultBox" class="vision-report-box hidden">
            <!-- Visual Chart Overlay Canvas -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h4 style="font-size: 14px; font-weight: 800;">Visual Chart Overlay</h4>
                <div style="display: flex; gap: 12px; font-size: 12px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" id="toggleOverlayEntry" checked> Entry Zone
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" id="toggleOverlaySl" checked> Stop Loss
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" id="toggleOverlayTp" checked> Take Profits
                    </label>
                </div>
            </div>

            <div class="vision-canvas-wrapper" id="canvasWrapper">
                <img id="overlayChartImg" src="" alt="Chart with overlay" style="width: 100%; display: block;">
                <canvas id="overlayCanvas" class="vision-overlay-canvas"></canvas>
            </div>

            <!-- Badges Row & Send to Telegram Action -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin: 12px 0 10px;">
                <div class="vision-badge-row" style="margin: 0; flex: 1;">
                    <span class="badge-bias" id="resBiasBadge">BULLISH</span>
                    <span class="badge-strat" id="resStrategyBadge">ICT 2022 Mentorship Model</span>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid #38bdf8;" id="resStratModeBadge">Mode: ALL</span>
                    <span class="badge" style="background: var(--bg-elevated); border: 1px solid var(--border);" id="resDirectionBadge">BUY</span>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981;" id="resScoreBadge">Confidence: 85/100</span>
                    <span class="badge" id="resModelBadge">Model: gemini-1.5-flash</span>
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

            <!-- 14-Point Report Tabs -->
            <div class="vision-tabs">
                <button type="button" class="vision-tab-btn active" data-tab="tab-structure">Structure &amp; Liquidity</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-thesis">Strategy Thesis</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-facts">Observed Facts vs Interpretations</button>
                <button type="button" class="vision-tab-btn" data-tab="tab-risk">Invalidations &amp; Risk</button>
            </div>

            <div id="tab-structure" class="vision-tab-content">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: var(--gold-bright);">Market Structure:</strong> <span id="resStructureVal">—</span>
                    <p class="muted" id="resStructureDesc" style="font-size: 12.5px; margin-top: 4px;"></p>
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

            <div id="tab-thesis" class="vision-tab-content hidden">
                <div style="background: var(--bg-elevated); padding: 12px 14px; border-radius: 6px; border: 1px solid var(--border); margin-bottom: 12px;">
                    <strong style="color: #c084fc;">Detected Model:</strong> <span id="resDetectedStratTitle">—</span>
                    <ul id="resEvidenceList" style="margin-left: 18px; margin-top: 8px; font-size: 12.5px; line-height: 1.6;"></ul>
                </div>
            </div>

            <div id="tab-facts" class="vision-tab-content hidden">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #38bdf8; font-size: 12px;">Observed Chart Facts (Visible)</strong>
                        <ul id="resFactsList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.5;"></ul>
                    </div>
                    <div style="background: var(--bg-elevated); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
                        <strong style="color: #f59e0b; font-size: 12px;">AI Interpretations (Probabilistic)</strong>
                        <ul id="resInterpList" style="margin-left: 18px; margin-top: 6px; font-size: 12px; line-height: 1.5;"></ul>
                    </div>
                </div>
            </div>

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

<!-- History Drawer Modal -->
<div id="historyModal" class="hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 100; display: flex; align-items: center; justify-content: center;">
    <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: var(--radius); width: 680px; max-width: 95vw; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <strong style="font-size: 16px;">📁 <?= I18n::t('recent_scans') ?></strong>
            <button type="button" class="btn btn-xs btn-ghost" id="closeHistoryModalBtn">✕</button>
        </div>
        <div id="historyListContainer" style="padding: 16px 20px; overflow-y: auto; flex: 1;">
            <div class="muted">Loading past analyses...</div>
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
    let currentImageFile = null;
    let currentBase64 = null;
    let currentRotation = 0;
    let currentAnalysisResult = null;

    const dropzone = document.getElementById('visionDropzone');
    const fileInput = document.getElementById('visionFileInput');
    const previewContainer = document.getElementById('visionPreviewContainer');
    const previewImg = document.getElementById('visionPreviewImg');
    const analyzeBtn = document.getElementById('analyzeBtn');
    const rotateBtn = document.getElementById('rotateBtn');
    const replaceBtn = document.getElementById('replaceBtn');
    const removeBtn = document.getElementById('removeBtn');

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
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let item of items) {
            if (item.type.indexOf('image') !== -1) {
                const blob = item.getAsFile();
                handleFile(blob);
                break;
            }
        }
    });

    function handleFile(file) {
        if (!file.type.match(/^image\/(png|jpe?g|webp)$/i)) {
            alert('Please select a PNG, JPG, JPEG, or WEBP chart image.');
            return;
        }
        currentImageFile = file;
        currentRotation = 0;

        const reader = new FileReader();
        reader.onload = (e) => {
            currentBase64 = e.target.result;
            document.getElementById('base64Payload').value = currentBase64;
            previewImg.src = currentBase64;
            previewImg.style.transform = 'rotate(0deg)';
            dropzone.classList.add('hidden');
            previewContainer.classList.remove('hidden');
            analyzeBtn.disabled = false;
        };
        reader.readAsDataURL(file);
    }

    rotateBtn.addEventListener('click', () => {
        currentRotation = (currentRotation + 90) % 360;
        previewImg.style.transform = `rotate(${currentRotation}deg)`;
    });

    replaceBtn.addEventListener('click', () => {
        fileInput.click();
    });

    removeBtn.addEventListener('click', () => {
        currentImageFile = null;
        currentBase64 = null;
        fileInput.value = '';
        document.getElementById('base64Payload').value = '';
        previewImg.src = '';
        previewContainer.classList.add('hidden');
        dropzone.classList.remove('hidden');
        analyzeBtn.disabled = true;
    });

    // Analyze Action
    analyzeBtn.addEventListener('click', async () => {
        if (!currentImageFile && !currentBase64) return;

        const formData = new FormData(document.getElementById('visionForm'));
        if (currentImageFile) {
            formData.set('chart_image', currentImageFile);
        }

        const placeholder = document.getElementById('visionPlaceholder');
        const loader = document.getElementById('visionLoader');
        const resultBox = document.getElementById('visionResultBox');

        placeholder.classList.add('hidden');
        resultBox.classList.add('hidden');
        loader.classList.remove('hidden');
        analyzeBtn.disabled = true;

        const steps = [
            'Detecting candlestick swings & liquidity pools...',
            'Tracing Market Structure Shifts (MSS) & displacement...',
            'Identifying Fair Value Gaps (FVG) and Order Blocks...',
            'Synthesizing ICT strategy & risk-reward parameters...'
        ];
        let stepIdx = 0;
        const interval = setInterval(() => {
            stepIdx = (stepIdx + 1) % steps.length;
            document.getElementById('visionScanStep').textContent = steps[stepIdx];
        }, 800);

        try {
            const res = await fetch('api/vision_analyze.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: formData,
            });
            const data = await res.json();
            clearInterval(interval);
            loader.classList.add('hidden');
            analyzeBtn.disabled = false;

            if (!data.ok) {
                alert('Analysis Error: ' + (data.error || 'Failed to analyze chart screenshot.'));
                placeholder.classList.remove('hidden');
                return;
            }

            currentAnalysisResult = data;
            renderAnalysisResult(data);
        } catch (err) {
            clearInterval(interval);
            loader.classList.add('hidden');
            analyzeBtn.disabled = false;
            placeholder.classList.remove('hidden');
            alert('Network error: ' + err.message);
        }
    });

    function renderAnalysisResult(data) {
        const report = data.report;
        const overlay = data.overlay;
        const resultBox = document.getElementById('visionResultBox');
        resultBox.classList.remove('hidden');

        // Badges
        const biasBadge = document.getElementById('resBiasBadge');
        biasBadge.textContent = report.market_bias.toUpperCase();
        biasBadge.className = 'badge-bias ' + report.market_bias.toLowerCase();

        document.getElementById('resStrategyBadge').textContent = report.detected_strategy || 'SMC/ICT Confluence';
        const dirBadge = document.getElementById('resDirectionBadge');
        dirBadge.textContent = report.trade_direction;
        dirBadge.className = 'badge ' + (report.trade_direction === 'BUY' ? 'badge-live' : (report.trade_direction === 'SELL' ? 'badge-warn' : ''));

        document.getElementById('resScoreBadge').textContent = `Confidence: ${report.confidence_score}/100`;
        document.getElementById('resModelBadge').textContent = `Model: ${data.ai_model}`;
        if (document.getElementById('resStratModeBadge')) {
            document.getElementById('resStratModeBadge').textContent = `Mode: ${report.strategy_mode || document.getElementById('strategyModeInput').value || 'ALL'}`;
        }

        // Level Cards
        const ez = report.entry_zone || {};
        document.getElementById('resEntryVal').textContent = ez.min && ez.max
            ? `$${Number(ez.min).toFixed(2)} – $${Number(ez.max).toFixed(2)}`
            : `$${Number(ez.ref_entry || 0).toFixed(2)}`;

        document.getElementById('resSlVal').textContent = report.stop_loss ? `$${Number(report.stop_loss.price).toFixed(2)}` : '—';
        document.getElementById('resTp1Val').textContent = report.take_profit && report.take_profit.tp1 ? `$${Number(report.take_profit.tp1.price).toFixed(2)}` : '—';
        document.getElementById('resTp2Val').textContent = report.take_profit && report.take_profit.tp2 ? `$${Number(report.take_profit.tp2.price).toFixed(2)}` : '—';
        document.getElementById('resTp3Val').textContent = report.take_profit && report.take_profit.tp3 ? `$${Number(report.take_profit.tp3.price).toFixed(2)}` : '—';

        // Structure Tab
        document.getElementById('resStructureVal').textContent = report.market_structure || 'Consolidation';
        document.getElementById('resStructureDesc').textContent = report.structure_details || '';

        const bslList = document.getElementById('resBslList');
        bslList.innerHTML = '';
        (report.liquidity_targets?.bsl || []).forEach(l => {
            const li = document.createElement('li');
            li.textContent = `$${Number(l.level).toFixed(2)} (${l.label})`;
            bslList.appendChild(li);
        });

        const sslList = document.getElementById('resSslList');
        sslList.innerHTML = '';
        (report.liquidity_targets?.ssl || []).forEach(l => {
            const li = document.createElement('li');
            li.textContent = `$${Number(l.level).toFixed(2)} (${l.label})`;
            sslList.appendChild(li);
        });

        // Thesis Tab
        document.getElementById('resDetectedStratTitle').textContent = report.detected_strategy;
        const evList = document.getElementById('resEvidenceList');
        evList.innerHTML = '';
        (report.supporting_evidence || []).forEach(e => {
            const li = document.createElement('li');
            li.textContent = e;
            evList.appendChild(li);
        });

        // Facts vs Interpretations
        const factsList = document.getElementById('resFactsList');
        factsList.innerHTML = '';
        (report.observed_facts || []).forEach(f => {
            const li = document.createElement('li');
            li.textContent = f;
            factsList.appendChild(li);
        });

        const interpList = document.getElementById('resInterpList');
        interpList.innerHTML = '';
        (report.supporting_evidence || []).forEach(i => {
            const li = document.createElement('li');
            li.textContent = i;
            interpList.appendChild(li);
        });

        // Invalidation & Risk
        const invList = document.getElementById('resInvalidationList');
        invList.innerHTML = '';
        (report.setup_invalidations || []).forEach(inv => {
            const li = document.createElement('li');
            li.textContent = inv;
            invList.appendChild(li);
        });

        const avoidList = document.getElementById('resAvoidList');
        avoidList.innerHTML = '';
        (report.reasons_to_avoid || []).concat(report.uncertainties_warnings || []).forEach(a => {
            const li = document.createElement('li');
            li.textContent = a;
            avoidList.appendChild(li);
        });

        // Render Canvas Overlay
        const overlayImg = document.getElementById('overlayChartImg');
        overlayImg.src = data.image_url;
        overlayImg.onload = () => {
            drawOverlay(overlay);
        };
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
        const scaleX = canvas.width / (overlay.width || canvas.width);

        const showEntry = document.getElementById('toggleOverlayEntry').checked;
        const showSl = document.getElementById('toggleOverlaySl').checked;
        const showTp = document.getElementById('toggleOverlayTp').checked;

        (overlay.elements || []).forEach(el => {
            if (el.type === 'zone' && showEntry) {
                const y1 = el.y1 * scaleY;
                const y2 = el.y2 * scaleY;
                ctx.fillStyle = el.color;
                ctx.fillRect(0, y1, canvas.width, Math.max(8, y2 - y1));
                ctx.strokeStyle = el.stroke;
                ctx.lineWidth = 1.5;
                ctx.strokeRect(0, y1, canvas.width, Math.max(8, y2 - y1));

                // Label tag
                ctx.fillStyle = 'rgba(0,0,0,0.7)';
                ctx.fillRect(10, y1 - 18, 220, 18);
                ctx.fillStyle = el.stroke;
                ctx.font = 'bold 11px JetBrains Mono, monospace';
                ctx.fillText(el.label, 14, y1 - 5);
            }
            if (el.type === 'line') {
                const isSl = el.label.includes('STOP LOSS');
                if ((isSl && !showSl) || (!isSl && !showTp)) return;

                const y = el.y * scaleY;
                ctx.strokeStyle = el.color;
                ctx.lineWidth = 2;
                if (el.dash) ctx.setLineDash(el.dash); else ctx.setLineDash([]);
                ctx.beginPath();
                ctx.moveTo(0, y);
                ctx.lineTo(canvas.width, y);
                ctx.stroke();
                ctx.setLineDash([]);

                // Label badge
                ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
                ctx.fillRect(canvas.width - 200, y - 10, 190, 20);
                ctx.strokeStyle = el.color;
                ctx.strokeRect(canvas.width - 200, y - 10, 190, 20);
                ctx.fillStyle = el.color;
                ctx.font = 'bold 11px JetBrains Mono, monospace';
                ctx.fillText(el.label, canvas.width - 192, y + 4);
            }
        });
    }

    ['toggleOverlayEntry', 'toggleOverlaySl', 'toggleOverlayTp'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            if (currentAnalysisResult && currentAnalysisResult.overlay) {
                drawOverlay(currentAnalysisResult.overlay);
            }
        });
    });

    // Tab buttons
    document.querySelectorAll('.vision-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.vision-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const targetId = btn.dataset.tab;
            document.querySelectorAll('.vision-tab-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(targetId).classList.remove('hidden');
        });
    });

    // History Modal
    const historyModal = document.getElementById('historyModal');
    document.getElementById('openHistoryBtn').addEventListener('click', async () => {
        historyModal.classList.remove('hidden');
        const container = document.getElementById('historyListContainer');
        container.innerHTML = '<div class="muted">Loading past scans...</div>';

        try {
            const res = await fetch('api/vision_history.php');
            const data = await res.json();
            if (!data.ok || !data.items || data.items.length === 0) {
                container.innerHTML = '<div class="muted">No past chart scans found. Upload a chart to get started.</div>';
                return;
            }
            container.innerHTML = '';
            data.items.forEach(item => {
                const div = document.createElement('div');
                div.style.cssText = 'background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 6px; padding: 12px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer;';
                div.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <img src="${item.image_url}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border);" onerror="this.style.display='none'">
                        <div>
                            <strong>${item.symbol} (${item.timeframe})</strong> — <span class="badge ${item.market_bias === 'Bullish' ? 'badge-live' : (item.market_bias === 'Bearish' ? 'badge-warn' : '')}">${item.market_bias}</span>
                            <div class="muted" style="font-size: 11.5px; margin-top: 2px;">${item.detected_strategy} · Score: ${item.confidence_score}/100 · ${item.created_at} UTC</div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-xs btn-primary load-item-btn">Load Analysis</button>
                        <button type="button" class="btn btn-xs send-history-tg-btn" style="background: #229ed9; color: #fff; border: none; font-weight: 600;" title="Send this chart to Telegram">✈️ Telegram</button>
                    </div>
                `;
                div.querySelector('.load-item-btn').addEventListener('click', (e) => {
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

                div.querySelector('.send-history-tg-btn').addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const btn = e.currentTarget;
                    const origText = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '⏳...';
                    try {
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const res = await fetch('api/vision_telegram.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ analysis_id: item.id, _csrf: csrf })
                        });
                        const resData = await res.json();
                        if (resData.ok) {
                            btn.style.background = '#10b981';
                            btn.innerHTML = '✅ Sent';
                            setTimeout(() => { btn.innerHTML = origText; btn.style.background = '#229ed9'; btn.disabled = false; }, 3000);
                        } else {
                            alert('Telegram Error: ' + (resData.error || 'Failed to send to Telegram.'));
                            btn.innerHTML = origText;
                            btn.disabled = false;
                        }
                    } catch (err) {
                        alert('Network error: ' + err.message);
                        btn.innerHTML = origText;
                        btn.disabled = false;
                    }
                });

                container.appendChild(div);
            });
        } catch (err) {
            container.innerHTML = `<div class="text-bear">Failed to load history: ${err.message}</div>`;
        }
    });

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

            const origHtml = sendTelegramBtn.innerHTML;
            sendTelegramBtn.disabled = true;
            sendTelegramBtn.style.opacity = '0.7';
            sendTelegramBtn.innerHTML = `
                <div class="spinner" style="width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.6s linear infinite; display: inline-block;"></div>
                <span><?= I18n::t('sending_to_telegram') ?></span>
            `;

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
                    sendTelegramBtn.innerHTML = `<span>✅ <?= I18n::t('sent_to_telegram') ?></span>`;
                    setTimeout(() => {
                        sendTelegramBtn.innerHTML = origHtml;
                        sendTelegramBtn.style.background = '#229ed9';
                        sendTelegramBtn.style.borderColor = '#229ed9';
                        sendTelegramBtn.disabled = false;
                        sendTelegramBtn.style.opacity = '1';
                    }, 3500);
                } else {
                    alert('Telegram Send Error: ' + (data.error || 'Failed to dispatch telegram notification.'));
                    sendTelegramBtn.innerHTML = origHtml;
                    sendTelegramBtn.disabled = false;
                    sendTelegramBtn.style.opacity = '1';
                }
            } catch (err) {
                alert('Network error sending to Telegram: ' + err.message);
                sendTelegramBtn.innerHTML = origHtml;
                sendTelegramBtn.disabled = false;
                sendTelegramBtn.style.opacity = '1';
            }
        });
    }
});
</script>

<?php
renderFooter();
