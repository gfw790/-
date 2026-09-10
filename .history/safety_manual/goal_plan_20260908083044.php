<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

$isAllowed = auth_can_manage($user)
    && trim((string)($user['login_id'] ?? '')) === '5878';

if (!$isAllowed) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>권한이 없습니다</title>
    </head>
    <body>
        <h1>권한이 없습니다</h1>
        <p>이 페이지는 지정된 관리자 계정만 사용할 수 있습니다.</p>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>목표 및 세부계획 만들기</title>
</head>
<body>
    <h1>목표 및 세부계획 만들기</h1>
</body>
</html>