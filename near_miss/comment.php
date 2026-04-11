<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$user = requireLogin();
checkCsrf($_POST['csrf'] ?? '');

$action = $_POST['action'] ?? 'create';

header('Content-Type: application/json; charset=utf-8');

if ($action === 'delete') {
    $cid = (int)($_POST['comment_id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$cid]);
    $cmt = $stmt->fetch();
    if (!$cmt) { echo json_encode(['ok' => false, 'message' => '댓글 없음']); exit; }
    if ($cmt['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'message' => '권한 없음']); exit;
    }
    db()->prepare("UPDATE comments SET is_deleted = 1 WHERE id = ?")->execute([$cid]);
    recalcCommentCount($cmt['post_id']);
    echo json_encode(['ok' => true]);
    exit;
}

// create
$postId  = (int)($_POST['post_id'] ?? 0);
$content = trim($_POST['content'] ?? '');
$parent  = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

if ($postId <= 0 || $content === '') {
    header('Location: view.php?id=' . $postId);
    exit;
}

$stmt = db()->prepare(
    "INSERT INTO comments (post_id, parent_id, content, author_id, author_name, author_dept)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->execute([$postId, $parent, $content, $user['id'], $user['name'], $user['dept']]);
recalcCommentCount($postId);

header('Location: view.php?id=' . $postId . '#comments');
exit;
