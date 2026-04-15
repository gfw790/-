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

function parseSiteVisitData($rawValue): array
{
    if (!is_string($rawValue) || trim($rawValue) === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    return is_array($decoded) ? $decoded : [];
}

function safetyLogHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

$alertType = $_GET['type'] ?? '';
$alertMessage = trim($_GET['message'] ?? '');
$alertClass = '';
if ($alertMessage !== '') {
    if ($alertType === 'success') {
        $alertClass = 'alert-success';
    } elseif ($alertType === 'error') {
        $alertClass = 'alert-danger';
    }
}

try {
    $pdo = getDB();
    $id = getValidLogId($pdo);
    $hasSiteVisitDataColumn = safetyLogHasColumn($pdo, 'safety_manager_log', 'site_visit_data');

    // safety_manager_log에서 단일 항목을 조회합니다.
    $logStmt = $pdo->prepare(
        'SELECT id, log_date, manager_name, site_name, work_location, '
        . ($hasSiteVisitDataColumn ? 'site_visit_data' : 'NULL AS site_visit_data') . ', weather, subject, summary, remark, created_at
         FROM safety_manager_log
         WHERE id = :id'
    );
    $logStmt->execute([':id' => $id]);
    $log = $logStmt->fetch();

    // safety_manager_log_detail에서 해당 log_id의 detail 목록을 조회합니다.
    $detailStmt = $pdo->prepare(
        'SELECT item_no, work_time, activity, description, status, photo_1, photo_2
         FROM safety_manager_log_detail
         WHERE log_id = :log_id
         ORDER BY item_no ASC'
    );
    $detailStmt->execute([':log_id' => $id]);
    $details = $detailStmt->fetchAll();
    $siteVisitRows = parseSiteVisitData($log['site_visit_data'] ?? '');
} catch (Throwable $e) {
    $errorMessage = '데이터를 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
    $log = null;
    $details = [];
    $siteVisitRows = [];
}
?>
<?php
$pageTitle = '안전관리자 업무일지 상세보기';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-0">업무일지 상세보기</h1>
            <p class="text-muted mb-0">선택한 업무일지의 기본 정보와 세부 기록을 확인합니다.</p>
        </div>
        <div class="btn-group" role="group">
            <a href="index.php" class="btn btn-outline-secondary">목록</a>
            <?php if (!empty($log)): ?>
                <a href="edit.php?id=<?= h($log['id']) ?>" class="btn btn-outline-primary">수정</a>
                <a href="delete.php?id=<?= h($log['id']) ?>" class="btn btn-outline-danger confirm-delete">삭제</a>
                <a href="print.php?id=<?= h($log['id']) ?>" class="btn btn-outline-success">출력</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alertClass && $alertMessage): ?>
        <div class="alert <?= h($alertClass) ?> alert-dismissible fade show" role="alert">
            <?= h($alertMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
        </div>
    <?php endif; ?>

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
                    <?php if (!empty($siteVisitRows)): ?>
                        <div class="col-12">
                            <strong>현장 방문 목록</strong>
                            <div class="table-responsive mt-2">
                                <table class="table table-bordered table-sm mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th style="width: 90px;">선택</th>
                                        <th>작업명</th>
                                        <th>작업장소</th>
                                        <th>미방문사유</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($siteVisitRows as $siteVisitRow): ?>
                                        <tr>
                                            <td><?= !empty($siteVisitRow['selected']) ? '방문' : '미방문' ?></td>
                                            <td>
                                                <?= h((string)($siteVisitRow['work_title'] ?? '')) ?>
                                                <?php if (!empty($siteVisitRow['team_name'])): ?>
                                                    <div class="text-muted small"><?= h((string)$siteVisitRow['team_name']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h((string)($siteVisitRow['work_place'] ?? '')) ?></td>
                                            <td><?= h((string)($siteVisitRow['non_visit_reason'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
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

    <script>
        // 상세보기에서도 삭제 확인을 동일하게 처리합니다.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.confirm-delete').forEach(function (deleteLink) {
                deleteLink.addEventListener('click', function (event) {
                    var confirmed = window.confirm('정말 삭제하시겠습니까?\n삭제 후에는 복구할 수 없습니다.');
                    if (!confirmed) {
                        event.preventDefault();
                    }
                });
            });
        });
    </script>
<?php include __DIR__ . '/includes/footer.php'; ?>
