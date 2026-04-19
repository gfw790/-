<?php
declare(strict_types=1);

$code = trim((string)($_GET['code'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$errorDescription = trim((string)($_GET['error_description'] ?? ''));

?><!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kakao OAuth Callback</title>
    <style>
        body {
            font-family: "Malgun Gothic", sans-serif;
            margin: 0;
            background: #f5f7fb;
            color: #1f2937;
        }
        .wrap {
            max-width: 760px;
            margin: 48px auto;
            padding: 24px;
        }
        .card {
            background: #fff;
            border: 1px solid #dbe2ea;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        h1 {
            margin-top: 0;
            font-size: 28px;
        }
        .ok {
            color: #166534;
        }
        .error {
            color: #991b1b;
        }
        .codebox {
            margin-top: 16px;
            padding: 16px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            word-break: break-all;
            font-family: Consolas, monospace;
            font-size: 14px;
        }
        .hint {
            margin-top: 16px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Kakao OAuth Callback</h1>

            <?php if ($code !== ''): ?>
                <p class="ok">인가 코드가 정상적으로 도착했습니다.</p>
                <div class="codebox"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="hint">
                    위 코드를 복사해서 access token / refresh token 발급 단계에 사용하면 됩니다.<br>
                    Redirect URI는 이 주소로 등록하세요: <strong>http://localhost/tbm/kakao_callback.php</strong>
                </div>
            <?php elseif ($error !== ''): ?>
                <p class="error">카카오 인증 중 오류가 반환되었습니다.</p>
                <div class="codebox">
                    error=<?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?><br>
                    error_description=<?php echo htmlspecialchars($errorDescription, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <p>이 페이지는 카카오 로그인 Redirect URI 테스트용입니다.</p>
                <div class="hint">
                    직접 여는 주소가 아니라, 카카오 로그인 후 돌아오는 콜백 주소로 사용하세요.<br>
                    등록할 Redirect URI: <strong>http://localhost/tbm/kakao_callback.php</strong>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>