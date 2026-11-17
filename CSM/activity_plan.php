<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/activity_plan_store.php';
require_once __DIR__.'/scoring.php';
$user=csm_require_auth();
header('Cache-Control: no-store');
if (!leadership_allowed($user)) { http_response_code(403); exit('이 페이지는 지정된 관리자만 사용할 수 있습니다.'); }
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD','POST'], true)) { http_response_code(405); header('Allow: GET, HEAD, POST'); exit; }
$escape=static fn($value)=>htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$today=(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year=filter_var($_GET['year'] ?? substr($today,0,4), FILTER_VALIDATE_INT);
if ($year === false || $year < 2000 || $year > 2100) { http_response_code(400); exit('관리 연도를 확인해 주세요.'); }
$base='/CSM/activity_plan.php?year='.$year;
$_SESSION['activity_plan_csrf'] ??= bin2hex(random_bytes(32));
$db=leadership_db();
if (isset($_GET['file'])) {
    $fileId=filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $query=$db->prepare("SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE f.id=? AND r.year=? AND r.item='1.2.1' AND f.deleted_at IS NULL");
    $query->execute([$fileId ?: 0, $year]); $file=$query->fetch();
    $path=$file ? __DIR__.'/storage/leadership_evidence/'.$file['stored_name'] : '';
    if (!$file || !preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', $file['stored_name']) || !is_file($path)) { http_response_code(404); exit('첨부문서를 찾을 수 없습니다.'); }
    header('X-Content-Type-Options: nosniff'); header('Content-Type: '.$file['mime']);
    $inline=($_GET['inline'] ?? '') === '1' && $file['mime'] === 'application/pdf';
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="evidence"; filename*=UTF-8\'\''.rawurlencode($file['original_name']));
    header('Content-Length: '.filesize($path));
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($path);
    exit;
}
$records=activity_plan_records($db,$year);
$id=filter_var($_POST['id'] ?? $_GET['edit'] ?? $_GET['version'] ?? (isset($_GET['new']) ? 0 : (array_key_first($records) ?? 0)), FILTER_VALIDATE_INT);
if ($id === false || $id < 0) { http_response_code(400); exit('계획 버전을 확인해 주세요.'); }
if ($id > 0 && !isset($records[$id])) { http_response_code(404); exit('해당 연도의 계획 버전을 찾을 수 없습니다.'); }
$selected=$records[$id] ?? null;
$form=$selected['data'] ?? activity_plan_form();
if (!$selected && $records) {
    $latest=reset($records)['data'];
    $form['basis']=$latest['basis'] ?? $form['basis'];
    $form['basis_details']=activity_plan_basis_details($latest['basis_details'] ?? []);
}
$revision=(int)($selected['revision'] ?? 0);
$currentPlan=null; $planError=''; $error='';
try {
    require_once dirname(__DIR__).'/risk_assessment/db_config.php';
    require_once dirname(__DIR__).'/safety_manual/quarter_plan_storage.php';
    $planDb=getDB(); goal_plan_initialize($planDb); quarter_plan_initialize($planDb);
    goal_plan_seed_raw($planDb); quarter_plan_seed($planDb);
    $currentPlan=activity_plan_snapshot($planDb,$year);
} catch (Throwable $exception) { $planError='현재 연간 계획을 불러오지 못했습니다. 기존에 보관한 계획과 기록은 확인할 수 있습니다.'; error_log('CSM activity plan source: '.$exception->getMessage()); }
$snapshot=$selected['data']['plan_snapshot'] ?? $currentPlan;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['activity_plan_csrf'],$_POST['csrf'])) throw new InvalidArgumentException('저장 요청을 확인할 수 없습니다. 새로고침해 주세요.');
        if ((string)($_POST['year'] ?? '') !== (string)$year) throw new InvalidArgumentException('저장할 연도를 확인해 주세요.');
        $postedRevision=filter_var($_POST['revision'] ?? '',FILTER_VALIDATE_INT);
        if ($postedRevision === false || $postedRevision < 0) throw new InvalidArgumentException('기록 버전을 확인해 주세요.');
        $revision=$postedRevision;
        if (isset($_POST['file_action'])) {
            $fileId=filter_var($_POST['file_id'] ?? '',FILTER_VALIDATE_INT);
            if (!$fileId || !is_string($_POST['file_action'])) throw new InvalidArgumentException('첨부문서를 확인해 주세요.');
            leadership_change_file($db,$fileId,$id,$postedRevision,'1.2.1',$year,$_POST['file_action'],(string)$user['login_id']);
        } else {
            foreach (activity_plan_form() as $key=>$default) {
                if ($key === 'basis') { foreach (activity_plan_basis_fields() as $basisKey=>$_) if (is_string($_POST['basis'][$basisKey] ?? null)) $form['basis'][$basisKey]=$_POST['basis'][$basisKey]; }
                elseif ($key === 'basis_details') $form[$key]=activity_plan_basis_details($_POST[$key] ?? []);
                elseif (is_string($_POST[$key] ?? null)) $form[$key]=$_POST[$key];
            }
            if (!$snapshot) throw new InvalidArgumentException('연간 활동계획을 불러온 뒤 새 버전을 저장해 주세요.');
            if (!is_string($_POST['plan_hash'] ?? null) || !hash_equals($snapshot['hash'],$_POST['plan_hash'])) throw new InvalidArgumentException('화면을 연 이후 연결할 계획이 변경되었습니다. 새로고침하여 확인해 주세요.');
            $record=activity_plan_validate($_POST,$year,$snapshot);
            if (!activity_plan_status($record['payload'],[])['planned']) throw new InvalidArgumentException('연간 또는 분기 활동계획을 먼저 입력해 주세요.');
            $uploads=[];
            foreach (activity_plan_file_kinds() as $kind=>$label) foreach (leadership_uploads($_FILES[$kind.'_files'] ?? []) as $upload) { $upload['file_kind']=$kind; $uploads[]=$upload; }
            foreach ($uploads as $upload) {
                if ($upload['file_kind']==='consultation' && ($record['payload']['consultation_date']==='' || $record['payload']['participants']==='')) throw new InvalidArgumentException('협의록을 첨부할 때 협의일과 참석자를 입력해 주세요.');
                if ($upload['file_kind']==='approval' && ($record['payload']['approval_date']==='' || $record['payload']['approver']==='')) throw new InvalidArgumentException('승인본을 첨부할 때 승인일과 승인자를 입력해 주세요.');
            }
            $id=leadership_save($db,$record,$id,$postedRevision,$uploads,(string)$user['login_id']);
        }
        $_SESSION['activity_plan_message']='계획 버전과 협의·승인 기록을 저장했습니다.';
        header('Location: '.$base.'&version='.$id.'#record-'.$id,true,303); exit;
    } catch (Throwable $exception) {
        http_response_code($exception instanceof InvalidArgumentException ? 422 : 500);
        $error=$exception instanceof InvalidArgumentException ? $exception->getMessage() : '저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        if (!$exception instanceof InvalidArgumentException) error_log('CSM activity plan save: '.$exception->getMessage());
    }
}
$message=$_SESSION['activity_plan_message'] ?? ''; unset($_SESSION['activity_plan_message']);
$files=$removed=[];
$query=$db->prepare("SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE r.item='1.2.1' AND r.year=? ORDER BY f.id");
$query->execute([$year]);
foreach ($query->fetchAll() as $file) { if ($file['deleted_at'] === null) $files[$file['record_id']][]=$file; else $removed[$file['record_id']][]=$file; }
$status=activity_plan_status($selected['data'] ?? ['plan_snapshot'=>$snapshot],$files[$id] ?? []);
$openEditor=isset($_GET['edit'], $selected) || !$selected || $error !== '';
$fileKinds=activity_plan_file_kinds();
$itemScore=csm_activity_plan_summary($db,$year,2.0);
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>1.2.1 활동계획 협의·승인 · CSM</title><link rel="stylesheet" href="leadership.css?v=20261110-meeting-pdf"><link rel="stylesheet" href="activity_plan.css?v=5-risk-fields"></head><body>
<header><div><a href="management_system.php?section=1.2&amp;year=<?= $year ?>">← 안전보건활동</a><strong>1.2.1 활동계획 협의·승인</strong><span><?= $escape($user['name']) ?></span></div></header>
<main><div class="heading"><div><small>안전보건활동계획 수립</small><h1><?= $year ?>년 활동계획 협의·승인</h1><p>연간 계획을 연결하고 목표 수립 근거, 근로자 협의, 경영책임자 승인 증빙을 관리합니다.</p></div><form method="get"><label for="year">관리 연도</label><input id="year" name="year" type="number" min="2000" max="2100" value="<?= $year ?>" required><button>조회</button></form></div>
<?php if ($error): ?><p class="error" role="alert"><?= $escape($error) ?> 첨부파일은 다시 선택해 주세요.</p><?php endif; ?>
<?php if ($message): ?><p class="message" role="status"><?= $escape($message) ?></p><?php endif; ?>
<?php if ($planError): ?><p class="error" role="alert"><?= $escape($planError) ?></p><?php endif; ?>
<section class="panel"><div class="section-title"><h2>연간 목표·추진계획 연결</h2><div class="quick-links"><a class="button" href="/safety_manual/goal_plan.php?year=<?= $year ?>">연간 계획 보기</a><a class="button" href="<?= $escape($base) ?>&amp;new=1#editor">현재 계획으로 새 버전 작성</a></div></div><p>저장 시점의 목표와 분기별 계획을 보관합니다. 실적의 증가로는 계획 버전이 바뀌지 않습니다.</p><p class="hint">위험성평가의 상·하반기 심의 기준과 목표 산식은 <a href="/safety_manual/goal_plan.php?year=<?= $year ?>">연간 계획 화면의 운영 안내</a>에서 확인할 수 있습니다.</p><p class="hint">근로자 협의나 승인 후 계획을 변경했다면 새 버전으로 협의·승인 기록을 남겨 주세요. 이전 버전의 기록과 서명본은 유지됩니다.</p></section>
<div class="plan-workflow" aria-label="선택한 계획 버전의 준비 현황">
<?php foreach ([['계획 연결',$selected && $status['planned']],['정량목표·수립 근거',$status['quantitative'] && $status['basis']],['근로자 협의·회의록',$status['consulted']],['경영책임자 승인본',$status['approved']]] as $step=>[$label,$done]): ?><div class="panel <?= $done ? 'complete' : '' ?>"><small>STEP <?= $step+1 ?></small><strong><?= $escape($label) ?></strong><span><?= $done ? '등록 완료' : '입력·증빙 확인 필요' ?></span></div><?php endforeach; ?>
</div>
<section class="panel score-panel"><div class="section-title"><h2>1.2.1 반영 점수 <?= $escape($itemScore['earned']) ?> / 2점</h2><span><?= $itemScore['state']==='assessed' ? '등록 자료 자동 반영' : '계획서·증빙 확인 필요' ?></span></div><p class="hint">점수는 같은 계획 버전의 연간 계획과 첨부 문서를 기준으로 계산합니다. 계획서만 확인되면 1점, 경영책임자 승인까지 확인되면 1.6점, 정량목표와 네 가지 수립 근거가 기록되면 1.8점, 근로자 협의록까지 확인되면 2점입니다. 계획서나 승인본 증빙이 없으면 0점입니다.</p></section>
<details class="panel editor" id="editor" <?= $openEditor ? 'open' : '' ?>><summary><?= $selected ? '버전 #'.$id.' 협의·승인 기록 수정' : '새 계획 버전 기록' ?></summary>
<?php if ($selected && $currentPlan && $snapshot['hash'] !== $currentPlan['hash']): ?><p class="error">현재 연간 계획이 이 버전과 다릅니다. 아래 내용은 보관된 계획에 대한 기록입니다. 변경된 계획의 협의·승인은 새 버전으로 작성해 주세요.</p><?php endif; ?>
<form method="post" action="<?= $escape($base) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_plan_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="revision" value="<?= $revision ?>"><input type="hidden" name="plan_hash" value="<?= $escape($snapshot['hash'] ?? '') ?>">
<?php require __DIR__.'/activity_plan_basis_fields.php'; ?>
<fieldset><legend>2. 근로자 협의</legend><div class="fields"><label>협의일<input type="date" name="consultation_date" max="<?= $today ?>" value="<?= $escape($form['consultation_date']) ?>"></label><label>참석자·근로자 대표<input name="participants" maxlength="4000" value="<?= $escape($form['participants']) ?>"></label><label>근로자 의견<textarea name="opinions" rows="4" maxlength="4000"><?= $escape($form['opinions']) ?></textarea></label><label>계획 반영 내용<textarea name="reflection" rows="4" maxlength="4000"><?= $escape($form['reflection']) ?></textarea></label></div><label>근로자 협의록·참석 서명부<input type="file" name="consultation_files[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label></fieldset>
<fieldset><legend>3. 경영책임자 승인</legend><div class="fields"><label>승인일<input type="date" name="approval_date" max="<?= $today ?>" value="<?= $escape($form['approval_date']) ?>"></label><label>승인자<input name="approver" maxlength="150" value="<?= $escape($form['approver']) ?>"></label></div><label>서명된 승인본<input type="file" name="approval_files[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label></fieldset>
<fieldset><legend>4. 추가 계획자료 (선택)</legend><label>승인본과 다른 초안·보완 자료가 있을 때만 첨부<input type="file" name="plan_files[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label></fieldset>
<p class="hint">PDF·JPG·PNG, 파일별 10MB, 한 번에 전체 합계 30MB. 각 구분별 최대 5개이며 기존 첨부문서는 유지됩니다.</p><div class="form-actions"><button class="primary" <?= !$snapshot ? 'disabled' : '' ?>><?= $selected ? '수정 내용 저장' : '계획 버전·기록 저장' ?></button><?php if ($selected): ?><a href="<?= $escape($base) ?>&amp;version=<?= $id ?>#record-<?= $id ?>">수정 취소</a><?php endif; ?></div></form></details>
<section class="panel"><div class="section-title"><h2>계획 버전 및 협의·승인 이력 <small><?= count($records) ?>건</small></h2></div>
<?php if (!$records): ?><p class="empty">저장된 버전이 없습니다. 연간 계획을 확인하고 목표 수립 근거부터 기록해 주세요.</p><?php endif; ?>
<?php foreach ($records as $row): $data=$row['data']; $rowStatus=activity_plan_status($data,$files[$row['id']] ?? []); $savedPlan=$data['plan_snapshot']; ?>
<article class="record plan-version" id="record-<?= $row['id'] ?>"><div class="section-title"><div><small>버전 #<?= $row['id'] ?> · 계획 보관 <?= $escape($savedPlan['captured_at']) ?></small><h3><?= $escape($row['title']) ?></h3></div><a class="button" href="<?= $escape($base) ?>&amp;edit=<?= $row['id'] ?>#editor">이 버전 수정</a></div>
<?php if ($currentPlan && $savedPlan['hash'] !== $currentPlan['hash']): ?><p class="plan-change-note">현재 연간 계획과 다른 보관 버전</p><?php endif; ?>
<dl class="plan-record-details"><dt>근로자 협의</dt><dd><?= $escape($data['consultation_date'] ?: '협의일 미등록') ?> · <?= $escape($data['participants'] ?: '참석자 미등록') ?> · <?= $rowStatus['consulted'] ? '회의록 등록 완료' : '입력·증빙 확인 필요' ?></dd><dt>경영책임자 승인</dt><dd><?= $escape($data['approval_date'] ?: '승인일 미등록') ?> · <?= $escape($data['approver'] ?: '승인자 미등록') ?> · <?= $rowStatus['approved'] ? '승인본 등록 완료' : '입력·증빙 확인 필요' ?></dd></dl>
<details><summary>수립 근거·협의 내용 보기</summary><div class="plan-record-text"><?php foreach (activity_plan_basis_fields() as $key=>$label): ?><h4><?= $escape($label) ?></h4><p class="pre"><?= $escape($data['basis'][$key] ?: '미등록') ?></p><?php endforeach; ?><h4>근로자 의견</h4><p class="pre"><?= $escape($data['opinions'] ?: '미등록') ?></p><h4>계획 반영 내용</h4><p class="pre"><?= $escape($data['reflection'] ?: '미등록') ?></p></div></details>
<details class="saved-plan"><summary>이 버전의 연간·분기 계획 보기</summary><div class="plan-table-scroll"><table><thead><tr><th>활동·목표</th><th>주기</th><th>단위</th><th>연간 계획</th><?php for($q=1;$q<=4;$q++): ?><th><?= $q ?>분기</th><?php endfor; ?></tr></thead><tbody><?php foreach ($savedPlan['rows'] as $planRow): ?><tr><td><?= $escape($planRow['name']) ?></td><td><?= $escape($planRow['frequency']) ?></td><td><?= $escape($planRow['unit']) ?></td><td><?= $escape($planRow['plan']) ?></td><?php foreach ($planRow['quarters'] as $quarterValue): ?><td><?= $escape($quarterValue) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details>
<div class="plan-file-groups"><?php foreach ($fileKinds as $kind=>$label): ?><section><h4><?= $escape($label) ?></h4><?php $groupFiles=array_filter($files[$row['id']] ?? [], static fn($file)=>$file['file_kind']===$kind); ?><?php if (!$groupFiles): ?><span class="muted">미첨부</span><?php endif; ?><?php foreach ($groupFiles as $file): ?><div class="file-item"><a href="<?= $escape($base) ?>&amp;file=<?= $file['id'] ?>"><?= $escape($file['original_name']) ?></a><?php if ($file['mime']==='application/pdf'): ?><a class="button" href="<?= $escape($base) ?>&amp;file=<?= $file['id'] ?>&amp;inline=1" data-pdf-preview data-pdf-name="<?= $escape($file['original_name']) ?>" data-pdf-download="<?= $escape($base) ?>&amp;file=<?= $file['id'] ?>" target="_blank" rel="noopener" aria-haspopup="dialog" aria-controls="meeting-pdf-viewer">PDF 보기</a><?php endif; ?><?php $action='delete_file'; require __DIR__.'/activity_plan_file_action.php'; ?></div><?php endforeach; ?></section><?php endforeach; ?></div>
<?php if ($removed[$row['id']] ?? []): ?><details class="deleted-files"><summary>삭제된 첨부문서 <?= count($removed[$row['id']]) ?>개</summary><?php foreach ($removed[$row['id']] as $file): ?><div class="file-item"><span><?= $escape($file['original_name']) ?></span><?php $action='restore_file'; require __DIR__.'/activity_plan_file_action.php'; ?></div><?php endforeach; ?></details><?php endif; ?>
<?php $history=$db->prepare('SELECT revision,editor,saved_at FROM leadership_history WHERE record_id=? ORDER BY id DESC');$history->execute([$row['id']]); ?><details class="history"><summary>저장·변경 이력</summary><?php foreach ($history->fetchAll() as $entry): ?><p>기록 <?= $entry['revision'] ?>차 · <?= $escape($entry['saved_at']) ?> · <?= $escape($entry['editor']) ?></p><?php endforeach; ?></details>
</article><?php endforeach; ?></section>
</main><dialog id="meeting-pdf-viewer" class="meeting-pdf-viewer" aria-labelledby="meeting-pdf-title"><div class="meeting-pdf-toolbar"><h2 id="meeting-pdf-title">PDF 첨부문서</h2><div class="quick-links"><a class="button" data-pdf-new-tab target="_blank" rel="noopener">새 탭에서 열기</a><a class="button" data-pdf-download-link>다운로드</a><button type="button" data-pdf-close>닫기</button></div></div><iframe class="meeting-pdf-frame" title="활동계획 증빙문서"></iframe><p class="hint">문서가 보이지 않으면 새 탭에서 열거나 다운로드해 주세요.</p></dialog><script src="leadership.js?v=20261110-meeting-pdf"></script></body></html>
