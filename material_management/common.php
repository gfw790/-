<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';

function mm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mm_authorized_user(): array
{
    $user = auth_current_user();
    if (!is_array($user)) {
        header('Location: /risk_assessment/task_select.php');
        exit;
    }

    $isAuthorized = auth_can_manage($user) && trim((string)auth_display_name($user)) === '김남균';
    if (!$isAuthorized) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="ko">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>제품관리</title>
            <style>
                body { margin: 0; padding: 32px; font-family: "Malgun Gothic", sans-serif; background: #f3f7fb; color: #122033; }
                .panel { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d7e0ea; border-radius: 20px; padding: 24px; }
                .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 12px; background: #e2e8f0; color: #0f172a; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class="panel">
                <h1>제품관리</h1>
                <p>이 페이지는 김남균 계정에서만 사용할 수 있습니다.</p>
                <a class="button" href="/risk_assessment/work_list.php">작업목록으로 돌아가기</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    return $user;
}

function mm_build_url(string $path, array $params = []): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $filtered[$key] = $value;
    }

    return empty($filtered) ? $path : ($path . '?' . http_build_query($filtered, '', '&', PHP_QUERY_RFC3986));
}

function mm_get_pdo(): PDO
{
    $pdo = getDB();
    mm_ensure_schema($pdo);
    mm_merge_duplicate_items($pdo);
    mm_refresh_item_storage_locations_from_movements($pdo);
    return $pdo;
}

function mm_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS material_management_items (
            material_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            material_name VARCHAR(150) NOT NULL,
            material_code VARCHAR(80) DEFAULT NULL,
            cas_no VARCHAR(80) DEFAULT NULL,
            msds_number VARCHAR(120) DEFAULT NULL,
            msds_file_name VARCHAR(255) DEFAULT NULL,
            msds_file_path VARCHAR(255) DEFAULT NULL,
            manufacturer_name VARCHAR(150) DEFAULT NULL,
            supplier_name VARCHAR(150) DEFAULT NULL,
            storage_location VARCHAR(150) DEFAULT NULL,
            hazard_class VARCHAR(120) DEFAULT NULL,
            unit_name VARCHAR(40) DEFAULT NULL,
            current_stock DECIMAL(14,1) NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(100) DEFAULT NULL,
            updated_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_material_code (material_code),
            KEY idx_material_name (material_name),
            KEY idx_storage_location (storage_location)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'material_code' => "ALTER TABLE material_management_items ADD COLUMN material_code VARCHAR(80) DEFAULT NULL AFTER material_name",
        'cas_no' => "ALTER TABLE material_management_items ADD COLUMN cas_no VARCHAR(80) DEFAULT NULL AFTER material_code",
        'msds_number' => "ALTER TABLE material_management_items ADD COLUMN msds_number VARCHAR(120) DEFAULT NULL AFTER cas_no",
        'msds_file_name' => "ALTER TABLE material_management_items ADD COLUMN msds_file_name VARCHAR(255) DEFAULT NULL AFTER msds_number",
        'msds_file_path' => "ALTER TABLE material_management_items ADD COLUMN msds_file_path VARCHAR(255) DEFAULT NULL AFTER msds_file_name",
        'manufacturer_name' => "ALTER TABLE material_management_items ADD COLUMN manufacturer_name VARCHAR(150) DEFAULT NULL AFTER msds_file_path",
        'supplier_name' => "ALTER TABLE material_management_items ADD COLUMN supplier_name VARCHAR(150) DEFAULT NULL AFTER manufacturer_name",
        'storage_location' => "ALTER TABLE material_management_items ADD COLUMN storage_location VARCHAR(150) DEFAULT NULL AFTER supplier_name",
        'hazard_class' => "ALTER TABLE material_management_items ADD COLUMN hazard_class VARCHAR(120) DEFAULT NULL AFTER storage_location",
        'unit_name' => "ALTER TABLE material_management_items ADD COLUMN unit_name VARCHAR(40) DEFAULT NULL AFTER hazard_class",
        'current_stock' => "ALTER TABLE material_management_items ADD COLUMN current_stock DECIMAL(14,1) NOT NULL DEFAULT 0 AFTER unit_name",
        'notes' => "ALTER TABLE material_management_items ADD COLUMN notes TEXT DEFAULT NULL AFTER current_stock",
        'is_active' => "ALTER TABLE material_management_items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes",
        'created_by' => "ALTER TABLE material_management_items ADD COLUMN created_by VARCHAR(100) DEFAULT NULL AFTER is_active",
        'updated_by' => "ALTER TABLE material_management_items ADD COLUMN updated_by VARCHAR(100) DEFAULT NULL AFTER created_by",
    ];

    foreach ($columns as $columnName => $sql) {
        mm_add_column_if_missing($pdo, 'material_management_items', $columnName, $sql);
    }

    mm_ensure_decimal_scale($pdo, 'material_management_items', 'current_stock', 'DECIMAL(14,1) NOT NULL DEFAULT 0');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS material_management_movements (
            movement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            material_id BIGINT UNSIGNED NOT NULL,
            movement_type ENUM('in', 'out') NOT NULL,
            quantity DECIMAL(14,1) NOT NULL,
            movement_date DATE NOT NULL,
            partner_name VARCHAR(150) DEFAULT NULL,
            storage_location VARCHAR(150) DEFAULT NULL,
            document_number VARCHAR(120) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_material_date (material_id, movement_date),
            CONSTRAINT fk_mm_movement_material FOREIGN KEY (material_id)
                REFERENCES material_management_items(material_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    mm_add_column_if_missing(
        $pdo,
        'material_management_movements',
        'storage_location',
        "ALTER TABLE material_management_movements ADD COLUMN storage_location VARCHAR(150) DEFAULT NULL AFTER partner_name"
    );
    $pdo->exec("
        UPDATE material_management_movements m
        INNER JOIN material_management_items i ON i.material_id = m.material_id
        SET m.storage_location = i.storage_location
        WHERE m.storage_location IS NULL OR m.storage_location = ''
    ");
    mm_ensure_decimal_scale($pdo, 'material_management_movements', 'quantity', 'DECIMAL(14,1) NOT NULL');
}

function mm_add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $alterSql): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($alterSql);
    }
}

