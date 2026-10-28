<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Database;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Economic calendar ingestion & high-impact news filter service.
 * Supports the public weekly ForexFactory JSON export (`faireconomy`) and
 * manual admin/analyst event entries (`manual`). Never fabricates events.
 */
final class CalendarService
{
    public function providerCode(): string
    {
        return strtolower((string)Env::get('CALENDAR_PROVIDER', 'faireconomy'));
    }

    /**
     * Sync events from the configured external provider when due (or forced).
     * @return array{ok:bool, provider:string, imported:int, skipped:bool, error:?string}
     */
    public function sync(bool $force = false): array
    {
        $provider = $this->providerCode();
        if ($provider === 'manual') {
            Settings::setState('calendar.last_sync', ['ts' => time(), 'ok' => true, 'provider' => 'manual']);
            return ['ok' => true, 'provider' => 'manual', 'imported' => 0, 'skipped' => false, 'error' => null];
        }

        $state = Settings::state('calendar.last_sync');
        $cadence = (int)Settings::get('calendar.refresh_minutes', 60) * 60;
        if (!$force && is_array($state) && !empty($state['ok']) && (time() - (int)($state['ts'] ?? 0) < $cadence)) {
            return ['ok' => true, 'provider' => $provider, 'imported' => 0, 'skipped' => true, 'error' => null];
        }

        $url = (string)Env::get('CALENDAR_URL', 'https://nfs.faireconomy.media/ff_calendar_thisweek.json');
        $res = Http::get($url, ['timeout' => 10, 'retries' => 1]);
        if ($res['error'] || !is_array($res['json'])) {
            $err = $res['error'] ?: ('Invalid calendar JSON (HTTP ' . $res['status'] . ')');
            Settings::setState('calendar.last_sync', ['ts' => time(), 'ok' => false, 'provider' => $provider, 'error' => $err]);
            Logger::warning('Calendar sync failed', ['provider' => $provider, 'err' => $err]);
            return ['ok' => false, 'provider' => $provider, 'imported' => 0, 'skipped' => false, 'error' => $err];
        }

        $imported = 0;
        $now = gmdate('Y-m-d H:i:s');
        try {
            $imported = Database::transaction(function () use ($res, $provider, $url, $now) {
                $stmt = Database::pdo()->prepare(
                    'INSERT INTO economic_events
                     (source, external_id, title, country, impact, event_time, all_day, forecast, previous, actual, source_url, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       title=VALUES(title), impact=VALUES(impact), event_time=VALUES(event_time),
                       forecast=VALUES(forecast), previous=VALUES(previous), actual=VALUES(actual), updated_at=VALUES(updated_at)'
                );
                $count = 0;
                foreach ($res['json'] as $item) {
                    if (!is_array($item) || empty($item['title']) || empty($item['date'])) {
                        continue;
                    }
                    $ts = strtotime((string)$item['date']);
                    if ($ts === false) {
                        continue;
                    }
                    $country = strtoupper(substr(trim((string)($item['country'] ?? 'USD')), 0, 16));
                    $rawImpact = strtolower(trim((string)($item['impact'] ?? 'low')));
                    $impact = in_array($rawImpact, ['high', 'medium', 'low', 'holiday'], true) ? $rawImpact : 'low';
                    $extId = sha1($country . '|' . $item['title'] . '|' . gmdate('Y-m-d H:i', $ts));
                    $stmt->execute([
                        $provider,
                        $extId,
                        mb_substr(trim((string)$item['title']), 0, 255),
                        $country,
                        $impact,
                        gmdate('Y-m-d H:i:s', $ts),
                        $impact === 'holiday' ? 1 : 0,
                        isset($item['forecast']) && $item['forecast'] !== '' ? mb_substr((string)$item['forecast'], 0, 64) : null,
                        isset($item['previous']) && $item['previous'] !== '' ? mb_substr((string)$item['previous'], 0, 64) : null,
                        isset($item['actual']) && $item['actual'] !== '' ? mb_substr((string)$item['actual'], 0, 64) : null,
                        mb_substr($url, 0, 255),
                        $now,
                        $now,
                    ]);
                    $count++;
                }
                return $count;
            });
            Settings::setState('calendar.last_sync', ['ts' => time(), 'ok' => true, 'provider' => $provider, 'imported' => $imported]);
            return ['ok' => true, 'provider' => $provider, 'imported' => $imported, 'skipped' => false, 'error' => null];
        } catch (\Throwable $e) {
            Logger::error('Calendar DB store failed', ['err' => $e->getMessage()]);
            return ['ok' => false, 'provider' => $provider, 'imported' => 0, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Context payload for MultiTimeframeAnalyzer (`news_connected` + `events` around $now).
     */
    public function contextForAnalysis(int $now): array
    {
        $state = Settings::state('calendar.last_sync');
        $provider = $this->providerCode();
        $hasManual = (int)Database::value('SELECT COUNT(*) FROM economic_events WHERE event_time >= ?', [gmdate('Y-m-d H:i:s', $now - 86400)]) > 0;
        $connected = $provider === 'manual'
            || $hasManual
            || (is_array($state) && !empty($state['ok']) && ($now - (int)($state['ts'] ?? 0) < 7 * 86400));

        $rows = $this->eventsBetween($now - 6 * 3600, $now + 24 * 3600);
        $events = array_map(static fn(array $r): array => [
            'id'       => (int)$r['id'],
            'title'    => $r['title'],
            'country'  => $r['country'],
            'impact'   => $r['impact'],
            'time'     => (int)strtotime($r['event_time'] . ' UTC'),
            'forecast' => $r['forecast'],
            'previous' => $r['previous'],
            'actual'   => $r['actual'],
        ], $rows);

        return ['connected' => $connected, 'events' => $events, 'last_sync' => $state];
    }

    public function eventsBetween(int $fromTs, int $toTs, ?string $impact = null, ?string $country = null): array
    {
        $where = 'event_time >= ? AND event_time <= ?';
        $params = [gmdate('Y-m-d H:i:s', $fromTs), gmdate('Y-m-d H:i:s', $toTs)];
        if ($impact !== null && $impact !== '') {
            $where .= ' AND impact = ?';
            $params[] = $impact;
        }
        if ($country !== null && $country !== '') {
            $where .= ' AND country = ?';
            $params[] = strtoupper($country);
        }
        return Database::fetchAll(
            "SELECT * FROM economic_events WHERE $where ORDER BY event_time ASC LIMIT 300",
            $params
        );
    }

    public function addManual(array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Event title is required.');
        }
        $country = strtoupper(substr(trim((string)($data['country'] ?? 'USD')), 0, 16)) ?: 'USD';
        $impact = strtolower(trim((string)($data['impact'] ?? 'high')));
        if (!in_array($impact, ['high', 'medium', 'low', 'holiday'], true)) {
            throw new \InvalidArgumentException('Invalid impact level.');
        }
        $ts = is_numeric($data['event_time'] ?? null)
            ? (int)$data['event_time']
            : strtotime((string)($data['event_time'] ?? '') . ' UTC');
        if ($ts === false || $ts <= 946684800) {
            throw new \InvalidArgumentException('Invalid event_time (expected UTC YYYY-MM-DD HH:MM).');
        }
        $now = gmdate('Y-m-d H:i:s');
        $extId = sha1('manual|' . $country . '|' . $title . '|' . gmdate('Y-m-d H:i', $ts));
        Database::query(
            'INSERT INTO economic_events
             (source, external_id, title, country, impact, event_time, all_day, forecast, previous, actual, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               title=VALUES(title), impact=VALUES(impact), forecast=VALUES(forecast),
               previous=VALUES(previous), actual=VALUES(actual), updated_at=VALUES(updated_at)',
            [
                'manual',
                $extId,
                mb_substr($title, 0, 255),
                $country,
                $impact,
                gmdate('Y-m-d H:i:s', $ts),
                !empty($data['all_day']) ? 1 : 0,
                isset($data['forecast']) && $data['forecast'] !== '' ? mb_substr((string)$data['forecast'], 0, 64) : null,
                isset($data['previous']) && $data['previous'] !== '' ? mb_substr((string)$data['previous'], 0, 64) : null,
                isset($data['actual']) && $data['actual'] !== '' ? mb_substr((string)$data['actual'], 0, 64) : null,
                $userId,
                $now,
                $now,
            ]
        );
        return (int)Database::value('SELECT id FROM economic_events WHERE source = ? AND external_id = ?', ['manual', $extId]);
    }

    public function deleteEvent(int $id): void
    {
        Database::query('DELETE FROM economic_events WHERE id = ?', [$id]);
    }
}
