<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';

$user = auth_current_user();
if (!is_array($user)) { header('Location: /risk_assessment/task_select.php'); exit; }
if (!auth_can_manage($user) || trim((string)($user['login_id'] ?? '')) !== '5878') { http_response_code(403); exit('권한이 없습니다.'); }
header('Content-Type: text/html; charset=UTF-8');

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function value(array $data, string $key): string { return (string)($data[$key] ?? ''); }
function field(array $data, string $key, string $type = 'text', string $extra = ''): string { return '<input type="' . h($type) . '" id="f_' . h($key) . '" name="' . h($key) . '" value="' . h(value($data, $key)) . '" ' . $extra . '>'; }
function text_field(array $data, string $key): string { return '<textarea name="' . h($key) . '">' . h(value($data, $key)) . '</textarea>'; }
function clean_data(array $source): array { $data = []; foreach ($source as $key => $item) { if (is_string($key) && !is_array($item)) { $data[$key] = mb_substr(trim($item), 0, 1000); } } return $data; }
function build_review_rows(array $post): array {
    $types = $post['review_type'] ?? []; $works = $post['review_work'] ?? []; $hazards = $post['review_hazard'] ?? [];
    $reviews = $post['review_review'] ?? []; $actions = $post['review_action'] ?? []; $workers = $post['review_worker'] ?? [];
    $count = max(count($types), count($works), count($hazards), count($reviews), count($actions), count($workers));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'type' => mb_substr(trim((string)($types[$i] ?? '')), 0, 200), 'work' => mb_substr(trim((string)($works[$i] ?? '')), 0, 2000),
            'hazard' => mb_substr(trim((string)($hazards[$i] ?? '')), 0, 2000), 'review' => mb_substr(trim((string)($reviews[$i] ?? '')), 0, 2000),
            'action' => mb_substr(trim((string)($actions[$i] ?? '')), 0, 2000), 'worker' => mb_substr(trim((string)($workers[$i] ?? '')), 0, 2000),
        ];
    }
    return $rows;
}
function normalize_review_rows(array $data): array {
    if (isset($data['review_rows']) && is_array($data['review_rows']) && count($data['review_rows']) > 0) {
        return array_values(array_map(static function ($row) {
            $row = is_array($row) ? $row : [];
            return ['type' => (string)($row['type'] ?? ''), 'work' => (string)($row['work'] ?? ''), 'hazard' => (string)($row['hazard'] ?? ''), 'review' => (string)($row['review'] ?? ''), 'action' => (string)($row['action'] ?? ''), 'worker' => (string)($row['worker'] ?? '')];
        }, $data['review_rows']));
    }
    $legacy = [];
    foreach (['regular' => '정기', 'temporary' => '수시', 'additional' => ''] as $key => $label) {
        $legacy[] = ['type' => $label, 'work' => value($data, $key . '_work'), 'hazard' => value($data, $key . '_hazard'), 'review' => value($data, $key . '_review'), 'action' => value($data, $key . '_action'), 'worker' => value($data, $key . '_worker')];
    }
    return $legacy;
}
function build_reflection_rows(array $post): array {
    $targets = $post['reflect_target'] ?? []; $dates = $post['reflect_date'] ?? []; $persons = $post['reflect_person'] ?? [];
    $eduTargets = $post['reflect_edu_target'] ?? []; $eduDates = $post['reflect_edu_date'] ?? []; $eduMethods = $post['reflect_edu_method'] ?? [];
    $count = max(count($targets), count($dates), count($persons), count($eduTargets), count($eduDates), count($eduMethods));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'target' => mb_substr(trim((string)($targets[$i] ?? '')), 0, 1000), 'reflect_date' => mb_substr(trim((string)($dates[$i] ?? '')), 0, 200), 'person' => mb_substr(trim((string)($persons[$i] ?? '')), 0, 200),
            'edu_target' => mb_substr(trim((string)($eduTargets[$i] ?? '')), 0, 1000), 'edu_date' => mb_substr(trim((string)($eduDates[$i] ?? '')), 0, 200), 'edu_method' => mb_substr(trim((string)($eduMethods[$i] ?? '')), 0, 1000),
        ];
    }
    return $rows;
}
function normalize_reflection_rows(array $data): array {
    if (isset($data['reflection_rows']) && is_array($data['reflection_rows']) && count($data['reflection_rows']) > 0) {
        return array_values(array_map(static function ($row) {
            $row = is_array($row) ? $row : [];
            if (isset($row['date_person']) && !isset($row['reflect_date'])) {
                // 이전 버전 호환: "반영 예정일 및 담당자" 한 칸이었던 값은 담당자 칸에 그대로 옮겨두고 예정일은 비워 둔다
                $row['reflect_date'] = ''; $row['person'] = (string)$row['date_person'];
            }
            return ['target' => (string)($row['target'] ?? ''), 'reflect_date' => (string)($row['reflect_date'] ?? ''), 'person' => (string)($row['person'] ?? ''), 'edu_target' => (string)($row['edu_target'] ?? ''), 'edu_date' => (string)($row['edu_date'] ?? ''), 'edu_method' => (string)($row['edu_method'] ?? '')];
        }, $data['reflection_rows']));
    }
    if (value($data, 'reflection_target') !== '' || value($data, 'reflection_date_person') !== '' || value($data, 'education_target') !== '' || value($data, 'education_method') !== '') {
        return [['target' => value($data, 'reflection_target'), 'reflect_date' => '', 'person' => value($data, 'reflection_date_person'), 'edu_target' => value($data, 'education_target'), 'edu_date' => '', 'edu_method' => value($data, 'education_method')]];
    }
    return [];
}

