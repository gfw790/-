<?php
// 안전관리자 업무일지 사진을 웹루트 외부에서 서빙합니다.
$uploadRoot = 'A:\\risk_server\\uploads\\safety_log';

// file 파라미터를 받아 상대 경로로 변환합니다.
$file = $_GET['file'] ?? '';
if (!is_string($file) || $file === '') {
    http_response_code(404);
    exit;
}

// 잘못된 경로 제거 및 정리
$file = str_replace(['../', '..\\'], '', $file);
$file = trim($file, '/\\');
$file = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
$path = $uploadRoot . DIRECTORY_SEPARATOR . $file;

$realRoot = realpath($uploadRoot);
$realPath = realpath($path);
if ($realRoot === false || $realPath === false || strpos($realPath, $realRoot) !== 0) {
    http_response_code(404);
    exit;
}

if (!is_file($realPath) || !is_readable($realPath)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($realPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: public, max-age=86400');
readfile($realPath);
exit;
