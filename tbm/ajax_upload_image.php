<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'error' => 'Fatal: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
        ], JSON_UNESCAPED_UNICODE);
    }
});

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/tbm_functions.php';
require_once __DIR__ . '/../risk_assessment/auth.php';

$raUser = auth_current_user();
if ($raUser === null) {
    http_response_code(401);
    echo json_encode(['error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isOperator = auth_is_admin($raUser) || ((string)($raUser['role'] ?? '') === 'safety_manager');
if (!$isOperator) {
    http_response_code(403);
    echo json_encode(['error' => '운영자만 이미지 업로드가 가능합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = trim((string)($_POST['date'] ?? ''));
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    http_response_code(400);
    echo json_encode(['error' => '날짜 형식이 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $imageFile = tbm_store_uploaded_manual_image($_FILES['image_upload'] ?? null, $date);
    if ($imageFile === null) {
        http_response_code(400);
        echo json_encode(['error' => '업로드할 이미지가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'image_file' => $imageFile,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}