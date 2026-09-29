<?php
declare(strict_types=1);

/**
 * Demo traffic generator. Produces AIS-catcher style JSON around the station.
 *
 *   php bin/simulate.php --hours 72                 backfill 72 h directly into the database
 *   php bin/simulate.php --live --url http://127.0.0.1:8080/ingest.php --token XXX
 *                                                   post a batch every 15 s over HTTP (gzip)
 *
 * Remove demo data afterwards with: php bin/reset-data.php --yes
 */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Ingest;
use AISSeaStats\Settings;

$opts = getopt('', ['hours:', 'live', 'url:', 'token:', 'vessels:', 'seed:']);
$hours = (int) ($opts['hours'] ?? 48);
$live = isset($opts['live']);
$fleetSize = (int) ($opts['vessels'] ?? 90);
mt_srand((int) ($opts['seed'] ?? 42));

Db::waitReady(60);
if (!Settings::hasStation()) {
    fwrite(STDERR, "Set the station position first (setup wizard).\n");
    exit(1);
}
$sLat = (float) Settings::get('station_lat');
$sLon = (float) Settings::get('station_lon');

/** Point at a bearing/distance (NM) from the station. */
$at = static function (float $brg, float $nm) use ($sLat, $sLon): array {
    $d = $nm / 3440.065;
    $b = deg2rad($brg);
    $p1 = deg2rad($sLat);
    $l1 = deg2rad($sLon);
    $p2 = asin(sin($p1) * cos($d) + cos($p1) * sin($d) * cos($b));
    $l2 = $l1 + atan2(sin($b) * sin($d) * cos($p1), cos($d) - sin($p1) * sin($p2));
    return [rad2deg($p2), rad2deg($l2)];
};
$lanes = [
    [$at(200, 26), $at(15, 24)],
    [$at(265, 30), $at(95, 28)],
    [$at(150, 14), $at(330, 18)],
    [$at(230, 20), $at(60, 22)],
];

$mids = [['227', 'FR'], ['211', 'DE'], ['244', 'NL'], ['205', 'BE'], ['253', 'LU'], ['636', 'LR'],
    ['538', 'MH'], ['229', 'MT'], ['255', 'PT'], ['319', 'KY'], ['352', 'PA'], ['257', 'NO']];
$types = [
    // [shiptype, weight, min len, max len, speed, class, prefix]
    [70, 22, 90, 230, 12, 'A', ['ATLANTIC', 'NORDIC', 'RHINE', 'DANUBE', 'EUROPA', 'BALTIC']],
    [80, 10, 100, 250, 11, 'A', ['STAR', 'PETRO', 'OCEAN', 'GAS']],
    [60, 6, 60, 180, 16, 'A', ['PRINCESS', 'VIKING', 'SPIRIT', 'RIVER']],
    [37, 18, 8, 16, 7, 'B', ['LIBERTE', 'SEA LA VIE', 'ALBATROS', 'MISTRAL', 'ODYSSEE']],
    [37, 3, 26, 70, 13, 'A', ['LADY', 'ECLIPSE', 'SERENITY', 'ALFA']],
    [52, 6, 20, 35, 9, 'A', ['TUG', 'HERCULES', 'ABEILLE']],
    [30, 6, 12, 30, 8, 'A', ['PECHEUR', 'NOTRE DAME', 'KERGUELEN']],
    [35, 2, 90, 150, 15, 'A', ['FS', 'BAP']],
    [55, 2, 20, 40, 18, 'A', ['GENDARMERIE', 'DOUANE']],
    [81, 3, 150, 280, 12, 'A', ['CHEM', 'HAZMAT']],
];
$weights = array_sum(array_column($types, 1));
$pickType = static function () use ($types, $weights): array {
    $r = mt_rand(1, $weights);
    foreach ($types as $t) {
        $r -= $t[1];
        if ($r <= 0) {
            return $t;
        }
    }
    return $types[0];
};
$imo = static function (): int {
    $d = [mt_rand(1, 9), mt_rand(0, 9), mt_rand(0, 9), mt_rand(0, 9), mt_rand(0, 9), mt_rand(0, 9)];
    $sum = 0;
    foreach ($d as $i => $x) {
        $sum += $x * (7 - $i);
    }
    return (int) (implode('', $d) . ($sum % 10));
};

