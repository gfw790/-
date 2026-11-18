<?php
declare(strict_types=1);

function worker_part_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage directory');
    }
    $dbPath = $dir . '/worker_participation.sqlite';
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_suggestions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        reg_no TEXT NOT NULL,
        reg_date TEXT NOT NULL,
        proposer_name TEXT NOT NULL,
        proposer_dept TEXT NOT NULL DEFAULT "",
        title TEXT NOT NULL,
        category TEXT NOT NULL,
        location TEXT NOT NULL DEFAULT "",
        problem TEXT NOT NULL,
        solution TEXT NOT NULL,
        expected_effect TEXT NOT NULL DEFAULT "",
        status TEXT NOT NULL DEFAULT "접수",
        review_date TEXT DEFAULT NULL,
        reviewer_name TEXT DEFAULT NULL,
        review_opinion TEXT DEFAULT NULL,
        action_plan TEXT DEFAULT NULL,
        action_manager TEXT DEFAULT NULL,
        action_deadline TEXT DEFAULT NULL,
        action_date TEXT DEFAULT NULL,
        action_result TEXT DEFAULT NULL,
        feedback_date TEXT DEFAULT NULL,
        feedback_method TEXT DEFAULT NULL,
        reward_points REAL DEFAULT 0.0,
        reward_note TEXT DEFAULT NULL,
        photo_before TEXT DEFAULT NULL,
        photo_after TEXT DEFAULT NULL,
        proposer_sign TEXT DEFAULT NULL,
        evaluator_sign TEXT DEFAULT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_safety_meetings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        meeting_type TEXT NOT NULL,
        meeting_name TEXT NOT NULL,
        meeting_date TEXT NOT NULL,
        meeting_place TEXT NOT NULL,
        chairperson TEXT NOT NULL,
        attendees_text TEXT NOT NULL,
        attendees_count INTEGER NOT NULL DEFAULT 0,
        agenda TEXT NOT NULL,
        deliberation_content TEXT NOT NULL,
        decisions TEXT NOT NULL,
        action_items_json TEXT DEFAULT "[]",
        result_sharing TEXT DEFAULT NULL,
        attachment_path TEXT DEFAULT NULL,
        drafter_sign TEXT DEFAULT NULL,
        approver_sign TEXT DEFAULT NULL,
        signatures_json TEXT DEFAULT "[]",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_feedback_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        half INTEGER NOT NULL,
        report_title TEXT NOT NULL,
        report_date TEXT NOT NULL,
        manager_name TEXT NOT NULL,
        summary_opinions TEXT NOT NULL,
        action_status_summary TEXT NOT NULL,
        feedback_notice_summary TEXT NOT NULL,
        future_improvements TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "작성",
        manager_sign TEXT DEFAULT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(year, half)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_safety_champions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        month INTEGER NOT NULL,
        recipient_name TEXT NOT NULL,
        recipient_role TEXT NOT NULL,
        award_title TEXT NOT NULL DEFAULT "이달의 안전인",
        reason TEXT NOT NULL,
        score_near_miss INTEGER NOT NULL DEFAULT 0,
        score_suggestion INTEGER NOT NULL DEFAULT 0,
        score_attendance INTEGER NOT NULL DEFAULT 0,
        score_other INTEGER NOT NULL DEFAULT 0,
        total_score INTEGER NOT NULL DEFAULT 0,
        reward_content TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(year, month)
    )');

    // Dedicated Digital Signatures Log Table
    $db->exec('CREATE TABLE IF NOT EXISTS csm_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_type TEXT NOT NULL,
        doc_id INTEGER NOT NULL,
        signer_role TEXT NOT NULL,
        signer_name TEXT NOT NULL,
        signature_data TEXT NOT NULL,
        signed_at TEXT NOT NULL,
        ip_address TEXT DEFAULT "",
        created_at TEXT NOT NULL
    )');

    // Schema migration for existing databases
    try { $db->exec("ALTER TABLE csm_safety_meetings ADD COLUMN drafter_sign TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_safety_meetings ADD COLUMN approver_sign TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_safety_meetings ADD COLUMN signatures_json TEXT DEFAULT '[]'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN proposer_sign TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN evaluator_sign TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN proposer_phone TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN is_mobile_submit INTEGER DEFAULT 0"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN proposer_login_id TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN submission_channel TEXT DEFAULT '직접등록'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_suggestions ADD COLUMN proxy_writer_name TEXT DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_feedback_reports ADD COLUMN manager_sign TEXT DEFAULT NULL"); } catch (Throwable $e) {}

    worker_part_seed_defaults($db);
    return $db;
}

function worker_part_get_user_accounts(): array
{
    if (function_exists('auth_accounts')) {
        $accounts = auth_accounts();
        $list = [];
        foreach ($accounts as $loginId => $acc) {
            $name = trim((string)($acc['name'] ?? ''));
            if ($name === '' || !empty($acc['is_retired'])) {
                continue;
            }
            $team = trim((string)($acc['team'] ?? $acc['team_name'] ?? $acc['department'] ?? ''));
            $list[] = [
                'login_id' => (string)$loginId,
                'name' => $name,
                'dept' => $team,
                'team' => $team,
                'phone' => (string)($acc['phone'] ?? ''),
                'role' => (string)($acc['role'] ?? '')
            ];
        }
        usort($list, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $list;
    }
    return [];
}

function worker_part_get_teams(): array
{
    if (function_exists('auth_read_active_teams')) {
        $teams = auth_read_active_teams();
        if (!empty($teams)) {
            return $teams;
        }
    }
    return ['공사팀-전기', '공사팀-모터', '가스팀', '제조팀', '안전관리', '경영지원'];
}

function worker_part_make_signature_svg(string $name, string $color = '#1e3a8a'): string
{
    $cleanName = trim($name);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 110 44" width="110" height="44">
        <text x="55" y="28" font-family="Batang, Gungsuh, Malgun Gothic, serif" font-size="20" font-weight="bold" font-style="italic" fill="' . $color . '" text-anchor="middle" letter-spacing="4">' . htmlspecialchars($cleanName, ENT_QUOTES, 'UTF-8') . '</text>
        <circle cx="55" cy="22" r="18" fill="none" stroke="' . $color . '" stroke-width="1.2" stroke-dasharray="3 2" opacity="0.65"/>
    </svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function worker_part_save_uploaded_image(string $base64Data = '', ?array $fileData = null, string $prefix = 'sug'): ?string
{
    $uploadDir = __DIR__ . '/storage/suggestions';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        // Fallback
    }

    $base64Data = trim($base64Data);
    if ($base64Data !== '' && preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $base64Data)) {
        $parts = explode(',', $base64Data, 2);
        $imgData = base64_decode($parts[1] ?? '', true);
        if ($imgData !== false && strlen($imgData) > 50) {
            $fileName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
            $dest = $uploadDir . '/' . $fileName;
            @file_put_contents($dest, $imgData);
            // Return Base64 data directly for 100% self-contained DB storage (no 403 Forbidden issues)
            return $base64Data;
        }
    }

    if ($fileData !== null && isset($fileData['error']) && $fileData['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo((string)$fileData['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $raw = @file_get_contents((string)$fileData['tmp_name']);
            if ($raw !== false && strlen($raw) > 0) {
                $fileName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $uploadDir . '/' . $fileName;
                @move_uploaded_file((string)$fileData['tmp_name'], $dest);
                $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
                return 'data:' . $mime . ';base64,' . base64_encode($raw);
            }
        }
    }

    return null;
}

function worker_part_resolve_photo_data(?string $photo): string
{
    $photo = trim((string)$photo);
    if ($photo === '') {
        return '';
    }
    if (str_starts_with($photo, 'data:image/')) {
        return $photo;
    }
    $fullPath = __DIR__ . '/' . ltrim($photo, '/\\');
    if (file_exists($fullPath) && is_file($fullPath)) {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg'
        };
        $data = @file_get_contents($fullPath);
        if ($data !== false && strlen($data) > 0) {
            return 'data:' . $mime . ';base64,' . base64_encode($data);
        }
    }
    return $photo;
}

