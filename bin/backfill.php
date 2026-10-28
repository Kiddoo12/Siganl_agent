<?php
declare(strict_types=1);

/**
 * CLI historical candle backfill utility.
 * Usage:
 *   php bin/backfill.php [days=30] [timeframe=ALL]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Market\MarketDataService;
use App\Market\Timeframe;

$days = isset($argv[1]) && is_numeric($argv[1]) ? max(1, min(365, (int)$argv[1])) : 30;
$tfArg = isset($argv[2]) ? strtoupper(trim($argv[2])) : 'ALL';
$symArg = isset($argv[3]) ? strtoupper(trim($argv[3])) : 'ALL';

$tfs = ($tfArg !== 'ALL' && Timeframe::valid($tfArg))
    ? [$tfArg]
    : MarketDataService::requiredTimeframes();

$symbols = ($symArg !== 'ALL')
    ? [MarketDataService::normalizeSymbol($symArg)]
    : array_keys(MarketDataService::supportedSymbols());

$toTs = time();
$fromTs = $toTs - $days * 86400;

foreach ($symbols as $sym) {
    $market = new MarketDataService($sym);
    echo sprintf(
        "Backfilling %s (%s) from %s to %s UTC...\n",
        $market->symbol(),
        $market->provider()->label(),
        gmdate('Y-m-d H:i', $fromTs),
        gmdate('Y-m-d H:i', $toTs)
    );

    foreach ($tfs as $tf) {
        $stored = $market->backfill($tf, $fromTs, $toTs);
        echo sprintf("  [%s %s] stored %d candles\n", $sym, $tf, $stored);
    }
}
echo "Backfill complete.\n";
