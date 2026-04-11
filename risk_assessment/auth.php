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

function auth_add_team(string $teamName): array
{
    $teamName = auth_normalize_team_name($teamName);
    if ($role === '') {
        return [false, '역할을 올바르게 선택해 주세요.'];
    }

    if ($role !== 'admin' && $teamName === '') {
        return [false, '팀 이름을 입력해주세요.'];
    }

    $teamLength = function_exists('mb_strlen') ? mb_strlen($teamName, 'UTF-8') : strlen($teamName);
    if ($teamLength > 30) {
        return [false, '팀 이름은 30자 이하로 입력해주세요.'];
    }

    if (auth_team_exists($teamName)) {
        return [false, '이미 등록된 팀입니다.'];
    }

    $teams = auth_read_teams();
    $teams[] = $teamName;

    if (!auth_write_teams($teams)) {
        return [false, '팀 정보를 저장하지 못했습니다.'];
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

    return [true, '팀이 삭제되었습니다.'];
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
    return ['worker', 'leader', 'manager', 'admin'];
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
        if (in_array($role, ['manager', 'leader'], true)) {
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
        'leader' => '작업지휘자(작업반장)',
        'admin' => '운영자',
        'worker' => '일반작업자',
        default => '사용자',
    };
}

function auth_is_admin(?array $user): bool
{
    return is_array($user) && (($user['role'] ?? '') === 'admin');
}

function auth_can_manage(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return in_array((string)($user['role'] ?? ''), ['manager', 'admin'], true);
}

function auth_can_lead(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return in_array((string)($user['role'] ?? ''), ['leader', 'admin'], true);
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
        return auth_unique_team_list(['공사팀-전기', '공사팀-모터', '가스팀']);
    }

    return [$teamName];
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

function auth_register_worker(string $loginId, string $password, string $name, string $teamName, string $role = 'worker'): array
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

    if ($teamName === '') {
        return [false, '소속 팀을 선택해주세요.'];
    }

    if (!auth_team_exists($teamName)) {
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
