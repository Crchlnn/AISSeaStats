<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Admin login throttling (1.1.6). Failed attempts are counted per address and in total over a sliding window;
 * past a threshold, logins are refused at once (even with the right password) until the window has passed.
 * No sleep(): a waiting request would hold one of the few PHP workers and let failed logins stall the site.
 */
final class LoginGuard
{
    public const WINDOW_S = 900;      // 15 minutes
    public const MAX_PER_IP = 10;
    public const MAX_TOTAL = 100;     // many addresses at once (distributed guessing)

    /** Seconds to wait before the next attempt is accepted; 0 when not locked. */
    public static function lockedFor(string $ip, int $now): int
    {
        $since = $now - self::WINDOW_S;
        $bin = self::bin($ip);
        $mine = Db::one('SELECT COUNT(*) AS n, MIN(ts) AS first FROM (SELECT ts FROM login_attempt WHERE ip = ? AND ts > ?
            ORDER BY ts DESC LIMIT ' . self::MAX_PER_IP . ') x', [$bin, $since]);
        if ((int) ($mine['n'] ?? 0) >= self::MAX_PER_IP) {
            return max(1, (int) $mine['first'] + self::WINDOW_S - $now);
        }
        $all = Db::one('SELECT COUNT(*) AS n, MIN(ts) AS first FROM (SELECT ts FROM login_attempt WHERE ts > ?
            ORDER BY ts DESC LIMIT ' . self::MAX_TOTAL . ') x', [$since]);
        if ((int) ($all['n'] ?? 0) >= self::MAX_TOTAL) {
            return max(1, (int) $all['first'] + self::WINDOW_S - $now);
        }
        return 0;
    }

    public static function failed(string $ip, int $now): void
    {
        Db::run('INSERT INTO login_attempt (ip, ts) VALUES (?, ?)', [self::bin($ip), $now]);
    }

    /** A successful login forgets that address's failures. */
    public static function succeeded(string $ip): void
    {
        Db::run('DELETE FROM login_attempt WHERE ip = ?', [self::bin($ip)]);
    }

    /** bin/reset-admin.php: the owner gets back in at once. */
    public static function clear(): void
    {
        Db::run('DELETE FROM login_attempt');
    }

    public static function purge(int $now): void
    {
        Db::run('DELETE FROM login_attempt WHERE ts < ?', [$now - 86400]);
    }

    private static function bin(string $ip): string
    {
        $b = @inet_pton($ip);
        return $b === false ? str_pad(substr($ip, 0, 16), 16, "\0") : $b;
    }
}
