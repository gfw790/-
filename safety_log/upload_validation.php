<?php

/**
 * 업로드된 이미지 파일을 검증합니다.
 *
 * @param array $file $_FILES 배열에서 추출된 단일 파일 정보
 * @return void
 * @throws RuntimeException 허용되지 않는 파일 유형 또는 용량을 초과한 경우
 */
function validateUploadedImage(array $file): void
{
    // 파일이 전달되지 않았거나 업로드되지 않은 경우에는 정상 처리합니다.
    if (empty($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return;
    }

    // 업로드 중에 발생한 오류를 먼저 확인합니다.
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('이미지 업로드 중 오류가 발생했습니다.');
    }

    // 파일 용량 제한: 5MB 이하
    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('파일 용량은 5MB 이하만 업로드할 수 있습니다.');
    }

    // 확장자 검증
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('이미지 파일만 업로드할 수 있습니다. (jpg, jpeg, png, webp)');
    }

    // MIME 타입 검증 - 실제 파일 내용을 기반으로 검사합니다.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('파일 MIME 타입을 확인할 수 없습니다.');
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if ($mimeType === false || !in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException('이미지 파일만 업로드할 수 있습니다. (jpg, jpeg, png, webp)');
    }
}
