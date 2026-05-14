<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$pdo = sg_get_pdo();
$query = sg_normalize_text($_GET['q'] ?? '');
$items = sg_fetch_all_items($pdo, $query);

$filename = 'safety_gear_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'wb');
if ($out === false) {
    exit;
}

fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    '관리키',
    '식별방식',
    '식별값',
    '보호구종류',
    '품명/모델',
    '구매처',
    '구매가격',
    '구매일',
    '상태',
    '지급자',
    '지급팀',
    '지급일시',
    '메모',
    '최종수정일시'
]);

foreach ($items as $item) {
    fputcsv($out, [
        $item['id'] ?? '',
        $item['identifier_type'] ?? '',
        $item['identifier_value'] ?? '',
        $item['gear_type'] ?? '',
        $item['product_name'] ?? '',
        $item['purchase_vendor'] ?? '',
        $item['purchase_price'] ?? '',
        $item['purchased_at'] ?? '',
        $item['status'] ?? '',
        $item['assigned_employee_name'] ?? '',
        $item['assigned_team'] ?? '',
        $item['assigned_at'] ?? '',
        $item['notes'] ?? '',
        $item['updated_at'] ?? '',
    ]);
}

fclose($out);
