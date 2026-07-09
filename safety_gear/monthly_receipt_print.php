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
        <title>월별 보호구 지급 확인서 일괄출력</title>
        <style>
            body { margin: 0; padding: 32px; font-family: "Malgun Gothic", sans-serif; background: #f3f7fb; color: #122033; }
            .panel { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d7e0ea; border-radius: 20px; padding: 24px; }
            .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 12px; background: #0f766e; color: #fff; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>월별 보호구 지급 확인서 일괄출력</h1>
            <p>이 페이지는 안전관리자 또는 관리자만 접근할 수 있습니다.</p>
            <a class="button" href="/risk_assessment/work_list.php">작업 목록으로 이동</a>
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

function monthly_print_value($value, string $fallback = '-'): string
{
    $text = sg_normalize_text((string)$value);
    return $text !== '' ? $text : $fallback;
}

function monthly_print_month_label(string $month): string
{
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return $month;
    }

    return substr($month, 0, 4) . '년 ' . substr($month, 5, 2) . '월';
}

function monthly_print_document_no(string $month, int $index): string
{
    return 'MGR-' . str_replace('-', '', $month) . '-' . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
}

function monthly_print_group_rows(array $receipts): array
{
    $rows = [];
    foreach ($receipts as $receipt) {
        $issueDate = sg_normalize_text($receipt['issue_date'] ?? '');
        foreach ((array)($receipt['items'] ?? []) as $item) {
            $gearLabel = sg_normalize_text($item['gear_label'] ?? '');
            $itemName = sg_normalize_text($item['item_name'] ?? '');
            $specName = sg_normalize_text($item['spec_name'] ?? '');
            $modelName = sg_normalize_text($item['model_name'] ?? '');
            $manufacturerName = sg_normalize_text($item['manufacturer_name'] ?? '');
            $kcsCertNo = sg_normalize_text($item['kcs_cert_no'] ?? '');
            $assignedDate = sg_normalize_text($item['assigned_date'] ?? $issueDate);
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $key = implode('|', [$gearLabel, $itemName, $specName, $modelName, $manufacturerName, $kcsCertNo, $assignedDate]);

            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'gear_label' => $gearLabel !== '' ? $gearLabel : $itemName,
                    'item_name' => $itemName,
                    'spec_name' => $specName,
                    'model_name' => $modelName,
                    'manufacturer_name' => $manufacturerName,
                    'kcs_cert_no' => $kcsCertNo,
                    'quantity' => 0,
                    'assigned_date' => $assignedDate,
                ];
            }

            $rows[$key]['quantity'] += $quantity;
        }
    }

    $result = array_values($rows);
    usort($result, static function (array $a, array $b): int {
        $dateCompare = strcmp(
            sg_normalize_text($a['assigned_date'] ?? ''),
            sg_normalize_text($b['assigned_date'] ?? '')
        );
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp(
            sg_normalize_text($a['gear_label'] ?? ''),
            sg_normalize_text($b['gear_label'] ?? '')
        );
    });

    return $result;
}

$companyName = '(주)현대기전';
$siteName = '광산현대화 1차 Crusher Area 전기공사';
$pledgeText = "본인은 상기 보호구를 지급받았으며, 작업 중 항상 착용하고 분실 및 오사용에 주의하겠습니다.\n보호구를 임의로 양도하거나 목적 외로 사용하지 않겠습니다.";

$pdo = sg_get_pdo();
$receipts = sg_fetch_receipts($pdo, 0, 'daily');

$monthOptions = [];
$teamOptions = [];
foreach ($receipts as $receipt) {
    $issueDate = sg_normalize_text($receipt['issue_date'] ?? '');
    if ($issueDate !== '' && preg_match('/^\d{4}-\d{2}/', $issueDate, $matches) === 1) {
        $monthOptions[$matches[0]] = $matches[0];
    }
    $teamName = sg_normalize_text($receipt['worker_team'] ?? '');
    if ($teamName !== '') {
        $teamOptions[$teamName] = $teamName;
    }
}
krsort($monthOptions);
sort($teamOptions);

$selectedMonth = sg_normalize_text($_GET['month'] ?? '');
if ($selectedMonth === '' || !isset($monthOptions[$selectedMonth])) {
    $selectedMonth = !empty($monthOptions) ? (string)array_key_first($monthOptions) : date('Y-m');
}

$selectedTeam = sg_normalize_text($_GET['team'] ?? '');

$groups = [];
foreach ($receipts as $receipt) {
    $issueDate = sg_normalize_text($receipt['issue_date'] ?? '');
    if ($issueDate === '' || strpos($issueDate, $selectedMonth) !== 0) {
        continue;
    }

    $teamName = sg_normalize_text($receipt['worker_team'] ?? '');
    if ($selectedTeam !== '' && $teamName !== $selectedTeam) {
        continue;
    }

    $workerName = sg_normalize_text($receipt['worker_name'] ?? '');
    if ($workerName === '') {
        continue;
    }

    $groupKey = implode('|', [
        $workerName,
        $teamName,
        sg_normalize_text($receipt['worker_position'] ?? ''),
    ]);

    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
            'worker_name' => $workerName,
            'worker_team' => $teamName,
            'worker_position' => sg_normalize_text($receipt['worker_position'] ?? ''),
            'issue_month' => $selectedMonth,
            'receipts' => [],
        ];
    }

    $groups[$groupKey]['receipts'][] = $receipt;
}

