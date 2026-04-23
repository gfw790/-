<?php
require_once __DIR__ . '/env.php';
header('Content-Type: application/json; charset=UTF-8');

$q     = trim($_POST['q']     ?? '');
$cases = trim($_POST['cases'] ?? '');

if ($q === '' || $cases === '') {
    echo json_encode(['error' => '질문과 사례 데이터가 필요합니다.']);
    exit;
}

$apiKey = env_get('GEMINI_API_KEY');
if (!$apiKey) {
    echo json_encode(['error' => '.env 파일에 GEMINI_API_KEY가 없습니다.']);
    exit;
}

$prompt = "아래 건설 사고사례 데이터를 바탕으로 사용자 질문에 한국어로 구체적으로 답변하세요.\n"
        . "사실에 기반하여 답하고, 데이터에 없는 내용은 추측하지 마세요.\n\n"
        . "=== 사고사례 데이터 ===\n{$cases}\n=== 끝 ===\n\n"
        . "질문: {$q}";

$url     = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";
$payload = [
    'contents' => [
        ['role' => 'user', 'parts' => [['text' => $prompt]]],
    ],
    'generationConfig' => [
        'temperature'     => 0.2,
        'maxOutputTokens' => 512,
    ],
    'systemInstruction' => [
        'parts' => [['text' => '당신은 건설 사고사례 데이터베이스에서 정보를 찾아주는 한국어 안전 전문 비서입니다.']],
    ],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
unset($ch);

if ($errno) {
    echo json_encode(['error' => "네트워크 오류: {$error}"]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $msg = $data['error']['message'] ?? $response;
    echo json_encode(['error' => "API 오류 ({$httpCode}): {$msg}"]);
    exit;
}

$answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
echo json_encode(['answer' => $answer]);
