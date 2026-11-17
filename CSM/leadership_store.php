<?php
declare(strict_types=1);

function leadership_allowed(array $user): bool
{
    return auth_can_manage($user) && (string)($user['login_id'] ?? '') === '5878';
}

function leadership_db(): PDO
{
    $directory = __DIR__ . '/storage';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create leadership storage');
    }
    $db = new PDO('sqlite:' . $directory . '/leadership.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS leadership_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, item TEXT NOT NULL, year INTEGER NOT NULL,
        activity_date TEXT NOT NULL, title TEXT NOT NULL, payload TEXT NOT NULL,
        revision INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL, updated_at TEXT NOT NULL
    ); CREATE INDEX IF NOT EXISTS leadership_year_item ON leadership_records(year, item);
    CREATE TABLE IF NOT EXISTS leadership_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INTEGER NOT NULL REFERENCES leadership_records(id),
        original_name TEXT NOT NULL, stored_name TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL
    ); CREATE TABLE IF NOT EXISTS leadership_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INTEGER NOT NULL REFERENCES leadership_records(id),
        revision INTEGER NOT NULL, snapshot TEXT NOT NULL, editor TEXT NOT NULL, saved_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS leadership_assessments (
        year INTEGER NOT NULL, item TEXT NOT NULL, multiplier REAL NOT NULL,
        revision INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL, updated_at TEXT NOT NULL,
        PRIMARY KEY(year,item)
    ); CREATE TABLE IF NOT EXISTS leadership_assessment_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER NOT NULL, item TEXT NOT NULL,
        multiplier REAL NOT NULL, editor TEXT NOT NULL, saved_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS leadership_monthly_assessments (
        year INTEGER NOT NULL, item TEXT NOT NULL, month INTEGER NOT NULL CHECK(month BETWEEN 1 AND 12),
        multiplier REAL NOT NULL, revision INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL, updated_at TEXT NOT NULL,
        PRIMARY KEY(year,item,month)
    ); CREATE TABLE IF NOT EXISTS leadership_monthly_assessment_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER NOT NULL, item TEXT NOT NULL, month INTEGER NOT NULL,
        multiplier REAL NOT NULL, editor TEXT NOT NULL, saved_at TEXT NOT NULL
    )');
    $columns = array_column($db->query('PRAGMA table_info(leadership_files)')->fetchAll(), 'name');
    $fileColumns = ['deleted_at' => 'TEXT NULL', 'deleted_by' => 'TEXT NULL', 'photo_slot' => 'INTEGER NULL', 'caption' => "TEXT NOT NULL DEFAULT ''", 'file_kind' => "TEXT NOT NULL DEFAULT 'evidence'"];
    if (array_diff(array_keys($fileColumns), $columns)) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column($db->query('PRAGMA table_info(leadership_files)')->fetchAll(), 'name');
            foreach ($fileColumns as $column => $type) {
                if (!in_array($column, $columns, true)) $db->exec('ALTER TABLE leadership_files ADD COLUMN ' . $column . ' ' . $type);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    }
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS leadership_active_photo_slot ON leadership_files(record_id,photo_slot) WHERE photo_slot IS NOT NULL AND deleted_at IS NULL');
    return $db;
}

