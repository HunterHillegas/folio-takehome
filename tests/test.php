<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_throws(callable $fn, string $expectedMessage): void {
    try {
        $fn();
    } catch (Throwable $e) {
        assert_true($e->getMessage() === $expectedMessage, 'unexpected exception: ' . $e->getMessage());
        return;
    }

    throw new RuntimeException('expected exception: ' . $expectedMessage);
}

function scalar_query(string $sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function run_command(string $command): void {
    system($command, $rc);
    assert_true($rc === 0, 'command failed: ' . $command);
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('seed applies pending migrations', function () {
    $appliedVersions = array_map(
        'strval',
        db()->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
    );
    $versions = [
        ['20260613000000', 'share index migration'],
        ['20260613001000', 'document ID migration'],
        ['20260614000000', 'schedule migration'],
        ['20260614001000', 'share type migration'],
    ];

    foreach ($versions as [$version, $label]) {
        assert_true(
            in_array($version, $appliedVersions, true),
            'expected ' . $label . ' to be recorded'
        );
    }

    assert_true(index_exists('idx_shares_document_id'), 'expected share document index');
    assert_true(index_exists('idx_documents_readable_id'), 'expected readable ID index');
    assert_true(index_exists('idx_documents_slug_id'), 'expected slug ID index');
    assert_true(column_exists('documents', 'readable_id'), 'expected readable_id column');
    assert_true(column_exists('documents', 'slug_id'), 'expected slug_id column');
    assert_true(column_exists('documents', 'publish_at'), 'expected publish_at column');
    assert_true(column_exists('documents', 'publish_timezone'), 'expected publish_timezone column');
    assert_true(column_exists('shares', 'share_type'), 'expected share_type column');
});

test('documents get readable secure and simple IDs', function () {
    $stmt = db()->query('SELECT readable_id, slug_id FROM documents WHERE title = \'Welcome Packet\'');
    $doc = $stmt->fetch();

    assert_true($doc !== false, 'expected seeded document');
    assert_true($doc['slug_id'] === 'welcome-packet', 'unexpected slug ID: ' . var_export($doc['slug_id'], true));
    assert_true(
        preg_match('/^welcome-packet-[2-9A-HJ-NP-Z]{4}$/', $doc['readable_id']) === 1,
        'unexpected readable ID: ' . var_export($doc['readable_id'], true)
    );
});

test('secure document links require the readable ID and token', function () {
    $stmt = db()->query('
        SELECT d.readable_id, s.token
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $share = $stmt->fetch();

    $doc = shared_document_for_request($share['readable_id'], $share['token']);
    assert_true($doc !== null, 'expected secure readable link to resolve');
    assert_true($doc['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($doc['title'], true));

    $mismatch = shared_document_for_request('wrong-document', $share['token']);
    assert_true($mismatch === null, 'expected mismatched readable ID and token to fail');

    $legacy = shared_document_for_request('', $share['token']);
    assert_true($legacy !== null, 'expected legacy token-only link to resolve');
});

test('simple slug links require a simple share', function () {
    assert_true(
        shared_document_for_request('welcome-packet', '') === null,
        'expected secure-only document to reject slug-only access'
    );

    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email, share_type)
        SELECT id, ?, ?, ?
        FROM documents
        WHERE slug_id = ?
    ');
    $stmt->execute([random_token(), 'simple@example.com', 'simple', 'welcome-packet']);

    $doc = shared_document_for_request('welcome-packet', '');

    assert_true($doc !== null, 'expected slug link with a simple share to resolve');
    assert_true($doc['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($doc['title'], true));
});

test('share URLs show secure and simple choices', function () {
    $stmt = db()->query('
        SELECT d.*, s.token
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $doc = $stmt->fetch();

    assert_true(
        document_share_url($doc, 'secure', $doc['token'], 'http://localhost:8000') ===
            'http://localhost:8000/view.php?id=' . $doc['readable_id'] . '&token=' . $doc['token'],
        'expected secure URL with readable ID and token'
    );
    assert_true(
        document_share_url($doc, 'simple', $doc['token'], 'http://localhost:8000') ===
            'http://localhost:8000/view.php?id=welcome-packet',
        'expected simple URL with slug only'
    );
});

test('scheduled availability parses to UTC and status badges use publish_at', function () {
    $availability = parse_document_availability([
        'availability' => 'scheduled',
        'publish_date' => '2026-07-04',
        'publish_time' => '09:30',
        'publish_timezone' => 'America/New_York',
    ], new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('UTC')));

    assert_true($availability['publish_at'] === '2026-07-04 13:30:00', 'expected UTC publish_at');
    assert_true($availability['publish_timezone'] === 'America/New_York', 'expected selected timezone');
    assert_true(document_status(['publish_at' => null]) === 'Draft', 'expected missing publish_at to be draft');
    assert_true(
        document_status(
            ['publish_at' => '2026-07-04 13:30:00'],
            new DateTimeImmutable('2026-07-04 13:00:00', new DateTimeZone('UTC'))
        ) === 'Scheduled',
        'expected future publish_at to be scheduled'
    );
    assert_true(
        document_status(
            ['publish_at' => '2026-07-04 13:30:00'],
            new DateTimeImmutable('2026-07-04 13:30:00', new DateTimeZone('UTC'))
        ) === 'Available',
        'expected current publish_at to be available'
    );
});

test('scheduled availability rejects nonexistent local times', function () {
    assert_throws(function () {
        parse_document_availability([
            'availability' => 'scheduled',
            'publish_date' => '2027-03-14',
            'publish_time' => '02:30',
            'publish_timezone' => 'America/New_York',
        ], new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
    }, 'Enter a valid schedule date and time.');
});

test('share view hides scheduled documents before publish time', function () {
    $ids = document_ids_for_title('Future Packet');
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, readable_id, slug_id, publish_at, publish_timezone)
        VALUES (?, ?, 1, ?, ?, ?, ?)
    ');
    $stmt->execute([
        'Future Packet',
        'Future-only body',
        $ids['readable_id'],
        $ids['slug_id'],
        '2099-01-01 15:00:00',
        'America/Chicago',
    ]);
    $docId = (int) db()->lastInsertId();
    $token = random_token();

    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email, share_type)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$docId, $token, 'later@example.com', 'secure']);

    $output = render_view_for_token($token);
    assert_true(
        str_contains($output, 'This document is scheduled to become available on'),
        'expected scheduled availability message'
    );
    assert_true(!str_contains($output, 'Future-only body'), 'expected body to be hidden before publish_at');
});

test('migration CLI rolls recent migrations down and back up', function () {
    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' down 2 > /dev/null');
    assert_true(!column_exists('shares', 'share_type'), 'expected share_type column to be removed');
    assert_true(!column_exists('documents', 'publish_at'), 'expected publish_at column to be removed');
    assert_true(index_exists('idx_documents_readable_id'), 'expected readable ID index to remain');
    assert_true(index_exists('idx_documents_slug_id'), 'expected slug ID index to remain');
    assert_true(index_exists('idx_shares_document_id'), 'expected share document index to remain');

    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' up > /dev/null');
    assert_true(column_exists('shares', 'share_type'), 'expected share_type column to be restored');
    assert_true(column_exists('documents', 'publish_at'), 'expected publish_at column to be restored');
    assert_true(index_exists('idx_documents_readable_id'), 'expected readable ID index to be restored');
    assert_true(index_exists('idx_documents_slug_id'), 'expected slug ID index to be restored');
    assert_true(index_exists('idx_shares_document_id'), 'expected share document index to be restored');
});

function index_exists(string $name): bool {
    return scalar_query(
        "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
        [$name]
    ) === $name;
}

function column_exists(string $table, string $column): bool {
    $stmt = db()->query('PRAGMA table_info(' . $table . ')');

    foreach ($stmt->fetchAll() as $row) {
        if ($row['name'] === $column) {
            return true;
        }
    }

    return false;
}

function render_view_for_token(string $token): string {
    $code = '$_GET["token"] = ' . var_export($token, true) . ';'
        . '$_SERVER["HTTP_HOST"] = "localhost:8000";'
        . 'require ' . var_export(__DIR__ . '/../public/view.php', true) . ';';

    return shell_exec('php -r ' . escapeshellarg($code)) ?: '';
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
