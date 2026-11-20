<?php
declare(strict_types=1);
require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}
if (!auth_can_manage($user)) {
    http_response_code(403);
    exit('관리 권한이 필요합니다.');
}
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>보호구 빠른 지급 · 모바일</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { margin: 0; font-family: "Malgun Gothic", -apple-system, BlinkMacSystemFont, sans-serif; background: #f1f5f9; color: #0f172a; padding-bottom: 96px; }
.appbar { position: sticky; top: 0; z-index: 50; background: #0f4c43; color: #fff; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.18); }
.appbar a { color: #fff; text-decoration: none; font-weight: 700; font-size: .85rem; padding: 6px 10px; border-radius: 8px; background: rgba(255,255,255,.15); white-space: nowrap; }
.appbar-title { flex: 1; text-align: center; font-weight: 800; font-size: 1.02rem; }

.wrap { max-width: 600px; margin: 0 auto; padding: 12px; display: flex; flex-direction: column; gap: 12px; }
.card { background: #fff; border: 1px solid #dbe2e8; border-radius: 16px; padding: 14px; }
.card-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 12px; }
.card-title { font-size: .98rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 7px; }
.step-num { width: 24px; height: 24px; border-radius: 50%; background: #0f766e; color: #fff; font-size: .8rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
.mini-btn { border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 5px 10px; border-radius: 8px; font-size: .78rem; font-weight: 700; cursor: pointer; }

/* 2-Column PPE Gear Grid */
.gear-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
.gear-tile { display: flex; align-items: center; gap: 9px; padding: 10px; border: 1.5px solid #dbe2e8; border-radius: 12px; background: #fff; cursor: pointer; text-align: left; transition: all .12s; }
.gear-tile.active { border-color: #0f766e; background: #ecfdf5; box-shadow: 0 2px 8px rgba(15,118,110,.15); }
.gear-tile-icon { width: 34px; height: 34px; border-radius: 9px; background: #f0fdfa; border: 1px solid #ccfbf1; color: #0f766e; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gear-tile.active .gear-tile-icon { background: #0f766e; border-color: #0f766e; color: #fff; }
.gear-tile-icon svg { width: 22px; height: 22px; display: block; }
.gear-tile-name { font-size: .92rem; font-weight: 700; color: #0f172a; line-height: 1.25; }
.gear-tile.active .gear-tile-name { color: #0f4c43; font-weight: 800; }

/* Employee Picker */
.emp-search { width: 100%; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: .92rem; margin-bottom: 10px; }
.emp-team { margin-bottom: 10px; }
.emp-team:last-child { margin-bottom: 0; }
.emp-team-lbl { display: inline-block; font-size: .76rem; font-weight: 800; color: #475569; background: #e2e8f0; padding: 2px 9px; border-radius: 999px; margin-bottom: 7px; }
.emp-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.emp-chip { padding: 9px 13px; border: 1.5px solid #dbe2e8; border-radius: 11px; background: #fff; color: #1e293b; font-size: .92rem; font-weight: 700; cursor: pointer; }
.emp-chip.active { border-color: #0f766e; background: #0f766e; color: #fff; box-shadow: 0 2px 6px rgba(15,118,110,.25); }

.meta-row { display: grid; grid-template-columns: 1fr; gap: 10px; }
.meta-row label { display: grid; gap: 5px; font-size: .82rem; font-weight: 700; color: #475569; }
.meta-row input, .meta-row textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 10px; font: inherit; font-size: .95rem; background: #fff; }

.toast { display: none; position: fixed; top: 64px; left: 50%; transform: translateX(-50%); z-index: 80; background: #0f172a; color: #fff; padding: 11px 18px; border-radius: 999px; font-size: .88rem; font-weight: 700; box-shadow: 0 8px 20px rgba(0,0,0,.25); white-space: nowrap; }
.toast.show { display: block; }
.toast.ok { background: #15803d; }
.toast.err { background: #b91c1c; }

.recent-log { font-size: .84rem; color: #334155; display: flex; flex-direction: column; gap: 6px; }
.recent-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 8px 10px; }

.bottom-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; background: #0f172a; color: #fff; padding: 11px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; box-shadow: 0 -4px 16px rgba(0,0,0,.22); }
.bottom-summary { font-size: .86rem; font-weight: 700; line-height: 1.3; }
.bottom-summary small { display: block; color: #94a3b8; font-weight: 500; font-size: .76rem; }
.submit-btn { border: 0; border-radius: 11px; padding: 12px 18px; background: #10b981; color: #fff; font-size: .96rem; font-weight: 800; cursor: pointer; white-space: nowrap; }
.submit-btn:disabled { background: #475569; cursor: not-allowed; }
</style>
</head>
<body>
<header class="appbar">
  <a href="/safety_gear/">PC 화면</a>
  <div class="appbar-title">📱 보호구 빠른 지급</div>
  <a href="/safety_gear/status.php">현황 ↗</a>
</header>

<div id="toast" class="toast"></div>

<div class="wrap">
  <!-- STEP 1: PPE Selection -->
  <section class="card">
    <div class="card-head">
      <div class="card-title"><span class="step-num">1</span> 지급할 보호구 선택</div>
      <button type="button" class="mini-btn" onclick="clearGears()">선택 해제</button>
    </div>
    <div id="gearGrid" class="gear-grid"><div style="color:#64748b;font-size:.86rem">불러오는 중...</div></div>
  </section>

  <!-- STEP 2: Employee Selection -->
  <section class="card">
    <div class="card-head">
      <div class="card-title"><span class="step-num">2</span> 지급 대상자 선택</div>
      <button type="button" class="mini-btn" onclick="clearEmps()">선택 해제</button>
    </div>
    <input type="search" class="emp-search" id="empSearch" placeholder="직원 이름 또는 팀 검색..." oninput="filterEmps(this.value)">
    <div id="empContainer"><div style="color:#64748b;font-size:.86rem">불러오는 중...</div></div>
  </section>

  <!-- STEP 3: Date & Note -->
  <section class="card">
    <div class="card-head">
      <div class="card-title"><span class="step-num">3</span> 지급일 및 메모</div>
    </div>
    <div class="meta-row">
      <label>지급일 <input type="date" id="assignedAt"></label>
      <label>메모 (선택) <textarea id="notes" rows="2" placeholder="예: 현장 교체 지급, 신규 입사자 지급"></textarea></label>
    </div>
  </section>

  <section class="card" id="recentCard" style="display:none">
    <div class="card-head">
      <div class="card-title">✅ 방금 등록된 지급 내역</div>
    </div>
    <div id="recentLog" class="recent-log"></div>
  </section>
</div>

<div class="bottom-bar">
  <div class="bottom-summary" id="bottomSummary">
    보호구와 직원을 선택하세요
    <small>선택 후 즉시 지급 등록됩니다</small>
  </div>
  <button type="button" class="submit-btn" id="submitBtn" disabled onclick="submitIssue()">💾 지급 등록</button>
</div>

<script>
const selectedGears = new Set();
const selectedEmps = new Map();
let allEmployees = [];

function todayStr() {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function showToast(msg, type = 'ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + type;
  clearTimeout(window._toastTimer);
  window._toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

function gearSvg(typeName) {
  const name = String(typeName || '').replace(/\s+/g, '');
  const w = b => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + b + '</svg>';
  if (name.includes('안전모') || name.includes('헬멧')) return w('<path d="M4 15a8 8 0 0 1 16 0v1H4v-1z" fill="currentColor" fill-opacity="0.14"/><path d="M10 7.2V5.5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v1.7"/><path d="M12 7v8"/><path d="M2.5 16.5h19a1 1 0 0 1 0 2h-19a1 1 0 0 1 0-2z"/>');
  if (name.includes('절연') && name.includes('장화')) return w('<path d="M7 3h6v10.5l4.2 1.8A2 2 0 0 1 18.5 17v2H6.5a1 1 0 0 1-1-1V4a1 1 0 0 1 1.5-1z" fill="currentColor" fill-opacity="0.14"/><path d="M5.5 19h13v2h-13z"/><path d="M10.8 6.2l-1.8 3.2h2l-1.3 2.8"/>');
  if (name.includes('안전화') || name.includes('장화')) return w('<path d="M5 7h5.5l1 4.5 5.2 1.8A2.8 2.8 0 0 1 19 16v2H5V7z" fill="currentColor" fill-opacity="0.14"/><path d="M4.5 18h15v2.2h-15z"/><path d="M14.5 13.5v4.5"/><path d="M7.5 10h2.5M7.5 12.5h2.5"/>');
  if (name.includes('안전대')) return w('<path d="M7 3v18M17 3v18"/><path d="M7 6l10 7M17 6l-10 7"/><rect x="5" y="13" width="14" height="3" rx="1" fill="currentColor" fill-opacity="0.18"/><circle cx="12" cy="14.5" r="1.2"/><path d="M12 16v3.5a1.5 1.5 0 0 0 3 0V18"/>');
  if (name.includes('신호수') && name.includes('조끼')) return w('<path d="M8 3l2 3h4l2-3 3 2-1.5 4.5V20h-11V9.5L5 5l3-2z" fill="currentColor" fill-opacity="0.14"/><path d="M7.5 10l9 7M16.5 10l-9 7"/><path d="M6.5 17.5h11"/>');
  if (name.includes('조끼')) return w('<path d="M8 3l2 3.5h4L16 3l3 2-1.5 4.5V20h-11V9.5L5 5l3-2z" fill="currentColor" fill-opacity="0.14"/><path d="M12 6.5V20"/><path d="M6.5 13h11M6.5 16.5h11"/>');
  if (name.includes('마스크')) return w('<path d="M6.5 9.5C6.5 7.5 9 6 12 6s5.5 1.5 5.5 3.5v4c0 3-2.5 5-5.5 5s-5.5-2-5.5-5v-4z" fill="currentColor" fill-opacity="0.14"/><circle cx="12" cy="12.5" r="2.2"/><path d="M6.5 9.5C4 9.5 3 10.8 3 12.2s1 2.5 3.5 2.5M17.5 9.5c2.5 0 3.5 1.3 3.5 2.7s-1 2.5-3.5 2.5"/>');
  if (name.includes('보안면')) return w('<path d="M5 7a7 7 0 0 1 14 0H5z" fill="currentColor" fill-opacity="0.22"/><path d="M6 7h12l-1 10.5A5 5 0 0 1 12 21a5 5 0 0 1-5-3.5L6 7z" fill="currentColor" fill-opacity="0.1"/><path d="M9 11.5l1.5-1.5M9.5 14.5l3-3"/>');
  if (name.includes('보안경') || name.includes('고글')) return w('<path d="M3 10.5A2.5 2.5 0 0 1 5.5 8h13A2.5 2.5 0 0 1 21 10.5v2.8a3.2 3.2 0 0 1-3.2 3.2h-2.3a2.2 2.2 0 0 1-2-1.3l-.6-1.3a1 1 0 0 0-1.8 0l-.6 1.3a2.2 2.2 0 0 1-2 1.3H6.2A3.2 3.2 0 0 1 3 13.3v-2.8z" fill="currentColor" fill-opacity="0.14"/><path d="M6.5 11.5l1.5-1.5M15 11.5l1.5-1.5"/>');
  if (name.includes('장갑')) return w('<path d="M8 11V5.5a1 1 0 0 1 2 0V10m0-5.5a1 1 0 0 1 2 0V10m0-4.5a1 1 0 0 1 2 0V10m0-3.5a1 1 0 0 1 2 0V14a5 5 0 0 1-5 5H9.5A4.5 4.5 0 0 1 5 14.5v-2a1.2 1.2 0 0 1 2.4 0V13" fill="currentColor" fill-opacity="0.14"/><path d="M7.5 19h8v2h-8z"/>');
  if (name.includes('귀마개')) return w('<path d="M6 6.5a2 2 0 0 1 4 0L9.2 11a1.2 1.2 0 0 1-2.4 0L6 6.5z" fill="currentColor" fill-opacity="0.22"/><path d="M14 6.5a2 2 0 0 1 4 0L17.2 11a1.2 1.2 0 0 1-2.4 0L14 6.5z" fill="currentColor" fill-opacity="0.22"/><path d="M8 12v3a4 4 0 0 0 8 0v-3"/>');
  if (name.includes('경광봉')) return w('<rect x="10" y="5" width="4" height="10" rx="2" fill="currentColor" fill-opacity="0.22"/><rect x="10.4" y="15" width="3.2" height="5.5" rx="0.8"/><path d="M6 7l1.8 1M5.5 11H7.5M18 7l-1.8 1M18.5 11h-2M12 2.2v1.5"/>');
  if (name.includes('각반')) return w('<path d="M8 4h8l-1 12.5 2.5 2.5H6.5L9 16.5 8 4z" fill="currentColor" fill-opacity="0.14"/><path d="M8.5 8.5h7M8.8 12.5h6.4"/>');
  if (name.includes('랜턴')) return w('<path d="M4 12a6.5 6.5 0 0 1 11-4.6"/><rect x="13" y="8" width="4.5" height="6" rx="1.2" fill="currentColor" fill-opacity="0.22"/><path d="M4 12v1.5a2 2 0 0 0 2 2h7"/><path d="M19.5 8.5l2-1M19.5 11h2.2M19.5 13.5l2 1"/>');
  return w('<path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6l7-3z" fill="currentColor" fill-opacity="0.14"/><path d="M9.5 12l1.8 1.8 3.5-3.8"/>');
}

function updateSummary() {
  const gCnt = selectedGears.size;
  const eCnt = selectedEmps.size;
  const total = gCnt * eCnt;
  const sumEl = document.getElementById('bottomSummary');
  const btn = document.getElementById('submitBtn');
  if (total > 0) {
    sumEl.innerHTML = `보호구 ${gCnt}종 × 직원 ${eCnt}명 = 총 ${total}건<small>터치하면 즉시 지급 등록됩니다</small>`;
    btn.disabled = false;
  } else {
    sumEl.innerHTML = `보호구 ${gCnt}종 · 직원 ${eCnt}명 선택<small>보호구와 직원을 모두 선택하세요</small>`;
    btn.disabled = true;
  }
}

function toggleGear(name, btn) {
  if (selectedGears.has(name)) {
    selectedGears.delete(name);
    btn.classList.remove('active');
  } else {
    selectedGears.add(name);
    btn.classList.add('active');
  }
  updateSummary();
}

function toggleEmp(emp, btn) {
  if (selectedEmps.has(emp.id)) {
    selectedEmps.delete(emp.id);
    btn.classList.remove('active');
  } else {
    selectedEmps.set(emp.id, emp);
    btn.classList.add('active');
  }
  updateSummary();
}

function clearGears() {
  selectedGears.clear();
  document.querySelectorAll('.gear-tile.active').forEach(b => b.classList.remove('active'));
  updateSummary();
}

function clearEmps() {
  selectedEmps.clear();
  document.querySelectorAll('.emp-chip.active').forEach(b => b.classList.remove('active'));
  updateSummary();
}

function filterEmps(q) {
  q = (q || '').trim().toLowerCase();
  document.querySelectorAll('.emp-chip').forEach(chip => {
    chip.style.display = (!q || chip.dataset.search.includes(q)) ? 'inline-block' : 'none';
  });
}

async function submitIssue() {
  const gears = Array.from(selectedGears);
  const emps = Array.from(selectedEmps.values());
  if (!gears.length || !emps.length) return;
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = '등록 중...';
  const assignedAt = document.getElementById('assignedAt').value || todayStr();
  const notes = document.getElementById('notes').value.trim();
  let okCount = 0;
  try {
    for (const g of gears) {
      for (const e of emps) {
        const body = new URLSearchParams({
          action: 'save_item',
          compact: '1',
          id: '',
          identifier_type: 'internal',
          identifier_value: '',
          gear_type: g,
          item_name: g,
          status: '지급됨',
          assigned_employee_id: e.id,
          assigned_employee_name: e.name,
          assigned_team: e.team || '',
          assigned_at: assignedAt,
          notes: notes
        });
        const res = await fetch('api.php', { method: 'POST', body });
        const data = await res.json();
        if (!data.ok) throw new Error(data.message || '저장 실패');
        okCount++;
      }
    }
    showToast(`✅ 총 ${okCount}건 지급 등록 완료!`, 'ok');
    const rc = document.getElementById('recentCard');
    const rl = document.getElementById('recentLog');
    rc.style.display = 'block';
    const names = emps.map(e => e.name).join(', ');
    const gNames = gears.join(', ');
    rl.insertAdjacentHTML('afterbegin', `<div class="recent-item"><strong>${assignedAt}</strong> · ${names}<br><span style="color:#0f766e">${gNames}</span> (${okCount}건)</div>`);
    clearGears();
    clearEmps();
    document.getElementById('notes').value = '';
  } catch (err) {
    showToast('⚠ ' + (err.message || '오류 발생'), 'err');
  } finally {
    btn.textContent = '💾 지급 등록';
    updateSummary();
  }
}

(async function init() {
  document.getElementById('assignedAt').value = todayStr();
  try {
    const [gRes, eRes] = await Promise.all([
      fetch('api.php?action=gear_types').then(r => r.json()),
      fetch('api.php?action=employees').then(r => r.json()),
    ]);
    const priority = ['안전모', '안전화', '안전대', '안전조끼', '신호수조끼', '방진마스크', '보안경', '장갑', '귀마개', '경광봉'];
    const gears = (gRes.gear_types || []).slice().sort((a, b) => {
      const ia = priority.indexOf(a.name), ib = priority.indexOf(b.name);
      if (ia !== -1 || ib !== -1) return ia === -1 ? 1 : (ib === -1 ? -1 : ia - ib);
      return a.name.localeCompare(b.name, 'ko');
    });
    const grid = document.getElementById('gearGrid');
    grid.innerHTML = '';
    gears.forEach(g => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'gear-tile';
      btn.innerHTML = `<span class="gear-tile-icon">${gearSvg(g.name)}</span><span class="gear-tile-name">${g.name}</span>`;
      btn.onclick = () => toggleGear(g.name, btn);
      grid.appendChild(btn);
    });

    allEmployees = eRes.employees || [];
    const byTeam = new Map();
    allEmployees.forEach(emp => {
      const t = emp.team || '미지정';
      if (!byTeam.has(t)) byTeam.set(t, []);
      byTeam.get(t).push(emp);
    });
    const cont = document.getElementById('empContainer');
    cont.innerHTML = '';
    byTeam.forEach((list, team) => {
      const sec = document.createElement('div');
      sec.className = 'emp-team';
      sec.innerHTML = `<div class="emp-team-lbl">${team}</div><div class="emp-chips"></div>`;
      const chips = sec.querySelector('.emp-chips');
      list.forEach(emp => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'emp-chip';
        b.dataset.search = (emp.name + ' ' + team).toLowerCase();
        b.textContent = emp.name;
        b.onclick = () => toggleEmp(emp, b);
        chips.appendChild(b);
      });
      cont.appendChild(sec);
    });
  } catch (e) {
    showToast('데이터를 불러오지 못했습니다.', 'err');
  }
})();
</script>
</body>
</html>
