<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../risk_assessment/db_config.php';require __DIR__.'/manual_db.php';
$data=json_decode((string)file_get_contents(__DIR__.'/data.json'),true,512,JSON_THROW_ON_ERROR);$current=$data['current']??null;
if(!is_array($current)||trim((string)($current['content_html']??''))==='')throw new RuntimeException('이전할 본문이 없습니다.');
$db=getDB();safety_manual_db_init($db);
if(safety_manual_db_latest($db)!==null){echo "DB 본문이 이미 존재하여 변경하지 않았습니다.\n";exit;}
$key=safety_manual_db_save($db,(string)$current['content_html'],[],trim((string)($current['updated_by']??$current['uploaded_by']??'기존 JSON 마이그레이션')));
$clauses=safety_manual_db_clauses($db,$key);if($clauses===[])throw new RuntimeException('조항 이전 결과가 비어 있습니다.');
echo 'PASS revision '.$key.', clauses '.count($clauses).PHP_EOL;
