<?php
require_once 'includes/header.php';
ensureBoardNoticeTargetSchema();

$q = trim($_GET['q'] ?? '');
$field = $_GET['field'] ?? 'all';
$cat = $_GET['cat'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * POSTS_PER_PAGE;

$where = ['1=1'];
$params = [];
$currentDept = trim((string)($_currentUser['dept'] ?? ''));
$isAdmin = in_array(($_currentUser['role'] ?? ''), ['admin', 'administrator'], true);

if (!$isAdmin && $currentDept !== '') {
    $where[] = "(p.is_notice = 0 OR COALESCE(p.notice_target_team, 'ALL') = 'ALL' OR p.notice_target_team = ?)";
    $params[] = $currentDept;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    switch ($field) {
        case 'title':
            $where[] = 'p.title LIKE ?';
            $params[] = $like;
            break;
        case 'content':
            $where[] = 'p.content LIKE ?';
            $params[] = $like;
            break;
        case 'author':
            $where[] = 'p.author_name LIKE ?';
            $params[] = $like;
            break;
        default:
            $where[] = '(p.title LIKE ? OR p.content LIKE ? OR p.author_name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            break;
    }
}

if ($cat !== '') {
    $category = getCategoryByCode($cat);
    if ($category) {
        $where[] = 'p.category_id = ?';
        $params[] = $category['id'];
    }
}

if ($from !== '') {
    $where[] = 'p.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 'p.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM posts p WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = db()->prepare(
    "SELECT p.*, c.name AS cat_name, c.code AS cat_code,
            (SELECT COUNT(*) FROM attachments WHERE post_id = p.id) AS attach_cnt
     FROM posts p
     JOIN categories c ON p.category_id = c.id
     WHERE $whereSql
     ORDER BY p.created_at DESC
     LIMIT $offset, " . POSTS_PER_PAGE
);
$listStmt->execute($params);
$posts = $listStmt->fetchAll();

$pageTitle = '검색';
?>

<h2 class="page-title">
    <span>검색 결과</span>
    <span class="sub">총 <?= number_format($total) ?>건</span>
</h2>

<form method="get" action="search.php" class="search-panel">
    <select name="field">
        <option value="all" <?= $field === 'all' ? 'selected' : '' ?>>전체</option>
        <option value="title" <?= $field === 'title' ? 'selected' : '' ?>>제목</option>
        <option value="content" <?= $field === 'content' ? 'selected' : '' ?>>내용</option>
        <option value="author" <?= $field === 'author' ? 'selected' : '' ?>>작성자</option>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="검색어">
    <select name="cat">
        <option value="">전체 분류</option>
        <?php foreach (getCategories() as $category): ?>
            <option value="<?= h($category['code']) ?>" <?= $cat === $category['code'] ? 'selected' : '' ?>>
                <?= h($category['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <span class="search-panel__label">기간:</span>
    <input type="date" name="from" value="<?= h($from) ?>">
    <span class="search-panel__label">~</span>
    <input type="date" name="to" value="<?= h($to) ?>">
    <button type="submit" class="btn btn-primary btn-sm">검색</button>
    <a href="search.php" class="btn btn-sm">초기화</a>
</form>

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
        <?php if (empty($posts)): ?>
            <tr class="empty-row">
                <td colspan="7"><?= $q !== '' ? '검색 결과가 없습니다.' : '검색어를 입력해 주세요.' ?></td>
            </tr>
        <?php else: ?>
            <?php $rowNum = $total - $offset; ?>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td><?= $rowNum-- ?></td>
                    <td><span class="cat-badge"><?= h($post['cat_name']) ?></span></td>
                    <td class="title-cell">
                        <a href="view.php?id=<?= (int)$post['id'] ?>" class="post-title"><?= h($post['title']) ?></a>
                        <?php if ($post['comment_count'] > 0): ?>
                            <span class="comment-count">[<?= (int)$post['comment_count'] ?>]</span>
                        <?php endif; ?>
                        <?php if ($post['attach_cnt'] > 0): ?>
                            <span class="attach-icon">첨부</span>
                        <?php endif; ?>
                        <div class="post-summary"><?= h(summarize($post['content'], 120)) ?></div>
                    </td>
                    <td>
                        <?php if ($post['author_dept']): ?>
                            <span class="author-dept"><?= h($post['author_dept']) ?></span>
                        <?php endif; ?>
                        <span class="author-name"><?= h($post['author_name']) ?></span>
                    </td>
                    <td><?= dateFormat($post['created_at'], 'Y-m-d') ?></td>
                    <td><?= number_format($post['views']) ?></td>
                    <td><?= number_format($post['like_count']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php
$queryString = http_build_query(array_filter([
    'q' => $q,
    'field' => $field,
    'cat' => $cat,
    'from' => $from,
    'to' => $to,
]));
$urlPattern = 'search.php?' . ($queryString !== '' ? $queryString . '&' : '') . 'page=%d';
echo paginate($total, $page, POSTS_PER_PAGE, $urlPattern);
?>

<?php require_once 'includes/footer.php'; ?>
