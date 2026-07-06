<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();

$materialId = (int)($_GET['material_id'] ?? 0);
$storageLocation = trim((string)($_GET['storage_location'] ?? ''));

if ($materialId <= 0) {
    http_response_code(400);
    echo '잘못된 요청입니다.';
    exit;
}

$itemStmt = $pdo->prepare("
    SELECT material_id, material_name, manufacturer_name, supplier_name, storage_location, unit_name, current_stock, notes, updated_at
    FROM material_management_items
    WHERE material_id = :material_id
    LIMIT 1
");
$itemStmt->execute([':material_id' => $materialId]);
$item = $itemStmt->fetch();

if (!is_array($item)) {
    http_response_code(404);
    echo '품목을 찾을 수 없습니다.';
    exit;
}

$ledgerSql = "
    SELECT movement_type, quantity, movement_date, partner_name, storage_location, document_number, notes, created_by, created_at
    FROM material_management_movements
    WHERE material_id = :material_id
";
$ledgerParams = [':material_id' => $materialId];
if ($storageLocation !== '') {
    $ledgerSql .= " AND COALESCE(storage_location, '') = :storage_location";
    $ledgerParams[':storage_location'] = $storageLocation;
}
$ledgerSql .= " ORDER BY movement_date DESC, movement_id DESC";
$ledgerStmt = $pdo->prepare($ledgerSql);
$ledgerStmt->execute($ledgerParams);
$ledgerRows = $ledgerStmt->fetchAll();

$displayLocation = $storageLocation !== '' ? $storageLocation : (string)($item['storage_location'] ?? '');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>품목별 관리대장</title>
    <style>
        body {
            margin: 0;
            padding: 24px;
            font-family: "Malgun Gothic", sans-serif;
            color: #111827;
            background: #f3f6fb;
        }
        .page {
            max-width: 1120px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #dbe4ef;
            border-radius: 18px;
            padding: 24px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .title {
            font-size: 28px;
            font-weight: 800;
        }
        .subtitle {
            margin-top: 6px;
            color: #6b7280;
            font-size: 13px;
        }
        .actions {
            display: flex;
            gap: 8px;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            padding: 14px;
            background: #f8fbff;
        }
        .card strong {
            display: block;
            font-size: 12px;
            color: #64748b;
        }
        .card span {
            display: block;
            margin-top: 8px;
            font-size: 18px;
            font-weight: 700;
        }
        .table-wrap {
            border: 1px solid #dbe4ef;
            border-radius: 16px;
            overflow: hidden;
            background: #fff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid #e5edf5;
            text-align: left;
            font-size: 13px;
        }
        th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
        }
        .section-title {
            margin: 24px 0 10px;
            font-size: 18px;
            font-weight: 700;
        }
        .empty {
            text-align: center;
            color: #6b7280;
            padding: 24px 12px;
        }
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page {
                max-width: none;
                border: 0;
                border-radius: 0;
                padding: 0;
            }
            .actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div>
                <div class="title">품목별 관리대장</div>
                <div class="subtitle">출력일시: <?= mm_h(date('Y-m-d H:i:s')) ?></div>
            </div>
            <div class="actions">
                <button type="button" class="button" onclick="window.print()">인쇄</button>
                <a class="button" href="/material_management/status.php">닫기</a>
            </div>
        </div>

        <div class="summary">
            <div class="card">
                <strong>품목명</strong>
                <span><?= mm_h((string)($item['material_name'] ?? '')) ?></span>
            </div>
            <div class="card">
                <strong>보관위치</strong>
                <span><?= mm_h($displayLocation !== '' ? $displayLocation : '-') ?></span>
            </div>
            <div class="card">
                <strong>제조사 / 공급처</strong>
                <span><?= mm_h(trim(((string)($item['manufacturer_name'] ?? '')) . ' / ' . ((string)($item['supplier_name'] ?? '')), ' /')) ?></span>
            </div>
            <div class="card">
                <strong>현재고</strong>
                <span><?= mm_format_quantity((float)($item['current_stock'] ?? 0)) ?> <?= mm_h((string)($item['unit_name'] ?? '')) ?></span>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <tbody>
                    <tr>
                        <th style="width:160px;">비고</th>
                        <td><?= nl2br(mm_h((string)($item['notes'] ?? ''))) ?></td>
                    </tr>
                    <tr>
                        <th>최종수정일</th>
                        <td><?= mm_h((string)($item['updated_at'] ?? '')) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="section-title">입출고 이력</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>일자</th>
                        <th>구분</th>
                        <th>보관위치</th>
                        <th>수량</th>
                        <th>거래처 / 사용처</th>
                        <th>문서번호</th>
                        <th>비고</th>
                        <th>작성자</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledgerRows)): ?>
                        <tr><td colspan="8" class="empty">표시할 이력이 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledgerRows as $row): ?>
                            <tr>
                                <td><?= mm_h((string)($row['movement_date'] ?? '')) ?></td>
                                <td><?= (string)($row['movement_type'] ?? '') === 'in' ? '입고' : '출고' ?></td>
                                <td><?= mm_h((string)($row['storage_location'] ?? '')) ?></td>
                                <td><?= mm_format_quantity((float)($row['quantity'] ?? 0)) ?> <?= mm_h((string)($item['unit_name'] ?? '')) ?></td>
                                <td><?= mm_h((string)($row['partner_name'] ?? '')) ?></td>
                                <td><?= mm_h((string)($row['document_number'] ?? '')) ?></td>
                                <td><?= mm_h((string)($row['notes'] ?? '')) ?></td>
                                <td><?= mm_h((string)($row['created_by'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
