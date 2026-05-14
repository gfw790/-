<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function report_value($value, string $fallback = '-'): string
{
    $text = sg_normalize_text((string)$value);
    return $text !== '' ? $text : $fallback;
}

$pdo = sg_get_pdo();
$mode = sg_normalize_text($_GET['mode'] ?? 'employee');
$employeeId = sg_normalize_text($_GET['employee_id'] ?? '');
$gearUid = sg_normalize_text($_GET['gear_uid'] ?? '');
$gearType = sg_normalize_text($_GET['gear_type'] ?? '');
$itemName = sg_normalize_text($_GET['item_name'] ?? '');
$modelName = sg_normalize_text($_GET['model_name'] ?? '');
$dateFrom = sg_normalize_text($_GET['date_from'] ?? '');
$dateTo = sg_normalize_text($_GET['date_to'] ?? '');

$employees = sg_fetch_employee_options();
$items = sg_fetch_all_items($pdo);

$gearTypes = array_values(array_unique(array_filter(array_map(static function (array $item): string {
    return sg_normalize_text($item['gear_type'] ?? '');
}, $items))));
sort($gearTypes);

$itemNames = array_values(array_unique(array_filter(array_map(static function (array $item): string {
    return sg_normalize_text($item['item_name'] ?? '');
}, $items))));
sort($itemNames);

$modelNames = array_values(array_unique(array_filter(array_map(static function (array $item): string {
    return sg_normalize_text($item['model_name'] ?? '');
}, $items))));
sort($modelNames);

$selectedEmployee = null;
foreach ($employees as $employee) {
    if ((string)($employee['id'] ?? '') === $employeeId) {
        $selectedEmployee = $employee;
        break;
    }
}

$selectedItem = null;
foreach ($items as $item) {
    if (($item['id'] ?? '') === $gearUid) {
        $selectedItem = $item;
        break;
    }
}

$employeeHistoryRows = [];
$employeeAssignedItems = [];
$itemHistoryRows = [];

if ($mode === 'employee' && $employeeId !== '') {
    $employeeHistoryRows = sg_fetch_employee_history_report($pdo, $employeeId, '', $dateFrom, $dateTo);
    $employeeAssignedItems = array_values(array_filter($items, static function (array $item) use ($employeeId): bool {
        return (string)($item['assigned_employee_id'] ?? '') === $employeeId;
    }));
}

