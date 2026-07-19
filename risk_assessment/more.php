<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
$registrationOpen = auth_is_worker_registration_open();
$isAdmin = auth_is_admin($user);
$canManage = auth_can_manage($user);
$canLead = auth_can_lead($user);
$isWorker = auth_is_worker($user);
$displayName = trim((string)auth_display_name($user));
$loginId = trim((string)($user['login_id'] ?? ''));
$primaryHref = $isWorker
    ? 'work_list.php'
    : ($canLead && !$canManage ? 'leader_task_select.php' : 'task_select.php');
$logoutHref = $canLead && !$canManage ? 'leader_task_select.php?logout=1' : 'task_select.php?logout=1';
$canAccessMyGearTest = $displayName === '김남균';
$canAccessSafetyGearManagement = $canManage && $loginId !== '6680';
$canAccessEmploymentRules = in_array($loginId, ['5878', '2316', '7204', '6680'], true);
$showMaterialManagement = $displayName === '김남균' && $canAccessSafetyGearManagement;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$menuItems = [
    ['label' => '홈', 'href' => 'index.php', 'desc' => '메인 화면으로 이동합니다.'],
    ['label' => '달력', 'href' => '../calendar/index.html', 'desc' => '일정과 날씨를 확인합니다.'],
    ['label' => '작업목록', 'href' => 'work_list.php', 'desc' => '등록된 작업 목록을 확인합니다.'],
    ['label' => 'MSDS', 'href' => 'msds_list.php', 'desc' => '물질안전보건자료를 등록하고 다운로드합니다.'],
    ['label' => '게시판', 'href' => '../board/index.php', 'desc' => '공지와 게시글을 확인합니다.'],
    ['label' => '작업 선택', 'href' => $primaryHref, 'desc' => '현재 권한에 맞는 작업 화면으로 이동합니다.'],
    ['label' => '아차사고', 'href' => '../near_miss/', 'desc' => '아차사고 등록 및 조회 페이지입니다.'],
];

