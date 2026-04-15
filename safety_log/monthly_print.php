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

// GET 파라미터 year/month를 받아서 현재 연/월을 기본값으로 사용합니다.
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
if ($year === false || $year === null) {
    $year = $currentYear;
}
if ($month === false || $month === null || $month < 1 || $month > 12) {
    $month = $currentMonth;
}

$displayMonth = sprintf('%04d-%02d', $year, $month);
$params = [
    ':year' => $year,
    ':month' => $month,
];

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT id, log_date, manager_name, site_name, work_location, subject, created_at
         FROM safety_manager_log
         WHERE YEAR(log_date) = :year
           AND MONTH(log_date) = :month
         ORDER BY log_date DESC, id DESC'
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    $totalCount = count($logs);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>데이터를 불러오는 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

$printDate = date('Y-m-d H:i');
$reportUrl = 'monthly_report.php?year=' . rawurlencode($year) . '&month=' . rawurlencode($month);
$indexUrl = 'index.php';
?>
<?php
$pageTitle = '안전관리자 업무일지 월간 현황';
$extraHead = <<<HTML
<style>
        body {
            background-color: #f8f9fa;
        }
        .print-card {
            border: 1px solid #dee2e6;
            border-radius: .5rem;
            background: #fff;
        }
        .print-toolbar {
            margin-bottom: 1rem;
        }
        .page-title {
            font-size: 1.25rem;
            font-weight: 700;
        }
        @media print {
            body {
                background: white;
                color: #000;
            }
            .print-toolbar {
                display: none !important;
            }
            .container {
                max-width: 100%;
                padding: 0;
            }
            .print-card {
                border: 1px solid #000;
                box-shadow: none;
            }
            .table {
                color: #000;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
            }
            .no-print {
                display: none !important;
            }
            @page {
                size: A4;
                margin: 15mm;
            }
            .table-responsive {
                overflow: visible;
            }
            tr {
                page-break-inside: avoid;
            }
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
        }
</style>
HTML;
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3 print-toolbar">
        <div>
            <h1 class="h4 mb-1">안전관리자 업무일지 월간 현황</h1>
            <p class="text-muted mb-0">선택한 월의 업무일지 목록을 인쇄합니다.</p>
        </div>
        <div class="btn-toolbar gap-2">
            <button type="button" class="btn btn-primary" onclick="window.print()">인쇄</button>
            <a href="<?= h($reportUrl) ?>" class="btn btn-secondary">월별 집계</a>
            <a href="<?= h($indexUrl) ?>" class="btn btn-outline-secondary">목록</a>
        </div>
    </div>

    <div class="print-card mb-4 p-4">
        <div class="row gy-3">
            <div class="col-12 col-md-3">
                <div class="fw-semibold text-muted">연도</div>
                <div><?= h($year) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="fw-semibold text-muted">월</div>
                <div><?= h(sprintf('%02d', $month)) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="fw-semibold text-muted">총 업무일지 건수</div>
                <div><?= h($totalCount) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="fw-semibold text-muted">출력일시</div>
                <div><?= h($printDate) ?></div>
            </div>
        </div>
    </div>

    <div class="print-card p-4">
        <div class="mb-3">
            <h2 class="h5 mb-0">업무일지 목록</h2>
            <p class="text-muted mb-0"><?= h($displayMonth) ?> 기준</p>
        </div>

        <?php if ($totalCount === 0): ?>
            <div class="text-center text-muted py-5">해당 월의 업무일지가 없습니다.</div>
        <?php else: ?>
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
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $index => $log): ?>
                        <tr>
                            <td><?= h($index + 1) ?></td>
                            <td><?= h($log['log_date']) ?></td>
                            <td><?= h($log['manager_name']) ?></td>
                            <td><?= h($log['site_name']) ?></td>
                            <td><?= h($log['work_location']) ?></td>
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
