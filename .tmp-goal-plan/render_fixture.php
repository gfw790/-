<?php
require __DIR__ . '/../safety_manual/goal_plan_storage.php';
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$year=2026;$template=goal_plan_template();$fields=goal_plan_fields();$values=goal_plan_defaults($year);$revision=0;$error='';$message='';$previousRecord=null;$_SESSION=['goal_plan_csrf'=>'fixture'];
$source=file_get_contents(__DIR__.'/../safety_manual/goal_plan.php');
$html=substr($source,strpos($source,'<!doctype html><html lang="ko"><head>'));
$html=str_replace('<head>','<head><base href="file:///A:/risk_server/project/safety_manual/">',$html);
ob_start();eval('?>'.$html);$output=ob_get_clean();
$script= <<<'JS'
<script>window.addEventListener('load',async()=>{await document.fonts.ready;document.getElementById('goal-plan-preview-open').click();await new Promise(r=>setTimeout(r,100));const page=document.querySelector('.goal-plan-sheet');document.body.dataset.test=JSON.stringify({items:page.querySelectorAll('.print-item').length,sections:page.querySelectorAll('.print-section').length,fits:!document.getElementById('goal-plan-print').disabled,height:page.getBoundingClientRect().height});});</script>
JS;
file_put_contents(__DIR__.'/fixture.html',str_replace('</body>',$script.'</body>',$output));
