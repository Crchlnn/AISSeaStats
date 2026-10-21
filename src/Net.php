<?php
declare(strict_types=1);

namespace AISSeaStats;

/** Address helpers for the ingestion allow-list (INGEST_ALLOW). */
final class Net
{
    /**
     * One allow-list entry: "192.168.1.0/24", "2001:db8::/32" or a single address.
     * Since 1.1.6 the prefix must be a number within range: "192.168.1.0/" or "/abc" used to read as /0 (everything).
     * @return array{0: string, 1: int}|null [network in binary, prefix length] or null when invalid
     */
    public static function parseCidr(string $entry): ?array
    {
        $entry = trim($entry);
        $parts = explode('/', $entry, 2);
        $net = @inet_pton($parts[0]);
        if ($net === false) {
            return null;
        }
        $max = strlen($net) * 8;
        if (!isset($parts[1])) {
            return [$net, $max];
        }
        if (!preg_match('/^\d{1,3}$/', $parts[1]) || (int) $parts[1] > $max) {
            return null;
        }
        return [$net, (int) $parts[1]];
    }

    /** True when $ip is in one of the comma separated entries; invalid entries are skipped and logged. */
    public static function ipAllowed(string $ip, string $list): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        foreach (explode(',', $list) as $entry) {
            if (trim($entry) === '') {
                continue;
            }
            $cidr = self::parseCidr($entry);
            if ($cidr === null) {
                error_log('[aisseastats] INGEST_ALLOW: ignored invalid entry "' . trim($entry) . '"');
                continue;
            }
            [$net, $bits] = $cidr;
            if (strlen($net) !== strlen($ipBin)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($rem === 0 || ((ord($ipBin[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }
}
