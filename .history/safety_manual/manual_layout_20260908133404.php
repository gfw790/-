<?php
declare(strict_types=1);
function safety_manual_indent_html(string $html): string
{
    $dom=new DOMDocument();
    if(!@$dom->loadHTML('<?xml encoding="utf-8" ?><div id="manual-indent-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET))return $html;
    $xpath=new DOMXPath($dom);$root=$dom->getElementById('manual-indent-root');
    if(!$root)return $html;
    foreach($xpath->query('.//p[not(ancestor::table)]',$root) as $paragraph){
        $text=trim($paragraph->textContent);
        $classes=preg_replace('/\bmanual-indent-(?:body|clause|item)\b/','',$paragraph->getAttribute('class'));
        $indent='';
        if(preg_match('/^\d+[.,]\d+(?:\.\d+)*\s/u',$text))$indent='manual-indent-clause';
        elseif(preg_match('/^(?:\d+\)|\([0-9]+\)|[가-하][.)]|[①-⑳])\s*/u',$text))$indent='manual-indent-item';
        elseif($text!==''&&!preg_match('/^(?:\d+\.\s|제\s*\d+\s*장)/u',$text)&&$paragraph->getAttribute('id')!=='manual-policy-link')$indent='manual-indent-body';
        $paragraph->setAttribute('class',trim($classes.' '.$indent));
    }
    foreach($xpath->query('.//h2 | .//h3',$root) as $heading){
        $text=trim(preg_replace('/\s+/u',' ',$heading->textContent));
        if(preg_match('/^제\s*\d+\s*장/u',$text)){
            $classes=trim($heading->getAttribute('class').' manual-chapter-title');
            $heading->setAttribute('class',$classes);
        }
    }
    $result='';foreach($root->childNodes as $child)$result.=$dom->saveHTML($child);
    return $result;
}
