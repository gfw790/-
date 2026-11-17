<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Get active/all employees with employment status (Read-Only)
 */
function csm_get_employees(?string $selectedDate = null, bool $onlyActiveAtDate = false): array {
    $db = get_ext_employees_db();
    $sql = "SELECT id, employee_no, name, team, position, job_title, phone, join_date, employment_status, retired_at, is_active 
            FROM employees 
            WHERE employee_no != 'admin01' 
            ORDER BY team ASC, position ASC, name ASC";
    $rows = $db->query($sql)->fetchAll();
    
    $result = [];
    foreach ($rows as $r) {
        // Specific assignment: 정주랑 (실장) & 대표이사는 '경영/기타'로 분류
        if ($r['employee_no'] === '2316' || $r['name'] === '정주랑') {
            $r['team'] = '경영/기타';
            if (!empty($r['job_title'])) {
                $r['position'] = $r['job_title']; // 실장
            }
        } elseif (trim((string)($r['team'] ?? '')) === '') {
            $r['team'] = '경영/기타';
        }

        $isRetired = ($r['employment_status'] === 'retired' || (int)$r['is_active'] === 0);
        $r['is_currently_active'] = !$isRetired;
        $joinDate = trim((string)($r['join_date'] ?? ''));
        $retiredAt = trim((string)($r['retired_at'] ?? ''));

        $wasActive = true;
        if ($selectedDate !== null && $selectedDate !== '') {
            // 1. 교육일 기준 아직 입사하지 않은 근로자 제외
            if ($joinDate !== '' && $selectedDate < $joinDate) {
                $wasActive = false;
            }
            // 2. 교육일 기준 이미 퇴사한 근로자 제외 (퇴사일 당일 및 이후)
            if ($retiredAt !== '' && $selectedDate >= $retiredAt) {
                $wasActive = false;
            }
            // 3. 퇴사 처리되었으나 퇴사일자 미등록인 경우 제외
            if ($isRetired && $retiredAt === '') {
                $wasActive = false;
            }
        } else {
            if ($isRetired) {
                $wasActive = false;
            }
        }
        $r['was_active_at_date'] = $wasActive;
        
        if ($isRetired && !empty($retiredAt)) {
            $r['status_badge'] = "퇴사 (" . $retiredAt . ")";
        } elseif ($isRetired) {
            $r['status_badge'] = "퇴사";
        } elseif ($selectedDate !== null && $joinDate !== '' && $selectedDate < $joinDate) {
            $r['status_badge'] = "입사전 (" . $joinDate . ")";
        } else {
            $r['status_badge'] = "재직";
        }
        
        $r['is_team_active'] = csm_is_team_active((string)($r['team'] ?? ''));

        // Option to exclude employees not active as of selected date
        if ($onlyActiveAtDate && !$r['was_active_at_date']) {
            continue;
        }
        
        $result[] = $r;
    }
    return $result;
}

/**
 * Read inactive teams from company auth_team_statuses.json
 */
function csm_get_inactive_teams(): array {
    $statusPath = 'A:/risk_server/project/risk_assessment/auth_team_statuses.json';
    if (file_exists($statusPath)) {
        $statuses = json_decode((string)file_get_contents($statusPath), true);
        if (is_array($statuses)) {
            $inactive = [];
            foreach ($statuses as $tname => $isActive) {
                if (!$isActive) {
                    $inactive[] = (string)$tname;
                }
            }
            return $inactive;
        }
    }
    return [];
}

/**
 * Check if a specific team is currently active
 */
function csm_is_team_active(string $teamName): bool {
    $tname = trim($teamName);
    if ($tname === '' || $tname === '(None)') {
        return false;
    }
    
    // Check against auth_team_statuses.json
    $statusPath = 'A:/risk_server/project/risk_assessment/auth_team_statuses.json';
    if (file_exists($statusPath)) {
        $statuses = json_decode((string)file_get_contents($statusPath), true);
        if (is_array($statuses) && array_key_exists($tname, $statuses)) {
            return (bool)$statuses[$tname];
        }
    }
    
    if (function_exists('auth_is_team_active')) {
        return auth_is_team_active($tname);
    }
    
    return true;
}

