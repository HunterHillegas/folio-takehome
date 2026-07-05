<?php

require __DIR__ . '/../lib/bootstrap.php';

current_staff();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST to create a share link.']);
    exit;
}

$docId = (int) ($_POST['doc_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    echo json_encode(['error' => 'Document not found.']);
    exit;
}

$share = create_document_share($doc, DIRECT_SHARE_RECIPIENT, 'labeled');

echo json_encode(['url' => $share['url']]);
