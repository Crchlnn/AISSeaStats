<?php
declare(strict_types=1);

/**
 * Dependency-free test runner: php tests/run.php
 * Unit tests always run; the ingestion test runs when DB_HOST points to an
 * empty test database (it creates its own tables and truncates them).
 */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Geo;
use AISSeaStats\Ingest;
use AISSeaStats\Migrator;
use AISSeaStats\Rules;
use AISSeaStats\Settings;
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
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
