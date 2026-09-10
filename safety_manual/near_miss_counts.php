<?php
declare(strict_types=1);

function safety_near_miss_months(PDO $db, int $year): array
{
    // Match the board dashboard: count existing reports by incident date.
    $stmt=$db->prepare("SELECT MONTH(n.incident_at) AS month_no, COUNT(*) AS total
        FROM board.near_miss_reports n JOIN board.posts p ON p.id=n.post_id
        WHERE n.incident_at >= ? AND n.incident_at <= ?
        GROUP BY MONTH(n.incident_at)");
    $stmt->execute([sprintf('%04d-01-01 00:00:00',$year),sprintf('%04d-12-31 23:59:59',$year)]);
    $months=array_fill(1,12,0);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$months[(int)$row['month_no']]=(int)$row['total'];
    return $months;
}

function safety_near_miss_quarter(PDO $db, int $year, int $quarter): array
{
    $months=safety_near_miss_months($db,$year);
    $previous=safety_near_miss_months($db,$year-1);
    $start=($quarter-1)*3+1;
    return ['previous'=>(string)array_sum(array_slice($previous,$start-1,3)),
        'm1'=>(string)$months[$start],'m2'=>(string)$months[$start+1],'m3'=>(string)$months[$start+2],
        'result'=>(string)array_sum(array_slice($months,$start-1,3))];
}

function safety_near_miss_annual(array $row, array $months): array
{
    for($quarter=1;$quarter<=4;$quarter++)$row['q'.$quarter]=array_sum(array_slice($months,($quarter-1)*3,3)).'건';
    if(preg_match('/^(\d+(?:\.\d+)?)\s*(?:건)?$/u',$row['plan'],$match)&&(float)$match[1]>0){
        $row['rate']=rtrim(rtrim(number_format(array_sum($months)/(float)$match[1]*100,2,'.',''),'0'),'.').'%';
    }else $row['rate']='';
    return $row;
}
