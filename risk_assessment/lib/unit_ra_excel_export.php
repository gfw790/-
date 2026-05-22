<?php

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/hazard_4m.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

function unit_ra_excel_report_title(array $header): string
{
    return match ((string)($header['report_title_type'] ?? 'regular')) {
        'occasional' => '수시 위험성평가서',
        default => '정기 위험성평가서',
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

function unit_ra_excel_write_guide_sheet(Worksheet $sheet): void
{
    $sheet->setTitle('작성안내');
    $sheet->setShowGridlines(false);

    foreach ([
        'A' => 24,
        'B' => 58,
        'C' => 30,
    ] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->mergeCells('A1:C1');
    $sheet->setCellValue('A1', '단위 위험성평가서 - 컬럼 작성 안내');
    unit_ra_excel_style($sheet, 'A1:C1', [
        'fill' => 'FF1F4E79',
        'font_color' => 'FFFFFFFF',
        'bold' => true,
        'size' => 13,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $sheet->fromArray(['컬럼명 (DB)', '설명', '예시값'], null, 'A2');
    unit_ra_excel_style($sheet, 'A2:C2', [
        'fill' => 'FFB6744E',
        'bold' => true,
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ]);

    $rows = [
        ['[평가서 기본정보]', '', ''],
        ['unit_code', '평가서 고유 코드입니다. 중복되지 않게 작성합니다.', 'E-001'],
        ['unit_title *', '단위 위험성평가서명을 입력합니다.', '분진 제거 작업'],
        ['unit_type *', '평가유형을 선택합니다. 업로드 시 허용값만 사용됩니다.', 'target'],
        ['process_name', '공정명 또는 작업 공정을 입력합니다.', '분진 제거 공정'],
        ['use_yn', '사용 여부입니다. 비워두면 기본값 Y로 저장됩니다.', 'Y'],
        ['sort_no', '목록 정렬 순서입니다.', '10'],
        ['created_by', '작성자 이름입니다.', '홍길동'],
        ['remark', '평가서 비고를 입력합니다.', '정기점검 연계'],
        ['', '', ''],
        ['[위험성평가 항목]', '', ''],
        ['task_code', '세부작업코드입니다. 같은 평가서 안에서 관리용으로 사용합니다.', 'DUST-01'],
        ['task_name *', '세부작업명을 입력합니다.', '집진기 주변 청소'],
        ['hazard_name *', '유해·위험요인을 입력합니다.', '분진 비산'],
        ['hazard_4m', '4M분류를 입력합니다. 인적/기계적/관리적/물질·환경적/검토필요 중 하나를 사용합니다.', '물질·환경적'],
        ['accident_type', '재해발생형태를 입력합니다.', '비래'],
        ['injury_result', '재해결과를 입력합니다.', '안구 자극'],
        ['cause_text', '원인/위험상황을 구체적으로 입력합니다.', '분진이 다량 비산되어 호흡기와 눈에 노출됨'],
        ['current_control_text', '현재 안전보건조치를 입력합니다.', '국소배기장치 설치 및 보안경 착용'],
        ['additional_control_text', '추가 개선대책을 입력합니다.', '습식 청소 전환 및 차단막 설치'],
        ['likelihood_before', '개선전 가능성(L)입니다. 1~5 범위를 권장합니다.', '4'],
        ['severity_before', '개선전 중대성(S)입니다. 1~5 범위를 권장합니다.', '4'],
        ['risk_score_before', '개선전 위험성(R)입니다. 엑셀에서 L x S 수식으로 계산됩니다.', '16'],
        ['likelihood_current', '현재 가능성(L)입니다. 1~5 범위를 권장합니다.', '3'],
        ['severity_current', '현재 중대성(S)입니다. 1~5 범위를 권장합니다.', '3'],
        ['risk_score_current', '현재 위험성(R)입니다. 엑셀에서 L x S 수식으로 계산됩니다.', '9'],
        ['likelihood_after', '개선후 가능성(L)입니다. 1~5 범위를 권장합니다.', '2'],
        ['severity_after', '개선후 중대성(S)입니다. 1~5 범위를 권장합니다.', '2'],
        ['risk_score_after', '개선후 위험성(R)입니다. 엑셀에서 L x S 수식으로 계산됩니다.', '4'],
        ['required_ppe', '현재 양식에는 별도 컬럼이 없으므로 필요 시 비고에 작성합니다.', '방진마스크, 보안경'],
        ['improvement_due_date', '개선 완료 예정일을 입력합니다.', '2026-05-31'],
        ['remark', '항목별 비고를 입력합니다.', '분기 내 완료'],
        ['', '', ''],
        ['[주의사항]', '', ''],
        ['1', '평가서 코드(unit_code)는 중복되지 않게 관리합니다.', ''],
        ['2', '시트명은 단위위험성평가서로 유지하는 것을 권장합니다.', ''],
        ['3', '평가유형은 target / major_work / tool / env 중 하나를 사용합니다.', ''],
        ['4', '가능성, 중대성은 숫자로 입력하며 위험성은 수식으로 자동 계산됩니다.', ''],
        ['5', '업로드 시 같은 코드의 기존 데이터와 중복 여부를 꼭 확인합니다.', ''],
    ];

    $row = 3;
    foreach ($rows as [$a, $b, $c]) {
        $sheet->fromArray([$a, $b, $c], null, "A{$row}");

        if ($a !== '' && str_starts_with($a, '[')) {
            unit_ra_excel_style($sheet, "A{$row}:C{$row}", [
                'fill' => 'FFD9EAF7',
                'bold' => true,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ]);
        } elseif ($a === '' && $b === '' && $c === '') {
            unit_ra_excel_style($sheet, "A{$row}:C{$row}", ['fill' => 'FFFFFFFF']);
        } else {
            unit_ra_excel_style($sheet, "A{$row}:A{$row}", [
                'fill' => 'FFF2F7FB',
                'bold' => true,
            ]);
            unit_ra_excel_style($sheet, "B{$row}:C{$row}", ['fill' => 'FFFFFFFF']);
        }

        $sheet->getRowDimension($row)->setRowHeight(24);
        $row++;
    }

    $sheet->freezePane('A3');
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

    $sheet->setTitle('단위위험성평가서');
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
    $sheet->setCellValue('A1', unit_ra_excel_report_title($header));
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

    foreach ([
        'B3:C3',
        'B4:C4',
        'D3:H3',
        'D4:H4',
        'I3:K3',
        'I4:K4',
        'L3:N3',
        'L4:N4',
        'O3:U3',
        'O4:U4',
        'A5:C5',
        'A6:C6',
        'D5:F5',
        'D6:F6',
        'G5:I5',
        'G6:I6',
        'J5:L5',
        'J6:L6',
        'M5:N5',
        'M6:N6',
        'O5:P5',
        'O6:P6',
        'Q5:U5',
        'Q6:U6',
    ] as $range) {
        $sheet->mergeCells($range);
    }

    $sheet->setCellValue('A3', 'DB ID');
    $sheet->setCellValue('A4', (string)($header['unit_ra_id'] ?? ''));
    $sheet->setCellValue('B3', '평가서 코드');
    $sheet->setCellValue('B4', (string)($header['unit_code'] ?? ''));
    $sheet->setCellValue('D3', '단위평가서명 *');
    $sheet->setCellValue('D4', (string)($header['unit_title'] ?? ''));
    $sheet->setCellValue('I3', '공정명');
    $sheet->setCellValue('I4', (string)($header['process_name'] ?? ''));
    $sheet->setCellValue('L3', '평가유형 *');
    $sheet->setCellValue('L4', unit_ra_excel_unit_type_label($header['unit_type'] ?? ''));
    $sheet->setCellValue('O3', '비고');
    $sheet->setCellValue('O4', (string)($header['remark'] ?? ''));

    $sheet->setCellValue('A5', '작성자');
    $sheet->setCellValue('A6', (string)($header['created_by'] ?? ''));
    $sheet->setCellValue('D5', '작성일');
    $sheet->setCellValue('D6', unit_ra_excel_date($header['created_at'] ?? null));
    $sheet->setCellValue('G5', '수정자');
    $sheet->setCellValue('G6', (string)($header['updated_by'] ?? $header['created_by'] ?? ''));
    $sheet->setCellValue('J5', '수정일');
    $sheet->setCellValue('J6', unit_ra_excel_date($header['updated_at'] ?? null));
    $sheet->setCellValue('M5', '사용여부');
    $sheet->setCellValue('M6', (string)($header['use_yn'] ?? 'Y'));
    $sheet->setCellValue('O5', '정렬순서');
    $sheet->setCellValue('O6', (string)($header['sort_no'] ?? ''));
    $sheet->setCellValue('Q5', '평가자');
    $sheet->setCellValue('Q6', (string)($header['evaluator_name'] ?? ''));

    foreach (['A3', 'B3:C3', 'D3:H3', 'I3:K3', 'L3:N3', 'O3:U3', 'A5:C5', 'D5:F5', 'G5:I5', 'J5:L5', 'M5:N5', 'O5:P5', 'Q5:U5'] as $range) {
        unit_ra_excel_style($sheet, $range, [
            'fill' => 'FFD9EAF7',
            'bold' => true,
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ]);
    }
    foreach (['A4', 'B4:C4', 'D4:H4', 'I4:K4', 'L4:N4', 'O4:U4', 'A6:C6', 'D6:F6', 'G6:I6', 'J6:L6', 'M6:N6', 'O6:P6', 'Q6:U6'] as $range) {
        unit_ra_excel_style($sheet, $range, ['fill' => 'FFFFFFFF']);
    }

    $row = 7;
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
        'B' => '세부작업코드',
        'C' => '세부작업명 *',
        'D' => '유해·위험요인 *',
        'E' => '4M분류',
        'F' => '재해발생형태',
        'G' => '재해결과',
        'H' => '원인/위험상황',
        'I' => '개선전 가능성(L)',
        'J' => '개선전 중대성(S)',
        'K' => '개선전 위험성(R)',
        'L' => '현재 안전보건조치',
        'M' => '현재 가능성(L)',
        'N' => '현재 중대성(S)',
        'O' => '현재 위험성(R)',
        'P' => '추가 개선대책',
        'Q' => '개선후 가능성(L)',
        'R' => '개선후 중대성(S)',
        'S' => '개선후 위험성(R)',
        'T' => '개선일자',
        'U' => '비고',
    ];
    foreach ($headers as $column => $label) {
        $sheet->setCellValue("{$column}{$row}", $label);
    }
    unit_ra_excel_style($sheet, "A{$row}:U{$row}", [
        'fill' => 'FFD9EAF7',
        'font_color' => 'FF000000',
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
    $sheet->getRowDimension($row)->setRowHeight(34);
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
        $sheet->setCellValue("K{$row}", "=IF(OR(I{$row}=\"\",J{$row}=\"\"),\"\",I{$row}*J{$row})");
        $sheet->setCellValue("L{$row}", $item['current_control_text'] ?? '');
        $sheet->setCellValue("M{$row}", $item['likelihood_current'] ?? '');
        $sheet->setCellValue("N{$row}", $item['severity_current'] ?? '');
        $sheet->setCellValue("O{$row}", "=IF(OR(M{$row}=\"\",N{$row}=\"\"),\"\",M{$row}*N{$row})");
        $sheet->setCellValue("P{$row}", $item['additional_control_text'] ?? '');
        $sheet->setCellValue("Q{$row}", $item['likelihood_after'] ?? '');
        $sheet->setCellValue("R{$row}", $item['severity_after'] ?? '');
        $sheet->setCellValue("S{$row}", "=IF(OR(Q{$row}=\"\",R{$row}=\"\"),\"\",Q{$row}*R{$row})");
        $sheet->setCellValue("T{$row}", unit_ra_excel_date($item['improvement_due_date'] ?? null));
        $sheet->setCellValue("U{$row}", $item['remark'] ?? '');

        $fill = 'FFFFFFFF';
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

    $guideSheet = $spreadsheet->getSheetByName('작성안내');
    if ($guideSheet === null) {
        $guideSheet = new Worksheet($spreadsheet, '작성안내');
        $spreadsheet->addSheet($guideSheet);
    }
    unit_ra_excel_write_guide_sheet($guideSheet);

    $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

    return $spreadsheet;
}

function unit_ra_excel_binary(array $header, array $items): string
{
    $spreadsheet = unit_ra_excel_spreadsheet($header, $items);
    ob_start();
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    return (string)ob_get_clean();
}
