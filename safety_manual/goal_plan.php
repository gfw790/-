<?php
declare(strict_types=1);
require_once __DIR__ . '/../risk_assessment/auth.php';
$user = auth_current_user();
if (!is_array($user)) { header('Location: /risk_assessment/task_select.php'); exit; }
if (!auth_can_manage($user) || trim((string)($user['login_id'] ?? '')) !== '5878') {
    http_response_code(403); exit('<!doctype html><html lang="ko"><meta charset="utf-8"><title>권한이 없습니다</title><p>이 페이지는 지정된 관리자 계정만 사용할 수 있습니다.</p></html>');
}
require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/goal_plan_storage.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405); header('Allow: GET, HEAD'); exit;
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$year = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$previewOnly=($_GET['preview']??'')==='1';$blankPreview=$previewOnly&&($_GET['blank']??'')==='1';
if($previewOnly||isset($_GET['year'])){$requestedYear=filter_var($_GET['year']??$year,FILTER_VALIDATE_INT);if($requestedYear===false||$requestedYear<1000||$requestedYear>9999){http_response_code(400);exit('올바른 연도를 선택해 주세요.');}$year=$requestedYear;}
$template = goal_plan_template(); $fields = goal_plan_fields(); $values = goal_plan_defaults($year); $error = ''; $previousRecord = null; $planBreakdown=[];
try {
    $db = getDB(); goal_plan_initialize($db); goal_plan_seed_raw($db);
    if($blankPreview){
        foreach($values as &$row)foreach($row as $key=>&$value)if(!in_array($key,['name','frequency','unit'],true))$value='';unset($value,$row);
    }else{
        if($previewOnly&&!goal_plan_load($db,$year))throw new InvalidArgumentException($year.'년의 저장된 추진계획이 없습니다.');
        $state = goal_plan_year_state($db, $year);
        $values = $state['rows']; $previousRecord = $state['previous'];
        $planBreakdown=$state['plan_breakdown'];
    }
} catch (Throwable $exception) {
    $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'DB에서 계획을 불러오거나 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    if (!$exception instanceof InvalidArgumentException) error_log('Goal plan: ' . $exception->getMessage());
}
if($previewOnly&&$error!==''){http_response_code($exception instanceof InvalidArgumentException?404:500);echo '<!doctype html><html lang="ko"><meta charset="utf-8"><p role="alert">'.h($error).'</p></html>';exit;}
?>
<!doctype html><html lang="ko"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>목표 및 세부계획 만들기</title><link rel="stylesheet" href="assets/goal-plan.css?v=<?= filemtime(__DIR__.'/assets/goal-plan.css') ?>">
</head><body data-preview-only="<?= $previewOnly&&$error===''?'1':'0' ?>">
<header><div class="plan-header"><div><small>SAFETY MANAGEMENT SYSTEM</small><h1>목표 및 세부계획 만들기</h1></div><nav class="plan-return" aria-label="CSM으로 이동"><a href="/CSM/">← CSM 포털로 돌아가기</a><a href="/CSM/management_system.php?section=1.2">안전보건활동으로 돌아가기</a></nav></div></header>
<main>
    <div class="page-heading"><h2><?= $year ?>년 안전보건활동 목표 및 추진계획</h2><p>저장된 계획과 실적을 조회합니다. 분기 제목을 누르면 해당 분기 페이지가 새 탭으로 열립니다.<?php if ($previousRecord !== null): ?> <?= $year-1 ?>년 실적은 해당 연도에 저장된 실적율에서 자동으로 불러옵니다.<?php endif; ?></p><p>VFL 실적은 <a href="/CSM/leadership.php?year=<?= $year ?>&amp;item=1.1.3">1.1.3 실행 이력</a>을 기준으로 분기별 횟수와 계획 대비 연간 실적률을 자동 반영합니다.</p></div>
    <?php if ($error): ?><div role="alert" class="notice error"><?= h($error) ?></div><?php endif; ?>
    <section class="risk-workflow-guide" aria-labelledby="risk-workflow-title">
        <div class="risk-workflow-heading">
            <div><small>전년도 말에 세우는 연간 계획</small><h3 id="risk-workflow-title">연간 목표는 먼저 정하고, 평가 후 실행 대상을 확정합니다</h3></div>
            <a href="/CSM/activity_plan.php?year=<?= $year ?>#editor">1.2.1 계획 근거 작성하기 →</a>
        </div>
        <p>전년도 말에는 올해 5월 정기위험성평가에서 나올 개선 건수를 알 수 없습니다. 따라서 전년도 평가·수시평가 실적과 미완료 조치를 근거로 <b>실시 시기, 심의·점검 횟수, 기한 도래 조치의 확인율</b>을 연간 목표로 정합니다. 개별 개선조치와 건수는 정기평가 후 확정합니다.</p>
        <div class="risk-workflow-steps">
            <div><strong>상반기 · 정기평가 전</strong><p>전년도 미완료 항목과 올해 발생한 수시평가를 심의해 <b>신규 추가 · 기존 항목 보완 · 유지</b>를 판정합니다. 정기평가 반영 대상을 기록합니다.</p><small>연간 목표 예시: 상반기 심의 1회, 심의 대상 판정률 100%<br>실적: 판정 완료 건수 ÷ 심의 대상 건수 × 100</small></div>
            <div><strong>하반기 · 정기평가 후</strong><p>정기평가 결과로 개선조치 목록·담당자·기한을 확정합니다. 하반기에는 기한이 된 조치의 이행과 새 수시평가 결과를 점검하고 미완료 사항의 이월을 결정합니다.</p><small>연간 목표 예시: 하반기 이행점검 1회, 기한 도래 조치 확인율 100%<br>실적: 확인 완료 건수 ÷ 기한 도래 건수 × 100</small></div>
        </div>
        <p class="risk-workflow-note">정기평가 후 예상과 다른 규모의 조치가 나오면 근거를 남기고 연간 계획을 개정해 다시 협의·승인합니다. 분모가 0이면 달성률 대신 ‘대상 없음’으로 기록합니다. 긴급한 개선조치는 정기평가를 기다리지 않고 진행합니다. 위 수치는 운영 예시이며 아래 표의 기존 목표를 자동으로 변경하지 않습니다.</p>
    </section>
    <section id="goal-plan-view" data-year="<?= $year ?>">
        <div class="table-scroll"><table class="editor-table"><caption class="sr-only"><?= $year ?>년 목표 및 세부추진계획 조회</caption>
            <colgroup><col style="width:280px"><col style="width:115px"><col style="width:65px"><?php for ($i=0;$i<7;$i++): ?><col style="width:100px"><?php endfor; ?><col style="width:160px"></colgroup>
            <thead><tr><th colspan="3" rowspan="2">구분</th><th colspan="8">연간 계획 및 실적</th></tr><tr><th><?= $year-1 ?>년</th><th colspan="6"><?= $year ?>년</th><th rowspan="2">비고</th></tr><tr><th>활동·목표</th><th>주기·기준</th><th>단위</th><th>실적</th><th>계획</th><th>실적율</th><?php for ($quarter=1;$quarter<=4;$quarter++): ?><th><a class="quarter-button" href="goal_plan_quarter.php?year=<?= $year ?>&amp;quarter=<?= $quarter ?>" target="_blank" rel="noopener" aria-label="<?= $year ?>년 <?= $quarter ?>분기 페이지 열기 (새 탭)"><?= $quarter ?>분기</a></th><?php endfor; ?></tr></thead>
            <tbody>
            <?php foreach ($template['rows'] as $row): ?>
                <?php if ($row['type'] === 'section'): ?><tr class="section <?= $row['major'] ? 'major' : '' ?>"><th colspan="11"><?= h($row['title']) ?></th></tr>
                <?php else: $id=$row['id']; ?>
                    <tr data-row-id="<?= h($id) ?>">
                        <?php foreach ($fields as $key=>$label): ?><td class="cell-<?= h($key) ?>"><?php if($key==='plan'): ?><button type="button" class="cell-output plan-detail-link" data-field="plan" data-plan-id="<?= h($id) ?>" aria-haspopup="dialog" aria-controls="plan-detail-dialog" aria-label="<?= h($values[$id]['name']) ?> 분기별 계획 보기"><?= h($values[$id][$key]) ?></button><?php else: ?><div class="cell-output" data-field="<?= h($key) ?>"><?= h($values[$id][$key]) ?></div><?php endif; ?></td><?php endforeach; ?>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="actions"><a class="button secondary" href="/CSM/">CSM 포털로 돌아가기</a><a class="button secondary" href="index.php">매뉴얼로 돌아가기</a><button type="button" id="goal-plan-preview-open">인쇄 미리보기</button></div>
    </section>