if ($registrationOpen) {
    $menuItems[] = ['label' => '작업자 등록', 'href' => 'register_worker.php', 'desc' => '작업자 계정을 등록합니다.'];
}
if ($canAccessMyGearTest) {
    $menuItems[] = ['label' => '나의 보호구', 'href' => '/safety_gear/my_gear.php', 'desc' => '개인 보호구 정보를 확인합니다.'];
}
if ($canAccessEmploymentRules) {
    $menuItems[] = ['label' => '취업규칙', 'href' => '/employment_rules/index.php', 'desc' => '취업규칙 페이지를 엽니다.'];
}
if ($canAccessSafetyGearManagement) {
    $menuItems[] = ['label' => '보호구관리', 'href' => '/safety_gear/index.php', 'desc' => '보호구 관리 화면을 엽니다.'];
}
if ($showMaterialManagement) {
    $menuItems[] = ['label' => '물질관리', 'href' => '/material_management/index.php', 'desc' => '물질 관리 화면을 엽니다.'];
}
if ($isAdmin) {
    $menuItems[] = ['label' => '게시판관리', 'href' => '../board/admin.php', 'desc' => '게시판 관리 페이지로 이동합니다.'];
}
if ($user !== null) {
    $menuItems[] = ['label' => '로그아웃', 'href' => $logoutHref, 'desc' => '현재 계정에서 로그아웃합니다.', 'danger' => true];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>더보기</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg-1: #edf4fb;
    --bg-2: #f8fbfe;
    --panel: rgba(255,255,255,0.96);
    --line: #d7e4ef;
    --text: #18364f;
    --text-soft: #647d94;
    --danger-soft: #fff1f3;
    --danger-line: #f3c8d0;
    --danger-text: #b4234b;
    --shadow: 0 20px 50px rgba(18, 48, 74, 0.12);
  }
  body {
    min-height: 100vh;
    font-family: "Malgun Gothic", sans-serif;
    color: var(--text);
    background:
      radial-gradient(circle at top left, rgba(29, 95, 146, 0.16), transparent 28%),
      linear-gradient(180deg, var(--bg-1) 0%, var(--bg-2) 100%);
    padding: 20px 14px 118px;
  }
  .shell {
    max-width: 760px;
    margin: 0 auto;
    display: grid;
    gap: 16px;
  }
  .hero {
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(16, 56, 88, 0.96), rgba(31, 96, 146, 0.92));
    color: #ffffff;
    padding: 24px 20px;
    box-shadow: var(--shadow);
  }
  .hero h1 {
    font-size: 27px;
    line-height: 1.25;
    margin-bottom: 8px;
  }
  .hero p {
    color: rgba(255,255,255,0.84);
    line-height: 1.65;
    font-size: 14px;
  }
  .menu-grid {
    display: grid;
    gap: 12px;
  }
  .menu-link {
    display: block;
    text-decoration: none;
    color: inherit;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 22px;
    padding: 18px;
    box-shadow: 0 10px 24px rgba(18, 48, 74, 0.06);
  }
  .menu-link.is-danger {
    background: var(--danger-soft);
    border-color: var(--danger-line);
    color: var(--danger-text);
  }
  .menu-link strong {
    display: block;
    font-size: 17px;
    margin-bottom: 6px;
  }
  .menu-link span {
    display: block;
    font-size: 13px;
    line-height: 1.6;
    color: var(--text-soft);
  }
  .menu-link.is-danger span {
    color: rgba(180, 35, 75, 0.82);
  }
  .mobile-bottom-nav {
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: max(10px, env(safe-area-inset-bottom));
    z-index: 1000;
    border: 1px solid rgba(24, 59, 86, 0.10);
    border-radius: 20px;
    background: rgba(255,255,255,0.96);
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 40px rgba(17, 52, 77, 0.18);
    padding: 8px;
  }
  .mobile-bottom-nav-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
  }
  .mobile-nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    border-radius: 14px;
    border: 1px solid transparent;
    color: #45627b;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
  }
  .mobile-nav-link.is-active {
    background: linear-gradient(180deg, rgba(35,104,162,0.14), rgba(35,104,162,0.08));
    border-color: rgba(35,104,162,0.18);
    color: #17486f;
  }
  .mobile-nav-icon { font-size: 14px; line-height: 1; }
</style>
</head>
<body>
  <div class="shell">
    <section class="hero">
      <h1>더보기</h1>
      <p>자주 여는 추가 메뉴와 바로가기를 한곳에 모아두었습니다.</p>
    </section>

    <section class="menu-grid">
      <?php foreach ($menuItems as $item): ?>
        <a class="menu-link<?= !empty($item['danger']) ? ' is-danger' : '' ?>" href="<?= h($item['href']) ?>">
          <strong><?= h($item['label']) ?></strong>
          <span><?= h($item['desc']) ?></span>
        </a>
      <?php endforeach; ?>
    </section>
  </div>

  <nav class="mobile-bottom-nav" aria-label="모바일 하단 메뉴">
    <div class="mobile-bottom-nav-grid">
      <a class="mobile-nav-link" href="index.php">
        <span class="mobile-nav-icon">홈</span>
        <span>홈</span>
      </a>
      <a class="mobile-nav-link" href="../calendar/index.html">
        <span class="mobile-nav-icon">달력</span>
        <span>달력</span>
      </a>
      <a class="mobile-nav-link" href="work_list.php">
        <span class="mobile-nav-icon">목록</span>
        <span>목록</span>
      </a>
      <a class="mobile-nav-link" href="../board/index.php">
        <span class="mobile-nav-icon">게시판</span>
        <span>게시판</span>
      </a>
      <a class="mobile-nav-link is-active" href="more.php">
        <span class="mobile-nav-icon">더보기</span>
        <span>더보기</span>
      </a>
    </div>
  </nav>
</body>
</html>
