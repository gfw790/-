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

try {
    $pdo = getDB();

    // 최신순 정렬: 작성일 기준 내림차순, 동일 날짜가 있을 경우 등록일 기준 내림차순
    $stmt = $pdo->prepare(
        'SELECT id, log_date, manager_name, site_name, work_location, subject, created_at
         FROM safety_manager_log
         ORDER BY log_date DESC, created_at DESC'
    );
    $stmt->execute();
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>데이터를 불러오는 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전관리자 업무일지 목록</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-VnY9Xl60G7eusM0ZyEJ+X8LwKUQ/yqPn2rGHXeFQ0WlQg5KL6N37pP3cT7QeFk0I" crossorigin="anonymous">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-0">안전관리자 업무일지 목록</h1>
            <p class="text-muted mb-0">최근 등록된 업무일지를 확인하고 관리합니다.</p>
        </div>
        <a href="create.php" class="btn btn-primary">업무일지 등록</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                    <thead class="table-light">
                    <tr>
                        <th style="width: 70px;">번호</th>
                        <th style="width: 120px;">작성일</th>
                        <th style="width: 140px;">작성자</th>
                        <th style="width: 160px;">현장명</th>
                        <th style="width: 160px;">작업위치</th>
                        <th>제목</th>
                        <th style="width: 180px;">등록일</th>
                        <th style="width: 300px;">기능</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">등록된 업무일지가 없습니다.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $index => $log): ?>
                            <tr>
                                <td><?= h($index + 1) ?></td>
                                <td><?= h($log['log_date']) ?></td>
                                <td><?= h($log['manager_name']) ?></td>
                                <td><?= h($log['site_name']) ?></td>
                                <td><?= h($log['work_location']) ?></td>
                                <td><?= h($log['subject']) ?></td>
                                <td><?= h($log['created_at']) ?></td>
                                <td>
                                    <div class="btn-group" role="group" aria-label="행동 버튼">
                                        <a href="view.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-outline-primary">보기</a>
                                        <a href="edit.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-outline-secondary">수정</a>
                                        <a href="delete.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-outline-danger">삭제</a>
                                        <a href="print.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-outline-success">출력</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
