<?php
declare(strict_types=1);

// Reset the admin password if it has been lost.
// docker compose exec app php bin/reset-admin.php
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Settings;

Db::waitReady(30);
$pass = bin2hex(random_bytes(8));
Settings::set('admin_hash', password_hash($pass, PASSWORD_DEFAULT));
echo "New admin password: {$pass}\nChange it from the admin page after logging in.\n";
