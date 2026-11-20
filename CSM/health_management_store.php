<?php
declare(strict_types=1);

function hmg_db_path(): string
{
    return __DIR__ . '/storage/health_management.sqlite';
}

function hmg_evidence_dir(): string
{
    return __DIR__ . '/storage/health_management_evidence';
}

function hmg_photo_dir(): string
{
    return __DIR__ . '/storage/health_management_photos';
}

function hmg_item_meta(): array
{
    return [
        '2.8.1' => [
            'code' => '2.8.1',
            'tab' => 'checkup',
            'title' => '일반/특수 건강검진 법적 요건 준수 실시',
            'short' => '일반·특수 건강검진',
            'points' => 2.0,
            'evidence_guide' => '건강검진 관리대장, 일반/특수 건강검진 결과표 및 사후관리 기록',
        ],
        '2.8.2' => [
            'code' => '2.8.2',
            'tab' => 'env',
            'title' => '작업환경측정 법적 요건 준수 실시 및 결과 개선',
            'short' => '작업환경측정',
            'points' => 2.0,
            'evidence_guide' => '작업환경측정 결과표, 노출기준 초과/개선 필요 시 개선조치 증빙',
        ],
        '2.8.3' => [
            'code' => '2.8.3',
            'tab' => 'msds',
            'title' => '취급 화학물질 목록 및 MSDS 최신본 비치·업데이트',
            'short' => '화학물질·MSDS 최신본',
            'points' => 2.0,
            'evidence_guide' => '취급 화학물질 목록(관리대장), MSDS 최신본 비치 확인 자료',
        ],
        '2.8.4' => [
            'code' => '2.8.4',
            'tab' => 'chem_check',
            'title' => '화학물질 적정 보관 및 경고표지(MSDS-GHS) 설치',
            'short' => '화학물질 보관·GHS 표지',
            'points' => 2.0,
            'evidence_guide' => '화학물질 보관장소·GHS 경고표지 현장 점검표 및 현장 확인 사진',
        ],
        '2.8.5' => [
            'code' => '2.8.5',
            'tab' => 'first_aid',
            'title' => '응급처치 장비(구호용품) 및 의약품 비치·상태 유지',
            'short' => '응급처치 장비·의약품',
            'points' => 2.0,
            'evidence_guide' => '응급처치장비(구호용품) 및 의약품 관리대장, 월간 점검표·현장 사진',
        ],
    ];
}

function hmg_evidence_kinds(): array
{
    return [
        '2.8.1' => [
            'checkup_list' => '건강검진 대상자·실시 관리대장',
            'checkup_result' => '일반/특수 건강검진 결과표(요약)',
            'followup' => '유소견자 사후관리·상담 증빙',
            'etc_281' => '기타 건강검진 증빙',
        ],
        '2.8.2' => [
            'env_result' => '작업환경측정 결과보고서/결과표',
            'env_action' => '작업환경 개선조치 증빙',
            'env_notice' => '측정결과 근로자 공유·게시 사진',
            'etc_282' => '기타 작업환경측정 증빙',
        ],
        '2.8.3' => [
            'msds_list' => '화학물질·MSDS 관리대장',
            'msds_file' => 'MSDS 최신본 비치 사진/사본',
            'etc_283' => '기타 MSDS 관리 증빙',
        ],
        '2.8.4' => [
            'chem_storage_photo' => '화학물질 보관함·보관상태 사진',
            'ghs_label_photo' => 'MSDS-GHS 경고표지 부착 사진',
            'chem_check_sheet' => '화학물질 보관·GHS 점검표',
            'etc_284' => '기타 화학물질 보관 증빙',
        ],
        '2.8.5' => [
            'aid_list' => '의약품·응급장비 관리대장',
            'aid_photo' => '구급함·응급처치장비 비치 사진',
            'aid_check_sheet' => '월간 유효기간·수량 점검표',
            'etc_285' => '기타 구급용품 증빙',
        ],
    ];
}

