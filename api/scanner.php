<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AnalysisService;
use App\Core\Auth;
use App\Core\Response;
use App\Market\MarketDataService;
use App\SMC\IctEngine;
use App\SMC\SmcEngine;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$symbols = ['XAUUSD', 'BTCUSD'];
$timeframes = ['M5', 'M15', 'H1', 'H4'];

$results = [];
$now = time();
$kz = IctEngine::killzones($now);
$macro = IctEngine::macroWindows($now);

foreach ($symbols as $sym) {
    $market = new MarketDataService(null, $sym);
    $quote = $market->quote(false);
    $curPrice = $quote['available'] ? (float)$quote['price'] : 0.0;

    foreach ($timeframes as $tf) {
        try {
            $candles = $market->candles($tf, 80);
            if (count($candles) < 30) {
                continue;
            }
            $smc = (new SmcEngine())->analyze($candles);
            $ict = (new IctEngine())->analyze($candles, $smc, $now);

            $trend = $smc['trend'] ?? 0;
            $dirLabel = $trend === 1 ? 'BUY' : ($trend === -1 ? 'SELL' : 'NO TRADE');
            $atr = $smc['atr'] ?? 1.0;

            $primaryStrat = 'SMC Trend Following';
            $detected = !empty($ict['detected_strategies']);
            if ($detected) {
                $primaryStrat = $ict['detected_strategies'][0]['name'];
            }

            // Estimate score
            $score = 50;
            if ($trend !== 0) $score += 20;
            if ($detected) $score += 20;
            if ($kz['is_killzone']) $score += 10;

            $status = ($score >= 80) ? 'CONFIRMED SETUP' : (($score >= 60) ? 'WAITING' : 'NO TRADE');

            $entry = $curPrice;
            $sl = $trend === 1 ? round($entry - 1.5 * $atr, 2) : round($entry + 1.5 * $atr, 2);
            $risk = max(0.1, abs($entry - $sl));
            $tp1 = $trend === 1 ? round($entry + 2.0 * $risk, 2) : round($entry - 2.0 * $risk, 2);

            $results[] = [
                'symbol'        => $sym,
                'timeframe'     => $tf,
                'price'         => $curPrice,
                'direction'     => $dirLabel,
                'trend'         => $smc['trend_label'] ?? 'neutral',
                'strategy'      => $primaryStrat,
                'score'         => min(100, $score),
                'status'        => $status,
                'entry'         => $entry,
                'sl'            => $sl,
                'tp1'           => $tp1,
                'rr'            => 2.0,
                'atr'           => round($atr, 2),
                'in_killzone'   => $kz['is_killzone'],
            ];
        } catch (\Throwable $e) {
            // Ignore timeframe errors
        }
    }
}

Response::json([
    'ok'          => true,
    'scanned_at'  => $now,
    'killzone'    => $kz,
    'macro'       => $macro,
    'count'       => count($results),
    'results'     => $results,
]);
