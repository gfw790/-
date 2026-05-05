<?php

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/hazard_4m.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function unit_ra_excel_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/risk_assessment/'));
    $dir = rtrim(str_replace('/lib', '', dirname($scriptName)), '/');

    return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
}

function unit_ra_excel_upload_url(): string
{
    return unit_ra_excel_base_url() . '/upload.html';
}

function unit_ra_excel_upload_api_url(): string
{
    return unit_ra_excel_base_url() . '/unit_ra_excel_upload.php';
}

function unit_ra_excel_template_path(): string
{
    return dirname(__DIR__) . '/templates/unit_ra_excel_template_v3.xlsm';
}

function unit_ra_excel_fetch(PDO $pdo, int $unitRaId): array
{
    $stmtH = $pdo->prepare("SELECT * FROM unit_ra_header WHERE unit_ra_id = :id");
    $stmtH->execute([':id' => $unitRaId]);
    $header = $stmtH->fetch();
    if (!$header) {
        throw new RuntimeException('해당 위험성평가서를 찾을 수 없습니다.');
    }

    ensure_unit_ra_item_hazard_4m_column($pdo);

    $stmtI = $pdo->prepare("
        SELECT *
        FROM unit_ra_item
        WHERE unit_ra_id = :id
          AND use_yn = 'Y'
        ORDER BY sort_no ASC, item_id ASC
    ");
    $stmtI->execute([':id' => $unitRaId]);
    $items = array_map(
        static fn(array $item): array => hazard_4m_enrich($item, true),
        $stmtI->fetchAll()
    );

    return [$header, $items];
}

function unit_ra_excel_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '';
    }

    $time = strtotime($value);

    return $time ? date('Y-m-d', $time) : $value;
}

function unit_ra_excel_style(Worksheet $sheet, string $range, array $options = []): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'name' => 'Malgun Gothic',
            'size' => $options['size'] ?? 10,
            'bold' => $options['bold'] ?? false,
            'color' => ['argb' => $options['font_color'] ?? 'FF000000'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => $options['fill'] ?? 'FFFFFFFF'],
        ],
        'alignment' => [
            'horizontal' => $options['horizontal'] ?? Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => $options['wrap'] ?? true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FFD3DCE6'],
            ],
        ],
    ]);
}

function unit_ra_excel_file_name(array $header): string
{
    $fileCode = trim((string)($header['unit_code'] ?? ''));
    $fileTitle = trim((string)($header['unit_title'] ?? 'sheet'));
    $fileBase = ($fileCode !== '' ? $fileCode . '_' : '') . $fileTitle;

    return preg_replace('/[^\w가-힣-]+/u', '_', $fileBase) . '.xlsm';
}

function unit_ra_excel_category_folder(array $header): string
{
    return match ((string)($header['unit_type'] ?? '')) {
        'target' => '작업대상',
        'major_work' => '중대위험작업',
        'tool' => '공구설비',
        'env' => '작업환경',
        default => '기타',
    };
}

function unit_ra_excel_unit_type_label(?string $unitType): string
{
    return match ((string)$unitType) {
        'target' => '작업유형',
        'major_work' => '중대위험작업',
        'tool' => '공구/장비',
        'env' => '작업환경',
        default => (string)$unitType,
    };
}

function unit_ra_excel_conditional(string $operator, array $conditions, string $fill, string $fontColor = 'FF000000'): Conditional
{
    $conditional = new Conditional();
    $conditional->setConditionType(Conditional::CONDITION_CELLIS);
    $conditional->setOperatorType($operator);
    $conditional->setConditions($conditions);
    $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
    $conditional->getStyle()->getFill()->getStartColor()->setARGB($fill);
    $conditional->getStyle()->getFont()->getColor()->setARGB($fontColor);

    return $conditional;
}

function unit_ra_excel_expression_conditional(string $formula, string $fill, string $fontColor = 'FF000000'): Conditional
{
    $conditional = new Conditional();
    $conditional->setConditionType(Conditional::CONDITION_EXPRESSION);
    $conditional->setConditions([$formula]);
    $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
    $conditional->getStyle()->getFill()->getStartColor()->setARGB($fill);
    $conditional->getStyle()->getFont()->getColor()->setARGB($fontColor);

    return $conditional;
}

function unit_ra_excel_apply_conditionals(Worksheet $sheet, string $range, array $conditionals): void
{
    $sheet->getStyle($range)->setConditionalStyles($conditionals);
}

