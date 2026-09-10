<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../risk_assessment/db_config.php';
require_once __DIR__.'/quarter_plan_storage.php';
// Supplied 2026 plan columns only. Percent plans use percentage points in the form.
$plans=[
3=>[
'qr7'=>'0','qr8'=>'100%','qr9'=>'0','qr10'=>'20','qr11'=>'0','qr12'=>'0','qr13'=>'0',
'qr16'=>'1','qr17'=>'','qr18'=>'10','qr19'=>'4','qr21'=>'0','qr22'=>'0','qr23'=>'0',
'qr25'=>'3','qr26'=>'13','qr27'=>'0','qr28'=>'0','qr29'=>'3','qr30'=>'0','qr31'=>'0','qr32'=>'3','qr33'=>'3',
'qr35'=>'6','qr36'=>'0','qr37'=>'1','qr38'=>'2','qr39'=>'16','qr40'=>'0','qr41'=>'0',
'qr43'=>'0','qr44'=>'0','qr45'=>'0','qr46'=>'0','qr48'=>'0','qr49'=>'3',
],
4=>[
'qr7'=>'1','qr8'=>'100','qr9'=>'0','qr10'=>'20','qr11'=>'0','qr12'=>'0','qr13'=>'0',
'qr16'=>'1','qr17'=>'1','qr18'=>'10','qr19'=>'4','qr21'=>'1','qr22'=>'1','qr23'=>'1',
'qr25'=>'3','qr26'=>'13','qr27'=>'1','qr28'=>'1','qr29'=>'3','qr30'=>'9','qr31'=>'1','qr32'=>'3','qr33'=>'3',
'qr35'=>'6','qr36'=>'0','qr37'=>'1','qr38'=>'2','qr39'=>'16','qr40'=>'1','qr41'=>'0',
'qr43'=>'1','qr44'=>'1','qr45'=>'1','qr46'=>'1','qr48'=>'0','qr49'=>'3',
],
];
$db=getDB();quarter_plan_initialize($db);
$db->beginTransaction();
try{
    foreach($plans as $quarter=>$source){
        $stmt=$db->prepare('SELECT rows_json FROM safety_quarter_plans WHERE plan_year=2026 AND quarter_no=? FOR UPDATE');$stmt->execute([$quarter]);$json=$stmt->fetchColumn();
        $before=$json===false?quarter_plan_defaults(2026,$quarter):json_decode($json,true,512,JSON_THROW_ON_ERROR);
        $rows=$before;
        if(array_diff_key($rows,$source)||array_diff_key($source,$rows))throw new RuntimeException('Item mismatch');
        foreach($source as $id=>$value)$rows[$id]['plan']=rtrim($value,'%');
        quarter_plan_validate($rows);
        $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        $stmt=$db->prepare("INSERT INTO safety_quarter_plans (plan_year,quarter_no,rows_json,revision,updated_by,updated_at) VALUES (2026,?,?,1,'user_plan_import',?) ON DUPLICATE KEY UPDATE rows_json=VALUES(rows_json),revision=revision+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)");
        $stmt->execute([$quarter,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
        $stmt=$db->prepare('SELECT source_json FROM safety_quarter_plan_raw WHERE source_year=2026 AND quarter_no=? FOR UPDATE');$stmt->execute([$quarter]);$raw=$stmt->fetchColumn();
        $snapshot=$raw===false?['source_year'=>2026,'source_quarter'=>$quarter]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $snapshot['user_supplied_plans']=$source;
        $stmt=$db->prepare('INSERT INTO safety_quarter_plan_raw (source_year,quarter_no,source_json,imported_at) VALUES (2026,?,?,?) ON DUPLICATE KEY UPDATE source_json=VALUES(source_json)');
        $stmt->execute([$quarter,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
        $saved=quarter_plan_load($db,2026,$quarter)['rows'];
        foreach($saved as $id=>$row){if($row['plan']!==rtrim($source[$id],'%'))throw new RuntimeException('Plan mismatch');unset($row['plan']);$old=$before[$id];unset($old['plan']);if($row!==$old)throw new RuntimeException('Non-plan fields changed');}
        echo "PASS 2026 Q$quarter: 36 plan values verified; other fields preserved.\n";
    }
    $db->commit();
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
