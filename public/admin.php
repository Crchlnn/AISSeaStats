<?php
declare(strict_types=1);

/** Administration: health, settings, rules, zones, ingestion token, data management. */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Alert;
use AISSeaStats\Db;
use AISSeaStats\Destinations;
use AISSeaStats\I18n;
use AISSeaStats\Page;
use AISSeaStats\Rules;
use AISSeaStats\Settings;
use AISSeaStats\Web;
use AISSeaStats\Worker;

try {
    Web::requireSetup();
} catch (Throwable $e) {
    http_response_code(503);
    exit('Database unavailable. Check that the "db" container is running.');
}
Web::session();
$t = static fn (string $k, array $v = []): string => Web::e(I18n::t($k, $v));
$flash = [];
$newToken = null;
$alertTest = null;
$alertDraft = null;
$action = (string) ($_POST['action'] ?? '');

// ---------- Login / logout ----------
if ($action === 'login') {
    Web::checkCsrf();
    if (password_verify((string) ($_POST['password'] ?? ''), (string) Settings::get('admin_hash'))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_hash'] = substr((string) Settings::get('admin_hash'), -16);
        header('Location: admin.php');
        exit;
    }
    sleep(2);
    $flash[] = ['err', I18n::t('admin.login_failed')];
}
if ($action === 'logout') {
    Web::checkCsrf();
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!Web::isAdmin()) {
    Page::open(I18n::t('nav.admin'));
    foreach ($flash as [$k, $m]) {
        echo '<p class="notice ' . $k . '">' . Web::e($m) . '</p>';
    }
    echo '<section class="card"><h2>' . $t('admin.login') . '</h2><form method="post">'
        . '<input type="hidden" name="csrf" value="' . Web::e(Web::csrfToken()) . '"><input type="hidden" name="action" value="login">'
        . '<input type="text" name="username" value="admin" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>'
        . '<div class="field"><label for="password">' . $t('setup.password') . '</label>'
        . '<input id="password" name="password" type="password" required autocomplete="current-password" autofocus></div>'
        . '<p><button class="btn" type="submit">' . $t('admin.login_btn') . '</button></p>'
        . '<p class="muted small">' . $t('admin.lost_password') . '</p></form></section>';
    Page::close();
    exit;
}

