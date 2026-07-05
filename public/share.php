<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docId = (int) ($_GET['doc'] ?? 0);
$stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error = null;
$created_url = null;
$created_share_type = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $shareType = $_POST['share_type'] ?? 'labeled';

    if ($email === '') {
        $error = 'Recipient email is required.';
    } elseif (!in_array($shareType, ['labeled', 'discreet'], true)) {
        $error = 'Choose a link type.';
    } else {
        $share = create_document_share($doc, $email, $shareType);
        $created_url = $share['url'];
        $created_share_type = $shareType;
    }
}

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Choose the link style that fits this document.</p>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_url): ?>
    <div class="banner banner-success">
        <?= $created_share_type === 'discreet' ? 'Discreet link ready:' : 'Labeled link ready:' ?>
        <code><?= h($created_url) ?></code>
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <p class="card-note">
        Recommended for most documents: a readable label plus a private token. Use a discreet link
        when the title itself is sensitive.
    </p>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <fieldset class="choice-list">
            <legend>Link type</legend>
            <label class="choice-item">
                <input type="radio" name="share_type" value="labeled" checked>
                <span>
                    <strong>Labeled link <em>Recommended</em></strong>
                    <small>/d/<?= h($doc['slug_id']) ?>/token. The token controls access; stale labels still work.</small>
                </span>
            </label>
            <label class="choice-item">
                <input type="radio" name="share_type" value="discreet">
                <span>
                    <strong>Discreet token link</strong>
                    <small>/s/token only. Best when the title should not appear in the URL.</small>
                </span>
            </label>
        </fieldset>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
