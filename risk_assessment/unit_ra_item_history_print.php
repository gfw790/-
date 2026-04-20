<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

$user = auth_require_login();
$unitRaId = isset($_GET['unit_ra_id']) ? (int)$_GET['unit_ra_id'] : 0;
$returnTo = normalize_return_to($_GET['return_to'] ?? 'edit_unit_ra_items.php');
$pdo = getDB();
ensure_history_table($pdo);

$header = null;
$historyRows = [];
$fieldLabels = history_field_labels();

if ($unitRaId > 0) {
    $headerStmt = $pdo->prepare("SELECT unit_ra_id, unit_title, unit_code, unit_type, process_name, created_by, updated_at FROM unit_ra_header WHERE unit_ra_id = :unit_ra_id LIMIT 1");
    $headerStmt->execute([':unit_ra_id' => $unitRaId]);
    $header = $headerStmt->fetch() ?: null;

    $historyStmt = $pdo->prepare("SELECT history_id, item_id, source_item_id, action_type, changed_fields, before_data, after_data, changed_by_name, changed_at
        FROM unit_ra_item_history
        WHERE unit_ra_id = :unit_ra_id
        ORDER BY changed_at DESC, history_id DESC");
    $historyStmt->execute([':unit_ra_id' => $unitRaId]);
    $historyRows = $historyStmt->fetchAll() ?: [];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>단위 위험성평가 수정이력 보고서</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: "Malgun Gothic", sans-serif;
    background: #eef3f8;
    color: #203243;
  }
  .shell {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px 18px 48px;
  }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
  }
  .title-block h1 {
    margin: 0 0 6px;
    font-size: 28px;
    color: #1f4e79;
  }
  .title-block p {
    margin: 0;
    color: #5a6f84;
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
  }
  .btn-primary {
    background: #2e75b6;
    color: #fff;
    border-color: #2e75b6;
  }
  .card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 8px 26px rgba(26, 52, 86, 0.08);
    overflow: hidden;
  }
  .meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    padding: 18px;
    border-bottom: 1px solid #dde8f2;
    background: #f8fbff;
  }
  .meta-box {
    border: 1px solid #d8e4f0;
    border-radius: 10px;
    padding: 12px 14px;
    background: #fff;
  }
  .meta-box strong {
    display: block;
    margin-bottom: 5px;
    color: #55708d;
    font-size: 11px;
  }
  .meta-box span {
    display: block;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.5;
  }
  .table-wrap {
    overflow-x: auto;
    padding: 18px;
  }
  table {
    width: 100%;
    min-width: 1180px;
    border-collapse: collapse;
  }
  th, td {
    border: 1px solid #dbe5ef;
    padding: 10px 8px;
    vertical-align: top;
    font-size: 12px;
    background: #fff;
  }
  th {
    background: #edf4fb;
    color: #37526c;
    white-space: nowrap;
  }
  .badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 11px;
  }
  .badge-insert {
    background: #e7f6ec;
    color: #19703a;
  }
  .badge-update {
    background: #e0edf9;
    color: #1f4e79;
  }
  .badge-delete {
    background: #fdecea;
    color: #a33a2a;
  }
  .diff-text {
    line-height: 1.6;
    color: #31506b;
  }
  .diff-text ul {
    margin: 0;
    padding-left: 18px;
  }
  .diff-text li + li {
    margin-top: 4px;
  }
  .empty-box {
    padding: 36px 18px;
    text-align: center;
    color: #63788d;
  }
  .print-note {
    padding: 0 18px 18px;
    color: #5a6f84;
    font-size: 12px;
  }
  @media print {
    @page { size: A4 landscape; margin: 8mm; }
    body {
      background: #fff;
    }
    .shell {
      max-width: none;
      padding: 0;
    }
    .actions {
      display: none;
    }
    .card {
      box-shadow: none;
      border-radius: 0;
    }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div class="title-block">
        <h1>단위 위험성평가 수정이력 보고서</h1>
        <p>단위 위험성평가 항목의 신규, 수정, 삭제 이력을 인쇄용 표 형식으로 정리한 화면입니다.</p>
      </div>
      <div class="actions">
        <a class="btn" href="<?= h($returnTo) ?>">돌아가기</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">인쇄하기</button>
      </div>
    </div>

    <div class="card">
      <?php if ($header): ?>
        <div class="meta-grid">
          <div class="meta-box">
            <strong>평가서명</strong>
            <span><?= h((string)($header['unit_title'] ?? '')) ?></span>
          </div>
          <div class="meta-box">
            <strong>평가서 코드</strong>
            <span><?= h((string)($header['unit_code'] ?? '-')) ?></span>
          </div>
          <div class="meta-box">
            <strong>유형</strong>
            <span><?= h((string)($header['unit_type'] ?? '-')) ?></span>
          </div>
          <div class="meta-box">
            <strong>공정명</strong>
            <span><?= h((string)($header['process_name'] ?? '-')) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <div class="table-wrap">
        <?php if (!$header): ?>
          <div class="empty-box">출력할 평가서 정보를 찾을 수 없습니다.</div>
        <?php elseif (empty($historyRows)): ?>
          <div class="empty-box">출력할 수정이력이 없습니다.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>구분</th>
                <th>항목 ID</th>
                <th>변경 항목</th>
                <th>변경자</th>
                <th>변경일시</th>
                <th>변경 요약</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historyRows as $index => $history): ?>
                <?php
                  $actionType = (string)($history['action_type'] ?? 'update');
                  $actionLabel = history_action_label($actionType);
                  $badgeClass = $actionType === 'insert' ? 'badge-insert' : ($actionType === 'delete' ? 'badge-delete' : 'badge-update');
                  $historyItemId = (string)($history['source_item_id'] ?? $history['item_id'] ?? '-');
                  $changedFields = decode_history_json($history['changed_fields'] ?? null);
                  $changedLabels = [];
                  $summaryLines = build_history_summary_lines($history, $fieldLabels);
                  foreach ($changedFields as $fieldName) {
                      $changedLabels[] = $fieldLabels[$fieldName] ?? $fieldName;
                  }
                ?>
                <tr>
                  <td><?= h((string)($index + 1)) ?></td>
                  <td><span class="badge <?= h($badgeClass) ?>"><?= h($actionLabel) ?></span></td>
                  <td><?= h($historyItemId) ?></td>
                  <td><?= h(!empty($changedLabels) ? implode(', ', $changedLabels) : '-') ?></td>
                  <td><?= h((string)($history['changed_by_name'] ?? '알 수 없음')) ?></td>
                  <td><?= h((string)($history['changed_at'] ?? '')) ?></td>
                  <td class="diff-text">
                    <?php if (!empty($summaryLines)): ?>
                      <ul>
                        <?php foreach ($summaryLines as $summaryLine): ?>
                          <li><?= h($summaryLine) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php elseif ($actionType === 'insert'): ?>
                      신규 항목이 등록되었습니다.
                    <?php elseif ($actionType === 'delete'): ?>
                      기존 항목이 삭제되었습니다.
                    <?php else: ?>
                      항목 내용이 수정되었습니다.
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="print-note">현재 로그인 사용자: <?= h(auth_display_name($user)) ?></div>
    </div>
  </div>
</body>
</html>