// ---------- Actions ----------
if ($action !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Web::checkCsrf();
    try {
        switch ($action) {
            case 'settings':
                $name = trim((string) ($_POST['station_name'] ?? ''));
                $lat = filter_var(str_replace(',', '.', (string) ($_POST['station_lat'] ?? '')), FILTER_VALIDATE_FLOAT);
                $lon = filter_var(str_replace(',', '.', (string) ($_POST['station_lon'] ?? '')), FILTER_VALIDATE_FLOAT);
                $tz = (string) ($_POST['timezone'] ?? '');
                if ($name === '' || $lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180
                    || !in_array($tz, Page::timezones(), true)) {
                    throw new InvalidArgumentException(I18n::t('admin.invalid'));
                }
                $tiles = trim((string) ($_POST['map_tiles'] ?? ''));
                if (!preg_match('~^https://[A-Za-z0-9.{}\-]+(:\d+)?/[^\s"\'<>]*\{z\}[^\s"\'<>]*$~', $tiles)) {
                    throw new InvalidArgumentException(I18n::t('admin.invalid_tiles'));
                }
                Settings::set('station_name', mb_substr($name, 0, 48));
                Settings::set('station_lat', round((float) $lat, 6));
                Settings::set('station_lon', round((float) $lon, 6));
                Settings::set('timezone', $tz);
                Settings::set('lang', in_array($_POST['lang'] ?? '', ['fr', 'en'], true) ? $_POST['lang'] : 'auto');
                Settings::set('max_range_nm', max(5, min(3000, (int) ($_POST['max_range_nm'] ?? 1500))));
                Settings::set('passage_gap_min', max(15, min(1440, (int) ($_POST['passage_gap_min'] ?? 120))));
                Settings::set('position_retention_days', max(1, min(365, (int) ($_POST['position_retention_days'] ?? 30))));
                Settings::set('enrich_enabled', isset($_POST['enrich_enabled']));
                Settings::set('map_tiles', $tiles);
                Settings::set('map_attribution', mb_substr(strip_tags((string) ($_POST['map_attribution'] ?? '')), 0, 200));
                $flash[] = ['ok', I18n::t('admin.saved')];
                break;

            case 'rules':
                $r = Rules::defaults();
                foreach (['emergency', 'military', 'authority', 'yacht', 'hazmat', 'large', 'rare_flag'] as $k) {
                    $r[$k] = isset($_POST['rule_' . $k]);
                }
                $r['yacht_min_len'] = max(5, min(200, (int) ($_POST['yacht_min_len'] ?? 24)));
                $r['large_min_len'] = max(20, min(500, (int) ($_POST['large_min_len'] ?? 200)));
                $r['rare_flag_max'] = max(1, min(50, (int) ($_POST['rare_flag_max'] ?? 3)));
                $r['rare_flag_min_fleet'] = max(0, min(100000, (int) ($_POST['rare_flag_min_fleet'] ?? 100)));
                $r['military_prefixes'] = split_list((string) ($_POST['military_prefixes'] ?? ''), '/^\d{3,9}$/');
                $r['watchlist'] = array_map(static fn ($s) => mb_substr($s, 0, 32), split_list((string) ($_POST['watchlist'] ?? ''), null));
                Settings::set('rules', $r);
                (new Worker())->updateTags(0);
                $flash[] = ['ok', I18n::t('admin.saved')];
                break;

            case 'zone_add':
                $zname = trim((string) ($_POST['zone_name'] ?? ''));
                $zlat = filter_var(str_replace(',', '.', (string) ($_POST['zone_lat'] ?? '')), FILTER_VALIDATE_FLOAT);
                $zlon = filter_var(str_replace(',', '.', (string) ($_POST['zone_lon'] ?? '')), FILTER_VALIDATE_FLOAT);
                $zr = filter_var(str_replace(',', '.', (string) ($_POST['zone_radius'] ?? '')), FILTER_VALIDATE_FLOAT);
                if ($zname === '' || mb_strlen($zname) > 48 || $zlat === false || $zlon === false || $zr === false || $zr <= 0 || $zr > 200
                    || in_array(strtoupper($zname), ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW', 'STATION'], true)) {
                    throw new InvalidArgumentException(I18n::t('admin.invalid_zone'));
                }
                Db::run('INSERT INTO zone (name, lat, lon, radius_nm) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE lat = VALUES(lat), lon = VALUES(lon), radius_nm = VALUES(radius_nm)',
                    [$zname, round((float) $zlat, 6), round((float) $zlon, 6), round((float) $zr, 2)]);
                Worker::reassignZones();
                $flash[] = ['ok', I18n::t('admin.zone_saved')];
                break;

            case 'zone_del':
                Db::run('DELETE FROM zone WHERE id = ?', [(int) ($_POST['zone_id'] ?? 0)]);
                Worker::reassignZones();
                $flash[] = ['ok', I18n::t('admin.zone_deleted')];
                break;

            case 'token':
                $newToken = Page::newToken();
                $flash[] = ['ok', I18n::t('admin.token_new')];
                break;

            case 'password':
                if (!password_verify((string) ($_POST['current'] ?? ''), (string) Settings::get('admin_hash'))) {
                    throw new InvalidArgumentException(I18n::t('admin.login_failed'));
                }
                $p = (string) ($_POST['new'] ?? '');
                if (mb_strlen($p) < 10 || $p !== (string) ($_POST['new2'] ?? '')) {
                    throw new InvalidArgumentException(I18n::t('setup.err.password'));
                }
                Settings::set('admin_hash', password_hash($p, PASSWORD_DEFAULT));
                session_regenerate_id(true);
                $_SESSION['admin_hash'] = substr((string) Settings::get('admin_hash'), -16);
                $flash[] = ['ok', I18n::t('admin.password_changed')];
                break;

            case 'delete_mmsi':
                $mmsi = (int) ($_POST['mmsi'] ?? 0);
                if ($mmsi < 1 || $mmsi > 999999999) {
                    throw new InvalidArgumentException(I18n::t('admin.invalid'));
                }
                $pdo = Db::pdo();
                $pdo->beginTransaction();
                foreach (['vessel', 'vessel_daily', 'vessel_hourly', 'position', 'passage'] as $table) {
                    Db::run("DELETE FROM {$table} WHERE mmsi = ?", [$mmsi]);
                }
                Db::run('UPDATE range_polar SET mmsi = 0 WHERE mmsi = ?', [$mmsi]);
                $pdo->commit();
                $flash[] = ['ok', I18n::t('admin.mmsi_deleted', ['m' => $mmsi])];
                break;

            case 'dest_aliases':
                $rows = Destinations::parseForm((array) ($_POST['dest_from'] ?? []), (array) ($_POST['dest_to'] ?? []));
                Settings::set('dest_aliases', $rows);
                $flash[] = ['ok', I18n::t('admin.dest_saved', ['n' => count($rows)])];
                break;

            case 'photo_upload':
                $mmsi = (int) ($_POST['photo_mmsi'] ?? 0);
                if ($mmsi < 1 || $mmsi > 999999999) {
                    throw new InvalidArgumentException(I18n::t('admin.invalid'));
                }
                $f = $_FILES['photo'] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                    throw new InvalidArgumentException(I18n::t('admin.photo_err_upload'));
                }
                if ((int) $f['size'] > 3 * 1024 * 1024) {
                    throw new InvalidArgumentException(I18n::t('admin.photo_err_size'));
                }
                $info = @getimagesize((string) $f['tmp_name']);
                $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) {
                    throw new InvalidArgumentException(I18n::t('admin.photo_err_type'));
                }
                $credit = trim(strip_tags((string) ($_POST['photo_credit'] ?? '')));
                Db::run('REPLACE INTO vessel_photo (mmsi, mime, width, height, data, credit, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                    $mmsi, $mime, min(65535, (int) $info[0]), min(65535, (int) $info[1]), (string) file_get_contents((string) $f['tmp_name']),
                    $credit !== '' ? mb_substr($credit, 0, 255) : null, time(),
                ]);
                $flash[] = ['ok', I18n::t('admin.photo_saved', ['m' => $mmsi])];
                break;

            case 'photo_delete':
                Db::run('DELETE FROM vessel_photo WHERE mmsi = ?', [(int) ($_POST['photo_mmsi'] ?? 0)]);
                $flash[] = ['ok', I18n::t('admin.photo_deleted')];
                break;

            case 'alerts':
                try {
                    $cfg = Alert::fromForm($_POST, Alert::config(), I18n::lang());
                } catch (InvalidArgumentException $e) {
                    // Show the form again with what was typed (secrets excepted), not the saved values.
                    $saved = Alert::config();
                    $alertDraft = Alert::fromForm($_POST, $saved, I18n::lang(), false);
                    $alertDraft['ntfy']['token'] = $saved['ntfy']['token'];
                    $alertDraft['telegram']['token'] = $saved['telegram']['token'];
                    $alertDraft['email']['pass'] = $saved['email']['pass'];
                    throw new InvalidArgumentException(I18n::t('admin.alert_invalid', ['f' => I18n::t('admin.alert_f_' . $e->getMessage())]));
                }
                Settings::set('alerts', $cfg);
                if (isset($_POST['test'])) {
                    $alertTest = Alert::configured($cfg) === [] ? [] : Alert::test();
                } else {
                    $flash[] = ['ok', I18n::t('admin.alert_saved')];
                }
                break;

            case 'reset_data':
                if (($_POST['confirm'] ?? '') !== 'RESET') {
                    throw new InvalidArgumentException(I18n::t('admin.reset_confirm_needed'));
                }
                foreach (['vessel', 'stats_hourly', 'stats_daily', 'vessel_hourly', 'vessel_daily', 'msgtype_daily',
                    'position', 'passage', 'range_polar', 'ingest_log'] as $table) {
                    Db::pdo()->exec("TRUNCATE TABLE {$table}");
                }
                $flash[] = ['ok', I18n::t('admin.reset_done')];
                break;
        }
    } catch (InvalidArgumentException $e) {
        $flash[] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[aisseastats] admin ' . $action . ': ' . $e->getMessage());
        $flash[] = ['err', I18n::t('admin.error')];
    }
    Settings::flush();
}

