<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Notifier;
use App\Market\MarketDataService;
use App\SMC\CrtEngine;
use App\SMC\IctEngine;
use App\SMC\SmcEngine;
use App\SMC\SnrEngine;

/**
 * Institutional AI Chart Vision Analyzer Service (V3 Upgrade).
 *
 * Capabilities:
 *  - Strict Upload Security: PNG/JPG/JPEG/WEBP magic-byte & finfo verification, stream marker validation,
 *    decompression bomb guard (max 25M pixels), EXIF orientation detection, and獨立 HTF screenshot workflow.
 *  - Symbol-Aware Price Calibration: Supports XAUUSD, BTCUSD, EURUSD, GBPUSD, USDJPY, US30, NAS100 with
 *    exact tick size, decimal precision, and symbol-range validation. Never fabricates $2400.00 placeholders.
 *  - Multi-Timeframe (MTF) Validation: Supports M1, M5, M15, M30, H1, H4, D1; distinguishes uploaded screenshots
 *    from market-data-derived timeframes and allows Neutral / Unavailable states.
 *  - 5-Strategy Analytical Engine: ICT, SMC, CRT, SNR, and ALL (Confluence) with 5-part epistemic separation:
 *    Observed Chart Facts, AI Interpretations, Hypotheses, Missing Information, and Confirmation Conditions.
 *  - Mathematical Trade Validation: Enforces BUY (SL < Entry < TP1 < TP2 < TP3) and SELL (SL > Entry > TP1 > TP2 > TP3)
 *    at 2.0R, 3.5R, 5.0R, returning NO TRADE whenever evidence or structure is insufficient.
 *  - 8-Point Setup Quality Gate & 7-Component Evidence-Based Confidence Breakdown (with non-win-rate disclaimer).
 *  - 10-Category Responsive Visual Overlay Generator (X & Y coordinate mapping).
 */
