<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity_monitor_store.php';
require_once __DIR__ . '/scoring.php';
$user = csm_require_auth();
header('Cache-Control: no-store');
if (!leadership_allowed($user)) { http_response_code(403); exit('관리자만 사용할 수 있습니다.'); }
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_GET['year'] ?? $now->format('Y'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100) { http_response_code(400); exit('연도를 확인해 주세요.'); }
$kind = (string)($_GET['kind'] ?? 'month');
$number = filter_var($_GET['no'] ?? ($kind === 'quarter' ? min(4, (int)ceil((int)$now->format('n') / 3)) : min(12, (int)$now->format('n'))), FILTER_VALIDATE_INT);
if (!in_array($kind, ['month', 'quarter'], true) || !$number || $number < 1 || $number > ($kind === 'month' ? 12 : 4)) { http_response_code(400); exit('기간을 확인해 주세요.'); }
$url = 'activity_monitor.php?year=' . $year . '&kind=' . $kind . '&no=' . $number;
$_SESSION['activity_monitor_csrf'] ??= bin2hex(random_bytes(32));
$db = leadership_db();
$records = activity_monitor_records($db, $year);
$files = activity_monitor_files($db, $year);
$selected = $records[$kind][$number] ?? null;
if (isset($_GET['file'])) {
    $fileId = filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $file = null;
    foreach ($files as $recordFiles) foreach ($recordFiles as $candidate) if ((int)$candidate['id'] === $fileId && $candidate['deleted_at'] === null) $file = $candidate;
    $path = $file ? __DIR__ . '/storage/leadership_evidence/' . $file['stored_name'] : '';
    if (!$file || !preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', $file['stored_name']) || !is_file($path)) { http_response_code(404); exit('첨부파일을 찾을 수 없습니다.'); }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $file['mime']);
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($file['original_name']));
    readfile($path); exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['activity_monitor_csrf'], $_POST['csrf'])) throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침해 주세요.');
        $revision = filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 0 || $revision !== (int)($selected['revision'] ?? 0)) throw new InvalidArgumentException('기록이 변경되었습니다. 새로고침해 주세요.');
        if (isset($_POST['delete_file'])) {
            if (!$selected) throw new InvalidArgumentException('저장된 기록이 없습니다.');
            $fileId = filter_var($_POST['delete_file'], FILTER_VALIDATE_INT);
            leadership_change_file($db, $fileId ?: 0, (int)$selected['id'], $revision, '1.2.2', $year, 'delete_file', (string)$user['login_id']);
        } else {
            $date = trim((string)($_POST['activity_date'] ?? ''));
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Seoul'));
            if (!$parsed || $parsed->format('Y-m-d') !== $date || (int)$parsed->format('Y') !== $year || $parsed > $now) throw new InvalidArgumentException('해당 연도의 실제 점검·보고일을 입력해 주세요.');
            $summary = leadership_text($_POST['summary'] ?? '', '점검·보고 내용', 4000, true);
            $followup = leadership_text($_POST['followup'] ?? '', '후속조치', 4000);
            $reviewer = $kind === 'quarter' ? leadership_text($_POST['reviewer'] ?? '', '경영자', 150, true) : '';
            $uploads = leadership_uploads($_FILES['evidence'] ?? []);
            $snapshot = $kind === 'month' ? (activity_monitor_source($year)[$number] ?? null) : activity_monitor_quarter_source($year, $number);
            if (!$snapshot || !$snapshot['rows']) throw new InvalidArgumentException('분기 실적표에 해당 기간의 실적을 먼저 입력해 주세요.');
            $payload = ['kind' => $kind, 'number' => $number, 'summary' => $summary,
                'followup' => $followup, 'reviewer' => $reviewer, 'source_snapshot' => $snapshot];
            $label = $kind === 'month' ? $number . '월 실적 점검' : $number . '분기 활동계획 수행 검토';
            $record = ['item' => '1.2.2', 'year' => $year, 'activity_date' => $date,
                'title' => $year . '년 ' . $label, 'payload' => $payload];
            leadership_save($db, $record, (int)($selected['id'] ?? 0), $revision, $uploads, (string)$user['login_id']);
        }
        $_SESSION['activity_monitor_message'] = '기록을 저장했습니다.';
        header('Location: ' . $url, true, 303); exit;
    } catch (Throwable $ex) {
        http_response_code($ex instanceof InvalidArgumentException ? 422 : 500);
        $error = $ex instanceof InvalidArgumentException ? $ex->getMessage() : '저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        if (!($ex instanceof InvalidArgumentException)) error_log('Activity monitor: ' . $ex->getMessage());
    }
}
$message = $_SESSION['activity_monitor_message'] ?? ''; unset($_SESSION['activity_monitor_message']);
$source = []; $sourceError = '';
try { $source = activity_monitor_source($year); }
catch (Throwable $ex) { $sourceError = '분기 실적표를 불러오지 못했습니다.'; error_log('Activity monitor source: ' . $ex->getMessage()); }
$score = csm_activity_monitor_summary($db, $year, 2.0);
$recordFiles = $selected ? activity_monitor_verified_files($files[(int)$selected['id']] ?? []) : [];
$isPrint = ($_GET['print'] ?? '') === '1';
$label = $kind === 'month' ? $number . '월 월별 실적 점검' : $number . '분기 활동계획 수행 검토';
$displaySource = $kind === 'month' ? ($source[$number] ?? ($selected['data']['source_snapshot'] ?? null))
    : ($selected['data']['source_snapshot'] ?? null);
