<?php
declare(strict_types=1);

function vfl_excel_xml(string $value): string
{
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function vfl_excel_cell(string $ref, string $value, int $style): string
{
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . vfl_excel_xml($value) . '</t></is></c>';
}

function vfl_excel_hanging_paragraph(string $text): string
{
    // Excel cells have no first-line/hanging-indent property. Use measured lines and
    // an ideographic space matching the circled number's width on continuation lines.
    if (!preg_match('/^(①\s+)(.*)$/us', $text, $match)) return $text;
    $font = 'C:/Windows/Fonts/malgun.ttf';
    $measure = static function (string $line) use ($font): float {
        if (function_exists('imagettfbbox') && is_file($font)) {
            $box = imagettfbbox(10, 0, $font, $line);
            if ($box !== false) return (max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6])) * 72 / 96;
        }
        return mb_strwidth($line) * 5.0;
    };
    $lines = [];
    $line = $match[1];
    foreach (preg_split('/\s+/u', $match[2], -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $candidate = rtrim($line) . ' ' . $word;
        if ($measure($candidate) > 310 && trim($line) !== '①') {
            $lines[] = $line;
            $line = '　 ' . $word;
        } else $line = $candidate;
    }
    $lines[] = $line;
    return implode("\n", $lines);
}

function vfl_excel_header_cell(string $ref, array $parts, int $style): string
{
    $runs = '';
    foreach ($parts as [$text, $size, $bold, $color]) {
        $runs .= '<r><rPr><rFont val="맑은 고딕"/><sz val="'.$size.'"/>'.($bold ? '<b/>' : '').'<color rgb="FF'.$color.'"/></rPr><t xml:space="preserve">'.vfl_excel_xml($text).'</t></r>';
    }
    return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is>'.$runs.'</is></c>';
}

