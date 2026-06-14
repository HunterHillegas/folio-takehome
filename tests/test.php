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
    $scheduleVersion = scalar_query(
        'SELECT version FROM schema_migrations WHERE version = ?',
        ['20260614000000']
    );

    assert_true($version === '20260613000000', 'expected migration version to be recorded');
    assert_true($scheduleVersion === '20260614000000', 'expected schedule migration version to be recorded');
    assert_true(index_exists('idx_shares_document_id'), 'expected share document index');
    assert_true(column_exists('documents', 'publish_at'), 'expected document publish_at column');
    assert_true(column_exists('documents', 'publish_timezone'), 'expected document publish_timezone column');
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

test('share view hides scheduled documents before publish time', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, publish_timezone)
        VALUES (?, ?, 1, ?, ?)
    ');
    $stmt->execute([
        'Future Packet',
        'Future-only body',
        '2099-01-01 15:00:00',
        'America/Chicago',
    ]);
    $docId = (int) db()->lastInsertId();
    $token = random_token();

    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$docId, $token, 'later@example.com']);

    $output = render_view_for_token($token);
    assert_true(
        str_contains($output, 'This document is scheduled to become available on'),
        'expected scheduled availability message'
    );
    assert_true(!str_contains($output, 'Future-only body'), 'expected body to be hidden before publish_at');
});

test('migration CLI rolls latest down and back up', function () {
    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' down > /dev/null');
    assert_true(!column_exists('documents', 'publish_at'), 'expected document publish_at column to be removed');
    assert_true(index_exists('idx_shares_document_id'), 'expected earlier share document index to remain');

    run_command('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' up > /dev/null');
    assert_true(column_exists('documents', 'publish_at'), 'expected document publish_at column to be restored');
    assert_true(index_exists('idx_shares_document_id'), 'expected share document index to remain');
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
