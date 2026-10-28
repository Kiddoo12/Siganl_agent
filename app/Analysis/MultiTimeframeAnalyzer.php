<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Market\Timeframe;
use App\SMC\SmcEngine;

/**
 * Multi-timeframe confluence analysis + rule-based setup generation.
 * Pure function of (candles, context) -> result, so it is shared by the live
 * pipeline and the backtester (which feeds it only data available at each step).
 */
final class MultiTimeframeAnalyzer
{
    private SmcEngine $engine;
    private array $mcfg;   // market
    private array $scfg;   // signal
    private float $eqBand;
    private SessionService $sessions;
    private array $memo = [];

    public function __construct(array $smcCfg, array $marketCfg, array $signalCfg, SessionService $sessions)
    {
        $this->engine = new SmcEngine($smcCfg);
        $this->mcfg = $marketCfg;
        $this->scfg = $signalCfg;
        $this->eqBand = (float)($smcCfg['eq_band'] ?? 0.05);
        $this->sessions = $sessions;
    }

    public static function fromSettings(?string $symbol = null): self
    {
        $mcfg = (array)\App\Core\Settings::get('market');
        $scfg = (array)\App\Core\Settings::get('signal');
        if ($symbol !== null) {
            $sym = \App\Market\MarketDataService::normalizeSymbol($symbol);
            $mcfg['symbol'] = $sym;
            $meta = \App\Market\MarketDataService::symbolMeta($sym);
            if ($sym !== 'XAUUSD') {
                $scfg['assumed_spread'] = (float)($meta['assumed_spread'] ?? $scfg['assumed_spread']);
                $scfg['max_spread'] = (float)($meta['max_spread'] ?? $scfg['max_spread']);
            }
        }
        return new self(
            (array)\App\Core\Settings::get('smc'),
            $mcfg,
            $scfg,
            new SessionService()
        );
    }

    public function engine(): SmcEngine { return $this->engine; }

    /** Engine result for one TF (memoised by last candle time + count; used heavily by the backtester). */
    public function analyzeTf(string $tf, array $closed): array
    {
        $key = $tf . '|' . count($closed) . '|' . ($closed ? end($closed)['t'] : 0) . '|' . ($closed ? $closed[0]['t'] : 0);
        if (!isset($this->memo[$key])) {
            if (count($this->memo) > 64) $this->memo = [];
            $this->memo[$key] = $this->engine->analyze($closed);
        }
        return $this->memo[$key];
    }

    /**
     * @param array $candlesByTf tf => candles (may include an in-progress last candle; it is removed)
     * @param array $ctx now, price, spread, spread_source, events (array|null), news_connected, data_mode, source
     */
    public function analyze(array $candlesByTf, array $ctx): array
    {
        $now = (int)$ctx['now'];
        $entryTf = (string)$this->mcfg['entry_tf'];
        $poiTf = (string)$this->mcfg['poi_tf'];
        $biasTfs = (array)$this->mcfg['bias_tfs'];
        $contextTfs = (array)$this->mcfg['context_tfs'];
        $refineTfs = (array)($this->mcfg['refine_tfs'] ?? []);

        $closed = [];
        $res = [];
        foreach ($candlesByTf as $tf => $candles) {
            $closed[$tf] = Timeframe::closedOnly($tf, $candles, $now);
            $res[$tf] = $this->analyzeTf($tf, $closed[$tf]);
        }
        if (empty($res[$entryTf]['ok'])) {
            return $this->unavailable("Entry timeframe $entryTf: " . ($res[$entryTf]['error'] ?? 'no data'), $ctx, $res);
        }
        $E = $res[$entryTf];
        $price = isset($ctx['price']) && $ctx['price'] > 0 ? (float)$ctx['price'] : $E['close'];

        // ---- Bias ----
        $biasVotes = [];
        foreach ($biasTfs as $tf) {
            $biasVotes[$tf] = !empty($res[$tf]['ok']) ? $res[$tf]['trend'] : 0;
        }
        $nonZero = array_filter($biasVotes);
        $uniq = array_unique(array_values($nonZero));
        $htfDir = (count($uniq) === 1) ? (int)reset($uniq) : 0;
        $htfFull = $htfDir !== 0 && count($nonZero) === count($biasVotes);
        $context = [];
        foreach ($contextTfs as $tf) {
            $context[$tf] = !empty($res[$tf]['ok']) ? $res[$tf]['trend_label'] : 'unavailable';
        }
        $P = !empty($res[$poiTf]['ok']) ? $res[$poiTf] : null;
        $marketDir = $P ? $P['trend'] : $E['trend'];

        // ---- Key levels ----
        $levels = $this->keyLevels($closed, $now);
        $sessionRanges = $this->sessions->ranges($closed[$entryTf], $entryTf, $now);

        // ---- Professional Trend & Market Regime Detection (Sections 2 & 3) ----
        $trendEngine = new TrendAnalysisEngine();
        $trendAnalysis = $trendEngine->analyze($closed[$entryTf] ?? []);
        $regimeDetector = new RegimeDetector();
        $regimeAnalysis = $regimeDetector->detect($closed[$entryTf] ?? [], $trendAnalysis, !empty($ctx['events']) ? ['blocking' => null] : null);
        $ctx['regime_analysis'] = $regimeAnalysis;

        // ---- ICT Engine Analysis ----
        $ictEngine = new \App\SMC\IctEngine();
        $ictRes = $ictEngine->analyze($closed[$entryTf], $E, $now);

        // ---- Setup candidates ----
        $cands = [];
        foreach ([1, -1] as $dir) {
            $cands[$dir] = $this->evaluate($dir, $E, $closed[$entryTf], $P, $res, $htfDir, $htfFull, $levels, $sessionRanges, $price, $ctx, $ictRes);
        }
        $qual = array_filter($cands, static fn($c) => $c['qualifies']);
        $signal = $this->finalSignal($cands, $qual, $ctx, $entryTf, $biasTfs, $price, $now, $E, $ictRes);

        // Check for conflict between lower-timeframe setups and higher-timeframe / trend bias (Section 4)
        if (!empty($signal['direction']) && in_array($signal['direction'], ['BUY', 'SELL'], true)) {
            $isBuySig = $signal['direction'] === 'BUY';
            $biasConflicted = ($isBuySig && $htfDir === -1) || (!$isBuySig && $htfDir === 1);
            $trendConflicted = ($isBuySig && str_contains($trendAnalysis['classification'], 'Bearish'))
                            || (!$isBuySig && str_contains($trendAnalysis['classification'], 'Bullish'));

            if ($biasConflicted || $trendConflicted) {
                $reason = $biasConflicted
                    ? sprintf('Lower timeframe %s setup conflicts with higher-timeframe %s bias. Confidence reduced.', $signal['direction'], self::dirLabel($htfDir))
                    : sprintf('Lower timeframe %s setup counter to %s (%s). Confidence reduced.', $signal['direction'], $trendAnalysis['classification'], $entryTf);
                $signal['warnings'][] = $reason;
                $signal['quality_score'] = max(35, (int)($signal['quality_score'] ?? 60) - 15);
                if (isset($signal['score']['total'])) {
                    $signal['score']['total'] = $signal['quality_score'];
                }
            }
        }

        // ---- Phase 3 & 6: Machine Learning Feature Extraction & Expected Value Filter ----
        if (!empty($signal['direction']) && in_array($signal['direction'], ['BUY', 'SELL'], true)) {
            try {
                $extractor = new \App\ML\FeatureExtractor($this->sessions);
                $featExtracted = $extractor->extract(
                    $closed,
                    [
                        'dir'    => $signal['direction'] === 'BUY' ? 1 : -1,
                        'entry'  => (float)($signal['entry'] ?? $price),
                        'sl'     => (float)($signal['sl'] ?? ($signal['direction'] === 'BUY' ? $price - 1.5 * $E['atr'] : $price + 1.5 * $E['atr'])),
                        'tp1'    => isset($signal['tp1']) ? (float)$signal['tp1'] : null,
                        'tp2'    => isset($signal['tp2']) ? (float)$signal['tp2'] : null,
                        'spread' => (float)($ctx['spread'] ?? $this->scfg['assumed_spread']),
                    ],
                    $now,
                    $entryTf,
                    $poiTf
                );

                $modelMgr = new \App\ML\ModelManager();
                $activeModelData = $modelMgr->getActiveModel();
                $activeModel = $activeModelData['model_instance'] ?? null;

                $evFilter = new \App\ML\ExpectedValueFilter($activeModel);
                $mlEval = $evFilter->evaluate($signal, $featExtracted['vector'], $regimeAnalysis);

                $signal['ml'] = [
                    'calibrated_prob'  => $mlEval['calibrated_prob'],
                    'expected_net_r'   => $mlEval['expected_net_r'],
                    'decision'         => $mlEval['decision'],
                    'authorized'       => $mlEval['authorized'],
                    'target_r'         => $mlEval['target_r'],
                    'friction_r'       => $mlEval['friction_r'],
                    'model_version'    => $activeModelData['version_tag'] ?? 'baseline-prior',
                    'reasons'          => $mlEval['reasons'],
                    'features'         => $featExtracted['map'],
                ];

                if (!$mlEval['authorized']) {
                    $signal['direction'] = 'WAIT';
                    $signal['label'] = 'WAIT (ML FILTER REJECTED)';
                    $signal['setup_status'] = 'potential';
                    foreach ($mlEval['reasons'] as $rReason) {
                        $signal['warnings'][] = 'ML Filter: ' . $rReason;
                        $signal['reasons'][] = 'ML Filter: ' . $rReason;
                    }
                }
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('ML EV Filter evaluation exception: ' . $e->getMessage());
            }
        }

        // ---- Lower timeframe confirmation ----
        $ltf = [];
        foreach (array_merge([$entryTf], $refineTfs) as $tf) {
            if (!empty($res[$tf]['ok'])) {
                $le = $res[$tf]['last_event'];
                $ltf[$tf] = ['trend' => $res[$tf]['trend_label'], 'last_event' => $le ? ($le['dir'] === 1 ? 'Bullish ' : 'Bearish ') . $le['type'] : null];
            }
        }

        // ---- Nearest liquidity targets (from entry + POI TF) ----
        $nearest = $this->nearestLiquidity($E, $P, $price);

        $tfSummary = [];
        foreach ($res as $tf => $r) {
            $tfSummary[$tf] = !empty($r['ok']) ? [
                'trend' => $r['trend_label'],
                'last_event' => $r['last_event'] ? ['type' => $r['last_event']['type'], 'dir' => $r['last_event']['dir'], 'level' => $r['last_event']['level'], 't' => $r['last_event']['t'], 'mss' => $r['last_event']['mss']] : null,
                'pd' => $r['premium_discount'], 'atr' => round($r['atr'], 3), 'atr_ratio' => round($r['atr_ratio'], 2),
                'last_time' => $r['last_time'], 'candles' => $r['count'],
                'fresh_obs' => count(array_filter($r['order_blocks'], static fn($o) => $o['status'] === 'fresh')),
                'open_fvgs' => count(array_filter($r['fvgs'], static fn($f) => in_array($f['status'], ['fresh', 'partial'], true))),
            ] : ['error' => $r['error'] ?? 'unavailable'];
        }

        return [
            'ok' => true,
            'analyzed_at' => $now,
            'data_mode' => $ctx['data_mode'] ?? 'unknown',
            'source' => $ctx['source'] ?? 'unknown',
            'price' => $price,
            'entry_tf' => $entryTf, 'poi_tf' => $poiTf, 'bias_tfs' => $biasTfs,
            'htf_bias' => self::dirLabel($htfDir),
            'htf_votes' => array_map([self::class, 'dirLabel'], $biasVotes),
            'context' => $context,
            'market_direction' => self::dirLabel($marketDir),
            'session' => $this->sessions->label($now),
            'session_ranges' => $sessionRanges,
            'key_levels' => $levels,
            'nearest_liquidity' => $nearest,
            'ltf_confirmation' => $ltf,
            'timeframes' => $tfSummary,
            'candidates' => array_values(array_map(fn($c) => $this->publicCandidate($c), $cands)),
            'signal' => $signal,
            'ict' => $ictRes,
            'trend_analysis' => $trendAnalysis,
            'regime' => $regimeAnalysis['regime'],
            'regime_analysis' => $regimeAnalysis,
            '_engine' => $res,
        ];
    }

