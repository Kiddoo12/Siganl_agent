<?php
declare(strict_types=1);

namespace App\Dataset;

/**
 * Standalone High-Resolution SVG Chart Image Generator.
 *
 * Generates vector chart graphics preserving candlesticks, volume histogram,
 * price/time grids, preliminary AI candidate boxes, and expert ground-truth annotations.
 */
final class ChartImageGenerator
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? (dirname(__DIR__, 2) . '/storage/datasets/charts');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
    }

    /**
     * Render and save SVG chart image for a chart window and its annotations.
     *
     * @param string $exampleUuid Unique example ID
     * @param string $symbol Instrument symbol ('XAUUSD')
     * @param string $timeframe Timeframe code ('M15')
     * @param array $candles Historical candles in window
     * @param array $annotations Preliminary or verified annotations
     * @param int $width Image width in pixels (default 1280)
     * @param int $height Image height in pixels (default 720)
     * @return string Relative file path to saved SVG image
     */
    public function renderAndSave(
        string $exampleUuid,
        string $symbol,
        string $timeframe,
        array $candles,
        array $annotations = [],
        int $width = 1280,
        int $height = 720
    ): string {
        $n = count($candles);
        if ($n === 0) {
            return '';
        }

        $padLeft = 20;
        $padRight = 75;
        $padTop = 45;
        $padBottom = 65;

        $plotW = $width - $padLeft - $padRight;
        $plotH = $height - $padTop - $padBottom;
        $volH = (int)round($plotH * 0.18);
        $pricePlotH = $plotH - $volH - 10;

        $highs = array_column($candles, 'h');
        $lows = array_column($candles, 'l');
        $pMax = max($highs);
        $pMin = min($lows);
        $pRange = max(0.001, $pMax - $pMin);

        // Add 3% buffer top and bottom
        $buf = $pRange * 0.03;
        $pMax += $buf;
        $pMin -= $buf;
        $pRange = max(0.001, $pMax - $pMin);

        $vols = array_column($candles, 'v');
        $vMax = !empty($vols) ? max($vols) : 1.0;
        $vMax = max(1.0, (float)$vMax);

        $svg = [];
        $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg[] = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\" style=\"background:#0b0f19; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;\">";

        // Grid lines
        $svg[] = "<g stroke=\"#162235\" stroke-width=\"1\">";
        $ySteps = 6;
        for ($s = 0; $s <= $ySteps; $s++) {
            $y = $padTop + ($pricePlotH / $ySteps) * $s;
            $svg[] = "<line x1=\"{$padLeft}\" y1=\"{$y}\" x2=\"" . ($width - $padRight) . "\" y2=\"{$y}\" />";
        }
        $svg[] = "</g>";

        // Price Axis labels on right
        $svg[] = "<g fill=\"#64748b\" font-size=\"11\" font-family=\"monospace\" text-anchor=\"start\">";
        for ($s = 0; $s <= $ySteps; $s++) {
            $p = $pMax - ($pRange / $ySteps) * $s;
            $y = $padTop + ($pricePlotH / $ySteps) * $s + 4;
            $svg[] = "<text x=\"" . ($width - $padRight + 8) . "\" y=\"{$y}\">" . number_format($p, 2) . "</text>";
        }
        $svg[] = "</g>";

        // Candlesticks & Volume
        $step = $plotW / max(1, $n);
        $cW = max(3.0, min(14.0, $step * 0.65));

        for ($i = 0; $i < $n; $i++) {
            $c = $candles[$i];
            $cx = $padLeft + $i * $step + ($step / 2.0);

            $openY = $padTop + (($pMax - (float)$c['o']) / $pRange) * $pricePlotH;
            $closeY = $padTop + (($pMax - (float)$c['c']) / $pRange) * $pricePlotH;
            $highY = $padTop + (($pMax - (float)$c['h']) / $pRange) * $pricePlotH;
            $lowY = $padTop + (($pMax - (float)$c['l']) / $pRange) * $pricePlotH;

            $isBull = (float)$c['c'] >= (float)$c['o'];
            $color = $isBull ? '#10b981' : '#ef4444';

            // Wick
            $svg[] = "<line x1=\"{$cx}\" y1=\"{$highY}\" x2=\"{$cx}\" y2=\"{$lowY}\" stroke=\"{$color}\" stroke-width=\"1.2\" />";

            // Body
            $bY = min($openY, $closeY);
            $bH = max(2.0, abs($closeY - $openY));
            $bX = $cx - ($cW / 2.0);
            $svg[] = "<rect x=\"{$bX}\" y=\"{$bY}\" width=\"{$cW}\" height=\"{$bH}\" fill=\"{$color}\" />";

            // Volume bar
            $v = (float)($c['v'] ?? 1.0);
            $barH = max(1.0, ($v / $vMax) * $volH);
            $vY = ($height - $padBottom) - $barH;
            $svg[] = "<rect x=\"{$bX}\" y=\"{$vY}\" width=\"{$cW}\" height=\"{$barH}\" fill=\"{$color}\" opacity=\"0.28\" />";
        }

        // Render Pattern Bounding Box Annotations
        foreach ($annotations as $ann) {
            $bx = $padLeft + (float)$ann['box_x'] * $plotW;
            $by = $padTop + (float)$ann['box_y'] * $pricePlotH;
            $bw = max(20.0, (float)$ann['box_w'] * $plotW);
            $bh = max(12.0, (float)$ann['box_h'] * $pricePlotH);

            $type = (string)$ann['pattern_type'];
            $isPrelim = (bool)($ann['is_preliminary'] ?? false);

            $color = '#f59e0b';
            if (str_contains($type, 'BULL')) $color = '#10b981';
            elseif (str_contains($type, 'BEAR')) $color = '#ef4444';
            elseif ($type === 'LIQUIDITY_SWEEP') $color = '#a855f7';
            elseif ($type === 'BOS' || $type === 'CHOCH') $color = '#06b6d4';

            $dash = $isPrelim ? "stroke-dasharray=\"4,3\"" : "";
            $fillOpacity = $isPrelim ? "0.12" : "0.22";

            $svg[] = "<g>";
            $svg[] = "<rect x=\"{$bx}\" y=\"{$by}\" width=\"{$bw}\" height=\"{$bh}\" fill=\"{$color}\" fill-opacity=\"{$fillOpacity}\" stroke=\"{$color}\" stroke-width=\"1.5\" {$dash} rx=\"3\" />";
            
            // Label tag pill
            $labelY = max($padTop + 14, $by - 4);
            $labelTxt = ($isPrelim ? "⚡ [AUTO] " : "✓ [GT] ") . $type;
            $svg[] = "<text x=\"{$bx}\" y=\"{$labelY}\" fill=\"{$color}\" font-size=\"10\" font-weight=\"bold\">" . htmlspecialchars($labelTxt) . "</text>";
            $svg[] = "</g>";
        }

        // Header Title & Watermark
        $firstTime = gmdate('Y-m-d H:i', $candles[0]['t']);
        $lastTime = gmdate('Y-m-d H:i', $candles[$n - 1]['t']);
        $svg[] = "<text x=\"{$padLeft}\" y=\"26\" fill=\"#f8fafc\" font-size=\"16\" font-weight=\"bold\">{$symbol} · {$timeframe} Institutional Chart Window</text>";
        $svg[] = "<text x=\"{$padLeft}\" y=\"40\" fill=\"#64748b\" font-size=\"11\">Window: {$firstTime} → {$lastTime} UTC · UUID: {$exampleUuid}</text>";

        $svg[] = "</svg>";

        $svgContent = implode("\n", $svg);
        $filename = "chart_{$exampleUuid}.svg";
        $filePath = $this->storageDir . '/' . $filename;
        file_put_contents($filePath, $svgContent);

        return 'storage/datasets/charts/' . $filename;
    }
}
