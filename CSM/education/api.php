<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log("CSM_API_ERROR [{$errno}] {$errstr} in {$errfile}:{$errline}");
    return true; // Suppress HTML error output so JSON is never corrupted
});

function csm_json_response(array $data, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

header('Content-Type: application/json; charset=utf-8');

// Security Gate
$user = csm_require_auth();
$actorName = (string)(!empty($user['name']) ? $user['name'] : (!empty($user['login_id']) ? $user['login_id'] : '관리자'));
$db = get_csm_db();

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));

try {
    switch ($action) {
        
        // ── LIST TRAININGS ───────────────────────────────────────
        case 'list':
            $isDemo = isset($_GET['is_demo']) ? (int)$_GET['is_demo'] : 0;
            $type = trim((string)($_GET['type'] ?? ''));
            $dateFrom = trim((string)($_GET['date_from'] ?? ''));
            $dateTo = trim((string)($_GET['date_to'] ?? ''));
            $attendee = trim((string)($_GET['attendee'] ?? ''));
            $status = trim((string)($_GET['status'] ?? ''));
            $csmCode = trim((string)($_GET['csm_code'] ?? ''));
            
            $sql = "SELECT t.*, 
                           (SELECT COUNT(*) FROM csm_training_attendees WHERE training_id = t.id) AS total_attendees,
                           (SELECT COUNT(*) FROM csm_training_attendees WHERE training_id = t.id AND is_completed = 1) AS completed_attendees,
                           (SELECT COUNT(*) FROM csm_training_photos WHERE training_id = t.id) AS photo_count
                    FROM csm_trainings t
                    WHERE t.is_demo = :is_demo";
            $params = [':is_demo' => $isDemo];
            
            if ($type !== '' && $type !== 'ALL') {
                $sql .= " AND t.training_type = :type";
                $params[':type'] = $type;
            }
            if ($status !== '' && $status !== 'ALL') {
                $sql .= " AND t.status = :status";
                $params[':status'] = $status;
            }
            if ($csmCode !== '' && $csmCode !== 'ALL') {
                $sql .= " AND t.csm_item_code = :csm_code";
                $params[':csm_code'] = $csmCode;
            }
            if ($dateFrom !== '') {
                $sql .= " AND t.training_date >= :date_from";
                $params[':date_from'] = $dateFrom;
            }
            if ($dateTo !== '') {
                $sql .= " AND t.training_date <= :date_to";
                $params[':date_to'] = $dateTo;
            }
            if ($attendee !== '') {
                $sql .= " AND t.id IN (SELECT training_id FROM csm_training_attendees WHERE name_snapshot LIKE :attendee OR employee_no = :attendee_no)";
                $params[':attendee'] = "%{$attendee}%";
                $params[':attendee_no'] = $attendee;
            }
            
            $sql .= " ORDER BY t.training_date DESC, t.session_round ASC, t.id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll();
            
            echo json_encode(['ok' => true, 'list' => $list]);
            break;

        // ── GET SINGLE TRAINING DETAIL ───────────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM csm_trainings WHERE id = ?");
            $stmt->execute([$id]);
            $training = $stmt->fetch();
            if (!$training) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '교육 기록을 찾을 수 없습니다.']);
                exit;
            }
            
            // Attendees
            $attStmt = $db->prepare("SELECT * FROM csm_training_attendees WHERE training_id = ? ORDER BY id ASC");
            $attStmt->execute([$id]);
            $attendees = $attStmt->fetchAll();
            
            // Photos (ordered by display_order)
            $pStmt = $db->prepare("SELECT * FROM csm_training_photos WHERE training_id = ? ORDER BY display_order ASC, id ASC");
            $pStmt->execute([$id]);
            $photos = $pStmt->fetchAll();
            
            // Revision History
            $revStmt = $db->prepare("SELECT id, training_id, revision_num, changed_by, changed_at, change_reason FROM csm_training_revisions WHERE training_id = ? ORDER BY revision_num DESC");
            $revStmt->execute([$id]);
            $revisions = $revStmt->fetchAll();
            
            // If part of Special Training course, get peer session
            $peerSession = null;
            if (!empty($training['course_id'])) {
                $pSessionStmt = $db->prepare("SELECT id, training_no, session_round, training_date, start_time, end_time, status FROM csm_trainings WHERE course_id = ? AND id != ?");
                $pSessionStmt->execute([$training['course_id'], $id]);
                $peerSession = $pSessionStmt->fetch();
            }
            
            echo json_encode([
                'ok' => true,
                'training' => $training,
                'attendees' => $attendees,
                'photos' => $photos,
                'revisions' => $revisions,
                'peer_session' => $peerSession,
            ]);
            break;

        // ── CHECK SCHEDULE COLLISION ─────────────────────────────
        case 'check_collision':
            $date = trim((string)($_GET['date'] ?? ''));
            $start = trim((string)($_GET['start_time'] ?? ''));
            $end = trim((string)($_GET['end_time'] ?? ''));
            $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
            $rawNos = trim((string)($_GET['attendee_nos'] ?? ''));
            $attendeeNos = $rawNos !== '' ? array_filter(explode(',', $rawNos)) : [];
            
            $collisions = csm_check_schedule_collision($db, $date, $start, $end, $excludeId, $attendeeNos);
            $personCollisions = array_filter($collisions, fn($c) => !empty($c['has_person_collision']));
            
            echo json_encode([
                'ok' => true,
                'has_collision' => !empty($collisions),
                'has_person_collision' => !empty($personCollisions),
                'has_attendees_specified' => !empty($attendeeNos),
                'collisions' => $collisions
            ]);
            break;

        // ── GET PLAN CURRICULUM TEMPLATE ─────────────────────────
        case 'plan_curriculum':
            $type = trim((string)($_GET['type'] ?? 'REGULAR'));
            $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
            $subType = trim((string)($_GET['sub_type'] ?? ''));
            $round = isset($_GET['round']) ? (int)$_GET['round'] : 1;
            
            $curr = csm_get_plan_curriculum($type, $month, $subType, $round);
            echo json_encode(['ok' => true, 'curriculum' => $curr]);
            break;

        // ── SYNC NEW HIRE TRAININGS ──────────────────────────────
        case 'sync_new_hires':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                exit;
            }
            $res = csm_sync_new_hire_trainings($db, get_ext_employees_db());
            echo json_encode([
                'ok' => true,
                'created' => $res['created'],
                'skipped' => $res['skipped'],
                'message' => $res['created'] > 0 
                    ? "신규 입사자 교육 예정기록 {$res['created']}건이 생성되었습니다." 
                    : "새로 등록된 신규 입사자 계정이 없습니다. (기존 재직자 29명은 신규교육 대상 제외)"
            ]);
            break;

        // ── TRAINING PLANS MANAGEMENT ───────────────────────────
        case 'plan_list':
            $year = (int)($_GET['year'] ?? 2026);
            $type = trim((string)($_GET['type'] ?? ''));
            $month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
            $status = trim((string)($_GET['status'] ?? ''));
            $plans = csm_get_training_plans($db, $year, $type, $month, $status);
            echo json_encode(['ok' => true, 'plans' => $plans]);
            break;

        case 'plan_get':
            $planId = (int)($_GET['id'] ?? 0);
            $plan = csm_get_training_plan_by_id($db, $planId);
            if (!$plan) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '계획을 찾을 수 없습니다.']);
                exit;
            }
            echo json_encode(['ok' => true, 'plan' => $plan]);
            break;

        case 'plan_save':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                exit;
            }
            $pId = (int)($_POST['id'] ?? 0);
            $pYear = (int)($_POST['plan_year'] ?? 2026);
            $pMonth = !empty($_POST['plan_month']) ? (int)$_POST['plan_month'] : null;
            $pType = trim((string)($_POST['training_type'] ?? 'REGULAR'));
            $pSubType = trim((string)($_POST['sub_type'] ?? ''));
            $pSubject = trim((string)($_POST['subject'] ?? ''));
            $pAudience = trim((string)($_POST['target_audience'] ?? '현장 전 근로자 (재직 20명)'));
            $pTeams = trim((string)($_POST['target_teams'] ?? '가스팀, 공사팀-전기, 공사팀-모터, 제조팀, 경영/기타, 안전관리'));
            $pDate = trim((string)($_POST['planned_date'] ?? ''));
            $pPeriod = trim((string)($_POST['planned_period'] ?? ''));
            $pHours = (float)($_POST['planned_hours'] ?? 2.0);
            $pInstructor = trim((string)($_POST['instructor_name'] ?? '김남균'));
            $pInstructorPos = trim((string)($_POST['instructor_position'] ?? '안전관리자'));
            $pLocation = trim((string)($_POST['location'] ?? '현장 사무실 / 휴게실'));
            $pMethod = trim((string)($_POST['method'] ?? '시청각 및 실습'));
            $pOutline = trim((string)($_POST['content_outline'] ?? ''));
            $pOutlineLeft = trim((string)($_POST['content_outline_left'] ?? ''));
            $pOutlineRight = trim((string)($_POST['content_outline_right'] ?? ''));
            if ($pOutline === '' && ($pOutlineLeft !== '' || $pOutlineRight !== '')) {
                $pOutline = ($pOutlineLeft ? "[좌측 본문]\n" . $pOutlineLeft : "") . ($pOutlineRight ? "\n\n[우측 본문]\n" . $pOutlineRight : "");
            }
            $pSource = trim((string)($_POST['plan_source'] ?? '(주)현대기전 정기안전교육계획서'));

            if (empty($pSubject) || (empty($pOutline) && empty($pOutlineLeft))) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '교육과목 및 상세 내용은 필수 항목입니다.']);
                exit;
            }

            $now = date('Y-m-d H:i:s');
            if ($pId === 0) {
                $stmt = $db->prepare("
                    INSERT INTO csm_training_plans (
                        plan_year, plan_month, training_type, sub_type, subject, target_audience,
                        target_teams, planned_date, planned_period, planned_hours, instructor_name,
                        instructor_position, location, method, content_outline,
                        content_outline_left, content_outline_right, plan_source,
                        status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PLANNED', ?, ?)
                ");
                $stmt->execute([
                    $pYear, $pMonth, $pType, $pSubType, $pSubject, $pAudience,
                    $pTeams, $pDate, $pPeriod, $pHours, $pInstructor,
                    $pInstructorPos, $pLocation, $pMethod, $pOutline,
                    $pOutlineLeft, $pOutlineRight, $pSource,
                    $now, $now
                ]);
                $newId = (int)$db->lastInsertId();
                echo json_encode(['ok' => true, 'id' => $newId, 'message' => '교육계획이 정상 등록되었습니다.']);
            } else {
                $stmt = $db->prepare("
                    UPDATE csm_training_plans SET
                        plan_year = ?, plan_month = ?, training_type = ?, sub_type = ?, subject = ?,
                        target_audience = ?, target_teams = ?, planned_date = ?, planned_period = ?,
                        planned_hours = ?, instructor_name = ?, instructor_position = ?, location = ?,
                        method = ?, content_outline = ?,
                        content_outline_left = ?, content_outline_right = ?,
                        plan_source = ?, updated_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $pYear, $pMonth, $pType, $pSubType, $pSubject,
                    $pAudience, $pTeams, $pDate, $pPeriod,
                    $pHours, $pInstructor, $pInstructorPos, $pLocation,
                    $pMethod, $pOutline,
                    $pOutlineLeft, $pOutlineRight,
                    $pSource, $now, $pId
                ]);
                echo json_encode(['ok' => true, 'id' => $pId, 'message' => '교육계획이 정상 수정되었습니다.']);
            }
            break;

        case 'plan_delete':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                exit;
            }
            $pId = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM csm_training_plans WHERE id = ?")->execute([$pId]);
            echo json_encode(['ok' => true, 'message' => '교육계획이 삭제되었습니다.']);
            break;

        // ── OTHER TRAINING TYPES ─────────────────────────────────
        case 'other_types':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!csm_can_manage($user)) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                    exit;
                }
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                $hours = (float)($_POST['default_hours'] ?? 2.0);
                $desc = trim((string)($_POST['description'] ?? ''));
                
                if (empty($name)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => '교육명은 필수입니다.']);
                    exit;
                }
                
                if (empty($code)) {
                    $code = 'OTHER_' . date('ymd') . '_' . substr(md5($name . microtime()), 0, 4);
                    $code = strtoupper($code);
                }
                
                $chk = $db->prepare("SELECT COUNT(*) FROM csm_other_training_types WHERE code = ?");
                $chk->execute([$code]);
                if ($chk->fetchColumn() > 0) {
                    $code .= '_' . mt_rand(10, 99);
                }

                $ins = $db->prepare("INSERT INTO csm_other_training_types (code, name, default_hours, description) VALUES (?, ?, ?, ?)");
                $ins->execute([$code, $name, $hours, $desc]);
                $newId = (int)$db->lastInsertId();

                echo json_encode([
                    'ok' => true, 
                    'message' => '새 기타 교육과목이 추가되었습니다.',
                    'type' => [
                        'id' => $newId,
                        'code' => $code,
                        'name' => $name,
                        'default_hours' => $hours,
                        'description' => $desc
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $types = csm_get_other_training_types($db);
            echo json_encode(['ok' => true, 'types' => $types], JSON_UNESCAPED_UNICODE);
            break;

        // ── SAVE / UPDATE TRAINING ───────────────────────────────
        case 'save':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '수정 및 저장 권한이 없습니다.']);
                exit;
            }
            
            $id = (int)($_POST['id'] ?? 0);
            $trainingDate = trim((string)($_POST['training_date'] ?? date('Y-m-d')));
            $startTime = trim((string)($_POST['start_time'] ?? '13:00'));
            $endTime = trim((string)($_POST['end_time'] ?? '15:00'));
            $trainingType = trim((string)($_POST['training_type'] ?? 'REGULAR'));
            $subType = trim((string)($_POST['sub_type'] ?? ''));
            $csmItemCode = trim((string)($_POST['csm_item_code'] ?? ''));
            if ($csmItemCode === '') {
                if ($trainingType === 'REGULAR' || $trainingType === 'SUPERVISOR') $csmItemCode = '5.1.2';
                elseif ($trainingType === 'NEW_HIRE') $csmItemCode = '5.1.3';
                elseif ($trainingType === 'SPECIAL') $csmItemCode = '5.1.4';
                elseif ($trainingType === 'MSDS') $csmItemCode = '5.1.5';
                elseif ($trainingType === 'OTHER') {
                    if ($subType === 'EXPERIENCE_TRAINING' || $subType === 'PORT_SAFETY') $csmItemCode = '5.2.2';
                    elseif ($subType === 'SAFETY_DIRECTOR' || $subType === 'SAFETY_OFFICER') $csmItemCode = '5.1.1';
                    else $csmItemCode = '기타';
                }
            }
            $subject = trim((string)($_POST['subject'] ?? ''));
            $method = trim((string)($_POST['method'] ?? '시청각 및 실습'));
            $plannedHours = (float)($_POST['planned_hours'] ?? 2.0);
            $location = trim((string)($_POST['location'] ?? '현장 사무실'));
            $instructorName = trim((string)($_POST['instructor_name'] ?? '김남균'));
            $instructorPosition = trim((string)($_POST['instructor_position'] ?? '과장'));
            $contentSummary = trim((string)($_POST['content_summary'] ?? ''));
            $contentSummaryLeft = trim((string)($_POST['content_summary_left'] ?? ''));
            $contentSummaryRight = trim((string)($_POST['content_summary_right'] ?? ''));
            if ($contentSummary === '' && ($contentSummaryLeft !== '' || $contentSummaryRight !== '')) {
                $contentSummary = ($contentSummaryLeft ? "[좌측 본문]\n" . $contentSummaryLeft : "") . ($contentSummaryRight ? "\n\n[우측 본문]\n" . $contentSummaryRight : "");
            }
            $evalOpinion = trim((string)($_POST['evaluation_opinion'] ?? ''));
            $isDemo = (int)($_POST['is_demo'] ?? 0);
            
            // Scheduling flags
            $isCustomSchedule = (int)($_POST['is_custom_schedule'] ?? 0);
            $designatedBy = trim((string)($_POST['designated_by'] ?? '김남균'));
            $courseId = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
            $sessionRound = (int)($_POST['session_round'] ?? 1);
            $totalRounds = (int)($_POST['total_rounds'] ?? ($trainingType === 'SPECIAL' ? 2 : 1));
            $planSource = trim((string)($_POST['planned_content_source'] ?? ''));
            $isEstimated = (int)($_POST['is_estimated'] ?? 0);
            
            // MSDS details if applicable (item 8)
            $msdsId = trim((string)($_POST['msds_material_id'] ?? ''));
            $msdsName = trim((string)($_POST['msds_material_name'] ?? ''));
            $msdsDate = trim((string)($_POST['msds_revision_date'] ?? ''));
            $msdsCount = trim((string)($_POST['msds_revision_count'] ?? ''));
            
            $attendeesRaw = json_decode((string)($_POST['attendees_json'] ?? '[]'), true);
            if (!is_array($attendeesRaw)) $attendeesRaw = [];
            
            $now = date('Y-m-d H:i:s');
            
            $db->beginTransaction();
            
            if ($id === 0) {
                // INSERT NEW
                $trainingNo = csm_generate_training_no($trainingDate);
                $insSql = "INSERT INTO csm_trainings (
                    training_no, training_date, start_time, end_time, training_type, sub_type, csm_item_code, subject, method,
                    planned_hours, location, instructor_name, instructor_position, content_summary,
                    content_summary_left, content_summary_right,
                    evaluation_opinion, msds_material_id, msds_material_name, msds_revision_date,
                    msds_revision_count, is_custom_schedule, designated_by, course_id, session_round,
                    total_rounds, planned_content_source, is_estimated, status, revision_num, is_demo,
                    created_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 'DRAFT', 1, ?,
                    ?, ?, ?
                )";
                $stmt = $db->prepare($insSql);
                $stmt->execute([
                    $trainingNo, $trainingDate, $startTime, $endTime, $trainingType, $subType, $csmItemCode, $subject, $method,
                    $plannedHours, $location, $instructorName, $instructorPosition, $contentSummary,
                    $contentSummaryLeft, $contentSummaryRight,
                    $evalOpinion, $msdsId, $msdsName, $msdsDate,
                    $msdsCount, $isCustomSchedule, $designatedBy, $courseId, $sessionRound,
                    $totalRounds, $planSource, $isEstimated, $isDemo,
                    $actorName, $now, $now
                ]);
                $id = (int)$db->lastInsertId();
                
                // If created from plan, link plan to this executed training
                $fromPlanId = (int)($_POST['from_plan_id'] ?? 0);
                if ($fromPlanId > 0) {
                    $db->prepare("UPDATE csm_training_plans SET status = 'EXECUTED', linked_training_id = ? WHERE id = ?")->execute([$id, $fromPlanId]);
                }
                
                // If special training without course_id, set course_id = id
                if ($trainingType === 'SPECIAL' && empty($courseId)) {
                    $db->prepare("UPDATE csm_trainings SET course_id = ? WHERE id = ?")->execute([$id, $id]);
                }
            } else {
                // UPDATE EXISTING
                $curStmt = $db->prepare("SELECT * FROM csm_trainings WHERE id = ?");
                $curStmt->execute([$id]);
                $old = $curStmt->fetch();
                if (!$old) {
                    $db->rollBack();
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => '기록을 찾을 수 없습니다.']);
                    exit;
                }
                
                // If old was FINALIZED, record Revision History
                if ($old['status'] === 'FINALIZED') {
                    $changeReason = trim((string)($_POST['change_reason'] ?? ''));
                    if ($changeReason === '') {
                        $changeReason = '교육일지 내용 및 관련 증빙 서류 갱신';
                    }
                    
                    // Fetch old attendees and photos for snapshot
                    $oldAtt = $db->query("SELECT * FROM csm_training_attendees WHERE training_id = {$id}")->fetchAll();
                    $oldPhoto = $db->query("SELECT * FROM csm_training_photos WHERE training_id = {$id}")->fetchAll();
                    $snapshot = json_encode(['training' => $old, 'attendees' => $oldAtt, 'photos' => $oldPhoto], JSON_UNESCAPED_UNICODE);
                    
                    $revNum = (int)$old['revision_num'] + 1;
                    $revSql = "INSERT INTO csm_training_revisions (training_id, revision_num, changed_by, changed_at, change_reason, snapshot_json) VALUES (?, ?, ?, ?, ?, ?)";
                    $db->prepare($revSql)->execute([$id, $revNum, $actorName, $now, $changeReason, $snapshot]);
                    
                    $updateRev = $revNum;
                } else {
                    $updateRev = (int)$old['revision_num'];
                }
                
                $upSql = "UPDATE csm_trainings SET 
                    training_date = ?, start_time = ?, end_time = ?, training_type = ?, sub_type = ?, csm_item_code = ?, subject = ?, method = ?,
                    planned_hours = ?, location = ?, instructor_name = ?, instructor_position = ?, content_summary = ?,
                    content_summary_left = ?, content_summary_right = ?,
                    evaluation_opinion = ?, msds_material_id = ?, msds_material_name = ?, msds_revision_date = ?,
                    msds_revision_count = ?, is_custom_schedule = ?, designated_by = ?, session_round = ?, total_rounds = ?,
                    planned_content_source = ?, is_estimated = ?, is_demo = ?, revision_num = ?, updated_at = ?
                    WHERE id = ?";
                $stmt = $db->prepare($upSql);
                $stmt->execute([
                    $trainingDate, $startTime, $endTime, $trainingType, $subType, $csmItemCode, $subject, $method,
                    $plannedHours, $location, $instructorName, $instructorPosition, $contentSummary,
                    $contentSummaryLeft, $contentSummaryRight,
                    $evalOpinion, $msdsId, $msdsName, $msdsDate,
                    $msdsCount, $isCustomSchedule, $designatedBy, $sessionRound, $totalRounds,
                    $planSource, $isEstimated, $isDemo, $updateRev, $now, $id
                ]);
            }
            
            // Sync Attendees (Snapshot)
            $db->prepare("DELETE FROM csm_training_attendees WHERE training_id = ?")->execute([$id]);
            $insAttStmt = $db->prepare("INSERT INTO csm_training_attendees (
                training_id, employee_id, employee_no, name_snapshot, team_snapshot, position_snapshot,
                is_completed, completed_hours, session_round, note
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($attendeesRaw as $att) {
                $empId = (int)($att['employee_id'] ?? 0);
                $empNo = (string)($att['employee_no'] ?? '');
                $name = (string)($att['name'] ?? '');
                $team = (string)($att['team'] ?? '');
                $pos = (string)($att['position'] ?? '');
                $isComp = (int)($att['is_completed'] ?? 1);
                $compHours = $isComp ? $plannedHours : 0.0;
                $note = (string)($att['note'] ?? '');
                
                $insAttStmt->execute([
                    $id, $empId, $empNo, $name, $team, $pos, $isComp, $compHours, $sessionRound, $note
                ]);
            }
            
            // Handle Photo Uploads if any
            if (!empty($_FILES['photos']['name'][0])) {
                $insPhotoStmt = $db->prepare("INSERT INTO csm_training_photos (training_id, session_round, original_name, stored_name, file_size, mime_type, caption, display_order, is_print, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                
                // Get existing count for display_order
                $curOrder = (int)$db->query("SELECT COALESCE(MAX(display_order), 0) FROM csm_training_photos WHERE training_id = {$id}")->fetchColumn();
                
                for ($i = 0; $i < count($_FILES['photos']['name']); $i++) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                        $f = [
                            'name' => $_FILES['photos']['name'][$i],
                            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                            'size' => $_FILES['photos']['size'][$i],
                            'error' => $_FILES['photos']['error'][$i],
                        ];
                        $upRes = csm_handle_upload($f, 'photos', $allowed);
                        if ($upRes['ok']) {
                            $curOrder++;
                            $insPhotoStmt->execute([
                                $id, $sessionRound, $upRes['original_name'], $upRes['stored_name'], $upRes['file_size'], $upRes['mime_type'], "현장 사진 " . $curOrder, $curOrder, $now
                            ]);
                        }
                    }
                }
            }
            
            $db->commit();
            csm_json_response(['ok' => true, 'id' => $id, 'message' => '성공적으로 저장되었습니다.']);
            break;

        // ── UPLOAD SCANNED EVIDENCE DOCUMENT (서명본 또는 이수증) ───
        case 'upload_scan':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            $docType = trim((string)($_POST['doc_type'] ?? 'signed')); // 'signed' (서명본) or 'cert' (이수증)

            if (empty($_FILES['scan_file']['name'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '파일을 선택해 주세요.']);
                exit;
            }
            $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
            $res = csm_handle_upload($_FILES['scan_file'], 'scanned_docs', $allowed);
            if (!$res['ok']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $res['error']]);
                exit;
            }
            $now = date('Y-m-d H:i:s');
            if ($docType === 'cert') {
                $up = $db->prepare("UPDATE csm_trainings SET cert_doc_filename = ?, cert_doc_uploaded_at = ? WHERE id = ?");
                $up->execute([$res['stored_name'], $now, $id]);
                $msg = '교육이수증(수료증)이 정상 등록되었습니다.';
            } else {
                $up = $db->prepare("UPDATE csm_trainings SET scanned_doc_filename = ?, scanned_doc_uploaded_at = ? WHERE id = ?");
                $up->execute([$res['stored_name'], $now, $id]);
                $msg = '수기 서명 교육일지 스캔본이 정상 등록되었습니다.';
            }
            echo json_encode([
                'ok' => true,
                'doc_type' => $docType,
                'filename' => $res['stored_name'],
                'message' => $msg
            ]);
            break;

        // ── PHOTO MANAGEMENT: REORDER / CAPTION / TOGGLE PRINT ───
        case 'photo_reorder':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                exit;
            }
            $items = json_decode((string)($_POST['photos_json'] ?? '[]'), true);
            if (!is_array($items)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '잘못된 데이터입니다.']);
                exit;
            }
            $stmt = $db->prepare("UPDATE csm_training_photos SET display_order = ?, caption = ?, is_print = ? WHERE id = ?");
            foreach ($items as $item) {
                $pid = (int)($item['id'] ?? 0);
                $ord = (int)($item['display_order'] ?? 1);
                $cap = trim((string)($item['caption'] ?? ''));
                $prt = (int)($item['is_print'] ?? 1);
                $stmt->execute([$ord, $cap, $prt, $pid]);
            }
            echo json_encode(['ok' => true, 'message' => '사진 설정이 변경되었습니다.']);
            break;

        case 'delete_photo':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
                exit;
            }
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $p = $db->query("SELECT * FROM csm_training_photos WHERE id = {$photoId}")->fetch();
            if ($p) {
                $fPath = CSM_UPLOAD_PHOTOS_DIR . '/' . $p['stored_name'];
                if (file_exists($fPath)) @unlink($fPath);
                $db->prepare("DELETE FROM csm_training_photos WHERE id = ?")->execute([$photoId]);
            }
            echo json_encode(['ok' => true, 'message' => '사진이 삭제되었습니다.']);
            break;

        // ── FINALIZE TRAINING RECORD ─────────────────────────────
        case 'finalize':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '확정 권한이 없습니다.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);

            // Validate that hand-signed scan or completion certificate exists
            $chk = $db->prepare("SELECT id, training_no, scanned_doc_filename, cert_doc_filename, status FROM csm_trainings WHERE id = ?");
            $chk->execute([$id]);
            $t = $chk->fetch();
            if (!$t) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '해당 교육 기록을 찾을 수 없습니다.']);
                exit;
            }

            if (empty($t['scanned_doc_filename']) && empty($t['cert_doc_filename'])) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'error' => '확정완료를 하려면 자필 서명된 [교육일지 서명 스캔본] 또는 [교육이수증 스캔본] 중 하나가 등록되어 있어야 합니다.'
                ]);
                exit;
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE csm_trainings SET status = 'FINALIZED', finalized_at = ?, finalized_by = ? WHERE id = ?");
            $stmt->execute([$now, $actorName, $id]);
            echo json_encode(['ok' => true, 'message' => '교육 기록이 성공적으로 확정완료되었습니다.']);
            break;

        // ── STATS: COMPANY METRICS & ATTENDEE STATUS ─────────────
        case 'stats':
            $year = (int)($_GET['year'] ?? date('Y'));
            $isDemo = (int)($_GET['is_demo'] ?? 0);
            
            $employees = csm_get_employees();
            
            // Get all training attendance for year
            $sql = "SELECT a.employee_no, a.name_snapshot, t.training_type, t.sub_type, t.training_date, a.completed_hours, t.course_id, t.session_round
                    FROM csm_training_attendees a
                    JOIN csm_trainings t ON a.training_id = t.id
                    WHERE a.is_completed = 1 AND t.is_demo = ? AND t.training_date LIKE ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$isDemo, "{$year}-%"]);
            $logs = $stmt->fetchAll();
            
            $stats = [];
            foreach ($employees as $emp) {
                $eno = $emp['employee_no'];
                $stats[$eno] = [
                    'employee_no' => $eno,
                    'name' => $emp['name'],
                    'team' => $emp['team'],
                    'position' => $emp['position'],
                    'status' => $emp['employment_status'],
                    'regular_h1' => 0.0,
                    'regular_h2' => 0.0,
                    'special_total' => 0.0,
                    'msds_total' => 0.0,
                    'other_courses' => [], // tracked individually (1h each)
                    'total_hours' => 0.0,
                    'special_satisfied' => false, // 8h check
                ];
            }
            
            foreach ($logs as $l) {
                $eno = $l['employee_no'];
                if (!isset($stats[$eno])) continue;
                $month = (int)substr($l['training_date'], 5, 2);
                $hrs = (float)$l['completed_hours'];
                $type = $l['training_type'];
                
                if ($type === 'REGULAR') {
                    if ($month <= 6) $stats[$eno]['regular_h1'] += $hrs;
                    else $stats[$eno]['regular_h2'] += $hrs;
                } elseif ($type === 'SPECIAL') {
                    $stats[$eno]['special_total'] += $hrs;
                } elseif ($type === 'MSDS') {
                    $stats[$eno]['msds_total'] += $hrs;
                } elseif ($type === 'OTHER') {
                    $st = $l['sub_type'] ?: '기타';
                    $stats[$eno]['other_courses'][$st] = ($stats[$eno]['other_courses'][$st] ?? 0.0) + $hrs;
                }
                $stats[$eno]['total_hours'] += $hrs;
            }
            
            foreach ($stats as &$st) {
                $st['special_satisfied'] = ($st['special_total'] >= 8.0);
            }
            unset($st);
            
            echo json_encode([
                'ok' => true,
                'year' => $year,
                'notice' => '※ 본 통계는 회사 운영규칙에 따른 집계이며, 법정 의무 인정 여부는 별도 행정 확인이 필요합니다.',
                'stats' => array_values($stats)
            ]);
            break;

        // ── BACKUP PACKAGE ───────────────────────────────────────
        
        // ── CSM CHAPTER 5 COMPLIANCE SCORE SUMMARY ───────────────
        case 'csm_part5_summary':
            $year = (int)($_GET['year'] ?? 2026);
            $isDemo = (int)($_GET['is_demo'] ?? 0);
            
            // 5.1.1 직무교육 (2.0점) - 정연탁(6h), 김남균(16h) (스캔본/이수증 첨부 필수)
            $stmt = $db->prepare("SELECT t.*, a.completed_hours, a.name_snapshot 
                                  FROM csm_trainings t
                                  JOIN csm_training_attendees a ON t.id = a.training_id
                                  WHERE t.csm_item_code = '5.1.1' AND t.is_demo = ? AND t.training_date LIKE ? AND a.is_completed = 1
                                    AND (
                                      (t.scanned_doc_filename IS NOT NULL AND t.scanned_doc_filename != '')
                                      OR (t.cert_doc_filename IS NOT NULL AND t.cert_doc_filename != '')
                                    )");
            $stmt->execute([$isDemo, "{$year}-%"]);
            $dutyRows = $stmt->fetchAll();
            $hasDirector = false;
            $hasOfficer = false;
            foreach ($dutyRows as $r) {
                if (strpos($r['name_snapshot'], '정연탁') !== false && (float)$r['completed_hours'] >= 6.0) $hasDirector = true;
                if (strpos($r['name_snapshot'], '김남균') !== false && (float)$r['completed_hours'] >= 16.0) $hasOfficer = true;
            }
            $score511 = ($hasDirector && $hasOfficer) ? 2.0 : (($hasDirector || $hasOfficer) ? 1.0 : 0.0);

            // 5.1.2 정기교육 (2.0점) - 1.1.3 VFL 방식의 경과월 대비 서명본 완료 월수 비례 산정 (스캔본 첨부 필수)
            $cutoffMonth = ($year < (int)date('Y')) ? 12 : (($year === (int)date('Y')) ? (int)date('n') : 0);
            $stmt = $db->prepare("SELECT DISTINCT SUBSTR(training_date, 6, 2) as m
                                  FROM csm_trainings 
                                  WHERE csm_item_code = '5.1.2' 
                                    AND is_demo = ? 
                                    AND training_date LIKE ? 
                                    AND (
                                      (scanned_doc_filename IS NOT NULL AND scanned_doc_filename != '')
                                      OR (cert_doc_filename IS NOT NULL AND cert_doc_filename != '')
                                    )");
            $stmt->execute([$isDemo, "{$year}-%"]);
            $completedMonths = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $completedCount = count($completedMonths);
            $score512 = ($cutoffMonth > 0) ? round(($completedCount * 2.0) / $cutoffMonth, 2) : 0.0;
            if ($score512 > 2.0) $score512 = 2.0;

            // 5.1.3 신규채용시 교육 (2.0점) - 사람수 대비 실시완료(스캔본 첨부 필수) 자동 점수 산출
            $stmtTargets = $db->prepare("SELECT DISTINCT a.name_snapshot 
                                         FROM csm_trainings t
                                         JOIN csm_training_attendees a ON t.id = a.training_id
                                         WHERE t.csm_item_code = '5.1.3' AND t.is_demo = ? AND t.training_date LIKE ?
                                           AND a.name_snapshot NOT IN ('김종훈', '진종철', '김남균')");
            $stmtTargets->execute([$isDemo, "{$year}-%"]);
            $allNhTargets = $stmtTargets->fetchAll(PDO::FETCH_COLUMN);
            $targetCount = max(5, count($allNhTargets));

            $stmtCompleted = $db->prepare("SELECT DISTINCT a.name_snapshot 
                                           FROM csm_trainings t
                                           JOIN csm_training_attendees a ON t.id = a.training_id
                                           WHERE t.csm_item_code = '5.1.3' AND t.is_demo = ? AND t.training_date LIKE ? AND a.is_completed = 1 AND a.completed_hours >= 8.0
                                             AND a.name_snapshot NOT IN ('김종훈', '진종철', '김남균')
                                             AND (
                                               (t.scanned_doc_filename IS NOT NULL AND t.scanned_doc_filename != '')
                                               OR (t.cert_doc_filename IS NOT NULL AND t.cert_doc_filename != '')
                                             )");
            $stmtCompleted->execute([$isDemo, "{$year}-%"]);
            $nhMet = $stmtCompleted->fetchAll(PDO::FETCH_COLUMN);
            $completedNhCount = count($nhMet);

            $multiplier513 = $targetCount > 0 ? round($completedNhCount / (float)$targetCount, 2) : 0.0;
            if ($multiplier513 > 1.0) $multiplier513 = 1.0;
            $score513 = round($multiplier513 * 2.0, 2);

            // 5.1.4 특별교육 (1.0점) - 5개 공종 각 2회 분할 8시간 (스캔본 첨부 필수)
            $stmt = $db->prepare("SELECT course_id, COUNT(DISTINCT session_round) as rounds, SUM(planned_hours) as total_hrs 
                                  FROM csm_trainings 
                                  WHERE csm_item_code = '5.1.4' AND is_demo = ? AND training_date LIKE ?
                                    AND (
                                      (scanned_doc_filename IS NOT NULL AND scanned_doc_filename != '')
                                      OR (cert_doc_filename IS NOT NULL AND cert_doc_filename != '')
                                    )
                                  GROUP BY course_id");
            $stmt->execute([$isDemo, "{$year}-%"]);
            $spCourses = $stmt->fetchAll();
            $spCompleteCount = 0;
            foreach ($spCourses as $sc) {
                if ((int)$sc['rounds'] >= 2) $spCompleteCount++;
            }
            $score514 = ($spCompleteCount >= 5) ? 1.0 : round(($spCompleteCount / 5.0) * 1.0, 1);

            // 5.1.5 MSDS 교육 (1.0점) - 12개 물질 및 개정시 교육 (스캔본 첨부 필수)
            $stmt = $db->prepare("SELECT COUNT(*) FROM csm_trainings 
                                  WHERE csm_item_code = '5.1.5' AND is_demo = ? AND training_date LIKE ?
                                    AND (
                                      (scanned_doc_filename IS NOT NULL AND scanned_doc_filename != '')
                                      OR (cert_doc_filename IS NOT NULL AND cert_doc_filename != '')
                                    )");
            $stmt->execute([$isDemo, "{$year}-%"]);
            $msdsCount = (int)$stmt->fetchColumn();
            $score515 = ($msdsCount >= 10) ? 1.0 : round(($msdsCount / 10.0) * 1.0, 1);

            // 5.2.2 안전체험교육장 (2.0점) - 현대기전 16명 전원 수료 (스캔본/이수증 첨부 필수)
            $stmt = $db->prepare("SELECT COUNT(DISTINCT a.employee_no) FROM csm_training_attendees a
                                  JOIN csm_trainings t ON a.training_id = t.id
                                  WHERE t.csm_item_code = '5.2.2' AND t.is_demo = ? AND t.training_date LIKE ? AND a.is_completed = 1
                                    AND (
                                      (t.scanned_doc_filename IS NOT NULL AND t.scanned_doc_filename != '')
                                      OR (t.cert_doc_filename IS NOT NULL AND t.cert_doc_filename != '')
                                    )");
            $stmt->execute([$isDemo, "{$year}-%"]);
            $expCount = (int)$stmt->fetchColumn();
            $score522 = ($expCount >= 16) ? 2.0 : round(($expCount / 16.0) * 2.0, 1);

            $totalScore = round($score511 + $score512 + $score513 + $score514 + $score515 + $score522, 1);

            echo json_encode([
                'ok' => true,
                'year' => $year,
                'total_score' => $totalScore,
                'max_score' => 10.0,
                'is_perfect' => ($totalScore >= 10.0),
                'items' => [
                    '5.1.1' => [
                        'code' => '5.1.1',
                        'name' => '안전보건관리책임자/담당자 직무교육',
                        'points' => 2.0,
                        'score' => $score511,
                        'status' => ($score511 >= 2.0) ? 'COMPLETED' : 'WARNING',
                        'details' => "책임자(정연탁 6h): " . ($hasDirector ? "완료" : "미완료(스캔필요)") . ", 담당자(김남균 16h): " . ($hasOfficer ? "완료" : "미완료(스캔필요)"),
                        'files' => ['안전보건책임자교육 이수증-정연탁2026.pdf', '안전보건관리담당자 이수증.pdf (김남균 16h)']
                    ],
                    '5.1.2' => [
                        'code' => '5.1.2',
                        'name' => '정기교육 (근로자 매월 2h + 관리감독자 16h)',
                        'points' => 2.0,
                        'score' => $score512,
                        'status' => ($score512 >= 2.0) ? 'COMPLETED' : 'WARNING',
                        'details' => "정기교육 서명본 완료: {$completedCount}/{$cutoffMonth}개월 ({$score512}점)",
                        'files' => ['정기안전교육_2026.xlsx (12개 시트 완비)', '관리감독자 16시간 연간이수증']
                    ],
                    '5.1.3' => [
                        'code' => '5.1.3',
                        'name' => '신규채용시 안전보건교육 (입사자 8시간)',
                        'points' => 2.0,
                        'score' => $score513,
                        'target_count' => $targetCount,
                        'completed_count' => $completedNhCount,
                        'status' => ($score513 >= 2.0) ? 'COMPLETED' : 'WARNING',
                        'details' => ($completedNhCount > 0) ? ("신규입사자 서명본 완료: {$completedNhCount}/{$targetCount}명 (" . implode(', ', $nhMet) . ") [{$score513}점]") : "신규입사자 서명본 미등록 (0/{$targetCount}명, 0.0점 처리)",
                        'files' => ['신규채용시 안전보건교육_통합정정본(2025-2026).xlsx']
                    ],
                    '5.1.4' => [
                        'code' => '5.1.4',
                        'name' => '특별안전보건교육 Matrix (5개 고위험 공종 8h 분할)',
                        'points' => 1.0,
                        'score' => $score514,
                        'status' => ($score514 >= 1.0) ? 'COMPLETED' : 'WARNING',
                        'details' => ($spCompleteCount > 0) ? "Matrix {$spCompleteCount}/5개 공종 스캔 완료 ({$score514}점)" : "스캔본 미등록 (0/5개 공종, 0점 처리)",
                        'files' => ['특별안전교육.xlsx (2026년 분할회차 시트)']
                    ],
                    '5.1.5' => [
                        'code' => '5.1.5',
                        'name' => '물질안전보건자료(MSDS) 교육 및 개정시 교육',
                        'points' => 1.0,
                        'score' => $score515,
                        'status' => ($score515 >= 1.0) ? 'COMPLETED' : 'WARNING',
                        'details' => ($msdsCount > 0) ? "12개 화학물질 스캔본 {$msdsCount}회 ({$score515}점)" : "스캔본 미등록 (0회, 0점 처리)",
                        'files' => ['MSDS_교육_2026.xlsx (12개 물질 및 개정시 교육)']
                    ],
                    '5.2.2' => [
                        'code' => '5.2.2',
                        'name' => '한라시멘트 사업장 안전체험교육장 이수 실적',
                        'points' => 2.0,
                        'score' => $score522,
                        'status' => ($score522 >= 2.0) ? 'COMPLETED' : 'WARNING',
                        'details' => ($expCount > 0) ? "이수증 확인: {$expCount}/16명 (" . round(($expCount/16.0)*100) . "%, {$score522}점)" : "이수증/스캔본 미등록 (0/16명, 0점 처리)",
                        'files' => ['체험교육/교육 참석자 명단_(주)현대기전 임직원 명단.xlsx', '공동훈련 실시계획서']
                    ]
                ]
            ]);
            break;

        case 'backup':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '백업 권한이 없습니다.']);
                exit;
            }
            $zipPath = csm_create_backup();
            echo json_encode([
                'ok' => true,
                'filename' => basename($zipPath),
                'size' => filesize($zipPath),
                'download_url' => 'api.php?action=download_backup&file=' . urlencode(basename($zipPath))
            ]);
            break;

        case 'download_backup':
            if (!csm_can_manage($user)) {
                http_response_code(403);
                die('권한이 없습니다.');
            }
            $file = basename((string)($_GET['file'] ?? ''));
            $zipPath = CSM_BACKUP_DIR . '/' . $file;
            if (!file_exists($zipPath)) {
                http_response_code(404);
                die('백업 파일을 찾을 수 없습니다.');
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => '유효하지 않은 요청 액션입니다.']);
            break;
    }
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    csm_json_response(['ok' => false, 'error' => '서버 내부 오류: ' . $e->getMessage()], 500);
}
