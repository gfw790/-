<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
$canManage = auth_can_manage($user);
$canLead = auth_can_lead($user);
$isWorker = auth_is_worker($user);
$registrationOpen = auth_is_worker_registration_open();
$mobileRegisterHref = $isWorker
    ? 'work_list.php'
    : ($canLead && !$canManage ? 'leader_task_select.php' : 'task_select.php');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$statusTitle = '로그인이 필요합니다.';
$statusBody = '아래 목차에서 원하는 화면으로 바로 이동할 수 있습니다.';
$primaryAction = ['label' => '로그인 화면 열기', 'href' => 'task_select.php'];
$secondaryAction = ['label' => '작업지휘자 화면', 'href' => 'leader_task_select.php'];
$logoutAction = null;

if ($user !== null) {
    $statusTitle = auth_display_name($user) . '님이 로그인 중입니다.';
    $statusBody = h((string)($user['role_label'] ?? auth_role_label((string)($user['role'] ?? '')))) . ' 권한으로 사용할 수 있는 화면을 바로 열 수 있습니다.';

  if ((string)($user['role'] ?? '') === 'safety_manager') {
    $primaryAction = ['label' => '작업 목록', 'href' => 'work_list.php'];
    $secondaryAction = ['label' => '관리감독자 시작', 'href' => 'task_select.php'];
    $logoutAction = ['label' => '로그아웃', 'href' => 'task_select.php?logout=1'];
  } elseif ($canManage) {
        $primaryAction = ['label' => '관리감독자 시작', 'href' => 'task_select.php'];
        $secondaryAction = ['label' => '작업 목록', 'href' => 'work_list.php'];
        $logoutAction = ['label' => '로그아웃', 'href' => 'task_select.php?logout=1'];
    } elseif ($canLead) {
        $primaryAction = ['label' => '작업지휘자 시작', 'href' => 'leader_task_select.php'];
        $secondaryAction = ['label' => '작업 목록', 'href' => 'work_list.php'];
        $logoutAction = ['label' => '로그아웃', 'href' => 'leader_task_select.php?logout=1'];
    } elseif ($isWorker) {
        $primaryAction = ['label' => '작업 목록', 'href' => 'work_list.php'];
        $secondaryAction = ['label' => '로그인 화면', 'href' => 'task_select.php'];
        $logoutAction = ['label' => '로그아웃', 'href' => 'task_select.php?logout=1'];
    }
}

