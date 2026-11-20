<?php
declare(strict_types=1);

function he_db_path(): string
{
    return __DIR__ . '/storage/heavy_equipment.sqlite';
}

function he_evidence_dir(): string
{
    return __DIR__ . '/storage/heavy_equipment_evidence';
}

function he_photo_dir(): string
{
    return __DIR__ . '/storage/heavy_equipment_photos';
}

function he_items(): array
{
    return [
        '2.7.1' => ['tab' => 'drivers', 'title' => '차량·중장비 보유 및 운전자 자격', 'points' => 2.0, 'kinds' => [
            'fleet' => '차량·중장비 보유현황', 'license' => '운전자 자격·면허', 'violations' => '규정위반 이력', 'other' => '기타 증빙',
        ]],
        '2.7.2' => ['tab' => 'inspect', 'title' => '운행 전·정기점검 및 법정검사·정비', 'points' => 2.0, 'kinds' => [
            'checklist' => '운행 전·정기점검표', 'statutory' => '법정 정기검사 기록', 'maintenance' => '정비·수리 기록', 'other' => '기타 증빙',
        ]],
        '2.7.3' => ['tab' => 'guards', 'title' => '중장비 기능·방호장치 현장확인', 'points' => 2.0, 'kinds' => [
            'guard_check' => '기능·방호장치 점검표', 'site_photo' => '현장 확인 사진', 'corrective' => '개선조치 증빙', 'other' => '기타 증빙',
        ]],
    ];
}

function he_vehicle_types(): array
{
    return ['고소작업차', '카고크레인', '화물트럭', '지게차', '굴삭기'];
}

function he_inspection_types(): array
{
    return ['운행 전 일상점검', '월간 자체점검', '법정 정기검사', '정비·수리'];
}

function he_check_labels(string $mode): array
{
    if ($mode === 'guards') {
        return [
            'chk_safety_pin_lock' => '안전핀·잠금장치',
            'chk_rear_camera_alarm' => '후방카메라·경보장치',
            'chk_seat_belt' => '안전벨트',
            'chk_no_mod' => '임의 개조 여부 확인',
            'chk_estop_limit' => '비상정지·리미트 스위치',
            'chk_tire_brake' => '타이어·제동장치',
        ];
    }
    return [
        'chk_engine_oil' => '엔진오일', 'chk_coolant' => '냉각수', 'chk_brake' => '제동장치',
        'chk_tire' => '타이어', 'chk_lights_horn' => '등화·경음기', 'chk_hydraulic_wire' => '유압·와이어로프',
    ];
}

function he_year(mixed $value): int
{
    $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2020, 'max_range' => 2099]]);
    return $year ?: (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
}

function he_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    he_make_dir(dirname(he_db_path()));
    $candidate = new PDO('sqlite:' . he_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $candidate->exec('PRAGMA busy_timeout = 5000');
    $candidate->exec('PRAGMA journal_mode = WAL');
    $candidate->exec('PRAGMA foreign_keys = ON');
    if (!(int)$candidate->query('PRAGMA user_version')->fetchColumn()) {
        he_initialize($candidate);
    }
    return $db = $candidate;
}

