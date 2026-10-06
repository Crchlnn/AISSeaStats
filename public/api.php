<?php
declare(strict_types=1);

/**
 * Read-only JSON API used by the statistics page.
 * GET api.php?q=<endpoint>[&...]
 */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Destinations;
use AISSeaStats\Enrich;
use AISSeaStats\Settings;
use AISSeaStats\Web;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Web::json(['error' => 'GET only'], 405);
}
try {
    if (!Settings::get('setup_done')) {
        Web::json(['error' => 'setup required'], 503);
    }
} catch (Throwable) {
    Web::json(['error' => 'database unavailable'], 503);
}

$q = (string) ($_GET['q'] ?? '');
$days = max(1, min(3650, (int) ($_GET['days'] ?? 30)));
$now = time();
$tz = Settings::timezone();
$today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
$sinceDay = $today->modify('-' . ($days - 1) . ' days');
$sinceTs = $sinceDay->getTimestamp();
const INFRA = "('BASE','ATON')";
const TYPE_CATS = [
    'cargo' => 'shiptype BETWEEN 70 AND 79',
    'tanker' => 'shiptype BETWEEN 80 AND 89',
    'passenger' => 'shiptype BETWEEN 60 AND 69',
    'hsc' => 'shiptype BETWEEN 40 AND 49',
    'fishing' => 'shiptype = 30',
    'tug' => 'shiptype IN (31, 32, 52)',
    'sailing' => 'shiptype = 36',
    'pleasure' => 'shiptype = 37',
    'military' => 'shiptype = 35',
    'pilot' => 'shiptype = 50',
    'authority' => 'shiptype IN (51, 55, 58)',
    'service' => 'shiptype IN (33, 34, 53, 54)',
    'unknown' => '(shiptype IS NULL OR shiptype = 0)',
];

try {
    switch ($q) {
        case 'summary':
            Web::json(summary($today, $now), 200, 20);
        case 'counts':
            Web::json(counts((string) ($_GET['period'] ?? 'day'), $today, $now, $days, (int) ($_GET['months'] ?? 24)), 200, 60);
        case 'routes':
            Web::json(routes($sinceTs), 200, 60);
        case 'top':
            Web::json(top((string) ($_GET['by'] ?? 'passages'), $sinceDay, $sinceTs), 200, 60);
        case 'remarkable':
            Web::json(remarkable(), 200, 30);
        case 'fleet':
            Web::json(fleet($sinceDay, $sinceTs), 200, 60);
        case 'polar':
            Web::json(polar($sinceDay), 200, 60);
        case 'vessel':
            Web::json(vessel((int) ($_GET['mmsi'] ?? 0)), 200, 30);
        case 'photo':
            Web::json(photo((int) ($_GET['mmsi'] ?? 0)), 200, 300);
        case 'list':
            Web::json(vesselList((string) ($_GET['by'] ?? ''), $sinceDay, $sinceTs), 200, 30);
        case 'search':
            Web::json(search((string) ($_GET['s'] ?? '')), 200, 10);
        default:
            Web::json(['error' => 'unknown endpoint'], 404);
    }
} catch (Throwable $e) {
    error_log('[aisseastats] api ' . $q . ': ' . $e->getMessage());
    Web::json(['error' => 'internal error'], 500);
}

