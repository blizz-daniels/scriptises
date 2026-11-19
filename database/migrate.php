<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Database;

/** @var Database $database */
$pdo = $database->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $migration = basename($file);
    if (in_array($migration, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Unable to read $migration.");
    }

    try {
        // MySQL implicitly commits most DDL statements, so migrations remain
        // idempotent SQL files rather than pretending to be transactional.
        $pdo->exec($sql);
        $statement = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $statement->execute([$migration]);
        echo "Applied {$migration}" . PHP_EOL;
    } catch (Throwable $exception) {
        throw $exception;
    }
}
