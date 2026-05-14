<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=UTF-8');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

try {
    $pdo = sg_get_pdo();
} catch (Throwable $e) {
    respond(['ok' => false, 'message' => 'DB 연결에 실패했습니다: ' . $e->getMessage()], 500);
}

$action = sg_normalize_text($_REQUEST['action'] ?? 'list');

if ($action === 'list') {
    $query = sg_normalize_text($_GET['q'] ?? '');
    respond(['ok' => true, 'items' => sg_fetch_all_items($pdo, $query)]);
}

if ($action === 'employees') {
    respond(['ok' => true, 'employees' => sg_fetch_employee_options()]);
}

if ($action === 'templates') {
    respond(['ok' => true, 'templates' => sg_fetch_templates($pdo)]);
}

if ($action === 'get') {
    $id = sg_normalize_text($_GET['id'] ?? '');
    $item = $id !== '' ? sg_fetch_item_by_uid($pdo, $id) : null;
    if ($item === null) {
        respond(['ok' => false, 'message' => '항목을 찾지 못했습니다.'], 404);
    }
    respond(['ok' => true, 'item' => $item]);
}

if ($action === 'find') {
    $identifier = sg_normalize_text($_GET['identifier'] ?? '');
    if ($identifier === '') {
        respond(['ok' => false, 'message' => '식별값이 필요합니다.'], 400);
    }
    $item = sg_fetch_item_by_identifier($pdo, $identifier);
    if ($item === null) {
        respond(['ok' => true, 'found' => false]);
    }
    respond(['ok' => true, 'found' => true, 'item' => $item]);
}

