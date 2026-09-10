<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../risk_assessment/db_config.php';require __DIR__.'/manual_db.php';require __DIR__.'/cover_storage.php';
$db=getDB();safety_manual_db_init($db);$latest=safety_manual_db_latest($db);if(!$latest)throw new RuntimeException('복구할 DB 본문이 없습니다.');
$before=$latest['content_html'];$repaired=safety_manual_repair_clause_headings_html($before);
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?><div id="root">'.$repaired.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);$xp=new DOMXPath($dom);
$boxes=$xp->query('//*[@id="root"]//div[contains(concat(" ",normalize-space(@class)," ")," manual-subtitle-box ")]');
if(!$boxes instanceof DOMNodeList||$boxes->length===0)throw new RuntimeException('복구된 조항 제목이 없습니다.');
foreach($boxes as $box){if(!$box instanceof DOMElement||!$box->getElementsByTagName('td')->item(0)||preg_match('/^제\s*\d+\s*조/u',trim($box->textContent)))throw new RuntimeException('조항 제목 복구 검증에 실패했습니다.');}
if($repaired===$before){echo "복구할 변경사항이 없습니다.\n";exit;}
$data=json_decode(file_get_contents(__DIR__.'/data.json'),true,512,JSON_THROW_ON_ERROR);$data['current']['content_html']=$repaired;$data['current']['updated_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');$data['current']['updated_by']='조항 제목 자동 복구';
if(file_put_contents(__DIR__.'/data.json',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('JSON 예비 사본을 갱신하지 못했습니다.');
$key=safety_manual_db_save($db,$repaired,safety_cover_load_data(),'조항 제목 자동 복구');
echo 'PASS revision '.$key.', restored title boxes '.$boxes->length.PHP_EOL;