function hmg_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(hmg_db_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . hmg_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hmg_checkups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            worker_name TEXT NOT NULL,
            dept_name TEXT NOT NULL DEFAULT '공사부',
            job_type TEXT NOT NULL DEFAULT '비사무직',
            exam_type TEXT NOT NULL DEFAULT '일반+특수',
            hazard_factors TEXT NOT NULL DEFAULT '소음, 분진, 용접흄',
            exam_date TEXT NOT NULL DEFAULT '',
            hospital TEXT NOT NULL DEFAULT '근로복지공단 동해병원',
            general_grade TEXT NOT NULL DEFAULT 'A',
            special_grade TEXT NOT NULL DEFAULT 'A',
            work_fitness TEXT NOT NULL DEFAULT '가',
            result_grade TEXT NOT NULL DEFAULT '정상(A/B)',
            finding_summary TEXT NOT NULL DEFAULT '',
            followup_note TEXT NOT NULL DEFAULT '',
            followup_done INTEGER NOT NULL DEFAULT 0,
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS hmg_env_measurements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            half_label TEXT NOT NULL DEFAULT '상반기',
            measure_date TEXT NOT NULL,
            agency TEXT NOT NULL DEFAULT '',
            work_area TEXT NOT NULL DEFAULT '',
            hazard_factors TEXT NOT NULL DEFAULT '',
            result_status TEXT NOT NULL DEFAULT '적합(노출기준 미만)',
            action_note TEXT NOT NULL DEFAULT '',
            action_done_date TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS hmg_chemicals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sort_order INTEGER NOT NULL DEFAULT 10,
            chem_name TEXT NOT NULL,
            manufacturer TEXT NOT NULL DEFAULT '',
            usage_process TEXT NOT NULL DEFAULT '',
            storage_place TEXT NOT NULL DEFAULT '',
            msds_rev_date TEXT NOT NULL DEFAULT '',
            msds_placed TEXT NOT NULL DEFAULT '비치완료',
            ghs_labeled TEXT NOT NULL DEFAULT '부착완료',
            hazard_class TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS hmg_chem_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            check_date TEXT NOT NULL,
            inspector TEXT NOT NULL DEFAULT '김남균',
            location TEXT NOT NULL DEFAULT '현대기전 자재창고 화학물질 보관함',
            chk_msds_latest TEXT NOT NULL DEFAULT '적합',
            chk_ghs_label TEXT NOT NULL DEFAULT '적합',
            chk_small_container TEXT NOT NULL DEFAULT '적합',
            chk_vent_lock TEXT NOT NULL DEFAULT '적합',
            chk_spill_kit TEXT NOT NULL DEFAULT '적합',
            chk_segregation TEXT NOT NULL DEFAULT '적합',
            overall_result TEXT NOT NULL DEFAULT '적합',
            issue_note TEXT NOT NULL DEFAULT '',
            action_note TEXT NOT NULL DEFAULT '',
            photo1 TEXT NOT NULL DEFAULT '',
            photo2 TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS hmg_first_aid_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            check_date TEXT NOT NULL,
            inspector TEXT NOT NULL DEFAULT '김남균',
            location TEXT NOT NULL DEFAULT '본사사무실 / 현장사무실 / 가스사무실 (3SET)',
            set_count TEXT NOT NULL DEFAULT '3 SET',
            chk_med_stock TEXT NOT NULL DEFAULT '적합',
            chk_expiry TEXT NOT NULL DEFAULT '적합',
            chk_equip_state TEXT NOT NULL DEFAULT '적합',
            chk_ledger TEXT NOT NULL DEFAULT '적합',
            overall_result TEXT NOT NULL DEFAULT '적합',
            refill_note TEXT NOT NULL DEFAULT '',
            photo1 TEXT NOT NULL DEFAULT '',
            photo2 TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS hmg_evidence (
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

        CREATE TABLE IF NOT EXISTS hmg_assessments (
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            multiplier REAL NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (year, item_code)
        );
    ");

    hmg_ensure_checkup_columns($pdo);
    hmg_seed_defaults($pdo);
    return $pdo;
}

function hmg_ensure_checkup_columns(PDO $db): void
{
    $cols = array_column($db->query('PRAGMA table_info(hmg_checkups)')->fetchAll(), 'name');
    $adds = [
        'dept_name' => "TEXT NOT NULL DEFAULT '공사부'",
        'general_grade' => "TEXT NOT NULL DEFAULT 'A'",
        'special_grade' => "TEXT NOT NULL DEFAULT 'A'",
        'work_fitness' => "TEXT NOT NULL DEFAULT '가'",
        'finding_summary' => "TEXT NOT NULL DEFAULT ''",
        'followup_done' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($adds as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $db->exec("ALTER TABLE hmg_checkups ADD COLUMN {$col} {$def}");
        }
    }
}

function hmg_seed_defaults(PDO $db): void
{
    $now = date('Y-m-d H:i:s');
    $ckCnt = (int)$db->query('SELECT COUNT(*) FROM hmg_checkups WHERE year = 2026')->fetchColumn();
    if ($ckCnt === 0) {
        $rows2026 = [
            // [worker_name, dept, general_grade, special_grade, work_fitness, hazard_factors, result_grade, finding_summary, followup_note, followup_done]
            ['김도담', '가스유지팀/전기공사', 'C', 'DN / CN', '나', '소음, 금속분진, 광물성분진, 야간작업', '유소견(DN)', '일반: 당뇨병의심(근무중치료) / 특수: 야간작업 당뇨병의심(DN)·이상지질혈증주의(CN)', '내과 진료·추적관찰 안내 (2027-03 추적검사 예정)', 0],
            ['김동훈', '가스측정팀', 'C', 'DN / C2', '나', '소음, 금속분진, 광물성분진, 야간작업', '유소견(DN)', '일반: 고혈압의심·비만·간장질환주의 / 특수: 야간작업 고혈압의심(DN)·구리 간장질환주의(C2)', '내과 진료·보호구착용·추적검사 안내 (2027-03-09 경)', 0],
            ['박재민', '가스측정팀', 'A', 'A', '가', '소음, 금속분진, 광물성분진, 야간작업', '정상(A/B)', '일반: 고혈압관리·당뇨전단계(A) / 특수: 전 항목 정상(A)', '생활습관 관리 안내 (정상)', 1],
            ['이윤성', '가스측정팀', 'C', 'DN / CN / C2', '나', '소음, 금속분진, 광물성분진, 야간작업', '유소견(DN)', '일반: 고혈압의심·비만·간장질환주의 / 특수: 야간작업 고혈압의심(DN)·이상지질혈증(CN)·구리 간장주의(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 보호구착용·추적검사', 1],
            ['장진혁', '가스측정팀', 'D2 / C', 'DN / C2', '나', '소음, 금속분진, 광물성분진, 야간작업', '유소견(D2/DN)', '일반: 고혈압·당뇨 관리적절(D2)·비만 / 특수: 야간작업 당뇨·고혈압 치료중(DN)·구리 간장주의(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 지속 투약·추적검사', 1],
            ['정재호', '가스측정팀', 'C / A', 'A', '나', '소음, 금속분진, 광물성분진, 야간작업', '요관찰(C)', '일반: 비만·복부비만(C)·고혈압관리(A) / 특수: 전 항목 정상(A)', '식이·운동요법 건강상담 안내', 0],
            ['김남균', '공사부', 'C', 'C2', '나', '소음, 망간·산화아연·산화철·알루미늄·광물성분진', '요관찰(C2)', '일반: 국소 폐섬유화·무기폐·고혈압의심(C) / 특수: 분진·금속 국소 폐섬유화·무기폐(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 호흡기 보호구착용·추적검사(2027-03-12)', 1],
            ['김종훈', '공사부', 'D2 / C', 'C1 / C2', '나', '소음, 금속분진, 광물성분진', '유소견(D2/C1)', '일반: 고혈압 관리필요(D2)·심장비대증(C) / 특수: 소음성난청주의 좌·우(C1)·심장비대증(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 청력보호구 착용 철저·추적검사(2027-03-18)', 1],
            ['엄기준', '공사부', 'D2 / C', 'D1 / C2', '나', '소음, 금속분진, 광물성분진', '직업병유소견(D1)', '일반: 고혈압 관리필요(D2)·청력저하·폐기능이상 / 특수: 소음성난청 좌·우(D1)·제한성환기장애·무기폐(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 청력·호흡기 보호구 착용 철저·추적검사(2027-03-18)', 1],
            ['윤순형', '공사부', 'D2 / C', 'C1 / C2', '나', '소음, 진동, 금속분진, 광물성분진, 자외선, 용접흄', '유소견(D2/C1)', '일반: 고혈압 관리적절·당뇨 관리필요(D2) / 특수: 소음성난청주의 좌·우(C1)·폐쇄성환기장애·간장주의(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 청력·호흡기 보호구 착용·추적검사(2027-03-18)', 1],
            ['윤장회', '공사부', 'A', 'C1 / C2', '나', '소음, 금속분진, 광물성분진', '요관찰(C1)', '일반: 비만·당뇨전단계·고혈압관리(A) / 특수: 소음성난청주의 좌·우(C1)·폐쇄성환기장애 중등도(C2)', '청력·호흡기 보호구 착용 지도 및 정기 추적검사(2027-03-18 경)', 0],
            ['윤택천', '공사부', 'C', 'D2 / C1 / C2', '나', '소음, 진동, 금속분진, 광물성분진, 자외선, 용접흄', '유소견(D2/C1)', '일반: 콜레스테롤·간장질환주의·만성기관지염(C) / 특수: 구리 간장질환(D2)·소음성난청주의(C1)·만성기관지염(C2)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 내과진찰·보호구착용·추적검사(2027-03-20)', 1],
            ['이돈희', '공사부', 'C / A', 'C2', '나', '소음, 톨루엔, 2-부톡시에탄올, 아세톤, 금속·광물성분진', '요관찰(C2)', '일반: 비만·복부비만(C) / 특수: 2-부톡시에탄올 망상적혈구수 증가(C2)', '보호구 착용·건강상담 및 주기적 혈액 추적관찰 안내', 0],
            ['이정민', '공사부', 'A', 'A', '가', '소음, 톨루엔, 2-부톡시에탄올, 아세톤, 금속·광물성분진', '정상(A/B)', '일반: 고혈압·비만관리(A) / 특수: 전 항목 정상(A)', '생활습관 관리 안내 (정상)', 1],
            ['한지민', '공사부', 'A', 'A', '가', '소음, 톨루엔, 2-부톡시에탄올, 아세톤, 금속·광물성분진', '정상(A/B)', '일반: 정상(A) / 특수: 전 항목 정상(A)', '특이사항 없음 (정상)', 1],
            ['진종철', '공사팀/전기공사', 'A', 'C1', '나', '소음, 금속분진, 광물성분진', '요관찰(C1)', '일반: 당뇨전단계·고혈압·비만관리(A) / 특수: 소음성난청주의 좌·우(C1)', '2026-09-17 강원근로자건강센터 사후관리 상담 완료 / 청력보호구 착용 철저·추적검사(2027-03-18)', 1],
        ];
        $stCk = $db->prepare('INSERT INTO hmg_checkups (year, worker_name, dept_name, job_type, exam_type, hazard_factors, exam_date, hospital, general_grade, special_grade, work_fitness, result_grade, finding_summary, followup_note, followup_done, created_by, created_at, updated_at) VALUES (2026, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows2026 as $r) {
            $stCk->execute([$r[0], $r[1], '비사무직', '일반+특수', $r[5], '2026-03-09', '근로복지공단 동해병원', $r[2], $r[3], $r[4], $r[6], $r[7], $r[8], $r[9], '시스템(2026 결과표)', $now, $now]);
        }
    }

    $cnt = (int)$db->query('SELECT COUNT(*) FROM hmg_chemicals')->fetchColumn();
    if ($cnt > 0) {
        return;
    }
    $chems = [
        [1, '락카 스프레이 (흑색·은색·적색)', '노루페인트/삼화페인트', '배관·트레이 도장 및 보수', '자재창고 화학물질 보관함', '2025-01-15', '인화성 에어로졸'],
        [2, '신나 (희석제)', '삼화페인트', '도장 희석 및 세척', '자재창고 화학물질 보관함', '2025-01-15', '인화성 액체'],
        [3, '용접봉 (피복아크·솔리드와이어)', '현대종합금속/고려용접봉', '전기·기계 구조물 용접', '자재창고 용접자재 구역', '2025-02-01', '용접흄·분진'],
        [4, '산업용 고압가스 (산소·LPG·질소)', '지역 고압가스 공급사', '절단·용접·퍼지 작업', '고압가스 전용 보관장소', '2025-02-01', '고압가스·인화성'],
        [5, '윤활유·절삭유·방청유 (WD-40 등)', 'SK/WD-40', '배관 나사가공 및 설비 정비', '자재창고 화학물질 보관함', '2025-01-20', '인화성·흡인유해'],
        [6, '절연세척제·접점부활제', 'CRC/3M', '전기판넬 및 기기 유지보수', '자재창고 화학물질 보관함', '2025-01-20', '인화성 에어로졸'],
    ];
    $stmt = $db->prepare('INSERT INTO hmg_chemicals (sort_order, chem_name, manufacturer, usage_process, storage_place, msds_rev_date, msds_placed, ghs_labeled, hazard_class, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
    foreach ($chems as $c) {
        $stmt->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], '비치완료', '부착완료', $c[6], $now, $now]);
    }
}

