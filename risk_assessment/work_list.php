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

function render_work_list_pagination(int $totalItems, int $currentPage, int $perPage, array $params = []): string
{
    $totalPages = max(1, (int)ceil($totalItems / max(1, $perPage)));
    $currentPage = max(1, min($totalPages, $currentPage));

    if ($totalItems <= 0 || $totalPages <= 1) {
        return '';
    }

    $html = '<div class="pagination">';
    $start = max(1, $currentPage - 5);
    $end = min($totalPages, $currentPage + 5);

    if ($currentPage > 1) {
        $html .= sprintf(
            '<a href="%s">처음</a>',
            h(build_page_url('work_list.php', array_merge($params, ['page' => 1])))
        );
        $html .= sprintf(
            '<a href="%s">이전</a>',
            h(build_page_url('work_list.php', array_merge($params, ['page' => $currentPage - 1])))
        );
    }

    for ($page = $start; $page <= $end; $page++) {
        if ($page === $currentPage) {
            $html .= '<span class="current">' . $page . '</span>';
            continue;
        }

        $html .= sprintf(
            '<a href="%s">%d</a>',
            h(build_page_url('work_list.php', array_merge($params, ['page' => $page]))),
            $page
        );
    }

    if ($currentPage < $totalPages) {
        $html .= sprintf(
            '<a href="%s">다음</a>',
            h(build_page_url('work_list.php', array_merge($params, ['page' => $currentPage + 1])))
        );
        $html .= sprintf(
            '<a href="%s">끝</a>',
            h(build_page_url('work_list.php', array_merge($params, ['page' => $totalPages])))
        );
    }

    $html .= '</div>';
    return $html;
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

function work_list_unit_type_labels(): array
{
    return [
        'target' => '작업대상',
        'major_work' => '중대위험작업',
        'tool' => '공구/장비',
        'env' => '작업환경',
    ];
}

function work_list_normalize_text_list(array $values): array
{
    $result = [];
    $seen = [];

    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($seen[$value])) {
            continue;
        }

        $seen[$value] = true;
        $result[] = $value;
    }

    sort($result, SORT_NATURAL);
    return $result;
}

function work_list_report_unit_types(array $report): array
{
    $types = [];
    foreach ((array)($report['selected_units'] ?? []) as $unit) {
        $types[] = (string)($unit['unit_type'] ?? '');
    }

    return work_list_normalize_text_list($types);
}

function work_list_report_process_names(array $report, string $unitTypeFilter = ''): array
{
    $names = [];
    foreach ((array)($report['selected_units'] ?? []) as $unit) {
        $unitType = trim((string)($unit['unit_type'] ?? ''));
        if ($unitTypeFilter !== '' && $unitType !== $unitTypeFilter) {
            continue;
        }

        $names[] = (string)($unit['process_name'] ?? '');
    }

    return work_list_normalize_text_list($names);
}

function work_list_collect_type_options(): array
{
    return work_list_unit_type_labels();
}

