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
function field(array $data, string $key, string $type = 'text'): string { return '<input type="' . h($type) . '" name="' . h($key) . '" value="' . h(value($data, $key)) . '">'; }
function text_field(array $data, string $key): string { return '<textarea name="' . h($key) . '">' . h(value($data, $key)) . '</textarea>'; }
function clean_data(array $source): array { $data = []; foreach ($source as $key => $item) { if (is_string($key) && !is_array($item)) { $data[$key] = mb_substr(trim($item), 0, 1000); } } return $data; }
function report_html(array $data): string {
    $get = static fn(string $key): string => h(value($data, $key));
    $year = $get('year') ?: date('Y'); $half = $get('half') ?: '상반기';
    $reviewRows = '';
    foreach (['regular' => '정기', 'temporary' => '수시', 'additional' => ''] as $row => $label) {
        $reviewRows .= '<tr><td>' . $label . '</td><td>' . $get($row . '_work') . '</td><td>' . $get($row . '_hazard') . '</td><td>' . $get($row . '_review') . '</td><td>' . $get($row . '_action') . '</td><td>' . $get($row . '_worker') . '</td></tr>';
    }
    $counterRows = '';
    foreach (['target' => '개선대상(건)', 'completed' => '개선완료(건)', 'pending' => '미개선(건)', 'cost' => '개선비용(천원)'] as $key => $label) {
        $counterRows .= '<tr><th>' . $label . '</th>'; foreach (['remove', 'replace', 'engineering', 'management', 'ppe', 'total'] as $type) { $counterRows .= '<td>' . $get('counter_' . $key . '_' . $type) . '</td>'; } $counterRows .= '</tr>';
    }
    $planRows = '';
    foreach ([1, 2, 3] as $number) { $planRows .= '<tr><td>' . $get('plan_' . $number . '_number') . '</td><td>' . $get('plan_' . $number . '_reason') . '</td><td>' . $get('plan_' . $number . '_measure') . '</td><td>' . $get('plan_' . $number . '_person') . '</td><td>' . $get('plan_' . $number . '_due') . '</td><td>' . $get('plan_' . $number . '_check') . '</td></tr>'; }
    $attachmentRows = '';
    foreach ([1, 2, 3, 4] as $number) { $attachmentRows .= '<tr><td>' . $number . '</td><td>' . $get('attachment_' . $number . '_name') . '</td><td>' . $get('attachment_' . $number . '_period') . '</td><td>' . $get('attachment_' . $number . '_quantity') . '</td><td>' . $get('attachment_' . $number . '_note') . '</td></tr>'; }
    $css = file_get_contents(__DIR__ . '/assets/risk-report.css');
    return '<!doctype html><html lang="ko"><head><meta charset="utf-8"><style>' . $css . '</style></head><body class="pdf"><div class="page"><p class="form-label">양식 3) 위험성평가 결과 및 검토 보고서</p><table class="title-table"><tr><td class="report-title"><strong>위험성평가 결과 및 검토 보고서</strong><br>' . $year . '년도 &nbsp; ' . ($half === '상반기' ? '&#9632;' : '&#9633;') . ' 상반기 &nbsp; ' . ($half === '하반기' ? '&#9632;' : '&#9633;') . ' 하반기</td><th rowspan="2">결<br>재</th><th>작성</th><th>검토</th><th>승인</th></tr><tr><td></td><td>' . $get('approval_writer') . '</td><td>' . $get('approval_reviewer') . '</td><td>' . $get('approval_approver') . '</td></tr></table><table class="info-table"><tr><th>문서번호</th><td>' . $get('document_number') . '</td><th>작성일</th><td>' . $get('report_date') . '</td></tr><tr><th>사업장명</th><td>' . $get('workplace') . '</td><th>작성부서</th><td>' . $get('department') . '</td></tr></table><p>해당 반기의 위험성평가 결과와 개선 이행 현황을 검토하여 보고합니다.</p><h2>1 평가 개요</h2><table class="detail-table"><tr><th>평가기간</th><td>' . $get('evaluation_start') . ' ~ ' . $get('evaluation_end') . '</td></tr><tr><th>평가구분 및 대상</th><td>' . $get('evaluation_type') . ' / 대상 부서·공정: ' . $get('evaluation_target') . '</td></tr><tr><th>평가방법</th><td>4M / 인적·기계적·물질 및 환경적·관리적 요인</td></tr><tr><th>위험도 산출기준</th><td>빈도(1~5) × 강도(1~4) = 위험도(1~20)<br>허용가능 판단기준: ' . $get('acceptable_standard') . '</td></tr><tr><th>참여자</th><td>평가담당자: ' . $get('evaluator') . ' &nbsp;&nbsp; 관리감독자: ' . $get('supervisor') . '<br>참여 근로자·근로자대표: ' . $get('workers') . '</td></tr><tr><th>검토기간 및 중점</th><td>검토기간: ' . $get('review_period') . ' &nbsp;&nbsp; 중점사항: ' . $get('focus') . '</td></tr></table><h2>2 종합 결과</h2><p>집계기준일: ' . $get('aggregation_date') . ' / 집계대상: 해당 반기 평가요인(중복 제외)</p><table><tr><th>전체<br>유해위험요인</th><th>허용가능</th><th>개선대상</th><th>개선완료</th><th>미개선</th><th>개선율</th></tr><tr><td>' . $get('summary_total') . ' 건</td><td>' . $get('summary_acceptable') . ' 건</td><td>' . $get('summary_target') . ' 건</td><td>' . $get('summary_completed') . ' 건</td><td>' . $get('summary_pending') . ' 건</td><td>' . $get('summary_rate') . ' %</td></tr></table><p class="note">※ 전체 = 허용가능 + 개선대상 / 개선대상 = 개선완료 + 미개선<br>※ 개선율 = 개선완료 ÷ 개선대상 × 100</p><h2>3 정기 및 수시 위험성평가 검토 결과</h2><table><tr><th>구분</th><th>공정·작업명</th><th>주요 위험요인</th><th>검토결과</th><th>조치사항</th><th>근로자 참여</th></tr>' . $reviewRows . '</table><p class="note">※ 검토결과: 신규·변경 요인, 누락 및 평가 반영 여부 / 조치사항: 담당자·기한 또는 5항 연계번호</p><footer>반기 위험성평가 결과 및 검토 보고서 | 1</footer></div><div class="page"><p class="form-label">양식 3) 위험성평가 결과 및 검토 보고서</p><h2>4 개선대책 유형별 현황</h2><table><tr><th>구분</th><th>제거</th><th>대체·변경</th><th>공학적</th><th>관리적</th><th>보호구</th><th>합계</th></tr>' . $counterRows . '</table><p class="note">※ 위험요인별 주된 대책 1개로 분류하여 2항의 건수와 일치시킴. 개선비용은 실제 집행 금액으로 기재.</p><h2>5 미개선 및 향후 계획</h2><table><tr><th>관리번호 및<br>공정·작업명</th><th>미개선 사유 및<br>잔여 위험요인</th><th>개선대책 및<br>완료 전 임시조치</th><th>담당자</th><th>완료예정일</th><th>확인계획</th></tr>' . $planRows . '</table><p class="note">※ 미개선에는 진행 중인 건을 포함. 확인계획에 조치 후 위험도 재평가 및 완료 확인 일정을 기재.</p><table class="detail-table"><tr><th>정기평가 반영계획</th><td>대상 공정·작업: ' . $get('reflection_target') . '<br>반영 예정일 및 담당자: ' . $get('reflection_date_person') . '</td></tr><tr><th>결과 공유 및 교육</th><td>공유·교육 대상: ' . $get('education_target') . '<br>실시 예정일 및 방법: ' . $get('education_method') . '</td></tr><tr><th>종합 검토의견</th><td>' . nl2br($get('opinion')) . '</td></tr></table><h2>6 첨부문서 목록</h2><table><tr><th>번호</th><th>문서명</th><th>문서번호 또는 대상기간</th><th>수량</th><th>비고</th></tr>' . $attachmentRows . '</table><p class="note">※ 위험성평가표, 개선 전후 사진, 근로자 참여·의견 기록 등 실제 첨부한 문서만 기재.</p><footer>반기 위험성평가 결과 및 검토 보고서 | 2</footer></div></body></html>';
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
        if (isset($_POST['preview_print'])) {
            echo report_html($data);
            exit;
        }
        $statement = $db->prepare('INSERT INTO safety_risk_assessment_reports(report_year,half_year,report_data,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE report_data=VALUES(report_data),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
        $statement->execute([$year, $half === '상반기' ? 1 : 2, json_encode($data, JSON_UNESCAPED_UNICODE), trim((string)($user['name'] ?? $user['login_id']))]);
        $notice = $year . '년 ' . $half . ' 보고서를 저장했습니다.';
    } else {
        $statement = $db->prepare('SELECT report_data FROM safety_risk_assessment_reports WHERE report_year=? AND half_year=?'); $statement->execute([$year, $half === '상반기' ? 1 : 2]); $saved = $statement->fetchColumn();
        if (is_string($saved) && is_array($decoded = json_decode($saved, true))) { $data = $decoded + $data; }
    }
} catch (Throwable $exception) { error_log($exception->getMessage()); $error = '보고서를 불러오거나 저장하지 못했습니다.'; }
$types = ['remove' => '제거', 'replace' => '대체·변경', 'engineering' => '공학적', 'management' => '관리적', 'ppe' => '보호구', 'total' => '합계'];
?>
<script defer src="assets/risk-report.js?v=<?= filemtime(__DIR__ . '/assets/risk-report.js') ?>"></script>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>위험성평가 결과 보고서</title><link rel="stylesheet" href="assets/risk-report.css?v=<?= filemtime(__DIR__ . '/assets/risk-report.css') ?>"></head><body><header><div><small>SAFETY MANAGEMENT SYSTEM</small><h1>위험성평가 결과 및 검토 보고서</h1></div></header><main>
<?php if ($notice !== ''): ?><p class="notice success"><?= h($notice) ?></p><?php endif; if ($error !== ''): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
<form method="get" class="period"><label>연도<input type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></label><label>반기<select name="half"><option<?= $half === '상반기' ? ' selected' : '' ?>>상반기</option><option<?= $half === '하반기' ? ' selected' : '' ?>>하반기</option></select></label><button>조회</button></form><form method="post"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="half" value="<?= h($half) ?>">
<section class="card"><h2>기본 정보 및 결재</h2><div class="fields four"><?php foreach (['document_number' => '문서번호', 'report_date' => '작성일', 'workplace' => '사업장명', 'department' => '작성부서', 'approval_writer' => '작성', 'approval_reviewer' => '검토', 'approval_approver' => '승인'] as $key => $label): ?><label><?= $label ?><?= field($data, $key, $key === 'report_date' ? 'date' : 'text') ?></label><?php endforeach; ?></div></section>
<section class="card"><h2>1. 평가 개요</h2><div class="fields three"><?php foreach (['evaluation_start' => '평가 시작일', 'evaluation_end' => '평가 종료일', 'evaluation_type' => '평가구분 (정기/수시)', 'evaluation_target' => '대상 부서·공정', 'evaluator' => '평가담당자', 'supervisor' => '관리감독자', 'workers' => '참여 근로자·근로자대표', 'review_period' => '검토기간', 'focus' => '중점사항'] as $key => $label): ?><label><?= $label ?><?= field($data, $key, str_contains($key, 'start') || str_contains($key, 'end') ? 'date' : 'text') ?></label><?php endforeach; ?></div></section>
<section class="card"><h2>2. 종합 결과</h2><div class="scroll"><table><tr><th>집계기준일</th><th>전체 유해위험요인</th><th>허용가능</th><th>개선대상</th><th>개선완료</th><th>미개선</th><th>개선율(%)</th></tr><tr><td><?= field($data, 'aggregation_date', 'date') ?></td><?php foreach (['total', 'acceptable', 'target', 'completed', 'pending', 'rate'] as $key): ?><td><?= field($data, 'summary_' . $key, 'number') ?></td><?php endforeach; ?></tr></table></div></section>
<section class="card"><h2>3. 정기 및 수시 위험성평가 검토 결과</h2><div class="scroll"><table><tr><th>구분</th><th>공정·작업명</th><th>주요 위험요인</th><th>검토결과</th><th>조치사항</th><th>근로자 참여</th></tr><?php foreach (['regular' => '정기', 'temporary' => '수시', 'additional' => ''] as $key => $label): ?><tr><td><?= $label ?></td><?php foreach (['work', 'hazard', 'review', 'action', 'worker'] as $column): ?><td><?= field($data, $key . '_' . $column) ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div></section>
<section class="card"><h2>4. 개선대책 유형별 현황</h2><div class="scroll"><table><tr><th>구분</th><?php foreach ($types as $label): ?><th><?= $label ?></th><?php endforeach; ?></tr><?php foreach (['target' => '개선대상(건)', 'completed' => '개선완료(건)', 'pending' => '미개선(건)', 'cost' => '개선비용(천원)'] as $key => $label): ?><tr><th><?= $label ?></th><?php foreach ($types as $type => $_): ?><td><?= field($data, 'counter_' . $key . '_' . $type, 'number') ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div></section>
<section class="card"><h2>5. 미개선 및 향후 계획</h2><div class="scroll"><table><tr><th>관리번호 및 공정·작업명</th><th>미개선 사유 및 잔여 위험요인</th><th>개선대책 및 완료 전 임시조치</th><th>담당자</th><th>완료예정일</th><th>확인계획</th></tr><?php foreach ([1, 2, 3] as $number): ?><tr><?php foreach (['number', 'reason', 'measure', 'person', 'due', 'check'] as $column): ?><td><?= field($data, 'plan_' . $number . '_' . $column, $column === 'due' ? 'date' : 'text') ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div><div class="fields two"><label>정기평가 반영 대상 공정·작업<?= field($data, 'reflection_target') ?></label><label>반영 예정일 및 담당자<?= field($data, 'reflection_date_person') ?></label><label>공유·교육 대상<?= field($data, 'education_target') ?></label><label>실시 예정일 및 방법<?= field($data, 'education_method') ?></label><label class="wide">종합 검토의견<?= text_field($data, 'opinion') ?></label></div></section>
<section class="card"><h2>6. 첨부문서 목록</h2><div class="scroll"><table><tr><th>번호</th><th>문서명</th><th>문서번호 또는 대상기간</th><th>수량</th><th>비고</th></tr><?php foreach ([1, 2, 3, 4] as $number): ?><tr><td><?= $number ?></td><td><?= field($data, 'attachment_' . $number . '_name') ?></td><td><?= field($data, 'attachment_' . $number . '_period') ?></td><td><?= field($data, 'attachment_' . $number . '_quantity') ?></td><td><?= field($data, 'attachment_' . $number . '_note') ?></td></tr><?php endforeach; ?></table></div></section><div class="actions"><a href="index.php#risk-assessment-clause" class="button secondary">매뉴얼로 돌아가기</a><button type="submit">저장하기</button><button type="submit" name="preview_print" value="1" formtarget="_blank">인쇄 미리보기</button></div></form></main></body></html>
