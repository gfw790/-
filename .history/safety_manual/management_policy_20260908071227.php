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
        <style>
            body { margin: 0; padding: 40px 20px; background: #f5f7fb; color: #1f2937; font-family: "Malgun Gothic", sans-serif; }
            .panel { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #dbe2ea; border-radius: 20px; padding: 28px; }
            a { color: #0b4ea2; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>권한이 없습니다</h1>
            <p>이 페이지는 지정된 관리자 계정만 사용할 수 있습니다.</p>
            <p><a href="index.php">안전보건 매뉴얼로 돌아가기</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$policySections = [
    'policy' => [
        'title' => '안전보건 경영방침',
        'placeholder' => '회사의 안전보건 경영방침을 입력하세요.',
        'value' => '현대기전은 안전보건을 최우선 가치로 삼고, 모든 임직원과 종사자가 안전하게 일할 수 있는 환경을 조성한다.\n\n경영책임자는 안전보건 목표를 수립하고 필요한 인력과 예산을 지원하며, 법령과 기준을 준수하고 지속적으로 개선한다.',
    ],
    'goals' => [
        'title' => '안전보건 목표',
        'placeholder' => '올해의 안전보건 목표를 입력하세요.',
        'value' => '1. 중대재해 및 산업재해 예방\n2. 유해·위험요인의 확인과 개선\n3. 종사자 의견 청취 및 참여 확대\n4. 안전보건 교육과 점검의 내실화',
    ],
    'implementation' => [
        'title' => '실행 및 점검 계획',
        'placeholder' => '목표를 실행하고 점검할 방법을 입력하세요.',
        'value' => '안전보건 목표의 이행 결과를 정기적으로 점검하고, 점검 결과와 종사자 의견을 다음 연도 목표 및 실행계획에 반영한다.',
    ],
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전보건 경영방침 만들기</title>
    <style>
        :root {
            --blue: #17477f;
            --deep-blue: #103563;
            --line: #d7e1ef;
            --muted: #63738a;
            --paper: #fff;
            --bg: #f2f6fb;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: linear-gradient(180deg, #eaf1fa 0%, var(--bg) 260px);
            color: #1f2937;
            font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(180deg, var(--blue), var(--deep-blue));
            color: #fff;
            border-bottom: 4px solid #0c274b;
        }
        .topbar-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .brand small { display: block; color: rgba(255,255,255,.75); font-size: 12px; letter-spacing: .08em; }
        .brand strong { display: block; margin-top: 4px; font-size: 24px; }
        .user-chip { padding: 9px 13px; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; font-size: 13px; }
        main { max-width: 980px; margin: 28px auto; padding: 0 20px 52px; }
        .page-card { background: var(--paper); border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 18px 38px rgba(21,45,83,.07); overflow: hidden; }
        .page-head { padding: 28px 32px 22px; border-bottom: 1px solid var(--line); }
        .page-head h1 { margin: 0 0 8px; color: var(--deep-blue); font-size: 26px; }
        .page-head p { margin: 0; color: var(--muted); }
        .form-body { padding: 28px 32px; }
        .field { margin-bottom: 24px; }
        .field:last-child { margin-bottom: 0; }
        label { display: block; margin-bottom: 9px; color: var(--deep-blue); font-weight: 700; }
        textarea { width: 100%; min-height: 145px; padding: 14px 16px; border: 1px solid #b9c9de; border-radius: 10px; color: #24364e; font: inherit; line-height: 1.75; resize: vertical; }
        textarea:focus { outline: 3px solid rgba(23,71,127,.15); border-color: var(--blue); }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 26px; }
        .button, a.button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 16px; border: 1px solid var(--blue); border-radius: 9px; background: var(--blue); color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary, a.button.secondary { background: #fff; color: var(--blue); }
        .button:hover, a.button:hover { filter: brightness(1.08); }
        @media (max-width: 640px) {
            .topbar-inner, .page-head, .form-body { padding-left: 18px; padding-right: 18px; }
            .topbar-inner { align-items: flex-start; flex-direction: column; }
            .page-head h1 { font-size: 22px; }
            .actions { flex-direction: column-reverse; }
            .button, a.button { width: 100%; }
        }
        @media print {
            body { background: #fff; }
            .topbar, .actions { display: none; }
            main { margin: 0; max-width: none; padding: 0; }
            .page-card { border: 0; box-shadow: none; }
            .page-head, .form-body { padding-left: 0; padding-right: 0; }
            textarea { border: 0; padding: 0; resize: none; min-height: 0; overflow: visible; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <small>SAFETY MANAGEMENT SYSTEM</small>
            <strong>안전보건 경영방침 만들기</strong>
        </div>
        <div class="user-chip"><?= h((string)($user['name'] ?? '5878')) ?>님</div>
    </div>
</header>
<main>
    <form class="page-card" method="post" onsubmit="return false;">
        <div class="page-head">
            <h1>안전보건 경영방침</h1>
            <p>사업장의 안전보건 방향과 목표를 작성한 뒤 출력할 수 있습니다.</p>
        </div>
        <div class="form-body">
            <?php foreach ($policySections as $section): ?>
                <div class="field">
                    <label><?= h($section['title']) ?></label>
                    <textarea placeholder="<?= h($section['placeholder']) ?>"><?= h($section['value']) ?></textarea>
                </div>
            <?php endforeach; ?>
            <div class="actions">
                <a class="button secondary" href="index.php">매뉴얼로 돌아가기</a>
                <button class="button" type="button" onclick="window.print();">인쇄하기</button>
            </div>
        </div>
    </form>
</main>
</body>
</html>