function leadership_save_assessment(PDO $db, array $catalog, int $year, string $item, mixed $value, int $revision, string $editor, ?int $month = null): void
{
    if ($item === '1.1.4') throw new InvalidArgumentException('대표자 협의회는 참석 기록과 증빙으로 자동 산정합니다. 별도의 평가 저장은 필요하지 않습니다.');
    require_once __DIR__ . '/scoring.php';
    $criterion = null;
    foreach ($catalog['modules'][0]['items'] as $entry) if ($entry['id'] === $item) $criterion = $entry;
    if (!$criterion || !is_string($value) || !is_numeric($value)) throw new InvalidArgumentException('평가 수준을 선택해 주세요.');
    $multiplier = (float)$value;
    $allowed = [1.0];
    foreach ($criterion['criteria'] as $level => $label) if (is_numeric($level) && $label !== '') $allowed[] = (float)$level;
    if (!in_array($multiplier, $allowed, true)) throw new InvalidArgumentException('원본 평가 기준에 있는 수준을 선택해 주세요.');
    if ($item === '1.1.3') {
        $cutoff = csm_vfl_monthly_summary(null, $year)['cutoff'];
        if ($month === null || $month < 1 || $month > $cutoff) throw new InvalidArgumentException('현재 월까지의 평가 대상 월을 선택해 주세요.');
    } elseif ($month !== null) throw new InvalidArgumentException('월별 평가는 VFL 항목에만 적용됩니다.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $counts = csm_scoring_evidence($db, $year, $month, $item === '1.1.3');
        if ($item === '1.1.3' && ($counts[$item] ?? 0) === 0) throw new InvalidArgumentException('해당 월의 서명본 증빙을 먼저 첨부해 주세요. 서명본이 있어야 평가수준을 저장할 수 있습니다.');
        if (($counts[$item] ?? 0) === 0 && $multiplier > 0) throw new InvalidArgumentException('증빙이 첨부되지 않은 항목에는 점수를 부여할 수 없습니다. 증빙을 먼저 첨부해 주세요.');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        if ($month !== null) {
            if ($revision === 0) {
                $query = $db->prepare('INSERT OR IGNORE INTO leadership_monthly_assessments(year,item,month,multiplier,updated_by,updated_at) VALUES(?,?,?,?,?,?)');
                $query->execute([$year, $item, $month, $multiplier, $editor, $now]);
            } else {
                $query = $db->prepare('UPDATE leadership_monthly_assessments SET multiplier=?,revision=revision+1,updated_by=?,updated_at=? WHERE year=? AND item=? AND month=? AND revision=?');
                $query->execute([$multiplier, $editor, $now, $year, $item, $month, $revision]);
            }
        } elseif ($revision === 0) {
            $query = $db->prepare('INSERT OR IGNORE INTO leadership_assessments(year,item,multiplier,updated_by,updated_at) VALUES(?,?,?,?,?)');
            $query->execute([$year, $item, $multiplier, $editor, $now]);
        } else {
            $query = $db->prepare('UPDATE leadership_assessments SET multiplier=?,revision=revision+1,updated_by=?,updated_at=? WHERE year=? AND item=? AND revision=?');
            $query->execute([$multiplier, $editor, $now, $year, $item, $revision]);
        }
        if ($query->rowCount() !== 1) throw new InvalidArgumentException('평가가 변경되었습니다. 새로고침 후 다시 확인해 주세요.');
        if ($month !== null) {
            $query = $db->prepare('INSERT INTO leadership_monthly_assessment_history(year,item,month,multiplier,editor,saved_at) VALUES(?,?,?,?,?,?)');
            $query->execute([$year, $item, $month, $multiplier, $editor, $now]);
        } else {
            $query = $db->prepare('INSERT INTO leadership_assessment_history(year,item,multiplier,editor,saved_at) VALUES(?,?,?,?,?)');
            $query->execute([$year, $item, $multiplier, $editor, $now]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function leadership_change_file(PDO $db, int $fileId, int $recordId, int $revision, string $item, int $year, string $action, string $editor): void
{
    if (!in_array($action, ['delete_file', 'restore_file'], true) || $fileId <= 0 || $recordId <= 0) throw new InvalidArgumentException('첨부파일 요청을 확인해 주세요.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $statement = $db->prepare('SELECT f.*, r.item, r.year, r.activity_date, r.title, r.payload, r.revision FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE f.id=? AND r.id=? AND r.item=? AND r.year=?');
        $statement->execute([$fileId, $recordId, $item, $year]);
        $file = $statement->fetch();
        if (!$file) throw new InvalidArgumentException('해당 기록의 첨부파일을 찾을 수 없습니다.');
        if ((int)$file['revision'] !== $revision) throw new InvalidArgumentException('기록이 변경되었습니다. 새로고침 후 다시 시도해 주세요.');
        $deleting = $action === 'delete_file';
        if (($file['deleted_at'] !== null) === $deleting) throw new InvalidArgumentException($deleting ? '이미 삭제된 첨부파일입니다.' : '삭제된 첨부파일이 아닙니다.');
        if (!$deleting && $file['photo_slot'] !== null) {
            $occupied = $db->prepare('SELECT id FROM leadership_files WHERE record_id=? AND photo_slot=? AND deleted_at IS NULL');
            $occupied->execute([$recordId, $file['photo_slot']]);
            if ($occupied->fetch()) throw new InvalidArgumentException('해당 사진 칸에 이미 사진이 있습니다. 현재 사진을 삭제한 뒤 복원해 주세요.');
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        $statement = $db->prepare('UPDATE leadership_files SET deleted_at=?, deleted_by=? WHERE id=?');
        $statement->execute([$deleting ? $now : null, $deleting ? $editor : null, $fileId]);
        $statement = $db->prepare('UPDATE leadership_records SET revision=revision+1,updated_by=?,updated_at=? WHERE id=? AND revision=?');
        $statement->execute([$editor, $now, $recordId, $revision]);
        if ($statement->rowCount() !== 1) throw new InvalidArgumentException('기록이 변경되었습니다. 새로고침 후 다시 시도해 주세요.');
        $snapshot = ['item' => $item, 'year' => $year, 'activity_date' => $file['activity_date'], 'title' => $file['title'], 'payload' => json_decode($file['payload'], true, 512, JSON_THROW_ON_ERROR),
            'file_event' => ['action' => $action, 'file_id' => $fileId, 'name' => $file['original_name']]];
        $statement = $db->prepare('INSERT INTO leadership_history(record_id,revision,snapshot,editor,saved_at) VALUES(?,?,?,?,?)');
        $statement->execute([$recordId, $revision + 1, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $editor, $now]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function leadership_date(mixed $value, string $label, bool $required = false): string
{
    if (!is_string($value)) throw new InvalidArgumentException($label . '를 확인해 주세요.');
    if ($value === '' && !$required) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || (int)$date->format('Y') < 2000 || (int)$date->format('Y') > 2100) {
        throw new InvalidArgumentException($label . '를 올바르게 입력해 주세요.');
    }
    return $value;
}

function leadership_text(mixed $value, string $label, int $limit = 4000, bool $required = false): string
{
    if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit || ($required && trim($value) === '')) {
        throw new InvalidArgumentException($label . ' 입력을 확인해 주세요.');
    }
    return trim($value);
}

function leadership_vfl_check_labels(): array
{
    return ['general_permit' => '일반작업허가', 'confined_space' => '밀폐공간 작업허가',
        'hot_work' => '화기작업허가', 'electrical_isolation' => '전기차단 작업',
        'excavation' => '굴착작업', 'height' => '고소작업', 'heavy_equipment' => '중장비 작업',
        'ppe' => '보호구 착용', 'loto' => '안전잠금(LOTO)', 'education' => '안전교육 이수',
        'housekeeping' => '정리정돈', 'tools_equipment' => '도구·장비·안전장치'];
}

function leadership_validate(array $input, string $item, int $year): array
{
    if (!in_array($item, ['1.1.1', '1.1.2', '1.1.3', '1.1.4'], true)) throw new InvalidArgumentException('평가항목을 확인해 주세요.');
    $date = leadership_date($input['activity_date'] ?? '', '활동일', true);
    if ((int)substr($date, 0, 4) !== $year) throw new InvalidArgumentException('활동일은 선택한 연도에 속해야 합니다.');
    if ($date > (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d')) throw new InvalidArgumentException('실제 활동일을 기록해 주세요. 미래 활동은 등록할 수 없습니다.');
    $payload = ['owner' => leadership_text($input['owner'] ?? '', '담당자·참석자', 150, true),
        'location' => leadership_text($input['location'] ?? '', '장소·전달방법', 500, true),
        'notes' => leadership_text($input['notes'] ?? '', '활동 내용', 10000, $item !== '1.1.3')];
    if ($item === '1.1.2') {
        $payload['signed_date'] = leadership_date($input['signed_date'] ?? '', '대표자 서명일');
        if ($payload['signed_date'] !== '' && $payload['signed_date'] > $date) throw new InvalidArgumentException('대표자 서명일은 전달일 이후일 수 없습니다.');
        $payload['audience'] = leadership_text($input['audience'] ?? '', '전달 대상', 1000, true);
    }
    if ($item === '1.1.3') {
        if (array_key_exists('vfl_photo_captions', $input)) {
            if (!is_array($input['vfl_photo_captions']) || array_diff(array_keys($input['vfl_photo_captions']), [1, 2])) throw new InvalidArgumentException('점검사진은 1번과 2번 칸만 사용할 수 있습니다.');
            $payload['vfl_photo_captions'] = [];
            foreach ([1, 2] as $slot) {
                $caption = leadership_text($input['vfl_photo_captions'][$slot] ?? '', '안전대화 내용', 4000);
                $payload['vfl_photo_captions'][$slot] = str_replace(["\r\n", "\r"], "\n", $caption);
            }
        }
        $time = leadership_text($input['vfl_time'] ?? '', '순찰 시간', 5);
        if ($time !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) throw new InvalidArgumentException('순찰 시간을 올바르게 입력해 주세요.');
        $countValue = $input['vfl_dialog_count'] ?? '';
        $dialogCount = $countValue === '' || $countValue === null ? null : filter_var($countValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
        if ($dialogCount === false) throw new InvalidArgumentException('대화 인원은 0~10,000명의 정수로 입력해 주세요.');
        $checks = $input['vfl_checks'] ?? [];
        if (!is_array($checks) || count($checks) > count(leadership_vfl_check_labels())) throw new InvalidArgumentException('중점 점검사항을 확인해 주세요.');
        foreach ($checks as $check) if (!is_string($check) || !array_key_exists($check, leadership_vfl_check_labels())) throw new InvalidArgumentException('중점 점검사항을 확인해 주세요.');
        $payload['vfl_time'] = $time;
        $payload['vfl_companions'] = leadership_text($input['vfl_companions'] ?? '', '동행자', 500);
        $payload['vfl_dialog_count'] = $dialogCount;
        $payload['vfl_dialogue'] = leadership_text($input['vfl_dialogue'] ?? '', '안전대화 내용', 10000);
        $hasDialogue = $payload['vfl_dialogue'] !== '' || count(array_filter($payload['vfl_photo_captions'] ?? [], static fn(string $text): bool => $text !== '')) > 0;
        if ($dialogCount !== null && $dialogCount > 0 && !$hasDialogue) throw new InvalidArgumentException('대화 인원을 기록한 경우 사진 오른쪽의 안전대화 내용도 입력해 주세요.');
        $payload['vfl_checks'] = array_values(array_unique($checks));
        $payload['vfl_focus_notes'] = leadership_text($input['vfl_focus_notes'] ?? '', '추가 중점 점검사항', 4000);
        $actions = $input['actions'] ?? [];
        if (!is_array($actions) || count($actions) > 30) throw new InvalidArgumentException('개선조치는 최대 30건까지 입력할 수 있습니다.');
        $payload['actions'] = [];
        foreach ($actions as $action) {
            if (!is_array($action)) throw new InvalidArgumentException('개선조치 입력을 확인해 주세요.');
            $description = leadership_text($action['description'] ?? '', '개선조치 내용', 2000);
            $owner = leadership_text($action['owner'] ?? '', '조치 담당자', 150);
            $due = leadership_date($action['due'] ?? '', '조치기한');
            $completed = leadership_date($action['completed'] ?? '', '조치완료일');
            $finding = leadership_text($action['finding'] ?? '', '발견사항·위험요인', 2000);
            $classification = $action['classification'] ?? '';
            if (!is_string($classification) || !in_array($classification, ['', 'SRV', 'UA', 'UC'], true)) throw new InvalidArgumentException('개선조치 분류를 확인해 주세요.');
            if ($description === '' && $owner === '' && $due === '' && $completed === '' && $finding === '' && $classification === '') continue;
            if ($description === '' || $owner === '' || $due === '') throw new InvalidArgumentException('개선조치 내용, 담당자와 기한을 함께 입력해 주세요.');
            if ($due < $date || ($completed !== '' && $completed < $date)) throw new InvalidArgumentException('조치기한·완료일은 관찰일 이전일 수 없습니다.');
            if ($completed !== '' && $completed > (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d')) throw new InvalidArgumentException('미래 날짜를 조치완료일로 입력할 수 없습니다.');
            $payload['actions'][] = compact('description', 'owner', 'due', 'completed', 'finding', 'classification');
        }
    }
    if ($item === '1.1.4') {
        $attendance = $input['attendance'] ?? '';
        if (!in_array($attendance, ['representative', 'delegate', 'absent', 'not_held'], true)) throw new InvalidArgumentException('참석 구분을 선택해 주세요.');
        $payload['attendance'] = $attendance;
    }
    return ['item' => $item, 'year' => $year, 'activity_date' => $date,
        'title' => leadership_text($input['title'] ?? '', '기록 제목', 200, true), 'payload' => $payload];
}

function leadership_uploads(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names) || count($names) > 5) throw new InvalidArgumentException('증빙은 한 번에 최대 5개까지 첨부할 수 있습니다.');
    $uploads = [];
    $total = 0;
    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xlsx',
    ];
    foreach ($names as $index => $name) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('증빙 파일을 업로드하지 못했습니다. 파일 크기를 확인해 주세요.');
        $temporary = $files['tmp_name'][$index] ?? '';
        if (!is_string($temporary) || !is_uploaded_file($temporary)) throw new InvalidArgumentException('업로드 파일을 확인해 주세요.');
        $size = filesize($temporary);
        $total += $size;
        if ($size <= 0 || $size > 10 * 1024 * 1024 || $total > 30 * 1024 * 1024) throw new InvalidArgumentException('파일별 10MB, 합계 30MB 이내로 첨부해 주세요.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (!isset($extensions[$mime])) throw new InvalidArgumentException('증빙은 PDF, XLSX, JPG, PNG 파일만 첨부할 수 있습니다.');
        $name = basename(str_replace('\\', '/', (string)$name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);
        $uploads[] = ['temporary' => $temporary, 'original_name' => mb_substr($name ?: 'evidence', 0, 180),
            'stored_name' => bin2hex(random_bytes(20)) . '.' . $extensions[$mime], 'mime' => $mime, 'size' => $size];
    }
    return $uploads;
}

function leadership_photo_uploads(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names) || count($names) > 2 || array_diff(array_keys($names), [1, 2])) throw new InvalidArgumentException('점검사진은 최대 2장까지 등록할 수 있습니다.');
    $uploads = [];
    foreach ($names as $slot => $name) {
        $single = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) $single[$key] = [0 => $files[$key][$slot] ?? ($key === 'error' ? UPLOAD_ERR_NO_FILE : '')];
        foreach (leadership_uploads($single) as $upload) {
            if (!in_array($upload['mime'], ['image/jpeg', 'image/png'], true) || @getimagesize($upload['temporary']) === false) throw new InvalidArgumentException('점검사진은 정상적인 JPG 또는 PNG 이미지로 등록해 주세요.');
            $upload['photo_slot'] = (int)$slot;
            $uploads[] = $upload;
        }
    }
    return $uploads;
}

function leadership_save(PDO $db, array $record, int $id, int $revision, array $uploads, string $editor, bool $attachmentsOnly = false): int
{
    $moved = [];
    $db->exec('BEGIN IMMEDIATE');
    try {
        $newPhotos = [];
        foreach ($uploads as $upload) if (isset($upload['photo_slot'])) {
            if ($record['item'] !== '1.1.3' || !in_array($upload['photo_slot'], [1, 2], true) || isset($newPhotos[$upload['photo_slot']])) throw new InvalidArgumentException('점검사진 칸을 확인해 주세요.');
            $newPhotos[$upload['photo_slot']] = true;
        }
        if (array_sum(array_column($uploads, 'size')) > 30 * 1024 * 1024) throw new InvalidArgumentException('사진과 증빙을 합해 30MB 이내로 첨부해 주세요.');
        if ($record['item'] === '1.1.4') {
            $query = $db->prepare('SELECT id FROM leadership_records WHERE item = ? AND year = ? AND substr(activity_date,1,7) = ? AND id <> ?');
            $query->execute([$record['item'], $record['year'], substr($record['activity_date'], 0, 7), $id]);
            if ($query->fetch()) throw new InvalidArgumentException('해당 월의 협의회 기록이 있습니다. 기존 월별 기록을 수정해 주세요.');
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        $payload = json_encode($record['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($id > 0) {
            $query = $db->prepare('UPDATE leadership_records SET activity_date=?, title=?, payload=?, revision=revision+1, updated_by=?, updated_at=? WHERE id=? AND item=? AND year=? AND revision=?');
            $query->execute([$record['activity_date'], $record['title'], $payload, $editor, $now, $id, $record['item'], $record['year'], $revision]);
            if ($query->rowCount() !== 1) throw new InvalidArgumentException('다른 화면에서 수정되었거나 기록이 없습니다. 새로고침 후 다시 확인해 주세요.');
            $revision++;
        } else {
            $query = $db->prepare('INSERT INTO leadership_records(item,year,activity_date,title,payload,updated_by,updated_at) VALUES(?,?,?,?,?,?,?)');
            $query->execute([$record['item'], $record['year'], $record['activity_date'], $record['title'], $payload, $editor, $now]);
            $id = (int)$db->lastInsertId();
            $revision = 1;
        }
        $directory = __DIR__ . '/storage/leadership_evidence';
        if ($record['item'] === '1.1.3' && !$attachmentsOnly) {
            $currentPhotos = $db->prepare('SELECT * FROM leadership_files WHERE record_id=? AND photo_slot IS NOT NULL AND deleted_at IS NULL');
            $currentPhotos->execute([$id]);
            $existingPhotos = [];
            foreach ($currentPhotos->fetchAll() as $photo) $existingPhotos[(int)$photo['photo_slot']] = $photo;
            foreach ($record['payload']['vfl_photo_captions'] ?? [] as $slot => $caption) {
                if ($caption !== '' && !isset($newPhotos[$slot]) && !isset($existingPhotos[$slot])) throw new InvalidArgumentException('사진 ' . $slot . '번 설명을 입력하려면 해당 사진도 선택해 주세요.');
                if (isset($existingPhotos[$slot]) && !isset($newPhotos[$slot])) {
                    $query = $db->prepare('UPDATE leadership_files SET caption=? WHERE id=?');
                    $query->execute([$caption, $existingPhotos[$slot]['id']]);
                }
            }
            foreach (array_keys($newPhotos) as $slot) {
                $query = $db->prepare('UPDATE leadership_files SET deleted_at=?,deleted_by=? WHERE record_id=? AND photo_slot=? AND deleted_at IS NULL');
                $query->execute([$now, $editor, $id, $slot]);
            }
        }
        if ($uploads && !is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Cannot create evidence storage');
        foreach ($uploads as $upload) {
            $path = $directory . '/' . $upload['stored_name'];
            if (!move_uploaded_file($upload['temporary'], $path)) throw new RuntimeException('Cannot store evidence');
            $moved[] = $path;
            $slot = $upload['photo_slot'] ?? null;
            $caption = $slot !== null ? ($record['payload']['vfl_photo_captions'][$slot] ?? '') : '';
            $query = $db->prepare('INSERT INTO leadership_files(record_id,original_name,stored_name,mime,size,photo_slot,caption,file_kind) VALUES(?,?,?,?,?,?,?,?)');
            $kind = ($upload['file_kind'] ?? '') === 'signed_scan' ? 'signed_scan' : 'evidence';
            if (!empty($upload['file_kind']) && preg_match('/^[a-z0-9_]{2,30}$/D', (string)$upload['file_kind'])) {
                $kind = (string)$upload['file_kind'];
            }
            $query->execute([$id, $upload['original_name'], $upload['stored_name'], $upload['mime'], $upload['size'], $slot, $caption, $kind]);
        }
        $query = $db->prepare('INSERT INTO leadership_history(record_id,revision,snapshot,editor,saved_at) VALUES(?,?,?,?,?)');
        $query->execute([$id, $revision, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $editor, $now]);
        $db->exec('COMMIT');
        return $id;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        foreach ($moved as $path) if (is_file($path)) unlink($path);
        throw $error;
    }
}
