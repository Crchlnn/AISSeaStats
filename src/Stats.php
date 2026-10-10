<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Statistics added in 1.1.0 (record times in 1.1.1): vessels by distance band, propagation days, furthest vessels and records,
 * time between passages ("regulars"), busiest hours and station reception.
 * Read-only queries; times are shown in the station's time zone.
 */
final class Stats
{
    /** Distance bands, in NM: below 20, 20 to 50, 50 and beyond, then "distance unknown". */
    public const BAND_LIMITS = [20, 50];
    /** A propagation day: range at least twice the usual one, 20 NM more, and 3 vessels beyond 1.5 × usual. */
    private const PROPAGATION_FACTOR = 2.0;
    private const PROPAGATION_MIN_GAIN_NM = 20.0;
    private const PROPAGATION_MIN_VESSELS = 3;
    private const BASELINE_DAYS = 30;
    private const BASELINE_MIN_DAYS = 7;
    /** Regulars: at least 4 passages (3 intervals) in the period. */
    public const REGULAR_MIN_GAPS = 3;

    /** Page sizes of the Top vessels and Regulars cards (1.1.2). */
    public const PAGE_SIZES = [10, 20, 50, 100];
    private const MAX_PAGE = 10000;

    public static function pageSize(mixed $v): int
    {
        return in_array((int) $v, self::PAGE_SIZES, true) ? (int) $v : self::PAGE_SIZES[0];
    }

    /**
     * One page of a ranking. The query selects `COUNT(*) OVER () AS _total` (rows before LIMIT) and has no LIMIT.
     * A page past the end (the ranking shrank since the page was shown) gives the last page instead.
     * @param array<int, mixed> $params
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public static function paged(string $sql, array $params, int $limit, int $page): array
    {
        $limit = self::pageSize($limit);
        $page = max(0, min(self::MAX_PAGE, $page));
        $rows = Db::all($sql . ' LIMIT ' . $limit . ' OFFSET ' . ($page * $limit), $params);
        if ($rows === [] && $page > 0) {
            $total = (int) (Db::all($sql . ' LIMIT 1', $params)[0]['_total'] ?? 0);
            $page = $total > 0 ? intdiv($total - 1, $limit) : 0;
            $rows = $total > 0 ? Db::all($sql . ' LIMIT ' . $limit . ' OFFSET ' . ($page * $limit), $params) : [];
        }
        $total = (int) ($rows[0]['_total'] ?? 0);
        foreach ($rows as &$r) {
            unset($r['_total']);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** SQL giving the band index (0, 1, 2; 3 = unknown) of a distance column. */
    public static function bandSql(string $col): string
    {
        [$a, $b] = self::BAND_LIMITS;
        return "CASE WHEN {$col} IS NULL THEN 3 WHEN {$col} < {$a} THEN 0 WHEN {$col} < {$b} THEN 1 ELSE 2 END";
    }

    public static function band(?float $nm): int
    {
        [$a, $b] = self::BAND_LIMITS;
        return $nm === null ? 3 : ($nm < $a ? 0 : ($nm < $b ? 1 : 2));
    }

