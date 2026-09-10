<?php
declare(strict_types=1);
require_once __DIR__ . '/goal_plan_storage.php';
require_once __DIR__ . '/near_miss_counts.php';
function quarter_plan_template(): array { return json_decode((string)file_get_contents(__DIR__.'/quarter_plan_template.json'), true, 512, JSON_THROW_ON_ERROR); }
function quarter_plan_number(float $value): string { return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'); }
function quarter_plan_result(array $row, string $mode): string
{
    $numbers = [];
    foreach (['m1','m2','m3'] as $key) if ($row[$key] !== '' && $row[$key] !== '-') $numbers[] = (float)$row[$key];
    if ($numbers === []) return '0';
    return quarter_plan_number(array_sum($numbers) / ($mode === 'average' ? count($numbers) : 1));
}
function quarter_plan_defaults(int $year, int $quarter): array
{
    $template=quarter_plan_template(); $rows=[];
    foreach ($template['rows'] as $row) {
        if ($row['type']!=='item') continue;
        foreach (['previous','plan','result','m1','m2','m3','note'] as $key) $rows[$row['id']][$key] = $year===$template['source_year'] && $quarter===$template['source_quarter'] ? $row[$key] : '';
    }
    return $rows;
}
function quarter_plan_initialize(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_quarter_plans (plan_year SMALLINT UNSIGNED NOT NULL, quarter_no TINYINT UNSIGNED NOT NULL, rows_json MEDIUMTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, updated_by VARCHAR(100) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(plan_year,quarter_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS safety_quarter_plan_raw (source_year SMALLINT UNSIGNED NOT NULL, quarter_no TINYINT UNSIGNED NOT NULL, source_json MEDIUMTEXT NOT NULL, imported_at DATETIME NOT NULL, PRIMARY KEY(source_year,quarter_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function quarter_plan_seed(PDO $db): void
{
    $source=quarter_plan_template();$now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $stmt=$db->prepare('INSERT INTO safety_quarter_plan_raw (source_year,quarter_no,source_json,imported_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE source_year=VALUES(source_year)');
        $stmt->execute([$source['source_year'],$source['source_quarter'],json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
        $stmt=$db->prepare('INSERT INTO safety_quarter_plans (plan_year,quarter_no,rows_json,updated_by,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE plan_year=VALUES(plan_year)');
        $stmt->execute([$source['source_year'],$source['source_quarter'],json_encode(quarter_plan_defaults($source['source_year'],$source['source_quarter']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'xlsx_import',$now]);
        $db->commit();
    } catch(Throwable $error) { if($db->inTransaction())$db->rollBack();throw $error; }
}
function quarter_plan_load(PDO $db,int $year,int $quarter): ?array
{
    $stmt=$db->prepare('SELECT rows_json,revision FROM safety_quarter_plans WHERE plan_year=? AND quarter_no=?');$stmt->execute([$year,$quarter]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['rows'=>json_decode($row['rows_json'],true,512,JSON_THROW_ON_ERROR),'revision'=>(int)$row['revision']] : null;
}
function quarter_plan_previous(array $rows,?array $previous): array
{
    if(!$previous)return $rows;
    foreach(quarter_plan_template()['rows'] as $item) {
        if($item['type']!=='item')continue;
        $value=$previous['rows'][$item['id']]['result']??'';
        $rows[$item['id']]['previous']=$value!=='' && $item['percent_result'] ? quarter_plan_number((float)$value*100) : $value;
    }
    return $rows;
}
function quarter_plan_validate(mixed $input): array
{
    if(!is_array($input))throw new InvalidArgumentException('입력 내용을 확인해 주세요.');
    $rows=[];
    foreach(quarter_plan_template()['rows'] as $item) {
        if($item['type']!=='item')continue;$id=$item['id'];
        if(!is_array($input[$id]??null))throw new InvalidArgumentException('누락된 입력 항목이 있습니다.');
        foreach(['previous','plan','m1','m2','m3'] as $key) {
            $value=$input[$id][$key]??null;
            $numericValue=$key==='previous'&&is_string($value)?rtrim($value,'%'):$value;
            $dash=in_array($key,['m1','m2','m3'],true)&&$value==='-';
            if(!is_string($value) || ($value!=='' && !$dash && (!preg_match('/^\d+(?:\.\d{1,6})?$/D',$numericValue) || (float)$numericValue>1000000000)))throw new InvalidArgumentException($item['name'].': 실적과 계획은 0 이상의 숫자(소수점 6자리 이내)로 입력해 주세요.');
            $rows[$id][$key]=$value;
        }
        $note=$input[$id]['note']??null;
        if(!is_string($note)||mb_strlen($note,'UTF-8')>1000)throw new InvalidArgumentException('비고는 1,000자 이내로 입력해 주세요.');
        $rows[$id]['note']=trim($note);$rows[$id]['result']=quarter_plan_result($rows[$id],$item['mode']);
    }
    return $rows;
}
function quarter_plan_annual_value(array $item,string $result): string
{
    if($result==='')return '';
    if($item['percent_result'])return quarter_plan_number((float)$result*100).'%';
    $id=$item['annual_id'];
    if($id==='r39')return quarter_plan_number((float)$result/8).'항목 ('.$result.')';
    foreach(goal_plan_template()['rows'] as $annual) {
        if(($annual['id']??null)!==$id)continue;
        $sample=$annual['q1'];
        if(preg_match('/^[\d.]+(\s*(?:회|건|인|HR|hr|%))$/u',$sample,$match))return $result.$match[1];
        break;
    }
    return $result;
}
function quarter_plan_update_annual_rates(array $rows): array
{
    foreach($rows as $id=>&$row) {
        if(in_array($id,['r11','r38','r39'],true))continue;
        $numbers=[];$valid=true;
        foreach(['q1','q2','q3','q4'] as $key) {
            if($row[$key]==='')continue;
            if(!preg_match('/^(\d+(?:\.\d+)?)\s*(?:회|건|인|HR|hr|%)?$/u',$row[$key],$m)){$valid=false;break;}
            $numbers[]=(float)$m[1];
        }
        if(!$valid||$numbers===[])continue;$sum=array_sum($numbers);
        if(in_array($id,['r10','r12','r13'],true))$row['rate']=$sum==0?'100%':'0%';
        elseif($id==='r9')$row['rate']=quarter_plan_number($sum/2).'%';
        elseif($id==='r37')$row['rate']=quarter_plan_number($sum*8).'hr';
        elseif(preg_match('/^(\d+(?:\.\d+)?)\s*(?:회|건|인|HR|hr|%)?$/u',$row['plan'],$m) && (float)$m[1]>0)$row['rate']=quarter_plan_number(round($sum/(float)$m[1]*100,2)).'%';
    }
    unset($row);return $rows;
}
function quarter_plan_save(PDO $db,int $year,int $quarter,array $rows,int $revision,string $editor): void
{
    if($year<1000||$year>9999||$quarter<1||$quarter>4)throw new InvalidArgumentException('연도와 분기를 확인해 주세요.');
    $rows['qr18']=array_replace($rows['qr18'],safety_near_miss_quarter($db,$year,$quarter));
    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        // Lock the annual row first so concurrent saves of different quarters cannot lose updates.
        $stmt=$db->prepare('INSERT INTO safety_goal_plans (plan_year,rows_json,updated_by,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE plan_year=VALUES(plan_year)');
        $stmt->execute([$year,json_encode(goal_plan_defaults($year),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$editor,$now]);
        $stmt=$db->prepare('SELECT rows_json FROM safety_goal_plans WHERE plan_year=? FOR UPDATE');$stmt->execute([$year]);$annual=json_decode($stmt->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($revision===0) {
            try {$stmt=$db->prepare('INSERT INTO safety_quarter_plans (plan_year,quarter_no,rows_json,updated_by,updated_at) VALUES (?,?,?,?,?)');$stmt->execute([$year,$quarter,$json,$editor,$now]);}
            catch(PDOException $error){if((int)($error->errorInfo[1]??0)===1062)throw new InvalidArgumentException('다른 창에서 저장되었습니다. 새로고침 후 확인해 주세요.');throw $error;}
        } else {
            $stmt=$db->prepare('UPDATE safety_quarter_plans SET rows_json=?,revision=revision+1,updated_by=?,updated_at=? WHERE plan_year=? AND quarter_no=? AND revision=?');$stmt->execute([$json,$editor,$now,$year,$quarter,$revision]);
            if($stmt->rowCount()!==1)throw new InvalidArgumentException('다른 창에서 수정되었습니다. 새로고침 후 확인해 주세요.');
        }
        foreach(quarter_plan_template()['rows'] as $item)if($item['type']==='item'&&$item['annual_id']!==null)$annual[$item['annual_id']]['q'.$quarter]=quarter_plan_annual_value($item,$rows[$item['id']]['result']);
        $annual=quarter_plan_update_annual_rates($annual);
        $stmt=$db->prepare('UPDATE safety_goal_plans SET rows_json=?,revision=revision+1,updated_by=?,updated_at=? WHERE plan_year=?');$stmt->execute([json_encode($annual,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$editor,$now,$year]);
        $db->commit();
    } catch(Throwable $error) {if($db->inTransaction())$db->rollBack();throw $error;}
}
