<?php
declare(strict_types=1);
require_once __DIR__ . '/../risk_assessment/auth.php';
$user = auth_current_user();
if (!is_array($user)) { header('Location: /risk_assessment/task_select.php'); exit; }
if (!auth_can_manage($user) || trim((string)($user['login_id'] ?? '')) !== '5878') {
    http_response_code(403);
    exit('<!doctype html><html lang="ko"><meta charset="utf-8"><title>권한이 없습니다</title><p>이 페이지는 지정된 관리자 계정만 사용할 수 있습니다.</p></html>');
}
require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/review_storage.php';
require_once __DIR__ . '/review_sources.php';
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$year = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$previewOnly=($_GET['preview']??'')==='1';$blankPreview=$previewOnly&&($_GET['blank']??'')==='1';
if($previewOnly){
    $requestedYear=filter_var($_GET['year']??$year,FILTER_VALIDATE_INT);
    if($requestedYear===false||$requestedYear<1000||$requestedYear>9999||$_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(400);exit('올바른 연도를 선택해 주세요.');}
    $year=$requestedYear;
}
$items = safety_review_items(); $values = safety_review_empty(); $revision = 0; $history = []; $error = '';
$message = $_SESSION['safety_review_message'] ?? '';
unset($_SESSION['safety_review_message']);
$_SESSION['safety_review_csrf'] ??= bin2hex(random_bytes(32));
$action = $_GET['action'] ?? '';
try {
    $db = getDB(); safety_review_initialize($db);
    if($action==='sources'){
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['policy_text'=>safety_review_source_text($db,$year)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if ($action === 'history') {
        header('Content-Type: application/json; charset=UTF-8');
        $sourceYear = filter_var($_GET['year'] ?? '', FILTER_VALIDATE_INT);
        if (!$sourceYear || $sourceYear < 1000 || $sourceYear >= $year) throw new InvalidArgumentException('지난 연도를 선택해 주세요.');
        $record = safety_review_load($db, $sourceYear);
        if (!$record) { http_response_code(404); echo json_encode(['error' => '해당 연도의 저장된 보고서가 없습니다.'], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    $record = $blankPreview?null:safety_review_load($db, $year);
    if($previewOnly&&!$blankPreview&&!$record)throw new InvalidArgumentException($year.'년의 저장된 검토 보고서가 없습니다.');
    if ($record) { $values = $record; $revision = $record['revision']; }
    if(!$previewOnly&&trim($values['policy_text'])==='')$values['policy_text']=safety_review_source_text($db,$year);
    $stmt = $db->prepare('SELECT review_year FROM safety_policy_reviews WHERE review_year < ? ORDER BY review_year DESC');
    $stmt->execute([$year]); $history = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['safety_review_csrf'], $_POST['csrf'])) throw new InvalidArgumentException('저장 요청을 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
        if ((string)($_POST['year'] ?? '') !== (string)$year) throw new InvalidArgumentException('연도가 변경되었습니다. 새로고침 후 당해년도에 작성해 주세요.');
        $postedRevision = filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT);
        if ($postedRevision === false || $postedRevision < 0) throw new InvalidArgumentException('보고서 버전을 확인할 수 없습니다.');
        $values = safety_review_validate($_POST); $revision = $postedRevision;
        safety_review_save($db, $year, $values, $revision, (string)$user['login_id']);
        $_SESSION['safety_review_message'] = $year . '년 검토 보고서를 저장했습니다.';
        header('Location: policy_review.php'); exit;
    }
} catch (Throwable $exception) {
    $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'DB에서 보고서를 불러오거나 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    if (!$exception instanceof InvalidArgumentException) error_log('Safety review: ' . $exception->getMessage());
    if (in_array($action,['history','sources'],true)) { http_response_code($exception instanceof InvalidArgumentException ? 400 : 500); header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (is_string($_POST['policy_text'] ?? null)) $values['policy_text'] = $_POST['policy_text'];
        if (is_string($_POST['review_date'] ?? null)) $values['review_date'] = $_POST['review_date'];
        foreach ($items as $i => $_label) {
            $posted = $_POST['items'][$i] ?? null;
            if (is_array($posted)) $values['items'][$i] = ['status' => in_array($posted['status'] ?? null, ['', '적합', '보통', '부적합'], true) ? $posted['status'] : '', 'note' => is_string($posted['note'] ?? null) ? $posted['note'] : ''];
        }
    }
}
if($previewOnly&&$error!==''){http_response_code($exception instanceof InvalidArgumentException?404:500);echo '<!doctype html><html lang="ko"><meta charset="utf-8"><p role="alert">'.h($error).'</p></html>';exit;}
?>
<!doctype html><html lang="ko"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>안전보건방침 및 목표 검토 보고서</title><link rel="stylesheet" href="assets/policy-review.css?v=<?= filemtime(__DIR__.'/assets/policy-review.css') ?>">
</head><body data-preview-only="<?= $previewOnly&&$error===''?'1':'0' ?>">
<header><div><small>SAFETY MANAGEMENT SYSTEM</small><h1>안전보건방침 및 목표 검토 보고서</h1></div></header>
<main>
    <div class="toolbar"><strong><?= $year ?>년 보고서</strong>
        <div class="history-tools"><label for="history-year">지난 연도 조회</label>
            <select id="history-year" <?= !$history ? 'disabled' : '' ?>>
                <?php if (!$history): ?><option>저장된 지난 연도 보고서 없음</option><?php endif; ?>
                <?php foreach ($history as $pastYear): ?><option value="<?= (int)$pastYear ?>"><?= (int)$pastYear ?>년</option><?php endforeach; ?>
            </select><button type="button" id="history-open" <?= !$history ? 'disabled' : '' ?>>조회</button>
        </div>
    </div>
    <div id="review-message" role="status" class="notice <?= $error !== '' ? 'error' : '' ?>" <?= $error === '' && $message === '' ? 'hidden' : '' ?>><?= h($error ?: $message) ?></div>
    <form id="review-form" method="post" action="policy_review.php" data-year="<?= $year ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['safety_review_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="revision" value="<?= $revision ?>">
        <div class="toolbar"><label for="review-date">검토일자</label><input type="date" id="review-date" name="review_date" value="<?= h($values['review_date']??'') ?>"></div>
        <section class="editor-card">
            <div class="policy-editor"><label for="review-policy">검토 대상 안전보건방침 및 목표</label><p class="help">해당 연도의 안전보건 경영방침에 저장된 방침 내용과 목표를 불러옵니다. 인쇄 양식 왼쪽 영역에 표시됩니다.</p>
                <input type="hidden" id="review-policy" name="policy_text" value="<?= h($values['policy_text']) ?>">
                <div id="review-policy-output" class="policy-output" role="region" aria-label="검토 대상 안전보건방침 및 목표"><?= nl2br(h($values['policy_text'])) ?></div>
            </div>
            <div class="review-items"><h2>방침 유효성 검토 내용</h2>
                <?php foreach ($items as $i => $label): ?>
                    <fieldset class="review-item"><legend><?= $i + 1 ?>. <?= h($label) ?></legend>
                        <div class="review-item-fields"><div><label for="status-<?= $i ?>">적합유무</label><select id="status-<?= $i ?>" name="items[<?= $i ?>][status]">
                            <?php foreach (['' => '선택', '적합' => '적합', '보통' => '보통', '부적합' => '부적합'] as $value => $text): ?><option value="<?= h($value) ?>" <?= $values['items'][$i]['status'] === $value ? 'selected' : '' ?>><?= h($text) ?></option><?php endforeach; ?>
                        </select></div><div><label for="note-<?= $i ?>">비고</label><textarea id="note-<?= $i ?>" name="items[<?= $i ?>][note]" rows="2" maxlength="2000"><?= h($values['items'][$i]['note']) ?></textarea></div></div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </section>
        <div class="actions"><a class="button secondary" href="management_policy.php">경영방침 만들기로 돌아가기</a><button type="submit" id="review-save">저장하기</button><button type="button" id="review-print-open">인쇄 미리보기</button></div>
    </form>
</main>
<dialog id="history-dialog" aria-labelledby="history-title"><div class="dialog-toolbar"><h2 id="history-title">지난 연도 검토 보고서</h2><button type="button" id="history-copy">당해년도 입력란에 불러오기</button><button type="button" class="secondary" id="history-close">닫기</button></div><div id="history-content"></div></dialog>
<dialog id="review-print-dialog" aria-labelledby="print-title"><div class="dialog-toolbar"><h2 id="print-title">인쇄 미리보기</h2><button type="button" id="review-print">인쇄 / PDF 저장</button><button type="button" class="secondary" id="print-close">닫기</button><p id="print-overflow" role="alert" hidden>내용이 한 장을 초과합니다. 본문 또는 비고 내용을 줄여 주세요.</p></div><div id="review-print-pages"></div></dialog>
<script id="review-labels" type="application/json"><?= json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/policy-review.js?v=<?= filemtime(__DIR__.'/assets/policy-review.js') ?>"></script>
</body></html>
