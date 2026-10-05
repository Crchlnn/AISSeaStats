<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Journal fichier de la capture debug, écrit au fil de l'eau.
 *
 * Un fichier par session de capture : capture_YYYYMMDD_HHMMSS.jsonl (date/heure du démarrage).
 * Une ligne JSON par événement :
 *   {"event":"start",...} / {"event":"msg","ts","iso","mmsi","type","raw":{...}} / {"event":"stop",...}
 * Ne doit jamais perturber l'ingestion : toute erreur d'écriture est absorbée (journalisée une fois).
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

    /** Nom (unique) du fichier d'une session démarrant à $ts. */
    public static function newName(int $ts): string
    {
        $base = 'capture_' . (new \DateTimeImmutable('@' . $ts))->setTimezone(Settings::timezone())->format('Ymd_His');
        $name = $base . '.jsonl';
        for ($i = 2; is_file(self::dir() . '/' . $name); $i++) {
            $name = $base . '_' . $i . '.jsonl';
        }
        return $name;
    }

    /** Ligne "message" : $json est le message brut tel que reçu (JSON). */
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

    /** Ligne d'événement (start / stop). @param array<string, mixed> $extra */
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

    /** @return list<array{name:string,size:int,mtime:int}> du plus récent au plus ancien */
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

    /** Chemin sûr (anti path-traversal) ou null. */
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

    /** Supprime les journaux de plus de $days jours. */
    public static function purge(int $days = 30): void
    {
        foreach (self::list() as $f) {
            if ($f['mtime'] < time() - $days * 86400) {
                self::delete($f['name']);
            }
        }
    }
}
