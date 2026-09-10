<?php
require __DIR__.'/../risk_assessment/db_config.php';require __DIR__.'/../safety_manual/manual_db.php';
$data=json_decode(file_get_contents(__DIR__.'/../safety_manual/data.json'),true,512,JSON_THROW_ON_ERROR);$html=$data['current']['content_html'];
$db=getDB();safety_manual_db_init($db);
foreach(['safety_manual_revisions','safety_manual_clauses'] as $table){$ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$db->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));}
$key=safety_manual_db_save($db,$html,[],'test');$clauses=safety_manual_db_clauses($db,$key);if(count($clauses)<20)throw new Exception('Too few clauses');
$loaded=safety_manual_db_current($db,$key);if(strip_tags($loaded['content_html'])!==strip_tags(implode("\n",array_column($clauses,'body'))))throw new Exception('Load mismatch');
$added=$html.'<p id="new-clause">21.1 새 조항 시험</p><p>새 내용</p>';$key2=safety_manual_db_save($db,$added,[],'test');$clauses2=safety_manual_db_clauses($db,$key2);
if(count($clauses2)!==count($clauses)+1||!str_contains(end($clauses2)['body'],'새 내용'))throw new Exception('New clause missing');
if(!str_contains(safety_manual_db_current($db,$key2)['content_html'],'새 조항 시험'))throw new Exception('New clause load failed');
echo 'PASS '.count($clauses).' clauses saved/loaded; added clause automatically became row '.count($clauses2).PHP_EOL;
$box='<div class="rule-table-wrap manual-subtitle-box rule-editable"><table class="rule-table"><tbody><tr><td>5. 수정한 조항</td></tr></tbody></table></div><p>수정 내용</p>';
$key3=safety_manual_db_save($db,$box,[],'test');$roundTrip=safety_manual_db_current($db,$key3)['content_html'];
if(!str_contains($roundTrip,'<td>5. 수정한 조항</td>')||str_contains($roundTrip,'제1조')||!str_contains($roundTrip,'수정 내용'))throw new Exception('Manual clause round trip failed');
echo "PASS edited manual clause stays selectable and receives no employment-rule prefix\n";