if ($kind === 'quarter' && $displaySource === null) {
    try { $displaySource = activity_monitor_quarter_source($year, $number); }
    catch (Throwable $ex) { $sourceError = '분기 실적표를 불러오지 못했습니다.'; error_log('Activity monitor quarter source: ' . $ex->getMessage()); }
}
if (isset($displaySource['rows']) && is_array($displaySource['rows'])) {
    require_once __DIR__ . '/../safety_manual/quarter_plan_storage.php';
    $unitsByName = [];
    foreach (quarter_plan_template()['rows'] as $templateRow) {
        if (($templateRow['type'] ?? '') === 'item') $unitsByName[$templateRow['name']] = $templateRow['unit'];
    }
    foreach ($displaySource['rows'] as &$item) {
        $item['plan'] = activity_monitor_display_quantity($item, 'plan');
        $actualKey = $kind === 'month' ? 'value' : 'result';
        $item[$actualKey] = activity_monitor_display_quantity($item, $actualKey);
        if (isset($unitsByName[$item['name'] ?? ''])) $item['unit'] = $unitsByName[$item['name']];
    }
    unset($item);
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>1.2.2 활동계획 실적 점검·보고 · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="activity_monitor.css?v=5"></head><body class="<?= $isPrint ? 'print-view' : '' ?> <?= $kind === 'month' ? 'month-report' : 'quarter-report' ?>">
<header><div><a href="management_system.php?section=1.2&amp;year=<?= $year ?>">← 안전보건활동</a><strong>1.2.2 활동계획 실적 점검·보고</strong><span><?= $e($user['name'] ?? '') ?></span></div></header>
<main>
<div class="heading screen-only"><div><small>안전보건활동계획 실행 확인</small><h1><?= $year ?>년 실적 점검·경영자 검토</h1><p>월별 수행 실적을 점검하고, 분기별 안전보건활동계획의 수행 결과를 경영자가 검토합니다. 안전보건 방침 검토와는 별개의 기록입니다.</p></div><form method="get"><label for="year">연도</label><input id="year" name="year" type="number" min="2000" max="2100" value="<?= $year ?>"><button>조회</button></form></div>
<?php if ($error): ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif; ?><?php if ($message): ?><p class="message" role="status"><?= $e($message) ?></p><?php endif; ?>
<section class="panel screen-only monthly-nav"><div class="section-title"><h2>월별 안전보건활동계획 실적</h2><a class="button" href="../safety_manual/goal_plan_quarter.php?year=<?= $year ?>&amp;quarter=<?= intdiv($number-1,3)+1 ?>">선택 월의 계획·실적 입력 ↗</a></div><div class="periods"><?php for ($m=1;$m<=12;$m++): $row=$records['month'][$m]??null; $verified=$row ? count(activity_monitor_verified_files($files[(int)$row['id']]??[])) : 0; ?><a href="activity_monitor.php?year=<?= $year ?>&amp;kind=month&amp;no=<?= $m ?>#report" <?= $kind==='month'&&$number===$m?'aria-current="page"':'' ?>><strong><?= $m ?>월</strong><span><?= empty($source[$m]['rows']) ? '월 계획 미입력' : ($verified ? '점검 증빙 완료' : ($row ? '실적 점검 기록' : '실적 보기')) ?></span></a><?php endfor; ?></div></section>
<section class="panel overview screen-only"><div><div><small>현재 반영점수</small><h2><?= $e($score['earned']) ?> / 2점</h2><p>증빙이 없는 기록은 점수에 포함하지 않습니다.</p></div><div><small>월별 점검</small><h2><?= $score['monthly_count'] ?> / <?= $score['cutoff'] ?>개월</h2><p>현재 월까지의 증빙 확인</p></div><div><small>분기별 경영자 검토</small><h2><?= $score['report_count'] ?> / 4건</h2><p>수행 실적 검토 증빙 확인</p></div></div><p class="hint">매월 점검과 완료된 분기의 경영자 검토 증빙이 모두 있으면 2점입니다. 분기 검토 기록이 상·하반기에 걸쳐 있으면 반기 보고 수준 1.8점, 연 1회 검토는 1.6점, 일부 점검·보고만 확인되면 1점입니다. 증빙이 없으면 0점입니다.</p></section>
<section class="panel screen-only"><div class="section-title"><h2>분기별 경영자 수행 검토</h2><span class="hint">안전보건활동계획서의 계획 대비 수행 실적을 검토합니다.</span></div><div class="periods quarters"><?php for($q=1;$q<=4;$q++): $row=$records['quarter'][$q]??null; $verified=$row ? count(activity_monitor_verified_files($files[(int)$row['id']]??[])) : 0; ?><a href="activity_monitor.php?year=<?= $year ?>&amp;kind=quarter&amp;no=<?= $q ?>" <?= $kind==='quarter'&&$number===$q?'aria-current="page"':'' ?>><strong><?= $q ?>분기</strong><span><?= $verified ? '검토 증빙 완료' : ($row ? '검토 증빙 필요' : '미작성') ?></span></a><?php endfor; ?></div></section>
<section class="panel report" id="report"><div class="activity-document-box"><div class="section-title"><h2><?= $year ?>년 <?= $e($label) ?></h2><div class="actions"><?php if($isPrint): ?><button type="button" onclick="window.print()">인쇄 / PDF 저장</button><?php else: ?><a class="button" href="<?= $e($url) ?>&amp;print=1#report" target="_blank" rel="noopener"><?= $kind === 'month' ? '해당 월 실적' : '분기 검토' ?> 인쇄 미리보기</a><?php endif; ?></div></div>
<div class="review-note"><?php if($selected): ?><p class="report-meta"><strong>점검·검토일</strong> <?= $e($selected['activity_date']) ?><?php if($kind==='quarter'): ?>　<strong>검토 경영자</strong> <?= $e($selected['data']['reviewer']??'') ?><?php endif; ?></p><h3><?= $kind==='month'?'월별 실적 점검 내용':'안전보건활동계획 수행 검토 의견' ?></h3><p class="pre report-text"><?= $e($selected['data']['summary']??'') ?></p><h3>후속조치</h3><p class="pre report-text"><?= $e(($selected['data']['followup']??'')?:'없음') ?></p><?php elseif($kind==='quarter'): ?><p class="empty">해당 분기의 경영자 검토 기록이 없습니다.</p><?php endif; ?></div>
<?php if($kind==='month'): ?><h3><?= $number ?>월 안전보건활동계획 실적</h3><?php if(!$displaySource && $sourceError): ?><p class="error"><?= $e($sourceError) ?></p><?php elseif(empty($displaySource['rows'])): ?><p class="hint">해당 월의 계획이 입력되지 않았습니다. <a href="../safety_manual/goal_plan_quarter.php?year=<?= $year ?>&amp;quarter=<?= intdiv($number-1,3)+1 ?>">분기 계획·실적표에서 <?= $number ?>월 계획을 입력</a>하면 여기에 계획 대비 실적이 표시됩니다.</p><?php else: ?><p class="hint">분기 계획표에 입력된 <?= $number ?>월 계획 항목 <?= count($displaySource['rows']) ?>개를 표시합니다. 실적이 비어 있으면 미입력으로 표시합니다.</p><div class="source-list quarter-list monthly-results"><div class="source-head"><strong>활동 항목</strong><strong><?= $number ?>월 계획</strong><strong><?= $number ?>월 실적</strong></div><?php foreach($displaySource['rows'] as $item): ?><div><span><?= $e($item['name']) ?></span><span><?= $e($item['plan']) ?> <?= $e($item['unit']) ?></span><strong><?= $e($item['value'] !== '' ? $item['value'] : '미입력') ?> <?= $item['value'] !== '' ? $e($item['unit']) : '' ?></strong></div><?php endforeach; ?></div><?php endif; ?><?php endif; ?>
<?php if($kind==='quarter'): ?><h3>검토 대상 · <?= $number ?>분기 안전보건활동계획 수행 실적</h3><?php if(!$displaySource && $sourceError): ?><p class="error"><?= $e($sourceError) ?></p><?php elseif(empty($displaySource['rows'])): ?><p class="hint">분기 실적표에 입력된 계획·수행 결과가 없습니다.</p><?php else: ?><p class="hint"><?= $selected && isset($selected['data']['source_snapshot']) ? '경영자 검토 기록 저장 당시의 실적입니다.' : '분기 실적표의 현재 값입니다.' ?> 항목별 계획과 수행 결과를 대조해 검토합니다.</p><div class="source-list quarter-list"><div class="source-head"><strong>활동 항목</strong><strong>분기 계획</strong><strong>수행 실적</strong></div><?php foreach($displaySource['rows'] as $item): ?><div><span><?= $e($item['name']) ?></span><span><?= $e($item['plan']) ?> <?= $e($item['unit']) ?></span><strong><?= $e($item['result']) ?> <?= $e($item['unit']) ?></strong></div><?php endforeach; ?></div><?php endif; ?><?php endif; ?>
<div class="evidence-section"><h3>첨부 증빙</h3><?php if(!$recordFiles): ?><p class="hint">증빙이 첨부되지 않았습니다. 출력한 실적 점검표나 서명된 보고서를 첨부해 주세요.</p><?php endif; ?><div class="attachments"><?php foreach($recordFiles as $file): ?><div><a href="activity_monitor.php?year=<?= $year ?>&amp;file=<?= $file['id'] ?>" target="_blank" rel="noopener"><?= $e($file['original_name']) ?> ↗</a><?php if(!$isPrint): ?><form method="post" action="<?= $e($url) ?>" onsubmit="return confirm('첨부파일을 삭제할까요?')"><input type="hidden" name="csrf" value="<?= $e($_SESSION['activity_monitor_csrf']) ?>"><input type="hidden" name="revision" value="<?= (int)($selected['revision']??0) ?>"><button name="delete_file" value="<?= $file['id'] ?>">삭제</button></form><?php endif; ?></div><?php endforeach; ?></div></div></div><footer class="activity-print-footer"><img src="assets/policy-logo.png" alt="현대기전 로고"><strong>주식회사 현대기전</strong></footer></section>
<?php if(!$isPrint): ?><section class="panel editor"><h2><?= $e($label) ?> <?= $selected?'수정':'작성' ?></h2><form method="post" action="<?= $e($url) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $e($_SESSION['activity_monitor_csrf']) ?>"><input type="hidden" name="revision" value="<?= (int)($selected['revision']??0) ?>"><div class="fields"><label>점검·검토일<input type="date" name="activity_date" required max="<?= $now->format('Y-m-d') ?>" value="<?= $e($selected['activity_date']??'') ?>"></label><?php if($kind==='quarter'): ?><label>검토 경영자<input name="reviewer" maxlength="150" required value="<?= $e($selected['data']['reviewer']??'') ?>"></label><?php endif; ?><label class="wide"><?= $kind==='month'?'점검 결과 · 목표 대비 실적, 미달 사유':'분기 수행 검토 · 계획 대비 실적, 미달 사유, 개선 결정' ?><textarea name="summary" rows="5" maxlength="4000" required><?= $e($selected['data']['summary']??'') ?></textarea></label><label class="wide">후속조치 · 담당자, 기한<textarea name="followup" rows="3" maxlength="4000"><?= $e($selected['data']['followup']??'') ?></textarea></label><label class="wide">증빙 첨부 · 서명된 검토서 등 PDF/JPG/PNG, 파일당 10MB<input type="file" name="evidence[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label></div><p class="hint">기존 첨부파일은 유지됩니다. 분기 경영자 검토에는 해당 분기 실적표의 계획·수행 결과가 연결됩니다. 점수 반영에는 확인 가능한 증빙이 필요합니다.</p><button class="primary">기록 저장</button></form></section><?php endif; ?>
</main></body></html>
