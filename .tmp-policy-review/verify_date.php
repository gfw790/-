<?php
require __DIR__.'/../risk_assessment/db_config.php';require __DIR__.'/../safety_manual/review_storage.php';
$db=getDB();safety_review_initialize($db);
$ddl=$db->query('SHOW CREATE TABLE safety_policy_reviews')->fetch(PDO::FETCH_NUM)[1];$db->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
$data=safety_review_empty();$data['review_date']='2026-09-08';$data=safety_review_validate($data);safety_review_save($db,2026,$data,0,'test');
if(safety_review_load($db,2026)['review_date']!=='2026-09-08')throw new Exception('Date mismatch');
$data['review_date']='';safety_review_save($db,2026,safety_review_validate($data),1,'test');if(safety_review_load($db,2026)['review_date']!=='')throw new Exception('Clear failed');
$data['review_date']='2026-02-30';try{safety_review_validate($data);throw new Exception('Invalid date accepted');}catch(InvalidArgumentException $e){}
echo 'PASS schema migration, date save/load/clear, invalid date rejection'.PHP_EOL;
