<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/risk_assessment/auth.php';

function csm_current_user(): ?array
{
    return auth_current_user();
}

function csm_login_destination(string $target): string
{
    return match ($target) {
        'education' => '/CSM/education/',
        'plan' => '/CSM/education/plan.php',
        'policy' => '/safety_manual/management_policy.php',
        'leadership' => '/CSM/leadership.php',
        'activity_plan' => '/CSM/activity_plan.php',
        'activity_monitor' => '/CSM/activity_monitor.php',
        'bonus_activity' => '/CSM/bonus_activity.php',
        'management_system' => '/CSM/management_system.php',
        'headcount' => '/CSM/headcount.php',
        'area_2' => '/CSM/area.php?group=2',
        'area_3' => '/CSM/area.php?group=3',
        'area_4' => '/CSM/area.php?group=4',
        'area_5' => '/CSM/area.php?group=5',
        'area_6' => '/CSM/area.php?group=6',
        default => '/CSM/',
    };
}

function csm_require_auth(): array
{
    $user = csm_current_user();
    if ($user !== null) {
        return $user;
    }

    header('Cache-Control: no-store');
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php' || isset($_GET['api'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/CSM/', PHP_URL_PATH);
    $target = str_starts_with((string)$path, '/CSM/education/') ? 'education' : 'portal';
    if ($path === '/CSM/education/plan.php') {
        $target = 'plan';
    }
    if ($path === '/CSM/leadership.php') {
        $target = 'leadership';
    }
    if ($path === '/CSM/activity_plan.php') {
        $target = 'activity_plan';
    }
    if ($path === '/CSM/activity_monitor.php') {
        $target = 'activity_monitor';
    }
    if ($path === '/CSM/bonus_activity.php') {
        $target = 'bonus_activity';
    }
    if ($path === '/CSM/management_system.php') {
        $target = 'management_system';
    }
    if ($path === '/CSM/headcount.php') {
        $target = 'headcount';
    }
    if ($path === '/CSM/area.php') {
        $area = $_GET['group'] ?? '2';
        if (is_string($area) && in_array($area, ['2', '3', '4', '5', '6'], true)) $target = 'area_' . $area;
    }
    header('Location: /CSM/login.php?next=' . $target);
    exit;
}
