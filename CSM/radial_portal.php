<?php
declare(strict_types=1);
if (!isset($currentUser, $eduStats, $eduStatsAvailable)) {
    header('Location: /CSM/');
    exit;
}
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
require_once __DIR__ . '/scoring.php';
$scoreYear = filter_var($_GET['year'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y'), FILTER_VALIDATE_INT);
if (!$scoreYear || $scoreYear < 2000 || $scoreYear > 2100) { http_response_code(400); exit('평가 연도를 확인해 주세요.'); }
$scoreSummary = null;
try {
    $scoreSummary = csm_score_summary(csm_scoring_db(), $catalog, $scoreYear);
} catch (Throwable $error) {
    error_log('CSM score summary: ' . $error->getMessage());
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$_SESSION['csm_login_csrf'] ??= bin2hex(random_bytes(32));
$colors = [1 => ['#3468df', '#edf3ff'], 2 => ['#0c8d95', '#e8f7f7'], 3 => ['#b77a13', '#fff6e5'], 4 => ['#248653', '#ebf7ef'], 5 => ['#7951cb', '#f3edff'], 6 => ['#c35277', '#fff0f5']];
$areas = [];
foreach ($catalog['groups'] as $group) {
    $modules = array_values(array_filter($catalog['modules'], static fn($module) => $module['group'] === $group['id']));
    $group['modules'] = count($modules);
    $group['items'] = array_sum(array_map(static fn($module) => count($module['items']), $modules));
    $group['href'] = $group['id'] === '1' ? 'management_system.php' : 'area.php?group=' . $group['id'];
    $group['score'] = $group['points'] !== null ? $group['points'] . '점' : '별도 가감점';
    // Each scored area has its own 100-point maximum. No earned score is assumed.
    $group['reference_score'] = $group['points'] !== null ? 100 : null;
    $score = $scoreSummary['groups'][$group['id']] ?? null;
    $group['earned_score'] = $score['normalized'] ?? null;
    $group['earned_points'] = $score['earned'] ?? null;
    $group['score_state'] = $score === null ? 'unavailable' : ($score['evidence_items'] === 0 ? 'no_evidence' : ($score['assessed_items'] === 0 ? 'pending' : 'assessed'));
    $areas[] = $group;
}
$scoredAreas = array_values(array_filter($areas, static fn($area) => $area['reference_score'] !== null));
$adjustmentArea = current(array_filter($areas, static fn($area) => $area['reference_score'] === null)) ?: null;
$formatPoints = static fn(float $value): string => rtrim(rtrim(number_format(round($value, 4), 4, '.', ''), '0'), '.');
$baseScoreText = '조회 실패';
$finalScoreText = '조회 실패';
$adjustmentScoreText = '조회 실패';
if ($scoreSummary !== null && ($scoreSummary['items']['6.1.1']['state'] ?? '') !== 'unavailable' && $adjustmentArea && $adjustmentArea['earned_points'] !== null) {
    $basePoints = array_sum(array_map(static fn($area) => (float)$area['earned_points'], $scoredAreas));
    $adjustmentPoints = (float)$adjustmentArea['earned_points'];
    $baseScoreText = $formatPoints($basePoints) . '점';
    $adjustmentScoreText = ($adjustmentPoints > 0 ? '+' : '') . $formatPoints($adjustmentPoints) . '점';
    $finalScoreText = $formatPoints($basePoints + $adjustmentPoints) . '점';
}
$axisCount = count($scoredAreas);
$axisStep = 360 / $axisCount;
$chartRadius = 245;
$chartCenterY = 320;
$point = static function (float $radius, float $angle) use ($chartCenterY): array {
    $radians = deg2rad($angle);
    return [500 + $radius * cos($radians), $chartCenterY + $radius * sin($radians)];
};
$radarPoints = [];
$earnedPoints = [];
foreach ($scoredAreas as $index => $area) {
    $radarPoints[] = implode(',', $point($chartRadius * $area['reference_score'] / 100, -90 + $index * $axisStep));
    if ($area['earned_score'] !== null) $earnedPoints[] = implode(',', $point($chartRadius * $area['earned_score'] / 100, -90 + $index * $axisStep));
}
$labelPositions = [[500, 33], [820, 180], [700, 560], [300, 560], [180, 180]];
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>현대기전 CSM 안전보건 업무포털</title><link rel="stylesheet" href="radial_portal.css?v=9"></head><body>
<header><div class="header-inner"><a href="/CSM/" class="brand"><span class="logo">CSM</span><div><strong>(주)현대기전 통합 CSM 업무시스템</strong><small>안전보건활동 · 평가 증빙 관리</small></div></a><div class="header-actions"><span class="user"><?= $escape($currentUser['name'] ?? '') ?></span><a href="http://127.0.0.1:8085">평가 시뮬레이터 ↗</a><a href="education/">안전교육</a><form method="post" action="/CSM/login.php"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_login_csrf']) ?>"><input type="hidden" name="action" value="logout"><button>로그아웃</button></form></div></div></header>
<main><div class="intro"><div><span class="eyebrow">CSM WORKSPACE</span><h1>안전보건 업무 한눈에 보기</h1><p>원하는 영역을 누르면 해당 업무 페이지가 열립니다.</p></div><div class="overview"><span><strong><?= $axisCount ?></strong>개 평가 영역</span><span><strong>18</strong>개 모듈</span><span>기본 <strong>100</strong>점 + 가감점</span></div></div>
<section class="radial-panel" aria-label="5개 평가 영역 방사형 업무 메뉴와 별도 가점·감점 항목">
<?php if (auth_can_manage($currentUser)): ?><div class="headcount-shortcut"><a href="headcount.php">기준일별 인원 조회 →</a></div><?php endif; ?>
<form class="score-year" method="get" action="/CSM/"><label for="score-year">평가 연도</label><input id="score-year" type="number" name="year" min="2000" max="2100" value="<?= $scoreYear ?>" required><button>조회</button><span class="score-total"><strong>현재 최종 점수 <?= $escape($finalScoreText) ?></strong><small>기본 <?= $escape($baseScoreText) ?> + 가감점 <?= $escape($adjustmentScoreText) ?> (6.1.1·6.1.2 반영 · 기타 산정 전)</small></span></form>
<svg class="radial-map" viewBox="0 0 1000 620" xmlns="http://www.w3.org/2000/svg" role="group" aria-labelledby="radial-title radial-description"><title id="radial-title">CSM 영역별 100점 기준 레이더 차트</title><desc id="radial-description">각 영역 100점 만점 기준입니다. 점선은 만점 기준, 실선은 증빙과 입력한 평가에 따라 반영된 점수입니다. 증빙 미첨부 항목은 0점이며 첨부만으로 점수가 부여되지 않습니다.</desc>
<?php for ($level = 20; $level <= 100; $level += 20): $ring = []; for ($axis = 0; $axis < $axisCount; $axis++) $ring[] = implode(',', $point($chartRadius * $level / 100, -90 + $axis * $axisStep)); ?><polygon class="radar-grid" points="<?= implode(' ', $ring) ?>"/><text class="radar-tick" x="508" y="<?= $chartCenterY - $chartRadius * $level / 100 + 4 ?>"><?= $level ?>점</text><?php endfor; ?>
<?php foreach ($scoredAreas as $index => $area): [$axisX, $axisY] = $point($chartRadius, -90 + $index * $axisStep); ?><line class="radar-axis" x1="500" y1="<?= $chartCenterY ?>" x2="<?= $axisX ?>" y2="<?= $axisY ?>"/><?php endforeach; ?>
<text class="radar-tick" x="480" y="<?= $chartCenterY - 2 ?>">0</text><polygon class="radar-reference" points="<?= implode(' ', $radarPoints) ?>"/><?php if (count($earnedPoints) === $axisCount): ?><polygon class="radar-data" points="<?= implode(' ', $earnedPoints) ?>"/><?php endif; ?>
<?php foreach ($scoredAreas as $index => $area): [$labelX, $labelY] = $labelPositions[$index]; [$color, $soft] = $colors[(int)$area['id']]; ?>
<?php require __DIR__ . '/radar_label.php'; ?>
<?php endforeach; ?>
</svg>
<nav class="mobile-areas" aria-label="안전보건 업무 영역"><?php foreach ($scoredAreas as $area): [$color, $soft] = $colors[(int)$area['id']]; ?><a href="<?= $escape($area['href']) ?>" style="--accent:<?= $color ?>;--soft:<?= $soft ?>"><span class="mobile-number"><?= $area['earned_score'] !== null ? $area['earned_score'] . '점' : '조회 실패' ?></span><div><strong><?= $escape($area['title']) ?></strong><small><?= ['no_evidence' => '증빙 미첨부 · 0점', 'pending' => '증빙 첨부 · 평가 대기', 'assessed' => '평가 반영', 'unavailable' => '점수 조회 실패'][$area['score_state']] ?></small></div><span>↗</span></a><?php endforeach; ?></nav>
<?php if ($adjustmentArea): ?><a class="adjustment-area" href="<?= $escape($adjustmentArea['href']) ?>" aria-label="가점·감점 항목, 현재 반영 <?= $escape($adjustmentScoreText) ?>, <?= $adjustmentArea['items'] ?>개 항목 보기"><span class="adjustment-icon">±</span><span><strong>가점·감점 항목</strong><small><b class="adjustment-score"><?= $escape($adjustmentScoreText) ?></b> · <?= $adjustmentArea['items'] ?>개 항목</small></span><span class="adjustment-arrow">↗</span></a><?php endif; ?>
<div class="chart-note"><span><?= $scoreYear ?>년 반영 점수 · 점선: 100점 만점 / 실선: 실제 반영 점수</span><span>증빙 미첨부 0점 · 첨부 후 평가 저장 시 반영 · 가점·감점 별도</span></div></section>
</main><footer class="portal-footer"><span>주식회사 현대기전 · CSM 통합 안전보건 업무포털</span><a href="/risk_assessment/work_list.php">작업목록으로 가기 →</a></footer></body></html>
