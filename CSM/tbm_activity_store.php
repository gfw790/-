<?php
declare(strict_types=1);

function csm_tbm_db(): PDO
{
    $db = new PDO('sqlite:' . __DIR__ . '/storage/leadership.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS csm_tbm_evidence (
        id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER NOT NULL, month INTEGER NOT NULL DEFAULT 0,
        original_name TEXT NOT NULL, stored_name TEXT NOT NULL UNIQUE,
        mime TEXT NOT NULL, size INTEGER NOT NULL, uploaded_by TEXT NOT NULL,
        uploaded_at TEXT NOT NULL, deleted_at TEXT NULL
    ); CREATE INDEX IF NOT EXISTS csm_tbm_evidence_year ON csm_tbm_evidence(year,deleted_at)');
    $columns = array_column($db->query('PRAGMA table_info(csm_tbm_evidence)')->fetchAll(), 'name');
    if (!in_array('month', $columns, true)) {
        $db->exec('ALTER TABLE csm_tbm_evidence ADD COLUMN month INTEGER NOT NULL DEFAULT 0');
    }
    return $db;
}

function csm_tbm_evidence_dir(): string
{
    return __DIR__ . '/storage/tbm_evidence';
}

function csm_tbm_evidence(PDO $db, int $year, int $month = 0): array
{
    $query = $db->prepare('SELECT id,month,original_name,stored_name,mime,size,uploaded_at FROM csm_tbm_evidence WHERE year=? AND deleted_at IS NULL' . ($month ? ' AND month=?' : '') . ' ORDER BY month DESC,id DESC');
    $query->execute($month ? [$year, $month] : [$year]);
    return $query->fetchAll();
}

function csm_tbm_month_counts(PDO $db, int $year): array
{
    $counts = array_fill(1, 12, 0);
    $query = $db->prepare('SELECT month,COUNT(*) AS file_count FROM csm_tbm_evidence WHERE year=? AND deleted_at IS NULL GROUP BY month');
    $query->execute([$year]);
    foreach ($query->fetchAll() as $row) {
        $month = (int)$row['month'];
        if ($month >= 1 && $month <= 12) $counts[$month] = (int)$row['file_count'];
    }
    return $counts;
}

function csm_tbm_monthly_summary(PDO $db, int $year, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $currentYear = (int)$asOf->format('Y');
    $cutoff = $year < $currentYear ? 12 : ($year === $currentYear ? (int)$asOf->format('n') : 0);
    $counts = array_fill(1, 12, 0);
    $query = $db->prepare('SELECT month,stored_name FROM csm_tbm_evidence WHERE year=? AND deleted_at IS NULL');
    $query->execute([$year]);
    foreach ($query->fetchAll() as $file) {
        $month = (int)$file['month'];
        $stored = (string)$file['stored_name'];
        if ($month >= 1 && $month <= 12
            && preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png)$/D', $stored)
            && is_file(csm_tbm_evidence_dir() . '/' . $stored)) $counts[$month]++;
    }
    $months = [];
    $covered = $total = $evidenceCount = 0;
    for ($month = 1; $month <= 12; $month++) {
        $earned = $month <= $cutoff && $counts[$month] > 0 ? 2.0 : 0.0;
        $months[$month] = ['earned' => $earned, 'evidence_count' => $counts[$month],
            'state' => $month > $cutoff ? 'future' : ($counts[$month] === 0 ? 'no_evidence' : 'assessed')];
        if ($month <= $cutoff) {
            $total += $earned;
            $evidenceCount += $counts[$month];
            if ($counts[$month] > 0) $covered++;
        }
    }
    return ['months' => $months, 'cutoff' => $cutoff, 'covered_months' => $covered,
        'evidence_count' => $evidenceCount, 'total' => round($total, 4),
        'earned' => $cutoff > 0 ? round($total / $cutoff, 4) : 0.0,
        'multiplier' => $cutoff > 0 ? round($covered / $cutoff, 4) : 0.0,
        'state' => $evidenceCount === 0 ? 'no_evidence' : 'assessed'];
}

function csm_tbm_upload(PDO $db, int $year, int $month, array $file, string $editor): void
{
    if ($month < 1 || $month > 12) throw new InvalidArgumentException('업로드할 월을 선택해 주세요.');
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    $temporary = $file['tmp_name'] ?? '';
    $size = (int)($file['size'] ?? 0);
    if ($error !== UPLOAD_ERR_OK || !is_string($temporary) || !is_uploaded_file($temporary) || $size < 1 || $size > 15 * 1024 * 1024) {
        throw new InvalidArgumentException('15MB 이하의 증빙 파일을 선택해 주세요.');
    }
    $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    if (!isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
        throw new InvalidArgumentException('스캔본은 PDF, JPG, PNG 파일로 첨부해 주세요.');
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true) && @getimagesize($temporary) === false) {
        throw new InvalidArgumentException('이미지 파일을 확인해 주세요.');
    }
    $directory = csm_tbm_evidence_dir();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    $stored = bin2hex(random_bytes(20)) . '.' . $extension;
    $path = $directory . '/' . $stored;
    if (!move_uploaded_file($temporary, $path)) throw new RuntimeException('증빙 파일을 저장하지 못했습니다.');
    try {
        $query = $db->prepare('INSERT INTO csm_tbm_evidence(year,month,original_name,stored_name,mime,size,uploaded_by,uploaded_at) VALUES(?,?,?,?,?,?,?,?)');
        $query->execute([$year, $month, mb_substr($name, 0, 180), $stored, $allowed[$extension], $size, $editor, date('Y-m-d H:i:s')]);
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
}

function csm_tbm_delete(PDO $db, int $year, int $id): bool
{
    $query = $db->prepare('UPDATE csm_tbm_evidence SET deleted_at=? WHERE id=? AND year=? AND deleted_at IS NULL');
    $query->execute([date('Y-m-d H:i:s'), $id, $year]);
    return $query->rowCount() === 1;
}
