<?php
require __DIR__.'/../risk_assessment/db_config.php';
require __DIR__.'/../safety_manual/quarter_plan_storage.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$db=getDB();
$live=safety_near_miss_quarter($db,2026,2);
echo 'Live 2026 Q2 counts: '.json_encode($live)."\n";
// Connection-local temporary tables shadow live tables without modifying board data.
$db->exec('CREATE TEMPORARY TABLE board.posts (id INT PRIMARY KEY)');
$db->exec('CREATE TEMPORARY TABLE board.near_miss_reports (post_id INT, incident_at DATETIME)');
$db->exec('INSERT INTO board.posts VALUES (1),(2),(3),(4),(5),(6)');
$db->exec("INSERT INTO board.near_miss_reports VALUES (1,'2026-03-31 23:59:59'),(2,'2026-04-01 00:00:00'),(3,'2026-06-30 23:59:59'),(4,'2026-07-01 00:00:00'),(5,'2025-05-01 00:00:00'),(999,'2026-05-01 00:00:00')");
check(safety_near_miss_quarter($db,2026,2)===['previous'=>'1','m1'=>'1','m2'=>'0','m3'=>'1','result'=>'2'],'boundaries, zero month, previous year, deleted post exclusion');
foreach(['safety_goal_plans','safety_quarter_plans'] as $table){$ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$db->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));}
$rows=quarter_plan_defaults(2026,1);$rows['qr18']['m1']='999';$rows['qr18']['result']='999';
quarter_plan_save($db,2026,2,$rows,0,'test');
$saved=quarter_plan_load($db,2026,2)['rows']['qr18'];
check($saved['m1']==='1'&&$saved['result']==='2'&&$saved['previous']==='1','save enforces board counts');
$annual=goal_plan_year_state($db,2026)['rows']['r18'];
check($annual['q1']==='1건'&&$annual['q2']==='2건'&&$annual['q3']==='1건'&&$annual['q4']==='0건','annual live counts');
$db->exec("INSERT INTO board.near_miss_reports VALUES (6,'2026-05-02 00:00:00')");
check(goal_plan_year_state($db,2026)['rows']['r18']['q2']==='3건','new board report reflected on reload');
