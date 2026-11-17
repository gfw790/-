<?php
declare(strict_types=1);
if (!isset($vflMonthly, $criterion, $escape, $allMonthsBase)) return;
$ratingMonth = $selectedMonth ?? max(1, $vflMonthly['cutoff']);
$rating = $vflMonthly['months'][$ratingMonth];
$futureMonth = $ratingMonth > $vflMonthly['cutoff'];
?>
<div class="vfl-month-rating">
<?php if (($vflMonthly['legacy_multiplier'] ?? null) !== null): ?><p class="hint">기존 연간 평가수준 <?= $vflMonthly['legacy_multiplier'] ?>은 보관되어 있습니다. 월별 실적을 확인해 각 월의 평가수준을 저장해 주세요. 연간 평가값을 각 월에 자동 배분하지 않습니다.</p><?php endif; ?>
<h3><?= $ratingMonth ?>월 평가수준</h3>
<p class="hint">위의 월을 눌러 평가할 달을 선택하세요. 해당 월의 ‘서명본 증빙’에 스캔본을 첨부해야 평가수준을 선택·저장할 수 있습니다. 점검사진과 일반 첨부만으로는 평가할 수 없습니다.</p>
<form method="post" action="<?= $escape($allMonthsBase) ?>&amp;month=<?= $ratingMonth ?>" class="score-form">
<input type="hidden" name="csrf" value="<?= $escape($_SESSION['leadership_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="item" value="1.1.3"><input type="hidden" name="form_action" value="assess_month"><input type="hidden" name="assessment_month" value="<?= $ratingMonth ?>"><input type="hidden" name="assessment_revision" value="<?= $rating['revision'] ?>">
<label><?= $ratingMonth ?>월 서명본 검토 후 평가수준<select name="multiplier" required <?= $futureMonth || !$db || $rating['evidence_count'] === 0 ? 'disabled' : '' ?>><option value="">평가수준 선택</option><?php foreach ($criterion['criteria'] as $level => $label): if (!is_numeric($level) || $label === '' || $label === '-') continue; ?><option value="<?= $escape($level) ?>" <?= $rating['multiplier'] !== null && (float)$level === $rating['multiplier'] ? 'selected' : '' ?> <?= $rating['evidence_count'] === 0 && (float)$level > 0 ? 'disabled' : '' ?>><?= $escape(str_replace("\n", ' ', $label)) ?> · <?= $criterion['points'] * (float)$level ?>점</option><?php endforeach; ?></select></label><button class="primary" <?= $futureMonth || !$db || $rating['evidence_count'] === 0 ? 'disabled' : '' ?>><?= $ratingMonth ?>월 평가 저장</button>
</form>
<p class="hint"><?= $futureMonth ? '아직 평가 대상 월이 아닙니다.' : '해당 월 서명본 증빙 ' . $rating['evidence_count'] . '개 · 반영 ' . $rating['earned'] . ' / ' . $criterion['points'] . '점' ?></p>
</div>
