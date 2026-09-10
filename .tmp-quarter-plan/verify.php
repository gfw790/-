<?php
require __DIR__.'/../risk_assessment/db_config.php';
require __DIR__.'/../safety_manual/quarter_plan_storage.php';
function check($condition,$label){if(!$condition)throw new Exception($label);echo "PASS $label\n";}
$db=getDB();goal_plan_initialize($db);goal_plan_seed_raw($db);quarter_plan_initialize($db);quarter_plan_seed($db);
check(count(quarter_plan_load($db,2026,1)['rows'])===36,'production source seeded');
foreach(['safety_goal_plans','safety_goal_plan_raw','safety_quarter_plans','safety_quarter_plan_raw'] as $table){$ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$db->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));}
goal_plan_seed_raw($db);quarter_plan_seed($db);
$rows=quarter_plan_validate(quarter_plan_defaults(2026,1));
check($rows['qr18']['result']==='16'&&$rows['qr10']['result']==='23'&&$rows['qr8']['result']==='1','source sum average percent');
$rows['qr18']['m1']='7';$rows['qr8']['m1']='0.5';$rows['qr18']['result']='999';$rows=quarter_plan_validate($rows);
quarter_plan_save($db,2026,1,$rows,1,'test');
$annual=json_decode($db->query('SELECT rows_json FROM safety_goal_plans WHERE plan_year=2026')->fetchColumn(),true);
check($annual['r18']['q1']==='18건'&&$annual['r9']['q1']==='50%','annual quarter synchronization');
check($annual['r37']['q1']==='4인'&&$annual['r37']['rate']==='48hr','education count and hours');
$before=$db->query('SELECT rows_json FROM safety_goal_plans WHERE plan_year=2026')->fetchColumn();
try{quarter_plan_save($db,2026,1,$rows,1,'test');throw new Exception('stale accepted');}catch(InvalidArgumentException $e){}
check($before===$db->query('SELECT rows_json FROM safety_goal_plans WHERE plan_year=2026')->fetchColumn(),'stale save rolls back annual');
quarter_plan_seed($db);check(quarter_plan_load($db,2026,1)['rows']['qr18']['result']==='18','seed preserves edits');
$next=quarter_plan_previous(quarter_plan_defaults(2027,1),quarter_plan_load($db,2026,1));
check($next['qr18']['previous']==='18'&&$next['qr8']['previous']==='50'&&$next['qr18']['m1']==='','year rollover');
$q2=quarter_plan_validate(quarter_plan_defaults(2026,2));quarter_plan_save($db,2026,2,$q2,0,'test');
check(quarter_plan_load($db,2026,1)['rows']['qr18']['result']==='18','quarters isolated');
foreach(['-1','NaN',[], '1000000001'] as $invalid){$bad=$rows;$bad['qr18']['m1']=$invalid;try{quarter_plan_validate($bad);throw new Exception('invalid accepted');}catch(InvalidArgumentException $e){}}
echo "PASS validation\n";
