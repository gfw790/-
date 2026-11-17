<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bonus_evidence_store.php';
require_once __DIR__ . '/bonus_near_miss.php';
require_once __DIR__ . '/scoring.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$module = array_values(array_filter($catalog['modules'], static fn(array $entry): bool => $entry['id'] === '6.1'))[0];
$items = array_column($module['items'], null, 'id');
$year = filter_var($_POST['year'] ?? $_GET['year'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y'), FILTER_VALIDATE_INT);
$item = $_POST['item'] ?? $_GET['item'] ?? '6.1.1';
if (!$year || $year < 2000 || $year > 2100 || !is_string($item) || !isset($items[$item])) {
    http_response_code(400);
    exit('평가 연도와 가점 항목을 확인해 주세요.');
}
$canManage = auth_can_manage($user);
$db = bonus_evidence_db();
$base = '/CSM/bonus_activity.php?year=' . $year . '&item=' . $item;
$_SESSION['bonus_evidence_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$notice = $_SESSION['bonus_evidence_notice'] ?? '';
unset($_SESSION['bonus_evidence_notice']);

if (isset($_GET['file'])) {
    $fileId = filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $file = $fileId ? bonus_evidence_file($db, $fileId, $year, $item) : null;
    $path = $file ? bonus_evidence_dir() . '/' . $file['stored_name'] : '';
    if (!$file || !is_file($path)) { http_response_code(404); exit('증빙 파일을 찾을 수 없습니다.'); }
    $inline = ($_GET['inline'] ?? '') === '1' && in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $file['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"evidence\"; filename*=UTF-8''" . rawurlencode($file['original_name']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canManage) throw new InvalidArgumentException('증빙을 등록하거나 삭제할 권한이 없습니다.');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['bonus_evidence_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침해 주세요.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'upload') {
            $kind = $_POST['evidence_kind'] ?? 'general';
            if (!is_string($kind)) throw new InvalidArgumentException('증빙 종류를 확인해 주세요.');
            $count = bonus_evidence_upload($db, $year, $item, $_FILES['evidence'] ?? [], (string)$user['login_id'], $kind);
            $_SESSION['bonus_evidence_notice'] = $count . '개 증빙을 첨부했습니다.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !bonus_evidence_delete($db, $id, $year, $item, (string)$user['login_id'])) {
                throw new InvalidArgumentException('삭제할 파일을 찾을 수 없습니다. 새로고침해 주세요.');
            }
            $_SESSION['bonus_evidence_notice'] = '증빙 목록에서 삭제했습니다.';
        } else {
            throw new InvalidArgumentException('요청한 작업을 확인해 주세요.');
        }
        header('Location: ' . $base . '#' . $item, true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : '증빙 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
        if (!($exception instanceof InvalidArgumentException)) error_log('CSM bonus evidence: ' . $exception->getMessage());
    }
}
$files = bonus_evidence_list($db, $year);
$certificationScore = csm_bonus_certification_summary($db, $year);
$nearMiss = null;
$nearMissError = '';
try { $nearMiss = bonus_near_miss_summary($year); }
catch (Throwable $exception) { $nearMissError = '아차사고 전산 기록을 조회하지 못했습니다.'; error_log('CSM bonus near miss: ' . $exception->getMessage()); }
$currentHeadcount = null;
if ($canManage) {
    try { $currentHeadcount = bonus_current_headcount(); }
    catch (Throwable $exception) { error_log('CSM bonus current headcount: ' . $exception->getMessage()); }
}
$nearMissScore = $nearMiss && $currentHeadcount ? bonus_near_miss_points($nearMiss['total'], $currentHeadcount) : null;
$examples = [
    '6.1.1' => 'MIS 아차사고 제출 실적표와 기준 상용직 인원 자료',
    '6.1.2' => '유효한 KOSHA·ISO 인증서와 위험성평가 인정서를 각각 첨부',
    '6.1.3' => '안전행동인 선발 통보서 또는 선정 명단',
    '6.1.4' => '정부·안전보건공단 사업 참여 확인서 또는 결과 공문',
    '6.1.5' => '설비·공정 개선 전후 사진과 결과 자료, 안전어울림 참여 확인서',
];
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>6.1 가점 증빙관리 · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="bonus_activity.css"></head><body>
<header><div><a href="/CSM/area.php?group=6&amp;section=6.1">← 가감점 항목</a><strong>6.1 가점 증빙관리</strong><span><?= $escape($user['name'] ?? '') ?></span></div></header>
<main>
<div class="heading"><div><small>CSM 안전보건활동평가 · 6.1</small><h1>가점 항목별 증빙관리</h1><p>평가표의 다섯 항목에 증빙을 각각 첨부하고 연도별로 확인합니다.</p></div><a class="back-link" href="/CSM/">CSM 한눈에 보기 →</a></div>
<section class="panel toolbar"><form method="get"><label for="bonus-year">평가 연도</label><input id="bonus-year" type="number" name="year" min="2000" max="2100" value="<?= $year ?>" required><input type="hidden" name="item" value="<?= $escape($item) ?>"><button>조회</button></form><p>증빙 등록은 점수 확정과 별개입니다. 6.1 가점은 평가 기준 확인 후 별도로 반영합니다.</p></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<nav class="item-nav" aria-label="가점 항목 선택"><?php foreach ($items as $entry): ?><a href="?year=<?= $year ?>&amp;item=<?= $entry['id'] ?>#<?= $entry['id'] ?>" <?= $item === $entry['id'] ? 'aria-current="page"' : '' ?>><strong><?= $entry['id'] ?></strong><span><?= $escape($entry['title']) ?></span><small>증빙 <?= count($files[$entry['id']] ?? []) ?>개</small></a><?php endforeach; ?></nav>
<div class="item-list"><?php foreach ($items as $entry): $code = $entry['id']; $attached = $files[$code] ?? []; $completedKinds = count(array_filter(['iso', 'risk_recognition'], static fn(string $kind): bool => (bool)array_filter($attached, static fn(array $file): bool => ($file['evidence_kind'] ?? 'general') === $kind))); ?>
<section class="panel bonus-item <?= $item === $code ? 'selected' : '' ?>" id="<?= $code ?>"><div class="item-top"><div><span class="code"><?= $code ?></span><h2><?= $escape($entry['title']) ?></h2></div><span class="file-count"><?= $code === '6.1.2' ? '증빙 종류 ' . $completedKinds . '/2 · ' : '' ?>파일 <?= count($attached) ?>개</span></div>
<p class="rule"><?= nl2br($escape($entry['evidence'])) ?></p><p class="example"><strong>첨부 예시</strong> <?= $escape($examples[$code]) ?></p>
<?php if ($code === '6.1.1'): ?><div class="near-miss-summary"><div class="near-miss-top"><div><strong>아차사고 전산 조회</strong><span><?= $year ?>년 발생일 기준 · 통계와 동일</span></div><?php if ($nearMiss): ?><strong class="near-miss-count"><?= number_format($nearMiss['total']) ?>건</strong><?php endif; ?></div><?php if ($nearMiss): ?><p>발생일 기준: 직접 등록 <?= number_format($nearMiss['local']) ?>건 · 엑셀 연계 <?= number_format($nearMiss['imported']) ?>건</p><p class="near-miss-written">작성일 기준 <?= number_format($nearMiss['written_total']) ?>건 · 발생일과 작성일이 다른 연도에 속할 수 있습니다.</p><?php if ($canManage): ?><div class="near-miss-ratio"><span>현재 재직 등록 인원 <strong><?= $currentHeadcount ? number_format($currentHeadcount['confirmed']) . '명' : '조회 불가' ?></strong><small><?= $currentHeadcount ? $escape($currentHeadcount['date']) . ' 기준' : '인원 DB 확인 필요' ?></small></span><span>참고 비율 <strong><?= $currentHeadcount && $currentHeadcount['confirmed'] > 0 && $currentHeadcount['unknown'] === 0 ? number_format($nearMiss['total'] / $currentHeadcount['confirmed'], 2) . '건/명' : '계산 불가' ?></strong><small>선택 연도 발생 건수 ÷ 현재 재직 등록 인원</small></span></div><?php endif; ?><div class="near-miss-months" aria-label="월별 아차사고 발생건수"><?php foreach ($nearMiss['months'] as $month => $count): ?><span><small><?= $month ?>월</small><b><?= $count ?></b></span><?php endforeach; ?></div><p class="near-miss-caveat">평가표는 MIS 제출건수와 현재 상용직 인원을 기준으로 합니다. 내부 전산의 발생 건수는 MIS 제출 실적과 다를 수 있고, 인원 DB에는 상용직 구분값이 없어 위 비율은 참고용입니다. 6.1.1 가점은 내부 전산 발생 건수와 현재 재직 등록 인원 기준으로 자동 반영한 잠정값입니다. MIS 제출건수와 상용직 인원 확인 시 재검토해 주세요.<?php if ($currentHeadcount && $currentHeadcount['unknown'] > 0): ?> 재직 여부 확인이 필요한 인원 <?= $currentHeadcount['unknown'] ?>명이 있어 비율을 계산하지 않았습니다.<?php endif; ?></p><?php else: ?><p class="near-miss-caveat" role="status"><?= $escape($nearMissError) ?></p><?php endif; ?><a href="/near_miss/statistics.php?from=<?= $year ?>-01-01&amp;to=<?= $year === (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y') ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d') : $year . '-12-31' ?>">같은 기간 통계 보기 →</a><?php if ($canManage): ?> · <a href="/CSM/headcount.php<?= $currentHeadcount ? '?date=' . $escape($currentHeadcount['date']) : '' ?>">현재 인원 확인 →</a><?php endif; ?></div><?php endif; ?>
<?php if ($code === '6.1.1' && $canManage): ?><div class="certification-score"><strong>현재 반영 점수 <?= $nearMissScore ? (float)$nearMissScore['earned'] . ' / 2점' : '조회 불가' ?></strong><span>현재 인원 1명당 3건 이상 2점 · 2건 이상 1.5점 · 1건 이상 1점</span></div><?php endif; ?>
<?php if ($code === '6.1.2'): ?><div class="certification-score"><strong>현재 반영 점수 <?= number_format($certificationScore['earned'], 0) ?> / 2점</strong><span>KOSHA·ISO 인증 <?= $certificationScore['kind_counts']['iso'] > 0 ? '1점' : '0점' ?> · 위험성평가 인정 <?= $certificationScore['kind_counts']['risk_recognition'] > 0 ? '1점' : '0점' ?></span></div><p class="note">각 종류의 증빙 파일이 있으면 기본 1점씩 자동 반영됩니다. 파일 삭제 시 해당 점수는 빠집니다. 위험성평가 인정 점수에 따른 추가 차등점수는 기준 확인 전까지 반영하지 않습니다.</p><?php endif; ?>
<?php if ($code === '6.1.2' || $code === '6.1.4'): ?><p class="note">6.1.2 인증 보유와 6.1.4 정부사업 참여는 동일 건을 중복 가점으로 반영하지 않습니다.</p><?php endif; ?>
<?php
$kinds = $code === '6.1.2'
    ? ['iso' => '① KOSHA·ISO 인증', 'risk_recognition' => '② 위험성평가 인정']
    : ['general' => '첨부된 증빙'];
if ($code === '6.1.2' && array_filter($attached, static fn(array $file): bool => ($file['evidence_kind'] ?? 'general') === 'general')) {
    $kinds['general'] = '기존 미분류 증빙';
}
foreach ($kinds as $kind => $kindLabel):
    $kindFiles = array_values(array_filter($attached, static fn(array $file): bool => ($file['evidence_kind'] ?? 'general') === $kind));
    $canUploadKind = $canManage && ($code !== '6.1.2' || $kind !== 'general');
?>
<div class="evidence-kind"><div class="files"><h3><?= $escape($kindLabel) ?> <small><?= count($kindFiles) ?>개</small></h3>
<?php if (!$kindFiles): ?><p class="empty">첨부된 파일이 없습니다.</p><?php else: ?><ul><?php foreach ($kindFiles as $file): $url = '/CSM/bonus_activity.php?year=' . $year . '&item=' . $code . '&file=' . (int)$file['id']; ?><li><div><a href="<?= $escape($url) ?><?= in_array($file['mime'], ['application/pdf','image/jpeg','image/png'], true) ? '&amp;inline=1' : '' ?>" target="_blank" rel="noopener"><?= $escape($file['original_name']) ?> ↗</a><small><?= $escape($file['uploaded_at']) ?> · <?= number_format((int)$file['size'] / 1024) ?> KB</small></div><?php if ($canManage): ?><form method="post" action="<?= $escape('/CSM/bonus_activity.php?year=' . $year . '&item=' . $code) ?>" onsubmit="return confirm('이 증빙을 목록에서 삭제할까요?')"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['bonus_evidence_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="item" value="<?= $code ?>"><input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>"><button name="action" value="delete" class="delete">삭제</button></form><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php if ($canUploadKind): ?><form method="post" action="<?= $escape('/CSM/bonus_activity.php?year=' . $year . '&item=' . $code) ?>" enctype="multipart/form-data" class="upload"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['bonus_evidence_csrf']) ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="item" value="<?= $code ?>"><input type="hidden" name="evidence_kind" value="<?= $escape($kind) ?>"><label for="file-<?= $code ?>-<?= $kind ?>"><?= $code === '6.1.2' ? $escape($kindLabel) . ' 증빙 첨부' : '이 항목에 증빙 첨부' ?></label><input id="file-<?= $code ?>-<?= $kind ?>" type="file" name="evidence[]" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx" multiple required><button name="action" value="upload">첨부하기</button><small>PDF · JPG · PNG · XLSX · DOCX, 파일당 10MB · 합계 30MB · 한 번에 최대 10개</small></form><?php endif; ?></div>
<?php endforeach; ?>
</section><?php endforeach; ?></div>
</main></body></html>
