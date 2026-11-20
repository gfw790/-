<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/emergency_response_store.php';

csm_require_auth();
header('Cache-Control: no-store');

$year = er_year($_GET['year'] ?? null);
$items = er_items();
$mode = er_input($_GET, 'mode');
if (!isset($items[$mode])) {
    $mode = 'scenarios';
}
[$code, $title] = $items[$mode];
$title = match ($mode) {
    'contacts' => '비상연락망',
    'reports' => '대표자 보고·종사자 공유 이력서',
    default => $title,
};
$rows = er_list_records(er_db(), $mode, $year);
$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id !== false && $mode !== 'contacts') {
    $rows = array_filter($rows, static fn(array $row): bool => (int)$row['id'] === $id);
    if (!$rows) {
        http_response_code(404);
    }
}
$fields = er_record_fields($mode);
$photoFields = er_photo_fields($mode);
$sheets = $mode === 'contacts' ? array_chunk($rows, 12) : array_map(static fn(array $row): array => [$row], $rows);
$sheets = $sheets ?: [[]];
$logo = is_file(__DIR__ . '/assets/policy-logo.png') ? 'assets/policy-logo.png' : '../safety_manual/assets/policy-logo.png';
$e = 'er_escape';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $e($title) ?> · 주식회사 현대기전</title>
<style>
    @page { size: A4 portrait; margin: 0; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; background: #e5e9ec; color: #111; font: 10pt/1.5 "Malgun Gothic", "Apple SD Gothic Neo", sans-serif; }
    .toolbar { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 16px; background: #103b35; }
    .toolbar button, .toolbar a { padding: 6px 10px; border: 1px solid #91b9ac; border-radius: 5px; color: #fff; background: transparent; font: inherit; cursor: pointer; text-decoration: none; }
    .toolbar [aria-pressed="true"] { color: #103b35; background: #fff; }
    .sheet {
        width: 210mm;
        min-height: 297mm;
        margin: 14px auto;
        padding: 20mm 12mm 12mm 12mm;
        background: #fff;
        box-shadow: 0 6px 24px rgba(0,0,0,.14);
        display: flex;
        flex-direction: column;
        break-after: page;
    }
    .sheet:last-child { break-after: auto; }
    body[data-margin="left"] .sheet { padding: 20mm 12mm 12mm 20mm; }
    body[data-margin="wide"] .sheet { padding: 18mm 16mm 16mm 16mm; }
    .hide-approval .approval-wrap { display: none; }
    .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-bottom: 1.5pt solid #111; padding-bottom: 3mm; margin-bottom: 4mm; break-inside: avoid; }
    .doc-head h1 { margin: 1mm 0; line-height: 1.35; font-size: 16pt; }
    .doc-head p { margin: 0; font-size: 9pt; }
    .approval-wrap { display: flex; flex-shrink: 0; border: 1pt solid #000; }
    .ap-side { display: flex; align-items: center; justify-content: center; width: 6mm; border-right: 1pt solid #000; text-align: center; font-weight: 800; }
    .ap-tbl { width: 34mm; table-layout: fixed; border-collapse: collapse; }
    .ap-tbl th, .ap-tbl td { width: 50%; border: 1pt solid #000; text-align: center; padding: 1mm; }
    .ap-tbl th { border-top: 0; font-size: 9pt; }
    .ap-tbl td { border-bottom: 0; height: 11mm; }
    .ap-tbl tr > :first-child { border-left: 0; }
    .ap-tbl tr > :last-child { border-right: 0; }
    .data-tbl { table-layout: fixed; width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
    .data-tbl th, .data-tbl td { padding: 2mm; border: .75pt solid #334155; vertical-align: top; overflow-wrap: anywhere; }
    .data-tbl th { background: #eef3f2; }
    .data-tbl th[scope="row"] { width: 35mm; text-align: left; }
    .data-tbl td { white-space: pre-wrap; }
    .contacts { font-size: 8.5pt; }
    .contacts tr { break-inside: avoid; }
    .contacts th { text-align: left; }
    .photos { display: flex; gap: 3mm; margin-bottom: 4mm; break-inside: avoid; }
    .photos figure { flex: 1; min-width: 0; margin: 0; padding: 2mm; border: .75pt solid #334155; text-align: center; }
    .photos figcaption { background: #eef3f2; padding: 1mm; margin-bottom: 2mm; font-weight: 700; }
    .photos img { display: block; width: 100%; height: 55mm; object-fit: contain; }
    .photo-empty { display: flex; justify-content: center; align-items: center; height: 55mm; color: #475569; }
    .empty { padding: 6mm; border: .75pt solid #334155; }
    .doc-footer { margin-top: auto; padding-top: 3mm; border-top: .75pt solid #cbd5e1; display: flex; align-items: center; justify-content: center; gap: 2mm; break-inside: avoid; }
    .doc-footer img { height: 6mm; width: auto; }
    .doc-footer span { font-size: 10pt; font-weight: 800; letter-spacing: 2px; }
    @media print {
        body { background: #fff; }
        .toolbar, button, nav { display: none !important; }
        .sheet { margin: 0; box-shadow: none; }
    }
</style>
</head>
<body data-margin="top">
<nav class="toolbar" aria-label="인쇄 설정">
    <button type="button" id="approval-toggle" aria-pressed="true">결재란 ON</button>
    <button type="button" data-preset="top" aria-pressed="true">상단 편철 (20/12 mm)</button>
    <button type="button" data-preset="left" aria-pressed="false">좌측 편철 (좌 20 mm)</button>
    <button type="button" data-preset="wide" aria-pressed="false">여유 편철 (18/16 mm)</button>
    <button type="button" id="print">인쇄 / PDF 저장</button>
    <a href="emergency_response.php?year=<?= $year ?>&amp;tab=<?= $e($mode) ?>">돌아가기</a>
</nav>
<?php foreach ($sheets as $sheet): ?>
<section class="sheet">
    <header class="doc-head">
        <div>
            <p>CSM <?= $e($code) ?> 비상대응</p>
            <h1><?= $e($title) ?></h1>
            <p><?= in_array($mode, ['drills', 'reports'], true) ? $year . '년 · ' : '' ?>담당부서 공사팀 · 안전관리책임자 김남균</p>
        </div>
        <div class="approval-wrap">
            <div class="ap-side">결<br>재</div>
            <table class="ap-tbl" aria-label="결재란">
                <tr><th scope="col">작성</th><th scope="col">승인</th></tr>
                <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
            </table>
        </div>
    </header>
    <?php if (!$sheet): ?>
        <p class="empty">인쇄할 기록이 없습니다.</p>
    <?php elseif ($mode === 'contacts'): ?>
        <table class="data-tbl contacts">
            <colgroup><col style="width:18%"><col style="width:16%"><col style="width:19%"><col style="width:15%"><col style="width:20%"><col style="width:12%"></colgroup>
            <thead><tr>
                <?php foreach ($fields as [$label]): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?>
                <th scope="col">수정일</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sheet as $row): ?>
                <tr>
                    <?php foreach ($fields as $key => $spec): ?>
                        <td><?= $e($key === 'phone' && $row[$key] === '' ? '미등록' : $row[$key]) ?></td>
                    <?php endforeach; ?>
                    <td><?= $e(substr($row['updated_at'], 0, 10)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else:
        $row = $sheet[0];
    ?>
        <table class="data-tbl">
            <tbody>
            <?php foreach ($fields as $key => [$label]): ?>
                <tr><th scope="row"><?= $e($label) ?></th><td><?= $e($row[$key]) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($photoFields): ?>
            <div class="photos">
            <?php foreach ($photoFields as $key => $label):
                $file = er_file_path($row[$key], true);
            ?>
                <figure>
                    <figcaption><?= $e($label) ?></figcaption>
                    <?php if ($file !== null && er_photo_mime($file) !== null): ?>
                        <img src="emergency_response.php?view_photo=<?= $e($row[$key]) ?>" alt="<?= $e($label) ?>">
                    <?php else: ?>
                        <div class="photo-empty">첨부 사진 없음</div>
                    <?php endif; ?>
                </figure>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <footer class="doc-footer"><img src="<?= $e($logo) ?>" alt=""><span>주식회사 현대기전</span></footer>
</section>
<?php endforeach; ?>
<script>
const approval = document.getElementById('approval-toggle');
approval.addEventListener('click', () => {
    const hidden = document.body.classList.toggle('hide-approval');
    approval.textContent = hidden ? '결재란 OFF' : '결재란 ON';
    approval.setAttribute('aria-pressed', String(!hidden));
});
const presets = document.querySelectorAll('[data-preset]');
presets.forEach((button) => button.addEventListener('click', () => {
    document.body.dataset.margin = button.dataset.preset;
    presets.forEach((preset) => preset.setAttribute('aria-pressed', String(preset === button)));
}));
document.getElementById('print').addEventListener('click', () => window.print());
</script>
</body>
</html>
