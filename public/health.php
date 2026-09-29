<?php
declare(strict_types=1);

// Container health check: web server, PHP and database all answer.
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Web;

try {
    Db::value('SELECT 1');
    Web::json(['ok' => true]);
} catch (Throwable) {
    Web::json(['ok' => false], 503);
}
