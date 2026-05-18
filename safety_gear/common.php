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

function sg_build_product_name(string $itemName, string $specName = '', string $modelName = '', string $fallback = ''): string
{
    $parts = array_values(array_filter([
        sg_normalize_text($itemName),
        sg_normalize_text($specName),
        sg_normalize_text($modelName),
    ], static function (string $value): bool {
        return $value !== '';
    }));

    $combined = implode(' / ', $parts);
    if ($combined !== '') {
        return $combined;
    }

    return sg_normalize_text($fallback);
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

function sg_make_internal_identifier(string $baseDate = ''): string
{
    $normalizedDate = preg_replace('/[^0-9]/', '', $baseDate);
    $datePart = is_string($normalizedDate) && strlen($normalizedDate) >= 8
        ? substr($normalizedDate, 0, 8)
        : date('Ymd');

    try {
        return 'SG-' . $datePart . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Throwable $e) {
        return 'SG-' . $datePart . '-' . mt_rand(100000, 999999);
    }
}

function sg_make_unique_internal_identifier(PDO $pdo, string $baseDate = '', int $maxAttempts = 20): string
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $candidate = sg_make_internal_identifier($baseDate);
        if (!sg_identifier_exists($pdo, $candidate)) {
            return $candidate;
        }
    }

    $normalizedDate = preg_replace('/[^0-9]/', '', $baseDate);
    $datePart = is_string($normalizedDate) && strlen($normalizedDate) >= 8
        ? substr($normalizedDate, 0, 8)
        : date('Ymd');

    do {
        $candidate = 'SG-' . $datePart . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    } while (sg_identifier_exists($pdo, $candidate));

    return $candidate;
}

