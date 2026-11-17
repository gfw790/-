<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/worker_participation_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$db = worker_part_db();

$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$year = filter_var($_GET['year'] ?? 2026, FILTER_VALIDATE_INT) ?: 2026;

if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM csm_safety_meetings WHERE id = ?');
    $stmt->execute([$id]);
    $meetings = $stmt->fetchAll();
} else {
    $stmt = $db->prepare('SELECT * FROM csm_safety_meetings WHERE year = ? ORDER BY meeting_date ASC, id ASC');
    $stmt->execute([$year]);
    $meetings = $stmt->fetchAll();
}

if (empty($meetings)) {
    http_response_code(404);
    exit('인쇄할 회의록이 없습니다.');
}

// Logo base64
$logoPath = __DIR__ . '/assets/policy-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = dirname(__DIR__) . '/safety_manual/assets/policy-logo.png';
}
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

$koreanDays = ['일', '월', '화', '수', '목', '금', '토'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>산업안전보건위원회 회의록 인쇄 · CSM</title>
  <style>
    :root {
      --top-pad: 20mm;
      --bottom-pad: 12mm;
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
      font-family: 'Malgun Gothic', 'Dotum', sans-serif;
      color: #000;
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
    }
    .btn-print:hover { background: #1e40af; }
    .btn-close {
      background: #f1f5f9;
      color: #475569;
      border-color: #cbd5e1;
    }
    .btn-close:hover { background: #e2e8f0; }

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
    }
    .pad-btn.active {
      background: #1e3a8a;
      color: #fff;
      border-color: #1e3a8a;
    }

    .toolbar-tip {
      font-size: 12px;
      color: #475569;
      background: #f8fafc;
      padding: 6px 14px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }

    /* Paper Container */
    .paper-container {
      max-width: 210mm;
      margin: 20px auto 40px auto;
      display: flex;
      flex-direction: column;
      gap: 30px;
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

    /* 회의록.xlsx Table Structure */
    .meeting-header-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-bottom: 6mm;
    }
    .meeting-doc-title {
      font-size: 24pt;
      font-weight: 900;
      letter-spacing: 12px;
      color: #000;
      padding-left: 10px;
    }
    .approval-table {
      border-collapse: collapse;
      text-align: center;
      font-size: 10pt;
    }
    .approval-table th, .approval-table td {
      border: 1.5px solid #000;
      padding: 3px 6px;
    }
    .approval-table th {
      background: #f8fafc;
      font-weight: 700;
    }
    .approval-table td {
      height: 48px;
      width: 65px;
      vertical-align: middle;
    }
    .approval-title-col {
      width: 22px !important;
      padding: 4px 2px !important;
      line-height: 1.3;
      font-size: 9.5pt;
      font-weight: 700;
    }

    .sheet-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 3.5mm;
      font-size: 10pt;
      table-layout: fixed;
    }
    .sheet-table th, .sheet-table td {
      border: 1.2px solid #000;
      padding: 5px 8px;
      vertical-align: middle;
    }
    .sheet-table th {
      background: #f1f5f9;
      text-align: center;
      font-weight: 700;
      color: #000;
    }
    .cell-center { text-align: center; }

    /* Content Box */
    .section-box-title {
      background: #f1f5f9;
      border: 1.2px solid #000;
      border-bottom: none;
      padding: 4px 8px;
      font-weight: 700;
      font-size: 10pt;
      text-align: center;
    }
    .section-box-content {
      border: 1.2px solid #000;
      padding: 8px 12px;
      min-height: 38mm;
      font-size: 9.5pt;
      line-height: 1.55;
      white-space: pre-wrap;
      margin-bottom: 3.5mm;
    }

    /* Opinions Table */
    .opinion-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.5pt;
      table-layout: fixed;
      margin-bottom: 4mm;
    }
    .opinion-table th, .opinion-table td {
      border: 1.2px solid #000;
      padding: 4px 6px;
      vertical-align: middle;
    }
    .opinion-table th {
      background: #f1f5f9;
      text-align: center;
      font-weight: 700;
    }

    /* Footer outside document */
    .doc-footer {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      padding-top: 3mm;
      border-top: 1px solid #cbd5e1;
      font-size: 9.5pt;
      color: #1e293b;
    }
    .doc-footer img {
      height: 18px;
      width: auto;
      object-fit: contain;
    }

    /* Print Media Rules */
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
    }
  </style>
