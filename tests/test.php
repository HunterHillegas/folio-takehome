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
    $shareIndexVersion = scalar_query(
        'SELECT version FROM schema_migrations WHERE version = ?',
        ['20260613000000']
    );
    $documentIdVersion = scalar_query(
        'SELECT version FROM schema_migrations WHERE version = ?',
        ['20260613001000']
    );

    assert_true($shareIndexVersion === '20260613000000', 'expected share index migration to be recorded');
    assert_true($documentIdVersion === '20260613001000', 'expected document ID migration to be recorded');
    assert_true(index_exists('idx_shares_document_id'), 'expected share document index');
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

test('simple slug links resolve without a token', function () {
    $doc = shared_document_for_request('welcome-packet', '');

    assert_true($doc !== null, 'expected simple slug link to resolve');
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

test('migration CLI rolls down and back up', function () {
    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' down 2 > /dev/null');
    assert_true(!index_exists('idx_documents_readable_id'), 'expected readable ID index to be removed');
    assert_true(!index_exists('idx_documents_slug_id'), 'expected slug ID index to be removed');
    assert_true(!index_exists('idx_shares_document_id'), 'expected share document index to be removed');

    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' up > /dev/null');
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

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
