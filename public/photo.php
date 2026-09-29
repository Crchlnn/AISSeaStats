<?php
declare(strict_types=1);

// Serves a vessel photo added in the admin page. Read-only, like the statistics page.
require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;

$mmsi = (int) ($_GET['mmsi'] ?? 0);
try {
    $row = $mmsi > 0 ? Db::one('SELECT mime, data, uploaded_at FROM vessel_photo WHERE mmsi = ?', [$mmsi]) : null;
} catch (Throwable) {
    $row = null;
}
if ($row === null || !in_array($row['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . strlen((string) $row['data']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: public, max-age=2592000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) $row['uploaded_at']) . ' GMT');
echo $row['data'];
