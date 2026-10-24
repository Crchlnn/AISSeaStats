<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Vessel alerts and periodic summaries (1.2.0), sent through the channels set up in the alerts card
 * (ntfy, Telegram, webhook, e-mail) and MQTT. Each kind of message (reception, vessel, summary) has its own
 * channels. Run by the worker every minute, after the "no data" check (Alert::check).
 *
 * - A vessel alert is sent at most once per vessel, trigger and passage; triggers found together make one message.
 * - Quiet hours and an hourly cap hold vessel alerts back (distress beacons excepted from quiet hours); held alerts
 *   are logged ("quiet", "capped") and never sent later, so nothing piles up.
 * - The range record alerts at most every 30 minutes, with the best value reached meanwhile; the first record of
 *   an installation is only stored.
 */
final class Notify
{
    public const KINDS = ['reception', 'vessel', 'summary'];
    public const CHANNELS = ['ntfy', 'telegram', 'webhook', 'email', 'mqtt'];
    public const TRIGGERS = ['watchlist', 'emergency', 'military', 'authority', 'hazmat', 'large', 'rare_flag', 'yacht',
        'first_visit', 'range_record', 'propagation'];
    private const RECORD_EVERY_S = 1800;
    private const MAX_VESSELS = 300;
    private const LOG_KEEP = 100;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'vessel_enabled' => false,
            'triggers' => self::defaultTriggers(),
            'routes' => [
                'reception' => self::CHANNELS,
                'vessel' => ['ntfy', 'telegram', 'webhook', 'mqtt'],
                'summary' => self::CHANNELS,
            ],
            'quiet' => ['enabled' => false, 'from' => '22:00', 'to' => '07:00'],
            'max_per_hour' => 10,
            'summary' => ['daily' => false, 'daily_at' => '20:00', 'weekly' => false, 'weekly_day' => 7, 'weekly_at' => '20:00'],
        ];
    }

    /** Triggers on by default: the rare and meaningful ones. @return array<string, bool> */
    private static function defaultTriggers(): array
    {
        return array_merge(array_fill_keys(self::TRIGGERS, false),
            ['watchlist' => true, 'emergency' => true, 'military' => true, 'range_record' => true]);
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        $d = self::defaults();
        $c = Settings::get('notify');
        if (!is_array($c)) {
            return $d;
        }
        $out = $d;
        $out['vessel_enabled'] = (bool) ($c['vessel_enabled'] ?? false);
        foreach (self::TRIGGERS as $t) {
            $out['triggers'][$t] = (bool) ($c['triggers'][$t] ?? $d['triggers'][$t]);
        }
        foreach (self::KINDS as $k) {
            if (isset($c['routes'][$k]) && is_array($c['routes'][$k])) {
                $out['routes'][$k] = array_values(array_intersect(self::CHANNELS, $c['routes'][$k]));
            }
        }
        $out['quiet'] = array_merge($d['quiet'], is_array($c['quiet'] ?? null) ? $c['quiet'] : []);
        $out['max_per_hour'] = max(1, min(120, (int) ($c['max_per_hour'] ?? $d['max_per_hour'])));
        $out['summary'] = array_merge($d['summary'], is_array($c['summary'] ?? null) ? $c['summary'] : []);
        return $out;
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $s = Settings::get('notify_state');
        return array_merge(['since' => 0, 'record' => null, 'record_alerted' => null, 'record_ts' => 0, 'prop_day' => '',
            'prop_checked' => 0, 'daily_last' => '', 'weekly_last' => ''], is_array($s) ? $s : []);
    }

    /**
     * Channels a kind of message goes to: those routed to it and set up. "test" goes everywhere.
     * @param array<string, mixed> $alertCfg
     * @return array<int, string>
     */
    public static function channels(string $kind, array $alertCfg, ?array $cfg = null): array
    {
        $ready = Alert::configured($alertCfg);
        if (MqttPublisher::ready(MqttPublisher::config())) {
            $ready[] = 'mqtt';
        }
        if ($kind === 'test') {
            return $ready;
        }
        $routes = ($cfg ?? self::config())['routes'][$kind] ?? self::CHANNELS;
        return array_values(array_intersect($ready, $routes));
    }

    /** Worker, every minute. */
    public static function check(int $now): void
    {
        $cfg = self::config();
        $state = self::state();
        // After a pause of the worker, look back one hour at most: older arrivals are not news any more.
        $since = max((int) $state['since'], $now - 3600);
        if ((int) $state['since'] === 0) {
            $since = $now - 120;
        }
        $alertCfg = Alert::config();
        if ($alertCfg['lang'] !== 'auto') {
            I18n::setLang((string) $alertCfg['lang']);
        }
        $state = self::rangeRecord($cfg, $alertCfg, $state, $now);
        if ($cfg['vessel_enabled']) {
            self::vessels($cfg, $alertCfg, $since, $now);
            $state = self::propagation($cfg, $alertCfg, $state, $now);
        }
        $state = self::summaries($cfg, $alertCfg, $state, $now);
        $state['since'] = $now;
        Settings::set('notify_state', $state);
    }

    /** Vessels seen since the last check that match an enabled trigger and were not alerted during this passage. */
    public static function vessels(array $cfg, array $alertCfg, int $since, int $now): int
    {
        $on = array_keys(array_filter($cfg['triggers']));
        $tagTriggers = array_values(array_intersect($on, Rules::TAGS));
        $firstVisit = in_array('first_visit', $on, true);
        if ($tagTriggers === [] && !$firstVisit) {
            return 0;
        }
        // A new installation sees only "first visits": that trigger waits for one week of history.
        $oldest = (int) Db::value("SELECT MIN(first_seen) FROM vessel");
        $firstVisit = $firstVisit && $oldest > 0 && $oldest < $now - 7 * 86400;
        $rows = Db::all("SELECT mmsi, name, shiptype, vclass, country, length_m, destination, tags, first_seen, cur_passage_id,
                                last_lat, last_lon
                         FROM vessel WHERE last_seen >= ? AND vclass NOT IN ('BASE', 'ATON') AND (tags <> '' OR first_seen >= ?)
                         ORDER BY last_seen DESC LIMIT " . self::MAX_VESSELS, [$since, $firstVisit ? $since : PHP_INT_MAX]);
        $sent = 0;
        foreach ($rows as $v) {
            $trigs = array_values(array_intersect($tagTriggers, array_filter(explode(',', (string) $v['tags']))));
            if ($firstVisit && (int) $v['first_seen'] >= $since) {
                $trigs[] = 'first_visit';
            }
            if ($trigs === []) {
                continue;
            }
            $pid = $v['cur_passage_id'] !== null ? (int) $v['cur_passage_id'] : null;
            $done = array_column(Db::all('SELECT DISTINCT trig FROM vessel_alert WHERE mmsi = ? AND (passage_id <=> ? OR trig = ?)',
                [(int) $v['mmsi'], $pid, 'first_visit']), 'trig');
            $trigs = array_values(array_diff($trigs, $done));
            if ($trigs === []) {
                continue;
            }
            [$title, $text, $extra] = self::composeVessel($v, $trigs, $now);
            $hold = self::held($cfg, $now, in_array('emergency', $trigs, true));
            $results = $hold ?? self::summarize(Alert::deliver($alertCfg, 'vessel', 'vessel', $title, $text,
                in_array('emergency', $trigs, true) ? 5 : 3, $extra));
            foreach ($trigs as $t) {
                Db::run('INSERT INTO vessel_alert (ts, mmsi, trig, passage_id, title, results) VALUES (?, ?, ?, ?, ?, ?)',
                    [$now, (int) $v['mmsi'], $t, $pid, mb_substr($title, 0, 200), mb_substr($results, 0, 500)]);
            }
            $sent += $hold === null ? 1 : 0;
        }
        return $sent;
    }

    /** Range record: stored silently the first time, then alerted at most every 30 minutes. */
    public static function rangeRecord(array $cfg, array $alertCfg, array $state, int $now): array
    {
        $r = Db::one('SELECT mmsi, max_dist_nm, sector, ts FROM range_polar ORDER BY max_dist_nm DESC LIMIT 1');
        if ($r === null) {
            return $state;
        }
        $best = round((float) $r['max_dist_nm'], 1);
        if ($state['record'] === null) {
            return array_merge($state, ['record' => $best, 'record_alerted' => $best, 'record_ts' => $now]);
        }
        $state['record'] = max((float) $state['record'], $best);
        $want = $cfg['vessel_enabled'] && $cfg['triggers']['range_record'];
        if ($best <= (float) $state['record_alerted'] + 0.05 || $now - (int) $state['record_ts'] < self::RECORD_EVERY_S) {
            return $want ? $state : array_merge($state, ['record_alerted' => max((float) $state['record_alerted'], $best)]);
        }
        $v = Db::one('SELECT mmsi, name, shiptype, vclass, country FROM vessel WHERE mmsi = ?', [(int) $r['mmsi']]);
        $name = self::vesselName($v ?? ['mmsi' => $r['mmsi']]);
        $title = I18n::t('notify.record_title', ['s' => self::station(), 'd' => self::nm($best)]);
        $text = I18n::t('notify.record_text', ['d' => self::nm($best), 'p' => self::nm((float) $state['record_alerted']),
            'v' => $name, 'b' => self::bearing((int) $r['sector'] * 10 + 5)]);
        $url = self::vesselUrl((int) $r['mmsi']);
        $extra = ['trigger' => 'range_record', 'distance_nm' => $best, 'mmsi' => (int) $r['mmsi'], 'url' => $url ?: null];
        if ($url !== '') {
            $text .= "\n" . $url;
        }
        $hold = self::held($cfg, $now, false);
        $results = $hold ?? self::summarize(Alert::deliver($alertCfg, 'vessel', 'record', $title, $text, 3, $extra));
        Db::run('INSERT INTO vessel_alert (ts, mmsi, trig, passage_id, title, results) VALUES (?, ?, ?, NULL, ?, ?)',
            [$now, (int) $r['mmsi'], 'range_record', mb_substr($title, 0, 200), $results]);
        return array_merge($state, ['record_alerted' => $best, 'record_ts' => $now]);
    }

    /** Exceptional reception today (Stats::propagation), checked every 10 minutes, alerted once a day. */
    public static function propagation(array $cfg, array $alertCfg, array $state, int $now): array
    {
        if (!$cfg['triggers']['propagation'] || $now - (int) $state['prop_checked'] < 600) {
            return $state;
        }
        $state['prop_checked'] = $now;
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(Settings::timezone())->setTime(0, 0);
        if ($state['prop_day'] === $today->format('Y-m-d')) {
            return $state;
        }
        $ev = Stats::propagation($today, $today)[0] ?? null;
        if ($ev === null) {
            return $state;
        }
        $title = I18n::t('notify.prop_title', ['s' => self::station()]);
        $text = I18n::t('notify.prop_text', ['d' => self::nm((float) $ev['max']), 'u' => self::nm((float) $ev['usual']), 'n' => $ev['far']]);
        $url = self::publicUrl();
        if ($url !== '') {
            $text .= "\n" . $url;
        }
        $hold = self::held($cfg, $now, false);
        $results = $hold ?? self::summarize(Alert::deliver($alertCfg, 'vessel', 'propagation', $title, $text, 3,
            ['trigger' => 'propagation', 'distance_nm' => (float) $ev['max'], 'usual_nm' => (float) $ev['usual'], 'url' => $url ?: null]));
        Db::run('INSERT INTO vessel_alert (ts, mmsi, trig, passage_id, title, results) VALUES (?, 0, ?, NULL, ?, ?)',
            [$now, 'propagation', mb_substr($title, 0, 200), $results]);
        $state['prop_day'] = $today->format('Y-m-d');
        return $state;
    }

    /** Daily and weekly summaries, at the chosen local time (a missed time is not caught up the next day). */
    public static function summaries(array $cfg, array $alertCfg, array $state, int $now): array
    {
        $s = $cfg['summary'];
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(Settings::timezone());
        $hm = $local->format('H:i');
        $today = $local->format('Y-m-d');
        if ($s['daily'] && $hm >= $s['daily_at'] && $state['daily_last'] !== $today) {
            $state['daily_last'] = $today;
            self::sendSummary($alertCfg, 'daily', $local->setTime(0, 0), $local, $now);
        }
        $week = $local->format('o-W');
        if ($s['weekly'] && (int) $local->format('N') === (int) $s['weekly_day'] && $hm >= $s['weekly_at'] && $state['weekly_last'] !== $week) {
            $state['weekly_last'] = $week;
            self::sendSummary($alertCfg, 'weekly', $local->setTime(0, 0)->modify('-6 days'), $local, $now);
        }
        return $state;
    }

    /** @return array<string, string|null> channel => error */
    public static function sendSummary(array $alertCfg, string $period, \DateTimeImmutable $from, \DateTimeImmutable $to, int $now): array
    {
        $d = self::summaryData($from, $to);
        $title = I18n::t('notify.sum_' . $period . '_title', ['s' => self::station(), 'd' => $to->format('d/m')]);
        $lines = [
            I18n::t('notify.sum_vessels', ['n' => $d['vessels'], 'k' => $d['new']]),
            I18n::t('notify.sum_msgs', ['n' => number_format($d['msgs'], 0, ',', "\u{202F}")]),
        ];
        if ($d['range'] !== null) {
            $lines[] = I18n::t('notify.sum_range', ['d' => self::nm($d['range']), 'v' => $d['range_vessel']]);
        }
        if ($d['notable'] !== []) {
            $lines[] = I18n::t('notify.sum_notable', ['n' => $d['notable_n'], 'l' => implode(', ', $d['notable'])]);
        }
        if ($d['busiest'] !== null) {
            $lines[] = I18n::t('notify.sum_busiest', ['h' => $d['busiest']]);
        }
        $url = self::publicUrl();
        if ($url !== '') {
            $lines[] = $url;
        }
        $results = Alert::deliver($alertCfg, 'summary', 'summary', $title, implode("\n", $lines), 2,
            ['period' => $period, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'url' => $url ?: null,
             'data' => array_diff_key($d, ['notable' => 1])]);
        Db::run('INSERT INTO vessel_alert (ts, mmsi, trig, passage_id, title, results) VALUES (?, 0, ?, NULL, ?, ?)',
            [$now, 'summary_' . $period, mb_substr($title, 0, 200), self::summarize($results)]);
        return $results;
    }

    /** @return array<string, mixed> */
    public static function summaryData(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $a = $from->format('Y-m-d');
        $b = $to->format('Y-m-d');
        $start = $from->setTime(0, 0)->getTimestamp();
        $end = $to->setTime(23, 59, 59)->getTimestamp();
        $infra = "('BASE','ATON')";
        $range = Db::one('SELECT mmsi, max_dist_nm FROM range_polar WHERE day BETWEEN ? AND ? ORDER BY max_dist_nm DESC LIMIT 1', [$a, $b]);
        $rv = $range !== null ? Db::one('SELECT mmsi, name FROM vessel WHERE mmsi = ?', [(int) $range['mmsi']]) : null;
        $notable = Db::all("SELECT DISTINCT v.mmsi, v.name FROM vessel_daily d JOIN vessel v ON v.mmsi = d.mmsi
                            WHERE d.day BETWEEN ? AND ? AND v.tags <> '' AND v.vclass NOT IN {$infra}
                            ORDER BY d.max_dist_nm IS NULL, v.name LIMIT 200", [$a, $b]);
        $busy = Db::one('SELECT hour_ts, vessels FROM stats_hourly WHERE hour_ts BETWEEN ? AND ? ORDER BY vessels DESC, hour_ts LIMIT 1', [$start, $end]);
        return [
            'vessels' => (int) Db::value('SELECT COUNT(DISTINCT mmsi) FROM vessel_daily WHERE day BETWEEN ? AND ?', [$a, $b]),
            'new' => (int) Db::value("SELECT COUNT(*) FROM vessel WHERE first_seen BETWEEN ? AND ? AND vclass NOT IN {$infra}", [$start, $end]),
            'msgs' => (int) Db::value('SELECT COALESCE(SUM(msgs), 0) FROM msgtype_daily WHERE day BETWEEN ? AND ?', [$a, $b]),
            'range' => $range !== null ? round((float) $range['max_dist_nm'], 1) : null,
            'range_vessel' => $range !== null ? self::vesselName($rv ?? ['mmsi' => $range['mmsi']]) : '',
            'notable_n' => count($notable),
            'notable' => array_map(static fn (array $v): string => self::vesselName($v), array_slice($notable, 0, 5)),
            'busiest' => $busy !== null && (int) $busy['vessels'] > 0
                ? (new \DateTimeImmutable('@' . (int) $busy['hour_ts']))->setTimezone(Settings::timezone())->format('d/m H:00') . ' (' . (int) $busy['vessels'] . ')'
                : null,
        ];
    }

    /**
     * Why a vessel alert is held back: "quiet" (quiet hours, distress beacons excepted) or "capped" (hourly cap), or null.
     * @param array<string, mixed> $cfg
     */
    public static function held(array $cfg, int $now, bool $urgent): ?string
    {
        if (!$urgent && $cfg['quiet']['enabled'] && self::inQuiet((string) $cfg['quiet']['from'], (string) $cfg['quiet']['to'],
            (new \DateTimeImmutable('@' . $now))->setTimezone(Settings::timezone())->format('H:i'))) {
            return 'quiet';
        }
        $count = (int) Db::value("SELECT COUNT(DISTINCT mmsi, ts) FROM vessel_alert
                                  WHERE ts > ? AND trig NOT LIKE 'summary%' AND results NOT IN ('quiet', 'capped')", [$now - 3600]);
        return $count >= (int) $cfg['max_per_hour'] ? 'capped' : null;
    }

    /** Is H:i inside [from, to)? The range may span midnight (22:00 → 07:00). */
    public static function inQuiet(string $from, string $to, string $hm): bool
    {
        if ($from === $to) {
            return false;
        }
        return $from < $to ? $hm >= $from && $hm < $to : $hm >= $from || $hm < $to;
    }

    /**
     * One message per vessel, whatever the number of triggers.
     * @param array<string, mixed> $v
     * @param array<int, string> $trigs
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    public static function composeVessel(array $v, array $trigs, int $now): array
    {
        $name = self::vesselName($v);
        $labels = array_map(static fn (string $t): string => I18n::t($t === 'first_visit' ? 'notify.t_first_visit' : 'tag.' . $t), $trigs);
        $icon = in_array('emergency', $trigs, true) ? '🆘' : (in_array('watchlist', $trigs, true) ? '⭐' : '🚢');
        $title = $icon . ' ' . $name . ' · ' . implode(', ', $labels);
        $parts = [I18n::t('type.' . self::typeCat((int) ($v['shiptype'] ?? 0), (string) ($v['vclass'] ?? '')))];
        if (!empty($v['country'])) {
            $parts[] = (string) $v['country'];
        }
        if (!empty($v['length_m'])) {
            $parts[] = (int) $v['length_m'] . ' m';
        }
        $parts[] = 'MMSI ' . (int) $v['mmsi'];
        $lines = [implode(' · ', $parts)];
        $dist = null;
        if ($v['last_lat'] !== null && $v['last_lon'] !== null && Settings::hasStation()) {
            $sLat = (float) Settings::get('station_lat');
            $sLon = (float) Settings::get('station_lon');
            $dist = round(Geo::distanceNm($sLat, $sLon, (float) $v['last_lat'], (float) $v['last_lon']), 1);
            $lines[] = I18n::t('notify.at', ['d' => self::nm($dist),
                'b' => self::bearing((int) round(Geo::bearing($sLat, $sLon, (float) $v['last_lat'], (float) $v['last_lon'])))]);
        }
        if (!empty($v['destination'])) {
            $lines[] = I18n::t('notify.dest', ['d' => (string) $v['destination']]);
        }
        $url = self::vesselUrl((int) $v['mmsi']);
        if ($url !== '') {
            $lines[] = $url;
        }
        $extra = ['trigger' => $trigs[0], 'triggers' => $trigs, 'mmsi' => (int) $v['mmsi'], 'name' => $v['name'] ?: null,
            'shiptype' => $v['shiptype'] !== null ? (int) $v['shiptype'] : null, 'country' => $v['country'] ?: null,
            'distance_nm' => $dist, 'url' => $url ?: null];
        return [$title, implode("\n", $lines), $extra];
    }

    /** Same categories as the page (app.js typeCat). */
    public static function typeCat(int $st, string $vclass): string
    {
        return match (true) {
            $vclass === 'SAR' => 'sar_aircraft',
            $vclass === 'EMRG' => 'emergency',
            $st >= 70 && $st <= 79 => 'cargo',
            $st >= 80 && $st <= 89 => 'tanker',
            $st >= 60 && $st <= 69 => 'passenger',
            $st >= 40 && $st <= 49 => 'hsc',
            $st === 30 => 'fishing',
            in_array($st, [31, 32, 52], true) => 'tug',
            $st === 36 => 'sailing',
            $st === 37 => 'pleasure',
            $st === 35 => 'military',
            $st === 50 => 'pilot',
            in_array($st, [51, 55, 58], true) => 'authority',
            in_array($st, [33, 34, 53, 54], true) => 'service',
            $st === 0 => 'unknown',
            default => 'other',
        };
    }

    /** Public address of the page (admin, optional), without trailing slash. */
    public static function publicUrl(): string
    {
        $u = trim((string) Settings::get('public_url'));
        return Alert::validUrl($u) ? rtrim($u, '/') : '';
    }

    public static function vesselUrl(int $mmsi): string
    {
        $u = self::publicUrl();
        return $u === '' ? '' : $u . '/?mmsi=' . $mmsi;
    }

    /** @param array<string, mixed> $v */
    private static function vesselName(array $v): string
    {
        $n = trim((string) ($v['name'] ?? ''));
        return $n !== '' ? $n : 'MMSI ' . (int) $v['mmsi'];
    }

    private static function station(): string
    {
        return (string) Settings::get('station_name');
    }

    private static function nm(float $d): string
    {
        return number_format($d, $d < 100 ? 1 : 0, I18n::lang() === 'fr' ? ',' : '.', "\u{202F}") . "\u{202F}NM";
    }

    private static function bearing(int $deg): string
    {
        $pts = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        return I18n::t('dir.' . $pts[(int) round((($deg % 360) + 360) % 360 / 45) % 8]);
    }

    /** Short text of the channels' results for the log: "ok", "ntfy: error…" or "–" when no channel is set up. */
    public static function summarize(array $results): string
    {
        if ($results === []) {
            return 'none';
        }
        $errs = [];
        foreach ($results as $ch => $err) {
            if ($err !== null) {
                $errs[] = $ch . ': ' . $err;
            }
        }
        return $errs === [] ? 'ok' : mb_substr(implode(' | ', $errs), 0, 500);
    }

    /**
     * Last messages for the admin.
     * @return array<int, array<string, mixed>>
     */
    public static function log(int $limit = self::LOG_KEEP): array
    {
        return Db::all('SELECT a.ts, a.mmsi, GROUP_CONCAT(a.trig ORDER BY a.id SEPARATOR \',\') AS trigs, MAX(a.title) AS title, MAX(a.results) AS results
                        FROM vessel_alert a GROUP BY a.ts, a.mmsi ORDER BY a.ts DESC, a.mmsi LIMIT ' . max(1, min(self::LOG_KEEP, $limit)));
    }

    /**
     * Settings from the admin form.
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with the field name
     */
    public static function fromForm(array $p): array
    {
        $c = self::defaults();
        $c['vessel_enabled'] = isset($p['notify_vessel']);
        foreach (self::TRIGGERS as $t) {
            $c['triggers'][$t] = isset($p['trig_' . $t]);
        }
        foreach (self::KINDS as $k) {
            $c['routes'][$k] = array_values(array_filter(self::CHANNELS, static fn (string $ch): bool => isset($p['route_' . $k . '_' . $ch])));
        }
        $time = static function (string $k, string $def) use ($p): string {
            $v = trim((string) ($p[$k] ?? $def));
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m)) {
                throw new \InvalidArgumentException($k);
            }
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        };
        $c['quiet'] = ['enabled' => isset($p['quiet_enabled']), 'from' => $time('quiet_from', '22:00'), 'to' => $time('quiet_to', '07:00')];
        $c['max_per_hour'] = max(1, min(120, (int) ($p['max_per_hour'] ?? 10)));
        $c['summary'] = [
            'daily' => isset($p['sum_daily']), 'daily_at' => $time('sum_daily_at', '20:00'),
            'weekly' => isset($p['sum_weekly']), 'weekly_day' => max(1, min(7, (int) ($p['sum_weekly_day'] ?? 7))),
            'weekly_at' => $time('sum_weekly_at', '20:00'),
        ];
        return $c;
    }
}
