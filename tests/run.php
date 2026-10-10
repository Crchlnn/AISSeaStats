<?php
declare(strict_types=1);

/**
 * Dependency-free test runner: php tests/run.php
 * Unit tests always run; the ingestion test runs when DB_HOST points to an
 * empty test database (it creates its own tables and truncates them).
 */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Alert;
use AISSeaStats\Db;
use AISSeaStats\Geo;
use AISSeaStats\Ingest;
use AISSeaStats\Migrator;
use AISSeaStats\Rules;
use AISSeaStats\Settings;
use AISSeaStats\Stats;
use AISSeaStats\Worker;

$fail = 0;
$pass = 0;
function check(string $name, bool $ok): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL  {$name}\n";
}

// Geo
check('distance Paris-London ~ 186 NM', abs(Geo::distanceNm(48.8566, 2.3522, 51.5074, -0.1278) - 186.0) < 2);
check('bearing north', abs(Geo::bearing(49, 6, 50, 6)) < 0.01);
check('bearing east ~90', abs(Geo::bearing(0, 0, 0, 1) - 90) < 0.01);
check('sector labels', Geo::sectorLabel(0) === 'N' && Geo::sectorLabel(44) === 'NE' && Geo::sectorLabel(350) === 'N' && Geo::sectorLabel(200) === 'S');
check('sentinel position rejected', !Geo::validPosition(91, 181));
check('null island rejected', !Geo::validPosition(0, 0));
check('valid position', Geo::validPosition('49.1', '6.2'));

// Ingest helpers
check('IMO checksum valid', Ingest::validImo(9074729));
check('IMO checksum invalid', !Ingest::validImo(9074728));
check('class base station', Ingest::vesselClass(2275400, 4, '') === 'BASE');
check('class AtoN by MMSI', Ingest::vesselClass(992271234, 21, '') === 'ATON');
check('class SAR aircraft', Ingest::vesselClass(111227501, 9, '') === 'SAR');
check('class B kept A', Ingest::vesselClass(227000001, 18, 'A') === 'A');
check('class B', Ingest::vesselClass(227000001, 24, '') === 'B');
check('clean AIS text', Ingest::cleanText('STOLT@@@@  ', 32) === 'STOLT');
check('clean empty text', Ingest::cleanText('@@@@', 32) === null);

$payload = json_encode(['protocol' => 'jsonaiscatcher', 'stationid' => 'S1', 'msgs' => [['mmsi' => 1, 'type' => 1]]]);
$d = Ingest::decodeBody((string) gzencode((string) $payload), 'gzip');
check('decode gzip AISCATCHER', $d['station'] === 'S1' && count($d['msgs']) === 1);
$d = Ingest::decodeBody("{\"mmsi\":1,\"type\":1}\n{\"mmsi\":2,\"type\":1}\n");
check('decode LIST protocol', count($d['msgs']) === 2);
try {
    Ingest::decodeBody("\x1f\x8bgarbage");
    check('bad gzip rejected', false);
} catch (InvalidArgumentException) {
    check('bad gzip rejected', true);
}

// Rules
check('MID France', AISSeaStats\Mid::country(227123456) === 'FR');
check('MID Netherlands', AISSeaStats\Mid::country(244150672) === 'NL');
check('MID base station', AISSeaStats\Mid::country(2275400) === 'FR');
check('MID AtoN', AISSeaStats\Mid::country(992351234) === 'GB');
check('MID SAR aircraft', AISSeaStats\Mid::country(111227501) === 'FR');
check('MID none for SART', AISSeaStats\Mid::country(970123456) === null);

$r = Rules::defaults();
$v = ['mmsi' => 227000001, 'name' => 'X', 'shiptype' => 37, 'length_m' => 40, 'country' => 'FR', 'vclass' => 'A'];
check('superyacht tag', in_array('yacht', Rules::tagsFor($v, $r, ['FR' => 50]), true));
check('rare flag tag', in_array('rare_flag', Rules::tagsFor($v, $r, ['FR' => 2, 'NL' => 200]), true));
check('no rare flag on a small fleet', !in_array('rare_flag', Rules::tagsFor($v, $r, ['FR' => 2, 'NL' => 5]), true));
$r['watchlist'] = ['227000001'];
check('watchlist by MMSI', in_array('watchlist', Rules::tagsFor($v, $r, ['FR' => 50]), true));
$r['military_prefixes'] = ['2270'];
check('military prefix', in_array('military', Rules::tagsFor($v, $r, ['FR' => 50]), true));

