<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/scoring.php';
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE leadership_records (id INTEGER PRIMARY KEY, item TEXT, year INTEGER, payload TEXT);
CREATE TABLE leadership_files (id INTEGER PRIMARY KEY, record_id INTEGER, stored_name TEXT, deleted_at TEXT)");
$asOf = new DateTimeImmutable('2026-11-12', new DateTimeZone('Asia/Seoul'));
$assert = static function (float $expected) use ($db, $asOf): void {
    $actual = csm_activity_monitor_summary($db, 2026, 2.0, $asOf)['earned'];
    if ($actual !== $expected) throw new RuntimeException("Expected $expected, got $actual");
};
$assert(0.0);
$directory = dirname(__DIR__) . '/storage/leadership_evidence';
if (!is_dir($directory)) mkdir($directory, 0750, true);
$name = bin2hex(random_bytes(20)) . '.pdf';
$path = $directory . '/' . $name;
file_put_contents($path, '%PDF-1.4 test');
try {
    $insertRecord = $db->prepare("INSERT INTO leadership_records(id,item,year,payload) VALUES (?,'1.2.2',2026,?)");
    $insertFile = $db->prepare('INSERT INTO leadership_files(record_id,stored_name) VALUES (?,?)');
    $insertRecord->execute([1, json_encode(['kind' => 'quarter', 'number' => 1, 'summary' => '수행 검토', 'reviewer' => '대표'], JSON_UNESCAPED_UNICODE)]);
    $insertFile->execute([1, $name]);
    $assert(1.6);
    $insertRecord->execute([20, json_encode(['kind' => 'quarter', 'number' => 4, 'summary' => '미완료 분기', 'reviewer' => '대표'], JSON_UNESCAPED_UNICODE)]);
    $insertFile->execute([20, $name]);
    $assert(1.6);
    $insertRecord->execute([2, json_encode(['kind' => 'quarter', 'number' => 3, 'summary' => '수행 검토', 'reviewer' => '대표'], JSON_UNESCAPED_UNICODE)]);
    $insertFile->execute([2, $name]);
    $assert(1.8);
    for ($month = 1; $month <= 11; $month++) {
        $id = $month + 2;
        $insertRecord->execute([$id, json_encode(['kind' => 'month', 'number' => $month, 'summary' => '점검'], JSON_UNESCAPED_UNICODE)]);
        $insertFile->execute([$id, $name]);
    }
    $assert(1.8);
    $insertRecord->execute([14, json_encode(['kind' => 'quarter', 'number' => 2, 'summary' => '수행 검토', 'reviewer' => '대표'], JSON_UNESCAPED_UNICODE)]);
    $insertFile->execute([14, $name]);
    $assert(2.0);
    $db->exec('UPDATE leadership_files SET deleted_at=\'2026-11-12\' WHERE record_id=14');
    $assert(1.8);
    $db->exec('UPDATE leadership_files SET deleted_at=\'2026-11-12\' WHERE record_id IN (1,2)');
    $assert(1.0);
    echo "activity monitor scoring OK\n";
} finally {
    unlink($path);
}
