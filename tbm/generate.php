<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Seoul');

define('TBM_ROOT', __DIR__);
define('TBM_OUTPUT_DIR', TBM_ROOT . '/output');

require_once TBM_ROOT . '/tbm_db.php';
require_once TBM_ROOT . '/tbm_ai.php';
require_once TBM_ROOT . '/tbm_functions.php';
require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/phpqrcode/qrlib.php';

if (auth_current_user() === null) {
    header('Location: ../risk_assessment/task_select.php');
    exit;
}

$raUser = auth_current_user();
$isOperator = auth_is_admin($raUser) || ((string)($raUser['role'] ?? '') === 'safety_manager');
$data = tbm_request_data();
$uploadError = null;

if ($isOperator) {
    try {
        $uploadedImageFile = tbm_store_uploaded_manual_image(
            $_FILES['image_upload'] ?? null,
            (string)($data['doc_date'] ?? '')
        );
        if ($uploadedImageFile !== null) {
            $data['image_file'] = $uploadedImageFile;
        }
    } catch (Throwable $e) {
        $uploadError = $e->getMessage();
    }
}

$postedTeam = trim((string)($_POST['selected_team'] ?? ''));
if ($isOperator && $postedTeam === '공통') {
    $documentTeam = '';
    $returnTeam = '공통';
} elseif ($postedTeam !== '') {
    $documentTeam = tbm_normalize_display_team_name(auth_normalize_team_name($postedTeam));
    $returnTeam = $postedTeam;
} else {
    $documentTeam = tbm_normalize_display_team_name(auth_normalize_team_name((string)($raUser['team'] ?? '')));
    $returnTeam = $documentTeam;
}

$data['names'] = tbm_resolve_attendee_names($data, $returnTeam, $raUser);

$validationPayload = tbm_ai_autofit_parsed_response([
    'body_text' => trim((string)($data['left_content'] ?? '')),
    'quiz_1' => trim((string)($data['quiz_1'] ?? '')),
    'quiz_2' => trim((string)($data['quiz_2'] ?? '')),
    'quiz_3' => trim((string)($data['quiz_3'] ?? '')),
]);
$validation = tbm_ai_validate_parsed_response($validationPayload);
$validationError = null;

if ($validation['valid']) {
    $data['left_content'] = (string)$validationPayload['body_text'];
    $data['quiz_1'] = (string)$validationPayload['quiz_1'];
    $data['quiz_2'] = (string)$validationPayload['quiz_2'];
    $data['quiz_3'] = (string)$validationPayload['quiz_3'];
} else {
    $validationError = implode(' ', $validation['errors']);
}

if (!is_dir(TBM_OUTPUT_DIR)) {
    mkdir(TBM_OUTPUT_DIR, 0777, true);
}

$dbError = null;
$renderError = null;
$docId = 0;
$sharedDocId = 0;
$outputRelativePath = '';
$outputUrl = '';
$fileName = '';

