<?php
declare(strict_types=1);
require_once __DIR__.'/../risk_assessment/auth.php';
$user=auth_current_user();
if(!is_array($user)){header('Location: /risk_assessment/task_select.php');exit;}
if(!auth_can_manage($user)||trim((string)($user['login_id']??''))!=='5878'){http_response_code(403);exit('<!doctype html><html lang="ko"><meta charset="utf-8"><p>권한이 없습니다.</p></html>');}
require_once __DIR__.'/../risk_assessment/db_config.php';
require_once __DIR__.'/quarter_plan_storage.php';
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
$currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
$year=filter_var($_GET['year']??$currentYear,FILTER_VALIDATE_INT);$quarter=filter_var($_GET['quarter']??'',FILTER_VALIDATE_INT);
if($year===false||$year<1000||$year>9999||$quarter===false||$quarter<1||$quarter>4){http_response_code(400);exit('<!doctype html><html lang="ko"><meta charset="utf-8"><p>연도와 분기를 확인해 주세요.</p></html>');}
$template=quarter_plan_template();$values=quarter_plan_defaults($year,$quarter);$revision=0;$previous=null;$error='';
$message=$_SESSION['quarter_plan_message'][$year][$quarter]??'';unset($_SESSION['quarter_plan_message'][$year][$quarter]);
$_SESSION['quarter_plan_csrf']??=bin2hex(random_bytes(32));
try {
    $db=getDB();goal_plan_initialize($db);goal_plan_seed_raw($db);quarter_plan_initialize($db);quarter_plan_seed($db);
    $saved=quarter_plan_load($db,$year,$quarter);$previous=quarter_plan_load($db,$year-1,$quarter);
    if($saved){$values=$saved['rows'];$revision=$saved['revision'];}
    $values=quarter_plan_previous($values,$previous);
    $boardCounts=safety_near_miss_quarter($db,$year,$quarter);
    $values['qr18']=array_replace($values['qr18'],$boardCounts);
    $vflCounts=safety_vfl_quarter($year,$quarter);
    $values['qr25']=array_replace($values['qr25'],$vflCounts);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['quarter_plan_csrf'],$_POST['csrf']))throw new InvalidArgumentException('저장 요청을 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
        if((string)($_POST['year']??'')!==(string)$year||(string)($_POST['quarter']??'')!==(string)$quarter)throw new InvalidArgumentException('저장할 연도와 분기를 확인해 주세요.');
        $postedRevision=filter_var($_POST['revision']??'',FILTER_VALIDATE_INT);
        if($postedRevision===false||$postedRevision<0)throw new InvalidArgumentException('문서 버전을 확인할 수 없습니다.');
        $postedRows=$_POST['rows']??null;
        if(is_array($postedRows))foreach($values as $id=>$row)if(is_array($postedRows[$id]??null))$postedRows[$id]['previous']=$row['previous'];
        if(is_array($postedRows['qr8']??null))foreach(['m1','m2','m3'] as $key)if(is_string($postedRows['qr8'][$key]??null))$postedRows['qr8'][$key]=quarter_plan_percent_store($postedRows['qr8'][$key]);
        if(is_array($postedRows)&&is_array($postedRows['qr18']??null))$postedRows['qr18']=array_replace($postedRows['qr18'],$boardCounts);
        if(is_array($postedRows)&&is_array($postedRows['qr25']??null))$postedRows['qr25']=array_replace($postedRows['qr25'],$vflCounts);
        $values=quarter_plan_previous(quarter_plan_validate($postedRows),$previous);$revision=$postedRevision;
        // Preserve imported raw results until the corresponding monthly values change.
        if($saved)foreach($values as $id=>&$row){$old=$saved['rows'][$id]??null;if($old&&$row['m1']===$old['m1']&&$row['m2']===$old['m2']&&$row['m3']===$old['m3'])$row['result']=$old['result'];}unset($row);
        $values['qr18']=array_replace($values['qr18'],$boardCounts);
        $values['qr25']=array_replace($values['qr25'],$vflCounts);
        quarter_plan_save($db,$year,$quarter,$values,$revision,(string)$user['login_id']);
        $_SESSION['quarter_plan_message'][$year][$quarter]=$year.'년 '.$quarter.'분기 계획·실적을 저장하고 연간표에 반영했습니다.';
        header('Location: goal_plan_quarter.php?year='.$year.'&quarter='.$quarter);exit;
    }
} catch(Throwable $exception) {
    $error=$exception instanceof InvalidArgumentException?$exception->getMessage():'DB에서 분기 계획·실적을 불러오거나 저장하지 못했습니다.';
    if(!$exception instanceof InvalidArgumentException)error_log('Quarter plan: '.$exception->getMessage());
    if($_SERVER['REQUEST_METHOD']==='POST'&&is_array($_POST['rows']??null)) {
        foreach($values as $id=>&$row)foreach(['plan','p1','p2','p3','m1','m2','m3','note'] as $key)if(is_string($_POST['rows'][$id][$key]??null))$row[$key]=$id==='qr8'&&in_array($key,['m1','m2','m3'],true)?quarter_plan_percent_store($_POST['rows'][$id][$key]):$_POST['rows'][$id][$key];unset($row);
        $values=quarter_plan_previous($values,$previous);
        if(isset($boardCounts))$values['qr18']=array_replace($values['qr18'],$boardCounts);
        if(isset($vflCounts))$values['qr25']=array_replace($values['qr25'],$vflCounts);
    }
}
$monthStart=($quarter-1)*3+1;
?>
<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $year ?>년 <?= $quarter ?>분기 세부계획 및 실적</title><link rel="stylesheet" href="assets/goal-plan.css"><link rel="stylesheet" href="assets/quarter-plan.css?v=2">
</head><body class="quarter-page">
<header><div class="plan-header"><div><small>SAFETY MANAGEMENT SYSTEM</small><h1><?= $year ?>년 <?= $quarter ?>분기 세부계획 및 실적</h1></div><nav class="plan-return" aria-label="CSM으로 이동"><a href="/CSM/">← CSM 포털로 돌아가기</a><a href="/CSM/management_system.php?section=1.2">안전보건활동으로 돌아가기</a></nav></div></header>
<main>
    <div class="page-heading"><h2>안전보건활동 <?= $quarter ?>분기 추진계획 및 실적</h2><p>분기 계획과 각 월의 계획·실적을 입력하세요. 월 계획 수량을 입력하면 해당 월의 실적 입력란이 활성화됩니다. 월 계획이 비어 있으면 1.2.2 월별 출력에서 그 항목은 제외됩니다. 분기 실적은 월 실적을 자동 합산하며, 인원수는 입력된 월의 평균으로 계산합니다.</p><p>아차사고 실적은 게시판의 사고 발생일 기준 건수를 자동으로 표시합니다.</p><p>VFL 실적은 <a href="/CSM/leadership.php?year=<?= $year ?>&amp;item=1.1.3">1.1.3 실행 이력</a>의 활동일 기준으로 1건당 1회 자동 반영됩니다.</p></div>
    <nav class="quarter-nav" aria-label="분기 선택"><?php for($q=1;$q<=4;$q++): ?><a class="button secondary" href="goal_plan_quarter.php?year=<?= $year ?>&amp;quarter=<?= $q ?>" <?= $q===$quarter?'aria-current="page"':'' ?>><?= $q ?>분기</a><?php endfor; ?></nav>
    <?php if($error||$message): ?><div class="notice <?= $error?'error':'' ?>" role="status"><?= h($error?:$message) ?></div><?php endif; ?>
    <form id="quarter-plan-form" method="post" action="goal_plan_quarter.php?year=<?= $year ?>&amp;quarter=<?= $quarter ?>" data-year="<?= $year ?>" data-quarter="<?= $quarter ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['quarter_plan_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="quarter" value="<?= $quarter ?>"><input type="hidden" name="revision" value="<?= $revision ?>">
        <div class="table-scroll"><table class="editor-table quarter-table"><colgroup><col style="width:300px"><col style="width:100px"><?php for($i=0;$i<6;$i++): ?><col style="width:110px"><?php endfor; ?><col style="width:180px"></colgroup>
        <thead><tr><th colspan="2" rowspan="2">구분</th><th><?= $year-1 ?>년</th><th colspan="6"><?= $year ?>년</th></tr><tr><th>실적</th><th>분기 계획</th><th><?= $quarter ?>분기 실적</th><?php for($m=$monthStart;$m<$monthStart+3;$m++): ?><th><?= $m ?>월<br><small>계획 / 실적</small></th><?php endfor; ?><th>비고</th></tr></thead>
        <tbody><?php foreach($template['rows'] as $item): ?>
            <?php if($item['type']==='section'): ?><tr class="section <?= $item['major']?'major':'' ?>"><th colspan="9"><?= h($item['title']) ?></th></tr>
            <?php else: $id=$item['id']; ?>
                <tr data-row-id="<?= h($id) ?>"><td class="cell-name"><div class="cell-output"><?= h($item['name']) ?></div><?php if($item['percent_result']): ?><small class="percent-help">월별 값: 100 = 100%, 50 = 50%</small><?php endif; ?></td><td><div class="cell-output"><?= h($item['unit']) ?></div></td>
                <?php foreach(['previous','plan','result','m1','m2','m3','note'] as $key): ?><td>
                    <?php if($key==='result'): ?><output data-field="result" class="quarter-result"><?= h($values[$id]['result']===''?'':($item['percent_result']?quarter_plan_number((float)$values[$id]['result']*100).'%':$values[$id]['result'])) ?></output>
                    <?php elseif($key==='previous'): ?><output data-field="<?= $key ?>" class="quarter-result"><?= h($values[$id][$key]) ?></output>
                    <?php elseif($key==='note'): ?><textarea rows="1" name="rows[<?= h($id) ?>][note]" data-field="note" aria-label="<?= h($item['name']) ?> 비고" maxlength="1000"><?= h($values[$id]['note']) ?></textarea>
                    <?php elseif(in_array($key,['m1','m2','m3'],true)): $slot=substr($key,1); $month=$monthStart+(int)$slot-1; ?><div class="month-plan-pair"><label>계획<input type="number" step="0.000001" min="0" max="1000000000" name="rows[<?= h($id) ?>][p<?= $slot ?>]" data-field="p<?= $slot ?>" aria-label="<?= h($item['name'].' '.$month.'월 계획') ?>" value="<?= h($item['percent_result']?quarter_plan_percent_display((string)($values[$id]['p'.$slot]??''),true):($values[$id]['p'.$slot]??'')) ?>"></label><label>실적<?php if(in_array($id,['qr18','qr25'],true)): ?><output data-field="<?= $key ?>" class="quarter-result"><?= h($values[$id][$key]) ?></output><?php else: ?><input type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?|\-" name="rows[<?= h($id) ?>][<?= $key ?>]" data-field="<?= $key ?>" aria-label="<?= h($item['name'].' '.$month.'월 실적') ?>" value="<?= h($item['percent_result']?quarter_plan_percent_display((string)$values[$id][$key]):$values[$id][$key]) ?>"><?php endif; ?></label></div>
                    <?php else: $label=['plan'=>'계획','m1'=>$monthStart.'월 실적','m2'=>($monthStart+1).'월 실적','m3'=>($monthStart+2).'월 실적'][$key]; ?><input <?= $key==='plan'?'type="number" step="0.000001" min="0" max="1000000000"':'type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?|\\-"' ?> name="rows[<?= h($id) ?>][<?= $key ?>]" data-field="<?= $key ?>" aria-label="<?= h($item['name'].' '.$label) ?>" value="<?= h($values[$id][$key]) ?>">
                    <?php endif; ?>
                </td><?php endforeach; ?></tr>
            <?php endif; ?>
        <?php endforeach; ?></tbody></table></div>
        <div class="actions"><a class="button secondary" href="/CSM/">CSM 포털로 돌아가기</a><a class="button secondary" href="goal_plan.php">연간 목표 및 세부계획으로 돌아가기</a><button type="submit" id="quarter-plan-save">저장하기</button><button type="button" id="quarter-preview-open">인쇄 미리보기</button></div>
    </form>
</main>
<dialog id="quarter-preview" aria-labelledby="quarter-preview-title"><div class="preview-toolbar"><h2 id="quarter-preview-title">분기 계획·실적 인쇄 미리보기</h2><button type="button" id="quarter-print">인쇄 / PDF 저장</button><button type="button" class="secondary" id="quarter-preview-close">닫기</button><p id="quarter-overflow" hidden>한 장을 초과합니다. 긴 비고 내용을 줄여 주세요.</p></div><div id="quarter-pages"></div></dialog>
<script id="quarter-template" type="application/json"><?= json_encode($template['rows'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script><script src="assets/quarter-plan.js?v=3"></script>
</body></html>
