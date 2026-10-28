<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = Database::fetchAll(
        'SELECT id, symbol, timeframe, image_path, manual_price, broker, chart_tz,
                market_bias, detected_strategy, entry_zone_low, entry_zone_high,
                stop_loss, tp1, tp2, tp3, risk_reward, confidence_score,
                analysis_json, overlay_json, ai_model, created_at
         FROM chart_vision_analyses
         WHERE user_id = ?
         ORDER BY id DESC LIMIT 30',
        [(int)$user['id']]
    );

    $items = [];
    foreach ($rows as $r) {
        $relUrl = '';
        $pos = strpos($r['image_path'], 'storage/uploads/vision');
        if ($pos !== false) {
            $relUrl = substr($r['image_path'], $pos);
        } else {
            $relUrl = 'storage/uploads/vision/' . basename($r['image_path']);
        }

        $items[] = [
            'id'                => (int)$r['id'],
            'symbol'            => $r['symbol'],
            'timeframe'         => $r['timeframe'],
            'image_url'         => $relUrl,
            'market_bias'       => $r['market_bias'],
            'detected_strategy' => $r['detected_strategy'],
            'entry_zone_low'    => $r['entry_zone_low'] !== null ? (float)$r['entry_zone_low'] : null,
            'entry_zone_high'   => $r['entry_zone_high'] !== null ? (float)$r['entry_zone_high'] : null,
            'stop_loss'         => $r['stop_loss'] !== null ? (float)$r['stop_loss'] : null,
            'tp1'               => $r['tp1'] !== null ? (float)$r['tp1'] : null,
            'risk_reward'       => $r['risk_reward'] !== null ? (float)$r['risk_reward'] : null,
            'confidence_score'  => (int)$r['confidence_score'],
            'ai_model'          => $r['ai_model'],
            'report'            => json_decode((string)$r['analysis_json'], true),
            'overlay'           => json_decode((string)$r['overlay_json'], true),
            'created_at'        => $r['created_at'],
        ];
    }

    Response::json(['ok' => true, 'count' => count($items), 'items' => $items, 'analyses' => $items]);
}

if ($method === 'DELETE' || ($method === 'POST' && ($_POST['_method'] ?? '') === 'DELETE')) {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
    if (!Csrf::validate($csrfHeader)) {
        Response::json(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }
    $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
    if ($id <= 0) {
        Response::json(['ok' => false, 'error' => 'Valid ID required.'], 422);
    }

    $deleted = Database::execute('DELETE FROM chart_vision_analyses WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
    Response::json(['ok' => true, 'deleted' => $deleted > 0]);
}

Response::json(['ok' => false, 'error' => 'Method not allowed.'], 405);