</main>
<dialog id="goal-plan-preview" aria-labelledby="preview-title"><div class="preview-toolbar"><h2 id="preview-title">인쇄 미리보기</h2><button type="button" id="goal-plan-print">인쇄 / PDF 저장</button><button type="button" class="secondary" id="goal-plan-preview-close">닫기</button><p id="plan-overflow" hidden role="alert">내용이 A4 한 장을 초과합니다. 긴 항목이나 비고를 줄여 주세요.</p></div><div id="goal-plan-pages"></div></dialog>
<script id="plan-template" type="application/json"><?= json_encode($template['rows'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<dialog id="plan-detail-dialog" aria-labelledby="plan-detail-title"><div class="preview-toolbar"><h2 id="plan-detail-title">분기별 계획</h2><button type="button" class="secondary" id="plan-detail-close">닫기</button></div><div class="plan-detail-body"><p id="plan-detail-name"></p><table class="plan-detail-table"><thead><tr><th>분기</th><th>계획값</th></tr></thead><tbody id="plan-detail-rows"></tbody><tfoot><tr><th>합계</th><td id="plan-detail-total"></td></tr></tfoot></table><p>미입력 항목은 합계에서 제외됩니다.</p></div></dialog>
<script id="plan-breakdown" type="application/json"><?= json_encode($planBreakdown,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/goal-plan.js?v=<?= filemtime(__DIR__.'/assets/goal-plan.js') ?>"></script>
</body></html>
