<?php
declare(strict_types=1);

// Background worker: runs the minute jobs forever (Docker service "worker").
// Use --once to run a single pass (tests, cron).
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Migrator;
use AISSeaStats\Worker;

$once = in_array('--once', $argv, true);
Db::waitReady(300);
while (!Migrator::isReady()) {
    fwrite(STDERR, "[aisseastats] worker: waiting for database schema\n");
    sleep(5);
}

$worker = new Worker();
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
    pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
}

echo "[aisseastats] worker started\n";
do {
    $t = microtime(true);
    try {
        $worker->tick();
    } catch (Throwable $e) {
        fwrite(STDERR, '[aisseastats] worker error: ' . $e->getMessage() . "\n");
        Db::reset();
    }
    if ($once) {
        break;
    }
    $sleep = max(5, 60 - (int) (microtime(true) - $t));
    for ($i = 0; $i < $sleep && !$stop; $i++) {
        sleep(1);
    }
} while (!$stop);
echo "[aisseastats] worker stopped\n";
