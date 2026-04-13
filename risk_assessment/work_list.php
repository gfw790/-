<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_page_url(string $path, array $params = []): string
{
    $queryParams = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_string($value) && $value === '') {
            continue;
        }
        $queryParams[$key] = $value;
    }

    if (empty($queryParams)) {
        return $path;
    }

    return $path . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
}

function build_safety_standard_url(string $standardNo): string
{
    $standardNo = trim($standardNo);
    if ($standardNo === '') {
        return '/safety/index.html';
    }

    $tokens = preg_split('/[\r\n,;\/|]+/', $standardNo) ?: [];
    foreach ($tokens as $token) {
        $token = trim((string)$token);
        if ($token !== '') {
            $standardNo = $token;
            break;
        }
    }

    return '/safety/index.html?' . http_build_query([
        'jobPlan' => $standardNo,
        'query' => $standardNo,
    ], '', '&', PHP_QUERY_RFC3986);
}

function normalize_safety_standard_key(string $value): string
{
    $value = preg_replace('/\s+/u', '', trim($value)) ?? '';
    return strtoupper($value);
}

function split_safety_standard_numbers(string $value): array
{
    $parts = preg_split('/[\r\n,;\/|]+/', trim($value)) ?: [];
    $result = [];
    $seen = [];

    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }

        $key = normalize_safety_standard_key($part);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $part;
    }

    if (empty($result) && trim($value) !== '') {
        $result[] = trim($value);
    }

    return $result;
}

function render_safety_standard_buttons(string $value): string
{
    $numbers = split_safety_standard_numbers($value);
    if (empty($numbers)) {
        return '-';
    }

    $buttons = [];
    foreach ($numbers as $number) {
        $buttons[] = sprintf(
            '<button type="button" class="unit-code-link js-safety-standard-preview" data-standard-no="%s">%s</button>',
            h($number),
            h($number)
        );
    }

    return '<span class="safety-standard-list">' . implode('', $buttons) . '</span>';
}

function ordered_units_for_display(array $units): array
{
    $ordered = [];
    $seen = [];

    foreach ($units as $unit) {
        $unitRaId = (int)($unit['unit_ra_id'] ?? 0);
        if ($unitRaId <= 0 || isset($seen[$unitRaId])) {
            continue;
        }
        $seen[$unitRaId] = true;
        $ordered[] = $unit;
    }

    return $ordered;
}

function render_unit_title_list(array $units): string
{
    $orderedUnits = ordered_units_for_display($units);
    if (empty($orderedUnits)) {
        return '-';
    }

    $titles = [];
    foreach ($orderedUnits as $unit) {
        $title = trim((string)($unit['unit_title'] ?? ''));
        if ($title === '') {
            $title = '-';
        }
        $titles[] = $title;
    }

    if (empty($titles)) {
        return '-';
    }

    if (count($titles) === 1) {
        return h($titles[0]);
    }

    $lines = [];
    foreach ($titles as $title) {
        $lines[] = '<span class="unit-multi-line">' . h($title) . '</span>';
    }

    return '<span class="unit-multi-lines">' . implode('', $lines) . '</span>';
}

function render_unit_code_preview_buttons(array $units): string
{
    $orderedUnits = ordered_units_for_display($units);
    if (empty($orderedUnits)) {
        return '-';
    }

    $buttons = [];
    foreach ($orderedUnits as $unit) {
        $unitRaId = (int)($unit['unit_ra_id'] ?? 0);
        if ($unitRaId <= 0) {
            continue;
        }
        $unitCode = trim((string)($unit['unit_code'] ?? ''));
        if ($unitCode === '') {
            $unitCode = '미등록';
        }

        $buttons[] = sprintf(
            '<span class="unit-multi-line"><button type="button" class="unit-code-link js-unit-preview" data-unit-ra-id="%d">%s</button></span>',
            $unitRaId,
            h($unitCode)
        );
    }

    if (empty($buttons)) {
        return '-';
    }

    if (count($buttons) === 1) {
        return $buttons[0];
    }

    return '<span class="unit-multi-lines">' . implode('', $buttons) . '</span>';
}

function render_safety_standard_buttons_from_units(array $units): string
{
    $orderedUnits = ordered_units_for_display($units);
    if (empty($orderedUnits)) {
        return '-';
    }

    $items = [];
    foreach ($orderedUnits as $unit) {
        $rawValue = (string)($unit['safe_work_standard_no'] ?? '');
        $numbers = split_safety_standard_numbers($rawValue);
        if (empty($numbers)) {
            $items[] = '<span class="unit-multi-line">-</span>';
            continue;
        }

        $buttons = [];
        foreach ($numbers as $number) {
            $buttons[] = sprintf(
                '<button type="button" class="unit-code-link js-safety-standard-preview" data-standard-no="%s">%s</button>',
                h($number),
                h($number)
            );
        }
        $items[] = '<span class="unit-multi-line safety-standard-unit-item">' . implode('', $buttons) . '</span>';
    }

    if (empty($items)) {
        return '-';
    }

    if (count($items) === 1) {
        return $items[0];
    }

    return '<span class="unit-multi-lines">' . implode('', $items) . '</span>';
}

function render_completion_badge(bool $isCompleted): string
{
    $className = $isCompleted ? 'status-badge is-complete' : 'status-badge is-pending';
    $label = $isCompleted ? '완료' : '대기';

    return sprintf(
        '<span class="%s">%s</span>',
        h($className),
        h($label)
    );
}

function render_hazard_completion_badge(array $report): string
{
    $isCompleted = (bool)($report['hazard_review_completed'] ?? false);
    if (!$isCompleted) {
        return render_completion_badge(false);
    }

    $participants = $report['hazard_participants'] ?? [];
    if (!is_array($participants)) {
        $participants = [];
    }

    $participantCount = (int)($report['hazard_participant_count'] ?? count($participants));
    if ($participantCount <= 0 && !empty($participants)) {
        $participantCount = count($participants);
    }

    $participantsJson = json_encode(
        array_values($participants),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($participantsJson) || $participantsJson === '') {
        $participantsJson = '[]';
    }

    $workTitle = trim((string)($report['work_title'] ?? ''));
    $ariaLabel = $participantCount > 0
        ? sprintf('위험성평가 완료, 참여인원 %d명 보기', $participantCount)
        : '위험성평가 완료, 참여인원 보기';

    return sprintf(
        '<button type="button" class="%s" data-report-title="%s" data-participant-count="%d" data-participants="%s" aria-label="%s">완료</button>',
        h('status-badge is-complete status-badge-button js-hazard-participant-trigger'),
        h($workTitle),
        $participantCount,
        h($participantsJson),
        h($ariaLabel)
    );
}

function report_view_content_hidden(array $report): bool
{
    return (bool)($report['work_input_completed'] ?? false)
        && (bool)($report['hazard_review_completed'] ?? false);
}

function tableExists(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => $tableName]);

    $cache[$tableName] = (int)$stmt->fetchColumn() > 0;
    return $cache[$tableName];
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    $cache[$cacheKey] = (int)$stmt->fetchColumn() > 0;
    return $cache[$cacheKey];
}

