<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();
$materials = mm_fetch_material_options($pdo);
$locationStockRows = mm_fetch_material_location_stocks($pdo);

$notice = '';
$error = '';
$search = trim((string)($_GET['q'] ?? ''));

$locationStocksByMaterialId = [];
foreach ($locationStockRows as $locationRow) {
    $materialId = (int)($locationRow['material_id'] ?? 0);
    if ($materialId <= 0) {
        continue;
    }

    if (!isset($locationStocksByMaterialId[$materialId])) {
        $locationStocksByMaterialId[$materialId] = [];
    }

    $locationStocksByMaterialId[$materialId][] = [
        'storage_location' => trim((string)($locationRow['storage_location'] ?? '')),
        'current_stock' => (float)($locationRow['current_stock'] ?? 0),
        'unit_name' => trim((string)($locationRow['unit_name'] ?? '')),
    ];
}

$form = [
    'material_id' => '',
    'quantity' => '',
    'movement_date' => date('Y-m-d'),
    'partner_name' => '',
    'storage_location' => '',
    'document_number' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'material_id' => trim((string)($_POST['material_id'] ?? '')),
        'quantity' => trim((string)($_POST['quantity'] ?? '')),
        'movement_date' => trim((string)($_POST['movement_date'] ?? date('Y-m-d'))),
        'partner_name' => trim((string)($_POST['partner_name'] ?? '')),
        'storage_location' => trim((string)($_POST['storage_location'] ?? '')),
        'document_number' => '',
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];

    try {
        $materialId = (int)$form['material_id'];
        if ($materialId <= 0) {
            throw new RuntimeException('출고할 품목을 선택해 주세요.');
        }

        $quantity = mm_normalize_quantity($form['quantity']);
        if ($quantity <= 0) {
            throw new RuntimeException('출고 수량은 0보다 커야 합니다.');
        }

        $stmt = $pdo->prepare('SELECT material_name, current_stock, unit_name, storage_location FROM material_management_items WHERE material_id = :material_id');
        $stmt->execute([':material_id' => $materialId]);
        $material = $stmt->fetch();
        if (!is_array($material)) {
            throw new RuntimeException('선택한 품목을 찾지 못했습니다.');
        }

        $currentStock = (float)($material['current_stock'] ?? 0);
        $availableLocationStocks = $locationStocksByMaterialId[$materialId] ?? [];
        if ($form['storage_location'] === '' && count($availableLocationStocks) === 1) {
            $form['storage_location'] = trim((string)($availableLocationStocks[0]['storage_location'] ?? ''));
        }
        if ($form['storage_location'] === '' && empty($availableLocationStocks)) {
            $form['storage_location'] = trim((string)($material['storage_location'] ?? ''));
        }

        $selectedLocationStock = null;
        if (!empty($availableLocationStocks)) {
            if ($form['storage_location'] === '' && count($availableLocationStocks) > 1) {
                throw new RuntimeException('출고할 보관위치를 선택해 주세요.');
            }

            foreach ($availableLocationStocks as $locationStockRow) {
                if (trim((string)($locationStockRow['storage_location'] ?? '')) !== $form['storage_location']) {
                    continue;
                }
                $selectedLocationStock = (float)($locationStockRow['current_stock'] ?? 0);
                break;
            }

            if ($selectedLocationStock === null) {
                throw new RuntimeException('선택한 보관위치의 재고 정보를 찾을 수 없습니다.');
            }
        }
        if ($currentStock < $quantity) {
            throw new RuntimeException('현재고보다 많이 출고할 수 없습니다.');
        }

        if ($selectedLocationStock !== null && $selectedLocationStock < $quantity) {
            throw new RuntimeException('선택한 보관위치의 재고보다 많이 출고할 수 없습니다.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE material_management_items
            SET current_stock = current_stock - :quantity,
                updated_by = :updated_by
            WHERE material_id = :material_id
        ");
        $stmt->execute([
            ':quantity' => $quantity,
            ':updated_by' => auth_display_name($user),
            ':material_id' => $materialId,
        ]);

        $movementDate = $form['movement_date'] !== '' ? $form['movement_date'] : date('Y-m-d');

        $stmt = $pdo->prepare("
            INSERT INTO material_management_movements (
                material_id, movement_type, quantity, movement_date, partner_name, storage_location, document_number, notes, created_by
            ) VALUES (
                :material_id, 'out', :quantity, :movement_date, :partner_name, :storage_location, :document_number, :notes, :created_by
            )
        ");
        $stmt->execute([
            ':material_id' => $materialId,
            ':quantity' => $quantity,
            ':movement_date' => $movementDate,
            ':partner_name' => $form['partner_name'] !== '' ? $form['partner_name'] : null,
            ':storage_location' => $form['storage_location'] !== '' ? $form['storage_location'] : null,
            ':document_number' => null,
            ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ':created_by' => auth_display_name($user),
        ]);
        $movementId = (int)$pdo->lastInsertId();
        $documentNumber = mm_build_document_number('out', $movementDate, $movementId);

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
        header('Location: /material_management/outbound.php?notice=saved');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

if (trim((string)($_GET['notice'] ?? '')) === 'saved') {
    $notice = '출고 등록이 완료되었습니다.';
}

$filteredMaterials = array_values(array_filter($materials, static function (array $material) use ($search): bool {
    if ($search === '') {
        return true;
    }
    $haystack = implode(' ', [
        (string)($material['material_name'] ?? ''),
        (string)($material['storage_location'] ?? ''),
    ]);
    return mb_stripos($haystack, $search) !== false;
}));

$filteredLocationRows = array_values(array_filter($locationStockRows, static function (array $row) use ($search): bool {
    if ($search === '') {
        return true;
    }

    $haystack = implode(' ', [
        (string)($row['material_name'] ?? ''),
        (string)($row['storage_location'] ?? ''),
    ]);
    return mb_stripos($haystack, $search) !== false;
}));

$materialMetaById = [];
foreach ($materials as $material) {
    $materialId = (int)($material['material_id'] ?? 0);
    if ($materialId <= 0) {
        continue;
    }

    $materialMetaById[$materialId] = [
        'material_name' => (string)($material['material_name'] ?? ''),
        'current_stock' => (float)($material['current_stock'] ?? 0),
        'unit_name' => (string)($material['unit_name'] ?? ''),
        'storage_location' => trim((string)($material['storage_location'] ?? '')),
    ];
}

mm_page_header('제품 출고', '등록된 품목의 출고 수량을 기록하고 현재고를 자동 차감합니다.');
?>
<?php if ($notice !== ''): ?><div class="notice"><?= mm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= mm_h($error) ?></div><?php endif; ?>

<form method="post">
    <div class="grid">
        <div class="field full">
            <label for="material_id">출고 품목</label>
            <select id="material_id" name="material_id" required>
                <option value="">선택</option>
                <?php foreach ($filteredMaterials as $material): ?>
                    <option value="<?= (int)$material['material_id'] ?>"<?= (string)$form['material_id'] === (string)$material['material_id'] ? ' selected' : '' ?>>
                        <?= mm_h((string)$material['material_name']) ?>
                        <?= trim((string)($material['storage_location'] ?? '')) !== '' ? ' / ' . mm_h((string)$material['storage_location']) : '' ?>
                        / 현재고 <?= mm_format_quantity($material['current_stock'] ?? 0) ?>
                        <?= trim((string)($material['unit_name'] ?? '')) !== '' ? mm_h((string)$material['unit_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="quantity">출고수량</label>
            <input id="quantity" name="quantity" type="number" min="0.1" step="0.1" value="<?= mm_h($form['quantity']) ?>" required>
        </div>
        <div class="field">
            <label for="movement_date">출고일자</label>
            <input id="movement_date" name="movement_date" type="date" value="<?= mm_h($form['movement_date']) ?>">
        </div>
        <div class="field">
            <label for="partner_name">사용처 / 반출처</label>
            <input id="partner_name" name="partner_name" type="text" value="<?= mm_h($form['partner_name']) ?>">
        </div>
        <div class="field">
            <label for="storage_location">보관위치</label>
            <input id="storage_location" name="storage_location" type="text" value="<?= mm_h($form['storage_location']) ?>" placeholder="실제 출고 위치 입력">
        </div>
        <div class="field">
            <label>문서번호</label>
            <input type="text" value="저장 시 자동 생성" readonly>
        </div>
        <div class="field full">
            <label for="notes">비고</label>
            <textarea id="notes" name="notes"><?= mm_h($form['notes']) ?></textarea>
        </div>
    </div>
    <div class="row" style="margin-top:14px;">
        <button type="submit">출고 저장</button>
    </div>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>품목명</th>
                <th>보관위치</th>
                <th>현재고</th>
                <th>상태</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($filteredLocationRows)): ?>
                <tr><td colspan="4" class="empty">조회된 품목이 없습니다.</td></tr>
            <?php else: ?>
                <?php foreach ($filteredLocationRows as $material): ?>
                    <tr>
                        <td><?= mm_h((string)($material['material_name'] ?? '')) ?></td>
                        <td><?= mm_h(trim((string)($material['storage_location'] ?? '')) !== '' ? (string)$material['storage_location'] : '미지정') ?></td>
                        <td><?= mm_format_quantity($material['current_stock'] ?? 0) ?> <?= mm_h((string)($material['unit_name'] ?? '')) ?></td>
                        <td>
                            <span class="pill <?= (int)($material['is_active'] ?? 1) === 1 ? 'on' : 'off' ?>">
                                <?= (int)($material['is_active'] ?? 1) === 1 ? '사용중' : '미사용' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<script>
const materialLocationStockMap = <?= json_encode($locationStocksByMaterialId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const materialMetaById = <?= json_encode($materialMetaById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialMaterialId = <?= json_encode((string)$form['material_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialStorageLocation = <?= json_encode((string)$form['storage_location'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const materialSelect = document.getElementById('material_id');
const storageLocationInput = document.getElementById('storage_location');

const formatQuantity = (value) => {
    const number = Number(value || 0);
    return number.toLocaleString('ko-KR', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
};

if (materialSelect && storageLocationInput) {
    const storageSelect = document.createElement('select');
    storageSelect.id = 'storage_location_select';
    let preferredStorageLocation = initialStorageLocation;

    const stockHelp = document.createElement('div');
    stockHelp.id = 'storage-location-stock-help';
    stockHelp.className = 'hint';
    stockHelp.style.marginTop = '8px';

    storageLocationInput.type = 'hidden';
    storageLocationInput.parentNode.insertBefore(storageSelect, storageLocationInput.nextSibling);
    storageSelect.insertAdjacentElement('afterend', stockHelp);

    const renderLocationOptions = () => {
        const materialId = String(materialSelect.value || '');
        const locationRows = Array.isArray(materialLocationStockMap[materialId]) ? materialLocationStockMap[materialId] : [];
        const materialMeta = materialMetaById[materialId] || {};
        const fallbackLocation = String(materialMeta.storage_location || '').trim();
        const unitName = String(materialMeta.unit_name || '');

        storageSelect.innerHTML = '';

        if (materialId === '') {
            storageSelect.innerHTML = '<option value="">보관위치 선택</option>';
            storageLocationInput.value = '';
            stockHelp.textContent = '품목을 선택하면 보관위치별 재고를 불러옵니다.';
            return;
        }

        if (locationRows.length === 0) {
            const option = document.createElement('option');
            option.value = fallbackLocation;
            option.textContent = fallbackLocation !== ''
                ? `${fallbackLocation} / 총재고 ${formatQuantity(materialMeta.current_stock || 0)} ${unitName}`
                : '미지정';
            storageSelect.appendChild(option);
            storageSelect.value = fallbackLocation;
            storageLocationInput.value = fallbackLocation;
            stockHelp.textContent = fallbackLocation !== ''
                ? `등록된 보관위치: ${fallbackLocation}`
                : '보관위치 이력이 없어 미지정으로 처리됩니다.';
            return;
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '보관위치 선택';
        storageSelect.appendChild(placeholder);

        locationRows.forEach((row) => {
            const option = document.createElement('option');
            const location = String(row.storage_location || '');
            const label = location !== '' ? location : '미지정';
            option.value = location;
            option.textContent = `${label} / 재고 ${formatQuantity(row.current_stock || 0)} ${String(row.unit_name || unitName)}`;
            storageSelect.appendChild(option);
        });

        const requestedLocation = String(storageLocationInput.value || preferredStorageLocation || '');
        const matchedRow = locationRows.find((row) => String(row.storage_location || '') === String(requestedLocation || '')) || null;
        if (matchedRow) {
            storageSelect.value = String(matchedRow.storage_location || '');
        } else if (locationRows.length === 1) {
            storageSelect.value = String(locationRows[0].storage_location || '');
        }

        const selectedRow = locationRows.find((row) => String(row.storage_location || '') === String(storageSelect.value || '')) || null;
        storageLocationInput.value = String(storageSelect.value || '');
        stockHelp.textContent = selectedRow
            ? `선택 위치 재고: ${formatQuantity(selectedRow.current_stock || 0)} ${String(selectedRow.unit_name || unitName)}`
            : '보관위치를 선택하면 해당 위치 재고가 표시됩니다.';
    };

    materialSelect.addEventListener('change', () => {
        preferredStorageLocation = '';
        storageLocationInput.value = '';
        renderLocationOptions();
    });
    storageSelect.addEventListener('change', () => {
        preferredStorageLocation = String(storageSelect.value || '');
        storageLocationInput.value = String(storageSelect.value || '');
        renderLocationOptions();
    });

    if (initialMaterialId !== '') {
        materialSelect.value = initialMaterialId;
    }
    renderLocationOptions();
}
</script>
<?php
mm_page_footer();
