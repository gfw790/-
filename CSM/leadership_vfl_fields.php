<?php
declare(strict_types=1);
if (!isset($form, $escape)) return;
?>
<label>순찰 시간<input type="time" name="vfl_time" value="<?= $escape($form['vfl_time']) ?>"></label>
<label>동행자<input name="vfl_companions" maxlength="500" value="<?= $escape($form['vfl_companions']) ?>" placeholder="동행한 안전관리자·현장 담당자 등"></label>
<label>안전순찰 중 대화 인원 (명)<input type="number" name="vfl_dialog_count" min="0" max="10000" step="1" value="<?= $escape($form['vfl_dialog_count']) ?>" placeholder="미기록이면 비워두세요"></label>
<details class="wide vfl-checklist"><summary>중점 점검사항 선택 · 실제 점검한 항목만 표시</summary><div class="vfl-check-grid"><?php foreach (leadership_vfl_check_labels() as $code => $label): ?><label><input type="checkbox" name="vfl_checks[]" value="<?= $code ?>" <?= in_array($code, $form['vfl_checks'], true) ? 'checked' : '' ?>><?= $escape($label) ?></label><?php endforeach; ?></div><label>추가 중점 점검사항<textarea name="vfl_focus_notes" maxlength="4000" rows="2"><?= $escape($form['vfl_focus_notes']) ?></textarea></label></details>
