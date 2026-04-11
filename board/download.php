<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('잘못된 접근입니다.');

$stmt = db()->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->execute([$id]);
$att = $stmt->fetch();
if (!$att) die('파일을 찾을 수 없습니다.');

$path = __DIR__ . '/uploads/' . $att['stored_name'];
if (!file_exists($path)) die('파일이 서버에서 삭제되었습니다.');

db()->prepare("UPDATE attachments SET download_count = download_count + 1 WHERE id = ?")->execute([$id]);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($att['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

readfile($path);
exit;
