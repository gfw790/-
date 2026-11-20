<?php
declare(strict_types=1);

function wi_db_path(): string
{
    return __DIR__ . '/storage/workplace_inspection.sqlite';
}

function wi_evidence_dir(): string
{
    return __DIR__ . '/storage/workplace_inspection_evidence';
}

function wi_photo_dir(): string
{
    return __DIR__ . '/storage/workplace_inspection_photos';
}

function wi_item_meta(): array
{
    return [
        '3.1.1' => [
            'code' => '3.1.1',
            'tab' => 's5',
            'title' => '근로자 휴게실, 작업장, 적재물 보관소, 기타 장소 청결 관리',
            'short' => '5S·청결 관리',
            'points' => 2.0,
            'evidence_guide' => '작업장·창고·휴게실 5S 점검표, 현장 청결 사진, 개선 전·후 조치 기록',
            'default_location' => '현대기전 작업장·자재창고·휴게실',
            'photo1_label' => '현장 점검 사진 1 (작업장·자재창고·적재물)',
            'photo2_label' => '현장 점검 사진 2 (휴게실·개선 후 확인)',
            'checklist' => [
                '원·부자재 및 적재물 보관소 구획 정리·품명 표기 상태',
                '작업장 바닥·통로 장애물 제거 및 청결 유지 상태',
                '사용 후 공구·부품 공구함 제자리 정돈 상태',
                '근로자 휴게실(화장실·샤워실 포함) 위생 및 청결 상태',
                '폐자재·불량품 지정 보관함 분리 수거 상태',
                '소화기·비상구 앞 적치물 금지 및 주변 정리정돈 상태',
            ],
        ],
        '3.1.2' => [
            'code' => '3.1.2',
            'tab' => 'env',
            'title' => '작업장소 조명, 환기설비, 휴게실 등 적정 설치·관리',
            'short' => '조명·환기·휴게실',
            'points' => 2.0,
            'evidence_guide' => '작업환경 현장 점검표, 조명·환기설비·휴게실 설치 및 가동 상태 사진',
            'default_location' => '현대기전 작업장 및 근로자 휴게실',
            'photo1_label' => '현장 점검 사진 1 (조명·환기설비 상태)',
            'photo2_label' => '현장 점검 사진 2 (휴게실·편의시설 상태)',
            'checklist' => [
                '작업장소 조명 설치 및 작업면 적정 조도 확보 여부',
                '국소배기장치·환기팬 등 환기설비 설치 및 정상 가동 여부',
                '근로자 휴게실 냉·난방기, 의자, 사물함 등 편의시설 적정 여부',
                '분진·용접흄·냄새 발생 구역 환기 및 공기질 관리 상태',
                '음용수 공급, 세면·위생시설 및 응급처치 비품 구비 상태',
                '야간·암소 작업용 임시조명 및 비상등 정상 작동 여부',
            ],
        ],
        '3.1.3' => [
            'code' => '3.1.3',
            'tab' => 'sign',
            'title' => '안전보건 표지판 설치, 조명·환기, 위험구역 접근금지 조치',
            'short' => '표지판·위험구역 통제',
            'points' => 2.0,
            'evidence_guide' => '작업장·사업장 현장 점검표, 안전보건 표지판 및 위험구역 통제 사진',
            'default_location' => '현대기전 작업구역 및 자재·장비 구역',
            'photo1_label' => '현장 점검 사진 1 (안전보건 표지판 설치)',
            'photo2_label' => '현장 점검 사진 2 (위험구역 접근금지 조치)',
            'checklist' => [
                '안전보건 표지판(금지·경고·지시·안내) 적정 위치 부착 및 시인성',
                '고소·중량물·화기 등 위험구역 접근금지(안전휀스·라바콘·구획띠) 조치',
                '분전반·전기설비 감전 경고표지 부착 및 시건장치 관리 상태',
                '작업구역 조명·환기설비 정상 유지 및 파손·탈락 없음',
                '소화기·비상대피로·비상연락망 안내 표지 식별 가능 상태',
                '통행로 구획선 및 차량·장비 동선 분리 관리 상태',
            ],
        ],
        '3.1.4' => [
            'code' => '3.1.4',
            'tab' => 'guard',
            'title' => '작업장 내 유해위험기계기구의 안전장치 설치 및 작동 여부',
            'short' => '유해위험기계 안전장치',
            'points' => 2.0,
            'evidence_guide' => '작업현장 유해위험기계·기구 안전장치 확인 점검표 및 작동 확인 사진',
            'default_location' => '현대기전 작업장 내 위험기계·기구 설치구역',
            'photo1_label' => '현장 점검 사진 1 (방호장치·덮개 설치 상태)',
            'photo2_label' => '현장 점검 사진 2 (비상정지·작동 확인 상태)',
            'checklist' => [
                '회전부·협착부 방호덮개·방호울 설치 및 견고한 고정 여부',
                '비상정지장치(E-STOP) 적정 위치 설치 및 정상 작동 여부',
                '권과방지·과부하방지·안전밸브·역화방지기 등 방호장치 정상 작동',
                '누전차단기·접지선 연결 및 충전부 절연·방호 상태',
                '안전장치(인터록·리미트스위치 등) 임의 해제·무력화 없음',
                '이상 발견 기계기구 즉시 사용중지(LOTO) 및 정비 조치 이행',
            ],
        ],
    ];
}

