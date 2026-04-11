<?php
require_once __DIR__ . "/config/db.php";
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

date_default_timezone_set('Asia/Seoul');

$file = "A:/risk_server/data/excel_sync/tool_master.xlsx";
$logFile = "A:/risk_server/data/logs/sync_tool.log";

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
            write_log(($i + 1) . "행 skip: tool_id 비어있음", $logFile);
            continue;
        }

        $data = array_combine($headers, $row);

        $tool_id = (int)$data['tool_id'];
        $tool_name = $conn->real_escape_string(trim((string)$data['tool_name']));
        $use_yn = trim((string)$data['use_yn']) !== '' ? trim((string)$data['use_yn']) : 'Y';
        $sort_no = (int)$data['sort_no'];

        $sql = "
            INSERT INTO tool_master (tool_id, tool_name, use_yn, sort_no)
            VALUES ($tool_id, '$tool_name', '$use_yn', $sort_no)
            ON DUPLICATE KEY UPDATE
                tool_name = VALUES(tool_name),
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