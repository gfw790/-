<?php
require_once __DIR__ . '/includes/header.php';

$user = requireAdmin();
ensureNearMissSchema();

$autoloadCandidates = [
    __DIR__ . '/../risk_assessment/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
$autoloadPath = '';
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '엑셀 가져오기 라이브러리를 찾을 수 없습니다. (vendor/autoload.php)';
    exit;
}

require_once $autoloadPath;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function nearMissImportNormalizeHeader(string $header): string {
    $header = trim($header);
    $header = mb_strtolower($header, 'UTF-8');
    $header = preg_replace('/\s+/u', '', $header) ?? $header;
    return $header;
}

function nearMissImportValue(array $row, array $aliases): string {
    foreach ($aliases as $alias) {
        $key = nearMissImportNormalizeHeader($alias);
        if (array_key_exists($key, $row)) {
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function nearMissImportDateTimeValue($value): ?DateTimeImmutable {
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }

    if (is_numeric($value) && $value !== '') {
        try {
            return DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float)$value));
        } catch (Throwable $e) {
            return null;
        }
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y/m/d H:i:s',
        'Y/m/d H:i',
        'n/j/Y H:i:s',
        'n/j/Y H:i',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'Y-m-d',
        'Y/m/d',
        'n/j/Y',
        'm/d/Y',
    ];

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $text);
        if ($dt instanceof DateTimeImmutable) {
            if (str_contains($format, 'H') === false) {
                $dt = $dt->setTime(0, 0, 0);
            }
            return $dt;
        }
    }

    $ts = strtotime($text);
    if ($ts === false) {
        return null;
    }
    return (new DateTimeImmutable())->setTimestamp($ts);
}

function nearMissImportBuildIncidentAt(array $row): ?string {
    $incidentAt = nearMissImportValue($row, ['incident_at', '발생일시', '발생 일시']);
    if ($incidentAt !== '') {
        $parsed = nearMissImportDateTimeValue($incidentAt);
        if ($parsed) {
            return $parsed->format('Y-m-d H:i:s');
        }
    }

    $dateValue = nearMissImportValue($row, ['발생 일자', '발생일자', 'incident_date']);
    if ($dateValue === '') {
        return null;
    }

    $date = nearMissImportDateTimeValue($dateValue);
    if (!$date) {
        return null;
    }

    $hourText = nearMissImportValue($row, ['발생 시간(Hour)', '발생시간(hour)', '발생시간hour', 'incident_hour']);
    $minuteText = nearMissImportValue($row, ['발생 시간(Minute)', '발생시간(minute)', '발생시간minute', 'incident_minute']);

    preg_match('/\d+/', $hourText, $hourMatch);
    preg_match('/\d+/', $minuteText, $minuteMatch);
    $hour = isset($hourMatch[0]) ? max(0, min(23, (int)$hourMatch[0])) : 0;
    $minute = isset($minuteMatch[0]) ? max(0, min(59, (int)$minuteMatch[0])) : 0;

    return $date->setTime($hour, $minute, 0)->format('Y-m-d H:i:s');
}

function nearMissImportBuildContent(array $payload): string {
    $lines = [];
    $lines[] = '발생일시: ' . $payload['incident_at'];
    $lines[] = '아차사고명: ' . $payload['incident_name'];
    $lines[] = '발생장소: ' . $payload['location'];
    $lines[] = '작업유형: ' . $payload['work_type'];
    $lines[] = '사고유형: ' . ($payload['risk_type'] !== '' ? $payload['risk_type'] : '-');
    $lines[] = '불안전한 상태: ' . ($payload['unsafe_state'] !== '' ? $payload['unsafe_state'] : '-');
    $lines[] = '불안전한 행동: ' . ($payload['unsafe_action'] !== '' ? $payload['unsafe_action'] : '-');
    $lines[] = '부주의 행동: ' . ($payload['careless_action'] !== '' ? $payload['careless_action'] : '-');
    $lines[] = '부주의 상태: ' . ($payload['careless_state'] !== '' ? $payload['careless_state'] : '-');
    $lines[] = '제보자: ' . $payload['reporter_contact'];
    $lines[] = '';
    $lines[] = '[상황 설명]';
    $lines[] = $payload['description'];
    $lines[] = '';
    $lines[] = '[원인]';
    $lines[] = $payload['cause'];
    $lines[] = '';
    $lines[] = '[즉시 조치]';
    $lines[] = $payload['action_taken'] !== '' ? $payload['action_taken'] : '-';
    $lines[] = '';
    $lines[] = '[재발 방지 대책]';
    $lines[] = $payload['prevention_plan'] !== '' ? $payload['prevention_plan'] : '-';
    return implode("\n", $lines);
}

