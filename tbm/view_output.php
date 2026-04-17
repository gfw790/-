<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/tbm_functions.php';

if (auth_current_user() === null) {
    header('Location: ../risk_assessment/task_select.php');
    exit;
}

$file = tbm_normalize_output_relative_path((string)($_GET['file'] ?? ''));
if ($file === '') {
    http_response_code(400);
    exit('파일명이 없습니다.');
}

$fullPath = tbm_resolve_output_full_path($file);
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('파일을 찾을 수 없습니다.');
}

[$basePath] = explode('?', $_SERVER['REQUEST_URI'] ?? 'view_output.php', 2);
$baseHref = rtrim(dirname($basePath), '/\\');
if ($baseHref === '.' || $baseHref === '') {
    $baseHref = '';
}
$baseHref .= '/output/';

header('Content-Type: text/html; charset=utf-8');
$html = file_get_contents($fullPath);
if ($html === false) {
    http_response_code(500);
    exit('파일을 읽을 수 없습니다.');
}

if (stripos($html, '<base ') === false) {
    if (preg_match('~<head([^>]*)>~i', $html) === 1) {
        $html = preg_replace('~<head([^>]*)>~i', '<head$1><base href="' . htmlspecialchars($baseHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">', $html, 1);
    } else {
        $html = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $html;
    }
}

$printAssets = '<style>'
    . '.tbm-floating-print-btn{position:fixed;right:24px;bottom:24px;z-index:9999;display:inline-flex;align-items:center;justify-content:center;min-width:140px;padding:14px 18px;border:none;border-radius:999px;background:#0f172a;color:#fff;font-family:"Malgun Gothic",sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;box-shadow:0 12px 30px rgba(15,23,42,.24);}'
    . '.tbm-floating-print-btn:hover{background:#1e293b;}'
    . '.tbm-print-guide{position:fixed;right:24px;bottom:86px;z-index:9998;width:320px;padding:14px 16px;border:1px solid #bfdbfe;border-radius:10px;background:rgba(239,246,255,.97);box-shadow:0 10px 28px rgba(15,23,42,.14);font-family:"Malgun Gothic",sans-serif;font-size:13px;line-height:1.6;color:#1e293b;}'
    . '.tbm-print-guide strong{display:block;margin-bottom:6px;color:#1d4ed8;font-size:14px;}'
    . '@media print{.tbm-floating-print-btn{display:none !important;}}'
    . '@media print{.tbm-print-guide{display:none !important;}}'
    . '</style>';

if (stripos($html, 'tbm-floating-print-btn') === false) {
    if (preg_match('~</head>~i', $html) === 1) {
        $html = preg_replace('~</head>~i', $printAssets . '</head>', $html, 1);
    } else {
        $html = $printAssets . $html;
    }

    $printGuide = '<div class="tbm-print-guide"><strong>인쇄 요령</strong>A4 세로 기준으로 인쇄하시고 배율은 100%로 맞춰 주세요.<br>여백은 없음 또는 최소, 머리글/바닥글은 끔으로 설정하는 것을 권장합니다.<br>사진이나 배경이 보이지 않으면 인쇄 설정에서 배경 그래픽 출력을 켜고 다시 인쇄해 주세요.</div>';
    $printButton = '<button type="button" class="tbm-floating-print-btn" onclick="window.print()">🖨 인쇄하기</button>';
    if (preg_match('~</body>~i', $html) === 1) {
        $html = preg_replace('~</body>~i', $printGuide . $printButton . '</body>', $html, 1);
    } else {
        $html .= $printGuide . $printButton;
    }
}

header('Content-Length: ' . (string)strlen($html));
echo $html;
