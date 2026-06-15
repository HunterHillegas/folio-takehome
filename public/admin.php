<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;
$form = [
    'title' => '',
    'body' => '',
    'availability' => 'immediate',
    'publish_date' => '',
    'publish_time' => '',
    'publish_timezone' => DEFAULT_PUBLISH_TIMEZONE,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $form = [
        'title' => $title,
        'body' => $body,
        'availability' => $_POST['availability'] ?? 'immediate',
        'publish_date' => $_POST['publish_date'] ?? '',
        'publish_time' => $_POST['publish_time'] ?? '',
        'publish_timezone' => $_POST['publish_timezone'] ?? DEFAULT_PUBLISH_TIMEZONE,
    ];

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        try {
            $availability = parse_document_availability($form);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }

    if ($error === null) {
        $ids = document_ids_for_title($title);
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, readable_id, slug_id, publish_at, publish_timezone)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $title,
            $body,
            $staff['id'],
            $ids['readable_id'],
            $ids['slug_id'],
            $availability['publish_at'],
            $availability['publish_timezone'],
        ]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title' => $title,
            'readable_id' => $ids['readable_id'],
            'slug_id' => $ids['slug_id'],
            'publish_at' => $availability['publish_at'],
            'publish_timezone' => $availability['publish_timezone'],
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= h($form['title']) ?>" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required><?= h($form['body']) ?></textarea>
        </div>

        <fieldset class="form-section">
            <legend>Availability</legend>
            <label class="radio-option">
                <input
                    type="radio"
                    name="availability"
                    value="immediate"
                    <?= $form['availability'] === 'immediate' ? 'checked' : '' ?>
                >
                Available immediately
            </label>
            <label class="radio-option">
                <input
                    type="radio"
                    name="availability"
                    value="scheduled"
                    <?= $form['availability'] === 'scheduled' ? 'checked' : '' ?>
                >
                Schedule for later
            </label>
            <div class="form-grid">
                <div class="form-field">
                    <label for="publish_date">Date</label>
                    <input type="date" id="publish_date" name="publish_date" value="<?= h($form['publish_date']) ?>">
                </div>
                <div class="form-field">
                    <label for="publish_time">Time</label>
                    <input type="time" id="publish_time" name="publish_time" value="<?= h($form['publish_time']) ?>">
                </div>
            </div>
            <div class="form-field">
                <label for="publish_timezone">Time zone</label>
                <select id="publish_timezone" name="publish_timezone">
                    <?php foreach (available_timezones() as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $form['publish_timezone'] === $value ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn">Create document</button>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>Document ID</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id"><?= h($d['readable_id']) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <?php $status = document_status($d); ?>
                        <td><span class="badge badge-<?= h(strtolower($status)) ?>"><?= h($status) ?></span></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