$fleet = [];
for ($i = 0; $i < $fleetSize; $i++) {
    $t = $pickType();
    [$mid, $cc] = $t[0] === 35 || $t[0] === 55 ? ['227', 'FR'] : $mids[mt_rand(0, count($mids) - 1)];
    $len = mt_rand($t[2], $t[3]);
    $fleet[] = [
        'mmsi' => (int) ($mid . str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT)),
        'cc' => $cc,
        'type' => $t[0],
        'class' => $t[5],
        'name' => $t[6][mt_rand(0, count($t[6]) - 1)] . ' ' . strtoupper(substr(md5((string) $i), 0, 3)),
        'imo' => $t[5] === 'A' && $len > 40 ? $imo() : 0,
        'len' => $len,
        'beam' => max(3, (int) ($len / 6.5)),
        'speed' => $t[4] * (0.8 + mt_rand(0, 40) / 100),
        'lane' => mt_rand(0, count($lanes) - 1),
        'dest' => ['FRLEH', 'DEHAM', 'NLRTM', 'BEANR', 'FRMRS', 'ESBCN', 'GBFXT', ''][mt_rand(0, 7)],
        'next' => 0,
    ];
}
// One SAR helicopter.
$fleet[] = ['mmsi' => 111227501, 'cc' => 'FR', 'type' => 0, 'class' => 'SAR', 'name' => '', 'imo' => 0, 'len' => 0,
    'beam' => 0, 'speed' => 110, 'lane' => 1, 'dest' => '', 'next' => 0];

/** @return array<int, array<string, mixed>> messages for one vessel between t0 and t1 */
$emit = static function (array &$v, int $t0, int $t1) use ($lanes, $sLat, $sLon): array {
    $out = [];
    if ($v['next'] === 0) {
        $v['next'] = $t0 + mt_rand(0, 36 * 3600);
    }
    if (!isset($v['trip']) && $t1 >= $v['next']) {
        [$a, $b] = $lanes[$v['lane']];
        if (mt_rand(0, 1)) {
            [$a, $b] = [$b, $a];
        }
        $nm = AISSeaStats\Geo::distanceNm($a[0], $a[1], $b[0], $b[1]);
        $v['trip'] = ['a' => $a, 'b' => $b, 'start' => $v['next'], 'dur' => (int) ($nm / $v['speed'] * 3600)];
    }
    if (!isset($v['trip'])) {
        return [];
    }
    $trip = $v['trip'];
    $step = $v['class'] === 'SAR' ? 20 : 60;
    for ($ts = max($t0, $trip['start']); $ts < min($t1, $trip['start'] + $trip['dur']); $ts += $step) {
        $f = ($ts - $trip['start']) / max(1, $trip['dur']);
        $lat = $trip['a'][0] + ($trip['b'][0] - $trip['a'][0]) * $f + mt_rand(-20, 20) / 100000;
        $lon = $trip['a'][1] + ($trip['b'][1] - $trip['a'][1]) * $f + mt_rand(-20, 20) / 100000;
        $course = AISSeaStats\Geo::bearing($trip['a'][0], $trip['a'][1], $trip['b'][0], $trip['b'][1]);
        $base = [
            'class' => 'AIS', 'device' => 'AIS-catcher', 'rxuxtime' => $ts + mt_rand(0, 999) / 1000,
            'rxtime' => gmdate('YmdHis', $ts), 'channel' => mt_rand(0, 1) ? 'A' : 'B',
            'signalpower' => round(-8 - AISSeaStats\Geo::distanceNm($sLat, $sLon, $lat, $lon) * 0.9 - mt_rand(0, 60) / 10, 1),
            'ppm' => 0.5, 'country_code' => $v['cc'], 'mmsi' => $v['mmsi'],
        ];
        if ($v['class'] === 'SAR') {
            $out[] = $base + ['type' => 9, 'lat' => round($lat, 6), 'lon' => round($lon, 6), 'speed' => 110,
                'course' => round($course, 1), 'alt' => 300];
            continue;
        }
        $posType = $v['class'] === 'A' ? 1 : 18;
        $out[] = $base + ['type' => $posType, 'lat' => round($lat, 6), 'lon' => round($lon, 6),
            'speed' => round($v['speed'] + mt_rand(-5, 5) / 10, 1), 'course' => round($course, 1),
            'heading' => (int) $course, 'status' => 0];
        if (($ts - $trip['start']) % 360 < $step) {
            $half = intdiv($v['len'], 2);
            $dims = ['to_bow' => $half, 'to_stern' => $v['len'] - $half, 'to_port' => intdiv($v['beam'], 2),
                'to_starboard' => $v['beam'] - intdiv($v['beam'], 2)];
            if ($v['class'] === 'A') {
                $out[] = $base + ['type' => 5, 'shipname' => $v['name'], 'callsign' => 'F' . substr((string) $v['mmsi'], -4),
                    'imo' => $v['imo'], 'shiptype' => $v['type'], 'destination' => $v['dest'], 'draught' => 4.5,
                    'eta' => '10-02T14:00Z'] + $dims;
            } else {
                $out[] = $base + ['type' => 24, 'partno' => 0, 'shipname' => $v['name']];
                $out[] = $base + ['type' => 24, 'partno' => 1, 'shiptype' => $v['type'], 'callsign' => 'FB' . substr((string) $v['mmsi'], -3)] + $dims;
            }
        }
    }
    if ($t1 >= $trip['start'] + $trip['dur']) {
        unset($v['trip']);
        $v['next'] = $trip['start'] + $trip['dur'] + mt_rand(4 * 3600, 60 * 3600);
        if (mt_rand(0, 3) === 0) {
            $v['lane'] = mt_rand(0, count($lanes) - 1);
        }
    }
    return $out;
};

