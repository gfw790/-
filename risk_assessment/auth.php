<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function auth_user_store_path(): string
{
    return __DIR__ . '/auth_users.json';
}

function auth_team_store_path(): string
{
    return __DIR__ . '/auth_teams.json';
}

function auth_team_supervisor_store_path(): string
{
    return __DIR__ . '/auth_team_supervisors.json';
}

function auth_default_teams(): array
{
    return ['공사팀-전기', '공사팀-모터'];
}

function auth_normalize_team_name(string $teamName): string
{
    $teamName = trim($teamName);
    $normalized = preg_replace('/\s+/u', ' ', $teamName);

    return is_string($normalized) ? trim($normalized) : $teamName;
}

function auth_team_key(string $teamName): string
{
    $normalized = auth_normalize_team_name($teamName);
    return function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
}

function auth_manager_leader_teams(): array
{
    return ['공사팀-모터', '가스팀', '제조팀'];
}

function auth_protected_team_names(): array
{
    return auth_unique_team_list(array_merge(
        auth_default_teams(),
        auth_manager_leader_teams(),
        ['공사팀-전기', '가스팀', '제조팀', '안전관리']
    ));
}

function auth_is_protected_team_name(string $teamName): bool
{
    $teamKey = auth_team_key($teamName);
    if ($teamKey === '') {
        return false;
    }

    foreach (auth_protected_team_names() as $protectedTeamName) {
        if (auth_team_key($protectedTeamName) === $teamKey) {
            return true;
        }
    }

    return false;
}

function auth_would_create_supervisor_cycle(string $teamName, string $supervisorTeamName): bool
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    $normalizedSupervisor = auth_normalize_team_name($supervisorTeamName);
    if ($normalizedTeam === '' || $normalizedSupervisor === '') {
        return false;
    }

    if (auth_team_key($normalizedTeam) === auth_team_key($normalizedSupervisor)) {
        return true;
    }

    $supervisors = auth_read_team_supervisors();
    $pending = [$normalizedSupervisor];
    $seen = [];

    while (!empty($pending)) {
        $currentTeam = array_pop($pending);
        $currentKey = auth_team_key($currentTeam);
        if ($currentKey === '' || isset($seen[$currentKey])) {
            continue;
        }
        $seen[$currentKey] = true;

        if ($currentKey === auth_team_key($normalizedTeam)) {
            return true;
        }

        if (!empty($supervisors[$currentTeam])) {
            $pending[] = auth_normalize_team_name((string)$supervisors[$currentTeam]);
        }
    }

    return false;
}

function auth_supervisor_map_has_cycle(array $supervisors): bool
{
    $graph = [];
    foreach ($supervisors as $teamName => $supervisorTeam) {
        $normalizedTeam = auth_normalize_team_name((string)$teamName);
        $normalizedSupervisor = auth_normalize_team_name((string)$supervisorTeam);
        if ($normalizedTeam === '' || $normalizedSupervisor === '') {
            continue;
        }
        $graph[$normalizedTeam] = $normalizedSupervisor;
    }

    foreach ($graph as $teamName => $_) {
        $seen = [];
        $currentTeam = $teamName;
        while (isset($graph[$currentTeam])) {
            $teamKey = auth_team_key($currentTeam);
            if ($teamKey === '') {
                break;
            }
            if (isset($seen[$teamKey])) {
                return true;
            }
            $seen[$teamKey] = true;
            $currentTeam = $graph[$currentTeam];
        }
    }

    return false;
}

function auth_team_requires_manager_leader_role(string $teamName): bool
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    if ($normalizedTeam === '') {
        return false;
    }

    foreach (auth_manager_leader_teams() as $leaderTeamName) {
        if (auth_team_key($leaderTeamName) === auth_team_key($normalizedTeam)) {
            return true;
        }
    }

    return false;
}

function auth_manager_can_cover_leader_role(?array $user): bool
{
    if (!auth_can_manage($user) || !is_array($user)) {
        return false;
    }

    return auth_team_requires_manager_leader_role((string)($user['team'] ?? ''));
}

