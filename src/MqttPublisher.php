<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * MQTT and Home Assistant (1.2.0).
 * - <prefix>/<station>/state        live figures (JSON, retained), every minute
 * - <prefix>/<station>/availability "online" / "offline" (retained; "offline" is the last will of the connection)
 * - <prefix>/<station>/event        alerts and summaries (JSON, not retained)
 * - <discovery>/sensor|binary_sensor/aisseastats_<node>/<key>/config  Home Assistant discovery (retained)
 * The worker keeps one connection open; the admin test opens a short one. No message per vessel (decision 21/10/2026).
 */
final class MqttPublisher
{
    /** Discovery is published again every hour (Home Assistant restarted without a retained copy, broker reset…). */
    private const DISCOVERY_EVERY_S = 3600;
    /** A sensor becomes "unavailable" in Home Assistant when its state is older than this. */
    private const EXPIRE_AFTER_S = 600;

    /** The worker's publisher, used to send events on its open connection. */
    public static ?self $current = null;
    /** Connection factory, replaceable in tests. @var null|callable(): Mqtt */
    public static $factory = null;

    private ?Mqtt $mqtt = null;
    private string $cfgHash = '';
    private int $discoveredAt = 0;
    private ?string $lastError = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['enabled' => false, 'host' => '', 'port' => 1883, 'tls' => false, 'tls_verify' => true, 'user' => '', 'pass' => '',
            'prefix' => 'aisseastats', 'discovery' => true, 'discovery_prefix' => 'homeassistant'];
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        $c = Settings::get('mqtt');
        return array_merge(self::defaults(), is_array($c) ? $c : []);
    }

    /** @param array<string, mixed> $c */
    public static function ready(array $c): bool
    {
        return (bool) $c['enabled'] && $c['host'] !== '';
    }

    /** Stable id of this installation (topics and Home Assistant unique ids). */
    public static function nodeId(): string
    {
        $id = Settings::get('mqtt_node');
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}$/', $id)) {
            $id = bin2hex(random_bytes(4));
            Settings::set('mqtt_node', $id);
        }
        return $id;
    }

    /** Topic part for the station: its name in lower case letters, digits, "-" and "_" (or the node id). */
    public static function stationSlug(): string
    {
        // Accents removed by a table: iconv's transliteration does not work on Alpine (musl).
        $ascii = strtr(mb_strtolower((string) Settings::get('station_name')), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
            'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'ß' => 'ss',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        return $slug !== '' ? substr($slug, 0, 40) : self::nodeId();
    }

    /** @param array<string, mixed> $c */
    public static function base(array $c): string
    {
        return trim((string) $c['prefix'], '/') . '/' . self::stationSlug();
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private static function connectCfg(array $c, bool $withWill): array
    {
        return [
            'host' => (string) $c['host'], 'port' => (int) $c['port'], 'tls' => (bool) $c['tls'], 'tls_verify' => (bool) $c['tls_verify'],
            'user' => (string) $c['user'], 'pass' => (string) $c['pass'], 'keepalive' => 180,
            'client_id' => 'aisseastats-' . self::nodeId() . ($withWill ? '' : '-' . bin2hex(random_bytes(2))),
            'will' => $withWill ? ['topic' => self::base($c) . '/availability', 'payload' => 'offline', 'retain' => true] : null,
        ];
    }

    private static function newClient(): Mqtt
    {
        return self::$factory !== null ? (self::$factory)() : new Mqtt();
    }

    /** Worker, every minute: keep the connection, publish discovery (on connect and hourly) and the live state. */
    public function tick(int $now): void
    {
        $c = self::config();
        if (!self::ready($c)) {
            if ($this->mqtt !== null) {
                $this->goOffline();
            }
            return;
        }
        $hash = md5((string) json_encode([$c, self::stationSlug()]));
        try {
            if ($this->mqtt === null || !$this->mqtt->connected() || $hash !== $this->cfgHash) {
                if ($this->mqtt !== null) {
                    $this->goOffline();
                }
                $this->mqtt = self::newClient();
                $this->mqtt->connect(self::connectCfg($c, true));
                $this->cfgHash = $hash;
                $this->discoveredAt = 0;
                $this->mqtt->publish(self::base($c) . '/availability', 'online', true, 1);
            }
            if ($c['discovery'] && $now - $this->discoveredAt >= self::DISCOVERY_EVERY_S) {
                foreach (self::discovery($c) as $topic => $payload) {
                    $this->mqtt->publish($topic, $payload, true);
                }
                $this->discoveredAt = $now;
            }
            $state = Stats::live($now, Settings::timezone());
            $this->mqtt->publish(self::base($c) . '/state', (string) json_encode($state, JSON_UNESCAPED_UNICODE), true);
            $this->status($now, null);
        } catch (\Throwable $e) {
            $this->mqtt?->close();
            $this->mqtt = null;
            $this->status($now, $e->getMessage());
            throw $e;
        }
    }

    /** Settings changed or MQTT turned off: say "offline" ourselves, then disconnect cleanly. */
    private function goOffline(): void
    {
        try {
            $c = self::config();
            $this->mqtt?->publish(self::base($c) . '/availability', 'offline', true, 1);
            $this->mqtt?->disconnect();
        } catch (\Throwable) {
            $this->mqtt?->close();
        }
        $this->mqtt = null;
    }

    /** Last result, shown in the admin. */
    private function status(int $now, ?string $error): void
    {
        $msg = $error === null ? '' : mb_substr($error, 0, 200);
        if ($msg !== $this->lastError || $error === null && $now % 600 < 60) {
            Settings::set('mqtt_status', ['ts' => $now, 'error' => $error === null ? null : $msg]);
            $this->lastError = $msg;
        }
    }

    /**
     * An event (alert or summary) on <base>/event, QoS 1. In the worker it uses the open connection;
     * elsewhere (admin test) a short one.
     * @param array<string, mixed> $payload
     * @throws \RuntimeException
     */
    public static function event(array $payload): void
    {
        $c = self::config();
        if (!self::ready($c)) {
            throw new \RuntimeException('MQTT not configured');
        }
        $json = (string) json_encode($payload + ['station' => (string) Settings::get('station_name'), 'ts' => time()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $w = self::$current;
        if ($w !== null && $w->mqtt !== null && $w->mqtt->connected()) {
            $w->mqtt->publish(self::base($c) . '/event', $json, false, 1);
            return;
        }
        $m = self::newClient();
        $m->connect(self::connectCfg($c, false));
        try {
            $m->publish(self::base($c) . '/event', $json, false, 1);
        } finally {
            $m->disconnect();
        }
    }

    /**
     * Admin "test": connect with the given settings and publish a test event. Returns null on success, else the error.
     * @param array<string, mixed> $c
     */
    public static function test(array $c): ?string
    {
        try {
            $m = self::newClient();
            $m->connect(self::connectCfg($c, false));
            $m->publish(self::base($c) . '/event', (string) json_encode(['event' => 'test', 'kind' => 'test',
                'title' => I18n::t('mqtt.test_title'), 'station' => (string) Settings::get('station_name'), 'ts' => time()],
                JSON_UNESCAPED_UNICODE), false, 1);
            $m->disconnect();
            return null;
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300);
        }
    }

    /**
     * Home Assistant discovery messages, topic => JSON.
     * @param array<string, mixed> $c
     * @return array<string, string>
     */
    public static function discovery(array $c): array
    {
        $node = self::nodeId();
        $base = self::base($c);
        $dp = trim((string) $c['discovery_prefix'], '/');
        $url = trim((string) Settings::get('public_url'));
        $device = ['identifiers' => ['aisseastats_' . $node], 'name' => 'AISSeaStats – ' . (string) Settings::get('station_name'),
            'manufacturer' => 'AISSeaStats', 'model' => 'AIS statistics', 'sw_version' => VERSION];
        if ($url !== '') {
            $device['configuration_url'] = $url;
        }
        // key => [component, icon, unit, extra]
        $sensors = [
            'vessels_today' => ['sensor', 'mdi:ferry', null, ['state_class' => 'measurement']],
            'active_now' => ['sensor', 'mdi:radar', null, ['state_class' => 'measurement']],
            'new_today' => ['sensor', 'mdi:new-box', null, ['state_class' => 'measurement']],
            'msgs_today' => ['sensor', 'mdi:message-processing', null, ['state_class' => 'total_increasing']],
            'msgs_hour' => ['sensor', 'mdi:message-fast', null, ['state_class' => 'measurement']],
            'range_today' => ['sensor', 'mdi:signal-distance-variant', 'NM', ['state_class' => 'measurement']],
            'range_record' => ['sensor', 'mdi:trophy', 'NM', []],
            'last_message_age' => ['sensor', 'mdi:timer-sand', 's', ['device_class' => 'duration', 'state_class' => 'measurement']],
            'reception' => ['binary_sensor', null, null, ['device_class' => 'connectivity', 'payload_on' => 'ON', 'payload_off' => 'OFF']],
        ];
        $out = [];
        foreach ($sensors as $key => [$comp, $icon, $unit, $extra]) {
            $cfg = [
                'name' => I18n::t('mqtt.s.' . $key),
                'unique_id' => 'aisseastats_' . $node . '_' . $key,
                'state_topic' => $base . '/state',
                'value_template' => '{{ value_json.' . $key . ' }}',
                'availability_topic' => $base . '/availability',
                'expire_after' => self::EXPIRE_AFTER_S,
                'device' => $device,
            ] + $extra;
            if ($icon !== null) {
                $cfg['icon'] = $icon;
            }
            if ($unit !== null) {
                $cfg['unit_of_measurement'] = $unit;
            }
            $out[$dp . '/' . $comp . '/aisseastats_' . $node . '/' . $key . '/config'] = (string) json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $out;
    }

    /**
     * Settings from the admin form; the password is kept when its field is left empty.
     * @param array<string, mixed> $p
     * @param array<string, mixed> $old
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with the name of the invalid field
     */
    public static function fromForm(array $p, array $old): array
    {
        $str = static fn (string $k, int $max = 120): string => mb_substr(trim(str_replace(["\r", "\n"], '', (string) ($p[$k] ?? ''))), 0, $max);
        $c = self::defaults();
        $c['enabled'] = isset($p['mqtt_enabled']);
        $c['host'] = $str('mqtt_host', 253);
        $c['tls'] = isset($p['mqtt_tls']);
        $c['tls_verify'] = isset($p['mqtt_tls_verify']);
        $c['port'] = (int) ($p['mqtt_port'] ?? 0) > 0 ? max(1, min(65535, (int) $p['mqtt_port'])) : ($c['tls'] ? 8883 : 1883);
        $c['user'] = $str('mqtt_user');
        $c['pass'] = isset($p['mqtt_pass_clear']) ? '' : ($str('mqtt_pass', 300) !== '' ? $str('mqtt_pass', 300) : (string) ($old['pass'] ?? ''));
        $c['prefix'] = $str('mqtt_prefix', 60) !== '' ? $str('mqtt_prefix', 60) : 'aisseastats';
        $c['discovery'] = isset($p['mqtt_discovery']);
        $c['discovery_prefix'] = $str('mqtt_discovery_prefix', 60) !== '' ? $str('mqtt_discovery_prefix', 60) : 'homeassistant';
        if ($c['host'] !== '' && !Mqtt::validHost($c['host'])) {
            throw new \InvalidArgumentException('mqtt_host');
        }
        foreach (['prefix', 'discovery_prefix'] as $k) {
            if (!preg_match('~^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$~', $c[$k])) {
                throw new \InvalidArgumentException('mqtt_' . $k);
            }
        }
        if ($c['enabled'] && $c['host'] === '') {
            throw new \InvalidArgumentException('mqtt_host');
        }
        return $c;
    }
}
