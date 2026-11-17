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

$categoriesList = [
    '작업장 안전', '건설 안전', '시설물 안전', '작업환경',
    '건강관리(보건)', '감정/심리', '제도(규정, 규칙)', '기타'
];
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

    /* 1.3.2 안전제안 서식 스타일 */
    .sug-header-box {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-bottom: 4mm;
      border-bottom: 2px solid #000;
      padding-bottom: 3mm;
    }
    .sug-title {
      font-size: 20pt;
      font-weight: 900;
      letter-spacing: 4px;
      color: #000;
    }
    .sug-slogan {
      font-size: 11pt;
      font-weight: 700;
      color: #1e3a8a;
      letter-spacing: 1px;
    }

    .sug-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.5pt;
      table-layout: fixed;
      margin-bottom: 3mm;
    }
    .sug-table th, .sug-table td {
      border: 1.2px solid #000;
      padding: 5px 8px;
      vertical-align: middle;
    }
    .sug-table th {
      background: #f1f5f9;
      text-align: center;
      font-weight: 700;
      color: #000;
    }
    .header-col {
      width: 15%;
      font-weight: 700;
      background: #f1f5f9;
      text-align: center;
    }

    /* Photo Slot */
    .photo-area {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 4mm;
    }
    .photo-card {
      border: 1.2px solid #000;
      display: flex;
      flex-direction: column;
      height: 52mm;
    }
    .photo-card-head {
      background: #f1f5f9;
      border-bottom: 1.2px solid #000;
      text-align: center;
      padding: 4px;
      font-weight: 700;
      font-size: 9.5pt;
    }
    .photo-card-body {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 6px;
      text-align: center;
      color: #475569;
      font-size: 9pt;
      line-height: 1.4;
      background: #fafafa;
    }
    .photo-card-body img {
      max-width: 100%;
      max-height: 42mm;
      object-fit: contain;
    }

    /* Score Box */
    .score-summary-bar {
      border: 1.5px solid #000;
      padding: 6px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 10pt;
      font-weight: 700;
      background: #f8fafc;
      margin-bottom: 4mm;
    }

    /* Footer */
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
      <a href="worker_participation.php?tab=suggestions&amp;year=<?= $year ?>" class="toolbar-btn btn-close">
        ← 안전제안 목록으로 돌아가기
      </a>
      <div class="pad-control-group">
        <span class="pad-label">상단 여백:</span>
        <button type="button" class="pad-btn" onclick="setTopPad('14mm', this)">14mm (최소)</button>
        <button type="button" class="pad-btn active" onclick="setTopPad('20mm', this)">20mm (표준)</button>
        <button type="button" class="pad-btn" onclick="setTopPad('28mm', this)">28mm (타공)</button>
      </div>
    </div>
    <div class="toolbar-tip">
      💡 <strong>1.3.2 안전제안 관리대장.xls</strong> 회사 표준 서식 1:1 반영 &bull; 브라우저 인쇄 설정에서 <strong>[배경 그래픽]</strong>을 켜주세요.
    </div>
  </aside>

  <!-- Paper Container -->
  <div class="paper-container">
    <?php foreach ($suggestions as $s): ?>
    <div class="a4-page">
      <div class="page-content">

        <!-- Top Header -->
        <div class="sug-header-box">
          <div class="sug-title">산업안전보건 제안 접수</div>
          <div class="sug-slogan">"안전은 행동입니다"</div>
        </div>

        <!-- Proposal Title & Reg No -->
        <table class="sug-table">
          <colgroup>
            <col style="width: 16%;">
            <col style="width: 54%;">
            <col style="width: 14%;">
            <col style="width: 16%;">
          </colgroup>
          <tr>
            <th class="header-col">제  안  명</th>
            <td style="font-weight:700;font-size:10.5pt;color:#1e3a8a;">
              <?= $escape($s['title']) ?>
            </td>
            <th class="header-col">접수번호</th>
            <td style="text-align:center;font-weight:700;">
              <?= $escape($s['reg_no']) ?>
            </td>
          </tr>
        </table>

        <!-- Proposal Categories (8-Checkboxes) -->
        <table class="sug-table">
          <colgroup>
            <col style="width: 16%;">
            <col style="width: 21%;">
            <col style="width: 21%;">
            <col style="width: 21%;">
            <col style="width: 21%;">
          </colgroup>
          <tr>
            <th rowspan="2" class="header-col">제안유형</th>
            <td><?= $s['category'] === '작업장 안전' ? '■' : '□' ?> 작업장 안전</td>
            <td><?= $s['category'] === '건설 안전' ? '■' : '□' ?> 건설 안전</td>
            <td><?= $s['category'] === '시설물 안전' ? '■' : '□' ?> 시설물 안전</td>
            <td><?= $s['category'] === '작업환경' ? '■' : '□' ?> 작업환경</td>
          </tr>
          <tr>
            <td><?= $s['category'] === '건강관리(보건)' ? '■' : '□' ?> 건강관리(보건)</td>
            <td><?= $s['category'] === '감정/심리' ? '■' : '□' ?> 감정/심리</td>
            <td><?= $s['category'] === '제도(규정, 규칙)' ? '■' : '□' ?> 제도(규정, 규칙)</td>
            <td><?= !in_array($s['category'], ['작업장 안전','건설 안전','시설물 안전','작업환경','건강관리(보건)','감정/심리','제도(규정, 규칙)'], true) ? '■' : '□' ?> 기타</td>
          </tr>
        </table>

        <!-- Proposal Details -->
        <table class="sug-table">
          <colgroup>
            <col style="width: 8%;">
            <col style="width: 14%;">
            <col style="width: 78%;">
          </colgroup>
          <tr>
            <th rowspan="4" class="header-col" style="letter-spacing:4px;line-height:1.4;">제<br>안<br>내<br>용</th>
            <th style="background:#f8fafc;">목    적</th>
            <td style="font-weight:600;"><?= $escape($s['expected_effect'] ?: '작업자 안전 확보 및 유해·위험요인 사전 차단') ?></td>
          </tr>
          <tr>
            <th style="background:#f8fafc;">현황 및<br>문제점</th>
            <td style="min-height:22mm;line-height:1.5;white-space:pre-wrap;"><?= $escape($s['problem']) ?></td>
          </tr>
          <tr>
            <th style="background:#f8fafc;">개선방안</th>
            <td style="min-height:22mm;line-height:1.5;white-space:pre-wrap;font-weight:600;"><?= $escape($s['solution']) ?></td>
          </tr>
          <tr>
            <th style="background:#f8fafc;">기대효과</th>
            <td style="line-height:1.4;"><?= $escape($s['expected_effect'] ?: '근로자 재해 예방 및 안전작업 환경 조성') ?></td>
          </tr>
          <tr>
            <th colspan="2" class="header-col">기    타</th>
            <td>대상구역: <strong><?= $escape($s['location'] ?: '현장 전 구역') ?></strong></td>
          </tr>
        </table>

        <!-- Proposer & Action Status Info -->
        <table class="sug-table">
          <colgroup>
            <col style="width: 14%;">
            <col style="width: 12%;">
            <col style="width: 16%;">
            <col style="width: 12%;">
            <col style="width: 14%;">
            <col style="width: 12%;">
            <col style="width: 20%;">
          </colgroup>
          <tr>
            <th rowspan="2" class="header-col">제안자<br>정  보</th>
            <th style="background:#f8fafc;">성  명</th>
            <td style="font-weight:700;text-align:center;">
              <?= $escape($s['proposer_name']) ?>
              <?php if (!empty($s['proposer_sign'])): ?>
                <img src="<?= $s['proposer_sign'] ?>" alt="서명" style="max-height:24px;vertical-align:middle;margin-left:4px;">
              <?php endif; ?>
            </td>
            <th style="background:#f8fafc;">접수일</th>
            <td style="text-align:center;"><?= $escape($s['reg_date']) ?></td>
            <th style="background:#f8fafc;">채택여부</th>
            <td style="text-align:center;font-weight:700;color:#1e3a8a;">채택</td>
          </tr>
          <tr>
            <th style="background:#f8fafc;">소  속</th>
            <td style="text-align:center;"><?= $escape($s['proposer_dept'] ?: '현대기전') ?></td>
            <th style="background:#f8fafc;">개선여부</th>
            <td style="text-align:center;font-weight:700;color:<?= $s['status'] === '완료' ? '#059669' : '#b45309' ?>;">
              <?= $s['status'] === '완료' ? '완료' : '진행중' ?>
            </td>
            <th style="background:#f8fafc;">완료일자</th>
            <td style="text-align:center;"><?= $escape($s['action_date'] ?: ($s['feedback_date'] ?: '2026-02-28')) ?></td>
          </tr>
        </table>

        <!-- Photo Section -->
        <div style="font-weight:700;font-size:10pt;margin:2mm 0 1.5mm 0;">■ 개선 사진 대지</div>
        <div class="photo-area">
          <div class="photo-card">
            <div class="photo-card-head">&lt; 개  선  전 &gt;</div>
            <div class="photo-card-body">
              <?php 
                $pBefore = $s['photo_before'] ?? '';
                $hasBefore = ($pBefore !== '' && (str_starts_with($pBefore, 'data:') || file_exists(__DIR__ . '/' . $pBefore)));
              ?>
              <?php if ($hasBefore): ?>
                <img src="<?= $escape($pBefore) ?>" alt="개선 전">
              <?php else: ?>
                <div>
                  <div style="font-weight:700;color:#334155;margin-bottom:4px;">[ 현황 사진 대지 ]</div>
                  <div style="font-size:8.5pt;color:#64748b;"><?= $escape($s['problem']) ?></div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="photo-card">
            <div class="photo-card-head">&lt; 개  선  후 &gt;</div>
            <div class="photo-card-body">
              <?php 
                $pAfter = $s['photo_after'] ?? '';
                $hasAfter = ($pAfter !== '' && (str_starts_with($pAfter, 'data:') || file_exists(__DIR__ . '/' . $pAfter)));
              ?>
              <?php if ($hasAfter): ?>
                <img src="<?= $escape($pAfter) ?>" alt="개선 후">
              <?php else: ?>
                <div>
                  <div style="font-weight:700;color:#1e3a8a;margin-bottom:4px;">[ 조치 완료 사진 대지 ]</div>
                  <div style="font-size:8.5pt;color:#334155;"><?= $escape($s['action_result'] ?: $s['solution']) ?></div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Evaluation & Sign-off Bar -->
        <div class="score-summary-bar">
          <div>
            제안 유형에 따른 안전기여도 평가 :
            <span style="font-size:12pt;color:#1e3a8a;margin-left:4px;"><?= (int)($s['reward_points'] ?: 30) ?> 점</span>
            <?php if ($s['reward_note']): ?>
              <span style="font-size:8.5pt;color:#64748b;margin-left:6px;">(<?= $escape($s['reward_note']) ?>)</span>
            <?php endif; ?>
          </div>
          <div>
            평가자 : <span style="font-weight:700;">안전보건관리책임자 정연탁</span>
            <?php if (!empty($s['evaluator_sign'])): ?>
              <img src="<?= $s['evaluator_sign'] ?>" alt="서명" style="max-height:30px;vertical-align:middle;margin-left:6px;">
            <?php else: ?>
              &nbsp; (서 명)
            <?php endif; ?>
          </div>
        </div>

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
