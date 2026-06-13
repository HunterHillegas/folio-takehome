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
    $version = scalar_query(
        'SELECT version FROM schema_migrations WHERE version = ?',
        ['20260613000000']
    );

    assert_true($version === '20260613000000', 'expected migration version to be recorded');
    assert_true(index_exists('idx_shares_document_id'), 'expected share document index');
});

test('migration CLI rolls down and back up', function () {
    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' down > /dev/null');
    assert_true(!index_exists('idx_shares_document_id'), 'expected share document index to be removed');

    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' up > /dev/null');
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
