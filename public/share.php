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
    $shareType = $_POST['share_type'] ?? 'secure';

    if ($email === '') {
        $error = 'Recipient email is required.';
    } elseif (!in_array($shareType, ['secure', 'simple'], true)) {
        $error = 'Choose a link type.';
    } else {
        $token = random_token();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$doc['id'], $token, $email]);
        $shareId = (int) db()->lastInsertId();
        audit_log('create', 'share', $shareId, [
            'document_id' => $doc['id'],
            'recipient_email' => $email,
            'share_type' => $shareType,
        ]);
        $created_url = document_share_url($doc, $shareType, $token, request_base_url());
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
        <?= $created_share_type === 'simple' ? 'Simple link ready:' : 'Secure link ready:' ?>
        <code><?= h($created_url) ?></code>
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <p class="card-note">
        Recommended for sensitive documents: a readable ID plus a private token. Use the simple slug
        when being easy to say or type matters more than keeping the link hard to guess.
    </p>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <fieldset class="choice-list">
            <legend>Link type</legend>
            <label class="choice-item">
                <input type="radio" name="share_type" value="secure" checked>
                <span>
                    <strong>Secure readable link <em>Recommended</em></strong>
                    <small><?= h($doc['readable_id']) ?> plus a private token. Best for HR, finance, legal, or anything sensitive.</small>
                </span>
            </label>
            <label class="choice-item">
                <input type="radio" name="share_type" value="simple">
                <span>
                    <strong>Simple slug link</strong>
                    <small><?= h($doc['slug_id']) ?> only. Easier to share aloud or paste in notes; anyone with the slug can open it.</small>
                </span>
            </label>
        </fieldset>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
