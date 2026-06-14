<?php

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE documents ADD COLUMN publish_at TEXT');
        $pdo->exec('ALTER TABLE documents ADD COLUMN publish_timezone TEXT');
        $pdo->exec("
            UPDATE documents
            SET publish_at = COALESCE(created_at, datetime('now')),
                publish_timezone = 'America/Chicago'
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE documents DROP COLUMN publish_timezone');
        $pdo->exec('ALTER TABLE documents DROP COLUMN publish_at');
    },
];
