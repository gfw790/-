<?php
declare(strict_types=1);

function near_miss_stats_filters(array $input, ?DateTimeImmutable $today=null): array
{
    $today ??= new DateTimeImmutable('today',new DateTimeZone('Asia/Seoul'));
    $values=['from'=>$today->format('Y-01-01'),'to'=>$today->format('Y-m-d'),'team'=>'','risk'=>'','status'=>'','page'=>1];
    foreach (['from','to','team','risk','status'] as $key) {
        if (isset($input[$key])) {
            if (!is_string($input[$key]) || mb_strlen($input[$key]) > 200) throw new InvalidArgumentException('조회 조건을 확인해 주세요.');
            $values[$key]=trim($input[$key]);
        }
    }
    foreach (['from','to'] as $key) {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$values[$key]);
        if (!$date || $date->format('Y-m-d')!==$values[$key] || $date->format('Y')<'2000' || $date->format('Y')>'2100') throw new InvalidArgumentException('조회 시작일과 종료일을 올바르게 선택해 주세요.');
    }
    if ($values['from']>$values['to']) throw new InvalidArgumentException('조회 종료일은 시작일 이후로 선택해 주세요.');
    if (!in_array($values['status'],['','open','in_progress','closed'],true)) throw new InvalidArgumentException('조치상태를 확인해 주세요.');
    $values['page']=filter_var($input['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if ($values['page']===false) throw new InvalidArgumentException('조회 페이지를 확인해 주세요.');
    return $values;
}

function near_miss_stats_team_sql(): string
{
    return "CASE WHEN TRIM(p.author_dept)='현대기전-가스팀' THEN '가스팀'
        WHEN TRIM(p.author_dept)='현대기전-건설팀' THEN '공사팀-전기'
        ELSE COALESCE(NULLIF(TRIM(p.author_dept),''),'미분류') END";
}

function near_miss_stats_label_sql(string $field): string
{
    if (!in_array($field,['risk_type','work_type','unsafe_state','unsafe_action','careless_action','careless_state'],true)) throw new InvalidArgumentException('분류 항목을 확인해 주세요.');
    return "CASE WHEN TRIM(n.`$field`) LIKE '기타:%' THEN '기타' ELSE COALESCE(NULLIF(TRIM(n.`$field`),''),'미분류') END";
}

function near_miss_stats_load(PDO $db,array $filters): array
{
    $team=near_miss_stats_team_sql(); $risk=near_miss_stats_label_sql('risk_type');
    $join=' FROM near_miss_reports n JOIN posts p ON p.id=n.post_id ';
    $bounds=$db->query('SELECT MIN(DATE(n.incident_at)) AS first_date,MAX(DATE(n.incident_at)) AS last_date'.$join)->fetch(PDO::FETCH_ASSOC);
    $teams=$db->query('SELECT DISTINCT '.$team.' AS label'.$join.'ORDER BY label')->fetchAll(PDO::FETCH_COLUMN);
    $risks=$db->query('SELECT DISTINCT '.$risk.' AS label'.$join.'ORDER BY label')->fetchAll(PDO::FETCH_COLUMN);
    $where=['n.incident_at >= ?','n.incident_at < ?'];
    $params=[$filters['from'].' 00:00:00',(new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    foreach (['team'=>$team,'risk'=>$risk,'status'=>'n.status'] as $key=>$expression) if ($filters[$key]!=='') { $where[]=$expression.'=?'; $params[]=$filters[$key]; }
    $whereSql=' WHERE '.implode(' AND ',$where);
    $query=$db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(n.status='closed'),0) AS closed,
        COALESCE(SUM(n.status='in_progress'),0) AS in_progress,COALESCE(SUM(n.status='open'),0) AS open,
        COUNT(DISTINCT $team) AS team_count".$join.$whereSql);
    $query->execute($params);$summary=array_map('intval',$query->fetch(PDO::FETCH_ASSOC));
    $summary['completion_rate']=$summary['total']>0 ? round($summary['closed']/$summary['total']*100,1) : 0;
    $query=$db->prepare("SELECT DATE_FORMAT(n.incident_at,'%Y-%m') AS month_key,COUNT(*) AS total".$join.$whereSql." GROUP BY DATE_FORMAT(n.incident_at,'%Y-%m') ORDER BY month_key");
    $query->execute($params);$monthlyMap=array_column($query->fetchAll(PDO::FETCH_ASSOC),'total','month_key');
    $monthly=[];$cursor=new DateTimeImmutable(substr($filters['from'],0,7).'-01');$last=substr($filters['to'],0,7);
    while($cursor->format('Y-m')<=$last) { $key=$cursor->format('Y-m');$monthly[]=['key'=>$key,'label'=>$cursor->format('y.n'),'total'=>(int)($monthlyMap[$key] ?? 0)];$cursor=$cursor->modify('+1 month'); }
    $groups=[];
    foreach (['risk'=>$risk,'team'=>$team,'unsafe_state'=>near_miss_stats_label_sql('unsafe_state'),'unsafe_action'=>near_miss_stats_label_sql('unsafe_action'),'careless_action'=>near_miss_stats_label_sql('careless_action'),'careless_state'=>near_miss_stats_label_sql('careless_state')] as $key=>$expression) {
        $query=$db->prepare('SELECT '.$expression.' AS label,COUNT(*) AS total'.$join.$whereSql.' GROUP BY '.$expression.' ORDER BY total DESC,label ASC');
        $query->execute($params);$groups[$key]=$query->fetchAll(PDO::FETCH_ASSOC);
    }
    $pageCount=max(1,(int)ceil($summary['total']/12));$page=min($filters['page'],$pageCount);$offset=($page-1)*12;
    $query=$db->prepare('SELECT p.id,p.title,p.author_name,'.$team.' AS team,n.incident_at,n.location,'.$risk.' AS risk,n.status'.$join.$whereSql.' ORDER BY n.incident_at DESC,p.id DESC LIMIT 12 OFFSET '.$offset);
    $query->execute($params);
    return ['summary'=>$summary,'months'=>$monthly,'groups'=>$groups,'reports'=>$query->fetchAll(PDO::FETCH_ASSOC),
        'teams'=>$teams,'risks'=>$risks,'bounds'=>$bounds,'page'=>$page,'pages'=>$pageCount];
}

function near_miss_stats_url(array $filters,array $changes=[]): string
{
    $query=array_replace($filters,$changes);
    foreach($query as $key=>$value) if ($value==='' || $value===null) unset($query[$key]);
    return 'statistics.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
}

function near_miss_stats_top(array $rows,int $limit=8): array
{
    if(count($rows)<=$limit)return $rows;
    $top=array_slice($rows,0,$limit);
    $top[]=['label'=>'그 외 항목 합계','total'=>array_sum(array_column(array_slice($rows,$limit),'total')),'combined'=>true];
    return $top;
}
