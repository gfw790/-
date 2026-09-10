<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/cover_storage.php';

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

$_SESSION['cover_csrf'] ??= bin2hex(random_bytes(32));
$coverData = safety_cover_load_data();
$message = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['cover_csrf'], $csrf)) {
        $errorMessage = '요청을 확인하지 못했습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.';
    } else {
        $controlType = in_array((string)($_POST['control_type'] ?? ''), ['관리본', '비관리본'], true)
            ? (string)$_POST['control_type'] : '관리본';
        $documentNumber = trim((string)($_POST['document_number'] ?? ''));
        $establishedDate = trim((string)($_POST['established_date'] ?? ''));
        $revisionDate = trim((string)($_POST['revision_date'] ?? ''));
        $revisionCount = trim((string)($_POST['revision_count'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $previousContent = trim((string)($coverData['content'] ?? ''));
        $revisions = is_array($coverData['revisions'] ?? null) ? $coverData['revisions'] : [];

        if ($revisionDate !== '' && ($content !== $previousContent || $revisionCount !== (string)($coverData['revision_count'] ?? ''))) {
            array_unshift($revisions, [
                'revision_date' => $revisionDate,
                'revision_count' => $revisionCount,
                'content' => $content,
                'note' => '표지 본문 수정',
            ]);
        }

        $coverData = [
            'document_number' => $documentNumber,
            'established_date' => $establishedDate,
            'revision_date' => $revisionDate,
            'revision_count' => $revisionCount,
            'control_type' => $controlType,
            'content' => $content,
            'revisions' => $revisions,
        ];
        safety_cover_save_data($coverData);
        $message = '표지 정보와 개정 이력을 저장했습니다.';
    }
}

function cover_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>표지</title>
    <style>
        :root { --ink: #1f2937; --line: #d7e1ef; --paper: #fff; --page-bg: #f2f6fb; --blue: #17477f; --deep-blue: #103563; --muted: #63738a; }
        * { box-sizing: border-box; }
        @page { size: A4 portrait; margin: 0; }
        body { margin: 0; background: linear-gradient(180deg, #eaf1fa 0%, var(--page-bg) 260px); color: var(--ink); font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif; }
        .topbar { position: sticky; top: 0; z-index: 10; background: linear-gradient(180deg, var(--blue), var(--deep-blue)); color: #fff; border-bottom: 4px solid #0c274b; }
        .topbar-inner { max-width: 1480px; margin: 0 auto; padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .brand small { display: block; color: rgba(255,255,255,.75); font-size: 12px; letter-spacing: .08em; }
        .brand strong { display: block; margin-top: 4px; font-size: 24px; }
        .user-chip { padding: 9px 13px; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; font-size: 13px; }
        .toolbar { display: flex; align-items: center; gap: 8px; }
        .toolbar a, .toolbar button { border: 1px solid #b7c8de; border-radius: 9px; background: #fff; color: var(--blue); padding: 9px 13px; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar button { background: var(--blue); border-color: var(--blue); color: #fff; }
        .paper { width: auto; max-width: 1480px; min-height: 0; margin: 28px auto; padding: 0 20px 52px; background: transparent; border: 0; }
        .cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
        .card { min-width: 0; border: 1px solid var(--line); border-radius: 18px; padding: 28px 32px; background: var(--paper); box-shadow: 0 18px 38px rgba(21,45,83,.07); }
        .card h2 { margin: 0 0 22px; color: var(--deep-blue); font-size: 26px; }
        .control-options { display: flex; gap: 18px; margin-bottom: 5mm; }
        .control-options label { display: flex; align-items: center; gap: 6px; }
        .field { display: grid; gap: 6px; margin-bottom: 4mm; }
        .field label { display: block; margin-bottom: 9px; color: var(--deep-blue); font-weight: 700; }
        input, select, textarea { width: 100%; border: 1px solid #b9c9de; border-radius: 10px; padding: 10px 14px; color: #24364e; font: inherit; }
        textarea { min-height: 110px; resize: vertical; }
        .notice { margin: 0 0 20px; padding: 14px 18px; border-radius: 10px; background: #e7f4ed; color: #185a37; }
        .error { margin: 0 0 20px; padding: 14px 18px; border-radius: 10px; background: #fff0f0; color: #9b2626; }
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
        .revision-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12px; }
        .revision-table th, .revision-table td { border: 1px solid var(--line); padding: 7px; vertical-align: top; text-align: left; word-break: break-word; }
        .revision-table th { background: #dbe4f5; text-align: center; }
        .revision-table th:nth-child(1) { width: 22%; }
        .revision-table th:nth-child(2) { width: 18%; }
        .revision-table th:nth-child(4) { width: 18%; }
        .company-name { margin-top: 34mm; text-align: center; font-size: 19px; font-weight: 500; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 26px; }
        .actions button { min-height: 42px; padding: 0 16px; border: 1px solid var(--blue); border-radius: 9px; background: var(--blue); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .print-cover { display: none; }
        @media (max-width: 900px) { .cards { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .topbar-inner { align-items: flex-start; flex-direction: column; padding-left: 18px; padding-right: 18px; } .paper { padding-left: 18px; padding-right: 18px; } .card { padding: 24px 18px; } .card h2 { font-size: 22px; } .toolbar { width: 100%; } .toolbar a, .toolbar button { flex: 1; text-align: center; } }
        @media print { body { background: #fff; } .topbar, .toolbar, .actions, .notice, .error, .cards { display: none; } .paper { width: 210mm; min-height: 297mm; margin: 0; padding: 12mm 10mm; border: 1px solid #111; background: #fff; } .print-cover { display: block; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="index.php">매뉴얼로 돌아가기</a>
        <button type="button" onclick="window.print();">인쇄</button>
    </div>
    <main class="paper">
        <?php if ($message !== ''): ?><p class="notice"><?= cover_h($message) ?></p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="error"><?= cover_h($errorMessage) ?></p><?php endif; ?>
        <div class="cards">
            <section class="card editor-card">
                <h2>표지 정보 입력</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= cover_h((string)$_SESSION['cover_csrf']) ?>">
                    <div class="control-options">
                        <label><input type="radio" name="control_type" value="관리본" <?= ($coverData['control_type'] ?? '관리본') === '관리본' ? 'checked' : '' ?>> 관리본</label>
                        <label><input type="radio" name="control_type" value="비관리본" <?= ($coverData['control_type'] ?? '') === '비관리본' ? 'checked' : '' ?>> 비관리본</label>
                    </div>
                    <div class="field"><label for="document_number">문서번호</label><input id="document_number" name="document_number" value="<?= cover_h((string)($coverData['document_number'] ?? '')) ?>"></div>
                    <div class="field"><label for="established_date">제정일</label><input id="established_date" type="date" name="established_date" value="<?= cover_h((string)($coverData['established_date'] ?? '')) ?>"></div>
                    <div class="field"><label for="revision_date">개정일</label><input id="revision_date" type="date" name="revision_date" value="<?= cover_h((string)($coverData['revision_date'] ?? '')) ?>"></div>
                    <div class="field"><label for="revision_count">개정차수</label><input id="revision_count" name="revision_count" value="<?= cover_h((string)($coverData['revision_count'] ?? '')) ?>"></div>
                    <div class="field"><label for="content">본문</label><textarea id="content" name="content" placeholder="개정 내용을 입력하세요."><?= cover_h((string)($coverData['content'] ?? '')) ?></textarea></div>
                    <div class="actions"><button type="submit">저장</button></div>
                </form>
            </section>
            <section class="card history-card">
                <h2>개정목록</h2>
                <table class="revision-table" aria-label="개정목록">
                    <thead><tr><th>개정일</th><th>개정차수</th><th>개정내용</th><th>비고</th></tr></thead>
                    <tbody>
                    <?php foreach (($coverData['revisions'] ?? []) as $revision): ?>
                        <tr><td><?= cover_h((string)($revision['revision_date'] ?? '')) ?></td><td><?= cover_h((string)($revision['revision_count'] ?? '')) ?></td><td><?= nl2br(cover_h((string)($revision['content'] ?? ''))) ?></td><td><?= cover_h((string)($revision['note'] ?? '')) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (($coverData['revisions'] ?? []) === []): ?><tr><td colspan="4">개정 이력이 없습니다.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <section class="print-cover" aria-label="인쇄용 표지">
            <div class="control-line"><span class="checked"><?= ($coverData['control_type'] ?? '관리본') === '관리본' ? '■' : '□' ?></span> 관 리 본&nbsp;&nbsp;<?= ($coverData['control_type'] ?? '관리본') === '비관리본' ? '■' : '□' ?> 비관리본</div>
            <table class="info-table" aria-label="문서 정보">
                <tbody><tr><th>문서번호</th><td><?= cover_h((string)($coverData['document_number'] ?? '')) ?></td></tr><tr><th>제정일</th><td><?= cover_h((string)($coverData['established_date'] ?? '')) ?></td></tr><tr><th>개정일</th><td><?= cover_h((string)($coverData['revision_date'] ?? '')) ?></td></tr></tbody>
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
        </section>
    </main>
</body>
</html>