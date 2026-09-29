<?php
declare(strict_types=1);

namespace AISSeaStats;

/** Shared page chrome and snippets for setup.php and admin.php. */
final class Page
{
    public static function open(string $title, bool $withMap = false): void
    {
        // Start the session before any output, or the session cookie is never sent
        // (PHP images ship with output_buffering = 0) and every form fails its CSRF check.
        Web::session();
        Web::headers();
        $lang = Web::e(I18n::lang());
        echo '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Web::e($title) . ' · AISSeaStats</title>'
            . '<link rel="icon" href="' . Web::asset('assets/icon.svg') . '" type="image/svg+xml">'
            . ($withMap ? '<link rel="stylesheet" href="' . Web::asset('assets/vendor/leaflet/leaflet.css') . '">' : '')
            . '<link rel="stylesheet" href="' . Web::asset('assets/app.css') . '">'
            . '<script src="' . Web::asset('assets/theme.js') . '"></script></head><body>'
            . '<header class="topbar"><div class="brand"><img src="' . Web::asset('assets/icon.svg') . '" alt="" width="28" height="28">'
            . '<div><h1>' . Web::e($title) . '</h1><p class="muted">AISSeaStats</p></div></div>'
            . '<div class="top-actions"><a class="btn secondary" href="index.php">' . Web::e(I18n::t('nav.stats')) . '</a></div></header>'
            . '<main class="form-page">';
    }

    /** @param array<int, string> $scripts */
    public static function close(array $scripts = []): void
    {
        echo '</main>';
        foreach ($scripts as $s) {
            echo '<script src="' . Web::asset($s) . '"></script>';
        }
        echo '</body></html>';
    }

    public static function ingestUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'IP-DU-PI:8095');
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            $host = 'IP-DU-PI:8095';
        }
        return 'http://' . $host . '/ingest.php';
    }

    public static function aiscatcherCommand(string $token): string
    {
        $station = preg_replace('/[^A-Za-z0-9_-]/', '', (string) Settings::get('station_name')) ?: 'station';
        return 'AIS-catcher ... -M DTM -H ' . self::ingestUrl() . ' interval 15 gzip on userpwd aisseastats:'
            . $token . ' id ' . $station;
    }

    /** True when the page was opened through an mDNS name (host.local). */
    public static function hostIsMdns(): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        return str_ends_with($host, '.local');
    }

    /**
     * How to fill AIS-catcher's HTTP output, field by field (managed mode),
     * plus the command-line variant. $token is shown in clear only right after it is created.
     */
    public static function aiscatcherHelp(string $token): string
    {
        $e = static fn (string $k, array $v = []): string => Web::e(I18n::t($k, $v));
        $rows = [
            ['Description', I18n::t('http.desc')],
            ['Link', I18n::t('http.link')],
            ['URL', self::ingestUrl()],
            ['Interval', '15'],
            ['ID', I18n::t('http.id')],
            ['Credentials', 'aisseastats:' . $token],
            ['Protocol', 'AISCATCHER'],
            ['Gzip', I18n::t('http.on')],
            ['Response', I18n::t('http.response')],
            ['Unique / Downsample Position', I18n::t('http.filters')],
        ];
        $html = '<h3 class="sub">' . $e('http.managed_title') . '</h3>'
            . '<p>' . $e('http.managed_intro') . '</p>'
            . '<table class="table http-fields"><thead><tr><th>' . $e('http.field') . '</th><th>' . $e('http.value') . '</th></tr></thead><tbody>';
        foreach ($rows as [$field, $value]) {
            $mono = in_array($field, ['URL', 'Interval', 'Credentials', 'Protocol'], true);
            $html .= '<tr><td>' . Web::e($field) . '</td><td>' . ($mono ? '<code>' . Web::e($value) . '</code>' : Web::e($value)) . '</td></tr>';
        }
        $html .= '</tbody></table>'
            . '<p class="muted small">' . $e('http.save') . '</p>';
        if (self::hostIsMdns()) {
            $html .= '<p class="notice err">' . $e('http.mdns_warning') . '</p>';
        }
        $html .= '<h3 class="sub">' . $e('setup.done.cli') . '</h3>'
            . '<pre class="cmd">' . Web::e(self::aiscatcherCommand($token)) . '</pre>'
            . '<p class="muted small">' . $e('setup.done.meta') . '</p>';
        return $html;
    }

    public static function newToken(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Settings::set('ingest_token_hash', hash('sha256', $token));
        return $token;
    }

    /** @return array<int, string> */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
