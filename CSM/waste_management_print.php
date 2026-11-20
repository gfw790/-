<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/waste_management_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$db = wm_db();
$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) {
    $year = $nowYear;
}
$mode = (string)($_GET['mode'] ?? 'process');
if (!in_array($mode, ['process', 'log', 'storage', 'sign'], true)) {
    $mode = 'process';
}

$isLandscape = in_array($mode, ['log', 'sign'], true);
$processDoc = wm_get_process_doc($db, $year);
$reportingInfo = wm_get_reporting_info($db, $year);
$wasteTypes = wm_list_waste_types($db, true);
$disposalLogs = wm_list_disposal_logs($db, $year);
$storageChecks = wm_list_storage_checks($db, $year);
$signThemes = wm_sign_themes();

$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
$checkId = isset($_GET['check_id']) ? (int)$_GET['check_id'] : 0;

if ($mode === 'sign' && $typeId > 0) {
    $wasteTypesForSign = array_values(array_filter($wasteTypes, static fn($r) => (int)$r['id'] === $typeId));
} else {
    $wasteTypesForSign = $wasteTypes;
}

if ($mode === 'storage' && $checkId > 0) {
    $checksToPrint = array_values(array_filter($storageChecks, static fn($r) => (int)$r['id'] === $checkId));
} else {
    $checksToPrint = $storageChecks;
}