function work_list_collect_major_options(PDO $pdo, string $unitTypeFilter = ''): array
{
    $sql = "
        SELECT DISTINCT process_name
        FROM unit_ra_header
        WHERE use_yn = 'Y'
          AND process_name IS NOT NULL
          AND TRIM(process_name) <> ''
    ";
    $params = [];
    if ($unitTypeFilter !== '') {
        $sql .= " AND unit_type = :unit_type";
        $params[':unit_type'] = $unitTypeFilter;
    }
    $sql .= " ORDER BY process_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return work_list_normalize_text_list($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function work_list_report_checked_items(array $report): array
{
    return work_list_normalize_text_list((array)($report['leader_checked_items'] ?? []));
}

function work_list_collect_checked_item_options(array $reports): array
{
    $items = [];
    foreach ($reports as $report) {
        foreach (work_list_report_checked_items($report) as $itemName) {
            $items[] = $itemName;
        }
    }

    return work_list_normalize_text_list($items);
}

function work_list_report_matches_filters(array $report, string $unitTypeFilter, string $majorFilter): bool
{
    if ($unitTypeFilter !== '') {
        $types = work_list_report_unit_types($report);
        if (!in_array($unitTypeFilter, $types, true)) {
            return false;
        }
    }

    if ($majorFilter !== '') {
        $processNames = work_list_report_process_names($report, $unitTypeFilter);
        if (!in_array($majorFilter, $processNames, true)) {
            return false;
        }
    }

    return true;
}

function work_list_report_matches_checked_item(array $report, string $checkedItemFilter): bool
{
    if ($checkedItemFilter === '') {
        return true;
    }

    return in_array($checkedItemFilter, work_list_report_checked_items($report), true);
}

function work_list_report_matches_keyword(array $report, string $keyword): bool
{
    $keyword = trim($keyword);
    if ($keyword === '') {
        return true;
    }

    $haystacks = [
        (string)($report['work_title'] ?? ''),
        (string)($report['work_place'] ?? ''),
        (string)($report['work_date'] ?? ''),
        (string)($report['team_name_display'] ?? ''),
        (string)($report['team_name_context'] ?? ''),
    ];

    foreach ((array)($report['selected_units'] ?? []) as $unit) {
        $haystacks[] = (string)($unit['unit_title'] ?? '');
        $haystacks[] = (string)($unit['unit_code'] ?? '');
        $haystacks[] = (string)($unit['unit_type'] ?? '');
        $haystacks[] = (string)($unit['process_name'] ?? '');
        $haystacks[] = (string)($unit['safe_work_standard_no'] ?? '');
    }

    foreach (work_list_report_checked_items($report) as $itemName) {
        $haystacks[] = $itemName;
    }

    foreach ($haystacks as $haystack) {
        if ($haystack !== '' && mb_stripos($haystack, $keyword, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

function work_list_report_matches_date_range(array $report, string $dateFrom, string $dateTo): bool
{
    $workDate = trim((string)($report['work_date'] ?? ''));
    if ($workDate === '') {
        return $dateFrom === '' && $dateTo === '';
    }

    if ($dateFrom !== '' && $workDate < $dateFrom) {
        return false;
    }

    if ($dateTo !== '' && $workDate > $dateTo) {
        return false;
    }

    return true;
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
    global $user;

    $viewerLoginId = trim((string)($user['login_id'] ?? ''));
    $viewerRole = trim((string)($user['role'] ?? ''));
    $isAdminWorkerTestViewer = $viewerLoginId === 'admin01' || $viewerRole === 'safety_manager';
    $isCompleted = (bool)($report['hazard_review_completed'] ?? false);
    $reportId = (int)($report['report_id'] ?? 0);

    if ($isAdminWorkerTestViewer && $reportId > 0) {
        $label = $isCompleted ? '완료' : '대기';
        $className = $isCompleted
            ? 'status-badge is-complete status-badge-button'
            : 'status-badge is-pending status-badge-button';

        return sprintf(
            '<a class="%s" href="%s">%s</a>',
            h($className),
            h(build_page_url('hazard_survey.php', [
                'report_id' => $reportId,
                'test_mode' => '1',
            ])),
            h($label)
        );
    }

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

function render_view_completion_text(array $report): string
{
    $photoCount = (int)($report['attached_photo_count'] ?? 0);
    if ($photoCount > 0) {
        return '<span class="sub-text">완료 <span class="photo-indicator" title="작업사진 첨부">@</span></span>';
    }

    return '<span class="sub-text">완료</span>';
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

function work_list_report_is_completed_by_id(PDO $pdo, int $reportId): bool
{
  if ($reportId <= 0) {
    return false;
  }

  $workInputCompleted = false;
  if (tableExists($pdo, 'work_report_detail')) {
    $detailStmt = $pdo->prepare("SELECT COUNT(*) FROM work_report_detail WHERE report_id = :report_id");
    $detailStmt->execute([':report_id' => $reportId]);
    $workInputCompleted = (int)$detailStmt->fetchColumn() > 0;
  }

  $hazardSubmissionCount = 0;
  $hazardTables = [
    'work_report_worker_hazard_selection',
    'work_report_hazard_selection',
    'work_report_hazard_change_request',
    'work_report_hazard_addition',
  ];
  foreach ($hazardTables as $tableName) {
    if (!tableExists($pdo, $tableName)) {
      continue;
    }
    $hazardStmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableName} WHERE report_id = :report_id");
    $hazardStmt->execute([':report_id' => $reportId]);
    $hazardSubmissionCount += (int)$hazardStmt->fetchColumn();
  }

  return $workInputCompleted && $hazardSubmissionCount > 0;
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
$managerShortcutTeams = [];
if ($canManage && !$isAdmin) {
    $currentTeam = auth_normalize_team_name((string)($user['team'] ?? ''));
    $managerShortcutTeams = auth_unique_team_list(array_merge(
        $currentTeam !== '' ? [$currentTeam] : [],
        $currentTeam !== '' ? auth_supervised_teams($currentTeam) : []
    ));
}

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

  function work_list_user_can_delete_report(array $user, array $report, bool $isCompleted): bool
  {
    if ($isCompleted) {
      return false;
    }

    if (auth_is_admin($user)) {
      return true;
    }

    $userRole = (string)($user['role'] ?? '');
    if (in_array($userRole, ['safety_manager', 'administrator'], true)) {
      return true;
    }

    if (!auth_can_manage($user)) {
      return false;
    }

    $reportTeamName = report_team_context($report);
    if ($reportTeamName === '') {
      return false;
    }

    $visibleTeams = auth_work_list_visible_teams($user);
    if (empty($visibleTeams)) {
      return false;
    }

    $visibleTeamKeys = array_fill_keys(array_map('auth_team_key', $visibleTeams), true);
    return isset($visibleTeamKeys[auth_team_key($reportTeamName)]);
  }

function filter_reports_for_user(array $reports, array $user): array
{
    $userRole = (string)($user['role'] ?? '');
    if (auth_is_admin($user) || in_array($userRole, ['safety_manager', 'administrator'], true)) {
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
    $ownerStmt = $pdo->prepare("
      SELECT report_id, user_login_id, team_name
      FROM work_report
      WHERE report_id = :report_id
      LIMIT 1
    ");
    $ownerStmt->execute([
      ':report_id' => $deleteReportId,
    ]);
        $deleteTarget = $ownerStmt->fetch();

        if (!$deleteTarget) {
      $errorMessage = '삭제할 작업을 찾을 수 없습니다.';
    } elseif (work_list_report_is_completed_by_id($pdo, $deleteReportId)) {
      $errorMessage = '완료된 작업문서는 삭제할 수 없습니다.';
    } elseif (!work_list_user_can_delete_report($user, $deleteTarget, false)) {
      $errorMessage = '본인 팀 또는 관리감독 대상 팀의 진행 중 작업문서만 삭제할 수 있습니다.';
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

// 현재 로그인 사용자의 개인 위험성평가 제출 여부 (작업자 전용)
$currentUserLoginId = (string)($user['login_id'] ?? '');
$currentUserHazardSubmittedSelect = '0 AS current_user_hazard_submitted,';
if ($isWorker && $currentUserLoginId !== '') {
    $quotedId = $pdo->quote($currentUserLoginId);
    $checks = [];
    if (tableExists($pdo, 'work_report_worker_hazard_selection')) {
        $checks[] = "(SELECT COUNT(*) FROM work_report_worker_hazard_selection ws WHERE ws.report_id = wr.report_id AND ws.user_login_id = {$quotedId})";
    }
    if (tableExists($pdo, 'work_report_hazard_change_request')) {
        $checks[] = "(SELECT COUNT(*) FROM work_report_hazard_change_request hcr WHERE hcr.report_id = wr.report_id AND hcr.user_login_id = {$quotedId})";
    }
    if (!empty($checks)) {
        $currentUserHazardSubmittedSelect = '(' . implode(' + ', $checks) . ') AS current_user_hazard_submitted,';
    }
}

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
        h.unit_type,
        h.process_name,
        {$safeWorkStandardSelect}
        h.unit_title,
        {$hazardWorkerSelectionCountSelect}
        {$hazardParticipantCountSelect}
        {$hazardSelectionCountSelect}
        {$hazardChangeRequestCountSelect}
        {$hazardAdditionCountSelect}
        {$currentUserHazardSubmittedSelect}
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
                h.unit_type,
                h.process_name,
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
                'unit_type' => (string)($row['unit_type'] ?? ''),
                'process_name' => (string)($row['process_name'] ?? ''),
                'safe_work_standard_no' => (string)($row['safe_work_standard_no'] ?? ''),
            ];
        }
    }
}

$leaderCheckedItemsByReportId = [];
if (!empty($reports) && tableExists($pdo, 'work_report_detail')) {
    $reportIds = array_values(array_unique(array_map(
        static fn($row) => (int)($row['report_id'] ?? 0),
        $reports
    )));
    $reportIds = array_values(array_filter($reportIds, static fn($id) => $id > 0));

    if (!empty($reportIds)) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $detailTaskStmt = $pdo->prepare("
            SELECT
                report_id,
                task_name
            FROM work_report_detail
            WHERE report_id IN ($placeholders)
            ORDER BY report_id ASC, report_detail_id ASC
        ");
        $detailTaskStmt->execute($reportIds);
        foreach ($detailTaskStmt->fetchAll() as $row) {
            $reportIdKey = (int)($row['report_id'] ?? 0);
            $taskName = trim((string)($row['task_name'] ?? ''));
            if ($reportIdKey <= 0 || $taskName === '') {
                continue;
            }

            if (!isset($leaderCheckedItemsByReportId[$reportIdKey])) {
                $leaderCheckedItemsByReportId[$reportIdKey] = [];
            }
            $leaderCheckedItemsByReportId[$reportIdKey][] = $taskName;
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
            'unit_type' => (string)($report['unit_type'] ?? ''),
            'process_name' => (string)($report['process_name'] ?? ''),
            'safe_work_standard_no' => (string)($report['safe_work_standard_no'] ?? ''),
        ];
    }
    $report['selected_units'] = $selectedUnits;
    $report['leader_checked_items'] = $leaderCheckedItemsByReportId[(int)($report['report_id'] ?? 0)] ?? [];
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
$unitTypeOptions = work_list_collect_type_options();
$workListKeyword = trim((string)($_GET['work_keyword'] ?? ''));
$workDateFrom = trim((string)($_GET['work_date_from'] ?? ''));
$workDateTo = trim((string)($_GET['work_date_to'] ?? ''));
$isDefaultMonthDateRange = false;
if ($workDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDateFrom)) {
    $workDateFrom = '';
}
if ($workDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDateTo)) {
    $workDateTo = '';
}
if ($workDateFrom === '' && $workDateTo === '') {
  $workDateFrom = date('Y-m-01');
  $workDateTo = date('Y-m-t');
  $isDefaultMonthDateRange = true;
}
$selectedUnitTypeFilter = trim((string)($_GET['filter_type'] ?? ''));
if ($selectedUnitTypeFilter !== '' && !array_key_exists($selectedUnitTypeFilter, $unitTypeOptions)) {
    $selectedUnitTypeFilter = '';
}

$majorOptions = work_list_collect_major_options($pdo, $selectedUnitTypeFilter);
$selectedMajorFilter = trim((string)($_GET['filter_major'] ?? ''));
if ($selectedMajorFilter !== '' && !in_array($selectedMajorFilter, $majorOptions, true)) {
    $selectedMajorFilter = '';
}

$checkedItemOptions = work_list_collect_checked_item_options($reports);
$selectedCheckedItemFilter = trim((string)($_GET['filter_checked_item'] ?? ''));
if ($selectedCheckedItemFilter !== '' && !in_array($selectedCheckedItemFilter, $checkedItemOptions, true)) {
    $selectedCheckedItemFilter = '';
}

if ($selectedUnitTypeFilter !== '' || $selectedMajorFilter !== '') {
    $reports = array_values(array_filter(
        $reports,
        static fn(array $report): bool => work_list_report_matches_filters($report, $selectedUnitTypeFilter, $selectedMajorFilter)
    ));
}

if ($selectedCheckedItemFilter !== '') {
    $reports = array_values(array_filter(
        $reports,
        static fn(array $report): bool => work_list_report_matches_checked_item($report, $selectedCheckedItemFilter)
    ));
}

if ($workListKeyword !== '') {
    $reports = array_values(array_filter(
        $reports,
        static fn(array $report): bool => work_list_report_matches_keyword($report, $workListKeyword)
    ));
}

if ($workDateFrom !== '' || $workDateTo !== '') {
    $reports = array_values(array_filter(
        $reports,
        static fn(array $report): bool => work_list_report_matches_date_range($report, $workDateFrom, $workDateTo)
    ));
}

$hasWorkListFilter = $selectedUnitTypeFilter !== ''
  || $selectedMajorFilter !== ''
  || $selectedCheckedItemFilter !== ''
  || $workListKeyword !== ''
  || (!$isDefaultMonthDateRange && ($workDateFrom !== '' || $workDateTo !== ''));
$filteredReportCount = count($reports);
$workListPerPage = 10;
$currentWorkListPage = max(1, (int)($_GET['page'] ?? 1));
$totalWorkListPages = max(1, (int)ceil($filteredReportCount / $workListPerPage));
$currentWorkListPage = min($currentWorkListPage, $totalWorkListPages);
$workListPageOffset = ($currentWorkListPage - 1) * $workListPerPage;
$pagedReports = array_slice($reports, $workListPageOffset, $workListPerPage);
$workListPagination = render_work_list_pagination(
    $filteredReportCount,
    $currentWorkListPage,
    $workListPerPage,
    [
        'work_keyword' => $workListKeyword,
        'work_date_from' => $workDateFrom,
        'work_date_to' => $workDateTo,
        'filter_type' => $selectedUnitTypeFilter,
        'filter_major' => $selectedMajorFilter,
        'filter_checked_item' => $selectedCheckedItemFilter,
    ]
);

$pagedReportIds = array_values(array_unique(array_map(
    static fn($row) => (int)($row['report_id'] ?? 0),
    $pagedReports
)));
$pagedReportIds = array_values(array_filter($pagedReportIds, static fn($id) => $id > 0));

$hazardParticipantMap = [];
if (!empty($pagedReportIds) && tableExists($pdo, 'work_report_worker_hazard_selection')) {
    $placeholders = implode(',', array_fill(0, count($pagedReportIds), '?'));
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
    $participantStmt->execute($pagedReportIds);
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

$attachedPhotoCountMap = [];
if (!empty($pagedReportIds) && tableExists($pdo, 'work_report_image')) {
    $placeholders = implode(',', array_fill(0, count($pagedReportIds), '?'));
    $photoStmt = $pdo->prepare("
        SELECT
            report_id,
            COUNT(*) AS photo_count
        FROM work_report_image
        WHERE report_id IN ($placeholders)
        GROUP BY report_id
    ");
    $photoStmt->execute($pagedReportIds);
    foreach ($photoStmt->fetchAll() as $photoRow) {
        $mapReportId = (int)($photoRow['report_id'] ?? 0);
        if ($mapReportId <= 0) {
            continue;
        }
        $attachedPhotoCountMap[$mapReportId] = (int)($photoRow['photo_count'] ?? 0);
    }
}

foreach ($pagedReports as &$report) {
    $reportIdKey = (int)($report['report_id'] ?? 0);
    $participants = $hazardParticipantMap[$reportIdKey] ?? [];
    $report['hazard_participants'] = $participants;
    if ((int)($report['hazard_participant_count'] ?? 0) <= 0 && !empty($participants)) {
        $report['hazard_participant_count'] = count($participants);
    }
    $report['attached_photo_count'] = (int)($attachedPhotoCountMap[$reportIdKey] ?? 0);
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

$currentUserName = trim((string)($user['name'] ?? ''));
$currentUserLoginId = trim((string)($user['login_id'] ?? ''));
$isJungYeontakAccount = $currentUserLoginId === '6680';
$canAccessLegacyListPage = $currentUserName === "\u{AE40}\u{B0A8}\u{ADE0}";
$canAccessMyGearTest = $currentUserName === "\u{AE40}\u{B0A8}\u{ADE0}";
$canAccessSafetyGearManagement = $canManage && !$isJungYeontakAccount;
$canAccessEmploymentRules = in_array($currentUserLoginId, [
    '5878',
    '2316',
    '7204',
    '6680',
], true);
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
    padding: 24px clamp(12px, 1.5vw, 24px) 40px;
  }
  .shell {
    width: min(100%, 1880px);
    margin: 0 auto;
  }
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
  .btn-employment-rules {
    background: rgba(164, 214, 178, 0.22) !important;
    color: #dff7e6 !important;
    border-color: rgba(164, 214, 178, 0.52) !important;
  }
  .btn-employment-rules:hover {
    background: rgba(164, 214, 178, 0.32) !important;
    color: #effcf2 !important;
  }
  .btn-header-cta {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%) !important;
    color: #fff !important;
    border-color: transparent !important;
    font-weight: 800;
    box-shadow: 0 12px 24px rgba(232, 146, 10, 0.26);
  }
  .btn-header-cta:hover {
    background: linear-gradient(135deg, var(--accent2) 0%, var(--accent) 100%) !important;
    color: #fff !important;
    transform: translateY(-1px);
    box-shadow: 0 16px 28px rgba(232, 146, 10, 0.32);
  }
  .btn-secondary:disabled {
    opacity: 0.55;
    cursor: wait;
  }
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
  input.work-search-input[type="date"] {
    color-scheme: dark;
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
  }
  input.work-search-input[type="date"]::-webkit-calendar-picker-indicator {
    filter: brightness(0) invert(1);
    cursor: pointer;
    opacity: 1;
  }
  .work-search-meta {
    margin-top: 10px;
    color: var(--text-dim);
    font-size: 12px;
  }
  .search-entry-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
  }
  .search-entry-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 180px;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.05);
    color: var(--text-hi);
    font: inherit;
    font-weight: 800;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, transform .15s ease;
  }
  .search-entry-button:hover {
    background: rgba(255,255,255,0.09);
    border-color: rgba(245, 166, 35, 0.4);
    transform: translateY(-1px);
  }
  .search-tool-modal {
    width: min(780px, 100%);
    height: min(920px, calc(100vh - 24px));
    max-height: calc(100vh - 24px);
  }
  .search-tool-body {
    padding: 18px 22px 22px;
    height: calc(100% - 88px);
    max-height: calc(100vh - 112px);
    overflow: auto;
  }
  .search-tool-body .work-search-form,
  .search-tool-body .work-filter-form {
    margin-top: 0;
  }
  .search-tool-body .work-search-meta,
  .search-tool-body .unit-db-search-note {
    margin-top: 12px;
  }
  .work-filter-form {
    display: flex;
    gap: 10px;
    align-items: end;
    flex-wrap: wrap;
    margin-top: 14px;
  }
  .work-filter-field {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
    flex: 0 1 220px;
  }
  .work-filter-label {
    color: var(--text-dim);
    font-size: 12px;
    font-weight: 700;
  }
  .work-filter-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    width: 100%;
    padding: 11px 42px 11px 14px;
    border-radius: 12px;
    border: 1px solid var(--border2);
    background-color: rgba(255,255,255,0.04);
    background-image:
      linear-gradient(45deg, transparent 50%, rgba(245, 166, 35, 0.95) 50%),
      linear-gradient(135deg, rgba(245, 166, 35, 0.95) 50%, transparent 50%),
      linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.02));
    background-position:
      calc(100% - 20px) calc(50% - 2px),
      calc(100% - 14px) calc(50% - 2px),
      0 0;
    background-size:
      6px 6px,
      6px 6px,
      100% 100%;
    background-repeat: no-repeat;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.03);
    color: var(--text-hi);
    font: inherit;
    line-height: 1.4;
    cursor: pointer;
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
  }
  .work-filter-select:hover {
    background-color: rgba(255,255,255,0.07);
    border-color: rgba(245, 166, 35, 0.22);
  }
  .work-filter-select:focus {
    outline: 2px solid rgba(245, 166, 35, 0.25);
    border-color: rgba(245, 166, 35, 0.45);
    background-color: rgba(255,255,255,0.08);
    box-shadow: 0 0 0 4px rgba(245, 166, 35, 0.08);
  }
  .work-filter-select option {
    background: #12203a;
    color: var(--text-hi);
  }
  .work-filter-select::-ms-expand {
    display: none;
  }
  .work-filter-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }
  .work-filter-count {
    color: var(--text-dim);
    font-size: 12px;
    font-weight: 700;
  }
  .unit-db-search-title {
    margin-top: 16px;
    color: var(--text-hi);
    font-size: 15px;
    font-weight: 800;
    letter-spacing: .02em;
  }
  .unit-db-search-note {
    margin-top: 10px;
    color: var(--text-dim);
    font-size: 12px;
  }
  .unit-db-result-list {
    display: grid;
    gap: 10px;
  }
  .unit-db-result-button {
    width: 100%;
    text-align: left;
    border: 1px solid var(--border2);
    border-radius: 14px;
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
    padding: 14px 16px;
    cursor: pointer;
    font: inherit;
    transition: background .15s ease, border-color .15s ease, transform .15s ease;
  }
  .unit-db-result-button:hover {
    background: rgba(255,255,255,0.08);
    border-color: rgba(245, 166, 35, 0.4);
    transform: translateY(-1px);
  }
  .unit-db-result-code {
    color: var(--accent2);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .04em;
    margin-bottom: 6px;
  }
  .unit-db-result-title {
    color: var(--text-hi);
    font-size: 15px;
    font-weight: 800;
    margin-bottom: 8px;
  }
  .unit-db-result-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .unit-db-result-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border2);
    color: var(--text);
    font-size: 12px;
    line-height: 1.3;
  }
  .risk-badge-line {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }
  .risk-badge-line:last-child {
    margin-bottom: 0;
  }
  .risk-level-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 56px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .02em;
    border: 1px solid transparent;
  }
  .risk-level-badge.is-low {
    background: rgba(54, 179, 126, 0.16);
    border-color: rgba(54, 179, 126, 0.36);
    color: #8ef0ba;
  }
  .risk-level-badge.is-medium {
    background: rgba(245, 166, 35, 0.16);
    border-color: rgba(245, 166, 35, 0.36);
    color: #ffd28f;
  }
  .risk-level-badge.is-high {
    background: rgba(214, 69, 65, 0.16);
    border-color: rgba(214, 69, 65, 0.38);
    color: #ffb2ae;
  }
  .risk-score-text {
    color: var(--text-hi);
    font-weight: 700;
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
    max-height: min(520px, calc(100vh - 220px));
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
  .table-wrap {
    overflow-x: auto;
    padding: 0 clamp(12px, 1.4vw, 24px) 18px;
    background: transparent !important;
  }
  .mobile-list { display: none; padding: 0 16px 16px; }
  .pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    justify-content: center;
    padding: 0 18px 22px;
  }
  .pagination a,
  .pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.04);
    color: var(--text);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    line-height: 1.2;
  }
  .pagination a:hover {
    background: rgba(255,255,255,0.09);
    border-color: rgba(245, 166, 35, 0.36);
    color: var(--text-hi);
  }
  .pagination .current {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 10px 20px rgba(232, 146, 10, 0.24);
  }
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
  .photo-indicator {
    color: var(--accent2);
    font-weight: 800;
  }
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
  .search-tool-head-actions {
    align-items: center;
  }
  .org-current-count {
    display: inline-flex;
    align-items: center;
    position: absolute;
    top: 14px;
    right: 16px;
    z-index: 3;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgba(27, 53, 86, 0.16);
    border-radius: 999px;
    background: #ffffff;
    color: #314258;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
    box-shadow: 0 8px 22px rgba(16, 31, 48, 0.1);
  }
  .org-current-count strong {
    margin-left: 5px;
    color: #16212f;
    font-size: 14px;
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
    .work-filter-field { flex-basis: 100%; min-width: 0; }
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
    .search-tool-modal { height: calc(100vh - 12px); max-height: calc(100vh - 12px); }
    .search-tool-body { height: calc(100% - 86px); max-height: calc(100vh - 118px); }
    .work-search-results { max-height: min(420px, calc(100vh - 200px)); }
    .unit-preview-body { padding: 14px 16px 16px; }
    .unit-preview-meta-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .standard-preview-grid { grid-template-columns: 1fr; }
  }
  /* 조직도 */
  .org-modal-shell {
    width: min(940px, 100%);
    border-radius: 20px;
    border-color: rgba(255,255,255,0.18);
    box-shadow: 0 28px 70px rgba(0,0,0,0.48);
  }
  .org-modal-head {
    align-items: center;
    padding: 18px 22px 14px;
    border-bottom-color: rgba(255,255,255,0.12);
    background:
      radial-gradient(circle at 18% -35%, rgba(245,166,35,0.23), transparent 50%),
      radial-gradient(circle at 90% 0%, rgba(77,157,255,0.2), transparent 42%),
      rgba(12, 20, 32, 0.66);
  }
  .org-modal-title-wrap h2 {
    margin: 0;
    font-size: 20px;
    letter-spacing: .01em;
    color: #f5f8fc;
  }
  .org-modal-sub {
    margin-top: 7px;
    color: #b8c7d9;
    font-size: 12px;
    letter-spacing: .01em;
    line-height: 1.5;
  }
  .org-modal-body {
    padding: 18px 20px 22px;
    overflow-y: auto;
    max-height: calc(100vh - 132px);
  }
  .org-modal-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 12px;
  }
  .org-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 11px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.16);
    background: rgba(255,255,255,0.03);
    color: var(--text);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .03em;
  }
  .org-legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    flex-shrink: 0;
  }
  .org-legend-dot.ceo { background: #f5a623; }
  .org-legend-dot.safety { background: #5ddb9a; }
  .org-legend-dot.team { background: #5e8cff; }
  .org-chart-surface {
    --org-team-top-width: 110px;
    --org-team-child-width: 152px;
    position: relative;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 16px;
    padding: 20px 16px 14px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
    background:
      radial-gradient(circle at 50% -20%, rgba(255,255,255,0.08), transparent 50%),
      linear-gradient(180deg, rgba(255,255,255,0.028), rgba(255,255,255,0.012)),
      repeating-linear-gradient(
        45deg,
        rgba(255,255,255,0.008) 0px,
        rgba(255,255,255,0.008) 10px,
        rgba(255,255,255,0.0) 10px,
        rgba(255,255,255,0.0) 20px
      );
  }
  .org-teams-wrap::-webkit-scrollbar { height: 7px; }
  .org-teams-wrap::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.22);
    border-radius: 999px;
  }
  .org-teams-wrap::-webkit-scrollbar-track { background: rgba(255,255,255,0.07); border-radius: 999px; }
  .org-chart { display:flex; flex-direction:column; align-items:center; }
  .org-vert { width:2px; height:34px; background:linear-gradient(180deg, rgba(55, 74, 104, .82), rgba(55, 74, 104, .62)); flex-shrink:0; }
  /* 노드 */
  .org-node {
    border: 1px solid rgba(27, 53, 86, 0.14);
    border-radius: 13px;
    padding: 0;
    text-align: center;
    background: linear-gradient(180deg, #ffffff, #f7fbff);
    box-shadow: 0 12px 30px rgba(16, 31, 48, .12);
    overflow: hidden;
  }
  .org-node-label {
    display: block;
    font-size: 10px;
    color: #fff;
    letter-spacing: .08em;
    margin: 0;
    padding: 7px 12px;
    font-weight: 800;
  }
  .org-node-name {
    font-size: 15px;
    font-weight: 700;
    color: #16212f;
    line-height: 1.35;
    padding: 9px 20px 10px;
  }
  .org-node-ceo { border-color: rgba(226, 160, 66, 0.55); box-shadow: 0 12px 30px rgba(226, 160, 66, .16); }
  .org-node-ceo .org-node-label { background: linear-gradient(90deg, rgba(245,166,35,.96), rgba(227,137,32,.96)); }
  .org-node-safety { border-color: rgba(93,219,154,.5); box-shadow: 0 12px 30px rgba(48, 151, 104, .14); }
  .org-node-safety .org-node-label { background: linear-gradient(90deg, rgba(75,176,127,.95), rgba(48,151,104,.95)); }
  /* 안전관리자 T-분기:
     - junction이 컨테이너 전체 너비를 차지(align-self:stretch)하고 position:relative
     - stem은 flex-column으로 세로로 쌓아 중앙 정렬 → CEO와 수직 정렬 유지
     - branch는 절대 위치: left:50%(stem 중심) top:50%(stem 중간) */
  .org-safety-junction {
    align-self: stretch; position:relative;
    display:flex; flex-direction:column; align-items:center;
  }
  .org-junction-stem { display:flex; flex-direction:column; align-items:center; }
  .org-junction-branch {
    position:absolute; left:50% ; top:50%; transform:translateY(-50%);
    display:flex; align-items:center;
  }
  .org-junction-hline { width:130px; height:2px; background:linear-gradient(90deg, rgba(76, 108, 145, .5), rgba(48, 151, 104, .45)); flex-shrink:0; }
  .org-junction-branch .org-node {
    margin-left: 0px; /* stem과 겹치지 않도록 여백 */
  }
  /* 팀 연결: fit-content로 카드 내용에 맞게만 늘어나도록 */
  .org-teams-wrap { width:100%; overflow-x:auto; padding-bottom:4px; }
  .org-teams-row {
    display:flex;
    gap:18px;
    align-items:flex-start;
    position:relative;
    width:fit-content;
    margin:0 auto;
  }
  .org-teams-row::before {
    content:''; position:absolute; top:0;
    left: calc(var(--org-team-top-width) / 2);
    right: calc(var(--org-team-top-width) / 2);
    height:2px; background:rgba(76, 108, 145, 0.42);
  }
  /* 팀 카드 래퍼: overflow:hidden 밖에서 수직 연결선을 그림 */
  .org-team-col {
    display:flex; flex-direction:column; align-items:center;
    padding-top:36px; position:relative;
    width: var(--org-team-top-width);
    flex: 0 0 var(--org-team-top-width);
  }
  .org-team-col::before {
    content:''; position:absolute; top:0; left:50%; transform:translateX(-50%);
    width:2px; height:36px; background:rgba(76, 108, 145, 0.42);
  }
  .org-team-card {
    width: 100%;
    min-width: 0;
    background: linear-gradient(180deg, #ffffff, #f7fbff);
    border:1px solid rgba(27, 53, 86, 0.14);
    border-radius:10px;
    overflow:hidden;
    box-shadow: 0 12px 30px rgba(16, 31, 48, .12);
  }
  .org-team-card-child { width: 100%; min-width: 0; }
  .org-team-head {
    background: linear-gradient(90deg, rgba(245,166,35,.95), rgba(227,137,32,.95));
    color:#fff;
    text-align:center;
    padding:9px 12px;
    font-size:12px;
    font-weight:800;
    letter-spacing:.03em;
    line-height: 1.25;
  }
  .org-team-body {
    padding: 11px 12px 10px;
    background: linear-gradient(180deg, #fbfdff 0%, #f2f7fc 100%);
    color: #16212f;
  }
  .org-team-children-wrap {
    position:relative;
    width:max-content;
    min-width:100%;
    margin-top:0;
    padding-top:18px;
    display:flex;
    justify-content:center;
  }
  .org-team-children-wrap::before {
    content:'';
    position:absolute;
    top:0;
    left:50%;
    transform:translateX(-50%);
    width:2px;
    height:18px;
    background:rgba(76, 108, 145, 0.42);
  }
  .org-team-children-row {
    display:flex;
    gap:16px;
    padding-top:0;
    width:max-content;
    justify-content:center;
    position:relative;
  }
  .org-team-children-row::before {
    content:'';
    position:absolute;
    top:0;
    left: calc(var(--org-team-child-width) / 2);
    right: calc(var(--org-team-child-width) / 2);
    height:2px;
    background:rgba(76, 108, 145, 0.42);
  }
  .org-team-children-row.is-single::before {
    display:none;
  }
  .org-subteam-col {
    display:flex;
    flex-direction:column;
    align-items:center;
    width: var(--org-team-child-width);
    flex: 0 0 var(--org-team-child-width);
    padding-top:18px;
    position:relative;
  }
  .org-subteam-col::before {
    content:'';
    position:absolute;
    top:0;
    left:50%;
    transform:translateX(-50%);
    width:2px;
    height:18px;
    background:rgba(76, 108, 145, 0.42);
  }
  .org-role-sec { padding:5px 0; border-top:1px solid var(--border2); }
  .org-role-sec { padding:7px 0 6px; border-top:1px dashed rgba(27, 53, 86, 0.14); }
  .org-role-sec:first-child { border-top:none; padding-top:0; }
  .org-role-lbl {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size:10px;
    color:#5b6b7f;
    margin-bottom:5px;
    letter-spacing:.05em;
    font-weight: 700;
  }
  .org-role-lbl::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 999px;
    background: rgba(245,166,35,0.65);
  }
  .org-role-lbl.role-manager { color: #9a6200; }
  .org-role-lbl.role-manager::before { background: #f5a623; }
  .org-role-lbl.role-leader { color: #205db5; }
  .org-role-lbl.role-leader::before { background: #4d9dff; }
  .org-role-lbl.role-worker { color: #23764b; }
  .org-role-lbl.role-worker::before { background: #5ddb9a; }
  .org-member-name {
    display:block;
    font-size:12px;
    color:#16212f;
    font-weight: 700;
    padding:2px 0;
    line-height: 1.5;
    text-shadow: none;
  }
  .org-member-name + .org-member-name { margin-top: 1px; }
  .org-has-phone {
    cursor:pointer;
    text-decoration:none;
    border-radius:6px;
    padding:2px 5px;
    margin:0 -5px;
    transition: background .15s ease, color .15s ease;
  }
  .org-has-phone:hover { color:#9a6200; background: rgba(245,166,35,0.16); }
  @media (max-width: 720px) {
    .org-modal-shell { width: min(100%, 100%); }
    .org-modal-head { align-items: flex-start; }
    .org-modal-title-wrap h2 { font-size: 18px; }
    .org-modal-body { padding: 14px 12px 16px; max-height: calc(100vh - 110px); }
    .org-chart-surface { --org-team-top-width: 96px; --org-team-child-width: 136px; padding: 14px 10px 10px; }
    .org-teams-row { gap: 12px; }
    .org-team-col { padding-top: 28px; }
    .org-team-col::before { height: 28px; }
    .org-team-card { min-width: 96px; }
    .org-team-children-wrap { padding-top: 14px; }
    .org-team-children-row { gap: 12px; }
    .org-team-children-wrap::before,
    .org-subteam-col::before { height: 14px; }
  }
  #org-phone-tip {
    position:fixed; z-index:99999; display:none;
    background:var(--accent);
    border-radius:10px; padding:10px 16px;
    box-shadow:0 6px 28px rgba(0,0,0,.7);
    font-size:13px; white-space:nowrap; pointer-events:none;
  }
  #org-phone-tip .tip-name { color:rgba(255,255,255,.8); font-size:11px; margin-bottom:3px; }
  #org-phone-tip .tip-phone { color:#fff; font-weight:800; font-size:16px; letter-spacing:.04em; }
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
            $isOperator = $isAdmin || $userRole === 'safety_manager';
            // 팀에 작업지휘자(leader)가 없으면 작업자가 직접 열기 가능
            $teamHasNoLeader = empty(auth_team_members((string)($user['team'] ?? ''), ['leader']));
          ?>
          <?php if ($isGasTeam): ?>
            <a class="btn-secondary" href="schedule.php">근무일정표</a>
          <?php endif; ?>
          <?php if ($isElectricalManager || $isOperator): ?>
            <a class="btn-secondary" href="schedule.php?view_team=가스팀">가스팀근무표</a>
          <?php endif; ?>
          <?php if (!$isWorker): ?>
            <?php if (!$isLeaderOnly && $userRole !== 'safety_manager' && $userRole !== 'administrator' && !$isJungYeontakAccount): ?>
              <a class="btn-secondary" href="<?= h($entryPage) ?>">작업 등록</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
              <a class="btn-secondary" href="register_worker.php">계정관리</a>
            <?php endif; ?>
          <?php endif; ?>
        <button type="button" class="btn-secondary" onclick="openOrgModal()">조직도</button>
        <a class="btn-secondary" href="../tbm/index.php">TBM일지</a>
        <?php if ($isOperator && !$isJungYeontakAccount): ?>
          <a class="btn-secondary" href="../safety_log/dashboard.php">안전일지</a>
        <?php endif; ?>
        <?php if ($canAccessLegacyListPage): ?>
          <a class="btn-secondary" href="list.html">평가서 등록</a>
        <?php endif; ?>
        <?php if ($canAccessMyGearTest): ?>
          <a class="btn-secondary" href="/safety_gear/my_gear.php">나의 보호구</a>
        <?php endif; ?>
        <?php if ($canAccessEmploymentRules): ?>
          <a class="btn-secondary btn-employment-rules" href="/employment_rules/index.php">취업규칙</a>
        <?php endif; ?>
        <?php if ($canAccessSafetyGearManagement): ?>
          <a class="btn-secondary" href="/safety_gear/index.php">보호구관리</a>
          <?php if (trim((string)auth_display_name($user)) === '김남균'): ?>
            <a class="btn-secondary" href="/material_management/index.php">물질관리</a>
          <?php endif; ?>
        <?php endif; ?>
        <a class="btn-secondary btn-header-cta" href="../board/index.php">게시판</a>
        <a class="btn-secondary" href="../calendar/index.html">달력</a>
        <a class="btn-secondary" href="hazard_review.php">수시위험성평가</a>
        <button type="button" class="btn-secondary" onclick="openPwModal()">비밀번호변경</button>
        <a class="btn-secondary" href="<?= h($entryPage) ?>?logout=1">로그아웃</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-label">WORK LIST</div>
        <h1>작업 <span>목록</span></h1>
        <p><?= h($workListDescription) ?></p>
        <div class="unit-db-search-title">작업목록 검색</div>
        <form class="work-search-form" method="get" autocomplete="off">
          <div class="work-search-box">
            <input
              type="search"
              name="work_keyword"
              class="work-search-input"
              placeholder="작업명, 장소, 팀명, 작업유형 검색"
              value="<?= h($workListKeyword) ?>"
            >
          </div>
          <input
            type="date"
            name="work_date_from"
            class="work-search-input"
            value="<?= h($workDateFrom) ?>"
            aria-label="작업일자 시작일"
            style="flex:0 1 170px; min-width:160px;"
          >
          <input
            type="date"
            name="work_date_to"
            class="work-search-input"
            value="<?= h($workDateTo) ?>"
            aria-label="작업일자 종료일"
            style="flex:0 1 170px; min-width:160px;"
          >
          <select
            name="filter_checked_item"
            class="work-filter-select"
            aria-label="작업지휘자 선택 항목"
            style="flex:0 1 240px; min-width:220px;"
          >
            <option value="">작업지휘자 선택 항목 전체</option>
            <?php foreach ($checkedItemOptions as $checkedItemOption): ?>
              <option value="<?= h($checkedItemOption) ?>" <?= $selectedCheckedItemFilter === $checkedItemOption ? 'selected' : '' ?>>
                <?= h($checkedItemOption) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($selectedUnitTypeFilter !== ''): ?>
            <input type="hidden" name="filter_type" value="<?= h($selectedUnitTypeFilter) ?>">
          <?php endif; ?>
          <?php if ($selectedMajorFilter !== ''): ?>
            <input type="hidden" name="filter_major" value="<?= h($selectedMajorFilter) ?>">
          <?php endif; ?>
          <button type="submit" class="btn-secondary">검색</button>
          <a class="btn-secondary" href="<?= h(build_page_url('work_list.php', [
              'filter_type' => $selectedUnitTypeFilter,
              'filter_major' => $selectedMajorFilter,
          ])) ?>">초기화</a>
        </form>
        <div class="work-search-meta">
          <?php if ($workListKeyword !== '' || $selectedCheckedItemFilter !== '' || !$isDefaultMonthDateRange): ?>
            <?php
              $searchMetaParts = [];
              if ($workListKeyword !== '') {
                  $searchMetaParts[] = '검색어 `' . h($workListKeyword) . '`';
              }
              if ($selectedCheckedItemFilter !== '') {
                  $searchMetaParts[] = '선택항목 `' . h($selectedCheckedItemFilter) . '`';
              }
              if ($workDateFrom !== '' || $workDateTo !== '') {
                  $searchMetaParts[] = '작업일자 ' . h($workDateFrom !== '' ? $workDateFrom : '전체') . ' ~ ' . h($workDateTo !== '' ? $workDateTo : '전체');
              }
            ?>
            <?= implode(' / ', $searchMetaParts) ?> 결과 <?= number_format($filteredReportCount) ?>건
          <?php else: ?>
            작업명, 장소, 팀명, 작업유형, 위험성평가번호, 작업지휘자 선택 항목과 작업일자로 검색할 수 있습니다.
          <?php endif; ?>
        </div>
        <div class="search-entry-actions">
          <button type="button" class="search-entry-button" id="open-standard-search-modal">작업표준서검색</button>
          <button type="button" class="search-entry-button" id="open-unit-db-search-modal">위험성평가서검색</button>
        </div>
      </div>

      <?php if ($successMessage !== ''): ?>
        <div class="flash-message"><?= h($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($errorMessage !== ''): ?>
        <div class="error-message"><?= h($errorMessage) ?></div>
      <?php endif; ?>

      <?php if (empty($reports)): ?>
        <div class="empty"><?= $hasWorkListFilter ? '검색 또는 필터 조건에 맞는 작업이 없습니다.' : '아직 저장된 작업이 없습니다.' ?></div>
      <?php else: ?>
        <div class="mobile-list">
          <?php foreach ($pagedReports as $report): ?>
            <article class="mobile-card">
              <?php $reportEntryPage = work_list_entry_page($user, $report, $entryPage); ?>
              <div class="mobile-card-head">
                <div>
                  <div class="work-title"><a href="work_list_detail.php?report_id=<?= (int)$report['report_id'] ?>" style="color:inherit;text-decoration:none;"><?= h($report['work_title']) ?></a></div>
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
                <?php
                  $canWorkerOpen = !$isWorker || $teamHasNoLeader || (int)($report['leader_detail_count'] ?? 0) > 0;
                  $workInputCompleted = $teamHasNoLeader || (int)($report['leader_detail_count'] ?? 0) > 0;
                  if ($isWorker) {
                      $allTasksCompleted = $workInputCompleted && (int)($report['current_user_hazard_submitted'] ?? 0) > 0;
                  } else {
                      $hazardReviewCompleted = (bool)($report['hazard_review_completed'] ?? false);
                      $allTasksCompleted = $workInputCompleted && $hazardReviewCompleted;
                  }
                  $canDeleteReport = $canManage && work_list_user_can_delete_report($user, $report, $allTasksCompleted);
                ?>
                <?php if ($canWorkerOpen && !$allTasksCompleted): ?>
                  <?php if ($isAdmin): ?>
                    <a class="btn-secondary" href="<?= h(build_page_url('task_select.php', $adminManagerOpenParams)) ?>">관리열기</a>
                    <a class="btn-secondary" href="leader_task_select.php?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>&saved_report_id=<?= (int)$report['report_id'] ?>&edit_report_id=<?= (int)$report['report_id'] ?>">지휘열기</a>
                  <?php else: ?>
                    <?php $managerOpenParams = [
                        'unit_ra_id' => (int)$report['unit_ra_id'],
                        'saved_report_id' => (int)$report['report_id'],
                    ];
                    if ($canManage && ($report['team_name_context'] ?? '') !== '') {
                        $managerOpenParams['manager_team'] = (string)$report['team_name_context'];
                    }
                    ?>
                    <a class="btn-secondary" href="<?= h(build_page_url($reportEntryPage, $managerOpenParams)) ?>">열기</a>
                  <?php endif; ?>
                <?php elseif ($isWorker && !$allTasksCompleted): ?>
                  <span class="sub-text">작업지휘자 입력 대기</span>
                <?php elseif ($allTasksCompleted): ?>
                  <?= render_view_completion_text($report) ?>
                <?php endif; ?>
                <?php if ($canDeleteReport): ?>
                  <form method="post" onsubmit="return window.confirm('이 작업을 삭제할까요? 완료된 작업문서는 삭제할 수 없습니다.');">
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
              <?php foreach ($pagedReports as $report): ?>
                <tr>
                  <?php $reportEntryPage = work_list_entry_page($user, $report, $entryPage); ?>
                  <td>
                    <div class="work-title"><a href="work_list_detail.php?report_id=<?= (int)$report['report_id'] ?>" style="color:inherit;text-decoration:none;"><?= h($report['work_title']) ?></a></div>
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
                      <?php
                        $canWorkerOpen = !$isWorker || $teamHasNoLeader || (int)($report['leader_detail_count'] ?? 0) > 0;
                        $workInputCompleted = $teamHasNoLeader || (int)($report['leader_detail_count'] ?? 0) > 0;
                        if ($isWorker) {
                            $allTasksCompleted = $workInputCompleted && (int)($report['current_user_hazard_submitted'] ?? 0) > 0;
                        } else {
                            $hazardReviewCompleted = (bool)($report['hazard_review_completed'] ?? false);
                            $allTasksCompleted = $workInputCompleted && $hazardReviewCompleted;
                        }
                        $canDeleteReport = $canManage && work_list_user_can_delete_report($user, $report, $allTasksCompleted);
                      ?>
                      <?php if ($canWorkerOpen && !$allTasksCompleted): ?>
                        <?php if ($isAdmin): ?>
                          <a class="btn-secondary" href="<?= h(build_page_url('task_select.php', $adminManagerOpenParams)) ?>">관리열기</a>
                          <a class="btn-secondary" href="leader_task_select.php?unit_ra_id=<?= (int)$report['unit_ra_id'] ?>&saved_report_id=<?= (int)$report['report_id'] ?>&edit_report_id=<?= (int)$report['report_id'] ?>">지휘열기</a>
                        <?php else: ?>
                          <?php $managerOpenParams = [
                              'unit_ra_id' => (int)$report['unit_ra_id'],
                              'saved_report_id' => (int)$report['report_id'],
                          ];
                          if ($canManage && ($report['team_name_context'] ?? '') !== '') {
                              $managerOpenParams['manager_team'] = (string)$report['team_name_context'];
                          }
                          ?>
                          <a class="btn-secondary" href="<?= h(build_page_url($reportEntryPage, $managerOpenParams)) ?>">열기</a>
                        <?php endif; ?>
                      <?php elseif ($isWorker && !$allTasksCompleted): ?>
                        <span class="sub-text">작업지휘자 입력 대기</span>
                      <?php elseif ($allTasksCompleted): ?>
                        <?= render_view_completion_text($report) ?>
                      <?php endif; ?>
                      <?php if ($canDeleteReport): ?>
                        <form method="post" onsubmit="return window.confirm('이 작업을 삭제할까요? 완료된 작업문서는 삭제할 수 없습니다.');">
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
        <?= $workListPagination ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="modal-backdrop" id="standard-search-modal" aria-hidden="true">
    <div class="unit-preview-modal search-tool-modal" role="dialog" aria-modal="true" aria-labelledby="standard-search-modal-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="standard-search-modal-title">작업표준서검색</h2>
          <p>작업표준서번호 또는 표준서명을 검색하고 결과를 선택하면 미리보기가 열립니다.</p>
        </div>
        <div class="modal-head-actions search-tool-head-actions">
          <a class="btn-secondary" href="/safety/index.html" target="_blank" rel="noopener">통합 조회 시스템</a>
          <button type="button" class="modal-close" data-standard-search-modal-close aria-label="닫기">&times;</button>
        </div>
      </div>
      <div class="search-tool-body">
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
    </div>
  </div>
  <div class="modal-backdrop" id="unit-db-search-modal" aria-hidden="true">
    <div class="unit-preview-modal search-tool-modal" role="dialog" aria-modal="true" aria-labelledby="unit-db-search-modal-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="unit-db-search-modal-title">위험성평가서검색</h2>
          <p>유형과 대분류 조건으로 단위위험성평가 DB를 조회하고 결과를 선택하면 미리보기가 열립니다.</p>
        </div>
        <button type="button" class="modal-close" data-unit-db-search-modal-close aria-label="닫기">&times;</button>
      </div>
      <div class="search-tool-body">
        <form class="work-filter-form" id="unit-db-filter-form" autocomplete="off">
          <div class="work-filter-field" style="flex:1 1 260px; min-width:240px;">
            <label class="work-filter-label" for="unit-db-keyword">검색어</label>
            <input
              type="search"
              id="unit-db-keyword"
              class="work-search-input"
              placeholder="평가서명 또는 위험성평가번호 검색"
            >
            <div class="work-search-results" id="unit-db-search-results"></div>
          </div>
          <div class="work-filter-field">
            <label class="work-filter-label" for="filter-type">유형</label>
            <select class="work-filter-select" id="filter-type" name="filter_type">
              <option value="">전체</option>
              <?php foreach ($unitTypeOptions as $typeValue => $typeLabel): ?>
                <option value="<?= h($typeValue) ?>"><?= h($typeLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="work-filter-field">
            <label class="work-filter-label" for="filter-major">대분류</label>
            <select class="work-filter-select" id="filter-major" name="filter_major">
              <option value="">전체</option>
            </select>
          </div>
          <div class="work-filter-actions">
            <button type="submit" class="btn-secondary" id="unit-db-search-button">조회</button>
            <button type="button" class="btn-secondary" id="unit-db-filter-reset">초기화</button>
            <span class="work-filter-count" id="unit-db-filter-count">단위위험성평가서를 조회할 수 있습니다.</span>
          </div>
        </form>
        <div class="unit-db-search-note">작업목록이 아니라 단위위험성평가 DB를 조회합니다. 결과를 누르면 미리보기가 열립니다.</div>
      </div>
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
  <div class="modal-backdrop" id="unit-db-modal" aria-hidden="true">
    <div class="unit-preview-modal" role="dialog" aria-modal="true" aria-labelledby="unit-db-modal-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="unit-db-modal-title">단위위험성평가서 조회</h2>
          <p id="unit-db-modal-subtitle">유형과 대분류 조건에 맞는 평가서를 표시합니다.</p>
        </div>
        <button type="button" class="modal-close" data-unit-db-modal-close aria-label="닫기">&times;</button>
      </div>
      <div class="unit-preview-body" id="unit-db-modal-body">
        <div class="unit-preview-empty">조회 결과가 여기에 표시됩니다.</div>
      </div>
    </div>
  </div>

  <!-- 비밀번호 변경 모달 -->
  <div class="modal-backdrop" id="pw-modal" aria-hidden="true">
    <div class="unit-preview-modal" style="width:min(420px,100%);">
      <div class="unit-preview-head">
        <h2 style="font-size:16px;">비밀번호 변경</h2>
        <button type="button" class="modal-close" id="pw-modal-close" aria-label="닫기">&times;</button>
      </div>
      <div class="unit-preview-body" style="padding:24px;">
        <div id="pw-msg" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:8px;font-size:13px;"></div>
        <div style="display:grid;gap:14px;">
          <div>
            <label style="display:block;font-size:12px;color:var(--text-lo);margin-bottom:6px;">현재 비밀번호</label>
            <input type="password" id="pw-current" placeholder="현재 비밀번호 입력"
              style="width:100%;padding:10px 12px;border:1px solid var(--border2);border-radius:8px;background:var(--bg2);color:var(--text-hi);font-size:14px;font-family:inherit;">
          </div>
          <div>
            <label style="display:block;font-size:12px;color:var(--text-lo);margin-bottom:6px;">새 비밀번호</label>
            <input type="password" id="pw-new" placeholder="새 비밀번호 (4자 이상)"
              style="width:100%;padding:10px 12px;border:1px solid var(--border2);border-radius:8px;background:var(--bg2);color:var(--text-hi);font-size:14px;font-family:inherit;">
          </div>
          <div>
            <label style="display:block;font-size:12px;color:var(--text-lo);margin-bottom:6px;">새 비밀번호 확인</label>
            <input type="password" id="pw-confirm" placeholder="새 비밀번호 다시 입력"
              style="width:100%;padding:10px 12px;border:1px solid var(--border2);border-radius:8px;background:var(--bg2);color:var(--text-hi);font-size:14px;font-family:inherit;">
          </div>
          <button type="button" id="pw-submit"
            style="padding:11px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;">
            변경하기
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 전화번호 팝업 -->
  <div id="org-phone-tip" aria-hidden="true">
    <div class="tip-name" id="org-tip-name"></div>
    <div class="tip-phone" id="org-tip-phone"></div>
  </div>

  <!-- 조직도 모달 -->
  <?php
    $orgChart = auth_org_chart_data();
    $orgCeo = $orgChart['ceo'] ?? [];
    $orgSafety = $orgChart['safety'] ?? [];
    $orgTeams = $orgChart['teams'] ?? [];
    $orgVisiblePeople = [];
    $orgAddVisiblePerson = static function(array $entry) use (&$orgVisiblePeople): void {
        $name = trim((string)($entry['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $phone = trim((string)($entry['phone'] ?? ''));
        $orgVisiblePeople[$name . '|' . $phone] = true;
    };
    foreach ($orgCeo as $entry) {
        $orgAddVisiblePerson($entry);
    }
    foreach ($orgSafety as $entry) {
        $orgAddVisiblePerson($entry);
    }
    $orgCollectVisiblePeople = static function(array $team) use (&$orgCollectVisiblePeople, $orgAddVisiblePerson): void {
        foreach (['managers', 'leaders', 'workers'] as $roleKey) {
            foreach (($team[$roleKey] ?? []) as $entry) {
                if (is_array($entry)) {
                    $orgAddVisiblePerson($entry);
                }
            }
        }
        foreach (($team['children'] ?? []) as $childTeam) {
            if (is_array($childTeam)) {
                $orgCollectVisiblePeople($childTeam);
            }
        }
    };
    foreach ($orgTeams as $team) {
        if (is_array($team)) {
            $orgCollectVisiblePeople($team);
        }
    }
    $orgCurrentPeopleCount = count($orgVisiblePeople);
  ?>
  <div class="modal-backdrop" id="org-modal" aria-hidden="true">
    <div class="unit-preview-modal org-modal-shell" role="dialog" aria-modal="true" aria-labelledby="org-modal-title">
      <div class="unit-preview-head org-modal-head">
        <div class="org-modal-title-wrap">
          <h2 id="org-modal-title">(주)현대기전 조직도</h2>
          <p class="org-modal-sub">이름을 누르면 전화번호를 빠르게 확인할 수 있습니다.</p>
        </div>
        <div class="modal-head-actions">
          <button type="button" class="btn-secondary" id="org-modal-print">인쇄 미리보기</button>
          <button type="button" class="modal-close" id="org-modal-close" aria-label="닫기">&times;</button>
        </div>
      </div>
      <div class="unit-preview-body org-modal-body">
        <?php
        $orgNameHtml = static function(array $entry): string {
            $name = htmlspecialchars($entry['name'], ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars($entry['phone'], ENT_QUOTES, 'UTF-8');
            if ($phone !== '') {
                return '<span class="org-member-name org-has-phone" data-name="' . $name . '" data-phone="' . $phone . '">' . $name . '</span>';
            }
            return '<span class="org-member-name">' . $name . '</span>';
        };
        $renderOrgTeamCard = static function(array $team, callable $orgNameHtml, callable $renderOrgTeamCard, bool $isChild = false): void {
            $children = array_values(array_filter($team['children'] ?? [], static fn($child): bool => is_array($child)));
            $childCount = count($children);
            $rowClass = 'org-team-children-row';
            if ($childCount === 1) {
                $rowClass .= ' is-single';
            }
        ?>
          <div class="org-team-card<?= $isChild ? ' org-team-card-child' : '' ?>">
            <div class="org-team-head"><?= h((string)($team['name'] ?? '')) ?></div>
            <div class="org-team-body">
              <?php if (!empty($team['managers'])): ?>
                <div class="org-role-sec">
                  <div class="org-role-lbl role-manager"><?= h((string)($team['manager_label'] ?? '관리감독자')) ?></div>
                  <?php foreach ($team['managers'] as $m): ?>
                    <?= $orgNameHtml($m) ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($team['leaders'])): ?>
                <div class="org-role-sec">
                  <div class="org-role-lbl role-leader"><?= h((string)($team['leader_label'] ?? '작업지휘자')) ?></div>
                  <?php foreach ($team['leaders'] as $m): ?>
                    <?= $orgNameHtml($m) ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($team['workers'])): ?>
                <div class="org-role-sec">
                  <div class="org-role-lbl role-worker"><?= h((string)($team['worker_label'] ?? '일반작업자')) ?></div>
                  <?php foreach ($team['workers'] as $m): ?>
                    <?= $orgNameHtml($m) ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($children)): ?>
            <div class="org-team-children-wrap">
              <div class="<?= h($rowClass) ?>">
                <?php foreach ($children as $childTeam): ?>
                  <div class="org-subteam-col">
                    <?php $renderOrgTeamCard($childTeam, $orgNameHtml, $renderOrgTeamCard, true); ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php
        };
        ?>
        <div class="org-chart-surface">
        <span class="org-current-count" data-org-current-count>현재인원 <strong><?= number_format($orgCurrentPeopleCount) ?></strong>명</span>
        <div class="org-chart">
          <!-- 대표이사 -->
          <?php foreach ($orgCeo as $entry): ?>
            <div class="org-node org-node-ceo">
              <div class="org-node-label">대표이사</div>
              <div class="org-node-name"><?= $orgNameHtml($entry) ?></div>
            </div>
          <?php endforeach; ?>

          <!-- 안전관리자 T-분기 (수직선 중간에서 우측으로 분기) -->
          <?php if (!empty($orgSafety)): ?>
            <div class="org-safety-junction">
              <div class="org-junction-stem">
                <div class="org-vert"></div>
                <div class="org-vert"></div>
              </div>
              <div class="org-junction-branch">
                <div class="org-junction-hline"></div>
                <div class="org-node org-node-safety">
                  <div class="org-node-label">안전관리자</div>
                  <div class="org-node-name">
                    <?php foreach ($orgSafety as $entry): ?>
                      <?= $orgNameHtml($entry) ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="org-vert"></div>
          <?php endif; ?>

          <!-- 팀 카드 (수평 연결선 + 수직 드롭) -->
          <?php if (!empty($orgTeams)): ?>
            <div class="org-teams-wrap">
            <div class="org-teams-row">
              <?php foreach ($orgTeams as $orgTeam): ?>
                <div class="org-team-col">
                  <?php $renderOrgTeamCard($orgTeam, $orgNameHtml, $renderOrgTeamCard); ?>
                </div><!-- org-team-col -->
              <?php endforeach; ?>
            </div>
            </div><!-- org-teams-wrap -->
          <?php endif; ?>
        </div>
        </div>
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
      const openStandardSearchModalButton = document.getElementById('open-standard-search-modal');
      const openUnitDbSearchModalButton = document.getElementById('open-unit-db-search-modal');
      const standardSearchModal = document.getElementById('standard-search-modal');
      const unitDbSearchModal = document.getElementById('unit-db-search-modal');
      const standardSearchForm = document.getElementById('standard-search-form');
      const standardSearchInput = document.getElementById('standard-search-input');
      const standardSearchMeta = document.getElementById('standard-search-meta');
      const standardSearchResults = document.getElementById('standard-search-results');
      const standardSearchReset = document.getElementById('standard-search-reset');
      const unitDbFilterForm = document.getElementById('unit-db-filter-form');
      const unitDbKeywordInput = document.getElementById('unit-db-keyword');
      const unitDbSearchResults = document.getElementById('unit-db-search-results');
      const unitDbFilterType = document.getElementById('filter-type');
      const unitDbFilterMajor = document.getElementById('filter-major');
      const unitDbFilterReset = document.getElementById('unit-db-filter-reset');
      const unitDbFilterCount = document.getElementById('unit-db-filter-count');
      const unitDbModal = document.getElementById('unit-db-modal');
      const unitDbModalSubtitle = document.getElementById('unit-db-modal-subtitle');
      const unitDbModalBody = document.getElementById('unit-db-modal-body');
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
        || !openStandardSearchModalButton
        || !openUnitDbSearchModalButton
        || !standardSearchModal
        || !unitDbSearchModal
        || !standardSearchForm
        || !standardSearchInput
        || !standardSearchMeta
        || !standardSearchResults
        || !standardSearchReset
        || !unitDbFilterForm
        || !unitDbKeywordInput
        || !unitDbSearchResults
        || !unitDbFilterType
        || !unitDbFilterMajor
        || !unitDbFilterReset
        || !unitDbFilterCount
        || !unitDbModal
        || !unitDbModalSubtitle
        || !unitDbModalBody
      ) {
        return;
      }

      const unitTypeLabels = {
        target: '작업대상',
        major_work: '중대위험작업',
        tool: '공구/장비',
        env: '작업환경',
      };
      let requestToken = 0;
      let previousBodyOverflow = '';
      let safetyRequestToken = 0;
      let previousSafetyBodyOverflow = '';
      let previousHazardParticipantBodyOverflow = '';
      let previousUnitDbBodyOverflow = '';
      let previousStandardSearchBodyOverflow = '';
      let previousUnitDbSearchBodyOverflow = '';
      let standardSearchDebounceTimer = 0;
      let standardSearchRequestToken = 0;
      let unitDbSearchDebounceTimer = 0;
      let unitDbRecords = [];
      let unitDbLoadPromise = null;

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

        const renderRiskLine = (label, probability, severity, riskScore) => {
          const hasValue = probability !== null || severity !== null || riskScore !== null
            || String(probability ?? '').trim() !== ''
            || String(severity ?? '').trim() !== ''
            || String(riskScore ?? '').trim() !== '';

          if (!hasValue) {
            return '';
          }

          const p = String(probability ?? '').trim() || '-';
          const s = String(severity ?? '').trim() || '-';
          const r = String(riskScore ?? '').trim() || '-';
          const numericScore = Number.parseInt(String(riskScore ?? '').trim(), 10);
          let riskLevelClass = 'is-medium';
          let riskLevelLabel = '중위험';
          if (Number.isFinite(numericScore)) {
            if (numericScore <= 6) {
              riskLevelClass = 'is-low';
              riskLevelLabel = '저위험';
            } else if (numericScore >= 15) {
              riskLevelClass = 'is-high';
              riskLevelLabel = '고위험';
            }
          }

          return `
            <div class="risk-badge-line">
              <span class="risk-level-badge ${riskLevelClass}">${escapeHtml(riskLevelLabel)}</span>
              <span class="risk-score-text"><strong>${escapeHtml(label)}</strong> P ${escapeHtml(p)} / S ${escapeHtml(s)} / R ${escapeHtml(r)}</span>
            </div>
          `;
        };

        const rows = items.map((item, index) => {
          const sortNo = String(item.sort_no ?? '').trim() !== '' ? item.sort_no : (index + 1);
          const accidentSummary = [item.accident_type, item.injury_result]
            .map((part) => String(part ?? '').trim())
            .filter(Boolean)
            .join(' / ');
          const riskSummary = [
            renderRiskLine('현재', item.likelihood_before, item.severity_before, item.risk_score_before),
            renderRiskLine('조치후', item.likelihood_current, item.severity_current, item.risk_score_current),
            renderRiskLine('개선후', item.likelihood_after, item.severity_after, item.risk_score_after),
          ].filter(Boolean).join('');

          return `
            <tr>
              <td>${escapeHtml(sortNo)}</td>
              <td>${displayValue(item.task_name)}</td>
              <td>${escapeHtml(String(item.hazard_4m_label || item.hazard_4m || '-').trim() || '-')}</td>
              <td>
                <strong>${displayValue(item.hazard_name)}</strong>
                ${String(item.cause_text ?? '').trim() !== '' ? `<div class="sub-text" style="margin-top:6px">원인/위험상황: ${displayTextBlock(item.cause_text)}</div>` : ''}
              </td>
              <td>${accidentSummary !== '' ? escapeHtml(accidentSummary) : '-'}</td>
              <td>${riskSummary !== '' ? `<div class="sub-text" style="line-height:1.6">${riskSummary}</div>` : '-'}</td>
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
                  <th>4M분류</th>
                  <th>유해위험요인</th>
                  <th>사고유형/상해결과</th>
                  <th>위험도</th>
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

      function updateUnitDbFilterCount(message) {
        unitDbFilterCount.textContent = message;
      }

      function hideUnitDbSearchResults() {
        unitDbSearchResults.classList.remove('is-open');
      }

      function clearUnitDbSearchResults() {
        hideUnitDbSearchResults();
        unitDbSearchResults.innerHTML = '';
      }

      async function ensureUnitDbRecordsLoaded() {
        if (unitDbLoadPromise) {
          return unitDbLoadPromise;
        }

        unitDbLoadPromise = fetch('unit_ra_list_api.php')
          .then((response) => response.json())
          .then((json) => {
            if (!json || !json.success) {
              throw new Error(json && json.message ? json.message : '단위위험성평가 목록을 불러오지 못했습니다.');
            }

            unitDbRecords = Array.isArray(json.data) ? json.data : [];
            return unitDbRecords;
          })
          .catch((error) => {
            unitDbLoadPromise = null;
            throw error;
          });

        return unitDbLoadPromise;
      }

      function updateUnitDbMajorOptions() {
        const selectedType = String(unitDbFilterType.value || '').trim();
        const previousValue = String(unitDbFilterMajor.value || '').trim();
        const names = [...new Set(
          unitDbRecords
            .filter((row) => !selectedType || String(row.unit_type || '').trim() === selectedType)
            .map((row) => String(row.process_name || '').trim())
            .filter(Boolean)
        )].sort((left, right) => left.localeCompare(right, 'ko'));

        unitDbFilterMajor.innerHTML = '<option value="">전체</option>'
          + names.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');

        if (previousValue && names.includes(previousValue)) {
          unitDbFilterMajor.value = previousValue;
        }
      }

      function filterUnitDbRecords(limit = 8) {
        const keywordValue = String(unitDbKeywordInput.value || '').trim().toLowerCase();
        const typeValue = String(unitDbFilterType.value || '').trim();
        const majorValue = String(unitDbFilterMajor.value || '').trim();

        return unitDbRecords
          .filter((row) => {
            const rowCode = String(row.unit_code || '').trim().toLowerCase();
            const rowTitle = String(row.unit_title || '').trim().toLowerCase();
            const rowType = String(row.unit_type || '').trim();
            const rowMajor = String(row.process_name || '').trim();
            return (!keywordValue || rowCode.includes(keywordValue) || rowTitle.includes(keywordValue))
              && (!typeValue || rowType === typeValue)
              && (!majorValue || rowMajor === majorValue);
          })
          .slice(0, limit);
      }

      function renderUnitDbSearchResults(records) {
        if (!Array.isArray(records) || records.length === 0) {
          clearUnitDbSearchResults();
          return;
        }

        unitDbSearchResults.classList.add('is-open');
        unitDbSearchResults.innerHTML = records.map((row) => `
          <button
            type="button"
            class="work-search-result-button"
            data-unit-db-open="${escapeHtml(String(row.unit_ra_id || '0'))}"
          >
            <span class="work-search-result-code">${escapeHtml(String(row.unit_code || '-'))}</span>
            <span class="work-search-result-name">
              ${escapeHtml(String(row.unit_title || '제목 없음'))}
              ${String(row.process_name || '').trim() !== '' ? ` · ${escapeHtml(String(row.process_name || '').trim())}` : ''}
            </span>
          </button>
        `).join('');
      }

      async function updateUnitDbSearchSuggestions() {
        const keywordValue = String(unitDbKeywordInput.value || '').trim();
        if (keywordValue === '') {
          clearUnitDbSearchResults();
          return;
        }

        try {
          await ensureUnitDbRecordsLoaded();
          updateUnitDbMajorOptions();
          renderUnitDbSearchResults(filterUnitDbRecords());
        } catch (error) {
          clearUnitDbSearchResults();
        }
      }

      function renderUnitDbResults(records) {
        if (!Array.isArray(records) || records.length === 0) {
          return '<div class="unit-preview-empty">조건에 맞는 단위위험성평가서가 없습니다.</div>';
        }

        return `
          <div class="unit-db-result-list">
            ${records.map((row) => `
              <button
                type="button"
                class="unit-db-result-button"
                data-unit-db-open="${escapeHtml(String(row.unit_ra_id || '0'))}"
              >
                <div class="unit-db-result-code">${escapeHtml(String(row.unit_code || '번호 미등록'))}</div>
                <div class="unit-db-result-title">${escapeHtml(String(row.unit_title || '제목 없음'))}</div>
                <div class="unit-db-result-meta">
                  <span class="unit-db-result-chip">유형 ${escapeHtml(unitTypeLabels[String(row.unit_type || '').trim()] || String(row.unit_type || '-'))}</span>
                  <span class="unit-db-result-chip">대분류 ${escapeHtml(String(row.process_name || '-'))}</span>
                  <span class="unit-db-result-chip">항목 ${escapeHtml(String(Number(row.item_count || 0)))}건</span>
                </div>
              </button>
            `).join('')}
          </div>
        `;
      }

      function openUnitDbModal(subtitle, bodyHtml) {
        closeUnitDbSearchModal();
        closeModal();
        closeSafetyModal();
        closeHazardParticipantModal();
        previousUnitDbBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        unitDbModalSubtitle.textContent = subtitle;
        unitDbModalBody.innerHTML = bodyHtml;
        unitDbModal.classList.add('is-open');
        unitDbModal.setAttribute('aria-hidden', 'false');
      }

      function closeUnitDbModal() {
        unitDbModal.classList.remove('is-open');
        unitDbModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousUnitDbBodyOverflow;
      }

      async function submitUnitDbSearch() {
        const keywordValue = String(unitDbKeywordInput.value || '').trim();
        const typeValue = String(unitDbFilterType.value || '').trim();
        const majorValue = String(unitDbFilterMajor.value || '').trim();

        updateUnitDbFilterCount('단위위험성평가 DB를 불러오는 중입니다.');
        try {
          await ensureUnitDbRecordsLoaded();
          updateUnitDbMajorOptions();

          const results = filterUnitDbRecords(Number.MAX_SAFE_INTEGER);

          const subtitleParts = [];
          if (keywordValue) {
            subtitleParts.push(`검색어: ${keywordValue}`);
          }
          if (typeValue) {
            subtitleParts.push(`유형: ${unitTypeLabels[typeValue] || typeValue}`);
          }
          if (majorValue) {
            subtitleParts.push(`대분류: ${majorValue}`);
          }
          const subtitle = subtitleParts.length > 0
            ? `${subtitleParts.join(' / ')} · ${results.length}건`
            : `전체 단위위험성평가서 ${results.length}건`;

          updateUnitDbFilterCount(`단위위험성평가 검색 결과 ${results.length}건`);
          openUnitDbModal(subtitle, renderUnitDbResults(results));
        } catch (error) {
          const message = error instanceof Error ? error.message : '단위위험성평가를 불러오지 못했습니다.';
          updateUnitDbFilterCount(message);
          openUnitDbModal('조회 결과를 불러오지 못했습니다.', `<div class="unit-preview-error">${escapeHtml(message)}</div>`);
        }
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

        closeStandardSearchModal();
        closeUnitDbSearchModal();
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

        closeStandardSearchModal();
        closeUnitDbSearchModal();
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
        closeStandardSearchModal();
        closeUnitDbSearchModal();

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

      function openStandardSearchModal() {
        closeModal();
        closeSafetyModal();
        closeHazardParticipantModal();
        closeUnitDbModal();
        closeUnitDbSearchModal();
        previousStandardSearchBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        standardSearchModal.classList.add('is-open');
        standardSearchModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => standardSearchInput.focus(), 0);
      }

      function closeStandardSearchModal() {
        standardSearchModal.classList.remove('is-open');
        standardSearchModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousStandardSearchBodyOverflow;
      }

      function openUnitDbSearchModal() {
        closeModal();
        closeSafetyModal();
        closeHazardParticipantModal();
        closeUnitDbModal();
        closeStandardSearchModal();
        previousUnitDbSearchBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        unitDbSearchModal.classList.add('is-open');
        unitDbSearchModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => unitDbKeywordInput.focus(), 0);
      }

      function closeUnitDbSearchModal() {
        unitDbSearchModal.classList.remove('is-open');
        unitDbSearchModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousUnitDbSearchBodyOverflow;
      }

      openStandardSearchModalButton.addEventListener('click', () => {
        openStandardSearchModal();
      });

      openUnitDbSearchModalButton.addEventListener('click', () => {
        openUnitDbSearchModal();
      });

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

      unitDbFilterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitUnitDbSearch();
      });

      unitDbFilterType.addEventListener('change', () => {
        unitDbFilterMajor.value = '';
        updateUnitDbMajorOptions();
        updateUnitDbFilterCount('선택한 유형 기준으로 단위위험성평가서를 조회할 수 있습니다.');
        updateUnitDbSearchSuggestions();
      });

      unitDbFilterReset.addEventListener('click', () => {
        unitDbKeywordInput.value = '';
        unitDbFilterType.value = '';
        unitDbFilterMajor.innerHTML = '<option value="">전체</option>';
        updateUnitDbMajorOptions();
        updateUnitDbFilterCount('단위위험성평가서를 조회할 수 있습니다.');
        clearUnitDbSearchResults();
      });

      unitDbKeywordInput.addEventListener('input', () => {
        window.clearTimeout(unitDbSearchDebounceTimer);
        unitDbSearchDebounceTimer = window.setTimeout(() => {
          updateUnitDbSearchSuggestions();
        }, 150);
      });

      unitDbKeywordInput.addEventListener('focus', () => {
        if (String(unitDbKeywordInput.value || '').trim() === '') {
          return;
        }
        updateUnitDbSearchSuggestions();
      });

      unitDbFilterMajor.addEventListener('change', () => {
        updateUnitDbSearchSuggestions();
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

      unitDbSearchResults.addEventListener('click', (event) => {
        const targetButton = event.target.closest('[data-unit-db-open]');
        if (!targetButton) {
          return;
        }
        clearUnitDbSearchResults();
        const unitRaId = Number(targetButton.dataset.unitDbOpen || 0);
        if (!Number.isInteger(unitRaId) || unitRaId <= 0) {
          return;
        }
        openModal(unitRaId);
      });

      document.addEventListener('click', (event) => {
        const inlinePreviewButton = event.target.closest('.js-inline-standard-preview');
        if (!inlinePreviewButton) {
          if (!event.target.closest('#standard-search-form')) {
            hideStandardSearchResults();
          }
          if (!event.target.closest('#unit-db-filter-form')) {
            hideUnitDbSearchResults();
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

      standardSearchModal.addEventListener('click', (event) => {
        if (event.target === standardSearchModal || event.target.closest('[data-standard-search-modal-close]')) {
          closeStandardSearchModal();
        }
      });

      unitDbSearchModal.addEventListener('click', (event) => {
        if (event.target === unitDbSearchModal || event.target.closest('[data-unit-db-search-modal-close]')) {
          closeUnitDbSearchModal();
        }
      });

      unitDbModal.addEventListener('click', (event) => {
        if (event.target === unitDbModal || event.target.closest('[data-unit-db-modal-close]')) {
          closeUnitDbModal();
          return;
        }

        const resultButton = event.target.closest('[data-unit-db-open]');
        if (!resultButton) {
          return;
        }

        const unitRaId = Number(resultButton.dataset.unitDbOpen || 0);
        if (!Number.isInteger(unitRaId) || unitRaId <= 0) {
          return;
        }

        closeUnitDbModal();
        openModal(unitRaId);
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && hazardParticipantModal.classList.contains('is-open')) {
          closeHazardParticipantModal();
        } else if (event.key === 'Escape' && unitDbSearchModal.classList.contains('is-open')) {
          closeUnitDbSearchModal();
        } else if (event.key === 'Escape' && standardSearchModal.classList.contains('is-open')) {
          closeStandardSearchModal();
        } else if (event.key === 'Escape' && unitDbModal.classList.contains('is-open')) {
          closeUnitDbModal();
        } else if (event.key === 'Escape' && safetyModal.classList.contains('is-open')) {
          closeSafetyModal();
        } else if (event.key === 'Escape' && modal.classList.contains('is-open')) {
          closeModal();
        }
      });

      ensureUnitDbRecordsLoaded()
        .then(() => {
          updateUnitDbMajorOptions();
          updateUnitDbFilterCount(`단위위험성평가서 ${unitDbRecords.length}건을 조회할 수 있습니다.`);
        })
        .catch((error) => {
          updateUnitDbFilterCount(error instanceof Error ? error.message : '단위위험성평가 목록을 불러오지 못했습니다.');
        });
    })();

    async function launchReceiptCropper() {
      const triggerButton = document.getElementById('btn-receipt-cropper');
      if (!triggerButton) return;

      const originalLabel = triggerButton.textContent;
      triggerButton.disabled = true;
      triggerButton.textContent = '실행 중…';

      try {
        const response = await fetch('launch_receipt_cropper.php', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        const payload = await response.json();
        if (!response.ok || !payload.success) {
          const errorMessage = payload.detail
            ? `${payload.message || '영수증 일괄크롭 실행에 실패했습니다.'}\n\n${payload.detail}`
            : (payload.message || '영수증 일괄크롭 실행에 실패했습니다.');
          throw new Error(errorMessage);
        }

        window.alert(payload.message || '영수증 일괄크롭 프로그램을 열었습니다.');
      } catch (error) {
        window.alert(error instanceof Error ? error.message : '영수증 일괄크롭 실행에 실패했습니다.');
      } finally {
        triggerButton.disabled = false;
        triggerButton.textContent = originalLabel;
      }
    }

    // ── 비밀번호 변경 모달 ────────────────────────────────────────
    (function() {
      const pwModal   = document.getElementById('pw-modal');
      const pwMsg     = document.getElementById('pw-msg');
      const pwCurrent = document.getElementById('pw-current');
      const pwNew     = document.getElementById('pw-new');
      const pwConfirm = document.getElementById('pw-confirm');
      const pwSubmit  = document.getElementById('pw-submit');

      function openPwModal() {
        pwModal.classList.add('is-open');
        pwModal.setAttribute('aria-hidden', 'false');
        pwCurrent.value = '';
        pwNew.value = '';
        pwConfirm.value = '';
        pwMsg.style.display = 'none';
        pwCurrent.focus();
      }

      function closePwModal() {
        pwModal.classList.remove('is-open');
        pwModal.setAttribute('aria-hidden', 'true');
      }

      function showPwMsg(text, isError) {
        pwMsg.textContent = text;
        pwMsg.style.display = '';
        pwMsg.style.background = isError ? 'rgba(220,50,50,0.15)' : 'rgba(34,180,100,0.15)';
        pwMsg.style.color = isError ? '#ff7b7b' : '#5ddb9a';
        pwMsg.style.border = isError ? '1px solid rgba(220,50,50,0.3)' : '1px solid rgba(34,180,100,0.3)';
      }

      window.openPwModal = openPwModal;

      document.getElementById('pw-modal-close').addEventListener('click', closePwModal);

      pwModal.addEventListener('click', function(e) {
        if (e.target === pwModal) closePwModal();
      });

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && pwModal.classList.contains('is-open')) closePwModal();
      });

      pwSubmit.addEventListener('click', async function() {
        const current = pwCurrent.value;
        const nw      = pwNew.value;
        const confirm = pwConfirm.value;

        if (!current || !nw || !confirm) {
          showPwMsg('모든 항목을 입력해주세요.', true);
          return;
        }
        if (nw !== confirm) {
          showPwMsg('새 비밀번호와 확인이 일치하지 않습니다.', true);
          return;
        }

        pwSubmit.disabled = true;
        pwSubmit.textContent = '처리 중…';

        try {
          const res  = await fetch('change_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: current, new_password: nw, confirm_password: confirm }),
          });
          const json = await res.json();
          if (json.success) {
            showPwMsg(json.message, false);
            pwCurrent.value = '';
            pwNew.value = '';
            pwConfirm.value = '';
            setTimeout(closePwModal, 1500);
          } else {
            showPwMsg(json.message, true);
          }
        } catch (e) {
          showPwMsg('네트워크 오류가 발생했습니다.', true);
        } finally {
          pwSubmit.disabled = false;
          pwSubmit.textContent = '변경하기';
        }
      });

      // Enter 키로 제출
      [pwCurrent, pwNew, pwConfirm].forEach(function(input) {
        input.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') pwSubmit.click();
        });
      });
    })();

    // ── 조직도 모달 ──────────────────────────────────────────────
    (function() {
      const orgModal = document.getElementById('org-modal');
      const orgClose = document.getElementById('org-modal-close');
      const orgPrint = document.getElementById('org-modal-print');
      if (!orgModal || !orgClose || !orgPrint) {
        return;
      }

      function openOrgModal() {
        orgModal.classList.add('is-open');
        orgModal.setAttribute('aria-hidden', 'false');
      }
      function closeOrgModal() {
        orgModal.classList.remove('is-open');
        orgModal.setAttribute('aria-hidden', 'true');
      }

      window.openOrgModal = openOrgModal;
      orgClose.addEventListener('click', closeOrgModal);
      orgModal.addEventListener('click', function(e) {
        if (e.target === orgModal) closeOrgModal();
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && orgModal.classList.contains('is-open')) closeOrgModal();
      });

      // 전화번호 팝업
      const phoneTip  = document.getElementById('org-phone-tip');
      const tipName   = document.getElementById('org-tip-name');
      const tipPhone  = document.getElementById('org-tip-phone');

      function showPhoneTip(name, phone, cx, cy) {
        tipName.textContent  = name;
        tipPhone.textContent = phone;
        phoneTip.style.display = 'block';
        const tw = phoneTip.offsetWidth  || 180;
        const th = phoneTip.offsetHeight || 60;
        phoneTip.style.left = Math.min(cx + 10, window.innerWidth  - tw - 12) + 'px';
        phoneTip.style.top  = Math.min(cy + 10, window.innerHeight - th - 12) + 'px';
      }
      function hidePhoneTip() { phoneTip.style.display = 'none'; }

      function openOrgPrintPreview() {
        hidePhoneTip();
        const orgBody = orgModal.querySelector('.org-modal-body');
        const orgTitle = document.getElementById('org-modal-title');
        if (!orgBody || !orgTitle) {
          return;
        }

        const previewWindow = window.open('', '_blank');
        if (!previewWindow) {
          return;
        }
        try {
          previewWindow.opener = null;
        } catch (e) {}
        previewWindow.focus();

        const headMarkup = Array.from(document.head.querySelectorAll('style, link[rel="stylesheet"]'))
          .map((node) => node.outerHTML)
          .join('\n');
        const doc = previewWindow.document;
        doc.open();
        doc.write('<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body></body></html>');
        doc.close();

        doc.title = orgTitle.textContent || '조직도';
        doc.head.insertAdjacentHTML('beforeend', headMarkup);
        doc.head.insertAdjacentHTML('beforeend', `
<style>
  body {
    margin: 0;
    padding: 24px;
    background: #ffffff !important;
    color: #16212f !important;
  }
  .org-print-shell {
    max-width: 1280px;
    margin: 0 auto;
    background: #ffffff !important;
  }
  .org-print-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    color: #16212f !important;
    background: #ffffff !important;
    border-bottom: 1px solid #d8e1ea;
    padding-bottom: 12px;
    font-family: "Malgun Gothic", sans-serif;
  }
  .org-print-head h1 {
    margin: 0;
    font-size: 24px;
    color: #16212f !important;
  }
  .org-print-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .org-print-actions .btn-secondary {
    background: #ffffff !important;
    color: #1d2b45 !important;
    border: 1px solid #c9d2e2 !important;
    box-shadow: none !important;
  }
  .org-print-actions .btn-secondary:hover {
    background: #f5f8fc !important;
  }
  .org-print-shell .org-modal-legend {
    color: #223247;
  }
  .org-print-shell .org-junction-hline {
    background: linear-gradient(90deg, rgba(55, 74, 104, .82), rgba(37, 107, 76, .72)) !important;
  }
  .org-print-shell .org-vert {
    background: linear-gradient(180deg, rgba(55, 74, 104, .82), rgba(55, 74, 104, .62)) !important;
  }
  .org-print-shell .org-teams-row::before,
  .org-print-shell .org-team-col::before,
  .org-print-shell .org-team-children-wrap::before,
  .org-print-shell .org-team-children-row::before,
  .org-print-shell .org-subteam-col::before {
    background: rgba(55, 74, 104, 0.78) !important;
  }
  .org-print-shell .org-chart-surface {
    background: #ffffff !important;
    box-shadow: 0 10px 30px rgba(18, 33, 47, 0.08);
  }
  .org-print-shell .org-team-card,
  .org-print-shell .org-node,
  .org-print-shell .org-node.org-node-safety {
    color: #16212f;
  }
  .org-print-shell .org-team-head,
  .org-print-shell .org-node-name,
  .org-print-shell .org-member-name {
    color: #16212f !important;
  }
  .org-print-shell .org-role-lbl.role-manager {
    color: #9a6200 !important;
  }
  .org-print-shell .org-role-lbl.role-leader {
    color: #205db5 !important;
  }
  .org-print-shell .org-role-lbl.role-worker {
    color: #23764b !important;
  }
  @media print {
    body {
      padding: 0;
      background: #fff;
    }
    .org-print-actions {
      display: none !important;
    }
  }
</style>`);

        doc.documentElement.style.background = '#ffffff';
        doc.documentElement.style.color = '#16212f';
        doc.body.style.margin = '0';
        doc.body.style.padding = '24px';
        doc.body.style.background = '#ffffff';
        doc.body.style.color = '#16212f';

        doc.body.innerHTML = `
  <div class="org-print-shell" style="max-width:1280px;margin:0 auto;background:#ffffff;color:#16212f;">
    <div class="org-print-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;color:#16212f;background:#ffffff;border-bottom:1px solid #d8e1ea;padding-bottom:12px;font-family:'Malgun Gothic',sans-serif;">
      <div>
        <h1 style="margin:0;font-size:24px;color:#16212f;"></h1>
      </div>
      <div class="org-print-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn-secondary" data-print-action="print" style="background:#ffffff;color:#1d2b45;border:1px solid #c9d2e2;box-shadow:none;">인쇄</button>
        <button type="button" class="btn-secondary" data-print-action="close" style="background:#ffffff;color:#1d2b45;border:1px solid #c9d2e2;box-shadow:none;">닫기</button>
      </div>
    </div>
    <div class="org-print-body" style="background:#ffffff;color:#16212f;"></div>
  </div>`;

        const titleNode = doc.querySelector('.org-print-head h1');
        const bodyNode = doc.querySelector('.org-print-body');
        if (titleNode) {
          titleNode.textContent = orgTitle.textContent || '조직도';
        }
        if (bodyNode) {
          bodyNode.innerHTML = orgBody.innerHTML;
        }

        const printButton = doc.querySelector('[data-print-action="print"]');
        const closeButton = doc.querySelector('[data-print-action="close"]');
        if (printButton) {
          printButton.addEventListener('click', () => previewWindow.print());
        }
        if (closeButton) {
          closeButton.addEventListener('click', () => previewWindow.close());
        }
      }

      document.querySelectorAll('.org-has-phone').forEach(function(el) {
        el.addEventListener('click', function(e) {
          showPhoneTip(el.dataset.name, el.dataset.phone, e.clientX, e.clientY);
          e.stopPropagation();
        });
      });
      document.addEventListener('click', hidePhoneTip);
      orgPrint.addEventListener('click', openOrgPrintPreview);
    })();
  </script>
</body>
</html>
