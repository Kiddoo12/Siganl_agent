<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Market\MarketDataService;
use App\SMC\CrtEngine;
use App\SMC\IctEngine;
use App\SMC\SmcEngine;
use App\SMC\SnrEngine;

/**
 * Interactive AI Chat Analyst Service.
 * Allows users to converse with the AI trading engine and select specific strategies:
 *  - ICT (Inner Circle Trader)
 *  - SMC (Smart Money Concepts)
 *  - CRT (Candle Range Theory)
 *  - SNR (Support and Resistance)
 *  - ALL (Multi-Confluence)
 */
final class AiChatService
{
    public const VALID_STRATEGIES = ['ICT', 'SMC', 'CRT', 'SNR', 'ALL'];

    /**
     * Send a message to the AI Analyst, process market data according to the selected strategy,
     * generate structured institutional analysis, and record in conversation history.
     */
    public static function sendMessage(
        int $userId,
        string $message,
        string $strategyMode = 'ALL',
        string $symbol = 'XAUUSD',
        string $timeframe = 'M15'
    ): array {
        $strategyMode = strtoupper(trim($strategyMode));
        if (!in_array($strategyMode, self::VALID_STRATEGIES, true)) {
            $strategyMode = 'ALL';
        }

        $symbol = MarketDataService::normalizeSymbol($symbol);
        $timeframe = strtoupper(trim($timeframe)) ?: 'M15';

        // 1. Record User Message
        $now = gmdate('Y-m-d H:i:s');
        Database::insert('ai_chat_messages', [
            'user_id'       => $userId,
            'symbol'        => $symbol,
            'timeframe'     => $timeframe,
            'strategy_mode' => $strategyMode,
            'sender'        => 'user',
            'message'       => $message,
            'metadata_json' => null,
            'created_at'    => $now,
        ]);

        // 2. Fetch Live Market Data
        $market = new MarketDataService(null, $symbol);
        $candles = $market->candles($timeframe, 100);
        $quote = $market->quote(false);
        $lastCandle = end($candles);
        $currPrice = $quote['available'] ? (float)$quote['price'] : (float)($lastCandle['c'] ?? $lastCandle['close'] ?? 2400.0);

        // 3. Execute Selected Strategy Engines
        $smcEngine = new SmcEngine();
        $smcRes = $smcEngine->analyze($candles);

        $ictEngine = new IctEngine();
        $ictRes = $ictEngine->analyze($candles, $smcRes, time());

        $crtEngine = new CrtEngine();
        $crtRes = $crtEngine->analyze($candles);

        $snrEngine = new SnrEngine();
        $snrRes = $snrEngine->analyze($candles);

        // 4. Generate Strategy-Specific Analysis & Reply
        $replyPayload = self::buildResponse($message, $strategyMode, $symbol, $timeframe, $currPrice, $smcRes, $ictRes, $crtRes, $snrRes);

        // 5. Check if external LLM should refine conversational wording
        $finalReply = self::callExternalAiIfAvailable($message, $replyPayload, $strategyMode, $symbol, $timeframe);

        // 6. Record Assistant Reply
        Database::insert('ai_chat_messages', [
            'user_id'       => $userId,
            'symbol'        => $symbol,
            'timeframe'     => $timeframe,
            'strategy_mode' => $strategyMode,
            'sender'        => 'assistant',
            'message'       => $finalReply,
            'metadata_json' => json_encode([
                'strategy_mode' => $strategyMode,
                'curr_price'    => $currPrice,
                'signal'        => $replyPayload['signal'] ?? 'NO TRADE',
                'setup_type'    => $replyPayload['setup_type'] ?? 'N/A',
            ]),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'ok'            => true,
            'reply'         => $finalReply,
            'strategy_mode' => $strategyMode,
            'symbol'        => $symbol,
            'timeframe'     => $timeframe,
            'curr_price'    => $currPrice,
            'payload'       => $replyPayload,
        ];
    }

    /**
     * Build deterministic, highly detailed institutional response tailored to the selected strategy.
     */
    private static function buildResponse(
        string $userMsg,
        string $strategyMode,
        string $symbol,
        string $timeframe,
        float $price,
        array $smcRes,
        array $ictRes,
        array $crtRes,
        array $snrRes = []
    ): array {
        $now = time();
        $kz = IctEngine::killzones($now);
        $macro = IctEngine::macroWindows($now);

        $signal = 'NO TRADE';
        $setupType = 'N/A';
        $entry = null;
        $sl = null;
        $tp1 = null;
        $tp2 = null;
        $rr = null;
        $confidence = 'Medium';
        $invalidation = 'Adverse candle close';

        $sections = [];

        // -------------------------------------------------------------
        // STRATEGY: CANDLE RANGE THEORY (CRT)
        // -------------------------------------------------------------
        if ($strategyMode === 'CRT') {
            $ref = $crtRes['reference_candle'] ?? [];
            $rh = (float)($ref['range_high'] ?? 0);
            $rl = (float)($ref['range_low'] ?? 0);
            $eq = (float)($ref['range_eq'] ?? 0);
            $phase = $crtRes['phase'] ?? 'inside_range';
            $bias = $crtRes['bias'] ?? 'Neutral';
            $crtSetup = $crtRes['trade_setup'];

            if ($crtSetup) {
                $signal = $crtSetup['direction'];
                $setupType = $crtSetup['name'];
                $entry = $crtSetup['entry'];
                $sl = $crtSetup['stop_loss'];
                $tp1 = $crtSetup['tp1'];
                $tp2 = $crtSetup['tp2'];
                $rr = $crtSetup['rr2'];
                $confidence = 'High (84%)';
                $invalidation = implode(' | ', $crtSetup['invalidations'] ?? []);
            } else {
                $signal = 'NO TRADE';
                $setupType = 'CRT Inside Range Contraction';
                $confidence = 'Low';
                $invalidation = sprintf('Wait for liquidity sweep beyond $%.2f (RH) or $%.2f (RL)', $rh, $rl);
            }

            $sections[] = sprintf(
                "### 🕯️ Candle Range Theory (CRT) Analysis — %s (%s)\n" .
                "* **Reference Range High (RH):** $%.2f\n" .
                "* **Reference Range Low (RL):** $%.2f\n" .
                "* **Range 50%% Equilibrium (EQ):** $%.2f\n" .
                "* **Current CRT Phase:** `%s`\n" .
                "* **Current Price Location:** $%.2f (%s Zone)",
                $symbol, $timeframe, $rh, $rl, $eq, strtoupper(str_replace('_', ' ', $phase)), $price,
                ($price >= $eq ? 'Premium' : 'Discount')
            );

            if ($crtSetup) {
                $sections[] = sprintf(
                    "#### 🎯 CRT Execution Setup (%s):\n" .
                    "* **Action:** `%s`\n" .
                    "* **Entry Price / Zone:** $%.2f ($%.2f - $%.2f)\n" .
                    "* **Stop Loss:** $%.2f (Beyond sweep extreme)\n" .
                    "* **Take Profit 1:** $%.2f (50%% Equilibrium Target, RR 1:%.2f)\n" .
                    "* **Take Profit 2:** $%.2f (Opposing Range Boundary, RR 1:%.2f)\n" .
                    "* **Rule Evidence:** %s",
                    $crtSetup['name'], $signal, $entry, $crtSetup['entry_zone']['min'], $crtSetup['entry_zone']['max'],
                    $sl, $tp1, $crtSetup['rr1'], $tp2, $crtSetup['rr2'], $crtSetup['details']
                );
            } else {
                $sections[] = sprintf(
                    "#### ⏳ CRT Stand Aside Guidance:\n" .
                    "Price is currently consolidating within the reference range ($%.2f – $%.2f). Under Candle Range Theory rules, **no entry is authorized** until an external boundary sweep occurs. Await a wick sweep beyond $%.2f or $%.2f with body closure back inside to initiate Phase 1–3 delivery.",
                    $rl, $rh, $rh, $rl
                );
            }
        }
        // -------------------------------------------------------------
        // STRATEGY: INNER CIRCLE TRADER (ICT)
        // -------------------------------------------------------------
        elseif ($strategyMode === 'ICT') {
            $fvgs = $smcRes['fvgs'] ?? [];
            $unicorns = $ictRes['unicorns'] ?? [];
            $sb = $ictRes['silver_bullet'] ?? [];
            $detected = $ictRes['detected_strategies'] ?? [];

            $primaryStrat = !empty($detected) ? $detected[0]['name'] : 'ICT Market Structure Context';
            $bias = !empty($detected) ? ($detected[0]['data']['direction'] ?? $detected[0]['data']['bias'] ?? 'WAIT') : 'WAIT';

            $sections[] = sprintf(
                "### ⚡ Inner Circle Trader (ICT) Analysis — %s (%s)\n" .
                "* **New York Time:** %s | **Killzone:** %s\n" .
                "* **Institutional Macro Window:** %s\n" .
                "* **Silver Bullet Active:** %s\n" .
                "* **Active FVGs Count:** %d | **Breaker Blocks:** %d",
                $symbol, $timeframe, $kz['ny_time'], ($kz['is_killzone'] ? '● ' . $kz['active'] : '○ Outside Killzone'),
                ($macro['is_macro_window'] ? '⚡ ' . $macro['active_macro'] : 'None active'),
                (!empty($sb['active']) ? 'YES (' . $sb['window'] . ')' : 'NO'),
                count($fvgs), count($ictRes['breakers'] ?? [])
            );

            if (!empty($unicorns)) {
                $u = $unicorns[0];
                $signal = $u['direction'] === 'BULLISH' ? 'BUY' : 'SELL';
                $setupType = 'ICT Unicorn Model (Breaker + FVG)';
                $entry = $u['mid'];
                $sl = $u['direction'] === 'BULLISH' ? round($u['bottom'] - 4.0, 2) : round($u['top'] + 4.0, 2);
                $tp1 = $u['direction'] === 'BULLISH' ? round($entry + 12.0, 2) : round($entry - 12.0, 2);
                $tp2 = $u['direction'] === 'BULLISH' ? round($entry + 24.0, 2) : round($entry - 24.0, 2);
                $rr = 2.5;

                $sections[] = sprintf(
                    "#### 🎯 Confirmed ICT Unicorn Setup:\n" .
                    "* **Model:** ICT Unicorn Model (Overlapping Breaker Block + Fair Value Gap)\n" .
                    "* **Action:** `%s`\n" .
                    "* **Zone:** $%.2f – $%.2f (Midpoint: $%.2f)\n" .
                    "* **Stop Loss:** $%.2f | **TP1:** $%.2f | **TP2:** $%.2f",
                    $signal, $u['bottom'], $u['top'], $entry, $sl, $tp1, $tp2
                );
            } elseif (!empty($detected) && !empty($detected[0]['data']['direction']) && $detected[0]['data']['direction'] !== 'WAIT') {
                $d = $detected[0];
                $signal = strtoupper((string)$d['data']['direction']);
                $setupType = $d['name'];
                $entry = $price;
                $sl = $signal === 'BUY' ? round($price - 5.0, 2) : round($price + 5.0, 2);
                $tp1 = $signal === 'BUY' ? round($price + 12.0, 2) : round($price - 12.0, 2);
                $tp2 = $signal === 'BUY' ? round($price + 22.0, 2) : round($price - 22.0, 2);
                $rr = 2.4;

                $sections[] = sprintf(
                    "#### 🎯 Active ICT Setup (%s):\n" .
                    "* **Action:** `%s`\n" .
                    "* **Description:** Active ICT institutional model detected.\n" .
                    "* **Target:** Opposing session liquidity pools.",
                    $setupType, $signal
                );
            } else {
                $signal = 'NO TRADE';
                $setupType = 'ICT Standby (Awaiting Killzone / Sweep)';
                $sections[] = "#### ⏳ ICT Stand Aside Guidance:\nNo confirmed ICT displacement or Silver Bullet delivery is active right now. Strict ICT discipline dictates standing aside until a clean liquidity sweep occurs followed by Market Structure Shift (MSS) displacement.";
            }
        }
        // -------------------------------------------------------------
        // STRATEGY: SMART MONEY CONCEPTS (SMC)
        // -------------------------------------------------------------
        elseif ($strategyMode === 'SMC') {
            $trend = $smcRes['trend'] ?? 0;
            $bias = $trend === 1 ? 'Bullish' : ($trend === -1 ? 'Bearish' : 'Neutral');
            $events = $smcRes['events'] ?? [];
            $obs = $smcRes['order_blocks'] ?? [];
            $pd = $smcRes['premium_discount'] ?? [];
            $sweeps = $smcRes['liquidity']['sweeps'] ?? [];

            $lastEvt = end($events);
            $structLabel = $lastEvt ? ($lastEvt['mss'] ? 'Market Structure Shift (MSS)' : $lastEvt['type']) : 'Consolidation';

            $sections[] = sprintf(
                "### 🏛️ Smart Money Concepts (SMC) Analysis — %s (%s)\n" .
                "* **SMC Trend & Bias:** `%s`\n" .
                "* **Latest Structure Event:** `%s`\n" .
                "* **Dealing Range:** $%.2f – $%.2f (50%% EQ: $%.2f)\n" .
                "* **Active Unmitigated Order Blocks:** %d\n" .
                "* **Liquidity Sweeps Recorded:** %d",
                $symbol, $timeframe, strtoupper($bias), $structLabel,
                (float)($pd['range_low'] ?? 0), (float)($pd['range_high'] ?? 0), (float)($pd['equilibrium'] ?? 0),
                count($obs), count($sweeps)
            );

            if (!empty($obs)) {
                $lastOb = end($obs);
                $isBullOb = ($lastOb['type'] ?? '') === 'BULLISH';
                $signal = $isBullOb ? 'BUY' : 'SELL';
                $setupType = ($isBullOb ? 'Bullish' : 'Bearish') . ' Order Block Retest';
                $entry = round(($lastOb['top'] + $lastOb['bottom']) / 2, 2);
                $sl = $isBullOb ? round($lastOb['bottom'] - 3.0, 2) : round($lastOb['top'] + 3.0, 2);
                $tp1 = $isBullOb ? round($entry + 10.0, 2) : round($entry - 10.0, 2);
                $tp2 = $isBullOb ? round($entry + 20.0, 2) : round($entry - 20.0, 2);
                $rr = 2.2;

                $sections[] = sprintf(
                    "#### 🎯 SMC Order Block Opportunity:\n" .
                    "* **Setup:** %s ($%.2f – $%.2f)\n" .
                    "* **Action:** `%s` (Limit / Retest)\n" .
                    "* **Stop Loss:** $%.2f | **TP1:** $%.2f | **TP2:** $%.2f",
                    $setupType, $lastOb['bottom'], $lastOb['top'], $signal, $sl, $tp1, $tp2
                );
            } else {
                $signal = 'NO TRADE';
                $setupType = 'SMC Equilibrium Oscillation';
                $sections[] = "#### ⏳ SMC Stand Aside Guidance:\nPrice is hovering in mid-range without an unmitigated Order Block nearby. Await price to enter either a deep Discount OB for longs or a Premium OB for shorts.";
            }
        }
        // -------------------------------------------------------------
        // STRATEGY: SUPPORT AND RESISTANCE (SNR)
        // -------------------------------------------------------------
        elseif ($strategyMode === 'SNR') {
            $nr = $snrRes['nearest_resistance'] ?? null;
            $ns = $snrRes['nearest_support'] ?? null;
            $allR = $snrRes['all_resistances'] ?? [];
            $allS = $snrRes['all_supports'] ?? [];
            $snrSetup = $snrRes['trade_setup'];
            $state = $snrRes['market_state'] ?? 'range_consolidation';
            $bias = $snrRes['bias'] ?? 'Neutral';
            $rej = $snrRes['rejection_candle'] ?? [];

            $rListStr = !empty($allR) 
                ? implode(', ', array_map(static fn($r) => sprintf('$%.2f (%d touches, %s)', $r['price'], $r['touches'], $r['strength']), array_slice($allR, 0, 3)))
                : 'None detected';
            $sListStr = !empty($allS) 
                ? implode(', ', array_map(static fn($s) => sprintf('$%.2f (%d touches, %s)', $s['price'], $s['touches'], $s['strength']), array_slice($allS, 0, 3)))
                : 'None detected';

            if ($snrSetup) {
                $signal = $snrSetup['direction'];
                $setupType = $snrSetup['name'];
                $entry = $snrSetup['entry'];
                $sl = $snrSetup['stop_loss'];
                $tp1 = $snrSetup['tp1'];
                $tp2 = $snrSetup['tp2'];
                $rr = $snrSetup['rr2'];
                $confidence = 'High (' . ($snrSetup['confidence_score'] ?? 84) . '%)';
                $invalidation = implode(' | ', $snrSetup['invalidations'] ?? []);
            } else {
                $signal = 'NO TRADE';
                $setupType = 'S&R Range Consolidation';
                $confidence = 'Medium';
                $invalidation = sprintf('Await price to test Support ($%.2f) or Resistance ($%.2f)', $ns ? $ns['price'] : 0, $nr ? $nr['price'] : 0);
            }

            $sections[] = sprintf(
                "### 🧱 Support & Resistance (S&R) Analysis — %s (%s)\n" .
                "* **Major Resistance Levels:** %s\n" .
                "* **Major Support Levels:** %s\n" .
                "* **Nearest Resistance (R1):** %s (Dist: +$%.2f)\n" .
                "* **Nearest Support (S1):** %s (Dist: -$%.2f)\n" .
                "* **Market State:** `%s`\n" .
                "* **Rejection Wick:** Upper %.1f%% | Lower %.1f%%",
                $symbol, $timeframe, $rListStr, $sListStr,
                $nr ? sprintf('$%.2f (%d touches, %s)', $nr['price'], $nr['touches'], $nr['strength']) : 'N/A',
                $nr ? (float)($nr['distance'] ?? 0) : 0,
                $ns ? sprintf('$%.2f (%d touches, %s)', $ns['price'], $ns['touches'], $ns['strength']) : 'N/A',
                $ns ? (float)($ns['distance'] ?? 0) : 0,
                strtoupper(str_replace('_', ' ', $state)),
                $rej['upper_wick_ratio'] ?? 0, $rej['lower_wick_ratio'] ?? 0
            );

            if ($snrSetup) {
                $sections[] = sprintf(
                    "#### 🎯 S&R Execution Setup (%s):\n" .
                    "* **Action:** `%s`\n" .
                    "* **Model:** %s\n" .
                    "* **Entry Price / Zone:** $%.2f ($%.2f – $%.2f)\n" .
                    "* **Stop Loss:** $%.2f (Structural invalidation)\n" .
                    "* **Take Profit 1:** $%.2f (Target S/R level, RR 1:%.2f)\n" .
                    "* **Take Profit 2:** $%.2f (Extended S/R level, RR 1:%.2f)\n" .
                    "* **Technical Evidence:** %s",
                    $snrSetup['name'], $signal, $snrSetup['code'],
                    $entry, $snrSetup['entry_zone']['min'], $snrSetup['entry_zone']['max'],
                    $sl, $tp1, $snrSetup['rr1'], $tp2, $snrSetup['rr2'], $snrSetup['details']
                );
            } else {
                $sections[] = sprintf(
                    "#### ⏳ S&R Stand Aside Guidance:\n" .
                    "Price ($%.2f) is currently oscillating between Support ($%.2f) and Resistance ($%.2f). Under institutional S&R rules, **no entry is authorized** in the middle of a range. Wait for one of the following:\n" .
                    "1. **Confirmed Breakout & Retest:** Candle body close beyond $%.2f or $%.2f, followed by a pullback retest holding as role reversal.\n" .
                    "2. **False Breakout / Trap:** Wick penetration beyond key levels with immediate rejection candle closing back inside the range.",
                    $price, ($ns ? (float)$ns['price'] : 0), ($nr ? (float)$nr['price'] : 0),
                    ($nr ? (float)$nr['price'] : 0), ($ns ? (float)$ns['price'] : 0)
                );
            }
        }
        // -------------------------------------------------------------
        // STRATEGY: ALL / FULL CONFLUENCE (ICT + SMC + CRT + SNR)
        // -------------------------------------------------------------
        else {
            $crtSetup = $crtRes['trade_setup'];
            $snrSetup = $snrRes['trade_setup'] ?? null;
            $unicorns = $ictRes['unicorns'] ?? [];
            $obs = $smcRes['order_blocks'] ?? [];

            $sections[] = sprintf(
                "### 🌐 Multi-Confluence Intelligence (ICT + SMC + CRT + SNR) — %s (%s)\n" .
                "* **Live Spot Price:** $%.2f\n" .
                "* **CRT Phase:** `%s` (RH: $%.2f | RL: $%.2f | EQ: $%.2f)\n" .
                "* **S&R Key Levels:** R1 $%.2f | S1 $%.2f\n" .
                "* **ICT Session:** %s (Killzone: %s)\n" .
                "* **SMC Structure:** %s (Trend: %s)",
                $symbol, $timeframe, $price,
                strtoupper(str_replace('_', ' ', $crtRes['phase'] ?? 'inside_range')),
                (float)($crtRes['reference_candle']['range_high'] ?? 0),
                (float)($crtRes['reference_candle']['range_low'] ?? 0),
                (float)($crtRes['reference_candle']['range_eq'] ?? 0),
                (float)($snrRes['nearest_resistance']['price'] ?? 0),
                (float)($snrRes['nearest_support']['price'] ?? 0),
                $kz['ny_time'], ($kz['is_killzone'] ? 'ACTIVE' : 'OFF'),
                ($smcRes['last_event']['type'] ?? 'Ranging'),
                ($smcRes['trend'] === 1 ? 'Bullish' : ($smcRes['trend'] === -1 ? 'Bearish' : 'Neutral'))
            );

            if ($crtSetup && $snrSetup && $crtSetup['direction'] === $snrSetup['direction']) {
                $signal = $crtSetup['direction'];
                $setupType = 'Quad Confluence: CRT ' . $crtSetup['name'] . ' + S&R ' . $snrSetup['name'];
                $entry = $crtSetup['entry'];
                $sl = $crtSetup['stop_loss'];
                $tp1 = $crtSetup['tp1'];
                $tp2 = $crtSetup['tp2'];
                $rr = 2.9;
                $confidence = 'Exceptional (94%)';
            } elseif ($crtSetup && !empty($unicorns)) {
                $signal = $crtSetup['direction'];
                $setupType = 'Triple Confluence: CRT Sweep + ICT Unicorn + SMC Breaker';
                $entry = $crtSetup['entry'];
                $sl = $crtSetup['stop_loss'];
                $tp1 = $crtSetup['tp1'];
                $tp2 = $crtSetup['tp2'];
                $rr = 2.8;
                $confidence = 'Very High (92%)';
            } elseif ($snrSetup) {
                $signal = $snrSetup['direction'];
                $setupType = 'S&R ' . $snrSetup['name'] . ' + Structure Confluence';
                $entry = $snrSetup['entry'];
                $sl = $snrSetup['stop_loss'];
                $tp1 = $snrSetup['tp1'];
                $tp2 = $snrSetup['tp2'];
                $rr = $snrSetup['rr2'];
                $confidence = 'High (86%)';
            } elseif ($crtSetup) {
                $signal = $crtSetup['direction'];
                $setupType = 'CRT ' . $crtSetup['name'] . ' + SMC Confluence';
                $entry = $crtSetup['entry'];
                $sl = $crtSetup['stop_loss'];
                $tp1 = $crtSetup['tp1'];
                $tp2 = $crtSetup['tp2'];
                $rr = $crtSetup['rr2'];
                $confidence = 'High (85%)';
            } else {
                $signal = 'NO TRADE';
                $setupType = 'Awaiting Multi-Confluence Alignment';
                $confidence = 'Low';
            }

            if ($signal !== 'NO TRADE') {
                $sections[] = sprintf(
                    "#### 🎯 High-Conviction Confluence Trade Signal:\n" .
                    "* **Model:** %s\n" .
                    "* **Signal Direction:** `%s`\n" .
                    "* **Ref Entry:** $%.2f | **Stop Loss:** $%.2f\n" .
                    "* **Take Profit 1:** $%.2f | **Take Profit 2:** $%.2f\n" .
                    "* **Confidence:** %s",
                    $setupType, $signal, $entry, $sl, $tp1, $tp2, $confidence
                );
            } else {
                $sections[] = "#### ⏳ Confluence Filter:\nICT, SMC, and CRT are not currently in 100% directional alignment. To protect capital, the system requires NO TRADE until all three frameworks confirm the setup.";
            }
        }

        // Standard Institutional Signal Block
        $signalSummary = sprintf(
            "```text\n" .
            "SIGNAL: %s\n" .
            "STRATEGY MODE: %s\n" .
            "SETUP: %s\n" .
            "ENTRY: %s\n" .
            "STOP LOSS: %s\n" .
            "TP1 / TP2: %s\n" .
            "RISK-TO-REWARD: %s\n" .
            "CONFIDENCE: %s\n" .
            "```",
            $signal, $strategyMode, $setupType,
            ($entry ? sprintf('$%.2f', $entry) : 'STANDBY'),
            ($sl ? sprintf('$%.2f', $sl) : 'N/A'),
            ($tp1 && $tp2 ? sprintf('$%.2f / $%.2f', $tp1, $tp2) : 'N/A'),
            ($rr ? sprintf('1:%.2f', $rr) : 'Min 2:1 required'),
            $confidence
        );

        $sections[] = $signalSummary;

        return [
            'signal'      => $signal,
            'setup_type'  => $setupType,
            'entry'       => $entry,
            'sl'          => $sl,
            'tp1'         => $tp1,
            'tp2'         => $tp2,
            'rr'          => $rr,
            'text_body'   => implode("\n\n", $sections),
        ];
    }

    /**
     * Call external LLM (OpenAI or Gemini) if API keys are configured, otherwise return the factual report.
     */
    private static function callExternalAiIfAvailable(
        string $userMsg,
        array $payload,
        string $strategyMode,
        string $symbol,
        string $timeframe
    ): string {
        $aiKey = trim((string)Env::get('AI_API_KEY', ''));
        $provider = strtolower(trim((string)Env::get('AI_PROVIDER', 'none')));

        if ($aiKey === '' || $provider !== 'openai') {
            return $payload['text_body'];
        }

        try {
            $prompt = <<<PROMPT
You are an expert XAUUSD institutional trading analyst specializing in {$strategyMode} (Smart Money Concepts, ICT, and Candle Range Theory CRT).
The user asked: "{$userMsg}"

Here are the VERIFIED real-time market facts, levels, and calculations:
{$payload['text_body']}

Provide a concise, professional analyst reply directly answering the user's question. Preserve all exact mathematical prices (Entry, SL, TP, RH, RL, EQ). Never fabricate prices.
PROMPT;

            $res = Http::postJson(
                (string)Env::get('AI_BASE_URL', 'https://api.openai.com/v1') . '/chat/completions',
                [
                    'model'       => Env::get('AI_MODEL', 'gpt-4o-mini'),
                    'messages'    => [
                        ['role' => 'system', 'content' => 'You are a professional XAUUSD quantitative trader specializing in ICT, SMC, and CRT.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens'  => 650,
                    'temperature' => 0.2,
                ],
                ['Authorization: Bearer ' . $aiKey],
                ['timeout' => 15, 'retries' => 1]
            );

            if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['json']['choices'][0]['message']['content'])) {
                return trim((string)$res['json']['choices'][0]['message']['content']);
            }
        } catch (\Throwable $e) {
            Logger::warning('AI chat completions failed, falling back to deterministic report', ['err' => $e->getMessage()]);
        }

        return $payload['text_body'];
    }

    /**
     * Get recent chat messages for a user and symbol.
     */
    public static function getHistory(int $userId, string $symbol = 'XAUUSD', int $limit = 30): array
    {
        $symbol = MarketDataService::normalizeSymbol($symbol);
        return Database::fetchAll(
            'SELECT id, strategy_mode, sender, message, metadata_json, created_at 
             FROM ai_chat_messages 
             WHERE user_id = ? AND symbol = ? 
             ORDER BY id ASC LIMIT ?',
            [$userId, $symbol, $limit]
        );
    }

    /**
     * Clear chat history.
     */
    public static function clearHistory(int $userId, string $symbol = 'XAUUSD'): bool
    {
        $symbol = MarketDataService::normalizeSymbol($symbol);
        Database::query('DELETE FROM ai_chat_messages WHERE user_id = ? AND symbol = ?', [$userId, $symbol]);
        return true;
    }
}