/**
 * Get MSDS chemical materials list from existing JSON
 */
function csm_get_msds_list(): array {
    $path = EXT_MSDS_JSON_PATH;
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    
    $list = [];
    foreach ($data as $item) {
        $list[] = [
            'id' => (string)($item['id'] ?? ''),
            'material_name' => (string)($item['material_name'] ?? ''),
            'manufacturer' => (string)($item['manufacturer'] ?? ''),
            'revised_date' => (string)($item['revised_date'] ?? ''),
            'revision_count' => (string)($item['revision_count'] ?? ''),
            'stored_name' => (string)($item['stored_name'] ?? ''),
        ];
    }
    return $list;
}

/**
 * Generate sequential Training Number
 */
function csm_generate_training_no(string $date): string {
    $year = substr($date, 0, 4);
    if (!$year) $year = date('Y');
    
    $db = get_csm_db();
    $stmt = $db->prepare("SELECT training_no FROM csm_trainings WHERE training_no LIKE ? ORDER BY training_no DESC LIMIT 1");
    $stmt->execute(["EDU-{$year}-%"]);
    $lastNo = (string)$stmt->fetchColumn();
    $seq = 1;
    if ($lastNo && preg_match('/EDU-\d{4}-(\d+)/', $lastNo, $m)) {
        $seq = (int)$m[1] + 1;
    }
    
    $check = $db->prepare("SELECT COUNT(*) FROM csm_trainings WHERE training_no = ?");
    while (true) {
        $candidate = sprintf("EDU-%s-%04d", $year, $seq);
        $check->execute([$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
        $seq++;
    }
}

/**
 * Company Rule: Compute 4th Friday of any Month
 * Strictly picks the 4th Friday even if a 5th Friday exists.
 */
function csm_get_fourth_friday(int $year, int $month): string {
    $fridays = [];
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $w = (int)date('w', mktime(0, 0, 0, $month, $d, $year));
        if ($w === 5) { // 5 is Friday
            $fridays[] = $d;
        }
    }
    // 4th Friday is index 3 (0-indexed)
    $day = $fridays[3] ?? $fridays[count($fridays) - 1];
    return sprintf("%04d-%02d-%02d", $year, $month, $day);
}

/**
 * Company Rule: Compute 3rd Friday of any Month
 */
function csm_get_third_friday(int $year, int $month): string {
    $fridays = [];
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $w = (int)date('w', mktime(0, 0, 0, $month, $d, $year));
        if ($w === 5) {
            $fridays[] = $d;
        }
    }
    $day = $fridays[2] ?? $fridays[0];
    return sprintf("%04d-%02d-%02d", $year, $month, $day);
}

/**
 * Company Rule: Compute 1st Friday of January (or specified month)
 */
function csm_get_first_friday(int $year, int $month = 1): string {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $w = (int)date('w', mktime(0, 0, 0, $month, $d, $year));
        if ($w === 5) {
            return sprintf("%04d-%02d-%02d", $year, $month, $d);
        }
    }
    return sprintf("%04d-%02d-01", $year, $month);
}

/**
 * Detect schedule collisions between trainings on the same date and overlapping times
 * Supports attendee-level overlap verification
 */
