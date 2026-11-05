(() => {
  'use strict';
  const root = document.querySelector('#preparation-root');
  const stages = ['과업·원가 분석','발주처 미팅','기존자료 수집','현장조사','내부 사전검토','미확인·쟁점사항','계획회의','실행계획','착공 준비점검','착공'];
  const works = {COMMON:'공통',BOILER:'폐열보일러',ECONOMIZER:'절탄기',SDR:'SDR',BF:'B/F',SCR:'SCR'};
  let stage = 1, data = null, qid = null, dirty = false, busy = false;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const $ = selector => root.querySelector(selector);
  async function request(path, method = 'GET', payload) {
    const response = await fetch(`/api/preparation/${path}`, {method, ...(payload === undefined ? {} : {headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || '요청을 처리하지 못했습니다.');
    return result;
  }
  function message(text, error = false) {
    const box = $('#prep-message');
    if (box) {box.textContent = text; box.dataset.error = String(error);}
  }
  async function run(action) {
    if (busy) return;
    busy = true;
    root.inert = true;
    root.setAttribute('aria-busy','true');
    root.querySelectorAll('button').forEach(b => {b.dataset.wasDisabled = String(b.disabled); b.disabled = true;});
    try {await action();} catch(error) {message(error.message, true);}
    finally {
      busy = false; root.inert = false; root.removeAttribute('aria-busy');
      root.querySelectorAll('button').forEach(b => {if ('wasDisabled' in b.dataset) {b.disabled = b.dataset.wasDisabled === 'true'; delete b.dataset.wasDisabled;}});
    }
  }
  function canLeave() {return !busy && (!dirty || window.confirm('저장하지 않은 답변이 있습니다. 저장하지 않고 이동할까요?')) && (!window.TaskAnalysis || window.TaskAnalysis.canLeave()) && (!window.CostAnalysis || window.CostAnalysis.canLeave());}
  function shell() {
    root.innerHTML = `<h2>착공 준비</h2><p class="prep-intro">확인할 질문을 따라 답변하고, 미확인 사항을 다음 확인으로 이어갑니다.</p>
      <details class="prep-stage-list" open><summary>진행 단계 · ${esc(stages[stage])}</summary><nav class="prep-stages" aria-label="착공 준비 단계">${stages.map((s,i) => `<button type="button" data-stage="${i}" ${stage === i ? 'aria-current="step"' : ''}>${i+1}. ${s}<small>${i === 0 ? '과업 분석' : i === 1 ? '미팅 문답' : i === 5 ? '연결된 확인사항' : '준비중'}</small></button>`).join('')}</nav></details>
      <div id="prep-message" class="prep-message" role="status" aria-live="polite"></div><div id="prep-view"></div>`;
  }
  async function overview() {
    data = null; qid = null; dirty = false; shell();
    if (stage === 0) {
      $('#prep-view').innerHTML = '<div id="ta-root"></div><div id="ca-root"></div>';
      await window.TaskAnalysis.mount($('#ta-root'));
      await window.CostAnalysis.mount($('#ca-root'));
    } else if (stage === 1) {
      const sessions = await request('sessions');
      $('#prep-view').innerHTML = `<section class="prep-card"><h3>발주처 미팅</h3><p class="prep-notice">과업지시서 분석(1단계)에서 도출한 33개 질문입니다. 발주처와 확인한 내용을 답변으로 기록하세요.</p>
        <label for="prep-title">새 미팅 제목</label><input id="prep-title" maxlength="200" placeholder="예: 1차 발주처 사전 미팅">
        <div class="prep-actions"><button type="button" class="prep-primary" data-action="start">발주처 미팅 시작</button></div></section>
        <h3>저장된 미팅</h3><div class="prep-sessions">${sessions.map(s => `<article class="prep-session"><strong>${esc(s.title)}</strong><p>제${s.id}회 · ${s.status === 'completed' ? '문답 저장 완료' : '작성 중'}<br>${esc(s.updated_at.replace('T',' '))}</p><button type="button" data-open="${s.id}">${s.status === 'completed' ? '답변 확인·수정' : '이어서 작성'}</button></article>`).join('') || '<p>아직 시작한 미팅이 없습니다.</p>'}</div>`;
    } else if (stage === 5) {
      const issues = await request('issues');
      $('#prep-view').innerHTML = `<h3>미확인·쟁점사항</h3><p class="prep-intro">답변 상태와 연결된 목록입니다. 조건에서 제외된 항목은 보존하며 현재 확인 대상과 구분합니다.</p>${issues.map(i => `<article class="prep-card"><header><span>${esc(i.session_title)}</span><strong>${!i.applicable ? '조건 제외 · 기록 보존' : i.resolved_at ? '확인 처리됨' : esc(i.status)}</strong></header><h3>${esc(i.title)}</h3><p>${esc(i.note || '메모 없음')}</p><button type="button" data-open="${i.session_id}" data-question="${i.question_id}">해당 미팅 열기</button></article>`).join('') || '<div class="prep-card">연결된 미확인 사항이 없습니다.</div>'}`;
    } else $('#prep-view').innerHTML = `<section class="prep-card"><h3>${esc(stages[stage])}</h3><p>준비중입니다. 이번 버전은 발주처 미팅 문답을 제공합니다.</p><button type="button" data-stage="1">발주처 미팅으로 이동</button></section>`;
  }
  function currentQuestion() {return data.questions.find(q => q.id === qid);}
  function controls(q, value) {
    const choices = q.options.length ? q.options : (q.answer_type === 'yes_no' ? [{value:'yes',label:'예'},{value:'no',label:'아니오'}] : []);
    if (['yes_no','single_choice','multi_choice'].includes(q.answer_type)) {
      return `<fieldset><legend>답변</legend>${choices.map(o => `<label class="prep-choice"><input name="value" type="${q.answer_type === 'multi_choice' ? 'checkbox' : 'radio'}" value="${esc(o.value)}" ${(Array.isArray(value) ? value.includes(o.value) : value === o.value) ? 'checked' : ''}>${esc(o.label)}</label>`).join('')}</fieldset>`;
    }
    if (q.answer_type === 'textarea') return `<label for="prep-value">답변</label><textarea id="prep-value" name="value" maxlength="20000">${esc(value)}</textarea>`;
    const type = {datetime:'datetime-local',number:'number',date:'date',text:'text'}[q.answer_type] || 'text';
    return `<label for="prep-value">답변</label><input id="prep-value" name="value" type="${type}" ${type === 'number' ? 'step="any"' : ''} maxlength="20000" value="${esc(value)}">`;
  }
  function card() {
    dirty = false;
    const q = currentQuestion();
    if (!q) {$('#prep-view').innerHTML = '<div class="prep-card">현재 적용되는 질문이 없습니다.</div>'; return;}
    const answer = data.answers[qid], index = data.visible_ids.indexOf(qid);
    const hiddenSaved = Object.keys(data.answers).filter(id => !data.visible_ids.includes(Number(id))).length;
    $('#prep-view').innerHTML = `<div class="prep-actions"><button type="button" data-action="list">미팅 목록</button><span>${esc(data.session.title)}</span></div>
      <form id="prep-answer-form" class="prep-card"><header><span>발주처 미팅 · ${esc(works[q.work_type])}</span><strong>${index+1} / ${data.visible_ids.length}</strong></header>
      <progress value="${data.saved_count}" max="${data.visible_ids.length}" aria-label="질문 저장 진행"></progress><p class="prep-intro">${data.saved_count}개 저장됨${hiddenSaved ? ` · 조건 제외 답변 ${hiddenSaved}개 보존` : ''}</p>
      <h3 tabindex="-1" id="prep-question-title">${esc(q.question_text)}${q.required ? ' *' : ''}</h3><p class="prep-intro">${esc(q.help_text)}</p>
      ${controls(q,answer?.value ?? '')}
      <label for="prep-status">확인상태</label><select id="prep-status" name="status">${data.statuses.map(s => `<option ${s === (answer?.status || '미확인') ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select>
      <label for="prep-note">메모</label><textarea id="prep-note" name="note" maxlength="10000">${esc(answer?.note || '')}</textarea>
      <p class="prep-intro">* 필수 질문도 미정이면 ‘미확인’으로 저장할 수 있습니다. 답변은 저장 버튼을 눌러야 보관됩니다.</p>
      <div class="prep-actions"><button type="button" data-action="previous" ${index === 0 ? 'disabled' : ''}>이전</button><button type="button" data-action="save">현재 답변 저장</button><button type="submit" class="prep-primary">${index === data.visible_ids.length-1 ? '저장 후 문답 마침' : '저장 후 다음'}</button></div>
      </form><section class="prep-card"><h3>사진·근거 첨부</h3><p class="prep-intro">JPG·PNG·WEBP·PDF, 파일당 15MB. 첨부 시 현재 답변도 함께 저장합니다.</p>
      <label for="prep-file">파일 선택</label><input id="prep-file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
      <label for="prep-camera">휴대폰 사진 촬영</label><input id="prep-camera" type="file" accept="image/jpeg,image/png,image/webp" capture="environment">
      <label for="prep-description">첨부 설명</label><input id="prep-description" maxlength="1000">
      <div class="prep-actions"><button type="button" data-action="upload">선택한 파일 첨부</button></div>
      <ul class="prep-attachments">${(answer?.attachments || []).map(a => `<li><a href="/api/preparation/attachments/${a.id}/download">${esc(a.original_name)}</a> (${Math.ceil(a.size_bytes/1024)}KB) ${esc(a.description)}</li>`).join('') || '<li>첨부파일 없음</li>'}</ul></section>`;
  }
  async function open(sid, requested) {
    stage = 1; shell(); data = await request(`sessions/${sid}`);
    $('.prep-stage-list').open = false;
    const preferred = requested || data.session.current_question_id;
    qid = data.visible_ids.includes(preferred) ? preferred : data.visible_ids.find(id => !data.answers[id]) || data.visible_ids[0];
    card();
    if (requested && !data.visible_ids.includes(requested)) message('해당 질문은 현재 조건에서 제외되어 있습니다. 기존 답변은 보존되어 있습니다.');
  }
  function payload() {
    const form = $('#prep-answer-form');
    if (!form.reportValidity()) throw new Error('입력 형식을 확인하세요.');
    const values = new FormData(form), q = currentQuestion();
    let value = q.answer_type === 'multi_choice' ? values.getAll('value') : values.get('value');
    if (q.answer_type === 'number' && value !== '' && value !== null) value = Number(value);
    return {value, status:values.get('status'), note:values.get('note'), revision:data.answers[qid]?.revision || 0};
  }
  async function save(next) {
    const body = payload();
    data = await request(`sessions/${data.session.id}/answers/${qid}`, 'PUT', body);
    dirty = false;
    if (next) {
      const index = data.visible_ids.indexOf(qid);
      if (index < data.visible_ids.length - 1) {
        qid = data.visible_ids[index+1];
        data = await request(`sessions/${data.session.id}`, 'PUT', {current_question_id:qid});
      } else {
        data = await request(`sessions/${data.session.id}`, 'PUT', {status:'completed',current_question_id:qid});
        card(); message('문답을 모두 저장했습니다. 미확인 사항의 해결이나 착공 승인을 의미하지 않습니다.'); return;
      }
    }
    card(); message('답변을 저장했습니다.'); $('#prep-question-title')?.focus();
  }
  root.addEventListener('submit', event => {if(event.target.id === 'prep-answer-form') {event.preventDefault(); run(() => save(true));}});
  root.addEventListener('input', event => {if (event.target.closest('#prep-answer-form')) {dirty = true; message('저장하지 않은 변경사항이 있습니다.');}});
  root.addEventListener('change', event => {
    if (event.target.id === 'prep-file') $('#prep-camera').value = '';
    if (event.target.id === 'prep-camera') $('#prep-file').value = '';
  });
  root.addEventListener('click', event => {
    const button = event.target.closest('button'); if (!button || busy) return;
    if (button.dataset.stage !== undefined) {
      if (!canLeave()) return; stage = Number(button.dataset.stage); run(overview); return;
    }
    if (button.dataset.open) {if (canLeave()) run(() => open(Number(button.dataset.open),Number(button.dataset.question))); return;}
    const action = button.dataset.action;
    if (action === 'start') run(async () => {const created = await request('sessions','POST',{title:$('#prep-title').value}); await open(created.session.id);});
    if (action === 'list' && canLeave()) run(overview);
    if (action === 'save') run(() => save(false));
    if (action === 'previous' && canLeave()) run(async () => {
      const target = data.visible_ids[data.visible_ids.indexOf(qid)-1];
      const fresh = await request(`sessions/${data.session.id}`,'PUT',{current_question_id:target}); data = fresh; qid = target; card();
    });
    if (action === 'upload') run(async () => {
      const file = $('#prep-file').files[0] || $('#prep-camera').files[0];
      if (!file) throw new Error('먼저 사진 또는 파일을 선택하세요.');
      if (file.size > 15*1024*1024) throw new Error('파일은 15MB 이하로 첨부하세요.');
      const description = $('#prep-description').value;
      data = await request(`sessions/${data.session.id}/answers/${qid}`, 'PUT', payload()); dirty = false;
      const aid = data.answers[qid].id;
      const response = await fetch(`/api/preparation/answers/${aid}/attachments?filename=${encodeURIComponent(file.name)}&description=${encodeURIComponent(description)}`,{method:'POST',headers:{'Content-Type':'application/octet-stream'},body:file});
      const result = await response.json(); if (!response.ok) throw new Error(`답변은 저장되었습니다. 첨부 실패: ${result.error}`);
      data = await request(`sessions/${data.session.id}`); card(); message('답변과 첨부파일을 저장했습니다.');
    });
  });
  document.querySelector('#show-preparation').addEventListener('click', () => {
    if (busy || !root.hidden) return;
    root.hidden = false; document.querySelector('#safety-root').hidden = true; document.querySelector('#billing-root').hidden = true;
    document.querySelector('#show-preparation').setAttribute('aria-pressed','true');
    document.querySelector('#show-safety').setAttribute('aria-pressed','false');
    document.querySelector('#show-billing').setAttribute('aria-pressed','false');
    run(overview);
  });
  document.querySelector('#show-safety').addEventListener('click', () => {
    if (!canLeave()) return;
    dirty = false; root.hidden = true; document.querySelector('#safety-root').hidden = false; document.querySelector('#billing-root').hidden = true;
    document.querySelector('#show-preparation').setAttribute('aria-pressed','false');
    document.querySelector('#show-safety').setAttribute('aria-pressed','true');
    document.querySelector('#show-billing').setAttribute('aria-pressed','false');
  });
  window.addEventListener('beforeunload', event => {if (dirty || busy) {event.preventDefault();event.returnValue = '';}});
  window.Preparation = {canLeave};
})();
