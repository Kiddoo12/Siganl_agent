<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Notifier;
use App\Market\MarketDataService;
use App\SMC\IctEngine;
use App\SMC\SmcEngine;

/**
 * Service handling chart screenshot uploads, AI vision analysis,
 * visual chart overlay generation, and history persistence.
 */
final class ChartVisionService
{
    private const ALLOWED_MIME = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 10485760; // 10MB

    /**
     * Store and validate an uploaded file or base64 image data.
     * @return array{path: string, url: string, mime: string, width: int, height: int}
     */
    public static function storeUpload(array $fileInfo, ?string $base64Payload = null): array
    {
        $uploadDir = APP_ROOT . '/storage/uploads/vision';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $tmpFile = null;
        $mime = null;

        if ($base64Payload !== null && trim($base64Payload) !== '') {
            if (preg_match('/^data:(image\/[a-zA-Z0-9\-\+\.]+);base64,(.+)$/', $base64Payload, $matches)) {
                $mime = strtolower($matches[1]);
                $binary = base64_decode($matches[2], true);
            } else {
                $binary = base64_decode($base64Payload, true);
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $binary ? $finfo->buffer($binary) : null;
            }
            if (!$binary || !isset(self::ALLOWED_MIME[$mime])) {
                throw new \InvalidArgumentException('Invalid base64 chart screenshot format. Only PNG, JPG, JPEG, and WEBP are supported.');
            }
            if (strlen($binary) > self::MAX_BYTES) {
                throw new \InvalidArgumentException('Image exceeds the maximum upload size of 10MB.');
            }
            $ext = self::ALLOWED_MIME[$mime];
            $fileName = 'chart_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destPath = $uploadDir . '/' . $fileName;
            file_put_contents($destPath, $binary);
        } else {
            if (empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                throw new \InvalidArgumentException('No valid image file uploaded.');
            }
            if ($fileInfo['size'] > self::MAX_BYTES) {
                throw new \InvalidArgumentException('Image exceeds the maximum upload size of 10MB.');
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fileInfo['tmp_name']);
            if (!isset(self::ALLOWED_MIME[$mime])) {
                throw new \InvalidArgumentException('Invalid image type (' . htmlspecialchars((string)$mime) . '). Only PNG, JPG, JPEG, and WEBP are allowed.');
            }
            $ext = self::ALLOWED_MIME[$mime];
            $fileName = 'chart_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destPath = $uploadDir . '/' . $fileName;
            if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
                throw new \RuntimeException('Failed to save uploaded chart image to storage.');
            }
        }

        $imageSize = @getimagesize($destPath) ?: [800, 600];
        $width = (int)$imageSize[0];
        $height = (int)$imageSize[1];

