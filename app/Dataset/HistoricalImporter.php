<?php
declare(strict_types=1);

namespace App\Dataset;

use App\Core\Database;
use App\Market\CandleValidator;
use App\Market\Timeframe;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Historical OHLCV Importer & Validator for Gold (XAUUSD) and Multi-Asset Datasets.
 *
 * Supports:
 *  - MetaTrader 5 (MT5) tab-separated and comma-separated exports.
 *  - TradingView CSV exports (Unix timestamps, milliseconds, ISO-8601).
 *  - Generic financial CSV files with auto-header detection.
 *  - Timezone normalization (Broker EET/UTC+2/UTC+3 to strict UTC).
 *  - Rigorous OHLC consistency validation and gap detection with weekend awareness.
 *  - Incremental deduplicated database persistence into `market_candles` and `dataset_imports`.
 */
final class HistoricalImporter
{
    /**
     * Parse and validate historical CSV/TSV content or file path.
     *
     * @param string $csvContentOrPath Raw string or absolute filesystem path
     * @param string $symbol Instrument symbol (e.g. 'XAUUSD')
     * @param string $timeframe Base timeframe ('M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1')
     * @param array $options Configuration:
     *   - tz_offset_hours: float (e.g. 2.0 or 3.0 for MT5 broker EET/EEST; default 0.0)
     *   - save_to_db: bool (whether to persist candles into market_candles; default false)
     *   - filename: string (optional label for database record)
     *   - max_rows: int (optional limit for large files)
     * @return array{
     *   success: bool,
     *   import_uuid: string,
     *   filename: string,
     *   symbol: string,
     *   timeframe: string,
     *   source_type: string,
     *   total_rows_read: int,
     *   valid_candles_count: int,
     *   rejected_rows_count: int,
     *   duplicate_rows_count: int,
     *   gap_count: int,
     *   date_start: ?string,
     *   date_end: ?string,
     *   gaps: array,
     *   issues: array,
     *   candles: array
     * }
     */
    public function import(
        string $csvContentOrPath,
        string $symbol = 'XAUUSD',
        string $timeframe = 'M15',
        array $options = []
    ): array {
        $tzOffsetHours = (float)($options['tz_offset_hours'] ?? 0.0);
        $saveToDb = (bool)($options['save_to_db'] ?? false);
        $filename = (string)($options['filename'] ?? 'xauusd_data.csv');
        $maxRows = (int)($options['max_rows'] ?? 250000);

        $lines = $this->getLines($csvContentOrPath, $maxRows);
        if (empty($lines)) {
            return [
                'success' => false,
                'import_uuid' => 'imp_' . bin2hex(random_bytes(8)),
                'filename' => $filename,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'source_type' => 'unknown',
                'total_rows_read' => 0,
                'valid_candles_count' => 0,
                'rejected_rows_count' => 0,
                'duplicate_rows_count' => 0,
                'gap_count' => 0,
                'date_start' => null,
                'date_end' => null,
                'gaps' => [],
                'issues' => ['Empty CSV input or file not found.'],
                'candles' => [],
            ];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headerMap = $this->parseHeader($lines[0], $delimiter);
        $hasHeader = $headerMap['has_header'];
        $startIndex = $hasHeader ? 1 : 0;
        $sourceType = $headerMap['source_type'];

        $parsedCandles = [];
        $rejectedCount = 0;
        $duplicateCount = 0;
        $seenTimestamps = [];
        $issues = [];
        $totalRowsRead = 0;

        $tzOffsetSeconds = (int)round($tzOffsetHours * 3600);

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $totalRowsRead++;

            $cols = str_getcsv($line, $delimiter);
            $parsed = $this->extractRowValues($cols, $headerMap, $tzOffsetSeconds);

            if ($parsed === null) {
                $rejectedCount++;
                if (count($issues) < 20) {
                    $issues[] = "Row {$i}: Invalid format or non-numeric OHLC values: '{$line}'";
                }
                continue;
            }

            $ts = $parsed['t'];
            if (isset($seenTimestamps[$ts])) {
                $duplicateCount++;
                continue;
            }

            // Basic price logic checks
            if ($parsed['h'] < max($parsed['o'], $parsed['c']) - 1e-6 ||
                $parsed['l'] > min($parsed['o'], $parsed['c']) + 1e-6 ||
                $parsed['h'] < $parsed['l'] ||
                $parsed['o'] <= 0 || $parsed['h'] <= 0 || $parsed['l'] <= 0 || $parsed['c'] <= 0) {
                $rejectedCount++;
                if (count($issues) < 20) {
                    $issues[] = "Row {$i} (" . gmdate('Y-m-d H:i:s', $ts) . "): Invalid OHLC relation [O:{$parsed['o']}, H:{$parsed['h']}, L:{$parsed['l']}, C:{$parsed['c']}]";
                }
                continue;
            }

            $seenTimestamps[$ts] = true;
            $parsedCandles[] = $parsed;
        }

        // Sort chronologically ascending
        usort($parsedCandles, static fn($a, $b) => $a['t'] <=> $b['t']);

        // Check for missing candle gaps (accounting for expected timeframe interval & weekends)
        $gapAnalysis = $this->analyzeGaps($parsedCandles, $timeframe);
        $issues = array_merge($issues, $gapAnalysis['issues']);

        $validCount = count($parsedCandles);
        $dateStart = $validCount > 0 ? gmdate('Y-m-d H:i:s', $parsedCandles[0]['t']) : null;
        $dateEnd = $validCount > 0 ? gmdate('Y-m-d H:i:s', $parsedCandles[$validCount - 1]['t']) : null;

        $importUuid = 'imp_' . bin2hex(random_bytes(8));

        // Incremental database persistence if requested
        if ($saveToDb && $validCount > 0) {
            $this->persistCandles($parsedCandles, $symbol, $timeframe);
            $this->recordImportLog([
                'import_uuid' => $importUuid,
                'filename' => $filename,
                'file_hash' => hash('sha256', implode("\n", array_slice($lines, 0, 100)) . $validCount),
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'source_type' => $sourceType,
                'tz_offset_hours' => $tzOffsetHours,
                'total_rows' => $totalRowsRead,
                'valid_candles' => $validCount,
                'rejected_rows' => $rejectedCount,
                'duplicate_rows' => $duplicateCount,
                'gap_count' => count($gapAnalysis['gaps']),
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'validation_report' => json_encode([
                    'issues' => array_slice($issues, 0, 30),
                    'gaps' => array_slice($gapAnalysis['gaps'], 0, 25),
                ], JSON_THROW_ON_ERROR),
                'status' => 'completed',
            ]);
        }

        return [
            'success' => $validCount > 0,
            'import_uuid' => $importUuid,
            'filename' => $filename,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'source_type' => $sourceType,
            'total_rows_read' => $totalRowsRead,
            'valid_candles_count' => $validCount,
            'rejected_rows_count' => $rejectedCount,
            'duplicate_rows_count' => $duplicateCount,
            'gap_count' => count($gapAnalysis['gaps']),
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'gaps' => $gapAnalysis['gaps'],
            'issues' => array_slice(array_unique($issues), 0, 30),
            'candles' => $parsedCandles,
        ];
    }

