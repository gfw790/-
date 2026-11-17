<?php
declare(strict_types=1);

function bonus_evidence_db(): PDO
{
    $directory = __DIR__ . '/storage';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    }
    $db = new PDO('sqlite:' . $directory . '/leadership.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS bonus_evidence (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        item TEXT NOT NULL,
        evidence_kind TEXT NOT NULL DEFAULT \'general\',
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        mime TEXT NOT NULL,
        size INTEGER NOT NULL,
        uploaded_by TEXT NOT NULL,
        uploaded_at TEXT NOT NULL,
        deleted_at TEXT NULL,
        deleted_by TEXT NULL
    ); CREATE INDEX IF NOT EXISTS bonus_evidence_year_item ON bonus_evidence(year,item,deleted_at)');
    $columns = array_column($db->query('PRAGMA table_info(bonus_evidence)')->fetchAll(), 'name');
    if (!in_array('evidence_kind', $columns, true)) {
        $db->exec("ALTER TABLE bonus_evidence ADD COLUMN evidence_kind TEXT NOT NULL DEFAULT 'general'");
    }
    return $db;
}

function bonus_evidence_dir(): string
{
    return __DIR__ . '/storage/bonus_evidence';
}

function bonus_evidence_list(PDO $db, int $year): array
{
    $query = $db->prepare('SELECT id,item,evidence_kind,original_name,mime,size,uploaded_at FROM bonus_evidence WHERE year=? AND deleted_at IS NULL ORDER BY uploaded_at DESC,id DESC');
    $query->execute([$year]);
    $result = [];
    foreach ($query->fetchAll() as $row) $result[$row['item']][] = $row;
    return $result;
}

function bonus_evidence_file(PDO $db, int $id, int $year, string $item): ?array
{
    $query = $db->prepare('SELECT * FROM bonus_evidence WHERE id=? AND year=? AND item=? AND deleted_at IS NULL');
    $query->execute([$id, $year, $item]);
    return $query->fetch() ?: null;
}

function bonus_evidence_upload(PDO $db, int $year, string $item, array $files, string $editor, string $kind = 'general'): int
{
    if ($item === '6.1.2') {
        if (!in_array($kind, ['iso', 'risk_recognition'], true)) throw new InvalidArgumentException('인증서와 위험성평가 인정서 중 증빙 종류를 선택해 주세요.');
    } elseif ($kind !== 'general') {
        throw new InvalidArgumentException('이 항목의 증빙 종류를 확인해 주세요.');
    }
    $names = $files['name'] ?? [];
    if (!is_array($names) || count($names) < 1 || count($names) > 10) throw new InvalidArgumentException('한 번에 1~10개 파일을 선택해 주세요.');
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $prepared = [];
    $sniffer = new finfo(FILEINFO_MIME_TYPE);
    foreach ($names as $index => $name) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('파일 전송에 실패했습니다. 용량과 파일 형식을 확인해 주세요.');
        $temporary = $files['tmp_name'][$index] ?? '';
        $size = (int)($files['size'][$index] ?? 0);
        if (!is_string($name) || !is_string($temporary) || !is_uploaded_file($temporary) || $size < 1 || $size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('파일당 10MB 이하의 정상적인 파일을 선택해 주세요.');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $sniffer->file($temporary);
        if (!isset($types[$extension]) || ($mime !== $types[$extension]
            && !(in_array($extension, ['xlsx', 'docx'], true) && $mime === 'application/zip'))) {
            throw new InvalidArgumentException('PDF, JPG, PNG, XLSX, DOCX 파일만 첨부할 수 있습니다.');
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png'], true) && @getimagesize($temporary) === false) {
            throw new InvalidArgumentException('올바른 이미지 파일을 선택해 주세요.');
        }
        $prepared[] = ['temporary' => $temporary, 'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 180),
            'stored' => bin2hex(random_bytes(20)) . '.' . $extension, 'mime' => $types[$extension], 'size' => $size];
    }
    if (array_sum(array_column($prepared, 'size')) > 30 * 1024 * 1024) {
        throw new InvalidArgumentException('한 번에 첨부하는 파일의 합계는 30MB 이하여야 합니다.');
    }
    $directory = bonus_evidence_dir();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    $moved = [];
    $db->beginTransaction();
    try {
        $query = $db->prepare('INSERT INTO bonus_evidence(year,item,evidence_kind,original_name,stored_name,mime,size,uploaded_by,uploaded_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        foreach ($prepared as $file) {
            $path = $directory . '/' . $file['stored'];
            if (!move_uploaded_file($file['temporary'], $path)) throw new RuntimeException('증빙 파일을 저장할 수 없습니다.');
            $moved[] = $path;
            $query->execute([$year, $item, $kind, $file['name'], $file['stored'], $file['mime'], $file['size'], $editor, $now]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($moved as $path) @unlink($path);
        throw $error;
    }
    return count($prepared);
}

function bonus_evidence_delete(PDO $db, int $id, int $year, string $item, string $editor): bool
{
    $query = $db->prepare('UPDATE bonus_evidence SET deleted_at=?,deleted_by=? WHERE id=? AND year=? AND item=? AND deleted_at IS NULL');
    $query->execute([(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s'), $editor, $id, $year, $item]);
    return $query->rowCount() === 1;
}
