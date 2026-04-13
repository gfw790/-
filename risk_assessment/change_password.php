<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_current_user();
if ($user === null) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => '요청 데이터가 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentPassword = (string)($body['current_password'] ?? '');
$newPassword     = (string)($body['new_password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$loginId         = (string)($user['login_id'] ?? '');

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => '새 비밀번호와 확인이 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

[$ok, $message] = auth_change_password($loginId, $currentPassword, $newPassword);
echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