foreach ($groups as &$group) {
    $group['rows'] = monthly_print_group_rows((array)($group['receipts'] ?? []));
    $group['total_quantity'] = array_sum(array_map(static function (array $row): int {
        return (int)($row['quantity'] ?? 0);
    }, (array)($group['rows'] ?? [])));
    $group['issue_dates'] = array_values(array_unique(array_filter(array_map(static function (array $receipt): string {
        return sg_normalize_text($receipt['issue_date'] ?? '');
    }, (array)($group['receipts'] ?? [])))));
    sort($group['issue_dates']);
    $group['document_date'] = !empty($group['issue_dates']) ? (string)max($group['issue_dates']) : $selectedMonth . '-01';
}
unset($group);

$groups = array_values($groups);
usort($groups, static function (array $a, array $b): int {
    $teamCompare = strcmp(
        sg_normalize_text($a['worker_team'] ?? ''),
        sg_normalize_text($b['worker_team'] ?? '')
    );
    if ($teamCompare !== 0) {
        return $teamCompare;
    }

    return strcmp(
        sg_normalize_text($a['worker_name'] ?? ''),
        sg_normalize_text($b['worker_name'] ?? '')
    );
});

$sheetCount = count($groups);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>월별 보호구 지급 확인서 일괄출력</title>
    <style>
        :root {
            --bg: #edf3f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #132238;
            --muted: #64748b;
            --accent: #0f766e;
            --secondary: #e2e8f0;
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

        .actions,
        .inline-actions {
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
            border: 0;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .button.secondary {
            background: var(--secondary);
            color: #0f172a;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
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

        input[type="month"], select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .month-picker {
            margin-top: 16px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 18px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
            padding: 22mm 16mm 18mm;
            page-break-after: always;
        }

        .sheet:last-of-type {
            page-break-after: auto;
        }

        .doc-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .doc-meta {
            font-size: 13px;
            line-height: 1.7;
            color: #334155;
        }

        .doc-title {
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin: 8px 0 18px;
        }

        .doc-subtitle {
            text-align: center;
            margin-top: -8px;
            margin-bottom: 16px;
            color: #64748b;
            font-size: 13px;
        }

        .section-title {
            margin-top: 14px;
            font-size: 16px;
            font-weight: 700;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        th, td {
            border: 1px solid #222;
            padding: 8px 10px;
            font-size: 13px;
            line-height: 1.5;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f8fafc;
            width: 120px;
        }

        .pledge-box {
            margin-top: 8px;
            border: 1px solid #222;
            padding: 10px 12px;
            line-height: 1.65;
            font-size: 13px;
            white-space: pre-line;
        }

        .sign-area {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .sign-box {
            border: 1px solid #222;
            min-height: 102px;
            padding: 10px;
        }

        .sign-box h3 {
            font-size: 14px;
            margin-bottom: 10px;
        }

        .sign-line {
            margin-top: 24px;
            text-align: right;
            font-size: 13px;
        }

        .note {
            margin-top: 10px;
            color: #475569;
            font-size: 11px;
            line-height: 1.55;
        }

        .empty {
            padding: 24px 18px;
            border: 1px dashed var(--line);
            border-radius: 16px;
            color: var(--muted);
            text-align: center;
            line-height: 1.8;
        }

        @media (max-width: 980px) {
            .filter-grid,
            .summary-grid,
            .sign-area {
                grid-template-columns: 1fr;
            }

            .sheet {
                width: 100%;
                min-height: auto;
                padding: 20px 16px;
            }

            .doc-head {
                flex-direction: column;
            }
        }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .page { width: auto; margin: 0; }
            .sheet { width: auto; min-height: auto; margin: 0; box-shadow: none; border: 0; border-radius: 0; page-break-after: always; }
            .sheet:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel no-print">
            <div class="toolbar">
                <div>
                    <h1>월별 보호구 지급 확인서 일괄출력</h1>
                    <p class="lead">선택한 월에 지급된 보호구를 사람별 지급 확인서 형식으로 한 번에 출력합니다.</p>
                </div>
                <div class="actions">
                    <a class="button secondary" href="/safety_gear/status.php">현황</a>
                    <a class="button secondary" href="/safety_gear/receipt_batch_print.php">기존 확인서 출력</a>
                    <button type="button" class="button" onclick="window.print()">인쇄</button>
                </div>
            </div>

            <form method="get" class="filter-grid">
                <div class="field">
                    <label for="month">출력 월</label>
                    <input id="month" name="month" type="month" value="<?= h($selectedMonth) ?>" onchange="this.form.submit()">
                </div>
                <div class="field">
                    <label for="team">팀 필터</label>
                    <select id="team" name="team" onchange="this.form.submit()">
                        <option value="">전체</option>
                        <?php foreach ($teamOptions as $teamName): ?>
                            <option value="<?= h($teamName) ?>"<?= $selectedTeam === $teamName ? ' selected' : '' ?>><?= h($teamName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="align-self:end;">
                    <div class="inline-actions">
                        <button type="submit" class="button">바로 출력</button>
                        <a class="button secondary" href="/safety_gear/monthly_receipt_print.php">초기화</a>
                    </div>
                </div>
            </form>

            <?php if (!empty($monthOptions)): ?>
                <div class="month-picker">
                    <?php foreach ($monthOptions as $monthValue): ?>
                        <?php
                        $params = ['month' => $monthValue];
                        if ($selectedTeam !== '') {
                            $params['team'] = $selectedTeam;
                        }
                        ?>
                        <a class="button<?= $selectedMonth === $monthValue ? '' : ' secondary' ?>" href="/safety_gear/monthly_receipt_print.php?<?= h(http_build_query($params)) ?>">
                            <?= h(monthly_print_month_label($monthValue)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="summary-grid">
                <div class="summary-card">
                    <span class="label">출력 월</span>
                    <div class="value"><?= h(monthly_print_month_label($selectedMonth)) ?></div>
                </div>
                <div class="summary-card">
                    <span class="label">출력 인원</span>
                    <div class="value"><?= $sheetCount ?>명</div>
                </div>
                <div class="summary-card">
                    <span class="label">팀 필터</span>
                    <div class="value"><?= h($selectedTeam !== '' ? $selectedTeam : '전체') ?></div>
                </div>
            </div>
        </section>

        <?php if (empty($groups)): ?>
            <section class="panel">
                <div class="empty">선택한 월에 생성할 지급 확인서 데이터가 없습니다.</div>
            </section>
        <?php endif; ?>

        <?php foreach ($groups as $index => $group): ?>
            <section class="sheet">
                <div class="doc-head">
                    <div class="doc-meta">
                        사업장명: <?= h($companyName) ?><br>
                        현장명: <?= h($siteName) ?>
                    </div>
                    <div class="doc-meta" style="text-align:right;">
                        문서번호: <?= h(monthly_print_document_no((string)($group['issue_month'] ?? ''), $index + 1)) ?><br>
                        작성일: <?= h(monthly_print_value($group['document_date'] ?? date('Y-m-d'))) ?>
                    </div>
                </div>

                <div class="doc-title">보호구 지급 확인서</div>
                <div class="doc-subtitle"><?= h(monthly_print_month_label((string)($group['issue_month'] ?? ''))) ?> 지급분</div>

                <table>
                    <tr>
                        <th>소속</th>
                        <td>공사팀</td>
                        <th>직무</th>
                        <td><?= h(monthly_print_value($group['worker_position'] ?? '')) ?></td>
                    </tr>
                    <tr>
                        <th>성명</th>
                        <td><?= h(monthly_print_value($group['worker_name'] ?? '')) ?></td>
                        <th>지급 일자</th>
                        <td><?= h(!empty($group['issue_dates']) ? implode(', ', (array)$group['issue_dates']) : '-') ?></td>
                    </tr>
                </table>

                <div class="section-title">지급 보호구 상세</div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:48px;">No.</th>
                            <th>보호구 명칭</th>
                            <th>품명</th>
                            <th>규격</th>
                            <th>모델명</th>
                            <th>제조사</th>
                            <th>KCS 안전인증번호</th>
                            <th style="width:88px;">지급 수량</th>
                            <th style="width:120px;">지급 일자</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ((array)($group['rows'] ?? []) as $rowIndex => $row): ?>
                            <tr>
                                <td><?= $rowIndex + 1 ?></td>
                                <td><?= h(monthly_print_value($row['gear_label'] ?? '')) ?></td>
                                <td><?= h(monthly_print_value($row['item_name'] ?? '')) ?></td>
                                <td><?= h(monthly_print_value($row['spec_name'] ?? '')) ?></td>
                                <td><?= h(monthly_print_value($row['model_name'] ?? '')) ?></td>
                                <td><?= h(monthly_print_value($row['manufacturer_name'] ?? '')) ?></td>
                                <td><?= h(monthly_print_value($row['kcs_cert_no'] ?? '')) ?></td>
                                <td><?= (int)($row['quantity'] ?? 0) ?></td>
                                <td><?= h(monthly_print_value($row['assigned_date'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="section-title">근로자 준수 서약</div>
                <div class="pledge-box"><?= nl2br(h($pledgeText)) ?></div>

                <div class="sign-area">
                    <div class="sign-box">
                        <h3>수령자 자필 서명</h3>
                        <div class="sign-line">서명: ____________________</div>
                        <div class="sign-line" style="margin-top:18px;">서명일: ____________________</div>
                    </div>
                </div>

                <div class="note">
                    비고: 본 확인서는 해당 월 보호구 지급 사실을 문서화하기 위한 용도입니다. 총 지급 수량은 <?= (int)($group['total_quantity'] ?? 0) ?>개입니다.
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</body>
</html>
