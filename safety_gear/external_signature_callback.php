<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/common.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

$pdo = sg_get_pdo();
$requestToken = sg_normalize_text($_GET['request'] ?? $_POST['request'] ?? '');
$result = sg_normalize_text($_GET['result'] ?? $_POST['result'] ?? 'success');
$requestRow = sg_fetch_external_signature_request($pdo, $requestToken);

if ($requestRow === null) {
    $_SESSION['my_gear_flash'] = [
        'message' => '서명 요청을 찾지 못했습니다.',
        'is_error' => true,
    ];
    header('Location: /safety_gear/my_gear.php');
    exit;
}

if (sg_normalize_text($requestRow['signer_login_id'] ?? '') !== sg_normalize_text($user['login_id'] ?? '')) {
    $_SESSION['my_gear_flash'] = [
        'message' => '본인 서명 요청만 처리할 수 있습니다.',
        'is_error' => true,
    ];
    header('Location: /safety_gear/my_gear.php');
    exit;
}

try {
    if ($result !== 'success') {
        $stmt = $pdo->prepare("
            UPDATE safety_gear_signature_request
            SET status_label = :status_label,
                updated_at = :updated_at
            WHERE request_token = :request_token
        ");
        $stmt->execute([
            ':status_label' => 'cancelled',
            ':updated_at' => sg_current_timestamp(),
            ':request_token' => $requestToken,
        ]);
        $_SESSION['my_gear_flash'] = [
            'message' => '외부 간편인증 서명이 취소되었습니다.',
            'is_error' => true,
        ];
    } else {
        $pdo->beginTransaction();
        $count = sg_complete_external_signature_request($pdo, $requestRow);
        $pdo->commit();
        $_SESSION['my_gear_flash'] = [
            'message' => '외부 간편인증 서명이 완료되었습니다. 총 ' . $count . '건 반영했습니다.',
            'is_error' => false,
        ];
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['my_gear_flash'] = [
        'message' => '외부 간편인증 서명 처리 중 오류가 발생했습니다: ' . $e->getMessage(),
        'is_error' => true,
    ];
}

header('Location: /safety_gear/my_gear.php');
exit;
