<?php
declare(strict_types=1);

namespace App\Backtest;

use App\Analysis\MultiTimeframeAnalyzer;
use App\Analysis\RiskCalculator;
use App\Analysis\SessionService;
use App\Core\Database;
use App\Core\Settings;
use App\Market\MarketDataService;
use App\Market\Timeframe;

/**
 * Walk-forward historical SMC backtester.
 *
 * Strictly zero look-ahead bias:
 *  - Steps chronologically candle-by-candle on the entry timeframe.
 *  - At step time $stepNow, every timeframe is sliced so only candles with
 *    closeTime(tf, open_time) <= $stepNow are visible to MultiTimeframeAnalyzer.
 *  - Simulates limit-order fills, stop loss, partial close at TP1 + move SL to BE,
 *    conservative same-bar SL/TP handling, spread, commission, and position sizing.
 *  - Splits results into In-Sample (first 70% of date range) and Out-of-Sample (last 30%).
 */
final class Backtester
{
    private MarketDataService $market;

    public function __construct(?MarketDataService $market = null)
    {
        $this->market = $market ?? new MarketDataService();
    }

    /**
     * Execute a backtest run and persist to `backtest_runs` and `backtest_trades`.
     */
    public function run(array $input, ?int $userId = null): array
    {
        $symbol = MarketDataService::normalizeSymbol($input['symbol'] ?? $this->market->symbol());
        if ($this->market->symbol() !== $symbol) {
            $this->market = new MarketDataService(null, $symbol);
        }
        $symMeta = MarketDataService::symbolMeta($symbol);
        $entryTf = (string)($input['timeframe'] ?? Settings::get('market.entry_tf', 'M15'));
        if (!Timeframe::valid($entryTf)) {
            throw new \InvalidArgumentException("Invalid timeframe: $entryTf");
        }
        $dateFrom = (string)($input['date_from'] ?? gmdate('Y-m-d', time() - 14 * 86400));
        $dateTo = (string)($input['date_to'] ?? gmdate('Y-m-d'));
        $fromTs = (int)strtotime($dateFrom . ' 00:00:00 UTC');
        $toTs = (int)strtotime($dateTo . ' 23:59:59 UTC');
        if ($fromTs <= 0 || $toTs <= $fromTs) {
            throw new \InvalidArgumentException('date_to must be after date_from');
        }
        $maxSpanDays = 90;
        if (($toTs - $fromTs) > $maxSpanDays * 86400) {
            throw new \InvalidArgumentException("Backtest span cannot exceed $maxSpanDays days per run.");
        }

        $initialBalance = max(100.0, (float)($input['initial_balance'] ?? 10000.0));
        $riskPct = max(0.1, min((float)Settings::get('risk.max_risk_pct', 2.0), (float)($input['risk_percent'] ?? 0.5)));
        $minScore = max(20, min(95, (int)($input['min_score'] ?? Settings::get('signal.min_score', 55))));
        $minRr = max(1.0, min(10.0, (float)($input['min_rr'] ?? Settings::get('signal.min_rr', 2.0))));
        $inSampleRatio = 0.70;
        $splitTs = (int)($fromTs + ($toTs - $fromTs) * $inSampleRatio);

        $name = trim((string)($input['name'] ?? '')) ?: sprintf('%s %s (%s to %s)', $symbol, $entryTf, $dateFrom, $dateTo);
        $nowSql = gmdate('Y-m-d H:i:s');

        $paramsSnapshot = [
            'symbol'          => $symbol,
            'entry_tf'        => $entryTf,
            'initial_balance' => $initialBalance,
            'risk_percent'    => $riskPct,
            'min_score'       => $minScore,
            'min_rr'          => $minRr,
            'in_sample_ratio' => $inSampleRatio,
            'spread'          => (float)($symMeta['assumed_spread'] ?? Settings::get('signal.assumed_spread', 0.30)),
            'partial_at_tp1'  => (float)Settings::get('tracking.partial_at_tp1', 0.5),
            'move_be_tp1'     => (bool)Settings::get('tracking.move_be_after_tp1', true),
        ];

        $runId = Database::insert('backtest_runs', [
            'user_id'    => $userId,
            'name'       => mb_substr($name, 0, 120),
            'data_mode'  => $this->market->mode(),
            'timeframe'  => $entryTf,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'params'     => json_encode($paramsSnapshot),
            'status'     => 'running',
            'created_at' => $nowSql,
        ]);

        try {
            $marketCfg = (array)Settings::get('market');
            $marketCfg['symbol'] = $symbol;
            $marketCfg['entry_tf'] = $entryTf;
            $signalCfg = (array)Settings::get('signal');
            $signalCfg['min_score'] = $minScore;
            $signalCfg['min_rr'] = $minRr;
            if ($symbol !== 'XAUUSD') {
                $signalCfg['assumed_spread'] = (float)($symMeta['assumed_spread'] ?? $signalCfg['assumed_spread']);
                $signalCfg['max_spread'] = (float)($symMeta['max_spread'] ?? $signalCfg['max_spread']);
            }
            $smcCfg = (array)Settings::get('smc');

            $tfs = array_values(array_unique(array_merge(
                (array)$marketCfg['context_tfs'],
                (array)$marketCfg['bias_tfs'],
                [(string)$marketCfg['poi_tf'], $entryTf]
            )));

            // Load (and if demo mode, ensure availability of) historical candles with warmup history
            $allCandles = [];
            foreach ($tfs as $tf) {
                $warmup = max(250 * Timeframe::seconds($tf), 14 * 86400);
                $tfFrom = $fromTs - $warmup;
                $c = $this->market->candles($tf, 5000, $toTs, $tfFrom);
                if (count($c) < 120 && $this->market->mode() === 'demo') {
                    $gen = $this->market->provider()->fetchCandles($tf, 1200, $tfFrom, $toTs);
                    $this->market->store($tf, $gen);
                    $c = $this->market->candles($tf, 5000, $toTs, $tfFrom);
                }
                $allCandles[$tf] = $c;
            }

            $entrySeries = $allCandles[$entryTf] ?? [];
            if (count($entrySeries) < 60) {
                throw new \RuntimeException("Insufficient historical $entryTf candles in range ($dateFrom to $dateTo). Run bin/backfill.php first.");
            }

            $analyzer = new MultiTimeframeAnalyzer($smcCfg, $marketCfg, $signalCfg, new SessionService());
            $riskCalc = new RiskCalculator(null, $symbol);
            $partialFrac = max(0.0, min(1.0, (float)Settings::get('tracking.partial_at_tp1', 0.5)));
            $moveBe = (bool)Settings::get('tracking.move_be_after_tp1', true);
            $beBand = (float)Settings::get('tracking.breakeven_band_r', 0.05);
            $spread = (float)$signalCfg['assumed_spread'];

            $equity = $initialBalance;
            $peakEquity = $initialBalance;
            $maxDdPct = 0.0;
            $trades = [];
            $activeTrade = null; // currently pending or open trade

            for ($idx = 50, $totalC = count($entrySeries); $idx < $totalC; $idx++) {
                $bar = $entrySeries[$idx];
                $barCloseTs = Timeframe::closeTime($entryTf, $bar['t']);
                if ($barCloseTs < $fromTs) {
                    continue;
                }
                if ($bar['t'] > $toTs) {
                    break;
                }

                // 1. If there is a pending or open trade, advance it on $bar first
                if ($activeTrade !== null) {
                    $activeTrade = $this->stepTrade($activeTrade, $bar, $barCloseTs, $partialFrac, $moveBe, $beBand);
                    if ($activeTrade['closed']) {
                        if ($activeTrade['outcome'] !== 'expired' && $activeTrade['outcome'] !== 'invalidated') {
                            $grossPnl = round($activeTrade['r_multiple'] * $activeTrade['risk_usd'], 2);
                            $commCost = (float)($activeTrade['commission_usd'] ?? 0.0);
                            $spreadCost = (float)($activeTrade['spread_usd'] ?? 0.0);
                            $slipCost = (float)($activeTrade['slippage_usd'] ?? 0.0);
                            $pnl = round($grossPnl - $commCost - $spreadCost - $slipCost, 2);
                            $equity = round($equity + $pnl, 2);
                            $peakEquity = max($peakEquity, $equity);
                            if ($peakEquity > 0) {
                                $dd = ($peakEquity - $equity) / $peakEquity * 100.0;
                                $maxDdPct = max($maxDdPct, $dd);
                            }
                            $activeTrade['pnl'] = $pnl;
                            $activeTrade['equity_after'] = $equity;
                            $trades[] = $activeTrade;
                        }
                        $activeTrade = null;
                    }
                }

                // 2. Only evaluate a new signal if no trade is currently active
                if ($activeTrade === null) {
                    // Quick pre-check: only run full multi-TF analyzer on candles within our backtest window
                    $sliced = [];
                    foreach ($tfs as $tf) {
                        $sliced[$tf] = self::sliceClosedUntil($allCandles[$tf], $tf, $barCloseTs, 350);
                    }
                    $ctx = [
                        'now'            => $barCloseTs,
                        'price'          => $bar['c'],
                        'spread'         => $spread,
                        'spread_source'  => 'assumed',
                        'events'         => [],
                        'news_connected' => true,
                        'data_mode'      => $this->market->mode(),
                        'source'         => $this->market->provider()->label(),
                    ];
                    $res = $analyzer->analyze($sliced, $ctx);
                    $sig = $res['signal'] ?? [];
                    if (in_array($sig['direction'] ?? 'WAIT', ['BUY', 'SELL'], true)
                        && in_array($sig['setup_status'] ?? '', ['confirmed', 'triggered'], true)
                        && !empty($sig['entry']) && !empty($sig['sl']) && !empty($sig['tp1'])
                    ) {
                        $sizing = $riskCalc->calculate($equity, $riskPct, (float)$sig['entry'], (float)$sig['sl'], (float)$sig['tp1'], isset($sig['tp2']) ? (float)$sig['tp2'] : null);
                        if (!empty($sizing['ok'])) {
                            $lots = (float)$sizing['lots'];
                            $contract = (float)($symMeta['contract_size'] ?? 100.0);
                            $spreadUsd = round($lots * $contract * $spread, 2);
                            $slippageUsd = round($lots * $contract * (float)Settings::get('risk.assumed_slippage_handles', 0.15), 2);
                            $activeTrade = [
                                'segment'        => $bar['t'] <= $splitTs ? 'in_sample' : 'out_of_sample',
                                'direction'      => $sig['direction'],
                                'setup_type'     => $sig['setup_type'] ?? 'SMC Setup',
                                'session'        => $res['session'] ?? 'Off-session',
                                'regime'         => $res['regime'] ?? 'ranging_consolidation',
                                'quality_score'  => (int)$sig['quality_score'],
                                'signal_time'    => $barCloseTs,
                                'expires_at'     => (int)($sig['expires_at'] ?? ($barCloseTs + 24 * Timeframe::seconds($entryTf))),
                                'entry_time'     => $sig['setup_status'] === 'triggered' ? $bar['t'] : null,
                                'filled'         => $sig['setup_status'] === 'triggered',
                                'entry_price'    => (float)$sig['entry'],
                                'stop_loss'      => (float)$sig['sl'],
                                'current_sl'     => (float)$sig['sl'],
                                'tp1'            => (float)$sig['tp1'],
                                'tp2'            => isset($sig['tp2']) ? (float)$sig['tp2'] : null,
                                'tp1_hit'        => false,
                                'rem_weight'     => 1.0,
                                'realized_r'     => 0.0,
                                'lots'           => $lots,
                                'risk_usd'       => (float)$sizing['actual_risk_usd'],
                                'commission_usd' => (float)$sizing['commission_usd'],
                                'spread_usd'     => $spreadUsd,
                                'slippage_usd'   => $slippageUsd,
                                'ambiguous'      => 0,
                                'closed'         => false,
                                'exit_time'      => null,
                                'exit_price'     => null,
                                'exit_reason'    => '',
                                'outcome'        => 'open',
                                'r_multiple'     => 0.0,
                                'pnl'            => 0.0,
                                'equity_after'   => $equity,
                            ];
                        }
                    }
                }
            }

            $summary = $this->summarize($trades, $initialBalance, $equity, $maxDdPct, $splitTs);

            Database::transaction(function () use ($runId, $trades, $summary) {
                $stmt = Database::pdo()->prepare(
                    'INSERT INTO backtest_trades
                     (run_id, trade_no, segment, direction, setup_type, session, quality_score,
                      signal_time, entry_time, entry_price, stop_loss, tp1, tp2,
                      exit_time, exit_price, exit_reason, outcome, r_multiple, lots, pnl, equity_after, ambiguous)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                foreach ($trades as $i => $t) {
                    $stmt->execute([
                        $runId,
                        $i + 1,
                        $t['segment'],
                        $t['direction'],
                        $t['setup_type'],
                        $t['session'],
                        $t['quality_score'],
                        gmdate('Y-m-d H:i:s', $t['signal_time']),
                        $t['entry_time'] ? gmdate('Y-m-d H:i:s', $t['entry_time']) : null,
                        $t['entry_price'],
                        $t['stop_loss'],
                        $t['tp1'],
                        $t['tp2'],
                        $t['exit_time'] ? gmdate('Y-m-d H:i:s', $t['exit_time']) : null,
                        $t['exit_price'],
                        $t['exit_reason'],
                        $t['outcome'],
                        $t['r_multiple'],
                        $t['lots'],
                        $t['pnl'],
                        $t['equity_after'],
                        $t['ambiguous'],
                    ]);
                }

                Database::update('backtest_runs', [
                    'status'           => 'completed',
                    'total_trades'     => $summary['total_trades'],
                    'win_rate'         => $summary['win_rate'],
                    'expectancy_r'     => $summary['expectancy_r'],
                    'profit_factor'    => $summary['profit_factor'],
                    'max_drawdown_pct' => $summary['max_drawdown_pct'],
                    'net_r'            => $summary['net_r'],
                    'ending_equity'    => $summary['ending_equity'],
                    'stats'            => json_encode($summary),
                    'finished_at'      => gmdate('Y-m-d H:i:s'),
                ], 'id = ?', [$runId]);
            });

            return ['ok' => true, 'run_id' => $runId, 'summary' => $summary, 'trades' => $trades];
        } catch (\Throwable $e) {
            Database::update('backtest_runs', [
                'status'      => 'failed',
                'error'       => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ], 'id = ?', [$runId]);
            throw $e;
        }
    }

    private function stepTrade(array $t, array $bar, int $barCloseTs, float $partialFrac, bool $moveBe, float $beBand): array
    {
        $isBuy = $t['direction'] === 'BUY';
        $entry = $t['entry_price'];
        $sl = $t['stop_loss'];
        $risk = abs($entry - $sl);

        if (!$t['filled']) {
            if ($isBuy ? $bar['c'] <= $sl : $bar['c'] >= $sl) {
                $t['closed'] = true;
                $t['outcome'] = 'invalidated';
                $t['exit_reason'] = 'SL breached before entry';
                return $t;
            }
            if ($bar['t'] >= $t['expires_at']) {
                $t['closed'] = true;
                $t['outcome'] = 'expired';
                $t['exit_reason'] = 'Expired unfilled';
                return $t;
            }
            $touchedEntry = $isBuy ? ($bar['l'] <= $entry) : ($bar['h'] >= $entry);
            $touchedTp1 = $t['tp1'] !== null && ($isBuy ? ($bar['h'] >= $t['tp1']) : ($bar['l'] <= $t['tp1']));
            if (!$touchedEntry && $touchedTp1) {
                $t['closed'] = true;
                $t['outcome'] = 'expired';
                $t['exit_reason'] = 'TP1 reached before fill';
                return $t;
            }
            if ($touchedEntry) {
                $t['filled'] = true;
                $t['entry_time'] = $bar['t'];
            } else {
                return $t;
            }
        }

        // Trade is open on $bar
        $curSl = $t['current_sl'];
        $hitSl = $isBuy ? ($bar['l'] <= $curSl) : ($bar['h'] >= $curSl);
        $hitTp1 = !$t['tp1_hit'] && $t['tp1'] !== null && ($isBuy ? ($bar['h'] >= $t['tp1']) : ($bar['l'] <= $t['tp1']));
        $hitTp2 = $t['tp2'] !== null && ($isBuy ? ($bar['h'] >= $t['tp2']) : ($bar['l'] <= $t['tp2']));

        if ($hitSl && ($hitTp1 || $hitTp2)) {
            $t['ambiguous'] = 1;
        }
        // Conservative policy: evaluate SL first
        if ($hitSl) {
            $slR = $isBuy ? ($curSl - $entry) / $risk : ($entry - $curSl) / $risk;
            $finalR = round($t['realized_r'] + $t['rem_weight'] * $slR, 3);
            $t['closed'] = true;
            $t['exit_time'] = $barCloseTs;
            $t['exit_price'] = $curSl;
            $t['exit_reason'] = $t['tp1_hit'] && $moveBe ? 'Breakeven after TP1' : 'Stop Loss';
            $t['r_multiple'] = $finalR;
            $t['outcome'] = abs($finalR) <= $beBand ? 'breakeven' : ($finalR > 0 ? 'win' : 'loss');
            return $t;
        }

        if ($hitTp1) {
            $t['tp1_hit'] = true;
            $tp1R = abs($t['tp1'] - $entry) / $risk;
            if ($t['tp2'] === null) {
                $t['closed'] = true;
                $t['exit_time'] = $barCloseTs;
                $t['exit_price'] = $t['tp1'];
                $t['exit_reason'] = 'TP1 target';
                $t['r_multiple'] = round($tp1R, 3);
                $t['outcome'] = 'win';
                return $t;
            }
            $t['realized_r'] += $partialFrac * $tp1R;
            $t['rem_weight'] = 1.0 - $partialFrac;
            if ($moveBe) {
                $t['current_sl'] = $entry;
            }
        }

        if ($hitTp2) {
            $tp2R = abs($t['tp2'] - $entry) / $risk;
            $t['closed'] = true;
            $t['exit_time'] = $barCloseTs;
            $t['exit_price'] = $t['tp2'];
            $t['exit_reason'] = 'TP2 target';
            $t['r_multiple'] = round($t['realized_r'] + $t['rem_weight'] * $tp2R, 3);
            $t['outcome'] = 'win';
            return $t;
        }

        return $t;
    }

    private function summarize(array $trades, float $initialBalance, float $endingEquity, float $maxDdPct, int $splitTs): array
    {
        $calcSegment = static function (array $list, float $initBal = 10000.0): array {
            $n = count($list);
            $wins = 0; $losses = 0; $be = 0;
            $grossWinR = 0.0; $grossLossR = 0.0; $netR = 0.0;
            $winPnlUsd = 0.0; $lossPnlUsd = 0.0; $netPnlUsd = 0.0;
            $totalLots = 0.0; $totalComm = 0.0; $totalSpread = 0.0; $totalSlip = 0.0;
            $tradeReturns = [];

            $currLossStreak = 0; $maxLossStreak = 0;
            $currWinStreak = 0;  $maxWinStreak = 0;

            foreach ($list as $t) {
                $r = (float)$t['r_multiple'];
                $pnl = (float)($t['pnl'] ?? ($r * ($t['risk_usd'] ?? 50.0)));
                $netR += $r;
                $netPnlUsd += $pnl;
                $totalLots += (float)($t['lots'] ?? 0.0);
                $totalComm += (float)($t['commission_usd'] ?? 0.0);
                $totalSpread += (float)($t['spread_usd'] ?? 0.0);
                $totalSlip += (float)($t['slippage_usd'] ?? 0.0);
                $tradeReturns[] = $initBal > 0 ? ($pnl / $initBal) : 0.0;

                if ($t['outcome'] === 'win') {
                    $wins++;
                    $grossWinR += max(0.0, $r);
                    $winPnlUsd += max(0.0, $pnl);
                    $currWinStreak++;
                    $currLossStreak = 0;
                    if ($currWinStreak > $maxWinStreak) $maxWinStreak = $currWinStreak;
                } elseif ($t['outcome'] === 'loss') {
                    $losses++;
                    $grossLossR += abs(min(0.0, $r));
                    $lossPnlUsd += abs(min(0.0, $pnl));
                    $currLossStreak++;
                    $currWinStreak = 0;
                    if ($currLossStreak > $maxLossStreak) $maxLossStreak = $currLossStreak;
                } else {
                    $be++;
                    $currWinStreak = 0;
                    $currLossStreak = 0;
                }
            }

            // Annualized Sharpe Ratio calculation (Section 2)
            $sharpe = null;
            if ($n >= 4) {
                $meanRet = array_sum($tradeReturns) / $n;
                $variance = 0.0;
                foreach ($tradeReturns as $ret) {
                    $variance += pow($ret - $meanRet, 2);
                }
                $stdDev = sqrt($variance / max(1, $n - 1));
                if ($stdDev > 0.00001) {
                    $sharpe = round(($meanRet / $stdDev) * sqrt(min(252, $n * 4)), 2);
                }
            }

            $avgWinUsd = $wins > 0 ? round($winPnlUsd / $wins, 2) : 0.0;
            $avgLossUsd = $losses > 0 ? round($lossPnlUsd / $losses, 2) : 0.0;
            $avgWinR = $wins > 0 ? round($grossWinR / $wins, 2) : 0.0;
            $avgLossR = $losses > 0 ? round($grossLossR / $losses, 2) : 0.0;
            $winRate = $n > 0 ? round($wins / $n * 100.0, 2) : null;
            $expectancyR = $n > 0 ? round($netR / $n, 3) : null;
            $expectancyUsd = $n > 0 ? round($netPnlUsd / $n, 2) : null;
            $profitFactor = $grossLossR > 0 ? round($grossWinR / $grossLossR, 2) : ($grossWinR > 0 ? 99.99 : null);

            return [
                'trades'                 => $n,
                'wins'                   => $wins,
                'losses'                 => $losses,
                'breakevens'             => $be,
                'win_rate'               => $winRate,
                'expectancy_r'           => $expectancyR,
                'expectancy_usd'         => $expectancyUsd,
                'profit_factor'          => $profitFactor,
                'net_r'                  => round($netR, 2),
                'net_pnl_usd'            => round($netPnlUsd, 2),
                'avg_win_usd'            => $avgWinUsd,
                'avg_loss_usd'           => $avgLossUsd,
                'avg_win_r'              => $avgWinR,
                'avg_loss_r'             => $avgLossR,
                'win_loss_ratio'         => $avgLossR > 0 ? round($avgWinR / $avgLossR, 2) : null,
                'sharpe_ratio'           => $sharpe,
                'max_consecutive_losses' => $maxLossStreak,
                'max_consecutive_wins'   => $maxWinStreak,
                'total_lots'             => round($totalLots, 2),
                'execution_costs'        => [
                    'commission_usd' => round($totalComm, 2),
                    'spread_usd'     => round($totalSpread, 2),
                    'slippage_usd'   => round($totalSlip, 2),
                    'total_cost_usd' => round($totalComm + $totalSpread + $totalSlip, 2),
                ],
            ];
        };

        $allStats = $calcSegment($trades, $initialBalance);
        $isTrades = array_values(array_filter($trades, static fn($t) => $t['segment'] === 'in_sample'));
        $oosTrades = array_values(array_filter($trades, static fn($t) => $t['segment'] === 'out_of_sample'));
        $oos = $calcSegment($oosTrades, $initialBalance);
        $is  = $calcSegment($isTrades, $initialBalance);

        // Performance by Market Regime (Section 3)
        $byRegime = [];
        $bySession = [];
        foreach ($trades as $t) {
            $reg = $t['regime'] ?? 'ranging_consolidation';
            $byRegime[$reg] ??= ['trades' => 0, 'wins' => 0, 'losses' => 0, 'net_r' => 0.0, 'net_pnl' => 0.0];
            $byRegime[$reg]['trades']++;
            if ($t['outcome'] === 'win') $byRegime[$reg]['wins']++;
            elseif ($t['outcome'] === 'loss') $byRegime[$reg]['losses']++;
            $byRegime[$reg]['net_r'] = round($byRegime[$reg]['net_r'] + (float)$t['r_multiple'], 2);
            $byRegime[$reg]['net_pnl'] = round($byRegime[$reg]['net_pnl'] + (float)$t['pnl'], 2);

            $sess = $t['session'] ?: 'Off-session';
            $bySession[$sess] ??= ['trades' => 0, 'wins' => 0, 'losses' => 0, 'net_r' => 0.0, 'net_pnl' => 0.0];
            $bySession[$sess]['trades']++;
            if ($t['outcome'] === 'win') $bySession[$sess]['wins']++;
            elseif ($t['outcome'] === 'loss') $bySession[$sess]['losses']++;
            $bySession[$sess]['net_r'] = round($bySession[$sess]['net_r'] + (float)$t['r_multiple'], 2);
            $bySession[$sess]['net_pnl'] = round($bySession[$sess]['net_pnl'] + (float)$t['pnl'], 2);
        }

        // Stress Testing (Slippage + Spread Widening)
        $stressSlipExtra = (float)Settings::get('validation.stress_slippage_handles', 0.25);
        $stressSpreadMult = (float)Settings::get('validation.stress_spread_mult', 1.5);
        $stressTrades = [];
        foreach ($trades as $t) {
            $st = $t;
            $lots = (float)($t['lots'] ?? 0.1);
            $contract = 100.0;
            $extraCost = ($lots * $contract * $stressSlipExtra) + ((float)($t['spread_usd'] ?? 0) * ($stressSpreadMult - 1.0));
            $st['pnl'] = round((float)$t['pnl'] - $extraCost, 2);
            $risk = max(1.0, (float)($t['risk_usd'] ?? 50.0));
            $st['r_multiple'] = round((float)$t['r_multiple'] - ($extraCost / $risk), 3);
            if ($st['r_multiple'] < 0 && $t['outcome'] === 'win') {
                $st['outcome'] = 'loss';
            }
            $stressTrades[] = $st;
        }
        $stressStats = $calcSegment($stressTrades, $initialBalance);
        $stressStatus = ($stressStats['net_r'] > 0 && ($stressStats['profit_factor'] ?? 0) >= 1.05)
            ? 'PASSED_STRESS'
            : (($stressStats['net_r'] >= -1.0) ? 'MARGINAL' : 'FAILED_STRESS');

        // Quantitative Out-of-Sample Validation Criteria Evaluation (Section 2 & 7)
        $vCfg = (array)Settings::get('validation', []);
        $minOosTrades = (int)($vCfg['min_oos_trades'] ?? 10);
        $minExpR      = (float)($vCfg['min_expectancy_r'] ?? 0.10);
        $minPf        = (float)($vCfg['min_profit_factor'] ?? 1.25);
        $minWinRate   = (float)($vCfg['min_win_rate_pct'] ?? 38.0);
        $maxDdLimit   = (float)($vCfg['max_drawdown_pct'] ?? 15.0);
        $maxDegradPct = (float)($vCfg['max_oos_degradation_pct'] ?? 40.0);

        $degradationPct = ($is['expectancy_r'] > 0 && $oos['expectancy_r'] !== null)
            ? max(0.0, round((($is['expectancy_r'] - $oos['expectancy_r']) / $is['expectancy_r']) * 100.0, 1))
            : 0.0;

        $criteria = [
            'sample_size' => [
                'name'     => 'Unseen Out-of-Sample Trades',
                'target'   => ">= {$minOosTrades} trades",
                'actual'   => "{$oos['trades']} trades",
                'passed'   => $oos['trades'] >= $minOosTrades,
            ],
            'expectancy' => [
                'name'     => 'Net Expectancy per Trade',
                'target'   => ">= +{$minExpR}R",
                'actual'   => sprintf('%+.2fR ($%+.2f)', $oos['expectancy_r'] ?? 0, $oos['expectancy_usd'] ?? 0),
                'passed'   => ($oos['expectancy_r'] ?? 0) >= $minExpR,
            ],
            'profit_factor' => [
                'name'     => 'Out-of-Sample Profit Factor',
                'target'   => ">= {$minPf}",
                'actual'   => sprintf('%.2f', $oos['profit_factor'] ?? 0),
                'passed'   => ($oos['profit_factor'] ?? 0) >= $minPf,
            ],
            'win_rate' => [
                'name'     => 'Out-of-Sample Win Rate',
                'target'   => ">= {$minWinRate}%",
                'actual'   => sprintf('%.1f%%', $oos['win_rate'] ?? 0),
                'passed'   => ($oos['win_rate'] ?? 0) >= $minWinRate,
            ],
            'drawdown' => [
                'name'     => 'Maximum Drawdown',
                'target'   => "<= {$maxDdLimit}%",
                'actual'   => sprintf('%.1f%%', $maxDdPct),
                'passed'   => $maxDdPct <= $maxDdLimit,
            ],
            'degradation' => [
                'name'     => 'Walk-Forward Performance Drop',
                'target'   => "<= {$maxDegradPct}% drop",
                'actual'   => sprintf('%.1f%% drop', $degradationPct),
                'passed'   => $degradationPct <= $maxDegradPct,
            ],
            'stress_test' => [
                'name'     => 'Slippage & Spread Stress Test',
                'target'   => 'Survive adverse slippage & spread',
                'actual'   => sprintf('%s (PF %.2f, Net %+.1fR)', $stressStatus, $stressStats['profit_factor'] ?? 0, $stressStats['net_r']),
                'passed'   => $stressStatus !== 'FAILED_STRESS',
            ],
        ];

        $allPassed = true;
        foreach ($criteria as $c) {
            if (!$c['passed']) {
                $allPassed = false;
                break;
            }
        }
        $validationStatus = $allPassed ? 'HISTORICALLY_VALIDATED' : 'NOT_VALIDATED';

        $curve = [['trade_no' => 0, 'time' => null, 'equity' => $initialBalance, 'cum_r' => 0.0]];
        $cumR = 0.0;
        foreach ($trades as $i => $t) {
            $cumR = round($cumR + (float)$t['r_multiple'], 3);
            $curve[] = [
                'trade_no' => $i + 1,
                'time'     => gmdate('Y-m-d H:i', (int)$t['exit_time']),
                'equity'   => $t['equity_after'],
                'cum_r'    => $cumR,
                'segment'  => $t['segment'],
            ];
        }

        return [
            'total_trades'           => $allStats['trades'],
            'wins'                   => $allStats['wins'],
            'losses'                 => $allStats['losses'],
            'breakevens'             => $allStats['breakevens'],
            'win_rate'               => $allStats['win_rate'],
            'expectancy_r'           => $allStats['expectancy_r'],
            'expectancy_usd'         => $allStats['expectancy_usd'],
            'profit_factor'          => $allStats['profit_factor'],
            'net_r'                  => $allStats['net_r'],
            'initial_balance'        => round($initialBalance, 2),
            'ending_equity'          => round($endingEquity, 2),
            'net_profit_usd'         => round($endingEquity - $initialBalance, 2),
            'return_pct'             => round(($endingEquity - $initialBalance) / $initialBalance * 100.0, 2),
            'max_drawdown_pct'       => round($maxDdPct, 2),
            'sharpe_ratio'           => $allStats['sharpe_ratio'],
            'max_consecutive_losses' => $allStats['max_consecutive_losses'],
            'max_consecutive_wins'   => $allStats['max_consecutive_wins'],
            'avg_win_usd'            => $allStats['avg_win_usd'],
            'avg_loss_usd'           => $allStats['avg_loss_usd'],
            'avg_win_r'              => $allStats['avg_win_r'],
            'avg_loss_r'             => $allStats['avg_loss_r'],
            'win_loss_ratio'         => $allStats['win_loss_ratio'],
            'execution_costs'        => $allStats['execution_costs'],
            'split_time_utc'         => gmdate('Y-m-d H:i', $splitTs),
            'in_sample'              => $is,
            'out_of_sample'          => $oos,
            'by_regime'              => $byRegime,
            'by_session'             => $bySession,
            'stress_test'            => [
                'status'        => $stressStatus,
                'stats'         => $stressStats,
                'slippage_add'  => $stressSlipExtra,
                'spread_mult'   => $stressSpreadMult,
            ],
            'validation'             => [
                'status'           => $validationStatus,
                'is_validated'     => $allPassed,
                'criteria'         => $criteria,
                'degradation_pct'  => $degradationPct,
            ],
            'equity_curve'           => $curve,
            'disclosure'             => 'DISCLOSURE: Simulated backtest results have inherent limitations. Positive historical expectancy and passing validation criteria do not guarantee future profits or immunity from risk.',
        ];
    }

    /**
     * Binary-search slice of candles whose close time is <= $nowTs.
     */
    private static function sliceClosedUntil(array $candles, string $tf, int $nowTs, int $maxCount): array
    {
        $n = count($candles);
        if ($n === 0) return [];
        $lo = 0;
        $hi = $n - 1;
        $endIdx = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if (Timeframe::closeTime($tf, $candles[$mid]['t']) <= $nowTs) {
                $endIdx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($endIdx < 0) return [];
        $start = max(0, $endIdx - $maxCount + 1);
        return array_slice($candles, $start, $endIdx - $start + 1);
    }
}
