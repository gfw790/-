<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
if (!$user) { echo json_encode(['ok' => false, 'message' => '로그인 필요']); exit; }

checkCsrf($_POST['csrf'] ?? '');

$postId = (int)($_POST['post_id'] ?? 0);
if ($postId <= 0) { echo json_encode(['ok' => false, 'message' => '잘못된 요청']); exit; }

$stmt = db()->prepare("SELECT 1 FROM likes WHERE post_id = ? AND user_id = ?");
$stmt->execute([$postId, $user['id']]);
$exists = (bool)$stmt->fetchColumn();

if ($exists) {
    db()->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?")->execute([$postId, $user['id']]);
    $liked = false;
} else {
    db()->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?)")->execute([$postId, $user['id']]);
    $liked = true;
}
recalcLikeCount($postId);

$cnt = db()->prepare("SELECT like_count FROM posts WHERE id = ?");
$cnt->execute([$postId]);
echo json_encode(['ok' => true, 'liked' => $liked, 'count' => (int)$cnt->fetchColumn()]);
