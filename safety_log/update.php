<?php
require_once __DIR__ . '/../../risk_server/db_config.php';

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
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

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
    $pdo->beginTransaction();

    // 기존 detail 파일 경로를 보존하기 위해 현재 데이터를 먼저 조회합니다.
    $oldPhotos = [];
    $oldStmt = $pdo->prepare(
        'SELECT item_no, photo_1, photo_2
         FROM safety_manager_log_detail
         WHERE log_id = :log_id'
    );
    $oldStmt->execute([':log_id' => $id]);
    foreach ($oldStmt->fetchAll() as $oldRow) {
        $oldPhotos[(int)$oldRow['item_no']] = [
            'photo_1' => $oldRow['photo_1'],
            'photo_2' => $oldRow['photo_2'],
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
        'INSERT INTO safety_manager_log_detail (log_id, item_no, work_time, activity, description, status, photo_1, photo_2)
         VALUES (:log_id, :item_no, :work_time, :activity, :description, :status, :photo_1, :photo_2)'
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
        $status = trim($detail['status'] ?? '');

        $photo1Upload = saveDetailFile($files[$index]['photo_1'] ?? [], $uploadRoot, $relativeDir);
        $photo2Upload = saveDetailFile($files[$index]['photo_2'] ?? [], $uploadRoot, $relativeDir);

        $photo1 = $photo1Upload !== '' ? $photo1Upload : ($oldPhotos[$itemNo]['photo_1'] ?? '');
        $photo2 = $photo2Upload !== '' ? $photo2Upload : ($oldPhotos[$itemNo]['photo_2'] ?? '');

        if ($workTime === '' && $activity === '' && $description === '' && $status === '' && $photo1 === '' && $photo2 === '') {
            continue;
        }

        $insertDetail->execute([
            ':log_id' => $id,
            ':item_no' => $itemNo,
            ':work_time' => $workTime,
            ':activity' => $activity,
            ':description' => $description,
            ':status' => $status,
            ':photo_1' => $photo1,
            ':photo_2' => $photo2,
        ]);
    }

    $pdo->commit();
    header('Location: view.php?id=' . $id);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackException) {
            // rollback 실패 시 추가 처리 없이 무시
        }
    }

    http_response_code(500);
    echo '<h1>수정 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}
