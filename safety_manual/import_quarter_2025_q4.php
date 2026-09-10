<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../risk_assessment/db_config.php';
require_once __DIR__.'/quarter_plan_storage.php';
// Only the supplied 2025 actual column; no 2026 plan/month data is imported.
$actuals=[
    'qr7'=>'0','qr8'=>'0','qr9'=>'0.0%','qr10'=>'20',
    'qr11'=>'0','qr12'=>'0','qr13'=>'0',
    'qr16'=>'1','qr17'=>'1','qr18'=>'10','qr19'=>'3',
    'qr21'=>'30','qr22'=>'0','qr23'=>'1',
    'qr25'=>'3','qr26'=>'9','qr27'=>'1','qr28'=>'1',
    'qr29'=>'3','qr30'=>'9','qr31'=>'1','qr32'=>'3','qr33'=>'3',
    'qr35'=>'6','qr36'=>'0','qr37'=>'3','qr38'=>'0','qr39'=>'16',
    'qr40'=>'1','qr41'=>'0','qr43'=>'1','qr44'=>'1','qr45'=>'1','qr46'=>'1',
    'qr48'=>'0','qr49'=>'3',
];
$db=getDB();quarter_plan_initialize($db);
$currentBefore=$db->query('SELECT plan_year,quarter_no,rows_json,revision FROM safety_quarter_plans WHERE NOT (plan_year=2025 AND quarter_no=4) ORDER BY plan_year,quarter_no')->fetchAll(PDO::FETCH_ASSOC);
$board=safety_near_miss_quarter($db,2025,4);
$db->beginTransaction();
try{
    $stmt=$db->prepare('SELECT source_json FROM safety_quarter_plan_raw WHERE source_year=2025 AND quarter_no=4 FOR UPDATE');$stmt->execute();
    if($stmt->fetchColumn()!==false){$db->rollBack();echo "Already imported; no changes.\n";exit;}
    $stmt=$db->prepare('SELECT rows_json FROM safety_quarter_plans WHERE plan_year=2025 AND quarter_no=4 FOR UPDATE');$stmt->execute();$json=$stmt->fetchColumn();
    $rows=$json===false?quarter_plan_defaults(2025,4):json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if(count($rows)!==count($actuals))throw new RuntimeException('Item count mismatch');
    foreach($actuals as $id=>$value)$rows[$id]['result']=$id==='qr8'?quarter_plan_number((float)$value/100):quarter_plan_number((float)$value);
    $rows['qr18']=array_replace($rows['qr18'],$board);
    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    $stmt=$db->prepare("INSERT INTO safety_quarter_plans (plan_year,quarter_no,rows_json,revision,updated_by,updated_at) VALUES (2025,4,?,1,'user_history_import',?) ON DUPLICATE KEY UPDATE rows_json=VALUES(rows_json),revision=revision+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)");
    $stmt->execute([json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
    $stmt=$db->prepare('INSERT INTO safety_quarter_plan_raw (source_year,quarter_no,source_json,imported_at) VALUES (2025,4,?,?)');
    $stmt->execute([json_encode(['source_year'=>2025,'source_quarter'=>4,'source_name'=>'user_supplied_previous_year_actuals','actuals'=>$actuals],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
    $display=quarter_plan_previous(quarter_plan_defaults(2026,4),quarter_plan_load($db,2025,4));
    foreach($actuals as $id=>$value){$expected=$id==='qr18'?$board['result']:quarter_plan_number((float)$value);if($display[$id]['previous']!==$expected)throw new RuntimeException('Previous-year display mismatch: '.$id);}
    $currentAfter=$db->query('SELECT plan_year,quarter_no,rows_json,revision FROM safety_quarter_plans WHERE NOT (plan_year=2025 AND quarter_no=4) ORDER BY plan_year,quarter_no')->fetchAll(PDO::FETCH_ASSOC);
    if($currentAfter!==$currentBefore)throw new RuntimeException('Unrelated quarter data changed');
    $db->commit();
    echo 'PASS 36 historical actuals imported; 2026 previous-year display verified; 2026 records unchanged. Board Q4 count: '.$board['result']."\n";
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
