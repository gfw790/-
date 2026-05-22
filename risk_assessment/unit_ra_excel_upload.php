<?php
// ================================================================
// unit_ra_excel_upload.php
// 단위 위험성평가서 엑셀 → MySQL 업로드
//
// 의존: PhpSpreadsheet (composer require phpoffice/phpspreadsheet)
//       db_config.php
// ================================================================

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function ensure_unit_ra_header_report_title_type(PDO $pdo): void
{
    $columnExists = (int)$pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unit_ra_header'
          AND COLUMN_NAME = 'report_title_type'
    ")->fetchColumn() > 0;

    if (!$columnExists) {
        $pdo->exec("
            ALTER TABLE unit_ra_header
            ADD COLUMN report_title_type VARCHAR(20) NOT NULL DEFAULT 'regular' AFTER unit_title
        ");
    }
}

// ── 응답 헬퍼 ────────────────────────────────────────────────────
function jsonResponse(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function findUploadWorksheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
    foreach (['정기위험성평가', '단위위험성평가서', 'RiskAssessment'] as $sheetName) {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if ($sheet !== null) {
            return $sheet;
        }
    }

    return $spreadsheet->getSheetCount() > 0 ? $spreadsheet->getSheet(0) : null;
}

// ── 요청 검증 ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'POST 요청만 허용됩니다.');
}

if (empty($_FILES['excel_file'])) {
    jsonResponse(false, '파일이 업로드되지 않았습니다.');
}

$file = $_FILES['excel_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, '파일 업로드 오류: ' . $file['error']);
}

// 확장자 검사
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) {
    jsonResponse(false, '.xlsx, .xls, .xlsm 파일만 업로드 가능합니다.');
}

// 파일 크기 제한 (10MB)
if ($file['size'] > 10 * 1024 * 1024) {
    jsonResponse(false, '파일 크기가 10MB를 초과합니다.');
}

// ── 엑셀 파싱 ────────────────────────────────────────────────────
try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
} catch (\Exception $e) {
    jsonResponse(false, '엑셀 파일을 읽을 수 없습니다: ' . $e->getMessage());
}

$ws = findUploadWorksheet($spreadsheet);
if ($ws === null) {
    jsonResponse(false, 'Worksheet not found. Please use the exported Excel template.');
}

// ── 헬퍼: 셀 값 가져오기 ─────────────────────────────────────────
function cellVal($ws, string $cell): ?string {
    $val = $ws->getCell($cell)->getCalculatedValue();
    if ($val === null || $val === '') return null;
    return trim((string)$val);
}

function cellInt($ws, string $cell): ?int {
    $val = cellVal($ws, $cell);
    return ($val !== null && is_numeric($val)) ? (int)$val : null;
}

function cellScore($ws, string $cell): ?int {
    // 위험도 = 가능성 x 중대성. 0이면 NULL로 처리 (DB CHECK 조건 대응)
    $val = cellVal($ws, $cell);
    if ($val === null || !is_numeric($val)) return null;
    $int = (int)$val;
    return $int > 0 ? $int : null;
}

function pairScore(?int $likelihood, ?int $severity): ?int {
    if ($likelihood === null || $severity === null) return null;
    return $likelihood * $severity;
}

