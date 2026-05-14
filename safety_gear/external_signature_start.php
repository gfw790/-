<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/common.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

$pdo = sg_get_pdo();
$requestToken = sg_normalize_text($_GET['request'] ?? '');
$requestRow = sg_fetch_external_signature_request($pdo, $requestToken);
if ($requestRow === null) {
    http_response_code(404);
    echo '서명 요청을 찾을 수 없습니다.';
    exit;
}

if (sg_normalize_text($requestRow['signer_login_id'] ?? '') !== sg_normalize_text($user['login_id'] ?? '')) {
    http_response_code(403);
    echo '본인 서명 요청만 진행할 수 있습니다.';
    exit;
}

$config = sg_signature_config();
$provider = sg_normalize_text($config['provider'] ?? 'pass');
$enabled = !empty($config['enabled']);
$simulation = !empty($config['simulation']);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>외부 간편인증 서명 시작</title>
    <style>
        body { margin:0; font-family:"Malgun Gothic", sans-serif; background:#edf4f8; color:#132238; }
        .page { width:min(900px, calc(100vw - 24px)); margin:18px auto; display:grid; gap:18px; }
        .panel { background:#fff; border:1px solid #d7e0ea; border-radius:20px; box-shadow:0 12px 28px rgba(15,23,42,.08); padding:18px; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .button { display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0f766e; color:#fff; text-decoration:none; border:0; font:inherit; cursor:pointer; }
        .button.secondary { background:#e2e8f0; color:#0f172a; }
        .meta { color:#64748b; font-size:13px; line-height:1.7; }
        ul { line-height:1.8; }
        code { background:#f8fafc; padding:2px 6px; border-radius:8px; }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel">
            <h1>외부 간편인증 서명 시작</h1>
            <p class="meta">현재 요청 토큰은 <code><?= h($requestToken) ?></code> 이고, 선택된 보호구 <?= count((array)($requestRow['gear_uids'] ?? [])) ?>건에 대한 서명 요청입니다.</p>

            <ul>
                <li>서명자: <?= h(sg_normalize_text($requestRow['signer_name'] ?? '')) ?></li>
                <li>연동 대상: <?= h($provider !== '' ? strtoupper($provider) : 'PASS') ?></li>
                <li>실연동 사용 여부: <?= $enabled ? '활성' : '비활성' ?></li>
                <li>시뮬레이션 모드: <?= $simulation ? '활성' : '비활성' ?></li>
            </ul>

            <?php if ($enabled): ?>
                <p class="meta">여기에 PASS 실연동 요청 URL 생성과 리다이렉트 로직을 연결하면 됩니다. 현재는 테스트 환경이라 자동 리다이렉트는 넣지 않았습니다.</p>
            <?php else: ?>
                <p class="meta">현재는 실연동 키가 없어 테스트 모드로 구성되어 있습니다. 설정 파일은 <code>/safety_gear/signature_config.php</code> 입니다.</p>
            <?php endif; ?>

            <div class="actions">
                <?php if ($simulation): ?>
                    <a class="button" href="/safety_gear/external_signature_callback.php?request=<?= rawurlencode($requestToken) ?>&result=success">시뮬레이션 서명 완료</a>
                    <a class="button secondary" href="/safety_gear/external_signature_callback.php?request=<?= rawurlencode($requestToken) ?>&result=cancel">시뮬레이션 취소</a>
                <?php endif; ?>
                <a class="button secondary" href="/safety_gear/my_gear.php">나의 보호구로 돌아가기</a>
            </div>
        </section>
    </div>
</body>
</html>
