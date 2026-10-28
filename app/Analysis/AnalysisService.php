<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Notifier;
use App\Core\Settings;
use App\Market\MarketDataService;
use App\Market\Timeframe;

/**
 * Orchestrates market data ingestion, multi-timeframe SMC analysis,
 * database persistence of structures/zones/signals, outcome tracking,
 * and dashboard state assembly.
 */
final class AnalysisService
{
    private MarketDataService $market;
    private CalendarService $calendar;
    private SignalTracker $tracker;

    public function __construct(
        ?MarketDataService $market = null,
        ?CalendarService $calendar = null,
        ?SignalTracker $tracker = null,
        ?string $symbol = null
    ) {
        $this->market = $market ?? new MarketDataService(null, $symbol);
        $this->calendar = $calendar ?? new CalendarService();
        $this->tracker = $tracker ?? new SignalTracker();
    }

    public function market(): MarketDataService { return $this->market; }
    public function calendar(): CalendarService { return $this->calendar; }
    public function tracker(): SignalTracker { return $this->tracker; }

    /**
     * Execute full ingestion + multi-timeframe SMC analysis + persistence + signal tracking.
     */
    public function run(bool $forceIngest = false): array
    {
        $now = time();
        $tfs = MarketDataService::requiredTimeframes();
        $ingestReport = [];

        foreach ($tfs as $tf) {
            $ingestReport[$tf] = $this->market->ingest($tf, $forceIngest);
        }

        // Sync economic calendar when due
        $calSync = $this->calendar->sync(false);
        $calCtx = $this->calendar->contextForAnalysis($now);

        // Update lifecycle of any previously generated signals
        $this->tracker->updateOpenSignals($this->market, $now);

        $quote = $this->market->quote($forceIngest);
        $freshness = $this->market->freshness($tfs);

        $candlesPerTf = (int)Settings::get('market.candles_per_tf', 500);
        $candlesByTf = [];
        foreach ($tfs as $tf) {
            $candlesByTf[$tf] = $this->market->candles($tf, $candlesPerTf, $now);
        }

        $symMeta = MarketDataService::symbolMeta($this->market->symbol());
        $spread = (float)($symMeta['assumed_spread'] ?? Settings::get('signal.assumed_spread', 0.30));
        $spreadSource = 'assumed';
        if (!empty($quote['bid']) && !empty($quote['ask']) && $quote['ask'] > $quote['bid']) {
            $spread = round((float)$quote['ask'] - (float)$quote['bid'], 3);
            $spreadSource = 'provider';
        }

        $ctx = [
            'now'            => $now,
            'price'          => $quote['available'] ? (float)$quote['price'] : null,
            'spread'         => $spread,
            'spread_source'  => $spreadSource,
            'events'         => $calCtx['events'],
            'news_connected' => $calCtx['connected'],
            'data_mode'      => $this->market->mode(),
            'source'         => $this->market->provider()->label(),
        ];

        $analyzer = MultiTimeframeAnalyzer::fromSettings($this->market->symbol());
        $analysis = $analyzer->analyze($candlesByTf, $ctx);
        $ai = AiExplainer::explain($analysis);
        $analysis['signal']['ai_explanation'] = $ai['explanation'];
        $analysis['signal']['ai_provider'] = $ai['provider'];
        $analysis['signal']['standard_report'] = AiExplainer::standardReport($analysis);
        $analysis['standard_report'] = $analysis['signal']['standard_report'];
        $analysis['data_freshness'] = $freshness;
        $analysis['ingest'] = $ingestReport;
        $analysis['calendar_sync'] = $calSync;

        if (!empty($analysis['ok'])) {
            $persisted = $this->persist($analysis, $freshness, $now);
            $analysis['analysis_id'] = $persisted['analysis_id'];
            $analysis['signal_id'] = $persisted['signal_id'];
        }

        return $analysis;
    }