function wi_5s_questions(): array
{
    return [
        // 정리 (1~5)
        ['no' => 1, 'group' => '정리', 'sub_no' => 1, 'text' => '자재창고 및 작업장의 불필요한 자재·고장 공구가 제거되어 있는가'],
        ['no' => 2, 'group' => '정리', 'sub_no' => 2, 'text' => '전기·배관 자재 및 소모품이 종류별로 바르게 구분·보관되어 있는가'],
        ['no' => 3, 'group' => '정리', 'sub_no' => 3, 'text' => '작업대·분전반·장비 주변에 불필요한 사물이나 자재가 놓여 있지 않는가'],
        ['no' => 4, 'group' => '정리', 'sub_no' => 4, 'text' => '사용이 끝난 휴대용 공구·전선릴·사다리 등이 지정 보관장소로 반납되었는가'],
        ['no' => 5, 'group' => '정리', 'sub_no' => 5, 'text' => '폐전선·고철·포장재 등 폐기물이 지정 폐기물 보관함에 분리 적치되어 있는가'],
        // 정돈 (6~10)
        ['no' => 6, 'group' => '정돈', 'sub_no' => 1, 'text' => '자재 보관대·적재물 보관소에 품명 표기 및 구획 정리가 되어 있는가'],
        ['no' => 7, 'group' => '정돈', 'sub_no' => 2, 'text' => '공구함 내 공구와 계측기가 정해진 위치에 정돈되어 있는가'],
        ['no' => 8, 'group' => '정돈', 'sub_no' => 3, 'text' => '작업장 통로·계단·출입구에 자재나 전선 등 걸림 장애물이 없는가'],
        ['no' => 9, 'group' => '정돈', 'sub_no' => 4, 'text' => '적재물이 무너지지 않도록 적정 높이·안정된 상태로 적재되어 있는가'],
        ['no' => 10, 'group' => '정돈', 'sub_no' => 5, 'text' => '소화기·분전반·비상구 앞에 물건이 적치되어 있지 않는가'],
        // 청소 (11~15)
        ['no' => 11, 'group' => '청소', 'sub_no' => 1, 'text' => '작업장 및 자재창고 바닥의 먼지·케이블 조각·절삭칩 등이 청소되어 있는가'],
        ['no' => 12, 'group' => '청소', 'sub_no' => 2, 'text' => '보유 기계·기구 및 공구의 기름때·분진·누유 여부가 확인·청소되어 있는가'],
        ['no' => 13, 'group' => '청소', 'sub_no' => 3, 'text' => '근로자 휴게실(화장실·샤워실 포함)이 청결하게 청소되어 있는가'],
        ['no' => 14, 'group' => '청소', 'sub_no' => 4, 'text' => '사무실·창고 외부 및 폐기물 보관장소 주변이 깨끗하게 정리되어 있는가'],
        ['no' => 15, 'group' => '청소', 'sub_no' => 5, 'text' => '빗자루·청소기·걸레 등 청소 도구가 지정 위치에 비치·정돈되어 있는가'],
        // 청결 (16~20)
        ['no' => 16, 'group' => '청결', 'sub_no' => 1, 'text' => '작업장·창고 내 조명·환기 상태가 양호하고 분진·악취가 없는가'],
        ['no' => 17, 'group' => '청결', 'sub_no' => 2, 'text' => '휴게실 내 음용수·냉난방기·사물함 등 편의시설이 위생적으로 유지되는가'],
        ['no' => 18, 'group' => '청결', 'sub_no' => 3, 'text' => '자재창고·공구보관소·보관장소의 관리책임자 및 표지판이 부착되어 있는가'],
        ['no' => 19, 'group' => '청결', 'sub_no' => 4, 'text' => '습기·누수 등으로 자재나 전기공구가 오염·부식될 우려는 없는가'],
        ['no' => 20, 'group' => '청결', 'sub_no' => 5, 'text' => '흡연은 지정된 흡연구역에서만 이루어지고 꽁초가 방치되지 않는가'],
        // 습관화 (21~25)
        ['no' => 21, 'group' => '습관화', 'sub_no' => 1, 'text' => '작업 종료 후 현장 정리정돈(자재·공구 회수 및 청소)을 매일 이행하는가'],
        ['no' => 22, 'group' => '습관화', 'sub_no' => 2, 'text' => '분리수거함 및 폐기물 보관장소 이용 기준을 전 작업자가 준수하는가'],
        ['no' => 23, 'group' => '습관화', 'sub_no' => 3, 'text' => '공구·장비 사용 후 이상 유무 확인과 제자리 정돈이 지켜지는가'],
        ['no' => 24, 'group' => '습관화', 'sub_no' => 4, 'text' => '자재창고·휴게실 등 공용 공간의 청결 유지 규칙이 자발적으로 지켜지는가'],
        ['no' => 25, 'group' => '습관화', 'sub_no' => 5, 'text' => '5S 점검 시 지적된 미흡 사항이 기한 내 개선되고 재발되지 않는가'],
    ];
}

