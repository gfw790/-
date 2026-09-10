<?php
require __DIR__.'/../risk_assessment/db_config.php';
require __DIR__.'/../safety_manual/manual_db.php';
$db=getDB();safety_manual_db_init($db);
foreach(['safety_manual_revisions','safety_manual_clauses'] as $table)echo $table.': '.$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn().PHP_EOL;
$rows=$db->query('SELECT revision_key,clause_key,heading,LEFT(body,80) body FROM safety_manual_clauses ORDER BY clause_id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
$latest=safety_manual_db_latest($db);
if(!$latest||!str_contains($latest['content_html'],'data-manual-form="review"')||!str_contains($latest['content_html'],'5.11'))throw new RuntimeException('Latest DB reconstruction failed');
echo 'PASS latest revision reconstructed from clause rows; 5.11 links retained'.PHP_EOL;
