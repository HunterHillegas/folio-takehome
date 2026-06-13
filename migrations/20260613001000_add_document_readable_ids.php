<?php

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE documents ADD COLUMN readable_id TEXT');
        $pdo->exec('ALTER TABLE documents ADD COLUMN slug_id TEXT');

        $docs = $pdo->query('SELECT id, title FROM documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $usedReadable = [];
        $usedSlug = [];

        foreach ($docs as $doc) {
            $base = migration_slug_base($doc['title']);
            $slugId = migration_unique_id($base, $usedSlug, false);
            $readableId = migration_unique_id($base, $usedReadable, true);

            $stmt = $pdo->prepare('UPDATE documents SET readable_id = ?, slug_id = ? WHERE id = ?');
            $stmt->execute([$readableId, $slugId, $doc['id']]);
        }

        $pdo->exec('CREATE UNIQUE INDEX idx_documents_readable_id ON documents(readable_id)');
        $pdo->exec('CREATE UNIQUE INDEX idx_documents_slug_id ON documents(slug_id)');
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP INDEX IF EXISTS idx_documents_readable_id');
        $pdo->exec('DROP INDEX IF EXISTS idx_documents_slug_id');
        $pdo->exec('ALTER TABLE documents DROP COLUMN readable_id');
        $pdo->exec('ALTER TABLE documents DROP COLUMN slug_id');
    },
];

function migration_slug_base(string $title): string {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'document';
}

function migration_unique_id(string $base, array &$used, bool $secure): string {
    $candidate = $secure ? "{$base}-" . migration_readable_code() : $base;
    $counter = 2;

    while (isset($used[$candidate])) {
        if ($secure) {
            $candidate = "{$base}-" . migration_readable_code();
        } else {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }
    }

    $used[$candidate] = true;

    return $candidate;
}

function migration_readable_code(): string {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';

    for ($i = 0; $i < 4; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}