        return [
            'path'   => $destPath,
            'rel_url'=> 'storage/uploads/vision/' . $fileName,
            'mime'   => $mime,
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Run full AI Chart Vision analysis on an uploaded screenshot.
     */
    public static function analyzeChart(
        string $imagePath,
        string $symbol = 'XAUUSD',
        string $timeframe = 'M15',
        ?float $manualPrice = null,
        ?string $broker = null,
        ?string $chartTz = null,
        ?int $userId = null
    ): array {
        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException('Chart image file does not exist at ' . $imagePath);
        }

        $symbol = MarketDataService::normalizeSymbol($symbol);
        $timeframe = strtoupper(trim($timeframe)) ?: 'M15';

        // Read image bytes for base64 transmission
        $imageBytes = file_get_contents($imagePath);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($imagePath);
        $base64 = base64_encode($imageBytes);

        // Fetch live/engine market data to ground analysis
        $market = new MarketDataService(null, $symbol);
        $quote = $market->quote(false);
        $refPrice = $manualPrice ?: ($quote['available'] ? (float)$quote['price'] : 2400.0);

        // Check if external Vision AI is configured (Gemini or OpenAI)
        $geminiKey = trim((string)Env::get('GEMINI_API_KEY', ''));
        $openAiKey = trim((string)Env::get('AI_API_KEY', ''));
        $aiProvider = strtolower(trim((string)Env::get('AI_PROVIDER', 'none')));

        $report = null;
        $aiModelUsed = 'heuristic-engine';

        // 1. Try Gemini Vision if key exists
        if ($geminiKey !== '') {
            try {
                $report = self::callGeminiVision($geminiKey, $base64, $mime, $symbol, $timeframe, $refPrice, $broker, $chartTz);
                $aiModelUsed = 'gemini-1.5-flash';
            } catch (\Throwable $e) {
                Logger::warning('Gemini vision API error, falling back to heuristic engine', ['error' => $e->getMessage()]);
            }
        }

        // 2. Try OpenAI Vision if configured
        if (!$report && $aiProvider === 'openai' && $openAiKey !== '') {
            try {
                $report = self::callOpenAiVision($openAiKey, $base64, $mime, $symbol, $timeframe, $refPrice, $broker, $chartTz);
                $aiModelUsed = 'gpt-4o-mini';
            } catch (\Throwable $e) {
                Logger::warning('OpenAI vision API error, falling back to heuristic engine', ['error' => $e->getMessage()]);
            }
        }

        // 3. Robust Deterministic Heuristic Analysis (always guaranteed to work)
        if (!$report) {
            $report = self::generateHeuristicAnalysis($symbol, $timeframe, $refPrice, $broker, $chartTz, $market);
            $aiModelUsed = 'deterministic-smc-ict';
        }

        // Compute Visual Chart Overlay
        $imgSize = @getimagesize($imagePath) ?: [1200, 800];
        $overlay = self::generateVisualOverlay($report, (int)$imgSize[0], (int)$imgSize[1], $refPrice);

        // Persist to database
        $analysisId = Database::insert('chart_vision_analyses', [
            'user_id'           => $userId,
            'symbol'            => $symbol,
            'timeframe'         => $timeframe,
            'image_path'        => $imagePath,
            'thumbnail_path'    => $imagePath,
            'manual_price'      => $manualPrice,
            'broker'            => $broker,
            'chart_tz'          => $chartTz,
            'market_bias'       => in_array($report['market_bias'], ['Bullish','Bearish','Neutral'], true) ? $report['market_bias'] : 'Neutral',
            'detected_strategy' => $report['detected_strategy'] ?? 'ICT/SMC Confluence Model',
            'entry_zone_low'    => $report['entry_zone']['min'] ?? null,
            'entry_zone_high'   => $report['entry_zone']['max'] ?? null,
            'stop_loss'         => $report['stop_loss']['price'] ?? null,
            'tp1'               => $report['take_profit']['tp1']['price'] ?? null,
            'tp2'               => $report['take_profit']['tp2']['price'] ?? null,
            'tp3'               => $report['take_profit']['tp3']['price'] ?? null,
            'risk_reward'       => $report['risk_reward']['tp1'] ?? null,
            'confidence_score'  => (int)($report['confidence_score'] ?? 75),
            'analysis_json'     => json_encode($report),
            'overlay_json'      => json_encode($overlay),
            'ai_model'          => $aiModelUsed,
            'created_at'        => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'analysis_id'       => $analysisId,
            'symbol'            => $symbol,
            'timeframe'         => $timeframe,
            'ai_model'          => $aiModelUsed,
            'report'            => $report,
            'overlay'           => $overlay,
            'created_at'        => gmdate('Y-m-d H:i:s'),
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
        ?string $chartTz
    ): ?array {
        $model = (string)Env::get('GEMINI_MODEL', 'gemini-1.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $systemInstruction = self::buildSystemPrompt($symbol, $timeframe, $refPrice, $broker, $chartTz);

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
                'temperature'     => 0.15,
                'maxOutputTokens' => 1500,
                'responseMimeType'=> 'application/json',
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
        ?string $chartTz
    ): ?array {
        $baseUrl = rtrim((string)Env::get('AI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $model = (string)Env::get('AI_MODEL', 'gpt-4o-mini');
        $systemInstruction = self::buildSystemPrompt($symbol, $timeframe, $refPrice, $broker, $chartTz);

        $payload = [
            'model'       => $model,
            'temperature' => 0.15,
            'max_tokens'  => 1500,
            'response_format' => ['type' => 'json_object'],
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => $systemInstruction,
                ],
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Analyze this {$symbol} {$timeframe} chart screenshot according to ICT/SMC principles. Current reference price is {$refPrice}.",
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mime};base64,{$base64}",
                            ],
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
     * Institutional System Prompt enforcing structured 14-point report and price safety.
     */
    private static function buildSystemPrompt(string $symbol, string $timeframe, float $refPrice, ?string $broker, ?string $chartTz): string
    {
        return <<<PROMPT
You are a top-tier institutional ICT (Inner Circle Trader) and SMC (Smart Money Concepts) Market Analyst specializing in {$symbol}.
Analyze the provided trading chart image rigorously and return ONLY a valid, minified JSON object matching the required schema.

CRITICAL RULES:
1. STRICT PRICING INTEGRITY: Never invent arbitrary prices. If the chart's price axis is blurry or ambiguous, anchor levels reasonably around the provided reference price {$refPrice}.
2. Separate OBSERVED CHART FACTS (what is physically visible) from AI INTERPRETATIONS (probabilistic SMC/ICT deductions).
3. If setup conditions are incomplete, low quality, or high-risk, explicitly select "NO TRADE" or "WAITING" and explain why in reasons_to_avoid.

SCHEMA REQUIREMENTS (JSON format):
{
  "market_bias": "Bullish" | "Bearish" | "Neutral",
  "htf_direction": "Bullish" | "Bearish" | "Neutral",
  "market_structure": "BOS Confirmed" | "CHoCH Reversal" | "Market Structure Shift (MSS)" | "Consolidation Range",
  "structure_details": "Detailed observation of recent swing points, breaks, and shifts.",
  "liquidity_targets": {
    "bsl": [{"level": 0.0, "label": "Equal Highs / BSL"}],
    "ssl": [{"level": 0.0, "label": "Sell-side Liquidity / PDL"}]
  },
  "fvg_ob_zones": {
    "fvgs": [{"type": "Bullish FVG", "top": 0.0, "bottom": 0.0, "status": "fresh"}],
    "order_blocks": [{"type": "Bearish OB", "top": 0.0, "bottom": 0.0, "status": "unmitigated"}]
  },
  "detected_strategy": "ICT 2022 Mentorship Model" | "ICT Silver Bullet" | "ICT Unicorn Model" | "ICT OTE" | "ICT Turtle Soup" | "SMC Breaker Retest",
  "trade_direction": "BUY" | "SELL" | "NO TRADE" | "WAITING",
  "entry_zone": {"min": 0.0, "max": 0.0, "ref_entry": 0.0},
  "stop_loss": {"price": 0.0, "reason": "Structural invalidation beyond liquidity sweep"},
  "take_profit": {
    "tp1": {"price": 0.0, "rr": 2.0, "target": "Opposing BSL"},
    "tp2": {"price": 0.0, "rr": 3.5, "target": "Major Liquidity Pool"},
    "tp3": {"price": 0.0, "rr": 5.0, "target": "HTF Dealing Range EQ/High"}
  },
  "risk_reward": {"tp1": 2.0, "tp2": 3.5, "tp3": 5.0},
  "setup_invalidations": ["Candle close beyond Stop Loss", "Flip of HTF structure", "Opposing CHoCH"],
  "supporting_evidence": ["Sweep of Asian low", "Displacement candle with FVG", "Premium/discount alignment"],
  "reasons_to_avoid": ["High-impact news window nearby", "Wide spread", "Unclear price scale"],
  "observed_facts": ["Candlestick sequence visible", "High at X, Low at Y"],
  "uncertainties_warnings": ["Price scale partially obscured", "Timeframe resolution limited"],
  "confidence_score": 85
}
PROMPT;
    }

    /**
     * Generate deterministic, high-confluence analysis using live/demo engine data.
     */
    public static function generateHeuristicAnalysis(
        string $symbol,
        string $timeframe,
        float $refPrice,
        ?string $broker,
        ?string $chartTz,
        MarketDataService $market
    ): array {
        $c = $market->candles($timeframe, 150);
        $smcEngine = new SmcEngine();
        $smcRes = $smcEngine->analyze($c);
        $n = count($c);
        $atr = $smcRes['atr'] ?? 3.5;

        $ictEngine = new IctEngine();
        $ictRes = $ictEngine->analyze($c, $smcRes, time());

        $trend = $smcRes['trend'] ?? 0;
        $isBull = ($trend === 1);
        $isBear = ($trend === -1);
        $bias = $isBull ? 'Bullish' : ($isBear ? 'Bearish' : 'Neutral');

        // Check if ICT strategy was detected
        $primaryStrat = 'ICT 2022 Mentorship Model';
        if (!empty($ictRes['detected_strategies'])) {
            $primaryStrat = $ictRes['detected_strategies'][0]['name'];
        }

        $lastEvt = $smcRes['last_event'] ?? null;
        $structLabel = $lastEvt
            ? ($lastEvt['mss'] ? 'Market Structure Shift (MSS)' : ($lastEvt['type'] . ' Confirmed'))
            : 'Consolidation / Dealing Range';

        // Entry zone determination
        $entryZone = null;
        $obList = $smcRes['order_blocks'] ?? [];
        $fvgList = $smcRes['fvgs'] ?? [];

        // Check Unicorn first
        if (!empty($ictRes['unicorns'])) {
            $u = $ictRes['unicorns'][0];
            $entryZone = ['min' => $u['bottom'], 'max' => $u['top'], 'ref_entry' => $u['mid']];
            $primaryStrat = 'ICT Unicorn Model (Breaker + FVG)';
        } elseif (!empty($fvgList)) {
            $lastFvg = end($fvgList);
            $entryZone = ['min' => $lastFvg['bottom'], 'max' => $lastFvg['top'], 'ref_entry' => round(($lastFvg['top'] + $lastFvg['bottom']) / 2, 2)];
        } elseif (!empty($obList)) {
            $lastOb = end($obList);
            $entryZone = ['min' => $lastOb['bottom'], 'max' => $lastOb['top'], 'ref_entry' => round(($lastOb['top'] + $lastOb['bottom']) / 2, 2)];
        } else {
            $entryZone = ['min' => round($refPrice - 0.25 * $atr, 2), 'max' => round($refPrice + 0.25 * $atr, 2), 'ref_entry' => $refPrice];
        }

        // Levels
        $entry = $entryZone['ref_entry'];
        $slDist = max(1.5, 1.2 * $atr);
        $sl = $isBull ? round($entry - $slDist, 2) : round($entry + $slDist, 2);
        $risk = abs($entry - $sl);

        $tp1 = $isBull ? round($entry + 2.0 * $risk, 2) : round($entry - 2.0 * $risk, 2);
        $tp2 = $isBull ? round($entry + 3.5 * $risk, 2) : round($entry - 3.5 * $risk, 2);
        $tp3 = $isBull ? round($entry + 5.0 * $risk, 2) : round($entry - 5.0 * $risk, 2);

        $bsl = [];
        foreach (array_slice($smcRes['liquidity']['bsl'] ?? [], -3) as $l) {
            $bsl[] = ['level' => (float)$l['price'], 'label' => 'Buy-Side Liquidity (BSL)'];
        }
        $ssl = [];
        foreach (array_slice($smcRes['liquidity']['ssl'] ?? [], -3) as $l) {
            $ssl[] = ['level' => (float)$l['price'], 'label' => 'Sell-Side Liquidity (SSL)'];
        }

        return [
            'market_bias'         => $bias,
            'htf_direction'       => $bias,
            'market_structure'    => $structLabel,
            'structure_details'   => sprintf(
                'Chart demonstrates %s flow on %s. Recent %s confirmed around $%.2f with ATR volatility of $%.2f.',
                strtolower($bias), $timeframe, $structLabel, (float)($lastEvt['level'] ?? $refPrice), $atr
            ),
            'liquidity_targets'   => ['bsl' => $bsl ?: [['level' => round($refPrice + 2 * $atr, 2), 'label' => 'BSL Resting High']], 'ssl' => $ssl ?: [['level' => round($refPrice - 2 * $atr, 2), 'label' => 'SSL Resting Low']]],
            'fvg_ob_zones'        => [
                'fvgs'        => array_slice($fvgList, -2),
                'order_blocks'=> array_slice($obList, -2),
            ],
            'detected_strategy'   => $primaryStrat,
            'trade_direction'     => $bias === 'Neutral' ? 'WAITING' : ($isBull ? 'BUY' : 'SELL'),
            'entry_zone'          => $entryZone,
            'stop_loss'           => ['price' => $sl, 'reason' => 'Structural invalidation beyond opposing impulse leg extreme'],
            'take_profit'         => [
                'tp1' => ['price' => $tp1, 'rr' => 2.0, 'target' => 'Opposing Intraday Liquidity Pool'],
                'tp2' => ['price' => $tp2, 'rr' => 3.5, 'target' => 'Dealing Range EQ / Structural Target'],
                'tp3' => ['price' => $tp3, 'rr' => 5.0, 'target' => 'Major External Session High/Low'],
            ],
            'risk_reward'         => ['tp1' => 2.0, 'tp2' => 3.5, 'tp3' => 5.0],
            'setup_invalidations' => [
                sprintf('Candle closes beyond Stop Loss at $%.2f', $sl),
                'Opposing Market Structure Shift (MSS) forms before entry fill',
                'Price pierces TP1 prior to entering the defined entry zone',
            ],
            'supporting_evidence' => [
                sprintf('%s market structure confirmed on %s', $bias, $timeframe),
                sprintf('Key institutional zone (%s) identified between $%.2f – $%.2f', $primaryStrat, $entryZone['min'], $entryZone['max']),
                'Positive technical Risk-to-Reward ratio exceeding 2.0R',
                !empty($ictRes['killzone']['active']) ? "Active killzone: {$ictRes['killzone']['active']}" : 'Standard trading hours alignment',
            ],
            'reasons_to_avoid'    => [
                'Do not chase market orders if price exceeds 35% of the distance to TP1',
                'Verify economic calendar for upcoming high-impact USD events',
            ],
            'observed_facts'      => [
                sprintf('Observed candlestick action around reference price $%.2f', $refPrice),
                sprintf('Timeframe: %s | Broker / Data Feed: %s', $timeframe, $broker ?: 'Institutional ECN'),
            ],
            'uncertainties_warnings' => [
                'Visual chart interpretation is probabilistic decision-support; verify exact broker spread before execution.',
            ],
            'confidence_score'    => ($bias !== 'Neutral') ? 85 : 55,
        ];
    }

    /**
     * Generate visual SVG/Canvas bounding coordinates mapped over the chart image.
     */
    public static function generateVisualOverlay(array $report, int $width, int $height, float $refPrice): array
    {
        $dir = $report['trade_direction'] ?? 'BUY';
        $entryZone = $report['entry_zone'] ?? ['min' => $refPrice - 2, 'max' => $refPrice + 2, 'ref_entry' => $refPrice];
        $sl = (float)($report['stop_loss']['price'] ?? ($refPrice - 5));
        $tp1 = (float)($report['take_profit']['tp1']['price'] ?? ($refPrice + 5));
        $tp2 = (float)($report['take_profit']['tp2']['price'] ?? ($refPrice + 10));

        // Estimate price range window to map Y coordinates (top = max price, bottom = min price)
        $prices = [$entryZone['min'], $entryZone['max'], $sl, $tp1, $tp2, $refPrice];
        $maxP = max($prices) * 1.002;
        $minP = min($prices) * 0.998;
        $pRange = max(0.01, $maxP - $minP);

        $priceToY = function(float $p) use ($maxP, $minP, $pRange, $height): int {
            $fraction = ($maxP - $p) / $pRange; // Top is 0px
            return (int)round(clamp($fraction * $height, 20.0, (float)($height - 20)));
        };

        $yEntryTop = $priceToY((float)$entryZone['max']);
        $yEntryBot = $priceToY((float)$entryZone['min']);
        $ySl = $priceToY($sl);
        $yTp1 = $priceToY($tp1);
        $yTp2 = $priceToY($tp2);

        return [
            'width'       => $width,
            'height'      => $height,
            'elements'    => [
                [
                    'type'     => 'zone',
                    'label'    => 'ENTRY ZONE (' . $report['detected_strategy'] . ')',
                    'y1'       => min($yEntryTop, $yEntryBot),
                    'y2'       => max($yEntryTop, $yEntryBot),
                    'color'    => 'rgba(0, 229, 255, 0.22)',
                    'stroke'   => '#00e5ff',
                    'price_low'=> $entryZone['min'],
                    'price_high'=> $entryZone['max'],
                ],
                [
                    'type'     => 'line',
                    'label'    => "STOP LOSS ($sl)",
                    'y'        => $ySl,
                    'color'    => '#ff3366',
                    'price'    => $sl,
                    'dash'     => [4, 4],
                ],
                [
                    'type'     => 'line',
                    'label'    => "TAKE PROFIT 1 ($tp1)",
                    'y'        => $yTp1,
                    'color'    => '#00ff88',
                    'price'    => $tp1,
                    'dash'     => [6, 4],
                ],
                [
                    'type'     => 'line',
                    'label'    => "TAKE PROFIT 2 ($tp2)",
                    'y'        => $yTp2,
                    'color'    => '#00cc66',
                    'price'    => $tp2,
                    'dash'     => [6, 4],
                ],
            ],
        ];
    }

    private static function normalizeReport(array $raw, float $refPrice): array
    {
        $raw['market_bias'] = ucfirst(strtolower((string)($raw['market_bias'] ?? 'Neutral')));
        if (!in_array($raw['market_bias'], ['Bullish','Bearish','Neutral'], true)) {
            $raw['market_bias'] = 'Neutral';
        }
        $raw['confidence_score'] = min(100, max(20, (int)($raw['confidence_score'] ?? 75)));
        return $raw;
    }

    /**
     * Send an AI Chart Vision analysis report directly to user's Telegram bot.
     * Supports sending with the chart screenshot photo and full structured SMC/ICT levels.
     */
    public static function sendToTelegram(int $analysisId, int $userId): array
    {
        $analysis = Database::fetch('SELECT * FROM chart_vision_analyses WHERE id = ?', [$analysisId]);
        if (!$analysis) {
            throw new \InvalidArgumentException('Chart analysis record not found (ID: ' . $analysisId . ')');
        }

        $botToken = Notifier::botToken();
        if ($botToken === '') {
            throw new \RuntimeException('Telegram Bot Token is not configured. Please set TELEGRAM_BOT_TOKEN in .env or Settings.');
        }

        // Fetch user's Telegram recipient settings
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
        $bias = $analysis['market_bias'] ?? 'Neutral';
        $biasEmoji = ($bias === 'Bullish') ? '🟢' : (($bias === 'Bearish') ? '🔴' : '🟡');
        $direction = $report['trade_direction'] ?? ($bias === 'Bullish' ? 'BUY' : ($bias === 'Bearish' ? 'SELL' : 'WAIT'));
        $strategy = $analysis['detected_strategy'] ?? ($report['detected_strategy'] ?? 'ICT/SMC Confluence Model');
        $score = (int)($analysis['confidence_score'] ?? 80);

        $entryLow = (float)($analysis['entry_zone_low'] ?? ($report['entry_zone']['min'] ?? 0));
        $entryHigh = (float)($analysis['entry_zone_high'] ?? ($report['entry_zone']['max'] ?? 0));
        $sl = (float)($analysis['stop_loss'] ?? ($report['stop_loss']['price'] ?? 0));
        $tp1 = (float)($analysis['tp1'] ?? ($report['take_profit']['tp1']['price'] ?? 0));
        $tp2 = (float)($analysis['tp2'] ?? ($report['take_profit']['tp2']['price'] ?? 0));
        $tp3 = (float)($analysis['tp3'] ?? ($report['take_profit']['tp3']['price'] ?? 0));

        $symbol = (string)($analysis['symbol'] ?? 'XAUUSD');
        $direction = (string)($report['trade_direction'] ?? ($bias === 'Bullish' ? 'BUY' : ($bias === 'Bearish' ? 'SELL' : '')));

        if ($entryLow > 0 && $entryHigh > 0) {
            $entryStr = (abs($entryLow - $entryHigh) < 0.01)
                ? sprintf('$%.2f', $entryLow)
                : sprintf('$%.2f – $%.2f', min($entryLow, $entryHigh), max($entryLow, $entryHigh));
        } elseif ($entryLow > 0) {
            $entryStr = sprintf('$%.2f', $entryLow);
        } elseif ($entryHigh > 0) {
            $entryStr = sprintf('$%.2f', $entryHigh);
        } else {
            $refEntry = (float)($report['entry_zone']['ref_entry'] ?? 0);
            $entryStr = $refEntry > 0 ? sprintf('$%.2f', $refEntry) : 'Market';
        }

        $slStr = $sl > 0 ? sprintf('$%.2f', $sl) : 'n/a';

        // Clean trade setup for Telegram: Entry, SL, and all TP
        $captionLines = [];
        if ($direction !== '') {
            $captionLines[] = sprintf('📊 %s · %s', $symbol, $direction);
        } else {
            $captionLines[] = sprintf('📊 %s', $symbol);
        }
        $captionLines[] = '📍 Entry: ' . $entryStr;
        $captionLines[] = '🛑 SL: ' . $slStr;
        if ($tp1 > 0) {
            $captionLines[] = sprintf('🎯 TP1: $%.2f', $tp1);
        }
        if ($tp2 > 0) {
            $captionLines[] = sprintf('🎯 TP2: $%.2f', $tp2);
        }
        if ($tp3 > 0) {
            $captionLines[] = sprintf('🎯 TP3: $%.2f', $tp3);
        }
        if ($tp1 <= 0 && $tp2 <= 0 && $tp3 <= 0) {
            $captionLines[] = '🎯 TP: n/a';
        }

        $caption = implode("\n", $captionLines);

        $imagePath = $analysis['image_path'] ?? '';
        $photoSent = false;

        // Try sending photo if image file exists
        if ($imagePath !== '' && file_exists($imagePath) && filesize($imagePath) < 10 * 1024 * 1024) {
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

        // If photo sending didn't succeed, send via text message
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

        // Log notification to database
        Database::insert('notification_logs', [
            'user_id'    => $userId,
            'signal_id'  => null,
            'channel'    => 'telegram',
            'event_type' => 'chart_vision',
            'title'      => sprintf('AI Chart Vision: %s %s %s', $analysis['symbol'], $bias, $strategy),
            'message'    => $caption,
            'dedupe_key' => 'vision:' . $analysisId . ':' . time(),
            'status'     => 'sent',
            'error'      => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'ok'        => true,
            'message'   => 'Chart analysis report successfully sent to Telegram bot (@Rithhha_bot)!',
            'chat_id'   => $resolvedChatId,
            'photo_sent'=> $photoSent,
        ];
    }
}

if (!function_exists('clamp')) {
    function clamp(float $val, float $min, float $max): float {
        return max($min, min($max, $val));
    }
}
