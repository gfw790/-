<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hazardous_machinery_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$db = hm_db();
$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT) ?: (int)date('Y');
$mode = (string)($_GET['mode'] ?? 'register');
if (!in_array($mode, ['register', 'checklist', 'all_checklists'], true)) {
    $mode = 'register';
}
$eqId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

$logoPath = __DIR__ . '/assets/policy-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = dirname(__DIR__) . '/safety_manual/assets/policy-logo.png';
}
$logoBase64 = file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath))) : '';

$allEquipment = hm_fetch_all_equipment($db);
$isLandscape = ($mode === 'register');

$targetChecklists = [];
if ($mode === 'checklist') {
    foreach ($allEquipment as $eq) {
        if ((int)$eq['id'] === $eqId) {
            $eq['items'] = hm_fetch_check_items($db, (int)$eq['id']);
            $targetChecklists[] = $eq;
            break;
        }
    }
} elseif ($mode === 'all_checklists') {
    foreach ($allEquipment as $eq) {
        $eq['items'] = hm_fetch_check_items($db, (int)$eq['id']);
        $targetChecklists[] = $eq;
    }
}

$registerPages = $mode === 'register' ? array_chunk($allEquipment, 10) : [];
$totalPages = $mode === 'register' ? max(1, count($registerPages)) : max(1, count($targetChecklists));
$pageTitle = $mode === 'register'
    ? "위험기계·기구 관리대장 ({$year}년)"
    : ($mode === 'checklist' && !empty($targetChecklists)
        ? $targetChecklists[0]['code'] . ' ' . $targetChecklists[0]['name'] . ' 안전점검표'
        : "위험기계·기구 안전점검표 전체 ({$year}년, " . count($targetChecklists) . "종)");
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $h($pageTitle) ?> · CSM 출력</title>
<style>
:root {
  --top-pad: 20mm;
  --right-pad: 12mm;
  --bottom-pad: 12mm;
  --left-pad: 12mm;
}
@page { size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: #f1f5f9;
  font-family: "Malgun Gothic", "맑은 고딕", Dotum, sans-serif;
  color: #000;
  font-size: 9pt;
  line-height: 1.4;
  -webkit-print-color-adjust: exact !important;
  print-color-adjust: exact !important;
}
.screen-toolbar {
  position: sticky; top: 0; z-index: 100;
  background: #fff; border-bottom: 1px solid #cbd5e1;
  padding: 10px 20px; display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,.07);
}
.toolbar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.toolbar-btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
  font-size: 13px; font-weight: 700; border-radius: 8px; cursor: pointer;
  border: 1px solid transparent; text-decoration: none;
}
.btn-print { background: #1e3a8a; color: #fff; }
.btn-close { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
.pad-control-group { display: inline-flex; align-items: center; gap: 5px; padding-left: 12px; border-left: 1px solid #cbd5e1; flex-wrap: wrap; }
.pad-label { font-size: 12px; font-weight: 700; color: #334155; }
.pad-btn { padding: 5px 10px; font-size: 11.5px; font-weight: 700; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; }
.pad-btn.active { background: #1e3a8a; color: #fff; border-color: #1e3a8a; }
.page-badge { background: #dcfce7; color: #166534; font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; border: 1px solid #86efac; }
.toolbar-tip { font-size: 12px; color: #475569; background: #f8fafc; padding: 6px 12px; border-radius: 8px; border: 1px solid #e2e8f0; }

.paper-container {
  max-width: <?= $isLandscape ? '297mm' : '210mm' ?>;
  margin: 16px auto 32px;
  display: flex; flex-direction: column; gap: 20px;
}
.a4-page {
  width: <?= $isLandscape ? '297mm' : '210mm' ?>;
  height: <?= $isLandscape ? '210mm' : '297mm' ?>;
  min-height: <?= $isLandscape ? '210mm' : '297mm' ?>;
  max-height: <?= $isLandscape ? '210mm' : '297mm' ?>;
  background: #fff;
  padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad);
  box-shadow: 0 10px 25px -5px rgba(0,0,0,.12);
  display: flex; flex-direction: column; overflow: hidden;
}
.page-body { flex: 1 1 auto; display: flex; flex-direction: column; overflow: hidden; }

.rpt-header {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
  border-bottom: 2px solid #000; padding-bottom: 7px; margin-bottom: 8px;
}
.rpt-kicker { font-size: 8pt; color: #334155; font-weight: 700; margin-bottom: 2px; }
.rpt-title { font-size: 15pt; font-weight: 800; letter-spacing: -.3px; margin-bottom: 2px; }
.rpt-sub { font-size: 8.2pt; color: #334155; }

/* 2단 동일폭 결재란 (인쇄 미리보기 100% 선명한 1pt solid) */
.approval-wrap { display: flex; align-items: stretch; width: 52mm; flex-shrink: 0; font-size: 8pt; text-align: center; }
.ap-side { width: 8mm; border: 1pt solid #000; border-right: 0; background: #f1f5f9; font-weight: 700; display: flex; align-items: center; justify-content: center; line-height: 1.3; }
.ap-tbl { width: 44mm; border-collapse: collapse; table-layout: fixed; }
.ap-tbl th, .ap-tbl td { width: 50%; border: 1pt solid #000; padding: 2.5px 2px; text-align: center; }
.ap-tbl th { background: #f1f5f9; font-weight: 700; }
.ap-tbl td.ap-sign { height: 13mm; background: #fff; }

/* 관리대장 표 */
table.reg-tbl { width: 100%; border-collapse: collapse; font-size: 7.6pt; table-layout: fixed; }
table.reg-tbl th, table.reg-tbl td { border: 0.75pt solid #1e293b; padding: 3px 4px; text-align: center; vertical-align: middle; word-break: keep-all; }
table.reg-tbl th { background: #f1f5f9; font-weight: 700; }
table.reg-tbl td.left { text-align: left; }
.reg-photo { width: 17mm; height: 11.5mm; object-fit: cover; display: block; margin: 0 auto; border: 0.5pt solid #94a3b8; }

/* 개별 점검표 */
table.eq-info { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 8px; }
table.eq-info th, table.eq-info td { border: 0.75pt solid #1e293b; padding: 4.5px 7px; }
table.eq-info th { background: #f1f5f9; font-weight: 700; text-align: center; width: 14%; }
.chk-photo-cell { width: 28mm; text-align: center; padding: 3px !important; }
.chk-photo-cell img { max-width: 26mm; max-height: 19mm; object-fit: contain; display: block; margin: 0 auto; }

table.chk-tbl { width: 100%; border-collapse: collapse; font-size: 8.4pt; }
table.chk-tbl th, table.chk-tbl td { border: 0.75pt solid #1e293b; padding: 4px 6px; vertical-align: middle; }
table.chk-tbl th { background: #f1f5f9; font-weight: 700; text-align: center; }
table.chk-tbl td.c { text-align: center; white-space: nowrap; }
.sign-row-tbl { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 8px; }
.sign-row-tbl th, .sign-row-tbl td { border: 0.75pt solid #1e293b; padding: 5px 8px; }
.sign-row-tbl th { background: #f8fafc; width: 16%; text-align: center; font-weight: 700; }
.action-box { border: 0.75pt solid #1e293b; margin-top: 8px; flex: 1 1 auto; display: flex; flex-direction: column; min-height: 22mm; }
.action-box-head { background: #f1f5f9; border-bottom: 0.75pt solid #1e293b; padding: 4px 8px; font-weight: 700; font-size: 8.4pt; }
.action-box-body { padding: 6px 8px; white-space: pre-wrap; font-size: 8.4pt; flex: 1; }

/* CSM 표준 하단 푸터 (회사로고 + 주식회사 현대기전) */
.rpt-footer {
  flex: 0 0 auto; margin-top: auto; padding-top: 5px;
  border-top: 1px solid #cbd5e1;
  display: flex; justify-content: space-between; align-items: center; gap: 8px;
  font-size: 8pt; color: #334155;
}
.rpt-brand { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
.doc-footer-logo { height: 6mm; width: auto; object-fit: contain; }
.doc-footer-company { font-size: 10pt; font-weight: 800; letter-spacing: 2px; color: #1e293b; }
.rpt-pageno { font-weight: 700; font-size: 8.4pt; color: #1e293b; }

@media print {
  body { background: #fff !important; }
  .screen-toolbar { display: none !important; }
  .paper-container { max-width: none; margin: 0; gap: 0; }
  .a4-page { box-shadow: none; margin: 0; break-after: page; page-break-after: always; }
  .a4-page:last-child { break-after: auto; page-break-after: auto; }
}
</style>
</head>
<body>
<aside class="screen-toolbar">
  <div class="toolbar-left">
    <button type="button" class="toolbar-btn btn-print" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
    <a class="toolbar-btn btn-close" href="hazardous_machinery.php?year=<?= $year ?>">← 돌아가기</a>
    <span class="page-badge">총 <?= $totalPages ?>P (<?= $isLandscape ? 'A4 가로' : 'A4 세로' ?>)</span>
    <div class="pad-control-group">
      <span class="pad-label">여백 규정:</span>
      <button type="button" class="pad-btn active" onclick="setPadPreset('20mm','12mm','12mm','12mm',this)">상단 편철 (상 20 / 좌·우·하 12mm)</button>
      <button type="button" class="pad-btn" onclick="setPadPreset('12mm','12mm','12mm','20mm',this)">좌측 편철 (좌 20 / 상·우·하 12mm)</button>
      <button type="button" class="pad-btn" onclick="setPadPreset('25mm','12mm','12mm','12mm',this)">여유 편철 (상 25 / 좌·우·하 12mm)</button>
    </div>
  </div>
  <div class="toolbar-tip">
    💡 <strong>CSM A4 출력원칙 적용</strong> &bull; 인쇄 설정에서 <strong>[배경 그래픽]</strong> 켜기 / <strong>[여백: 없음]</strong> 권장
  </div>
</aside>

<div class="paper-container">
<?php if ($mode === 'register'): ?>
  <?php foreach ($registerPages as $pIdx => $chunk): ?>
  <section class="a4-page">
    <div class="rpt-header">
      <div>
        <div class="rpt-kicker">(주)현대기전 안전보건경영체계 · CSM 2.6.3 위험기계·기구 안전검사 및 점검 관리</div>
        <h1 class="rpt-title">위험기계ㆍ기구 관리대장 (<?= $year ?>년)</h1>
        <div class="rpt-sub">관련근거: 산업안전보건기준에 관한 규칙 제35조 제2항 별표3 &nbsp;|&nbsp; 총 보유 현황: <?= count($allEquipment) ?>종</div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl">
          <tr><th>작성</th><th>승인</th></tr>
          <tr><td class="ap-sign">&nbsp;</td><td class="ap-sign">&nbsp;</td></tr>
        </table>
      </div>
    </div>

    <div class="page-body">
      <table class="reg-tbl">
        <thead>
          <tr>
            <th rowspan="2" style="width:4%">순번</th>
            <th rowspan="2" style="width:8%">관리번호</th>
            <th rowspan="2" style="width:12%">기계기구 설비명</th>
            <th rowspan="2" style="width:7%">규격</th>
            <th rowspan="2" style="width:4%">수량</th>
            <th rowspan="2" style="width:9%">검사대상/유효기간</th>
            <th rowspan="2" style="width:9%">방호장치</th>
            <th rowspan="2" style="width:5%">주기</th>
            <th rowspan="2" style="width:10%">발생가능 재해형태</th>
            <th colspan="4" style="width:20%">점 검 결 과 (<?= $year ?>년)</th>
            <th rowspan="2" style="width:8%">이미지</th>
            <th rowspan="2" style="width:4%">비고</th>
          </tr>
          <tr>
            <th style="width:5%">1차 결과</th>
            <th style="width:5%">스티커</th>
            <th style="width:5%">2차 결과</th>
            <th style="width:5%">스티커</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($chunk as $eq): ?>
          <tr>
            <td><?= (int)$eq['seq_no'] ?></td>
            <td><strong><?= $h($eq['code']) ?></strong></td>
            <td class="left"><strong><?= $h($eq['name']) ?></strong></td>
            <td><?= $h($eq['spec'] ?: '-') ?></td>
            <td><?= (int)$eq['qty'] ?></td>
            <td><?= $h($eq['inspection_target']) ?><?= $eq['cert_valid_until'] ? '<br>~' . $h($eq['cert_valid_until']) : '' ?></td>
            <td class="left"><?= $h($eq['guard_device'] ?: '-') ?></td>
            <td><?= $h($eq['check_cycle']) ?></td>
            <td class="left"><?= $h($eq['hazard_type'] ?: '-') ?></td>
            <td><?= $h($eq['check1_result'] ?: '-') ?></td>
            <td><?= $h($eq['check1_sticker']) ?></td>
            <td><?= $h($eq['check2_result'] ?: '-') ?></td>
            <td><?= $h($eq['check2_sticker']) ?></td>
            <td>
              <?php if ($eq['photo_file'] !== ''): ?>
                <img class="reg-photo" src="hazardous_machinery.php?photo=<?= $h($eq['photo_file']) ?>" alt="">
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td><?= $h($eq['remarks'] ?: '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <footer class="rpt-footer">
      <div>※ 결재(서명) 후 PDF 스캔하여 CSM 2.6.3 증빙으로 첨부·보관합니다.</div>
      <div class="rpt-pageno"><?= $pIdx + 1 ?> / <?= $totalPages ?></div>
      <div class="rpt-brand"><?php if ($logoBase64): ?><img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo"><?php endif; ?><span class="doc-footer-company">주식회사 현대기전</span></div>
    </footer>
  </section>
  <?php endforeach; ?>

<?php else: ?>
  <?php foreach ($targetChecklists as $pIdx => $eq):
    $items = $eq['items'] ?? [];
    $hasCat = false;
    foreach ($items as $it) { if ($it['category'] !== '') { $hasCat = true; break; } }
  ?>
  <section class="a4-page">
    <div class="rpt-header">
      <div>
        <div class="rpt-kicker">(주)현대기전 안전보건경영체계 · CSM 2.6.3 위험기계·기구 안전점검표</div>
        <h1 class="rpt-title"><?= $h($eq['name']) ?> 안전점검표 (<?= $year ?>년)</h1>
        <div class="rpt-sub"><?= $h($eq['legal_basis']) ?></div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl">
          <tr><th>작성</th><th>승인</th></tr>
          <tr><td class="ap-sign">&nbsp;</td><td class="ap-sign">&nbsp;</td></tr>
        </table>
      </div>
    </div>

    <div class="page-body">
      <table class="eq-info">
        <tr>
          <th>관리번호</th>
          <td><strong><?= $h($eq['code']) ?></strong></td>
          <th>설비명 / 규격</th>
          <td><?= $h($eq['name']) ?> <?= $eq['spec'] ? '(' . $h($eq['spec']) . ')' : '' ?></td>
          <td rowspan="2" class="chk-photo-cell">
            <?php if ($eq['photo_file'] !== ''): ?>
              <img src="hazardous_machinery.php?photo=<?= $h($eq['photo_file']) ?>" alt="">
            <?php else: ?>
              사진 미등록
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th>방호장치</th>
          <td><?= $h($eq['guard_device'] ?: '-') ?></td>
          <th>발생가능 재해</th>
          <td><?= $h($eq['hazard_type'] ?: '-') ?> (점검주기: <?= $h($eq['check_cycle']) ?>)</td>
        </tr>
      </table>

      <table class="chk-tbl">
        <thead>
          <tr>
            <th style="width:6%">순번</th>
            <?php if ($hasCat): ?><th style="width:15%">점검항목</th><?php endif; ?>
            <th><?= $h($eq['name']) ?> 점검내용</th>
            <th style="width:7%">구분</th>
            <th colspan="2" style="width:22%">점 검 결 과</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
          <tr>
            <td class="c" rowspan="2"><?= (int)$it['item_no'] ?></td>
            <?php if ($hasCat): ?><td class="c" rowspan="2"><strong><?= $h($it['category']) ?></strong></td><?php endif; ?>
            <td rowspan="2"><?= $h($it['content']) ?></td>
            <td class="c">1차</td>
            <td class="c"><?= $it['round1_result'] === '적정' ? '☑ 적정' : '□ 적정' ?></td>
            <td class="c"><?= $it['round1_result'] === '부적정' ? '☑ 부적정' : '□ 부적정' ?></td>
          </tr>
          <tr>
            <td class="c">2차</td>
            <td class="c"><?= $it['round2_result'] === '적정' ? '☑ 적정' : '□ 적정' ?></td>
            <td class="c"><?= $it['round2_result'] === '부적정' ? '☑ 부적정' : '□ 부적정' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <table class="sign-row-tbl">
        <tr>
          <th>1차 점검일자</th>
          <td><?= $h($eq['check1_date'] ?: '2026년   월   일') ?></td>
          <th>1차 점검자</th>
          <td><?= $h($eq['check1_inspector'] ?: '') ?> &nbsp;&nbsp;&nbsp;&nbsp;(서명)</td>
          <th>스티커</th>
          <td style="text-align:center"><?= $h($eq['check1_sticker']) ?></td>
        </tr>
        <tr>
          <th>2차 점검일자</th>
          <td><?= $h($eq['check2_date'] ?: '2026년   월   일') ?></td>
          <th>2차 점검자</th>
          <td><?= $h($eq['check2_inspector'] ?: '') ?> &nbsp;&nbsp;&nbsp;&nbsp;(서명)</td>
          <th>스티커</th>
          <td style="text-align:center"><?= $h($eq['check2_sticker']) ?></td>
        </tr>
      </table>

      <div class="action-box">
        <div class="action-box-head">점검결과 주요 조치 및 요구사항</div>
        <div class="action-box-body"><?= $h($eq['action_note']) ?></div>
      </div>
    </div>

    <footer class="rpt-footer">
      <div>※ 관리번호: <?= $h($eq['code']) ?> · <?= $h($eq['name']) ?> 안전점검표</div>
      <div class="rpt-pageno"><?= $pIdx + 1 ?> / <?= $totalPages ?></div>
      <div class="rpt-brand"><?php if ($logoBase64): ?><img src="<?= $logoBase64 ?>" alt="현대기전 로고" class="doc-footer-logo"><?php endif; ?><span class="doc-footer-company">주식회사 현대기전</span></div>
    </footer>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<script>
function setPadPreset(top, right, bottom, left, btn) {
  const root = document.documentElement.style;
  root.setProperty('--top-pad', top);
  root.setProperty('--right-pad', right);
  root.setProperty('--bottom-pad', bottom);
  root.setProperty('--left-pad', left);
  document.querySelectorAll('.pad-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
}
</script>
</body>
</html>
