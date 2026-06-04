<?php
require_once 'includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * POSTS_PER_PAGE;
$selectedMonth = trim((string)($_GET['stat_month'] ?? ''));
$listMonth = trim((string)($_GET['list_month'] ?? ''));
$listTeam = trim((string)($_GET['list_team'] ?? ''));
$listPerson = trim((string)($_GET['list_person'] ?? ''));

$countStmt = db()->query(
    "SELECT COUNT(*)
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id"
);
$total = (int)$countStmt->fetchColumn();

$availableMonthStmt = db()->query(
    "SELECT DISTINCT DATE_FORMAT(n.incident_at, '%Y-%m') AS month_key
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     ORDER BY month_key DESC"
);
$availableMonths = [];
foreach ($availableMonthStmt->fetchAll() as $availableMonthRow) {
    $monthKey = (string)($availableMonthRow['month_key'] ?? '');
    if ($monthKey !== '') {
        $availableMonths[] = $monthKey;
    }
}

$isAllMonths = $selectedMonth === 'all';
if ($selectedMonth === '') {
    $selectedMonth = 'all';
    $isAllMonths = true;
} elseif (!$isAllMonths && (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth) || !in_array($selectedMonth, $availableMonths, true))) {
    $selectedMonth = $availableMonths[0] ?? 'all';
    $isAllMonths = $selectedMonth === 'all';
}

if ($listMonth !== '' && (!preg_match('/^\d{4}-\d{2}$/', $listMonth) || !in_array($listMonth, $availableMonths, true))) {
    $listMonth = '';
}

$monthlyCountStmt = db()->query(
    "SELECT DATE_FORMAT(n.incident_at, '%Y-%m') AS month_key, COUNT(*) AS cnt
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     WHERE n.incident_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(n.incident_at, '%Y-%m')
     ORDER BY month_key ASC"
);
$monthlyCountMap = [];
foreach ($monthlyCountStmt->fetchAll() as $monthRow) {
    $monthKey = (string)($monthRow['month_key'] ?? '');
    if ($monthKey !== '') {
        $monthlyCountMap[$monthKey] = (int)($monthRow['cnt'] ?? 0);
    }
}

$monthlyStats = [];
$monthCursor = new DateTimeImmutable(date('Y-m-01'));
for ($i = 5; $i >= 0; $i--) {
    $targetMonth = $monthCursor->modify("-{$i} month");
    $monthKey = $targetMonth->format('Y-m');
    $monthlyStats[] = [
        'key' => $monthKey,
        'label' => $targetMonth->format('Y년 n월'),
        'count' => $monthlyCountMap[$monthKey] ?? 0,
        'is_current' => $i === 0,
    ];
}

$teamNameExpr = "CASE
    WHEN TRIM(p.author_dept) = '현대기전-가스팀' THEN '가스팀'
    WHEN TRIM(p.author_dept) = '현대기전-건설팀' THEN '공사팀-전기'
    ELSE COALESCE(NULLIF(TRIM(p.author_dept), ''), '미분류')
END";

$selectedMonthSql = $isAllMonths
    ? ''
    : " WHERE DATE_FORMAT(n.incident_at, '%Y-%m') = " . db()->quote($selectedMonth);

$teamCountStmt = db()->query(
    "SELECT
         {$teamNameExpr} AS team_name,
         COUNT(*) AS cnt
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     {$selectedMonthSql}
     GROUP BY team_name
     ORDER BY cnt DESC, team_name ASC
     LIMIT 10"
);
$teamStats = $teamCountStmt->fetchAll();

$personCountStmt = db()->query(
    "SELECT
         {$teamNameExpr} AS team_name,
         COALESCE(NULLIF(TRIM(p.author_name), ''), '미상') AS author_name,
         COUNT(*) AS cnt
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     {$selectedMonthSql}
     GROUP BY team_name, author_name
     ORDER BY cnt DESC, team_name ASC, author_name ASC
     LIMIT 10"
);
$personStats = $personCountStmt->fetchAll();

$selectedMonthCount = $isAllMonths ? $total : 0;
if (!$isAllMonths) {
    foreach ($monthlyStats as $monthlyStat) {
        if ((string)$monthlyStat['key'] === $selectedMonth) {
            $selectedMonthCount = (int)$monthlyStat['count'];
            break;
        }
    }
}

