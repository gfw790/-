<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workplace_inspection_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$db = wi_db();
$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) {
    $year = $nowYear;
}
$mode = (string)($_GET['mode'] ?? '5s');
if (!in_array($mode, ['5s', 'check'], true)) {
    $mode = '5s';
}

$isLandscape = ($mode === '5s');
$itemMeta = wi_item_meta();
$s5Questions = wi_5s_questions();
$s5Meta = wi_get_5s_meta($db, $year);
$s5Matrix = wi_get_5s_matrix($db, $year);

$itemFilter = (string)($_GET['item'] ?? 'all');
$checkId = isset($_GET['check_id']) ? (int)$_GET['check_id'] : 0;

if ($mode === 'check') {
    $all = wi_list_checks($db, $year, isset($itemMeta[$itemFilter]) ? $itemFilter : null);
    if ($checkId > 0) {
        $checksToPrint = array_values(array_filter($all, static fn($r) => (int)$r['id'] === $checkId));
    } else {
        $checksToPrint = $all;
    }
} else {
    $checksToPrint = [];
}

$logoSrc = is_file(__DIR__ . '/assets/policy-logo.png')
    ? 'assets/policy-logo.png'
    : (is_file(dirname(__DIR__) . '/safety_manual/assets/policy-logo.png') ? '../safety_manual/assets/policy-logo.png' : '');

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$docTitle = $mode === '5s'
    ? $year . '년 작업장 정리정돈 (5S) 점검표 (3.1.1)'
    : $year . '년 현장점검·작업환경 정기점검표 (' . (isset($itemMeta[$itemFilter]) ? $itemFilter : '3.1 전체') . ')';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $e($docTitle) ?> · 주식회사 현대기전</title>
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
    font-size: 9.2pt;
    line-height: 1.4;
  }

  .toolbar {
    position: sticky; top: 0; z-index: 100;
    background: #172a46; color: #fff; padding: 10px 20px;
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  }
  .toolbar-left { font-weight: 800; font-size: 13.5px; }
  .toolbar-right { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .tbtn {
    border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); color: #fff;
    padding: 6px 12px; border-radius: 7px; font-weight: 700; font-size: 12.5px; cursor: pointer; text-decoration: none;
  }
  .tbtn:hover { background: rgba(255,255,255,0.22); }
  .tbtn.active { background: #fff; color: #172a46; border-color: #fff; }
  .tbtn.print { background: #1d4ed8; border-color: #93c5fd; }

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
  body.hide-approval .approval-wrap { display: none !important; }

  .doc-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
    margin-bottom: 8px; padding-bottom: 7px; border-bottom: 2px solid #111;
  }
  .doc-title-area small { display: block; font-size: 8.3pt; color: #475569; font-weight: 700; }
  .doc-title-area h1 { margin: 2px 0 3px; font-size: 16pt; font-weight: 900; letter-spacing: -0.4px; }
  .doc-meta-line { font-size: 8.5pt; color: #334155; }

  /* CSM 2-column equal-width blank approval box */
  .approval-wrap { display: flex; flex-shrink: 0; border: 1pt solid #000; }
  .ap-side {
    width: 18px; display: flex; align-items: center; justify-content: center; text-align: center;
    font-weight: 800; font-size: 8.3pt; line-height: 1.4; border-right: 1pt solid #000; background: #f8fafc;
  }
  .ap-tbl { border-collapse: collapse; width: 128px; table-layout: fixed; }
  .ap-tbl th, .ap-tbl td { border: 1pt solid #000; text-align: center; padding: 2px 4px; width: 50%; }
  .ap-tbl tr:first-child th { border-top: none; background: #f8fafc; font-size: 8.3pt; font-weight: 800; height: 19px; }
  .ap-tbl tr:last-child td { border-bottom: none; height: 40px; }
  .ap-tbl th:first-child, .ap-tbl td:first-child { border-left: none; }
  .ap-tbl th:last-child, .ap-tbl td:last-child { border-right: none; }

  .sec-title { font-size: 10pt; font-weight: 900; margin: 11px 0 5px; padding-left: 7px; border-left: 3.5px solid #1d4ed8; }

  table.data-tbl { width: 100%; border-collapse: collapse; font-size: 8.3pt; }
  table.data-tbl th, table.data-tbl td { border: 0.75pt solid #334155; padding: 3.5px 5px; vertical-align: middle; }
  table.data-tbl th { background: #eef2f7; font-weight: 800; text-align: center; }
  .ctr { text-align: center; }

  table.s5-print { width: 100%; border-collapse: collapse; font-size: 7.7pt; }
  table.s5-print th, table.s5-print td { border: 0.7pt solid #334155; padding: 2.3px 4px; vertical-align: middle; }
  table.s5-print th { background: #eef2f7; font-weight: 800; text-align: center; }
  .s5-grp { text-align: center; font-weight: 900; background: #f8fafc; width: 38px; }
  .s5-ok { color: #15803d; font-weight: 900; }
  .s5-bad { color: #b91c1c; font-weight: 900; }

  .photo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 8px; }
  .photo-box { border: 0.75pt solid #334155; padding: 6px; text-align: center; }
  .photo-box .pcap { font-size: 8.3pt; font-weight: 800; margin-bottom: 5px; background: #f1f5f9; padding: 3px; }
  .photo-box img { max-width: 100%; max-height: 68mm; object-fit: contain; display: block; margin: 0 auto; }
  .photo-empty { height: 54mm; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 8.3pt; }

  .doc-footer {
    margin-top: auto;
    padding-top: 7px;
    border-top: 0.75pt solid #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }
  .doc-footer-logo { height: 6mm; width: auto; object-fit: contain; }
  .doc-footer-company { font-size: 10pt; font-weight: 800; letter-spacing: 2px; color: #111; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .sheet { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div class="toolbar-left">🖨️ <?= $e($docTitle) ?></div>
  <div class="toolbar-right">
    <button type="button" class="tbtn active" id="btnApproval" onclick="toggleApproval()">결재란 ON</button>
    <button type="button" class="tbtn active" id="mTop" onclick="setMargin('')">상단 편철 (기본 20/12mm)</button>
    <button type="button" class="tbtn" id="mLeft" onclick="setMargin('margin-left')">좌측 편철 (좌 20mm)</button>
    <button type="button" class="tbtn" id="mWide" onclick="setMargin('margin-wide')">여유 편철 (18/16mm)</button>
    <button type="button" class="tbtn print" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
    <a class="tbtn" href="workplace_inspection.php?year=<?= $year ?>">돌아가기</a>
  </div>
</div>

<?php if ($mode === '5s'): ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 3.1.1 작업장 관리 · 작업장 정리정돈 (Housekeeping / 5S)</small>
        <h1><?= $year ?>년 5S 점검표</h1>
        <div class="doc-meta-line">
          점검장소: <?= $e($s5Meta['location']) ?> &nbsp;|&nbsp;
          점검년도: <?= $year ?>년 &nbsp;|&nbsp;
          확인자: <?= $e($s5Meta['checker_name']) ?> &nbsp;|&nbsp;
          범례: ○ 양호 / × 불량
        </div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
      </div>
    </div>

    <table class="s5-print">
      <thead>
        <tr>
          <th style="width:38px;">구분</th>
          <th style="width:24px;">No</th>
          <th>평 가 내 용</th>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <th style="width:34px;"><?= $m ?>월</th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php $lastGrp = ''; foreach ($s5Questions as $q): $no = $q['no']; ?>
        <tr>
          <?php if ($q['group'] !== $lastGrp): $lastGrp = $q['group']; ?>
            <td rowspan="5" class="s5-grp"><?= $e($q['group']) ?></td>
          <?php endif; ?>
          <td class="ctr"><?= $q['sub_no'] ?></td>
          <td><?= $e($q['text']) ?></td>
          <?php for ($m = 1; $m <= 12; $m++):
            $v = $s5Matrix[$no][$m] ?? '';
            $cls = $v === '○' ? 's5-ok' : ($v === '×' ? 's5-bad' : '');
          ?>
            <td class="ctr <?= $cls ?>"><?= $e($v) ?></td>
          <?php endfor; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (trim((string)$s5Meta['note']) !== ''): ?>
      <div style="margin-top:6px; font-size:8.2pt; border:0.7pt solid #334155; padding:4px 7px;">
        <strong>비고:</strong> <?= $e($s5Meta['note']) ?>
      </div>
    <?php endif; ?>

    <div class="doc-footer">
      <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
      <span class="doc-footer-company">주식회사 현대기전</span>
    </div>
  </section>

<?php else: ?>
  <?php if (!$checksToPrint): ?>
    <section class="sheet">
      <div class="doc-head">
        <div class="doc-title-area">
          <small>CSM 3.1 작업장 관리 · 현장점검·작업환경 정기점검표</small>
          <h1><?= $e($docTitle) ?></h1>
        </div>
        <div class="approval-wrap">
          <div class="ap-side">결<br>재</div>
          <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
        </div>
      </div>
      <p style="padding:36px; text-align:center; color:#64748b;">등록된 <?= $year ?>년 현장점검 기록이 없습니다.</p>
      <div class="doc-footer">
        <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
        <span class="doc-footer-company">주식회사 현대기전</span>
      </div>
    </section>
  <?php else: ?>
    <?php foreach ($checksToPrint as $ck):
      $meta = $itemMeta[$ck['item_code']] ?? $itemMeta['3.1.1'];
    ?>
    <section class="sheet">
      <div class="doc-head">
        <div class="doc-title-area">
          <small>CSM <?= $e($meta['code']) ?> 작업장 관리 · <?= $e($meta['short']) ?></small>
          <h1>현장점검·작업환경 정기점검표 (<?= $e($meta['code']) ?>)</h1>
          <div class="doc-meta-line">
            점검일자: <?= $e($ck['check_date']) ?> &nbsp;|&nbsp;
            점검자: <?= $e($ck['inspector']) ?> &nbsp;|&nbsp;
            점검구역: <?= $e($ck['location']) ?>
          </div>
        </div>
        <div class="approval-wrap">
          <div class="ap-side">결<br>재</div>
          <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
        </div>
      </div>

      <div class="sec-title">1. <?= $e($meta['code']) ?> 점검 결과 — <?= $e($meta['title']) ?> (종합: <?= $e($ck['overall_result']) ?>)</div>
      <table class="data-tbl">
        <thead>
          <tr>
            <th style="width:34px;">No</th>
            <th>점검 항목</th>
            <th style="width:76px;">점검 결과</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($meta['checklist'] as $idx => $lbl): $n = $idx + 1; ?>
          <tr>
            <td class="ctr"><?= $n ?></td>
            <td><?= $e($lbl) ?></td>
            <td class="ctr"><strong><?= $e($ck['chk' . $n]) ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="sec-title">2. 지적사항 및 개선조치 내용</div>
      <table class="data-tbl">
        <tr>
          <th style="width:110px;">지적 / 미흡 사항</th>
          <td><?= $ck['issue_note'] !== '' ? nl2br($e($ck['issue_note'])) : '특이사항 없음 (양호)' ?></td>
        </tr>
        <tr>
          <th>개선 조치 내용</th>
          <td><?= $ck['action_note'] !== '' ? nl2br($e($ck['action_note'])) : '지속 유지관리' ?></td>
        </tr>
      </table>

      <div class="sec-title">3. 현장 확인 사진</div>
      <div class="photo-grid">
        <div class="photo-box">
          <div class="pcap">① <?= $e($meta['photo1_label']) ?></div>
          <?php if ($ck['photo1'] !== ''): ?>
            <img src="workplace_inspection.php?view_photo=<?= urlencode((string)$ck['photo1']) ?>" alt="현장사진1">
          <?php else: ?>
            <div class="photo-empty">첨부 사진 없음</div>
          <?php endif; ?>
        </div>
        <div class="photo-box">
          <div class="pcap">② <?= $e($meta['photo2_label']) ?></div>
          <?php if ($ck['photo2'] !== ''): ?>
            <img src="workplace_inspection.php?view_photo=<?= urlencode((string)$ck['photo2']) ?>" alt="현장사진2">
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
<?php endif; ?>

<script>
function setMargin(cls) {
  document.body.classList.remove('margin-left', 'margin-wide');
  if (cls) document.body.classList.add(cls);
  document.getElementById('mTop')?.classList.toggle('active', cls === '');
  document.getElementById('mLeft')?.classList.toggle('active', cls === 'margin-left');
  document.getElementById('mWide')?.classList.toggle('active', cls === 'margin-wide');
}
function toggleApproval() {
  const hidden = document.body.classList.toggle('hide-approval');
  const btn = document.getElementById('btnApproval');
  if (btn) {
    btn.classList.toggle('active', !hidden);
    btn.textContent = hidden ? '결재란 OFF' : '결재란 ON';
  }
}
</script>
</body>
</html>
