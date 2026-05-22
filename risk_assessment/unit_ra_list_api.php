<?php
// ================================================================
// unit_ra_list_api.php
// 단위 위험성평가서 목록 조회 API (list.html에서 fetch로 호출)
// ================================================================

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=utf-8');

function ensure_unit_ra_header_report_title_type(PDO $pdo): void
{
    $columnExists = (int)$pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unit_ra_header'
          AND COLUMN_NAME = 'report_title_type'
    ")->fetchColumn() > 0;

    if (!$columnExists) {
        $pdo->exec("
            ALTER TABLE unit_ra_header
            ADD COLUMN report_title_type VARCHAR(20) NOT NULL DEFAULT 'regular' AFTER unit_title
        ");
    }
}

try {
    $pdo = getDB();
    ensure_unit_ra_header_report_title_type($pdo);

    $rows = $pdo->query("
        SELECT
            h.unit_ra_id,
            h.unit_code,
            h.unit_title,
            h.report_title_type,
            h.unit_type,
            h.process_name,
            h.use_yn,
            h.created_by,
            h.created_at,
            COUNT(i.item_id) AS item_count
        FROM unit_ra_header h
        LEFT JOIN unit_ra_item i
            ON i.unit_ra_id = h.unit_ra_id AND i.use_yn = 'Y'
        WHERE h.use_yn = 'Y'
        GROUP BY h.unit_ra_id
        ORDER BY h.unit_ra_id DESC
    ")->fetchAll();

    echo json_encode([
        'success' => true,
        'data'    => $rows,
    ], JSON_UNESCAPED_UNICODE);

} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'DB 오류: ' . $e->getMessage(),
        'data'    => [],
    ], JSON_UNESCAPED_UNICODE);
}