function sg_get_pdo(): PDO
{
    /** @var PDO|null $pdo */
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
            item_name VARCHAR(255) NOT NULL DEFAULT '',
            spec_name VARCHAR(255) NOT NULL DEFAULT '',
            model_name VARCHAR(255) NOT NULL DEFAULT '',
            kcs_cert_no VARCHAR(255) NOT NULL DEFAULT '',
            manufacturer_name VARCHAR(255) NOT NULL DEFAULT '',
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
            item_name VARCHAR(255) NOT NULL DEFAULT '',
            spec_name VARCHAR(255) NOT NULL DEFAULT '',
            model_name VARCHAR(255) NOT NULL DEFAULT '',
            kcs_cert_no VARCHAR(255) NOT NULL DEFAULT '',
            manufacturer_name VARCHAR(255) NOT NULL DEFAULT '',
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_type (
            type_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type_name VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (type_id),
            UNIQUE KEY uq_safety_gear_type_name (type_name),
            KEY idx_safety_gear_type_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_signature (
            signature_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gear_uid VARCHAR(64) NOT NULL,
            signer_login_id VARCHAR(100) NOT NULL,
            signer_name VARCHAR(120) NOT NULL DEFAULT '',
            signer_team VARCHAR(120) NOT NULL DEFAULT '',
            signature_method VARCHAR(40) NOT NULL DEFAULT 'internal_ack',
            provider_code VARCHAR(40) NOT NULL DEFAULT '',
            signed_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (signature_id),
            UNIQUE KEY uq_safety_gear_signature_user_item (gear_uid, signer_login_id),
            KEY idx_safety_gear_signature_signer (signer_login_id),
            KEY idx_safety_gear_signature_signed_at (signed_at),
            CONSTRAINT fk_safety_gear_signature_uid
                FOREIGN KEY (gear_uid) REFERENCES safety_gear_item (gear_uid)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_signature_request (
            request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_token VARCHAR(80) NOT NULL,
            signer_login_id VARCHAR(100) NOT NULL,
            signer_name VARCHAR(120) NOT NULL DEFAULT '',
            signer_team VARCHAR(120) NOT NULL DEFAULT '',
            provider_code VARCHAR(40) NOT NULL DEFAULT 'pass',
            request_payload LONGTEXT NULL,
            status_label VARCHAR(40) NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (request_id),
            UNIQUE KEY uq_safety_gear_signature_request_token (request_token),
            KEY idx_safety_gear_signature_request_signer (signer_login_id),
            KEY idx_safety_gear_signature_request_status (status_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_signature_request_item (
            request_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_token VARCHAR(80) NOT NULL,
            gear_uid VARCHAR(64) NOT NULL,
            PRIMARY KEY (request_item_id),
            UNIQUE KEY uq_safety_gear_signature_request_item (request_token, gear_uid),
            KEY idx_safety_gear_signature_request_item_uid (gear_uid),
            CONSTRAINT fk_safety_gear_signature_request_item_uid
                FOREIGN KEY (gear_uid) REFERENCES safety_gear_item (gear_uid)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_receipt (
            receipt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            document_no VARCHAR(80) NOT NULL,
            worker_type VARCHAR(20) NOT NULL DEFAULT 'employee',
            employee_id INT NULL,
            worker_name VARCHAR(120) NOT NULL DEFAULT '',
            worker_team VARCHAR(120) NOT NULL DEFAULT '',
            worker_position VARCHAR(120) NOT NULL DEFAULT '',
            company_name VARCHAR(160) NOT NULL DEFAULT '',
            site_name VARCHAR(160) NOT NULL DEFAULT '',
            issue_date DATE NOT NULL,
            pledge_text TEXT NULL,
            status_label VARCHAR(40) NOT NULL DEFAULT 'issued',
            attachment_path VARCHAR(255) NOT NULL DEFAULT '',
            attachment_original_name VARCHAR(255) NOT NULL DEFAULT '',
            confirm_note TEXT NULL,
            created_by_login_id VARCHAR(100) NOT NULL DEFAULT '',
            created_by_name VARCHAR(120) NOT NULL DEFAULT '',
            confirmed_by_login_id VARCHAR(100) NOT NULL DEFAULT '',
            confirmed_by_name VARCHAR(120) NOT NULL DEFAULT '',
            confirmed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (receipt_id),
            UNIQUE KEY uq_safety_gear_receipt_document_no (document_no),
            KEY idx_safety_gear_receipt_worker (worker_name, worker_team),
            KEY idx_safety_gear_receipt_status (status_label),
            KEY idx_safety_gear_receipt_issue_date (issue_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_receipt_item (
            receipt_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT UNSIGNED NOT NULL,
            gear_label VARCHAR(160) NOT NULL DEFAULT '',
            item_name VARCHAR(255) NOT NULL DEFAULT '',
            spec_name VARCHAR(255) NOT NULL DEFAULT '',
            model_name VARCHAR(255) NOT NULL DEFAULT '',
            manufacturer_name VARCHAR(255) NOT NULL DEFAULT '',
            kcs_cert_no VARCHAR(255) NOT NULL DEFAULT '',
            detail_text TEXT NULL,
            quantity INT NOT NULL DEFAULT 1,
            assigned_date DATE NOT NULL,
            PRIMARY KEY (receipt_item_id),
            KEY idx_safety_gear_receipt_item_receipt (receipt_id),
            CONSTRAINT fk_safety_gear_receipt_item_receipt
                FOREIGN KEY (receipt_id) REFERENCES safety_gear_receipt (receipt_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safety_gear_receipt_preset (
            preset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            preset_name VARCHAR(160) NOT NULL,
            locale_code VARCHAR(10) NOT NULL DEFAULT 'ko',
            items_text TEXT NOT NULL,
            created_by_login_id VARCHAR(100) NOT NULL DEFAULT '',
            created_by_name VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (preset_id),
            UNIQUE KEY uq_safety_gear_receipt_preset_name (preset_name),
            KEY idx_safety_gear_receipt_preset_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!sg_column_exists($pdo, 'safety_gear_item', 'assigned_employee_id')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN assigned_employee_id INT NULL AFTER notes");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'item_name')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN item_name VARCHAR(255) NOT NULL DEFAULT '' AFTER gear_type");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'spec_name')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN spec_name VARCHAR(255) NOT NULL DEFAULT '' AFTER item_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'model_name')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN model_name VARCHAR(255) NOT NULL DEFAULT '' AFTER spec_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'kcs_cert_no')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN kcs_cert_no VARCHAR(255) NOT NULL DEFAULT '' AFTER model_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_item', 'manufacturer_name')) {
        $pdo->exec("ALTER TABLE safety_gear_item ADD COLUMN manufacturer_name VARCHAR(255) NOT NULL DEFAULT '' AFTER kcs_cert_no");
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
    if (!sg_column_exists($pdo, 'safety_gear_history', 'employee_id')) {
        $pdo->exec("ALTER TABLE safety_gear_history ADD COLUMN employee_id INT NULL AFTER history_note");
    }
    if (!sg_column_exists($pdo, 'safety_gear_history', 'employee_name')) {
        $pdo->exec("ALTER TABLE safety_gear_history ADD COLUMN employee_name VARCHAR(120) NOT NULL DEFAULT '' AFTER employee_id");
    }
    if (!sg_column_exists($pdo, 'safety_gear_history', 'employee_team')) {
        $pdo->exec("ALTER TABLE safety_gear_history ADD COLUMN employee_team VARCHAR(120) NOT NULL DEFAULT '' AFTER employee_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_template', 'item_name')) {
        $pdo->exec("ALTER TABLE safety_gear_template ADD COLUMN item_name VARCHAR(255) NOT NULL DEFAULT '' AFTER gear_type");
    }
    if (!sg_column_exists($pdo, 'safety_gear_template', 'spec_name')) {
        $pdo->exec("ALTER TABLE safety_gear_template ADD COLUMN spec_name VARCHAR(255) NOT NULL DEFAULT '' AFTER item_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_template', 'model_name')) {
        $pdo->exec("ALTER TABLE safety_gear_template ADD COLUMN model_name VARCHAR(255) NOT NULL DEFAULT '' AFTER spec_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_template', 'kcs_cert_no')) {
        $pdo->exec("ALTER TABLE safety_gear_template ADD COLUMN kcs_cert_no VARCHAR(255) NOT NULL DEFAULT '' AFTER model_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_template', 'manufacturer_name')) {
        $pdo->exec("ALTER TABLE safety_gear_template ADD COLUMN manufacturer_name VARCHAR(255) NOT NULL DEFAULT '' AFTER kcs_cert_no");
    }
    if (!sg_column_exists($pdo, 'safety_gear_receipt_item', 'detail_text')) {
        $pdo->exec("ALTER TABLE safety_gear_receipt_item ADD COLUMN detail_text TEXT NULL AFTER model_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_receipt_item', 'spec_name')) {
        $pdo->exec("ALTER TABLE safety_gear_receipt_item ADD COLUMN spec_name VARCHAR(255) NOT NULL DEFAULT '' AFTER item_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_receipt_item', 'manufacturer_name')) {
        $pdo->exec("ALTER TABLE safety_gear_receipt_item ADD COLUMN manufacturer_name VARCHAR(255) NOT NULL DEFAULT '' AFTER model_name");
    }
    if (!sg_column_exists($pdo, 'safety_gear_receipt_item', 'kcs_cert_no')) {
        $pdo->exec("ALTER TABLE safety_gear_receipt_item ADD COLUMN kcs_cert_no VARCHAR(255) NOT NULL DEFAULT '' AFTER manufacturer_name");
    }

    $pdo->exec("UPDATE safety_gear_item SET item_name = product_name WHERE item_name = '' AND product_name <> ''");
    $pdo->exec("UPDATE safety_gear_template SET item_name = product_name WHERE item_name = '' AND product_name <> ''");

    sg_seed_default_gear_types($pdo);
}

function sg_default_gear_types(): array
{
    return [
        '안전모',
        '안전화',
        '안전대',
        '안전조끼',
        '신호수조끼',
        '경광봉',
        '방진마스크',
        '보안경',
        '장갑',
    ];
}

function sg_save_gear_type(PDO $pdo, string $typeName): void
{
    $typeName = sg_normalize_text($typeName);
    if ($typeName === '') {
        return;
    }

    $now = sg_current_timestamp();
    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_type (type_name, created_at, updated_at)
        VALUES (:type_name, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            type_name = VALUES(type_name),
            updated_at = VALUES(updated_at)
    ");
    $stmt->execute([
        ':type_name' => $typeName,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function sg_seed_default_gear_types(PDO $pdo): void
{
    foreach (sg_default_gear_types() as $typeName) {
        sg_save_gear_type($pdo, $typeName);
    }
}

function sg_fetch_gear_types(PDO $pdo): array
{
    $types = [];

    $stmt = $pdo->query("
        SELECT type_id, type_name
        FROM safety_gear_type
        ORDER BY type_name ASC, type_id ASC
    ");
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $typeName = sg_normalize_text($row['type_name'] ?? '');
        if ($typeName === '') {
            continue;
        }
        $types[$typeName] = [
            'id' => (string)($row['type_id'] ?? ''),
            'name' => $typeName,
            'in_use' => false,
        ];
    }

    $sources = [
        "SELECT DISTINCT gear_type AS type_name FROM safety_gear_item WHERE gear_type <> ''",
        "SELECT DISTINCT gear_type AS type_name FROM safety_gear_template WHERE gear_type <> ''",
    ];

    foreach ($sources as $sql) {
        $sourceStmt = $pdo->query($sql);
        foreach ($sourceStmt->fetchAll() ?: [] as $row) {
            $typeName = sg_normalize_text($row['type_name'] ?? '');
            if ($typeName === '') {
                continue;
            }
            if (!isset($types[$typeName])) {
                $types[$typeName] = [
                    'id' => '',
                    'name' => $typeName,
                    'in_use' => true,
                ];
            } else {
                $types[$typeName]['in_use'] = true;
            }
        }
    }

    ksort($types, SORT_NATURAL);
    return array_values($types);
}

function sg_delete_gear_type(PDO $pdo, string $typeId, string $typeName = ''): bool
{
    $normalizedId = sg_normalize_text($typeId);
    $normalizedName = sg_normalize_text($typeName);

    if ($normalizedId === '' && $normalizedName === '') {
        return false;
    }

    if ($normalizedId !== '') {
        $stmt = $pdo->prepare("DELETE FROM safety_gear_type WHERE type_id = :type_id");
        $stmt->execute([':type_id' => (int)$normalizedId]);
        return $stmt->rowCount() > 0;
    }

    $stmt = $pdo->prepare("DELETE FROM safety_gear_type WHERE type_name = :type_name");
    $stmt->execute([':type_name' => $normalizedName]);
    return $stmt->rowCount() > 0;
}

function sg_build_receipt_detail_text(array $item): string
{
    $parts = array_values(array_filter([
        sg_normalize_text($item['item_name'] ?? ''),
        sg_normalize_text($item['spec_name'] ?? ''),
        sg_normalize_text($item['model_name'] ?? ''),
        sg_normalize_text($item['manufacturer_name'] ?? ''),
        sg_normalize_text($item['kcs_cert_no'] ?? ''),
    ], static function (string $value): bool {
        return $value !== '';
    }));

    if (!empty($parts)) {
        return implode(' / ', $parts);
    }

    $fallback = array_values(array_filter([
        sg_normalize_text($item['item_name'] ?? ''),
        sg_normalize_text($item['model_name'] ?? ''),
    ], static function (string $value): bool {
        return $value !== '';
    }));

    return implode(' / ', $fallback);
}

function sg_test_user_name(): string
{
    return '김남균';
}

function sg_can_access_my_gear(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return sg_normalize_text($user['name'] ?? '') === sg_test_user_name();
}

function sg_hidden_statuses(): array
{
    return ['반납', '교체', '폐기'];
}

function sg_visible_statuses(): array
{
    return ['사용 가능', '지급됨', '확인 필요', '점검 필요', '수리 중'];
}

function sg_status_filter_options(): array
{
    return array_merge(['active', 'all'], sg_visible_statuses(), sg_hidden_statuses());
}

function sg_status_label_is_hidden(string $status): bool
{
    return in_array(sg_normalize_text($status), sg_hidden_statuses(), true);
}

function sg_find_employee_option_for_user(array $user): ?array
{
    $userName = sg_normalize_text($user['name'] ?? '');
    $userTeam = sg_normalize_text($user['team'] ?? '');
    if ($userName === '') {
        return null;
    }

    $employees = sg_fetch_employee_options();
    foreach ($employees as $employee) {
        if ($userTeam !== ''
            && sg_normalize_text($employee['name'] ?? '') === $userName
            && sg_normalize_text($employee['team'] ?? '') === $userTeam) {
            return $employee;
        }
    }

    foreach ($employees as $employee) {
        if (sg_normalize_text($employee['name'] ?? '') === $userName) {
            return $employee;
        }
    }

    return null;
}

function sg_fetch_signature_map(PDO $pdo, string $signerLoginId): array
{
    $signerLoginId = sg_normalize_text($signerLoginId);
    if ($signerLoginId === '') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT gear_uid, signature_method, provider_code, signed_at, updated_at
        FROM safety_gear_signature
        WHERE signer_login_id = :signer_login_id
    ");
    $stmt->execute([':signer_login_id' => $signerLoginId]);

    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $gearUid = sg_normalize_text($row['gear_uid'] ?? '');
        if ($gearUid === '') {
            continue;
        }
        $map[$gearUid] = [
            'signature_method' => sg_normalize_text($row['signature_method'] ?? ''),
            'provider_code' => sg_normalize_text($row['provider_code'] ?? ''),
            'signed_at' => sg_normalize_text($row['signed_at'] ?? ''),
            'updated_at' => sg_normalize_text($row['updated_at'] ?? ''),
        ];
    }

    return $map;
}

function sg_signature_config(): array
{
    $path = __DIR__ . '/signature_config.php';
    if (!is_file($path)) {
        return [];
    }

    $config = require $path;
    return is_array($config) ? $config : [];
}

function sg_make_signature_request_token(): string
{
    try {
        return 'sigreq-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(8)), 0, 12);
    } catch (Throwable $e) {
        return 'sigreq-' . date('YmdHis') . '-' . mt_rand(100000, 999999);
    }
}

function sg_create_external_signature_request(PDO $pdo, array $user, array $gearUids, string $providerCode = 'pass'): string
{
    $loginId = sg_normalize_text($user['login_id'] ?? '');
    $userName = sg_normalize_text($user['name'] ?? '');
    $userTeam = sg_normalize_text($user['team'] ?? '');
    if ($loginId === '' || empty($gearUids)) {
        throw new RuntimeException('서명 요청을 생성할 수 없습니다.');
    }

    $requestToken = sg_make_signature_request_token();
    $payload = json_encode([
        'gear_uids' => array_values($gearUids),
        'provider' => $providerCode,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = sg_current_timestamp();

    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_signature_request (
            request_token, signer_login_id, signer_name, signer_team, provider_code, request_payload,
            status_label, requested_at, created_at, updated_at
        ) VALUES (
            :request_token, :signer_login_id, :signer_name, :signer_team, :provider_code, :request_payload,
            'pending', :requested_at, :created_at, :updated_at
        )
    ");
    $stmt->execute([
        ':request_token' => $requestToken,
        ':signer_login_id' => $loginId,
        ':signer_name' => $userName,
        ':signer_team' => $userTeam,
        ':provider_code' => $providerCode,
        ':request_payload' => $payload !== false ? $payload : null,
        ':requested_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $itemStmt = $pdo->prepare("
        INSERT INTO safety_gear_signature_request_item (request_token, gear_uid)
        VALUES (:request_token, :gear_uid)
    ");
    foreach ($gearUids as $gearUid) {
        $itemStmt->execute([
            ':request_token' => $requestToken,
            ':gear_uid' => $gearUid,
        ]);
    }

    return $requestToken;
}

function sg_fetch_external_signature_request(PDO $pdo, string $requestToken): ?array
{
    $requestToken = sg_normalize_text($requestToken);
    if ($requestToken === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT request_token, signer_login_id, signer_name, signer_team, provider_code, request_payload,
               status_label, requested_at, completed_at, created_at, updated_at
        FROM safety_gear_signature_request
        WHERE request_token = :request_token
        LIMIT 1
    ");
    $stmt->execute([':request_token' => $requestToken]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $itemStmt = $pdo->prepare("
        SELECT gear_uid
        FROM safety_gear_signature_request_item
        WHERE request_token = :request_token
        ORDER BY request_item_id ASC
    ");
    $itemStmt->execute([':request_token' => $requestToken]);
    $gearUids = array_values(array_filter(array_map(static function (array $item): string {
        return sg_normalize_text($item['gear_uid'] ?? '');
    }, $itemStmt->fetchAll() ?: [])));

    return [
        'request_token' => sg_normalize_text($row['request_token'] ?? ''),
        'signer_login_id' => sg_normalize_text($row['signer_login_id'] ?? ''),
        'signer_name' => sg_normalize_text($row['signer_name'] ?? ''),
        'signer_team' => sg_normalize_text($row['signer_team'] ?? ''),
        'provider_code' => sg_normalize_text($row['provider_code'] ?? ''),
        'status' => sg_normalize_text($row['status_label'] ?? ''),
        'requested_at' => sg_normalize_text($row['requested_at'] ?? ''),
        'completed_at' => sg_normalize_text($row['completed_at'] ?? ''),
        'gear_uids' => $gearUids,
    ];
}

function sg_complete_external_signature_request(PDO $pdo, array $requestRow): int
{
    $requestToken = sg_normalize_text($requestRow['request_token'] ?? '');
    $loginId = sg_normalize_text($requestRow['signer_login_id'] ?? '');
    $user = [
        'login_id' => $loginId,
        'name' => sg_normalize_text($requestRow['signer_name'] ?? ''),
        'team' => sg_normalize_text($requestRow['signer_team'] ?? ''),
    ];

    $gearUids = array_values(array_filter(array_map('sg_normalize_text', (array)($requestRow['gear_uids'] ?? []))));
    if ($requestToken === '' || $loginId === '' || empty($gearUids)) {
        throw new RuntimeException('서명 완료 처리에 필요한 정보가 부족합니다.');
    }

    $count = sg_sign_user_items($pdo, $user, $gearUids, 'pass_simple_auth', 'pass');
    $stmt = $pdo->prepare("
        UPDATE safety_gear_signature_request
        SET status_label = 'completed',
            completed_at = :completed_at,
            updated_at = :updated_at
        WHERE request_token = :request_token
    ");
    $now = sg_current_timestamp();
    $stmt->execute([
        ':completed_at' => $now,
        ':updated_at' => $now,
        ':request_token' => $requestToken,
    ]);

    return $count;
}

function sg_fetch_my_items(PDO $pdo, array $user, string $statusFilter = 'active'): array
{
    $userName = sg_normalize_text($user['name'] ?? '');
    $userTeam = sg_normalize_text($user['team'] ?? '');
    $loginId = sg_normalize_text($user['login_id'] ?? '');
    if ($userName === '' && $loginId === '') {
        return [];
    }

    $employee = sg_find_employee_option_for_user($user);
    $employeeId = sg_normalize_text($employee['id'] ?? '');

    $sql = "
        SELECT gear_uid, identifier_type, identifier_value, gear_type, item_name, spec_name, model_name, kcs_cert_no, product_name,
               purchase_vendor, purchase_price, purchased_at, status_label, notes,
               assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
               created_at, updated_at
        FROM safety_gear_item
        WHERE (
            assigned_employee_name = :assigned_employee_name
    ";
    $params = [
        ':assigned_employee_name' => $userName,
    ];

    if ($employeeId !== '') {
        $sql .= " OR assigned_employee_id = :assigned_employee_id";
        $params[':assigned_employee_id'] = (int)$employeeId;
    }

    if ($userTeam !== '') {
        $sql .= " OR (assigned_employee_name = :assigned_employee_name_team AND assigned_team = :assigned_team)";
        $params[':assigned_employee_name_team'] = $userName;
        $params[':assigned_team'] = $userTeam;
    }

    $sql .= ")";

    $statusFilter = sg_normalize_text($statusFilter);
    if ($statusFilter === '' || $statusFilter === 'active') {
        $placeholders = [];
        foreach (sg_hidden_statuses() as $index => $hiddenStatus) {
            $key = ':hidden_status_' . $index;
            $placeholders[] = $key;
            $params[$key] = $hiddenStatus;
        }
        $sql .= " AND status_label NOT IN (" . implode(', ', $placeholders) . ")";
    } elseif ($statusFilter !== 'all') {
        $sql .= " AND status_label = :status_label";
        $params[':status_label'] = $statusFilter;
    }

    $sql .= " ORDER BY updated_at DESC, item_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $items = array_map(static function (array $row) use ($pdo): array {
        return sg_map_item_row($pdo, $row);
    }, $rows);

    $signatureMap = sg_fetch_signature_map($pdo, $loginId);
    foreach ($items as &$item) {
        $gearUid = sg_normalize_text($item['id'] ?? '');
        $signature = $signatureMap[$gearUid] ?? null;
        $item['signature_completed'] = $signature !== null;
        $item['signature_signed_at'] = $signature['signed_at'] ?? '';
        $item['signature_method'] = $signature['signature_method'] ?? '';
    }
    unset($item);

    return $items;
}

function sg_sign_user_items(PDO $pdo, array $user, array $gearUids, string $signatureMethod = 'internal_ack', string $providerCode = 'test-local'): int
{
    $loginId = sg_normalize_text($user['login_id'] ?? '');
    $userName = sg_normalize_text($user['name'] ?? '');
    $userTeam = sg_normalize_text($user['team'] ?? '');
    if ($loginId === '' || empty($gearUids)) {
        return 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_signature (
            gear_uid, signer_login_id, signer_name, signer_team, signature_method, provider_code, signed_at, created_at, updated_at
        ) VALUES (
            :gear_uid, :signer_login_id, :signer_name, :signer_team, :signature_method, :provider_code, :signed_at, :created_at, :updated_at
        )
        ON DUPLICATE KEY UPDATE
            signer_name = VALUES(signer_name),
            signer_team = VALUES(signer_team),
            signature_method = VALUES(signature_method),
            provider_code = VALUES(provider_code),
            signed_at = VALUES(signed_at),
            updated_at = VALUES(updated_at)
    ");

    $count = 0;
    foreach ($gearUids as $gearUid) {
        $gearUid = sg_normalize_text($gearUid);
        if ($gearUid === '') {
            continue;
        }

        $now = sg_current_timestamp();
        $stmt->execute([
            ':gear_uid' => $gearUid,
            ':signer_login_id' => $loginId,
            ':signer_name' => $userName,
            ':signer_team' => $userTeam,
            ':signature_method' => $signatureMethod,
            ':provider_code' => $providerCode,
            ':signed_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        sg_add_history($pdo, $gearUid, '개인서명', '보호구 수령/확인 서명 완료', [
            'employee_name' => $userName,
            'employee_team' => $userTeam,
        ]);
        $count++;
    }

    return $count;
}

function sg_update_user_item_status(PDO $pdo, array $user, array $gearUids, string $newStatus, string $note = ''): int
{
    $newStatus = sg_normalize_text($newStatus);
    if ($newStatus === '' || empty($gearUids)) {
        return 0;
    }

    $userName = sg_normalize_text($user['name'] ?? '');
    $userTeam = sg_normalize_text($user['team'] ?? '');
    $stmt = $pdo->prepare("
        UPDATE safety_gear_item
        SET status_label = :status_label,
            updated_at = :updated_at
        WHERE gear_uid = :gear_uid
    ");

    $count = 0;
    foreach ($gearUids as $gearUid) {
        $gearUid = sg_normalize_text($gearUid);
        if ($gearUid === '') {
            continue;
        }

        $stmt->execute([
            ':status_label' => $newStatus,
            ':updated_at' => sg_current_timestamp(),
            ':gear_uid' => $gearUid,
        ]);

        $historyNote = $note !== '' ? $note : '본인 페이지에서 상태 변경';
        sg_add_history($pdo, $gearUid, $newStatus, $historyNote, [
            'employee_name' => $userName,
            'employee_team' => $userTeam,
        ]);
        $count++;
    }

    return $count;
}

function sg_fetch_history(PDO $pdo, string $gearUid): array
{
    $stmt = $pdo->prepare("
        SELECT created_at, history_type, history_note, employee_id, employee_name, employee_team
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
            'employee_id' => isset($row['employee_id']) && $row['employee_id'] !== null ? (string)$row['employee_id'] : '',
            'employee_name' => sg_normalize_text($row['employee_name'] ?? ''),
            'employee_team' => sg_normalize_text($row['employee_team'] ?? ''),
        ];
    }, $stmt->fetchAll() ?: []);
}

function sg_map_item_row(PDO $pdo, array $row): array
{
    $gearUid = sg_normalize_text($row['gear_uid'] ?? '');
    $itemName = sg_normalize_text($row['item_name'] ?? '');
    $specName = sg_normalize_text($row['spec_name'] ?? '');
    $modelName = sg_normalize_text($row['model_name'] ?? '');
    $kcsCertNo = sg_normalize_text($row['kcs_cert_no'] ?? '');
    $manufacturerName = sg_normalize_text($row['manufacturer_name'] ?? '');
    $legacyProductName = sg_normalize_text($row['product_name'] ?? '');
    if ($itemName === '' && $legacyProductName !== '') {
        $itemName = $legacyProductName;
    }
    $combinedProductName = sg_build_product_name($itemName, $specName, $modelName, $legacyProductName);

    return [
        'id' => $gearUid,
        'identifier_type' => sg_normalize_text($row['identifier_type'] ?? ''),
        'identifier_value' => sg_normalize_text($row['identifier_value'] ?? ''),
        'gear_type' => sg_normalize_text($row['gear_type'] ?? ''),
        'item_name' => $itemName,
        'spec_name' => $specName,
        'model_name' => $modelName,
        'kcs_cert_no' => $kcsCertNo,
        'manufacturer_name' => $manufacturerName,
        'product_name' => $combinedProductName,
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
        SELECT gear_uid, identifier_type, identifier_value, gear_type, item_name, spec_name, model_name, kcs_cert_no, manufacturer_name, product_name,
               purchase_vendor, purchase_price, purchased_at, status_label, notes,
               assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
               created_at, updated_at
        FROM safety_gear_item
    ";
    $params = [];
    $query = sg_normalize_text($query);

    if ($query !== '') {
        $likeValue = '%' . $query . '%';
        $searchColumns = [
            'identifier_value',
            'gear_type',
            'item_name',
            'spec_name',
            'model_name',
            'kcs_cert_no',
            'manufacturer_name',
            'product_name',
            'purchase_vendor',
            'status_label',
            'assigned_employee_name',
            'assigned_team',
        ];
        $whereParts = [];

        foreach ($searchColumns as $index => $columnName) {
            $paramKey = ':q' . $index;
            $whereParts[] = $columnName . ' LIKE ' . $paramKey;
            $params[$paramKey] = $likeValue;
        }

        $sql .= "
            WHERE " . implode("
               OR ", $whereParts) . "
        ";
    }

    $sql .= " ORDER BY updated_at DESC, item_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    return array_map(static function (array $row) use ($pdo): array {
        return sg_map_item_row($pdo, $row);
    }, $rows);
}

function sg_fetch_assigned_items_grouped_by_employee(PDO $pdo, array $employeeIds = [], bool $includeHidden = false): array
{
    $normalizedEmployeeIds = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return sg_normalize_text((string)$value);
    }, $employeeIds))));
    $employeeIdFilter = array_fill_keys($normalizedEmployeeIds, true);

    $groups = [];
    foreach (sg_fetch_all_items($pdo) as $item) {
        $employeeId = sg_normalize_text($item['assigned_employee_id'] ?? '');
        $employeeName = sg_normalize_text($item['assigned_employee_name'] ?? '');
        $employeeTeam = sg_normalize_text($item['assigned_team'] ?? '');
        $status = sg_normalize_text($item['status'] ?? '');

        if ($employeeId === '' && $employeeName === '') {
            continue;
        }
        if (!$includeHidden && sg_status_label_is_hidden($status)) {
            continue;
        }
        if (!empty($employeeIdFilter) && !isset($employeeIdFilter[$employeeId])) {
            continue;
        }

        $groupKey = $employeeId !== '' ? 'id:' . $employeeId : 'name:' . $employeeName . '|team:' . $employeeTeam;
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'employee_team' => $employeeTeam,
                'employee_position' => '',
                'assigned_items' => [],
            ];
        }
        $groups[$groupKey]['assigned_items'][] = $item;
    }

    $employeeOptions = sg_fetch_employee_options();
    $employeeOptionMap = [];
    foreach ($employeeOptions as $employee) {
        $employeeOptionMap[(string)($employee['id'] ?? '')] = $employee;
    }

    foreach ($groups as &$group) {
        $employeeId = sg_normalize_text($group['employee_id'] ?? '');
        if ($employeeId !== '' && isset($employeeOptionMap[$employeeId])) {
            $group['employee_position'] = sg_normalize_text($employeeOptionMap[$employeeId]['position'] ?? '');
            if ($group['employee_name'] === '') {
                $group['employee_name'] = sg_normalize_text($employeeOptionMap[$employeeId]['name'] ?? '');
            }
            if ($group['employee_team'] === '') {
                $group['employee_team'] = sg_normalize_text($employeeOptionMap[$employeeId]['team'] ?? '');
            }
        }
    }
    unset($group);

    usort($groups, static function (array $a, array $b): int {
        $teamCompare = strcmp((string)($a['employee_team'] ?? ''), (string)($b['employee_team'] ?? ''));
        if ($teamCompare !== 0) {
            return $teamCompare;
        }
        return strcmp((string)($a['employee_name'] ?? ''), (string)($b['employee_name'] ?? ''));
    });

    return $groups;
}

function sg_fetch_item_by_uid(PDO $pdo, string $gearUid): ?array
{
    $stmt = $pdo->prepare("
        SELECT gear_uid, identifier_type, identifier_value, gear_type, item_name, spec_name, model_name, kcs_cert_no, manufacturer_name, product_name,
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
        SELECT gear_uid, identifier_type, identifier_value, gear_type, item_name, spec_name, model_name, kcs_cert_no, manufacturer_name, product_name,
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

function sg_add_history(PDO $pdo, string $gearUid, string $type, string $note, array $meta = []): void
{
    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_history (
            gear_uid, history_type, history_note, employee_id, employee_name, employee_team, created_at
        ) VALUES (
            :gear_uid, :history_type, :history_note, :employee_id, :employee_name, :employee_team, :created_at
        )
    ");
    $stmt->execute([
        ':gear_uid' => $gearUid,
        ':history_type' => $type,
        ':history_note' => $note,
        ':employee_id' => isset($meta['employee_id']) && $meta['employee_id'] !== '' ? (int)$meta['employee_id'] : null,
        ':employee_name' => sg_normalize_text($meta['employee_name'] ?? ''),
        ':employee_team' => sg_normalize_text($meta['employee_team'] ?? ''),
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
    $itemName = sg_normalize_text($row['item_name'] ?? '');
    $specName = sg_normalize_text($row['spec_name'] ?? '');
    $modelName = sg_normalize_text($row['model_name'] ?? '');
    $kcsCertNo = sg_normalize_text($row['kcs_cert_no'] ?? '');
    $manufacturerName = sg_normalize_text($row['manufacturer_name'] ?? '');
    $legacyProductName = sg_normalize_text($row['product_name'] ?? '');
    if ($itemName === '' && $legacyProductName !== '') {
        $itemName = $legacyProductName;
    }
    $combinedProductName = sg_build_product_name($itemName, $specName, $modelName, $legacyProductName);

    return [
        'id' => (string)($row['template_id'] ?? ''),
        'template_name' => sg_normalize_text($row['template_name'] ?? ''),
        'gear_type' => sg_normalize_text($row['gear_type'] ?? ''),
        'item_name' => $itemName,
        'spec_name' => $specName,
        'model_name' => $modelName,
        'kcs_cert_no' => $kcsCertNo,
        'manufacturer_name' => $manufacturerName,
        'product_name' => $combinedProductName,
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
        SELECT template_id, template_name, gear_type, item_name, spec_name, model_name, kcs_cert_no, manufacturer_name, product_name, purchase_vendor,
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
        SELECT template_id, template_name, gear_type, item_name, spec_name, model_name, kcs_cert_no, manufacturer_name, product_name, purchase_vendor,
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

function sg_history_date_range(string $dateFrom, string $dateTo): array
{
    $from = sg_normalize_text($dateFrom);
    $to = sg_normalize_text($dateTo);

    $fromValue = $from !== '' ? $from . ' 00:00:00' : '';
    $toValue = $to !== '' ? $to . ' 23:59:59' : '';

    return [$fromValue, $toValue];
}

function sg_fetch_employee_history_report(PDO $pdo, string $employeeId = '', string $employeeName = '', string $dateFrom = '', string $dateTo = ''): array
{
    [$fromValue, $toValue] = sg_history_date_range($dateFrom, $dateTo);

    $sql = "
        SELECT h.history_id, h.gear_uid, h.history_type, h.history_note, h.employee_id, h.employee_name, h.employee_team, h.created_at,
               i.identifier_type, i.identifier_value, i.gear_type, i.item_name, i.spec_name, i.model_name, i.kcs_cert_no, i.product_name, i.status_label
        FROM safety_gear_history h
        JOIN safety_gear_item i ON i.gear_uid = h.gear_uid
        WHERE 1=1
    ";
    $params = [];

    if ($employeeId !== '') {
        $sql .= " AND h.employee_id = :employee_id";
        $params[':employee_id'] = (int)$employeeId;
    } elseif ($employeeName !== '') {
        $sql .= " AND h.employee_name = :employee_name";
        $params[':employee_name'] = $employeeName;
    }

    if ($fromValue !== '') {
        $sql .= " AND h.created_at >= :date_from";
        $params[':date_from'] = $fromValue;
    }
    if ($toValue !== '') {
        $sql .= " AND h.created_at <= :date_to";
        $params[':date_to'] = $toValue;
    }

    $sql .= " ORDER BY h.created_at DESC, h.history_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function sg_fetch_item_history_report(
    PDO $pdo,
    string $gearUid = '',
    string $identifierValue = '',
    string $dateFrom = '',
    string $dateTo = '',
    string $gearType = '',
    string $itemName = '',
    string $modelName = ''
): array
{
    [$fromValue, $toValue] = sg_history_date_range($dateFrom, $dateTo);

    $sql = "
        SELECT h.history_id, h.gear_uid, h.history_type, h.history_note, h.employee_id, h.employee_name, h.employee_team, h.created_at,
               i.identifier_type, i.identifier_value, i.gear_type, i.item_name, i.spec_name, i.model_name, i.kcs_cert_no, i.product_name, i.status_label
        FROM safety_gear_history h
        JOIN safety_gear_item i ON i.gear_uid = h.gear_uid
        WHERE 1=1
    ";
    $params = [];

    if ($gearUid !== '') {
        $sql .= " AND h.gear_uid = :gear_uid";
        $params[':gear_uid'] = $gearUid;
    } elseif ($identifierValue !== '') {
        $sql .= " AND i.identifier_value = :identifier_value";
        $params[':identifier_value'] = $identifierValue;
    }

    if ($gearType !== '') {
        $sql .= " AND i.gear_type = :gear_type";
        $params[':gear_type'] = $gearType;
    }

    if ($itemName !== '') {
        $sql .= " AND i.item_name = :item_name";
        $params[':item_name'] = $itemName;
    }

    if ($modelName !== '') {
        $sql .= " AND i.model_name = :model_name";
        $params[':model_name'] = $modelName;
    }

    if ($fromValue !== '') {
        $sql .= " AND h.created_at >= :date_from";
        $params[':date_from'] = $fromValue;
    }
    if ($toValue !== '') {
        $sql .= " AND h.created_at <= :date_to";
        $params[':date_to'] = $toValue;
    }

    $sql .= " ORDER BY h.created_at DESC, h.history_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function sg_receipt_company_name(): string
{
    return '(주)현대기전';
}

function sg_receipt_site_name(): string
{
    return '옥계면 한라시멘트';
}

function sg_receipt_pledge_text(): string
{
    return '본인은 상기 보호구를 지급받았으며, 지급받은 보호구를 작업 중 항상 착용하겠으며, 미착용으로 인한 불이익은 본인에게 있음을 확인합니다.';
}

function sg_receipt_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/safety_gear_receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function sg_unique_receipt_document_no(PDO $pdo, string $baseDocumentNo): string
{
    $baseDocumentNo = sg_normalize_text($baseDocumentNo);
    if ($baseDocumentNo === '') {
        $baseDocumentNo = 'SGR-' . date('YmdHis');
    }

    $candidate = $baseDocumentNo;
    $suffix = 2;
    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM safety_gear_receipt WHERE document_no = :document_no");
        $stmt->execute([':document_no' => $candidate]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $baseDocumentNo . '-' . $suffix;
        $suffix++;
    }
}

function sg_create_receipt(PDO $pdo, array $header, array $items, array $creator): int
{
    if (empty($items)) {
        throw new RuntimeException('확인서에 저장할 보호구 항목이 없습니다.');
    }

    $documentNo = sg_unique_receipt_document_no($pdo, (string)($header['document_no'] ?? ''));
    $now = sg_current_timestamp();

    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_receipt (
            document_no, worker_type, employee_id, worker_name, worker_team, worker_position,
            company_name, site_name, issue_date, pledge_text, status_label,
            created_by_login_id, created_by_name, created_at, updated_at
        ) VALUES (
            :document_no, :worker_type, :employee_id, :worker_name, :worker_team, :worker_position,
            :company_name, :site_name, :issue_date, :pledge_text, 'issued',
            :created_by_login_id, :created_by_name, :created_at, :updated_at
        )
    ");
    $stmt->execute([
        ':document_no' => $documentNo,
        ':worker_type' => sg_normalize_text($header['worker_type'] ?? 'employee'),
        ':employee_id' => sg_normalize_text($header['employee_id'] ?? '') !== '' ? (int)$header['employee_id'] : null,
        ':worker_name' => sg_normalize_text($header['worker_name'] ?? ''),
        ':worker_team' => sg_normalize_text($header['worker_team'] ?? ''),
        ':worker_position' => sg_normalize_text($header['worker_position'] ?? ''),
        ':company_name' => sg_normalize_text($header['company_name'] ?? sg_receipt_company_name()),
        ':site_name' => sg_normalize_text($header['site_name'] ?? sg_receipt_site_name()),
        ':issue_date' => sg_normalize_text($header['issue_date'] ?? date('Y-m-d')),
        ':pledge_text' => sg_normalize_text($header['pledge_text'] ?? sg_receipt_pledge_text()),
        ':created_by_login_id' => sg_normalize_text($creator['login_id'] ?? ''),
        ':created_by_name' => sg_normalize_text($creator['name'] ?? ''),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $receiptId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO safety_gear_receipt_item (
            receipt_id, gear_label, item_name, spec_name, model_name, manufacturer_name, kcs_cert_no, detail_text, quantity, assigned_date
        ) VALUES (
            :receipt_id, :gear_label, :item_name, :spec_name, :model_name, :manufacturer_name, :kcs_cert_no, :detail_text, :quantity, :assigned_date
        )
    ");
    foreach ($items as $item) {
        $itemStmt->execute([
            ':receipt_id' => $receiptId,
            ':gear_label' => sg_normalize_text($item['gear_label'] ?? ''),
            ':item_name' => sg_normalize_text($item['item_name'] ?? ''),
            ':spec_name' => sg_normalize_text($item['spec_name'] ?? ''),
            ':model_name' => sg_normalize_text($item['model_name'] ?? ''),
            ':manufacturer_name' => sg_normalize_text($item['manufacturer_name'] ?? ''),
            ':kcs_cert_no' => sg_normalize_text($item['kcs_cert_no'] ?? ''),
            ':detail_text' => sg_normalize_text($item['detail_text'] ?? sg_build_receipt_detail_text($item)),
            ':quantity' => max(1, (int)($item['quantity'] ?? 1)),
            ':assigned_date' => sg_normalize_text($item['assigned_date'] ?? date('Y-m-d')),
        ]);
    }

    return $receiptId;
}

function sg_fetch_receipt_items(PDO $pdo, int $receiptId): array
{
    $stmt = $pdo->prepare("
        SELECT gear_label, item_name, spec_name, model_name, manufacturer_name, kcs_cert_no, detail_text, quantity, assigned_date
        FROM safety_gear_receipt_item
        WHERE receipt_id = :receipt_id
        ORDER BY receipt_item_id ASC
    ");
    $stmt->execute([':receipt_id' => $receiptId]);
    return $stmt->fetchAll() ?: [];
}

function sg_fetch_receipts(PDO $pdo, int $limit = 50): array
{
    $stmt = $pdo->prepare("
        SELECT receipt_id, document_no, worker_type, employee_id, worker_name, worker_team, worker_position,
               company_name, site_name, issue_date, pledge_text, status_label,
               attachment_path, attachment_original_name, confirm_note,
               created_by_login_id, created_by_name, confirmed_by_login_id, confirmed_by_name, confirmed_at,
               created_at, updated_at
        FROM safety_gear_receipt
        ORDER BY created_at DESC, receipt_id DESC
        LIMIT :limit_count
    ");
    $stmt->bindValue(':limit_count', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as &$row) {
        $row['items'] = sg_fetch_receipt_items($pdo, (int)($row['receipt_id'] ?? 0));
    }
    unset($row);

    return $rows;
}

function sg_attach_receipt_file(PDO $pdo, int $receiptId, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('첨부파일 업로드에 실패했습니다.');
    }

    $originalName = sg_normalize_text($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('첨부파일은 jpg, jpeg, png, webp, pdf 형식만 가능합니다.');
    }

    $targetDir = sg_receipt_upload_dir();
    $filename = 'receipt_' . $receiptId . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $extension;
    $targetPath = $targetDir . '/' . $filename;
    if (!move_uploaded_file((string)($file['tmp_name'] ?? ''), $targetPath)) {
        throw new RuntimeException('첨부파일 저장에 실패했습니다.');
    }

    $relativePath = 'uploads/safety_gear_receipts/' . $filename;
    $stmt = $pdo->prepare("
        UPDATE safety_gear_receipt
        SET attachment_path = :attachment_path,
            attachment_original_name = :attachment_original_name,
            updated_at = :updated_at
        WHERE receipt_id = :receipt_id
    ");
    $stmt->execute([
        ':attachment_path' => $relativePath,
        ':attachment_original_name' => $originalName,
        ':updated_at' => sg_current_timestamp(),
        ':receipt_id' => $receiptId,
    ]);
}

function sg_confirm_receipt(PDO $pdo, int $receiptId, array $confirmer, string $note = ''): void
{
    $stmt = $pdo->prepare("
        UPDATE safety_gear_receipt
        SET status_label = 'confirmed',
            confirm_note = :confirm_note,
            confirmed_by_login_id = :confirmed_by_login_id,
            confirmed_by_name = :confirmed_by_name,
            confirmed_at = :confirmed_at,
            updated_at = :updated_at
        WHERE receipt_id = :receipt_id
    ");
    $now = sg_current_timestamp();
    $stmt->execute([
        ':confirm_note' => sg_normalize_text($note),
        ':confirmed_by_login_id' => sg_normalize_text($confirmer['login_id'] ?? ''),
        ':confirmed_by_name' => sg_normalize_text($confirmer['name'] ?? ''),
        ':confirmed_at' => $now,
        ':updated_at' => $now,
        ':receipt_id' => $receiptId,
    ]);
}

function sg_delete_receipt(PDO $pdo, int $receiptId): void
{
    $stmt = $pdo->prepare("DELETE FROM safety_gear_receipt WHERE receipt_id = :receipt_id");
    $stmt->execute([':receipt_id' => $receiptId]);
}

function sg_fetch_receipt_presets(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT preset_id, preset_name, locale_code, items_text, created_by_login_id, created_by_name, created_at, updated_at
        FROM safety_gear_receipt_preset
        ORDER BY updated_at DESC, preset_id DESC
    ");
    return $stmt->fetchAll() ?: [];
}

function sg_fetch_receipt_preset_by_id(PDO $pdo, string $presetId): ?array
{
    $presetId = sg_normalize_text($presetId);
    if ($presetId === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT preset_id, preset_name, locale_code, items_text, created_by_login_id, created_by_name, created_at, updated_at
        FROM safety_gear_receipt_preset
        WHERE preset_id = :preset_id
    ");
    $stmt->execute([':preset_id' => (int)$presetId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function sg_save_receipt_preset(PDO $pdo, string $presetName, string $itemsText, string $localeCode, array $user): void
{
    $presetName = sg_normalize_text($presetName);
    $itemsText = trim($itemsText);
    $localeCode = sg_normalize_text($localeCode) === 'ru' ? 'ru' : 'ko';

    if ($presetName === '') {
        throw new RuntimeException('preset 이름을 입력해 주세요.');
    }
    if ($itemsText === '') {
        throw new RuntimeException('저장할 공통 지급 보호구 목록이 없습니다.');
    }

    $now = sg_current_timestamp();
    $existing = $pdo->prepare("SELECT preset_id FROM safety_gear_receipt_preset WHERE preset_name = :preset_name");
    $existing->execute([':preset_name' => $presetName]);
    $presetId = (int)$existing->fetchColumn();

    if ($presetId > 0) {
        $stmt = $pdo->prepare("
            UPDATE safety_gear_receipt_preset
            SET locale_code = :locale_code,
                items_text = :items_text,
                created_by_login_id = :created_by_login_id,
                created_by_name = :created_by_name,
                updated_at = :updated_at
            WHERE preset_id = :preset_id
        ");
        $stmt->execute([
            ':locale_code' => $localeCode,
            ':items_text' => $itemsText,
            ':created_by_login_id' => sg_normalize_text($user['login_id'] ?? ''),
            ':created_by_name' => sg_normalize_text($user['name'] ?? ''),
            ':updated_at' => $now,
            ':preset_id' => $presetId,
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO safety_gear_receipt_preset (
            preset_name, locale_code, items_text, created_by_login_id, created_by_name, created_at, updated_at
        ) VALUES (
            :preset_name, :locale_code, :items_text, :created_by_login_id, :created_by_name, :created_at, :updated_at
        )
    ");
    $stmt->execute([
        ':preset_name' => $presetName,
        ':locale_code' => $localeCode,
        ':items_text' => $itemsText,
        ':created_by_login_id' => sg_normalize_text($user['login_id'] ?? ''),
        ':created_by_name' => sg_normalize_text($user['name'] ?? ''),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}