function auth_unique_team_list(array $teams): array
{
    $uniqueTeams = [];
    $seen = [];

    foreach ($teams as $team) {
        if (!is_string($team) && !is_int($team)) {
            continue;
        }

        $normalizedTeam = auth_normalize_team_name((string)$team);
        if ($normalizedTeam === '') {
            continue;
        }

        $teamKey = auth_team_key($normalizedTeam);
        if (isset($seen[$teamKey])) {
            continue;
        }

        $seen[$teamKey] = true;
        $uniqueTeams[] = $normalizedTeam;
    }

    return $uniqueTeams;
}

function auth_read_teams(): array
{
    $path = auth_team_store_path();
    if (!is_file($path)) {
        return auth_default_teams();
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return auth_default_teams();
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return auth_default_teams();
    }

    $teams = auth_unique_team_list($decoded);
    return !empty($teams) ? $teams : auth_default_teams();
}

function auth_write_teams(array $teams): bool
{
    $payload = auth_unique_team_list($teams);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(auth_team_store_path(), $json, LOCK_EX) !== false;
}

function auth_team_exists(string $teamName): bool
{
    $teamKey = auth_team_key($teamName);
    foreach (auth_read_teams() as $team) {
        if (auth_team_key($team) === $teamKey) {
            return true;
        }
    }

    return false;
}

function auth_read_team_supervisors(): array
{
    $path = auth_team_supervisor_store_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    $supervisors = [];
    foreach ($decoded as $teamName => $supervisorTeam) {
        if (!is_string($teamName) || !is_string($supervisorTeam)) {
            continue;
        }

        $normalizedTeam = auth_normalize_team_name($teamName);
        $normalizedSupervisor = auth_normalize_team_name($supervisorTeam);
        if ($normalizedTeam === '' || $normalizedSupervisor === '') {
            continue;
        }

        if (!auth_team_exists($normalizedTeam) || !auth_team_exists($normalizedSupervisor)) {
            continue;
        }

        $supervisors[$normalizedTeam] = $normalizedSupervisor;
    }

    return $supervisors;
}

function auth_write_team_supervisors(array $supervisors): bool
{
    $payload = [];
    foreach ($supervisors as $teamName => $supervisorTeam) {
        $normalizedTeam = auth_normalize_team_name((string)$teamName);
        $normalizedSupervisor = auth_normalize_team_name((string)$supervisorTeam);
        if ($normalizedTeam === '' || $normalizedSupervisor === '') {
            continue;
        }
        $payload[$normalizedTeam] = $normalizedSupervisor;
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(auth_team_supervisor_store_path(), $json, LOCK_EX) !== false;
}

function auth_get_team_supervisor(string $teamName): string
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    if ($normalizedTeam === '') {
        return '';
    }

    $supervisors = auth_read_team_supervisors();
    return $supervisors[$normalizedTeam] ?? '';
}

function auth_supervised_teams(string $supervisorTeam): array
{
    $normalizedSupervisor = auth_normalize_team_name($supervisorTeam);
    if ($normalizedSupervisor === '') {
        return [];
    }

    $supervisors = auth_read_team_supervisors();
    $supervisedTeams = [];
    $pending = [$normalizedSupervisor];
    $seen = [];

    while (!empty($pending)) {
        $currentSupervisor = array_pop($pending);
        foreach ($supervisors as $teamName => $managedTeam) {
            if (auth_team_key($managedTeam) !== auth_team_key($currentSupervisor)) {
                continue;
            }
            $normalizedTeamName = auth_normalize_team_name($teamName);
            $teamKey = auth_team_key($normalizedTeamName);
            if ($teamKey === '' || isset($seen[$teamKey])) {
                continue;
            }
            $seen[$teamKey] = true;
            $supervisedTeams[] = $normalizedTeamName;
            $pending[] = $normalizedTeamName;
        }
    }

    return auth_unique_team_list($supervisedTeams);
}

function auth_set_team_supervisor(string $teamName, string $supervisorTeamName): bool
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    $normalizedSupervisor = auth_normalize_team_name($supervisorTeamName);
    if ($normalizedTeam === '') {
        return false;
    }

    $supervisors = auth_read_team_supervisors();
    if ($normalizedSupervisor === '') {
        unset($supervisors[$normalizedTeam]);
        return auth_write_team_supervisors($supervisors);
    }

    if (!auth_team_exists($normalizedTeam) || !auth_team_exists($normalizedSupervisor)) {
        return false;
    }

    if (auth_team_key($normalizedTeam) === auth_team_key($normalizedSupervisor)) {
        return false;
    }

    if (auth_would_create_supervisor_cycle($normalizedTeam, $normalizedSupervisor)) {
        return false;
    }

    $supervisors[$normalizedTeam] = $normalizedSupervisor;
    return auth_write_team_supervisors($supervisors);
}

