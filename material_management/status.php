<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();
$search = trim((string)($_GET['q'] ?? ''));
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'deactivate') {
            $materialId = (int)($_POST['material_id'] ?? 0);
            if ($materialId <= 0) {
                throw new RuntimeException('대상을 찾을 수 없습니다.');
            }

            $stmt = $pdo->prepare("
                UPDATE material_management_items
                SET is_active = 0,
                    updated_by = :updated_by
                WHERE material_id = :material_id
            ");
            $stmt->execute([
                ':updated_by' => auth_display_name($user),
                ':material_id' => $materialId,
            ]);

            $redirectParams = ['notice' => 'deactivated'];
            if ($search !== '') {
                $redirectParams['q'] = $search;
            }
            header('Location: ' . mm_build_url('/material_management/status.php', $redirectParams));
            exit;
        }

        if ($action === 'delete_movement') {
            $movementId = (int)($_POST['movement_id'] ?? 0);
            if ($movementId <= 0) {
                throw new RuntimeException('삭제할 문서를 찾을 수 없습니다.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT movement_id, material_id, movement_type, quantity
                FROM material_management_movements
                WHERE movement_id = :movement_id
                FOR UPDATE
            ");
            $stmt->execute([':movement_id' => $movementId]);
            $movement = $stmt->fetch();
            if (!is_array($movement)) {
                throw new RuntimeException('삭제할 문서를 찾을 수 없습니다.');
            }

            $stmt = $pdo->prepare("
                SELECT current_stock
                FROM material_management_items
                WHERE material_id = :material_id
                FOR UPDATE
            ");
            $stmt->execute([':material_id' => (int)$movement['material_id']]);
            $item = $stmt->fetch();
            if (!is_array($item)) {
                throw new RuntimeException('연결된 품목을 찾을 수 없습니다.');
            }

            $quantity = (float)($movement['quantity'] ?? 0);
            $currentStock = (float)($item['current_stock'] ?? 0);
            $movementType = (string)($movement['movement_type'] ?? '');

            if ($movementType === 'in') {
                if ($currentStock < $quantity) {
                    throw new RuntimeException('이 입고 문서를 삭제하면 현재고가 음수가 되어 삭제할 수 없습니다.');
                }

                $stmt = $pdo->prepare("
                    UPDATE material_management_items
                    SET current_stock = current_stock - :quantity,
                        updated_by = :updated_by
                    WHERE material_id = :material_id
                ");
                $stmt->execute([
                    ':quantity' => $quantity,
                    ':updated_by' => auth_display_name($user),
                    ':material_id' => (int)$movement['material_id'],
                ]);
            } elseif ($movementType === 'out') {
                $stmt = $pdo->prepare("
                    UPDATE material_management_items
                    SET current_stock = current_stock + :quantity,
                        updated_by = :updated_by
                    WHERE material_id = :material_id
                ");
                $stmt->execute([
                    ':quantity' => $quantity,
                    ':updated_by' => auth_display_name($user),
                    ':material_id' => (int)$movement['material_id'],
                ]);
            } else {
                throw new RuntimeException('알 수 없는 문서 구분입니다.');
            }

            $stmt = $pdo->prepare('DELETE FROM material_management_movements WHERE movement_id = :movement_id');
            $stmt->execute([':movement_id' => $movementId]);

            $pdo->commit();

            $redirectParams = ['notice' => 'movement_deleted'];
            if ($search !== '') {
                $redirectParams['q'] = $search;
            }
            header('Location: ' . mm_build_url('/material_management/status.php', $redirectParams));
            exit;
        }

        throw new RuntimeException('잘못된 요청입니다.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$itemsSql = "
    SELECT material_id, material_name, msds_file_name, msds_file_path, manufacturer_name,
           supplier_name, storage_location, unit_name, current_stock, notes, is_active, updated_at
    FROM material_management_items
";
$itemsParams = [];
if ($search !== '') {
    $itemsSql .= "
        WHERE material_name LIKE :q
           OR storage_location LIKE :q
           OR supplier_name LIKE :q
           OR manufacturer_name LIKE :q
    ";
    $itemsParams[':q'] = '%' . $search . '%';
}
$itemsSql .= ' ORDER BY is_active DESC, material_name ASC, material_id DESC';
$itemsStmt = $pdo->prepare($itemsSql);
$itemsStmt->execute($itemsParams);
$allItems = $itemsStmt->fetchAll();

$activeItems = array_values(array_filter($allItems, static fn (array $item): bool => (int)($item['is_active'] ?? 0) === 1));

$locationSql = "
    SELECT
        i.material_id,
        i.material_name,
        i.msds_file_name,
        i.msds_file_path,
        i.manufacturer_name,
        i.supplier_name,
        i.unit_name,
        i.is_active,
        i.updated_at,
        COALESCE(NULLIF(TRIM(m.storage_location), ''), '미지정') AS storage_location,
        SUM(CASE WHEN m.movement_type = 'in' THEN m.quantity ELSE -m.quantity END) AS current_stock
    FROM material_management_items i
    INNER JOIN material_management_movements m ON m.material_id = i.material_id
    WHERE i.is_active = 1
";
$locationParams = [];
if ($search !== '') {
    $locationSql .= "
        AND (
            i.material_name LIKE :q
            OR i.storage_location LIKE :q
            OR i.supplier_name LIKE :q
            OR i.manufacturer_name LIKE :q
            OR m.storage_location LIKE :q
        )
    ";
    $locationParams[':q'] = '%' . $search . '%';
}
$locationSql .= "
    GROUP BY
        i.material_id,
        i.material_name,
        i.msds_file_name,
        i.msds_file_path,
        i.manufacturer_name,
        i.supplier_name,
        i.unit_name,
        i.is_active,
        i.updated_at,
        COALESCE(NULLIF(TRIM(m.storage_location), ''), '미지정')
    HAVING ABS(SUM(CASE WHEN m.movement_type = 'in' THEN m.quantity ELSE -m.quantity END)) > 0.0001
    ORDER BY storage_location ASC, i.material_name ASC, i.material_id DESC
";
$locationStmt = $pdo->prepare($locationSql);
$locationStmt->execute($locationParams);
$locationRows = $locationStmt->fetchAll();

$summary = [
    'material_count' => count($allItems),
    'active_count' => count($activeItems),
];

$recentSql = "
    SELECT m.movement_id, m.movement_type, m.quantity, m.movement_date, m.partner_name, m.storage_location, m.document_number, m.notes,
           i.material_name, i.unit_name
    FROM material_management_movements m
    INNER JOIN material_management_items i ON i.material_id = m.material_id
    WHERE i.is_active = 1
";
$recentParams = [];
if ($search !== '') {
    $recentSql .= " AND i.material_name LIKE :q";
    $recentParams[':q'] = '%' . $search . '%';
}
$recentSql .= " ORDER BY m.movement_date DESC, m.movement_id DESC LIMIT 30";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($recentParams);
$recentMovements = $recentStmt->fetchAll();

$noticeCode = trim((string)($_GET['notice'] ?? ''));
if ($noticeCode === 'deactivated') {
    $notice = '항목을 미사용 상태로 변경했습니다.';
} elseif ($noticeCode === 'movement_deleted') {
    $notice = '문서를 삭제하고 재고를 반영했습니다.';
}

mm_page_header('제품 현황', '현재 재고와 최근 입출고 이력을 한 화면에서 확인합니다.');
?>
<style>
    .summary-card {
        border: 0;
        text-align: left;
        cursor: default;
    }
    .summary-card.js-open-modal {
        cursor: pointer;
    }
    .summary-card.js-open-modal:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }
    .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.48);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 1000;
    }
    .modal-backdrop.open {
        display: flex;
    }
    .modal-panel {
        width: min(1120px, 100%);
        max-height: min(86vh, 920px);
        background: #fff;
        border-radius: 20px;
        border: 1px solid var(--line);
        box-shadow: 0 18px 48px rgba(15, 23, 42, 0.22);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .modal-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid #e5edf5;
    }
    .modal-head h3 {
        font-size: 20px;
    }
    .modal-body {
        padding: 0 20px 20px;
        overflow: auto;
    }
    .modal-close {
        min-width: 44px;
        height: 44px;
        border-radius: 12px;
    }
</style>

<?php if ($notice !== ''): ?><div class="notice"><?= mm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= mm_h($error) ?></div><?php endif; ?>

<form method="get" style="margin-top:16px;">
    <div class="row">
        <input class="grow" type="text" name="q" value="<?= mm_h($search) ?>" placeholder="품목명, 제조사, 공급처, 보관위치 검색">
        <button type="submit" class="secondary">검색</button>
        <a class="button ghost" href="/material_management/status.php">초기화</a>
    </div>
</form>

<div class="summary-grid">
    <button type="button" class="summary-card js-open-modal" data-modal-id="registered-items-modal">
        <strong>등록 품목</strong>
        <span><?= number_format((int)($summary['material_count'] ?? 0)) ?></span>
    </button>
    <button type="button" class="summary-card js-open-modal" data-modal-id="active-items-modal">
        <strong>사용중 품목</strong>
        <span><?= number_format((int)($summary['active_count'] ?? 0)) ?></span>
    </button>
    <div class="summary-card">
        <strong>최근 이력</strong>
        <span><?= number_format(count($recentMovements)) ?></span>
    </div>
</div>

<div class="table-wrap" style="margin-top:18px;">
    <table>
        <thead>
            <tr>
                <th>품목명</th>
                <th>MSDS</th>
                <th>보관위치</th>
                <th>제조사</th>
                <th>공급처</th>
                <th>현재고</th>
                <th>상태</th>
                <th>수정일</th>
                <th>작업</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($locationRows)): ?>
                <tr><td colspan="9" class="empty">등록된 품목 정보가 없습니다.</td></tr>
            <?php else: ?>
                <?php foreach ($locationRows as $row): ?>
                    <tr>
                        <td><?= mm_h((string)($row['material_name'] ?? '')) ?></td>
                        <td>
                            <?php if (trim((string)($row['msds_file_path'] ?? '')) !== ''): ?>
                                <a class="button secondary" href="<?= mm_h((string)$row['msds_file_path']) ?>" target="_blank" rel="noopener">MSDS 보기</a>
                            <?php else: ?>
                                <span class="pill off">없음</span>
                            <?php endif; ?>
                        </td>
                        <td><?= mm_h((string)($row['storage_location'] ?? '')) ?></td>
                        <td><?= mm_h((string)($row['manufacturer_name'] ?? '')) ?></td>
                        <td><?= mm_h((string)($row['supplier_name'] ?? '')) ?></td>
                        <td><?= mm_format_quantity($row['current_stock'] ?? 0) ?> <?= mm_h((string)($row['unit_name'] ?? '')) ?></td>
                        <td><span class="pill on">사용중</span></td>
                        <td><?= mm_h((string)($row['updated_at'] ?? '')) ?></td>
                        <td>
                            <div class="row">
                                <a class="button ghost" href="<?= mm_h(mm_build_url('/material_management/item_ledger_print.php', ['material_id' => (int)($row['material_id'] ?? 0), 'storage_location' => (string)($row['storage_location'] ?? '')])) ?>" target="_blank" rel="noopener">출력</a>
                                <a class="button secondary" href="<?= mm_h(mm_build_url('/material_management/item_edit.php', ['material_id' => (int)($row['material_id'] ?? 0)])) ?>">수정</a>
                                <form method="post" onsubmit="return confirm('이 항목을 미사용 상태로 변경하시겠습니까?');">
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="material_id" value="<?= (int)($row['material_id'] ?? 0) ?>">
                                    <button type="submit" class="danger">삭제</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>일자</th>
                <th>구분</th>
                <th>품목명</th>
                <th>보관위치</th>
                <th>수량</th>
                <th>거래처 / 사용처</th>
                <th>문서번호</th>
                <th>비고</th>
                <th>작업</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentMovements)): ?>
                <tr><td colspan="9" class="empty">최근 입출고 이력이 없습니다.</td></tr>
            <?php else: ?>
                <?php foreach ($recentMovements as $movement): ?>
                    <tr>
                        <td><?= mm_h((string)($movement['movement_date'] ?? '')) ?></td>
                        <td><?= (string)($movement['movement_type'] ?? '') === 'in' ? '입고' : '출고' ?></td>
                        <td><?= mm_h((string)($movement['material_name'] ?? '')) ?></td>
                        <td><?= mm_h((string)($movement['storage_location'] ?? '')) ?></td>
                        <td><?= mm_format_quantity($movement['quantity'] ?? 0) ?> <?= mm_h((string)($movement['unit_name'] ?? '')) ?></td>
                        <td><?= mm_h((string)($movement['partner_name'] ?? '')) ?></td>
                        <td><?= mm_h((string)($movement['document_number'] ?? '')) ?></td>
                        <td class="wrap"><?= mm_h((string)($movement['notes'] ?? '')) ?></td>
                        <td>
                            <div class="row">
                                <a class="button ghost" href="<?= mm_h(mm_build_url('/material_management/movement_edit.php', ['movement_id' => (int)($movement['movement_id'] ?? 0), 'mode' => 'view'])) ?>">보기</a>
                                <a class="button secondary" href="<?= mm_h(mm_build_url('/material_management/movement_edit.php', ['movement_id' => (int)($movement['movement_id'] ?? 0), 'mode' => 'edit'])) ?>">수정</a>
                                <form method="post" onsubmit="return confirm('이 문서를 삭제하시겠습니까? 재고도 함께 조정됩니다.');">
                                    <input type="hidden" name="action" value="delete_movement">
                                    <input type="hidden" name="movement_id" value="<?= (int)($movement['movement_id'] ?? 0) ?>">
                                    <button type="submit" class="danger">삭제</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="registered-items-modal" class="modal-backdrop" aria-hidden="true">
    <div class="modal-panel">
        <div class="modal-head">
            <h3>등록 품목 상세보기</h3>
            <button type="button" class="button secondary modal-close" data-close-modal="registered-items-modal">닫기</button>
        </div>
        <div class="modal-body">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>품목명</th>
                            <th>보관위치</th>
                            <th>제조사</th>
                            <th>공급처</th>
                            <th>현재고</th>
                            <th>상태</th>
                            <th>수정일</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allItems)): ?>
                            <tr><td colspan="7" class="empty">표시할 품목이 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allItems as $item): ?>
                                <tr>
                                    <td><?= mm_h((string)($item['material_name'] ?? '')) ?></td>
                                    <td><?= mm_h((string)($item['storage_location'] ?? '')) ?></td>
                                    <td><?= mm_h((string)($item['manufacturer_name'] ?? '')) ?></td>
                                    <td><?= mm_h((string)($item['supplier_name'] ?? '')) ?></td>
                                    <td><?= mm_format_quantity((float)($item['current_stock'] ?? 0)) ?> <?= mm_h((string)($item['unit_name'] ?? '')) ?></td>
                                    <td>
                                        <span class="pill <?= (int)($item['is_active'] ?? 0) === 1 ? 'on' : 'off' ?>">
                                            <?= (int)($item['is_active'] ?? 0) === 1 ? '사용중' : '미사용' ?>
                                        </span>
                                    </td>
                                    <td><?= mm_h((string)($item['updated_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="active-items-modal" class="modal-backdrop" aria-hidden="true">
    <div class="modal-panel">
        <div class="modal-head">
            <h3>사용중 품목 상세보기</h3>
            <button type="button" class="button secondary modal-close" data-close-modal="active-items-modal">닫기</button>
        </div>
        <div class="modal-body">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>품목명</th>
                            <th>보관위치</th>
                            <th>제조사</th>
                            <th>공급처</th>
                            <th>현재고</th>
                            <th>상태</th>
                            <th>수정일</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allItems)): ?>
                            <tr><td colspan="7" class="empty">표시할 품목이 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allItems as $item): ?>
                                <tr>
                                    <td><?= mm_h((string)($item['material_name'] ?? '')) ?></td>
                                    <td><?= mm_h((string)($item['storage_location'] ?? '')) ?></td>
                                    <td><?= mm_h((string)($item['manufacturer_name'] ?? '')) ?></td>
                                    <td><?= mm_h((string)($item['supplier_name'] ?? '')) ?></td>
                                    <td><?= mm_format_quantity((float)($item['current_stock'] ?? 0)) ?> <?= mm_h((string)($item['unit_name'] ?? '')) ?></td>
                                    <td>
                                        <span class="pill <?= (int)($item['is_active'] ?? 0) === 1 ? 'on' : 'off' ?>">
                                            <?= (int)($item['is_active'] ?? 0) === 1 ? '사용중' : '미사용' ?>
                                        </span>
                                    </td>
                                    <td><?= mm_h((string)($item['updated_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.js-open-modal').forEach(function (button) {
    button.addEventListener('click', function () {
        var modalId = button.getAttribute('data-modal-id');
        var modal = modalId ? document.getElementById(modalId) : null;
        if (!modal) {
            return;
        }
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    });
});

document.querySelectorAll('[data-close-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        var modalId = button.getAttribute('data-close-modal');
        var modal = modalId ? document.getElementById(modalId) : null;
        if (!modal) {
            return;
        }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    });
});

document.querySelectorAll('.modal-backdrop').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target !== modal) {
            return;
        }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }
    document.querySelectorAll('.modal-backdrop.open').forEach(function (modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    });
});
</script>
<?php
mm_page_footer();