function wi_evidence_kinds(): array
{
    return [
        '3.1.1' => [
            's5_sheet' => '5S 점검표 (월별·반기)',
            'clean_photo' => '작업장·창고·휴게실 청결 사진',
            'before_after' => '정리정돈 개선 전·후 사진',
            'etc_311' => '기타 청결관리 증빙',
        ],
        '3.1.2' => [
            'env_sheet' => '작업환경(조명·환기·휴게실) 점검표',
            'light_vent_photo' => '조명·환기설비 설치·가동 사진',
            'restroom_photo' => '근로자 휴게실·편의시설 사진',
            'etc_312' => '기타 작업환경 증빙',
        ],
        '3.1.3' => [
            'sign_sheet' => '안전표지·위험구역 통제 점검표',
            'sign_photo' => '안전보건 표지판 설치 사진',
            'zone_photo' => '위험구역 접근금지 통제 사진',
            'etc_313' => '기타 표지·통제 증빙',
        ],
        '3.1.4' => [
            'guard_sheet' => '유해위험기계 안전장치 점검표',
            'guard_photo' => '방호장치·비상정지 작동 확인 사진',
            'action_photo' => '안전장치 보완·개선 조치 사진',
            'etc_314' => '기타 안전장치 증빙',
        ],
    ];
}

function wi_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(wi_db_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . wi_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wi_5s_meta (
            year INTEGER PRIMARY KEY,
            location TEXT NOT NULL DEFAULT '작업장·자재창고·휴게실',
            checker_name TEXT NOT NULL DEFAULT '김남균',
            note TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS wi_5s_records (
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            item_no INTEGER NOT NULL,
            result TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (year, month, item_no)
        );

        CREATE TABLE IF NOT EXISTS wi_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            check_date TEXT NOT NULL,
            inspector TEXT NOT NULL DEFAULT '김남균',
            location TEXT NOT NULL DEFAULT '',
            chk1 TEXT NOT NULL DEFAULT '적합',
            chk2 TEXT NOT NULL DEFAULT '적합',
            chk3 TEXT NOT NULL DEFAULT '적합',
            chk4 TEXT NOT NULL DEFAULT '적합',
            chk5 TEXT NOT NULL DEFAULT '적합',
            chk6 TEXT NOT NULL DEFAULT '적합',
            overall_result TEXT NOT NULL DEFAULT '적합',
            issue_note TEXT NOT NULL DEFAULT '',
            action_note TEXT NOT NULL DEFAULT '',
            photo1 TEXT NOT NULL DEFAULT '',
            photo2 TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS wi_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            kind TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            uploaded_by TEXT NOT NULL,
            uploaded_at TEXT NOT NULL,
            deleted_at TEXT NULL
        );

        CREATE TABLE IF NOT EXISTS wi_assessments (
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            multiplier REAL NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (year, item_code)
        );
    ");

    return $pdo;
}

function wi_get_5s_meta(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM wi_5s_meta WHERE year = ?');
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT OR IGNORE INTO wi_5s_meta (year, updated_at) VALUES (?, ?)')->execute([$year, $now]);
    $stmt->execute([$year]);
    return $stmt->fetch() ?: ['year' => $year, 'location' => '작업장·자재창고·휴게실', 'checker_name' => '김남균', 'note' => ''];
}

