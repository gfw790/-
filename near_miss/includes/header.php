<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

ensureNearMissSchema();
$_currentUser = getCurrentUser();
$_pageTitle   = $_pageTitle ?? BOARD_TITLE;
$_boardCssVersion = (string)@filemtime(__DIR__ . '/../assets/css/style.css');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<title><?= h($_pageTitle) ?> - <?= h(BOARD_TITLE) ?></title>
<link rel="stylesheet" href="assets/css/style.css<?= $_boardCssVersion !== '' ? '?v=' . h($_boardCssVersion) : '' ?>">
</head>
<body>
<div class="board-wrap">

  <header class="board-header">
    <div class="header-inner">
      <h1 class="board-logo">
        <a href="index.php"><?= h(BOARD_TITLE) ?></a>
      </h1>
      <div class="header-user">
        <?php if ($_currentUser): ?>
          <span class="user-info">
            <?php if ($_currentUser['dept']): ?><span class="user-dept"><?= h($_currentUser['dept']) ?></span><?php endif; ?>
            <span class="user-name"><?= h($_currentUser['name']) ?></span>
            <?php if (!empty($_currentUser['sys_role_label'])): ?>
              <span class="user-badge"><?= h($_currentUser['sys_role_label']) ?></span>
            <?php endif; ?>
          </span>
          <?php if ($_currentUser['role'] === 'admin'): ?>
            <a href="admin.php" class="btn-link">관리</a>
          <?php endif; ?>
          <a href="../board/" class="btn-link">게시판</a>
          <a href="../risk_assessment/task_select.php" class="btn-link">메인으로</a>
        <?php else: ?>
          <span class="user-info">비로그인</span>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <nav class="board-nav">
    <div class="nav-inner">
      <ul class="cat-tabs">
        <li class="active"><a href="index.php">아차사고 목록</a></li>
      </ul>
    </div>
  </nav>

  <main class="board-main">