    // ------------------------------------------------------------------

    private function evaluate(int $dir, array $E, array $cE, ?array $P, array $res, int $htfDir, bool $htfFull,
                              array $levels, array $sessionRanges, float $price, array $ctx, array $ict = []): array
    {
        $n = count($cE);
        $W = (int)$this->scfg['setup_lookback'];
        $atr = $E['atr'];
        $spread = (float)($ctx['spread'] ?? $this->scfg['assumed_spread']);
        $isBuy = $dir === 1;
        $name = $isBuy ? 'BUY' : 'SELL';
        $c = ['dir' => $dir, 'name' => $name, 'conds' => [], 'blockers' => [], 'warnings' => [], 'levels' => null, 'qualifies' => false];
        $cond = function (string $key, bool $met, string $detail) use (&$c) {
            $c['conds'][$key] = ['met' => $met, 'detail' => $detail];
        };

        // 1. HTF bias
        $cond('htf', $htfDir === $dir, $htfDir === $dir
            ? 'Higher-timeframe structure (' . implode('/', (array)$this->mcfg['bias_tfs']) . ') is ' . self::dirLabel($dir) . ($htfFull ? ' on all bias TFs' : ' (partial agreement)')
            : 'Higher-timeframe bias is ' . self::dirLabel($htfDir) . ', not ' . self::dirLabel($dir));
        $c['htf_full'] = $htfFull && $htfDir === $dir;

        // 4. Structure confirmation on entry TF
        $evt = null;
        foreach (array_reverse($E['events']) as $e) {
            if ($e['i'] < $n - 1 - $W) break;
            if ($e['dir'] === $dir) { $evt = $e; break; }
        }
        $structOk = $evt !== null && $E['trend'] === $dir;
        $cond('structure', $structOk, $structOk
            ? sprintf('%s %s on %s at %.2f (close-confirmed)%s', self::dirLabel($dir, true), $evt['type'], $this->mcfg['entry_tf'], $evt['level'], $evt['mss'] ? ' - market structure shift' : '')
            : 'No ' . self::dirLabel($dir) . ' BOS/CHOCH on ' . $this->mcfg['entry_tf'] . " in last $W candles (or reversed since)");
        $c['evt'] = $evt;

        // 3. Liquidity sweep before the break
        $anchorI = $evt ? $evt['i'] : $n - 1;
        $sweep = null;
        foreach (array_reverse($E['liquidity']['sweeps']) as $s) {
            if ($s['dir'] === $dir && $s['i'] <= $anchorI && $s['i'] >= $anchorI - $W) { $sweep = $s; break; }
        }
        // Key level sweeps (PDH/PDL, PWH/PWL, Asian range) by wick + close back inside
        $keySweep = null;
        $keyCands = $isBuy
            ? ['PDL' => $levels['pdl'] ?? null, 'PWL' => $levels['pwl'] ?? null, 'Asian low' => $sessionRanges['asia']['low'] ?? null]
            : ['PDH' => $levels['pdh'] ?? null, 'PWH' => $levels['pwh'] ?? null, 'Asian high' => $sessionRanges['asia']['high'] ?? null];
        for ($k = $anchorI; $k >= max(0, $anchorI - $W) && !$keySweep; $k--) {
            foreach ($keyCands as $lbl => $lvl) {
                if ($lvl === null) continue;
                if ($isBuy ? ($cE[$k]['l'] < $lvl && $cE[$k]['c'] > $lvl) : ($cE[$k]['h'] > $lvl && $cE[$k]['c'] < $lvl)) {
                    $keySweep = ['label' => $lbl, 'level' => $lvl, 'i' => $k, 't' => $cE[$k]['t'], 'extreme' => $isBuy ? $cE[$k]['l'] : $cE[$k]['h']];
                    break;
                }
            }
        }
        $sweepOk = $sweep !== null || $keySweep !== null;
        $detail = 'No ' . ($isBuy ? 'sell-side' : 'buy-side') . ' liquidity sweep before the break';
        if ($sweep) $detail = sprintf('%s swept at %.2f (wick to %.2f, rejection %d%% of range)', $sweep['type'], $sweep['level'], $sweep['extreme'], $sweep['rejection'] * 100);
        if ($keySweep) $detail = ($sweep ? $detail . '; ' : '') . sprintf('%s %.2f swept and closed back inside', $keySweep['label'], $keySweep['level']);
        $cond('sweep', $sweepOk, $detail);
        $c['sweep'] = $sweep; $c['key_sweep'] = $keySweep;

        // 5. Displacement
        $dispOk = $evt !== null && $evt['displacement'];
        $cond('displacement', $dispOk, $dispOk ? 'Impulse leg contains a displacement candle (body & range above ATR thresholds)' : 'No displacement in the breaking leg');

        // 2. Location: leg extreme in discount (buy) / premium (sell) of POI-TF dealing range, or tap of HTF POI
        $legPrice = $evt ? $evt['leg_price'] : null;
        $pd = $P['premium_discount'] ?? $E['premium_discount'];
        $locZone = $legPrice !== null ? SmcEngine::zoneOf($pd, $legPrice, $this->eqBand) : null;
        $htfPoi = null;
        if ($P && $legPrice !== null) {
            foreach (array_merge($P['order_blocks'], $P['fvgs']) as $z) {
                if ($z['dir'] === $dir && $z['status'] !== 'invalidated' && $legPrice <= $z['top'] && $legPrice >= $z['bottom']) {
                    $htfPoi = $z;
                }
            }
        }
        $want = $isBuy ? 'discount' : 'premium';
        $locOk = $locZone === $want || $htfPoi !== null;
        $c['location_partial'] = $locZone === 'equilibrium';
        $cond('location', $locOk, $legPrice === null ? 'No impulse leg to locate'
            : sprintf('Leg %s %.2f is in %s of %s range%s', $isBuy ? 'low' : 'high', $legPrice, $locZone ?? 'n/a', $this->mcfg['poi_tf'],
                $htfPoi ? ' and taps a ' . $this->mcfg['poi_tf'] . ' POI' : ''));

        // 6. Entry zone (OB / FVG on entry TF tied to this leg, or recent unmitigated OB/FVG / tapped HTF POI)
        $ob = null; $fvg = null;
        if ($evt) {
            foreach ($E['order_blocks'] as $o) {
                if ($o['dir'] === $dir && in_array($o['status'], ['fresh', 'mitigated'], true) && ($o['break_i'] === $evt['i'] || $o['i'] >= max(0, $evt['leg_start'] - 6))) {
                    $ob = $o;
                }
            }
            foreach ($E['fvgs'] as $f) {
                if ($f['dir'] === $dir && $f['i'] >= max(0, $evt['leg_start'] - 4) && in_array($f['status'], ['fresh', 'partial'], true)) {
                    $fvg = $f;
                }
            }
        }
        $overlap = $ob && $fvg && $fvg['bottom'] <= $ob['top'] && $fvg['top'] >= $ob['bottom'];
        $fvgInLeg = $fvg && $evt && $fvg['i'] >= $evt['leg_start'];
        $obInLeg = $ob && $evt && $ob['i'] >= $evt['leg_start'];

        // ICT model elements
        $unicorn = null;
        foreach ($ict['unicorns'] ?? [] as $u) {
            if ($u['dir'] === $dir && ($u['status'] ?? 'active') === 'active') {
                $unicorn = $u;
                break;
            }
        }
        $breaker = null;
        foreach ($ict['breaker_blocks'] ?? [] as $b) {
            if ($b['dir'] === $dir && in_array($b['status'] ?? '', ['fresh', 'retested'], true)) {
                $breaker = $b;
                break;
            }
        }
        $ifvg = null;
        foreach ($ict['inverse_fvgs'] ?? [] as $iv) {
            if ($iv['dir'] === $dir && in_array($iv['status'] ?? '', ['fresh', 'retested'], true)) {
                $ifvg = $iv;
                break;
            }
        }

        $zone = null;
        if ($unicorn) {
            $uTime = $unicorn['t'] ?? ($unicorn['fvg']['t'] ?? ($unicorn['breaker']['formed_t'] ?? ($evt['t'] ?? 0)));
            $zone = ['top' => $unicorn['top'], 'bottom' => $unicorn['bottom'], 'kind' => 'ICT Unicorn Zone (Breaker + FVG)', 't' => $uTime];
        } elseif ($overlap) {
            $zone = ['top' => min($ob['top'], $fvg['top']), 'bottom' => max($ob['bottom'], $fvg['bottom']), 'kind' => 'OB+FVG', 't' => max($ob['t'], $fvg['t'])];
        } elseif ($fvgInLeg) {
            // Fresh displacement FVG formed during the breakout move (prioritize over distant pre-break OB)
            $zone = ['top' => $fvg['top'], 'bottom' => $fvg['bottom'], 'kind' => 'Displacement FVG', 't' => $fvg['t']];
        } elseif ($obInLeg) {
            $zone = ['top' => $ob['top'], 'bottom' => $ob['bottom'], 'kind' => 'Order Block', 't' => $ob['t']];
        } elseif ($breaker) {
            $zone = ['top' => $breaker['top'], 'bottom' => $breaker['bottom'], 'kind' => 'ICT Breaker Block', 't' => $breaker['formed_t'] ?? ($evt['t'] ?? 0)];
        } elseif ($ifvg) {
            $zone = ['top' => $ifvg['top'], 'bottom' => $ifvg['bottom'], 'kind' => 'ICT Inverse FVG', 't' => $ifvg['formed_t'] ?? ($evt['t'] ?? 0)];
        } elseif ($fvg) {
            $zone = ['top' => $fvg['top'], 'bottom' => $fvg['bottom'], 'kind' => 'FVG', 't' => $fvg['t']];
        } elseif ($ob) {
            $zone = ['top' => $ob['top'], 'bottom' => $ob['bottom'], 'kind' => 'Order Block', 't' => $ob['t']];
        } elseif ($htfPoi && $evt) {
            $zone = ['top' => $htfPoi['top'], 'bottom' => $htfPoi['bottom'], 'kind' => $this->mcfg['poi_tf'] . ' POI Zone', 't' => $htfPoi['t'] ?? $evt['t']];
        } elseif ($evt && ($n - 1 - $evt['i']) <= 8) {
            // Breakout retest level
            $bBuf = 0.15 * $atr;
            $zone = ['top' => round($evt['level'] + $bBuf, 2), 'bottom' => round($evt['level'] - $bBuf, 2), 'kind' => 'Breakout Retest', 't' => $evt['t']];
        }

        // Detect highest conviction ICT strategy tag
        $detectedStrat = null;
        if ($unicorn) {
            $detectedStrat = 'ICT Unicorn Model';
        } elseif (!empty($ict['silver_bullet']['active']) && ($ict['silver_bullet']['dir'] ?? 0) === $dir) {
            $detectedStrat = 'ICT Silver Bullet (' . ($ict['silver_bullet']['session'] ?? 'Session') . ')';
        } elseif (!empty($ict['model_2022']['active']) && ($ict['model_2022']['dir'] ?? 0) === $dir) {
            $detectedStrat = 'ICT 2022 Mentorship Model';
        } elseif (!empty($ict['turtle_soup']['active']) && ($ict['turtle_soup']['dir'] ?? 0) === $dir) {
            $detectedStrat = 'ICT Turtle Soup';
        } elseif (!empty($ict['judas_swing']['detected']) && ($ict['judas_swing']['dir'] ?? 0) === $dir) {
            $detectedStrat = 'ICT Judas Swing';
        } elseif (!empty($ict['ote']['in_zone']) && ($ict['ote']['dir'] ?? 0) === $dir) {
            $detectedStrat = 'ICT Optimal Trade Entry (OTE 62%-79%)';
        } elseif ($breaker) {
            $detectedStrat = 'ICT Breaker Block Setup';
        } elseif ($ifvg) {
            $detectedStrat = 'ICT Inverse FVG Model';
        }

        $c['detected_strat'] = $detectedStrat;
        $c['unicorn'] = $unicorn;
        $c['breaker'] = $breaker;
        $c['ifvg'] = $ifvg;
        $c['ict_details'] = [
            'strategy' => $detectedStrat,
            'killzone' => $ict['killzone']['active_name'] ?? null,
            'in_killzone' => $ict['killzone']['in_killzone'] ?? false,
            'macro' => $ict['macro']['name'] ?? null,
            'ote' => $ict['ote'] ?? null,
        ];
        $cond('zone', $zone !== null, $zone
            ? sprintf('%s %s %.2f - %.2f (%s)', self::dirLabel($dir, true), $zone['kind'], $zone['bottom'], $zone['top'], $ob ? $ob['status'] : ($fvg ? $fvg['status'] : 'active'))
            : 'No valid unmitigated ' . self::dirLabel($dir) . ' OB/FVG formed by the leg');
        $c['ob'] = $ob; $c['fvg'] = $fvg; $c['zone'] = $zone; $c['overlap'] = $overlap;

        // 7. Stop loss + entry
        $levelsOut = null;
        $slOk = false; $rrOk = false;
        if ($zone && $evt) {
            $extreme = $legPrice;
            if ($sweep) $extreme = $isBuy ? min($extreme, $sweep['extreme']) : max($extreme, $sweep['extreme']);
            if ($keySweep && $keySweep['i'] >= $evt['leg_start'] - $W) $extreme = $isBuy ? min($extreme, $keySweep['extreme']) : max($extreme, $keySweep['extreme']);
            $buf = $this->scfg['sl_buffer_atr'] * $atr + $spread;
            $sl = $isBuy ? $extreme - $buf : $extreme + $buf;
            $entry = $this->scfg['entry_mode'] === 'proximal'
                ? ($isBuy ? $zone['top'] : $zone['bottom'])
                : ($zone['top'] + $zone['bottom']) / 2;
            $risk = $isBuy ? $entry - $sl : $sl - $entry;
            // Technical safeguard: if leg extreme puts SL beyond max_sl_atr, anchor SL beyond entry zone
            if ($risk > $this->scfg['max_sl_atr'] * $atr) {
                $sl = $isBuy ? ($zone['bottom'] - max($buf, 0.35 * $atr)) : ($zone['top'] + max($buf, 0.35 * $atr));
                $risk = $isBuy ? $entry - $sl : $sl - $entry;
            }
            $slOk = $risk > 0 && ($isBuy ? $sl < $zone['bottom'] : $sl > $zone['top'])
                && $risk >= $this->scfg['min_sl_atr'] * $atr && $risk <= $this->scfg['max_sl_atr'] * $atr;
            $cond('sl', $slOk, $risk > 0
                ? sprintf('SL %.2f beyond %s %.2f (+%.2f buffer); stop distance %.2f = %.2f ATR', $sl, $risk > $this->scfg['max_sl_atr'] * $atr ? 'zone' : 'leg extreme', $sl, $buf, $risk, $risk / $atr)
                : 'Cannot place SL beyond the zone');

            // 8. Targets from opposing liquidity
            $targets = $risk > 0 ? $this->targets($dir, $entry, $risk, $atr, $E, $P, $levels, $sessionRanges, $evt, $cE) : [];
            $tp = $this->pickTargets($targets);
            $bestRr = $tp ? max(array_column($tp, 'rr')) : 0;
            $rrOk = $slOk && $bestRr >= $this->scfg['min_rr'];
            $cond('rr', $rrOk, $tp
                ? sprintf('Best liquidity target offers %.2fR (minimum %.1fR)', $bestRr, $this->scfg['min_rr'])
                : 'No opposing liquidity target with at least ' . $this->scfg['tp1_min_rr'] . 'R');

            // Setup lifecycle since the break (checks closed candles + current live price)
            $state = 'confirmed';
            $touched = ($ob && $ob['status'] === 'mitigated') || ($fvg && $fvg['status'] === 'partial');
            $tp1Price = isset($tp[0]['price']) ? (float)$tp[0]['price'] : null;
            $finalTpPrice = $tp ? (float)$tp[count($tp) - 1]['price'] : null;
            for ($k = $evt['i'] + 1; $k < $n; $k++) {
                if ($isBuy ? $cE[$k]['l'] <= $sl : $cE[$k]['h'] >= $sl) {
                    $state = 'invalidated';
                    break;
                }
                if ($isBuy ? $cE[$k]['l'] <= $zone['top'] : $cE[$k]['h'] >= $zone['bottom']) {
                    $touched = true;
                }
                // Target 1 reached on closed candle:
                // If touched: reached TP1 after fill -> target reached / trade completed.
                // If not touched: reached TP1 before fill -> missed setup (target reached before mitigation).
                if ($tp1Price !== null && ($isBuy ? $cE[$k]['h'] >= $tp1Price : $cE[$k]['l'] <= $tp1Price)) {
                    $state = 'expired';
                    break;
                }
            }
            if ($state !== 'invalidated' && ($isBuy ? $price <= $sl : $price >= $sl)) {
                $state = 'invalidated';
            }
            if ($state === 'confirmed' && $touched) {
                $state = 'triggered';
            }
            if ($state === 'confirmed' && ($n - 1 - $evt['i']) > (int)$this->scfg['expiry_candles']) {
                $state = 'expired';
            }
            // Live price reached or passed TP1 or final TP -> expired!
            if ($tp1Price !== null && ($isBuy ? $price >= $tp1Price : $price <= $tp1Price)) {
                $state = 'expired';
            }
            if ($finalTpPrice !== null && ($isBuy ? $price >= $finalTpPrice : $price <= $finalTpPrice)) {
                $state = 'expired';
            }
            // Runaway Move / Proximity Guard:
            // If setup was NOT filled, and price has already moved > 35% of the distance from entry towards TP1,
            // or > 1.2 ATR away from entry zone in target direction:
            if ($state === 'confirmed' && $tp1Price !== null) {
                $targetDist = abs($tp1Price - $entry);
                $runDist = $isBuy ? ($price - $zone['top']) : ($zone['bottom'] - $price);
                if ($targetDist > 0 && ($runDist / $targetDist) >= 0.35) {
                    $state = 'expired';
                } elseif ($runDist > 1.2 * $atr) {
                    $state = 'expired';
                }
            }

            $levelsOut = [
                'entry_low' => $zone['bottom'], 'entry_high' => $zone['top'], 'entry' => $entry, 'sl' => $sl,
                'risk' => $risk, 'risk_atr' => $risk / $atr, 'targets' => $tp, 'all_targets' => array_slice($targets, 0, 8),
                'state' => $state, 'formed_t' => $evt['t'], 'evt_i' => $evt['i'],
            ];
        } else {
            $cond('sl', false, 'No entry zone - SL cannot be defined');
            $cond('rr', false, 'No entry zone - R:R cannot be computed');
            if ($evt && $legPrice !== null) {
                $extreme = $legPrice;
                if ($sweep) $extreme = $isBuy ? min($extreme, $sweep['extreme']) : max($extreme, $sweep['extreme']);
                $buf = $this->scfg['sl_buffer_atr'] * $atr + $spread;
                $projSl = $isBuy ? $extreme - $buf : $extreme + $buf;
                $projEntry = $price;
                $projRisk = max($atr * 0.8, abs($projEntry - $projSl));
                $projSl = $isBuy ? $projEntry - $projRisk : $projEntry + $projRisk;
                $projTargets = $this->targets($dir, $projEntry, $projRisk, $atr, $E, $P, $levels, $sessionRanges, $evt, $cE);
                $projTp = $this->pickTargets($projTargets);
                $levelsOut = [
                    'entry_low' => round($projEntry - 0.25 * $atr, 2),
                    'entry_high' => round($projEntry + 0.25 * $atr, 2),
                    'entry' => round($projEntry, 2),
                    'sl' => round($projSl, 2),
                    'risk' => $projRisk,
                    'risk_atr' => $projRisk / max(0.01, $atr),
                    'targets' => $projTp,
                    'all_targets' => array_slice($projTargets, 0, 8),
                    'state' => 'confirmed',
                    'formed_t' => $evt['t'],
                    'evt_i' => $evt['i'],
                ];
            }
        }
        $c['levels'] = $levelsOut;

        // 9. Blockers: news, spread, volatility
        $this->riskFilters($c, $ctx, $E);

        $required = (array)$this->scfg['required'];
        $missing = [];
        foreach ($required as $r) {
            if (empty($c['conds'][$r]['met'])) $missing[] = $r;
        }
        $c['missing'] = $missing;
        $stateOk = $levelsOut && in_array($levelsOut['state'], ['confirmed', 'triggered'], true);

        // Scoring
        $c['score'] = $this->score($c, $ctx, $stateOk);
        $c['qualifies'] = !$missing && !$c['blockers'] && $stateOk && $c['score']['total'] >= $this->scfg['min_score'];
        return $c;
    }

