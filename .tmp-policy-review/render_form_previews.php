<?php
require __DIR__.'/../risk_assessment/db_config.php';
require __DIR__.'/../safety_manual/review_storage.php';
require __DIR__.'/../safety_manual/goal_plan_storage.php';
require __DIR__.'/../safety_manual/review_sources.php';
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
$db=getDB();$year=2026;$previewOnly=true;$error='';$message='';$revision=0;$history=[];$_SESSION=['safety_review_csrf'=>'fixture'];
foreach(['review','plan'] as $kind)foreach([true,false] as $blankPreview){
    $items=safety_review_items();$template=goal_plan_template();$fields=goal_plan_fields();$previousRecord=null;$planBreakdown=[];
    if($kind==='review'){$values=safety_review_empty();if(!$blankPreview)$values['policy_text']=safety_review_source_text($db,$year);}
    else{$state=goal_plan_year_state($db,$year);$values=$state['rows'];$planBreakdown=$state['plan_breakdown'];if($blankPreview){foreach($values as &$row)foreach($row as $key=>&$value)if(!in_array($key,['name','frequency','unit'],true))$value='';unset($value,$row);}}
    $file=$kind==='review'?'policy_review.php':'goal_plan.php';$source=file_get_contents(__DIR__.'/../safety_manual/'.$file);
    $html=substr($source,strpos($source,'<!doctype html><html lang="ko"><head>'));
    $html=str_replace("__DIR__.'/assets/","__DIR__.'/../safety_manual/assets/",$html);
    $html=str_replace('<head>','<head><base href="file:///A:/risk_server/project/safety_manual/">',$html);
    ob_start();eval('?>'.$html);$output=ob_get_clean();
    file_put_contents(__DIR__.'/preview-'.$kind.'-'.($blankPreview?'blank':'filled').'.html',$output);
}
