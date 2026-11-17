<?php
declare(strict_types=1);
if (!isset($row, $escape)) return;
$vfl = $row['data'];
$checked = [];
$photos = [];
foreach ($files[$row['id']] ?? [] as $file) if ($file['photo_slot'] !== null) $photos[(int)$file['photo_slot']] = $file;
ksort($photos);
foreach ($vfl['vfl_checks'] ?? [] as $key) if (isset(leadership_vfl_check_labels()[$key])) $checked[] = leadership_vfl_check_labels()[$key];
?>
<div class="vfl-record-meta"><span>순찰 시간: <?= $escape(($vfl['vfl_time'] ?? '') ?: '미기록') ?></span><span>대화 인원: <?= isset($vfl['vfl_dialog_count']) ? (int)$vfl['vfl_dialog_count'] . '명' : '미기록' ?></span><?php if (!empty($vfl['vfl_companions'])): ?><span>동행자: <?= $escape($vfl['vfl_companions']) ?></span><?php endif; ?></div>
<?php if ($checked || !empty($vfl['vfl_focus_notes'])): ?><div class="vfl-record-focus"><strong>중점 점검사항</strong><p><?= $escape(implode(' · ', $checked)) ?></p><?php if (!empty($vfl['vfl_focus_notes'])): ?><p class="pre"><?= $escape($vfl['vfl_focus_notes']) ?></p><?php endif; ?></div><?php endif; ?>
<div class="vfl-report-photos"><?php foreach ($photos as $slot => $photo): ?><figure><div><h4>점검사진 <?= $slot ?></h4><img src="<?= $escape($base) ?>&amp;file=<?= $photo['id'] ?>&amp;inline=1" alt="점검사진 <?= $slot ?>"></div><figcaption><strong>안전대화 내용</strong><p><?= $escape($photo['caption'] ?: '미기록') ?></p></figcaption></figure><?php endforeach; ?><?php if (!$photos): ?><p class="muted">점검사진 미등록</p><?php endif; ?></div>
<?php if ($vfl['notes'] !== ''): ?><p class="pre"><?= $escape($vfl['notes']) ?></p><?php endif; ?>
<?php if (!empty($vfl['vfl_dialogue'])): ?><div><h4>기존 공통 안전대화 내용</h4><p class="pre"><?= $escape($vfl['vfl_dialogue']) ?></p></div><?php endif; ?>
<p class="vfl-signature">순찰자: <?= $escape($vfl['owner']) ?>　(서명) __________________</p>