</head>
<body>

  <!-- Screen Toolbar -->
  <aside class="screen-toolbar no-print">
    <div class="toolbar-left">
      <button type="button" onclick="window.print()" class="toolbar-btn btn-print">
        🖨️ 바로 인쇄하기 (Ctrl+P)
      </button>
      <a href="worker_participation.php?tab=meetings&amp;year=<?= $year ?>" class="toolbar-btn btn-close">
        ← 회의록 목록으로 돌아가기
      </a>
      <div class="pad-control-group">
        <span class="pad-label">상단 여백:</span>
        <button type="button" class="pad-btn" onclick="setTopPad('14mm', this)">14mm (최소)</button>
        <button type="button" class="pad-btn active" onclick="setTopPad('20mm', this)">20mm (표준)</button>
        <button type="button" class="pad-btn" onclick="setTopPad('28mm', this)">28mm (타공)</button>
      </div>
    </div>
    <div class="toolbar-tip">
      💡 <strong>회의록.xlsx</strong> 회사 표준 서식 1:1 반영 &bull; 브라우저 인쇄 설정에서 <strong>[배경 그래픽]</strong>을 켜주세요.
    </div>
  </aside>

  <!-- Paper Container -->
  <div class="paper-container">
    <?php foreach ($meetings as $m):
      $mDate = new DateTimeImmutable($m['meeting_date']);
      $dayOfWeek = $koreanDays[(int)$mDate->format('w')];
      $dateStr = sprintf('%04d 년   %02d월  %02d 일        %s요일', (int)$mDate->format('Y'), (int)$mDate->format('m'), (int)$mDate->format('d'), $dayOfWeek);
      
      // Parse attendees
      $attendees = array_map('trim', explode(',', $m['attendees_text']));
      $attChunk = array_chunk($attendees, 3);
    ?>
    <div class="a4-page">
      <div class="page-content">

        <!-- Header: Title & Approval Table -->
        <div class="meeting-header-row">
          <div class="meeting-doc-title">회  의  록</div>
          <table class="approval-table">
            <tr>
              <th rowspan="2" class="approval-title-col">결<br><br>재</th>
              <th style="width:70px;">담   당</th>
              <th style="width:70px;">관리책임자</th>
            </tr>
            <tr>
              <td></td>
              <td></td>
            </tr>
          </table>
        </div>

        <!-- Date & Location Table -->
        <table class="sheet-table">
          <colgroup>
            <col style="width: 20%;">
            <col style="width: 80%;">
          </colgroup>
          <tr>
            <th colspan="2" style="text-align:center;font-size:11pt;padding:6px;letter-spacing:2px;">
              <?= $escape($dateStr) ?>
            </th>
          </tr>
          <tr>
            <th>회 의  장 소</th>
            <td style="font-weight:600;"><?= $escape($m['meeting_place'] ?: '사무실') ?></td>
          </tr>
          <tr>
            <th>회 의  구 분</th>
            <td><strong><?= $escape($m['meeting_type']) ?></strong> (주관: <?= $escape($m['chairperson']) ?>)</td>
          </tr>
        </table>

        <!-- Attendees Table (3-Columns Signatures) -->
        <table class="sheet-table" style="margin-bottom:3.5mm;">
          <colgroup>
            <col style="width: 16%;">
            <col style="width: 15%;">
            <col style="width: 13%;">
            <col style="width: 15%;">
            <col style="width: 13%;">
            <col style="width: 15%;">
            <col style="width: 13%;">
          </colgroup>
          <tr>
            <th rowspan="<?= max(2, count($attChunk) + 1) ?>" style="font-size:10pt;">참석자<br>명  단</th>
            <th>성   명</th>
            <th>서   명</th>
            <th>성   명</th>
            <th>서   명</th>
            <th>성   명</th>
            <th>서   명</th>
          </tr>
          <?php if (empty($attChunk)): ?>
            <tr><td></td><td>(인)</td><td></td><td>(인)</td><td></td><td>(인)</td></tr>
          <?php else: foreach ($attChunk as $row): ?>
            <tr>
              <td class="cell-center"><strong><?= $escape($row[0] ?? '') ?></strong></td>
              <td class="cell-center" style="color:#64748b;"><?= !empty($row[0]) ? '(서 명)' : '' ?></td>
              <td class="cell-center"><strong><?= $escape($row[1] ?? '') ?></strong></td>
              <td class="cell-center" style="color:#64748b;"><?= !empty($row[1]) ? '(서 명)' : '' ?></td>
              <td class="cell-center"><strong><?= $escape($row[2] ?? '') ?></strong></td>
              <td class="cell-center" style="color:#64748b;"><?= !empty($row[2]) ? '(서 명)' : '' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </table>

        <!-- Meeting Content Box -->
        <div class="section-box-title">회  의  내  용</div>
        <div class="section-box-content" style="min-height:36mm;"><?= $escape($m['agenda']) . "\n\n" . $escape($m['deliberation_content']) ?></div>

        <!-- Decisions Box -->
        <div class="section-box-title">결  정  사  항</div>
        <div class="section-box-content" style="min-height:30mm;"><?= $escape($m['decisions']) ?></div>

        <!-- Worker Opinion Hearing Table -->
        <table class="opinion-table">
          <colgroup>
            <col style="width: 16%;">
            <col style="width: 70%;">
            <col style="width: 14%;">
          </colgroup>
          <tr>
            <th colspan="3" style="text-align:center;font-size:10pt;">근 로 자   의 견   청 취</th>
          </tr>
          <tr>
            <th>성   명</th>
            <th>의   견   내   용</th>
            <th>서   명</th>
          </tr>
          <tr>
            <td class="cell-center"><strong>윤택천</strong><br><small>(근로자대표)</small></td>
            <td style="padding:6px 10px;font-size:9pt;line-height:1.4;">고소작업 시 안전발판 규격품 교체 및 전기실 작업 시 활선접근경보기 지급 요청</td>
            <td class="cell-center" style="color:#64748b;">(서 명)</td>
          </tr>
          <tr>
            <td class="cell-center"><strong>김도담</strong><br><small>(정비반)</small></td>
            <td style="padding:6px 10px;font-size:9pt;line-height:1.4;">자재창고 앞 안전통로 구획 도색 및 보행자/지게차 동선 분리 요청</td>
            <td class="cell-center" style="color:#64748b;">(서 명)</td>
          </tr>
          <tr>
            <td class="cell-center"><strong>우홍기</strong><br><small>(전기반)</small></td>
            <td style="padding:6px 10px;font-size:9pt;line-height:1.4;">변전실 정전 점검 시 조명 확보를 위한 휴대용 충전식 투광등 추가 비치 요청</td>
            <td class="cell-center" style="color:#64748b;">(서 명)</td>
          </tr>
        </table>

      </div>

      <!-- Footer Outside Document Box -->
      <footer class="doc-footer">
        <?php if ($logoBase64): ?>
          <img src="<?= $logoBase64 ?>" alt="로고">
        <?php endif; ?>
        <strong>주식회사 현대기전</strong>
      </footer>
    </div>
    <?php endforeach; ?>
  </div>

  <script>
    function setTopPad(val, btn) {
      document.documentElement.style.setProperty('--top-pad', val);
      document.querySelectorAll('.pad-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    }
  </script>

</body>
</html>
