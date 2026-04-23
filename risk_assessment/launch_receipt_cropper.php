<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function windows_cmd_quote(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function run_windows_command(string $command, ?int &$exitCode = null): string
{
    $output = [];
    exec($command . ' 2>&1', $output, $exitCode);

    return trim(implode(PHP_EOL, $output));
}

$user = auth_current_user();
if (!is_array($user)) {
    respond_json(401, [
        'success' => false,
        'message' => '로그인 후 다시 시도해주세요.',
    ]);
}

$userRole = (string)($user['role'] ?? '');
if (!auth_is_admin($user) && !in_array($userRole, ['safety_manager', 'administrator'], true)) {
    respond_json(403, [
        'success' => false,
        'message' => '안전관리자 또는 관리자만 사용할 수 있습니다.',
    ]);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    respond_json(405, [
        'success' => false,
        'message' => '허용되지 않는 요청 방식입니다.',
    ]);
}

$projectRoot = dirname(__DIR__);
$scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'ETC' . DIRECTORY_SEPARATOR . 'receipt_batch_cropper.py';
$launcherPath = $projectRoot . DIRECTORY_SEPARATOR . 'ETC' . DIRECTORY_SEPARATOR . 'launch_receipt_batch_cropper.ps1';
$taskName = 'ReceiptBatchCropper';

if (!is_file($scriptPath)) {
    respond_json(500, [
        'success' => false,
        'message' => '영수증 일괄크롭 프로그램 파일을 찾을 수 없습니다.',
    ]);
}

if (!is_file($launcherPath)) {
    respond_json(500, [
        'success' => false,
        'message' => '영수증 일괄크롭 실행 스크립트를 찾을 수 없습니다.',
    ]);
}

$queryCommand = 'schtasks /Query /TN ' . windows_cmd_quote($taskName);
$queryOutput = run_windows_command($queryCommand, $queryExitCode);
if ($queryExitCode !== 0) {
    respond_json(500, [
        'success' => false,
        'message' => '영수증 일괄크롭 작업이 아직 등록되지 않았습니다. 서버에서 작업 스케줄러를 먼저 확인해주세요.',
        'detail' => $queryOutput,
    ]);
}

$runCommand = 'schtasks /Run /TN ' . windows_cmd_quote($taskName);
$runOutput = run_windows_command($runCommand, $runExitCode);
if ($runExitCode !== 0) {
    respond_json(500, [
        'success' => false,
        'message' => '영수증 일괄크롭 작업 실행에 실패했습니다.',
        'detail' => $runOutput,
    ]);
}

respond_json(200, [
    'success' => true,
    'message' => '영수증 일괄크롭 창 실행을 요청했습니다. 잠시 후 바탕화면에 창이 보이는지 확인해주세요.',
]);
