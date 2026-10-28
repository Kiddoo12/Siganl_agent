<?php
declare(strict_types=1);

namespace App\Market;

use App\Core\Env;
use App\Core\Http;
use App\Core\Settings;

/**
 * Twelve Data adapter (https://twelvedata.com/docs). Supports XAU/USD and BTC/USD.
 * Free plans are rate limited (e.g. 8 req/min, 800/day) - the ingest cadence in
 * config/defaults.php (market.refresh_minutes) is designed to respect that.
 */
final class TwelveDataProvider implements MarketDataProvider
{
    private string $key;
    private string $symbol;
    private string $base;

    public function __construct(string $symbol = 'XAUUSD')
    {
        $this->key = (string)Env::get('TWELVEDATA_API_KEY', '');
        $this->base = rtrim((string)Env::get('TWELVEDATA_BASE_URL', 'https://api.twelvedata.com'), '/');
        $this->setSymbol($symbol);
    }

    public function setSymbol(string $symbol): void
    {
        $symbol = strtoupper(trim($symbol));
        $meta = Settings::get("market.symbols.$symbol");
        if (is_array($meta) && !empty($meta['twelvedata_symbol'])) {
            $this->symbol = (string)$meta['twelvedata_symbol'];
        } elseif ($symbol === 'BTCUSD') {
            $this->symbol = 'BTC/USD';
        } else {
            $this->symbol = (string)Env::get('TWELVEDATA_SYMBOL', 'XAU/USD');
        }
    }

    public function code(): string { return 'twelvedata'; }
    public function mode(): string { return 'live'; }
    public function label(): string { return 'Twelve Data ' . $this->symbol; }
    public function isConfigured(): bool { return $this->key !== ''; }

    public function fetchCandles(string $tf, int $limit, ?int $startTs = null, ?int $endTs = null): array
    {
        $this->assertConfigured();
        $params = [
            'symbol' => $this->symbol, 'interval' => Timeframe::TWELVEDATA[$tf],
            'outputsize' => max(1, min(5000, $limit)), 'timezone' => 'UTC', 'order' => 'ASC',
            'apikey' => $this->key,
        ];
        if ($startTs) $params['start_date'] = gmdate('Y-m-d H:i:s', $startTs);
        if ($endTs) $params['end_date'] = gmdate('Y-m-d H:i:s', $endTs);

        $res = Http::get($this->base . '/time_series?' . http_build_query($params));
        $json = $this->checkResponse($res);
        $out = [];
        foreach ($json['values'] ?? [] as $v) {
            $ts = strtotime($v['datetime'] . (strlen($v['datetime']) === 10 ? ' 00:00:00' : '') . ' UTC');
            if ($ts === false) continue;
            $out[] = [
                't' => $ts, 'o' => (float)$v['open'], 'h' => (float)$v['high'],
                'l' => (float)$v['low'], 'c' => (float)$v['close'],
                'v' => isset($v['volume']) && $v['volume'] !== '' ? (float)$v['volume'] : null,
            ];
        }
        usort($out, static fn($a, $b) => $a['t'] <=> $b['t']);
        return $out;
    }

    public function fetchQuote(): array
    {
        $this->assertConfigured();
        $res = Http::get($this->base . '/quote?' . http_build_query([
            'symbol' => $this->symbol, 'timezone' => 'UTC', 'apikey' => $this->key,
        ]));
        $q = $this->checkResponse($res);
        if (!isset($q['close']) || !is_numeric($q['close'])) {
            throw new \RuntimeException('Twelve Data quote missing price');
        }
        $price = (float)$q['close'];
        $p = Http::get($this->base . '/price?' . http_build_query(['symbol' => $this->symbol, 'apikey' => $this->key]), ['retries' => 0]);
        if (is_array($p['json']) && isset($p['json']['price']) && is_numeric($p['json']['price'])) {
            $price = (float)$p['json']['price'];
        }
        $prev = isset($q['previous_close']) && is_numeric($q['previous_close']) ? (float)$q['previous_close'] : null;
        return [
            'price' => $price,
            'prev_close' => $prev,
            'change' => $prev ? round($price - $prev, 3) : null,
            'change_pct' => $prev ? round(($price - $prev) / $prev * 100, 3) : null,
            'ts' => isset($q['timestamp']) ? (int)$q['timestamp'] : time(),
            'bid' => null, 'ask' => null,
            'market_open' => isset($q['is_market_open']) ? (bool)$q['is_market_open'] : null,
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('TWELVEDATA_API_KEY is not configured in .env');
        }
    }

    private function checkResponse(array $res): array
    {
        if ($res['error']) {
            throw new \RuntimeException('Twelve Data network error: ' . $res['error']);
        }
        $json = $res['json'];
        if (!is_array($json)) {
            throw new \RuntimeException('Twelve Data returned invalid JSON (HTTP ' . $res['status'] . ')');
        }
        if (($json['status'] ?? '') === 'error' || $res['status'] >= 400) {
            throw new \RuntimeException('Twelve Data error: ' . ($json['message'] ?? ('HTTP ' . $res['status'])));
        }
        return $json;
    }
}
