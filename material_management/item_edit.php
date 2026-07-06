<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();

$materialId = (int)($_GET['material_id'] ?? $_POST['material_id'] ?? 0);
$notice = '';
$error = '';

if ($materialId <= 0) {
    http_response_code(400);
    mm_page_header('품목 수정', '잘못된 품목 요청입니다.');
    echo '<div class="error">품목을 찾을 수 없습니다.</div>';
    mm_page_footer();
    exit;
}

$loadItem = static function (PDO $pdo, int $materialId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM material_management_items WHERE material_id = :material_id LIMIT 1');
    $stmt->execute([':material_id' => $materialId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
};

$item = $loadItem($pdo, $materialId);
if ($item === null) {
    http_response_code(404);
    mm_page_header('품목 수정', '품목을 찾을 수 없습니다.');
    echo '<div class="error">품목을 찾을 수 없습니다.</div>';
    mm_page_footer();
    exit;
}

$form = [
    'material_name' => (string)($item['material_name'] ?? ''),
    'existing_msds_file_name' => (string)($item['msds_file_name'] ?? ''),
    'existing_msds_file_path' => (string)($item['msds_file_path'] ?? ''),
    'manufacturer_name' => (string)($item['manufacturer_name'] ?? ''),
    'supplier_name' => (string)($item['supplier_name'] ?? ''),
    'storage_location' => (string)($item['storage_location'] ?? ''),
    'unit_name' => (string)($item['unit_name'] ?? ''),
    'notes' => (string)($item['notes'] ?? ''),
    'is_active' => (string)((int)($item['is_active'] ?? 1)),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'material_name' => trim((string)($_POST['material_name'] ?? '')),
        'existing_msds_file_name' => trim((string)($_POST['existing_msds_file_name'] ?? '')),
        'existing_msds_file_path' => trim((string)($_POST['existing_msds_file_path'] ?? '')),
        'manufacturer_name' => trim((string)($_POST['manufacturer_name'] ?? '')),
        'supplier_name' => trim((string)($_POST['supplier_name'] ?? '')),
        'storage_location' => trim((string)($_POST['storage_location'] ?? '')),
        'unit_name' => trim((string)($_POST['unit_name'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'is_active' => (string)((int)($_POST['is_active'] ?? 1)),
    ];

    $uploadedMsds = null;
    $previousMsdsFilePathToDelete = '';

    try {
        if ($form['material_name'] === '') {
            throw new RuntimeException('품목명을 입력해 주세요.');
        }

        $uploadedMsds = mm_store_msds_pdf($_FILES['msds_pdf'] ?? []);
        $msdsFileName = $uploadedMsds['file_name'] ?? $form['existing_msds_file_name'];
        $msdsFilePath = $uploadedMsds['file_path'] ?? $form['existing_msds_file_path'];

        $stmt = $pdo->prepare('SELECT msds_file_path FROM material_management_items WHERE material_id = :material_id');
        $stmt->execute([':material_id' => $materialId]);
        $existingRow = $stmt->fetch();
        $previousMsdsFilePathToDelete = (string)($existingRow['msds_file_path'] ?? '');

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
                updated_by = :updated_by
            WHERE material_id = :material_id
        ");
        $stmt->execute([
            ':material_name' => $form['material_name'],
            ':msds_file_name' => $msdsFileName !== '' ? $msdsFileName : null,
            ':msds_file_path' => $msdsFilePath !== '' ? $msdsFilePath : null,
            ':manufacturer_name' => $form['manufacturer_name'] !== '' ? $form['manufacturer_name'] : null,
            ':supplier_name' => $form['supplier_name'] !== '' ? $form['supplier_name'] : null,
            ':storage_location' => $form['storage_location'] !== '' ? $form['storage_location'] : null,
            ':unit_name' => $form['unit_name'] !== '' ? $form['unit_name'] : null,
            ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ':is_active' => $form['is_active'] === '0' ? 0 : 1,
            ':updated_by' => auth_display_name($user),
            ':material_id' => $materialId,
        ]);

        if (($uploadedMsds['file_path'] ?? null) !== null && $previousMsdsFilePathToDelete !== '' && $previousMsdsFilePathToDelete !== $msdsFilePath) {
            mm_delete_uploaded_file($previousMsdsFilePathToDelete);
        }

        header('Location: ' . mm_build_url('/material_management/item_edit.php', [
            'material_id' => $materialId,
            'notice' => 'saved',
        ]));
        exit;
    } catch (Throwable $e) {
        if (($uploadedMsds['file_path'] ?? null) !== null) {
            mm_delete_uploaded_file((string)$uploadedMsds['file_path']);
        }
        $error = $e->getMessage();
    }
}

if (trim((string)($_GET['notice'] ?? '')) === 'saved') {
    $notice = '품목 정보 수정이 완료되었습니다.';
}

mm_page_header('품목 수정', '품목 기본정보를 수정합니다. 이 작업은 입출고 문서를 새로 만들지 않습니다.');
?>
<?php if ($notice !== ''): ?><div class="notice"><?= mm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= mm_h($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="material_id" value="<?= $materialId ?>">
    <input type="hidden" name="existing_msds_file_name" value="<?= mm_h($form['existing_msds_file_name']) ?>">
    <input type="hidden" name="existing_msds_file_path" value="<?= mm_h($form['existing_msds_file_path']) ?>">
    <div class="grid">
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
            <input id="unit_name" name="unit_name" type="text" value="<?= mm_h($form['unit_name']) ?>" placeholder="예: 병, EA, L">
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
        <button type="submit">저장</button>
        <a class="button secondary" href="/material_management/status.php">목록</a>
    </div>
</form>
<?php
mm_page_footer();
