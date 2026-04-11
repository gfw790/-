<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/tbm_db.php';
require_once __DIR__ . '/auth.php';

$redirect = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php'));
if ($redirect === '' || str_starts_with($redirect, 'http://') || str_starts_with($redirect, 'https://')) {
    $redirect = 'index.php';
}

if (isset($_GET['logout'])) {
    tbm_auth_logout();
    header('Location: login.php');
    exit;
}

if (tbm_auth_is_logged_in()) {
    header('Location: ' . $redirect);
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!tbm_auth_login($username, $password)) {
        $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
    } else {
        header('Location: ' . $redirect);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>TBM 로그인</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #e0f2fe, #f8fafc); font-family: "Malgun Gothic", sans-serif; color: #0f172a; }
.card { width: 100%; max-width: 420px; background: #fff; border: 1px solid #dbe4ee; border-radius: 16px; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08); padding: 32px; }
h1 { margin: 0 0 8px; font-size: 1.5rem; }
p { margin: 0 0 18px; color: #475569; line-height: 1.6; }
label { display: block; margin: 14px 0 6px; font-size: 0.92rem; font-weight: 600; }
input { width: 100%; padding: 11px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font: inherit; }
input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
button { width: 100%; margin-top: 18px; padding: 12px; border: 0; border-radius: 10px; background: #2563eb; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.error { margin-top: 12px; color: #b91c1c; font-size: 0.9rem; }
.hint { margin-top: 18px; padding: 12px 14px; border-radius: 10px; background: #eff6ff; color: #1d4ed8; font-size: 0.88rem; }
.hint code { font-family: Consolas, monospace; }
</style>
</head>
<body>
    <form class="card" method="post">
        <h1>TBM 로그인</h1>
        <p>팀 계정으로 로그인한 뒤 문서를 생성하고 출력할 수 있습니다.</p>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <label for="username">아이디</label>
        <input id="username" name="username" autocomplete="username" required>

        <label for="password">비밀번호</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>

        <button type="submit">로그인</button>

        <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="hint">
            기본 계정:<br>
            공사팀: <code>gongsa</code><br>
            가스팀: <code>gas</code><br>
            삼척팀: <code>samcheok</code>
        </div>
    </form>
</body>
</html>
