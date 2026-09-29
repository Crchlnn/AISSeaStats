<?php
declare(strict_types=1);

namespace AISSeaStats;

/** Web helpers: security headers, sessions, CSRF, escaping, JSON output. */
final class Web
{
    public static function headers(bool $html = true): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        if ($html) {
            $tiles = self::originOf((string) Settings::get('map_tiles'));
            $img = "'self' data: https://upload.wikimedia.org" . ($tiles !== '' ? ' ' . $tiles : '');
            header("Content-Security-Policy: default-src 'self'; img-src {$img}; style-src 'self'; script-src 'self'; "
                . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        }
    }

    /** Scheme + host of a tile URL template, with {s} subdomains widened to a wildcard. */
    public static function originOf(string $url): string
    {
        if (!preg_match('~^(https?://)([^/]+)~i', $url, $m)) {
            return '';
        }
        $host = str_replace('{s}', '*', $m[2]);
        if (!preg_match('/^[A-Za-z0-9.*:-]+$/', $host)) {
            return '';
        }
        return strtolower($m[1]) . $host;
    }

    public static function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('aisseastats');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function csrfToken(): string
    {
        self::session();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        self::session();
        $sent = (string) ($_POST['csrf'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            http_response_code(400);
            exit('Invalid form token, please reload the page.');
        }
    }

    public static function isAdmin(): bool
    {
        self::session();
        return !empty($_SESSION['admin']) && ($_SESSION['admin_hash'] ?? '') === substr((string) Settings::get('admin_hash'), -16);
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(mixed $data, int $status = 200, int $maxAge = 0): never
    {
        http_response_code($status);
        self::headers(false);
        header('Content-Type: application/json; charset=utf-8');
        header($maxAge > 0 ? "Cache-Control: private, max-age={$maxAge}" : 'Cache-Control: no-store');
        $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
        if (strlen((string) $out) > 2048 && str_contains($accept, 'gzip') && function_exists('gzencode')) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            $out = gzencode((string) $out, 5);
        }
        echo $out;
        exit;
    }

    /** Redirect to setup while the first-run wizard has not been completed. */
    public static function requireSetup(): void
    {
        if (!Migrator::isReady() || !Settings::get('setup_done')) {
            header('Location: setup.php');
            exit;
        }
    }

    public static function asset(string $path): string
    {
        $file = APP_ROOT . '/public/' . $path;
        $v = is_file($file) ? substr(md5((string) filemtime($file) . VERSION), 0, 8) : VERSION;
        return self::e($path . '?v=' . $v);
    }
}
