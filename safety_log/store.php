<?php
require_once __DIR__ . '/../risk_assessment/db_config.php';

function h($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function restructureFilesArray(array $files): array
{
    $result = [];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    foreach ($files['name'] as $index => $fieldValues) {
        foreach ($fieldValues as $fieldName => $value) {
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

function saveUploadedFile(array $file, string $uploadDir): string
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return '';
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = pathinfo($file['name'], PATHINFO_FILENAME);
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    $uniqueName = sprintf('%s_%s.%s', $filename, uniqid('', true), $extension ?: 'dat');
    $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uniqueName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $targetPath;
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$logDate = trim($_POST['log_date'] ?? '');
$managerName = trim($_POST['manager_name'] ?? '');
$siteName = trim($_POST['site_name'] ?? '');
$workLocation = trim($_POST['work_location'] ?? '');
$weather = trim($_POST['weather'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$summary = trim($_POST['summary'] ?? '');
$remark = trim($_POST['remark'] ?? '');
$details = $_POST['details'] ?? [];
$files = restructureFilesArray($_FILES['details'] ?? []);

try {
    $pdo = getDB();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS safety_manager_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_date DATE,
            manager_name VARCHAR(255),
            site_name VARCHAR(255),
            work_location VARCHAR(255),
            weather VARCHAR(255),
            subject VARCHAR(255),
            summary TEXT,
            remark TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS safety_manager_log_detail (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_id INT UNSIGNED NOT NULL,
            item_no INT UNSIGNED,
            work_time VARCHAR(100),
            activity VARCHAR(255),
            description TEXT,
            status VARCHAR(50),
            photo_1 VARCHAR(500),
            photo_2 VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (log_id),
            FOREIGN KEY (log_id) REFERENCES safety_manager_log(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $transactionStarted = $pdo->beginTransaction();

    if (!$transactionStarted) {
        throw new RuntimeException('트랜잭션을 시작할 수 없습니다.');
    }

    $insertLog = $pdo->prepare(
        'INSERT INTO safety_manager_log (log_date, manager_name, site_name, work_location, weather, subject, summary, remark)
         VALUES (:log_date, :manager_name, :site_name, :work_location, :weather, :subject, :summary, :remark)'
    );
    $insertLog->execute([
        ':log_date' => $logDate,
        ':manager_name' => $managerName,
        ':site_name' => $siteName,
        ':work_location' => $workLocation,
        ':weather' => $weather,
        ':subject' => $subject,
        ':summary' => $summary,
        ':remark' => $remark,
    ]);

    $logId = (int)$pdo->lastInsertId();
    $insertDetail = $pdo->prepare(
        'INSERT INTO safety_manager_log_detail (log_id, item_no, work_time, activity, description, status, photo_1, photo_2)
         VALUES (:log_id, :item_no, :work_time, :activity, :description, :status, :photo_1, :photo_2)'
    );
    $uploadDir = 'A:\\risk_server\\uploads\\2026';

    foreach ($details as $index => $detail) {
        $workTime = trim($detail['work_time'] ?? '');
        $activity = trim($detail['activity'] ?? '');
        $description = trim($detail['description'] ?? '');
        $status = trim($detail['status'] ?? '');

        if ($workTime === '' && $activity === '' && $description === '' && $status === '') {
            continue;
        }

        $photo1 = saveUploadedFile($files[$index]['photo_1'] ?? [], $uploadDir);
        $photo2 = saveUploadedFile($files[$index]['photo_2'] ?? [], $uploadDir);

        $insertDetail->execute([
            ':log_id' => $logId,
            ':item_no' => $index + 1,
            ':work_time' => $workTime,
            ':activity' => $activity,
            ':description' => $description,
            ':status' => $status,
            ':photo_1' => $photo1,
            ':photo_2' => $photo2,
        ]);
    }

    $pdo->commit();
    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && isset($transactionStarted) && $transactionStarted && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackException) {
            // ignore rollback failure because we are already handling an error
        }
    }

    http_response_code(500);
    echo '<h1>저장 중 오류가 발생했습니다.</h1>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}