function he_initialize(PDO $db): void
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!(int)$db->query('PRAGMA user_version')->fetchColumn()) {
            $db->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS he_vehicles (
                    id INTEGER PRIMARY KEY,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    vehicle_type TEXT NOT NULL CHECK (vehicle_type IN ('고소작업차','카고크레인','화물트럭','지게차','굴삭기')),
                    vehicle_name TEXT NOT NULL,
                    plate_no TEXT NOT NULL,
                    reg_date TEXT NOT NULL DEFAULT '',
                    driver_name TEXT NOT NULL DEFAULT '',
                    legal_inspect_due TEXT NOT NULL DEFAULT '',
                    insurance_info TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT '운용',
                    note TEXT NOT NULL DEFAULT '',
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS he_drivers (
                    id INTEGER PRIMARY KEY,
                    worker_name TEXT NOT NULL,
                    team TEXT NOT NULL DEFAULT '',
                    license_type TEXT NOT NULL DEFAULT '',
                    license_no TEXT NOT NULL DEFAULT '',
                    acquire_date TEXT NOT NULL DEFAULT '',
                    renewal_date TEXT NOT NULL DEFAULT '',
                    assigned_vehicle TEXT NOT NULL DEFAULT '',
                    violation_count INTEGER NOT NULL DEFAULT 0 CHECK (violation_count >= 0),
                    violation_history TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT '확인 필요',
                    note TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS he_inspections (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    year INTEGER NOT NULL,
                    vehicle_id INTEGER REFERENCES he_vehicles(id) ON DELETE SET NULL,
                    vehicle_label TEXT NOT NULL,
                    check_type TEXT NOT NULL CHECK (check_type IN ('운행 전 일상점검','월간 자체점검','법정 정기검사','정비·수리')),
                    check_date TEXT NOT NULL,
                    inspector TEXT NOT NULL,
                    mileage INTEGER CHECK (mileage >= 0),
                    chk_engine_oil TEXT NOT NULL,
                    chk_coolant TEXT NOT NULL,
                    chk_brake TEXT NOT NULL,
                    chk_tire TEXT NOT NULL,
                    chk_lights_horn TEXT NOT NULL,
                    chk_hydraulic_wire TEXT NOT NULL,
                    overall_result TEXT NOT NULL CHECK (overall_result IN ('적합','보완필요')),
                    detail_note TEXT NOT NULL DEFAULT '',
                    shop_name TEXT NOT NULL DEFAULT '',
                    next_due_date TEXT NOT NULL DEFAULT '',
                    created_by TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS he_guard_checks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    year INTEGER NOT NULL,
                    vehicle_id INTEGER REFERENCES he_vehicles(id) ON DELETE SET NULL,
                    vehicle_label TEXT NOT NULL,
                    check_date TEXT NOT NULL,
                    inspector TEXT NOT NULL,
                    chk_safety_pin_lock TEXT NOT NULL,
                    chk_rear_camera_alarm TEXT NOT NULL,
                    chk_seat_belt TEXT NOT NULL,
                    chk_no_mod TEXT NOT NULL,
                    chk_estop_limit TEXT NOT NULL,
                    chk_tire_brake TEXT NOT NULL,
                    overall_result TEXT NOT NULL CHECK (overall_result IN ('적합','보완필요')),
                    issue_note TEXT NOT NULL DEFAULT '',
                    action_note TEXT NOT NULL DEFAULT '',
                    photo1 TEXT NOT NULL DEFAULT '',
                    photo2 TEXT NOT NULL DEFAULT '',
                    created_by TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS he_evidence (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    year INTEGER NOT NULL,
                    item_code TEXT NOT NULL CHECK (item_code IN ('2.7.1','2.7.2','2.7.3')),
                    kind TEXT NOT NULL,
                    original_name TEXT NOT NULL,
                    stored_name TEXT NOT NULL UNIQUE,
                    mime TEXT NOT NULL,
                    size INTEGER NOT NULL CHECK (size > 0),
                    note TEXT NOT NULL DEFAULT '',
                    uploaded_by TEXT NOT NULL,
                    uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    deleted_at TEXT
                );
                CREATE TABLE IF NOT EXISTS he_assessments (
                    year INTEGER NOT NULL,
                    item_code TEXT NOT NULL CHECK (item_code IN ('2.7.1','2.7.2','2.7.3')),
                    multiplier REAL NOT NULL CHECK (multiplier IN (1.0, 0.9, 0.8, 0.5, 0.0)),
                    note TEXT NOT NULL DEFAULT '',
                    updated_by TEXT NOT NULL,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (year, item_code)
                );
                CREATE INDEX IF NOT EXISTS he_inspections_year_date ON he_inspections(year, check_date DESC, id DESC);
                CREATE INDEX IF NOT EXISTS he_guards_year_date ON he_guard_checks(year, check_date DESC, id DESC);
                CREATE INDEX IF NOT EXISTS he_evidence_year_item ON he_evidence(year, item_code, id DESC) WHERE deleted_at IS NULL;
                SQL);
            he_seed_defaults($db);
            $db->exec('PRAGMA user_version = 1');
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function he_seed_defaults(PDO $db): void
{
    if (!(int)$db->query('SELECT COUNT(*) FROM he_vehicles')->fetchColumn()) {
        $vehicles = [
            ['고소작업차', '고소작업차', '98주 9346', '2015-07-03', '윤택천'],
            ['카고크레인', '크레인카고크레인', '88거 0780', '2021-10-20', '윤택천'],
            ['화물트럭', '봉고Ⅲ 4륜구동', '90오 9858', '2011-10-11', '김남균'],
            ['화물트럭', '포터Ⅱ', '81어 7603', '2012-09-28', '윤택천'],
            ['화물트럭', '포터Ⅱ', '81무 2084', '2018-02-14', '이정민'],
            ['화물트럭', '포터Ⅱ', '84구 3617', '2020-01-02', '윤순형'],
        ];
        $stmt = $db->prepare('INSERT INTO he_vehicles (sort_order, vehicle_type, vehicle_name, plate_no, reg_date, driver_name) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($vehicles as $index => $vehicle) {
            $stmt->execute([$index + 1, ...$vehicle]);
        }
    }
    if (!(int)$db->query('SELECT COUNT(*) FROM he_drivers')->fetchColumn()) {
        $stmt = $db->prepare('INSERT INTO he_drivers (worker_name, assigned_vehicle) VALUES (?, ?)');
        $stmt->execute(['윤택천', '98주 9346 / 88거 0780 / 81어 7603']);
        $stmt->execute(['김남균', '90오 9858']);
        $stmt->execute(['이정민', '81무 2084']);
        $stmt->execute(['윤순형', '84구 3617']);
    }
}

function he_rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function he_vehicle_label(array $vehicle): string
{
    return $vehicle['vehicle_name'] . ($vehicle['plate_no'] !== '' ? ' · ' . $vehicle['plate_no'] : '');
}

function he_make_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('저장소를 만들 수 없습니다.');
    }
}

function he_image_types(): array
{
    return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
}

function he_document_types(): array
{
    return [
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
}

function he_stored_path(string $name, bool $photo): ?string
{
    $extensions = array_values(he_image_types());
    if (!$photo) {
        $extensions = array_merge($extensions, array_keys(he_document_types()));
    }
    if (!preg_match('/\A[a-f0-9]{40}\.(' . implode('|', $extensions) . ')\z/', $name)) {
        return null;
    }
    $path = ($photo ? he_photo_dir() : he_evidence_dir()) . '/' . $name;
    clearstatcache(true, $path);
    return !is_link($path) && is_file($path) && is_readable($path) && filesize($path) > 0 ? $path : null;
}

function he_store_attachment(?array $file, string $clipboard, bool $photo): ?array
{
    $maxMb = $photo ? 15 : 20;
    $maxBytes = $maxMb * 1024 * 1024;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $images = he_image_types();
    $raw = null;
    $tmp = '';
    if ($clipboard !== '') {
        $pieces = explode(',', $clipboard, 2);
        if (count($pieces) !== 2 || !preg_match('~\Adata:image/(?:png|jpeg|jpg|webp);base64\z~i', $pieces[0])
            || strlen($pieces[1]) > 4 * (int)ceil($maxBytes / 3)) {
            throw new InvalidArgumentException("붙여넣기 이미지 형식과 크기를 확인해 주세요 (최대 {$maxMb}MB).");
        }
        $raw = base64_decode($pieces[1], true);
        if ($raw === false || $raw === '' || strlen($raw) > $maxBytes) {
            throw new InvalidArgumentException("붙여넣기 이미지 크기를 확인해 주세요 (최대 {$maxMb}MB).");
        }
        $mime = $finfo->buffer($raw);
        $image = @getimagesizefromstring($raw);
        if (!isset($images[$mime]) || ($image['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('유효한 JPG, PNG, WEBP 이미지가 필요합니다.');
        }
        $extension = $images[$mime];
        $size = strlen($raw);
        $name = '스크린샷_' . date('Ymd_His') . '.' . $extension;
    } else {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('파일 업로드에 실패했습니다. 서버 업로드 한도와 파일을 확인해 주세요.');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException("{$maxMb}MB 이하의 파일을 선택해 주세요.");
        }
        $name = basename(str_replace('\\', '/', (string)$file['name']));
        $name = mb_strcut((string)preg_replace('/[\x00-\x1F\x7F]/', '', $name), 0, 240, 'UTF-8');
        $mime = $finfo->file($tmp);
        if (isset($images[$mime])) {
            $image = @getimagesize($tmp);
            if (($image['mime'] ?? '') !== $mime) {
                throw new InvalidArgumentException('이미지 파일을 확인해 주세요.');
            }
            $extension = $images[$mime];
        } else {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($photo || !in_array($mime, he_document_types()[$extension] ?? [], true)) {
                throw new InvalidArgumentException($photo ? 'JPG, PNG, WEBP 사진을 선택해 주세요.' : 'PDF, JPG, PNG, WEBP, XLSX, DOCX 파일을 선택해 주세요.');
            }
            if ($extension === 'xlsx' || $extension === 'docx') {
                he_validate_office($tmp, $extension);
                $mime = he_document_types()[$extension][0];
            }
        }
    }
    $dir = $photo ? he_photo_dir() : he_evidence_dir();
    he_make_dir($dir);
    $stored = bin2hex(random_bytes(20)) . '.' . $extension;
    $path = $dir . '/' . $stored;
    $saved = $raw !== null ? file_put_contents($path, $raw, LOCK_EX) === $size : move_uploaded_file($tmp, $path);
    if (!$saved) {
        if (is_file($path)) {
            unlink($path);
        }
        throw new RuntimeException('첨부 파일을 저장할 수 없습니다.');
    }
    return ['original_name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size];
}

function he_validate_office(string $path, string $extension): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
    }
    try {
        $part = $extension === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
        foreach (['[Content_Types].xml', '_rels/.rels', $part] as $entry) {
            if (($zip->statName($entry)['size'] ?? 0) < 1) {
                throw new InvalidArgumentException('Office 문서 구조를 확인해 주세요.');
            }
        }
    } finally {
        $zip->close();
    }
}

function he_save_photo(?array $file, string $clipboard): string
{
    return he_store_attachment($file, $clipboard, true)['stored_name'] ?? '';
}

function he_discard_attachment(string $name, bool $photo): void
{
    $path = he_stored_path($name, $photo);
    if ($path !== null && !@unlink($path)) {
        error_log('heavy_equipment: attachment cleanup failed for ' . $name);
    }
}

function he_upload_evidence(PDO $db, int $year, string $itemCode, string $kind, ?array $file, string $clipboard, string $note, string $actor): void
{
    if (!isset(he_items()[$itemCode]['kinds'][$kind])) {
        throw new InvalidArgumentException('증빙 항목과 구분을 확인해 주세요.');
    }
    $attachment = he_store_attachment($file, $clipboard, false);
    if ($attachment === null) {
        throw new InvalidArgumentException('증빙 파일을 선택하거나 스크린샷을 붙여넣어 주세요.');
    }
    try {
        $db->prepare('INSERT INTO he_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$year, $itemCode, $kind, ...array_values($attachment), $note, $actor]);
    } catch (Throwable $error) {
        he_discard_attachment($attachment['stored_name'], false);
        throw $error;
    }
}

function he_photo_url(string $name): string
{
    return 'heavy_equipment.php?view_photo=' . rawurlencode($name);
}

function csm_he_score_summary(int $year, string $itemCode, float $points = 2.0): array
{
    $empty = [
        'earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence', 'multiplier' => 0.0,
        'revision' => 0, 'automatic' => true, 'assessment' => null,
    ];
    if (!isset(he_items()[$itemCode]) || !is_file(he_db_path())) {
        return $empty;
    }
    try {
        $path = str_replace('\\', '/', he_db_path());
        $path = str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($path));
        $db = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA query_only = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->beginTransaction();
        $tables = array_column(he_rows($db, "SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
        $count = 0;
        if (in_array('he_evidence', $tables, true)) {
            $files = he_rows($db, 'SELECT stored_name, size FROM he_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL', [$year, $itemCode]);
            foreach ($files as $file) {
                $path = he_stored_path($file['stored_name'], false);
                if ($path !== null && filesize($path) === (int)$file['size']) {
                    $count++;
                }
            }
        }
        if ($itemCode === '2.7.3' && in_array('he_guard_checks', $tables, true)) {
            foreach (he_rows($db, 'SELECT photo1, photo2 FROM he_guard_checks WHERE year = ?', [$year]) as $photos) {
                foreach ($photos as $name) {
                    $path = he_stored_path($name, true);
                    if ($path !== null && isset(he_image_types()[(@getimagesize($path)['mime'] ?? '')])) {
                        $count++;
                    }
                }
            }
        }
        $assessment = in_array('he_assessments', $tables, true)
            ? (he_rows($db, 'SELECT multiplier, note, updated_by, updated_at FROM he_assessments WHERE year = ? AND item_code = ?', [$year, $itemCode])[0] ?? null)
            : null;
        $db->commit();

        if ($count === 0) {
            return array_replace($empty, ['assessment' => $assessment, 'automatic' => $assessment === null]);
        }
        $multiplier = (float)($assessment['multiplier'] ?? 1.0);
        return [
            'earned' => round($points * $multiplier, 2), 'evidence_count' => $count, 'state' => 'assessed',
            'multiplier' => $multiplier, 'revision' => 0, 'automatic' => $assessment === null, 'assessment' => $assessment,
        ];
    } catch (PDOException $error) {
        error_log('csm_he_score_summary: ' . $error->getMessage());
        return $empty;
    }
}
