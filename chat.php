<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Views/layout.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Validator;
use App\Market\MarketDataService;

$layout = renderHeader('AI Chat Analyst — ICT / SMC / CRT', 'chat');
$user = $layout['user'];
$csrf = Csrf::token();

$activeSymbol = MarketDataService::normalizeSymbol($_GET['symbol'] ?? 'XAUUSD');
$activeTf = strtoupper(trim($_GET['tf'] ?? 'M15'));
$activeStrategy = strtoupper(trim($_GET['strategy'] ?? 'ALL'));
if (!in_array($activeStrategy, ['ICT', 'SMC', 'CRT', 'ALL'], true)) {
    $activeStrategy = 'ALL';
}

$market = new MarketDataService(null, $activeSymbol);
$quote = $market->quote(false);
$livePrice = $quote['available'] ? (float)$quote['price'] : 2400.0;
?>

<div class="vision-hero">
    <div>
        <div class="vision-hero-title">
            <span>💬</span>
            <span><?= I18n::t('nav_chat') ?> — Multi-Strategy Institutional Intelligence</span>
        </div>
        <div class="vision-hero-sub">
            Converse directly with the AI quantitative analyst. Select your preferred institutional methodology: <strong>ICT</strong> (Killzones &amp; FVGs), <strong>SMC</strong> (BOS &amp; Order Blocks), <strong>CRT</strong> (Candle Range Theory &amp; 50% Equilibrium), or <strong>ALL</strong> (Triple Confluence).
        </div>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <span class="badge badge-live" id="chatLivePriceBadge">● Live <?= Validator::e($activeSymbol) ?>: $<?= number_format($livePrice, 2) ?></span>
        <button type="button" class="btn btn-sm btn-ghost" id="clearChatBtn" title="Reset chat history">🗑️ Clear Chat</button>
    </div>
</div>