function wi_get_5s_matrix(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT month, item_no, result FROM wi_5s_records WHERE year = ?');
    $stmt->execute([$year]);
    $matrix = [];
    foreach ($stmt->fetchAll() as $r) {
        $matrix[(int)$r['item_no']][(int)$r['month']] = (string)$r['result'];
    }
    return $matrix;
}

function wi_count_5s_completed_months(array $matrix): int
{
    $months = [];
    foreach ($matrix as $itemRows) {
        foreach ($itemRows as $m => $val) {
            if ($val === '○' || $val === '×') {
                $months[$m] = true;
            }
        }
    }
    return count($months);
}

function wi_list_checks(PDO $db, int $year, ?string $itemCode = null): array
{
    if ($itemCode !== null) {
        $stmt = $db->prepare('SELECT * FROM wi_checks WHERE year = ? AND item_code = ? ORDER BY check_date DESC, id DESC');
        $stmt->execute([$year, $itemCode]);
    } else {
        $stmt = $db->prepare('SELECT * FROM wi_checks WHERE year = ? ORDER BY item_code ASC, check_date DESC, id DESC');
        $stmt->execute([$year]);
    }
    return $stmt->fetchAll();
}

function wi_list_evidence(PDO $db, int $year, ?string $itemCode = null): array
{
    if ($itemCode !== null) {
        $stmt = $db->prepare('SELECT * FROM wi_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL ORDER BY id DESC');
        $stmt->execute([$year, $itemCode]);
    } else {
        $stmt = $db->prepare('SELECT * FROM wi_evidence WHERE year = ? AND deleted_at IS NULL ORDER BY item_code ASC, id DESC');
        $stmt->execute([$year]);
    }
    return $stmt->fetchAll();
}

function wi_save_photo_file(?array $file, string $base64Data, string $prefix): string
{
    $dir = wi_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('사진 저장소를 만들 수 없습니다.');
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($base64Data !== '' && preg_match('/^data:(image\/(?:png|jpeg|jpg|webp));base64,(.+)$/s', trim($base64Data), $m)) {
        $raw = base64_decode($m[2], true);
        if ($raw === false || strlen($raw) < 16 || strlen($raw) > 15 * 1024 * 1024) {
            throw new InvalidArgumentException('붙여넣기 이미지 크기를 확인해 주세요 (최대 15MB).');
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || !isset($extMap[$info['mime'] ?? ''])) {
            throw new InvalidArgumentException('유효한 이미지(PNG/JPG/WEBP)가 아닙니다.');
        }
        $ext = $extMap[$info['mime']];
        $fileName = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(8)), $ext);
        if (file_put_contents($dir . '/' . $fileName, $raw) === false) {
            throw new RuntimeException('붙여넣기 사진 저장에 실패했습니다.');
        }
        return $fileName;
    }

    if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        if ((int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new InvalidArgumentException('15MB 이하의 이미지 파일만 업로드할 수 있습니다.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!isset($extMap[$mime]) || @getimagesize((string)$file['tmp_name']) === false) {
            throw new InvalidArgumentException('JPG, PNG, WEBP 이미지 파일만 업로드할 수 있습니다.');
        }
        $fileName = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(8)), $extMap[$mime]);
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $fileName)) {
            throw new RuntimeException('사진 파일 저장에 실패했습니다.');
        }
        return $fileName;
    }

    return '';
}

