<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tbm_activity_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_POST['year'] ?? $_GET['year'] ?? $today->format('Y'), FILTER_VALIDATE_INT);
$month = filter_var($_POST['month'] ?? $_GET['month'] ?? $today->format('n'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100 || !$month || $month < 1 || $month > 12) {
    http_response_code(400);
    exit('연도와 월을 확인해 주세요.');
}
$canManage = auth_can_manage($user);
$db = csm_tbm_db();
$_SESSION['csm_tbm_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$notice = $_SESSION['csm_tbm_notice'] ?? '';
unset($_SESSION['csm_tbm_notice']);

if (isset($_GET['file'])) {
    $id = filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $query = $db->prepare('SELECT * FROM csm_tbm_evidence WHERE id=? AND year=? AND deleted_at IS NULL');
    $query->execute([$id ?: 0, $year]);
    $file = $query->fetch();
    $path = $file ? csm_tbm_evidence_dir() . '/' . $file['stored_name'] : '';
    if (!$file || !preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx)$/D', $file['stored_name']) || !is_file($path)) {
        http_response_code(404);
        exit('첨부파일을 찾을 수 없습니다.');
    }
    $inline = in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $file['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"evidence\"; filename*=UTF-8''" . rawurlencode($file['original_name']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canManage) throw new InvalidArgumentException('스캔본과 평가를 변경할 권한이 없습니다.');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_tbm_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침해 주세요.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'upload') {
            csm_tbm_upload($db, $year, $month, $_FILES['evidence'] ?? [], (string)$user['login_id']);
            $_SESSION['csm_tbm_notice'] = $month . '월 TBM 일지 스캔본을 첨부했습니다.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !csm_tbm_delete($db, $year, $id)) throw new InvalidArgumentException('삭제할 스캔본을 찾을 수 없습니다.');
            $_SESSION['csm_tbm_notice'] = '스캔본을 삭제했습니다. 반영 점수도 다시 계산됩니다.';
        } else {
            throw new InvalidArgumentException('요청한 작업을 확인해 주세요.');
        }
        header('Location: /CSM/tbm_activity.php?year=' . $year . '&month=' . $month . '#month-files', true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : '저장 중 오류가 발생했습니다.';
        if (!($exception instanceof InvalidArgumentException)) error_log('CSM TBM activity: ' . $exception->getMessage());
    }
}

$files = csm_tbm_evidence($db, $year, $month);
$monthCounts = csm_tbm_month_counts($db, $year);
$monthlyScore = csm_tbm_monthly_summary($db, $year, $today);
$earned = $monthlyScore['earned'];
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$module = array_values(array_filter($catalog['modules'], static fn(array $entry): bool => $entry['id'] === '2.3'))[0];
$criterion = $module['items'][0];
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>2.3 TBM 일지 스캔본 · CSM</title>
  <link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="tbm_activity.css?v=<?= (int)filemtime(__DIR__ . '/tbm_activity.css') ?>">
</head>
<body>
<header><div><a href="/CSM/area.php?group=2&amp;section=2.3">← 위험관리</a><strong>2.3 작업 전 안전미팅</strong><span><?= $h($user['name'] ?? '') ?></span></div></header>
<main>
  <div class="heading"><div><small>CSM 안전보건활동평가 · 2.3 · 2점</small><h1>TBM 일지 스캔본</h1><p>실제로 작성하고 서명한 작업 전 안전미팅 일지를 월별로 보관합니다.</p></div><a class="back-link" href="/CSM/">CSM 한눈에 보기 →</a></div>
  <?php if ($error): ?><p class="tbm-alert error" role="alert"><?= $h($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="tbm-alert success" role="status"><?= $h($notice) ?></p><?php endif; ?>

  <section class="panel tbm-filter"><form method="get"><label>평가 연도 <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>" required></label><button>연도 조회</button></form></section>
  <nav class="tbm-months" aria-label="월별 TBM 스캔본"><?php for ($m=1; $m<=12; $m++): ?><a href="?year=<?= $year ?>&amp;month=<?= $m ?>#month-files" <?= $m===$month ? 'aria-current="page"' : '' ?>><strong><?= $m ?>월</strong><small><?= $monthCounts[$m] ?>개 첨부</small><small><?= $m > $monthlyScore['cutoff'] ? '평가 전' : number_format($monthlyScore['months'][$m]['earned'], 2) . ' / 2점' ?></small></a><?php endfor; ?></nav>

  <section class="panel tbm-criterion"><div class="section-title"><h2>2.3.1 <?= $h($criterion['title']) ?></h2><strong>현재 점수 <?= number_format($earned, 2) ?> / 2점</strong></div><p class="tbm-score-explanation"><?= $year === (int)$today->format('Y') ? $today->format('Y-m-d') . ' 기준' : ($year < (int)$today->format('Y') ? $year . '년 전체 기준' : '평가 대상 연도 전') ?> · 1월~<?= $monthlyScore['cutoff'] ?>월 <?= $monthlyScore['cutoff'] ?>개월 중 증빙 <?= $monthlyScore['covered_months'] ?>개월 · 월별 점수 합계 <?= number_format($monthlyScore['total'], 2) ?>점<?= $monthlyScore['cutoff'] > 0 ? ' ÷ ' . $monthlyScore['cutoff'] . '개월 = ' . number_format($earned, 2) . '점' : '' ?></p><p><?= nl2br($h($criterion['evidence'])) ?></p><div class="tbm-flow"><span>작업사항</span><span>위험요인 예지</span><span>개선대책</span><span>결과 환류</span></div><p class="hint">해당 월에 유효한 일지 스캔본이 1개 이상 올라오면 자동으로 충족(2점) 처리합니다. 미첨부 월은 0점이며, 올해는 현재 월까지 평균하고 미래 월은 제외합니다.</p></section>

  <section class="panel tbm-review" id="month-files"><div class="section-title"><h2><?= $year ?>년 <?= $month ?>월 작성 일지</h2><span>스캔본 <?= count($files) ?>개</span></div><p class="hint">해당 월에 실제 실시한 일지의 서명된 스캔본을 PDF 또는 사진으로 첨부하세요. 여러 장이라면 한 PDF로 묶거나 파일을 차례로 올릴 수 있습니다.</p>
    <div class="tbm-files">
      <?php if (!$files): ?><p class="muted">이 달에 첨부된 일지가 없습니다.</p><?php else: ?>
      <ul><?php foreach ($files as $file):
        $fileUrl = '/CSM/tbm_activity.php?year=' . $year . '&month=' . $month . '&file=' . (int)$file['id'];
      ?><li class="tbm-file-entry">
        <div class="tbm-file-row"><span class="tbm-file-label">원본 일지</span><strong class="tbm-file-name"><?= $h($file['original_name']) ?></strong><small><?= $h($file['uploaded_at']) ?> · <?= number_format((int)$file['size']/1024) ?> KB</small><button type="button" class="tbm-document-button" data-url="<?= $h($fileUrl) ?>" data-mime="<?= $h($file['mime']) ?>" data-name="<?= $h($file['original_name']) ?>">문서보기</button><?php if ($canManage): ?><form method="post" onsubmit="return confirm('이 스캔본을 삭제할까요?')"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_tbm_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>"><button name="action" value="delete">삭제</button></form><?php endif; ?></div>
      </li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php if ($canManage): ?><form method="post" enctype="multipart/form-data" class="tbm-upload"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_tbm_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="month" value="<?= $month ?>"><label><?= $month ?>월 스캔본 선택 <input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png" required></label><button name="action" value="upload">이 달에 첨부</button><small>PDF · JPG · PNG, 파일당 15MB 이하</small></form><?php endif; ?>
  </section>

</main>
<dialog class="tbm-document-modal" id="tbm-document-modal" aria-labelledby="tbm-document-title">
  <div class="tbm-modal-header" id="tbm-document-drag"><div><strong id="tbm-document-title"></strong><small>끌어서 이동 · 모서리를 잡아 크기 조절</small></div><div><a id="tbm-document-open" href="#" target="_blank" rel="noopener">새 탭에서 열기 ↗</a><button type="button" id="tbm-document-close" aria-label="문서보기 닫기">닫기 ×</button></div></div>
  <div class="tbm-modal-content"><iframe id="tbm-document-pdf" title="TBM 스캔본 PDF" hidden></iframe><img id="tbm-document-image" alt="TBM 일지 스캔본" hidden></div>
</dialog>
<script>
const documentModal = document.getElementById('tbm-document-modal');
const documentPdf = document.getElementById('tbm-document-pdf');
const documentImage = document.getElementById('tbm-document-image');
const documentTitle = document.getElementById('tbm-document-title');
const documentOpen = document.getElementById('tbm-document-open');
document.querySelectorAll('.tbm-document-button').forEach(button => {
  button.addEventListener('click', () => {
    const url = button.dataset.url;
    documentTitle.textContent = button.dataset.name || 'TBM 일지';
    documentOpen.href = url;
    if (button.dataset.mime === 'application/pdf') {
      documentImage.hidden = true;
      documentPdf.hidden = false;
      documentPdf.src = url + '#toolbar=1';
    } else {
      documentPdf.hidden = true;
      documentImage.hidden = false;
      documentImage.alt = documentTitle.textContent;
      documentImage.src = url;
    }
    if (!documentModal.open) documentModal.show();
  });
});
document.getElementById('tbm-document-close').addEventListener('click', () => documentModal.close());
documentModal.addEventListener('close', () => {
  documentPdf.removeAttribute('src');
  documentImage.removeAttribute('src');
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && documentModal.open) documentModal.close();
});
const dragHandle = document.getElementById('tbm-document-drag');
let dragStart = null;
dragHandle.addEventListener('pointerdown', event => {
  if (event.target.closest('a,button')) return;
  const bounds = documentModal.getBoundingClientRect();
  dragStart = {x: event.clientX, y: event.clientY, left: bounds.left, top: bounds.top};
  documentModal.style.left = bounds.left + 'px';
  documentModal.style.top = bounds.top + 'px';
  documentModal.style.right = 'auto';
  dragHandle.setPointerCapture(event.pointerId);
});
dragHandle.addEventListener('pointermove', event => {
  if (!dragStart) return;
  const left = Math.max(0, Math.min(window.innerWidth - 120, dragStart.left + event.clientX - dragStart.x));
  const top = Math.max(0, Math.min(window.innerHeight - 60, dragStart.top + event.clientY - dragStart.y));
  documentModal.style.left = left + 'px';
  documentModal.style.top = top + 'px';
});
dragHandle.addEventListener('pointerup', () => { dragStart = null; });
dragHandle.addEventListener('pointercancel', () => { dragStart = null; });
</script>
</body>
</html>
