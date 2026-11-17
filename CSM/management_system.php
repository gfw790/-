<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scoring.php';
$user = csm_require_auth();
header('Cache-Control: no-store');
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = require __DIR__ . '/module_routes.php';
$scoreYear = filter_var($_GET['year'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y'), FILTER_VALIDATE_INT);
if (!$scoreYear || $scoreYear < 2000 || $scoreYear > 2100) { http_response_code(400); exit('조회 연도를 확인해 주세요.'); }
try { $currentScores = csm_score_summary(csm_scoring_db(), $catalog, $scoreYear)['items']; }
catch (Throwable $exception) { $currentScores = []; error_log('CSM item scores: ' . $exception->getMessage()); }

$modules = array_values(array_filter($catalog['modules'], static fn($module) => $module['group'] === '1'));
$section = $_GET['section'] ?? '1.1';
if (!is_string($section) || !in_array($section, ['1.1', '1.2', '1.3'], true)) {
    http_response_code(400);
    exit('올바른 안전보건관리체계 항목을 선택해 주세요.');
}
$current = array_values(array_filter($modules, static fn($module) => $module['id'] === $section))[0];
$route = $routes[$section];
$titles = ['1.1' => '경영자 리더십', '1.2' => '안전보건활동', '1.3' => '근로자의 참여'];
$itemLinks = [
    '1.2.1' => [['활동계획 협의·승인', 'activity_plan.php?year='.$scoreYear], ['목표·활동계획', '../safety_manual/goal_plan.php?year='.$scoreYear]],
    '1.2.2' => [['월별 점검·분기 경영자 검토', 'activity_monitor.php?year='.$scoreYear], ['분기 실적표', '../safety_manual/goal_plan_quarter.php?year='.$scoreYear]],
    '1.2.3' => [['예산·조직 관리', 'activity_organization.php?year='.$scoreYear], ['안전보건 예산 원장', '../safety_manual/safety_budget.php']],
    '1.2.4' => [['개선계획 반영·완료', 'activity_improvement.php?year='.$scoreYear], ['활동계획 대조', '../safety_manual/goal_plan.php?year='.$scoreYear]],
    '1.2.5' => [['안전업무일지', '../safety_log/']],
    '1.2.7' => [['현장점검 업무일지', '../safety_log/']],
    '1.3.1' => [['산업안전보건위원회 회의록', 'worker_participation.php?tab=meetings&year='.$scoreYear]],
    '1.3.2' => [['안전제안 관리대장', 'worker_participation.php?tab=suggestions&year='.$scoreYear], ['TBM 기록', '../tbm/']],
    '1.3.3' => [['반기 환류 보고서', 'worker_participation.php?tab=feedback&year='.$scoreYear], ['정보 공유 게시판', '../board/']],
];
$pending = [
    '1.2.5' => '선임서류, 책임·전결 권한과 업무수행 이력 관리',
    '1.2.6' => '업무수행 평가 기준과 반기 평가·지원조치 관리',
    '1.2.7' => '관리감독자 지정서와 현장점검 이력 연결',
];
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>안전보건관리체계 · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="management_system.css"></head><body>
<header><div><a href="/CSM/">← CSM 포털</a><strong>안전보건관리체계</strong><span><?= $escape($user['name']) ?></span></div></header>
<main>
  <div class="heading"><div><small>CSM 평가 영역 1 · 28점</small><h1>안전보건관리체계</h1><p>경영자 리더십, 안전보건활동, 근로자 참여를 한곳에서 관리합니다.</p></div><div class="scope-summary"><strong>3개 업무 · 14개 평가항목</strong><span>2025 협력업체 안전활동평가표 기준</span></div></div>
  <nav class="tabs management-tabs" aria-label="안전보건관리체계 업무 선택"><?php foreach ($modules as $module): ?><a href="?section=<?= $module['id'] ?>&amp;year=<?= $scoreYear ?>" <?= $section === $module['id'] ? 'aria-current="page"' : '' ?>><small><?= $module['id'] ?> · <?= array_sum(array_column($module['items'], 'points')) ?>점</small><strong><?= $escape($titles[$module['id']]) ?></strong><span><?= count($module['items']) ?>개 평가항목 · <?= $module['id'] === '1.1' ? '활동기록 사용 가능' : '기존 화면 연결 · 보완 필요' ?></span></a><?php endforeach; ?></nav>
  <section class="panel module-intro" aria-labelledby="section-title"><div class="section-title"><h2 id="section-title"><?= $section ?> <?= $escape($titles[$section]) ?></h2><span><?= count($current['items']) ?>개 항목 · <?= array_sum(array_column($current['items'], 'points')) ?>점</span></div><p><?= $escape($route['summary']) ?></p><div class="quick-links"><?php foreach ($route['links'] as [$label, $href]): ?><a class="button <?= $section === '1.1' && $href === 'leadership.php' ? 'primary' : '' ?>" href="<?= $escape($href) ?>"><?= $escape($label) ?> ↗</a><?php endforeach; ?></div><?php if ($section !== '1.1'): ?><p class="hint">관련 업무 화면을 연결했습니다. 전용 기록 기능이 필요한 부분은 아래 항목별로 표시했습니다.</p><?php endif; ?></section>
  <section class="panel item-panel" aria-label="선택한 업무의 평가항목">
    <?php foreach ($current['items'] as $item): ?>
    <article class="system-item" id="item-<?= $item['id'] ?>">
      <div class="item-heading"><div><span class="item-code"><?= $item['id'] ?></span><span class="item-period"><?= $item['points'] ?>점 · 현재 점수 <?= isset($currentScores[$item['id']]) ? $currentScores[$item['id']]['earned'] . "점" : "조회 불가" ?> · <?= $item['first_half'] ? '상·하반기' : '하반기' ?></span></div><div class="quick-links item-actions"><?php if ($section === '1.1'): ?><a class="button" href="leadership.php?item=<?= $item['id'] ?>&amp;year=<?= $scoreYear ?>">활동 기록</a><?php else: ?><?php foreach ($itemLinks[$item['id']] ?? [] as [$label, $href]): ?><a class="button" href="<?= $escape($href) ?>"><?= $escape($label) ?> ↗</a><?php endforeach; ?><?php endif; ?></div></div>
      <details><summary><?= $escape($item['title']) ?></summary><div class="item-details"><div><h3>필요 증빙·확인 사항</h3><p class="pre"><?= $escape($item['evidence']) ?></p><?php if ($item['note']): ?><p class="hint"><?= $escape($item['note']) ?></p><?php endif; ?><?php if (isset($pending[$item['id']])): ?><div class="pending"><strong>추가 연결·구현할 업무</strong><p><?= $escape($pending[$item['id']]) ?></p></div><?php endif; ?></div><div><h3>원본 평가 수준별 기준</h3><dl><?php foreach ($item['criteria'] as $level => $text): ?><dt><?= $escape($level) ?></dt><dd><?= $escape($text ?: '—') ?></dd><?php endforeach; ?></dl></div></div></details>
    </article>
    <?php endforeach; ?>
  </section>
  <p class="hint">평가 기준과 업무 연결을 안내하는 페이지입니다. 1.1의 실제 기록과 증빙은 리더십 활동관리에서 관리하며, 각 업무 화면의 기존 접근 권한이 적용됩니다.</p>
</main></body></html>