if ($action === 'create_internal_key') {
    respond([
        'ok' => true,
        'identifier_type' => 'internal',
        'identifier_value' => sg_make_internal_identifier(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'message' => '지원하지 않는 요청입니다.'], 405);
}

if ($action === 'initial_issue') {
    $id = sg_normalize_text($_POST['id'] ?? '');
    $assignedEmployeeId = sg_normalize_text($_POST['assigned_employee_id'] ?? '');
    $assignedEmployeeName = sg_normalize_text($_POST['assigned_employee_name'] ?? '');
    $assignedTeam = sg_normalize_text($_POST['assigned_team'] ?? '');
    $assignedAt = sg_normalize_text($_POST['assigned_at'] ?? '') ?: sg_current_timestamp();
    $historyNote = sg_normalize_text($_POST['history_note'] ?? '시스템 도입 전 지급 완료된 품목을 초기 등록');

    $existing = $id !== '' ? sg_fetch_item_by_uid($pdo, $id) : null;
    if ($existing === null) {
        respond(['ok' => false, 'message' => '초기 지급 처리할 항목을 먼저 선택해 주세요.'], 404);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE safety_gear_item
            SET status_label = :status_label,
                assigned_employee_id = :assigned_employee_id,
                assigned_employee_name = :assigned_employee_name,
                assigned_team = :assigned_team,
                assigned_at = :assigned_at,
                updated_at = :updated_at
            WHERE gear_uid = :gear_uid
        ");
        $stmt->execute([
            ':status_label' => '지급됨',
            ':assigned_employee_id' => $assignedEmployeeId !== '' ? (int)$assignedEmployeeId : null,
            ':assigned_employee_name' => $assignedEmployeeName,
            ':assigned_team' => $assignedTeam,
            ':assigned_at' => $assignedAt,
            ':updated_at' => sg_current_timestamp(),
            ':gear_uid' => $id,
        ]);

        sg_add_history($pdo, $id, '지급', $historyNote, [
            'employee_id' => $assignedEmployeeId,
            'employee_name' => $assignedEmployeeName,
            'employee_team' => $assignedTeam,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond(['ok' => false, 'message' => '초기 지급 처리 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    respond([
        'ok' => true,
        'message' => '초기 지급 등록이 완료되었습니다.',
        'item' => sg_fetch_item_by_uid($pdo, $id),
        'items' => sg_fetch_all_items($pdo),
    ]);
}

if ($action === 'bulk_initial_issue') {
    $identifierType = sg_normalize_text($_POST['identifier_type'] ?? 'barcode');
    $rawIdentifiers = (string)($_POST['identifiers'] ?? '');
    $gearType = sg_normalize_text($_POST['gear_type'] ?? '');
    $itemName = sg_normalize_text($_POST['item_name'] ?? '');
    $modelName = sg_normalize_text($_POST['model_name'] ?? '');
    $purchaseVendor = sg_normalize_text($_POST['purchase_vendor'] ?? '');
    $purchasePrice = sg_normalize_price($_POST['purchase_price'] ?? '');
    $purchasedAt = sg_normalize_text($_POST['purchased_at'] ?? '');
    $notes = sg_normalize_text($_POST['notes'] ?? '');
    $assignedEmployeeId = sg_normalize_text($_POST['assigned_employee_id'] ?? '');
    $assignedEmployeeName = sg_normalize_text($_POST['assigned_employee_name'] ?? '');
    $assignedTeam = sg_normalize_text($_POST['assigned_team'] ?? '');
    $assignedAt = sg_normalize_text($_POST['assigned_at'] ?? '') ?: sg_current_timestamp();

    if ($gearType === '') {
        respond(['ok' => false, 'message' => '보호구 종류를 먼저 입력해 주세요.'], 400);
    }

    $identifiers = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $rawIdentifiers) ?: [])));
    if (empty($identifiers)) {
        respond(['ok' => false, 'message' => '일괄 등록할 식별값을 한 줄에 하나씩 입력해 주세요.'], 400);
    }

    $purchasePriceValue = $purchasePrice !== '' ? (float)$purchasePrice : null;
    $purchasedAtValue = $purchasedAt !== '' ? $purchasedAt : null;
    $assignedEmployeeIdValue = $assignedEmployeeId !== '' ? (int)$assignedEmployeeId : null;
    $created = 0;
    $skipped = [];

    try {
        $pdo->beginTransaction();

        $insertStmt = $pdo->prepare("
            INSERT INTO safety_gear_item (
                gear_uid, identifier_type, identifier_value, gear_type, item_name, model_name, product_name,
                purchase_vendor, purchase_price, purchased_at, status_label, notes,
                assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
                created_at, updated_at
            ) VALUES (
                :gear_uid, :identifier_type, :identifier_value, :gear_type, :item_name, :model_name, :product_name,
                :purchase_vendor, :purchase_price, :purchased_at, :status_label, :notes,
                :assigned_employee_id, :assigned_employee_name, :assigned_team, :assigned_at,
                :created_at, :updated_at
            )
        ");

        foreach ($identifiers as $identifierValue) {
            if (sg_identifier_exists($pdo, $identifierValue)) {
                $skipped[] = $identifierValue;
                continue;
            }

            $gearUid = sg_make_item_id();
            $now = sg_current_timestamp();
            $insertStmt->execute([
                ':gear_uid' => $gearUid,
                ':identifier_type' => $identifierType,
                ':identifier_value' => $identifierValue,
                ':gear_type' => $gearType,
                ':item_name' => $itemName,
                ':model_name' => $modelName,
                ':product_name' => trim($itemName . ($modelName !== '' ? ' / ' . $modelName : '')),
                ':purchase_vendor' => $purchaseVendor,
                ':purchase_price' => $purchasePriceValue,
                ':purchased_at' => $purchasedAtValue,
                ':status_label' => '지급됨',
                ':notes' => $notes,
                ':assigned_employee_id' => $assignedEmployeeIdValue,
                ':assigned_employee_name' => $assignedEmployeeName,
                ':assigned_team' => $assignedTeam,
                ':assigned_at' => $assignedAt,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            sg_add_history($pdo, $gearUid, '등록', '기존 지급품 초기 등록', [
                'employee_id' => $assignedEmployeeId,
                'employee_name' => $assignedEmployeeName,
                'employee_team' => $assignedTeam,
            ]);
            sg_add_history($pdo, $gearUid, '지급', '시스템 도입 전 지급 완료된 품목을 초기 등록', [
                'employee_id' => $assignedEmployeeId,
                'employee_name' => $assignedEmployeeName,
                'employee_team' => $assignedTeam,
            ]);
            $created++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond(['ok' => false, 'message' => '기존 지급품 일괄 등록 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    $message = '일괄 등록 완료: ' . $created . '건 등록';
    if (!empty($skipped)) {
        $message .= ', 중복으로 ' . count($skipped) . '건 건너뜀';
    }

    respond([
        'ok' => true,
        'message' => $message,
        'created_count' => $created,
        'skipped_identifiers' => $skipped,
        'items' => sg_fetch_all_items($pdo),
    ]);
}

if ($action === 'save_template') {
    $templateId = sg_normalize_text($_POST['template_id'] ?? '');
    $templateName = sg_normalize_text($_POST['template_name'] ?? '');
    $gearType = sg_normalize_text($_POST['gear_type'] ?? '');
    $itemName = sg_normalize_text($_POST['item_name'] ?? '');
    $modelName = sg_normalize_text($_POST['model_name'] ?? '');
    $purchaseVendor = sg_normalize_text($_POST['purchase_vendor'] ?? '');
    $purchasePrice = sg_normalize_price($_POST['purchase_price'] ?? '');
    $statusLabel = sg_normalize_text($_POST['status'] ?? '');
    $notes = sg_normalize_text($_POST['notes'] ?? '');

    if ($templateName === '') {
        respond(['ok' => false, 'message' => '템플릿 이름을 입력해 주세요.'], 400);
    }
    if ($gearType === '') {
        respond(['ok' => false, 'message' => '보호구 종류를 입력해 주세요.'], 400);
    }
    if (sg_template_name_exists($pdo, $templateName, $templateId)) {
        respond(['ok' => false, 'message' => '같은 이름의 템플릿이 이미 있습니다.'], 409);
    }

    $now = sg_current_timestamp();
    $purchasePriceValue = $purchasePrice !== '' ? (float)$purchasePrice : null;

    try {
        if ($templateId !== '' && sg_fetch_template_by_id($pdo, $templateId) !== null) {
            $stmt = $pdo->prepare("
                UPDATE safety_gear_template
                SET template_name = :template_name,
                    gear_type = :gear_type,
                    item_name = :item_name,
                    model_name = :model_name,
                    product_name = :product_name,
                    purchase_vendor = :purchase_vendor,
                    purchase_price = :purchase_price,
                    status_label = :status_label,
                    notes = :notes,
                    updated_at = :updated_at
                WHERE template_id = :template_id
            ");
            $stmt->execute([
                ':template_name' => $templateName,
                ':gear_type' => $gearType,
                ':item_name' => $itemName,
                ':model_name' => $modelName,
                ':product_name' => trim($itemName . ($modelName !== '' ? ' / ' . $modelName : '')),
                ':purchase_vendor' => $purchaseVendor,
                ':purchase_price' => $purchasePriceValue,
                ':status_label' => $statusLabel,
                ':notes' => $notes,
                ':updated_at' => $now,
                ':template_id' => (int)$templateId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO safety_gear_template (
                    template_name, gear_type, item_name, model_name, product_name, purchase_vendor, purchase_price,
                    status_label, notes, created_at, updated_at
                ) VALUES (
                    :template_name, :gear_type, :item_name, :model_name, :product_name, :purchase_vendor, :purchase_price,
                    :status_label, :notes, :created_at, :updated_at
                )
            ");
            $stmt->execute([
                ':template_name' => $templateName,
                ':gear_type' => $gearType,
                ':item_name' => $itemName,
                ':model_name' => $modelName,
                ':product_name' => trim($itemName . ($modelName !== '' ? ' / ' . $modelName : '')),
                ':purchase_vendor' => $purchaseVendor,
                ':purchase_price' => $purchasePriceValue,
                ':status_label' => $statusLabel,
                ':notes' => $notes,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    } catch (Throwable $e) {
        respond(['ok' => false, 'message' => '템플릿 저장 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    respond([
        'ok' => true,
        'message' => '템플릿이 저장되었습니다.',
        'templates' => sg_fetch_templates($pdo),
    ]);
}

if ($action === 'delete_template') {
    $templateId = sg_normalize_text($_POST['template_id'] ?? '');
    if ($templateId === '' || sg_fetch_template_by_id($pdo, $templateId) === null) {
        respond(['ok' => false, 'message' => '삭제할 템플릿을 찾지 못했습니다.'], 404);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM safety_gear_template WHERE template_id = :template_id");
        $stmt->execute([':template_id' => (int)$templateId]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'message' => '템플릿 삭제 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    respond([
        'ok' => true,
        'message' => '템플릿이 삭제되었습니다.',
        'templates' => sg_fetch_templates($pdo),
    ]);
}

if ($action === 'save_item') {
    $id = sg_normalize_text($_POST['id'] ?? '');
    $identifierType = sg_normalize_text($_POST['identifier_type'] ?? '');
    $identifierValue = sg_normalize_text($_POST['identifier_value'] ?? '');
    $gearType = sg_normalize_text($_POST['gear_type'] ?? '');
    $itemName = sg_normalize_text($_POST['item_name'] ?? '');
    $modelName = sg_normalize_text($_POST['model_name'] ?? '');
    $purchaseVendor = sg_normalize_text($_POST['purchase_vendor'] ?? '');
    $purchasePrice = sg_normalize_price($_POST['purchase_price'] ?? '');
    $purchasedAt = sg_normalize_text($_POST['purchased_at'] ?? '');
    $statusLabel = sg_normalize_text($_POST['status'] ?? '');
    $notes = sg_normalize_text($_POST['notes'] ?? '');
    $assignedEmployeeId = sg_normalize_text($_POST['assigned_employee_id'] ?? '');
    $assignedEmployeeName = sg_normalize_text($_POST['assigned_employee_name'] ?? '');
    $assignedTeam = sg_normalize_text($_POST['assigned_team'] ?? '');
    $assignedAt = sg_normalize_text($_POST['assigned_at'] ?? '');

    if ($identifierType === '') {
        respond(['ok' => false, 'message' => '식별 방식이 필요합니다.'], 400);
    }
    if ($identifierValue === '') {
        respond(['ok' => false, 'message' => '식별값이 필요합니다.'], 400);
    }
    if ($gearType === '') {
        respond(['ok' => false, 'message' => '보호구 종류를 입력해 주세요.'], 400);
    }
    if (sg_identifier_exists($pdo, $identifierValue, $id)) {
        respond(['ok' => false, 'message' => '이미 등록된 식별값입니다.'], 409);
    }

    $now = sg_current_timestamp();
    $purchasePriceValue = $purchasePrice !== '' ? (float)$purchasePrice : null;
    $purchasedAtValue = $purchasedAt !== '' ? $purchasedAt : null;
    $assignedAtValue = $assignedAt !== '' ? $assignedAt : null;
    $assignedEmployeeIdValue = $assignedEmployeeId !== '' ? (int)$assignedEmployeeId : null;

    try {
        $pdo->beginTransaction();

        if ($id !== '' && sg_fetch_item_by_uid($pdo, $id) !== null) {
            $stmt = $pdo->prepare("
                UPDATE safety_gear_item
                SET identifier_type = :identifier_type,
                    identifier_value = :identifier_value,
                    gear_type = :gear_type,
                    item_name = :item_name,
                    model_name = :model_name,
                    product_name = :product_name,
                    purchase_vendor = :purchase_vendor,
                    purchase_price = :purchase_price,
                    purchased_at = :purchased_at,
                    status_label = :status_label,
                    notes = :notes,
                    assigned_employee_id = :assigned_employee_id,
                    assigned_employee_name = :assigned_employee_name,
                    assigned_team = :assigned_team,
                    assigned_at = :assigned_at,
                    updated_at = :updated_at
                WHERE gear_uid = :gear_uid
            ");
            $stmt->execute([
                ':identifier_type' => $identifierType,
                ':identifier_value' => $identifierValue,
                ':gear_type' => $gearType,
                ':item_name' => $itemName,
                ':model_name' => $modelName,
                ':product_name' => trim($itemName . ($modelName !== '' ? ' / ' . $modelName : '')),
                ':purchase_vendor' => $purchaseVendor,
                ':purchase_price' => $purchasePriceValue,
                ':purchased_at' => $purchasedAtValue,
                ':status_label' => $statusLabel,
                ':notes' => $notes,
                ':assigned_employee_id' => $assignedEmployeeIdValue,
                ':assigned_employee_name' => $assignedEmployeeName,
                ':assigned_team' => $assignedTeam,
                ':assigned_at' => $assignedAtValue,
                ':updated_at' => $now,
                ':gear_uid' => $id,
            ]);
        } else {
            $id = sg_make_item_id();
            $stmt = $pdo->prepare("
                INSERT INTO safety_gear_item (
                    gear_uid, identifier_type, identifier_value, gear_type, item_name, model_name, product_name,
                    purchase_vendor, purchase_price, purchased_at, status_label, notes,
                    assigned_employee_id, assigned_employee_name, assigned_team, assigned_at,
                    created_at, updated_at
                ) VALUES (
                    :gear_uid, :identifier_type, :identifier_value, :gear_type, :item_name, :model_name, :product_name,
                    :purchase_vendor, :purchase_price, :purchased_at, :status_label, :notes,
                    :assigned_employee_id, :assigned_employee_name, :assigned_team, :assigned_at,
                    :created_at, :updated_at
                )
            ");
            $stmt->execute([
                ':gear_uid' => $id,
                ':identifier_type' => $identifierType,
                ':identifier_value' => $identifierValue,
                ':gear_type' => $gearType,
                ':item_name' => $itemName,
                ':model_name' => $modelName,
                ':product_name' => trim($itemName . ($modelName !== '' ? ' / ' . $modelName : '')),
                ':purchase_vendor' => $purchaseVendor,
                ':purchase_price' => $purchasePriceValue,
                ':purchased_at' => $purchasedAtValue,
                ':status_label' => $statusLabel,
                ':notes' => $notes,
                ':assigned_employee_id' => $assignedEmployeeIdValue,
                ':assigned_employee_name' => $assignedEmployeeName,
                ':assigned_team' => $assignedTeam,
                ':assigned_at' => $assignedAtValue,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            sg_add_history($pdo, $id, '등록', '최초 등록', [
                'employee_id' => $assignedEmployeeId,
                'employee_name' => $assignedEmployeeName,
                'employee_team' => $assignedTeam,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond(['ok' => false, 'message' => '저장 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    respond([
        'ok' => true,
        'message' => '저장되었습니다.',
        'item' => sg_fetch_item_by_uid($pdo, $id),
        'items' => sg_fetch_all_items($pdo),
    ]);
}

if ($action === 'add_history') {
    $id = sg_normalize_text($_POST['id'] ?? '');
    $type = sg_normalize_text($_POST['history_type'] ?? '');
    $note = sg_normalize_text($_POST['history_note'] ?? '');

    if ($id === '' || $type === '') {
        respond(['ok' => false, 'message' => '이력 정보를 입력해 주세요.'], 400);
    }
    if (sg_fetch_item_by_uid($pdo, $id) === null) {
        respond(['ok' => false, 'message' => '항목을 찾지 못했습니다.'], 404);
    }

    try {
        $pdo->beginTransaction();
        sg_add_history($pdo, $id, $type, $note, [
            'employee_id' => sg_normalize_text($_POST['assigned_employee_id'] ?? ''),
            'employee_name' => sg_normalize_text($_POST['assigned_employee_name'] ?? ''),
            'employee_team' => sg_normalize_text($_POST['assigned_team'] ?? ''),
        ]);
        $stmt = $pdo->prepare("UPDATE safety_gear_item SET updated_at = :updated_at WHERE gear_uid = :gear_uid");
        $stmt->execute([
            ':updated_at' => sg_current_timestamp(),
            ':gear_uid' => $id,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond(['ok' => false, 'message' => '이력 추가 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    respond([
        'ok' => true,
        'message' => '이력이 추가되었습니다.',
        'item' => sg_fetch_item_by_uid($pdo, $id),
        'items' => sg_fetch_all_items($pdo),
    ]);
}

if ($action === 'delete_item') {
    $id = sg_normalize_text($_POST['id'] ?? '');
    if ($id === '') {
        respond(['ok' => false, 'message' => '삭제할 항목이 필요합니다.'], 400);
    }
    if (sg_fetch_item_by_uid($pdo, $id) === null) {
        respond(['ok' => false, 'message' => '항목을 찾지 못했습니다.'], 404);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM safety_gear_item WHERE gear_uid = :gear_uid");
        $stmt->execute([':gear_uid' => $id]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()], 500);
    }

    respond([
        'ok' => true,
        'message' => '삭제되었습니다.',
        'items' => sg_fetch_all_items($pdo),
    ]);
}

respond(['ok' => false, 'message' => '알 수 없는 작업입니다.'], 400);