$logoSrc = is_file(__DIR__ . '/assets/policy-logo.png')
    ? 'assets/policy-logo.png'
    : (is_file(dirname(__DIR__) . '/safety_manual/assets/policy-logo.png') ? '../safety_manual/assets/policy-logo.png' : '');

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$docTitleMap = [
    'process' => $year . '년 폐기물 배출현황 및 처리 프로세스 (4.1.1)',
    'log' => $year . '년 사업장폐기물처리 관리대장 (4.1.2)',
    'storage' => $year . '년 폐기물 보관·분리배출 정기점검표 (4.1.3)',
    'sign' => '현장 부착용 폐기물 보관표지판 (4.1.3)',
];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $e($docTitleMap[$mode]) ?> · 주식회사 현대기전</title>
<style>
  @page {
    size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>;
    margin: 0;
  }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  body {
    margin: 0;
    background: #e5e9ec;
    color: #111;
    font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
    font-size: 9.5pt;
    line-height: 1.45;
  }

  .toolbar {
    position: sticky; top: 0; z-index: 100;
    background: #103b35; color: #fff; padding: 10px 20px;
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  }
  .toolbar-left { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 13.5px; }
  .toolbar-right { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .tbtn {
    border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); color: #fff;
    padding: 6px 12px; border-radius: 7px; font-weight: 700; font-size: 12.5px; cursor: pointer; text-decoration: none;
  }
  .tbtn:hover { background: rgba(255,255,255,0.22); }
  .tbtn.active { background: #fff; color: #103b35; border-color: #fff; }
  .tbtn.print { background: #0f766e; border-color: #2dd4bf; }

  .sheet {
    width: <?= $isLandscape ? '297mm' : '210mm' ?>;
    min-height: <?= $isLandscape ? '210mm' : '297mm' ?>;
    margin: 14px auto;
    background: #fff;
    padding: 20mm 12mm 12mm 12mm;
    box-shadow: 0 6px 24px rgba(0,0,0,0.14);
    display: flex;
    flex-direction: column;
    page-break-after: always;
    break-after: page;
  }
  .sheet:last-child { page-break-after: auto; break-after: auto; }
  body.margin-left .sheet { padding: 14mm 12mm 12mm 20mm; }
  body.margin-wide .sheet { padding: 18mm 16mm 16mm 16mm; }

  .doc-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
    margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #111;
  }
  .doc-title-area small { display: block; font-size: 8.5pt; color: #475569; font-weight: 700; }
  .doc-title-area h1 { margin: 2px 0 4px; font-size: 17pt; font-weight: 900; letter-spacing: -0.4px; }
  .doc-meta-line { font-size: 8.8pt; color: #334155; }

  /* Strict CSM 2-column equal-width blank approval box */
  .approval-wrap { display: flex; flex-shrink: 0; border: 1pt solid #000; }
  .ap-side {
    width: 18px; display: flex; align-items: center; justify-content: center; text-align: center;
    font-weight: 800; font-size: 8.5pt; line-height: 1.4; border-right: 1pt solid #000; background: #f8fafc;
  }
  .ap-tbl { border-collapse: collapse; width: 128px; table-layout: fixed; }
  .ap-tbl th, .ap-tbl td { border: 1pt solid #000; text-align: center; padding: 2px 4px; width: 50%; }
  .ap-tbl tr:first-child th { border-top: none; background: #f8fafc; font-size: 8.5pt; font-weight: 800; height: 20px; }
  .ap-tbl tr:last-child td { border-bottom: none; height: 42px; }
  .ap-tbl th:first-child, .ap-tbl td:first-child { border-left: none; }
  .ap-tbl th:last-child, .ap-tbl td:last-child { border-right: none; }

  .sec-title { font-size: 10.5pt; font-weight: 900; margin: 12px 0 6px; padding-left: 7px; border-left: 3.5px solid #0f766e; }

  .flow-print { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-bottom: 10px; }
  .flow-box { border: 1pt solid #475569; border-top: 3pt solid #0f766e; padding: 7px 8px; border-radius: 4px; background: #f8fbfa; }
  .flow-box strong { display: block; font-size: 8.8pt; color: #0f766e; margin-bottom: 4px; }
  .flow-box p { margin: 0; font-size: 8pt; line-height: 1.4; color: #1e293b; }

  table.data-tbl { width: 100%; border-collapse: collapse; font-size: 8.4pt; }
  table.data-tbl th, table.data-tbl td { border: 0.75pt solid #334155; padding: 4px 5px; vertical-align: middle; }
  table.data-tbl th { background: #eef3f2; font-weight: 800; text-align: center; }
  .ctr { text-align: center; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }

  .info-strip {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 0;
    border: 0.75pt solid #334155; margin-bottom: 10px; font-size: 8.5pt;
  }
  .info-cell { padding: 5px 7px; border-right: 0.75pt solid #334155; border-bottom: 0.75pt solid #334155; }
  .info-cell:nth-child(4n) { border-right: none; }
  .info-cell b { display: block; font-size: 7.5pt; color: #475569; margin-bottom: 1px; }

  .photo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
  .photo-box { border: 0.75pt solid #334155; padding: 6px; text-align: center; }
  .photo-box .pcap { font-size: 8.5pt; font-weight: 800; margin-bottom: 5px; background: #f1f5f9; padding: 3px; }
  .photo-box img { max-width: 100%; max-height: 68mm; object-fit: contain; display: block; margin: 0 auto; }
  .photo-empty { height: 56mm; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 8.5pt; }

  .doc-footer {
    margin-top: auto;
    padding-top: 8px;
    border-top: 0.75pt solid #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }
  .doc-footer-logo { height: 6mm; width: auto; object-fit: contain; }
  .doc-footer-company { font-size: 10pt; font-weight: 800; letter-spacing: 2px; color: #111; }

  /* Full-page Waste Storage Sign (mode=sign) */
  .sign-sheet {
    width: 297mm; height: 210mm;
    margin: 14px auto;
    padding: 9mm;
    box-shadow: 0 6px 24px rgba(0,0,0,0.18);
    page-break-after: always;
    break-after: page;
    display: flex;
  }
  .sign-sheet:last-child { page-break-after: auto; break-after: auto; }
  .sign-frame {
    width: 100%; height: 100%;
    border: 4pt solid;
    display: flex; flex-direction: column;
  }
  .sign-header {
    text-align: center; font-size: 42pt; font-weight: 900; letter-spacing: 4px;
    padding: 14px 10px; border-bottom: 3pt solid;
  }
  .sign-table { width: 100%; flex: 1; border-collapse: collapse; table-layout: fixed; }
  .sign-table td {
    border: 2.5pt solid; padding: 10px 16px; font-size: 18pt; font-weight: 900; vertical-align: middle;
  }
  .sign-table tr:first-child td { border-top: none; }
  .sign-table tr:last-child td { border-bottom: none; }
  .sign-table td:first-child { border-left: none; }
  .sign-table td:last-child { border-right: none; }
  .sign-caution { font-size: 16pt !important; line-height: 1.55; vertical-align: top !important; }
  .sign-caution-line { margin-top: 6px; padding-left: 16px; text-indent: -16px; font-weight: 800; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .sheet, .sign-sheet { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div class="toolbar-left">
    <span>🖨️ <?= $e($docTitleMap[$mode]) ?></span>
  </div>
  <div class="toolbar-right">
    <?php if ($mode !== 'sign'): ?>
      <button type="button" class="tbtn active" id="mTop" onclick="setMargin('')">상단 편철 (기본 20/12mm)</button>
      <button type="button" class="tbtn" id="mLeft" onclick="setMargin('margin-left')">좌측 편철 (좌 20mm)</button>
      <button type="button" class="tbtn" id="mWide" onclick="setMargin('margin-wide')">여유 편철 (18/16mm)</button>
    <?php endif; ?>
    <button type="button" class="tbtn print" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
    <a class="tbtn" href="waste_management.php?year=<?= $year ?>">돌아가기</a>
  </div>
</div>

<?php if ($mode === 'process'): ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 4.1.1 환경관리 · 폐기물 배출현황 및 처리 프로세스</small>
        <h1><?= $year ?>년 폐기물 배출현황 및 처리 프로세스</h1>
        <div class="doc-meta-line">
          담당부서: <?= $e($processDoc['department']) ?> &nbsp;|&nbsp;
          관리책임자: <?= $e($processDoc['manager_name']) ?> &nbsp;|&nbsp;
          제·개정일: <?= $e($processDoc['revision_date']) ?>
        </div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl">
          <tr><th>작 성</th><th>승 인</th></tr>
          <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
        </table>
      </div>
    </div>

    <div class="sec-title">1. 폐기물 처리 업무 Flow (프로세스)</div>
    <div class="flow-print">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <div class="flow-box">
          <strong><?= $e($processDoc['step' . $i . '_title']) ?></strong>
          <p><?= nl2br($e($processDoc['step' . $i . '_desc'])) ?></p>
        </div>
      <?php endfor; ?>
    </div>

    <div class="sec-title">2. 사업장 폐기물 배출 현황 목록 (List)</div>
    <table class="data-tbl">
      <thead>
        <tr>
          <th style="width:28px;">No</th>
          <th style="width:68px;">분류</th>
          <th style="width:110px;">폐기물명<br>(분류번호)</th>
          <th style="width:36px;">성상</th>
          <th>발생 공정</th>
          <th style="width:130px;">보관장소<br>(용량/기간)</th>
          <th style="width:125px;">처리방법 및<br>처리(예정)장소</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($wasteTypes as $idx => $wt): ?>
        <tr>
          <td class="ctr"><?= $idx + 1 ?></td>
          <td class="ctr"><strong><?= $e($wt['category']) ?></strong></td>
          <td><strong><?= $e($wt['waste_name']) ?></strong><?= $wt['waste_code'] !== '' ? '<br><span style="color:#475569;font-size:7.6pt;">(' . $e($wt['waste_code']) . ')</span>' : '' ?></td>
          <td class="ctr"><?= $e($wt['state_form']) ?></td>
          <td><?= $e($wt['source_process']) ?></td>
          <td><?= $e($wt['storage_place']) ?><br><span style="color:#475569;font-size:7.6pt;"><?= $e($wt['storage_capacity']) ?> / <?= $e($wt['storage_period']) ?></span></td>
          <td><?= $e($wt['disposal_method']) ?><br><span style="color:#475569;font-size:7.6pt;"><?= $e($wt['disposal_vendor']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="doc-footer">
      <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
      <span class="doc-footer-company">주식회사 현대기전</span>
    </div>
  </section>

<?php elseif ($mode === 'log'): ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 4.1.2 환경관리 · 폐기물 배출처리 신고 및 관리대장</small>
        <h1><?= $year ?>년 사업장폐기물처리 관리대장</h1>
        <div class="doc-meta-line">
          담당부서: <?= $e($reportingInfo['department']) ?> &nbsp;|&nbsp;
          관리책임자: <?= $e($reportingInfo['manager_name']) ?> &nbsp;|&nbsp;
          최종출력일: <?= date('Y. m. d.') ?>
        </div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl">
          <tr><th>작 성</th><th>승 인</th></tr>
          <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
        </table>
      </div>
    </div>

    <div class="info-strip">
      <div class="info-cell"><b>배출자 신고 구분</b><?= $e($reportingInfo['report_status']) ?></div>
      <div class="info-cell"><b>신고번호 / 신고일</b><?= $e($reportingInfo['report_number'] ?: '-') ?> <?= $reportingInfo['report_date'] ? '(' . $e($reportingInfo['report_date']) . ')' : '' ?></div>
      <div class="info-cell"><b>주요 위탁처리업체 / 선별장</b><?= $e($reportingInfo['vendor_name'] ?: '-') ?></div>
      <div class="info-cell"><b>위탁계약 기간 / 올바로 ID</b><?= $e($reportingInfo['contract_period'] ?: '-') ?> <?= $reportingInfo['allbaro_id'] ? '/ ' . $e($reportingInfo['allbaro_id']) : '' ?></div>
    </div>

    <table class="data-tbl">
      <thead>
        <tr>
          <th rowspan="2" style="width:26px;">No</th>
          <th rowspan="2" style="width:92px;">폐기물 종류<br>(단위)</th>
          <th colspan="3">발 생 내 역</th>
          <th colspan="4">자 가 처 리 내 역</th>
          <th colspan="5">위 탁 처 리 내 역</th>
          <th rowspan="2">비고</th>
        </tr>
        <tr>
          <th style="width:66px;">년·월·일</th>
          <th style="width:46px;">발생량</th>
          <th style="width:48px;">발생량<br>누계</th>
          <th style="width:64px;">년·월·일</th>
          <th style="width:42px;">처리량</th>
          <th style="width:60px;">처리방법</th>
          <th style="width:44px;">처리량<br>누계</th>
          <th style="width:64px;">년·월·일</th>
          <th style="width:46px;">위탁<br>처리량</th>
          <th style="width:82px;">처리방법/업체</th>
          <th style="width:64px;">인계서번호</th>
          <th style="width:48px;">위탁량<br>누계</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$disposalLogs): ?>
          <?php for ($r = 0; $r < 12; $r++): ?>
            <tr>
              <td class="ctr"><?= $r + 1 ?></td>
              <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
              <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
              <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
          <?php endfor; ?>
        <?php else: ?>
          <?php foreach ($disposalLogs as $idx => $row): ?>
          <tr>
            <td class="ctr"><?= $idx + 1 ?></td>
            <td><strong><?= $e($row['waste_name']) ?></strong> (<?= $e($row['unit']) ?>)</td>
            <td class="ctr"><?= $e($row['occur_date']) ?></td>
            <td class="num"><?= number_format((float)$row['occur_qty'], 1) ?></td>
            <td class="num"><strong><?= number_format((float)$row['cum_occur'], 1) ?></strong></td>
            <td class="ctr"><?= $e($row['self_date']) ?></td>
            <td class="num"><?= (float)$row['self_qty'] > 0 ? number_format((float)$row['self_qty'], 1) : '' ?></td>
            <td><?= $e($row['self_method']) ?></td>
            <td class="num"><?= (float)$row['cum_self'] > 0 ? number_format((float)$row['cum_self'], 1) : '' ?></td>
            <td class="ctr"><?= $e($row['consign_date']) ?></td>
            <td class="num"><?= (float)$row['consign_qty'] > 0 ? number_format((float)$row['consign_qty'], 1) : '' ?></td>
            <td><?= $e($row['consign_method']) ?><?= $row['consign_vendor'] !== '' ? '<br>' . $e($row['consign_vendor']) : '' ?></td>
            <td class="ctr"><?= $e($row['manifest_no']) ?></td>
            <td class="num"><?= (float)$row['cum_consign'] > 0 ? number_format((float)$row['cum_consign'], 1) : '' ?></td>
            <td><?= $e($row['note']) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="doc-footer">
      <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
      <span class="doc-footer-company">주식회사 현대기전</span>
    </div>
  </section>

<?php elseif ($mode === 'storage'): ?>
  <?php if (!$checksToPrint): ?>
    <section class="sheet">
      <div class="doc-head">
        <div class="doc-title-area">
          <small>CSM 4.1.3 환경관리 · 폐기물 보관·분리배출 점검표</small>
          <h1><?= $year ?>년 폐기물 보관·분리배출 정기점검표</h1>
        </div>
        <div class="approval-wrap">
          <div class="ap-side">결<br>재</div>
          <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
        </div>
      </div>
      <p style="padding:30px; text-align:center; color:#64748b;">등록된 <?= $year ?>년 보관·분리배출 점검 기록이 없습니다.</p>
      <div class="doc-footer">
        <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
        <span class="doc-footer-company">주식회사 현대기전</span>
      </div>
    </section>
  <?php else: ?>
    <?php
    $chkLabels = [
        'chk_sign_installed' => '폐기물 보관표지판 설치 및 기재사항(종류·용량·관리책임자·주의사항) 최신화 여부',
        'chk_bin_labeled' => '분리수거함 종류별 분리 설치 및 품목 명칭 표기 여부 (일반/재활용/폐전선·고철 등)',
        'chk_segregated' => '성상·종류별 혼합보관 금지 및 지정구역 내 분리배출 준수 여부',
        'chk_containment' => '침출수·비산·유출 방지 조치 및 보관마대/덮개 파손 여부',
        'chk_capacity_period' => '보관기간 준수 및 적정 보관용량 초과 여부',
        'chk_housekeeping' => '보관장소 주변 정리정돈 및 화기 접근 금지 등 안전관리 상태',
    ];
    foreach ($checksToPrint as $sc):
    ?>
    <section class="sheet">
      <div class="doc-head">
        <div class="doc-title-area">
          <small>CSM 4.1.3 환경관리 · 폐기물 보관표지판 및 분리배출 점검</small>
          <h1>폐기물 보관·분리배출 정기점검표</h1>
          <div class="doc-meta-line">
            점검일자: <?= $e($sc['check_date']) ?> &nbsp;|&nbsp;
            점검자: <?= $e($sc['inspector']) ?> &nbsp;|&nbsp;
            점검장소: <?= $e($sc['location']) ?>
          </div>
        </div>
        <div class="approval-wrap">
          <div class="ap-side">결<br>재</div>
          <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
        </div>
      </div>

      <div class="sec-title">1. 보관장소·표지판 및 분리배출 점검 결과 (종합: <?= $e($sc['overall_result']) ?>)</div>
      <table class="data-tbl">
        <thead>
          <tr>
            <th style="width:34px;">No</th>
            <th>점검 항목</th>
            <th style="width:76px;">점검 결과</th>
          </tr>
        </thead>
        <tbody>
          <?php $n = 1; foreach ($chkLabels as $col => $lbl): ?>
          <tr>
            <td class="ctr"><?= $n++ ?></td>
            <td><?= $e($lbl) ?></td>
            <td class="ctr"><strong><?= $e($sc[$col]) ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="sec-title">2. 지적사항 및 개선조치 내용</div>
      <table class="data-tbl">
        <tr>
          <th style="width:110px;">지적 / 미흡 사항</th>
          <td style="min-height:36px;"><?= $sc['issue_note'] !== '' ? nl2br($e($sc['issue_note'])) : '특이사항 없음 (양호)' ?></td>
        </tr>
        <tr>
          <th>개선 조치 내용</th>
          <td style="min-height:36px;"><?= $sc['action_note'] !== '' ? nl2br($e($sc['action_note'])) : '지속 유지관리' ?></td>
        </tr>
      </table>

      <div class="sec-title">3. 현장 확인 사진 (보관표지판 및 분리수거함)</div>
      <div class="photo-grid">
        <div class="photo-box">
          <div class="pcap">① 폐기물 보관표지판 설치 상태</div>
          <?php if ($sc['photo_sign'] !== ''): ?>
            <img src="waste_management.php?view_photo=<?= urlencode((string)$sc['photo_sign']) ?>" alt="보관표지판 사진">
          <?php else: ?>
            <div class="photo-empty">첨부 사진 없음</div>
          <?php endif; ?>
        </div>
        <div class="photo-box">
          <div class="pcap">② 분리수거함 분리·표기 상태</div>
          <?php if ($sc['photo_bin'] !== ''): ?>
            <img src="waste_management.php?view_photo=<?= urlencode((string)$sc['photo_bin']) ?>" alt="분리수거함 사진">
          <?php else: ?>
            <div class="photo-empty">첨부 사진 없음</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="doc-footer">
        <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
        <span class="doc-footer-company">주식회사 현대기전</span>
      </div>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>

<?php else: ?>
  <!-- MODE: sign (A4 Landscape Full-Color Storage Signboard) -->
  <?php foreach ($wasteTypesForSign as $wt):
    $theme = $signThemes[$wt['sign_theme']] ?? $signThemes['general'];
  ?>
  <section class="sign-sheet" style="background:<?= $e($theme['bg']) ?>; color:<?= $e($theme['fg']) ?>;">
    <div class="sign-frame" style="border-color:<?= $e($theme['border']) ?>;">
      <div class="sign-header" style="border-color:<?= $e($theme['border']) ?>;">
        <?= $e($theme['title']) ?>
      </div>
      <table class="sign-table">
        <tr style="height:14%;">
          <td style="width:50%; border-color:<?= $e($theme['border']) ?>;">1. 폐기물의 종류 : <?= $e($wt['waste_name']) ?></td>
          <td style="width:50%; border-color:<?= $e($theme['border']) ?>;">2. 보관가능용량 : <?= $e($wt['storage_capacity']) ?></td>
        </tr>
        <tr style="height:14%;">
          <td style="border-color:<?= $e($theme['border']) ?>;">3. 관리책임자 : <?= $e($wt['manager_name']) ?></td>
          <td style="border-color:<?= $e($theme['border']) ?>;">4. 보관기간 : <?= $e($wt['storage_period']) ?></td>
        </tr>
        <tr style="height:13%;">
          <td colspan="2" style="border-color:<?= $e($theme['border']) ?>;">5. 특 성 : <?= $e($wt['characteristic'] ?: $wt['state_form']) ?></td>
        </tr>
        <tr style="height:43%;">
          <td colspan="2" class="sign-caution" style="border-color:<?= $e($theme['border']) ?>;">
            <div>6. 취급 시 주의사항</div>
            <?php if ($wt['caution_storage'] !== ''): ?>
              <div class="sign-caution-line">○ 보관 시 : <?= $e($wt['caution_storage']) ?></div>
            <?php endif; ?>
            <?php if ($wt['caution_transport'] !== ''): ?>
              <div class="sign-caution-line">○ 운반 시 : <?= $e($wt['caution_transport']) ?></div>
            <?php endif; ?>
            <?php if ($wt['caution_disposal'] !== ''): ?>
              <div class="sign-caution-line">○ 처리 시 : <?= $e($wt['caution_disposal']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <tr style="height:16%;">
          <td colspan="2" style="border-color:<?= $e($theme['border']) ?>;">7. 운반(처리) 예정장소 : <?= $e($wt['disposal_vendor']) ?></td>
        </tr>
      </table>
    </div>
  </section>
  <?php endforeach; ?>
<?php endif; ?>

<script>
function setMargin(cls) {
  document.body.className = cls;
  document.getElementById('mTop')?.classList.toggle('active', cls === '');
  document.getElementById('mLeft')?.classList.toggle('active', cls === 'margin-left');
  document.getElementById('mWide')?.classList.toggle('active', cls === 'margin-wide');
}
</script>
</body>
</html>
