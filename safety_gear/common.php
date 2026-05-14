<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/db_config.php';

function sg_normalize_text($value): string
{
    return trim((string)($value ?? ''));
}

function sg_normalize_price($value): string
{
    $text = preg_replace('/[^\d.]/', '', (string)($value ?? ''));
    return $text === null ? '' : trim($text);
}

function sg_current_timestamp(): string
{
    return date('Y-m-d H:i:s');
}

function sg_make_item_id(): string
{
    try {
        return 'gear-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
        return 'gear-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
    }
}

function sg_make_internal_identifier(): string
{
    try {
        return 'SG-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Throwable $e) {
        return 'SG-' . date('YmdHis') . '-' . mt_rand(100000, 999999);
    }
}

function sg_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = getDB();
        sg_ensure_tables($pdo);
    }
    return $pdo;
}

function sg_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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
    return (int)$stmt->fetchColumn() > 0;
}

function sg_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_item (
            item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gear_uid VARCHAR(64) NOT NULL,
            identifier_type VARCHAR(32) NOT NULL,
            identifier_value VARCHAR(255) NOT NULL,
            gear_type VARCHAR(120) NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            purchase_vendor VARCHAR(255) NOT NULL DEFAULT '',
            purchase_price DECIMAL(12,2) DEFAULT NULL,
            purchased_at DATE DEFAULT NULL,
            status_label VARCHAR(80) NOT NULL DEFAULT '',
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (item_id),
            UNIQUE KEY uq_safety_gear_uid (gear_uid),
            UNIQUE KEY uq_safety_gear_identifier_value (identifier_value),
            KEY idx_safety_gear_type (gear_type),
            KEY idx_safety_gear_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_history (
            history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gear_uid VARCHAR(64) NOT NULL,
            history_type VARCHAR(80) NOT NULL,
            history_note TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (history_id),
            KEY idx_safety_gear_history_uid (gear_uid),
            KEY idx_safety_gear_history_created_at (created_at),
            CONSTRAINT fk_safety_gear_history_uid
                FOREIGN KEY (gear_uid) REFERENCES safety_gear_item (gear_uid)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_template (
            template_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_name VARCHAR(160) NOT NULL,
            gear_type VARCHAR(120) NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            purchase_vendor VARCHAR(255) NOT NULL DEFAULT '',
            purchase_price DECIMAL(12,2) DEFAULT NULL,
            status_label VARCHAR(80) NOT NULL DEFAULT '',
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (template_id),
            UNIQUE KEY uq_safety_gear_template_name (template_name),
            KEY idx_safety_gear_template_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!sg_column_exists($pdo, 'safety_gear_item', 'assigned_employee_id')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN assigned_employee_id INT NULL AFTER notes");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'assigned_employee_name')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN assigned_employee_name VARCHAR(120) NOT NULL DEFAULT '' AFTER assigned_employee_id");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'assigned_team')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN assigned_team VARCHAR(120) NOT NULL DEFAULT '' AFTER assigned_employee_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'assigned_at')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN assigned_at DATETIME NULL AFTER assigned_team");
    }
}

function sg_fetch_history(PDO $pdo, string $gearUid): array
{
    $stmt = $pdo->prepare("
        SELECT created_at, history_type, history_note
        FROM safety_gear_history
        WHERE gear_uid = :gear_uid
        ORDER BY created_at DESC, history_id DESC
    ");
    $stmt->execute([':gear_uid' => $gearUid]);

    return array_map(static function (array $row): array {
        return [
            'timestamp' => sg_normalize_text($row['created_at'] ?? ''),
            'type' => sg_normalize_text($row['history_type'] ?? ''),
            'note' => sg_normalize_text($row['history_note'] ?? ''),
        ];
    }, $stmt->fetchAll() ?: []);
}

function sg_map_item_row(PDO $pdo, array $row): array
{
    $gearUid = sg_normalize_text($row['gear_uid'] ?? '');

    return [
        'id' => $gearUid,
        'identifier_type' => sg_normalize_text($row['identifier_type'] ?? ''),
        'identifier_value' => sg_normalize_text($row['identifier_value'] ?? ''),
        'gear_type' => sg_normalize_text($row['gear_type'] ?? ''),
        'product_name' => sg_normalize_text($row['product_name'] ?? ''),
        'purchase_vendor' => sg_normalize_text($row['purchase_vendor'] ?? ''),
        'purchase_price' => isset($row['purchase_price']) && $row['purchase_price'] !== null ? rtrim(rtrim((string)$row['purchase_price'], '0'), '.') : '',
        'purchased_at' => sg_normalize_text($row['purchased_at'] ?? ''),
        'status' => sg_normalize_text($row['status_label'] ?? ''),
        'notes' => sg_normalize_text($row['notes'] ?? ''),
        'assigned_employee_id' => isset($row['assigned_employee_id']) && $row['assigned_employee_id'] !== null ? (string)$row['assigned_employee_id'] : '',
        'assigned_employee_name' => sg_normalize_text($row['assigned_employee_name'] ?? ''),
        'assigned_team' => sg_normalize_text($row['assigned_team'] ?? ''),
        'assigned_at' => sg_normalize_text($row['assigned_at'] ?? ''),
        'created_at' => sg_normalize_text($row['created_at'] ?? ''),
        'updated_at' => sg_normalize_text($row['updated_at'] ?? ''),
        'history' => $gearUid !== '' ? sg_fetch_history($pdo, $gearUid) : [],
    ];
}

function sg_fetch_all_items(PDO $pdo, string $query = ''): array
{
    $sql = "
        SELECT gear_uid, identifier_type, identifier_value, gear_type, product_name,
               purchase_vendor, purchase_price, purchased_at, status_label, notes,
               assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
               created_at, updated_at
        FROM safety_gear_item
    ";
    $params = [];
    $query = sg_normalize_text($query);

    if ($query !== '') {
        $sql .= "
            WHERE identifier_value LIKE :q
               OR gear_type LIKE :q
               OR product_name LIKE :q
               OR purchase_vendor LIKE :q
               OR status_label LIKE :q
               OR assigned_employee_name LIKE :q
               OR assigned_team LIKE :q
        ";
        $params[':q'] = '%' . $query . '%';
    }

    $sql .= " ORDER BY updated_at DESC, item_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    return array_map(static function (array $row) use ($pdo): array {
        return sg_map_item_row($pdo, $row);
    }, $rows);
}

