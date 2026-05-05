<?php

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../lib/hazard_4m.php';

function out(string $message): void
{
    if (PHP_SAPI === 'cli') {
        echo $message . PHP_EOL;
        return;
    }

    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$columnExists = (int)$pdo->query("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hazard_master'
      AND COLUMN_NAME = 'hazard_4m'
")->fetchColumn() > 0;

if (!$columnExists) {
    $pdo->exec("
        ALTER TABLE hazard_master
        ADD COLUMN hazard_4m VARCHAR(10) NULL
        COMMENT '" . HAZARD_4M_COMMENT . "'
        AFTER required_ppe
    ");
    out('hazard_master.hazard_4m 컬럼을 추가했습니다.');
} else {
    out('hazard_master.hazard_4m 컬럼이 이미 존재합니다.');
}

$rows = $pdo->query("
    SELECT
        hazard_id,
        hazard_group,
        hazard_name,
        accident_type,
        injury_result,
        description,
        cause_text,
        default_control_text,
        required_ppe
    FROM hazard_master
    ORDER BY hazard_id ASC
")->fetchAll();

$updateStmt = $pdo->prepare("
    UPDATE hazard_master
    SET hazard_4m = :hazard_4m
    WHERE hazard_id = :hazard_id
");

$counts = [
    'total' => 0,
    'M1' => 0,
    'M2' => 0,
    'M3' => 0,
    'M4' => 0,
    'REVIEW' => 0,
];
$reviewRows = [];
$m1CandidateRows = [];

$pdo->beginTransaction();

try {
    foreach ($rows as $row) {
        $code = hazard_4m_classify($row, true);
        if ($code === null || $code === '') {
            $code = 'REVIEW';
        }

        $updateStmt->execute([
            ':hazard_4m' => $code,
            ':hazard_id' => (int)$row['hazard_id'],
        ]);

        $counts['total']++;
        $counts[$code] = ($counts[$code] ?? 0) + 1;

        if ($code === 'REVIEW') {
            $reviewRows[] = $row;
        }

        if ($code !== 'M1' && hazard_4m_m1_candidate($row)) {
            $m1CandidateRows[] = $row + ['classified_as' => $code];
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

out('4M 분류 적용이 완료되었습니다.');
out('총 처리건수: ' . $counts['total']);
out('M1 인적: ' . $counts['M1']);
out('M2 기계적: ' . $counts['M2']);
out('M3 관리적: ' . $counts['M3']);
out('M4 물질·환경적: ' . $counts['M4']);
out('검토필요(REVIEW): ' . $counts['REVIEW']);

$m1Ratio = $counts['total'] > 0 ? ($counts['M1'] / $counts['total']) * 100 : 0;
out('M1 비율: ' . number_format($m1Ratio, 2) . '%');

if (!empty($reviewRows)) {
    out('검토필요 목록:');
    foreach ($reviewRows as $row) {
        out(sprintf(
            '- [%d] %s / %s',
            (int)$row['hazard_id'],
            trim((string)($row['hazard_group'] ?? '')),
            trim((string)($row['hazard_name'] ?? ''))
        ));
    }
}

if ($m1Ratio < 20 && !empty($m1CandidateRows)) {
    out('M1 후보 목록:');
    foreach ($m1CandidateRows as $row) {
        out(sprintf(
            '- [%d] 현재분류=%s / hazard_name=%s / cause_text=%s / default_control_text=%s',
            (int)($row['hazard_id'] ?? 0),
            (string)($row['classified_as'] ?? ''),
            trim((string)($row['hazard_name'] ?? '')),
            trim((string)($row['cause_text'] ?? '')),
            trim((string)($row['default_control_text'] ?? ''))
        ));
    }
}
