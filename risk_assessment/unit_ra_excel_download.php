<?php
ob_start();

require_once __DIR__ . '/lib/unit_ra_excel_export.php';

$unitRaId = filter_input(INPUT_GET, 'unit_ra_id', FILTER_VALIDATE_INT);
if (!$unitRaId || $unitRaId <= 0) {
    http_response_code(400);
    exit('unit_ra_id 파라미터가 필요합니다.');
}

try {
    $pdo = getDB();
    [$header, $items] = unit_ra_excel_fetch($pdo, $unitRaId);
    $binary = unit_ra_excel_binary($header, $items);
    $fileName = unit_ra_excel_file_name($header);
} catch (Throwable $e) {
    http_response_code(500);
    exit('엑셀 생성 중 오류가 발생했습니다: ' . $e->getMessage());
}

ob_end_clean();
header('Content-Type: application/vnd.ms-excel.sheet.macroEnabled.12');
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
header('Cache-Control: max-age=0');
echo $binary;
exit;
