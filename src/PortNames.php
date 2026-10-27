<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Names of UN/LOCODE ports (1.2.0): "NLRTM" -> "Rotterdam", for destinations declared as a code.
 *
 * The list is not shipped with AISSeaStats: its licence is unclear (UNECE's terms say personal use without
 * redistribution, the same site says CC BY 4.0). The admin downloads it on demand from the station, from the
 * datasets/un-locode extract of UNECE's list; only ports (function 1) are kept. Names from the destination
 * dictionary always win.
 */
final class PortNames
{
    public const SOURCE = 'https://raw.githubusercontent.com/datasets/un-locode/main/data/code-list.csv';
    public const INFO = 'https://unlocode.unece.org/';
    private const MAX_BYTES = 30 * 1024 * 1024;
    private const MIN_PORTS = 1000;

    /** Download transport, replaceable in tests: fn(string $url): string. @var null|callable */
    public static $fetch = null;
    /** @var array<string, string|null> */
    private static array $cache = [];

    /** Name of a port code, or null. */
    public static function name(string $code): ?string
    {
        if (!preg_match('/^[A-Z]{2}[A-Z0-9]{3}$/', $code)) {
            return null;
        }
        if (!array_key_exists($code, self::$cache)) {
            try {
                $n = Db::value('SELECT name FROM port_name WHERE code = ?', [$code]);
                self::$cache[$code] = $n !== null ? (string) $n : null;
            } catch (\Throwable) {
                self::$cache[$code] = null; // table not created yet (migration pending)
            }
        }
        return self::$cache[$code];
    }

    /** @return array{count: int, ts: int} */
    public static function status(): array
    {
        $s = Settings::get('port_names');
        $count = 0;
        try {
            $count = (int) Db::value('SELECT COUNT(*) FROM port_name');
        } catch (\Throwable) {
        }
        return ['count' => $count, 'ts' => is_array($s) ? (int) ($s['ts'] ?? 0) : 0];
    }

    /**
     * Ports of a UN/LOCODE CSV (columns Country, Location, Name, Function…): code => name.
     * @return array<string, string>
     */
    public static function parse(string $csv): array
    {
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $csv);
        rewind($fp);
        $head = fgetcsv($fp, 0, ',', '"', '');
        $col = is_array($head) ? array_flip(array_map(static fn ($h) => trim((string) $h, "\u{FEFF} "), $head)) : [];
        foreach (['Country', 'Location', 'Name', 'Function'] as $c) {
            if (!isset($col[$c])) {
                fclose($fp);
                throw new \RuntimeException('unexpected file format (column ' . $c . ' missing)');
            }
        }
        $out = [];
        while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            $code = strtoupper(trim((string) ($r[$col['Country']] ?? '')) . trim((string) ($r[$col['Location']] ?? '')));
            $fn = (string) ($r[$col['Function']] ?? '');
            $name = trim(strip_tags((string) ($r[$col['Name']] ?? '')));
            if ($fn === '' || $fn[0] !== '1' || !preg_match('/^[A-Z]{2}[A-Z0-9]{3}$/', $code) || $name === '' || isset($out[$code])) {
                continue;
            }
            $out[$code] = mb_substr($name, 0, 64);
        }
        fclose($fp);
        return $out;
    }

    /** Download, check and store the list. Returns the number of ports. @throws \RuntimeException */
    public static function import(): int
    {
        $csv = self::download(self::SOURCE);
        $ports = self::parse($csv);
        if (count($ports) < self::MIN_PORTS) {
            throw new \RuntimeException('only ' . count($ports) . ' ports in the file: kept the current list');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM port_name');
            $rows = [];
            foreach ($ports as $code => $name) {
                $rows[] = [$code, $name];
            }
            Ingest::bulk('INSERT INTO port_name (code, name) VALUES %s', $rows, 1000);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Settings::set('port_names', ['ts' => time()]);
        self::$cache = [];
        return count($ports);
    }

    public static function clear(): void
    {
        Db::pdo()->exec('DELETE FROM port_name');
        Settings::set('port_names', null);
        self::$cache = [];
    }

    private static function download(string $url): string
    {
        if (self::$fetch !== null) {
            return (string) (self::$fetch)($url);
        }
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true, 'follow_location' => 1,
            'header' => ['User-Agent: AISSeaStats/' . VERSION]]]);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            $err = error_get_last();
            throw new \RuntimeException('cannot reach ' . (string) parse_url($url, PHP_URL_HOST)
                . ($err ? ' (' . preg_replace('/^.*: /', '', $err['message']) . ')' : ''));
        }
        $code = 0;
        foreach (stream_get_meta_data($fp)['wrapper_data'] ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $h, $m)) {
                $code = (int) $m[1];
            }
        }
        $body = (string) stream_get_contents($fp, self::MAX_BYTES + 1);
        fclose($fp);
        if ($code !== 200) {
            throw new \RuntimeException('HTTP ' . $code);
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('file too large');
        }
        return $body;
    }
}