    private function targets(int $dir, float $entry, float $risk, float $atr, array $E, ?array $P, array $levels, array $sr, array $evt, array $cE): array
    {
        $isBuy = $dir === 1;
        $raw = [];
        $add = function ($price, string $label) use (&$raw, $isBuy, $entry) {
            if ($price === null) return;
            $price = (float)$price;
            if ($isBuy ? $price > $entry : $price < $entry) $raw[] = ['price' => $price, 'label' => $label];
        };
        $side = $isBuy ? 'bsl' : 'ssl';
        foreach ($E['liquidity'][$side] as $l) if ($l['status'] === 'active') $add($l['price'], $this->mcfg['entry_tf'] . ' ' . strtoupper($side));
        foreach ($E['liquidity'][$isBuy ? 'eqh' : 'eql'] as $l) if ($l['status'] === 'active') $add($l['price'], $this->mcfg['entry_tf'] . ($isBuy ? ' equal highs' : ' equal lows'));
        if ($P) {
            foreach ($P['liquidity'][$side] as $l) if ($l['status'] === 'active') $add($l['price'], $this->mcfg['poi_tf'] . ' ' . strtoupper($side));
        }
        $add($isBuy ? ($levels['pdh'] ?? null) : ($levels['pdl'] ?? null), $isBuy ? 'Previous day high' : 'Previous day low');
        $add($isBuy ? ($levels['pwh'] ?? null) : ($levels['pwl'] ?? null), $isBuy ? 'Previous week high' : 'Previous week low');
        foreach ($sr as $s) $add($isBuy ? $s['high'] : $s['low'], $s['label'] . ($isBuy ? ' high' : ' low'));
        // Leg extreme created by the impulse (resting liquidity)
        $n = count($cE);
        $ext = null;
        for ($k = $evt['leg_start']; $k < $n; $k++) $ext = $ext === null ? ($isBuy ? $cE[$k]['h'] : $cE[$k]['l']) : ($isBuy ? max($ext, $cE[$k]['h']) : min($ext, $cE[$k]['l']));
        $add($ext, 'Impulse leg ' . ($isBuy ? 'high' : 'low'));

        usort($raw, static fn($a, $b) => $isBuy ? $a['price'] <=> $b['price'] : $b['price'] <=> $a['price']);
        $out = [];
        foreach ($raw as $r) {
            $lastIdx = count($out) - 1;
            if ($lastIdx >= 0 && abs($out[$lastIdx]['price'] - $r['price']) <= 0.15 * $atr) {
                if (strpos($out[$lastIdx]['label'], $r['label']) === false) $out[$lastIdx]['label'] .= ' + ' . $r['label'];
                continue;
            }
            $r['rr'] = round(abs($r['price'] - $entry) / $risk, 2);
            $out[] = $r;
        }
        return $out;
    }

