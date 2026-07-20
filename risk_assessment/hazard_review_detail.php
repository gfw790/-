<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensure_table_column_exists(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
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

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec(sprintf(
        'ALTER TABLE `%s` ADD COLUMN `%s` %s',
        str_replace('`', '``', $tableName),
        str_replace('`', '``', $columnName),
        $definition
    ));
}

function get_board_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=board;charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

function delete_board_post_by_id(PDO $boardPdo, int $postId): void
{
    if ($postId <= 0) {
        return;
    }

    $stmt = $boardPdo->prepare("SELECT stored_name FROM attachments WHERE post_id = ?");
    $stmt->execute([$postId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
        $storedName = trim((string)$storedName);
        if ($storedName === '') {
            continue;
        }
        @unlink(__DIR__ . '/../board/uploads/' . $storedName);
    }

    $boardPdo->beginTransaction();
    try {
        $boardPdo->prepare("DELETE FROM attachments WHERE post_id = ?")->execute([$postId]);
        $boardPdo->prepare("DELETE FROM comments WHERE post_id = ?")->execute([$postId]);
        $boardPdo->prepare("DELETE FROM likes WHERE post_id = ?")->execute([$postId]);
        $boardPdo->prepare("DELETE pv FROM poll_votes pv JOIN polls p ON pv.poll_id = p.id WHERE p.post_id = ?")->execute([$postId]);
        $boardPdo->prepare("DELETE po FROM poll_options po JOIN polls p ON po.poll_id = p.id WHERE p.post_id = ?")->execute([$postId]);
        $boardPdo->prepare("DELETE FROM polls WHERE post_id = ?")->execute([$postId]);
        $boardPdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
        $boardPdo->commit();
    } catch (Throwable $e) {
        if ($boardPdo->inTransaction()) {
            $boardPdo->rollBack();
        }
        throw $e;
    }
}

function hazard_change_request_delete_allowed(array $viewer, array $requestRow): bool
{
    $viewerLoginId = trim((string)($viewer['login_id'] ?? ''));
    $requestLoginId = trim((string)($requestRow['user_login_id'] ?? ''));
    if ($viewerLoginId !== '' && $viewerLoginId === $requestLoginId) {
        return true;
    }

    return in_array((string)($viewer['role'] ?? ''), ['admin', 'manager', 'safety_manager'], true);
}

function ensureWorkerHazardSelectionTable(PDO $pdo): void
{
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
        CREATE TABLE IF NOT EXISTS work_report_worker_hazard_selection (
            worker_selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (worker_selection_id),
            UNIQUE KEY uk_work_report_worker_hazard_selection (report_id, user_login_id, item_id),
            KEY idx_work_report_worker_hazard_selection_report (report_id),
            KEY idx_work_report_worker_hazard_selection_user (user_login_id),
            KEY idx_work_report_worker_hazard_selection_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_hazard_change_request (
            request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            request_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (request_id),
            UNIQUE KEY uk_work_report_hazard_change_request (report_id, user_login_id),
            KEY idx_work_report_hazard_change_request_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ensure_table_column_exists(
        $pdo,
        'work_report_hazard_change_request',
        'board_post_id',
        'INT NULL AFTER request_text'
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_hazard_addition (
            addition_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            sort_no INT NULL,
            task_code VARCHAR(100) NULL,
            task_name VARCHAR(255) NOT NULL,
            hazard_name TEXT NOT NULL,
            accident_type VARCHAR(255) NULL,
            injury_result VARCHAR(255) NULL,
            cause_text TEXT NULL,
            current_control_text TEXT NULL,
            additional_control_text TEXT NULL,
            likelihood_before TINYINT NULL,
            severity_before TINYINT NULL,
            risk_score_before INT NULL,
            likelihood_current TINYINT NULL,
            severity_current TINYINT NULL,
            risk_score_current INT NULL,
            likelihood_after TINYINT NULL,
            severity_after TINYINT NULL,
            risk_score_after INT NULL,
            improvement_due_date DATE NULL,
            remark TEXT NULL,
            use_yn CHAR(1) NOT NULL DEFAULT 'Y',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (addition_id),
            KEY idx_work_report_hazard_addition_report (report_id),
            KEY idx_work_report_hazard_addition_user (user_login_id),
            KEY idx_work_report_hazard_addition_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function hazard_review_detail_report_team_context(array $report): string
{
    $reportTeam = auth_normalize_team_name((string)($report['team_name'] ?? ''));
    if ($reportTeam !== '') {
        return $reportTeam;
    }

    $ownerAccount = auth_find_user((string)($report['user_login_id'] ?? ''));
    return auth_normalize_team_name((string)($ownerAccount['team'] ?? ''));
}

function hazard_review_detail_user_can_view_report(array $user, array $report): bool
{
  $userRole = (string)($user['role'] ?? '');
  if (auth_is_admin($user) || in_array($userRole, ['safety_manager', 'administrator'], true)) {
        return true;
    }

    $visibleTeams = auth_work_list_visible_teams($user);
    if (!empty($visibleTeams)) {
        $reportTeam = hazard_review_detail_report_team_context($report);
        if ($reportTeam === '') {
            return false;
        }

        $visibleTeamKeys = array_fill_keys(array_map('auth_team_key', $visibleTeams), true);
        return isset($visibleTeamKeys[auth_team_key($reportTeam)]);
    }

    $userLoginId = trim((string)($user['login_id'] ?? ''));
    return $userLoginId !== '' && (string)($report['user_login_id'] ?? '') === $userLoginId;
}

function parse_detail_selection(string $value): array
{
    $parts = explode('|', $value, 3);
    return [
        'type' => $parts[0] ?? '',
        'parent' => $parts[1] ?? '',
        'title' => count($parts) >= 3 ? ($parts[2] ?? '') : ($parts[1] ?? ''),
    ];
}

function resolveSelectedRiskAssessments(PDO $pdo, array $report): array
{
    $selected = [];

    $baseUnitId = (int)($report['unit_ra_id'] ?? 0);
    if ($baseUnitId > 0) {
        $selected[$baseUnitId] = $baseUnitId;
    }

    $selectedUnitStmt = $pdo->prepare("
        SELECT unit_ra_id
        FROM work_report_selected_unit
        WHERE report_id = :report_id
        ORDER BY sort_no ASC, report_selection_id ASC
    ");
    $selectedUnitStmt->execute([':report_id' => (int)$report['report_id']]);
    foreach ($selectedUnitStmt->fetchAll(PDO::FETCH_COLUMN) as $selectedUnitId) {
        $unitId = (int)$selectedUnitId;
        if ($unitId > 0) {
            $selected[$unitId] = $unitId;
        }
    }

    $detailStmt = $pdo->prepare("
        SELECT task_name
        FROM work_report_detail
        WHERE report_id = :report_id
        ORDER BY report_detail_id ASC
    ");
    $detailStmt->execute([':report_id' => (int)$report['report_id']]);

    $lookupStmt = $pdo->prepare("
        SELECT unit_ra_id
        FROM unit_ra_header
        WHERE use_yn = 'Y'
          AND unit_type = :unit_type
          AND unit_title = :unit_title
        ORDER BY sort_no ASC, unit_ra_id DESC
        LIMIT 1
    ");

    foreach ($detailStmt->fetchAll(PDO::FETCH_COLUMN) as $detailValue) {
        $parsed = parse_detail_selection((string)$detailValue);
        $lookupType = '';
        $lookupTitle = '';

        if ($parsed['type'] === 'major_work' && $parsed['title'] !== '') {
            $lookupType = 'major_work';
            $lookupTitle = $parsed['title'];
        } elseif ($parsed['type'] === 'major_work_sub' && $parsed['parent'] !== '' && $parsed['title'] !== '') {
            $lookupType = 'major_work';
            $lookupTitle = $parsed['parent'] . ' - ' . $parsed['title'];
        } elseif (in_array($parsed['type'], ['env', 'tool'], true) && $parsed['title'] !== '') {
            $lookupType = $parsed['type'];
            $lookupTitle = $parsed['title'];
        }

        if ($lookupType === '' || $lookupTitle === '') {
            continue;
        }

        $lookupStmt->execute([
            ':unit_type' => $lookupType,
            ':unit_title' => $lookupTitle,
        ]);
        $unitId = (int)$lookupStmt->fetchColumn();
        if ($unitId > 0) {
            $selected[$unitId] = $unitId;
        }
    }

    if (empty($selected)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $stmt = $pdo->prepare("
        SELECT unit_ra_id, unit_code, unit_title, unit_type, process_name
        FROM unit_ra_header
        WHERE unit_ra_id IN ($placeholders)
        ORDER BY sort_no ASC, unit_ra_id ASC
    ");
    $stmt->execute(array_values($selected));

    return $stmt->fetchAll() ?: [];
}

function is_other_recommended_unit_type(string $unitType): bool
{
    return in_array($unitType, ['major_work', 'tool', 'env'], true);
}

function empty_recommended_hazard_groups(): array
{
    return [
        'work_related' => [],
        'other' => [],
    ];
}

function merge_recommended_hazard_rows(array $primaryRows, array $fallbackRows, int $limit): array
{
    $merged = [];
    $seen = [];

    foreach (array_merge($primaryRows, $fallbackRows) as $row) {
        $key = implode('|', [
            trim((string)($row['unit_title'] ?? '')),
            trim((string)($row['unit_code'] ?? '')),
            trim((string)($row['hazard_name'] ?? '')),
        ]);
        if ($key === '||' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[] = $row;
        if (count($merged) >= $limit) {
            break;
        }
    }

    return $merged;
}

function fetch_recommended_hazards_by_group(PDO $pdo, array $report, bool $includeOther, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $typeCondition = $includeOther
        ? "AND h.unit_type IN ('major_work', 'tool', 'env')"
        : "AND (h.unit_type IS NULL OR h.unit_type NOT IN ('major_work', 'tool', 'env'))";

    $stmt = $pdo->prepare("
        SELECT
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.additional_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_title,
            h.unit_code,
            h.unit_type,
            COUNT(*) AS selected_count,
            COUNT(DISTINCT s.user_login_id) AS worker_count
        FROM work_report_worker_hazard_selection s
        INNER JOIN work_report wr
            ON wr.report_id = s.report_id
        INNER JOIN unit_ra_item i
            ON i.item_id = s.item_id
        INNER JOIN unit_ra_header h
            ON h.unit_ra_id = s.unit_ra_id
        WHERE wr.work_title = :work_title
          AND wr.work_date = :work_date
          AND wr.work_place = :work_place
          {$typeCondition}
        GROUP BY
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.additional_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_title,
            h.unit_code,
            h.unit_type
        ORDER BY selected_count DESC, worker_count DESC, i.hazard_name ASC
        LIMIT {$limit}
    ");
    $stmt->execute([
        ':work_title' => (string)($report['work_title'] ?? ''),
        ':work_date' => (string)($report['work_date'] ?? ''),
        ':work_place' => (string)($report['work_place'] ?? ''),
    ]);

    return $stmt->fetchAll() ?: [];
}

function fetch_fallback_hazards_by_group(PDO $pdo, array $riskAssessments, bool $includeOther, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $filteredAssessments = array_values(array_filter(
        $riskAssessments,
        static function (array $assessment) use ($includeOther): bool {
            $isOther = is_other_recommended_unit_type((string)($assessment['unit_type'] ?? ''));
            return $includeOther ? $isOther : !$isOther;
        }
    ));

    if (empty($filteredAssessments)) {
        return [];
    }

    $unitOrderMap = [];
    $unitIds = [];
    foreach ($filteredAssessments as $index => $assessment) {
        $unitId = (int)($assessment['unit_ra_id'] ?? 0);
        if ($unitId <= 0) {
            continue;
        }
        $unitOrderMap[$unitId] = $index;
        $unitIds[] = $unitId;
    }

    if (empty($unitIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.additional_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_ra_id,
            h.unit_title,
            h.unit_code,
            h.unit_type,
            i.sort_no AS item_sort_no,
            i.item_id,
            0 AS selected_count,
            0 AS worker_count
        FROM unit_ra_item i
        INNER JOIN unit_ra_header h
            ON h.unit_ra_id = i.unit_ra_id
        WHERE i.use_yn = 'Y'
          AND i.unit_ra_id IN ($placeholders)
    ");
    $stmt->execute($unitIds);
    $rows = $stmt->fetchAll() ?: [];

    usort($rows, static function (array $left, array $right) use ($unitOrderMap): int {
        $leftOrder = $unitOrderMap[(int)($left['unit_ra_id'] ?? 0)] ?? 999;
        $rightOrder = $unitOrderMap[(int)($right['unit_ra_id'] ?? 0)] ?? 999;
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }

        $leftSort = (int)($left['item_sort_no'] ?? 0);
        $rightSort = (int)($right['item_sort_no'] ?? 0);
        if ($leftSort !== $rightSort) {
            return $leftSort <=> $rightSort;
        }

        return ((int)($left['item_id'] ?? 0)) <=> ((int)($right['item_id'] ?? 0));
    });

    return array_slice($rows, 0, $limit);
}

function build_recommended_hazard_groups(PDO $pdo, array $report, array $riskAssessments): array
{
    $groups = empty_recommended_hazard_groups();
    $workLimit = 5;
    $otherLimit = 5;

    $recommendedWorkRows = fetch_recommended_hazards_by_group($pdo, $report, false, $workLimit);
    $recommendedOtherRows = fetch_recommended_hazards_by_group($pdo, $report, true, $otherLimit);

    $fallbackWorkRows = count($recommendedWorkRows) < $workLimit
        ? fetch_fallback_hazards_by_group($pdo, $riskAssessments, false, $workLimit)
        : [];
    $fallbackOtherRows = count($recommendedOtherRows) < $otherLimit
        ? fetch_fallback_hazards_by_group($pdo, $riskAssessments, true, $otherLimit)
        : [];

    $groups['work_related'] = merge_recommended_hazard_rows($recommendedWorkRows, $fallbackWorkRows, $workLimit);
    $groups['other'] = merge_recommended_hazard_rows($recommendedOtherRows, $fallbackOtherRows, $otherLimit);

    return $groups;
}

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$pdo = getDB();
ensureWorkerHazardSelectionTable($pdo);
$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$openUnitRaId = isset($_GET['open_unit_ra_id']) ? (int)$_GET['open_unit_ra_id'] : 0;
$submitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$deletedRequest = isset($_GET['deleted_request']) && $_GET['deleted_request'] === '1';
$error = '';
$message = $deletedRequest ? '수정요청이 삭제되었습니다.' : '';
$report = null;
$riskAssessments = [];
$recommendedHazardGroups = empty_recommended_hazard_groups();
$recommendedHazards = [];
$openedAssessment = null;
$openedTaskSteps = [];
$openedSelectedHazards = [];
$managerTaskHeader = '';
$changeRequests = [];
$additionalRequestItems = [];

if ($reportId > 0) {
    $stmt = $pdo->prepare("
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
            h.unit_code,
            h.unit_title
        FROM work_report wr
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = wr.unit_ra_id
        WHERE wr.report_id = :report_id
        LIMIT 1
    ");
    $stmt->execute([':report_id' => $reportId]);
    $report = $stmt->fetch();

    if ($report && !hazard_review_detail_user_can_view_report($user, $report)) {
        $report = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $report && (string)($_POST['action'] ?? '') === 'delete_change_request') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    if ($requestId <= 0) {
        $error = '삭제할 수정요청을 찾지 못했습니다.';
    } else {
        $requestStmt = $pdo->prepare("
            SELECT request_id, report_id, user_login_id, board_post_id
            FROM work_report_hazard_change_request
            WHERE request_id = :request_id
              AND report_id = :report_id
            LIMIT 1
        ");
        $requestStmt->execute([
            ':request_id' => $requestId,
            ':report_id' => $reportId,
        ]);
        $requestRow = $requestStmt->fetch();

        if (!$requestRow) {
            $error = '삭제할 수정요청을 찾지 못했습니다.';
        } elseif (!hazard_change_request_delete_allowed($user, $requestRow)) {
            $error = '해당 수정요청을 삭제할 권한이 없습니다.';
        } else {
            try {
                $boardPostId = (int)($requestRow['board_post_id'] ?? 0);
                if ($boardPostId > 0) {
                    delete_board_post_by_id(get_board_db(), $boardPostId);
                }

                $pdo->beginTransaction();
                $pdo->prepare("
                    DELETE FROM work_report_hazard_change_request
                    WHERE request_id = :request_id
                ")->execute([
                    ':request_id' => $requestId,
                ]);
                $pdo->commit();

                $redirectUrl = 'hazard_review_detail.php?report_id=' . $reportId;
                if ($openUnitRaId > 0) {
                    $redirectUrl .= '&open_unit_ra_id=' . $openUnitRaId;
                }
                if ($submitted) {
                    $redirectUrl .= '&submitted=1';
                }
                $redirectUrl .= '&deleted_request=1';
                header('Location: ' . $redirectUrl);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = '수정요청 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
            }
        }
    }
}

if (!$report) {
    http_response_code(404);
    $error = '작업 정보를 찾을 수 없습니다.';
} else {
    $riskAssessments = resolveSelectedRiskAssessments($pdo, $report);

    if ($openUnitRaId <= 0) {
        $openUnitRaId = (int)($report['unit_ra_id'] ?? 0);
    }

    foreach ($riskAssessments as $assessment) {
        if ((int)$assessment['unit_ra_id'] === $openUnitRaId) {
            $openedAssessment = $assessment;
            break;
        }
    }
    if (!$openedAssessment && !empty($riskAssessments)) {
        $openedAssessment = $riskAssessments[0];
        $openUnitRaId = (int)$openedAssessment['unit_ra_id'];
    }

    $recommendedHazardGroups = build_recommended_hazard_groups($pdo, $report, $riskAssessments);
    $recommendedHazards = array_merge(
        $recommendedHazardGroups['work_related'],
        $recommendedHazardGroups['other']
    );

    if ($openedAssessment) {
        $stepStmt = $pdo->prepare("
            SELECT task_name
            FROM unit_ra_item
            WHERE unit_ra_id = :unit_ra_id
              AND use_yn = 'Y'
            ORDER BY sort_no ASC, item_id ASC
        ");
        $stepStmt->execute([':unit_ra_id' => $openUnitRaId]);
        $seen = [];
        foreach ($stepStmt->fetchAll(PDO::FETCH_COLUMN) as $taskName) {
            $taskName = trim((string)$taskName);
            if ($taskName === '' || $taskName === '공통' || isset($seen[$taskName])) {
                continue;
            }
            $seen[$taskName] = true;
            $openedTaskSteps[] = $taskName;
            if (count($openedTaskSteps) >= 5) {
                break;
            }
        }
        if (!empty($openedTaskSteps)) {
            $managerTaskHeader = implode(' > ', array_slice($openedTaskSteps, 0, 3));
        }

        $hazardStmt = $pdo->prepare("
            SELECT
                i.hazard_name,
                COUNT(*) AS selected_count,
                COUNT(DISTINCT s.user_login_id) AS worker_count
            FROM work_report_worker_hazard_selection s
            INNER JOIN work_report wr
                ON wr.report_id = s.report_id
            INNER JOIN unit_ra_item i
                ON i.item_id = s.item_id
            WHERE s.unit_ra_id = :unit_ra_id
              AND wr.work_title = :work_title
              AND wr.work_date = :work_date
              AND wr.work_place = :work_place
            GROUP BY i.hazard_name
            ORDER BY selected_count DESC, worker_count DESC, i.hazard_name ASC
            LIMIT 10
        ");
        $hazardStmt->execute([
            ':unit_ra_id' => $openUnitRaId,
            ':work_title' => (string)$report['work_title'],
            ':work_date' => (string)$report['work_date'],
            ':work_place' => (string)$report['work_place'],
        ]);
        $openedSelectedHazards = $hazardStmt->fetchAll() ?: [];
    }

    $changeRequestStmt = $pdo->prepare("
        SELECT user_login_id, user_name, request_text, updated_at, board_post_id, request_id
        FROM work_report_hazard_change_request
        WHERE report_id = :report_id
        ORDER BY updated_at DESC, request_id DESC
    ");
    $changeRequestStmt->execute([':report_id' => $reportId]);
    $changeRequests = $changeRequestStmt->fetchAll() ?: [];

    $additionStmt = $pdo->prepare("
        SELECT
            a.user_login_id,
            a.user_name,
            a.unit_ra_id,
            a.sort_no,
            a.task_code,
            a.task_name,
            a.hazard_name,
            a.accident_type,
            a.injury_result,
            a.cause_text,
            a.current_control_text,
            a.additional_control_text,
            a.likelihood_before,
            a.severity_before,
            a.risk_score_before,
            a.likelihood_current,
            a.severity_current,
            a.risk_score_current,
            a.likelihood_after,
            a.severity_after,
            a.risk_score_after,
            a.improvement_due_date,
            a.remark,
            a.use_yn,
            a.updated_at,
            h.unit_title,
            h.unit_code
        FROM work_report_hazard_addition a
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = a.unit_ra_id
        WHERE a.report_id = :report_id
        ORDER BY a.unit_ra_id ASC, a.sort_no ASC, a.addition_id ASC
    ");
    $additionStmt->execute([':report_id' => $reportId]);
    $additionalRequestItems = $additionStmt->fetchAll() ?: [];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>수시위험성평가 상세</title>
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
  .shell { width: 100%; max-width: none; margin: 0 auto; }
  .topbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
  }
  .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .identity { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .topbar-label {
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 4px;
  }
  .topbar-title { font-size: 22px; font-weight: 900; color: var(--text-hi); line-height: 1.2; }
  .topbar-title span { color: var(--accent2); }
  .role-badge {
    display: inline-flex;
    padding: 5px 11px;
    border-radius: 999px;
    background: rgba(232,146,10,0.15);
    color: var(--accent2);
    font-size: 12px;
    font-weight: 700;
    border: 1px solid rgba(232,146,10,0.35);
  }
  .panel {
    background: var(--bg2) !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    box-shadow: none !important;
    overflow: hidden;
  }
  .panel-head {
    padding: 24px 28px 16px;
    border-bottom: 1px solid var(--border) !important;
    background: var(--bg2) !important;
  }
  .panel-head-label { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .panel-head h1 { font-size: 26px; font-weight: 900; color: var(--text-hi); margin-bottom: 6px; }
  .panel-head h1 span { color: var(--accent2); }
  .panel-head p, .subtext { color: var(--text-dim); font-size: 13px; line-height: 1.6; }
  .content { padding: 22px 28px 28px; display: grid; gap: 20px; }
  .section-title { font-size: 16px; font-weight: 800; color: var(--text-hi); margin-bottom: 12px; letter-spacing: .04em; }
  .grid { display: grid; gap: 10px; }
  .card {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
  }
  .card > strong { display: block; font-size: 11px; color: var(--text-dim); margin-bottom: 10px; letter-spacing: .06em; text-transform: uppercase; }
  .hazard-title { font-size: 15px; font-weight: 700; color: var(--text-hi); margin-bottom: 8px; }
  .hazard-summary { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0 10px; }
  .summary-chip {
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
  .hazard-meta {
    display: grid;
    grid-template-columns: repeat(2, minmax(200px, 1fr));
    gap: 8px;
    margin-top: 8px;
  }
  .hazard-meta-row {
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.5;
    background: rgba(255,255,255,0.07);
    border: 1px solid var(--border2);
    border-radius: 10px;
    padding: 10px 12px;
  }
  .hazard-meta-row strong { display: block; color: #8aadcc; font-size: 11px; font-weight: 700; margin-bottom: 5px; letter-spacing: .04em; }
  .request-list { display: grid; gap: 10px; }
  .request-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 8px;
  }
  .request-card-title { font-size: 15px; font-weight: 700; color: var(--text-hi); margin-bottom: 8px; }
  .request-text {
    color: var(--text-hi);
    font-size: 14px;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .btn-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 9px;
    cursor: pointer;
    padding: 9px 14px;
    font-size: 12px;
    font-weight: 700;
    font-family: inherit;
    background: rgba(180, 60, 60, 0.16);
    color: #ffb4b4;
    border: 1px solid rgba(200, 90, 90, 0.35);
  }
  .btn-danger:hover { background: rgba(180, 60, 60, 0.24); }
  .btn-secondary {
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none; border-radius: 9px; cursor: pointer;
    padding: 11px 18px; font-size: 13px; font-weight: 600; font-family: inherit;
    background: rgba(255,255,255,0.05); color: var(--text); border: 1px solid var(--border2);
  }
  .btn-secondary:hover { background: rgba(255,255,255,0.09); }
  .message, .error, .empty { border-radius: 10px; padding: 12px 16px; font-size: 13px; }
  .message { background: rgba(30,90,50,.35); border: 1px solid rgba(60,180,90,.3); color: #6de09a; }
  .error   { background: rgba(90,20,20,.35); border: 1px solid rgba(200,60,60,.3); color: #f09090; }
  .empty   { border: 1px dashed var(--border2); text-align: center; color: var(--text-dim); }
  .step-flow { display: flex; gap: 10px; align-items: stretch; flex-wrap: wrap; }
  .step-flow-item {
    min-width: 130px;
    max-width: 170px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border2);
    border-radius: 12px;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    flex-shrink: 0;
  }
  .step-flow-item:not(:last-child)::after {
    content: '›';
    position: absolute;
    right: -9px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    font-weight: 700;
    font-size: 20px;
    background: var(--bg3);
    padding: 0 2px;
  }
  .step-number { font-size: 10px; color: var(--accent); font-weight: 700; margin-bottom: 4px; letter-spacing: .06em; }
  .step-text   { font-size: 13px; color: var(--text-hi); font-weight: 700; line-height: 1.5; word-break: keep-all; }
  .hazard-list { display: grid; gap: 10px; }
  .hazard-group { display: grid; gap: 10px; }
  .hazard-group + .hazard-group { margin-top: 18px; }
  .hazard-group-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--accent2);
    margin-bottom: 2px;
  }
  .list-row, .rank-row { display: grid; grid-template-columns: 52px 1fr; gap: 14px; align-items: start; }
  .list-badge, .rank-badge {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700;
  }
  .list-badge { background: rgba(58,127,193,0.2); color: var(--blue); }
  .rank-badge { background: var(--accent); color: #fff; font-size: 15px; }
  @media (max-width: 720px) {
    .panel-head, .content { padding-left: 18px; padding-right: 18px; }
    .list-row, .rank-row { grid-template-columns: 1fr; }
    .card { padding: 12px; }
    .hazard-title { font-size: 14px; }
    .request-card-head { flex-direction: column; align-items: stretch; }
    .step-flow-item { min-width: 120px; max-width: none; flex: 1 1 130px; }
    .hazard-meta { grid-template-columns: 1fr; gap: 6px; }
    .hazard-meta-row { padding: 8px 10px; font-size: 12px; }
    .hazard-meta-row strong { display: inline; margin-bottom: 0; margin-right: 6px; }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div>
        <div class="topbar-label">RISK ASSESSMENT · DETAIL</div>
        <div class="topbar-title">수시위험성평가 <span>세부내용</span></div>
      </div>
      <div class="identity">
        <span class="role-badge"><?= h($user['role_label']) ?></span>
        <span style="color:var(--text-dim);font-size:13px"><?= h(auth_display_name($user)) ?></span>
        <div class="actions">
          <a class="btn-secondary" href="hazard_review.php?report_id=<?= (int)$reportId ?><?= $submitted ? '&submitted=1' : '' ?>">목록으로</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-label">HAZARD DETAIL</div>
        <h1>주요 <span>위험요소</span></h1>
        <?php if ($report): ?>
          <p><?= h($report['work_date']) ?> &nbsp;/&nbsp; <?= h($report['work_title']) ?></p>
        <?php endif; ?>
      </div>

      <div class="content">
        <?php if ($message !== ''): ?>
          <div class="message"><?= h($message) ?></div>
        <?php endif; ?>
        <?php if ($submitted): ?>
          <div class="message">수시위험성평가 제출이 완료되었습니다. 금일 수시위험성평가서를 열람해주세요.</div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="error"><?= h($error) ?></div>
        <?php elseif ($report): ?>
          <?php if ($openedAssessment): ?>
          <section>
            <div class="section-title"><?= h($report['unit_title'] ?: '작업유형') ?></div>
            <div class="grid">
              <div class="card">
                <strong>작업순서</strong>
                <?php if (empty($openedTaskSteps)): ?>
                  <div class="empty">표시할 작업순서가 없습니다.</div>
                <?php else: ?>
                  <div class="step-flow">
                    <?php foreach ($openedTaskSteps as $index => $step): ?>
                      <div class="step-flow-item">
                        <div class="step-number">STEP <?= (int)($index + 1) ?></div>
                        <div class="step-text"><?= h($step) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

            </div>
          </section>
          <?php endif; ?>

          <section>
            <div class="section-title">금일 주요 위험요소</div>
            <?php if (empty($recommendedHazards)): ?>
              <div class="empty">아직 집계된 작업자 추천목록이 없습니다.</div>
            <?php else: ?>
              <?php
              $hazardSections = [
                  'work_related' => '작업관련 5가지',
                  'other' => '그 외 5가지 (중대재해, 위험공구, 환경)',
              ];
              ?>
              <div class="grid">
                <?php foreach ($hazardSections as $sectionKey => $sectionTitle): ?>
                  <div class="hazard-group">
                    <div class="hazard-group-title"><?= h($sectionTitle) ?></div>
                    <?php if (empty($recommendedHazardGroups[$sectionKey])): ?>
                      <div class="empty">표시할 위험요소가 없습니다.</div>
                    <?php else: ?>
                      <div class="hazard-list">
                        <?php foreach ($recommendedHazardGroups[$sectionKey] as $index => $hazard): ?>
                          <div class="card rank-row">
                            <div class="rank-badge"><?= (int)($index + 1) ?></div>
                            <div>
                              <div class="hazard-title"><?= h($hazard['hazard_name']) ?></div>
                              <div class="hazard-summary">
                                <span class="summary-chip">수시위험성평가 <?= h($hazard['unit_title']) ?><?php if (!empty($hazard['unit_code'])): ?> (<?= h($hazard['unit_code']) ?>)<?php endif; ?></span>
                                <span class="summary-chip">추천 <?= h($hazard['selected_count']) ?>회</span>
                                <span class="summary-chip">참여 <?= h($hazard['worker_count']) ?>명</span>
                              </div>
                              <div class="hazard-meta">
                                <?php if (!empty($hazard['accident_type'])): ?>
                                  <div class="hazard-meta-row"><strong>재해형태</strong><?= h($hazard['accident_type']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($hazard['injury_result'])): ?>
                                  <div class="hazard-meta-row"><strong>상해결과</strong><?= h($hazard['injury_result']) ?></div>
                                <?php endif; ?>
                                <?php if (
                                  $hazard['likelihood_before'] !== null ||
                                  $hazard['severity_before'] !== null ||
                                  $hazard['risk_score_before'] !== null
                                ): ?>
                                  <div class="hazard-meta-row">
                                    <strong>현재 위험도</strong>
                                    빈도 <?= h($hazard['likelihood_before'] ?? '-') ?>
                                    / 강도 <?= h($hazard['severity_before'] ?? '-') ?>
                                    / 위험도 <?= h($hazard['risk_score_before'] ?? '-') ?>
                                  </div>
                                <?php endif; ?>
                                <?php if (!empty($hazard['current_control_text'])): ?>
                                  <div class="hazard-meta-row"><strong>현재조치사항</strong><?= h($hazard['current_control_text']) ?></div>
                                <?php endif; ?>
                                <?php if (
                                  $hazard['likelihood_current'] !== null ||
                                  $hazard['severity_current'] !== null ||
                                  $hazard['risk_score_current'] !== null
                                ): ?>
                                  <div class="hazard-meta-row">
                                    <strong>조치후위험도</strong>
                                    빈도 <?= h($hazard['likelihood_current'] ?? '-') ?>
                                    / 강도 <?= h($hazard['severity_current'] ?? '-') ?>
                                    / 위험도 <?= h($hazard['risk_score_current'] ?? '-') ?>
                                  </div>
                                <?php else: ?>
                                  <div class="hazard-meta-row">
                                    <strong>조치후위험도</strong>
                                    빈도 - / 강도 - / 위험도 -
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section>
            <div class="section-title">위험성평가 수정요청</div>
            <?php if (empty($changeRequests)): ?>
              <div class="empty">등록된 수정요청이 없습니다.</div>
            <?php else: ?>
              <div class="request-list">
                <?php foreach ($changeRequests as $request): ?>
                  <div class="card">
                    <div class="request-card-head">
                      <div class="hazard-summary">
                        <span class="summary-chip">작성자 <?= h($request['user_name'] ?: $request['user_login_id']) ?></span>
                        <span class="summary-chip">계정 <?= h($request['user_login_id']) ?></span>
                        <span class="summary-chip">수정일 <?= h($request['updated_at']) ?></span>
                      </div>
                      <?php if (hazard_change_request_delete_allowed($user, $request)): ?>
                        <form method="post" onsubmit="return confirm('이 수정요청을 삭제하시겠습니까? 게시판 글도 함께 삭제됩니다.');">
                          <input type="hidden" name="action" value="delete_change_request">
                          <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                          <button type="submit" class="btn-danger">삭제</button>
                        </form>
                      <?php endif; ?>
                    </div>
                    <div class="request-text"><?= nl2br(h($request['request_text'])) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section>
            <div class="section-title">추가 위험성평가 요청</div>
            <?php if (empty($additionalRequestItems)): ?>
              <div class="empty">등록된 추가 위험성평가 요청이 없습니다.</div>
            <?php else: ?>
              <div class="request-list">
                <?php foreach ($additionalRequestItems as $item): ?>
                  <div class="card">
                    <div class="request-card-title"><?= h($item['hazard_name']) ?></div>
                    <div class="hazard-summary">
                      <span class="summary-chip">작성자 <?= h($item['user_name'] ?: $item['user_login_id']) ?></span>
                      <span class="summary-chip">계정 <?= h($item['user_login_id']) ?></span>
                      <span class="summary-chip">수시위험성평가 <?= h($item['unit_title'] ?: '미지정') ?><?php if (!empty($item['unit_code'])): ?> (<?= h($item['unit_code']) ?>)<?php endif; ?></span>
                      <span class="summary-chip">작업명 <?= h($item['task_name']) ?></span>
                      <?php if (!empty($item['task_code'])): ?>
                        <span class="summary-chip">코드 <?= h($item['task_code']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($item['sort_no'])): ?>
                        <span class="summary-chip">정렬 <?= h($item['sort_no']) ?></span>
                      <?php endif; ?>
                      <span class="summary-chip">사용 <?= h($item['use_yn']) ?></span>
                    </div>
                    <div class="hazard-meta">
                      <?php if (!empty($item['accident_type'])): ?>
                        <div class="hazard-meta-row"><strong>사고유형</strong><?= h($item['accident_type']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($item['injury_result'])): ?>
                        <div class="hazard-meta-row"><strong>상해결과</strong><?= h($item['injury_result']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($item['cause_text'])): ?>
                        <div class="hazard-meta-row"><strong>원인</strong><?= h($item['cause_text']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($item['current_control_text'])): ?>
                        <div class="hazard-meta-row"><strong>현재 조치사항</strong><?= h($item['current_control_text']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($item['additional_control_text'])): ?>
                        <div class="hazard-meta-row"><strong>추가 조치사항</strong><?= h($item['additional_control_text']) ?></div>
                      <?php endif; ?>
                      <?php if (
                        $item['likelihood_before'] !== null ||
                        $item['severity_before'] !== null ||
                        $item['risk_score_before'] !== null
                      ): ?>
                        <div class="hazard-meta-row">
                          <strong>개선 전 위험도</strong>
                          빈도 <?= h($item['likelihood_before'] ?? '-') ?>
                          / 강도 <?= h($item['severity_before'] ?? '-') ?>
                          / 위험도 <?= h($item['risk_score_before'] ?? '-') ?>
                        </div>
                      <?php endif; ?>
                      <?php if (
                        $item['likelihood_current'] !== null ||
                        $item['severity_current'] !== null ||
                        $item['risk_score_current'] !== null
                      ): ?>
                        <div class="hazard-meta-row">
                          <strong>현재 위험도</strong>
                          빈도 <?= h($item['likelihood_current'] ?? '-') ?>
                          / 강도 <?= h($item['severity_current'] ?? '-') ?>
                          / 위험도 <?= h($item['risk_score_current'] ?? '-') ?>
                        </div>
                      <?php endif; ?>
                      <?php if (
                        $item['likelihood_after'] !== null ||
                        $item['severity_after'] !== null ||
                        $item['risk_score_after'] !== null
                      ): ?>
                        <div class="hazard-meta-row">
                          <strong>개선 후 위험도</strong>
                          빈도 <?= h($item['likelihood_after'] ?? '-') ?>
                          / 강도 <?= h($item['severity_after'] ?? '-') ?>
                          / 위험도 <?= h($item['risk_score_after'] ?? '-') ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($item['improvement_due_date'])): ?>
                        <div class="hazard-meta-row"><strong>개선기한</strong><?= h($item['improvement_due_date']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($item['remark'])): ?>
                        <div class="hazard-meta-row"><strong>비고</strong><?= h($item['remark']) ?></div>
                      <?php endif; ?>
                      <div class="hazard-meta-row"><strong>등록일</strong><?= h($item['updated_at']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
