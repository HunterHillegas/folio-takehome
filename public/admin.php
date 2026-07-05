<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;
$form = [
    'title' => '',
    'body' => '',
    'visible_from' => '',
    'publish_timezone' => DEFAULT_PUBLISH_TIMEZONE,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_document';

    if ($action === 'make_visible_now') {
        $docId = (int) ($_POST['doc'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if ($doc) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $stmt = db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?');
            $stmt->execute([format_sql_datetime($now), $docId]);
            audit_log('make_visible_now', 'document', $docId, [
                'previous_publish_at' => $doc['publish_at'],
                'publish_at' => format_sql_datetime($now),
            ]);

            header('Location: /admin.php?made_visible=' . $docId);
            exit;
        }

        $error = 'Document not found.';
    }

    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $form = [
        'title' => $title,
        'body' => $body,
        'visible_from' => $_POST['visible_from'] ?? '',
        'publish_timezone' => $_POST['publish_timezone'] ?? DEFAULT_PUBLISH_TIMEZONE,
    ];

    if ($action !== 'create_document') {
        // Non-create actions either redirect above or set their own error.
    } elseif ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        try {
            $availability = parse_document_availability($form);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }

    if ($action === 'create_document' && $error === null) {
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

        $query = ['created' => $docId];
        if ($availability['notice']) {
            $query['visible_now'] = '1';
        }

        header('Location: /admin.php?' . http_build_query($query));
        exit;
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

render_header('Admin', $staff, ['/assets/admin.css']);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document created.</div>
<?php endif ?>

<?php if (!empty($_GET['visible_now'])): ?>
    <div class="banner banner-warn">Past visible-from time saved as visible now.</div>
<?php endif ?>

<?php if (!empty($_GET['made_visible'])): ?>
    <div class="banner banner-success">Document is visible now.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <input type="hidden" name="action" value="create_document">
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
            <div class="form-field">
                <label for="visible_from">Visible from</label>
                <input
                    type="datetime-local"
                    id="visible_from"
                    name="visible_from"
                    value="<?= h($form['visible_from']) ?>"
                    aria-describedby="visible_from_summary"
                >
                <p class="field-help" id="visible_from_summary">Blank means visible now.</p>
            </div>
            <div class="preset-actions" aria-label="Visible from presets">
                <button type="button" class="btn-quiet" data-visible-preset="tomorrow">Tomorrow 9 AM</button>
                <button type="button" class="btn-quiet" data-visible-preset="next-monday">Next Monday 9 AM</button>
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
        <div class="form-field table-filter">
            <label for="documents_filter">Find document to share</label>
            <input
                type="text"
                id="documents_filter"
                name="documents_filter"
                autocomplete="off"
                placeholder="Type title words, like welcome packet"
            >
            <p class="field-help">Order-independent word-prefix filter. Press Enter to copy the highlighted row.</p>
        </div>
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
                    <?php
                    $status = document_status($d);
                    $search = strtolower($d['title'] . ' ' . $d['slug_id']);
                    ?>
                    <tr
                        data-document-row
                        data-doc-id="<?= (int) $d['id'] ?>"
                        data-title="<?= h($d['title']) ?>"
                        data-search="<?= h($search) ?>"
                    >
                        <td class="id"><?= h($d['readable_id']) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td>
                            <?php if ($status === 'Scheduled'): ?>
                                <span class="badge badge-scheduled">Visible <?= h(format_document_visible_at($d)) ?></span>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="make_visible_now">
                                    <input type="hidden" name="doc" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" class="btn-link">Make visible now</button>
                                </form>
                            <?php else: ?>
                                <span class="badge badge-<?= h(strtolower($status)) ?>"><?= h($status) ?></span>
                            <?php endif ?>
                        </td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td class="row-actions">
                            <button type="button" class="btn-link" data-copy-share>Copy link</button>
                            <a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Share options</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        <p class="empty" id="documents_empty" hidden>No matching documents.</p>
    <?php endif ?>
</section>

<div class="toast" id="copy_toast" hidden></div>

<script>
(() => {
    const visibleFrom = document.querySelector('#visible_from');
    const visibleSummary = document.querySelector('#visible_from_summary');
    const timezone = document.querySelector('#publish_timezone');

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function inputValueFor(date) {
        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate())
        ].join('-') + 'T' + [pad(date.getHours()), pad(date.getMinutes())].join(':');
    }

    function setNineAm(date) {
        date.setHours(9, 0, 0, 0);
        return date;
    }

    function updateVisibleSummary() {
        if (!visibleFrom || !visibleSummary) return;
        if (!visibleFrom.value) {
            visibleSummary.textContent = 'Blank means visible now.';
            return;
        }

        const selected = new Date(visibleFrom.value);
        const deltaMs = selected.getTime() - Date.now();
        const days = Math.ceil(deltaMs / 86400000);
        const label = selected.toLocaleString(undefined, {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });

        if (deltaMs <= 0) {
            visibleSummary.textContent = 'Past times publish immediately.';
        } else {
            visibleSummary.textContent = `Visible ${label} in ${days} day${days === 1 ? '' : 's'} using ${timezone?.selectedOptions[0]?.textContent ?? 'selected time zone'}.`;
        }
    }

    document.querySelectorAll('[data-visible-preset]').forEach((button) => {
        button.addEventListener('click', () => {
            const date = new Date();
            if (button.dataset.visiblePreset === 'tomorrow') {
                date.setDate(date.getDate() + 1);
            } else {
                const day = date.getDay();
                const daysUntilMonday = (8 - day) % 7 || 7;
                date.setDate(date.getDate() + daysUntilMonday);
            }
            visibleFrom.value = inputValueFor(setNineAm(date));
            updateVisibleSummary();
            visibleFrom.focus();
        });
    });

    visibleFrom?.addEventListener('input', updateVisibleSummary);
    timezone?.addEventListener('change', updateVisibleSummary);
    updateVisibleSummary();

    const filter = document.querySelector('#documents_filter');
    const rows = Array.from(document.querySelectorAll('[data-document-row]'));
    const empty = document.querySelector('#documents_empty');
    const toast = document.querySelector('#copy_toast');
    let activeIndex = 0;

    function rowMatches(row, terms) {
        const words = row.dataset.search.split(/\s+/).filter(Boolean);
        return terms.every((term) => words.some((word) => word.startsWith(term)));
    }

    function visibleRows() {
        return rows.filter((row) => !row.hidden);
    }

    function paintActive() {
        const visible = visibleRows();
        rows.forEach((row) => row.classList.remove('is-active'));
        if (visible.length === 0) return;
        activeIndex = Math.max(0, Math.min(activeIndex, visible.length - 1));
        visible[activeIndex].classList.add('is-active');
    }

    function applyFilter() {
        const terms = (filter?.value ?? '').toLowerCase().trim().split(/\s+/).filter(Boolean);
        rows.forEach((row) => {
            row.hidden = terms.length > 0 && !rowMatches(row, terms);
        });
        activeIndex = 0;
        empty.hidden = visibleRows().length !== 0;
        paintActive();
    }

    function showToast(message, link) {
        if (!toast) return;
        toast.innerHTML = link ? `${message} <code>${link}</code>` : message;
        toast.hidden = false;
        window.setTimeout(() => { toast.hidden = true; }, 5000);
    }

    async function copyShare(row, button) {
        const title = row.dataset.title;
        button.disabled = true;
        button.textContent = 'Copying...';

        try {
            const form = new FormData();
            form.append('doc_id', row.dataset.docId);
            const response = await fetch('/share_link.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: form
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Could not create link.');

            await navigator.clipboard.writeText(payload.url);
            showToast(`Copied "${title}" link.`);
        } catch (error) {
            showToast('Link ready. Copy manually:', error.message.startsWith('http') ? error.message : '');
            console.error(error);
        } finally {
            button.disabled = false;
            button.textContent = 'Copy link';
        }
    }

    rows.forEach((row) => {
        row.querySelector('[data-copy-share]')?.addEventListener('click', (event) => {
            copyShare(row, event.currentTarget);
        });
    });

    filter?.addEventListener('input', applyFilter);
    filter?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex += 1;
            paintActive();
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex -= 1;
            paintActive();
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            const row = visibleRows()[activeIndex];
            row?.querySelector('[data-copy-share]')?.click();
        }
    });
    applyFilter();
})();
</script>

<?php render_footer(); ?>