    private function pickTargets(array $targets): array
    {
        $tp = [];
        $minTp1 = (float)$this->scfg['tp1_min_rr'];
        $minRr = (float)$this->scfg['min_rr'];
        foreach ($targets as $t) {
            if (!$tp) { if ($t['rr'] >= $minTp1) $tp[] = $t; continue; }
            if (count($tp) === 1) {
                if ($tp[0]['rr'] >= $minRr || $t['rr'] >= $minRr) $tp[] = $t;
                continue;
            }
            if (count($tp) === 2) { $tp[] = $t; break; }
        }
        return $tp;
    }

    private function riskFilters(array &$c, array $ctx, array $E): void
    {
        // Institutional Risk & Emergency Controls (Section 4)
        if (!empty(\App\Core\Settings::get('risk.emergency_stop')) || !empty($ctx['emergency_stop'])) {
            $c['blockers'][] = 'Emergency Stop ACTIVE: System-wide trading halt enforced by risk manager.';
        }

        if (!empty($ctx['portfolio'])) {
            $riskCalc = new RiskCalculator(null, (string)($this->mcfg['symbol'] ?? 'XAUUSD'));
            $limits = $riskCalc->evaluatePortfolioLimits((float)($ctx['balance'] ?? 10000.0), $ctx['portfolio']);
            if (!$limits['allowed']) {
                $c['blockers'][] = $limits['reason'];
            }
        }

        if (!empty($ctx['regime_analysis']['circuit_breaker'])) {
            $c['blockers'][] = 'Market Regime Circuit Breaker: Abnormal volatility hazard active. Setups suspended.';
        }

        $spread = (float)($ctx['spread'] ?? $this->scfg['assumed_spread']);
        $src = $ctx['spread_source'] ?? 'assumed';
        if ($spread > $this->scfg['max_spread']) {
            $c['blockers'][] = sprintf('Spread %.2f exceeds maximum %.2f', $spread, $this->scfg['max_spread']);
        }
        if ($src !== 'provider') {
            $c['warnings'][] = sprintf('Spread %.2f is a configured assumption (provider does not supply bid/ask). Check your broker.', $spread);
        }
        $ratio = $E['atr_ratio'];
        if ($ratio >= 10.0) {
            $c['blockers'][] = sprintf('Extreme volatility anomaly: ATR is %.1fx its 100-candle average', $ratio);
        } elseif ($ratio >= $this->scfg['vol_extreme_ratio']) {
            $c['warnings'][] = sprintf('High session volatility: ATR is %.1fx its 100-candle average - use strict risk sizing', $ratio);
        } elseif ($ratio >= $this->scfg['vol_spike_ratio']) {
            $c['warnings'][] = sprintf('Elevated volatility: ATR is %.1fx its 100-candle average - slippage risk', $ratio);
        }
        $c['news'] = ['connected' => !empty($ctx['news_connected']), 'blocking' => null, 'upcoming' => []];
        if (empty($ctx['news_connected'])) {
            $c['warnings'][] = 'Economic calendar not connected/verified - news risk unknown.';
            return;
        }
        $now = (int)$ctx['now'];
        $before = (int)$this->scfg['news_block_before_min'] * 60;
        $after = (int)$this->scfg['news_block_after_min'] * 60;
        $ccy = (array)$this->scfg['news_currencies'];
        foreach ((array)($ctx['events'] ?? []) as $ev) {
            if (!in_array($ev['country'], $ccy, true)) continue;
            $t = (int)$ev['time'];
            if ($ev['impact'] === 'high' && $now >= $t - $before && $now <= $t + $after) {
                $c['news']['blocking'] = $ev;
                $c['blockers'][] = sprintf('High-impact news window: %s %s at %s UTC', $ev['country'], $ev['title'], gmdate('Y-m-d H:i', $t));
            }
            if ($t > $now && $t - $now <= 6 * 3600 && in_array($ev['impact'], ['high', 'medium'], true)) {
                $c['news']['upcoming'][] = $ev;
            }
        }
    }

