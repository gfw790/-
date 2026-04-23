<?php
require_once 'includes/header.php';

$catCode = $_GET['cat'] ?? 'notice';
$isAllTab = ($catCode === 'all');
$effectiveCatCode = $isAllTab ? '' : $catCode;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * POSTS_PER_PAGE;

$allTabAllowedCodes = ['notice', 'free', 'qna', 'data', 'change_request'];
$allTabSharedDeptCodes = ['notice', 'data'];
$allTabStrictDeptCodes = ['free', 'qna', 'change_request'];
$currentDept = trim((string)($_currentUser['dept'] ?? ''));

$where = '1=1';
$params = [];
// 관리자는 모든 게시물 볼 수 있음
$isAdmin = in_array(($_currentUser['role'] ?? ''), ['admin', 'administrator'], true);
$category = null;
if ($effectiveCatCode !== '') {
    $category = getCategoryByCode($effectiveCatCode);
    if ($category) {
        $where .= ' AND p.category_id = ?';
        $params[] = $category['id'];
    }
}

if ($isAllTab) {
    $allTabPlaceholders = implode(',', array_fill(0, count($allTabAllowedCodes), '?'));
    $where .= " AND c.code IN ($allTabPlaceholders)";
    $params = array_merge($params, $allTabAllowedCodes);

    if ($currentDept !== '' && !$isAdmin) {
        $sharedDeptPlaceholders = implode(',', array_fill(0, count($allTabSharedDeptCodes), '?'));
        $strictDeptPlaceholders = implode(',', array_fill(0, count($allTabStrictDeptCodes), '?'));
        $where .= " AND ((c.code IN ($sharedDeptPlaceholders) AND (COALESCE(p.author_dept, '') = '' OR p.author_dept = ?)) OR (c.code IN ($strictDeptPlaceholders) AND p.author_dept = ?))";
        $params = array_merge($params, $allTabSharedDeptCodes, [$currentDept], $allTabStrictDeptCodes, [$currentDept]);
    }
}

$noticeQuery =
    "SELECT p.*, c.name AS cat_name, c.code AS cat_code,
            (SELECT COUNT(*) FROM attachments WHERE post_id = p.id) AS attach_cnt
     FROM posts p
     JOIN categories c ON p.category_id = c.id
     WHERE p.is_notice = 1";
$noticeParams = [];
if ($category) {
    $noticeQuery .= " AND p.category_id = ?";
    $noticeParams[] = $category['id'];
}
if ($isAllTab) {
    $allTabNoticePlaceholders = implode(',', array_fill(0, count($allTabAllowedCodes), '?'));
    $noticeQuery .= " AND c.code IN ($allTabNoticePlaceholders)";
    $noticeParams = array_merge($noticeParams, $allTabAllowedCodes);

    if ($currentDept !== '' && !$isAdmin) {
        $noticeQuery .= " AND (COALESCE(p.author_dept, '') = '' OR p.author_dept = ?)";
        $noticeParams[] = $currentDept;
    }
}
$noticeQuery .= "\n     ORDER BY p.created_at DESC\n     LIMIT 5";

$noticeStmt = db()->prepare($noticeQuery);
$noticeStmt->execute($noticeParams);
$notices = $noticeStmt->fetchAll();

$countStmt = db()->prepare("SELECT COUNT(*) FROM posts p JOIN categories c ON p.category_id = c.id WHERE $where AND p.is_notice = 0");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = db()->prepare(
    "SELECT p.*, c.name AS cat_name, c.code AS cat_code,
            (SELECT COUNT(*) FROM attachments WHERE post_id = p.id) AS attach_cnt
     FROM posts p
     JOIN categories c ON p.category_id = c.id
     WHERE $where AND p.is_notice = 0
     ORDER BY p.created_at DESC
     LIMIT $offset, " . POSTS_PER_PAGE
);
$listStmt->execute($params);
$posts = $listStmt->fetchAll();

$pageTitleText = $category ? $category['name'] : '전체 게시글';
?>

