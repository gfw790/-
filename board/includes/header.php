<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

ensureNearMissSchema();
ensureHandoverCategory();
$_currentUser = getCurrentUser();

// 가스팀 여부 판별
$_userDept   = (string)($_currentUser['dept'] ?? '');
$_isGasTeam  = (mb_strpos($_userDept, '가스') !== false);
$_canSeeRevisionRequestTab = (($_currentUser['role'] ?? '') === 'admin') || (($_currentUser['original_role'] ?? '') === 'safety_manager');

// 팀에 따라 카테고리 필터링
// - 가스팀: '도면자료실'(dwg) 제외, '인계사항'(handover) 포함
// - 그 외:  '인계사항'(handover) 제외, '도면자료실'(dwg) 포함
$_categories = array_values(array_filter(getCategories(), function($cat) use ($_isGasTeam, $_currentUser, $_canSeeRevisionRequestTab) {
    $code = $cat['code'] ?? '';
    if ($_isGasTeam && $code === 'dwg') return false;
    if (!$_isGasTeam && $code === 'handover' && ($_currentUser['role'] ?? '') !== 'admin') return false;
  if ($code === 'revision_request' && !$_canSeeRevisionRequestTab) return false;
    return true;
}));
$_currentPage = basename($_SERVER['PHP_SELF'] ?? '');
if (isset($_GET['cat'])) {
  $_currentCat = (string)$_GET['cat'];
} else {
  $_currentCat = $_currentPage === 'index.php' ? 'notice' : '';
}
$_pageTitle   = $_pageTitle ?? BOARD_TITLE;
$_boardCssVersion = (string)@filemtime(__DIR__ . '/../assets/css/style.css');
$_mainPageHref = function_exists('auth_main_page_path') ? '../risk_assessment/' . auth_main_page_path($_currentUser) : '../risk_assessment/task_select.php';
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
          <?php if (in_array($_currentUser['role'] ?? '', ['admin', 'administrator'], true)): ?>
            <a href="admin.php" class="btn-link">관리</a>
          <?php endif; ?>
          <a href="../near_miss/" class="btn-link">아차사고</a>
          <a href="<?= h($_mainPageHref) ?>" class="btn-link">메인으로</a>
        <?php else: ?>
          <span class="user-info">비로그인</span>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <nav class="board-nav">
    <div class="nav-inner">
      <ul class="cat-tabs">
        <?php $allInserted = false; ?>
        <?php foreach ($_categories as $cat): ?>
          <?php if (($cat['code'] ?? '') === 'near_miss') continue; ?>
          <?php $tabLabel = ($cat['code'] ?? '') === 'revision_request' ? '평가서수정' : ($cat['name'] ?? ''); ?>
          <li class="<?= $_currentCat === $cat['code'] ? 'active' : '' ?>">
            <a href="index.php?cat=<?= h($cat['code']) ?>"><?= h($tabLabel) ?></a>
          </li>
          <?php if (in_array($tabLabel, ['평가서수정', '수정요청'], true) && !$allInserted): ?>
            <li class="<?= $_currentCat === 'all' ? 'active' : '' ?>">
              <a href="index.php?cat=all">전체</a>
            </li>
            <?php $allInserted = true; ?>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$allInserted): ?>
          <li class="<?= $_currentCat === 'all' ? 'active' : '' ?>">
            <a href="index.php?cat=all">전체</a>
          </li>
        <?php endif; ?>
      </ul>
      <form class="nav-search" method="get" action="search.php">
        <select name="field">
          <option value="all">전체</option>
          <option value="title">제목</option>
          <option value="content">내용</option>
          <option value="author">작성자</option>
        </select>
        <input type="text" name="q" placeholder="검색어를 입력하세요" value="<?= h($_GET['q'] ?? '') ?>">
        <button type="submit">검색</button>
      </form>
    </div>
  </nav>

  <main class="board-main">

