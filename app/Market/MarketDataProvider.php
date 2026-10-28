<?php
declare(strict_types=1);

namespace App\Market;

/**
 * Market data adapter contract. Candles are arrays:
 *   ['t' => int UTC open time, 'o' => float, 'h' => float, 'l' => float, 'c' => float, 'v' => ?float]
 * ordered oldest -> newest.
 */
interface MarketDataProvider
{
    public function code(): string;          // matches market_data_sources.code
    public function mode(): string;          // 'live' | 'demo'
    public function label(): string;
    public function isConfigured(): bool;

    /** @throws \RuntimeException on provider error */
    public function fetchCandles(string $tf, int $limit, ?int $startTs = null, ?int $endTs = null): array;

    /** @return array{price:float, change:?float, change_pct:?float, prev_close:?float, ts:int, bid:?float, ask:?float, market_open:?bool} */
    public function fetchQuote(): array;
}
