(() => {
  'use strict';
  let host=null, data=null, busy=false;
  const dirty=new Set();
  const works={COMMON:'공통',BOILER:'폐열보일러',ECONOMIZER:'절탄기',SDR:'SDR',BF:'B/F',SCR:'SCR'};
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function api(path='',body) {
    const r=await fetch('/api/preparation/task-analysis'+path,body?{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});
    const result=await r.json(); if(!r.ok) throw new Error(result.error||'요청을 처리하지 못했습니다.');return result;
  }
  function notice(text,error=false) {const n=host.querySelector('#ta-feedback');n.textContent=text;n.dataset.error=String(error);}
  function summary() {
    host.querySelector('#ta-summary').innerHTML=`<div><strong>${data.items.length}</strong>분석 항목</div><div><strong>${data.items.filter(i=>i.status==='확인완료').length}</strong>검토 확인완료</div><div><strong>${data.items.filter(i=>!['미검토','확인완료'].includes(i.status)).length}</strong>추가 확인 필요</div>`;
  }
  function itemHTML(i) {
    return `<details class="ta-item" data-id="${i.id}"><summary><span class="ta-badge">${esc(works[i.work_type])}</span><span class="ta-badge">${esc(i.priority)}</span><strong>${esc(i.title)}</strong><br><span>${esc(i.category)} · <span class="ta-status-label">${esc(i.status)}</span> · PDF ${esc(i.pages)}쪽</span></summary><div class="ta-item-body">
      <h4>문서에 명시된 내용</h4><p>${esc(i.requirement)}</p><h4>책임 구분</h4><p>${esc(i.responsibility)}</p>
      <p>근거: ${esc(i.section)} · ${i.pages.split(',').map(p=>data.source_available?`<a href="/api/preparation/task-analysis/source#page=${Number(p)}" target="_blank" rel="noopener">PDF ${Number(p)}쪽</a>`:`PDF ${Number(p)}쪽`).join(' / ')}</p>
      <div class="ta-question"><strong>발주처 미팅에서 확인할 질문</strong><p>${esc(i.question)}</p><small>문서에서 도출한 확인안입니다. 발주처의 확정 답변이 아닙니다.</small></div>
      <form class="ta-form" data-id="${i.id}"><div class="ta-edit"><div><label for="ta-status-${i.id}">검토 상태</label><select id="ta-status-${i.id}" name="status">${data.statuses.map(s=>`<option ${s===i.status?'selected':''}>${esc(s)}</option>`).join('')}</select></div>
      <div><label for="ta-owner-${i.id}">확인 담당자</label><input id="ta-owner-${i.id}" name="owner" maxlength="100" value="${esc(i.owner)}"></div>
      <div><label for="ta-due-${i.id}">확인 기한</label><input id="ta-due-${i.id}" name="due_date" type="date" value="${esc(i.due_date)}"></div></div>
      <label for="ta-note-${i.id}">검토 결과·확인 근거·쟁점 메모</label><textarea id="ta-note-${i.id}" name="note" maxlength="10000" placeholder="확인한 내용과 근거, 추가로 확인할 사항을 기록하세요.">${esc(i.note)}</textarea>
      <div class="prep-actions"><button class="prep-primary" type="submit">검토 내용 저장</button><small class="ta-save-message" role="status">${i.revision?'저장됨 · '+esc(i.updated_at):'아직 검토 내용을 저장하지 않았습니다.'}</small></div></form></div></details>`;
  }
  function filter() {
    const work=host.querySelector('#ta-work').value, status=host.querySelector('#ta-status').value, term=host.querySelector('#ta-search').value.trim().toLowerCase();
    let count=0;
    data.items.forEach(i=>{const show=(!work||i.work_type===work)&&(!status||i.status===status)&&(!term||[i.title,i.requirement,i.question,i.note,i.owner,i.category].join(' ').toLowerCase().includes(term));host.querySelector(`.ta-item[data-id="${i.id}"]`).hidden=!show;if(show)count++;});
    host.querySelector('#ta-count').textContent=`${count}개 항목 표시`;
  }
  async function mount(element) {
    host=element;dirty.clear();data=await api();
    host.innerHTML=`<section aria-label="과업 분석"><div class="ta-banner"><h3>과업지시서 분석</h3><div>2026년 8월 · 폐열보일러 외 부대설비 세정 용역 단가계약</div><p>${data.source_available?`<a href="/api/preparation/task-analysis/source" target="_blank" rel="noopener">${esc(data.source_name)} 원문 열기</a>`:'원문 파일을 찾을 수 없습니다.'} · PDF 19쪽, 표지 포함 페이지 기준</p></div>
      <p class="prep-notice">과업지시서에 명시된 내용과 추가 확인 질문을 정리했습니다. 검토 확인완료는 작업 완료나 착공 승인을 의미하지 않습니다.</p>
      <div id="ta-summary" class="ta-summary"></div>
      <div class="ta-filters"><div><label for="ta-work">공종</label><select id="ta-work"><option value="">전체 공종</option>${Object.entries(works).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}</select></div>
      <div><label for="ta-status">검토 상태</label><select id="ta-status"><option value="">전체 상태</option>${data.statuses.map(s=>`<option>${esc(s)}</option>`).join('')}</select></div>
      <div><label for="ta-search">항목·쟁점 검색</label><input id="ta-search" type="search" placeholder="예: 압축공기, 패킹, 보수"></div></div>
      <div class="prep-actions"><button type="button" id="ta-client-filter">발주처 재확인 항목</button><button type="button" data-stage="1">발주처 미팅으로 이동</button></div>
      <p id="ta-count"></p><div id="ta-feedback" class="ta-feedback" role="status"></div><div id="ta-items">${data.items.map(itemHTML).join('')}</div></section>`;
    summary();filter();
    host.querySelectorAll('.ta-filters input,.ta-filters select').forEach(e=>e.addEventListener('input',filter));
    host.querySelector('#ta-client-filter').addEventListener('click',()=>{host.querySelector('#ta-status').value='발주처 재확인';filter();});
    host.querySelectorAll('.ta-form').forEach(form=>{
      form.addEventListener('input',()=>{dirty.add(Number(form.dataset.id));form.querySelector('.ta-save-message').textContent='저장하지 않은 변경사항이 있습니다.';});
      form.addEventListener('submit',async event=>{
        event.preventDefault();event.stopPropagation();if(busy)return;
        const id=Number(form.dataset.id), current=data.items.find(i=>i.id===id);
        const body={...Object.fromEntries(new FormData(form)),revision:current.revision};
        busy=true;host.inert=true;
        try {
          const updated=await api('/'+id,body);Object.assign(current,updated);dirty.delete(id);
          form.closest('.ta-item').querySelector('.ta-status-label').textContent=updated.status;
          form.querySelector('.ta-save-message').textContent='저장됨 · '+updated.updated_at;
          notice('검토 내용을 저장했습니다.');summary();
        }catch(e){notice(e.message,true);form.querySelector('.ta-save-message').textContent=e.message;}
        finally{busy=false;host.inert=false;}
      });
    });
  }
  function canLeave(){if(busy)return false;if(dirty.size&&host?.isConnected){if(!confirm('저장하지 않은 과업 검토 내용이 있습니다. 저장하지 않고 이동할까요?'))return false;}dirty.clear();return true;}
  window.addEventListener('beforeunload',e=>{if(busy||(dirty.size&&host?.isConnected)){e.preventDefault();e.returnValue='';}});
  window.TaskAnalysis={mount,canLeave};
})();