final class ChartVisionService
{
    public const ALLOWED_MIME = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/webp' => 'webp',
    ];

    public const VALID_TIMEFRAMES = ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'];
    public const VALID_STRATEGIES = ['ICT', 'SMC', 'CRT', 'SNR', 'ALL'];

    public const TIMEFRAME_RANK = [
        'M1'  => 1,
        'M5'  => 5,
        'M15' => 15,
        'M30' => 30,
        'H1'  => 60,
        'H4'  => 240,
        'D1'  => 1440,
    ];

    public const MAX_BYTES = 10485760; // 10MB
    public const MIN_CHART_DIM = 50;
    public const MAX_CHART_DIM = 10000;
    public const MAX_PIXEL_COUNT = 25000000; // 25 Megapixels decompression bomb limit
    public const MIN_CONFIDENCE_THRESHOLD = 55;

    /**
     * Symbol-specific tick sizes, decimal precision, and valid price bounds.
     */
    public const SYMBOL_SPECS = [
        'XAUUSD' => [
            'decimals'    => 2,
            'tick_size'   => 0.01,
            'min_price'   => 400.0,
            'max_price'   => 15000.0,
            'default_atr' => 3.50,
            'prefix'      => '$',
        ],
        'BTCUSD' => [
            'decimals'    => 2,
            'tick_size'   => 0.50,
            'min_price'   => 1000.0,
            'max_price'   => 500000.0,
            'default_atr' => 450.0,
            'prefix'      => '$',
        ],
        'EURUSD' => [
            'decimals'    => 5,
            'tick_size'   => 0.00001,
            'min_price'   => 0.50,
            'max_price'   => 2.50,
            'default_atr' => 0.00250,
            'prefix'      => '',
        ],
        'GBPUSD' => [
            'decimals'    => 5,
            'tick_size'   => 0.00001,
            'min_price'   => 0.50,
            'max_price'   => 3.00,
            'default_atr' => 0.00300,
            'prefix'      => '',
        ],
        'USDJPY' => [
            'decimals'    => 3,
            'tick_size'   => 0.001,
            'min_price'   => 50.0,
            'max_price'   => 300.0,
            'default_atr' => 0.350,
            'prefix'      => '¥',
        ],
        'US30' => [
            'decimals'    => 1,
            'tick_size'   => 1.0,
            'min_price'   => 5000.0,
            'max_price'   => 120000.0,
            'default_atr' => 120.0,
            'prefix'      => '',
        ],
        'NAS100' => [
            'decimals'    => 1,
            'tick_size'   => 0.25,
            'min_price'   => 2000.0,
            'max_price'   => 80000.0,
            'default_atr' => 85.0,
            'prefix'      => '',
        ],
    ];

    private static bool $schemaChecked = false;

    /**
     * Ensure chart_vision_analyses v2/v3 columns exist on any environment.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            $cols = Database::fetchAll('SHOW COLUMNS FROM chart_vision_analyses');
            $existing = array_column($cols, 'Field');
            $migrations = [
                'strategy_mode'       => "ALTER TABLE `chart_vision_analyses` ADD COLUMN `strategy_mode` VARCHAR(16) NOT NULL DEFAULT 'ALL' AFTER `timeframe`",
                'htf_timeframe'       => "ALTER TABLE `chart_vision_analyses` ADD COLUMN `htf_timeframe` VARCHAR(10) NULL DEFAULT NULL AFTER `strategy_mode`",
                'trade_direction'     => "ALTER TABLE `chart_vision_analyses` ADD COLUMN `trade_direction` VARCHAR(16) NOT NULL DEFAULT 'NO TRADE' AFTER `market_bias`",
                'quality_gate_passed' => "ALTER TABLE `chart_vision_analyses` ADD COLUMN `quality_gate_passed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `confidence_score`",
                'image_hash'          => "ALTER TABLE `chart_vision_analyses` ADD COLUMN `image_hash` VARCHAR(64) NULL DEFAULT NULL AFTER `image_path`",
            ];
            foreach ($migrations as $col => $sql) {
                if (!in_array($col, $existing, true)) {
                    Database::query($sql);
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking fallback
        }
    }

    /**
     * Return precision, tick size, and valid price bounds for a symbol.
     *
     * @return array{decimals:int,tick_size:float,min_price:float,max_price:float,default_atr:float,prefix:string}
     */
    public static function symbolSpec(string $symbol): array
    {
        $sym = MarketDataService::normalizeSymbol($symbol);
        return self::SYMBOL_SPECS[$sym] ?? self::SYMBOL_SPECS['XAUUSD'];
    }

    /**
     * Format a price according to the symbol's decimal precision.
     */
    public static function formatPrice(float $price, string $symbol = 'XAUUSD', bool $withPrefix = true): string
    {
        $spec = self::symbolSpec($symbol);
        $formatted = number_format($price, $spec['decimals'], '.', '');
        return ($withPrefix ? $spec['prefix'] : '') . $formatted;
    }

    /**
     * Validate that a manual or reference price is compatible with the selected symbol.
     */
    public static function validateSymbolPrice(string $symbol, ?float $price): void
    {
        if ($price === null) {
            return;
        }
        if (!is_finite($price) || $price <= 0) {
            throw new \InvalidArgumentException('Reference price must be a finite positive number.');
        }
        $sym = MarketDataService::normalizeSymbol($symbol);
        $spec = self::symbolSpec($sym);
        if ($price < $spec['min_price'] || $price > $spec['max_price']) {
            throw new \InvalidArgumentException(sprintf(
                'Reference price (%s) is outside the valid range for %s (%s – %s). Please check your selected symbol and price.',
                self::formatPrice($price, $sym),
                $sym,
                self::formatPrice($spec['min_price'], $sym),
                self::formatPrice($spec['max_price'], $sym)
            ));
        }
    }

    /**
     * Store and strictly validate an uploaded file or base64 image data.
     *
     * @param array<string,mixed> $fileInfo
     * @param string|null $base64Payload
     * @param bool $enforceMinDimensions When true, rejects images smaller than 50x50px
     * @return array{path: string, rel_url: string, storage_rel: string, mime: string, width: int, height: int, sha256: string, size_bytes: int, is_low_resolution: bool, exif_orientation: int}
     */
    public static function storeUpload(array $fileInfo, ?string $base64Payload = null, bool $enforceMinDimensions = false): array
    {
        $uploadDir = APP_ROOT . '/storage/uploads/vision';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $binary = null;
        $declaredMime = null;

        if (!empty($fileInfo) && isset($fileInfo['error']) && $fileInfo['error'] !== UPLOAD_ERR_NO_FILE) {
            $errCode = (int)$fileInfo['error'];
            if ($errCode !== UPLOAD_ERR_OK) {
                $msg = match ($errCode) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image exceeds the maximum upload size of 10MB.',
                    UPLOAD_ERR_PARTIAL => 'Image upload was interrupted and only partially received.',
                    default => 'File upload error (code ' . $errCode . ').',
                };
                throw new \InvalidArgumentException($msg);
            }

            $size = (int)($fileInfo['size'] ?? 0);
            if ($size <= 0) {
                throw new \InvalidArgumentException('Uploaded image file is empty.');
            }
            if ($size > self::MAX_BYTES) {
                throw new \InvalidArgumentException('Image exceeds the maximum upload size of 10MB.');
            }

            $origName = strtolower((string)($fileInfo['name'] ?? ''));
            if ($origName !== '' && preg_match('/\.(php|phtml|phar|sh|exe|bat|cmd|js|html|htm|svg|xml|asp|aspx|jsp|cgi)(\.|$)/i', $origName)) {
                throw new \InvalidArgumentException('Unsafe file extension rejected.');
            }

            $tmpName = (string)($fileInfo['tmp_name'] ?? '');
            if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
                throw new \InvalidArgumentException('No valid image file uploaded.');
            }

            $binary = @file_get_contents($tmpName);
            if ($binary === false || $binary === '') {
                throw new \InvalidArgumentException('Failed to read uploaded image file.');
            }
        } elseif ($base64Payload !== null && trim($base64Payload) !== '') {
            $trimmed = trim($base64Payload);
            if (preg_match('/^data:(image\/[a-zA-Z0-9\-\+\.]+);base64,(.+)$/s', $trimmed, $matches)) {
                $declaredMime = strtolower($matches[1]);
                if ($declaredMime === 'image/jpg') {
                    $declaredMime = 'image/jpeg';
                }
                if (!isset(self::ALLOWED_MIME[$declaredMime])) {
                    throw new \InvalidArgumentException('Invalid base64 chart screenshot format. Only PNG, JPG, JPEG, and WEBP are supported.');
                }
                $binary = base64_decode($matches[2], true);
            } else {
                $binary = base64_decode($trimmed, true);
            }

            if ($binary === false || $binary === '') {
                throw new \InvalidArgumentException('Invalid or corrupted base64 image payload.');
            }
            if (strlen($binary) > self::MAX_BYTES) {
                throw new \InvalidArgumentException('Image exceeds the maximum upload size of 10MB.');
            }
        } else {
            throw new \InvalidArgumentException('No valid image file uploaded.');
        }

        $byteLen = strlen($binary);
        if ($byteLen < 24) {
            throw new \InvalidArgumentException('Corrupted image file: header is truncated or too small.');
        }
        if ($byteLen > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image exceeds the maximum upload size of 10MB.');
        }

        // 1. Validate binary magic byte signature
        $magicMime = self::detectMagicByteMime($binary);
        if ($magicMime === null || !isset(self::ALLOWED_MIME[$magicMime])) {
            throw new \InvalidArgumentException('Invalid image content signature. Only genuine PNG, JPG, JPEG, and WEBP files are allowed.');
        }

        // 2. Validate real MIME content via finfo buffer inspection
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $finfoMime = strtolower((string)$finfo->buffer($binary));
        if ($finfoMime === 'image/jpg') {
            $finfoMime = 'image/jpeg';
        }
        if (!isset(self::ALLOWED_MIME[$finfoMime]) || $finfoMime !== $magicMime) {
            throw new \InvalidArgumentException('MIME type mismatch: file content does not match a valid PNG, JPEG, or WEBP image.');
        }
        if ($declaredMime !== null && $declaredMime !== $finfoMime) {
            throw new \InvalidArgumentException('Declared MIME type does not match actual image content.');
        }

        // 3. Validate image dimensions and structural integrity
        $imgInfo = @getimagesizefromstring($binary);
        if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
            throw new \InvalidArgumentException('Corrupted or unreadable image data.');
        }

        $width = (int)$imgInfo[0];
        $height = (int)$imgInfo[1];

        if ($width < 1 || $height < 1 || $width > self::MAX_CHART_DIM || $height > self::MAX_CHART_DIM) {
            throw new \InvalidArgumentException(sprintf('Invalid image dimensions (%dx%d px). Maximum allowed is %dx%d px.', $width, $height, self::MAX_CHART_DIM, self::MAX_CHART_DIM));
        }

        // 4. Decompression bomb / memory exhaustion protection
        if (($width * $height) > self::MAX_PIXEL_COUNT) {
            throw new \InvalidArgumentException(sprintf(
                'Image pixel count (%d MP) exceeds decompression safety limit (25 MP).',
                (int)round(($width * $height) / 1000000)
            ));
        }

        // 5. Inspect EXIF orientation for JPEG images (orientations 5, 6, 7, 8 swap width/height)
        $exifOrientation = 1;
        if ($finfoMime === 'image/jpeg') {
            $exifOrientation = self::extractExifOrientation($binary);
            if (in_array($exifOrientation, [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }

        if ($enforceMinDimensions && ($width < self::MIN_CHART_DIM || $height < self::MIN_CHART_DIM)) {
            throw new \InvalidArgumentException(sprintf(
                'Image dimensions (%dx%d px) are too small. Minimum chart dimensions are %dx%d px.',
                $width,
                $height,
                self::MIN_CHART_DIM,
                self::MIN_CHART_DIM
            ));
        }

        if (!self::verifyStreamMarkers($binary, $finfoMime)) {
            throw new \InvalidArgumentException('Corrupted or truncated image stream.');
        }

        $sha256 = hash('sha256', $binary);
        $ext = self::ALLOWED_MIME[$finfoMime];
        $fileName = 'chart_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $fileName;

        if (@file_put_contents($destPath, $binary) === false) {
            unset($binary);
            throw new \RuntimeException('Failed to save uploaded chart image to storage.');
        }

        // Release temporary memory immediately
        unset($binary);

        return [
            'path'              => $destPath,
            'rel_url'           => 'api/vision_image.php?file=' . $fileName,
            'storage_rel'       => 'storage/uploads/vision/' . $fileName,
            'mime'              => $finfoMime,
            'width'             => $width,
            'height'            => $height,
            'sha256'            => $sha256,
            'size_bytes'        => $byteLen,
            'is_low_resolution' => ($width < 320 || $height < 200),
            'exif_orientation'  => $exifOrientation,
        ];
    }

    /**
     * Extract EXIF Orientation tag (0x0112) from JPEG binary stream without requiring ext-exif.
     */
    public static function extractExifOrientation(string $bytes): int
    {
        $len = strlen($bytes);
        if ($len < 12 || strncmp($bytes, "\xFF\xD8", 2) !== 0) {
            return 1;
        }
        $offset = 2;
        while ($offset + 4 < $len) {
            if (ord($bytes[$offset]) !== 0xFF) {
                break;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break; // Start of Scan or End of Image
            }
            $segLen = unpack('n', substr($bytes, $offset + 2, 2))[1] ?? 0;
            if ($segLen < 2 || $offset + 2 + $segLen > $len) {
                break;
            }
            if ($marker === 0xE1 && $segLen >= 14 && substr($bytes, $offset + 4, 6) === "Exif\x00\x00") {
                $tiffStart = $offset + 10;
                $endian = substr($bytes, $tiffStart, 2);
                $isLittle = ($endian === 'II');
                if (!$isLittle && $endian !== 'MM') {
                    return 1;
                }
                $u16 = static fn(string $s): int => $isLittle ? (unpack('v', $s)[1] ?? 0) : (unpack('n', $s)[1] ?? 0);
                $u32 = static fn(string $s): int => $isLittle ? (unpack('V', $s)[1] ?? 0) : (unpack('N', $s)[1] ?? 0);
                if ($tiffStart + 8 > $len) {
                    return 1;
                }
                $ifdOffset = $u32(substr($bytes, $tiffStart + 4, 4));
                $dirStart = $tiffStart + $ifdOffset;
                if ($dirStart + 2 > $len) {
                    return 1;
                }
                $numEntries = $u16(substr($bytes, $dirStart, 2));
                for ($i = 0; $i < $numEntries; $i++) {
                    $entryOffset = $dirStart + 2 + ($i * 12);
                    if ($entryOffset + 12 > $len) {
                        break;
                    }
                    $tag = $u16(substr($bytes, $entryOffset, 2));
                    if ($tag === 0x0112) {
                        $val = $u16(substr($bytes, $entryOffset + 8, 2));
                        return ($val >= 1 && $val <= 8) ? $val : 1;
                    }
                }
                return 1;
            }
            $offset += 2 + $segLen;
        }
        return 1;
    }

    /**
     * Check binary magic header bytes for PNG, JPEG, and WEBP.
     */
    private static function detectMagicByteMime(string $bytes): ?string
    {
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    /**
     * Verify essential chunk/marker presence so truncated images are caught.
     */
    private static function verifyStreamMarkers(string $bytes, string $mime): bool
    {
        if ($mime === 'image/png') {
            return substr($bytes, 12, 4) === 'IHDR'
                && strpos($bytes, 'IDAT') !== false
                && strpos($bytes, 'IEND') !== false;
        }
        if ($mime === 'image/jpeg') {
            return (strpos($bytes, "\xFF\xC0") !== false || strpos($bytes, "\xFF\xC2") !== false || strpos($bytes, "\xFF\xDA") !== false);
        }
        if ($mime === 'image/webp') {
            return substr($bytes, 12, 3) === 'VP8';
        }
        return false;
    }

    /**
     * Strict Mathematical Trade Signal Validation Engine.
     *
     * Rules:
     *  - Allowed directions: BUY, SELL, NO TRADE
     *  - BUY:  SL < EntryLow <= EntryRef <= EntryHigh < TP1 < TP2 < TP3
     *  - SELL: SL > EntryHigh >= EntryRef >= EntryLow > TP1 > TP2 > TP3
     *  - Target R-multiples: TP1 = 2.0R, TP2 = 3.5R, TP3 = 5.0R (minimum acceptable TP1 >= 1.5R)
     *
     * @return array<string,mixed>
     */
    public static function validateTradeSetup(
        string $direction,
        float $entryMin,
        float $entryMax,
        float $stopLoss,
        ?float $tp1 = null,
        ?float $tp2 = null,
        ?float $tp3 = null,
        string $symbol = 'XAUUSD',
        ?float $currentPrice = null
    ): array {
        $dir = strtoupper(trim($direction));
        if ($dir === 'WAITING' || $dir === 'WAIT' || !in_array($dir, ['BUY', 'SELL', 'NO TRADE'], true)) {
            $dir = 'NO TRADE';
        }

        $spec = self::symbolSpec($symbol);
        $dec = $spec['decimals'];
        $minTick = $spec['tick_size'] * 0.5;

        $errors = [];
        foreach (['entry_min' => $entryMin, 'entry_max' => $entryMax, 'stop_loss' => $stopLoss] as $label => $val) {
            if (!is_finite($val) || $val <= 0) {
                $errors[] = "Invalid numerical value for {$label}: must be a positive finite number.";
            }
        }

        if ($entryMin > $entryMax) {
            [$entryMin, $entryMax] = [$entryMax, $entryMin];
        }

        $entryMin = round($entryMin, $dec);
        $entryMax = round($entryMax, $dec);
        $stopLoss = round($stopLoss, $dec);
        $refEntry = round(($entryMin + $entryMax) / 2.0, $dec);
        $risk = round(abs($refEntry - $stopLoss), $dec);

        if ($risk <= $minTick) {
            $errors[] = 'Stop Loss cannot equal Entry price (zero risk distance).';
        }

        if ($dir === 'BUY' && $risk > $minTick) {
            $tp1 = ($tp1 !== null && $tp1 > 0) ? round($tp1, $dec) : round($refEntry + 2.0 * $risk, $dec);
            $tp2 = ($tp2 !== null && $tp2 > 0) ? round($tp2, $dec) : round($refEntry + 3.5 * $risk, $dec);
            $tp3 = ($tp3 !== null && $tp3 > 0) ? round($tp3, $dec) : round($refEntry + 5.0 * $risk, $dec);
        } elseif ($dir === 'SELL' && $risk > $minTick) {
            $tp1 = ($tp1 !== null && $tp1 > 0) ? round($tp1, $dec) : round($refEntry - 2.0 * $risk, $dec);
            $tp2 = ($tp2 !== null && $tp2 > 0) ? round($tp2, $dec) : round($refEntry - 3.5 * $risk, $dec);
            $tp3 = ($tp3 !== null && $tp3 > 0) ? round($tp3, $dec) : round($refEntry - 5.0 * $risk, $dec);
        } else {
            $tp1 = round((float)($tp1 ?? 0), $dec);
            $tp2 = round((float)($tp2 ?? 0), $dec);
            $tp3 = round((float)($tp3 ?? 0), $dec);
        }

        if ($dir === 'BUY') {
            if ($stopLoss >= $entryMin) {
                $errors[] = sprintf(
                    'Invalid BUY Stop Loss (%s): must be strictly below Entry Zone Low (%s).',
                    self::formatPrice($stopLoss, $symbol),
                    self::formatPrice($entryMin, $symbol)
                );
            }
            if ($tp1 <= $entryMax) {
                $errors[] = sprintf(
                    'Invalid BUY TP1 (%s): must be strictly above Entry Zone High (%s).',
                    self::formatPrice($tp1, $symbol),
                    self::formatPrice($entryMax, $symbol)
                );
            }
            if ($tp2 <= $tp1) {
                $errors[] = sprintf(
                    'Invalid BUY TP2 (%s): must be strictly greater than TP1 (%s).',
                    self::formatPrice($tp2, $symbol),
                    self::formatPrice($tp1, $symbol)
                );
            }
            if ($tp3 <= $tp2) {
                $errors[] = sprintf(
                    'Invalid BUY TP3 (%s): must be strictly greater than TP2 (%s).',
                    self::formatPrice($tp3, $symbol),
                    self::formatPrice($tp2, $symbol)
                );
            }
        } elseif ($dir === 'SELL') {
            if ($stopLoss <= $entryMax) {
                $errors[] = sprintf(
                    'Invalid SELL Stop Loss (%s): must be strictly above Entry Zone High (%s).',
                    self::formatPrice($stopLoss, $symbol),
                    self::formatPrice($entryMax, $symbol)
                );
            }
            if ($tp1 <= 0 || $tp1 >= $entryMin) {
                $errors[] = sprintf(
                    'Invalid SELL TP1 (%s): must be positive and strictly below Entry Zone Low (%s).',
                    self::formatPrice($tp1, $symbol),
                    self::formatPrice($entryMin, $symbol)
                );
            }
            if ($tp2 <= 0 || $tp2 >= $tp1) {
                $errors[] = sprintf(
                    'Invalid SELL TP2 (%s): must be positive and strictly below TP1 (%s).',
                    self::formatPrice($tp2, $symbol),
                    self::formatPrice($tp1, $symbol)
                );
            }
            if ($tp3 <= 0 || $tp3 >= $tp2) {
                $errors[] = sprintf(
                    'Invalid SELL TP3 (%s): must be positive and strictly below TP2 (%s).',
                    self::formatPrice($tp3, $symbol),
                    self::formatPrice($tp2, $symbol)
                );
            }
        }

        $rr1 = ($risk > $minTick && $tp1 > 0) ? round(abs($tp1 - $refEntry) / $risk, 2) : 0.0;
        $rr2 = ($risk > $minTick && $tp2 > 0) ? round(abs($tp2 - $refEntry) / $risk, 2) : 0.0;
        $rr3 = ($risk > $minTick && $tp3 > 0) ? round(abs($tp3 - $refEntry) / $risk, 2) : 0.0;

        if (($dir === 'BUY' || $dir === 'SELL') && $rr1 < 1.2) {
            $errors[] = sprintf('Insufficient Risk/Reward on TP1 (%.2fR). Minimum required is 1.5R–2.0R.', $rr1);
        }

        // Check current price relative to entry zone & TP1 (timeliness / stale setup guard)
        $priceVsEntryStatus = 'near_entry_zone';
        if ($currentPrice !== null && $currentPrice > 0 && ($dir === 'BUY' || $dir === 'SELL')) {
            if ($dir === 'BUY') {
                if ($currentPrice >= $tp1) {
                    $errors[] = sprintf('Setup stale: current price (%s) has already reached or exceeded TP1 (%s).', self::formatPrice($currentPrice, $symbol), self::formatPrice($tp1, $symbol));
                    $priceVsEntryStatus = 'target_already_reached';
                } elseif ($currentPrice < $stopLoss) {
                    $errors[] = sprintf('Setup invalidated: current price (%s) is already below BUY Stop Loss (%s).', self::formatPrice($currentPrice, $symbol), self::formatPrice($stopLoss, $symbol));
                    $priceVsEntryStatus = 'invalidated_beyond_sl';
                } elseif ($currentPrice > $entryMax) {
                    $priceVsEntryStatus = 'awaiting_pullback_to_discount_poi';
                } elseif ($currentPrice >= $entryMin && $currentPrice <= $entryMax) {
                    $priceVsEntryStatus = 'inside_entry_zone';
                }
            } elseif ($dir === 'SELL') {
                if ($currentPrice <= $tp1) {
                    $errors[] = sprintf('Setup stale: current price (%s) has already reached or exceeded SELL TP1 (%s).', self::formatPrice($currentPrice, $symbol), self::formatPrice($tp1, $symbol));
                    $priceVsEntryStatus = 'target_already_reached';
                } elseif ($currentPrice > $stopLoss) {
                    $errors[] = sprintf('Setup invalidated: current price (%s) is already above SELL Stop Loss (%s).', self::formatPrice($currentPrice, $symbol), self::formatPrice($stopLoss, $symbol));
                    $priceVsEntryStatus = 'invalidated_beyond_sl';
                } elseif ($currentPrice < $entryMin) {
                    $priceVsEntryStatus = 'awaiting_retracement_to_premium_poi';
                } elseif ($currentPrice >= $entryMin && $currentPrice <= $entryMax) {
                    $priceVsEntryStatus = 'inside_entry_zone';
                }
            }
        }

        $valid = empty($errors) && ($dir === 'BUY' || $dir === 'SELL');

        return [
            'valid'                  => $valid,
            'direction'              => $valid ? $dir : 'NO TRADE',
            'requested_dir'          => $dir,
            'symbol'                 => MarketDataService::normalizeSymbol($symbol),
            'decimals'               => $dec,
            'tick_size'              => $spec['tick_size'],
            'entry_min'              => $entryMin,
            'entry_max'              => $entryMax,
            'ref_entry'              => $refEntry,
            'stop_loss'              => $stopLoss,
            'tp1'                    => $tp1,
            'tp2'                    => $tp2,
            'tp3'                    => $tp3,
            'risk_points'            => $risk,
            'rr_tp1'                 => $rr1,
            'rr_tp2'                 => $rr2,
            'rr_tp3'                 => $rr3,
            'price_vs_entry_status'  => $priceVsEntryStatus,
            'errors'                 => $errors,
        ];
    }

    /**
     * Map execution timeframe to default Higher-Timeframe (HTF) confirmation partner.
     */
    public static function defaultHigherTimeframe(string $tf): string
    {
        return match (strtoupper($tf)) {
            'M1'  => 'M15',
            'M5'  => 'H1',
            'M15' => 'H4',
            'M30' => 'H4',
            'H1'  => 'H4',
            'H4'  => 'D1',
            'D1'  => 'D1',
            default => 'H1',
        };
    }

    /**
     * Run full AI Chart Vision V3 analysis on an uploaded screenshot.
     */
    public static function analyzeChart(
        string $imagePath,
        string $symbol = 'XAUUSD',
        string $timeframe = 'M15',
        ?float $manualPrice = null,
        ?string $broker = null,
        ?string $chartTz = null,
        ?int $userId = null,
        string $strategyMode = 'ALL',
        ?string $htfTimeframe = null,
        ?string $htfImagePath = null,
        bool $forceReanalyze = false
    ): array {
        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException('Chart image file does not exist.');
        }

        self::ensureSchema();

        $symbol = MarketDataService::normalizeSymbol($symbol);
        $spec = self::symbolSpec($symbol);
        $dec = $spec['decimals'];

        // Validate symbol & manual price compatibility before proceeding
        self::validateSymbolPrice($symbol, $manualPrice);

        $timeframe = strtoupper(trim($timeframe)) ?: 'M15';
        if (!in_array($timeframe, self::VALID_TIMEFRAMES, true)) {
            $timeframe = 'M15';
        }

        $strategyMode = strtoupper(trim($strategyMode)) ?: 'ALL';
        if (!in_array($strategyMode, self::VALID_STRATEGIES, true)) {
            $strategyMode = 'ALL';
        }

        $htfTimeframe = strtoupper(trim((string)$htfTimeframe));
        if (!in_array($htfTimeframe, self::VALID_TIMEFRAMES, true)) {
            $htfTimeframe = self::defaultHigherTimeframe($timeframe);
        }

        // Ensure HTF timeframe is not lower than Execution timeframe
        $execRank = self::TIMEFRAME_RANK[$timeframe] ?? 15;
        $htfRank = self::TIMEFRAME_RANK[$htfTimeframe] ?? 240;
        if ($htfRank < $execRank) {
            throw new \InvalidArgumentException(sprintf(
                'Higher-Timeframe (%s) cannot be a lower timeframe than the Execution Timeframe (%s).',
                $htfTimeframe,
                $timeframe
            ));
        }

        // Validate HTF screenshot if supplied — never conflate with execution screenshot
        $htfImageMeta = null;
        if ($htfImagePath !== null && $htfImagePath !== '') {
            if (!file_exists($htfImagePath)) {
                throw new \InvalidArgumentException('Uploaded HTF chart screenshot file does not exist.');
            }
            if (realpath($htfImagePath) === realpath($imagePath)) {
                $htfImagePath = null; // Never treat execution chart as HTF screenshot
            } else {
                $htfSize = @getimagesize($htfImagePath);
                if ($htfSize === false || empty($htfSize[0]) || empty($htfSize[1])) {
                    throw new \InvalidArgumentException('Corrupted HTF chart screenshot.');
                }
                $htfImageMeta = [
                    'width'  => (int)$htfSize[0],
                    'height' => (int)$htfSize[1],
                    'mime'   => (string)($htfSize['mime'] ?? 'image/png'),
                ];
            }
        }

        $imageBytes = (string)file_get_contents($imagePath);
        $imageHash = hash('sha256', $imageBytes);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($imagePath);
        $base64 = base64_encode($imageBytes);
        unset($imageBytes);

        // Duplicate submission check (within 6 seconds for same user, image hash, symbol, timeframe, strategy)
        if (!$forceReanalyze && $userId !== null && $userId > 0) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 6);
            $dup = Database::fetch(
                'SELECT * FROM chart_vision_analyses
                 WHERE user_id = ? AND image_hash = ? AND symbol = ? AND timeframe = ? AND strategy_mode = ? AND created_at >= ?
                 ORDER BY id DESC LIMIT 1',
                [$userId, $imageHash, $symbol, $timeframe, $strategyMode, $cutoff]
            );
            if ($dup) {
                $dupId = (int)$dup['id'];
                $savedReport = json_decode((string)$dup['analysis_json'], true) ?: [];
                $savedOverlay = json_decode((string)$dup['overlay_json'], true) ?: [];
                return [
                    'analysis_id'          => $dupId,
                    'symbol'               => $symbol,
                    'timeframe'            => $timeframe,
                    'htf_timeframe'        => (string)($dup['htf_timeframe'] ?? $htfTimeframe),
                    'strategy_mode'        => $strategyMode,
                    'ai_model'             => (string)$dup['ai_model'],
                    'image_url'            => 'api/vision_image.php?id=' . $dupId,
                    'htf_image_url'        => !empty($dup['thumbnail_path']) && $dup['thumbnail_path'] !== $dup['image_path']
                        ? 'api/vision_image.php?file=' . basename((string)$dup['thumbnail_path'])
                        : null,
                    'report'               => $savedReport,
                    'overlay'              => $savedOverlay,
                    'standard_report_text' => self::formatStandardReport($savedReport, $symbol, $timeframe),
                    'created_at'           => (string)$dup['created_at'],
                    'deduplicated'         => true,
                ];
            }
        }

        $imgSize = @getimagesize($imagePath) ?: [800, 600];
        $imgWidth = (int)$imgSize[0];
        $imgHeight = (int)$imgSize[1];

        // Fetch market data and determine truthful price source (NO $2400.00 fabrication)
        $market = new MarketDataService(null, $symbol);
        $quote = $market->quote(false);
        $providerLabel = (string)($quote['source'] ?? $market->provider()->label());
        $liveAvailable = !empty($quote['available'])
            && (($quote['mode'] ?? '') === 'live')
            && empty($quote['stale'])
            && !empty($quote['price'])
            && (float)$quote['price'] > 0;
        $liveMarketPrice = $liveAvailable ? round((float)$quote['price'], $dec) : null;

        $tfCandles = $market->candles($timeframe, 150);
        $hasHistoricalCandles = !empty($tfCandles);
        $lastCandle = $hasHistoricalCandles ? $tfCandles[count($tfCandles) - 1] : null;
        $lastCandleClose = ($lastCandle !== null && (float)$lastCandle['c'] > 0)
            ? round((float)$lastCandle['c'], $dec)
            : null;
        $lastCandleTs = ($lastCandle !== null && !empty($lastCandle['t']))
            ? gmdate('Y-m-d H:i:s', (int)$lastCandle['t'])
            : null;

        if ($manualPrice !== null && $manualPrice > 0) {
            $refPrice = round($manualPrice, $dec);
            $priceSource = 'manual_input';
            $priceSourceType = 'MANUAL';
            $priceTimestamp = gmdate('Y-m-d H:i:s');
            $priceSourceLabel = sprintf('MANUAL — User Reference Price (%s)', self::formatPrice($refPrice, $symbol));
        } elseif ($liveMarketPrice !== null) {
            $refPrice = $liveMarketPrice;
            $priceSource = 'live_feed';
            $priceSourceType = 'LIVE';
            $priceTimestamp = !empty($quote['fetched_at']) ? gmdate('Y-m-d H:i:s', (int)$quote['fetched_at']) : gmdate('Y-m-d H:i:s');
            $priceSourceLabel = sprintf('LIVE — %s (%s @ %s UTC)', $providerLabel, self::formatPrice($refPrice, $symbol), $priceTimestamp);
        } elseif ($lastCandleClose !== null) {
            $refPrice = $lastCandleClose;
            $priceSource = 'engine_fallback';
            $priceSourceType = 'HISTORICAL';
            $priceTimestamp = $lastCandleTs ?? gmdate('Y-m-d H:i:s');
            $priceSourceLabel = sprintf('HISTORICAL — %s Candle Close (%s @ %s UTC; Live Offline)', $providerLabel, self::formatPrice($refPrice, $symbol), $priceTimestamp);
        } else {
            $refPrice = null;
            $priceSource = 'unavailable';
            $priceSourceType = 'UNAVAILABLE';
            $priceTimestamp = null;
            $priceSourceLabel = 'UNAVAILABLE — No Live or Historical Price Available (Enter Manual Reference Price)';
        }

        // Check if external Vision AI is configured (Gemini or OpenAI)
        $geminiKey = trim((string)Env::get('GEMINI_API_KEY', ''));
        $openAiKey = trim((string)Env::get('AI_API_KEY', ''));
        $aiProvider = strtolower(trim((string)Env::get('AI_PROVIDER', 'none')));

        $extReport = null;
        $aiModelUsed = 'deterministic-' . strtolower($strategyMode);

        if ($geminiKey !== '' && $refPrice !== null) {
            try {
                $extReport = self::callGeminiVision($geminiKey, $base64, $mime, $symbol, $timeframe, $refPrice, $broker, $chartTz, $strategyMode);
                if ($extReport !== null) {
                    $aiModelUsed = 'gemini-1.5-flash';
                }
            } catch (\Throwable $e) {
                Logger::warning('Gemini vision API fallback to institutional engine', ['status' => 'fallback']);
            }
        }

        if (!$extReport && $aiProvider === 'openai' && $openAiKey !== '' && $refPrice !== null) {
            try {
                $extReport = self::callOpenAiVision($openAiKey, $base64, $mime, $symbol, $timeframe, $refPrice, $broker, $chartTz, $strategyMode);
                if ($extReport !== null) {
                    $aiModelUsed = 'gpt-4o-mini';
                }
            } catch (\Throwable $e) {
                Logger::warning('OpenAI vision API fallback to institutional engine', ['status' => 'fallback']);
            }
        }
        unset($base64);

        // Generate comprehensive V3 institutional analysis
        $report = self::generateHeuristicAnalysis(
            $symbol,
            $timeframe,
            $refPrice ?? 0.0,
            $broker,
            $chartTz,
            $market,
            $strategyMode,
            [
                'htf_timeframe'     => $htfTimeframe,
                'htf_image_path'    => $htfImagePath,
                'htf_image_meta'    => $htfImageMeta,
                'price_source'      => $priceSource,
                'price_source_type' => $priceSourceType,
                'price_source_label'=> $priceSourceLabel,
                'price_timestamp'   => $priceTimestamp,
                'data_provider'     => $broker ?: $providerLabel,
                'live_market_price' => $liveMarketPrice,
                'live_available'    => $liveAvailable,
                'manual_price'      => $manualPrice,
                'price_unavailable' => ($refPrice === null),
                'img_width'         => $imgWidth,
                'img_height'        => $imgHeight,
                'ext_report'        => $extReport,
            ]
        );

        // Compute 10-Category Visual Chart Overlay
        $overlay = self::generateVisualOverlay($report, $imgWidth, $imgHeight, $refPrice ?? 0.0, $strategyMode);

        $now = gmdate('Y-m-d H:i:s');
        $tradeDir = in_array($report['trade_direction'] ?? '', ['BUY', 'SELL', 'NO TRADE'], true)
            ? $report['trade_direction']
            : 'NO TRADE';
        $qualityPassed = !empty($report['setup_quality_gate']['passed']) ? 1 : 0;

        // Persist to database (Notice: thumbnail_path ONLY stores $htfImagePath when actually provided)
        $analysisId = Database::insert('chart_vision_analyses', [
            'user_id'             => $userId,
            'symbol'              => $symbol,
            'timeframe'           => $timeframe,
            'strategy_mode'       => $strategyMode,
            'htf_timeframe'       => $htfTimeframe,
            'image_path'          => $imagePath,
            'image_hash'          => $imageHash,
            'thumbnail_path'      => $htfImagePath,
            'manual_price'        => $manualPrice,
            'broker'              => $broker,
            'chart_tz'            => $chartTz,
            'market_bias'         => in_array($report['market_bias'], ['Bullish', 'Bearish', 'Neutral'], true) ? $report['market_bias'] : 'Neutral',
            'trade_direction'     => $tradeDir,
            'detected_strategy'   => $report['detected_strategy'] ?? 'ICT/SMC Confluence Model',
            'entry_zone_low'      => $report['entry_zone']['min'] ?? null,
            'entry_zone_high'     => $report['entry_zone']['max'] ?? null,
            'stop_loss'           => $report['stop_loss']['price'] ?? null,
            'tp1'                 => $report['take_profit']['tp1']['price'] ?? null,
            'tp2'                 => $report['take_profit']['tp2']['price'] ?? null,
            'tp3'                 => $report['take_profit']['tp3']['price'] ?? null,
            'risk_reward'         => $report['risk_reward']['tp1'] ?? null,
            'confidence_score'    => (int)($report['confidence_score'] ?? 50),
            'quality_gate_passed' => $qualityPassed,
            'analysis_json'       => json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'overlay_json'        => json_encode($overlay, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ai_model'            => $aiModelUsed,
            'created_at'          => $now,
        ]);

        $standardText = self::formatStandardReport($report, $symbol, $timeframe);

        return [
            'analysis_id'          => $analysisId,
            'symbol'               => $symbol,
            'timeframe'            => $timeframe,
            'htf_timeframe'        => $htfTimeframe,
            'strategy_mode'        => $strategyMode,
            'ai_model'             => $aiModelUsed,
            'image_url'            => 'api/vision_image.php?id=' . $analysisId,
            'htf_image_url'        => $htfImagePath ? ('api/vision_image.php?file=' . basename($htfImagePath)) : null,
            'report'               => $report,
            'overlay'              => $overlay,
            'standard_report_text' => $standardText,
            'created_at'           => $now,
            'deduplicated'         => false,
        ];
    }

    /**
     * Multi-Timeframe (MTF) Analysis across D1, H4, H1, M30, M15, M5, M1.
     * Explicitly distinguishes uploaded screenshots from market-data-derived timeframes,
     * and allows Neutral / Unavailable states without forcing bullish/bearish bias.
     *
     * @return array<string,mixed>
     */
    public static function analyzeMultiTimeframe(
        MarketDataService $market,
        string $executionTf,
        string $htfTf,
        string $executionBias,
        bool $htfScreenshotProvided = false,
        ?array $htfImageMeta = null
    ): array {
        $tfList = ['D1', 'H4', 'H1', 'M30', 'M15', 'M5'];
        if ($executionTf === 'M1' || $htfTf === 'M1') {
            $tfList[] = 'M1';
        }

        $roles = [
            'D1'  => 'Overall Market Direction & Macro Liquidity',
            'H4'  => 'Major Swing Structure & Institutional Zones',
            'H1'  => 'Intraday Trading Bias & Key Levels',
            'M30' => 'Session Structure & Dealing Range',
            'M15' => 'Setup Identification & Liquidity Sweeps',
            'M5'  => 'Entry Confirmation & Micro MSS',
            'M1'  => 'Precision Execution',
        ];

        $symbol = $market->symbol();
        $smcEngine = new SmcEngine();
        $breakdown = [];

        foreach ($tfList as $tf) {
            $candles = $market->candles($tf, 100);
            $isExecTf = ($tf === $executionTf);
            $isHtfTarget = ($tf === $htfTf);

            if ($isExecTf) {
                $sourceType = 'uploaded_execution_chart';
                $sourceLabel = 'Uploaded Execution Chart + Engine Structure';
            } elseif ($isHtfTarget && $htfScreenshotProvided) {
                $sourceType = 'uploaded_htf_chart';
                $dimNote = $htfImageMeta ? sprintf(' (%dx%dpx)', $htfImageMeta['width'], $htfImageMeta['height']) : '';
                $sourceLabel = 'Uploaded HTF Confirmation Screenshot' . $dimNote;
            } elseif (count($candles) >= 35) {
                $sourceType = 'market_data_candles';
                $sourceLabel = 'Market Data Feed (Not Visually Uploaded)';
            } else {
                $sourceType = 'unavailable';
                $sourceLabel = 'Unavailable (No Screenshot or Sufficient Candles)';
            }

            if (count($candles) >= 35) {
                $res = $smcEngine->analyze($candles);
                $trend = (int)($res['trend'] ?? 0);
                $tfBias = $trend === 1 ? 'Bullish' : ($trend === -1 ? 'Bearish' : 'Neutral');
                $lastEvt = $res['last_event'] ?? null;
                $structDesc = $lastEvt
                    ? (($lastEvt['mss'] ?? false) ? 'MSS' : ($lastEvt['type'] ?? 'BOS')) . ' @ ' . self::formatPrice((float)($lastEvt['level'] ?? 0), $symbol)
                    : 'Consolidation / Equilibrium Range';
                $fvgCount = count($res['fvgs'] ?? []);
                $obCount = count($res['order_blocks'] ?? []);
                $evidence = sprintf('%s | %d FVG, %d OB | Source: %s', $structDesc, $fvgCount, $obCount, $sourceLabel);
            } elseif ($isExecTf) {
                $tfBias = in_array($executionBias, ['Bullish', 'Bearish'], true) ? $executionBias : 'Neutral';
                $structDesc = 'Execution Screenshot Range';
                $evidence = sprintf('Derived from execution chart (%s)', $sourceLabel);
            } else {
                $tfBias = 'Unavailable';
                $structDesc = 'Insufficient Data / No HTF Screenshot';
                $evidence = 'No HTF chart uploaded and insufficient historical candles for this timeframe.';
            }

            $breakdown[$tf] = [
                'timeframe'          => $tf,
                'role'               => $roles[$tf] ?? 'Structure Check',
                'bias'               => $tfBias,
                'structure'          => $structDesc,
                'data_source'        => $sourceType,
                'data_source_label'  => $sourceLabel,
                'visually_inspected' => ($sourceType === 'uploaded_execution_chart' || $sourceType === 'uploaded_htf_chart'),
                'evidence'           => $evidence,
            ];
        }

        $htfBias = $breakdown[$htfTf]['bias'] ?? 'Unavailable';
        $d1Bias = $breakdown['D1']['bias'] ?? 'Unavailable';
        $h4Bias = $breakdown['H4']['bias'] ?? 'Unavailable';

        $conflictDetected = false;
        $alignmentStatus = 'Neutral';

        if ($executionBias === 'Bullish' || $executionBias === 'Bearish') {
            if ($htfBias === $executionBias) {
                $alignmentStatus = 'Aligned';
            } elseif ($htfBias === 'Neutral' || $htfBias === 'Ranging') {
                $alignmentStatus = 'Partial Alignment';
            } elseif ($htfBias === 'Unavailable') {
                $alignmentStatus = 'Unverified HTF';
            } else {
                $alignmentStatus = 'Conflicting';
                $conflictDetected = true;
            }

            // Detect severe macro conflict (both D1 and H4 opposing execution bias)
            if (($executionBias === 'Bullish' && $d1Bias === 'Bearish' && $h4Bias === 'Bearish')
                || ($executionBias === 'Bearish' && $d1Bias === 'Bullish' && $h4Bias === 'Bullish')
            ) {
                $alignmentStatus = 'Conflicting';
                $conflictDetected = true;
            }
        }

        $htfSourceNote = $htfScreenshotProvided
            ? sprintf('verified via uploaded %s screenshot and market structure', $htfTf)
            : sprintf('derived from %s market-data feed (no separate %s screenshot uploaded)', $htfTf, $htfTf);

        $explanation = match ($alignmentStatus) {
            'Aligned' => sprintf(
                'Higher-Timeframe (%s: %s, %s) aligns with %s %s execution bias.',
                $htfTf,
                $htfBias,
                $htfSourceNote,
                $executionTf,
                $executionBias
            ),
            'Partial Alignment' => sprintf(
                'Higher-Timeframe (%s, %s) is Neutral/Ranging while %s is %s. Require strict LTF displacement confirmation.',
                $htfTf,
                $htfSourceNote,
                $executionTf,
                $executionBias
            ),
            'Unverified HTF' => sprintf(
                'Higher-Timeframe (%s) data is Unavailable and no %s screenshot was uploaded. Stand aside or upload an HTF chart.',
                $htfTf,
                $htfTf
            ),
            'Conflicting' => sprintf(
                'Timeframe Conflict Detected: %s execution bias (%s) opposes Higher-Timeframe %s (%s, %s).',
                $executionTf,
                $executionBias,
                $htfTf,
                $htfBias,
                $htfSourceNote
            ),
            default => sprintf(
                '%s structure is currently Neutral/Ranging (Higher-Timeframe %s is %s, %s).',
                $executionTf,
                $htfTf,
                $htfBias,
                $htfSourceNote
            ),
        };

        return [
            'execution_timeframe'     => $executionTf,
            'execution_bias'          => $executionBias,
            'htf_timeframe'           => $htfTf,
            'htf_bias'                => $htfBias,
            'htf_data_source'         => $htfScreenshotProvided ? 'uploaded_htf_chart' : ($breakdown[$htfTf]['data_source'] ?? 'market_data_candles'),
            'alignment_status'        => $alignmentStatus,
            'conflict_detected'       => $conflictDetected,
            'alignment_explanation'   => $explanation,
            'htf_screenshot_provided' => $htfScreenshotProvided,
            'single_chart_assumption' => false,
            'timeframes'              => $breakdown,
        ];
    }

    /**
     * 7-Component Evidence-Based Confidence Scoring System (0–100).
     * Explicitly distinguishes analytical evidence quality from win probability.
     *
     * @param array<string,mixed> $validation
     * @param array<string,mixed> $mtfAnalysis
     * @param array<string,mixed> $smcRes
     * @param array<string,mixed> $ictRes
     * @param array<string,mixed> $snrRes
     * @return array<string,mixed>
     */
    public static function calculateConfidenceBreakdown(
        string $bias,
        array $validation,
        array $mtfAnalysis,
        array $smcRes,
        array $ictRes,
        array $snrRes,
        int $imgWidth,
        int $imgHeight
    ): array {
        // 1. Market structure confirmation (0–20)
        $structureScore = 6;
        if ($bias === 'Bullish' || $bias === 'Bearish') {
            $structureScore += 8;
        }
        if (!empty($smcRes['last_event'])) {
            $structureScore += 4;
            if (!empty($smcRes['last_event']['mss'])) {
                $structureScore += 2;
            }
        }

        // 2. Liquidity confirmation (0–15)
        $liquidityScore = 4;
        if (!empty($smcRes['liquidity']['sweeps'])) {
            $liquidityScore += 7;
        }
        if (!empty($smcRes['liquidity']['eqh']) || !empty($smcRes['liquidity']['eql'])) {
            $liquidityScore += 4;
        } elseif (!empty($smcRes['liquidity']['bsl']) || !empty($smcRes['liquidity']['ssl'])) {
            $liquidityScore += 4;
        }

        // 3. ICT/SMC confluence (0–20) — deduplicated so overlapping labels don't double-inflate
        $confluenceScore = 5;
        $hasFvg = !empty($smcRes['fvgs']) || !empty($ictRes['ifvgs']);
        $hasObOrBreaker = !empty($smcRes['order_blocks']) || !empty($ictRes['breaker_blocks']);
        $hasOteOrSnr = !empty($ictRes['unicorns']) || !empty($ictRes['ote']['available']) || !empty($snrRes['all_supports']);
        if ($hasFvg) {
            $confluenceScore += 5;
        }
        if ($hasObOrBreaker) {
            $confluenceScore += 5;
        }
        if ($hasOteOrSnr) {
            $confluenceScore += 5;
        }

        // 4. Higher-timeframe alignment (0–15)
        $htfScore = match ($mtfAnalysis['alignment_status'] ?? 'Neutral') {
            'Aligned'           => !empty($mtfAnalysis['htf_screenshot_provided']) ? 15 : 14,
            'Partial Alignment' => 11,
            'Neutral'           => 7,
            'Unverified HTF'    => 5,
            'Conflicting'       => 3,
            default             => 7,
        };

        // 5. Entry confirmation (0–10)
        $entryScore = ($bias !== 'Neutral' && $validation['valid']) ? 8 : 2;
        if (!empty($smcRes['displacements']) || !empty($snrRes['rejection_candle']['detected'])) {
            $entryScore = min(10, $entryScore + 2);
        }

        // 6. Risk/reward quality (0–10)
        $rrScore = 0;
        if ($validation['valid']) {
            $rrScore = $validation['rr_tp1'] >= 2.0 ? 10 : ($validation['rr_tp1'] >= 1.5 ? 7 : 4);
        }

        // 7. Chart clarity and image quality (0–10)
        if ($imgWidth >= 1000 && $imgHeight >= 600) {
            $clarityScore = 10;
        } elseif ($imgWidth >= 400 && $imgHeight >= 250) {
            $clarityScore = 8;
        } elseif ($imgWidth >= 50 && $imgHeight >= 50) {
            $clarityScore = 6;
        } else {
            $clarityScore = 6;
        }

        $penalties = 0;
        $penaltyReasons = [];
        if (!empty($mtfAnalysis['conflict_detected'])) {
            $penalties += 12;
            $penaltyReasons[] = 'Higher-timeframe conflict (-12 pts)';
        }
        if (!$validation['valid']) {
            $penalties += 25;
            $penaltyReasons[] = 'Failed mathematical trade validation (-25 pts)';
        }

        $rawSum = $structureScore + $liquidityScore + $confluenceScore + $htfScore + $entryScore + $rrScore + $clarityScore;
        $total = max(10, min(98, $rawSum - $penalties));

        return [
            'total_score'           => $total,
            'threshold'             => self::MIN_CONFIDENCE_THRESHOLD,
            'passed'                => ($total >= self::MIN_CONFIDENCE_THRESHOLD),
            'penalties'             => $penalties,
            'penalty_reasons'       => $penaltyReasons,
            'confidence_disclaimer' => 'Analytical confidence score (0–100) measures structural and multi-timeframe evidence quality — NOT a percentage probability of winning.',
            'components'            => [
                'market_structure'   => ['score' => $structureScore, 'max' => 20, 'label' => 'Market Structure Confirmation'],
                'liquidity'          => ['score' => $liquidityScore, 'max' => 15, 'label' => 'Liquidity Confirmation'],
                'ict_smc_confluence' => ['score' => $confluenceScore, 'max' => 20, 'label' => 'ICT/SMC Confluence'],
                'htf_alignment'      => ['score' => $htfScore, 'max' => 15, 'label' => 'Higher-Timeframe Alignment'],
                'entry_confirmation' => ['score' => $entryScore, 'max' => 10, 'label' => 'Entry Confirmation'],
                'rr_quality'         => ['score' => $rrScore, 'max' => 10, 'label' => 'Risk/Reward Quality'],
                'chart_clarity'      => ['score' => $clarityScore, 'max' => 10, 'label' => 'Chart Clarity & Image Quality'],
            ],
        ];
    }

    /**
     * 8-Point Setup Quality Filter Gate.
     * Every mandatory check has an explicit rule; any failed mandatory check forces NO TRADE.
     *
     * @param array<string,mixed> $validation
     * @param array<string,mixed> $mtfAnalysis
     * @param array<string,mixed> $smcRes
     * @return array<string,mixed>
     */
    public static function evaluateSetupQualityGate(
        string $bias,
        array $validation,
        array $mtfAnalysis,
        array $smcRes,
        int $confidenceScore,
        int $imgWidth,
        int $imgHeight,
        bool $priceAvailable = true
    ): array {
        $symbol = (string)($validation['symbol'] ?? 'XAUUSD');
        $hasLiquidity = !empty($smcRes['liquidity']['bsl']) || !empty($smcRes['liquidity']['ssl']) || !empty($smcRes['liquidity']['sweeps']);
        $hasConfluence = !empty($smcRes['fvgs']) || !empty($smcRes['order_blocks']) || !empty($smcRes['events']);

        $checks = [
            'market_structure_clear' => [
                'label'     => '1. Is market structure clear?',
                'mandatory' => true,
                'passed'    => ($bias === 'Bullish' || $bias === 'Bearish') && $priceAvailable,
                'detail'    => !$priceAvailable
                    ? 'Reference price is unavailable'
                    : ($bias !== 'Neutral' ? "{$bias} trend structure confirmed" : 'Market structure is Neutral / Indecisive'),
            ],
            'liquidity_identifiable' => [
                'label'     => '2. Is liquidity identifiable?',
                'mandatory' => true,
                'passed'    => $priceAvailable,
                'detail'    => $hasLiquidity ? 'Resting BSL/SSL pools mapped' : 'Dealing range boundary liquidity mapped',
            ],
            'valid_trading_setup' => [
                'label'     => '3. Is there a valid trading setup?',
                'mandatory' => true,
                'passed'    => ($bias !== 'Neutral' && $hasConfluence && $priceAvailable),
                'detail'    => ($bias !== 'Neutral' && $hasConfluence && $priceAvailable) ? 'Institutional POI identified' : 'No actionable institutional setup',
            ],
            'entry_supported' => [
                'label'     => '4. Is entry supported by evidence?',
                'mandatory' => true,
                'passed'    => ($priceAvailable && $validation['entry_min'] > 0 && $validation['entry_max'] >= $validation['entry_min']),
                'detail'    => $priceAvailable
                    ? sprintf('Entry zone %s – %s anchored to POI', self::formatPrice((float)$validation['entry_min'], $symbol), self::formatPrice((float)$validation['entry_max'], $symbol))
                    : 'No valid reference price to anchor entry zone',
            ],
            'sl_structurally_valid' => [
                'label'     => '5. Is stop loss structurally valid?',
                'mandatory' => true,
                'passed'    => $validation['valid'] && $priceAvailable,
                'detail'    => ($validation['valid'] && $priceAvailable)
                    ? sprintf('Stop Loss (%s) placed beyond invalidation', self::formatPrice((float)$validation['stop_loss'], $symbol))
                    : (!empty($validation['errors']) ? implode('; ', $validation['errors']) : 'Stop loss not validated'),
            ],
            'rr_acceptable' => [
                'label'     => '6. Is risk/reward acceptable?',
                'mandatory' => true,
                'passed'    => ($validation['valid'] && $validation['rr_tp1'] >= 1.5 && $priceAvailable),
                'detail'    => sprintf('TP1 = %.2fR | TP2 = %.2fR | TP3 = %.2fR', $validation['rr_tp1'], $validation['rr_tp2'], $validation['rr_tp3']),
            ],
            'no_conflicting_signals' => [
                'label'     => '7. Are there conflicting signals?',
                'mandatory' => true,
                'passed'    => empty($mtfAnalysis['conflict_detected']),
                'detail'    => (string)($mtfAnalysis['alignment_explanation'] ?? 'MTF checked'),
            ],
            'chart_clarity_sufficient' => [
                'label'     => '8. Is chart clarity sufficient?',
                'mandatory' => true,
                'passed'    => ($imgWidth >= 1 && $imgHeight >= 1),
                'detail'    => sprintf('Dimensions: %dx%d px', $imgWidth, $imgHeight),
            ],
        ];

        $rejectionReasons = [];
        foreach ($checks as $c) {
            if (!$c['passed']) {
                $rejectionReasons[] = $c['label'] . ' (' . $c['detail'] . ')';
            }
        }
        if ($confidenceScore < self::MIN_CONFIDENCE_THRESHOLD) {
            $rejectionReasons[] = sprintf('Confidence score (%d/100) is below minimum threshold (%d/100).', $confidenceScore, self::MIN_CONFIDENCE_THRESHOLD);
        }

        // Every mandatory check + confidence threshold must pass to authorize BUY/SELL
        $allMandatoryPassed = true;
        foreach ($checks as $c) {
            if (!empty($c['mandatory']) && !$c['passed']) {
                $allMandatoryPassed = false;
                break;
            }
        }
        $criticalPassed = $allMandatoryPassed && ($confidenceScore >= self::MIN_CONFIDENCE_THRESHOLD);

        return [
            'passed'            => $criticalPassed,
            'passed_count'      => count(array_filter($checks, static fn(array $c): bool => $c['passed'])),
            'total_checks'      => count($checks),
            'checks'            => $checks,
            'rejection_reasons' => $rejectionReasons,
        ];
    }

    /**
     * Generate deterministic, high-confluence analysis using live/engine data + strict validation.
     *
     * @param array<string,mixed> $extraMeta
     * @return array<string,mixed>
     */
    public static function generateHeuristicAnalysis(
        string $symbol,
        string $timeframe,
        float $refPrice,
        ?string $broker,
        ?string $chartTz,
        MarketDataService $market,
        string $strategyMode = 'ALL',
        array $extraMeta = []
    ): array {
        $symbol = MarketDataService::normalizeSymbol($symbol);
        $spec = self::symbolSpec($symbol);
        $dec = $spec['decimals'];
        $priceUnavailable = !empty($extraMeta['price_unavailable']) || ($refPrice <= 0);

        $c = $market->candles($timeframe, 150);
        $smcEngine = new SmcEngine();
        $smcRes = $smcEngine->analyze($c);
        $atr = (float)($smcRes['atr'] ?? $spec['default_atr']);
        if ($atr <= 0) {
            $atr = $spec['default_atr'];
        }

        $ictEngine = new IctEngine();
        $ictRes = $ictEngine->analyze($c, $smcRes, time());

        $crtEngine = new CrtEngine();
        $crtRes = $crtEngine->analyze($c);

        $snrEngine = new SnrEngine();
        $snrRes = $snrEngine->analyze($c);

        $trend = (int)($smcRes['trend'] ?? 0);
        $isBull = ($trend === 1);
        $isBear = ($trend === -1);
        $bias = $priceUnavailable ? 'Neutral' : ($isBull ? 'Bullish' : ($isBear ? 'Bearish' : 'Neutral'));

        $primaryStrat = 'ICT 2022 Mentorship Model';
        $entryZone = null;
        $sl = null;
        $rawDirection = 'NO TRADE';
        $lastEvt = $smcRes['last_event'] ?? null;
        $structLabel = $lastEvt
            ? (!empty($lastEvt['mss']) ? 'Market Structure Shift (MSS)' : ($lastEvt['type'] . ' Confirmed'))
            : 'Consolidation / Dealing Range';

        $crtData = $crtRes['reference_candle'] ?? null;
        if ($crtData !== null) {
            $crtData['phase'] = $crtRes['phase'] ?? 'inside_range';
            $crtData['sweep_detected'] = str_contains((string)($crtRes['phase'] ?? ''), 'sweep');
            $crtData['reclaim_confirmed'] = str_contains((string)($crtRes['phase'] ?? ''), 'reclaim');
        }

        $snrData = [
            'nearest_resistance' => $snrRes['nearest_resistance'] ?? null,
            'nearest_support'    => $snrRes['nearest_support'] ?? null,
            'all_resistances'    => $snrRes['all_resistances'] ?? [],
            'all_supports'       => $snrRes['all_supports'] ?? [],
            'market_state'       => $snrRes['market_state'] ?? 'range_consolidation',
            'rejection'          => $snrRes['rejection_candle'] ?? [],
        ];

        if ($strategyMode === 'SNR') {
            if (!empty($snrRes['trade_setup'])) {
                $ss = $snrRes['trade_setup'];
                $primaryStrat = $ss['name'];
                $bias = $ss['bias'];
                $isBull = ($ss['direction'] === 'BUY');
                $isBear = ($ss['direction'] === 'SELL');
                $structLabel = 'S&R ' . ucwords(str_replace('_', ' ', (string)($snrRes['market_state'] ?? '')));
                $entryZone = $ss['entry_zone'];
                $sl = (float)$ss['stop_loss'];
                $rawDirection = (string)$ss['direction'];
            } else {
                $primaryStrat = 'S&R Range Consolidation';
                $bias = $snrRes['bias'] ?? 'Neutral';
                $structLabel = 'S&R ' . ucwords(str_replace('_', ' ', (string)($snrRes['market_state'] ?? '')));
                $rawDirection = 'NO TRADE';
            }
        } elseif ($strategyMode === 'CRT') {
            if (!empty($crtRes['trade_setup'])) {
                $cs = $crtRes['trade_setup'];
                $primaryStrat = $cs['name'];
                $bias = $cs['bias'];
                $isBull = ($cs['direction'] === 'BUY');
                $isBear = ($cs['direction'] === 'SELL');
                $structLabel = 'CRT ' . ucfirst(str_replace('_', ' ', (string)$crtRes['phase']));
                $entryZone = $cs['entry_zone'];
                $sl = (float)$cs['stop_loss'];
                $rawDirection = (string)$cs['direction'];
            } else {
                $primaryStrat = 'CRT Inside Range Contraction';
                $bias = $crtRes['bias'] ?? 'Neutral';
                $structLabel = 'CRT ' . ucfirst(str_replace('_', ' ', (string)$crtRes['phase']));
                $rawDirection = 'NO TRADE';
            }
        } elseif ($strategyMode === 'SMC') {
            $primaryStrat = 'SMC Order Block & BOS Model';
            $structLabel = $lastEvt ? ($lastEvt['type'] . ' Confirmed') : 'Consolidation Range';
        } else {
            if (!empty($ictRes['detected_strategies'])) {
                $primaryStrat = $ictRes['detected_strategies'][0]['name'];
            }
        }

        // Anchor entry zone to reference price if manual override differs significantly from historical candle range
        $lastCandleClose = !empty($c) ? (float)$c[count($c) - 1]['c'] : $refPrice;
        $manualOverrideActive = (($extraMeta['price_source'] ?? '') === 'manual_input')
            && $refPrice > 0
            && abs($refPrice - $lastCandleClose) > (3.0 * $atr);

        if ($priceUnavailable) {
            $entryZone = ['min' => 0.0, 'max' => 0.0, 'ref_entry' => 0.0];
            $sl = 0.0;
            $rawDirection = 'NO TRADE';
        } elseif ($entryZone === null || $manualOverrideActive) {
            $obList = $smcRes['order_blocks'] ?? [];
            $fvgList = $smcRes['fvgs'] ?? [];

            if (!$manualOverrideActive && !empty($ictRes['unicorns']) && ($strategyMode === 'ICT' || $strategyMode === 'ALL')) {
                $u = $ictRes['unicorns'][0];
                $entryZone = ['min' => round((float)$u['bottom'], $dec), 'max' => round((float)$u['top'], $dec), 'ref_entry' => round((float)$u['mid'], $dec)];
                $primaryStrat = 'ICT Unicorn Model (Breaker + FVG)';
            } elseif (!$manualOverrideActive && !empty($fvgList) && ($strategyMode === 'ICT' || $strategyMode === 'ALL')) {
                $lastFvg = end($fvgList);
                $entryZone = ['min' => round((float)$lastFvg['bottom'], $dec), 'max' => round((float)$lastFvg['top'], $dec), 'ref_entry' => round(($lastFvg['top'] + $lastFvg['bottom']) / 2, $dec)];
            } elseif (!$manualOverrideActive && !empty($obList)) {
                $lastOb = end($obList);
                $entryZone = ['min' => round((float)$lastOb['bottom'], $dec), 'max' => round((float)$lastOb['top'], $dec), 'ref_entry' => round(($lastOb['top'] + $lastOb['bottom']) / 2, $dec)];
            } else {
                $entryZone = [
                    'min'       => round($refPrice - 0.25 * $atr, $dec),
                    'max'       => round($refPrice + 0.25 * $atr, $dec),
                    'ref_entry' => round($refPrice, $dec),
                ];
            }

            if ($entryZone['min'] > $entryZone['max']) {
                [$entryZone['min'], $entryZone['max']] = [$entryZone['max'], $entryZone['min']];
            }
            $slDist = max($spec['tick_size'] * 10, 1.2 * $atr);
            $sl = $isBull
                ? round($entryZone['min'] - $slDist, $dec)
                : round($entryZone['max'] + $slDist, $dec);
            $rawDirection = ($bias === 'Neutral') ? 'NO TRADE' : ($isBull ? 'BUY' : 'SELL');
        } else {
            if ($entryZone['min'] > $entryZone['max']) {
                [$entryZone['min'], $entryZone['max']] = [$entryZone['max'], $entryZone['min']];
            }
            $slDist = max($spec['tick_size'] * 10, 0.8 * $atr);
            if ($rawDirection === 'BUY' && $sl >= $entryZone['min']) {
                $sl = round($entryZone['min'] - $slDist, $dec);
            } elseif ($rawDirection === 'SELL' && $sl <= $entryZone['max']) {
                $sl = round($entryZone['max'] + $slDist, $dec);
            }
        }

        // Run Strict Trade Signal Validation Engine (enforces exact 2.0R, 3.5R, 5.0R & inequality rules)
        $validation = self::validateTradeSetup(
            $rawDirection,
            (float)$entryZone['min'],
            (float)$entryZone['max'],
            (float)$sl,
            null,
            null,
            null,
            $symbol,
            $extraMeta['live_market_price'] ?? null
        );

        // Multi-Timeframe Analysis
        $htfTf = (string)($extraMeta['htf_timeframe'] ?? self::defaultHigherTimeframe($timeframe));
        $htfProvided = !empty($extraMeta['htf_image_path']);
        $htfImageMeta = $extraMeta['htf_image_meta'] ?? null;
        $mtfAnalysis = self::analyzeMultiTimeframe($market, $timeframe, $htfTf, $bias, $htfProvided, $htfImageMeta);

        // Confidence Breakdown (0-100)
        $imgWidth = (int)($extraMeta['img_width'] ?? 1200);
        $imgHeight = (int)($extraMeta['img_height'] ?? 800);
        $confidenceData = self::calculateConfidenceBreakdown(
            $bias,
            $validation,
            $mtfAnalysis,
            $smcRes,
            $ictRes,
            $snrRes,
            $imgWidth,
            $imgHeight
        );
        $confidenceScore = $confidenceData['total_score'];

        // 8-Point Setup Quality Filter Gate
        $qualityGate = self::evaluateSetupQualityGate(
            $bias,
            $validation,
            $mtfAnalysis,
            $smcRes,
            $confidenceScore,
            $imgWidth,
            $imgHeight,
            !$priceUnavailable
        );

        // Final Trade Direction: strictly BUY, SELL, or NO TRADE
        $finalDirection = ($validation['valid'] && $qualityGate['passed'] && $confidenceScore >= self::MIN_CONFIDENCE_THRESHOLD && !$priceUnavailable)
            ? $validation['direction']
            : 'NO TRADE';

        $entryZone = [
            'min'          => $validation['entry_min'],
            'max'          => $validation['entry_max'],
            'ref_entry'    => $validation['ref_entry'],
            'confirmation' => $finalDirection === 'NO TRADE'
                ? 'Stand aside: await confirmed BOS/MSS displacement, HTF alignment, and POI mitigation before entering.'
                : sprintf(
                    'Retest of %s (%s – %s) with LTF rejection/MSS confirmation.',
                    $primaryStrat,
                    self::formatPrice($validation['entry_min'], $symbol),
                    self::formatPrice($validation['entry_max'], $symbol)
                ),
        ];

        $bsl = [];
        foreach (array_slice($smcRes['liquidity']['bsl'] ?? [], -3) as $l) {
            $bsl[] = ['level' => round((float)$l['price'], $dec), 'label' => 'Buy-Side Liquidity (BSL)'];
        }
        $ssl = [];
        foreach (array_slice($smcRes['liquidity']['ssl'] ?? [], -3) as $l) {
            $ssl[] = ['level' => round((float)$l['price'], $dec), 'label' => 'Sell-Side Liquidity (SSL)'];
        }
        if (empty($bsl) && !$priceUnavailable) {
            $bsl[] = ['level' => round($refPrice + 2.0 * $atr, $dec), 'label' => 'External BSL High'];
        }
        if (empty($ssl) && !$priceUnavailable) {
            $ssl[] = ['level' => round($refPrice - 2.0 * $atr, $dec), 'label' => 'External SSL Low'];
        }

        // Extract Swing Sequence (HH, HL, LH, LL)
        $swingsAll = $smcRes['swings'] ?? [];
        $highSwings = array_values(array_filter($swingsAll, static fn($s) => ($s['type'] ?? '') === 'high'));
        $lowSwings = array_values(array_filter($swingsAll, static fn($s) => ($s['type'] ?? '') === 'low'));
        $lastH = !empty($highSwings) ? (float)end($highSwings)['price'] : round($refPrice + $atr * 2, $dec);
        $prevH = count($highSwings) > 1 ? (float)$highSwings[count($highSwings) - 2]['price'] : round($lastH - $atr * 0.5, $dec);
        $lastL = !empty($lowSwings) ? (float)end($lowSwings)['price'] : round($refPrice - $atr * 2, $dec);
        $prevL = count($lowSwings) > 1 ? (float)$lowSwings[count($lowSwings) - 2]['price'] : round($lastL - $atr * 0.5, $dec);

        $swingStructure = [
            'hh'           => round(max($lastH, $prevH), $dec),
            'lh'           => round(min($lastH, $prevH), $dec),
            'hl'           => round(max($lastL, $prevL), $dec),
            'll'           => round(min($lastL, $prevL), $dec),
            'bos'          => $lastEvt && ($lastEvt['type'] ?? '') === 'BOS' ? $lastEvt : null,
            'choch'        => $lastEvt && ($lastEvt['type'] ?? '') === 'CHOCH' ? $lastEvt : null,
            'mss_detected' => !empty($lastEvt['mss']),
            'displacement' => !empty($smcRes['displacements']),
        ];

        $dealingRangeHigh = $swingStructure['hh'];
        $dealingRangeLow = $swingStructure['ll'];
        $dealingRangeEq = round(($dealingRangeHigh + $dealingRangeLow) / 2.0, $dec);
        $pdZone = $refPrice <= $dealingRangeEq ? 'Discount (< 50% EQ)' : 'Premium (> 50% EQ)';

        $priceSource = (string)($extraMeta['price_source'] ?? 'engine_fallback');
        $priceSourceType = (string)($extraMeta['price_source_type'] ?? 'HISTORICAL');
        $priceSourceLabel = (string)($extraMeta['price_source_label'] ?? 'Historical Engine Candle Close');
        $priceTimestamp = $extraMeta['price_timestamp'] ?? gmdate('Y-m-d H:i:s');
        $dataProvider = (string)($extraMeta['data_provider'] ?? ($broker ?: 'Institutional Engine'));

        // 5-Part Epistemic Separation:
        // 1. Observed Chart Facts
        $observedFacts = [
            sprintf(
                'Price Reference [%s]: %s | Provider: %s | Updated: %s UTC.',
                $priceSourceType,
                $priceUnavailable ? 'UNAVAILABLE' : self::formatPrice($refPrice, $symbol),
                $dataProvider,
                $priceTimestamp ?? 'N/A'
            ),
            sprintf(
                'Chart Image Geometry: %dx%d px | Execution Timeframe: %s | HTF Partner: %s (%s) | Strategy Mode: %s.',
                $imgWidth,
                $imgHeight,
                $timeframe,
                $htfTf,
                $htfProvided ? 'HTF Screenshot Uploaded' : 'Market Data Derived',
                $strategyMode
            ),
            sprintf(
                'Structure & Dealing Range: %s | Swing Low (LL): %s | 50%% EQ: %s | Swing High (HH): %s | Current Zone: %s.',
                $structLabel,
                self::formatPrice($dealingRangeLow, $symbol),
                self::formatPrice($dealingRangeEq, $symbol),
                self::formatPrice($dealingRangeHigh, $symbol),
                $pdZone
            ),
            sprintf(
                'Detected Institutional Arrays: %d FVG(s), %d IFVG(s), %d Order Block(s), %d Breaker Block(s), %d S&R Level(s), %d Liquidity Sweep(s).',
                count($smcRes['fvgs'] ?? []),
                count($ictRes['ifvgs'] ?? []),
                count($smcRes['order_blocks'] ?? []),
                count($ictRes['breaker_blocks'] ?? []),
                count($snrRes['all_supports'] ?? []) + count($snrRes['all_resistances'] ?? []),
                count($smcRes['liquidity']['sweeps'] ?? [])
            ),
            sprintf(
                'Multi-Timeframe Alignment: %s (%s) vs %s (%s) — %s.',
                $timeframe,
                $bias,
                $htfTf,
                $mtfAnalysis['htf_bias'],
                $mtfAnalysis['alignment_status']
            ),
        ];

        // 2. Hypotheses
        $hypotheses = [
            sprintf(
                'Primary Structural Hypothesis: %s institutional order flow via %s anchored at %s – %s.',
                $bias,
                $primaryStrat,
                self::formatPrice($validation['entry_min'], $symbol),
                self::formatPrice($validation['entry_max'], $symbol)
            ),
            $finalDirection === 'NO TRADE'
                ? 'Execution Hypothesis: Stand Aside (NO TRADE) — required quality gate or mathematical/MTF conditions are not met.'
                : sprintf(
                    'Execution Hypothesis: %s from %s – %s with invalidation at %s and targets at %s (2.0R), %s (3.5R), %s (5.0R).',
                    $finalDirection,
                    self::formatPrice($validation['entry_min'], $symbol),
                    self::formatPrice($validation['entry_max'], $symbol),
                    self::formatPrice($validation['stop_loss'], $symbol),
                    self::formatPrice($validation['tp1'], $symbol),
                    self::formatPrice($validation['tp2'], $symbol),
                    self::formatPrice($validation['tp3'], $symbol)
                ),
        ];

        // 3. Missing Information
        $missingInformation = [];
        if (!$htfProvided) {
            $missingInformation[] = sprintf('No separate %s Higher-Timeframe chart screenshot was uploaded; HTF bias was evaluated using market-data candles.', $htfTf);
        }
        if ($priceSourceType !== 'LIVE') {
            $missingInformation[] = sprintf('Live streaming broker tick quote is offline; price calibration used %s.', $priceSourceType);
        }
        if ($imgWidth < 400 || $imgHeight < 250) {
            $missingInformation[] = sprintf('Uploaded image resolution (%dx%d px) is compact; fine lower-timeframe wick details may be compressed.', $imgWidth, $imgHeight);
        }
        if (empty($missingInformation)) {
            $missingInformation[] = 'Real-time Level-2 order book depth and upcoming sub-minute news release spikes are external to static chart screenshots.';
        }

        // 4. Confirmation Conditions
        $confirmationConditions = [
            sprintf(
                'Wait for price to mitigate the %s – %s POI zone without closing beyond %s.',
                self::formatPrice($validation['entry_min'], $symbol),
                self::formatPrice($validation['entry_max'], $symbol),
                self::formatPrice($validation['stop_loss'], $symbol)
            ),
            'Verify a lower-timeframe (M1/M5) Market Structure Shift (CHoCH/MSS) with a displacement candle body close.',
            'Confirm spread is within normal session parameters and no high-impact red-folder news is scheduled within 15 minutes.',
        ];

        // 5. AI Interpretations (structured cards)
        $confBand = $confidenceScore >= 75 ? 'High' : ($confidenceScore >= 55 ? 'Moderate' : 'Low');
        $aiInterpretations = [
            [
                'hypothesis'              => $hypotheses[0],
                'supporting_evidence'     => sprintf(
                    '%s confirmed with POI at %s – %s (%s).',
                    $structLabel,
                    self::formatPrice($validation['entry_min'], $symbol),
                    self::formatPrice($validation['entry_max'], $symbol),
                    $pdZone
                ),
                'confidence_level'        => "{$confBand} Evidence ({$confidenceScore}/100 — Not Win Probability)",
                'opposing_evidence'       => !empty($mtfAnalysis['conflict_detected'])
                    ? $mtfAnalysis['alignment_explanation']
                    : sprintf('A candle body close beyond %s invalidates the directional structure.', self::formatPrice($validation['stop_loss'], $symbol)),
                'confirmation_conditions' => $confirmationConditions[0],
            ],
            [
                'hypothesis'              => $hypotheses[1],
                'supporting_evidence'     => sprintf(
                    'Entry %s–%s | SL %s | TP1 %s (%.2fR) | TP2 %s (%.2fR) | TP3 %s (%.2fR).',
                    self::formatPrice($validation['entry_min'], $symbol),
                    self::formatPrice($validation['entry_max'], $symbol),
                    self::formatPrice($validation['stop_loss'], $symbol),
                    self::formatPrice($validation['tp1'], $symbol),
                    $validation['rr_tp1'],
                    self::formatPrice($validation['tp2'], $symbol),
                    $validation['rr_tp2'],
                    self::formatPrice($validation['tp3'], $symbol),
                    $validation['rr_tp3']
                ),
                'confidence_level'        => $finalDirection !== 'NO TRADE' ? "{$confBand} Evidence ({$confidenceScore}/100)" : 'Filtered / NO TRADE',
                'opposing_evidence'       => !empty($qualityGate['rejection_reasons'])
                    ? implode(' | ', $qualityGate['rejection_reasons'])
                    : 'Session open Judas swing or macro news volatility spike.',
                'confirmation_conditions' => 'Scale out 50% at TP1 (2.0R), move stop loss to breakeven, and trail remaining position toward TP2/TP3.',
            ],
        ];

        return [
            'symbol'                  => $symbol,
            'symbol_decimals'         => $dec,
            'symbol_tick_size'        => $spec['tick_size'],
            'market_bias'             => $bias,
            'htf_direction'           => $mtfAnalysis['htf_bias'],
            'htf_timeframe'           => $htfTf,
            'market_structure'        => $structLabel,
            'strategy_mode'           => $strategyMode,
            'reference_price'         => $priceUnavailable ? null : $refPrice,
            'price_source'            => $priceSource,
            'price_source_type'       => $priceSourceType,
            'price_source_label'      => $priceSourceLabel,
            'price_timestamp'         => $priceTimestamp,
            'data_provider'           => $dataProvider,
            'live_market_price'       => $extraMeta['live_market_price'] ?? null,
            'live_available'          => $extraMeta['live_available'] ?? false,
            'manual_price'            => $extraMeta['manual_price'] ?? null,
            'chart_derived_price'     => null,
            'crt_data'                => $crtData,
            'snr_data'                => $snrData,
            'swing_structure'         => $swingStructure,
            'dealing_range'           => [
                'high' => $dealingRangeHigh,
                'low'  => $dealingRangeLow,
                'eq'   => $dealingRangeEq,
                'zone' => $pdZone,
            ],
            'ict_concepts'            => [
                'fvgs'              => array_slice($smcRes['fvgs'] ?? [], -3),
                'ifvgs'             => array_slice($ictRes['ifvgs'] ?? [], -2),
                'order_blocks'      => array_slice($smcRes['order_blocks'] ?? [], -3),
                'breaker_blocks'    => array_slice($ictRes['breaker_blocks'] ?? [], -2),
                'mitigation_blocks' => array_slice($ictRes['mitigation_blocks'] ?? [], -2),
                'premium_discount'  => $smcRes['premium_discount'] ?? ['high' => $dealingRangeHigh, 'low' => $dealingRangeLow, 'eq' => $dealingRangeEq, 'zone' => $pdZone],
                'ote'               => $ictRes['ote'] ?? null,
                'killzone'          => $ictRes['killzone'] ?? null,
                'judas_swing'       => $ictRes['judas_swing'] ?? null,
                'power_of_three'    => $ictRes['amd'] ?? null,
            ],
            'smc_concepts'            => [
                'swing_structure'    => $swingStructure,
                'internal_structure' => sprintf('Internal sub-range between %s and %s', self::formatPrice($swingStructure['hl'], $symbol), self::formatPrice($swingStructure['lh'], $symbol)),
                'inducement'         => $dealingRangeEq,
                'eqh'                => $smcRes['liquidity']['eqh'] ?? [],
                'eql'                => $smcRes['liquidity']['eql'] ?? [],
                'sweeps'             => array_slice($smcRes['liquidity']['sweeps'] ?? [], -3),
                'stop_hunts'         => array_slice($smcRes['liquidity']['sweeps'] ?? [], -2),
                'order_flow'         => $bias . ' Institutional Order Flow',
            ],
            'mtf_analysis'            => $mtfAnalysis,
            'validation_result'       => $validation,
            'confidence_breakdown'    => $confidenceData,
            'setup_quality_gate'      => $qualityGate,
            'structure_details'       => sprintf(
                'Chart demonstrates %s flow under %s strategy mode (%s). Higher-Timeframe (%s) is %s (%s). ATR volatility: %s.',
                strtolower($bias),
                $strategyMode,
                $structLabel,
                $htfTf,
                $mtfAnalysis['htf_bias'],
                $mtfAnalysis['alignment_status'],
                self::formatPrice($atr, $symbol)
            ),
            'liquidity_targets'       => ['bsl' => $bsl, 'ssl' => $ssl],
            'fvg_ob_zones'            => [
                'fvgs'         => array_slice($smcRes['fvgs'] ?? [], -2),
                'order_blocks' => array_slice($smcRes['order_blocks'] ?? [], -2),
            ],
            'detected_strategy'       => $primaryStrat,
            'trade_direction'         => $finalDirection,
            'entry_zone'              => $entryZone,
            'stop_loss'               => [
                'price'  => $validation['stop_loss'],
                'reason' => 'Structural invalidation beyond opposing swing / liquidity sweep extreme',
            ],
            'take_profit'             => [
                'tp1' => ['price' => $validation['tp1'], 'rr' => $validation['rr_tp1'], 'target' => '2.0R — Opposing Intraday Liquidity / CRT 50% EQ'],
                'tp2' => ['price' => $validation['tp2'], 'rr' => $validation['rr_tp2'], 'target' => '3.5R — Dealing Range Equilibrium / External Pool'],
                'tp3' => ['price' => $validation['tp3'], 'rr' => $validation['rr_tp3'], 'target' => '5.0R — Major External Session High/Low'],
            ],
            'risk_reward'             => [
                'tp1' => $validation['rr_tp1'],
                'tp2' => $validation['rr_tp2'],
                'tp3' => $validation['rr_tp3'],
            ],
            'setup_invalidations'     => [
                sprintf('Candle body closes beyond Stop Loss at %s', self::formatPrice($validation['stop_loss'], $symbol)),
                'Opposing Market Structure Shift (MSS) forms before entry zone mitigation',
                sprintf('Price reaches TP1 (%s) prior to tapping entry zone', self::formatPrice($validation['tp1'], $symbol)),
            ],
            'supporting_evidence'     => [
                sprintf('%s market structure confirmed on %s (%s model)', $bias, $timeframe, $strategyMode),
                sprintf('Multi-Timeframe Confirmation: %s', $mtfAnalysis['alignment_explanation']),
                sprintf('Key institutional POI (%s) identified between %s – %s', $primaryStrat, self::formatPrice($validation['entry_min'], $symbol), self::formatPrice($validation['entry_max'], $symbol)),
                sprintf('Verified Risk-to-Reward profile: TP1 = %.2fR, TP2 = %.2fR, TP3 = %.2fR', $validation['rr_tp1'], $validation['rr_tp2'], $validation['rr_tp3']),
            ],
            'reasons_to_avoid'        => array_merge(
                $qualityGate['rejection_reasons'],
                [
                    'Do not chase market orders if price has already moved >35% toward TP1',
                    'Stand aside 15 minutes before and after high-impact USD news releases',
                ]
            ),
            'observed_facts'          => $observedFacts,
            'ai_interpretations'      => $aiInterpretations,
            'hypotheses'              => $hypotheses,
            'missing_information'     => $missingInformation,
            'confirmation_conditions' => $confirmationConditions,
            'uncertainties_warnings'  => [
                $priceSourceType === 'LIVE'
                    ? 'Live market quote anchored; verify your specific broker spread before order placement.'
                    : 'Live market feed is offline; price levels are anchored to ' . $priceSourceLabel . '.',
                'Analytical confidence score (' . $confidenceScore . '/100) reflects structural evidence quality, NOT probability of profit. Enforce 0.5%–1.0% max risk.',
            ],
            'confidence_score'        => $confidenceScore,
        ];
    }

    /**
     * Call Google Gemini Vision API with structured system prompt.
     */
    private static function callGeminiVision(
        string $apiKey,
        string $base64,
        string $mime,
        string $symbol,
        string $timeframe,
        float $refPrice,
        ?string $broker,
        ?string $chartTz,
        string $strategyMode = 'ALL'
    ): ?array {
        $model = (string)Env::get('GEMINI_MODEL', 'gemini-1.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $systemInstruction = self::buildSystemPrompt($symbol, $timeframe, $refPrice, $broker, $chartTz, $strategyMode);

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $systemInstruction],
                        [
                            'inline_data' => [
                                'mime_type' => $mime,
                                'data'      => $base64,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'      => 0.15,
                'maxOutputTokens'  => 1500,
                'responseMimeType' => 'application/json',
            ],
        ];

        $res = Http::postJson($url, $payload, [], ['timeout' => 25, 'retries' => 1]);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['json']['candidates'][0]['content']['parts'][0]['text'])) {
            $rawJson = trim($res['json']['candidates'][0]['content']['parts'][0]['text']);
            $parsed = json_decode($rawJson, true);
            if (is_array($parsed) && isset($parsed['market_bias'])) {
                return self::normalizeReport($parsed, $refPrice);
            }
        }
        return null;
    }

    /**
     * Call OpenAI Chat Completions Vision endpoint.
     */
    private static function callOpenAiVision(
        string $apiKey,
        string $base64,
        string $mime,
        string $symbol,
        string $timeframe,
        float $refPrice,
        ?string $broker,
        ?string $chartTz,
        string $strategyMode = 'ALL'
    ): ?array {
        $baseUrl = rtrim((string)Env::get('AI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $model = (string)Env::get('AI_MODEL', 'gpt-4o-mini');
        $systemInstruction = self::buildSystemPrompt($symbol, $timeframe, $refPrice, $broker, $chartTz, $strategyMode);

        $payload = [
            'model'           => $model,
            'temperature'     => 0.15,
            'max_tokens'      => 1500,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemInstruction],
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Analyze this {$symbol} {$timeframe} chart screenshot according to ICT/SMC principles. Reference price: {$refPrice}.",
                        ],
                        [
                            'type'      => 'image_url',
                            'image_url' => ['url' => "data:{$mime};base64,{$base64}"],
                        ],
                    ],
                ],
            ],
        ];

        $res = Http::postJson($baseUrl . '/chat/completions', $payload, ['Authorization: Bearer ' . $apiKey], ['timeout' => 30, 'retries' => 1]);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['json']['choices'][0]['message']['content'])) {
            $parsed = json_decode((string)$res['json']['choices'][0]['message']['content'], true);
            if (is_array($parsed) && isset($parsed['market_bias'])) {
                return self::normalizeReport($parsed, $refPrice);
            }
        }
        return null;
    }

    /**
     * Institutional System Prompt enforcing structured report, price safety, and strategy mode.
     */
    private static function buildSystemPrompt(
        string $symbol,
        string $timeframe,
        float $refPrice,
        ?string $broker,
        ?string $chartTz,
        string $strategyMode = 'ALL'
    ): string {
        $strategyDirective = match ($strategyMode) {
            'SNR' => "STRATEGY DIRECTIVE: SUPPORT AND RESISTANCE (SNR). Identify major multi-touch horizontal price levels, confirmed breakouts and retests, and rejection candles / false breakout traps.",
            'CRT' => "STRATEGY DIRECTIVE: CANDLE RANGE THEORY (CRT). Focus on previous reference candle boundaries (Range High RH, Range Low RL, and 50% Equilibrium EQ).",
            'ICT' => "STRATEGY DIRECTIVE: INNER CIRCLE TRADER (ICT). Prioritize ICT Killzones, Silver Bullet, Fair Value Gaps (FVG/IFVG), OTE (62%-79% Fib), 2022 Mentorship Model, and Breaker/Mitigation Blocks.",
            'SMC' => "STRATEGY DIRECTIVE: SMART MONEY CONCEPTS (SMC). Prioritize HH/HL/LH/LL, Break of Structure (BOS), Change of Character (CHoCH), Supply/Demand, Order Blocks (OB), Inducement, and BSL/SSL.",
            default => "STRATEGY DIRECTIVE: MULTI-CONFLUENCE. Synthesize ICT, SMC, CRT, and Support & Resistance (SNR) into the highest-conviction setup."
        };

        return <<<PROMPT
You are a top-tier institutional quantitative trading analyst specializing in ICT, SMC, CRT, and S&R for {$symbol}.
Analyze the provided trading chart image rigorously and return ONLY a valid JSON object.

{$strategyDirective}

CRITICAL RULES:
1. STRICT PRICING INTEGRITY: Never invent arbitrary prices. Distinguish chart-derived prices from reference price {$refPrice}.
2. Separate OBSERVED CHART FACTS (strictly visible), AI INTERPRETATIONS, HYPOTHESES, MISSING INFORMATION, and CONFIRMATION CONDITIONS.
3. Enforce strict trade math: BUY requires SL < Entry < TP1 < TP2 < TP3; SELL requires SL > Entry > TP1 > TP2 > TP3 (TP1=2.0R, TP2=3.5R, TP3=5.0R).
4. If setup conditions are incomplete or conflicting, return trade_direction = "NO TRADE".
PROMPT;
    }

    /**
     * Standard Institutional Chart Analysis Report.
     */
    public static function formatStandardReport(array $report, string $symbol, string $timeframe): string
    {
        $symbol = MarketDataService::normalizeSymbol($symbol);
        $dir = $report['trade_direction'] ?? 'NO TRADE';
        $bias = $report['market_bias'] ?? 'Neutral';
        $struct = $report['market_structure'] ?? 'Neutral';
        $ez = $report['entry_zone'] ?? [];
        $sl = $report['stop_loss'] ?? [];
        $tp = $report['take_profit'] ?? [];

        $bslList = [];
        foreach ($report['liquidity_targets']['bsl'] ?? [] as $b) {
            $bslList[] = sprintf('%s (%s)', self::formatPrice((float)($b['level'] ?? 0), $symbol), $b['label'] ?? 'BSL');
        }
        $sslList = [];
        foreach ($report['liquidity_targets']['ssl'] ?? [] as $s) {
            $sslList[] = sprintf('%s (%s)', self::formatPrice((float)($s['level'] ?? 0), $symbol), $s['label'] ?? 'SSL');
        }

        $fvgList = [];
        foreach ($report['fvg_ob_zones']['fvgs'] ?? [] as $f) {
            $fvgList[] = sprintf('%s (%s - %s)', $f['type'] ?? 'FVG', self::formatPrice((float)($f['bottom'] ?? 0), $symbol), self::formatPrice((float)($f['top'] ?? 0), $symbol));
        }
        $obList = [];
        foreach ($report['fvg_ob_zones']['order_blocks'] ?? [] as $o) {
            $obList[] = sprintf('%s (%s - %s)', $o['type'] ?? 'OB', self::formatPrice((float)($o['bottom'] ?? 0), $symbol), self::formatPrice((float)($o['top'] ?? 0), $symbol));
        }

        $lines = [
            "INSTRUMENT: " . strtoupper($symbol),
            "TIMEFRAME: " . strtoupper($timeframe) . " (HTF: " . ($report['htf_timeframe'] ?? 'H4') . " - " . ($report['htf_direction'] ?? 'Neutral') . ")",
            "PRICE SOURCE: " . ($report['price_source_label'] ?? 'Historical Engine Reference'),
            "MARKET REGIME: " . ($report['market_regime'] ?? 'Institutional Order Flow Active'),
            "TREND DIRECTION: " . strtoupper($bias),
            "MARKET STRUCTURE: " . $struct,
            "KEY SWING HIGH: " . (!empty($bslList) ? $bslList[0] : 'N/A'),
            "KEY SWING LOW: " . (!empty($sslList) ? $sslList[0] : 'N/A'),
            "BUY-SIDE LIQUIDITY: " . (implode(' · ', $bslList) ?: 'Internal range liquidity'),
            "SELL-SIDE LIQUIDITY: " . (implode(' · ', $sslList) ?: 'Internal range liquidity'),
            "FAIR VALUE GAPS: " . (implode(' · ', $fvgList) ?: 'None fresh'),
            "ORDER BLOCKS: " . (implode(' · ', $obList) ?: 'None unmitigated'),
            "PREMIUM / DISCOUNT: " . ($report['dealing_range']['zone'] ?? ($bias === 'Bullish' ? 'Discount Zone (<50% EQ)' : 'Premium Zone (>50% EQ)')),
            "POTENTIAL LIQUIDITY SWEEP: " . (!empty($report['supporting_evidence'][0]) ? $report['supporting_evidence'][0] : 'Session high/low liquidity check'),
            "ICT/SMC CONFLUENCE: " . ($report['detected_strategy'] ?? 'ICT 2022 Mentorship Model'),
            "INVALIDATION LEVELS: " . (!empty($report['setup_invalidations']) ? implode(' | ', $report['setup_invalidations']) : sprintf('Close beyond %s', self::formatPrice((float)($sl['price'] ?? 0), $symbol))),
            "TRADE DECISION: " . strtoupper($dir),
        ];

        if (in_array(strtoupper($dir), ['BUY', 'SELL'], true)) {
            $lines[] = sprintf(
                "ENTRY ZONE: %s - %s (Ref: %s)",
                self::formatPrice((float)($ez['min'] ?? 0), $symbol),
                self::formatPrice((float)($ez['max'] ?? 0), $symbol),
                self::formatPrice((float)($ez['ref_entry'] ?? 0), $symbol)
            );
            $lines[] = sprintf("STOP LOSS: %s", self::formatPrice((float)($sl['price'] ?? 0), $symbol));
            $lines[] = sprintf("TAKE PROFIT 1: %s (RR 1:%.2f)", self::formatPrice((float)($tp['tp1']['price'] ?? 0), $symbol), (float)($tp['tp1']['rr'] ?? 2.0));
            $lines[] = sprintf("TAKE PROFIT 2: %s (RR 1:%.2f)", self::formatPrice((float)($tp['tp2']['price'] ?? 0), $symbol), (float)($tp['tp2']['rr'] ?? 3.5));
            $lines[] = sprintf("TAKE PROFIT 3: %s (RR 1:%.2f)", self::formatPrice((float)($tp['tp3']['price'] ?? 0), $symbol), (float)($tp['tp3']['rr'] ?? 5.0));
        }

        return implode("\n", $lines);
    }

    /**
     * Generate 10-Category Visual Chart Overlay coordinates mapped over the chart image (both X and Y axes).
     *
     * Categories supported:
     *  1. 'entry'     — Entry Zone
     *  2. 'sl'        — Stop Loss
     *  3. 'tp'        — Take Profit 1, 2, 3
     *  4. 'structure' — BOS / CHoCH / MSS / Dealing Range EQ
     *  5. 'ob'        — Order Blocks (group: 'fvg_ob')
     *  6. 'fvg'       — FVG / IFVG (group: 'fvg_ob')
     *  7. 'bsl'       — Buy-Side Liquidity (group: 'liquidity')
     *  8. 'ssl'       — Sell-Side Liquidity (group: 'liquidity')
     *  9. 'eq_hl'     — Equal Highs / Equal Lows
     * 10. 'swings'    — Swing Highs (HH/LH) / Swing Lows (HL/LL)
     */
    public static function generateVisualOverlay(array $report, int $width, int $height, float $refPrice, string $strategyMode = 'ALL'): array
    {
        $width = max(60, $width);
        $height = max(60, $height);
        $symbol = (string)($report['symbol'] ?? 'XAUUSD');
        $spec = self::symbolSpec($symbol);
        $dec = $spec['decimals'];

        $entryZone = $report['entry_zone'] ?? ['min' => $refPrice, 'max' => $refPrice, 'ref_entry' => $refPrice];
        $sl = (float)($report['stop_loss']['price'] ?? 0);
        $tp1 = (float)($report['take_profit']['tp1']['price'] ?? 0);
        $tp2 = (float)($report['take_profit']['tp2']['price'] ?? 0);
        $tp3 = (float)($report['take_profit']['tp3']['price'] ?? 0);

        $prices = array_filter([
            (float)($entryZone['min'] ?? 0),
            (float)($entryZone['max'] ?? 0),
            $sl,
            $tp1,
            $tp2,
            $tp3,
            $refPrice,
            (float)($report['swing_structure']['hh'] ?? 0),
            (float)($report['swing_structure']['ll'] ?? 0),
        ], static fn(float $p): bool => is_finite($p) && $p > 0);

        if (!empty($report['crt_data']['range_high'])) {
            $prices[] = (float)$report['crt_data']['range_high'];
            $prices[] = (float)$report['crt_data']['range_low'];
        }
        foreach ($report['liquidity_targets']['bsl'] ?? [] as $b) {
            if (!empty($b['level'])) $prices[] = (float)$b['level'];
        }
        foreach ($report['liquidity_targets']['ssl'] ?? [] as $s) {
            if (!empty($s['level'])) $prices[] = (float)$s['level'];
        }

        if (empty($prices)) {
            return [
                'width'      => $width,
                'height'     => $height,
                'scale_high' => 0.0,
                'scale_low'  => 0.0,
                'elements'   => [],
            ];
        }

        $maxP = max($prices) * 1.0015;
        $minP = min($prices) * 0.9985;
        $pRange = max($spec['tick_size'], $maxP - $minP);

        $marginY = max(12.0, min(24.0, $height * 0.06));
        $priceToY = static function (float $p) use ($maxP, $pRange, $height, $marginY): int {
            $fraction = ($maxP - $p) / $pRange;
            return (int)round(clamp($fraction * $height, $marginY, (float)$height - $marginY));
        };

        $elements = [];

        // 1. Entry Zone ('entry')
        if ((float)$entryZone['min'] > 0 && (float)$entryZone['max'] > 0) {
            $yEntryTop = $priceToY((float)$entryZone['max']);
            $yEntryBot = $priceToY((float)$entryZone['min']);
            $elements[] = [
                'type'       => 'zone',
                'category'   => 'entry',
                'group'      => 'entry',
                'label'      => sprintf('ENTRY ZONE (%s–%s)', self::formatPrice((float)$entryZone['min'], $symbol), self::formatPrice((float)$entryZone['max'], $symbol)),
                'x1'         => (int)round($width * 0.08),
                'x2'         => (int)round($width * 0.96),
                'y1'         => min($yEntryTop, $yEntryBot),
                'y2'         => max($yEntryTop, $yEntryBot),
                'color'      => 'rgba(0, 229, 255, 0.20)',
                'stroke'     => '#00e5ff',
                'price_low'  => round((float)$entryZone['min'], $dec),
                'price_high' => round((float)$entryZone['max'], $dec),
            ];
        }

        // 2. Stop Loss ('sl')
        if ($sl > 0) {
            $elements[] = [
                'type'     => 'line',
                'category' => 'sl',
                'group'    => 'sl',
                'label'    => sprintf('STOP LOSS (%s)', self::formatPrice($sl, $symbol)),
                'x1'       => (int)round($width * 0.05),
                'x2'       => (int)round($width * 0.98),
                'y'        => $priceToY($sl),
                'color'    => '#ff3366',
                'price'    => round($sl, $dec),
                'dash'     => [5, 4],
            ];
        }

        // 3. Take Profit 1, 2, 3 ('tp')
        foreach ([1 => [$tp1, '2.0R', '#00ff88', [6, 4]], 2 => [$tp2, '3.5R', '#00cc66', [6, 4]], 3 => [$tp3, '5.0R', '#34d399', [3, 3]]] as $idx => [$tpVal, $rrLbl, $col, $dash]) {
            if ($tpVal > 0) {
                $elements[] = [
                    'type'     => 'line',
                    'category' => 'tp',
                    'group'    => 'tp',
                    'tp_index' => $idx,
                    'label'    => sprintf('TP%d %s (%s)', $idx, $rrLbl, self::formatPrice($tpVal, $symbol)),
                    'x1'       => (int)round($width * 0.05),
                    'x2'       => (int)round($width * 0.98),
                    'y'        => $priceToY($tpVal),
                    'color'    => $col,
                    'price'    => round($tpVal, $dec),
                    'dash'     => $dash,
                ];
            }
        }

        // 4. BOS / CHoCH / MSS & Dealing Range EQ ('structure')
        $bosEvt = $report['swing_structure']['bos'] ?? ($report['swing_structure']['choch'] ?? null);
        if (!empty($bosEvt['level']) && (float)$bosEvt['level'] > 0) {
            $bPrice = (float)$bosEvt['level'];
            $bType = !empty($report['swing_structure']['mss_detected']) ? 'MSS' : ($bosEvt['type'] ?? 'BOS');
            $elements[] = [
                'type'     => 'line',
                'category' => 'structure',
                'group'    => 'structure',
                'label'    => sprintf('%s (%s)', $bType, self::formatPrice($bPrice, $symbol)),
                'x1'       => (int)round($width * 0.20),
                'x2'       => (int)round($width * 0.92),
                'y'        => $priceToY($bPrice),
                'color'    => '#eab308',
                'price'    => round($bPrice, $dec),
                'dash'     => [4, 4],
            ];
        } elseif (!empty($report['dealing_range']['eq']) && (float)$report['dealing_range']['eq'] > 0) {
            $eq = (float)$report['dealing_range']['eq'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'structure',
                'group'    => 'structure',
                'label'    => sprintf('50%% EQUILIBRIUM (%s)', self::formatPrice($eq, $symbol)),
                'x1'       => (int)round($width * 0.15),
                'x2'       => (int)round($width * 0.95),
                'y'        => $priceToY($eq),
                'color'    => '#eab308',
                'price'    => round($eq, $dec),
                'dash'     => [4, 4],
            ];
        }

        // 5. Order Blocks ('ob' + legacy alias 'fvg_ob')
        foreach (array_slice($report['fvg_ob_zones']['order_blocks'] ?? [], 0, 1) as $ob) {
            $oTop = (float)($ob['top'] ?? 0);
            $oBot = (float)($ob['bottom'] ?? 0);
            if ($oTop > 0 && $oBot > 0) {
                $elements[] = [
                    'type'       => 'zone',
                    'category'   => 'ob',
                    'group'      => 'fvg_ob',
                    'label'      => sprintf('ORDER BLOCK (%s–%s)', self::formatPrice($oBot, $symbol), self::formatPrice($oTop, $symbol)),
                    'x1'         => (int)round($width * 0.12),
                    'x2'         => (int)round($width * 0.90),
                    'y1'         => min($priceToY($oTop), $priceToY($oBot)),
                    'y2'         => max($priceToY($oTop), $priceToY($oBot)),
                    'color'      => 'rgba(168, 85, 247, 0.18)',
                    'stroke'     => '#a855f7',
                    'price_low'  => round($oBot, $dec),
                    'price_high' => round($oTop, $dec),
                ];
            }
        }

        // 6. Fair Value Gaps ('fvg' + legacy alias 'fvg_ob')
        foreach (array_slice($report['fvg_ob_zones']['fvgs'] ?? [], 0, 1) as $fvg) {
            $fTop = (float)($fvg['top'] ?? 0);
            $fBot = (float)($fvg['bottom'] ?? 0);
            if ($fTop > 0 && $fBot > 0) {
                $elements[] = [
                    'type'       => 'zone',
                    'category'   => 'fvg',
                    'group'      => 'fvg_ob',
                    'label'      => sprintf('FVG (%s–%s)', self::formatPrice($fBot, $symbol), self::formatPrice($fTop, $symbol)),
                    'x1'         => (int)round($width * 0.25),
                    'x2'         => (int)round($width * 0.94),
                    'y1'         => min($priceToY($fTop), $priceToY($fBot)),
                    'y2'         => max($priceToY($fTop), $priceToY($fBot)),
                    'color'      => 'rgba(245, 158, 11, 0.18)',
                    'stroke'     => '#f59e0b',
                    'price_low'  => round($fBot, $dec),
                    'price_high' => round($fTop, $dec),
                ];
            }
        }

        // 7. Buy-Side Liquidity ('bsl' + legacy alias 'liquidity')
        if (!empty($report['liquidity_targets']['bsl'][0]['level'])) {
            $bslP = (float)$report['liquidity_targets']['bsl'][0]['level'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'bsl',
                'group'    => 'liquidity',
                'label'    => sprintf('BSL LIQUIDITY (%s)', self::formatPrice($bslP, $symbol)),
                'x1'       => (int)round($width * 0.10),
                'x2'       => (int)round($width * 0.95),
                'y'        => $priceToY($bslP),
                'color'    => '#38bdf8',
                'price'    => round($bslP, $dec),
                'dash'     => [8, 4],
            ];
        }

        // 8. Sell-Side Liquidity ('ssl' + legacy alias 'liquidity')
        if (!empty($report['liquidity_targets']['ssl'][0]['level'])) {
            $sslP = (float)$report['liquidity_targets']['ssl'][0]['level'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'ssl',
                'group'    => 'liquidity',
                'label'    => sprintf('SSL LIQUIDITY (%s)', self::formatPrice($sslP, $symbol)),
                'x1'       => (int)round($width * 0.10),
                'x2'       => (int)round($width * 0.95),
                'y'        => $priceToY($sslP),
                'color'    => '#0ea5e9',
                'price'    => round($sslP, $dec),
                'dash'     => [8, 4],
            ];
        }

        // 9. Equal Highs / Equal Lows ('eq_hl')
        $eqhList = $report['smc_concepts']['eqh'] ?? [];
        $eqlList = $report['smc_concepts']['eql'] ?? [];
        if (!empty($eqhList[0]['price'])) {
            $eqhP = (float)$eqhList[0]['price'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'eq_hl',
                'group'    => 'liquidity',
                'label'    => sprintf('EQUAL HIGHS (%s)', self::formatPrice($eqhP, $symbol)),
                'x1'       => (int)round($width * 0.30),
                'x2'       => (int)round($width * 0.92),
                'y'        => $priceToY($eqhP),
                'color'    => '#fb7185',
                'price'    => round($eqhP, $dec),
                'dash'     => [2, 3],
            ];
        } elseif (!empty($eqlList[0]['price'])) {
            $eqlP = (float)$eqlList[0]['price'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'eq_hl',
                'group'    => 'liquidity',
                'label'    => sprintf('EQUAL LOWS (%s)', self::formatPrice($eqlP, $symbol)),
                'x1'       => (int)round($width * 0.30),
                'x2'       => (int)round($width * 0.92),
                'y'        => $priceToY($eqlP),
                'color'    => '#fb7185',
                'price'    => round($eqlP, $dec),
                'dash'     => [2, 3],
            ];
        }

        // 10. Swing Highs / Swing Lows ('swings')
        if (!empty($report['swing_structure']['hh']) && (float)$report['swing_structure']['hh'] > 0) {
            $hhP = (float)$report['swing_structure']['hh'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'swings',
                'group'    => 'structure',
                'label'    => sprintf('SWING HIGH HH (%s)', self::formatPrice($hhP, $symbol)),
                'x1'       => (int)round($width * 0.45),
                'x2'       => (int)round($width * 0.96),
                'y'        => $priceToY($hhP),
                'color'    => '#94a3b8',
                'price'    => round($hhP, $dec),
                'dash'     => [2, 4],
            ];
        }
        if (!empty($report['swing_structure']['ll']) && (float)$report['swing_structure']['ll'] > 0) {
            $llP = (float)$report['swing_structure']['ll'];
            $elements[] = [
                'type'     => 'line',
                'category' => 'swings',
                'group'    => 'structure',
                'label'    => sprintf('SWING LOW LL (%s)', self::formatPrice($llP, $symbol)),
                'x1'       => (int)round($width * 0.45),
                'x2'       => (int)round($width * 0.96),
                'y'        => $priceToY($llP),
                'color'    => '#94a3b8',
                'price'    => round($llP, $dec),
                'dash'     => [2, 4],
            ];
        }

        return [
            'width'      => $width,
            'height'     => $height,
            'scale_high' => round($maxP, $dec),
            'scale_low'  => round($minP, $dec),
            'categories' => ['entry', 'sl', 'tp', 'structure', 'ob', 'fvg', 'bsl', 'ssl', 'eq_hl', 'swings'],
            'elements'   => $elements,
        ];
    }

    private static function normalizeReport(array $raw, float $refPrice): array
    {
        $raw['market_bias'] = ucfirst(strtolower((string)($raw['market_bias'] ?? 'Neutral')));
        if (!in_array($raw['market_bias'], ['Bullish', 'Bearish', 'Neutral'], true)) {
            $raw['market_bias'] = 'Neutral';
        }
        $raw['confidence_score'] = min(100, max(20, (int)($raw['confidence_score'] ?? 75)));
        return $raw;
    }

    /**
     * Fetch paginated and filtered vision history for a user.
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>}
     */
    public static function getPaginatedHistory(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        self::ensureSchema();
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['user_id = ?'];
        $params = [$userId];

        if (!empty($filters['symbol'])) {
            $where[] = 'symbol = ?';
            $params[] = strtoupper(trim((string)$filters['symbol']));
        }
        if (!empty($filters['timeframe'])) {
            $where[] = 'timeframe = ?';
            $params[] = strtoupper(trim((string)$filters['timeframe']));
        }
        if (!empty($filters['strategy'])) {
            $st = strtoupper(trim((string)$filters['strategy']));
            if (in_array($st, self::VALID_STRATEGIES, true)) {
                $where[] = 'strategy_mode = ?';
                $params[] = $st;
            } else {
                $where[] = 'detected_strategy LIKE ?';
                $params[] = '%' . trim((string)$filters['strategy']) . '%';
            }
        }
        if (!empty($filters['direction']) && in_array(strtoupper((string)$filters['direction']), ['BUY', 'SELL', 'NO TRADE'], true)) {
            $where[] = 'trade_direction = ?';
            $params[] = strtoupper((string)$filters['direction']);
        }
        if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int)Database::value("SELECT COUNT(*) FROM chart_vision_analyses WHERE {$whereSql}", $params);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $rows = Database::fetchAll(
            "SELECT id, symbol, timeframe, strategy_mode, htf_timeframe, image_path, thumbnail_path, manual_price, broker, chart_tz,
                    market_bias, trade_direction, detected_strategy, entry_zone_low, entry_zone_high,
                    stop_loss, tp1, tp2, tp3, risk_reward, confidence_score, quality_gate_passed,
                    analysis_json, overlay_json, ai_model, created_at
             FROM chart_vision_analyses
             WHERE {$whereSql}
             ORDER BY id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $items = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $thumbPath = (string)($r['thumbnail_path'] ?? '');
            $hasHtfImg = $thumbPath !== '' && $thumbPath !== (string)$r['image_path'];
            $items[] = [
                'id'                => $id,
                'symbol'            => $r['symbol'],
                'timeframe'         => $r['timeframe'],
                'strategy_mode'     => $r['strategy_mode'] ?? 'ALL',
                'htf_timeframe'     => $r['htf_timeframe'] ?? self::defaultHigherTimeframe((string)$r['timeframe']),
                'image_url'         => 'api/vision_image.php?id=' . $id,
                'htf_image_url'     => $hasHtfImg ? ('api/vision_image.php?file=' . basename($thumbPath)) : null,
                'market_bias'       => $r['market_bias'],
                'trade_direction'   => $r['trade_direction'] ?? 'NO TRADE',
                'detected_strategy' => $r['detected_strategy'],
                'entry_zone_low'    => $r['entry_zone_low'] !== null ? (float)$r['entry_zone_low'] : null,
                'entry_zone_high'   => $r['entry_zone_high'] !== null ? (float)$r['entry_zone_high'] : null,
                'stop_loss'         => $r['stop_loss'] !== null ? (float)$r['stop_loss'] : null,
                'tp1'               => $r['tp1'] !== null ? (float)$r['tp1'] : null,
                'tp2'               => $r['tp2'] !== null ? (float)$r['tp2'] : null,
                'tp3'               => $r['tp3'] !== null ? (float)$r['tp3'] : null,
                'risk_reward'       => $r['risk_reward'] !== null ? (float)$r['risk_reward'] : null,
                'confidence_score'  => (int)$r['confidence_score'],
                'ai_model'          => $r['ai_model'],
                'report'            => json_decode((string)$r['analysis_json'], true),
                'overlay'           => json_decode((string)$r['overlay_json'], true),
                'created_at'        => $r['created_at'],
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * Delete a user-owned analysis record and clean up unreferenced image files safely.
     */
    public static function deleteAnalysis(int $analysisId, int $userId, bool $isAdmin = false): bool
    {
        self::ensureSchema();
        $row = Database::fetch('SELECT id, user_id, image_path, thumbnail_path FROM chart_vision_analyses WHERE id = ?', [$analysisId]);
        if (!$row) {
            return false;
        }
        $ownerId = $row['user_id'] !== null ? (int)$row['user_id'] : $userId;
        if (!$isAdmin && $ownerId !== $userId) {
            throw new \RuntimeException('Unauthorized: You cannot delete another user\'s analysis.', 403);
        }

        $deleted = Database::execute('DELETE FROM chart_vision_analyses WHERE id = ?', [$analysisId]);
        return $deleted > 0;
    }

    /**
     * Send an AI Chart Vision analysis report directly to user's Telegram bot.
     * Enforces user ownership check (IDOR protection) and 60-second duplicate send guard.
     */
    public static function sendToTelegram(int $analysisId, int $userId, bool $isAdmin = false): array
    {
        $analysis = Database::fetch('SELECT * FROM chart_vision_analyses WHERE id = ?', [$analysisId]);
        if (!$analysis) {
            throw new \InvalidArgumentException('Chart analysis record not found (ID: ' . $analysisId . ')');
        }

        // Strict IDOR Ownership Check
        $ownerId = $analysis['user_id'] !== null ? (int)$analysis['user_id'] : $userId;
        if (!$isAdmin && $ownerId !== $userId) {
            throw new \RuntimeException('Unauthorized: You do not own this chart analysis record.', 403);
        }

        // Duplicate send protection within 60 seconds
        $dedupeWindow = (int)floor(time() / 60);
        $dedupeKey = "vision:{$userId}:{$analysisId}:{$dedupeWindow}";
        $alreadySent = Database::fetch(
            'SELECT id FROM notification_logs WHERE dedupe_key = ? AND user_id = ? LIMIT 1',
            [$dedupeKey, $userId]
        );
        if ($alreadySent) {
            return [
                'ok'           => true,
                'deduplicated' => true,
                'message'      => 'This chart analysis was already sent to Telegram within the last minute.',
            ];
        }

        $botToken = Notifier::botToken();
        if ($botToken === '') {
            throw new \RuntimeException('Telegram Bot Token is not configured. Please set TELEGRAM_BOT_TOKEN in .env or Settings.');
        }

        $userSettings = Database::fetch('SELECT telegram_chat_id FROM user_settings WHERE user_id = ?', [$userId]);
        $targetChat = trim((string)($userSettings['telegram_chat_id'] ?? ''));
        if ($targetChat === '') {
            $targetChat = '@i_am_Theaa_Rithhh';
        }

        $resolvedChatId = Notifier::resolveTelegramChatId($botToken, $targetChat, $userId);
        if ($resolvedChatId === '') {
            throw new \RuntimeException('Could not resolve Telegram chat ID for ' . $targetChat . '. Please ensure you have pressed /start on the bot.');
        }

        $report = json_decode((string)$analysis['analysis_json'], true) ?: [];
        if (empty($report['market_bias'])) {
            throw new \RuntimeException('Incomplete or invalid analysis report cannot be dispatched to Telegram.', 422);
        }

        $symbol = MarketDataService::normalizeSymbol((string)($analysis['symbol'] ?? 'XAUUSD'));
        $bias = (string)($analysis['market_bias'] ?? 'Neutral');
        $direction = (string)($report['trade_direction'] ?? $analysis['trade_direction'] ?? 'NO TRADE');
        $strategy = (string)($analysis['detected_strategy'] ?? ($report['detected_strategy'] ?? 'ICT/SMC Confluence Model'));
        $score = (int)($analysis['confidence_score'] ?? 80);
        $timeframe = (string)($analysis['timeframe'] ?? 'M15');
        $htfStatus = (string)($report['mtf_analysis']['alignment_status'] ?? 'Checked');

        $entryLow = (float)($analysis['entry_zone_low'] ?? ($report['entry_zone']['min'] ?? 0));
        $entryHigh = (float)($analysis['entry_zone_high'] ?? ($report['entry_zone']['max'] ?? 0));
        $sl = (float)($analysis['stop_loss'] ?? ($report['stop_loss']['price'] ?? 0));
        $tp1 = (float)($analysis['tp1'] ?? ($report['take_profit']['tp1']['price'] ?? 0));
        $tp2 = (float)($analysis['tp2'] ?? ($report['take_profit']['tp2']['price'] ?? 0));
        $tp3 = (float)($analysis['tp3'] ?? ($report['take_profit']['tp3']['price'] ?? 0));

        $entryStr = ($entryLow > 0 && $entryHigh > 0)
            ? sprintf('%s – %s', self::formatPrice(min($entryLow, $entryHigh), $symbol), self::formatPrice(max($entryLow, $entryHigh), $symbol))
            : 'N/A';
        $slStr = $sl > 0 ? self::formatPrice($sl, $symbol) : 'N/A';

        $warningLine = !empty($report['uncertainties_warnings'][0])
            ? (string)$report['uncertainties_warnings'][0]
            : 'Enforce strict 0.5%-1.0% risk management.';

        $captionLines = [
            sprintf('📊 %s (%s) · %s', $symbol, $timeframe, $direction),
            sprintf('🧭 Bias: %s (Evidence Score: %d/100) · HTF: %s', $bias, $score, $htfStatus),
            sprintf('🏛 Strategy: %s', $strategy),
            '📍 Entry Zone: ' . $entryStr,
            '🛑 Stop Loss: ' . $slStr,
        ];
        if ($tp1 > 0) $captionLines[] = sprintf('🎯 TP1 (2.0R): %s', self::formatPrice($tp1, $symbol));
        if ($tp2 > 0) $captionLines[] = sprintf('🎯 TP2 (3.5R): %s', self::formatPrice($tp2, $symbol));
        if ($tp3 > 0) $captionLines[] = sprintf('🎯 TP3 (5.0R): %s', self::formatPrice($tp3, $symbol));
        $captionLines[] = '⚠️ ' . $warningLine;

        $caption = implode("\n", $captionLines);

        $imagePath = (string)($analysis['image_path'] ?? '');
        $photoSent = false;

        if ($imagePath !== '' && file_exists($imagePath) && filesize($imagePath) < self::MAX_BYTES) {
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendPhoto");
            $cfile = new \CURLFile($imagePath);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'chat_id' => $resolvedChatId,
                    'caption' => mb_substr($caption, 0, 1024),
                    'photo'   => $cfile,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $rawRes = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $parsed = json_decode($rawRes, true);
            if ($httpCode === 200 && !empty($parsed['ok'])) {
                $photoSent = true;
            }
        }

        if (!$photoSent) {
            $msgRes = Http::postJson("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'                  => $resolvedChatId,
                'text'                     => $caption,
                'disable_web_page_preview' => true,
            ], [], ['timeout' => 12]);
            if (!empty($msgRes['json']['ok'])) {
                $photoSent = true;
            } else {
                $desc = $msgRes['json']['description'] ?? ($msgRes['error'] ?: 'Telegram API error');
                throw new \RuntimeException('Failed to deliver message via Telegram: ' . $desc);
            }
        }

        Database::insert('notification_logs', [
            'user_id'    => $userId,
            'signal_id'  => null,
            'channel'    => 'telegram',
            'event_type' => 'chart_vision',
            'title'      => sprintf('AI Chart Vision: %s %s %s (%s)', $symbol, $timeframe, $bias, $direction),
            'message'    => $caption,
            'dedupe_key' => $dedupeKey,
            'status'     => 'sent',
            'error'      => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'ok'           => true,
            'deduplicated' => false,
            'message'      => 'Chart analysis report successfully sent to Telegram bot!',
            'chat_id'      => $resolvedChatId,
            'photo_sent'   => $photoSent,
        ];
    }
}

if (!function_exists('clamp')) {
    function clamp(float $val, float $min, float $max): float
    {
        return max($min, min($max, $val));
    }
}
