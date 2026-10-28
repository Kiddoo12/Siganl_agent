/**
 * GOLD SMC AI PRO — Frontend Core, TradingView-Style Interactive Candlestick/Bar/Heikin-Ashi
 * + SMC Overlay Chart Engine, Drawing Tools, Equity Curve Renderer, and Notification Manager.
 */
(function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const userTz = document.documentElement.getAttribute('data-tz') || 'UTC';

    const Api = {
        async request(method, url, body = null) {
            const opts = {
                method,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                credentials: 'same-origin',
            };
            if (body !== null) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            const resp = await fetch(url, opts);
            const data = await resp.json().catch(() => ({ ok: false, error: 'Invalid server response' }));
            if (!resp.ok || data.ok === false) {
                throw new Error(data.error || ('HTTP ' + resp.status));
            }
            return data;
        },
        get(url) { return this.request('GET', url); },
        post(url, body = {}) { return this.request('POST', url, body); },
    };

    function toast(msg, type = 'info') {
        const c = document.getElementById('toastContainer');
        if (!c) return;
        const el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' error' : '');
        el.textContent = msg;
        c.appendChild(el);
        setTimeout(() => el.remove(), 4500);
    }

    function playAlertChime(isConfirmed = false) {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const notes = isConfirmed ? [587.33, 880, 1174.66] : [523.25, 659.25];
            notes.forEach((freq, idx) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.08, ctx.currentTime + idx * 0.13);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + idx * 0.13 + 0.25);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + idx * 0.13);
                osc.stop(ctx.currentTime + idx * 0.13 + 0.26);
            });
        } catch (_) {}
    }

    function showEntryPrepPopup(opts = {}) {
        const container = document.getElementById('entryPrepPopupContainer');
        if (!container) return;

        const isReady85 = opts.stage === 'ready_85' || Number(opts.score || 0) >= 85;
        const dir = String(opts.direction || 'SELL').toUpperCase();
        const sym = String(opts.symbol || 'XAUUSD').toUpperCase();
        const tf = String(opts.tf || 'M15').toUpperCase();
        const score = Number(opts.score || 0);
        const metCount = Number(opts.met_count ?? (isReady85 ? 8 : 7));
        const totalCount = Number(opts.total_count || 8);
        const completionPct = Math.min(100, Math.round((metCount / Math.max(1, totalCount)) * 100));
        const cls = isReady85 ? (dir === 'BUY' ? 'stage-ready-buy' : 'stage-ready-sell') : 'stage-near';
        const badgeCls = isReady85 ? (dir === 'BUY' ? 'badge-bull' : 'badge-bear') : 'badge-warn';
        const badgeText = isReady85 ? `SCORE ${score}/100 (≥85 CONFIRMED)` : `NEARING CONFIRMATION (${metCount}/${totalCount})`;

        playAlertChime(isReady85);

        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification(opts.title || `${sym} — Prepare for ${dir} Market Entry`, {
                    body: opts.summary || `Score ${score}/100 (${metCount}/${totalCount} Confluences) · Entry $${fmtPrice(opts.entry)}`,
                });
            } catch (_) {}
        }

        const card = document.createElement('div');
        card.className = `entry-prep-popup ${cls}`;
        card.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div>
                    <span class="badge ${badgeCls}" style="font-size:10px;">${escapeHtml(badgeText)}</span>
                    <div style="font-weight:700;font-size:13.5px;margin-top:4px;">
                        ${isReady85 ? '🚨 PREPARE FOR MARKET ENTRY NOW' : '⏳ PREPARE FOR MARKET ENTRY'} — ${escapeHtml(sym)} ${escapeHtml(dir)} (${escapeHtml(tf)})
                    </div>
                </div>
                <button type="button" class="btn btn-xs btn-ghost prep-close-btn" title="Dismiss">✕</button>
            </div>
            <div class="prep-meter-track">
                <div class="prep-meter-fill" style="width:${Math.max(completionPct, score)}%;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;" class="muted mono">
                <span>Confirmation: <strong>${metCount}/${totalCount} (${completionPct}%)</strong></span>
                <span>Quality Score: <strong>${score}/100</strong></span>
            </div>
            <div style="margin-top:8px;font-size:11.5px;line-height:1.45;">
                <div>🎯 <strong>Entry Zone:</strong> $${fmtPrice(opts.entry_low)} – $${fmtPrice(opts.entry_high)} (Ref: <strong>$${fmtPrice(opts.entry)}</strong>)</div>
                <div>🛑 <strong>Stop Loss:</strong> <span class="text-bear">$${fmtPrice(opts.sl)}</span> · ✅ <strong>TP1:</strong> <span class="text-bull">$${fmtPrice(opts.tp1)}</span> ${opts.tp2 ? `· 🎯 <strong>TP2:</strong> <span class="text-bull">$${fmtPrice(opts.tp2)}</span>` : ''}</div>
                ${opts.missing_labels && opts.missing_labels.length
                    ? `<div class="muted" style="margin-top:3px;">🔍 Awaiting final trigger: ${escapeHtml(opts.missing_labels.join(', '))}</div>`
                    : `<div class="text-bull" style="margin-top:3px;">✓ All 8/8 SMC Confluences Confirmed — Ready for Execution!</div>`}
            </div>
            <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
                <button type="button" class="btn btn-xs btn-primary prep-calc-btn">⚡ Fill Lot Calculator</button>
                <button type="button" class="btn btn-xs prep-tg-btn">✈️ Send to Telegram</button>
            </div>
        `;

        card.querySelector('.prep-close-btn')?.addEventListener('click', () => card.remove());
        card.querySelector('.prep-calc-btn')?.addEventListener('click', () => {
            const eIn = document.getElementById('rcEntry');
            const sIn = document.getElementById('rcSl');
            const t1In = document.getElementById('rcTp1');
            const t2In = document.getElementById('rcTp2');
            if (eIn && opts.entry) eIn.value = Number(opts.entry).toFixed(2);
            if (sIn && opts.sl) sIn.value = Number(opts.sl).toFixed(2);
            if (t1In && opts.tp1) t1In.value = Number(opts.tp1).toFixed(2);
            if (t2In && opts.tp2) t2In.value = Number(opts.tp2).toFixed(2);
            const form = document.getElementById('riskCalcForm');
            if (form) {
                form.dispatchEvent(new Event('submit'));
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            toast('Entry, Stop Loss & TP levels loaded into Position Size Calculator.');
        });
        card.querySelector('.prep-tg-btn')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            btn.disabled = true;
            btn.textContent = 'Sending...';
            try {
                await Api.post('api/notifications.php', { action: 'test_prepare', symbol: sym });
                toast('Prepare-for-Entry alert sent to Telegram (@i_am_Theaa_Rithhh).');
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = '✈️ Send to Telegram';
            }
        });

        while (container.children.length >= 2) {
            container.firstElementChild.remove();
        }
        container.appendChild(card);
        setTimeout(() => {
            if (card.parentElement) card.remove();
        }, 25000);
    }

    function checkSignalReadiness(sig, symbol, forcePopup = false) {
        if (!sig || !sig.readiness) return;
        const r = sig.readiness;
        if (!r.ready && !forcePopup) return;
        const key = String(r.key || '');
        if (!forcePopup && key) {
            try {
                const seen = JSON.parse(localStorage.getItem('gsmc_seen_prep_keys') || '[]');
                if (Array.isArray(seen) && seen.includes(key)) {
                    return;
                }
                const nextSeen = [...(Array.isArray(seen) ? seen : []).slice(-40), key];
                localStorage.setItem('gsmc_seen_prep_keys', JSON.stringify(nextSeen));
            } catch (_) {}
        }
        showEntryPrepPopup({
            stage: r.stage,
            symbol: symbol || sig.asset || 'XAUUSD',
            tf: sig.entry_tf || 'M15',
            direction: r.direction || (sig.direction !== 'WAIT' ? sig.direction : (sig.watch?.direction || 'SELL')),
            score: sig.quality_score ?? r.score ?? 0,
            met_count: r.met_count ?? 7,
            total_count: r.total_count ?? 8,
            title: r.title,
            summary: r.summary,
            missing_labels: r.missing_labels || [],
            entry_low: sig.entry_low,
            entry_high: sig.entry_high,
            entry: sig.entry,
            sl: sig.sl,
            tp1: sig.tp1,
            tp2: sig.tp2,
        });
    }

    function fmtPrice(v, digits = 2) {
        if (v === null || v === undefined || isNaN(Number(v))) return '—';
        return Number(v).toFixed(digits);
    }

    let activeChartTz = localStorage.getItem('gsmc_chart_tz') || userTz || 'Asia/Bangkok';

    function setChartTimezone(tz) {
        activeChartTz = tz || userTz || 'UTC';
        try {
            localStorage.setItem('gsmc_chart_tz', activeChartTz);
        } catch (_) {}
    }

    function getChartTimezone() {
        return activeChartTz;
    }

    function fmtTime(utcSeconds, includeDate = true, tzOverride = null) {
        if (!utcSeconds) return '—';
        const d = new Date(Number(utcSeconds) * 1000);
        const tz = tzOverride || activeChartTz || userTz || 'UTC';
        try {
            const opts = includeDate
                ? { timeZone: tz, month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }
                : { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
            return new Intl.DateTimeFormat('en-GB', opts).format(d);
        } catch (_) {
            return d.toISOString().slice(0, 16).replace('T', ' ');
        }
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    // ---- Topbar Clocks, Theme Toggle & Notifications ----
    function initTopbar() {
        const utcEl = document.getElementById('topClockUtc');
        const locEl = document.getElementById('topClockLocal');
        if (utcEl && locEl) {
            const tick = () => {
                const now = new Date();
                utcEl.textContent = now.toISOString().slice(11, 19) + ' UTC';
                try {
                    const localStr = new Intl.DateTimeFormat('en-GB', {
                        timeZone: userTz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
                    }).format(now);
                    locEl.textContent = localStr + ' (' + userTz + ')';
                } catch (_) {
                    locEl.textContent = userTz;
                }
            };
            tick();
            setInterval(tick, 1000);
        }

        const themeBtn = document.getElementById('themeToggleBtn');
        if (themeBtn) {
            themeBtn.addEventListener('click', () => {
                const cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
                const next = cur === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                window.dispatchEvent(new CustomEvent('smc:theme'));
            });
        }

        const langBtn = document.getElementById('langToggleBtn');
        if (langBtn) {
            langBtn.addEventListener('click', () => {
                const cur = document.documentElement.getAttribute('data-lang') === 'km' ? 'km' : 'en';
                const next = cur === 'km' ? 'en' : 'km';
                document.cookie = `app_lang=${next};path=/;max-age=31536000;SameSite=Lax`;
                window.location.reload();
            });
        }

        const bellBtn = document.getElementById('notifBellBtn');
        const drawer = document.getElementById('notifDrawer');
        const closeBtn = document.getElementById('closeNotifBtn');
        const markAllBtn = document.getElementById('markAllReadBtn');

        async function loadNotifs() {
            const list = document.getElementById('notifList');
            const badge = document.getElementById('notifBadge');
            if (!list) return;
            try {
                const res = await Api.get('api/notifications.php');
                if (badge) {
                    badge.textContent = res.unread || 0;
                    badge.classList.toggle('hidden', !res.unread);
                }
                const items = (res.notifications || []).filter(n => n.channel === 'inapp');
                if (!items.length) {
                    list.innerHTML = '<div class="muted">No notifications yet.</div>';
                    return;
                }
                list.innerHTML = items.map(n => `
                    <div class="notif-item ${Number(n.is_read) === 0 ? 'unread' : ''}">
                        <div style="display:flex;justify-content:space-between;gap:6px;">
                            <strong>${escapeHtml(n.title)}</strong>
                            <span class="muted mono" style="font-size:10.5px;">${escapeHtml(n.created_at.slice(5, 16))} UTC</span>
                        </div>
                        <div class="muted" style="margin-top:4px;white-space:pre-line;">${escapeHtml(n.message)}</div>
                    </div>
                `).join('');
            } catch (e) {
                list.innerHTML = '<div class="text-bear">' + escapeHtml(e.message) + '</div>';
            }
        }

        if (bellBtn && drawer) {
            bellBtn.addEventListener('click', () => {
                drawer.classList.toggle('hidden');
                if (!drawer.classList.contains('hidden')) loadNotifs();
            });
        }
        if (closeBtn && drawer) {
            closeBtn.addEventListener('click', () => drawer.classList.add('hidden'));
        }
        if (markAllBtn) {
            markAllBtn.addEventListener('click', async () => {
                await Api.post('api/notifications.php', { action: 'mark_read' });
                loadNotifs();
            });
        }
    }

    // =========================================================================
    // TradingView-Style Interactive Candlestick / OHLC / Heikin-Ashi + SMC Chart
    // =========================================================================
    const TF_SECONDS = {
        M1: 60, M5: 300, M15: 900, M30: 1800, H1: 3600, H4: 14400, D1: 86400, W1: 604800, MN1: 2592000,
    };

    const DEFAULT_STYLE = {
        barStyle: 'candles', // candles | hollow | ohlc | heikin_ashi | line | area
        bullColor: '#089981',
        bearColor: '#f23645',
        bodyWidthRatio: 0.72,
        showGrid: true,
        showVolume: true,
        showEma: true,
        showCountdown: true,
    };

    class SmcChart {
        constructor(canvas, hudEl) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');
            this.hud = hudEl;
            this.symbol = 'XAUUSD';
            this.tf = 'M15';
            this.candles = [];
            this.ema20 = [];
            this.ema50 = [];
            this.smc = null;
            this.keyLevels = {};
            this.sessionRanges = {};
            this.signal = null;
            this.overlays = {
                structure: true,
                ob: true,
                fvg: true,
                liquidity: true,
                pd: true,
                levels: true,
                signal: true,
            };

            let savedStyle = {};
            try {
                savedStyle = JSON.parse(localStorage.getItem('gsmc_tv_style') || '{}') || {};
            } catch (_) {}
            this.style = Object.assign({}, DEFAULT_STYLE, savedStyle);

            // Viewport & scaling state
            this.viewCount = 105;
            this.rightOffset = 0;
            this.priceScaleZoom = 1.0; // >1 zooms in vertically, <1 compresses
            this.pricePanOffset = 0.0; // vertical price offset when manually scaled

            // Interaction & Drawing tool state
            this.activeTool = 'cursor'; // cursor | trendline | hline | rect | ruler
            this.pendingPoint = null;
            this.measureResult = null;
            this.drawings = [];
            this.dragMode = null; // 'pan' | 'price_axis' | 'time_axis'
            this.dragStartX = 0;
            this.dragStartY = 0;
            this.dragStartOffset = 0;
            this.dragStartViewCount = 105;
            this.dragStartPriceZoom = 1.0;
            this.dragStartPricePan = 0.0;
            this.hoverX = null;
            this.hoverY = null;
            this.lastPlot = null;

            this.loadDrawings();
            this.bindEvents();
            setInterval(() => {
                if (this.style.showCountdown && this.candles.length) this.draw();
            }, 1000);
        }

        setData(candles, smc, keyLevels = {}, sessionRanges = {}, signal = null, symbol = null, tf = null) {
            if (symbol && symbol !== this.symbol) {
                this.symbol = symbol;
                this.priceScaleZoom = 1.0;
                this.pricePanOffset = 0.0;
                this.loadDrawings();
            }
            if (tf) this.tf = tf;
            this.candles = Array.isArray(candles) ? candles : [];
            this.ema20 = this.computeEma(this.candles, 20);
            this.ema50 = this.computeEma(this.candles, 50);
            this.smc = smc || null;
            this.keyLevels = keyLevels || {};
            this.sessionRanges = sessionRanges || {};
            this.signal = signal || null;
            this.rightOffset = Math.min(this.rightOffset, Math.max(0, this.candles.length - 15));
            this.draw();
        }

        updateStyle(patch) {
            Object.assign(this.style, patch);
            try {
                localStorage.setItem('gsmc_tv_style', JSON.stringify(this.style));
            } catch (_) {}
            this.draw();
        }

        resetStyle() {
            this.style = Object.assign({}, DEFAULT_STYLE);
            try {
                localStorage.setItem('gsmc_tv_style', JSON.stringify(this.style));
            } catch (_) {}
            this.draw();
        }

        resetView() {
            this.viewCount = 105;
            this.rightOffset = 0;
            this.priceScaleZoom = 1.0;
            this.pricePanOffset = 0.0;
            this.measureResult = null;
            this.pendingPoint = null;
            this.draw();
        }

        setTool(tool) {
            this.activeTool = tool;
            this.pendingPoint = null;
            if (tool !== 'ruler') this.measureResult = null;
            this.canvas.style.cursor = tool === 'cursor' ? 'crosshair' : 'cell';
            this.draw();
        }

        loadDrawings() {
            try {
                const raw = localStorage.getItem('gsmc_drawings_' + this.symbol);
                this.drawings = raw ? JSON.parse(raw) : [];
            } catch (_) {
                this.drawings = [];
            }
        }

        saveDrawings() {
            try {
                localStorage.setItem('gsmc_drawings_' + this.symbol, JSON.stringify(this.drawings));
            } catch (_) {}
        }

        clearDrawings() {
            this.drawings = [];
            this.pendingPoint = null;
            this.measureResult = null;
            this.saveDrawings();
            this.draw();
        }

        exportPng() {
            const url = this.canvas.toDataURL('image/png');
            const a = document.createElement('a');
            a.href = url;
            a.download = `${this.symbol}-${this.tf}-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.png`;
            a.click();
        }

        computeEma(candles, period) {
            const out = [];
            if (!candles.length) return out;
            const k = 2 / (period + 1);
            let ema = candles[0].c;
            for (let i = 0; i < candles.length; i++) {
                ema = i === 0 ? candles[0].c : candles[i].c * k + ema * (1 - k);
                out.push(ema);
            }
            return out;
        }

        computeHeikinAshi(slice) {
            const out = [];
            for (let i = 0; i < slice.length; i++) {
                const c = slice[i];
                const haC = (c.o + c.h + c.l + c.c) / 4;
                const haO = i === 0 ? (c.o + c.c) / 2 : (out[i - 1].o + out[i - 1].c) / 2;
                const haH = Math.max(c.h, haO, haC);
                const haL = Math.min(c.l, haO, haC);
                out.push({ t: c.t, o: haO, h: haH, l: haL, c: haC, v: c.v });
            }
            return out;
        }

        bindEvents() {
            window.addEventListener('resize', () => this.draw());
            window.addEventListener('smc:theme', () => this.draw());

            this.canvas.addEventListener('mousedown', (e) => {
                const rect = this.canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const P = this.lastPlot;
                if (!P) return;

                // Right Price Axis drag
                if (x >= P.padLeft + P.plotW) {
                    this.dragMode = 'price_axis';
                    this.dragStartY = y;
                    this.dragStartPriceZoom = this.priceScaleZoom;
                    return;
                }
                // Bottom Time Axis drag
                if (y >= P.padTop + P.plotH) {
                    this.dragMode = 'time_axis';
                    this.dragStartX = x;
                    this.dragStartViewCount = this.viewCount;
                    return;
                }

                // Drawing tool clicks inside plot area
                if (this.activeTool !== 'cursor') {
                    const pt = this.pointFromXY(x, y);
                    if (!pt) return;
                    if (this.activeTool === 'hline') {
                        this.drawings.push({ type: 'hline', price: pt.price, color: '#fbbf24' });
                        this.saveDrawings();
                        this.draw();
                        return;
                    }
                    if (!this.pendingPoint) {
                        this.pendingPoint = pt;
                    } else {
                        if (this.activeTool === 'ruler') {
                            this.measureResult = { p1: this.pendingPoint, p2: pt };
                        } else {
                            this.drawings.push({
                                type: this.activeTool,
                                t1: this.pendingPoint.t,
                                p1: this.pendingPoint.price,
                                t2: pt.t,
                                p2: pt.price,
                                color: this.activeTool === 'rect' ? 'rgba(41, 98, 255, 0.22)' : '#2962ff',
                            });
                            this.saveDrawings();
                        }
                        this.pendingPoint = null;
                    }
                    this.draw();
                    return;
                }

                // Default pan mode
                this.dragMode = 'pan';
                this.dragStartX = e.clientX;
                this.dragStartY = e.clientY;
                this.dragStartOffset = this.rightOffset;
                this.dragStartPricePan = this.pricePanOffset;
            });

            this.canvas.addEventListener('dblclick', (e) => {
                const rect = this.canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const P = this.lastPlot;
                if (!P) return;
                if (x >= P.padLeft + P.plotW) {
                    // Reset vertical price scale to auto-fit
                    this.priceScaleZoom = 1.0;
                    this.pricePanOffset = 0.0;
                    this.draw();
                } else if (y >= P.padTop + P.plotH) {
                    // Jump to latest bar
                    this.rightOffset = 0;
                    this.draw();
                }
            });

            window.addEventListener('mouseup', () => { this.dragMode = null; });

            this.canvas.addEventListener('mousemove', (e) => {
                const rect = this.canvas.getBoundingClientRect();
                this.hoverX = e.clientX - rect.left;
                this.hoverY = e.clientY - rect.top;
                const P = this.lastPlot;

                if (P && !this.dragMode) {
                    if (this.hoverX >= P.padLeft + P.plotW) {
                        this.canvas.style.cursor = 'ns-resize';
                    } else if (this.hoverY >= P.padTop + P.plotH) {
                        this.canvas.style.cursor = 'ew-resize';
                    } else {
                        this.canvas.style.cursor = this.activeTool === 'cursor' ? 'crosshair' : 'cell';
                    }
                }

                if (this.dragMode === 'pan' && this.candles.length && P) {
                    const dx = e.clientX - this.dragStartX;
                    const dy = e.clientY - this.dragStartY;
                    const step = P.plotW / Math.max(15, this.viewCount);
                    const deltaCandles = Math.round(dx / Math.max(2, step));
                    this.rightOffset = Math.max(0, Math.min(this.candles.length - 15, this.dragStartOffset + deltaCandles));
                    if (Math.abs(dy) > 3 && this.priceScaleZoom !== 1.0) {
                        const priceSpan = P.maxP - P.minP;
                        this.pricePanOffset = this.dragStartPricePan + (dy / P.plotH) * priceSpan;
                    }
                } else if (this.dragMode === 'price_axis') {
                    const dy = this.hoverY - this.dragStartY;
                    const factor = Math.exp(-dy * 0.006);
                    this.priceScaleZoom = Math.max(0.25, Math.min(6.0, this.dragStartPriceZoom * factor));
                } else if (this.dragMode === 'time_axis') {
                    const dx = this.hoverX - this.dragStartX;
                    this.viewCount = Math.max(20, Math.min(240, Math.round(this.dragStartViewCount + dx * 0.45)));
                }
                this.draw();
            });

            this.canvas.addEventListener('mouseleave', () => {
                this.hoverX = null;
                this.hoverY = null;
                this.draw();
            });

            this.canvas.addEventListener('wheel', (e) => {
                e.preventDefault();
                const delta = Math.sign(e.deltaY) * Math.max(4, Math.round(this.viewCount * 0.1));
                this.viewCount = Math.max(20, Math.min(240, this.viewCount + delta));
                this.draw();
            }, { passive: false });
        }

        pointFromXY(x, y) {
            const P = this.lastPlot;
            if (!P || !P.slice.length) return null;
            const idx = Math.min(P.slice.length - 1, Math.max(0, Math.floor((x - P.padLeft) / P.stepX)));
            return {
                idx,
                t: P.slice[idx].t,
                price: P.priceOf(y),
            };
        }

        draw() {
            const rect = this.canvas.parentElement.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            const W = Math.max(320, rect.width);
            const H = Math.max(280, rect.height);
            this.canvas.width = W * dpr;
            this.canvas.height = H * dpr;
            const ctx = this.ctx;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            const isLight = document.documentElement.getAttribute('data-theme') === 'light';
            const bg = isLight ? '#ffffff' : '#131722';
            const axisBg = isLight ? '#f8fafc' : '#131722';
            const gridColor = isLight ? '#e2e8f0' : '#1e222d';
            const borderColor = isLight ? '#cbd5e1' : '#2a2e39';
            const textColor = isLight ? '#475569' : '#9598a1';
            ctx.fillStyle = bg;
            ctx.fillRect(0, 0, W, H);

            if (!this.candles.length) {
                ctx.fillStyle = textColor;
                ctx.font = '13px sans-serif';
                ctx.fillText('Loading market candles...', 24, 48);
                return;
            }

            const padRight = 84;
            const padBottom = 26;
            const padTop = 14;
            const padLeft = 8;
            const plotW = W - padLeft - padRight;
            const plotH = H - padTop - padBottom;

            const n = this.candles.length;
            const endIdx = Math.max(1, n - this.rightOffset);
            const startIdx = Math.max(0, endIdx - this.viewCount);
            const rawSlice = this.candles.slice(startIdx, endIdx);
            const slice = this.style.barStyle === 'heikin_ashi' ? this.computeHeikinAshi(rawSlice) : rawSlice;
            const count = slice.length;
            if (!count) return;

            let minP = Infinity;
            let maxP = -Infinity;
            let maxVol = 1;
            for (const c of slice) {
                if (c.l < minP) minP = c.l;
                if (c.h > maxP) maxP = c.h;
                if ((c.v || 0) > maxVol) maxVol = c.v;
            }
            const candleSpan = Math.max(0.5, maxP - minP);
            if (this.overlays.signal && this.signal && this.signal.entry) {
                for (const k of ['sl', 'entry', 'tp1', 'tp2']) {
                    const v = Number(this.signal[k]);
                    if (v > 0 && Math.abs(v - (maxP + minP) / 2) <= candleSpan * 2.2) {
                        minP = Math.min(minP, v);
                        maxP = Math.max(maxP, v);
                    }
                }
            }
            const midP = (maxP + minP) / 2 + this.pricePanOffset;
            const halfSpan = Math.max(0.25, ((maxP - minP) * 1.14) / (2 * this.priceScaleZoom));
            minP = midP - halfSpan;
            maxP = midP + halfSpan;

            const yOf = (price) => padTop + (1 - (price - minP) / (maxP - minP)) * plotH;
            const priceOf = (y) => maxP - ((y - padTop) / plotH) * (maxP - minP);
            const stepX = plotW / Math.max(count + 4, 10);
            const xOfTime = (ts) => {
                let best = 0;
                for (let i = 0; i < count; i++) {
                    if (slice[i].t <= ts) best = i;
                    else break;
                }
                return padLeft + (best + 0.5) * stepX;
            };

            this.lastPlot = { padLeft, padTop, plotW, plotH, minP, maxP, stepX, slice, yOf, priceOf };

            ctx.save();
            ctx.beginPath();
            ctx.rect(padLeft, padTop, plotW, plotH);
            ctx.clip();

            // Grid lines (Horizontal & Vertical)
            const gridSteps = 8;
            if (this.style.showGrid) {
                ctx.strokeStyle = gridColor;
                ctx.lineWidth = 1;
                for (let i = 0; i <= gridSteps; i++) {
                    const p = minP + ((maxP - minP) * i) / gridSteps;
                    const y = Math.round(yOf(p)) + 0.5;
                    ctx.beginPath();
                    ctx.moveTo(padLeft, y);
                    ctx.lineTo(padLeft + plotW, y);
                    ctx.stroke();
                }
                const vStep = Math.max(1, Math.floor(count / 7));
                for (let i = 0; i < count; i += vStep) {
                    const x = Math.round(padLeft + (i + 0.5) * stepX) + 0.5;
                    ctx.beginPath();
                    ctx.moveTo(x, padTop);
                    ctx.lineTo(x, padTop + plotH);
                    ctx.stroke();
                }
            }

            // 1. Premium / Discount dealing range background
            if (this.overlays.pd && this.smc?.premium_discount) {
                const pd = this.smc.premium_discount;
                const yHi = yOf(pd.high);
                const yEq = yOf(pd.eq);
                const yLo = yOf(pd.low);
                ctx.fillStyle = 'rgba(242, 54, 69, 0.04)';
                ctx.fillRect(padLeft, yHi, plotW, Math.max(0, yEq - yHi));
                ctx.fillStyle = 'rgba(8, 153, 129, 0.04)';
                ctx.fillRect(padLeft, yEq, plotW, Math.max(0, yLo - yEq));
                this.drawLevelLine(ctx, yEq, padLeft, plotW, 'rgba(245, 158, 11, 0.6)', [4, 4], 'EQ 50% ' + pd.eq.toFixed(2));
            }

            // 2. Order Blocks
            if (this.overlays.ob && Array.isArray(this.smc?.order_blocks)) {
                for (const ob of this.smc.order_blocks) {
                    if (ob.status === 'invalidated' || ob.status === 'expired') continue;
                    const x1 = Math.max(padLeft, xOfTime(ob.t));
                    const x2 = padLeft + plotW;
                    const yTop = yOf(ob.top);
                    const yBot = yOf(ob.bottom);
                    const isBull = ob.dir === 1;
                    ctx.fillStyle = isBull
                        ? (ob.status === 'fresh' ? 'rgba(8, 153, 129, 0.18)' : 'rgba(8, 153, 129, 0.07)')
                        : (ob.status === 'fresh' ? 'rgba(242, 54, 69, 0.18)' : 'rgba(242, 54, 69, 0.07)');
                    ctx.strokeStyle = isBull ? 'rgba(8, 153, 129, 0.6)' : 'rgba(242, 54, 69, 0.6)';
                    ctx.fillRect(x1, yTop, x2 - x1, Math.max(2, yBot - yTop));
                    ctx.strokeRect(x1, yTop, x2 - x1, Math.max(2, yBot - yTop));
                    ctx.fillStyle = isBull ? this.style.bullColor : this.style.bearColor;
                    ctx.font = '10px JetBrains Mono, monospace';
                    ctx.fillText((isBull ? '+OB ' : '-OB ') + '(' + ob.status + ')', x1 + 4, yTop + 11);
                }
            }

            // 3. Fair Value Gaps (FVGs)
            if (this.overlays.fvg && Array.isArray(this.smc?.fvgs)) {
                for (const fvg of this.smc.fvgs) {
                    if (fvg.status !== 'fresh' && fvg.status !== 'partial') continue;
                    const x1 = Math.max(padLeft, xOfTime(fvg.t));
                    const x2 = padLeft + plotW;
                    const yTop = yOf(fvg.top);
                    const yBot = yOf(fvg.bottom);
                    ctx.fillStyle = fvg.dir === 1 ? 'rgba(6, 182, 212, 0.13)' : 'rgba(168, 85, 247, 0.13)';
                    ctx.fillRect(x1, yTop, x2 - x1, Math.max(2, yBot - yTop));
                    ctx.fillStyle = fvg.dir === 1 ? '#06b6d4' : '#a855f7';
                    ctx.font = '9.5px JetBrains Mono, monospace';
                    ctx.fillText('FVG ' + fvg.fill_pct + '%', x1 + 4, (yTop + yBot) / 2 + 3);
                }
            }

            // 4. Key Levels (PDH/PDL/PWH/PWL)
            if (this.overlays.levels && this.keyLevels) {
                const map = [
                    ['pdh', 'PDH', '#f59e0b'],
                    ['pdl', 'PDL', '#f59e0b'],
                    ['pwh', 'PWH', '#a855f7'],
                    ['pwl', 'PWL', '#a855f7'],
                ];
                for (const [k, lbl, col] of map) {
                    if (this.keyLevels[k]) {
                        const p = Number(this.keyLevels[k]);
                        this.drawLevelLine(ctx, yOf(p), padLeft, plotW, col, [2, 4], lbl + ' ' + p.toFixed(2));
                    }
                }
            }

            // 5. Liquidity Pools (BSL/SSL & Sweeps)
            if (this.overlays.liquidity && this.smc?.liquidity) {
                const bsl = (this.smc.liquidity.bsl || []).filter(l => l.status === 'active').slice(-3);
                const ssl = (this.smc.liquidity.ssl || []).filter(l => l.status === 'active').slice(-3);
                for (const l of bsl) {
                    const x1 = Math.max(padLeft, xOfTime(l.t));
                    this.drawSegmentLine(ctx, x1, padLeft + plotW, yOf(l.price), 'rgba(6, 182, 212, 0.75)', [3, 3], 'BSL ' + l.price.toFixed(2));
                }
                for (const l of ssl) {
                    const x1 = Math.max(padLeft, xOfTime(l.t));
                    this.drawSegmentLine(ctx, x1, padLeft + plotW, yOf(l.price), 'rgba(6, 182, 212, 0.75)', [3, 3], 'SSL ' + l.price.toFixed(2));
                }
                for (const sw of (this.smc.liquidity.sweeps || []).slice(-6)) {
                    if (sw.t < slice[0].t) continue;
                    const x = xOfTime(sw.t);
                    const y = yOf(sw.extreme);
                    ctx.fillStyle = '#fbbf24';
                    ctx.font = 'bold 10px JetBrains Mono, monospace';
                    ctx.fillText('⚡SWEEP ' + sw.type, x - 18, sw.dir === 1 ? y + 12 : y - 5);
                }
            }

            // 6. Structure breaks (BOS / CHOCH / MSS)
            if (this.overlays.structure && Array.isArray(this.smc?.events)) {
                for (const ev of this.smc.events.slice(-12)) {
                    if (ev.t < slice[0].t) continue;
                    const x1 = Math.max(padLeft, xOfTime(ev.swing_t));
                    const x2 = xOfTime(ev.t);
                    const y = yOf(ev.level);
                    const col = ev.dir === 1 ? this.style.bullColor : this.style.bearColor;
                    ctx.strokeStyle = col;
                    ctx.lineWidth = ev.type === 'CHOCH' ? 1.6 : 1.1;
                    ctx.setLineDash(ev.type === 'CHOCH' ? [] : [4, 2]);
                    ctx.beginPath();
                    ctx.moveTo(x1, y);
                    ctx.lineTo(x2, y);
                    ctx.stroke();
                    ctx.setLineDash([]);
                    ctx.fillStyle = col;
                    ctx.font = 'bold 10px JetBrains Mono, monospace';
                    const lbl = ev.type + (ev.mss ? ' (MSS)' : '');
                    ctx.fillText(lbl, (x1 + x2) / 2 - 14, ev.dir === 1 ? y - 4 : y + 11);
                }
            }

            // 7. Volume Histogram (bottom 14% of plot area)
            if (this.style.showVolume) {
                const volMaxH = plotH * 0.14;
                const volW = Math.max(1.5, stepX * 0.6);
                for (let i = 0; i < count; i++) {
                    const c = rawSlice[i];
                    const v = c.v || 0;
                    if (v <= 0) continue;
                    const h = (v / maxVol) * volMaxH;
                    const x = padLeft + (i + 0.5) * stepX;
                    ctx.fillStyle = c.c >= c.o ? 'rgba(8, 153, 129, 0.22)' : 'rgba(242, 54, 69, 0.22)';
                    ctx.fillRect(x - volW / 2, padTop + plotH - h, volW, h);
                }
            }

            // 8. EMA 20 & EMA 50 Lines
            if (this.style.showEma) {
                const drawMa = (arr, color) => {
                    const sub = arr.slice(startIdx, endIdx);
                    if (sub.length < 2) return;
                    ctx.strokeStyle = color;
                    ctx.lineWidth = 1.4;
                    ctx.beginPath();
                    for (let i = 0; i < sub.length; i++) {
                        const x = padLeft + (i + 0.5) * stepX;
                        const y = yOf(sub[i]);
                        if (i === 0) ctx.moveTo(x, y);
                        else ctx.lineTo(x, y);
                    }
                    ctx.stroke();
                };
                drawMa(this.ema20, '#2962ff');
                drawMa(this.ema50, '#ff9800');
            }

            // 9. Price Series (Candles | Hollow | OHLC Bars | Heikin Ashi | Line | Area)
            const bs = this.style.barStyle;
            const bodyW = Math.max(2, Math.min(18, stepX * Number(this.style.bodyWidthRatio || 0.72)));

            if (bs === 'line' || bs === 'area') {
                ctx.strokeStyle = '#2962ff';
                ctx.lineWidth = 2;
                ctx.beginPath();
                for (let i = 0; i < count; i++) {
                    const x = padLeft + (i + 0.5) * stepX;
                    const y = yOf(slice[i].c);
                    if (i === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                }
                ctx.stroke();
                if (bs === 'area') {
                    const lastX = padLeft + (count - 0.5) * stepX;
                    ctx.lineTo(lastX, padTop + plotH);
                    ctx.lineTo(padLeft + 0.5 * stepX, padTop + plotH);
                    ctx.closePath();
                    const grad = ctx.createLinearGradient(0, padTop, 0, padTop + plotH);
                    grad.addColorStop(0, 'rgba(41, 98, 255, 0.28)');
                    grad.addColorStop(1, 'rgba(41, 98, 255, 0.0)');
                    ctx.fillStyle = grad;
                    ctx.fill();
                }
            } else if (bs === 'ohlc') {
                const tickW = Math.max(2, bodyW / 2);
                for (let i = 0; i < count; i++) {
                    const c = slice[i];
                    const x = padLeft + (i + 0.5) * stepX;
                    const yO = yOf(c.o);
                    const yC = yOf(c.c);
                    const yH = yOf(c.h);
                    const yL = yOf(c.l);
                    const col = c.c >= c.o ? this.style.bullColor : this.style.bearColor;
                    ctx.strokeStyle = col;
                    ctx.lineWidth = 1.4;
                    ctx.beginPath();
                    ctx.moveTo(x, yH);
                    ctx.lineTo(x, yL);
                    ctx.moveTo(x - tickW, yO);
                    ctx.lineTo(x, yO);
                    ctx.moveTo(x, yC);
                    ctx.lineTo(x + tickW, yC);
                    ctx.stroke();
                }
            } else {
                // candles | hollow | heikin_ashi
                for (let i = 0; i < count; i++) {
                    const c = slice[i];
                    const x = padLeft + (i + 0.5) * stepX;
                    const yO = yOf(c.o);
                    const yC = yOf(c.c);
                    const yH = yOf(c.h);
                    const yL = yOf(c.l);
                    const bull = c.c >= c.o;
                    const col = bull ? this.style.bullColor : this.style.bearColor;
                    const top = Math.min(yO, yC);
                    const h = Math.max(1.5, Math.abs(yC - yO));

                    ctx.strokeStyle = col;
                    ctx.lineWidth = 1.15;
                    ctx.beginPath();
                    if (bs === 'hollow' && bull) {
                        ctx.moveTo(x, yH);
                        ctx.lineTo(x, top);
                        ctx.moveTo(x, top + h);
                        ctx.lineTo(x, yL);
                    } else {
                        ctx.moveTo(x, yH);
                        ctx.lineTo(x, yL);
                    }
                    ctx.stroke();

                    if (bs === 'hollow' && bull) {
                        ctx.strokeRect(x - bodyW / 2, top, bodyW, h);
                    } else {
                        ctx.fillStyle = col;
                        ctx.fillRect(x - bodyW / 2, top, bodyW, h);
                    }
                }
            }

            // 10. Active / Potential Signal Lines & TradingView Position Risk/Reward Box
            if (this.overlays.signal && this.signal && this.signal.entry && this.signal.sl) {
                const s = this.signal;
                const isWait = s.direction === 'WAIT';
                const prefix = isWait ? 'WATCH ' : '';
                const yEnt = yOf(Number(s.entry));
                const ySl = yOf(Number(s.sl));
                const boxX = padLeft + plotW * 0.58;
                const boxW = plotW * 0.42;

                // Stop-Loss Risk Box (Entry -> SL)
                ctx.fillStyle = 'rgba(242, 54, 69, 0.13)';
                ctx.fillRect(boxX, Math.min(yEnt, ySl), boxW, Math.max(2, Math.abs(ySl - yEnt)));

                // Take-Profit Reward Box (Entry -> TP2 or TP1)
                const tpTarget = Number(s.tp2 || s.tp1 || 0);
                if (tpTarget > 0) {
                    const yTp = yOf(tpTarget);
                    ctx.fillStyle = 'rgba(8, 153, 129, 0.13)';
                    ctx.fillRect(boxX, Math.min(yEnt, yTp), boxW, Math.max(2, Math.abs(yTp - yEnt)));
                }

                // Entry Zone band [entry_low .. entry_high]
                if (s.entry_low && s.entry_high) {
                    const yLo = yOf(Number(s.entry_low));
                    const yHi = yOf(Number(s.entry_high));
                    ctx.fillStyle = 'rgba(251, 191, 36, 0.16)';
                    ctx.fillRect(boxX, Math.min(yLo, yHi), boxW, Math.max(2, Math.abs(yLo - yHi)));
                }

                this.drawLevelLine(ctx, yEnt, padLeft, plotW, '#fbbf24', isWait ? [4, 3] : [], prefix + 'ENTRY ' + Number(s.entry).toFixed(2));
                this.drawLevelLine(ctx, ySl, padLeft, plotW, '#f23645', [5, 3], prefix + 'SL ' + Number(s.sl).toFixed(2));
                if (s.tp1) this.drawLevelLine(ctx, yOf(s.tp1), padLeft, plotW, '#089981', [5, 3], prefix + 'TP1 ' + Number(s.tp1).toFixed(2) + (s.rr1 ? ' (' + s.rr1 + 'R)' : ''));
                if (s.tp2) this.drawLevelLine(ctx, yOf(s.tp2), padLeft, plotW, '#089981', [2, 2], prefix + 'TP2 ' + Number(s.tp2).toFixed(2) + (s.rr2 ? ' (' + s.rr2 + 'R)' : ''));
            }

            // 11. User Drawings (Trendlines, Horizontal Levels, Rectangles)
            for (const d of this.drawings) {
                if (d.type === 'hline') {
                    this.drawLevelLine(ctx, yOf(d.price), padLeft, plotW, d.color || '#fbbf24', [], 'LEVEL ' + Number(d.price).toFixed(2));
                } else if (d.type === 'trendline') {
                    const x1 = xOfTime(d.t1);
                    const y1 = yOf(d.p1);
                    const x2 = xOfTime(d.t2);
                    const y2 = yOf(d.p2);
                    ctx.strokeStyle = d.color || '#2962ff';
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.moveTo(x1, y1);
                    ctx.lineTo(x2, y2);
                    ctx.stroke();
                    ctx.fillStyle = '#2962ff';
                    ctx.beginPath(); ctx.arc(x1, y1, 3.5, 0, Math.PI * 2); ctx.fill();
                    ctx.beginPath(); ctx.arc(x2, y2, 3.5, 0, Math.PI * 2); ctx.fill();
                } else if (d.type === 'rect') {
                    const x1 = xOfTime(d.t1);
                    const y1 = yOf(d.p1);
                    const x2 = xOfTime(d.t2);
                    const y2 = yOf(d.p2);
                    ctx.fillStyle = d.color || 'rgba(41, 98, 255, 0.22)';
                    ctx.strokeStyle = '#2962ff';
                    ctx.lineWidth = 1.2;
                    ctx.fillRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
                    ctx.strokeRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
                }
            }

            // Live preview of in-progress drawing or Measure Ruler
            if (this.pendingPoint && this.hoverX !== null && this.hoverY !== null) {
                const x1 = xOfTime(this.pendingPoint.t);
                const y1 = yOf(this.pendingPoint.price);
                const x2 = this.hoverX;
                const y2 = this.hoverY;
                if (this.activeTool === 'rect' || this.activeTool === 'ruler') {
                    ctx.fillStyle = this.activeTool === 'ruler' ? 'rgba(41, 98, 255, 0.16)' : 'rgba(41, 98, 255, 0.22)';
                    ctx.strokeStyle = '#2962ff';
                    ctx.fillRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
                    ctx.strokeRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
                } else {
                    ctx.strokeStyle = '#2962ff';
                    ctx.lineWidth = 1.8;
                    ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
                }
            }

            if (this.measureResult) {
                const { p1, p2 } = this.measureResult;
                const x1 = xOfTime(p1.t);
                const y1 = yOf(p1.price);
                const x2 = xOfTime(p2.t);
                const y2 = yOf(p2.price);
                const diff = p2.price - p1.price;
                const pct = (diff / p1.price) * 100;
                const bars = Math.abs(p2.idx - p1.idx);
                ctx.fillStyle = diff >= 0 ? 'rgba(8, 153, 129, 0.2)' : 'rgba(242, 54, 69, 0.2)';
                ctx.strokeStyle = diff >= 0 ? this.style.bullColor : this.style.bearColor;
                ctx.fillRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
                ctx.strokeRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));

                const txt = `${diff >= 0 ? '+' : ''}${diff.toFixed(2)} (${diff >= 0 ? '+' : ''}${pct.toFixed(2)}%) · ${bars} bars`;
                ctx.font = 'bold 11px JetBrains Mono, monospace';
                const tw = ctx.measureText(txt).width + 12;
                const bx = (x1 + x2) / 2 - tw / 2;
                const by = Math.min(y1, y2) - 24;
                ctx.fillStyle = '#1e222d';
                ctx.fillRect(bx, by, tw, 20);
                ctx.fillStyle = '#f8fafc';
                ctx.fillText(txt, bx + 6, by + 14);
            }

            // Last close horizontal dotted price line across plot
            const last = rawSlice[count - 1];
            const yLast = yOf(last.c);
            const lastBull = last.c >= last.o;
            const lastCol = lastBull ? this.style.bullColor : this.style.bearColor;
            ctx.strokeStyle = lastCol;
            ctx.lineWidth = 1;
            ctx.setLineDash([2, 2]);
            ctx.beginPath();
            ctx.moveTo(padLeft, yLast);
            ctx.lineTo(padLeft + plotW, yLast);
            ctx.stroke();
            ctx.setLineDash([]);

            ctx.restore(); // end plot clip

            // Right Price Axis & Bottom Time Axis Borders
            ctx.fillStyle = axisBg;
            ctx.fillRect(padLeft + plotW, 0, padRight, H);
            ctx.fillRect(0, padTop + plotH, W, padBottom);
            ctx.strokeStyle = borderColor;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(padLeft + plotW + 0.5, 0);
            ctx.lineTo(padLeft + plotW + 0.5, H);
            ctx.moveTo(0, padTop + plotH + 0.5);
            ctx.lineTo(W, padTop + plotH + 0.5);
            ctx.stroke();

            // Price Axis Labels
            ctx.fillStyle = textColor;
            ctx.font = '11px JetBrains Mono, monospace';
            for (let i = 0; i <= gridSteps; i++) {
                const p = minP + ((maxP - minP) * i) / gridSteps;
                const y = Math.round(yOf(p));
                if (y >= padTop + 8 && y <= padTop + plotH - 8) {
                    ctx.fillText(p.toFixed(2), padLeft + plotW + 6, y + 4);
                }
            }

            // Time Axis Labels
            const vStep = Math.max(1, Math.floor(count / 7));
            for (let i = 0; i < count; i += vStep) {
                const x = padLeft + (i + 0.5) * stepX;
                ctx.fillStyle = textColor;
                ctx.fillText(fmtTime(slice[i].t, true), x - 26, H - 8);
            }

            // Live Price Tag + Countdown on Right Axis
            if (yLast >= padTop && yLast <= padTop + plotH) {
                const secTf = TF_SECONDS[this.tf] || 900;
                const remSec = Math.max(0, secTf - (Math.floor(Date.now() / 1000) % secTf));
                const mm = String(Math.floor(remSec / 60)).padStart(2, '0');
                const ss = String(remSec % 60).padStart(2, '0');
                const tagH = this.style.showCountdown ? 32 : 18;
                ctx.fillStyle = lastCol;
                ctx.fillRect(padLeft + plotW + 1, yLast - 9, padRight - 2, tagH);
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 11px JetBrains Mono, monospace';
                ctx.fillText(last.c.toFixed(2), padLeft + plotW + 6, yLast + 4);
                if (this.style.showCountdown) {
                    ctx.font = '10px JetBrains Mono, monospace';
                    ctx.fillText(`${mm}:${ss}`, padLeft + plotW + 6, yLast + 18);
                }
            }

            // Crosshair & Legend HUD
            let hudIdx = count - 1;
            if (this.hoverX !== null && this.hoverY !== null && this.hoverX >= padLeft && this.hoverX <= padLeft + plotW && this.hoverY >= padTop && this.hoverY <= padTop + plotH) {
                hudIdx = Math.min(count - 1, Math.max(0, Math.floor((this.hoverX - padLeft) / stepX)));
                const cx = padLeft + (hudIdx + 0.5) * stepX;

                ctx.strokeStyle = 'rgba(149, 152, 161, 0.55)';
                ctx.lineWidth = 1;
                ctx.setLineDash([4, 4]);
                ctx.beginPath();
                ctx.moveTo(cx, padTop);
                ctx.lineTo(cx, padTop + plotH);
                ctx.moveTo(padLeft, this.hoverY);
                ctx.lineTo(padLeft + plotW, this.hoverY);
                ctx.stroke();
                ctx.setLineDash([]);

                // Crosshair Price Pill on Right Axis
                const pHover = priceOf(this.hoverY);
                ctx.fillStyle = '#363a45';
                ctx.fillRect(padLeft + plotW + 1, this.hoverY - 9, padRight - 2, 18);
                ctx.fillStyle = '#f8fafc';
                ctx.font = '10.5px JetBrains Mono, monospace';
                ctx.fillText(pHover.toFixed(2), padLeft + plotW + 6, this.hoverY + 4);

                // Crosshair Time Pill on Bottom Axis
                const tStr = fmtTime(slice[hudIdx].t, true);
                const tw = ctx.measureText(tStr).width + 14;
                ctx.fillStyle = '#363a45';
                ctx.fillRect(Math.max(0, cx - tw / 2), padTop + plotH + 1, tw, 20);
                ctx.fillStyle = '#f8fafc';
                ctx.fillText(tStr, Math.max(4, cx - tw / 2 + 7), padTop + plotH + 15);
            }

            if (this.hud && slice[hudIdx]) {
                const hc = slice[hudIdx];
                const chg = hc.c - hc.o;
                const pct = hc.o ? (chg / hc.o) * 100 : 0;
                const sign = chg >= 0 ? '+' : '';
                const colCls = chg >= 0 ? 'text-bull' : 'text-bear';
                const e20 = this.ema20[startIdx + hudIdx];
                const e50 = this.ema50[startIdx + hudIdx];
                const isLiveBar = (startIdx + hudIdx) === (this.candles.length - 1);
                this.hud.innerHTML = `
                    <div>
                        <strong>${this.symbol} · ${this.tf}</strong> ·
                        <span class="badge ${isLiveBar ? 'badge-live' : 'badge-neutral'}" style="padding:1px 5px;font-size:10px;">
                            ${isLiveBar ? '● LIVE BAR' : 'BAR'} ${fmtTime(hc.t, true)} (${escapeHtml(activeChartTz)})
                        </span> ·
                        <span>O <strong class="${colCls}">${hc.o.toFixed(2)}</strong></span>
                        <span>H <strong class="${colCls}">${hc.h.toFixed(2)}</strong></span>
                        <span>L <strong class="${colCls}">${hc.l.toFixed(2)}</strong></span>
                        <span>C <strong class="${colCls}">${hc.c.toFixed(2)}</strong></span>
                        <span class="${colCls}">${sign}${chg.toFixed(2)} (${sign}${pct.toFixed(2)}%)</span>
                        ${hc.v ? ` · <span>Vol <strong>${Math.round(hc.v)}</strong></span>` : ''}
                    </div>
                    <div style="font-size:10.5px;opacity:0.9;">
                        ${this.style.showEma && e20 ? `<span style="color:#2962ff;">EMA(20) ${e20.toFixed(2)}</span> · <span style="color:#ff9800;">EMA(50) ${e50.toFixed(2)}</span> · ` : ''}
                        ${this.smc ? `<span>Structure: <strong class="${this.smc.trend === 1 ? 'text-bull' : (this.smc.trend === -1 ? 'text-bear' : '')}">${this.smc.trend_label.toUpperCase()}</strong></span> · <span>ATR(14): ${Number(this.smc.atr).toFixed(2)}</span>` : ''}
                    </div>
                `;
            }
        }

        drawLevelLine(ctx, y, x1, width, color, dash, label) {
            this.drawSegmentLine(ctx, x1, x1 + width, y, color, dash, label);
        }

        drawSegmentLine(ctx, x1, x2, y, color, dash, label) {
            ctx.save();
            ctx.strokeStyle = color;
            ctx.lineWidth = 1.1;
            ctx.setLineDash(dash || []);
            ctx.beginPath();
            ctx.moveTo(x1, y);
            ctx.lineTo(x2, y);
            ctx.stroke();
            ctx.setLineDash([]);
            if (label) {
                ctx.fillStyle = color;
                ctx.font = '10px JetBrains Mono, monospace';
                ctx.fillText(label, x2 - ctx.measureText(label).width - 6, y - 3);
            }
            ctx.restore();
        }
    }

    // =========================================================================
    // Simple Equity / Cumulative-R Curve Renderer
    // =========================================================================
    function renderEquityCurve(canvas, points, valueKey = 'cum_r', suffix = 'R') {
        if (!canvas) return;
        const rect = canvas.parentElement.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        const W = Math.max(260, rect.width);
        const H = Math.max(180, canvas.height || 200);
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        const isLight = document.documentElement.getAttribute('data-theme') === 'light';
        ctx.fillStyle = isLight ? '#f8fafc' : '#090d16';
        ctx.fillRect(0, 0, W, H);

        if (!Array.isArray(points) || points.length < 2) {
            ctx.fillStyle = '#8b9bb4';
            ctx.font = '12px sans-serif';
            ctx.fillText('Insufficient closed trades to plot curve.', 20, H / 2);
            return;
        }

        const vals = points.map(p => Number(p[valueKey] || 0));
        let minV = Math.min(...vals);
        let maxV = Math.max(...vals);
        if (minV === maxV) { minV -= 1; maxV += 1; }
        const pad = 28;
        const plotW = W - pad * 2;
        const plotH = H - pad * 2;

        const xOf = (i) => pad + (i / (vals.length - 1)) * plotW;
        const yOf = (v) => pad + (1 - (v - minV) / (maxV - minV)) * plotH;

        ctx.strokeStyle = 'rgba(148, 163, 184, 0.2)';
        ctx.beginPath();
        const yZero = yOf(vals[0]);
        ctx.moveTo(pad, yZero);
        ctx.lineTo(W - pad, yZero);
        ctx.stroke();

        const positive = vals[vals.length - 1] >= vals[0];
        ctx.strokeStyle = positive ? '#10b981' : '#ef4444';
        ctx.lineWidth = 2;
        ctx.beginPath();
        vals.forEach((v, i) => {
            const x = xOf(i);
            const y = yOf(v);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();

        ctx.fillStyle = '#8b9bb4';
        ctx.font = '11px JetBrains Mono, monospace';
        ctx.fillText('Start: ' + vals[0].toFixed(2) + suffix, pad, H - 8);
        const endTxt = 'End: ' + vals[vals.length - 1].toFixed(2) + suffix;
        ctx.fillText(endTxt, W - pad - ctx.measureText(endTxt).width, H - 8);
    }

    document.addEventListener('DOMContentLoaded', initTopbar);

    window.GoldSmc = {
        Api,
        SmcChart,
        renderEquityCurve,
        toast,
        showEntryPrepPopup,
        checkSignalReadiness,
        fmtPrice,
        fmtTime,
        setChartTimezone,
        getChartTimezone,
        escapeHtml,
    };
})();
