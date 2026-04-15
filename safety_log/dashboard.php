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

try {
    $pdo = getDB();

    // 전체 업무일지 건수
    $totalCountStmt = $pdo->prepare('SELECT COUNT(*) FROM safety_manager_log');
    $totalCountStmt->execute();
    $totalCount = (int)$totalCountStmt->fetchColumn();

    // 이번 달 업무일지 건수
    $currentYearMonth = date('Y-m');
    $monthCountStmt = $pdo->prepare('SELECT COUNT(*) FROM safety_manager_log WHERE DATE_FORMAT(log_date, "%Y-%m") = :year_month');
    $monthCountStmt->execute([':year_month' => $currentYearMonth]);
    $monthCount = (int)$monthCountStmt->fetchColumn();

    // 오늘 작성 건수
    $today = date('Y-m-d');
    $todayCountStmt = $pdo->prepare('SELECT COUNT(*) FROM safety_manager_log WHERE DATE(log_date) = :today');
    $todayCountStmt->execute([':today' => $today]);
    $todayCount = (int)$todayCountStmt->fetchColumn();

    // 작성자 수 (중복 제외)
    $uniqueAuthorsStmt = $pdo->prepare('SELECT COUNT(DISTINCT manager_name) FROM safety_manager_log');
    $uniqueAuthorsStmt->execute();
    $uniqueAuthors = (int)$uniqueAuthorsStmt->fetchColumn();

    // 최근 등록 업무일지 5건
    $recentLogsStmt = $pdo->prepare(
        'SELECT id, log_date, manager_name, site_name, subject
         FROM safety_manager_log
         ORDER BY log_date DESC, id DESC
         LIMIT 5'
    );
    $recentLogsStmt->execute();
    $recentLogs = $recentLogsStmt->fetchAll();

    // 작성자별 상위 5명 집계
    $authorTopStmt = $pdo->prepare(
        'SELECT manager_name, COUNT(*) AS count
         FROM safety_manager_log
         GROUP BY manager_name
         ORDER BY count DESC, manager_name ASC
         LIMIT 5'
    );
    $authorTopStmt->execute();
    $authorTop = $authorTopStmt->fetchAll();

    // 현장별 상위 5개 집계
    $siteTopStmt = $pdo->prepare(
        'SELECT site_name, COUNT(*) AS count
         FROM safety_manager_log
         GROUP BY site_name
         ORDER BY count DESC, site_name ASC
         LIMIT 5'
    );
    $siteTopStmt->execute();
    $siteTop = $siteTopStmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>데이터를 불러오는 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

$navButtons = [
    ['href' => 'create.php', 'label' => '업무일지 등록', 'class' => 'btn-primary'],
    ['href' => 'index.php', 'label' => '목록 보기', 'class' => 'btn-secondary'],
    ['href' => 'monthly_report.php', 'label' => '월별 집계', 'class' => 'btn-info text-white'],
    ['href' => 'monthly_print.php', 'label' => '월간 출력', 'class' => 'btn-outline-dark'],
];
?>
<?php
$pageTitle = '안전관리자 업무일지 대시보드';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">안전관리자 업무일지 대시보드</h1>
            <p class="text-muted mb-0">업무일지 현황과 주요 집계를 한눈에 확인합니다.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($navButtons as $button): ?>
                <a href="<?= h($button['href']) ?>" class="btn <?= h($button['class']) ?>"><?= h($button['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted mb-2">전체 업무일지 수</h6>
                    <p class="display-4 fw-bold mb-1"> <?= h($totalCount) ?></p>
                    <p class="text-muted small mb-0">총 등록된 업무일지 건수입니다.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted mb-2">이번 달 업무일지 수</h6>
                    <p class="display-4 fw-bold mb-1"> <?= h($monthCount) ?></p>
                    <p class="text-muted small mb-0">현재 달에 작성된 업무일지 건수입니다.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted mb-2">오늘 작성 건수</h6>
                    <p class="display-4 fw-bold mb-1"> <?= h($todayCount) ?></p>
                    <p class="text-muted small mb-0">오늘 작성된 업무일지 수입니다.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted mb-2">작성자 수</h6>
                    <p class="display-4 fw-bold mb-1"> <?= h($uniqueAuthors) ?></p>
                    <p class="text-muted small mb-0">중복 없이 집계된 작성자 수입니다.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-header">최근 등록 업무일지 5건</div>
                <div class="card-body p-0">
                    <?php if (empty($recentLogs)): ?>
                        <div class="p-4 text-center text-muted">최근 등록된 업무일지가 없습니다.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 110px;">작성일</th>
                                    <th style="width: 140px;">작성자</th>
                                    <th style="width: 150px;">현장명</th>
                                    <th>제목</th>
                                    <th style="width: 120px;">보기</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td><?= h($log['log_date']) ?></td>
                                        <td><?= h($log['manager_name']) ?></td>
                                        <td><?= h($log['site_name']) ?></td>
                                        <td><?= h($log['subject']) ?></td>
                                        <td>
                                            <a href="view.php?id=<?= h($log['id']) ?>" class="btn btn-sm btn-outline-primary">보기</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-header">작성자별 상위 5명</div>
                <div class="card-body p-0">
                    <?php if (empty($authorTop)): ?>
                        <div class="p-4 text-center text-muted">작성자 집계 데이터가 없습니다.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 70px;">순위</th>
                                    <th>작성자</th>
                                    <th style="width: 110px;">건수</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($authorTop as $index => $row): ?>
                                    <tr>
                                        <td><?= h($index + 1) ?></td>
                                        <td><?= h($row['manager_name']) ?></td>
                                        <td><?= h($row['count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-header">현장별 상위 5개</div>
                <div class="card-body p-0">
                    <?php if (empty($siteTop)): ?>
                        <div class="p-4 text-center text-muted">현장 집계 데이터가 없습니다.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 70px;">순위</th>
                                    <th>현장명</th>
                                    <th style="width: 110px;">건수</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($siteTop as $index => $row): ?>
                                    <tr>
                                        <td><?= h($index + 1) ?></td>
                                        <td><?= h($row['site_name']) ?></td>
                                        <td><?= h($row['count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php include __DIR__ . '/includes/footer.php'; ?>
