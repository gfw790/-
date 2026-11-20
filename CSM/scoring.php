<?php
declare(strict_types=1);

function csm_scoring_db(): ?PDO
{
    $path = __DIR__ . '/storage/leadership.sqlite';
    if (!is_file($path)) return null;
    return new PDO('sqlite:file:' . str_replace('\\', '/', $path) . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function csm_activity_plan_summary(?PDO $db, int $year, float $points): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
        'multiplier' => 0.0, 'revision' => 0, 'automatic' => true, 'record_id' => null];
    if ($db === null) return $empty;
    require_once __DIR__ . '/activity_plan_store.php';
    $query = $db->prepare("SELECT id,payload FROM leadership_records WHERE item='1.2.1' AND year=? ORDER BY id DESC");
    $query->execute([$year]);
    $fileQuery = $db->prepare('SELECT file_kind,stored_name,deleted_at FROM leadership_files WHERE record_id=?');
    $best = $empty;
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $record) {
        try {
            $data = json_decode($record['payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            continue;
        }
        if (!is_array($data)) continue;
        $fileQuery->execute([(int)$record['id']]);
        $status = activity_plan_status($data, $fileQuery->fetchAll(PDO::FETCH_ASSOC));
        $counts = $status['files'];
        $evidenceCount = array_sum($counts);
        $planEvidence = ($counts['plan'] ?? 0) + ($counts['approval'] ?? 0) > 0;
        $multiplier = 0.0;
        if ($status['planned'] && $planEvidence) {
            $multiplier = 0.5;
            if ($status['approved']) {
                $multiplier = 0.8;
                if ($status['quantitative'] && $status['basis']) {
                    $multiplier = $status['consulted'] ? 1.0 : 0.9;
                }
            }
        }
        $earned = round($points * $multiplier, 4);
        if ($earned > $best['earned'] || ($best['record_id'] === null && $evidenceCount > 0)) {
            $best = ['earned' => $earned, 'evidence_count' => $evidenceCount,
                'state' => $evidenceCount === 0 ? 'no_evidence' : ($earned > 0 ? 'assessed' : 'pending'),
                'multiplier' => $multiplier, 'revision' => 0, 'automatic' => true,
                'record_id' => (int)$record['id']];
        }
    }
    return $best;
}

function csm_activity_monitor_summary(?PDO $db, int $year, float $points, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $thisYear = (int)$asOf->format('Y');
    $cutoff = $year < $thisYear ? 12 : ($year === $thisYear ? (int)$asOf->format('n') : 0);
    $completedQuarters = $year < $thisYear ? 4 : ($year === $thisYear ? intdiv(max(0, $cutoff - 1), 3) : 0);
    $monthly = [];
    $reports = [];
    $evidenceCount = 0;
    if ($db !== null && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_records'")->fetch()) {
        require_once __DIR__ . '/activity_monitor_store.php';
        $records = activity_monitor_records($db, $year);
        $files = activity_monitor_files($db, $year);
        foreach ($records as $kind => $periods) foreach ($periods as $number => $record) {
            $verified = activity_monitor_verified_files($files[(int)$record['id']] ?? []);
            if (!$verified || trim((string)($record['data']['summary'] ?? '')) === '') continue;
            if ($kind === 'month' && $number > $cutoff) continue;
            if ($kind === 'quarter' && $number > $completedQuarters) continue;
            $evidenceCount += count($verified);
            if ($kind === 'month' && $number <= $cutoff) $monthly[$number] = true;
            if ($kind === 'quarter' && trim((string)($record['data']['reviewer'] ?? '')) !== '') $reports[$number] = true;
        }
    }
    $quarterReportsComplete = $completedQuarters > 0 && count(array_intersect(range(1, $completedQuarters), array_keys($reports))) === $completedQuarters;
    $halfYearCoverage = $completedQuarters >= 2 && count(array_intersect([1, 2], array_keys($reports))) > 0
        && ($completedQuarters < 3 || count(array_intersect([3, 4], array_keys($reports))) > 0);
    $multiplier = 0.0;
    if ($evidenceCount > 0) {
        $multiplier = 0.5;
        if ($reports) $multiplier = 0.8;
        if ($halfYearCoverage) $multiplier = 0.9;
        if ($cutoff > 0 && count($monthly) === $cutoff && $quarterReportsComplete) $multiplier = 1.0;
    }
    return ['earned' => round($points * $multiplier, 4), 'multiplier' => $multiplier,
        'evidence_count' => $evidenceCount, 'monthly_count' => count($monthly),
        'report_count' => count($reports), 'cutoff' => $cutoff, 'completed_quarters' => $completedQuarters,
        'state' => $evidenceCount === 0 ? 'no_evidence' : 'assessed', 'revision' => 0, 'automatic' => true];
}

function csm_activity_organization_summary(?PDO $db, int $year, float $points): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
        'multiplier' => 0.0, 'revision' => 0, 'automatic' => true, 'record_id' => null, 'status_text' => '편성 안됨'];
    if ($db === null) return $empty;
    require_once __DIR__ . '/activity_organization_store.php';
    $record = activity_org_records($db, $year);
    if (!$record) return $empty;

    $status = activity_org_status($record['data'], $record['files']);
    $multiplier = (float)$status['multiplier'];
    $earned = round($points * $multiplier, 4);
    $evidenceCount = (int)$status['files_count'];

    return [
        'earned' => $earned,
        'evidence_count' => $evidenceCount,
        'state' => $evidenceCount === 0 ? 'no_evidence' : ($earned > 0 ? 'assessed' : 'pending'),
        'multiplier' => $multiplier,
        'status_text' => $status['status_text'],
        'revision' => (int)($record['revision'] ?? 1),
        'automatic' => true,
        'record_id' => (int)$record['id'],
    ];
}

