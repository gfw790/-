<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

$user = csm_require_auth();
$db = get_csm_db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('올바른 교육 ID를 지정해 주세요.');
}

$stmt = $db->prepare("SELECT * FROM csm_trainings WHERE id = ?");
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) {
    http_response_code(404);
    exit('교육일지를 찾을 수 없습니다.');
}

// Fetch Attendees
$attStmt = $db->prepare("SELECT * FROM csm_training_attendees WHERE training_id = ? ORDER BY id ASC");
$attStmt->execute([$id]);
$attendees = $attStmt->fetchAll();

// Fetch Photos
$pStmt = $db->prepare("SELECT * FROM csm_training_photos WHERE training_id = ? AND is_print = 1 ORDER BY display_order ASC, id ASC");
$pStmt->execute([$id]);
$photos = $pStmt->fetchAll();

// Date formatting
$dt = date_create($t['training_date']);
$weekdays = ['일요일', '월요일', '화요일', '수요일', '목요일', '금요일', '토요일'];
$wStr = $dt ? $weekdays[(int)$dt->format('w')] : '';
$timeStr = ($t['start_time'] && $t['end_time']) ? "{$t['start_time']}~{$t['end_time']}" : "13:00~15:00";

if (str_contains($t['subject'] ?? '', '2일간') || ($t['training_no'] ?? '') === 'EDU-2026-0118') {
    $dt2 = (clone $dt)->modify('+1 day');
    $wStr2 = $weekdays[(int)$dt2->format('w')];
    $dateLine = sprintf("%d년   %02d월   %02d일(%s) ~ %02d월 %02d일(%s) [2일간]   %s (총 %d시간)",
        (int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'), mb_substr($wStr, 0, 1),
        (int)$dt2->format('m'), (int)$dt2->format('d'), mb_substr($wStr2, 0, 1),
        $timeStr, (int)$t['planned_hours']
    );
} else {
    $dateLine = sprintf("%d년   %02d월   %02d일   %s %s",
        (int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'), $wStr, $timeStr
    );
}

// Training Types Checkboxes
$ttype = strtoupper((string)$t['training_type']);
$chkNewHire = in_array($ttype, ['NEW_HIRE', '신규채용', '신규채용시'], true);
$chkRegular = in_array($ttype, ['REGULAR', '정기', '정기안전', '정기안전보건'], true);
$chkJobChange = in_array($ttype, ['JOB_CHANGE', '작업변경', '작업내용변경시'], true);
$chkSupervisor = in_array($ttype, ['SUPERVISOR', '관리감독자', '관리감독자교육'], true);
$chkSpecial = in_array($ttype, ['SPECIAL', '특별안전', '특별교육'], true);
$chkExternal = in_array($ttype, ['EXTERNAL', 'INVITED', '외부강사'], true);
$chkOther = in_array($ttype, ['OTHER', '기타'], true);
$chkMsds = in_array($ttype, ['MSDS', 'MSDS교육'], true);

// Headcount
$completedAtt = array_filter($attendees, fn($a) => (int)$a['is_completed'] === 1);
$totalCompleted = count($completedAtt);
$totalTarget = count($attendees);

// 2-Column Content Summary
$leftVal = (string)($t['content_summary_left'] ?? '');
$rightVal = (string)($t['content_summary_right'] ?? '');

if ($leftVal === '' && $rightVal === '' && !empty($t['content_summary'])) {
    $rawLines = array_values(array_filter(array_map('trim', explode("\n", (string)$t['content_summary']))));
    $half = (int)ceil(count($rawLines) / 2);
    $leftLines = array_slice($rawLines, 0, max(4, $half));
    $rightLines = array_slice($rawLines, max(4, $half));
} else {
    $leftLines = array_values(array_filter(array_map('trim', explode("\n", $leftVal))));
    $rightLines = array_values(array_filter(array_map('trim', explode("\n", $rightVal))));
}

if ($ttype === 'MSDS' || !empty($t['msds_material_name'])) {
    $msdsHdr = sprintf("◈ MSDS 대상물질: %s (자료ID: %s, 개정일: %s, %s차)",
        $t['msds_material_name'] ?: '물질',
        $t['msds_material_id'] ?: '-',
        $t['msds_revision_date'] ?: '-',
        $t['msds_revision_count'] ?: '-'
    );
    array_unshift($leftLines, $msdsHdr);
}

function format_csm_content_line(string $line): string {
    $trimmed = trim($line);
    if ($trimmed === '') {
        return '<div class="content-blank-line"></div>';
    }
    
    // Check if header e.g. [2일간...], ◈...◈
    if (preg_match('/^(\[[^\]]+\]|◈[^◈]+◈)(.*)$/u', $trimmed, $m)) {
        return '<div class="content-item content-hdr">' . htmlspecialchars($trimmed) . '</div>';
    }
    
    // Level 3 outline numbering: e.g. 2.1.1, 2.1.1., 2.1.1), a., b., (a), (b)
    if (preg_match('/^(\d+\.\d+\.\d+(\.|\))?|[a-z]\.|\([a-z]\))\s*(.*)$/ui', $trimmed, $m)) {
        return '<div class="content-item content-sub-2">' .
               '<span class="item-num">' . htmlspecialchars($m[1]) . '</span>' .
               '<span class="item-text">' . htmlspecialchars($m[3]) . '</span>' .
               '</div>';
    }

    // Level 2 outline numbering: e.g. 2.1, 2.1., 2.1), 1), 2), (1), (2), ①, ②
    // (개요번호 2에서 2.1로 증가 시 들여쓰기 적용)
    if (preg_match('/^(\d+\.\d+(\.|\))?|\d+\)|\([0-9]+\)|[①-⑳])\s*(.*)$/u', $trimmed, $m)) {
        return '<div class="content-item content-sub">' .
               '<span class="item-num">' . htmlspecialchars($m[1]) . '</span>' .
               '<span class="item-text">' . htmlspecialchars($m[3]) . '</span>' .
               '</div>';
    }
    
    // Bullets or special lines: -, ·, *, ※, ★
    if (preg_match('/^([\-|\·|\*|※|★])\s*(.*)$/u', $trimmed, $m)) {
        return '<div class="content-item content-bullet">' .
               '<span class="item-num">' . htmlspecialchars($m[1]) . '</span>' .
               '<span class="item-text">' . htmlspecialchars($m[2]) . '</span>' .
               '</div>';
    }

    // Level 1 main outline numbering: 1., 2., 3., 가., 나., 다., etc.
    if (preg_match('/^(\d+\.|[가-하]\.|[IVXLCDM]+\.)\s*(.*)$/u', $trimmed, $m)) {
        return '<div class="content-item content-main">' .
               '<span class="item-num">' . htmlspecialchars($m[1]) . '</span>' .
               '<span class="item-text">' . htmlspecialchars($m[2]) . '</span>' .
               '</div>';
    }
    
    // Default: Plain line
    return '<div class="content-item content-plain">' .
           '<span class="item-text">' . htmlspecialchars($trimmed) . '</span>' .
           '</div>';
}

// Evaluation Opinion
$cleanOpinion = trim(str_replace(['◈ 교육평가 및 의견 ◈', '◈'], '', (string)($t['evaluation_opinion'] ?? '')));

// Attendees 4 columns x 6 rows (24 slots)
$maxRows = 6;
$gridCols = 4;
$grid = array_fill(0, $maxRows, array_fill(0, $gridCols, ''));

foreach ($attendees as $idx => $att) {
    $col = intdiv($idx, $maxRows);
    $row = $idx % $maxRows;
    if ($col < $gridCols) {
        $cleanName = trim(explode('(', $att['name_snapshot'] ?? '')[0]);
        $grid[$row][$col] = $cleanName;
    }
}

// Load Company Logo (Base64)
$logoPath = dirname(__DIR__) . '/assets/policy-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = dirname(dirname(__DIR__)) . '/safety_manual/assets/policy-logo.png';
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
  <title><?= htmlspecialchars($t['training_no']) ?>_안전보건교육일지_인쇄</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;600;700;900&display=swap');

    :root {
      --top-pad: 28mm;
      --bottom-pad: 8mm; /* 문서 하단 회사 정보 배치 공간 확보 */
      --left-pad: 13mm;
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
      background: #2563eb;
      color: #fff;
      box-shadow: 0 2px 4px rgba(37,99,235,0.25);
    }
    .btn-print:hover {
      background: #1d4ed8;
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

    /* Padding selector buttons */
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
      background: #2563eb;
      color: #fff;
      border-color: #2563eb;
      font-weight: 700;
    }

    .toolbar-tip {
      font-size: 11px;
      color: #475569;
      background: #f8fafc;
      padding: 6px 12px;
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
      max-height: 297mm;
      background: #fff;
      padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad); /* 항상 패딩으로 편철 여백 유지 */
      box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      position: relative;
      box-sizing: border-box;
      overflow: hidden;
    }

    /* Table Styles strictly matching 회사 서식 (교육일지_서식사본.xlsx) */
    table.csm-sheet {
      width: 100%;
      height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm);
      max-height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm);
      border-collapse: collapse;
      table-layout: fixed;
      border: 2px solid #000;
      font-size: 8.5pt;
      line-height: 1.35;
    }

    table.csm-sheet td,
    table.csm-sheet th {
      border: 1px solid #000;
      padding: 2px 4px;
      vertical-align: middle;
      color: #000;
    }

    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    .bold { font-weight: 700; }

    /* Title & Approval Stamp */
    .title-cell {
      padding: 6px 4px;
      vertical-align: middle;
      text-align: center;
    }

    .title-text {
      font-size: 19.5pt;
      font-weight: 900;
      letter-spacing: 6px;
      text-align: center;
      color: #000;
      line-height: 1.25;
    }

    /* Document Bottom Footer (문서하단 회사로고 + 회사명: 오른쪽 정렬) */
    .doc-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end; /* 오른쪽 정렬 */
      gap: 6px;
      height: 8mm;
      margin-top: 1mm;
      padding-right: 4px;
      flex-shrink: 0;
    }

    .doc-footer-logo {
      height: 6mm;
      width: auto;
      object-fit: contain;
    }

    .doc-footer-company {
      font-size: 11pt;
      font-weight: 800;
      letter-spacing: 2.5px;
      color: #1e293b;
    }

    .stamp-th {
      font-size: 8.5pt;
      font-weight: 700;
      text-align: center;
      background: #fafafa;
      height: 28px;
    }
    .stamp-title-col {
      width: 25px;
      writing-mode: vertical-rl;
      letter-spacing: 4px;
      font-size: 8.5pt;
      font-weight: 700;
      background: #fafafa;
      text-align: center;
    }
    .stamp-box {
      height: 42px;
    }

    /* Subheaders and Gray Fill */
    .bg-label {
      background-color: #ffffff;
      font-weight: 600;
      text-align: center;
    }

    /* Checkbox list */
    .cb-cell {
      font-size: 8.5pt;
      padding-left: 6px;
    }
    .cb-mark {
      font-family: monospace;
      font-weight: 900;
      font-size: 9.5pt;
      margin-right: 3px;
    }

    /* Content Area 2-Columns (안쪽 가로 그리드 없는 일체형 박스) */
    .content-subheading {
      font-weight: 700;
      text-align: center;
      background: #fafafa;
      height: 20px;
    }
    table.csm-sheet td.content-box,
    .content-box {
      vertical-align: top !important;
      background: #ffffff;
      padding: 9px 8px 6px 10px; /* 상단 그리드와 붙지 않도록 상단 여백 9px */
      font-size: 8.0pt;
      line-height: 1.35;
      color: #000;
    }
    .content-item {
      display: flex;
      align-items: flex-start;
      margin-bottom: 3px;
      line-height: 1.35;
      font-size: 8.0pt;
    }
    .content-item:last-child {
      margin-bottom: 0;
    }
    /* 개요번호 계층별 들여쓰기 (2 -> 2.1 증가 시 들여쓰기) */
    .content-item.content-main {
      margin-left: 2px;
      font-weight: 700;
    }
    .content-item.content-sub {
      margin-left: 10px; /* 2 -> 2.1 또는 1) 등 2수준 들여쓰기 */
    }
    .content-item.content-sub-2 {
      margin-left: 18px; /* 2.1 -> 2.1.1 또는 a. 등 3수준 들여쓰기 */
    }
    .content-item.content-bullet {
      margin-left: 10px;
    }
    .content-item.content-plain {
      margin-left: 6px;
    }
    .content-item.content-hdr {
      font-weight: 700;
      color: #0f172a;
      margin-top: 2px;
      margin-bottom: 3px;
    }
    .content-item.content-note {
      color: #334155;
      margin-top: 3px;
    }
    /* 개요번호와 글머리아래 시작 (내어쓰기 정렬) */
    .content-item .item-num {
      flex-shrink: 0;
      font-weight: 700;
      margin-right: 3px; /* 개요번호와 글머리 사이 간격도 절반으로 밀착 */
      display: inline-block;
      text-align: left;
    }
    .content-item .item-text {
      flex: 1 1 auto;
      word-break: keep-all;
      overflow-wrap: break-word;
    }
    .content-blank-line {
      height: 6px;
    }

    /* Attendees Grid */
    .att-header {
      font-size: 8pt;
      font-weight: 700;
      background: #fafafa;
      text-align: center;
      height: 18px;
    }
    .att-name {
      font-size: 8.5pt;
      font-weight: 600;
      text-align: center;
      height: 22px;
    }
    .att-sign {
      /* Hand-signed: Strictly blank */
      background: #fff;
    }

    /* Page 2: Photo Sheet Styles */
    .photo-table {
      width: 100%;
      height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm);
      max-height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm);
      border-collapse: collapse;
      table-layout: fixed;
      border: 2px solid #2d3748;
      font-size: 9pt;
    }
    .photo-table td, .photo-table th {
      border: 1px solid #cbd5e1;
      padding: 3px 6px;
      vertical-align: middle;
    }
    .photo-title {
      font-size: 16pt;
      font-weight: 800;
      text-align: center;
      padding: 10px 0;
      border-bottom: 2px solid #2d3748;
      letter-spacing: 1px;
    }
    .meta-th {
      background: #edf2f7;
      font-weight: 700;
      text-align: center;
      width: 15%;
      border: 1px solid #a0aec0;
    }
    .meta-td {
      font-weight: 500;
      border: 1px solid #a0aec0;
    }
    .photo-bar {
      background: #f1f5f9;
      font-weight: 700;
      font-size: 9pt;
      padding: 3px 10px;
      border: 1px solid #a0aec0;
    }
    .photo-slot {
      height: 85mm;
      text-align: center;
      vertical-align: middle;
      background: #fff;
      padding: 6px;
      border: 1px solid #a0aec0;
    }
    .photo-slot img {
      max-width: 100%;
      max-height: 81mm;
      object-fit: contain;
    }
    .photo-empty {
      color: #94a3b8;
      font-size: 10pt;
      font-weight: 500;
    }
    .photo-footer {
      font-size: 7.5pt;
      color: #64748b;
      text-align: center;
      padding: 4px 0;
      border: none !important;
    }

    @page {
      size: A4 portrait;
      margin: 0;
    }

    /* Print Media Rules */
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
        padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad) !important; /* 브라우저 여백 없음 인쇄 시 항상 패딩으로 여백 유지 */
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
      table.csm-sheet {
        width: 100% !important;
        height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm) !important;
        max-height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm) !important;
      }
      .photo-table {
        width: 100% !important;
        height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm) !important;
        max-height: calc(297mm - var(--top-pad) - var(--bottom-pad) - 9mm) !important;
      }
      .doc-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        height: 8mm !important;
        margin-top: 1mm !important;
        padding-right: 4px !important;
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
      <a href="view.php?id=<?= $t['id'] ?>" class="toolbar-btn btn-close">
        ← 일지 상세로 돌아가기
      </a>
      <a href="export.php?id=<?= $t['id'] ?>&format=pdf" class="toolbar-btn btn-close">
        📄 PDF 다운로드
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

  <!-- Paper Container -->
  <div class="paper-container">

    <!-- PAGE 1: 교육일지 (회사 서식 1:1) -->
    <div class="a4-page">
      <table class="csm-sheet">
        <!-- Colgroup for 22 units -->
        <colgroup>
          <col style="width: 4.5%;"> <!-- C1 (A) -->
          <col style="width: 4.5%;"> <!-- C2 (B) -->
          <col style="width: 4.5%;"> <!-- C3 (C) -->
          <col style="width: 4.5%;"> <!-- C4 (D) -->
          <col style="width: 4.5%;"> <!-- C5 (E) -->
          <col style="width: 4.5%;"> <!-- C6 (F) -->
          <col style="width: 4.5%;"> <!-- C7 (G) -->
          <col style="width: 4.5%;"> <!-- C8 (H) -->
          <col style="width: 4.5%;"> <!-- C9 (I) -->
          <col style="width: 4.5%;"> <!-- C10 (J) -->
          <col style="width: 4.5%;"> <!-- C11 (K) -->
          <col style="width: 4.5%;"> <!-- C12 (L) -->
          <col style="width: 4.5%;"> <!-- C13 (M) -->
          <col style="width: 4.5%;"> <!-- C14 (N) -->
          <col style="width: 4.5%;"> <!-- C15 (O) -->
          <col style="width: 4.5%;"> <!-- C16 (P) -->
          <col style="width: 4.5%;"> <!-- C17 (Q) -->
          <col style="width: 4.5%;"> <!-- C18 (R) -->
          <col style="width: 4.5%;"> <!-- C19 (S) -->
          <col style="width: 4.5%;"> <!-- C20 (T) -->
          <col style="width: 5.0%;"> <!-- C21 (U) -->
          <col style="width: 5.5%;"> <!-- C22 (V) -->
        </colgroup>

        <tbody>
          <!-- Row 1-3: Header & 결재란 -->
          <tr>
            <td colspan="17" rowspan="3" class="title-cell">
              <div class="title-text">안전보건 교육일지</div>
              <?php if (!empty($t['is_demo'])): ?>
                <div style="font-size:8.5pt; font-weight:700; color:#dc2626; letter-spacing:0; margin-top:2px;">[ 시연용 가명 데이터 - 제출 불가 ]</div>
              <?php endif; ?>
            </td>
            <td rowspan="3" class="stamp-title-col">결재</td>
            <td colspan="2" class="stamp-th">작 성</td>
            <td colspan="2" class="stamp-th">승 인</td>
          </tr>
          <tr>
            <td colspan="2" rowspan="2" class="stamp-box"></td>
            <td colspan="2" rowspan="2" class="stamp-box"></td>
          </tr>
          <tr></tr>

          <!-- Row 4: 교육일시 -->
          <tr>
            <td colspan="22" class="bold text-left" style="padding-left:10px; height:24px; font-size:9pt; background:#fafafa;">
              <?= htmlspecialchars($dateLine) ?>
            </td>
          </tr>

          <!-- Rows 5-8: 교육구분 -->
          <tr>
            <td rowspan="4" class="bold text-center" style="writing-mode: vertical-rl; letter-spacing: 4px; background:#fff;">교육구분</td>
            <td colspan="5" class="cb-cell">1. 신규채용시 교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkNewHire ? '■' : '□' ?></span></td>
            <td colspan="8" class="cb-cell">2. 정기안전 보건교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkRegular ? '■' : '□' ?></span></td>
          </tr>
          <tr>
            <td colspan="5" class="cb-cell">3. 작업내용변경시 교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkJobChange ? '■' : '□' ?></span></td>
            <td colspan="8" class="cb-cell">4. 관리감독자교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkSupervisor ? '■' : '□' ?></span></td>
          </tr>
          <tr>
            <td colspan="5" class="cb-cell">5. 특별안전교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkSpecial ? '■' : '□' ?></span></td>
            <td colspan="8" class="cb-cell">6. 외부강사초빙 교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkExternal ? '■' : '□' ?></span></td>
          </tr>
          <tr>
            <td colspan="5" class="cb-cell">7. 기타</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkOther ? '■' : '□' ?></span></td>
            <td colspan="8" class="cb-cell">8. MSDS 교육</td>
            <td colspan="4" class="text-center font-bold"><span class="cb-mark"><?= $chkMsds ? '■' : '□' ?></span></td>
          </tr>

          <!-- Rows 9-12: 인원수 -->
          <tr>
            <td rowspan="4" class="bold text-center" style="font-size:7.5pt; line-height:1.25; padding:2px 0;">교육실시<br>인원</td>
            <td colspan="3" class="bold text-center">인원구분</td>
            <td colspan="3" class="bold text-center">계</td>
            <td colspan="3" class="bold text-center">남</td>
            <td colspan="3" class="bold text-center">여</td>
            <td colspan="9" class="bold text-center">교육을 제외한 이유</td>
          </tr>
          <tr>
            <td colspan="3" class="text-center" style="font-size:8pt;">전 근 로 자 수</td>
            <td colspan="3" class="text-center font-bold"><?= $totalCompleted ?></td>
            <td colspan="3" class="text-center font-bold"><?= $totalCompleted ?></td>
            <td colspan="3" class="text-center">0</td>
            <td colspan="9" rowspan="3" class="text-left" style="font-size:7.5pt; color:#4b5563; padding-left:8px;">
              <?= ($totalTarget > $totalCompleted) ? '※ 현장 필수 교대근무 및 휴가자 제외' : '' ?>
            </td>
          </tr>
          <tr>
            <td colspan="3" class="text-center" style="font-size:8pt;">대 상 인 원 수</td>
            <td colspan="3" class="text-center font-bold"><?= $totalTarget ?></td>
            <td colspan="3" class="text-center font-bold"><?= $totalTarget ?></td>
            <td colspan="3" class="text-center">0</td>
          </tr>
          <tr>
            <td colspan="3" class="text-center" style="font-size:8pt;">교육실시인원수</td>
            <td colspan="3" class="text-center font-bold"><?= $totalCompleted ?></td>
            <td colspan="3" class="text-center font-bold"><?= $totalCompleted ?></td>
            <td colspan="3" class="text-center">0</td>
          </tr>

          <!-- Row 13-14: 교육과목, 방법, 시간, 교재, 장소 -->
          <tr>
            <td rowspan="2" class="bold text-center" style="writing-mode: vertical-rl; letter-spacing: 4px;">교육내용</td>
            <td colspan="4" class="bold text-center">교&nbsp;&nbsp;육&nbsp;&nbsp;과&nbsp;&nbsp;목</td>
            <td colspan="3" class="bold text-center">교 육 방 법</td>
            <td colspan="3" class="bold text-center">교 육 시 간</td>
            <td colspan="3" class="bold text-center">교 재 준 비</td>
            <td colspan="8" class="bold text-center">교 육 장 소</td>
          </tr>
          <tr style="height:26px;">
            <td colspan="4" class="text-center font-bold" style="font-size:<?= mb_strlen($t['subject'] ?? '') > 24 ? '7.5pt' : '8.5pt' ?>;">
              <?= htmlspecialchars($t['subject']) ?>
            </td>
            <td colspan="3" class="text-center" style="font-size:<?= mb_strlen($t['method'] ?? '') > 14 ? '7.5pt' : '8.5pt' ?>;">
              <?= htmlspecialchars($t['method'] ?: '시청각 및 회람') ?>
            </td>
            <td colspan="3" class="text-center font-bold"><?= $t['planned_hours'] ?>HR</td>
            <td colspan="3" class="text-center">교재 및 PPT</td>
            <td colspan="8" class="text-center"><?= htmlspecialchars($t['location'] ?: '현장 사무실') ?></td>
          </tr>

          <!-- Rows 15-24: 2-Column 본문 내용 (안쪽 그리드 제거 일체형) -->
          <tr>
            <td colspan="10" class="content-subheading" style="height:20px;">◈ 교육내용 (1) ◈</td>
            <td colspan="12" class="content-subheading" style="height:20px;">◈ 교육내용 (2) ◈</td>
          </tr>
          <tr>
            <td colspan="10" class="content-box text-left" style="vertical-align: top !important; padding-top: 9px; height: 168px;">
              <?php foreach ($leftLines as $l): ?>
                <?= format_csm_content_line($l) ?>
              <?php endforeach; ?>
            </td>
            <td colspan="12" class="content-box text-left" style="vertical-align: top !important; padding-top: 9px; height: 168px;">
              <?php foreach ($rightLines as $l): ?>
                <?= format_csm_content_line($l) ?>
              <?php endforeach; ?>
            </td>
          </tr>

          <!-- Row 25-26: 교육평가 및 의견 -->
          <tr>
            <td colspan="22" class="content-subheading" style="height:20px;">◈ 교육평가 및 의견 ◈</td>
          </tr>
          <tr style="height:24px;">
            <td colspan="22" class="text-left font-normal" style="padding: 2px 10px; font-size: 8.5pt;">
              <?= htmlspecialchars($cleanOpinion ?: '본 교육을 성실히 이수하였으며 작업 전 안전수칙 및 위험요인 예방대책을 숙지함.') ?>
            </td>
          </tr>

          <!-- Rows 27-28: 강사 -->
          <tr>
            <td rowspan="2" class="bold text-center" style="width:4.5%;">강사</td>
            <td colspan="5" class="bold text-center">성&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="5" class="bold text-center">직&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;위</td>
            <td colspan="11" class="bold text-center">비&nbsp;&nbsp;&nbsp;&nbsp;고</td>
          </tr>
          <tr style="height:22px;">
            <td colspan="5" class="text-center font-bold"><?= htmlspecialchars($t['instructor_name']) ?></td>
            <td colspan="5" class="text-center"><?= htmlspecialchars($t['instructor_position'] ?: '과장') ?></td>
            <td colspan="11" class="text-center font-normal" style="font-size:7.5pt;">
              <?= !empty($t['scanned_doc_filename']) ? '공식 증빙 첨부' : '' ?>
            </td>
          </tr>

          <!-- Rows 29-35: 참석자 4열 x 6행 (서명란 엄격 공란) -->
          <tr>
            <td rowspan="7" class="bold text-center" style="width:4.5%; letter-spacing:2px;">참석자</td>
            <td colspan="2" class="att-header">성&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="3" class="att-header">서&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="2" class="att-header">성&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="3" class="att-header">서&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="2" class="att-header">성&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="3" class="att-header">서&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="3" class="att-header">성&nbsp;&nbsp;&nbsp;명</td>
            <td colspan="3" class="att-header">서&nbsp;&nbsp;&nbsp;명</td>
          </tr>
          <?php for ($row = 0; $row < 6; $row++): ?>
            <tr style="height:22px;">
              <!-- Col 1 -->
              <td colspan="2" class="att-name"><?= htmlspecialchars($grid[$row][0]) ?></td>
              <td colspan="3" class="att-sign"></td>
              <!-- Col 2 -->
              <td colspan="2" class="att-name"><?= htmlspecialchars($grid[$row][1]) ?></td>
              <td colspan="3" class="att-sign"></td>
              <!-- Col 3 -->
              <td colspan="2" class="att-name"><?= htmlspecialchars($grid[$row][2]) ?></td>
              <td colspan="3" class="att-sign"></td>
              <!-- Col 4 -->
              <td colspan="3" class="att-name"><?= htmlspecialchars($grid[$row][3]) ?></td>
              <td colspan="3" class="att-sign"></td>
            </tr>
          <?php endfor; ?>

        </tbody>
      </table>
      <!-- 문서하단: 회사로고 + 회사명 -->
      <footer class="doc-footer">
        <?php if (!empty($logoBase64)): ?>
          <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
        <?php endif; ?>
        <strong class="doc-footer-company">주식회사 현대기전</strong>
      </footer>
    </div>

    <!-- PAGE 2: 사진대지 (양면 2페이지 인쇄 규격) -->
    <div class="a4-page">
      <table class="photo-table">
        <tbody>
          <!-- Title -->
          <tr>
            <td colspan="12" class="photo-title">
              안전보건교육 실시 사진대지
              <?php if (!empty($t['is_demo'])): ?>
                <div style="font-size:9pt; font-weight:700; color:#dc2626; letter-spacing:0; margin-top:3px;">[ 시연용 가명 데이터 - 제출 불가 ]</div>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Metadata Rows (Row 4-6) -->
          <tr>
            <th colspan="2" class="meta-th">교육과목</th>
            <td colspan="5" class="meta-td font-bold"><?= htmlspecialchars($t['subject']) ?></td>
            <th colspan="2" class="meta-th">교육구분</th>
            <td colspan="3" class="meta-td text-center font-bold"><?= htmlspecialchars($t['training_type']) ?></td>
          </tr>
          <tr>
            <th colspan="2" class="meta-th">교육일시</th>
            <td colspan="5" class="meta-td"><?= htmlspecialchars($dateLine) ?></td>
            <th colspan="2" class="meta-th">교육회차</th>
            <td colspan="3" class="meta-td text-center font-bold"><?= $t['session_round'] ?>회차 (총 <?= $t['total_rounds'] ?>회)</td>
          </tr>
          <tr>
            <th colspan="2" class="meta-th">교육강사</th>
            <td colspan="5" class="meta-td"><?= htmlspecialchars($t['instructor_name']) ?> (<?= htmlspecialchars($t['instructor_position'] ?: '담당') ?>)</td>
            <th colspan="2" class="meta-th">교육장소</th>
            <td colspan="3" class="meta-td text-center"><?= htmlspecialchars($t['location'] ?: '현장 사무실') ?></td>
          </tr>

          <!-- Photo 1 -->
          <tr>
            <td colspan="12" class="photo-bar">
              [사진 1] <?= isset($photos[0]) ? htmlspecialchars($photos[0]['caption'] ?: '교육 실시 현장 사진 #1') : '(사진 미등록)' ?>
            </td>
          </tr>
          <tr>
            <td colspan="12" class="photo-slot">
              <?php if (isset($photos[0])): ?>
                <?php
                  $pPath = CSM_UPLOAD_PHOTOS_DIR . '/' . $photos[0]['stored_name'];
                  if (file_exists($pPath)):
                    $imgData = base64_encode(file_get_contents($pPath));
                    $imgSrc = "data:{$photos[0]['mime_type']};base64,{$imgData}";
                ?>
                  <img src="<?= $imgSrc ?>" alt="교육 사진 1">
                <?php else: ?>
                  <span class="photo-empty">[사진 파일 없음]</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="photo-empty">[사진 미등록 - 필요시 현장 교육사진을 부착하십시오]</span>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Photo 2 -->
          <tr>
            <td colspan="12" class="photo-bar">
              [사진 2] <?= isset($photos[1]) ? htmlspecialchars($photos[1]['caption'] ?: '교육 실시 현장 사진 #2') : '(사진 미등록)' ?>
            </td>
          </tr>
          <tr>
            <td colspan="12" class="photo-slot">
              <?php if (isset($photos[1])): ?>
                <?php
                  $pPath = CSM_UPLOAD_PHOTOS_DIR . '/' . $photos[1]['stored_name'];
                  if (file_exists($pPath)):
                    $imgData = base64_encode(file_get_contents($pPath));
                    $imgSrc = "data:{$photos[1]['mime_type']};base64,{$imgData}";
                ?>
                  <img src="<?= $imgSrc ?>" alt="교육 사진 2">
                <?php else: ?>
                  <span class="photo-empty">[사진 파일 없음]</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="photo-empty">[사진 미등록 - 필요시 현장 교육사진을 부착하십시오]</span>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Footer Note -->
          <tr>
            <td colspan="12" class="photo-footer">
              ※ 본 사진대지는 안전보건교육 실시 증빙자료로 교육일지 뒷면에 양면 인쇄(긴 쪽 넘김)됩니다.
            </td>
          </tr>
        </tbody>
      </table>
      <!-- 문서하단: 회사로고 + 회사명 -->
      <footer class="doc-footer">
        <?php if (!empty($logoBase64)): ?>
          <img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo">
        <?php endif; ?>
        <strong class="doc-footer-company">주식회사 현대기전</strong>
      </footer>
    </div>

  </div>

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

    // Initialize padding from saved preference or default 28mm
    try {
      const savedPad = localStorage.getItem('csm_print_top_padding') || '28';
      changePadding(parseInt(savedPad, 10));
    } catch (e) {
      changePadding(28);
    }

    // Automatic print trigger if requested
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
