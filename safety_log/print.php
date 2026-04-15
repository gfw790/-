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

try {
    $pdo = getDB();
    $id = getValidLogId($pdo);

    // safety_manager_log에서 단일 항목을 조회합니다.
    $stmt = $pdo->prepare(
        'SELECT id, log_date, manager_name, site_name, work_location, weather, subject, summary, remark, created_at
         FROM safety_manager_log
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $log = $stmt->fetch();

    // safety_manager_log_detail 목록을 조회합니다.
    $detailStmt = $pdo->prepare(
        'SELECT item_no, work_time, activity, description, status, photo_1, photo_2
         FROM safety_manager_log_detail
         WHERE log_id = :log_id
         ORDER BY item_no ASC'
    );
    $detailStmt->execute([':log_id' => $id]);
    $details = $detailStmt->fetchAll();
    $errorMessage = '';
} catch (Throwable $e) {
    $errorMessage = '데이터를 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
    $log = null;
    $details = [];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전관리자 업무일지</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-VnY9Xl60G7eusM0ZyEJ+X8LwKUQ/yqPn2rGHXeFQ0WlQg5KL6N37pP3cT7QeFk0I" crossorigin="anonymous">
    <style>
        body {
            background: #f8f9fa;
        }
        .print-container {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 24px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
        }
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        .field-label {
            font-weight: 600;
        }
        .info-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 16px;
            border-radius: 0.35rem;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .image-block img {
            max-width: 100%;
            height: auto;
            display: block;
            margin-bottom: 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .no-print {
            margin-bottom: 20px;
        }
        @media print {
            body {
                background: white;
            }
            .print-container {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .table {
                font-size: 12pt;
            }
            .image-block img {
                max-width: 100%;
            }
            @page {
                size: A4;
                margin: 20mm;
            }
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="print-container">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h1 class="h5 mb-1">안전관리자 업무일지</h1>
                <div class="text-muted">출력용 페이지입니다.</div>
            </div>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-primary" onclick="window.print()">인쇄</button>
                <a href="index.php" class="btn btn-secondary">목록</a>
                <?php if (!empty($log)): ?>
                    <a href="view.php?id=<?= h($log['id']) ?>" class="btn btn-info text-white">상세</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-warning"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <?php if (!empty($log)): ?>
            <div class="mb-4">
                <div class="section-title">기본 정보</div>
                <div class="row g-3 info-box">
                    <div class="col-md-4">
                        <div class="field-label">작성일</div>
                        <div><?= h($log['log_date']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="field-label">작성자</div>
                        <div><?= h($log['manager_name']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="field-label">현장명</div>
                        <div><?= h($log['site_name']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="field-label">작업위치</div>
                        <div><?= h($log['work_location']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="field-label">날씨</div>
                        <div><?= h($log['weather']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="field-label">제목</div>
                        <div><?= h($log['subject']) ?></div>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="field-label">요약</div>
                        <div class="border rounded p-3 bg-light"><?= nl2br(h($log['summary'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="field-label">비고</div>
                        <div class="border rounded p-3 bg-light"><?= nl2br(h($log['remark'])) ?></div>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <div class="section-title">세부 기록</div>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 60px;">순번</th>
                            <th style="width: 120px;">시간</th>
                            <th>업무구분</th>
                            <th>내용</th>
                            <th style="width: 120px;">상태</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($details)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">등록된 세부 기록이 없습니다.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($details as $detail): ?>
                                <tr>
                                    <td><?= h($detail['item_no']) ?></td>
                                    <td><?= h($detail['work_time']) ?></td>
                                    <td><?= h($detail['activity']) ?></td>
                                    <td><?= h($detail['description']) ?></td>
                                    <td><?= h($detail['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php
            $images = [];
            foreach ($details as $detail) {
                if (!empty($detail['photo_1'])) {
                    $images[] = $detail['photo_1'];
                }
                if (!empty($detail['photo_2'])) {
                    $images[] = $detail['photo_2'];
                }
            }
            ?>

            <?php if (!empty($images)): ?>
                <div class="mb-4">
                    <div class="section-title">사진</div>
                    <div class="row gy-3 image-block">
                        <?php foreach ($images as $image): ?>
                            <div class="col-md-6">
                                <img src="show_image.php?file=<?= h(rawurlencode($image)) ?>" alt="업무일지 사진">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
