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
    /** Plausible top speeds: high-speed craft (AIS types 40-49) up to 50 kn, other vessels up to 40 kn. Faster values
     *  come from corrupted speed reports (a 55 kn "ferry" was seen at a test station). */
    private const MAX_SPEED_KN = 40;
    private const MAX_SPEED_HSC_KN = 50;
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
            // Towboats and pushers declare the length of their convoy (1.2.1): left out, like for the "very large" tag.
            'largest' => $vessel(Db::one("SELECT mmsi, name, country, shiptype, vclass, length_m, beam_m, last_seen AS ts FROM vessel
                WHERE vclass NOT IN {$infra} AND length_m IS NOT NULL AND length_m BETWEEN 1 AND 460"
                . (empty(Settings::get('rules')['large_tows']) ? ' AND pusher = 0 AND (shiptype IS NULL OR shiptype NOT IN (31, 32, 52))' : '')
                . ' ORDER BY length_m DESC, first_seen LIMIT 1')),
            'fastest' => $vessel(Db::one("SELECT mmsi, name, country, shiptype, vclass, max_speed_kn AS speed, last_seen AS ts FROM vessel
                WHERE vclass IN ('A', 'B') AND max_speed_kn IS NOT NULL
                AND max_speed_kn <= IF(shiptype BETWEEN 40 AND 49, ?, ?) ORDER BY max_speed_kn DESC, first_seen LIMIT 1",
                [self::MAX_SPEED_HSC_KN, self::MAX_SPEED_KN])),
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
        $rows = Db::all("SELECT {$dkey} AS dkey, MAX(UPPER(TRIM(destination))) AS label,
            SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT UPPER(TRIM(destination)) SEPARATOR '|'), '|', 6) AS variants, shiptype, vclass, COUNT(*) AS n FROM vessel
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
            $dest[$k] ??= ['key' => $k, 'label' => Destinations::label($k, (string) $r['label'], $map), 'n' => 0, 'types' => [], 'written' => []];
            $dest[$k]['n'] += $n;
            // How the crews wrote it ("ROTTERDAM", "NL RTM"…): shown on hover, often says what a code such as NLRTM is.
            foreach (explode('|', (string) $r['variants']) as $w) {
                if ($w !== '' && $w !== $dest[$k]['label'] && count($dest[$k]['written']) < 4) {
                    $dest[$k]['written'][$w] = true;
                }
            }
            $cat = Notify::typeCat((int) ($r['shiptype'] ?? 0), (string) $r['vclass']);
            $dest[$k]['types'][$cat] = ($dest[$k]['types'][$cat] ?? 0) + $n;
        }
        uasort($dest, static fn (array $a, array $b): int => [$b['n'], $a['label']] <=> [$a['n'], $b['label']]);
        $shown = array_slice(array_values($dest), 0, $top);
        foreach ($shown as &$d) {
            arsort($d['types']);
            $d['written'] = array_keys($d['written']);
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

    /** Kinds of transmitters, in display order: vclass => key (1.2.1). */
    public const TX_KINDS = ['A' => 'class_a', 'B' => 'class_b', 'SAR' => 'sar', 'EMRG' => 'emergency', 'BASE' => 'base', 'ATON' => 'aton', '' => 'unknown'];
    /** Aids to navigation grouped by AIS message 21 "type of aid" (ITU-R M.1371). */
    public const AID_GROUPS = [
        'light' => [5, 6, 7, 8], 'cardinal' => [9, 10, 11, 12, 20, 21, 22, 23], 'lateral' => [13, 14, 15, 16, 24, 25, 26, 27],
        'danger' => [17, 28], 'safe_water' => [18, 29], 'special' => [19, 30], 'structure' => [1, 2, 3], 'light_vessel' => [31],
    ];

    public static function aidGroup(?int $aidType): string
    {
        foreach (self::AID_GROUPS as $g => $types) {
            if ($aidType !== null && in_array($aidType, $types, true)) {
                return $g;
            }
        }
        return 'unspecified';
    }

    /**
     * Every transmitter heard, by kind (admin, 1.2.1): explains the difference between the "known vessels" of the admin
     * and the vessels of the public page, which leave out base stations and aids to navigation.
     * @return array{kinds: array<string, array{n: int, rows: array<int, array<string, mixed>>}>, aids: array<string, int>, vessels: int, infra: int}
     */
    public static function transmitters(int $limit = 100): array
    {
        $kinds = [];
        foreach (self::TX_KINDS as $vc => $key) {
            $kinds[$key] = ['n' => 0, 'rows' => []];
        }
        foreach (Db::all('SELECT vclass, COUNT(*) AS n FROM vessel GROUP BY vclass') as $r) {
            $key = self::TX_KINDS[(string) $r['vclass']] ?? 'unknown';
            $kinds[$key]['n'] += (int) $r['n'];
        }
        $hasStation = Settings::hasStation();
        $sLat = (float) Settings::get('station_lat');
        $sLon = (float) Settings::get('station_lon');
        foreach (self::TX_KINDS as $vc => $key) {
            if ($kinds[$key]['n'] === 0) {
                continue;
            }
            $where = $key === 'unknown' ? "vclass NOT IN ('A', 'B', 'SAR', 'EMRG', 'BASE', 'ATON')" : 'vclass = ?';
            $rows = Db::all("SELECT mmsi, name, country, shiptype, aid_type, last_seen, last_lat, last_lon FROM vessel WHERE {$where}
                             ORDER BY last_seen DESC LIMIT " . max(1, min(500, $limit)), $key === 'unknown' ? [] : [$vc]);
            foreach ($rows as &$r) {
                $r['dist'] = $hasStation && $r['last_lat'] !== null
                    ? round(Geo::distanceNm($sLat, $sLon, (float) $r['last_lat'], (float) $r['last_lon']), 1) : null;
                $r['aid'] = $key === 'aton' ? self::aidGroup($r['aid_type'] !== null ? (int) $r['aid_type'] : null) : null;
                unset($r['last_lat'], $r['last_lon']);
            }
            unset($r);
            $kinds[$key]['rows'] = $rows;
        }
        $aids = array_fill_keys(array_merge(array_keys(self::AID_GROUPS), ['unspecified']), 0);
        foreach (Db::all("SELECT aid_type, COUNT(*) AS n FROM vessel WHERE vclass = 'ATON' GROUP BY aid_type") as $r) {
            $aids[self::aidGroup($r['aid_type'] !== null ? (int) $r['aid_type'] : null)] += (int) $r['n'];
        }
        $infra = $kinds['base']['n'] + $kinds['aton']['n'];
        $all = array_sum(array_column($kinds, 'n'));
        return ['kinds' => $kinds, 'aids' => array_filter($aids), 'vessels' => $all - $infra, 'infra' => $infra];
    }
}
