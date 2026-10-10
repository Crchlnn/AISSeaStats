<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\I18n;
use AISSeaStats\Settings;
use AISSeaStats\Web;

use const AISSeaStats\VERSION;

try {
    Web::requireSetup();
} catch (Throwable $e) {
    http_response_code(503);
    exit('Database unavailable. Check that the "db" container is running.');
}
Web::session();
Web::headers();

$cfg = [
    'lang' => I18n::lang(),
    'i18n' => I18n::dict(),
    'station' => [
        'name' => Settings::get('station_name'),
        'lat' => Settings::get('station_lat'),
        'lon' => Settings::get('station_lon'),
    ],
    'tz' => Settings::timezone()->getName(),
    'tiles' => Settings::get('map_tiles'),
    'attribution' => Settings::get('map_attribution'),
    'version' => VERSION,
    'isAdmin' => Web::isAdmin(),
];
$t = static fn (string $k): string => Web::e(I18n::t($k));
?>
<!doctype html>
<html lang="<?= Web::e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Web::e(Settings::get('station_name')) ?> · AISSeaStats</title>
<link rel="icon" href="<?= Web::asset('assets/icon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= Web::asset('assets/vendor/leaflet/leaflet.css') ?>">
<link rel="stylesheet" href="<?= Web::asset('assets/app.css') ?>">
<script type="application/json" id="cfg"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= Web::asset('assets/theme.js') ?>"></script>
</head>
<body>
<header class="topbar">
  <div class="brand">
    <img src="<?= Web::asset('assets/icon.svg') ?>" alt="" width="28" height="28">
    <div>
      <h1><?= Web::e(Settings::get('station_name')) ?></h1>
      <p class="muted" id="status"><?= $t('status.loading') ?></p>
    </div>
  </div>
  <div class="top-actions">
    <div class="search">
      <input type="search" id="search" placeholder="<?= $t('search.placeholder') ?>" autocomplete="off" aria-label="<?= $t('search.placeholder') ?>">
      <ul id="search-results" class="search-results" hidden></ul>
    </div>
    <button class="icon-btn" id="theme-toggle" type="button" aria-label="<?= $t('theme.toggle') ?>" title="<?= $t('theme.toggle') ?>">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z"/></svg>
    </button>
    <a class="icon-btn" href="admin.php" aria-label="<?= $t('nav.admin') ?>" title="<?= $t('nav.admin') ?>">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.4 7.4 0 0 0-1.7-1L14.9 3h-4l-.4 2.9a7.4 7.4 0 0 0-1.7 1l-2.5-1-2 3.5L6.4 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.4 7.4 0 0 0 1.7 1l.4 2.9h4l.4-2.9a7.4 7.4 0 0 0 1.7-1l2.5 1 2-3.5zM12.9 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z"/></svg>
    </a>
  </div>
</header>

