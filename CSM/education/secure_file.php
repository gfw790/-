<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

$user = csm_require_auth();

$type = (string)($_GET['type'] ?? '');
$filename = basename((string)($_GET['file'] ?? ''));

if ($filename === '') {
    http_response_code(400);
    exit('파일명을 지정해 주세요.');
}

$dir = match ($type) {
    'photo' => CSM_UPLOAD_PHOTOS_DIR,
    'scan' => CSM_UPLOAD_SCANS_DIR,
    'signature' => CSM_UPLOAD_SIGS_DIR,
    default => null
};

if ($dir === null || !is_dir($dir)) {
    http_response_code(400);
    exit('올바른 파일 구분(type)을 지정해 주세요.');
}

$filePath = $dir . '/' . $filename;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('요청한 파일을 찾을 수 없습니다.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
$inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
$disposition = $inline ? 'inline' : 'attachment';
header("Content-Disposition: $disposition; filename=\"$filename\"; filename*=UTF-8''" . rawurlencode($filename));

readfile($filePath);
exit;
