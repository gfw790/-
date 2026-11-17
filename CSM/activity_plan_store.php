<?php
declare(strict_types=1);
require_once __DIR__.'/leadership_store.php';

function activity_plan_basis_fields(): array
{
    return ['accidents'=>'재해현황', 'risk'=>'위험성평가 결과', 'performance'=>'성과측정', 'review'=>'경영자 검토'];
}

function activity_plan_basis_guides(): array
{
    return [
        'accidents'=>['question'=>'지난해 사고·아차사고에서 어떤 문제를 확인했나요?', 'finding'=>'예: 통로에서 걸림·넘어짐 아차사고가 반복됨', 'action'=>'예: 통로 정리 상태를 매월 점검하도록 계획에 반영'],
        'risk'=>['question'=>'전년도 평가 결과가 계획연도 목표로 어떻게 이어졌나요?', 'finding'=>'예: 전년도 개선조치 8건 중 연말 미완료 2건(추락 방지시설)', 'action'=>'예: 미완료 조치가 남아 있어 기한 도래 개선조치 확인율을 목표로 선정'],
        'performance'=>['question'=>'지난해 계획과 실적을 비교했을 때 부족한 부분은 무엇인가요?', 'finding'=>'예: 지난해 정기교육 실시율이 90%였음', 'action'=>'예: 올해 정기교육 실시율 목표를 100%로 설정'],
        'review'=>['question'=>'경영책임자가 검토하면서 요청한 사항은 무엇인가요?', 'finding'=>'예: 경영자 검토에서 현장 안전관찰 강화를 요청함', 'action'=>'예: 대표자 VFL을 매월 1회 수행하도록 계획에 반영'],
    ];
}

function activity_plan_basis_details(mixed $input): array
{
    $details=[];
    foreach (activity_plan_basis_fields() as $key=>$_) foreach (['source','finding','goal','metric','action'] as $field) {
        $details[$key][$field]=is_array($input) && is_string($input[$key][$field] ?? null) ? $input[$key][$field] : '';
    }
    return $details;
}

function activity_plan_file_kinds(): array
{
    return [
        'plan'=>'추가 계획자료 (선택)',
        'basis_accidents'=>'재해현황 근거',
        'basis_risk'=>'위험성평가 결과 근거',
        'basis_performance'=>'성과측정 근거',
        'basis_review'=>'경영자 검토 근거',
        'basis'=>'기존 공통 근거 자료',
        'consultation'=>'근로자 협의록',
        'approval'=>'경영책임자 승인본',
    ];
}

