<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Vessel photos from Wikimedia Commons, looked up by IMO number
 * (Commons files are categorised as "IMO nnnnnnn"). Results, including
 * "nothing found", are cached for 30 days. This is the only outbound call
 * the application makes, and it can be disabled in the admin.
 */
final class Enrich
{
    private const TTL = 30 * 86400;
    private const USER_AGENT = 'AISSeaStats/1.0 (https://github.com/Crchlnn/AISSeaStats)';

    /** @return array{thumb:string, page:string, author:?string, license:?string}|null */
    public static function photo(?int $imo): ?array
    {
        if ($imo === null || !Ingest::validImo($imo)) {
            return null;
        }
        $row = Db::one('SELECT * FROM enrich_cache WHERE imo = ?', [$imo]);
        if ($row !== null && (int) $row['fetched_at'] > time() - self::TTL) {
            return self::fromRow($row);
        }
        if (!Settings::get('enrich_enabled')) {
            return $row !== null ? self::fromRow($row) : null;
        }
        $found = self::fetch($imo);
        if ($found === false) {
            if ($row === null) {
                // Network error: remember it for an hour so each popup does not wait on a timeout.
                Db::run('INSERT IGNORE INTO enrich_cache (imo, fetched_at, found) VALUES (?, ?, 0)', [$imo, time() - self::TTL + 3600]);
                return null;
            }
            return self::fromRow($row);
        }
        Db::run('REPLACE INTO enrich_cache (imo, fetched_at, found, thumb_url, page_url, author, license)
                 VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $imo, time(), $found === null ? 0 : 1, $found['thumb'] ?? null, $found['page'] ?? null,
            $found['author'] ?? null, $found['license'] ?? null,
        ]);
        return $found;
    }

    /** @param array<string, mixed> $row @return array{thumb:string, page:string, author:?string, license:?string}|null */
    private static function fromRow(array $row): ?array
    {
        if (!(int) $row['found'] || empty($row['thumb_url'])) {
            return null;
        }
        return [
            'thumb' => (string) $row['thumb_url'],
            'page' => (string) $row['page_url'],
            'author' => $row['author'] !== null ? (string) $row['author'] : null,
            'license' => $row['license'] !== null ? (string) $row['license'] : null,
        ];
    }

    /** @return array{thumb:string, page:string, author:?string, license:?string}|null|false false on network error */
    private static function fetch(int $imo): array|null|false
    {
        $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'generator' => 'categorymembers',
            'gcmtitle' => 'Category:IMO ' . $imo,
            'gcmtype' => 'file',
            'gcmlimit' => '10',
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime|extmetadata',
            'iiurlwidth' => '640',
            'iiextmetadatafilter' => 'Artist|LicenseShortName',
        ]);
        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'user_agent' => self::USER_AGENT,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return false;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return false;
        }
        foreach ($data['query']['pages'] ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!is_array($info) || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                continue;
            }
            $thumb = (string) ($info['thumburl'] ?? $info['url'] ?? '');
            if (!str_starts_with($thumb, 'https://upload.wikimedia.org/')) {
                continue;
            }
            $meta = $info['extmetadata'] ?? [];
            $author = isset($meta['Artist']['value']) ? trim(html_entity_decode(strip_tags((string) $meta['Artist']['value']))) : null;
            $license = isset($meta['LicenseShortName']['value']) ? trim(strip_tags((string) $meta['LicenseShortName']['value'])) : null;
            return [
                'thumb' => $thumb,
                'page' => (string) ($info['descriptionurl'] ?? ''),
                'author' => $author !== '' ? mb_substr((string) $author, 0, 255) : null,
                'license' => $license !== '' ? mb_substr((string) $license, 0, 64) : null,
            ];
        }
        return null;
    }
}
