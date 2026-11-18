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
    $stmt = $db->prepare('SELECT * FROM csm_suggestions WHERE id = ?');
    $stmt->execute([$id]);
    $suggestions = $stmt->fetchAll();
} else {
    $stmt = $db->prepare('SELECT * FROM csm_suggestions WHERE year = ? ORDER BY reg_date ASC, id ASC');
    $stmt->execute([$year]);
    $suggestions = $stmt->fetchAll();
}

if (empty($suggestions)) {
    http_response_code(404);
    exit('인쇄할 안전제안서가 없습니다.');
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

$formatDotDate = static function(?string $d): string {
    if (empty($d)) return '';
    $ts = strtotime($d);
    if ($ts === false) return (string)$d;
    return date('Y. m. d.', $ts);
};

$formatKoreanPhone = static function(?string $p): string {
    if (empty($p)) return '010 - **** - 6209';
    $clean = preg_replace('/[^\d]/', '', $p);
    if (strlen($clean) === 11) {
        return substr($clean, 0, 3) . ' - **** - ' . substr($clean, 7, 4);
    }
    return $p;
};

function renderCircularSeal(string $name): string {
    $clean = mb_substr(trim($name), 0, 4);
    $len = mb_strlen($clean);
    if ($len === 2) {
        $top = $clean;
        $bottom = '인 ';
    } elseif ($len === 3) {
        $top = mb_substr($clean, 0, 2);
        $bottom = mb_substr($clean, 2, 1) . '인';
    } else {
        $top = mb_substr($clean, 0, 2);
        $bottom = mb_substr($clean, 2, 2);
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 60" width="36" height="36" style="vertical-align:middle;margin-left:4px;">
        <circle cx="30" cy="30" r="28" fill="#fff" stroke="#b91c1c" stroke-width="2.5"/>
        <circle cx="30" cy="30" r="24.5" fill="none" stroke="#b91c1c" stroke-width="0.8" opacity="0.8"/>
        <text x="30" y="24" font-family="Batang, Gungsuh, \'Malgun Gothic\', serif" font-size="13" font-weight="900" fill="#b91c1c" text-anchor="middle" letter-spacing="2">' . htmlspecialchars($top, ENT_QUOTES, 'UTF-8') . '</text>
        <text x="30" y="43" font-family="Batang, Gungsuh, \'Malgun Gothic\', serif" font-size="13" font-weight="900" fill="#b91c1c" text-anchor="middle" letter-spacing="2">' . htmlspecialchars($bottom, ENT_QUOTES, 'UTF-8') . '</text>
    </svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>산업안전보건 제안 접수서 인쇄 · CSM</title>
  <style>
    :root {
      --top-pad: 20mm;
      --bottom-pad: 10mm;
      --left-pad: 12mm;
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
      margin: 15px auto 30px auto;
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
      justify-content: space-between;
    }

    /* Header */
    .sug-header-box {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-bottom: 2mm;
      padding-bottom: 1.5mm;
    }
    .sug-title {
      font-size: 22pt;
      font-weight: 900;
      letter-spacing: 5px;
      color: #000;
      margin: 0;
      line-height: 1.1;
    }
    .sug-slogan {
      font-size: 11.5pt;
      font-weight: 700;
      letter-spacing: 0.5px;
      white-space: nowrap;
    }

    /* Form Tables */
    .sug-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.8pt;
      table-layout: fixed;
      margin-bottom: 0;
    }
    .sug-table th, .sug-table td {
      border: 1.2px solid #000;
      padding: 6.5px 8px;
      vertical-align: middle;
      color: #000;
    }
    .sug-table th {
      background: #fff;
      text-align: center;
      font-weight: 700;
    }
    .header-col {
      font-weight: 700;
      background: #fff;
      text-align: center;
    }

    /* Section Title */
    .photo-section-title {
      text-align: center;
      font-weight: 900;
      font-size: 15.5pt;
      letter-spacing: 4px;
      margin: 3.5mm 0 2.5mm 0;
      color: #000;
    }

    /* Photo Table - Table guarantees identical height for both description cells and aligns 100% with form width */
    .photo-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      margin-bottom: 0;
    }
    .photo-box-cell {
      border: 1.2px solid #000;
      padding: 3.5px 4px;
      vertical-align: middle;
      background: #fff;
    }
    .photo-img-frame {
      height: 58.5mm;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      background: #fff;
    }
    .photo-img-frame img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .photo-img-placeholder {
      font-size: 9pt;
      color: #64748b;
      text-align: center;
      padding: 10px;
    }
    .photo-desc-cell {
      border: 1.2px solid #000;
      padding: 6px 10px 8px 10px;
      background: #fff;
      color: #000;
      vertical-align: top;
      box-sizing: border-box;
    }
    .photo-desc-title {
      text-align: center;
      font-weight: 700;
      font-size: 10.5pt;
      letter-spacing: 3px;
      margin-bottom: 3px;
      color: #000;
    }
    .photo-desc-text {
      font-size: 9.3pt;
      line-height: 1.5;
      color: #000;
    }

    /* Evaluation Box */
    .eval-box {
      border: 1.2px solid #000;
      margin-top: 3mm;
      padding: 5px 10px;
      background: #fff;
    }
    .eval-top-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 10pt;
      font-weight: 700;
      margin-bottom: 3px;
    }
    .eval-summary-title {
      font-size: 9.6pt;
      font-weight: 700;
      margin-bottom: 2px;
    }
    .eval-summary-content {
      font-size: 9.5pt;
      line-height: 1.5;
      padding-left: 2px;
    }

    /* Footer */
    .doc-footer {
      text-align: right;
      padding-top: 1.5mm;
      font-size: 10pt;
      font-weight: 900;
      letter-spacing: 1px;
      color: #000;
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
      <a href="worker_participation.php?tab=suggestions&amp;year=<?= $year ?>" class="toolbar-btn btn-close">
        ← 안전제안 목록으로 돌아가기
      </a>
      <div class="pad-control-group">
        <span class="pad-label">상단 편철 여백:</span>
        <button type="button" class="pad-btn" onclick="setTopPad('14mm', this)">14mm (기본)</button>
        <button type="button" class="pad-btn active" onclick="setTopPad('20mm', this)">20mm (표준 편철)</button>
        <button type="button" class="pad-btn" onclick="setTopPad('25mm', this)">25mm (여유 편철)</button>
      </div>
    </div>
    <div class="toolbar-tip">
      💡 <strong>1.3.2 안전제안 관리대장</strong> 회사 표준 양식 1:1 반영 &bull; 브라우저 인쇄 설정에서 <strong>[배경 그래픽]</strong>을 켜주세요.
    </div>
  </aside>

  <!-- Paper Container -->
  <div class="paper-container">
    <?php foreach ($suggestions as $s): ?>
    <div class="a4-page">
      <div class="page-content">

        <div>
          <!-- Top Header: Title & Slogan -->
          <div class="sug-header-box">
            <div class="sug-title">산업안전보건 제안 접수</div>
            <div class="sug-slogan">
              <span style="color:#000;">안전은</span> <strong style="color:#dc2626;">행동입니다.</strong>
            </div>
          </div>

          <!-- Table 1: 제안명 & 접수번호 -->
          <table class="sug-table" style="border-bottom:none;">
            <colgroup>
              <col style="width: 15%;">
              <col style="width: 55%;">
              <col style="width: 15%;">
              <col style="width: 15%;">
            </colgroup>
            <tr style="height: 11mm;">
              <th class="header-col" style="letter-spacing:4px;padding:7px 6px;">제 안 명</th>
              <td style="font-weight:700;font-size:11pt;padding:7px 12px;">
                <?= $escape($s['title']) ?>
              </td>
              <th class="header-col" style="letter-spacing:2px;padding:7px 6px;">접수번호</th>
              <td style="text-align:center;font-weight:700;font-size:11pt;padding:7px 6px;">
                <?= $escape($s['reg_no']) ?>
              </td>
            </tr>
          </table>

          <!-- Table 2: 제안유형 (8 Checkboxes) -->
          <?php
            $cat = $s['category'] ?? '';
            $isWorkplace = in_array($cat, ['작업장 안전', '통로·보행'], true);
            $isConst = in_array($cat, ['건설 안전', '설비·기계', '전기안전'], true);
            $isFacility = in_array($cat, ['시설물 안전', '화재·폭발', '시설물·통로'], true);
            $isEnv = in_array($cat, ['작업환경', '보호구·환경'], true);
            $isHealth = in_array($cat, ['건강관리(보건)', '보건·위생'], true);
            $isEmotion = ($cat === '감정/심리');
            $isRule = ($cat === '제도(규정, 규칙)');
            $isOther = (!$isWorkplace && !$isConst && !$isFacility && !$isEnv && !$isHealth && !$isEmotion && !$isRule);
          ?>
          <table class="sug-table" style="border-top:none;border-bottom:none;">
            <colgroup>
              <col style="width: 15%;">
              <col style="width: 21.25%;">
              <col style="width: 21.25%;">
              <col style="width: 21.25%;">
              <col style="width: 21.25%;">
            </colgroup>
            <tr style="height: 8.5mm;">
              <th rowspan="2" class="header-col" style="letter-spacing:2px;">제안유형</th>
              <td><?= $isWorkplace ? '■' : '□' ?> 작업장 안전</td>
              <td><?= $isConst ? '■' : '□' ?> 건설 안전</td>
              <td><?= $isFacility ? '■' : '□' ?> 시설물 안전</td>
              <td><?= $isEnv ? '■' : '□' ?> 작업환경</td>
            </tr>
            <tr style="height: 8.5mm;">
              <td><?= $isHealth ? '■' : '□' ?> 건강관리(보건)</td>
              <td><?= $isEmotion ? '■' : '□' ?> 감정/심리</td>
              <td><?= $isRule ? '■' : '□' ?> 제도(규정, 규칙)</td>
              <td><?= $isOther ? '■' : '□' ?> 기타 <?= ($isOther && $cat !== '' && !str_contains($cat, '미분류')) ? '(' . $escape($cat) . ')' : '' ?></td>
            </tr>
          </table>

          <!-- Table 3: 제안내용 (목적, 현황 및 문제점, 개선방안, 기대효과, 대상구역) -->
          <table class="sug-table" style="border-top:none;border-bottom:none;">
            <colgroup>
              <col style="width: 15%;">
              <col style="width: 13%;">
              <col style="width: 72%;">
            </colgroup>
            <tr style="height: 9mm;">
              <th rowspan="4" class="header-col" style="letter-spacing:4px;line-height:1.6;">제<br>안<br>내<br>용</th>
              <th style="letter-spacing:4px;">목    적</th>
              <td style="font-weight:600;padding-left:10px;"><?= $escape($s['purpose'] ?? ($s['expected_effect'] ?: '컨베이어 상부 작업용 안전 발판')) ?></td>
            </tr>
            <tr>
              <th style="line-height:1.35;">현황 및<br>문제점</th>
              <td style="line-height:1.55;min-height:20mm;padding:8px 10px;"><?= nl2br($escape($s['problem'])) ?></td>
            </tr>
            <tr>
              <th style="letter-spacing:2px;">개선방안</th>
              <td style="line-height:1.55;font-weight:600;min-height:20mm;padding:8px 10px;"><?= nl2br($escape($s['solution'])) ?></td>
            </tr>
            <tr style="height: 9mm;">
              <th style="letter-spacing:2px;">기대효과</th>
              <td style="line-height:1.5;padding-left:10px;"><?= nl2br($escape($s['expected_effect'])) ?></td>
            </tr>
            <tr style="height: 9.5mm;">
              <th colspan="2" class="header-col" style="letter-spacing:3px;">대 상 구 역</th>
              <td style="font-weight:600;padding-left:10px;"><?= $escape($s['location'] ?: '현장 전 구역') ?></td>
            </tr>
          </table>

          <!-- Table 4: 제안자정보 (Grid layout matching Excel 1:1) -->
          <?php
            $regDateStr = $formatDotDate($s['reg_date']);
            $doneDateStr = $formatDotDate($s['action_date'] ?: $s['reg_date']);
            $phoneStr = $formatKoreanPhone($s['proposer_phone'] ?? '');
            $isDone = ($s['status'] === '완료');
          ?>
          <table class="sug-table" style="border-top:none;">
            <colgroup>
              <col style="width: 15%;">
              <col style="width: 11%;">
              <col style="width: 22%;">
              <col style="width: 13%;">
              <col style="width: 13%;">
              <col style="width: 13%;">
              <col style="width: 13%;">
            </colgroup>
            <tr style="height: 9mm;">
              <th rowspan="2" class="header-col" style="letter-spacing:2px;line-height:1.5;">제안자정보</th>
              <th>성명</th>
              <td style="text-align:center;font-weight:700;letter-spacing:3px;font-size:10.5pt;">
                <?= $escape($s['proposer_name']) ?>
              </td>
              <th>접수일</th>
              <th>채택여부</th>
              <th>개선여부</th>
              <th>완료일자</th>
            </tr>
            <tr style="height: 9mm;">
              <th>연락처</th>
              <td style="text-align:center;font-size:9.5pt;letter-spacing:0.5px;">
                <?= $escape($phoneStr) ?>
              </td>
              <td style="text-align:center;font-size:9.5pt;">
                <?= $escape($regDateStr) ?>
              </td>
              <td style="text-align:center;font-weight:700;font-size:10pt;">
                <?= $s['status'] === '불채택' ? '불채택' : '채택' ?>
              </td>
              <td style="text-align:center;font-weight:700;font-size:10pt;">
                <?= $isDone ? '완료' : '진행중' ?>
              </td>
              <td style="text-align:center;font-size:9.5pt;">
                <?= $escape($isDone ? $doneDateStr : '진행중') ?>
              </td>
            </tr>
          </table>

          <!-- Section Header: 개선 사진 대지 -->
          <div class="photo-section-title">
            개선 사진 대지
          </div>

          <!-- Before & After Photo Table (HTML Table guarantees both cells have the exact same height based on the taller side) -->
          <?php
            $beforeDesc = trim($s['photo_before_desc'] ?? '');
            if ($beforeDesc === '') {
              $beforeDesc = "1. " . ($s['problem'] ?: '개선 전 문제 상황 확인');
            }
            $afterDesc = trim($s['photo_after_desc'] ?? '');
            if ($afterDesc === '') {
              $afterDesc = "1. " . ($s['action_result'] ?: ($s['solution'] ?: '개선 조치 완료'));
            }
            $pBefore = worker_part_resolve_photo_data($s['photo_before'] ?? '');
            $pAfter = worker_part_resolve_photo_data($s['photo_after'] ?? '');
          ?>
          <table class="photo-table">
            <colgroup>
              <col style="width: 50%;">
              <col style="width: 50%;">
            </colgroup>
            <tr>
              <td class="photo-box-cell">
                <div class="photo-img-frame">
                  <?php if (!empty($pBefore)): ?>
                    <img src="<?= $pBefore ?>" alt="개선 전">
                  <?php else: ?>
                    <div class="photo-img-placeholder">
                      <strong>[ 개선 전 사진 ]</strong><br>
                      <span style="font-size:8pt;color:#94a3b8;">관리 화면에서 사진을 등록하세요</span>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td class="photo-box-cell">
                <div class="photo-img-frame">
                  <?php if (!empty($pAfter)): ?>
                    <img src="<?= $pAfter ?>" alt="개선 후">
                  <?php else: ?>
                    <div class="photo-img-placeholder">
                      <strong>[ 개선 후 사진 ]</strong><br>
                      <span style="font-size:8pt;color:#94a3b8;">조치 완료 사진을 등록하세요</span>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <tr>
              <td class="photo-desc-cell">
                <div class="photo-desc-title">&lt; 개 선 전 &gt;</div>
                <div class="photo-desc-text"><?= nl2br($escape($beforeDesc)) ?></div>
              </td>
              <td class="photo-desc-cell">
                <div class="photo-desc-title">&lt; 개 선 후 &gt;</div>
                <div class="photo-desc-text"><?= nl2br($escape($afterDesc)) ?></div>
              </td>
            </tr>
          </table>

          <!-- Evaluation Box -->
          <?php
            $evalScore = (int)($s['reward_points'] ?: ($s['eval_score'] ?: 90));
            $rawReviewer = trim($s['reviewer_name'] ?? '');
            $evaluatorName = $rawReviewer !== '' ? $rawReviewer : '진종철';
            $spacedName = mb_strlen($evaluatorName) === 3 
              ? mb_substr($evaluatorName, 0, 1) . ' ' . mb_substr($evaluatorName, 1, 1) . ' ' . mb_substr($evaluatorName, 2, 1) 
              : $evaluatorName;
            $evaluatorSeal = renderCircularSeal($evaluatorName);
            $evalSummary = trim($s['review_opinion'] ?? ($s['eval_summary'] ?? ''));
            if ($evalSummary === '') {
              $evalSummary = '현장의 작업 여건의 한계를 극복하고 안전에 기여한 바를 높게 평가함';
            }
          ?>
          <div class="eval-box">
            <div class="eval-top-row">
              <div>
                제안 유형에 따른 안전기여도 평가 : &nbsp; <span style="font-size:11pt;font-weight:700;"><?= $evalScore ?> 점</span>
              </div>
              <div style="display:flex;align-items:center;">
                평가자: &nbsp;<span style="letter-spacing:1px;"><?= $escape($spacedName) ?></span>
                <img src="<?= $evaluatorSeal ?>" alt="직인" style="height:34px;width:34px;margin-left:4px;">
              </div>
            </div>
            <div class="eval-summary-title">평가요약:</div>
            <div class="eval-summary-content">
              <?= nl2br($escape($evalSummary)) ?>
            </div>
          </div>
        </div>

        <!-- Footer Outside Document Box -->
        <footer class="doc-footer">
          주식회사 현대기전
        </footer>

      </div>
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
