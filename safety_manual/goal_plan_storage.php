<?php
declare(strict_types=1);
function goal_plan_template(): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/goal_plan_template.json'), true, 512, JSON_THROW_ON_ERROR);
}
function goal_plan_fields(): array
{
    return ['name' => '구분', 'frequency' => '주기·기준', 'unit' => '단위', 'previous' => '전년도 실적', 'plan' => '계획', 'rate' => '실적율', 'q1' => '1분기', 'q2' => '2분기', 'q3' => '3분기', 'q4' => '4분기', 'note' => '비고'];
}
function goal_plan_defaults(int $year): array
{
    $template = goal_plan_template(); $values = [];
    foreach ($template['rows'] as $row) {
        if ($row['type'] !== 'item') continue;
        $values[$row['id']] = [];
        foreach (goal_plan_fields() as $key => $_label) {
            $values[$row['id']][$key] = $year === $template['source_year'] || in_array($key, ['name', 'frequency', 'unit'], true) ? $row[$key] : '';
        }
    }
    return $values;
}
function goal_plan_initialize(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_goal_plans (plan_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY, rows_json MEDIUMTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, updated_by VARCHAR(100) NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS safety_goal_plan_raw (source_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY, source_name VARCHAR(255) NOT NULL, source_json MEDIUMTEXT NOT NULL, imported_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function goal_plan_seed_raw(PDO $db): void
{
    $template = goal_plan_template();
    $year = (int)$template['source_year'];
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        // Keep the original supplied values separate from the editable yearly record.
        $stmt = $db->prepare('INSERT INTO safety_goal_plan_raw (source_year, source_name, source_json, imported_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_year = VALUES(source_year)');
        $stmt->execute([$year, '목표 및 세부추진계획.xlsx', json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $now]);
        $stmt = $db->prepare('INSERT INTO safety_goal_plans (plan_year, rows_json, updated_by, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE plan_year = VALUES(plan_year)');
        $stmt->execute([$year, json_encode(goal_plan_defaults($year), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'xlsx_import', $now]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
function goal_plan_apply_previous(array $rows, ?array $previous): array
{
    if ($previous === null) return $rows;
    foreach ($rows as $id => &$row) {
        // Use last year's stored result, including 0%, hours, and intentionally empty values.
        $row['previous'] = (string)($previous['rows'][$id]['rate'] ?? '');
    }
    unset($row);
    return $rows;
}
function goal_plan_year_state(PDO $db, int $year): array
{
    require_once __DIR__.'/near_miss_counts.php';
    $saved = goal_plan_load($db, $year);
    $previous = goal_plan_load($db, $year - 1);
    $rows = $saved['rows'] ?? goal_plan_defaults($year);
    if ($saved === null && $previous !== null) {
        foreach ($rows as $id => &$row) {
            foreach (['name', 'frequency', 'unit'] as $key) {
                if (isset($previous['rows'][$id][$key])) $row[$key] = $previous['rows'][$id][$key];
            }
        }
        unset($row);
    }
    if($previous!==null)$previous['rows']['r18']=safety_near_miss_annual($previous['rows']['r18'],safety_near_miss_months($db,$year-1));
    $rows=goal_plan_apply_previous($rows,$previous);
    $planBreakdown=goal_plan_quarter_plans($db,$year);
    foreach($planBreakdown as $id=>$detail)$rows[$id]['plan']=$detail['total'];
    $rows['r18']=safety_near_miss_annual($rows['r18'],safety_near_miss_months($db,$year));
    return ['rows' => $rows, 'revision' => $saved['revision'] ?? 0, 'previous' => $previous, 'plan_breakdown'=>$planBreakdown];
}
function goal_plan_quarter_plans(PDO $db,int $year): array
{
    $stmt=$db->prepare('SELECT quarter_no,rows_json FROM safety_quarter_plans WHERE plan_year=? ORDER BY quarter_no');
    $stmt->execute([$year]);$quarters=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $record)$quarters[(int)$record['quarter_no']]=json_decode($record['rows_json'],true,512,JSON_THROW_ON_ERROR);
    $template=json_decode((string)file_get_contents(__DIR__.'/quarter_plan_template.json'),true,512,JSON_THROW_ON_ERROR);
    $details=[];
    foreach($template['rows'] as $item){
        if($item['type']!=='item'||$item['annual_id']===null)continue;
        $values=[];$sum=0;$entered=0;
        for($q=1;$q<=4;$q++){
            $value=$quarters[$q][$item['id']]['plan']??'';$values[]=$value;
            if($value!==''){$sum+=(float)$value;$entered++;}
        }
        $mode=in_array($item['annual_id'],['r9','r11'],true)?'average':'sum';
        $value=$entered&&$mode==='average'?$sum/$entered:$sum;
        $total=$entered?rtrim(rtrim(number_format($value,6,'.',''),'0'),'.'):'';
        $details[$item['annual_id']]=['quarters'=>$values,'total'=>$total,'mode'=>$mode];
    }
    return $details;
}
function goal_plan_load(PDO $db, int $year): ?array
{
    $stmt = $db->prepare('SELECT rows_json, revision, updated_at FROM safety_goal_plans WHERE plan_year = ?');
    $stmt->execute([$year]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ['rows' => json_decode($row['rows_json'], true, 512, JSON_THROW_ON_ERROR), 'revision' => (int)$row['revision'], 'updated_at' => $row['updated_at']];
}
function goal_plan_validate(mixed $input): array
{
    if (!is_array($input)) throw new InvalidArgumentException('입력 내용을 확인해 주세요.');
    $values = [];
    foreach (goal_plan_template()['rows'] as $row) {
        if ($row['type'] !== 'item') continue;
        $id = $row['id'];
        if (!is_array($input[$id] ?? null)) throw new InvalidArgumentException('누락된 항목이 있습니다. 입력 내용을 확인해 주세요.');
        foreach (goal_plan_fields() as $key => $label) {
            $value = $input[$id][$key] ?? null;
            $limit = $key === 'note' ? 1000 : ($key === 'name' ? 200 : 100);
            if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit) throw new InvalidArgumentException($label . ' 항목은 ' . $limit . '자 이내로 입력해 주세요.');
            $values[$id][$key] = trim($value);
        }
    }
    return $values;
}
function goal_plan_save(PDO $db, int $year, array $rows, int $revision, string $editor): void
{
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    if ($revision === 0) {
        try {
            $stmt = $db->prepare('INSERT INTO safety_goal_plans (plan_year, rows_json, updated_by, updated_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$year, $json, $editor, $now]);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1062) throw new InvalidArgumentException('다른 창에서 저장된 내용이 있습니다. 새로고침 후 확인해 주세요.');
            throw $error;
        }
    } else {
        $stmt = $db->prepare('UPDATE safety_goal_plans SET rows_json = ?, updated_by = ?, updated_at = ?, revision = revision + 1 WHERE plan_year = ? AND revision = ?');
        $stmt->execute([$json, $editor, $now, $year, $revision]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('다른 창에서 내용이 변경되었습니다. 새로고침 후 확인해 주세요.');
    }
}
