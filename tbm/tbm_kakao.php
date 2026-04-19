<?php
declare(strict_types=1);

function tbm_kakao_is_enabled(): bool
{
    $raw = strtolower(trim((string)(getenv('KAKAO_NOTIFY_ENABLED') ?: '0')));
    return in_array($raw, ['1', 'true', 'y', 'yes', 'on'], true);
}

function tbm_kakao_notify_on_success(): bool
{
    $raw = strtolower(trim((string)(getenv('KAKAO_NOTIFY_ON_SUCCESS') ?: '1')));
    return in_array($raw, ['1', 'true', 'y', 'yes', 'on'], true);
}

function tbm_kakao_notify_on_failure(): bool
{
    $raw = strtolower(trim((string)(getenv('KAKAO_NOTIFY_ON_FAILURE') ?: '1')));
    return in_array($raw, ['1', 'true', 'y', 'yes', 'on'], true);
}

function tbm_kakao_http_post_form(string $url, array $headers, array $data): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for Kakao notifications.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Kakao API request failed: ' . $error);
    }

    return [$status, (string)$body];
}

function tbm_kakao_set_env_value(string $key, string $value): void
{
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function tbm_kakao_persist_env_values(array $updates): void
{
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile) || $updates === []) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    $remaining = $updates;
    foreach ($lines as $index => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$name] = explode('=', $line, 2) + [''];
        $name = trim($name);
        if ($name === '' || !array_key_exists($name, $remaining)) {
            continue;
        }

        $lines[$index] = $name . '=' . $remaining[$name];
        unset($remaining[$name]);
    }

    foreach ($remaining as $name => $value) {
        $lines[] = $name . '=' . $value;
    }

    @file_put_contents($envFile, implode(PHP_EOL, $lines) . PHP_EOL);
}

function tbm_kakao_is_expired_token_error(string $message, array $json = [], int $status = 0): bool
{
    $normalized = strtolower(trim($message));
    if ($normalized === '') {
        $normalized = strtolower(trim((string)($json['msg'] ?? $json['message'] ?? '')));
    }

    if (str_contains($normalized, 'access token is already expired')) {
        return true;
    }

    if (str_contains($normalized, 'invalid_token') || str_contains($normalized, 'expired')) {
        return true;
    }

    return $status === 401;
}

function tbm_kakao_refresh_access_token(): string
{
    $restApiKey = trim((string)(getenv('KAKAO_REST_API_KEY') ?: ''));
    $refreshToken = trim((string)(getenv('KAKAO_REFRESH_TOKEN') ?: ''));
    $clientSecret = trim((string)(getenv('KAKAO_CLIENT_SECRET') ?: ''));

    if ($restApiKey === '' || $refreshToken === '') {
        throw new RuntimeException('KAKAO_REST_API_KEY or KAKAO_REFRESH_TOKEN is missing.');
    }

    $payload = [
        'grant_type' => 'refresh_token',
        'client_id' => $restApiKey,
        'refresh_token' => $refreshToken,
    ];

    if ($clientSecret !== '') {
        $payload['client_secret'] = $clientSecret;
    }

    [$status, $body] = tbm_kakao_http_post_form(
        'https://kauth.kakao.com/oauth/token',
        ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
        $payload
    );

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid Kakao token response: ' . $body);
    }

    if ($status < 200 || $status >= 300 || empty($json['access_token'])) {
        $message = trim((string)($json['error_description'] ?? $json['msg'] ?? $body));
        throw new RuntimeException('Kakao token refresh failed: ' . $message);
    }

    $newAccessToken = trim((string)$json['access_token']);
    $updates = [
        'KAKAO_ACCESS_TOKEN' => $newAccessToken,
    ];

    $newRefreshToken = trim((string)($json['refresh_token'] ?? ''));
    if ($newRefreshToken !== '') {
        $updates['KAKAO_REFRESH_TOKEN'] = $newRefreshToken;
    }

    foreach ($updates as $name => $value) {
        tbm_kakao_set_env_value($name, $value);
    }
    tbm_kakao_persist_env_values($updates);

    return $newAccessToken;
}

function tbm_kakao_resolve_access_token(bool $forceRefresh = false): string
{
    if ($forceRefresh) {
        return tbm_kakao_refresh_access_token();
    }

    $accessToken = trim((string)(getenv('KAKAO_ACCESS_TOKEN') ?: ''));
    if ($accessToken !== '') {
        return $accessToken;
    }

    return tbm_kakao_refresh_access_token();
}

