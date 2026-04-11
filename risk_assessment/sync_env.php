<?php
require_once __DIR__ . "/config/db.php";
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

date_default_timezone_set('Asia/Seoul');

$file = "A:/risk_server/data/excel_sync/env_master.xlsx";
$logFile = "A:/risk_server/data/logs/sync_env.log";

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
write_log("PHP_SAPI: " . php_sapi_name(), $logFile);
write_log("FILE PATH: " . $file, $logFile);
write_log("SCRIPT USER: " . get_current_user(), $logFile);

if (!file_exists($file)) {
    write_log("엑셀 파일 없음", $logFile);
    exit;
}

write_log("엑셀 파일 존재 확인", $logFile);

$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

write_log("행 수: " . count($rows), $logFile);

if (count($rows) < 2) {
    write_log("데이터 없음", $logFile);
    exit;
}

$headers = array_map('trim', $rows[0]);
write_log("헤더: " . implode(", ", $headers), $logFile);

$conn->begin_transaction();

try {
    $count = 0;

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        if (empty($row[0])) {
            write_log(($i + 1) . "행 skip: env_id 비어있음", $logFile);
            continue;
        }

        $data = array_combine($headers, $row);

        $env_id = (int)$data['env_id'];
        $env_name = $conn->real_escape_string(trim((string)$data['env_name']));
        $use_yn = trim((string)$data['use_yn']) !== '' ? trim((string)$data['use_yn']) : 'Y';
        $sort_no = (int)$data['sort_no'];

        $sql = "
            INSERT INTO env_master (env_id, env_name, use_yn, sort_no)
            VALUES ($env_id, '$env_name', '$use_yn', $sort_no)
            ON DUPLICATE KEY UPDATE
                env_name = VALUES(env_name),
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