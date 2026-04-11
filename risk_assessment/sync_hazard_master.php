<?php
require_once __DIR__ . "/config/db.php";
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

date_default_timezone_set('Asia/Seoul');

$file = "A:/risk_server/data/excel_sync/hazard_master.xlsx";
$logFile = "A:/risk_server/data/logs/sync_hazard_master.log";

function write_log(string $msg, string $logFile): void {
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function normalize_use_yn($value): string {
    if (is_bool($value)) return $value ? 'Y' : 'N';
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['N','NO','FALSE','0']) ? 'N' : 'Y';
}

write_log("=== sync start ===", $logFile);

$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, false);

$headers = array_map('trim', $rows[0]);

$conn->begin_transaction();

try {
    $count = 0;

    for ($i = 1; $i < count($rows); $i++) {

        $row = $rows[$i];
        if (!array_filter($row)) continue;

        $data = array_combine($headers, $row);

        $hazard_id = (int)$data['hazard_id'];
        if ($hazard_id <= 0) continue;

        $use_yn = normalize_use_yn($data['use_yn']);

        $sql = "
        INSERT INTO hazard_master (
            hazard_id, hazard_group, hazard_name, accident_type,
            injury_result, description, cause_text,
            default_control_text, required_ppe,
            default_likelihood, default_severity, default_risk_score,
            use_yn, sort_no
        )
        VALUES (
            {$hazard_id},
            '{$conn->real_escape_string($data['hazard_group'])}',
            '{$conn->real_escape_string($data['hazard_name'])}',
            '{$conn->real_escape_string($data['accident_type'])}',
            '{$conn->real_escape_string($data['injury_result'])}',
            '{$conn->real_escape_string($data['description'])}',
            '{$conn->real_escape_string($data['cause_text'])}',
            '{$conn->real_escape_string($data['default_control_text'])}',
            '{$conn->real_escape_string($data['required_ppe'])}',
            " . (int)$data['default_likelihood'] . ",
            " . (int)$data['default_severity'] . ",
            " . (int)$data['default_risk_score'] . ",
            '{$use_yn}',
            " . (int)$data['sort_no'] . "
        )
        ON DUPLICATE KEY UPDATE
            hazard_group = VALUES(hazard_group),
            hazard_name = VALUES(hazard_name),
            accident_type = VALUES(accident_type),
            injury_result = VALUES(injury_result),
            description = VALUES(description),
            cause_text = VALUES(cause_text),
            default_control_text = VALUES(default_control_text),
            required_ppe = VALUES(required_ppe),
            default_likelihood = VALUES(default_likelihood),
            default_severity = VALUES(default_severity),
            default_risk_score = VALUES(default_risk_score),
            use_yn = VALUES(use_yn),
            sort_no = VALUES(sort_no)
        ";

        if (!$conn->query($sql)) {
            throw new Exception($conn->error);
        }

        $count++;
    }

    $conn->commit();
    write_log("완료: {$count}건", $logFile);

} catch (Exception $e) {
    $conn->rollback();
    write_log("오류: " . $e->getMessage(), $logFile);
}