function deleteReportImages(PDO $pdo, int $reportId): void
{
    if (!tableExists($pdo, 'work_report_image')) {
        return;
    }

    $stmt = $pdo->prepare("SELECT file_path FROM work_report_image WHERE report_id = :report_id");
    $stmt->execute([':report_id' => $reportId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
        if (!is_string($filePath) || trim($filePath) === '') {
            continue;
        }

        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

function deleteReportRelatedRows(PDO $pdo, int $reportId): void
{
    $relatedTables = [
        'work_report_selected_unit',
        'work_report_worker_hazard_selection',
        'work_report_detail',
        'work_report_task',
        'work_report_tool',
        'work_report_image',
    ];

    foreach ($relatedTables as $tableName) {
        if (!tableExists($pdo, $tableName)) {
            continue;
        }

        $pdo->prepare("DELETE FROM {$tableName} WHERE report_id = :report_id")
            ->execute([':report_id' => $reportId]);
    }
}

function ensureWorkListTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report (
            report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            role_code VARCHAR(30) NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            team_name VARCHAR(100) NULL,
            work_title VARCHAR(255) NOT NULL,
            work_date DATE NOT NULL,
            work_place VARCHAR(255) NOT NULL,
            use_equipment_yn CHAR(1) NOT NULL DEFAULT 'N',
            note_html MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (report_id),
            KEY idx_work_report_unit_ra_id (unit_ra_id),
            KEY idx_work_report_work_date (work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try {
        $pdo->exec("ALTER TABLE work_report ADD COLUMN team_name VARCHAR(100) NULL AFTER user_name");
    } catch (Throwable $e) {
        // Column already exists.
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_selected_unit (
            report_selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_selection_id),
            UNIQUE KEY uk_work_report_selected_unit (report_id, unit_ra_id),
            KEY idx_work_report_selected_unit_report (report_id),
            KEY idx_work_report_selected_unit_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_detail (
            report_detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            task_name VARCHAR(255) NOT NULL,
            risk_code VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_detail_id),
            KEY idx_work_report_detail_report_id (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$userRole = (string)($user['role'] ?? '');
$isAdmin = auth_is_admin($user);
$canManage = auth_can_manage($user);
$canLead = auth_can_lead($user);
$isWorker = auth_is_worker($user);
$isLeaderOnly = $canLead && !$canManage;
$entryPage = $canLead && !$canManage ? 'leader_task_select.php' : 'task_select.php';
$adminManagerTeams = $isAdmin ? auth_read_teams() : [];

function work_list_entry_page(array $user, array $report, string $defaultPage): string
{
    if (auth_is_admin($user) && ((string)($report['role_code'] ?? '') === 'leader')) {
        return 'leader_task_select.php';
    }

    return $defaultPage;
}

function report_team_context(array $report): string
{
    $reportTeam = auth_normalize_team_name((string)($report['team_name'] ?? ''));
    if ($reportTeam !== '') {
        return $reportTeam;
    }

    $ownerAccount = auth_find_user((string)($report['user_login_id'] ?? ''));
    return auth_normalize_team_name((string)($ownerAccount['team'] ?? ''));
}

function filter_reports_for_user(array $reports, array $user): array
{
    $userRole = (string)($user['role'] ?? '');
    if (auth_is_admin($user) || $userRole === 'safety_manager') {
        return array_values($reports);
    }

    $visibleTeams = auth_work_list_visible_teams($user);
    $userLoginId = trim((string)($user['login_id'] ?? ''));
    $visibleTeamKeys = array_fill_keys(array_map('auth_team_key', $visibleTeams), true);

    return array_values(array_filter($reports, static function (array $report) use ($visibleTeamKeys, $userLoginId): bool {
        $reportTeam = auth_normalize_team_name((string)($report['team_name_context'] ?? ''));
        if (!empty($visibleTeamKeys)) {
            return $reportTeam !== '' && isset($visibleTeamKeys[auth_team_key($reportTeam)]);
        }

        return $userLoginId !== '' && (string)($report['user_login_id'] ?? '') === $userLoginId;
    }));
}

function work_list_team_has_leader_account(string $teamName): bool
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    if ($normalizedTeam === '') {
        return false;
    }

    $targetTeamKey = auth_team_key($normalizedTeam);
    foreach (auth_accounts() as $account) {
        $role = auth_normalize_role((string)($account['role'] ?? ''));
        if ($role !== 'leader') {
            continue;
        }

        $accountTeamKey = auth_team_key((string)($account['team'] ?? ''));
        if ($accountTeamKey === $targetTeamKey) {
            return true;
        }
    }

    return false;
}

$pdo = getDB();
ensureWorkListTables($pdo);
$hasSafeWorkStandardNo = columnExists($pdo, 'unit_ra_header', 'safe_work_standard_no');

$errorMessage = '';
$successMessage = isset($_GET['deleted']) && $_GET['deleted'] === '1'
    ? '작업이 삭제되었습니다.'
    : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_report') {
    $deleteReportId = (int)($_POST['report_id'] ?? 0);

    if (!$canManage) {
        $errorMessage = '삭제는 관리감독자 또는 운영자만 할 수 있습니다.';
    } elseif ($deleteReportId <= 0) {
        $errorMessage = '삭제할 작업을 찾을 수 없습니다.';
    } else {
        if ($isAdmin) {
            $ownerStmt = $pdo->prepare("
                SELECT report_id
                FROM work_report
                WHERE report_id = :report_id
                LIMIT 1
            ");
            $ownerStmt->execute([
                ':report_id' => $deleteReportId,
            ]);
        } else {
            $ownerStmt = $pdo->prepare("
                SELECT report_id
                FROM work_report
                WHERE report_id = :report_id
                  AND user_login_id = :user_login_id
                LIMIT 1
            ");
            $ownerStmt->execute([
                ':report_id' => $deleteReportId,
                ':user_login_id' => $user['login_id'],
            ]);
        }
        $deleteTarget = $ownerStmt->fetch();

        if (!$deleteTarget) {
            $errorMessage = $isAdmin ? '삭제할 작업을 찾을 수 없습니다.' : '본인이 등록한 작업만 삭제할 수 있습니다.';
        } else {
            try {
                $pdo->beginTransaction();
                deleteReportImages($pdo, $deleteReportId);
                deleteReportRelatedRows($pdo, $deleteReportId);
                $pdo->prepare("DELETE FROM work_report WHERE report_id = :report_id")
                    ->execute([':report_id' => $deleteReportId]);
                $pdo->commit();

                header('Location: work_list.php?deleted=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMessage = '작업 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
            }
        }
    }
}

$safeWorkStandardSelect = $hasSafeWorkStandardNo
    ? "h.safe_work_standard_no,"
    : "NULL AS safe_work_standard_no,";
$hazardWorkerSelectionCountSelect = tableExists($pdo, 'work_report_worker_hazard_selection')
    ? "(
            SELECT COUNT(*)
            FROM work_report_worker_hazard_selection ws
            WHERE ws.report_id = wr.report_id
        ) AS hazard_worker_selection_count,"
    : "0 AS hazard_worker_selection_count,";
$hazardParticipantCountSelect = tableExists($pdo, 'work_report_worker_hazard_selection')
    ? "(
            SELECT COUNT(DISTINCT ws.user_login_id)
            FROM work_report_worker_hazard_selection ws
            WHERE ws.report_id = wr.report_id
        ) AS hazard_participant_count,"
    : "0 AS hazard_participant_count,";
$hazardSelectionCountSelect = tableExists($pdo, 'work_report_hazard_selection')
    ? "(
            SELECT COUNT(*)
            FROM work_report_hazard_selection hs
            WHERE hs.report_id = wr.report_id
        ) AS hazard_selection_count,"
    : "0 AS hazard_selection_count,";
$hazardChangeRequestCountSelect = tableExists($pdo, 'work_report_hazard_change_request')
    ? "(
            SELECT COUNT(*)
            FROM work_report_hazard_change_request hcr
            WHERE hcr.report_id = wr.report_id
        ) AS hazard_change_request_count,"
    : "0 AS hazard_change_request_count,";
$hazardAdditionCountSelect = tableExists($pdo, 'work_report_hazard_addition')
    ? "(
            SELECT COUNT(*)
            FROM work_report_hazard_addition ha
            WHERE ha.report_id = wr.report_id
        ) AS hazard_addition_count,"
    : "0 AS hazard_addition_count,";

$reports = $pdo->query("
    SELECT
        wr.report_id,
        wr.unit_ra_id,
        wr.role_code,
        wr.user_login_id,
        wr.user_name,
        wr.team_name,
        wr.work_title,
        wr.work_date,
        wr.work_place,
        wr.use_equipment_yn,
        wr.created_at,
        h.unit_code,
        {$safeWorkStandardSelect}
        h.unit_title,
        {$hazardWorkerSelectionCountSelect}
        {$hazardParticipantCountSelect}
        {$hazardSelectionCountSelect}
        {$hazardChangeRequestCountSelect}
        {$hazardAdditionCountSelect}
        (
            SELECT COUNT(*)
            FROM work_report_detail wd
            WHERE wd.report_id = wr.report_id
        ) AS leader_detail_count
    FROM work_report wr
    LEFT JOIN unit_ra_header h
        ON h.unit_ra_id = wr.unit_ra_id
    ORDER BY wr.work_date DESC, wr.report_id DESC
")->fetchAll();

$selectedUnitsByReportId = [];
if (!empty($reports) && tableExists($pdo, 'work_report_selected_unit')) {
    $reportIds = array_values(array_unique(array_map(
        static fn($row) => (int)($row['report_id'] ?? 0),
        $reports
    )));
    $reportIds = array_values(array_filter($reportIds, static fn($id) => $id > 0));

    if (!empty($reportIds)) {
        $safeWorkStandardBySelectedUnit = $hasSafeWorkStandardNo
            ? "h.safe_work_standard_no"
            : "NULL AS safe_work_standard_no";
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $selectedUnitStmt = $pdo->prepare("
            SELECT
                su.report_id,
                su.unit_ra_id,
                h.unit_title,
                h.unit_code,
                {$safeWorkStandardBySelectedUnit}
            FROM work_report_selected_unit su
            LEFT JOIN unit_ra_header h
                ON h.unit_ra_id = su.unit_ra_id
            WHERE su.report_id IN ($placeholders)
            ORDER BY su.report_id ASC, su.sort_no ASC, su.report_selection_id ASC
        ");
        $selectedUnitStmt->execute($reportIds);
        foreach ($selectedUnitStmt->fetchAll() as $row) {
            $reportIdKey = (int)($row['report_id'] ?? 0);
            if ($reportIdKey <= 0) {
                continue;
            }
            if (!isset($selectedUnitsByReportId[$reportIdKey])) {
                $selectedUnitsByReportId[$reportIdKey] = [];
            }
            $selectedUnitsByReportId[$reportIdKey][] = [
                'unit_ra_id' => (int)($row['unit_ra_id'] ?? 0),
                'unit_title' => (string)($row['unit_title'] ?? ''),
                'unit_code' => (string)($row['unit_code'] ?? ''),
                'safe_work_standard_no' => (string)($row['safe_work_standard_no'] ?? ''),
            ];
        }
    }
}

foreach ($reports as &$report) {
    $resolvedTeamName = report_team_context($report);
    $report['team_name_context'] = $resolvedTeamName;
    $report['team_name_display'] = $resolvedTeamName !== '' ? $resolvedTeamName : '-';
    $selectedUnits = $selectedUnitsByReportId[(int)($report['report_id'] ?? 0)] ?? [];
    if (empty($selectedUnits) && (int)($report['unit_ra_id'] ?? 0) > 0) {
        $selectedUnits[] = [
            'unit_ra_id' => (int)($report['unit_ra_id'] ?? 0),
            'unit_title' => (string)($report['unit_title'] ?? ''),
            'unit_code' => (string)($report['unit_code'] ?? ''),
            'safe_work_standard_no' => (string)($report['safe_work_standard_no'] ?? ''),
        ];
    }
    $report['selected_units'] = $selectedUnits;
    $workInputCompleted = (int)($report['leader_detail_count'] ?? 0) > 0;
    $hazardSubmissionCount = (int)($report['hazard_worker_selection_count'] ?? 0)
        + (int)($report['hazard_selection_count'] ?? 0)
        + (int)($report['hazard_change_request_count'] ?? 0)
        + (int)($report['hazard_addition_count'] ?? 0);
    $report['work_input_completed'] = $workInputCompleted;
    $report['hazard_review_completed'] = $hazardSubmissionCount > 0;
    $report['hazard_participant_count'] = (int)($report['hazard_participant_count'] ?? 0);
}
unset($report);

$reports = filter_reports_for_user($reports, $user);

$hazardParticipantMap = [];
if (!empty($reports) && tableExists($pdo, 'work_report_worker_hazard_selection')) {
    $reportIds = array_values(array_unique(array_map(
        static fn($row) => (int)($row['report_id'] ?? 0),
        $reports
    )));
    $reportIds = array_values(array_filter($reportIds, static fn($id) => $id > 0));

    if (!empty($reportIds)) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $participantStmt = $pdo->prepare("
            SELECT
                report_id,
                user_login_id,
                user_name
            FROM work_report_worker_hazard_selection
            WHERE report_id IN ($placeholders)
            GROUP BY report_id, user_login_id, user_name
            ORDER BY user_name ASC, user_login_id ASC
        ");
        $participantStmt->execute($reportIds);
        foreach ($participantStmt->fetchAll() as $participantRow) {
            $mapReportId = (int)($participantRow['report_id'] ?? 0);
            if ($mapReportId <= 0) {
                continue;
            }
            if (!isset($hazardParticipantMap[$mapReportId])) {
                $hazardParticipantMap[$mapReportId] = [];
            }
            $hazardParticipantMap[$mapReportId][] = [
                'user_name' => (string)($participantRow['user_name'] ?? ''),
                'user_login_id' => (string)($participantRow['user_login_id'] ?? ''),
            ];
        }
    }
}

foreach ($reports as &$report) {
    $reportIdKey = (int)($report['report_id'] ?? 0);
    $participants = $hazardParticipantMap[$reportIdKey] ?? [];
    $report['hazard_participants'] = $participants;
    if ((int)($report['hazard_participant_count'] ?? 0) <= 0 && !empty($participants)) {
        $report['hazard_participant_count'] = count($participants);
    }
}
unset($report);

$showLeaderInputColumn = false;
if (auth_is_admin($user)) {
    // 운영자는 전체 팀을 보므로 목록 데이터에 작업지휘자 팀이 있으면 칼럼을 보여준다.
    foreach ($reports as $report) {
        if (work_list_team_has_leader_account((string)($report['team_name_context'] ?? ''))) {
            $showLeaderInputColumn = true;
            break;
        }
    }
} else {
    // 일반/관리 계정은 본인이 볼 수 있는 팀 기준으로 칼럼 노출을 결정한다.
    foreach (auth_work_list_visible_teams($user) as $teamName) {
        if (work_list_team_has_leader_account((string)$teamName)) {
            $showLeaderInputColumn = true;
            break;
        }
    }
}

foreach ($reports as &$report) {
    $report['requires_leader_input'] = work_list_team_has_leader_account((string)($report['team_name_context'] ?? ''));
}
unset($report);

$workListDescription = '저장된 작업리스트를 확인하고 필요한 항목을 다시 열어볼 수 있습니다.';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>작업목록</title>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
<style>
  :root {
    --bg:       #0c1420;
    --bg2:      #111d2e;
    --bg3:      #162033;
    --border:   rgba(255,255,255,0.07);
    --border2:  rgba(255,255,255,0.12);
    --text:     #c5d8eb;
    --text-dim: #5d7a96;
    --text-hi:  #e8f2fc;
    --accent:   #e8920a;
    --accent2:  #f5a623;
    --blue:     #3a7fc1;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background: var(--bg) !important;
    min-height: 100vh;
    color: var(--text) !important;
    padding: 28px 20px 48px;
  }
  .shell { max-width: 1200px; margin: 0 auto; }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 22px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
  }
  .topbar-label { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 4px; }
  .topbar-title { font-size: 22px; font-weight: 900; color: var(--text-hi); line-height: 1.2; }
  .topbar-title span { color: var(--accent2); }
  .identity { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .role-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 11px;
    border-radius: 999px;
    background: rgba(232,146,10,0.15);
    color: var(--accent2);
    font-size: 12px;
    font-weight: bold;
    border: 1px solid rgba(232,146,10,0.35);
  }
  .btn-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 9px;
    cursor: pointer;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    background: rgba(255,255,255,0.07) !important;
    color: var(--text) !important;
    border: 1px solid var(--border2) !important;
  }
  .btn-secondary:hover { background: rgba(255,255,255,0.12) !important; }
  .btn-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 9px;
    cursor: pointer;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    background: rgba(214, 69, 65, 0.18);
    color: #ffd8d6;
    border: 1px solid rgba(214, 69, 65, 0.45);
  }
  .btn-danger:hover {
    background: rgba(214, 69, 65, 0.28);
  }
  .btn-ra {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 9px;
    cursor: pointer;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    background: var(--accent);
    color: #fff;
    border: none;
    white-space: nowrap;
  }
  .btn-ra:hover { background: var(--accent2); }
  .btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
  .panel {
    background: var(--bg2) !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    box-shadow: none !important;
    overflow: hidden;
  }
  .panel-head {
    padding: 22px 24px 14px;
    background: var(--bg2) !important;
    border-bottom: 1px solid var(--border);
  }
  .panel-head-label { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .panel-head h1 { font-size: 24px; font-weight: 900; color: var(--text-hi); margin-bottom: 6px; }
  .panel-head h1 span { color: var(--accent2); }
  .panel-head p { color: var(--text-dim); font-size: 13px; }
  .work-search-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-top: 16px;
  }
  .work-search-box {
    position: relative;
    flex: 1 1 320px;
    min-width: 220px;
  }
  .work-search-input {
    width: 100%;
    padding: 11px 14px;
    border-radius: 12px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
    font: inherit;
  }
  .work-search-input::placeholder { color: var(--text-dim); }
  .work-search-input:focus {
    outline: 2px solid rgba(245, 166, 35, 0.25);
    border-color: rgba(245, 166, 35, 0.45);
  }
  .work-search-meta {
    margin-top: 10px;
    color: var(--text-dim);
    font-size: 12px;
  }
  .work-search-results {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    display: none;
    flex-direction: column;
    gap: 6px;
    padding: 8px;
    border-radius: 14px;
    border: 1px solid var(--border2);
    background: #12203a;
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.35);
    max-height: min(320px, calc(100vh - 240px));
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: rgba(245, 166, 35, 0.75) rgba(255,255,255,0.08);
    z-index: 20;
  }
  .work-search-results.is-open { display: flex; }
  .work-search-results::-webkit-scrollbar {
    width: 10px;
  }
  .work-search-results::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.06);
    border-radius: 999px;
  }
  .work-search-results::-webkit-scrollbar-thumb {
    background: rgba(245, 166, 35, 0.72);
    border-radius: 999px;
    border: 2px solid transparent;
    background-clip: padding-box;
  }
  .work-search-results::-webkit-scrollbar-thumb:hover {
    background: rgba(245, 166, 35, 0.9);
    background-clip: padding-box;
  }
  .work-search-result-button {
    display: flex;
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
    width: 100%;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
    cursor: pointer;
    font: inherit;
    text-align: left;
  }
  .work-search-result-button:hover {
    border-color: rgba(245, 166, 35, 0.45);
    background: rgba(245, 166, 35, 0.08);
  }
  .work-search-result-code {
    color: var(--accent2);
    font-weight: 800;
    white-space: nowrap;
  }
  .work-search-result-name {
    color: var(--text);
    font-size: 13px;
  }
  .flash-message,
  .error-message {
    margin: 0 24px 16px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
  }
  .flash-message {
    background: rgba(54, 179, 126, 0.12);
    border: 1px solid rgba(54, 179, 126, 0.35);
    color: #99efc3;
  }
  .error-message {
    background: rgba(214, 69, 65, 0.12);
    border: 1px solid rgba(214, 69, 65, 0.35);
    color: #ffd3d1;
  }
  .table-wrap { overflow-x: auto; padding: 0 18px 18px; background: transparent !important; }
  .mobile-list { display: none; padding: 0 16px 16px; }
  .mobile-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 10px;
    transition: background .15s;
  }
  .mobile-card:hover { background: rgba(255,255,255,0.055); }
  .mobile-card:last-child { margin-bottom: 0; }
  .mobile-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
  }
  .mobile-meta {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 12px;
  }
  .mobile-meta-item {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
    border-radius: 10px;
    padding: 9px 11px;
    min-width: 0;
  }
  .mobile-meta-item strong { display: block; margin-bottom: 4px; color: var(--text-dim); font-size: 11px; }
  .mobile-meta-item span  { display: block; color: var(--text-hi); font-size: 13px; font-weight: 700; line-height: 1.45; word-break: keep-all; }
  .mobile-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .inline-form { display: inline-flex; }
  table { width: 100%; border-collapse: collapse; min-width: 1180px; background: transparent !important; }
  thead, tbody { background: transparent !important; }
  th, td {
    padding: 13px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: top;
    font-size: 13px;
    background: transparent !important;
    color: var(--text) !important;
  }
  th {
    background: rgba(255,255,255,0.03) !important;
    color: var(--text-dim) !important;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .04em;
  }
  tr:hover td { background: rgba(255,255,255,0.03) !important; }
  .work-title { font-weight: 700; color: var(--text-hi); margin-bottom: 4px; font-size: 14px; }
  .sub-text { color: var(--text-dim); font-size: 12px; line-height: 1.5; }
  .unit-code-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--accent2);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
  }
  .unit-code-link:hover { color: #ffd089; }
  .unit-code-link:focus-visible {
    outline: 2px solid rgba(245, 166, 35, 0.65);
    outline-offset: 3px;
    border-radius: 6px;
  }
  .safety-standard-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 10px;
    align-items: center;
  }
  .unit-multi-lines {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }
  .unit-multi-line {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    line-height: 1.55;
  }
  .safety-standard-unit-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .unit-title-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 10px;
    align-items: center;
  }
  .unit-title-item {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.03);
    color: var(--text-hi);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
  }
  .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 56px;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .02em;
    white-space: nowrap;
    line-height: 1.3;
  }
  .status-badge.is-complete {
    background: rgba(54, 179, 126, 0.14);
    border-color: rgba(54, 179, 126, 0.45);
    color: #99efc3;
  }
  .status-badge.is-pending {
    background: rgba(245, 166, 35, 0.14);
    border-color: rgba(245, 166, 35, 0.45);
    color: #ffd28b;
  }
  .status-badge-button {
    font-family: inherit;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
  }
  .status-badge-button:hover {
    filter: brightness(1.08);
  }
  .status-badge-button:focus-visible {
    outline: 2px solid rgba(245, 166, 35, 0.65);
    outline-offset: 2px;
  }
  .empty { padding: 48px 24px; text-align: center; color: var(--text-dim); }
  .modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(5, 10, 18, 0.78);
    backdrop-filter: blur(4px);
  }
  .modal-backdrop.is-open { display: flex; }
  .unit-preview-modal {
    width: min(1180px, 100%);
    max-height: calc(100vh - 40px);
    overflow: hidden;
    border-radius: 18px;
    border: 1px solid var(--border2);
    background: linear-gradient(180deg, rgba(18, 30, 48, 0.98), rgba(12, 20, 32, 0.98));
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
  }
  .unit-preview-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 20px 22px 16px;
    border-bottom: 1px solid var(--border);
  }
  .unit-preview-head h2 {
    margin: 0 0 6px;
    color: var(--text-hi);
    font-size: 22px;
    line-height: 1.3;
  }
  .unit-preview-head p {
    margin: 0;
    color: var(--text-dim);
    font-size: 13px;
    line-height: 1.5;
  }
  .modal-close {
    flex: 0 0 auto;
    min-width: 42px;
    height: 42px;
    border: 1px solid var(--border2);
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    color: var(--text-hi);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
  }
  .modal-close:hover { background: rgba(255,255,255,0.11); }
  .modal-head-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
  }
  .modal-head-actions .btn-secondary {
    padding: 9px 14px;
    white-space: nowrap;
  }
  .unit-preview-body {
    padding: 18px 22px 22px;
    max-height: calc(100vh - 156px);
    overflow: auto;
  }
  .unit-preview-loading,
  .unit-preview-error,
  .unit-preview-empty {
    padding: 32px 18px;
    border: 1px dashed var(--border2);
    border-radius: 14px;
    text-align: center;
    color: var(--text-dim);
    background: rgba(255,255,255,0.03);
  }
  .participant-list { display: grid; gap: 8px; list-style: none; }
  .participant-item {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 14px;
  }
  .participant-name { color: var(--text-hi); font-size: 14px; font-weight: 700; }
  .participant-id { color: var(--text-dim); font-size: 12px; margin-top: 4px; }
  .unit-preview-error { color: #ffd3d1; border-color: rgba(214, 69, 65, 0.45); }
  .unit-preview-meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
  }
  .unit-preview-meta-card {
    min-width: 0;
    padding: 12px 13px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.035);
  }
  .unit-preview-meta-card strong {
    display: block;
    margin-bottom: 6px;
    color: var(--text-dim);
    font-size: 11px;
    letter-spacing: .04em;
  }
  .unit-preview-meta-card span {
    display: block;
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 700;
    line-height: 1.45;
    word-break: break-word;
  }
  .unit-preview-section {
    margin-top: 16px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: rgba(255,255,255,0.025);
  }
  .unit-preview-section h3 {
    margin: 0 0 10px;
    color: var(--text-hi);
    font-size: 16px;
  }
  .unit-preview-remark {
    color: var(--text);
    font-size: 13px;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .unit-preview-table-wrap {
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 14px;
  }
  .unit-preview-table {
    width: 100%;
    min-width: 880px;
    border-collapse: collapse;
  }
  .unit-preview-table th,
  .unit-preview-table td {
    padding: 11px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: top;
    font-size: 13px;
    line-height: 1.55;
  }
  .unit-preview-table th {
    position: sticky;
    top: 0;
    background: rgba(18, 30, 48, 0.98);
    color: var(--text-dim);
    font-size: 12px;
    letter-spacing: .04em;
  }
  .unit-preview-table td { color: var(--text); }
  .unit-preview-table td strong { color: var(--text-hi); }
  .unit-preview-table tr:last-child td { border-bottom: 0; }
  .standard-preview-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }
  .standard-preview-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .standard-preview-list li {
    position: relative;
    padding-left: 14px;
    color: var(--text);
    font-size: 13px;
    line-height: 1.65;
    word-break: break-word;
  }
  .standard-preview-list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 8px;
    width: 5px;
    height: 5px;
    border-radius: 999px;
    background: rgba(245, 166, 35, 0.7);
  }
  @media (max-width: 720px) {
    body { padding: 18px 12px 36px; }
    .panel-head h1 { font-size: 20px; }
    .panel-head, .mobile-list { padding-left: 14px; padding-right: 14px; }
    .work-search-form { align-items: stretch; }
    .work-search-input { flex-basis: 100%; min-width: 0; }
    .table-wrap { display: none; }
    .mobile-list { display: block; }
    .mobile-meta { grid-template-columns: 1fr; gap: 7px; }
    .mobile-card { padding: 13px; }
    .mobile-actions .btn-secondary,
    .mobile-actions .btn-ra { flex: 1 1 100%; width: 100%; }
    .modal-backdrop { padding: 12px; }
    .unit-preview-head { padding: 16px 16px 14px; }
    .unit-preview-head h2 { font-size: 19px; }
    .modal-head-actions { width: 100%; justify-content: flex-end; }
    .unit-preview-body { padding: 14px 16px 16px; }
    .unit-preview-meta-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .standard-preview-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div>
        <div class="topbar-label">WORK MANAGEMENT · LIST</div>
        <div class="topbar-title">작업 <span>목록</span></div>
      </div>
        <div class="identity">
        <span style="color:var(--text-hi);font-size:14px;font-weight:700"><?= h(auth_display_name($user)) ?></span>
          <?php
            $userTeamKey = auth_team_key((string)($user['team'] ?? ''));
            $isGasTeam   = ($userTeamKey === auth_team_key('가스팀'));
            $isElectricalManager = auth_can_manage($user) && ($userTeamKey === auth_team_key('공사팀-전기'));
          ?>
          <?php if ($isGasTeam): ?>
            <a class="btn-secondary" href="schedule.php">근무일정표</a>
          <?php endif; ?>
          <?php if ($isElectricalManager): ?>
            <a class="btn-secondary" href="schedule.php?view_team=가스팀">가스팀근무표</a>
          <?php endif; ?>
          <?php if (!$isWorker): ?>
            <?php if ($isAdmin): ?>
              <?php foreach ($adminManagerTeams as $teamName): ?>
                <a class="btn-secondary" href="<?= h(build_page_url('task_select.php', ['manager_team' => $teamName])) ?>"><?= h($teamName) ?> 관리등록</a>
              <?php endforeach; ?>
            <?php elseif (!$isLeaderOnly): ?>
              <a class="btn-secondary" href="<?= h($entryPage) ?>">작업 등록</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
              <a class="btn-secondary" href="register_worker.php">계정관리</a>
            <?php endif; ?>
          <?php endif; ?>
        <a class="btn-secondary" href="../tbm/index.php">TBM일지</a>
        <a class="btn-secondary" href="../board/index.php">게시판</a>
        <a class="btn-secondary" href="../calendar/index.html">달력</a>
        <a class="btn-secondary" href="hazard_review.php">위험성평가목록</a>
        <a class="btn-secondary" href="<?= h($entryPage) ?>?logout=1">로그아웃</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-label">WORK LIST</div>
        <h1>작업 <span>목록</span></h1>
        <p><?= h($workListDescription) ?></p>
        <form class="work-search-form" id="standard-search-form" autocomplete="off">
          <div class="work-search-box">
            <input
              type="search"
              id="standard-search-input"
              class="work-search-input"
              placeholder="작업표준서번호 또는 표준서명 검색"
            >
            <div class="work-search-results" id="standard-search-results"></div>
          </div>
          <button type="submit" class="btn-secondary">검색</button>
          <button type="button" class="btn-secondary" id="standard-search-reset">초기화</button>
        </form>
        <div class="work-search-meta" id="standard-search-meta">작업목록을 건드리지 않고 작업표준서만 검색합니다.</div>
      </div>

      <?php if ($successMessage !== ''): ?>
        <div class="flash-message"><?= h($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($errorMessage !== ''): ?>
        <div class="error-message"><?= h($errorMessage) ?></div>
      <?php endif; ?>

      <?php if (empty($reports)): ?>
        <div class="empty">아직 저장된 작업이 없습니다.</div>
      <?php else: ?>
        <div class="mobile-list">
          <?php foreach ($reports as $report): ?>
            <article class="mobile-card">
              <?php $reportEntryPage = work_list_entry_page($user, $report, $entryPage); ?>
              <div class="mobile-card-head">
                <div>
                  <div class="work-title"><?= h($report['work_title']) ?></div>
                  <div class="sub-text"><?= $report['use_equipment_yn'] === 'Y' ? '중장비 사용' : '중장비 미사용' ?></div>
                </div>
              </div>
              <div class="mobile-meta">
                <div class="mobile-meta-item">
                  <strong>작업일자</strong>
                  <span><?= h($report['work_date']) ?></span>
                </div>
                <div class="mobile-meta-item">
                  <strong>작업장소</strong>
                  <span><?= h($report['work_place']) ?></span>
                </div>
                <div class="mobile-meta-item">
                  <strong>작업팀</strong>
                  <span><?= h($report['team_name_display']) ?></span>
                </div>
                <div class="mobile-meta-item">
                  <strong>작업유형</strong>
                  <?= render_unit_title_list($report['selected_units'] ?? []) ?>
                </div>
                <div class="mobile-meta-item">
                  <strong>위험성평가번호</strong>
                  <?= render_unit_code_preview_buttons($report['selected_units'] ?? []) ?>
                </div>
                <div class="mobile-meta-item">
                  <strong>작업표준서번호</strong>
                  <?= render_safety_standard_buttons_from_units($report['selected_units'] ?? []) ?>
                </div>
                <?php if ((bool)($report['requires_leader_input'] ?? false)): ?>
                  <div class="mobile-meta-item">
                    <strong>작업지휘자 입력</strong>
                    <?= render_completion_badge((bool)($report['work_input_completed'] ?? false)) ?>
                  </div>
                <?php endif; ?>
                <div class="mobile-meta-item">
                  <strong>위험성평가</strong>
                  <?= render_hazard_completion_badge($report) ?>
                </div>
                <div class="mobile-meta-item">
                  <strong>등록일</strong>
                  <span><?= h(substr((string)$report['created_at'], 0, 10)) ?></span>
                </div>
              </div>
              <div class="mobile-actions">
                <?php
                  $adminManagerOpenParams = [
                      'unit_ra_id' => (int)$report['unit_ra_id'],
                      'saved_report_id' => (int)$report['report_id'],
                  ];
                  if (($report['team_name_context'] ?? '') !== '') {
                      $adminManagerOpenParams['manager_team'] = (string)$report['team_name_context'];
                  }
                ?>
                <?php $canWorkerOpen = !$isWorker || (int)($report['leader_detail_count'] ?? 0) > 0; ?>
                <?php $canDeleteReport = $isAdmin || (in_array($userRole, ['manager', 'safety_manager'], true) && (string)($report['user_login_id'] ?? '') === (string)($user['login_id'] ?? '')); ?>
                <?php
                  $workInputCompleted = (int)($report['leader_detail_count'] ?? 0) > 0;
                  $hazardReviewCompleted = (bool)($report['hazard_review_completed'] ?? false);
                  $allTasksCompleted = $workInputCompleted && $hazardReviewCompleted;
                ?>
                <?php if ($canWorkerOpen && !$allTasksCompleted): ?>
                  <?php if ($isAdmin): ?>
                    <a class="btn-secondary" href="<?= h(build_page_url('task_select.php', $adminManagerOpenParams)) ?>">관리열기</a>
                    <a class="btn-secondary" href="leader_task_select.php?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>&saved_report_id=<?= (int)$report['report_id'] ?>&edit_report_id=<?= (int)$report['report_id'] ?>">지휘열기</a>
                  <?php else: ?>
                    <a class="btn-secondary" href="<?= h($reportEntryPage) ?>?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>&saved_report_id=<?= (int)$report['report_id'] ?>&edit_report_id=<?= (int)$report['report_id'] ?>">열기</a>
                  <?php endif; ?>
                <?php elseif ($isWorker && !$allTasksCompleted): ?>
                  <span class="sub-text">작업지휘자 입력 대기</span>
                <?php elseif ($allTasksCompleted): ?>
                  <span class="sub-text">완료</span>
                <?php endif; ?>
                <?php if ($report['unit_ra_id'] && $canManage): ?>
                  <a class="btn-secondary" href="unit_ra_excel_download.php?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>" download>엑셀다운로드</a>
                <?php endif; ?>
                <?php if ($canDeleteReport): ?>
                  <form method="post" class="inline-form" onsubmit="return confirm('이 작업을 삭제하시겠습니까? 삭제 후에는 되돌릴 수 없습니다.');">
                    <input type="hidden" name="action" value="delete_report">
                    <input type="hidden" name="report_id" value="<?= (int)$report['report_id'] ?>">
                    <button type="submit" class="btn-danger">삭제</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>작업명</th>
                <th>작업일자</th>
                <th>작업장소</th>
                <th>작업팀</th>
                <th>작업유형</th>
                <th>위험성평가번호</th>
                <th>작업표준서번호</th>
                <?php if ($showLeaderInputColumn): ?>
                  <th>작업지휘자 입력</th>
                <?php endif; ?>
                <th>위험성평가</th>
                <th>등록일</th>
                <th>보기</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $report): ?>
                <tr>
                  <?php $reportEntryPage = work_list_entry_page($user, $report, $entryPage); ?>
                  <td>
                    <div class="work-title"><?= h($report['work_title']) ?></div>
                    <div class="sub-text"><?= $report['use_equipment_yn'] === 'Y' ? '중장비 사용' : '중장비 미사용' ?></div>
                  </td>
                  <td><?= h($report['work_date']) ?></td>
                  <td><?= h($report['work_place']) ?></td>
                  <td><?= h($report['team_name_display']) ?></td>
                  <td><?= render_unit_title_list($report['selected_units'] ?? []) ?></td>
                  <td><?= render_unit_code_preview_buttons($report['selected_units'] ?? []) ?></td>
                  <td><?= render_safety_standard_buttons_from_units($report['selected_units'] ?? []) ?></td>
                  <?php if ($showLeaderInputColumn): ?>
                    <td>
                      <?php if ((bool)($report['requires_leader_input'] ?? false)): ?>
                        <?= render_completion_badge((bool)($report['work_input_completed'] ?? false)) ?>
                      <?php else: ?>
                        <span class="sub-text">-</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td><?= render_hazard_completion_badge($report) ?></td>
                  <td><?= h(substr((string)$report['created_at'], 0, 10)) ?></td>
                  <td>
                    <div class="btn-group">
                      <?php
                        $adminManagerOpenParams = [
                            'unit_ra_id' => (int)$report['unit_ra_id'],
                            'saved_report_id' => (int)$report['report_id'],
                        ];
                        if (($report['team_name_context'] ?? '') !== '') {
                            $adminManagerOpenParams['manager_team'] = (string)$report['team_name_context'];
                        }
                      ?>
                      <?php $canWorkerOpen = !$isWorker || (int)($report['leader_detail_count'] ?? 0) > 0; ?>
                      <?php $canDeleteReport = $isAdmin || (in_array($userRole, ['manager', 'safety_manager'], true) && (string)($report['user_login_id'] ?? '') === (string)($user['login_id'] ?? '')); ?>
                      <?php
                        $workInputCompleted = (int)($report['leader_detail_count'] ?? 0) > 0;
                        $hazardReviewCompleted = (bool)($report['hazard_review_completed'] ?? false);
                        $allTasksCompleted = $workInputCompleted && $hazardReviewCompleted;
                      ?>
                      <?php if ($canWorkerOpen && !$allTasksCompleted): ?>
                        <?php if ($isAdmin): ?>
                          <a class="btn-secondary" href="<?= h(build_page_url('task_select.php', $adminManagerOpenParams)) ?>">관리열기</a>
                          <a class="btn-secondary" href="leader_task_select.php?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>&saved_report_id=<?= (int)$report['report_id'] ?>&edit_report_id=<?= (int)$report['report_id'] ?>">지휘열기</a>
                        <?php else: ?>
                          <a class="btn-secondary" href="<?= h($reportEntryPage) ?>?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>&saved_report_id=<?= (int)$report['report_id'] ?>&edit_report_id=<?= (int)$report['report_id'] ?>">열기</a>
                        <?php endif; ?>
                      <?php elseif ($isWorker && !$allTasksCompleted): ?>
                        <span class="sub-text">작업지휘자 입력 대기</span>
                      <?php elseif ($allTasksCompleted): ?>
                        <span class="sub-text">완료</span>
                      <?php endif; ?>
                      <?php if ($report['unit_ra_id'] && $canManage): ?>
                        <a class="btn-secondary" href="unit_ra_excel_download.php?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>" download>엑셀다운로드</a>
                      <?php endif; ?>
                      <?php if ($canDeleteReport): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('이 작업을 삭제하시겠습니까? 삭제 후에는 되돌릴 수 없습니다.');">
                          <input type="hidden" name="action" value="delete_report">
                          <input type="hidden" name="report_id" value="<?= (int)$report['report_id'] ?>">
                          <button type="submit" class="btn-danger">삭제</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="modal-backdrop" id="unit-preview-modal" aria-hidden="true">
    <div class="unit-preview-modal" role="dialog" aria-modal="true" aria-labelledby="unit-preview-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="unit-preview-title">위험성평가 미리보기</h2>
          <p id="unit-preview-subtitle">위험성평가번호를 선택하면 상세 내용이 이 창에서 열립니다.</p>
        </div>
        <button type="button" class="modal-close" data-modal-close aria-label="닫기">&times;</button>
      </div>
      <div class="unit-preview-body" id="unit-preview-body">
        <div class="unit-preview-empty">위험성평가번호를 선택해 주세요.</div>
      </div>
    </div>
  </div>
  <div class="modal-backdrop" id="safety-preview-modal" aria-hidden="true">
    <div class="unit-preview-modal" role="dialog" aria-modal="true" aria-labelledby="safety-preview-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="safety-preview-title">작업표준서 미리보기</h2>
          <p id="safety-preview-subtitle">작업표준서번호를 선택하면 상세 내용이 이 창에서 열립니다.</p>
        </div>
        <div class="modal-head-actions">
          <a class="btn-secondary" id="safety-preview-link" href="/safety/index.html" target="_blank" rel="noopener noreferrer">원본 열기</a>
          <button type="button" class="modal-close" data-safety-modal-close aria-label="닫기">&times;</button>
        </div>
      </div>
      <div class="unit-preview-body" id="safety-preview-body">
        <div class="unit-preview-empty">작업표준서번호를 선택해 주세요.</div>
      </div>
    </div>
  </div>
  <div class="modal-backdrop" id="hazard-participant-modal" aria-hidden="true">
    <div class="unit-preview-modal" role="dialog" aria-modal="true" aria-labelledby="hazard-participant-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="hazard-participant-title">위험성평가 참여인원</h2>
          <p id="hazard-participant-subtitle">완료 배지를 누르면 참여인원을 확인할 수 있습니다.</p>
        </div>
        <button type="button" class="modal-close" data-hazard-participant-close aria-label="닫기">&times;</button>
      </div>
      <div class="unit-preview-body">
        <ul class="participant-list" id="hazard-participant-list">
          <li class="participant-item">
            <div class="participant-name">참여자 정보가 없습니다.</div>
          </li>
        </ul>
      </div>
    </div>
  </div>
  <script>
    (() => {
      const modal = document.getElementById('unit-preview-modal');
      const titleNode = document.getElementById('unit-preview-title');
      const subtitleNode = document.getElementById('unit-preview-subtitle');
      const bodyNode = document.getElementById('unit-preview-body');
      const safetyModal = document.getElementById('safety-preview-modal');
      const safetyTitleNode = document.getElementById('safety-preview-title');
      const safetySubtitleNode = document.getElementById('safety-preview-subtitle');
      const safetyBodyNode = document.getElementById('safety-preview-body');
      const safetyLinkNode = document.getElementById('safety-preview-link');
      const hazardParticipantModal = document.getElementById('hazard-participant-modal');
      const hazardParticipantTitleNode = document.getElementById('hazard-participant-title');
      const hazardParticipantSubtitleNode = document.getElementById('hazard-participant-subtitle');
      const hazardParticipantListNode = document.getElementById('hazard-participant-list');
      const standardSearchForm = document.getElementById('standard-search-form');
      const standardSearchInput = document.getElementById('standard-search-input');
      const standardSearchMeta = document.getElementById('standard-search-meta');
      const standardSearchResults = document.getElementById('standard-search-results');
      const standardSearchReset = document.getElementById('standard-search-reset');
      if (
        !modal
        || !titleNode
        || !subtitleNode
        || !bodyNode
        || !safetyModal
        || !safetyTitleNode
        || !safetySubtitleNode
        || !safetyBodyNode
        || !safetyLinkNode
        || !hazardParticipantModal
        || !hazardParticipantTitleNode
        || !hazardParticipantSubtitleNode
        || !hazardParticipantListNode
        || !standardSearchForm
        || !standardSearchInput
        || !standardSearchMeta
        || !standardSearchResults
        || !standardSearchReset
      ) {
        return;
      }

      const unitTypeLabels = {
        target: '작업관련',
        major_work: '중대위험작업',
        tool: '공구/장비',
        env: '작업환경',
      };
      let requestToken = 0;
      let previousBodyOverflow = '';
      let safetyRequestToken = 0;
      let previousSafetyBodyOverflow = '';
      let previousHazardParticipantBodyOverflow = '';
      let standardSearchDebounceTimer = 0;
      let standardSearchRequestToken = 0;

      function escapeHtml(value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function displayValue(value) {
        const normalized = String(value ?? '').trim();
        return normalized !== '' ? escapeHtml(normalized) : '-';
      }

      function displayTextBlock(value) {
        const normalized = String(value ?? '').trim();
        return normalized !== ''
          ? escapeHtml(normalized).replace(/\r?\n/g, '<br>')
          : '-';
      }

      function formatDate(value) {
        const normalized = String(value ?? '').trim();
        return normalized !== '' ? normalized.slice(0, 10) : '-';
      }

      function normalizeStandardKey(value) {
        return String(value ?? '').replace(/\s+/g, '').toUpperCase();
      }

      function splitStandardNumbers(value) {
        const normalized = String(value ?? '').trim();
        if (!normalized) {
          return [];
        }

        const parts = normalized
          .split(/[\r\n,;\/|]+/)
          .map((part) => part.trim())
          .filter(Boolean);
        const unique = [];
        const seen = new Set();

        parts.forEach((part) => {
          const key = normalizeStandardKey(part);
          if (!key || seen.has(key)) {
            return;
          }
          seen.add(key);
          unique.push(part);
        });

        return unique.length > 0 ? unique : [normalized];
      }

      function buildSafetyStandardUrl(standardNo) {
        const tokens = splitStandardNumbers(standardNo);
        if (tokens.length === 0) {
          return '/safety/index.html';
        }

        const candidate = tokens[0];

        const params = new URLSearchParams({
          jobPlan: candidate,
          query: candidate,
        });
        return `/safety/index.html?${params.toString()}`;
      }

      function renderSafetyStandardValue(standardNo) {
        const numbers = splitStandardNumbers(standardNo);
        if (numbers.length === 0) {
          return '-';
        }

        return `
          <span class="safety-standard-list">
            ${numbers.map((number) => `<button type="button" class="unit-code-link js-inline-standard-preview" data-standard-no="${escapeHtml(number)}">${escapeHtml(number)}</button>`).join('')}
          </span>
        `;
      }

      function renderMetaCard(label, value) {
        return `
          <div class="unit-preview-meta-card">
            <strong>${escapeHtml(label)}</strong>
            <span>${value}</span>
          </div>
        `;
      }

      function renderSafetyList(items) {
        if (!Array.isArray(items) || items.length === 0) {
          return '<div class="unit-preview-empty">등록된 내용이 없습니다.</div>';
        }

        return `
          <ul class="standard-preview-list">
            ${items.map((item) => `<li>${displayTextBlock(item)}</li>`).join('')}
          </ul>
        `;
      }

      function renderItems(items) {
        if (!Array.isArray(items) || items.length === 0) {
          return '<div class="unit-preview-empty">등록된 위험성평가 항목이 없습니다.</div>';
        }

        const rows = items.map((item, index) => {
          const sortNo = String(item.sort_no ?? '').trim() !== '' ? item.sort_no : (index + 1);
          const accidentSummary = [item.accident_type, item.injury_result]
            .map((part) => String(part ?? '').trim())
            .filter(Boolean)
            .join(' / ');

          return `
            <tr>
              <td>${escapeHtml(sortNo)}</td>
              <td>${displayValue(item.task_name)}</td>
              <td>
                <strong>${displayValue(item.hazard_name)}</strong>
                ${String(item.cause_text ?? '').trim() !== '' ? `<div class="sub-text" style="margin-top:6px">원인/위험상황: ${displayTextBlock(item.cause_text)}</div>` : ''}
              </td>
              <td>${accidentSummary !== '' ? escapeHtml(accidentSummary) : '-'}</td>
              <td>${displayTextBlock(item.current_control_text)}</td>
              <td>${displayTextBlock(item.additional_control_text)}</td>
            </tr>
          `;
        }).join('');

        return `
          <div class="unit-preview-table-wrap">
            <table class="unit-preview-table">
              <thead>
                <tr>
                  <th>No</th>
                  <th>작업절차</th>
                  <th>유해위험요인</th>
                  <th>사고유형/상해결과</th>
                  <th>현재 조치</th>
                  <th>추가 조치</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        `;
      }

      function renderPreview(payload) {
        const header = payload && payload.header ? payload.header : {};
        const items = payload && Array.isArray(payload.items) ? payload.items : [];
        const unitTitle = String(header.unit_title ?? '').trim();
        const unitCode = String(header.unit_code ?? '').trim();
        const previewTitle = unitTitle || unitCode || '위험성평가 미리보기';
        const previewSubtitle = `${unitCode || '번호 미등록'} · 항목 ${items.length}건`;

        titleNode.textContent = previewTitle;
        subtitleNode.textContent = previewSubtitle;

        bodyNode.innerHTML = `
          <div class="unit-preview-meta-grid">
            ${renderMetaCard('위험성평가번호', displayValue(header.unit_code))}
            ${renderMetaCard('작업표준서번호', renderSafetyStandardValue(header.safe_work_standard_no))}
            ${renderMetaCard('평가유형', displayValue(unitTypeLabels[String(header.unit_type ?? '').trim()] || header.unit_type))}
            ${renderMetaCard('공정명', displayValue(header.process_name))}
            ${renderMetaCard('평가서명', displayValue(header.unit_title))}
            ${renderMetaCard('평가자', displayValue(header.evaluator_name))}
            ${renderMetaCard('등록일', displayValue(formatDate(header.created_at)))}
            ${renderMetaCard('수정일', displayValue(formatDate(header.updated_at)))}
          </div>
          <section class="unit-preview-section">
            <h3>비고</h3>
            <div class="unit-preview-remark">${displayTextBlock(header.remark)}</div>
          </section>
          <section class="unit-preview-section">
            <h3>위험성평가 항목</h3>
            ${renderItems(items)}
          </section>
        `;
      }

      function renderSafetyPreview(payload) {
        const record = payload || {};
        const sections = record.sections || {};
        const previewTitle = String(record.name ?? '').trim() || String(record.job_plan ?? '').trim() || '작업표준서 미리보기';
        const previewSubtitle = `${String(record.job_plan ?? '').trim() || '번호 미등록'} · 문서 ${String(record.no ?? '-').trim() || '-'}번`;
        const fileLabel = Number(record.file_no || 0) > 0 ? `VOL.0${Number(record.file_no)}` : '-';
        const revisionText = String(record.rev ?? '').trim() || String(record.note ?? '').trim() || '-';

        safetyTitleNode.textContent = previewTitle;
        safetySubtitleNode.textContent = previewSubtitle;
        safetyLinkNode.href = buildSafetyStandardUrl(record.job_plan || '');

        if (String(record.source_url ?? '').trim() !== '') {
          safetyLinkNode.href = String(record.source_url).trim();
        }

        safetyBodyNode.innerHTML = `
          <div class="unit-preview-meta-grid">
            ${renderMetaCard('작업표준서번호', displayValue(record.job_plan))}
            ${renderMetaCard('문서번호', displayValue(record.no))}
            ${renderMetaCard('작업명', displayValue(record.name))}
            ${renderMetaCard('작성자', displayValue(record.author))}
            ${renderMetaCard('작성일', displayValue(record.created))}
            ${renderMetaCard('개정일', displayValue(record.revised))}
            ${renderMetaCard('개정', displayValue(revisionText))}
            ${renderMetaCard('파일권호', displayValue(fileLabel))}
          </div>
          <section class="unit-preview-section">
            <h3>시트 정보</h3>
            <div class="unit-preview-remark">${displayValue(record.sheet_name)}</div>
          </section>
          <div class="standard-preview-grid">
            <section class="unit-preview-section">
              <h3>작업 준비사항</h3>
              ${renderSafetyList(sections.prep)}
            </section>
            <section class="unit-preview-section">
              <h3>안전 일반</h3>
              ${renderSafetyList(sections.safety)}
            </section>
            <section class="unit-preview-section">
              <h3>작업 순서</h3>
              ${renderSafetyList(sections.steps)}
            </section>
            <section class="unit-preview-section">
              <h3>안전 작업 순서</h3>
              ${renderSafetyList(sections.safe_steps)}
            </section>
          </div>
        `;
      }

      function renderParticipantItems(participants) {
        if (!Array.isArray(participants) || participants.length === 0) {
          return '<li class="participant-item"><div class="participant-name">참여자 정보가 없습니다.</div></li>';
        }

        return participants.map((participant) => {
          const name = String(participant && participant.user_name ? participant.user_name : '').trim() || '-';
          const loginId = String(participant && participant.user_login_id ? participant.user_login_id : '').trim();
          return `
            <li class="participant-item">
              <div class="participant-name">${escapeHtml(name)}</div>
              ${loginId !== '' ? `<div class="participant-id">${escapeHtml(loginId)}</div>` : ''}
            </li>
          `;
        }).join('');
      }

      function setStandardSearchMeta(message) {
        standardSearchMeta.textContent = message;
      }

      function hideStandardSearchResults() {
        standardSearchResults.classList.remove('is-open');
      }

      function renderStandardSearchResults(results) {
        if (!Array.isArray(results) || results.length === 0) {
          hideStandardSearchResults();
          standardSearchResults.innerHTML = '';
          return;
        }

        standardSearchResults.classList.add('is-open');
        standardSearchResults.innerHTML = results.map((result) => `
          <button
            type="button"
            class="work-search-result-button"
            data-search-standard-no="${escapeHtml(result.job_plan || '')}"
          >
            <span class="work-search-result-code">${escapeHtml(result.job_plan || '-')}</span>
            <span class="work-search-result-name">${escapeHtml(result.name || '제목 없음')}</span>
          </button>
        `).join('');
      }

      function clearStandardSearch() {
        standardSearchInput.value = '';
        window.clearTimeout(standardSearchDebounceTimer);
        hideStandardSearchResults();
        standardSearchResults.innerHTML = '';
        setStandardSearchMeta('작업목록을 건드리지 않고 작업표준서만 검색합니다.');
      }

      async function searchSafetyStandards(query, options = {}) {
        const normalized = String(query ?? '').trim();
        const autoOpenExact = Boolean(options.autoOpenExact);
        if (!normalized) {
          clearStandardSearch();
          return;
        }

        standardSearchRequestToken += 1;
        const currentSearchToken = standardSearchRequestToken;
        setStandardSearchMeta('작업표준서를 검색하는 중입니다.');
        hideStandardSearchResults();
        standardSearchResults.innerHTML = '';

        try {
          const response = await fetch(`safety_standard_api.php?query=${encodeURIComponent(normalized)}`);
          const json = await response.json();
          if (currentSearchToken !== standardSearchRequestToken) {
            return;
          }
          if (!json || !json.success) {
            throw new Error(json && json.message ? json.message : '작업표준서를 검색하지 못했습니다.');
          }

          const results = Array.isArray(json.data && json.data.results) ? json.data.results : [];
          if (results.length === 0) {
            setStandardSearchMeta(`\`${normalized}\`에 대한 표준서를 찾지 못했습니다.`);
            renderStandardSearchResults([]);
            return;
          }

          setStandardSearchMeta(`\`${normalized}\` 검색 결과 ${results.length}건`);
          renderStandardSearchResults(results);

          if (autoOpenExact && results.length === 1 && normalizeStandardKey(results[0].job_plan || '') === normalizeStandardKey(normalized)) {
            openSafetyModal(results[0].job_plan || '');
          }
        } catch (error) {
          if (currentSearchToken !== standardSearchRequestToken) {
            return;
          }
          setStandardSearchMeta(error && error.message ? error.message : '작업표준서 검색 중 오류가 발생했습니다.');
          renderStandardSearchResults([]);
        }
      }

      function openModal(unitRaId) {
        if (!Number.isInteger(unitRaId) || unitRaId <= 0) {
          return;
        }

        closeSafetyModal();
        closeHazardParticipantModal();
        requestToken += 1;
        const currentToken = requestToken;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        titleNode.textContent = '위험성평가 미리보기';
        subtitleNode.textContent = '데이터를 불러오는 중입니다.';
        bodyNode.innerHTML = '<div class="unit-preview-loading">위험성평가 상세 정보를 불러오는 중입니다.</div>';

        fetch(`unit_ra_header_api.php?action=preview&unit_ra_id=${encodeURIComponent(unitRaId)}`)
          .then((response) => response.json())
          .then((json) => {
            if (currentToken !== requestToken) {
              return;
            }
            if (!json || !json.success) {
              throw new Error(json && json.message ? json.message : '위험성평가 정보를 불러오지 못했습니다.');
            }
            renderPreview(json.data || {});
          })
          .catch((error) => {
            if (currentToken !== requestToken) {
              return;
            }
            titleNode.textContent = '위험성평가 미리보기';
            subtitleNode.textContent = '데이터를 불러오지 못했습니다.';
            bodyNode.innerHTML = `<div class="unit-preview-error">${escapeHtml(error && error.message ? error.message : '오류가 발생했습니다.')}</div>`;
          });
      }

      function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousBodyOverflow;
      }

      function openSafetyModal(standardNo) {
        const normalized = String(standardNo ?? '').trim();
        if (!normalized) {
          return;
        }

        hideStandardSearchResults();
        closeModal();
        closeHazardParticipantModal();
        safetyRequestToken += 1;
        const currentToken = safetyRequestToken;
        previousSafetyBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        safetyModal.classList.add('is-open');
        safetyModal.setAttribute('aria-hidden', 'false');
        safetyTitleNode.textContent = '작업표준서 미리보기';
        safetySubtitleNode.textContent = '데이터를 불러오는 중입니다.';
        safetyLinkNode.href = buildSafetyStandardUrl(normalized);
        safetyBodyNode.innerHTML = '<div class="unit-preview-loading">작업표준서 상세 정보를 불러오는 중입니다.</div>';

        fetch(`safety_standard_api.php?job_plan=${encodeURIComponent(normalized)}`)
          .then((response) => response.json())
          .then((json) => {
            if (currentToken !== safetyRequestToken) {
              return;
            }
            if (!json || !json.success) {
              throw new Error(json && json.message ? json.message : '작업표준서 정보를 불러오지 못했습니다.');
            }
            renderSafetyPreview(json.data || {});
          })
          .catch((error) => {
            if (currentToken !== safetyRequestToken) {
              return;
            }
            safetyTitleNode.textContent = '작업표준서 미리보기';
            safetySubtitleNode.textContent = '데이터를 불러오지 못했습니다.';
            safetyBodyNode.innerHTML = `<div class="unit-preview-error">${escapeHtml(error && error.message ? error.message : '오류가 발생했습니다.')}</div>`;
          });
      }

      function closeSafetyModal() {
        safetyModal.classList.remove('is-open');
        safetyModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousSafetyBodyOverflow;
      }

      function openHazardParticipantModal(workTitle, participants, participantCountHint = 0) {
        const normalizedParticipants = Array.isArray(participants) ? participants : [];
        const count = Math.max(Number(participantCountHint || 0), normalizedParticipants.length);

        closeModal();
        closeSafetyModal();

        previousHazardParticipantBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        hazardParticipantTitleNode.textContent = workTitle ? `${workTitle} 참여인원` : '위험성평가 참여인원';
        hazardParticipantSubtitleNode.textContent = count > 0
          ? `위험성평가 참여인원 ${count}명`
          : '참여인원 정보가 아직 없습니다.';
        hazardParticipantListNode.innerHTML = renderParticipantItems(normalizedParticipants);
        hazardParticipantModal.classList.add('is-open');
        hazardParticipantModal.setAttribute('aria-hidden', 'false');
      }

      function closeHazardParticipantModal() {
        hazardParticipantModal.classList.remove('is-open');
        hazardParticipantModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousHazardParticipantBodyOverflow;
      }

      document.querySelectorAll('.js-unit-preview').forEach((button) => {
        button.addEventListener('click', () => {
          openModal(Number(button.dataset.unitRaId || 0));
        });
      });

      document.querySelectorAll('.js-safety-standard-preview').forEach((button) => {
        button.addEventListener('click', () => {
          openSafetyModal(button.dataset.standardNo || '');
        });
      });

      document.querySelectorAll('.js-hazard-participant-trigger[data-participants]').forEach((button) => {
        button.addEventListener('click', () => {
          const workTitle = String(button.dataset.reportTitle || '').trim();
          const participantCount = Number(button.dataset.participantCount || 0);
          const rawParticipants = button.dataset.participants || '[]';
          let participants = [];

          try {
            participants = JSON.parse(rawParticipants);
          } catch (error) {
            participants = [];
          }

          openHazardParticipantModal(
            workTitle,
            Array.isArray(participants) ? participants : [],
            Number.isFinite(participantCount) ? participantCount : 0
          );
        });
      });

      standardSearchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(standardSearchDebounceTimer);
        searchSafetyStandards(standardSearchInput.value, { autoOpenExact: true });
      });

      standardSearchReset.addEventListener('click', () => {
        clearStandardSearch();
      });

      standardSearchInput.addEventListener('input', () => {
        const query = standardSearchInput.value;
        window.clearTimeout(standardSearchDebounceTimer);
        if (String(query ?? '').trim() === '') {
          clearStandardSearch();
          return;
        }

        standardSearchDebounceTimer = window.setTimeout(() => {
          searchSafetyStandards(query);
        }, 180);
      });

      standardSearchInput.addEventListener('focus', () => {
        const query = String(standardSearchInput.value ?? '').trim();
        if (!query) {
          return;
        }

        if (standardSearchResults.innerHTML.trim() !== '') {
          standardSearchResults.classList.add('is-open');
          return;
        }

        searchSafetyStandards(query);
      });

      standardSearchResults.addEventListener('click', (event) => {
        const targetButton = event.target.closest('[data-search-standard-no]');
        if (!targetButton) {
          return;
        }
        hideStandardSearchResults();
        openSafetyModal(targetButton.dataset.searchStandardNo || '');
      });

      document.addEventListener('click', (event) => {
        const inlinePreviewButton = event.target.closest('.js-inline-standard-preview');
        if (!inlinePreviewButton) {
          if (!event.target.closest('#standard-search-form')) {
            hideStandardSearchResults();
          }
          return;
        }
        openSafetyModal(inlinePreviewButton.dataset.standardNo || '');
      });

      modal.addEventListener('click', (event) => {
        if (event.target === modal || event.target.closest('[data-modal-close]')) {
          closeModal();
        }
      });

      safetyModal.addEventListener('click', (event) => {
        if (event.target === safetyModal || event.target.closest('[data-safety-modal-close]')) {
          closeSafetyModal();
        }
      });

      hazardParticipantModal.addEventListener('click', (event) => {
        if (event.target === hazardParticipantModal || event.target.closest('[data-hazard-participant-close]')) {
          closeHazardParticipantModal();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && hazardParticipantModal.classList.contains('is-open')) {
          closeHazardParticipantModal();
        } else if (event.key === 'Escape' && safetyModal.classList.contains('is-open')) {
          closeSafetyModal();
        } else if (event.key === 'Escape' && modal.classList.contains('is-open')) {
          closeModal();
        }
      });
    })();
  </script>
</body>
</html>
