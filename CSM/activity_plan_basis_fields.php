<?php
if (!isset($form,$snapshot,$escape)) return;
$basisDetails=activity_plan_basis_details($form['basis_details'] ?? []);
$basisGuides=activity_plan_basis_guides();
?>
<fieldset><legend>1. 목표 수립 근거</legend>
<p>항목을 하나씩 열고 두 질문에 짧게 답해 주세요. 관련 목표는 연간 계획에서 선택할 수 있습니다.</p>
<p class="hint">입력칸의 예시는 작성 방법을 보여주는 예시입니다. 실제로 확인한 내용을 적어 주세요. 아직 자료를 확인하지 않았다면 빈칸으로 두고 중간 저장해도 됩니다.</p>
<div class="basis-guided-list">
<?php foreach (activity_plan_basis_fields() as $key=>$label): $guide=$basisGuides[$key]; $detail=$basisDetails[$key]; $legacy=$form['basis'][$key] ?? ''; $guided=(bool)array_filter($detail, static fn($value)=>trim($value) !== '') || $legacy === ''; ?>
<details class="basis-guided-card" <?= $key==='accidents' ? 'open' : '' ?>><summary><strong><?= $escape($label) ?></strong><span><?= $escape($guide['question']) ?></span></summary>
<input type="hidden" name="basis_mode[<?= $key ?>]" value="<?= $guided ? 'guided' : 'legacy' ?>">
<?php if ($key==='risk'): ?>
<div class="basis-risk-help"><strong>전년도 말 계획과 올해 실행을 구분해 기록하세요</strong><p><b>연간 목표:</b> 전년도 평가 결과를 근거로 정기평가 시기, 상반기 심의와 하반기 이행점검 횟수·확인율을 정합니다.</p><p><b>정기평가 후:</b> 실제 개선조치 건수와 기한을 확정하고, 계획 변경이 필요하면 새 버전으로 협의·승인합니다.</p><a href="/safety_manual/goal_plan.php?year=<?= (int)($snapshot['year'] ?? date('Y')) ?>">연간 목표표에서 운영 흐름 보기 →</a></div>
<?php endif; ?>
<?php if (!$guided): ?><details class="basis-legacy"><summary>기존에 작성한 내용</summary><textarea name="basis[<?= $key ?>]" rows="3" maxlength="4000"><?= $escape($legacy) ?></textarea><p class="hint">기존 내용은 그대로 보관됩니다. 아래 질문에 새로 답하면 질문형 내용으로 저장됩니다.</p></details><?php else: ?><input type="hidden" name="basis[<?= $key ?>]" value="<?= $escape($legacy) ?>"><?php endif; ?>
<?php if ($key==='risk'): ?>
<div class="basis-questions basis-risk-questions">
<label>① 기준이 된 자료<select name="basis_details[risk][source]"><option value="">주요 근거자료 선택</option><?php foreach (['regular'=>'전년도 정기위험성평가','occasional'=>'전년도 수시위험성평가','unresolved'=>'전년도 미완료 개선조치','other'=>'기타 위험성평가 자료'] as $sourceId=>$sourceLabel): ?><option value="<?= $sourceId ?>" <?= $detail['source']===$sourceId ? 'selected' : '' ?>><?= $sourceLabel ?></option><?php endforeach; ?></select></label>
<label>② 확인된 실적과 주요 위험 <small>가능하면 건수를 포함하세요</small><textarea name="basis_details[risk][finding]" rows="2" maxlength="1500" placeholder="<?= $escape($guide['finding']) ?>"><?= $escape($detail['finding']) ?></textarea></label>
<label>③ 계획연도에 연결할 연간 목표<select name="basis_details[risk][goal]" data-risk-goal><option value="">관련 목표 선택</option><?php foreach ($snapshot['rows'] ?? [] as $goalId=>$goal): ?><option value="<?= $escape($goalId) ?>" data-plan="<?= $escape((string)($goal['plan']??'')) ?>" data-unit="<?= $escape((string)($goal['unit']??'')) ?>" data-frequency="<?= $escape((string)($goal['frequency']??'')) ?>" <?= $detail['goal']===$goalId ? 'selected' : '' ?>><?= $escape($goal['name']) ?></option><?php endforeach; ?></select></label>
<p class="risk-goal-value" data-risk-goal-value aria-live="polite">목표를 선택하면 연간 계획표의 목표값이 표시됩니다.</p>
<label>목표 달성 여부를 측정하는 방법 <small>예: 기한 내 이행 확인 건수 ÷ 기한 도래 건수 × 100</small><input name="basis_details[risk][metric]" maxlength="1500" value="<?= $escape($detail['metric']) ?>" placeholder="분자와 분모 또는 점검 횟수를 적어 주세요"></label>
<label>④ 이 목표를 선정한 이유<textarea name="basis_details[risk][action]" rows="2" maxlength="1500" placeholder="<?= $escape($guide['action']) ?>"><?= $escape($detail['action']) ?></textarea></label>
</div>
<?php else: ?>
<div class="basis-questions"><label>① 무엇을 확인했나요?<textarea name="basis_details[<?= $key ?>][finding]" rows="2" maxlength="1500" placeholder="<?= $escape($guide['finding']) ?>"><?= $escape($detail['finding']) ?></textarea></label>
<label>관련된 연간 목표 <small>선택사항</small><select name="basis_details[<?= $key ?>][goal]"><option value="">관련 목표 선택</option><?php foreach ($snapshot['rows'] ?? [] as $goalId=>$goal): ?><option value="<?= $escape($goalId) ?>" <?= $detail['goal']===$goalId ? 'selected' : '' ?>><?= $escape($goal['name']) ?></option><?php endforeach; ?></select></label>
<label>② 올해 계획에 어떻게 반영했나요?<textarea name="basis_details[<?= $key ?>][action]" rows="2" maxlength="1500" placeholder="<?= $escape($guide['action']) ?>"><?= $escape($detail['action']) ?></textarea></label></div>
<?php endif; ?>
<label class="basis-file-input"><?= $escape($label) ?> 근거 자료 첨부<input type="file" name="basis_<?= $escape($key) ?>_files[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label>
</details><?php endforeach; ?></div>
</fieldset>
<script>document.querySelectorAll('[data-risk-goal]').forEach(function(select){const output=select.closest('.basis-risk-questions').querySelector('[data-risk-goal-value]');function update(){const option=select.selectedOptions[0];if(!option || !option.value){output.textContent='목표를 선택하면 연간 계획표의 목표값이 표시됩니다.';return;}output.textContent='연간 계획표의 목표값: '+(option.dataset.plan || '미입력')+(option.dataset.unit || '')+(option.dataset.frequency ? ' · 주기: '+option.dataset.frequency : '');}select.addEventListener('change',update);update();});</script>
