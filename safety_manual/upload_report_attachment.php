<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';

header('Content-Type: application/json; charset=UTF-8');

function respond(bool $ok, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_current_user();
if (!is_array($user) || !auth_can_manage($user) || trim((string)($user['login_id'] ?? '')) !== '5878') {
    respond(false, ['message' => '권한이 없습니다.'], 403);
}

$year = max(2000, min(2100, (int)($_POST['year'] ?? 0)));
$half = ($_POST['half'] ?? '') === '하반기' ? '하반기' : '상반기';
$index = (int)($_POST['index'] ?? 0);
if ($year === 0 || $index < 1 || $index > 4) { respond(false, ['message' => '요청 값이 올바르지 않습니다.'], 400); }

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    respond(false, ['message' => '파일 업로드에 실패했습니다.'], 400);
}
$file = $_FILES['file'];
if ($file['size'] > 20 * 1024 * 1024) { respond(false, ['message' => '파일 용량은 20MB 이하만 가능합니다.'], 400); }

$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
if (!in_array($ext, $allowed, true)) { respond(false, ['message' => 'PDF 또는 이미지 파일만 업로드할 수 있습니다.'], 400); }

$uploadRoot = dirname(__DIR__) . '/uploads/safety_manual/risk_assessment_reports/' . $year . '_' . $half;
if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0775, true) && !is_dir($uploadRoot)) {
    respond(false, ['message' => '업로드 폴더를 만들지 못했습니다.'], 500);
}

$storedName = 'attachment_' . $index . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
$targetPath = $uploadRoot . '/' . $storedName;
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    respond(false, ['message' => '업로드 파일을 저장하지 못했습니다.'], 500);
}

$relativePath = '/uploads/safety_manual/risk_assessment_reports/' . $year . '_' . $half . '/' . $storedName;
$fieldName = 'attachment_' . $index . '_file';

try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS safety_risk_assessment_reports(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,report_year SMALLINT UNSIGNED NOT NULL,half_year TINYINT UNSIGNED NOT NULL,report_data LONGTEXT NOT NULL,updated_by VARCHAR(100) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_period(report_year,half_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $halfYear = $half === '상반기' ? 1 : 2;
    $statement = $db->prepare('SELECT report_data FROM safety_risk_assessment_reports WHERE report_year=? AND half_year=?');
    $statement->execute([$year, $halfYear]);
    $saved = $statement->fetchColumn();
    $data = is_string($saved) && is_array($decoded = json_decode($saved, true)) ? $decoded : [];
    $data[$fieldName] = $relativePath;
    $upsert = $db->prepare('INSERT INTO safety_risk_assessment_reports(report_year,half_year,report_data,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE report_data=VALUES(report_data),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
    $upsert->execute([$year, $halfYear, json_encode($data, JSON_UNESCAPED_UNICODE), trim((string)($user['name'] ?? $user['login_id']))]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(false, ['message' => '업로드는 되었지만 저장 중 오류가 발생했습니다.'], 500);
}

respond(true, ['path' => $relativePath]);
