<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전보건관리비 보고서 보기</title>
    <style>
        body { margin: 0; padding: 40px 20px; background: #f5f7fb; color: #1f2937; font-family: "Malgun Gothic", sans-serif; }
        .panel { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #dbe2ea; border-radius: 20px; padding: 28px; }
        a { color: #0b4ea2; }
    </style>
</head>
<body>
    <div class="panel">
        <h1>안전보건관리비 보고서 보기</h1>
        <p>준비 중인 기능입니다.</p>
        <p><a href="index.php">안전보건 매뉴얼로 돌아가기</a></p>
    </div>
</body>
</html>