    /**
     * Get the complete state needed by the main dashboard for a chosen chart timeframe.
     */
    public function dashboardState(string $chartTf = 'M15', ?int $userId = null): array
    {
        if (!Timeframe::valid($chartTf)) {
            $chartTf = (string)Settings::get('market.entry_tf', 'M15');
        }

        // Ensure we have candles and an up-to-date analysis for the active provider
        $latestRow = Database::fetch(
            'SELECT * FROM market_analysis WHERE source_id = ? AND symbol = ? ORDER BY id DESC LIMIT 1',
            [$this->market->sourceId(), $this->market->symbol()]
        );

        $maxAge = (int)(Settings::get('market.refresh_minutes')[$chartTf] ?? 5) * 60;
        if (!$latestRow || (time() - (int)strtotime($latestRow['analyzed_at'] . ' UTC') > $maxAge)) {
            $this->run(false);
            $latestRow = Database::fetch(
                'SELECT * FROM market_analysis WHERE source_id = ? AND symbol = ? ORDER BY id DESC LIMIT 1',
                [$this->market->sourceId(), $this->market->symbol()]
            );
        }

        // Make sure the requested chart timeframe is ingested up to current live bar
        $this->market->ingest($chartTf, false);
        $chartCandles = $this->market->candles($chartTf, 300);
        if (count($chartCandles) < 40) {
            $this->market->ingest($chartTf, true);
            $chartCandles = $this->market->candles($chartTf, 300);
        }

        $now = time();
        $closedChart = Timeframe::closedOnly($chartTf, $chartCandles, $now);
        $engine = new \App\SMC\SmcEngine((array)Settings::get('smc', []));
        $chartSmc = $engine->analyze($closedChart);
        $trendEngine = new TrendAnalysisEngine();
        $chartTrend = $trendEngine->analyze($closedChart);
        $sessions = new SessionService();

        $analysisPayload = $latestRow ? json_decode((string)$latestRow['result'], true) : null;
        if ($analysisPayload) {
            if (empty($analysisPayload['trend_analysis'])) {
                $analysisPayload['trend_analysis'] = $chartTrend;
            }
            if (empty($analysisPayload['standard_report'])) {
                $analysisPayload['standard_report'] = AiExplainer::standardReport($analysisPayload);
            }
        }
        $freshness = $latestRow && $latestRow['data_freshness']
            ? json_decode((string)$latestRow['data_freshness'], true)
            : $this->market->freshness(MarketDataService::requiredTimeframes());

        $recentSignals = Database::fetchAll(
            'SELECT s.*, o.outcome, o.r_multiple, o.entry_filled_at, o.exit_at, o.exit_reason
             FROM trading_signals s
             LEFT JOIN signal_outcomes o ON o.signal_id = s.id
             WHERE s.data_mode = ? AND s.symbol = ?
             ORDER BY s.id DESC LIMIT 15',
            [$this->market->mode(), $this->market->symbol()]
        );

        $quote = $this->market->quote(false);
        // Sync rightmost live candle with latest live spot price
        $displayCandles = array_slice($chartCandles, -220);
        if ($displayCandles && !empty($quote['available']) && !empty($quote['price'])) {
            $lp = round((float)$quote['price'], 2);
            $lastIdx = count($displayCandles) - 1;
            $displayCandles[$lastIdx]['c'] = $lp;
            $displayCandles[$lastIdx]['h'] = max($displayCandles[$lastIdx]['h'], $lp);
            $displayCandles[$lastIdx]['l'] = min($displayCandles[$lastIdx]['l'], $lp);
        }
        $calCtx = $this->calendar->contextForAnalysis($now);
        $stats = $this->tracker->performanceStats($this->market->mode(), $this->market->symbol());

        $unreadNotifs = [];
        if ($userId) {
            $unreadNotifs = Database::fetchAll(
                "SELECT id, event_type, title, message, signal_id, is_read, created_at
                 FROM notification_logs
                 WHERE user_id = ? AND channel = 'inapp'
                 ORDER BY id DESC LIMIT 20",
                [$userId]
            );
        }

        return [
            'symbol'          => $this->market->symbol(),
            'symbol_meta'     => MarketDataService::symbolMeta($this->market->symbol()),
            'symbols'         => Settings::get('market.symbols', []),
            'data_mode'       => $this->market->mode(),
            'provider_code'   => $this->market->provider()->code(),
            'provider_label'  => $this->market->provider()->label(),
            'quote'           => $quote,
            'market_status'   => MarketDataService::marketStatus($now, $this->market->symbol()),
            'sessions'        => $sessions->schedule($now),
            'active_session'  => $sessions->label($now),
            'freshness'       => $freshness,
            'analysis'        => $analysisPayload,
            'analysis_id'     => $latestRow ? (int)$latestRow['id'] : null,
            'chart_tf'        => $chartTf,
            'chart_candles'   => $displayCandles,
            'chart_smc'       => $this->publicSmc($chartSmc),
            'trend_analysis'  => $analysisPayload['trend_analysis'] ?? $chartTrend,
            'standard_report' => $analysisPayload['standard_report'] ?? ($analysisPayload ? AiExplainer::standardReport($analysisPayload) : null),
            'session_ranges'  => $sessions->ranges($closedChart, $chartTf, $now),
            'recent_signals'  => $recentSignals,
            'performance'     => $stats,
            'calendar'        => [
                'connected' => $calCtx['connected'],
                'events'    => array_slice($calCtx['events'], 0, 12),
                'last_sync' => $calCtx['last_sync'],
            ],
            'notifications'   => $unreadNotifs,
            'emergency_stop'  => (bool)Settings::get('risk.emergency_stop', false),
            'server_time_utc' => $now,
        ];
    }

