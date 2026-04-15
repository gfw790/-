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

// 검색할 연도와 월을 GET 파라미터로 받습니다.
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['default' => $currentYear, 'min_range' => 2000, 'max_range' => $currentYear + 5]]);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['default' => $currentMonth, 'min_range' => 1, 'max_range' => 12]]);

// 표시할 기간 문자열
$displayMonth = sprintf('%04d-%02d', $year, $month);

// 검색 조건을 위한 WHERE 절과 바인딩값
$whereSql = 'WHERE YEAR(log_date) = :year AND MONTH(log_date) = :month';
$params = [
    ':year' => $year,
    ':month' => $month,
];

try {
    $pdo = getDB();

    // 해당 월 전체 업무일지 건수
    $totalCountStmt = $pdo->prepare("SELECT COUNT(*) FROM safety_manager_log {$whereSql}");
    $totalCountStmt->execute($params);
    $totalCount = (int)$totalCountStmt->fetchColumn();

    // 작성자별 건수 집계
    $authorCountsStmt = $pdo->prepare(
        "SELECT manager_name, COUNT(*) AS count
         FROM safety_manager_log
         {$whereSql}
         GROUP BY manager_name
         ORDER BY count DESC, manager_name ASC"
    );
    $authorCountsStmt->execute($params);
    $authorCounts = $authorCountsStmt->fetchAll();

    // 현장명별 건수 집계
    $siteCountsStmt = $pdo->prepare(
        "SELECT site_name, COUNT(*) AS count
         FROM safety_manager_log
         {$whereSql}
         GROUP BY site_name
         ORDER BY count DESC, site_name ASC"
    );
    $siteCountsStmt->execute($params);
    $siteCounts = $siteCountsStmt->fetchAll();

    // 해당 월 업무일지 목록
    $logsStmt = $pdo->prepare(
        "SELECT id, log_date, manager_name, site_name, subject, created_at
         FROM safety_manager_log
         {$whereSql}
         ORDER BY log_date DESC, id DESC"
    );
    $logsStmt->execute($params);
    $logs = $logsStmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>데이터를 조회하는 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

$yearOptions = range($currentYear - 5, $currentYear + 1);
$monthOptions = range(1, 12);
?>
<?php
$pageTitle = '안전관리자 업무일지 월별 집계';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h1 class="h4 mb-1">월별 업무일지 집계</h1>
            <p class="text-muted mb-0">선택한 월의 업무일지 현황을 요약합니다.</p>
        </div>
        <div class="text-md-end">
            <a href="index.php" class="btn btn-primary">업무일지 목록</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" action="monthly_report.php" class="row gy-3 gx-3 align-items-end">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">연도</label>
                    <select name="year" class="form-select">
                        <?php foreach ($yearOptions as $optionYear): ?>
                            <option value="<?= h($optionYear) ?>" <?= $optionYear === $year ? 'selected' : '' ?>><?= h($optionYear) ?>년</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label">월</label>
                    <select name="month" class="form-select">
                        <?php foreach ($monthOptions as $optionMonth): ?>
                            <option value="<?= h($optionMonth) ?>" <?= $optionMonth === $month ? 'selected' : '' ?>><?= h(sprintf('%02d', $optionMonth)) ?>월</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">조회</button>
                </div>
                <div class="col-12 col-md-3 text-muted">
                    <div>선택 기간: <?= h($displayMonth) ?></div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">전체 업무일지 건수</h5>
                    <p class="display-6 mb-0"><?= h($totalCount) ?></p>
                    <p class="text-muted mb-0"><?= h($displayMonth) ?> 기간</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">작성자별 집계</h5>
                    <?php if (empty($authorCounts)): ?>
                        <p class="text-muted mb-0">집계 데이터가 없습니다.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($authorCounts as $row): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= h($row['manager_name'] ?: '작성자 미정') ?>
                                    <span class="badge bg-primary rounded-pill"><?= h($row['count']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">현장명별 집계</h5>
                    <?php if (empty($siteCounts)): ?>
                        <p class="text-muted mb-0">집계 데이터가 없습니다.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($siteCounts as $row): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= h($row['site_name'] ?: '현장명 미정') ?>
                                    <span class="badge bg-success rounded-pill"><?= h($row['count']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">월별 업무일지 목록</div>
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="p-4 text-center text-muted">조회된 업무일지 데이터가 없습니다.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">번호</th>
                            <th style="width: 120px;">작성일</th>
                            <th style="width: 140px;">작성자</th>
                            <th style="width: 160px;">현장명</th>
                            <th>제목</th>
                            <th style="width: 180px;">등록일</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $index => $log): ?>
                            <tr>
                                <td><?= h($index + 1) ?></td>
                                <td><?= h($log['log_date']) ?></td>
                                <td><?= h($log['manager_name']) ?></td>
                                <td><?= h($log['site_name']) ?></td>
                                <td><?= h($log['subject']) ?></td>
                                <td><?= h($log['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include __DIR__ . '/includes/footer.php'; ?>
