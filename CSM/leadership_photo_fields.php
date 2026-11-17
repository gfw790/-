<?php
declare(strict_types=1);
if (!isset($form, $formPhotos, $base, $escape)) return;
?>
<fieldset class="vfl-photo-editor wide"><legend>점검사진 · 최대 2장</legend><p class="hint">JPG·PNG 사진을 선택하고 사진 오른쪽에 안전대화 내용을 입력하세요. 사진별 10MB 이내이며, 수정 시 같은 칸에 새 사진을 선택하면 기존 사진을 교체합니다.</p><div class="vfl-photo-slots">
<?php foreach ([1, 2] as $slot): $photo = $formPhotos[$slot] ?? null; $url = $photo ? $base . '&file=' . $photo['id'] . '&inline=1' : ''; $caption = is_string($form['vfl_photo_captions'][$slot] ?? null) ? $form['vfl_photo_captions'][$slot] : ''; ?>
<div class="vfl-photo-slot" data-photo-slot="<?= $slot ?>" tabindex="-1">
<div class="vfl-photo-image"><label class="vfl-photo-picker">점검사진 <?= $slot ?>
<span class="vfl-photo-preview"><img data-photo-preview <?= $url ? 'src="' . $escape($url) . '"' : 'hidden' ?> alt="점검사진 <?= $slot ?> 미리보기"><span data-photo-empty <?= $url ? 'hidden' : '' ?>><span aria-hidden="true">＋</span><br>클릭하여 사진 선택</span></span>
<span class="vfl-photo-select"><?= $photo ? '사진 변경' : '사진 선택' ?></span>
<input type="file" name="photo[<?= $slot ?>]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" aria-label="점검사진 <?= $slot ?> 선택" data-photo-input>
</label>
<button type="button" data-photo-paste>사진 붙여넣기 위치 선택 (Ctrl+V)</button><span class="hint" data-photo-status role="status" aria-live="polite"></span>
<span class="hint" data-photo-name><?= $escape($photo['original_name'] ?? '') ?></span></div><label class="vfl-photo-dialogue">안전대화 내용 <?= $slot ?><textarea name="vfl_photo_captions[<?= $slot ?>]" rows="7" maxlength="4000" placeholder="근로자와 나눈 안전대화 내용을 입력하세요."><?= $escape($caption) ?></textarea></label></div>
<?php endforeach; ?></div>
<?php if ($form['notes'] !== ''): ?><details><summary>기존 공통 설명</summary><textarea name="notes" rows="2" maxlength="10000"><?= $escape($form['notes']) ?></textarea></details><?php else: ?><input type="hidden" name="notes" value=""><?php endif; ?></fieldset>
