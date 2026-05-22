<?php
require_once __DIR__ . '/lib/unit_ra_excel_export.php';

function parse_batch_ids(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $value) {
        $id = (int)trim($value);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

$unitIds = parse_batch_ids((string)($_POST['unit_ra_ids'] ?? $_GET['unit_ra_ids'] ?? ''));
if (empty($unitIds)) {
    http_response_code(400);
    exit('다운로드할 위험성평가를 선택해 주세요.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP 확장자를 사용할 수 없습니다.');
}

$pdo = getDB();
$zipPath = tempnam(sys_get_temp_dir(), 'ra_zip_');
if ($zipPath === false) {
    http_response_code(500);
    exit('임시 ZIP 파일을 만들 수 없습니다.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    @unlink($zipPath);
    http_response_code(500);
    exit('ZIP 파일을 생성할 수 없습니다.');
}
$zipClosed = false;

try {
    foreach ($unitIds as $unitId) {
        [$header, $items] = unit_ra_excel_fetch($pdo, $unitId);
        $folder = unit_ra_excel_category_folder($header);
        $fileName = unit_ra_excel_file_name($header);
        $binary = unit_ra_excel_binary($header, $items);
        $zip->addEmptyDir($folder);
        $zip->addFromString($folder . '/' . $fileName, $binary);
    }

    $zip->close();
    $zipClosed = true;

    $downloadName = '위험성평가_일괄다운로드_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: max-age=0');
    readfile($zipPath);
} finally {
    if (!$zipClosed) {
        $zip->close();
    }
    @unlink($zipPath);
}
exit;
