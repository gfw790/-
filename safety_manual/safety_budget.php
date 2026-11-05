<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/safety_budget_storage.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

$isAllowed = auth_can_manage($user) && trim((string)($user['login_id'] ?? '')) === '5878';
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

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$currentYear = (int)$now->format('Y');
$currentMonth = (int)$now->format('n');

$categories = safety_budget_categories();

$errorMessage = '';
$generalInfo = safety_budget_general_defaults($currentYear);
$categoryTotals = array_fill_keys(array_keys($categories), 0);
$previousCategoryTotals = array_fill_keys(array_keys($categories), 0);
$emptyDetailRow = ['item_name' => '', 'unit' => '', 'quantity' => 0, 'unit_price' => 0, 'amount' => 0, 'calculation_note' => '', 'usage_period' => ''];
$detailGrid = array_fill_keys(array_keys($categories), [$emptyDetailRow]);
$usageRows = [];
try {
    $db = getDB();
    safety_budget_initialize($db);
    $generalInfo = safety_budget_general_load($db, $currentYear) ?? safety_budget_general_defaults($currentYear);
    $categoryTotals = safety_budget_category_totals($db, $currentYear);
    $previousCategoryTotals = safety_budget_category_totals($db, $currentYear - 1);
    $detailGrid = safety_budget_detail_load($db, $currentYear);
    $usageRows = safety_budget_usage_load($db, $currentYear, $currentMonth);
} catch (Throwable $error) {
    $errorMessage = 'DB에서 데이터를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.';
    error_log('Safety budget page load failure: ' . $error->getMessage());
}

