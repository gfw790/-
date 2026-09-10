<?php
require __DIR__.'/../risk_assessment/db_config.php';require __DIR__.'/../safety_manual/manual_db.php';
$db=getDB();safety_manual_db_init($db);$rows=$db->query('SELECT revision_id,revision_key,updated_by,updated_at,content_html FROM safety_manual_revisions ORDER BY revision_id')->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){$tables=substr_count($row['content_html'],'manual-subtitle-box');$pref=preg_match_all('/제\s*\d+\s*조/u',$row['content_html']);echo implode(' | ',[$row['revision_id'],$row['revision_key'],$row['updated_by'],$row['updated_at'],'subtitle='.$tables,'prefix='.$pref]).PHP_EOL;}
$data=json_decode(file_get_contents(__DIR__.'/../safety_manual/data.json'),true);$html=$data['current']['content_html'];
echo 'JSON subtitle='.substr_count($html,'manual-subtitle-box').' prefix='.preg_match_all('/제\s*\d+\s*조/u',$html).PHP_EOL;
$at=strpos($html,'manual-subtitle-box');echo substr($html,max(0,$at-100),600).PHP_EOL;
$original=$rows[0]['content_html'];$at=strpos($original,'1. 목 적');echo "ORIGINAL\n".substr($original,max(0,$at-200),700).PHP_EOL;