function nearMissImportNormalizeRow(array $row): array {
    $sourceExcelId = nearMissImportValue($row, ['Id', 'source_excel_id']);
    $incidentName = nearMissImportValue($row, ['아차사고명', 'incident_name', 'incident_name_clean']);
    $location = nearMissImportValue($row, ['발생 장소', '발생장소', 'location']);
    $riskType = nearMissImportValue($row, ['사고유형', 'risk_type']);
    $unsafeState = nearMissImportValue($row, ['불안전한 상태', 'unsafe_state']);
    $unsafeAction = nearMissImportValue($row, ['불안전한 행동', 'unsafe_action']);
    $carelessAction = nearMissImportValue($row, ['부주의 행동', 'careless_action']);
    $carelessState = nearMissImportValue($row, ['부주의 상태', 'careless_state']);
    $workType = nearMissImportValue($row, ['작업유형', 'work_type']);
    $description = nearMissImportValue($row, ['description']);
    $cause = nearMissImportValue($row, ['cause']);
    $combinedContent = nearMissImportValue($row, ['내용/ 원인', '내용/원인', 'content_cause']);
    $actionTaken = nearMissImportValue($row, ['action_taken', '즉시 조치']);
    $preventionPlan = nearMissImportValue($row, ['사고 방지를 위해 이렇게 조치하였습니다', 'prevention_plan']);
    $reporterTeam = nearMissImportValue($row, ['소속', 'author_dept']);
    $reporterName = nearMissImportValue($row, ['성명', 'author_name', '이름']);
    $sourceWrittenAt = nearMissImportValue($row, ['작성 시간', 'source_written_at']);
    $status = nearMissImportValue($row, ['status']);
    $incidentAt = nearMissImportBuildIncidentAt($row);

    if ($description === '' && $combinedContent !== '') {
        $description = $combinedContent;
    }
    if ($cause === '' && $combinedContent !== '') {
        $cause = $combinedContent;
    }
    if ($actionTaken === '' && $preventionPlan !== '') {
        $actionTaken = $preventionPlan;
    }
    if ($workType === '') {
        $workType = '일반';
    }
    if ($status === '' || !in_array($status, ['open', 'in_progress', 'closed'], true)) {
        $status = 'open';
    }

    $writtenAt = nearMissImportDateTimeValue($sourceWrittenAt);

    return [
        'source_excel_id' => $sourceExcelId,
        'source_written_at' => $writtenAt ? $writtenAt->format('Y-m-d H:i:s') : null,
        'incident_at' => $incidentAt,
        'incident_name' => $incidentName,
        'location' => $location,
        'work_type' => $workType,
        'risk_type' => $riskType,
        'unsafe_state' => $unsafeState,
        'unsafe_action' => $unsafeAction,
        'careless_action' => $carelessAction,
        'careless_state' => $carelessState,
        'description' => $description,
        'cause' => $cause,
        'action_taken' => $actionTaken,
        'prevention_plan' => $preventionPlan,
        'reporter_team' => $reporterTeam !== '' ? $reporterTeam : 'ETC',
        'reporter_name' => $reporterName !== '' ? $reporterName : '미상',
        'reporter_contact' => trim(($reporterTeam !== '' ? $reporterTeam : 'ETC') . ' / ' . ($reporterName !== '' ? $reporterName : '미상')),
        'status' => $status,
    ];
}

