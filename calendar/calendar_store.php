<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/../risk_assessment/auth.php';

function calendar_store_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_calendar_store_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_custom_category (
            category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_login_id VARCHAR(100) NOT NULL,
            category_key VARCHAR(100) NOT NULL,
            label VARCHAR(24) NOT NULL,
            color CHAR(7) NOT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (category_id),
            UNIQUE KEY uk_calendar_custom_category_owner_key (owner_login_id, category_key),
            KEY idx_calendar_custom_category_owner_sort (owner_login_id, sort_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_manual_event (
            manual_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_login_id VARCHAR(100) NOT NULL,
            event_key VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            start_time CHAR(5) NOT NULL DEFAULT '',
            end_time CHAR(5) NOT NULL DEFAULT '',
            category_key VARCHAR(100) NOT NULL,
            category_label VARCHAR(24) NULL,
            category_color CHAR(7) NULL,
            memo TEXT NULL,
            visibility_scope VARCHAR(20) NOT NULL DEFAULT 'private',
            owner_team_key VARCHAR(120) NOT NULL DEFAULT '',
            shared_team_key VARCHAR(120) NOT NULL DEFAULT '',
            shared_team_name VARCHAR(120) NOT NULL DEFAULT '',
            shared_user_ids TEXT NULL,
            shared_user_names TEXT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (manual_event_id),
            UNIQUE KEY uk_calendar_manual_event_owner_key (owner_login_id, event_key),
            KEY idx_calendar_manual_event_owner_date (owner_login_id, event_date, sort_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'category_label' => "ALTER TABLE calendar_manual_event ADD COLUMN category_label VARCHAR(24) NULL AFTER category_key",
        'category_color' => "ALTER TABLE calendar_manual_event ADD COLUMN category_color CHAR(7) NULL AFTER category_label",
        'visibility_scope' => "ALTER TABLE calendar_manual_event ADD COLUMN visibility_scope VARCHAR(20) NOT NULL DEFAULT 'private' AFTER memo",
        'owner_team_key' => "ALTER TABLE calendar_manual_event ADD COLUMN owner_team_key VARCHAR(120) NOT NULL DEFAULT '' AFTER visibility_scope",
        'shared_team_key' => "ALTER TABLE calendar_manual_event ADD COLUMN shared_team_key VARCHAR(120) NOT NULL DEFAULT '' AFTER owner_team_key",
        'shared_team_name' => "ALTER TABLE calendar_manual_event ADD COLUMN shared_team_name VARCHAR(120) NOT NULL DEFAULT '' AFTER shared_team_key",
        'shared_user_ids' => "ALTER TABLE calendar_manual_event ADD COLUMN shared_user_ids TEXT NULL AFTER shared_team_name",
        'shared_user_names' => "ALTER TABLE calendar_manual_event ADD COLUMN shared_user_names TEXT NULL AFTER shared_user_ids",
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM calendar_manual_event");
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $existing[(string)($column['Field'] ?? '')] = true;
    }

    foreach ($columns as $name => $sql) {
        if (!isset($existing[$name])) {
            $pdo->exec($sql);
        }
    }
}

function is_valid_date_key(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

function is_valid_time_key(string $value): bool
{
    return $value === '' || preg_match('/^\d{2}:\d{2}$/', $value) === 1;
}

function normalize_category_payload(array $categories): array
{
    $result = [];
    $seen = [];

    foreach ($categories as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = trim((string)($item['id'] ?? ''));
        $label = trim((string)($item['label'] ?? ''));
        $color = strtoupper(trim((string)($item['color'] ?? '#2563EB')));

        if ($id === '' || $label === '') {
            continue;
        }

        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;

        if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
            $color = '#2563EB';
        }

        $result[] = [
            'id' => mb_substr($id, 0, 100, 'UTF-8'),
            'label' => mb_substr($label, 0, 24, 'UTF-8'),
            'color' => $color,
            'sort_no' => $index,
        ];
    }

    return $result;
}

function can_share_team_events(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return auth_can_manage($user) || in_array((string)($user['role'] ?? ''), ['leader', 'administrator'], true);
}

function can_share_global_events(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return auth_is_admin($user) || in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator'], true);
}

function can_share_user_events(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return auth_is_admin($user) || in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator'], true);
}

function calendar_share_team_options(?array $user): array
{
    if (!is_array($user)) {
        return [];
    }

    if (auth_is_admin($user) || in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator'], true)) {
        $teams = auth_read_teams();
    } else {
        $teams = auth_work_list_visible_teams($user);
        $ownTeam = auth_normalize_team_name((string)($user['team'] ?? ''));
        if ($ownTeam !== '') {
            array_unshift($teams, $ownTeam);
        }
        $teams = auth_unique_team_list($teams);
    }

    $options = [];
    foreach ($teams as $teamName) {
        $normalized = auth_normalize_team_name((string)$teamName);
        if ($normalized === '') {
            continue;
        }
        $options[] = [
            'key' => auth_team_key($normalized),
            'name' => $normalized,
        ];
    }

    return $options;
}

function calendar_share_user_options(?array $user): array
{
    if (!can_share_user_events($user)) {
        return [];
    }

    $options = [];
    foreach (auth_accounts() as $loginId => $account) {
        $loginId = trim((string)$loginId);
        if ($loginId === '' || !is_array($account)) {
            continue;
        }

        $name = trim((string)($account['name'] ?? ''));
        $role = trim((string)($account['role'] ?? ''));
        $team = auth_normalize_team_name((string)($account['team'] ?? ''));

        $options[] = [
            'loginId' => $loginId,
            'name' => $name !== '' ? $name : $loginId,
            'role' => $role,
            'roleLabel' => auth_role_label($role),
            'team' => $team,
        ];
    }

    usort($options, static function (array $a, array $b): int {
        return strcmp($a['loginId'], $b['loginId']);
    });

    return $options;
}

function can_view_shared_team_event(array $eventRow, ?array $user, array $shareTeams): bool
{
    if (!is_array($user)) {
        return false;
    }

    if (auth_is_admin($user) || in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator'], true)) {
        return true;
    }

    $sharedTeamKey = trim((string)($eventRow['shared_team_key'] ?? ''));
    return $sharedTeamKey !== '' && isset($shareTeams[$sharedTeamKey]);
}

function can_view_shared_user_event(array $eventRow, string $loginId): bool
{
    $raw = trim((string)($eventRow['shared_user_ids'] ?? ''));
    if ($raw === '' || $loginId === '') {
        return false;
    }

    $tokens = preg_split('/[\s,;]+/', $raw) ?: [];
    foreach ($tokens as $token) {
        if (trim((string)$token) === $loginId) {
            return true;
        }
    }

    return false;
}

function normalize_event_payload(array $events, ?array $user, array $teamOptions, array $userOptions): array
{
    $result = [];
    $seen = [];
    $teamOptionMap = [];
    foreach ($teamOptions as $teamOption) {
        if (!is_array($teamOption)) {
            continue;
        }
        $teamKey = trim((string)($teamOption['key'] ?? ''));
        if ($teamKey === '') {
            continue;
        }
        $teamOptionMap[$teamKey] = (string)($teamOption['name'] ?? '');
    }

    $userOptionMap = [];
    foreach ($userOptions as $userOption) {
        if (!is_array($userOption)) {
            continue;
        }
        $optionLoginId = trim((string)($userOption['loginId'] ?? ''));
        if ($optionLoginId === '') {
            continue;
        }
        $userOptionMap[$optionLoginId] = (string)($userOption['name'] ?? $optionLoginId);
    }

    $canShareTeam = can_share_team_events($user);
    $canShareGlobal = can_share_global_events($user);
    $canShareUsers = can_share_user_events($user);
    $ownerTeamName = auth_normalize_team_name((string)($user['team'] ?? ''));
    $ownerTeamKey = $ownerTeamName !== '' ? auth_team_key($ownerTeamName) : '';

    foreach ($events as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = trim((string)($item['id'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        $date = trim((string)($item['date'] ?? ''));
        $startTime = trim((string)($item['startTime'] ?? ''));
        $endTime = trim((string)($item['endTime'] ?? ''));
        $category = trim((string)($item['category'] ?? 'personal'));
        $categoryLabel = trim((string)($item['categoryLabel'] ?? ''));
        $categoryColor = strtoupper(trim((string)($item['categoryColor'] ?? '#2563EB')));
        $memo = trim((string)($item['memo'] ?? ''));
        $visibilityScope = trim((string)($item['visibilityScope'] ?? 'private'));
        $sharedTeamKey = trim((string)($item['sharedTeamKey'] ?? ''));
        $sharedTeamName = '';
        $sharedUserIds = [];
        $sharedUserNames = [];

        if ($id === '' || $title === '' || !is_valid_date_key($date)) {
            continue;
        }
        if (!is_valid_time_key($startTime) || !is_valid_time_key($endTime)) {
            continue;
        }
        if ($startTime !== '' && $endTime !== '' && strcmp($startTime, $endTime) > 0) {
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;

        if (preg_match('/^#[0-9A-F]{6}$/', $categoryColor) !== 1) {
            $categoryColor = '#2563EB';
        }

        if (!in_array($visibilityScope, ['private', 'team', 'global', 'users'], true)) {
            $visibilityScope = 'private';
        }
        if ($visibilityScope === 'global' && !$canShareGlobal) {
            $visibilityScope = 'private';
        }
        if ($visibilityScope === 'team') {
            if (!$canShareTeam || $sharedTeamKey === '' || !isset($teamOptionMap[$sharedTeamKey])) {
                $visibilityScope = 'private';
            } else {
                $sharedTeamName = $teamOptionMap[$sharedTeamKey];
            }
        }
        if ($visibilityScope === 'users') {
            $rawSharedUsers = $item['sharedUserIds'] ?? [];
            if (!is_array($rawSharedUsers)) {
                $rawSharedUsers = [];
            }
            foreach ($rawSharedUsers as $sharedLoginId) {
                $sharedLoginId = trim((string)$sharedLoginId);
                if ($sharedLoginId === '' || !isset($userOptionMap[$sharedLoginId])) {
                    continue;
                }
                if (isset($sharedUserIds[$sharedLoginId])) {
                    continue;
                }
                $sharedUserIds[$sharedLoginId] = $sharedLoginId;
                $sharedUserNames[$sharedLoginId] = $userOptionMap[$sharedLoginId];
            }

            if (!$canShareUsers || empty($sharedUserIds)) {
                $visibilityScope = 'private';
                $sharedUserIds = [];
                $sharedUserNames = [];
            }
        }
        if ($visibilityScope !== 'team') {
            $sharedTeamKey = '';
            $sharedTeamName = '';
        }
        if ($visibilityScope !== 'users') {
            $sharedUserIds = [];
            $sharedUserNames = [];
        }

        $result[] = [
            'id' => mb_substr($id, 0, 100, 'UTF-8'),
            'title' => mb_substr($title, 0, 255, 'UTF-8'),
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'category' => mb_substr($category !== '' ? $category : 'personal', 0, 100, 'UTF-8'),
            'category_label' => mb_substr($categoryLabel !== '' ? $categoryLabel : '업무', 0, 24, 'UTF-8'),
            'category_color' => $categoryColor,
            'memo' => mb_substr($memo, 0, 65535, 'UTF-8'),
            'visibility_scope' => $visibilityScope,
            'owner_team_key' => $ownerTeamKey,
            'shared_team_key' => mb_substr($sharedTeamKey, 0, 120, 'UTF-8'),
            'shared_team_name' => mb_substr($sharedTeamName, 0, 120, 'UTF-8'),
            'shared_user_ids' => implode(',', array_values($sharedUserIds)),
            'shared_user_names' => implode(', ', array_values($sharedUserNames)),
            'sort_no' => $index,
        ];
    }

    return $result;
}

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user = auth_current_user();
    if (!is_array($user)) {
        calendar_store_json_response(401, [
            'success' => false,
            'message' => '로그인이 필요합니다.',
        ]);
    }

    $loginId = trim((string)($user['login_id'] ?? ''));
    if ($loginId === '') {
        calendar_store_json_response(401, [
            'success' => false,
            'message' => '로그인 정보가 올바르지 않습니다.',
        ]);
    }

    $pdo = getDB();
    ensure_calendar_store_tables($pdo);
    $teamOptions = calendar_share_team_options($user);
    $userOptions = calendar_share_user_options($user);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $categoryStmt = $pdo->prepare("
            SELECT category_key, label, color
            FROM calendar_custom_category
            WHERE owner_login_id = :owner_login_id
            ORDER BY sort_no ASC, category_id ASC
        ");
        $categoryStmt->execute(['owner_login_id' => $loginId]);

        $eventStmt = $pdo->prepare("
            SELECT owner_login_id, event_key, title, event_date, start_time, end_time, category_key, category_label, category_color, memo, visibility_scope, shared_team_key, shared_team_name, shared_user_ids, shared_user_names
            FROM calendar_manual_event
            ORDER BY event_date ASC, sort_no ASC, manual_event_id ASC
        ");
        $eventStmt->execute();

        $shareTeams = [];
        foreach ($teamOptions as $teamOption) {
            if (isset($teamOption['key'])) {
                $shareTeams[(string)$teamOption['key']] = true;
            }
        }

        $events = [];
        foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ownerLoginId = trim((string)($row['owner_login_id'] ?? ''));
            $visibilityScope = trim((string)($row['visibility_scope'] ?? 'private'));
            $isOwn = $ownerLoginId !== '' && $ownerLoginId === $loginId;

            $visible = $isOwn
                || $visibilityScope === 'global'
                || ($visibilityScope === 'team' && can_view_shared_team_event($row, $user, $shareTeams))
                || ($visibilityScope === 'users' && can_view_shared_user_event($row, $loginId));

            if (!$visible) {
                continue;
            }

            $ownerAccount = $ownerLoginId !== '' ? auth_find_user($ownerLoginId) : null;
            $events[] = [
                'id' => (string)$row['event_key'],
                'title' => (string)$row['title'],
                'date' => (string)$row['event_date'],
                'startTime' => (string)$row['start_time'],
                'endTime' => (string)$row['end_time'],
                'category' => (string)$row['category_key'],
                'categoryLabel' => (string)($row['category_label'] ?? ''),
                'categoryColor' => (string)($row['category_color'] ?? ''),
                'memo' => (string)($row['memo'] ?? ''),
                'visibilityScope' => $visibilityScope !== '' ? $visibilityScope : 'private',
                'sharedTeamKey' => (string)($row['shared_team_key'] ?? ''),
                'sharedTeamName' => (string)($row['shared_team_name'] ?? ''),
                'sharedUserIds' => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($row['shared_user_ids'] ?? '')) ?: []), static function (string $value): bool {
                    return $value !== '';
                })),
                'sharedUserNames' => (string)($row['shared_user_names'] ?? ''),
                'canEdit' => $isOwn,
                'ownerLoginId' => $ownerLoginId,
                'ownerName' => auth_display_name(is_array($ownerAccount) ? array_merge($ownerAccount, ['login_id' => $ownerLoginId]) : ['login_id' => $ownerLoginId]),
            ];
        }

        calendar_store_json_response(200, [
            'success' => true,
            'categories' => array_map(static function (array $row): array {
                return [
                    'id' => (string)$row['category_key'],
                    'label' => (string)$row['label'],
                    'color' => (string)$row['color'],
                ];
            }, $categoryStmt->fetchAll(PDO::FETCH_ASSOC)),
            'events' => $events,
            'shareOptions' => [
                'canShareTeam' => can_share_team_events($user),
                'canShareGlobal' => can_share_global_events($user),
                'canShareUsers' => can_share_user_events($user),
                'teams' => $teamOptions,
                'users' => $userOptions,
                'userTeamKey' => auth_team_key(auth_normalize_team_name((string)($user['team'] ?? ''))),
            ],
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        calendar_store_json_response(405, [
            'success' => false,
            'message' => '지원되지 않는 요청 방식입니다.',
        ]);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($payload)) {
        calendar_store_json_response(400, [
            'success' => false,
            'message' => '잘못된 요청 본문입니다.',
        ]);
    }

    $categories = normalize_category_payload(is_array($payload['categories'] ?? null) ? $payload['categories'] : []);
    $events = normalize_event_payload(is_array($payload['events'] ?? null) ? $payload['events'] : [], $user, $teamOptions, $userOptions);

    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM calendar_custom_category WHERE owner_login_id = :owner_login_id")
        ->execute(['owner_login_id' => $loginId]);
    $pdo->prepare("DELETE FROM calendar_manual_event WHERE owner_login_id = :owner_login_id")
        ->execute(['owner_login_id' => $loginId]);

    $categoryInsertStmt = $pdo->prepare("
        INSERT INTO calendar_custom_category (
            owner_login_id,
            category_key,
            label,
            color,
            sort_no
        ) VALUES (
            :owner_login_id,
            :category_key,
            :label,
            :color,
            :sort_no
        )
    ");

    foreach ($categories as $category) {
        $categoryInsertStmt->execute([
            'owner_login_id' => $loginId,
            'category_key' => $category['id'],
            'label' => $category['label'],
            'color' => $category['color'],
            'sort_no' => $category['sort_no'],
        ]);
    }

    $eventInsertStmt = $pdo->prepare("
        INSERT INTO calendar_manual_event (
            owner_login_id,
            event_key,
            title,
            event_date,
            start_time,
            end_time,
            category_key,
            category_label,
            category_color,
            memo,
            visibility_scope,
            owner_team_key,
            shared_team_key,
            shared_team_name,
            shared_user_ids,
            shared_user_names,
            sort_no
        ) VALUES (
            :owner_login_id,
            :event_key,
            :title,
            :event_date,
            :start_time,
            :end_time,
            :category_key,
            :category_label,
            :category_color,
            :memo,
            :visibility_scope,
            :owner_team_key,
            :shared_team_key,
            :shared_team_name,
            :shared_user_ids,
            :shared_user_names,
            :sort_no
        )
    ");

    foreach ($events as $event) {
        $eventInsertStmt->execute([
            'owner_login_id' => $loginId,
            'event_key' => $event['id'],
            'title' => $event['title'],
            'event_date' => $event['date'],
            'start_time' => $event['start_time'],
            'end_time' => $event['end_time'],
            'category_key' => $event['category'],
            'category_label' => $event['category_label'],
            'category_color' => $event['category_color'],
            'memo' => $event['memo'],
            'visibility_scope' => $event['visibility_scope'],
            'owner_team_key' => $event['owner_team_key'],
            'shared_team_key' => $event['shared_team_key'],
            'shared_team_name' => $event['shared_team_name'],
            'shared_user_ids' => $event['shared_user_ids'],
            'shared_user_names' => $event['shared_user_names'],
            'sort_no' => $event['sort_no'],
        ]);
    }

    $pdo->commit();

    calendar_store_json_response(200, [
        'success' => true,
        'message' => '달력 데이터가 저장되었습니다.',
        'categoryCount' => count($categories),
        'eventCount' => count($events),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    calendar_store_json_response(500, [
        'success' => false,
        'message' => '달력 데이터 저장 중 오류가 발생했습니다.',
    ]);
}
