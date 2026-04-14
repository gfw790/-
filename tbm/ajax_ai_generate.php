<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

// 오류를 JSON으로 반환
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'Fatal: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
        ], JSON_UNESCAPED_UNICODE);
    }
});

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/tbm_db.php';
require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/tbm_ai.php';

if (auth_current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = trim($_POST['date'] ?? '');
$forceNew = in_array(strtolower(trim((string)($_POST['force_new'] ?? '0'))), ['1','true','y','yes','on'], true);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => '날짜 형식이 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $content = tbm_ai_generate_content($date, $forceNew);

    echo json_encode([
        'edu_title'  => $content['edu_title'],
        'body_text'  => $content['body_text'],
        'quiz_1'     => $content['quiz_1'],
        'quiz_2'     => $content['quiz_2'],
        'quiz_3'     => $content['quiz_3'],
        'source_url' => $content['source_url'] ?? '',
        'image_file' => $content['image_file'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    // 에러 상세는 로그에만 기록
    error_log('[TBM AI AJAX] ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

    $errorResponse = [
        'error' => $e->getMessage(),
    ];

    // 개발 환경에서만 trace 포함 (XAMPP 등)
    $isDevEnv = (getenv('APP_ENV') === 'development')
             || (getenv('APP_DEBUG') === '1')
             || (PHP_SAPI === 'cli');

    if ($isDevEnv) {
        $errorResponse['file']  = basename($e->getFile()) . ':' . $e->getLine();
        $errorResponse['trace'] = substr($e->getTraceAsString(), 0, 500);
    }

    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}
