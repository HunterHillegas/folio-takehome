<?php

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE shares ADD COLUMN share_type TEXT NOT NULL DEFAULT 'labeled'");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE shares DROP COLUMN share_type');
    },
];
