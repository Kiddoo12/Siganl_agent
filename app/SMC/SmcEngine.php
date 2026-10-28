<?php
declare(strict_types=1);

namespace App\SMC;

/**
 * Deterministic, rule-based Smart Money Concepts engine.
 *
 * Input: closed OHLC candles (oldest -> newest), each ['t','o','h','l','c','v'].
 * Every detection rule is documented inline and parameterised via $cfg.
 * Look-ahead safety: a swing pivot is only "known" at index i + swing_right
 * (its confirmation candle). Structure breaks only reference swings confirmed
 * BEFORE the breaking candle, and breaks require a candle CLOSE beyond the level.
 */
final class SmcEngine
{
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = array_merge([
            'swing_left' => 3, 'swing_right' => 3, 'pd_swing_left' => 6, 'pd_swing_right' => 6,
            'atr_period' => 14, 'break_buffer_atr' => 0.05, 'displacement_body_ratio' => 0.6,
            'displacement_atr_mult' => 1.3, 'fvg_min_atr' => 0.15, 'ob_lookback' => 6,
            'ob_require_displacement' => true, 'ob_zone' => 'wick', 'ob_max_age' => 150,
            'eq_tolerance_atr' => 0.10, 'eq_lookback_swings' => 8, 'sweep_min_atr' => 0.02,
            'eq_band' => 0.05, 'max_items' => 25,
        ], $cfg);
    }

    public function config(): array { return $this->cfg; }

    public function analyze(array $c): array
    {
        $n = count($c);
        if ($n < max(30, $this->cfg['atr_period'] + 5)) {
            return ['ok' => false, 'error' => 'Insufficient candles (' . $n . ')', 'count' => $n];
        }
        $atr = self::atr($c, (int)$this->cfg['atr_period']);
        $disp = $this->displacements($c, $atr);
        $swings = $this->swings($c, (int)$this->cfg['swing_left'], (int)$this->cfg['swing_right']);
        $structure = $this->structure($c, $swings, $atr, $disp);
        $fvgs = $this->fvgs($c, $atr, $disp);
        $obs = $this->orderBlocks($c, $structure['events'], $disp);
        $liq = $this->liquidity($c, $swings, $atr);
        $major = $this->swings($c, (int)$this->cfg['pd_swing_left'], (int)$this->cfg['pd_swing_right']);
        $pd = $this->premiumDiscount($c, $major['all'] ?: $swings['all']);

        $last = $c[$n - 1];
        $atrNow = $atr[$n - 1];
        $avgAtr = array_sum(array_slice($atr, -100)) / max(1, count(array_slice($atr, -100)));
        $max = (int)$this->cfg['max_items'];

        return [
            'ok' => true,
            'count' => $n,
            'last_time' => $last['t'],
            'close' => $last['c'],
            'atr' => $atrNow,
            'atr_ratio' => $avgAtr > 0 ? $atrNow / $avgAtr : 1.0,
            'trend' => $structure['trend'],               // 1 bullish, -1 bearish, 0 undefined
            'trend_label' => $structure['trend'] === 1 ? 'bullish' : ($structure['trend'] === -1 ? 'bearish' : 'neutral'),
            'swings' => array_slice($swings['all'], -40),
            'events' => array_slice($structure['events'], -$max),
            'last_event' => $structure['events'] ? end($structure['events']) : null,
            'displacements' => array_slice($disp['list'], -$max),
            'fvgs' => array_slice($fvgs, -$max),
            'order_blocks' => array_slice($obs, -$max),
            'liquidity' => [
                'bsl' => array_slice($liq['bsl'], -$max),
                'ssl' => array_slice($liq['ssl'], -$max),
                'eqh' => $liq['eqh'], 'eql' => $liq['eql'],
                'sweeps' => array_slice($liq['sweeps'], -$max),
            ],
            'premium_discount' => $pd,
            '_disp_index' => $disp['byIndex'],
        ];
    }

    // ------------------------------------------------------------------
    // Indicators
    // ------------------------------------------------------------------

    /** Wilder ATR. Values before `period` use the running mean of true range. */
    public static function atr(array $c, int $period = 14): array
    {
        $n = count($c);
        $atr = [];
        $sum = 0.0;
        $prev = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $tr = $i === 0 ? $c[0]['h'] - $c[0]['l'] : max(
                $c[$i]['h'] - $c[$i]['l'],
                abs($c[$i]['h'] - $c[$i - 1]['c']),
                abs($c[$i]['l'] - $c[$i - 1]['c'])
            );
            if ($i < $period) {
                $sum += $tr;
                $prev = $sum / ($i + 1);
            } else {
                $prev = ($prev * ($period - 1) + $tr) / $period;
            }
            $atr[$i] = max($prev, 1e-9);
        }
        return $atr;
    }

    // ------------------------------------------------------------------
    // F. Displacement
    // Rule: body/range >= displacement_body_ratio AND range >= displacement_atr_mult x ATR(previous candle).
    // ------------------------------------------------------------------
    private function displacements(array $c, array $atr): array
    {
        $list = [];
        $by = [];
        for ($i = 1, $n = count($c); $i < $n; $i++) {
            $range = $c[$i]['h'] - $c[$i]['l'];
            if ($range <= 0) continue;
            $body = abs($c[$i]['c'] - $c[$i]['o']);
            if ($body / $range >= $this->cfg['displacement_body_ratio'] && $range >= $this->cfg['displacement_atr_mult'] * $atr[$i - 1]) {
                $dir = $c[$i]['c'] > $c[$i]['o'] ? 1 : -1;
                $by[$i] = $dir;
                $list[] = ['i' => $i, 't' => $c[$i]['t'], 'dir' => $dir, 'range_atr' => round($range / $atr[$i - 1], 2)];
            }
        }
        return ['list' => $list, 'byIndex' => $by];
    }

    // ------------------------------------------------------------------
    // A. Swing points
    // Pivot high at i: high[i] > highs of `left` prior candles AND >= highs of `right` later candles.
    // Confirmed (known) only at index i + right.
    // ------------------------------------------------------------------
    public function swings(array $c, int $left, int $right): array
    {
        $n = count($c);
        $all = [];
        for ($i = $left; $i < $n - $right; $i++) {
            $isH = true; $isL = true;
            for ($j = $i - $left; $j < $i; $j++) {
                if ($c[$j]['h'] >= $c[$i]['h']) $isH = false;
                if ($c[$j]['l'] <= $c[$i]['l']) $isL = false;
            }
            for ($j = $i + 1; $j <= $i + $right; $j++) {
                if ($c[$j]['h'] > $c[$i]['h']) $isH = false;
                if ($c[$j]['l'] < $c[$i]['l']) $isL = false;
            }
            if ($isH) $all[] = ['type' => 'high', 'i' => $i, 't' => $c[$i]['t'], 'price' => $c[$i]['h'], 'confirmed_i' => $i + $right];
            if ($isL) $all[] = ['type' => 'low', 'i' => $i, 't' => $c[$i]['t'], 'price' => $c[$i]['l'], 'confirmed_i' => $i + $right];
        }
        // HH / LH / HL / LL labels relative to previous swing of the same type
        $prevH = null; $prevL = null;
        foreach ($all as &$s) {
            if ($s['type'] === 'high') {
                $s['label'] = $prevH === null ? 'H' : ($s['price'] > $prevH ? 'HH' : 'LH');
                $prevH = $s['price'];
            } else {
                $s['label'] = $prevL === null ? 'L' : ($s['price'] < $prevL ? 'LL' : 'HL');
                $prevL = $s['price'];
            }
        }
        unset($s);
        return ['all' => $all];
    }

    // ------------------------------------------------------------------
    // A. Market structure: BOS / CHOCH / MSS
    // Bullish break: CLOSE > last confirmed unbroken swing high + break_buffer_atr x ATR.
    //   - CHOCH if prevailing trend was bearish, otherwise BOS.
    //   - MSS  = CHOCH whose impulse leg contains a displacement candle.
    // Bearish mirror. Each swing can be broken only once.
    // ------------------------------------------------------------------
    private function structure(array $c, array $swings, array $atr, array $disp): array
    {
        $n = count($c);
        $byConfirm = [];
        foreach ($swings['all'] as $idx => $s) {
            $byConfirm[$s['confirmed_i']][] = $idx;
        }
        $trend = 0;
        $events = [];
        $hi = null; // ['price','i','t']
        $lo = null;
        for ($j = 0; $j < $n; $j++) {
            $buf = $this->cfg['break_buffer_atr'] * $atr[$j];
            if ($hi !== null && $c[$j]['c'] > $hi['price'] + $buf) {
                $legStart = $this->extremeIndex($c, $hi['i'], $j, 'low');
                $hasDisp = $this->hasDisp($disp['byIndex'], $legStart, $j, 1);
                $type = $trend === -1 ? 'CHOCH' : 'BOS';
                $events[] = [
                    'type' => $type, 'dir' => 1, 'level' => $hi['price'], 'swing_i' => $hi['i'], 'swing_t' => $hi['t'],
                    'i' => $j, 't' => $c[$j]['t'], 'leg_start' => $legStart, 'leg_price' => $c[$legStart]['l'],
                    'displacement' => $hasDisp, 'mss' => $type === 'CHOCH' && $hasDisp,
                ];
                $trend = 1;
                $hi = null;
            }
            if ($lo !== null && $c[$j]['c'] < $lo['price'] - $buf) {
                $legStart = $this->extremeIndex($c, $lo['i'], $j, 'high');
                $hasDisp = $this->hasDisp($disp['byIndex'], $legStart, $j, -1);
                $type = $trend === 1 ? 'CHOCH' : 'BOS';
                $events[] = [
                    'type' => $type, 'dir' => -1, 'level' => $lo['price'], 'swing_i' => $lo['i'], 'swing_t' => $lo['t'],
                    'i' => $j, 't' => $c[$j]['t'], 'leg_start' => $legStart, 'leg_price' => $c[$legStart]['h'],
                    'displacement' => $hasDisp, 'mss' => $type === 'CHOCH' && $hasDisp,
                ];
                $trend = -1;
                $lo = null;
            }
            // Swings confirmed at the close of candle j become references from j+1
            foreach ($byConfirm[$j] ?? [] as $idx) {
                $s = $swings['all'][$idx];
                if ($s['type'] === 'high') $hi = ['price' => $s['price'], 'i' => $s['i'], 't' => $s['t']];
                else $lo = ['price' => $s['price'], 'i' => $s['i'], 't' => $s['t']];
            }
        }
        return ['trend' => $trend, 'events' => $events];
    }

    private function extremeIndex(array $c, int $from, int $to, string $kind): int
    {
        $best = $from;
        for ($k = $from; $k <= $to; $k++) {
            if ($kind === 'low' ? $c[$k]['l'] < $c[$best]['l'] : $c[$k]['h'] > $c[$best]['h']) $best = $k;
        }
        return $best;
    }

    private function hasDisp(array $by, int $from, int $to, int $dir): bool
    {
        for ($k = $from; $k <= $to; $k++) {
            if (($by[$k] ?? 0) === $dir) return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // D. Fair Value Gaps (three-candle imbalance)
    // Bullish: high[i-2] < low[i]  -> gap [high[i-2], low[i]]
    // Bearish: low[i-2]  > high[i] -> gap [high[i], low[i-2]]
    // Minimum size fvg_min_atr x ATR. Fill % = deepest retracement into the gap.
    // Invalidated when a candle CLOSES beyond the far edge.
    // ------------------------------------------------------------------
    private function fvgs(array $c, array $atr, array $disp): array
    {
        $n = count($c);
        $out = [];
        for ($i = 2; $i < $n; $i++) {
            $dir = 0;
            if ($c[$i - 2]['h'] < $c[$i]['l']) { $dir = 1; $bottom = $c[$i - 2]['h']; $top = $c[$i]['l']; }
            elseif ($c[$i - 2]['l'] > $c[$i]['h']) { $dir = -1; $bottom = $c[$i]['h']; $top = $c[$i - 2]['l']; }
            if (!$dir || ($top - $bottom) < $this->cfg['fvg_min_atr'] * $atr[$i]) continue;

            $size = $top - $bottom;
            $fill = 0.0; $status = 'fresh'; $endI = null;
            for ($k = $i + 1; $k < $n; $k++) {
                if ($dir === 1) {
                    if ($c[$k]['l'] < $top) $fill = max($fill, ($top - max($c[$k]['l'], $bottom)) / $size);
                    if ($c[$k]['c'] < $bottom) { $status = 'invalidated'; $endI = $k; break; }
                } else {
                    if ($c[$k]['h'] > $bottom) $fill = max($fill, (min($c[$k]['h'], $top) - $bottom) / $size);
                    if ($c[$k]['c'] > $top) { $status = 'invalidated'; $endI = $k; break; }
                }
                if ($fill >= 0.999) { $status = 'filled'; $endI = $k; break; }
            }
            if ($status === 'fresh' && $fill > 0) $status = 'partial';
            $out[] = [
                'dir' => $dir, 'top' => $top, 'bottom' => $bottom, 'i' => $i - 1, 't' => $c[$i - 1]['t'],
                'confirmed_i' => $i, 'age' => $n - 1 - $i, 'fill_pct' => round(min(1, $fill) * 100, 1),
                'status' => $status, 'end_t' => $endI !== null ? $c[$endI]['t'] : null,
                'displacement' => isset($disp['byIndex'][$i - 1]) && $disp['byIndex'][$i - 1] === $dir,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // C. Order Blocks
    // Rule: for each BOS/CHOCH, take the extreme candle of the impulse leg (lowest low for
    // bullish, highest high for bearish) and search back up to `ob_lookback` candles for the
    // LAST opposite-colour candle (bearish candle for a bullish OB). If
    // ob_require_displacement, the leg must contain a displacement candle.
    // Zone = candle wick range (or body+wick extreme with ob_zone=body).
    // Status: fresh -> mitigated (first revisit after the break) -> invalidated (close beyond
    // far edge); untouched for > ob_max_age candles -> expired.
    // ------------------------------------------------------------------
    private function orderBlocks(array $c, array $events, array $disp): array
    {
        $n = count($c);
        $out = [];
        $used = [];
        foreach ($events as $e) {
            if ($this->cfg['ob_require_displacement'] && !$e['displacement']) continue;
            $obI = null;
            for ($k = $e['leg_start']; $k >= max(0, $e['leg_start'] - (int)$this->cfg['ob_lookback']); $k--) {
                $isOpp = $e['dir'] === 1 ? $c[$k]['c'] < $c[$k]['o'] : $c[$k]['c'] > $c[$k]['o'];
                if ($isOpp) { $obI = $k; break; }
            }
            if ($obI === null) {
                for ($k = min($e['i'] - 1, $e['leg_start'] + (int)$this->cfg['ob_lookback']); $k > $e['leg_start']; $k--) {
                    $isOpp = $e['dir'] === 1 ? $c[$k]['c'] < $c[$k]['o'] : $c[$k]['c'] > $c[$k]['o'];
                    if ($isOpp) { $obI = $k; break; }
                }
            }
            if ($obI === null || isset($used[$obI])) continue;
            $used[$obI] = true;
            $ob = $c[$obI];
            if ($this->cfg['ob_zone'] === 'body') {
                $top = $e['dir'] === 1 ? max($ob['o'], $ob['c']) : $ob['h'];
                $bottom = $e['dir'] === 1 ? $ob['l'] : min($ob['o'], $ob['c']);
            } else {
                $top = $ob['h'];
                $bottom = $ob['l'];
            }
            $status = 'fresh'; $mitigatedT = null; $endT = null;
            for ($k = $e['i'] + 1; $k < $n; $k++) {
                if ($e['dir'] === 1) {
                    if ($c[$k]['c'] < $bottom) { $status = 'invalidated'; $endT = $c[$k]['t']; break; }
                    if ($status === 'fresh' && $c[$k]['l'] <= $top) { $status = 'mitigated'; $mitigatedT = $c[$k]['t']; }
                } else {
                    if ($c[$k]['c'] > $top) { $status = 'invalidated'; $endT = $c[$k]['t']; break; }
                    if ($status === 'fresh' && $c[$k]['h'] >= $bottom) { $status = 'mitigated'; $mitigatedT = $c[$k]['t']; }
                }
            }
            $age = $n - 1 - $e['i'];
            if ($status === 'fresh' && $age > (int)$this->cfg['ob_max_age']) $status = 'expired';
            $out[] = [
                'dir' => $e['dir'], 'top' => $top, 'bottom' => $bottom, 'i' => $obI, 't' => $ob['t'],
                'break_type' => $e['type'], 'break_i' => $e['i'], 'break_t' => $e['t'], 'break_level' => $e['level'],
                'displacement' => $e['displacement'], 'status' => $status, 'mitigated_t' => $mitigatedT,
                'end_t' => $endT, 'age' => $age,
                'rule' => 'Last ' . ($e['dir'] === 1 ? 'bearish' : 'bullish') . ' candle before the impulse leg that produced a ' .
                    $e['type'] . ($e['displacement'] ? ' with displacement' : ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // B. Liquidity
    // BSL = resting liquidity above confirmed swing highs, SSL = below swing lows.
    // Sweep: a later candle trades beyond the level by >= sweep_min_atr x ATR but CLOSES back
    //        inside (wick penetration + rejection). Breakout: candle CLOSES beyond the level.
    // Either event consumes the level. Equal highs/lows: two active swings within
    // eq_tolerance_atr x ATR of each other.
    // ------------------------------------------------------------------
    private function liquidity(array $c, array $swings, array $atr): array
    {
        $n = count($c);
        $bsl = []; $ssl = []; $sweeps = [];
        foreach ($swings['all'] as $s) {
            $isHigh = $s['type'] === 'high';
            $status = 'active'; $eventT = null; $eventI = null;
            for ($k = $s['confirmed_i'] + 1; $k < $n; $k++) {
                $pen = $isHigh ? $c[$k]['h'] - $s['price'] : $s['price'] - $c[$k]['l'];
                if ($pen <= 0) continue;
                $closedBeyond = $isHigh ? $c[$k]['c'] > $s['price'] : $c[$k]['c'] < $s['price'];
                if ($closedBeyond) { $status = 'broken'; $eventT = $c[$k]['t']; $eventI = $k; break; }
                if ($pen >= $this->cfg['sweep_min_atr'] * $atr[$k]) {
                    $status = 'swept'; $eventT = $c[$k]['t']; $eventI = $k;
                    $range = max(1e-9, $c[$k]['h'] - $c[$k]['l']);
                    $wick = $isHigh ? $c[$k]['h'] - max($c[$k]['o'], $c[$k]['c']) : min($c[$k]['o'], $c[$k]['c']) - $c[$k]['l'];
                    $sweeps[] = [
                        'type' => $isHigh ? 'BSL' : 'SSL', 'dir' => $isHigh ? -1 : 1, // reaction direction expected
                        'level' => $s['price'], 'swing_t' => $s['t'], 'i' => $k, 't' => $c[$k]['t'],
                        'extreme' => $isHigh ? $c[$k]['h'] : $c[$k]['l'],
                        'rejection' => round($wick / $range, 2),
                    ];
                    break;
                }
            }
            $item = ['price' => $s['price'], 't' => $s['t'], 'i' => $s['i'], 'status' => $status, 'event_t' => $eventT, 'event_i' => $eventI, 'label' => $s['label'] ?? null];
            if ($isHigh) $bsl[] = $item; else $ssl[] = $item;
        }
        usort($sweeps, static fn($a, $b) => $a['i'] <=> $b['i']);
        return [
            'bsl' => $bsl, 'ssl' => $ssl, 'sweeps' => $sweeps,
            'eqh' => $this->equalLevels($bsl, $atr), 'eql' => $this->equalLevels($ssl, $atr),
        ];
    }

    private function equalLevels(array $levels, array $atr): array
    {
        $recent = array_slice($levels, -(int)$this->cfg['eq_lookback_swings']);
        $out = [];
        $m = count($recent);
        for ($a = 0; $a < $m; $a++) {
            for ($b = $a + 1; $b < $m; $b++) {
                $tol = $this->cfg['eq_tolerance_atr'] * $atr[$recent[$b]['i']];
                if (abs($recent[$a]['price'] - $recent[$b]['price']) <= $tol) {
                    $status = $recent[$b]['status'] === 'active' && $recent[$a]['status'] === 'active' ? 'active'
                        : ($recent[$a]['status'] !== 'active' ? $recent[$a]['status'] : $recent[$b]['status']);
                    $out[] = [
                        'price' => round(($recent[$a]['price'] + $recent[$b]['price']) / 2, 3),
                        'p1' => $recent[$a]['price'], 'p2' => $recent[$b]['price'],
                        't1' => $recent[$a]['t'], 't2' => $recent[$b]['t'], 'status' => $status,
                    ];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // E. Premium / Discount
    // Dealing range = most recent major swing high and major swing low (larger pivots),
    // extended by any price beyond them since. EQ = midpoint.
    // ------------------------------------------------------------------
    private function premiumDiscount(array $c, array $swings): ?array
    {
        $lastH = null; $lastL = null;
        foreach ($swings as $s) {
            if ($s['type'] === 'high') $lastH = $s; else $lastL = $s;
        }
        if (!$lastH || !$lastL) return null;
        $n = count($c);
        $from = min($lastH['i'], $lastL['i']);
        $hi = $lastH['price']; $lo = $lastL['price'];
        for ($k = $from; $k < $n; $k++) {
            $hi = max($hi, $c[$k]['h']);
            $lo = min($lo, $c[$k]['l']);
        }
        if ($hi <= $lo) return null;
        $eq = ($hi + $lo) / 2;
        $band = ($hi - $lo) * $this->cfg['eq_band'];
        $close = $c[$n - 1]['c'];
        $zone = $close > $eq + $band ? 'premium' : ($close < $eq - $band ? 'discount' : 'equilibrium');
        return [
            'high' => $hi, 'low' => $lo, 'eq' => $eq, 'zone' => $zone,
            'position_pct' => round(($close - $lo) / ($hi - $lo) * 100, 1),
            'from_t' => $c[$from]['t'],
        ];
    }

    /** Position of an arbitrary price within a premium/discount range. */
    public static function zoneOf(?array $pd, float $price, float $band = 0.05): ?string
    {
        if (!$pd) return null;
        $b = ($pd['high'] - $pd['low']) * $band;
        return $price > $pd['eq'] + $b ? 'premium' : ($price < $pd['eq'] - $b ? 'discount' : 'equilibrium');
    }
}
