<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * "No data" alerts: when no AIS message has been received for N minutes, one alert is sent
 * to every configured channel (ntfy, Telegram, webhook, e-mail); when messages come back,
 * one "reception restored" message follows. An optional heartbeat URL (Uptime Kuma,
 * healthchecks.io…) is called while reception is fine, to detect a station that is off.
 */
final class Alert
{
    public const CHANNELS = ['ntfy', 'telegram', 'webhook', 'email'];
    private const RETRY_S = 600;
    private const HEARTBEAT_S = 300;

    /**
     * HTTP transport, replaceable in tests: fn(string $url, string $body, array $headers, string $method): array{0: int, 1: string}.
     * @var null|callable
     */
    public static $http = null;
    /** @var null|callable E-mail transport for tests: fn(array $cfg, string $subject, string $body): void */
    public static $mailer = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'after_min' => 30,
            'lang' => 'auto',
            'ntfy' => ['url' => 'https://ntfy.sh', 'topic' => '', 'token' => ''],
            'telegram' => ['token' => '', 'chat' => ''],
            'webhook' => ['url' => ''],
            'email' => ['host' => '', 'port' => 587, 'security' => 'starttls', 'user' => '', 'pass' => '', 'from' => '', 'to' => ''],
            'heartbeat_url' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        $cfg = Settings::get('alerts');
        $d = self::defaults();
        if (!is_array($cfg)) {
            return $d;
        }
        foreach ($d as $k => $v) {
            $cfg[$k] = is_array($v) ? array_merge($v, is_array($cfg[$k] ?? null) ? $cfg[$k] : []) : ($cfg[$k] ?? $v);
        }
        return $cfg;
    }

    /** Channels with enough settings to send. @param array<string, mixed> $cfg @return array<int, string> */
    public static function configured(array $cfg): array
    {
        $out = [];
        if ($cfg['ntfy']['topic'] !== '' && $cfg['ntfy']['url'] !== '') {
            $out[] = 'ntfy';
        }
        if ($cfg['telegram']['token'] !== '' && $cfg['telegram']['chat'] !== '') {
            $out[] = 'telegram';
        }
        if ($cfg['webhook']['url'] !== '') {
            $out[] = 'webhook';
        }
        if ($cfg['email']['host'] !== '' && $cfg['email']['from'] !== '' && $cfg['email']['to'] !== '') {
            $out[] = 'email';
        }
        return $out;
    }

    /** @return array{down: bool, since: int, alerted: int, retry: int, heartbeat: int, results: array<string, mixed>, last_event: string, last_ts: int} */
    public static function state(): array
    {
        $s = Settings::get('alert_state');
        return array_merge(['down' => false, 'since' => 0, 'alerted' => 0, 'retry' => 0, 'heartbeat' => 0,
            'results' => [], 'last_event' => '', 'last_ts' => 0], is_array($s) ? $s : []);
    }

    /** Time of the last AIS message received (same source as the status line of the page). */
    public static function lastMessage(): int
    {
        return (int) Db::value('SELECT MAX(last_seen) FROM vessel');
    }

    /** Called by the worker every minute. Returns the event sent ('down', 'up') or null. */
    public static function check(int $now): ?string
    {
        $cfg = self::config();
        $state = self::state();
        if (!$cfg['enabled']) {
            if ($state['down']) {
                Settings::set('alert_state', array_merge($state, ['down' => false, 'retry' => 0]));
            }
            return null;
        }
        $last = self::lastMessage();
        if ($last === 0) {
            return null; // nothing received yet: a new station is not "down"
        }
        $threshold = max(5, min(1440, (int) $cfg['after_min'])) * 60;
        $silent = $now - $last;
        $event = null;

        if (!$state['down'] && $silent >= $threshold && $now >= $state['retry']) {
            $results = self::send($cfg, 'down', $last, $now);
            $ok = in_array(null, $results, true);
            $state = array_merge($state, ['down' => $ok, 'since' => $last, 'alerted' => $now,
                'retry' => $ok ? 0 : $now + self::RETRY_S, 'results' => $results, 'last_event' => 'down', 'last_ts' => $now]);
            Settings::set('alert_state', $state);
            $event = 'down';
        } elseif ($state['down'] && $last > $state['since']) {
            $results = self::send($cfg, 'up', $last, $now, $state['since']);
            $state = array_merge($state, ['down' => false, 'retry' => 0, 'results' => $results, 'last_event' => 'up', 'last_ts' => $now]);
            Settings::set('alert_state', $state);
            $event = 'up';
        }

        $hb = trim((string) $cfg['heartbeat_url']);
        if ($hb !== '' && $silent < $threshold && $now - $state['heartbeat'] >= self::HEARTBEAT_S) {
            try {
                self::http($hb, '', [], 'GET');
            } catch (\Throwable $e) {
                error_log('[aisseastats] heartbeat: ' . $e->getMessage());
            }
            $state['heartbeat'] = $now;
            Settings::set('alert_state', $state);
        }
        return $event;
    }

    /** Send a test message to every configured channel. @return array<string, string|null> channel => error */
    public static function test(): array
    {
        $cfg = self::config();
        $now = time();
        return self::send($cfg, 'test', self::lastMessage(), $now);
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array<string, string|null> channel => null on success, error text otherwise
     */
    public static function send(array $cfg, string $event, int $last, int $now, int $since = 0): array
    {
        if ($cfg['lang'] !== 'auto') {
            I18n::setLang((string) $cfg['lang']);
        }
        [$title, $text] = self::compose($event, $last, $now, $since);
        $priority = $event === 'down' ? 4 : 3;
        $results = [];
        foreach (self::configured($cfg) as $ch) {
            try {
                match ($ch) {
                    'ntfy' => self::ntfy($cfg['ntfy'], $title, $text, $priority, $event),
                    'telegram' => self::telegram($cfg['telegram'], $title, $text),
                    'webhook' => self::webhook($cfg['webhook'], $title, $text, $priority, $event, $last, $since),
                    'email' => self::email($cfg['email'], $title, $text),
                };
                $results[$ch] = null;
            } catch (\Throwable $e) {
                $results[$ch] = mb_substr($e->getMessage(), 0, 300);
                error_log('[aisseastats] alert ' . $ch . ': ' . $e->getMessage());
            }
        }
        return $results;
    }

    /** @return array{0: string, 1: string} title, text */
    public static function compose(string $event, int $last, int $now, int $since = 0): array
    {
        $station = (string) Settings::get('station_name');
        $at = $last > 0 ? (new \DateTimeImmutable('@' . $last))->setTimezone(Settings::timezone())->format('d/m/Y H:i') : '–';
        return match ($event) {
            'down' => [I18n::t('alert.down_title', ['s' => $station]),
                I18n::t('alert.down_text', ['s' => $station, 'd' => self::duration($now - $last), 't' => $at])],
            'up' => [I18n::t('alert.up_title', ['s' => $station]),
                I18n::t('alert.up_text', ['s' => $station, 'd' => self::duration(max(0, $last - $since))])],
            default => [I18n::t('alert.test_title', ['s' => $station]),
                I18n::t('alert.test_text', ['s' => $station, 't' => $at])],
        };
    }

    public static function duration(int $s): string
    {
        $m = intdiv($s, 60);
        if ($m < 60) {
            return I18n::t('time.minutes', ['n' => max(1, $m)]);
        }
        if ($m < 2880) {
            return sprintf('%d h %02d', intdiv($m, 60), $m % 60);
        }
        return I18n::t('time.days', ['n' => intdiv($m, 1440)]);
    }

    /** @param array<string, string> $c */
    private static function ntfy(array $c, string $title, string $text, int $priority, string $event): void
    {
        // JSON publishing: UTF-8 title and text without header encoding issues.
        $headers = ['Content-Type: application/json'];
        if ($c['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $c['token'];
        }
        $body = json_encode(['topic' => $c['topic'], 'title' => $title, 'message' => $text, 'priority' => $priority,
            'tags' => [$event === 'down' ? 'warning' : ($event === 'up' ? 'white_check_mark' : 'bell')]], JSON_UNESCAPED_UNICODE);
        self::expectOk(self::http(rtrim($c['url'], '/') . '/', (string) $body, $headers));
    }

    /** @param array<string, string> $c */
    private static function telegram(array $c, string $title, string $text): void
    {
        $body = json_encode(['chat_id' => $c['chat'], 'text' => $title . "\n" . $text, 'disable_web_page_preview' => true], JSON_UNESCAPED_UNICODE);
        [$code, $resp] = self::http('https://api.telegram.org/bot' . $c['token'] . '/sendMessage', (string) $body, ['Content-Type: application/json']);
        $j = json_decode($resp, true);
        if ($code !== 200 || !is_array($j) || ($j['ok'] ?? false) !== true) {
            throw new \RuntimeException(is_array($j) && isset($j['description']) ? (string) $j['description'] : 'HTTP ' . $code);
        }
    }

    /** @param array<string, string> $c */
    private static function webhook(array $c, string $title, string $text, int $priority, string $event, int $last, int $since): void
    {
        // One payload readable by the common receivers: Slack/Mattermost ("text"), Discord ("content"),
        // Gotify ("title", "message", "priority"), Home Assistant and n8n (any JSON).
        $full = $title . "\n" . $text;
        $body = json_encode([
            'event' => $event, 'station' => (string) Settings::get('station_name'),
            'title' => $title, 'message' => $text, 'text' => $full, 'content' => $full, 'priority' => $priority,
            'last_message' => $last ?: null, 'down_since' => $since ?: null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::expectOk(self::http($c['url'], (string) $body, ['Content-Type: application/json']));
    }

    /** @param array<string, mixed> $c */
    private static function email(array $c, string $title, string $text): void
    {
        $cfg = ['host' => (string) $c['host'], 'port' => (int) $c['port'], 'security' => (string) $c['security'],
            'user' => (string) $c['user'], 'pass' => (string) $c['pass'], 'from' => (string) $c['from'], 'to' => (string) $c['to']];
        if (self::$mailer !== null) {
            (self::$mailer)($cfg, $title, $text);
            return;
        }
        Smtp::send($cfg, $title, $text . "\n\n-- \nAISSeaStats");
    }

    /** @param array{0: int, 1: string} $r */
    private static function expectOk(array $r): void
    {
        if ($r[0] < 200 || $r[0] >= 300) {
            throw new \RuntimeException('HTTP ' . $r[0] . ($r[1] !== '' ? ': ' . mb_substr(trim(strip_tags($r[1])), 0, 150) : ''));
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: int, 1: string}
     */
    public static function http(string $url, string $body, array $headers = [], string $method = 'POST'): array
    {
        if (!self::validUrl($url)) {
            throw new \RuntimeException('invalid URL');
        }
        if (self::$http !== null) {
            return (self::$http)($url, $body, $headers, $method);
        }
        $opts = ['method' => $method, 'header' => array_merge($headers, ['User-Agent: AISSeaStats/' . VERSION]),
            'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
        if ($method === 'POST') {
            $opts['content'] = $body;
        }
        $ctx = stream_context_create(['http' => $opts]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $err = error_get_last();
            throw new \RuntimeException('cannot reach ' . (parse_url($url, PHP_URL_HOST) ?: 'server') . ($err ? ' (' . preg_replace('/^.*: /', '', $err['message']) . ')' : ''));
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, (string) $resp];
    }

    public static function validUrl(string $url): bool
    {
        return (bool) preg_match('~^https?://[^\s/"\'<>]+(/[^\s"\'<>]*)?$~', $url);
    }

    /**
     * Build the configuration from the admin form, keeping stored secrets when their field is left empty.
     * @param array<string, mixed> $p $_POST
     * @param array<string, mixed> $old current configuration
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with the name of the invalid field
     */
    public static function fromForm(array $p, array $old, string $lang, bool $strict = true): array
    {
        $fail = static function (string $field) use ($strict): void {
            if ($strict) {
                throw new \InvalidArgumentException($field);
            }
        };
        $str = static fn (string $k, int $max = 200): string => mb_substr(trim(str_replace(["\r", "\n"], '', (string) ($p[$k] ?? ''))), 0, $max);
        $secret = static function (string $k, string $prev) use ($p, $str): string {
            if (isset($p[$k . '_clear'])) {
                return '';
            }
            $v = $str($k, 300);
            return $v === '' ? $prev : $v;
        };
        $cfg = self::defaults();
        $cfg['enabled'] = isset($p['alert_enabled']);
        $cfg['after_min'] = max(5, min(1440, (int) ($p['alert_after_min'] ?? 30)));
        $cfg['lang'] = in_array($lang, I18n::LANGS, true) ? $lang : 'auto';

        $cfg['ntfy']['url'] = rtrim($str('ntfy_url') ?: 'https://ntfy.sh', '/');
        $cfg['ntfy']['topic'] = $str('ntfy_topic', 64);
        $cfg['ntfy']['token'] = $secret('ntfy_token', (string) $old['ntfy']['token']);
        if (!self::validUrl($cfg['ntfy']['url'])) {
            $fail('ntfy_url');
        }
        if ($cfg['ntfy']['topic'] !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $cfg['ntfy']['topic'])) {
            $fail('ntfy_topic');
        }

        $cfg['telegram']['token'] = $secret('telegram_token', (string) $old['telegram']['token']);
        $cfg['telegram']['chat'] = $str('telegram_chat', 64);
        if ($cfg['telegram']['token'] !== '' && !preg_match('/^\d+:[A-Za-z0-9_\-]{20,}$/', $cfg['telegram']['token'])) {
            $fail('telegram_token');
        }
        if ($cfg['telegram']['chat'] !== '' && !preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/', $cfg['telegram']['chat'])) {
            $fail('telegram_chat');
        }

        $cfg['webhook']['url'] = $str('webhook_url', 500);
        if ($cfg['webhook']['url'] !== '' && !self::validUrl($cfg['webhook']['url'])) {
            $fail('webhook_url');
        }

        $e = &$cfg['email'];
        $e['host'] = $str('smtp_host', 120);
        $e['port'] = max(1, min(65535, (int) ($p['smtp_port'] ?? 587)));
        $e['security'] = in_array($p['smtp_security'] ?? '', Smtp::SECURITY, true) ? (string) $p['smtp_security'] : 'starttls';
        $e['user'] = $str('smtp_user', 120);
        $e['pass'] = $secret('smtp_pass', (string) $old['email']['pass']);
        $e['from'] = $str('smtp_from', 120);
        $e['to'] = implode(', ', Smtp::addresses($str('smtp_to', 500)));
        unset($e);
        if ($cfg['email']['host'] !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $cfg['email']['host'])) {
            $fail('smtp_host');
        }
        if ($cfg['email']['from'] !== '' && !Smtp::validAddress($cfg['email']['from'])) {
            $fail('smtp_from');
        }
        if ($str('smtp_to', 500) !== '' && $cfg['email']['to'] === '') {
            $fail('smtp_to');
            $cfg['email']['to'] = $str('smtp_to', 500);
        }

        $cfg['heartbeat_url'] = $str('heartbeat_url', 500);
        if ($cfg['heartbeat_url'] !== '' && !self::validUrl($cfg['heartbeat_url'])) {
            $fail('heartbeat_url');
        }
        return $cfg;
    }
}