function cellDate($ws, string $cell): ?string {
    // 엑셀 날짜 → Y-m-d 문자열 변환
    $cell_obj = $ws->getCell($cell);
    $val = $cell_obj->getValue();
    if ($val === null || $val === '') return null;

    // 0000-00-00 또는 빈값 처리
    if ($val === '0000-00-00' || $val === '0000-00-00 00:00:00') return null;

    // 엑셀 날짜 직렬번호인 경우 변환
    if (is_numeric($val)) {
        try {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    // 문자열로 입력된 경우 파싱
    $str = trim((string)$val);

    // YY-MM-DD 형식 (예: 25-04-11) → 20YY-MM-DD 로 변환
    if (preg_match('/^(\d{2})-(\d{2})-(\d{2})$/', $str, $m)) {
        $str = '20' . $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    // YY/MM/DD 형식 (예: 25/04/11)
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $str, $m)) {
        $str = '20' . $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    // YYYY/MM/DD 형식
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', $str, $m)) {
        $str = $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    $ts = strtotime($str);
    if ($ts === false || $ts <= 0) return null;
    return date('Y-m-d', $ts);
}

// ── 헤더 정보 읽기 ───────────────────────────────────────────────
// 양식 컬럼 매핑:
//   A1: 평가서 제목(정기/수시)
//   B4: unit_code, D4: unit_title, I4: process_name, L4: unit_type, O4: remark
//   A6: created_by, M6: use_yn, O6: sort_no, Q6: evaluator_name
$reportTitleCell = cellVal($ws, 'A1') ?? '';
$reportTitleType = str_contains($reportTitleCell, '수시') ? 'occasional' : 'regular';
$header = [
    'unit_code'      => cellVal($ws, 'B4'),
    'unit_title'     => cellVal($ws, 'D4'),
    'report_title_type' => $reportTitleType,
    'process_name'   => cellVal($ws, 'I4'),
    'unit_type'      => cellVal($ws, 'L4') ?? 'major_work',
    'remark'         => cellVal($ws, 'O4'),
    'created_by'     => cellVal($ws, 'A6') ?? 'excel_upload',
    'updated_by'     => 'excel_upload',
    'use_yn'         => cellVal($ws, 'M6') ?? 'Y',
    'sort_no'        => cellInt($ws, 'O6') ?? 0,
    'evaluator_name' => cellVal($ws, 'Q6'),
];

// unit_type 한글 → DB값 변환
$unitTypeMap = [
    '작업유형'     => 'target',
    '중대위험작업' => 'major_work',
    '공구/장비'    => 'tool',
    '작업환경'     => 'env',
];
if (isset($unitTypeMap[$header['unit_type']])) {
    $header['unit_type'] = $unitTypeMap[$header['unit_type']];
}

// 필수값 검증
if (empty($header['unit_title'])) {
    jsonResponse(false, '단위평가서명(unit_title)은 필수 입력 항목입니다. 엑셀 D4 셀을 확인하세요.');
}

$validTypes = ['target', 'major_work', 'tool', 'env'];
if (!in_array($header['unit_type'], $validTypes, true)) {
    jsonResponse(false, "평가유형이 올바르지 않습니다. 허용값: 작업유형, 중대위험작업, 공구/장비, 작업환경");
}

// ── 항목 행 읽기 (행10~) ─────────────────────────────────────────
// 컬럼 매핑:
//   A=sort_no, B=task_code, C=task_name, D=hazard_name
//   E=hazard_4m, F=accident_type, G=injury_result, H=cause_text
//   I=likelihood_before, J=severity_before, K=risk_score_before
//   L=current_control_text
//   M=likelihood_current, N=severity_current, O=risk_score_current
//   P=additional_control_text
//   Q=likelihood_after, R=severity_after, S=risk_score_after, T=improvement_due_date, U=remark
$items = [];
$START_ROW = 9;
$MAX_ROW   = 200; // 최대 200행까지 읽음

for ($r = $START_ROW; $r <= $MAX_ROW; $r++) {
    // task_name(C), hazard_name(D) 둘 다 비어있으면 끝으로 판단
    $taskName   = cellVal($ws, "C{$r}");
    $hazardName = cellVal($ws, "D{$r}");

    if ($taskName === null && $hazardName === null) {
        // 연속 2행이 비어있으면 진짜 끝
        $nextTask = cellVal($ws, "C" . ($r + 1));
        if ($nextTask === null) break;
        continue; // 중간에 빈 행이 있어도 계속
    }

    if (empty($taskName) || empty($hazardName)) {
        // 필수값 하나라도 없으면 해당 행 스킵 (경고 수집)
        continue;
    }

    $items[] = [
        'sort_no'                   => cellInt($ws, "A{$r}") ?? ($r - $START_ROW + 1),
        'task_code'                 => cellVal($ws, "B{$r}"),
        'task_name'                 => $taskName,
        'hazard_name'               => $hazardName,
        'hazard_4m'                 => cellVal($ws, "E{$r}"),
        'accident_type'             => cellVal($ws, "F{$r}"),
        'injury_result'             => cellVal($ws, "G{$r}"),
        'cause_text'                => cellVal($ws, "H{$r}"),
        'likelihood_before'         => cellInt($ws, "I{$r}"),
        'severity_before'           => cellInt($ws, "J{$r}"),
        'risk_score_before'         => pairScore(cellInt($ws, "I{$r}"), cellInt($ws, "J{$r}")),
        'current_control_text'      => cellVal($ws, "L{$r}"),
        'likelihood_current'        => cellInt($ws, "M{$r}"),
        'severity_current'          => cellInt($ws, "N{$r}"),
        'risk_score_current'        => pairScore(cellInt($ws, "M{$r}"), cellInt($ws, "N{$r}")),
        'additional_control_text'   => cellVal($ws, "P{$r}"),
        'likelihood_after'          => cellInt($ws, "Q{$r}"),
        'severity_after'            => cellInt($ws, "R{$r}"),
        'risk_score_after'          => pairScore(cellInt($ws, "Q{$r}"), cellInt($ws, "R{$r}")),
        'improvement_due_date'      => cellDate($ws, "T{$r}"),
        'remark'                    => cellVal($ws, "U{$r}"),
        'use_yn'                    => 'Y',
    ];
}

if (empty($items)) {
    jsonResponse(false, '입력된 항목이 없습니다. 9행부터 세부작업명(C열)과 위험요인(D열)을 확인하세요.');
}

// ── DB 저장 (트랜잭션) ───────────────────────────────────────────
try {
    $pdo = getDB();
    ensure_unit_ra_header_report_title_type($pdo);
    $pdo->beginTransaction();

    // A4셀에 unit_ra_id 있으면 UPDATE, 없으면 INSERT
    $configSheet = $spreadsheet->getSheetByName('__config');
    $unitRaId = $configSheet ? cellInt($configSheet, 'A3') : null;

    if ($unitRaId) {
        // 존재 확인
        $chk = $pdo->prepare("SELECT unit_ra_id FROM unit_ra_header WHERE unit_ra_id = :id");
        $chk->execute([':id' => $unitRaId]);
        if (!$chk->fetch()) {
            jsonResponse(false, "unit_ra_id={$unitRaId} 에 해당하는 평가서가 없습니다. A4셀을 확인하세요.");
        }

        // 헤더 UPDATE
        $sqlHeader = "
            UPDATE unit_ra_header SET
                unit_type    = :unit_type,
                unit_title   = :unit_title,
                report_title_type = :report_title_type,
                process_name = :process_name,
                use_yn       = :use_yn,
                sort_no      = :sort_no,
                remark       = :remark,
                evaluator_name = :evaluator_name,
                updated_by   = :updated_by,
                updated_at   = NOW()
            WHERE unit_ra_id = :unit_ra_id
        ";
        $pdo->prepare($sqlHeader)->execute([
            ':unit_type'    => $header['unit_type'],
            ':unit_title'   => $header['unit_title'],
            ':report_title_type' => $header['report_title_type'],
            ':process_name' => $header['process_name'],
            ':use_yn'       => $header['use_yn'],
            ':sort_no'      => $header['sort_no'],
            ':remark'       => $header['remark'],
            ':evaluator_name' => $header['evaluator_name'],
            ':updated_by'   => $header['updated_by'],
            ':unit_ra_id'   => $unitRaId,
        ]);

        // 기존 항목 전체 삭제
        $pdo->prepare("DELETE FROM unit_ra_item WHERE unit_ra_id = :id")
            ->execute([':id' => $unitRaId]);

        $mode = 'UPDATE';

    } else {
        // 신규 헤더 INSERT
        $sqlHeader = "
            INSERT INTO unit_ra_header
                (unit_type, unit_title, report_title_type, unit_code, process_name,
                 use_yn, sort_no, remark, created_by, evaluator_name, updated_by,
                 created_at, updated_at)
            VALUES
                (:unit_type, :unit_title, :report_title_type, :unit_code, :process_name,
                 :use_yn, :sort_no, :remark, :created_by, :evaluator_name, :updated_by,
                 NOW(), NOW())
        ";
        $pdo->prepare($sqlHeader)->execute($header);
        $unitRaId = (int)$pdo->lastInsertId();
        $mode = 'INSERT';
    }

    // 항목 INSERT
    $sqlItem = "
        INSERT INTO unit_ra_item
            (unit_ra_id, sort_no, task_code, task_name,
             hazard_name, hazard_4m, accident_type, injury_result,
             cause_text, current_control_text, additional_control_text,
             likelihood_before, severity_before, risk_score_before,
             likelihood_current, severity_current, risk_score_current,
             likelihood_after, severity_after, risk_score_after,
             improvement_due_date, use_yn, remark,
             created_at, updated_at)
        VALUES
            (:unit_ra_id, :sort_no, :task_code, :task_name,
             :hazard_name, :hazard_4m, :accident_type, :injury_result,
             :cause_text, :current_control_text, :additional_control_text,
             :likelihood_before, :severity_before, :risk_score_before,
             :likelihood_current, :severity_current, :risk_score_current,
             :likelihood_after, :severity_after, :risk_score_after,
             :improvement_due_date, :use_yn, :remark,
             NOW(), NOW())
    ";
    $stmtI = $pdo->prepare($sqlItem);
    foreach ($items as $item) {
        $item['unit_ra_id'] = $unitRaId;
        $stmtI->execute($item);
    }

    $pdo->commit();

    jsonResponse(true, $mode === 'UPDATE' ? '수정 완료' : '등록 완료', [
        'unit_ra_id'  => $unitRaId,
        'unit_title'  => $header['unit_title'],
        'item_count'  => count($items),
        'mode'        => $mode,
    ]);

} catch (\PDOException $e) {
    $pdo->rollBack();
    jsonResponse(false, 'DB 저장 중 오류가 발생했습니다: ' . $e->getMessage());
} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, '처리 중 오류: ' . $e->getMessage());
}
