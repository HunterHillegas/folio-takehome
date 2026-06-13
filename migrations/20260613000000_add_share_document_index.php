<?php

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shares_document_id ON shares(document_id)');
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP INDEX IF EXISTS idx_shares_document_id');
    },
];
