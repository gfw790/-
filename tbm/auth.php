<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function tbm_auth_users(): array
{
    return [
        'gongsa' => [
            'username' => 'gongsa',
            'password' => getenv('TBM_AUTH_GONGSA_PASS') ?: 'gongsa1234',
            'team'     => '공사팀',
            'label'    => '공사팀',
        ],
        'gas' => [
            'username' => 'gas',
            'password' => getenv('TBM_AUTH_GAS_PASS') ?: 'gas1234',
            'team'     => '가스팀',
            'label'    => '가스팀',
        ],
        'samcheok' => [
            'username' => 'samcheok',
            'password' => getenv('TBM_AUTH_SAMCHEOK_PASS') ?: 'samcheok1234',
            'team'     => '제조팀',
            'label'    => '제조팀',
        ],
    ];
}

function tbm_auth_login(string $username, string $password): bool
{
    $users = tbm_auth_users();
    if (!isset($users[$username])) {
        return false;
    }

    $user = $users[$username];
    if (!hash_equals((string)$user['password'], $password)) {
        return false;
    }

    $_SESSION['tbm_auth_user'] = [
        'username' => $user['username'],
        'team'     => $user['team'],
        'label'    => $user['label'],
    ];

    return true;
}

function tbm_auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function tbm_auth_is_logged_in(): bool
{
    return !empty($_SESSION['tbm_auth_user']['username']);
}

function tbm_auth_user(): ?array
{
    return tbm_auth_is_logged_in() ? $_SESSION['tbm_auth_user'] : null;
}

function tbm_auth_current_team(): ?string
{
    $user = tbm_auth_user();
    return $user['team'] ?? null;
}

function tbm_is_ajax_request(): bool
{
    if (isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1') {
        return true;
    }
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function tbm_auth_require_login(): void
{
    if (tbm_auth_is_logged_in()) {
        return;
    }

    if (tbm_is_ajax_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode(['error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $target = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: login.php?redirect=' . rawurlencode($target));
    exit;
}