function unit_ra_excel_spreadsheet(array $header, array $items): Spreadsheet
{
    $templatePath = unit_ra_excel_template_path();
    $spreadsheet = is_file($templatePath)
        ? IOFactory::load($templatePath)
        : new Spreadsheet();

    $sheet = $spreadsheet->getSheetByName('RiskAssessment');
    if ($sheet === null) {
        $sheet = $spreadsheet->getActiveSheet();
    }

    $sheet->setTitle('정기위험성평가');
    $sheet->setShowGridlines(false);

    $widths = [
        'A' => 6,
        'B' => 14,
        'C' => 22,
        'D' => 26,
        'E' => 12,
        'F' => 14,
        'G' => 14,
        'H' => 30,
        'I' => 9,
        'J' => 9,
        'K' => 9,
        'L' => 26,
        'M' => 9,
        'N' => 9,
        'O' => 9,
        'P' => 26,
        'Q' => 9,
        'R' => 9,
        'S' => 9,
        'T' => 13,
        'U' => 14,
    ];
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->mergeCells('A1:U1');
    $sheet->setCellValue('A1', '정기 위험성평가서');
    unit_ra_excel_style($sheet, 'A1:U1', [
        'fill' => 'FF1F4E79',
        'font_color' => 'FFFFFFFF',
        'bold' => true,
        'size' => 15,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $sheet->mergeCells('A2:U2');
    $sheet->setCellValue('A2', '▶ 평가서 기본정보');
    unit_ra_excel_style($sheet, 'A2:U2', [
        'fill' => 'FFB6744E',
        'font_color' => 'FF000000',
        'bold' => true,
    ]);

    $metaRows = [
        ['위험성평가번호', (string)($header['unit_code'] ?? ''), '공정명', (string)($header['process_name'] ?? '')],
        ['평가서명', (string)($header['unit_title'] ?? ''), '평가유형', unit_ra_excel_unit_type_label($header['unit_type'] ?? '')],
        ['평가자', (string)($header['evaluator_name'] ?? ''), '등록일', unit_ra_excel_date($header['created_at'] ?? null)],
        ['수정일', unit_ra_excel_date($header['updated_at'] ?? null), '비고', (string)($header['remark'] ?? '')],
    ];

    $row = 3;
    foreach ($metaRows as [$label1, $value1, $label2, $value2]) {
        $sheet->mergeCells("A{$row}:C{$row}");
        $sheet->mergeCells("D{$row}:K{$row}");
        $sheet->mergeCells("L{$row}:N{$row}");
        $sheet->mergeCells("O{$row}:U{$row}");
        $sheet->setCellValue("A{$row}", $label1);
        $sheet->setCellValue("D{$row}", $value1);
        $sheet->setCellValue("L{$row}", $label2);
        $sheet->setCellValue("O{$row}", $value2);
        unit_ra_excel_style($sheet, "A{$row}:C{$row}", [
            'fill' => 'FFD9EAF7',
            'bold' => true,
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ]);
        unit_ra_excel_style($sheet, "D{$row}:K{$row}", ['fill' => 'FFFFFFFF']);
        unit_ra_excel_style($sheet, "L{$row}:N{$row}", [
            'fill' => 'FFD9EAF7',
            'bold' => true,
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ]);
        unit_ra_excel_style($sheet, "O{$row}:U{$row}", ['fill' => 'FFFFFFFF']);
        $row++;
    }

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '▶ 위험성평가 항목');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFB6744E',
        'font_color' => 'FF000000',
        'bold' => true,
    ]);
    $row++;

    $headers = [
        'A' => 'No',
        'B' => "세부작업\n코드",
        'C' => '세부작업명',
        'D' => '유해·위험요인',
        'E' => '4M분류',
        'F' => '재해유형',
        'G' => '상해결과',
        'H' => '원인 및 위험상황',
        'I' => "개선전\n가능성(L)",
        'J' => "개선전\n중대성(S)",
        'K' => "개선전\n위험성(R)",
        'L' => '현재 관리대책',
        'M' => "현재\n가능성(L)",
        'N' => "현재\n중대성(S)",
        'O' => "현재\n위험성(R)",
        'P' => '추가 개선대책',
        'Q' => "개선후\n가능성(L)",
        'R' => "개선후\n중대성(S)",
        'S' => "개선후\n위험성(R)",
        'T' => '개선기한',
        'U' => '비고',
    ];
    foreach ($headers as $column => $label) {
        $sheet->setCellValue("{$column}{$row}", $label);
    }
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FF4472C4',
        'font_color' => 'FFFFFFFF',
        'bold' => true,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    unit_ra_excel_style($sheet, "I{$row}:K{$row}", [
        'fill' => 'FFFCE4D6',
        'font_color' => 'FF7F3121',
        'bold' => true,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    unit_ra_excel_style($sheet, "L{$row}:O{$row}", [
        'fill' => 'FFDDEBF7',
        'font_color' => 'FF1F4E79',
        'bold' => true,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    unit_ra_excel_style($sheet, "P{$row}:S{$row}", [
        'fill' => 'FFE2F0D9',
        'font_color' => 'FF385723',
        'bold' => true,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    $sheet->getRowDimension($row)->setRowHeight(38);
    $row++;

    $totalRows = max(count($items), 15);
    for ($index = 0; $index < $totalRows; $index++, $row++) {
        $item = $items[$index] ?? null;
        $sheet->setCellValue("A{$row}", $item['sort_no'] ?? ($index + 1));
        $sheet->setCellValue("B{$row}", $item['task_code'] ?? '');
        $sheet->setCellValue("C{$row}", $item['task_name'] ?? '');
        $sheet->setCellValue("D{$row}", $item['hazard_name'] ?? '');
        $sheet->setCellValue("E{$row}", $item['hazard_4m_label'] ?? '');
        $sheet->setCellValue("F{$row}", $item['accident_type'] ?? '');
        $sheet->setCellValue("G{$row}", $item['injury_result'] ?? '');
        $sheet->setCellValue("H{$row}", $item['cause_text'] ?? '');
        $sheet->setCellValue("I{$row}", $item['likelihood_before'] ?? '');
        $sheet->setCellValue("J{$row}", $item['severity_before'] ?? '');
        $sheet->setCellValue("K{$row}", $item['risk_score_before'] ?? '');
        $sheet->setCellValue("L{$row}", $item['current_control_text'] ?? '');
        $sheet->setCellValue("M{$row}", $item['likelihood_current'] ?? '');
        $sheet->setCellValue("N{$row}", $item['severity_current'] ?? '');
        $sheet->setCellValue("O{$row}", $item['risk_score_current'] ?? '');
        $sheet->setCellValue("P{$row}", $item['additional_control_text'] ?? '');
        $sheet->setCellValue("Q{$row}", $item['likelihood_after'] ?? '');
        $sheet->setCellValue("R{$row}", $item['severity_after'] ?? '');
        $sheet->setCellValue("S{$row}", $item['risk_score_after'] ?? '');
        $sheet->setCellValue("T{$row}", unit_ra_excel_date($item['improvement_due_date'] ?? null));
        $sheet->setCellValue("U{$row}", $item['remark'] ?? '');

        $fill = $index % 2 === 0 ? 'FFF8FBFF' : 'FFFFFFFF';
        unit_ra_excel_style($sheet, "A{$row}:U{$row}", ['fill' => $fill]);
        unit_ra_excel_style($sheet, "A{$row}:A{$row}", ['fill' => $fill, 'horizontal' => Alignment::HORIZONTAL_CENTER]);
        unit_ra_excel_style($sheet, "E{$row}:E{$row}", ['fill' => $fill, 'horizontal' => Alignment::HORIZONTAL_CENTER]);
        unit_ra_excel_style($sheet, "I{$row}:K{$row}", ['fill' => $fill, 'horizontal' => Alignment::HORIZONTAL_CENTER]);
        unit_ra_excel_style($sheet, "M{$row}:O{$row}", ['fill' => $fill, 'horizontal' => Alignment::HORIZONTAL_CENTER]);
        unit_ra_excel_style($sheet, "Q{$row}:T{$row}", ['fill' => $fill, 'horizontal' => Alignment::HORIZONTAL_CENTER]);
        $sheet->getRowDimension($row)->setRowHeight(40);
    }

    $dropdownStartRow = 9;
    $dropdownEndRow = 8 + $totalRows;
    $dropdownFormula = '"인적,기계적,관리적,물질·환경적,검토필요"';
    for ($validationRow = $dropdownStartRow; $validationRow <= $dropdownEndRow; $validationRow++) {
        $validation = $sheet->getCell("E{$validationRow}")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle('입력 오류');
        $validation->setError('목록에 있는 4M분류만 선택할 수 있습니다.');
        $validation->setPromptTitle('4M분류 선택');
        $validation->setPrompt('인적, 기계적, 관리적, 물질·환경적, 검토필요 중 하나를 선택하세요.');
        $validation->setFormula1($dropdownFormula);
    }

    $levelConditionals = [
        unit_ra_excel_conditional(Conditional::OPERATOR_GREATERTHANOREQUAL, ['5'], 'FFE06666', 'FFFFFFFF'),
        unit_ra_excel_conditional(Conditional::OPERATOR_EQUAL, ['4'], 'FFF4B183'),
        unit_ra_excel_conditional(Conditional::OPERATOR_EQUAL, ['3'], 'FFFFEB9C'),
        unit_ra_excel_conditional(Conditional::OPERATOR_LESSTHANOREQUAL, ['2'], 'FFC6E0B4'),
    ];
    $scoreConditionals = [
        unit_ra_excel_conditional(Conditional::OPERATOR_GREATERTHANOREQUAL, ['12'], 'FFC00000', 'FFFFFFFF'),
        unit_ra_excel_conditional(Conditional::OPERATOR_GREATERTHANOREQUAL, ['6'], 'FFF4B183'),
        unit_ra_excel_conditional(Conditional::OPERATOR_GREATERTHANOREQUAL, ['3'], 'FFFFEB9C'),
        unit_ra_excel_conditional(Conditional::OPERATOR_LESSTHAN, ['3'], 'FFC6E0B4'),
    ];
    $fourMConditionals = [
        unit_ra_excel_expression_conditional('EXACT(E9,"인적")', 'FFD9EAF7'),
        unit_ra_excel_expression_conditional('EXACT(E9,"기계적")', 'FFE2F0D9'),
        unit_ra_excel_expression_conditional('EXACT(E9,"관리적")', 'FFFCE4D6'),
        unit_ra_excel_expression_conditional('OR(EXACT(E9,"물질·환경적"),EXACT(E9,"물질환경적"))', 'FFEDEDED'),
        unit_ra_excel_expression_conditional('EXACT(E9,"검토필요")', 'FFC00000', 'FFFFFFFF'),
    ];

    unit_ra_excel_apply_conditionals($sheet, "I{$dropdownStartRow}:J{$dropdownEndRow}", $levelConditionals);
    unit_ra_excel_apply_conditionals($sheet, "M{$dropdownStartRow}:N{$dropdownEndRow}", $levelConditionals);
    unit_ra_excel_apply_conditionals($sheet, "Q{$dropdownStartRow}:R{$dropdownEndRow}", $levelConditionals);
    unit_ra_excel_apply_conditionals($sheet, "K{$dropdownStartRow}:K{$dropdownEndRow}", $scoreConditionals);
    unit_ra_excel_apply_conditionals($sheet, "O{$dropdownStartRow}:O{$dropdownEndRow}", $scoreConditionals);
    unit_ra_excel_apply_conditionals($sheet, "S{$dropdownStartRow}:S{$dropdownEndRow}", $scoreConditionals);
    unit_ra_excel_apply_conditionals($sheet, "E{$dropdownStartRow}:E{$dropdownEndRow}", $fourMConditionals);

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '【 위험도 범례 】  위험도 = 가능성 × 중대성');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FF1F4E79',
        'font_color' => 'FFFFFFFF',
        'bold' => true,
        'size' => 10,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '■  높음 (12 이상)  -  즉시 개선 필요');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFD6A5A5',
        'bold' => true,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '■  보통 (6~11)  -  계획 후 개선');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFF4B183',
        'bold' => true,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '■  낮음 (3~5)  -  관리상태 유지');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFFFEB9C',
        'bold' => true,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '■  매우 낮음 (1~2)  -  일상 관리');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFC6E0B4',
        'bold' => true,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '4M분류: M1 인적 / M2 기계적 / M3 관리적 / M4 물질·환경적 / REVIEW 검토필요');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFF2F7FB',
        'bold' => true,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '조건부서식 안내: L/S 값과 위험성(R) 점수는 입력값에 따라 자동으로 색상이 바뀝니다.');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFF8FBFF',
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '업로드 실행 안내: Alt + F8에서 UploadRiskAssessment를 실행하면 현재 파일이 바로 업로드됩니다.');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFEAF2F8',
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    $sheet->getRowDimension($row)->setVisible(false);
    $row++;

    $sheet->mergeCells("A{$row}:U{$row}");
    $sheet->setCellValue("A{$row}", '업로드 기능은 화면 편집용이며 인쇄 시에는 표시되지 않습니다.');
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFEAF2F8',
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    $sheet->getRowDimension($row)->setVisible(false);

    $sheet->freezePane('A9');
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4);
    $sheet->getPageMargins()->setBottom(0.4);
    $sheet->getPageMargins()->setLeft(0.3);
    $sheet->getPageMargins()->setRight(0.3);

    $configSheet = $spreadsheet->getSheetByName('__config');
    if ($configSheet !== null) {
        $configSheet->setCellValue('A1', unit_ra_excel_upload_api_url());
        $configSheet->setCellValue('A2', unit_ra_excel_upload_url());
        $configSheet->setCellValue('A3', (string)($header['unit_ra_id'] ?? ''));
    }

    $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

    return $spreadsheet;
}

function unit_ra_excel_binary(array $header, array $items): string
{
    $spreadsheet = unit_ra_excel_spreadsheet($header, $items);
    ob_start();
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');

    return (string)ob_get_clean();
}
