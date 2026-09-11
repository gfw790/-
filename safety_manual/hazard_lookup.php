<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

$user = auth_current_user();
if (!is_array($user) || !auth_can_manage($user) || trim((string)($user['login_id'] ?? '')) !== '5878') {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') { echo json_encode([]); exit; }

try {
    $db = getDB();
    $like = '%' . $query . '%';
    $statement = $db->prepare(
        'SELECT h.unit_code, h.unit_title, h.process_name, i.item_id, i.task_name, i.hazard_name
         FROM unit_ra_item i
         JOIN unit_ra_header h ON h.unit_ra_id = i.unit_ra_id
         WHERE i.use_yn = \'Y\'
           AND (h.unit_code LIKE ? OR h.unit_title LIKE ? OR h.process_name LIKE ? OR i.task_name LIKE ? OR i.hazard_name LIKE ?)
         ORDER BY h.unit_code, i.item_id
         LIMIT 50'
    );
    $statement->execute([$like, $like, $like, $like, $like]);
    $rows = $statement->fetchAll();
    $results = array_map(static function (array $row): array {
        $code = trim((string)$row['unit_code']) !== '' ? $row['unit_code'] : (string)$row['item_id'];
        return [
            'label' => $code . '-' . $row['item_id'],
            'task_name' => (string)$row['task_name'],
            'hazard_name' => (string)$row['hazard_name'],
        ];
    }, $rows);
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
