<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Key/value application settings stored in the `setting` table.
 * Values are JSON-encoded so that arrays and booleans round-trip.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'setup_done' => false,
            'station_name' => 'AIS station',
            'station_lat' => null,
            'station_lon' => null,
            'timezone' => Db::env('TZ', 'Europe/Paris'),
            'lang' => 'auto',
            'max_range_nm' => 200,
            'passage_gap_min' => 120,
            'position_retention_days' => 30,
            'enrich_enabled' => true,
            'map_tiles' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'map_attribution' => '© OpenStreetMap contributors',
            'admin_hash' => '',
            'ingest_token_hash' => '',
            'rules' => Rules::defaults(),
        ];
    }

    public static function get(string $name): mixed
    {
        $all = self::all();
        return $all[$name] ?? (self::defaults()[$name] ?? null);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $values = self::defaults();
            foreach (Db::all('SELECT name, value FROM setting') as $row) {
                $values[$row['name']] = json_decode((string) $row['value'], true);
            }
            if (!is_array($values['rules'])) {
                $values['rules'] = Rules::defaults();
            } else {
                $values['rules'] = array_merge(Rules::defaults(), $values['rules']);
            }
            self::$cache = $values;
        }
        return self::$cache;
    }

    public static function set(string $name, mixed $value): void
    {
        Db::run(
            'INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) self::get('timezone'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    public static function hasStation(): bool
    {
        return is_numeric(self::get('station_lat')) && is_numeric(self::get('station_lon'));
    }

    /** Local calendar day (Y-m-d) of a UTC timestamp, in the station time zone. */
    public static function localDay(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::timezone())->format('Y-m-d');
    }
}
