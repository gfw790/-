<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();

$notice = '';
$error = '';
$materials = mm_fetch_material_options($pdo);
$selectedMaterialId = (int)($_GET['material_id'] ?? 0);

$form = [
    'material_id' => $selectedMaterialId,
    'material_name' => '',
    'existing_msds_file_name' => '',
    'existing_msds_file_path' => '',
    'manufacturer_name' => '',
    'supplier_name' => '',
    'storage_location' => '',
    'unit_name' => '',
    'quantity' => '',
    'movement_date' => date('Y-m-d'),
    'notes' => '',
    'is_active' => '1',
];

if ($selectedMaterialId > 0) {
    foreach ($materials as $material) {
        if ((int)$material['material_id'] !== $selectedMaterialId) {
            continue;
        }

        $stmt = $pdo->prepare('SELECT * FROM material_management_items WHERE material_id = :material_id');
        $stmt->execute([':material_id' => $selectedMaterialId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $form['material_name'] = (string)($row['material_name'] ?? '');
            $form['existing_msds_file_name'] = (string)($row['msds_file_name'] ?? '');
            $form['existing_msds_file_path'] = (string)($row['msds_file_path'] ?? '');
            $form['manufacturer_name'] = (string)($row['manufacturer_name'] ?? '');
            $form['supplier_name'] = (string)($row['supplier_name'] ?? '');
            $form['storage_location'] = (string)($row['storage_location'] ?? '');
            $form['unit_name'] = (string)($row['unit_name'] ?? '');
            $form['notes'] = (string)($row['notes'] ?? '');
            $form['is_active'] = (string)((int)($row['is_active'] ?? 1));
        }
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'material_id' => (int)($_POST['material_id'] ?? 0),
        'material_name' => trim((string)($_POST['material_name'] ?? '')),
        'existing_msds_file_name' => trim((string)($_POST['existing_msds_file_name'] ?? '')),
        'existing_msds_file_path' => trim((string)($_POST['existing_msds_file_path'] ?? '')),
        'manufacturer_name' => trim((string)($_POST['manufacturer_name'] ?? '')),
        'supplier_name' => trim((string)($_POST['supplier_name'] ?? '')),
        'storage_location' => trim((string)($_POST['storage_location'] ?? '')),
        'unit_name' => trim((string)($_POST['unit_name'] ?? '')),
        'quantity' => trim((string)($_POST['quantity'] ?? '')),
        'movement_date' => trim((string)($_POST['movement_date'] ?? date('Y-m-d'))),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'is_active' => (string)((int)($_POST['is_active'] ?? 1)),
    ];

    $uploadedMsds = null;
    $previousMsdsFilePathToDelete = '';

    try {
        if ($form['material_name'] === '') {
            throw new RuntimeException('품목명을 입력해 주세요.');
        }

        $quantity = mm_normalize_quantity($form['quantity']);
        if ($quantity <= 0) {
            throw new RuntimeException('입고 수량은 0보다 커야 합니다.');
        }

        $uploadedMsds = mm_store_msds_pdf($_FILES['msds_pdf'] ?? []);
        $msdsFileName = $uploadedMsds['file_name'] ?? $form['existing_msds_file_name'];
        $msdsFilePath = $uploadedMsds['file_path'] ?? $form['existing_msds_file_path'];

        $pdo->beginTransaction();

        $targetMaterialId = 0;
        $targetStorageLocation = $form['storage_location'];
        $targetIsActive = $form['is_active'] === '0' ? 0 : 1;
        $selectedRow = null;
        if ($form['material_id'] > 0) {
            $stmt = $pdo->prepare('SELECT * FROM material_management_items WHERE material_id = :material_id');
            $stmt->execute([':material_id' => $form['material_id']]);
            $selectedRow = $stmt->fetch();
        }

        $nameMatchedRow = mm_find_material_by_name(
            $pdo,
            $form['material_name'],
            $form['unit_name'],
            $targetIsActive,
            $selectedRow !== false ? (int)($selectedRow['material_id'] ?? 0) : null
        );

        if (
            is_array($selectedRow)
            && trim((string)($selectedRow['material_name'] ?? '')) === $form['material_name']
            && trim((string)($selectedRow['unit_name'] ?? '')) === $form['unit_name']
            && (int)($selectedRow['is_active'] ?? 1) === $targetIsActive
        ) {
            $targetMaterialId = (int)$selectedRow['material_id'];
        } elseif (is_array($nameMatchedRow)) {
            $targetMaterialId = (int)$nameMatchedRow['material_id'];
        }

        if ($targetMaterialId > 0) {
            $stmt = $pdo->prepare('SELECT msds_file_path, storage_location FROM material_management_items WHERE material_id = :material_id');
            $stmt->execute([':material_id' => $targetMaterialId]);
            $existingRow = $stmt->fetch();
            $previousMsdsFilePathToDelete = (string)($existingRow['msds_file_path'] ?? '');
            $mergedStorageLocation = mm_merge_storage_locations(
                (string)($existingRow['storage_location'] ?? ''),
                $targetStorageLocation
            );

            $stmt = $pdo->prepare("
                UPDATE material_management_items
                SET material_name = :material_name,
                    msds_file_name = :msds_file_name,
                    msds_file_path = :msds_file_path,
                    manufacturer_name = :manufacturer_name,
                    supplier_name = :supplier_name,
                    storage_location = :storage_location,
                    unit_name = :unit_name,
                    notes = :notes,
                    is_active = :is_active,
                    current_stock = current_stock + :quantity,
                    updated_by = :updated_by
                WHERE material_id = :material_id
            ");
            $stmt->execute([
                ':material_id' => $targetMaterialId,
                ':material_name' => $form['material_name'],
                ':msds_file_name' => $msdsFileName !== '' ? $msdsFileName : null,
                ':msds_file_path' => $msdsFilePath !== '' ? $msdsFilePath : null,
                ':manufacturer_name' => $form['manufacturer_name'] !== '' ? $form['manufacturer_name'] : null,
                ':supplier_name' => $form['supplier_name'] !== '' ? $form['supplier_name'] : null,
                ':storage_location' => $mergedStorageLocation !== '' ? $mergedStorageLocation : null,
                ':unit_name' => $form['unit_name'] !== '' ? $form['unit_name'] : null,
                ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
                ':is_active' => $targetIsActive,
                ':quantity' => $quantity,
                ':updated_by' => auth_display_name($user),
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO material_management_items (
                    material_name, msds_file_name, msds_file_path, manufacturer_name, supplier_name,
                    storage_location, unit_name, current_stock, notes, is_active, created_by, updated_by
                ) VALUES (
                    :material_name, :msds_file_name, :msds_file_path, :manufacturer_name, :supplier_name,
                    :storage_location, :unit_name, :current_stock, :notes, :is_active, :created_by, :updated_by
                )
            ");
            $stmt->execute([
                ':material_name' => $form['material_name'],
                ':msds_file_name' => $msdsFileName !== '' ? $msdsFileName : null,
                ':msds_file_path' => $msdsFilePath !== '' ? $msdsFilePath : null,
                ':manufacturer_name' => $form['manufacturer_name'] !== '' ? $form['manufacturer_name'] : null,
                ':supplier_name' => $form['supplier_name'] !== '' ? $form['supplier_name'] : null,
                ':storage_location' => $targetStorageLocation !== '' ? $targetStorageLocation : null,
                ':unit_name' => $form['unit_name'] !== '' ? $form['unit_name'] : null,
                ':current_stock' => $quantity,
                ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
                ':is_active' => $targetIsActive,
                ':created_by' => auth_display_name($user),
                ':updated_by' => auth_display_name($user),
            ]);
            $targetMaterialId = (int)$pdo->lastInsertId();
        }

        $form['material_id'] = $targetMaterialId;

        $movementDate = $form['movement_date'] !== '' ? $form['movement_date'] : date('Y-m-d');

        $stmt = $pdo->prepare("
            INSERT INTO material_management_movements (
                material_id, movement_type, quantity, movement_date, partner_name, storage_location, document_number, notes, created_by
            ) VALUES (
                :material_id, 'in', :quantity, :movement_date, :partner_name, :storage_location, :document_number, :notes, :created_by
            )
        ");
        $stmt->execute([
            ':material_id' => $form['material_id'],
            ':quantity' => $quantity,
            ':movement_date' => $movementDate,
            ':partner_name' => $form['supplier_name'] !== '' ? $form['supplier_name'] : null,
            ':storage_location' => $targetStorageLocation !== '' ? $targetStorageLocation : null,
            ':document_number' => null,
            ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ':created_by' => auth_display_name($user),
        ]);
        $movementId = (int)$pdo->lastInsertId();
        $documentNumber = mm_build_document_number('in', $movementDate, $movementId);

        $stmt = $pdo->prepare("
            UPDATE material_management_movements
            SET document_number = :document_number
            WHERE movement_id = :movement_id
        ");
        $stmt->execute([
            ':document_number' => $documentNumber,
            ':movement_id' => $movementId,
        ]);

        $pdo->commit();
        if (($uploadedMsds['file_path'] ?? null) !== null && $previousMsdsFilePathToDelete !== '' && $previousMsdsFilePathToDelete !== $msdsFilePath) {
            mm_delete_uploaded_file($previousMsdsFilePathToDelete);
        }
        header('Location: ' . mm_build_url('/material_management/inbound.php', ['notice' => 'saved', 'material_id' => $form['material_id']]));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (($uploadedMsds['file_path'] ?? null) !== null) {
            mm_delete_uploaded_file((string)$uploadedMsds['file_path']);
        }
        $error = $e->getMessage();
    }
}

if (trim((string)($_GET['notice'] ?? '')) === 'saved') {
    $notice = '입고 등록이 완료되었습니다.';
}

mm_page_header('제품 입고', '새 품목 등록과 기존 품목 입고를 처리합니다.');
?>
<?php if ($notice !== ''): ?><div class="notice"><?= mm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= mm_h($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="existing_msds_file_name" value="<?= mm_h($form['existing_msds_file_name']) ?>">
    <input type="hidden" name="existing_msds_file_path" value="<?= mm_h($form['existing_msds_file_path']) ?>">
    <div class="grid">
        <div class="field full">
            <label for="material_id">기존 품목 선택</label>
            <select id="material_id" name="material_id" onchange="window.location.href='/material_management/inbound.php?material_id=' + encodeURIComponent(this.value);">
                <option value="0">신규 품목 등록</option>
                <?php foreach ($materials as $material): ?>
                    <option value="<?= (int)$material['material_id'] ?>"<?= (int)$form['material_id'] === (int)$material['material_id'] ? ' selected' : '' ?>>
                        <?= mm_h((string)$material['material_name']) ?>
                        <?= trim((string)($material['storage_location'] ?? '')) !== '' ? ' / ' . mm_h((string)$material['storage_location']) : '' ?>
                        / 재고 <?= mm_format_quantity($material['current_stock'] ?? 0) ?>
                        <?= trim((string)($material['unit_name'] ?? '')) !== '' ? ' ' . mm_h((string)$material['unit_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="material_name">품목명</label>
            <input id="material_name" name="material_name" type="text" value="<?= mm_h($form['material_name']) ?>" required>
        </div>
        <div class="field">
            <label for="msds_pdf">MSDS PDF</label>
            <input id="msds_pdf" name="msds_pdf" type="file" accept="application/pdf,.pdf">
            <?php if ($form['existing_msds_file_path'] !== ''): ?>
                <div class="lead" style="margin:0;">
                    현재 파일:
                    <a href="<?= mm_h($form['existing_msds_file_path']) ?>" target="_blank" rel="noopener">
                        <?= mm_h($form['existing_msds_file_name'] !== '' ? $form['existing_msds_file_name'] : 'MSDS 보기') ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="manufacturer_name">제조사</label>
            <input id="manufacturer_name" name="manufacturer_name" type="text" value="<?= mm_h($form['manufacturer_name']) ?>">
        </div>
        <div class="field">
            <label for="supplier_name">공급처</label>
            <input id="supplier_name" name="supplier_name" type="text" value="<?= mm_h($form['supplier_name']) ?>">
        </div>
        <div class="field">
            <label for="storage_location">보관위치</label>
            <input id="storage_location" name="storage_location" type="text" value="<?= mm_h($form['storage_location']) ?>">
        </div>
        <div class="field">
            <label for="unit_name">단위</label>
            <input id="unit_name" name="unit_name" type="text" value="<?= mm_h($form['unit_name']) ?>" placeholder="예: EA, L, kg">
        </div>
        <div class="field">
            <label for="quantity">입고수량</label>
            <input id="quantity" name="quantity" type="number" min="0.1" step="0.1" value="<?= mm_h($form['quantity']) ?>" required>
        </div>
        <div class="field">
            <label for="movement_date">입고일자</label>
            <input id="movement_date" name="movement_date" type="date" value="<?= mm_h($form['movement_date']) ?>">
        </div>
        <div class="field">
            <label>문서번호</label>
            <input type="text" value="저장 시 자동 생성" readonly>
        </div>
        <div class="field">
            <label for="is_active">사용상태</label>
            <select id="is_active" name="is_active">
                <option value="1"<?= $form['is_active'] === '1' ? ' selected' : '' ?>>사용중</option>
                <option value="0"<?= $form['is_active'] === '0' ? ' selected' : '' ?>>미사용</option>
            </select>
        </div>
        <div class="field full">
            <label for="notes">비고</label>
            <textarea id="notes" name="notes"><?= mm_h($form['notes']) ?></textarea>
        </div>
    </div>
    <div class="row" style="margin-top:14px;">
        <button type="submit">입고 저장</button>
        <a class="button ghost" href="/material_management/inbound.php">신규 입력</a>
    </div>
</form>
<?php
mm_page_footer();
