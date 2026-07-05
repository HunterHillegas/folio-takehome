<?php

date_default_timezone_set('America/Chicago');

const DEFAULT_PUBLISH_TIMEZONE = 'America/Chicago';
const DIRECT_SHARE_RECIPIENT = 'quick-share@folio.local';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function document_slug_base(string $title): string {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'document';
}

function readable_code(int $length = 4): string {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function next_unique_document_id(string $column, string $base, ?string $suffix = null): string {
    $candidate = $suffix ? "{$base}-{$suffix}" : $base;
    $counter = 2;

    while (document_id_exists($column, $candidate)) {
        if ($suffix) {
            $candidate = "{$base}-" . readable_code();
        } else {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }
    }

    return $candidate;
}

function document_id_exists(string $column, string $value): bool {
    if (!in_array($column, ['readable_id', 'slug_id'], true)) {
        throw new InvalidArgumentException('Unsupported document ID column.');
    }

    $stmt = db()->prepare("SELECT 1 FROM documents WHERE {$column} = ? LIMIT 1");
    $stmt->execute([$value]);

    return $stmt->fetchColumn() !== false;
}

function document_ids_for_title(string $title): array {
    $base = document_slug_base($title);

    return [
        'readable_id' => next_unique_document_id('readable_id', $base, readable_code()),
        'slug_id' => $base,
    ];
}

function document_share_url(array $doc, string $shareType, string $token, string $baseUrl): string {
    $baseUrl = rtrim($baseUrl, '/');

    if ($shareType === 'discreet') {
        return $baseUrl . '/s/' . rawurlencode($token);
    }

    return $baseUrl . '/d/' . rawurlencode($doc['slug_id']) . '/' . rawurlencode($token);
}

function create_document_share(array $doc, string $recipientEmail, string $shareType): array {
    if (!in_array($shareType, ['labeled', 'discreet'], true)) {
        throw new InvalidArgumentException('Choose a link type.');
    }

    $token = random_token();
    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email, share_type)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$doc['id'], $token, $recipientEmail, $shareType]);
    $shareId = (int) db()->lastInsertId();

    audit_log('create', 'share', $shareId, [
        'document_id' => $doc['id'],
        'recipient_email' => $recipientEmail,
        'share_type' => $shareType,
    ]);

    return [
        'id' => $shareId,
        'token' => $token,
        'url' => document_share_url($doc, $shareType, $token, request_base_url()),
    ];
}

function request_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

    return "{$scheme}://{$host}";
}

function shared_document_for_request(string $documentId, string $token): ?array {
    $documentId = trim($documentId);
    $token = trim($token);

    if ($token !== '') {
        $stmt = db()->prepare('
            SELECT d.*, s.recipient_email
            FROM shares s
            JOIN documents d ON d.id = s.document_id
            WHERE s.token = ?
        ');
        $stmt->execute([$token]);
        $doc = $stmt->fetch();

        if (!$doc) {
            return null;
        }

        return $doc;
    }

    return null;
}

function available_timezones(): array {
    return [
        'America/Los_Angeles' => 'Pacific Time',
        'America/Denver' => 'Mountain Time',
        'America/Chicago' => 'Central Time',
        'America/New_York' => 'Eastern Time',
        'UTC' => 'UTC',
    ];
}

function parse_document_availability(array $input, ?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $timezone = normalize_publish_timezone($input['publish_timezone'] ?? DEFAULT_PUBLISH_TIMEZONE);
    $visibleFrom = trim($input['visible_from'] ?? '');

    if ($visibleFrom === '' && isset($input['publish_date'], $input['publish_time'])) {
        $date = trim($input['publish_date'] ?? '');
        $time = trim($input['publish_time'] ?? '');
        $visibleFrom = $date !== '' || $time !== '' ? "{$date}T{$time}" : '';
    }

    if ($visibleFrom === '') {
        return [
            'publish_at' => format_sql_datetime($now),
            'publish_timezone' => $timezone,
            'notice' => null,
        ];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $visibleFrom)) {
        throw new InvalidArgumentException('Enter a valid schedule date and time.');
    }

    $localTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $visibleFrom,
        new DateTimeZone($timezone)
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$localTime
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $localTime->format('Y-m-d\TH:i') !== $visibleFrom
    ) {
        throw new InvalidArgumentException('Enter a valid schedule date and time.');
    }

    $publishAt = $localTime->setTimezone(new DateTimeZone('UTC'));
    if ($publishAt <= $now) {
        return [
            'publish_at' => format_sql_datetime($now),
            'publish_timezone' => $timezone,
            'notice' => 'Past visible-from times publish immediately.',
        ];
    }

    return [
        'publish_at' => format_sql_datetime($publishAt),
        'publish_timezone' => $timezone,
        'notice' => null,
    ];
}

function document_status(array $document, ?DateTimeImmutable $now = null): string {
    if (empty($document['publish_at'])) {
        return 'Draft';
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return document_publish_datetime($document) > $now ? 'Scheduled' : 'Available';
}

function document_is_available(array $document, ?DateTimeImmutable $now = null): bool {
    return document_status($document, $now) === 'Available';
}

function format_document_publish_at(array $document): string {
    $timezone = $document['publish_timezone'] ?: DEFAULT_PUBLISH_TIMEZONE;

    return document_publish_datetime($document)
        ->setTimezone(new DateTimeZone($timezone))
        ->format('F j, Y \a\t g:i A T');
}

function format_document_visible_at(array $document): string {
    $timezone = $document['publish_timezone'] ?: DEFAULT_PUBLISH_TIMEZONE;

    return document_publish_datetime($document)
        ->setTimezone(new DateTimeZone($timezone))
        ->format('M j, g:i A T');
}

function document_publish_iso8601(array $document): string {
    return document_publish_datetime($document)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format(DateTimeInterface::ATOM);
}

function format_sql_datetime(DateTimeImmutable $datetime): string {
    return $datetime
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function normalize_publish_timezone(string $timezone): string {
    if (!array_key_exists($timezone, available_timezones())) {
        throw new InvalidArgumentException('Choose a valid time zone.');
    }

    return $timezone;
}

function document_publish_datetime(array $document): DateTimeImmutable {
    return DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $document['publish_at'],
        new DateTimeZone('UTC')
    ) ?: new DateTimeImmutable($document['publish_at'], new DateTimeZone('UTC'));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