function worker_part_save_signature(PDO $db, string $docType, int $docId, string $role, string $name, string $sigData, string $ip = ''): void
{
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO csm_signatures (
        doc_type, doc_id, signer_role, signer_name, signature_data, signed_at, ip_address, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $docType, $docId, $role, $name, $sigData, $now, $ip, $now
    ]);

    if ($docType === 'meeting') {
        if ($role === 'drafter') {
            $db->prepare('UPDATE csm_safety_meetings SET drafter_sign = ?, updated_at = ? WHERE id = ?')->execute([$sigData, $now, $docId]);
        } elseif ($role === 'approver') {
            $db->prepare('UPDATE csm_safety_meetings SET approver_sign = ?, updated_at = ? WHERE id = ?')->execute([$sigData, $now, $docId]);
        } elseif ($role === 'attendee' || $role === 'opinion') {
            $currJson = $db->query("SELECT signatures_json FROM csm_safety_meetings WHERE id = $docId")->fetchColumn() ?: '[]';
            $signs = json_decode($currJson, true) ?: [];
            $found = false;
            foreach ($signs as &$s) {
                if ($s['name'] === $name) {
                    $s['sign'] = $sigData;
                    $s['signed_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset($s);
            if (!$found) {
                $signs[] = ['name' => $name, 'role' => $role, 'sign' => $sigData, 'signed_at' => $now];
            }
            $db->prepare('UPDATE csm_safety_meetings SET signatures_json = ?, updated_at = ? WHERE id = ?')
               ->execute([json_encode($signs, JSON_UNESCAPED_UNICODE), $now, $docId]);
        }
    } elseif ($docType === 'suggestion') {
        if ($role === 'proposer') {
            $db->prepare('UPDATE csm_suggestions SET proposer_sign = ?, updated_at = ? WHERE id = ?')->execute([$sigData, $now, $docId]);
        } elseif ($role === 'evaluator') {
            $db->prepare('UPDATE csm_suggestions SET evaluator_sign = ?, updated_at = ? WHERE id = ?')->execute([$sigData, $now, $docId]);
        }
    }
}

function worker_part_seed_defaults(PDO $db): void
{
    $count = (int)$db->query('SELECT COUNT(*) FROM csm_suggestions')->fetchColumn();
    if ($count > 0) {
        // Ensure signatures are populated for seeded rows if currently null
        $mSign = $db->query('SELECT drafter_sign FROM csm_safety_meetings LIMIT 1')->fetchColumn();
        if (!$mSign) {
            $now = date('Y-m-d H:i:s');
            $drafterSign = worker_part_make_signature_svg('진종철', '#b91c1c');
            $approverSign = worker_part_make_signature_svg('정연탁', '#1e3a8a');
            $attendeeSigns = [
                ['name' => '진종철', 'role' => 'attendee', 'sign' => $drafterSign, 'signed_at' => '2026-01-22 14:00'],
                ['name' => '정주랑', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('정주랑', '#0284c7'), 'signed_at' => '2026-01-22 14:05'],
                ['name' => '윤택천', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('윤택천', '#059669'), 'signed_at' => '2026-01-22 14:10'],
                ['name' => '김도담', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('김도담', '#1e3a8a'), 'signed_at' => '2026-01-22 14:12'],
                ['name' => '우홍기', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('우홍기', '#0284c7'), 'signed_at' => '2026-01-22 14:15'],
                ['name' => '김남균', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('김남균', '#475569'), 'signed_at' => '2026-01-22 14:18'],
            ];
            $db->prepare('UPDATE csm_safety_meetings SET
                drafter_sign = ?, approver_sign = ?, signatures_json = ?, updated_at = ?
                WHERE id = 1')->execute([
                $drafterSign, $approverSign, json_encode($attendeeSigns, JSON_UNESCAPED_UNICODE), $now
            ]);

            // Seed signatures for suggestions
            $stmt = $db->query('SELECT id, proposer_name FROM csm_suggestions');
            while ($row = $stmt->fetch()) {
                $pSign = worker_part_make_signature_svg($row['proposer_name'], '#059669');
                $eSign = worker_part_make_signature_svg('정연탁', '#1e3a8a');
                $db->prepare('UPDATE csm_suggestions SET proposer_sign = ?, evaluator_sign = ?, updated_at = ? WHERE id = ?')
                   ->execute([$pSign, $eSign, $now, $row['id']]);
            }
        }
        return;
    }

    $now = date('Y-m-d H:i:s');
    $evalSign = worker_part_make_signature_svg('정연탁', '#1e3a8a');

    $suggestions = [
        [
            'year' => 2026,
            'reg_no' => '2026-S001',
            'reg_date' => '2026-01-15',
            'proposer_name' => '윤택천',
            'proposer_dept' => '전기반',
            'title' => '고소작업 시 안전 발판 및 추락방지대 규격품 교체',
            'category' => '작업장 안전',
            'location' => 'K2 CBS 증설 현장 3층',
            'problem' => '일반 A형 사다리 사용 시 무게중심이 측부로 쏠려 상단 작업 중 추락 위험이 상존함',
            'solution' => '안전난간대 및 와이드 발판이 구비된 안전작업대(말비계) 현장 전면 배치',
            'expected_effect' => '고소 작업자 추락 재해 원천 차단 및 양손 작업 편의성 확보',
            'status' => '완료',
            'review_date' => '2026-01-18',
            'reviewer_name' => '진종철 부장',
            'review_opinion' => '타당성 매우 높음. 즉시 구매 승인 및 현장 불출',
            'action_plan' => '규격 안전사다리 2세트 구매 및 작업장 비치',
            'action_manager' => '정주랑 대리',
            'action_deadline' => '2026-01-25',
            'action_date' => '2026-01-23',
            'action_result' => '규격 말비계 2조 구매 후 현장 비치 및 불출 완료. 작업 전 점검표 작성 교육 실시',
            'feedback_date' => '2026-01-26',
            'feedback_method' => 'TBM 미팅 전파 및 안전게시판 공지',
            'reward_points' => 30.0,
            'reward_note' => '안전포상 상품권(5만원) 지급 및 이달의 안전인 가점 30점',
            'proposer_sign' => worker_part_make_signature_svg('윤택천', '#059669'),
            'evaluator_sign' => $evalSign,
        ],
        [
            'year' => 2026,
            'reg_no' => '2026-S002',
            'reg_date' => '2026-02-10',
            'proposer_name' => '김도담',
            'proposer_dept' => '기계정비반',
            'title' => '자재창고 앞 케이블 드럼 및 트레이 적재 구역 전면 구획 정리',
            'category' => '작업환경',
            'location' => '옥외 자재보관소',
            'problem' => '트레이 자재와 드럼이 혼재되어 지게차 및 보행자 동선 충돌 및 전도 위험',
            'solution' => '바닥 노란색 안전통로 구획 도색 및 자재 품목별 전용 적재 랙 지정',
            'expected_effect' => '안전통로 확보 및 야간·우천 시 전도 사고 예방',
            'status' => '완료',
            'review_date' => '2026-02-12',
            'reviewer_name' => '진종철 부장',
            'review_opinion' => '통로 확보 필요성 공감. 안전보건 예산 반영 조치',
            'action_plan' => '구획 도색 작업 및 자재표지판 부착',
            'action_manager' => '김도담 사원',
            'action_deadline' => '2026-02-20',
            'action_date' => '2026-02-18',
            'action_result' => '보행통로 1.2m 확보 도색 및 드럼 받침목 고정대 설치 완료',
            'feedback_date' => '2026-02-22',
            'feedback_method' => '주간 안전회의 공지 및 사진 공유',
            'reward_points' => 25.0,
            'reward_note' => '안전제안 우수상품권(3만원)',
            'proposer_sign' => worker_part_make_signature_svg('김도담', '#1e3a8a'),
            'evaluator_sign' => $evalSign,
        ],
        [
            'year' => 2026,
            'reg_no' => '2026-S003',
            'reg_date' => '2026-03-05',
            'proposer_name' => '우홍기',
            'proposer_dept' => '전기반',
            'title' => '배전반 점검 시 저압 활선 접근경보 센서 헬멧 부착 제안',
            'category' => '시설물 안전',
            'location' => '변전실 및 분전반 구역',
            'problem' => '정전 작업 완료 후 복전 시 또는 인접 활선 분전반 작업 중 감전 위험',
            'solution' => '충전부 1m 이내 접근 시 경보음이 발생하는 휴대용 활선경보기 헬멧 장착',
            'expected_effect' => '감전재해 제로화 및 근로자 부주의 위험 방지',
            'status' => '완료',
            'review_date' => '2026-03-08',
            'reviewer_name' => '정연탁 대표',
            'review_opinion' => '중대재해 예방 핵심 제안. 전기작업자 전원 지급 결정',
            'action_plan' => '활선경보기 5대 일괄 구매 및 착용 점검',
            'action_manager' => '정주랑 대리',
            'action_deadline' => '2026-03-15',
            'action_date' => '2026-03-14',
            'action_result' => '활선경보기 5세트 구매 및 전기반 전원 지급, 작동테스트 완료',
            'feedback_date' => '2026-03-16',
            'feedback_method' => 'TBM 실물 시연 및 환류 공지',
            'reward_points' => 30.0,
            'reward_note' => '최우수 안전제안상(10만원)',
            'proposer_sign' => worker_part_make_signature_svg('우홍기', '#0284c7'),
            'evaluator_sign' => $evalSign,
        ],
    ];

    $stmt = $db->prepare('INSERT INTO csm_suggestions (
        year, reg_no, reg_date, proposer_name, proposer_dept, title, category, location,
        problem, solution, expected_effect, status, review_date, reviewer_name, review_opinion,
        action_plan, action_manager, action_deadline, action_date, action_result, feedback_date, feedback_method,
        reward_points, reward_note, proposer_sign, evaluator_sign, created_at, updated_at
    ) VALUES (
        :year, :reg_no, :reg_date, :proposer_name, :proposer_dept, :title, :category, :location,
        :problem, :solution, :expected_effect, :status, :review_date, :reviewer_name, :review_opinion,
        :action_plan, :action_manager, :action_deadline, :action_date, :action_result, :feedback_date, :feedback_method,
        :reward_points, :reward_note, :proposer_sign, :evaluator_sign, :created_at, :updated_at
    )');

    foreach ($suggestions as $s) {
        $stmt->execute(array_merge($s, ['created_at' => $now, 'updated_at' => $now]));
    }

    // Seed Meeting with Signatures
    $drafterSign = worker_part_make_signature_svg('진종철', '#b91c1c');
    $approverSign = worker_part_make_signature_svg('정연탁', '#1e3a8a');
    $attendeeSigns = [
        ['name' => '진종철', 'role' => 'attendee', 'sign' => $drafterSign, 'signed_at' => '2026-01-22 14:00'],
        ['name' => '정주랑', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('정주랑', '#0284c7'), 'signed_at' => '2026-01-22 14:05'],
        ['name' => '윤택천', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('윤택천', '#059669'), 'signed_at' => '2026-01-22 14:10'],
        ['name' => '김도담', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('김도담', '#1e3a8a'), 'signed_at' => '2026-01-22 14:12'],
        ['name' => '우홍기', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('우홍기', '#0284c7'), 'signed_at' => '2026-01-22 14:15'],
        ['name' => '김남균', 'role' => 'attendee', 'sign' => worker_part_make_signature_svg('김남균', '#475569'), 'signed_at' => '2026-01-22 14:18'],
    ];

    $mStmt = $db->prepare('INSERT INTO csm_safety_meetings (
        year, meeting_type, meeting_name, meeting_date, meeting_place, chairperson,
        attendees_text, attendees_count, agenda, deliberation_content, decisions, action_items_json,
        result_sharing, attachment_path, drafter_sign, approver_sign, signatures_json, created_at, updated_at
    ) VALUES (
        :year, :meeting_type, :meeting_name, :meeting_date, :meeting_place, :chairperson,
        :attendees_text, :attendees_count, :agenda, :deliberation_content, :decisions, :action_items_json,
        :result_sharing, :attachment_path, :drafter_sign, :approver_sign, :signatures_json, :created_at, :updated_at
    )');
    $mStmt->execute([
        'year' => 2026,
        'meeting_type' => '산업안전보건위원회',
        'meeting_name' => '2026년 1분기 정기 산업안전보건위원회 회의',
        'meeting_date' => '2026-01-22',
        'meeting_place' => '본관 회의실',
        'chairperson' => '정연탁 대표',
        'attendees_text' => '정연탁(대표), 진종철(부장), 정주랑(대리), 윤택천(근로자대표), 김도담(정비반), 우홍기(전기반)',
        'attendees_count' => 6,
        'agenda' => "1. 2026년 안전보건경영방침 및 연간 활동계획 공유\n2. 동절기/해빙기 전기설비 및 고소작업 안전점검 계획\n3. 근로자 안전제안 및 아차사고 발굴 활성화 방안",
        'deliberation_content' => "- 원청 및 협력사 합동 순회점검 주기 준수 철저\n- 근로자 의견청취 결과: 이동식 작업대 노후품 교체 건의 및 활선경보기 지급 요청 접수\n- TBM 시간 준수 및 10대 안전수칙 서명 이행 상태 점검",
        'decisions' => "1. 제안된 규격 말비계 및 활선경보기 1분기 안전예산 즉시 집행\n2. 근로자 제안 포상금 제도 분기별 정례화\n3. 회의 결과 전 근로자 TBM 및 게시판 공유",
        'action_items_json' => json_encode([
            ['task' => '규격 말비계 2조 구매', 'manager' => '정주랑', 'deadline' => '2026-01-25', 'done' => true],
            ['task' => '활선경보기 견적 및 구매', 'manager' => '정주랑', 'deadline' => '2026-03-15', 'done' => true],
        ], JSON_UNESCAPED_UNICODE),
        'result_sharing' => '사무실 및 휴게실 게시판 공지, 1/26 TBM 교육 전파',
        'attachment_path' => null,
        'drafter_sign' => $drafterSign,
        'approver_sign' => $approverSign,
        'signatures_json' => json_encode($attendeeSigns, JSON_UNESCAPED_UNICODE),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Seed Champions
    $cStmt = $db->prepare('INSERT INTO csm_safety_champions (
        year, month, recipient_name, recipient_role, award_title, reason,
        score_near_miss, score_suggestion, score_attendance, score_other, total_score,
        reward_content, created_at, updated_at
    ) VALUES (
        :year, :month, :recipient_name, :recipient_role, :award_title, :reason,
        :score_near_miss, :score_suggestion, :score_attendance, :score_other, :total_score,
        :reward_content, :created_at, :updated_at
    )');
    $cStmt->execute([
        'year' => 2026,
        'month' => 1,
        'recipient_name' => '윤택천',
        'recipient_role' => '전기반 반장',
        'award_title' => '이달의 안전인 (1월)',
        'reason' => '고소작업 안전발판 개선 제안 발굴 및 일상 TBM 시 전 동료 위험성평가 참여를 적극 주도하여 무재해 달성에 기여함',
        'score_near_miss' => 30,
        'score_suggestion' => 30,
        'score_attendance' => 30,
        'score_other' => 10,
        'total_score' => 100,
        'reward_content' => '상장 및 온누리상품권 10만원 수여',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Seed Feedback Report
    $rStmt = $db->prepare('INSERT INTO csm_feedback_reports (
        year, half, report_title, report_date, manager_name,
        summary_opinions, action_status_summary, feedback_notice_summary, future_improvements,
        status, manager_sign, created_at, updated_at
    ) VALUES (
        :year, :half, :report_title, :report_date, :manager_name,
        :summary_opinions, :action_status_summary, :feedback_notice_summary, :future_improvements,
        :status, :manager_sign, :created_at, :updated_at
    )');
    $rStmt->execute([
        'year' => 2026,
        'half' => 1,
        'report_title' => '2026년 상반기 근로자 의견청취 및 안전제안 조치결과 환류 보고서',
        'report_date' => '2026-06-30',
        'manager_name' => '안전보건관리책임자 정연탁',
        'summary_opinions' => "1. 상반기 안전제안 총 3건 접수 (작업장안전 1건, 작업환경 1건, 시설물안전 1건)\n2. 정기 산업안전보건위원회 분기별 2회 실시 및 근로자대표 건의사항 청취",
        'action_status_summary' => "1. 고소작업 규격 안전사다리 2조 구매 비치 완료 (100% 조치)\n2. 자재보관소 안전통로 도색 및 구획 분리 완료 (100% 조치)\n3. 전기작업자 활선경보기 5세트 지급 및 작동점검 완료 (100% 조치)",
        'feedback_notice_summary' => '수렴된 의견 및 조치 완료 내역을 매월 TBM 미팅 및 사내 휴게실 안전게시판에 공지하였으며, 제안자 전원 포상금 지급 완료',
        'future_improvements' => '하반기 혹서기 온열질환 예방 제안 및 화학물질 취급 개선 제안 집중 발굴 추진',
        'status' => '승인·공지',
        'manager_sign' => $evalSign,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