function sg_fetch_item_by_uid(PDO $pdo, string $gearUid): ?array
{
    $stmt = $pdo->prepare("
        SELECT gear_uid, identifier_type, identifier_value, gear_type, product_name,
               purchase_vendor, purchase_price, purchased_at, status_label, notes,
               assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
               created_at, updated_at
        FROM safety_gear_item
        WHERE gear_uid = :gear_uid
        LIMIT 1
    ");
    $stmt->execute([':gear_uid' => $gearUid]);
    $row = $stmt->fetch();

    return $row ? sg_map_item_row($pdo, $row) : null;
}

function sg_fetch_item_by_identifier(PDO $pdo, string $identifierValue): ?array
{
    $stmt = $pdo->prepare("
        SELECT gear_uid, identifier_type, identifier_value, gear_type, product_name,
               purchase_vendor, purchase_price, purchased_at, status_label, notes,
               assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
               created_at, updated_at
        FROM safety_gear_item
        WHERE identifier_value = :identifier_value
        LIMIT 1
    ");
    $stmt->execute([':identifier_value' => $identifierValue]);
    $row = $stmt->fetch();

    return $row ? sg_map_item_row($pdo, $row) : null;
}

function sg_identifier_exists(PDO $pdo, string $identifierValue, string $excludeGearUid = ''): bool
{
    $sql = "SELECT COUNT(*) FROM safety_gear_item WHERE identifier_value = :identifier_value";
    $params = [':identifier_value' => $identifierValue];

    if ($excludeGearUid !== '') {
        $sql .= " AND gear_uid != :exclude_gear_uid";
        $params[':exclude_gear_uid'] = $excludeGearUid;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function sg_add_history(PDO $pdo, string $gearUid, string $type, string $note): void
{
    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_history (gear_uid, history_type, history_note, created_at)
        VALUES (:gear_uid, :history_type, :history_note, :created_at)
    ");
    $stmt->execute([
        ':gear_uid' => $gearUid,
        ':history_type' => $type,
        ':history_note' => $note,
        ':created_at' => sg_current_timestamp(),
    ]);
}

function sg_fetch_employee_options(): array
{
    $dbPath = __DIR__ . '/../employees/employees.db';
    if (!is_file($dbPath)) {
        return [];
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("
        SELECT id, employee_no, name, team, position
        FROM employees
        ORDER BY
            CASE WHEN team IS NULL OR team = '' THEN 1 ELSE 0 END,
            team ASC,
            name ASC
    ");

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'employee_no' => sg_normalize_text($row['employee_no'] ?? ''),
            'name' => sg_normalize_text($row['name'] ?? ''),
            'team' => sg_normalize_text($row['team'] ?? ''),
            'position' => sg_normalize_text($row['position'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function sg_map_template_row(array $row): array
{
    return [
        'id' => (string)($row['template_id'] ?? ''),
        'template_name' => sg_normalize_text($row['template_name'] ?? ''),
        'gear_type' => sg_normalize_text($row['gear_type'] ?? ''),
        'product_name' => sg_normalize_text($row['product_name'] ?? ''),
        'purchase_vendor' => sg_normalize_text($row['purchase_vendor'] ?? ''),
        'purchase_price' => isset($row['purchase_price']) && $row['purchase_price'] !== null ? rtrim(rtrim((string)$row['purchase_price'], '0'), '.') : '',
        'status' => sg_normalize_text($row['status_label'] ?? ''),
        'notes' => sg_normalize_text($row['notes'] ?? ''),
        'created_at' => sg_normalize_text($row['created_at'] ?? ''),
        'updated_at' => sg_normalize_text($row['updated_at'] ?? ''),
    ];
}

function sg_fetch_templates(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT template_id, template_name, gear_type, product_name, purchase_vendor,
               purchase_price, status_label, notes, created_at, updated_at
        FROM safety_gear_template
        ORDER BY updated_at DESC, template_id DESC
    ");

    return array_map('sg_map_template_row', $stmt->fetchAll() ?: []);
}

function sg_fetch_template_by_id(PDO $pdo, string $templateId): ?array
{
    if ($templateId === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT template_id, template_name, gear_type, product_name, purchase_vendor,
               purchase_price, status_label, notes, created_at, updated_at
        FROM safety_gear_template
        WHERE template_id = :template_id
        LIMIT 1
    ");
    $stmt->execute([':template_id' => (int)$templateId]);
    $row = $stmt->fetch();

    return $row ? sg_map_template_row($row) : null;
}

function sg_template_name_exists(PDO $pdo, string $templateName, string $excludeId = ''): bool
{
    $sql = "SELECT COUNT(*) FROM safety_gear_template WHERE template_name = :template_name";
    $params = [':template_name' => $templateName];

    if ($excludeId !== '') {
        $sql .= " AND template_id != :template_id";
        $params[':template_id'] = (int)$excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}
