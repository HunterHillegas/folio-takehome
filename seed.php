<?php

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrations.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
run_pending_migrations($pdo);

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$stmt = $pdo->prepare("
    INSERT INTO documents (title, body, created_by, readable_id, slug_id, publish_at, publish_timezone)
    VALUES (?, ?, 1, ?, ?, datetime('now'), ?)
");
$ids = document_ids_for_title('Welcome Packet');
$stmt->execute([
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    $ids['readable_id'],
    $ids['slug_id'],
    DEFAULT_PUBLISH_TIMEZONE,
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email, share_type)
    VALUES (?, ?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com', 'secure']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
