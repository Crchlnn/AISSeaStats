<?php
declare(strict_types=1);

namespace AISSeaStats;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Connection settings come from environment variables
 * (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD) set by Docker Compose.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function connect(): PDO
    {
        $host = self::env('DB_HOST', 'db');
        $port = self::env('DB_PORT', '3306');
        $name = self::env('DB_NAME', 'aisseastats');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, self::env('DB_USER', 'aisseastats'), self::env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    /** Wait until the database accepts connections (container start-up). */
    public static function waitReady(int $timeoutSec = 120): void
    {
        $deadline = time() + $timeoutSec;
        while (true) {
            try {
                self::$pdo = self::connect();
                return;
            } catch (PDOException $e) {
                if (time() >= $deadline) {
                    throw $e;
                }
                fwrite(STDERR, "[aisseastats] waiting for database: {$e->getMessage()}\n");
                sleep(3);
            }
        }
    }

    public static function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $file = getenv($key . '_FILE');
            if ($file !== false && is_readable($file)) {
                return trim((string) file_get_contents($file));
            }
            return $default;
        }
        return $v;
    }

    /** @param array<int|string, mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @param array<int|string, mixed> $params @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }
}
