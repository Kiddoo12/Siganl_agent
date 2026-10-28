<?php
declare(strict_types=1);

namespace App\Vision;

use App\Core\Database;

/**
 * Section 4 — Retrieval-Augmented Generation (RAG) for Chart Vision Learning.
 *
 * Retrieves verified ground-truth examples and past expert corrections
 * to ground vision prompts, prevent recurring false positives, and teach
 * the AI genuine institutional chart interpretation.
 */
final class VisionRagService
{
    /**
     * Retrieve relevant verified ground-truth exemplars for a chart analysis session.
     *
     * @param string $symbol Instrument symbol (e.g. 'XAUUSD')
     * @param string $timeframe Chart timeframe (e.g. 'M15')
     * @param int $limit Maximum number of exemplars to retrieve (default 3)
     * @return array{
     *   exemplars: array<array>,
     *   prompt_context: string,
     *   has_corrections: bool,
     *   count: int
     * }
     */
    public function retrieveExemplars(string $symbol = 'XAUUSD', string $timeframe = 'M15', int $limit = 3): array
    {
        // Prioritize expert_corrected examples with verified trade outcomes
        $rows = Database::fetchAll(
            "SELECT e.*, 
                    (SELECT COUNT(*) FROM vision_annotations a WHERE a.example_id = e.id AND a.source = 'expert_ground_truth') as gt_count
             FROM vision_training_examples e
             WHERE e.symbol = ?
               AND e.review_status IN ('expert_corrected', 'verified')
             ORDER BY (CASE WHEN e.review_status = 'expert_corrected' THEN 1 ELSE 2 END) ASC,
                      e.id DESC
             LIMIT ?",
            [$symbol, $limit]
        );

        if (empty($rows)) {
            return [
                'exemplars'       => [],
                'prompt_context'  => '',
                'has_corrections' => false,
                'count'           => 0,
            ];
        }

        $exemplars = [];
        $contextLines = [
            "--- INSTITUTIONAL GROUND-TRUTH FEW-SHOT EXEMPLARS (LEARNED FROM EXPERT CORRECTIONS) ---",
            "Follow these verified chart patterns and avoid recurring mistakes identified by human expert reviews:",
        ];

        foreach ($rows as $row) {
            $exId = (int)$row['id'];
            $annotations = Database::fetchAll(
                "SELECT pattern_type, direction, price_top, price_bottom, price_level, notes
                 FROM vision_annotations
                 WHERE example_id = ? AND source = 'expert_ground_truth'
                 ORDER BY id ASC",
                [$exId]
            );

            $annSummaries = [];
            foreach ($annotations as $a) {
                $lvl = $a['price_level'] ? sprintf(' @ %.2f', (float)$a['price_level']) : ($a['price_top'] ? sprintf(' @ %.2f-%.2f', (float)$a['price_bottom'], (float)$a['price_top']) : '');
                $annSummaries[] = sprintf('%s (%s)%s', $a['pattern_type'], $a['direction'], $lvl);
            }

            $exemplars[] = [
                'id'            => $exId,
                'uuid'          => $row['example_uuid'],
                'symbol'        => $row['symbol'],
                'timeframe'     => $row['timeframe'],
                'review_status' => $row['review_status'],
                'regime'        => $row['market_regime'],
                'decision'      => $row['trade_decision'],
                'outcome'       => $row['outcome'],
                'realized_r'    => (float)($row['realized_r'] ?? 0.0),
                'expert_notes'  => $row['expert_notes'],
                'annotations'   => $annSummaries,
            ];

            $contextLines[] = sprintf(
                "[Exemplar #%d (%s %s)]: Review Status: %s | Regime: %s | Expert Decision: %s (Outcome: %s, Realized: %+.2fR)\n - Ground-Truth Patterns: %s\n - Expert Feedback & Correction: %s",
                $exId,
                $row['symbol'],
                $row['timeframe'],
                $row['review_status'],
                $row['market_regime'],
                $row['trade_decision'],
                $row['outcome'],
                (float)($row['realized_r'] ?? 0.0),
                implode(', ', $annSummaries) ?: 'Standard SMC Structure',
                $row['expert_notes'] ?: 'Verified institutional alignment'
            );
        }

        $contextLines[] = "CRITICAL INSTRUCTION: Adhere strictly to the definitions demonstrated in these exemplars. Never classify a wick-only penetration as a BOS (requires candle body close).";
        $contextLines[] = "-----------------------------------------------------------------------------------";

        return [
            'exemplars'       => $exemplars,
            'prompt_context'  => implode("\n", $contextLines),
            'has_corrections' => true,
            'count'           => count($exemplars),
        ];
    }
}
