<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/headcount_functions.php';
$user = csm_require_auth();
header('Cache-Control: no-store');
if (!auth_can_manage($user)) { http_response_code(403); exit('기준일 인원 조회는 인원관리 권한이 있는 계정만 사용할 수 있습니다.'); }
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$selectedDate = $_GET['date'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$team = $_GET['team'] ?? '';
$view = $_GET['view'] ?? 'relevant';
if (!is_string($selectedDate) || csm_headcount_date($selectedDate) !== $selectedDate || !is_string($team) || !is_string($view) || !in_array($view, ['relevant', 'confirmed', 'unknown', 'excluded', 'all'], true)) {
    http_response_code(400); exit('기준일과 조회 조건을 확인해 주세요.');
}
$rows = [];
$teams = [];
$error = '';
$counts = ['confirmed' => 0, 'unknown' => 0, 'excluded' => 0];
try {
    $employees = csm_headcount_rows();
    foreach ($employees as $employee) {
        $employee['team'] = trim((string)$employee['team']) ?: '팀 미등록';
        $teams[$employee['team']] = true;
        if ($team !== '' && $employee['team'] !== $team) continue;
        $employee = array_merge($employee, csm_headcount_classify($employee, $selectedDate));
        $counts[$employee['state']]++;
        if ($view === 'all' || ($view === 'relevant' && $employee['state'] !== 'excluded') || $view === $employee['state']) $rows[] = $employee;
    }
    ksort($teams);
    if ($team !== '' && !isset($teams[$team])) { http_response_code(400); $error = '선택한 팀을 찾을 수 없습니다. 전체 팀으로 다시 조회해 주세요.'; }
} catch (Throwable $exception) {
    http_response_code(500);
    $error = '인원 정보를 불러오지 못했습니다. 잠시 후 다시 조회해 주세요.';
    error_log('CSM headcount: ' . $exception->getMessage());
}
$labels = ['confirmed' => '재직 확인', 'unknown' => '확인 필요', 'excluded' => '집계 제외'];
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>기준일별 인원 조회 · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="headcount.css"></head><body>
<header><div><a href="/CSM/">← CSM 포털</a><strong>기준일별 인원 조회</strong><span><?= $escape($user['name']) ?></span></div></header>
<main><div class="heading"><div><small>인원관리 연계</small><h1>선택한 날짜의 재직 인원</h1><p>원하는 월과 날짜를 선택해 그날 기준으로 재직 여부를 조회합니다.</p></div><a class="button" href="/CSM/education/">안전보건교육</a></div>
<form class="panel headcount-filter" method="get"><label>기준일<input type="date" name="date" value="<?= $escape($selectedDate) ?>" required></label><label>현재 등록팀<select name="team"><option value="">전체 팀</option><?php foreach (array_keys($teams) as $name): ?><option value="<?= $escape($name) ?>" <?= $team === $name ? 'selected' : '' ?>><?= $escape($name) ?></option><?php endforeach; ?></select></label><label>명단 표시<select name="view"><?php foreach (['relevant' => '재직 확인 + 확인 필요', 'confirmed' => '재직 확인만', 'unknown' => '확인 필요한 인원만', 'excluded' => '집계 제외 인원만', 'all' => '전체 명단'] as $key => $label): ?><option value="<?= $key ?>" <?= $view === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="primary">기준일 조회</button></form>
<?php if ($error): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php else: ?>
<section class="headcount-metrics" aria-label="기준일 인원 집계"><div class="panel"><span>기준일</span><strong><?= $escape($selectedDate) ?></strong><small><?= $escape($team ?: '전체 팀') ?></small></div><div class="panel confirmed"><span>재직 확인</span><strong><?= $counts['confirmed'] ?>명</strong><small>등록된 날짜로 재직 확인</small></div><div class="panel unknown"><span>확인 필요</span><strong><?= $counts['unknown'] ?>명</strong><small>입사·퇴사일 누락 또는 불일치</small></div><div class="panel"><span>집계 제외</span><strong><?= $counts['excluded'] ?>명</strong><small>기준일 전 퇴사 또는 이후 입사</small></div></section>
<p class="<?= $counts['unknown'] ? 'error' : 'message' ?>" role="status"><?php if ($counts['unknown']): ?>정확한 재직 총원은 아직 확정할 수 없습니다. <?= $counts['confirmed'] ?>명은 재직이 확인되었으며, <?= $counts['unknown'] ?>명은 날짜 정보 보완이 필요합니다.<?php else: ?>등록된 입사·퇴사일 기준 재직 인원은 <?= $counts['confirmed'] ?>명입니다.<?php endif; ?></p>
<section class="panel"><div class="section-title"><h2>기준일 명단 <small><?= count($rows) ?>명 표시</small></h2><span>해당 팀의 검토 대상 <?= array_sum($counts) ?>명</span></div><div class="headcount-table"><table><thead><tr><th scope="col">사번</th><th scope="col">이름</th><th scope="col">현재 등록팀</th><th scope="col">입사일</th><th scope="col">퇴사일</th><th scope="col">기준일 판정</th><th scope="col">확인 내용</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= $escape($row['employee_no'] ?: '—') ?></td><td><a href="/employees/view.php?id=<?= (int)$row['id'] ?>"><?= $escape($row['name']) ?></a></td><td><?= $escape($row['team']) ?></td><td><?= $escape($row['join_date'] ?: '미등록') ?></td><td><?= $escape($row['retired_at'] ?: '—') ?></td><td><span class="status <?= $row['state'] ?>"><?= $labels[$row['state']] ?></span></td><td><?= $escape($row['reason']) ?></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7" class="empty">선택한 조건에 해당하는 인원이 없습니다.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
<details class="panel headcount-guide"><summary>집계 기준과 확인이 필요한 이력</summary><p>입사일은 포함하고 퇴사일은 제외합니다. 예를 들어 11월 9일 입사자는 11월 9일 인원에 포함되며, 11월 9일 퇴사자는 제외됩니다. 운영계정(admin01)은 제외합니다.</p><p>입사일이 없거나 퇴사 처리된 인원의 퇴사일이 없으면 임의로 재직에 포함하지 않습니다. 미래 입사 또는 이미 지난 퇴사가 날짜로 확인되는 경우에는 제외합니다.</p><p>현재 인원 DB에는 팀 이동·재입사·복직의 과거 구간 이력이 없습니다. 결과는 현재 저장된 입사·퇴사일을 기준으로 하며, 팀 필터도 현재 등록팀 기준입니다. 실제 출근 인원과는 구분됩니다.</p><p>정보를 보완하려면 명단의 이름을 눌러 기존 인원관리 화면에서 수정한 뒤 다시 조회하세요.</p></details>
</main></body></html>
