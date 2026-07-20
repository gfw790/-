<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$isSafetyManager = is_array($user) && (string)($user['role'] ?? '') === 'safety_manager';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function msds_storage_path(): string
{
    return __DIR__ . '/msds_records.json';
}

function msds_upload_dir(): string
{
    return __DIR__ . '/uploads/msds';
}

function msds_ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function msds_read_records(): array
{
    $path = msds_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function msds_write_records(array $records): bool
{
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) && file_put_contents(msds_storage_path(), $json, LOCK_EX) !== false;
}

function msds_normalize_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('Y-m-d', $timestamp);
}

function msds_normalize_multiline_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", trim($value));
    if ($value === '') {
        return '';
    }

    $lines = array_map(static function ($line) {
        return rtrim((string)$line);
    }, explode("\n", $value));

    return trim(implode("\n", $lines));
}

function msds_csv_escape(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function msds_record_extension(array $record): string
{
    $originalName = trim((string)($record['original_name'] ?? ''));
    $storedName = trim((string)($record['stored_name'] ?? ''));
    $candidate = $originalName !== '' ? $originalName : $storedName;
    return strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
}

function msds_find_record(array $records, string $id): ?array
{
    foreach ($records as $record) {
        if ((string)($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

function msds_find_record_index(array $records, string $id): ?int
{
    foreach ($records as $index => $record) {
        if ((string)($record['id'] ?? '') === $id) {
            return $index;
        }
    }

    return null;
}

function msds_remove_file_if_exists(string $storedName): void
{
    $storedName = basename($storedName);
    if ($storedName === '') {
        return;
    }

    $path = msds_upload_dir() . '/' . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}

function msds_ocr_script_path(): string
{
    return __DIR__ . '/scripts/msds_extract_text.py';
}

function msds_default_ocr_payload(string $status = 'pending', string $error = ''): array
{
    return [
        'ocr_status' => $status,
        'ocr_engine' => '',
        'ocr_text' => '',
        'ocr_sections' => [],
        'ocr_error' => $error,
        'ocr_updated_at' => date('c'),
    ];
}

function msds_extract_text_payload(string $pdfPath): array
{
    if (!is_file($pdfPath)) {
        return msds_default_ocr_payload('failed', 'PDF 파일을 찾을 수 없습니다.');
    }

    $scriptPath = msds_ocr_script_path();
    if (!is_file($scriptPath)) {
        return msds_default_ocr_payload('failed', 'OCR 추출 스크립트를 찾을 수 없습니다.');
    }

    $python = trim((string)getenv('MSDS_OCR_PYTHON'));
    if ($python === '') {
        $python = 'python';
    }

    $outputPath = tempnam(sys_get_temp_dir(), 'msds_ocr_');
    if ($outputPath === false) {
        return msds_default_ocr_payload('failed', 'OCR 임시 파일을 만들지 못했습니다.');
    }

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg($scriptPath)
        . ' --input ' . escapeshellarg($pdfPath)
        . ' --output ' . escapeshellarg($outputPath)
        . ' 2>&1';

    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);

    $payload = msds_default_ocr_payload('failed', 'OCR 추출 결과를 읽지 못했습니다.');
    if (is_file($outputPath)) {
        $decoded = json_decode((string)file_get_contents($outputPath), true);
        if (is_array($decoded)) {
            $status = (string)($decoded['status'] ?? 'failed');
            $payload = [
                'ocr_status' => $status,
                'ocr_engine' => (string)($decoded['engine'] ?? ''),
                'ocr_text' => (string)($decoded['text'] ?? ''),
                'ocr_sections' => is_array($decoded['sections'] ?? null) ? $decoded['sections'] : [],
                'ocr_error' => (string)($decoded['error'] ?? ''),
                'ocr_updated_at' => date('c'),
            ];
        }
        @unlink($outputPath);
    }

    if ($exitCode !== 0 && $payload['ocr_error'] === '') {
        $payload['ocr_error'] = trim(implode("\n", $outputLines)) ?: 'OCR 추출 실행에 실패했습니다.';
        $payload['ocr_status'] = 'failed';
    }

    return $payload;
}

$errors = [];
$successMessage = '';
$records = msds_read_records();

if (isset($_GET['download']) && $_GET['download'] === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="msds_upload_template.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('msds_csv_escape', ['물질명', '제조사', '작성일자', '개정일자', '개정횟수', '비고'])) . "\r\n";
    echo implode(',', array_map('msds_csv_escape', ['예시 물질명', '예시 제조사', date('Y-m-d'), date('Y-m-d'), '0', '예시 메모'])) . "\r\n";
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'list') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="msds_list_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('msds_csv_escape', ['번호', '물질명', '제조사', '작성일자', '개정일자', '개정횟수', '비고'])) . "\r\n";
    foreach (array_reverse($records) as $index => $record) {
        echo implode(',', array_map('msds_csv_escape', [
            (string)($index + 1),
            (string)($record['material_name'] ?? ''),
            (string)($record['manufacturer'] ?? ''),
            (string)($record['created_date'] ?? ''),
            (string)($record['revised_date'] ?? ''),
            (string)($record['revision_count'] ?? ''),
            (string)($record['note'] ?? ''),
        ])) . "\r\n";
    }
    exit;
}

if (isset($_GET['download_file'])) {
    $record = msds_find_record($records, trim((string)$_GET['download_file']));
    if ($record !== null) {
        $storedName = basename((string)($record['stored_name'] ?? ''));
        $originalName = trim((string)($record['original_name'] ?? ''));
        $path = msds_upload_dir() . '/' . $storedName;
        if ($storedName !== '' && is_file($path)) {
            $downloadName = $originalName !== '' ? $originalName : $storedName;
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . (string)filesize($path));
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
            readfile($path);
            exit;
        }
    }

    $errors[] = '????μ떜媛?걫??????癲????ル㎦??????????耀붾굝????????????????源낆┰?????????곸죩.';
}

if (isset($_GET['view_file'])) {
    $record = msds_find_record($records, trim((string)$_GET['view_file']));
    if ($record !== null && msds_record_extension($record) === 'pdf') {
        $storedName = basename((string)($record['stored_name'] ?? ''));
        $originalName = trim((string)($record['original_name'] ?? ''));
        $path = msds_upload_dir() . '/' . $storedName;
        if ($storedName !== '' && is_file($path)) {
            $viewName = $originalName !== '' ? $originalName : $storedName;
            header('Content-Type: application/pdf');
            header('Content-Length: ' . (string)filesize($path));
            header('Content-Disposition: inline; filename="' . rawurlencode($viewName) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    }

    $errors[] = 'PDF ???癲??????????용만??耀붾굝????????????????源낆┰?????????곸죩.';
}

$editingRecord = null;
$editingId = trim((string)($_GET['edit'] ?? ''));
if ($editingId !== '' && $isSafetyManager) {
    $editingRecord = msds_find_record($records, $editingId);
    if ($editingRecord === null) {
        $errors[] = '?????곌떽釉붾???MSDS ??????耀붾굝????????????????源낆┰?????????곸죩.';
    }
}

$formDefaults = [
    'material_name' => '',
    'manufacturer' => '',
    'created_date' => '',
    'revised_date' => '',
    'revision_count' => '',
    'note' => '',
    'mobile_content' => '',
];

if ($editingRecord !== null) {
    $formDefaults = [
        'material_name' => (string)($editingRecord['material_name'] ?? ''),
        'manufacturer' => (string)($editingRecord['manufacturer'] ?? ''),
        'created_date' => (string)($editingRecord['created_date'] ?? ''),
        'revised_date' => (string)($editingRecord['revised_date'] ?? ''),
        'revision_count' => (string)($editingRecord['revision_count'] ?? ''),
        'note' => (string)($editingRecord['note'] ?? ''),
        'mobile_content' => (string)($editingRecord['mobile_content'] ?? ''),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete_msds') {
        if (!$isSafetyManager) {
            $errors[] = 'MSDS ?????????μ떜媛?걫?繹먃??????怨멸텛??????얠뺏??????傭?끆??????????傭?끆??????レ춸??????ル뒌???????嚥〓끃異??????????곸죩.';
        } else {
            $targetId = trim((string)($_POST['record_id'] ?? ''));
            $existingIndex = msds_find_record_index($records, $targetId);
            if ($existingIndex === null) {
                $errors[] = '?????MSDS ??????耀붾굝????????????????源낆┰?????????곸죩.';
            } else {
                $existingRecord = $records[$existingIndex];
                msds_remove_file_if_exists((string)($existingRecord['stored_name'] ?? ''));
                array_splice($records, $existingIndex, 1);
                if (!msds_write_records($records)) {
                    $errors[] = 'MSDS ????????????뗫떔?????????ㅼ뒩??????????????곸죩.';
                } else {
                    header('Location: msds_list.php?deleted=1');
                    exit;
                }
            }
        }
    } elseif ($action === 'upload_msds' || $action === 'update_msds') {
        if (!$isSafetyManager) {
            $errors[] = 'MSDS ??????????????곌떽釉붾??? ????μ떜媛?걫?繹먃??????怨멸텛??????얠뺏??????傭?끆??????????傭?끆??????レ춸??????ル뒌???????嚥〓끃異??????????곸죩.';
        }

        $targetId = trim((string)($_POST['record_id'] ?? ''));
        $materialName = trim((string)($_POST['material_name'] ?? ''));
        $manufacturer = trim((string)($_POST['manufacturer'] ?? ''));
        $createdDate = msds_normalize_date((string)($_POST['created_date'] ?? ''));
        $revisedDate = msds_normalize_date((string)($_POST['revised_date'] ?? ''));
        $revisionCount = trim((string)($_POST['revision_count'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $mobileContent = msds_normalize_multiline_text((string)($_POST['mobile_content'] ?? ''));
        $uploadedFile = isset($_FILES['msds_file']) && is_array($_FILES['msds_file']) ? $_FILES['msds_file'] : null;
        $isUpdate = $action === 'update_msds';

        $formDefaults = [
            'material_name' => $materialName,
            'manufacturer' => $manufacturer,
            'created_date' => $createdDate,
            'revised_date' => $revisedDate,
            'revision_count' => $revisionCount,
            'note' => $note,
            'mobile_content' => $mobileContent,
        ];

        if ($materialName === '') {
            $errors[] = '??????ル뭸????ル쭔???⑥?뎅?꿔꺂??蹂?덩???????깅♥?????????쇨덫櫻????欲꼲????饔낅떽??????';
        }
        if ($manufacturer === '') {
            $errors[] = '???轅붽틓?????뮻???? ???????쇨덫櫻????欲꼲????饔낅떽??????';
        }
        if ($createdDate === '') {
            $errors[] = '????????關?쒎첎?嫄??ル竊숃눧?????????쇨덫櫻????欲꼲????饔낅떽??????';
        }
        if ($revisedDate === '') {
            $errors[] = '?????ル뒌??????關?쒎첎?嫄??ル竊숃눧?????????쇨덫櫻????欲꼲????饔낅떽??????';
        }
        if ($revisionCount === '') {
            $errors[] = '?????ル뒌???????????껉묻?????????쇨덫櫻????欲꼲????饔낅떽??????';
        } elseif (!preg_match('/^\d+$/', $revisionCount)) {
            $errors[] = '?????ル뒌???????????껉묻??????????ㅿ폍筌????????쇨덫櫻????欲꼲????饔낅떽??????';
        }

        $existingIndex = null;
        $existingRecord = null;
        if ($isUpdate) {
            $existingIndex = msds_find_record_index($records, $targetId);
            $existingRecord = $existingIndex !== null ? $records[$existingIndex] : null;
            if ($existingRecord === null) {
                $errors[] = '?????곌떽釉붾???MSDS ??????耀붾굝????????????????源낆┰?????????곸죩.';
            }
        } else {
            if ($uploadedFile === null || (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = '?????????????MSDS ??????????壤굿??Β?????欲꼲????饔낅떽??????';
            }
        }

        $newStoredName = null;
        $newOriginalName = null;
        if ($uploadedFile !== null && (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $tmpName = (string)($uploadedFile['tmp_name'] ?? '');
            $newOriginalName = trim((string)($uploadedFile['name'] ?? ''));
            $extension = strtolower(pathinfo($newOriginalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx', 'png', 'jpg', 'jpeg'];

            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $errors[] = '????????轅붽틓???壤굿?戮깅룾?????????饔낅떽??????????????????源낆┰?????????곸죩.';
            } elseif (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = '?耀붾굝??????????????????饔낅떽????????怨룸씔?? pdf, xlsx, xls, csv, doc, docx, png, jpg, jpeg ??????筌?캉??';
            } else {
                msds_ensure_directory(msds_upload_dir());
                $newStoredName = 'msds_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $newPath = msds_upload_dir() . '/' . $newStoredName;
                if (!move_uploaded_file($tmpName, $newPath)) {
                    $errors[] = '???????????????????????ㅼ뒩??????????????곸죩.';
                    $newStoredName = null;
                    $newOriginalName = null;
                }
            }
        }

        if (empty($errors)) {
            if ($isUpdate && $existingIndex !== null && $existingRecord !== null) {
                $updatedRecord = $existingRecord;
                $updatedRecord['material_name'] = $materialName;
                $updatedRecord['manufacturer'] = $manufacturer;
                $updatedRecord['created_date'] = $createdDate;
                $updatedRecord['revised_date'] = $revisedDate;
                $updatedRecord['revision_count'] = $revisionCount;
                $updatedRecord['note'] = $note;
                $updatedRecord['mobile_content'] = $mobileContent;
                $updatedRecord['updated_at'] = date('c');
                $updatedRecord['updated_by'] = (string)auth_display_name($user);

                if ($newStoredName !== null && $newOriginalName !== null) {
                    msds_remove_file_if_exists((string)($existingRecord['stored_name'] ?? ''));
                    $updatedRecord['stored_name'] = $newStoredName;
                    $updatedRecord['original_name'] = $newOriginalName;
                    if (strtolower(pathinfo($newOriginalName, PATHINFO_EXTENSION)) === 'pdf') {
                        $updatedRecord = array_merge(
                            $updatedRecord,
                            msds_extract_text_payload(msds_upload_dir() . '/' . $newStoredName)
                        );
                    } else {
                        $updatedRecord = array_merge($updatedRecord, msds_default_ocr_payload('skipped', 'PDF가 아닌 파일은 OCR 추출을 건너뜁니다.'));
                    }
                }

                $records[$existingIndex] = $updatedRecord;
                if (!msds_write_records($records)) {
                    if ($newStoredName !== null) {
                        msds_remove_file_if_exists($newStoredName);
                    }
                    $errors[] = 'MSDS ?????곌떽釉붾??????????뗫떔?????????ㅼ뒩??????????????곸죩.';
                } else {
                    header('Location: msds_list.php?updated=1');
                    exit;
                }
            } elseif (!$isUpdate) {
                $newRecord = [
                    'id' => uniqid('msds_', true),
                    'material_name' => $materialName,
                    'manufacturer' => $manufacturer,
                    'created_date' => $createdDate,
                    'revised_date' => $revisedDate,
                    'revision_count' => $revisionCount,
                    'note' => $note,
                    'mobile_content' => $mobileContent,
                    'stored_name' => (string)$newStoredName,
                    'original_name' => (string)$newOriginalName,
                    'uploaded_at' => date('c'),
                    'uploaded_by' => (string)auth_display_name($user),
                ];

                if ($newStoredName !== null && strtolower(pathinfo((string)$newOriginalName, PATHINFO_EXTENSION)) === 'pdf') {
                    $newRecord = array_merge(
                        $newRecord,
                        msds_extract_text_payload(msds_upload_dir() . '/' . $newStoredName)
                    );
                } else {
                    $newRecord = array_merge($newRecord, msds_default_ocr_payload('skipped', 'PDF가 아닌 파일은 OCR 추출을 건너뜁니다.'));
                }

                $records[] = $newRecord;

                if (!msds_write_records($records)) {
                    if ($newStoredName !== null) {
                        msds_remove_file_if_exists($newStoredName);
                    }
                    array_pop($records);
                    $errors[] = 'MSDS ?????롮쾸?椰???⑤챶猷??????????뗫떔?????????ㅼ뒩??????????????곸죩.';
                } else {
                    header('Location: msds_list.php?uploaded=1');
                    exit;
                }
            }
        }

        if (!empty($errors) && $newStoredName !== null) {
            msds_remove_file_if_exists($newStoredName);
        }
    }
}

if (isset($_GET['uploaded']) && $_GET['uploaded'] === '1') {
    $successMessage = 'MSDS ???????????롮쾸?椰???⑤챶猷???????????';
}
if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $successMessage = 'MSDS ??????????곌떽釉붾???????????';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $successMessage = 'MSDS ??????????????????';
}

$displayRows = array_reverse(msds_read_records());
$selectedMaterialId = trim((string)($_GET['material_id'] ?? ''));
$matchedMaterialId = '';
$materialOptions = [];
foreach ($displayRows as $record) {
    $recordId = trim((string)($record['id'] ?? ''));
    $materialName = trim((string)($record['material_name'] ?? ''));
    if ($recordId === '' || $materialName === '') {
        continue;
    }

    $materialOptions[] = [
        'id' => $recordId,
        'label' => $materialName,
    ];

    if ($selectedMaterialId !== '' && $recordId === $selectedMaterialId) {
        $matchedMaterialId = $recordId;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSDS LIST</title>
<style>
  * { box-sizing: border-box; }
  :root {
    --line: #d9e3ee;
    --text: #16324a;
    --muted: #678198;
    --primary: #175b8c;
    --primary-soft: #edf5fb;
    --accent: #ffb11a;
    --danger: #b73552;
    --shadow: 0 18px 36px rgba(18, 45, 70, 0.10);
  }
  body {
    margin: 0;
    min-height: 100vh;
    background: linear-gradient(180deg, #eef4fa 0%, #f9fbfd 100%);
    color: var(--text);
    font-family: "Malgun Gothic", sans-serif;
    padding: 18px 18px 24px;
  }
  .shell {
    width: 100%;
    display: grid;
    gap: 16px;
  }
  .hero,
  .panel {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 24px;
    box-shadow: var(--shadow);
  }
  .hero {
    padding: 22px;
    background: linear-gradient(135deg, #123c60 0%, #1d6597 100%);
    color: #ffffff;
  }
  .eyebrow {
    font-size: 12px;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.72);
    margin-bottom: 10px;
  }
  .hero h1 {
    margin: 0;
    font-size: 31px;
    line-height: 1.2;
  }
  .hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
  }
  .btn,
  button.btn,
  a.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 16px;
    border-radius: 14px;
    border: 1px solid transparent;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
  }
  .btn-primary {
    background: var(--accent);
    color: #17273a;
  }
  .btn-secondary {
    background: var(--primary-soft);
    border-color: #cfe0ef;
    color: var(--primary);
  }
  .btn-link {
    background: #ffffff;
    border-color: #d6e3ef;
    color: #1d405e;
  }
  .btn-danger {
    background: #fff2f4;
    border-color: #f1c8d1;
    color: var(--danger);
  }
  .panel {
    padding: 20px;
  }
  .panel-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 16px;
  }
  .panel-head h2 {
    margin: 0;
    font-size: 24px;
  }
  .panel-head p {
    margin: 6px 0 0;
    color: var(--muted);
    line-height: 1.6;
    font-size: 14px;
    word-break: keep-all;
  }
  .inline-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .feedback {
    border-radius: 16px;
    padding: 13px 14px;
    font-size: 14px;
  }
  .feedback.is-error {
    background: #fff2f4;
    color: var(--danger);
    border: 1px solid #f1c8d1;
  }
  .feedback.is-success {
    background: #eefaf3;
    color: #1f7a4d;
    border: 1px solid #cdebd8;
  }
  .upload-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .field {
    display: grid;
    gap: 7px;
  }
  .field.is-span-2 {
    grid-column: span 2;
  }
  .field.is-span-4 {
    grid-column: 1 / -1;
  }
  .field label {
    font-size: 13px;
    font-weight: 700;
  }
  .field input,
  .field textarea {
    width: 100%;
    min-height: 46px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid var(--line);
    background: #fbfdff;
    font: inherit;
    color: var(--text);
  }
  .field input[type="file"] {
    padding: 10px 12px;
  }
  .field textarea {
    min-height: 96px;
    resize: vertical;
  }
  .field-help {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
  }
  .upload-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 14px;
    flex-wrap: wrap;
  }
  .upload-actions form {
    margin: 0;
  }
  .table-mobile-caption {
    display: none;
    margin-top: 10px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
  }
  .mobile-search-panel {
    display: none;
    margin-top: 14px;
    padding: 14px;
    border: 1px solid var(--line);
    border-radius: 18px;
    background: #f8fbfe;
  }
  .mobile-search-form {
    display: grid;
    gap: 8px;
  }
  .mobile-search-form label {
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
  }
  .mobile-search-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
  }
  .mobile-search-input {
    width: 100%;
    min-height: 44px;
    padding: 11px 13px;
    border-radius: 14px;
    border: 1px solid var(--line);
    background: #ffffff;
    font: inherit;
    color: var(--text);
  }
  .mobile-search-hint {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
  }
  .table-wrap {
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: 18px;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    min-width: 980px;
    background: #ffffff;
  }
  thead th {
    background: #f1f6fb;
    color: #254663;
    font-size: 13px;
    padding: 14px 12px;
    text-align: left;
    border-bottom: 1px solid var(--line);
    white-space: nowrap;
  }
  tbody td {
    padding: 14px 12px;
    border-bottom: 1px solid #ebf1f6;
    font-size: 14px;
    vertical-align: middle;
    word-break: break-word;
  }
  tbody tr:last-child td {
    border-bottom: 0;
  }
  tbody tr.is-search-target {
    outline: 2px solid rgba(255, 177, 26, 0.7);
    outline-offset: -2px;
  }
  .empty-state {
    padding: 26px 20px;
    text-align: center;
    color: var(--muted);
    line-height: 1.7;
  }
  .note-cell {
    min-width: 320px;
  }
  .note-cell strong {
    display: block;
    margin-bottom: 8px;
  }
  .file-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .file-download,
  .file-view,
  .file-view-original,
  .file-edit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 0 12px;
    border-radius: 12px;
    border: 1px solid #cfe0ef;
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
  }
  .file-download {
    background: var(--primary-soft);
    color: var(--primary);
  }
  .file-view,
  .file-view-original,
  .file-edit {
    background: #ffffff;
    color: #1d405e;
  }
  .mobile-bottom-nav {
    display: none;
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: max(10px, env(safe-area-inset-bottom));
    z-index: 1000;
    border: 1px solid rgba(24, 59, 86, 0.10);
    border-radius: 20px;
    background: rgba(255,255,255,0.96);
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 40px rgba(17, 52, 77, 0.18);
    padding: 8px;
  }
  .mobile-bottom-nav-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
  }
  .mobile-nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    border-radius: 14px;
    border: 1px solid transparent;
    color: #45627b;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
  }
  .mobile-nav-link.is-active {
    background: linear-gradient(180deg, rgba(35,104,162,0.14), rgba(35,104,162,0.08));
    border-color: rgba(35,104,162,0.18);
    color: #17486f;
  }
  .mobile-nav-icon {
    font-size: 18px;
    line-height: 1;
  }
  .scroll-top-fab {
    display: none;
  }
  .scroll-top-fab {
    position: fixed;
    right: 16px;
    bottom: calc(max(10px, env(safe-area-inset-bottom)) + 92px);
    z-index: 1001;
    width: 48px;
    height: 48px;
    border: 1px solid rgba(255, 177, 26, 0.34);
    border-radius: 999px;
    background: rgba(255, 177, 26, 0.92);
    color: #111827;
    box-shadow: 0 16px 30px rgba(0,0,0,0.32);
    font: inherit;
    font-size: 18px;
    font-weight: 900;
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transform: translateY(8px);
    transition: opacity .18s ease, transform .18s ease;
  }
  .scroll-top-fab.is-visible {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
  }
  @media (max-width: 900px) {
    .upload-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 640px) {
    body {
      padding: 14px 12px 118px;
    }
    .hero,
    .panel {
      border-radius: 20px;
    }
    .hero {
      padding: 18px 16px;
    }
    .hero h1 {
      font-size: 22px;
      word-break: keep-all;
    }
    .panel {
      padding: 14px;
    }
    .panel-head {
      flex-direction: column;
    }
    .panel-head h2 {
      font-size: 20px;
    }
    .inline-actions,
    .hero-actions,
    .upload-actions {
      width: 100%;
    }
    .inline-actions .btn,
    .hero-actions .btn,
    .upload-actions .btn,
    .upload-actions form,
    .upload-actions form .btn {
      width: 100%;
    }
    .mobile-bottom-nav {
      display: block;
    }
    .scroll-top-fab {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .mobile-search-panel {
      display: block;
    }
    .table-mobile-caption {
      display: block;
    }
    .table-wrap {
      overflow: visible;
      border: 0;
      border-radius: 0;
    }
    table {
      min-width: 0;
      background: transparent;
    }
    thead {
      display: none;
    }
    tbody {
      display: grid;
      gap: 12px;
    }
    tbody tr {
      display: grid;
      gap: 8px;
      padding: 14px;
      background: #ffffff;
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: 0 10px 24px rgba(18, 45, 70, 0.08);
      scroll-margin-top: 18px;
    }
    tbody td {
      display: grid;
      grid-template-columns: 78px minmax(0, 1fr);
      gap: 10px;
      padding: 0;
      border-bottom: 0;
      font-size: 13px;
      align-items: start;
    }
    tbody td::before {
      content: attr(data-label);
      color: var(--muted);
      font-weight: 700;
      font-size: 12px;
    }
    .note-cell {
      min-width: 0;
    }
    .note-cell strong {
      margin-bottom: 6px;
    }
    .file-actions {
      display: grid;
      grid-template-columns: 1fr;
      gap: 6px;
    }
    .file-download,
    .file-view,
    .file-view-original,
    .file-edit {
      width: 100%;
      min-height: 40px;
      font-size: 12px;
    }
    .upload-grid {
      grid-template-columns: minmax(0, 1fr);
    }
    .field.is-span-2,
    .field.is-span-4 {
      grid-column: auto;
    }
  }
</style>
</head>
<body>
  <div class="shell">
    <section class="hero">
      <div class="eyebrow">MSDS</div>
      <h1>물질안전보건자료(MSDS) LIST</h1>
      <div class="hero-actions">
        <a class="btn btn-primary" href="work_list.php">작업목록으로 돌아가기</a>
      </div>
    </section>

    <?php if (!empty($errors)): ?>
      <section class="panel">
        <div class="feedback is-error"><?= h(implode(' ', $errors)) ?></div>
      </section>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
      <section class="panel">
        <div class="feedback is-success"><?= h($successMessage) ?></div>
      </section>
    <?php endif; ?>

    <?php if ($isSafetyManager): ?>
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2><?= $editingRecord !== null ? 'MSDS 수정' : 'MSDS 업로드' ?></h2>
            <p><?= $editingRecord !== null ? '기존 등록 정보를 수정하고 필요하면 새 파일로 교체할 수 있습니다.' : '새로운 MSDS 문서를 등록하고 목록에서 바로 열람하거나 다운로드할 수 있습니다.' ?></p>
          </div>
          <div class="inline-actions">
            <a class="btn btn-secondary" href="msds_list.php?download=template">업로드 양식 다운로드</a>
            <a class="btn btn-secondary" href="msds_list.php?download=list">현재 목록 CSV 다운로드</a>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="<?= $editingRecord !== null ? 'update_msds' : 'upload_msds' ?>">
          <?php if ($editingRecord !== null): ?>
            <input type="hidden" name="record_id" value="<?= h($editingRecord['id'] ?? '') ?>">
          <?php endif; ?>
          <div class="upload-grid">
            <div class="field">
              <label for="material_name">물질명</label>
              <input id="material_name" name="material_name" type="text" value="<?= h($formDefaults['material_name']) ?>" required>
            </div>
            <div class="field">
              <label for="manufacturer">제조사</label>
              <input id="manufacturer" name="manufacturer" type="text" value="<?= h($formDefaults['manufacturer']) ?>" required>
            </div>
            <div class="field">
              <label for="created_date">작성일자</label>
              <input id="created_date" name="created_date" type="date" value="<?= h($formDefaults['created_date']) ?>" required>
            </div>
            <div class="field">
              <label for="revised_date">개정일자</label>
              <input id="revised_date" name="revised_date" type="date" value="<?= h($formDefaults['revised_date']) ?>" required>
            </div>
            <div class="field">
              <label for="revision_count">개정횟수</label>
              <input id="revision_count" name="revision_count" type="number" min="0" step="1" value="<?= h($formDefaults['revision_count']) ?>" required>
            </div>
            <div class="field is-span-2">
              <label for="msds_file">MSDS 파일<?= $editingRecord !== null ? ' 교체' : '' ?></label>
              <input id="msds_file" name="msds_file" type="file" accept=".pdf,.xlsx,.xls,.csv,.doc,.docx,.png,.jpg,.jpeg" <?= $editingRecord === null ? 'required' : '' ?>>
              <?php if ($editingRecord !== null): ?>
                <div class="field-help">수정 시 새 파일을 올리면 기존 파일을 교체하고, 올리지 않으면 기존 파일을 유지합니다.</div>
              <?php endif; ?>
            </div>
            <div class="field is-span-4">
              <label for="note">비고</label>
              <textarea id="note" name="note" placeholder="현장 메모, 보관 위치, 주의사항 등을 적어두면 목록에서 함께 확인할 수 있습니다."><?= h($formDefaults['note']) ?></textarea>
            </div>
            <div class="field is-span-4">
              <label for="mobile_content">모바일 리더용 정리 본문</label>
              <textarea id="mobile_content" name="mobile_content" placeholder="모바일에서 크게 읽기 좋게 정리한 본문을 붙여넣으세요. 비워두면 PDF에서 자동 추출한 텍스트를 사용합니다."><?= h($formDefaults['mobile_content']) ?></textarea>
              <div class="field-help">줄바꿈은 그대로 유지됩니다. 항목 제목은 한 줄씩 구분해 두면 모바일 카드로 읽기 쉽게 보입니다.</div>
            </div>
          </div>
          <div class="upload-actions">
            <?php if ($editingRecord !== null): ?>
              <a class="btn btn-link" href="msds_list.php">수정 취소</a>
              <form method="post" onsubmit="return confirm('이 MSDS 항목을 삭제하시겠습니까?');">
                <input type="hidden" name="action" value="delete_msds">
                <input type="hidden" name="record_id" value="<?= h($editingRecord['id'] ?? '') ?>">
                <button class="btn btn-danger" type="submit">삭제</button>
              </form>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= $editingRecord !== null ? '수정 저장' : 'MSDS 업로드' ?></button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>물질안전보건(MSDS) LIST</h2>
          <p>번호, 물질명, 제조사, 작성일자, 개정일자, 개정횟수, 비고 정보를 한 번에 확인할 수 있습니다.</p>
        </div>
      </div>

      <div class="mobile-search-panel"></div>
      <div class="table-mobile-caption">모바일에서는 자료별 카드 형식으로 표시되며, 보기 버튼으로 읽기 전용 화면을 열 수 있습니다.</div>

      <div class="table-wrap">
        <?php if (empty($displayRows)): ?>
          <div class="empty-state">아직 등록된 MSDS가 없습니다. 안전관리자 계정에서 첫 자료를 등록해주세요.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>번호</th>
                <th>물질명</th>
                <th>제조사</th>
                <th>작성일자</th>
                <th>개정일자</th>
                <th>개정횟수</th>
                <th>비고</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($displayRows as $index => $record): ?>
                <?php $isPdf = msds_record_extension($record) === 'pdf'; ?>
                <tr<?= $matchedMaterialId !== '' && (string)($record['id'] ?? '') === $matchedMaterialId ? ' id="msds-search-target" class="is-search-target"' : '' ?>>
                  <td data-label="번호"><?= h($index + 1) ?></td>
                  <td data-label="물질명"><?= h($record['material_name'] ?? '') ?></td>
                  <td data-label="제조사"><?= h($record['manufacturer'] ?? '') ?></td>
                  <td data-label="작성일자"><?= h($record['created_date'] ?? '') ?></td>
                  <td data-label="개정일자"><?= h($record['revised_date'] ?? '') ?></td>
                  <td data-label="개정횟수"><?= h($record['revision_count'] ?? '') ?></td>
                  <td class="note-cell" data-label="비고">
                    <?php if (trim((string)($record['note'] ?? '')) !== ''): ?>
                      <strong><?= h($record['note'] ?? '') ?></strong>
                    <?php endif; ?>
                    <div class="file-actions">
                      <?php if ($isPdf): ?>
                        <a class="file-view" href="msds_reader.php?id=<?= h($record['id'] ?? '') ?>">보기</a>
                        <a class="file-view-original" href="msds_list.php?view_file=<?= h($record['id'] ?? '') ?>" target="_blank" rel="noopener">원본보기</a>
                      <?php endif; ?>
                      <a class="file-download" href="msds_list.php?download_file=<?= h($record['id'] ?? '') ?>">다운로드</a>
                      <?php if ($isSafetyManager): ?>
                        <a class="file-edit" href="msds_list.php?edit=<?= h($record['id'] ?? '') ?>">수정</a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <nav class="mobile-bottom-nav" aria-label="모바일 하단 메뉴">
    <div class="mobile-bottom-nav-grid">
      <a class="mobile-nav-link" href="index.php">
        <span class="mobile-nav-icon">⌂</span>
        <span>홈</span>
      </a>
      <a class="mobile-nav-link" href="../calendar/index.html">
        <span class="mobile-nav-icon">◫</span>
        <span>달력</span>
      </a>
      <a class="mobile-nav-link" href="work_list.php">
        <span class="mobile-nav-icon">▤</span>
        <span>작업목록</span>
      </a>
      <a class="mobile-nav-link is-active" href="msds_list.php">
        <span class="mobile-nav-icon">⌘</span>
        <span>MSDS</span>
      </a>
      <a class="mobile-nav-link" href="more.php">
        <span class="mobile-nav-icon">⋯</span>
        <span>더보기</span>
      </a>
    </div>
  </nav>

  <button type="button" class="scroll-top-fab" id="scroll-top-fab" aria-label="맨 위로 이동">↑</button>

  <script>
    (function () {
      var panel = document.querySelector('.mobile-search-panel');
      if (panel) {
        var options = <?= json_encode($materialOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var selectedId = <?= json_encode($selectedMaterialId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var optionHtml = ['<option value="">물질을 선택하면 해당 항목으로 자동 이동합니다</option>'];

        options.forEach(function (option) {
          var selected = selectedId === option.id ? ' selected' : '';
          optionHtml.push('<option value="' + option.id + '"' + selected + '>' + option.label + '</option>');
        });

        panel.innerHTML = ''
          + '<form class="mobile-search-form" method="get">'
          + '  <label for="material_id">물질 빠른 찾기</label>'
          + '  <div class="mobile-search-row">'
          + '    <select id="material_id" name="material_id" class="mobile-search-input" onchange="this.form.submit()">'
          + optionHtml.join('')
          + '    </select>'
          + '    <a class="btn btn-link" href="msds_list.php">초기화</a>'
          + '  </div>'
          + '  <div class="mobile-search-hint">드롭다운에서 물질명을 고르면 해당 MSDS 카드 위치로 자동 이동합니다.</div>'
          + '</form>';
      }

      var target = document.getElementById('msds-search-target');
      if (target) {
        window.requestAnimationFrame(function () {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }

      var scrollTopFab = document.getElementById('scroll-top-fab');
      if (scrollTopFab) {
        var syncScrollTopFab = function () {
          if (!window.matchMedia('(max-width: 640px)').matches) {
            scrollTopFab.classList.remove('is-visible');
            return;
          }

          scrollTopFab.classList.toggle('is-visible', window.scrollY > 240);
        };

        scrollTopFab.addEventListener('click', function () {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        window.addEventListener('scroll', syncScrollTopFab, { passive: true });
        window.addEventListener('resize', syncScrollTopFab);
        syncScrollTopFab();
      }
    }());
  </script>
</body>
</html>
