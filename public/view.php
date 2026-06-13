<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$documentId = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';
$doc = shared_document_for_request($documentId, $token);

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<?php if ($doc['recipient_email']): ?>
    <p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>
<?php else: ?>
    <p class="meta">Opened with simple document ID <?= h($doc['slug_id']) ?></p>
<?php endif ?>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
