<?php
require __DIR__ . '/../safety_manual/review_storage.php';
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$items=safety_review_items(); $year=2026; $history=[2025]; $revision=0; $error=''; $message='';
$_SESSION=['safety_review_csrf'=>'fixture']; $values=safety_review_empty();
$values['policy_text']="안전보건 경영방침\n\n주식회사 현대기전은 안전하고 쾌적한 작업환경을 조성하고, 근로자의 참여와 협의를 통해 안전보건관리체계를 지속적으로 개선한다.\n\n안전보건 목표\n1. 중대재해 예방\n2. 유해·위험요인 확인 및 개선\n3. 근로자 의견 청취\n4. 안전보건교육 실시\n5. 개선대책 이행 확인";
foreach($values['items'] as &$item) { $item['status']='적합'; $item['note']='확인'; } unset($item);
$source=file_get_contents(__DIR__.'/../safety_manual/policy_review.php');
$html=substr($source,strpos($source,'<!doctype html><html lang="ko"><head>'));
$html=str_replace('<head>', '<head><base href="file:///A:/risk_server/project/safety_manual/">', $html);
ob_start(); eval('?>'.$html); $output=ob_get_clean();
$test= <<<'JS'
<script>
window.addEventListener('load', async () => {
    await document.fonts.ready;
    document.getElementById('review-print-open').click();
    await new Promise(resolve => setTimeout(resolve, 100));
    const sheet=document.querySelector('.review-sheet');
    const report={rows:sheet.querySelectorAll('tr').length, fits:sheet.getBoundingClientRect().height<=1124, statuses:sheet.querySelectorAll('.review-result').length};
    document.body.dataset.test=JSON.stringify(report);
});
</script>
JS;
file_put_contents(__DIR__.'/fixture.html',str_replace('</body>',$test.'</body>',$output));