function csm_check_schedule_collision(PDO $pdo, string $date, string $startTime, string $endTime, ?int $excludeId = null, array $attendeeNos = []): array {
    if (empty($date) || empty($startTime) || empty($endTime)) {
        return [];
    }
    
    $sql = "SELECT id, training_no, training_type, subject, start_time, end_time 
            FROM csm_trainings 
            WHERE training_date = ? AND start_time IS NOT NULL AND end_time IS NOT NULL";
    $params = [$date];
    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $collisions = [];
    $attStmt = $pdo->prepare("SELECT employee_no, name_snapshot FROM csm_training_attendees WHERE training_id = ?");

    foreach ($existing as $ex) {
        $exStart = $ex['start_time'];
        $exEnd = $ex['end_time'];
        // Overlap: max(start1, start2) < min(end1, end2)
        if (max($startTime, $exStart) < min($endTime, $exEnd)) {
            $attStmt->execute([$ex['id']]);
            $existingAtts = $attStmt->fetchAll(PDO::FETCH_ASSOC);
            $existingNos = array_column($existingAtts, 'employee_no');

            $overlappingNames = [];
            if (!empty($attendeeNos) && !empty($existingNos)) {
                $intersect = array_intersect($attendeeNos, $existingNos);
                if (!empty($intersect)) {
                    foreach ($existingAtts as $ea) {
                        if (in_array($ea['employee_no'], $intersect, true)) {
                            $overlappingNames[] = $ea['name_snapshot'];
                        }
                    }
                }
            }

            $ex['overlapping_attendees'] = $overlappingNames;
            $ex['has_person_collision'] = !empty($overlappingNames);
            $collisions[] = $ex;
        }
    }
    return $collisions;
}

/**
 * Get Plan Curriculum (extracted from 1.2.1 계획서)
 */
function csm_get_plan_curriculum(string $type, ?int $month = null, ?string $subType = null, int $round = 1): ?array {
    $path = CSM_DEV_ROOT . '/storage/plan_curriculum.json';
    if (!file_exists($path)) return null;
    $plan = json_decode(file_get_contents($path), true);
    if (!is_array($plan)) return null;
    
    $typeUpper = strtoupper($type);
    if ($typeUpper === 'REGULAR') {
        $mKey = (string)($month ?: (int)date('n'));
        return $plan['regular_monthly'][$mKey] ?? null;
    }
    if ($typeUpper === 'SPECIAL') {
        $mKey = (string)($month ?: (int)date('n'));
        $rKey = (string)($round ?: 1);
        if (!empty($plan['special_monthly'][$mKey][$rKey])) {
            return $plan['special_monthly'][$mKey][$rKey];
        }
        if (!empty($plan['special_monthly'][$mKey]['1'])) {
            return $plan['special_monthly'][$mKey]['1'];
        }
        return $plan['special_default'] ?? null;
    }
    if ($typeUpper === 'NEW_HIRE') {
        return $plan['new_hire'] ?? null;
    }
    if ($typeUpper === 'SUPERVISOR') {
        return $plan['supervisor'] ?? null;
    }
    if ($typeUpper === 'OTHER' && $subType) {
        return $plan['other_types'][$subType] ?? null;
    }
    return null;
}

/**
 * Get Other Training Types List
 */
