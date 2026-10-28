<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Notifier;
use App\Core\Settings;
use App\Market\MarketDataService;
use App\Market\Timeframe;

/**
 * Evaluates active trading signals against newly closed candles to track
 * fills, TP1/TP2/TP3 hits, breakeven moves, stop-outs, expirations, MFE/MAE,
 * and aggregate performance metrics (strictly separating live vs demo data).
 */
final class SignalTracker
{
    private array $tcfg;

    public function __construct(?array $trackingCfg = null)
    {
        $this->tcfg = array_merge([
            'partial_at_tp1'    => 0.5,
            'move_be_after_tp1' => true,
            'same_bar_policy'   => 'conservative',
            'breakeven_band_r'  => 0.05,
        ], $trackingCfg ?? (array)Settings::get('tracking', []));
    }

    /**
     * Evaluate all open/confirmed/triggered signals for a market data service.
     * @return int Number of signals updated
     */
    public function updateOpenSignals(MarketDataService $market, ?int $now = null): int
    {
        $now = $now ?? time();
        $openSignals = Database::fetchAll(
            "SELECT s.*, o.outcome, o.entry_filled_at, o.max_favorable_r, o.max_adverse_r, o.ambiguous
             FROM trading_signals s
             LEFT JOIN signal_outcomes o ON o.signal_id = s.id
             WHERE s.data_mode = ?
               AND s.symbol = ?
               AND s.direction IN ('BUY','SELL')
               AND s.setup_status IN ('confirmed','triggered','potential')
             ORDER BY s.id ASC",
            [$market->mode(), $market->symbol()]
        );

        $updated = 0;
        $quote = $market->quote(false);
        $livePrice = (!empty($quote['available']) && !empty($quote['price'])) ? round((float)$quote['price'], 2) : null;
        foreach ($openSignals as $sig) {
            $tf = (string)$sig['entry_tf'];
            $refTs = (int)strtotime($sig['data_ref'] . ' UTC');
            $candles = $market->candles($tf, 500, $now, $refTs);
            if ($candles && $livePrice !== null) {
                $lastIdx = count($candles) - 1;
                if ($candles[$lastIdx]['t'] > $refTs) {
                    $candles[$lastIdx]['c'] = $livePrice;
                    $candles[$lastIdx]['h'] = max((float)$candles[$lastIdx]['h'], $livePrice);
                    $candles[$lastIdx]['l'] = min((float)$candles[$lastIdx]['l'], $livePrice);
                }
            }
            if ($this->evaluateSignal($sig, $candles, $now)) {
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Step a single signal row through closed candles after its `data_ref` candle.
     */
    public function evaluateSignal(array $sig, array $closedCandles, int $now): bool
    {
        $sigId = (int)$sig['id'];
        $isBuy = $sig['direction'] === 'BUY';
        $entry = (float)$sig['entry_price'];
        $sl = (float)$sig['stop_loss'];
        $risk = $isBuy ? ($entry - $sl) : ($sl - $entry);
        if ($risk <= 0) {
            return false;
        }

        $tp1 = $sig['tp1'] !== null ? (float)$sig['tp1'] : null;
        $tp2 = $sig['tp2'] !== null ? (float)$sig['tp2'] : null;
        $tp3 = $sig['tp3'] !== null ? (float)$sig['tp3'] : null;
        $refTs = (int)strtotime($sig['data_ref'] . ' UTC');
        $expiresTs = $sig['expires_at'] ? (int)strtotime($sig['expires_at'] . ' UTC') : null;

        $partialFrac = max(0.0, min(1.0, (float)$this->tcfg['partial_at_tp1']));
        $moveBe = (bool)$this->tcfg['move_be_after_tp1'];
        $conservative = ($this->tcfg['same_bar_policy'] ?? 'conservative') === 'conservative';
        $beBand = (float)$this->tcfg['breakeven_band_r'];

        $filled = $sig['entry_filled_at'] !== null;
        $filledAt = $sig['entry_filled_at'] ? (int)strtotime($sig['entry_filled_at'] . ' UTC') : null;
        $tp1Hit = (bool)Database::value("SELECT 1 FROM signal_events WHERE signal_id = ? AND event_type = 'tp1_hit'", [$sigId]);
        $tp2Hit = (bool)Database::value("SELECT 1 FROM signal_events WHERE signal_id = ? AND event_type = 'tp2_hit'", [$sigId]);
        $currentSl = ($tp1Hit && $moveBe) ? $entry : $sl;
        $remWeight = $tp1Hit ? (1.0 - $partialFrac) : 1.0;
        $realizedR = ($tp1Hit && $tp1 !== null) ? $partialFrac * (abs($tp1 - $entry) / $risk) : 0.0;
        $mfe = $sig['max_favorable_r'] !== null ? (float)$sig['max_favorable_r'] : 0.0;
        $mae = $sig['max_adverse_r'] !== null ? (float)$sig['max_adverse_r'] : 0.0;
        $ambiguous = (int)($sig['ambiguous'] ?? 0);

        $setupStatus = (string)$sig['setup_status'];
        $outcome = (string)($sig['outcome'] ?? 'pending');
        $exitAt = null;
        $exitPrice = null;
        $exitReason = null;
        $finalR = null;

        foreach ($closedCandles as $c) {
            if ($c['t'] <= $refTs) {
                continue;
            }
            $cCloseTs = Timeframe::closeTime((string)$sig['entry_tf'], $c['t']);
            $evTs = min($now, $cCloseTs);

            if (!$filled) {
                // Invalidation before entry: closed candle beyond SL
                if ($cCloseTs <= $now && ($isBuy ? $c['c'] <= $sl : $c['c'] >= $sl)) {
                    $setupStatus = 'invalidated';
                    $outcome = 'invalidated';
                    $exitAt = $cCloseTs;
                    $exitPrice = $c['c'];
                    $exitReason = 'SL breached before entry';
                    $this->recordEvent($sig, 'invalidated', $c['c'], $cCloseTs);
                    break;
                }
                // Expiry before fill
                if ($expiresTs !== null && $c['t'] >= $expiresTs) {
                    $setupStatus = 'expired';
                    $outcome = 'expired';
                    $exitAt = $evTs;
                    $exitPrice = $c['c'];
                    $exitReason = 'Setup expired unfilled';
                    $this->recordEvent($sig, 'expired', $c['c'], $evTs);
                    break;
                }
                $touchedEntry = $isBuy ? ($c['l'] <= $entry) : ($c['h'] >= $entry);
                $touchedTp1 = $tp1 !== null && ($isBuy ? ($c['h'] >= $tp1) : ($c['l'] <= $tp1));
                if (!$touchedEntry && $touchedTp1) {
                    $setupStatus = 'expired';
                    $outcome = 'expired';
                    $exitAt = $evTs;
                    $exitPrice = $tp1;
                    $exitReason = 'Reached TP1 before entry fill';
                    $this->recordEvent($sig, 'expired', $tp1, $evTs, ['reason' => 'missed_tp1']);
                    break;
                }
                if ($touchedEntry) {
                    $filled = true;
                    $filledAt = $c['t'];
                    $setupStatus = 'triggered';
                    $outcome = 'open';
                    $this->recordEvent($sig, 'entry_filled', $entry, $c['t']);
                } else {
                    continue;
                }
            }

            // Trade is open on candle $c
            $fav = $isBuy ? ($c['h'] - $entry) / $risk : ($entry - $c['l']) / $risk;
            $adv = $isBuy ? ($entry - $c['l']) / $risk : ($c['h'] - $entry) / $risk;
            $mfe = max($mfe, round(max(0.0, $fav), 3));
            $mae = max($mae, round(max(0.0, $adv), 3));

            $hitSl = $isBuy ? ($c['l'] <= $currentSl) : ($c['h'] >= $currentSl);
            $finalTp = $tp3 ?? $tp2 ?? $tp1;
            $hitAnyTp = ($tp1 !== null && !$tp1Hit && ($isBuy ? $c['h'] >= $tp1 : $c['l'] <= $tp1))
                || ($finalTp !== null && ($isBuy ? $c['h'] >= $finalTp : $c['l'] <= $finalTp));

            if ($hitSl && $hitAnyTp) {
                $ambiguous = 1;
                if ($conservative) {
                    // Conservative policy: count stop loss hit first when SL & TP touched in same bar
                    $slR = $isBuy ? ($currentSl - $entry) / $risk : ($entry - $currentSl) / $risk;
                    $finalR = round($realizedR + $remWeight * $slR, 3);
                    $exitAt = $evTs;
                    $exitPrice = $currentSl;
                    $exitReason = $tp1Hit && $moveBe ? 'Breakeven stop after TP1 (same-bar conservative)' : 'Stop loss hit (same-bar conservative)';
                    $setupStatus = 'closed';
                    $outcome = abs($finalR) <= $beBand ? 'breakeven' : ($finalR > 0 ? 'win' : 'loss');
                    $this->recordEvent($sig, 'sl_hit', $currentSl, $evTs, ['ambiguous' => true, 'is_be' => ($tp1Hit && $moveBe), 'final_r' => $finalR]);
                    break;
                }
            }

            if ($hitSl) {
                $slR = $isBuy ? ($currentSl - $entry) / $risk : ($entry - $currentSl) / $risk;
                $finalR = round($realizedR + $remWeight * $slR, 3);
                $exitAt = $evTs;
                $exitPrice = $currentSl;
                $exitReason = ($tp1Hit && $moveBe) ? 'Breakeven stop after TP1' : 'Stop loss hit';
                $setupStatus = 'closed';
                $outcome = abs($finalR) <= $beBand ? 'breakeven' : ($finalR > 0 ? 'win' : 'loss');
                $this->recordEvent($sig, 'sl_hit', $currentSl, $evTs, ['is_be' => ($tp1Hit && $moveBe), 'final_r' => $finalR]);
                break;
            }

            // Check TP1
            if ($tp1 !== null && !$tp1Hit && ($isBuy ? $c['h'] >= $tp1 : $c['l'] <= $tp1)) {
                $tp1Hit = true;
                $tp1R = abs($tp1 - $entry) / $risk;
                $this->recordEvent($sig, 'tp1_hit', $tp1, $evTs, ['rr' => round($tp1R, 2)]);
                if ($tp2 === null && $tp3 === null) {
                    $finalR = round($tp1R, 3);
                    $exitAt = $evTs;
                    $exitPrice = $tp1;
                    $exitReason = 'TP1 target reached';
                    $setupStatus = 'closed';
                    $outcome = 'win';
                    break;
                }
                $realizedR += $partialFrac * $tp1R;
                $remWeight = 1.0 - $partialFrac;
                if ($moveBe) {
                    $currentSl = $entry;
                    $this->recordEvent($sig, 'be_moved', $entry, $evTs);
                }
            }

            // Check TP2
            if ($tp2 !== null && !$tp2Hit && ($isBuy ? $c['h'] >= $tp2 : $c['l'] <= $tp2)) {
                $tp2Hit = true;
                $tp2R = abs($tp2 - $entry) / $risk;
                $estFinalR = round($realizedR + $remWeight * $tp2R, 3);
                $this->recordEvent($sig, 'tp2_hit', $tp2, $evTs, ['rr' => round($tp2R, 2), 'final_r' => $estFinalR]);
                if ($tp3 === null) {
                    $finalR = $estFinalR;
                    $exitAt = $evTs;
                    $exitPrice = $tp2;
                    $exitReason = 'TP2 target reached';
                    $setupStatus = 'closed';
                    $outcome = 'win';
                    break;
                }
            }

            // Check TP3
            if ($tp3 !== null && ($isBuy ? $c['h'] >= $tp3 : $c['l'] <= $tp3)) {
                $tp3R = abs($tp3 - $entry) / $risk;
                $finalR = round($realizedR + $remWeight * $tp3R, 3);
                $this->recordEvent($sig, 'tp3_hit', $tp3, $evTs, ['rr' => round($tp3R, 2), 'final_r' => $finalR]);
                $exitAt = $evTs;
                $exitPrice = $tp3;
                $exitReason = 'TP3 target reached';
                $setupStatus = 'closed';
                $outcome = 'win';
                break;
            }
        }

        // Check time expiry if still unfilled at $now
        if (!$filled && $setupStatus === 'confirmed' && $expiresTs !== null && $now >= $expiresTs) {
            $setupStatus = 'expired';
            $outcome = 'expired';
            $exitAt = $now;
            $exitReason = 'Setup expired unfilled';
            $this->recordEvent($sig, 'expired', null, $now);
        }

        $nowSql = gmdate('Y-m-d H:i:s', $now);
        Database::update('trading_signals', [
            'setup_status' => $setupStatus,
            'updated_at'   => $nowSql,
        ], 'id = ?', [$sigId]);

        $outcomeData = [
            'outcome'         => $outcome,
            'entry_filled_at' => $filledAt ? gmdate('Y-m-d H:i:s', $filledAt) : null,
            'exit_at'         => $exitAt ? gmdate('Y-m-d H:i:s', $exitAt) : null,
            'exit_price'      => $exitPrice,
            'exit_reason'     => $exitReason,
            'r_multiple'      => $finalR,
            'is_simulated'    => 1,
            'ambiguous'       => $ambiguous,
            'max_favorable_r' => $filled ? $mfe : null,
            'max_adverse_r'   => $filled ? $mae : null,
            'updated_at'      => $nowSql,
        ];

        $existingOutcome = Database::value('SELECT id FROM signal_outcomes WHERE signal_id = ?', [$sigId]);
        if ($existingOutcome) {
            Database::update('signal_outcomes', $outcomeData, 'signal_id = ?', [$sigId]);
        } else {
            Database::insert('signal_outcomes', ['signal_id' => $sigId] + $outcomeData);
        }

        // Phase 4: Diagnostic Feedback and Error Reporting on Closed Outcomes
        if (in_array($outcome, ['win', 'loss', 'breakeven'], true)) {
            try {
                $feedback = new \App\ML\SignalFeedbackEngine();
                $feedback->recordClosedTrade($sigId, $outcome, (float)($finalR ?? 0.0), (float)$mfe, (float)$mae);
            } catch (\Throwable $e) {
                // Non-blocking diagnostic record
            }
        }

        return $setupStatus !== $sig['setup_status'] || $outcome !== ($sig['outcome'] ?? 'pending');
    }

    /**
     * Aggregate performance statistics for a given data_mode ('live' or 'demo') and optional symbol.
     */
    public function performanceStats(string $dataMode = 'live', ?string $symbol = null): array
    {
        $where = "s.data_mode = ? AND s.direction IN ('BUY','SELL')";
        $params = [$dataMode];
        if ($symbol !== null && $symbol !== '') {
            $where .= ' AND s.symbol = ?';
            $params[] = MarketDataService::normalizeSymbol($symbol);
        }
        $rows = Database::fetchAll(
            "SELECT s.id, s.symbol, s.direction, s.setup_type, s.session, s.quality_score, s.created_at,
                    o.outcome, o.r_multiple, o.max_favorable_r, o.max_adverse_r, o.ambiguous, o.exit_at
             FROM trading_signals s
             INNER JOIN signal_outcomes o ON o.signal_id = s.id
             WHERE $where
             ORDER BY s.created_at ASC",
            $params
        );

        $counts = ['total' => count($rows), 'open' => 0, 'pending' => 0, 'win' => 0, 'loss' => 0, 'breakeven' => 0, 'expired' => 0, 'invalidated' => 0];
        $closedR = [];
        $grossWinR = 0.0;
        $grossLossR = 0.0;
        $curve = [];
        $cumR = 0.0;
        $bySession = [];
        $bySetup = [];

        foreach ($rows as $r) {
            $out = $r['outcome'];
            if (isset($counts[$out])) {
                $counts[$out]++;
            }
            if (in_array($out, ['win', 'loss', 'breakeven'], true) && $r['r_multiple'] !== null) {
                $rm = (float)$r['r_multiple'];
                $closedR[] = $rm;
                $cumR = round($cumR + $rm, 3);
                if ($rm > 0) $grossWinR += $rm;
                elseif ($rm < 0) $grossLossR += abs($rm);
                $curve[] = [
                    'signal_id' => (int)$r['id'],
                    'time'      => $r['exit_at'] ?: $r['created_at'],
                    'r'         => $rm,
                    'cum_r'     => $cumR,
                    'outcome'   => $out,
                ];

                $sess = $r['session'] ?: 'Off-session';
                $bySession[$sess] ??= ['trades' => 0, 'wins' => 0, 'net_r' => 0.0];
                $bySession[$sess]['trades']++;
                if ($out === 'win') $bySession[$sess]['wins']++;
                $bySession[$sess]['net_r'] = round($bySession[$sess]['net_r'] + $rm, 2);

                $st = $r['setup_type'] ?: 'Standard SMC';
                $bySetup[$st] ??= ['trades' => 0, 'wins' => 0, 'net_r' => 0.0];
                $bySetup[$st]['trades']++;
                if ($out === 'win') $bySetup[$st]['wins']++;
                $bySetup[$st]['net_r'] = round($bySetup[$st]['net_r'] + $rm, 2);
            }
        }

        $resolved = $counts['win'] + $counts['loss'] + $counts['breakeven'];
        $winRate = $resolved > 0 ? round($counts['win'] / $resolved * 100.0, 2) : null;
        $expectancyR = $resolved > 0 ? round(array_sum($closedR) / $resolved, 3) : null;
        $profitFactor = $grossLossR > 0 ? round($grossWinR / $grossLossR, 2) : ($grossWinR > 0 ? 99.99 : null);

        // Consecutive Loss & Forward Degradation Monitoring (Section 6)
        $currentLossStreak = 0;
        $maxLossStreak = 0;
        foreach (array_reverse($rows) as $r) {
            $out = $r['outcome'];
            if ($out === 'loss') {
                $currentLossStreak++;
                if ($currentLossStreak > $maxLossStreak) $maxLossStreak = $currentLossStreak;
            } elseif ($out === 'win') {
                break;
            }
        }

        $forwardStage = 'COLLECTING_EVIDENCE';
        $requiresRevalidation = false;
        if ($resolved >= 10) {
            if (($winRate ?? 0) >= 38.0 && ($expectancyR ?? 0) >= 0.10 && ($profitFactor ?? 0) >= 1.25) {
                $forwardStage = 'VALIDATED_FORWARD';
            } else {
                $forwardStage = 'REVALIDATION_REQUIRED';
                $requiresRevalidation = true;
            }
        }

        return [
            'data_mode'             => $dataMode,
            'counts'                => $counts,
            'resolved'              => $resolved,
            'win_rate'              => $winRate,
            'expectancy_r'          => $expectancyR,
            'profit_factor'         => $profitFactor,
            'net_r'                 => round($cumR, 2),
            'gross_win_r'           => round($grossWinR, 2),
            'gross_loss_r'          => round($grossLossR, 2),
            'current_loss_streak'   => $currentLossStreak,
            'max_loss_streak'       => $maxLossStreak,
            'forward_stage'         => $forwardStage,
            'requires_revalidation' => $requiresRevalidation,
            'curve'                 => $curve,
            'by_session'            => $bySession,
            'by_setup'              => $bySetup,
            'note'                  => 'Forward test candle-replay tracking with look-ahead guards. Live execution requires reproducible positive expectancy.',
        ];
    }

    private function recordEvent(array $sig, string $eventType, ?float $price, int $eventTs, array $meta = []): void
    {
        $signalId = (int)$sig['id'];
        $stmt = Database::query(
            'INSERT IGNORE INTO signal_events (signal_id, event_type, price, event_time, meta, created_at)
             VALUES (?,?,?,?,?,?)',
            [
                $signalId,
                $eventType,
                $price,
                gmdate('Y-m-d H:i:s', $eventTs),
                $meta ? json_encode($meta) : null,
                gmdate('Y-m-d H:i:s'),
            ]
        );
        if ($stmt->rowCount() > 0) {
            Notifier::notifyTradeEvent($sig, $eventType, $price, $meta);
        }
    }
}