$sections = [
    [
        'title' => '업무 시작',
        'description' => '실제 업무를 시작할 때 가장 자주 들어가는 화면입니다.',
        'items' => [
            [
                'title' => '관리감독자 시작',
                'href' => 'task_select.php',
                'description' => '로그인, 작업 선택, 작업보고서 작성과 위험성평가 진행의 기본 진입 화면입니다.',
                'badge' => '기본',
            ],
            [
                'title' => '작업지휘자 시작',
                'href' => 'leader_task_select.php',
                'description' => '작업지휘자 또는 작업반장 흐름으로 진입할 때 사용하는 전용 화면입니다.',
                'badge' => '리더',
            ],
            [
                'title' => '작업 목록',
                'href' => 'work_list.php',
                'description' => '저장된 작업보고서, 진행 상태, 후속 평가 화면으로 이어지는 목록입니다.',
                'badge' => '목록',
            ],
        ],
    ],
    [
        'title' => '단위 위험성평가 기준 관리',
        'description' => '기준서와 마스터 데이터를 등록하거나 조회할 때 사용하는 화면입니다.',
        'items' => [
            [
                'title' => '단위평가서 등록',
                'href' => 'form.html',
                'description' => '공정명, 대분류, 작업유형 기준으로 단위 위험성평가서 헤더를 등록합니다.',
                'badge' => '등록',
            ],
            [
                'title' => '단위평가서 목록',
                'href' => 'list.html',
                'description' => '등록된 단위 위험성평가서를 조회하고 관리하는 목록 화면입니다.',
                'badge' => '조회',
            ],
            [
                'title' => '엑셀 업로드',
                'href' => 'upload.html',
                'description' => '엑셀 파일을 이용해 단위 위험성평가 기준 데이터를 업로드합니다.',
                'badge' => '업로드',
            ],
        ],
    ],
    [
        'title' => '계정 및 운영',
        'description' => '사용자 계정과 가입 상태를 다루는 운영용 화면입니다.',
        'items' => [
            [
                'title' => '회원가입 및 계정관리',
                'href' => 'register_worker.php',
                'description' => $registrationOpen
                    ? '일반작업자 회원가입이 현재 열려 있습니다. 관리자는 계정도 함께 관리할 수 있습니다.'
                    : '관리자는 계정을 관리할 수 있고, 일반작업자 회원가입은 현재 닫혀 있습니다.',
                'badge' => $registrationOpen ? '가입 열림' : '가입 닫힘',
            ],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>위험성평가 시스템 목차</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg-top: #eef5fb;
    --bg-bottom: #f8fbfe;
    --panel: rgba(255,255,255,0.94);
    --line: #d5e3ef;
    --line-strong: #b9cfe2;
    --text: #183b56;
    --text-soft: #5f7890;
    --primary: #2368a2;
    --primary-strong: #17486f;
    --primary-soft: #e8f1f9;
    --accent: #2f855a;
    --shadow: 0 18px 50px rgba(17, 52, 77, 0.10);
  }
  body {
    min-height: 100vh;
    font-family: "Malgun Gothic", sans-serif;
    color: var(--text);
    background:
      radial-gradient(circle at top left, rgba(35, 104, 162, 0.16), transparent 28%),
      radial-gradient(circle at right 12% top 18%, rgba(26, 91, 140, 0.10), transparent 20%),
      linear-gradient(180deg, var(--bg-top) 0%, var(--bg-bottom) 100%);
    padding: 34px 18px 44px;
  }
  body.mobile-nav-open { overflow: hidden; }
  .shell {
    max-width: 1180px;
    margin: 0 auto;
  }
  .mobile-bottom-nav,
  .mobile-utility-sheet,
  .mobile-sheet-scrim { display: none; }
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
  .mobile-nav-link,
  .mobile-nav-button {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    border-radius: 14px;
    border: 1px solid transparent;
    background: transparent;
    color: #45627b;
    text-decoration: none;
    font: inherit;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
  }
  .mobile-nav-link.is-active,
  .mobile-nav-button.is-active {
    background: linear-gradient(180deg, rgba(35,104,162,0.14), rgba(35,104,162,0.08));
    border-color: rgba(35,104,162,0.18);
    color: #17486f;
  }
  .mobile-nav-icon { font-size: 18px; line-height: 1; }
  .mobile-sheet-scrim {
    position: fixed;
    inset: 0;
    z-index: 1001;
    background: rgba(15, 31, 45, 0.32);
  }
  .mobile-utility-sheet {
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: calc(max(10px, env(safe-area-inset-bottom)) + 84px);
    z-index: 1002;
    border: 1px solid rgba(24, 59, 86, 0.10);
    border-radius: 22px;
    background: rgba(255,255,255,0.98);
    box-shadow: 0 24px 48px rgba(17, 52, 77, 0.22);
    padding: 16px;
  }
  .mobile-sheet-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }
  .mobile-sheet-title {
    color: var(--primary-strong);
    font-size: 16px;
    font-weight: 800;
  }
  .mobile-sheet-close {
    border: none;
    background: rgba(24, 59, 86, 0.08);
    color: var(--primary-strong);
    border-radius: 999px;
    width: 34px;
    height: 34px;
    font-size: 18px;
    cursor: pointer;
  }
  .mobile-sheet-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
  .mobile-sheet-grid a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
  }
  .hero {
    background: linear-gradient(135deg, rgba(20, 66, 103, 0.96), rgba(38, 104, 156, 0.90));
    color: #fff;
    border-radius: 28px;
    padding: 28px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
  }
  .is-hidden {
    display: none !important;
  }
  .hero::after {
    content: "";
    position: absolute;
    inset: auto -60px -80px auto;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
  }
  .hero-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 18px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
  }
  .eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.76);
    margin-bottom: 12px;
  }
  .hero h1 {
    font-size: 32px;
    line-height: 1.24;
    margin-bottom: 12px;
  }
  .hero p {
    max-width: 680px;
    color: rgba(255,255,255,0.88);
    line-height: 1.7;
    font-size: 15px;
  }
  .hero-bar > div > p,
  .hero .hero-bar > div > p,
  .hero-bar > div > p:first-of-type {
    display: none !important;
    height: 0 !important;
    margin: 0 !important;
    overflow: hidden !important;
  }
  .status-panel {
    min-width: 280px;
    max-width: 340px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 22px;
    padding: 18px;
    backdrop-filter: blur(8px);
  }
  .status-label {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(255,255,255,0.16);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 12px;
  }
  .status-panel strong {
    display: block;
    font-size: 18px;
    line-height: 1.5;
    margin-bottom: 8px;
  }
  .status-panel p {
    font-size: 13px;
    line-height: 1.65;
    color: rgba(255,255,255,0.84);
  }
  .hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
  }
  .btn-primary,
  .btn-secondary,
  .btn-ghost,
  .card-link {
    text-decoration: none;
    font-family: inherit;
  }
  .btn-primary,
  .btn-secondary,
  .btn-ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    padding: 12px 18px;
    font-size: 14px;
    font-weight: 700;
  }
  .btn-primary {
    background: #fff;
    color: var(--primary-strong);
    border: 1px solid transparent;
  }
  .btn-primary:hover {
    background: #eef6ff;
  }
  .btn-secondary {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.24);
  }
  .btn-secondary:hover {
    background: rgba(255,255,255,0.18);
  }
  .btn-ghost {
    background: transparent;
    color: rgba(255,255,255,0.86);
    border: 1px solid rgba(255,255,255,0.22);
  }
  .btn-ghost:hover {
    background: rgba(255,255,255,0.08);
  }
  .guide {
    margin-top: 18px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 22px;
    padding: 18px 20px;
    box-shadow: 0 10px 24px rgba(17, 52, 77, 0.05);
  }
  .guide strong {
    display: block;
    font-size: 14px;
    margin-bottom: 8px;
    color: var(--primary-strong);
  }
  .guide p {
    color: var(--text-soft);
    line-height: 1.7;
    font-size: 14px;
  }
  .sections {
    margin-top: 28px;
    display: grid;
    gap: 18px;
  }
  .section-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 24px;
    padding: 22px;
    box-shadow: 0 12px 32px rgba(17, 52, 77, 0.06);
  }
  .section-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .section-head h2 {
    font-size: 22px;
    color: var(--primary-strong);
    margin-bottom: 6px;
  }
  .section-head p {
    color: var(--text-soft);
    line-height: 1.7;
    font-size: 14px;
    max-width: 760px;
  }
  .section-no {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: var(--primary-soft);
    color: var(--primary);
    font-weight: 800;
  }
  .card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
  }
  .card-link {
    display: block;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 18px;
    color: inherit;
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
    min-height: 170px;
  }
  .card-link:hover {
    transform: translateY(-3px);
    border-color: var(--line-strong);
    box-shadow: 0 14px 28px rgba(20, 65, 101, 0.10);
  }
  .card-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    background: #edf5fb;
    color: var(--primary);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 14px;
  }
  .card-link h3 {
    font-size: 18px;
    margin-bottom: 10px;
    color: #143e60;
  }
  .card-link p {
    color: var(--text-soft);
    line-height: 1.7;
    font-size: 14px;
  }
  .card-link .path {
    margin-top: 16px;
    font-size: 12px;
    color: #6d879d;
  }
  .foot-note {
    margin-top: 18px;
    padding: 16px 18px;
    border-radius: 18px;
    border: 1px dashed var(--line-strong);
    color: var(--text-soft);
    background: rgba(255,255,255,0.7);
    line-height: 1.7;
    font-size: 13px;
  }
  @media (max-width: 820px) {
    body { padding: 20px 14px 120px; }
    .hero { padding: 22px; border-radius: 24px; }
    .hero h1 { font-size: 27px; }
    .section-card { padding: 18px; }
    .section-head h2 { font-size: 19px; }
    .mobile-bottom-nav { display: block; }
  }