$preparedDateDisplay = '';
if ((string)$generalInfo['prepared_date'] !== '') {
    $preparedDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$generalInfo['prepared_date']);
    if ($preparedDateObj) {
        $preparedDateDisplay = $preparedDateObj->format('Y') . '년   ' . $preparedDateObj->format('m') . ' 월   ' . $preparedDateObj->format('d') . ' 일';
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['safety_budget_csrf'] ??= bin2hex(random_bytes(32));
$csrfToken = $_SESSION['safety_budget_csrf'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>안전보건관리비 작성하기</title>
<style>
    :root {
        --blue: #17477f;
        --deep-blue: #103563;
        --line: #d7e1ef;
        --muted: #63738a;
        --bg: #f2f6fb;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        background: linear-gradient(180deg, #eaf1fa 0%, var(--bg) 260px);
        color: #1f2937;
        font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
    }
    header {
        padding: 22px 28px;
        background: var(--deep-blue);
        color: #fff;
    }
    header small { letter-spacing: .08em; opacity: .8; }
    header h1 { margin: 4px 0 0; font-size: 22px; }
    main { width: 100%; margin: 0 auto; padding: 24px 20px 60px; }
    .top-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .year-lookup { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
    .year-lookup select {
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 5px 8px;
        font: inherit;
        color: #1f2937;
        background: #fff;
    }
    .top-actions a { color: var(--blue); font-weight: 600; text-decoration: none; }
    .top-actions a:hover { text-decoration: underline; }
    .notice.error {
        background: #fdecec; border: 1px solid #f2b8b8; color: #a41c1c;
        padding: 12px 16px; border-radius: 10px; margin-bottom: 16px;
    }
    .budget-layout {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: flex-start;
    }
    .budget-card {
        flex: 1 1 460px;
        min-width: 340px;
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 22px 24px 26px;
        box-shadow: 0 1px 3px rgba(16, 53, 99, .06);
        overflow-x: hidden;
    }
    .budget-card h2 {
        margin: 0 0 4px;
        font-size: 18px;
        color: var(--deep-blue);
    }
    .budget-card .card-sub {
        margin: 0 0 16px;
        font-size: 12.5px;
        color: var(--muted);
    }

    /* ---- Official document (front page) styles ---- */
    .doc-header { text-align: center; margin-bottom: 14px; }
    .doc-formno { margin: 0 0 12px; font-size: 11.5px; color: var(--muted); text-align: left; }
    .doc-title { margin: 4px 0; font-size: 20px; color: var(--deep-blue); }
    .doc-page-mark { margin: 0; font-size: 12px; color: var(--muted); text-align: right; }
    table.doc-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        font-size: 12.5px;
        margin-bottom: 16px;
    }
    table.doc-table th, table.doc-table td {
        border: 1px solid #9fb2c9;
        padding: 3px 8px;
    }
    table.doc-table th {
        background: #eef3fa;
        color: var(--deep-blue);
        font-weight: 700;
        text-align: center;
        white-space: nowrap;
    }
    table.doc-table td { text-align: left; }
    table.doc-table th.section-th {
        background: var(--deep-blue);
        color: #fff;
        text-align: left;
        font-size: 13.5px;
    }
    .doc-hint { font-weight: 400; font-size: 10.5px; color: var(--muted); }
    table.doc-table input[type="text"],
    table.doc-table input[type="number"],
    table.doc-table input[type="date"] {
        width: 100%;
        border: 1px solid transparent;
        background: transparent;
        font: inherit;
        padding: 3px 4px;
        border-radius: 4px;
    }
    table.doc-table input[type="number"] { text-align: right; }
    .nowrap-cell { white-space: nowrap; overflow: hidden; }
    table.doc-table td.nowrap-cell input[type="date"] { width: 125px; display: inline-block; flex: none; }
    table.doc-table td.nowrap-cell input[type="date"]::-webkit-calendar-picker-indicator {
        width: 10px;
        height: 10px;
        padding: 1px;
    }
    table.doc-table td.nowrap-cell input[type="number"] { width: 80px; display: inline-block; flex: none; }
    .inline-options {
        display: flex;
        align-items: center;
        gap: 16px;
        white-space: nowrap;
    }
    table.doc-table input:hover, table.doc-table input:focus {
        border-color: var(--line);
        background: #fbfdff;
        outline: none;
    }
    table.doc-table label {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }
    table.category-table td, table.category-table th { text-align: center; }
    table.category-table tbody td:first-child { text-align: left; white-space: nowrap; }
    table.category-table th { white-space: nowrap; }
    table.category-table tfoot th, table.category-table tfoot td {
        background: #f7fafd;
        font-weight: 700;
    }
    .readonly-cell { color: var(--deep-blue); font-weight: 700; text-align: right; }
    .doc-footnote { font-size: 12px; color: var(--muted); margin: 0 0 24px; }
    .doc-date-line { font-size: 13px; text-align: center; margin: 0 0 20px; }
    .doc-signature-line {
        font-size: 13px;
        margin: 18px 0;
        text-align: right;
    }
    .doc-signature-line > span { display: block; max-width: 100%; white-space: nowrap; }
    .doc-signature-line > span:first-child { margin-bottom: 20px; }
    .doc-paper-note {
        font-size: 10.5px;
        color: var(--muted);
        margin: 0;
        text-align: right;
        border-top: 1px solid #9fb2c9;
        padding-top: 1px;
    }
    .print-spacer { display: none; }
    .print-only-footer { display: none; }
    .doc-signature {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        font-size: 13px;
        margin-bottom: 6px;
    }
    .doc-signature input[type="date"],
    .doc-signature input[type="text"] {
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 4px 8px;
        font: inherit;
    }
    .doc-signature-author { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .doc-signature-author input[type="text"] { width: 120px; }

    .card-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 14px;
        gap: 8px;
        flex-wrap: wrap;
    }
    .doc-paper-note + .card-actions { margin-top: 0; }
    .card-actions .left-group { display: flex; gap: 8px; align-items: center; }
    button.plain-btn, a.plain-btn {
        display: inline-block;
        border: 1px solid var(--line);
        background: #f5f8fc;
        color: var(--blue);
        font-weight: 700;
        padding: 7px 14px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
        text-decoration: none;
    }
    button.plain-btn:hover, a.plain-btn:hover { background: #eaf1fa; }
    button.primary-btn {
        border: 1px solid var(--blue);
        background: var(--blue);
        color: #fff;
        font-weight: 700;
        padding: 7px 16px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
    }
    button.primary-btn:hover { background: var(--deep-blue); }
    .save-status {
        font-size: 12.5px;
        color: var(--muted);
        min-height: 16px;
    }
    .save-status.ok { color: #147a3c; }
    .save-status.error { color: #a41c1c; }

    /* ---- Usage card ---- */
    table.budget-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    table.budget-table th, table.budget-table td {
        border: 1px solid var(--line);
        padding: 6px 8px;
        text-align: center;
    }
    table.budget-table th { background: #eef3fa; color: var(--deep-blue); font-weight: 700; }
    table.budget-table input {
        width: 100%;
        border: 1px solid transparent;
        background: transparent;
        font: inherit;
        text-align: inherit;
        padding: 3px 4px;
        border-radius: 4px;
    }
    table.budget-table input:hover, table.budget-table input:focus {
        border-color: var(--line);
        background: #fbfdff;
        outline: none;
    }
    table.budget-table input[type="number"] { text-align: right; }
    table.budget-table tfoot td { font-weight: 700; background: #f7fafd; }
    .row-remove {
        border: none;
        background: none;
        color: #b02a2a;
        cursor: pointer;
        font-size: 13px;
    }
    .month-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin: 18px 0 12px;
        border-top: 1px solid var(--line);
        padding-top: 16px;
    }
    .month-tab {
        border: 1px solid var(--line);
        background: #f5f8fc;
        color: var(--blue);
        font-weight: 600;
        padding: 6px 0;
        width: 52px;
        border-radius: 20px;
        cursor: pointer;
        font-size: 12.5px;
    }
    .month-tab:hover { background: #eaf1fa; }
    .month-tab.is-active {
        background: var(--blue);
        color: #fff;
        border-color: var(--blue);
    }
    .usage-view-box {
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 14px 6px;
        background: #fafcff;
        position: relative;
        min-height: 120px;
    }
    .usage-view-box.is-loading::after {
        content: '불러오는 중...';
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.7);
        color: var(--muted);
        font-size: 13px;
        border-radius: 12px;
    }

    /* ---- Plan card flip (front: 사용계획서 / back: 세부 사용계획) ---- */
    .flip-scene {
        flex: 1 1 460px;
        min-width: 340px;
        perspective: 2000px;
        transition: height .35s ease;
    }
    .flip-card {
        position: relative;
        width: 100%;
        height: 100%;
        transition: transform .7s cubic-bezier(.4, .2, .2, 1);
        transform-style: preserve-3d;
    }
    .flip-card.is-flipped { transform: rotateY(180deg); }
    .flip-face {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 22px 24px 26px;
        box-shadow: 0 1px 3px rgba(16, 53, 99, .06);
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
    }
    .flip-face-front { position: relative; }
    .flip-face-back {
        position: absolute;
        inset: 0;
        transform: rotateY(180deg);
        overflow-y: auto;
        pointer-events: none;
    }
    .flip-card.is-flipped .flip-face-back { pointer-events: auto; }
    .flip-card.is-flipped .flip-face-front { pointer-events: none; }

    /* ---- Detail plan (back face) ---- */
    table.detail-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        font-size: 12.5px;
        margin-bottom: 18px;
    }
    table.detail-table th, table.detail-table td {
        border: 1px solid #9fb2c9;
        padding: 6px 4px;
        text-align: center;
        overflow: hidden;
    }
    table.detail-table th { background: #eef3fa; color: var(--deep-blue); font-weight: 700; }
    table.detail-table th.section-th {
        background: var(--deep-blue);
        color: #fff;
        text-align: left;
        font-size: 13.5px;
    }
    table.detail-table td.category-cell { background: #f7fafd; font-weight: 700; color: var(--deep-blue); }
    table.detail-table td.field-calculation-note { font-size: 10px; }
    table.detail-table td.detail-cell.field-unit { text-align: center; }
    .category-cell-subtotal { display: inline-block; margin-top: 4px; font-size: 10px; font-weight: 400; color: var(--muted); }
    table.detail-table td.detail-cell {
        cursor: pointer;
        text-align: left;
    }
    table.detail-table td.detail-cell.is-empty { color: #b7c2d0; font-style: italic; }
    table.detail-table td.detail-cell.is-empty::before { content: '클릭하여 입력'; }
    table.detail-table td.detail-cell:hover { background: #f5f8fc; }
    table.detail-table td.num-cell { text-align: right; }
    table.detail-table tfoot td { background: #f7fafd; font-weight: 700; }
    tr.add-row-trigger td { background: #fbfdff; padding: 4px 8px; text-align: left; }
    .add-row-btn {
        border: 1px dashed var(--blue);
        background: none;
        color: var(--blue);
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
    }
    .add-row-btn:hover { background: #eaf1fa; }

    .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(16, 30, 50, .45);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 50;
    }
    .modal-backdrop[hidden] { display: none; }
    .modal-box {
        background: #fff;
        border-radius: 14px;
        padding: 22px 24px;
        width: 100%;
        max-width: 460px;
        box-shadow: 0 12px 32px rgba(16, 30, 50, .25);
    }
    .modal-box h3 { margin: 0 0 4px; color: var(--deep-blue); font-size: 17px; }
    .modal-box .modal-category { margin: 0 0 16px; font-size: 12.5px; color: var(--muted); }
    .modal-grid { display: flex; flex-direction: column; gap: 10px; }
    .modal-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--muted); }
    .modal-grid input {
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 7px 9px;
        font: inherit;
    }
    .modal-actions { display: flex; justify-content: space-between; gap: 8px; margin-top: 18px; flex-wrap: wrap; }
    .modal-actions .right-group, .modal-actions .left-group { display: flex; gap: 8px; }
    button.danger-btn {
        border: 1px solid #e3b3b3;
        background: #fdf2f2;
        color: #b02a2a;
        font-weight: 700;
        padding: 7px 14px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
    }
    button.danger-btn:hover { background: #fbe4e4; }

    @media print {
        @page { size: A4; margin: 0; }
        header, .top-actions, .card-actions, #usage-card, .notice { display: none !important; }
        .print-hide-row { display: none !important; }
        tr.add-row-trigger { display: none !important; }
        table.detail-table td.detail-cell.is-empty::before { content: ''; }
        input[type="date"]::-webkit-calendar-picker-indicator { display: none !important; }
        input[type="date"] { -webkit-appearance: none; appearance: none; }
        body { background: #fff; padding: 2cm 2cm 0.5cm !important; margin: 0 !important; }
        main { padding: 0 !important; margin: 0 !important; max-width: none !important; }
        .budget-layout { display: block; }
        .budget-card { border: none; box-shadow: none; padding: 0; }
        .flip-card { transform: none !important; }
        .flip-face-back { display: none !important; }
        .flip-face-front {
            position: relative;
            border: none;
            box-shadow: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 27cm;
        }
        .print-only-footer {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
        }
        .print-spacer { display: block; flex-shrink: 0; }
        .print-spacer-a { flex: 1 0 0; }
        .print-spacer-b { flex: 1.4 0 0; }
        .print-spacer-c { flex: 0.6 0 0; }
        .flip-card.is-flipped .flip-face-front { display: none !important; }
        .flip-card.is-flipped .flip-face-back {
            display: block !important;
            position: relative;
            transform: none !important;
            border: none;
            box-shadow: none;
            padding: 0;
        }
    }
</style>
</head>
<body>
<header>
    <small>SAFETY MANAGEMENT SYSTEM</small>
    <h1>안전보건관리비 작성하기</h1>
</header>
<main>
    <div class="top-actions">
        <div class="year-lookup">
            <label for="plan-year-select">조회 연도</label>
            <select id="plan-year-select">
                <?php for ($y = $currentYear - 5; $y <= $currentYear + 1; $y++): ?>
                    <option value="<?= $y ?>"<?= $y === $currentYear ? ' selected' : '' ?>><?= $y ?>년</option>
                <?php endfor; ?>
            </select>
        </div>
        <a href="index.php">매뉴얼로 돌아가기</a>
    </div>
    <?php if ($errorMessage): ?><div role="alert" class="notice error"><?= h($errorMessage) ?></div><?php endif; ?>

    <div class="budget-layout">
        <div class="flip-scene" id="plan-flip-scene">
        <div class="flip-card" id="plan-flip-card">
        <section class="budget-card flip-face flip-face-front" id="plan-card" data-year="<?= $currentYear ?>">
            <div class="doc-header">
                <p class="doc-formno">■ 산업안전보건법 시행규칙 [별지 제102호서식] &lt;개정 2025. 5. 30.&gt;</p>
                <h2 class="doc-title">산업안전보건관리비 사용계획서</h2>
                <p class="doc-page-mark">(앞 쪽)</p>
            </div>

            <table class="doc-table">
                <colgroup>
                    <col style="width:16%"><col style="width:34%"><col style="width:16%"><col style="width:34%">
                </colgroup>
                <tbody>
                    <tr><th colspan="4" class="section-th">1. 일반사항</th></tr>
                    <tr>
                        <th>회사명</th>
                        <td><input type="text" id="gi-company-name" value="<?= h((string)$generalInfo['company_name']) ?>"></td>
                        <th>계획년도</th>
                        <td class="nowrap-cell"><input type="number" min="1000" max="9999" step="1" id="gi-plan-year" value="<?= (int)$generalInfo['plan_year'] ?>"> 년</td>
                    </tr>
                    <tr>
                        <th>계획기간</th>
                        <td colspan="3" class="nowrap-cell"><input type="date" id="gi-period-start" value="<?= h((string)$generalInfo['period_start']) ?>"> ~ <input type="date" id="gi-period-end" value="<?= h((string)$generalInfo['period_end']) ?>"></td>
                    </tr>
                    <tr>
                        <th>총 계획금액</th>
                        <td class="readonly-cell" id="gi-total-planned-amount">0 원</td>
                        <th>계획구분</th>
                        <td class="inline-options">
                            <label><input type="checkbox" id="gi-plan-type-annual"<?= $generalInfo['plan_type_annual'] ? ' checked' : '' ?>> 연간</label>
                            <label><input type="checkbox" id="gi-plan-type-change"<?= $generalInfo['plan_type_change'] ? ' checked' : '' ?>> 변경</label>
                        </td>
                    </tr>
                    <tr class="print-hide-row">
                        <th>작성</th>
                        <td>안전보건관리담당자 <input type="text" id="gi-preparer-name" value="<?= h((string)$generalInfo['preparer_name']) ?>" placeholder="성명" style="width:90px"> (서명)</td>
                        <th>검토/승인</th>
                        <td>대표이사 <input type="text" id="gi-reviewer-name" value="<?= h((string)$generalInfo['reviewer_name']) ?>" placeholder="성명" style="width:90px"> (서명)</td>
                    </tr>
                    <tr class="print-hide-row">
                        <th>작성일</th>
                        <td colspan="3"><input type="date" id="gi-prepared-date" value="<?= h((string)$generalInfo['prepared_date']) ?>"></td>
                    </tr>
                </tbody>
            </table>

            <table class="doc-table category-table">
                <colgroup>
                    <col style="width:44%"><col style="width:20%"><col style="width:20%"><col style="width:16%">
                </colgroup>
                <thead>
                    <tr><th colspan="4" class="section-th">2. 항목별 실행계획</th></tr>
                    <tr><th>항목</th><th>전년도</th><th>금년도</th><th>비율(%)</th></tr>
                </thead>
                <tbody id="category-table-body">
                    <?php foreach ($categories as $key => $label): ?>
                        <tr data-category="<?= h($key) ?>" data-amount="<?= (int)$categoryTotals[$key] ?>">
                            <td><?= h($label) ?></td>
                            <td class="readonly-cell category-previous-amount-cell"><?= number_format((int)$previousCategoryTotals[$key]) ?></td>
                            <td class="readonly-cell category-amount-cell"><?= number_format((int)$categoryTotals[$key]) ?></td>
                            <td class="category-ratio-cell">0%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th>총계</th><td class="readonly-cell" id="category-total-previous-amount"><?= number_format(array_sum($previousCategoryTotals)) ?></td><td class="readonly-cell" id="category-total-amount">0</td><td id="category-total-ratio">100%</td></tr>
                </tfoot>
            </table>

            <div class="print-only-footer">
                <p class="doc-footnote">『건설업 산업안전보건관리비 계상 및 사용기준』 제10조 1항에 따라 위와 같이 사용계획서를 작성하였습니다.</p>
                <div class="print-spacer print-spacer-a"></div>
                <p class="doc-date-line" id="doc-date-line"><?= h($preparedDateDisplay) ?></p>
                <div class="print-spacer print-spacer-b"></div>
                <p class="doc-signature-line">
                    <span>작성자 직책 : 안전보건관리담당자              성명: <span id="doc-preparer-name-echo"><?= h((string)$generalInfo['preparer_name']) ?></span> (서명 또는 인)</span>
                    <span>검토/승인 : 대표이사              성명: <span id="doc-reviewer-name-echo"><?= h((string)$generalInfo['reviewer_name']) ?></span> (서명 또는 인)</span>
                </p>
                <div class="print-spacer print-spacer-c"></div>
                <p class="doc-paper-note">210㎜×297㎜[일반용지 60g/㎡(재활용품)]</p>
            </div>

            <div class="card-actions">
                <div class="left-group">
                    <button type="button" class="plain-btn" id="detail-flip-open">세부사용계획서 작성하기</button>
                    <button type="button" class="plain-btn" id="general-print">인쇄</button>
                </div>
                <div>
                    <span class="save-status" id="general-save-status"></span>
                    <button type="button" class="primary-btn" id="general-save">저장</button>
                </div>
            </div>
        </section>

        <section class="budget-card flip-face flip-face-back" id="detail-card" data-year="<?= $currentYear ?>">
            <div class="doc-header">
                <p class="doc-page-mark">(뒤 쪽)</p>
            </div>

            <table class="detail-table">
                <colgroup>
                    <col style="width:24%"><col style="width:16%"><col style="width:6%"><col style="width:7%"><col style="width:12%"><col style="width:21%"><col style="width:14%">
                </colgroup>
                <thead>
                    <tr><th colspan="7" class="section-th">3. 세부 사용계획</th></tr>
                    <tr><th>항목</th><th>세부항목</th><th>단위</th><th>수량</th><th>금액</th><th>산출명세</th><th>사용시기</th></tr>
                </thead>
                <tbody id="detail-table-body">
                    <?php foreach ($categories as $categoryKey => $categoryLabel): ?>
                        <?php $count = count($detailGrid[$categoryKey]); ?>
                        <?php for ($i = 0; $i < $count; $i++): ?>
                            <?php $row = $detailGrid[$categoryKey][$i] ?? $emptyDetailRow; ?>
                            <tr data-category="<?= h($categoryKey) ?>" data-sort-order="<?= $i ?>"
                                data-item-name="<?= h((string)$row['item_name']) ?>"
                                data-unit="<?= h((string)$row['unit']) ?>"
                                data-quantity="<?= h((string)$row['quantity']) ?>"
                                data-unit-price="<?= h((string)$row['unit_price']) ?>"
                                data-amount="<?= h((string)$row['amount']) ?>"
                                data-calculation-note="<?= h((string)$row['calculation_note']) ?>"
                                data-usage-period="<?= h((string)$row['usage_period']) ?>">
                                <?php if ($i === 0): ?><td class="category-cell" id="category-cell-<?= h($categoryKey) ?>" rowspan="<?= $count ?>"><?= h($categoryLabel) ?><br><span class="category-cell-subtotal" id="category-subtotal-<?= h($categoryKey) ?>">소계 <?= number_format((int)$categoryTotals[$categoryKey]) ?>원</span></td><?php endif; ?>
                                <td class="detail-cell field-item-name<?= $row['item_name'] === '' ? ' is-empty' : '' ?>"><?= h((string)$row['item_name']) ?></td>
                                <td class="detail-cell field-unit<?= $row['unit'] === '' ? ' is-empty' : '' ?>"><?= h((string)$row['unit']) ?></td>
                                <td class="detail-cell num-cell field-quantity<?= (float)$row['quantity'] === 0.0 && $row['item_name'] === '' ? ' is-empty' : '' ?>"><?= $row['item_name'] === '' ? '' : rtrim(rtrim(number_format((float)$row['quantity'], 2), '0'), '.') ?></td>
                                <td class="detail-cell num-cell field-amount<?= (int)$row['amount'] === 0 && $row['item_name'] === '' ? ' is-empty' : '' ?>"><?= $row['item_name'] === '' ? '' : number_format((int)$row['amount']) ?></td>
                                <td class="detail-cell field-calculation-note<?= $row['calculation_note'] === '' ? ' is-empty' : '' ?>"><?= h((string)$row['calculation_note']) ?></td>
                                <td class="detail-cell field-usage-period<?= $row['usage_period'] === '' ? ' is-empty' : '' ?>"><?= h((string)$row['usage_period']) ?></td>
                            </tr>
                        <?php endfor; ?>
                        <tr class="add-row-trigger" data-category="<?= h($categoryKey) ?>">
                            <td colspan="6"><button type="button" class="add-row-btn" data-category="<?= h($categoryKey) ?>">+ 세부항목 행 추가</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="4">총계</td><td class="num-cell" id="detail-total-amount">0</td><td colspan="2"></td></tr>
                </tfoot>
            </table>

            <div class="card-actions">
                <div class="left-group">
                    <button type="button" class="plain-btn" id="detail-flip-close">← 사용계획서로 돌아가기</button>
                    <button type="button" class="plain-btn" id="detail-print">인쇄</button>
                </div>
                <div>
                    <span class="save-status" id="detail-save-status"></span>
                    <button type="button" class="primary-btn" id="detail-save">저장</button>
                </div>
            </div>
        </section>
        </div>
        </div>

        <div class="modal-backdrop" id="detail-modal-backdrop" hidden>
            <div class="modal-box">
                <h3>세부항목 입력</h3>
                <p class="modal-category" id="detail-modal-category"></p>
                <div class="modal-grid">
                    <label>세부항목명<input type="text" id="modal-item-name" placeholder="예: 전선거치대"></label>
                    <label>단위<input type="text" id="modal-unit" placeholder="예: ea"></label>
                    <label>수량<input type="number" min="0" step="0.01" id="modal-quantity" placeholder="0"></label>
                    <label>단가<input type="number" min="0" step="1" id="modal-unit-price" placeholder="0"></label>
                    <label>금액(계산)<input type="number" min="0" step="1" id="modal-amount" placeholder="0"></label>
                    <label>산출명세<input type="text" id="modal-calculation-note" placeholder="예: 3,000*1,000ea"></label>
                    <label>사용시기<input type="text" id="modal-usage-period" placeholder="예: 25. 08.~"></label>
                </div>
                <div class="modal-actions">
                    <div class="left-group">
                        <button type="button" class="plain-btn" id="modal-clear">비우기</button>
                        <button type="button" class="danger-btn" id="modal-delete">행 삭제</button>
                    </div>
                    <div class="right-group">
                        <button type="button" class="plain-btn" id="modal-cancel">취소</button>
                        <button type="button" class="primary-btn" id="modal-confirm">확인</button>
                    </div>
                </div>
            </div>
        </div>

        <section class="budget-card" id="usage-card" data-year="<?= $currentYear ?>" data-month="<?= $currentMonth ?>">
            <h2 id="usage-title"><?= $currentYear ?>년 <?= $currentMonth ?>월 산업안전보건관리비 사용내역서</h2>
            <p class="card-sub">월별 탭을 눌러 해당 월의 사용내역을 조회하고 입력합니다.</p>

            <div class="usage-view-box" id="usage-view-box">
                <table class="budget-table" id="usage-table">
                    <thead>
                        <tr><th style="width:14%">집행일자</th><th style="width:22%">항목</th><th>내용</th><th style="width:16%">금액(원)</th><th style="width:16%">비고</th><th style="width:34px"></th></tr>
                    </thead>
                    <tbody id="usage-table-body"></tbody>
                    <tfoot>
                        <tr><td colspan="3">합계</td><td id="usage-total">0</td><td colspan="2"></td></tr>
                    </tfoot>
                </table>
                <div class="card-actions">
                    <div class="left-group">
                        <button type="button" class="plain-btn" id="usage-add-row">+ 내역 추가</button>
                    </div>
                    <div>
                        <span class="save-status" id="usage-save-status"></span>
                        <button type="button" class="primary-btn" id="usage-save">저장</button>
                    </div>
                </div>
            </div>

            <div class="month-tabs" id="month-tabs" role="tablist" aria-label="조회할 월 선택">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <button type="button" class="month-tab<?= $m === $currentMonth ? ' is-active' : '' ?>" data-month="<?= $m ?>" role="tab" aria-selected="<?= $m === $currentMonth ? 'true' : 'false' ?>"><?= $m ?>월</button>
                <?php endfor; ?>
            </div>
        </section>
    </div>
</main>
<script id="usage-initial-data" type="application/json"><?= json_encode($usageRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script>
(function () {
    var csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
    var generalYear = <?= $currentYear ?>;
    var usageState = { year: <?= $currentYear ?>, month: <?= $currentMonth ?> };

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatNumber(value) {
        var num = Number(value) || 0;
        return num.toLocaleString('ko-KR');
    }

    function readJsonScript(id) {
        var node = document.getElementById(id);
        if (!node) return [];
        try { return JSON.parse(node.textContent || '[]'); } catch (e) { return []; }
    }

    // ---------- Category summary (front page) ----------
    var generalStatus = document.getElementById('general-save-status');
    var categoryBody = document.getElementById('category-table-body');
    var categoryTotalAmount = document.getElementById('category-total-amount');
    var categoryTotalRatio = document.getElementById('category-total-ratio');
    var giTotalPlannedAmount = document.getElementById('gi-total-planned-amount');
    var categoryGrandTotal = 0;

    function updateCategoryRatios() {
        var rows = Array.prototype.slice.call(categoryBody.querySelectorAll('tr'));
        var total = rows.reduce(function (sum, tr) { return sum + (Number(tr.getAttribute('data-amount')) || 0); }, 0);
        rows.forEach(function (tr) {
            var amount = Number(tr.getAttribute('data-amount')) || 0;
            var ratio = total > 0 ? Math.round((amount / total) * 100) : 0;
            tr.querySelector('.category-ratio-cell').textContent = ratio + '%';
        });
        categoryGrandTotal = total;
        categoryTotalAmount.textContent = formatNumber(total);
        categoryTotalRatio.textContent = total > 0 ? '100%' : '0%';
        giTotalPlannedAmount.textContent = formatNumber(total) + ' 원';
    }

    function applyCategoryTotals(totals) {
        categoryBody.querySelectorAll('tr').forEach(function (tr) {
            var category = tr.getAttribute('data-category');
            var amount = Number((totals || {})[category]) || 0;
            tr.setAttribute('data-amount', String(amount));
            tr.querySelector('.category-amount-cell').textContent = formatNumber(amount);
        });
        updateCategoryRatios();
    }

    function applyPreviousCategoryTotals(totals) {
        var sum = 0;
        categoryBody.querySelectorAll('tr').forEach(function (tr) {
            var category = tr.getAttribute('data-category');
            var amount = Number((totals || {})[category]) || 0;
            sum += amount;
            tr.querySelector('.category-previous-amount-cell').textContent = formatNumber(amount);
        });
        document.getElementById('category-total-previous-amount').textContent = formatNumber(sum);
    }

    updateCategoryRatios(); // compute ratios/totals from the server-rendered data-amount values, without overwriting them

    function collectGeneralInfo() {
        return {
            company_name: document.getElementById('gi-company-name').value.trim(),
            plan_year: Number(document.getElementById('gi-plan-year').value) || generalYear,
            period_start: document.getElementById('gi-period-start').value,
            period_end: document.getElementById('gi-period-end').value,
            prepared_date: document.getElementById('gi-prepared-date').value,
            total_planned_amount: categoryGrandTotal,
            plan_type_annual: document.getElementById('gi-plan-type-annual').checked,
            plan_type_change: document.getElementById('gi-plan-type-change').checked,
            preparer_name: document.getElementById('gi-preparer-name').value.trim(),
            reviewer_name: document.getElementById('gi-reviewer-name').value.trim(),
        };
    }

    var docDateLine = document.getElementById('doc-date-line');
    var docPreparerNameEcho = document.getElementById('doc-preparer-name-echo');
    var docReviewerNameEcho = document.getElementById('doc-reviewer-name-echo');

    function formatPreparedDate(dateStr) {
        if (!dateStr) return '';
        var parts = dateStr.split('-');
        if (parts.length !== 3) return '';
        return parts[0] + '년   ' + parts[1] + ' 월   ' + parts[2] + ' 일';
    }

    function applyGeneralInfo(general) {
        general = general || {};
        document.getElementById('gi-company-name').value = general.company_name || '';
        document.getElementById('gi-plan-year').value = general.plan_year || generalYear;
        document.getElementById('gi-period-start').value = general.period_start || '';
        document.getElementById('gi-period-end').value = general.period_end || '';
        document.getElementById('gi-prepared-date').value = general.prepared_date || '';
        document.getElementById('gi-plan-type-annual').checked = !!general.plan_type_annual;
        document.getElementById('gi-plan-type-change').checked = !!general.plan_type_change;
        document.getElementById('gi-preparer-name').value = general.preparer_name || '';
        document.getElementById('gi-reviewer-name').value = general.reviewer_name || '';
        docDateLine.textContent = formatPreparedDate(general.prepared_date);
        docPreparerNameEcho.textContent = general.preparer_name || '';
        docReviewerNameEcho.textContent = general.reviewer_name || '';
    }

    var generalDirty = false;

    function saveGeneralInfo() {
        generalStatus.className = 'save-status';
        generalStatus.textContent = '저장 중...';
        var body = new URLSearchParams();
        body.set('action', 'save_general');
        body.set('csrf', csrfToken);
        body.set('year', String(generalYear));
        body.set('general', JSON.stringify(collectGeneralInfo()));
        return fetch('safety_budget_ajax.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                generalStatus.className = 'save-status ' + (res.success ? 'ok' : 'error');
                generalStatus.textContent = res.message || (res.success ? '저장했습니다.' : '저장에 실패했습니다.');
                if (res.success) generalDirty = false;
                if (res.success && res.category_totals) applyCategoryTotals(res.category_totals);
                if (res.success && res.general) applyGeneralInfo(res.general);
            })
            .catch(function () {
                generalStatus.className = 'save-status error';
                generalStatus.textContent = '저장 중 오류가 발생했습니다.';
            });
    }

    document.getElementById('general-save').addEventListener('click', function () {
        saveGeneralInfo();
    });

    document.getElementById('general-print').addEventListener('click', function () {
        window.print();
    });

    // 새로고침/페이지 이동 시 DB에 반영되지 않은 입력이 사라지지 않도록,
    // 각 입력 필드를 벗어날 때(변경 시) 자동으로 저장한다.
    var generalAutoSaveFieldIds = [
        'gi-company-name', 'gi-plan-year', 'gi-period-start', 'gi-period-end', 'gi-prepared-date',
        'gi-plan-type-annual', 'gi-plan-type-change', 'gi-preparer-name', 'gi-reviewer-name',
    ];
    generalAutoSaveFieldIds.forEach(function (id) {
        var field = document.getElementById(id);
        if (!field) return;
        field.addEventListener('change', function () {
            generalDirty = true;
            saveGeneralInfo();
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!generalDirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ---------- Card flip (front: 사용계획서 / back: 세부 사용계획) ----------
    var flipScene = document.getElementById('plan-flip-scene');
    var flipCard = document.getElementById('plan-flip-card');
    var flipFaceFront = document.getElementById('plan-card');
    var flipFaceBack = document.getElementById('detail-card');

    function setFlipSceneHeight(face) {
        flipScene.style.height = face.offsetHeight + 'px';
    }
    setFlipSceneHeight(flipFaceFront);

    function flipTo(showBack) {
        setFlipSceneHeight(showBack ? flipFaceBack : flipFaceFront);
        flipCard.classList.toggle('is-flipped', showBack);
    }

    document.getElementById('detail-flip-open').addEventListener('click', function () { flipTo(true); });
    document.getElementById('detail-flip-close').addEventListener('click', function () { flipTo(false); });

    // ---------- Detail plan (back face) ----------
    var detailYear = generalYear;
    var categoryLabels = <?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    function formatQuantity(value) {
        var num = Math.round((Number(value) || 0) * 100) / 100;
        return num.toLocaleString('ko-KR', { maximumFractionDigits: 2 });
    }

    var detailBody = document.getElementById('detail-table-body');
    var detailTotal = document.getElementById('detail-total-amount');
    var detailStatus = document.getElementById('detail-save-status');

    function updateDetailTotal() {
        var sum = 0;
        var categorySums = {};
        detailBody.querySelectorAll('tr[data-category]').forEach(function (tr) {
            var amount = Number(tr.getAttribute('data-amount')) || 0;
            sum += amount;
            var category = tr.getAttribute('data-category');
            categorySums[category] = (categorySums[category] || 0) + amount;
        });
        detailTotal.textContent = formatNumber(sum);
        Object.keys(categorySums).forEach(function (category) {
            var el = document.getElementById('category-subtotal-' + category);
            if (el) el.textContent = '소계 ' + formatNumber(categorySums[category]) + '원';
        });
        if (flipCard.classList.contains('is-flipped')) setFlipSceneHeight(flipFaceBack);
    }
    updateDetailTotal();

    function detailRowHtml(category, sortOrder, row, categoryRowCount) {
        var hasItem = row.item_name !== '';
        var html = '<tr data-category="' + category + '" data-sort-order="' + sortOrder + '"' +
            ' data-item-name="' + escapeHtml(row.item_name) + '"' +
            ' data-unit="' + escapeHtml(row.unit) + '"' +
            ' data-quantity="' + (Number(row.quantity) || 0) + '"' +
            ' data-unit-price="' + (Number(row.unit_price) || 0) + '"' +
            ' data-amount="' + (Number(row.amount) || 0) + '"' +
            ' data-calculation-note="' + escapeHtml(row.calculation_note) + '"' +
            ' data-usage-period="' + escapeHtml(row.usage_period) + '">';
        if (sortOrder === 0) {
            html += '<td class="category-cell" id="category-cell-' + category + '" rowspan="' + categoryRowCount + '">' +
                escapeHtml(categoryLabels[category] || '') + '<br>' +
                '<span class="category-cell-subtotal" id="category-subtotal-' + category + '">소계 0원</span></td>';
        }
        html += '<td class="detail-cell field-item-name' + (hasItem ? '' : ' is-empty') + '">' + escapeHtml(row.item_name) + '</td>';
        html += '<td class="detail-cell field-unit' + (hasItem ? '' : ' is-empty') + '">' + escapeHtml(row.unit) + '</td>';
        html += '<td class="detail-cell num-cell field-quantity' + (hasItem ? '' : ' is-empty') + '">' + (hasItem ? formatQuantity(row.quantity) : '') + '</td>';
        html += '<td class="detail-cell num-cell field-amount' + (hasItem ? '' : ' is-empty') + '">' + (hasItem ? formatNumber(row.amount) : '') + '</td>';
        html += '<td class="detail-cell field-calculation-note' + (row.calculation_note ? '' : ' is-empty') + '">' + escapeHtml(row.calculation_note) + '</td>';
        html += '<td class="detail-cell field-usage-period' + (row.usage_period ? '' : ' is-empty') + '">' + escapeHtml(row.usage_period) + '</td>';
        html += '</tr>';
        return html;
    }

    function renderDetailGrid(grid) {
        grid = grid || {};
        var html = '';
        Object.keys(categoryLabels).forEach(function (category) {
            var rows = (grid[category] && grid[category].length) ? grid[category] :
                [{ item_name: '', unit: '', quantity: 0, unit_price: 0, amount: 0, calculation_note: '', usage_period: '' }];
            rows.forEach(function (row, i) {
                html += detailRowHtml(category, i, row, rows.length);
            });
            html += '<tr class="add-row-trigger" data-category="' + category + '">' +
                '<td colspan="6"><button type="button" class="add-row-btn" data-category="' + category + '">+ 세부항목 행 추가</button></td></tr>';
        });
        detailBody.innerHTML = html;
        updateDetailTotal();
    }

    function renderDetailRow(tr) {
        var itemName = tr.getAttribute('data-item-name') || '';
        var unit = tr.getAttribute('data-unit') || '';
        var quantity = parseFloat(tr.getAttribute('data-quantity')) || 0;
        var amount = Number(tr.getAttribute('data-amount')) || 0;
        var calcNote = tr.getAttribute('data-calculation-note') || '';
        var usagePeriod = tr.getAttribute('data-usage-period') || '';
        var hasItem = itemName !== '';

        var itemCell = tr.querySelector('.field-item-name');
        itemCell.textContent = itemName;
        itemCell.classList.toggle('is-empty', !hasItem);

        var unitCell = tr.querySelector('.field-unit');
        unitCell.textContent = unit;
        unitCell.classList.toggle('is-empty', !hasItem && unit === '');

        var quantityCell = tr.querySelector('.field-quantity');
        quantityCell.textContent = hasItem ? formatQuantity(quantity) : '';
        quantityCell.classList.toggle('is-empty', !hasItem);

        var amountCell = tr.querySelector('.field-amount');
        amountCell.textContent = hasItem ? formatNumber(amount) : '';
        amountCell.classList.toggle('is-empty', !hasItem);

        var noteCell = tr.querySelector('.field-calculation-note');
        noteCell.textContent = calcNote;
        noteCell.classList.toggle('is-empty', !hasItem && calcNote === '');

        var periodCell = tr.querySelector('.field-usage-period');
        periodCell.textContent = usagePeriod;
        periodCell.classList.toggle('is-empty', !hasItem && usagePeriod === '');

        updateDetailTotal();
    }

    function addDetailRow(category) {
        var triggerRow = detailBody.querySelector('tr.add-row-trigger[data-category="' + category + '"]');
        if (!triggerRow) return;
        var existingRows = detailBody.querySelectorAll('tr[data-category="' + category + '"]:not(.add-row-trigger)');
        var nextSortOrder = existingRows.length;
        var html = '<tr data-category="' + category + '" data-sort-order="' + nextSortOrder + '" data-item-name="" data-unit="" data-quantity="0" data-unit-price="0" data-amount="0" data-calculation-note="" data-usage-period="">' +
            '<td class="detail-cell field-item-name is-empty"></td>' +
            '<td class="detail-cell field-unit is-empty"></td>' +
            '<td class="detail-cell num-cell field-quantity is-empty"></td>' +
            '<td class="detail-cell num-cell field-amount is-empty"></td>' +
            '<td class="detail-cell field-calculation-note is-empty"></td>' +
            '<td class="detail-cell field-usage-period is-empty"></td>' +
            '</tr>';
        triggerRow.insertAdjacentHTML('beforebegin', html);
        var categoryCell = document.getElementById('category-cell-' + category);
        if (categoryCell) categoryCell.rowSpan = (categoryCell.rowSpan || 1) + 1;
        if (flipCard.classList.contains('is-flipped')) setFlipSceneHeight(flipFaceBack);
    }

    // ---------- Detail entry modal ----------
    var modalBackdrop = document.getElementById('detail-modal-backdrop');
    var modalCategory = document.getElementById('detail-modal-category');
    var modalItemName = document.getElementById('modal-item-name');
    var modalUnit = document.getElementById('modal-unit');
    var modalQuantity = document.getElementById('modal-quantity');
    var modalUnitPrice = document.getElementById('modal-unit-price');
    var modalAmount = document.getElementById('modal-amount');
    var modalCalcNote = document.getElementById('modal-calculation-note');
    var modalUsagePeriod = document.getElementById('modal-usage-period');
    var activeDetailRow = null;

    function openDetailModal(tr) {
        activeDetailRow = tr;
        modalCategory.textContent = categoryLabels[tr.getAttribute('data-category')] || '';
        modalItemName.value = tr.getAttribute('data-item-name') || '';
        modalUnit.value = tr.getAttribute('data-unit') || '';
        modalQuantity.value = tr.getAttribute('data-quantity') || '';
        modalUnitPrice.value = tr.getAttribute('data-unit-price') || '';
        modalAmount.value = tr.getAttribute('data-amount') || '';
        modalCalcNote.value = tr.getAttribute('data-calculation-note') || '';
        modalUsagePeriod.value = tr.getAttribute('data-usage-period') || '';
        modalBackdrop.hidden = false;
        modalItemName.focus();
    }

    function closeDetailModal() {
        modalBackdrop.hidden = true;
        activeDetailRow = null;
    }

    detailBody.addEventListener('click', function (e) {
        var addBtn = e.target.closest('.add-row-btn');
        if (addBtn) {
            addDetailRow(addBtn.getAttribute('data-category'));
            return;
        }
        var cell = e.target.closest('.detail-cell');
        if (!cell) return;
        openDetailModal(cell.closest('tr'));
    });

    function recalcModalAmount() {
        var quantity = Number(modalQuantity.value) || 0;
        var unitPrice = Number(modalUnitPrice.value) || 0;
        if (quantity > 0 && unitPrice > 0) {
            modalAmount.value = String(Math.round(quantity * unitPrice));
        }
    }
    modalQuantity.addEventListener('input', recalcModalAmount);
    modalUnitPrice.addEventListener('input', recalcModalAmount);

    document.getElementById('modal-cancel').addEventListener('click', closeDetailModal);

    document.getElementById('modal-clear').addEventListener('click', function () {
        if (!activeDetailRow) return;
        activeDetailRow.setAttribute('data-item-name', '');
        activeDetailRow.setAttribute('data-unit', '');
        activeDetailRow.setAttribute('data-quantity', '0');
        activeDetailRow.setAttribute('data-unit-price', '0');
        activeDetailRow.setAttribute('data-amount', '0');
        activeDetailRow.setAttribute('data-calculation-note', '');
        activeDetailRow.setAttribute('data-usage-period', '');
        renderDetailRow(activeDetailRow);
        closeDetailModal();
        saveDetailRows();
    });

    document.getElementById('modal-delete').addEventListener('click', function () {
        if (!activeDetailRow) return;
        var category = activeDetailRow.getAttribute('data-category');
        var rows = Array.prototype.slice.call(
            detailBody.querySelectorAll('tr[data-category="' + category + '"]:not(.add-row-trigger)')
        );
        if (rows.length <= 1) {
            // 카테고리마다 최소 1행은 유지 — 대신 비워서 초기화한다.
            document.getElementById('modal-clear').click();
            return;
        }
        var categoryCell = document.getElementById('category-cell-' + category);
        var wasFirstRow = categoryCell && categoryCell.parentElement === activeDetailRow;
        if (wasFirstRow) categoryCell.remove();
        activeDetailRow.remove();

        var remaining = detailBody.querySelectorAll('tr[data-category="' + category + '"]:not(.add-row-trigger)');
        remaining.forEach(function (tr, i) { tr.setAttribute('data-sort-order', String(i)); });
        if (categoryCell && remaining.length) {
            categoryCell.rowSpan = remaining.length;
            if (wasFirstRow) remaining[0].insertBefore(categoryCell, remaining[0].firstChild);
        }

        updateDetailTotal();
        closeDetailModal();
        saveDetailRows();
    });

    document.getElementById('modal-confirm').addEventListener('click', function () {
        if (!activeDetailRow) return;
        activeDetailRow.setAttribute('data-item-name', modalItemName.value.trim());
        activeDetailRow.setAttribute('data-unit', modalUnit.value.trim());
        activeDetailRow.setAttribute('data-quantity', String(Number(modalQuantity.value) || 0));
        activeDetailRow.setAttribute('data-unit-price', String(Number(modalUnitPrice.value) || 0));
        activeDetailRow.setAttribute('data-amount', String(Number(modalAmount.value) || 0));
        activeDetailRow.setAttribute('data-calculation-note', modalCalcNote.value.trim());
        activeDetailRow.setAttribute('data-usage-period', modalUsagePeriod.value.trim());
        renderDetailRow(activeDetailRow);
        closeDetailModal();
        saveDetailRows();
    });

    function collectDetailRows() {
        var rows = [];
        detailBody.querySelectorAll('tr').forEach(function (tr) {
            var itemName = tr.getAttribute('data-item-name') || '';
            if (!itemName) return;
            rows.push({
                category: tr.getAttribute('data-category'),
                sort_order: Number(tr.getAttribute('data-sort-order')),
                item_name: itemName,
                unit: tr.getAttribute('data-unit') || '',
                quantity: Number(tr.getAttribute('data-quantity')) || 0,
                unit_price: Number(tr.getAttribute('data-unit-price')) || 0,
                amount: Number(tr.getAttribute('data-amount')) || 0,
                calculation_note: tr.getAttribute('data-calculation-note') || '',
                usage_period: tr.getAttribute('data-usage-period') || '',
            });
        });
        return rows;
    }

    function saveDetailRows(onDone) {
        detailStatus.className = 'save-status';
        detailStatus.textContent = '저장 중...';
        var body = new URLSearchParams();
        body.set('action', 'save_detail');
        body.set('csrf', csrfToken);
        body.set('year', String(detailYear));
        body.set('rows', JSON.stringify(collectDetailRows()));
        return fetch('safety_budget_ajax.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                detailStatus.className = 'save-status ' + (res.success ? 'ok' : 'error');
                detailStatus.textContent = res.message || (res.success ? '저장했습니다.' : '저장에 실패했습니다.');
                if (res.success && res.category_totals) applyCategoryTotals(res.category_totals);
                if (onDone) onDone(res);
            })
            .catch(function () {
                detailStatus.className = 'save-status error';
                detailStatus.textContent = '저장 중 오류가 발생했습니다.';
            });
    }

    document.getElementById('detail-save').addEventListener('click', function () {
        saveDetailRows();
    });

    document.getElementById('detail-print').addEventListener('click', function () {
        window.print();
    });

    // ---------- Year lookup (조회 연도) ----------
    function loadYear(year) {
        generalStatus.className = 'save-status';
        generalStatus.textContent = year + '년 데이터를 불러오는 중...';
        var currentReq = fetch('safety_budget_ajax.php?' + new URLSearchParams({ action: 'load_general', year: String(year) }))
            .then(function (res) { return res.json(); });
        var previousReq = fetch('safety_budget_ajax.php?' + new URLSearchParams({ action: 'load_general', year: String(year - 1) }))
            .then(function (res) { return res.json(); });
        var detailReq = fetch('safety_budget_ajax.php?' + new URLSearchParams({ action: 'load_detail', year: String(year) }))
            .then(function (res) { return res.json(); });

        Promise.all([currentReq, previousReq, detailReq]).then(function (results) {
            var currentRes = results[0], previousRes = results[1], detailRes = results[2];
            if (!currentRes.success) throw new Error(currentRes.message || '불러오기에 실패했습니다.');

            generalYear = year;
            detailYear = year;
            document.getElementById('plan-card').setAttribute('data-year', String(year));
            document.getElementById('detail-card').setAttribute('data-year', String(year));

            applyGeneralInfo(currentRes.general);
            applyCategoryTotals(currentRes.category_totals);
            if (previousRes.success) applyPreviousCategoryTotals(previousRes.category_totals);
            if (detailRes.success) renderDetailGrid(detailRes.grid);

            generalDirty = false;
            generalStatus.className = 'save-status ok';
            generalStatus.textContent = year + '년 데이터를 불러왔습니다.';
            if (flipCard.classList.contains('is-flipped')) setFlipSceneHeight(flipFaceBack);
            else setFlipSceneHeight(flipFaceFront);
        }).catch(function (err) {
            generalStatus.className = 'save-status error';
            generalStatus.textContent = err.message || '데이터를 불러오지 못했습니다.';
        });
    }

    document.getElementById('plan-year-select').addEventListener('change', function () {
        loadYear(Number(this.value));
    });

    // ---------- Usage card ----------
    var usageBody = document.getElementById('usage-table-body');
    var usageTotal = document.getElementById('usage-total');
    var usageStatus = document.getElementById('usage-save-status');
    var usageTitle = document.getElementById('usage-title');
    var usageViewBox = document.getElementById('usage-view-box');

    function usageRowHtml(row) {
        row = row || { spent_date: '', item_name: '', description: '', amount: 0, note: '' };
        return '<tr>' +
            '<td><input type="date" class="usage-date" value="' + escapeHtml(row.spent_date) + '"></td>' +
            '<td><input type="text" class="usage-item-name" value="' + escapeHtml(row.item_name) + '" placeholder="항목명"></td>' +
            '<td><input type="text" class="usage-description" value="' + escapeHtml(row.description) + '" placeholder="내용"></td>' +
            '<td><input type="number" min="0" step="1" class="usage-amount" value="' + escapeHtml(row.amount) + '"></td>' +
            '<td><input type="text" class="usage-note" value="' + escapeHtml(row.note) + '" placeholder="비고"></td>' +
            '<td><button type="button" class="row-remove" aria-label="행 삭제">&times;</button></td>' +
            '</tr>';
    }

    function renderUsageRows(rows) {
        usageBody.innerHTML = rows.length ? rows.map(usageRowHtml).join('') : usageRowHtml();
        updateUsageTotal();
    }

    function updateUsageTotal() {
        var sum = 0;
        usageBody.querySelectorAll('.usage-amount').forEach(function (input) { sum += Number(input.value) || 0; });
        usageTotal.textContent = formatNumber(sum);
    }

    usageBody.addEventListener('input', function (e) {
        if (e.target.classList.contains('usage-amount')) updateUsageTotal();
    });
    usageBody.addEventListener('click', function (e) {
        if (e.target.classList.contains('row-remove')) {
            e.target.closest('tr').remove();
            if (!usageBody.querySelector('tr')) renderUsageRows([]);
            updateUsageTotal();
        }
    });
    document.getElementById('usage-add-row').addEventListener('click', function () {
        usageBody.insertAdjacentHTML('beforeend', usageRowHtml());
    });

    function collectUsageRows() {
        var rows = [];
        usageBody.querySelectorAll('tr').forEach(function (tr) {
            var itemName = tr.querySelector('.usage-item-name').value.trim();
            if (!itemName) return;
            rows.push({
                spent_date: tr.querySelector('.usage-date').value,
                item_name: itemName,
                description: tr.querySelector('.usage-description').value.trim(),
                amount: Number(tr.querySelector('.usage-amount').value) || 0,
                note: tr.querySelector('.usage-note').value.trim(),
            });
        });
        return rows;
    }

    function saveUsage() {
        usageStatus.className = 'save-status';
        usageStatus.textContent = '저장 중...';
        var body = new URLSearchParams();
        body.set('action', 'save_usage');
        body.set('csrf', csrfToken);
        body.set('year', String(usageState.year));
        body.set('month', String(usageState.month));
        body.set('rows', JSON.stringify(collectUsageRows()));
        return fetch('safety_budget_ajax.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                usageStatus.className = 'save-status ' + (res.success ? 'ok' : 'error');
                usageStatus.textContent = res.message || (res.success ? '저장했습니다.' : '저장에 실패했습니다.');
                if (res.success && res.rows) renderUsageRows(res.rows);
            })
            .catch(function () {
                usageStatus.className = 'save-status error';
                usageStatus.textContent = '저장 중 오류가 발생했습니다.';
            });
    }

    document.getElementById('usage-save').addEventListener('click', saveUsage);

    function loadUsageMonth(month) {
        usageState.month = month;
        usageTitle.textContent = usageState.year + '년 ' + month + '월 산업안전보건관리비 사용내역서';
        usageViewBox.classList.add('is-loading');
        usageStatus.textContent = '';
        var params = new URLSearchParams({ action: 'load_usage', year: String(usageState.year), month: String(month) });
        fetch('safety_budget_ajax.php?' + params.toString())
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.success) {
                    renderUsageRows(res.rows || []);
                } else {
                    usageStatus.className = 'save-status error';
                    usageStatus.textContent = res.message || '내역을 불러오지 못했습니다.';
                }
            })
            .catch(function () {
                usageStatus.className = 'save-status error';
                usageStatus.textContent = '내역을 불러오는 중 오류가 발생했습니다.';
            })
            .finally(function () {
                usageViewBox.classList.remove('is-loading');
            });
    }

    document.getElementById('month-tabs').addEventListener('click', function (e) {
        var tab = e.target.closest('.month-tab');
        if (!tab) return;
        document.querySelectorAll('.month-tab').forEach(function (btn) {
            btn.classList.toggle('is-active', btn === tab);
            btn.setAttribute('aria-selected', btn === tab ? 'true' : 'false');
        });
        loadUsageMonth(Number(tab.getAttribute('data-month')));
    });

    renderUsageRows(readJsonScript('usage-initial-data'));
})();
</script>
</body>
</html>
