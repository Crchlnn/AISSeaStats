<?php
declare(strict_types=1);

/**
 * Ingestion endpoint for AIS-catcher's HTTP output (-H).
 *
 * AIS-catcher ... -M DTM -H http://<host>:8095/ingest.php interval 15 gzip on userpwd aisseastats:<token>
 *
 * Authentication: the token as HTTP Basic password (any user name) or as a Bearer token.
 */

require __DIR__ . '/../src/bootstrap.php';

use AISSeaStats\Db;
use AISSeaStats\Ingest;
use AISSeaStats\Settings;
use AISSeaStats\Web;

$started = microtime(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    Web::json(['error' => 'POST only'], 405);
}

try {
    $hash = (string) Settings::get('ingest_token_hash');
} catch (Throwable $e) {
    error_log('[aisseastats] ingest: database unavailable: ' . $e->getMessage());
    Web::json(['error' => 'database unavailable'], 503);
}
if ($hash === '') {
    Web::json(['error' => 'not configured: finish the setup wizard first'], 503);
}

// Optional source allow-list (comma separated CIDRs) from the environment.
$allow = trim(Db::env('INGEST_ALLOW', ''));
if ($allow !== '' && !ip_allowed((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $allow)) {
    Web::json(['error' => 'forbidden'], 403);
}

$token = presented_token();
if ($token === null || !hash_equals($hash, hash('sha256', $token))) {
    usleep(250000);
    log_batch(null, 0, 0, 0, $started, $token === null ? 'missing token' : 'token rejected');
    header('WWW-Authenticate: Basic realm="AISSeaStats ingest"');
    Web::json(['error' => 'unauthorized'], 401);
}

$len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len > Ingest::MAX_BODY_BYTES) {
    Web::json(['error' => 'payload too large'], 413);
}
$body = (string) file_get_contents('php://input', false, null, 0, Ingest::MAX_BODY_BYTES + 1);
if (strlen($body) > Ingest::MAX_BODY_BYTES) {
    Web::json(['error' => 'payload too large'], 413);
}

$station = null;
try {
    $decoded = Ingest::decodeBody($body, (string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? ''));
    $station = $decoded['station'];
    $result = (new Ingest())->process($decoded['msgs']);
    log_batch($station, strlen($body), $result['received'], $result['accepted'], $started, null);
    Web::json(['ok' => true] + $result);
} catch (InvalidArgumentException $e) {
    log_batch($station, strlen($body), 0, 0, $started, $e->getMessage());
    Web::json(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[aisseastats] ingest failed: ' . $e->getMessage());
    log_batch($station, strlen($body), 0, 0, $started, 'internal error');
    Web::json(['error' => 'internal error'], 500);
}

function presented_token(): ?string
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m)) {
        $pair = base64_decode($m[1], true);
        if ($pair !== false && str_contains($pair, ':')) {
            return substr($pair, strpos($pair, ':') + 1);
        }
    }
    if (isset($_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_PW'] !== '') {
        return (string) $_SERVER['PHP_AUTH_PW'];
    }
    return null;
}

function ip_allowed(string $ip, string $list): bool
{
    foreach (explode(',', $list) as $cidr) {
        $cidr = trim($cidr);
        if ($cidr === '') {
            continue;
        }
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            continue;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            continue;
        }
        if ($rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

function log_batch(?string $station, int $bytes, int $msgs, int $accepted, float $started, ?string $error): void
{
    try {
        Db::run('INSERT INTO ingest_log (ts, station, bytes, msgs, accepted, ms, error) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            time(), $station !== null ? mb_substr($station, 0, 64) : null, $bytes, $msgs, $accepted,
            (int) round((microtime(true) - $started) * 1000), $error !== null ? mb_substr($error, 0, 255) : null,
        ]);
    } catch (Throwable) {
    }
}
