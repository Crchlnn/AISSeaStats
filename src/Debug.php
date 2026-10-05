<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Debug capture: keeps the raw messages received from AIS-catcher for a few vessels
 * (or all of them, briefly), to understand a missing name or type. Stops by itself.
 */
final class Debug
{
    public const MAX_MMSI = 20;
    public const MAX_ROWS = 20000;
    public const KEEP_DAYS = 7;
    /** Durations offered in the admin, in hours; capturing every vessel is limited to 1 hour. */
    public const DURATIONS = [1, 6, 24];

    /** @return array{mmsi: array<int, int>, until: int, started: int, logfile: string} */
    public static function config(): array
    {
        $c = Settings::get('debug_capture');
        $c = is_array($c) ? $c : [];
        return [
            'mmsi' => array_values(array_map('intval', (array) ($c['mmsi'] ?? []))),
            'until' => (int) ($c['until'] ?? 0),
            'started' => (int) ($c['started'] ?? 0),
            'logfile' => preg_match('/^capture_[\d_]+\.jsonl$/', (string) ($c['logfile'] ?? '')) ? (string) $c['logfile'] : '',
        ];
    }

    public static function active(?array $c = null, ?int $now = null): bool
    {
        $c ??= self::config();
        return $c['until'] > ($now ?? time());
    }

    /** @return array<int, int> */
    public static function parseMmsi(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $x) {
            if (preg_match('/^\d{9}$/', $x)) {
                $out[] = (int) $x;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, self::MAX_MMSI);
    }

    /** @param array<int, int> $mmsi */
    public static function start(array $mmsi, int $hours, int $now): void
    {
        $hours = in_array($hours, self::DURATIONS, true) ? $hours : 1;
        if ($mmsi === []) {
            $hours = 1;
        }
        $logfile = DebugLog::newName($now);
        Settings::set('debug_capture', ['mmsi' => $mmsi, 'until' => $now + $hours * 3600, 'started' => $now, 'logfile' => $logfile]);
        DebugLog::event($logfile, 'start', ['mmsi_filter' => $mmsi, 'hours' => $hours]);
    }

    public static function stop(): void
    {
        $c = self::config();
        if ($c['logfile'] !== '' && self::active($c)) {
            DebugLog::event($c['logfile'], 'stop', ['reason' => 'manual']);
        }
        Settings::set('debug_capture', array_merge($c, ['until' => 0]));
    }

    public static function clear(): void
    {
        Db::pdo()->exec('TRUNCATE TABLE debug_msg');
    }

    /**
     * Raw message as stored: our internal keys removed, size capped. Also copied to the dated log file.
     * $mmsi and $type are the values already decoded by the caller (fallback: the message's own keys).
     * @param array<string, mixed> $m
     */
    public static function encode(array $m, ?int $mmsi = null, ?int $type = null): string
    {
        $ts = (int) ($m['_ts'] ?? 0);
        unset($m['_ts']);
        $json = (string) json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        self::mirror($m, $json, $ts, $mmsi, $type);
        return strlen($json) > 4000 ? substr($json, 0, 4000) . '…' : $json;
    }

    /**
     * Copy of a captured message to the dated log file (written as it arrives, untruncated).
     * Same rules as the database capture: only while active, and only for the chosen MMSI.
     * @param array<string, mixed> $m
     */
    private static function mirror(array $m, string $json, int $ts, ?int $mmsi, ?int $type): void
    {
        $c = self::config();
        if ($c['logfile'] === '' || !self::active($c)) {
            return;
        }
        $mmsi ??= (int) ($m['mmsi'] ?? 0);
        if ($c['mmsi'] !== [] && !in_array($mmsi, $c['mmsi'], true)) {
            return;
        }
        DebugLog::message($c['logfile'], $ts > 0 ? $ts : time(), $mmsi, $type ?? (int) ($m['type'] ?? 0), $json);
    }

    /** Keep the table small: a week at most, and the newest rows only. */
    public static function purge(int $now): void
    {
        Db::run('DELETE FROM debug_msg WHERE ts < ?', [$now - self::KEEP_DAYS * 86400]);
        $cut = Db::value('SELECT id FROM debug_msg ORDER BY id DESC LIMIT 1 OFFSET ' . self::MAX_ROWS);
        if ($cut !== null) {
            Db::run('DELETE FROM debug_msg WHERE id <= ?', [(int) $cut]);
        }
    }

    /**
     * Messages per vessel and type, to see at a glance what a vessel sends.
     * Type 8 is split by application (DAC/FID), e.g. "8 200/10" for Inland AIS.
     * @return array<int, array{mmsi: int, name: ?string, total: int, types: array<string, int>, last: int}>
     */
    public static function summary(): array
    {
        $out = [];
        foreach (Db::all("SELECT d.mmsi, d.type,
                CASE WHEN d.type = 8 THEN CONCAT(JSON_VALUE(d.raw, '$.dac'), '/', JSON_VALUE(d.raw, '$.fid')) ELSE NULL END AS app,
                COUNT(*) AS n, MAX(d.ts) AS last, MAX(v.name) AS name
            FROM debug_msg d LEFT JOIN vessel v ON v.mmsi = d.mmsi
            GROUP BY d.mmsi, d.type, app ORDER BY d.mmsi, d.type") as $r) {
            $k = (int) $r['mmsi'];
            $out[$k] ??= ['mmsi' => $k, 'name' => $r['name'], 'total' => 0, 'types' => [], 'last' => 0];
            $label = (string) $r['type'] . ($r['app'] !== null && $r['app'] !== '' ? ' ' . $r['app'] : '');
            $out[$k]['types'][$label] = (int) $r['n'];
            $out[$k]['total'] += (int) $r['n'];
            $out[$k]['last'] = max($out[$k]['last'], (int) $r['last']);
        }
        return array_values($out);
    }
}
