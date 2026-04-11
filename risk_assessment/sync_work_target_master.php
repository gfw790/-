<?php
require_once __DIR__ . "/config/db.php";
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

date_default_timezone_set('Asia/Seoul');

$file = "A:/risk_server/data/excel_sync/work_target_master.xlsx";
$logFile = "A:/risk_server/data/logs/sync_work_target_master.log";

function write_log(string $msg, string $logFile): void {
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);

    if (php_sapi_name() === 'cli') {
        echo $line;
    } else {
        echo nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'));
    }
}

function normalize_use_yn($value): string {
    if (is_bool($value)) return $value ? 'Y' : 'N';

    $value = strtoupper(trim((string)$value));

    if (in_array($value, ['1','Y','YES','TRUE'], true)) return 'Y';
    if (in_array($value, ['0','N','NO','FALSE'], true)) return 'N';

    return 'Y';
}

write_log("=== sync start ===", $logFile);
write_log("FILE PATH: " . $file, $logFile);

if (!file_exists($file)) {
    write_log("엑셀 파일 없음", $logFile);
    exit;
}

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
} catch (Throwable $e) {
    write_log("엑셀 로드 오류: " . $e->getMessage(), $logFile);
    exit;
}

if (count($rows) < 2) {
    write_log("데이터 없음", $logFile);
    exit;
}

$headers = array_map(fn($h) => trim((string)$h), $rows[0]);

$requiredHeaders = [
    'target_id',
    'process_category',
    'major_category',
    'work_type',
    'use_yn',
    'sort_no'
];

foreach ($requiredHeaders as $requiredHeader) {
    if (!in_array($requiredHeader, $headers, true)) {
        write_log("필수 헤더 없음: " . $requiredHeader, $logFile);
        exit;
    }
}

$conn->begin_transaction();

try {
    $count = 0;
    $skipCount = 0;

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $data = array_combine($headers, array_pad($row, count($headers), null));

        $target_id        = (int)($data['target_id'] ?? 0);
        $process_category = trim((string)($data['process_category'] ?? ''));
        $major_category   = trim((string)($data['major_category'] ?? ''));
        $work_type        = trim((string)($data['work_type'] ?? ''));
        $use_yn           = normalize_use_yn($data['use_yn'] ?? 'Y');
        $sort_no          = trim((string)($data['sort_no'] ?? '')) !== '' ? (int)$data['sort_no'] : 0;

        if ($target_id <= 0) {
            write_log(($i+1) . "행 skip: target_id 오류", $logFile);
            $skipCount++;
            continue;
        }

        if ($process_category === '' || $major_category === '' || $work_type === '') {
            write_log(($i+1) . "행 skip: 필수값 누락", $logFile);
            $skipCount++;
            continue;
        }

        $process_category_esc = $conn->real_escape_string($process_category);
        $major_category_esc   = $conn->real_escape_string($major_category);
        $work_type_esc        = $conn->real_escape_string($work_type);
        $use_yn_esc           = $conn->real_escape_string($use_yn);

        $sql = "
            INSERT INTO work_target_master (
                target_id,
                process_category,
                major_category,
                work_type,
                use_yn,
                sort_no
            )
            VALUES (
                $target_id,
                '$process_category_esc',
                '$major_category_esc',
                '$work_type_esc',
                '$use_yn_esc',
                $sort_no
            )
            ON DUPLICATE KEY UPDATE
                process_category = VALUES(process_category),
                major_category   = VALUES(major_category),
                work_type        = VALUES(work_type),
                use_yn           = VALUES(use_yn),
                sort_no          = VALUES(sort_no)
        ";

        if (!$conn->query($sql)) {
            throw new Exception("SQL 오류(" . ($i+1) . "행): " . $conn->error);
        }

        $count++;
    }

    $conn->commit();

    write_log("UPSERT: {$count}건", $logFile);
    write_log("SKIP: {$skipCount}건", $logFile);
    write_log("동기화 완료", $logFile);

} catch (Throwable $e) {
    $conn->rollback();
    write_log("오류: " . $e->getMessage(), $logFile);
}