function activity_plan_snapshot(PDO $db, int $year): array
{
    require_once dirname(__DIR__).'/safety_manual/goal_plan_storage.php';
    $state = goal_plan_year_state($db, $year);
    $rows = [];
    foreach ($state['rows'] as $id=>$row) {
        $rows[$id] = array_intersect_key($row, array_flip(['name','frequency','unit','plan']));
        $rows[$id]['quarters'] = $state['plan_breakdown'][$id]['quarters'] ?? ['', '', '', ''];
    }
    // Results change throughout the year; only the actual plan defines its version.
    $content = ['year'=>$year, 'rows'=>$rows];
    $hash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return $content + ['hash'=>$hash, 'captured_at'=>(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s')];
}

function activity_plan_form(): array
{
    return ['basis'=>array_fill_keys(array_keys(activity_plan_basis_fields()), ''), 'basis_details'=>activity_plan_basis_details([]), 'consultation_date'=>'',
        'participants'=>'', 'opinions'=>'', 'reflection'=>'', 'approval_date'=>'', 'approver'=>''];
}

function activity_plan_validate(array $input, int $year, array $snapshot): array
{
    if (!is_array($input['basis'] ?? [])) throw new InvalidArgumentException('목표 수립 근거를 확인해 주세요.');
    if (($snapshot['year'] ?? null) !== $year || !isset($snapshot['hash'], $snapshot['rows'])) throw new InvalidArgumentException('연간 활동계획을 먼저 확인해 주세요.');
    $payload = activity_plan_form();
    foreach (activity_plan_basis_fields() as $key=>$label) $payload['basis'][$key] = leadership_text($input['basis'][$key] ?? '', $label, 4000);
    if (isset($input['basis_details']) && !is_array($input['basis_details'])) throw new InvalidArgumentException('질문형 입력 내용을 확인해 주세요.');
    foreach (activity_plan_basis_fields() as $key=>$label) {
        $detail=[];
        foreach (['source','finding','goal','metric','action'] as $field) $detail[$field]=leadership_text($input['basis_details'][$key][$field] ?? '', $label, in_array($field,['source','goal'],true) ? 30 : 1500);
        if ($key==='risk' && $detail['source']!=='' && !in_array($detail['source'],['regular','occasional','unresolved','other'],true)) throw new InvalidArgumentException('위험성평가 근거자료 유형을 다시 선택해 주세요.');
        if ($detail['goal'] !== '' && !isset($snapshot['rows'][$detail['goal']])) throw new InvalidArgumentException('관련 목표는 연결된 계획에서 선택해 주세요.');
        $payload['basis_details'][$key]=$detail;
        if (array_filter($detail, static fn($value)=>trim($value) !== '') || ($input['basis_mode'][$key] ?? '') === 'guided') {
            $lines=[];
            if ($key==='risk' && $detail['source']!=='') $lines[]='기준자료: '.(['regular'=>'전년도 정기위험성평가','occasional'=>'전년도 수시위험성평가','unresolved'=>'전년도 미완료 개선조치','other'=>'기타 위험성평가 자료'][$detail['source']]);
            if ($detail['finding'] !== '') $lines[]=($key==='risk' ? '확인된 실적·위험: ' : '확인한 내용: ').$detail['finding'];
            if ($detail['goal'] !== '') { $goal=$snapshot['rows'][$detail['goal']]; $lines[]='관련 목표: '.$goal['name'].($key==='risk' && trim((string)($goal['plan']??''))!=='' ? ' (계획값: '.$goal['plan'].($goal['unit']??'').')' : ''); }
            if ($key==='risk' && $detail['metric']!=='') $lines[]='측정 방법: '.$detail['metric'];
            if ($detail['action'] !== '') $lines[]=($key==='risk' ? '목표 선정 이유: ' : '계획 반영: ').$detail['action'];
            $payload['basis'][$key]=implode("\n",$lines);
        }
    }
    foreach (['participants'=>'협의 참석자', 'opinions'=>'근로자 의견', 'reflection'=>'계획 반영 내용', 'approver'=>'승인자'] as $key=>$label) {
        $payload[$key] = leadership_text($input[$key] ?? '', $label, $key === 'approver' ? 150 : 4000);
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    foreach (['consultation_date'=>'근로자 협의일', 'approval_date'=>'경영책임자 승인일'] as $key=>$label) {
        $payload[$key] = leadership_date($input[$key] ?? '', $label);
        if ($payload[$key] > $today) throw new InvalidArgumentException($label.'은 미래 날짜로 등록할 수 없습니다.');
    }
    if ($payload['consultation_date'] !== '' && $payload['approval_date'] !== '' && $payload['approval_date'] < $payload['consultation_date']) throw new InvalidArgumentException('승인일은 근로자 협의일 이후로 입력해 주세요.');
    $payload['plan_snapshot'] = $snapshot;
    return ['item'=>'1.2.1', 'year'=>$year, 'activity_date'=>$payload['approval_date'] ?: ($payload['consultation_date'] ?: $today),
        'title'=>$year.'년 안전보건활동계획 협의·승인', 'payload'=>$payload];
}

function activity_plan_records(PDO $db, int $year): array
{
    $query=$db->prepare("SELECT * FROM leadership_records WHERE item='1.2.1' AND year=? ORDER BY id DESC");
    $query->execute([$year]);
    $rows=[];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['data']=json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $rows[(int)$row['id']]=$row;
    }
    return $rows;
}

function activity_plan_status(array $data, array $files): array
{
    $counts=array_fill_keys(array_keys(activity_plan_file_kinds()), 0);
    foreach ($files as $file) {
        if (isset($counts[$file['file_kind']]) && empty($file['deleted_at']) && preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', $file['stored_name'])
            && is_file(__DIR__.'/storage/leadership_evidence/'.$file['stored_name'])) $counts[$file['file_kind']]++;
    }
    $snapshot=$data['plan_snapshot'] ?? [];
    $plans=array_column($snapshot['rows'] ?? [], 'plan');
    $planned=count(array_filter($plans, static fn($value)=>trim((string)$value) !== '')) > 0;
    $quantitative=count(array_filter($plans, static fn($value)=>preg_match('/^\d+(?:\.\d+)?\s*(?:회|건|인|hr|HR|%)?$/u', trim((string)$value)))) > 0;
    $basis=true;
    foreach (activity_plan_basis_fields() as $key=>$_) {
        $detail=$data['basis_details'][$key] ?? [];
        $hasDetails=(bool)array_filter($detail, static fn($value)=>trim($value) !== '');
        $complete=$hasDetails ? trim($detail['finding'] ?? '') !== '' && trim($detail['action'] ?? '') !== '' : trim($data['basis'][$key] ?? '') !== '';
        if (!$complete) $basis=false;
    }
    $consulted=($data['consultation_date'] ?? '') !== '' && ($data['participants'] ?? '') !== '' && ($data['opinions'] ?? '') !== '' && ($data['reflection'] ?? '') !== '' && $counts['consultation'] > 0;
    $approved=($data['approval_date'] ?? '') !== '' && ($data['approver'] ?? '') !== '' && $counts['approval'] > 0;
    return ['planned'=>$planned, 'quantitative'=>$quantitative, 'basis'=>$basis, 'consulted'=>$consulted, 'approved'=>$approved, 'files'=>$counts];
}
