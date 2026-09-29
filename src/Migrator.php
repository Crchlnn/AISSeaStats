<?php
declare(strict_types=1);

namespace AISSeaStats;

/** Applies SQL files from sql/migrations in order, once each. */
final class Migrator
{
    /** @return array<int, string> names of migrations applied in this run */
    public static function run(): array
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(128) NOT NULL PRIMARY KEY, applied_at INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB');
        $done = array_column(Db::all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(APP_ROOT . '/sql/migrations/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (preg_split('/;\s*$/m', $sql) ?: [] as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
            Db::run('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, time()]);
            $applied[] = $name;
        }
        return $applied;
    }

    public static function isReady(): bool
    {
        try {
            return (bool) Db::value("SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'vessel'");
        } catch (\Throwable) {
            return false;
        }
    }
}