$post = static function (array $msgs, string $url, string $token): void {
    $body = gzencode((string) json_encode(['protocol' => 'jsonaiscatcher', 'stationid' => 'simulator', 'msgs' => $msgs]));
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 20, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nContent-Encoding: gzip\r\nAuthorization: Basic "
            . base64_encode('aisseastats:' . $token) . "\r\n", 'content' => $body]]);
    $res = @file_get_contents($url, false, $ctx);
    echo date('H:i:s') . ' posted ' . count($msgs) . ' msgs -> ' . ($res === false ? 'ERROR' : $res) . "\n";
};

$now = time();
if ($live) {
    $url = (string) ($opts['url'] ?? 'http://127.0.0.1:8080/ingest.php');
    $token = (string) ($opts['token'] ?? '');
    foreach ($fleet as &$v) {
        $v['next'] = $now + mt_rand(-3 * 3600, 3 * 3600);
    }
    unset($v);
    $t = $now;
    while (true) {
        $msgs = [];
        foreach ($fleet as &$v) {
            $msgs = array_merge($msgs, $emit($v, $t, $t + 15));
        }
        unset($v);
        if ($msgs !== []) {
            $post($msgs, $url, $token);
        }
        $t += 15;
        sleep(15);
    }
}

$start = $now - $hours * 3600;
$total = 0;
$worker = new AISSeaStats\Worker();
for ($t = $start; $t < $now; $t += 600) {
    $msgs = [];
    foreach ($fleet as &$v) {
        $msgs = array_merge($msgs, $emit($v, $t, min($now, $t + 600)));
    }
    unset($v);
    if ($msgs !== []) {
        (new Ingest($now))->process($msgs);
        $total += count($msgs);
    }
    if (($t - $start) % 7200 === 0) {
        $worker->closePassages($t);
    }
}
$worker->tick($now);
echo "Simulated {$hours} h: {$total} messages from " . count($fleet) . " vessels.\n";
