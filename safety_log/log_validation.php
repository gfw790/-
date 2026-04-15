<?php

/**
 * GET 파라미터 id를 검사하고 safety_manager_log에 존재하는지 확인합니다.
 *
 * @param PDO $pdo
 * @return int 유효한 로그 ID
 */
function getValidLogId(PDO $pdo): int
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id === false || $id === null) {
        redirectInvalidLog('잘못된 접근입니다.');
    }

    $stmt = $pdo->prepare('SELECT id FROM safety_manager_log WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        redirectInvalidLog('존재하지 않는 업무일지입니다.');
    }

    return (int)$row['id'];
}

/**
 * 오류 메시지를 index.php로 전달하고 이동합니다.
 *
 * @param string $message
 * @return void
 */
function redirectInvalidLog(string $message): void
{
    header('Location: index.php?type=error&message=' . rawurlencode($message));
    exit;
}
