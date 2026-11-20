<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/health_management_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$db = hmg_db();
$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) $year = $nowYear;

$mode = (string)($_GET['mode'] ?? 'checkup');
if (!in_array($mode, ['checkup', 'env', 'msds', 'chem_check', 'first_aid'], true)) $mode = 'checkup';
$idFilter = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$isLandscape = in_array($mode, ['checkup', 'env', 'msds'], true);
$logoSrc = is_file(__DIR__ . '/assets/policy-logo.png')
    ? 'assets/policy-logo.png'
    : (is_file(dirname(__DIR__) . '/safety_manual/assets/policy-logo.png') ? '../safety_manual/assets/policy-logo.png' : '');

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$titles = [
    'checkup' => $year . '년 일반·특수 건강검진 관리대장 (2.8.1)',
    'env' => $year . '년 작업환경측정 및 개선 관리대장 (2.8.2)',
    'msds' => $year . '년 취급 화학물질 및 MSDS 관리대장 (2.8.3)',
    'chem_check' => $year . '년 화학물질 보관·GHS 경고표지 점검표 (2.8.4)',
    'first_aid' => $year . '년 응급처치 장비·의약품 월간점검표 (2.8.5)',
];

$rows = [];
if ($mode === 'checkup') {
    $s = $db->prepare('SELECT * FROM hmg_checkups WHERE year=? ORDER BY exam_date ASC, id ASC'); $s->execute([$year]); $rows = $s->fetchAll();
} elseif ($mode === 'env') {
    $s = $db->prepare('SELECT * FROM hmg_env_measurements WHERE year=? ORDER BY measure_date ASC, id ASC'); $s->execute([$year]); $rows = $s->fetchAll();
} elseif ($mode === 'msds') {
    $rows = $db->query('SELECT * FROM hmg_chemicals WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchAll();
} elseif ($mode === 'chem_check') {
    $s = $db->prepare($idFilter > 0 ? 'SELECT * FROM hmg_chem_checks WHERE year=? AND id=?' : 'SELECT * FROM hmg_chem_checks WHERE year=? ORDER BY check_date DESC, id DESC');
    $s->execute($idFilter > 0 ? [$year, $idFilter] : [$year]); $rows = $s->fetchAll();
} elseif ($mode === 'first_aid') {
    $s = $db->prepare($idFilter > 0 ? 'SELECT * FROM hmg_first_aid_checks WHERE year=? AND id=?' : 'SELECT * FROM hmg_first_aid_checks WHERE year=? ORDER BY check_date DESC, id DESC');
    $s->execute($idFilter > 0 ? [$year, $idFilter] : [$year]); $rows = $s->fetchAll();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $e($titles[$mode]) ?> · 주식회사 현대기전</title>
<style>
  @page { size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>; margin: 0; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  body { margin: 0; background: #e5e9ec; color: #111; font-family: "Malgun Gothic","Apple SD Gothic Neo",sans-serif; font-size: 9pt; line-height: 1.45; }
  .toolbar { position: sticky; top: 0; z-index: 100; background: #0c354c; color: #fff; padding: 10px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; }
  .toolbar-right { display: flex; flex-wrap: wrap; gap: 8px; }
  .tbtn { border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); color: #fff; padding: 6px 12px; border-radius: 7px; font-weight: 700; font-size: 12.5px; cursor: pointer; text-decoration: none; }
  .tbtn.active { background: #fff; color: #0c354c; border-color: #fff; }
  .tbtn.print { background: #0369a1; border-color: #7dd3fc; }
  .sheet { width: <?= $isLandscape ? '297mm' : '210mm' ?>; min-height: <?= $isLandscape ? '210mm' : '297mm' ?>; margin: 14px auto; background: #fff; padding: 20mm 12mm 12mm 12mm; box-shadow: 0 6px 24px rgba(0,0,0,0.14); display: flex; flex-direction: column; page-break-after: always; break-after: page; }
  .sheet:last-child { page-break-after: auto; break-after: auto; }
  body.margin-left .sheet { padding: 14mm 12mm 12mm 20mm; }
  body.margin-wide .sheet { padding: 18mm 16mm 16mm 16mm; }
  body.hide-approval .approval-wrap { display: none !important; }
  .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #111; }
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
  .sec-title { font-size: 10pt; font-weight: 900; margin: 11px 0 5px; padding-left: 7px; border-left: 3.5px solid #0369a1; }
  table.data-tbl { width: 100%; border-collapse: collapse; font-size: 8.4pt; }
  table.data-tbl th, table.data-tbl td { border: 0.75pt solid #334155; padding: 4px 6px; vertical-align: middle; }
  table.data-tbl th { background: #eef4f8; font-weight: 800; text-align: center; }
  .ctr { text-align: center; }
  .photo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 8px; }
  .photo-box { border: 0.75pt solid #334155; padding: 6px; text-align: center; }
  .photo-box .pcap { font-size: 8.3pt; font-weight: 800; margin-bottom: 5px; background: #f1f5f9; padding: 3px; }
  .photo-box img { max-width: 100%; max-height: 68mm; object-fit: contain; display: block; margin: 0 auto; }
  .photo-empty { height: 54mm; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 8.3pt; }
  .doc-footer { margin-top: auto; padding-top: 8px; border-top: 0.75pt solid #cbd5e1; display: flex; align-items: center; justify-content: center; gap: 8px; }
  .doc-footer-logo { height: 6mm; width: auto; object-fit: contain; }
  .doc-footer-company { font-size: 10pt; font-weight: 800; letter-spacing: 2px; color: #111; }
  @media print { body { background: #fff; } .toolbar { display: none !important; } .sheet { margin: 0; box-shadow: none; } }
</style>
</head>
<body>
<div class="toolbar">
  <div><strong>🖨️ <?= $e($titles[$mode]) ?></strong></div>
  <div class="toolbar-right">
    <button type="button" class="tbtn active" id="btnApproval" onclick="toggleApproval()">결재란 ON</button>
    <button type="button" class="tbtn active" id="mTop" onclick="setMargin('')">상단 편철</button>
    <button type="button" class="tbtn" id="mLeft" onclick="setMargin('margin-left')">좌측 편철</button>
    <button type="button" class="tbtn" id="mWide" onclick="setMargin('margin-wide')">여유 편철</button>
    <button type="button" class="tbtn print" onclick="window.print()">🖨️ 인쇄 / PDF</button>
    <a class="tbtn" href="health_management.php?year=<?= $year ?>">돌아가기</a>
  </div>
</div>

<?php if ($mode === 'checkup' || $mode === 'env' || $mode === 'msds'): ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 2.8 보건 · 주식회사 현대기전</small>
        <h1><?= $e($titles[$mode]) ?></h1>
        <div style="font-size:8.5pt;color:#334155;">관리연도: <?= $year ?>년 &nbsp;|&nbsp; 출력일: <?= date('Y. m. d.') ?></div>
      </div>
      <div class="approval-wrap"><div class="ap-side">결<br>재</div><table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table></div>
    </div>

    <?php if ($mode === 'checkup'): ?>
      <table class="data-tbl"><thead><tr><th>No</th><th>성명</th><th>근무구분</th><th>검진구분</th><th>유해인자</th><th>검진일</th><th>검진기관</th><th>판정</th><th>사후관리</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="ctr" style="padding:24px;">등록된 건강검진 기록이 없습니다.</td></tr>
        <?php else: foreach ($rows as $i => $r): ?>
          <tr><td class="ctr"><?= $i+1 ?></td><td class="ctr"><strong><?= $e($r['worker_name']) ?></strong></td><td class="ctr"><?= $e($r['job_type']) ?></td><td class="ctr"><?= $e($r['exam_type']) ?></td><td><?= $e($r['hazard_factors']) ?></td><td class="ctr"><?= $e($r['exam_date']) ?></td><td><?= $e($r['hospital']) ?></td><td class="ctr"><?= $e($r['result_grade']) ?></td><td><?= $e($r['followup_note']) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table>
    <?php elseif ($mode === 'env'): ?>
      <table class="data-tbl"><thead><tr><th>No</th><th>구분</th><th>측정일</th><th>측정기관</th><th>측정 구역/공정</th><th>유해인자</th><th>측정 결과</th><th>개선조치 / 완료일</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="ctr" style="padding:24px;">등록된 작업환경측정 기록이 없습니다.</td></tr>
        <?php else: foreach ($rows as $i => $r): ?>
          <tr><td class="ctr"><?= $i+1 ?></td><td class="ctr"><?= $e($r['half_label']) ?></td><td class="ctr"><?= $e($r['measure_date']) ?></td><td><?= $e($r['agency']) ?></td><td><?= $e($r['work_area']) ?></td><td><?= $e($r['hazard_factors']) ?></td><td class="ctr"><?= $e($r['result_status']) ?></td><td><?= $e($r['action_note']) ?><?= $r['action_done_date'] ? ' (' . $e($r['action_done_date']) . ')' : '' ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table>
    <?php else: ?>
      <table class="data-tbl"><thead><tr><th>No</th><th>화학물질명</th><th>유해성 분류</th><th>제조사</th><th>사용 공정</th><th>보관장소</th><th>MSDS 개정일</th><th>MSDS 비치</th><th>GHS 표지</th></tr></thead><tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr><td class="ctr"><?= $i+1 ?></td><td><strong><?= $e($r['chem_name']) ?></strong></td><td><?= $e($r['hazard_class']) ?></td><td><?= $e($r['manufacturer']) ?></td><td><?= $e($r['usage_process']) ?></td><td><?= $e($r['storage_place']) ?></td><td class="ctr"><?= $e($r['msds_rev_date']) ?></td><td class="ctr"><?= $e($r['msds_placed']) ?></td><td class="ctr"><?= $e($r['ghs_labeled']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>

    <div class="doc-footer"><?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?><span class="doc-footer-company">주식회사 현대기전</span></div>
  </section>

<?php else: ?>
  <?php if (!$rows): ?>
    <section class="sheet">
      <div class="doc-head"><div class="doc-title-area"><small>CSM 2.8 보건</small><h1><?= $e($titles[$mode]) ?></h1></div><div class="approval-wrap"><div class="ap-side">결<br>재</div><table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table></div></div>
      <p style="padding:36px;text-align:center;color:#64748b;">등록된 <?= $year ?>년 점검 기록이 없습니다.</p>
      <div class="doc-footer"><?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?><span class="doc-footer-company">주식회사 현대기전</span></div>
    </section>
  <?php else: foreach ($rows as $r): ?>
    <section class="sheet">
      <div class="doc-head">
        <div class="doc-title-area">
          <small>CSM <?= $mode === 'chem_check' ? '2.8.4' : '2.8.5' ?> 보건 · 현장 정기점검표</small>
          <h1><?= $e($titles[$mode]) ?></h1>
          <div style="font-size:8.5pt;color:#334155;">점검일: <?= $e($r['check_date']) ?> &nbsp;|&nbsp; 점검자: <?= $e($r['inspector']) ?> &nbsp;|&nbsp; 장소: <?= $e($r['location']) ?></div>
        </div>
        <div class="approval-wrap"><div class="ap-side">결<br>재</div><table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table></div>
      </div>

      <div class="sec-title">1. 점검 결과 (종합: <?= $e($r['overall_result']) ?>)</div>
      <table class="data-tbl"><thead><tr><th style="width:34px;">No</th><th>점검 항목</th><th style="width:76px;">결과</th></tr></thead><tbody>
        <?php
        $items = $mode === 'chem_check'
          ? ['chk_msds_latest'=>'MSDS 최신본 현장 비치 및 열람 가능 여부','chk_ghs_label'=>'화학물질 용기·보관함 MSDS-GHS 경고표지 부착','chk_small_container'=>'소분 용기 경고표지 및 내용물 명칭 표기','chk_vent_lock'=>'전용 보관함 시건장치·환기 및 화기엄금 표지','chk_spill_kit'=>'누출방지 트레이·흡착포 등 방제용품 비치','chk_segregation'=>'성상별 분리 보관 여부']
          : ['chk_med_stock'=>'필수 의약품 비치 수량 적정 여부','chk_expiry'=>'의약품 유효기간 경과 여부','chk_equip_state'=>'응급구조장비 파손·분실 없이 사용가능 상태','chk_ledger'=>'의약품·응급장비 관리대장 작성 상태'];
        $n = 1; foreach ($items as $col => $lbl): ?>
          <tr><td class="ctr"><?= $n++ ?></td><td><?= $e($lbl) ?></td><td class="ctr"><strong><?= $e($r[$col]) ?></strong></td></tr>
        <?php endforeach; ?>
      </tbody></table>

      <div class="sec-title">2. 조치 및 비고 사항</div>
      <table class="data-tbl">
        <?php if ($mode === 'chem_check'): ?>
          <tr><th style="width:110px;">지적 사항</th><td><?= $e($r['issue_note'] ?: '특이사항 없음 (양호)') ?></td></tr>
          <tr><th>개선 조치</th><td><?= $e($r['action_note'] ?: '지속 유지관리') ?></td></tr>
        <?php else: ?>
          <tr><th style="width:110px;">보충·교체 비고</th><td><?= $e($r['refill_note'] ?: '특이사항 없음 (양호)') ?></td></tr>
        <?php endif; ?>
      </table>

      <div class="sec-title">3. 현장 확인 사진</div>
      <div class="photo-grid">
        <div class="photo-box"><div class="pcap"><?= $mode === 'chem_check' ? '① 화학물질 보관함 상태' : '① 구급함·의약품 비치 상태' ?></div><?php if ($r['photo1']): ?><img src="health_management.php?view_photo=<?= urlencode($r['photo1']) ?>"><?php else: ?><div class="photo-empty">첨부 사진 없음</div><?php endif; ?></div>
        <div class="photo-box"><div class="pcap"><?= $mode === 'chem_check' ? '② MSDS-GHS 경고표지 부착' : '② 응급구조장비 비치 상태' ?></div><?php if ($r['photo2']): ?><img src="health_management.php?view_photo=<?= urlencode($r['photo2']) ?>"><?php else: ?><div class="photo-empty">첨부 사진 없음</div><?php endif; ?></div>
      </div>

      <div class="doc-footer"><?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?><span class="doc-footer-company">주식회사 현대기전</span></div>
    </section>
  <?php endforeach; endif; ?>
<?php endif; ?>

<script>
function setMargin(c){ document.body.classList.remove('margin-left','margin-wide'); if(c) document.body.classList.add(c); ['mTop','mLeft','mWide'].forEach((id,i)=>document.getElementById(id)?.classList.toggle('active', c===['','margin-left','margin-wide'][i])); }
function toggleApproval(){ const h=document.body.classList.toggle('hide-approval'); const b=document.getElementById('btnApproval'); if(b){ b.classList.toggle('active',!h); b.textContent=h?'결재란 OFF':'결재란 ON'; } }
</script>
</body>
</html>
