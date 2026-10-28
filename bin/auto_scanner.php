<?php
declare(strict_types=1);

/**
 * Continuous Background Auto-Scanner & Telegram Dispatcher Daemon.
 * Scans XAUUSD and BTCUSD every 20 seconds and automatically sends
 * qualified BUY/SELL signals to configured Telegram accounts (@i_am_Theaa_Rithhh).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Analysis\AnalysisService;
use App\Market\MarketDataService;

echo sprintf("[%s UTC] Starting GOLD SMC AI PRO Auto-Scanner Daemon (XAUUSD & BTCUSD -> Telegram)...\n", gmdate('Y-m-d H:i:s'));

while (true) {
    foreach (MarketDataService::SUPPORTED_SYMBOLS as $sym) {
        try {
            $service = new AnalysisService(null, null, null, $sym);
            $res = $service->run(false);
            $sig = $res['signal'] ?? [];
            $dir = $sig['direction'] ?? 'WAIT';
            echo sprintf(
                "[%s UTC] %s: $%.2f | %s (%d/100)\n",
                gmdate('H:i:s'),
                $sym,
                (float)($res['price'] ?? 0),
                $sig['label'] ?? $dir,
                (int)($sig['quality_score'] ?? 0)
            );
        } catch (\Throwable $e) {
            echo sprintf("[%s UTC] %s scan warning: %s\n", gmdate('H:i:s'), $sym, $e->getMessage());
        }
    }
    sleep(20);
}
