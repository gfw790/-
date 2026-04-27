<?php
require_once 'includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * POSTS_PER_PAGE;

$countStmt = db()->query(
    "SELECT COUNT(*)
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id"
);
$total = (int)$countStmt->fetchColumn();

$listStmt = db()->prepare(
    "SELECT p.id, p.title, p.author_name, p.created_at, p.views,
            n.incident_at, n.location, n.work_type, n.risk_type, n.status
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     ORDER BY n.incident_at DESC, p.id DESC
     LIMIT $offset, " . POSTS_PER_PAGE
);
$listStmt->execute();
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
        <span class="sub">총 <?= number_format($total) ?>건 / <?= $page ?>페이지</span>
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
        <?php $rowNum = $total - $offset; ?>
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
$urlPattern = 'index.php?page=%d';
echo paginate($total, $page, POSTS_PER_PAGE, $urlPattern);
?>

<?php require_once 'includes/footer.php'; ?>
