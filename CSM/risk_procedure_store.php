<?php
declare(strict_types=1);

function csm_ra_procedure_db(): PDO
{
    $db = new PDO('sqlite:' . __DIR__ . '/storage/leadership.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS csm_ra_procedure_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER NOT NULL, kind TEXT NOT NULL,
        original_name TEXT NOT NULL, stored_name TEXT NOT NULL UNIQUE, mime TEXT NOT NULL,
        size INTEGER NOT NULL, uploaded_by TEXT NOT NULL, uploaded_at TEXT NOT NULL, deleted_at TEXT
    ); CREATE INDEX IF NOT EXISTS csm_ra_procedure_files_year ON csm_ra_procedure_files(year,kind,deleted_at);
    CREATE TABLE IF NOT EXISTS csm_ra_procedure_assessments (
        year INTEGER PRIMARY KEY, multiplier REAL NOT NULL, note TEXT NOT NULL DEFAULT \'\',
        updated_by TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    return $db;
}

function csm_ra_procedure_kinds(): array
{
    return [
        'procedure' => ['title' => '위험성평가 규정·절차', 'help' => '승인된 위험성평가 규정 또는 절차서와 개정 이력을 첨부합니다.'],
        'report_first' => ['title' => '상반기 점검·조치 보고', 'help' => '상반기 위험성평가 점검·조치 결과와 보고 증빙을 첨부합니다.'],
        'report_second' => ['title' => '하반기 점검·조치 보고', 'help' => '하반기 위험성평가 점검·조치 결과와 보고 증빙을 첨부합니다.'],
        'participation' => ['title' => '근로자 참여 증빙', 'help' => '참여자 명단·서명, 의견청취 기록 또는 참여자가 표시된 평가서를 첨부합니다.'],
    ];
}

function csm_ra_procedure_dir(): string
{
    return __DIR__ . '/storage/ra_procedure';
}

function csm_ra_procedure_files(PDO $db, int $year): array
{
    $query = $db->prepare('SELECT * FROM csm_ra_procedure_files WHERE year=? AND deleted_at IS NULL ORDER BY id DESC');
    $query->execute([$year]);
    $grouped = array_fill_keys(array_keys(csm_ra_procedure_kinds()), []);
    foreach ($query->fetchAll() as $row) {
        if (isset($grouped[$row['kind']])) $grouped[$row['kind']][] = $row;
    }
    return $grouped;
}

function csm_ra_procedure_valid_counts(PDO $db, int $year): array
{
    $counts = array_fill_keys(array_keys(csm_ra_procedure_kinds()), 0);
    foreach (csm_ra_procedure_files($db, $year) as $kind => $files) {
        foreach ($files as $file) {
            if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', (string)$file['stored_name'])
                && is_file(csm_ra_procedure_dir() . '/' . $file['stored_name'])) $counts[$kind]++;
        }
    }
    if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='csm_ra_procedure_document_history'")->fetch()) {
        $query = $db->prepare("SELECT 1 FROM csm_ra_procedure_document_history WHERE year=? AND status='issued' LIMIT 1");
        $query->execute([$year]);
        if ($query->fetch()) $counts['procedure']++;
    }
    return $counts;
}

function csm_ra_procedure_assessment(PDO $db, int $year): ?array
{
    $query = $db->prepare('SELECT multiplier,note,updated_by,updated_at FROM csm_ra_procedure_assessments WHERE year=?');
    $query->execute([$year]);
    return $query->fetch() ?: null;
}

function csm_ra_procedure_summary(PDO $db, int $year): array
{
    $counts = csm_ra_procedure_valid_counts($db, $year);
    $assessment = csm_ra_procedure_assessment($db, $year);
    $evidenceCount = array_sum($counts);
    return ['earned' => $evidenceCount && $assessment ? round(3 * (float)$assessment['multiplier'], 2) : 0.0,
        'evidence_count' => $evidenceCount,
        'state' => $evidenceCount === 0 ? 'no_evidence' : ($assessment ? 'assessed' : 'pending'),
        'multiplier' => $assessment ? (float)$assessment['multiplier'] : null,
        'revision' => 0, 'automatic' => false, 'counts' => $counts];
}

function csm_ra_procedure_upload(PDO $db, int $year, string $kind, array $file, string $editor): void
{
    if (!isset(csm_ra_procedure_kinds()[$kind])) throw new InvalidArgumentException('증빙 항목을 확인해 주세요.');
    $temporary = $file['tmp_name'] ?? '';
    $size = (int)($file['size'] ?? 0);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($temporary)
        || !is_uploaded_file($temporary) || $size < 1 || $size > 15 * 1024 * 1024) {
        throw new InvalidArgumentException('15MB 이하의 파일을 선택해 주세요.');
    }
    $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $allowed = [
        'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
    if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
        throw new InvalidArgumentException('PDF, JPG, PNG, XLSX 또는 DOCX 파일만 첨부할 수 있습니다.');
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true) && @getimagesize($temporary) === false) {
        throw new InvalidArgumentException('이미지 파일을 확인해 주세요.');
    }
    if (in_array($extension, ['xlsx', 'docx'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($temporary) !== true) throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
        $expected = $extension === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($expected) === false) {
            $zip->close();
            throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
        }
        $zip->close();
    }
    $directory = csm_ra_procedure_dir();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    $stored = bin2hex(random_bytes(20)) . '.' . $extension;
    $path = $directory . '/' . $stored;
    if (!move_uploaded_file($temporary, $path)) throw new RuntimeException('증빙 파일을 저장하지 못했습니다.');
    try {
        $query = $db->prepare('INSERT INTO csm_ra_procedure_files(year,kind,original_name,stored_name,mime,size,uploaded_by,uploaded_at) VALUES(?,?,?,?,?,?,?,?)');
        $query->execute([$year, $kind, mb_substr($name, 0, 180), $stored, $mime, $size, $editor, date('Y-m-d H:i:s')]);
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
}

function csm_ra_procedure_delete(PDO $db, int $year, int $id): bool
{
    $query = $db->prepare('UPDATE csm_ra_procedure_files SET deleted_at=? WHERE id=? AND year=? AND deleted_at IS NULL');
    $query->execute([date('Y-m-d H:i:s'), $id, $year]);
    return $query->rowCount() === 1;
}

function csm_ra_procedure_save_assessment(PDO $db, int $year, string $level, string $note, string $editor): void
{
    if (!in_array($level, ['0', '0.5', '0.8', '0.9', '1'], true)) throw new InvalidArgumentException('평가 수준을 선택해 주세요.');
    if (mb_strlen($note, 'UTF-8') > 2000) throw new InvalidArgumentException('검토 메모는 2,000자 이내로 입력해 주세요.');
    if ((float)$level > 0 && array_sum(csm_ra_procedure_valid_counts($db, $year)) === 0) {
        throw new InvalidArgumentException('점수를 반영하려면 먼저 근거 자료를 첨부해 주세요.');
    }
    $query = $db->prepare('INSERT INTO csm_ra_procedure_assessments(year,multiplier,note,updated_by,updated_at) VALUES(?,?,?,?,?)
        ON CONFLICT(year) DO UPDATE SET multiplier=excluded.multiplier,note=excluded.note,updated_by=excluded.updated_by,updated_at=excluded.updated_at');
    $query->execute([$year, (float)$level, trim($note), $editor, date('Y-m-d H:i:s')]);
}
