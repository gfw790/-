<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Seoul');

define('TBM_ROOT',       __DIR__);
define('TBM_OUTPUT_DIR', TBM_ROOT . '/output');

require_once TBM_ROOT . '/tbm_db.php';
require_once TBM_ROOT . '/tbm_functions.php';
require_once TBM_ROOT . '/auth.php';
require_once __DIR__ . '/phpqrcode/qrlib.php';

tbm_auth_require_login();

$data = tbm_request_data();

if (!is_dir(TBM_OUTPUT_DIR)) {
    mkdir(TBM_OUTPUT_DIR, 0777, true);
}

$dbError = null;
$docId   = 0;

try {
    $pdo = tbm_db();

    $stmt = $pdo->prepare('SELECT id FROM tbm_instructors WHERE name = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$data['instructor_name']]);
    $instructorId = (int)($stmt->fetchColumn() ?: 1);
    
    $sourceUrl = trim($_POST['source_url'] ?? '');
    
    if ($sourceUrl === '') {
        $existingDoc = tbm_get_document($data['doc_date']);
        $sourceUrl = trim($existingDoc['source_url'] ?? '');
    }

    // quiz_1 / quiz_2 / quiz_3 키로 저장
    $contentPayload = [
        'accident_date'  => null,
        'accident_title' => $data['edu_title'],
        'edu_title'      => $data['edu_title'],
        'body_text'      => $data['left_content'],
        'image_file'     => $data['image_file'],
        'source_url'     => $sourceUrl !== '' ? $sourceUrl : null,
        'quiz_1'         => $data['quiz_1'] ?? '',
        'quiz_2'         => $data['quiz_2'] ?? '',
        'quiz_3'         => $data['quiz_3'] ?? '',
        'ai_generated'   => 0,
    ];
    $contentId = tbm_insert_content($contentPayload);

    $docDate = $data['doc_date'];

    if (tbm_document_exists($docDate)) {
        $stmt = $pdo->prepare(
            'UPDATE tbm_documents
                SET instructor_id=:iid, content_id=:cid,
                    today_work_1=:tw1, today_work_2=:tw2,
                    risk_checks=:checks, risk_rows=:rows,
                    remarks=:remarks, generation_status="pending", updated_at=NOW()
              WHERE doc_date=:doc_date'
        );
        $stmt->execute([
            ':iid'      => $instructorId, ':cid'   => $contentId,
            ':tw1'      => $data['today_work_1'], ':tw2' => $data['today_work_2'],
            ':checks'   => json_encode($data['risk_checks'], JSON_UNESCAPED_UNICODE),
            ':rows'     => json_encode($data['risk_rows'],   JSON_UNESCAPED_UNICODE),
            ':remarks'  => $data['remarks'], ':doc_date' => $docDate,
        ]);
        $s2 = $pdo->prepare('SELECT id FROM tbm_documents WHERE doc_date=? LIMIT 1');
        $s2->execute([$docDate]);
        $docId = (int)$s2->fetchColumn();
    } else {
        $docId = tbm_create_document($docDate, $instructorId, $contentId);
        $stmt = $pdo->prepare(
            'UPDATE tbm_documents SET today_work_1=:tw1, today_work_2=:tw2,
             risk_checks=:checks, risk_rows=:rows, remarks=:remarks WHERE id=:id'
        );
        $stmt->execute([
            ':tw1'=>$data['today_work_1'], ':tw2'=>$data['today_work_2'],
            ':checks'=>json_encode($data['risk_checks'], JSON_UNESCAPED_UNICODE),
            ':rows'=>json_encode($data['risk_rows'], JSON_UNESCAPED_UNICODE),
            ':remarks'=>$data['remarks'], ':id'=>$docId,
        ]);
        tbm_link_document_members($docId, tbm_get_active_members());
    }

} catch (Throwable $e) {
    $dbError = $e->getMessage();
    error_log('[TBM generate] DB 오류: ' . $e->getMessage());
}

$renderError = null;
$fileName    = '';
$outputUrl   = '';

try {
    $htmlContent = tbm_render_template($data);
    $fileName    = 'tbm_' . date('Ymd_His') . '.html';
    $outputPath  = TBM_OUTPUT_DIR . '/' . $fileName;

    if (file_put_contents($outputPath, $htmlContent) === false) {
        throw new RuntimeException('파일 저장 실패: ' . $outputPath);
    }
    $outputUrl = 'view_output.php?file=' . rawurlencode($fileName);

    if ($docId > 0) {
        tbm_update_document_result($docId, $fileName, 'success');
        tbm_log($docId, 'manual', 'success', "수동 생성: {$fileName}");
    }
} catch (Throwable $e) {
    $renderError = $e->getMessage();
    if ($docId > 0) {
        tbm_update_document_result($docId, '', 'failed', $e->getMessage());
        tbm_log($docId, 'manual', 'failed', $e->getMessage());
    }
}

// tbm_functions.php의 e()와 동일. 템플릿에서 h()로 사용 중이므로 래퍼 유지.
if (!function_exists('h')) {
    function h(string $v): string {
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
body{font-family:"Malgun Gothic",sans-serif;margin:30px;line-height:1.7;}
.box{border:1px solid #ccc;padding:24px;max-width:700px;border-radius:4px;}
h2{margin-top:0;}.ok{color:#1a7f37;font-weight:bold;}.err{color:#cf222e;font-weight:bold;}
a{color:#0b57d0;text-decoration:none;}a:hover{text-decoration:underline;}
.warn{background:#fff8c5;border:1px solid #d4a017;padding:8px 12px;border-radius:4px;margin-top:12px;font-size:.9em;}
</style>
</head>
<body>
<div class="box">
    <h2>TBM 문서 생성 결과</h2>
    <?php if ($renderError): ?>
        <p class="err">❌ HTML 생성 실패: <?= h($renderError) ?></p>
    <?php else: ?>
        <p class="ok">✅ HTML 생성 완료</p>
        <p><strong>작업일자:</strong> <?= h($data['doc_date']) ?></p>
        <p><strong>교육내용:</strong> <?= h($data['edu_title']) ?></p>
        <p><strong>생성파일:</strong> <?= h($fileName) ?></p>
        <p><a href="<?= h($outputUrl) ?>" target="_blank">📄 생성된 문서 열기</a></p>
    <?php endif; ?>
    <?php if ($dbError): ?>
        <div class="warn">⚠️ DB 저장 오류 (HTML은 생성됨):<br><?= h($dbError) ?></div>
    <?php else: ?>
        <p style="color:#666;font-size:.9em;">✔ DB 저장 완료 (doc_id: <?= (int)$docId ?>)</p>
    <?php endif; ?>
    <p><a href="index.php">← 다시 입력하기</a></p>
</div>
</body>
</html>
