<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$user = requireLogin();
checkCsrf($_GET['csrf'] ?? '');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('잘못된 접근');

$stmt = db()->prepare("SELECT * FROM posts WHERE id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) die('게시글을 찾을 수 없습니다.');

if ($post['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
    die('삭제 권한이 없습니다.');
}

// 첨부파일 물리 삭제
$atts = getAttachments($id);
foreach ($atts as $att) {
    deleteAttachmentPhysicalFile($att);
}

try {
    db()->beginTransaction();
    db()->prepare("DELETE FROM near_miss_photo_links WHERE post_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM attachments WHERE post_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM comments WHERE post_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM likes WHERE post_id = ?")->execute([$id]);
    db()->prepare("DELETE pv FROM poll_votes pv JOIN polls p ON pv.poll_id = p.id WHERE p.post_id = ?")->execute([$id]);
    db()->prepare("DELETE po FROM poll_options po JOIN polls p ON po.poll_id = p.id WHERE p.post_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM polls WHERE post_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
    db()->commit();
} catch (Exception $e) {
    db()->rollBack();
    die('삭제 실패: ' . (DEBUG ? $e->getMessage() : ''));
}

header('Location: index.php');
exit;
