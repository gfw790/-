<?php
require_once 'includes/header.php';

$catCode = $_GET['cat'] ?? 'notice';
$isAllTab = ($catCode === 'all');
$effectiveCatCode = $isAllTab ? '' : $catCode;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * POSTS_PER_PAGE;

$where = '1=1';
$params = [];
$category = null;
if ($effectiveCatCode !== '') {
    $category = getCategoryByCode($effectiveCatCode);
    if ($category) {
        $where .= ' AND p.category_id = ?';
        $params[] = $category['id'];
    }
}

// [전체] 탭에서는 가스팀을 제외한 일반 사용자에게 인계사항을 제외함
if ($isAllTab && !($_isGasTeam ?? false) && (($_currentUser['role'] ?? '') !== 'admin')) {
    $where .= ' AND c.code <> ?';
    $params[] = 'handover';
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
if ($isAllTab && !($_isGasTeam ?? false)) {
    $noticeQuery .= " AND c.code <> ?";
    $noticeParams[] = 'handover';
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

<table class="post-table">
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
            <td><span class="notice-tag">공지</span></td>
            <td><span class="cat-badge"><?= h($n['cat_name']) ?></span></td>
            <td class="title-cell">
                <a href="view.php?id=<?= (int)$n['id'] ?>" class="post-title"><?= h($n['title']) ?></a>
                <?php if ($n['comment_count'] > 0): ?><span class="comment-count">[<?= (int)$n['comment_count'] ?>]</span><?php endif; ?>
                <?php if ($n['attach_cnt'] > 0): ?><span class="attach-icon">첨부</span><?php endif; ?>
                <?php if (strtotime($n['created_at']) > time() - 86400): ?><span class="new-tag">N</span><?php endif; ?>
            </td>
            <td>
                <?php if ($n['author_dept']): ?><span class="author-dept"><?= h($n['author_dept']) ?></span> <?php endif; ?>
                <span class="author-name"><?= h($n['author_name']) ?></span>
            </td>
            <td><?= dateFormat($n['created_at'], 'Y-m-d') ?></td>
            <td><?= number_format($n['views']) ?></td>
            <td><?= number_format($n['like_count']) ?></td>
        </tr>
    <?php endforeach; ?>

    <?php if (empty($posts) && empty($notices)): ?>
        <tr class="empty-row"><td colspan="7">등록된 게시글이 없습니다.</td></tr>
    <?php endif; ?>

    <?php $rowNum = $total - $offset; ?>
    <?php foreach ($posts as $p): ?>
        <tr>
            <td><?= $rowNum-- ?></td>
            <td><span class="cat-badge"><?= h($p['cat_name']) ?></span></td>
            <td class="title-cell">
                <a href="view.php?id=<?= (int)$p['id'] ?>" class="post-title"><?= h($p['title']) ?></a>
                <?php if ($p['comment_count'] > 0): ?><span class="comment-count">[<?= (int)$p['comment_count'] ?>]</span><?php endif; ?>
                <?php if ($p['attach_cnt'] > 0): ?><span class="attach-icon">첨부</span><?php endif; ?>
                <?php if (strtotime($p['created_at']) > time() - 86400): ?><span class="new-tag">N</span><?php endif; ?>
            </td>
            <td>
                <?php if ($p['author_dept']): ?><span class="author-dept"><?= h($p['author_dept']) ?></span> <?php endif; ?>
                <span class="author-name"><?= h($p['author_name']) ?></span>
            </td>
            <td><?= dateFormat($p['created_at'], 'Y-m-d') ?></td>
            <td><?= number_format($p['views']) ?></td>
            <td><?= number_format($p['like_count']) ?></td>
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
