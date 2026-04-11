<?php
// ================================================================
// unit_ra_list_api.php
// 단위 위험성평가서 목록 조회 API (list.html에서 fetch로 호출)
// ================================================================

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();

    $rows = $pdo->query("
        SELECT
            h.unit_ra_id,
            h.unit_code,
            h.unit_title,
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
