<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/education_matrix_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_GET['year'] ?? $now->format('Y'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100) {
    http_response_code(400);
    exit('올바른 연도를 선택해 주세요.');
}

$printType = trim((string)($_GET['type'] ?? 'all'));
if (!in_array($printType, ['all', 'special', 'msds'], true)) {
    $printType = 'all';
}

$db = leadership_db();
$matrixData = education_matrix_records($db, $year);
$specialTasks = $matrixData['special']['tasks'] ?? [];
$msdsSubstances = $matrixData['msds']['substances'] ?? [];
$allWorkers = education_matrix_all_workers();

// Load Company Logo (Base64)
$logoPath = __DIR__ . '/assets/policy-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = dirname(__DIR__) . '/safety_manual/assets/policy-logo.png';
}
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

$titleText = match ($printType) {
    'special' => "{$year}년도_특별안전교육_메트릭스_인쇄",
    'msds'    => "{$year}년도_MSDS_교육_메트릭스_인쇄",
    default   => "{$year}년도_교육_메트릭스_특별안전_MSDS_종합_인쇄",
};
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $escape($titleText) ?></title>
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
      flex-wrap: wrap;
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
      background: #f8fafc;
      color: #475569;
      border-color: #cbd5e1;
    }
    .btn-close:hover {
      background: #f1f5f9;
      color: #0f172a;
    }

    .pad-control-group {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      background: #f8fafc;
      padding: 3px 6px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 12px;
    }
    .pad-label {
      color: #64748b;
      font-weight: 600;
      margin-right: 2px;
    }
    .pad-btn {
      padding: 4px 8px;
      font-size: 11.5px;
      font-weight: 700;
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      background: #fff;
      cursor: pointer;
      color: #334155;
      text-decoration: none;
    }
    .pad-btn.active {
      background: #1e3a8a;
      color: #fff;
      border-color: #1e3a8a;
    }

    .toolbar-tip {
      font-size: 12px;
      color: #64748b;
    }

    /* Paper Container */
    .paper-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 30px;
      padding: 30px 10px;
    }

    /* Standard A4 Landscape Page */
    .a4-page {
      background: #ffffff;
      width: 297mm;
      height: 210mm;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad);
      box-sizing: border-box;
      overflow: hidden;
      border: 1px solid #e2e8f0;
    }

    .page-content {
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    /* Document Top Bar */
    .doc-top-bar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 5px;
      border-bottom: 2px solid #0f172a;
      padding-bottom: 4px;
    }
    .doc-corp-sub {
      font-size: 7.5pt;
      font-weight: 800;
      color: #0284c7;
      letter-spacing: 1px;
      text-transform: uppercase;
      margin-bottom: 2px;
    }
    .doc-main-title {
      font-size: 14pt;
      font-weight: 900;
      color: #0f172a;
      letter-spacing: -0.5px;
      margin: 0;
      line-height: 1.2;
    }

    /* Approval Table: 2-stage (작성 / 승인) */
    .approval-table {
      border-collapse: collapse;
      border: 1.5px solid #334155;
      font-size: 7.5pt;
      text-align: center;
      background: #ffffff;
      margin-left: auto;
    }
    .approval-table th, .approval-table td {
      border: 1px solid #94a3b8;
      padding: 2px 4px;
    }
    .approval-side-th {
      background: #f1f5f9;
      color: #1e293b;
      font-weight: 800;
      width: 18px;
      vertical-align: middle;
      font-size: 7pt;
      line-height: 1.3;
    }
    .approval-title-th {
      background: #f1f5f9;
      color: #1e293b;
      font-weight: 700;
      width: 50px;
      height: 18px;
      vertical-align: middle;
      font-size: 7.5pt;
    }
    .approval-sign-td {
      height: 40px;
      width: 50px;
      vertical-align: middle;
      background: #ffffff;
    }

    /* Tables */
    .doc-table {
      width: 100%;
      border-collapse: collapse;
      border: 1.5px solid #0f172a;
      font-size: 7.2pt;
      background: #ffffff;
      margin-bottom: 4px;
    }
    .doc-table th {
      background: #f1f5f9;
      color: #0f172a;
      font-weight: 800;
      padding: 3px 5px;
      border: 1px solid #64748b;
      text-align: center;
      font-size: 7.4pt;
      white-space: nowrap;
    }
    .doc-table td {
      padding: 3px 5px;
      border: 1px solid #64748b;
      vertical-align: middle;
      line-height: 1.25;
      color: #1e293b;
    }

    /* Fixed Layout 2D Matrix Table for A4 Printing */
    .matrix-grid-print-table {
      width: 100%;
      table-layout: fixed;
      border-collapse: collapse;
      border: 1.5px solid #0f172a;
      font-size: 6.8pt;
      background: #ffffff;
      margin-bottom: 4px;
      box-sizing: border-box;
    }
    .matrix-grid-print-table th, .matrix-grid-print-table td {
      border: 1px solid #64748b;
      vertical-align: middle;
      box-sizing: border-box;
      padding: 2px 1px;
      overflow: hidden;
      text-align: center;
    }
    .matrix-grid-print-table th {
      background: #f1f5f9;
      color: #0f172a;
      font-weight: 800;
      white-space: normal;
      word-break: break-all;
      word-wrap: break-word;
      line-height: 1.15;
    }
    .matrix-grid-print-table td {
      line-height: 1.2;
      color: #1e293b;
    }

    .badge-done-print {
      display: inline-block;
      padding: 1px 4px;
      border-radius: 3px;
      font-size: 7pt;
      font-weight: 800;
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #86efac;
      white-space: nowrap;
    }

    /* Document Bottom Footer */
    .doc-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
      height: 6mm;
      margin-top: 1mm;
      padding-right: 4px;
      flex-shrink: 0;
      border-top: 1px solid #cbd5e1;
      padding-top: 2px;
    }
    .doc-footer-logo {
      height: 4.5mm;
      width: auto;
      object-fit: contain;
    }
    .doc-footer-company {
      font-size: 8.5pt;
      font-weight: 800;
      letter-spacing: 2px;
      color: #1e293b;
    }

    /* Strict Browser Print Rules */
    @page {
      size: A4 landscape;
      margin: 0;
    }

    @media print {
      html, body {
        background: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 297mm !important;
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
        width: 297mm !important;
        height: 210mm !important;
        min-height: 210mm !important;
        max-height: 210mm !important;
        margin: 0 !important;
        padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad) !important;
        box-shadow: none !important;
        border: none !important;
        page-break-after: always !important;
        break-after: page !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Toolbar -->
  <aside class="screen-toolbar no-print" aria-label="출력 설정 도구">
    <div class="toolbar-left">
      <button class="toolbar-btn btn-print" onclick="window.print()">
        🖨️ 인쇄 (A4 가로 출력)
      </button>

      <!-- Print Mode Filters -->
      <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 3px; border-radius: 8px;">
        <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=all" class="pad-btn <?= $printType === 'all' ? 'active' : '' ?>">
          📄 전체 인쇄 (4P)
        </a>
        <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=special" class="pad-btn <?= $printType === 'special' ? 'active' : '' ?>">
          ⚡ 특별안전교육만 (2P)
        </a>
        <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=msds" class="pad-btn <?= $printType === 'msds' ? 'active' : '' ?>">
          🧪 MSDS 교육만 (2P)
        </a>
      </div>

      <a href="education_matrix.php?year=<?= $year ?>&amp;tab=<?= $printType === 'msds' ? 'msds' : 'special' ?>" class="toolbar-btn btn-close">
        ✕ 화면으로 복귀
      </a>

      <!-- Binder Padding Controls -->
      <div class="pad-control-group">
        <span class="pad-label">상단 철 여백:</span>
        <button class="pad-btn" onclick="setPad(20)">20mm</button>
        <button class="pad-btn" onclick="setPad(25)">25mm</button>
        <button class="pad-btn active" onclick="setPad(28)">28mm(표준)</button>
        <button class="pad-btn" onclick="setPad(32)">32mm</button>
      </div>
    </div>

    <div class="toolbar-tip">
      💡 브라우저 인쇄 설정에서 <strong>방향: 가로 (Landscape)</strong>, <strong>여백: 없음 (None)</strong>, <strong>배경 그래픽 포함</strong>을 체크해 주세요.
    </div>
  </aside>

  <div class="paper-container">

    <?php if ($printType === 'all' || $printType === 'special'): ?>
      <!-- ================= [종류 1] 특별안전교육 1페이지: 계획 및 대상 작업 대장 ================= -->
      <section class="a4-page" aria-label="특별안전교육 메트릭스 1페이지">
        <div class="page-content">

          <!-- Top Header & Approval Block -->
          <div class="doc-top-bar">
            <div>
              <div class="doc-corp-sub">SPECIAL SAFETY EDUCATION PLAN &amp; MATRIX</div>
              <h1 class="doc-main-title"><?= $year ?>년도 특별안전보건교육 대상 작업 및 교육이수 Matrix</h1>
              <div style="font-size: 7.5pt; color: #475569; margin-top: 2px;">
                근거: 산업안전보건법 제29조제3항 및 시행규칙 별표 5 제1호 라목 (CSM 평가항목 5.1.4 배점 1.0점)
              </div>
            </div>

            <!-- Document Approval Stamp -->
            <table class="approval-table">
              <tr>
                <th class="approval-side-th" rowspan="2">결<br>재</th>
                <th class="approval-title-th">작 성</th>
                <th class="approval-title-th">승 인</th>
              </tr>
              <tr>
                <td class="approval-sign-td"></td>
                <td class="approval-sign-td"></td>
              </tr>
            </table>
          </div>

          <!-- Section 1 Table: 특별안전교육 (작업내용 / 교육일 / 교육받는사람) -->
          <table class="doc-table">
            <thead>
              <tr>
                <th style="width: 35px;">법정호</th>
                <th style="width: 220px;">작업내용 (수행 위험작업)</th>
                <th style="width: 180px;">현장 세부작업 예시</th>
                <th style="width: 45px;">빈도</th>
                <th style="width: 150px;">교육일정 및 시간</th>
                <th>교육받는사람 (해당 작업 수행자)</th>
                <th style="width: 55px;">인원</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($specialTasks as $t): ?>
                <tr>
                  <td style="text-align: center; font-weight: 800; color: #1e40af;"><?= $escape($t['task_code']) ?></td>
                  <td>
                    <strong style="color: #0f172a;"><?= $escape($t['task_name']) ?></strong>
                  </td>
                  <td style="font-size: 7pt; color: #334155;"><?= $escape($t['task_desc']) ?></td>
                  <td style="text-align: center; font-size: 7pt;"><?= $escape($t['work_freq']) ?></td>
                  <td style="font-size: 7pt;">
                    <?php foreach ($t['edu_dates'] as $d): ?>
                      <div>[<?= $escape($d['session']) ?>] <?= $escape($d['date']) ?> (<?= (int)$d['hours'] ?>HR)</div>
                    <?php endforeach; ?>
                  </td>
                  <td style="font-size: 7.2pt; font-weight: 600; color: #1e3a8a;">
                    <?= $escape(implode(', ', $t['assigned_workers'])) ?>
                  </td>
                  <td style="text-align: center; font-weight: 800; color: #1e40af;"><?= count($t['assigned_workers']) ?>명</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="font-size: 7pt; color: #475569; line-height: 1.35; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px 8px;">
            ※ 본 메트릭스는 법정 1~39호 항목 중 당사 실제 공정에 해당하는 10개 위험작업에 대하여 작업 전 필수 교육 대상 작업 및 인원을 편성하여 관리합니다.
          </div>

        </div>

        <!-- Footer -->
        <footer class="doc-footer" aria-label="바닥글">
          <?php if ($logoBase64): ?>
            <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
          <?php endif; ?>
          <span class="doc-footer-company">주식회사 현대기전</span>
          <span style="font-size: 7pt; color: #94a3b8; margin-left: 8px;">
            <?= $printType === 'special' ? 'Page 1 of 2 (특별안전교육)' : 'Page 1 of 4 (특별안전교육)' ?>
          </span>
        </footer>
      </section>


      <!-- ================= [종류 1] 특별안전교육 2페이지: 전 근로자 대상작업 2D 매트릭스 ================= -->
      <section class="a4-page" aria-label="특별안전교육 근로자별 2D 매트릭스">
        <div class="page-content">

          <!-- Top Header & Approval Block -->
          <div class="doc-top-bar">
            <div>
              <div class="doc-corp-sub">SPECIAL SAFETY EDUCATION WORKER ASSIGNMENT GRID</div>
              <h1 class="doc-main-title"><?= $year ?>년도 특별안전교육 대상 작업별 전 근로자(20명) 대조 Matrix</h1>
              <div style="font-size: 7.5pt; color: #475569; margin-top: 2px;">
                사업장 소속 전 근로자(20명) 기준 법정 10대 위험작업(●) 실명 매핑 및 대상자 지정 총괄
              </div>
            </div>

            <!-- Document Approval Stamp -->
            <table class="approval-table">
              <tr>
                <th class="approval-side-th" rowspan="2">결<br>재</th>
                <th class="approval-title-th">작 성</th>
                <th class="approval-title-th">승 인</th>
              </tr>
              <tr>
                <td class="approval-sign-td"></td>
                <td class="approval-sign-td"></td>
              </tr>
            </table>
          </div>

          <!-- Special Safety 2D Grid Table -->
          <table class="matrix-grid-print-table" style="font-size: 6.8pt; text-align: center;">
            <thead>
              <tr>
                <th rowspan="2" style="width: 8%; font-size: 7.2pt;">성명</th>
                <th colspan="<?= count($specialTasks) ?>" style="background: #e0f2fe; color: #0369a1; font-size: 7.2pt; padding: 3px 2px;">
                  ⚡ 법정 특별안전보건교육 대상 작업 (10종)
                </th>
                <th rowspan="2" style="width: 8%; font-size: 7pt;">작업수</th>
              </tr>
              <tr>
                <?php foreach ($specialTasks as $t): ?>
                  <th style="width: 8.4%; font-size: 5.2pt; padding: 3px 1px; vertical-align: top; white-space: normal; word-break: break-all; line-height: 1.15;" title="<?= $escape($t['task_name']) ?>">
                    <div style="font-weight: 800; color: #1e40af; font-size: 6.8pt; margin-bottom: 2px;"><?= $escape($t['task_code']) ?></div>
                    <div style="color: #0f172a; letter-spacing: -0.4px;">
                      <?= nl2br($escape($t['task_name'])) ?>
                    </div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allWorkers as $w): ?>
                <?php
                  $wName = $w['name'];
                  $sCnt = 0;
                ?>
                <tr>
                  <td style="font-weight: 700; color: #0f172a; white-space: nowrap; font-size: 7pt;"><?= $escape($wName) ?></td>

                  <?php foreach ($specialTasks as $t): ?>
                    <?php
                      $isS = in_array($wName, $t['assigned_workers'] ?? [], true);
                      if ($isS) $sCnt++;
                    ?>
                    <td style="font-weight: 800; color: #1e40af; background: <?= $isS ? '#eff6ff' : '' ?>;">
                      <?= $isS ? '●' : '-' ?>
                    </td>
                  <?php endforeach; ?>

                  <td style="font-weight: 800; color: #1e40af;"><?= $sCnt ?>개</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="background: #f8fafc; font-weight: 800; font-size: 6.8pt;">
                <td colspan="1" style="text-align: center; color: #1e3a8a; font-size: 7pt; font-weight: 800;">합계</td>
                <?php foreach ($specialTasks as $t): ?>
                  <td style="text-align: center; color: #1e40af; font-weight: 800;">
                    <?= count($t['assigned_workers'] ?? []) ?>
                  </td>
                <?php endforeach; ?>
                <td style="text-align: center; color: #1e3a8a;">80명</td>
              </tr>
            </tfoot>
          </table>

          <div style="margin-top: 4px; font-size: 7pt; color: #1e40af; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 4px 8px;">
            <strong>※ 특별안전교육 매핑 기준:</strong> 사업장 공정상 10대 위험작업에 투입되는 근로자 80명(연인원) 전원에 대해 작업 투입 전 필수 특별안전보건교육 대상자로 지정하여 관리합니다.
          </div>

        </div>

        <!-- Footer -->
        <footer class="doc-footer" aria-label="바닥글">
          <?php if ($logoBase64): ?>
            <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
          <?php endif; ?>
          <span class="doc-footer-company">주식회사 현대기전</span>
          <span style="font-size: 7pt; color: #94a3b8; margin-left: 8px;">
            <?= $printType === 'special' ? 'Page 2 of 2 (특별안전교육)' : 'Page 2 of 4 (특별안전교육)' ?>
          </span>
        </footer>
      </section>
    <?php endif; ?>


    <?php if ($printType === 'all' || $printType === 'msds'): ?>
      <!-- ================= [종류 2] MSDS 교육 1페이지: 화학물질별 교육 대장 ================= -->
      <section class="a4-page" aria-label="MSDS 교육 메트릭스 1페이지">
        <div class="page-content">

          <!-- Top Header & Approval Block -->
          <div class="doc-top-bar">
            <div>
              <div class="doc-corp-sub">MSDS CHEMICAL EDUCATION PLAN &amp; MATRIX</div>
              <h1 class="doc-main-title"><?= $year ?>년도 물질안전보건자료(MSDS) 교육 계획 및 Matrix</h1>
              <div style="font-size: 7.5pt; color: #475569; margin-top: 2px;">
                근거: 산업안전보건법 제114조 및 고용노동부고시 화학물질 취급 안전기준 (CSM 평가항목 5.1.5 배점 1.0점)
              </div>
            </div>

            <!-- Document Approval Stamp -->
            <table class="approval-table">
              <tr>
                <th class="approval-side-th" rowspan="2">결<br>재</th>
                <th class="approval-title-th">작 성</th>
                <th class="approval-title-th">승 인</th>
              </tr>
              <tr>
                <td class="approval-sign-td"></td>
                <td class="approval-sign-td"></td>
              </tr>
            </table>
          </div>

          <!-- Section 2 Table: MSDS (물질 / 교육일 / 교육받는사람) -->
          <table class="doc-table">
            <thead>
              <tr>
                <th style="width: 35px;">코드</th>
                <th style="width: 170px;">취급 화학물질명</th>
                <th style="width: 130px;">물질분류 및 장소</th>
                <th style="width: 170px;">주요 유해위험성 및 필수보호구</th>
                <th style="width: 130px;">교육일정 및 시간</th>
                <th>교육받는사람 (해당 물질 취급자)</th>
                <th style="width: 55px;">인원</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($msdsSubstances as $s): ?>
                <tr>
                  <td style="text-align: center; font-weight: 800; color: #059669;"><?= $escape($s['code']) ?></td>
                  <td>
                    <strong style="color: #0f172a;"><?= $escape($s['name']) ?></strong>
                    <?php if (!empty($s['manufacturer'])): ?>
                      <div style="font-size: 6.5pt; color: #475569;">(<?= $escape($s['manufacturer']) ?>)</div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size: 7pt; color: #334155;">
                    <div><?= $escape($s['category']) ?></div>
                    <div style="color: #64748b;">[장소] <?= $escape($s['usage_place']) ?></div>
                  </td>
                  <td style="font-size: 7pt;">
                    <div style="color: #dc2626;">⚠ <?= $escape($s['danger_point']) ?></div>
                    <div style="color: #1e40af;">🛡 <?= $escape($s['ppe_required']) ?></div>
                  </td>
                  <td style="font-size: 7pt;">
                    <?php foreach ($s['edu_dates'] as $d): ?>
                      <div>[<?= $escape($d['session']) ?>] <?= $escape($d['date']) ?> (<?= (int)$d['hours'] ?>HR)</div>
                    <?php endforeach; ?>
                  </td>
                  <td style="font-size: 7.2pt; font-weight: 600; color: #047857;">
                    <?= $escape(implode(', ', $s['assigned_workers'])) ?>
                  </td>
                  <td style="text-align: center; font-weight: 800; color: #059669;"><?= count($s['assigned_workers']) ?>명</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="font-size: 7pt; color: #475569; line-height: 1.35; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px 8px;">
            ※ 본 MSDS 교육은 현장에 반입되는 <?= count($msdsSubstances) ?>종 유해화학물질의 취급 근로자 전원을 대상으로 반기별 2시간 이상 정기 교육 계획에 따라 실시 관리합니다.
          </div>

        </div>

        <!-- Footer -->
        <footer class="doc-footer" aria-label="바닥글">
          <?php if ($logoBase64): ?>
            <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
          <?php endif; ?>
          <span class="doc-footer-company">주식회사 현대기전</span>
          <span style="font-size: 7pt; color: #94a3b8; margin-left: 8px;">
            <?= $printType === 'msds' ? 'Page 1 of 2 (MSDS 교육)' : 'Page 3 of 4 (MSDS 교육)' ?>
          </span>
        </footer>
      </section>


      <!-- ================= [종류 2] MSDS 교육 2페이지: 화학물질별 전 근로자 취급 2D 매트릭스 ================= -->
      <section class="a4-page" aria-label="MSDS 화학물질 근로자별 2D 매트릭스">
        <div class="page-content">

          <!-- Top Header & Approval Block -->
          <div class="doc-top-bar">
            <div>
              <div class="doc-corp-sub">MSDS CHEMICAL SUBSTANCE WORKER ASSIGNMENT GRID</div>
              <h1 class="doc-main-title"><?= $year ?>년도 물질안전보건자료(MSDS) 대상 물질별 전 근로자(20명) 취급 Matrix</h1>
              <div style="font-size: 7.5pt; color: #475569; margin-top: 2px;">
                사업장 소속 전 근로자(20명) 기준 시스템 등록 12종 유해화학물질(◎) 실명 취급 매핑 총괄
              </div>
            </div>

            <!-- Document Approval Stamp -->
            <table class="approval-table">
              <tr>
                <th class="approval-side-th" rowspan="2">결<br>재</th>
                <th class="approval-title-th">작 성</th>
                <th class="approval-title-th">승 인</th>
              </tr>
              <tr>
                <td class="approval-sign-td"></td>
                <td class="approval-sign-td"></td>
              </tr>
            </table>
          </div>

          <!-- MSDS 2D Grid Table -->
          <table class="matrix-grid-print-table" style="font-size: 6.8pt; text-align: center;">
            <thead>
              <tr>
                <th rowspan="2" style="width: 8%; font-size: 7pt;">부서</th>
                <th rowspan="2" style="width: 6%; font-size: 7pt;">직급</th>
                <th rowspan="2" style="width: 7%; font-size: 7pt;">성명</th>
                <th colspan="<?= count($msdsSubstances) ?>" style="background: #dcfce7; color: #15803d; font-size: 7.2pt; padding: 3px 2px;">
                  🧪 시스템 등록 MSDS 취급 화학물질 (12종)
                </th>
                <th rowspan="2" style="width: 7%; font-size: 6.8pt;">물질수</th>
              </tr>
              <tr>
                <?php foreach ($msdsSubstances as $s): ?>
                  <th style="width: 6%; font-size: 5pt; padding: 2px 1px; vertical-align: top; white-space: normal; word-break: break-all; line-height: 1.15;" title="<?= $escape($s['name']) ?> (<?= $escape($s['manufacturer'] ?? '') ?>)">
                    <div style="font-weight: 800; color: #059669; font-size: 6.8pt; margin-bottom: 2px;"><?= $escape($s['code']) ?></div>
                    <div style="color: #0f172a; letter-spacing: -0.4px;"><?= nl2br($escape($s['name'])) ?></div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allWorkers as $w): ?>
                <?php
                  $wName = $w['name'];
                  $mCnt = 0;
                ?>
                <tr>
                  <td style="text-align: left; font-size: 6.8pt;"><?= $escape($w['dept']) ?></td>
                  <td style="font-size: 6.8pt; color: #64748b;"><?= $escape($w['pos']) ?></td>
                  <td style="font-weight: 700; color: #0f172a; white-space: nowrap;"><?= $escape($wName) ?></td>

                  <?php foreach ($msdsSubstances as $s): ?>
                    <?php
                      $isM = in_array($wName, $s['assigned_workers'] ?? [], true);
                      if ($isM) $mCnt++;
                    ?>
                    <td style="font-weight: 800; color: #059669; background: <?= $isM ? '#f0fdf4' : '' ?>;">
                      <?= $isM ? '◎' : '-' ?>
                    </td>
                  <?php endforeach; ?>

                  <td style="font-weight: 800; color: #059669;"><?= $mCnt ?>종</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="background: #f8fafc; font-weight: 800; font-size: 6.8pt;">
                <td colspan="3" style="text-align: center; color: #059669;">물질별 총 취급인원 합계</td>
                <?php foreach ($msdsSubstances as $s): ?>
                  <td style="text-align: center; color: #059669; font-weight: 800;">
                    <?= count($s['assigned_workers'] ?? []) ?>
                  </td>
                <?php endforeach; ?>
                <td style="text-align: center; color: #059669;">82명</td>
              </tr>
            </tfoot>
          </table>

          <div style="margin-top: 4px; font-size: 7pt; color: #047857; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; padding: 4px 8px;">
            <strong>※ MSDS 교육 매핑 기준:</strong> 12종 화학물질을 취급하는 근로자 82명(연인원) 전원에 대해 물질안전보건자료 특별교육 대상으로 지정하여 관리합니다.
          </div>

        </div>

        <!-- Footer -->
        <footer class="doc-footer" aria-label="바닥글">
          <?php if ($logoBase64): ?>
            <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
          <?php endif; ?>
          <span class="doc-footer-company">주식회사 현대기전</span>
          <span style="font-size: 7pt; color: #94a3b8; margin-left: 8px;">
            <?= $printType === 'msds' ? 'Page 2 of 2 (MSDS 교육)' : 'Page 4 of 4 (MSDS 교육)' ?>
          </span>
        </footer>
      </section>
    <?php endif; ?>

  </div>

  <script>
    function setPad(mm) {
      document.documentElement.style.setProperty('--top-pad', mm + 'mm');
      document.querySelectorAll('.pad-btn').forEach(function(b) {
        b.classList.remove('active');
        if (b.textContent.indexOf(mm + 'mm') !== -1) {
          b.classList.add('active');
        }
      });
    }
  </script>
</body>
</html>
