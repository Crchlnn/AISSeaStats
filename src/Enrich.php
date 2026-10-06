<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Vessel photos, from sources that allow reuse:
 *  1. a photo added by the station owner in the admin page (stored locally);
 *  2. Wikimedia Commons, files categorised "IMO nnnnnnn";
 *  3. Wikidata, the image (P18) of the item with that IMO number (P458) or MMSI (P587).
 * Look-ups, including "nothing found", are cached for 30 days; a network error is retried after an hour.
 * Commercial sites (VesselFinder, ShipSpotting) are only linked, never fetched:
 * their terms do not allow automated reuse of their photos.
 */
final class Enrich
{
    private const TTL = 30 * 86400;
    private const RETRY = 3600;
    private const USER_AGENT = 'AISSeaStats/1.0 (https://github.com/Crchlnn/AISSeaStats)';
    private const COMMONS = 'https://commons.wikimedia.org/w/api.php';
    private const WIKIDATA = 'https://www.wikidata.org/w/api.php';

    /** @var callable|null test hook: fn(string $url): string|false */
    public static $http = null;

    /** @return array{thumb:string, page:?string, author:?string, license:?string, source:string}|null */
    public static function photo(int $mmsi, ?int $imo): ?array
    {
        $local = Db::one('SELECT uploaded_at, credit FROM vessel_photo WHERE mmsi = ?', [$mmsi]);
        if ($local !== null) {
            return [
                'thumb' => 'photo.php?mmsi=' . $mmsi . '&v=' . (int) $local['uploaded_at'],
                'page' => null,
                'author' => $local['credit'] !== null ? (string) $local['credit'] : null,
                'license' => null,
                'source' => 'local',
            ];
        }
        if ($imo !== null && Ingest::validImo($imo)) {
            $found = self::cached('imo:' . $imo, static fn () => self::byImo($imo));
            if ($found !== null) {
                return $found;
            }
        }
        if ($mmsi >= 100000000) {
            return self::cached('mmsi:' . $mmsi, static fn () => self::byWikidata('P587', (string) $mmsi));
        }
        return null;
    }

