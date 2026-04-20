<?php

$uploadRoot = 'A:\\risk_server\\uploads\\safety_log_temp';

$file = $_GET['file'] ?? '';
if (!is_string($file) || $file === '') {
    http_response_code(404);
    exit;
}

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
header('Cache-Control: private, max-age=3600');
readfile($realPath);
exit;