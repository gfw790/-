<?php
declare(strict_types=1);
require_once __DIR__.'/policy_storage.php';

function safety_review_source_text(PDO $db,int $year): string
{
    $policy=safety_policy_load($db,$year);
    $parts=[];
    if(trim($policy['policy']??'')!=='')$parts[]="안전보건방침\n".trim($policy['policy']);
    $goals=[];
    foreach($policy['goals']??[] as $goal){
        $goal=trim($goal);
        if($goal!=='')$goals[]=(count($goals)+1).'. '.$goal;
    }
    if($goals)$parts[]="안전보건 목표\n".implode("\n",$goals);
    return implode("\n\n",$parts);
}
