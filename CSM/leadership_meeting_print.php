<?php
declare(strict_types=1);
if (!isset($records, $files, $escape, $attendanceLabels)) return;
?>
<div class="meeting-print-documents">
<?php foreach ($records as $meetingRecord): $meetingData = $meetingRecord['data']; ?>
<article class="meeting-print-report" data-meeting-print-record="<?= (int)$meetingRecord['id'] ?>">
<header class="meeting-print-heading"><p><?= $escape(substr($meetingRecord['activity_date'], 0, 4)) ?>년 <?= (int)substr($meetingRecord['activity_date'], 5, 2) ?>월</p><h1>대표자 안전보건 협의회</h1><p class="meeting-print-subtitle"><?= $escape($meetingRecord['title']) ?></p></header>
<table class="meeting-print-meta"><colgroup><col style="width:16%"><col style="width:34%"><col style="width:16%"><col style="width:34%"></colgroup><tbody>
<tr><th>협의회일</th><td><?= $escape($meetingRecord['activity_date']) ?></td><th>참석 구분</th><td><?= $escape($attendanceLabels[$meetingData['attendance']] ?? '') ?></td></tr>
<tr><th>참석자</th><td><?= $escape($meetingData['owner']) ?></td><th>장소</th><td><?= $escape($meetingData['location']) ?></td></tr>
</tbody></table>
<section class="meeting-print-content"><h2>협의 내용 · 확인 사항</h2><div class="meeting-print-notes">
<?php foreach (preg_split('/\R/u', trim($meetingData['notes'])) as $meetingLine): ?>
<?php if (trim($meetingLine) === ''): ?><div class="meeting-print-paragraph-gap"></div><?php else: ?>
<p<?= preg_match('/^\s*\d+[-.]\d+[.\s]/u', $meetingLine) ? ' class="meeting-print-subitem"' : '' ?>><?= $escape($meetingLine) ?></p>
<?php endif; ?><?php endforeach; ?>
</div></section>
<?php if ($files[$meetingRecord['id']] ?? []): ?><section class="meeting-print-evidence"><h2>첨부 증빙</h2><ul><?php foreach ($files[$meetingRecord['id']] as $meetingFile): ?><li><?= $escape($meetingFile['original_name']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<footer class="meeting-print-footer"><img src="/safety_manual/assets/policy-logo.png" alt="현대기전 로고"><strong>주식회사 현대기전</strong></footer>
</article>
<?php endforeach; ?>
</div>