    /**
     * Retrieve lines from file path or raw string.
     */
    private function getLines(string $input, int $maxRows): array
    {
        if (strlen($input) < 4096 && is_file($input)) {
            $lines = [];
            $handle = fopen($input, 'rb');
            if ($handle === false) {
                return [];
            }
            while (!feof($handle) && count($lines) < $maxRows) {
                $line = fgets($handle);
                if ($line !== false) {
                    $lines[] = $line;
                }
            }
            fclose($handle);
            return $lines;
        }

        $rawLines = preg_split('/\r\n|\r|\n/', $input);
        if ($rawLines === false) {
            return [];
        }
        return array_slice($rawLines, 0, $maxRows);
    }

    /**
     * Auto-detect CSV delimiter (tab, comma, semicolon).
     */
    private function detectDelimiter(string $sampleLine): string
    {
        $tabs = substr_count($sampleLine, "\t");
        $commas = substr_count($sampleLine, ",");
        $semis = substr_count($sampleLine, ";");

        if ($tabs >= 4 && $tabs > $commas) {
            return "\t";
        }
        if ($semis >= 4 && $semis > $commas) {
            return ";";
        }
        return ",";
    }

    /**
     * Parse header row to extract column indices and source profile.
     *
     * @return array{
     *   has_header: bool,
     *   source_type: string,
     *   date_idx: int,
     *   time_idx: int,
     *   open_idx: int,
     *   high_idx: int,
     *   low_idx: int,
     *   close_idx: int,
     *   vol_idx: int,
     *   spread_idx: int
     * }
     */
    private function parseHeader(string $firstLine, string $delimiter): array
    {
        $cols = array_map(static fn($c) => trim(strtolower(trim($c, "\"' \t\r\n\0\x0B"))), str_getcsv($firstLine, $delimiter));

        $hasHeader = false;
        $map = [
            'date_idx' => -1,
            'time_idx' => -1,
            'open_idx' => -1,
            'high_idx' => -1,
            'low_idx' => -1,
            'close_idx' => -1,
            'vol_idx' => -1,
            'spread_idx' => -1,
        ];

        foreach ($cols as $idx => $col) {
            if (in_array($col, ['<date>', 'date', 'datetime', 'time', 'timestamp'], true)) {
                $hasHeader = true;
                if ($col === 'time' && $map['date_idx'] !== -1) {
                    $map['time_idx'] = $idx;
                } else {
                    $map['date_idx'] = $idx;
                }
            } elseif (in_array($col, ['<time>', 'time'], true) && $map['time_idx'] === -1) {
                $map['time_idx'] = $idx;
            } elseif (in_array($col, ['<open>', 'open', 'o'], true)) {
                $hasHeader = true;
                $map['open_idx'] = $idx;
            } elseif (in_array($col, ['<high>', 'high', 'h'], true)) {
                $hasHeader = true;
                $map['high_idx'] = $idx;
            } elseif (in_array($col, ['<low>', 'low', 'l'], true)) {
                $hasHeader = true;
                $map['low_idx'] = $idx;
            } elseif (in_array($col, ['<close>', 'close', 'c'], true)) {
                $hasHeader = true;
                $map['close_idx'] = $idx;
            } elseif (in_array($col, ['<vol>', '<tickvol>', 'volume', 'vol', 'tick_volume'], true)) {
                $map['vol_idx'] = $idx;
            } elseif (in_array($col, ['<spread>', 'spread'], true)) {
                $map['spread_idx'] = $idx;
            }
        }

        // Determine source type
        $sourceType = 'generic_csv';
        if ($delimiter === "\t") {
            $sourceType = 'mt5_tab';
        } elseif (in_array('<date>', $cols, true) || in_array('<open>', $cols, true)) {
            $sourceType = 'mt5_csv';
        } elseif (in_array('time', $cols, true) && (in_array('volume', $cols, true) || in_array('close', $cols, true))) {
            $sourceType = 'tradingview';
        }

        // Fallback for headerless standard MT5/TradingView exports:
        // MT5 default: Date, Time, Open, High, Low, Close, Volume, Spread (8 cols)
        // TradingView default: Time, Open, High, Low, Close, Volume (6 cols)
        if (!$hasHeader) {
            $colCount = count($cols);
            if ($colCount >= 7) {
                // Typical MT5 headerless
                $map['date_idx'] = 0;
                $map['time_idx'] = 1;
                $map['open_idx'] = 2;
                $map['high_idx'] = 3;
                $map['low_idx'] = 4;
                $map['close_idx'] = 5;
                $map['vol_idx'] = 6;
                $sourceType = ($delimiter === "\t") ? 'mt5_tab' : 'mt5_csv';
            } elseif ($colCount >= 5) {
                // Typical TV / generic headerless: Time, O, H, L, C
                $map['date_idx'] = 0;
                $map['open_idx'] = 1;
                $map['high_idx'] = 2;
                $map['low_idx'] = 3;
                $map['close_idx'] = 4;
                if ($colCount >= 6) {
                    $map['vol_idx'] = 5;
                }
            }
        }

        return array_merge(['has_header' => $hasHeader, 'source_type' => $sourceType], $map);
    }

