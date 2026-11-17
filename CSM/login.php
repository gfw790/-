<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
header('Cache-Control: no-store');

$target = (string)($_POST['next'] ?? $_GET['next'] ?? 'portal');
$destination = csm_login_destination($target);
$error = '';
$loginId = '';
$_SESSION['csm_login_csrf'] ??= bin2hex(random_bytes(32));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['csm_login_csrf'], $token)) {
        http_response_code(400);
        $error = '요청을 확인할 수 없습니다. 페이지를 새로고침해 주세요.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        auth_logout();
        header('Location: /CSM/login.php', true, 303);
        exit;
    } else {
        $loginId = trim((string)($_POST['login_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!auth_login($loginId, $password)) {
            $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
        } else {
            session_regenerate_id(true);
            unset($_SESSION['csm_dev_user'], $_SESSION['risk_user']);
            $_SESSION['csm_login_csrf'] = bin2hex(random_bytes(32));
            header('Location: ' . $destination, true, 303);
            exit;
        }
    }
}
if ($error === '' && csm_current_user() !== null) {
    header('Location: ' . $destination);
    exit;
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>현대기전 업무시스템 로그인</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f2f5f9;color:#17253b;font:14px/1.6 "Malgun Gothic",sans-serif}.panel{width:100%;max-width:430px;background:white;border:1px solid #dfe6ef;border-radius:20px;padding:32px}.logo{display:inline-block;background:#3865f3;color:white;padding:7px 10px;border-radius:10px;font-weight:bold}h1{font-size:23px;margin:18px 0 8px}p{color:#61738c;margin:0 0 23px}label{display:block;margin:16px 0 5px;font-weight:bold}input{font:inherit;width:100%;border:1px solid #bdc9da;border-radius:8px;padding:11px}button{font:inherit;width:100%;margin-top:23px;background:#245eea;border:0;color:white;border-radius:9px;padding:12px;cursor:pointer;font-weight:bold}.error{background:#fff0f0;color:#a51c30;padding:12px;border-radius:8px}.note{font-size:12px;margin:18px 0 0}</style></head>
<body><main class="panel"><span class="logo">CSM</span><h1>안전보건 업무시스템 로그인</h1><p>기존 업무시스템 계정으로 로그인하세요.<br>로그인 후 경영방침과 안전교육으로 바로 이동할 수 있습니다.</p>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<form method="post" action="/CSM/login.php"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_login_csrf']) ?>"><input type="hidden" name="next" value="<?= $escape($target) ?>"><label for="login-id">아이디</label><input id="login-id" name="login_id" autocomplete="username" value="<?= $escape($loginId) ?>" required autofocus><label for="password">비밀번호</label><input id="password" name="password" type="password" autocomplete="current-password" required><button type="submit">로그인</button></form><p class="note">각 업무 화면의 접근 권한은 기존 계정 권한을 따릅니다.</p></main></body></html>
