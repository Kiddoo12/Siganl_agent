<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Notifier;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;

$user = Response::apiInit('trader', 60);
$uid = (int)$user['id'];

if (Response::method() === 'GET') {
    $uSettings = Database::fetch('SELECT * FROM user_settings WHERE user_id = ?', [$uid]);
    if ($uSettings && $uSettings['notify_events']) {
        $uSettings['notify_events'] = json_decode((string)$uSettings['notify_events'], true);
    }
    $out = [
        'user'          => $user,
        'user_settings' => $uSettings,
    ];
    if (Auth::hasRole('admin')) {
        $out['system_settings'] = Settings::all();
        $out['system_defaults'] = Settings::defaults();
        $out['system_overrides'] = Settings::overrides();
    }
    Response::ok($out);
}

$in = Response::input();
$action = (string)($in['action'] ?? 'user_profile');

if ($action === 'user_profile') {
    try {
        $tz = Validator::timezone((string)($in['timezone'] ?? 'UTC'));
        $theme = Validator::enum((string)($in['theme'] ?? 'dark'), 'theme', ['dark', 'light']);
        $balance = Validator::float($in['account_balance'] ?? 10000, 'account_balance', 10.0, 100000000.0);
        $riskPct = Validator::float($in['risk_percent'] ?? 0.5, 'risk_percent', 0.05, (float)Settings::get('risk.max_risk_pct', 2.0));
        $minScore = Validator::int($in['min_alert_score'] ?? 55, 'min_alert_score', 0, 100);
        $displayName = mb_substr(trim((string)($in['display_name'] ?? $user['display_name'])), 0, 100) ?: $user['email'];
        $chatId = isset($in['telegram_chat_id']) && trim((string)$in['telegram_chat_id']) !== ''
            ? mb_substr(trim((string)$in['telegram_chat_id']), 0, 64)
            : '@i_am_Theaa_Rithhh';
        if (array_key_exists('telegram_bot_token', $in) && Auth::hasRole('admin')) {
            $botTok = trim((string)$in['telegram_bot_token']);
            Settings::set('notify.telegram_bot_token', $botTok, $uid);
        }
        $activeTok = Notifier::botToken();
        if ($activeTok !== '' && $chatId !== null) {
            $chatId = Notifier::resolveTelegramChatId($activeTok, $chatId, $uid);
        }
        $events = is_array($in['notify_events'] ?? null) ? $in['notify_events'] : [
            'signal' => true,
            'news'   => true,
        ];

        $now = gmdate('Y-m-d H:i:s');
        Database::update('users', ['display_name' => $displayName, 'updated_at' => $now], 'id = ?', [$uid]);
        Database::update('user_settings', [
            'timezone'         => $tz,
            'theme'            => $theme,
            'account_balance'  => $balance,
            'risk_percent'     => $riskPct,
            'notify_inapp'     => !empty($in['notify_inapp']) ? 1 : 0,
            'notify_browser'   => !empty($in['notify_browser']) ? 1 : 0,
            'notify_email'     => !empty($in['notify_email']) ? 1 : 0,
            'notify_telegram'  => !empty($in['notify_telegram']) ? 1 : 0,
            'telegram_chat_id' => $chatId,
            'notify_events'    => json_encode($events),
            'min_alert_score'  => $minScore,
            'updated_at'       => $now,
        ], 'user_id = ?', [$uid]);

        Logger::audit('update_user_settings', 'user', $uid, [], $uid);
        Response::ok(['message' => 'Settings saved']);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
}

if ($action === 'change_password') {
    $current = (string)($in['current_password'] ?? '');
    $newPass = (string)($in['new_password'] ?? '');
    if (strlen($newPass) < 10) {
        Response::error('New password must be at least 10 characters.', 422);
    }
    $row = Database::fetch('SELECT password_hash FROM users WHERE id = ?', [$uid]);
    if (!$row || !password_verify($current, $row['password_hash'])) {
        Response::error('Current password is incorrect.', 422);
    }
    Database::update('users', [
        'password_hash' => password_hash($newPass, PASSWORD_DEFAULT),
        'updated_at'    => gmdate('Y-m-d H:i:s'),
    ], 'id = ?', [$uid]);
    Logger::audit('change_password', 'user', $uid, [], $uid);
    Response::ok(['message' => 'Password updated']);
}

if (!Auth::hasRole('admin')) {
    Response::error('Forbidden: administrator role required', 403);
}

if ($action === 'system_setting') {
    $path = trim((string)($in['key'] ?? ''));
    if ($path === '') {
        Response::error('Setting key is required', 422);
    }
    try {
        if (!empty($in['reset'])) {
            Settings::reset($path);
            Logger::audit('reset_system_setting', 'system_settings', $path, [], $uid);
            Response::ok(['reset' => $path, 'value' => Settings::get($path)]);
        }
        Settings::set($path, $in['value'] ?? null, $uid);
        Logger::audit('set_system_setting', 'system_settings', $path, ['value' => $in['value'] ?? null], $uid);
        Response::ok(['key' => $path, 'value' => Settings::get($path)]);
    } catch (\InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    }
}

if ($action === 'user_manage') {
    $sub = (string)($in['sub_action'] ?? '');
    if ($sub === 'create') {
        $res = Auth::register(
            (string)($in['email'] ?? ''),
            (string)($in['password'] ?? ''),
            (string)($in['display_name'] ?? ''),
            (string)($in['role'] ?? 'trader')
        );
        if (!$res['ok']) {
            Response::error($res['error'], 422);
        }
        Response::ok(['user_id' => $res['id']]);
    }
    $targetId = (int)($in['user_id'] ?? 0);
    if ($targetId <= 0) {
        Response::error('Invalid user_id', 422);
    }
    if ($sub === 'update_role') {
        $role = Validator::enum((string)($in['role'] ?? ''), 'role', array_keys(Auth::ROLES));
        if ($targetId === $uid && $role !== 'admin') {
            Response::error('You cannot demote your own admin account.', 422);
        }
        Database::update('users', ['role' => $role, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$targetId]);
        Logger::audit('admin_update_role', 'user', $targetId, ['role' => $role], $uid);
        Response::ok();
    }
    if ($sub === 'toggle_active') {
        if ($targetId === $uid) {
            Response::error('You cannot deactivate your own account.', 422);
        }
        $active = !empty($in['is_active']) ? 1 : 0;
        Database::update('users', ['is_active' => $active, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$targetId]);
        Logger::audit('admin_toggle_active', 'user', $targetId, ['is_active' => $active], $uid);
        Response::ok();
    }
}

Response::error('Unknown action', 400);