    /**
     * Extract structured candle from row array according to column mapping.
     */
    private function extractRowValues(array $cols, array $headerMap, int $tzOffsetSeconds): ?array
    {
        $dateIdx = $headerMap['date_idx'];
        $timeIdx = $headerMap['time_idx'];
        $openIdx = $headerMap['open_idx'];
        $highIdx = $headerMap['high_idx'];
        $lowIdx = $headerMap['low_idx'];
        $closeIdx = $headerMap['close_idx'];
        $volIdx = $headerMap['vol_idx'];

        if ($dateIdx === -1 || !isset($cols[$dateIdx])) {
            return null;
        }

        $dateStr = trim($cols[$dateIdx], " \t\r\n\"'");
        $timeStr = ($timeIdx !== -1 && isset($cols[$timeIdx])) ? trim($cols[$timeIdx], " \t\r\n\"'") : '';

        $timestamp = $this->parseTimestamp($dateStr, $timeStr);
        if ($timestamp === null) {
            return null;
        }

        // Apply timezone normalization to UTC
        $utcTimestamp = $timestamp - $tzOffsetSeconds;

        $open = isset($cols[$openIdx]) ? (float)trim($cols[$openIdx], " \t\r\n\"'") : null;
        $high = isset($cols[$highIdx]) ? (float)trim($cols[$highIdx], " \t\r\n\"'") : null;
        $low = isset($cols[$lowIdx]) ? (float)trim($cols[$lowIdx], " \t\r\n\"'") : null;
        $close = isset($cols[$closeIdx]) ? (float)trim($cols[$closeIdx], " \t\r\n\"'") : null;
        $volume = ($volIdx !== -1 && isset($cols[$volIdx])) ? (float)trim($cols[$volIdx], " \t\r\n\"'") : 1.0;

        if ($open === null || $high === null || $low === null || $close === null) {
            return null;
        }

        return [
            't' => $utcTimestamp,
            'o' => round($open, 3),
            'h' => round($high, 3),
            'l' => round($low, 3),
            'c' => round($close, 3),
            'v' => round(max(0.0, $volume), 2),
        ];
    }

