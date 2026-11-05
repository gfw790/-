(() => {
  'use strict';
  let host=null, data=null, busy=false;
  const dirty=new Set();
  const works={COMMON:'공통',BOILER:'폐열보일러',ECONOMIZER:'절탄기',SDR:'SDR',BF:'B/F',SCR:'SCR'};
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const won=v=>`${Number(v||0).toLocaleString('ko-KR')}원`;
  async function api(path='',body) {
    const r=await fetch('/api/preparation/cost-analysis'+path,body?{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});
    const result=await r.json(); if(!r.ok) throw new Error(result.error||'요청을 처리하지 못했습니다.');return result;
  }
  function summaryHTML() {
    const s=data.summary;
    const rows=[['재료비',s.material_cost],['노무비',s.labor_cost],['경비',s.expense_cost],['[총공사비]',s.total_cost],
      ['일반관리비',s.general_admin],['이윤',s.profit],['공급가액',s.supply_amount],['부가가치세',s.vat],['도급액',s.contract_amount]];
    return `<div class="ca-summary-grid">${rows.map(([label,value])=>`<div class="${label.startsWith('[')||label==='도급액'?'ca-summary-strong':''}"><span>${esc(label.replace(/[\[\]]/g,''))}</span><strong>${won(value)}</strong></div>`).join('')}</div><p class="prep-intro">${esc(s.basis_note)}</p>`;
  }
  function facilitiesHTML() {
    return `<div class="ca-table-scroll"><table class="ca-table"><thead><tr><th>설비</th><th>공종</th><th>수량</th><th>합계</th><th>재료비</th><th>노무비</th><th>경비</th></tr></thead><tbody>
      ${data.facilities.map(f=>`<tr><td>${esc(f.name)}</td><td>${esc(works[f.work_type])}</td><td>${esc(f.quantity)}</td><td>${won(f.total)}</td><td>${won(f.material)}</td><td>${won(f.labor)}</td><td>${won(f.expense)}</td></tr>`).join('')}
      </tbody></table></div>`;
  }
  function itemHTML(i) {
    return `<details class="ta-item" data-id="${i.id}"><summary><span class="ta-badge">${esc(works[i.work_type])}</span><span class="ta-badge">${esc(i.priority)}</span><strong>${esc(i.title)}</strong><br><span>${esc(i.category)} · <span class="ta-status-label">${esc(i.status)}</span> · ${esc(i.cost_ref)}</span></summary><div class="ta-item-body">
      <h4>원가내역서·과업지시서 대조 내용</h4><p>${esc(i.requirement)}</p>
      ${i.related_task_code?`<p>관련 과업 분석 항목: ${esc(i.related_task_code)}</p>`:''}
      <div class="ta-question"><strong>발주처 미팅에서 확인할 질문</strong><p>${esc(i.question)}</p><small>원가내역서에서 도출한 확인안입니다. 발주처의 확정 답변이 아닙니다.</small></div>
      <form class="ta-form" data-id="${i.id}"><div class="ta-edit"><div><label for="ca-status-${i.id}">검토 상태</label><select id="ca-status-${i.id}" name="status">${data.statuses.map(s=>`<option ${s===i.status?'selected':''}>${esc(s)}</option>`).join('')}</select></div>
      <div><label for="ca-owner-${i.id}">확인 담당자</label><input id="ca-owner-${i.id}" name="owner" maxlength="100" value="${esc(i.owner)}"></div>
      <div><label for="ca-due-${i.id}">확인 기한</label><input id="ca-due-${i.id}" name="due_date" type="date" value="${esc(i.due_date)}"></div></div>
      <label for="ca-note-${i.id}">검토 결과·확인 근거·쟁점 메모</label><textarea id="ca-note-${i.id}" name="note" maxlength="10000" placeholder="확인한 내용과 근거, 추가로 확인할 사항을 기록하세요.">${esc(i.note)}</textarea>
      <div class="prep-actions"><button class="prep-primary" type="submit">검토 내용 저장</button><small class="ta-save-message" role="status">${i.revision?'저장됨 · '+esc(i.updated_at):'아직 검토 내용을 저장하지 않았습니다.'}</small></div></form></div></details>`;
  }
  async function mount(element) {
    host=element;dirty.clear();data=await api();
    host.innerHTML=`<section aria-label="원가 분석"><div class="ta-banner"><h3>원가내역서 분석</h3><div>2026년도 · 폐열보일러 외 부대설비 세정 용역 단가계약</div><p>${data.source_available?`<a href="/api/preparation/cost-analysis/source" target="_blank" rel="noopener">${esc(data.source_name)} 원문 열기</a>`:'원문 파일을 찾을 수 없습니다.'}</p></div>
      <p class="prep-notice">원가내역서의 수량·단가·금액을 과업지시서 분석과 대조해 확인이 필요한 사항을 정리했습니다. 검토 확인완료는 발주처 확정 답변을 의미하지 않습니다.</p>
      <h4>공사원가계산서 총괄</h4>${summaryHTML()}
      <h4>설비별 내역</h4>${facilitiesHTML()}
      <h4>확인 필요 사항 (${data.items.length}건)</h4>
      <div id="ca-summary" class="ta-summary"></div><div id="ca-feedback" class="ta-feedback" role="status"></div><div id="ca-items">${data.items.map(itemHTML).join('')}</div></section>`;
    host.querySelector('#ca-summary').innerHTML=`<div><strong>${data.items.length}</strong>확인 항목</div><div><strong>${data.review.reviewed}</strong>검토 확인완료</div><div><strong>${data.review.pending}</strong>추가 확인 필요</div>`;
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
          host.querySelector('#ca-feedback').textContent='검토 내용을 저장했습니다.';
          host.querySelector('#ca-summary').innerHTML=`<div><strong>${data.items.length}</strong>확인 항목</div><div><strong>${data.items.filter(i=>i.status==='확인완료').length}</strong>검토 확인완료</div><div><strong>${data.items.filter(i=>!['미검토','확인완료'].includes(i.status)).length}</strong>추가 확인 필요</div>`;
        }catch(e){host.querySelector('#ca-feedback').textContent=e.message;host.querySelector('#ca-feedback').dataset.error='true';form.querySelector('.ta-save-message').textContent=e.message;}
        finally{busy=false;host.inert=false;}
      });
    });
  }
  function canLeave(){if(busy)return false;if(dirty.size&&host?.isConnected){if(!confirm('저장하지 않은 원가 검토 내용이 있습니다. 저장하지 않고 이동할까요?'))return false;}dirty.clear();return true;}
  window.addEventListener('beforeunload',e=>{if(busy||(dirty.size&&host?.isConnected)){e.preventDefault();e.returnValue='';}});
  window.CostAnalysis={mount,canLeave};
})();
