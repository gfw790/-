<?php
require __DIR__.'/../safety_manual/quarter_plan_storage.php';
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
$year=2026;$quarter=(int)($argv[1]??1);$monthStart=($quarter-1)*3+1;$template=quarter_plan_template();$values=quarter_plan_defaults($year,$quarter);$revision=1;$previous=null;$error='';$message='';$_SESSION=['quarter_plan_csrf'=>'fixture'];
$source=file_get_contents(__DIR__.'/../safety_manual/goal_plan_quarter.php');$html=substr($source,strpos($source,'<!doctype html><html lang="ko"><head>'));
$html=str_replace('<head>','<head><base href="file:///A:/risk_server/project/safety_manual/">',$html);ob_start();eval('?>'.$html);$output=ob_get_clean();
$script= <<<'JS'
<script>window.addEventListener('load',async()=>{await document.fonts.ready;document.getElementById('quarter-preview-open').click();await new Promise(r=>setTimeout(r,200));const page=document.querySelector('.quarter-sheet');document.body.dataset.test=JSON.stringify({items:page.querySelectorAll('.print-item').length,sections:page.querySelectorAll('.print-section').length,fits:!document.getElementById('quarter-print').disabled,height:page.getBoundingClientRect().height});});</script>
JS;
file_put_contents(__DIR__.'/fixture.html',str_replace('</body>',$script.'</body>',$output));
