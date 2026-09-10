<?php
declare(strict_types=1);

function safety_manual_db_init(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_manual_revisions (
        revision_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        revision_key CHAR(36) NOT NULL UNIQUE,
        revision_date DATE NULL,
        revision_count VARCHAR(50) NOT NULL DEFAULT "",
        content_html MEDIUMTEXT NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_manual_revision_date (revision_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function safety_manual_db_revisions(PDO $db): array
{
    return $db->query('SELECT revision_key, revision_date, revision_count, updated_by, updated_at FROM safety_manual_revisions ORDER BY revision_id DESC')->fetchAll();
}

function safety_manual_db_current(PDO $db, string $key): ?array
{
    $stmt = $db->prepare('SELECT revision_key, revision_date, revision_count, content_html, updated_by, updated_at FROM safety_manual_revisions WHERE revision_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function safety_manual_db_save(PDO $db, string $contentHtml, array $coverData, string $editor): string
{
    $key = (string)preg_replace('/[^a-f0-9-]/', '', strtolower(trim((string)($_POST['revision_key'] ?? ''))));
    if ($key === '' || strlen($key) !== 36) $key = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    $stmt = $db->prepare('INSERT INTO safety_manual_revisions (revision_key, revision_date, revision_count, content_html, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$key, (($coverData['revision_date'] ?? '') !== '' ? $coverData['revision_date'] : null), (string)($coverData['revision_count'] ?? ''), $contentHtml, $editor, (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s')]);
    return $key;
}