    /** @return array<int, array<int, int>> hour_ts => [n0, n1, n2, unknown] */
    public static function bandsByHour(int $from): array
    {
        $out = [];
        foreach (Db::all('SELECT hour_ts, ' . self::bandSql('max_dist_nm') . ' AS b, COUNT(*) AS n
                FROM vessel_hourly WHERE hour_ts >= ? GROUP BY hour_ts, b', [$from]) as $r) {
            $out[(int) $r['hour_ts']] ??= [0, 0, 0, 0];
            $out[(int) $r['hour_ts']][(int) $r['b']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string, array<int, int>> Y-m-d => [n0, n1, n2, unknown] */
    public static function bandsByDay(string $fromDay): array
    {
        $out = [];
        foreach (Db::all('SELECT day, ' . self::bandSql('max_dist_nm') . ' AS b, COUNT(*) AS n
                FROM vessel_daily WHERE day >= ? GROUP BY day, b', [$fromDay]) as $r) {
            $out[(string) $r['day']] ??= [0, 0, 0, 0];
            $out[(string) $r['day']][(int) $r['b']] = (int) $r['n'];
        }
        return $out;
    }

    /** Unique vessels per month, each counted in the band of its furthest position that month. @return array<string, array<int, int>> */
    public static function bandsByMonth(string $fromDay): array
    {
        $out = [];
        foreach (Db::all('SELECT m, ' . self::bandSql('d') . " AS b, COUNT(*) AS n FROM (
                    SELECT DATE_FORMAT(day, '%Y-%m') AS m, mmsi, MAX(max_dist_nm) AS d
                    FROM vessel_daily WHERE day >= ? GROUP BY m, mmsi) x
                GROUP BY m, b", [$fromDay]) as $r) {
            $out[(string) $r['m']] ??= [0, 0, 0, 0];
            $out[(string) $r['m']][(int) $r['b']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Days of exceptional reception (tropospheric ducting): the day's range is at least twice the usual
     * range (median of the previous 30 days' ranges), 20 NM more, and at least 3 vessels were heard beyond
     * 1.5 × the usual range, so one stray message cannot make an event.
     * @return array<int, array{t: string, max: float, usual: float, threshold: float, far: int}>
     */
    public static function propagation(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $start = $from->modify('-' . self::BASELINE_DAYS . ' days');
        $range = [];
        foreach (Db::all('SELECT day, max_dist_nm FROM stats_daily WHERE day >= ? AND day <= ? AND max_dist_nm IS NOT NULL',
            [$start->format('Y-m-d'), $to->format('Y-m-d')]) as $r) {
            $range[(string) $r['day']] = (float) $r['max_dist_nm'];
        }
        $events = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            if (!isset($range[$k])) {
                continue;
            }
            $prev = [];
            for ($p = $d->modify('-' . self::BASELINE_DAYS . ' days'); $p < $d; $p = $p->modify('+1 day')) {
                if (isset($range[$p->format('Y-m-d')])) {
                    $prev[] = $range[$p->format('Y-m-d')];
                }
            }
            if (count($prev) < self::BASELINE_MIN_DAYS) {
                continue;
            }
            $usual = self::median($prev);
            $max = $range[$k];
            if ($usual <= 0 || $max < self::PROPAGATION_FACTOR * $usual || $max - $usual < self::PROPAGATION_MIN_GAIN_NM) {
                continue;
            }
            $threshold = round(1.5 * $usual, 1);
            $far = (int) Db::value('SELECT COUNT(*) FROM vessel_daily WHERE day = ? AND max_dist_nm > ?', [$k, $threshold]);
            if ($far >= self::PROPAGATION_MIN_VESSELS) {
                $events[] = ['t' => $k, 'max' => $max, 'usual' => round($usual, 1), 'threshold' => $threshold, 'far' => $far];
            }
        }
        return $events;
    }

    /** @param array<int, float> $values */
    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * Furthest vessels of the period, one line per vessel, with the day and direction of its record.
     * @return array<int, array<string, mixed>>
     */
    public static function furthest(string $fromDay, ?\DateTimeZone $tz = null, int $limit = 5): array
    {
        $out = [];
        foreach (Db::all('SELECT mmsi, MAX(max_dist_nm) AS d FROM range_polar WHERE day >= ? GROUP BY mmsi ORDER BY d DESC LIMIT ' . max(1, $limit),
            [$fromDay]) as $r) {
            $rec = Db::one('SELECT day, sector, ts FROM range_polar WHERE mmsi = ? AND day >= ? ORDER BY max_dist_nm DESC, day DESC LIMIT 1',
                [(int) $r['mmsi'], $fromDay]);
            $v = Db::one('SELECT mmsi, name, country, shiptype, vclass, length_m FROM vessel WHERE mmsi = ?', [(int) $r['mmsi']])
                ?? ['mmsi' => (int) $r['mmsi'], 'name' => null, 'country' => null, 'shiptype' => null, 'vclass' => '', 'length_m' => null];
            $out[] = $v + [
                'dist' => (float) $r['d'],
                'day' => $rec['day'] ?? null,
                'ts' => $rec !== null ? self::timeOnDay($rec['ts'], (string) $rec['day'], $tz ?? new \DateTimeZone('UTC')) : null,
                'dir' => $rec !== null ? Geo::sectorLabel((int) $rec['sector'] * 10 + 5) : null,
            ];
        }
        return $out;
    }

    /**
     * The record of each 10° sector (since a day, or all time): vessel, day and, when known, time.
     * A time not on the record's day (station time zone) is dropped: it can only come from an approximate backfill.
     * @return array<int, array<string, mixed>|null> 36 entries, null for a sector without record
     */
    public static function sectorRecords(?string $fromDay, \DateTimeZone $tz): array
    {
        $out = array_fill(0, 36, null);
        $rows = Db::all('SELECT x.sector, x.day, x.max_dist_nm, x.mmsi, x.ts, v.name, v.country FROM (
                SELECT sector, day, max_dist_nm, mmsi, ts,
                       ROW_NUMBER() OVER (PARTITION BY sector ORDER BY max_dist_nm DESC, day DESC) AS rn
                FROM range_polar' . ($fromDay !== null ? ' WHERE day >= ?' : '') . '
            ) x LEFT JOIN vessel v ON v.mmsi = x.mmsi WHERE x.rn = 1', $fromDay !== null ? [$fromDay] : []);
        foreach ($rows as $r) {
            $sector = (int) $r['sector'];
            if ($sector < 0 || $sector > 35) {
                continue;
            }
            $out[$sector] = [
                'mmsi' => (int) $r['mmsi'],
                'name' => $r['name'],
                'country' => $r['country'],
                'day' => (string) $r['day'],
                'ts' => self::timeOnDay($r['ts'], (string) $r['day'], $tz),
            ];
        }
        return $out;
    }

    /** The time of a record, or null when unknown or not on the record's day (station time zone). */
    public static function timeOnDay(mixed $ts, string $day, \DateTimeZone $tz): ?int
    {
        if ($ts === null || $ts === '' || (int) $ts <= 0) {
            return null;
        }
        return (new \DateTimeImmutable('@' . (int) $ts))->setTimezone($tz)->format('Y-m-d') === $day ? (int) $ts : null;
    }

    /**
     * Time between consecutive passages: from the end of one to the start of the next.
     * @param array<int, array{start_ts: int|string, end_ts: int|string}> $passages ordered by start
     * @return array<int, int> seconds
     */
    public static function gapsOf(array $passages): array
    {
        $gaps = [];
        $prevEnd = null;
        foreach ($passages as $p) {
            if ($prevEnd !== null && (int) $p['start_ts'] > $prevEnd) {
                $gaps[] = (int) $p['start_ts'] - $prevEnd;
            }
            $prevEnd = max($prevEnd ?? 0, (int) $p['end_ts']);
        }
        return $gaps;
    }

    /** @param array<int, int> $gaps @return array{count: int, avg: int, min: int, max: int, sd: int}|null */
    public static function gapStats(array $gaps): ?array
    {
        $n = count($gaps);
        if ($n === 0) {
            return null;
        }
        $avg = array_sum($gaps) / $n;
        $var = 0.0;
        foreach ($gaps as $g) {
            $var += ($g - $avg) ** 2;
        }
        return ['count' => $n, 'avg' => (int) round($avg), 'min' => min($gaps), 'max' => max($gaps), 'sd' => (int) round(sqrt($var / $n))];
    }

    /** @return array{count: int, avg: int, min: int, max: int, sd: int}|null */
    public static function vesselGaps(int $mmsi): ?array
    {
        return self::gapStats(self::gapsOf(Db::all('SELECT start_ts, end_ts FROM passage WHERE mmsi = ? ORDER BY start_ts', [$mmsi])));
    }

    /**
     * Vessels that come back most regularly: at least 4 passages in the period, ranked by the spread of
     * the time between passages relative to its average (coefficient of variation), then by passages.
     * @return array<int, array<string, mixed>>
     */
    public static function regulars(int $sinceTs, int $limit = 10): array
    {
        return self::regularsPage($sinceTs, $limit, 0)['rows'];
    }

    /**
     * One page of the regulars, best first, with the number of regulars in the period.
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public static function regularsPage(int $sinceTs, int $limit, int $page): array
    {
        // Computed by the database (window function): light on memory even with a year of a busy coast.
        $ranked = [];
        $res = self::paged("SELECT y.*, COUNT(*) OVER () AS _total FROM (
                SELECT mmsi, COUNT(*) AS gaps, AVG(g) AS avg_s, STDDEV_POP(g) AS sd_s, MIN(g) AS min_s, MAX(g) AS max_s
                FROM (SELECT p.mmsi, CAST(p.start_ts AS SIGNED) - LAG(CAST(p.end_ts AS SIGNED)) OVER (PARTITION BY p.mmsi ORDER BY p.start_ts) AS g
                      FROM passage p JOIN vessel v ON v.mmsi = p.mmsi
                      WHERE p.start_ts >= ? AND v.vclass NOT IN ('BASE','ATON')) x
                WHERE g > 0 GROUP BY mmsi HAVING gaps >= ?) y
            ORDER BY sd_s / avg_s, gaps DESC, mmsi", [$sinceTs, self::REGULAR_MIN_GAPS], $limit, $page);
        foreach ($res['rows'] as $r) {
            $avg = (int) round((float) $r['avg_s']);
            $ranked[] = ['mmsi' => (int) $r['mmsi'], 'passages' => (int) $r['gaps'] + 1, 'count' => (int) $r['gaps'],
                'avg' => $avg, 'min' => (int) $r['min_s'], 'max' => (int) $r['max_s'], 'sd' => (int) round((float) $r['sd_s']),
                'cv' => $avg > 0 ? (float) $r['sd_s'] / $avg : 0.0];
        }
        if ($ranked === []) {
            return ['rows' => []] + $res;
        }
        $info = [];
        $in = implode(',', array_fill(0, count($ranked), '?'));
        foreach (Db::all("SELECT mmsi, name, country, shiptype, vclass, length_m FROM vessel WHERE mmsi IN ($in)", array_column($ranked, 'mmsi')) as $v) {
            $info[(int) $v['mmsi']] = $v;
        }
        $res['rows'] = array_map(static fn ($r) => array_merge($info[$r['mmsi']] ?? [], $r, ['cv' => round($r['cv'], 3)]), $ranked);
        return $res;
    }

    /**
     * Average number of vessels per local weekday (1 = Monday) and hour, over the hours when messages
     * were received (hours with no message at all are not counted, so an outage does not look like calm).
     * @return array{cells: array<int, array<int, float|null>>, max: float, hours: int}
     */
    public static function heatmap(int $sinceTs, \DateTimeZone $tz): array
    {
        $sum = array_fill(1, 7, array_fill(0, 24, 0));
        $n = array_fill(1, 7, array_fill(0, 24, 0));
        $dt = new \DateTime('now', $tz);
        $hours = 0;
        foreach (Db::all('SELECT hour_ts, vessels FROM stats_hourly WHERE hour_ts >= ? AND msgs > 0', [$sinceTs]) as $r) {
            [$wd, $h] = explode(' ', $dt->setTimestamp((int) $r['hour_ts'])->format('N G'));
            $sum[(int) $wd][(int) $h] += (int) $r['vessels'];
            $n[(int) $wd][(int) $h]++;
            $hours++;
        }
        $cells = [];
        $max = 0.0;
        for ($wd = 1; $wd <= 7; $wd++) {
            for ($h = 0; $h < 24; $h++) {
                $avg = $n[$wd][$h] > 0 ? round($sum[$wd][$h] / $n[$wd][$h], 1) : null;
                $cells[$wd - 1][$h] = $avg;
                $max = max($max, (float) $avg);
            }
        }
        return ['cells' => $cells, 'max' => $max, 'hours' => $hours];
    }

    /**
     * Reception per hour: a strip per local day (the last $rows days) and, over the whole period,
     * the share of hours with messages and the gaps without any.
     * Cell states: ok, none (no message), now (current hour, nothing yet), before (station not yet
     * receiving), future, skip (hour that does not exist: daylight saving change).
     * @return array<string, mixed>
     */
    public static function uptime(int $days, int $rows, int $now, \DateTimeZone $tz): array
    {
        $curHour = intdiv($now, 3600) * 3600;
        $first = Db::value('SELECT MIN(hour_ts) FROM stats_hourly WHERE msgs > 0');
        $first = $first === null ? null : (int) $first;
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);

        // Strip: the last $rows local days.
        $stripStart = $today->modify('-' . ($rows - 1) . ' days');
        $ok = [];
        foreach (Db::all('SELECT hour_ts FROM stats_hourly WHERE hour_ts >= ? AND msgs > 0', [$stripStart->getTimestamp()]) as $r) {
            $ok[(int) $r['hour_ts']] = true;
        }
        $strip = [];
        for ($d = $stripStart; $d <= $today; $d = $d->modify('+1 day')) {
            $strip[$d->format('Y-m-d')] = array_fill(0, 24, 'skip');
        }
        $dt = new \DateTime('now', $tz);
        $end = $today->modify('+1 day')->getTimestamp();
        for ($h = intdiv($stripStart->getTimestamp(), 3600) * 3600; $h < $end; $h += 3600) {
            [$day, $hour] = explode(' ', $dt->setTimestamp($h)->format('Y-m-d G'));
            if (!isset($strip[$day])) {
                continue;
            }
            $state = match (true) {
                isset($ok[$h]) => 'ok',
                $h > $curHour => 'future',
                $first === null || $h < intdiv($first, 3600) * 3600 => 'before',
                $h === $curHour => 'now',
                default => 'none',
            };
            // A local hour seen twice (end of daylight saving time): one hour with messages is enough.
            if ($strip[$day][(int) $hour] !== 'ok') {
                $strip[$day][(int) $hour] = $state;
            }
        }
        $stripOut = [];
        foreach ($strip as $day => $cells) {
            $stripOut[] = ['day' => $day, 'cells' => $cells];
        }

        // Whole period: completed hours since the station started receiving.
        $periodStart = $today->modify('-' . (max(1, $days) - 1) . ' days')->getTimestamp();
        $from = $first === null ? null : max(intdiv($periodStart, 3600) * 3600, intdiv($first, 3600) * 3600);
        $total = $from === null ? 0 : max(0, intdiv($curHour - $from, 3600));
        $gaps = [];
        $okHours = 0;
        if ($total > 0) {
            $prev = $from - 3600;
            $hoursOk = array_map('intval', array_column(Db::all('SELECT hour_ts FROM stats_hourly WHERE hour_ts >= ? AND hour_ts < ? AND msgs > 0 ORDER BY hour_ts',
                [$from, $curHour]), 'hour_ts'));
            $okHours = count($hoursOk);
            foreach (array_merge($hoursOk, [$curHour]) as $h) {
                if ($h - $prev > 3600) {
                    $gaps[] = ['from' => $prev + 3600, 'to' => $h, 'hours' => intdiv($h - $prev - 3600, 3600)];
                }
                $prev = $h;
            }
        }
        $gapsTotal = count($gaps);
        $longest = $gaps === [] ? 0 : max(array_column($gaps, 'hours'));
        usort($gaps, static fn ($a, $b) => $b['from'] <=> $a['from']);
        return [
            'strip' => $stripOut,
            'first' => $first,
            'hours' => $total,
            'ok_hours' => $okHours,
            'uptime' => $total > 0 ? round(100 * $okHours / $total, 1) : null,
            'gaps' => array_slice($gaps, 0, 5),
            'gaps_total' => $gapsTotal,
            'longest' => $longest,
        ];
    }
}
