<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/common.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

if (!auth_can_manage($user)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>안전보호구 현황</title>
        <style>
            body { font-family: "Malgun Gothic", sans-serif; background:#f3f7fb; color:#122033; margin:0; padding:32px; }
            .panel { max-width:720px; margin:0 auto; background:#fff; border:1px solid #d7e0ea; border-radius:20px; padding:24px; }
            .actions { margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; }
            .button { display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0f766e; color:#fff; text-decoration:none; }
            .button.secondary { background:#e2e8f0; color:#0f172a; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>안전보호구 현황</h1>
            <p>이 페이지는 안전관리자 또는 관리자만 접근할 수 있습니다.</p>
            <div class="actions">
                <a class="button secondary" href="/risk_assessment/work_list.php">작업목록으로 돌아가기</a>
            </div>
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

function status_value($value, string $fallback = '-'): string
{
    $text = sg_normalize_text((string)$value);
    return $text !== '' ? $text : $fallback;
}

function status_date_only($value, string $fallback = '-'): string
{
    $text = sg_normalize_text((string)$value);
    if ($text === '') {
        return $fallback;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text, $matches) === 1) {
        return $matches[0];
    }

    return $text;
}

function status_sort_dates(array $dates): array
{
    $normalized = [];
    foreach ($dates as $date) {
        $text = status_date_only($date, '');
        if ($text !== '') {
            $normalized[$text] = $text;
        }
    }

    $result = array_values($normalized);
    sort($result);
    return $result;
}

function status_json_attr($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '[]';
    }

    return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
}

$pdo = sg_get_pdo();
$viewMode = sg_normalize_text($_GET['view'] ?? 'employee');
$teamFilter = sg_normalize_text($_GET['team'] ?? '');
$typeFilter = sg_normalize_text($_GET['gear_type'] ?? '');

$allItems = sg_fetch_all_items($pdo);
$employeeGroups = sg_fetch_assigned_items_grouped_by_employee($pdo, [], false);

$gearTypes = [];
foreach ($allItems as $item) {
    $gearType = sg_normalize_text($item['gear_type'] ?? '');
    if ($gearType !== '') {
        $gearTypes[$gearType] = $gearType;
    }
}
$gearTypes = array_values($gearTypes);
sort($gearTypes);

$teams = [];
foreach ($employeeGroups as $group) {
    $teamName = sg_normalize_text($group['employee_team'] ?? '');
    if ($teamName !== '') {
        $teams[$teamName] = $teamName;
    }
}
$teams = array_values($teams);
sort($teams);

$employeeRows = [];
foreach ($employeeGroups as $group) {
    $teamName = sg_normalize_text($group['employee_team'] ?? '');
    if ($teamFilter !== '' && $teamName !== $teamFilter) {
        continue;
    }

    $cells = [];
    foreach ($gearTypes as $gearType) {
        $cells[$gearType] = [];
    }

    foreach ((array)($group['assigned_items'] ?? []) as $item) {
        $gearType = sg_normalize_text($item['gear_type'] ?? '');
        if ($gearType === '' || !isset($cells[$gearType])) {
            continue;
        }

        $assignedAt = sg_normalize_text($item['assigned_at'] ?? '');
        if ($assignedAt !== '') {
            $cells[$gearType][] = $assignedAt;
        }
    }

    $employeeRows[] = [
        'employee_name' => sg_normalize_text($group['employee_name'] ?? ''),
        'employee_team' => $teamName,
        'assigned_count' => count((array)($group['assigned_items'] ?? [])),
        'cells' => $cells,
    ];
}

usort($employeeRows, static function (array $a, array $b): int {
    $teamCompare = strcmp($a['employee_team'], $b['employee_team']);
    if ($teamCompare !== 0) {
        return $teamCompare;
    }

    return strcmp($a['employee_name'], $b['employee_name']);
});

$inventoryMap = [];
foreach ($allItems as $item) {
    $gearType = sg_normalize_text($item['gear_type'] ?? '');
    if ($typeFilter !== '' && $gearType !== $typeFilter) {
        continue;
    }

    $itemName = sg_normalize_text($item['item_name'] ?? '');
    $specName = sg_normalize_text($item['spec_name'] ?? '');
    $modelName = sg_normalize_text($item['model_name'] ?? '');
    $rowKey = implode('|', [$gearType, $itemName, $specName, $modelName]);

    if (!isset($inventoryMap[$rowKey])) {
        $inventoryMap[$rowKey] = [
            'gear_type' => $gearType,
            'item_name' => $itemName,
            'spec_name' => $specName,
            'model_name' => $modelName,
            'total_count' => 0,
            'current_count' => 0,
            'issued_count' => 0,
            'returned_count' => 0,
            'disposed_count' => 0,
            'purchase_dates' => [],
            'issued_dates' => [],
            'issued_people_by_date' => [],
        ];
    }

    $inventoryMap[$rowKey]['total_count']++;

    $purchasedAt = sg_normalize_text($item['purchased_at'] ?? '');
    if ($purchasedAt !== '') {
        $inventoryMap[$rowKey]['purchase_dates'][] = $purchasedAt;
    }

    $assignedEmployeeName = sg_normalize_text($item['assigned_employee_name'] ?? '');
    $assignedAt = sg_normalize_text($item['assigned_at'] ?? '');
    $status = sg_normalize_text($item['status'] ?? '');
    $isHiddenStatus = sg_status_label_is_hidden($status);
    $isDisposed = ($status === '폐기');
    $isReturned = ($status === '반납');
    $isIssued = ($status === '지급됨') || (!$isHiddenStatus && ($assignedEmployeeName !== '' || $assignedAt !== ''));

    if ($isIssued) {
        $inventoryMap[$rowKey]['issued_count']++;
        if ($assignedAt !== '') {
            $inventoryMap[$rowKey]['issued_dates'][] = $assignedAt;
            $issuedDateKey = status_date_only($assignedAt, '');
            if ($issuedDateKey !== '') {
                if (!isset($inventoryMap[$rowKey]['issued_people_by_date'][$issuedDateKey])) {
                    $inventoryMap[$rowKey]['issued_people_by_date'][$issuedDateKey] = [];
                }
                $inventoryMap[$rowKey]['issued_people_by_date'][$issuedDateKey][] = [
                    'gear_uid' => sg_normalize_text($item['id'] ?? ''),
                    'employee_name' => $assignedEmployeeName,
                    'employee_team' => sg_normalize_text($item['assigned_team'] ?? ''),
                    'assigned_at' => $assignedAt,
                    'identifier_value' => sg_normalize_text($item['identifier_value'] ?? ''),
                ];
            }
        }
    }

    if ($isReturned) {
        $inventoryMap[$rowKey]['returned_count']++;
    }

    if ($isDisposed) {
        $inventoryMap[$rowKey]['disposed_count']++;
    }

    if (!$isDisposed && !$isIssued) {
        $inventoryMap[$rowKey]['current_count']++;
    }
}

$inventoryRows = array_values($inventoryMap);
usort($inventoryRows, static function (array $a, array $b): int {
    $typeCompare = strcmp($a['gear_type'], $b['gear_type']);
    if ($typeCompare !== 0) {
        return $typeCompare;
    }

    $nameCompare = strcmp($a['item_name'], $b['item_name']);
    if ($nameCompare !== 0) {
        return $nameCompare;
    }

    $specCompare = strcmp($a['spec_name'], $b['spec_name']);
    if ($specCompare !== 0) {
        return $specCompare;
    }

    return strcmp($a['model_name'], $b['model_name']);
});

$employeeCount = count($employeeRows);
$inventoryCount = count($inventoryRows);
$totalAssignedCount = 0;
foreach ($employeeRows as $row) {
    $totalAssignedCount += (int)($row['assigned_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전보호구 현황</title>
    <style>
        :root {
            --bg: #edf3f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #132238;
            --muted: #64748b;
            --accent: #0f766e;
            --accent-soft: #ccfbf1;
            --soft: #f8fafc;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Malgun Gothic", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 22%),
                linear-gradient(180deg, #f8fbfd 0%, var(--bg) 100%);
        }

        .page {
            width: min(1500px, calc(100vw - 24px));
            margin: 18px auto 28px;
            display: grid;
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
            padding: 18px;
        }

        h1, h2 { margin: 0; }

        .lead {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .summary-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--soft);
            padding: 14px;
        }

        .summary-card .label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .summary-card .value {
            font-size: 15px;
            line-height: 1.6;
            word-break: break-word;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .toggle-group,
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }

        .button.active,
        .button.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .button.soft {
            background: var(--accent-soft);
            color: #115e59;
            border-color: transparent;
        }

        .filter-form {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
        }

        .field {
            display: grid;
            gap: 6px;
        }

        .field label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 700;
        }

        select, button {
            font: inherit;
        }

        select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 18px;
            margin-top: 18px;
            background: #fff;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 960px;
        }

        th, td {
            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            padding: 12px;
            vertical-align: top;
            text-align: left;
            font-size: 13px;
            line-height: 1.5;
            background: #fff;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            font-size: 12px;
        }

        tr:last-child td { border-bottom: 0; }
        th:last-child, td:last-child { border-right: 0; }

        .sticky-col {
            position: sticky;
            left: 0;
            z-index: 1;
            background: #fff;
        }

        th.sticky-col {
            z-index: 3;
            background: #f8fafc;
        }

        .person-cell {
            min-width: 160px;
        }

        .person-name {
            font-weight: 700;
        }

        .person-team {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .date-stack {
            display: grid;
            gap: 4px;
        }

        .date-chip {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            max-width: 100%;
            padding: 3px 8px;
            border-radius: 999px;
            background: #ecfeff;
            color: #155e75;
            font-size: 12px;
            white-space: nowrap;
        }

        .date-chip.button-chip {
            border: 0;
            cursor: pointer;
            font: inherit;
        }

        .date-chip.button-chip:hover {
            background: #cffafe;
        }

        .muted {
            color: var(--muted);
        }

        .empty {
            margin-top: 18px;
            padding: 28px 18px;
            border: 1px dashed var(--line);
            border-radius: 18px;
            color: var(--muted);
            text-align: center;
            line-height: 1.8;
            background: #fff;
        }

        .caption {
            margin-top: 14px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(15, 23, 42, 0.5);
            z-index: 50;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            width: min(680px, calc(100vw - 36px));
            max-height: calc(100vh - 36px);
            overflow: auto;
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--line);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
            padding: 20px;
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
        }

        .modal-subtitle {
            margin-top: 6px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .modal-close {
            border: 0;
            border-radius: 12px;
            background: #e2e8f0;
            color: #0f172a;
            padding: 8px 12px;
            cursor: pointer;
            font: inherit;
        }

        .modal-list {
            margin-top: 16px;
            display: grid;
            gap: 10px;
        }

        .modal-item {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--soft);
            padding: 12px 14px;
        }

        .modal-item.clickable {
            cursor: pointer;
        }

        .modal-item.clickable:hover {
            background: #eefcf8;
            border-color: #99f6e4;
        }

        .modal-item strong {
            display: block;
            font-size: 14px;
        }

        .modal-meta {
            margin-top: 6px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        @media (max-width: 980px) {
            .summary-grid,
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .summary-grid,
            .filter-form {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            body { background: #fff; }
            .panel { box-shadow: none; }
            .no-print { display: none !important; }
            .table-wrap { overflow: visible; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel no-print">
            <div class="toolbar">
                <div>
                    <h1>안전보호구 현황</h1>
                    <p class="lead">사람별 지급현황과 품목별 재고 현황을 테이블로 전환해 한 화면에서 비교할 수 있습니다.</p>
                </div>
                <div class="actions">
                    <a class="button soft" href="/safety_gear/index.php">관리 페이지</a>
                    <a class="button soft" href="/safety_gear/report.php">지급 이력서</a>
                    <button class="button" type="button" onclick="window.print()">인쇄</button>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <span class="label">조회 화면</span>
                    <div class="value"><?= $viewMode === 'inventory' ? '품목별 재고 현황' : '사람별 지급현황' ?></div>
                </div>
                <div class="summary-card">
                    <span class="label">지급중 인원</span>
                    <div class="value"><?= $employeeCount ?>명</div>
                </div>
                <div class="summary-card">
                    <span class="label">현재 지급 품목</span>
                    <div class="value"><?= $totalAssignedCount ?>건</div>
                </div>
                <div class="summary-card">
                    <span class="label">재고 집계 행</span>
                    <div class="value"><?= $inventoryCount ?>개 품목</div>
                </div>
            </div>

            <div class="toggle-group" style="margin-top:16px;">
                <a class="button<?= $viewMode === 'employee' ? ' active' : '' ?>" href="/safety_gear/status.php?view=employee">사람별 지급현황</a>
                <a class="button<?= $viewMode === 'inventory' ? ' active' : '' ?>" href="/safety_gear/status.php?view=inventory">품목별 재고 현황</a>
            </div>

            <form method="get" class="filter-form">
                <input type="hidden" name="view" value="<?= h($viewMode) ?>">

                <?php if ($viewMode === 'employee'): ?>
                    <div class="field">
                        <label for="team">소속팀</label>
                        <select id="team" name="team">
                            <option value="">전체</option>
                            <?php foreach ($teams as $teamName): ?>
                                <option value="<?= h($teamName) ?>"<?= $teamFilter === $teamName ? ' selected' : '' ?>><?= h($teamName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label for="gear_type">보호구 종류</label>
                        <select id="gear_type" name="gear_type">
                            <option value="">전체</option>
                            <?php foreach ($gearTypes as $gearType): ?>
                                <option value="<?= h($gearType) ?>"<?= $typeFilter === $gearType ? ' selected' : '' ?>><?= h($gearType) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>&nbsp;</label>
                    <div class="actions">
                        <button class="button primary" type="submit">적용</button>
                        <a class="button" href="/safety_gear/status.php?view=<?= h($viewMode) ?>">초기화</a>
                    </div>
                </div>
            </form>
        </section>

        <?php if ($viewMode === 'inventory'): ?>
            <section class="panel">
                <h2>품목별 재고 현황</h2>
                <p class="lead">좌측에 품목 리스트를 두고, 우측에 총수량, 입고일, 지급일, 지급수량, 회수수량, 폐기수량, 현재수량을 집계했습니다.</p>

                <?php if (empty($inventoryRows)): ?>
                    <div class="empty">선택한 조건에 맞는 품목 재고 데이터가 없습니다.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sticky-col">품목리스트</th>
                                    <th>보호구 종류</th>
                                    <th>수량</th>
                                    <th>입고일</th>
                                    <th>지급일</th>
                                    <th>지급수량</th>
                                    <th>회수수량</th>
                                    <th>폐기수량</th>
                                    <th>현재수량</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventoryRows as $row): ?>
                                    <?php
                                    $purchaseDates = status_sort_dates((array)$row['purchase_dates']);
                                    $issuedDates = status_sort_dates((array)$row['issued_dates']);
                                    $productLabel = trim(implode(' / ', array_filter([
                                        sg_normalize_text($row['item_name'] ?? ''),
                                        sg_normalize_text($row['spec_name'] ?? ''),
                                        sg_normalize_text($row['model_name'] ?? ''),
                                    ], static fn($value): bool => $value !== '')));
                                    ?>
                                    <tr>
                                        <td class="sticky-col">
                                            <strong><?= h($productLabel !== '' ? $productLabel : '미분류 품목') ?></strong>
                                        </td>
                                        <td><?= h(status_value($row['gear_type'] ?? '')) ?></td>
                                        <td><?= (int)($row['total_count'] ?? 0) ?></td>
                                        <td><?= h(!empty($purchaseDates) ? implode(', ', $purchaseDates) : '-') ?></td>
                                        <td>
                                            <?php if (empty($issuedDates)): ?>
                                                <span class="muted">-</span>
                                            <?php else: ?>
                                                <div class="date-stack">
                                                    <?php foreach ($issuedDates as $issuedDate): ?>
                                                        <?php $issuedPeople = array_values((array)($row['issued_people_by_date'][$issuedDate] ?? [])); ?>
                                                        <button
                                                            type="button"
                                                            class="date-chip button-chip issued-date-button"
                                                            data-product-label="<?= h($productLabel !== '' ? $productLabel : '미분류 품목') ?>"
                                                            data-issued-date="<?= h($issuedDate) ?>"
                                                            data-issued-people="<?= status_json_attr($issuedPeople) ?>"
                                                        ><?= h($issuedDate) ?></button>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)($row['issued_count'] ?? 0) ?></td>
                                        <td><?= (int)($row['returned_count'] ?? 0) ?></td>
                                        <td><?= (int)($row['disposed_count'] ?? 0) ?></td>
                                        <td><?= (int)($row['current_count'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="caption">`지급수량`은 현재 지급 상태이거나 지급자로 연결된 수량, `회수수량`은 `반납`, `폐기수량`은 `폐기`, `현재수량`은 폐기를 제외하고 현재 창고에 남아 있는 수량 기준입니다.</div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="panel">
                <h2>사람별 지급현황</h2>
                <p class="lead">좌측에는 지급 대상자를 배치하고, 상단에는 보호구 종류를 나열해 각 셀에 지급일자를 표시했습니다.</p>

                <?php if (empty($employeeRows) || empty($gearTypes)): ?>
                    <div class="empty">표시할 지급현황 데이터가 없습니다.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sticky-col">사람 / 팀</th>
                                    <?php foreach ($gearTypes as $gearType): ?>
                                        <th><?= h($gearType) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employeeRows as $row): ?>
                                    <tr>
                                        <td class="sticky-col person-cell">
                                            <div class="person-name"><?= h(status_value($row['employee_name'] ?? '미지정')) ?></div>
                                            <div class="person-team"><?= h(status_value($row['employee_team'] ?? '소속 미지정')) ?></div>
                                        </td>
                                        <?php foreach ($gearTypes as $gearType): ?>
                                            <?php $dates = status_sort_dates((array)($row['cells'][$gearType] ?? [])); ?>
                                            <td>
                                                <?php if (empty($dates)): ?>
                                                    <span class="muted">-</span>
                                                <?php else: ?>
                                                    <div class="date-stack">
                                                        <?php foreach ($dates as $date): ?>
                                                            <span class="date-chip"><?= h($date) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="caption">같은 직원이 같은 보호구 종류를 여러 번 지급받은 경우, 셀 안에 지급일자를 여러 줄로 표시합니다.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <div id="issuedPeopleModal" class="modal-backdrop" hidden>
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="issuedPeopleModalTitle">
            <div class="modal-head">
                <div>
                    <div id="issuedPeopleModalTitle" class="modal-title">지급자 목록</div>
                    <div id="issuedPeopleModalSubtitle" class="modal-subtitle"></div>
                </div>
                <button type="button" class="modal-close" id="issuedPeopleModalClose">닫기</button>
            </div>
            <div id="issuedPeopleModalList" class="modal-list"></div>
        </div>
    </div>
    <script>
        (function () {
            const modal = document.getElementById('issuedPeopleModal');
            const modalClose = document.getElementById('issuedPeopleModalClose');
            const modalSubtitle = document.getElementById('issuedPeopleModalSubtitle');
            const modalList = document.getElementById('issuedPeopleModalList');
            const buttons = Array.from(document.querySelectorAll('.issued-date-button'));

            if (!modal || !modalClose || !modalSubtitle || !modalList || !buttons.length) {
                return;
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function closeModal() {
                modal.classList.remove('open');
                modal.hidden = true;
            }

            function openModal(productLabel, issuedDate, issuedPeople) {
                modalSubtitle.textContent = productLabel + ' / ' + issuedDate;
                modalList.innerHTML = '';

                if (!Array.isArray(issuedPeople) || !issuedPeople.length) {
                    modalList.innerHTML = '<div class="modal-item"><strong>지급자 정보가 없습니다.</strong></div>';
                } else {
                    issuedPeople.forEach(function (person) {
                        const item = document.createElement('div');
                        item.className = 'modal-item' + (person.gear_uid ? ' clickable' : '');
                        item.innerHTML =
                            '<strong>' + escapeHtml(person.employee_name || '미지정') + '</strong>' +
                            '<div class="modal-meta">' +
                            '팀: ' + escapeHtml(person.employee_team || '-') + '<br>' +
                            '지급일시: ' + escapeHtml(person.assigned_at || '-') + '<br>' +
                            '식별값: ' + escapeHtml(person.identifier_value || '-') +
                            '</div>';
                        if (person.gear_uid) {
                            item.addEventListener('click', function () {
                                window.location.href = '/safety_gear/index.php?gear_uid=' + encodeURIComponent(person.gear_uid);
                            });
                        }
                        modalList.appendChild(item);
                    });
                }

                modal.hidden = false;
                modal.classList.add('open');
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    let issuedPeople = [];
                    try {
                        issuedPeople = JSON.parse(button.dataset.issuedPeople || '[]');
                    } catch (error) {
                        issuedPeople = [];
                    }

                    openModal(
                        button.dataset.productLabel || '미분류 품목',
                        button.dataset.issuedDate || '',
                        issuedPeople
                    );
                });
            });

            modalClose.addEventListener('click', closeModal);
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
        })();
    </script>
</body>
</html>