    /** Strip internal arrays before returning SMC overlay data to clients. */
    public function publicSmc(array $smc): array
    {
        unset($smc['_disp_index']);
        return $smc;
    }

    /**
     * Persist market_analysis, child SMC structures/zones, and trading_signals.
     * @return array{analysis_id:int, signal_id:?int}
     */
    private function persist(array $analysis, array $freshness, int $now): array
    {
        $engineByTf = $analysis['_engine'] ?? [];
        $publicAnalysis = $analysis;
        unset($publicAnalysis['_engine']);

        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $sig = $publicAnalysis['signal'];
        $sourceId = $this->market->sourceId();
        $symbol = $this->market->symbol();
        $mode = $this->market->mode();

        return Database::transaction(function () use ($publicAnalysis, $engineByTf, $freshness, $sig, $sourceId, $symbol, $mode, $now, $nowSql) {
            $analysisId = Database::insert('market_analysis', [
                'symbol'         => $symbol,
                'source_id'      => $sourceId,
                'data_mode'      => $mode,
                'analyzed_at'    => gmdate('Y-m-d H:i:s', (int)$publicAnalysis['analyzed_at']),
                'price'          => $publicAnalysis['price'],
                'direction'      => in_array($publicAnalysis['market_direction'], ['bullish', 'bearish', 'neutral'], true)
                    ? $publicAnalysis['market_direction'] : 'neutral',
                'htf_bias'       => in_array($publicAnalysis['htf_bias'], ['bullish', 'bearish', 'neutral'], true)
                    ? $publicAnalysis['htf_bias'] : 'neutral',
                'session'        => $publicAnalysis['session'] ?? null,
                'signal_label'   => mb_substr((string)($sig['label'] ?? 'WAIT'), 0, 32),
                'quality_score'  => $sig['quality_score'] !== null ? (int)$sig['quality_score'] : null,
                'timeframes'     => json_encode($publicAnalysis['timeframes'] ?? []),
                'result'         => json_encode($publicAnalysis),
                'data_freshness' => json_encode($freshness),
                'created_at'     => $nowSql,
            ]);

            $htfDir = $publicAnalysis['htf_bias'] === 'bullish' ? 1 : ($publicAnalysis['htf_bias'] === 'bearish' ? -1 : 0);
            $price = (float)($publicAnalysis['price'] ?? 0);

            foreach ($engineByTf as $tf => $r) {
                if (empty($r['ok'])) {
                    continue;
                }
                // Structure events (BOS / CHOCH)
                foreach (array_slice($r['events'] ?? [], -12) as $e) {
                    Database::insert('smc_structures', [
                        'analysis_id'      => $analysisId,
                        'timeframe'        => $tf,
                        'type'             => $e['type'],
                        'direction'        => $e['dir'] === 1 ? 'bullish' : 'bearish',
                        'price'            => $e['level'],
                        'candle_time'      => gmdate('Y-m-d H:i:s', (int)$e['t']),
                        'is_mss'           => !empty($e['mss']) ? 1 : 0,
                        'has_displacement' => !empty($e['displacement']) ? 1 : 0,
                        'meta'             => json_encode(['swing_t' => $e['swing_t'], 'leg_price' => $e['leg_price']]),
                    ]);
                }
                // Liquidity zones (BSL / SSL / EQH / EQL)
                foreach (['bsl' => 'BSL', 'ssl' => 'SSL'] as $k => $lbl) {
                    foreach (array_slice($r['liquidity'][$k] ?? [], -10) as $l) {
                        Database::insert('liquidity_zones', [
                            'analysis_id' => $analysisId,
                            'timeframe'   => $tf,
                            'type'        => $lbl,
                            'price'       => $l['price'],
                            'status'      => $l['status'],
                            'formed_at'   => gmdate('Y-m-d H:i:s', (int)$l['t']),
                            'event_at'    => !empty($l['event_t']) ? gmdate('Y-m-d H:i:s', (int)$l['event_t']) : null,
                            'meta'        => json_encode(['label' => $l['label'] ?? null]),
                        ]);
                    }
                }
                foreach (['eqh' => 'EQH', 'eql' => 'EQL'] as $k => $lbl) {
                    foreach (array_slice($r['liquidity'][$k] ?? [], -6) as $eq) {
                        Database::insert('liquidity_zones', [
                            'analysis_id' => $analysisId,
                            'timeframe'   => $tf,
                            'type'        => $lbl,
                            'price'       => $eq['price'],
                            'status'      => $eq['status'],
                            'formed_at'   => gmdate('Y-m-d H:i:s', (int)$eq['t1']),
                            'event_at'    => gmdate('Y-m-d H:i:s', (int)$eq['t2']),
                            'meta'        => json_encode(['p1' => $eq['p1'], 'p2' => $eq['p2']]),
                        ]);
                    }
                }
                // Order Blocks
                foreach (array_slice($r['order_blocks'] ?? [], -10) as $ob) {
                    $mid = ($ob['top'] + $ob['bottom']) / 2.0;
                    Database::insert('order_blocks', [
                        'analysis_id'      => $analysisId,
                        'timeframe'        => $tf,
                        'direction'        => $ob['dir'] === 1 ? 'bullish' : 'bearish',
                        'zone_high'        => $ob['top'],
                        'zone_low'         => $ob['bottom'],
                        'formed_at'        => gmdate('Y-m-d H:i:s', (int)$ob['t']),
                        'break_type'       => $ob['break_type'],
                        'break_at'         => gmdate('Y-m-d H:i:s', (int)$ob['break_t']),
                        'has_displacement' => !empty($ob['displacement']) ? 1 : 0,
                        'status'           => $ob['status'],
                        'distance'         => $price > 0 ? round(abs($price - $mid), 3) : null,
                        'htf_aligned'      => ($htfDir !== 0 && $ob['dir'] === $htfDir) ? 1 : 0,
                        'meta'             => json_encode(['rule' => $ob['rule'] ?? null, 'age' => $ob['age']]),
                    ]);
                }
                // Fair Value Gaps
                foreach (array_slice($r['fvgs'] ?? [], -10) as $fvg) {
                    Database::insert('fair_value_gaps', [
                        'analysis_id'  => $analysisId,
                        'timeframe'    => $tf,
                        'direction'    => $fvg['dir'] === 1 ? 'bullish' : 'bearish',
                        'gap_high'     => $fvg['top'],
                        'gap_low'      => $fvg['bottom'],
                        'formed_at'    => gmdate('Y-m-d H:i:s', (int)$fvg['t']),
                        'age_candles'  => (int)$fvg['age'],
                        'fill_percent' => (float)$fvg['fill_pct'],
                        'status'       => $fvg['status'],
                        'meta'         => json_encode(['displacement' => !empty($fvg['displacement'])]),
                    ]);
                }
            }

            // Signal persistence (deduplicated by symbol + data_mode + entry_tf + direction + data_ref)
            $dataRefSql = gmdate('Y-m-d H:i:s', (int)($sig['data_ref'] ?? $now));
            $setupStatus = in_array($sig['setup_status'] ?? '', ['potential', 'confirmed', 'triggered', 'invalidated', 'expired', 'closed'], true)
                ? $sig['setup_status']
                : 'potential';

            $existingSigId = Database::value(
                "SELECT id FROM trading_signals
                 WHERE symbol = ? AND data_mode = ? AND entry_tf = ? AND direction = ?
                   AND (
                       data_ref = ?
                       OR (direction IN ('BUY','SELL') AND ABS(COALESCE(entry_price, 0) - ?) <= 0.60 AND created_at >= ?)
                   )
                 ORDER BY id DESC LIMIT 1",
                [
                    $symbol,
                    $mode,
                    (string)$sig['entry_tf'],
                    (string)$sig['direction'],
                    $dataRefSql,
                    (float)($sig['entry'] ?? 0),
                    gmdate('Y-m-d H:i:s', $now - 12 * 3600),
                ]
            );

            $sigData = [
                'analysis_id'     => $analysisId,
                'symbol'          => $symbol,
                'data_mode'       => $mode,
                'direction'       => $sig['direction'],
                'signal_label'    => mb_substr((string)$sig['label'], 0, 32),
                'setup_status'    => $setupStatus,
                'setup_type'      => $sig['setup_type'] ?? null,
                'entry_tf'        => (string)$sig['entry_tf'],
                'bias_tfs'        => implode(',', (array)($sig['bias_tfs'] ?? [])),
                'entry_low'       => $sig['entry_low'] ?? null,
                'entry_high'      => $sig['entry_high'] ?? null,
                'entry_price'     => $sig['entry'] ?? null,
                'stop_loss'       => $sig['sl'] ?? null,
                'tp1'             => $sig['tp1'] ?? null,
                'tp2'             => $sig['tp2'] ?? null,
                'tp3'             => $sig['tp3'] ?? null,
                'rr1'             => $sig['rr1'] ?? null,
                'rr2'             => $sig['rr2'] ?? null,
                'rr3'             => $sig['rr3'] ?? null,
                'quality_score'   => (int)($sig['quality_score'] ?? 0),
                'score_breakdown' => json_encode($sig['score'] ?? []),
                'confluences'     => json_encode($sig['confluences'] ?? []),
                'reasons'         => json_encode($sig['reasons'] ?? []),
                'invalidations'   => json_encode($sig['invalidations'] ?? []),
                'warnings'        => json_encode($sig['warnings'] ?? []),
                'ai_explanation'  => $sig['ai_explanation'] ?? null,
                'ai_provider'     => $sig['ai_provider'] ?? 'rule-engine',
                'price_at_signal' => (float)$publicAnalysis['price'],
                'data_source'     => mb_substr((string)$publicAnalysis['source'], 0, 64),
                'data_ref'        => $dataRefSql,
                'session'         => $publicAnalysis['session'] ?? null,
                'expires_at'      => !empty($sig['expires_at']) ? gmdate('Y-m-d H:i:s', (int)$sig['expires_at']) : null,
                'updated_at'      => $nowSql,
            ];

            if ($existingSigId) {
                $signalId = (int)$existingSigId;
                // Preserve setup_status and original data_ref if already tracked
                $curStatus = (string)Database::value('SELECT setup_status FROM trading_signals WHERE id = ?', [$signalId]);
                unset($sigData['data_ref']);
                if (in_array($curStatus, ['triggered', 'closed', 'invalidated', 'expired'], true)) {
                    unset($sigData['setup_status']);
                }
                Database::update('trading_signals', $sigData, 'id = ?', [$signalId]);
                if (in_array($sig['direction'], ['BUY', 'SELL'], true) && in_array($setupStatus, ['confirmed', 'triggered'], true) && (int)($sig['quality_score'] ?? 0) >= 85) {
                    Notifier::notifySignal($sig, $signalId);
                } elseif (($sig['readiness']['stage'] ?? '') === 'nearing_confirmation') {
                    Notifier::notifyPrepareEntry($sig, $signalId);
                }
            } else {
                $sigData['created_at'] = $nowSql;
                $signalId = Database::insert('trading_signals', $sigData);
                if (in_array($sig['direction'], ['BUY', 'SELL'], true)) {
                    Database::insert('signal_outcomes', [
                        'signal_id'       => $signalId,
                        'outcome'         => $setupStatus === 'triggered' ? 'open' : 'pending',
                        'entry_filled_at' => $setupStatus === 'triggered' ? $nowSql : null,
                        'is_simulated'    => 1,
                        'ambiguous'       => 0,
                        'updated_at'      => $nowSql,
                    ]);
                    if (in_array($setupStatus, ['confirmed', 'triggered'], true) && (int)($sig['quality_score'] ?? 0) >= 85) {
                        Notifier::notifySignal($sig, $signalId);
                    } elseif (($sig['readiness']['stage'] ?? '') === 'nearing_confirmation') {
                        Notifier::notifyPrepareEntry($sig, $signalId);
                    }
                } elseif (($sig['readiness']['stage'] ?? '') === 'nearing_confirmation') {
                    Notifier::notifyPrepareEntry($sig, $signalId);
                }
            }

            return ['analysis_id' => $analysisId, 'signal_id' => $signalId];
        });
    }
}