function mm_ensure_decimal_scale(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    $columnType = strtolower((string)$stmt->fetchColumn());
    if ($columnType === '') {
        return;
    }

    if ($columnType !== 'decimal(14,1)') {
        $pdo->exec(sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s',
            $tableName,
            $columnName,
            $definition
        ));
    }
}

function mm_normalize_quantity($value): float
{
    return round((float)$value, 1);
}

function mm_format_quantity($value): string
{
    return number_format((float)$value, 1);
}

function mm_msds_upload_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/material_msds';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function mm_relative_upload_path(string $fileName): string
{
    return '/uploads/material_msds/' . $fileName;
}

function mm_absolute_upload_path(string $relativePath): string
{
    return dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function mm_store_msds_pdf(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [
            'file_name' => null,
            'file_path' => null,
        ];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('MSDS PDF 업로드 중 오류가 발생했습니다.');
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        throw new RuntimeException('MSDS 파일은 PDF만 업로드할 수 있습니다.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    $mimeType = '';
    if ($tmpPath !== '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = (string)finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
    }
    if ($mimeType !== '' && $mimeType !== 'application/pdf') {
        throw new RuntimeException('업로드한 파일이 PDF 형식이 아닙니다.');
    }

    $safeBaseName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
    $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBaseName;
    $targetPath = rtrim(mm_msds_upload_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('MSDS PDF 파일을 저장하지 못했습니다.');
    }

    return [
        'file_name' => $originalName,
        'file_path' => mm_relative_upload_path($storedName),
    ];
}

function mm_delete_uploaded_file(?string $relativePath): void
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '' || strpos($relativePath, '/uploads/material_msds/') !== 0) {
        return;
    }

    $absolutePath = mm_absolute_upload_path($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function mm_build_document_number(string $movementType, string $movementDate, int $movementId): string
{
    $prefix = strtolower($movementType) === 'out' ? 'OUT' : 'IN';
    $dateToken = preg_replace('/[^0-9]/', '', $movementDate);
    if (!is_string($dateToken) || strlen($dateToken) !== 8) {
        $dateToken = date('Ymd');
    }

    return sprintf('%s-%s-%06d', $prefix, $dateToken, max(1, $movementId));
}

function mm_page_header(string $title, string $description): void
{
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= mm_h($title) ?></title>
        <style>
            :root {
                --bg: #eef4f8;
                --panel: #ffffff;
                --line: #d7e0ea;
                --text: #122033;
                --muted: #64748b;
                --accent: #0f766e;
                --accent-soft: #ccfbf1;
                --secondary: #e2e8f0;
                --danger: #b91c1c;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Malgun Gothic", sans-serif;
                color: var(--text);
                background:
                    radial-gradient(circle at top right, rgba(15, 118, 110, 0.12), transparent 24%),
                    linear-gradient(180deg, #f8fbfd 0%, var(--bg) 100%);
            }
            .page {
                width: min(1480px, calc(100vw - 28px));
                margin: 20px auto 28px;
            }
            .panel {
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 18px;
                box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
                padding: 18px;
            }
            h1, h2, h3 { margin: 0; }
            .lead { margin: 8px 0 0; color: var(--muted); font-size: 14px; line-height: 1.6; }
            .menu-row, .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
            .menu-row { margin-top: 14px; }
            .grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                margin-top: 16px;
            }
            .field { display: grid; gap: 6px; }
            .field.full { grid-column: 1 / -1; }
            .field label { font-size: 12px; font-weight: 700; color: var(--muted); }
            input, textarea, select, button { font: inherit; }
            input[type="text"], input[type="date"], input[type="number"], textarea, select {
                width: 100%;
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 10px 12px;
                background: #fff;
            }
            textarea { min-height: 92px; resize: vertical; }
            .grow { flex: 1 1 220px; }
            .button, button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 0;
                border-radius: 12px;
                padding: 10px 14px;
                text-decoration: none;
                cursor: pointer;
                background: var(--accent);
                color: #fff;
            }
            .button.secondary, button.secondary { background: var(--secondary); color: #0f172a; }
            .button.ghost, button.ghost { background: var(--accent-soft); color: #115e59; }
            .button.danger, button.danger { background: var(--danger); color: #fff; }
            .notice, .error {
                margin-top: 14px;
                border-radius: 12px;
                padding: 12px 14px;
                font-size: 13px;
            }
            .notice { background: #ecfdf5; color: #166534; }
            .error { background: #fee2e2; color: #991b1b; }
            .table-wrap {
                margin-top: 16px;
                border: 1px solid var(--line);
                border-radius: 16px;
                overflow: auto;
                background: #fff;
            }
            table {
                width: 100%;
                min-width: 1100px;
                border-collapse: collapse;
            }
            th, td {
                padding: 12px 14px;
                border-bottom: 1px solid #e5edf5;
                text-align: left;
                vertical-align: middle;
                font-size: 13px;
                white-space: nowrap;
            }
            th {
                position: sticky;
                top: 0;
                background: #f8fafc;
                color: #475569;
                font-size: 12px;
                z-index: 1;
            }
            td.wrap { white-space: normal; }
            .pill {
                display: inline-flex;
                align-items: center;
                padding: 4px 8px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 700;
            }
            .pill.on { background: #dcfce7; color: #166534; }
            .pill.off { background: #e2e8f0; color: #475569; }
            .summary-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
                margin-top: 16px;
            }
            .summary-card {
                border: 1px solid var(--line);
                border-radius: 16px;
                padding: 16px;
                background: linear-gradient(180deg, #f8fbfd 0%, #ffffff 100%);
            }
            .summary-card strong { display: block; font-size: 12px; color: var(--muted); }
            .summary-card span { display: block; margin-top: 10px; font-size: 28px; font-weight: 800; color: #0f172a; }
            .cards {
                margin-top: 18px;
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 14px;
            }
            .nav-card {
                display: block;
                padding: 20px;
                border: 1px solid var(--line);
                border-radius: 18px;
                background: linear-gradient(180deg, #ffffff 0%, #f8fbfd 100%);
                color: inherit;
                text-decoration: none;
            }
            .nav-card h2 { font-size: 20px; }
            .nav-card p { margin: 10px 0 0; color: var(--muted); line-height: 1.6; }
            .empty { padding: 28px 16px; text-align: center; color: var(--muted); }
            @media (max-width: 1024px) {
                .summary-grid, .cards { grid-template-columns: 1fr 1fr; }
            }
            @media (max-width: 760px) {
                .grid, .summary-grid, .cards { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <section class="panel">
                <h1><?= mm_h($title) ?></h1>
                <p class="lead"><?= mm_h($description) ?></p>
                <div class="menu-row">
                    <a class="button secondary" href="/risk_assessment/work_list.php">작업목록</a>
                    <a class="button ghost" href="/material_management/index.php">제품관리 메인</a>
                    <a class="button secondary" href="/material_management/inbound.php">입고</a>
                    <a class="button secondary" href="/material_management/outbound.php">출고</a>
                    <a class="button secondary" href="/material_management/status.php">현황</a>
                </div>
    <?php
}

function mm_page_footer(): void
{
    ?>
            </section>
        </div>
    </body>
    </html>
    <?php
}

function mm_fetch_material_options(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT material_id, material_name, current_stock, unit_name, storage_location, is_active, msds_file_name, msds_file_path
        FROM material_management_items
        ORDER BY material_name ASC, material_id DESC
    ");
    return $stmt->fetchAll();
}

function mm_find_material_by_name_and_location(PDO $pdo, string $materialName, string $storageLocation, ?int $excludeMaterialId = null): ?array
{
    $sql = "
        SELECT *
        FROM material_management_items
        WHERE material_name = :material_name
          AND COALESCE(storage_location, '') = :storage_location
    ";
    $params = [
        ':material_name' => $materialName,
        ':storage_location' => $storageLocation,
    ];

    if (($excludeMaterialId ?? 0) > 0) {
        $sql .= ' AND material_id <> :exclude_material_id';
        $params[':exclude_material_id'] = $excludeMaterialId;
    }

    $sql .= ' ORDER BY material_id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function mm_find_material_by_name(PDO $pdo, string $materialName, string $unitName, int $isActive, ?int $excludeMaterialId = null): ?array
{
    $sql = "
        SELECT *
        FROM material_management_items
        WHERE material_name = :material_name
          AND COALESCE(unit_name, '') = :unit_name
          AND is_active = :is_active
    ";
    $params = [
        ':material_name' => $materialName,
        ':unit_name' => $unitName,
        ':is_active' => $isActive,
    ];

    if (($excludeMaterialId ?? 0) > 0) {
        $sql .= ' AND material_id <> :exclude_material_id';
        $params[':exclude_material_id'] = $excludeMaterialId;
    }

    $sql .= ' ORDER BY material_id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function mm_merge_storage_locations(string ...$locations): string
{
    $result = [];
    $seen = [];

    foreach ($locations as $locationSet) {
        $parts = preg_split('/\s*,\s*/', trim($locationSet)) ?: [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $key = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $part;
        }
    }

    return implode(', ', $result);
}

function mm_merge_duplicate_items(PDO $pdo): void
{
    $groups = $pdo->query("
        SELECT
            material_name,
            COALESCE(unit_name, '') AS unit_name_key,
            is_active,
            COUNT(*) AS row_count
        FROM material_management_items
        GROUP BY material_name, COALESCE(unit_name, ''), is_active
        HAVING COUNT(*) > 1
    ")->fetchAll();

    if (empty($groups)) {
        return;
    }

    $pdo->beginTransaction();

    try {
        foreach ($groups as $group) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM material_management_items
                WHERE material_name = :material_name
                  AND COALESCE(unit_name, '') = :unit_name
                  AND is_active = :is_active
                ORDER BY material_id ASC
            ");
            $stmt->execute([
                ':material_name' => (string)($group['material_name'] ?? ''),
                ':unit_name' => (string)($group['unit_name_key'] ?? ''),
                ':is_active' => (int)($group['is_active'] ?? 1),
            ]);
            $rows = $stmt->fetchAll();
            if (count($rows) < 2) {
                continue;
            }

            $keeper = $rows[0];
            $keeperId = (int)($keeper['material_id'] ?? 0);
            if ($keeperId <= 0) {
                continue;
            }

            $mergedStock = 0.0;
            $duplicateIds = [];
            $mergedFields = [
                'msds_file_name' => (string)($keeper['msds_file_name'] ?? ''),
                'msds_file_path' => (string)($keeper['msds_file_path'] ?? ''),
                'manufacturer_name' => (string)($keeper['manufacturer_name'] ?? ''),
                'supplier_name' => (string)($keeper['supplier_name'] ?? ''),
                'notes' => (string)($keeper['notes'] ?? ''),
                'storage_location' => (string)($keeper['storage_location'] ?? ''),
            ];

            foreach ($rows as $index => $row) {
                $materialId = (int)($row['material_id'] ?? 0);
                $mergedStock += (float)($row['current_stock'] ?? 0);

                if ($mergedFields['msds_file_name'] === '' && trim((string)($row['msds_file_name'] ?? '')) !== '') {
                    $mergedFields['msds_file_name'] = (string)$row['msds_file_name'];
                }
                if ($mergedFields['msds_file_path'] === '' && trim((string)($row['msds_file_path'] ?? '')) !== '') {
                    $mergedFields['msds_file_path'] = (string)$row['msds_file_path'];
                }
                if ($mergedFields['manufacturer_name'] === '' && trim((string)($row['manufacturer_name'] ?? '')) !== '') {
                    $mergedFields['manufacturer_name'] = (string)$row['manufacturer_name'];
                }
                if ($mergedFields['supplier_name'] === '' && trim((string)($row['supplier_name'] ?? '')) !== '') {
                    $mergedFields['supplier_name'] = (string)$row['supplier_name'];
                }
                if ($mergedFields['notes'] === '' && trim((string)($row['notes'] ?? '')) !== '') {
                    $mergedFields['notes'] = (string)$row['notes'];
                }
                $mergedFields['storage_location'] = mm_merge_storage_locations(
                    $mergedFields['storage_location'],
                    (string)($row['storage_location'] ?? '')
                );

                if ($index > 0 && $materialId > 0) {
                    $duplicateIds[] = $materialId;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE material_management_items
                SET current_stock = :current_stock,
                    msds_file_name = :msds_file_name,
                    msds_file_path = :msds_file_path,
                    manufacturer_name = :manufacturer_name,
                    supplier_name = :supplier_name,
                    storage_location = :storage_location,
                    notes = :notes
                WHERE material_id = :material_id
            ");
            $stmt->execute([
                ':current_stock' => mm_normalize_quantity($mergedStock),
                ':msds_file_name' => $mergedFields['msds_file_name'] !== '' ? $mergedFields['msds_file_name'] : null,
                ':msds_file_path' => $mergedFields['msds_file_path'] !== '' ? $mergedFields['msds_file_path'] : null,
                ':manufacturer_name' => $mergedFields['manufacturer_name'] !== '' ? $mergedFields['manufacturer_name'] : null,
                ':supplier_name' => $mergedFields['supplier_name'] !== '' ? $mergedFields['supplier_name'] : null,
                ':storage_location' => $mergedFields['storage_location'] !== '' ? $mergedFields['storage_location'] : null,
                ':notes' => $mergedFields['notes'] !== '' ? $mergedFields['notes'] : null,
                ':material_id' => $keeperId,
            ]);

            foreach ($duplicateIds as $duplicateId) {
                $stmt = $pdo->prepare("
                    UPDATE material_management_movements
                    SET material_id = :keeper_id
                    WHERE material_id = :duplicate_id
                ");
                $stmt->execute([
                    ':keeper_id' => $keeperId,
                    ':duplicate_id' => $duplicateId,
                ]);

                $stmt = $pdo->prepare('DELETE FROM material_management_items WHERE material_id = :material_id');
                $stmt->execute([':material_id' => $duplicateId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function mm_refresh_item_storage_locations_from_movements(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT material_id, storage_location
        FROM material_management_items
        ORDER BY material_id ASC
    ");
    $items = $stmt->fetchAll();

    if (empty($items)) {
        return;
    }

    $selectMovementStmt = $pdo->prepare("
        SELECT storage_location
        FROM material_management_movements
        WHERE material_id = :material_id
        ORDER BY movement_id ASC
    ");
    $updateItemStmt = $pdo->prepare("
        UPDATE material_management_items
        SET storage_location = :storage_location
        WHERE material_id = :material_id
    ");

    $pdo->beginTransaction();

    try {
        foreach ($items as $item) {
            $materialId = (int)($item['material_id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }

            $selectMovementStmt->execute([':material_id' => $materialId]);
            $movementRows = $selectMovementStmt->fetchAll();

            $mergedLocation = (string)($item['storage_location'] ?? '');
            foreach ($movementRows as $movementRow) {
                $mergedLocation = mm_merge_storage_locations(
                    $mergedLocation,
                    (string)($movementRow['storage_location'] ?? '')
                );
            }

            $updateItemStmt->execute([
                ':storage_location' => $mergedLocation !== '' ? $mergedLocation : null,
                ':material_id' => $materialId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
