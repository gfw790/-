<?php
declare(strict_types=1);

// 1. 설정 및 함수 정의 파일 로드
require_once __DIR__ . '/tbm_siren.php';

// 2. 경로 설정 (반드시 / 슬래시를 사용하세요)
$originalImage = 'A:/risk_server/project/tbm/output/images/siren_76eaa22385b687a2.jpg';

echo "--- 진단 시작 ---\n";

// 진단 1: 파일 존재 여부
if (!file_exists($originalImage)) {
    die("실패: 파일을 찾을 수 없습니다. 경로를 확인하세요.\n입력된 경로: $originalImage\n");
}
echo "1. 파일 확인 완료\n";

// 진단 2: 이미지 읽기 권한 및 GD 라이브러리 작동
$imgInfo = @getimagesize($originalImage);
if (!$imgInfo) {
    die("실패: 이미지 정보를 읽을 수 없습니다. 유효한 이미지 파일이 아니거나 GD가 읽지 못합니다.\n");
}
echo "2. 이미지 정보 읽기 성공 (" . $imgInfo[0] . "x" . $imgInfo[1] . ")\n";

// 진단 3: 좌표 옵션 설정
$options = [
    'crop_main_image' => [
        'x' => 0.107,
        'y' => 0.457,
        'w' => 0.785,  // 테스트를 위해 전체 영역 지정 (1.0 = 100%)
        'h' => 0.335   
    ]
];

// 4. 함수 실행 및 상세 결과 확인
$resultPath = tbm_siren_crop_main_image($originalImage, $options);

if ($resultPath) {
    echo "--- 결과 ---\n";
    echo "성공! 추출된 파일: " . $resultPath . "\n";
} else {
    echo "--- 결과 ---\n";
    echo "실패: tbm_siren_crop_main_image 함수가 null을 반환했습니다.\n";
    echo "원인 추정: 함수 내부에서 imagecreatefromjpeg 등의 처리 중 오류 발생\n";
}