function auth_remove_team_supervisor(string $teamName): bool
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    if ($normalizedTeam === '') {
        return false;
    }

    $supervisors = auth_read_team_supervisors();
    foreach ($supervisors as $team => $supervisor) {
        if (auth_team_key($team) === auth_team_key($normalizedTeam) || auth_team_key($supervisor) === auth_team_key($normalizedTeam)) {
            unset($supervisors[$team]);
        }
    }

    return auth_write_team_supervisors($supervisors);
}

function auth_team_has_leader_account(string $teamName): bool
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

function auth_add_team(string $teamName, string $teamSupervisor = ''): array
{
    $teamName = auth_normalize_team_name($teamName);
    $teamSupervisor = auth_normalize_team_name($teamSupervisor);
    if ($teamName === '') {
        return [false, '팀 이름을 입력해주세요.'];
    }

    $teamLength = function_exists('mb_strlen') ? mb_strlen($teamName, 'UTF-8') : strlen($teamName);
    if ($teamLength > 30) {
        return [false, '팀 이름은 30자 이하로 입력해주세요.'];
    }

    if (auth_team_exists($teamName)) {
        return [false, '이미 등록된 팀입니다.'];
    }

    if ($teamSupervisor !== '' && !auth_team_exists($teamSupervisor)) {
        return [false, '관리감독팀을 선택해주세요.'];
    }

    if ($teamSupervisor !== '' && auth_team_key($teamName) === auth_team_key($teamSupervisor)) {
        return [false, '관리감독팀은 자신과 같을 수 없습니다.'];
    }

    $teams = auth_read_teams();
    $teams[] = $teamName;

    if (!auth_write_teams($teams)) {
        return [false, '팀 정보를 저장하지 못했습니다.'];
    }

    if ($teamSupervisor !== '' && !auth_set_team_supervisor($teamName, $teamSupervisor)) {
        return [false, '관리감독팀 정보를 저장하지 못했습니다.'];
    }

    return [true, '팀이 추가되었습니다.'];
}

function auth_team_member_counts(): array
{
    $counts = [];

    foreach (auth_accounts() as $account) {
        $teamName = auth_normalize_team_name((string)($account['team'] ?? ''));
        if ($teamName === '') {
            continue;
        }

        if (!isset($counts[$teamName])) {
            $counts[$teamName] = 0;
        }
        $counts[$teamName]++;
    }

    return $counts;
}

function auth_count_team_members(string $teamName): int
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    if ($normalizedTeam === '') {
        return 0;
    }

    $counts = auth_team_member_counts();
    return (int)($counts[$normalizedTeam] ?? 0);
}