$year = max(2000, min(2100, (int)($_REQUEST['year'] ?? date('Y'))));
$half = ($_REQUEST['half'] ?? ((int)date('n') <= 6 ? '상반기' : '하반기')) === '하반기' ? '하반기' : '상반기';
$data = ['year' => (string)$year, 'half' => $half, 'report_date' => date('Y-m-d'), 'acceptable_standard' => '6'];
$notice = $error = '';
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS safety_risk_assessment_reports(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,report_year SMALLINT UNSIGNED NOT NULL,half_year TINYINT UNSIGNED NOT NULL,report_data LONGTEXT NOT NULL,updated_by VARCHAR(100) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_period(report_year,half_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = clean_data($_POST) + $data;
        $data['review_rows'] = build_review_rows($_POST);
        $data['reflection_rows'] = build_reflection_rows($_POST);
        $statement = $db->prepare('INSERT INTO safety_risk_assessment_reports(report_year,half_year,report_data,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE report_data=VALUES(report_data),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
        $statement->execute([$year, $half === '상반기' ? 1 : 2, json_encode($data, JSON_UNESCAPED_UNICODE), trim((string)($user['name'] ?? $user['login_id']))]);
        $notice = $year . '년 ' . $half . ' 보고서를 저장했습니다.';
    } else {
        $statement = $db->prepare('SELECT report_data FROM safety_risk_assessment_reports WHERE report_year=? AND half_year=?'); $statement->execute([$year, $half === '상반기' ? 1 : 2]); $saved = $statement->fetchColumn();
        if (is_string($saved) && is_array($decoded = json_decode($saved, true))) { $data = $decoded + $data; }
    }
} catch (Throwable $exception) { error_log($exception->getMessage()); $error = '보고서를 불러오거나 저장하지 못했습니다.'; }
$reviewRows = normalize_review_rows($data);
if (count($reviewRows) === 0) { $reviewRows = [['type' => '', 'work' => '', 'hazard' => '', 'review' => '', 'action' => '', 'worker' => '']]; }
$reflectionRows = normalize_reflection_rows($data);
if (count($reflectionRows) === 0) { $reflectionRows = [['target' => '', 'reflect_date' => '', 'person' => '', 'edu_target' => '', 'edu_date' => '', 'edu_method' => '']]; }
$teamOptions = auth_read_active_teams();
function team_select(array $teamOptions, string $name, string $current): string {
    $options = $teamOptions;
    if ($current !== '' && !in_array($current, $options, true)) { array_unshift($options, $current); }
    $html = '<select name="' . h($name) . '"><option value=""></option>';
    foreach ($options as $team) { $html .= '<option' . ($current === $team ? ' selected' : '') . '>' . h($team) . '</option>'; }
    return $html . '</select>';
}
// 문서번호는 자유 입력이 아니라 작성일에서 바로 유추할 수 있는 자동 키로 생성한다 (예: SM-RA-20260630-H1)
$reportDateDigits = value($data, 'report_date') !== '' ? str_replace('-', '', value($data, 'report_date')) : date('Ymd');
$data['document_number'] = 'SM-RA-' . $reportDateDigits . '-' . ($half === '상반기' ? 'H1' : 'H2');
$types = ['remove' => '제거', 'replace' => '대체·변경', 'engineering' => '공학적', 'management' => '관리적', 'ppe' => '보호구', 'total' => '합계'];
foreach (['target', 'completed', 'pending', 'cost'] as $counterKey) {
    foreach ($types as $typeKey => $_) {
        $counterField = 'counter_' . $counterKey . '_' . $typeKey;
        if (value($data, $counterField) === '') { $data[$counterField] = '0'; }
    }
}
if (value($data, 'summary_rate') === '') {
    $summaryTarget = (float)value($data, 'summary_target'); $summaryCompleted = (float)value($data, 'summary_completed');
    $data['summary_rate'] = $summaryTarget > 0 ? (string)round($summaryCompleted / $summaryTarget * 100) : '0';
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>위험성평가 결과 보고서</title><link rel="stylesheet" href="assets/risk-report.css?v=<?= filemtime(__DIR__ . '/assets/risk-report.css') ?>"></head><body><header><div><small>SAFETY MANAGEMENT SYSTEM</small><h1>위험성평가 결과 및 검토 보고서</h1></div></header><main>
<?php if ($notice !== ''): ?><p class="notice success"><?= h($notice) ?></p><?php endif; if ($error !== ''): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
<form method="get" class="period"><label>연도<input type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></label><label>반기<select name="half"><option<?= $half === '상반기' ? ' selected' : '' ?>>상반기</option><option<?= $half === '하반기' ? ' selected' : '' ?>>하반기</option></select></label><button>조회</button></form><form method="post"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="half" value="<?= h($half) ?>">
<section class="card"><h2>기본 정보</h2><div class="fields four"><?php foreach (['document_number' => '문서번호', 'report_date' => '작성일'] as $key => $label): ?><label><?= $label ?><?= field($data, $key, $key === 'report_date' ? 'date' : 'text', $key === 'document_number' ? 'readonly' : '') ?></label><?php endforeach; ?></div></section>
<section class="card"><h2>1. 평가 개요</h2><div class="fields three"><?php foreach (['evaluation_start' => '평가 시작일', 'evaluation_end' => '평가 종료일', 'evaluation_type' => '평가구분 (정기/수시)', 'evaluation_target' => '대상 부서·공정', 'evaluator' => '평가담당자', 'supervisor' => '관리감독자', 'workers' => '참여 근로자·근로자대표', 'review_period' => '검토기간', 'focus' => '중점사항'] as $key => $label): ?><label><?= $label ?><?= field($data, $key, str_contains($key, 'start') || str_contains($key, 'end') ? 'date' : 'text') ?></label><?php endforeach; ?></div></section>
<section class="card"><h2>2. 종합 결과</h2><div class="scroll"><table><tr><th>집계기준일</th><th>전체 유해위험요인</th><th>허용가능</th><th>개선대상</th><th>개선완료</th><th>미개선</th><th>개선율(%)</th></tr><tr><td><?= field($data, 'aggregation_date', 'date') ?></td><?php foreach (['total', 'acceptable', 'target', 'completed', 'pending'] as $key): ?><td><?= field($data, 'summary_' . $key, 'number') ?></td><?php endforeach; ?><td><?= field($data, 'summary_rate', 'number', 'readonly') ?></td></tr></table></div></section>
<section class="card"><h2>3. 정기 및 수시 위험성평가 검토 결과</h2><div class="scroll"><table id="review-rows-table"><tr><th>구분</th><th>공정·작업명</th><th>주요 위험요인</th><th>검토결과</th><th>조치사항</th><th>근로자 참여</th><th></th></tr><?php foreach ($reviewRows as $row): ?><tr><td><select name="review_type[]"><?php foreach (['정기', '수시', '기타'] as $option): ?><option<?= ($row['type'] ?? '') === $option ? ' selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></td><td><textarea name="review_work[]"><?= h((string)($row['work'] ?? '')) ?></textarea></td><td><textarea name="review_hazard[]"><?= h((string)($row['hazard'] ?? '')) ?></textarea></td><td><textarea name="review_review[]"><?= h((string)($row['review'] ?? '')) ?></textarea></td><td><textarea name="review_action[]"><?= h((string)($row['action'] ?? '')) ?></textarea></td><td><textarea name="review_worker[]"><?= h((string)($row['worker'] ?? '')) ?></textarea></td><td><button type="button" class="row-remove secondary">삭제</button></td></tr><?php endforeach; ?></table></div><button type="button" id="review-row-add" class="secondary">+ 행 추가</button></section>
<section class="card"><h2>4. 개선대책 유형별 현황</h2><div class="scroll"><table class="counter-table"><tr><th>구분</th><?php foreach ($types as $label): ?><th><?= $label ?></th><?php endforeach; ?></tr><?php foreach (['target' => '개선대상(건)', 'completed' => '개선완료(건)', 'pending' => '미개선(건)', 'cost' => '개선비용(천원)'] as $key => $label): ?><tr><th><?= $label ?></th><?php foreach ($types as $type => $_):
    $isCost = $key === 'cost'; $isTotal = $type === 'total';
    $extra = $isTotal ? 'readonly' : 'data-counter-row="' . h($key) . '"';
    if ($isCost) { $extra .= ' class="cost-input" inputmode="numeric" autocomplete="off"'; }
    $fieldType = $isCost ? 'text' : 'number';
    ?><td><?= field($data, 'counter_' . $key . '_' . $type, $fieldType, $extra) ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div></section>
<section class="card"><h2>5. 미개선 및 향후 계획</h2><div class="scroll"><table><tr><th>관리번호 및 공정·작업명</th><th>미개선 사유 및 잔여 위험요인</th><th>개선대책 및 완료 전 임시조치</th><th>담당자</th><th>완료예정일</th><th>확인계획</th></tr><?php foreach ([1, 2, 3] as $number): ?><tr><td class="lookup-cell"><?= field($data, 'plan_' . $number . '_number') ?><button type="button" class="secondary hazard-lookup-open" data-target="f_plan_<?= $number ?>_number">조회</button></td><?php foreach (['reason', 'measure', 'person', 'due', 'check'] as $column): ?><td><?= field($data, 'plan_' . $number . '_' . $column, $column === 'due' ? 'date' : 'text') ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div>
<h3>정기평가 반영계획 및 결과 공유·교육</h3>
<div class="scroll"><table id="reflection-rows-table"><tr><th>정기평가 반영 대상 공정·작업</th><th>반영 예정일</th><th>담당자</th><th>공유·교육 대상</th><th>실시 예정일</th><th>방법</th><th></th></tr><?php foreach ($reflectionRows as $row): ?><tr><td><input type="text" name="reflect_target[]" value="<?= h((string)($row['target'] ?? '')) ?>"></td><td><input type="date" name="reflect_date[]" value="<?= h((string)($row['reflect_date'] ?? '')) ?>"></td><td><input type="text" name="reflect_person[]" value="<?= h((string)($row['person'] ?? '')) ?>"></td><td><?= team_select($teamOptions, 'reflect_edu_target[]', (string)($row['edu_target'] ?? '')) ?></td><td><input type="date" name="reflect_edu_date[]" value="<?= h((string)($row['edu_date'] ?? '')) ?>"></td><td><input type="text" name="reflect_edu_method[]" value="<?= h((string)($row['edu_method'] ?? '')) ?>"></td><td><button type="button" class="row-remove secondary">삭제</button></td></tr><?php endforeach; ?></table></div><button type="button" id="reflection-row-add" class="secondary">+ 행 추가</button>
<div class="fields two"><label class="wide">종합 검토의견<?= text_field($data, 'opinion') ?></label></div></section>
<section class="card"><h2>6. 첨부문서 목록</h2><div class="scroll"><table><tr><th>번호</th><th>문서명</th><th>문서번호 또는 대상기간</th><th>수량</th><th>비고</th><th>스캔본</th></tr><?php foreach ([1, 2, 3, 4] as $number): $filePath = value($data, 'attachment_' . $number . '_file'); ?><tr><td><?= $number ?></td><td><?= field($data, 'attachment_' . $number . '_name') ?></td><td><?= field($data, 'attachment_' . $number . '_period') ?></td><td><?= field($data, 'attachment_' . $number . '_quantity') ?></td><td><?= field($data, 'attachment_' . $number . '_note') ?></td><td class="attachment-cell"><input type="hidden" name="attachment_<?= $number ?>_file" id="f_attachment_<?= $number ?>_file" value="<?= h($filePath) ?>"><a href="<?= h($filePath) ?>" target="_blank" class="attachment-link" id="link_attachment_<?= $number ?>_file"<?= $filePath === '' ? ' hidden' : '' ?>>보기</a><button type="button" class="secondary attachment-upload-open" data-index="<?= $number ?>"><?= $filePath === '' ? '업로드' : '다시 업로드' ?></button></td></tr><?php endforeach; ?></table></div><input type="file" id="attachment-file-input" accept="image/*,.pdf" hidden></section><div class="actions"><a href="index.php#risk-assessment-clause" class="button secondary">매뉴얼로 돌아가기</a><button type="submit">저장하기</button><button type="submit" name="preview_print" value="1" formaction="generate_report_pdf.php" formtarget="_blank">인쇄 미리보기</button></div></form></main>
<script>
(function () {
    'use strict';
    var table = document.getElementById('review-rows-table');
    var addButton = document.getElementById('review-row-add');
    if (!table || !addButton) { return; }
    function rowHtml() {
        return '<tr><td><select name="review_type[]"><option>정기</option><option>수시</option><option>기타</option></select></td><td><textarea name="review_work[]"></textarea></td><td><textarea name="review_hazard[]"></textarea></td><td><textarea name="review_review[]"></textarea></td><td><textarea name="review_action[]"></textarea></td><td><textarea name="review_worker[]"></textarea></td><td><button type="button" class="row-remove secondary">삭제</button></td></tr>';
    }
    addButton.addEventListener('click', function () { table.insertAdjacentHTML('beforeend', rowHtml()); });
    table.addEventListener('click', function (event) {
        if (!event.target.classList.contains('row-remove')) { return; }
        if (table.rows.length > 2) { event.target.closest('tr').remove(); }
    });
}());
(function () {
    'use strict';
    var table = document.getElementById('reflection-rows-table');
    var addButton = document.getElementById('reflection-row-add');
    if (!table || !addButton) { return; }
    var teamOptions = <?= json_encode($teamOptions, JSON_UNESCAPED_UNICODE) ?>;
    function teamSelectHtml() {
        var html = '<select name="reflect_edu_target[]"><option value=""></option>';
        teamOptions.forEach(function (team) {
            html += '<option>' + team.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });
        return html + '</select>';
    }
    function rowHtml() {
        return '<tr><td><input type="text" name="reflect_target[]"></td><td><input type="date" name="reflect_date[]"></td><td><input type="text" name="reflect_person[]"></td><td>' + teamSelectHtml() + '</td><td><input type="date" name="reflect_edu_date[]"></td><td><input type="text" name="reflect_edu_method[]"></td><td><button type="button" class="row-remove secondary">삭제</button></td></tr>';
    }
    addButton.addEventListener('click', function () { table.insertAdjacentHTML('beforeend', rowHtml()); });
    table.addEventListener('click', function (event) {
        if (!event.target.classList.contains('row-remove')) { return; }
        if (table.rows.length > 2) { event.target.closest('tr').remove(); }
    });
}());
(function () {
    'use strict';
    var reportDate = document.getElementById('f_report_date');
    var docNumber = document.getElementById('f_document_number');
    var halfInput = document.querySelector('input[name="half"]');
    if (!reportDate || !docNumber || !halfInput) { return; }
    function recompute() {
        var digits = (reportDate.value || '').replace(/-/g, '');
        if (digits === '') { return; }
        docNumber.value = 'SM-RA-' + digits + '-' + (halfInput.value === '하반기' ? 'H2' : 'H1');
    }
    reportDate.addEventListener('input', recompute);
    recompute();
}());
(function () {
    'use strict';
    var target = document.getElementById('f_summary_target');
    var completed = document.getElementById('f_summary_completed');
    var rate = document.getElementById('f_summary_rate');
    if (!target || !completed || !rate) { return; }
    function recalc() {
        var t = parseFloat(target.value) || 0;
        var c = parseFloat(completed.value) || 0;
        rate.value = t > 0 ? Math.round((c / t) * 100) : 0;
    }
    target.addEventListener('input', recalc);
    completed.addEventListener('input', recalc);
    recalc();
}());
(function () {
    'use strict';
    var table = document.querySelector('.counter-table');
    if (!table) { return; }
    var types = ['remove', 'replace', 'engineering', 'management', 'ppe'];
    function recalcRow(rowKey) {
        var sum = 0;
        types.forEach(function (type) {
            var input = document.getElementById('f_counter_' + rowKey + '_' + type);
            var raw = input ? input.value.replace(/,/g, '') : '';
            sum += parseFloat(raw) || 0;
        });
        var total = document.getElementById('f_counter_' + rowKey + '_total');
        if (total) { total.value = rowKey === 'cost' ? sum.toLocaleString('en-US') : sum; }
    }
    var rowKeys = ['target', 'completed', 'pending', 'cost'];
    rowKeys.forEach(function (rowKey) {
        table.querySelectorAll('[data-counter-row="' + rowKey + '"]').forEach(function (input) {
            input.addEventListener('input', function () { recalcRow(rowKey); });
        });
        recalcRow(rowKey);
    });
}());
(function () {
    'use strict';
    var costInputs = document.querySelectorAll('.cost-input');
    if (costInputs.length === 0) { return; }
    function toRawDigits(value) { return (value || '').toString().replace(/[^0-9]/g, ''); }
    function formatWithCommas(el) {
        var raw = toRawDigits(el.value);
        el.value = raw === '' ? '' : Number(raw).toLocaleString('en-US');
    }
    costInputs.forEach(function (el) {
        formatWithCommas(el);
        if (!el.readOnly) { el.addEventListener('input', function () { formatWithCommas(el); }); }
    });
    var form = document.querySelector('form[method="post"]');
    if (form) {
        form.addEventListener('submit', function () {
            costInputs.forEach(function (el) { el.value = toRawDigits(el.value); });
        });
    }
}());
(function () {
    'use strict';
    var openButtons = document.querySelectorAll('.hazard-lookup-open');
    if (openButtons.length === 0) { return; }
    var dialog = document.createElement('dialog');
    dialog.id = 'hazard-lookup-dialog';
    dialog.innerHTML = '<div class="preview-toolbar"><h2>위험요소 조회</h2><button type="button" class="secondary" id="hazard-lookup-close">닫기</button></div><div style="padding:16px"><input type="text" id="hazard-lookup-input" placeholder="관리번호·공정명·위험요인 검색" style="width:100%"><div id="hazard-lookup-results" class="scroll" style="margin-top:12px;max-height:50vh"></div></div>';
    document.body.append(dialog);
    var input = dialog.querySelector('#hazard-lookup-input');
    var results = dialog.querySelector('#hazard-lookup-results');
    var currentTargetId = null;
    var searchTimer = null;

    function renderResults(items) {
        if (items.length === 0) { results.textContent = '검색 결과가 없습니다.'; return; }
        var table = document.createElement('table');
        table.innerHTML = '<tr><th>관리번호</th><th>공정·작업</th><th>위험요인</th></tr>';
        items.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.innerHTML = '<td></td><td></td><td></td>';
            tr.children[0].textContent = item.label;
            tr.children[1].textContent = item.task_name;
            tr.children[2].textContent = item.hazard_name;
            tr.addEventListener('click', function () {
                var targetInput = document.getElementById(currentTargetId);
                if (targetInput) { targetInput.value = item.label + ' / ' + item.task_name; }
                dialog.close();
            });
            table.append(tr);
        });
        results.replaceChildren(table);
    }

    function search(q) {
        fetch('hazard_lookup.php?q=' + encodeURIComponent(q))
            .then(function (response) { return response.json(); })
            .then(renderResults)
            .catch(function () { results.textContent = '조회 중 오류가 발생했습니다.'; });
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            currentTargetId = button.getAttribute('data-target');
            input.value = '';
            results.textContent = '검색어를 입력하세요.';
            dialog.showModal();
            input.focus();
        });
    });
    input.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var q = input.value.trim();
        if (q === '') { results.textContent = '검색어를 입력하세요.'; return; }
        searchTimer = setTimeout(function () { search(q); }, 250);
    });
    dialog.querySelector('#hazard-lookup-close').addEventListener('click', function () { dialog.close(); });
}());
(function () {
    'use strict';
    var fileInput = document.getElementById('attachment-file-input');
    var uploadButtons = document.querySelectorAll('.attachment-upload-open');
    if (!fileInput || uploadButtons.length === 0) { return; }
    var yearInput = document.querySelector('input[name="year"]');
    var halfInput = document.querySelector('input[name="half"]');
    var currentIndex = null;

    uploadButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            currentIndex = button.getAttribute('data-index');
            fileInput.value = '';
            fileInput.click();
        });
    });

    fileInput.addEventListener('change', function () {
        var file = fileInput.files[0];
        if (!file || !currentIndex) { return; }
        var data = new FormData();
        data.set('year', yearInput.value);
        data.set('half', halfInput.value);
        data.set('index', currentIndex);
        data.set('file', file);
        var button = document.querySelector('.attachment-upload-open[data-index="' + currentIndex + '"]');
        var originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = '업로드 중...';
        fetch('upload_report_attachment.php', { method: 'POST', body: data })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.ok) { throw new Error(result.message || '업로드에 실패했습니다.'); }
                document.getElementById('f_attachment_' + currentIndex + '_file').value = result.path;
                var link = document.getElementById('link_attachment_' + currentIndex + '_file');
                link.href = result.path;
                link.hidden = false;
                button.textContent = '다시 업로드';
            })
            .catch(function (error) {
                alert(error.message || '업로드에 실패했습니다.');
                button.textContent = originalLabel;
            })
            .finally(function () { button.disabled = false; });
    });
}());
</script>
</body></html>
