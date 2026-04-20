<?php
require_once 'includes/header.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('잘못된 접근입니다.');
}

if (empty($_SESSION['viewed_posts'][$id])) {
    db()->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([$id]);
    $_SESSION['viewed_posts'][$id] = true;
}

$stmt = db()->prepare(
    "SELECT p.*, c.name AS cat_name, c.code AS cat_code
     FROM posts p
     JOIN categories c ON p.category_id = c.id
     WHERE p.id = ?"
);
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    die('게시글을 찾을 수 없습니다.');
}

$attachments = getAttachments($id);

$liked = false;
if ($_currentUser) {
    $stmt = db()->prepare("SELECT 1 FROM likes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$id, $_currentUser['id']]);
    $liked = (bool)$stmt->fetchColumn();
}

$pollStmt = db()->prepare("SELECT * FROM polls WHERE post_id = ?");
$pollStmt->execute([$id]);
$poll = $pollStmt->fetch();
$pollOptions = [];
$pollVotedOptionIds = [];
$pollTotalVoters = 0;
if ($poll) {
    $optStmt = db()->prepare("SELECT * FROM poll_options WHERE poll_id = ? ORDER BY sort_order, id");
    $optStmt->execute([$poll['id']]);
    $pollOptions = $optStmt->fetchAll();

    foreach ($pollOptions as &$opt) {
        $cnt = db()->prepare("SELECT COUNT(*) FROM poll_votes WHERE option_id = ?");
        $cnt->execute([$opt['id']]);
        $opt['votes'] = (int)$cnt->fetchColumn();
    }
    unset($opt);

    $totVoter = db()->prepare("SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = ?");
    $totVoter->execute([$poll['id']]);
    $pollTotalVoters = (int)$totVoter->fetchColumn();

    if ($_currentUser) {
        $myVote = db()->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
        $myVote->execute([$poll['id'], $_currentUser['id']]);
        $pollVotedOptionIds = array_map('intval', $myVote->fetchAll(PDO::FETCH_COLUMN));
    }
}

$cmtStmt = db()->prepare(
    "SELECT * FROM comments
     WHERE post_id = ? AND is_deleted = 0
     ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, id"
);
$cmtStmt->execute([$id]);
$comments = $cmtStmt->fetchAll();

$canEdit = $_currentUser && ($_currentUser['id'] === $post['author_id'] || $_currentUser['role'] === 'admin');
$pageTitle = $post['title'];
?>

<h2 class="page-title">
    <span><?= h($post['cat_name']) ?></span>
    <span class="sub"><a href="index.php?cat=<?= h($post['cat_code']) ?>">목록</a></span>
</h2>

<article class="post-view">
    <header class="post-view-head">
        <h1 class="title">
            <?php if ($post['is_notice']): ?><span class="notice-tag">공지</span><?php endif; ?>
            <?= h($post['title']) ?>
        </h1>
        <div class="post-meta">
            <span class="meta-item">
                <?php if ($post['author_dept']): ?><?= h($post['author_dept']) ?> <?php endif; ?>
                <strong><?= h($post['author_name']) ?></strong>
            </span>
            <span class="meta-item"><?= dateFormat($post['created_at'], 'Y-m-d H:i') ?></span>
            <?php if ($post['updated_at'] !== $post['created_at']): ?>
                <span class="meta-item">수정: <?= dateFormat($post['updated_at'], 'Y-m-d H:i') ?></span>
            <?php endif; ?>
            <span class="meta-item">조회 <strong><?= number_format($post['views']) ?></strong></span>
            <span class="meta-item">추천 <strong><?= number_format($post['like_count']) ?></strong></span>
            <span class="meta-item">댓글 <strong><?= number_format($post['comment_count']) ?></strong></span>
        </div>
    </header>

    <div class="post-view-body">
        <?= renderContent($post['content'], $attachments) ?>
    </div>

    <?php if ($poll): ?>
        <?php
        $isClosed = $poll['closes_at'] && strtotime($poll['closes_at']) < time();
        $hasVoted = !empty($pollVotedOptionIds);
        $maxVotes = max(1, max(array_column($pollOptions, 'votes')));
        ?>
        <div class="poll-box">
            <div class="poll-question">
                투표: <?= h($poll['question']) ?>
                <span class="meta">
                    (<?= $poll['multi_select'] ? '복수선택' : '단일선택' ?>
                    <?php if ($poll['is_anonymous']): ?> · 익명<?php endif; ?>
                    <?php if ($poll['closes_at']): ?> · <?= $isClosed ? '마감' : '마감 ' . dateFormat($poll['closes_at']) ?><?php endif; ?>
                    · 참여 <?= $pollTotalVoters ?>명)
                </span>
            </div>
            <form method="post" action="vote.php">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                <input type="hidden" name="post_id" value="<?= (int)$id ?>">
                <ul class="poll-options">
                    <?php foreach ($pollOptions as $opt): ?>
                        <?php $pct = $pollTotalVoters > 0 ? round($opt['votes'] / $maxVotes * 100) : 0; ?>
                        <li class="poll-option <?= in_array($opt['id'], $pollVotedOptionIds, true) ? 'voted-mine' : '' ?>">
                            <div class="poll-result-bar" style="width: <?= $pct ?>%"></div>
                            <?php if (!$hasVoted && !$isClosed && $_currentUser): ?>
                                <input type="<?= $poll['multi_select'] ? 'checkbox' : 'radio' ?>" name="option_ids[]" value="<?= (int)$opt['id'] ?>">
                            <?php endif; ?>
                            <span><?= h($opt['option_text']) ?></span>
                            <span class="poll-percent"><?= $opt['votes'] ?>표</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!$hasVoted && !$isClosed && $_currentUser): ?>
                    <button type="submit" class="btn btn-primary btn-sm poll-vote-btn">투표하기</button>
                <?php elseif ($hasVoted): ?>
                    <p class="poll-count" style="margin-top:10px;">이미 투표에 참여했습니다.</p>
                <?php elseif ($isClosed): ?>
                    <p class="poll-count" style="margin-top:10px;">투표가 마감되었습니다.</p>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!empty($attachments)): ?>
        <?php
        $imageAttachments = array_values(array_filter($attachments, 'isImageAttachment'));
        $fileAttachments = array_values(array_filter($attachments, static fn($att) => !isImageAttachment($att)));
        ?>
        <div class="post-attachments">
            <span class="label">첨부파일 (<?= count($attachments) ?>)</span>

            <?php if (!empty($imageAttachments)): ?>
                <div class="post-attachment-images">
                    <?php foreach ($imageAttachments as $att): ?>
                        <?php
                        $downloadUrl = 'download.php?id=' . (int)$att['id'];
                        $imageSrc = attachmentInlineUrl($att);
                        ?>
                        <figure class="post-attachment-image">
                            <a href="<?= h($downloadUrl) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="<?= h($imageSrc) ?>" alt="<?= h($att['original_name']) ?>" loading="lazy">
                            </a>
                            <figcaption>
                                <a href="<?= h($downloadUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($att['original_name']) ?></a>
                                <span class="attach-size">(<?= formatBytes($att['file_size']) ?>)</span>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($fileAttachments)): ?>
                <ul>
                    <?php foreach ($fileAttachments as $att): ?>
                        <li>
                            <a href="download.php?id=<?= (int)$att['id'] ?>"><?= h($att['original_name']) ?></a>
                            <span class="attach-size">(<?= formatBytes($att['file_size']) ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="post-actions">
        <?php if ($_currentUser): ?>
            <button type="button" class="like-btn <?= $liked ? 'liked' : '' ?>" data-post-id="<?= (int)$id ?>">
                <span class="icon">❤</span>
                <span>추천</span>
                <span class="like-count"><?= (int)$post['like_count'] ?></span>
            </button>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <div>
            <?php if ($canEdit): ?>
                <a href="write.php?id=<?= (int)$id ?>" class="btn btn-sm">수정</a>
                <a href="delete.php?id=<?= (int)$id ?>&csrf=<?= h(csrfToken()) ?>"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('정말 삭제하시겠습니까?');">삭제</a>
            <?php endif; ?>
            <a href="index.php?cat=<?= h($post['cat_code']) ?>" class="btn btn-sm">목록</a>
        </div>
    </div>
</article>

<section class="comment-section" id="comments">
    <h3>댓글 <span class="count"><?= (int)$post['comment_count'] ?></span></h3>

    <ul class="comment-list">
        <?php
        $parents = [];
        $children = [];
        foreach ($comments as $c) {
            if ($c['parent_id']) {
                $children[$c['parent_id']][] = $c;
            } else {
                $parents[] = $c;
            }
        }
        ?>

        <?php foreach ($parents as $c): ?>
            <li class="comment-item">
                <div class="comment-head">
                    <span class="comment-author">
                        <?php if ($c['author_dept']): ?><span class="dept"><?= h($c['author_dept']) ?></span><?php endif; ?>
                        <?= h($c['author_name']) ?>
                    </span>
                    <span class="comment-date"><?= timeAgo($c['created_at']) ?></span>
                </div>
                <div class="comment-body"><?= h($c['content']) ?></div>

                <?php if ($_currentUser): ?>
                    <div class="comment-actions">
                        <a class="reply-toggle" data-comment-id="<?= (int)$c['id'] ?>">답글</a>
                        <?php if ($_currentUser['id'] === $c['author_id'] || $_currentUser['role'] === 'admin'): ?>
                            <a class="comment-delete" data-comment-id="<?= (int)$c['id'] ?>">삭제</a>
                        <?php endif; ?>
                    </div>
                    <div id="reply-form-<?= (int)$c['id'] ?>" class="comment-form" style="display:none;margin-top:8px;">
                        <form method="post" action="comment.php">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="post_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="parent_id" value="<?= (int)$c['id'] ?>">
                            <textarea name="content" placeholder="답글 내용을 입력해 주세요" required></textarea>
                            <div class="form-bottom">
                                <span class="info"></span>
                                <button class="btn btn-primary btn-sm">답글 등록</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($children[$c['id']])): ?>
                    <?php foreach ($children[$c['id']] as $rc): ?>
                        <div class="comment-item is-reply" style="border:none;margin-top:8px;">
                            <div class="comment-head">
                                <span class="comment-author">
                                    <?php if ($rc['author_dept']): ?><span class="dept"><?= h($rc['author_dept']) ?></span><?php endif; ?>
                                    <?= h($rc['author_name']) ?>
                                </span>
                                <span class="comment-date"><?= timeAgo($rc['created_at']) ?></span>
                            </div>
                            <div class="comment-body"><?= h($rc['content']) ?></div>
                            <?php if ($_currentUser && ($_currentUser['id'] === $rc['author_id'] || $_currentUser['role'] === 'admin')): ?>
                                <div class="comment-actions">
                                    <a class="comment-delete" data-comment-id="<?= (int)$rc['id'] ?>">삭제</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>

        <?php if (empty($parents)): ?>
            <li class="comment-empty">첫 번째 댓글을 남겨보세요.</li>
        <?php endif; ?>
    </ul>

    <?php if ($_currentUser): ?>
        <form class="comment-form" method="post" action="comment.php">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="post_id" value="<?= (int)$id ?>">
            <textarea name="content" placeholder="댓글 내용을 입력해 주세요" required></textarea>
            <div class="form-bottom">
                <span class="info"><?= h($_currentUser['name']) ?>님으로 댓글이 등록됩니다.</span>
                <button class="btn btn-primary btn-sm">수정완료</button>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-info">댓글을 작성하려면 로그인이 필요합니다.</div>
    <?php endif; ?>
</section>

<?php require_once 'includes/footer.php'; ?>
