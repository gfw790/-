<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../risk_assessment/db_config.php';
require_once __DIR__.'/quarter_plan_storage.php';
// User-supplied values only: previous, plan, result, April, May, June.
// Missing trailing cells are left blank, including the staffing row.
$source=[
 'qr7'=>['1','1','0','0','',''],
 'qr8'=>['100','100','0','-','-','-'],
 'qr9'=>['0.0%','0','0','0','0','0'],
 'qr10'=>['22','20','23','23','24','23'],
 'qr11'=>['0','0','0','0','0','0'],
 'qr12'=>['0','0','0','0','0','0'],
 'qr13'=>['0','0','0','0','0','0'],
 'qr16'=>['1','1','1','1','0','0'],
 'qr17'=>['1','1','1','0','0','1'],
 'qr18'=>['7','10','23','9','7','7'],
 'qr19'=>['4','4','3','1','1','1'],
 'qr21'=>['29','1','1','1','0','0'],
 'qr22'=>['1','1','1','1','0','0'],
 'qr23'=>['1','1','1','1','0','0'],
 'qr25'=>['3','3','3','1','1','1'],
 'qr26'=>['12','13','16','6','5','5'],
 'qr27'=>['1','1','1','0','1','0'],
 'qr28'=>['0','0','0','0','0','0'],
 'qr29'=>['3','3','3','1','1','1'],
 'qr30'=>['9','9','9','0','0','9'],
 'qr31'=>['1','1','1','0','1','0'],
 'qr32'=>['3','3','3','1','1','1'],
 'qr33'=>['3','3','3','1','1','1'],
 'qr35'=>['6','6','6','2','2','2'],
 'qr36'=>['1','16','16','0','0','16'],
 'qr37'=>['3','1','2','2','0','0'],
 'qr38'=>['0','0','0','0','0','0'],
 'qr39'=>['24','24','24','8','8','8'],
 'qr40'=>['1','1','1','0','0','1'],
 'qr41'=>['0','0','0','0','0','0'],
 'qr43'=>['0','0','0','0','0','0'],
 'qr44'=>['0','0','0','0','0','0'],
 'qr45'=>['0','0','0','0','0','0'],
 'qr46'=>['0','0','0','0','0','0'],
 'qr48'=>['1','','','','',''],
 'qr49'=>['3','3','3','1','1','1'],
];
$rows=[];foreach($source as $id=>$cells)$rows[$id]=array_combine(['previous','plan','result','m1','m2','m3','note'],[...$cells,'']);
quarter_plan_validate($rows); // Validate input types, retaining supplied raw results.
$db=getDB();quarter_plan_initialize($db);
$stmt=$db->prepare('SELECT source_json FROM safety_quarter_plan_raw WHERE source_year=2026 AND quarter_no=2');$stmt->execute();
if($stmt->fetchColumn()!==false){echo "Q2 raw data already imported; no changes made.\n";exit;}
$beforeQ1=quarter_plan_load($db,2026,1);
$saved=quarter_plan_load($db,2026,2);
quarter_plan_save($db,2026,2,$rows,$saved['revision']??0,'user_table_import');
$stmt=$db->prepare('INSERT INTO safety_quarter_plan_raw (source_year,quarter_no,source_json,imported_at) VALUES (2026,2,?,?)');
$stmt->execute([json_encode(['source_year'=>2026,'source_quarter'=>2,'source_name'=>'user_supplied_table','rows'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s')]);
if(quarter_plan_load($db,2026,2)['rows']!==$rows)throw new RuntimeException('Stored values differ');
if(quarter_plan_load($db,2026,1)!==$beforeQ1)throw new RuntimeException('Q1 unexpectedly changed');
echo "PASS: 36 Q2 rows saved exactly, raw snapshot retained, annual Q2 synchronized, Q1 unchanged.\n";