$message = '';
$messageType = 'info';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST['csrf'] ?? '');

    if (empty($_FILES['excel_file']) || (int)($_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = '업로드할 엑셀 파일을 선택해 주세요.';
        $messageType = 'error';
    } else {
        try {
            $tmpPath = (string)$_FILES['excel_file']['tmp_name'];
            $originalName = (string)($_FILES['excel_file']['name'] ?? '');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $readerType = match ($extension) {
                'xlsx' => 'Xlsx',
                'xls' => 'Xls',
                'csv' => 'Csv',
                default => '',
            };
            if ($readerType === '') {
                throw new RuntimeException('지원하지 않는 파일 형식입니다. xlsx, xls, csv만 가능합니다.');
            }

            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();

            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            $headerMap = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellAddress = Coordinate::stringFromColumnIndex($col) . '1';
                $headerText = trim((string)$sheet->getCell($cellAddress)->getFormattedValue());
                if ($headerText === '') {
                    continue;
                }
                $headerMap[$col] = nearMissImportNormalizeHeader($headerText);
            }

            if (empty($headerMap)) {
                throw new RuntimeException('헤더 행을 읽을 수 없습니다.');
            }

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            $categoryId = nearMissCategoryId();

            db()->beginTransaction();

            for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                $rawRow = [];
                foreach ($headerMap as $col => $headerKey) {
                    $cellAddress = Coordinate::stringFromColumnIndex($col) . $rowIndex;
                    $rawRow[$headerKey] = $sheet->getCell($cellAddress)->getValue();
                }

                $hasAnyValue = false;
                foreach ($rawRow as $value) {
                    if (trim((string)$value) !== '') {
                        $hasAnyValue = true;
                        break;
                    }
                }
                if (!$hasAnyValue) {
                    continue;
                }

                $payload = nearMissImportNormalizeRow($rawRow);
                if ($payload['source_excel_id'] === '' || !ctype_digit((string)$payload['source_excel_id'])) {
                    $skipped++;
                    $errors[] = $rowIndex . '행: Id 값이 없거나 숫자가 아닙니다.';
                    continue;
                }
                if ($payload['incident_at'] === null || $payload['incident_name'] === '' || $payload['location'] === '' || $payload['description'] === '' || $payload['cause'] === '') {
                    $skipped++;
                    $errors[] = $rowIndex . '행: 필수값(발생일시, 아차사고명, 장소, 내용/원인)이 부족합니다.';
                    continue;
                }

                $sourceExcelId = (int)$payload['source_excel_id'];
                $title = '[아차사고] ' . $payload['incident_name'];
                $content = nearMissImportBuildContent($payload);

                $existing = db()->prepare(
                    "SELECT n.post_id
                     FROM near_miss_reports n
                     WHERE n.source_excel_id = ?
                     LIMIT 1"
                );
                $existing->execute([$sourceExcelId]);
                $existingPostId = (int)$existing->fetchColumn();

                if ($existingPostId <= 0) {
                    $fallback = db()->prepare(
                        "SELECT p.id
                         FROM near_miss_reports n
                         JOIN posts p ON p.id = n.post_id
                         WHERE n.incident_at = ?
                           AND p.author_name = ?
                           AND p.title = ?
                         ORDER BY p.id ASC
                         LIMIT 1"
                    );
                    $fallback->execute([
                        $payload['incident_at'],
                        $payload['reporter_name'],
                        $title,
                    ]);
                    $existingPostId = (int)$fallback->fetchColumn();
                }

                if ($existingPostId > 0) {
                    db()->prepare(
                        "UPDATE posts
                         SET category_id = ?, title = ?, content = ?, author_name = ?, author_dept = ?
                         WHERE id = ?"
                    )->execute([
                        $categoryId,
                        $title,
                        $content,
                        $payload['reporter_name'],
                        $payload['reporter_team'],
                        $existingPostId,
                    ]);

                    db()->prepare(
                        "UPDATE near_miss_reports
                         SET source_excel_id = ?, source_written_at = ?, incident_at = ?, location = ?, work_type = ?, risk_type = ?,
                             unsafe_state = ?, unsafe_action = ?, careless_action = ?, careless_state = ?,
                             description = ?, cause = ?, action_taken = ?, prevention_plan = ?,
                             reporter_contact = ?, status = ?
                         WHERE post_id = ?"
                    )->execute([
                        $sourceExcelId,
                        $payload['source_written_at'],
                        $payload['incident_at'],
                        $payload['location'],
                        $payload['work_type'],
                        $payload['risk_type'] !== '' ? $payload['risk_type'] : null,
                        $payload['unsafe_state'] !== '' ? $payload['unsafe_state'] : null,
                        $payload['unsafe_action'] !== '' ? $payload['unsafe_action'] : null,
                        $payload['careless_action'] !== '' ? $payload['careless_action'] : null,
                        $payload['careless_state'] !== '' ? $payload['careless_state'] : null,
                        $payload['description'],
                        $payload['cause'],
                        $payload['action_taken'],
                        $payload['prevention_plan'] !== '' ? $payload['prevention_plan'] : null,
                        $payload['reporter_contact'],
                        $payload['status'],
                        $existingPostId,
                    ]);
                    $updated++;
                    continue;
                }

                db()->prepare(
                    "INSERT INTO posts (category_id, title, content, author_id, author_name, author_dept, is_notice)
                     VALUES (?, ?, ?, ?, ?, ?, 0)"
                )->execute([
                    $categoryId,
                    $title,
                    $content,
                    $user['id'],
                    $payload['reporter_name'],
                    $payload['reporter_team'],
                ]);

                $postId = (int)db()->lastInsertId();

                db()->prepare(
                    "INSERT INTO near_miss_reports
                     (post_id, source_excel_id, source_written_at, incident_at, location, work_type, risk_type, unsafe_state, unsafe_action, careless_action, careless_state,
                      description, cause, action_taken, prevention_plan, reporter_contact, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $postId,
                    $sourceExcelId,
                    $payload['source_written_at'],
                    $payload['incident_at'],
                    $payload['location'],
                    $payload['work_type'],
                    $payload['risk_type'] !== '' ? $payload['risk_type'] : null,
                    $payload['unsafe_state'] !== '' ? $payload['unsafe_state'] : null,
                    $payload['unsafe_action'] !== '' ? $payload['unsafe_action'] : null,
                    $payload['careless_action'] !== '' ? $payload['careless_action'] : null,
                    $payload['careless_state'] !== '' ? $payload['careless_state'] : null,
                    $payload['description'],
                    $payload['cause'],
                    $payload['action_taken'],
                    $payload['prevention_plan'] !== '' ? $payload['prevention_plan'] : null,
                    $payload['reporter_contact'],
                    $payload['status'],
                ]);

                $inserted++;
            }

            db()->commit();

            $result = [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
            $message = '엑셀 가져오기를 완료했습니다.';
            $messageType = 'info';
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $message = '엑셀 가져오기 중 오류가 발생했습니다.' . (DEBUG ? ' ' . $e->getMessage() : '');
            $messageType = 'error';
        }
    }
}
?>

