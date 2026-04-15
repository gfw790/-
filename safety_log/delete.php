<?php
require_once __DIR__ . '/../../risk_server/db_config.php';
require_once __DIR__ . '/log_validation.php';

/**
 * HTML escape helper.
 *
 * @param mixed $value
 * @return string
 */
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$pdo = getDB();
$id = getValidLogId($pdo);

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // 먼저 자식 detail 레코드를 삭제합니다.
    $deleteDetail = $pdo->prepare('DELETE FROM safety_manager_log_detail WHERE log_id = :id');
    $deleteDetail->execute([':id' => $id]);

    $stmt = $pdo->prepare('DELETE FROM safety_manager_log WHERE id = :id');
    $stmt->execute([':id' => $id]);

    $pdo->commit();

    // 삭제 성공 시 목록 페이지로 이동하고 성공 메시지를 전달합니다.
    header('Location: index.php?type=success&message=' . rawurlencode('업무일지가 삭제되었습니다.'));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackException) {
            // rollback 실패 시 무시합니다.
        }
    }
    // 삭제 실패 시 목록 페이지로 이동하고 에러 메시지를 전달합니다.
    header('Location: index.php?type=error&message=' . rawurlencode('업무일지 삭제 중 오류가 발생했습니다.'));
    exit;
}