// Photo sources: parsing of the documented Wikimedia / Wikidata answers.
$commons = ['query' => ['pages' => [['imageinfo' => [[
    'mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/x/640px-Ship.jpg',
    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Ship.jpg',
    'extmetadata' => ['Artist' => ['value' => '<a href="#">Jane Doe</a>'], 'LicenseShortName' => ['value' => 'CC BY-SA 4.0']],
]]]]]];
$pi = AISSeaStats\Enrich::parseImageInfo($commons, 'commons');
check('commons imageinfo parsed', $pi !== null && $pi['author'] === 'Jane Doe' && $pi['license'] === 'CC BY-SA 4.0' && str_starts_with($pi['thumb'], 'https://upload.wikimedia.org/'));
$bad = $commons; $bad['query']['pages'][0]['imageinfo'][0]['thumburl'] = 'https://evil.example/x.jpg';
check('foreign image host rejected', AISSeaStats\Enrich::parseImageInfo($bad, 'commons') === null);
check('wikidata search qid', AISSeaStats\Enrich::parseSearchQid(['query' => ['search' => [['title' => 'Q12345']]]]) === 'Q12345');
check('wikidata search empty', AISSeaStats\Enrich::parseSearchQid(['query' => ['search' => []]]) === null);
check('wikidata P18 claim', AISSeaStats\Enrich::parseImageClaim(['claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => 'Ship.jpg']]]]]]) === 'Ship.jpg');
check('wikidata P18 path rejected', AISSeaStats\Enrich::parseImageClaim(['claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => '../x.jpg']]]]]]) === null);

// Declared destinations
use AISSeaStats\Destinations;
check('dest key strips punctuation', Destinations::key(' fr sml ') === 'FRSML' && Destinations::key('St.-Malo') === 'STMALO');
check('dest junk is unknown', Destinations::key('0') === '?' && Destinations::key('Q') === '?' && Destinations::key('000') === '?' && Destinations::key('NONE') === '?' && Destinations::key('') === '?');
check('dest real short ports kept', Destinations::key('BREHAT') === 'BREHAT' && Destinations::key('FRCER') === 'FRCER');
$dict = Destinations::parseForm(['SAINT-MALO, ST-MALO; FR SML, 0', '', 'x'], ['frsml', 'FRCER', '']);
check('dest form parsed', $dict === [['to' => 'FRSML', 'from' => ['SAINT-MALO', 'ST-MALO', 'FR SML']]]);
$map = Destinations::map($dict);
check('dest canonical via alias', Destinations::canonical('Saint Malo', $map) === 'FRSML' && Destinations::canonical('st-malo', $map) === 'FRSML');
check('dest canonical untouched', Destinations::canonical('FRCER', $map) === 'FRCER' && Destinations::canonical('0', $map) === '?');
[$dsql, $dparams] = Destinations::sql($map);
check('dest sql parameters', substr_count($dsql, '?') - 1 === count($dparams) && end($dparams) === 'FRSML');
check('dest label locode', Destinations::label('FRSML', 'fr sml', $map) === 'FRSML' && Destinations::label('BREHAT', 'Bréhat', []) === 'Bréhat');

// Inland ERI ship types
check('eri motor freighter is cargo', Ingest::eriToAis(8010) === 79 && Ingest::eriToAis(8250) === 79);
check('eri tanker, passenger, unknown', Ingest::eriToAis(8021) === 89 && Ingest::eriToAis(8443) === 69 && Ingest::eriToAis(8000) === null);

// Debug capture
check('debug mmsi list', AISSeaStats\Debug::parseMmsi('226007350, 12, 244150672;226007350') === [226007350, 244150672]);
check('debug encode drops internal keys', AISSeaStats\Debug::encode(['mmsi' => 1, '_ts' => 5]) === '{"mmsi":1}');

// Distance bands
check('distance bands', Stats::band(null) === 3 && Stats::band(19.9) === 0 && Stats::band(20.0) === 1 && Stats::band(50.0) === 2);
check('gaps between passages', Stats::gapsOf([['start_ts' => 0, 'end_ts' => 100], ['start_ts' => 400, 'end_ts' => 500], ['start_ts' => 1500, 'end_ts' => 1600]]) === [300, 1000]);

// SMTP helpers
check('smtp addresses', AISSeaStats\Smtp::addresses("a@x.fr; b@y.com\nnope, a@x.fr") === ['a@x.fr', 'b@y.com']);
check('smtp header injection refused', !AISSeaStats\Smtp::validAddress("a@x.fr\r\nBcc: z@z.z"));
$msg = AISSeaStats\Smtp::message('a@x.fr', ['b@y.com'], 'Été', 'Réception');
check('smtp message utf-8', str_contains($msg, 'Subject: =?UTF-8?B?' . base64_encode('Été') . '?=') && str_contains($msg, base64_encode('Réception')));

// Integration (database)
if (getenv('DB_HOST')) {
    Db::waitReady(60);
    Migrator::run();
    foreach (['vessel', 'stats_hourly', 'stats_daily', 'vessel_hourly', 'vessel_daily', 'msgtype_daily', 'position',
        'passage', 'range_polar', 'setting', 'zone'] as $t) {
        Db::pdo()->exec("TRUNCATE TABLE {$t}");
    }
    Settings::flush();
    Settings::set('station_lat', 51.9225);
    Settings::set('station_lon', 4.4792);
    Settings::set('timezone', 'Europe/Paris');
    Settings::flush();
    $now = 1790000000;
    $msgs = [];
    for ($i = 0; $i < 30; $i++) {
        $msgs[] = ['mmsi' => 244030470, 'type' => 1, 'channel' => $i % 2 ? 'A' : 'B', 'rxuxtime' => $now - 7200 + $i * 60,
            'lat' => 51.77 + $i * 0.01, 'lon' => 4.4792, 'speed' => 8.5, 'course' => 0, 'signalpower' => -20];
    }
    $msgs[] = ['mmsi' => 244030470, 'type' => 5, 'rxuxtime' => $now - 7000, 'shipname' => 'STOLT', 'imo' => 9074729,
        'shiptype' => 80, 'to_bow' => 80, 'to_stern' => 30, 'to_port' => 8, 'to_starboard' => 8];
    $msgs[] = ['mmsi' => 244030470, 'type' => 1, 'rxuxtime' => $now - 5000, 'lat' => 54.8, 'lon' => 4.4792, 'speed' => 8]; // jump
    $res = (new Ingest($now))->process($msgs);
    check('ingest accepted', $res['accepted'] === 32 && $res['rejected_positions'] >= 1);
    $v = Db::one('SELECT * FROM vessel WHERE mmsi = 244030470');
    check('vessel static', $v !== null && $v['name'] === 'STOLT' && (int) $v['length_m'] === 110 && (int) $v['imo'] === 9074729);
check('flag from MMSI when not sent', $v['country'] === 'NL');
    check('one passage', (int) $v['passages'] === 1);
    check('positions sampled per minute', (int) Db::value('SELECT COUNT(*) FROM position') === 30);
    $w = new Worker();
    $w->closePassages($now + 3 * 3600);
    $p = Db::one('SELECT * FROM passage');
    check('route S -> N', $p['closed'] == 1 && $p['entry_zone'] === 'S' && $p['exit_zone'] === 'N');
    $w->tick($now);
    check('daily rollup', (int) Db::value('SELECT vessels FROM stats_daily') === 1);
    check('hourly vessels', (int) Db::value('SELECT MAX(vessels) FROM stats_hourly') === 1);
    // A new message after the gap starts a second passage.
    (new Ingest($now + 4 * 3600))->process([['mmsi' => 244030470, 'type' => 1, 'rxuxtime' => $now + 4 * 3600, 'lat' => 51.87, 'lon' => 4.50]]);
    check('second passage after gap', (int) Db::value('SELECT passages FROM vessel WHERE mmsi = 244030470') === 2);
    // Inland vessel heard through DAC 200 FID 10 only: ENI, length and type from the ERI code; type 5 wins later.
    (new Ingest($now))->process([['mmsi' => 226007350, 'type' => 8, 'dac' => 200, 'fid' => 10, 'rxuxtime' => $now,
        'vin' => '01823383', 'length' => 73.5, 'beam' => 8.2, 'shiptype' => 8010]]);
    $iv = Db::one('SELECT name, eni, shiptype, length_m FROM vessel WHERE mmsi = 226007350');
    check('inland static without type 5', $iv['name'] === null && $iv['eni'] === '01823383' && (int) $iv['shiptype'] === 79 && (int) $iv['length_m'] === 74);
    (new Ingest($now + 60))->process([['mmsi' => 226007350, 'type' => 5, 'rxuxtime' => $now + 60, 'shipname' => 'LU MA', 'shiptype' => 70]]);
    $iv = Db::one('SELECT name, shiptype FROM vessel WHERE mmsi = 226007350');
    check('type 5 completes inland vessel', $iv['name'] === 'LU MA' && (int) $iv['shiptype'] === 70);
    // Debug capture: only the followed MMSI is recorded, with its raw message; nothing once stopped.
    Db::pdo()->exec('TRUNCATE TABLE debug_msg');
    $logDir = sys_get_temp_dir() . '/aisseastats-debuglog-' . getmypid();
    putenv('DEBUG_LOG_DIR=' . $logDir);
    $t0 = time(); // a capture is "running" against the real clock
    AISSeaStats\Debug::start([226007350], 1, $t0);
    (new Ingest($t0 + 120))->process([
        ['mmsi' => 226007350, 'type' => 8, 'dac' => 200, 'fid' => 10, 'rxuxtime' => $t0 + 120, 'vin' => '01823383'],
        ['mmsi' => 244030470, 'type' => 1, 'rxuxtime' => $t0 + 120, 'lat' => 51.9, 'lon' => 4.4],
    ]);
    $dbg = AISSeaStats\Debug::summary();
    check('debug capture filtered', count($dbg) === 1 && $dbg[0]['mmsi'] === 226007350 && $dbg[0]['types'] === ['8 200/10' => 1]);
    check('debug raw kept', str_contains((string) Db::value('SELECT raw FROM debug_msg'), '"vin":"01823383"'));
    // The same capture is written live to a dated JSON Lines file.
    $logs = AISSeaStats\DebugLog::list();
    $lines = $logs ? file((string) AISSeaStats\DebugLog::path($logs[0]['name']), FILE_IGNORE_NEW_LINES) : [];
    $msgLine = $lines ? json_decode((string) end($lines), true) : null;
    check('debug log file written', count($logs) === 1 && preg_match('/^capture_\d{8}_\d{6}\.jsonl$/', $logs[0]['name']) === 1
        && count($lines) === 2 && json_decode($lines[0], true)['event'] === 'start'
        && $msgLine['event'] === 'msg' && $msgLine['mmsi'] === 226007350 && $msgLine['raw']['vin'] === '01823383');
    check('debug log path is safe', AISSeaStats\DebugLog::path('../' . $logs[0]['name']) !== null
        && AISSeaStats\DebugLog::path('../../etc/passwd') === null && AISSeaStats\DebugLog::path('capture_x.jsonl') === null);
    AISSeaStats\Debug::stop();
    (new Ingest($t0 + 180))->process([['mmsi' => 226007350, 'type' => 5, 'rxuxtime' => $t0 + 180, 'shipname' => 'LU MA']]);
    check('debug stopped', (int) Db::value('SELECT COUNT(*) FROM debug_msg') === 1);
    $lines = file((string) AISSeaStats\DebugLog::path($logs[0]['name']), FILE_IGNORE_NEW_LINES);
    check('debug log closed', count($lines) === 3 && json_decode((string) end($lines), true)['event'] === 'stop');
    AISSeaStats\DebugLog::delete($logs[0]['name']);
    check('debug log deleted', AISSeaStats\DebugLog::list() === []);
    @rmdir($logDir);
    putenv('DEBUG_LOG_DIR');
    Settings::set('debug_capture', null);
    // Long range: a lone far position is kept for the track but not for range records;
    // a second one shortly after, at a consistent place, counts (tropospheric ducting).
    $far = static fn (int $t, float $lat): array => ['mmsi' => 227000001, 'type' => 1, 'rxuxtime' => $t, 'lat' => $lat, 'lon' => 4.4792, 'speed' => 12];
    $r1 = (new Ingest($now + 5 * 3600))->process([$far($now + 5 * 3600, 60.0)]); // ~485 NM north
    check('far lone position not a record', $r1['unconfirmed_range'] === 1 && Db::value('SELECT max_dist_nm FROM vessel WHERE mmsi = 227000001') === null);
    $r2 = (new Ingest($now + 5 * 3600 + 120))->process([$far($now + 5 * 3600 + 120, 60.005)]);
    $md = (float) Db::value('SELECT max_dist_nm FROM vessel WHERE mmsi = 227000001');
    check('far confirmed position is a record', $r2['unconfirmed_range'] === 0 && $md > 480 && $md < 490);
    check('far range in polar', (float) Db::value('SELECT MAX(max_dist_nm) FROM range_polar') > 480);
    $r3 = (new Ingest($now + 6 * 3600))->process([['mmsi' => 227000002, 'type' => 1, 'rxuxtime' => $now + 6 * 3600, 'lat' => -40.0, 'lon' => 4.4792]]);
    check('beyond max range rejected', $r3['rejected_positions'] === 1);
    // Destinations grouped in SQL with the dictionary.
    Db::run("UPDATE vessel SET destination = 'SAINT-MALO' WHERE mmsi = 244030470");
    Db::run("UPDATE vessel SET destination = 'Q' WHERE mmsi = 227000001");
    [$dsql, $dparams] = Destinations::sql($map);
    check('dest sql alias', Db::value("SELECT {$dsql} FROM vessel WHERE mmsi = 244030470", $dparams) === 'FRSML');
    check('dest sql junk', Db::value("SELECT {$dsql} FROM vessel WHERE mmsi = 227000001", $dparams) === '?');
    // Photos: Commons empty for the IMO, then Wikidata by IMO finds an image.
    AISSeaStats\Db::pdo()->exec('TRUNCATE TABLE photo_lookup');
    AISSeaStats\Db::pdo()->exec('TRUNCATE TABLE vessel_photo');
    $calls = [];
    AISSeaStats\Enrich::$http = static function (string $url) use (&$calls, $commons): string {
        $calls[] = $url;
        if (str_contains($url, 'gcmtitle=Category%3AIMO')) { return '{"query":{"pages":[]}}'; }
        if (str_contains($url, 'haswbstatement%3AP458%3D9074729')) { return '{"query":{"search":[{"title":"Q42"}]}}'; }
        if (str_contains($url, 'wbgetclaims')) { return '{"claims":{"P18":[{"mainsnak":{"datavalue":{"value":"Ship.jpg"}}}]}}'; }
        if (str_contains($url, 'titles=File%3AShip.jpg')) { return json_encode($commons); }
        return '{}';
    };
    $ph = AISSeaStats\Enrich::photo(244030470, 9074729);
    check('photo via wikidata', $ph !== null && $ph['source'] === 'wikidata' && count($calls) === 4);
    $n = count($calls);
    $ph = AISSeaStats\Enrich::photo(244030470, 9074729);
    check('photo cached', $ph !== null && count($calls) === $n);
    AISSeaStats\Enrich::$http = static fn (string $url): false => false;
    check('network error: no photo, no crash', AISSeaStats\Enrich::photo(211000001, null) === null);
    AISSeaStats\Db::run('INSERT INTO vessel_photo (mmsi, mime, width, height, data, credit, uploaded_at) VALUES (?,?,?,?,?,?,?)',
        [244030470, 'image/png', 16, 16, 'x', 'Me', 1]);
    $ph = AISSeaStats\Enrich::photo(244030470, 9074729);
    check('local photo has priority', $ph !== null && $ph['source'] === 'local' && $ph['author'] === 'Me');
    AISSeaStats\Enrich::$http = null;

    // Alerts: one alert after the silence threshold, none repeated, one recovery message.
    $sent = [];
    Alert::$http = static function (string $url, string $body, array $headers, string $method) use (&$sent): array {
        $sent[] = [$url, json_decode($body, true), $method];
        return str_contains($url, 'api.telegram.org') ? [200, '{"ok":true}'] : [200, 'ok'];
    };
    $mails = [];
    Alert::$mailer = static function (array $cfg, string $subject, string $body) use (&$mails): void { $mails[] = [$cfg['to'], $subject]; };
    $cfg = Alert::fromForm(['alert_enabled' => '1', 'alert_after_min' => '30', 'ntfy_url' => 'https://ntfy.sh/', 'ntfy_topic' => 'ais-test_1',
        'telegram_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'telegram_chat' => '-100123', 'webhook_url' => 'https://hooks.example/x?token=1',
        'smtp_host' => 'smtp.example.com', 'smtp_port' => '587', 'smtp_security' => 'starttls', 'smtp_from' => 'st@example.com',
        'smtp_to' => 'a@example.com, bad, b@example.com', 'heartbeat_url' => 'https://hc.example/ping'], Alert::defaults(), 'fr');
    check('alert form parsed', $cfg['email']['to'] === 'a@example.com, b@example.com' && $cfg['ntfy']['url'] === 'https://ntfy.sh'
        && Alert::configured($cfg) === ['ntfy', 'telegram', 'webhook', 'email']);
    $kept = Alert::fromForm(['telegram_chat' => '42'], $cfg, 'fr');
    check('alert secrets kept when left empty', $kept['telegram']['token'] === $cfg['telegram']['token'] && !$kept['enabled']);
    check('alert secret cleared', Alert::fromForm(['telegram_token_clear' => '1'], $cfg, 'fr')['telegram']['token'] === '');
    $bad = false;
    try { Alert::fromForm(['webhook_url' => 'javascript:alert(1)'], $cfg, 'fr'); } catch (InvalidArgumentException $e) { $bad = $e->getMessage() === 'webhook_url'; }
    check('alert bad url rejected', $bad);
    Settings::set('alerts', $cfg);
    Settings::set('alert_state', null);
    $last = Alert::lastMessage();
    check('alert: nothing while fresh', Alert::check($last + 600) === null && count($sent) === 1 && $sent[0][2] === 'GET'); // heartbeat only
    $sent = [];
    check('alert: down after threshold', Alert::check($last + 31 * 60) === 'down' && count($sent) === 3 && count($mails) === 1);
    check('alert: ntfy json', $sent[0][0] === 'https://ntfy.sh/' && $sent[0][1]['topic'] === 'ais-test_1' && preg_match('/31\s?min/u', $sent[0][1]['message']) === 1);
    check('alert: french text', str_contains($mails[0][1], 'plus de données AIS'));
    check('alert: not repeated', Alert::check($last + 45 * 60) === null && count($sent) === 3);
    Db::run('UPDATE vessel SET last_seen = ? WHERE mmsi = 244030470', [$last + 50 * 60]);
    $ev = Alert::check($last + 51 * 60);
    $hook = array_values(array_filter($sent, static fn ($x) => str_starts_with($x[0], 'https://hooks.example/')));
    check('alert: recovery', $ev === 'up' && count($mails) === 2 && count($hook) === 2 && $hook[1][1]['event'] === 'up'
        && preg_match('/50\s?min/u', $hook[1][1]['text']) === 1);
    Settings::set('alert_state', null);
    $prevLog = ini_set('error_log', '/dev/null');
    Alert::$http = static fn (): array => [500, 'boom'];
    Alert::$mailer = static function (): void { throw new RuntimeException('smtp down'); };
    Db::run('UPDATE vessel SET last_seen = ? WHERE mmsi = 244030470', [$last]);
    Alert::check($last + 40 * 60);
    $st = Alert::state();
    check('alert: all channels failed, retried later', !$st['down'] && $st['retry'] > 0 && $st['results']['email'] === 'smtp down' && str_starts_with((string) $st['results']['ntfy'], 'HTTP 500'));
    ini_set('error_log', (string) $prevLog);
    Settings::set('alerts', null);
    Alert::$http = null;
    Alert::$mailer = null;

    // 1.1.0 statistics, on controlled data (2024) after emptying the tables they read.
    foreach (['stats_hourly', 'stats_daily', 'vessel_daily', 'vessel_hourly', 'passage', 'range_polar'] as $t) {
        Db::pdo()->exec("TRUNCATE TABLE {$t}");
    }
    $utc = new DateTimeZone('UTC');
    // Distance bands: < 20, 20-50, >= 50 NM, unknown; per month a vessel counts once, at its furthest.
    Ingest::bulk('INSERT INTO vessel_daily (day, mmsi, msgs, max_dist_nm) VALUES %s', [
        ['2024-01-10', 900000001, 1, 5], ['2024-01-10', 900000002, 1, 25], ['2024-01-10', 900000003, 1, 60],
        ['2024-01-10', 900000004, 1, null], ['2024-01-11', 900000001, 1, 70],
    ]);
    check('bands by day', (Stats::bandsByDay('2024-01-01')['2024-01-10'] ?? null) === [1, 1, 1, 1]);
    check('bands by month', (Stats::bandsByMonth('2024-01-01')['2024-01'] ?? null) === [0, 1, 2, 1]);
    // The hourly distance is recorded by ingestion (about 4.6 NM from the station).
    $h0 = 1790100000;
    (new Ingest($h0))->process([['mmsi' => 900000009, 'type' => 1, 'rxuxtime' => $h0, 'lat' => 52.0, 'lon' => 4.4792]]);
    $hb = Stats::bandsByHour(intdiv($h0, 3600) * 3600);
    check('bands by hour from ingestion', ($hb[intdiv($h0, 3600) * 3600] ?? null) === [1, 0, 0, 0]
        && abs((float) Db::value('SELECT max_dist_nm FROM vessel_hourly WHERE mmsi = 900000009') - 4.6) < 0.2);
    // Propagation: 20 ordinary days at 30 NM, then 100 NM with 3 vessels beyond 45 NM (event), then 100 NM with only 2 (no event).
    $daily = [];
    for ($i = 1; $i <= 20; $i++) {
        $daily[] = [sprintf('2024-02-%02d', $i), 50, 1000, 30];
    }
    $daily[] = ['2024-02-21', 50, 1000, 100];
    $daily[] = ['2024-02-22', 50, 1000, 100];
    Ingest::bulk('INSERT INTO stats_daily (day, vessels, msgs, max_dist_nm) VALUES %s', $daily);
    Ingest::bulk('INSERT INTO vessel_daily (day, mmsi, msgs, max_dist_nm) VALUES %s', [
        ['2024-02-21', 900000001, 1, 60], ['2024-02-21', 900000002, 1, 70], ['2024-02-21', 900000003, 1, 100], ['2024-02-21', 900000004, 1, 10],
        ['2024-02-22', 900000001, 1, 90], ['2024-02-22', 900000002, 1, 100], ['2024-02-22', 900000003, 1, 12],
    ]);
    $ev = Stats::propagation(new DateTimeImmutable('2024-02-15', $utc), new DateTimeImmutable('2024-02-22', $utc));
    check('propagation day detected', count($ev) === 1 && $ev[0]['t'] === '2024-02-21' && $ev[0]['usual'] === 30.0 && $ev[0]['far'] === 3 && $ev[0]['threshold'] === 45.0);
    check('median', Stats::median([3.0, 1.0, 2.0]) === 2.0 && Stats::median([4.0, 1.0, 2.0, 3.0]) === 2.5);
    // Furthest vessels, with day and direction of the record (sector 31 = 310-320°, north-west).
    Ingest::bulk('INSERT INTO range_polar (day, sector, max_dist_nm, mmsi) VALUES %s',
        [['2024-02-21', 31, 100, 900000003], ['2024-02-21', 4, 70, 900000002], ['2024-02-20', 31, 20, 900000003]]);
    $far = Stats::furthest('2024-02-01');
    check('furthest vessels', count($far) === 3 && $far[0]['mmsi'] === 900000003 && $far[0]['dist'] === 100.0
        && $far[0]['day'] === '2024-02-21' && $far[0]['dir'] === 'NW' && $far[0]['ts'] === null);
    // 1.1.1: who set each sector's record and when; a time off the record's day (approximate backfill) is not shown.
    $rt = (int) (new DateTimeImmutable('2024-02-21 10:15', $utc))->getTimestamp();
    Db::run("UPDATE range_polar SET ts = ? WHERE day = '2024-02-21' AND sector = 31", [$rt]);
    Db::run("UPDATE range_polar SET ts = ? WHERE day = '2024-02-21' AND sector = 4", [$rt + 86400]);
    $recs = Stats::sectorRecords('2024-02-01', $utc);
    check('sector records', count($recs) === 36 && $recs[31]['mmsi'] === 900000003 && $recs[31]['day'] === '2024-02-21'
        && $recs[31]['ts'] === $rt && $recs[4]['mmsi'] === 900000002 && $recs[4]['ts'] === null && $recs[5] === null);
    check('sector records since a day', Stats::sectorRecords('2024-02-22', $utc)[31] === null);
    check('furthest vessel time', Stats::furthest('2024-02-01', $utc)[0]['ts'] === $rt);
    $late = (int) (new DateTimeImmutable('2024-02-20 23:30', $utc))->getTimestamp(); // 21 Feb 00:30 in Paris
    check('record time on its day', Stats::timeOnDay($late, '2024-02-21', new DateTimeZone('Europe/Paris')) === $late
        && Stats::timeOnDay($late, '2024-02-21', $utc) === null && Stats::timeOnDay(null, '2024-02-21', $utc) === null);
    // Ingestion keeps the time of the furthest position of the day and sector, across batches.
    foreach ([[100, 52.05], [1000, 52.08], [2000, 52.06]] as [$dt, $la]) {
        (new Ingest($h0 + $dt))->process([['mmsi' => 900000020, 'type' => 1, 'rxuxtime' => $h0 + $dt, 'lat' => $la, 'lon' => 4.4792]]);
    }
    $rec0 = Db::one('SELECT mmsi, ts FROM range_polar WHERE sector = 0 ORDER BY max_dist_nm DESC LIMIT 1');
    check('record time from ingestion', $rec0 !== null && (int) $rec0['mmsi'] === 900000020 && (int) $rec0['ts'] === $h0 + 1000);
    // Time between passages: A every 10 h (1 h long, so 9 h away), B irregular, C only 3 passages.
    Ingest::bulk('INSERT IGNORE INTO vessel (mmsi, name, vclass, first_seen, last_seen) VALUES %s',
        [[900000011, 'REGULAR', 'A', 1, 1], [900000012, 'IRREGULAR', 'A', 1, 1], [900000013, 'RARE', 'A', 1, 1]]);
    $t0 = 1704067200; // 2024-01-01 00:00 UTC, a Monday
    $passRows = [];
    foreach ([0, 10, 20, 30, 40] as $hh) {
        $passRows[] = [900000011, $t0 + $hh * 3600, $t0 + ($hh + 1) * 3600, 1];
    }
    foreach ([0, 3, 24, 30, 71] as $hh) {
        $passRows[] = [900000012, $t0 + $hh * 3600, $t0 + ($hh + 1) * 3600, 1];
    }
    foreach ([0, 10, 20] as $hh) {
        $passRows[] = [900000013, $t0 + $hh * 3600, $t0 + ($hh + 1) * 3600, 1];
    }
    Ingest::bulk('INSERT INTO passage (mmsi, start_ts, end_ts, closed) VALUES %s', $passRows);
    $reg = Stats::regulars($t0 - 1);
    check('regulars ranked by regularity', count($reg) === 2 && $reg[0]['mmsi'] === 900000011 && $reg[0]['avg'] === 32400
        && $reg[0]['sd'] === 0 && $reg[0]['passages'] === 5 && $reg[0]['name'] === 'REGULAR' && $reg[1]['mmsi'] === 900000012);
    $g = Stats::vesselGaps(900000011);
    check('vessel gaps', $g !== null && $g['count'] === 4 && $g['avg'] === 32400 && $g['min'] === 32400 && $g['max'] === 32400);
    check('gaps of a single passage', Stats::gapStats(Stats::gapsOf([['start_ts' => 1, 'end_ts' => 2]])) === null);
    // Reception: 48 h from Monday 00:00 UTC, nothing received at 05:00, 06:00 and 07:00 on the first day.
    $hours = [];
    for ($i = 0; $i < 48; $i++) {
        if ($i < 5 || $i > 7) {
            $hours[] = [$t0 + $i * 3600, 10, 3];
        }
    }
    Db::pdo()->exec('TRUNCATE TABLE stats_hourly'); // drop the hour written by the ingestion test above
    Ingest::bulk('INSERT INTO stats_hourly (hour_ts, msgs, vessels) VALUES %s', $hours);
    $up = Stats::uptime(2, 2, $t0 + 47 * 3600 + 60, $utc); // Tuesday 23:01: 47 hours completed
    check('uptime share and gaps', $up['hours'] === 47 && $up['ok_hours'] === 44 && $up['uptime'] === 93.6
        && $up['gaps_total'] === 1 && $up['longest'] === 3 && $up['gaps'][0]['from'] === $t0 + 5 * 3600);
    check('uptime strip', count($up['strip']) === 2 && $up['strip'][0]['day'] === '2024-01-01'
        && $up['strip'][0]['cells'][4] === 'ok' && $up['strip'][0]['cells'][5] === 'none' && $up['strip'][1]['cells'][23] === 'ok');
    $hm = Stats::heatmap($t0, $utc);
    check('heatmap by weekday and hour', $hm['hours'] === 45 && $hm['cells'][0][0] === 3.0 && $hm['cells'][0][5] === null
        && $hm['cells'][1][5] === 3.0 && $hm['cells'][2][0] === null && $hm['max'] === 3.0);
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
