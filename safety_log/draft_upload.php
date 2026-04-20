<?php

require_once __DIR__ . '/upload_validation.php';

header('Content-Type: application/json; charset=utf-8');

function respondJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$file = $_FILES['photo'] ?? null;
if (!is_array($file)) {
    respondJson(['success' => false, 'message' => '업로드할 사진이 없습니다.'], 400);
}

try {
    validateUploadedImage($file);
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('유효한 업로드 파일이 아닙니다.');
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
    $extension = $extension !== '' ? '.' . $extension : '';
    $relativeDir = sprintf('%s/%s', date('Y'), date('m'));
    $uploadRoot = 'A:\\risk_server\\uploads\\safety_log_temp';
    $targetDir = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        throw new RuntimeException('임시업로드 폴더를 생성할 수 없습니다.');
    }

    $fileName = sprintf('draft_%s_%s%s', date('Ymd_His'), random_int(1000, 9999), $extension);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('임시 사진 업로드에 실패했습니다.');
    }

    respondJson([
        'success' => true,
        'temp_path' => $relativeDir . '/' . $fileName,
    ]);
} catch (Throwable $e) {
    respondJson(['success' => false, 'message' => $e->getMessage()], 400);
}