<?php
require __DIR__.'/../risk_assessment/db_config.php';require __DIR__.'/../safety_manual/manual_db.php';
$db=getDB();$row=safety_manual_db_latest($db);$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?><div id="root">'.$row['content_html'].'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
$xp=new DOMXPath($dom);$nodes=$xp->query('//*[@id="root"]//h2|//*[@id="root"]//h3|//*[@id="root"]//div[contains(@class,"manual-subtitle-box")]');
foreach($nodes as $i=>$n)echo ($i+1).' '.$n->nodeName.' ['.($n instanceof DOMElement?$n->getAttribute('class'):'').'] '.trim($n->textContent).PHP_EOL;
