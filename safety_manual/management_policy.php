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
        'values' => ['중대재해 및 산업재해 예방', '유해·위험요인의 확인과 개선', '종사자 의견 청취 및 참여 확대', '안전보건 교육과 점검의 내실화', ''],
    ],
];
$currentYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$autoPrint = ($_GET['print'] ?? '') === '1';
$policyYears = [
    ['key' => 'previous', 'label' => '전년도', 'year' => $currentYear - 1],
    ['key' => 'current', 'label' => '당해년도', 'year' => $currentYear],
];
require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/policy_storage.php';
$_SESSION['safety_policy_csrf'] ??= bin2hex(random_bytes(32));
$message = $_SESSION['safety_policy_saved'] ?? '';
unset($_SESSION['safety_policy_saved']);
$errorMessage = '';
$yearValues = [];
foreach ($policyYears as $entry) {
    $yearValues[$entry['key']] = [
        'policy' => '',
        'date' => '',
        'goals' => array_fill(0, 5, ''),
    ];
}
try {
    $db = getDB();
    safety_policy_initialize($db);
    foreach ($policyYears as $entry) {
        $saved = safety_policy_load($db, $entry['year']);
        if ($saved !== null) $yearValues[$entry['key']] = $saved;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['safety_policy_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('저장 요청을 확인할 수 없습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.');
        }
        if ((string)($_POST['current_year'] ?? '') !== (string)$currentYear) {
            throw new InvalidArgumentException('연도가 변경되었습니다. 페이지를 새로고침한 뒤 해당 연도에 입력해 주세요.');
        }
        $records = [];
        foreach ($policyYears as $entry) {
            $records[$entry['year']] = safety_policy_validate($_POST[$entry['key']] ?? null);
        }
        safety_policy_save($db, $records, (string)$user['login_id']);
        $_SESSION['safety_policy_saved'] = '전년도와 당해년도 경영방침 및 목표를 저장했습니다.';
        header('Location: management_policy.php');
        exit;
    }
} catch (Throwable $error) {
    $errorMessage = $error instanceof InvalidArgumentException ? $error->getMessage() : 'DB에서 데이터를 불러오거나 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    if (!$error instanceof InvalidArgumentException) error_log('Safety policy DB failure: ' . $error->getMessage());
    // Keep entered text available when a save fails.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($policyYears as $entry) {
            $posted = $_POST[$entry['key']] ?? null;
            if (is_array($posted) && is_string($posted['policy'] ?? null) && is_array($posted['goals'] ?? null)) {
                $yearValues[$entry['key']] = ['policy' => $posted['policy'], 'date' => is_string($posted['date'] ?? null) ? $posted['date'] : '', 'goals' => array_values(array_filter($posted['goals'], 'is_string'))];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전보건 경영방침 만들기</title>
    <link rel="stylesheet" href="assets/policy-print.css">
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
            max-width: 1480px;
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
        main { max-width: 1480px; margin: 28px auto; padding: 0 20px 52px; }
        .year-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
        .page-card { min-width: 0; background: var(--paper); border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 18px 38px rgba(21,45,83,.07); overflow: hidden; }
        .year-label { display: inline-block; margin-bottom: 12px; padding: 5px 12px; border-radius: 999px; background: #edf2f7; color: #52647b; font-size: 14px; font-weight: 700; }
        .page-card.current { border-color: #9eb9db; }
        .page-card.current .page-head { background: #f5f9ff; }
        .page-card.current .year-label { background: var(--blue); color: #fff; }
        .page-head { padding: 28px 32px 22px; border-bottom: 1px solid var(--line); }
        .page-head h2 { margin: 0 0 8px; color: var(--deep-blue); font-size: 26px; }
        .page-head p { margin: 0; color: var(--muted); }
        .form-body { padding: 28px 32px; }
        .field { margin-bottom: 24px; }
        .field:last-child { margin-bottom: 0; }
        label { display: block; margin-bottom: 9px; color: var(--deep-blue); font-weight: 700; }
        textarea { width: 100%; min-height: 145px; padding: 14px 16px; border: 1px solid #b9c9de; border-radius: 10px; color: #24364e; font: inherit; line-height: 1.75; resize: vertical; }
        textarea:focus { outline: 3px solid rgba(23,71,127,.15); border-color: var(--blue); }
        .policy-date { width: 100%; min-width: 0; min-height: 44px; padding: 10px 14px; border: 1px solid #b9c9de; border-radius: 10px; color: #24364e; background: #fff; font: inherit; }
        .policy-date:focus { outline: 3px solid rgba(23,71,127,.15); border-color: var(--blue); }
        .goals-group { min-width: 0; margin: 0; padding: 0; border: 0; }
        .goals-group legend { margin-bottom: 9px; padding: 0; color: var(--deep-blue); font-weight: 700; }
        .goal-list { display: grid; gap: 10px; }
        .goal-row { display: flex; align-items: flex-start; gap: 10px; break-inside: avoid; }
        .goal-row label { flex: 0 0 24px; margin: 13px 0 0; text-align: center; }
        .goal-row textarea { min-width: 0; min-height: 54px; padding: 11px 12px; }
        .goal-add { margin-top: 12px; }
        .notice { padding: 14px 18px; margin-bottom: 20px; border-radius: 10px; background: #e7f4ed; color: #185a37; }
        .notice.error { background: #fff0f0; color: #9b2626; }
        .actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin-top: 26px; }
        .button, a.button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 16px; border: 1px solid var(--blue); border-radius: 9px; background: var(--blue); color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary, a.button.secondary { background: #fff; color: var(--blue); }
        .button:hover, a.button:hover { filter: brightness(1.08); }
        @media (max-width: 900px) {
            .year-cards { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 640px) {
            .topbar-inner, .page-head, .form-body { padding-left: 18px; padding-right: 18px; }
            .topbar-inner { align-items: flex-start; flex-direction: column; }
            .page-head h2 { font-size: 22px; }
            .actions { flex-direction: column-reverse; }
            .button, a.button { width: 100%; }
        }
        @media print {
            body { background: #fff; }
            .topbar, .actions, .goal-add, .notice { display: none; }
            main { margin: 0; max-width: none; padding: 0; }
            .year-cards { display: block; }
            .page-card { border: 0; box-shadow: none; }
            .page-card + .page-card { break-before: page; }
            .page-head, .form-body { padding-left: 0; padding-right: 0; }
            textarea { border: 0; padding: 0; resize: none; min-height: 0; overflow: visible; }
        }
    </style>
</head>
<body data-auto-print="<?= $autoPrint ? '1' : '0' ?>">
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
    <?php if ($errorMessage !== ''): ?><div class="notice error" role="alert"><?= h($errorMessage) ?></div>
    <?php elseif ($message !== ''): ?><div class="notice" role="status"><?= h($message) ?></div><?php endif; ?>
    <form method="post" action="management_policy.php" id="policy-form">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['safety_policy_csrf']) ?>">
    <input type="hidden" name="current_year" value="<?= $currentYear ?>">
    <div class="year-cards">
    <?php foreach ($policyYears as $policyYear): ?>
    <section class="page-card <?= h($policyYear['key']) ?>" aria-labelledby="heading-<?= h($policyYear['key']) ?>">
        <div class="page-head">
            <span class="year-label"><?= h($policyYear['label']) ?> · <?= $policyYear['year'] ?>년</span>
            <h2 id="heading-<?= h($policyYear['key']) ?>">안전보건 경영방침</h2>
            <p><?= $policyYear['key'] === 'previous' ? '전년도의 안전보건 경영방침과 목표를 입력하세요.' : '전년도 내용을 참고하여 당해년도의 경영방침과 목표를 작성하세요.' ?></p>
        </div>
        <div class="form-body">
            <div class="field">
                <label for="<?= h($policyYear['key']) ?>-date">날짜</label>
                <input class="policy-date" type="date" id="<?= h($policyYear['key']) ?>-date" name="<?= h($policyYear['key']) ?>[date]" min="1000-01-01" max="9999-12-31" value="<?= h($yearValues[$policyYear['key']]['date']) ?>">
            </div>
            <?php foreach ($policySections as $sectionKey => $section): ?>
                <?php $fieldId = $policyYear['key'] . '-' . $sectionKey; ?>
                <div class="field">
                    <?php if ($sectionKey === 'goals'): ?>
                    <fieldset class="goals-group" data-year="<?= h($policyYear['key']) ?>" data-year-label="<?= h($policyYear['label']) ?>">
                        <legend><?= h($section['title']) ?></legend>
                        <div class="goal-list">
                            <?php foreach (array_pad($yearValues[$policyYear['key']]['goals'], 5, '') as $goalIndex => $goalValue): ?>
                                <?php $goalNumber = $goalIndex + 1; $goalId = $fieldId . '-' . $goalNumber; ?>
                                <div class="goal-row">
                                    <label for="<?= h($goalId) ?>" aria-label="<?= h($policyYear['label']) ?> 안전보건 목표 <?= $goalNumber ?>"><?= $goalNumber ?>.</label>
                                    <textarea rows="1" maxlength="2000" id="<?= h($goalId) ?>" name="<?= h($policyYear['key']) ?>[goals][]" placeholder="목표 <?= $goalNumber ?>을 입력하세요."><?= h($goalValue) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="button secondary goal-add" type="button">+ 목표 추가</button>
                    </fieldset>
                    <?php else: ?>
                    <label for="<?= h($fieldId) ?>"><?= h($section['title']) ?></label>
                    <textarea maxlength="20000" id="<?= h($fieldId) ?>" name="<?= h($policyYear['key']) ?>[<?= h($sectionKey) ?>]" placeholder="<?= h($policyYear['label'] . ' ' . $section['title'] . '을 입력하세요.') ?>"><?= h($yearValues[$policyYear['key']]['policy']) ?></textarea>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
    </div>
    <div class="actions">
        <a class="button secondary" href="index.php">매뉴얼로 돌아가기</a>
        <a class="button secondary" href="policy_review.php">안전보건방침 및 목표 검토 보고서</a>
        <button class="button" type="submit" id="policy-save">저장하기</button>
        <button class="button" type="button" id="policy-preview-open">인쇄하기</button>
    </div>
    </form>
</main>
<dialog id="policy-preview" aria-labelledby="policy-preview-title">
    <div class="policy-preview-toolbar">
        <strong id="policy-preview-title">경영방침 인쇄 미리보기</strong>
        <label for="policy-print-year">인쇄 대상</label>
        <select id="policy-print-year">
            <?php foreach ($policyYears as $entry): ?>
                <option value="<?= h($entry['key']) ?>" data-year="<?= $entry['year'] ?>" <?= $entry['key'] === 'current' ? 'selected' : '' ?>><?= h($entry['label']) ?> (<?= $entry['year'] ?>년)</option>
            <?php endforeach; ?>
            <option value="both">전년도와 당해년도 모두</option>
        </select>
        <button class="button" type="button" id="policy-preview-print">인쇄 / PDF 저장</button>
        <button class="button secondary" type="button" id="policy-preview-close">닫기</button>
    </div>
    <div id="policy-print-pages"></div>
</dialog>
<script src="assets/policy-print.js?v=<?= filemtime(__DIR__ . '/assets/policy-print.js') ?>"></script>
<script>
    function resizeGoal(field) {
        field.style.height = 'auto';
        field.style.height = (field.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('.goals-group').forEach(function (group) {
        var list = group.querySelector('.goal-list');
        list.addEventListener('input', function (event) {
            if (event.target.matches('textarea')) resizeGoal(event.target);
        });
        group.querySelector('.goal-add').addEventListener('click', function () {
            if (list.children.length >= 200) {
                alert('목표는 최대 200개까지 추가할 수 있습니다.');
                return;
            }
            var number = list.children.length + 1;
            var id = group.dataset.year + '-goals-' + number;
            var row = document.createElement('div');
            row.className = 'goal-row';
            var label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = number + '.';
            label.setAttribute('aria-label', group.dataset.yearLabel + ' 안전보건 목표 ' + number);
            var field = document.createElement('textarea');
            field.id = id;
            field.name = group.dataset.year + '[goals][]';
            field.rows = 1;
            field.maxLength = 2000;
            field.placeholder = '목표 ' + number + '을 입력하세요.';
            row.append(label, field);
            list.appendChild(row);
            resizeGoal(field);
            field.focus();
        });
    });
    function resizeAllGoals() {
        document.querySelectorAll('.goal-row textarea').forEach(resizeGoal);
    }
    resizeAllGoals();
    window.addEventListener('resize', resizeAllGoals);
    window.addEventListener('beforeprint', resizeAllGoals);
    window.addEventListener('afterprint', resizeAllGoals);
    document.getElementById('policy-form').addEventListener('submit', function () {
        var button = document.getElementById('policy-save');
        button.disabled = true;
        button.textContent = '저장 중…';
    });
</script>
</body>
</html>
