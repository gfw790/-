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
if($previewOnly){$requestedYear=filter_var($_GET['year']??$year,FILTER_VALIDATE_INT);if($requestedYear===false||$requestedYear<1000||$requestedYear>9999){http_response_code(400);exit('올바른 연도를 선택해 주세요.');}$year=$requestedYear;}
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
<header><div><small>SAFETY MANAGEMENT SYSTEM</small><h1>목표 및 세부계획 만들기</h1></div></header>
<main>
    <div class="page-heading"><h2><?= $year ?>년 안전보건활동 목표 및 추진계획</h2><p>저장된 계획과 실적을 조회합니다. 분기 제목을 누르면 해당 분기 페이지가 새 탭으로 열립니다.<?php if ($previousRecord !== null): ?> <?= $year-1 ?>년 실적은 해당 연도에 저장된 실적율에서 자동으로 불러옵니다.<?php endif; ?></p></div>
    <?php if ($error): ?><div role="alert" class="notice error"><?= h($error) ?></div><?php endif; ?>
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
        <div class="actions"><a class="button secondary" href="index.php">매뉴얼로 돌아가기</a><button type="button" id="goal-plan-preview-open">인쇄 미리보기</button></div>
    </section>
</main>
<dialog id="goal-plan-preview" aria-labelledby="preview-title"><div class="preview-toolbar"><h2 id="preview-title">인쇄 미리보기</h2><button type="button" id="goal-plan-print">인쇄 / PDF 저장</button><button type="button" class="secondary" id="goal-plan-preview-close">닫기</button><p id="plan-overflow" hidden role="alert">내용이 A4 한 장을 초과합니다. 긴 항목이나 비고를 줄여 주세요.</p></div><div id="goal-plan-pages"></div></dialog>
<script id="plan-template" type="application/json"><?= json_encode($template['rows'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<dialog id="plan-detail-dialog" aria-labelledby="plan-detail-title"><div class="preview-toolbar"><h2 id="plan-detail-title">분기별 계획</h2><button type="button" class="secondary" id="plan-detail-close">닫기</button></div><div class="plan-detail-body"><p id="plan-detail-name"></p><table class="plan-detail-table"><thead><tr><th>분기</th><th>계획값</th></tr></thead><tbody id="plan-detail-rows"></tbody><tfoot><tr><th>합계</th><td id="plan-detail-total"></td></tr></tfoot></table><p>미입력 항목은 합계에서 제외됩니다.</p></div></dialog>
<script id="plan-breakdown" type="application/json"><?= json_encode($planBreakdown,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/goal-plan.js?v=<?= filemtime(__DIR__.'/assets/goal-plan.js') ?>"></script>
</body></html>
