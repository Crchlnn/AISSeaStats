<?php
declare(strict_types=1);

// Show the one-time code asked by the setup wizard (1.1.6).
//   docker compose exec app php bin/setup-code.php           the code, with a sentence
//   docker compose exec app php bin/setup-code.php --quiet   the code only (install.sh)
// Prints nothing with --quiet, and a short notice otherwise, once the setup is done.
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Migrator;
use AISSeaStats\SetupCode;

Db::waitReady(30);
if (!Migrator::isReady()) {
    fwrite(STDERR, "Database not initialised yet.\n");
    exit(1);
}
$quiet = in_array('--quiet', $argv, true);
$code = SetupCode::current();
if ($code === null) {
    if (!$quiet) {
        echo "Setup already completed: no code needed.\n";
    }
    exit(0);
}
echo $quiet ? $code . "\n" : "AISSeaStats setup code: {$code}\n(asked by the setup wizard in the browser; it works once)\n";
