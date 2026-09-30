<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Turns a batch of decoded AIS-catcher messages into aggregated statistics.
 *
 * Nothing is stored per message: vessels, hourly/daily counters, sampled
 * positions (one per vessel per minute), passages and range records are
 * updated in a single transaction per batch.
 */
final class Ingest
{
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;
    public const MAX_JSON_BYTES = 32 * 1024 * 1024;
    private const POSITION_TYPES = [1, 2, 3, 4, 9, 18, 19, 21, 27];
    private const MAX_PLAUSIBLE_KN = 70.0;
    private const MAX_PLAUSIBLE_AIR_KN = 350.0;
    /** Beyond this distance a position only counts for range records when confirmed by a previous one. */
    public const RANGE_CONFIRM_NM = 50.0;
    private const RANGE_CONFIRM_S = 1800;

    private int $now;
    private ?float $stLat = null;
    private ?float $stLon = null;
    private float $maxRange;
    private int $gap;
    private \DateTimeZone $tz;

    /** @var array<int, array<string, mixed>> */
    private array $vessels = [];
    /** @var array<int, bool> vessels whose DB row must be written */
    private array $dirty = [];
    /** @var array<int, array<string, mixed>> */
    private array $passages = [];
    /** @var array<int, array<string, mixed>> */
    private array $hourly = [];
    /** @var array<int, array<int, true>> */
    private array $vHourly = [];
    /** @var array<string, array<int, array{msgs:int, dist:?float}>> */
    private array $vDaily = [];
    /** @var array<string, array<int, int>> */
    private array $mType = [];
    /** @var array<string, array<int|float|null>> */
    private array $positions = [];
    /** @var array<string, array{dist:float, mmsi:int}> */
    private array $polar = [];
    /** @var array<string, string> cache of local day per hour */
    private array $dayCache = [];

