<?php
declare(strict_types=1);

function wm_db_path(): string
{
    return __DIR__ . '/storage/waste_management.sqlite';
}

function wm_evidence_dir(): string
{
    return __DIR__ . '/storage/waste_management_evidence';
}

function wm_photo_dir(): string
{
    return __DIR__ . '/storage/waste_management_photos';
}

function wm_item_meta(): array
{
    return [
        '4.1.1' => [
            'code' => '4.1.1',
            'title' => '폐기물 배출 현황 파악 및 처리 프로세스 수립',
            'short' => '배출현황·처리프로세스',
            'points' => 1.0,
            'evidence_guide' => '폐기물 배출 현황 목록(List), 폐기물 처리 업무 Flow(프로세스), 인터뷰 및 관련 자료',
        ],
        '4.1.2' => [
            'code' => '4.1.2',
            'title' => '폐기물 배출처리 신고 및 관리대장 운영',
            'short' => '배출신고·관리대장',
            'points' => 1.0,
            'evidence_guide' => '폐기물 배출자 신고필증(또는 원청 일괄처리 확인), 위탁처리 계약서, 사업장폐기물처리 관리대장',
        ],
        '4.1.3' => [
            'code' => '4.1.3',
            'title' => '폐기물 적법 보관·표지판 설치 및 분리배출 관리',
            'short' => '보관표지·분리배출',
            'points' => 1.0,
            'evidence_guide' => '폐기물 보관 표지판 설치 사진, 분리수거함 분리·표기 사진, 정기 보관·분리배출 점검표',
        ],
    ];
}

function wm_evidence_kinds(): array
{
    return [
        '4.1.1' => [
            'process_doc' => '폐기물 처리 프로세스(업무 Flow)',
            'waste_list' => '폐기물 배출 현황 목록(List)',
            'etc_411' => '기타 관련 자료(인터뷰·교육 등)',
        ],
        '4.1.2' => [
            'report_cert' => '폐기물 배출자 신고필증 / 확인서',
            'contract' => '폐기물 위탁처리 계약서',
            'disposal_log' => '사업장폐기물처리 관리대장 / 인계서',
            'etc_412' => '기타 신고·계약 증빙',
        ],
        '4.1.3' => [
            'sign_photo' => '폐기물 보관표지판 설치 사진',
            'bin_photo' => '분리수거함 분리·표기 사진',
            'check_sheet' => '보관·분리배출 정기 점검표',
            'etc_413' => '기타 현장 보관·분리배출 증빙',
        ],
    ];
}

function wm_sign_themes(): array
{
    return [
        'general' => ['label' => '일반 폐기물 보관표지 (녹색)', 'title' => '일반 폐기물 보관표지', 'bg' => '#009245', 'fg' => '#ffffff', 'border' => '#ffffff'],
        'recycle' => ['label' => '재활용 보관표지 (노란색)', 'title' => '재활용 보관표지', 'bg' => '#fff200', 'fg' => '#111111', 'border' => '#111111'],
        'designated' => ['label' => '지정 폐기물 보관표지 (적색)', 'title' => '지정 폐기물 보관표지', 'bg' => '#c81e1e', 'fg' => '#ffffff', 'border' => '#ffffff'],
    ];
}

