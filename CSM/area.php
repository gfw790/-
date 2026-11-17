<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scoring.php';
$user = csm_require_auth();
header('Cache-Control: no-store');
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$groupId = $_GET['group'] ?? '2';
if (!is_string($groupId) || !in_array($groupId, ['1', '2', '3', '4', '5', '6'], true)) { http_response_code(400); exit('평가 영역을 확인해 주세요.'); }
if ($groupId === '1') { header('Location: /CSM/management_system.php'); exit; }
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$scoreYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
try { $currentScores = csm_score_summary(csm_scoring_db(), $catalog, $scoreYear)['items']; }
catch (Throwable $exception) { $currentScores = []; error_log('CSM area item scores: ' . $exception->getMessage()); }
$routes = require __DIR__ . '/module_routes.php';
$group = array_values(array_filter($catalog['groups'], static fn($group) => $group['id'] === $groupId))[0];
$modules = array_values(array_filter($catalog['modules'], static fn($module) => $module['group'] === $groupId));
$section = $_GET['section'] ?? $modules[0]['id'];
$matching = array_values(array_filter($modules, static fn($module) => $module['id'] === $section));
if (!is_string($section) || !$matching) { http_response_code(400); exit('해당 영역의 업무를 선택해 주세요.'); }
$current = $matching[0];
$route = $routes[$section];
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($group['title']) ?> · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="management_system.css"><link rel="stylesheet" href="area.css"></head><body>
<header><div><a href="/CSM/">← CSM 포털</a><strong><?= $escape($group['title']) ?></strong><span><?= $escape($user['name']) ?></span></div></header>
<main><div class="heading"><div><small>CSM 평가 영역 <?= $groupId ?> · <?= $group['points'] !== null ? $group['points'] . '점' : '별도 가감점' ?></small><h1><?= $escape($group['title']) ?></h1><p>업무를 선택해 관련 화면과 평가항목·필요 증빙을 확인하세요.</p></div><div class="scope-summary"><strong><?= count($modules) ?>개 업무 · <?= array_sum(array_map(static fn($module) => count($module['items']), $modules)) ?>개 평가항목</strong><span>2025 협력업체 안전활동평가표 기준</span></div></div>
<div class="area-layout"><nav class="area-nav" aria-label="<?= $escape($group['title']) ?> 업무 선택"><?php foreach ($modules as $module): ?><a href="?group=<?= $groupId ?>&amp;section=<?= $module['id'] ?>" <?= $section === $module['id'] ? 'aria-current="page"' : '' ?>><small><?= $module['id'] ?> · <?= $groupId === '6' ? '별도 가감점' : array_sum(array_column($module['items'], 'points')) . '점' ?></small><strong><?= $escape($routes[$module['id']]['title']) ?></strong></a><?php endforeach; ?></nav>
<div><section class="panel module-intro"><div class="section-title"><h2><?= $section ?> <?= $escape($route['title']) ?></h2><span><?= count($current['items']) ?>개 항목</span></div><p><?= $escape($route['summary']) ?></p><div class="quick-links"><?php foreach ($route['links'] as [$label, $href]): ?><a class="button <?= (($groupId === '5' && strpos($href, 'education_activity.php') !== false) || $href === 'bonus_activity.php' || $href === 'tbm_activity.php') ? 'primary' : '' ?>" href="<?= $escape($href) ?>"><?= $escape($label) ?> ↗</a><?php endforeach; ?></div><?php if ($route['gap'] !== ''): ?><div class="pending"><strong><?= $route['links'] ? '보완할 업무' : '추가 구현할 업무' ?></strong><p><?= $escape($route['gap']) ?></p></div><?php endif; ?></section>
<section class="panel item-panel" aria-label="평가항목 및 필요 증빙"><?php foreach ($current['items'] as $item): ?><article class="system-item"><div class="item-heading"><div><span class="item-code"><?= $item['id'] ?></span><span class="item-period"><?= $item['points'] !== null ? $item['points'] . '점 · ' : '' ?>현재 점수 <?= isset($currentScores[$item['id']]) ? $currentScores[$item['id']]['earned'] . '점' : '조회 불가' ?> · <?= $groupId === '6' ? '별도 가감점' : ($item['first_half'] ? '상·하반기' : '하반기') ?></span></div><?php if ($groupId === '5'): ?><div class="quick-links item-actions"><a class="button primary" href="education_activity.php?item=<?= $item['id'] ?>&amp;year=<?= $scoreYear ?>">활동 기록</a></div><?php elseif ($item['id'] === '2.1.2'): ?><div class="quick-links item-actions"><a class="button primary" href="risk_procedure.php?year=<?= $scoreYear ?>">증빙 관리</a></div><?php elseif ($section === '6.1'): ?><div class="quick-links item-actions"><a class="button primary" href="bonus_activity.php?year=<?= $scoreYear ?>&amp;item=<?= $item['id'] ?>#<?= $item['id'] ?>">증빙 관리</a></div><?php endif; ?></div><details><summary><?= $escape($item['title']) ?></summary><div class="item-details"><div><h3>필요 증빙·확인 사항</h3><p class="pre"><?= $escape($item['evidence'] ?: '현장 상태 확인 항목 (원본에 별도 자료 기재 없음)') ?></p><?php if ($item['note']): ?><p class="hint"><?= $escape($item['note']) ?></p><?php endif; ?></div><?php if ($groupId !== '6'): ?><div><h3>원본 평가 수준별 기준</h3><dl><?php foreach ($item['criteria'] as $level => $text): ?><dt><?= $escape($level) ?></dt><dd><?= $escape($text ?: '—') ?></dd><?php endforeach; ?></dl></div><?php endif; ?></div></details></article><?php endforeach; ?></section><p class="hint">기존 화면으로 연결되는 기능과 추가 구현할 업무를 구분해 표시합니다. 평가점수는 자동 산정하지 않으며, 업무 화면의 기존 접근 권한이 적용됩니다.</p></div></div>
</main></body></html>
