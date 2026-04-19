<?php
require_once __DIR__ . '/../../risk_server/db_config.php';
require_once __DIR__ . '/upload_validation.php';

/**
 * HTML escape helper.
 *
 * @param mixed $value
 * @return string
 */
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * detail 파일 배열을 인덱스 기반으로 재구성합니다.
 *
 * @param array $files
 * @return array
 */
function normalizeDetailFiles(array $files): array
{
    $result = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    foreach ($files['name'] as $index => $fields) {
        foreach ($fields as $fieldName => $value) {
            $result[$index][$fieldName] = [
                'name' => $files['name'][$index][$fieldName] ?? '',
                'type' => $files['type'][$index][$fieldName] ?? '',
                'tmp_name' => $files['tmp_name'][$index][$fieldName] ?? '',
                'error' => $files['error'][$index][$fieldName] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index][$fieldName] ?? 0,
            ];
        }
    }

    return $result;
}

/**
 * 파일을 저장하고 상대 경로를 반환합니다.
 *
 * @param array $file
 * @param string $uploadRoot
 * @param string $relativeDir
 * @return string
 */
function saveDetailFile(array $file, string $uploadRoot, string $relativeDir): string
{
    // 업로드된 파일이 없으면 정상 처리합니다.
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    // 그 외 업로드 오류가 있으면 예외로 처리합니다.
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('이미지 업로드 중 오류가 발생했습니다.');
    }

    // 업로드된 이미지에 대한 확장자, MIME 타입, 용량 검증
    validateUploadedImage($file);

    if (!is_uploaded_file($file['tmp_name'])) {
        return '';
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $extension = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $extension));
    $extension = $extension !== '' ? '.' . $extension : '';

    $timestamp = date('Ymd_His');
    $randomSuffix = random_int(1000, 9999);
    $filename = sprintf('safety_%s_%s%s', $timestamp, $randomSuffix, $extension);

    $relativeDir = trim($relativeDir, '/');
    $targetDir = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        throw new RuntimeException('업로드 폴더를 생성할 수 없습니다: ' . $targetDir);
    }

    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('파일 이동에 실패했습니다: ' . $file['name']);
    }

    return $relativeDir . '/' . $filename;
}

function safetyLogHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function normalizePreventionData($rawValue): string
{
    if (!is_string($rawValue) || trim($rawValue) === '') {
        return '';
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return '';
    }

    $activity = trim((string)($decoded['activity'] ?? ''));
    $process = trim((string)($decoded['process'] ?? ''));
    $items = [];

    foreach (($decoded['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $measure = trim((string)($item['measure'] ?? ''));
        $status = trim((string)($item['status'] ?? ''));
        if ($measure === '') {
            continue;
        }

        $items[] = [
            'measure' => $measure,
            'status' => $status,
        ];
    }

    if ($activity === '' && $process === '' && empty($items)) {
        return '';
    }

    return json_encode([
        'activity' => $activity,
        'process' => $process,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$logDate = trim($_POST['log_date'] ?? '');
$managerName = trim($_POST['manager_name'] ?? '');
$siteName = trim($_POST['site_name'] ?? '');
$workLocation = trim($_POST['work_location'] ?? '');
$weather = trim($_POST['weather'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$summary = trim($_POST['summary'] ?? '');
$remark = trim($_POST['remark'] ?? '');
$details = $_POST['details'] ?? [];
$files = normalizeDetailFiles($_FILES['details'] ?? []);

if ($id === false || $id === null) {
    http_response_code(400);
    echo '<h1>잘못된 요청입니다.</h1>';
    echo '<p>유효한 업무일지 ID가 전달되지 않았습니다.</p>';
    exit;
}

try {
    $pdo = getDB();
    if (!safetyLogHasColumn($pdo, 'safety_manager_log_detail', 'prevention_data')) {
        $pdo->exec('ALTER TABLE safety_manager_log_detail ADD COLUMN prevention_data LONGTEXT NULL AFTER description');
    }
    if (!safetyLogHasColumn($pdo, 'safety_manager_log_detail', 'photo_3')) {
        $pdo->exec('ALTER TABLE safety_manager_log_detail ADD COLUMN photo_3 VARCHAR(500) NULL AFTER photo_2');
    }
    $pdo->beginTransaction();

    // 기존 detail 파일 경로를 보존하기 위해 현재 데이터를 먼저 조회합니다.
    $oldPhotos = [];
    $oldStmt = $pdo->prepare(
        'SELECT item_no, photo_1, photo_2, photo_3, prevention_data
         FROM safety_manager_log_detail
         WHERE log_id = :log_id'
    );
    $oldStmt->execute([':log_id' => $id]);
    foreach ($oldStmt->fetchAll() as $oldRow) {
        $oldPhotos[(int)$oldRow['item_no']] = [
            'photo_1' => $oldRow['photo_1'],
            'photo_2' => $oldRow['photo_2'],
            'photo_3' => $oldRow['photo_3'],
            'prevention_data' => $oldRow['prevention_data'] ?? '',
        ];
    }

    // safety_manager_log 업데이트
    $updateLog = $pdo->prepare(
        'UPDATE safety_manager_log
         SET log_date = :log_date,
             manager_name = :manager_name,
             site_name = :site_name,
             work_location = :work_location,
             weather = :weather,
             subject = :subject,
             summary = :summary,
             remark = :remark
         WHERE id = :id'
    );
    $updateLog->execute([
        ':log_date' => $logDate,
        ':manager_name' => $managerName,
        ':site_name' => $siteName,
        ':work_location' => $workLocation,
        ':weather' => $weather,
        ':subject' => $subject,
        ':summary' => $summary,
        ':remark' => $remark,
        ':id' => $id,
    ]);

    // 기존 detail 삭제
    $deleteDetail = $pdo->prepare('DELETE FROM safety_manager_log_detail WHERE log_id = :log_id');
    $deleteDetail->execute([':log_id' => $id]);

    // 새로운 detail 다시 삽입
    $insertDetail = $pdo->prepare(
           'INSERT INTO safety_manager_log_detail (log_id, item_no, work_time, activity, description, prevention_data, status, photo_1, photo_2, photo_3)
            VALUES (:log_id, :item_no, :work_time, :activity, :description, :prevention_data, :status, :photo_1, :photo_2, :photo_3)'
    );

    $uploadRoot = 'A:\\risk_server\\uploads\\safety_log';
    if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0755, true)) {
        throw new RuntimeException('업로드 루트 경로를 생성할 수 없습니다: ' . $uploadRoot);
    }
    $relativeDir = sprintf('%s/%s', date('Y'), date('m'));

    foreach ($details as $index => $detail) {
        $itemNo = isset($detail['item_no']) ? (int)$detail['item_no'] : $index + 1;
        $workTime = trim($detail['work_time'] ?? '');
        $activity = trim($detail['activity'] ?? '');
        $description = trim($detail['description'] ?? '');
        $preventionData = normalizePreventionData($detail['prevention_data'] ?? '');

        $photo1Upload = saveDetailFile($files[$index]['photo_1'] ?? [], $uploadRoot, $relativeDir);
        $photo2Upload = saveDetailFile($files[$index]['photo_2'] ?? [], $uploadRoot, $relativeDir);
        $photo3Upload = saveDetailFile($files[$index]['photo_3'] ?? [], $uploadRoot, $relativeDir);

        $photo1 = $photo1Upload !== '' ? $photo1Upload : ($oldPhotos[$itemNo]['photo_1'] ?? '');
        $photo2 = $photo2Upload !== '' ? $photo2Upload : ($oldPhotos[$itemNo]['photo_2'] ?? '');
        $photo3 = $photo3Upload !== '' ? $photo3Upload : ($oldPhotos[$itemNo]['photo_3'] ?? '');
        if ($preventionData === '') {
            $preventionData = (string)($oldPhotos[$itemNo]['prevention_data'] ?? '');
        }

        if ($workTime === '' && $activity === '' && $description === '' && $preventionData === '' && $photo1 === '' && $photo2 === '' && $photo3 === '') {
            continue;
        }

        $insertDetail->execute([
            ':log_id' => $id,
            ':item_no' => $itemNo,
            ':work_time' => $workTime,
            ':activity' => $activity,
            ':description' => $description,
            ':prevention_data' => $preventionData,
            ':status' => '',
            ':photo_1' => $photo1,
            ':photo_2' => $photo2,
            ':photo_3' => $photo3,
        ]);
    }

    $pdo->commit();
    // 수정 성공 시 상세보기 페이지로 이동하고 성공 메시지를 전달합니다.
    header('Location: view.php?id=' . $id . '&type=success&message=' . rawurlencode('업무일지가 수정되었습니다.'));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackException) {
            // rollback 실패 시 추가 처리 없이 무시
        }
    }

    // 수정 실패 시 상세보기 페이지로 이동하고 에러 메시지를 전달합니다.
    header('Location: view.php?id=' . $id . '&type=error&message=' . rawurlencode('업무일지 수정 중 오류가 발생했습니다.'));
    exit;
}