    /**
     * @param callable(): (array<string, string|null>|null|false) $lookup
     * @return array{thumb:string, page:?string, author:?string, license:?string, source:string}|null
     */
    private static function cached(string $key, callable $lookup): ?array
    {
        $row = Db::one('SELECT * FROM photo_lookup WHERE k = ?', [$key]);
        if ($row !== null && (int) $row['fetched_at'] > time() - self::TTL) {
            return self::fromRow($row);
        }
        if (!Settings::get('enrich_enabled')) {
            return $row !== null ? self::fromRow($row) : null;
        }
        $found = $lookup();
        if ($found === false) {
            // Network error: keep what we had, try again in an hour.
            Db::run('INSERT INTO photo_lookup (k, fetched_at, found) VALUES (?, ?, 0)
                     ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at)', [$key, time() - self::TTL + self::RETRY]);
            return $row !== null ? self::fromRow($row) : null;
        }
        Db::run('REPLACE INTO photo_lookup (k, fetched_at, found, thumb_url, page_url, author, license, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $key, time(), $found === null ? 0 : 1, $found['thumb'] ?? null, $found['page'] ?? null,
            $found['author'] ?? null, $found['license'] ?? null, $found['source'] ?? null,
        ]);
        return $found === null ? null : self::fromRow(['found' => 1, 'thumb_url' => $found['thumb'], 'page_url' => $found['page'],
            'author' => $found['author'], 'license' => $found['license'], 'source' => $found['source']]);
    }

    /** @param array<string, mixed> $row @return array{thumb:string, page:?string, author:?string, license:?string, source:string}|null */
    private static function fromRow(array $row): ?array
    {
        if (!(int) $row['found'] || empty($row['thumb_url'])) {
            return null;
        }
        return [
            'thumb' => (string) $row['thumb_url'],
            'page' => $row['page_url'] !== null ? (string) $row['page_url'] : null,
            'author' => $row['author'] !== null ? (string) $row['author'] : null,
            'license' => $row['license'] !== null ? (string) $row['license'] : null,
            'source' => (string) ($row['source'] ?? 'commons'),
        ];
    }

    /** @return array<string, string|null>|null|false */
    private static function byImo(int $imo): array|null|false
    {
        $data = self::get(self::COMMONS, [
            'action' => 'query', 'format' => 'json', 'formatversion' => '2',
            'generator' => 'categorymembers', 'gcmtitle' => 'Category:IMO ' . $imo, 'gcmtype' => 'file', 'gcmlimit' => '10',
            'prop' => 'imageinfo', 'iiprop' => 'url|mime|extmetadata', 'iiurlwidth' => '640',
            'iiextmetadatafilter' => 'Artist|LicenseShortName',
        ]);
        if ($data === false) {
            return false;
        }
        $found = self::parseImageInfo($data, 'commons');
        if ($found !== null) {
            return $found;
        }
        return self::byWikidata('P458', (string) $imo);
    }

    /**
     * Wikidata item whose property (P458 IMO / P587 MMSI) equals $value, then its image (P18) on Commons.
     * @return array<string, string|null>|null|false
     */
    private static function byWikidata(string $property, string $value): array|null|false
    {
        $search = self::get(self::WIKIDATA, [
            'action' => 'query', 'format' => 'json', 'formatversion' => '2', 'list' => 'search',
            'srsearch' => 'haswbstatement:' . $property . '=' . $value, 'srlimit' => '1',
        ]);
        if ($search === false) {
            return false;
        }
        $qid = self::parseSearchQid($search);
        if ($qid === null) {
            return null;
        }
        $claims = self::get(self::WIKIDATA, ['action' => 'wbgetclaims', 'format' => 'json', 'entity' => $qid, 'property' => 'P18']);
        if ($claims === false) {
            return false;
        }
        $file = self::parseImageClaim($claims);
        if ($file === null) {
            return null;
        }
        $info = self::get(self::COMMONS, [
            'action' => 'query', 'format' => 'json', 'formatversion' => '2', 'titles' => 'File:' . $file,
            'prop' => 'imageinfo', 'iiprop' => 'url|mime|extmetadata', 'iiurlwidth' => '640',
            'iiextmetadatafilter' => 'Artist|LicenseShortName',
        ]);
        if ($info === false) {
            return false;
        }
        return self::parseImageInfo($info, 'wikidata');
    }

    /** @param array<string, mixed> $data @return array<string, string|null>|null */
    public static function parseImageInfo(array $data, string $source): ?array
    {
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
            $author = isset($meta['Artist']['value']) ? trim(html_entity_decode(strip_tags((string) $meta['Artist']['value']))) : '';
            $license = isset($meta['LicenseShortName']['value']) ? trim(strip_tags((string) $meta['LicenseShortName']['value'])) : '';
            $pageUrl = (string) ($info['descriptionurl'] ?? '');
            return [
                'thumb' => $thumb,
                'page' => str_starts_with($pageUrl, 'https://commons.wikimedia.org/') ? $pageUrl : null,
                'author' => $author !== '' ? mb_substr($author, 0, 255) : null,
                'license' => $license !== '' ? mb_substr($license, 0, 64) : null,
                'source' => $source,
            ];
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    public static function parseSearchQid(array $data): ?string
    {
        $title = (string) ($data['query']['search'][0]['title'] ?? '');
        return preg_match('/^Q\d+$/', $title) ? $title : null;
    }

    /** @param array<string, mixed> $data */
    public static function parseImageClaim(array $data): ?string
    {
        foreach ($data['claims']['P18'] ?? [] as $claim) {
            $file = $claim['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($file) && $file !== '' && !str_contains($file, '/')) {
                return $file;
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|false false on network or decoding error
     */
    private static function get(string $base, array $params): array|false
    {
        $url = $base . '?' . http_build_query($params);
        if (self::$http !== null) {
            $body = (self::$http)($url);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 4, 'user_agent' => self::USER_AGENT, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($body)) {
            return false;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : false;
    }
}