if ($mode === 'item' && $gearUid !== '') {
    $itemHistoryRows = sg_fetch_item_history_report($pdo, $gearUid, '', $dateFrom, $dateTo, $gearType, $itemName, $modelName);
} elseif ($mode === 'item' && ($gearType !== '' || $itemName !== '' || $modelName !== '')) {
    $itemHistoryRows = sg_fetch_item_history_report($pdo, '', '', $dateFrom, $dateTo, $gearType, $itemName, $modelName);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>보호구 지급 이력서</title>
    <style>
        :root {
            --bg: #edf3f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #132238;
            --muted: #64748b;
            --accent: #0f766e;
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
            width: min(1240px, calc(100vw - 24px));
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

        h1, h2, h3 { margin: 0; }

        .lead {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
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

        input, select, button {
            font: inherit;
        }

        input[type="date"], select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: 0;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .button.secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .summary-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #f8fafc;
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            background: #fff;
        }

        th, td {
            border: 1px solid var(--line);
            padding: 10px 12px;
            vertical-align: top;
            text-align: left;
            font-size: 13px;
            line-height: 1.5;
        }

        th {
            background: #f8fafc;
            font-size: 12px;
        }

        .empty {
            margin-top: 14px;
            padding: 24px 18px;
            border: 1px dashed var(--line);
            border-radius: 16px;
            color: var(--muted);
            text-align: center;
            line-height: 1.8;
        }

        @media (max-width: 920px) {
            .filter-grid,
            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .filter-grid,
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .panel {
                box-shadow: none;
                border-radius: 0;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel no-print">
            <h1>보호구 지급 이력서</h1>
            <p class="lead">사람별 또는 물품별로 지급, 회수, 점검 같은 이력을 기간 기준으로 조회하고 바로 인쇄할 수 있습니다.</p>

            <form method="get">
                <div class="filter-grid">
                    <div class="field">
                        <label for="mode">조회 기준</label>
                        <select id="mode" name="mode" onchange="this.form.submit()">
                            <option value="employee"<?= $mode === 'employee' ? ' selected' : '' ?>>사람별</option>
                            <option value="item"<?= $mode === 'item' ? ' selected' : '' ?>>물품별</option>
                        </select>
                    </div>

                    <div class="field"<?= $mode === 'item' ? ' style="display:none;"' : '' ?>>
                        <label for="employee_id">직원</label>
                        <select id="employee_id" name="employee_id">
                            <option value="">선택하세요</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= h((string)($employee['id'] ?? '')) ?>"<?= $employeeId === (string)($employee['id'] ?? '') ? ' selected' : '' ?>>
                                    [<?= h((string)($employee['team'] ?? '-')) ?>] <?= h((string)($employee['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field"<?= $mode === 'employee' ? ' style="display:none;"' : '' ?>>
                        <label for="gear_type">보호구 종류</label>
                        <select id="gear_type" name="gear_type">
                            <option value="">전체</option>
                            <?php foreach ($gearTypes as $option): ?>
                                <option value="<?= h($option) ?>"<?= $gearType === $option ? ' selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field"<?= $mode === 'employee' ? ' style="display:none;"' : '' ?>>
                        <label for="item_name">품명</label>
                        <select id="item_name" name="item_name">
                            <option value="">전체</option>
                            <?php foreach ($itemNames as $option): ?>
                                <option value="<?= h($option) ?>"<?= $itemName === $option ? ' selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field"<?= $mode === 'employee' ? ' style="display:none;"' : '' ?>>
                        <label for="model_name">모델</label>
                        <select id="model_name" name="model_name">
                            <option value="">전체</option>
                            <?php foreach ($modelNames as $option): ?>
                                <option value="<?= h($option) ?>"<?= $modelName === $option ? ' selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="date_from">시작일</label>
                        <input id="date_from" type="date" name="date_from" value="<?= h($dateFrom) ?>">
                    </div>

                    <div class="field">
                        <label for="date_to">종료일</label>
                        <input id="date_to" type="date" name="date_to" value="<?= h($dateTo) ?>">
                    </div>
                </div>

                <div class="actions">
                    <button class="button" type="submit">조회</button>
                    <a class="button secondary" href="/safety_gear/report.php">초기화</a>
                    <button class="button secondary" type="button" onclick="window.print()">인쇄</button>
                    <a class="button secondary" href="/safety_gear/index.php">관리 페이지</a>
                </div>
            </form>
        </section>

        <?php if ($mode === 'employee'): ?>
            <section class="panel">
                <h2>사람별 보호구 지급 이력서</h2>
                <?php if ($selectedEmployee === null): ?>
                    <div class="empty">직원을 선택하면 현재 보유 보호구와 기간별 이력을 확인할 수 있습니다.</div>
                <?php else: ?>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <span class="label">직원명</span>
                            <div class="value"><?= h(report_value($selectedEmployee['name'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">팀</span>
                            <div class="value"><?= h(report_value($selectedEmployee['team'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">현재 보유 수량</span>
                            <div class="value"><?= count($employeeAssignedItems) ?>건</div>
                        </div>
                        <div class="summary-card">
                            <span class="label">조회 기간</span>
                            <div class="value"><?= h(report_value($dateFrom, '전체')) ?> ~ <?= h(report_value($dateTo, '전체')) ?></div>
                        </div>
                    </div>

                    <h3 style="margin-top:18px;">현재 보유 보호구</h3>
                    <?php if (empty($employeeAssignedItems)): ?>
                        <div class="empty">현재 이 직원에게 연결된 보호구가 없습니다.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>보호구 종류</th>
                                    <th>품명</th>
                                    <th>모델</th>
                                    <th>식별값</th>
                                    <th>상태</th>
                                    <th>지급일시</th>
                                    <th>메모</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employeeAssignedItems as $item): ?>
                                    <tr>
                                        <td><?= h(report_value($item['gear_type'] ?? '')) ?></td>
                                        <td><?= h(report_value($item['item_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($item['model_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($item['identifier_value'] ?? '')) ?></td>
                                        <td><?= h(report_value($item['status'] ?? '')) ?></td>
                                        <td><?= h(report_value($item['assigned_at'] ?? '')) ?></td>
                                        <td><?= nl2br(h(report_value($item['notes'] ?? ''))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3 style="margin-top:18px;">기간별 이력</h3>
                    <?php if (empty($employeeHistoryRows)): ?>
                        <div class="empty">선택한 조건에 해당하는 이력이 없습니다.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>일시</th>
                                    <th>이력 종류</th>
                                    <th>보호구 종류</th>
                                    <th>품명</th>
                                    <th>모델</th>
                                    <th>식별값</th>
                                    <th>상태</th>
                                    <th>내용</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employeeHistoryRows as $row): ?>
                                    <tr>
                                        <td><?= h(report_value($row['created_at'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['history_type'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['gear_type'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['item_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['model_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['identifier_value'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['status_label'] ?? '')) ?></td>
                                        <td><?= nl2br(h(report_value($row['history_note'] ?? ''))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="panel">
                <h2>물품별 보호구 지급 이력서</h2>
                <?php if ($selectedItem === null): ?>
                    <?php if ($gearType === '' && $itemName === '' && $modelName === ''): ?>
                        <div class="empty">보호구 종류, 품명, 모델 중 하나 이상을 선택하면 그 조건에 맞는 이력을 기간 기준으로 조회할 수 있습니다.</div>
                    <?php else: ?>
                        <div class="summary-grid">
                            <div class="summary-card">
                                <span class="label">보호구 종류</span>
                                <div class="value"><?= h(report_value($gearType)) ?></div>
                            </div>
                            <div class="summary-card">
                                <span class="label">품명</span>
                                <div class="value"><?= h(report_value($itemName)) ?></div>
                            </div>
                            <div class="summary-card">
                                <span class="label">모델</span>
                                <div class="value"><?= h(report_value($modelName)) ?></div>
                            </div>
                            <div class="summary-card">
                                <span class="label">조회 이력 수</span>
                                <div class="value"><?= count($itemHistoryRows) ?>건</div>
                            </div>
                        </div>

                        <h3 style="margin-top:18px;">기간별 이력</h3>
                        <?php if (empty($itemHistoryRows)): ?>
                            <div class="empty">선택한 조건에 해당하는 이력이 없습니다.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>일시</th>
                                        <th>이력 종류</th>
                                        <th>보호구 종류</th>
                                        <th>품명</th>
                                        <th>모델</th>
                                        <th>식별값</th>
                                        <th>지급 대상자</th>
                                        <th>지급팀</th>
                                        <th>상태</th>
                                        <th>내용</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itemHistoryRows as $row): ?>
                                        <tr>
                                            <td><?= h(report_value($row['created_at'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['history_type'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['gear_type'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['item_name'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['model_name'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['identifier_value'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['employee_name'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['employee_team'] ?? '')) ?></td>
                                            <td><?= h(report_value($row['status_label'] ?? '')) ?></td>
                                            <td><?= nl2br(h(report_value($row['history_note'] ?? ''))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <span class="label">보호구 종류</span>
                            <div class="value"><?= h(report_value($selectedItem['gear_type'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">품명</span>
                            <div class="value"><?= h(report_value($selectedItem['item_name'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">모델</span>
                            <div class="value"><?= h(report_value($selectedItem['model_name'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">식별값</span>
                            <div class="value"><?= h(report_value($selectedItem['identifier_value'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">현재 상태</span>
                            <div class="value"><?= h(report_value($selectedItem['status'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">현재 지급자</span>
                            <div class="value"><?= h(report_value($selectedItem['assigned_employee_name'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">지급팀</span>
                            <div class="value"><?= h(report_value($selectedItem['assigned_team'] ?? '')) ?></div>
                        </div>
                        <div class="summary-card">
                            <span class="label">지급일시</span>
                            <div class="value"><?= h(report_value($selectedItem['assigned_at'] ?? '')) ?></div>
                        </div>
                    </div>

                    <h3 style="margin-top:18px;">기간별 이력</h3>
                    <?php if (empty($itemHistoryRows)): ?>
                        <div class="empty">선택한 조건에 해당하는 이력이 없습니다.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>일시</th>
                                    <th>이력 종류</th>
                                    <th>품명</th>
                                    <th>모델</th>
                                    <th>지급 대상자</th>
                                    <th>지급팀</th>
                                    <th>상태</th>
                                    <th>내용</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($itemHistoryRows as $row): ?>
                                    <tr>
                                        <td><?= h(report_value($row['created_at'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['history_type'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['item_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['model_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['employee_name'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['employee_team'] ?? '')) ?></td>
                                        <td><?= h(report_value($row['status_label'] ?? '')) ?></td>
                                        <td><?= nl2br(h(report_value($row['history_note'] ?? ''))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