<div class="chat-workspace" style="display: grid; grid-template-columns: 280px 1fr; gap: 16px; margin-top: 14px;">
    <!-- Left Sidebar: Controls, Strategy Picker, and Strategy Insights -->
    <div style="display: flex; flex-direction: column; gap: 14px;">
        <div class="card" style="padding: 16px;">
            <h4 style="font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gold-bright); margin-bottom: 10px;">
                1. Select Strategy Model
            </h4>

            <div class="strategy-picker-vertical" style="display: flex; flex-direction: column; gap: 8px;">
                <label class="strat-radio-card <?= $activeStrategy === 'ALL' ? 'active' : '' ?>">
                    <input type="radio" name="chat_strat" value="ALL" <?= $activeStrategy === 'ALL' ? 'checked' : '' ?> onchange="setChatStrategy('ALL')">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 13px;">🌐 Triple Confluence</div>
                        <div class="muted" style="font-size: 11px;">ICT + SMC + CRT consensus filter</div>
                    </div>
                </label>

                <label class="strat-radio-card <?= $activeStrategy === 'CRT' ? 'active' : '' ?>">
                    <input type="radio" name="chat_strat" value="CRT" <?= $activeStrategy === 'CRT' ? 'checked' : '' ?> onchange="setChatStrategy('CRT')">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 13px;">🕯️ CRT (Candle Range Theory)</div>
                        <div class="muted" style="font-size: 11px;">Range High/Low sweep, 50% EQ delivery</div>
                    </div>
                </label>

                <label class="strat-radio-card <?= $activeStrategy === 'ICT' ? 'active' : '' ?>">
                    <input type="radio" name="chat_strat" value="ICT" <?= $activeStrategy === 'ICT' ? 'checked' : '' ?> onchange="setChatStrategy('ICT')">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 13px;">⚡ ICT (Inner Circle Trader)</div>
                        <div class="muted" style="font-size: 11px;">Silver Bullet, FVGs, 2022 Mentorship</div>
                    </div>
                </label>

                <label class="strat-radio-card <?= $activeStrategy === 'SMC' ? 'active' : '' ?>">
                    <input type="radio" name="chat_strat" value="SMC" <?= $activeStrategy === 'SMC' ? 'checked' : '' ?> onchange="setChatStrategy('SMC')">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 13px;">🏛️ SMC (Smart Money Concepts)</div>
                        <div class="muted" style="font-size: 11px;">BOS, CHoCH, Order Blocks, Liquidity</div>
                    </div>
                </label>
            </div>
        </div>

        <div class="card" style="padding: 16px;">
            <h4 style="font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 10px;">
                2. Market Parameters
            </h4>

            <div class="form-group" style="margin-bottom: 10px;">
                <label for="chatSymbolSelect" style="font-size: 12px;">Instrument / Pair</label>
                <select id="chatSymbolSelect" class="form-control" style="font-size: 13px;" onchange="updateChatParams()">
                    <option value="XAUUSD" <?= $activeSymbol === 'XAUUSD' ? 'selected' : '' ?>>XAU/USD (Spot Gold)</option>
                    <option value="BTCUSD" <?= $activeSymbol === 'BTCUSD' ? 'selected' : '' ?>>BTC/USD (Bitcoin)</option>
                    <option value="EURUSD" <?= $activeSymbol === 'EURUSD' ? 'selected' : '' ?>>EUR/USD (Euro)</option>
                    <option value="US30" <?= $activeSymbol === 'US30' ? 'selected' : '' ?>>US30 (Dow Jones)</option>
                    <option value="NAS100" <?= $activeSymbol === 'NAS100' ? 'selected' : '' ?>>NAS100 (Nasdaq)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="chatTfSelect" style="font-size: 12px;">Analysis Timeframe</label>
                <select id="chatTfSelect" class="form-control" style="font-size: 13px;" onchange="updateChatParams()">
                    <option value="M5" <?= $activeTf === 'M5' ? 'selected' : '' ?>>M5 (5 Minutes - Scalping)</option>
                    <option value="M15" <?= $activeTf === 'M15' ? 'selected' : '' ?>>M15 (15 Minutes - Standard Setup)</option>
                    <option value="H1" <?= $activeTf === 'H1' ? 'selected' : '' ?>>H1 (1 Hour - Intraday)</option>
                    <option value="H4" <?= $activeTf === 'H4' ? 'selected' : '' ?>>H4 (4 Hours - Swing Structure)</option>
                    <option value="D1" <?= $activeTf === 'D1' ? 'selected' : '' ?>>D1 (Daily - Macro Trend)</option>
                </select>
            </div>
        </div>

        <!-- Strategy Quick Info Box -->
        <div class="card" style="padding: 14px; background: rgba(15, 23, 42, 0.6); font-size: 12px; line-height: 1.5; color: var(--text-muted);" id="stratInfoCard">
            <strong style="color: var(--text-main); display: block; margin-bottom: 4px;" id="stratInfoTitle">🌐 Triple Confluence</strong>
            <p style="margin: 0;" id="stratInfoDesc">
                Evaluates setups through ICT time/price delivery, SMC structural order blocks, and CRT reference candle boundaries simultaneously. Only authorizes trades when all models agree.
            </p>
        </div>
    </div>

    <!-- Right Column: Interactive Chat Stream & Input Box -->
    <div class="card" style="display: flex; flex-direction: column; height: 750px; padding: 0; overflow: hidden;">
        <!-- Chat Top Bar -->
        <div style="padding: 12px 18px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-elevated); flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="brand-icon" style="width: 28px; height: 28px; font-size: 12px;">AI</span>
                <span style="font-weight: 700; font-size: 14px;">Quantitative Trading Assistant</span>
                <span class="badge badge-bull" id="activeStratBadge">Strategy: <?= Validator::e($activeStrategy) ?></span>
            </div>
            <div style="font-size: 12px; color: var(--text-muted);">
                <span id="activeContextText"><?= Validator::e($activeSymbol) ?> · <?= Validator::e($activeTf) ?></span>
            </div>
        </div>

        <!-- Scrollable Message Stream -->
        <div id="chatMessageStream" style="flex: 1; padding: 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px;">
            <!-- Welcome Message from AI Assistant -->
            <div class="chat-msg assistant">
                <div class="chat-avatar">AI</div>
                <div class="chat-bubble">
                    <p style="margin: 0 0 8px 0; font-weight: 700; color: var(--gold-bright);">
                        Welcome to the XAUUSD &amp; Multi-Asset AI Quantitative Trading Desk.
                    </p>
                    <p style="margin: 0 0 8px 0;">
                        I am your institutional analyst. Select your methodology above (<strong>ICT</strong>, <strong>SMC</strong>, <strong>CRT</strong>, or <strong>ALL</strong>), and I will inspect real-time candle structures, liquidity pools, reference candle boundaries, and entry invalidations.
                    </p>
                    <p style="margin: 0; font-size: 12px;" class="muted">
                        💡 <em>Tip: Click any quick prompt below or ask about current liquidity, 50% equilibrium levels, or high-probability entry targets.</em>
                    </p>
                </div>
            </div>
        </div>

        <!-- Quick Prompt Pills Bar -->
        <div style="padding: 8px 18px; border-top: 1px solid var(--border); background: var(--bg-panel); display: flex; gap: 8px; overflow-x: auto; white-space: nowrap;">
            <button type="button" class="btn btn-xs btn-secondary" onclick="sendQuickPrompt('Analyze current CRT Range High, Range Low and 50% Equilibrium setup')">
                🕯️ CRT Range &amp; Reclaim
            </button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="sendQuickPrompt('Check ICT Silver Bullet window, Killzones and Fair Value Gaps')">
                ⚡ ICT Silver Bullet &amp; FVG
            </button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="sendQuickPrompt('Identify SMC Break of Structure (BOS), CHoCH and unmitigated Order Blocks')">
                🏛️ SMC BOS &amp; Order Blocks
            </button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="sendQuickPrompt('Generate complete BUY/SELL/NO TRADE trade signal with exact Entry, SL and TP')">
                🎯 Generate Trade Signal
            </button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="sendQuickPrompt('Evaluate portfolio risk, ATR volatility and maximum daily exposure limits')">
                🛡️ Check Risk &amp; Invalidation
            </button>
        </div>

        <!-- Chat Input Form -->
        <div style="padding: 12px 18px; border-top: 1px solid var(--border); background: var(--bg-elevated);">
            <form id="chatForm" onsubmit="handleChatSubmit(event)" style="display: flex; gap: 10px; align-items: flex-end;">
                <textarea id="chatInput" rows="2" class="form-control" placeholder="Ask about current setup, CRT boundaries, ICT FVG, or SMC levels... (Enter to send, Shift+Enter for newline)" style="resize: none; font-size: 13px; line-height: 1.4;"></textarea>
                <button type="submit" class="btn btn-primary" id="chatSendBtn" style="padding: 10px 20px; font-weight: 700; height: 50px; display: flex; align-items: center; gap: 6px;">
                    <span>Send</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.strat-radio-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: var(--radius);
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: all 0.2s ease;
}
.strat-radio-card:hover {
    border-color: var(--border-hover);
}
.strat-radio-card.active {
    border-color: var(--gold);
    background: rgba(245, 158, 11, 0.12);
}
.strat-radio-card input {
    margin: 0;
    accent-color: var(--gold);
}

