<?php
// OpenAI API를 이용한 자연어 사고사례 질의 응답
require_once __DIR__ . '/env.php';
header('Content-Type: application/json; charset=UTF-8');

$q = trim($_POST['q'] ?? '');
$cases = $_POST['cases'] ?? '';
if ($q === '' || $cases === '') {
    echo json_encode(['error' => '질문과 사례 데이터가 필요합니다.']);
    exit;
}

$apiKey = env_get('OPENAI_API_KEY');
if (!$apiKey) {
    echo json_encode(['error' => 'OpenAI API 키가 없습니다.']);
    exit;
}

// 프롬프트 구성: 사고사례 목록을 테이블로 요약
$prompt = "아래는 사고사례 목록입니다. 사용자가 자연어로 질문하면, 해당 사고사례에서 답을 찾아 한국어로 간결하게 답변하세요.\n";
$prompt .= "사고사례 목록:\n" . $cases . "\n\n질문: $q\n답변:";

$payload = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => '너는 사고사례 데이터베이스에서 정보를 찾아주는 한국어 비서야.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 256,
    'temperature' => 0.2
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode(['error' => 'OpenAI API 호출 실패', 'detail' => $response]);
    exit;
}

$data = json_decode($response, true);
$answer = $data['choices'][0]['message']['content'] ?? '';
echo json_encode(['answer' => $answer]);
