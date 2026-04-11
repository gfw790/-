<?php
require_once __DIR__ . "/config/db.php";
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

date_default_timezone_set('Asia/Seoul');

$file = "A:/risk_server/data/excel_sync/major_work_master.xlsx";
$logFile = "A:/risk_server/data/logs/sync_major_work_master.log";

function write_log(string $msg, string $logFile): void {
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') {
        echo $line;
    } else {
        echo nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'));
    }
}

write_log("=== sync start ===", $logFile);
write_log("FILE PATH: " . $file, $logFile);

if (!file_exists($file)) {
    write_log("엑셀 파일 없음", $logFile);
    exit;
}

$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

if (count($rows) < 2) {
    write_log("데이터 없음", $logFile);
    exit;
}

$headers = array_map('trim', $rows[0]);

$conn->begin_transaction();

try {
    $count = 0;

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        if (empty($row[0])) {
            write_log(($i + 1) . "행 skip: major_work_id 비어있음", $logFile);
            continue;
        }

        $data = array_combine($headers, $row);

        $major_work_id = (int)$data['major_work_id'];
        $major_work_name = $conn->real_escape_string(trim((string)$data['major_work_name']));
        $use_yn = trim((string)$data['use_yn']) !== '' ? trim((string)$data['use_yn']) : 'Y';
        $sort_no = isset($data['sort_no']) && trim((string)$data['sort_no']) !== ''
            ? (int)$data['sort_no']
            : 0;

        $sql = "
            INSERT INTO major_work_master (major_work_id, major_work_name, use_yn, sort_no)
            VALUES ($major_work_id, '$major_work_name', '$use_yn', $sort_no)
            ON DUPLICATE KEY UPDATE
                major_work_name = VALUES(major_work_name),
                use_yn = VALUES(use_yn),
                sort_no = VALUES(sort_no)
        ";

        if (!$conn->query($sql)) {
            throw new Exception("SQL 오류: " . $conn->error);
        }

        $count++;
    }

    $conn->commit();
    write_log("동기화 완료: {$count}건", $logFile);

} catch (Exception $e) {
    $conn->rollback();
    write_log("오류: " . $e->getMessage(), $logFile);
}