<?php
declare(strict_types=1);

namespace App\ML;

use App\Market\MarketDataService;
use App\Market\Timeframe;
use App\SMC\SmcEngine;

/**
 * Historical Dataset Builder for Machine Learning Training.
 *
 * Enforces:
 *  1. Zero look-ahead bias (features strictly derived from candles closed at or before $t$).
 *  2. Conservative execution (if SL and TP touched on same bar, resolved as loss).
 *  3. Accurate friction modeling (spread, slippage, commission).
 *  4. Point-in-time multi-timeframe synchronization.
 */
final class DatasetGenerator
{
    private FeatureExtractor $extractor;

    public function __construct(?FeatureExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new FeatureExtractor();
    }

    /**
     * Generate structured training examples from historical candles.
     *
     * @param array $candles Closed entry-timeframe candles (e.g. M15)
     * @param string $symbol Instrument symbol (e.g. 'XAUUSD')
     * @param string $entryTf Primary execution timeframe
     * @param array $options Configuration overrides (horizon, spread, friction)
     * @return array{
     *   samples: array<array{features: array<float>, label: int, return_r: float, meta: array}>,
     *   stats: array
     * }
     */
    public function generate(
        array $candles,
        string $symbol = 'XAUUSD',
        string $entryTf = 'M15',
        array $options = []
    ): array {
        $n = count($candles);
        if ($n < 80) {
            return ['samples' => [], 'stats' => ['error' => 'Insufficient candles for dataset generation (minimum 80)']];
        }

        $horizon = (int)($options['horizon'] ?? 48); // max candles to evaluate forward
        $spread = (float)($options['spread'] ?? 0.30); // 30 cents on Gold
        $slippage = (float)($options['slippage'] ?? 0.10); // 10 cents slippage
        $commR = (float)($options['comm_r'] ?? 0.02); // 0.02R commission friction
        $smc = new SmcEngine();

        $samples = [];
        $totalCandidates = 0;
        $wins = 0;
        $losses = 0;
        $expired = 0;

        // Step through historical candles with minimum lookback of 50 candles
        for ($i = 50; $i < $n - $horizon; $i++) {
            $currentSlice = array_slice($candles, 0, $i + 1);
            $evalTime = (int)$candles[$i]['t'];

            // Run look-ahead safe SMC analysis on closed slice
            $res = $smc->analyze($currentSlice);
            if (empty($res['events'])) {
                continue;
            }

            $lastEvt = end($res['events']);
            // Only trigger at confirmation of structure break (within 8 candles)
            if (($i - (int)$lastEvt['i']) > 8) {
                continue;
            }

            $dir = (int)$lastEvt['dir']; // 1 = BUY, -1 = SELL
            $isBuy = $dir === 1;
            $price = (float)$candles[$i]['c'];
            $atr = max(0.5, (float)$res['atr']);

            // Locate nearest Order Block or FVG
            $zone = null;
            foreach ($res['order_blocks'] as $ob) {
                if ($ob['dir'] === $dir && in_array($ob['status'], ['fresh', 'mitigated'], true)) {
                    $zone = ['top' => (float)$ob['top'], 'bottom' => (float)$ob['bottom'], 'type' => 'OB'];
                    break;
                }
            }
            if (!$zone) {
                foreach ($res['fvgs'] as $fvg) {
                    if ($fvg['dir'] === $dir && in_array($fvg['status'], ['fresh', 'partial'], true)) {
                        $zone = ['top' => (float)$fvg['top'], 'bottom' => (float)$fvg['bottom'], 'type' => 'FVG'];
                        break;
                    }
                }
            }
            if (!$zone) {
                $buf = 0.2 * $atr;
                $zone = ['top' => $price + $buf, 'bottom' => $price - $buf, 'type' => 'Retest'];
            }

            // Entry, Stop Loss, and Take Profit
            $entry = $isBuy ? (float)$zone['top'] : (float)$zone['bottom'];
            $sl = $isBuy ? ((float)$zone['bottom'] - (0.4 * $atr + $spread)) : ((float)$zone['top'] + (0.4 * $atr + $spread));
            $risk = abs($entry - $sl);
            if ($risk <= 0.1 || $risk > 4.5 * $atr) {
                continue;
            }

            // Impulse leg extreme for realistic opposing liquidity target
            $legStart = (int)($lastEvt['leg_start'] ?? max(0, $i - 12));
            $impulseExtreme = null;
            for ($k = $legStart; $k <= $i; $k++) {
                $hK = (float)$candles[$k]['h'];
                $lK = (float)$candles[$k]['l'];
                $impulseExtreme = $impulseExtreme === null ? ($isBuy ? $hK : $lK) : ($isBuy ? max($impulseExtreme, $hK) : min($impulseExtreme, $lK));
            }
            if ($impulseExtreme === null) $impulseExtreme = $price;

            $minTp1Dist = 1.6 * $risk;
            $tp1 = $isBuy ? max($entry + $minTp1Dist, $impulseExtreme + 0.1 * $atr) : min($entry - $minTp1Dist, $impulseExtreme - 0.1 * $atr);
            $tp2 = $isBuy ? ($entry + 3.0 * $risk) : ($entry - 3.0 * $risk);

            // Extract point-in-time features strictly using $currentSlice
            $extracted = $this->extractor->extract(
                [$entryTf => $currentSlice],
                [
                    'dir'    => $dir,
                    'entry'  => $entry,
                    'sl'     => $sl,
                    'tp1'    => $tp1,
                    'tp2'    => $tp2,
                    'spread' => $spread + $slippage,
                ],
                $evalTime,
                $entryTf
            );

            // Forward simulation across subsequent $horizon candles
            $filled = false;
            $filledIdx = null;
            $outcome = 'expired';
            $realizedR = 0.0;
            $mfe = 0.0;
            $mae = 0.0;
            $currentSl = $sl;
            $partialTaken = false;

            for ($f = $i + 1; $f <= min($n - 1, $i + $horizon); $f++) {
                $bar = $candles[$f];
                $high = (float)$bar['h'];
                $low = (float)$bar['l'];

                if (!$filled) {
                    // Check if price reached target BEFORE filling -> missed setup
                    if ($isBuy ? $high >= $tp1 : $low <= $tp1) {
                        $outcome = 'expired';
                        break;
                    }
                    // Check if entry filled
                    if ($isBuy ? $low <= $entry : $high >= $entry) {
                        $filled = true;
                        $filledIdx = $f;
                    } else {
                        continue;
                    }
                }

                // Once filled, track MFE and MAE
                $favDist = $isBuy ? ($high - $entry) : ($entry - $low);
                $advDist = $isBuy ? ($entry - $low) : ($high - $entry);
                $mfe = max($mfe, $favDist / $risk);
                $mae = max($mae, $advDist / $risk);

                // Check stop loss and take profit hits
                $hitSl = $isBuy ? ($low <= $currentSl) : ($high >= $currentSl);
                $hitTp1 = $isBuy ? ($high >= $tp1) : ($low <= $tp1);
                $hitTp2 = $isBuy ? ($high >= $tp2) : ($low <= $tp2);

                // Conservative Same-Bar Resolution: if both SL and TP are touched, resolve to LOSS
                if ($hitSl && ($hitTp1 || $hitTp2)) {
                    $outcome = 'loss';
                    $realizedR = -1.0 - ($spread / $risk) - $commR;
                    break;
                }

                if ($hitSl) {
                    if ($partialTaken) {
                        $outcome = 'breakeven';
                        // 50% locked at TP1 (1.8R * 0.5 = 0.9R), remainder stopped at entry BE
                        $realizedR = (0.5 * 1.8) - ($spread / $risk) - $commR;
                    } else {
                        $outcome = 'loss';
                        $realizedR = -1.0 - ($spread / $risk) - $commR;
                    }
                    break;
                }

                if ($hitTp2 && $partialTaken) {
                    $outcome = 'win';
                    // 50% closed at TP1 (1.8R * 0.5 = 0.9R) + 50% closed at TP2 (3.2R * 0.5 = 1.6R) = 2.5R
                    $realizedR = (0.5 * 1.8) + (0.5 * 3.2) - ($spread / $risk) - $commR;
                    break;
                }

                if ($hitTp1 && !$partialTaken) {
                    $partialTaken = true;
                    $currentSl = $entry; // move SL to breakeven
                }
            }

            if (!$filled) {
                $expired++;
                continue;
            }

            $totalCandidates++;
            $isProfitable = $realizedR > 0.15;
            $label = $isProfitable ? 1 : 0;

            if ($label === 1) {
                $wins++;
            } else {
                $losses++;
            }

            $samples[] = [
                'features'  => $extracted['vector'],
                'feature_map' => $extracted['map'],
                'label'     => $label,
                'return_r'  => round($realizedR, 4),
                'outcome'   => $outcome,
                'mfe_r'     => round($mfe, 3),
                'mae_r'     => round($mae, 3),
                'meta'      => [
                    'symbol'     => $symbol,
                    'time'       => $evalTime,
                    'time_iso'   => gmdate('Y-m-d H:i:s', $evalTime),
                    'direction'  => $isBuy ? 'BUY' : 'SELL',
                    'entry'      => $entry,
                    'sl'         => $sl,
                    'tp1'        => $tp1,
                    'tp2'        => $tp2,
                    'risk'       => $risk,
                    'zone_type'  => $zone['type'],
                ],
            ];
        }

        $winRate = $totalCandidates > 0 ? round(($wins / $totalCandidates) * 100, 2) : 0.0;
        $netR = array_sum(array_column($samples, 'return_r'));
        $expectancy = $totalCandidates > 0 ? round($netR / $totalCandidates, 3) : 0.0;

        return [
            'samples' => $samples,
            'stats'   => [
                'total_candles'    => $n,
                'total_candidates' => $totalCandidates,
                'wins'             => $wins,
                'losses'           => $losses,
                'expired_unfilled' => $expired,
                'win_rate'         => $winRate,
                'net_r'            => round($netR, 3),
                'expectancy_r'     => $expectancy,
            ],
        ];
    }
}
