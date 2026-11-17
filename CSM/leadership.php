<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/scoring.php';
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$user = csm_require_auth();
header('Cache-Control: no-store');
if (!leadership_allowed($user)) {
    http_response_code(403);
    exit('<!doctype html><html lang="ko"><meta charset="utf-8"><p>1.1 리더십 기록은 기존 경영방침과 동일하게 지정된 관리자만 관리할 수 있습니다.</p><a href="/CSM/">CSM 포털로 돌아가기</a></html>');
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year = filter_var($_POST['year'] ?? $_GET['year'] ?? substr($today, 0, 4), FILTER_VALIDATE_INT);
$item = $_POST['item'] ?? $_GET['item'] ?? '1.1.1';
$titles = ['1.1.1' => '방침·목표 공표', '1.1.2' => '서명·근로자 전달', '1.1.3' => 'VFL·개선조치', '1.1.4' => '대표자 협의회'];
if (!$year || $year < 2000 || $year > 2100 || !is_string($item) || !isset($titles[$item])) {
    http_response_code(400);
    exit('연도와 평가항목을 확인해 주세요.');
}
$base = '/CSM/leadership.php?year=' . $year . '&item=' . $item;
$allMonthsBase = $base;
$selectedMonth = null;
if (in_array($item, ['1.1.3', '1.1.4'], true) && isset($_GET['month'])) {
    $selectedMonth = filter_var($_GET['month'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    if ($selectedMonth === false) { http_response_code(400); exit('조회할 월은 1~12월로 선택해 주세요.'); }
    $base .= '&month=' . $selectedMonth;
}
$error = '';
$message = $_SESSION['leadership_message'] ?? '';
unset($_SESSION['leadership_message']);
$_SESSION['leadership_csrf'] ??= bin2hex(random_bytes(32));
$db = null;
$records = [];
$allRecords = [];
$files = [];
$deletedFiles = [];
$history = [];
$id = filter_var($_POST['id'] ?? $_GET['edit'] ?? 0, FILTER_VALIDATE_INT);
$form = ['activity_date' => $year === (int)substr($today, 0, 4) ? $today : $year . '-01-01', 'title' => '', 'owner' => '', 'location' => '', 'notes' => '', 'signed_date' => '', 'audience' => '', 'attendance' => '', 'actions' => []];
$form += ['vfl_time' => '', 'vfl_companions' => '', 'vfl_dialog_count' => '', 'vfl_dialogue' => '', 'vfl_checks' => [], 'vfl_focus_notes' => ''];
$form['vfl_photo_captions'] = [1 => '', 2 => ''];
$revision = 0;
if ($id === false || $id < 0) { http_response_code(400); exit('기록 번호를 확인해 주세요.'); }
try {
    $db = leadership_db();
    if (isset($_GET['file'])) {
        $fileId = filter_var($_GET['file'], FILTER_VALIDATE_INT);
        $statement = $db->prepare('SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE f.id=? AND f.deleted_at IS NULL AND r.item=? AND r.year=?');
        $statement->execute([$fileId ?: 0, $item, $year]);
        $file = $statement->fetch();
        $path = $file ? __DIR__ . '/storage/leadership_evidence/' . $file['stored_name'] : '';
        if (!$file || !is_file($path)) { http_response_code(404); exit('증빙 파일을 찾을 수 없습니다.'); }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $file['mime']);
        $inline = ($_GET['inline'] ?? '') === '1' && (
            ($file['photo_slot'] !== null && in_array($file['mime'], ['image/jpeg', 'image/png'], true))
            || ($item === '1.1.4' && $file['mime'] === 'application/pdf')
        );
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"evidence\"; filename*=UTF-8''" . rawurlencode($file['original_name']));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ($id > 0) {
        $statement = $db->prepare('SELECT * FROM leadership_records WHERE id=? AND item=? AND year=?');
        $statement->execute([$id, $item, $year]);
        $saved = $statement->fetch();
        if (!$saved) { http_response_code(404); exit('기록을 찾을 수 없습니다.'); }
        $form = array_merge($form, json_decode($saved['payload'], true, 512, JSON_THROW_ON_ERROR), ['activity_date' => $saved['activity_date'], 'title' => $saved['title']]);
        $revision = (int)$saved['revision'];
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['leadership_csrf'], $_POST['csrf'])) throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침 후 다시 저장해 주세요.');
        if (($_POST['form_action'] ?? '') === 'upload_signed_scan') {
            if ($item !== '1.1.3' || $id < 1 || !isset($saved)) throw new InvalidArgumentException('서명본을 첨부할 활동기록을 확인해 주세요.');
            $postedRevision = filter_var($_POST['revision'] ?? -1, FILTER_VALIDATE_INT);
            if ($postedRevision === false || $postedRevision < 1) throw new InvalidArgumentException('기록의 수정 정보를 확인해 주세요.');
            $uploads = leadership_uploads($_FILES['signed_scan'] ?? []);
            if (!$uploads) throw new InvalidArgumentException('서명된 스캔 파일을 선택해 주세요.');
            foreach ($uploads as &$upload) $upload['file_kind'] = 'signed_scan';
            unset($upload);
            $record = ['item' => $item, 'year' => $year, 'activity_date' => $saved['activity_date'], 'title' => $saved['title'], 'payload' => json_decode($saved['payload'], true, 512, JSON_THROW_ON_ERROR)];
            leadership_save($db, $record, $id, $postedRevision, $uploads, (string)$user['login_id'], true);
            $_SESSION['leadership_message'] = '해당 활동기록에 서명본 스캔 파일을 첨부했습니다.';
            header('Location: ' . $base . '#record-' . $id, true, 303);
            exit;
        }
        if (in_array($_POST['form_action'] ?? '', ['assess', 'assess_month'], true)) {
            $assessmentRevision = filter_var($_POST['assessment_revision'] ?? -1, FILTER_VALIDATE_INT);
            if ($assessmentRevision === false || $assessmentRevision < 0) throw new InvalidArgumentException('평가 정보를 확인해 주세요.');
            $assessmentMonth = null;
            if (($_POST['form_action'] ?? '') === 'assess_month') {
                $assessmentMonth = filter_var($_POST['assessment_month'] ?? 0, FILTER_VALIDATE_INT);
                if ($item !== '1.1.3' || !$assessmentMonth) throw new InvalidArgumentException('평가 대상 월을 확인해 주세요.');
            }
            leadership_save_assessment($db, $catalog, $year, $item, $_POST['multiplier'] ?? null, $assessmentRevision, (string)$user['login_id'], $assessmentMonth);
            $_SESSION['leadership_message'] = ($assessmentMonth !== null ? $assessmentMonth . '월 ' : '') . '평가 수준을 저장했습니다. 유효한 첨부 증빙이 있는 동안에만 점수가 반영됩니다.';
            if ($assessmentMonth !== null) { header('Location: ' . $allMonthsBase . '&month=' . $assessmentMonth . '#monthly-vfl', true, 303); exit; }
            header('Location: ' . $base, true, 303);
            exit;
        }
        if (isset($_POST['file_action'])) {
            $action = $_POST['file_action'];
            $fileId = filter_var($_POST['file_id'] ?? 0, FILTER_VALIDATE_INT);
            $fileRevision = filter_var($_POST['revision'] ?? -1, FILTER_VALIDATE_INT);
            if (!is_string($action) || !$fileId || $fileRevision === false || $fileRevision < 1) throw new InvalidArgumentException('첨부파일 요청을 확인해 주세요.');
            leadership_change_file($db, $fileId, $id, $fileRevision, $item, $year, $action, (string)$user['login_id']);
            $_SESSION['leadership_message'] = $action === 'delete_file' ? '첨부파일을 삭제했습니다. 삭제된 첨부파일에서 복원할 수 있습니다.' : '첨부파일을 복원했습니다.';
            header('Location: ' . $base, true, 303);
            exit;
        }
        $record = leadership_validate($_POST, $item, $year);
        $uploads = leadership_uploads($_FILES['evidence'] ?? []);
        if ($item === '1.1.3') $uploads = array_merge($uploads, leadership_photo_uploads($_FILES['photo'] ?? []));
        $postedRevision = filter_var($_POST['revision'] ?? 0, FILTER_VALIDATE_INT);
        if ($postedRevision === false || $postedRevision < 0) throw new InvalidArgumentException('수정 정보를 확인해 주세요.');
        leadership_save($db, $record, $id, $postedRevision, $uploads, (string)$user['login_id']);
        $_SESSION['leadership_message'] = '활동 기록과 첨부 증빙을 저장했습니다.';
        header('Location: ' . $base, true, 303);
        exit;
    }
} catch (Throwable $exception) {
    if ($exception instanceof InvalidArgumentException) {
        $error = $exception->getMessage();
        http_response_code(422);
    } else {
        $error = '활동 기록을 불러오거나 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        error_log('CSM leadership: ' . $exception->getMessage());
        http_response_code(500);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach (array_keys($form) as $key) {
            if (in_array($key, ['actions', 'vfl_checks', 'vfl_photo_captions'], true)) {
                $form[$key] = is_array($_POST[$key] ?? null) ? array_slice($_POST[$key], 0, 30, true) : [];
            } elseif (is_string($_POST[$key] ?? null)) {
                $form[$key] = $_POST[$key];
            }
        }
    }
}
if ($db) {
    try {
        $statement = $db->prepare("SELECT * FROM leadership_records WHERE year=? AND item IN ('1.1.1','1.1.2','1.1.3','1.1.4') ORDER BY activity_date DESC,id DESC");
        $statement->execute([$year]);
        foreach ($statement->fetchAll() as $row) {
            $row['data'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $allRecords[] = $row;
            if ($row['item'] === $item) $records[] = $row;
        }
        $statement = $db->prepare('SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE r.year=? AND r.item=? ORDER BY f.id');
        $statement->execute([$year, $item]);
        foreach ($statement->fetchAll() as $file) {
            if ($file['deleted_at'] !== null) $deletedFiles[$file['record_id']][] = $file;
            else $files[$file['record_id']][] = $file;
        }
        if ($id > 0) {
            $statement = $db->prepare('SELECT * FROM leadership_history WHERE record_id=? ORDER BY revision DESC');
            $statement->execute([$id]);
            $history = $statement->fetchAll();
        }
    } catch (Throwable $exception) {
        $error = '기록 조회에 실패했습니다. 저장 전 화면을 새로고침해 주세요.';
        error_log('CSM leadership listing: ' . $exception->getMessage());
    }
}
$counts = array_fill_keys(array_keys($titles), 0);
$formPhotos = [];
foreach ($files[$id] ?? [] as $file) if ($file['photo_slot'] !== null) $formPhotos[(int)$file['photo_slot']] = $file;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    foreach ([1, 2] as $slot) $form['vfl_photo_captions'][$slot] = $formPhotos[$slot]['caption'] ?? '';
}
$vflMonths = array_fill(1, 12, 0);
$meetings = [];
$actionCount = $doneCount = $overdueCount = 0;
foreach ($allRecords as $row) {
    $counts[$row['item']]++;
    if ($row['item'] === '1.1.3') {
        $vflMonths[(int)substr($row['activity_date'], 5, 2)]++;
        foreach ($row['data']['actions'] as $action) {
            $actionCount++;
            if ($action['completed'] !== '') $doneCount++;
            elseif ($action['due'] < $today) $overdueCount++;
        }
    }
    if ($row['item'] === '1.1.4') $meetings[(int)substr($row['activity_date'], 5, 2)] = $row['data']['attendance'];
}
$attendanceLabels = ['representative' => '대표자 참석', 'delegate' => '대리 참석', 'absent' => '불참', 'not_held' => '미개최'];
$held = array_filter($meetings, static fn($value) => $value !== 'not_held');
$credits = array_sum(array_map(static fn($value) => $value === 'representative' ? 1 : ($value === 'delegate' ? 0.5 : 0), $held));
$criterion = array_values(array_filter($catalog['modules'][0]['items'], static fn($criterion) => $criterion['id'] === $item))[0];
$leadershipScores = $db ? csm_score_summary($db, $catalog, $year)['items'] : [];
$itemScore = $leadershipScores[$item] ?? null;
$vflMonthly = $item === '1.1.3' ? ($itemScore['monthly'] ?? csm_vfl_monthly_summary($db, $year)) : null;
$policy = null;
$policyError = '';
if ($item === '1.1.1' || $item === '1.1.2') {
    try {
        require_once dirname(__DIR__) . '/risk_assessment/db_config.php';
        require_once dirname(__DIR__) . '/safety_manual/policy_storage.php';
        $policy = safety_policy_load(getDB(), $year);
    } catch (Throwable $exception) {
        $policyError = '기존 경영방침을 불러오지 못했습니다. 경영방침 화면에서 확인해 주세요.';
        error_log('CSM leadership policy read: ' . $exception->getMessage());
    }
}
$hasAnyRecords = count($records) > 0;
if ($selectedMonth !== null) $records = array_values(array_filter($records, static fn(array $record): bool => (int)substr($record['activity_date'], 5, 2) === $selectedMonth));
if (isset($_GET['export'])) {
    if ($_GET['export'] !== 'xlsx' || $item !== '1.1.3') { http_response_code(400); exit('VFL 엑셀 다운로드 요청을 확인해 주세요.'); }
    if ($error !== '') { http_response_code(500); exit('기록을 불러오지 못해 엑셀을 생성할 수 없습니다.'); }
    if (!$records) { http_response_code(404); exit('다운로드할 VFL 기록이 없습니다.'); }
    $temporaryExport = tempnam(sys_get_temp_dir(), 'csm-vfl-');
    try {
        if ($temporaryExport === false) throw new RuntimeException('Cannot allocate VFL export');
        require_once __DIR__ . '/leadership_vfl_excel.php';
        vfl_excel_build($temporaryExport, $records, $files);
        $exportName = 'VFL_' . $year . ($selectedMonth !== null ? '_' . sprintf('%02d', $selectedMonth) : '_전체') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="VFL_' . $year . '.xlsx"; filename*=UTF-8\'\'' . rawurlencode($exportName));
        header('Content-Length: ' . filesize($temporaryExport));
        readfile($temporaryExport);
    } catch (Throwable $exception) {
        http_response_code(500);
        error_log('CSM VFL export: ' . $exception->getMessage());
        echo '엑셀 파일을 생성하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    } finally {
        if (is_string($temporaryExport) && is_file($temporaryExport)) unlink($temporaryExport);
    }
    exit;
}
$openForm = $id > 0 || $error !== '' || (!$hasAnyRecords && $selectedMonth === null);
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>1.1 경영자 리더십 · CSM</title><link rel="stylesheet" href="leadership.css?v=20261110-meeting-pdf"><?php if ($item === '1.1.3'): ?><link rel="stylesheet" href="leadership_vfl_print.css?v=20261110-binding-margin"><?php elseif ($item === '1.1.4'): ?><link rel="stylesheet" href="leadership_meeting_print.css?v=20261110-meeting-padding"><?php endif; ?></head><body class="<?= $item === '1.1.3' ? 'vfl-print-mode' : ($item === '1.1.4' ? 'meeting-print-mode' : '') ?>">
<header><div><a href="/CSM/management_system.php?section=1.1">← 안전보건관리체계</a><strong>1.1 경영자 리더십</strong><span><?= $escape($user['name']) ?></span></div></header>
<main><div class="heading"><div><small>안전보건관리체계 · 기본 8점</small><h1>경영자 리더십 활동관리</h1><p>방침을 수립하고 전달한 기록부터 현장관찰·협의회 참여까지 관리합니다.</p></div><form method="get"><input type="hidden" name="item" value="<?= $escape($item) ?>"><label for="year">관리 연도</label><input id="year" name="year" type="number" min="2000" max="2100" value="<?= $year ?>" required><button>조회</button></form></div>
<nav class="tabs" aria-label="1.1 세부 평가항목"><?php foreach ($titles as $code => $title): ?><a href="?year=<?= $year ?>&amp;item=<?= $code ?>" <?= $item === $code ? 'aria-current="page"' : '' ?>><small><?= $code ?> · 배점 2점 · 현재 점수 <?= isset($leadershipScores[$code]) ? $leadershipScores[$code]['earned'] . "점" : "조회 불가" ?></small><strong><?= $escape($title) ?></strong><span>활동 기록 <?= $counts[$code] ?>건<?= $code === '1.1.4' ? ' · 하반기 평가' : '' ?></span></a><?php endforeach; ?></nav>
<?php if ($message): ?><p class="message" role="status"><?= $escape($message) ?></p><?php endif; ?><?php if ($error): ?><p class="error" role="alert"><?= $escape($error) ?><?php if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'): ?> 첨부 파일은 다시 선택해 주세요.<?php endif; ?></p><?php endif; ?>
<section class="panel criteria"><div><strong><?= $item ?> <?= $escape($titles[$item]) ?></strong><p><?= $escape($criterion['title']) ?></p></div><details><summary>평가 기준·필요 증빙</summary><p class="pre"><?= $escape($criterion['evidence']) ?></p><dl><?php foreach ($criterion['criteria'] as $level => $text): ?><dt><?= $escape($level) ?></dt><dd><?= $escape($text) ?></dd><?php endforeach; ?></dl><?php if ($criterion['note']): ?><p><?= $escape($criterion['note']) ?></p><?php endif; ?></details></section>
<?php if ($itemScore !== null): ?>
<section class="panel score-panel" data-earned-score="<?= $itemScore['earned'] ?>" data-evidence-count="<?= $itemScore['evidence_count'] ?>"><div class="section-title"><h2>반영 점수 <?= $itemScore['earned'] ?> / <?= $criterion['points'] ?>점</h2><span><?= $item === '1.1.4' ? ($itemScore['evidence_count'] > 0 ? '참석 기록·증빙 자동 반영' : '증빙 미첨부 · 0점') : ['no_evidence' => '증빙 미첨부 · 0점', 'pending' => '증빙 첨부 · 평가 대기', 'assessed' => '증빙 확인 후 입력한 평가 반영'][$itemScore['state']] ?></span></div><p class="hint"><?php if ($item === '1.1.4'): ?>증빙이 있는 대표자 참석은 100%, 대리 참석은 50%로 계산하고, 참석률에 따라 원본 평가기준의 점수를 자동 반영합니다. 올해는 1월부터 현재 월까지의 전체 개월 수를 기준으로 계산합니다. 미등록·증빙 미첨부·미개최 월은 0%로 포함하고, 미래 월은 제외합니다. 지난 연도는 12개월 기준입니다.<?php else: ?>이 연도·평가항목에 유효한 첨부파일이 없으면 저장된 평가가 있어도 0점입니다. 첨부만으로 점수를 부여하지 않으며, 마지막 증빙을 삭제하면 점수가 제외됩니다.<?php endif; ?></p>
<?php if (in_array($item, ['1.1.1', '1.1.2'], true)): ?><form method="post" action="<?= $escape($base) ?>" class="score-form"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['leadership_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="item" value="<?= $item ?>"><input type="hidden" name="form_action" value="assess"><input type="hidden" name="assessment_revision" value="<?= $itemScore['revision'] ?>"><label>증빙 검토 후 평가 수준<select name="multiplier" required <?= $itemScore['evidence_count'] === 0 ? 'disabled' : '' ?>><option value="">평가 수준 선택</option><?php foreach ($criterion['criteria'] as $level => $label): if (!is_numeric($level) || $label === '' || $label === '-') continue; ?><option value="<?= $escape($level) ?>" <?= $itemScore['multiplier'] !== null && (float)$level === $itemScore['multiplier'] ? 'selected' : '' ?>><?= $escape(str_replace("\n", ' ', $label)) ?> · <?= $criterion['points'] * (float)$level ?>점</option><?php endforeach; ?></select></label><button class="primary" <?= $itemScore['evidence_count'] === 0 ? 'disabled' : '' ?>>평가 저장</button></form><?php elseif ($item === '1.1.3'): ?><?php if ($vflMonthly['cutoff'] > 0): ?><p class="vfl-average">1월~<?= $vflMonthly['cutoff'] ?>월 합계 <?= $vflMonthly['total'] ?>점 ÷ <?= $vflMonthly['cutoff'] ?>개월 = <strong><?= $vflMonthly['earned'] ?>점</strong></p><?php else: ?><p class="vfl-average">아직 평가 대상 연도가 아닙니다. 반영점수 0점</p><?php endif; ?><p class="hint">올해는 현재 월까지, 지난 연도는 12개월을 평균합니다. 미평가·서명본 미첨부 월은 0점으로 포함하고, 미래 월은 제외합니다. 평가수준은 아래 월별 VFL 실시 현황에서 저장하세요.</p><?php else: ?><p class="vfl-average">참석률 <strong><?= $itemScore['attendance_rate'] ?>%</strong> · <?= $itemScore['cutoff'] > 0 ? '1월~' . $itemScore['cutoff'] . '월 ' . $itemScore['cutoff'] . '개월 기준' : '아직 평가 대상 연도가 아닙니다' ?></p><?php endif; ?></section>
<?php endif; ?>
<?php if ($item === '1.1.1' || $item === '1.1.2'): ?>
<section class="panel policy"><div class="section-title"><h2><?= $year ?>년 경영방침·목표</h2><a class="button" href="/safety_manual/management_policy.php">경영방침 작성·수정 ↗</a></div><?php if ($policyError): ?><p class="muted"><?= $escape($policyError) ?></p><?php elseif (!$policy): ?><p class="muted">해당 연도의 저장된 경영방침이 없습니다. 경영방침 화면에서 먼저 작성해 주세요.</p><?php else: ?><p>방침 작성일: <?= $escape($policy['date'] ?: '미입력') ?></p><details><summary>저장된 방침·목표 확인</summary><p class="pre"><?= $escape($policy['policy']) ?></p><ol><?php foreach ($policy['goals'] as $goal): if (trim($goal) === '') continue; ?><li><?= $escape($goal) ?></li><?php endforeach; ?></ol></details><?php endif; ?><p class="hint">경영방침 원문은 기존 화면에서 관리합니다. 아래에는 <?= $item === '1.1.1' ? '공표·게시한 날짜, 장소와 사진' : '대표자 서명일, 전달 대상·방법과 서명본' ?>을 기록하세요.</p></section>
<?php endif; ?>
<?php if ($item === '1.1.3' || $item === '1.1.4'): ?>
<section class="panel" id="monthly-vfl"><div class="section-title"><h2><?= $item === '1.1.3' ? '월별 VFL 실시 현황' : '월별 협의회 참석 현황' ?></h2><?php if ($item === '1.1.3'): ?><span>개선 <?= $doneCount ?>/<?= $actionCount ?>건 완료 · 기한 초과 <?= $overdueCount ?>건</span><?php else: ?><span><?= count($held) ?>개 개최월 기록상 참석률 <?= count($held) ? number_format($credits / count($held) * 100, 1) . '%' : '미산정' ?></span><?php endif; ?></div><div class="months"><?php for ($month = 1; $month <= 12; $month++): ?><?php if ($item === '1.1.3'): ?><a href="<?= $escape($allMonthsBase) ?>&amp;month=<?= $month ?>#records" <?= $selectedMonth === $month ? 'aria-current="true"' : '' ?> aria-label="<?= $month ?>월 VFL 기록 <?= $vflMonths[$month] ?>건 보기"><small><?= $month ?>월</small><strong><?= $vflMonths[$month] ?>회</strong><span class="month-score"><?= $vflMonthly['months'][$month]['earned'] ?> / <?= $criterion['points'] ?>점</span><span class="month-rating-state"><?= ['future' => '평가 전', 'no_evidence' => '서명본 없음', 'pending' => '미평가', 'assessed' => '수준 ' . $vflMonthly['months'][$month]['multiplier']][$vflMonthly['months'][$month]['state']] ?></span></a><?php else: ?><a href="<?= $escape($allMonthsBase) ?>&amp;month=<?= $month ?>#records" <?= $selectedMonth === $month ? 'aria-current="true"' : '' ?> aria-label="<?= $month ?>월 협의회 기록 보기"><small><?= $month ?>월</small><strong><?= $escape(isset($meetings[$month]) ? $attendanceLabels[$meetings[$month]] : '미등록') ?></strong></a><?php endif; ?><?php endfor; ?></div><p class="month-filter-status"><?= $selectedMonth !== null ? $selectedMonth . '월 기록 조회 중' : '전체 월 기록 조회 중' ?> <a href="<?= $escape($allMonthsBase) ?>#records">전체 기록 보기</a></p><?php if ($item === '1.1.3') require __DIR__ . '/leadership_vfl_assessment.php'; ?><p class="hint"><?= $item === '1.1.3' ? '활동 기록 1건을 VFL 보고서 1건으로 집계합니다. 개선조치가 없는 관찰은 개선율 산정 대상에서 제외됩니다.' : '대표자 참석 100%, 대리 참석 50%, 불참 0%로 반영합니다. 반영점수는 위의 참석률을 기준으로 하며, 평가 대상 월 중 미등록·증빙 미첨부·미개최 월은 0%로 포함합니다. 원본은 월별 적용 기준이므로 월 1건으로 관리합니다. 최종 평가는 안전관리팀 확인값을 따릅니다.' ?></p></section>
<?php endif; ?>
<details class="panel editor" <?= $openForm ? 'open' : '' ?>><summary><?= $id > 0 ? '활동 기록 수정' : '+ 새 활동 기록 등록' ?></summary>
<form method="post" action="<?= $escape($base) ?>" enctype="multipart/form-data" id="activity-form"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['leadership_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="item" value="<?= $item ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="revision" value="<?= $revision ?>"><div class="fields">
<label><?= $item === '1.1.3' ? '순찰 일자' : ($item === '1.1.4' ? '협의회일 / 미개최 확인일' : ($item === '1.1.2' ? '전달일' : '공표·게시일')) ?><input name="activity_date" type="date" value="<?= $escape($form['activity_date']) ?>" min="<?= $year ?>-01-01" max="<?= $year ?>-12-31" required></label>
<label>기록 제목<input name="title" maxlength="200" value="<?= $escape($form['title']) ?>" placeholder="<?= $escape($titles[$item]) ?> 기록" required></label>
<label><?= $item === '1.1.3' ? '순찰자 (경영진)' : ($item === '1.1.4' ? '참석자 / 확인 담당자' : '담당자') ?><input name="owner" maxlength="150" value="<?= $escape($form['owner']) ?>" required></label>
<label><?= $item === '1.1.2' ? '전달 방법·장소' : ($item === '1.1.3' ? '순찰지역' : '장소') ?><input name="location" maxlength="500" value="<?= $escape($form['location']) ?>" placeholder="<?= $item === '1.1.2' ? '사무실 게시, 대기실 안내, 현장 전달 등' : '' ?>" required></label>
<?php if ($item === '1.1.3'): require __DIR__ . '/leadership_vfl_fields.php'; endif; ?>
<?php if ($item === '1.1.2'): ?><label>대표자 서명일<input type="date" name="signed_date" value="<?= $escape($form['signed_date']) ?>"></label><label>전달 대상<input name="audience" maxlength="1000" value="<?= $escape($form['audience']) ?>" placeholder="팀·대상 근로자 및 인원" required></label><?php endif; ?>
<?php if ($item === '1.1.4'): ?><label>참석 구분<select name="attendance" required><option value="">선택해 주세요</option><?php foreach ($attendanceLabels as $key => $label): ?><option value="<?= $key ?>" <?= $form['attendance'] === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if ($item === '1.1.3'): require __DIR__ . '/leadership_photo_fields.php'; ?><?php if ($form['vfl_dialogue'] !== ''): ?><details class="wide"><summary>기존 공통 안전대화 내용</summary><textarea name="vfl_dialogue" rows="3" maxlength="10000"><?= $escape($form['vfl_dialogue']) ?></textarea></details><?php else: ?><input type="hidden" name="vfl_dialogue" value=""><?php endif; ?><?php else: ?><label class="wide"><?= $item === '1.1.4' ? '협의 내용·불참 사유 / 확인 내용' : '활동 내용·확인 사항' ?><textarea name="notes" rows="4" maxlength="10000" required><?= $escape($form['notes']) ?></textarea></label><?php endif; ?></div>
<?php if ($item === '1.1.3'): ?><fieldset><legend>개선조치 관리</legend><p class="hint">SRV: 안전수칙 위반 / UA: 불안전한 행동 / UC: 불안전한 상태. 발견사항과 개선조치를 나누어 기록합니다. 지적사항이 없으면 비워둡니다.</p><div id="actions"><?php foreach (array_values($form['actions']) as $index => $action): if (!is_array($action)) continue; require __DIR__ . '/leadership_action_fields.php'; endforeach; ?></div><button type="button" id="add-action">+ 개선조치 추가</button><template id="action-template"><?php $index = '__INDEX__'; $action = []; require __DIR__ . '/leadership_action_fields.php'; ?></template></fieldset><p class="hint">순찰자 서명은 인쇄후 수기로 받고, 서명본·순찰 현장·개선 전후 사진을 아래 증빙으로 첨부해 주세요.</p><?php endif; ?>
<label><?= $item === '1.1.3' ? '서명본·추가 증빙 첨부' : '증빙 첨부' ?><input type="file" name="evidence[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label><p class="hint">PDF·JPG·PNG, 파일별 10MB / 합계 30MB, 한 번에 최대 5개. 수정 시 새 증빙이 추가되며 기존 파일은 유지됩니다.</p>
<?php if ($id && isset($files[$id])): ?><div class="file-links"><?php foreach ($files[$id] as $file): ?><a href="<?= $escape($base) ?>&amp;file=<?= $file['id'] ?>"><?= $escape($file['original_name']) ?></a><?php endforeach; ?></div><?php endif; ?>
<div class="form-actions"><button class="primary" <?= !$db ? 'disabled' : '' ?>><?= $id ? '수정 내용 저장' : '활동 기록 저장' ?></button><?php if ($id): ?><a href="<?= $escape($base) ?>">수정 취소</a><?php endif; ?></div></form>
<?php if ($history): ?><details><summary>저장·수정 이력 <?= count($history) ?>건</summary><?php foreach ($history as $entry): $snapshot = json_decode($entry['snapshot'], true); ?><div class="history"><strong><?= $escape($entry['saved_at']) ?> · <?= $escape($entry['editor']) ?></strong><p><?= $escape($snapshot['activity_date'] . ' · ' . $snapshot['title']) ?></p><?php if (isset($snapshot['file_event'])): ?><p><?= $snapshot['file_event']['action'] === 'delete_file' ? '증빙 삭제' : '증빙 복원' ?>: <?= $escape($snapshot['file_event']['name']) ?></p><?php endif; ?><p class="pre"><?= $escape($snapshot['payload']['notes']) ?></p></div><?php endforeach; ?></details><?php endif; ?></details>
<section class="panel" id="records"><div class="section-title"><h2><?= $selectedMonth !== null ? $selectedMonth . ($item === '1.1.3' ? "월 VFL 기록" : "월 협의회 기록") : "등록 이력" ?> <small><?= count($records) ?>건</small></h2><div class="quick-links"><button type="button" onclick="window.print()"><?= $selectedMonth !== null ? $selectedMonth . "월 기록 인쇄" : "현재 항목 인쇄" ?></button><?php if ($item === '1.1.3' && $records): ?><a class="button" href="<?= $escape($base) ?>&amp;export=xlsx">엑셀 다운로드</a><?php endif; ?></div></div><?php if (!$records): ?><p class="empty"><?= $selectedMonth !== null ? $selectedMonth . ($item === '1.1.3' ? "월에 등록된 VFL 기록이 없습니다." : "월에 등록된 협의회 기록이 없습니다.") : "등록된 활동이 없습니다. 실제 활동과 증빙을 등록해 주세요." ?></p><?php endif; ?>
<?php foreach ($records as $row): ?><article class="record" id="record-<?= (int)$row['id'] ?>"><div class="section-title"><div><small><?= $escape($row['activity_date']) ?> · <?= $escape($row['data']['owner']) ?></small><h3><?= $escape($row['title']) ?></h3></div><a class="button" href="<?= $escape($base) ?>&amp;edit=<?= $row['id'] ?>">수정</a></div><p class="muted"><?= $escape($row['data']['location']) ?><?php if ($item === '1.1.4'): ?> · <?= $escape($attendanceLabels[$row['data']['attendance']]) ?><?php endif; ?></p><?php if ($item === '1.1.3'): require __DIR__ . '/leadership_vfl_record.php'; else: ?><p class="pre"><?= $escape($row['data']['notes']) ?></p><?php endif; ?>
<?php if ($item === '1.1.2'): ?><p class="hint">대표자 서명일 <?= $escape($row['data']['signed_date'] ?: '미등록') ?> · 전달 대상 <?= $escape($row['data']['audience']) ?></p><?php endif; ?>
<?php if ($item === '1.1.3'): ?><ul class="action-list"><?php foreach ($row['data']['actions'] as $action): ?><li><strong><?= $action['completed'] !== '' ? '완료' : ($action['due'] < $today ? '기한 초과' : '진행 중') ?></strong> <?php if (!empty($action['classification'])): ?><span class="vfl-classification"><?= $escape($action['classification']) ?></span><?php endif; ?><?php if (!empty($action['finding'])): ?><p class="pre"><b>발견사항:</b> <?= $escape($action['finding']) ?></p><?php endif; ?><p class="pre"><b>개선조치:</b> <?= $escape($action['description']) ?></p><small><?= $escape($action['owner']) ?> · 기한 <?= $escape($action['due']) ?><?= $action['completed'] ? ' · 완료 ' . $escape($action['completed']) : '' ?></small></li><?php endforeach; ?></ul><?php endif; ?>
<?php require __DIR__ . '/leadership_attachments.php'; ?><small class="muted">최종 저장 <?= $escape($row['updated_at']) ?> · <?= $escape($row['updated_by']) ?></small></article><?php endforeach; ?></section>
<p class="hint">등록 건수는 활동 이력입니다. 증빙의 적정성이나 평가점수 충족을 자동 확정하지 않습니다. 적용 기준: 2025 협력업체 안전활동평가표.</p>
</main><?php if ($item === '1.1.4'): ?>
<dialog id="meeting-pdf-viewer" class="meeting-pdf-viewer" aria-labelledby="meeting-pdf-title">
<div class="meeting-pdf-toolbar"><h2 id="meeting-pdf-title">PDF 첨부문서</h2><div class="quick-links"><a class="button" data-pdf-new-tab target="_blank" rel="noopener">새 탭에서 열기</a><a class="button" data-pdf-download-link>다운로드</a><button type="button" data-pdf-close>닫기</button></div></div>
<iframe title="대표자 협의회 PDF 첨부문서" class="meeting-pdf-frame"></iframe>
<p class="hint">문서가 보이지 않으면 새 탭에서 열거나 다운로드해 주세요.</p>
</dialog>
<?php endif; ?><?php if ($item === '1.1.3'): require __DIR__ . '/leadership_vfl_print.php'; elseif ($item === '1.1.4'): require __DIR__ . '/leadership_meeting_print.php'; endif; ?><script src="leadership.js?v=20261110-meeting-pdf"></script></body></html>
