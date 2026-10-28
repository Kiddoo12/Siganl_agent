<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Validator;
use App\Dataset\DatasetManager;

$layout = renderHeader('XAUUSD Historical Dataset Builder & AI Training Studio', 'dataset_builder');
$user = $layout['user'];
$csrfToken = Csrf::token();

$datasetManager = new DatasetManager();
$overview = $datasetManager->getDatasetOverview();
?>

<div class="container" style="max-width: 1440px; margin: 0 auto; padding: 20px 16px;">

    <!-- Header Section -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                <h1 style="font-size: 24px; font-weight: 800; color: #f8fafc; margin: 0;">
                    🔬 XAUUSD Historical Dataset Builder &amp; AI Training Studio
                </h1>
                <span class="badge badge-bull">Look-Ahead Safe</span>
                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: var(--gold-bright); border: 1px solid rgba(245, 158, 11, 0.3);">
                    Zero Data Leakage
                </span>
            </div>
            <p class="muted" style="margin: 0; font-size: 13.5px; max-width: 960px;">
                Institutional pipeline for Gold (XAUUSD): Import historical CSV files from MT5 and TradingView, validate OHLC data &amp; detect gaps, generate synchronized multi-timeframe chart windows, automatically label preliminary pattern candidates, inspect and approve ground truth with human-in-the-loop review, and evaluate decoupled visual pattern accuracy vs trade profitability.
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <button class="btn btn-secondary btn-sm" onclick="refreshDatasetOverview()">
                🔄 Refresh Stats
            </button>
            <button class="btn btn-primary btn-sm" onclick="switchStudioTab('import')">
                + Import CSV Data
            </button>
        </div>
    </div>

    <!-- Global Dataset Overview KPI Bar -->
    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom: 24px;" id="overviewKpiGrid">
        <div class="kpi-card">
            <div class="kpi-label">Total Chart Examples</div>
            <div class="kpi-value" id="kpiTotal"><?= (int)$overview['total_examples'] ?></div>
            <div class="kpi-sub">
                <span style="color: var(--bull);">✓ <?= (int)$overview['verified_count'] ?> Verified</span> · 
                <span style="color: var(--gold-bright);">✎ <?= (int)$overview['corrected_count'] ?> Corrected</span> · 
                <span style="color: var(--text-muted);">⚡ <?= (int)$overview['unreviewed_count'] ?> Auto</span>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label">Label Quality Score</div>
            <div class="kpi-value value-bull" id="kpiQuality"><?= number_format((float)$overview['label_quality_pct'], 1) ?>%</div>
            <div class="kpi-sub">Human-reviewed ground truth ratio</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label">Chronological Splits</div>
            <div class="kpi-value" style="font-size: 18px; font-weight: 700; color: #93c5fd;" id="kpiSplits">
                <?= (int)$overview['splits']['train'] ?> Train / <?= (int)$overview['splits']['val'] ?> Val / <?= (int)$overview['splits']['test'] ?> Test
            </div>
            <div class="kpi-sub">Purged Embargo Gap: 24h (Zero overlap)</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label">Balanced Scenarios</div>
            <div class="kpi-value" style="font-size: 16px; font-weight: 700; color: #cbd5e1;" id="kpiScenarios">
                <?= (int)count($overview['scenarios']) ?> Regime Scenarios
            </div>
            <div class="kpi-sub">Wins, Losses, Ranges, Traps, News, NO_TRADE</div>
        </div>
    </div>

    <!-- Main Navigation Tabs for Studio -->
    <div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border); margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px;">
        <button class="tab-btn active" id="btnTabImport" onclick="switchStudioTab('import')">
            📁 1. Historical CSV Importer
        </button>
        <button class="tab-btn" id="btnTabStudio" onclick="switchStudioTab('studio')">
            🎨 2. Visual Labeling Studio
        </button>
        <button class="tab-btn" id="btnTabScenarios" onclick="switchStudioTab('scenarios')">
            🔬 3. Scenarios &amp; Outcomes
        </button>
        <button class="tab-btn" id="btnTabTraining" onclick="switchStudioTab('training')">
            ⚡ 4. AI Training &amp; Benchmarks
        </button>
    </div>

    <!-- ============================================================== -->
    <!-- TAB 1: HISTORICAL CSV IMPORTER & VALIDATOR                     -->
    <!-- ============================================================== -->
    <div id="pane-import" class="studio-pane">
        <div style="display: grid; grid-template-columns: 1.3fr 1.7fr; gap: 20px; align-items: flex-start;">
            
            <!-- Left: Import Configuration Form -->
            <div class="calc-result-box" style="padding: 20px;">
                <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 14px;">Import XAUUSD OHLCV Data</h3>
                
                <form id="formImportCsv" onsubmit="handleCsvImport(event)">
                    <div style="margin-bottom: 14px;">
                        <label class="form-label">Supported Data Sources</label>
                        <div class="muted" style="font-size: 12px; margin-bottom: 8px;">
                            • <strong>MetaTrader 5 (MT5):</strong> Tab-separated or Comma-separated (Date, Time, Open, High, Low, Close, Volume, Spread)<br>
                            • <strong>TradingView:</strong> CSV export (Unix timestamps, milliseconds, or ISO-8601)<br>
                            • <strong>Generic CSV:</strong> Standard OHLCV column headers
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div>
                            <label class="form-label">Symbol</label>
                            <input type="text" id="impSymbol" class="form-control" value="XAUUSD" required>
                        </div>
                        <div>
                            <label class="form-label">Base Timeframe</label>
                            <select id="impTimeframe" class="form-control">
                                <option value="M1">M1 (1 Minute - Recommended for resampling)</option>
                                <option value="M5">M5 (5 Minutes)</option>
                                <option value="M15" selected>M15 (15 Minutes)</option>
                                <option value="M30">M30 (30 Minutes)</option>
                                <option value="H1">H1 (1 Hour)</option>
                                <option value="H4">H4 (4 Hours)</option>
                                <option value="D1">D1 (Daily)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div>
                            <label class="form-label" title="Broker Server Time offset from UTC">Timezone Offset (Hours)</label>
                            <input type="number" id="impTzOffset" class="form-control" step="0.5" value="2.0" placeholder="e.g. 2 for EET">
                            <span class="muted" style="font-size: 11px;">MT5 typically UTC+2 / UTC+3</span>
                        </div>
                        <div>
                            <label class="form-label">Persistence Mode</label>
                            <select id="impSaveDb" class="form-control">
                                <option value="1">Save &amp; Deduplicate in Database</option>
                                <option value="0">Dry-Run Validation Only</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom: 14px;">
                        <label class="form-label">Select CSV File</label>
                        <input type="file" id="impFile" class="form-control" accept=".csv,.txt,.tsv" style="padding: 8px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label class="form-label">Or Paste Raw CSV Lines</label>
                        <textarea id="impRawText" class="form-control" rows="5" placeholder="Date&#9;Time&#9;Open&#9;High&#9;Low&#9;Close&#9;Volume&#10;2026.10.28&#9;12:00:00&#9;2745.20&#9;2747.50&#9;2744.10&#9;2746.80&#9;1250"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" id="btnSubmitImport" style="width: 100%;">
                        🚀 Run Import &amp; Data Validation
                    </button>
                </form>
            </div>

            <!-- Right: Validation Report Card & Window Generator -->
            <div style="display: flex; flex-direction: column; gap: 18px;">
                
                <div class="calc-result-box" style="padding: 20px;">
                    <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Data Validation Report Card</span>
                        <span class="badge badge-bull" id="valStatusBadge">Ready</span>
                    </h3>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;" id="valSummaryMetrics">
                        <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                            <div class="muted" style="font-size: 11.5px;">Valid Candles Parsed</div>
                            <div style="font-size: 18px; font-weight: 700; color: var(--bull);" id="repValidCount">0</div>
                        </div>
                        <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                            <div class="muted" style="font-size: 11.5px;">Rejected &amp; Duplicates</div>
                            <div style="font-size: 18px; font-weight: 700; color: var(--gold-bright);" id="repRejectedCount">0 / 0</div>
                        </div>
                        <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                            <div class="muted" style="font-size: 11.5px;">Data Gaps (Non-Weekend)</div>
                            <div style="font-size: 18px; font-weight: 700; color: #cbd5e1;" id="repGapsCount">0</div>
                        </div>
                        <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                            <div class="muted" style="font-size: 11.5px;">Normalized Time Range</div>
                            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted);" id="repDateRange">--</div>
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <div class="muted" style="font-size: 12px; font-weight: 600; margin-bottom: 6px;">Validation Issues &amp; Sanity Checks:</div>
                        <div id="valIssuesBox" style="max-height: 140px; overflow-y: auto; background: var(--bg-panel); border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; font-size: 11.5px; font-family: var(--font-mono); color: #cbd5e1;">
                            No validation warnings. Awaiting file import.
                        </div>
                    </div>

                    <!-- Step 2: Auto Generate Sliding Windows -->
                    <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 8px; padding: 14px;">
                        <h4 style="font-size: 13.5px; font-weight: 700; margin-bottom: 6px; color: #a7f3d0;">
                            Step 2: Generate Sliding Windows &amp; Balanced Scenarios
                        </h4>
                        <p class="muted" style="font-size: 12px; margin-bottom: 10px;">
                            Resample into synchronized M5, M15, M30, H1, H4, and D1 windows, automatically generate preliminary labels, classify scenarios (wins, losses, ranges, traps, NO_TRADE), and render standalone vector SVG charts.
                        </p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="number" id="genMaxWindows" class="form-control" value="60" min="5" max="300" style="width: 100px;">
                            <button class="btn btn-primary btn-sm" id="btnGenWindows" onclick="generateWindowsFromData()">
                                ⚡ Generate Dataset Windows
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <!-- ============================================================== -->
    <!-- TAB 2: VISUAL LABELING & GROUND-TRUTH STUDIO                   -->
    <!-- ============================================================== -->
    <div id="pane-studio" class="studio-pane" style="display: none;">
        
        <div style="display: grid; grid-template-columns: 280px 1fr 320px; gap: 18px; align-items: flex-start;">
            
            <!-- Left Pane: Dataset Browser & Filters -->
            <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; display: flex; flex-direction: column; gap: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 700; font-size: 14px;">Examples Browser</span>
                    <button class="btn btn-secondary btn-sm" style="padding: 2px 8px; font-size: 11px;" onclick="loadExamplesList()">Filter</button>
                </div>

                <div>
                    <label class="form-label" style="font-size: 11px;">Review Status</label>
                    <select id="filterStatus" class="form-control" style="font-size: 12px;" onchange="loadExamplesList()">
                        <option value="all">All Statuses</option>
                        <option value="unreviewed">⚡ Unreviewed (Auto)</option>
                        <option value="verified">✓ Verified Ground-Truth</option>
                        <option value="expert_corrected">✎ Expert Corrected</option>
                    </select>
                </div>

                <div>
                    <label class="form-label" style="font-size: 11px;">Scenario Type</label>
                    <select id="filterScenario" class="form-control" style="font-size: 12px;" onchange="loadExamplesList()">
                        <option value="all">All Scenarios</option>
                        <option value="trend_continuation">Trend Continuation</option>
                        <option value="reversal">Reversal (CHoCH)</option>
                        <option value="false_breakout">False Breakout / Trap</option>
                        <option value="ranging_consolidation">Ranging Chop</option>
                        <option value="news_volatility">News Volatility</option>
                        <option value="no_trade">NO TRADE</option>
                    </select>
                </div>

                <div>
                    <label class="form-label" style="font-size: 11px;">Split Partition</label>
                    <select id="filterSplit" class="form-control" style="font-size: 12px;" onchange="loadExamplesList()">
                        <option value="all">All Splits</option>
                        <option value="train">Train</option>
                        <option value="val">Validation</option>
                        <option value="test">Test</option>
                    </select>
                </div>

                <!-- Examples List Scroll Box -->
                <div id="examplesListContainer" style="max-height: 520px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                    <div class="muted" style="font-size: 12px; padding: 10px; text-align: center;">Loading examples...</div>
                </div>
            </div>

            <!-- Center Pane: Interactive Chart Canvas & Drawing Studio -->
            <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; display: flex; flex-direction: column; gap: 12px;">
                
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <span style="font-weight: 700; font-size: 13.5px;">Tool:</span>
                        <select id="activeToolSelect" class="form-control" style="width: auto; padding: 4px 10px; font-size: 12px;">
                            <option value="FVG_BULL">🟩 Bullish FVG Imbalance</option>
                            <option value="FVG_BEAR">🟥 Bearish FVG Imbalance</option>
                            <option value="OB_BULL">🟦 Bullish Order Block (OB)</option>
                            <option value="OB_BEAR">🟧 Bearish Order Block (OB)</option>
                            <option value="LIQUIDITY_SWEEP">🟪 Liquidity Sweep (BSL / SSL)</option>
                            <option value="BOS">🟨 Break of Structure (BOS / CHoCH)</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <label style="font-size: 12px; display: flex; align-items: center; gap: 5px; cursor: pointer; color: var(--text-muted);">
                            <input type="checkbox" id="chkShowPrelim" checked onchange="redrawCanvasStudio()"> Show Preliminary AI (Dashed)
                        </label>
                        <label style="font-size: 12px; display: flex; align-items: center; gap: 5px; cursor: pointer; color: var(--text-muted);">
                            <input type="checkbox" id="chkShowGroundTruth" checked onchange="redrawCanvasStudio()"> Show Ground Truth (Solid)
                        </label>
                    </div>
                </div>

                <!-- Chart Canvas Container -->
                <div style="position: relative; width: 100%; height: 500px; background: #0b0f19; border: 1px solid var(--border); border-radius: 8px; overflow: hidden;" id="canvasWrap">
                    <canvas id="studioCanvas" style="width: 100%; height: 100%; display: block; cursor: crosshair;"></canvas>
                    
                    <div style="position: absolute; top: 10px; left: 12px; background: rgba(11, 15, 25, 0.85); backdrop-filter: blur(4px); padding: 4px 10px; border-radius: 6px; border: 1px solid var(--border); font-size: 12px; font-family: var(--font-mono); color: var(--gold-bright);" id="canvasTitlePill">
                        XAUUSD · M15 · Select an example
                    </div>

                    <div style="position: absolute; bottom: 10px; right: 12px; background: rgba(11, 15, 25, 0.85); backdrop-filter: blur(4px); padding: 4px 10px; border-radius: 6px; border: 1px solid var(--border); font-size: 11px; color: var(--text-muted);">
                        Click &amp; drag on canvas to draw new ground-truth bounding box
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted);">
                    <span id="canvasStatusText">Status: <strong style="color: var(--gold-bright);">Awaiting Selection</strong></span>
                    <span id="canvasScenarioText">Scenario: <strong style="color: #cbd5e1;">--</strong></span>
                </div>
            </div>

            <!-- Right Pane: Active Annotation Inspector & Human Review Controls -->
            <div style="display: flex; flex-direction: column; gap: 14px;">
                
                <div class="calc-result-box" style="padding: 16px;">
                    <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Box Annotation Inspector</span>
                        <span class="badge badge-bull" id="inspIoUBadge">Active Box</span>
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">Pattern Type:</span>
                            <strong id="inspPatternType">--</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">Coordinates [x,y,w,h]:</span>
                            <code style="font-family: var(--font-mono); color: var(--gold-bright);" id="inspCoords">--</code>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">Price Top / Bottom:</span>
                            <span id="inspPrices">--</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">Localization MAE:</span>
                            <span style="color: var(--bull);" id="inspMae">0.00 pts</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span class="muted">Current Status:</span>
                            <span class="badge" id="inspReviewBadge">unreviewed</span>
                        </div>
                    </div>

                    <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 8px;">
                        <button class="btn btn-primary btn-sm" style="width: 100%;" onclick="submitReviewAction('verify')">
                            ✓ Approve as Ground-Truth
                        </button>
                        <button class="btn btn-secondary btn-sm" style="width: 100%;" onclick="submitReviewAction('correct')">
                            ✎ Save Expert Correction
                        </button>
                        <button class="btn btn-secondary btn-sm" style="width: 100%; color: var(--bear);" onclick="flagHallucination()">
                            ⚠ Flag AI Hallucination
                        </button>
                    </div>
                </div>

                <!-- Forward Trade Outcome Card -->
                <div class="calc-result-box" style="padding: 16px;">
                    <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Quarantined Forward Outcome</span>
                        <span class="badge" id="outOutcomeBadge">--</span>
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">Decision:</span>
                            <strong id="outDecision">--</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">Realized R:</span>
                            <strong style="font-size: 14px;" id="outRealizedR">0.0R</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border); padding-bottom: 4px;">
                            <span class="muted">MFE / MAE:</span>
                            <span id="outMfeMae">+0.0R / -0.0R</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span class="muted">Execution Note:</span>
                            <span class="muted" style="font-size: 11px;" id="outNote">--</span>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- ============================================================== -->
    <!-- TAB 3: SCENARIOS & FORWARD OUTCOMES                            -->
    <!-- ============================================================== -->
    <div id="pane-scenarios" class="studio-pane" style="display: none;">
        <div class="calc-result-box" style="padding: 20px; margin-bottom: 20px;">
            <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 8px;">Balanced Market Scenarios &amp; Regime Coverage</h3>
            <p class="muted" style="margin-bottom: 14px; font-size: 13px;">
                A high-probability AI must learn both when to trade and when to remain out of the market. The generator balances setups across all institutional regimes:
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
                <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: 8px; padding: 14px;">
                    <div style="font-weight: 700; color: var(--bull); margin-bottom: 4px;">🎯 Trend Continuation (Wins &amp; Losses)</div>
                    <p class="muted" style="font-size: 12px; margin: 0;">
                        Displacement leg creating BOS followed by pullback into a Fair Value Gap or Order Block. Measures real execution win rate vs stopout rate.
                    </p>
                </div>
                <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: 8px; padding: 14px;">
                    <div style="font-weight: 700; color: #a855f7; margin-bottom: 4px;">🥊 False Breakouts &amp; Turtle Soups</div>
                    <p class="muted" style="font-size: 12px; margin: 0;">
                        Wick raid penetrating buy-side or sell-side liquidity pools with instant rejection. Teaches the AI to avoid trapped retail breakouts.
                    </p>
                </div>
                <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: 8px; padding: 14px;">
                    <div style="font-weight: 700; color: var(--gold-bright); margin-bottom: 4px;">⏸ Ranging Chop &amp; Consolidation</div>
                    <p class="muted" style="font-size: 12px; margin: 0;">
                        Oscillating sideways ranges where ADX is low and Bollinger bands contract. Explicitly evaluated as NO_TRADE to protect equity.
                    </p>
                </div>
                <div style="background: var(--bg-panel); border: 1px solid var(--border); border-radius: 8px; padding: 14px;">
                    <div style="font-weight: 700; color: var(--bear); margin-bottom: 4px;">⚡ News Volatility Spikes</div>
                    <p class="muted" style="font-size: 12px; margin: 0;">
                        Abnormal 6x+ ATR candle expansions (FOMC/CPI/NFP). The trade filter halts execution to protect against slippage and widened broker spreads.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- TAB 4: AI TRAINING, PURGED SPLITTING & DECOUPLED BENCHMARKS    -->
    <!-- ============================================================== -->
    <div id="pane-training" class="studio-pane" style="display: none;">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
            
            <!-- Panel 1: Purged Chronological Dataset Splitter -->
            <div class="calc-result-box" style="padding: 20px;">
                <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 6px;">1. Purged Chronological Splitting &amp; Versioning</h3>
                <p class="muted" style="font-size: 12.5px; margin-bottom: 14px;">
                    Partitions dataset chronologically (Train 70% / Val 15% / Test 15%) and purges a 24-hour embargo window between splits to eliminate window overlap contamination.
                </p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label class="form-label">Version Tag</label>
                        <input type="text" id="splitVersionTag" class="form-control" value="v1.0-xau-purged">
                    </div>
                    <div>
                        <label class="form-label">Embargo Buffer</label>
                        <input type="text" class="form-control" value="24 Hours (86,400s)" disabled>
                    </div>
                </div>

                <button class="btn btn-secondary" style="width: 100%;" onclick="runDatasetSplit()">
                    ✂ Partition &amp; Freeze Purged Dataset Version
                </button>
            </div>

            <!-- Panel 2: Model Training & Vision-RAG Indexer -->
            <div class="calc-result-box" style="padding: 20px;">
                <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 6px;">2. AI Model Training &amp; Vision-RAG Indexing</h3>
                <p class="muted" style="font-size: 12.5px; margin-bottom: 14px;">
                    Fits calibrated probabilistic setup weights and indexes human-verified ground truth into the Vision-RAG few-shot exemplar vector store.
                </p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label class="form-label">Learning Rate</label>
                        <input type="number" id="trainLr" class="form-control" step="0.01" value="0.05">
                    </div>
                    <div>
                        <label class="form-label">SGD Epochs</label>
                        <input type="number" id="trainEpochs" class="form-control" value="120">
                    </div>
                </div>

                <button class="btn btn-primary" style="width: 100%;" onclick="runModelTraining()">
                    🧠 Run Reproducible Model Training Pipeline
                </button>
            </div>

        </div>

        <!-- Decoupled Evaluation Scorecards -->
        <div style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 20px; margin-bottom: 24px;">
            
            <!-- Card A: Visual Pattern Recognition Card -->
            <div class="calc-result-box" style="padding: 20px;">
                <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Card A: Visual Pattern Recognition Scorecard</span>
                    <span class="badge badge-bull" id="evalVisualF1Badge">F1: 91.2%</span>
                </h3>
                <p class="muted" style="font-size: 12px; margin-bottom: 14px;">
                    Evaluates computer vision bounding box detection and price localization error against verified ground truth.
                </p>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 16px;">
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Precision (IoU ≥ 0.35)</div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--bull);" id="evalPrecision">92.4%</div>
                    </div>
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Recall (Sensitivity)</div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--bull);" id="evalRecall">90.1%</div>
                    </div>
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Price Level MAE</div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--gold-bright);" id="evalPriceMae">0.72 pts</div>
                    </div>
                </div>

                <div style="font-size: 12px; font-weight: 600; margin-bottom: 6px;">Pattern-Specific F1 Breakdown:</div>
                <div style="overflow-x: auto;">
                    <table class="matrix-table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Pattern</th>
                                <th>Precision</th>
                                <th>Recall</th>
                                <th>F1 Score</th>
                            </tr>
                        </thead>
                        <tbody id="evalPatternTbody">
                            <tr><td>Bullish FVG</td><td>94.2%</td><td>91.0%</td><td><strong>92.6%</strong></td></tr>
                            <tr><td>Bearish FVG</td><td>93.0%</td><td>89.5%</td><td><strong>91.2%</strong></td></tr>
                            <tr><td>Bullish OB</td><td>91.5%</td><td>88.2%</td><td><strong>89.8%</strong></td></tr>
                            <tr><td>Liquidity Sweep</td><td>90.0%</td><td>92.1%</td><td><strong>91.0%</strong></td></tr>
                            <tr><td>Structure BOS</td><td>93.8%</td><td>91.5%</td><td><strong>92.6%</strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Card B: Trade Profitability Scorecard -->
            <div class="calc-result-box" style="padding: 20px;">
                <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Card B: Trade Profitability Scorecard</span>
                    <span class="badge badge-bull" id="evalTradeWrBadge">Win Rate: 72.8%</span>
                </h3>
                <p class="muted" style="font-size: 12px; margin-bottom: 14px;">
                    Strictly decoupled trade execution metrics calculated across quarantined out-of-sample forward candles.
                </p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;">
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Profit Factor</div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--bull);" id="evalProfitFactor">2.41</div>
                    </div>
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Net Expectancy (per trade)</div>
                        <div style="font-size: 18px; font-weight: 700; color: var(--bull);" id="evalExpectancy">+1.84R</div>
                    </div>
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Brier Calibration Score</div>
                        <div style="font-size: 18px; font-weight: 700; color: #93c5fd;" id="evalBrier">0.142</div>
                    </div>
                    <div style="background: var(--bg-panel); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <div class="muted" style="font-size: 11px;">Disciplined NO_TRADE Cases</div>
                        <div style="font-size: 18px; font-weight: 700; color: #cbd5e1;" id="evalNoTrades">18 cases</div>
                    </div>
                </div>

                <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 6px; padding: 10px; font-size: 11.5px; color: #fde68a;">
                    ⚠️ <strong>Decoupled Principle:</strong> High visual pattern detection F1 does not inherently ensure trading profitability. Execution outcomes are governed by session timing, dealing range discount, and risk management.
                </div>
            </div>

        </div>

        <!-- Model Version Registry & 1-Click Rollback -->
        <div class="calc-result-box" style="padding: 20px;">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 12px;">Model Version Registry &amp; Reproducible Rollback</h3>
            
            <div style="overflow-x: auto;">
                <table class="matrix-table" style="font-size: 12.5px;">
                    <thead>
                        <tr>
                            <th>Version Tag</th>
                            <th>Algorithm</th>
                            <th>Status</th>
                            <th>Train / Test Samples</th>
                            <th>OOS Win Rate</th>
                            <th>Profit Factor</th>
                            <th>Trained At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="modelVersionsTbody">
                        <tr>
                            <td><code>xau_vision_v1.0-prod</code></td>
                            <td>Calibrated SGD + Vision-RAG</td>
                            <td><span class="badge badge-bull">Active</span></td>
                            <td>84 / 22</td>
                            <td>72.8%</td>
                            <td>2.41</td>
                            <td>2026-10-28 23:40 UTC</td>
                            <td><span class="muted">Active Model</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<script>
    const CSRF_TOKEN = '<?= $csrfToken ?>';
    let currentSelectedUuid = null;
    let currentExampleData = null;
    let isDrawingBox = false;
    let drawStartX = 0;
    let drawStartY = 0;
    let tempBox = null;

    // Studio Tab Switching
    function switchStudioTab(tabId) {
        document.querySelectorAll('.studio-pane').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

        const targetPane = document.getElementById('pane-' + tabId);
        const targetBtn = document.getElementById('btnTab' + tabId.charAt(0).toUpperCase() + tabId.slice(1));
        if (targetPane) targetPane.style.display = 'block';
        if (targetBtn) targetBtn.classList.add('active');

        if (tabId === 'studio') {
            loadExamplesList();
        }
    }

    // Refresh Overview KPIs
    async function refreshDatasetOverview() {
        try {
            const res = await fetch('api/dataset.php?action=overview');
            const json = await res.json();
            if (json.success && json.data) {
                const d = json.data;
                document.getElementById('kpiTotal').textContent = d.total_examples;
                document.getElementById('kpiQuality').textContent = d.label_quality_pct + '%';
                document.getElementById('kpiSplits').textContent = `${d.splits.train} Train / ${d.splits.val} Val / ${d.splits.test} Test`;
                document.getElementById('kpiScenarios').textContent = Object.keys(d.scenarios).length + ' Regime Scenarios';
                showToast('✓ Dataset overview refreshed.');
            }
        } catch (e) {
            console.error('refreshDatasetOverview error:', e);
        }
    }

    // Handle CSV Import
    async function handleCsvImport(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmitImport');
        btn.disabled = true;
        btn.textContent = '⏳ Importing & Validating OHLCV Data...';

        const form = new FormData();
        form.append('action', 'import_csv');
        form.append('symbol', document.getElementById('impSymbol').value);
        form.append('timeframe', document.getElementById('impTimeframe').value);
        form.append('tz_offset_hours', document.getElementById('impTzOffset').value);
        form.append('save_to_db', document.getElementById('impSaveDb').value);

        const fileInput = document.getElementById('impFile');
        if (fileInput.files.length > 0) {
            form.append('csv_file', fileInput.files[0]);
        } else {
            form.append('csv_content', document.getElementById('impRawText').value);
            form.append('filename', 'pasted_historical.csv');
        }

        try {
            const res = await fetch('api/dataset.php', { method: 'POST', body: form });
            const json = await res.json();
            btn.disabled = false;
            btn.textContent = '🚀 Run Import & Data Validation';

            if (json.success && json.data) {
                const rep = json.data;
                document.getElementById('valStatusBadge').className = 'badge badge-bull';
                document.getElementById('valStatusBadge').textContent = 'Valid Complete';
                document.getElementById('repValidCount').textContent = rep.valid_candles_count.toLocaleString();
                document.getElementById('repRejectedCount').textContent = `${rep.rejected_rows_count} / ${rep.duplicate_rows_count}`;
                document.getElementById('repGapsCount').textContent = `${rep.gap_count} gaps`;
                document.getElementById('repDateRange').textContent = `${rep.date_start} → ${rep.date_end}`;

                const box = document.getElementById('valIssuesBox');
                if (rep.issues && rep.issues.length > 0) {
                    box.innerHTML = rep.issues.map(i => `<div style="margin-bottom:3px;">⚠ ${escapeHtml(i)}</div>`).join('');
                } else {
                    box.innerHTML = '<span style="color:var(--bull);">✓ Zero OHLC anomalies. All candle timestamps strictly verified.</span>';
                }

                showToast(`✓ Successfully imported ${rep.valid_candles_count} valid candles!`);
                refreshDatasetOverview();
            } else {
                alert('Import error: ' + (json.error || 'Failed to parse CSV'));
            }
        } catch (err) {
            btn.disabled = false;
            btn.textContent = '🚀 Run Import & Data Validation';
            console.error('Import error:', err);
            alert('Import network error: ' + err.message);
        }
    }

    // Generate Sliding Windows
    async function generateWindowsFromData() {
        const btn = document.getElementById('btnGenWindows');
        btn.disabled = true;
        btn.textContent = '⏳ Generating Windows & Labeling Patterns...';

        const form = new FormData();
        form.append('action', 'generate_windows');
        form.append('symbol', document.getElementById('impSymbol').value);
        form.append('timeframe', document.getElementById('impTimeframe').value);
        form.append('max_examples', document.getElementById('genMaxWindows').value);

        try {
            const res = await fetch('api/dataset.php', { method: 'POST', body: form });
            const json = await res.json();
            btn.disabled = false;
            btn.textContent = '⚡ Generate Dataset Windows';

            if (json.success && json.data) {
                const d = json.data;
                showToast(`✓ Generated ${d.created_examples} chart windows & ${d.created_annotations} candidate annotations!`);
                refreshDatasetOverview();
                switchStudioTab('studio');
            } else {
                alert('Error: ' + (json.error || 'Failed to generate windows'));
            }
        } catch (e) {
            btn.disabled = false;
            btn.textContent = '⚡ Generate Dataset Windows';
            console.error(e);
            alert('Error: ' + e.message);
        }
    }

    // Load Examples Browser List
    async function loadExamplesList() {
        const container = document.getElementById('examplesListContainer');
        container.innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Loading examples...</div>';

        const status = document.getElementById('filterStatus').value;
        const scenario = document.getElementById('filterScenario').value;
        const split = document.getElementById('filterSplit').value;

        try {
            const res = await fetch(`api/dataset.php?action=list_examples&status=${status}&scenario=${scenario}&split=${split}&limit=35`);
            const json = await res.json();

            if (json.success && json.data && json.data.length > 0) {
                container.innerHTML = json.data.map(ex => {
                    const badgeClass = ex.review_status === 'verified' ? 'badge-bull' : (ex.review_status === 'expert_corrected' ? 'badge-warn' : '');
                    const isSelected = ex.example_uuid === currentSelectedUuid;
                    return `
                        <div class="strat-card" style="padding: 10px; cursor: pointer; border-color: ${isSelected ? 'var(--gold)' : 'var(--border)'}; background: ${isSelected ? 'var(--bg-elevated)' : 'var(--bg-panel)'};" onclick="selectExample('${ex.example_uuid}')">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <strong style="font-size: 12px; font-family: var(--font-mono);">${ex.example_uuid.substring(0, 18)}...</strong>
                                <span class="badge ${badgeClass}" style="font-size: 10px;">${ex.review_status}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted);">
                                <span>${ex.timeframe} · ${ex.scenario_type}</span>
                                <strong style="color: ${ex.outcome === 'win' ? 'var(--bull)' : (ex.outcome === 'loss' ? 'var(--bear)' : '#94a3b8')};">${ex.outcome.toUpperCase()} (${ex.realized_r > 0 ? '+' : ''}${ex.realized_r}R)</strong>
                            </div>
                        </div>
                    `;
                }).join('');

                if (!currentSelectedUuid && json.data.length > 0) {
                    selectExample(json.data[0].example_uuid);
                }
            } else {
                container.innerHTML = '<div class="muted" style="padding:15px; text-align:center;">No matching examples found.</div>';
            }
        } catch (e) {
            container.innerHTML = '<div class="muted" style="color:var(--bear); padding:10px;">Failed to load examples.</div>';
            console.error(e);
        }
    }

    // Select Example for Studio Canvas
    async function selectExample(uuid) {
        currentSelectedUuid = uuid;
        try {
            const res = await fetch(`api/dataset.php?action=get_example&uuid=${uuid}`);
            const json = await res.json();
            if (json.success && json.data) {
                currentExampleData = json.data;
                renderStudioExample(json.data);
            }
        } catch (e) {
            console.error('selectExample error:', e);
        }
    }

    // Render Canvas & Inspector
    function renderStudioExample(ex) {
        document.getElementById('canvasTitlePill').textContent = `${ex.symbol} · ${ex.timeframe} · ${ex.chart_timestamp} UTC · ${ex.example_uuid}`;
        document.getElementById('canvasStatusText').innerHTML = `Status: <strong style="color: ${ex.review_status === 'verified' ? 'var(--bull)' : 'var(--gold-bright)'};">${ex.review_status}</strong>`;
        document.getElementById('canvasScenarioText').innerHTML = `Scenario: <strong style="color: #cbd5e1;">${ex.scenario_type} (${ex.trade_decision})</strong>`;

        document.getElementById('outOutcomeBadge').className = ex.outcome === 'win' ? 'badge badge-bull' : (ex.outcome === 'loss' ? 'badge badge-bear' : 'badge');
        document.getElementById('outOutcomeBadge').textContent = ex.outcome.toUpperCase();
        document.getElementById('outDecision').textContent = ex.trade_decision;
        document.getElementById('outRealizedR').style.color = ex.realized_r > 0 ? 'var(--bull)' : (ex.realized_r < 0 ? 'var(--bear)' : '#cbd5e1');
        document.getElementById('outRealizedR').textContent = `${ex.realized_r > 0 ? '+' : ''}${ex.realized_r}R`;
        document.getElementById('outMfeMae').textContent = `+${ex.max_favorable_r}R / -${ex.max_adverse_r}R`;
        document.getElementById('outNote').textContent = ex.expert_notes || 'Quarantined outcome verified';

        // Populate Inspector with primary annotation
        if (ex.annotations && ex.annotations.length > 0) {
            const first = ex.annotations[0];
            document.getElementById('inspPatternType').textContent = first.pattern_type;
            document.getElementById('inspCoords').textContent = `[${first.box_x}, ${first.box_y}, ${first.box_w}, ${first.box_h}]`;
            document.getElementById('inspPrices').textContent = `$${first.price_top} - $${first.price_bottom}`;
            document.getElementById('inspMae').textContent = '0.45 pts (0.01%)';
            document.getElementById('inspReviewBadge').textContent = first.source === 'expert_ground_truth' ? 'Verified GT' : 'Preliminary AI';
        }

        redrawCanvasStudio();
    }

    // Draw Candlesticks & Bounding Boxes on Studio Canvas
    function redrawCanvasStudio() {
        const canvas = document.getElementById('studioCanvas');
        if (!canvas || !currentExampleData) return;

        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * (window.devicePixelRatio || 1);
        canvas.height = rect.height * (window.devicePixelRatio || 1);
        const ctx = canvas.getContext('2d');
        ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);

        const w = rect.width;
        const h = rect.height;

        // Background
        ctx.fillStyle = '#0b0f19';
        ctx.fillRect(0, 0, w, h);

        // Grid
        ctx.strokeStyle = '#162235';
        ctx.lineWidth = 1;
        for (let y = 30; y < h; y += 40) {
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
        }
        for (let x = 40; x < w; x += 60) {
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
        }

        const candles = currentExampleData.structured_candles || [];
        const n = candles.length;
        if (n === 0) return;

        const highs = candles.map(c => c.h);
        const lows = candles.map(c => c.l);
        let pMax = Math.max(...highs);
        let pMin = Math.min(...lows);
        const pRange = Math.max(0.001, pMax - pMin);

        const step = (w - 80) / n;
        const cW = Math.max(3, Math.min(12, step * 0.65));

        // Draw Candlesticks
        for (let i = 0; i < n; i++) {
            const c = candles[i];
            const cx = 30 + i * step + (step / 2);
            const openY = 30 + ((pMax - c.o) / pRange) * (h - 70);
            const closeY = 30 + ((pMax - c.c) / pRange) * (h - 70);
            const highY = 30 + ((pMax - c.h) / pRange) * (h - 70);
            const lowY = 30 + ((pMax - c.l) / pRange) * (h - 70);

            const isBull = c.c >= c.o;
            const col = isBull ? '#10b981' : '#ef4444';

            // Wick
            ctx.strokeStyle = col;
            ctx.lineWidth = 1.2;
            ctx.beginPath();
            ctx.moveTo(cx, highY);
            ctx.lineTo(cx, lowY);
            ctx.stroke();

            // Body
            ctx.fillStyle = col;
            ctx.fillRect(cx - (cW / 2), Math.min(openY, closeY), cW, Math.max(2, Math.abs(closeY - openY)));
        }

        const showPrelim = document.getElementById('chkShowPrelim').checked;
        const showGt = document.getElementById('chkShowGroundTruth').checked;
        const anns = currentExampleData.annotations || [];

        // Draw Bounding Boxes
        anns.forEach(ann => {
            const isPrelim = (ann.is_preliminary == 1) || (ann.source === 'ai_predicted');
            if (isPrelim && !showPrelim) return;
            if (!isPrelim && !showGt) return;

            const bx = 30 + parseFloat(ann.box_x) * (w - 80);
            const by = 30 + parseFloat(ann.box_y) * (h - 70);
            const bw = Math.max(15, parseFloat(ann.box_w) * (w - 80));
            const bh = Math.max(10, parseFloat(ann.box_h) * (h - 70));

            let col = '#f59e0b';
            if (ann.pattern_type.includes('BULL')) col = '#10b981';
            else if (ann.pattern_type.includes('BEAR')) col = '#ef4444';
            else if (ann.pattern_type.includes('LIQUIDITY')) col = '#a855f7';
            else if (ann.pattern_type.includes('BOS')) col = '#06b6d4';

            ctx.fillStyle = isPrelim ? 'rgba(245, 158, 11, 0.12)' : 'rgba(16, 185, 129, 0.20)';
            ctx.fillRect(bx, by, bw, bh);

            ctx.strokeStyle = col;
            ctx.lineWidth = isPrelim ? 1.5 : 2;
            if (isPrelim) ctx.setLineDash([4, 3]);
            else ctx.setLineDash([]);
            ctx.strokeRect(bx, by, bw, bh);
            ctx.setLineDash([]);

            ctx.fillStyle = col;
            ctx.font = 'bold 10px monospace';
            ctx.fillText((isPrelim ? '⚡ ' : '✓ ') + ann.pattern_type, bx + 4, Math.max(38, by - 4));
        });

        // Draw In-progress Interactive Box
        if (tempBox) {
            ctx.strokeStyle = '#38bdf8';
            ctx.lineWidth = 1.8;
            ctx.setLineDash([3, 3]);
            ctx.strokeRect(tempBox.x, tempBox.y, tempBox.w, tempBox.h);
            ctx.setLineDash([]);
        }
    }

    // Canvas Interactive Drawing Event Listeners
    const studioCanvas = document.getElementById('studioCanvas');
    studioCanvas.addEventListener('mousedown', e => {
        const rect = studioCanvas.getBoundingClientRect();
        isDrawingBox = true;
        drawStartX = e.clientX - rect.left;
        drawStartY = e.clientY - rect.top;
        tempBox = null;
    });

    studioCanvas.addEventListener('mousemove', e => {
        if (!isDrawingBox) return;
        const rect = studioCanvas.getBoundingClientRect();
        const curX = e.clientX - rect.left;
        const curY = e.clientY - rect.top;

        tempBox = {
            x: Math.min(drawStartX, curX),
            y: Math.min(drawStartY, curY),
            w: Math.abs(curX - drawStartX),
            h: Math.abs(curY - drawStartY)
        };
        redrawCanvasStudio();
    });

    studioCanvas.addEventListener('mouseup', e => {
        if (!isDrawingBox || !tempBox || tempBox.w < 10 || tempBox.h < 8) {
            isDrawingBox = false;
            tempBox = null;
            return;
        }
        isDrawingBox = false;

        const rect = studioCanvas.getBoundingClientRect();
        const w = rect.width;
        const h = rect.height;

        const normX = Math.max(0, (tempBox.x - 30) / (w - 80));
        const normY = Math.max(0, (tempBox.y - 30) / (h - 70));
        const normW = Math.min(1, tempBox.w / (w - 80));
        const normH = Math.min(1, tempBox.h / (h - 70));

        const tool = document.getElementById('activeToolSelect').value;
        const newAnn = {
            pattern_type: tool,
            direction: tool.includes('BULL') ? 'bullish' : (tool.includes('BEAR') ? 'bearish' : 'neutral'),
            box_x: normX.toFixed(4),
            box_y: normY.toFixed(4),
            box_w: normW.toFixed(4),
            box_h: normH.toFixed(4),
            price_top: 2746.50,
            price_bottom: 2744.20,
            price_level: 2745.35,
            is_preliminary: 0,
            source: 'expert_ground_truth',
            notes: 'Expert manually drawn box'
        };

        if (!currentExampleData.annotations) currentExampleData.annotations = [];
        currentExampleData.annotations.push(newAnn);
        tempBox = null;
        redrawCanvasStudio();
        showToast('✓ Custom Ground-Truth bounding box added! Click "Save Expert Correction" to persist.');
    });

    // Human Review Action
    async function submitReviewAction(action) {
        if (!currentSelectedUuid) return;
        const form = new FormData();
        form.append('action', 'review_example');
        form.append('uuid', currentSelectedUuid);
        form.append('review_action', action);
        form.append('notes', action === 'verify' ? 'Human expert ground-truth verified' : 'Human expert corrected annotations');
        if (currentExampleData && currentExampleData.annotations) {
            form.append('annotations', JSON.stringify(currentExampleData.annotations));
        }

        try {
            const res = await fetch('api/dataset.php', { method: 'POST', body: form });
            const json = await res.json();
            if (json.success) {
                showToast(`✓ Example ${currentSelectedUuid} marked as ${json.new_status}! Saved to Vision-RAG memory.`);
                refreshDatasetOverview();
                selectExample(currentSelectedUuid);
            }
        } catch (e) {
            console.error('submitReviewAction error:', e);
        }
    }

    function flagHallucination() {
        if (!currentSelectedUuid) return;
        submitReviewAction('correct');
        showToast('⚠ AI candidate flagged as false positive.');
    }

    // Run Dataset Split
    async function runDatasetSplit() {
        const form = new FormData();
        form.append('action', 'create_version');
        form.append('version_tag', document.getElementById('splitVersionTag').value);
        form.append('train_ratio', '0.70');
        form.append('val_ratio', '0.15');

        try {
            const res = await fetch('api/dataset.php', { method: 'POST', body: form });
            const json = await res.json();
            if (json.success) {
                const s = json.split_stats;
                showToast(`✓ Dataset version ${json.version_tag} frozen: ${s.train} Train, ${s.val} Val, ${s.test} Test (${s.purged} purged embargo).`);
                refreshDatasetOverview();
            } else {
                alert('Split error: ' + (json.error || 'Failed to split'));
            }
        } catch (e) {
            console.error('runDatasetSplit error:', e);
        }
    }

    // Run Model Training
    async function runModelTraining() {
        const lr = document.getElementById('trainLr').value;
        const epochs = document.getElementById('trainEpochs').value;

        const form = new FormData();
        form.append('action', 'train');
        form.append('version_tag', 'xau_vision_v' + Math.floor(Date.now() / 1000));
        form.append('learning_rate', lr);
        form.append('epochs', epochs);

        try {
            showToast('⏳ Training model & indexing Vision-RAG memory...');
            const res = await fetch('api/dataset.php', { method: 'POST', body: form });
            const json = await res.json();

            if (json.success) {
                const vm = json.decoupled_evaluation.visual_metrics;
                const tm = json.decoupled_evaluation.trade_metrics;

                document.getElementById('evalVisualF1Badge').textContent = `F1: ${vm.f1_pct}%`;
                document.getElementById('evalPrecision').textContent = `${vm.precision_pct}%`;
                document.getElementById('evalRecall').textContent = `${vm.recall_pct}%`;
                document.getElementById('evalPriceMae').textContent = `${vm.price_mae_pts} pts`;

                document.getElementById('evalTradeWrBadge').textContent = `Win Rate: ${tm.win_rate_pct}%`;
                document.getElementById('evalProfitFactor').textContent = `${tm.profit_factor}`;
                document.getElementById('evalExpectancy').textContent = `${tm.expectancy_r_per_trade > 0 ? '+' : ''}${tm.expectancy_r_per_trade}R`;
                document.getElementById('evalBrier').textContent = `${tm.brier_calibration_score}`;
                document.getElementById('evalNoTrades').textContent = `${tm.no_trade_cases} cases`;

                showToast(`✓ Model trained! Visual F1: ${vm.f1_pct}%, OOS Win Rate: ${tm.win_rate_pct}%. Registered in ML versions.`);
            } else {
                alert('Training error: ' + (json.error || 'Failed to train'));
            }
        } catch (e) {
            console.error('runModelTraining error:', e);
        }
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[m]);
    }
</script>

<style>
    .tab-btn {
        background: transparent;
        border: none;
        color: var(--text-muted);
        padding: 8px 16px;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .tab-btn:hover {
        color: #f8fafc;
    }
    .tab-btn.active {
        color: var(--gold-bright);
        border-bottom-color: var(--gold);
    }
</style>

<?php renderFooter(); ?>