    /**
     * Transparent setup-quality score. Each evidence group is counted once (no double counting):
     * MSS is part of 'structure'; OB+FVG share one 'poi' group; displacement counted once.
     */
    private function score(array $c, array $ctx, bool $stateOk = false): array
    {
        $w = (array)$this->scfg['weights'];
        $allConfirmed = empty($c['missing']) && empty($c['blockers']) && $stateOk;
        $f = []; // fraction 0..1 per group
        $f['htf'] = $c['conds']['htf']['met'] ? (($c['htf_full'] || $allConfirmed) ? 1.0 : 0.85) : 0.0;
        $sw = $c['sweep'];
        $f['sweep'] = ($sw && ($sw['rejection'] >= 0.25 || $allConfirmed)) || $c['key_sweep'] ? 1.0 : ($sw ? 0.85 : 0.0);
        $f['structure'] = $c['conds']['structure']['met'] ? (($c['evt']['type'] === 'CHOCH' || !empty($c['evt']['mss']) || $allConfirmed) ? 1.0 : 0.9) : 0.0;
        $f['displacement'] = $c['conds']['displacement']['met'] ? 1.0 : 0.0;
        $f['poi'] = $c['zone'] ? (($c['overlap'] || $allConfirmed) ? 1.0 : 0.9) : 0.0;
        $f['location'] = $c['conds']['location']['met'] ? 1.0 : (!empty($c['location_partial']) ? 0.5 : 0.0);
        $maxSession = 5;
        foreach ((array)\App\Core\Settings::get('sessions', []) as $s) $maxSession = max($maxSession, (int)$s['score']);
        $sessScore = $this->sessions->score((int)$ctx['now']);
        $f['session'] = $allConfirmed && $sessScore >= 3 ? 1.0 : ($sessScore / max(1, $maxSession));
        $spread = (float)($ctx['spread'] ?? $this->scfg['assumed_spread']);
        $spreadOk = $spread <= $this->scfg['max_spread'];
        $volOk = !array_filter($c['warnings'], static fn($x) => strpos($x, 'volatility') !== false) && !array_filter($c['blockers'], static fn($x) => strpos($x, 'volatility') !== false);
        $f['spread_vol'] = $spreadOk && ($volOk || $allConfirmed) ? 1.0 : ($spreadOk ? 0.6 : 0.0);
        if (empty($c['news']['connected']) || $c['news']['blocking']) {
            $f['news'] = 0.0;
        } else {
            $hasHigh = (bool)array_filter($c['news']['upcoming'], static fn($e) => $e['impact'] === 'high');
            $f['news'] = $hasHigh ? 0.4 : (count($c['news']['upcoming']) ? 0.7 : 1.0);
        }
        $parts = [];
        $total = 0.0; $max = 0.0;
        foreach ($w as $k => $weight) {
            $pts = round($weight * ($f[$k] ?? 0), 1);
            $parts[$k] = ['points' => $pts, 'max' => $weight];
            $total += $pts; $max += $weight;
        }
        $pct = (int)round($max > 0 ? $total / $max * 100 : 0);
        if (!$allConfirmed && $pct >= 85) {
            $pct = 84;
        }
        return ['total' => $pct, 'parts' => $parts,
            'note' => 'Setup quality score (rule-based confluence), NOT a calibrated probability of winning.'];
    }

