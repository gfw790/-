<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/activity_organization_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_GET['year'] ?? $now->format('Y'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100) {
    http_response_code(400);
    exit('올바른 연도를 선택해 주세요.');
}

$db = leadership_db();
$record = activity_org_records($db, $year);
$data = $record['data'] ?? activity_org_default_data($year);
$currentRoster = activity_org_current_roster();

// Load Company Logo (Base64)
$logoPath = __DIR__ . '/assets/policy-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = dirname(__DIR__) . '/safety_manual/assets/policy-logo.png';
}
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $year ?>년_안전보건관리조직도_인쇄</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;600;700;800;900&display=swap');

    :root {
      --top-pad: 28mm;
      --bottom-pad: 10mm;
      --left-pad: 14mm;
      --right-pad: 12mm;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background-color: #f1f5f9;
      font-family: 'Noto Sans KR', 'Malgun Gothic', 'Dotum', sans-serif;
      color: #0f172a;
      -webkit-font-smoothing: antialiased;
    }

    /* Screen Toolbar */
    .screen-toolbar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: #ffffff;
      border-bottom: 1px solid #cbd5e1;
      padding: 10px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07);
    }
    .toolbar-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .toolbar-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      font-size: 13px;
      font-weight: 700;
      border-radius: 8px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.15s ease;
      border: 1px solid transparent;
    }
    .btn-print {
      background: #1e3a8a;
      color: #fff;
      box-shadow: 0 2px 4px rgba(30,58,138,0.25);
    }
    .btn-print:hover {
      background: #1e40af;
    }
    .btn-close {
      background: #f1f5f9;
      color: #475569;
      border-color: #cbd5e1;
    }
    .btn-close:hover {
      background: #e2e8f0;
      color: #1e293b;
    }

    .pad-control-group {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      margin-left: 8px;
      padding-left: 12px;
      border-left: 1px solid #cbd5e1;
    }
    .pad-label {
      font-size: 12px;
      font-weight: 700;
      color: #334155;
      margin-right: 2px;
    }
    .pad-btn {
      padding: 4px 8px;
      font-size: 11px;
      font-weight: 600;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #475569;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .pad-btn:hover {
      background: #f1f5f9;
      color: #1e293b;
    }
    .pad-btn.active {
      background: #1e3a8a;
      color: #fff;
      border-color: #1e3a8a;
      font-weight: 700;
    }

    .toolbar-tip {
      font-size: 11.5px;
      color: #475569;
      background: #f8fafc;
      padding: 6px 14px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }

    /* Container for A4 Paper Sheets on Screen */
    .paper-container {
      max-width: 210mm;
      margin: 20px auto 40px auto;
      display: flex;
      flex-direction: column;
      gap: 25px;
    }

    .a4-page {
      width: 210mm;
      height: 297mm;
      min-height: 297mm;
      max-height: 297mm;
      background: #fff;
      padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad);
      box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      box-sizing: border-box;
      overflow: hidden;
    }

    .page-content {
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    /* Document Header Area */
    .doc-top-bar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 2px solid #0f172a;
    }
    .doc-corp-sub {
      font-size: 9.5pt;
      font-weight: 700;
      color: #1e40af;
      margin-bottom: 2px;
      letter-spacing: 0.5px;
    }
    .doc-main-title {
      font-size: 16pt;
      font-weight: 900;
      color: #0f172a;
      letter-spacing: 1px;
    }
    .doc-meta-right {
      text-align: right;
      font-size: 8.5pt;
      color: #64748b;
      line-height: 1.4;
    }
    .doc-meta-right strong {
      color: #1e293b;
    }

    /* Subtitle Header */
    .section-h4 {
      font-size: 11pt;
      font-weight: 800;
      color: #1e3a8a;
      display: flex;
      align-items: center;
      gap: 6px;
      margin: 10px 0 8px 0;
    }

    /* 1. Organization Tree Canvas (A4 Optimized) */
    .tree-diagram-canvas {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 10px 6px;
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      margin-bottom: 12px;
    }
    .tree-box {
      border-radius: 6px;
      padding: 7px 14px;
      text-align: center;
      position: relative;
    }
    .tree-box-root {
      width: 290px;
      background: #1e3a8a;
      color: #ffffff;
      border: 2px solid #1e3a8a;
    }
    .tree-box-manager {
      width: 280px;
      background: #f0fdfa;
      border: 2px solid #0d9488;
    }
    .tree-box-officer {
      width: 280px;
      background: #ecfdf5;
      border: 2px solid #059669;
    }
    .tree-box-workers {
      width: 320px;
      background: #eef2ff;
      border: 2px solid #6366f1;
    }
    .tree-connector-line {
      width: 2px;
      height: 14px;
      background: #94a3b8;
    }

    .tree-branch-area {
      width: 100%;
      max-width: 580px;
      height: 48px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .tree-branch-trunk-line {
      position: absolute;
      left: 50%;
      top: 0;
      bottom: 0;
      width: 2px;
      background: #94a3b8;
      transform: translateX(-50%);
    }
    .tree-branch-horiz-line {
      position: absolute;
      left: calc(50% - 180px);
      width: 180px;
      top: 50%;
      height: 2px;
      border-top: 2px dashed #2563eb;
    }
    .tree-box-committee {
      position: absolute;
      left: calc(50% - 180px);
      transform: translate(-100%, -50%);
      top: 50%;
      width: 170px;
      background: #eff6ff;
      border: 2px dashed #2563eb;
      border-radius: 6px;
      padding: 5px 8px;
      text-align: center;
      z-index: 2;
    }

    /* 2. Roster Grid */
    .roster-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
      margin-top: 6px;
    }
    .roster-card {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 8px 10px;
      font-size: 8.5pt;
    }
    .roster-card.team-mgmt { border-top: 3.5px solid #1e3a8a; }
    .roster-card.team-const { border-top: 3.5px solid #0d9488; }
    .roster-card.team-gas { border-top: 3.5px solid #2563eb; }
    .roster-card.team-mfg { border-top: 3.5px solid #d97706; }

    .roster-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
      padding-bottom: 4px;
      border-bottom: 1px solid #f1f5f9;
    }
    .roster-team-title {
      font-size: 9.5pt;
      font-weight: 800;
      color: #0f172a;
    }
    .roster-team-count {
      font-size: 8pt;
      font-weight: 700;
      color: #64748b;
      background: #f1f5f9;
      padding: 1px 5px;
      border-radius: 3px;
    }
    .roster-member-pill {
      display: inline-block;
      margin: 2px 2px 2px 0;
      padding: 1px 5px;
      border-radius: 3px;
      font-size: 8pt;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #334155;
      line-height: 1.35;
    }
    .roster-member-pill.leader {
      background: #eff6ff;
      border-color: #bfdbfe;
      color: #1d4ed8;
      font-weight: 700;
    }

    /* 3. Duties Official Table */
    .plan-duties-table {
      width: 100%;
      border-collapse: collapse;
      border: 1.5px solid #0f172a;
      font-size: 8.5pt;
      background: #ffffff;
      margin-top: 8px;
    }
    .plan-duties-table th {
      background: #f1f5f9;
      color: #0f172a;
      font-weight: 800;
      padding: 7px 8px;
      border: 1px solid #64748b;
      text-align: center;
      font-size: 9pt;
    }
    .plan-duties-table td {
      padding: 7px 9px;
      border: 1px solid #64748b;
      vertical-align: top;
      line-height: 1.45;
      color: #1e293b;
    }
    .duty-num-list {
      margin: 0;
      padding-left: 0;
      list-style: none;
    }
    .duty-num-list li {
      margin-bottom: 3px;
      position: relative;
      padding-left: 18px;
      text-indent: -18px;
    }
    .duty-num-list li:last-child {
      margin-bottom: 0;
    }

    /* Document Bottom Footer */
    .doc-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
      height: 8mm;
      margin-top: 2mm;
      padding-right: 4px;
      flex-shrink: 0;
      border-top: 1px solid #cbd5e1;
      padding-top: 4px;
    }
    .doc-footer-logo {
      height: 6mm;
      width: auto;
      object-fit: contain;
    }
    .doc-footer-company {
      font-size: 10pt;
      font-weight: 800;
      letter-spacing: 2px;
      color: #1e293b;
    }

    /* Strict Browser Print Rules */
    @page {
      size: A4 portrait;
      margin: 0;
    }

    @media print {
      html, body {
        background: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 210mm !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .no-print {
        display: none !important;
      }
      .paper-container {
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        gap: 0 !important;
      }
      .a4-page {
        width: 210mm !important;
        height: 297mm !important;
        min-height: 297mm !important;
        max-height: 297mm !important;
        margin: 0 !important;
        padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad) !important;
        box-shadow: none !important;
        border: none !important;
        page-break-after: always !important;
        break-after: page !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
      }
      .a4-page:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
      }
      .doc-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        height: 8mm !important;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Top Bar -->
  <aside class="screen-toolbar no-print" aria-label="인쇄 도구 모음">
    <div class="toolbar-left">
      <button type="button" onclick="window.print()" class="toolbar-btn btn-print">
        🖨️ 바로 인쇄하기 (Ctrl+P)
      </button>
      <a href="activity_organization.php?year=<?= $year ?>" class="toolbar-btn btn-close">
        ← 조직도 화면으로 돌아가기
      </a>

      <!-- 편철 여백 패딩 조절 버튼군 -->
      <div class="pad-control-group">
        <span class="pad-label">📌 상단 편철 패딩:</span>
        <button type="button" class="pad-btn" data-pad="20" onclick="changePadding(20)">20mm</button>
        <button type="button" class="pad-btn" data-pad="25" onclick="changePadding(25)">25mm</button>
        <button type="button" class="pad-btn active" data-pad="28" onclick="changePadding(28)">28mm (기본)</button>
        <button type="button" class="pad-btn" data-pad="32" onclick="changePadding(32)">32mm</button>
      </div>
    </div>
    <div class="toolbar-tip">
      💡 <strong>[인쇄 설정]</strong> 여백: <strong>[없음(None)]</strong> 필수 선택 &bull; 배경 그래픽: <strong>[체크(On)]</strong> &bull; 상단 패딩 <strong id="cur-pad-txt">28</strong>mm로 여백을 항상 유지합니다.
    </div>
  </aside>

  <!-- A4 Paper Container -->
  <main class="paper-container">

    <!-- PAGE 1: 안전보건관리 조직도 및 부서별 인원 편성 현황 -->
    <article class="a4-page" aria-label="1페이지: 안전보건관리 조직도">
      <div class="page-content">
        <!-- Document Top Title Bar -->
        <div class="doc-top-bar">
          <div>
            <div class="doc-corp-sub">(주)현대기전 안전보건경영체계</div>
            <h1 class="doc-main-title">3. 안전보건관리 조직 및 안전관계자의 책무</h1>
          </div>
          <div class="doc-meta-right">
            <div>표준 규정: <strong>안전보건활동계획서 (제Ⅰ편 제3장)</strong></div>
            <div>발효일자: <strong><?= $escape($data['organization']['effective_date'] ?? sprintf('%04d-01-01', $year)) ?></strong> (1/2)</div>
          </div>
        </div>

        <!-- 1. ▣ 안전보건관리 조직도 -->
        <h2 class="section-h4"><span style="color: #2563eb;">▣</span> 안전보건관리 조직도</h2>
        <div class="tree-diagram-canvas">
          <!-- Level 1: 최고경영자 (안전보건관리책임자) -->
          <div class="tree-box tree-box-root">
            <div style="font-size: 9.5pt; font-weight: 800; color: #bfdbfe; letter-spacing: 0.5px;">안전보건 총괄 책임자</div>
            <div style="font-size: 13.5pt; font-weight: 900; margin: 3px 0 1px;">정연탁 대표이사</div>
            <div style="font-size: 9pt; color: #dbeafe;">최고경영자 (안전보건관리책임자)</div>
            <div style="margin-top: 4px; padding-top: 4px; border-top: 1px dashed rgba(255,255,255,0.3); font-size: 8pt; font-weight: 700; color: #fef08a;">
              ⚡ 전면 작업중지권 · 예산 최종 승인권
            </div>
          </div>

          <!-- Branch Area: 산업안전보건위원회 (좌측 분기) & 주 간선 수직선 -->
          <div class="tree-branch-area">
            <div class="tree-branch-trunk-line"></div>
            <div class="tree-branch-horiz-line"></div>
            <div class="tree-box tree-box-committee">
              <div style="font-size: 8pt; font-weight: 800; color: #1d4ed8;">심의·의결 기구</div>
              <div style="font-size: 10.5pt; font-weight: 900; color: #1e3a8a; margin: 1px 0;">산업안전보건위원회</div>
              <div style="font-size: 8pt; color: #475569;">위원장: <strong>정연탁</strong> 대표이사</div>
              <div style="font-size: 7.5pt; color: #64748b;">(노사 동수 심의·의결 기구)</div>
            </div>
          </div>

          <!-- Level 2: 관리감독자 (부서장) -->
          <div class="tree-box tree-box-manager">
            <div style="font-size: 8pt; font-weight: 800; color: #0d9488;">현장 안전보건 지휘·감독</div>
            <div style="font-size: 12.5pt; font-weight: 900; color: #0f172a; margin: 2px 0 1px;">진종철 부장</div>
            <div style="font-size: 8.5pt; font-weight: 700; color: #0f766e;">관리감독자 (공사팀장)</div>
            <div style="margin-top: 3px; padding-top: 3px; border-top: 1px dashed #ccfbf1; font-size: 8pt; font-weight: 700; color: #0d9488;">
              ⚡ 현장 즉각 작업중지권 · 시정명령권
            </div>
          </div>

          <div class="tree-connector-line"></div>

          <!-- Level 3: 안전담당자 -->
          <div class="tree-box tree-box-officer">
            <div style="font-size: 8pt; font-weight: 800; color: #059669;">안전보건 관리 실무 총괄</div>
            <div style="font-size: 12.5pt; font-weight: 900; color: #0f172a; margin: 2px 0 1px;">김남균 과장</div>
            <div style="font-size: 8.5pt; font-weight: 700; color: #047857;">안전담당자 (제조팀)</div>
            <div style="margin-top: 3px; padding-top: 3px; border-top: 1px dashed #d1fae5; font-size: 8pt; font-weight: 700; color: #059669;">
              ⚡ 시정요구권 · 안전점검권
            </div>
          </div>

          <div class="tree-connector-line"></div>

          <!-- Level 4: 전 근로자 -->
          <div class="tree-box tree-box-workers">
            <div style="font-size: 8pt; font-weight: 800; color: #4f46e5;">안전수칙 준수 및 위험작업 중지 요청</div>
            <div style="font-size: 12.5pt; font-weight: 900; color: #0f172a; margin: 2px 0 1px;">전 근 로 자</div>
            <div style="font-size: 8.5pt; font-weight: 700; color: #4338ca;">
              공사팀(<?= count($currentRoster['teams']['const']['members'] ?? []) ?>명) · GAS팀(<?= count($currentRoster['teams']['gas']['members'] ?? []) ?>명) · 제조팀(<?= count($currentRoster['teams']['mfg']['members'] ?? []) ?>명) 등 재직 근로자 총 <?= $currentRoster['total'] ?>명
            </div>
            <div style="font-size: 8pt; color: #64748b; margin-top: 1px;">
              근로자대표: <strong>윤택천</strong> 반장
            </div>
            <div style="margin-top: 3px; padding-top: 3px; border-top: 1px dashed #e0e7ff; font-size: 7.5pt; font-weight: 700; color: #4f46e5;">
              ⚡ 급박한 위험 시 대피 및 작업중지 요청권 · 위험 개선의견 개진권
            </div>
          </div>
        </div>

        <!-- 2. ▣ 부서별 인원 편성 현황 -->
        <h2 class="section-h4"><span style="color: #2563eb;">▣</span> 부서별 인원 편성 현황 (<?= $escape($currentRoster['formatted_date']) ?> 현재 기준 재직 총 <?= $currentRoster['total'] ?>명)</h2>
        <div class="roster-grid">
          <?php foreach ($currentRoster['teams'] as $team): ?>
            <div class="roster-card <?= $escape($team['class']) ?>">
              <div class="roster-card-header">
                <span class="roster-team-title"><?= $escape($team['title']) ?></span>
                <span class="roster-team-count"><?= count($team['members']) ?>명</span>
              </div>
              <div>
                <?php foreach ($team['members'] as $m): ?>
                  <span class="roster-member-pill <?= $m['leader'] ? 'leader' : '' ?>" <?= !empty($m['badge']) ? 'title="' . $escape($m['badge']) . '"' : '' ?>>
                    <?= $escape($m['pos']) ?> <?= $escape($m['name']) ?><?= !empty($m['badge']) ? ' (' . $escape($m['badge']) . ')' : '' ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Page 1 Bottom Footer -->
      <footer class="doc-footer">
        <?php if ($logoBase64): ?>
          <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
        <?php endif; ?>
        <span class="doc-footer-company">주식회사 현대기전</span>
      </footer>
    </article>


    <!-- PAGE 2: 안전관계자의 책무 및 법정 고유 권한 -->
    <article class="a4-page" aria-label="2페이지: 안전관계자의 책무">
      <div class="page-content">
        <!-- Document Top Title Bar -->
        <div class="doc-top-bar">
          <div>
            <div class="doc-corp-sub">(주)현대기전 안전보건경영체계</div>
            <h1 class="doc-main-title">안전관계자의 책무 및 법정 고유 권한</h1>
          </div>
          <div class="doc-meta-right">
            <div>표준 규정: <strong>안전보건활동계획서 (제Ⅰ편 제3장)</strong></div>
            <div>발효일자: <strong><?= $escape($data['organization']['effective_date'] ?? sprintf('%04d-01-01', $year)) ?></strong> (2/2)</div>
          </div>
        </div>

        <h2 class="section-h4"><span style="color: #2563eb;">▣</span> 안전관계자의 책무 (안전보건활동계획서 제1편 제3장)</h2>

        <!-- Duties Official Table -->
        <table class="plan-duties-table">
          <thead>
            <tr>
              <th style="width: 130px;">구 분</th>
              <th style="width: 110px;">직위 및 성명</th>
              <th>주요 책무 및 법정 역할</th>
              <th style="width: 180px;">부여된 고유 권한 (CSM 핵심)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['organization']['roles'] ?? [] as $r):
              $title = $r['role_title'];
              $dutiesText = trim((string)($r['duties'] ?? ''));
              $dutyLines = preg_split('/\r\n|\r|\n/', $dutiesText);
            ?>
              <tr>
                <td style="font-weight: 800; color: #0f172a; text-align: center; vertical-align: middle; background: #fafafa;">
                  <div style="font-size: 9pt;"><?= $escape($title) ?></div>
                </td>
                <td style="text-align: center; vertical-align: middle;">
                  <div style="font-size: 9.5pt; font-weight: 800; color: #1e293b;"><?= $escape($r['name']) ?></div>
                  <div style="font-size: 8pt; color: #64748b; margin-top: 2px;"><?= $escape($r['position']) ?></div>
                  <?php if (!empty($r['appointed_date'])): ?>
                    <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 2px;">선임: <?= $escape($r['appointed_date']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <ul class="duty-num-list">
                    <?php foreach ($dutyLines as $line):
                      $line = trim($line);
                      if ($line === '') continue;
                    ?>
                      <li><?= $escape($line) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </td>
                <td style="background: #fff7ed; border-left: 2px solid #fdba74;">
                  <div style="font-size: 8pt; font-weight: 800; color: #c2410c; margin-bottom: 2px;">⚡ 법정 고유 권한</div>
                  <div style="font-size: 8pt; color: #9a3412; font-weight: 600; line-height: 1.4;">
                    <?= nl2br($escape($r['authority'])) ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Page 2 Bottom Footer -->
      <footer class="doc-footer">
        <?php if ($logoBase64): ?>
          <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
        <?php endif; ?>
        <span class="doc-footer-company">주식회사 현대기전</span>
      </footer>
    </article>

  </main>

  <script>
    function changePadding(mm) {
      document.documentElement.style.setProperty('--top-pad', mm + 'mm');
      try {
        localStorage.setItem('csm_print_top_padding', mm);
      } catch (e) {}
      document.querySelectorAll('.pad-btn').forEach(btn => {
        if (btn.getAttribute('data-pad') == mm) {
          btn.classList.add('active');
        } else {
          btn.classList.remove('active');
        }
      });
      const txt = document.getElementById('cur-pad-txt');
      if (txt) txt.textContent = mm;
    }

    try {
      const savedPad = localStorage.getItem('csm_print_top_padding') || '28';
      changePadding(parseInt(savedPad, 10));
    } catch (e) {
      changePadding(28);
    }

    window.addEventListener('DOMContentLoaded', () => {
      const params = new URLSearchParams(window.location.search);
      if (params.get('autoprint') === '1' || params.get('print') === '1') {
        setTimeout(() => {
          window.print();
        }, 400);
      }
    });
  </script>
</body>
</html>
