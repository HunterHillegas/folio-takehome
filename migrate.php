<?php

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrations.php';

$direction = $argv[1] ?? 'up';
$steps = isset($argv[2]) ? (int) $argv[2] : 1;

try {
    if ($direction === 'up') {
        $ran = run_pending_migrations(db());
        echo count($ran) === 0
            ? "No pending migrations.\n"
            : 'Migrated: ' . implode(', ', $ran) . "\n";
        exit(0);
    }

    if ($direction === 'down') {
        if ($steps < 1) {
            throw new InvalidArgumentException('Down steps must be at least 1.');
        }

        $rolledBack = rollback_migrations(db(), $steps);
        echo count($rolledBack) === 0
            ? "No migrations to roll back.\n"
            : 'Rolled back: ' . implode(', ', $rolledBack) . "\n";
        exit(0);
    }

    throw new InvalidArgumentException('Usage: php migrate.php [up|down] [steps]');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
