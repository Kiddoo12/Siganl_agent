<?php
declare(strict_types=1);

namespace App\Market;

use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Settings;

/** Ingestion, storage, retrieval and freshness checks for market data (XAUUSD & BTCUSD). */
final class MarketDataService
{
    public const SUPPORTED_SYMBOLS = ['XAUUSD', 'BTCUSD', 'EURUSD', 'GBPUSD', 'USDJPY', 'US30', 'NAS100'];

    private MarketDataProvider $provider;
    private string $symbol;
    private ?int $sourceId = null;

    public function __construct($provider = null, ?string $symbol = null)
    {
        if (is_string($provider)) {
            $symbol = $provider;
            $provider = null;
        }
        $defaultSymbol = (string)Settings::get('market.symbol', 'XAUUSD');
        $this->symbol = self::normalizeSymbol($symbol ?? $defaultSymbol);
        $this->provider = $provider ?? self::makeProvider(null, $this->symbol);
        if (method_exists($this->provider, 'setSymbol')) {
            $this->provider->setSymbol($this->symbol);
        }
    }

    public static function normalizeSymbol(?string $symbol): string
    {
        $clean = strtoupper(str_replace(['/', '-', ' '], '', trim((string)$symbol)));
        if ($clean === 'BTCUSE' || $clean === 'BTCUSDT') {
            $clean = 'BTCUSD';
        } elseif ($clean === 'GOLD' || $clean === 'XAU') {
            $clean = 'XAUUSD';
        } elseif ($clean === 'DJI' || $clean === 'DOW' || $clean === 'WS30') {
            $clean = 'US30';
        } elseif ($clean === 'NDX' || $clean === 'NASDAQ' || $clean === 'NAS') {
            $clean = 'NAS100';
        }
        return in_array($clean, self::SUPPORTED_SYMBOLS, true) ? $clean : 'XAUUSD';
    }

    public static function supportedSymbols(): array
    {
        $out = [];
        foreach (self::SUPPORTED_SYMBOLS as $sym) {
            $out[$sym] = self::symbolMeta($sym);
        }
        return $out;
    }

    public static function symbolMeta(string $symbol): array
    {
        $sym = self::normalizeSymbol($symbol);
        $all = (array)Settings::get('market.symbols', []);
        return $all[$sym] ?? [
            'code'           => $sym,
            'display'        => $sym === 'BTCUSD' ? 'BTC/USD' : 'XAU/USD',
            'name'           => $sym === 'BTCUSD' ? 'Bitcoin / US Dollar' : 'Spot Gold',
            'contract_size'  => $sym === 'BTCUSD' ? 1.0 : 100.0,
            'unit'           => $sym === 'BTCUSD' ? 'BTC' : 'oz',
            'assumed_spread' => $sym === 'BTCUSD' ? 15.0 : 0.30,
            'max_spread'     => $sym === 'BTCUSD' ? 60.0 : 0.60,
            'is_24_7'        => $sym === 'BTCUSD',
        ];
    }

    public static function makeProvider(?string $code = null, string $symbol = 'XAUUSD'): MarketDataProvider
    {
        $code = $code ?? strtolower((string)Env::get('MARKET_PROVIDER', 'yahoo'));
        switch ($code) {
            case 'yahoo':
            case 'live':
                return new YahooProvider($symbol);
            case 'twelvedata':
                return new TwelveDataProvider($symbol);
            case 'demo':
                return new DemoProvider($symbol);
            default:
                throw new \RuntimeException("Unknown MARKET_PROVIDER '$code'");
        }
    }

    public function provider(): MarketDataProvider { return $this->provider; }
    public function mode(): string { return $this->provider->mode(); }
    public function symbol(): string { return $this->symbol; }