function wi_upload_evidence(PDO $db, int $year, string $itemCode, string $kind, ?array $file, string $base64Data, string $note, string $actor): void
{
    $kinds = wi_evidence_kinds();
    if (!isset($kinds[$itemCode][$kind])) {
        throw new InvalidArgumentException('증빙 항목 및 구분을 확인해 주세요.');
    }
    $dir = wi_evidence_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    }

    if ($base64Data !== '' && preg_match('/^data:(image\/(?:png|jpeg|jpg|webp));base64,(.+)$/s', trim($base64Data), $m)) {
        $raw = base64_decode($m[2], true);
        if ($raw === false || strlen($raw) < 16 || strlen($raw) > 20 * 1024 * 1024) {
            throw new InvalidArgumentException('붙여넣기 이미지 크기를 확인해 주세요 (최대 20MB).');
        }
        $info = @getimagesizefromstring($raw);
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'png'];
        if ($info === false || !isset($extMap[$info['mime'] ?? ''])) {
            throw new InvalidArgumentException('유효한 이미지가 아닙니다.');
        }
        $ext = $extMap[$info['mime']];
        $stored = bin2hex(random_bytes(20)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $stored, $raw) === false) {
            throw new RuntimeException('스크린샷 증빙 저장에 실패했습니다.');
        }
        $origName = sprintf('스크린샷_%s_%s.%s', $itemCode, date('Ymd_His'), $ext);
        $db->prepare('INSERT INTO wi_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$year, $itemCode, $kind, $origName, $stored, (string)$info['mime'], strlen($raw), $note, $actor, date('Y-m-d H:i:s')]);
        return;
    }

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? '')) || (int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new InvalidArgumentException('20MB 이하의 증빙 파일을 선택하거나 스크린샷을 붙여넣어 주세요.');
    }
    $origName = mb_substr(basename(str_replace('\\', '/', (string)$file['name'])), 0, 180);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
        throw new InvalidArgumentException('PDF, JPG, PNG, XLSX, XLS, DOCX 파일만 첨부할 수 있습니다.');
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && @getimagesize((string)$file['tmp_name']) === false) {
        throw new InvalidArgumentException('이미지 파일을 확인해 주세요.');
    }
    if (in_array($ext, ['xlsx', 'docx'], true)) {
        $zip = new ZipArchive();
        if ($zip->open((string)$file['tmp_name']) !== true) {
            throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
        }
        $expected = $ext === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($expected) === false) {
            $zip->close();
            throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
        }
        $zip->close();
    }
    $stored = bin2hex(random_bytes(20)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('증빙 파일 저장에 실패했습니다.');
    }
    $db->prepare('INSERT INTO wi_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$year, $itemCode, $kind, $origName, $stored, $mime, (int)$file['size'], $note, $actor, date('Y-m-d H:i:s')]);
}

function csm_wi_score_summary(int $year, string $itemCode, float $points = 2.0): array
{
    $empty = [
        'earned' => 0.0,
        'evidence_count' => 0,
        'state' => 'no_evidence',
        'multiplier' => 0.0,
        'revision' => 0,
        'automatic' => true,
        'assessment' => null,
    ];
    $dbPath = wi_db_path();
    if (!is_file($dbPath)) {
        return $empty;
    }
    try {
        $db = new PDO('sqlite:file:' . str_replace('\\', '/', $dbPath) . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $evCount = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wi_evidence'")->fetch()) {
            $q = $db->prepare('SELECT stored_name FROM wi_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL');
            $q->execute([$year, $itemCode]);
            foreach ($q->fetchAll() as $row) {
                $s = (string)$row['stored_name'];
                if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|xls|docx)$/D', $s)) {
                    $p = wi_evidence_dir() . '/' . $s;
                    if (is_file($p) && filesize($p) > 0) {
                        $evCount++;
                    }
                }
            }
        }
        // Also count valid field inspection photos attached to wi_checks for this item_code
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wi_checks'")->fetch()) {
            $pq = $db->prepare('SELECT photo1, photo2 FROM wi_checks WHERE year = ? AND item_code = ?');
            $pq->execute([$year, $itemCode]);
            foreach ($pq->fetchAll() as $prow) {
                foreach (['photo1', 'photo2'] as $col) {
                    $fn = (string)($prow[$col] ?? '');
                    if ($fn !== '' && preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn)) {
                        $p = wi_photo_dir() . '/' . $fn;
                        if (is_file($p) && filesize($p) > 0) {
                            $evCount++;
                        }
                    }
                }
            }
        }
        $assessment = null;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wi_assessments'")->fetch()) {
            $aq = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM wi_assessments WHERE year = ? AND item_code = ?');
            $aq->execute([$year, $itemCode]);
            $assessment = $aq->fetch() ?: null;
        }
        if ($evCount === 0) {
            return array_merge($empty, ['assessment' => $assessment, 'automatic' => $assessment === null]);
        }
        if ($assessment !== null) {
            $mult = (float)$assessment['multiplier'];
            return [
                'earned' => round($points * $mult, 2),
                'evidence_count' => $evCount,
                'state' => 'assessed',
                'multiplier' => $mult,
                'revision' => 0,
                'automatic' => false,
                'assessment' => $assessment,
            ];
        }
        return [
            'earned' => round($points * 1.0, 2),
            'evidence_count' => $evCount,
            'state' => 'assessed',
            'multiplier' => 1.0,
            'revision' => 0,
            'automatic' => true,
            'assessment' => null,
        ];
    } catch (Throwable $e) {
        error_log('csm_wi_score_summary error: ' . $e->getMessage());
        return $empty;
    }
}
