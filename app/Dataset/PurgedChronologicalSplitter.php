<?php
declare(strict_types=1);

namespace App\Dataset;

/**
 * Purged Chronological Dataset Splitter with Window Overlap Embargo.
 *
 * Enforces:
 *  1. Strict chronological ordering (Train < Validation < Test).
 *  2. Zero look-ahead bias and zero data leakage.
 *  3. Purged embargo gaps between splits to ensure no chart window overlap exists
 *     between training, validation, and out-of-sample test partitions.
 */
final class PurgedChronologicalSplitter
{
    /**
     * Partition chronological samples into Train, Validation, and Test sets with an embargo gap.
     *
     * @param array<array{id: int|string, chart_timestamp: string|int}> $samples
     * @param float $trainRatio Fraction for training (default 0.70)
     * @param float $valRatio Fraction for validation (default 0.15)
     * @param int $embargoSeconds Minimum time gap between partitions (default 86400s = 24h)
     * @return array{
     *   train: array,
     *   val: array,
     *   test: array,
     *   purged: array,
     *   split_stats: array
     * }
     */
    public function split(
        array $samples,
        float $trainRatio = 0.70,
        float $valRatio = 0.15,
        int $embargoSeconds = 86400
    ): array {
        $n = count($samples);
        if ($n === 0) {
            return [
                'train' => [],
                'val' => [],
                'test' => [],
                'purged' => [],
                'split_stats' => ['total' => 0, 'train' => 0, 'val' => 0, 'test' => 0, 'purged' => 0],
            ];
        }

        // Sort chronologically ascending
        usort($samples, function ($a, $b) {
            $tsA = is_numeric($a['chart_timestamp']) ? (int)$a['chart_timestamp'] : strtotime((string)$a['chart_timestamp']);
            $tsB = is_numeric($b['chart_timestamp']) ? (int)$b['chart_timestamp'] : strtotime((string)$b['chart_timestamp']);
            return $tsA <=> $tsB;
        });

        $trainCount = (int)floor($n * $trainRatio);
        $valCount = (int)floor($n * $valRatio);

        $rawTrain = array_slice($samples, 0, $trainCount);
        $rawRest = array_slice($samples, $trainCount);

        // Apply embargo gap between Train and Validation
        $lastTrainTs = !empty($rawTrain) ? $this->getTimestamp(end($rawTrain)) : 0;
        $purgedBetweenTrainVal = [];
        $rawValCandidates = [];

        foreach ($rawRest as $s) {
            $ts = $this->getTimestamp($s);
            if ($ts < ($lastTrainTs + $embargoSeconds)) {
                $purgedBetweenTrainVal[] = $s;
            } else {
                $rawValCandidates[] = $s;
            }
        }

        $valSlice = array_slice($rawValCandidates, 0, $valCount);
        $rawTestCandidates = array_slice($rawValCandidates, $valCount);

        // Apply embargo gap between Validation and Test
        $lastValTs = !empty($valSlice) ? $this->getTimestamp(end($valSlice)) : 0;
        $purgedBetweenValTest = [];
        $testSlice = [];

        foreach ($rawTestCandidates as $s) {
            $ts = $this->getTimestamp($s);
            if ($ts < ($lastValTs + $embargoSeconds)) {
                $purgedBetweenValTest[] = $s;
            } else {
                $testSlice[] = $s;
            }
        }

        $allPurged = array_merge($purgedBetweenTrainVal, $purgedBetweenValTest);

        $splitStats = [
            'total' => $n,
            'train' => count($rawTrain),
            'val' => count($valSlice),
            'test' => count($testSlice),
            'purged' => count($allPurged),
            'train_range' => !empty($rawTrain) ? [
                'start' => gmdate('Y-m-d H:i', $this->getTimestamp($rawTrain[0])),
                'end' => gmdate('Y-m-d H:i', $lastTrainTs),
            ] : null,
            'val_range' => !empty($valSlice) ? [
                'start' => gmdate('Y-m-d H:i', $this->getTimestamp($valSlice[0])),
                'end' => gmdate('Y-m-d H:i', $lastValTs),
            ] : null,
            'test_range' => !empty($testSlice) ? [
                'start' => gmdate('Y-m-d H:i', $this->getTimestamp($testSlice[0])),
                'end' => gmdate('Y-m-d H:i', $this->getTimestamp(end($testSlice))),
            ] : null,
            'embargo_seconds' => $embargoSeconds,
            'split_hash' => hash('sha256', count($rawTrain) . '_' . count($valSlice) . '_' . count($testSlice) . '_' . $lastTrainTs),
        ];

        return [
            'train' => $rawTrain,
            'val' => $valSlice,
            'test' => $testSlice,
            'purged' => $allPurged,
            'split_stats' => $splitStats,
        ];
    }

    private function getTimestamp(array $sample): int
    {
        $val = $sample['chart_timestamp'] ?? 0;
        return is_numeric($val) ? (int)$val : (strtotime((string)$val) ?: 0);
    }
}
