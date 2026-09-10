<?php
$path=__DIR__.'/../safety_manual/data.json';
$data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$review='<a href="policy_review.php?preview=1" data-manual-form="review">양식1) 안전보건방침 및 목표 검토 결과</a>';
$plan='<a href="goal_plan.php?preview=1" data-manual-form="plan">양식2)안전보건 목표 및 추진계획</a>';
$sentence='5.11 안전보건 목표 및 경영방침의 설정 등 구체적인 내용은 '.$review.', '.$plan.'에 따른다.';
$html=$data['current']['content_html'];
$updated=preg_replace('/<p>5\.11\s.*?<\/p>/us','<p>'.$sentence.'</p>',$html,1,$count);
if($count!==1)throw new Exception('5.11 paragraph not found');
$data['current']['content_html']=$updated;
$data['current']['plain_text']=preg_replace('/5\.11[^\r\n]*/u',strip_tags($sentence),$data['current']['plain_text'],1);
file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
echo "PASS manual 5.11 wording and two form links saved\n";
