<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/hazard_4m.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nullable_int($value, ?int $min = null, ?int $max = null): ?int
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    $intValue = (int)$value;
    if ($min !== null && $intValue < $min) {
        return null;
    }
    if ($max !== null && $intValue > $max) {
        return null;
    }

    return $intValue;
}

function nullable_text($value): ?string
{
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function normalize_return_to($value): string
{
    $returnTo = trim((string)$value);
    if ($returnTo === '') {
        return 'list.html';
    }

    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $returnTo)) {
        return 'list.html';
    }

    if (strpos($returnTo, '//') === 0) {
        return 'list.html';
    }

    return $returnTo;
}

function ensure_history_table(PDO $pdo): void
{
  $pdo->exec("CREATE TABLE IF NOT EXISTS unit_ra_item_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unit_ra_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NULL,
    source_item_id BIGINT UNSIGNED NULL,
    action_type VARCHAR(20) NOT NULL,
    changed_fields JSON NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    changed_by_login_id VARCHAR(100) NULL,
    changed_by_name VARCHAR(100) NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY idx_unit_ra_item_history_unit_changed (unit_ra_id, changed_at),
    KEY idx_unit_ra_item_history_item (item_id),
    KEY idx_unit_ra_item_history_source_item (source_item_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $columnStmt = $pdo->query("SHOW COLUMNS FROM unit_ra_item_history LIKE 'source_item_id'");
  $hasSourceItemId = $columnStmt !== false && $columnStmt->fetch() !== false;
  if (!$hasSourceItemId) {
    $pdo->exec("ALTER TABLE unit_ra_item_history ADD COLUMN source_item_id BIGINT UNSIGNED NULL AFTER item_id");
  }

  $pdo->exec("UPDATE unit_ra_item_history SET source_item_id = item_id WHERE source_item_id IS NULL AND item_id IS NOT NULL");
}

function item_history_snapshot(array $item): array
{
  return [
    'sort_no' => isset($item['sort_no']) && $item['sort_no'] !== '' ? (int)$item['sort_no'] : null,
    'task_code' => nullable_text($item['task_code'] ?? null),
    'task_name' => trim((string)($item['task_name'] ?? '')),
    'hazard_name' => trim((string)($item['hazard_name'] ?? '')),
    'hazard_4m' => hazard_4m_normalize_manual($item['hazard_4m'] ?? null),
    'accident_type' => nullable_text($item['accident_type'] ?? null),
    'injury_result' => nullable_text($item['injury_result'] ?? null),
    'cause_text' => nullable_text($item['cause_text'] ?? null),
    'current_control_text' => nullable_text($item['current_control_text'] ?? null),
    'additional_control_text' => nullable_text($item['additional_control_text'] ?? null),
    'likelihood_before' => nullable_int($item['likelihood_before'] ?? null, 1, 5),
    'severity_before' => nullable_int($item['severity_before'] ?? null, 1, 5),
    'risk_score_before' => nullable_int($item['risk_score_before'] ?? null, 1, 25),
    'likelihood_current' => nullable_int($item['likelihood_current'] ?? null, 1, 5),
    'severity_current' => nullable_int($item['severity_current'] ?? null, 1, 5),
    'risk_score_current' => nullable_int($item['risk_score_current'] ?? null, 1, 25),
    'likelihood_after' => nullable_int($item['likelihood_after'] ?? null, 1, 5),
    'severity_after' => nullable_int($item['severity_after'] ?? null, 1, 5),
    'risk_score_after' => nullable_int($item['risk_score_after'] ?? null, 1, 25),
    'improvement_due_date' => nullable_text($item['improvement_due_date'] ?? null),
    'remark' => nullable_text($item['remark'] ?? null),
    'use_yn' => (($item['use_yn'] ?? 'Y') === 'N') ? 'N' : 'Y',
  ];
}

function build_history_diff(array $before, array $after): array
{
  $changedFields = [];
  foreach ($after as $field => $afterValue) {
    $beforeValue = $before[$field] ?? null;
    if ($beforeValue !== $afterValue) {
      $changedFields[] = $field;
    }
  }

  return $changedFields;
}

function save_item_history(PDO $pdo, int $unitRaId, ?int $itemId, string $actionType, array $beforeData, array $afterData, array $changedFields, array $user): void
{
  $stmt = $pdo->prepare("INSERT INTO unit_ra_item_history
    (
      unit_ra_id,
      item_id,
      source_item_id,
      action_type,
      changed_fields,
      before_data,
      after_data,
      changed_by_login_id,
      changed_by_name,
      changed_at
    )
    VALUES
    (
      :unit_ra_id,
      :item_id,
      :source_item_id,
      :action_type,
      :changed_fields,
      :before_data,
      :after_data,
      :changed_by_login_id,
      :changed_by_name,
      NOW()
    )");
  $stmt->execute([
    ':unit_ra_id' => $unitRaId,
    ':item_id' => $itemId,
    ':source_item_id' => $itemId,
    ':action_type' => $actionType,
    ':changed_fields' => !empty($changedFields) ? json_encode(array_values($changedFields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ':before_data' => !empty($beforeData) ? json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ':after_data' => !empty($afterData) ? json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ':changed_by_login_id' => nullable_text($user['login_id'] ?? null),
    ':changed_by_name' => nullable_text($user['name'] ?? null) ?? auth_display_name($user),
  ]);
}

function decode_history_json($value): array
{
  if (!is_string($value) || trim($value) === '') {
    return [];
  }

  $decoded = json_decode($value, true);
  return is_array($decoded) ? $decoded : [];
}

function history_field_labels(): array
{
  return [
    'sort_no' => '정렬',
    'task_code' => '작업코드',
    'task_name' => '작업명',
    'hazard_name' => '주요 위험요소',
    'accident_type' => '사고유형',
    'injury_result' => '상해결과',
    'cause_text' => '원인',
    'current_control_text' => '현재 조치사항',
    'additional_control_text' => '추가 조치사항',
    'likelihood_before' => '개선 전 L',
    'severity_before' => '개선 전 S',
    'risk_score_before' => '개선 전 점수',
    'likelihood_current' => '현재 L',
    'severity_current' => '현재 S',
    'risk_score_current' => '현재 점수',
    'likelihood_after' => '개선 후 L',
    'severity_after' => '개선 후 S',
    'risk_score_after' => '개선 후 점수',
    'improvement_due_date' => '개선기한',
    'remark' => '비고',
    'use_yn' => '사용',
  ];
}

function history_format_value($value): string
{
  if ($value === null || $value === '') {
    return '-';
  }

  return trim((string)$value) === '' ? '-' : (string)$value;
}

function build_history_summary_lines(array $history, array $fieldLabels): array
{
  $actionType = (string)($history['action_type'] ?? 'update');
  $beforeData = decode_history_json($history['before_data'] ?? null);
  $afterData = decode_history_json($history['after_data'] ?? null);
  $changedFields = decode_history_json($history['changed_fields'] ?? null);
  $lines = [];

  foreach ($changedFields as $fieldName) {
    $label = $fieldLabels[$fieldName] ?? $fieldName;
    $beforeValue = history_format_value($beforeData[$fieldName] ?? null);
    $afterValue = history_format_value($afterData[$fieldName] ?? null);

    if ($actionType === 'insert') {
      $lines[] = sprintf('%s: 신규값 %s', $label, $afterValue);
      continue;
    }

    if ($actionType === 'delete') {
      $lines[] = sprintf('%s: 삭제 전 %s', $label, $beforeValue);
      continue;
    }

    $lines[] = sprintf('%s: 수정 전 %s / 수정 후 %s', $label, $beforeValue, $afterValue);
  }

  return $lines;
}

function history_action_label(string $actionType): string
{
  if ($actionType === 'insert') {
    return '신규';
  }
  if ($actionType === 'delete') {
    return '삭제';
  }

  return '수정';
}

function score_from_pair(?int $likelihood, ?int $severity): ?int
{
    if ($likelihood === null || $severity === null) {
        return null;
    }

    return $likelihood * $severity;
}

function blank_item(array $defaults = []): array
{
    return array_merge([
        'item_id' => '',
        'sort_no' => '',
        'task_code' => '',
        'task_name' => '',
    'hazard_name' => '',
        'hazard_4m' => '',
        'accident_type' => '',
        'injury_result' => '',
        'cause_text' => '',
        'current_control_text' => '',
        'additional_control_text' => '',
        'likelihood_before' => '',
        'severity_before' => '',
        'risk_score_before' => '',
        'likelihood_current' => '',
        'severity_current' => '',
        'risk_score_current' => '',
        'likelihood_after' => '',
        'severity_after' => '',
        'risk_score_after' => '',
        'improvement_due_date' => '',
        'remark' => '',
        'use_yn' => 'Y',
        'delete_yn' => '0',
    ], $defaults);
}

function post_array_value(array $source, string $key, int $index)
{
    $values = $source[$key] ?? [];
    return is_array($values) ? ($values[$index] ?? null) : null;
}

function post_row_count(array $source): int
{
    $keys = [
        'item_id',
        'sort_no',
        'task_code',
        'task_name',
        'hazard_name',
        'hazard_4m',
        'accident_type',
        'injury_result',
        'cause_text',
        'current_control_text',
        'additional_control_text',
        'likelihood_before',
        'severity_before',
        'likelihood_current',
        'severity_current',
        'likelihood_after',
        'severity_after',
        'improvement_due_date',
        'remark',
        'use_yn',
        'delete_yn',
    ];

    $count = 0;
    foreach ($keys as $key) {
        if (isset($source[$key]) && is_array($source[$key])) {
            $count = max($count, count($source[$key]));
        }
    }

    return $count;
}

function build_posted_items(array $source): array
{
    $items = [];
    $rowCount = post_row_count($source);

    for ($index = 0; $index < $rowCount; $index++) {
        $likelihoodBefore = nullable_int(post_array_value($source, 'likelihood_before', $index), 1, 5);
        $severityBefore = nullable_int(post_array_value($source, 'severity_before', $index), 1, 5);
        $likelihoodCurrent = nullable_int(post_array_value($source, 'likelihood_current', $index), 1, 5);
        $severityCurrent = $severityBefore;
        $likelihoodAfter = nullable_int(post_array_value($source, 'likelihood_after', $index), 1, 5);
        $severityAfter = nullable_int(post_array_value($source, 'severity_after', $index), 1, 5);

        $items[] = blank_item([
            'item_id' => (string)(post_array_value($source, 'item_id', $index) ?? ''),
            'sort_no' => (string)(post_array_value($source, 'sort_no', $index) ?? ''),
            'task_code' => (string)(post_array_value($source, 'task_code', $index) ?? ''),
            'task_name' => (string)(post_array_value($source, 'task_name', $index) ?? ''),
            'hazard_name' => (string)(post_array_value($source, 'hazard_name', $index) ?? ''),
            'hazard_4m' => (string)(hazard_4m_normalize_manual(post_array_value($source, 'hazard_4m', $index)) ?? ''),
            'accident_type' => (string)(post_array_value($source, 'accident_type', $index) ?? ''),
            'injury_result' => (string)(post_array_value($source, 'injury_result', $index) ?? ''),
            'cause_text' => (string)(post_array_value($source, 'cause_text', $index) ?? ''),
            'current_control_text' => (string)(post_array_value($source, 'current_control_text', $index) ?? ''),
            'additional_control_text' => (string)(post_array_value($source, 'additional_control_text', $index) ?? ''),
            'likelihood_before' => $likelihoodBefore === null ? '' : (string)$likelihoodBefore,
            'severity_before' => $severityBefore === null ? '' : (string)$severityBefore,
            'risk_score_before' => score_from_pair($likelihoodBefore, $severityBefore) ?? '',
            'likelihood_current' => $likelihoodCurrent === null ? '' : (string)$likelihoodCurrent,
            'severity_current' => $severityCurrent === null ? '' : (string)$severityCurrent,
            'risk_score_current' => score_from_pair($likelihoodCurrent, $severityCurrent) ?? '',
            'likelihood_after' => $likelihoodAfter === null ? '' : (string)$likelihoodAfter,
            'severity_after' => $severityAfter === null ? '' : (string)$severityAfter,
            'risk_score_after' => score_from_pair($likelihoodAfter, $severityAfter) ?? '',
            'improvement_due_date' => (string)(post_array_value($source, 'improvement_due_date', $index) ?? ''),
            'remark' => (string)(post_array_value($source, 'remark', $index) ?? ''),
            'use_yn' => ((string)(post_array_value($source, 'use_yn', $index) ?? 'Y')) === 'N' ? 'N' : 'Y',
            'delete_yn' => ((string)(post_array_value($source, 'delete_yn', $index) ?? '0')) === '1' ? '1' : '0',
        ]);
    }

    return $items;
}

$unitRaId = isset($_GET['unit_ra_id'])
    ? (int)$_GET['unit_ra_id']
    : (int)($_POST['unit_ra_id'] ?? 0);
$user = auth_require_login();
$returnTo = normalize_return_to($_GET['return_to'] ?? ($_POST['return_to'] ?? 'list.html'));
$errorMessage = '';
$successMessage = '';
$header = null;
$items = [];
$historyRows = [];
$renderPostedItems = false;

if ($unitRaId <= 0) {
    $errorMessage = '편집할 평가서를 찾을 수 없습니다.';
} else {
    $pdo = getDB();
  ensure_history_table($pdo);
  ensure_unit_ra_item_hazard_4m_column($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_items') {
        $postedItems = build_posted_items($_POST);
        $itemIds = $_POST['item_id'] ?? [];
        if (!is_array($itemIds) || empty($postedItems)) {
            $errorMessage = '저장할 항목이 없습니다.';
        } else {
            try {
                $pdo->beginTransaction();

            $existingItemStmt = $pdo->prepare("SELECT * FROM unit_ra_item WHERE unit_ra_id = :unit_ra_id");
            $existingItemStmt->execute([':unit_ra_id' => $unitRaId]);
            $existingItems = [];
            foreach ($existingItemStmt->fetchAll() ?: [] as $existingRow) {
              $existingItems[(int)$existingRow['item_id']] = item_history_snapshot($existingRow);
            }

                $updateStmt = $pdo->prepare("
                    UPDATE unit_ra_item
                    SET
                        sort_no = :sort_no,
                        task_code = :task_code,
                        task_name = :task_name,
                        hazard_name = :hazard_name,
                        hazard_4m = :hazard_4m,
                        accident_type = :accident_type,
                        injury_result = :injury_result,
                        cause_text = :cause_text,
                        current_control_text = :current_control_text,
                        additional_control_text = :additional_control_text,
                        likelihood_before = :likelihood_before,
                        severity_before = :severity_before,
                        risk_score_before = :risk_score_before,
                        likelihood_current = :likelihood_current,
                        severity_current = :severity_current,
                        risk_score_current = :risk_score_current,
                        likelihood_after = :likelihood_after,
                        severity_after = :severity_after,
                        risk_score_after = :risk_score_after,
                        improvement_due_date = :improvement_due_date,
                        remark = :remark,
                        use_yn = :use_yn,
                        updated_at = NOW()
                    WHERE item_id = :item_id
                      AND unit_ra_id = :unit_ra_id
                ");

                $insertStmt = $pdo->prepare("
                    INSERT INTO unit_ra_item
                        (
                            unit_ra_id,
                            sort_no,
                            task_code,
                            task_name,
                            hazard_name,
                            hazard_4m,
                            accident_type,
                            injury_result,
                            cause_text,
                            current_control_text,
                            additional_control_text,
                            likelihood_before,
                            severity_before,
                            risk_score_before,
                            likelihood_current,
                            severity_current,
                            risk_score_current,
                            likelihood_after,
                            severity_after,
                            risk_score_after,
                            improvement_due_date,
                            use_yn,
                            remark,
                            created_at,
                            updated_at
                        )
                    VALUES
                        (
                            :unit_ra_id,
                            :sort_no,
                            :task_code,
                            :task_name,
                            :hazard_name,
                            :hazard_4m,
                            :accident_type,
                            :injury_result,
                            :cause_text,
                            :current_control_text,
                            :additional_control_text,
                            :likelihood_before,
                            :severity_before,
                            :risk_score_before,
                            :likelihood_current,
                            :severity_current,
                            :risk_score_current,
                            :likelihood_after,
                            :severity_after,
                            :risk_score_after,
                            :improvement_due_date,
                            :use_yn,
                            :remark,
                            NOW(),
                            NOW()
                        )
                ");

                      $deleteStmt = $pdo->prepare("DELETE FROM unit_ra_item WHERE item_id = :item_id AND unit_ra_id = :unit_ra_id");

                $updatedCount = 0;
                $insertedCount = 0;
                      $deletedCount = 0;
        $historyCount = 0;

                foreach ($itemIds as $index => $rawItemId) {
                    $itemId = (int)$rawItemId;
                    $likelihoodBefore = nullable_int($_POST['likelihood_before'][$index] ?? null, 1, 5);
                    $severityBefore = nullable_int($_POST['severity_before'][$index] ?? null, 1, 5);
                    $likelihoodCurrent = nullable_int($_POST['likelihood_current'][$index] ?? null, 1, 5);
                    $severityCurrent = $severityBefore;
                    $likelihoodAfter = nullable_int($_POST['likelihood_after'][$index] ?? null, 1, 5);
                    $severityAfter = nullable_int($_POST['severity_after'][$index] ?? null, 1, 5);

                    $taskCode = nullable_text($_POST['task_code'][$index] ?? null);
                    $taskName = trim((string)($_POST['task_name'][$index] ?? ''));
                    $hazardName = trim((string)($_POST['hazard_name'][$index] ?? ''));
                    $hazard4m = hazard_4m_normalize_manual($_POST['hazard_4m'][$index] ?? null);
                    $accidentType = nullable_text($_POST['accident_type'][$index] ?? null);
                    $injuryResult = nullable_text($_POST['injury_result'][$index] ?? null);
                    $causeText = nullable_text($_POST['cause_text'][$index] ?? null);
                    $currentControlText = nullable_text($_POST['current_control_text'][$index] ?? null);
                    $additionalControlText = nullable_text($_POST['additional_control_text'][$index] ?? null);
                    $improvementDueDate = nullable_text($_POST['improvement_due_date'][$index] ?? null);
                    $remark = nullable_text($_POST['remark'][$index] ?? null);
                    $sortNo = nullable_int($_POST['sort_no'][$index] ?? null) ?? ($index + 1);
                    $useYn = (($_POST['use_yn'][$index] ?? 'Y') === 'N') ? 'N' : 'Y';
                    $deleteYn = (string)(post_array_value($_POST, 'delete_yn', $index) ?? '0') === '1';

                    $hasContent = $taskCode !== null
                        || $taskName !== ''
                        || $hazardName !== ''
                        || $hazard4m !== null
                        || $accidentType !== null
                        || $injuryResult !== null
                        || $causeText !== null
                        || $currentControlText !== null
                        || $additionalControlText !== null
                        || $improvementDueDate !== null
                        || $remark !== null
                        || $likelihoodBefore !== null
                        || $severityBefore !== null
                        || $likelihoodCurrent !== null
                        || $severityCurrent !== null
                        || $likelihoodAfter !== null
                        || $severityAfter !== null;

                      if ($itemId > 0 && $deleteYn) {
                        $beforeSnapshot = $existingItems[$itemId] ?? [];
                        if (!empty($beforeSnapshot)) {
                          save_item_history($pdo, $unitRaId, $itemId, 'delete', $beforeSnapshot, [], array_keys($beforeSnapshot), $user);
                          $historyCount++;
                          $deleteStmt->execute([
                            ':item_id' => $itemId,
                            ':unit_ra_id' => $unitRaId,
                          ]);
                          $deletedCount++;
                        }
                        continue;
                      }

                    if (!$hasContent) {
                        continue;
                    }

                    if ($taskName === '' || $hazardName === '') {
                        throw new RuntimeException('작업명과 주요 위험요소를 모두 입력해야 합니다.');
                    }

                    $params = [
                        ':sort_no' => $sortNo,
                        ':task_code' => $taskCode,
                        ':task_name' => $taskName,
                        ':hazard_name' => $hazardName,
                        ':hazard_4m' => $hazard4m,
                        ':accident_type' => $accidentType,
                        ':injury_result' => $injuryResult,
                        ':cause_text' => $causeText,
                        ':current_control_text' => $currentControlText,
                        ':additional_control_text' => $additionalControlText,
                        ':likelihood_before' => $likelihoodBefore,
                        ':severity_before' => $severityBefore,
                        ':risk_score_before' => score_from_pair($likelihoodBefore, $severityBefore),
                        ':likelihood_current' => $likelihoodCurrent,
                        ':severity_current' => $severityCurrent,
                        ':risk_score_current' => score_from_pair($likelihoodCurrent, $severityCurrent),
                        ':likelihood_after' => $likelihoodAfter,
                        ':severity_after' => $severityAfter,
                        ':risk_score_after' => score_from_pair($likelihoodAfter, $severityAfter),
                        ':improvement_due_date' => $improvementDueDate,
                        ':remark' => $remark,
                        ':use_yn' => $useYn,
                        ':unit_ra_id' => $unitRaId,
                    ];

                      $afterSnapshot = item_history_snapshot([
                        'sort_no' => $sortNo,
                        'task_code' => $taskCode,
                        'task_name' => $taskName,
                        'hazard_name' => $hazardName,
                        'hazard_4m' => $hazard4m,
                        'accident_type' => $accidentType,
                        'injury_result' => $injuryResult,
                        'cause_text' => $causeText,
                        'current_control_text' => $currentControlText,
                        'additional_control_text' => $additionalControlText,
                        'likelihood_before' => $likelihoodBefore,
                        'severity_before' => $severityBefore,
                        'risk_score_before' => score_from_pair($likelihoodBefore, $severityBefore),
                        'likelihood_current' => $likelihoodCurrent,
                        'severity_current' => $severityCurrent,
                        'risk_score_current' => score_from_pair($likelihoodCurrent, $severityCurrent),
                        'likelihood_after' => $likelihoodAfter,
                        'severity_after' => $severityAfter,
                        'risk_score_after' => score_from_pair($likelihoodAfter, $severityAfter),
                        'improvement_due_date' => $improvementDueDate,
                        'remark' => $remark,
                        'use_yn' => $useYn,
                      ]);

                    if ($itemId > 0) {
                        $beforeSnapshot = $existingItems[$itemId] ?? [];
                        $changedFields = build_history_diff($beforeSnapshot, $afterSnapshot);
                        if (empty($changedFields)) {
                          continue;
                        }

                        $params[':item_id'] = $itemId;
                        $updateStmt->execute($params);
                        $updatedCount++;
                        save_item_history($pdo, $unitRaId, $itemId, 'update', $beforeSnapshot, $afterSnapshot, $changedFields, $user);
                        $historyCount++;
                    } else {
                        $insertStmt->execute($params);
                        $newItemId = (int)$pdo->lastInsertId();
                        $insertedCount++;
                        save_item_history($pdo, $unitRaId, $newItemId > 0 ? $newItemId : null, 'insert', [], $afterSnapshot, array_keys($afterSnapshot), $user);
                        $historyCount++;
                    }
                }

                if ($updatedCount === 0 && $insertedCount === 0 && $deletedCount === 0) {
                    throw new RuntimeException('내용이 입력된 행이 없어 저장할 수 없습니다.');
                }

                $pdo->commit();
            $successMessage = sprintf('항목이 저장되었습니다. 수정 %d건, 신규 %d건, 삭제 %d건, 이력 %d건입니다.', $updatedCount, $insertedCount, $deletedCount, $historyCount);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $items = $postedItems;
                if (empty($items)) {
                    $items = [blank_item(['sort_no' => '1'])];
                }
                $renderPostedItems = true;
                $errorMessage = '저장 중 오류가 발생했습니다: ' . $e->getMessage();
            }
        }
    }

    $headerStmt = $pdo->prepare("
        SELECT
            unit_ra_id,
            unit_type,
            unit_title,
            unit_code,
            process_name,
            created_by,
            evaluator_name,
            created_at,
            updated_at
        FROM unit_ra_header
        WHERE unit_ra_id = :unit_ra_id
        LIMIT 1
    ");
    $headerStmt->execute([':unit_ra_id' => $unitRaId]);
    $header = $headerStmt->fetch();

    if (!$header) {
        $errorMessage = '평가서 정보를 찾을 수 없습니다.';
    } elseif (!$renderPostedItems) {
        $itemsStmt = $pdo->prepare("
            SELECT
                item_id,
                sort_no,
                task_code,
                task_name,
                hazard_name,
                hazard_4m,
                accident_type,
                injury_result,
                cause_text,
                current_control_text,
                additional_control_text,
                likelihood_before,
                severity_before,
                risk_score_before,
                likelihood_current,
                severity_current,
                risk_score_current,
                likelihood_after,
                severity_after,
                risk_score_after,
                improvement_due_date,
                remark,
                use_yn
            FROM unit_ra_item
            WHERE unit_ra_id = :unit_ra_id
            ORDER BY sort_no ASC, item_id ASC
        ");
        $itemsStmt->execute([':unit_ra_id' => $unitRaId]);
        $items = $itemsStmt->fetchAll() ?: [];
    }

    if ($header && empty($items)) {
        $items = [blank_item(['sort_no' => '1'])];
    }

    if ($header) {
      $historyStmt = $pdo->prepare("SELECT history_id, item_id, source_item_id, action_type, changed_fields, before_data, after_data, changed_by_name, changed_at
        FROM unit_ra_item_history
        WHERE unit_ra_id = :unit_ra_id
        ORDER BY changed_at DESC, history_id DESC
        LIMIT 20");
      $historyStmt->execute([':unit_ra_id' => $unitRaId]);
      $historyRows = $historyStmt->fetchAll() ?: [];
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>평가 항목 편집</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background: #f3f6fb;
    color: #243447;
    padding: 28px 18px 42px;
  }
  .shell { max-width: 1700px; margin: 0 auto; }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
  }
  .topbar h1 {
    font-size: 24px;
    color: #1f4e79;
    margin-bottom: 6px;
  }
  .topbar p {
    color: #5b6b7a;
    font-size: 13px;
    line-height: 1.6;
  }
  .actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 10px 16px;
    border-radius: 8px;
    border: 1px solid #c6d6e6;
    background: #fff;
    color: #2d4b66;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
  }
  .btn:hover {
    background: #eef5fc;
    border-color: #93b7d9;
  }
  .btn-primary {
    background: #2e75b6;
    color: #fff;
    border-color: #2e75b6;
  }
  .btn-primary:hover {
    background: #1f4e79;
    border-color: #1f4e79;
  }
  .panel {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(26, 52, 86, 0.08);
    overflow: hidden;
  }
  .panel-head {
    padding: 18px 22px;
    background: #2e75b6;
    color: #fff;
  }
  .panel-head h2 {
    font-size: 16px;
    margin-bottom: 6px;
  }
  .panel-head p {
    font-size: 13px;
    opacity: 0.92;
  }
  .meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    padding: 20px 22px 0;
  }
  .meta-box {
    border: 1px solid #d8e4f0;
    border-radius: 10px;
    background: #f8fbff;
    padding: 12px 14px;
  }
  .meta-box strong {
    display: block;
    color: #55708d;
    font-size: 11px;
    margin-bottom: 5px;
  }
  .meta-box span {
    color: #1f3550;
    font-weight: 700;
    font-size: 14px;
    line-height: 1.5;
  }
  .message,
  .error {
    margin: 16px 22px 0;
    padding: 13px 15px;
    border-radius: 10px;
    font-size: 13px;
    line-height: 1.6;
  }
  .message {
    background: #e8f6ec;
    border: 1px solid #b9e1c3;
    color: #1d6b34;
  }
  .error {
    background: #fdecea;
    border: 1px solid #f0b9b3;
    color: #a33a2a;
  }
  .table-wrap {
    padding: 20px 22px 22px;
    overflow-x: auto;
  }
  table {
    width: 100%;
    min-width: 2200px;
    border-collapse: collapse;
  }
  th, td {
    border: 1px solid #dbe5ef;
    padding: 8px;
    vertical-align: top;
    background: #fff;
  }
  th {
    background: #edf4fb;
    color: #37526c;
    font-size: 12px;
    white-space: nowrap;
  }
  td {
    font-size: 12px;
  }
  .col-sort {
    width: 72px;
    min-width: 72px;
  }
  .col-wide-text {
    width: 220px;
    min-width: 220px;
  }
  .col-sort input[type="number"] {
    padding-left: 6px;
    padding-right: 6px;
  }
  input[type="text"],
  input[type="date"],
  input[type="number"],
  select,
  textarea {
    width: 100%;
    padding: 7px 8px;
    border: 1px solid #c9d7e5;
    border-radius: 6px;
    font-size: 12px;
    font-family: inherit;
    outline: none;
    background: #fff;
  }
  textarea {
    min-height: 76px;
    resize: vertical;
  }
  input:focus,
  select:focus,
  textarea:focus {
    border-color: #4e88c4;
    box-shadow: 0 0 0 3px rgba(78, 136, 196, 0.14);
  }
  .score-box {
    display: inline-flex;
    min-width: 36px;
    justify-content: center;
    align-items: center;
    padding: 7px 8px;
    border-radius: 6px;
    background: #f3f7fb;
    border: 1px solid #d9e3ee;
    font-weight: 700;
    color: #2e5578;
  }
  .empty {
    padding: 40px 20px;
    text-align: center;
    color: #6a7d90;
  }
  .save-bar {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 0 22px 22px;
    flex-wrap: wrap;
  }
  .history-panel {
    margin: 0 22px 22px;
    border: 1px solid #d8e4f0;
    border-radius: 12px;
    background: #f8fbff;
    overflow: hidden;
  }
  .history-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    background: #edf4fb;
    color: #294661;
  }
  .history-head h3 {
    font-size: 14px;
  }
  .history-list {
    display: grid;
    gap: 10px;
    padding: 14px 16px 16px;
  }
  .history-item {
    border: 1px solid #dbe5ef;
    border-radius: 10px;
    background: #fff;
    padding: 12px 14px;
  }
  .history-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 8px;
    font-size: 12px;
    color: #55708d;
  }
  .history-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 999px;
    background: #e0edf9;
    color: #1f4e79;
    font-weight: 700;
  }
  .history-badge.is-insert {
    background: #e7f6ec;
    color: #19703a;
  }
  .history-badge.is-update {
    background: #e0edf9;
    color: #1f4e79;
  }
  .history-badge.is-delete {
    background: #fdecea;
    color: #a33a2a;
  }
  .history-fields {
    font-size: 12px;
    color: #2d4b66;
    line-height: 1.6;
  }
  .history-fields ul {
    margin: 6px 0 0;
    padding-left: 18px;
  }
  .history-fields li + li {
    margin-top: 4px;
  }
  .history-empty {
    padding: 16px;
    color: #6a7d90;
    font-size: 13px;
  }
  @media (max-width: 768px) {
    body { padding: 18px 10px 28px; }
    .panel-head,
    .table-wrap,
    .save-bar,
    .meta-grid { padding-left: 14px; padding-right: 14px; }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div>
        <h1>평가 항목 편집</h1>
        <p>선택한 평가서의 `unit_ra_item` 레코드를 직접 수정하고 새 항목도 추가할 수 있습니다.</p>
      </div>
      <div class="actions">
        <a class="btn" href="list.html">목록으로</a>
        <?php if ($unitRaId > 0): ?>
          <a class="btn" href="form.html?unit_ra_id=<?= (int)$unitRaId ?>">기본정보 편집</a>
          <a class="btn" href="unit_ra_item_history_print.php?unit_ra_id=<?= (int)$unitRaId ?>&return_to=<?= urlencode($returnTo) ?>" target="_blank" rel="noopener">이력 보고서 출력</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>평가 항목 관리</h2>
        <p>작업명, 주요 위험요소, 조치사항, 빈도와 강도를 수정하면 점수는 자동으로 계산됩니다.</p>
      </div>

      <?php if ($header): ?>
        <div class="meta-grid">
          <div class="meta-box">
            <strong>평가서명</strong>
            <span><?= h($header['unit_title']) ?></span>
          </div>
          <div class="meta-box">
            <strong>평가서 코드</strong>
            <span><?= h($header['unit_code'] ?: '-') ?></span>
          </div>
          <div class="meta-box">
            <strong>유형</strong>
            <span><?= h($header['unit_type']) ?></span>
          </div>
          <div class="meta-box">
            <strong>공정명</strong>
            <span><?= h($header['process_name'] ?: '-') ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($successMessage !== ''): ?>
        <div class="message"><?= h($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($errorMessage !== ''): ?>
        <div class="error"><?= h($errorMessage) ?></div>
      <?php endif; ?>

      <?php if ($header): ?>
        <?php $fieldLabels = history_field_labels(); ?>
        <?php $hazard4mOptions = hazard_4m_manual_options(); ?>
        <div class="history-panel">
          <div class="history-head">
            <h3>최근 수정 이력</h3>
            <span>최근 20건</span>
          </div>
          <?php if (!empty($historyRows)): ?>
            <div class="history-list">
              <?php foreach ($historyRows as $history): ?>
                <?php
                  $changedFields = decode_history_json($history['changed_fields'] ?? null);
                  $changedLabels = [];
                  $actionType = (string)($history['action_type'] ?? 'update');
                  $actionLabel = history_action_label($actionType);
                  $historyItemId = (string)($history['source_item_id'] ?? $history['item_id'] ?? '-');
                  $summaryLines = build_history_summary_lines($history, $fieldLabels);
                  foreach ($changedFields as $fieldName) {
                      $changedLabels[] = $fieldLabels[$fieldName] ?? $fieldName;
                  }
                ?>
                <div class="history-item">
                  <div class="history-meta">
                    <span class="history-badge <?= $actionType === 'insert' ? 'is-insert' : ($actionType === 'delete' ? 'is-delete' : 'is-update') ?>"><?= h($actionLabel) ?></span>
                    <span>항목 ID <?= h($historyItemId) ?></span>
                    <span>작성자 <?= h((string)($history['changed_by_name'] ?? '알 수 없음')) ?></span>
                    <span><?= h((string)$history['changed_at']) ?></span>
                  </div>
                  <div class="history-fields">
                    <?= !empty($changedLabels) ? '변경 항목: ' . h(implode(', ', $changedLabels)) : '변경 항목 정보 없음' ?>
                    <?php if (!empty($summaryLines)): ?>
                      <ul>
                        <?php foreach ($summaryLines as $summaryLine): ?>
                          <li><?= h($summaryLine) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="history-empty">아직 저장된 수정 이력이 없습니다.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($header): ?>
        <form method="post">
          <input type="hidden" name="action" value="save_items">
          <input type="hidden" name="unit_ra_id" value="<?= (int)$unitRaId ?>">
          <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
          <div class="table-wrap">
            <p style="margin-bottom:12px; color:#48627b; font-size:13px;">정렬순서 오른쪽 칸에 안전작업표준서 번호를 입력할 수 있습니다.</p>
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th class="col-sort">정렬</th>
                  <th>작업코드</th>
                  <th>작업명</th>
                  <th>주요 위험요소</th>
                  <th>사고유형</th>
                  <th>상해결과</th>
                  <th class="col-wide-text">원인</th>
                  <th>개선 전 L</th>
                  <th>개선 전 S</th>
                  <th>개선 전 점수</th>
                  <th class="col-wide-text">현재 조치사항</th>
                  <th>현재 L</th>
                  <th>현재 S</th>
                  <th>현재 점수</th>
                  <th>추가 조치사항</th>
                  <th>개선 후 L</th>
                  <th>개선 후 S</th>
                  <th>개선 후 점수</th>
                  <th>개선기한</th>
                  <th>비고</th>
                  <th>사용</th>
                  <th>삭제</th>
                </tr>
              </thead>
              <tbody id="items-body">
                <?php foreach ($items as $index => $item): ?>
                  <tr>
                    <td>
                      <?= (int)($item['item_id'] ?? 0) > 0 ? (int)$item['item_id'] : '신규' ?>
                      <input type="hidden" name="item_id[]" value="<?= h((string)($item['item_id'] ?? '')) ?>">
                    </td>
                    <td class="col-sort"><input type="number" name="sort_no[]" value="<?= h($item['sort_no'] ?? '') ?>" placeholder="정렬순서"></td>
                    <td><input type="text" name="task_code[]" value="<?= h($item['task_code'] ?? '') ?>" placeholder="안전작업표준서 번호"></td>
                    <td><input type="text" name="task_name[]" value="<?= h($item['task_name'] ?? '') ?>"></td>
                    <td><textarea name="hazard_name[]"><?= h($item['hazard_name'] ?? '') ?></textarea></td>
                    <td>
                      <select name="hazard_4m[]">
                        <?php foreach ($hazard4mOptions as $optionValue => $optionLabel): ?>
                          <option value="<?= h($optionValue) ?>" <?= (($item['hazard_4m'] ?? '') === $optionValue) ? 'selected' : '' ?>><?= h($optionLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="text" name="accident_type[]" value="<?= h($item['accident_type'] ?? '') ?>"></td>
                    <td><input type="text" name="injury_result[]" value="<?= h($item['injury_result'] ?? '') ?>"></td>
                    <td class="col-wide-text"><textarea name="cause_text[]"><?= h($item['cause_text'] ?? '') ?></textarea></td>
                    <td><input type="number" min="1" max="5" name="likelihood_before[]" value="<?= h($item['likelihood_before'] ?? '') ?>" data-score-group="before" data-row-index="<?= $index ?>"></td>
                    <td><input type="number" min="1" max="5" name="severity_before[]" value="<?= h($item['severity_before'] ?? '') ?>" data-score-group="before" data-row-index="<?= $index ?>"></td>
                    <td><span class="score-box" id="score-before-<?= $index ?>"><?= h($item['risk_score_before'] ?? '-') ?></span></td>
                    <td class="col-wide-text"><textarea name="current_control_text[]"><?= h($item['current_control_text'] ?? '') ?></textarea></td>
                    <td><input type="number" min="1" max="5" name="likelihood_current[]" value="<?= h($item['likelihood_current'] ?? '') ?>" data-score-group="current" data-row-index="<?= $index ?>"></td>
                    <td><input type="number" min="1" max="5" name="severity_current[]" value="<?= h($item['severity_before'] ?? $item['severity_current'] ?? '') ?>" data-score-group="current" data-row-index="<?= $index ?>" readonly></td>
                    <td><span class="score-box" id="score-current-<?= $index ?>"><?= h($item['risk_score_current'] ?? '-') ?></span></td>
                    <td><textarea name="additional_control_text[]"><?= h($item['additional_control_text'] ?? '') ?></textarea></td>
                    <td><input type="number" min="1" max="5" name="likelihood_after[]" value="<?= h($item['likelihood_after'] ?? '') ?>" data-score-group="after" data-row-index="<?= $index ?>"></td>
                    <td><input type="number" min="1" max="5" name="severity_after[]" value="<?= h($item['severity_after'] ?? '') ?>" data-score-group="after" data-row-index="<?= $index ?>"></td>
                    <td><span class="score-box" id="score-after-<?= $index ?>"><?= h($item['risk_score_after'] ?? '-') ?></span></td>
                    <td><input type="date" name="improvement_due_date[]" value="<?= h($item['improvement_due_date'] ?? '') ?>"></td>
                    <td><textarea name="remark[]"><?= h($item['remark'] ?? '') ?></textarea></td>
                    <td>
                      <select name="use_yn[]">
                        <option value="Y" <?= ($item['use_yn'] ?? 'Y') === 'Y' ? 'selected' : '' ?>>Y</option>
                        <option value="N" <?= ($item['use_yn'] ?? 'Y') === 'N' ? 'selected' : '' ?>>N</option>
                      </select>
                    </td>
                    <td style="text-align:center; vertical-align:middle;">
                      <input type="checkbox" name="delete_yn[<?= $index ?>]" value="1" <?= ($item['delete_yn'] ?? '0') === '1' ? 'checked' : '' ?> aria-label="행 삭제">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="save-bar">
            <button type="button" class="btn" id="add-row-button">행 추가</button>
            <button type="button" class="btn" onclick="window.location.reload()">다시 불러오기</button>
            <button type="submit" class="btn btn-primary">항목 저장</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>





  <script>
    const returnTo = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const currentUnitRaId = <?= (int)$unitRaId ?>;
    const itemsBody = document.getElementById('items-body');
    const addRowButton = document.getElementById('add-row-button');
    let nextRowIndex = <?= (int)count($items) ?>;
    const hazard4mOptions = <?= json_encode(hazard_4m_manual_options(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const actionButtons = document.querySelectorAll('.actions .btn');
    if (actionButtons[0] && returnTo) {
      actionButtons[0].href = returnTo;
    }
    if (actionButtons[1] && currentUnitRaId > 0) {
      const backParam = returnTo ? `&return_to=${encodeURIComponent(returnTo)}` : '';
      actionButtons[1].href = `form.html?unit_ra_id=${currentUnitRaId}${backParam}`;
    }

    function updateScore(group, rowIndex) {
      const likelihoodInput = document.querySelector(`input[name="likelihood_${group}[]"][data-row-index="${rowIndex}"]`);
      const severityInput = document.querySelector(`input[name="severity_${group}[]"][data-row-index="${rowIndex}"]`);
      const scoreBox = document.getElementById(`score-${group}-${rowIndex}`);
      if (!likelihoodInput || !severityInput || !scoreBox) {
        return;
      }

      const likelihood = Number.parseInt(likelihoodInput.value || '', 10);
      const severity = Number.parseInt(severityInput.value || '', 10);
      scoreBox.textContent = Number.isInteger(likelihood) && Number.isInteger(severity)
        ? String(likelihood * severity)
        : '-';
    }

    function syncCurrentSeverity(rowIndex) {
      const beforeSeverityInput = document.querySelector(`input[name="severity_before[]"][data-row-index="${rowIndex}"]`);
      const currentSeverityInput = document.querySelector(`input[name="severity_current[]"][data-row-index="${rowIndex}"]`);
      if (!beforeSeverityInput || !currentSeverityInput) {
        return;
      }

      currentSeverityInput.value = beforeSeverityInput.value;
      updateScore('current', rowIndex);
    }

    function buildRowHtml(rowIndex, sortNo) {
      const hazard4mSelect = Object.entries(hazard4mOptions).map(([value, label]) =>
        `<option value="${String(value).replace(/"/g, '&quot;')}">${String(label).replace(/</g, '&lt;')}</option>`
      ).join('');

      return `
        <tr>
          <td>
            신규
            <input type="hidden" name="item_id[]" value="">
          </td>
          <td class="col-sort"><input type="number" name="sort_no[]" value="${sortNo}" placeholder="정렬순서"></td>
          <td><input type="text" name="task_code[]" value="" placeholder="안전작업표준서 번호"></td>
          <td><input type="text" name="task_name[]" value=""></td>
          <td><textarea name="hazard_name[]"></textarea></td>
          <td><select name="hazard_4m[]">${hazard4mSelect}</select></td>
          <td><input type="text" name="accident_type[]" value=""></td>
          <td><input type="text" name="injury_result[]" value=""></td>
          <td class="col-wide-text"><textarea name="cause_text[]"></textarea></td>
          <td><input type="number" min="1" max="5" name="likelihood_before[]" value="" data-score-group="before" data-row-index="${rowIndex}"></td>
          <td><input type="number" min="1" max="5" name="severity_before[]" value="" data-score-group="before" data-row-index="${rowIndex}"></td>
          <td><span class="score-box" id="score-before-${rowIndex}">-</span></td>
          <td class="col-wide-text"><textarea name="current_control_text[]"></textarea></td>
          <td><input type="number" min="1" max="5" name="likelihood_current[]" value="" data-score-group="current" data-row-index="${rowIndex}"></td>
          <td><input type="number" min="1" max="5" name="severity_current[]" value="" data-score-group="current" data-row-index="${rowIndex}" readonly></td>
          <td><span class="score-box" id="score-current-${rowIndex}">-</span></td>
          <td><textarea name="additional_control_text[]"></textarea></td>
          <td><input type="number" min="1" max="5" name="likelihood_after[]" value="" data-score-group="after" data-row-index="${rowIndex}"></td>
          <td><input type="number" min="1" max="5" name="severity_after[]" value="" data-score-group="after" data-row-index="${rowIndex}"></td>
          <td><span class="score-box" id="score-after-${rowIndex}">-</span></td>
          <td><input type="date" name="improvement_due_date[]" value=""></td>
          <td><textarea name="remark[]"></textarea></td>
          <td>
            <select name="use_yn[]">
              <option value="Y" selected>Y</option>
              <option value="N">N</option>
            </select>
          </td>
          <td style="text-align:center; vertical-align:middle;"><input type="checkbox" name="delete_yn[${rowIndex}]" value="1" aria-label="행 삭제"></td>
        </tr>
      `;
    }

    if (itemsBody) {
      const headerRow = document.querySelector('table thead tr');
      if (headerRow) {
        const headerCells = Array.from(headerRow.querySelectorAll('th'));
        const hasHazard4mHeader = headerCells.some((cell) => cell.textContent.trim() === '4M분류');
        if (!hasHazard4mHeader && headerCells.length >= 6) {
          const th = document.createElement('th');
          th.textContent = '4M분류';
          headerRow.insertBefore(th, headerCells[5] || null);
        }
      }

      itemsBody.addEventListener('input', (event) => {
        const input = event.target.closest('input[data-score-group]');
        if (!input) {
          return;
        }

        if (input.name === 'severity_before[]') {
          syncCurrentSeverity(input.dataset.rowIndex || '');
        }

        updateScore(input.dataset.scoreGroup || '', input.dataset.rowIndex || '');
      });

      itemsBody.querySelectorAll('input[data-score-group]').forEach((input) => {
        if (input.name === 'severity_before[]') {
          syncCurrentSeverity(input.dataset.rowIndex || '');
        }
        updateScore(input.dataset.scoreGroup || '', input.dataset.rowIndex || '');
      });
    }

    if (itemsBody && addRowButton) {
      addRowButton.addEventListener('click', () => {
        const rowIndex = nextRowIndex++;
        const sortNo = itemsBody.querySelectorAll('tr').length + 1;
        itemsBody.insertAdjacentHTML('beforeend', buildRowHtml(rowIndex, sortNo));

        const newRow = itemsBody.lastElementChild;
        const firstField = newRow ? newRow.querySelector('input[name="task_code[]"]') : null;
        if (firstField) {
          firstField.focus();
        }
      });
    }
  </script>
</body>
</html>
