<?php
require __DIR__.'/../risk_assessment/db_config.php';
require __DIR__.'/../safety_manual/review_sources.php';
$db=getDB();$text=safety_review_source_text($db,2026);$policy=safety_policy_load($db,2026);$plan=goal_plan_load($db,2026);
if(trim($policy['policy']??'')!==''&&!str_contains($text,trim($policy['policy'])))throw new Exception('Policy missing');
$count=0;foreach($plan['rows'] as $row){if(trim($row['name'])!==''){if(!str_contains($text,trim($row['name'])))throw new Exception('Activity missing');$count++;}}
echo 'PASS saved policy and '.$count.' activity names included; '.mb_strlen($text).' characters'.PHP_EOL;