/** @return array<int, string> */
function split_list(string $s, ?string $pattern): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $s) ?: [] as $item) {
        $item = trim($item);
        if ($item !== '' && ($pattern === null || preg_match($pattern, $item))) {
            $out[] = $item;
        }
    }
    return array_values(array_unique($out));
}

// ---------- Health ----------
$now = time();
$lastBatch = Db::one('SELECT ts, station, error FROM ingest_log ORDER BY id DESC LIMIT 1');
$hour = Db::one('SELECT COUNT(*) batches, COALESCE(SUM(msgs), 0) msgs, COALESCE(SUM(error IS NOT NULL), 0) errors, COALESCE(AVG(ms), 0) ms
                 FROM ingest_log WHERE ts >= ?', [$now - 3600]);
$lastError = Db::one('SELECT ts, error FROM ingest_log WHERE error IS NOT NULL ORDER BY id DESC LIMIT 1');
$heartbeat = (int) Settings::get('worker_heartbeat');
$dbSize = (int) Db::value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
// MariaDB's redo log has a fixed size (96 MB by default) and explains most of the Docker volume's size.
$redoLog = (int) (Db::value('SELECT @@innodb_log_file_size') ?? 0);
$mb = static fn (int $bytes): string => I18n::t('unit.mb', ['n' => number_format($bytes / 1048576, $bytes < 10485760 ? 1 : 0, ',', "\u{202F}")]);
$counts = Db::one("SELECT (SELECT COUNT(*) FROM vessel) vessels, (SELECT COUNT(*) FROM position) positions, (SELECT COUNT(*) FROM passage) passages");
$zones = Db::all('SELECT * FROM zone ORDER BY name');
$destDict = Destinations::dictionary();
$destMap = Destinations::map($destDict);
[$dkeySql, $dkeyParams] = Destinations::sql($destMap);
$destSeen = Db::all("SELECT " . Destinations::RAW_KEY . " AS rk, {$dkeySql} AS ck,
        SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT UPPER(TRIM(destination)) ORDER BY UPPER(TRIM(destination)) SEPARATOR '|'), '|', 6) AS variants,
        COUNT(*) AS vessels
    FROM vessel WHERE last_seen >= ? AND destination IS NOT NULL AND destination <> ''
    GROUP BY rk, ck ORDER BY vessels DESC LIMIT 40", array_merge($dkeyParams, [$now - 90 * 86400]));
