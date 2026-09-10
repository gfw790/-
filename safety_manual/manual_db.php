<?php
declare(strict_types=1);

function safety_manual_repair_clause_headings_html(string $contentHtml): string
{
    $dom=new DOMDocument();
    if(!@$dom->loadHTML('<?xml encoding="utf-8" ?><div id="manual-repair-root">'.$contentHtml.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET))return $contentHtml;
    $root=$dom->getElementById('manual-repair-root');if(!$root instanceof DOMElement)return $contentHtml;
    $xpath=new DOMXPath($dom);$nodes=$xpath->query('.//div[contains(concat(" ",normalize-space(@class)," ")," manual-subtitle-box ")]',$root);
    if($nodes instanceof DOMNodeList)foreach(iterator_to_array($nodes) as $wrap){
        if(!$wrap instanceof DOMElement)continue;
        $title=trim((string)preg_replace('/^제\s*\d+\s*조(?:\s*의\s*\d+)?\s*/u','',trim($wrap->textContent)));
        $class=trim((string)preg_replace('/\s*rule-editable\s*/',' ',$wrap->getAttribute('class')));$wrap->setAttribute('class',$class);
        $cell=$wrap->getElementsByTagName('td')->item(0);
        if($cell instanceof DOMElement){$cell->textContent=$title;continue;}
        while($wrap->firstChild)$wrap->removeChild($wrap->firstChild);
        $table=$dom->createElement('table');$table->setAttribute('class','rule-table');$tbody=$dom->createElement('tbody');$tr=$dom->createElement('tr');$td=$dom->createElement('td');$td->textContent=$title;
        $tr->appendChild($td);$tbody->appendChild($tr);$table->appendChild($tbody);$wrap->appendChild($table);
    }
    $html='';foreach($root->childNodes as $node)$html.=$dom->saveHTML($node);return trim($html);
}

function safety_manual_db_init(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_manual_revisions (
        revision_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        revision_key CHAR(36) NOT NULL UNIQUE,
        revision_date DATE NULL,
        revision_count VARCHAR(50) NOT NULL DEFAULT "",
        content_html MEDIUMTEXT NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_manual_revision_date (revision_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS safety_manual_clauses (
        clause_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        revision_key CHAR(36) NOT NULL,
        clause_key VARCHAR(190) NOT NULL,
        heading VARCHAR(500) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_manual_clause (revision_key, clause_key),
        INDEX idx_manual_clause_key (clause_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!$db->query("SHOW COLUMNS FROM safety_manual_clauses LIKE 'sort_order'")->fetch()) {
        try { $db->exec('ALTER TABLE safety_manual_clauses ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER heading, ADD INDEX idx_manual_clause_order (revision_key, sort_order)'); }
        catch (PDOException $error) { if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error; }
    }
}

function safety_manual_db_revisions(PDO $db): array
{
    return $db->query('SELECT revision_key, revision_date, revision_count, updated_by, updated_at FROM safety_manual_revisions ORDER BY revision_id DESC')->fetchAll();
}

function safety_manual_db_current(PDO $db, string $key): ?array
{
    $stmt = $db->prepare('SELECT revision_key, revision_date, revision_count, content_html, updated_by, updated_at FROM safety_manual_revisions WHERE revision_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!is_array($row)) return null;
    $clauses = safety_manual_db_clauses($db, $key);
    if ($clauses !== []) $row['content_html'] = implode("\n", array_column($clauses, 'body'));
    return $row;
}

function safety_manual_db_latest(PDO $db): ?array
{
    $key=$db->query('SELECT revision_key FROM safety_manual_revisions ORDER BY revision_id DESC LIMIT 1')->fetchColumn();
    return is_string($key)&&$key!==''?safety_manual_db_current($db,$key):null;
}

function safety_manual_db_clauses(PDO $db,string $key): array
{
    $stmt=$db->prepare('SELECT clause_key,heading,sort_order,body,updated_at FROM safety_manual_clauses WHERE revision_key=? ORDER BY sort_order,clause_id');
    $stmt->execute([$key]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function safety_manual_db_split_clauses(string $contentHtml): array
{
    $dom=new DOMDocument();
    if(!@$dom->loadHTML('<?xml encoding="utf-8" ?><div id="manual-clause-root">'.$contentHtml.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET))throw new RuntimeException('조항 HTML을 분석하지 못했습니다.');
    $root=$dom->getElementById('manual-clause-root');if(!$root instanceof DOMElement)throw new RuntimeException('조항 문서 루트를 찾지 못했습니다.');
    $groups=[];$current=null;$used=[];
    foreach(iterator_to_array($root->childNodes) as $node){
        if(!$node instanceof DOMNode)continue;
        $text=trim((string)$node->textContent);$tag=$node instanceof DOMElement?strtolower($node->tagName):'';
        $isSubtitle=$node instanceof DOMElement&&preg_match('/(^|\s)manual-subtitle-box(\s|$)/',$node->getAttribute('class'));
        $isClause=$tag==='h2'||$tag==='h3'||$isSubtitle||($tag==='p'&&preg_match('/^\d+(?:\.\d+)*\.?(?:\s|$)/u',$text));
        if($isClause||$current===null){
            $heading=$isClause?$text:'문서 머리말';
            $base=$node instanceof DOMElement?trim($node->getAttribute('id')):'';
            if($base==='')$base=$isClause?'clause-'.substr(sha1(preg_replace('/\s+/u',' ',$heading)),0,16):'document-preamble';
            $used[$base]=($used[$base]??0)+1;$key=$base.($used[$base]>1?'-'.$used[$base]:'');
            $groups[]=['clause_key'=>$key,'heading'=>$heading,'body'=>''];$current=count($groups)-1;
        }
        $groups[$current]['body'].=$dom->saveHTML($node);
    }
    return array_values(array_filter($groups,static fn($group)=>trim($group['body'])!==''));
}

function safety_manual_db_save(PDO $db, string $contentHtml, array $coverData, string $editor): string
{
    $contentHtml=safety_manual_repair_clause_headings_html($contentHtml);
    $key = (string)preg_replace('/[^a-f0-9-]/', '', strtolower(trim((string)($_POST['revision_key'] ?? ''))));
    if ($key === '' || strlen($key) !== 36) $key = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    $started=!$db->inTransaction();if($started)$db->beginTransaction();
    try{
        $stmt = $db->prepare('INSERT INTO safety_manual_revisions (revision_key, revision_date, revision_count, content_html, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$key, (($coverData['revision_date'] ?? '') !== '' ? $coverData['revision_date'] : null), (string)($coverData['revision_count'] ?? ''), $contentHtml, $editor, (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s')]);
        $clauseStmt=$db->prepare('INSERT INTO safety_manual_clauses (revision_key,clause_key,heading,sort_order,body,updated_at) VALUES (?,?,?,?,?,?)');
        $now=date('Y-m-d H:i:s');
        foreach(safety_manual_db_split_clauses($contentHtml) as $order=>$clause)$clauseStmt->execute([$key,$clause['clause_key'],$clause['heading'],$order,$clause['body'],$now]);
        if($started)$db->commit();return $key;
    }catch(Throwable $error){if($started&&$db->inTransaction())$db->rollBack();throw $error;}
}
