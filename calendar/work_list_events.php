<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/../risk_assessment/auth.php';

function json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_valid_date(?string $value): bool
{
    if (!is_string($value)) {
        return false;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

function resolve_report_team_name(array $row): string
{
    $reportTeam = auth_normalize_team_name((string)($row['team_name'] ?? ''));
    if ($reportTeam !== '') {
        return $reportTeam;
    }

    $ownerLoginId = trim((string)($row['user_login_id'] ?? ''));
    if ($ownerLoginId === '') {
        return '';
    }

    $ownerAccount = auth_find_user($ownerLoginId);
    return auth_normalize_team_name((string)($ownerAccount['team'] ?? ''));
}

function build_memo(string $workPlace, string $teamName, string $userName): string
{
    $parts = [];

    if ($workPlace !== '') {
        $parts[] = '장소: ' . $workPlace;
    }
    if ($teamName !== '') {
        $parts[] = '팀: ' . $teamName;
    }
    if ($userName !== '') {
        $parts[] = '작성자: ' . $userName;
    }

    return implode(' / ', $parts);
}

function can_view_all_calendar_task_teams(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return auth_is_admin($user) || in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator'], true);
}

function visible_team_keys_for_calendar(?array $user): array
{
    if (!is_array($user) || can_view_all_calendar_task_teams($user)) {
        return [];
    }

    $teamName = auth_normalize_team_name((string)($user['team'] ?? ''));
    if ($teamName === '') {
        return [];
    }

    $visibleTeams = [$teamName];
    if (auth_can_manage($user)) {
        $visibleTeams = array_merge($visibleTeams, auth_supervised_teams($teamName));
    }

    return array_fill_keys(array_map('auth_team_key', auth_unique_team_list($visibleTeams)), true);
}

function should_include_report_for_user(array $row, ?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    $reportTeam = resolve_report_team_name($row);
    $userLoginId = trim((string)($user['login_id'] ?? ''));
    $visibleTeamKeys = visible_team_keys_for_calendar($user);

    if (can_view_all_calendar_task_teams($user)) {
        return true;
    }

    if (!empty($visibleTeamKeys)) {
        return $reportTeam !== '' && isset($visibleTeamKeys[auth_team_key($reportTeam)]);
    }

    return $userLoginId !== '' && (string)($row['user_login_id'] ?? '') === $userLoginId;
}

function can_manual_sync_for_user(?array $user): bool
{
    return is_array($user) && auth_can_manage($user);
}

try {
    $user = auth_current_user();
    if ($user === null) {
        json_response(401, [
            'success' => false,
            'message' => '로그인이 필요합니다.',
            'userRole' => '',
            'canManualSync' => false,
            'teamFilter' => '',
            'events' => [],
        ]);
    }

    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';

    $sql = "
        SELECT
            wr.report_id,
            wr.user_login_id,
            wr.work_title,
            wr.work_date,
            wr.work_place,
            wr.team_name,
            wr.user_name
        FROM work_report wr
        WHERE wr.work_date IS NOT NULL
          AND wr.work_date <> ''
    ";
    $params = [];

    if ($start !== '') {
        if (!is_valid_date($start)) {
            json_response(400, [
                'success' => false,
                'message' => 'Invalid start date format.',
            ]);
        }
        $sql .= ' AND wr.work_date >= :start_date';
        $params[':start_date'] = $start;
    }

    if ($end !== '') {
        if (!is_valid_date($end)) {
            json_response(400, [
                'success' => false,
                'message' => 'Invalid end date format.',
            ]);
        }
        $sql .= ' AND wr.work_date <= :end_date';
        $params[':end_date'] = $end;
    }

    $sql .= ' ORDER BY wr.work_date ASC, wr.report_id ASC';

    $pdo = getDB();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!should_include_report_for_user($row, $user)) {
            continue;
        }

        $reportId = (int)($row['report_id'] ?? 0);
        $workDate = trim((string)($row['work_date'] ?? ''));
        $workTitle = trim((string)($row['work_title'] ?? ''));
        $workPlace = trim((string)($row['work_place'] ?? ''));
        $teamName = resolve_report_team_name($row);
        $userName = trim((string)($row['user_name'] ?? ''));

        if ($workDate === '' || $workTitle === '') {
            continue;
        }

        $events[] = [
            'id' => 'task-' . ($reportId > 0 ? (string)$reportId : md5($workDate . '|' . $workTitle . '|' . $workPlace)),
            'source' => 'task',
            'reportId' => $reportId,
            'title' => $workTitle,
            'date' => $workDate,
            'startTime' => '',
            'endTime' => '',
            'category' => 'work',
            'memo' => build_memo($workPlace, $teamName, $userName),
            'place' => $workPlace,
            'teamName' => $teamName,
            'userName' => $userName,
        ];
    }

    json_response(200, [
        'success' => true,
        'userRole' => (string)($user['role'] ?? ''),
        'canManualSync' => can_manual_sync_for_user($user),
        'teamFilter' => auth_normalize_team_name((string)($user['team'] ?? '')),
        'count' => count($events),
        'events' => $events,
    ]);
} catch (Throwable $e) {
    json_response(500, [
        'success' => false,
        'message' => 'Failed to load work list events.',
        'userRole' => '',
        'canManualSync' => false,
        'teamFilter' => '',
        'events' => [],
    ]);
}