$photos = Db::all('SELECT p.mmsi, p.credit, p.uploaded_at, p.width, p.height, LENGTH(p.data) AS bytes, v.name
                   FROM vessel_photo p LEFT JOIN vessel v ON v.mmsi = p.mmsi ORDER BY p.uploaded_at DESC LIMIT 200');
$photoMmsi = preg_match('/^\d{1,9}$/', (string) ($_GET['photo_mmsi'] ?? '')) ? (string) $_GET['photo_mmsi'] : '';
$s = Settings::all();
$ac = $alertDraft ?? Alert::config();
$r = (array) $s['rules'];
$ago = static function (?int $ts) use ($now): string {
    if (!$ts) {
        return '–';
    }
    $d = $now - $ts;
    return $d < 120 ? I18n::t('time.seconds', ['n' => $d]) : ($d < 7200 ? I18n::t('time.minutes', ['n' => intdiv($d, 60)])
        : ($d < 172800 ? I18n::t('time.hours', ['n' => intdiv($d, 3600)]) : I18n::t('time.days', ['n' => intdiv($d, 86400)])));
};
$csrf = Web::e(Web::csrfToken());
$form = static fn (string $action): string => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="' . $action . '">';

Page::open(I18n::t('nav.admin'), true);
// Messages of the alerts form are shown in its own card (the form returns to #alerts).
$alertFlash = $action === 'alerts' ? $flash : [];
foreach ($action === 'alerts' ? [] : $flash as [$k, $m]) {
    echo '<p class="notice ' . $k . '">' . Web::e($m) . '</p>';
}
?>
<section class="card">
  <div class="card-head">
    <h2><?= $t('admin.health') ?></h2>
    <form method="post"><?= $form('logout') ?><button class="btn secondary" type="submit"><?= $t('admin.logout') ?></button></form>
  </div>
  <div class="health">
    <div><?= $t('admin.last_batch') ?><b><?= Web::e($lastBatch ? $ago((int) $lastBatch['ts']) : I18n::t('admin.never')) ?></b></div>
    <div><?= $t('admin.batches_hour') ?><b><?= (int) $hour['batches'] ?></b></div>
    <div><?= $t('admin.msgs_hour') ?><b><?= number_format((int) $hour['msgs'], 0, ',', ' ') ?></b></div>
    <div><?= $t('admin.avg_ms') ?><b><?= (int) $hour['ms'] ?> ms</b></div>
    <div><?= $t('admin.worker') ?><b><?= Web::e($heartbeat ? $ago($heartbeat) : I18n::t('admin.never')) ?></b></div>
    <div><?= $t('admin.db_size') ?><b><?= Web::e($mb($dbSize)) ?></b></div>
    <div><?= $t('admin.vessels') ?><b><?= number_format((int) $counts['vessels'], 0, ',', ' ') ?></b></div>
    <div><?= $t('admin.positions') ?><b><?= number_format((int) $counts['positions'], 0, ',', ' ') ?></b></div>
  </div>
  <p class="muted small"><?= $t('admin.db_size_help', ['log' => $mb($redoLog)]) ?></p>
  <?php if ($heartbeat && $now - $heartbeat > 300): ?>
    <p class="notice err"><?= $t('admin.worker_down') ?></p>
  <?php endif; ?>
  <?php if ($lastError): ?>
    <p class="muted small"><?= $t('admin.last_error', ['a' => $ago((int) $lastError['ts'])]) ?> <code><?= Web::e($lastError['error']) ?></code></p>
  <?php endif; ?>
</section>

<section class="card">
  <h2><?= $t('admin.ingest') ?></h2>
  <?php if ($newToken !== null): ?>
    <p class="notice ok"><?= $t('setup.done.token_once') ?></p>
    <?= Page::aiscatcherHelp($newToken) ?>
  <?php else: ?>
    <p class="muted"><?= $t('admin.ingest_help') ?></p>
    <details><summary><?= $t('http.show_help') ?></summary><?= Page::aiscatcherHelp(I18n::t('admin.token_placeholder')) ?></details>
  <?php endif; ?>
  <form method="post" class="confirm" data-confirm="<?= $t('admin.token_confirm') ?>"><?= $form('token') ?>
    <button class="btn secondary" type="submit"><?= $t('admin.token_btn') ?></button></form>
</section>

<section class="card">
  <h2><?= $t('admin.station') ?></h2>
  <form method="post"><?= $form('settings') ?>
    <div class="field"><label for="station_name"><?= $t('setup.station_name') ?></label>
      <input id="station_name" name="station_name" required maxlength="48" value="<?= Web::e($s['station_name']) ?>"></div>
    <div class="row">
      <div class="field"><label for="station_lat"><?= $t('setup.lat') ?></label>
        <input id="station_lat" name="station_lat" required value="<?= Web::e($s['station_lat']) ?>"></div>
      <div class="field"><label for="station_lon"><?= $t('setup.lon') ?></label>
        <input id="station_lon" name="station_lon" required value="<?= Web::e($s['station_lon']) ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label for="timezone"><?= $t('setup.timezone') ?></label>
        <select id="timezone" name="timezone"><?php foreach (Page::timezones() as $tz): ?>
          <option<?= $tz === $s['timezone'] ? ' selected' : '' ?>><?= Web::e($tz) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="lang"><?= $t('setup.lang') ?></label>
        <select id="lang" name="lang">
          <option value="auto"<?= $s['lang'] === 'auto' ? ' selected' : '' ?>><?= $t('setup.lang_auto') ?></option>
          <option value="fr"<?= $s['lang'] === 'fr' ? ' selected' : '' ?>>Français</option>
          <option value="en"<?= $s['lang'] === 'en' ? ' selected' : '' ?>>English</option>
        </select></div>
    </div>
    <div class="row">
      <div class="field"><label for="max_range_nm"><?= $t('admin.max_range') ?></label>
        <input id="max_range_nm" name="max_range_nm" type="number" min="5" max="3000" value="<?= (int) $s['max_range_nm'] ?>">
        <span class="help"><?= $t('admin.max_range_help') ?></span></div>
      <div class="field"><label for="passage_gap_min"><?= $t('admin.gap') ?></label>
        <input id="passage_gap_min" name="passage_gap_min" type="number" min="15" max="1440" value="<?= (int) $s['passage_gap_min'] ?>">
        <span class="help"><?= $t('admin.gap_help') ?></span></div>
      <div class="field"><label for="position_retention_days"><?= $t('admin.retention') ?></label>
        <input id="position_retention_days" name="position_retention_days" type="number" min="1" max="365" value="<?= (int) $s['position_retention_days'] ?>">
        <span class="help"><?= $t('admin.retention_help') ?></span></div>
    </div>
    <label class="check"><input type="checkbox" name="enrich_enabled"<?= $s['enrich_enabled'] ? ' checked' : '' ?>> <?= $t('admin.enrich') ?></label>
    <div class="field"><label for="map_tiles"><?= $t('admin.tiles') ?></label>
      <input id="map_tiles" name="map_tiles" value="<?= Web::e($s['map_tiles']) ?>"></div>
    <div class="field"><label for="map_attribution"><?= $t('admin.attribution') ?></label>
      <input id="map_attribution" name="map_attribution" value="<?= Web::e($s['map_attribution']) ?>"></div>
    <p><button class="btn" type="submit"><?= $t('admin.save') ?></button></p>
  </form>
</section>

<section class="card" id="alerts">
  <h2><?= $t('admin.alerts') ?></h2>
  <p class="muted small"><?= $t('admin.alerts_help') ?></p>
  <?php foreach ($alertFlash as [$k, $m]): ?><p class="notice <?= $k ?>"><?= Web::e($m) ?></p><?php endforeach; ?>
  <?php if ($alertTest !== null): ?>
    <?php if ($alertTest === []): ?><p class="notice err"><?= $t('admin.alert_none') ?></p><?php endif; ?>
    <?php foreach ($alertTest as $ch => $err): ?>
      <p class="notice <?= $err === null ? 'ok' : 'err' ?>"><b><?= $t('admin.alert_ch_' . $ch) ?></b> : <?= $err === null ? $t('admin.alert_test_ok') : Web::e($err) ?></p>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php
    $as = Alert::state();
    $asLast = Alert::lastMessage();
  ?>
  <p class="small">
    <?php if (!$ac['enabled']): ?><?= $t('admin.alert_state_off') ?>
    <?php elseif ($as['down']): ?><span class="dot critical"></span><?= $t('admin.alert_state_down', ['a' => $ago((int) $as['alerted'])]) ?>
    <?php else: ?><span class="dot good"></span><?= $t('admin.alert_state_ok', ['c' => implode(', ', array_map(static fn ($c) => I18n::t('admin.alert_ch_' . $c), Alert::configured($ac))) ?: '–']) ?>
    <?php endif; ?>
    <?php if ($as['last_ts']): ?> · <?= $t('admin.alert_last_' . ($as['last_event'] === 'up' ? 'up' : 'down'), ['a' => $ago((int) $as['last_ts'])]) ?>
      <?php foreach ((array) $as['results'] as $ch => $err): if ($err !== null): ?> · <span class="err-text"><?= $t('admin.alert_ch_' . $ch) ?> : <?= Web::e($err) ?></span><?php endif; endforeach; ?>
    <?php endif; ?>
  </p>
  <form method="post" action="admin.php#alerts" autocomplete="off"><?= $form('alerts') ?>
    <label class="check"><input type="checkbox" name="alert_enabled"<?= $ac['enabled'] ? ' checked' : '' ?>> <?= $t('admin.alert_enabled') ?></label>
    <div class="row">
      <div class="field"><label for="alert_after_min"><?= $t('admin.alert_after') ?></label>
        <input id="alert_after_min" name="alert_after_min" type="number" min="5" max="1440" value="<?= (int) $ac['after_min'] ?>"></div>
    </div>

    <fieldset class="alert-ch"><legend>ntfy</legend>
      <p class="help"><?= $t('admin.alert_ntfy_help') ?></p>
      <div class="row">
        <div class="field"><label for="ntfy_url"><?= $t('admin.alert_server') ?></label>
          <input id="ntfy_url" name="ntfy_url" value="<?= Web::e($ac['ntfy']['url']) ?>" placeholder="https://ntfy.sh"></div>
        <div class="field"><label for="ntfy_topic"><?= $t('admin.alert_topic') ?></label>
          <input id="ntfy_topic" name="ntfy_topic" maxlength="64" value="<?= Web::e($ac['ntfy']['topic']) ?>" placeholder="aisseastats-<?= bin2hex(random_bytes(5)) ?>"></div>
        <div class="field"><label for="ntfy_token"><?= $t('admin.alert_ntfy_token') ?></label>
          <input id="ntfy_token" name="ntfy_token" type="password" autocomplete="new-password" placeholder="<?= $ac['ntfy']['token'] !== '' ? $t('admin.alert_secret_set') : $t('admin.alert_optional') ?>">
          <?php if ($ac['ntfy']['token'] !== ''): ?><label class="check small"><input type="checkbox" name="ntfy_token_clear"> <?= $t('admin.alert_clear') ?></label><?php endif; ?></div>
      </div>
    </fieldset>

    <fieldset class="alert-ch"><legend>Telegram</legend>
      <p class="help"><?= $t('admin.alert_telegram_help') ?></p>
      <div class="row">
        <div class="field"><label for="telegram_token"><?= $t('admin.alert_bot_token') ?></label>
          <input id="telegram_token" name="telegram_token" type="password" autocomplete="new-password" placeholder="<?= $ac['telegram']['token'] !== '' ? $t('admin.alert_secret_set') : '123456789:AA…' ?>">
          <?php if ($ac['telegram']['token'] !== ''): ?><label class="check small"><input type="checkbox" name="telegram_token_clear"> <?= $t('admin.alert_clear') ?></label><?php endif; ?></div>
        <div class="field"><label for="telegram_chat"><?= $t('admin.alert_chat_id') ?></label>
          <input id="telegram_chat" name="telegram_chat" maxlength="64" value="<?= Web::e($ac['telegram']['chat']) ?>" placeholder="123456789"></div>
      </div>
    </fieldset>

    <fieldset class="alert-ch"><legend><?= $t('admin.alert_ch_webhook') ?></legend>
      <p class="help"><?= $t('admin.alert_webhook_help') ?></p>
      <div class="field"><label for="webhook_url"><?= $t('admin.alert_url') ?></label>
        <input id="webhook_url" name="webhook_url" maxlength="500" value="<?= Web::e($ac['webhook']['url']) ?>" placeholder="https://…"></div>
    </fieldset>

    <fieldset class="alert-ch"><legend><?= $t('admin.alert_ch_email') ?></legend>
      <p class="help"><?= $t('admin.alert_email_help') ?></p>
      <div class="row">
        <div class="field"><label for="smtp_host"><?= $t('admin.alert_smtp_host') ?></label>
          <input id="smtp_host" name="smtp_host" maxlength="120" value="<?= Web::e($ac['email']['host']) ?>" placeholder="smtp.example.com"></div>
        <div class="field"><label for="smtp_port"><?= $t('admin.alert_smtp_port') ?></label>
          <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= (int) $ac['email']['port'] ?>"></div>
        <div class="field"><label for="smtp_security"><?= $t('admin.alert_smtp_security') ?></label>
          <select id="smtp_security" name="smtp_security">
            <?php foreach (['starttls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => I18n::t('admin.alert_smtp_none')] as $k => $lbl): ?>
              <option value="<?= $k ?>"<?= $ac['email']['security'] === $k ? ' selected' : '' ?>><?= Web::e($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="row">
        <div class="field"><label for="smtp_user"><?= $t('admin.alert_smtp_user') ?></label>
          <input id="smtp_user" name="smtp_user" maxlength="120" autocomplete="off" value="<?= Web::e($ac['email']['user']) ?>" placeholder="<?= $t('admin.alert_optional') ?>"></div>
        <div class="field"><label for="smtp_pass"><?= $t('admin.alert_smtp_pass') ?></label>
          <input id="smtp_pass" name="smtp_pass" type="password" autocomplete="new-password" placeholder="<?= $ac['email']['pass'] !== '' ? $t('admin.alert_secret_set') : $t('admin.alert_optional') ?>">
          <?php if ($ac['email']['pass'] !== ''): ?><label class="check small"><input type="checkbox" name="smtp_pass_clear"> <?= $t('admin.alert_clear') ?></label><?php endif; ?></div>
      </div>
      <div class="row">
        <div class="field"><label for="smtp_from"><?= $t('admin.alert_smtp_from') ?></label>
          <input id="smtp_from" name="smtp_from" type="email" maxlength="120" value="<?= Web::e($ac['email']['from']) ?>" placeholder="station@example.com"></div>
        <div class="field"><label for="smtp_to"><?= $t('admin.alert_smtp_to') ?></label>
          <input id="smtp_to" name="smtp_to" maxlength="500" value="<?= Web::e($ac['email']['to']) ?>" placeholder="me@example.com, other@example.com"></div>
      </div>
    </fieldset>

    <fieldset class="alert-ch"><legend><?= $t('admin.alert_heartbeat') ?></legend>
      <p class="help"><?= $t('admin.alert_heartbeat_help') ?></p>
      <div class="field"><label for="heartbeat_url"><?= $t('admin.alert_url') ?></label>
        <input id="heartbeat_url" name="heartbeat_url" maxlength="500" value="<?= Web::e($ac['heartbeat_url']) ?>" placeholder="https://hc-ping.com/…"></div>
    </fieldset>

    <p><button class="btn" type="submit"><?= $t('admin.save') ?></button>
      <button class="btn secondary" type="submit" name="test" value="1"><?= $t('admin.alert_test') ?></button></p>
  </form>
</section>

<section class="card">
  <h2><?= $t('admin.rules') ?></h2>
  <p class="muted small"><?= $t('admin.rules_help') ?></p>
  <form method="post"><?= $form('rules') ?>
    <label class="check"><input type="checkbox" name="rule_emergency"<?= $r['emergency'] ? ' checked' : '' ?>> <?= $t('tag.emergency') ?> — <?= $t('admin.rule_emergency') ?></label>
    <label class="check"><input type="checkbox" name="rule_military"<?= $r['military'] ? ' checked' : '' ?>> <?= $t('tag.military') ?> — <?= $t('admin.rule_military') ?></label>
    <label class="check"><input type="checkbox" name="rule_authority"<?= $r['authority'] ? ' checked' : '' ?>> <?= $t('tag.authority') ?> — <?= $t('admin.rule_authority') ?></label>
    <label class="check"><input type="checkbox" name="rule_yacht"<?= $r['yacht'] ? ' checked' : '' ?>> <?= $t('tag.yacht') ?> — <?= $t('admin.rule_yacht') ?></label>
    <label class="check"><input type="checkbox" name="rule_hazmat"<?= $r['hazmat'] ? ' checked' : '' ?>> <?= $t('tag.hazmat') ?> — <?= $t('admin.rule_hazmat') ?></label>
    <label class="check"><input type="checkbox" name="rule_large"<?= $r['large'] ? ' checked' : '' ?>> <?= $t('tag.large') ?> — <?= $t('admin.rule_large') ?></label>
    <label class="check"><input type="checkbox" name="rule_rare_flag"<?= $r['rare_flag'] ? ' checked' : '' ?>> <?= $t('tag.rare_flag') ?> — <?= $t('admin.rule_rare') ?></label>
    <div class="row">
      <div class="field"><label for="yacht_min_len"><?= $t('admin.yacht_len') ?></label>
        <input id="yacht_min_len" name="yacht_min_len" type="number" min="5" max="200" value="<?= (int) $r['yacht_min_len'] ?>"></div>
      <div class="field"><label for="large_min_len"><?= $t('admin.large_len') ?></label>
        <input id="large_min_len" name="large_min_len" type="number" min="20" max="500" value="<?= (int) $r['large_min_len'] ?>"></div>
      <div class="field"><label for="rare_flag_max"><?= $t('admin.rare_max') ?></label>
        <input id="rare_flag_max" name="rare_flag_max" type="number" min="1" max="50" value="<?= (int) $r['rare_flag_max'] ?>"></div>
      <div class="field"><label for="rare_flag_min_fleet"><?= $t('admin.rare_min_fleet') ?></label>
        <input id="rare_flag_min_fleet" name="rare_flag_min_fleet" type="number" min="0" max="100000" value="<?= (int) ($r['rare_flag_min_fleet'] ?? 100) ?>"></div>
    </div>
    <div class="field"><label for="military_prefixes"><?= $t('admin.military_prefixes') ?></label>
      <textarea id="military_prefixes" name="military_prefixes"><?= Web::e(implode("\n", (array) $r['military_prefixes'])) ?></textarea>
      <span class="help"><?= $t('admin.military_prefixes_help') ?></span></div>
    <div class="field"><label for="watchlist"><?= $t('admin.watchlist') ?></label>
      <textarea id="watchlist" name="watchlist"><?= Web::e(implode("\n", (array) $r['watchlist'])) ?></textarea>
      <span class="help"><?= $t('admin.watchlist_help') ?></span></div>
    <p><button class="btn" type="submit"><?= $t('admin.save') ?></button></p>
  </form>
</section>

<section class="card">
  <h2><?= $t('admin.zones') ?></h2>
  <p class="muted small"><?= $t('admin.zones_help') ?></p>
  <div id="zone-map" class="zone-map"
       data-lat="<?= Web::e($s['station_lat']) ?>" data-lon="<?= Web::e($s['station_lon']) ?>"
       data-tiles="<?= Web::e($s['map_tiles']) ?>" data-attribution="<?= Web::e($s['map_attribution']) ?>"
       data-zones="<?= Web::e(json_encode($zones)) ?>"></div>
  <?php if ($zones): ?>
    <table class="table"><tbody>
      <?php foreach ($zones as $z): ?>
        <tr><td><b><?= Web::e($z['name']) ?></b></td>
          <td class="r coords"><?= Web::e($z['lat']) ?>, <?= Web::e($z['lon']) ?> <span class="muted">· <?= Web::e($z['radius_nm']) ?>&nbsp;NM</span></td>
          <td class="r"><form method="post"><?= $form('zone_del') ?><input type="hidden" name="zone_id" value="<?= (int) $z['id'] ?>">
            <button class="btn danger" type="submit"><?= $t('admin.delete') ?></button></form></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  <form method="post"><?= $form('zone_add') ?>
    <div class="row">
      <div class="field"><label for="zone_name"><?= $t('admin.zone_name') ?></label><input id="zone_name" name="zone_name" maxlength="48" required></div>
      <div class="field"><label for="zone_lat"><?= $t('setup.lat') ?></label><input id="zone_lat" name="zone_lat" required></div>
      <div class="field"><label for="zone_lon"><?= $t('setup.lon') ?></label><input id="zone_lon" name="zone_lon" required></div>
      <div class="field"><label for="zone_radius"><?= $t('admin.zone_radius') ?></label><input id="zone_radius" name="zone_radius" value="2" required></div>
    </div>
    <p><button class="btn" type="submit"><?= $t('admin.zone_add') ?></button></p>
  </form>
</section>

<section class="card" id="destinations">
  <h2><?= $t('admin.dest') ?></h2>
  <p class="muted small"><?= $t('admin.dest_help') ?></p>
  <form method="post" id="dest-form"><?= $form('dest_aliases') ?>
    <div class="dest-rows" id="dest-rows">
      <div class="dest-head row"><span class="help"><?= $t('admin.dest_from') ?></span><span class="help"><?= $t('admin.dest_to') ?></span></div>
      <?php foreach (array_merge($destDict, [['to' => '', 'from' => []]]) as $d): ?>
        <div class="dest-row">
          <input name="dest_from[]" value="<?= Web::e(implode(', ', (array) $d['from'])) ?>" placeholder="<?= $t('admin.dest_from_ph') ?>" aria-label="<?= $t('admin.dest_from') ?>">
          <input name="dest_to[]" value="<?= Web::e($d['to']) ?>" maxlength="32" placeholder="<?= $t('admin.dest_to_ph') ?>" aria-label="<?= $t('admin.dest_to') ?>">
        </div>
      <?php endforeach; ?>
    </div>
    <p><button class="btn secondary" type="button" id="dest-add"><?= $t('admin.dest_add') ?></button>
      <button class="btn" type="submit"><?= $t('admin.save') ?></button></p>
  </form>
  <?php if ($destSeen): ?>
    <h3 class="sub"><?= $t('admin.dest_seen') ?></h3>
    <table class="table dest-seen"><tbody>
      <?php foreach ($destSeen as $d):
          $variants = array_values(array_filter(explode('|', (string) $d['variants']), static fn ($x) => $x !== ''));
          $merged = $d['ck'] !== $d['rk']; ?>
        <tr><td><?= Web::e(implode(' · ', $variants)) ?>
            <?php if ($d['ck'] === Destinations::UNKNOWN): ?><span class="type"><?= $t('admin.dest_is_unknown') ?></span>
            <?php elseif ($merged): ?><span class="type">→ <?= Web::e(Destinations::label((string) $d['ck'], '', $destMap)) ?></span><?php endif; ?></td>
          <td class="r"><?= $t('routes.vessels_n', ['n' => (int) $d['vessels']]) ?></td>
          <td class="r"><?php if (!$merged && $d['ck'] !== Destinations::UNKNOWN && !isset($destMap[$d['rk']])): ?><button type="button" class="btn secondary dest-use" data-variants="<?= Web::e(implode(', ', $variants)) ?>"><?= $t('admin.dest_use') ?></button><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</section>

<section class="card" id="photos">
  <h2><?= $t('admin.photos') ?></h2>
  <p class="muted small"><?= $t('admin.photos_help') ?></p>
  <form method="post" enctype="multipart/form-data"><?= $form('photo_upload') ?>
    <div class="row">
      <div class="field"><label for="photo_mmsi"><?= $t('admin.photo_mmsi') ?></label>
        <input id="photo_mmsi" name="photo_mmsi" inputmode="numeric" pattern="\d{1,9}" required value="<?= Web::e($photoMmsi) ?>"></div>
      <div class="field"><label for="photo"><?= $t('admin.photo_file') ?></label>
        <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required></div>
    </div>
    <div class="field"><label for="photo_credit"><?= $t('admin.photo_credit') ?></label>
      <input id="photo_credit" name="photo_credit" maxlength="255" placeholder="<?= $t('admin.photo_credit_ph') ?>"></div>
    <p><button class="btn" type="submit"><?= $t('admin.photo_add') ?></button></p>
  </form>
  <?php if ($photos): ?>
    <table class="table photo-table"><tbody>
      <?php foreach ($photos as $ph): ?>
        <tr><td><img src="photo.php?mmsi=<?= (int) $ph['mmsi'] ?>&amp;v=<?= (int) $ph['uploaded_at'] ?>" alt="" width="72" height="48" loading="lazy"></td>
          <td><b><?= Web::e($ph['name'] ?: 'MMSI ' . $ph['mmsi']) ?></b><span class="type"><?= (int) $ph['mmsi'] ?> · <?= (int) $ph['width'] ?>×<?= (int) $ph['height'] ?> · <?= number_format((int) $ph['bytes'] / 1024, 0, ',', ' ') ?> Ko<?= $ph['credit'] ? ' · ' . Web::e($ph['credit']) : '' ?></span></td>
          <td class="r"><form method="post" class="confirm" data-confirm="<?= $t('admin.photo_delete_confirm') ?>"><?= $form('photo_delete') ?><input type="hidden" name="photo_mmsi" value="<?= (int) $ph['mmsi'] ?>">
            <button class="btn danger" type="submit"><?= $t('admin.delete') ?></button></form></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</section>

<section class="card">
  <h2><?= $t('admin.password') ?></h2>
  <form method="post"><?= $form('password') ?>
    <input type="text" name="username" value="admin" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
    <div class="row">
      <div class="field"><label for="current"><?= $t('admin.current_password') ?></label><input id="current" name="current" type="password" required autocomplete="current-password"></div>
      <div class="field"><label for="new"><?= $t('admin.new_password') ?></label><input id="new" name="new" type="password" minlength="10" required autocomplete="new-password"></div>
      <div class="field"><label for="new2"><?= $t('setup.password2') ?></label><input id="new2" name="new2" type="password" minlength="10" required autocomplete="new-password"></div>
    </div>
    <p><button class="btn" type="submit"><?= $t('admin.save') ?></button></p>
  </form>
</section>

<section class="card">
  <h2><?= $t('admin.data') ?></h2>
  <form method="post" class="confirm" data-confirm="<?= $t('admin.delete_mmsi_confirm') ?>"><?= $form('delete_mmsi') ?>
    <div class="row">
      <div class="field"><label for="mmsi"><?= $t('admin.delete_mmsi') ?></label><input id="mmsi" name="mmsi" inputmode="numeric" pattern="\d{1,9}" required>
        <span class="help"><?= $t('admin.delete_mmsi_help') ?></span></div>
    </div>
    <p><button class="btn danger" type="submit"><?= $t('admin.delete') ?></button></p>
  </form>
  <form method="post" class="confirm" data-confirm="<?= $t('admin.reset_confirm') ?>"><?= $form('reset_data') ?>
    <div class="field"><label for="confirm"><?= $t('admin.reset') ?></label><input id="confirm" name="confirm" placeholder="RESET" required>
      <span class="help"><?= $t('admin.reset_help') ?></span></div>
    <p><button class="btn danger" type="submit"><?= $t('admin.reset_btn') ?></button></p>
  </form>
</section>
<?php
Page::close(['assets/vendor/leaflet/leaflet.js', 'assets/admin.js']);
