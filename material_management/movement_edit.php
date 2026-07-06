<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();

$movementId = (int)($_GET['movement_id'] ?? $_POST['movement_id'] ?? 0);
$mode = trim((string)($_GET['mode'] ?? 'edit'));
if ($mode !== 'view') {
    $mode = 'edit';
}

$notice = '';
$error = '';

if ($movementId <= 0) {
    http_response_code(400);
    mm_page_header('문서 상세', '잘못된 문서 요청입니다.');
    echo '<div class="error">문서를 찾을 수 없습니다.</div>';
    mm_page_footer();
    exit;
}

$loadMovement = static function (PDO $pdo, int $movementId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            m.movement_id,
            m.material_id,
            m.movement_type,
            m.quantity,
            m.movement_date,
            m.partner_name,
            m.storage_location,
            m.document_number,
            m.notes,
            m.created_by,
            m.created_at,
            i.material_name,
            i.unit_name,
            i.current_stock
        FROM material_management_movements m
        INNER JOIN material_management_items i ON i.material_id = m.material_id
        WHERE m.movement_id = :movement_id
        LIMIT 1
    ");
    $stmt->execute([':movement_id' => $movementId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
};

$movement = $loadMovement($pdo, $movementId);
if ($movement === null) {
    http_response_code(404);
    mm_page_header('문서 상세', '문서를 찾을 수 없습니다.');
    echo '<div class="error">문서를 찾을 수 없습니다.</div>';
    mm_page_footer();
    exit;
}

$form = [
    'movement_date' => (string)($movement['movement_date'] ?? date('Y-m-d')),
    'quantity' => (string)mm_format_quantity($movement['quantity'] ?? 0),
    'partner_name' => (string)($movement['partner_name'] ?? ''),
    'storage_location' => (string)($movement['storage_location'] ?? ''),
    'notes' => (string)($movement['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'edit') {
    $form = [
        'movement_date' => trim((string)($_POST['movement_date'] ?? date('Y-m-d'))),
        'quantity' => trim((string)($_POST['quantity'] ?? '')),
        'partner_name' => trim((string)($_POST['partner_name'] ?? '')),
        'storage_location' => trim((string)($_POST['storage_location'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];

    try {
        $newQuantity = mm_normalize_quantity($form['quantity']);
        if ($newQuantity <= 0) {
            throw new RuntimeException('수량은 0보다 커야 합니다.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT movement_id, material_id, movement_type, quantity
            FROM material_management_movements
            WHERE movement_id = :movement_id
            FOR UPDATE
        ");
        $stmt->execute([':movement_id' => $movementId]);
        $lockedMovement = $stmt->fetch();
        if (!is_array($lockedMovement)) {
            throw new RuntimeException('문서를 찾을 수 없습니다.');
        }

        $stmt = $pdo->prepare("
            SELECT current_stock
            FROM material_management_items
            WHERE material_id = :material_id
            FOR UPDATE
        ");
        $stmt->execute([':material_id' => (int)$lockedMovement['material_id']]);
        $item = $stmt->fetch();
        if (!is_array($item)) {
            throw new RuntimeException('연결된 품목을 찾을 수 없습니다.');
        }

        $oldQuantity = (float)($lockedMovement['quantity'] ?? 0);
        $currentStock = (float)($item['current_stock'] ?? 0);
        $movementType = (string)($lockedMovement['movement_type'] ?? '');

        if ($movementType === 'in') {
            $updatedStock = $currentStock + ($newQuantity - $oldQuantity);
        } elseif ($movementType === 'out') {
            $updatedStock = $currentStock + ($oldQuantity - $newQuantity);
        } else {
            throw new RuntimeException('알 수 없는 문서 구분입니다.');
        }

        if ($updatedStock < 0) {
            throw new RuntimeException('수정 결과 현재고가 음수가 되어 저장할 수 없습니다.');
        }

        $stmt = $pdo->prepare("
            UPDATE material_management_movements
            SET movement_date = :movement_date,
                quantity = :quantity,
                partner_name = :partner_name,
                storage_location = :storage_location,
                notes = :notes
            WHERE movement_id = :movement_id
        ");
        $stmt->execute([
            ':movement_date' => $form['movement_date'] !== '' ? $form['movement_date'] : date('Y-m-d'),
            ':quantity' => $newQuantity,
            ':partner_name' => $form['partner_name'] !== '' ? $form['partner_name'] : null,
            ':storage_location' => $form['storage_location'] !== '' ? $form['storage_location'] : null,
            ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ':movement_id' => $movementId,
        ]);

        $stmt = $pdo->prepare("
            UPDATE material_management_items
            SET current_stock = :current_stock,
                updated_by = :updated_by
            WHERE material_id = :material_id
        ");
        $stmt->execute([
            ':current_stock' => mm_normalize_quantity($updatedStock),
            ':updated_by' => auth_display_name($user),
            ':material_id' => (int)$lockedMovement['material_id'],
        ]);

        $pdo->commit();

        header('Location: ' . mm_build_url('/material_management/movement_edit.php', [
            'movement_id' => $movementId,
            'mode' => 'view',
            'notice' => 'saved',
        ]));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }

    $movement = $loadMovement($pdo, $movementId) ?? $movement;
}

if (trim((string)($_GET['notice'] ?? '')) === 'saved') {
    $notice = '문서 수정이 완료되었습니다.';
}

$isViewMode = $mode === 'view';
$title = $isViewMode ? '문서 보기' : '문서 수정';
$description = $isViewMode ? '입출고 문서 상세 내용을 확인합니다.' : '입출고 문서 내용을 수정하고 재고를 자동 조정합니다.';

mm_page_header($title, $description);
?>
<?php if ($notice !== ''): ?><div class="notice"><?= mm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= mm_h($error) ?></div><?php endif; ?>

<div class="grid">
    <div class="field">
        <label>문서번호</label>
        <input type="text" value="<?= mm_h((string)($movement['document_number'] ?? '')) ?>" readonly>
    </div>
    <div class="field">
        <label>구분</label>
        <input type="text" value="<?= mm_h((string)($movement['movement_type'] ?? '') === 'in' ? '입고' : '출고') ?>" readonly>
    </div>
    <div class="field">
        <label>품목명</label>
        <input type="text" value="<?= mm_h((string)($movement['material_name'] ?? '')) ?>" readonly>
    </div>
    <div class="field">
        <label>단위</label>
        <input type="text" value="<?= mm_h((string)($movement['unit_name'] ?? '')) ?>" readonly>
    </div>
</div>

<form method="post" style="margin-top:16px;">
    <input type="hidden" name="movement_id" value="<?= $movementId ?>">
    <div class="grid">
        <div class="field">
            <label for="movement_date">일자</label>
            <input id="movement_date" name="movement_date" type="date" value="<?= mm_h($form['movement_date']) ?>"<?= $isViewMode ? ' readonly' : '' ?>>
        </div>
        <div class="field">
            <label for="quantity">수량</label>
            <input id="quantity" name="quantity" type="number" min="0.1" step="0.1" value="<?= mm_h($form['quantity']) ?>"<?= $isViewMode ? ' readonly' : '' ?>>
        </div>
        <div class="field">
            <label for="partner_name">거래처 / 사용처</label>
            <input id="partner_name" name="partner_name" type="text" value="<?= mm_h($form['partner_name']) ?>"<?= $isViewMode ? ' readonly' : '' ?>>
        </div>
        <div class="field">
            <label for="storage_location">보관위치</label>
            <input id="storage_location" name="storage_location" type="text" value="<?= mm_h($form['storage_location']) ?>"<?= $isViewMode ? ' readonly' : '' ?>>
        </div>
        <div class="field full">
            <label for="notes">비고</label>
            <textarea id="notes" name="notes"<?= $isViewMode ? ' readonly' : '' ?>><?= mm_h($form['notes']) ?></textarea>
        </div>
    </div>
    <div class="row" style="margin-top:14px;">
        <a class="button secondary" href="/material_management/status.php">목록</a>
        <?php if ($isViewMode): ?>
            <a class="button ghost" href="<?= mm_h(mm_build_url('/material_management/movement_edit.php', ['movement_id' => $movementId, 'mode' => 'edit'])) ?>">수정</a>
        <?php else: ?>
            <button type="submit">저장</button>
            <a class="button ghost" href="<?= mm_h(mm_build_url('/material_management/movement_edit.php', ['movement_id' => $movementId, 'mode' => 'view'])) ?>">취소</a>
        <?php endif; ?>
    </div>
</form>
<?php
mm_page_footer();
