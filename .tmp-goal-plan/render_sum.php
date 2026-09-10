<?php
require __DIR__ . '/../safety_manual/goal_plan_storage.php';
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$year=2026;$template=goal_plan_template();$fields=goal_plan_fields();$values=goal_plan_defaults($year);$revision=0;$error='';$message='';$previousRecord=null;$_SESSION=['goal_plan_csrf'=>'fixture'];
require __DIR__.'/../risk_assessment/db_config.php';$state=goal_plan_year_state(getDB(),2026);$values=$state['rows'];$planBreakdown=$state['plan_breakdown'];
if($values['r18']['plan']!=='50'||$planBreakdown['r18']['quarters']!==['20','10','10','10'])throw new Exception('Sum mismatch');
echo 'PASS annual near-miss plan 20+10+10+10=50'.PHP_EOL;
$source=file_get_contents(__DIR__.'/../safety_manual/goal_plan.php');
$html=substr($source,strpos($source,'<!doctype html><html lang="ko"><head>'));
$html=str_replace('<head>','<head><base href="file:///A:/risk_server/project/safety_manual/">',$html);
ob_start();eval('?>'.$html);$output=ob_get_clean();
$script= <<<'JS'
<script>window.addEventListener('load',async()=>{await document.fonts.ready;document.querySelector('[data-plan-id="r18"]').click();if(document.getElementById('plan-detail-rows').children.length!==4||document.getElementById('plan-detail-total').textContent!=='50')throw new Error('Modal mismatch');document.body.dataset.modal='passed';document.getElementById('plan-detail-close').click();document.getElementById('goal-plan-preview-open').click();await new Promise(r=>setTimeout(r,100));const page=document.querySelector('.goal-plan-sheet');document.body.dataset.test=JSON.stringify({items:page.querySelectorAll('.print-item').length,sections:page.querySelectorAll('.print-section').length,fits:!document.getElementById('goal-plan-print').disabled,height:page.getBoundingClientRect().height});});</script>
JS;
file_put_contents(__DIR__.'/fixture.html',str_replace('</body>',$script.'</body>',$output));
