<?php
declare(strict_types=1);
if (!isset($records, $files, $escape, $base)) return;
$printReference = json_decode(file_get_contents(__DIR__ . '/vfl_print_reference.json'), true, 512, JSON_THROW_ON_ERROR);
?>
<div class="vfl-print-documents">
<?php foreach ($records as $printRecord):
    $data = $printRecord['data'];
    $printPhotos = [];
    $selected = array_intersect_key(leadership_vfl_check_labels(), array_flip($data['vfl_checks'] ?? []));
    foreach ($files[$printRecord['id']] ?? [] as $file) if ($file['photo_slot'] !== null) $printPhotos[(int)$file['photo_slot']] = $file;
?>
<section class="vfl-print-report" data-print-record="<?= (int)$printRecord['id'] ?>">
<h1>안전순찰 일지 <span>Safety inspection report</span></h1>
<table class="vfl-print-meta"><colgroup><col style="width:17%"><col style="width:40%"><col style="width:15%"><col style="width:28%"></colgroup><tbody>
<tr><th>일자 및 시간</th><td><?= $escape($printRecord['activity_date'] . ' ' . ($data['vfl_time'] ?? '')) ?></td><th>순찰지역</th><td><?= $escape($data['location']) ?></td></tr>
<tr><th>순찰자</th><td colspan="3"><?= $escape($data['owner']) ?><span class="vfl-print-sign">(서명) __________________</span></td></tr>
</tbody></table>
<h2>중점 점검 사항 <small>선택한 항목은 노란 형광색으로 표시</small></h2>
<?php
$guideGroups = ['A' => [8 => [14, 'general_permit'], 15 => [23, 'confined_space'], 24 => [31, 'hot_work'], 33 => [40, 'electrical_isolation']],
    'H' => [7 => [12, 'excavation'], 13 => [19, 'height'], 20 => [26, 'heavy_equipment'], 28 => [29, 'ppe'], 30 => [30, 'loto'], 31 => [31, 'education'], 32 => [32, 'housekeeping'], 34 => [37, 'tools_equipment']]];
?>
<div class="vfl-print-guide"><?php foreach (['A', 'H'] as $column): ?><div><?php $groupEnd = null; for ($guideRow = 7; $guideRow <= 40; $guideRow++):
    if (isset($guideGroups[$column][$guideRow]) && isset($selected[$guideGroups[$column][$guideRow][1]])):
        $groupEnd = $guideGroups[$column][$guideRow][0]; ?><div class="vfl-print-selected-group"><?php endif;
    $guideText = $printReference['guide'][$column . $guideRow] ?? ''; ?><div class="vfl-print-guide-line <?= preg_match('/^\s*(▶|[1-4]\. |[1-4]\))/', $guideText) ? 'vfl-print-guide-heading' : '' ?> <?= in_array($guideRow, $column === 'A' ? [8, 15, 24, 32, 33] : [13, 20, 27, 33], true) ? 'vfl-print-guide-paragraph' : '' ?>"><span><?= $escape($guideText) ?: '&nbsp;' ?></span></div><?php if ($groupEnd === $guideRow): ?></div><?php $groupEnd = null; endif; endfor; ?></div><?php endforeach; ?></div>
<?php if (!empty($data['vfl_focus_notes'])): ?><div class="vfl-print-focus-notes"><strong>추가 중점 점검사항</strong><p><?= $escape($data['vfl_focus_notes']) ?></p></div><?php endif; ?>
<div class="vfl-print-count"><div>● 안전순찰중 대화인원 : <strong><?= isset($data['vfl_dialog_count']) ? (int)$data['vfl_dialog_count'] . ' 명' : '미기록' ?></strong></div><div>● 분류　SRV : 안전수칙위반<br>　　　　 UA : 불안전한 행동<br>　　　　 UC : 불안전한 상태</div></div>
<table class="vfl-print-observations"><colgroup><col style="width:40%"><col style="width:60%"></colgroup><thead><tr><th>점검사진</th><th>안전대화 내용</th></tr></thead><tbody>
<?php foreach ([1, 2] as $photoSlot): $printPhoto = $printPhotos[$photoSlot] ?? null; ?>
<tr><td><div class="vfl-print-photo"><?php if ($printPhoto): ?><img src="<?= $escape($base) ?>&amp;file=<?= (int)$printPhoto['id'] ?>&amp;inline=1" alt="점검사진 <?= $photoSlot ?>"><?php else: ?><span>점검사진 <?= $photoSlot ?> 미등록</span><?php endif; ?></div></td><td class="vfl-print-dialogue"><?= $escape($printPhoto['caption'] ?? '') ?></td></tr>
<?php endforeach; ?></tbody></table>
<footer class="vfl-print-footer"><span class="vfl-print-company"><img src="/safety_manual/assets/policy-logo.png" alt="현대기전 로고"><strong>주식회사 현대기전</strong></span></footer>
</section>
<?php endforeach; ?>
</div>