<h2 class="page-title">
    <span>아차사고 엑셀 가져오기</span>
    <span class="page-title-right">
        <a href="index.php" class="btn">목록으로</a>
    </span>
</h2>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'error' : 'info' ?>"><?= h($message) ?></div>
<?php endif; ?>

<section class="admin-section">
    <h2>업로드</h2>
    <p class="admin-note">
        <code>near_miss.xlsx</code> 템플릿과 현재 시스템의 엑셀 다운로드 형식을 모두 읽습니다.
        같은 <code>Id</code>가 있으면 기존 글을 갱신하고, 없으면 새로 등록합니다.
    </p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="survey-field">
            <label for="excel_file">엑셀 파일</label>
            <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
        </div>
        <button type="submit" class="btn btn-primary">가져오기</button>
    </form>
</section>

<?php if (is_array($result)): ?>
    <section class="admin-section">
        <h2>처리 결과</h2>
        <div class="admin-stat-grid">
            <div class="admin-stat-card">
                <div class="admin-stat-label">신규 등록</div>
                <div class="admin-stat-value"><?= number_format((int)$result['inserted']) ?></div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-label">기존 갱신</div>
                <div class="admin-stat-value"><?= number_format((int)$result['updated']) ?></div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-label">건너뜀</div>
                <div class="admin-stat-value"><?= number_format((int)$result['skipped']) ?></div>
            </div>
        </div>

        <?php if (!empty($result['errors'])): ?>
            <div class="alert alert-info" style="margin-top:16px;">
                <?= nl2br(h(implode("\n", $result['errors']))) ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