$selectedMonthLabel = $isAllMonths ? '전체' : (str_replace('-', '년 ', $selectedMonth) . '월');
$baseQuery = ['stat_month' => $selectedMonth];
$buildIndexUrl = static function (array $overrides = []) use ($baseQuery): string {
    $query = array_merge($baseQuery, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $queryString = http_build_query($query);
    return 'index.php' . ($queryString !== '' ? '?' . $queryString : '');
};

$listWhere = [];
$listParams = [];
$activeListFilters = [];

if ($listMonth !== '') {
    $listWhere[] = "DATE_FORMAT(n.incident_at, '%Y-%m') = ?";
    $listParams[] = $listMonth;
    $activeListFilters[] = str_replace('-', '년 ', $listMonth) . '월';
}
if ($listTeam !== '') {
    $listWhere[] = "{$teamNameExpr} = ?";
    $listParams[] = $listTeam;
    $activeListFilters[] = '팀: ' . $listTeam;
}
if ($listPerson !== '') {
    $listWhere[] = "COALESCE(NULLIF(TRIM(p.author_name), ''), '미상') = ?";
    $listParams[] = $listPerson;
    $activeListFilters[] = '작성자: ' . $listPerson;
}

$listWhereSql = $listWhere ? (' WHERE ' . implode(' AND ', $listWhere)) : '';

$filteredCountStmt = db()->prepare(
    "SELECT COUNT(*)
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id" . $listWhereSql
);
$filteredCountStmt->execute($listParams);
$filteredTotal = (int)$filteredCountStmt->fetchColumn();

$listStmt = db()->prepare(
    "SELECT p.id, p.title, p.author_name, p.created_at, p.views,
            n.incident_at, n.location, n.work_type, n.risk_type, n.status
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     {$listWhereSql}
     ORDER BY n.incident_at DESC, p.id DESC
     LIMIT $offset, " . POSTS_PER_PAGE
);
$listStmt->execute($listParams);
$rows = $listStmt->fetchAll();

function statusLabel(string $status): string {
    if ($status === 'closed') return '완료';
    if ($status === 'in_progress') return '조치중';
    return '접수';
}
?>

<h2 class="page-title">
    <span>아차사고 목록</span>
    <span class="page-title-right">
        <span class="sub">목록 <?= number_format($filteredTotal) ?>건 / <?= $page ?>페이지</span>
        <?php if ($_currentUser && $_currentUser['role'] === 'admin'): ?>
            <a href="import_excel.php" class="btn">엑셀 가져오기</a>
        <?php endif; ?>
        <?php if ($_currentUser && (string)($_currentUser['original_role'] ?? '') === 'safety_manager'): ?>
            <a href="export_excel.php" class="btn">엑셀 다운로드</a>
        <?php endif; ?>
        <?php if ($_currentUser): ?>
            <a href="write.php" class="btn btn-primary">아차사고 입력</a>
        <?php endif; ?>
    </span>
</h2>

<section class="nearmiss-dashboard">
    <form method="get" class="nearmiss-filter-bar">
        <input type="hidden" name="page" value="1">
        <label for="stat-month" class="nearmiss-filter-label">집계 월</label>
        <select name="stat_month" id="stat-month" class="nearmiss-filter-select" onchange="this.form.submit()">
            <option value="all"<?= $selectedMonth === 'all' ? ' selected' : '' ?>>전체</option>
            <?php foreach ($availableMonths as $monthOption): ?>
                <?php $monthOptionLabel = str_replace('-', '년 ', $monthOption) . '월'; ?>
                <option value="<?= h($monthOption) ?>"<?= $monthOption === $selectedMonth ? ' selected' : '' ?>>
                    <?= h($monthOptionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm">조회</button></noscript>
    </form>

    <div class="nearmiss-summary-cards">
        <article class="nearmiss-summary-card">
            <span class="summary-label">전체 등록</span>
            <strong class="summary-value">
                <a href="<?= h($buildIndexUrl(['list_month' => null, 'list_team' => null, 'list_person' => null, 'page' => 1])) ?>" class="stat-link">
                    <?= number_format($total) ?>건
                </a>
            </strong>
        </article>
        <article class="nearmiss-summary-card">
            <span class="summary-label"><?= h($selectedMonthLabel) ?></span>
            <strong class="summary-value">
                <a href="<?= h($buildIndexUrl(['list_month' => $isAllMonths ? null : $selectedMonth, 'list_team' => null, 'list_person' => null, 'page' => 1])) ?>" class="stat-link">
                    <?= number_format($selectedMonthCount) ?>건
                </a>
            </strong>
        </article>
    </div>

    <div class="nearmiss-stat-grid">
        <section class="nearmiss-stat-panel">
            <div class="nearmiss-stat-head">
                <h3>월별 집계</h3>
                <span>최근 6개월 기준</span>
            </div>
            <div class="nearmiss-stat-list">
                <?php foreach ($monthlyStats as $monthlyIndex => $monthlyStat): ?>
                    <div class="nearmiss-stat-row<?= !empty($monthlyStat['is_current']) ? ' is-current' : '' ?>">
                        <span class="stat-main">
                            <span class="stat-rank"><?= $monthlyIndex + 1 ?></span>
                            <span class="stat-name"><?= h($monthlyStat['label']) ?></span>
                        </span>
                        <a href="<?= h($buildIndexUrl(['list_month' => (string)$monthlyStat['key'], 'list_team' => null, 'list_person' => null, 'page' => 1])) ?>" class="stat-value stat-link">
                            <?= number_format((int)$monthlyStat['count']) ?>건
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="nearmiss-stat-panel">
            <div class="nearmiss-stat-head">
                <h3>팀별 집계</h3>
                <span><?= h($selectedMonthLabel) ?> 기준 상위 10개 팀</span>
            </div>
            <div class="nearmiss-stat-list">
                <?php if (empty($teamStats)): ?>
                    <div class="nearmiss-stat-row">
                        <span class="stat-main">
                            <span class="stat-name">데이터 없음</span>
                        </span>
                        <span class="stat-value">0건</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($teamStats as $teamIndex => $teamStat): ?>
                        <div class="nearmiss-stat-row">
                            <span class="stat-main">
                                <span class="stat-rank"><?= $teamIndex + 1 ?></span>
                                <span class="stat-name"><?= h((string)$teamStat['team_name']) ?></span>
                            </span>
                            <a href="<?= h($buildIndexUrl(['list_month' => $isAllMonths ? null : $selectedMonth, 'list_team' => (string)$teamStat['team_name'], 'list_person' => null, 'page' => 1])) ?>" class="stat-value stat-link">
                                <?= number_format((int)$teamStat['cnt']) ?>건
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="nearmiss-stat-panel nearmiss-stat-panel-wide">
            <div class="nearmiss-stat-head">
                <h3>인원별 집계</h3>
                <span><?= h($selectedMonthLabel) ?> 기준 상위 10명</span>
            </div>
            <div class="nearmiss-stat-list nearmiss-stat-list-two-col">
                <?php if (empty($personStats)): ?>
                    <div class="nearmiss-stat-row">
                        <span class="stat-main">
                            <span class="stat-name">데이터 없음</span>
                        </span>
                        <span class="stat-value">0건</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($personStats as $personIndex => $personStat): ?>
                        <div class="nearmiss-stat-row">
                            <span class="stat-main">
                                <span class="stat-rank"><?= $personIndex + 1 ?></span>
                                <span class="stat-name">
                                    <?= h((string)$personStat['author_name']) ?>
                                    <small class="stat-subname"><?= h((string)$personStat['team_name']) ?></small>
                                </span>
                            </span>
                            <a href="<?= h($buildIndexUrl(['list_month' => $isAllMonths ? null : $selectedMonth, 'list_team' => (string)$personStat['team_name'], 'list_person' => (string)$personStat['author_name'], 'page' => 1])) ?>" class="stat-value stat-link">
                                <?= number_format((int)$personStat['cnt']) ?>건
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</section>

<?php if (!empty($activeListFilters)): ?>
    <div class="alert alert-info nearmiss-active-filter">
        <span>목록 필터: <?= h(implode(' / ', $activeListFilters)) ?></span>
        <a href="<?= h($buildIndexUrl(['list_month' => null, 'list_team' => null, 'list_person' => null, 'page' => 1])) ?>" class="btn btn-sm">필터 해제</a>
    </div>
<?php endif; ?>

<table class="post-table nearmiss-table nearmiss-list-table">
    <colgroup>
        <col class="col-num">
        <col class="col-date">
        <col>
        <col class="col-cat">
        <col class="col-author">
        <col class="col-date">
        <col class="col-views">
    </colgroup>
    <thead>
    <tr>
        <th>번호</th>
        <th>발생일시</th>
        <th>제목 / 장소</th>
        <th>상태</th>
        <th>작성자</th>
        <th>등록일</th>
        <th>조회</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr class="empty-row"><td colspan="7">등록된 아차사고가 없습니다.</td></tr>
    <?php else: ?>
        <?php $rowNum = $filteredTotal - $offset; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td data-label="번호"><?= $rowNum-- ?></td>
                <td data-label="발생일시"><?= dateFormat($row['incident_at'], 'Y-m-d H:i') ?></td>
                <td class="title-cell" data-label="제목 / 장소">
                    <a href="view.php?id=<?= (int)$row['id'] ?>" class="post-title">
                        <?= h($row['title']) ?>
                    </a>
                    <?php if (!empty($row['location'])): ?>
                        <div class="post-summary"><?= h($row['location']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['risk_type'])): ?>
                        <div class="post-summary"><?= h($row['risk_type']) ?></div>
                    <?php endif; ?>
                </td>
                <td data-label="상태">
                    <span class="status-badge status-<?= h($row['status']) ?>">
                        <?= statusLabel($row['status']) ?>
                    </span>
                </td>
                <td data-label="작성자">
                    <span class="author-name"><?= h($row['author_name']) ?></span>
                </td>
                <td data-label="등록일"><?= dateFormat($row['created_at'], 'Y-m-d') ?></td>
                <td data-label="조회"><?= number_format($row['views']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php
$urlPattern = 'index.php?page=%d&stat_month=' . urlencode($selectedMonth);
if ($listMonth !== '') {
    $urlPattern .= '&list_month=' . urlencode($listMonth);
}
if ($listTeam !== '') {
    $urlPattern .= '&list_team=' . urlencode($listTeam);
}
if ($listPerson !== '') {
    $urlPattern .= '&list_person=' . urlencode($listPerson);
}
echo paginate($filteredTotal, $page, POSTS_PER_PAGE, $urlPattern);
?>

<?php require_once 'includes/footer.php'; ?>
