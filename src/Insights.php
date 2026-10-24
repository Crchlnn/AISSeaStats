<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Statistics added in 1.2.0: the station's records (hall of fame), signal level by distance, and the
 * constellation (destinations of the vessels seen, and the network of ports linked by destination changes).
 */
final class Insights
{
    private const INFRA = "('BASE','ATON')";
    /** Plausible speed of a vessel; faster values come from bad positions or aircraft. */
    private const MAX_SPEED_KN = 60;
    /** Signal bins of 2 NM need this many messages to be shown (noise of single messages). */
    private const SIGNAL_MIN_N = 5;
    public const CONSTELLATION_TOPS = [10, 20, 30];

    /**
     * All-time records of the station; each entry has the vessel (when there is one) and the time.
     * @return array<string, array<string, mixed>|null>
     */
    public static function records(\DateTimeZone $tz): array
    {
        $infra = self::INFRA;
        $vessel = static function (?array $r): ?array {
            if ($r === null) {
                return null;
            }
            foreach (['mmsi', 'length_m', 'beam_m', 'shiptype', 'ts', 'n', 'secs'] as $k) {
                if (isset($r[$k])) {
                    $r[$k] = (int) $r[$k];
                }
            }
            if (isset($r['speed'])) {
                $r['speed'] = (float) $r['speed'];
            }
            return $r;
        };
        $far = Db::one('SELECT r.mmsi, r.max_dist_nm AS dist, r.day, r.ts, r.sector, v.name, v.country, v.shiptype, v.vclass
                        FROM range_polar r LEFT JOIN vessel v ON v.mmsi = r.mmsi ORDER BY r.max_dist_nm DESC, r.day LIMIT 1');
        if ($far !== null) {
            $far['dist'] = round((float) $far['dist'], 1);
            $far['sector'] = (int) $far['sector'];
        }
        $rare = Db::one("SELECT country, COUNT(*) AS n, MIN(first_seen) AS ts FROM vessel
                         WHERE country IS NOT NULL AND vclass NOT IN {$infra} GROUP BY country ORDER BY n, ts DESC LIMIT 1");
        if ($rare !== null) {
            $rare += Db::one("SELECT mmsi, name, shiptype, vclass FROM vessel WHERE country = ? AND vclass NOT IN {$infra}
                              ORDER BY first_seen DESC LIMIT 1", [$rare['country']]) ?? [];
        }
        $busy = Db::one('SELECT day, vessels AS n, msgs FROM stats_daily ORDER BY vessels DESC, day LIMIT 1');
        $long = Db::one("SELECT p.mmsi, p.start_ts AS ts, p.end_ts, p.end_ts - p.start_ts AS secs, v.name, v.country, v.shiptype, v.vclass
                         FROM passage p JOIN vessel v ON v.mmsi = p.mmsi WHERE v.vclass NOT IN {$infra}
                         ORDER BY secs DESC, p.start_ts LIMIT 1");
        return [
            'furthest' => $vessel($far),
            'largest' => $vessel(Db::one("SELECT mmsi, name, country, shiptype, vclass, length_m, beam_m, last_seen AS ts FROM vessel
                WHERE vclass NOT IN {$infra} AND length_m IS NOT NULL AND length_m BETWEEN 1 AND 460 ORDER BY length_m DESC, first_seen LIMIT 1")),
            'fastest' => $vessel(Db::one("SELECT mmsi, name, country, shiptype, vclass, max_speed_kn AS speed, last_seen AS ts FROM vessel
                WHERE vclass IN ('A', 'B') AND max_speed_kn IS NOT NULL AND max_speed_kn <= ? ORDER BY max_speed_kn DESC, first_seen LIMIT 1",
                [self::MAX_SPEED_KN])),
            'rarest_flag' => $vessel($rare),
            'busiest_day' => $busy !== null ? ['day' => (string) $busy['day'], 'n' => (int) $busy['n'], 'msgs' => (int) $busy['msgs']] : null,
            'longest_stay' => $vessel($long),
            'first' => $vessel(Db::one("SELECT mmsi, name, country, shiptype, vclass, first_seen AS ts FROM vessel
                WHERE vclass NOT IN {$infra} ORDER BY first_seen, mmsi LIMIT 1")),
            'since' => (int) Db::value("SELECT MIN(first_seen) FROM vessel"),
        ];
    }

    /**
     * Mean signal level by distance (bins of 2 NM) over the period, for the whole station and for each of the
     * eight 45° sectors (sector 0 = N). Bins with fewer than 5 messages are left out.
     * @return array{bins: array<int, array<string, mixed>>, sectors: array<int, array<int, array{d: float, avg: float, n: int}>>}
     */
    public static function signal(string $fromDay): array
    {
        $rows = Db::all('SELECT bin, octant, SUM(n) AS n, SUM(sig_sum) AS s, MIN(sig_min) AS mn, MAX(sig_max) AS mx
                         FROM signal_band WHERE day >= ? GROUP BY bin, octant ORDER BY bin', [$fromDay]);
        $all = [];
        $sectors = array_fill(0, 8, []);
        foreach ($rows as $r) {
            $b = (int) $r['bin'];
            $n = (int) $r['n'];
            $all[$b] ??= ['n' => 0, 's' => 0.0, 'mn' => null, 'mx' => null];
            $all[$b]['n'] += $n;
            $all[$b]['s'] += (float) $r['s'];
            $all[$b]['mn'] = $all[$b]['mn'] === null ? (float) $r['mn'] : min($all[$b]['mn'], (float) $r['mn']);
            $all[$b]['mx'] = $all[$b]['mx'] === null ? (float) $r['mx'] : max($all[$b]['mx'], (float) $r['mx']);
            if ($n >= self::SIGNAL_MIN_N) {
                $sectors[(int) $r['octant'] % 8][] = ['d' => $b * 2 + 1.0, 'avg' => round((float) $r['s'] / $n, 1), 'n' => $n];
            }
        }
        $bins = [];
        foreach ($all as $b => $a) {
            if ($a['n'] >= self::SIGNAL_MIN_N) {
                $bins[] = ['d' => $b * 2 + 1.0, 'avg' => round($a['s'] / $a['n'], 1), 'min' => $a['mn'], 'max' => $a['mx'], 'n' => $a['n']];
            }
        }
        return ['bins' => $bins, 'sectors' => $sectors];
    }

    /**
     * Option A: destinations declared by the vessels seen since $sinceTs (top N, unknown and meaningless left out)
     * and, for each, the vessel types going there.
     * @return array<string, mixed>
     */
    public static function destinations(int $sinceTs, int $top): array
    {
        $top = in_array($top, self::CONSTELLATION_TOPS, true) ? $top : 20;
        $map = Destinations::map();
        [$dkey, $dparams] = Destinations::sql($map);
        $infra = self::INFRA;
        $rows = Db::all("SELECT {$dkey} AS dkey, MAX(UPPER(TRIM(destination))) AS label, shiptype, vclass, COUNT(*) AS n FROM vessel
            WHERE last_seen >= ? AND destination IS NOT NULL AND destination <> '' AND vclass NOT IN {$infra}
            GROUP BY dkey, shiptype, vclass", array_merge($dparams, [$sinceTs]));
        $dest = [];
        $total = 0;
        $hidden = 0;
        foreach ($rows as $r) {
            $k = (string) $r['dkey'];
            $n = (int) $r['n'];
            $total += $n;
            if ($k === Destinations::UNKNOWN) {
                $hidden += $n;
                continue;
            }
            $dest[$k] ??= ['key' => $k, 'label' => Destinations::label($k, (string) $r['label'], $map), 'n' => 0, 'types' => []];
            $dest[$k]['n'] += $n;
            $cat = Notify::typeCat((int) ($r['shiptype'] ?? 0), (string) $r['vclass']);
            $dest[$k]['types'][$cat] = ($dest[$k]['types'][$cat] ?? 0) + $n;
        }
        uasort($dest, static fn (array $a, array $b): int => [$b['n'], $a['label']] <=> [$a['n'], $b['label']]);
        $shown = array_slice(array_values($dest), 0, $top);
        foreach ($shown as &$d) {
            arsort($d['types']);
            $d['types'] = array_map(static fn (string $c, int $n): array => ['cat' => $c, 'n' => $n], array_keys($d['types']), $d['types']);
        }
        unset($d);
        return ['mode' => 'dest', 'top' => $top, 'nodes' => $shown, 'total' => $total, 'unknown' => $hidden,
            'others' => max(0, count($dest) - count($shown))];
    }

    /**
     * Option B: ports linked by the destination changes of the same vessel (old → new), since $sinceTs.
     * Destination changes are collected since 1.2.0: "since" tells from when.
     * @return array<string, mixed>
     */
    public static function ports(int $sinceTs, int $top): array
    {
        $top = in_array($top, self::CONSTELLATION_TOPS, true) ? $top : 20;
        $map = Destinations::map();
        $links = [];
        $ports = [];
        foreach (Db::all('SELECT mmsi, old_dest, new_dest FROM destination_change WHERE ts >= ? ORDER BY ts DESC LIMIT 50000', [$sinceTs]) as $r) {
            $a = Destinations::canonical((string) $r['old_dest'], $map);
            $b = Destinations::canonical((string) $r['new_dest'], $map);
            if ($a === Destinations::UNKNOWN || $b === Destinations::UNKNOWN || $a === $b) {
                continue;
            }
            $m = (int) $r['mmsi'];
            $links[$a . '>' . $b][$m] = true;
            foreach ([[$a, (string) $r['old_dest']], [$b, (string) $r['new_dest']]] as [$k, $raw]) {
                $ports[$k] ??= ['key' => $k, 'label' => Destinations::label($k, strtoupper(trim($raw)), $map), 'vessels' => []];
                $ports[$k]['vessels'][$m] = true;
            }
        }
        foreach ($ports as &$p) {
            $p['n'] = count($p['vessels']);
            unset($p['vessels']);
        }
        unset($p);
        uasort($ports, static fn (array $a, array $b): int => [$b['n'], $a['label']] <=> [$a['n'], $b['label']]);
        $keep = array_slice($ports, 0, $top, true);
        $edges = [];
        foreach ($links as $ab => $vessels) {
            [$a, $b] = explode('>', $ab, 2);
            if (isset($keep[$a], $keep[$b])) {
                $edges[] = ['from' => $a, 'to' => $b, 'n' => count($vessels)];
            }
        }
        usort($edges, static fn (array $x, array $y): int => $y['n'] <=> $x['n']);
        return ['mode' => 'ports', 'top' => $top, 'nodes' => array_values($keep), 'links' => array_slice($edges, 0, 120),
            'others' => max(0, count($ports) - count($keep)), 'since' => (int) (Db::value('SELECT MIN(ts) FROM destination_change') ?? 0)];
    }

    /**
     * Vessels of one link of the ports network (from → to), for the list dialog.
     * @return array<int, array<string, mixed>>
     */
    public static function linkVessels(string $from, string $to, int $sinceTs): array
    {
        $map = Destinations::map();
        $mmsi = [];
        foreach (Db::all('SELECT mmsi, old_dest, new_dest FROM destination_change WHERE ts >= ? ORDER BY ts DESC LIMIT 50000', [$sinceTs]) as $r) {
            if (Destinations::canonical((string) $r['old_dest'], $map) === $from && Destinations::canonical((string) $r['new_dest'], $map) === $to) {
                $mmsi[(int) $r['mmsi']] = true;
            }
        }
        if ($mmsi === []) {
            return [];
        }
        $ids = array_slice(array_keys($mmsi), 0, 300);
        return Db::all('SELECT mmsi, name, shiptype, vclass, country, length_m, tags, last_seen, destination FROM vessel
                        WHERE mmsi IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY last_seen DESC', $ids);
    }
}
