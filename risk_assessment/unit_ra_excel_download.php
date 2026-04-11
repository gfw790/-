<?php
// ================================================================
// unit_ra_excel_download.php
// MySQL → 단위 위험성평가서 엑셀 다운로드
// 사용: GET ?unit_ra_id=1
// ================================================================
ob_start();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;

// ── 파라미터 검증 ─────────────────────────────────────────────────
$unitRaId = filter_input(INPUT_GET, 'unit_ra_id', FILTER_VALIDATE_INT);
if (!$unitRaId || $unitRaId <= 0) {
    http_response_code(400);
    exit('unit_ra_id 파라미터가 필요합니다. 예: ?unit_ra_id=1');
}

// ── DB 조회 ───────────────────────────────────────────────────────
try {
    $pdo = getDB();

    $stmtH = $pdo->prepare("SELECT * FROM unit_ra_header WHERE unit_ra_id = :id");
    $stmtH->execute([':id' => $unitRaId]);
    $header = $stmtH->fetch();
    if (!$header) {
        http_response_code(404);
        exit("unit_ra_id={$unitRaId} 에 해당하는 평가서가 없습니다.");
    }

    $stmtI = $pdo->prepare("
        SELECT * FROM unit_ra_item
        WHERE unit_ra_id = :id AND use_yn = 'Y'
        ORDER BY sort_no ASC
    ");
    $stmtI->execute([':id' => $unitRaId]);
    $items = $stmtI->fetchAll();

} catch (\PDOException $e) {
    http_response_code(500);
    exit('DB 오류: ' . $e->getMessage());
}

// ── 스타일 헬퍼 ──────────────────────────────────────────────────
const FONT_NAME  = '맑은 고딕';
const C_HDR_BG   = 'FF1F4E79';
const C_HDR_FG   = 'FFFFFFFF';
const C_SEC_BG   = 'FF2E75B6';
const C_SEC_FG   = 'FFFFFFFF';
const C_LABEL_BG = 'FFD6E4F0';
const C_LABEL_FG = 'FF1F4E79';
const C_COL_BG   = 'FF4472C4';
const C_COL_FG   = 'FFFFFFFF';
const C_REQ_BG   = 'FFFFF2CC';
const C_ODD_BG   = 'FFEBF3FB';
const C_WHITE    = 'FFFFFFFF';
const C_BOR      = 'FFB8CCE4';

function thin(): array {
    $s = ['style' => Border::BORDER_THIN, 'color' => ['argb' => C_BOR]];
    return ['borders' => ['allBorders' => $s]];
}

function applyStyle($ws, string $range, array $opts): void {
    $arr = [];

    // 폰트
    $arr['font'] = [
        'name'  => FONT_NAME,
        'bold'  => $opts['bold']  ?? false,
        'size'  => $opts['size']  ?? 10,
        'color' => ['argb' => $opts['fg'] ?? 'FF000000'],
    ];

    // 채우기
    if (!empty($opts['bg'])) {
        $arr['fill'] = [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['argb' => $opts['bg']],
        ];
    }

    // 정렬
    $arr['alignment'] = [
        'horizontal' => $opts['h']    ?? Alignment::HORIZONTAL_LEFT,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => $opts['wrap'] ?? false,
        'indent'     => $opts['ind']  ?? 0,
    ];

    // 테두리
    if ($opts['bor'] ?? true) {
        $s = ['style' => Border::BORDER_THIN, 'color' => ['argb' => C_BOR]];
        $arr['borders'] = ['allBorders' => $s];
    }

    $ws->getStyle($range)->applyFromArray($arr);
}

// ── 워크북 생성 ───────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setTitle('단위 위험성평가서');
$ws = $spreadsheet->getActiveSheet();
$ws->setTitle('단위위험성평가서');
$ws->setShowGridlines(false);

// ── 열 너비 ───────────────────────────────────────────────────────
$colWidths = [
    'A'=>8.0,  'B'=>11.87,'C'=>18.67,'D'=>27.07,'E'=>13.2, 'F'=>13.2, 'G'=>27.07,
    'H'=>5.47, 'I'=>5.47, 'J'=>5.47, 'K'=>27.07,'L'=>5.47, 'M'=>5.47, 'N'=>5.47,
    'O'=>26.13,'P'=>5.47, 'Q'=>5.47, 'R'=>5.47, 'S'=>12.13,'T'=>8.93,
];
foreach ($colWidths as $col => $w) {
    $ws->getColumnDimension($col)->setWidth($w);
}

// ── 행1: 타이틀 ───────────────────────────────────────────────────
$ws->mergeCells('A1:T1');
$ws->setCellValue('A1', '단위 위험성평가서');
$ws->getRowDimension(1)->setRowHeight(33.95);
applyStyle($ws, 'A1:T1', [
    'bold'=>true, 'size'=>15, 'fg'=>C_HDR_FG, 'bg'=>C_HDR_BG,
    'h'=>Alignment::HORIZONTAL_CENTER, 'bor'=>false,
]);

// ── 행2: 평가서 기본정보 섹션 ─────────────────────────────────────
$ws->mergeCells('A2:T2');
$ws->setCellValue('A2', '▶ 평가서 기본정보');
$ws->getRowDimension(2)->setRowHeight(22.5);
applyStyle($ws, 'A2:T2', [
    'bold'=>true, 'size'=>10, 'fg'=>C_SEC_FG, 'bg'=>C_SEC_BG,
    'h'=>Alignment::HORIZONTAL_LEFT, 'ind'=>1, 'bor'=>false,
]);

// ── 행3: 라벨 ─────────────────────────────────────────────────────
$ws->getRowDimension(3)->setRowHeight(22.5);
// A3: DB ID 라벨 (단독)
$ws->setCellValue('A3', 'DB ID');
applyStyle($ws, 'A3', [
    'bold'=>true, 'size'=>7, 'fg'=>'FF375623', 'bg'=>'FFE2EFDA',
    'h'=>Alignment::HORIZONTAL_CENTER,
]);

// 나머지 라벨
$row3Labels = [
    ['평가서 코드',   'B3', 'C3'],
    ['단위평가서명 *','D3', 'H3'],
    ['공정명',        'I3', 'K3'],
    ['평가유형 *',    'L3', 'N3'],
    ['비고',          'O3', 'T3'],
];
foreach ($row3Labels as [$label, $start, $end]) {
    $ws->mergeCells("{$start}:{$end}");
    $ws->setCellValue($start, $label);
    applyStyle($ws, "{$start}:{$end}", [
        'bold'=>true, 'size'=>10, 'fg'=>C_LABEL_FG, 'bg'=>C_LABEL_BG,
        'h'=>Alignment::HORIZONTAL_CENTER,
    ]);
}

// unit_type 한글 변환
$unitTypeMap = [
    'target'     => '작업유형',
    'major_work' => '중대위험작업',
    'tool'       => '공구/장비',
    'env'        => '작업환경',
];
$unitTypeLabel = $unitTypeMap[$header['unit_type']] ?? $header['unit_type'];

// ── 행4: 값 ───────────────────────────────────────────────────────
$ws->getRowDimension(4)->setRowHeight(22.5);

// A4: unit_ra_id 자동입력 (연두색)
$ws->setCellValue('A4', $unitRaId);
applyStyle($ws, 'A4', [
    'size'=>9, 'fg'=>'FF375623', 'bg'=>'FFE2EFDA',
    'h'=>Alignment::HORIZONTAL_CENTER, 'ind'=>0,
]);

// B4~ 나머지 값
$row4Values = [
    ['B4','C4', $header['unit_code'],    false],
    ['D4','H4', $header['unit_title'],   true],
    ['I4','K4', $header['process_name'], false],
    ['L4','N4', $unitTypeLabel,          true],
    ['O4','T4', $header['remark'],       false],
];
foreach ($row4Values as [$start, $end, $val, $req]) {
    $ws->mergeCells("{$start}:{$end}");
    $ws->setCellValue($start, $val ?? '');
    applyStyle($ws, "{$start}:{$end}", [
        'size'=>10, 'bg'=> $req ? C_REQ_BG : C_WHITE,
        'h'=>Alignment::HORIZONTAL_LEFT, 'ind'=>1,
    ]);
}

// ── 행5: 라벨 ─────────────────────────────────────────────────────
$ws->getRowDimension(5)->setRowHeight(22.5);
$row5Labels = [
    ['작성자',   'A5','C5'],
    ['작성일',   'D5','F5'],
    ['수정자',   'G5','I5'],
    ['수정일',   'J5','L5'],
    ['사용여부', 'M5','N5'],
    ['정렬순서', 'O5','P5'],
    ['평가자',   'Q5','T5'],
];
foreach ($row5Labels as [$label, $start, $end]) {
    $ws->mergeCells("{$start}:{$end}");
    $ws->setCellValue($start, $label);
    applyStyle($ws, "{$start}:{$end}", [
        'bold'=>true, 'size'=>10, 'fg'=>C_LABEL_FG, 'bg'=>C_LABEL_BG,
        'h'=>Alignment::HORIZONTAL_CENTER,
    ]);
}

// ── 행6: 값 ───────────────────────────────────────────────────────
$ws->getRowDimension(6)->setRowHeight(22.5);
$createdAt = '';
if (!empty($header['created_at']) && $header['created_at'] !== '0000-00-00' && $header['created_at'] !== '0000-00-00 00:00:00') {
    $ts = strtotime($header['created_at']);
    if ($ts && $ts > 0) $createdAt = date('Y-m-d', $ts);
}
$updatedAt = '';
if (!empty($header['updated_at']) && $header['updated_at'] !== '0000-00-00' && $header['updated_at'] !== '0000-00-00 00:00:00') {
    $ts = strtotime($header['updated_at']);
    if ($ts && $ts > 0) $updatedAt = date('Y-m-d', $ts);
}
$row6Values = [
    ['A6','C6', $header['created_by'] ?? ''],
    ['D6','F6', $createdAt],
    ['G6','I6', $header['updated_by'] ?? ''],
    ['J6','L6', $updatedAt],
    ['M6','N6', $header['use_yn']     ?? 'Y'],
    ['O6','P6', $header['sort_no']    ?? '0'],
    ['Q6','T6', $header['evaluator_name'] ?? ''],
];
foreach ($row6Values as [$start, $end, $val]) {
    $ws->mergeCells("{$start}:{$end}");
    $ws->setCellValue($start, $val);
    applyStyle($ws, "{$start}:{$end}", [
        'size'=>10, 'bg'=>C_WHITE,
        'h'=>Alignment::HORIZONTAL_LEFT, 'ind'=>1,
    ]);
}

// ── 행7: 위험성평가 항목 섹션 ─────────────────────────────────────
$ws->mergeCells('A7:T7');
$ws->setCellValue('A7', '▶ 위험성평가 항목');
$ws->getRowDimension(7)->setRowHeight(22.5);
applyStyle($ws, 'A7:T7', [
    'bold'=>true, 'size'=>10, 'fg'=>C_SEC_FG, 'bg'=>C_SEC_BG,
    'h'=>Alignment::HORIZONTAL_LEFT, 'ind'=>1, 'bor'=>false,
]);

// ── 행8: 컬럼 헤더 ────────────────────────────────────────────────
$ws->getRowDimension(8)->setRowHeight(30.75);
$colHeaders = [
    'A'=>'No',            'B'=>'세부작업코드',    'C'=>'세부작업명 *',
    'D'=>'유해·위험요인 *','E'=>'재해발생형태',    'F'=>'재해결과',
    'G'=>'원인/위험상황', 'H'=>'가능성',           'I'=>'중대성',
    'J'=>'위험도',        'K'=>'현재 안전보건조치','L'=>"조치후\n가능성",
    'M'=>"조치후\n중대성",'N'=>"조치후\n위험도",   'O'=>'추가 개선대책',
    'P'=>"개선후\n가능성",'Q'=>"개선후\n중대성",   'R'=>"개선후\n위험도",
    'S'=>'개선일자', 'T'=>'비고',
];
$size9Cols = ['H','I','J'];
$size8Cols = ['L','M','N','P','Q','R'];
foreach ($colHeaders as $col => $label) {
    $ws->setCellValue("{$col}8", $label);
    $fontSize = in_array($col, $size9Cols) ? 9 : (in_array($col, $size8Cols) ? 8 : 10);
    applyStyle($ws, "{$col}8", [
        'bold'=>true,
        'size'=> $fontSize,
        'fg'=>C_COL_FG, 'bg'=>C_COL_BG,
        'h'=>Alignment::HORIZONTAL_CENTER, 'wrap'=>true,
    ]);
}

// ── 행9~: 항목 데이터 ─────────────────────────────────────────────
$centerCols  = ['A','H','I','J','L','M','N','P','Q','R'];
$reqCols     = ['C','D'];
$totalRows   = max(count($items), 15); // 최소 15행

for ($i = 0; $i < $totalRows; $i++) {
    $r    = 9 + $i;
    $odd  = ($i % 2 === 0);
    $item = $items[$i] ?? null;
    $ws->getRowDimension($r)->setRowHeight(48);

    $rowData = [
        'A' => $item ? $item['sort_no']                 : ($i + 1),
        'B' => $item ? ($item['task_code']          ?? '') : '',
        'C' => $item ? ($item['task_name']           ?? '') : '',
        'D' => $item ? ($item['hazard_name']         ?? '') : '',
        'E' => $item ? ($item['accident_type']       ?? '') : '',
        'F' => $item ? ($item['injury_result']       ?? '') : '',
        'G' => $item ? ($item['cause_text']          ?? '') : '',
        'H' => $item ? ($item['likelihood_before']   ?? '') : '',
        'I' => $item ? ($item['severity_before']     ?? '') : '',
        'J' => "=H{$r}*I{$r}",
        'K' => $item ? ($item['current_control_text']      ?? '') : '',
        'L' => $item ? ($item['likelihood_current'] ?? '') : '',
        'M' => $item ? ($item['severity_current']   ?? '') : '',
        'N' => "=L{$r}*M{$r}",
        'O' => $item ? ($item['additional_control_text']   ?? '') : '',
        'P' => $item ? ($item['likelihood_after']   ?? '') : '',
        'Q' => $item ? ($item['severity_after']     ?? '') : '',
        'R' => "=P{$r}*Q{$r}",
        'S' => $item ? (function($d) {
    if (empty($d) || $d === '0000-00-00') return '';
    $ts = strtotime($d);
    return ($ts && $ts > 0) ? date('Y-m-d', $ts) : '';
})($item['improvement_due_date'] ?? '') : '',
        'T' => $item ? ($item['remark']              ?? '') : '',
        
    ];

    foreach ($rowData as $col => $val) {
        $ws->setCellValue("{$col}{$r}", $val);
        $isCenter = in_array($col, $centerCols);
        $isReq    = in_array($col, $reqCols);
        $bg = $isReq ? C_REQ_BG : ($odd ? C_ODD_BG : C_WHITE);
        applyStyle($ws, "{$col}{$r}", [
            'size' => 10,
            'bg'   => $bg,
            'h'    => $isCenter
                        ? Alignment::HORIZONTAL_CENTER
                        : Alignment::HORIZONTAL_LEFT,
            'wrap' => true,
            'ind'  => $isCenter ? 0 : 1,
        ]);
    }
}

// ── G열(원인) 글자크기 9pt ────────────────────────────────────────────
$ws->getStyle('G9:G23')->getFont()->setSize(9);

// ── 위험도 조건부 서식 ────────────────────────────────────────────────

$fillGreen  = ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFC6EFCE']];
$fillYellow = ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFEB9C']];
$fillRed    = ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFCE4D6']];

// 위험도(J), 조치후위험도(N), 개선후위험도(R): 모두 1이상만 색칠
$endRow = 8 + $totalRows;
foreach (['J','N','R'] as $col) {
    $c1 = new Conditional(); $c1->setConditionType(Conditional::CONDITION_CELLIS);
    $c1->setOperatorType(Conditional::OPERATOR_BETWEEN);
    $c1->addCondition('1'); $c1->addCondition('5');
    $c1->getStyle()->applyFromArray(['fill'=>$fillGreen]);

    $c2 = new Conditional(); $c2->setConditionType(Conditional::CONDITION_CELLIS);
    $c2->setOperatorType(Conditional::OPERATOR_BETWEEN);
    $c2->addCondition('6'); $c2->addCondition('11');
    $c2->getStyle()->applyFromArray(['fill'=>$fillYellow]);

    $c3 = new Conditional(); $c3->setConditionType(Conditional::CONDITION_CELLIS);
    $c3->setOperatorType(Conditional::OPERATOR_GREATERTHANOREQUAL);
    $c3->addCondition('12');
    $c3->getStyle()->applyFromArray(['fill'=>$fillRed]);

    $ws->getStyle("{$col}9:{$col}{$endRow}")->setConditionalStyles([$c1,$c2,$c3]);
}

// ── 범례 ──────────────────────────────────────────────────────────
$lr  = 9 + $totalRows;
$lr2 = $lr + 1;

$ws->mergeCells("A{$lr}:T{$lr}");
$ws->setCellValue("A{$lr}", '【 위험도 범례 】  위험도 = 가능성 × 중대성');
$ws->getRowDimension($lr)->setRowHeight(23.25);
applyStyle($ws, "A{$lr}:T{$lr}", [
    'bold'=>true, 'size'=>10, 'fg'=>C_HDR_FG, 'bg'=>C_HDR_BG,
    'h'=>Alignment::HORIZONTAL_LEFT, 'ind'=>1, 'bor'=>false,
]);

$ws->getRowDimension($lr2)->setRowHeight(35.1);
$legends = [
    ['A', 'G', 'FFFCE4D6', '■  높음 (12 이상)  —  즉시 개선 필요'],
    ['H', 'N', 'FFFFEB9C', '■  중간 (6~11)  —  단기 개선 필요'],
    ['O', 'T', 'FFC6EFCE', '■  낮음 (1~5)  —  지속 관리'],
];
foreach ($legends as [$sc, $ec, $bg, $lbl]) {
    $ws->mergeCells("{$sc}{$lr2}:{$ec}{$lr2}");
    $ws->setCellValue("{$sc}{$lr2}", $lbl);
    applyStyle($ws, "{$sc}{$lr2}:{$ec}{$lr2}", [
        'bold'=>true, 'size'=>10, 'bg'=>$bg,
        'h'=>Alignment::HORIZONTAL_CENTER,
    ]);
}

// ── 인쇄 설정 ────────────────────────────────────────────────────────
$ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$ws->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
$ws->getPageSetup()->setFitToPage(true);
$ws->getPageSetup()->setFitToWidth(0);
$ws->getPageSetup()->setFitToHeight(0);
$ws->getPageSetup()->setScale(55);
$ws->getPageMargins()->setTop(0.58);
$ws->getPageMargins()->setBottom(0);
$ws->getPageMargins()->setLeft(0);
$ws->getPageMargins()->setRight(0);
$ws->getPageMargins()->setHeader(0);
$ws->getPageMargins()->setFooter(0);
$ws->getPageSetup()->setHorizontalCentered(true);
$ws->getPageSetup()->setVerticalCentered(false);

// ── 다운로드 ──────────────────────────────────────────────────────
$fileName = '단위위험성평가_'
    . preg_replace('/[^\w가-힣]/u', '_', $header['unit_title'])
    . '.xlsx';

ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