</style>
</head>
<body>
  <div class="shell">
    <section class="hero">
      <div class="hero-bar">
        <div>
          <div class="eyebrow">Risk Assessment Navigation</div>
          <h1>위험성평가 시스템 절차</h1>
          <div class="hero-actions">
            <a class="btn-primary" href="<?= h($primaryAction['href']) ?>"><?= h($primaryAction['label']) ?></a>
            <a class="btn-secondary" href="<?= h($secondaryAction['href']) ?>"><?= h($secondaryAction['label']) ?></a>
            <?php if ($logoutAction !== null): ?>
              <a class="btn-ghost" href="<?= h($logoutAction['href']) ?>"><?= h($logoutAction['label']) ?></a>
            <?php elseif ($registrationOpen): ?>
              <a class="btn-ghost" href="register_worker.php">회원가입</a>
            <?php endif; ?>
          </div>
        </div>
        <aside class="status-panel">
          <div class="status-label"><?= $user !== null ? '현재 접속 상태' : '접속 안내' ?></div>
          <strong><?= h($statusTitle) ?></strong>
          <p><?= $statusBody ?></p>
        </aside>
      </div>
    </section>

    <div class="guide is-hidden">
      <strong>안내</strong>
      <p>상세 작성 화면이나 인쇄 화면처럼 별도 선택값이 필요한 페이지는 이 목차에 직접 넣지 않았습니다. 그런 화면들은 `작업 선택`, `작업 목록`, `단위평가서 목록`에서 자연스럽게 이어서 들어가도록 구성하는 편이 안전합니다.</p>
    </div>

    <div class="sections is-hidden">
      <?php foreach ($sections as $index => $section): ?>
        <section class="section-card">
          <div class="section-head">
            <div>
              <h2><?= h($section['title']) ?></h2>
              <p><?= h($section['description']) ?></p>
            </div>
            <div class="section-no"><?= $index + 1 ?></div>
          </div>
          <div class="card-grid">
            <?php foreach ($section['items'] as $item): ?>
              <a class="card-link" href="<?= h($item['href']) ?>">
                <div class="card-badge"><?= h($item['badge']) ?></div>
                <h3><?= h($item['title']) ?></h3>
                <p><?= h($item['description']) ?></p>
                <div class="path"><?= h($item['href']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="foot-note is-hidden">
      루트 URL로 접속했을 때 바로 작업 선택 화면으로 보내던 기존 동작 대신, 이제는 이 목차 페이지가 먼저 열립니다. 필요하시면 다음 단계에서 여기에 검색, 최근 사용 화면, 관리자 전용 바로가기까지 이어서 붙일 수 있습니다.
    </div>
  </div>
  <div class="mobile-sheet-scrim" id="mobile-sheet-scrim" hidden></div>
  <div class="mobile-utility-sheet" id="mobile-utility-sheet" hidden>
    <div class="mobile-sheet-head">
      <div class="mobile-sheet-title">추가 메뉴</div>
      <button type="button" class="mobile-sheet-close" id="mobile-sheet-close" aria-label="닫기">&times;</button>
    </div>
    <div class="mobile-sheet-grid">
      <a class="btn-secondary" href="<?= h($primaryAction['href']) ?>"><?= h($primaryAction['label']) ?></a>
      <a class="btn-secondary" href="<?= h($secondaryAction['href']) ?>"><?= h($secondaryAction['label']) ?></a>
      <?php if ($registrationOpen): ?>
        <a class="btn-secondary" href="register_worker.php">회원가입</a>
      <?php endif; ?>
      <?php if ($logoutAction !== null): ?>
        <a class="btn-secondary" href="<?= h($logoutAction['href']) ?>"><?= h($logoutAction['label']) ?></a>
      <?php endif; ?>
    </div>
  </div>
  <nav class="mobile-bottom-nav" aria-label="모바일 하단 메뉴">
    <div class="mobile-bottom-nav-grid">
      <a class="mobile-nav-link is-active" href="index.php">
        <span class="mobile-nav-icon">⌂</span>
        <span>홈</span>
      </a>
      <a class="mobile-nav-link" href="../calendar/index.html">
        <span class="mobile-nav-icon">◫</span>
        <span>달력</span>
      </a>
      <a class="mobile-nav-link" href="work_list.php">
        <span class="mobile-nav-icon">≡</span>
        <span>목록</span>
      </a>
      <a class="mobile-nav-link" href="../board/index.php">
        <span class="mobile-nav-icon">▣</span>
        <span>게시판</span>
      </a>
      <button type="button" class="mobile-nav-button" id="mobile-nav-more">
        <span class="mobile-nav-icon">◎</span>
        <span>더보기</span>
      </button>
    </div>
  </nav>
  <script>
    (() => {
      const mobileNavMore = document.getElementById('mobile-nav-more');
      const mobileSheet = document.getElementById('mobile-utility-sheet');
      const mobileSheetScrim = document.getElementById('mobile-sheet-scrim');
      const mobileSheetClose = document.getElementById('mobile-sheet-close');

      const setMobileSheetOpen = (open) => {
        if (!mobileNavMore || !mobileSheet || !mobileSheetScrim) {
          return;
        }

        mobileSheet.hidden = !open;
        mobileSheetScrim.hidden = !open;
        mobileNavMore.classList.toggle('is-active', open);
        document.body.classList.toggle('mobile-nav-open', open);
      };

      if (mobileNavMore && mobileSheet && mobileSheetScrim && mobileSheetClose) {
        mobileNavMore.addEventListener('click', () => setMobileSheetOpen(mobileSheet.hidden));
        mobileSheetClose.addEventListener('click', () => setMobileSheetOpen(false));
        mobileSheetScrim.addEventListener('click', () => setMobileSheetOpen(false));
      }
    })();
  </script>
</body>
</html>