function wm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(wm_db_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . wm_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wm_waste_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            category TEXT NOT NULL DEFAULT '일반폐기물',
            waste_name TEXT NOT NULL,
            waste_code TEXT NOT NULL DEFAULT '',
            state_form TEXT NOT NULL DEFAULT '고체',
            source_process TEXT NOT NULL DEFAULT '',
            storage_place TEXT NOT NULL DEFAULT '',
            storage_capacity TEXT NOT NULL DEFAULT '',
            storage_period TEXT NOT NULL DEFAULT '90일간',
            characteristic TEXT NOT NULL DEFAULT '',
            manager_name TEXT NOT NULL DEFAULT '김남균',
            caution_storage TEXT NOT NULL DEFAULT '',
            caution_transport TEXT NOT NULL DEFAULT '',
            caution_disposal TEXT NOT NULL DEFAULT '',
            disposal_method TEXT NOT NULL DEFAULT '위탁처리',
            disposal_vendor TEXT NOT NULL DEFAULT '',
            sign_theme TEXT NOT NULL DEFAULT 'general',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS wm_process_doc (
            year INTEGER PRIMARY KEY,
            department TEXT NOT NULL DEFAULT '공사팀',
            manager_name TEXT NOT NULL DEFAULT '김남균',
            approver_name TEXT NOT NULL DEFAULT '정연탁',
            revision_date TEXT NOT NULL DEFAULT '',
            step1_title TEXT NOT NULL DEFAULT '1. 폐기물 발생 및 현장 선별',
            step1_desc TEXT NOT NULL DEFAULT '작업 중 발생한 폐기물을 생활폐기물, 재활용품, 사업장 일반폐기물(고철·폐전선), 지정폐기물로 즉시 분류한다.',
            step2_title TEXT NOT NULL DEFAULT '2. 지정 장소 적치 및 보관표지 관리',
            step2_desc TEXT NOT NULL DEFAULT '성상·종류별로 지정된 보관장소 및 분리수거함에 적치하고, 폐기물 보관표지판(종류·용량·관리책임자·주의사항)을 부착한다.',
            step3_title TEXT NOT NULL DEFAULT '3. 발생량 확인 및 관리대장 기록',
            step3_desc TEXT NOT NULL DEFAULT '배출·보관 현황을 확인하여 사업장폐기물처리 관리대장에 일자·발생량·누계를 기록한다.',
            step4_title TEXT NOT NULL DEFAULT '4. 적법 반출·위탁처리 및 인계 확인',
            step4_desc TEXT NOT NULL DEFAULT '지정된 수거·위탁처리 업체 또는 한라시멘트 內 폐기물 선별장으로 인계하고, 인계서(올바로/계량증명)를 보관한다.',
            step5_title TEXT NOT NULL DEFAULT '5. 정기 점검 및 사후관리',
            step5_desc TEXT NOT NULL DEFAULT '보관표지판 훼손, 분리수거함 혼합배출, 비산·유출 여부를 정기 점검하고 미흡 사항을 즉시 개선한다.',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS wm_reporting_info (
            year INTEGER PRIMARY KEY,
            report_status TEXT NOT NULL DEFAULT '해당(신고완료)',
            report_number TEXT NOT NULL DEFAULT '',
            report_date TEXT NOT NULL DEFAULT '',
            allbaro_id TEXT NOT NULL DEFAULT '',
            vendor_name TEXT NOT NULL DEFAULT '지정 고철·폐기물 수거 업체 / 한라시멘트 內 선별장',
            contract_period TEXT NOT NULL DEFAULT '',
            department TEXT NOT NULL DEFAULT '공사팀',
            manager_name TEXT NOT NULL DEFAULT '김남균',
            notes TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS wm_disposal_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            waste_type_id INTEGER NULL,
            waste_name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT '일반폐기물',
            unit TEXT NOT NULL DEFAULT 'kg',
            occur_date TEXT NOT NULL,
            occur_qty REAL NOT NULL DEFAULT 0,
            self_date TEXT NOT NULL DEFAULT '',
            self_qty REAL NOT NULL DEFAULT 0,
            self_method TEXT NOT NULL DEFAULT '',
            consign_date TEXT NOT NULL DEFAULT '',
            consign_qty REAL NOT NULL DEFAULT 0,
            consign_method TEXT NOT NULL DEFAULT '',
            consign_vendor TEXT NOT NULL DEFAULT '',
            manifest_no TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS wm_storage_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            check_date TEXT NOT NULL,
            inspector TEXT NOT NULL DEFAULT '김남균',
            location TEXT NOT NULL DEFAULT '현대기전 자재창고 및 현장 폐기물 보관장소',
            chk_sign_installed TEXT NOT NULL DEFAULT '적합',
            chk_bin_labeled TEXT NOT NULL DEFAULT '적합',
            chk_segregated TEXT NOT NULL DEFAULT '적합',
            chk_containment TEXT NOT NULL DEFAULT '적합',
            chk_capacity_period TEXT NOT NULL DEFAULT '적합',
            chk_housekeeping TEXT NOT NULL DEFAULT '적합',
            overall_result TEXT NOT NULL DEFAULT '적합',
            issue_note TEXT NOT NULL DEFAULT '',
            action_note TEXT NOT NULL DEFAULT '',
            photo_sign TEXT NOT NULL DEFAULT '',
            photo_bin TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS wm_evidence (
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

        CREATE TABLE IF NOT EXISTS wm_assessments (
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            multiplier REAL NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (year, item_code)
        );
    ");

    wm_seed_defaults($pdo);
    return $pdo;
}

function wm_seed_defaults(PDO $db): void
{
    $cnt = (int)$db->query('SELECT COUNT(*) FROM wm_waste_types')->fetchColumn();
    if ($cnt > 0) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $seeds = [
        [
            'sort_order' => 1,
            'category' => '일반폐기물',
            'waste_name' => '고철',
            'waste_code' => '51-29-01',
            'state_form' => '고체',
            'source_process' => '전기·기계 배관, 트레이 및 구조물 철거 작업',
            'storage_place' => '현대기전 지정 폐기물 보관장소 (고철 구역)',
            'storage_capacity' => '5 톤',
            'storage_period' => '90일간',
            'characteristic' => '부식성',
            'manager_name' => '김남균',
            'caution_storage' => '철재류 외의 재질이 섞이지 않도록 한다.',
            'caution_transport' => '모서리가 날카로울 수 있어 찰과상이 발생할 수 있으므로 보호구 착용 후 운반하거나, 장비로 운반한다.',
            'caution_disposal' => '지정된 고철 수거 업체로 적법 인계한다.',
            'disposal_method' => '위탁처리(재활용 매각)',
            'disposal_vendor' => '지정된 고철 수거 업체',
            'sign_theme' => 'general',
        ],
        [
            'sort_order' => 2,
            'category' => '일반폐기물',
            'waste_name' => '폐전선',
            'waste_code' => '51-29-02',
            'state_form' => '고체',
            'source_process' => '동력·제어 케이블 포설, 단말처리 및 철거 작업',
            'storage_place' => '현대기전 지정 폐기물 보관장소 (폐전선 구역)',
            'storage_capacity' => '1 톤',
            'storage_period' => '90일간',
            'characteristic' => '부식성',
            'manager_name' => '김남균',
            'caution_storage' => '전선류 외의 재질이 섞이지 않도록 한다.',
            'caution_transport' => '중량물에 의한 근골격계 질환이 발생할 수 있으니 인력운반 시 적절한 길이로 절단하여 운반하거나, 장비로 운반한다.',
            'caution_disposal' => '지정된 고철·폐전선 수거 업체로 적법 인계한다.',
            'disposal_method' => '위탁처리(재활용 매각)',
            'disposal_vendor' => '지정된 고철 수거 업체',
            'sign_theme' => 'general',
        ],
        [
            'sort_order' => 3,
            'category' => '재활용',
            'waste_name' => '재활용 용기 (포장재·캔·페트병)',
            'waste_code' => '51-03-01',
            'state_form' => '고체',
            'source_process' => '자재 포장 개봉 및 현장 휴게실·사무실',
            'storage_place' => '현장 분리수거함 (재활용 마대)',
            'storage_capacity' => '100 kg',
            'storage_period' => '30일간',
            'characteristic' => '가연성 / 재활용',
            'manager_name' => '김남균',
            'caution_storage' => '내용물을 완전히 제거한 상태에서 보관한다. 직사광선에 노출되어 부식된 보관마대는 교체한다. 화기의 접근을 금지한다.',
            'caution_transport' => '보관마대가 찢어져 있는지 확인한다.',
            'caution_disposal' => '지정된 장소(한라시멘트 內 폐기물 선별장)에 처리한다.',
            'disposal_method' => '원청 선별장 분리배출',
            'disposal_vendor' => '한라시멘트 內 폐기물 선별장',
            'sign_theme' => 'recycle',
        ],
        [
            'sort_order' => 4,
            'category' => '생활폐기물',
            'waste_name' => '일반 생활폐기물 (종량제)',
            'waste_code' => '생활',
            'state_form' => '고체',
            'source_process' => '사무실·휴게실 및 현장 정리정돈',
            'storage_place' => '현장 분리수거함 (일반쓰레기)',
            'storage_capacity' => '200 kg',
            'storage_period' => '15일간',
            'characteristic' => '일반 가연성',
            'manager_name' => '김남균',
            'caution_storage' => '재활용품 및 사업장 폐기물과 혼합되지 않도록 종량제 봉투에 분리 보관한다.',
            'caution_transport' => '봉투 파손 및 비산이 없도록 밀봉하여 운반한다.',
            'caution_disposal' => '한라시멘트 內 지정 배출장소에 정기 배출한다.',
            'disposal_method' => '지정 배출장소 처리',
            'disposal_vendor' => '한라시멘트 內 폐기물 선별장',
            'sign_theme' => 'general',
        ],
        [
            'sort_order' => 5,
            'category' => '지정폐기물',
            'waste_name' => '폐형광등·폐건전지·폐유걸레',
            'waste_code' => '06-01-00',
            'state_form' => '고체',
            'source_process' => '조명기구 교체, 계측기 정비 및 장비 유지보수',
            'storage_place' => '지정폐기물 전용 밀폐 보관함',
            'storage_capacity' => '50 kg',
            'storage_period' => '45일간',
            'characteristic' => '유해성 / 파손주의',
            'manager_name' => '김남균',
            'caution_storage' => '파손 및 누출 방지를 위해 전용 밀폐용기에 보관하고 타 폐기물과 혼합 금지한다.',
            'caution_transport' => '충격에 의한 파손 주의 및 보호장갑 착용 후 운반한다.',
            'caution_disposal' => '지정폐기물 전문 처리업체 또는 한라시멘트 지정 수거함에 인계한다.',
            'disposal_method' => '전문 위탁 / 전용수거함',
            'disposal_vendor' => '지정폐기물 처리업체 (또는 원청 환경팀 인계)',
            'sign_theme' => 'designated',
        ],
    ];
    $stmt = $db->prepare('INSERT INTO wm_waste_types
        (sort_order, category, waste_name, waste_code, state_form, source_process, storage_place, storage_capacity, storage_period, characteristic, manager_name, caution_storage, caution_transport, caution_disposal, disposal_method, disposal_vendor, sign_theme, active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
    foreach ($seeds as $s) {
        $stmt->execute([
            $s['sort_order'], $s['category'], $s['waste_name'], $s['waste_code'], $s['state_form'],
            $s['source_process'], $s['storage_place'], $s['storage_capacity'], $s['storage_period'],
            $s['characteristic'], $s['manager_name'], $s['caution_storage'], $s['caution_transport'],
            $s['caution_disposal'], $s['disposal_method'], $s['disposal_vendor'], $s['sign_theme'],
            $now, $now,
        ]);
    }
}

function wm_get_process_doc(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM wm_process_doc WHERE year = ?');
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $now = date('Y-m-d H:i:s');
    $revDate = $year . '-01-02';
    $db->prepare('INSERT OR IGNORE INTO wm_process_doc (year, revision_date, updated_at) VALUES (?, ?, ?)')
       ->execute([$year, $revDate, $now]);
    $stmt->execute([$year]);
    return $stmt->fetch() ?: [];
}

function wm_get_reporting_info(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM wm_reporting_info WHERE year = ?');
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT OR IGNORE INTO wm_reporting_info (year, contract_period, updated_at) VALUES (?, ?, ?)')
       ->execute([$year, $year . '-01-01 ~ ' . $year . '-12-31', $now]);
    $stmt->execute([$year]);
    return $stmt->fetch() ?: [];
}

function wm_list_waste_types(PDO $db, bool $onlyActive = true): array
{
    $sql = $onlyActive
        ? 'SELECT * FROM wm_waste_types WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        : 'SELECT * FROM wm_waste_types ORDER BY active DESC, sort_order ASC, id ASC';
    return $db->query($sql)->fetchAll();
}

function wm_list_disposal_logs(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM wm_disposal_log WHERE year = ? ORDER BY occur_date ASC, id ASC');
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll();
    // Calculate cumulative quantities per waste_name + unit
    $cumOccur = [];
    $cumSelf = [];
    $cumConsign = [];
    foreach ($rows as &$r) {
        $key = $r['waste_name'] . '|' . $r['unit'];
        $cumOccur[$key] = ($cumOccur[$key] ?? 0.0) + (float)$r['occur_qty'];
        $cumSelf[$key] = ($cumSelf[$key] ?? 0.0) + (float)$r['self_qty'];
        $cumConsign[$key] = ($cumConsign[$key] ?? 0.0) + (float)$r['consign_qty'];
        $r['cum_occur'] = round($cumOccur[$key], 2);
        $r['cum_self'] = round($cumSelf[$key], 2);
        $r['cum_consign'] = round($cumConsign[$key], 2);
    }
    unset($r);
    return $rows;
}

function wm_list_storage_checks(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM wm_storage_checks WHERE year = ? ORDER BY check_date DESC, id DESC');
    $stmt->execute([$year]);
    return $stmt->fetchAll();
}

function wm_list_evidence(PDO $db, int $year, ?string $itemCode = null): array
{
    if ($itemCode !== null) {
        $stmt = $db->prepare('SELECT * FROM wm_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL ORDER BY id DESC');
        $stmt->execute([$year, $itemCode]);
    } else {
        $stmt = $db->prepare('SELECT * FROM wm_evidence WHERE year = ? AND deleted_at IS NULL ORDER BY item_code ASC, id DESC');
        $stmt->execute([$year]);
    }
    return $stmt->fetchAll();
}

function wm_save_photo_file(?array $file, string $base64Data, string $prefix): string
{
    $dir = wm_photo_dir();
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

function wm_upload_evidence(PDO $db, int $year, string $itemCode, string $kind, ?array $file, string $base64Data, string $note, string $actor): void
{
    $kinds = wm_evidence_kinds();
    if (!isset($kinds[$itemCode][$kind])) {
        throw new InvalidArgumentException('증빙 항목 및 구분을 확인해 주세요.');
    }
    $dir = wm_evidence_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    }

    // Handle pasted screenshot as PNG/JPG evidence
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
        $db->prepare('INSERT INTO wm_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
    $db->prepare('INSERT INTO wm_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$year, $itemCode, $kind, $origName, $stored, $mime, (int)$file['size'], $note, $actor, date('Y-m-d H:i:s')]);
}

function csm_wm_score_summary(int $year, string $itemCode, float $points = 1.0): array
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
    $dbPath = wm_db_path();
    if (!is_file($dbPath)) {
        return $empty;
    }
    try {
        $db = new PDO('sqlite:file:' . str_replace('\\', '/', $dbPath) . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $evCount = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wm_evidence'")->fetch()) {
            $q = $db->prepare('SELECT stored_name FROM wm_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL');
            $q->execute([$year, $itemCode]);
            foreach ($q->fetchAll() as $row) {
                $s = (string)$row['stored_name'];
                if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|xls|docx)$/D', $s)) {
                    $p = wm_evidence_dir() . '/' . $s;
                    if (is_file($p) && filesize($p) > 0) {
                        $evCount++;
                    }
                }
            }
        }
        // For 4.1.3, also count valid site photos attached to storage checks in the same year
        if ($itemCode === '4.1.3' && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wm_storage_checks'")->fetch()) {
            $pq = $db->prepare('SELECT photo_sign, photo_bin FROM wm_storage_checks WHERE year = ?');
            $pq->execute([$year]);
            foreach ($pq->fetchAll() as $prow) {
                foreach (['photo_sign', 'photo_bin'] as $col) {
                    $fn = (string)($prow[$col] ?? '');
                    if ($fn !== '' && preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn)) {
                        $p = wm_photo_dir() . '/' . $fn;
                        if (is_file($p) && filesize($p) > 0) {
                            $evCount++;
                        }
                    }
                }
            }
        }
        $assessment = null;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wm_assessments'")->fetch()) {
            $aq = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM wm_assessments WHERE year = ? AND item_code = ?');
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
        error_log('csm_wm_score_summary error: ' . $e->getMessage());
        return $empty;
    }
}
