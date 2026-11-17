<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

// Auth Guard
$user = csm_require_auth();

$batch = trim((string)($_GET['batch'] ?? ''));
$workerPath = __DIR__ . '/storage/export_worker.py';
$pyBin = get_python_bin();

if ($batch !== '') {
    // BATCH EXPORT (Stitched Duplex PDF)
    $cleanIds = array_filter(array_map('intval', explode(',', $batch)));
    if (empty($cleanIds)) {
        die('일괄 출력할 교육 ID가 올바르지 않습니다.');
    }
    $idsArg = implode(',', $cleanIds);
    $cmd = sprintf('"%s" "%s" --batch "%s" 2>&1', $pyBin, $workerPath, $idsArg);
    $output = shell_exec($cmd);
    
    // Find latest generated batch file
    $exports = glob(__DIR__ . '/storage/exports/CSM_BATCH_EXPORT_*.pdf');
    if (empty($exports)) {
        http_response_code(500);
        die('일괄 출력 PDF 생성에 실패했습니다: <br><pre>' . htmlspecialchars((string)$output) . '</pre>');
    }
    usort($exports, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $outPath = $exports[0];
    
    $downloadName = sprintf("CSM_일괄안전교육일지_양면출력_%s.pdf", date('Ymd_His'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . urlencode($downloadName) . '"');
    header('Content-Length: ' . filesize($outPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($outPath);
    exit;
}

// SINGLE EXPORT
$id = (int)($_GET['id'] ?? 0);
$format = strtolower((string)($_GET['format'] ?? 'xlsx'));
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    die('지원하지 않는 서식입니다.');
}

$db = get_csm_db();
$stmt = $db->prepare("SELECT * FROM csm_trainings WHERE id = ?");
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) {
    http_response_code(404);
    die('교육일지 기록을 찾을 수 없습니다.');
}

$cmd = sprintf('"%s" "%s" %d 2>&1', $pyBin, $workerPath, $id);
$output = shell_exec($cmd);

$tNoSafe = str_replace(['/', '\\'], '_', (string)$t['training_no']);
$outPath = __DIR__ . '/storage/exports/' . $tNoSafe . '.' . $format;

if (!file_exists($outPath)) {
    http_response_code(500);
    die('출력 파일 생성에 실패했습니다: <br><pre>' . htmlspecialchars((string)$output) . '</pre>');
}

$contentType = ($format === 'pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
$downloadName = sprintf("%s_안전보건교육일지_%s.%s", $t['training_date'], $tNoSafe, $format);

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . urlencode($downloadName) . '"');
header('Content-Length: ' . filesize($outPath));
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($outPath);
exit;
