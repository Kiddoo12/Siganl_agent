<?php
declare(strict_types=1);

namespace App\SMC;

/**
 * Dedicated ICT (Inner Circle Trader) Strategy Engine.
 * Implements deterministic detection of core ICT models:
 *  - Breaker Blocks & Mitigation Blocks
 *  - Inverse Fair Value Gaps (IFVG)
 *  - Optimal Trade Entry (OTE 62% - 79% Fibonacci)
 *  - ICT Kill Zones & Macro Time Windows
 *  - ICT Silver Bullet (London, NY AM, NY PM 60-min models)
 *  - ICT 2022 Mentorship Model (Sweep + MSS + Displacement + FVG)
 *  - ICT Unicorn Model (Breaker Block + FVG overlap)
 *  - ICT Judas Swing (Session Open false expansion)
 *  - ICT Power of Three (AMD: Accumulation, Manipulation, Distribution)
 *  - ICT Turtle Soup (False breakout beyond 20-candle swing extreme)
 *  - ICT SMT Divergence (Inter-market non-confirmation)
 */
final class IctEngine
{
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = array_merge([
            'ote_fib_entry_min'  => 0.618,
            'ote_fib_entry_sweet'=> 0.705,
            'ote_fib_entry_max'  => 0.786,
            'unicorn_min_overlap'=> 0.25,
            'turtle_soup_lookback'=> 20,
            'judas_max_minutes'  => 45,
            'sb_target_handles'  => 12.0, // XAUUSD points/handles
        ], $cfg);
    }

    /**
     * Run complete ICT analysis across a closed candle series and SmcEngine base results.
     */
    public function analyze(array $c, array $smcRes, int $now, ?array $cCorrelated = null, ?string $correlatedSym = null): array
    {
        $n = count($c);
        if ($n < 20) {
            return ['ok' => false, 'error' => 'Insufficient candles for ICT engine (' . $n . ')'];
        }

        $atr = $smcRes['atr'] ?? (SmcEngine::atr($c, 14)[$n - 1] ?? 1.0);
        $atrArray = SmcEngine::atr($c, 14);
        $events = $smcRes['events'] ?? [];
        $obs = $smcRes['order_blocks'] ?? [];
        $fvgs = $smcRes['fvgs'] ?? [];
        $swings = $smcRes['swings'] ?? [];
        $sweeps = $smcRes['liquidity']['sweeps'] ?? [];
        $pd = $smcRes['premium_discount'] ?? null;

        // 1. Kill Zones and Macro Time Windows
        $kz = self::killzones($now);
        $macro = self::macroWindows($now);

        // 2. Breaker Blocks & Mitigation Blocks
        $breakers = $this->breakerBlocks($c, $swings, $events, $obs);
        $mitigations = $this->mitigationBlocks($c, $swings, $events, $obs);

        // 3. Inverse Fair Value Gaps (IFVG)
        $ifvgs = $this->inverseFvgs($c, $fvgs);

        // 4. Optimal Trade Entry (OTE)
        $ote = $this->optimalTradeEntry($c, $events, $pd);

        // 5. ICT 2022 Mentorship Model
        $model2022 = $this->detect2022Model($c, $sweeps, $events, $fvgs, $atr);

        // 6. ICT Unicorn Model (Breaker + FVG)
        $unicorns = $this->detectUnicorn($breakers, $fvgs);

        // 7. ICT Silver Bullet
        $silverBullet = $this->detectSilverBullet($c, $fvgs, $events, $sweeps, $now, $atr);

        // 8. ICT Turtle Soup
        $turtleSoup = $this->detectTurtleSoup($c, $swings, $atrArray);

        // 9. ICT Judas Swing
        $judas = $this->detectJudasSwing($c, $swings, $now);

        // 10. ICT Power of Three (AMD)
        $amd = $this->detectAmd($c, $smcRes['trend'] ?? 0);

        // 11. SMT Divergence (if correlated series provided)
        $smt = null;
        if (!empty($cCorrelated) && count($cCorrelated) >= 20) {
            $smt = $this->detectSmt($c, $cCorrelated, 'XAUUSD', $correlatedSym ?? 'BTCUSD');
        }

        // Active setups synthesis
        $detectedStrategies = [];
        if (!empty($silverBullet['active'])) {
            $detectedStrategies[] = ['code' => 'ict_silver_bullet', 'name' => 'ICT Silver Bullet', 'data' => $silverBullet];
        }
        if (!empty($unicorns)) {
            $detectedStrategies[] = ['code' => 'ict_unicorn', 'name' => 'ICT Unicorn Model', 'data' => $unicorns[0]];
        }
        if (!empty($model2022['active'])) {
            $detectedStrategies[] = ['code' => 'ict_2022_model', 'name' => 'ICT 2022 Mentorship Model', 'data' => $model2022];
        }
        if (!empty($ote['in_zone'])) {
            $detectedStrategies[] = ['code' => 'ict_ote', 'name' => 'ICT Optimal Trade Entry', 'data' => $ote];
        }
        if (!empty($judas['detected'])) {
            $detectedStrategies[] = ['code' => 'ict_judas_swing', 'name' => 'ICT Judas Swing', 'data' => $judas];
        }
        if (!empty($turtleSoup['active'])) {
            $detectedStrategies[] = ['code' => 'ict_turtle_soup', 'name' => 'ICT Turtle Soup', 'data' => $turtleSoup];
        }
        if (!empty($smt['divergence'])) {
            $detectedStrategies[] = ['code' => 'ict_smt_divergence', 'name' => 'ICT SMT Divergence', 'data' => $smt];
        }

        return [
            'ok'                 => true,
            'analyzed_at'        => $now,
            'killzone'           => $kz,
            'macro'              => $macro,
            'breaker_blocks'     => $breakers,
            'mitigation_blocks'  => $mitigations,
            'inverse_fvgs'       => $ifvgs,
            'ote'                => $ote,
            'silver_bullet'      => $silverBullet,
            'model_2022'         => $model2022,
            'unicorns'           => $unicorns,
            'turtle_soup'        => $turtleSoup,
            'judas_swing'        => $judas,
            'power_of_three'     => $amd,
            'smt_divergence'     => $smt,
            'detected_strategies'=> $detectedStrategies,
        ];
    }

    // ------------------------------------------------------------------
    // A. ICT Kill Zones & Macro Time Windows (New York Time Base)
    // ------------------------------------------------------------------

    public static function nyTime(int $ts): array
    {
        $dt = new \DateTime('@' . $ts);
        $dt->setTimezone(new \DateTimeZone('America/New_York'));
        return [
            'hour'   => (int)$dt->format('G'), // 0..23
            'minute' => (int)$dt->format('i'),
            'time'   => $dt->format('H:i'),
            'date'   => $dt->format('Y-m-d'),
        ];
    }

    public static function killzones(int $ts): array
    {
        $ny = self::nyTime($ts);
        $h = $ny['hour'];
        $m = $ny['minute'];
        $mins = $h * 60 + $m;

        // Asian Kill Zone: 20:00 - 00:00 NY (1200 - 1440 mins)
        $isAsian = ($mins >= 20 * 60);

        // London Open Kill Zone: 02:00 - 05:00 NY (120 - 300 mins)
        $isLondon = ($mins >= 2 * 60 && $mins < 5 * 60);

        // New York AM Kill Zone: 07:00 - 10:00 NY (420 - 600 mins)
        $isNyAm = ($mins >= 7 * 60 && $mins < 10 * 60);

        // London Close Kill Zone: 10:00 - 12:00 NY (600 - 720 mins)
        $isLondonClose = ($mins >= 10 * 60 && $mins < 12 * 60);

        // New York PM Kill Zone: 13:30 - 16:00 NY (810 - 960 mins)
        $isNyPm = ($mins >= 13 * 60 + 30 && $mins < 16 * 60);

        $active = null;
        if ($isLondon) $active = 'London Open Killzone';
        elseif ($isNyAm) $active = 'New York AM Killzone';
        elseif ($isLondonClose) $active = 'London Close Killzone';
        elseif ($isNyPm) $active = 'New York PM Killzone';
        elseif ($isAsian) $active = 'Asian Killzone';

        return [
            'ny_time'          => $ny['time'],
            'active'           => $active,
            'active_name'      => $active,
            'is_killzone'      => $active !== null,
            'in_killzone'      => $active !== null,
            'asian'            => $isAsian,
            'london_open'      => $isLondon,
            'ny_am'            => $isNyAm,
            'london_close'     => $isLondonClose,
            'ny_pm'            => $isNyPm,
            'silver_bullet_hour'=> ($mins >= 3 * 60 && $mins < 4 * 60) ? 'London (03:00-04:00 NY)'
                : (($mins >= 10 * 60 && $mins < 11 * 60) ? 'NY AM (10:00-11:00 NY)'
                : (($mins >= 14 * 60 && $mins < 15 * 60) ? 'NY PM (14:00-15:00 NY)' : null)),
        ];
    }

    public static function macroWindows(int $ts): array
    {
        $ny = self::nyTime($ts);
        $h = $ny['hour'];
        $m = $ny['minute'];
        $mins = $h * 60 + $m;

        // ICT 20-min Macros (in NY time minutes)
        $macros = [
            'London Macro 02:50 - 03:10' => [2 * 60 + 50, 3 * 60 + 10],
            'NY AM Macro 08:50 - 09:10'  => [8 * 60 + 50, 9 * 60 + 10],
            'NY AM Macro 09:50 - 10:10'  => [9 * 60 + 50, 10 * 60 + 10],
            'NY AM Macro 10:50 - 11:10'  => [10 * 60 + 50, 11 * 60 + 10],
            'NY PM Macro 11:50 - 12:10'  => [11 * 60 + 50, 12 * 60 + 10],
            'NY PM Macro 13:50 - 14:10'  => [13 * 60 + 50, 14 * 60 + 10],
            'NY PM Macro 15:10 - 15:30'  => [15 * 60 + 10, 15 * 60 + 30],
        ];

        $current = null;
        foreach ($macros as $lbl => [$start, $end]) {
            if ($mins >= $start && $mins <= $end) {
                $current = $lbl;
                break;
            }
        }

        return [
            'is_macro_window' => $current !== null,
            'active_macro'    => $current,
            'ny_time'         => $ny['time'],
        ];
    }

    // ------------------------------------------------------------------
    // B. Breaker Blocks & Mitigation Blocks
    // ------------------------------------------------------------------

    /**
     * Breaker Block: Order block that was broken AFTER taking liquidity.
     * Bearish Breaker: Prior high took liquidity, then market broke down past intermediate low.
     * The last bullish candle (OB) of that upward leg now acts as resistance.
     * Bullish Breaker: Prior low took liquidity, then market broke up past intermediate high.
     * The last bearish candle (OB) of that downward leg now acts as support.
     */
    public function breakerBlocks(array $c, array $swings, array $events, array $obs): array
    {
        $n = count($c);
        $breakers = [];

        foreach ($obs as $ob) {
            // A breaker must have been invalidated (i.e. broken through by closed candle)
            if ($ob['status'] !== 'invalidated') {
                continue;
            }
            $obI = $ob['i'];
            $obDir = $ob['dir']; // 1 = bullish OB (up candle), -1 = bearish OB
            $breakerDir = -$obDir; // Flips polarity: broken bullish OB becomes bearish breaker (-1)

            // Look for structure break that invalidated it
            $hasBreak = false;
            foreach ($events as $e) {
                if ($e['i'] > $obI && $e['dir'] === $breakerDir) {
                    $hasBreak = true;
                    break;
                }
            }
            if (!$hasBreak) {
                continue;
            }

            // Check if current price is retesting the breaker zone
            $top = $ob['top'];
            $bottom = $ob['bottom'];
            $status = 'fresh';
            $retested = false;

            for ($k = $obI + 1; $k < $n; $k++) {
                if ($breakerDir === 1) { // Bullish breaker: support
                    if ($c[$k]['l'] <= $top && $c[$k]['c'] >= $bottom) {
                        $retested = true;
                        $status = 'retested';
                    }
                    if ($c[$k]['c'] < $bottom) {
                        $status = 'invalidated';
                    }
                } else { // Bearish breaker: resistance
                    if ($c[$k]['h'] >= $bottom && $c[$k]['c'] <= $top) {
                        $retested = true;
                        $status = 'retested';
                    }
                    if ($c[$k]['c'] > $top) {
                        $status = 'invalidated';
                    }
                }
            }

            if ($status !== 'invalidated') {
                $breakers[] = [
                    'dir'         => $breakerDir,
                    'dir_label'   => $breakerDir === 1 ? 'bullish' : 'bearish',
                    'top'         => $top,
                    'bottom'      => $bottom,
                    'mid'         => round(($top + $bottom) / 2, 3),
                    'formed_i'    => $obI,
                    'formed_t'    => $ob['t'],
                    'status'      => $status,
                    'retested'    => $retested,
                    'type'        => 'Breaker Block',
                    'original_ob' => $ob,
                ];
            }
        }

        return $breakers;
    }

    /**
     * Mitigation Block: Like a breaker block, but does not take liquidity prior to the break (failure swing).
     */
    public function mitigationBlocks(array $c, array $swings, array $events, array $obs): array
    {
        $n = count($c);
        $mitigations = [];

        foreach ($obs as $ob) {
            if ($ob['status'] !== 'invalidated') {
                continue;
            }
            $breakerDir = -$ob['dir'];
            $top = $ob['top'];
            $bottom = $ob['bottom'];
            $status = 'fresh';

            for ($k = $ob['i'] + 1; $k < $n; $k++) {
                if ($breakerDir === 1 && $c[$k]['c'] < $bottom) {
                    $status = 'invalidated';
                    break;
                }
                if ($breakerDir === -1 && $c[$k]['c'] > $top) {
                    $status = 'invalidated';
                    break;
                }
            }

            if ($status !== 'invalidated') {
                $mitigations[] = [
                    'dir'       => $breakerDir,
                    'dir_label' => $breakerDir === 1 ? 'bullish' : 'bearish',
                    'top'       => $top,
                    'bottom'    => $bottom,
                    'mid'       => round(($top + $bottom) / 2, 3),
                    'formed_t'  => $ob['t'],
                    'status'    => $status,
                    'type'      => 'Mitigation Block',
                ];
            }
        }
        return $mitigations;
    }

    // ------------------------------------------------------------------
    // C. Inverse Fair Value Gaps (IFVG)
    // ------------------------------------------------------------------

    /**
     * When an FVG is completely closed through, it inverts polarity:
     * Bullish FVG closed below by body -> Bearish IFVG resistance.
     * Bearish FVG closed above by body -> Bullish IFVG support.
     */
    public function inverseFvgs(array $c, array $fvgs): array
    {
        $n = count($c);
        $ifvgs = [];

        foreach ($fvgs as $f) {
            if ($f['status'] !== 'invalidated') {
                continue;
            }
            // Polarity inverts
            $ifvgDir = -$f['dir'];
            $top = $f['top'];
            $bottom = $f['bottom'];
            $status = 'fresh';
            $retested = false;

            for ($k = $f['i'] + 2; $k < $n; $k++) {
                if ($ifvgDir === 1) { // Bullish IFVG: acts as support
                    if ($c[$k]['l'] <= $top && $c[$k]['c'] >= $bottom) {
                        $retested = true;
                        $status = 'retested';
                    }
                    if ($c[$k]['c'] < $bottom) {
                        $status = 'invalidated';
                    }
                } else { // Bearish IFVG: acts as resistance
                    if ($c[$k]['h'] >= $bottom && $c[$k]['c'] <= $top) {
                        $retested = true;
                        $status = 'retested';
                    }
                    if ($c[$k]['c'] > $top) {
                        $status = 'invalidated';
                    }
                }
            }

            if ($status !== 'invalidated') {
                $ifvgs[] = [
                    'dir'          => $ifvgDir,
                    'dir_label'    => $ifvgDir === 1 ? 'bullish' : 'bearish',
                    'top'          => $top,
                    'bottom'       => $bottom,
                    'mid'          => round(($top + $bottom) / 2, 3),
                    'formed_t'     => $f['t'],
                    'status'       => $status,
                    'retested'     => $retested,
                    'type'         => 'Inverse FVG (IFVG)',
                    'original_fvg' => $f,
                ];
            }
        }

        return $ifvgs;
    }

    // ------------------------------------------------------------------
    // D. Optimal Trade Entry (OTE - 62% - 79% Fibonacci)
    // ------------------------------------------------------------------

    public function optimalTradeEntry(array $c, array $events, ?array $pd): array
    {
        $n = count($c);
        $curPrice = $c[$n - 1]['c'];
        if (!$events) {
            return ['ok' => false, 'in_zone' => false];
        }

        $lastEvt = end($events);
        $dir = $lastEvt['dir'];
        $isBuy = ($dir === 1);

        // Find high and low of the impulse leg
        $legStart = $lastEvt['leg_start'];
        $hi = $c[$legStart]['h'];
        $lo = $c[$legStart]['l'];

        for ($k = $legStart; $k < $n; $k++) {
            $hi = max($hi, $c[$k]['h']);
            $lo = min($lo, $c[$k]['l']);
        }
        $range = $hi - $lo;
        if ($range <= 0.001) {
            return ['ok' => false, 'in_zone' => false];
        }

        if ($isBuy) {
            // Retracement downwards into discount
            $fib50  = $hi - 0.500 * $range;
            $fib618 = $hi - 0.618 * $range;
            $fib705 = $hi - 0.705 * $range;
            $fib786 = $hi - 0.786 * $range;
            $zoneTop = $fib618;
            $zoneBottom = $fib786;
            $inZone = ($curPrice <= $zoneTop && $curPrice >= $zoneBottom);
            $sl = $lo;
        } else {
            // Retracement upwards into premium
            $fib50  = $lo + 0.500 * $range;
            $fib618 = $lo + 0.618 * $range;
            $fib705 = $lo + 0.705 * $range;
            $fib786 = $lo + 0.786 * $range;
            $zoneTop = $fib786;
            $zoneBottom = $fib618;
            $inZone = ($curPrice >= $zoneBottom && $curPrice <= $zoneTop);
            $sl = $hi;
        }

        return [
            'ok'          => true,
            'direction'   => $isBuy ? 'BUY' : 'SELL',
            'in_zone'     => $inZone,
            'impulse_high'=> round($hi, 3),
            'impulse_low' => round($lo, 3),
            'fib_50'      => round($fib50, 3),
            'fib_618'     => round($fib618, 3),
            'fib_705'     => round($fib705, 3), // sweet spot
            'fib_786'     => round($fib786, 3),
            'zone_low'    => round(min($zoneTop, $zoneBottom), 3),
            'zone_high'   => round(max($zoneTop, $zoneBottom), 3),
            'sl'          => round($sl, 3),
        ];
    }

    // ------------------------------------------------------------------
    // E. ICT 2022 Mentorship Model
    // ------------------------------------------------------------------

    public function detect2022Model(array $c, array $sweeps, array $events, array $fvgs, float $atr): array
    {
        $n = count($c);
        $curPrice = $c[$n - 1]['c'];

        if (!$sweeps || !$events) {
            return ['active' => false];
        }

        $lastSweep = end($sweeps);
        $sweepI = $lastSweep['i'];
        $dir = $lastSweep['dir']; // Reaction direction (1 for SSL sweep, -1 for BSL sweep)
        $isBuy = ($dir === 1);

        // Must have a structure break (MSS) following the sweep
        $mss = null;
        foreach ($events as $e) {
            if ($e['i'] >= $sweepI && $e['dir'] === $dir && ($e['mss'] || $e['displacement'])) {
                $mss = $e;
                break;
            }
        }
        if (!$mss) {
            return ['active' => false];
        }

        // Must have an FVG formed during the displacement move
        $fvg = null;
        foreach ($fvgs as $f) {
            if ($f['dir'] === $dir && $f['i'] >= $sweepI && in_array($f['status'], ['fresh', 'partial'], true)) {
                $fvg = $f;
            }
        }
        if (!$fvg) {
            return ['active' => false];
        }

        $entry = round(($fvg['top'] + $fvg['bottom']) / 2, 3);
        $sl = $isBuy ? round($lastSweep['extreme'] - 0.15 * $atr, 3) : round($lastSweep['extreme'] + 0.15 * $atr, 3);
        $risk = abs($entry - $sl);
        $tp1 = $isBuy ? round($entry + 2.0 * $risk, 3) : round($entry - 2.0 * $risk, 3);

        return [
            'active'      => true,
            'direction'   => $isBuy ? 'BUY' : 'SELL',
            'sweep_level' => $lastSweep['level'],
            'sweep_type'  => $lastSweep['type'],
            'mss_level'   => $mss['level'],
            'entry_zone'  => ['top' => $fvg['top'], 'bottom' => $fvg['bottom'], 'mid' => $entry],
            'sl'          => $sl,
            'tp1'         => $tp1,
            'rr'          => 2.0,
            'fvg_t'       => $fvg['t'],
        ];
    }

    // ------------------------------------------------------------------
    // F. ICT Unicorn Model (Breaker + FVG Overlap)
    // ------------------------------------------------------------------

    public function detectUnicorn(array $breakers, array $fvgs): array
    {
        $unicorns = [];
        foreach ($breakers as $b) {
            foreach ($fvgs as $f) {
                if ($b['dir'] === $f['dir'] && in_array($f['status'], ['fresh', 'partial'], true)) {
                    // Check zone overlap
                    $overlapTop = min($b['top'], $f['top']);
                    $overlapBottom = max($b['bottom'], $f['bottom']);
                    if ($overlapTop > $overlapBottom) {
                        $unicorns[] = [
                            'dir'       => $b['dir'],
                            'direction' => $b['dir'] === 1 ? 'BUY' : 'SELL',
                            'top'       => round($overlapTop, 3),
                            'bottom'    => round($overlapBottom, 3),
                            'mid'       => round(($overlapTop + $overlapBottom) / 2, 3),
                            'breaker'   => $b,
                            'fvg'       => $f,
                            'status'    => 'active',
                            't'         => max($b['formed_t'], $f['t']),
                            'type'      => 'ICT Unicorn Model (Breaker + FVG)',
                        ];
                    }
                }
            }
        }
        return $unicorns;
    }

    // ------------------------------------------------------------------
    // G. ICT Silver Bullet
    // ------------------------------------------------------------------

    public function detectSilverBullet(array $c, array $fvgs, array $events, array $sweeps, int $now, float $atr): array
    {
        $n = count($c);
        $kz = self::killzones($now);
        $sbWindow = $kz['silver_bullet_hour'];
        if (!$sbWindow) {
            return ['active' => false, 'window' => null];
        }

        // Inside the Silver Bullet hour: look for fresh displacement FVG
        $fvg = null;
        foreach (array_reverse($fvgs) as $f) {
            if (in_array($f['status'], ['fresh', 'partial'], true) && ($n - 1 - $f['i']) <= 6) {
                $fvg = $f;
                break;
            }
        }
        if (!$fvg) {
            return ['active' => false, 'window' => $sbWindow];
        }

        $dir = $fvg['dir'];
        $isBuy = ($dir === 1);
        $entry = round(($fvg['top'] + $fvg['bottom']) / 2, 3);
        $sl = $isBuy ? round($fvg['bottom'] - 0.25 * $atr, 3) : round($fvg['top'] + 0.25 * $atr, 3);
        $targetPts = (float)$this->cfg['sb_target_handles'];
        $tp1 = $isBuy ? round($entry + $targetPts, 3) : round($entry - $targetPts, 3);
        $risk = max(0.1, abs($entry - $sl));

        return [
            'active'     => true,
            'window'     => $sbWindow,
            'direction'  => $isBuy ? 'BUY' : 'SELL',
            'entry_zone' => ['top' => $fvg['top'], 'bottom' => $fvg['bottom'], 'mid' => $entry],
            'sl'         => $sl,
            'tp1'        => $tp1,
            'rr'         => round(abs($tp1 - $entry) / $risk, 2),
            'target_handles' => $targetPts,
        ];
    }

    // ------------------------------------------------------------------
    // H. ICT Turtle Soup (False Breakout beyond 20-candle extreme)
    // ------------------------------------------------------------------

    public function detectTurtleSoup(array $c, array $swings, array $atrArray): array
    {
        $n = count($c);
        if ($n < 25) {
            return ['active' => false];
        }
        $last = $c[$n - 1];
        $prev = $c[$n - 2];
        $atr = $atrArray[$n - 1] ?? 1.0;

        // Check 20-candle high and low prior to last 2 candles
        $lookback = (int)$this->cfg['turtle_soup_lookback'];
        $hi = $c[$n - 3]['h'];
        $lo = $c[$n - 3]['l'];
        for ($k = max(0, $n - 3 - $lookback); $k <= $n - 3; $k++) {
            $hi = max($hi, $c[$k]['h']);
            $lo = min($lo, $c[$k]['l']);
        }

        // Bullish Turtle Soup: price pierces below $lo, but closes back above $lo
        if ($prev['l'] < $lo && $prev['c'] > $lo) {
            $sl = round($prev['l'] - 0.10 * $atr, 3);
            $entry = $last['c'];
            $risk = max(0.1, abs($entry - $sl));
            return [
                'active'     => true,
                'direction'  => 'BUY',
                'swept_low'  => $lo,
                'entry'      => $entry,
                'sl'         => $sl,
                'tp1'        => round($entry + 2.0 * $risk, 3),
                'type'       => 'ICT Turtle Soup (Sell-Side Sweep)',
            ];
        }

        // Bearish Turtle Soup: price pierces above $hi, but closes back below $hi
        if ($prev['h'] > $hi && $prev['c'] < $hi) {
            $sl = round($prev['h'] + 0.10 * $atr, 3);
            $entry = $last['c'];
            $risk = max(0.1, abs($entry - $sl));
            return [
                'active'     => true,
                'direction'  => 'SELL',
                'swept_high' => $hi,
                'entry'      => $entry,
                'sl'         => $sl,
                'tp1'        => round($entry - 2.0 * $risk, 3),
                'type'       => 'ICT Turtle Soup (Buy-Side Sweep)',
            ];
        }

        return ['active' => false];
    }

    // ------------------------------------------------------------------
    // I. ICT Judas Swing
    // ------------------------------------------------------------------

    public function detectJudasSwing(array $c, array $swings, int $now): array
    {
        $ny = self::nyTime($now);
        $h = $ny['hour'];
        $m = $ny['minute'];
        $n = count($c);
        if ($n < 10) return ['detected' => false];

        // Only active during first 45 mins of London open (02:00 - 02:45 NY) or NY open (07:00 - 07:45 NY)
        $isLondonOpen = ($h === 2 && $m <= 45);
        $isNyOpen = ($h === 7 && $m <= 45);

        if (!$isLondonOpen && !$isNyOpen) {
            return ['detected' => false];
        }

        $sessionName = $isLondonOpen ? 'London Open' : 'New York Open';
        $last = $c[$n - 1];
        $prev = $c[$n - 2];

        // False pump with immediate bearish rejection
        if ($prev['h'] > $prev['o'] && $last['c'] < $prev['l']) {
            return [
                'detected'    => true,
                'direction'   => 'SELL',
                'session'     => $sessionName,
                'fakeout_high'=> $prev['h'],
                'entry'       => $last['c'],
                'description' => $sessionName . ' Judas Swing: false upside spike followed by bearish breakdown.',
            ];
        }
        // False dump with immediate bullish recovery
        if ($prev['l'] < $prev['o'] && $last['c'] > $prev['h']) {
            return [
                'detected'    => true,
                'direction'   => 'BUY',
                'session'     => $sessionName,
                'fakeout_low' => $prev['l'],
                'entry'       => $last['c'],
                'description' => $sessionName . ' Judas Swing: false downside spike followed by bullish displacement.',
            ];
        }

        return ['detected' => false];
    }

    // ------------------------------------------------------------------
    // J. ICT Power of Three (AMD)
    // ------------------------------------------------------------------

    public function detectAmd(array $c, int $trend): array
    {
        $n = count($c);
        if ($n < 30) return ['ok' => false];

        // Look at last 24 candles (approx 6 hours on M15)
        $slice = array_slice($c, -24);
        $o = $slice[0]['o'];
        $h = max(array_column($slice, 'h'));
        $l = min(array_column($slice, 'l'));
        $cLast = end($slice)['c'];

        // Measure range expansion vs initial open
        $upperWick = $h - max($o, $cLast);
        $lowerWick = min($o, $cLast) - $l;
        $body = abs($cLast - $o);

        $phase = 'Accumulation';
        if ($body > 1.5 * ($upperWick + $lowerWick)) {
            $phase = 'Distribution';
        } elseif ($upperWick > 1.5 * $body || $lowerWick > 1.5 * $body) {
            $phase = 'Manipulation (Judas/Sweep)';
        }

        return [
            'ok'             => true,
            'current_phase'  => $phase,
            'cycle_open'     => $o,
            'cycle_high'     => $h,
            'cycle_low'      => $l,
            'favored_bias'   => $trend === 1 ? 'Bullish Expansion' : ($trend === -1 ? 'Bearish Expansion' : 'Neutral Consolidation'),
        ];
    }

    // ------------------------------------------------------------------
    // K. ICT SMT Divergence (Smart Money Tool)
    // ------------------------------------------------------------------

    public function detectSmt(array $cPrimary, array $cCorrelated, string $sym1, string $sym2): array
    {
        $n1 = count($cPrimary);
        $n2 = count($cCorrelated);
        if ($n1 < 20 || $n2 < 20) return ['divergence' => false];

        // Check last 2 local swing highs / lows
        $p1LastH = max($cPrimary[$n1 - 1]['h'], $cPrimary[$n1 - 2]['h']);
        $p1PrevH = max($cPrimary[$n1 - 5]['h'], $cPrimary[$n1 - 6]['h']);

        $p2LastH = max($cCorrelated[$n2 - 1]['h'], $cCorrelated[$n2 - 2]['h']);
        $p2PrevH = max($cCorrelated[$n2 - 5]['h'], $cCorrelated[$n2 - 6]['h']);

        // Bearish SMT: Primary makes Higher High, Correlated makes Lower High
        if ($p1LastH > $p1PrevH && $p2LastH < $p2PrevH) {
            return [
                'divergence'   => true,
                'type'         => 'Bearish SMT Divergence',
                'direction'    => 'SELL',
                'explanation'  => sprintf('%s reached a higher high while %s printed a lower high (smart money distribution divergence).', $sym1, $sym2),
                'primary'      => $sym1,
                'correlated'   => $sym2,
            ];
        }

        // Bullish SMT: Primary makes Lower Low, Correlated makes Higher Low
        $p1LastL = min($cPrimary[$n1 - 1]['l'], $cPrimary[$n1 - 2]['l']);
        $p1PrevL = min($cPrimary[$n1 - 5]['l'], $cPrimary[$n1 - 6]['l']);

        $p2LastL = min($cCorrelated[$n2 - 1]['l'], $cCorrelated[$n2 - 2]['l']);
        $p2PrevL = min($cCorrelated[$n2 - 5]['l'], $cCorrelated[$n2 - 6]['l']);

        if ($p1LastL < $p1PrevL && $p2LastL > $p2PrevL) {
            return [
                'divergence'   => true,
                'type'         => 'Bullish SMT Divergence',
                'direction'    => 'BUY',
                'explanation'  => sprintf('%s printed a lower low while %s held a higher low (smart money accumulation divergence).', $sym1, $sym2),
                'primary'      => $sym1,
                'correlated'   => $sym2,
            ];
        }

        return ['divergence' => false];
    }
}
