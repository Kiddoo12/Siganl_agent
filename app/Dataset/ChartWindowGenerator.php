<?php
declare(strict_types=1);

namespace App\Dataset;

use App\Market\Timeframe;

/**
 * Generates synchronized, look-ahead safe sliding chart windows and quarantined forward evaluation horizons.
 */
final class ChartWindowGenerator
{
    /**
     * Generate sequential or event-based multi-timeframe chart windows.
     *
     * @param array<string, array> $mtfCandles Array of candles keyed by timeframe code ('M15', 'H1', 'H4')
     * @param string $execTf Primary execution timeframe (e.g. 'M15')
     * @param int $windowBars Number of historical bars in the execution window (default 60)
     * @param int $forwardHorizonBars Number of forward bars reserved for outcome verification (default 40)
     * @param int $stepBars Number of bars to advance between candidate windows (default 10)
     * @return array<array{
     *   decision_time: int,
     *   decision_datetime: string,
     *   exec_tf: string,
     *   exec_window: array,
     *   htf_windows: array<string, array>,
     *   forward_candles: array,
     *   ref_price: float,
     *   high_bound: float,
     *   low_bound: float
     * }>
     */
    public function generateWindows(
        array $mtfCandles,
        string $execTf = 'M15',
        int $windowBars = 60,
        int $forwardHorizonBars = 40,
        int $stepBars = 12
    ): array {
        if (!isset($mtfCandles[$execTf]) || count($mtfCandles[$execTf]) < ($windowBars + $forwardHorizonBars)) {
            return [];
        }

        $candles = $mtfCandles[$execTf];
        $totalCandles = count($candles);
        $windows = [];

        // Slide window across historical candles
        for ($i = $windowBars - 1; $i <= $totalCandles - $forwardHorizonBars - 1; $i += $stepBars) {
            $execWindow = array_slice($candles, $i - $windowBars + 1, $windowBars);
            $forwardCandles = array_slice($candles, $i + 1, $forwardHorizonBars);

            $decisionCandle = $candles[$i];
            $decisionTime = (int)$decisionCandle['t'];
            $decisionCloseTime = Timeframe::closeTime($execTf, $decisionTime);

            // Synchronize HTF windows closed at or before $decisionCloseTime
            $htfWindows = [];
            foreach ($mtfCandles as $tf => $tfCandles) {
                if ($tf === $execTf) {
                    continue;
                }
                $closedHtf = Timeframe::closedOnly($tf, $tfCandles, $decisionCloseTime);
                $htfCount = ($tf === 'D1') ? 20 : (($tf === 'H4') ? 30 : 45);
                $htfWindows[$tf] = array_slice($closedHtf, -$htfCount);
            }

            // Calculate bounding price extremes of execution window
            $highs = array_column($execWindow, 'h');
            $lows = array_column($execWindow, 'l');
            $maxHigh = !empty($highs) ? max($highs) : (float)$decisionCandle['c'];
            $minLow = !empty($lows) ? min($lows) : (float)$decisionCandle['c'];

            $windows[] = [
                'decision_time' => $decisionTime,
                'decision_datetime' => gmdate('Y-m-d H:i:s', $decisionTime),
                'exec_tf' => $execTf,
                'exec_window' => $execWindow,
                'htf_windows' => $htfWindows,
                'forward_candles' => $forwardCandles,
                'ref_price' => (float)$decisionCandle['c'],
                'high_bound' => (float)$maxHigh,
                'low_bound' => (float)$minLow,
            ];
        }

        return $windows;
    }
}
