<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Multi-channel notification dispatcher (In-App, Telegram Bot API, Email)
 * with automatic @username -> chat_id resolution via Telegram getUpdates,
 * deduplication, and per-channel cooldowns.
 */
final class Notifier
{
    public static function botToken(): string
    {
        $fromSettings = trim((string)Settings::get('notify.telegram_bot_token', ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }
        return trim((string)Env::get('TELEGRAM_BOT_TOKEN', ''));
    }

    /**
     * Resolve a Telegram @username (e.g. @i_am_Theaa_Rithhh) to a numeric chat_id
     * by querying the bot's recent /start messages via getUpdates.
     */
    public static function resolveTelegramChatId(string $botToken, string $chatIdOrUsername, ?int $userId = null): string
    {
        $clean = trim($chatIdOrUsername);
        if ($clean === '') {
            return '';
        }
        // Already a numeric chat_id (e.g. 123456789 or -100123456789)
        if (preg_match('/^-?\d+$/', $clean)) {
            return $clean;
        }

        $targetUsername = strtolower(ltrim($clean, '@'));
        try {
            $res = Http::get(
                "https://api.telegram.org/bot{$botToken}/getUpdates",
                ['limit' => 100],
                [],
                ['timeout' => 8, 'retries' => 1]
            );
            $updates = $res['json']['result'] ?? [];
            $fallbackChatId = null;

            if (is_array($updates)) {
                foreach (array_reverse($updates) as $u) {
                    $msg = $u['message'] ?? $u['edited_message'] ?? $u['channel_post'] ?? $u['my_chat_member'] ?? null;
                    if (!is_array($msg)) {
                        continue;
                    }
                    $chat = $msg['chat'] ?? [];
                    $from = $msg['from'] ?? [];
                    $cid = isset($chat['id']) ? (string)$chat['id'] : '';
                    if ($cid === '') {
                        continue;
                    }
                    if ($fallbackChatId === null) {
                        $fallbackChatId = $cid;
                    }
                    $u1 = strtolower((string)($from['username'] ?? ''));
                    $u2 = strtolower((string)($chat['username'] ?? ''));
                    if ($u1 === $targetUsername || $u2 === $targetUsername) {
                        if ($userId !== null && $userId > 0) {
                            Database::update('user_settings', [
                                'telegram_chat_id' => $cid,
                                'updated_at'       => gmdate('Y-m-d H:i:s'),
                            ], 'user_id = ?', [$userId]);
                        }
                        return $cid;
                    }
                }
            }

            // If only one private user started the bot, use their chat_id as fallback
            if ($fallbackChatId !== null) {
                if ($userId !== null && $userId > 0) {
                    Database::update('user_settings', [
                        'telegram_chat_id' => $fallbackChatId,
                        'updated_at'       => gmdate('Y-m-d H:i:s'),
                    ], 'user_id = ?', [$userId]);
                }
                return $fallbackChatId;
            }
        } catch (\Throwable $e) {
            Logger::warning('Telegram getUpdates resolution failed', ['err' => $e->getMessage()]);
        }

        return '@' . $targetUsername;
    }

    /**
     * Notify eligible active users of a newly qualified BUY/SELL trading signal.
     */
    public static function notifySignal(array $signal, int $signalId, bool $force = false): int
    {
        if (!in_array($signal['direction'] ?? 'WAIT', ['BUY', 'SELL'], true)) {
            return 0;
        }
        if (($signal['data_mode'] ?? 'live') === 'demo' && !$force) {
            return 0;
        }
        $score = (int)($signal['quality_score'] ?? 0);
        $setupStatus = strtolower((string)($signal['setup_status'] ?? ''));
        if (!$force && ($score < 85 || !in_array($setupStatus, ['confirmed', 'triggered'], true))) {
            return 0;
        }
        // Timeliness check: if market price has already reached or passed TP1, the setup has already completed!
        $tp1 = (float)($signal['tp1'] ?? 0);
        $curPrice = (float)($signal['price'] ?? $signal['price_at_signal'] ?? 0);
        $isBuy = ($signal['direction'] === 'BUY');
        if (!$force && $tp1 > 0 && $curPrice > 0) {
            if ($isBuy ? $curPrice >= $tp1 : $curPrice <= $tp1) {
                return 0;
            }
        }
        $asset = (string)($signal['asset'] ?? $signal['symbol'] ?? 'XAUUSD');
        $modeTag = ($signal['data_mode'] ?? 'live') === 'demo' ? '[DEMO] ' : '';
        $emoji = ($signal['direction'] === 'BUY') ? '🟢' : '🔴';

        $title = sprintf(
            '🚨 %s %s%s — PREPARE FOR MARKET ENTRY (%s · %d/100 CONFIRMED) @ $%.2f',
            $emoji,
            $modeTag,
            $asset,
            $signal['label'] ?? $signal['direction'],
            $score,
            (float)($signal['entry'] ?? $signal['price'] ?? 0)
        );
        $tpParts = [];
        foreach ([1, 2, 3] as $i) {
            if (!empty($signal['tp' . $i])) {
                $tpParts[] = sprintf('TP%d: $%.2f (%sR)', $i, (float)$signal['tp' . $i], (string)($signal['rr' . $i] ?? '—'));
            }
        }
        $message = sprintf(
            "⚡ Readiness: Score ≥ 85 Reached (%d/100 · 8/8 Confirmed) — Prepare for Market Entry!\n📊 Symbol: %s (%s · CONFIRMED ✅)\n🎯 Entry Zone: $%.2f – $%.2f\n📍 Ref Entry: $%.2f\n🛑 Stop Loss: $%.2f\n✅ Targets: %s\n🧠 Setup: %s\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
            $score,
            $asset,
            $signal['entry_tf'] ?? 'M15',
            (float)($signal['entry_low'] ?? 0),
            (float)($signal['entry_high'] ?? 0),
            (float)($signal['entry'] ?? 0),
            (float)($signal['sl'] ?? $signal['stop_loss'] ?? 0),
            $tpParts ? implode(' | ', $tpParts) : 'n/a',
            $signal['setup_type'] ?? 'SMC Setup',
            gmdate('Y-m-d H:i:s')
        );

        $entryZoneKey = sprintf(
            '%.1f_%.1f',
            (float)($signal['entry_low'] ?? $signal['entry'] ?? 0),
            (float)($signal['entry_high'] ?? $signal['entry'] ?? 0)
        );
        $dedupeKey = sprintf(
            'sig:%s:%s:%s:%s:%s',
            $asset,
            $signal['data_mode'] ?? 'live',
            $signal['entry_tf'] ?? 'M15',
            $signal['direction'],
            $entryZoneKey
        );
        if ($force) {
            $dedupeKey .= ':manual:' . time();
        }

        $users = Database::fetchAll(
            'SELECT u.id, u.email, u.display_name, s.notify_inapp, s.notify_email, s.notify_telegram,
                    s.telegram_chat_id, s.notify_events, s.min_alert_score
             FROM users u
             INNER JOIN user_settings s ON s.user_id = u.id
             WHERE u.is_active = 1'
        );

        $sentCount = 0;
        foreach ($users as $u) {
            $minScore = max(85, (int)$u['min_alert_score']);
            if (!$force && $score < $minScore) {
                continue;
            }
            $events = $u['notify_events'] ? json_decode((string)$u['notify_events'], true) : null;
            if (!$force && is_array($events) && isset($events['signal']) && !$events['signal']) {
                continue;
            }
            $sentCount += self::dispatchToUser($u, 'signal', $title, $message, $dedupeKey, $signalId, !$force);
        }
        return $sentCount;
    }

    /**
     * Notify eligible active users to prepare in advance when confirmation is nearing completion (6/8 or 7/8 confluences met).
     */
    public static function notifyPrepareEntry(array $signal, ?int $signalId = null, bool $force = false): int
    {
        if (($signal['data_mode'] ?? 'live') === 'demo' && !$force) {
            return 0;
        }
        $readiness = (array)($signal['readiness'] ?? []);
        $stage = (string)($readiness['stage'] ?? '');
        if (!$force && $stage !== 'nearing_confirmation') {
            return 0;
        }
        $entry = (float)($signal['entry'] ?? $signal['entry_price'] ?? 0);
        $sl = (float)($signal['sl'] ?? $signal['stop_loss'] ?? 0);
        if ($entry <= 0 || $sl <= 0) {
            return 0;
        }
        $dir = (string)($readiness['direction'] ?? $signal['watch']['direction'] ?? $signal['direction'] ?? 'SELL');
        $asset = (string)($signal['asset'] ?? $signal['symbol'] ?? 'XAUUSD');
        $tf = (string)($signal['entry_tf'] ?? 'M15');
        // Timeliness check: if market price has already reached or passed TP1, do not notify prepare entry
        $tp1 = (float)($signal['tp1'] ?? 0);
        $curPrice = (float)($signal['price'] ?? $signal['price_at_signal'] ?? 0);
        $isBuy = ($dir === 'BUY');
        if (!$force && $tp1 > 0 && $curPrice > 0) {
            if ($isBuy ? $curPrice >= $tp1 : $curPrice <= $tp1) {
                return 0;
            }
        }
        $metCount = (int)($readiness['met_count'] ?? 7);
        $totalCount = (int)($readiness['total_count'] ?? 8);
        $score = (int)($signal['quality_score'] ?? $readiness['score'] ?? 75);
        $missing = !empty($readiness['missing_labels'])
            ? implode(', ', (array)$readiness['missing_labels'])
            : 'Final candle confirmation';

        $tpParts = [];
        foreach ([1, 2] as $i) {
            if (!empty($signal['tp' . $i])) {
                $tpParts[] = sprintf('TP%d: $%.2f (%sR)', $i, (float)$signal['tp' . $i], (string)($signal['rr' . $i] ?? '—'));
            }
        }
        $title = sprintf(
            '⏳ %s — PREPARE FOR %s MARKET ENTRY (%d/%d Confirmed · %d/100)',
            $asset,
            $dir,
            $metCount,
            $totalCount,
            $score
        );
        $message = sprintf(
            "⚡ Status: Confirmation Nearing Completion (%d/%d Confluences Met)\n📊 Symbol: %s (%s · %s Setup Forming)\n🎯 Projected Entry Zone: $%.2f – $%.2f (Ref: $%.2f)\n🛑 Projected Stop Loss: $%.2f\n✅ Projected Targets: %s\n⭐ Current Score: %d/100\n🔍 Awaiting: %s\n💡 Action: Prepare your order & lot size in advance!\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
            $metCount,
            $totalCount,
            $asset,
            $tf,
            $dir,
            (float)($signal['entry_low'] ?? $entry),
            (float)($signal['entry_high'] ?? $entry),
            $entry,
            $sl,
            $tpParts ? implode(' | ', $tpParts) : 'n/a',
            $score,
            $missing,
            gmdate('Y-m-d H:i:s')
        );

        $entryZoneKey = sprintf(
            '%.1f_%.1f',
            (float)($signal['entry_low'] ?? $entry),
            (float)($signal['entry_high'] ?? $entry)
        );
        $dedupeKey = sprintf(
            'prep:%s:%s:%s:%s:%s',
            $asset,
            $signal['data_mode'] ?? 'live',
            $tf,
            $dir,
            $entryZoneKey
        );
        if ($force) {
            $dedupeKey .= ':manual:' . time();
        }

        $users = Database::fetchAll(
            'SELECT u.id, u.email, u.display_name, s.notify_inapp, s.notify_email, s.notify_telegram,
                    s.telegram_chat_id, s.notify_events, s.min_alert_score
             FROM users u
             INNER JOIN user_settings s ON s.user_id = u.id
             WHERE u.is_active = 1'
        );

        $sentCount = 0;
        foreach ($users as $u) {
            $events = $u['notify_events'] ? json_decode((string)$u['notify_events'], true) : null;
            if (!$force && is_array($events) && array_key_exists('prepare', $events) && !$events['prepare']) {
                continue;
            }
            $sentCount += self::dispatchToUser($u, 'prepare', $title, $message, $dedupeKey, $signalId, !$force);
        }
        return $sentCount;
    }

    /**
     * Notify eligible active users when an open/confirmed 100-score signal hits TP1, TP2, TP3, Breakeven, or Stop Loss (SL).
     */
    public static function notifyTradeEvent(array $sig, string $eventType, ?float $price, array $meta = []): int
    {
        $allowedEvents = !empty($meta['test'])
            ? ['entry_filled', 'tp1_hit', 'tp2_hit', 'tp3_hit', 'sl_hit', 'invalidated']
            : ['tp1_hit', 'tp2_hit', 'tp3_hit', 'sl_hit'];
        if (!in_array($eventType, $allowedEvents, true)) {
            return 0;
        }
        if (($sig['data_mode'] ?? 'live') === 'demo' && empty($meta['test'])) {
            return 0;
        }
        $sigId = (int)($sig['id'] ?? 0);
        $sigScore = (int)($sig['quality_score'] ?? 0);
        if (empty($meta['test'])) {
            if ($sigScore < 85) {
                return 0;
            }
            $wasSignaled = Database::value(
                "SELECT id FROM notification_logs WHERE signal_id = ? AND event_type = 'signal' AND status = 'sent' LIMIT 1",
                [$sigId]
            );
            if (!$wasSignaled) {
                return 0;
            }
        }
        $asset = (string)($sig['symbol'] ?? $sig['asset'] ?? 'XAUUSD');
        $tf = (string)($sig['entry_tf'] ?? 'M15');
        $dir = (string)($sig['direction'] ?? 'SELL');
        $label = (string)($sig['signal_label'] ?? ($dir . ' SETUP'));
        $entry = (float)($sig['entry_price'] ?? $sig['entry'] ?? 0);
        $sl = (float)($sig['stop_loss'] ?? $sig['sl'] ?? 0);
        $tp1 = isset($sig['tp1']) && $sig['tp1'] !== null ? (float)$sig['tp1'] : null;
        $tp2 = isset($sig['tp2']) && $sig['tp2'] !== null ? (float)$sig['tp2'] : null;
        $hitPrice = (float)($price ?? $entry);
        $modeTag = ($sig['data_mode'] ?? 'live') === 'demo' ? '[DEMO] ' : '';

        if ($eventType === 'tp1_hit') {
            $rr = isset($meta['rr']) ? sprintf('+%.2fR', (float)$meta['rr']) : '+TP1';
            $title = sprintf('✅ %s%s — TP1 HIT (%s) @ $%.2f', $modeTag, $asset, $rr, $hitPrice);
            $nextLine = $tp2 !== null ? sprintf("\n🎯 Next Target (TP2): $%.2f (%sR)", $tp2, (string)($sig['rr2'] ?? '—')) : '';
            $message = sprintf(
                "📊 Signal #%d: %s (%s)\n📍 Entry Price: $%.2f\n✅ TP1 Reached: $%.2f (%s)\n🛡️ Protection: Stop Loss moved to Breakeven ($%.2f)%s\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                $sigId, $label, $tf, $entry, $hitPrice, $rr, $entry, $nextLine, gmdate('Y-m-d H:i:s')
            );
        } elseif ($eventType === 'tp2_hit') {
            $rr = isset($meta['rr']) ? sprintf('+%.2fR', (float)$meta['rr']) : '+TP2';
            $finalR = isset($meta['final_r']) ? sprintf('+%.2fR', (float)$meta['final_r']) : $rr;
            $title = sprintf('🎯 %s%s — TP2 TARGET HIT (%s) @ $%.2f', $modeTag, $asset, $rr, $hitPrice);
            $message = sprintf(
                "📊 Signal #%d: %s (%s)\n📍 Entry Price: $%.2f\n🏆 TP2 Reached: $%.2f (%s)\n💰 Net Realized: %s (WIN ✅)\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                $sigId, $label, $tf, $entry, $hitPrice, $rr, $finalR, gmdate('Y-m-d H:i:s')
            );
        } elseif ($eventType === 'tp3_hit') {
            $rr = isset($meta['rr']) ? sprintf('+%.2fR', (float)$meta['rr']) : '+TP3';
            $finalR = isset($meta['final_r']) ? sprintf('+%.2fR', (float)$meta['final_r']) : $rr;
            $title = sprintf('🏆 %s%s — TP3 FINAL TARGET HIT (%s) @ $%.2f', $modeTag, $asset, $rr, $hitPrice);
            $message = sprintf(
                "📊 Signal #%d: %s (%s)\n📍 Entry Price: $%.2f\n🏆 TP3 Reached: $%.2f (%s)\n💰 Net Realized: %s (FULL WIN 🏆)\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                $sigId, $label, $tf, $entry, $hitPrice, $rr, $finalR, gmdate('Y-m-d H:i:s')
            );
        } elseif ($eventType === 'sl_hit') {
            $isBe = !empty($meta['is_be']);
            $finalR = (float)($meta['final_r'] ?? ($isBe ? 0.0 : -1.0));
            $rStr = ($finalR >= 0 ? '+' : '') . sprintf('%.2fR', $finalR);
            if ($isBe) {
                $title = sprintf('🛡️ %s%s — BREAKEVEN STOP AFTER TP1 (%s) @ $%.2f', $modeTag, $asset, $rStr, $hitPrice);
                $message = sprintf(
                    "📊 Signal #%d: %s (%s)\n📍 Entry Price: $%.2f\n🛡️ Exit Price: $%.2f (Breakeven Stop after TP1)\n💰 Net Realized: %s (Protected Profit)\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                    $sigId, $label, $tf, $entry, $hitPrice, $rStr, gmdate('Y-m-d H:i:s')
                );
            } else {
                $title = sprintf('🛑 %s%s — STOP LOSS HIT (%s) @ $%.2f', $modeTag, $asset, $rStr, $hitPrice);
                $message = sprintf(
                    "📊 Signal #%d: %s (%s)\n📍 Entry Price: $%.2f\n🛑 Stop Loss Hit: $%.2f (%s)\n📋 Status: Closed at Stop Loss\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                    $sigId, $label, $tf, $entry, $hitPrice, $rStr, gmdate('Y-m-d H:i:s')
                );
            }
        } elseif ($eventType === 'entry_filled') {
            $title = sprintf('📍 %s%s — ENTRY TRIGGERED @ $%.2f', $modeTag, $asset, $hitPrice);
            $tpLine = $tp1 !== null ? sprintf('TP1: $%.2f', $tp1) . ($tp2 !== null ? sprintf(' | TP2: $%.2f', $tp2) : '') : 'n/a';
            $message = sprintf(
                "📊 Signal #%d: %s (%s)\n📍 Entry Filled: $%.2f\n🛑 Stop Loss: $%.2f\n✅ Targets: %s\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                $sigId, $label, $tf, $hitPrice, $sl, $tpLine, gmdate('Y-m-d H:i:s')
            );
        } else {
            // invalidated before fill
            $title = sprintf('⚠️ %s%s — SETUP INVALIDATED @ $%.2f', $modeTag, $asset, $hitPrice);
            $message = sprintf(
                "📊 Signal #%d: %s (%s)\n⚠️ Reason: Stop Loss ($%.2f) breached before entry fill.\n🕒 Time: %s UTC (@i_am_Theaa_Rithhh)",
                $sigId, $label, $tf, $sl, gmdate('Y-m-d H:i:s')
            );
        }

        $dedupeKey = !empty($meta['test'])
            ? sprintf('trade_ev:test:%d:%s:%d', $sigId, $eventType, time())
            : sprintf('trade_ev:%s:%s:%s:%.1f:%s', $asset, $dir, $tf, $entry, $eventType);
        $users = Database::fetchAll(
            'SELECT u.id, u.email, u.display_name, s.notify_inapp, s.notify_email, s.notify_telegram,
                    s.telegram_chat_id, s.notify_events, s.min_alert_score
             FROM users u
             INNER JOIN user_settings s ON s.user_id = u.id
             WHERE u.is_active = 1'
        );

        $sent = 0;
        foreach ($users as $u) {
            $events = $u['notify_events'] ? json_decode((string)$u['notify_events'], true) : null;
            if (is_array($events) && array_key_exists('tp_sl', $events) && !$events['tp_sl']) {
                continue;
            }
            $sent += self::dispatchToUser($u, $eventType, $title, $message, $dedupeKey, $sigId, empty($meta['test']));
        }
        return $sent;
    }

    /**
     * Broadcast an event notification (e.g. high-impact news warning, structure shift) to opted-in users.
     */
    public static function broadcastEvent(string $eventType, string $title, string $message, string $dedupeKey, ?int $signalId = null): int
    {
        $users = Database::fetchAll(
            'SELECT u.id, u.email, u.display_name, s.notify_inapp, s.notify_email, s.notify_telegram,
                    s.telegram_chat_id, s.notify_events, s.min_alert_score
             FROM users u
             INNER JOIN user_settings s ON s.user_id = u.id
             WHERE u.is_active = 1'
        );
        $sent = 0;
        foreach ($users as $u) {
            $events = $u['notify_events'] ? json_decode((string)$u['notify_events'], true) : null;
            if (is_array($events) && array_key_exists($eventType, $events) && !$events[$eventType]) {
                continue;
            }
            $sent += self::dispatchToUser($u, $eventType, $title, $message, $dedupeKey, $signalId, true);
        }
        return $sent;
    }

    /**
     * Send a test notification for a specific user and channel ('inapp'|'telegram'|'email').
     * @return array{ok:bool, channel:string, error:?string}
     */
    public static function sendTest(int $userId, string $channel): array
    {
        $u = Database::fetch(
            'SELECT u.id, u.email, u.display_name, s.telegram_chat_id
             FROM users u
             LEFT JOIN user_settings s ON s.user_id = u.id
             WHERE u.id = ?',
            [$userId]
        );
        if (!$u) {
            return ['ok' => false, 'channel' => $channel, 'error' => 'User not found'];
        }
        $title = '🚨 GOLD SMC AI PRO — Telegram Alert Connected';
        $message = "Account: @i_am_Theaa_Rithhh\nStatus: Active ✅\nXAUUSD & BTCUSD qualified BUY/SELL SMC signals will be delivered automatically to this Telegram chat.\nTime: " . gmdate('Y-m-d H:i:s') . ' UTC';
        $dedupe = 'test:' . $userId . ':' . $channel . ':' . time();

        return self::sendChannel((int)$u['id'], $channel, 'test', $title, $message, $dedupe, null, $u, false);
    }

    private static function dispatchToUser(
        array $u,
        string $eventType,
        string $title,
        string $message,
        string $dedupeKey,
        ?int $signalId,
        bool $applyCooldown = true
    ): int {
        $sent = 0;
        $uid = (int)$u['id'];
        if ((int)$u['notify_inapp'] === 1) {
            $r = self::sendChannel($uid, 'inapp', $eventType, $title, $message, $dedupeKey, $signalId, $u, $applyCooldown);
            if ($r['ok']) $sent++;
        }
        if ((int)$u['notify_telegram'] === 1) {
            $r = self::sendChannel($uid, 'telegram', $eventType, $title, $message, $dedupeKey, $signalId, $u, $applyCooldown);
            if ($r['ok']) $sent++;
        }
        if ((int)$u['notify_email'] === 1) {
            $r = self::sendChannel($uid, 'email', $eventType, $title, $message, $dedupeKey, $signalId, $u, $applyCooldown);
            if ($r['ok']) $sent++;
        }
        return $sent;
    }

    private static function sendChannel(
        int $userId,
        string $channel,
        string $eventType,
        string $title,
        string $message,
        string $dedupeKey,
        ?int $signalId,
        array $userRow,
        bool $applyCooldown
    ): array {
        $cooldownMin = (int)Settings::get('notify.cooldown_minutes', 30);
        if ($applyCooldown) {
            // 1. Permanent deduplication: never send the exact same setup/trade dedupe_key twice
            $existsEver = Database::value(
                'SELECT id FROM notification_logs
                 WHERE user_id = ? AND channel = ? AND dedupe_key = ? AND status = ?
                 LIMIT 1',
                [$userId, $channel, $dedupeKey, 'sent']
            );
            if ($existsEver) {
                return ['ok' => false, 'channel' => $channel, 'error' => 'Duplicate setup suppressed'];
            }
            // 2. If signal_id was already notified for this eventType, never send again
            if ($signalId !== null && $signalId > 0) {
                $existsSig = Database::value(
                    'SELECT id FROM notification_logs
                     WHERE user_id = ? AND channel = ? AND signal_id = ? AND event_type = ? AND status = ?
                     LIMIT 1',
                    [$userId, $channel, $signalId, $eventType, 'sent']
                );
                if ($existsSig) {
                    return ['ok' => false, 'channel' => $channel, 'error' => 'Signal event already notified'];
                }
            }
            // 3. Cooldown window for same event_type on same channel to prevent burst spam
            if (in_array($eventType, ['signal', 'prepare'], true)) {
                $cutoff = gmdate('Y-m-d H:i:s', time() - $cooldownMin * 60);
                $symbolPrefix = implode(':', array_slice(explode(':', $dedupeKey), 0, 5)) . ':%';
                $recentSymbolSig = Database::value(
                    'SELECT id FROM notification_logs
                     WHERE user_id = ? AND channel = ? AND event_type = ? AND dedupe_key LIKE ? AND status = ? AND created_at >= ?
                     LIMIT 1',
                    [$userId, $channel, $eventType, $symbolPrefix, 'sent', $cutoff]
                );
                if ($recentSymbolSig) {
                    return ['ok' => false, 'channel' => $channel, 'error' => 'Symbol cooldown active'];
                }
            }
        }

        $status = 'sent';
        $error = null;

        if ($channel === 'inapp') {
            $status = 'sent';
        } elseif ($channel === 'telegram') {
            $botToken = self::botToken();
            $rawChatId = trim((string)($userRow['telegram_chat_id'] ?? '@i_am_Theaa_Rithhh'));
            if ($botToken === '') {
                $status = 'skipped';
                $error = 'Telegram Bot Token is not set yet. Open Settings -> paste your @BotFather token and click Save Settings.';
            } elseif ($rawChatId === '') {
                $status = 'skipped';
                $error = 'Telegram username / chat_id not set.';
            } else {
                $resolvedChatId = self::resolveTelegramChatId($botToken, $rawChatId, $userId);

                $tgText = $title . "\n\n" . $message;
                if ($eventType === 'signal' && $signalId !== null && $signalId > 0) {
                    $sigRow = Database::fetch('SELECT * FROM trading_signals WHERE id = ?', [$signalId]);
                    if ($sigRow) {
                        $sym = (string)$sigRow['symbol'];
                        $sDir = (string)$sigRow['direction'];
                        $sLow = (float)($sigRow['entry_low'] ?? $sigRow['entry_price']);
                        $sHigh = (float)($sigRow['entry_high'] ?? $sigRow['entry_price']);
                        $sEntry = (abs($sLow - $sHigh) < 0.01 || $sLow <= 0)
                            ? sprintf('$%.2f', (float)$sigRow['entry_price'])
                            : sprintf('$%.2f – $%.2f', min($sLow, $sHigh), max($sLow, $sHigh));
                        $sSl = sprintf('$%.2f', (float)$sigRow['stop_loss']);
                        $sTps = [];
                        if (!empty($sigRow['tp1'])) $sTps[] = sprintf('🎯 TP1: $%.2f', (float)$sigRow['tp1']);
                        if (!empty($sigRow['tp2'])) $sTps[] = sprintf('🎯 TP2: $%.2f', (float)$sigRow['tp2']);
                        if (!empty($sigRow['tp3'])) $sTps[] = sprintf('🎯 TP3: $%.2f', (float)$sigRow['tp3']);

                        $tgText = sprintf("📊 %s · %s\n📍 Entry: %s\n🛑 SL: %s\n%s", $sym, $sDir, $sEntry, $sSl, implode("\n", $sTps));
                    }
                }

                $res = Http::postJson(
                    "https://api.telegram.org/bot{$botToken}/sendMessage",
                    [
                        'chat_id'                  => $resolvedChatId,
                        'text'                     => $tgText,
                        'disable_web_page_preview' => true,
                    ],
                    [],
                    ['timeout' => 10, 'retries' => 1]
                );
                if ($res['error'] || $res['status'] >= 400 || empty($res['json']['ok'])) {
                    $status = 'failed';
                    $desc = $res['json']['description'] ?? ($res['error'] ?: ('HTTP ' . $res['status']));
                    if (stripos($desc, 'chat not found') !== false) {
                        $error = "Telegram chat not found for {$rawChatId}. Please open your Telegram bot from @i_am_Theaa_Rithhh and press START (/start), then try again.";
                    } else {
                        $error = $desc;
                    }
                }
            }
        } elseif ($channel === 'email') {
            $email = trim((string)($userRow['email'] ?? ''));
            $from = trim((string)Env::get('MAIL_FROM', 'alerts@example.com'));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'skipped';
                $error = 'Invalid recipient email';
            } else {
                $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
                $ok = @mail($email, $title, $message, $headers);
                if (!$ok) {
                    $status = 'failed';
                    $error = 'PHP mail() returned false (verify SMTP/sendmail in php.ini)';
                }
            }
        } else {
            return ['ok' => false, 'channel' => $channel, 'error' => 'Unsupported channel'];
        }

        Database::insert('notification_logs', [
            'user_id'    => $userId,
            'channel'    => $channel,
            'event_type' => mb_substr($eventType, 0, 32),
            'title'      => mb_substr($title, 0, 200),
            'message'    => $message,
            'signal_id'  => $signalId,
            'dedupe_key' => mb_substr($dedupeKey, 0, 128),
            'status'     => $status,
            'error'      => $error ? mb_substr($error, 0, 500) : null,
            'is_read'    => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['ok' => $status === 'sent', 'channel' => $channel, 'error' => $error];
    }
}