/** @return array<string, mixed> */
function summary(DateTimeImmutable $today, int $now): array
{
    $d = $today->format('Y-m-d');
    $count = static fn (string $from): int => (int) Db::value(
        'SELECT COUNT(DISTINCT mmsi) FROM vessel_daily WHERE day >= ?',
        [$from]
    );
    $record = Db::one('SELECT r.day, r.max_dist_nm, r.mmsi, v.name FROM range_polar r
                       LEFT JOIN vessel v ON v.mmsi = r.mmsi ORDER BY r.max_dist_nm DESC LIMIT 1');
    $lastBatch = Db::one('SELECT ts, error FROM ingest_log ORDER BY id DESC LIMIT 1');
    return [
        'station' => [
            'name' => Settings::get('station_name'),
            'lat' => Settings::get('station_lat'),
            'lon' => Settings::get('station_lon'),
        ],
        'now' => $now,
        'vessels' => [
            'today' => $count($d),
            'd7' => $count($today->modify('-6 days')->format('Y-m-d')),
            'd30' => $count($today->modify('-29 days')->format('Y-m-d')),
            'all' => (int) Db::value('SELECT COUNT(*) FROM vessel WHERE vclass NOT IN ' . INFRA),
        ],
        'new_today' => (int) Db::value('SELECT COUNT(*) FROM vessel WHERE first_seen >= ? AND vclass NOT IN ' . INFRA, [$today->getTimestamp()]),
        'msgs_today' => (int) Db::value('SELECT COALESCE(SUM(msgs), 0) FROM msgtype_daily WHERE day = ?', [$d]),
        'active_now' => (int) Db::value('SELECT COUNT(*) FROM vessel WHERE last_seen >= ? AND vclass NOT IN ' . INFRA, [$now - 900]),
        'range_today' => Db::value('SELECT MAX(max_dist_nm) FROM range_polar WHERE day = ?', [$d]),
        'range_record' => $record,
        'last_msg' => (int) Db::value('SELECT MAX(last_seen) FROM vessel'),
        'last_batch' => $lastBatch,
        'first_day' => Db::value('SELECT MIN(day) FROM vessel_daily'),
    ];
}

/** @return array<string, mixed> */
function counts(string $period, DateTimeImmutable $today, int $now, int $days = 90, int $months = 24): array
{
    $out = [];
    if ($period === 'hour') {
        $from = intdiv($now, 3600) * 3600 - 47 * 3600;
        $rows = [];
        foreach (Db::all('SELECT hour_ts, vessels, msgs FROM stats_hourly WHERE hour_ts >= ?', [$from]) as $r) {
            $rows[(int) $r['hour_ts']] = $r;
        }
        for ($h = $from; $h <= $now; $h += 3600) {
            $out[] = ['t' => $h, 'vessels' => (int) ($rows[$h]['vessels'] ?? 0), 'msgs' => (int) ($rows[$h]['msgs'] ?? 0)];
        }
        return ['period' => 'hour', 'series' => $out];
    }
    if ($period === 'month') {
        if ($months <= 0) {
            // "All": from the first month with data (at least 12 months shown).
            $first = (string) (Db::value('SELECT MIN(day) FROM vessel_daily') ?? $today->format('Y-m-d'));
            $from = (new DateTimeImmutable($first, $today->getTimezone()))->modify('first day of this month');
            $floor = $today->modify('first day of this month')->modify('-11 months');
            if ($from > $floor) {
                $from = $floor;
            }
        } else {
            $from = $today->modify('first day of this month')->modify('-' . (min(120, $months) - 1) . ' months');
        }
        $v = [];
        foreach (Db::all("SELECT DATE_FORMAT(day, '%Y-%m') m, COUNT(DISTINCT mmsi) c FROM vessel_daily
                          WHERE day >= ? GROUP BY m", [$from->format('Y-m-d')]) as $r) {
            $v[$r['m']] = (int) $r['c'];
        }
        $msgs = [];
        foreach (Db::all("SELECT DATE_FORMAT(day, '%Y-%m') m, SUM(msgs) c FROM msgtype_daily
                          WHERE day >= ? GROUP BY m", [$from->format('Y-m-d')]) as $r) {
            $msgs[$r['m']] = (int) $r['c'];
        }
        for ($m = $from; $m <= $today; $m = $m->modify('+1 month')) {
            $k = $m->format('Y-m');
            $out[] = ['t' => $k, 'vessels' => $v[$k] ?? 0, 'msgs' => $msgs[$k] ?? 0];
        }
        return ['period' => 'month', 'series' => $out];
    }
    $from = $today->modify('-' . (max(7, min(366, $days)) - 1) . ' days');
    $rows = [];
    foreach (Db::all('SELECT day, vessels, msgs, new_vessels, max_dist_nm FROM stats_daily WHERE day >= ?', [$from->format('Y-m-d')]) as $r) {
        $rows[(string) $r['day']] = $r;
    }
    for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $out[] = [
            't' => $k,
            'vessels' => (int) ($rows[$k]['vessels'] ?? 0),
            'msgs' => (int) ($rows[$k]['msgs'] ?? 0),
            'new' => (int) ($rows[$k]['new_vessels'] ?? 0),
            'range' => isset($rows[$k]['max_dist_nm']) ? (float) $rows[$k]['max_dist_nm'] : null,
        ];
    }
    return ['period' => 'day', 'series' => $out];
}

/** @return array<string, mixed> */
function routes(int $sinceTs): array
{
    $routes = Db::all("SELECT entry_zone AS `from`, exit_zone AS `to`, COUNT(*) AS passages,
            COUNT(DISTINCT mmsi) AS vessels, AVG(entry_lat) AS from_lat, AVG(entry_lon) AS from_lon,
            AVG(exit_lat) AS to_lat, AVG(exit_lon) AS to_lon, MAX(start_ts) AS last_ts
        FROM passage
        WHERE closed = 1 AND start_ts >= ? AND moved_nm >= 0.5 AND entry_zone IS NOT NULL AND exit_zone IS NOT NULL
        GROUP BY entry_zone, exit_zone ORDER BY passages DESC LIMIT 10", [$sinceTs]);
    // "FR SML", "SAINT-MALO", "ST.MALO"… grouped on one key (letters and digits, owner's dictionary);
    // meaningless values such as "0" or "Q" are grouped as "unknown".
    $map = Destinations::map();
    [$dkey, $dparams] = Destinations::sql($map);
    $dest = Db::all("SELECT {$dkey} AS dkey, MAX(UPPER(TRIM(destination))) AS label, COUNT(*) AS vessels FROM vessel
        WHERE last_seen >= ? AND destination IS NOT NULL AND destination <> ''
        GROUP BY dkey ORDER BY (dkey = '?'), vessels DESC, dkey LIMIT 10", array_merge($dparams, [$sinceTs]));
    foreach ($dest as &$d) {
        $d['destination'] = Destinations::label((string) $d['dkey'], (string) $d['label'], $map);
        unset($d['label']);
    }
    unset($d);
    return ['routes' => $routes, 'destinations' => $dest];
}

/** @return array<string, mixed> */
function top(string $by, DateTimeImmutable $sinceDay, int $sinceTs): array
{
    $cols = 'v.mmsi, v.name, v.shiptype, v.country, v.length_m, v.tags, v.vclass';
    $sql = match ($by) {
        'days' => "SELECT {$cols}, COUNT(*) AS value FROM vessel_daily d JOIN vessel v ON v.mmsi = d.mmsi
                   WHERE d.day >= ? GROUP BY d.mmsi ORDER BY value DESC, v.last_seen DESC LIMIT 15",
        'length' => "SELECT {$cols}, v.length_m AS value FROM vessel v WHERE v.last_seen >= ? AND v.length_m IS NOT NULL
                   AND v.vclass NOT IN " . INFRA . ' ORDER BY value DESC LIMIT 15',
        'speed' => "SELECT {$cols}, v.max_speed_kn AS value FROM vessel v WHERE v.last_seen >= ? AND v.max_speed_kn IS NOT NULL
                   ORDER BY value DESC LIMIT 15",
        'distance' => "SELECT {$cols}, v.max_dist_nm AS value FROM vessel v WHERE v.last_seen >= ? AND v.max_dist_nm IS NOT NULL
                   AND v.vclass NOT IN " . INFRA . ' ORDER BY value DESC LIMIT 15',
        default => "SELECT {$cols}, COUNT(*) AS value FROM passage p JOIN vessel v ON v.mmsi = p.mmsi
                   WHERE p.start_ts >= ? GROUP BY p.mmsi ORDER BY value DESC, v.last_seen DESC LIMIT 15",
    };
    $param = $by === 'days' ? $sinceDay->format('Y-m-d') : $sinceTs;
    return ['by' => in_array($by, ['days', 'length', 'speed', 'distance'], true) ? $by : 'passages', 'rows' => Db::all($sql, [$param])];
}

/** @return array<string, mixed> */
function remarkable(): array
{
    $rows = Db::all('SELECT p.start_ts, p.end_ts, p.max_dist_nm, v.mmsi, v.name, v.shiptype, v.country, v.length_m, v.tags, v.vclass
        FROM passage p JOIN vessel v ON v.mmsi = p.mmsi
        WHERE v.tags <> \'\' ORDER BY p.start_ts DESC LIMIT 30');
    $counts = [];
    foreach (Db::all("SELECT tags FROM vessel WHERE tags <> ''") as $r) {
        foreach (explode(',', (string) $r['tags']) as $t) {
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
    }
    return ['rows' => $rows, 'counts' => $counts];
}

/** @return array<string, mixed> */
function fleet(DateTimeImmutable $sinceDay, int $sinceTs): array
{
    return [
        'types' => Db::all('SELECT COALESCE(shiptype, 0) AS shiptype, COUNT(*) AS vessels FROM vessel
            WHERE last_seen >= ? AND vclass NOT IN ' . INFRA . ' GROUP BY COALESCE(shiptype, 0)', [$sinceTs]),
        'flags' => Db::all('SELECT country, COUNT(*) AS vessels FROM vessel WHERE last_seen >= ? AND country IS NOT NULL
            AND vclass NOT IN ' . INFRA . ' GROUP BY country ORDER BY vessels DESC LIMIT 12', [$sinceTs]),
        'classes' => Db::all('SELECT vclass, COUNT(*) AS vessels FROM vessel WHERE last_seen >= ? GROUP BY vclass', [$sinceTs]),
    ];
}

/** @return array<string, mixed> */
function polar(DateTimeImmutable $sinceDay): array
{
    $period = array_fill(0, 36, 0.0);
    foreach (Db::all('SELECT sector, MAX(max_dist_nm) m FROM range_polar WHERE day >= ? GROUP BY sector', [$sinceDay->format('Y-m-d')]) as $r) {
        $period[(int) $r['sector']] = (float) $r['m'];
    }
    $all = array_fill(0, 36, 0.0);
    foreach (Db::all('SELECT sector, MAX(max_dist_nm) m FROM range_polar GROUP BY sector') as $r) {
        $all[(int) $r['sector']] = (float) $r['m'];
    }
    return ['period' => $period, 'all' => $all];
}

/** @return array<string, mixed> */
function vessel(int $mmsi): array
{
    $v = Db::one('SELECT mmsi, name, callsign, imo, eni, shiptype, vclass, length_m, beam_m, draught_m, country,
        destination, eta, first_seen, last_seen, msgs, passages, max_dist_nm, max_speed_kn, last_lat, last_lon,
        last_pos_ts, last_sog, last_cog, tags FROM vessel WHERE mmsi = ?', [$mmsi]);
    if ($v === null) {
        Web::json(['error' => 'not found'], 404);
    }
    $v['days_seen'] = (int) Db::value('SELECT COUNT(*) FROM vessel_daily WHERE mmsi = ?', [$mmsi]);
    $v['recent_passages'] = Db::all('SELECT start_ts, end_ts, entry_zone, exit_zone, max_dist_nm, moved_nm, closed
        FROM passage WHERE mmsi = ? ORDER BY start_ts DESC LIMIT 8', [$mmsi]);
    $lastPos = (int) ($v['last_pos_ts'] ?? 0);
    $trace = [];
    if ($lastPos > 0) {
        // Track of the latest passage, at most the last 24 h.
        $from = $lastPos - 86400;
        foreach ($v['recent_passages'] as $p) {
            if ((int) $p['start_ts'] <= $lastPos) {
                $from = max($from, (int) $p['start_ts']);
                break;
            }
        }
        $pts = Db::all('SELECT minute, lat, lon FROM position WHERE mmsi = ? AND minute >= ? ORDER BY minute', [
            $mmsi, intdiv($from, 60),
        ]);
        $step = max(1, (int) ceil(count($pts) / 600));
        foreach ($pts as $i => $p) {
            if ($i % $step === 0 || $i === count($pts) - 1) {
                $trace[] = [(float) $p['lat'], (float) $p['lon'], (int) $p['minute'] * 60];
            }
        }
    }
    $v['trace'] = $trace;
    $v['has_local_photo'] = (bool) Db::value('SELECT 1 FROM vessel_photo WHERE mmsi = ?', [$mmsi]);
    // Links only: these sites do not allow their photos to be fetched automatically.
    $v['links'] = [
        // No MarineTraffic link: its vessel pages use an internal id, and neither an MMSI URL nor its search accepts an MMSI.
        'aiscatcher' => 'https://www.aiscatcher.org/ship/details/' . $mmsi,
        'vesselfinder' => 'https://www.vesselfinder.com/vessels/details/' . ($v['imo'] ?: $mmsi),
        'shipspotting' => 'https://www.shipspotting.com/photos/gallery?' . ($v['imo'] ? 'search_imo=' . (int) $v['imo'] : 'search_mmsi=' . $mmsi),
    ];
    return $v;
}

/** Photo looked up separately so that the vessel card opens at once. @return array<string, mixed> */
function photo(int $mmsi): array
{
    $imo = Db::value('SELECT imo FROM vessel WHERE mmsi = ?', [$mmsi]);
    try {
        return ['photo' => Enrich::photo($mmsi, $imo !== null ? (int) $imo : null)];
    } catch (Throwable $e) {
        error_log('[aisseastats] photo lookup failed: ' . $e->getMessage());
        return ['photo' => null];
    }
}

/** @return array<int, array<string, mixed>> */
function search(string $s): array
{
    $s = trim($s);
    if (mb_strlen($s) < 2) {
        return [];
    }
    if (ctype_digit($s)) {
        return Db::all('SELECT mmsi, name, shiptype, country FROM vessel WHERE mmsi LIKE ? OR imo = ? ORDER BY last_seen DESC LIMIT 10', [$s . '%', (int) $s]);
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    return Db::all('SELECT mmsi, name, shiptype, country FROM vessel WHERE name LIKE ? ORDER BY last_seen DESC LIMIT 10', [$like]);
}

/**
 * Vessels behind one bar or row of the page: a ship type category, a flag,
 * a route (entry -> exit zone) or a declared destination, over the selected period.
 * @return array<string, mixed>
 */
function vesselList(string $by, DateTimeImmutable $sinceDay, int $sinceTs): array
{
    $cols = 'v.mmsi, v.name, v.shiptype, v.vclass, v.country, v.length_m, v.tags, v.last_seen';
    switch ($by) {
        case 'type':
            $cat = (string) ($_GET['value'] ?? '');
            if ($cat === 'other') {
                $known = implode(' OR ', array_map(static fn ($w) => '(' . $w . ')', array_values(TYPE_CATS)));
                $where = "NOT ({$known})";
            } elseif (isset(TYPE_CATS[$cat])) {
                $where = TYPE_CATS[$cat];
            } else {
                Web::json(['error' => 'unknown type'], 400);
            }
            $rows = Db::all("SELECT {$cols} FROM vessel v WHERE v.last_seen >= ? AND v.vclass NOT IN " . INFRA
                . " AND {$where} ORDER BY v.last_seen DESC LIMIT 300", [$sinceTs]);
            break;
        case 'flag':
            $cc = strtoupper((string) ($_GET['value'] ?? ''));
            if (!preg_match('/^[A-Z]{2}$/', $cc)) {
                Web::json(['error' => 'bad flag'], 400);
            }
            $rows = Db::all("SELECT {$cols} FROM vessel v WHERE v.last_seen >= ? AND v.vclass NOT IN " . INFRA
                . ' AND v.country = ? ORDER BY v.last_seen DESC LIMIT 300', [$sinceTs, $cc]);
            break;
        case 'route':
            $rows = Db::all("SELECT {$cols}, COUNT(*) AS passages, MAX(p.start_ts) AS last_passage
                FROM passage p JOIN vessel v ON v.mmsi = p.mmsi
                WHERE p.closed = 1 AND p.start_ts >= ? AND p.moved_nm >= 0.5 AND p.entry_zone = ? AND p.exit_zone = ?
                GROUP BY p.mmsi ORDER BY passages DESC, last_passage DESC LIMIT 300",
                [$sinceTs, mb_substr((string) ($_GET['from'] ?? ''), 0, 48), mb_substr((string) ($_GET['to'] ?? ''), 0, 48)]);
            break;
        case 'dest':
            $value = (string) ($_GET['value'] ?? '');
            $key = $value === Destinations::UNKNOWN ? $value : strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
            [$dkey, $dparams] = Destinations::sql();
            $rows = Db::all("SELECT {$cols}, v.destination FROM vessel v WHERE v.last_seen >= ? AND destination IS NOT NULL
                AND destination <> '' AND {$dkey} = ? ORDER BY v.last_seen DESC LIMIT 300", array_merge([$sinceTs], $dparams, [$key]));
            break;
        case 'hour':
            // One bar of the 48 h chart (hourly detail is kept 35 days).
            $h = (int) ($_GET['t'] ?? 0);
            if ($h <= 0 || $h % 3600 !== 0) {
                Web::json(['error' => 'bad hour'], 400);
            }
            $rows = Db::all("SELECT {$cols} FROM vessel_hourly h JOIN vessel v ON v.mmsi = h.mmsi
                WHERE h.hour_ts = ? ORDER BY v.name IS NULL, v.name, v.mmsi LIMIT 300", [$h]);
            break;
        case 'day':
        case 'month':
            // One bar of the daily or monthly chart.
            $value = (string) ($_GET['value'] ?? '');
            if ($by === 'day' ? !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) : !preg_match('/^\d{4}-\d{2}$/', $value)) {
                Web::json(['error' => 'bad date'], 400);
            }
            $first = $by === 'day' ? $value : $value . '-01';
            $last = $by === 'day' ? $value : date('Y-m-t', (int) strtotime($first . ' 00:00:00 UTC'));
            $rows = Db::all("SELECT {$cols}, SUM(d.msgs) AS msgs FROM vessel_daily d JOIN vessel v ON v.mmsi = d.mmsi
                WHERE d.day BETWEEN ? AND ? GROUP BY d.mmsi ORDER BY msgs DESC, v.mmsi LIMIT 300", [$first, $last]);
            break;
        default:
            Web::json(['error' => 'unknown list'], 400);
    }
    return ['by' => $by, 'rows' => $rows, 'truncated' => count($rows) >= 300];
}
