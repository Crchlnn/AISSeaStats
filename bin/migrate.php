<?php
declare(strict_types=1);

// Apply database migrations. Run automatically by the container entrypoint.
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Migrator;

Db::waitReady((int) (getenv('DB_WAIT') ?: 180));
$applied = Migrator::run();
echo $applied === [] ? "[aisseastats] database schema up to date\n"
    : '[aisseastats] applied migrations: ' . implode(', ', $applied) . "\n";