function tbm_kakao_default_link_urls(): array
{
    $webUrl = trim((string)(getenv('KAKAO_MEMO_WEB_URL') ?: ''));
    $mobileUrl = trim((string)(getenv('KAKAO_MEMO_MOBILE_WEB_URL') ?: ''));

    if ($webUrl === '') {
        $webUrl = 'https://developers.kakao.com';
    }
    if ($mobileUrl === '') {
        $mobileUrl = $webUrl;
    }

    return [$webUrl, $mobileUrl];
}

function tbm_kakao_normalize_utf8_string(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8,CP949,EUC-KR,ISO-8859-1');
    if (is_string($converted) && $converted !== '') {
        return $converted;
    }

    $converted = @iconv('CP949', 'UTF-8//IGNORE', $value);
    if (is_string($converted) && $converted !== '') {
        return $converted;
    }

    return $value;
}

function tbm_kakao_compose_auto_message(string $status, array $context): string
{
    $date = trim(tbm_kakao_normalize_utf8_string((string)($context['date'] ?? '')));
    $title = trim(tbm_kakao_normalize_utf8_string((string)($context['title'] ?? '')));
    $outputFile = trim(tbm_kakao_normalize_utf8_string((string)($context['output_file'] ?? '')));
    $docId = (int)($context['doc_id'] ?? 0);
    $error = trim(tbm_kakao_normalize_utf8_string((string)($context['error'] ?? '')));

    if ($status === 'success') {
        $lines = [
            '[TBM 자동생성 완료]',
            '일자: ' . ($date !== '' ? $date : '-'),
            '제목: ' . ($title !== '' ? $title : '-'),
            '문서ID: ' . ($docId > 0 ? (string)$docId : '-'),
            '파일: ' . ($outputFile !== '' ? $outputFile : '-'),
        ];
    } else {
        $lines = [
            '[TBM 자동생성 실패]',
            '일자: ' . ($date !== '' ? $date : '-'),
            '오류: ' . ($error !== '' ? $error : '알 수 없는 오류'),
        ];
    }

    $message = implode("\n", $lines);
    if (mb_strlen($message, 'UTF-8') > 190) {
        $message = mb_substr($message, 0, 187, 'UTF-8') . '...';
    }

    return tbm_kakao_normalize_utf8_string($message);
}

function tbm_kakao_send_memo(string $message): void
{
    [$webUrl, $mobileUrl] = tbm_kakao_default_link_urls();
    $buttonTitle = trim(tbm_kakao_normalize_utf8_string((string)(getenv('KAKAO_NOTIFY_BUTTON_TITLE') ?: 'TBM 확인')));
    $message = tbm_kakao_normalize_utf8_string($message);

    $template = [
        'object_type' => 'text',
        'text' => $message,
        'link' => [
            'web_url' => $webUrl,
            'mobile_web_url' => $mobileUrl,
        ],
        'button_title' => $buttonTitle,
    ];

    $templateJson = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($templateJson === false) {
        throw new RuntimeException('Kakao memo payload encoding failed: ' . json_last_error_msg());
    }

    $attempt = 0;
    $forceRefresh = false;

    while ($attempt < 2) {
        $attempt++;
        $accessToken = tbm_kakao_resolve_access_token($forceRefresh);

        [$status, $body] = tbm_kakao_http_post_form(
            'https://kapi.kakao.com/v2/api/talk/memo/default/send',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/x-www-form-urlencoded;charset=utf-8',
            ],
            ['template_object' => $templateJson]
        );

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid Kakao memo response: ' . $body);
        }

        if ($status >= 200 && $status < 300 && (int)($json['result_code'] ?? -1) === 0) {
            return;
        }

        $errorMessage = trim((string)($json['msg'] ?? $json['message'] ?? $body));
        if (!$forceRefresh && tbm_kakao_is_expired_token_error($errorMessage, $json, $status)) {
            $forceRefresh = true;
            continue;
        }

        throw new RuntimeException('Kakao memo send failed: ' . $errorMessage);
    }

    throw new RuntimeException('Kakao memo send failed: access token refresh retry exhausted.');
}

function tbm_kakao_send_auto_report(string $status, array $context = []): void
{
    if (!tbm_kakao_is_enabled()) {
        return;
    }

    if ($status === 'success' && !tbm_kakao_notify_on_success()) {
        return;
    }

    if ($status !== 'success' && !tbm_kakao_notify_on_failure()) {
        return;
    }

    $message = tbm_kakao_compose_auto_message($status, $context);
    tbm_kakao_send_memo($message);
}