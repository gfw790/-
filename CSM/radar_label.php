<?php
declare(strict_types=1);
if (!isset($area, $labelX, $labelY, $color, $soft, $escape, $index, $axisStep, $chartRadius)) return;
$scoreStates = ['no_evidence' => '증빙 미첨부', 'pending' => '평가 대기', 'assessed' => '평가 반영', 'unavailable' => '조회 실패'];
$labelName = $area['title'];
$scoreText = $area['earned_score'] !== null ? $area['earned_score'] . '점' : '확인 불가';
?>
<a class="radial-sector" href="<?= $escape($area['href']) ?>" style="--accent:<?= $color ?>;--soft:<?= $soft ?>" aria-label="<?= $escape($labelName . ' 반영 ' . $scoreText . ', ' . $scoreStates[$area['score_state']] . ', 페이지 열기') ?>"><title><?= $escape($labelName . ' · ' . $scoreStates[$area['score_state']] . ' · 증빙 없는 항목은 0점') ?></title><rect class="radar-label-box" x="<?= $labelX - 115 ?>" y="<?= $labelY - 31 ?>" width="230" height="68" rx="12"/><text class="sector-title" x="<?= $labelX ?>" y="<?= $labelY - 5 ?>"><?= $escape($labelName) ?> ↗</text><text class="radar-label-value" x="<?= $labelX ?>" y="<?= $labelY + 22 ?>"><?= $escape($scoreText) ?><tspan class="sector-meta"> / 100 · <?= $scoreStates[$area['score_state']] ?></tspan></text><?php if ($area['earned_score'] !== null): [$dataX, $dataY] = $point($chartRadius * $area['earned_score'] / 100, -90 + $index * $axisStep); ?><circle class="radar-dot" cx="<?= $dataX ?>" cy="<?= $dataY ?>" r="4.5" data-earned-score="<?= $area['earned_score'] ?>"/><?php endif; ?></a>
