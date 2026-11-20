<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/penalty_history_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');

$db = pen_db();

if (isset($_GET['download_evidence'])) {
    $evId = (int)$_GET['download_evidence'];
    $stmt = $db->prepare('SELECT * FROM pen_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$evId]);
    $ev = $stmt->fetch();
    if (!$ev) { http_response_code(404); exit('증빙 없음'); }
    $path = pen_evidence_dir() . '/' . basename((string)$ev['stored_name']);
    if (!is_file($path)) { http_response_code(404); exit('파일 없음'); }
    $inline = isset($_GET['inline']) && in_array($ev['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('Content-Type: ' . $ev['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode((string)$ev['original_name']));
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['pen_csrf'])) $_SESSION['pen_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['pen_csrf'];

$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) $year = $nowYear;

$meta = pen_item_meta();
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $flashError = '보안 토큰이 만료되었습니다.';
    } elseif (!$canManage) {
        $flashError = '관리 권한이 필요합니다.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $now = date('Y-m-d H:i:s');
        try {
            if ($action === 'save_record') {
                $ic = (string)($_POST['item_code'] ?? '');
                $sub = (string)($_POST['subtype'] ?? '');
                if (!isset($meta[$ic]['subtypes'][$sub])) throw new InvalidArgumentException('감점 항목 및 세부 구분을 확인해 주세요.');
                $occurDate = trim((string)($_POST['occur_date'] ?? ''));
                $summary = trim((string)($_POST['summary'] ?? ''));
                if ($occurDate === '' || $summary === '') throw new InvalidArgumentException('발생일자와 발생 내용을 입력해 주세요.');
                $subInfo = $meta[$ic]['subtypes'][$sub];
                $db->prepare('INSERT INTO pen_records (year, item_code, subtype, subtype_label, deduction_points, occur_date, issuer_or_place, summary, corrective_action, action_done_date, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, $ic, $sub, $subInfo['label'], (float)$subInfo['points'],
                       $occurDate, trim((string)($_POST['issuer_or_place'] ?? '')),
                       $summary, trim((string)($_POST['corrective_action'] ?? '')),
                       trim((string)($_POST['action_done_date'] ?? '')),
                       trim((string)($_POST['status'] ?? '조치완료')),
                       $actor, $now, $now,
                   ]);
                $flashSuccess = $ic . ' 감점·사고 이력이 등록되었습니다 (' . $subInfo['points'] . '점 자동 반영).';
            } elseif ($action === 'delete_record') {
                $db->prepare('DELETE FROM pen_records WHERE id=? AND year=?')->execute([(int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '감점·사고 이력이 삭제되었습니다.';
            } elseif ($action === 'upload_evidence') {
                pen_upload_evidence($db, $year, (string)($_POST['item_code'] ?? '6.2.2'), $_FILES['evidence_file'] ?? null, (string)($_POST['evidence_base64'] ?? ''), trim((string)($_POST['note'] ?? '')), $actor);
                $flashSuccess = '확인 서류(증빙)가 등록되었습니다.';
            } elseif ($action === 'delete_evidence') {
                $db->prepare('UPDATE pen_evidence SET deleted_at=? WHERE id=? AND year=?')->execute([$now, (int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '증빙 파일이 삭제되었습니다.';
            }
        } catch (Throwable $ex) {
            $flashError = $ex->getMessage();
        }
    }
}

$records = $db->prepare('SELECT * FROM pen_records WHERE year=? ORDER BY occur_date DESC, id DESC');
$records->execute([$year]);
$records = $records->fetchAll();

$evStmt = $db->prepare('SELECT * FROM pen_evidence WHERE year=? AND deleted_at IS NULL ORDER BY item_code ASC, id DESC');
$evStmt->execute([$year]);
$evidences = $evStmt->fetchAll();

$scores = [];
$totalDeduction = 0.0;
foreach (array_keys($meta) as $c) {
    $scores[$c] = csm_penalty_score_summary($year, $c);
    $totalDeduction += $scores[$c]['earned'];
}
$totalDeduction = round($totalDeduction, 2);

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
function pen_fmt_size(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1) . 'MB' : ($b >= 1024 ? number_format($b / 1024, 1) . 'KB' : $b . 'B'); }
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>6.2 감점·사고이력 관리 · CSM</title>
<style>
  :root { --bg:#f7f5f6; --card:#fff; --line:#e4dce0; --ink:#22181c; --muted:#6b5c63; --brand:#9f1239; --ok:#15803d; --ok-bg:#dcfce7; --danger:#b91c1c; --danger-bg:#fee2e2; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg); color:var(--ink); font-size:14px; line-height:1.5; }
  a { color:inherit; text-decoration:none; }
  .topbar { background:#4c1125; color:#fff; padding:0 20px; height:54px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; }
  .topbar-left { display:flex; align-items:center; gap:12px; }
  .topbar-back { color:#fecdd3; font-size:13px; padding:5px 10px; border-radius:6px; background:rgba(255,255,255,0.1); }
  .topbar-title { font-size:16px; font-weight:800; }
  .year-select { background:rgba(255,255,255,0.14); color:#fff; border:1px solid rgba(255,255,255,0.28); border-radius:6px; padding:5px 10px; font-weight:700; }
  .year-select option { color:#111; }
  .wrap { max-width:1360px; margin:0 auto; padding:20px 20px 60px; }
  .hero { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-end; gap:14px; margin-bottom:18px; }
  .hero small { color:var(--brand); font-weight:800; font-size:12px; }
  .hero h1 { margin:4px 0; font-size:24px; font-weight:900; }
  .hero p { margin:0; color:var(--muted); font-size:13px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 14px; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink); font-weight:700; font-size:13px; cursor:pointer; white-space:nowrap; }
  .btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
  .btn.danger { border-color:#fecaca; color:var(--danger); }
  .btn.ghost { background:#faf8f9; }
  .btn.sm { padding:5px 10px; font-size:12px; border-radius:6px; }
  .flash { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-weight:700; font-size:13px; }
  .flash.ok { background:var(--ok-bg); color:var(--ok); }
  .flash.err { background:var(--danger-bg); color:var(--danger); }
  .kpi-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:18px; }
  .kpi { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:4px; }
  .kpi.clean { background:linear-gradient(135deg,#15803d,#166534); color:#fff; border-color:#15803d; }
  .kpi.alert { background:linear-gradient(135deg,#b91c1c,#881337); color:#fff; border-color:#b91c1c; }
  .kpi-code { font-size:12px; font-weight:800; color:var(--brand); }
  .kpi.clean .kpi-code, .kpi.alert .kpi-code { color:rgba(255,255,255,0.88); }
  .kpi-val { font-size:22px; font-weight:900; }
  .kpi-sub { font-size:12px; color:var(--muted); }
  .kpi.clean .kpi-sub, .kpi.alert .kpi-sub { color:rgba(255,255,255,0.9); }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px 20px; margin-bottom:18px; }
  .card-head { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f0e8ec; }
  .card-head h2 { margin:0; font-size:16px; font-weight:900; }
  .sub { margin:2px 0 0; font-size:12.5px; color:var(--muted); }
  .tbl-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:10px; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th, td { padding:9px 10px; border-bottom:1px solid #efe7eb; text-align:left; vertical-align:middle; }
  th { background:#f8f3f5; font-weight:800; white-space:nowrap; }
  .ctr { text-align:center; }
  .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:800; background:#ffe4e6; color:#9f1239; }
  .badge.ok { background:var(--ok-bg); color:var(--ok); }
  .form-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; }
  .form-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
  .field { display:flex; flex-direction:column; gap:4px; }
  .field.span-2 { grid-column:span 2; }
  .field.span-3 { grid-column:span 3; }
  .field label { font-size:12px; font-weight:800; color:#4b3c43; }
  .field input[type="text"], .field input[type="date"], .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid #d5c7ce; border-radius:8px; font-family:inherit; font-size:13px; background:#fff; }
  .paste-zone { margin-top:8px; padding:10px 12px; border:2px dashed #fda4af; border-radius:10px; background:#fff1f2; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px; font-size:12.5px; color:#9f1239; cursor:pointer; }
  .paste-preview { max-height:110px; border-radius:6px; border:1px solid #d5c7ce; margin-top:6px; }
  .ev-item { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--line); border-radius:9px; background:#fff; margin-bottom:8px; }
  @media (max-width:900px) { .kpi-grid, .form-grid { grid-template-columns:1fr; } .field.span-2, .field.span-3 { grid-column:span 1; } }
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a class="topbar-back" href="/CSM/area.php?group=6&amp;section=6.2">← 6.2 영역</a>
    <a class="topbar-back" href="/CSM/">CSM 포털</a>
    <span class="topbar-title">6.2 감점·사고이력 관리</span>
  </div>
  <div style="display:flex;align-items:center;gap:10px;">
    <form method="get" style="display:inline;">
      <select name="year" class="year-select" onchange="this.form.submit()">
        <?php for ($y = $nowYear + 1; $y >= 2024; $y--): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년</option><?php endfor; ?>
      </select>
    </form>
    <span><?= $e($actor) ?></span>
  </div>
</header>

<main class="wrap">
  <div class="hero">
    <div>
      <small>CSM 평가 영역 6 · 6.2 감점 항목 · 6.2.1 ~ 6.2.3 (실시간 자동 합산)</small>
      <h1><?= $year ?>년 경고장·재해(LTI/MI)·환경사고 감점 관리</h1>
      <p>발생 건수별 기준 감점이 CSM 포털 최종 점수에 자동 반영되며, 발생 내역이 없으면 <strong>무감점(0점) 확인서</strong>로 출력할 수 있습니다.</p>
    </div>
    <div>
      <a class="btn primary" href="penalty_history_print.php?year=<?= $year ?>" target="_blank">🖨️ 6.2 감점·무사고 확인서 A4 출력</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash ok">✅ <?= $e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash err">⚠️ <?= $e($flashError) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <?php foreach ($meta as $code => $m): ?>
      <div class="kpi">
        <span class="kpi-code"><?= $e($code) ?> · <?= $e($m['short']) ?></span>
        <span class="kpi-val"><?= number_format($scores[$code]['earned'], 1) ?>점</span>
        <span class="kpi-sub"><?= $e($scores[$code]['status_text']) ?> · 증빙 <?= (int)$scores[$code]['evidence_count'] ?>건</span>
      </div>
    <?php endforeach; ?>
    <div class="kpi <?= $totalDeduction == 0.0 ? 'clean' : 'alert' ?>">
      <span class="kpi-code">6.2 감점 합계</span>
      <span class="kpi-val"><?= number_format($totalDeduction, 1) ?>점</span>
      <span class="kpi-sub"><?= $totalDeduction == 0.0 ? '무감점 유지 중 (경고장·재해·환경사고 0건)' : 'CSM 포털 최종 점수에 자동 차감 반영 중' ?></span>
    </div>
  </section>

  <?php if ($canManage): ?>
  <section class="card">
    <div class="card-head">
      <div><h2>감점·사고 이력 등록 (발생 시에만 입력)</h2><p class="sub">세부 구분 선택 시 평가표 기준 감점(-1 / -2 / -3 / -5점)이 자동 적용됩니다.</p></div>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_record">
      <div class="form-grid">
        <div class="field">
          <label>감점 항목</label>
          <select name="item_code" id="penItemCode" onchange="syncSubtypes()">
            <?php foreach ($meta as $c => $m): ?><option value="<?= $e($c) ?>"><?= $e($c) ?> <?= $e($m['title']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>세부 구분 (자동 감점 기준)</label>
          <select name="subtype" id="penSubtype"></select>
        </div>
        <div class="field"><label>발생일자 *</label><input type="date" name="occur_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field"><label>발행 주체 / 발생 장소</label><input type="text" name="issuer_or_place" placeholder="예: 한라시멘트 안전관리팀 / 옥계공장"></div>
        <div class="field"><label>조치 완료일</label><input type="date" name="action_done_date"></div>
        <div class="field"><label>조치 상태</label><select name="status"><option value="조치완료">조치완료</option><option value="조치중">조치중</option></select></div>
        <div class="field span-3"><label>발생 경위 및 내용 *</label><textarea name="summary" rows="2" required placeholder="경고장 사유, 재해 경위 또는 환경사고 내용"></textarea></div>
        <div class="field span-3"><label>시정·재발방지 조치 내용</label><textarea name="corrective_action" rows="2"></textarea></div>
      </div>
      <div style="margin-top:12px;text-align:right;"><button type="submit" class="btn primary">감점 이력 등록</button></div>
    </form>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><div><h2><?= $year ?>년 감점·사고 발생 대장 (<?= count($records) ?>건)</h2></div></div>
    <div class="tbl-wrap"><table><thead><tr><th class="ctr">항목</th><th>세부 구분</th><th class="ctr">감점</th><th class="ctr">발생일</th><th>주체/장소</th><th>발생 내용 및 시정조치</th><th class="ctr">상태</th><th class="ctr">관리</th></tr></thead><tbody>
      <?php if (!$records): ?>
        <tr><td colspan="8" class="ctr" style="padding:26px;color:var(--ok);font-weight:800;">✅ <?= $year ?>년 안전 경고장, LTI/MI 재해, 환경사고 발생 내역이 없습니다. (무감점 0점)</td></tr>
      <?php else: foreach ($records as $r): ?>
        <tr>
          <td class="ctr"><span class="badge"><?= $e($r['item_code']) ?></span></td>
          <td><?= $e($r['subtype_label']) ?></td>
          <td class="ctr"><strong style="color:var(--danger);"><?= number_format((float)$r['deduction_points'], 1) ?>점</strong></td>
          <td class="ctr"><?= $e($r['occur_date']) ?></td>
          <td><?= $e($r['issuer_or_place']) ?></td>
          <td><div><strong><?= $e($r['summary']) ?></strong></div><?php if ($r['corrective_action']): ?><div class="sub">조치: <?= $e($r['corrective_action']) ?></div><?php endif; ?></td>
          <td class="ctr"><span class="badge ok"><?= $e($r['status']) ?></span></td>
          <td class="ctr"><?php if ($canManage): ?><form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_record"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody></table></div>
  </section>

  <section class="card">
    <div class="card-head"><div><h2>📎 6.2 확인 서류 (산재요양승인/반려여부 확인서, 무사고 증빙 등)</h2></div></div>
    <?php if ($canManage): ?>
    <form method="post" enctype="multipart/form-data" style="background:#faf8f9;border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:14px;">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="upload_evidence"><input type="hidden" name="evidence_base64" class="js-paste-b64" value="">
      <div class="form-row">
        <div class="field"><label>항목 구분</label><select name="item_code"><?php foreach ($meta as $c => $m): ?><option value="<?= $e($c) ?>"><?= $e($c) ?> <?= $e($m['short']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>파일 선택 (최대 20MB)</label><input type="file" name="evidence_file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.docx"></div>
        <div class="field" style="flex:1;"><label>설명</label><input type="text" name="note" placeholder="예: 근로복지공단 산재요양승인/반려여부 확인서"></div>
        <button type="submit" class="btn primary">서류 등록</button>
      </div>
      <div class="paste-zone js-paste-zone" tabindex="0">
        <span class="js-paste-label">📋 클릭 후 <strong>Ctrl+V</strong> 로 스크린샷 붙여넣기</span>
        <button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button>
        <img class="paste-preview js-paste-preview" style="display:none;">
      </div>
    </form>
    <?php endif; ?>
    <?php if (!$evidences): ?><div style="padding:16px;text-align:center;color:var(--muted);">등록된 확인 서류가 없습니다.</div>
    <?php else: foreach ($evidences as $ev): ?>
      <div class="ev-item">
        <div><span class="badge"><?= $e($ev['item_code']) ?></span> <a href="?download_evidence=<?= (int)$ev['id'] ?>&amp;inline=1" target="_blank" style="font-weight:800;"><?= $e($ev['original_name']) ?></a> <span class="sub"><?= pen_fmt_size((int)$ev['size']) ?> · <?= $e($ev['uploaded_by']) ?> · <?= $e(substr((string)$ev['uploaded_at'], 0, 10)) ?></span><?php if ($ev['note']): ?><div class="sub"><?= $e($ev['note']) ?></div><?php endif; ?></div>
        <div style="display:flex;gap:6px;"><a href="?download_evidence=<?= (int)$ev['id'] ?>" class="btn sm ghost">다운로드</a><?php if ($canManage): ?><form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_evidence"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></div>
      </div>
    <?php endforeach; endif; ?>
  </section>
</main>

<script>
const SUBTYPES = <?= json_encode(array_map(static fn($m) => $m['subtypes'], $meta), JSON_UNESCAPED_UNICODE) ?>;
function syncSubtypes() {
  const code = document.getElementById('penItemCode')?.value;
  const sel = document.getElementById('penSubtype');
  if (!sel || !SUBTYPES[code]) return;
  sel.innerHTML = '';
  Object.entries(SUBTYPES[code]).forEach(([k, info]) => {
    const opt = document.createElement('option');
    opt.value = k; opt.textContent = info.label;
    sel.appendChild(opt);
  });
}
syncSubtypes();

function applyPastedImage(zone, blob) {
  const b64 = (zone.closest('form') || zone.parentElement).querySelector('.js-paste-b64');
  const prev = zone.querySelector('.js-paste-preview');
  const lbl = zone.querySelector('.js-paste-label');
  const r = new FileReader();
  r.onload = () => { if (b64) b64.value = r.result; if (prev) { prev.src = r.result; prev.style.display = 'block'; } if (lbl) lbl.innerHTML = '✅ <strong>스크린샷 준비 완료</strong>'; };
  r.readAsDataURL(blob);
}
document.querySelectorAll('.js-paste-zone').forEach(zone => {
  zone.addEventListener('paste', e => { for (const it of (e.clipboardData || window.clipboardData)?.items || []) { if (it.type && it.type.startsWith('image/')) { e.preventDefault(); applyPastedImage(zone, it.getAsFile()); return; } } });
  zone.querySelector('.js-paste-btn')?.addEventListener('click', async e => {
    e.stopPropagation();
    if (!navigator.clipboard || !navigator.clipboard.read) { zone.focus(); alert('클릭 후 Ctrl+V 를 눌러주세요.'); return; }
    try { for (const ci of await navigator.clipboard.read()) { const t = ci.types.find(x => x.startsWith('image/')); if (t) { applyPastedImage(zone, await ci.getType(t)); return; } } alert('클립보드에 이미지가 없습니다.'); }
    catch { zone.focus(); alert('클릭 후 Ctrl+V 를 눌러주세요.'); }
  });
});
</script>
</body>
</html>
