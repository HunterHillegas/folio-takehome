<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$documentId = $_GET['slug'] ?? ($_GET['id'] ?? '');
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

if ($token !== '' && $documentId !== '' && $documentId !== $doc['slug_id']) {
    header('Location: ' . document_share_url($doc, 'labeled', $token, request_base_url()), true, 301);
    exit;
}

if (!document_is_available($doc)) {
    http_response_code(403);
    render_header('Not yet available');
    $publishIso = document_publish_iso8601($doc);
    ?>
    <div class="centered-message">
        <p class="meta"><?= h($doc['title']) ?></p>
        <h1>Not yet available</h1>
        <?php if (document_status($doc) === 'Scheduled'): ?>
            <p>This document is scheduled to become available on <?= h(format_document_publish_at($doc)) ?>.</p>
            <p class="meta">Your local time: <time data-local-publish-at="<?= h($publishIso) ?>"><?= h(format_document_publish_at($doc)) ?></time></p>
        <?php else: ?>
            <p>This document is not yet available.</p>
        <?php endif ?>
    </div>
    <script>
    (() => {
        const publishAt = new Date('<?= h($publishIso) ?>');
        const localTime = document.querySelector('[data-local-publish-at]');
        if (localTime && !Number.isNaN(publishAt.getTime())) {
            localTime.textContent = publishAt.toLocaleString(undefined, {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                timeZoneName: 'short'
            });
        }

        const delay = publishAt.getTime() - Date.now();
        if (delay > 0) {
            window.setTimeout(() => window.location.reload(), Math.min(delay + 1000, 3600000));
        }
    })();
    </script>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<?php if ($doc['recipient_email']): ?>
    <?php if ($doc['recipient_email'] === DIRECT_SHARE_RECIPIENT): ?>
        <p class="meta">Opened with labeled share link <?= h($doc['slug_id']) ?></p>
    <?php else: ?>
        <p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>
    <?php endif ?>
<?php else: ?>
    <p class="meta">Opened with discreet share link</p>
<?php endif ?>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
