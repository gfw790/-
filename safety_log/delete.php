<?php
require_once __DIR__ . '/../risk_assessment/db_config.php';

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

// GET 또는 POST에서 id를 받아옵니다.
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}

if ($id === false || $id === null) {
    http_response_code(400);
    echo '<h1>잘못된 요청입니다.</h1>';
    echo '<p>유효한 업무일지 ID가 전달되지 않았습니다.</p>';
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('DELETE FROM safety_manager_log WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // 삭제 후 목록 페이지로 이동합니다.
    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>삭제 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}
