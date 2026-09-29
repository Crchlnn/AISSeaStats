<?php
declare(strict_types=1);

// Delete all collected data (vessels, statistics, positions, passages)
// while keeping settings, zones and credentials. Useful after a demo run.
// docker compose exec app php bin/reset-data.php --yes
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "This deletes every collected statistic. Re-run with --yes to confirm.\n");
    exit(1);
}
Db::waitReady(30);
foreach (['vessel', 'stats_hourly', 'stats_daily', 'vessel_hourly', 'vessel_daily', 'msgtype_daily',
    'position', 'passage', 'range_polar', 'ingest_log'] as $table) {
    Db::pdo()->exec("TRUNCATE TABLE {$table}");
}
echo "All collected data deleted. Settings, zones and credentials kept.\n";