function vfl_excel_styles(): string
{
    $fonts = '<font><sz val="11"/><name val="맑은 고딕"/></font><font><b/><sz val="24"/><name val="맑은 고딕"/></font><font><b/><sz val="11"/><name val="맑은 고딕"/></font><font><sz val="10"/><name val="맑은 고딕"/></font><font><b/><sz val="10"/><name val="맑은 고딕"/></font>';
    $fonts .= '<font><b/><sz val="9"/><name val="맑은 고딕"/></font>';
    $styles = [[0,0,0,'left','top'],[1,0,5,'center','center'],[3,0,1,'center','center'],[0,0,1,'left','center'],[2,2,1,'center','center'],[3,0,2,'left','center'],[3,3,2,'left','center'],[4,0,2,'left','center'],[4,3,2,'left','center'],[3,0,1,'left','center'],[3,0,1,'center','center'],[0,0,1,'left','top'],[2,0,0,'right','center'],[5,2,1,'center','center'],[0,0,3,'left','center'],[0,0,4,'right','center'],[2,0,1,'center','center'],[3,0,2,'left','center'],[3,3,2,'left','center'],[3,0,2,'left','center'],[3,3,2,'left','center']];
    $xfs = '';
    foreach ($styles as $styleIndex => [$font,$fill,$border,$horizontal,$vertical]) $xfs .= '<xf numFmtId="0" fontId="'.$font.'" fillId="'.$fill.'" borderId="'.$border.'" xfId="0" applyAlignment="1" applyFill="1" applyBorder="1"><alignment horizontal="'.$horizontal.'" vertical="'.$vertical.'"'.(in_array($styleIndex,[17,18,19,20],true) ? ' indent="'.($styleIndex >= 19 ? 2 : 1).'"' : '').' wrapText="'.(in_array($styleIndex,[1,5,6,7,8,16],true) ? '0' : '1').'" shrinkToFit="'.(in_array($styleIndex,[1,5,6,7,8,16],true) ? '1' : '0').'"/></xf>';
    return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="6">'.$fonts.'</fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="6"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border><border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF000000"/></left><right/><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border><border><left/><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border><border><left style="medium"><color rgb="FF000000"/></left><right style="medium"><color rgb="FF000000"/></right><top style="medium"><color rgb="FF000000"/></top><bottom style="medium"><color rgb="FF000000"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="21">'.$xfs.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/** Build a native XLSX report; all record text is literal, never an Excel formula. */
function vfl_excel_build(string $destination, array $records, array $files): void
{
    $reference = json_decode(file_get_contents(__DIR__ . '/vfl_print_reference.json'), true, 512, JSON_THROW_ON_ERROR);
    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create VFL workbook');
    try {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $packageRel = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $types = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $sheets = $workbookRels = $printAreas = '';
        foreach (array_values($records) as $index => $record) {
            $number = $index + 1;
            $data = $record['data'];
            $sheetName = $record['activity_date'] . '_' . $record['id'];
            $sheets .= '<sheet name="'.vfl_excel_xml($sheetName).'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
            $workbookRels .= '<Relationship Id="rId'.$number.'" Type="'.$rel.'/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
            $rows = $heights = $merges = [];
            $put = static function(string $start, string $end, string $text, int $style) use (&$rows, &$merges): void {
                preg_match('/([A-K])(\d+)/', $start, $from);
                preg_match('/([A-K])(\d+)/', $end, $to);
                for ($r=(int)$from[2]; $r<=(int)$to[2]; $r++) for ($c=ord($from[1]); $c<=ord($to[1]); $c++) $rows[$r][chr($c)] = vfl_excel_cell(chr($c).$r, $r===(int)$from[2] && $c===ord($from[1]) ? $text : '', $style);
                if ($start !== $end) $merges[] = $start.':'.$end;
            };
            $put('A1','K3','',1);
            $rows[1]['A'] = vfl_excel_header_cell('A1', [['안전순찰 일지',24,true,'000000'],['　 Safety inspection report',16,false,'000000']],1);
            $put('A4','B4','일자 및 시간',2); $put('C4','G4',$record['activity_date'].' '.($data['vfl_time'] ?? ''),3);
            $put('H4','H4','순찰지역',2); $put('I4','K4',$data['location'],3);
            $put('A5','B5','순찰자',2); $put('C5','H5',$data['owner'],14); $put('I5','K5','(서명) __________________',15);
            $put('A6','K6','',16);
            $rows[6]['A'] = vfl_excel_header_cell('A6', [['중점 점검 사항',13,true,'000000'],['　선택한 항목은 노란 형광색으로 표시',9,false,'61738C']],16);
            $groups = ['A'=>[8=>[14,'general_permit'],15=>[23,'confined_space'],24=>[31,'hot_work'],33=>[40,'electrical_isolation']], 'H'=>[7=>[12,'excavation'],13=>[19,'height'],20=>[26,'heavy_equipment'],28=>[29,'ppe'],30=>[30,'loto'],31=>[31,'education'],32=>[32,'housekeeping'],34=>[37,'tools_equipment']]];
            // The reference splits these sentences across worksheet rows, not at word boundaries.
            // Keep the original row heights but let each complete paragraph wrap across its block.
            $paragraphs = [21 => 22, 34 => 36];
            foreach (['A'=>'G','H'=>'K'] as $column=>$endColumn) for ($row=7;$row<=40;$row++) {
                $heights[$row] = 12.75;
                if ($column === 'A' && in_array($row, [22,35,36], true)) continue;
                $text = $reference['guide'][$column.$row] ?? '';
                $selected = false;
                foreach ($groups[$column] as $start=>[$end,$code]) if ($row >= $start && $row <= $end && in_array($code,$data['vfl_checks'] ?? [],true)) $selected=true;
                $bold = preg_match('/^\s*(▶|[1-4]\. |[1-4]\))/', $text);
                if ($column === 'A' && isset($paragraphs[$row])) {
                    for ($continuation=$row+1; $continuation<=$paragraphs[$row]; $continuation++) $text = rtrim($text) . ' ' . trim($reference['guide']['A'.$continuation] ?? '');
                    $paragraphStyle = $row === 21 ? ($selected ? 20 : 19) : ($selected ? 18 : 17);
                    $text = ltrim($text);
                    if ($row === 34) $text = vfl_excel_hanging_paragraph($text);
                    $put('A'.$row,'G'.$paragraphs[$row],$text,$paragraphStyle);
                } else {
                    $put($column.$row,$endColumn.$row,$text,$selected ? ($bold ? 8 : 6) : ($bold ? 7 : 5));
                }
            }
            $offset = !empty($data['vfl_focus_notes']) ? 3 : 0;
            if ($offset) {
                $put('A41','K43',"추가 중점 점검사항\n".$data['vfl_focus_notes'],9);
                // Keep longer notes readable rather than clipping them to a single line.
                $heights[41] = max(14, 14 * (1 + array_sum(array_map(static fn(string $line): int => max(1, (int)ceil(mb_strwidth($line)/110)), explode("\n", $data['vfl_focus_notes'])))));
            }
            $countStart = 41+$offset; $countEnd = 43+$offset;
            $put('A'.$countStart,'G'.$countEnd,'● 안전순찰중 대화인원 : '.(isset($data['vfl_dialog_count']) ? $data['vfl_dialog_count'].' 명' : '미기록'),9);
            $put('H'.$countStart,'K'.$countEnd,"● 분류  SRV : 안전수칙위반\n          UA : 불안전한 행동\n          UC : 불안전한 상태",9);
            $head = 44+$offset;
            $put('A'.$head,'F'.$head,'점검사진',13); $put('G'.$head,'K'.$head,'안전대화 내용',13);
            $heights[$head] = 11.25;
            $photos = [];
            foreach ($files[$record['id']] ?? [] as $file) if (($file['deleted_at'] ?? null) === null && in_array((int)($file['photo_slot'] ?? 0),[1,2],true)) $photos[(int)$file['photo_slot']]=$file;
            $images = [];
            foreach ([1,2] as $slot) {
                $start = 45+$offset+($slot-1)*7; $end=$start+6;
                $photo = $photos[$slot] ?? null;
                $put('A'.$start,'F'.$end,$photo ? '' : '점검사진 '.$slot.' 미등록',10);
                $caption = $photo['caption'] ?? '';
                $put('G'.$start,'K'.$end,$caption,11);
                $lineCount = array_sum(array_map(static fn(string $line): int => max(1, (int)ceil(mb_strwidth($line)/65)), explode("\n",$caption)));
                $height = max(29.9, $lineCount * 13 / 7);
                for ($r=$start;$r<=$end;$r++) $heights[$r]=$height;
                if ($photo && preg_match('/^[a-f0-9]{40}\.(jpg|png)$/D',$photo['stored_name'])) {
                    $path=__DIR__.'/storage/leadership_evidence/'.$photo['stored_name'];
                    if (is_file($path) && ($dimensions=@getimagesize($path))) $images[]=['path'=>$path,'column'=>0,'x_offset'=>0,'y_offset'=>0,'row'=>$start-1,'width'=>373.1,'height'=>7*$height*96/72,'dimensions'=>$dimensions];
                }
            }
            $heights[59+$offset] = 6;
            $footer=60+$offset; $put('G'.$footer,'K'.$footer,'주식회사 현대기전',12); $heights[$footer]=22;
            $logo=dirname(__DIR__).'/safety_manual/assets/policy-logo.png';
            if (is_file($logo) && ($dimensions=@getimagesize($logo))) $images[]=['path'=>$logo,'column'=>9,'x_offset'=>96,'row'=>$footer-1,'width'=>22,'height'=>22,'dimensions'=>$dimensions];
            $sheetData='';
            for ($row=1;$row<=$footer;$row++) {
                $height=$heights[$row] ?? ($row<=3 ? 19.5 : ($row>=4 && $row<=6 ? 18.95 : 12.75));
                $cells=$rows[$row] ?? []; ksort($cells);
                $sheetData.='<row r="'.$row.'" ht="'.$height.'" customHeight="1">'.implode('',$cells).'</row>';
            }
            $drawing='';
            if ($images) {
                $anchors=$drawingRels='';
                foreach ($images as $imageIndex=>$image) {
                    $imageId=$imageIndex+1;
                    $ext=strtolower(pathinfo($image['path'],PATHINFO_EXTENSION));
                    $media='vfl'.$number.'_'.$imageId.'.'.$ext;
                    if (!$zip->addFile($image['path'],'xl/media/'.$media)) throw new RuntimeException('Cannot embed report photo');
                    $ratio=min($image['width']/$image['dimensions'][0],$image['height']/$image['dimensions'][1]);
                    $width=max(1,(int)round($image['dimensions'][0]*$ratio*9525)); $height=max(1,(int)round($image['dimensions'][1]*$ratio*9525));
                    $anchors.='<xdr:oneCellAnchor><xdr:from><xdr:col>'.$image['column'].'</xdr:col><xdr:colOff>'.(int)(($image['x_offset'] ?? 4)*9525).'</xdr:colOff><xdr:row>'.$image['row'].'</xdr:row><xdr:rowOff>'.(int)(($image['y_offset'] ?? 4)*9525).'</xdr:rowOff></xdr:from><xdr:ext cx="'.$width.'" cy="'.$height.'"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.$imageId.'" name="Image '.$imageId.'"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId'.$imageId.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
                    $drawingRels.='<Relationship Id="rId'.$imageId.'" Type="'.$rel.'/image" Target="../media/'.$media.'"/>';
                }
                $zip->addFromString('xl/drawings/drawing'.$number.'.xml','<?xml version="1.0" encoding="UTF-8"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="'.$rel.'">'.$anchors.'</xdr:wsDr>');
                $zip->addFromString('xl/drawings/_rels/drawing'.$number.'.xml.rels','<?xml version="1.0"?><Relationships xmlns="'.$packageRel.'">'.$drawingRels.'</Relationships>');
                $zip->addFromString('xl/worksheets/_rels/sheet'.$number.'.xml.rels','<?xml version="1.0"?><Relationships xmlns="'.$packageRel.'"><Relationship Id="rId1" Type="'.$rel.'/drawing" Target="../drawings/drawing'.$number.'.xml"/></Relationships>');
                $types.='<Override PartName="/xl/drawings/drawing'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
                $drawing='<drawing r:id="rId1"/>';
            }
            $mergeXml=''; foreach($merges as $merge) $mergeXml.='<mergeCell ref="'.$merge.'"/>';
            $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:K'.$footer.'"/><sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols><col min="1" max="1" width="7.51054688" customWidth="1"/><col min="2" max="2" width="9.2421875" customWidth="1"/><col min="3" max="6" width="7.51054688" customWidth="1"/><col min="7" max="7" width="9.67382812" customWidth="1"/><col min="8" max="8" width="17.184375" customWidth="1"/><col min="9" max="9" width="13.43164062" customWidth="1"/><col min="10" max="10" width="12.85273438" customWidth="1"/><col min="11" max="11" width="17.33164063" customWidth="1"/></cols><sheetData>'.$sheetData.'</sheetData><mergeCells count="'.count($merges).'">'.$mergeXml.'</mergeCells><printOptions horizontalCentered="1"/><pageMargins left="0.3937" right="0.3937" top="0.7874" bottom="0.315" header="0" footer="0"/><pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="1"/>'.$drawing.'</worksheet>';
            $zip->addFromString('xl/worksheets/sheet'.$number.'.xml',$xml);
            $types.='<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $printAreas.='<definedName name="_xlnm.Print_Area" localSheetId="'.$index.'">'.vfl_excel_xml("'".$sheetName."'!\$A\$1:\$K\$".$footer).'</definedName>';
        }
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$types.'</Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="'.$packageRel.'"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><bookViews><workbookView/></bookViews><sheets>'.$sheets.'</sheets><definedNames>'.$printAreas.'</definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="'.$packageRel.'">'.$workbookRels.'<Relationship Id="styles" Type="'.$rel.'/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml',vfl_excel_styles());
    } finally {
        if (!$zip->close()) throw new RuntimeException('Cannot finalize VFL workbook');
    }
}
