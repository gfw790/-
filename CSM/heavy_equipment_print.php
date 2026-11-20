<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/heavy_equipment_store.php';

csm_require_auth();
header('Cache-Control: no-store');

$year = he_year($_GET['year'] ?? null);
$titles = [
    'drivers' => ['2.7.1', '보유차량·운전자 면허·규정위반 이력 대장'],
    'inspect' => ['2.7.2', '차량·중장비 점검 및 법정검사·정비일지'],
    'guards' => ['2.7.3', '중장비 기능·방호장치 현장확인 점검표'],
];
$mode = is_string($_GET['mode'] ?? null) && isset($titles[$_GET['mode']]) ? $_GET['mode'] : 'drivers';
[$code, $title] = $titles[$mode];
$landscape = $mode !== 'guards';
$db = he_db();
$sheets = [[]];
if ($mode === 'guards') {
    $checkId = filter_var($_GET['check_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $sheets = $checkId
        ? he_rows($db, 'SELECT * FROM he_guard_checks WHERE year = ? AND id = ?', [$year, $checkId])
        : he_rows($db, 'SELECT * FROM he_guard_checks WHERE year = ? ORDER BY check_date DESC, id DESC', [$year]);
    if ($checkId && !$sheets) {
        http_response_code(404);
        exit('해당 연도의 점검 기록을 찾을 수 없습니다.');
    }
    $sheets = $sheets ?: [[]];
}

$logo = is_file(__DIR__ . '/assets/policy-logo.png') ? 'assets/policy-logo.png' : '../safety_manual/assets/policy-logo.png';
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$table = static function (array $columns, array $rows) use ($e): void {
    ?>
    <table class="data-tbl">
      <thead><tr><?php foreach ($columns as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td class="center" colspan="<?= count($columns) ?>">등록된 기록이 없습니다.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr><?php foreach ($columns as $field => $label): ?><td><?= $e($row[$field] ?? '') ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $year ?>년 <?= $e($title) ?> · 주식회사 현대기전</title>
<style>
  @page { size: A4 <?= $landscape ? 'landscape' : 'portrait' ?>; margin: 0; }
  * { box-sizing:border-box; }
  body { margin:0; background:#e5e9ec; color:#111; font:9.5pt/1.4 "Malgun Gothic","Apple SD Gothic Neo",sans-serif; }
  .toolbar { position:sticky; top:0; z-index:1; display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:10px 18px; background:#103b35; color:white; }
  .toolbar strong { margin-right:auto; }
  .toolbar button,.toolbar a { border:1px solid #80a7a0; border-radius:6px; padding:5px 10px; background:#164d45; color:white; font:inherit; text-decoration:none; cursor:pointer; }
  .toolbar button[aria-pressed="true"] { background:white; color:#103b35; }
  .sheet { width:<?= $landscape ? '297mm' : '210mm' ?>; min-height:<?= $landscape ? '210mm' : '297mm' ?>; margin:14px auto; background:white; padding:20mm 12mm 12mm 12mm; box-shadow:0 4px 20px #0003; }
  .sheet + .sheet { break-before:page; }
  body[data-margin="left"] .sheet { padding:20mm 12mm 12mm 20mm; }
  body[data-margin="wide"] .sheet { padding:18mm 16mm 16mm 16mm; }
  body.hide-approval .approval-wrap { display:none; }
  .doc-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; border-bottom:2pt solid #111; padding-bottom:3mm; margin-bottom:3mm; break-inside:avoid; }
  .doc-head small { font-weight:700; }
  h1 { margin:1mm 0; font-size:16pt; line-height:1.2; }
  h2 { border-left:3pt solid #28544d; padding-left:2mm; margin:4mm 0 2mm; font-size:11pt; break-after:avoid; }
  .approval-wrap { display:flex; flex-shrink:0; border:1pt solid #000; }
  .ap-side { display:flex; align-items:center; justify-content:center; width:6mm; border-right:1pt solid #000; font-weight:800; }
  .ap-tbl { border-collapse:collapse; table-layout:fixed; width:32mm; }
  .ap-tbl th,.ap-tbl td { border:1pt solid #000; width:50%; text-align:center; }
  .ap-tbl th { border-top:0; background:#eef3f2; }
  .ap-tbl td { height:11mm; border-bottom:0; }
  .ap-tbl :is(th,td):first-child { border-left:0; }
  .ap-tbl :is(th,td):last-child { border-right:0; }
  .data-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:8.5pt; }
  .data-tbl th,.data-tbl td { border:.75pt solid #334155; padding:1.3mm; overflow-wrap:anywhere; vertical-align:top; }
  .data-tbl th { background:#edf3f1; }
  .data-tbl td { white-space:pre-wrap; }
  .data-tbl thead { display:table-header-group; }
  .data-tbl tr { break-inside:avoid; }
  .center { text-align:center; }
  .guard-meta th,.guard-notes th { width:26mm; }
  .guard-notes td { min-height:8mm; }
  .photo-row { display:flex; gap:3mm; break-inside:avoid; }
  figure { flex:1 1 0; min-width:0; margin:0; border:.75pt solid #334155; }
  figcaption { background:#edf3f1; border-bottom:.75pt solid #334155; text-align:center; padding:1.5mm; font-weight:700; }
  figure img,.photo-empty { display:block; width:100%; height:57mm; object-fit:contain; }
  .photo-empty { display:flex; align-items:center; justify-content:center; }
  .doc-footer { display:flex; justify-content:center; align-items:center; gap:3mm; margin-top:5mm; padding-top:2mm; border-top:1pt solid #334155; break-inside:avoid; }
  .doc-footer img { height:6mm; width:auto; }
  .doc-footer span { font-size:10pt; font-weight:800; letter-spacing:2px; }
  @media print {
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    body { background:white; }
    .toolbar,button,nav { display:none !important; }
    .sheet { margin:0; box-shadow:none; }
  }
</style>
</head>
<body data-margin="top">
<div class="toolbar">
  <strong><?= $e($code . ' ' . $title) ?></strong>
  <button type="button" id="approval-toggle" aria-pressed="true">결재란 ON</button>
  <button type="button" data-preset="top" aria-pressed="true">상단 편철 (20/12mm)</button>
  <button type="button" data-preset="left" aria-pressed="false">좌측 편철 (좌 20mm)</button>
  <button type="button" data-preset="wide" aria-pressed="false">여유 편철 (18/16mm)</button>
  <button type="button" id="print-page">인쇄 / PDF 저장</button>
  <a href="heavy_equipment.php?year=<?= $year ?>&amp;tab=<?= $e($mode) ?>">돌아가기</a>
</div>

<?php foreach ($sheets as $check): ?>
  <section class="sheet">
    <header class="doc-head">
      <div>
        <small>CSM <?= $e($code) ?> · 운송장비 및 중장비</small>
        <h1><?= $year ?>년 <?= $e($title) ?></h1>
        <div>담당부서 공사팀 · 안전관리책임자 김남균</div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl" aria-label="결재란">
          <tr><th scope="col">작성</th><th scope="col">승인</th></tr>
          <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
        </table>
      </div>
    </header>

    <?php if ($mode === 'drivers'): ?>
      <h2>차량·중장비 보유현황</h2>
      <?php
      $vehicles = he_rows($db, 'SELECT *, CASE active WHEN 1 THEN \'활성\' ELSE \'비활성\' END AS active_label FROM he_vehicles ORDER BY active DESC, sort_order, id');
      $table([
          'vehicle_type' => '차종', 'vehicle_name' => '차량·장비명', 'plate_no' => '등록번호', 'reg_date' => '등록일',
          'driver_name' => '담당 운전자', 'legal_inspect_due' => '법정검사 예정일', 'insurance_info' => '보험 정보',
          'status' => '운용 상태', 'active_label' => '보유 목록', 'note' => '비고',
      ], $vehicles);
      ?>
      <h2>운전자 자격·면허 및 규정위반 이력</h2>
      <?php
      $table([
          'worker_name' => '성명', 'team' => '소속', 'license_type' => '자격·면허 종류', 'license_no' => '면허번호',
          'acquire_date' => '취득일', 'renewal_date' => '갱신일·기한', 'assigned_vehicle' => '배정 차량·장비',
          'violation_count' => '위반 건수', 'violation_history' => '위반일·내용·조치', 'status' => '자격 상태', 'note' => '비고',
      ], he_rows($db, 'SELECT * FROM he_drivers ORDER BY id'));
      ?>
    <?php elseif ($mode === 'inspect'): ?>
      <?php
      $records = he_rows($db, 'SELECT * FROM he_inspections WHERE year = ? ORDER BY check_date DESC, id DESC', [$year]);
      foreach ($records as &$record) {
          $record['check_details'] = '';
          foreach (he_check_labels('inspect') as $field => $label) {
              $record['check_details'] .= ($record['check_details'] === '' ? '' : "\n") . $label . ' · ' . $record[$field];
          }
      }
      unset($record);
      $table([
          'check_date' => '점검일', 'vehicle_label' => '차량·중장비', 'check_type' => '점검 구분', 'inspector' => '점검자',
          'mileage' => '주행거리 (km)', 'check_details' => '항목별 결과', 'overall_result' => '종합 결과',
          'detail_note' => '검사·정비 내용 및 조치', 'shop_name' => '검사·정비 업체', 'next_due_date' => '차기 예정일',
      ], $records);
      ?>
    <?php else: ?>
      <table class="data-tbl guard-meta">
        <tr><th scope="row">차량·중장비</th><td><?= $e($check['vehicle_label'] ?? '') ?></td><th scope="row">점검일</th><td><?= $e($check['check_date'] ?? '') ?></td></tr>
        <tr><th scope="row">점검자</th><td><?= $e($check['inspector'] ?? '') ?></td><th scope="row">종합 결과</th><td><?= $e($check['overall_result'] ?? '') ?></td></tr>
      </table>
      <?php if (!$check): ?><p>등록된 기록이 없어 빈 점검표를 표시합니다.</p><?php endif; ?>
      <h2>점검 항목 및 결과</h2>
      <table class="data-tbl">
        <thead><tr><th scope="col">점검 항목</th><th scope="col" style="width:35mm">점검 결과</th></tr></thead>
        <tbody>
          <?php foreach (he_check_labels('guards') as $field => $label): ?>
            <tr><th scope="row"><?= $e($label) ?></th><td class="center"><?= $e($check[$field] ?? '') ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <h2>지적사항 및 개선조치</h2>
      <table class="data-tbl guard-notes">
        <tr><th scope="row">지적사항</th><td><?= $e($check['issue_note'] ?? '') ?></td></tr>
        <tr><th scope="row">개선조치</th><td><?= $e($check['action_note'] ?? '') ?></td></tr>
      </table>
      <h2>현장 확인 사진</h2>
      <div class="photo-row">
        <?php foreach (['photo1' => '현장 사진 1', 'photo2' => '현장 사진 2'] as $slot => $label): ?>
          <?php $image = $check[$slot] ?? ''; ?>
          <figure>
            <figcaption><?= $e($label) ?></figcaption>
            <?php if (he_stored_path($image, true) !== null): ?>
              <img src="<?= $e(he_photo_url($image)) ?>" alt="<?= $e($label) ?>">
            <?php else: ?>
              <div class="photo-empty">첨부 사진 없음</div>
            <?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <footer class="doc-footer">
      <img src="<?= $e($logo) ?>" alt="">
      <span>주식회사 현대기전</span>
    </footer>
  </section>
<?php endforeach; ?>
<script>
const approval = document.getElementById('approval-toggle');
approval.addEventListener('click', () => {
  const shown = !document.body.classList.toggle('hide-approval');
  approval.setAttribute('aria-pressed', String(shown));
  approval.textContent = shown ? '결재란 ON' : '결재란 OFF';
});
const presets = document.querySelectorAll('[data-preset]');
presets.forEach(preset => preset.addEventListener('click', () => {
  document.body.dataset.margin = preset.dataset.preset;
  presets.forEach(button => button.setAttribute('aria-pressed', String(button === preset)));
}));
document.getElementById('print-page').addEventListener('click', () => window.print());
</script>
</body>
</html>
