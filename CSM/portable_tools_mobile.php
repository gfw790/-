<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/portable_tools_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$canManage = auth_can_manage($user);
$db = pt_db();
$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT) ?: (int)date('Y');
$round = (int)($_GET['round'] ?? ((int)date('n') >= 7 ? 2 : 1));
if (!in_array($round, [1, 2], true)) $round = 1;
$eqId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$_SESSION['csm_pt_csrf'] ??= bin2hex(random_bytes(32));

$allEquipment = pt_fetch_all_equipment($db);
$selectedEq = null;
$nextEqId = 0;
if ($eqId > 0) {
    foreach ($allEquipment as $idx => $eq) {
        if ((int)$eq['id'] === $eqId) {
            $selectedEq = $eq;
            if ($idx < count($allEquipment) - 1) $nextEqId = (int)$allEquipment[$idx + 1]['id'];
            break;
        }
    }
}
$selectedItems = $selectedEq ? pt_fetch_check_items($db, (int)$selectedEq['id']) : [];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title><?= $selectedEq ? $h($selectedEq['code'] . ' ' . $selectedEq['name']) : '휴대용 공구 현장점검' ?> · 모바일</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { margin: 0; font-family: "Malgun Gothic", -apple-system, BlinkMacSystemFont, sans-serif; background: #f1f5f9; color: #0f172a; padding-bottom: 90px; }
.m-appbar { position: sticky; top: 0; z-index: 50; background: #0f4c43; color: #fff; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.18); }
.m-appbar a { color: #fff; text-decoration: none; font-weight: 700; font-size: .9rem; padding: 6px 10px; border-radius: 8px; background: rgba(255,255,255,.14); white-space: nowrap; }
.m-appbar-title { flex: 1; text-align: center; font-weight: 800; font-size: .98rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wrap { max-width: 600px; margin: 0 auto; padding: 12px; }
.round-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; background: #e2e8f0; padding: 4px; border-radius: 12px; margin-bottom: 12px; }
.round-switch a { text-align: center; text-decoration: none; padding: 10px 8px; border-radius: 9px; font-weight: 800; font-size: .92rem; color: #475569; }
.round-switch .active { background: #176053; color: #fff; box-shadow: 0 2px 6px rgba(23,96,83,.25); }
.search-box { width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; border-radius: 12px; font-size: .95rem; background: #fff; margin-bottom: 12px; }
.eq-list { display: flex; flex-direction: column; gap: 10px; }
.eq-card { display: flex; align-items: center; gap: 12px; background: #fff; border: 1.5px solid #dbe2e8; border-radius: 14px; padding: 10px 12px; text-decoration: none; color: inherit; }
.eq-card.done { border-color: #86efac; background: #f8fffb; }
.eq-card img { width: 64px; height: 52px; object-fit: cover; border-radius: 9px; border: 1px solid #cbd5e1; flex-shrink: 0; background: #f8fafc; }
.eq-card-body { flex: 1; min-width: 0; }
.eq-card-code { font-size: .78rem; font-weight: 800; color: #0f766e; }
.eq-card-name { font-size: .98rem; font-weight: 800; color: #0f172a; margin: 2px 0; }
.eq-card-sub { font-size: .78rem; color: #64748b; }
.pill { display: inline-block; padding: 4px 9px; border-radius: 999px; font-size: .75rem; font-weight: 800; white-space: nowrap; }
.pill-ok { background: #dcfce7; color: #166534; }
.pill-bad { background: #fee2e2; color: #991b1b; }
.pill-gray { background: #f1f5f9; color: #475569; }
.hero-card { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid #dbe2e8; border-radius: 14px; padding: 12px; margin-bottom: 12px; }
.hero-card img { width: 72px; height: 58px; object-fit: cover; border-radius: 9px; border: 1px solid #cbd5e1; flex-shrink: 0; }
.hero-info h2 { margin: 0 0 3px; font-size: 1.04rem; }
.hero-info div { font-size: .79rem; color: #475569; }
details.meta-acc { background: #fff; border: 1px solid #dbe2e8; border-radius: 12px; padding: 10px 12px; margin-bottom: 12px; }
details.meta-acc summary { font-weight: 800; font-size: .88rem; color: #334155; cursor: pointer; }
.meta-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.meta-fields label { display: grid; gap: 4px; font-size: .78rem; font-weight: 700; color: #475569; }
.meta-fields input, .meta-fields select, .meta-fields textarea { width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; font-size: .9rem; }
.meta-fields .full { grid-column: 1 / -1; }
.chk-list { display: flex; flex-direction: column; gap: 12px; }
.chk-card { background: #fff; border: 1.5px solid #dbe2e8; border-radius: 14px; padding: 14px; }
.chk-card.is-ok { border-color: #86efac; background: #f8fffb; }
.chk-card.is-bad { border-color: #fca5a5; background: #fff8f8; }
.chk-top { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
.chk-num { flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #0f172a; color: #fff; font-weight: 800; font-size: .82rem; display: inline-flex; align-items: center; justify-content: center; }
.chk-text { flex: 1; font-size: .96rem; font-weight: 600; line-height: 1.45; word-break: keep-all; }
.chk-cat { display: inline-block; margin-right: 5px; padding: 2px 7px; border-radius: 6px; background: #e2e8f0; color: #1e293b; font-size: .75rem; font-weight: 800; }
.chk-save { font-size: .74rem; font-weight: 800; color: #16a34a; white-space: nowrap; }
.judge-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.judge-btn { min-height: 48px; border-radius: 11px; border: 2px solid #cbd5e1; background: #fff; color: #334155; font-size: 1rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
.judge-btn.ok.active { background: #16a34a; border-color: #16a34a; color: #fff; }
.judge-btn.bad.active { background: #dc2626; border-color: #dc2626; color: #fff; }
.photo-panel { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #cbd5e1; }
.photo-panel.open { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.photo-btns { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.cam-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 42px; padding: 8px 14px; border-radius: 10px; background: #0f766e; color: #fff; font-size: .9rem; font-weight: 800; cursor: pointer; }
.cam-btn.sub { background: #fff; color: #1e293b; border: 1.5px solid #cbd5e1; }
.thumb-box { display: inline-flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 4px 8px 4px 4px; }
.thumb-box img { width: 76px; height: 58px; object-fit: cover; border-radius: 7px; display: block; cursor: pointer; }
.thumb-del { border: 0; background: #fee2e2; color: #b91c1c; width: 30px; height: 30px; border-radius: 50%; font-weight: 800; font-size: 1rem; cursor: pointer; }
.up-msg { font-size: .8rem; font-weight: 700; color: #0f766e; }
.bottom-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; background: #0f172a; color: #fff; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 8px; box-shadow: 0 -4px 16px rgba(0,0,0,.22); }
.bottom-prog { font-size: .84rem; font-weight: 700; }
.bottom-btns { display: flex; gap: 6px; }
.b-btn { border: 0; border-radius: 9px; padding: 10px 12px; font-weight: 800; font-size: .86rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
.b-btn.gray { background: #334155; color: #fff; }
.b-btn.green { background: #10b981; color: #fff; }
.lb { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.82); z-index: 100; align-items: center; justify-content: center; padding: 16px; }
.lb.open { display: flex; }
.lb img { max-width: 100%; max-height: 85vh; border-radius: 10px; }
</style>
</head>
<body>

<?php if (!$selectedEq): ?>
<header class="m-appbar">
  <a href="portable_tools.php?year=<?= $year ?>">PC 화면</a>
  <div class="m-appbar-title">📱 휴대용 공구 현장점검 (<?= count($allEquipment) ?>종)</div>
  <span style="width:52px"></span>
</header>

<div class="wrap">
  <div class="round-switch">
    <a class="<?= $round === 1 ? 'active' : '' ?>" href="?round=1&amp;year=<?= $year ?>">1차 점검 (상반기)</a>
    <a class="<?= $round === 2 ? 'active' : '' ?>" href="?round=2&amp;year=<?= $year ?>">2차 점검 (하반기)</a>
  </div>
  <input type="search" class="search-box" placeholder="관리번호 또는 공구명 검색 (예: 그라인더, E-HD-07)" oninput="filterCards(this.value)">
  <div class="eq-list" id="eqList">
    <?php foreach ($allEquipment as $eq):
      $res = (string)$eq['check' . $round . '_result'];
      $stk = (string)$eq['check' . $round . '_sticker'];
    ?>
    <a class="eq-card <?= $res !== '' ? 'done' : '' ?>" data-search="<?= $h(strtolower($eq['code'] . ' ' . $eq['name'] . ' ' . $eq['spec'])) ?>" href="?id=<?= (int)$eq['id'] ?>&amp;round=<?= $round ?>&amp;year=<?= $year ?>">
      <img src="portable_tools.php?photo=<?= $h($eq['photo_file']) ?>" alt="">
      <div class="eq-card-body">
        <div class="eq-card-code"><?= $h($eq['code']) ?> · <?= $h($eq['spec'] ?: '-') ?></div>
        <div class="eq-card-name"><?= $h($eq['name']) ?></div>
        <div class="eq-card-sub"><?= $h($eq['voltage']) ?>V · 케이블 <?= $h($eq['cable_len'] ? $eq['cable_len'] . 'm' : '-') ?> · 스티커: <?= $h($stk) ?></div>
      </div>
      <span class="pill <?= $res === '적정' ? 'pill-ok' : ($res !== '' ? 'pill-bad' : 'pill-gray') ?>"><?= $h($res ?: '미점검') ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<script>
function filterCards(q) {
  q = (q || '').trim().toLowerCase();
  document.querySelectorAll('#eqList .eq-card').forEach(el => {
    el.style.display = (!q || el.dataset.search.includes(q)) ? 'flex' : 'none';
  });
}
</script>

<?php else: ?>
<header class="m-appbar">
  <a href="?round=<?= $round ?>&amp;year=<?= $year ?>">← 목록</a>
  <div class="m-appbar-title"><?= $h($selectedEq['code'] . ' · ' . $selectedEq['name']) ?></div>
  <?php if ($nextEqId): ?><a href="?id=<?= $nextEqId ?>&amp;round=<?= $round ?>&amp;year=<?= $year ?>">다음 ▶</a><?php else: ?><span style="width:52px"></span><?php endif; ?>
</header>

<div class="wrap">
  <div class="hero-card">
    <?php if ($selectedEq['photo_file'] !== ''): ?>
      <img src="portable_tools.php?photo=<?= $h($selectedEq['photo_file']) ?>" alt="" onclick="openLb(this.src)">
    <?php endif; ?>
    <div class="hero-info">
      <h2><?= $h($selectedEq['name']) ?> <small style="color:#0f766e">(<?= $h($selectedEq['code']) ?>)</small></h2>
      <div>정격: <?= $h($selectedEq['voltage']) ?>V / <?= $h($selectedEq['current_amp'] ?: '-') ?> · 규격: <?= $h($selectedEq['spec'] ?: '-') ?></div>
      <div>케이블: <?= $h($selectedEq['cable_len'] ? $selectedEq['cable_len'] . 'm' : '-') ?> · <?= $h($selectedEq['socket_type']) ?> (<?= $h($selectedEq['indoor_outdoor']) ?>)</div>
    </div>
  </div>

  <div class="round-switch">
    <a class="<?= $round === 1 ? 'active' : '' ?>" href="?id=<?= (int)$selectedEq['id'] ?>&amp;round=1&amp;year=<?= $year ?>">1차 점검 (상반기)</a>
    <a class="<?= $round === 2 ? 'active' : '' ?>" href="?id=<?= (int)$selectedEq['id'] ?>&amp;round=2&amp;year=<?= $year ?>">2차 점검 (하반기)</a>
  </div>

  <details class="meta-acc">
    <summary>📅 점검일자 · 점검자 · 스티커 · 조치사항 설정 (터치)</summary>
    <form method="post" action="portable_tools.php">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
      <input type="hidden" name="action" value="save_checklist">
      <input type="hidden" name="equipment_id" value="<?= (int)$selectedEq['id'] ?>">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="check1_date" value="<?= $h($selectedEq['check1_date']) ?>" id="h_c1_date">
      <input type="hidden" name="check1_inspector" value="<?= $h($selectedEq['check1_inspector']) ?>" id="h_c1_insp">
      <input type="hidden" name="check1_sticker" value="<?= $h($selectedEq['check1_sticker']) ?>" id="h_c1_stk">
      <input type="hidden" name="check2_date" value="<?= $h($selectedEq['check2_date']) ?>" id="h_c2_date">
      <input type="hidden" name="check2_inspector" value="<?= $h($selectedEq['check2_inspector']) ?>" id="h_c2_insp">
      <input type="hidden" name="check2_sticker" value="<?= $h($selectedEq['check2_sticker']) ?>" id="h_c2_stk">
      <div class="meta-fields">
        <label><?= $round ?>차 점검일자 <input type="date" value="<?= $h($selectedEq['check' . $round . '_date']) ?>" onchange="document.getElementById('h_c<?= $round ?>_date').value=this.value"></label>
        <label><?= $round ?>차 점검자 <input type="text" value="<?= $h($selectedEq['check' . $round . '_inspector']) ?>" onchange="document.getElementById('h_c<?= $round ?>_insp').value=this.value"></label>
        <label class="full"><?= $round ?>차 스티커 발부 여부
          <select onchange="document.getElementById('h_c<?= $round ?>_stk').value=this.value">
            <option value="발부" <?= $selectedEq['check' . $round . '_sticker'] === '발부' ? 'selected' : '' ?>>발부 완료</option>
            <option value="미발부" <?= $selectedEq['check' . $round . '_sticker'] !== '발부' ? 'selected' : '' ?>>미발부</option>
          </select>
        </label>
        <label class="full">주요 조치사항 <textarea name="action_note" rows="2"><?= $h($selectedEq['action_note']) ?></textarea></label>
      </div>
      <?php foreach ($selectedItems as $it): $iid = (int)$it['id']; ?>
        <input type="hidden" name="r1[<?= $iid ?>]" id="f_r1_<?= $iid ?>" value="<?= $h($it['round1_result']) ?>">
        <input type="hidden" name="r2[<?= $iid ?>]" id="f_r2_<?= $iid ?>" value="<?= $h($it['round2_result']) ?>">
      <?php endforeach; ?>
      <?php if ($canManage): ?><button type="submit" class="b-btn green" style="width:100%;justify-content:center;margin-top:10px">설정·조치사항 저장</button><?php endif; ?>
    </form>
  </details>

  <div class="chk-list">
    <?php foreach ($selectedItems as $it):
      $iid = (int)$it['id'];
      $res = (string)$it['round' . $round . '_result'];
      $photo = (string)($it['round' . $round . '_photo'] ?? '');
    ?>
    <div class="chk-card <?= $res === '적정' ? 'is-ok' : ($res === '부적정' ? 'is-bad' : '') ?>" id="card-<?= $iid ?>" data-id="<?= $iid ?>" data-res="<?= $h($res) ?>" data-photo="<?= $h($photo) ?>">
      <div class="chk-top">
        <span class="chk-num"><?= (int)$it['item_no'] ?></span>
        <div class="chk-text">
          <?php if ($it['category'] !== ''): ?><span class="chk-cat"><?= $h($it['category']) ?></span><?php endif; ?>
          <?= $h($it['content']) ?>
        </div>
        <span class="chk-save" id="st-<?= $iid ?>"></span>
      </div>
      <div class="judge-row">
        <button type="button" class="judge-btn ok <?= $res === '적정' ? 'active' : '' ?>" id="ok-<?= $iid ?>" onclick="setJudge(<?= $iid ?>, '적정')" <?= $canManage ? '' : 'disabled' ?>>✅ 적정</button>
        <button type="button" class="judge-btn bad <?= $res === '부적정' ? 'active' : '' ?>" id="bad-<?= $iid ?>" onclick="setJudge(<?= $iid ?>, '부적정')" <?= $canManage ? '' : 'disabled' ?>>❌ 부적정</button>
      </div>
      <div class="photo-panel <?= ($res !== '' || $photo !== '') ? 'open' : '' ?>" id="pp-<?= $iid ?>">
        <?php if ($canManage): ?>
        <div class="photo-btns">
          <label class="cam-btn">📷 사진 촬영<input type="file" accept="image/*" capture="environment" style="display:none" onchange="uploadPhoto(<?= $iid ?>, this)"></label>
          <label class="cam-btn sub">🖼️ 앨범<input type="file" accept="image/*" style="display:none" onchange="uploadPhoto(<?= $iid ?>, this)"></label>
          <span class="up-msg" id="up-<?= $iid ?>"></span>
        </div>
        <?php endif; ?>
        <div id="th-<?= $iid ?>">
          <?php if ($photo !== ''): ?>
          <div class="thumb-box">
            <img src="portable_tools.php?check_photo=<?= $h($photo) ?>" alt="점검사진" onclick="openLb(this.src)">
            <?php if ($canManage): ?><button type="button" class="thumb-del" onclick="delPhoto(<?= $iid ?>)">×</button><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="bottom-bar">
  <div class="bottom-prog" id="bProg">점검 현황</div>
  <div class="bottom-btns">
    <?php if ($canManage): ?><button type="button" class="b-btn gray" onclick="markAllOk()">✅ 전체 적정</button><?php endif; ?>
    <?php if ($nextEqId): ?><a class="b-btn green" href="?id=<?= $nextEqId ?>&amp;round=<?= $round ?>&amp;year=<?= $year ?>">다음 공구 ▶</a><?php else: ?><a class="b-btn green" href="?round=<?= $round ?>&amp;year=<?= $year ?>">목록으로</a><?php endif; ?>
  </div>
</div>

<div class="lb" id="lb" onclick="this.classList.remove('open')"><img id="lbImg" src="" alt=""></div>

<script>
const CSRF = <?= json_encode($_SESSION['csm_pt_csrf']) ?>;
const EQ_ID = <?= (int)$selectedEq['id'] ?>;
const ROUND = <?= $round ?>;
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;

function openLb(src) { document.getElementById('lbImg').src = src; document.getElementById('lb').classList.add('open'); }
function updateProg() {
  const cards = document.querySelectorAll('.chk-card');
  let done = 0, ph = 0;
  cards.forEach(c => { if (c.dataset.res) done++; if (c.dataset.photo) ph++; });
  document.getElementById('bProg').textContent = `${ROUND}차 · ${done}/${cards.length} 완료 · 사진 ${ph}장`;
}
async function setJudge(iid, val) {
  if (!CAN_MANAGE) return;
  const card = document.getElementById('card-' + iid);
  card.dataset.res = val;
  card.classList.toggle('is-ok', val === '적정');
  card.classList.toggle('is-bad', val === '부적정');
  document.getElementById('ok-' + iid).classList.toggle('active', val === '적정');
  document.getElementById('bad-' + iid).classList.toggle('active', val === '부적정');
  document.getElementById('pp-' + iid).classList.add('open');
  const hid = document.getElementById(`f_r${ROUND}_${iid}`);
  if (hid) hid.value = val;
  updateProg();

  const st = document.getElementById('st-' + iid);
  st.textContent = '저장중...';
  try {
    const fd = new FormData();
    fd.append('csrf', CSRF); fd.append('action', 'ajax_set_item_result');
    fd.append('equipment_id', String(EQ_ID)); fd.append('item_id', String(iid));
    fd.append('round', String(ROUND)); fd.append('result', val);
    const r = await fetch('portable_tools.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.message || '저장 실패');
    st.textContent = '✓ 저장';
    setTimeout(() => { if (st.textContent === '✓ 저장') st.textContent = ''; }, 1500);
  } catch (e) { st.textContent = '⚠ 오류'; alert(e.message || '저장 오류'); }
}
function compressImg(file, maxSide = 1280, quality = 0.82) {
  return new Promise(resolve => {
    const img = new Image(); const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      let w = img.naturalWidth, h = img.naturalHeight;
      if (w > maxSide || h > maxSide) {
        if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
        else { w = Math.round(w * maxSide / h); h = maxSide; }
      }
      const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
      cv.getContext('2d').drawImage(img, 0, 0, w, h);
      cv.toBlob(b => resolve(b ? new File([b], 'chk.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });
}
async function uploadPhoto(iid, input) {
  const file = input.files && input.files[0]; input.value = '';
  if (!file) return;
  const up = document.getElementById('up-' + iid); up.textContent = '⏳ 업로드 중...';
  try {
    const comp = await compressImg(file);
    const fd = new FormData();
    fd.append('csrf', CSRF); fd.append('action', 'ajax_upload_check_photo');
    fd.append('equipment_id', String(EQ_ID)); fd.append('item_id', String(iid));
    fd.append('round', String(ROUND)); fd.append('photo', comp);
    const r = await fetch('portable_tools.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.message || '업로드 실패');
    document.getElementById('card-' + iid).dataset.photo = d.photo;
    document.getElementById('th-' + iid).innerHTML = `<div class="thumb-box"><img src="${d.url}" alt="점검사진" onclick="openLb('${d.url}')"><button type="button" class="thumb-del" onclick="delPhoto(${iid})">×</button></div>`;
    updateProg(); up.textContent = '✅ 저장됨';
    setTimeout(() => { if (up.textContent === '✅ 저장됨') up.textContent = ''; }, 1800);
  } catch (e) { up.textContent = ''; alert(e.message || '사진 업로드 오류'); }
}
async function delPhoto(iid) {
  if (!confirm('촬영한 사진을 삭제할까요?')) return;
  try {
    const fd = new FormData();
    fd.append('csrf', CSRF); fd.append('action', 'ajax_delete_check_photo');
    fd.append('equipment_id', String(EQ_ID)); fd.append('item_id', String(iid)); fd.append('round', String(ROUND));
    const r = await fetch('portable_tools.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.message || '삭제 실패');
    document.getElementById('card-' + iid).dataset.photo = '';
    document.getElementById('th-' + iid).innerHTML = '';
    updateProg();
  } catch (e) { alert(e.message || '사진 삭제 오류'); }
}
function markAllOk() { document.querySelectorAll('.chk-card').forEach(c => setJudge(Number(c.dataset.id), '적정')); }
updateProg();
</script>
<?php endif; ?>
</body>
</html>
