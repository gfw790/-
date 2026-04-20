<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

function markRevisionRequestPostCompleted(int $postId): void
{
    if ($postId <= 0) {
        return;
    }

    $stmt = db()->prepare(
        "SELECT p.id, p.title, c.code AS category_code, c.name AS category_name
         FROM posts p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ?
         LIMIT 1"
    );
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if (!$post) {
        return;
    }

    $title = trim((string)($post['title'] ?? ''));
    $categoryCode = trim((string)($post['category_code'] ?? ''));
    $categoryName = trim((string)($post['category_name'] ?? ''));
    $isRevisionCategory = $categoryCode === 'revision_request'
        || in_array($categoryName, ['수정요청', '평가서수정'], true);

    if (!$isRevisionCategory || $title === '') {
        return;
    }

    if ((function_exists('mb_strpos') && mb_strpos($title, '[수정요청]', 0, 'UTF-8') === false)
        || (function_exists('mb_strpos') && mb_strpos($title, '[수정완료]', 0, 'UTF-8') !== false)) {
        if (!function_exists('mb_strpos')) {
            if (strpos($title, '[수정요청]') === false || strpos($title, '[수정완료]') !== false) {
                return;
            }
        } else {
            return;
        }
    }

    $updatedTitle = preg_replace('/\[수정요청\]/u', '[수정완료]', $title, 1) ?? $title;
    if ($updatedTitle === $title) {
        return;
    }

    db()->prepare("UPDATE posts SET title = ? WHERE id = ?")->execute([$updatedTitle, $postId]);
}

$user = requireLogin();
checkCsrf($_POST['csrf'] ?? '');

$action = $_POST['action'] ?? 'create';

header('Content-Type: application/json; charset=utf-8');

if ($action === 'delete') {
    $cid = (int)($_POST['comment_id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$cid]);
    $cmt = $stmt->fetch();
    if (!$cmt) { echo json_encode(['ok' => false, 'message' => '댓글이 없습니다.']); exit; }
    if ($cmt['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'message' => '권한이 없습니다.']); exit;
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
markRevisionRequestPostCompleted($postId);
recalcCommentCount($postId);

header('Location: view.php?id=' . $postId . '#comments');
exit;