function csm_activity_improvement_summary(?PDO $db, int $year, float $points): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
        'multiplier' => 0.0, 'revision' => 0, 'automatic' => true, 'record_id' => null, 'status_text' => '관리 안됨'];
    if ($db === null) return $empty;
    require_once __DIR__ . '/activity_improvement_store.php';
    $record = activity_improvement_records($db, $year);
    if (!$record) return $empty;

    $status = activity_improvement_status($record['data'], $record['files']);
    $multiplier = (float)$status['multiplier'];
    $earned = round($points * $multiplier, 4);
    $evidenceCount = (int)$status['files_count'];

    return [
        'earned' => $earned,
        'evidence_count' => $evidenceCount,
        'state' => $evidenceCount === 0 ? 'no_evidence' : ($earned > 0 ? 'assessed' : 'pending'),
        'multiplier' => $multiplier,
        'status_text' => $status['status_text'],
        'revision' => (int)($record['revision'] ?? 1),
        'automatic' => true,
        'record_id' => (int)$record['id'],
    ];
}

function csm_training_doc_ratio(string $itemCode, int $year, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $todayStr = $asOf->format('Y-m-d');
    $currentYear = (int)$asOf->format('Y');

    $totalCount = 0;
    $dueCount = 0;
    $completedCount = 0;

    $csmDbPath = __DIR__ . '/education/storage/db/csm.db';
    if (is_file($csmDbPath)) {
        try {
            $pdo = new PDO('sqlite:' . $csmDbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $stmt = $pdo->prepare("
                SELECT training_date, scanned_doc_filename, cert_doc_filename
                FROM csm_trainings
                WHERE csm_item_code = :code
                  AND strftime('%Y', training_date) = :year
                ORDER BY training_date ASC, id ASC
            ");
            $stmt->execute([':code' => $itemCode, ':year' => (string)$year]);
            foreach ($stmt->fetchAll() as $row) {
                $totalCount++;
                $isDue = ($year < $currentYear) || ($year === $currentYear && (string)$row['training_date'] <= $todayStr);
                if ($isDue) {
                    $dueCount++;
                    if (!empty($row['scanned_doc_filename']) || !empty($row['cert_doc_filename'])) {
                        $completedCount++;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('csm_training_doc_ratio error: ' . $e->getMessage());
        }
    }

    $multiplier = $dueCount > 0 ? round(min(1.0, $completedCount / (float)$dueCount), 2) : 0.0;
    return [
        'total_count' => $totalCount,
        'due_count' => $dueCount,
        'completed_count' => $completedCount,
        'multiplier' => $multiplier,
        'as_of' => $todayStr,
    ];
}

function csm_special_edu_matrix_summary(?PDO $db, int $year, float $points = 1.0, ?DateTimeImmutable $asOf = null): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence', 'multiplier' => 0.0, 'revision' => 0, 'automatic' => true, 'status_text' => '미실시'];
    if ($db === null) return $empty;
    require_once __DIR__ . '/education_matrix_store.php';
    $records = education_matrix_records($db, $year);
    $status = education_matrix_status($records);
    $ratio = csm_training_doc_ratio('5.1.4', $year, $asOf);
    $mult = (float)$ratio['multiplier'];
    $completed = (int)$ratio['completed_count'];
    $due = (int)$ratio['due_count'];
    $tasksCount = (int)$status['special_task_count'];
    return [
        'earned' => round($points * $mult, 2),
        'evidence_count' => $completed,
        'state' => $completed > 0 ? 'assessed' : 'no_evidence',
        'multiplier' => $mult,
        'status_text' => $completed > 0 ? "서명본 완료 {$completed}/{$due}건" : "서명본 미등록 (0/{$due}건 · Matrix {$tasksCount}종 보유)",
        'revision' => (int)$records['special']['revision'],
        'automatic' => true,
        'task_count' => $tasksCount,
        'assigned_total' => (int)$status['special_assigned_total'],
        'due_count' => $due,
        'completed_count' => $completed,
        'total_count' => (int)$ratio['total_count'],
    ];
}

function csm_msds_edu_summary(?PDO $db, int $year, float $points = 1.0, ?DateTimeImmutable $asOf = null): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence', 'multiplier' => 0.0, 'revision' => 0, 'automatic' => true, 'status_text' => '미실시'];
    if ($db === null) return $empty;
    require_once __DIR__ . '/education_matrix_store.php';
    $records = education_matrix_records($db, $year);
    $status = education_matrix_status($records);
    $ratio = csm_training_doc_ratio('5.1.5', $year, $asOf);
    $mult = (float)$ratio['multiplier'];
    $completed = (int)$ratio['completed_count'];
    $due = (int)$ratio['due_count'];
    $substancesCount = (int)$status['msds_substance_count'];
    return [
        'earned' => round($points * $mult, 2),
        'evidence_count' => $completed,
        'state' => $completed > 0 ? 'assessed' : 'no_evidence',
        'multiplier' => $mult,
        'status_text' => $completed > 0 ? "서명본 완료 {$completed}/{$due}건" : "서명본 미등록 (0/{$due}건 · Matrix {$substancesCount}종 보유)",
        'revision' => (int)$records['msds']['revision'],
        'automatic' => true,
        'substance_count' => $substancesCount,
        'assigned_total' => (int)$status['msds_assigned_total'],
        'due_count' => $due,
        'completed_count' => $completed,
        'total_count' => (int)$ratio['total_count'],
    ];
}

function csm_scoring_evidence(?PDO $db, int $year, ?int $month = null, bool $signedOnly = false): array
{
    $counts = [];
    if ($db !== null && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_files'")->fetch()) {
        $columns = array_column($db->query('PRAGMA table_info(leadership_files)')->fetchAll(), 'name');
        if (!$signedOnly || in_array('file_kind', $columns, true)) {
            $active = in_array('deleted_at', $columns, true) ? ' AND f.deleted_at IS NULL' : '';
            if ($signedOnly) $active .= " AND f.file_kind='signed_scan'";
            $query = $db->prepare('SELECT r.item,f.stored_name FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE r.year=?' . $active . ($month !== null ? ' AND substr(r.activity_date,6,2)=?' : ''));
            $query->execute($month !== null ? [$year, sprintf('%02d', $month)] : [$year]);
            foreach ($query->fetchAll() as $file) {
                if (!preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', $file['stored_name'])) continue;
                if (is_file(__DIR__ . '/storage/leadership_evidence/' . $file['stored_name'])) $counts[$file['item']] = ($counts[$file['item']] ?? 0) + 1;
            }
        }
    }

    // Integrate Group 5 trainings evidence from csm.db (csm_trainings)
    $csmDbPath = __DIR__ . '/education/storage/db/csm.db';
    if (is_file($csmDbPath)) {
        try {
            $csmPdo = new PDO('sqlite:' . $csmDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $monthFilter = ($month !== null) ? " AND strftime('%m', training_date) = :month" : "";
            $stmt = $csmPdo->prepare("
                SELECT csm_item_code, COUNT(*) as cnt
                FROM csm_trainings
                WHERE strftime('%Y', training_date) = :year
                  AND (
                    (scanned_doc_filename IS NOT NULL AND scanned_doc_filename != '')
                    OR (cert_doc_filename IS NOT NULL AND cert_doc_filename != '')
                  )
                  {$monthFilter}
                GROUP BY csm_item_code
            ");
            $params = [':year' => (string)$year];
            if ($month !== null) {
                $params[':month'] = sprintf('%02d', $month);
            }
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $item = $row['csm_item_code'];
                $counts[$item] = ($counts[$item] ?? 0) + (int)$row['cnt'];
            }
        } catch (Throwable $e) {
            error_log('scoring.php csm.db evidence count error: ' . $e->getMessage());
        }
    }

    return $counts;
}

function csm_vfl_monthly_summary(?PDO $db, int $year, float $points = 2.0, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $currentYear = (int)$asOf->format('Y');
    $cutoff = $year < $currentYear ? 12 : ($year === $currentYear ? (int)$asOf->format('n') : 0);
    $saved = [];
    if ($db !== null && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_monthly_assessments'")->fetch()) {
        $query = $db->prepare("SELECT * FROM leadership_monthly_assessments WHERE year=? AND item='1.1.3'");
        $query->execute([$year]);
        foreach ($query->fetchAll() as $row) $saved[(int)$row['month']] = $row;
    }
    $months = [];
    $total = 0.0;
    $evidenceCount = $assessedCount = 0;
    for ($month = 1; $month <= 12; $month++) {
        $count = csm_scoring_evidence($db, $year, $month, true)['1.1.3'] ?? 0;
        $assessment = $saved[$month] ?? null;
        $multiplier = $assessment === null ? null : (float)$assessment['multiplier'];
        $earned = $count > 0 && $multiplier !== null && $month <= $cutoff ? round($points * $multiplier, 4) : 0.0;
        $months[$month] = ['earned' => $earned, 'evidence_count' => $count, 'multiplier' => $multiplier,
            'revision' => $assessment === null ? 0 : (int)$assessment['revision'],
            'state' => $month > $cutoff ? 'future' : ($count === 0 ? 'no_evidence' : ($assessment === null ? 'pending' : 'assessed'))];
        if ($month <= $cutoff) {
            $total += $earned;
            $evidenceCount += $count;
            if ($assessment !== null && $count > 0) $assessedCount++;
        }
    }
    return ['months' => $months, 'cutoff' => $cutoff, 'total' => round($total, 4),
        'earned' => $cutoff > 0 ? round($total / $cutoff, 4) : 0.0, 'evidence_count' => $evidenceCount,
        'assessed_months' => $assessedCount,
        'state' => $evidenceCount === 0 ? 'no_evidence' : ($assessedCount === 0 ? 'pending' : 'assessed')];
}

function csm_meeting_summary(?PDO $db, int $year, array $criterion, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $currentYear = (int)$asOf->format('Y');
    $cutoff = $year < $currentYear ? 12 : ($year === $currentYear ? (int)$asOf->format('n') : 0);
    $held = $evidenceCount = 0;
    $credits = 0.0;
    if ($db !== null && $cutoff > 0 && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_records'")->fetch()) {
        $query = $db->prepare("SELECT r.id,r.payload,f.stored_name FROM leadership_records r
            LEFT JOIN leadership_files f ON f.record_id=r.id AND f.deleted_at IS NULL
            WHERE r.item='1.1.4' AND r.year=? AND r.activity_date >= ? AND r.activity_date <= ? ORDER BY r.id");
        $lastDay = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $cutoff)))->format('Y-m-t');
        $query->execute([$year, sprintf('%04d-01-01', $year), $lastDay]);
        $records = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $file) {
            $id = (int)$file['id'];
            $records[$id] ??= ['attendance' => json_decode($file['payload'], true, 512, JSON_THROW_ON_ERROR)['attendance'] ?? '', 'evidence' => 0];
            if (is_string($file['stored_name']) && preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', $file['stored_name'])
                && is_file(__DIR__.'/storage/leadership_evidence/'.$file['stored_name'])) $records[$id]['evidence']++;
        }
        foreach ($records as $record) {
            if (!in_array($record['attendance'], ['representative', 'delegate', 'absent'], true)) continue;
            $held++;
            $evidenceCount += $record['evidence'];
            if ($record['evidence'] > 0) $credits += $record['attendance'] === 'representative' ? 1 : ($record['attendance'] === 'delegate' ? 0.5 : 0);
        }
    }
    // Unrecorded/unsupported months remain in the elapsed-month denominator, like VFL.
    $rate = $cutoff > 0 ? $credits / $cutoff : 0.0;
    $multiplier = 0.0;
    foreach ($criterion['criteria'] as $level => $label) {
        if (is_numeric($level) && $label !== '' && $label !== '-' && $rate + 1e-9 >= (float)$level) $multiplier = max($multiplier, (float)$level);
    }
    return ['earned' => $evidenceCount > 0 ? round((float)$criterion['points'] * $multiplier, 4) : 0.0,
        'evidence_count' => $evidenceCount, 'state' => $evidenceCount > 0 ? 'assessed' : 'no_evidence',
        'multiplier' => $multiplier, 'revision' => 0, 'automatic' => true,
        'attendance_rate' => round($rate * 100, 2), 'held_months' => $held, 'credits' => $credits, 'cutoff' => $cutoff];
}

function csm_regular_edu_monthly_summary(int $year, float $points = 2.0, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $currentYear = (int)$asOf->format('Y');
    $cutoff = $year < $currentYear ? 12 : ($year === $currentYear ? (int)$asOf->format('n') : 0);

    $months = [];
    $total = 0.0;
    $evidenceCount = 0;
    $completedMonths = 0;

    $csmDbPath = __DIR__ . '/education/storage/db/csm.db';
    $monthlyEdu = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthlyEdu[$m] = ['count' => 0, 'has_scan' => false, 'finalized' => false];
    }

    if (is_file($csmDbPath)) {
        try {
            $pdo = new PDO('sqlite:' . $csmDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $stmt = $pdo->prepare("
                SELECT strftime('%m', training_date) as m,
                       COUNT(*) as cnt,
                       SUM(CASE WHEN (scanned_doc_filename IS NOT NULL AND scanned_doc_filename != '') OR (cert_doc_filename IS NOT NULL AND cert_doc_filename != '') THEN 1 ELSE 0 END) as scan_cnt,
                       SUM(CASE WHEN status = 'FINALIZED' THEN 1 ELSE 0 END) as fin_cnt
                FROM csm_trainings
                WHERE csm_item_code = '5.1.2' AND strftime('%Y', training_date) = :year
                GROUP BY strftime('%m', training_date)
            ");
            $stmt->execute([':year' => (string)$year]);
            foreach ($stmt->fetchAll() as $row) {
                $m = (int)$row['m'];
                if (isset($monthlyEdu[$m])) {
                    $monthlyEdu[$m]['count'] = (int)$row['cnt'];
                    $monthlyEdu[$m]['has_scan'] = ((int)$row['scan_cnt'] > 0);
                    $monthlyEdu[$m]['finalized'] = ((int)$row['fin_cnt'] > 0);
                }
            }
        } catch (Throwable $e) {
            error_log('csm_regular_edu_monthly_summary error: ' . $e->getMessage());
        }
    }

    for ($month = 1; $month <= 12; $month++) {
        $data = $monthlyEdu[$month];
        $isDone = $data['has_scan'];
        $earned = ($isDone && $month <= $cutoff) ? $points : 0.0;
        $state = ($month > $cutoff) ? 'future' : ($isDone ? 'assessed' : 'no_evidence');

        $months[$month] = [
            'earned' => $earned,
            'evidence_count' => $data['has_scan'] ? 1 : 0,
            'multiplier' => $isDone ? 1.0 : 0.0,
            'state' => $state,
            'is_done' => $isDone
        ];

        if ($month <= $cutoff) {
            $total += $earned;
            if ($isDone) {
                $completedMonths++;
                $evidenceCount++;
            }
        }
    }

    $finalScore = ($cutoff > 0) ? round($total / $cutoff, 2) : 0.0;

    return [
        'months' => $months,
        'cutoff' => $cutoff,
        'total' => round($total, 2),
        'earned' => $finalScore,
        'evidence_count' => $evidenceCount,
        'completed_months' => $completedMonths,
        'state' => $evidenceCount === 0 ? 'no_evidence' : 'assessed'
    ];
}

function csm_new_hire_edu_summary(int $year, float $points = 2.0): array
{
    $csmDbPath = __DIR__ . '/education/storage/db/csm.db';
    $targetCount = 5;
    $completedCount = 0;
    $completedNames = [];
    $allTargets = [];

    if (is_file($csmDbPath)) {
        try {
            $pdo = new PDO('sqlite:' . $csmDbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // 대상자 명단 조회 (강사/관리자 제외)
            $stmtTargets = $pdo->prepare("
                SELECT DISTINCT a.name_snapshot 
                FROM csm_trainings t
                JOIN csm_training_attendees a ON t.id = a.training_id
                WHERE t.csm_item_code = '5.1.3'
                  AND strftime('%Y', t.training_date) = :year
                  AND a.name_snapshot NOT IN ('김종훈', '진종철', '김남균')
            ");
            $stmtTargets->execute([':year' => (string)$year]);
            $allTargets = $stmtTargets->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($allTargets)) {
                $targetCount = count($allTargets);
            }

            // 서명본 스캔/수료증 첨부 및 8시간 이수 완료자 조회
            $stmtDone = $pdo->prepare("
                SELECT DISTINCT a.name_snapshot 
                FROM csm_trainings t
                JOIN csm_training_attendees a ON t.id = a.training_id
                WHERE t.csm_item_code = '5.1.3'
                  AND strftime('%Y', t.training_date) = :year
                  AND a.is_completed = 1
                  AND a.completed_hours >= 8.0
                  AND a.name_snapshot NOT IN ('김종훈', '진종철', '김남균')
                  AND (
                    (t.scanned_doc_filename IS NOT NULL AND t.scanned_doc_filename != '')
                    OR (t.cert_doc_filename IS NOT NULL AND t.cert_doc_filename != '')
                  )
            ");
            $stmtDone->execute([':year' => (string)$year]);
            $completedNames = $stmtDone->fetchAll(PDO::FETCH_COLUMN);
            $completedCount = count($completedNames);
        } catch (Throwable $e) {
            error_log('csm_new_hire_edu_summary error: ' . $e->getMessage());
        }
    }

    $multiplier = $targetCount > 0 ? round($completedCount / (float)$targetCount, 2) : 0.0;
    if ($multiplier > 1.0) $multiplier = 1.0;
    $earned = round($multiplier * $points, 2);

    return [
        'target_count' => $targetCount,
        'completed_count' => $completedCount,
        'completed_names' => $completedNames,
        'all_targets' => $allTargets,
        'evidence_count' => $completedCount,
        'multiplier' => $multiplier,
        'earned' => $earned,
        'state' => $completedCount === 0 ? 'no_evidence' : 'assessed',
        'details' => $completedCount > 0
            ? "신규채용자 {$completedCount}/{$targetCount}명 8h 서명본 완료 (" . implode(', ', $completedNames) . ")"
            : "신규채용자 서명본 미등록 (0/{$targetCount}명)"
    ];
}

function csm_experience_edu_summary(?PDO $db, int $year, float $points = 2.0, ?DateTimeImmutable $asOf = null): array
{
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $currentYear = (int)$asOf->format('Y');
    $currentMonth = (int)$asOf->format('n');
    $todayStr = $asOf->format('Y-m-d');

    $h1Due = ($year < $currentYear) || ($year === $currentYear);
    $h2Due = ($year < $currentYear) || ($year === $currentYear && $currentMonth >= 7);
    $dueHalves = ($h1Due ? 1 : 0) + ($h2Due ? 1 : 0);

    $halves = [
        1 => ['label' => '상반기 (1~6월)', 'due' => $h1Due, 'completed' => false, 'evidence_count' => 0, 'sessions' => []],
        2 => ['label' => '하반기 (7~12월)', 'due' => $h2Due, 'completed' => false, 'evidence_count' => 0, 'sessions' => []],
    ];

    $csmDbPath = __DIR__ . '/education/storage/db/csm.db';
    if (is_file($csmDbPath)) {
        try {
            $pdo = new PDO('sqlite:' . $csmDbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $stmt = $pdo->prepare("
                SELECT id, training_no, training_date, subject, status, sub_type,
                       scanned_doc_filename, cert_doc_filename
                FROM csm_trainings
                WHERE csm_item_code = '5.2.2'
                  AND strftime('%Y', training_date) = :year
                  AND (sub_type = 'EXPERIENCE_TRAINING' OR subject LIKE '%안전체험%')
                ORDER BY training_date ASC, id ASC
            ");
            $stmt->execute([':year' => (string)$year]);
            foreach ($stmt->fetchAll() as $row) {
                $m = (int)substr((string)$row['training_date'], 5, 2);
                $half = ($m <= 6) ? 1 : 2;
                $hasDoc = (!empty($row['scanned_doc_filename']) || !empty($row['cert_doc_filename']));
                $isPastOrToday = ((string)$row['training_date'] <= $todayStr);
                $isDone = $hasDoc && $isPastOrToday;
                $halves[$half]['sessions'][] = [
                    'id' => (int)$row['id'],
                    'training_no' => (string)$row['training_no'],
                    'training_date' => (string)$row['training_date'],
                    'subject' => (string)$row['subject'],
                    'status' => (string)$row['status'],
                    'has_doc' => $hasDoc,
                    'is_done' => $isDone,
                ];
                if ($isDone) {
                    $halves[$half]['completed'] = true;
                    $halves[$half]['evidence_count']++;
                }
            }
        } catch (Throwable $e) {
            error_log('csm_experience_edu_summary csm.db error: ' . $e->getMessage());
        }
    }

    // Also accept valid leadership_files uploaded directly to 5.2.2 (excluding 항만안전)
    if ($db !== null && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_files'")->fetch()) {
        try {
            $q = $db->prepare("
                SELECT r.activity_date, r.title, f.stored_name
                FROM leadership_files f
                JOIN leadership_records r ON r.id = f.record_id
                WHERE r.year = ? AND r.item = '5.2.2' AND f.deleted_at IS NULL
                  AND r.title LIKE '%안전체험%'
            ");
            $q->execute([$year]);
            foreach ($q->fetchAll() as $f) {
                if (!preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', (string)$f['stored_name'])) continue;
                if (!is_file(__DIR__ . '/storage/leadership_evidence/' . $f['stored_name'])) continue;
                if ((string)$f['activity_date'] > $todayStr) continue;
                $m = (int)substr((string)$f['activity_date'], 5, 2);
                $half = ($m <= 6) ? 1 : 2;
                $halves[$half]['completed'] = true;
                $halves[$half]['evidence_count']++;
            }
        } catch (Throwable $e) {
            error_log('csm_experience_edu_summary leadership error: ' . $e->getMessage());
        }
    }

    $completedHalves = 0;
    $evidenceCount = 0;
    foreach ([1, 2] as $h) {
        if ($halves[$h]['due'] && $halves[$h]['completed']) {
            $completedHalves++;
        }
        $evidenceCount += $halves[$h]['evidence_count'];
    }

    $multiplier = $dueHalves > 0 ? round($completedHalves / (float)$dueHalves, 2) : 0.0;
    $earned = round($points * $multiplier, 2);

    return [
        'halves' => $halves,
        'due_halves' => $dueHalves,
        'completed_halves' => $completedHalves,
        'evidence_count' => $evidenceCount,
        'multiplier' => $multiplier,
        'earned' => $earned,
        'state' => $evidenceCount === 0 ? 'no_evidence' : 'assessed',
        'revision' => 0,
        'automatic' => true,
        'as_of' => $todayStr,
    ];
}

function csm_bonus_certification_summary(?PDO $db, int $year): array
{
    $counts = ['iso' => 0, 'risk_recognition' => 0];
    if ($db !== null && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bonus_evidence'")->fetch()) {
        $columns = array_column($db->query('PRAGMA table_info(bonus_evidence)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (in_array('evidence_kind', $columns, true)) {
            $query = $db->prepare("SELECT evidence_kind,stored_name FROM bonus_evidence WHERE year=? AND item='6.1.2' AND deleted_at IS NULL");
            $query->execute([$year]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $file) {
                $kind = (string)$file['evidence_kind'];
                $name = (string)$file['stored_name'];
                if (!array_key_exists($kind, $counts) || !preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', $name)) continue;
                $path = __DIR__ . '/storage/bonus_evidence/' . $name;
                if (is_file($path) && filesize($path) > 0) $counts[$kind]++;
            }
        }
    }
    $earned = (float)((int)($counts['iso'] > 0) + (int)($counts['risk_recognition'] > 0));
    return ['earned' => $earned, 'evidence_count' => array_sum($counts),
        'state' => $earned > 0 ? 'assessed' : 'no_evidence',
        'multiplier' => null, 'revision' => 0, 'automatic' => true,
        'kind_counts' => $counts, 'base_points_max' => 2.0];
}

function csm_bonus_near_miss_summary(int $year, ?DateTimeImmutable $asOf = null): array
{
    require_once __DIR__ . '/bonus_near_miss.php';
    try {
        $reports = bonus_near_miss_summary($year, $asOf);
        $headcount = bonus_current_headcount($asOf);
    } catch (Throwable $error) {
        error_log('CSM 6.1.1 score: ' . $error->getMessage());
        return ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'unavailable',
            'multiplier' => null, 'revision' => 0, 'automatic' => true,
            'rate' => null, 'report_count' => null, 'headcount' => null,
            'headcount_unknown' => null, 'headcount_date' => null, 'source' => 'internal_incident_date'];
    }
    $score = bonus_near_miss_points($reports['total'], $headcount);
    return ['earned' => $score['earned'], 'evidence_count' => $reports['total'],
        'state' => $score['state'], 'multiplier' => null, 'revision' => 0,
        'automatic' => true, 'rate' => $score['rate'], 'report_count' => $reports['total'],
        'headcount' => $headcount['confirmed'], 'headcount_unknown' => $headcount['unknown'],
        'headcount_date' => $headcount['date'], 'source' => 'internal_incident_date'];
}

function csm_tbm_activity_summary(?PDO $db, int $year): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
        'multiplier' => 0.0, 'revision' => 0, 'automatic' => true];
    if ($db === null || !$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='csm_tbm_evidence'")->fetch()) return $empty;
    require_once __DIR__ . '/tbm_activity_store.php';
    $monthly = csm_tbm_monthly_summary($db, $year);
    return ['earned' => $monthly['earned'], 'evidence_count' => $monthly['evidence_count'],
        'state' => $monthly['state'], 'multiplier' => $monthly['multiplier'],
        'revision' => 0, 'automatic' => true, 'monthly' => $monthly];
}

function csm_ra_procedure_score_summary(?PDO $db, int $year): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
        'multiplier' => null, 'revision' => 0, 'automatic' => false];
    if ($db === null || !$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='csm_ra_procedure_files'")->fetch()
        || !$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='csm_ra_procedure_assessments'")->fetch()) return $empty;
    require_once __DIR__ . '/risk_procedure_store.php';
    return csm_ra_procedure_summary($db, $year);
}

function csm_sop_procedure_score_summary(?PDO $db, int $year, float $points = 2.0): array
{
    $empty = ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
        'multiplier' => 0.0, 'revision' => 0, 'automatic' => true, 'assessment' => null];
    if ($db === null) return $empty;

    $evidenceCount = 0;
    if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='csm_risk_tasks'")->fetch()) {
        $q = $db->prepare("SELECT evidence_path FROM csm_risk_tasks WHERE section='2.2' AND substr(record_date,1,4)=? AND evidence_path != ''");
        $q->execute([(string)$year]);
        foreach ($q->fetchAll() as $row) {
            $stored = (string)$row['evidence_path'];
            if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', $stored)) {
                $path = __DIR__ . '/storage/risk_task_evidence/' . $stored;
                if (is_file($path) && filesize($path) > 0) {
                    $evidenceCount++;
                }
            }
        }
    }

    $assessment = null;
    if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='csm_sop_assessments'")->fetch()) {
        $aq = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM csm_sop_assessments WHERE year=?');
        $aq->execute([$year]);
        $assessment = $aq->fetch() ?: null;
    }

    if ($evidenceCount === 0) {
        return ['earned' => 0.0, 'evidence_count' => 0, 'state' => 'no_evidence',
            'multiplier' => 0.0, 'revision' => 0, 'automatic' => $assessment === null, 'assessment' => $assessment];
    }

    if ($assessment !== null) {
        $mult = (float)$assessment['multiplier'];
        return ['earned' => round($points * $mult, 2), 'evidence_count' => $evidenceCount, 'state' => 'assessed',
            'multiplier' => $mult, 'revision' => 0, 'automatic' => false, 'assessment' => $assessment];
    }

    // 증빙 파일이 첨부된 경우, 표준서 DB 보유 및 정기 제·개정 이력 여부에 따라 자동 배율 적용
    $mult = 0.8;
    $sopDbPath = __DIR__ . '/storage/standard_procedure.sqlite';
    if (is_file($sopDbPath)) {
        try {
            $sopPdo = new PDO('sqlite:file:' . str_replace('\\', '/', $sopDbPath) . '?mode=ro', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $sopCnt = (int)$sopPdo->query('SELECT COUNT(*) FROM csm_sop_master')->fetchColumn();
            $revCnt = (int)$sopPdo->query('SELECT COUNT(*) FROM csm_sop_revisions')->fetchColumn();
            if ($sopCnt > 0 && $revCnt > 0) {
                $mult = 1.0; // 자체 작성(정기 개정)
            } elseif ($sopCnt > 0) {
                $mult = 0.9;
            }
        } catch (Throwable $e) {
            error_log('csm_sop_procedure_score_summary error: ' . $e->getMessage());
        }
    }

    return ['earned' => round($points * $mult, 2), 'evidence_count' => $evidenceCount, 'state' => 'assessed',
        'multiplier' => $mult, 'revision' => 0, 'automatic' => true, 'assessment' => null];
}

function csm_score_summary(?PDO $db, array $catalog, int $year): array
{
    $counts = csm_scoring_evidence($db, $year);
    $assessments = [];
    if ($db !== null && $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_assessments'")->fetch()) {
        $query = $db->prepare('SELECT * FROM leadership_assessments WHERE year=?');
        $query->execute([$year]);
        foreach ($query->fetchAll() as $row) $assessments[$row['item']] = $row;
    }
    $items = [];
    $groups = [];
    foreach ($catalog['groups'] as $group) $groups[$group['id']] = ['earned' => 0.0, 'normalized' => 0.0, 'evidence_items' => 0, 'assessed_items' => 0, 'na_points' => 0.0];
    foreach ($catalog['modules'] as $module) {
        foreach ($module['items'] as $criterion) {
            $code = $criterion['id'];
            $assessment = $assessments[$code] ?? null;
            $hasEvidence = ($counts[$code] ?? 0) > 0;
            $multiplier = $assessment === null ? null : (float)$assessment['multiplier'];
            // Recheck evidence at read time, including after deletion or a missing file.
            $earned = $hasEvidence && $multiplier !== null && $criterion['points'] !== null ? round($criterion['points'] * $multiplier, 4) : 0.0;
            $items[$code] = ['earned' => $earned, 'evidence_count' => $counts[$code] ?? 0,
                'state' => !$hasEvidence ? 'no_evidence' : ($assessment === null ? 'pending' : 'assessed'),
                'multiplier' => $multiplier, 'revision' => $assessment === null ? 0 : (int)$assessment['revision']];
            if ($code === '1.2.1') {
                $items[$code] = csm_activity_plan_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $earned > 0 ? $items[$code] : null;
            }
            if ($code === '6.1.2') {
                $items[$code] = csm_bonus_certification_summary($db, $year);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '6.1.1') {
                $items[$code] = csm_bonus_near_miss_summary($year);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if ($code === '1.2.2') {
                $items[$code] = csm_activity_monitor_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '1.2.3') {
                $items[$code] = csm_activity_organization_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '1.2.4') {
                $items[$code] = csm_activity_improvement_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '2.3.1') {
                $items[$code] = csm_tbm_activity_summary($db, $year);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if ($code === '2.1.1') {
                require_once __DIR__ . '/accident_investigation_store.php';
                $items[$code] = csm_ai_score_summary($year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = in_array($items[$code]['state'], ['assessed', 'na'], true) ? $items[$code] : null;
                if (!empty($items[$code]['is_na']) && $criterion['points'] !== null) {
                    $groups[$module['group']]['na_points'] += (float)$criterion['points'];
                }
            }
            if ($code === '2.1.2') {
                $items[$code] = csm_ra_procedure_score_summary($db, $year);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if ($code === '2.2.1') {
                $items[$code] = csm_sop_procedure_score_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if ($code === '2.6.2') {
                require_once __DIR__ . '/portable_tools_store.php';
                $items[$code] = csm_pt_score_summary($year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if ($code === '2.6.3') {
                require_once __DIR__ . '/hazardous_machinery_store.php';
                $items[$code] = csm_hm_score_summary($year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if (in_array($code, ['3.1.1', '3.1.2', '3.1.3', '3.1.4'], true)) {
                require_once __DIR__ . '/workplace_inspection_store.php';
                $items[$code] = csm_wi_score_summary($year, $code, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if (in_array($code, ['4.1.1', '4.1.2', '4.1.3'], true)) {
                require_once __DIR__ . '/waste_management_store.php';
                $items[$code] = csm_wm_score_summary($year, $code, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $items[$code]['state'] === 'assessed' ? $items[$code] : null;
            }
            if ($code === '1.1.3') {
                $monthly = csm_vfl_monthly_summary($db, $year, (float)$criterion['points']);
                $monthly['legacy_multiplier'] = isset($assessments[$code]) ? (float)$assessments[$code]['multiplier'] : null;
                $earned = $monthly['earned'];
                $hasEvidence = $monthly['evidence_count'] > 0;
                $assessment = $monthly['assessed_months'] > 0 ? $monthly : null;
                $items[$code] = ['earned' => $earned, 'evidence_count' => $monthly['evidence_count'], 'state' => $monthly['state'],
                    'multiplier' => null, 'revision' => 0, 'monthly' => $monthly];
            }
            if ($code === '1.1.4') {
                $items[$code] = csm_meeting_summary($db, $year, $criterion);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '5.1.2') {
                $monthly = csm_regular_edu_monthly_summary($year, (float)$criterion['points']);
                $earned = $monthly['earned'];
                $hasEvidence = $monthly['evidence_count'] > 0;
                $assessment = $monthly['completed_months'] > 0 ? $monthly : null;
                $items[$code] = [
                    'earned' => $earned,
                    'evidence_count' => $monthly['evidence_count'],
                    'state' => $monthly['state'],
                    'multiplier' => ($monthly['cutoff'] > 0) ? round($monthly['completed_months'] / $monthly['cutoff'], 2) : 0.0,
                    'revision' => 0,
                    'monthly' => $monthly
                ];
            }
            if ($code === '5.1.3') {
                $nhSummary = csm_new_hire_edu_summary($year, (float)$criterion['points']);
                $earned = $nhSummary['earned'];
                $hasEvidence = $nhSummary['evidence_count'] > 0;
                $assessment = $nhSummary['completed_count'] > 0 ? $nhSummary : null;
                $items[$code] = [
                    'earned' => $earned,
                    'evidence_count' => $nhSummary['evidence_count'],
                    'state' => $nhSummary['state'],
                    'multiplier' => $nhSummary['multiplier'],
                    'revision' => 0,
                    'summary' => $nhSummary
                ];
            }
            if ($code === '5.1.4') {
                $items[$code] = csm_special_edu_matrix_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '5.1.5') {
                $items[$code] = csm_msds_edu_summary($db, $year, (float)$criterion['points']);
                $earned = $items[$code]['earned'];
                $hasEvidence = $items[$code]['evidence_count'] > 0;
                $assessment = $hasEvidence ? $items[$code] : null;
            }
            if ($code === '5.2.2') {
                $expSummary = csm_experience_edu_summary($db, $year, (float)$criterion['points']);
                $items[$code] = $expSummary;
                $earned = $expSummary['earned'];
                $hasEvidence = $expSummary['evidence_count'] > 0;
                $assessment = $expSummary['completed_halves'] > 0 ? $expSummary : null;
            }
            $groups[$module['group']]['earned'] += $earned;
            if ($hasEvidence) $groups[$module['group']]['evidence_items']++;
            if ($hasEvidence && $assessment !== null) $groups[$module['group']]['assessed_items']++;
        }
    }
    foreach ($catalog['groups'] as $group) {
        if ($group['points'] !== null && $group['points'] > 0) {
            $effectivePoints = max(0.0, (float)$group['points'] - (float)($groups[$group['id']]['na_points'] ?? 0.0));
            $groups[$group['id']]['normalized'] = $effectivePoints > 0 ? round($groups[$group['id']]['earned'] / $effectivePoints * 100, 1) : 0.0;
        }
    }
    return ['items' => $items, 'groups' => $groups];
}