    private function finalSignal(array $cands, array $qual, array $ctx, string $entryTf, array $biasTfs, float $price, int $now, array $E, array $ict = []): array
    {
        $base = [
            'asset' => (string)($this->mcfg['symbol'] ?? 'XAUUSD'), 'price' => $price, 'analyzed_at' => $now, 'data_mode' => $ctx['data_mode'] ?? null,
            'source' => $ctx['source'] ?? null, 'entry_tf' => $entryTf, 'bias_tfs' => $biasTfs,
            'data_ref' => $E['last_time'],
        ];
        if (count($qual) === 1) {
            $c = reset($qual);
            $L = $c['levels'];
            $base['data_ref'] = (int)($L['formed_t'] ?? $E['last_time']);
            $strong = $c['score']['total'] >= $this->scfg['strong_score'] && $c['conds']['sweep']['met'] && $c['conds']['displacement']['met'];
            $tp = $L['targets'];
            $isBuy = $c['dir'] === 1;
            $expires = $now + (int)$this->scfg['expiry_candles'] * Timeframe::seconds($entryTf);
            $setupType = !empty($c['detected_strat'])
                ? $c['detected_strat'] . ' (' . $c['zone']['kind'] . ')'
                : trim(($c['key_sweep'] || $c['sweep'] ? 'Sweep + ' : '') . ($c['evt']['mss'] ? 'MSS' : $c['evt']['type']) . ' + ' . $c['zone']['kind']);
            $confs = $this->confluences($c);
            $metCount = count(array_filter($confs, static fn($x) => !empty($x['met'])));
            $totalConfs = count($confs) ?: 8;
            $scoreVal = (int)$c['score']['total'];
            $isReady85 = $scoreVal >= 85;
            $readiness = [
                'stage'          => $isReady85 ? 'ready_85' : 'nearing_confirmation',
                'ready'          => true,
                'direction'      => $c['name'],
                'met_count'      => $metCount,
                'total_count'    => $totalConfs,
                'completion_pct' => (int)round(($metCount / max(1, $totalConfs)) * 100),
                'score'          => $scoreVal,
                'missing_labels' => [],
                'title'          => $isReady85
                    ? sprintf('🚨 SCORE %d/100 (≥85) — PREPARE FOR %s MARKET ENTRY', $scoreVal, $c['name'])
                    : sprintf('⏳ CONFIRMATION NEARING (%d/%d · %d/100) — PREPARE %s ENTRY', $metCount, $totalConfs, $scoreVal, $c['name']),
                'summary'        => sprintf(
                    '%s %s setup confirmed (%d/%d confluences · Score %d/100). Prepare Market/Limit Entry at $%.2f – $%.2f (Ref $%.2f, SL $%.2f, TP1 $%.2f).',
                    $base['asset'], $c['name'], $metCount, $totalConfs, $scoreVal,
                    $L['entry_low'], $L['entry_high'], $L['entry'], $L['sl'], (float)($tp[0]['price'] ?? 0)
                ),
                'key'            => sprintf(
                    '%s:%s:%s:%s:%.1f_%.1f',
                    $isReady85 ? 'ready85' : 'prep',
                    $base['asset'], $entryTf, $c['name'], $L['entry_low'], $L['entry_high']
                ),
            ];
            return $base + [
                'direction' => $c['name'],
                'label' => ($strong ? 'STRONG ' : '') . $c['name'] . ' SETUP',
                'setup_status' => $L['state'],
                'setup_type' => $setupType,
                'ict_strategy' => $c['detected_strat'] ?? null,
                'ict_details' => $c['ict_details'] ?? null,
                'entry_low' => round($L['entry_low'], 2), 'entry_high' => round($L['entry_high'], 2),
                'entry' => round($L['entry'], 2), 'sl' => round($L['sl'], 2),
                'tp1' => isset($tp[0]) ? round($tp[0]['price'], 2) : null, 'rr1' => $tp[0]['rr'] ?? null, 'tp1_label' => $tp[0]['label'] ?? null,
                'tp2' => isset($tp[1]) ? round($tp[1]['price'], 2) : null, 'rr2' => $tp[1]['rr'] ?? null, 'tp2_label' => $tp[1]['label'] ?? null,
                'tp3' => isset($tp[2]) ? round($tp[2]['price'], 2) : null, 'rr3' => $tp[2]['rr'] ?? null, 'tp3_label' => $tp[2]['label'] ?? null,
                'stop_distance' => round($L['risk'], 2),
                'quality_score' => $c['score']['total'], 'score' => $c['score'],
                'readiness' => $readiness,
                'confluences' => $confs,
                'reasons' => array_values(array_map(static fn($x) => $x['detail'], array_filter($c['conds'], static fn($x) => $x['met']))),
                'invalidations' => array_values(array_filter([
                    sprintf('A %s candle closes %s the stop loss %.2f', $entryTf, $isBuy ? 'below' : 'above', $L['sl']),
                    sprintf('%s bias flips %s (opposite CHOCH on %s)', implode('/', $biasTfs), $isBuy ? 'bearish' : 'bullish', implode('/', $biasTfs)),
                    isset($tp[0]) ? sprintf('Price reaches TP1 %.2f before filling the entry zone (setup missed)', $tp[0]['price']) : null,
                    sprintf('Entry not filled before %s UTC (%d %s candles)', gmdate('Y-m-d H:i', $expires), $this->scfg['expiry_candles'], $entryTf),
                    'A high-impact news window opens before entry',
                ])),
                'warnings' => $c['warnings'],
                'news' => $c['news'],
                'expires_at' => $expires,
                'conditions' => $c['conds'],
            ];
        }
        // WAIT
        $best = $cands[1]['score']['total'] >= $cands[-1]['score']['total'] ? $cands[1] : $cands[-1];
        $reasons = [];
        if (count($qual) > 1) {
            $reasons[] = 'Conflicting BUY and SELL setups - standing aside.';
        }
        foreach ($best['missing'] as $m) $reasons[] = 'Missing: ' . $best['conds'][$m]['detail'];
        foreach ($best['blockers'] as $b) $reasons[] = 'Blocked: ' . $b;
        if ($best['levels'] && !in_array($best['levels']['state'], ['confirmed', 'triggered'], true)) {
            $reasons[] = 'Latest ' . $best['name'] . ' setup is ' . $best['levels']['state'] . '.';
        }
        if (!$best['missing'] && !$best['blockers'] && $best['score']['total'] < $this->scfg['min_score']) {
            $reasons[] = sprintf('Setup quality %d below minimum %d.', $best['score']['total'], $this->scfg['min_score']);
        }
        $confs = $this->confluences($best);
        $metCount = count(array_filter($confs, static fn($x) => !empty($x['met'])));
        $totalConfs = count($confs) ?: 8;
        $bestL = $best['levels'] ?? null;
        $bestTp = $bestL['targets'] ?? [];
        $stateStr = (string)($bestL['state'] ?? 'confirmed');
        $isLiveState = $bestL !== null && in_array($stateStr, ['confirmed', 'triggered'], true);
        $potential = $isLiveState && (($metCount >= 5) || ($best['conds']['htf']['met'] && ($best['conds']['zone']['met'] || $best['conds']['structure']['met'] || $best['conds']['sweep']['met'])));
        $activeL = $isLiveState ? $bestL : null;
        $activeTp = $isLiveState ? $bestTp : [];
        $missingLabels = array_values(array_map(static fn($x) => $x['label'], array_filter($confs, static fn($x) => empty($x['met']))));
        $scoreVal = (int)$best['score']['total'];
        $nearConfirm = $potential
            && empty($best['blockers'])
            && $isLiveState
            && ($metCount >= 5 || $scoreVal >= 70);
        $readiness = [
            'stage'          => $nearConfirm ? 'nearing_confirmation' : 'standby',
            'ready'          => $nearConfirm,
            'direction'      => $best['name'],
            'met_count'      => $metCount,
            'total_count'    => $totalConfs,
            'completion_pct' => (int)round(($metCount / max(1, $totalConfs)) * 100),
            'score'          => $scoreVal,
            'missing_labels' => $missingLabels,
            'title'          => $nearConfirm
                ? sprintf('⏳ NEARING CONFIRMATION (%d/%d · %d/100) — PREPARE FOR %s ENTRY', $metCount, $totalConfs, $scoreVal, $best['name'])
                : sprintf('Standby (%d/%d Confluences · %d/100)', $metCount, $totalConfs, $scoreVal),
            'summary'        => $nearConfirm
                ? sprintf(
                    '%s %s setup is nearing completion (%d/%d confluences met · Score %d/100). Get ready in advance for Entry Zone $%.2f – $%.2f (Ref $%.2f | SL $%.2f | TP1 $%.2f). Remaining: %s.',
                    $base['asset'], $best['name'], $metCount, $totalConfs, $scoreVal,
                    $activeL['entry_low'], $activeL['entry_high'], $activeL['entry'], $activeL['sl'], (float)($activeTp[0]['price'] ?? 0),
                    $missingLabels ? implode(', ', $missingLabels) : 'Final confirmation candle'
                )
                : ($stateStr === 'expired'
                    ? 'Latest ' . $best['name'] . ' setup has expired (target reached or move completed). Waiting for fresh market structure.'
                    : 'Waiting for additional multi-timeframe SMC confluence alignment.'),
            'key'            => $activeL
                ? sprintf('prep:%s:%s:%s:%.1f_%.1f', $base['asset'], $entryTf, $best['name'], $activeL['entry_low'], $activeL['entry_high'])
                : sprintf('standby:%s:%s', $base['asset'], $entryTf),
        ];
        return $base + [
            'direction' => 'WAIT',
            'label' => 'WAIT / NO TRADE',
            'setup_status' => $potential ? 'potential' : ($stateStr === 'expired' ? 'expired' : 'none'),
            'setup_type' => $potential && $best['zone'] ? (!empty($best['detected_strat']) ? $best['detected_strat'] : ($best['name'] . ' Watch (' . $best['zone']['kind'] . ')')) : null,
            'ict_strategy' => $best['detected_strat'] ?? null,
            'ict_details' => $best['ict_details'] ?? null,
            'entry_low' => $activeL ? round($activeL['entry_low'], 2) : null,
            'entry_high' => $activeL ? round($activeL['entry_high'], 2) : null,
            'entry' => $activeL ? round($activeL['entry'], 2) : null,
            'sl' => $activeL ? round($activeL['sl'], 2) : null,
            'tp1' => isset($activeTp[0]) ? round($activeTp[0]['price'], 2) : null,
            'rr1' => $activeTp[0]['rr'] ?? null,
            'tp2' => isset($activeTp[1]) ? round($activeTp[1]['price'], 2) : null,
            'rr2' => $activeTp[1]['rr'] ?? null,
            'quality_score' => $best['score']['total'], 'score' => $best['score'],
            'readiness' => $readiness,
            'watch' => $potential ? [
                'direction' => $best['name'],
                'zone' => $best['zone'] ? ['top' => round($best['zone']['top'], 2), 'bottom' => round($best['zone']['bottom'], 2), 'kind' => $best['zone']['kind']] : null,
                'missing' => $best['missing'],
            ] : null,
            'confluences' => $confs,
            'reasons' => $reasons ?: ['Insufficient evidence for a rule-qualified setup.'],
            'invalidations' => [],
            'warnings' => $best['warnings'],
            'news' => $best['news'],
            'conditions' => $best['conds'],
            'expires_at' => null,
        ];
    }

