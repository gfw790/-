<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

$user = csm_require_auth();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>5. 안전보건교육 이행 및 교육일지 관리 · CSM</title>
  <link rel="stylesheet" href="../leadership.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Pretendard:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Pretendard', system-ui, -apple-system, sans-serif; background: #f2f5f9; color: #17253b; }
    
    /* CSM Standard Alignment Overrides */
    header { background: #101c31; color: white; }
    header > div { max-width: 1240px; padding: 14px 24px; margin: auto; display: flex; gap: 20px; align-items: center; justify-content: space-between; }
    header a.back-link { color: #c4d5f8; text-decoration: none; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
    header a.back-link:hover { color: #ffffff; }
    header strong.header-title { font-size: 16px; font-weight: 700; }
    .header-user { display: flex; align-items: center; gap: 12px; font-size: 12px; }
    .header-btn { background: rgba(255,255,255,0.12); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 7px; font-size: 11px; font-weight: 600; text-decoration: none; transition: all 0.15s; }
    .header-btn:hover { background: rgba(255,255,255,0.22); color: #fff; }

    main { max-width: 1240px; margin: auto; padding: 24px; }
    
    /* Tabs grid - 7 items */
    .edu-tabs {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 8px;
      margin-bottom: 18px;
    }
    @media (max-width: 1200px) {
      .edu-tabs {
        grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
        gap: 10px;
      }
    }
    .edu-tabs button {
      padding: 11px 10px;
      min-width: 0;
      width: 100%;
      overflow: hidden;
      background: #fff;
      border: 1px solid var(--line, #dfe6ef);
      border-radius: 12px;
      text-align: left;
      display: flex;
      flex-direction: column;
      gap: 3px;
      color: #17253b;
      transition: all 0.15s ease;
      cursor: pointer;
      box-sizing: border-box;
    }
    .edu-tabs button:hover {
      border-color: #245eea;
      background: #f8faff;
    }
    .edu-tabs button[aria-current="page"] {
      background: #245eea;
      color: #fff;
      border-color: #245eea;
      box-shadow: 0 4px 12px rgba(36, 94, 234, 0.22);
    }
    .edu-tabs button small {
      font-size: 10.5px;
      color: #61738c;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      width: 100%;
      letter-spacing: -0.3px;
    }
    .edu-tabs button[aria-current="page"] small {
      color: #dbe7ff;
    }
    .edu-tabs button strong {
      font-size: 13px;
      font-weight: 700;
      line-height: 1.25;
      letter-spacing: -0.4px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      width: 100%;
    }
    .edu-tabs button span {
      font-size: 10.5px;
      color: #61738c;
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      width: 100%;
      letter-spacing: -0.3px;
    }
    .edu-tabs button[aria-current="page"] span {
      color: #e0e9ff;
    }

    /* Score Panel & Buttons */
    .panel {
      background: #fff;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      padding: 18px 22px;
      margin-bottom: 16px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .score-panel-inner {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    @media (min-width: 768px) {
      .score-panel-inner {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
      }
    }
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
    }

    /* Filter Form */
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      padding-bottom: 14px;
      border-bottom: 1px solid #edf2f7;
      margin-bottom: 14px;
    }
    .filter-bar select, .filter-bar input[type="date"], .filter-bar input[type="text"] {
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 6px 10px;
      font-size: 12px;
      background: #fff;
      color: #1e293b;
    }
    .filter-bar select:focus, .filter-bar input:focus {
      outline: none;
      border-color: #245eea;
      box-shadow: 0 0 0 2px rgba(36, 94, 234, 0.15);
    }

    /* Table styling */
    .edu-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 12px;
    }
    .edu-table th {
      background: #f8fafc;
      color: #475569;
      font-weight: 600;
      padding: 10px 8px;
      border-bottom: 1px solid #e2e8f0;
      text-align: center;
      white-space: nowrap;
    }
    .edu-table td {
      padding: 11px 8px;
      border-bottom: 1px solid #f1f5f9;
      vertical-align: middle;
    }
    .edu-table tbody tr:hover {
      background: #f8fafc;
    }
    .edu-table tbody tr:last-child td {
      border-bottom: none;
    }

    /* Print guide alert */
    .duplex-notice {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 11.5px;
      color: #1e3a8a;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 14px;
    }
  </style>
</head>
<body>

  <!-- Top Header (CSM Standard) -->
  <header>
    <div>
      <div class="flex items-center space-x-3">
        <a href="/CSM/area.php?group=5" class="back-link">
          <i data-lucide="arrow-left" class="w-4 h-4"></i>
          <span>안전보건관리체계</span>
        </a>
        <span class="text-slate-500">|</span>
        <strong class="header-title">5. 안전보건교육 활동관리</strong>
      </div>

      <div class="header-user">
        <span class="text-slate-300 font-medium"><?= htmlspecialchars($user['name']) ?></span>
        <span class="text-slate-400 text-xs">(<?= htmlspecialchars($user['role']) ?>)</span>
        <a href="/CSM/education_activity.php" class="header-btn">활동기록 관리 (CSM 5영역) ↗</a>
        <a href="plan.php" class="header-btn">연간 교육계획 ↗</a>
        <button type="button" onclick="triggerBackup()" class="header-btn">통합 백업 ZIP</button>
      </div>
    </div>
  </header>

  <!-- Main Container -->
  <main>

    <!-- Heading with Year Filter (leadership.php style) -->
    <div class="heading">
      <div>
        <small>안전보건관리체계 · 기본 10점</small>
        <h1>안전보건교육 활동관리 및 교육일지</h1>
        <p>법정 직무교육, 정기·특별·채용·MSDS 교육 실시 계획부터 자필 서명 교육일지 및 이수증까지 일괄 관리합니다.</p>
      </div>
      <form method="get" onsubmit="event.preventDefault(); loadPart5Summary(); loadTrainings();" class="flex items-center space-x-2">
        <label for="mgmt-year" class="font-bold text-xs text-slate-600">관리 연도</label>
        <input id="mgmt-year" name="year" type="number" min="2020" max="2030" value="2026" required class="w-20 px-2 py-1 border border-slate-300 rounded text-center text-xs font-bold">
        <button type="button" onclick="loadPart5Summary(); loadTrainings();" class="primary px-3 py-1 rounded text-xs font-bold bg-blue-600 text-white hover:bg-blue-700 transition">조회</button>
      </form>
    </div>

    <!-- 7 Item Cards (Tabs) -->
    <nav class="edu-tabs" aria-label="CSM 제5장 세부 평가항목">
      <button type="button" onclick="selectCsmTab('ALL')" id="tab-all" aria-current="page" title="제5장 전체 교육일지">
        <small>제5장 총괄 · 10.0점 만점</small>
        <strong>전체 교육일지</strong>
        <span id="tab-all-count">활동 기록 로딩중...</span>
      </button>

      <button type="button" onclick="selectCsmTab('5.1.1')" id="tab-5-1-1" title="5.1.1 안전보건관리책임자/담당자 직무교육">
        <small id="tab-5-1-1-score">5.1.1 · 배점 2점 · 현재 2.0점</small>
        <strong>직무교육 (책임·담당)</strong>
        <span id="tab-5-1-1-count">책임자·담당자 완료</span>
      </button>

      <button type="button" onclick="selectCsmTab('5.1.2')" id="tab-5-1-2" title="5.1.2 정기안전보건교육 (근로자/관리감독자)">
        <small id="tab-5-1-2-score">5.1.2 · 배점 2점 · 현재 2.0점</small>
        <strong>정기교육 (근로·감독)</strong>
        <span id="tab-5-1-2-count">서명완료 11/11개월</span>
      </button>

      <button type="button" onclick="selectCsmTab('5.1.3')" id="tab-5-1-3" title="5.1.3 신규채용시 안전보건교육 (8시간)">
        <small id="tab-5-1-3-score">5.1.3 · 배점 2점 · 현재 0.0점</small>
        <strong>신규채용시 교육 (8h)</strong>
        <span id="tab-5-1-3-count">스캔본 등록 필요</span>
      </button>

      <button type="button" onclick="selectCsmTab('5.1.4')" id="tab-5-1-4" title="5.1.4 특별안전보건교육 Matrix">
        <small id="tab-5-1-4-score">5.1.4 · 배점 1점 · 현재 0.0점</small>
        <strong>특별안전교육 (Matrix)</strong>
        <span id="tab-5-1-4-count">스캔본 미등록 (0점)</span>
      </button>

      <button type="button" onclick="selectCsmTab('5.1.5')" id="tab-5-1-5" title="5.1.5 물질안전보건자료(MSDS) 교육">
        <small id="tab-5-1-5-score">5.1.5 · 배점 1점 · 현재 0.0점</small>
        <strong>MSDS 교육 (12종)</strong>
        <span id="tab-5-1-5-count">스캔본 미등록 (0점)</span>
      </button>

      <button type="button" onclick="selectCsmTab('5.2.2')" id="tab-5-2-2" title="5.2.2 한라시멘트 안전체험교육장 이수">
        <small id="tab-5-2-2-score">5.2.2 · 배점 2점 · 현재 1.4점</small>
        <strong>안전체험교육장 이수</strong>
        <span id="tab-5-2-2-count">이수증 11/16명 (69%)</span>
      </button>
    </nav>

    <!-- Panel 1: Criteria & Item Information (leadership.php style) -->
    <section class="panel criteria" id="criteria-section">
      <div class="flex-1 pr-4">
        <strong id="criteria-title" class="text-base text-slate-900 block mb-1">제5장 안전보건교육 총괄 실적</strong>
        <p id="criteria-desc" class="text-xs text-slate-600 leading-relaxed">
          법정 직무교육, 근로자·관리감독자 정기교육, 신규입사자 8시간 교육, 5개 고위험 공종 특별교육, 12개 화학물질 MSDS 교육 및 한라시멘트 안전체험교육장 실적을 종합 관리합니다.
        </p>
      </div>
      <details class="max-w-md shrink-0 text-xs">
        <summary class="font-bold text-blue-700 cursor-pointer">평가 기준·필요 증빙</summary>
        <div id="criteria-details-body" class="mt-2 text-slate-600 leading-normal border-t border-slate-200 pt-2 space-y-1">
          <p><strong>필수 증빙:</strong> 교육일지(자필 서명본 스캔), 사진대지 2장, 참석자 명단, 교육교재, 수료증(해당시).</p>
          <p><strong>서명본 필수:</strong> 출력 후 자필 서명이 완료된 스캔본 등록 시에만 [확정완료] 처리됩니다.</p>
        </div>
      </details>
    </section>

    <!-- Panel 1.5: 5.1.2 Monthly Regular Training Board (leadership.php 1.1.3 style) -->
    <section class="panel" id="monthly-edu-panel" style="display: none;">
      <div class="section-title flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-900">월별 정기안전보건교육 실시 현황 (1~12월)</h2>
        <span class="text-xs text-slate-500">연간 12회 근로자 정기교육 (월 2h) + 관리감독자 16h 실시간 연동</span>
      </div>
      <div class="months" id="monthly-edu-grid">
        <!-- Rendered via renderMonthlyGrid() -->
      </div>
      <div class="month-filter-status" style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 12px;">
        <span id="monthly-filter-text"><strong>전체 월 (1~12월)</strong> 정기교육 기록 조회 중</span>
        <button type="button" onclick="filterByMonth(null)" class="text-blue-600 hover:underline font-bold">전체 월 기록 보기</button>
      </div>
      <p class="hint">
        사내 운영규칙: 매월 4주차 금요일 13:00~15:00 정기교육(2h) 실시. 6월 관리감독자 16시간 교육 포함.<br>
        ※ 각 월 버튼을 클릭하면 해당 월의 정기교육일지만 필터링됩니다.
      </p>
    </section>

    <!-- Panel 2: Score & Action Toolbar (leadership.php score-panel style) -->
    <section class="panel score-panel">
      <div class="score-panel-inner">
        <div>
          <div class="section-title flex items-center space-x-3">
            <h2 id="total-score-text" class="text-lg font-bold text-slate-900">반영 점수 10.0 / 10.0점</h2>
            <span id="total-score-badge" class="badge-status bg-emerald-100 text-emerald-800 border border-emerald-300">
              <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
              <span>평가기준 충족 (만점)</span>
            </span>
          </div>
          <p class="hint text-xs text-slate-500 mt-1">
            모든 사내 교육일지는 출력 후 자필 서명본 스캔이 등록되어야 <strong class="text-slate-800">[확정완료]</strong> 처리됩니다. 외부 위탁 및 공단 교육은 <strong class="text-slate-800">[이수증]</strong>으로 별도 관리됩니다.
          </p>
        </div>

        <!-- Quick Action Buttons -->
        <div class="flex items-center space-x-2 shrink-0">
          <button type="button" onclick="syncNewHires()" class="button bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border-emerald-200 text-xs font-bold flex items-center space-x-1" title="신규 입사자 계정에 한해 8시간 교육 예정기록 자동 생성">
            <i data-lucide="user-plus" class="w-3.5 h-3.5 text-emerald-600"></i>
            <span>신규입사자 교육생성</span>
          </button>

          <button type="button" onclick="batchExportSelected()" class="button bg-indigo-50 hover:bg-indigo-100 text-indigo-800 border-indigo-200 text-xs font-bold flex items-center space-x-1" title="선택한 교육일지를 A4 양면(앞면:일지/뒷면:사진대지) PDF로 일괄 출력">
            <i data-lucide="printer" class="w-3.5 h-3.5 text-indigo-600"></i>
            <span>선택 일괄 양면출력</span>
          </button>

          <a href="write.php" class="primary button text-xs font-bold flex items-center space-x-1">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
            <span>+ 새 교육일지 작성</span>
          </a>
        </div>
      </div>
    </section>

    <!-- Panel 3: Training Records List Table -->
    <section class="panel">
      <!-- Search & Filters -->
      <div class="filter-bar">
        <select id="filter-type" onchange="loadTrainings()" class="text-xs font-semibold">
          <option value="ALL">전체 교육구분 (1~8번)</option>
          <option value="REGULAR">2. 정기안전보건 (2h)</option>
          <option value="SPECIAL">5. 특별안전교육 (8h / 2회)</option>
          <option value="NEW_HIRE">1. 신규채용시 교육 (8h)</option>
          <option value="JOB_CHANGE">3. 작업내용변경시 교육 (2h)</option>
          <option value="SUPERVISOR">4. 관리감독자교육 (16h)</option>
          <option value="EXTERNAL">6. 외부강사초빙 교육</option>
          <option value="OTHER">7. 기타</option>
          <option value="MSDS">8. MSDS 교육 (2h)</option>
        </select>

        <select id="filter-status" onchange="loadTrainings()" class="text-xs">
          <option value="ALL">전체 상태</option>
          <option value="FINALIZED">확정완료 (서명본 등록)</option>
          <option value="DRAFT">작성중 (DRAFT)</option>
        </select>

        <input type="date" id="filter-date-from" onchange="loadTrainings()" class="text-xs">
        <span class="text-slate-400 text-xs">~</span>
        <input type="date" id="filter-date-to" onchange="loadTrainings()" class="text-xs">

        <input type="text" id="filter-attendee" onkeyup="if(event.key==='Enter')loadTrainings()" placeholder="참석자 성명/사번..." class="w-36 text-xs">
        
        <button type="button" onclick="loadTrainings()" class="button primary text-xs">검색</button>

        <!-- Mode Toggle (Live / Demo) -->
        <div class="ml-auto flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-[11px]">
          <button type="button" onclick="setMode(0)" id="mode-live-btn" class="px-2.5 py-1 rounded-md bg-white text-blue-700 shadow-xs font-bold">실제 업무</button>
          <button type="button" onclick="setMode(1)" id="mode-demo-btn" class="px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900">시연/가상</button>
        </div>
      </div>

      <!-- Duplex Print Guide Notice -->
      <div class="duplex-notice">
        <div class="flex items-center space-x-2">
          <i data-lucide="info" class="w-4 h-4 text-blue-600 shrink-0"></i>
          <span><strong>회사 표준 양면 서식 가이드:</strong> [앞면: 교육일지 (수기 서명용)] &bull; [뒷면: 증빙 사진대지 (2장)]. 일괄 출력 시 각 교육이 새 용지 앞면에서 시작되도록 짝수 페이지로 자동 정렬됩니다.</span>
        </div>
      </div>

      <!-- Training Records Table -->
      <div class="overflow-x-auto">
        <table class="edu-table">
          <thead>
            <tr>
              <th class="w-8">
                <input type="checkbox" id="check-all-trainings" onchange="toggleAllCheckboxes(this.checked)" class="rounded border-slate-300">
              </th>
              <th class="w-24">교육일지번호</th>
              <th class="w-20">실시일자</th>
              <th class="w-36">교육구분 / CSM</th>
              <th>교육과목 및 내용 요약</th>
              <th class="w-14">시간</th>
              <th class="w-16">강사</th>
              <th class="w-16">이수인원</th>
              <th class="w-12">사진</th>
              <th class="w-14" title="출력 후 자필 서명/결재된 교육일지 스캔 원본">서명본</th>
              <th class="w-14" title="공단/협회 등 공식 교육이수증(수료증)">이수증</th>
              <th class="w-20">상태</th>
              <th class="w-48">양면 서식 출력 및 관리</th>
            </tr>
          </thead>
          <tbody id="training-tbody">
            <!-- Rendered via JS -->
          </tbody>
        </table>
      </div>
    </section>

    <!-- Panel 4: Personal Stats Collapsible Panel -->
    <details class="panel" id="stats-details">
      <summary class="font-bold text-sm text-slate-800 cursor-pointer flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <i data-lucide="bar-chart-3" class="w-4 h-4 text-blue-600"></i>
          <span>개인별 사내 안전보건교육 이수시간 현황판 (클릭하여 열기)</span>
        </div>
        <span class="text-xs text-slate-400 font-normal">정기(월 2h) &bull; 특별(8h) &bull; MSDS &bull; 기타(1h)</span>
      </summary>
      
      <div class="mt-4 pt-4 border-t border-slate-100 space-y-3">
        <div class="flex items-center justify-between text-xs text-slate-500">
          <span>※ 본 통계는 사내 교육일지 기준 실시간 집계입니다.</span>
          <select id="stats-year" onchange="loadStats()" class="border border-slate-200 rounded px-2 py-1 text-xs">
            <option value="2026">2026년도</option>
            <option value="2025">2025년도</option>
          </select>
        </div>

        <div class="overflow-x-auto">
          <table class="edu-table">
            <thead>
              <tr>
                <th class="w-20">사번</th>
                <th class="w-20">성명</th>
                <th class="w-24">부서</th>
                <th class="w-20">직위</th>
                <th class="w-16">재직상태</th>
                <th class="w-20">정기(상반기)</th>
                <th class="w-20">정기(하반기)</th>
                <th class="w-24">특별교육 (8h)</th>
                <th class="w-20">8. MSDS</th>
                <th class="w-28">기타교육 (각 1h)</th>
                <th class="w-20 font-bold">연간 총 이수</th>
              </tr>
            </thead>
            <tbody id="stats-tbody">
              <!-- Rendered via loadStats() -->
            </tbody>
          </table>
        </div>
      </div>
    </details>

  </main>

  <!-- Footer -->
  <footer class="text-center py-6 text-xs text-slate-400">
    현대기전 CSM 안전보건교육 시스템 &bull; 사내 운영규칙 준수 &bull; 안전보건관리체계 5. 교육훈련
  </footer>

  <script>
    let currentMode = 0; // 0: Live, 1: Demo
    let selectedCsmCode = 'ALL';
    let selectedMonth = null;
    let rawTrainingsList = [];
    let summaryData = null;

    const itemMeta = {
      'ALL': {
        title: '제5장 안전보건교육 총괄 실적',
        desc: '법정 직무교육, 근로자·관리감독자 정기교육, 신규입사자 8시간 교육, 5개 고위험 공종 특별교육, 12개 화학물질 MSDS 교육 및 한라시멘트 안전체험교육장 실적을 종합 관리합니다.',
        evidence: '<p><strong>필수 증빙:</strong> 교육일지(자필 서명본 스캔), 사진대지 2장, 참석자 명단, 교육교재, 수료증(해당시).</p><p><strong>서명본 필수:</strong> 출력 후 자필 서명이 완료된 스캔본 등록 시에만 [확정완료] 처리됩니다.</p>'
      },
      '5.1.1': {
        title: '5.1.1 안전보건관리책임자/담당자 직무교육 (배점 2.0점)',
        desc: '산업안전보건법 제32조에 따른 안전보건관리책임자(정연탁 대표, 보수교육 6시간) 및 안전보건관리담당자(김남균 팀장, 신규교육 16시간) 직무교육 이수 실적입니다.',
        evidence: '<p><strong>필요 증빙:</strong> 공단/협회 공식 교육이수증(수료증) 스캔 원본, 활동기록표.</p><p><strong>이행 기준:</strong> 법정 기한 내 책임자 6h, 담당자 16h 신규/보수교육 전원 완료.</p>'
      },
      '5.1.2': {
        title: '5.1.2 정기안전보건교육 (근로자 매월 2h + 관리감독자 16h) (배점 2.0점)',
        desc: '사내 운영규칙(매월 4주차 금 13:00~15:00)에 따라 매월 실시하는 근로자 정기교육(연간 12회) 및 관리감독자 16시간 연간 집체/우편원격 교육 실적입니다.',
        evidence: '<p><strong>필요 증빙:</strong> 매월 정기교육일지(수기 서명 원본 스캔), 교육 사진 2장, 교육교재, 관리감독자 이수증.</p><p><strong>이행 기준:</strong> 상반기/하반기 각 월별 2시간 실시 및 관리감독자 16시간 충족.</p>'
      },
      '5.1.3': {
        title: '5.1.3 신규채용시 안전보건교육 (입사자 8시간) (배점 2.0점)',
        desc: '신규 입사자 전원에 대해 현장 배치 전 법정 8시간 이상 실시하는 채용시 안전보건교육 실적입니다. (2026년 입사자 5명 전원 완료)',
        evidence: '<p><strong>필요 증빙:</strong> 채용시 교육일지(자필 서명본), 신규채용자 서약서, 교육 사진대지, 이수 확인서.</p><p><strong>이행 기준:</strong> 신규 입사자 발생 시 작업 투입 전 8시간 이상 교육 이수 완료.</p>'
      },
      '5.1.4': {
        title: '5.1.4 특별안전보건교육 Matrix (5개 고위험 공종 8h 분할) (배점 1.0점)',
        desc: '한라시멘트 공장 내 5개 고위험 공종(밀폐/산소결핍, 비계/고소, 화기/용접, 유기용제, 크레인) 종사자에 대한 연간 8시간(4h × 2회) 특별교육 이행 실적입니다.',
        evidence: '<p><strong>필요 증빙:</strong> 공종별 특별안전교육일지(자필 서명본 스캔), 사진대지, 대상자 명단 Matrix.</p><p><strong>이행 기준:</strong> 5개 고위험 공종별 대상자 8시간 분할 이수 완료.</p>'
      },
      '5.1.5': {
        title: '5.1.5 물질안전보건자료(MSDS) 교육 및 개정시 교육 (배점 1.0점)',
        desc: '사업장 내 취급하는 12개 화학물질(윤활유, 그리스, 세척제, 방청제 등)에 대한 MSDS 취급 근로자 교육 및 물질 개정 시 특별 교육 실적입니다.',
        evidence: '<p><strong>필요 증빙:</strong> MSDS 교육일지(서명본 스캔), MSDS 경고표지 및 교육 사진대지, 화학물질 관리대장.</p><p><strong>이행 기준:</strong> 12종 화학물질 취급자 교육 및 MSDS 변경 시 개정교육 반영.</p>'
      },
      '5.2.2': {
        title: '5.2.2 한라시멘트 사업장 안전체험교육장 이수 실적 (배점 2.0점)',
        desc: '한라시멘트 옥계공장 내 안전체험교육장에서 실시한 가상안전체험, VR 교육, 추락·감전 체험 등 실습 교육 이수 실적입니다. (임직원 전원 참여율 100%)',
        evidence: '<p><strong>필요 증빙:</strong> 안전체험교육장 수료증/참석자 명단, 현장 체험 사진대지, 공동훈련 실시계획서.</p><p><strong>이행 기준:</strong> 협력업체 소속 근로자 전원 안전체험교육 이수 완료.</p>'
      }
    };

    function selectCsmTab(code) {
      selectedCsmCode = code;
      selectedMonth = null; // 월 선택 초기화

      // Toggle Monthly Panel for 5.1.2
      const monthlyPanel = document.getElementById('monthly-edu-panel');
      if (monthlyPanel) {
        monthlyPanel.style.display = (code === '5.1.2') ? 'block' : 'none';
      }

      // Update Tab Styles
      const tabIds = ['ALL', '5.1.1', '5.1.2', '5.1.3', '5.1.4', '5.1.5', '5.2.2'];
      tabIds.forEach(id => {
        const btn = document.getElementById('tab-' + id.toLowerCase().replace(/\./g, '-'));
        if (!btn) return;
        if (id === code) {
          btn.setAttribute('aria-current', 'page');
        } else {
          btn.removeAttribute('aria-current');
        }
      });

      // Update Criteria Box
      const meta = itemMeta[code] || itemMeta['ALL'];
      document.getElementById('criteria-title').textContent = meta.title;
      document.getElementById('criteria-desc').textContent = meta.desc;
      document.getElementById('criteria-details-body').innerHTML = meta.evidence;

      // Filter training list
      loadTrainings();
    }

    function filterByMonth(m) {
      selectedMonth = m;
      const filterText = document.getElementById('monthly-filter-text');
      if (filterText) {
        filterText.innerHTML = m ? `<strong>${m}월</strong> 정기교육 기록 조회 중` : '<strong>전체 월 (1~12월)</strong> 정기교육 기록 조회 중';
      }
      renderMonthlyGrid();
      renderTableRows();
    }

    function renderMonthlyGrid() {
      const grid = document.getElementById('monthly-edu-grid');
      if (!grid) return;

      const monthly = {};
      for (let m = 1; m <= 12; m++) {
        monthly[m] = { count: 0, hours: 0, attendees: 0, hasScan: false, finalized: false };
      }
      rawTrainingsList.forEach(t => {
        if (t.csm_item_code === '5.1.2' || t.training_type === 'REGULAR' || t.training_type === 'SUPERVISOR') {
          const parts = t.training_date.split('-');
          if (parts.length >= 2) {
            const m = parseInt(parts[1], 10);
            if (monthly[m]) {
              monthly[m].count++;
              monthly[m].hours += parseFloat(t.planned_hours) || 2;
              monthly[m].attendees = Math.max(monthly[m].attendees, parseInt(t.completed_attendees) || 0);
              if (t.scanned_doc_filename || t.cert_doc_filename) monthly[m].hasScan = true;
              if (t.status === 'FINALIZED') monthly[m].finalized = true;
            }
          }
        }
      });

      let html = '';
      for (let m = 1; m <= 12; m++) {
        const st = monthly[m];
        const isCur = (selectedMonth === m);
        const stateText = st.count === 0 ? '미실시' : (st.finalized ? '증빙/확정 완료' : '작성중 (DRAFT)');
        const stateColor = isCur ? 'color: #fff;' : (st.count === 0 ? 'color: var(--muted);' : (st.finalized ? 'color: #059669; font-weight: bold;' : 'color: #d97706;'));

        html += `
          <a href="javascript:void(0)" onclick="filterByMonth(${m})" ${isCur ? 'aria-current="true"' : ''} title="${m}월 정기교육 ${st.count}건 보기">
            <small>${m}월</small>
            <strong>${st.count > 0 ? st.count + '회 (' + st.hours + 'h)' : '미실시'}</strong>
            <span class="month-score">${st.count > 0 ? st.attendees + '명 이수' : '-'}</span>
            <span class="month-rating-state" style="${stateColor}">${stateText}</span>
          </a>
        `;
      }
      grid.innerHTML = html;
    }

    function setMode(mode) {
      currentMode = mode;
      if (mode === 0) {
        document.getElementById('mode-live-btn').className = 'px-2.5 py-1 rounded-md bg-white text-blue-700 shadow-xs font-bold';
        document.getElementById('mode-demo-btn').className = 'px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900';
      } else {
        document.getElementById('mode-demo-btn').className = 'px-2.5 py-1 rounded-md bg-white text-blue-700 shadow-xs font-bold';
        document.getElementById('mode-live-btn').className = 'px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900';
      }
      loadPart5Summary();
      loadTrainings();
      loadStats();
    }

    function toggleAllCheckboxes(checked) {
      document.querySelectorAll('.training-row-check').forEach(cb => cb.checked = checked);
    }

    function batchExportSelected() {
      const selected = [];
      document.querySelectorAll('.training-row-check:checked').forEach(cb => selected.push(cb.value));
      if (selected.length === 0) {
        alert('일괄 출력할 교육일지를 1건 이상 체크해 주십시오.');
        return;
      }
      window.location.href = `export.php?batch=${selected.join(',')}`;
    }

    async function syncNewHires() {
      if (!confirm('새로 등록된 신규 입사자 계정을 확인하여 신규채용 교육일지(8시간) 예정기록을 자동 생성하시겠습니까?\n(※ 기존 재직자는 제외되며, 향후 새로 추가되는 신규 입사자에 한해 생성됩니다)')) return;
      const res = await fetch('api.php?action=sync_new_hires');
      const data = await res.json();
      if (data.ok) {
        alert(data.message);
        loadPart5Summary();
        loadTrainings();
      } else {
        alert('동기화 실패: ' + data.error);
      }
    }

    async function loadPart5Summary() {
      const year = document.getElementById('mgmt-year').value || 2026;
      try {
        const res = await fetch(`api.php?action=csm_part5_summary&year=${year}&is_demo=${currentMode}`);
        const data = await res.json();
        if (!data.ok) return;

        summaryData = data;

        // Total score display
        const scoreText = document.getElementById('total-score-text');
        const scoreBadge = document.getElementById('total-score-badge');
        if (scoreText) {
          scoreText.textContent = `반영 점수 ${data.total_score.toFixed(1)} / ${data.max_score.toFixed(1)}점`;
        }
        if (scoreBadge) {
          if (data.is_perfect) {
            scoreBadge.className = 'badge-status bg-emerald-100 text-emerald-800 border border-emerald-300';
            scoreBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i><span>평가기준 충족 (만점)</span>';
          } else {
            scoreBadge.className = 'badge-status bg-amber-100 text-amber-800 border border-amber-300';
            scoreBadge.innerHTML = '<i data-lucide="alert-circle" class="w-3.5 h-3.5"></i><span>보완 필요</span>';
          }
        }

        // Update scores and counts on all tabs dynamically
        if (data.items) {
          const keys = ['5.1.1', '5.1.2', '5.1.3', '5.1.4', '5.1.5', '5.2.2'];
          keys.forEach(k => {
            const item = data.items[k];
            if (!item) return;
            const idPrefix = 'tab-' + k.replace(/\./g, '-');
            const scoreEl = document.getElementById(idPrefix + '-score');
            const countEl = document.getElementById(idPrefix + '-count');
            if (scoreEl) {
              scoreEl.textContent = `${k} · 배점 ${item.points}점 · 현재 ${item.score.toFixed(1)}점`;
              if (item.score === 0) {
                scoreEl.style.color = '#dc2626';
              } else if (item.score < item.points) {
                scoreEl.style.color = '#d97706';
              } else {
                scoreEl.style.color = '';
              }
            }
            if (countEl) {
              if (k === '5.1.1') {
                countEl.textContent = item.score >= 2.0 ? '책임자·담당자 완료' : (item.score >= 1.0 ? '1인 수료 (추가필요)' : '스캔본 미등록 (0점)');
              } else if (k === '5.1.2') {
                countEl.textContent = `서명완료: ${item.details.split('(')[0].replace('정기교육 서명본 완료: ', '')}`;
              } else if (k === '5.1.3') {
                const tot = item.target_count || 5;
                const done = item.completed_count !== undefined ? item.completed_count : (item.score > 0 ? Math.round((item.score / 2.0) * tot) : 0);
                countEl.textContent = item.score >= 2.0 ? `입사자 전원 완료 (${tot}/${tot}명)` : (item.score > 0 ? `서명완료 ${done}/${tot}명 (${item.score.toFixed(1)}점)` : `서명본 미등록 (0/${tot}명)`);
              } else if (k === '5.1.4') {
                countEl.textContent = item.score >= 1.0 ? '5개 공종 8h 분할이수' : (item.score > 0 ? `스캔 ${item.score.toFixed(1)}점` : '스캔본 미등록 (0점)');
              } else if (k === '5.1.5') {
                countEl.textContent = item.score >= 1.0 ? '12종 화학물질 완료' : (item.score > 0 ? `스캔 ${item.score.toFixed(1)}점` : '스캔본 미등록 (0점)');
              } else if (k === '5.2.2') {
                countEl.textContent = item.score >= 2.0 ? '16명 전원 이수 (100%)' : (item.score > 0 ? `이수증 11/16명 (${item.score.toFixed(1)}점)` : '이수증 미등록 (0점)');
              }
            }
          });
        }

        lucide.createIcons();
      } catch (e) {
        console.error('Error loading Part 5 summary:', e);
      }
    }

    async function loadTrainings() {
      const year = document.getElementById('mgmt-year').value || 2026;
      const type = document.getElementById('filter-type').value;
      const status = document.getElementById('filter-status').value;
      const dateFrom = document.getElementById('filter-date-from').value;
      const dateTo = document.getElementById('filter-date-to').value;
      const attendee = document.getElementById('filter-attendee').value;

      const params = new URLSearchParams({
        action: 'list',
        is_demo: currentMode,
        csm_code: selectedCsmCode,
        type: type,
        status: status,
        date_from: dateFrom,
        date_to: dateTo,
        attendee: attendee
      });

      const res = await fetch('api.php?' + params.toString());
      const data = await res.json();

      rawTrainingsList = (data.ok && data.list) ? data.list : [];

      if (selectedCsmCode === 'ALL') {
        document.getElementById('tab-all-count').textContent = `총 ${rawTrainingsList.length}건 등록됨`;
      }

      if (selectedCsmCode === '5.1.2') {
        renderMonthlyGrid();
      }

      renderTableRows();
    }

    function renderTableRows() {
      const tbody = document.getElementById('training-tbody');
      let displayList = rawTrainingsList;

      if (selectedCsmCode === '5.1.2' && selectedMonth !== null) {
        displayList = rawTrainingsList.filter(t => {
          const parts = t.training_date.split('-');
          return parts.length >= 2 && parseInt(parts[1], 10) === selectedMonth;
        });
      }

      if (!displayList || displayList.length === 0) {
        const msg = (selectedCsmCode === '5.1.2' && selectedMonth !== null) 
          ? `${selectedMonth}월에 등록된 정기교육 일지가 없습니다.`
          : '조건에 해당하는 교육일지가 없습니다.';
        tbody.innerHTML = `<tr><td colspan="13" class="py-12 text-center text-slate-400">${msg}</td></tr>`;
        return;
      }

      const csmBadges = {
        '5.1.1': '<span class="bg-purple-100 text-purple-900 border border-purple-200 px-1.5 py-0.5 rounded text-[10px] font-bold">CSM 5.1.1 (2점)</span>',
        '5.1.2': '<span class="bg-blue-100 text-blue-900 border border-blue-200 px-1.5 py-0.5 rounded text-[10px] font-bold">CSM 5.1.2 (2점)</span>',
        '5.1.3': '<span class="bg-emerald-100 text-emerald-900 border border-emerald-200 px-1.5 py-0.5 rounded text-[10px] font-bold">CSM 5.1.3 (2점)</span>',
        '5.1.4': '<span class="bg-amber-100 text-amber-900 border border-amber-200 px-1.5 py-0.5 rounded text-[10px] font-bold">CSM 5.1.4 (1점)</span>',
        '5.1.5': '<span class="bg-pink-100 text-pink-900 border border-pink-200 px-1.5 py-0.5 rounded text-[10px] font-bold">CSM 5.1.5 (1점)</span>',
        '5.2.2': '<span class="bg-cyan-100 text-cyan-900 border border-cyan-200 px-1.5 py-0.5 rounded text-[10px] font-bold">CSM 5.2.2 (2점)</span>',
        '기타': '<span class="bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded text-[10px]">기타</span>'
      };

      const typeLabels = {
        'REGULAR': '<span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-[10px] font-semibold">2. 정기안전보건</span>',
        'SPECIAL': t => `<span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded text-[10px] font-semibold">5. 특별(${t.session_round || 1}회)</span>`,
        'NEW_HIRE': '<span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-[10px] font-semibold">1. 신규채용시</span>',
        'JOB_CHANGE': '<span class="bg-slate-100 text-slate-800 px-2 py-0.5 rounded text-[10px] font-semibold">3. 작업변경</span>',
        'SUPERVISOR': '<span class="bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded text-[10px] font-semibold">4. 관리감독자</span>',
        'EXTERNAL': '<span class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px]">6. 외부강사</span>',
        'OTHER': '<span class="bg-amber-50 text-amber-900 border border-amber-200 px-2 py-0.5 rounded text-[10px]">7. 기타</span>',
        'MSDS': '<span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded text-[10px] font-semibold">8. MSDS교육</span>',
      };

      let html = '';
      displayList.forEach(t => {
        const typeRenderer = typeLabels[t.training_type];
        const typeHtml = typeof typeRenderer === 'function' ? typeRenderer(t) : (typeRenderer || t.training_type);

        const statusBadge = t.status === 'FINALIZED'
          ? `<span class="bg-emerald-600 text-white px-2 py-0.5 rounded text-[10px] font-bold">확정완료 (R${t.revision_num})</span>`
          : '<span class="bg-amber-500 text-white px-2 py-0.5 rounded text-[10px] font-bold">작성중 (DRAFT)</span>';

        const signedBadge = t.scanned_doc_filename 
          ? `<a href="secure_file.php?type=scan&file=${encodeURIComponent(t.scanned_doc_filename)}" data-doc-title="[자필 서명본 스캔] ${t.training_no}" data-doc-name="${encodeURIComponent(t.scanned_doc_filename)}" onclick="openDocLink(this); return false;" class="text-emerald-600 font-bold hover:underline" title="자필 서명본 스캔 모달 열람">등록됨</a>`
          : '<span class="text-slate-300">-</span>';

        const certBadge = t.cert_doc_filename 
          ? `<a href="secure_file.php?type=scan&file=${encodeURIComponent(t.cert_doc_filename)}" data-doc-title="[공식 교육이수증] ${t.training_no}" data-doc-name="${encodeURIComponent(t.cert_doc_filename)}" onclick="openDocLink(this); return false;" class="text-blue-600 font-bold hover:underline" title="공식 교육이수증 모달 열람">이수증</a>`
          : '<span class="text-slate-300">-</span>';

        html += `
          <tr class="hover:bg-slate-50 transition">
            <td class="text-center">
              <input type="checkbox" value="${t.id}" class="training-row-check rounded border-slate-300">
            </td>
            <td class="text-center font-mono font-bold text-slate-700 text-xs">${t.training_no}</td>
            <td class="text-center font-medium text-xs">${t.training_date}</td>
            <td class="text-center space-y-1">
              <div>${typeHtml}</div>
              ${csmBadges[t.csm_item_code] ? '<div>' + csmBadges[t.csm_item_code] + '</div>' : ''}
            </td>
            <td>
              <a href="view.php?id=${t.id}" class="font-bold text-slate-900 hover:text-blue-600 block line-clamp-1">${t.subject}</a>
              <div class="text-[11px] text-slate-500 line-clamp-1 mt-0.5">${t.content_summary}</div>
            </td>
            <td class="text-center font-bold text-blue-600">${t.planned_hours}h</td>
            <td class="text-center">${t.instructor_name}</td>
            <td class="text-center font-mono font-semibold">${t.completed_attendees}/${t.total_attendees}</td>
            <td class="text-center">${t.photo_count > 0 ? '<span class="text-purple-600 font-bold">' + t.photo_count + '</span>' : '<span class="text-slate-300">미등록</span>'}</td>
            <td class="text-center">${signedBadge}</td>
            <td class="text-center">${certBadge}</td>
            <td class="text-center">${statusBadge}</td>
            <td class="text-center">
              <div class="flex items-center justify-center space-x-1">
                <a href="view.php?id=${t.id}" class="px-1.5 py-0.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded text-[11px] font-semibold">보기</a>
                <a href="print.php?id=${t.id}&print=1" target="_blank" class="px-1.5 py-0.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-[11px] font-semibold" title="회사 양식 인쇄">인쇄</a>
                <a href="write.php?id=${t.id}" class="px-1.5 py-0.5 bg-slate-100 hover:bg-blue-50 text-blue-700 rounded text-[11px] font-semibold">수정</a>
                <a href="export.php?id=${t.id}&format=xlsx" class="px-1.5 py-0.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 rounded text-[11px] font-semibold" title="엑셀 서식 다운로드">엑셀</a>
                <a href="export.php?id=${t.id}&format=pdf" class="px-1.5 py-0.5 bg-rose-50 hover:bg-rose-100 text-rose-800 rounded text-[11px] font-semibold" title="A4 양면 2페이지 PDF 다운로드">PDF</a>
              </div>
            </td>
          </tr>
        `;
      });

      tbody.innerHTML = html;
      lucide.createIcons();
    }

    async function loadStats() {
      const year = document.getElementById('stats-year').value;
      const res = await fetch(`api.php?action=stats&year=${year}&is_demo=${currentMode}`);
      const data = await res.json();
      const tbody = document.getElementById('stats-tbody');

      if (!data.ok || !data.stats) return;

      let html = '';
      data.stats.forEach(s => {
        const isRetired = s.status === 'retired';
        const retBadge = isRetired ? '<span class="bg-slate-200 text-slate-600 text-[10px] px-1 rounded">퇴사</span>' : '<span class="bg-emerald-100 text-emerald-700 text-[10px] px-1 rounded">재직</span>';

        const spBadge = s.special_satisfied 
          ? '<span class="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-1.5 py-0.5 rounded">8h 충족</span>'
          : `<span class="bg-amber-100 text-amber-800 text-[10px] font-bold px-1.5 py-0.5 rounded">${s.special_total}h / 8h</span>`;

        let otherStr = '-';
        if (s.other_courses && Object.keys(s.other_courses).length > 0) {
          otherStr = Object.entries(s.other_courses).map(([k, v]) => `${k}:${v}h`).join(', ');
        }

        html += `
          <tr class="hover:bg-slate-50">
            <td class="text-center font-mono text-slate-500">${s.employee_no}</td>
            <td class="font-bold text-slate-900">${s.name}</td>
            <td class="text-slate-600">${s.team || '-'}</td>
            <td class="text-slate-600">${s.position || '-'}</td>
            <td class="text-center">${retBadge}</td>
            <td class="text-center font-mono font-bold ${s.regular_h1 > 0 ? 'text-blue-600' : 'text-slate-400'}">${s.regular_h1.toFixed(1)}h</td>
            <td class="text-center font-mono font-bold ${s.regular_h2 > 0 ? 'text-blue-600' : 'text-slate-400'}">${s.regular_h2.toFixed(1)}h</td>
            <td class="text-center">${spBadge}</td>
            <td class="text-center font-mono font-bold ${s.msds_total > 0 ? 'text-purple-600' : 'text-slate-400'}">${s.msds_total.toFixed(1)}h</td>
            <td class="text-center text-[11px] text-slate-600">${otherStr}</td>
            <td class="text-center font-mono font-extrabold text-slate-900">${s.total_hours.toFixed(1)}h</td>
          </tr>
        `;
      });
      tbody.innerHTML = html;
    }

    async function triggerBackup() {
      if (!confirm('현재 교육 DB와 첨부된 모든 사진/서명/스캔 파일을 단일 ZIP으로 백업하시겠습니까?')) return;
      const res = await fetch('api.php?action=backup');
      const data = await res.json();
      if (data.ok) {
        alert('백업이 생성되었습니다: ' + data.filename + ' (' + (data.size/1024).toFixed(1) + ' KB)');
        window.location.href = data.download_url;
      } else {
        alert('백업 실패: ' + data.error);
      }
    }

    // Initialize
    loadPart5Summary();
    loadTrainings();
    loadStats();
    lucide.createIcons();
  </script>

  <?php require_once __DIR__ . '/doc_viewer_modal.php'; ?>
</body>
</html>
