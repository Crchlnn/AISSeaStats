<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Debug capture written live to disk, for later analysis with grep or jq.
 *
 * One file per capture session: capture_YYYYMMDD_HHMMSS.jsonl (start date and time, station time zone).
 * One JSON line per event:
 *   {"event":"start",...} / {"event":"msg","ts","iso","mmsi","type","raw":{...}} / {"event":"stop",...}
 * Must never disturb ingestion: any write error is swallowed (and logged once).
 * Directory: DEBUG_LOG_DIR (Docker: a volume on /data/debug), else var/debug in the application folder.
 */
final class DebugLog
{
    private const FILE_RE = '/^capture_\d{8}_\d{6}(_\d+)?\.jsonl$/';
    private static bool $warned = false;

    public static function dir(): string
    {
        $dir = getenv('DEBUG_LOG_DIR') ?: dirname(__DIR__) . '/var/debug';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return rtrim($dir, '/');
    }

    /** Unique file name for a session starting at $ts. */
    public static function newName(int $ts): string
    {
        $base = 'capture_' . (new \DateTimeImmutable('@' . $ts))->setTimezone(Settings::timezone())->format('Ymd_His');
        $name = $base . '.jsonl';
        for ($i = 2; is_file(self::dir() . '/' . $name); $i++) {
            $name = $base . '_' . $i . '.jsonl';
        }
        return $name;
    }

    /** "msg" line: $json is the raw message as received (JSON). */
    public static function message(string $file, int $ts, int $mmsi, int $type, string $json): void
    {
        $raw = json_decode($json, true);
        self::write($file, [
            'event' => 'msg',
            'ts'    => $ts,
            'iso'   => self::iso($ts),
            'mmsi'  => $mmsi,
            'type'  => $type,
            'raw'   => $raw ?? $json,
        ]);
    }

    /** Event line (start / stop). @param array<string, mixed> $extra */
    public static function event(string $file, string $event, array $extra = []): void
    {
        self::write($file, ['event' => $event, 'ts' => time(), 'iso' => self::iso(time())] + $extra);
    }

    /** @param array<string, mixed> $line */
    private static function write(string $file, array $line): void
    {
        try {
            if (!preg_match(self::FILE_RE, $file)) {
                return;
            }
            $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false || @file_put_contents(self::dir() . '/' . $file, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('write failed in ' . self::dir());
            }
        } catch (\Throwable $e) {
            if (!self::$warned) {
                self::$warned = true;
                error_log('[aisseastats] debuglog: ' . $e->getMessage());
            }
        }
    }

    private static function iso(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(Settings::timezone())->format('Y-m-d\TH:i:sP');
    }

    /** @return list<array{name:string,size:int,mtime:int}> newest first */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/capture_*.jsonl') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_RE, $name)) {
                $out[] = ['name' => $name, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Safe path of a log file (no path traversal), or null. */
    public static function path(string $name): ?string
    {
        $name = basename($name);
        $path = self::dir() . '/' . $name;
        return preg_match(self::FILE_RE, $name) && is_file($path) ? $path : null;
    }

    public static function delete(string $name): bool
    {
        $p = self::path($name);
        return $p !== null && @unlink($p);
    }

    /** Delete logs older than $days days. */
    public static function purge(int $days = 30): void
    {
        foreach (self::list() as $f) {
            if ($f['mtime'] < time() - $days * 86400) {
                self::delete($f['name']);
            }
        }
    }
}
