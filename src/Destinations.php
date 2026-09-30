<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Declared destinations are free text typed by the crew: "FRSML", "FR SML", "SAINT-MALO", "ST.MALO"…
 * They are grouped on a key made of letters and digits only, then:
 *  - meaningless values ("0", "Q", "NONE"…) become the "unknown" key "?";
 *  - the station owner's dictionary (admin page) maps several keys to one destination.
 */
final class Destinations
{
    public const UNKNOWN = '?';
    /** SQL key of vessel.destination: upper case, letters and digits only. */
    public const RAW_KEY = "REGEXP_REPLACE(UPPER(TRIM(destination)), '[^A-Z0-9]', '')";
    /** Keys treated as "not declared": 0 to 2 characters, only zeros, or a placeholder word. */
    private const JUNK_SQL = "'^(.{0,2}|0+|X+|NONE|UNKNOWN|NA|NIL|NULL|NOTAVAILABLE|TBA|TBN)$'";
    private const JUNK_PHP = '/^(.{0,2}|0+|X+|NONE|UNKNOWN|NA|NIL|NULL|NOTAVAILABLE|TBA|TBN)$/';
    public const MAX_ROWS = 100;
    public const MAX_ALIASES = 50;

    public static function key(string $raw): string
    {
        $k = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
        return preg_match(self::JUNK_PHP, $k) ? self::UNKNOWN : $k;
    }

    /**
     * Dictionary as saved in settings: [['to' => 'FRSML', 'from' => ['FR SML', 'SAINT-MALO']], …].
     * @return array<int, array{to: string, from: array<int, string>}>
     */
    public static function dictionary(): array
    {
        $rows = Settings::get('dest_aliases');
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Normalised mapping alias key => [canonical key, canonical label].
     * @param array<int, array{to: string, from: array<int, string>}>|null $dict
     * @return array<string, array{0: string, 1: string}>
     */
    public static function map(?array $dict = null): array
    {
        $map = [];
        foreach ($dict ?? self::dictionary() as $row) {
            $label = mb_strtoupper(trim((string) ($row['to'] ?? '')));
            $to = self::key($label);
            if ($to === self::UNKNOWN) {
                continue;
            }
            foreach ((array) ($row['from'] ?? []) as $alias) {
                $k = self::key((string) $alias);
                if ($k !== self::UNKNOWN && $k !== $to && !isset($map[$k])) {
                    $map[$k] = [$to, $label];
                }
            }
            $map[$to] ??= [$to, $label];
        }
        return $map;
    }

    /** Canonical key of one raw destination (PHP twin of sql()). */
    public static function canonical(string $raw, ?array $map = null): string
    {
        $k = self::key($raw);
        if ($k === self::UNKNOWN) {
            return $k;
        }
        $map ??= self::map();
        return $map[$k][0] ?? $k;
    }

    /**
     * SQL expression giving the canonical key of vessel.destination, with its parameters.
     * @return array{0: string, 1: array<int, string>}
     */
    public static function sql(?array $map = null): array
    {
        $map ??= self::map();
        $sql = 'CASE WHEN ' . self::RAW_KEY . ' REGEXP ' . self::JUNK_SQL . " THEN '" . self::UNKNOWN . "'";
        $params = [];
        $byTarget = [];
        foreach ($map as $from => [$to]) {
            if ($from !== $to) {
                $byTarget[$to][] = $from;
            }
        }
        foreach ($byTarget as $to => $froms) {
            $sql .= ' WHEN ' . self::RAW_KEY . ' IN (' . implode(',', array_fill(0, count($froms), '?')) . ') THEN ?';
            foreach ($froms as $f) {
                $params[] = (string) $f;
            }
            $params[] = (string) $to;
        }
        return [$sql . ' ELSE ' . self::RAW_KEY . ' END', $params];
    }

    /** Label shown for a canonical key: dictionary label, UN/LOCODE as is, else the most common raw text. */
    public static function label(string $key, string $raw, ?array $map = null): string
    {
        if ($key === self::UNKNOWN) {
            return I18n::t('dest.unknown');
        }
        $map ??= self::map();
        if (isset($map[$key])) {
            return $map[$key][1];
        }
        return preg_match('/^[A-Z]{2}[A-Z0-9]{3}$/', $key) ? $key : $raw;
    }

    /**
     * Parse the admin form: parallel arrays of "aliases, comma separated" and "keep as".
     * @param array<int, mixed> $froms
     * @param array<int, mixed> $tos
     * @return array<int, array{to: string, from: array<int, string>}>
     */
    public static function parseForm(array $froms, array $tos): array
    {
        $rows = [];
        foreach ($tos as $i => $to) {
            $to = mb_substr(trim(strip_tags((string) $to)), 0, 32);
            $aliases = [];
            foreach (preg_split('/[,;\n]+/', (string) ($froms[$i] ?? '')) ?: [] as $a) {
                $a = mb_substr(trim(strip_tags($a)), 0, 32);
                if ($a !== '' && self::key($a) !== self::UNKNOWN) {
                    $aliases[] = $a;
                }
            }
            if ($to === '' || self::key($to) === self::UNKNOWN || $aliases === []) {
                continue;
            }
            $rows[] = ['to' => mb_strtoupper($to), 'from' => array_slice(array_values(array_unique($aliases)), 0, self::MAX_ALIASES)];
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }
}