function auth_team_members(string $teamName, ?array $includeRoles = null): array
{
    $normalizedTeam = auth_normalize_team_name($teamName);
    if ($normalizedTeam === '') {
        return [];
    }

    if ($includeRoles === null) {
        $includeRoles = ['worker'];
    }

    $members = [];
    foreach (auth_accounts() as $loginId => $account) {
        $accountTeam = auth_normalize_team_name((string)($account['team'] ?? ''));
        if ($accountTeam !== $normalizedTeam) {
            continue;
        }

        $role = (string)($account['role'] ?? '');
        if (!in_array($role, $includeRoles, true)) {
            continue;
        }

        $members[] = [
            'login_id' => $loginId,
            'name' => trim((string)($account['name'] ?? '')) ?: $loginId,
            'role' => $role,
            'phone' => trim((string)($account['phone'] ?? '')),
        ];
    }

    usort($members, static fn(array $a, array $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    return $members;
}

function auth_team_worker_names(string $teamName): array
{
    $names = [];
    foreach (auth_team_members($teamName, ['worker']) as $member) {
        $name = trim((string)($member['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $names[] = $name;
    }

    return array_values($names);
}

function auth_team_member_names(string $teamName, ?array $includeRoles = null): array
{
    $roles = $includeRoles ?? ['worker', 'leader', 'manager', 'safety_manager'];
    $names = [];
    foreach (auth_team_members($teamName, $roles) as $member) {
        $name = trim((string)($member['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $names[] = $name;
    }

    return array_values($names);
}

function auth_delete_team(string $teamName): array
{
    $teamName = auth_normalize_team_name($teamName);
    if ($teamName === '') {
        return [false, '삭제할 팀을 찾을 수 없습니다.'];
    }

    if ($teamName !== '' && !auth_team_exists($teamName)) {
        return [false, '삭제할 팀을 찾을 수 없습니다.'];
    }

    if (auth_count_team_members($teamName) > 0) {
        return [false, '팀원이 있는 팀은 삭제할 수 없습니다.'];
    }

    $teams = array_values(array_filter(
        auth_read_teams(),
        static fn($existingTeam) => auth_team_key((string)$existingTeam) !== auth_team_key($teamName)
    ));

    if (!auth_write_teams($teams)) {
        return [false, '팀 정보를 저장하지 못했습니다.'];
    }

    auth_remove_team_supervisor($teamName);

    return [true, '팀이 삭제되었습니다.'];
}

function auth_sync_team_name_in_work_reports(string $oldTeamName, string $newTeamName): array
{
    $normalizedOldTeam = auth_normalize_team_name($oldTeamName);
    $normalizedNewTeam = auth_normalize_team_name($newTeamName);
    if ($normalizedOldTeam === '' || $normalizedNewTeam === '') {
        return [false, '작업 이력 팀명 동기화 대상이 올바르지 않습니다.'];
    }

    $dbConfigPath = __DIR__ . '/db_config.php';
    if (!is_file($dbConfigPath)) {
        return [false, 'DB 설정 파일을 찾을 수 없습니다.'];
    }

    require_once $dbConfigPath;

    if (!function_exists('getDB')) {
        return [false, 'DB 연결 함수를 찾을 수 없습니다.'];
    }

    try {
        $pdo = getDB();
        $tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'work_report'");
        $workReportTableExists = $tableCheckStmt !== false && $tableCheckStmt->fetchColumn() !== false;
        if (!$workReportTableExists) {
            return [true, '작업 이력 테이블이 아직 없어 동기화할 데이터가 없습니다.'];
        }
        $stmt = $pdo->prepare("UPDATE work_report SET team_name = :new_team_name WHERE team_name = :old_team_name");
        $stmt->execute([
            ':new_team_name' => $normalizedNewTeam,
            ':old_team_name' => $normalizedOldTeam,
        ]);
    } catch (Throwable $e) {
        return [false, '작업 이력 팀명을 변경하지 못했습니다: ' . $e->getMessage()];
    }

    return [true, '작업 이력 팀명이 동기화되었습니다.'];
}

function auth_rename_team(string $currentTeamName, string $newTeamName): array
{
    $currentTeamName = auth_normalize_team_name($currentTeamName);
    $newTeamName = auth_normalize_team_name($newTeamName);

    if ($currentTeamName === '') {
        return [false, '수정할 팀을 찾을 수 없습니다.'];
    }

    if (!auth_team_exists($currentTeamName)) {
        return [false, '수정할 팀을 찾을 수 없습니다.'];
    }

    if ($newTeamName === '') {
        return [false, '새 팀 이름을 입력해주세요.'];
    }

    $teamLength = function_exists('mb_strlen') ? mb_strlen($newTeamName, 'UTF-8') : strlen($newTeamName);
    if ($teamLength > 30) {
        return [false, '팀 이름은 30자 이하로 입력해주세요.'];
    }

    if (auth_team_key($currentTeamName) === auth_team_key($newTeamName)) {
        return [false, '변경할 팀 이름이 현재 이름과 같습니다.'];
    }

    if (auth_team_exists($newTeamName)) {
        return [false, '이미 등록된 팀 이름입니다.'];
    }

    if (auth_is_protected_team_name($currentTeamName) || auth_is_protected_team_name($newTeamName)) {
        return [false, '시스템 규칙에 연결된 기본 팀은 이름을 변경할 수 없습니다.'];
    }

    $teams = auth_read_teams();
    $updatedTeams = [];
    $renamed = false;
    foreach ($teams as $teamName) {
        if (auth_team_key((string)$teamName) === auth_team_key($currentTeamName)) {
            $updatedTeams[] = $newTeamName;
            $renamed = true;
            continue;
        }
        $updatedTeams[] = auth_normalize_team_name((string)$teamName);
    }

    if (!$renamed) {
        return [false, '수정할 팀을 찾을 수 없습니다.'];
    }

    $storedAccounts = auth_read_stored_accounts();
    $originalStoredAccounts = $storedAccounts;
    foreach ($storedAccounts as $loginId => $account) {
        $accountTeam = auth_normalize_team_name((string)($account['team'] ?? ''));
        if (auth_team_key($accountTeam) === auth_team_key($currentTeamName)) {
            $storedAccounts[$loginId]['team'] = $newTeamName;
        }
    }

    $supervisors = auth_read_team_supervisors();
    $originalSupervisors = $supervisors;
    $updatedSupervisors = [];
    foreach ($supervisors as $teamName => $supervisorTeam) {
        $normalizedTeam = auth_normalize_team_name((string)$teamName);
        $normalizedSupervisor = auth_normalize_team_name((string)$supervisorTeam);

        if (auth_team_key($normalizedTeam) === auth_team_key($currentTeamName)) {
            $normalizedTeam = $newTeamName;
        }
        if (auth_team_key($normalizedSupervisor) === auth_team_key($currentTeamName)) {
            $normalizedSupervisor = $newTeamName;
        }

        if ($normalizedTeam === '' || $normalizedSupervisor === '') {
            continue;
        }
        $updatedSupervisors[$normalizedTeam] = $normalizedSupervisor;
    }

    foreach ($updatedSupervisors as $teamName => $supervisorTeam) {
        if (auth_team_key($teamName) === auth_team_key($supervisorTeam)) {
            return [false, '팀 이름 변경 후 관리감독팀 연결이 자기 자신을 가리키게 됩니다.'];
        }
    }
    if (auth_supervisor_map_has_cycle($updatedSupervisors)) {
        return [false, '팀 이름 변경 후 관리감독팀 연결에 순환이 생깁니다.'];
    }

    $originalSessionTeam = auth_normalize_team_name((string)($_SESSION['auth_user']['team'] ?? ''));

    if (!auth_write_teams($updatedTeams)) {
        return [false, '팀 정보를 저장하지 못했습니다.'];
    }

    if (!auth_write_stored_accounts($storedAccounts)) {
        auth_write_teams($teams);
        return [false, '팀 소속 계정 정보를 저장하지 못했습니다.'];
    }

    if (!auth_write_team_supervisors($updatedSupervisors)) {
        auth_write_stored_accounts($originalStoredAccounts);
        auth_write_teams($teams);
        return [false, '관리감독팀 연결 정보를 저장하지 못했습니다.'];
    }

    [$synced, $syncMessage] = auth_sync_team_name_in_work_reports($currentTeamName, $newTeamName);
    if (!$synced) {
        auth_write_team_supervisors($originalSupervisors);
        auth_write_stored_accounts($originalStoredAccounts);
        auth_write_teams($teams);
        return [false, $syncMessage];
    }

    if ($originalSessionTeam !== '' && auth_team_key($originalSessionTeam) === auth_team_key($currentTeamName) && isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
        $_SESSION['auth_user']['team'] = $newTeamName;
    }

    return [true, '팀 이름이 수정되었습니다.'];
}

function auth_default_accounts(): array
{
    return [
        'manager01' => [
            'password' => '1234',
            'name' => '관리감독자',
            'role' => 'manager',
            'team' => '',
        ],
        'leader01' => [
            'password' => '1234',
            'name' => '작업지휘자',
            'role' => 'leader',
            'team' => '',
        ],
        'worker01' => [
            'password' => '1234',
            'name' => '일반작업자',
            'role' => 'worker',
            'team' => '',
        ],
    ];
}

function auth_read_stored_accounts(): array
{
    $path = auth_user_store_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    $accounts = [];
    foreach ($decoded as $loginId => $account) {
        if ((!is_string($loginId) && !is_int($loginId)) || !is_array($account)) {
            continue;
        }

        $loginId = trim((string)$loginId);
        if ($loginId === '') {
            continue;
        }

        $role = auth_normalize_role((string)($account['role'] ?? ''));
        if ($role === '') {
            continue;
        }

        $accounts[$loginId] = [
            'password' => (string)($account['password'] ?? ''),
            'name' => (string)($account['name'] ?? ''),
            'role' => $role,
            'team' => auth_normalize_team_name((string)($account['team'] ?? '')),
            'phone' => trim((string)($account['phone'] ?? '')),
        ];
    }

    return $accounts;
}

function auth_write_stored_accounts(array $accounts): bool
{
    $json = json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(auth_user_store_path(), $json, LOCK_EX) !== false;
}

function auth_allowed_roles(): array
{
    return ['worker', 'leader', 'manager', 'safety_manager', 'admin', 'ceo'];
}

function auth_normalize_role(string $role): string
{
    $role = trim($role);
    return in_array($role, auth_allowed_roles(), true) ? $role : '';
}

function auth_settings_store_path(): string
{
    return __DIR__ . '/auth_settings.json';
}

function auth_default_settings(): array
{
    return [
        'worker_registration_open' => false,
    ];
}

function auth_read_settings(): array
{
    $settings = auth_default_settings();
    $path = auth_settings_store_path();
    if (!is_file($path)) {
        return $settings;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $settings;
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return $settings;
    }

    if (array_key_exists('worker_registration_open', $decoded)) {
        $settings['worker_registration_open'] = (bool)$decoded['worker_registration_open'];
    }

    return $settings;
}

function auth_write_settings(array $settings): bool
{
    $payload = [
        'worker_registration_open' => !empty($settings['worker_registration_open']),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(auth_settings_store_path(), $json, LOCK_EX) !== false;
}

function auth_is_worker_registration_open(): bool
{
    $settings = auth_read_settings();
    return !empty($settings['worker_registration_open']);
}

function auth_set_worker_registration_open(bool $isOpen): bool
{
    $settings = auth_read_settings();
    $settings['worker_registration_open'] = $isOpen;
    return auth_write_settings($settings);
}

function auth_accounts(): array
{
    $defaultAccounts = auth_default_accounts();
    $storedAccounts = auth_read_stored_accounts();
    $rolesWithStoredOverride = [];

    foreach ($storedAccounts as $account) {
        $role = (string)($account['role'] ?? '');
        if (in_array($role, ['manager', 'safety_manager', 'leader'], true)) {
            $rolesWithStoredOverride[$role] = true;
        }
    }

    if (!empty($rolesWithStoredOverride)) {
        foreach ($defaultAccounts as $loginId => $account) {
            $role = (string)($account['role'] ?? '');
            if (isset($rolesWithStoredOverride[$role])) {
                unset($defaultAccounts[$loginId]);
            }
        }
    }

    return $defaultAccounts + $storedAccounts;
}

function auth_role_label(string $role): string
{
    return match ($role) {
        'manager' => '관리감독자',
        'safety_manager' => '안전관리자',
        'leader' => '작업지휘자(작업반장)',
        'admin' => '운영자',
        'ceo' => '대표이사',
        'worker' => '일반작업자',
        default => '사용자',
    };
}

function auth_is_admin(?array $user): bool
{
    return is_array($user) && in_array((string)($user['role'] ?? ''), ['admin', 'ceo'], true);
}

function auth_can_manage(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return in_array((string)($user['role'] ?? ''), ['manager', 'safety_manager', 'admin', 'ceo'], true);
}

function auth_can_lead(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return in_array((string)($user['role'] ?? ''), ['leader', 'admin', 'ceo'], true);
}

function auth_is_worker(?array $user): bool
{
    return is_array($user) && (($user['role'] ?? '') === 'worker');
}

function auth_current_user(): ?array
{
    $sessionUser = $_SESSION['auth_user'] ?? null;
    if (!is_array($sessionUser)) {
        return null;
    }

    $loginId = trim((string)($sessionUser['login_id'] ?? ''));
    if ($loginId === '') {
        return null;
    }

    $account = auth_find_user($loginId);
    if ($account === null) {
        return null;
    }

    $sessionUser['name'] = (string)($account['name'] ?? $sessionUser['name'] ?? '');
    $sessionUser['role'] = (string)($account['role'] ?? $sessionUser['role'] ?? '');
    $sessionUser['role_label'] = auth_role_label((string)($sessionUser['role'] ?? ''));
    $sessionUser['team'] = auth_normalize_team_name((string)($account['team'] ?? ''));
    $_SESSION['auth_user'] = $sessionUser;

    return $sessionUser;
}

function auth_team_process_preferences(?array $user): array
{
    $teamName = auth_normalize_team_name((string)($user['team'] ?? ''));
    $preferences = [
        'default_manager_process_category' => '',
        'allowed_manager_process_categories' => [],
        'excluded_manager_process_categories' => [],
    ];

    if ($teamName === '공사팀-모터') {
        $preferences['default_manager_process_category'] = '모터관련';
        $preferences['allowed_manager_process_categories'] = ['모터관련'];
    } elseif ($teamName === '공사팀-전기') {
        $preferences['excluded_manager_process_categories'] = ['모터관련'];
    } elseif ($teamName === '가스팀') {
        $preferences['default_manager_process_category'] = '가스분석 관련';
        $preferences['allowed_manager_process_categories'] = ['가스분석관련', '가스분석 관련'];
    } else {
        $preferences['excluded_manager_process_categories'] = ['가스분석관련', '가스분석 관련'];
    }

    return $preferences;
}

function auth_work_list_visible_teams(?array $user): array
{
    if (!is_array($user) || auth_is_admin($user)) {
        return [];
    }

    $teamName = auth_normalize_team_name((string)($user['team'] ?? ''));
    if ($teamName === '') {
        return [];
    }

    if (auth_can_manage($user) && auth_team_key($teamName) === auth_team_key('공사팀-전기')) {
        return auth_unique_team_list(array_merge(
            ['공사팀-전기', '공사팀-모터', '가스팀'],
            auth_supervised_teams($teamName)
        ));
    }

    $visibleTeams = [$teamName];
    if (auth_can_manage($user)) {
        $visibleTeams = array_merge($visibleTeams, auth_supervised_teams($teamName));
    }

    if (!auth_team_has_leader_account($teamName)) {
        $supervisorTeam = auth_get_team_supervisor($teamName);
        if ($supervisorTeam !== '') {
            $visibleTeams[] = $supervisorTeam;
        }
    }

    return auth_unique_team_list($visibleTeams);
}

function auth_display_name(?array $user): string
{
    if (!is_array($user)) {
        return '';
    }

    $name = trim((string)($user['name'] ?? ''));
    $roleLabel = trim((string)($user['role_label'] ?? ''));
    $loginId = trim((string)($user['login_id'] ?? ''));

    if ($name !== '' && $roleLabel !== '' && $name === $roleLabel) {
        return $name . ' 계정';
    }

    if ($name !== '') {
        return $name;
    }

    return $loginId;
}

function auth_find_user(string $loginId): ?array
{
    $accounts = auth_accounts();
    return $accounts[$loginId] ?? null;
}

function auth_login(string $loginId, string $password): bool
{
    $account = auth_find_user($loginId);
    if ($account === null) {
        return false;
    }

    if ($account['password'] !== $password) {
        return false;
    }

    $_SESSION['auth_user'] = [
        'login_id' => $loginId,
        'name' => $account['name'],
        'role' => $account['role'],
        'role_label' => auth_role_label($account['role']),
        'team' => auth_normalize_team_name((string)($account['team'] ?? '')),
    ];

    return true;
}

function auth_is_login_id_available(string $loginId): bool
{
    return auth_find_user($loginId) === null;
}

function auth_register_worker(string $loginId, string $password, string $name, string $teamName, string $role = 'worker', string $phone = ''): array
{
    $loginId = trim($loginId);
    $password = trim($password);
    $name = trim($name);
    $teamName = auth_normalize_team_name($teamName);
    $role = auth_normalize_role($role);

    if ($loginId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,29}$/', $loginId)) {
        return [false, '아이디는 영문, 숫자, -, _ 만 사용할 수 있으며 3~30자여야 합니다.'];
    }

    if ($password === '' || strlen($password) < 4) {
        return [false, '비밀번호는 4자 이상 입력해주세요.'];
    }

    if ($name === '') {
        return [false, '이름을 입력해주세요.'];
    }

    $teamRequired = !in_array($role, ['admin', 'ceo'], true);
    if ($teamRequired && $teamName === '') {
        return [false, '소속 팀을 선택해주세요.'];
    }

    if ($teamRequired && $teamName !== '' && !auth_team_exists($teamName)) {
        return [false, '선택한 팀을 찾을 수 없습니다.'];
    }

    if (!auth_is_login_id_available($loginId)) {
        return [false, '이미 사용 중인 아이디입니다.'];
    }

    $storedAccounts = auth_read_stored_accounts();
    $storedAccounts[$loginId] = [
        'password' => $password,
        'name' => $name,
        'role' => $role,
        'team' => $teamName,
        'phone' => trim($phone),
    ];

    if (!auth_write_stored_accounts($storedAccounts)) {
        return [false, '회원가입 정보를 저장하지 못했습니다.'];
    }

    return [true, '회원가입이 완료되었습니다.'];
}

function auth_update_stored_account_role(string $loginId, string $role): array
{
    $loginId = trim($loginId);
    $role = auth_normalize_role($role);

    if ($loginId === '') {
        return [false, '변경할 계정을 찾을 수 없습니다.'];
    }

    if ($role === '') {
        return [false, '역할을 올바르게 선택해 주세요.'];
    }

    $storedAccounts = auth_read_stored_accounts();
    if (!isset($storedAccounts[$loginId])) {
        return [false, '변경할 계정을 찾을 수 없습니다.'];
    }

    $storedAccounts[$loginId]['role'] = $role;

    if (!auth_write_stored_accounts($storedAccounts)) {
        return [false, '계정 역할을 저장하지 못했습니다.'];
    }

    return [true, '계정 역할을 변경했습니다.'];
}

function auth_delete_stored_account(string $loginId): array
{
    $loginId = trim($loginId);
    if ($loginId === '') {
        return [false, '삭제할 계정을 찾을 수 없습니다.'];
    }

    $storedAccounts = auth_read_stored_accounts();
    if (!isset($storedAccounts[$loginId])) {
        return [false, '삭제할 계정을 찾을 수 없습니다.'];
    }

    unset($storedAccounts[$loginId]);

    if (!auth_write_stored_accounts($storedAccounts)) {
        return [false, '계정 정보를 저장하지 못했습니다.'];
    }

    return [true, '계정이 삭제되었습니다.'];
}

function auth_update_account_phone(string $loginId, string $phone): array
{
    $loginId = trim($loginId);
    if ($loginId === '') {
        return [false, '계정을 찾을 수 없습니다.'];
    }

    $storedAccounts = auth_read_stored_accounts();
    if (!isset($storedAccounts[$loginId])) {
        return [false, '계정을 찾을 수 없습니다.'];
    }

    $storedAccounts[$loginId]['phone'] = trim($phone);

    if (!auth_write_stored_accounts($storedAccounts)) {
        return [false, '전화번호를 저장하지 못했습니다.'];
    }

    return [true, '전화번호가 저장되었습니다.'];
}

function auth_change_password(string $loginId, string $currentPassword, string $newPassword): array
{
    $loginId = trim($loginId);

    if ($loginId === '') {
        return [false, '계정 정보를 찾을 수 없습니다.'];
    }

    if (trim($newPassword) === '' || strlen(trim($newPassword)) < 4) {
        return [false, '새 비밀번호는 4자 이상 입력해주세요.'];
    }

    $storedAccounts = auth_read_stored_accounts();
    if (!isset($storedAccounts[$loginId])) {
        return [false, '계정을 찾을 수 없습니다.'];
    }

    if ($storedAccounts[$loginId]['password'] !== trim($currentPassword)) {
        return [false, '현재 비밀번호가 올바르지 않습니다.'];
    }

    $storedAccounts[$loginId]['password'] = trim($newPassword);

    if (!auth_write_stored_accounts($storedAccounts)) {
        return [false, '비밀번호를 저장하지 못했습니다.'];
    }

    return [true, '비밀번호가 변경되었습니다.'];
}

function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function auth_require_login(): array
{
    $user = auth_current_user();
    if ($user === null) {
        header('Location: task_select.php');
        exit;
    }

    return $user;
}