    /** @var array{received:int, accepted:int, rejected_positions:int, vessels:int} */
    private array $stats = ['received' => 0, 'accepted' => 0, 'rejected_positions' => 0, 'unconfirmed_range' => 0, 'vessels' => 0];

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
        if (Settings::hasStation()) {
            $this->stLat = (float) Settings::get('station_lat');
            $this->stLon = (float) Settings::get('station_lon');
        }
        $this->maxRange = (float) Settings::get('max_range_nm');
        $this->gap = max(10, (int) Settings::get('passage_gap_min')) * 60;
        $this->tz = Settings::timezone();
    }

    /**
     * Decode a raw HTTP body (optionally gzip) posted by AIS-catcher.
     * Accepts the default AISCATCHER protocol ({"msgs":[...]}) and the LIST
     * protocol (one JSON message per line).
     *
     * @return array{station:?string, msgs:array<int, array<string, mixed>>}
     */
    public static function decodeBody(string $body, string $contentEncoding = ''): array
    {
        if ($body === '') {
            throw new \InvalidArgumentException('empty body');
        }
        if (stripos($contentEncoding, 'gzip') !== false || str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body, self::MAX_JSON_BYTES);
            if ($decoded === false) {
                throw new \InvalidArgumentException('invalid gzip payload');
            }
            $body = $decoded;
        }
        $body = trim($body);
        $data = json_decode($body, true, 64);
        if (is_array($data) && isset($data['msgs']) && is_array($data['msgs'])) {
            $station = isset($data['stationid']) && is_scalar($data['stationid']) ? (string) $data['stationid'] : null;
            return ['station' => $station, 'msgs' => array_values(array_filter($data['msgs'], 'is_array'))];
        }
        if (is_array($data) && isset($data['mmsi'])) {
            return ['station' => null, 'msgs' => [$data]];
        }
        $msgs = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $m = json_decode($line, true, 32);
            if (is_array($m)) {
                $msgs[] = $m;
            }
        }
        if ($msgs === []) {
            throw new \InvalidArgumentException('no AIS messages found in payload');
        }
        return ['station' => null, 'msgs' => $msgs];
    }

    /**
     * @param array<int, array<string, mixed>> $msgs
     * @return array{received:int, accepted:int, rejected_positions:int, vessels:int}
     */
    public function process(array $msgs): array
    {
        $this->stats['received'] = count($msgs);
        $byMmsi = [];
        foreach ($msgs as $m) {
            $mmsi = isset($m['mmsi']) && is_numeric($m['mmsi']) ? (int) $m['mmsi'] : 0;
            if ($mmsi < 1 || $mmsi > 999999999) {
                continue;
            }
            $m['_ts'] = $this->timestamp($m);
            if ($m['_ts'] === null) {
                continue;
            }
            $byMmsi[$mmsi][] = $m;
        }
        if ($byMmsi === []) {
            return $this->stats;
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $this->loadVessels(array_keys($byMmsi));
            foreach ($byMmsi as $mmsi => $list) {
                usort($list, static fn ($a, $b) => $a['_ts'] <=> $b['_ts']);
                foreach ($list as $m) {
                    $this->handle($mmsi, $m);
                }
            }
            $this->flush();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->stats['vessels'] = count($byMmsi);
        return $this->stats;
    }

    /** @param array<string, mixed> $m */
    private function timestamp(array $m): ?int
    {
        $ts = null;
        if (isset($m['rxuxtime']) && is_numeric($m['rxuxtime'])) {
            $ts = (int) $m['rxuxtime'];
        } elseif (isset($m['rxtime']) && is_string($m['rxtime']) && preg_match('/^\d{14}$/', $m['rxtime'])) {
            $d = \DateTimeImmutable::createFromFormat('YmdHis', $m['rxtime'], new \DateTimeZone('UTC'));
            $ts = $d ? $d->getTimestamp() : null;
        }
        if ($ts === null) {
            return $this->now;
        }
        if ($ts > $this->now + 300) {
            return $this->now;
        }
        if ($ts < $this->now - 400 * 86400) {
            return null;
        }
        return $ts;
    }

    /** @param array<int, int> $mmsis */
    private function loadVessels(array $mmsis): void
    {
        foreach (array_chunk($mmsis, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all("SELECT * FROM vessel WHERE mmsi IN ($in)", $chunk) as $row) {
                $this->vessels[(int) $row['mmsi']] = $row;
            }
        }
        $pids = [];
        foreach ($this->vessels as $v) {
            if ($v['cur_passage_id'] !== null) {
                $pids[] = (int) $v['cur_passage_id'];
            }
        }
        foreach (array_chunk($pids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all("SELECT * FROM passage WHERE id IN ($in)", $chunk) as $row) {
                $this->passages[(int) $row['id']] = $row;
            }
        }
    }

    /** @param array<string, mixed> $m */
    private function handle(int $mmsi, array $m): void
    {
        $ts = (int) $m['_ts'];
        $type = isset($m['type']) && is_numeric($m['type']) ? (int) $m['type'] : 0;
        $this->stats['accepted']++;

        if (!isset($this->vessels[$mmsi])) {
            $this->vessels[$mmsi] = [
                'mmsi' => $mmsi, 'name' => null, 'callsign' => null, 'imo' => null, 'eni' => null,
                'shiptype' => null, 'vclass' => '', 'length_m' => null, 'beam_m' => null, 'draught_m' => null,
                'country' => null, 'destination' => null, 'eta' => null, 'first_seen' => $ts, 'last_seen' => $ts,
                'msgs' => 0, 'passages' => 0, 'max_dist_nm' => null, 'max_speed_kn' => null,
                'last_lat' => null, 'last_lon' => null, 'last_pos_ts' => null, 'last_sog' => null,
                'last_cog' => null, 'last_signal' => null, 'cur_passage_id' => null, 'static_updated' => 0,
                '_new' => true,
            ];
        }
        $v = &$this->vessels[$mmsi];
        $this->dirty[$mmsi] = true;

        $v['vclass'] = self::vesselClass($mmsi, $type, (string) $v['vclass']);
        $infra = in_array($v['vclass'], ['BASE', 'ATON'], true);
        $prevLastSeen = (int) $v['last_seen'];
        $v['first_seen'] = min((int) $v['first_seen'], $ts);
        $v['last_seen'] = max($prevLastSeen, $ts);
        $v['msgs'] = (int) $v['msgs'] + 1;
        if (isset($m['country_code']) && is_string($m['country_code']) && preg_match('/^[A-Za-z]{2}$/', $m['country_code'])) {
            $v['country'] = strtoupper($m['country_code']);
        } elseif ($v['country'] === null) {
            $v['country'] = Mid::country($mmsi);
        }
        $signal = isset($m['signalpower']) && is_numeric($m['signalpower']) ? (float) $m['signalpower'] : null;
        if ($signal !== null && $signal > -200 && $signal < 100) {
            $v['last_signal'] = round($signal, 1);
        } else {
            $signal = null;
        }

        // Passages (not for fixed infrastructure).
        $pid = null;
        if (!$infra) {
            if ($v['cur_passage_id'] === null || ($ts - $prevLastSeen > $this->gap)) {
                $pid = $this->startPassage($mmsi, $ts);
                $v['cur_passage_id'] = $pid;
                $v['passages'] = (int) $v['passages'] + 1;
            } else {
                $pid = (int) $v['cur_passage_id'];
                if (!isset($this->passages[$pid]) || !empty($this->passages[$pid]['closed'])) {
                    $pid = $this->startPassage($mmsi, $ts);
                    $v['cur_passage_id'] = $pid;
                    $v['passages'] = (int) $v['passages'] + 1;
                }
            }
            $p = &$this->passages[$pid];
            $p['end_ts'] = max((int) $p['end_ts'], $ts);
            $p['msgs'] = (int) $p['msgs'] + 1;
            $p['_dirty'] = true;
            unset($p);
        }

        // Aggregates.
        $hour = intdiv($ts, 3600) * 3600;
        $day = $this->day($hour, $ts);
        $h = &$this->hourly[$hour];
        $h ??= ['msgs' => 0, 'a' => 0, 'b' => 0, 'dist' => null, 'sig_sum' => 0.0, 'sig_n' => 0, 'sig_min' => null, 'sig_max' => null];
        $h['msgs']++;
        $channel = isset($m['channel']) && is_string($m['channel']) ? strtoupper($m['channel']) : '';
        if ($channel === 'A') {
            $h['a']++;
        } elseif ($channel === 'B') {
            $h['b']++;
        }
        if ($signal !== null) {
            $h['sig_sum'] += $signal;
            $h['sig_n']++;
            $h['sig_min'] = $h['sig_min'] === null ? $signal : min($h['sig_min'], $signal);
            $h['sig_max'] = $h['sig_max'] === null ? $signal : max($h['sig_max'], $signal);
        }
        unset($h);
        $this->mType[$day][$type] = ($this->mType[$day][$type] ?? 0) + 1;
        if (!$infra) {
            $this->vHourly[$hour][$mmsi] = true;
            $vd = &$this->vDaily[$day][$mmsi];
            $vd ??= ['msgs' => 0, 'dist' => null];
            $vd['msgs']++;
            unset($vd);
        }

        if (in_array($type, self::POSITION_TYPES, true) && Geo::validPosition($m['lat'] ?? null, $m['lon'] ?? null)) {
            $this->handlePosition($mmsi, $v, $m, $ts, $type, $hour, $day, $pid, $infra);
        }
        $this->handleStatic($v, $m, $type, $ts);
        unset($v);
    }

    /**
     * @param array<string, mixed> $v
     * @param array<string, mixed> $m
     */
    private function handlePosition(int $mmsi, array &$v, array $m, int $ts, int $type, int $hour, string $day, ?int $pid, bool $infra): void
    {
        $lat = (float) $m['lat'];
        $lon = (float) $m['lon'];
        $dist = null;
        $brg = null;
        if ($this->stLat !== null && $this->stLon !== null) {
            $dist = Geo::distanceNm($this->stLat, $this->stLon, $lat, $lon);
            if ($dist > $this->maxRange) {
                $this->stats['rejected_positions']++;
                return;
            }
            $brg = Geo::bearing($this->stLat, $this->stLon, $lat, $lon);
        }

        $isAir = $type === 9 || $v['vclass'] === 'SAR';
        $maxKn = $isAir ? self::MAX_PLAUSIBLE_AIR_KN : self::MAX_PLAUSIBLE_KN;
        $prevTs = $v['last_pos_ts'] !== null ? (int) $v['last_pos_ts'] : null;
        $step = 0.0;
        $confirmed = false;
        if ($prevTs !== null && $v['last_lat'] !== null) {
            $step = Geo::distanceNm((float) $v['last_lat'], (float) $v['last_lon'], $lat, $lon);
            $dt = $ts - $prevTs;
            if ($dt > 0 && $dt <= 600 && $step > 1.0) {
                $implied = $step / ($dt / 3600);
                if ($implied > $maxKn) {
                    $this->stats['rejected_positions']++;
                    return;
                }
            }
            // A previous position of the same vessel, recent and reachable at a plausible speed.
            $confirmed = $dt > 0 && $dt <= self::RANGE_CONFIRM_S && $step <= max(1.0, $maxKn * $dt / 3600);
            if ($ts < $prevTs) {
                $step = 0.0; // late message: do not add travel distance
            }
        }

        $sog = isset($m['speed']) && is_numeric($m['speed']) ? (float) $m['speed'] : null;
        if ($sog !== null && ($sog < 0 || $sog >= 102.2)) {
            $sog = null;
        }
        $cog = isset($m['course']) && is_numeric($m['course']) ? (float) $m['course'] : null;
        if ($cog !== null && ($cog < 0 || $cog >= 360)) {
            $cog = null;
        }

        if ($prevTs === null || $ts >= $prevTs) {
            $v['last_lat'] = round($lat, 6);
            $v['last_lon'] = round($lon, 6);
            $v['last_pos_ts'] = $ts;
            $v['last_sog'] = $sog;
            $v['last_cog'] = $cog;
        }
        if ($infra) {
            return;
        }
        if ($sog !== null && !$isAir && $sog <= 60 && ($v['max_speed_kn'] === null || $sog > (float) $v['max_speed_kn'])) {
            $v['max_speed_kn'] = round($sog, 1);
        }

        $minute = intdiv($ts, 60);
        $this->positions[$mmsi . ':' . $minute] ??= [$mmsi, $minute, round($lat, 6), round($lon, 6), $sog, $cog];

        // Long range (tropospheric ducting) is real but so are corrupted positions:
        // a far position feeds the range records only once confirmed by the vessel's previous one.
        if ($dist !== null && $dist > self::RANGE_CONFIRM_NM && !$confirmed) {
            $dist = null;
            $this->stats['unconfirmed_range']++;
        }
        if ($dist !== null) {
            $dist = round($dist, 2);
            if ($v['max_dist_nm'] === null || $dist > (float) $v['max_dist_nm']) {
                $v['max_dist_nm'] = $dist;
            }
            $h = &$this->hourly[$hour];
            $h['dist'] = $h['dist'] === null ? $dist : max($h['dist'], $dist);
            unset($h);
            $vd = &$this->vDaily[$day][$mmsi];
            $vd['dist'] = $vd['dist'] === null ? $dist : max($vd['dist'], $dist);
            unset($vd);
            $sector = (int) floor($brg / 10) % 36;
            $key = $day . '|' . $sector;
            if (!isset($this->polar[$key]) || $dist > $this->polar[$key]['dist']) {
                $this->polar[$key] = ['dist' => $dist, 'mmsi' => $mmsi];
            }
        }

        if ($pid !== null) {
            $p = &$this->passages[$pid];
            if ($p['entry_lat'] === null) {
                $p['entry_lat'] = round($lat, 6);
                $p['entry_lon'] = round($lon, 6);
            } elseif ($prevTs !== null && $prevTs >= (int) $p['start_ts']) {
                $p['moved_nm'] = round((float) $p['moved_nm'] + $step, 2);
            }
            if ((int) ($p['exit_ts'] ?? 0) <= $ts) {
                $p['exit_lat'] = round($lat, 6);
                $p['exit_lon'] = round($lon, 6);
                $p['exit_ts'] = $ts;
            }
            if ($dist !== null && ($p['max_dist_nm'] === null || $dist > (float) $p['max_dist_nm'])) {
                $p['max_dist_nm'] = $dist;
            }
            unset($p);
        }
    }

    /**
     * @param array<string, mixed> $v
     * @param array<string, mixed> $m
     */
    private function handleStatic(array &$v, array $m, int $type, int $ts): void
    {
        $changed = false;
        $set = static function (string $col, mixed $val) use (&$v, &$changed): void {
            if ($val !== null && $val !== '' && $v[$col] != $val) {
                $v[$col] = $val;
                $changed = true;
            }
        };
        $name = self::cleanText($m['shipname'] ?? ($type === 21 ? ($m['name'] ?? null) : null), 32);
        $isB24 = $type === 24;
        $part = isset($m['partno']) && is_numeric($m['partno']) ? (int) $m['partno'] : -1;

        if ($type === 5 || $type === 19 || $type === 21 || ($isB24 && $part === 0)) {
            $set('name', $name);
        }
        if ($type === 5 || ($isB24 && $part === 1)) {
            $set('callsign', self::cleanText($m['callsign'] ?? null, 16));
        }
        if ($type === 5) {
            $imo = isset($m['imo']) && is_numeric($m['imo']) ? (int) $m['imo'] : 0;
            if (self::validImo($imo)) {
                $set('imo', $imo);
            }
            $set('destination', self::cleanText($m['destination'] ?? null, 32));
            if (isset($m['eta']) && is_string($m['eta']) && !str_starts_with($m['eta'], '00-00')) {
                $set('eta', substr($m['eta'], 0, 16));
            }
            if (isset($m['draught']) && is_numeric($m['draught']) && (float) $m['draught'] > 0) {
                $set('draught_m', round((float) $m['draught'], 1));
            }
        }
        if ($type === 5 || $type === 19 || ($isB24 && $part === 1)) {
            $st = isset($m['shiptype']) && is_numeric($m['shiptype']) ? (int) $m['shiptype'] : 0;
            if ($st > 0 && $st < 100) {
                $set('shiptype', $st);
            }
        }
        if (in_array($type, [5, 19, 21], true) || ($isB24 && $part === 1)) {
            $bow = (int) ($m['to_bow'] ?? 0);
            $stern = (int) ($m['to_stern'] ?? 0);
            $port = (int) ($m['to_port'] ?? 0);
            $stbd = (int) ($m['to_starboard'] ?? 0);
            if ($bow > 0 && $stern > 0 && $bow + $stern < 600) {
                $set('length_m', $bow + $stern);
            }
            if ($port > 0 && $stbd > 0 && $port + $stbd < 100) {
                $set('beam_m', $port + $stbd);
            }
        }
        // Inland AIS static voyage data (ASM DAC 200 FID 10): ENI and hull size.
        if ($type === 8 && (int) ($m['dac'] ?? 0) === 200 && (int) ($m['fid'] ?? 0) === 10) {
            $eni = self::cleanText($m['vin'] ?? null, 16);
            if ($eni !== null && preg_match('/^\d{8}$/', $eni) && $eni !== '00000000') {
                $set('eni', $eni);
            }
            if ($v['length_m'] === null && isset($m['length']) && is_numeric($m['length']) && (float) $m['length'] > 0) {
                $set('length_m', (int) round((float) $m['length']));
            }
            if ($v['beam_m'] === null && isset($m['beam']) && is_numeric($m['beam']) && (float) $m['beam'] > 0) {
                $set('beam_m', (int) round((float) $m['beam']));
            }
            // Many inland vessels are heard through this message before (or without) their type 5:
            // take the type from the ERI code until the AIS type arrives.
            if (empty($v['shiptype']) && isset($m['shiptype']) && is_numeric($m['shiptype'])) {
                $set('shiptype', self::eriToAis((int) $m['shiptype']));
            }
        }
        if ($changed) {
            $v['static_updated'] = $ts;
        }
    }

    /**
     * ERI inland ship type (CCNR / UNECE, 4 digits) to the closest AIS ship type, grouped as AIS-catcher does.
     */
    public static function eriToAis(int $eri): ?int
    {
        return match (true) {
            in_array($eri, [8010, 8030, 8050, 8070, 8090, 8110, 8130, 8140, 8150, 8170, 1500, 1510, 1520], true),
            $eri >= 8210 && $eri <= 8390 => 79,   // motor freighters, push-tows, cargo barges
            in_array($eri, [8020, 8021, 8022, 8023, 8040, 8060, 8080, 8100, 8120, 8160, 8161, 8162, 8163, 8180,
                8490, 8500, 1530, 1540], true) => 89, // tankers, tank barges, bunker ships
            $eri >= 8440 && $eri <= 8448 => 69,  // ferries, passenger and cabin ships
            in_array($eri, [8400, 8410, 8420, 8430], true) => 52, // tugs and push boats
            $eri >= 8450 && $eri <= 8454 => 53,  // service vessels
            $eri === 8460 => 33,                 // work and maintenance craft
            $eri === 8480 => 30,                 // fishing
            $eri === 1850 => 37,                 // pleasure craft
            in_array($eri, [1900, 1910, 1920], true) => 49, // high-speed craft
            default => null,
        };
    }

    private function startPassage(int $mmsi, int $ts): int
    {
        Db::run('INSERT INTO passage (mmsi, start_ts, end_ts) VALUES (?, ?, ?)', [$mmsi, $ts, $ts]);
        $id = (int) Db::pdo()->lastInsertId();
        $this->passages[$id] = [
            'id' => $id, 'mmsi' => $mmsi, 'start_ts' => $ts, 'end_ts' => $ts, 'entry_lat' => null,
            'entry_lon' => null, 'exit_lat' => null, 'exit_lon' => null, 'moved_nm' => 0,
            'max_dist_nm' => null, 'msgs' => 0, 'exit_ts' => 0, '_dirty' => true,
        ];
        return $id;
    }

    private function day(int $hour, int $ts): string
    {
        // Time zones with non-hour offsets (e.g. +05:30) need per-timestamp days.
        $offset = $this->tz->getOffset(new \DateTimeImmutable('@' . $ts));
        if ($offset % 3600 !== 0) {
            return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz)->format('Y-m-d');
        }
        return $this->dayCache[$hour] ??= (new \DateTimeImmutable('@' . $hour))->setTimezone($this->tz)->format('Y-m-d');
    }

    private function flush(): void
    {
        $vrows = [];
        foreach (array_keys($this->dirty) as $mmsi) {
            $v = $this->vessels[$mmsi];
            $vrows[] = [
                $mmsi, $v['name'], $v['callsign'], $v['imo'], $v['eni'], $v['shiptype'], $v['vclass'],
                $v['length_m'], $v['beam_m'], $v['draught_m'], $v['country'], $v['destination'], $v['eta'],
                $v['first_seen'], $v['last_seen'], $v['msgs'], $v['passages'], $v['max_dist_nm'],
                $v['max_speed_kn'], $v['last_lat'], $v['last_lon'], $v['last_pos_ts'], $v['last_sog'],
                $v['last_cog'], $v['last_signal'], $v['cur_passage_id'], $v['static_updated'],
            ];
        }
        $cols = ['mmsi', 'name', 'callsign', 'imo', 'eni', 'shiptype', 'vclass', 'length_m', 'beam_m', 'draught_m',
            'country', 'destination', 'eta', 'first_seen', 'last_seen', 'msgs', 'passages', 'max_dist_nm',
            'max_speed_kn', 'last_lat', 'last_lon', 'last_pos_ts', 'last_sog', 'last_cog', 'last_signal',
            'cur_passage_id', 'static_updated'];
        $upd = implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", array_slice($cols, 1)));
        self::bulk('INSERT INTO vessel (' . implode(',', $cols) . ') VALUES %s ON DUPLICATE KEY UPDATE ' . $upd, $vrows);

        $st = Db::pdo()->prepare(
            'UPDATE passage SET end_ts = ?, msgs = ?, entry_lat = ?, entry_lon = ?, exit_lat = ?, exit_lon = ?,
             moved_nm = ?, max_dist_nm = ? WHERE id = ?'
        );
        foreach ($this->passages as $id => $p) {
            if (empty($p['_dirty'])) {
                continue;
            }
            $st->execute([$p['end_ts'], $p['msgs'], $p['entry_lat'], $p['entry_lon'], $p['exit_lat'],
                $p['exit_lon'], $p['moved_nm'], $p['max_dist_nm'], $id]);
        }

        $rows = [];
        foreach ($this->hourly as $hour => $h) {
            $rows[] = [$hour, $h['msgs'], $h['a'], $h['b'], $h['dist'], $h['sig_sum'], $h['sig_n'], $h['sig_min'], $h['sig_max']];
        }
        self::bulk(
            'INSERT INTO stats_hourly (hour_ts, msgs, msgs_a, msgs_b, max_dist_nm, sig_sum, sig_n, sig_min, sig_max) VALUES %s
             ON DUPLICATE KEY UPDATE msgs = msgs + VALUES(msgs), msgs_a = msgs_a + VALUES(msgs_a), msgs_b = msgs_b + VALUES(msgs_b),
             max_dist_nm = GREATEST(COALESCE(max_dist_nm, VALUES(max_dist_nm)), COALESCE(VALUES(max_dist_nm), max_dist_nm)),
             sig_sum = sig_sum + VALUES(sig_sum), sig_n = sig_n + VALUES(sig_n),
             sig_min = LEAST(COALESCE(sig_min, VALUES(sig_min)), COALESCE(VALUES(sig_min), sig_min)),
             sig_max = GREATEST(COALESCE(sig_max, VALUES(sig_max)), COALESCE(VALUES(sig_max), sig_max))',
            $rows
        );

        $rows = [];
        foreach ($this->vHourly as $hour => $set) {
            foreach (array_keys($set) as $mmsi) {
                $rows[] = [$hour, $mmsi];
            }
        }
        self::bulk('INSERT IGNORE INTO vessel_hourly (hour_ts, mmsi) VALUES %s', $rows);

        $rows = [];
        foreach ($this->vDaily as $day => $set) {
            foreach ($set as $mmsi => $d) {
                $rows[] = [$day, $mmsi, $d['msgs'], $d['dist']];
            }
        }
        self::bulk(
            'INSERT INTO vessel_daily (day, mmsi, msgs, max_dist_nm) VALUES %s ON DUPLICATE KEY UPDATE msgs = msgs + VALUES(msgs),
             max_dist_nm = GREATEST(COALESCE(max_dist_nm, VALUES(max_dist_nm)), COALESCE(VALUES(max_dist_nm), max_dist_nm))',
            $rows
        );

        $rows = [];
        foreach ($this->mType as $day => $types) {
            foreach ($types as $type => $n) {
                $rows[] = [$day, $type, $n];
            }
        }
        self::bulk('INSERT INTO msgtype_daily (day, type, msgs) VALUES %s ON DUPLICATE KEY UPDATE msgs = msgs + VALUES(msgs)', $rows);

        self::bulk('INSERT IGNORE INTO position (mmsi, minute, lat, lon, sog, cog) VALUES %s', array_values($this->positions));

        $rows = [];
        foreach ($this->polar as $key => $p) {
            [$day, $sector] = explode('|', $key);
            $rows[] = [$day, (int) $sector, $p['dist'], $p['mmsi']];
        }
        self::bulk(
            'INSERT INTO range_polar (day, sector, max_dist_nm, mmsi) VALUES %s ON DUPLICATE KEY UPDATE
             mmsi = IF(VALUES(max_dist_nm) > max_dist_nm, VALUES(mmsi), mmsi),
             max_dist_nm = GREATEST(max_dist_nm, VALUES(max_dist_nm))',
            $rows
        );
    }

    /** @param array<int, array<int, mixed>> $rows */
    public static function bulk(string $sql, array $rows, int $chunk = 400): void
    {
        if ($rows === []) {
            return;
        }
        $width = count($rows[0]);
        $tuple = '(' . implode(',', array_fill(0, $width, '?')) . ')';
        foreach (array_chunk($rows, $chunk) as $part) {
            $params = [];
            foreach ($part as $r) {
                foreach ($r as $val) {
                    $params[] = $val;
                }
            }
            Db::run(sprintf($sql, implode(',', array_fill(0, count($part), $tuple))), $params);
        }
    }

    public static function vesselClass(int $mmsi, int $type, string $current): string
    {
        $s = str_pad((string) $mmsi, 9, '0', STR_PAD_LEFT);
        if (str_starts_with($s, '00')) {
            return 'BASE';
        }
        if (str_starts_with($s, '99')) {
            return 'ATON';
        }
        if (str_starts_with($s, '111')) {
            return 'SAR';
        }
        if (str_starts_with($s, '970') || str_starts_with($s, '972') || str_starts_with($s, '974')) {
            return 'EMRG';
        }
        return match (true) {
            $type === 4 => 'BASE',
            $type === 21 => 'ATON',
            $type === 9 => 'SAR',
            in_array($type, [1, 2, 3, 5], true) => 'A',
            in_array($type, [18, 19, 24], true) && $current !== 'A' => 'B',
            default => $current,
        };
    }

    public static function validImo(int $imo): bool
    {
        if ($imo < 1000000 || $imo > 9999999) {
            return false;
        }
        $d = str_split((string) $imo);
        $sum = 0;
        for ($i = 0; $i < 6; $i++) {
            $sum += (int) $d[$i] * (7 - $i);
        }
        return $sum % 10 === (int) $d[6];
    }

    public static function cleanText(mixed $s, int $max): ?string
    {
        if (!is_string($s)) {
            return null;
        }
        $s = str_replace('@', ' ', $s);
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }
}
