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
if (!in_array($ext, ['xlsx', 'xls'], true)) {
    jsonResponse(false, '.xlsx 또는 .xls 파일만 업로드 가능합니다.');
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

$ws = $spreadsheet->getSheetByName('단위위험성평가서');
if ($ws === null) {
    jsonResponse(false, '"단위위험성평가서" 시트를 찾을 수 없습니다. 양식을 확인하세요.');
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

// ── 헤더 정보 읽기 (행5, 행7) ────────────────────────────────────
// 양식 컬럼 매핑 (2행 제거 후 기준):
//   행4: A=unit_ra_id(자동), B=unit_code, D=unit_title, I=process_name, L=unit_type, O=remark
//   행6: A=created_by, D=created_at, M=use_yn, O=sort_no
$header = [
    'unit_code'     => cellVal($ws, 'B4'),  // B4셀에서 읽기
    'unit_title'    => cellVal($ws, 'D4'),
    'process_name'  => cellVal($ws, 'I4'),
    'unit_type'     => cellVal($ws, 'L4') ?? 'major_work',
    'remark'        => cellVal($ws, 'O4'),
    'created_by'    => cellVal($ws, 'A6'),
    'updated_by'    => cellVal($ws, 'G6'),
    'use_yn'        => cellVal($ws, 'M6') ?? 'Y',
    'sort_no'       => cellInt($ws, 'O6') ?? 0,
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
    jsonResponse(false, '단위평가서명(unit_title)은 필수 입력 항목입니다. 엑셀 D5 셀을 확인하세요.');
}

$validTypes = ['target', 'major_work', 'tool', 'env'];
if (!in_array($header['unit_type'], $validTypes, true)) {
    jsonResponse(false, "평가유형이 올바르지 않습니다. 허용값: 작업유형, 중대위험작업, 공구/장비, 작업환경");
}

// ── 항목 행 읽기 (행10~) ─────────────────────────────────────────
// 컬럼 매핑:
//   A=sort_no, B=task_code, C=task_name, D=hazard_name
//   E=accident_type, F=injury_result, G=cause_text
//   H=likelihood_before, I=severity_before, J=risk_score_before
//   K=current_control_text
//   L=likelihood_current, M=severity_current, N=risk_score_current
//   O=additional_control_text
//   P=likelihood_after, Q=severity_after, R=risk_score_after, S=improvement_due_date, T=remark
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
        'accident_type'             => cellVal($ws, "E{$r}"),
        'injury_result'             => cellVal($ws, "F{$r}"),
        'cause_text'                => cellVal($ws, "G{$r}"),
        'likelihood_before'         => cellInt($ws, "H$r"),
        'severity_before'           => cellInt($ws, "I$r"),
        'risk_score_before'         => cellScore($ws, "J$r"),
        'current_control_text'      => cellVal($ws, "K$r"),
        'likelihood_current'        => cellInt($ws, "L$r"),
        'severity_current'          => cellInt($ws, "I$r"),
        'risk_score_current'        => pairScore(cellInt($ws, "L$r"), cellInt($ws, "I$r")),
        'additional_control_text'   => cellVal($ws, "O$r"),
        'likelihood_after'          => cellInt($ws, "P$r"),
        'severity_after'            => cellInt($ws, "Q$r"),
        'risk_score_after'          => cellScore($ws, "R$r"),
        'improvement_due_date'      => cellDate($ws, "S$r"),
        'remark'                    => cellVal($ws, "T$r"),
        'use_yn'                    => 'Y',
    ];
}

if (empty($items)) {
    jsonResponse(false, '입력된 항목이 없습니다. 9행부터 세부작업명(C열)과 위험요인(D열)을 확인하세요.');
}

// ── DB 저장 (트랜잭션) ───────────────────────────────────────────
try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // A4셀에 unit_ra_id 있으면 UPDATE, 없으면 INSERT
    $unitRaId = cellInt($ws, 'A4');

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
                (unit_type, unit_title, unit_code, process_name,
                 use_yn, sort_no, remark, created_by, evaluator_name, updated_by,
                 created_at, updated_at)
            VALUES
                (:unit_type, :unit_title, :unit_code, :process_name,
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
             hazard_name, accident_type, injury_result,
             cause_text, current_control_text, additional_control_text,
             likelihood_before, severity_before, risk_score_before,
             likelihood_current, severity_current, risk_score_current,
             likelihood_after, severity_after, risk_score_after,
             improvement_due_date, use_yn, remark,
             created_at, updated_at)
        VALUES
            (:unit_ra_id, :sort_no, :task_code, :task_name,
             :hazard_name, :accident_type, :injury_result,
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