    public function sourceId(): int
    {
        if ($this->sourceId === null) {
            $id = Database::value('SELECT id FROM market_data_sources WHERE code = ?', [$this->provider->code()]);
            if (!$id) {
                $now = gmdate('Y-m-d H:i:s');
                $id = Database::insert('market_data_sources', [
                    'code' => $this->provider->code(), 'name' => $this->provider->label(),
                    'type' => $this->provider->mode(), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->sourceId = (int)$id;
        }
        return $this->sourceId;
    }

    /** All timeframes the analysis needs, de-duplicated. */
    public static function requiredTimeframes(): array
    {
        $tfs = array_merge(
            (array)Settings::get('market.context_tfs', []),
            (array)Settings::get('market.bias_tfs', []),
            [(string)Settings::get('market.poi_tf', 'H1'), (string)Settings::get('market.entry_tf', 'M15')],
            (array)Settings::get('market.refine_tfs', [])
        );
        return array_values(array_unique(array_filter($tfs, [Timeframe::class, 'valid'])));
    }

    /**
     * Fetch latest candles for a timeframe if its refresh cadence has elapsed.
     * @return array{tf:string, fetched:int, stored:int, skipped:bool, issues:array, error:?string}
     */
    public function ingest(string $tf, bool $force = false): array
    {
        $result = ['tf' => $tf, 'fetched' => 0, 'stored' => 0, 'skipped' => false, 'issues' => [], 'error' => null];
        $stateKey = 'ingest.' . $this->provider->code() . '.' . $this->symbol . '.' . $tf;
        $cadence = (int)(Settings::get('market.refresh_minutes')[$tf] ?? 15) * 60;
        $last = (int)Settings::state($stateKey, 0);
        if (!$force && $this->provider->mode() === 'live' && time() - $last < $cadence) {
            $result['skipped'] = true;
            return $result;
        }
        try {
            $have = (int)Database::value(
                'SELECT COUNT(*) FROM market_candles WHERE source_id = ? AND symbol = ? AND timeframe = ?',
                [$this->sourceId(), $this->symbol, $tf]
            );
            $limit = $have >= 200 ? 100 : (int)Settings::get('market.candles_per_tf', 500);
            $raw = $this->provider->fetchCandles($tf, $limit);
            $v = CandleValidator::validate($raw, $tf);
            $result['fetched'] = count($raw);
            $result['issues'] = $v['issues'];
            $result['stored'] = $this->store($tf, $v['candles']);
            Settings::setState($stateKey, time());
            $this->markHealth(true);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->markHealth(false, $e->getMessage());
            Logger::error('Ingest failed', ['symbol' => $this->symbol, 'tf' => $tf, 'provider' => $this->provider->code(), 'err' => $e->getMessage()]);
        }
        return $result;
    }

    /** Historical backfill in chunks (used by bin/backfill.php). */
    public function backfill(string $tf, int $fromTs, int $toTs): int
    {
        $total = 0;
        $cursorEnd = $toTs;
        $guard = 0;
        while ($cursorEnd > $fromTs && $guard++ < 200) {
            $raw = $this->provider->fetchCandles($tf, 5000, $fromTs, $cursorEnd);
            $v = CandleValidator::validate($raw, $tf);
            if (!$v['candles']) break;
            $total += $this->store($tf, $v['candles']);
            $first = $v['candles'][0]['t'];
            if ($first >= $cursorEnd) break;
            $cursorEnd = $first - 1;
            if ($this->provider->mode() === 'live') sleep(8);
        }
        return $total;
    }

    public function store(string $tf, array $candles): int
    {
        if (!$candles) return 0;
        $sid = $this->sourceId();
        $now = gmdate('Y-m-d H:i:s');
        return Database::transaction(function () use ($candles, $tf, $sid, $now) {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO market_candles (source_id, symbol, timeframe, open_time, open, high, low, close, volume, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE open=VALUES(open), high=VALUES(high), low=VALUES(low), close=VALUES(close),
                                         volume=VALUES(volume), updated_at=VALUES(updated_at)'
            );
            $n = 0;
            foreach ($candles as $c) {
                $stmt->execute([$sid, $this->symbol, $tf, gmdate('Y-m-d H:i:s', $c['t']),
                    $c['o'], $c['h'], $c['l'], $c['c'], $c['v'], $now, $now]);
                $n++;
            }
            return $n;
        });
    }

    /** Candles from DB, oldest -> newest. $untilTs filters by open time (inclusive). */
    public function candles(string $tf, int $limit = 500, ?int $untilTs = null, ?int $fromTs = null): array
    {
        $params = [$this->sourceId(), $this->symbol, $tf];
        $where = 'source_id = ? AND symbol = ? AND timeframe = ?';
        if ($untilTs !== null) { $where .= ' AND open_time <= ?'; $params[] = gmdate('Y-m-d H:i:s', $untilTs); }
        if ($fromTs !== null) { $where .= ' AND open_time >= ?'; $params[] = gmdate('Y-m-d H:i:s', $fromTs); }
        $order = $fromTs !== null ? 'ASC' : 'DESC';
        $rows = Database::fetchAll(
            "SELECT open_time, open, high, low, close, volume FROM market_candles WHERE $where ORDER BY open_time $order LIMIT " . (int)$limit,
            $params
        );
        if ($order === 'DESC') $rows = array_reverse($rows);
        return array_map(static fn($r) => [
            't' => (int)strtotime($r['open_time'] . ' UTC'), 'o' => (float)$r['open'], 'h' => (float)$r['high'],
            'l' => (float)$r['low'], 'c' => (float)$r['close'], 'v' => $r['volume'] === null ? null : (float)$r['volume'],
        ], $rows);
    }

    /** Cached quote (protects provider quota). Never fabricates: returns available=false on failure. */
    public function quote(bool $force = false): array
    {
        $key = 'quote.' . $this->provider->code() . '.' . $this->symbol;
        $cached = Settings::state($key);
        $ttl = (int)Settings::get('market.quote_cache_seconds', 60);
        if (!$force && is_array($cached) && time() - (int)$cached['fetched_at'] < $ttl) {
            return $cached + ['cached' => true];
        }
        try {
            $q = $this->provider->fetchQuote();
            $q['fetched_at'] = time();
            $q['available'] = true;
            $q['source'] = $this->provider->label();
            $q['mode'] = $this->provider->mode();
            $q['symbol'] = $this->symbol;
            Settings::setState($key, $q);
            $this->markHealth(true);
            return $q + ['cached' => false];
        } catch (\Throwable $e) {
            $this->markHealth(false, $e->getMessage());
            if (is_array($cached)) {
                return $cached + ['cached' => true, 'stale' => true, 'error' => $e->getMessage()];
            }
            return ['available' => false, 'error' => $e->getMessage(), 'source' => $this->provider->label(), 'mode' => $this->provider->mode(), 'symbol' => $this->symbol];
        }
    }

    /** Market trading hours: 24/7 for BTCUSD; Sun 18:00 -> Fri 17:00 NY (daily break 17:00-18:00 NY) for XAUUSD. */
    public static function marketStatus(?int $now = null, string $symbol = 'XAUUSD'): array
    {
        $now = $now ?? time();
        $meta = self::symbolMeta($symbol);
        $ny = new \DateTime('@' . $now);
        $ny->setTimezone(new \DateTimeZone('America/New_York'));
        if (!empty($meta['is_24_7'])) {
            return ['open' => true, 'reason' => '24/7 Crypto Market Open', 'ny_time' => $ny->format('D H:i')];
        }
        $provCode = strtolower((string)Env::get('MARKET_PROVIDER', 'yahoo'));
        if ($provCode === 'yahoo' || $provCode === 'live') {
            return ['open' => true, 'reason' => '24/7 Spot Gold Live Feed', 'ny_time' => $ny->format('D H:i')];
        }
        $dow = (int)$ny->format('w');
        $hm = (int)$ny->format('Hi');
        $open = true;
        $reason = 'Market open';
        if ($dow === 6 || ($dow === 5 && $hm >= 1700) || ($dow === 0 && $hm < 1800)) {
            $open = false;
            $reason = 'Weekend close';
        } elseif ($hm >= 1700 && $hm < 1800) {
            $open = false;
            $reason = 'Daily maintenance break (17:00-18:00 New York)';
        }
        return ['open' => $open, 'reason' => $reason, 'ny_time' => $ny->format('D H:i')];
    }

    /** Freshness of the last stored candle per timeframe. */
    public function freshness(array $tfs): array
    {
        $out = [];
        $mult = (int)Settings::get('market.stale_multiplier', 3);
        $status = self::marketStatus(null, $this->symbol);
        foreach ($tfs as $tf) {
            $last = Database::value(
                'SELECT MAX(open_time) FROM market_candles WHERE source_id = ? AND symbol = ? AND timeframe = ?',
                [$this->sourceId(), $this->symbol, $tf]
            );
            $lastTs = $last ? (int)strtotime($last . ' UTC') : null;
            $age = $lastTs ? time() - Timeframe::closeTime($tf, $lastTs) : null;
            $limit = max(Timeframe::seconds($tf) * $mult, 900);
            $stale = $lastTs === null || ($status['open'] && $age > $limit && Timeframe::seconds($tf) < 86400);
            $out[$tf] = ['last_open' => $lastTs, 'age_seconds' => $age, 'stale' => $stale];
        }
        return $out;
    }

    private function markHealth(bool $ok, ?string $error = null): void
    {
        try {
            $now = gmdate('Y-m-d H:i:s');
            if ($ok) {
                Database::update('market_data_sources', ['last_success_at' => $now, 'updated_at' => $now], 'id = ?', [$this->sourceId()]);
            } else {
                Database::update('market_data_sources', [
                    'last_error_at' => $now, 'last_error' => mb_substr((string)$error, 0, 500), 'updated_at' => $now,
                ], 'id = ?', [$this->sourceId()]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
