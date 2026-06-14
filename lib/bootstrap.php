<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function document_slug_base(string $title): string {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'document';
}

function readable_code(int $length = 4): string {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function next_unique_document_id(string $column, string $base, ?string $suffix = null): string {
    $candidate = $suffix ? "{$base}-{$suffix}" : $base;
    $counter = 2;

    while (document_id_exists($column, $candidate)) {
        if ($suffix) {
            $candidate = "{$base}-" . readable_code();
        } else {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }
    }

    return $candidate;
}

function document_id_exists(string $column, string $value): bool {
    if (!in_array($column, ['readable_id', 'slug_id'], true)) {
        throw new InvalidArgumentException('Unsupported document ID column.');
    }

    $stmt = db()->prepare("SELECT 1 FROM documents WHERE {$column} = ? LIMIT 1");
    $stmt->execute([$value]);

    return $stmt->fetchColumn() !== false;
}

function document_ids_for_title(string $title): array {
    $base = document_slug_base($title);

    return [
        'readable_id' => next_unique_document_id('readable_id', $base, readable_code()),
        'slug_id' => next_unique_document_id('slug_id', $base),
    ];
}

function document_share_url(array $doc, string $shareType, string $token, string $baseUrl): string {
    $baseUrl = rtrim($baseUrl, '/');

    if ($shareType === 'simple') {
        return $baseUrl . '/view.php?' . http_build_query(['id' => $doc['slug_id']], '', '&', PHP_QUERY_RFC3986);
    }

    return $baseUrl . '/view.php?' . http_build_query(
        ['id' => $doc['readable_id'], 'token' => $token],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function request_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

    return "{$scheme}://{$host}";
}

function shared_document_for_request(string $documentId, string $token): ?array {
    $documentId = trim($documentId);
    $token = trim($token);

    if ($token !== '') {
        $stmt = db()->prepare('
            SELECT d.*, s.recipient_email
            FROM shares s
            JOIN documents d ON d.id = s.document_id
            WHERE s.token = ?
        ');
        $stmt->execute([$token]);
        $doc = $stmt->fetch();

        if (!$doc) {
            return null;
        }

        if ($documentId !== '' && !in_array($documentId, [$doc['readable_id'], $doc['slug_id']], true)) {
            return null;
        }

        return $doc;
    }

    if ($documentId === '') {
        return null;
    }

    $stmt = db()->prepare('
        SELECT d.*, NULL AS recipient_email
        FROM documents d
        WHERE d.slug_id = ?
            AND EXISTS (
                SELECT 1
                FROM shares s
                WHERE s.document_id = d.id
                    AND s.share_type = ?
            )
    ');
    $stmt->execute([$documentId, 'simple']);
    $doc = $stmt->fetch();

    return $doc ?: null;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