.chat-msg {
    display: flex;
    gap: 12px;
    max-width: 88%;
}
.chat-msg.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}
.chat-msg.assistant {
    align-self: flex-start;
}
.chat-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    flex-shrink: 0;
}
.chat-msg.assistant .chat-avatar {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #000;
}
.chat-msg.user .chat-avatar {
    background: #3b82f6;
    color: #fff;
}
.chat-bubble {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.55;
    background: var(--bg-panel);
    border: 1px solid var(--border);
    word-break: break-word;
}
.chat-msg.user .chat-bubble {
    background: #1e3a5f;
    border-color: #2b5278;
    color: #fff;
}
.chat-msg.assistant .chat-bubble {
    background: var(--bg-panel);
    border-color: var(--border);
}
.chat-bubble pre {
    background: #0d131f;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 10px;
    font-family: var(--font-mono);
    font-size: 12px;
    margin: 8px 0;
    overflow-x: auto;
}
.chat-bubble h3 { font-size: 14px; font-weight: 800; margin: 4px 0 8px; color: var(--gold-bright); }
.chat-bubble h4 { font-size: 13px; font-weight: 700; margin: 6px 0 6px; color: #60a5fa; }
.chat-bubble ul { margin: 6px 0 6px 18px; padding: 0; }
</style>

<script>
let currentStrategy = '<?= Validator::e($activeStrategy) ?>';
let currentSymbol = '<?= Validator::e($activeSymbol) ?>';
let currentTf = '<?= Validator::e($activeTf) ?>';

const stratInfo = {
    'ALL': {
        title: '🌐 Triple Confluence (ICT + SMC + CRT)',
        desc: 'Evaluates setups through ICT time/price delivery, SMC structural order blocks, and CRT reference candle boundaries simultaneously. Only authorizes trades when all models agree.'
    },
    'CRT': {
        title: '🕯️ Candle Range Theory (CRT)',
        desc: 'Focuses on the previous reference candle High (RH), Low (RL), and 50% Equilibrium (EQ). Enforces Phase 1 (Sweep), Phase 2 (Close re-entry), and Phase 3 (Delivery to 50% EQ & opposing boundary).'
    },
    'ICT': {
        title: '⚡ Inner Circle Trader (ICT)',
        desc: 'Specializes in institutional Killzones (London/NY), Silver Bullet 60-min models, Fair Value Gaps (FVG), Breaker Blocks, and 2022 Mentorship liquidity sweep retracements.'
    },
    'SMC': {
        title: '🏛️ Smart Money Concepts (SMC)',
        desc: 'Tracks true institutional order flow: Break of Structure (BOS), Change of Character (CHoCH), Market Structure Shift (MSS), Premium/Discount dealing ranges, and unmitigated Order Blocks.'
    }
};

function setChatStrategy(strat) {
    currentStrategy = strat;
    document.querySelectorAll('.strat-radio-card').forEach(el => {
        const inp = el.querySelector('input');
        if (inp.value === strat) {
            el.classList.add('active');
            inp.checked = true;
        } else {
            el.classList.remove('active');
        }
    });

    document.getElementById('activeStratBadge').textContent = 'Strategy: ' + strat;
    if (stratInfo[strat]) {
        document.getElementById('stratInfoTitle').textContent = stratInfo[strat].title;
        document.getElementById('stratInfoDesc').textContent = stratInfo[strat].desc;
    }
}

function updateChatParams() {
    currentSymbol = document.getElementById('chatSymbolSelect').value;
    currentTf = document.getElementById('chatTfSelect').value;
    document.getElementById('activeContextText').textContent = `${currentSymbol} · ${currentTf}`;
    document.getElementById('chatLivePriceBadge').textContent = `● Live ${currentSymbol}`;
}

async function handleChatSubmit(e) {
    if (e) e.preventDefault();
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    appendMessage('user', msg);

    const sendBtn = document.getElementById('chatSendBtn');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="spinner" style="width:14px;height:14px;border:2px solid #000;border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;"></span> Analyzing...';

    try {
        const res = await fetch('api/chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                action: 'send',
                message: msg,
                strategy_mode: currentStrategy,
                symbol: currentSymbol,
                timeframe: currentTf
            })
        });

        const data = await res.json();
        if (data.ok) {
            appendMessage('assistant', data.reply);
        } else {
            appendMessage('assistant', '⚠️ Error: ' + (data.error || 'Failed to process strategy analysis.'));
        }
    } catch (err) {
        appendMessage('assistant', '⚠️ Network error: ' + err.message);
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<span>Send</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    }
}

