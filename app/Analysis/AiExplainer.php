<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;

/**
 * Generates institutional SMC commentary for a multi-timeframe analysis snapshot.
 * Never invents prices or overrides the deterministic signal engine:
 *  - When AI_PROVIDER=none (or unconfigured), produces a structured rule-based explanation.
 *  - When AI_PROVIDER=openai, calls an OpenAI-compatible Chat Completions endpoint
 *    grounded strictly on the engine's JSON output, falling back to the rule-based
 *    explanation if the external request fails.
 */
final class AiExplainer
{
    /**
     * @return array{explanation: string, provider: string}
     */
    public static function explain(array $analysis): array
    {
        $fallback = self::buildRuleExplanation($analysis);
        $provider = strtolower((string)Env::get('AI_PROVIDER', 'none'));
        $apiKey = (string)Env::get('AI_API_KEY', '');

        if ($provider !== 'openai' || $apiKey === '') {
            return ['explanation' => $fallback, 'provider' => 'rule-engine'];
        }

        $model = (string)Env::get('AI_MODEL', 'gpt-4o-mini');
        $baseUrl = rtrim((string)Env::get('AI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $timeout = Env::int('AI_TIMEOUT', 30);

        $sig = $analysis['signal'] ?? [];
        $payloadSummary = [
            'symbol'            => $sig['asset'] ?? 'XAUUSD',
            'data_mode'         => $analysis['data_mode'] ?? 'unknown',
            'price'             => $analysis['price'] ?? null,
            'htf_bias'          => $analysis['htf_bias'] ?? null,
            'htf_votes'         => $analysis['htf_votes'] ?? [],
            'context'           => $analysis['context'] ?? [],
            'session'           => $analysis['session'] ?? null,
            'key_levels'        => $analysis['key_levels'] ?? [],
            'nearest_liquidity' => $analysis['nearest_liquidity'] ?? [],
            'signal'            => [
                'direction'     => $sig['direction'] ?? 'WAIT',
                'label'         => $sig['label'] ?? 'WAIT',
                'setup_status'  => $sig['setup_status'] ?? 'none',
                'setup_type'    => $sig['setup_type'] ?? null,
                'entry_tf'      => $sig['entry_tf'] ?? null,
                'entry_zone'    => isset($sig['entry_low'], $sig['entry_high']) ? [$sig['entry_low'], $sig['entry_high']] : null,
                'entry'         => $sig['entry'] ?? null,
                'sl'            => $sig['sl'] ?? null,
                'tp1'           => $sig['tp1'] ?? null,
                'tp2'           => $sig['tp2'] ?? null,
                'tp3'           => $sig['tp3'] ?? null,
                'rr1'           => $sig['rr1'] ?? null,
                'rr2'           => $sig['rr2'] ?? null,
                'quality_score' => $sig['quality_score'] ?? null,
                'reasons'       => $sig['reasons'] ?? [],
                'invalidations' => $sig['invalidations'] ?? [],
                'warnings'      => $sig['warnings'] ?? [],
            ],
        ];

        $res = Http::postJson(
            $baseUrl . '/chat/completions',
            [
                'model'       => $model,
                'temperature' => 0.15,
                'max_tokens'  => 450,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'You are an institutional XAU/USD Smart Money Concepts (SMC) analyst. '
                            . 'Explain the provided deterministic engine output concisely in 3 short paragraphs: '
                            . '(1) Multi-timeframe structure & dealing range bias, '
                            . '(2) Setup thesis, liquidity sweeps, POI (Order Block/FVG) or why the engine is waiting, '
                            . '(3) Invalidations, targets, and execution risk notes. '
                            . 'STRICT RULES: Never invent prices, levels, or signals not present in the JSON. '
                            . 'Never contradict the direction (' . ($sig['direction'] ?? 'WAIT') . ') or claim guaranteed profits.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => json_encode($payloadSummary, JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ],
            ['Authorization: Bearer ' . $apiKey],
            ['timeout' => $timeout, 'retries' => 1]
        );

        $text = trim((string)($res['json']['choices'][0]['message']['content'] ?? ''));
        if ($res['status'] >= 200 && $res['status'] < 300 && $text !== '') {
            return ['explanation' => $text, 'provider' => 'openai:' . $model];
        }

        Logger::warning('AI explanation fallback to rule-engine', [
            'status' => $res['status'],
            'error'  => $res['error'] ?? ($res['json']['error']['message'] ?? 'empty response'),
        ]);
        return ['explanation' => $fallback, 'provider' => 'rule-engine (fallback)'];
    }

    public static function buildRuleExplanation(array $analysis): string
    {
        if (empty($analysis['ok'])) {
            return 'Analysis unavailable: ' . ($analysis['error'] ?? 'insufficient market data.');
        }

        $sig = $analysis['signal'] ?? [];
        $dir = $sig['direction'] ?? 'WAIT';
        $price = isset($analysis['price']) ? number_format((float)$analysis['price'], 2, '.', '') : 'n/a';
        $entryTf = $analysis['entry_tf'] ?? 'M15';
        $poiTf = $analysis['poi_tf'] ?? 'H1';
        $htfBias = strtoupper((string)($analysis['htf_bias'] ?? 'neutral'));
        $votes = [];
        foreach (($analysis['htf_votes'] ?? []) as $tf => $v) {
            $votes[] = "$tf: $v";
        }
        $voteStr = $votes ? ' (' . implode(', ', $votes) . ')' : '';

        $poiSummary = $analysis['timeframes'][$poiTf] ?? null;
        $pdText = '';
        if (!empty($poiSummary['pd'])) {
            $pd = $poiSummary['pd'];
            $pdText = sprintf(
                ' On %s, price is trading at %.1f%% of the dealing range (%.2f - %.2f, EQ %.2f) inside the %s zone.',
                $poiTf,
                (float)$pd['position_pct'],
                (float)$pd['low'],
                (float)$pd['high'],
                (float)$pd['eq'],
                strtoupper((string)$pd['zone'])
            );
        }

        $asset = $sig['asset'] ?? 'XAUUSD';
        $p1 = sprintf(
            '1. Multi-Timeframe Structure & Bias: %s is at $%s during the %s session. Higher-timeframe bias is %s%s.%s',
            $asset,
            $price,
            $analysis['session'] ?? 'current',
            $htfBias,
            $voteStr,
            $pdText
        );

        if ($dir === 'BUY' || $dir === 'SELL') {
            $p2 = sprintf(
                '2. %s Setup Thesis (%s, Score %d/100): Qualified %s setup on %s (%s). Entry zone spans $%.2f - $%.2f (reference entry $%.2f) with technical invalidation at $%.2f (stop distance $%.2f). Key confluences: %s.',
                $dir,
                $sig['label'] ?? $dir,
                (int)($sig['quality_score'] ?? 0),
                $sig['setup_type'] ?? 'SMC confluence',
                $entryTf,
                strtoupper((string)($sig['setup_status'] ?? 'confirmed')),
                (float)($sig['entry_low'] ?? 0),
                (float)($sig['entry_high'] ?? 0),
                (float)($sig['entry'] ?? 0),
                (float)($sig['sl'] ?? 0),
                (float)($sig['stop_distance'] ?? 0),
                implode('; ', $sig['reasons'] ?? [])
            );

            $tpParts = [];
            foreach ([1, 2, 3] as $i) {
                if (!empty($sig['tp' . $i])) {
                    $tpParts[] = sprintf(
                        'TP%d $%.2f (%.2fR%s)',
                        $i,
                        (float)$sig['tp' . $i],
                        (float)($sig['rr' . $i] ?? 0),
                        !empty($sig['tp' . $i . '_label']) ? ' - ' . $sig['tp' . $i . '_label'] : ''
                    );
                }
            }
            $p3 = sprintf(
                '3. Targets & Invalidation: Opposing liquidity targets are %s. Setup is invalidated if %s.',
                $tpParts ? implode(', ', $tpParts) : 'none',
                implode('; or ', array_slice($sig['invalidations'] ?? ['stop loss is breached on a candle close'], 0, 3))
            );
        } else {
            $reasons = $sig['reasons'] ?? ['Required SMC confluence conditions are not simultaneously satisfied.'];
            $watchText = '';
            if (!empty($sig['watch'])) {
                $w = $sig['watch'];
                $zStr = !empty($w['zone']) ? sprintf(' near %s $%.2f - $%.2f', $w['zone']['kind'], (float)$w['zone']['bottom'], (float)$w['zone']['top']) : '';
                $watchText = sprintf(' Developing %s interest noted%s (awaiting: %s).', $w['direction'], $zStr, implode(', ', $w['missing'] ?? []));
            }
            $p2 = sprintf(
                '2. Execution Status (WAIT / NO TRADE, Best Candidate Score %d/100): The engine is standing aside on %s. %s%s',
                (int)($sig['quality_score'] ?? 0),
                $entryTf,
                implode(' ', $reasons),
                $watchText
            );

            $near = $analysis['nearest_liquidity'] ?? [];
            $liqParts = [];
            if (!empty($near['bsl'])) {
                $liqParts[] = sprintf('resting BSL above at $%.2f', (float)$near['bsl']);
            }
            if (!empty($near['ssl'])) {
                $liqParts[] = sprintf('resting SSL below at $%.2f', (float)$near['ssl']);
            }
            $p3 = sprintf(
                '3. Liquidity Map & Risk Notes: Nearest external pools are %s. Wait for a clean liquidity sweep, close-confirmed BOS/CHOCH with displacement, and a valid unmitigated OB/FVG offering at least the minimum R:R before committing risk.',
                $liqParts ? implode(' and ', $liqParts) : 'being established'
            );
        }

        if (!empty($sig['warnings'])) {
            $p3 .= ' Warnings: ' . implode(' ', $sig['warnings']);
        }

        return implode("\n\n", [$p1, $p2, $p3]);
    }

    /**
     * Standard AI Analysis Output as required by Section 12 of MASTER PROMPT:
     *
     * INSTRUMENT: XAUUSD
     * MARKET STATUS: Trending / Ranging / Uncertain
     * HIGHER-TIMEFRAME BIAS: Bullish / Bearish / Neutral
     * CURRENT PRICE: Verified price with timestamp, or unavailable
     * KEY LIQUIDITY: Relevant BSL / SSL levels
     * MARKET STRUCTURE: BOS / CHoCH / MSS / None confirmed
     * ICT/SMC CONFLUENCE: Evidence-based findings
     * TRADE DECISION: BUY / SELL / WAIT / NO TRADE
     *
     * ENTRY ZONE: [Price or zone]
     * STOP LOSS: [Price]
     * TAKE PROFIT 1: [Price]
     * TAKE PROFIT 2: [Price]
     * TAKE PROFIT 3: [Price or N/A]
     * RISK-TO-REWARD: [Calculated ratio]
     * SETUP CONFIDENCE: [Score with explanation]
     * INVALIDATION: [Conditions that cancel the setup]
     * RISK WARNING: [Important risks]
     *
     * REASONING: Explain the setup step by step using observable evidence.
     */
    public static function standardReport(array $analysis): string
    {
        $sig = $analysis['signal'] ?? [];
        $instrument = $sig['asset'] ?? ($analysis['symbol'] ?? 'XAUUSD');

        // 1. Market Status & Regime
        $trend = $analysis['trend_analysis'] ?? null;
        $class = $trend['classification'] ?? null;
        if ($class !== null) {
            if (str_contains($class, 'Bullish') || str_contains($class, 'Bearish')) {
                $marketStatus = 'Trending (' . $class . ')';
            } elseif ($class === 'Consolidation / Range') {
                $marketStatus = 'Ranging (' . $class . ')';
            } else {
                $marketStatus = 'Uncertain (' . $class . ')';
            }
        } else {
            $marketStatus = !empty($analysis['market_direction']) && $analysis['market_direction'] !== 'neutral' ? 'Trending' : 'Ranging';
        }

        $regime = $analysis['regime'] ?? ($analysis['regime_analysis']['regime'] ?? null);
        $regimeLabel = match ($regime) {
            'trending_bullish' => 'Trending Bullish (Trend Continuation Models Authorized)',
            'trending_bearish' => 'Trending Bearish (Trend Continuation Models Authorized)',
            'ranging_consolidation' => 'Ranging / Consolidation (Reversal & Liquidity Sweep Models)',
            'high_volatility_abnormal' => 'High Volatility Abnormal (HALTED: Circuit Breaker Active)',
            default => 'Consolidation / Mixed Market Regimes',
        };

        // 2. Higher-Timeframe Bias
        $htfBias = ucfirst((string)($analysis['htf_bias'] ?? 'Neutral'));

        // 3. Current Price
        $price = isset($analysis['price']) && (float)$analysis['price'] > 0
            ? sprintf('$%.2f (UTC %s)', (float)$analysis['price'], gmdate('Y-m-d H:i:s', (int)($analysis['analyzed_at'] ?? time())))
            : 'Unavailable';

        // 4. Key Liquidity
        $near = $analysis['nearest_liquidity'] ?? [];
        $liqParts = [];
        if (!empty($near['bsl'])) $liqParts[] = sprintf('BSL: $%.2f', (float)$near['bsl']);
        if (!empty($near['ssl'])) $liqParts[] = sprintf('SSL: $%.2f', (float)$near['ssl']);
        $keyLiquidity = $liqParts ? implode(' | ', $liqParts) : 'Establishing intraday pools';

        // 5. Market Structure
        $structure = 'None confirmed';
        if (!empty($analysis['events'])) {
            $lastEv = end($analysis['events']);
            $structure = $lastEv['label'] ?? ($lastEv['type'] ?? 'Confirmed');
        } elseif (!empty($trend['structure_events'])) {
            $lastEv = end($trend['structure_events']);
            $structure = $lastEv['type'] ?? 'Confirmed';
        }

        // 6. ICT / SMC Confluence
        $confs = [];
        if (!empty($sig['confluences'])) {
            foreach ($sig['confluences'] as $c) {
                if (!empty($c['met'])) $confs[] = $c['label'];
            }
        }
        if (!empty($sig['ict_strategy'])) {
            $confs[] = 'Model: ' . $sig['ict_strategy'];
        }
        $confluenceStr = $confs ? implode(', ', array_slice($confs, 0, 4)) : 'Awaiting multi-timeframe confluence alignment';

        // 7. Trade Decision
        $dir = $sig['direction'] ?? 'WAIT';
        $decision = ($dir === 'BUY' || $dir === 'SELL') ? $dir : ($dir === 'WAIT' ? 'WAIT' : 'NO TRADE');

        // Setup Levels (Leave unavailable rather than inventing prices)
        $isTrade = ($decision === 'BUY' || $decision === 'SELL');
        $entryZone = $isTrade && isset($sig['entry_low'], $sig['entry_high'])
            ? sprintf('$%.2f – $%.2f (Ref $%.2f)', (float)$sig['entry_low'], (float)$sig['entry_high'], (float)($sig['entry'] ?? 0))
            : 'Unavailable';
        $stopLoss = $isTrade && isset($sig['sl'])
            ? sprintf('$%.2f (Risk: $%.2f)', (float)$sig['sl'], (float)($sig['stop_distance'] ?? 0))
            : 'Unavailable';
        $tp1 = $isTrade && isset($sig['tp1']) ? sprintf('$%.2f (%.2fR)', (float)$sig['tp1'], (float)($sig['rr1'] ?? 0)) : 'Unavailable';
        $tp2 = $isTrade && isset($sig['tp2']) ? sprintf('$%.2f (%.2fR)', (float)$sig['tp2'], (float)($sig['rr2'] ?? 0)) : 'Unavailable';
        $tp3 = $isTrade && isset($sig['tp3']) ? sprintf('$%.2f (%.2fR)', (float)$sig['tp3'], (float)($sig['rr3'] ?? 0)) : 'N/A';

        $rr = $isTrade && !empty($sig['rr1']) ? sprintf('1:%.2f (TP1) / 1:%.2f (TP2)', (float)$sig['rr1'], (float)($sig['rr2'] ?? 0)) : 'Unavailable';
        $score = (int)($sig['quality_score'] ?? 0);
        $confidence = sprintf('%d/100 (%s)', $score, $score >= 80 ? 'High institutional confluence' : ($score >= 65 ? 'Moderate confluence' : 'Standby / Incomplete'));

        // Invalidation & Risks
        $invals = !empty($sig['invalidations']) ? implode('; ', $sig['invalidations']) : 'Candle close breaching technical structure or opposite liquidity sweep.';
        $warnings = !empty($sig['warnings']) ? implode('; ', $sig['warnings']) : 'Adhere to strict risk management (0.5%–1.0% equity max). Monitor upcoming high-impact economic news releases.';

        // Reasoning
        $reasoning = !empty($sig['ai_explanation'])
            ? $sig['ai_explanation']
            : self::buildRuleExplanation($analysis);

        $riskControls = 'Default 0.5% Account Risk | Max Daily Loss: 2.0% | Max Open Positions: 2 | Anti-Martingale Enforced';
        $disclosure = 'DISCLOSURE: Simulated and rule-based trading signals carry inherent financial risk. Quantitative backtesting and high confluence scores do not guarantee future profits. Enforce disciplined capital preservation.';

        return <<<REPORT
INSTRUMENT: {$instrument}
MARKET STATUS: {$marketStatus}
MARKET REGIME: {$regimeLabel}
HIGHER-TIMEFRAME BIAS: {$htfBias}
CURRENT PRICE: {$price}
KEY LIQUIDITY: {$keyLiquidity}
MARKET STRUCTURE: {$structure}
ICT/SMC CONFLUENCE: {$confluenceStr}
TRADE DECISION: {$decision}
RISK CONTROLS: {$riskControls}

ENTRY ZONE: {$entryZone}
STOP LOSS: {$stopLoss}
TAKE PROFIT 1: {$tp1}
TAKE PROFIT 2: {$tp2}
TAKE PROFIT 3: {$tp3}
RISK-TO-REWARD: {$rr}
SETUP CONFIDENCE: {$confidence}
INVALIDATION: {$invals}
RISK WARNING: {$warnings}

REASONING: Explain the setup step by step using observable evidence.
{$reasoning}

{$disclosure}
REPORT;
    }
}
