<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

$user = csm_require_auth();
$db = get_csm_db();

$selectedYear = (int)($_GET['year'] ?? 2026);
$typeFilter = trim((string)($_GET['type'] ?? 'ALL'));
$monthFilter = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
$statusFilter = trim((string)($_GET['status'] ?? 'ALL'));

// Fetch plans
$plans = csm_get_training_plans($db, $selectedYear, $typeFilter, $monthFilter, $statusFilter);

// Calculate Stats
$totalPlans = count($plans);
$executedCount = count(array_filter($plans, fn($p) => $p['status'] === 'EXECUTED'));
$pendingCount = $totalPlans - $executedCount;
$totalHours = array_sum(array_column($plans, 'planned_hours'));
$executedHours = 0.0;
foreach ($plans as $p) {
    if ($p['status'] === 'EXECUTED') {
        $executedHours += (float)$p['planned_hours'];
    }
}
$progressRate = $totalPlans > 0 ? round(($executedCount / $totalPlans) * 100, 1) : 0.0;

function render_csm_plan_content_summary(string $text): string {
    $lines = explode("\n", $text);
    $html = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        
        // Level 3 outline: 2.1.1, a., (a), etc.
        if (preg_match('/^(\d+\.\d+\.\d+(\.|\))?|[a-z]\.|\([a-z]\))\s*(.*)$/ui', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-4 py-0.5 text-slate-600">' .
                     '<span class="font-bold mr-1 shrink-0 text-slate-400">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[3]) . '</span></div>';
            continue;
        }
        
        // Level 2 outline: 2.1, 2.1., 2.1), 1), (1), ①, etc. (2에서 2.1로 증가 시 들여쓰기)
        if (preg_match('/^(\d+\.\d+(\.|\))?|\d+\)|\([0-9]+\)|[①-⑳])\s*(.*)$/u', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-2.5 py-0.5 text-slate-700">' .
                     '<span class="font-bold mr-1 shrink-0 text-indigo-600">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[3]) . '</span></div>';
            continue;
        }
        
        // Bullets: -, ·, *, ※, ★
        if (preg_match('/^([\-|\·|\*|※|★])\s*(.*)$/u', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-3 py-0.5 text-slate-500">' .
                     '<span class="font-bold mr-1 shrink-0">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[2]) . '</span></div>';
            continue;
        }
        
        // Level 1 outline: 1., 2., 3., 가., I., etc.
        if (preg_match('/^(\d+\.|[가-하]\.|[IVXLCDM]+\.)\s*(.*)$/u', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-0 py-0.5 font-bold text-slate-800">' .
                     '<span class="mr-1 shrink-0">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[2]) . '</span></div>';
            continue;
        }
        
        // Plain line
        $html .= '<div class="pl-1 py-0.5">' . htmlspecialchars($trimmed) . '</div>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $selectedYear ?>년 안전보건교육계획 - CSM 업무시스템</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Pretendard:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Pretendard', system-ui, sans-serif; }
  </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col">

  <!-- Portal Banner -->
  <div class="bg-slate-900 text-slate-300 px-4 py-2 text-xs flex items-center justify-between border-b border-slate-800">
    <div class="flex items-center space-x-3">
      <a href="../" class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-2.5 py-1 rounded-lg font-bold flex items-center space-x-1 shrink-0 transition">
        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
        <span>CSM 통합 포털</span>
      </a>
      <span class="inline-block w-2 h-2 rounded-full bg-emerald-400"></span>
      <span class="font-bold text-slate-200">주식회사 현대기전 안전보건교육계획</span>
      <span class="text-slate-500">|</span>
      <span>자체 수립 2026년안전보건활동계획서(1.2.1) 및 정기안전교육계획 연동</span>
    </div>
    <div class="flex items-center space-x-3 shrink-0">
      <span class="bg-slate-800 text-slate-400 text-[11px] px-2.5 py-0.5 rounded border border-slate-700 font-mono">A:\risk_server\project\CSM\education</span>
    </div>
  </div>

  <!-- Header -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <div class="bg-blue-600 text-white p-2 rounded-xl shadow-md shadow-blue-500/20">
            <i data-lucide="calendar-range" class="w-6 h-6"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <h1 class="text-lg font-bold text-slate-900 tracking-tight">현대기전 CSM 안전보건교육계획</h1>
              <span class="bg-indigo-100 text-indigo-800 text-[10px] font-bold px-2 py-0.5 rounded-full">1.2.1 계획서 연동</span>
            </div>
            <p class="text-xs text-slate-500">어떤 내용을 &bull; 언제 &bull; 누구에게 교육할지 사전 수립 &bull; 원클릭 교육일지 작성 연계</p>
          </div>
        </div>

        <!-- User Profile & Action -->
        <div class="flex items-center space-x-3">
          <!-- Year Switcher -->
          <div class="flex items-center bg-slate-100 p-1 rounded-xl text-xs font-semibold border border-slate-200">
            <a href="plan.php?year=2026" class="px-3 py-1.5 rounded-lg <?= $selectedYear === 2026 ? 'bg-white text-blue-700 shadow-sm font-bold' : 'text-slate-600 hover:text-slate-900' ?>">2026년 계획</a>
            <a href="plan.php?year=2025" class="px-3 py-1.5 rounded-lg <?= $selectedYear === 2025 ? 'bg-white text-blue-700 shadow-sm font-bold' : 'text-slate-600 hover:text-slate-900' ?>">2025년 계획</a>
          </div>

          <!-- User Badge -->
          <div class="flex items-center space-x-2 text-xs bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-200">
            <i data-lucide="user-check" class="w-4 h-4 text-blue-600"></i>
            <span class="font-semibold text-slate-800"><?= htmlspecialchars($user['name']) ?></span>
          </div>

          <!-- Add Plan Button -->
          <button onclick="openPlanModal()" class="px-3.5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 flex items-center space-x-1.5 transition">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>신규 계획 추가</span>
          </button>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <nav class="flex space-x-6 border-t border-slate-100 pt-1 text-sm font-medium">
        <a href="../" class="tab-btn pb-3 border-b-2 border-transparent text-slate-500 hover:text-blue-600 flex items-center space-x-1.5 transition">
          <i data-lucide="layout-dashboard" class="w-4 h-4 text-slate-400"></i>
          <span>CSM 통합 포털</span>
        </a>
        <a href="index.php" class="tab-btn pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center space-x-2">
          <i data-lucide="list" class="w-4 h-4"></i>
          <span>교육일지 목록 및 출력</span>
        </a>
        <a href="plan.php?year=<?= $selectedYear ?>" class="tab-btn pb-3 border-b-2 border-blue-600 text-blue-600 font-semibold flex items-center space-x-2">
          <i data-lucide="calendar-range" class="w-4 h-4"></i>
          <span>연간 교육계획 관리 (현행)</span>
        </a>
        <a href="index.php?tab=stats" class="tab-btn pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center space-x-2">
          <i data-lucide="bar-chart-2" class="w-4 h-4"></i>
          <span>개인별 이수 & 회사 실적</span>
        </a>
      </nav>
    </div>
  </header>

  <!-- Main Container -->
  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1 w-full space-y-6">

    <!-- Plan Principle Banner -->
    <div class="bg-gradient-to-r from-blue-900 to-indigo-900 rounded-2xl p-5 text-white shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
      <div class="space-y-1">
        <div class="flex items-center space-x-2">
          <span class="bg-blue-500/30 text-blue-200 border border-blue-400/30 text-[10px] font-bold px-2 py-0.5 rounded">회사 표준 프로세스</span>
          <span class="font-bold text-sm">사전 교육계획 &rarr; 일지 자동연계 &rarr; 현장 실시 &rarr; 수기 서명 스캔본 보관</span>
        </div>
        <p class="text-xs text-blue-200/90 leading-relaxed">
          <strong>(주)현대기전 자체 수립 2026 안전보건활동계획서(1.2.1)</strong>에 따라 <strong>어떤 교육을, 언제, 누구에게 실시할 것인지</strong> 사전에 명확히 관리합니다.<br>
          계획된 항목의 <strong>[교육일지 작성 연계]</strong>를 클릭하면 계획서 내용과 대상 부서가 작성 양식에 자동 입력됩니다.
        </p>
      </div>
      <div class="flex items-center space-x-2 shrink-0">
        <button onclick="resetDefaultPlans()" class="px-3.5 py-2 bg-white/10 hover:bg-white/20 text-white border border-white/20 rounded-xl text-xs font-semibold transition flex items-center space-x-1.5">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          <span>현대기전 연간계획 기본값 복원</span>
        </button>
      </div>
    </div>

    <!-- Metrics Cards Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <span class="text-[11px] font-bold text-slate-400 block uppercase">연간 총 교육계획</span>
        <div class="flex items-baseline space-x-1.5 mt-1">
          <strong class="text-2xl font-black text-slate-900"><?= $totalPlans ?></strong>
          <span class="text-xs text-slate-500 font-semibold">건</span>
        </div>
        <span class="text-[10px] text-slate-400 mt-1 block">정기12 &bull; 특별2 &bull; 기타8</span>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <span class="text-[11px] font-bold text-emerald-600 block uppercase">실시 완료</span>
        <div class="flex items-baseline space-x-1.5 mt-1">
          <strong class="text-2xl font-black text-emerald-600"><?= $executedCount ?></strong>
          <span class="text-xs text-emerald-700 font-semibold">건</span>
        </div>
        <span class="text-[10px] text-emerald-600/80 mt-1 block">교육일지 등록 및 연동됨</span>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <span class="text-[11px] font-bold text-blue-600 block uppercase">계획 예정</span>
        <div class="flex items-baseline space-x-1.5 mt-1">
          <strong class="text-2xl font-black text-blue-600"><?= $pendingCount ?></strong>
          <span class="text-xs text-blue-700 font-semibold">건</span>
        </div>
        <span class="text-[10px] text-blue-600/80 mt-1 block">작성 대기 상태</span>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <span class="text-[11px] font-bold text-purple-600 block uppercase">연간 계획 시간</span>
        <div class="flex items-baseline space-x-1.5 mt-1">
          <strong class="text-2xl font-black text-purple-700"><?= number_format($totalHours, 1) ?></strong>
          <span class="text-xs text-purple-600 font-semibold">시간</span>
        </div>
        <span class="text-[10px] text-purple-500 mt-1 block">실시: <?= number_format($executedHours, 1) ?>h</span>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <span class="text-[11px] font-bold text-slate-500 block uppercase">계획 대비 진척률</span>
        <div class="flex items-baseline space-x-1 mt-1">
          <strong class="text-2xl font-black text-slate-900"><?= $progressRate ?></strong>
          <span class="text-xs font-bold text-slate-600">%</span>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-1.5 mt-2 overflow-hidden">
          <div class="bg-emerald-500 h-1.5 rounded-full" style="width: <?= min(100, $progressRate) ?>%"></div>
        </div>
      </div>
    </div>

    <!-- Filter & Toolbar -->
    <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
      <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
        <!-- Type Filter -->
        <select id="filter-type" onchange="applyFilters()" class="px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-semibold">
          <option value="ALL" <?= $typeFilter === 'ALL' ? 'selected' : '' ?>>전체 교육구분 (1~8번)</option>
          <option value="REGULAR" <?= $typeFilter === 'REGULAR' ? 'selected' : '' ?>>2. 정기안전보건교육 (12건)</option>
          <option value="SPECIAL" <?= $typeFilter === 'SPECIAL' ? 'selected' : '' ?>>5. 특별안전교육 (8h/2회)</option>
          <option value="NEW_HIRE" <?= $typeFilter === 'NEW_HIRE' ? 'selected' : '' ?>>1. 신규채용시 교육 (8h)</option>
          <option value="SUPERVISOR" <?= $typeFilter === 'SUPERVISOR' ? 'selected' : '' ?>>4. 관리감독자교육 (16h)</option>
          <option value="MSDS" <?= $typeFilter === 'MSDS' ? 'selected' : '' ?>>8. MSDS 교육 (2h)</option>
          <option value="OTHER" <?= $typeFilter === 'OTHER' ? 'selected' : '' ?>>7. 기타</option>
        </select>

        <!-- Month Filter -->
        <select id="filter-month" onchange="applyFilters()" class="px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white">
          <option value="">전체 (연간)</option>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $monthFilter === $m ? 'selected' : '' ?>><?= $m ?>월</option>
          <?php endfor; ?>
        </select>

        <!-- Status Filter -->
        <select id="filter-status" onchange="applyFilters()" class="px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white">
          <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>전체 진행상태</option>
          <option value="PLANNED" <?= $statusFilter === 'PLANNED' ? 'selected' : '' ?>>계획예정 (PLANNED)</option>
          <option value="EXECUTED" <?= $statusFilter === 'EXECUTED' ? 'selected' : '' ?>>실시완료 (EXECUTED)</option>
        </select>

        <!-- Search Input -->
        <div class="relative">
          <input type="text" id="filter-keyword" onkeyup="if(event.key==='Enter')applyFilters()" placeholder="과목, 대상, 강사 검색..." 
            class="px-3 py-2 pr-8 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white w-44">
          <button onclick="applyFilters()" class="absolute right-2.5 top-2.5 text-slate-400 hover:text-slate-600">
            <i data-lucide="search" class="w-3.5 h-3.5"></i>
          </button>
        </div>
      </div>

      <!-- View Switcher -->
      <div class="flex items-center space-x-2">
        <div class="flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-xs">
          <button onclick="switchView('card')" id="btn-view-card" class="px-2.5 py-1 rounded bg-white text-blue-700 font-bold shadow-xs flex items-center space-x-1">
            <i data-lucide="layout-grid" class="w-3.5 h-3.5"></i>
            <span>카드 뷰</span>
          </button>
          <button onclick="switchView('table')" id="btn-view-table" class="px-2.5 py-1 rounded text-slate-600 hover:text-slate-900 font-medium flex items-center space-x-1">
            <i data-lucide="table" class="w-3.5 h-3.5"></i>
            <span>테이블 뷰</span>
          </button>
        </div>
      </div>
    </div>

    <!-- VIEW 1: CARDS GRID VIEW -->
    <div id="view-card" class="space-y-4">
      <?php if (empty($plans)): ?>
        <div class="bg-white rounded-2xl p-12 text-center text-slate-400 border border-slate-200">
          <i data-lucide="calendar-x" class="w-10 h-10 mx-auto text-slate-300 mb-2"></i>
          <p class="font-bold">조건에 해당하는 교육계획이 없습니다.</p>
        </div>
      <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <?php foreach ($plans as $p): ?>
            <?php 
              $isExec = ($p['status'] === 'EXECUTED');
              $typeBadgeClasses = match($p['training_type']) {
                  'REGULAR' => 'bg-blue-100 text-blue-800 border-blue-200',
                  'SPECIAL' => 'bg-amber-100 text-amber-800 border-amber-200',
                  'NEW_HIRE' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                  'SUPERVISOR' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
                  'MSDS' => 'bg-purple-100 text-purple-800 border-purple-200',
                  default => 'bg-slate-100 text-slate-700 border-slate-200'
              };
              $typeLabels = [
                  'REGULAR' => '2. 정기안전보건',
                  'SPECIAL' => '5. 특별안전교육',
                  'NEW_HIRE' => '1. 신규채용시',
                  'JOB_CHANGE' => '3. 작업변경시',
                  'SUPERVISOR' => '4. 관리감독자',
                  'EXTERNAL' => '6. 외부강사초빙',
                  'OTHER' => '7. 기타법정의무',
                  'MSDS' => '8. MSDS 화학물질',
              ];
            ?>
            <div class="bg-white rounded-2xl border <?= $isExec ? 'border-emerald-200' : 'border-slate-200' ?> shadow-sm overflow-hidden flex flex-col justify-between hover:shadow-md transition">
              
              <!-- Card Top Header -->
              <div class="p-4 space-y-3">
                <div class="flex items-center justify-between gap-2">
                  <div class="flex items-center space-x-1.5 flex-wrap">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold border <?= $typeBadgeClasses ?>">
                      <?= $typeLabels[$p['training_type']] ?? $p['training_type'] ?>
                    </span>
                    <?php if ($p['plan_month']): ?>
                      <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded text-[10px] font-bold">
                        <?= $p['plan_month'] ?>월 계획
                      </span>
                    <?php endif; ?>
                  </div>

                  <!-- Status Badge -->
                  <?php 
                    $hasDraftLog = (!$isExec && !empty($p['linked_training_id']));
                  ?>
                  <?php if ($isExec): ?>
                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full text-[10px] font-bold flex items-center space-x-1" title="일지 확정 및 이수 인원 확인 완료">
                      <i data-lucide="check-circle" class="w-3 h-3 text-emerald-600"></i>
                      <span>실시완료 (일지확인)</span>
                    </span>
                  <?php elseif ($hasDraftLog): ?>
                    <span class="px-2 py-0.5 bg-amber-100 text-amber-800 border border-amber-300 rounded-full text-[10px] font-bold flex items-center space-x-1" title="일지가 작성 중이거나 이수자 미확정 상태">
                      <i data-lucide="alert-triangle" class="w-3 h-3 text-amber-600"></i>
                      <span>일지 미확인 (미완료)</span>
                    </span>
                  <?php else: ?>
                    <span class="px-2 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded-full text-[10px] font-bold">
                      계획예정
                    </span>
                  <?php endif; ?>
                </div>

                <!-- Subject -->
                <h3 class="font-bold text-slate-900 text-sm leading-snug">
                  <?= htmlspecialchars($p['subject']) ?>
                </h3>

                <!-- Key Details (When & Who) -->
                <div class="space-y-1.5 text-xs bg-slate-50/80 p-3 rounded-xl border border-slate-100 text-slate-700">
                  <div class="flex items-center justify-between">
                    <span class="text-slate-400 font-medium">실시 예정일자:</span>
                    <span class="font-bold text-slate-800">
                      <?= htmlspecialchars($p['planned_date'] ?: '수시') ?>
                      <span class="text-[11px] text-blue-600 ml-1 font-semibold">(<?= $p['planned_hours'] ?>h)</span>
                    </span>
                  </div>
                  <div class="flex items-start justify-between gap-2">
                    <span class="text-slate-400 font-medium shrink-0">교육 주기/시기:</span>
                    <span class="text-right text-[11px] text-slate-600 font-medium"><?= htmlspecialchars($p['planned_period'] ?: '연간 계획') ?></span>
                  </div>
                  <div class="flex items-start justify-between gap-2 pt-1 border-t border-slate-200/60">
                    <span class="text-slate-400 font-medium shrink-0">교육 대상(Who):</span>
                    <span class="text-right font-bold text-indigo-700 text-xs"><?= htmlspecialchars($p['target_audience']) ?></span>
                  </div>
                  <?php if (!empty($p['target_teams'])): ?>
                    <div class="flex items-start justify-between gap-2">
                      <span class="text-slate-400 font-medium shrink-0">대상 부서:</span>
                      <span class="text-right text-[10px] text-slate-600"><?= htmlspecialchars($p['target_teams']) ?></span>
                    </div>
                  <?php endif; ?>
                  <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 text-[11px]">
                    <span class="text-slate-400 font-medium">강사 / 장소:</span>
                    <span class="text-slate-700 font-medium"><?= htmlspecialchars($p['instructor_name'] ?: '김남균') ?> &bull; <?= htmlspecialchars($p['location'] ?: '현장 사무실') ?></span>
                  </div>
                </div>

                <!-- Content Outline Preview -->
                <div class="text-xs space-y-1">
                  <span class="font-bold text-slate-500 text-[11px] uppercase tracking-wider block">교육 내용 요약 (What):</span>
                  <div class="bg-white p-2.5 rounded-lg border border-slate-200 text-[11px] text-slate-600 leading-relaxed font-mono max-h-24 overflow-y-auto"><?= render_csm_plan_content_summary($p['content_outline']) ?></div>
                </div>
              </div>

              <!-- Card Bottom Action Bar -->
              <div class="p-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-2">
                <span class="text-[10px] text-slate-400 truncate max-w-[130px]" title="<?= htmlspecialchars($p['plan_source'] ?? '') ?>">
                  <?= htmlspecialchars($p['plan_source'] ?: '1.2.1 계획서') ?>
                </span>

                <div class="flex items-center space-x-1.5 text-xs">
                  <button type="button" onclick="editPlan(<?= $p['id'] ?>)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-200 rounded transition" title="계획 수정">
                    <i data-lucide="edit-2" class="w-3.5 h-3.5"></i>
                  </button>
                  <button type="button" onclick="deletePlan(<?= $p['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded transition" title="계획 삭제">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                  </button>

                  <?php if ($isExec && !empty($p['linked_training_id'])): ?>
                    <a href="view.php?id=<?= $p['linked_training_id'] ?>" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-300 rounded-lg text-xs font-bold flex items-center space-x-1 transition">
                      <i data-lucide="file-check" class="w-3 h-3"></i>
                      <span>일지 확인</span>
                    </a>
                  <?php elseif ($hasDraftLog): ?>
                    <a href="write.php?id=<?= $p['linked_training_id'] ?>" class="px-2.5 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs font-bold shadow-sm shadow-amber-500/20 flex items-center space-x-1 transition" title="일지 서명/참석자 확정 필요">
                      <i data-lucide="edit-3" class="w-3 h-3"></i>
                      <span>일지 확인/확정</span>
                    </a>
                  <?php else: ?>
                    <a href="write.php?from_plan_id=<?= $p['id'] ?>" class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold shadow-sm shadow-blue-500/20 flex items-center space-x-1 transition">
                      <i data-lucide="arrow-right-circle" class="w-3 h-3"></i>
                      <span>교육일지 작성</span>
                    </a>
                  <?php endif; ?>
                </div>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- VIEW 2: TABLE VIEW -->
    <div id="view-table" class="hidden bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
          <thead>
            <tr class="bg-slate-100 text-slate-700 font-semibold border-b border-slate-200">
              <th class="py-3 px-3 text-center w-12">월</th>
              <th class="py-3 px-3 w-28 text-center">교육구분</th>
              <th class="py-3 px-4">교육과목 및 상세 내용</th>
              <th class="py-3 px-3 w-28 text-center">예정일자</th>
              <th class="py-3 px-2 text-center w-14">시간</th>
              <th class="py-3 px-3 w-40">교육 대상(Who)</th>
              <th class="py-3 px-3 w-24 text-center">강사</th>
              <th class="py-3 px-3 w-20 text-center">상태</th>
              <th class="py-3 px-3 w-28 text-center">연계 작업</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($plans as $p): ?>
              <?php $isExec = ($p['status'] === 'EXECUTED'); ?>
              <tr class="hover:bg-slate-50/80 transition">
                <td class="py-3 px-3 text-center font-bold text-slate-700"><?= $p['plan_month'] ? $p['plan_month'] . '월' : '수시' ?></td>
                <td class="py-3 px-3 text-center">
                  <span class="px-1.5 py-0.5 rounded text-[10px] font-bold border <?= $p['training_type'] === 'REGULAR' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-amber-50 text-amber-700 border-amber-200' ?>">
                    <?= htmlspecialchars($p['training_type']) ?>
                  </span>
                </td>
                <td class="py-3 px-4">
                  <strong class="font-bold text-slate-900 block"><?= htmlspecialchars($p['subject']) ?></strong>
                  <span class="text-[11px] text-slate-500 line-clamp-1 mt-0.5"><?= htmlspecialchars(str_replace("\n", " &bull; ", $p['content_outline'])) ?></span>
                </td>
                <td class="py-3 px-3 text-center font-semibold text-slate-800"><?= htmlspecialchars($p['planned_date'] ?: '수시') ?></td>
                <td class="py-3 px-2 text-center font-bold text-blue-600"><?= $p['planned_hours'] ?>h</td>
                <td class="py-3 px-3">
                  <span class="font-bold text-indigo-700 block"><?= htmlspecialchars($p['target_audience']) ?></span>
                  <span class="text-[10px] text-slate-400 block truncate"><?= htmlspecialchars($p['target_teams'] ?? '') ?></span>
                </td>
                <td class="py-3 px-3 text-center text-slate-600"><?= htmlspecialchars($p['instructor_name'] ?: '김남균') ?></td>
                <td class="py-3 px-3 text-center">
                  <?php 
                    $hasDraftLog = (!$isExec && !empty($p['linked_training_id']));
                  ?>
                  <?php if ($isExec): ?>
                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full text-[10px] font-bold" title="일지 확정 완료">실시완료 (확인)</span>
                  <?php elseif ($hasDraftLog): ?>
                    <span class="px-2 py-0.5 bg-amber-100 text-amber-800 border border-amber-300 rounded-full text-[10px] font-bold" title="일지 미확인 상태">일지미확인</span>
                  <?php else: ?>
                    <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold">계획예정</span>
                  <?php endif; ?>
                </td>
                <td class="py-3 px-3 text-center">
                  <?php if ($isExec && !empty($p['linked_training_id'])): ?>
                    <a href="view.php?id=<?= $p['linked_training_id'] ?>" class="px-2 py-1 bg-emerald-50 text-emerald-700 border border-emerald-300 rounded text-[11px] font-bold inline-block hover:bg-emerald-100">
                      일지보기
                    </a>
                  <?php elseif ($hasDraftLog): ?>
                    <a href="write.php?id=<?= $p['linked_training_id'] ?>" class="px-2 py-1 bg-amber-500 text-white rounded text-[11px] font-bold inline-block hover:bg-amber-600 shadow-sm" title="일지 서명/참석자 확정">
                      확인/확정
                    </a>
                  <?php else: ?>
                    <a href="write.php?from_plan_id=<?= $p['id'] ?>" class="px-2 py-1 bg-blue-600 text-white rounded text-[11px] font-bold inline-block hover:bg-blue-700 shadow-sm">
                      일지작성
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>

  <!-- MODAL: ADD / EDIT TRAINING PLAN -->
  <div id="plan-modal" class="fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto p-6 space-y-4 shadow-2xl border border-slate-200">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <h3 id="modal-title" class="text-sm font-bold text-slate-900 uppercase tracking-wider flex items-center space-x-2">
          <i data-lucide="calendar-plus" class="w-4 h-4 text-blue-600"></i>
          <span>안전보건교육 계획 등록 / 수정</span>
        </h3>
        <button type="button" onclick="closePlanModal()" class="text-slate-400 hover:text-slate-600">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="plan-form" onsubmit="submitPlanForm(event)" class="space-y-4 text-xs">
        <input type="hidden" name="id" id="plan-id" value="0">
        <input type="hidden" name="plan_year" id="plan-year" value="<?= $selectedYear ?>">

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">계획 월 (Month)</label>
            <select name="plan_month" id="plan-month" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-bold">
              <option value="">수시 / 연중</option>
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>"><?= $m ?>월</option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 구분 <span class="text-rose-500">*</span></label>
            <select name="training_type" id="plan-type" required class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-bold">
              <option value="REGULAR">2. 정기안전보건교육</option>
              <option value="SPECIAL">5. 특별안전교육</option>
              <option value="NEW_HIRE">1. 신규채용시 교육</option>
              <option value="JOB_CHANGE">3. 작업내용변경시 교육</option>
              <option value="SUPERVISOR">4. 관리감독자교육</option>
              <option value="EXTERNAL">6. 외부강사초빙 교육</option>
              <option value="OTHER">7. 기타 법정의무교육</option>
              <option value="MSDS">8. MSDS 화학물질 교육</option>
            </select>
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">계획 교육시간 (HR) <span class="text-rose-500">*</span></label>
            <input type="number" step="0.5" name="planned_hours" id="plan-hours" value="2.0" required class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-bold text-blue-600">
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">교육 과목 및 명칭 (Subject) <span class="text-rose-500">*</span></label>
          <input type="text" name="subject" id="plan-subject" required placeholder="예: 2026년 1월 정기안전보건교육 (고소작업)" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-bold text-slate-900">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 대상 (누구에게 / Who) <span class="text-rose-500">*</span></label>
            <input type="text" name="target_audience" id="plan-audience" required value="현장 및 본사 전 근로자 (재직 20명)" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-semibold text-indigo-700">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">대상 부서/팀</label>
            <input type="text" name="target_teams" id="plan-teams" value="가스팀, 공사팀-전기, 공사팀-모터, 제조팀, 경영/기타, 안전관리" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">실시 예정일자 (언제 / When)</label>
            <input type="date" name="planned_date" id="plan-date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-bold">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">실시 주기 / 시간대</label>
            <input type="text" name="planned_period" id="plan-period" value="매월 4번째 금요일 (13:00~15:00)" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">강사 성명 / 직위</label>
            <div class="flex items-center space-x-1">
              <input type="text" name="instructor_name" id="plan-instructor" value="김남균" class="w-full px-2 py-2 border border-slate-200 rounded-xl bg-slate-50 font-bold">
              <input type="text" name="instructor_position" id="plan-pos" value="안전관리자" class="w-24 px-2 py-2 border border-slate-200 rounded-xl bg-slate-50 text-center">
            </div>
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 장소</label>
            <input type="text" name="location" id="plan-location" value="현장 사무실 / 휴게실" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 방법</label>
            <input type="text" name="method" id="plan-method" value="시청각 및 실습" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50">
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">상세 교육 내용 요약 (어떤 내용 / What) <span class="text-rose-500">*</span></label>
          <textarea name="content_outline" id="plan-outline" rows="4" required class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-mono leading-relaxed text-xs">1. 산업안전 및 사고 예방에 관한 사항
2. 당월 중점 유해·위험요인 발굴 및 안전작업 절차
3. 작업 전 TBM 실시 및 비상조치 요령</textarea>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">계획서 출처 근거</label>
          <input type="text" name="plan_source" id="plan-source" value="1.2.1 계획서 (참조_안전교육)" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 text-slate-500">
        </div>

        <div class="flex items-center justify-end space-x-2 pt-3 border-t border-slate-100">
          <button type="button" onclick="closePlanModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-semibold">취소</button>
          <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-sm">계획 저장</button>
        </div>
      </form>
    </div>
  </div>

  <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-400 mt-auto">
    현대기전 CSM 안전보건교육 시스템 &bull; 개발환경 (A:\CSM\dev) &bull; 1.2.1 연간 교육실행계획
  </footer>

  <script>
    function applyFilters() {
      const type = document.getElementById('filter-type').value;
      const month = document.getElementById('filter-month').value;
      const status = document.getElementById('filter-status').value;
      const year = '<?= $selectedYear ?>';

      const url = new URL(window.location.href);
      url.searchParams.set('year', year);
      url.searchParams.set('type', type);
      url.searchParams.set('month', month);
      url.searchParams.set('status', status);
      window.location.href = url.toString();
    }

    function switchView(mode) {
      const btnCard = document.getElementById('btn-view-card');
      const btnTable = document.getElementById('btn-view-table');
      const vCard = document.getElementById('view-card');
      const vTable = document.getElementById('view-table');

      if (mode === 'card') {
        btnCard.className = 'px-2.5 py-1 rounded bg-white text-blue-700 font-bold shadow-xs flex items-center space-x-1';
        btnTable.className = 'px-2.5 py-1 rounded text-slate-600 hover:text-slate-900 font-medium flex items-center space-x-1';
        vCard.classList.remove('hidden');
        vTable.classList.add('hidden');
      } else {
        btnTable.className = 'px-2.5 py-1 rounded bg-white text-blue-700 font-bold shadow-xs flex items-center space-x-1';
        btnCard.className = 'px-2.5 py-1 rounded text-slate-600 hover:text-slate-900 font-medium flex items-center space-x-1';
        vTable.classList.remove('hidden');
        vCard.classList.add('hidden');
      }
      lucide.createIcons();
    }

    function openPlanModal(isEdit = false) {
      document.getElementById('plan-modal').classList.remove('hidden');
      document.getElementById('modal-title').innerText = isEdit ? '안전보건교육 계획 수정' : '안전보건교육 계획 신규 등록';
      lucide.createIcons();
    }

    function closePlanModal() {
      document.getElementById('plan-modal').classList.add('hidden');
      document.getElementById('plan-form').reset();
      document.getElementById('plan-id').value = '0';
    }

    async function editPlan(pid) {
      try {
        const res = await fetch(`api.php?action=plan_get&id=${pid}`);
        const data = await res.json();
        if (data.ok && data.plan) {
          const p = data.plan;
          document.getElementById('plan-id').value = p.id;
          document.getElementById('plan-year').value = p.plan_year;
          document.getElementById('plan-month').value = p.plan_month || '';
          document.getElementById('plan-type').value = p.training_type;
          document.getElementById('plan-hours').value = p.planned_hours;
          document.getElementById('plan-subject').value = p.subject;
          document.getElementById('plan-audience').value = p.target_audience;
          document.getElementById('plan-teams').value = p.target_teams || '';
          document.getElementById('plan-date').value = p.planned_date || '';
          document.getElementById('plan-period').value = p.planned_period || '';
          document.getElementById('plan-instructor').value = p.instructor_name || '김남균';
          document.getElementById('plan-pos').value = p.instructor_position || '안전관리자';
          document.getElementById('plan-location').value = p.location || '현장 사무실';
          document.getElementById('plan-method').value = p.method || '시청각 및 실습';
          document.getElementById('plan-outline').value = p.content_outline || '';
          document.getElementById('plan-source').value = p.plan_source || '';

          openPlanModal(true);
        } else {
          alert('계획 정보를 불러오지 못했습니다: ' + data.error);
        }
      } catch (e) {
        alert('오류 발생: ' + e.message);
      }
    }

    async function deletePlan(pid) {
      if (!confirm('이 교육계획을 삭제하시겠습니까?')) return;
      const fd = new FormData();
      fd.append('action', 'plan_delete');
      fd.append('id', pid);

      const res = await fetch('api.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        alert(data.message);
        location.reload();
      } else {
        alert('삭제 실패: ' + data.error);
      }
    }

    async function resetDefaultPlans() {
      if (!confirm('(주)현대기전 2026 안전보건활동계획서의 표준 연간 교육계획 기본값으로 동기화하시겠습니까?')) return;
      location.reload();
    }

    async function submitPlanForm(e) {
      e.preventDefault();
      const form = document.getElementById('plan-form');
      const fd = new FormData(form);
      fd.append('action', 'plan_save');

      try {
        const res = await fetch('api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          alert(data.message);
          closePlanModal();
          location.reload();
        } else {
          alert('저장 실패: ' + data.error);
        }
      } catch (e) {
        alert('저장 오류: ' + e.message);
      }
    }

    lucide.createIcons();
  </script>
</body>
</html>
