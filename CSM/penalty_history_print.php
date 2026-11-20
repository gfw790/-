<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/penalty_history_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$db = pen_db();
$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) $year = $nowYear;

$s = $db->prepare('SELECT * FROM pen_records WHERE year=? ORDER BY occur_date ASC, id ASC');
$s->execute([$year]);
$records = $s->fetchAll();

$meta = pen_item_meta();
$totalDed = 0.0;
foreach (array_keys($meta) as $c) {
    $totalDed += csm_penalty_score_summary($year, $c)['earned'];
}

$logoSrc = is_file(__DIR__ . '/assets/policy-logo.png')
    ? 'assets/policy-logo.png'
    : (is_file(dirname(__DIR__) . '/safety_manual/assets/policy-logo.png') ? '../safety_manual/assets/policy-logo.png' : '');
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $year ?>년 감점·사고이력 관리대장 (6.2) · 주식회사 현대기전</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  body { margin: 0; background: #e5e9ec; color: #111; font-family: "Malgun Gothic","Apple SD Gothic Neo",sans-serif; font-size: 9.2pt; line-height: 1.5; }
  .toolbar { position: sticky; top: 0; z-index: 100; background: #4c1125; color: #fff; padding: 10px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; }
  .toolbar-right { display: flex; flex-wrap: wrap; gap: 8px; }
  .tbtn { border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); color: #fff; padding: 6px 12px; border-radius: 7px; font-weight: 700; font-size: 12.5px; cursor: pointer; text-decoration: none; }
  .tbtn.active { background: #fff; color: #4c1125; border-color: #fff; }
  .tbtn.print { background: #9f1239; border-color: #fda4af; }
  .sheet { width: 210mm; min-height: 297mm; margin: 14px auto; background: #fff; padding: 20mm 12mm 12mm 12mm; box-shadow: 0 6px 24px rgba(0,0,0,0.14); display: flex; flex-direction: column; }
  body.margin-left .sheet { padding: 14mm 12mm 12mm 20mm; }
  body.margin-wide .sheet { padding: 18mm 16mm 16mm 16mm; }
  body.hide-approval .approval-wrap { display: none !important; }
  .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #111; }
  .doc-title-area small { display: block; font-size: 8.3pt; color: #475569; font-weight: 700; }
  .doc-title-area h1 { margin: 2px 0 3px; font-size: 16pt; font-weight: 900; }
  .approval-wrap { display: flex; flex-shrink: 0; border: 1pt solid #000; }
  .ap-side { width: 18px; display: flex; align-items: center; justify-content: center; text-align: center; font-weight: 800; font-size: 8.3pt; line-height: 1.4; border-right: 1pt solid #000; background: #f8fafc; }
  .ap-tbl { border-collapse: collapse; width: 128px; table-layout: fixed; }
  .ap-tbl th, .ap-tbl td { border: 1pt solid #000; text-align: center; padding: 2px 4px; width: 50%; }
  .ap-tbl tr:first-child th { border-top: none; background: #f8fafc; font-size: 8.3pt; font-weight: 800; height: 19px; }
  .ap-tbl tr:last-child td { border-bottom: none; height: 40px; }
  .ap-tbl th:first-child, .ap-tbl td:first-child { border-left: none; }
  .ap-tbl th:last-child, .ap-tbl td:last-child { border-right: none; }
  .sec-title { font-size: 10pt; font-weight: 900; margin: 12px 0 6px; padding-left: 7px; border-left: 3.5px solid #9f1239; }
  table.data-tbl { width: 100%; border-collapse: collapse; font-size: 8.6pt; }
  table.data-tbl th, table.data-tbl td { border: 0.75pt solid #334155; padding: 5px 6px; vertical-align: middle; }
  table.data-tbl th { background: #f8f3f5; font-weight: 800; text-align: center; }
  .ctr { text-align: center; }
  .doc-footer { margin-top: auto; padding-top: 8px; border-top: 0.75pt solid #cbd5e1; display: flex; align-items: center; justify-content: center; gap: 8px; }
  .doc-footer-logo { height: 6mm; width: auto; object-fit: contain; }
  .doc-footer-company { font-size: 10pt; font-weight: 800; letter-spacing: 2px; color: #111; }
  @media print { body { background: #fff; } .toolbar { display: none !important; } .sheet { margin: 0; box-shadow: none; } }
</style>
</head>
<body>
<div class="toolbar">
  <div><strong>🖨️ <?= $year ?>년 감점·사고이력 확인서 (6.2)</strong></div>
  <div class="toolbar-right">
    <button type="button" class="tbtn active" id="btnApproval" onclick="toggleApproval()">결재란 ON</button>
    <button type="button" class="tbtn active" id="mTop" onclick="setMargin('')">상단 편철</button>
    <button type="button" class="tbtn" id="mLeft" onclick="setMargin('margin-left')">좌측 편철</button>
    <button type="button" class="tbtn" id="mWide" onclick="setMargin('margin-wide')">여유 편철</button>
    <button type="button" class="tbtn print" onclick="window.print()">🖨️ 인쇄 / PDF</button>
    <a class="tbtn" href="penalty_history.php?year=<?= $year ?>">돌아가기</a>
  </div>
</div>

<section class="sheet">
  <div class="doc-head">
    <div class="doc-title-area">
      <small>CSM 6.2 감점·사고이력 · 경고장 / LTI·MI 재해 / 환경사고</small>
      <h1><?= $year ?>년 감점·사고이력 관리대장 및 확인서</h1>
      <div style="font-size:8.5pt;color:#334155;">대상연도: <?= $year ?>년 &nbsp;|&nbsp; 총 감점: <strong><?= number_format($totalDed, 1) ?>점</strong> &nbsp;|&nbsp; 출력일: <?= date('Y. m. d.') ?></div>
    </div>
    <div class="approval-wrap"><div class="ap-side">결<br>재</div><table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table></div>
  </div>

  <div class="sec-title">1. 항목별 감점 요약</div>
  <table class="data-tbl">
    <thead><tr><th style="width:14%;">항목</th><th>평가 내용</th><th style="width:16%;">발생 건수</th><th style="width:16%;">감점 합계</th></tr></thead>
    <tbody>
      <?php foreach ($meta as $c => $m): $sc = csm_penalty_score_summary($year, $c); ?>
        <tr><td class="ctr"><strong><?= $e($c) ?></strong></td><td><?= $e($m['title']) ?></td><td class="ctr"><?= (int)$sc['incident_count'] ?>건</td><td class="ctr"><strong><?= number_format($sc['earned'], 1) ?>점</strong></td></tr>
      <?php endforeach; ?>
      <tr><th colspan="2">합 계</th><th class="ctr"><?= count($records) ?>건</th><th class="ctr"><?= number_format($totalDed, 1) ?>점</th></tr>
    </tbody>
  </table>

  <div class="sec-title">2. 상세 발생 및 조치 이력</div>
  <table class="data-tbl">
    <thead><tr><th style="width:10%;">항목</th><th style="width:20%;">구분</th><th style="width:12%;">발생일</th><th style="width:9%;">감점</th><th>발생 내용 및 시정조치</th><th style="width:10%;">상태</th></tr></thead>
    <tbody>
      <?php if (!$records): ?>
        <tr><td colspan="6" class="ctr" style="padding:32px;font-weight:800;color:#15803d;font-size:10pt;">당사는 <?= $year ?>년도 안전 경고장, 근로손실·의료처치재해(LTI/MI), 환경사고 발생 내역이 없음을 확인합니다. (무감점 0점)</td></tr>
      <?php else: foreach ($records as $r): ?>
        <tr><td class="ctr"><?= $e($r['item_code']) ?></td><td><?= $e($r['subtype_label']) ?></td><td class="ctr"><?= $e($r['occur_date']) ?></td><td class="ctr"><strong><?= number_format((float)$r['deduction_points'], 1) ?>점</strong></td><td><?= $e($r['summary']) ?><?= $r['corrective_action'] ? '<br>조치: ' . $e($r['corrective_action']) : '' ?></td><td class="ctr"><?= $e($r['status']) ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="doc-footer"><?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?><span class="doc-footer-company">주식회사 현대기전</span></div>
</section>

<script>
function setMargin(c){ document.body.classList.remove('margin-left','margin-wide'); if(c) document.body.classList.add(c); ['mTop','mLeft','mWide'].forEach((id,i)=>document.getElementById(id)?.classList.toggle('active', c===['','margin-left','margin-wide'][i])); }
function toggleApproval(){ const h=document.body.classList.toggle('hide-approval'); const b=document.getElementById('btnApproval'); if(b){ b.classList.toggle('active',!h); b.textContent=h?'결재란 OFF':'결재란 ON'; } }
</script>
</body>
</html>
