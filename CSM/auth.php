<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/risk_assessment/auth.php';

function csm_current_user(): ?array
{
    return auth_current_user();
}

function csm_is_portal_allowed(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }
    $loginId = trim((string)($user['login_id'] ?? ''));
    if ($loginId === '5878') {
        return true;
    }
    $rawName = trim((string)($user['name'] ?? ''));
    $cleanName = trim((string)preg_replace('/\s*\([^)]*\)\s*/u', '', $rawName));
    return $cleanName === '김남균';
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
        if (csm_is_portal_allowed($user)) {
            return $user;
        }
        header('Cache-Control: no-store');
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php' || isset($_GET['api'])) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'CSM 포털 접근 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>접근 제한 · CSM</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Malgun Gothic\',sans-serif;padding:20px;">'
            . '<div style="max-width:420px;width:100%;background:#fff;border:1px solid #cbd5e1;border-radius:14px;padding:28px 24px;text-align:center;box-shadow:0 10px 25px rgba(15,23,42,0.08);">'
            . '<div style="font-size:36px;margin-bottom:10px;">🔒</div>'
            . '<h2 style="margin:0 0 8px;font-size:18px;color:#0f172a;">CSM 포털 접근 권한이 없습니다</h2>'
            . '<p style="margin:0 0 20px;font-size:13.5px;color:#64748b;line-height:1.6;">현재 CSM 통합관리 포털 및 관리대장은<br>지정된 관리자 계정만 접근할 수 있습니다.</p>'
            . '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">'
            . '<a href="/CSM/safety_suggestion_submit.php" style="padding:9px 16px;border-radius:8px;background:#1e3a8a;color:#fff;font-size:13px;font-weight:700;text-decoration:none;">💡 안전제안 접수</a>'
            . '<a href="/risk_assessment/work_list.php" style="padding:9px 16px;border-radius:8px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;font-size:13px;font-weight:700;text-decoration:none;">← 작업목록으로</a>'
            . '</div></div></body></html>';
        exit;
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
