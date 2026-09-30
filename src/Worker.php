<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Background jobs run every minute by bin/worker.php:
 * close passages and assign routes, tag interesting vessels,
 * roll up hourly/daily statistics and apply retention.
 */
final class Worker
{
    private int $lastPurge = 0;
    private int $lastFull = 0;
    private int $lastTags = 0;

    public function tick(?int $now = null): void
    {
        $now ??= time();
        Settings::flush();
        $full = $now - $this->lastFull >= 3600;
        $this->closePassages($now);
        $this->rollupHours($full ? $now - 35 * 86400 : $now - 3 * 3600);
        $this->rollupDays($full ? 40 : 2, $now);
        $this->updateTags($full || $this->rulesChanged() ? 0 : $this->lastTags - 120);
        $this->lastTags = $now;
        if ($now - $this->lastPurge >= 3600) {
            $this->purge($now);
            $this->lastPurge = $now;
        }
        if ($full) {
            $this->lastFull = $now;
        }
        Settings::set('worker_heartbeat', $now);
        // Alerts last, and isolated: a failing notification channel never stops the statistics.
        try {
            Alert::check($now);
        } catch (\Throwable $e) {
            error_log('[aisseastats] alerts: ' . $e->getMessage());
        }
    }

    public function closePassages(int $now): int
    {
        $gap = max(10, (int) Settings::get('passage_gap_min')) * 60;
        $zones = Db::all('SELECT name, lat, lon, radius_nm FROM zone ORDER BY radius_nm ASC');
        $hasStation = Settings::hasStation();
        $sLat = (float) Settings::get('station_lat');
        $sLon = (float) Settings::get('station_lon');
        $rows = Db::all('SELECT id, entry_lat, entry_lon, exit_lat, exit_lon, moved_nm FROM passage
                         WHERE closed = 0 AND end_ts < ? ORDER BY end_ts LIMIT 5000', [$now - $gap]);
        $st = Db::pdo()->prepare('UPDATE passage SET closed = 1, entry_zone = ?, exit_zone = ? WHERE id = ?');
        foreach ($rows as $p) {
            $in = self::zoneFor($p['entry_lat'], $p['entry_lon'], $zones, $hasStation, $sLat, $sLon);
            $out = self::zoneFor($p['exit_lat'], $p['exit_lon'], $zones, $hasStation, $sLat, $sLon);
            $st->execute([$in, $out, $p['id']]);
        }
        return count($rows);
    }

    /** Recompute zones of all closed passages (after zones are edited). */
    public static function reassignZones(): void
    {
        $zones = Db::all('SELECT name, lat, lon, radius_nm FROM zone ORDER BY radius_nm ASC');
        $hasStation = Settings::hasStation();
        $sLat = (float) Settings::get('station_lat');
        $sLon = (float) Settings::get('station_lon');
        $st = Db::pdo()->prepare('UPDATE passage SET entry_zone = ?, exit_zone = ? WHERE id = ?');
        $last = 0;
        while (true) {
            $rows = Db::all('SELECT id, entry_lat, entry_lon, exit_lat, exit_lon FROM passage
                             WHERE closed = 1 AND id > ? ORDER BY id LIMIT 2000', [$last]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $p) {
                $st->execute([
                    self::zoneFor($p['entry_lat'], $p['entry_lon'], $zones, $hasStation, $sLat, $sLon),
                    self::zoneFor($p['exit_lat'], $p['exit_lon'], $zones, $hasStation, $sLat, $sLon),
                    $p['id'],
                ]);
                $last = (int) $p['id'];
            }
        }
    }

    /** @param array<int, array<string, mixed>> $zones */
    public static function zoneFor(mixed $lat, mixed $lon, array $zones, bool $hasStation, float $sLat, float $sLon): ?string
    {
        if ($lat === null || $lon === null) {
            return null;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        foreach ($zones as $z) {
            if (Geo::distanceNm($lat, $lon, (float) $z['lat'], (float) $z['lon']) <= (float) $z['radius_nm']) {
                return (string) $z['name'];
            }
        }
        if (!$hasStation) {
            return null;
        }
        if (Geo::distanceNm($sLat, $sLon, $lat, $lon) < 0.3) {
            return 'STATION';
        }
        return Geo::sectorLabel(Geo::bearing($sLat, $sLon, $lat, $lon));
    }

    public function rollupHours(int $since): void
    {
        $since = intdiv($since, 3600) * 3600;
        Db::run('UPDATE stats_hourly h JOIN (
                    SELECT hour_ts, COUNT(*) c FROM vessel_hourly WHERE hour_ts >= ? GROUP BY hour_ts
                 ) x ON x.hour_ts = h.hour_ts SET h.vessels = x.c', [$since]);
    }

    public function rollupDays(int $days, int $now): void
    {
        $tz = Settings::timezone();
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $from = $today->modify('-' . $days . ' days')->format('Y-m-d');
        Db::run('INSERT INTO stats_daily (day, vessels, max_dist_nm)
                 SELECT day, COUNT(*), MAX(max_dist_nm) FROM vessel_daily WHERE day >= ? GROUP BY day
                 ON DUPLICATE KEY UPDATE vessels = VALUES(vessels), max_dist_nm = VALUES(max_dist_nm)', [$from]);
        Db::run('INSERT INTO stats_daily (day, msgs)
                 SELECT day, SUM(msgs) FROM msgtype_daily WHERE day >= ? GROUP BY day
                 ON DUPLICATE KEY UPDATE msgs = VALUES(msgs)', [$from]);
        $st = Db::pdo()->prepare('UPDATE stats_daily SET new_vessels = (
                SELECT COUNT(*) FROM vessel WHERE first_seen >= ? AND first_seen < ? AND vclass NOT IN (\'BASE\', \'ATON\')
            ) WHERE day = ?');
        for ($i = 0; $i <= $days; $i++) {
            $d = $today->modify('-' . $i . ' days');
            $st->execute([$d->getTimestamp(), $d->modify('+1 day')->getTimestamp(), $d->format('Y-m-d')]);
        }
    }

    private function rulesChanged(): bool
    {
        $ts = (int) Db::value("SELECT updated_at FROM setting WHERE name = 'rules'");
        return $ts >= $this->lastTags;
    }

    public function updateTags(int $since): int
    {
        $rules = (array) Settings::get('rules');
        $flags = [];
        foreach (Db::all("SELECT country, COUNT(*) c FROM vessel WHERE country IS NOT NULL
                          AND vclass NOT IN ('BASE', 'ATON') GROUP BY country") as $r) {
            $flags[(string) $r['country']] = (int) $r['c'];
        }
        $st = Db::pdo()->prepare('UPDATE vessel SET tags = ? WHERE mmsi = ?');
        $changed = 0;
        $last = -1;
        while (true) {
            $rows = Db::all("SELECT mmsi, name, shiptype, length_m, country, vclass, tags FROM vessel
                             WHERE last_seen >= ? AND mmsi > ? AND vclass NOT IN ('BASE', 'ATON')
                             ORDER BY mmsi LIMIT 2000", [max(0, $since), $last]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $v) {
                $tags = implode(',', Rules::tagsFor($v, $rules, $flags));
                if ($tags !== $v['tags']) {
                    $st->execute([$tags, $v['mmsi']]);
                    $changed++;
                }
                $last = (int) $v['mmsi'];
            }
        }
        return $changed;
    }

    public function purge(int $now): void
    {
        $days = max(1, (int) Settings::get('position_retention_days'));
        self::deleteChunked('DELETE FROM position WHERE minute < ? LIMIT 5000', [intdiv($now - $days * 86400, 60)]);
        self::deleteChunked('DELETE FROM vessel_hourly WHERE hour_ts < ? LIMIT 5000', [$now - 35 * 86400]);
        self::deleteChunked('DELETE FROM ingest_log WHERE ts < ? LIMIT 5000', [$now - 7 * 86400]);
        Debug::purge($now);
    }

    /** @param array<int, mixed> $params */
    private static function deleteChunked(string $sql, array $params): void
    {
        do {
            $n = Db::run($sql, $params)->rowCount();
        } while ($n >= 5000);
    }
}
