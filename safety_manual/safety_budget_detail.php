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

$currentYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');

$categories = safety_budget_categories();

$errorMessage = '';
$grid = [];
try {
    $db = getDB();
    safety_budget_initialize($db);
    $grid = safety_budget_detail_load($db, $currentYear);
} catch (Throwable $error) {
    $errorMessage = 'DB에서 데이터를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.';
    error_log('Safety budget detail page load failure: ' . $error->getMessage());
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
<title>안전보건관리비 세부 사용계획</title>
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
    header { padding: 22px 28px; background: var(--deep-blue); color: #fff; }
    header small { letter-spacing: .08em; opacity: .8; }
    header h1 { margin: 4px 0 0; font-size: 22px; }
    main { max-width: 1080px; margin: 0 auto; padding: 24px 20px 60px; }
    .top-actions { display: flex; justify-content: flex-end; margin-bottom: 16px; }
    .top-actions a { color: var(--blue); font-weight: 600; text-decoration: none; }
    .top-actions a:hover { text-decoration: underline; }
    .notice.error {
        background: #fdecec; border: 1px solid #f2b8b8; color: #a41c1c;
        padding: 12px 16px; border-radius: 10px; margin-bottom: 16px;
    }
    .budget-card {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 22px 24px 26px;
        box-shadow: 0 1px 3px rgba(16, 53, 99, .06);
    }
    .doc-header { text-align: center; margin-bottom: 14px; }
    .doc-title { margin: 4px 0; font-size: 20px; color: var(--deep-blue); }
    .doc-page-mark { margin: 0; font-size: 12px; color: var(--muted); text-align: right; }
    table.detail-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12.5px;
        margin-bottom: 18px;
    }
    table.detail-table th, table.detail-table td {
        border: 1px solid #9fb2c9;
        padding: 6px 8px;
        text-align: center;
    }
    table.detail-table th { background: #eef3fa; color: var(--deep-blue); font-weight: 700; }
    table.detail-table td.category-cell { background: #f7fafd; font-weight: 700; color: var(--deep-blue); }
    table.detail-table td.detail-cell {
        cursor: pointer;
        text-align: left;
        min-width: 60px;
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
    .card-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 14px;
        gap: 8px;
        flex-wrap: wrap;
    }
    .save-status { font-size: 12.5px; color: var(--muted); min-height: 16px; }
    .save-status.ok { color: #147a3c; }
    .save-status.error { color: #a41c1c; }
    button.plain-btn {
        border: 1px solid var(--line);
        background: #f5f8fc;
        color: var(--blue);
        font-weight: 700;
        padding: 7px 14px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
    }
    button.plain-btn:hover { background: #eaf1fa; }
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
    .modal-actions { display: flex; justify-content: space-between; gap: 8px; margin-top: 18px; }
    .modal-actions .right-group { display: flex; gap: 8px; }
</style>
</head>
<body>
<header>
    <small>SAFETY MANAGEMENT SYSTEM</small>
    <h1>산업안전보건관리비 세부 사용계획</h1>
</header>
<main>
    <div class="top-actions"><a href="safety_budget.php">사용계획서로 돌아가기</a></div>
    <?php if ($errorMessage): ?><div role="alert" class="notice error"><?= h($errorMessage) ?></div><?php endif; ?>

    <section class="budget-card" data-year="<?= $currentYear ?>">
        <div class="doc-header">
            <h2 class="doc-title"><?= $currentYear ?>년 3. 세부 사용계획</h2>
            <p class="doc-page-mark">(뒤 쪽)</p>
        </div>

        <table class="detail-table">
            <thead>
                <tr><th style="width:14%">항목</th><th style="width:16%">세부항목</th><th style="width:8%">단위</th><th style="width:8%">수량</th><th style="width:12%">금액</th><th>산출명세</th><th style="width:12%">사용시기</th></tr>
            </thead>
            <tbody id="detail-table-body">
                <?php foreach ($categories as $categoryKey => $categoryLabel): ?>
                    <?php $count = count($grid[$categoryKey]); ?>
                    <?php for ($i = 0; $i < $count; $i++): ?>
                        <?php $row = $grid[$categoryKey][$i] ?? ['item_name' => '', 'unit' => '', 'quantity' => 0, 'amount' => 0, 'calculation_note' => '', 'usage_period' => '']; ?>
                        <tr data-category="<?= h($categoryKey) ?>" data-sort-order="<?= $i ?>"
                            data-item-name="<?= h((string)$row['item_name']) ?>"
                            data-unit="<?= h((string)$row['unit']) ?>"
                            data-quantity="<?= h((string)$row['quantity']) ?>"
                            data-amount="<?= h((string)$row['amount']) ?>"
                            data-calculation-note="<?= h((string)$row['calculation_note']) ?>"
                            data-usage-period="<?= h((string)$row['usage_period']) ?>">
                            <?php if ($i === 0): ?><td class="category-cell" id="category-cell-<?= h($categoryKey) ?>" rowspan="<?= $count ?>"><?= h($categoryLabel) ?></td><?php endif; ?>
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
            <span></span>
            <div>
                <span class="save-status" id="detail-save-status"></span>
                <button type="button" class="primary-btn" id="detail-save">저장</button>
            </div>
        </div>
    </section>
</main>

<div class="modal-backdrop" id="detail-modal-backdrop" hidden>
    <div class="modal-box">
        <h3>세부항목 입력</h3>
        <p class="modal-category" id="detail-modal-category"></p>
        <div class="modal-grid">
            <label>세부항목명<input type="text" id="modal-item-name" placeholder="예: 전선거치대"></label>
            <label>단위<input type="text" id="modal-unit" placeholder="예: ea"></label>
            <label>수량<input type="number" min="0" step="0.01" id="modal-quantity" placeholder="0"></label>
            <label>금액(계산)<input type="number" min="0" step="1" id="modal-amount" placeholder="0"></label>
            <label>산출명세<input type="text" id="modal-calculation-note" placeholder="예: 3,000*1,000ea"></label>
            <label>사용시기<input type="text" id="modal-usage-period" placeholder="예: 25. 08.~"></label>
        </div>
        <div class="modal-actions">
            <button type="button" class="plain-btn" id="modal-clear">비우기</button>
            <div class="right-group">
                <button type="button" class="plain-btn" id="modal-cancel">취소</button>
                <button type="button" class="primary-btn" id="modal-confirm">확인</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
    var detailYear = <?= $currentYear ?>;
    var categoryLabels = <?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatNumber(value) {
        var num = Number(value) || 0;
        return num.toLocaleString('ko-KR');
    }

    function formatQuantity(value) {
        var num = Math.round((Number(value) || 0) * 100) / 100;
        return num.toLocaleString('ko-KR', { maximumFractionDigits: 2 });
    }

    var detailBody = document.getElementById('detail-table-body');
    var detailTotal = document.getElementById('detail-total-amount');
    var detailStatus = document.getElementById('detail-save-status');

    function updateDetailTotal() {
        var sum = 0;
        detailBody.querySelectorAll('tr').forEach(function (tr) { sum += Number(tr.getAttribute('data-amount')) || 0; });
        detailTotal.textContent = formatNumber(sum);
    }
    updateDetailTotal();

    function renderRow(tr) {
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

    // ---------- Modal ----------
    var modalBackdrop = document.getElementById('detail-modal-backdrop');
    var modalCategory = document.getElementById('detail-modal-category');
    var modalItemName = document.getElementById('modal-item-name');
    var modalUnit = document.getElementById('modal-unit');
    var modalQuantity = document.getElementById('modal-quantity');
    var modalAmount = document.getElementById('modal-amount');
    var modalCalcNote = document.getElementById('modal-calculation-note');
    var modalUsagePeriod = document.getElementById('modal-usage-period');
    var activeRow = null;

    function openModal(tr) {
        activeRow = tr;
        modalCategory.textContent = categoryLabels[tr.getAttribute('data-category')] || '';
        modalItemName.value = tr.getAttribute('data-item-name') || '';
        modalUnit.value = tr.getAttribute('data-unit') || '';
        modalQuantity.value = tr.getAttribute('data-quantity') || '';
        modalAmount.value = tr.getAttribute('data-amount') || '';
        modalCalcNote.value = tr.getAttribute('data-calculation-note') || '';
        modalUsagePeriod.value = tr.getAttribute('data-usage-period') || '';
        modalBackdrop.hidden = false;
        modalItemName.focus();
    }

    function closeModal() {
        modalBackdrop.hidden = true;
        activeRow = null;
    }

    function addRow(category) {
        var triggerRow = detailBody.querySelector('tr.add-row-trigger[data-category="' + category + '"]');
        if (!triggerRow) return;
        var existingRows = detailBody.querySelectorAll('tr[data-category="' + category + '"]:not(.add-row-trigger)');
        var nextSortOrder = existingRows.length;
        var html = '<tr data-category="' + category + '" data-sort-order="' + nextSortOrder + '" data-item-name="" data-unit="" data-quantity="0" data-amount="0" data-calculation-note="" data-usage-period="">' +
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
    }

    detailBody.addEventListener('click', function (e) {
        var addBtn = e.target.closest('.add-row-btn');
        if (addBtn) {
            addRow(addBtn.getAttribute('data-category'));
            return;
        }
        var cell = e.target.closest('.detail-cell');
        if (!cell) return;
        openModal(cell.closest('tr'));
    });

    document.getElementById('modal-cancel').addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', function (e) {
        if (e.target === modalBackdrop) closeModal();
    });

    document.getElementById('modal-clear').addEventListener('click', function () {
        if (!activeRow) return;
        activeRow.setAttribute('data-item-name', '');
        activeRow.setAttribute('data-unit', '');
        activeRow.setAttribute('data-quantity', '0');
        activeRow.setAttribute('data-amount', '0');
        activeRow.setAttribute('data-calculation-note', '');
        activeRow.setAttribute('data-usage-period', '');
        renderRow(activeRow);
        closeModal();
    });

    document.getElementById('modal-confirm').addEventListener('click', function () {
        if (!activeRow) return;
        activeRow.setAttribute('data-item-name', modalItemName.value.trim());
        activeRow.setAttribute('data-unit', modalUnit.value.trim());
        activeRow.setAttribute('data-quantity', String(Number(modalQuantity.value) || 0));
        activeRow.setAttribute('data-amount', String(Number(modalAmount.value) || 0));
        activeRow.setAttribute('data-calculation-note', modalCalcNote.value.trim());
        activeRow.setAttribute('data-usage-period', modalUsagePeriod.value.trim());
        renderRow(activeRow);
        closeModal();
    });

    function collectRows() {
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
                amount: Number(tr.getAttribute('data-amount')) || 0,
                calculation_note: tr.getAttribute('data-calculation-note') || '',
                usage_period: tr.getAttribute('data-usage-period') || '',
            });
        });
        return rows;
    }

    document.getElementById('detail-save').addEventListener('click', function () {
        detailStatus.className = 'save-status';
        detailStatus.textContent = '저장 중...';
        var body = new URLSearchParams();
        body.set('action', 'save_detail');
        body.set('csrf', csrfToken);
        body.set('year', String(detailYear));
        body.set('rows', JSON.stringify(collectRows()));
        fetch('safety_budget_ajax.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                detailStatus.className = 'save-status ' + (res.success ? 'ok' : 'error');
                detailStatus.textContent = res.message || (res.success ? '저장했습니다.' : '저장에 실패했습니다.');
            })
            .catch(function () {
                detailStatus.className = 'save-status error';
                detailStatus.textContent = '저장 중 오류가 발생했습니다.';
            });
    });
})();
</script>
</body>
</html>
