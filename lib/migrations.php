<?php

function run_pending_migrations(PDO $pdo, ?string $directory = null): array {
    ensure_schema_migrations_table($pdo);

    $applied = applied_migration_versions($pdo);
    $ran = [];

    foreach (migration_files($directory) as $version => $path) {
        if (isset($applied[$version])) {
            continue;
        }

        $migration = load_migration($path);
        run_migration($pdo, $migration['up'], function () use ($pdo, $version): void {
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $stmt->execute([$version]);
        });
        $ran[] = $version;
    }

    return $ran;
}

function rollback_migrations(PDO $pdo, int $steps = 1, ?string $directory = null): array {
    ensure_schema_migrations_table($pdo);

    $files = migration_files($directory);
    $applied = array_keys(applied_migration_versions($pdo));
    rsort($applied, SORT_STRING);

    $rolledBack = [];
    foreach (array_slice($applied, 0, $steps) as $version) {
        if (!isset($files[$version])) {
            throw new RuntimeException("Migration file for {$version} is missing.");
        }

        $migration = load_migration($files[$version]);
        run_migration($pdo, $migration['down'], function () use ($pdo, $version): void {
            $stmt = $pdo->prepare('DELETE FROM schema_migrations WHERE version = ?');
            $stmt->execute([$version]);
        });
        $rolledBack[] = $version;
    }

    return $rolledBack;
}

function ensure_schema_migrations_table(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY,
            migrated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');
}

function applied_migration_versions(PDO $pdo): array {
    $stmt = $pdo->query('SELECT version FROM schema_migrations ORDER BY version');
    $versions = [];

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $version) {
        $versions[$version] = true;
    }

    return $versions;
}

function migration_files(?string $directory = null): array {
    $directory ??= __DIR__ . '/../migrations';
    $files = glob($directory . '/*.php') ?: [];
    $migrations = [];

    foreach ($files as $path) {
        $name = basename($path);
        if (!preg_match('/^(\d{14})_[a-z0-9_]+\.php$/', $name, $matches)) {
            throw new RuntimeException("Invalid migration filename: {$name}");
        }

        $version = $matches[1];
        if (isset($migrations[$version])) {
            throw new RuntimeException("Duplicate migration version: {$version}");
        }

        $migrations[$version] = $path;
    }

    ksort($migrations, SORT_STRING);
    return $migrations;
}

function load_migration(string $path): array {
    $migration = require $path;

    if (!is_array($migration) || !isset($migration['up'], $migration['down'])) {
        throw new RuntimeException("Migration must return up/down callbacks: {$path}");
    }

    if (!is_callable($migration['up']) || !is_callable($migration['down'])) {
        throw new RuntimeException("Migration up/down entries must be callable: {$path}");
    }

    return $migration;
}

function run_migration(PDO $pdo, callable $change, callable $record): void {
    $pdo->beginTransaction();

    try {
        $change($pdo);
        $record();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
