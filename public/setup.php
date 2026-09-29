<?php
declare(strict_types=1);

/**
 * First-run wizard: station, language, admin password, ingestion token.
 * Only reachable until the setup has been completed once.
 */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\I18n;
use AISSeaStats\Migrator;
use AISSeaStats\Page;
use AISSeaStats\Settings;
use AISSeaStats\Web;

try {
    if (!Migrator::isReady()) {
        http_response_code(503);
        exit('The database is still being initialised. Reload this page in a few seconds.');
    }
    if (Settings::get('setup_done')) {
        header('Location: index.php');
        exit;
    }
} catch (Throwable $e) {
    http_response_code(503);
    exit('Database unavailable. Check that the "db" container is running.');
}

$t = static fn (string $k, array $v = []): string => Web::e(I18n::t($k, $v));
Web::session();
Web::csrfToken();
$errors = [];
$values = [
    'station_name' => (string) ($_POST['station_name'] ?? ''),
    'station_lat' => (string) ($_POST['station_lat'] ?? ''),
    'station_lon' => (string) ($_POST['station_lon'] ?? ''),
    'timezone' => (string) ($_POST['timezone'] ?? Settings::get('timezone')),
    'lang' => (string) ($_POST['lang'] ?? 'auto'),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Web::checkCsrf();
    $name = trim($values['station_name']);
    if ($name === '' || mb_strlen($name) > 48) {
        $errors[] = I18n::t('setup.err.name');
    }
    $lat = filter_var(str_replace(',', '.', $values['station_lat']), FILTER_VALIDATE_FLOAT);
    $lon = filter_var(str_replace(',', '.', $values['station_lon']), FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180) {
        $errors[] = I18n::t('setup.err.position');
    }
    if (!in_array($values['timezone'], Page::timezones(), true)) {
        $errors[] = I18n::t('setup.err.timezone');
    }
    $pass = (string) ($_POST['password'] ?? '');
    if (mb_strlen($pass) < 10) {
        $errors[] = I18n::t('setup.err.password');
    } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
        $errors[] = I18n::t('setup.err.password_match');
    }
    if ($errors === []) {
        Settings::set('station_name', $name);
        Settings::set('station_lat', round((float) $lat, 6));
        Settings::set('station_lon', round((float) $lon, 6));
        Settings::set('timezone', $values['timezone']);
        Settings::set('lang', in_array($values['lang'], ['fr', 'en'], true) ? $values['lang'] : 'auto');
        Settings::set('admin_hash', password_hash($pass, PASSWORD_DEFAULT));
        $token = Page::newToken();
        Settings::set('setup_done', true);
        Web::session();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_hash'] = substr((string) Settings::get('admin_hash'), -16);

        Page::open(I18n::t('setup.done.title'));
        echo '<section class="card"><h2>' . $t('setup.done.title') . '</h2>'
            . '<p>' . $t('setup.done.intro') . '</p>'
            . '<h3 class="sub">' . $t('setup.done.token') . '</h3>'
            . '<pre class="cmd">' . Web::e($token) . '</pre>'
            . '<p class="notice">' . $t('setup.done.token_once') . '</p>'
            . '<h3 class="sub">' . $t('setup.done.cli') . '</h3>'
            . '<pre class="cmd">' . Web::e(Page::aiscatcherCommand($token)) . '</pre>'
            . '<h3 class="sub">' . $t('setup.done.managed') . '</h3>'
            . '<p>' . $t('setup.done.managed_help') . '</p>'
            . '<pre class="cmd">URL      : ' . Web::e(Page::ingestUrl()) . "\n"
            . 'userpwd  : aisseastats:' . Web::e($token) . "\n"
            . "interval : 15\ngzip     : on</pre>"
            . '<p class="muted small">' . $t('setup.done.meta') . '</p>'
            . '<p><a class="btn" href="index.php">' . $t('setup.done.go') . '</a> '
            . '<a class="btn secondary" href="admin.php">' . $t('nav.admin') . '</a></p></section>';
        Page::close();
        exit;
    }
}

Page::open(I18n::t('setup.title'));
?>
<section class="card">
  <h2><?= $t('setup.welcome') ?></h2>
  <p class="muted"><?= $t('setup.intro') ?></p>
  <?php foreach ($errors as $err): ?>
    <p class="notice err"><?= Web::e($err) ?></p>
  <?php endforeach; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= Web::e(Web::csrfToken()) ?>">
    <div class="field">
      <label for="station_name"><?= $t('setup.station_name') ?></label>
      <input id="station_name" name="station_name" required maxlength="48" value="<?= Web::e($values['station_name']) ?>" placeholder="<?= $t('setup.ph_name') ?>">
    </div>
    <div class="row">
      <div class="field">
        <label for="station_lat"><?= $t('setup.lat') ?></label>
        <input id="station_lat" name="station_lat" required inputmode="decimal" value="<?= Web::e($values['station_lat']) ?>" placeholder="<?= $t('setup.ph_lat') ?>">
      </div>
      <div class="field">
        <label for="station_lon"><?= $t('setup.lon') ?></label>
        <input id="station_lon" name="station_lon" required inputmode="decimal" value="<?= Web::e($values['station_lon']) ?>" placeholder="<?= $t('setup.ph_lon') ?>">
      </div>
    </div>
    <p class="help muted small"><?= $t('setup.position_help') ?></p>
    <div class="row">
      <div class="field">
        <label for="timezone"><?= $t('setup.timezone') ?></label>
        <select id="timezone" name="timezone">
          <?php foreach (Page::timezones() as $tz): ?>
            <option<?= $tz === $values['timezone'] ? ' selected' : '' ?>><?= Web::e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="lang"><?= $t('setup.lang') ?></label>
        <select id="lang" name="lang">
          <option value="auto"<?= $values['lang'] === 'auto' ? ' selected' : '' ?>><?= $t('setup.lang_auto') ?></option>
          <option value="fr"<?= $values['lang'] === 'fr' ? ' selected' : '' ?>>Français</option>
          <option value="en"<?= $values['lang'] === 'en' ? ' selected' : '' ?>>English</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="password"><?= $t('setup.password') ?></label>
        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="password2"><?= $t('setup.password2') ?></label>
        <input id="password2" name="password2" type="password" required minlength="10" autocomplete="new-password">
      </div>
    </div>
    <p><button class="btn" type="submit"><?= $t('setup.submit') ?></button></p>
  </form>
</section>
<?php
Page::close();
