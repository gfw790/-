<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

$isAllowed = auth_can_manage($user)
    && trim((string)($user['login_id'] ?? '')) === '5878';

if (!$isAllowed) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>권한이 없습니다</title>
    </head>
    <body>
        <h1>권한이 없습니다</h1>
        <p>이 페이지는 지정된 관리자 계정만 사용할 수 있습니다.</p>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>목차제 표지</title>
    <style>
        :root { --ink: #111; --line: #222; --paper: #fff; --page-bg: #edf1f5; }
        * { box-sizing: border-box; }
        @page { size: A4 portrait; margin: 0; }
        body { margin: 0; background: var(--page-bg); color: var(--ink); font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif; }
        .toolbar { max-width: 210mm; margin: 16px auto 10px; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar a, .toolbar button { border: 1px solid #17477f; border-radius: 7px; background: #17477f; color: #fff; padding: 9px 13px; font: inherit; text-decoration: none; cursor: pointer; }
        .paper { width: 210mm; min-height: 297mm; margin: 0 auto 24px; padding: 12mm 10mm; background: var(--paper); border: 1px solid #111; }
        .control-line { margin: 8mm 0 14mm; text-align: center; font-size: 15px; letter-spacing: .08em; }
        .control-line .checked { font-size: 17px; margin-right: 12px; }
        .info-table, .approval-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .info-table { width: 58%; margin: 0 auto 22mm; }
        .info-table th, .info-table td, .approval-table th, .approval-table td { border: 1px solid var(--line); text-align: center; vertical-align: middle; }
        .info-table th { width: 50%; padding: 9px; background: #dbe4f5; font-weight: 500; }
        .info-table td { padding: 9px; }
        .approval-table { margin-top: 0; }
        .approval-table .approval-label { width: 20%; background: #d0d0d0; font-size: 18px; font-weight: 700; }
        .approval-table th { height: 16mm; background: #d0d0d0; font-size: 17px; letter-spacing: .18em; }
        .approval-table td { height: 18mm; }
        .company-name { margin-top: 34mm; text-align: center; font-size: 19px; font-weight: 500; }
        @media (max-width: 800px) { .paper { width: 100%; min-height: 100vh; } .toolbar { margin: 10px; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .paper { margin: 0; border: 1px solid #111; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="index.php">매뉴얼로 돌아가기</a>
        <button type="button" onclick="window.print();">인쇄</button>
    </div>
    <main class="paper">
        <div class="control-line"><span class="checked">■</span> 관 리 본&nbsp;&nbsp;□ 비관리본</div>
        <table class="info-table" aria-label="문서 정보">
            <tbody>
                <tr><th>문서번호</th><td></td></tr>
                <tr><th>제정일</th><td>2026. 0. 0.</td></tr>
            </tbody>
        </table>
        <table class="approval-table" aria-label="결재">
            <tbody>
                <tr>
                    <td class="approval-label" rowspan="3">결&nbsp;&nbsp;&nbsp;재</td>
                    <th>작&nbsp;&nbsp;&nbsp;성</th>
                    <th>검&nbsp;&nbsp;&nbsp;토</th>
                    <th>승&nbsp;&nbsp;&nbsp;인</th>
                </tr>
                <tr><td></td><td></td><td></td></tr>
                <tr><td></td><td></td><td></td></tr>
            </tbody>
        </table>
        <div class="company-name">주식회사 현대기전</div>
    </main>
</body>
</html>