    private function confluences(array $c): array
    {
        $labels = ['htf' => 'HTF structure aligned', 'sweep' => 'Liquidity sweep', 'structure' => 'BOS/CHOCH confirmed',
            'displacement' => 'Displacement', 'zone' => 'OB/FVG entry zone', 'location' => 'Premium/discount location',
            'sl' => 'Technical invalidation', 'rr' => 'Acceptable R:R'];
        $out = [];
        foreach ($c['conds'] as $k => $v) $out[] = ['key' => $k, 'label' => $labels[$k] ?? $k, 'met' => $v['met'], 'detail' => $v['detail']];
        if (!empty($c['detected_strat'])) {
            $out[] = [
                'key' => 'ict_model',
                'label' => 'ICT Model: ' . $c['detected_strat'],
                'met' => true,
                'detail' => 'Pattern aligns with ' . $c['detected_strat'] . (!empty($c['ict_details']['killzone']) ? ' during ' . $c['ict_details']['killzone'] : '')
            ];
        }
        if (!empty($c['ict_details']['in_killzone'])) {
            $out[] = [
                'key' => 'ict_killzone',
                'label' => 'ICT Kill Zone (' . $c['ict_details']['killzone'] . ')',
                'met' => true,
                'detail' => 'High institutional volume window active: ' . $c['ict_details']['killzone']
            ];
        }
        return $out;
    }

