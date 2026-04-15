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

// 검색 파라미터를 GET으로 받습니다.
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$managerName = trim($_GET['manager_name'] ?? '');
$siteName = trim($_GET['site_name'] ?? '');
$subject = trim($_GET['subject'] ?? '');

// 페이징 파라미터 처리
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
if ($page === false || $page === null || $page < 1) {
    $page = 1;
}
$perPage = 10;

// 알림 메시지 처리: 성공/실패 메시지를 GET 파라미터로 받습니다.
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

$conditions = [];
$params = [];

if ($startDate !== '') {
    $conditions[] = 'log_date >= :start_date';
    $params[':start_date'] = $startDate;
}
if ($endDate !== '') {
    $conditions[] = 'log_date <= :end_date';
    $params[':end_date'] = $endDate;
}
if ($managerName !== '') {
    $conditions[] = 'manager_name LIKE :manager_name';
    $params[':manager_name'] = '%' . $managerName . '%';
}
if ($siteName !== '') {
    $conditions[] = 'site_name LIKE :site_name';
    $params[':site_name'] = '%' . $siteName . '%';
}
if ($subject !== '') {
    $conditions[] = 'subject LIKE :subject';
    $params[':subject'] = '%' . $subject . '%';
}

$whereSql = '';
if (!empty($conditions)) {
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
}

try {
    $pdo = getDB();

    // 전체 건수 조회
    $countSql = "SELECT COUNT(*) AS total_count FROM safety_manager_log {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    // 검색 결과 조회 (페이징 적용)
    $sql = "SELECT id, log_date, manager_name, site_name, work_location, subject, created_at
            FROM safety_manager_log
            {$whereSql}
            ORDER BY log_date DESC, id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();
    $resultCount = count($logs);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>데이터를 불러오는 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

// 검색 파라미터를 유지하면서 페이지 값을 생성하는 헬퍼
function buildQueryParams(array $overrides = []): string
{
    $params = [
        'start_date' => $_GET['start_date'] ?? '',
        'end_date' => $_GET['end_date'] ?? '',
        'manager_name' => $_GET['manager_name'] ?? '',
        'site_name' => $_GET['site_name'] ?? '',
        'subject' => $_GET['subject'] ?? '',
    ];
    $params = array_merge($params, $overrides);
    return http_build_query($params);
}
?>
<?php
$pageTitle = '안전관리자 업무일지 목록';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="mb-1">안전관리자 업무일지 목록</h2>
            <p class="text-muted mb-0">검색 조건으로 업무일지를 빠르게 찾습니다.</p>
        </div>
        <div class="text-md-end">
            <a href="create.php" class="btn btn-success">업무일지 등록</a>
        </div>
    </div>

    <?php if ($alertClass && $alertMessage): ?>
        <div class="alert <?= h($alertClass) ?> alert-dismissible fade show mb-4" role="alert">
            <?= h($alertMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="get" action="index.php">
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label">시작일</label>
                        <input type="date" name="start_date" class="form-control" value="<?= h($startDate) ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label">종료일</label>
                        <input type="date" name="end_date" class="form-control" value="<?= h($endDate) ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label">작성자</label>
                        <input type="text" name="manager_name" class="form-control" value="<?= h($managerName) ?>" placeholder="작성자 이름">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label">현장명</label>
                        <input type="text" name="site_name" class="form-control" value="<?= h($siteName) ?>" placeholder="현장명">
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label">제목</label>
                        <input type="text" name="subject" class="form-control" value="<?= h($subject) ?>" placeholder="제목">
                    </div>
                    <div class="col-12 col-lg-6 d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-end">
                        <button type="submit" class="btn btn-primary w-100">검색</button>
                        <a href="index.php" class="btn btn-secondary w-100">초기화</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-striped mb-0">
                <thead class="table-dark">
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
                        <td colspan="8" class="text-center text-muted py-4">검색된 결과가 없습니다.</td>
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
                                    <a href="view.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-primary">보기</a>
                                    <a href="edit.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-warning">수정</a>
                                    <a href="delete.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-danger confirm-delete">삭제</a>
                                    <a href="print.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-secondary">출력</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3 mt-3">
        <div class="text-muted">
            총 <?= h($totalCount) ?>건 / <?= h($page) ?>페이지
        </div>
        <?php if ($totalCount > 0): ?>
            <nav aria-label="페이지 네비게이션">
                <ul class="pagination mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= buildQueryParams(['page' => $page - 1]) ?>" aria-label="이전">이전</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= buildQueryParams(['page' => $i]) ?>"><?= h($i) ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= buildQueryParams(['page' => $page + 1]) ?>" aria-label="다음">다음</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <script>
        // 삭제 버튼 클릭 시 확인창을 표시하고, 사용자가 확인한 경우에만 기본 링크 이동을 허용합니다.
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
