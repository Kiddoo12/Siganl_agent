<?php
declare(strict_types=1);

namespace App\Vision;

use App\Analysis\ChartVisionService;
use App\Core\Database;
use App\Market\MarketDataService;

/**
 * Section 6 — Multi-Timeframe Chart Vision Analysis & Conflict Detection.
 *
 * Synchronizes screenshots across Higher Timeframe (H4/H1 bias),
 * Setup Development (M15), and Execution Refinement (M5/M1).
 */
final class MultiTimeframeVisionAnalyzer
{
    /**
     * Analyze a synchronized multi-timeframe bundle of chart screenshots.
     *
     * @param array<string, string> $imagesByTf Timeframe (e.g. 'H4', 'M15', 'M5') => Local file path
     * @param string $symbol Instrument symbol (e.g. 'XAUUSD')
     * @param float|null $refPrice Current reference market price
     * @param int|null $userId User ID
     * @return array Multi-timeframe synthesis report
     */
    public function analyzeBundle(
        array $imagesByTf,
        string $symbol = 'XAUUSD',
        ?float $refPrice = null,
        ?int $userId = null
    ): array {
        $symbol = MarketDataService::normalizeSymbol($symbol);
        $tfReports = [];
        $biasVotes = [];

        // 1. Analyze each timeframe screenshot
        foreach ($imagesByTf as $tf => $filePath) {
            $tfUpper = strtoupper(trim((string)$tf));
            if (!file_exists($filePath)) {
                continue;
            }

            $analysis = ChartVisionService::analyzeChart(
                $filePath,
                $symbol,
                $tfUpper,
                $refPrice,
                null,
                null,
                $userId
            );

            $tfReports[$tfUpper] = $analysis;
            $biasVotes[$tfUpper] = $analysis['market_bias'] ?? 'Neutral';
        }

        if (empty($tfReports)) {
            throw new \InvalidArgumentException('No valid chart screenshot images provided for multi-timeframe bundle.');
        }

        // 2. Synthesize Higher-Timeframe (HTF) vs Lower-Timeframe (LTF) Bias
        $htfTfs = ['D1', 'H4', 'H1'];
        $ltfTfs = ['M15', 'M5', 'M1'];

        $htfBias = 'Neutral';
        foreach ($htfTfs as $htf) {
            if (isset($biasVotes[$htf]) && $biasVotes[$htf] !== 'Neutral') {
                $htfBias = $biasVotes[$htf];
                break;
            }
        }

        $ltfBias = 'Neutral';
        foreach ($ltfTfs as $ltf) {
            if (isset($biasVotes[$ltf]) && $biasVotes[$ltf] !== 'Neutral') {
                $ltfBias = $biasVotes[$ltf];
                break;
            }
        }

        // 3. Conflict Detection
        $hasConflict = false;
        $conflictReasons = [];

        if ($htfBias !== 'Neutral' && $ltfBias !== 'Neutral' && $htfBias !== $ltfBias) {
            $hasConflict = true;
            $conflictReasons[] = sprintf(
                'Directional Bias Conflict: Higher timeframe (%s) is %s, while lower execution timeframe is %s. Never force counter-trend trades against institutional order flow.',
                implode('/', array_intersect($htfTfs, array_keys($biasVotes))),
                $htfBias,
                $ltfBias
            );
        }

        // Calculate Overall Confluence Score
        $score = 50;
        if (!$hasConflict && $htfBias !== 'Neutral' && $htfBias === $ltfBias) {
            $score = 90; // High alignment
        } elseif ($hasConflict) {
            $score = 35; // Severely penalized
        }

        $overallDecision = 'WAIT';
        if (!$hasConflict && $score >= 80) {
            $overallDecision = ($htfBias === 'Bullish') ? 'BUY' : 'SELL';
        }

        // 4. Persist Bundle into Database
        $bundleUuid = 'mtf_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $nowSql = gmdate('Y-m-d H:i:s');

        $bundleId = Database::insert('vision_mtf_bundles', [
            'bundle_uuid'      => $bundleUuid,
            'user_id'          => $userId,
            'symbol'           => $symbol,
            'anchor_timestamp' => $nowSql,
            'htf_bias'         => in_array($htfBias, ['Bullish', 'Bearish', 'Neutral'], true) ? $htfBias : 'Neutral',
            'confluence_score' => $score,
            'has_conflict'     => $hasConflict ? 1 : 0,
            'conflict_notes'   => !empty($conflictReasons) ? implode("\n", $conflictReasons) : null,
            'overall_decision' => $overallDecision,
            'created_at'       => $nowSql,
        ]);

        return [
            'ok'               => true,
            'bundle_id'        => $bundleId,
            'bundle_uuid'      => $bundleUuid,
            'symbol'           => $symbol,
            'timeframes'       => array_keys($tfReports),
            'htf_bias'         => $htfBias,
            'ltf_bias'         => $ltfBias,
            'has_conflict'     => $hasConflict,
            'conflict_reasons' => $conflictReasons,
            'confluence_score' => $score,
            'overall_decision' => $overallDecision,
            'reports_by_tf'    => $tfReports,
        ];
    }
}