function sendQuickPrompt(promptText) {
    document.getElementById('chatInput').value = promptText;
    handleChatSubmit(null);
}

function appendMessage(sender, text) {
    const stream = document.getElementById('chatMessageStream');
    const msgDiv = document.createElement('div');
    msgDiv.className = 'chat-msg ' + sender;

    const avatarDiv = document.createElement('div');
    avatarDiv.className = 'chat-avatar';
    avatarDiv.textContent = sender === 'assistant' ? 'AI' : 'YOU';

    const bubbleDiv = document.createElement('div');
    bubbleDiv.className = 'chat-bubble';

    // Simple markdown conversion for display (headers, bold, code)
    let formatted = escapeHtml(text)
        .replace(/### (.*?)\n/g, '<h3>$1</h3>')
        .replace(/#### (.*?)\n/g, '<h4>$1</h4>')
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/```text\n([\s\S]*?)```/g, '<pre>$1</pre>')
        .replace(/\n/g, '<br>');

    bubbleDiv.innerHTML = formatted;

    msgDiv.appendChild(avatarDiv);
    msgDiv.appendChild(bubbleDiv);
    stream.appendChild(msgDiv);
    stream.scrollTop = stream.scrollHeight;
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.getElementById('chatInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleChatSubmit(null);
    }
});

document.getElementById('clearChatBtn').addEventListener('click', async () => {
    if (!confirm('Clear conversation history for ' + currentSymbol + '?')) return;
    try {
        await fetch('api/chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ action: 'clear', symbol: currentSymbol })
        });
        const stream = document.getElementById('chatMessageStream');
        stream.innerHTML = '';
        appendMessage('assistant', 'Chat history cleared. Select a strategy (ICT, SMC, or CRT) and ask any market question.');
    } catch (e) {
        alert('Failed to clear chat.');
    }
});
</script>