    private function publicCandidate(array $c): array
    {
        return [
            'direction' => $c['name'], 'qualifies' => $c['qualifies'], 'score' => $c['score']['total'],
            'missing' => $c['missing'], 'blockers' => $c['blockers'], 'state' => $c['levels']['state'] ?? null,
            'zone' => $c['zone'] ? ['top' => $c['zone']['top'], 'bottom' => $c['zone']['bottom'], 'kind' => $c['zone']['kind']] : null,
        ];
    }

    private function keyLevels(array $closed, int $now): array
    {
        $out = [];
        if (!empty($closed['D1'])) {
            $d = end($closed['D1']);
            $out += ['pdh' => $d['h'], 'pdl' => $d['l'], 'pd_time' => $d['t']];
        }
        if (!empty($closed['W1'])) {
            $w = end($closed['W1']);
            $out += ['pwh' => $w['h'], 'pwl' => $w['l'], 'pw_time' => $w['t']];
        }
        // Fallback: derive previous UTC day from intraday data
        if (!isset($out['pdh'])) {
            $src = $closed[$this->mcfg['poi_tf']] ?? ($closed[$this->mcfg['entry_tf']] ?? []);
            $today = $now - ($now % 86400);
            $prevDay = null; $h = null; $l = null;
            for ($k = count($src) - 1; $k >= 0; $k--) {
                $day = $src[$k]['t'] - ($src[$k]['t'] % 86400);
                if ($day >= $today) continue;
                if ($prevDay === null) $prevDay = $day;
                if ($day !== $prevDay) break;
                $h = $h === null ? $src[$k]['h'] : max($h, $src[$k]['h']);
                $l = $l === null ? $src[$k]['l'] : min($l, $src[$k]['l']);
            }
            if ($h !== null) $out += ['pdh' => $h, 'pdl' => $l, 'pd_time' => $prevDay];
        }
        return $out;
    }

    private function nearestLiquidity(array $E, ?array $P, float $price): array
    {
        $above = null; $below = null;
        foreach ([$E, $P] as $r) {
            if (!$r) continue;
            foreach ($r['liquidity']['bsl'] as $l) if ($l['status'] === 'active' && $l['price'] > $price && ($above === null || $l['price'] < $above)) $above = $l['price'];
            foreach ($r['liquidity']['ssl'] as $l) if ($l['status'] === 'active' && $l['price'] < $price && ($below === null || $l['price'] > $below)) $below = $l['price'];
        }
        $nearest = null;
        if ($above !== null || $below !== null) {
            $da = $above !== null ? $above - $price : INF;
            $db = $below !== null ? $price - $below : INF;
            $nearest = $da <= $db ? ['type' => 'BSL', 'price' => $above, 'distance' => round($da, 2)] : ['type' => 'SSL', 'price' => $below, 'distance' => round($db, 2)];
        }
        return ['bsl' => $above, 'ssl' => $below, 'nearest' => $nearest];
    }

    private function unavailable(string $why, array $ctx, array $res): array
    {
        return [
            'ok' => false, 'error' => $why, 'analyzed_at' => (int)$ctx['now'], 'data_mode' => $ctx['data_mode'] ?? null,
            'signal' => ['direction' => 'WAIT', 'label' => 'DATA UNAVAILABLE', 'setup_status' => 'none', 'reasons' => [$why],
                'quality_score' => null, 'warnings' => [], 'confluences' => [], 'invalidations' => []],
            '_engine' => $res,
        ];
    }

    public static function dirLabel(int $d, bool $cap = false): string
    {
        $s = $d === 1 ? 'bullish' : ($d === -1 ? 'bearish' : 'neutral');
        return $cap ? ucfirst($s) : $s;
    }
}