if ($validationError === null) {
    try {
        $pdo = tbm_db();

        $stmt = $pdo->prepare('SELECT id FROM tbm_instructors WHERE name = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$data['instructor_name']]);
        $instructorId = (int)($stmt->fetchColumn() ?: 1);

        $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
        $existingDoc = tbm_get_document_for_team($data['doc_date'], $documentTeam);

        if ($existingDoc) {
            $savedTeam = tbm_normalize_display_team_name(
                auth_normalize_team_name((string)($existingDoc['team'] ?? ''))
            );
            if ($savedTeam !== '') {
                $documentTeam = $savedTeam;
            }
        }

        if ($sourceUrl === '') {
            $fallbackDoc = tbm_get_document($data['doc_date']);
            $sourceUrl = trim((string)($fallbackDoc['source_url'] ?? ''));
        }

        $contentPayload = [
            'accident_date' => null,
            'accident_title' => $data['edu_title'],
            'edu_title' => $data['edu_title'],
            'body_text' => $data['left_content'],
            'image_file' => $data['image_file'],
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'quiz_1' => $data['quiz_1'] ?? '',
            'quiz_2' => $data['quiz_2'] ?? '',
            'quiz_3' => $data['quiz_3'] ?? '',
            'ai_generated' => 0,
        ];
        $contentId = tbm_insert_content($contentPayload);

        if ($existingDoc) {
            $docId = (int)$existingDoc['id'];
        } else {
            $docId = tbm_create_document($data['doc_date'], $instructorId, $contentId, $documentTeam);
            tbm_link_document_members($docId, tbm_get_active_members());
        }

        $stmt = $pdo->prepare(
            'UPDATE tbm_documents
                SET team = :team,
                    instructor_id = :iid,
                    content_id = :cid,
                    today_work_1 = :tw1,
                    today_work_2 = :tw2,
                    risk_checks = :checks,
                    risk_rows = :rows,
                    remarks = :remarks,
                    generation_status = "pending",
                    updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':team' => $documentTeam,
            ':iid' => $instructorId,
            ':cid' => $contentId,
            ':tw1' => $data['today_work_1'],
            ':tw2' => $data['today_work_2'],
            ':checks' => json_encode($data['risk_checks'], JSON_UNESCAPED_UNICODE),
            ':rows' => json_encode($data['risk_rows'], JSON_UNESCAPED_UNICODE),
            ':remarks' => $data['remarks'],
            ':id' => $docId,
        ]);

        if ($isOperator && $documentTeam !== '') {
            $sharedDocId = tbm_sync_shared_document_content($data['doc_date'], $instructorId, $contentId);
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
        error_log('[TBM generate] DB 오류: ' . $e->getMessage());
    }
}

if ($validationError !== null) {
    $renderError = '길이 검증 실패: ' . $validationError;
} elseif ($dbError === null) {
    try {
        $htmlContent = tbm_render_template($data);
        $fileName = 'tbm_' . date('Ymd_His') . '.html';
        [, $outputDir] = tbm_prepare_output_directory($documentTeam);
        $outputRelativePath = tbm_build_output_relative_path($fileName, $documentTeam);
        $outputPath = $outputDir . '/' . $fileName;

        if (file_put_contents($outputPath, $htmlContent) === false) {
            throw new RuntimeException('파일 저장 실패: ' . $outputPath);
        }

        $outputUrl = 'view_output.php?file=' . rawurlencode($outputRelativePath);

        if ($docId > 0) {
            tbm_update_document_result($docId, $outputRelativePath, 'success');
            tbm_log($docId, 'manual', 'success', '수동 생성: ' . $outputRelativePath);
        }
        if ($sharedDocId > 0 && $sharedDocId !== $docId) {
            tbm_update_document_result($sharedDocId, $outputRelativePath, 'success');
        }
    } catch (Throwable $e) {
        $renderError = $e->getMessage();
        if ($docId > 0) {
            tbm_update_document_result($docId, '', 'failed', $e->getMessage());
            tbm_log($docId, 'manual', 'failed', $e->getMessage());
        }
        if ($sharedDocId > 0 && $sharedDocId !== $docId) {
            tbm_update_document_result($sharedDocId, '', 'failed', $e->getMessage());
        }
    }
}

if (!function_exists('h')) {
    function h(string $v): string
    {
        return e($v);
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>TBM 생성 결과</title>
<style>
body { font-family: "Malgun Gothic", sans-serif; margin: 30px; line-height: 1.7; }
.box { border: 1px solid #ccc; padding: 24px; max-width: 700px; border-radius: 4px; }
h2 { margin-top: 0; }
.ok { color: #1a7f37; font-weight: bold; }
.err { color: #cf222e; font-weight: bold; }
a { color: #0b57d0; text-decoration: none; }
a:hover { text-decoration: underline; }
.warn { background: #fff8c5; border: 1px solid #d4a017; padding: 8px 12px; border-radius: 4px; margin-top: 12px; font-size: .9em; }
</style>
</head>
<body>
<div class="box">
    <h2>TBM 문서 생성 결과</h2>
    <?php if ($renderError): ?>
        <p class="err">HTML 생성 실패: <?= h($renderError) ?></p>
    <?php else: ?>
        <p class="ok">HTML 생성 완료</p>
        <p><strong>작업일자:</strong> <?= h($data['doc_date']) ?></p>
        <p><strong>교육내용:</strong> <?= h($data['edu_title']) ?></p>
        <p><strong>생성파일:</strong> <?= h($outputRelativePath !== '' ? $outputRelativePath : $fileName) ?></p>
        <p><a href="<?= h($outputUrl) ?>" target="_blank">생성된 문서 열기</a></p>
    <?php endif; ?>

    <?php if ($dbError): ?>
        <div class="warn">DB 저장 오류가 발생했습니다.<br><?= h($dbError) ?></div>
    <?php elseif ($uploadError): ?>
        <div class="warn">사진 업로드는 반영되지 않았습니다.<br><?= h($uploadError) ?></div>
    <?php elseif ($renderError === null): ?>
        <p style="color:#666;font-size:.9em;">DB 저장 완료 (doc_id: <?= (int)$docId ?>)</p>
    <?php endif; ?>

    <p><a href="index.php?team=<?= h(rawurlencode($returnTeam)) ?>">다시 입력하기</a></p>
</div>
</body>
</html>