<main>
  <section class="kpis" id="kpis" aria-label="<?= $t('kpi.title') ?>"></section>

  <div class="filter-row sticky-filter">
    <span class="muted"><?= $t('filter.period') ?></span>
    <div class="seg" id="range" role="group" aria-label="<?= $t('filter.period') ?>">
      <button type="button" data-range="2"><?= $t('range.48h') ?></button>
      <button type="button" data-range="7"><?= $t('range.7d') ?></button>
      <button type="button" data-range="30" class="on"><?= $t('range.30d') ?></button>
      <button type="button" data-range="90"><?= $t('range.90d') ?></button>
      <button type="button" data-range="365"><?= $t('range.1y') ?></button>
      <button type="button" data-range="all"><?= $t('filter.all') ?></button>
    </div>
    <span class="muted small" id="updated" aria-live="polite"></span>
  </div>

  <section class="card">
    <div class="card-head">
      <h2><?= $t('counts.title') ?></h2>
      <span class="muted small" id="counts-note"></span>
    </div>
    <div class="chart-wrap tall"><canvas id="chart-counts" role="img" aria-label="<?= $t('counts.title') ?>"></canvas></div>
    <p class="small prop-events" id="counts-events" hidden></p>
  </section>

  <section class="card">
    <div class="card-head">
      <h2><?= $t('routes.title') ?></h2>
      <span class="muted small"><?= $t('routes.hint') ?></span>
    </div>
    <div class="routes">
      <div id="routes-map" class="map" role="region" aria-label="<?= $t('routes.map') ?>"></div>
      <div>
        <ol id="routes-list" class="rank"></ol>
        <h3 class="sub"><?= $t('routes.destinations') ?></h3>
        <ol id="dest-list" class="rank compact"></ol>
      </div>
    </div>
  </section>

  <div class="grid-2">
    <section class="card">
      <div class="card-head">
        <h2><?= $t('top.title') ?></h2>
        <div class="seg small" id="top-by">
          <button type="button" data-by="passages" class="on"><?= $t('top.passages') ?></button>
          <button type="button" data-by="days"><?= $t('top.days') ?></button>
          <button type="button" data-by="length"><?= $t('top.length') ?></button>
          <button type="button" data-by="speed"><?= $t('top.speed') ?></button>
          <button type="button" data-by="distance"><?= $t('top.distance') ?></button>
        </div>
      </div>
      <table class="table" id="top-table"><tbody></tbody></table>
    </section>

    <section class="card">
      <div class="card-head">
        <h2><?= $t('remarkable.title') ?></h2>
        <span class="muted small"><?= $t('remarkable.hint') ?></span>
      </div>
      <div id="remarkable-counts" class="chips"></div>
      <ul id="remarkable-list" class="events"></ul>
    </section>
  </div>

  <div class="grid-2">
    <section class="card">
      <div class="card-head"><h2><?= $t('fleet.title') ?></h2></div>
      <h3 class="sub"><?= $t('fleet.types') ?></h3>
      <ul class="bars" id="fleet-types"></ul>
      <h3 class="sub"><?= $t('fleet.flags') ?></h3>
      <ul class="bars flags" id="fleet-flags"></ul>
      <p class="muted small"><?= $t('fleet.hint') ?></p>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= $t('range.title') ?></h2></div>
      <div class="chart-wrap square"><canvas id="chart-polar" role="img" aria-label="<?= $t('range.title') ?>"></canvas></div>
      <p class="muted small" id="range-note"></p>
      <h3 class="sub"><?= $t('range.furthest') ?></h3>
      <ol id="furthest-list" class="rank compact"></ol>
      <p class="muted small"><?= $t('range.tropo_hint') ?> <a href="https://dxinfocentre.com/tropo_eur.html" target="_blank" rel="noopener">dxinfocentre.com</a></p>
    </section>
  </div>

  <div class="grid-2">
    <section class="card">
      <div class="card-head">
        <h2><?= $t('reg.title') ?></h2>
        <span class="muted small"><?= $t('reg.hint') ?></span>
      </div>
      <table class="table" id="regulars-table"><tbody></tbody></table>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= $t('heat.title') ?></h2></div>
      <div id="heatmap" class="heatmap"></div>
      <p class="muted small" id="heatmap-note"></p>
    </section>
  </div>

  <section class="card">
    <div class="card-head">
      <h2><?= $t('up.title') ?></h2>
      <span class="muted small" id="uptime-summary"></span>
    </div>
    <div id="uptime" class="uptime"></div>
    <ol id="uptime-gaps" class="rank compact up-gaps" hidden></ol>
    <p class="muted small"><?= $t('up.hint') ?></p>
  </section>
</main>

<footer class="footer muted small">
  <span>AISSeaStats <?= Web::e(VERSION) ?> · GPLv3</span>
  <span><?= $t('footer.data') ?></span>
  <a href="https://github.com/Crchlnn/AISSeaStats" rel="noopener">GitHub</a>
</footer>

<dialog id="list-dialog" class="vessel-dialog" aria-labelledby="ld-title">
  <div class="vd-inner" id="ld-body"></div>
</dialog>

<dialog id="vessel-dialog" class="vessel-dialog" aria-labelledby="vd-title">
  <div class="vd-inner" id="vd-body"></div>
</dialog>

<script src="<?= Web::asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= Web::asset('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script src="<?= Web::asset('assets/app.js') ?>"></script>
</body>
</html>
