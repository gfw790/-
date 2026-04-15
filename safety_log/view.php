<?php
require_once __DIR__ . '/../../risk_server/db_config.php';

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

// GET 파라미터 id를 받아서 검증합니다.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null) {
    $errorMessage = '유효한 업무일지 ID가 전달되지 않았습니다.';
    $log = null;
    $details = [];
} else {
    try {
        $pdo = getDB();

        // safety_manager_log에서 단일 항목을 조회합니다.
        $logStmt = $pdo->prepare(
            'SELECT id, log_date, manager_name, site_name, work_location, weather, subject, summary, remark, created_at
             FROM safety_manager_log
             WHERE id = :id'
        );
        $logStmt->execute([':id' => $id]);
        $log = $logStmt->fetch();

        if (!$log) {
            $errorMessage = '요청하신 업무일지를 찾을 수 없습니다.';
            $details = [];
        } else {
            // safety_manager_log_detail에서 해당 log_id의 detail 목록을 조회합니다.
            $detailStmt = $pdo->prepare(
                'SELECT item_no, work_time, activity, description, status, photo_1, photo_2
                 FROM safety_manager_log_detail
                 WHERE log_id = :log_id
                 ORDER BY item_no ASC'
            );
            $detailStmt->execute([':log_id' => $id]);
            $details = $detailStmt->fetchAll();
            $errorMessage = '';
        }
    } catch (Throwable $e) {
        $errorMessage = '데이터를 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
        $log = null;
        $details = [];
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전관리자 업무일지 상세보기</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-VnY9Xl60G7eusM0ZyEJ+X8LwKUQ/yqPn2rGHXeFQ0WlQg5KL6N37pP3cT7QeFk0I" crossorigin="anonymous">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-0">업무일지 상세보기</h1>
            <p class="text-muted mb-0">선택한 업무일지의 기본 정보와 세부 기록을 확인합니다.</p>
        </div>
        <div class="btn-group" role="group">
            <a href="index.php" class="btn btn-outline-secondary">목록</a>
            <?php if (!empty($log)): ?>
                <a href="edit.php?id=<?= h($log['id']) ?>" class="btn btn-outline-primary">수정</a>
                <a href="delete.php?id=<?= h($log['id']) ?>" class="btn btn-outline-danger">삭제</a>
                <a href="print.php?id=<?= h($log['id']) ?>" class="btn btn-outline-success">출력</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-warning"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php if (!empty($log)): ?>
        <div class="card mb-4">
            <div class="card-header">기본 정보</div>
            <div class="card-body">
                <div class="row gy-3">
                    <div class="col-md-4">
                        <strong>작성일</strong>
                        <div><?= h($log['log_date']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <strong>작성자</strong>
                        <div><?= h($log['manager_name']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <strong>현장명</strong>
                        <div><?= h($log['site_name']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <strong>작업위치</strong>
                        <div><?= h($log['work_location']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <strong>날씨</strong>
                        <div><?= h($log['weather']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <strong>등록일</strong>
                        <div><?= h($log['created_at']) ?></div>
                    </div>
                    <div class="col-12">
                        <strong>제목</strong>
                        <div><?= h($log['subject']) ?></div>
                    </div>
                    <div class="col-12">
                        <strong>요약</strong>
                        <div class="border rounded p-3 bg-light"><?= nl2br(h($log['summary'])) ?></div>
                    </div>
                    <div class="col-12">
                        <strong>비고</strong>
                        <div class="border rounded p-3 bg-light"><?= nl2br(h($log['remark'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">세부 기록</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">No.</th>
                            <th style="width: 120px;">시간</th>
                            <th>업무구분</th>
                            <th>내용</th>
                            <th style="width: 120px;">상태</th>
                            <th style="width: 200px;">사진1</th>
                            <th style="width: 200px;">사진2</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($details)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">등록된 세부 기록이 없습니다.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($details as $detail): ?>
                                <tr>
                                    <td><?= h($detail['item_no']) ?></td>
                                    <td><?= h($detail['work_time']) ?></td>
                                    <td><?= h($detail['activity']) ?></td>
                                    <td><?= h($detail['description']) ?></td>
                                    <td><?= h($detail['status']) ?></td>
                                    <td>
                                        <?php if (!empty($detail['photo_1'])): ?>
                                            <a href="show_image.php?file=<?= h(rawurlencode($detail['photo_1'])) ?>" target="_blank">
                                                <img src="show_image.php?file=<?= h(rawurlencode($detail['photo_1'])) ?>" alt="사진1" class="img-fluid img-thumbnail" style="max-height: 120px;">
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">없음</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($detail['photo_2'])): ?>
                                            <a href="show_image.php?file=<?= h(rawurlencode($detail['photo_2'])) ?>" target="_blank">
                                                <img src="show_image.php?file=<?= h(rawurlencode($detail['photo_2'])) ?>" alt="사진2" class="img-fluid img-thumbnail" style="max-height: 120px;">
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">없음</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
