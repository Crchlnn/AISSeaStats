<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * One-time setup code (1.1.6). Until the first-run wizard is completed, whoever reached the page first could
 * become admin; the wizard now also asks for a code only shown to the person who runs the server
 * (install.sh output, container logs, or: docker compose exec app php bin/setup-code.php).
 */
final class SetupCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I: easy to type

    /** The current code, created on first use; null once setup is done. */
    public static function current(): ?string
    {
        if (Settings::get('setup_done')) {
            return null;
        }
        $code = Settings::get('setup_code');
        if (!is_string($code) || !preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code)) {
            $code = self::generate();
            Settings::set('setup_code', $code);
        }
        return $code;
    }

    public static function generate(): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return substr($s, 0, 4) . '-' . substr($s, 4);
    }

    /** Typed code matches, whatever the case, spaces or dash. */
    public static function check(string $typed): bool
    {
        $code = Settings::get('setup_code');
        if (!is_string($code) || $code === '') {
            return false;
        }
        $norm = static fn (string $s): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
        return $norm($typed) !== '' && hash_equals($norm($code), $norm($typed));
    }

    /**
     * Claims the setup once (atomic): two forms sent at the same time cannot both become admin.
     * The claim row is removed again if the setup could not be completed.
     */
    public static function claim(): bool
    {
        try {
            Db::run('INSERT INTO setting (name, value, updated_at) VALUES (?, ?, ?)', ['setup_claim', 'true', time()]);
            return true;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false; // duplicate key: someone else is completing the setup
            }
            throw $e;
        }
    }

    public static function release(): void
    {
        Db::run('DELETE FROM setting WHERE name = ?', ['setup_claim']);
    }

    /** After a successful setup the code is no longer needed. */
    public static function forget(): void
    {
        Db::run('DELETE FROM setting WHERE name = ?', ['setup_code']);
    }
}