function hmg_load_linked_msds(): array
{
    $jsonPath = dirname(__DIR__) . '/risk_assessment/msds_records.json';
    $uploadDir = dirname(__DIR__) . '/risk_assessment/uploads/msds';
    if (!is_file($jsonPath)) {
        return [];
    }
    $raw = @file_get_contents($jsonPath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $r) {
        if (!is_array($r)) continue;
        $stored = basename((string)($r['stored_name'] ?? ''));
        $hasFile = $stored !== '' && is_file($uploadDir . '/' . $stored) && filesize($uploadDir . '/' . $stored) > 0;
        $out[] = [
            'id' => (string)($r['id'] ?? ''),
            'material_name' => (string)($r['material_name'] ?? $r['original_name'] ?? ''),
            'manufacturer' => (string)($r['manufacturer'] ?? ''),
            'created_date' => (string)($r['created_date'] ?? ''),
            'revised_date' => (string)($r['revised_date'] ?? ''),
            'revision_count' => (string)($r['revision_count'] ?? ''),
            'note' => (string)($r['note'] ?? ''),
            'original_name' => (string)($r['original_name'] ?? ''),
            'stored_name' => $stored,
            'has_file' => $hasFile,
        ];
    }
    return $out;
}

function hmg_save_photo_file(?array $file, string $base64Data, string $prefix): string
{
    $dir = hmg_photo_dir();
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
        $fileName = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(8)), $extMap[$info['mime']]);
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