<h2 class="page-title">
    <span><?= h($pageTitleText) ?></span>
    <span class="sub">총 <?= number_format($total) ?>건 / <?= $page ?>페이지</span>
</h2>

<table class="post-table post-list-table">
    <colgroup>
        <col class="col-num">
        <col class="col-cat">
        <col>
        <col class="col-author">
        <col class="col-date">
        <col class="col-views">
        <col class="col-likes">
    </colgroup>
    <thead>
    <tr>
        <th>번호</th>
        <th>분류</th>
        <th>제목</th>
        <th>작성자</th>
        <th>등록일</th>
        <th>조회</th>
        <th>추천</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($notices as $n): ?>
        <tr class="notice-row">
            <td data-label="번호"><span class="notice-tag">공지</span></td>
            <td data-label="분류"><span class="cat-badge"><?= h($n['cat_name']) ?></span></td>
            <td class="title-cell" data-label="제목">
                <a href="view.php?id=<?= (int)$n['id'] ?>" class="post-title"><?= h($n['title']) ?></a>
                <?php if ($n['comment_count'] > 0): ?><span class="comment-count">[<?= (int)$n['comment_count'] ?>]</span><?php endif; ?>
                <?php if ($n['attach_cnt'] > 0): ?><span class="attach-icon">첨부</span><?php endif; ?>
                <?php if (strtotime($n['created_at']) > time() - 86400): ?><span class="new-tag">N</span><?php endif; ?>
            </td>
            <td data-label="작성자">
                <?php if ($n['author_dept']): ?><span class="author-dept"><?= h($n['author_dept']) ?></span> <?php endif; ?>
                <span class="author-name"><?= h($n['author_name']) ?></span>
            </td>
            <td data-label="등록일"><?= dateFormat($n['created_at'], 'Y-m-d') ?></td>
            <td data-label="조회"><?= number_format($n['views']) ?></td>
            <td data-label="추천"><?= number_format($n['like_count']) ?></td>
        </tr>
    <?php endforeach; ?>

    <?php if (empty($posts) && empty($notices)): ?>
        <tr class="empty-row"><td colspan="7">등록된 게시글이 없습니다.</td></tr>
    <?php endif; ?>

    <?php $rowNum = $total - $offset; ?>
    <?php foreach ($posts as $p): ?>
        <tr>
            <td data-label="번호"><?= $rowNum-- ?></td>
            <td data-label="분류"><span class="cat-badge"><?= h($p['cat_name']) ?></span></td>
            <td class="title-cell" data-label="제목">
                <a href="view.php?id=<?= (int)$p['id'] ?>" class="post-title"><?= h($p['title']) ?></a>
                <?php if ($p['comment_count'] > 0): ?><span class="comment-count">[<?= (int)$p['comment_count'] ?>]</span><?php endif; ?>
                <?php if ($p['attach_cnt'] > 0): ?><span class="attach-icon">첨부</span><?php endif; ?>
                <?php if (strtotime($p['created_at']) > time() - 86400): ?><span class="new-tag">N</span><?php endif; ?>
            </td>
            <td data-label="작성자">
                <?php if ($p['author_dept']): ?><span class="author-dept"><?= h($p['author_dept']) ?></span> <?php endif; ?>
                <span class="author-name"><?= h($p['author_name']) ?></span>
            </td>
            <td data-label="등록일"><?= dateFormat($p['created_at'], 'Y-m-d') ?></td>
            <td data-label="조회"><?= number_format($p['views']) ?></td>
            <td data-label="추천"><?= number_format($p['like_count']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php
$urlPattern = 'index.php?' . ($effectiveCatCode || $isAllTab ? 'cat=' . urlencode($isAllTab ? 'all' : $effectiveCatCode) . '&' : '') . 'page=%d';
echo paginate($total, $page, POSTS_PER_PAGE, $urlPattern);
?>

<div class="action-bar">
    <div></div>
    <?php if ($_currentUser): ?>
        <a href="write.php<?= $effectiveCatCode ? '?cat=' . h($effectiveCatCode) : '' ?>" class="btn btn-primary">글쓰기</a>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