function csm_get_other_training_types(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM csm_other_training_types WHERE is_active = 1 ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Synchronize New Hire Training Records from employees.db
 * STRICT RULE: Existing employees (baseline IDs 1-29) are pre-existing workers and NOT new hires.
 * Only newly registered accounts (id > 29, added going forward) will trigger the automatic
 * creation of scheduled pending draft training (8 hours, is_completed=0).
 */
function csm_sync_new_hire_trainings(PDO $csmDb, PDO $empDb): array {
    $baselineMaxId = 29; // Pre-existing baseline employees
    $stmt = $empDb->prepare("
        SELECT * FROM employees 
        WHERE id > ? AND is_active = 1 AND employee_no != 'admin01' 
        ORDER BY id ASC
    ");
    $stmt->execute([$baselineMaxId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $createdCount = 0;
    $skippedCount = 0;
    
    $checkStmt = $csmDb->prepare("
        SELECT COUNT(*) FROM csm_trainings t
        JOIN csm_training_attendees a ON t.id = a.training_id
        WHERE t.training_type = 'NEW_HIRE' AND a.employee_id = ?
    ");
    
    $insTraining = $csmDb->prepare("
        INSERT INTO csm_trainings (
            training_no, training_date, start_time, end_time, training_type, subject, method, 
            planned_hours, location, instructor_name, instructor_position, content_summary, 
            status, is_demo, created_by, created_at, updated_at
        ) VALUES (
            ?, ?, '09:00', '18:00', 'NEW_HIRE', ?, '시청각 및 실습', 
            8.0, '현장 사무실', '진종철', '관리감독자', ?, 
            'DRAFT', 0, 'SYSTEM_SYNC', datetime('now', 'localtime'), datetime('now', 'localtime')
        )
    ");
    
    $insAttendee = $csmDb->prepare("
        INSERT INTO csm_training_attendees (
            training_id, employee_id, employee_no, name_snapshot, team_snapshot, position_snapshot, 
            is_completed, completed_hours, note
        ) VALUES (?, ?, ?, ?, ?, ?, 0, 0.0, '신규채용 계정등록 연계 (교육 미실시)')
    ");
    
    $curriculum = csm_get_plan_curriculum('NEW_HIRE');
    $summary = $curriculum ? implode("\n", $curriculum['contents']) : "신규채용시 안전보건교육";
    
    foreach ($employees as $emp) {
        $checkStmt->execute([$emp['id']]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            $skippedCount++;
            continue;
        }
        
        $today = date('Y-m-d');
        $trainingNo = csm_generate_training_no($today);
        $subject = sprintf("[신규채용] 신규 입사자 안전보건교육 (%s)", $emp['name']);
        
        $csmDb->beginTransaction();
        try {
            $insTraining->execute([$trainingNo, $today, $subject, $summary]);
            $tId = (int)$csmDb->lastInsertId();
            
            $insAttendee->execute([
                $tId,
                $emp['id'],
                $emp['employee_no'],
                $emp['name'],
                $emp['team'] ?? '',
                $emp['position'] ?? ''
            ]);
            $csmDb->commit();
            $createdCount++;
        } catch (Exception $e) {
            $csmDb->rollBack();
        }
    }
    
    return ['created' => $createdCount, 'skipped' => $skippedCount];
}

/**
 * Security: Validate and save uploaded file (Supports photos, signs, scanned docs)
 */
function csm_handle_upload(array $file, string $targetSubdir, array $allowedMimes): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => '파일 업로드 오류 코드: ' . $file['error']];
    }
    
    // Max 10MB
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => '파일 용량은 10MB 이하만 가능합니다.'];
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => '허용되지 않는 파일 형식입니다. (감지된 MIME: ' . $mime . ')'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $blocked = ['php', 'phtml', 'exe', 'bat', 'cmd', 'sh', 'js', 'html', 'htm', 'vbs', 'jar'];
    if (in_array($ext, $blocked, true)) {
        return ['ok' => false, 'error' => '보안상 업로드가 금지된 확장자입니다.'];
    }
    
    // Generate secure random filename
    $storedName = sprintf("%s_%s.%s", date('Ymd_His'), bin2hex(random_bytes(6)), $ext);
    
    $targetDir = match ($targetSubdir) {
        'photos' => CSM_UPLOAD_PHOTOS_DIR,
        'signs' => CSM_UPLOAD_SIGNS_DIR,
        'scanned_docs' => CSM_UPLOAD_SCANS_DIR,
        default => null
    };
    
    if ($targetDir === null) {
        return ['ok' => false, 'error' => '유효하지 않은 업로드 대상 디렉터리입니다.'];
    }
    
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    $targetPath = $targetDir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['ok' => false, 'error' => '서버 내 파일 저장에 실패했습니다.'];
    }
    
    return [
        'ok' => true,
        'original_name' => pathinfo($file['name'], PATHINFO_BASENAME),
        'stored_name' => $storedName,
        'file_size' => $file['size'],
        'mime_type' => $mime,
    ];
}

/**
 * Create Backup ZIP of DB + Uploads + Scans
 */
function csm_create_backup(): string {
    if (!is_dir(CSM_BACKUP_DIR)) {
        mkdir(CSM_BACKUP_DIR, 0755, true);
    }
    
    $zipFilename = sprintf("CSM_BACKUP_%s.zip", date('Ymd_His'));
    $zipPath = CSM_BACKUP_DIR . '/' . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("백업 ZIP 파일을 생성할 수 없습니다.");
    }
    
    // Add DB
    if (file_exists(CSM_DB_PATH)) {
        $zip->addFile(CSM_DB_PATH, 'db/csm_dev.db');
    }
    
    // Add Photos
    foreach (glob(CSM_UPLOAD_PHOTOS_DIR . '/*') as $f) {
        if (is_file($f)) $zip->addFile($f, 'uploads/photos/' . basename($f));
    }
    
    // Add Signatures
    foreach (glob(CSM_UPLOAD_SIGNS_DIR . '/*') as $f) {
        if (is_file($f)) $zip->addFile($f, 'uploads/signatures/' . basename($f));
    }
    
    // Add Scans
    foreach (glob(CSM_UPLOAD_SCANS_DIR . '/*') as $f) {
        if (is_file($f)) $zip->addFile($f, 'uploads/scanned_docs/' . basename($f));
    }
    
    $zip->close();
    return $zipPath;
}

/**
 * Automatically sync plan execution status against csm_trainings
 * [핵심 규칙] 특별안전교육(SPECIAL)은 교육일지 확인(FINALIZED) 및 참석자 이수 확인이 완료되지 않으면 절대로 실시완료(EXECUTED) 처리하지 않음!
 */
function csm_sync_plan_execution_status(PDO $db): void {
    // 1. 유효하지 않은 연결 초기화 (연결된 교육일지가 DB에 없으면 계획예정으로 복원)
    $db->exec("
        UPDATE csm_training_plans 
        SET status = 'PLANNED', linked_training_id = NULL
        WHERE linked_training_id IS NOT NULL 
          AND NOT EXISTS (
              SELECT 1 FROM csm_trainings t 
              WHERE t.id = csm_training_plans.linked_training_id
          )
    ");

    // 2. 교육구분 및 주기 기준 최신 일지 매핑
    // 2-1. 정기안전보건교육(REGULAR): 연도(plan_year) 및 해당 월(plan_month) 기준 매핑
    $db->exec("
        UPDATE csm_training_plans 
        SET linked_training_id = (
            SELECT id FROM csm_trainings t 
            WHERE t.training_type = 'REGULAR' 
              AND strftime('%Y', t.training_date) = CAST(csm_training_plans.plan_year AS TEXT)
              AND CAST(strftime('%m', t.training_date) AS INTEGER) = csm_training_plans.plan_month
              AND t.is_demo = 0
            ORDER BY (t.status = 'FINALIZED') DESC, t.id DESC
            LIMIT 1
        )
        WHERE training_type = 'REGULAR' 
          AND plan_month IS NOT NULL
          AND (
            linked_training_id IS NULL 
            OR NOT EXISTS (SELECT 1 FROM csm_trainings t WHERE t.id = csm_training_plans.linked_training_id)
          )
    ");

    // 2-2. 관리감독자(SUPERVISOR): 연도(plan_year) 기준 매핑
    $db->exec("
        UPDATE csm_training_plans 
        SET linked_training_id = (
            SELECT id FROM csm_trainings t 
            WHERE t.training_type = 'SUPERVISOR' 
              AND strftime('%Y', t.training_date) = CAST(csm_training_plans.plan_year AS TEXT)
              AND t.is_demo = 0
            ORDER BY (t.status = 'FINALIZED') DESC, t.id DESC
            LIMIT 1
        )
        WHERE training_type = 'SUPERVISOR'
          AND (
            linked_training_id IS NULL 
            OR NOT EXISTS (SELECT 1 FROM csm_trainings t WHERE t.id = csm_training_plans.linked_training_id)
          )
    ");

    // 2-3. 계획예정일자(planned_date)가 명시된 교육: 날짜 일치 매핑
    $db->exec("
        UPDATE csm_training_plans 
        SET linked_training_id = (
            SELECT id FROM csm_trainings t 
            WHERE t.training_type = csm_training_plans.training_type 
              AND t.training_date = csm_training_plans.planned_date 
              AND t.is_demo = 0
            ORDER BY (t.status = 'FINALIZED') DESC, t.id DESC
            LIMIT 1
        )
        WHERE linked_training_id IS NULL
          AND planned_date IS NOT NULL
          AND EXISTS (
            SELECT 1 FROM csm_trainings t 
            WHERE t.training_type = csm_training_plans.training_type 
              AND t.training_date = csm_training_plans.planned_date 
              AND t.is_demo = 0
        )
    ");

    // 3. 특별안전교육(SPECIAL) 엄격 통제:
    // 일지 상태가 'FINALIZED'이고 참석자 이수 인원이 1명 이상인 경우에만 'EXECUTED' (실시완료) 허용!
    // 일지가 없거나 DRAFT(미확인) 상태이거나 참석자 이수 인원이 0명이면 무조건 'PLANNED' 유지!
    $db->exec("
        UPDATE csm_training_plans
        SET status = 'EXECUTED'
        WHERE training_type = 'SPECIAL'
          AND linked_training_id IS NOT NULL
          AND EXISTS (
              SELECT 1 FROM csm_trainings t
              WHERE t.id = csm_training_plans.linked_training_id
                AND t.status = 'FINALIZED'
                AND (SELECT COUNT(*) FROM csm_training_attendees WHERE training_id = t.id AND is_completed = 1) > 0
          )
    ");

    $db->exec("
        UPDATE csm_training_plans
        SET status = 'PLANNED'
        WHERE training_type = 'SPECIAL'
          AND (
              linked_training_id IS NULL
              OR NOT EXISTS (
                  SELECT 1 FROM csm_trainings t
                  WHERE t.id = csm_training_plans.linked_training_id
                    AND t.status = 'FINALIZED'
                    AND (SELECT COUNT(*) FROM csm_training_attendees WHERE training_id = t.id AND is_completed = 1) > 0
              )
          )
    ");

    // 4. 일반 교육구분 (REGULAR, NEW_HIRE, SUPERVISOR, MSDS 등):
    // 일지가 확정(FINALIZED)된 경우에 한해 실시완료 처리
    $db->exec("
        UPDATE csm_training_plans 
        SET status = 'EXECUTED'
        WHERE training_type != 'SPECIAL'
          AND linked_training_id IS NOT NULL
          AND EXISTS (
              SELECT 1 FROM csm_trainings t 
              WHERE t.id = csm_training_plans.linked_training_id
                AND t.status = 'FINALIZED'
          )
    ");

    $db->exec("
        UPDATE csm_training_plans 
        SET status = 'PLANNED'
        WHERE training_type != 'SPECIAL'
          AND (
              linked_training_id IS NULL
              OR NOT EXISTS (
                  SELECT 1 FROM csm_trainings t 
                  WHERE t.id = csm_training_plans.linked_training_id
                    AND t.status = 'FINALIZED'
              )
          )
    ");
}

/**
 * Get Training Plans with filters and execution status
 */
function csm_get_training_plans(PDO $db, int $year = 2026, ?string $type = null, ?int $month = null, ?string $status = null): array {
    csm_sync_plan_execution_status($db);
    
    $sql = "SELECT p.*, 
                   t.training_no AS executed_training_no,
                   t.status AS executed_training_status,
                   t.finalized_at AS executed_finalized_at,
                   (SELECT COUNT(*) FROM csm_training_attendees WHERE training_id = t.id AND is_completed = 1) AS executed_completed_attendees
            FROM csm_training_plans p
            LEFT JOIN csm_trainings t ON p.linked_training_id = t.id
            WHERE p.plan_year = :year";
    $params = [':year' => $year];
    
    if (!empty($type) && $type !== 'ALL') {
        $sql .= " AND p.training_type = :type";
        $params[':type'] = $type;
    }
    if ($month !== null && $month > 0) {
        $sql .= " AND p.plan_month = :month";
        $params[':month'] = $month;
    }
    if (!empty($status) && $status !== 'ALL') {
        $sql .= " AND p.status = :status";
        $params[':status'] = $status;
    }
    
    $sql .= " ORDER BY p.plan_month ASC, p.planned_date ASC, p.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get Single Training Plan by ID
 */
function csm_get_training_plan_by_id(PDO $db, int $planId): ?array {
    $stmt = $db->prepare("SELECT * FROM csm_training_plans WHERE id = ?");
    $stmt->execute([$planId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