function hmg_upload_evidence(PDO $db, int $year, string $itemCode, string $kind, ?array $file, string $base64Data, string $note, string $actor): void
{
    $kinds = hmg_evidence_kinds();
    if (!isset($kinds[$itemCode][$kind])) {
        throw new InvalidArgumentException('증빙 항목 및 구분을 확인해 주세요.');
    }
    $dir = hmg_evidence_dir();
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
        $db->prepare('INSERT INTO hmg_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
    $db->prepare('INSERT INTO hmg_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$year, $itemCode, $kind, $origName, $stored, $mime, (int)$file['size'], $note, $actor, date('Y-m-d H:i:s')]);
}

function csm_hmg_score_summary(int $year, string $itemCode, float $points = 2.0): array
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
    $dbPath = hmg_db_path();
    if (!is_file($dbPath)) {
        return $empty;
    }
    try {
        $db = new PDO('sqlite:file:' . str_replace('\\', '/', $dbPath) . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $evCount = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='hmg_evidence'")->fetch()) {
            $q = $db->prepare('SELECT stored_name FROM hmg_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL');
            $q->execute([$year, $itemCode]);
            foreach ($q->fetchAll() as $row) {
                $s = (string)$row['stored_name'];
                if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|xls|docx)$/D', $s)) {
                    $p = hmg_evidence_dir() . '/' . $s;
                    if (is_file($p) && filesize($p) > 0) {
                        $evCount++;
                    }
                }
            }
        }
        // For 2.8.3, also count valid linked MSDS PDF files from risk_assessment/msds_records.json
        if ($itemCode === '2.8.3') {
            foreach (hmg_load_linked_msds() as $msds) {
                if (!empty($msds['has_file'])) {
                    $evCount++;
                }
            }
        }
        // Count site photos for 2.8.4 and 2.8.5
        if ($itemCode === '2.8.4' && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='hmg_chem_checks'")->fetch()) {
            $pq = $db->prepare('SELECT photo1, photo2 FROM hmg_chem_checks WHERE year = ?');
            $pq->execute([$year]);
            foreach ($pq->fetchAll() as $prow) {
                foreach (['photo1', 'photo2'] as $col) {
                    $fn = (string)($prow[$col] ?? '');
                    if ($fn !== '' && preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn) && is_file(hmg_photo_dir() . '/' . $fn)) {
                        $evCount++;
                    }
                }
            }
        }
        if ($itemCode === '2.8.5' && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='hmg_first_aid_checks'")->fetch()) {
            $pq = $db->prepare('SELECT photo1, photo2 FROM hmg_first_aid_checks WHERE year = ?');
            $pq->execute([$year]);
            foreach ($pq->fetchAll() as $prow) {
                foreach (['photo1', 'photo2'] as $col) {
                    $fn = (string)($prow[$col] ?? '');
                    if ($fn !== '' && preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn) && is_file(hmg_photo_dir() . '/' . $fn)) {
                        $evCount++;
                    }
                }
            }
        }
        $assessment = null;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='hmg_assessments'")->fetch()) {
            $aq = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM hmg_assessments WHERE year = ? AND item_code = ?');
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
        error_log('csm_hmg_score_summary error: ' . $e->getMessage());
        return $empty;
    }
}
