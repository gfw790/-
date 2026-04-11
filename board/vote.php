<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$user = requireLogin();
checkCsrf($_POST['csrf'] ?? '');

$pollId  = (int)($_POST['poll_id'] ?? 0);
$postId  = (int)($_POST['post_id'] ?? 0);
$options = $_POST['option_ids'] ?? [];

if ($pollId <= 0 || empty($options)) {
    header('Location: view.php?id=' . $postId);
    exit;
}

$stmt = db()->prepare("SELECT * FROM polls WHERE id = ?");
$stmt->execute([$pollId]);
$poll = $stmt->fetch();
if (!$poll) die('투표를 찾을 수 없습니다.');

if ($poll['closes_at'] && strtotime($poll['closes_at']) < time()) {
    die('투표가 마감되었습니다.');
}

// 이미 투표했는지 확인
$check = db()->prepare("SELECT COUNT(*) FROM poll_votes WHERE poll_id = ? AND user_id = ?");
$check->execute([$pollId, $user['id']]);
if ((int)$check->fetchColumn() > 0) {
    header('Location: view.php?id=' . $postId);
    exit;
}

// 단일 선택은 첫 번째만
if (!$poll['multi_select']) {
    $options = [(int)$options[0]];
}

$ins = db()->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)");
foreach ($options as $optId) {
    $optId = (int)$optId;
    // 옵션이 해당 투표에 속하는지 확인
    $vs = db()->prepare("SELECT 1 FROM poll_options WHERE id = ? AND poll_id = ?");
    $vs->execute([$optId, $pollId]);
    if ($vs->fetchColumn()) {
        try { $ins->execute([$pollId, $optId, $user['id']]); }
        catch (PDOException $e) { /* 중복 무시 */ }
    }
}

header('Location: view.php?id=' . $postId);
exit;
