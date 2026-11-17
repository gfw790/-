<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/risk_procedure_document_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_POST['year'] ?? $_GET['year'] ?? $today->format('Y'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100) { http_response_code(400); exit('평가 연도를 확인해 주세요.'); }
$canManage = auth_can_manage($user);
$db = csm_ra_procedure_db();
csm_ra_document_schema($db);
$_SESSION['csm_ra_procedure_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$notice = $_SESSION['csm_ra_procedure_notice'] ?? '';
unset($_SESSION['csm_ra_procedure_notice']);

if (isset($_GET['file'])) {
    $id = filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $query = $db->prepare('SELECT * FROM csm_ra_procedure_files WHERE id=? AND year=? AND deleted_at IS NULL');
    $query->execute([$id ?: 0, $year]);
    $file = $query->fetch();
    $path = $file ? csm_ra_procedure_dir() . '/' . $file['stored_name'] : '';
    if (!$file || !preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', (string)$file['stored_name']) || !is_file($path)) {
        http_response_code(404); exit('첨부문서를 찾을 수 없습니다.');
    }
    $inline = in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $file['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="evidence"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canManage) throw new InvalidArgumentException('증빙과 평가를 변경할 권한이 없습니다.');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_ra_procedure_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침해 주세요.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upload') {
            csm_ra_procedure_upload($db, $year, (string)($_POST['kind'] ?? ''), $_FILES['evidence'] ?? [], (string)$user['login_id']);
            $_SESSION['csm_ra_procedure_notice'] = '근거 자료를 첨부했습니다.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !csm_ra_procedure_delete($db, $year, $id)) throw new InvalidArgumentException('삭제할 문서를 찾을 수 없습니다.');
            $_SESSION['csm_ra_procedure_notice'] = '첨부문서를 삭제했습니다. 반영 점수를 다시 확인해 주세요.';
        } elseif ($action === 'assess') {
            csm_ra_procedure_save_assessment($db, $year, (string)($_POST['level'] ?? ''), (string)($_POST['note'] ?? ''), (string)$user['login_id']);
            $_SESSION['csm_ra_procedure_notice'] = '2.1.2 평가 수준을 저장했습니다.';
        } else throw new InvalidArgumentException('요청한 작업을 확인해 주세요.');
        header('Location: /CSM/risk_procedure.php?year=' . $year . '#evidence', true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : '저장 중 오류가 발생했습니다.';
        if (!($exception instanceof InvalidArgumentException)) error_log('CSM risk procedure: ' . $exception->getMessage());
    }
}

$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$module = array_values(array_filter($catalog['modules'], static fn(array $entry): bool => $entry['id'] === '2.1'))[0];
$criterion = array_values(array_filter($module['items'], static fn(array $entry): bool => $entry['id'] === '2.1.2'))[0];
$kinds = csm_ra_procedure_kinds();
$files = csm_ra_procedure_files($db, $year);
$score = csm_ra_procedure_summary($db, $year);
$assessment = csm_ra_procedure_assessment($db, $year);
$document = csm_ra_document($db, $year);
$issuedDocument = csm_ra_document_issued($db, $year);
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>2.1.2 위험성평가 규정·절차 · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="risk_procedure.css?v=<?= (int)filemtime(__DIR__ . '/risk_procedure.css') ?>"></head><body>
<header><div><a href="/CSM/area.php?group=2&amp;section=2.1">← 위험성평가·재해예방</a><strong>2.1.2 위험성평가 규정·절차</strong><span><?= $h($user['name'] ?? '') ?></span></div></header>
<main>
  <div class="heading"><div><small>CSM 안전보건활동평가 · 2.1.2 · 3점</small><h1><?= $h($criterion['title']) ?></h1><p>규정·절차, 연 2회 점검·조치 보고, 근로자 참여 근거를 한곳에서 확인합니다.</p></div><a class="back-link" href="/CSM/">CSM 한눈에 보기 →</a></div>
  <?php if ($error): ?><p class="rp-alert error" role="alert"><?= $h($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="rp-alert success" role="status"><?= $h($notice) ?></p><?php endif; ?>
  <section class="panel rp-year"><form method="get"><label>평가 연도 <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>" required></label><button>연도 조회</button></form></section>
  <section class="panel rp-overview"><div class="section-title"><h2>2.1.2 확인 현황</h2><strong>현재 점수 <?= number_format($score['earned'], 1) ?> / 3점</strong></div><div class="rp-status"><?php foreach ($kinds as $kind => $info): ?><a href="#<?= $h($kind) ?>"><span><?= $h($info['title']) ?></span><strong><?= $score['counts'][$kind] ?>개</strong></a><?php endforeach; ?></div><p class="hint">원본 평가기준: <?= $h(str_replace("\n", ' · ', $criterion['evidence'])) ?>. 파일 첨부만으로 평가수준을 확정하지 않습니다. 문서 내용을 확인하고 아래에서 평가를 저장하세요.</p></section>
  <section class="rp-links"><a href="/CSM/risk_procedure_document.php?year=<?= $year ?>">위험성평가 실시규정 편집·개정이력 →</a><a href="/risk_assessment/" target="_blank" rel="noopener">위험성평가 시스템 ↗</a><a href="/safety_manual/risk_assessment_report.php?year=<?= $year ?>&amp;half=상반기" target="_blank" rel="noopener">상반기 평가보고서 ↗</a><a href="/safety_manual/risk_assessment_report.php?year=<?= $year ?>&amp;half=하반기" target="_blank" rel="noopener">하반기 평가보고서 ↗</a><a href="/risk_assessment/hazard_review.php" target="_blank" rel="noopener">참여자 있는 수시평가 열람 ↗</a></section>
  <div id="evidence" class="rp-grid"><?php foreach ($kinds as $kind => $info): ?><section class="panel rp-evidence" id="<?= $h($kind) ?>"><div class="section-title"><h2><?= $h($info['title']) ?></h2><span><?= $score['counts'][$kind] ?>개 근거</span></div><p class="hint"><?= $h($info['help']) ?></p>
    <?php if ($kind === 'procedure' && $document): ?><p class="rp-authored"><strong>작성 절차서 · <?= $document['status'] === 'issued' ? '발행본' : '초안' ?></strong><a href="risk_procedure_document.php?year=<?= $year ?>">편집·보기 →</a><?php if ($issuedDocument): ?><a href="risk_procedure_document.php?year=<?= $year ?>&amp;print=1&amp;issued=1" target="_blank" rel="noopener">최근 발행본 ↗</a><?php endif; ?></p><?php endif; ?>
    <?php if (!$files[$kind] && !($kind === 'procedure' && $document)): ?><p class="rp-empty">첨부된 문서가 없습니다.</p><?php elseif ($files[$kind]): ?><ul class="rp-files"><?php foreach ($files[$kind] as $file): $url = '/CSM/risk_procedure.php?year=' . $year . '&file=' . (int)$file['id']; ?><li><div><strong><?= $h($file['original_name']) ?></strong><small><?= $h($file['uploaded_at']) ?> · <?= number_format((int)$file['size'] / 1024) ?> KB</small></div><a href="<?= $h($url) ?>" target="_blank" rel="noopener"><?= in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true) ? '문서보기' : '다운로드' ?></a><?php if ($canManage): ?><form method="post" onsubmit="return confirm('이 첨부문서를 삭제할까요?')"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_ra_procedure_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>"><button name="action" value="delete">삭제</button></form><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($canManage): ?><form method="post" enctype="multipart/form-data" class="rp-upload"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_ra_procedure_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="kind" value="<?= $h($kind) ?>"><label>근거 자료 선택 <input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx" required></label><button name="action" value="upload">이 항목에 첨부</button><small>PDF · JPG · PNG · XLSX · DOCX, 15MB 이하</small></form><?php endif; ?>
  </section><?php endforeach; ?></div>
  <section class="panel rp-assessment"><div class="section-title"><h2>증빙 검토 후 평가 수준</h2><span><?= $assessment ? '마지막 저장 ' . $h($assessment['updated_at']) : '미평가' ?></span></div><p class="hint">규정·절차의 적정성과 상·하반기 보고 및 근로자 참여를 확인해 원본 평가수준을 선택합니다. 유효한 첨부파일이 모두 삭제되면 점수는 0점입니다.</p><?php if ($canManage): ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_ra_procedure_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><label>평가 수준 <select name="level" required><option value="">선택</option><?php foreach ($criterion['criteria'] as $level => $label): if (!is_numeric($level)) continue; ?><option value="<?= $h($level) ?>" <?= $assessment && (float)$assessment['multiplier'] === (float)$level ? 'selected' : '' ?>><?= $h(str_replace("\n", ' ', $label)) ?> · <?= number_format(3 * (float)$level, 1) ?>점</option><?php endforeach; ?></select></label><label>검토 메모 <textarea name="note" rows="3" placeholder="확인한 규정·보고·근로자 참여 증빙과 보완할 사항을 적어 주세요."><?= $h($assessment['note'] ?? '') ?></textarea></label><button name="action" value="assess">평가 수준 저장</button></form><?php elseif ($assessment): ?><p><?= nl2br($h($assessment['note'])) ?></p><?php endif; ?></section>
</main></body></html>
