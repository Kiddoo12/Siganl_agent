<?php
declare(strict_types=1);

namespace App\Analysis;

use App\Core\Settings;

/**
 * Trading sessions defined in their local timezone (DST handled by PHP's tz database).
 */
final class SessionService
{
    private array $sessions;

    public function __construct(?array $sessions = null)
    {
        $this->sessions = $sessions ?? (array)Settings::get('sessions', []);
    }

    /** UTC [start, end] of a session occurring on the local calendar date of $refTs. */
    public function window(string $key, int $refTs, int $dayOffset = 0): array
    {
        $s = $this->sessions[$key];
        $tz = new \DateTimeZone($s['tz']);
        $d = (new \DateTime('@' . $refTs))->setTimezone($tz);
        if ($dayOffset) $d->modify(($dayOffset > 0 ? '+' : '') . $dayOffset . ' day');
        $date = $d->format('Y-m-d');
        $start = new \DateTime($date . ' ' . $s['start'], $tz);
        $end = new \DateTime($date . ' ' . $s['end'], $tz);
        if ($end <= $start) $end->modify('+1 day');
        return [$start->getTimestamp(), $end->getTimestamp()];
    }

    /** Active sessions at $ts. */
    public function active(int $ts): array
    {
        $out = [];
        foreach ($this->sessions as $key => $s) {
            foreach ([0, -1] as $off) {
                [$a, $b] = $this->window($key, $ts, $off);
                if ($ts >= $a && $ts < $b) { $out[] = $key; break; }
            }
        }
        return $out;
    }

    public function label(int $ts): string
    {
        $act = $this->active($ts);
        if (!$act) return 'Off-session';
        $labels = array_map(fn($k) => $this->sessions[$k]['label'], $act);
        return implode(' / ', $labels) . (count($act) > 1 ? ' overlap' : '');
    }

    /** Session suitability points (max of active sessions). */
    public function score(int $ts): int
    {
        $best = 0;
        foreach ($this->active($ts) as $k) {
            $best = max($best, (int)($this->sessions[$k]['score'] ?? 0));
        }
        return $best;
    }

    /**
     * High/low of the most recent occurrence (completed or in progress) of each session,
     * computed from intraday candles that are already closed at $now.
     */
    public function ranges(array $candles, string $tf, int $now): array
    {
        $out = [];
        foreach ($this->sessions as $key => $s) {
            foreach ([0, -1, -2, -3] as $off) {
                [$a, $b] = $this->window($key, $now, $off);
                if ($a > $now) continue;
                $hi = null; $lo = null;
                foreach ($candles as $c) {
                    if ($c['t'] >= $a && $c['t'] < $b) {
                        $hi = $hi === null ? $c['h'] : max($hi, $c['h']);
                        $lo = $lo === null ? $c['l'] : min($lo, $c['l']);
                    }
                }
                if ($hi !== null) {
                    $out[$key] = [
                        'label' => $s['label'], 'high' => $hi, 'low' => $lo, 'start' => $a, 'end' => $b,
                        'in_progress' => $now < $b,
                    ];
                    break;
                }
            }
        }
        return $out;
    }

    /** Session hours for display, converted to UTC for "today". */
    public function schedule(int $now): array
    {
        $out = [];
        foreach ($this->sessions as $key => $s) {
            [$a, $b] = $this->window($key, $now);
            $out[] = ['key' => $key, 'label' => $s['label'], 'tz' => $s['tz'], 'local' => $s['start'] . '-' . $s['end'],
                'start_utc' => $a, 'end_utc' => $b, 'active' => in_array($key, $this->active($now), true)];
        }
        return $out;
    }
}