    /**
     * Parse date/time string from diverse broker and charting formats into Unix epoch seconds.
     */
    private function parseTimestamp(string $datePart, string $timePart): ?int
    {
        // 1. Unix epoch seconds or milliseconds
        if ($timePart === '' && is_numeric($datePart)) {
            $num = (float)$datePart;
            if ($num > 100000000000) { // milliseconds
                return (int)round($num / 1000);
            }
            if ($num > 946684800 && $num < 2500000000) { // seconds (2000-2049)
                return (int)$num;
            }
        }

        $combined = trim($datePart . ' ' . $timePart);

        // Normalize separators: 2026.10.28 -> 2026-10-28
        $normalized = str_replace(['.', '/'], '-', $combined);

        // Handle ISO-8601 strings (e.g. 2026-10-28T14:15:00Z)
        if (str_contains($combined, 'T')) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $combined, new DateTimeZone('UTC'));
            if ($dt === false) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $combined, new DateTimeZone('UTC'));
            }
            if ($dt === false) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $combined, new DateTimeZone('UTC'));
            }
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }

        // Standard DateTime formats
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'Y-m-d',
        ];

        foreach ($formats as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $normalized, new DateTimeZone('UTC'));
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }

        // Fallback to strtotime
        $ts = strtotime($combined . ' UTC');
        return ($ts !== false && $ts > 946684800) ? $ts : null;
    }

    /**
     * Analyze candle time deltas, flagging unexpected gaps while accounting for standard weekend closes.
     */
    private function analyzeGaps(array $candles, string $timeframe): array
    {
        $expectedSec = Timeframe::seconds($timeframe) ?? 900;
        $gaps = [];
        $issues = [];
        $count = count($candles);

        for ($i = 1; $i < $count; $i++) {
            $prev = $candles[$i - 1]['t'];
            $curr = $candles[$i]['t'];
            $delta = $curr - $prev;

            if ($delta <= $expectedSec) {
                continue;
            }

            // Check if this gap spans a weekend (Friday ~21:00 UTC to Sunday ~22:00 UTC)
            $prevDay = (int)gmdate('w', $prev); // 0=Sun, 5=Fri, 6=Sat
            $currDay = (int)gmdate('w', $curr);
            $isWeekend = ($prevDay === 5 && ($currDay === 0 || $currDay === 1));

            $missingBars = (int)floor($delta / $expectedSec) - 1;

            if ($isWeekend) {
                // Expected weekend market closure
                continue;
            }

            if ($missingBars >= 1) {
                $gapInfo = [
                    'from' => gmdate('Y-m-d H:i', $prev),
                    'to' => gmdate('Y-m-d H:i', $curr),
                    'missing_bars' => $missingBars,
                    'delta_seconds' => $delta,
                ];
                $gaps[] = $gapInfo;
                if (count($issues) < 15) {
                    $issues[] = "Data gap: {$missingBars} missing {$timeframe} candle(s) between {$gapInfo['from']} and {$gapInfo['to']} (duration: " . round($delta / 60) . " min)";
                }
            }
        }

        return ['gaps' => $gaps, 'issues' => $issues];
    }

    /**
     * Persist imported candles into `market_candles` with deduplication.
     */
    private function persistCandles(array $candles, string $symbol, string $timeframe): void
    {
        $pdo = Database::pdo();
        $sourceId = 1; // 1 = Local / Manual Import

        $sql = "INSERT INTO market_candles 
                (source_id, symbol, timeframe, open_time, open, high, low, close, volume, created_at, updated_at)
                VALUES (:sid, :sym, :tf, :ot, :o, :h, :l, :c, :v, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                open = VALUES(open), high = VALUES(high), low = VALUES(low), 
                close = VALUES(close), volume = VALUES(volume), updated_at = NOW()";

        $stmt = $pdo->prepare($sql);

        $pdo->beginTransaction();
        try {
            foreach ($candles as $c) {
                $openTime = gmdate('Y-m-d H:i:s', $c['t']);
                $stmt->execute([
                    ':sid' => $sourceId,
                    ':sym' => $symbol,
                    ':tf' => $timeframe,
                    ':ot' => $openTime,
                    ':o' => $c['o'],
                    ':h' => $c['h'],
                    ':l' => $c['l'],
                    ':c' => $c['c'],
                    ':v' => $c['v'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Record import metadata in `dataset_imports` table.
     */
    private function recordImportLog(array $meta): void
    {
        $pdo = Database::pdo();
        $sql = "INSERT INTO dataset_imports 
                (import_uuid, filename, file_hash, symbol, timeframe, source_type, tz_offset_hours, 
                 total_rows, valid_candles, rejected_rows, duplicate_rows, gap_count, date_start, date_end, 
                 validation_report, status, created_at, updated_at)
                VALUES 
                (:import_uuid, :filename, :file_hash, :symbol, :timeframe, :source_type, :tz_offset_hours,
                 :total_rows, :valid_candles, :rejected_rows, :duplicate_rows, :gap_count, :date_start, :date_end,
                 :validation_report, :status, NOW(), NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':import_uuid' => $meta['import_uuid'],
            ':filename' => $meta['filename'],
            ':file_hash' => $meta['file_hash'],
            ':symbol' => $meta['symbol'],
            ':timeframe' => $meta['timeframe'],
            ':source_type' => $meta['source_type'],
            ':tz_offset_hours' => $meta['tz_offset_hours'],
            ':total_rows' => $meta['total_rows'],
            ':valid_candles' => $meta['valid_candles'],
            ':rejected_rows' => $meta['rejected_rows'],
            ':duplicate_rows' => $meta['duplicate_rows'],
            ':gap_count' => $meta['gap_count'],
            ':date_start' => $meta['date_start'],
            ':date_end' => $meta['date_end'],
            ':validation_report' => $meta['validation_report'],
            ':status' => $meta['status'],
        ]);
    }
}
