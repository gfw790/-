(() => {
  'use strict';
  const root = document.querySelector('#billing-root');
  const works = {COMMON:'공통', BOILER:'폐열보일러', ECONOMIZER:'절탄기', SDR:'반건식 반응탑(SDR)', BF:'여과집진기(B/F)', SCR:'촉매환원탑(SCR)'};
  const employmentLabels = {AGENCY:'인력소개소', DIRECT:'임시직(직접고용)'};
  let tab = 'billing', busy = false, editingId = null, pendingMessage = null, addingSettlementFor = null;
  let billingData = null, expenditureData = null, personnelData = null, safetyBudgetData = null, editingHeader = false;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const won = value => `${Number(value || 0).toLocaleString('ko-KR')}원`;
  async function api(path, options = {}) {
    const response = await fetch(`/api/billing/${path}`, options);
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || '요청을 처리하지 못했습니다.');
    return result;
  }
  function message(text, error = false) {
    const box = root.querySelector('#billing-message');
    if (box) { box.textContent = text; box.dataset.error = String(error); }
  }
  async function run(action) {
    if (busy) return;
    busy = true; root.setAttribute('aria-busy', 'true');
    try { await action(); } catch (error) { message(error.message, true); }
    finally { busy = false; root.removeAttribute('aria-busy'); }
  }

  // ---------- Billing (청구·입금) ----------
  function billingSummaryHTML() {
    const d = billingData;
    const rows = [['총 도급액', d.contract_amount], ['누적 청구액', d.total_billed], ['누적 입금액', d.total_received], ['잔액', d.remaining_amount]];
    return `<div class="ca-summary-grid">${rows.map(([label, value], i) => `<div class="${i === 3 ? 'ca-summary-strong' : ''}"><span>${esc(label)}</span><strong>${won(value)}</strong></div>`).join('')}</div>`;
  }
  function billingFacilityTableHTML() {
    return `<div class="ca-table-scroll"><table class="ca-table"><thead><tr><th>설비</th><th>계약수량</th><th>단가</th><th>계약금액</th><th>집행수량</th><th>잔여수량</th><th>청구액</th><th>잔여금액</th></tr></thead><tbody>
      ${billingData.facilities.map(f => `<tr><td>${esc(f.name)}</td><td>${f.contract_quantity}식</td><td>${won(f.unit_price)}</td><td>${won(f.contract_amount)}</td><td>${f.executed_quantity}식</td><td>${f.remaining_quantity}식</td><td>${won(f.billed_amount)}</td><td>${won(f.remaining_amount)}</td></tr>`).join('')}
      </tbody></table></div>`;
  }
  function billingFacilityOptions(selected) {
    return billingData.facilities.map(f => `<option value="${f.work_type}" ${f.work_type === selected ? 'selected' : ''}>${esc(f.name)}</option>`).join('');
  }
  function statusOptions(selected) {
    return billingData.statuses.map(s => `<option ${s === selected ? 'selected' : ''}>${esc(s)}</option>`).join('');
  }
  function billingLineFormHTML(line) {
    const v = line || {work_type: billingData.facilities[0].work_type, execution_date: '', quantity: 1, unit_price: billingData.facilities[0].unit_price, amount: '', invoice_no: '', invoice_date: '', request_date: '', payment_date: '', payment_amount: '', status: billingData.statuses[0], note: ''};
    return `<form class="billing-line-form" data-kind="billing" data-id="${line ? line.id : ''}" data-revision="${line ? line.revision : 0}">
      <div class="billing-form-grid">
        <label>설비<select name="work_type">${billingFacilityOptions(v.work_type)}</select></label>
        <label>수행일자<input name="execution_date" type="date" value="${esc(v.execution_date)}" required></label>
        <label>수량(식)<input name="quantity" type="number" step="0.5" min="0.5" max="4" value="${esc(v.quantity)}" required></label>
        <label>단가<input name="unit_price" type="number" min="0" value="${esc(v.unit_price)}"></label>
        <label>금액<input name="amount" type="number" min="0" value="${esc(v.amount)}" placeholder="비우면 단가×수량"></label>
        <label>상태<select name="status">${statusOptions(v.status)}</select></label>
        <label>세금계산서 번호<input name="invoice_no" maxlength="100" value="${esc(v.invoice_no)}"></label>
        <label>세금계산서 발행일<input name="invoice_date" type="date" value="${esc(v.invoice_date)}"></label>
        <label>청구일<input name="request_date" type="date" value="${esc(v.request_date)}"></label>
        <label>입금일<input name="payment_date" type="date" value="${esc(v.payment_date)}"></label>
        <label>입금액<input name="payment_amount" type="number" min="0" value="${esc(v.payment_amount)}"></label>
      </div>
      <label>비고<textarea name="note" maxlength="2000">${esc(v.note)}</textarea></label>
      <div class="prep-actions">
        <button type="submit" class="prep-primary">${line ? '수정 저장' : '청구 기록 추가'}</button>
        ${line ? '<button type="button" data-cancel-edit>취소</button><button type="button" data-delete-line class="billing-delete">삭제</button>' : ''}
      </div>
    </form>`;
  }
  function billingAttachmentsHTML(line) {
    const list = (line.attachments || []).map(a => `<li><a href="/api/billing/attachments/${a.id}/download">${esc(a.original_name)}</a> (${Math.ceil(a.size_bytes / 1024)}KB)</li>`).join('') || '<li>첨부파일 없음</li>';
    return `<div class="billing-attachments"><ul>${list}</ul>
      <label>증빙 파일(세금계산서 등) 첨부<input type="file" class="billing-file" data-kind="billing" data-line-id="${line.id}" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>`;
  }
  function billingLineRowHTML(line) {
    const isEditing = editingId === `billing-${line.id}`;
    return `<article class="billing-line ${isEditing ? 'editing' : ''}" data-id="${line.id}">
      <div class="billing-line-summary" data-toggle="billing-${line.id}">
        <span class="ta-badge">${esc(works[line.work_type])}</span>
        <span class="ta-badge">${esc(line.status)}</span>
        <strong>${esc(line.execution_date)} · ${line.quantity}식 · ${won(line.amount)}</strong>
        <span>세금계산서 ${esc(line.invoice_no || '-')} · 입금 ${won(line.payment_amount)}</span>
      </div>
      ${isEditing ? `<div class="billing-line-body">${billingLineFormHTML(line)}${billingAttachmentsHTML(line)}</div>` : ''}
    </article>`;
  }
  function billingTabHTML() {
    const d = billingData;
    return `<h4>총괄 현황</h4>${billingSummaryHTML()}
      <h4>설비별 집행 현황</h4>${billingFacilityTableHTML()}
      <h4>새 청구 기록 추가</h4>${billingLineFormHTML(null)}
      <h4>청구·정산 기록 (${d.lines.length}건)</h4>
      <div>${d.lines.length ? d.lines.map(billingLineRowHTML).join('') : '<p class="prep-intro">등록된 청구 기록이 없습니다.</p>'}</div>`;
  }

  // ---------- Expenditure (실 집행) ----------
  function expenditureSummaryHTML() {
    const d = expenditureData;
    const rows = [['재료비 집행', d.category_totals['재료비']], ['노무비 집행', d.category_totals['노무비']],
      ['경비 집행', d.category_totals['경비']], ['기타 집행', d.category_totals['기타']], ['총 집행액', d.total]];
    return `<div class="ca-summary-grid">${rows.map(([label, value], i) => `<div class="${i === 4 ? 'ca-summary-strong' : ''}"><span>${esc(label)}</span><strong>${won(value)}</strong></div>`).join('')}</div>`;
  }
  function expenditureBudgetTableHTML() {
    const d = expenditureData;
    const rows = [['재료비', d.budget.material_cost, d.category_totals['재료비']], ['노무비', d.budget.labor_cost, d.category_totals['노무비']], ['경비', d.budget.expense_cost, d.category_totals['경비']]];
    return `<div class="ca-table-scroll"><table class="ca-table"><thead><tr><th>비목</th><th>원가내역서 예산</th><th>실 집행액</th><th>차이(예산-집행)</th></tr></thead><tbody>
      ${rows.map(([label, budget, actual]) => `<tr><td>${esc(label)}</td><td>${won(budget)}</td><td>${won(actual)}</td><td>${won(budget - actual)}</td></tr>`).join('')}
      </tbody></table></div>`;
  }
  function expenditureFacilityTableHTML() {
    const totals = expenditureData.facility_totals;
    return `<div class="ca-table-scroll"><table class="ca-table"><thead><tr><th>구분</th><th>집행액</th></tr></thead><tbody>
      ${Object.keys(works).map(wt => `<tr><td>${esc(works[wt])}</td><td>${won(totals[wt] || 0)}</td></tr>`).join('')}
      </tbody></table></div>`;
  }
  function expenditureFacilityOptions(selected) {
    return Object.keys(works).map(wt => `<option value="${wt}" ${wt === selected ? 'selected' : ''}>${esc(works[wt])}</option>`).join('');
  }
  function categoryOptions(selected) {
    return expenditureData.categories.map(c => `<option ${c === selected ? 'selected' : ''}>${esc(c)}</option>`).join('');
  }
  function expenditureLineFormHTML(line) {
    const v = line || {work_type: 'COMMON', category: expenditureData.categories[0], expense_date: '', description: '', quantity: 1, unit: '', unit_price: '', amount: '', payee: '', receipt_no: '', payment_method: '', note: ''};
    return `<form class="billing-line-form" data-kind="expenditure" data-id="${line ? line.id : ''}" data-revision="${line ? line.revision : 0}">
      <div class="billing-form-grid">
        <label>구분<select name="work_type">${expenditureFacilityOptions(v.work_type)}</select></label>
        <label>비목<select name="category">${categoryOptions(v.category)}</select></label>
        <label>집행일자<input name="expense_date" type="date" value="${esc(v.expense_date)}" required></label>
        <label>내용<input name="description" maxlength="500" value="${esc(v.description)}"></label>
        <label>수량<input name="quantity" type="number" step="0.01" min="0.01" value="${esc(v.quantity)}" required></label>
        <label>단위<input name="unit" maxlength="20" value="${esc(v.unit)}"></label>
        <label>단가<input name="unit_price" type="number" min="0" value="${esc(v.unit_price)}"></label>
        <label>금액<input name="amount" type="number" min="0" value="${esc(v.amount)}" placeholder="비우면 단가×수량"></label>
        <label>거래처<input name="payee" maxlength="200" value="${esc(v.payee)}"></label>
        <label>증빙번호<input name="receipt_no" maxlength="100" value="${esc(v.receipt_no)}"></label>
        <label>결제수단<input name="payment_method" maxlength="50" value="${esc(v.payment_method)}"></label>
      </div>
      <label>비고<textarea name="note" maxlength="2000">${esc(v.note)}</textarea></label>
      <div class="prep-actions">
        <button type="submit" class="prep-primary">${line ? '수정 저장' : '집행 기록 추가'}</button>
        ${line ? '<button type="button" data-cancel-edit>취소</button><button type="button" data-delete-line class="billing-delete">삭제</button>' : ''}
      </div>
    </form>`;
  }
  function expenditureAttachmentsHTML(line) {
    const list = (line.attachments || []).map(a => `<li><a href="/api/billing/expenditure-attachments/${a.id}/download">${esc(a.original_name)}</a> (${Math.ceil(a.size_bytes / 1024)}KB)</li>`).join('') || '<li>첨부파일 없음</li>';
    return `<div class="billing-attachments"><ul>${list}</ul>
      <label>증빙 파일(영수증·세금계산서 등) 첨부<input type="file" class="billing-file" data-kind="expenditure" data-line-id="${line.id}" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>`;
  }
  function expenditureLineRowHTML(line) {
    const isEditing = editingId === `expenditure-${line.id}`;
    return `<article class="billing-line ${isEditing ? 'editing' : ''}" data-id="${line.id}">
      <div class="billing-line-summary" data-toggle="expenditure-${line.id}">
        <span class="ta-badge">${esc(works[line.work_type])}</span>
        <span class="ta-badge">${esc(line.category)}</span>
        <strong>${esc(line.expense_date)} · ${esc(line.description || '-')} · ${won(line.amount)}</strong>
        <span>거래처 ${esc(line.payee || '-')} · 증빙 ${esc(line.receipt_no || '-')}</span>
      </div>
      ${isEditing ? `<div class="billing-line-body">${expenditureLineFormHTML(line)}${expenditureAttachmentsHTML(line)}</div>` : ''}
    </article>`;
  }
  function expenditureTabHTML() {
    const d = expenditureData;
    return `<h4>총괄 현황</h4>${expenditureSummaryHTML()}
      <h4>비목별 예산 대비 집행</h4>${expenditureBudgetTableHTML()}
      <h4>설비별 집행 현황</h4>${expenditureFacilityTableHTML()}
      <h4>새 집행 기록 추가</h4>${expenditureLineFormHTML(null)}
      <h4>집행 기록 (${d.lines.length}건)</h4>
      <div>${d.lines.length ? d.lines.map(expenditureLineRowHTML).join('') : '<p class="prep-intro">등록된 집행 기록이 없습니다.</p>'}</div>`;
  }

  // ---------- Personnel (인원 관리) ----------
  function personnelSummaryHTML() {
    const d = personnelData;
    const rows = [['재직 인원', `${d.active_count}명`], ['인력소개소 정산액', won(d.by_employment_type.AGENCY)],
      ['임시직 정산액', won(d.by_employment_type.DIRECT)], ['누적 지급액', won(d.total_paid)]];
    return `<div class="ca-summary-grid">${rows.map(([label, value], i) => `<div class="${i === 3 ? 'ca-summary-strong' : ''}"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('')}</div>`;
  }
  function employmentTypeOptions(selected) {
    return personnelData.employment_types.map(t => `<option value="${t}" ${t === selected ? 'selected' : ''}>${esc(employmentLabels[t])}</option>`).join('');
  }
  function personnelStatusOptions(selected) {
    return personnelData.personnel_statuses.map(s => `<option ${s === selected ? 'selected' : ''}>${esc(s)}</option>`).join('');
  }
  function personnelFormHTML(person) {
    const v = person || {name: '', employment_type: 'DIRECT', agency_name: '', role: '', daily_rate: '', phone: '', contract_start: '', contract_end: '', status: personnelData.personnel_statuses[0], note: ''};
    return `<form class="billing-line-form" data-kind="personnel" data-id="${person ? person.id : ''}" data-revision="${person ? person.revision : 0}">
      <div class="billing-form-grid">
        <label>성명<input name="name" maxlength="100" value="${esc(v.name)}" required></label>
        <label>소속 구분<select name="employment_type">${employmentTypeOptions(v.employment_type)}</select></label>
        <label>인력소개소명(해당 시)<input name="agency_name" maxlength="200" value="${esc(v.agency_name)}"></label>
        <label>직종<input name="role" maxlength="100" value="${esc(v.role)}"></label>
        <label>일당<input name="daily_rate" type="number" min="0" value="${esc(v.daily_rate)}"></label>
        <label>연락처<input name="phone" maxlength="30" value="${esc(v.phone)}"></label>
        <label>근로계약 시작일(임시직)<input name="contract_start" type="date" value="${esc(v.contract_start)}"></label>
        <label>근로계약 종료일(임시직)<input name="contract_end" type="date" value="${esc(v.contract_end)}"></label>
        <label>재직 상태<select name="status">${personnelStatusOptions(v.status)}</select></label>
      </div>
      <label>비고<textarea name="note" maxlength="2000">${esc(v.note)}</textarea></label>
      <div class="prep-actions">
        <button type="submit" class="prep-primary">${person ? '수정 저장' : '인원 추가'}</button>
        ${person ? '<button type="button" data-cancel-edit>취소</button><button type="button" data-delete-line class="billing-delete">삭제</button>' : ''}
      </div>
    </form>`;
  }
  function personnelAttachmentsHTML(person) {
    const list = (person.attachments || []).map(a => `<li><a href="/api/billing/personnel-attachments/${a.id}/download">${esc(a.original_name)}</a> (${Math.ceil(a.size_bytes / 1024)}KB)</li>`).join('') || '<li>첨부파일 없음</li>';
    return `<div class="billing-attachments"><strong>근로계약서 등 첨부</strong><ul>${list}</ul>
      <label>파일 첨부<input type="file" class="billing-person-file" data-person-id="${person.id}" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>`;
  }
  function settlementStatusOptions(selected) {
    return personnelData.statuses.map(s => `<option ${s === selected ? 'selected' : ''}>${esc(s)}</option>`).join('');
  }
  function settlementFormHTML(personId, settlement) {
    const v = settlement || {period_start: '', period_end: '', work_days: '', amount: '', document_no: '', document_date: '', payment_date: '', payment_amount: '', status: personnelData.statuses[0], note: ''};
    return `<form class="settlement-form" data-person-id="${personId}" data-id="${settlement ? settlement.id : ''}" data-revision="${settlement ? settlement.revision : 0}">
      <div class="billing-form-grid">
        <label>정산 시작일<input name="period_start" type="date" value="${esc(v.period_start)}" required></label>
        <label>정산 종료일<input name="period_end" type="date" value="${esc(v.period_end)}"></label>
        <label>근무일수<input name="work_days" type="number" step="0.5" min="0" value="${esc(v.work_days)}"></label>
        <label>정산금액<input name="amount" type="number" min="0" value="${esc(v.amount)}"></label>
        <label>증빙번호(계산서·정산서)<input name="document_no" maxlength="100" value="${esc(v.document_no)}"></label>
        <label>증빙 발행일<input name="document_date" type="date" value="${esc(v.document_date)}"></label>
        <label>입금일<input name="payment_date" type="date" value="${esc(v.payment_date)}"></label>
        <label>입금액<input name="payment_amount" type="number" min="0" value="${esc(v.payment_amount)}"></label>
        <label>상태<select name="status">${settlementStatusOptions(v.status)}</select></label>
      </div>
      <label>비고<textarea name="note" maxlength="2000">${esc(v.note)}</textarea></label>
      <div class="prep-actions">
        <button type="submit" class="prep-primary">${settlement ? '수정 저장' : '정산 기록 추가'}</button>
        ${settlement ? '<button type="button" data-cancel-settlement>취소</button><button type="button" data-delete-settlement class="billing-delete">삭제</button>' : '<button type="button" data-cancel-settlement>닫기</button>'}
      </div>
    </form>`;
  }
  function settlementAttachmentsHTML(settlement) {
    const list = (settlement.attachments || []).map(a => `<li><a href="/api/billing/settlement-attachments/${a.id}/download">${esc(a.original_name)}</a> (${Math.ceil(a.size_bytes / 1024)}KB)</li>`).join('') || '<li>첨부파일 없음</li>';
    return `<div class="billing-attachments"><ul>${list}</ul>
      <label>계산서·정산서 파일 첨부<input type="file" class="billing-settlement-file" data-settlement-id="${settlement.id}" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>`;
  }
  function settlementRowHTML(person, settlement) {
    const isEditing = addingSettlementFor === `edit-${settlement.id}`;
    return `<article class="billing-line settlement-line ${isEditing ? 'editing' : ''}" data-id="${settlement.id}">
      <div class="billing-line-summary" data-toggle-settlement="${settlement.id}">
        <span class="ta-badge">${esc(settlement.status)}</span>
        <strong>${esc(settlement.period_start)}${settlement.period_end ? ' ~ ' + esc(settlement.period_end) : ''} · ${settlement.work_days || 0}일 · ${won(settlement.amount)}</strong>
        <span>증빙 ${esc(settlement.document_no || '-')} · 입금 ${won(settlement.payment_amount)}</span>
      </div>
      ${isEditing ? `<div class="billing-line-body">${settlementFormHTML(person.id, settlement)}${settlementAttachmentsHTML(settlement)}</div>` : ''}
    </article>`;
  }
  function personnelRowHTML(person) {
    const isEditing = editingId === `personnel-${person.id}`;
    const totalPaid = person.settlements.reduce((sum, s) => sum + s.payment_amount, 0);
    const totalBilled = person.settlements.reduce((sum, s) => sum + (s.status !== '청구예정' ? s.amount : 0), 0);
    return `<article class="billing-line ${isEditing ? 'editing' : ''}" data-id="${person.id}">
      <div class="billing-line-summary" data-toggle="personnel-${person.id}">
        <span class="ta-badge">${esc(employmentLabels[person.employment_type])}</span>
        <span class="ta-badge">${esc(person.status)}</span>
        <strong>${esc(person.name)} · ${esc(person.role || '-')} · 일당 ${won(person.daily_rate)}</strong>
        <span>${person.employment_type === 'AGENCY' ? '소속: ' + esc(person.agency_name || '-') : '계약기간: ' + esc(person.contract_start || '-') + '~' + esc(person.contract_end || '-')} · 정산액 ${won(totalBilled)} · 지급액 ${won(totalPaid)}</span>
      </div>
      ${isEditing ? `<div class="billing-line-body">${personnelFormHTML(person)}${personnelAttachmentsHTML(person)}
        <h5>정산·계산서 기록 (${person.settlements.length}건)</h5>
        ${person.settlements.map(s => settlementRowHTML(person, s)).join('') || '<p class="prep-intro">등록된 정산 기록이 없습니다.</p>'}
        ${addingSettlementFor === `new-${person.id}` ? settlementFormHTML(person.id, null) : `<button type="button" class="secondary-button" data-add-settlement="${person.id}">정산 기록 추가</button>`}
      </div>` : ''}
    </article>`;
  }
  function personnelTabHTML() {
    const d = personnelData;
    return `<h4>총괄 현황</h4>${personnelSummaryHTML()}
      <h4>새 인원 추가</h4>${personnelFormHTML(null)}
      <h4>인원 목록 (${d.people.length}명)</h4>
      <div>${d.people.length ? d.people.map(personnelRowHTML).join('') : '<p class="prep-intro">등록된 인원이 없습니다.</p>'}</div>`;
  }

  // ---------- Safety budget (산업안전보건관리비) ----------
  function safetyBudgetSummaryHTML() {
    const d = safetyBudgetData;
    const rows = [['계상액(법정 산정액)', d.header.budget_amount], ['계상대상금액(①+②+③)', d.target_amount],
      ['항목별 계획합계', d.total_planned], ['누적 집행액', d.total_executed], ['잔액(계상액-집행액)', d.remaining_amount]];
    return `<div class="ca-summary-grid">${rows.map(([label, value], i) => `<div class="${i === 4 ? 'ca-summary-strong' : ''}"><span>${esc(label)}</span><strong>${won(value)}</strong></div>`).join('')}</div>`;
  }
  function safetyBudgetWorkKindOptions(selected) {
    return safetyBudgetData.work_kinds.map(k => `<option ${k === selected ? 'selected' : ''}>${esc(k)}</option>`).join('');
  }
  function safetyBudgetHeaderFormHTML() {
    const h = safetyBudgetData.header;
    if (!editingHeader) {
      return `<div class="ca-table-scroll"><table class="ca-table"><tbody>
        <tr><td>발주자</td><td>${esc(h.client_name || '-')}</td><td>공사종류</td><td>${esc(h.work_kind)}</td></tr>
        <tr><td>재료비①</td><td>${won(h.material_cost)}</td><td>관급재료비②</td><td>${won(h.government_material_cost)}</td></tr>
        <tr><td>직접노무비③</td><td>${won(h.direct_labor_cost)}</td><td>그 밖의 사항④</td><td>${won(h.other_cost)}</td></tr>
        <tr><td>산업안전보건관리비 계상액</td><td>${won(h.budget_amount)}</td><td>작성일</td><td>${esc(h.prepared_date || '-')}</td></tr>
        <tr><td>작성자</td><td colspan="3">${esc(h.preparer_title)} ${esc(h.preparer_name)}</td></tr>
      </tbody></table></div>
      <button type="button" class="secondary-button" id="sb-header-edit">일반사항 수정</button>`;
    }
    return `<form id="sb-header-form" data-revision="${h.revision}">
      <div class="billing-form-grid">
        <label>발주자<input name="client_name" maxlength="200" value="${esc(h.client_name)}"></label>
        <label>공사종류<select name="work_kind">${safetyBudgetWorkKindOptions(h.work_kind)}</select></label>
        <label>재료비①<input name="material_cost" type="number" min="0" value="${esc(h.material_cost)}"></label>
        <label>관급재료비②<input name="government_material_cost" type="number" min="0" value="${esc(h.government_material_cost)}"></label>
        <label>직접노무비③<input name="direct_labor_cost" type="number" min="0" value="${esc(h.direct_labor_cost)}"></label>
        <label>그 밖의 사항④<input name="other_cost" type="number" min="0" value="${esc(h.other_cost)}"></label>
        <label>산업안전보건관리비 계상액<input name="budget_amount" type="number" min="0" value="${esc(h.budget_amount)}"></label>
        <label>작성일<input name="prepared_date" type="date" value="${esc(h.prepared_date)}"></label>
        <label>작성자 직책<input name="preparer_title" maxlength="50" value="${esc(h.preparer_title)}"></label>
        <label>작성자 성명<input name="preparer_name" maxlength="50" value="${esc(h.preparer_name)}"></label>
      </div>
      <label>비고<textarea name="note" maxlength="2000">${esc(h.note)}</textarea></label>
      <div class="prep-actions">
        <button type="submit" class="prep-primary">일반사항 저장</button>
        <button type="button" id="sb-header-cancel">취소</button>
      </div>
    </form>`;
  }
  function safetyBudgetItemsTableHTML() {
    return `<p class="prep-intro">계획금액은 아래 3번 세부계획에 등록한 금액의 합계이며, 집행액은 그중 사용(집행)일자가 입력된 항목만의 합계입니다. 직접 입력하지 않습니다.</p>
      <div class="ca-table-scroll"><table class="ca-table"><thead><tr><th>항목</th><th>계획금액</th><th>비율(%)</th><th>집행액</th><th>잔액(계획-집행)</th></tr></thead><tbody>
      ${safetyBudgetData.items.map(i => `<tr>
        <td>${esc(i.label)}</td>
        <td>${won(i.planned_amount)}</td>
        <td>${i.ratio}%</td><td>${won(i.executed_amount)}</td><td>${won(i.remaining_amount)}</td>
      </tr>`).join('')}
      <tr><td>총계</td><td>${won(safetyBudgetData.total_planned)}</td><td>100%</td><td>${won(safetyBudgetData.total_executed)}</td><td>${won(safetyBudgetData.total_planned - safetyBudgetData.total_executed)}</td></tr>
      </tbody></table></div>`;
  }
  function safetyBudgetCategoryOptions(selected) {
    return safetyBudgetData.categories.map(([code, label]) => `<option value="${code}" ${code === selected ? 'selected' : ''}>${esc(label)}</option>`).join('');
  }
  function safetyBudgetLineFormHTML(line, options = {}) {
    const execution = !!options.execution;
    const today = new Date().toISOString().slice(0, 10);
    const v = line || {category: safetyBudgetData.categories[0][0], expense_date: execution ? today : '', description: '', quantity: 1, unit: '', unit_price: '', amount: '', basis: '', usage_period: '', payee: '', receipt_no: '', note: ''};
    const dateLabel = execution ? '사용(집행)일자' : '사용일자(집행 시 입력)';
    const payeeLabel = execution ? '거래처' : '거래처(집행 시 입력)';
    const receiptLabel = execution ? '증빙번호' : '증빙번호(집행 시 입력)';
    return `<form class="billing-line-form" data-kind="safety_budget" data-execution="${execution}" data-id="${line ? line.id : ''}" data-revision="${line ? line.revision : 0}">
      <div class="billing-form-grid">
        <label>항목<select name="category">${safetyBudgetCategoryOptions(v.category)}</select></label>
        <label>세부항목<input name="description" maxlength="500" value="${esc(v.description)}" placeholder="예: 안전모"></label>
        <label>단위<input name="unit" maxlength="20" value="${esc(v.unit)}" placeholder="예: ea"></label>
        <label>수량<input name="quantity" type="number" step="0.01" min="0.01" value="${esc(v.quantity)}" required></label>
        <label>단가<input name="unit_price" type="number" min="0" value="${esc(v.unit_price)}"></label>
        <label>금액<input name="amount" type="number" min="0" value="${esc(v.amount)}" placeholder="비우면 단가×수량"></label>
        <label>산출명세<input name="basis" maxlength="200" value="${esc(v.basis)}" placeholder="예: 6명×2분기"></label>
        <label>사용시기<input name="usage_period" maxlength="50" value="${esc(v.usage_period)}" placeholder="예: 26.10.~"></label>
        <label>${dateLabel}<input name="expense_date" type="date" value="${esc(v.expense_date)}" ${execution ? 'required' : ''}></label>
        <label>${payeeLabel}<input name="payee" maxlength="200" value="${esc(v.payee)}"></label>
        <label>${receiptLabel}<input name="receipt_no" maxlength="100" value="${esc(v.receipt_no)}"></label>
      </div>
      <label>비고<textarea name="note" maxlength="2000">${esc(v.note)}</textarea></label>
      <div class="prep-actions">
        <button type="submit" class="prep-primary">${line ? '수정 저장' : execution ? '집행 등록' : '세부계획 추가'}</button>
        ${line ? '<button type="button" data-cancel-edit>취소</button><button type="button" data-delete-line class="billing-delete">삭제</button>' : ''}
      </div>
    </form>`;
  }
  function safetyBudgetAttachmentsHTML(line) {
    const list = (line.attachments || []).map(a => `<li><a href="/api/billing/safety-budget-attachments/${a.id}/download">${esc(a.original_name)}</a> (${Math.ceil(a.size_bytes / 1024)}KB)</li>`).join('') || '<li>첨부파일 없음</li>';
    return `<div class="billing-attachments"><ul>${list}</ul>
      <label>증빙 파일(영수증·세금계산서 등) 첨부<input type="file" class="billing-file" data-kind="safety_budget" data-line-id="${line.id}" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>`;
  }
  function safetyBudgetLineRowHTML(line) {
    const isEditing = editingId === `safety_budget-${line.id}`;
    const label = (safetyBudgetData.categories.find(c => c[0] === line.category) || [, line.category])[1];
    return `<article class="billing-line ${isEditing ? 'editing' : ''}" data-id="${line.id}">
      <div class="billing-line-summary" data-toggle="safety_budget-${line.id}">
        <span class="ta-badge">${esc(label)}</span>
        <strong>${esc(line.description || '-')} · ${esc(line.usage_period || '시기 미정')} · ${won(line.amount)}</strong>
        <span>${line.expense_date ? `집행일 ${esc(line.expense_date)} · ` : '(계획 단계, 미집행) · '}거래처 ${esc(line.payee || '-')} · 증빙 ${esc(line.receipt_no || '-')}</span>
      </div>
      ${isEditing ? `<div class="billing-line-body">${safetyBudgetLineFormHTML(line)}${safetyBudgetAttachmentsHTML(line)}</div>` : ''}
    </article>`;
  }
  function safetyBudgetTabHTML() {
    const d = safetyBudgetData;
    return `<h4>총괄 현황</h4>${safetyBudgetSummaryHTML()}
      <h4>1. 일반사항</h4>${safetyBudgetHeaderFormHTML()}
      <h4>2. 항목별 계획·집행 현황</h4>${safetyBudgetItemsTableHTML()}
      <h4>3. 세부 사용계획 추가</h4>
      <p class="prep-intro">계획 단계에서는 세부항목·단위·수량·금액·산출명세·사용시기만 입력하면 됩니다.</p>
      ${safetyBudgetLineFormHTML(null, {execution: false})}
      <h4>4. 안전관리비 집행 등록</h4>
      <p class="prep-intro">실제로 비용을 사용한 뒤에는 이 폼으로 집행 내역(사용일자·거래처·증빙번호)을 바로 등록하세요. 등록 후 목록에서 열어 증빙 파일(영수증 등)도 첨부할 수 있습니다.</p>
      ${safetyBudgetLineFormHTML(null, {execution: true})}
      <h4>등록된 세부계획·집행 내역 (${d.lines.length}건)</h4>
      <a class="small-button" href="/api/billing/safety-budget-export">사용계획서 엑셀 내보내기</a>
      <div>${d.lines.length ? d.lines.map(safetyBudgetLineRowHTML).join('') : '<p class="prep-intro">등록된 내역이 없습니다.</p>'}</div>`;
  }

  // ---------- Shared ----------
  async function refresh() {
    billingData = await api('summary');
    expenditureData = await api('expenditure-summary');
    personnelData = await api('personnel-summary');
    safetyBudgetData = await api('safety-budget-summary');
    render();
  }
  function render() {
    const tabView = tab === 'billing' ? billingTabHTML() : tab === 'expenditure' ? expenditureTabHTML() : tab === 'personnel' ? personnelTabHTML() : safetyBudgetTabHTML();
    root.innerHTML = `<h2>공사 금액 관리</h2><p class="prep-intro">계약 원가내역서 기준으로 청구·입금 현황과 실제 집행 비용, 투입 인원, 산업안전보건관리비를 각각 기록·관리합니다.</p>
      <div id="billing-message" class="prep-message" role="status" aria-live="polite"></div>
      <nav class="billing-tabs" role="tablist">
        <button type="button" data-tab="billing" aria-pressed="${tab === 'billing'}">청구·입금 관리</button>
        <button type="button" data-tab="expenditure" aria-pressed="${tab === 'expenditure'}">집행 관리</button>
        <button type="button" data-tab="personnel" aria-pressed="${tab === 'personnel'}">인원 관리</button>
        <button type="button" data-tab="safety_budget" aria-pressed="${tab === 'safety_budget'}">안전관리비 관리</button>
      </nav>
      <div id="billing-tab-view">${tabView}</div>`;
    if (pendingMessage) { message(pendingMessage.text, pendingMessage.error); pendingMessage = null; }
    bind();
  }
  function readForm(form) {
    const values = Object.fromEntries(new FormData(form));
    if (values.amount === '') delete values.amount;
    if (values.unit_price === '') delete values.unit_price;
    values.quantity = Number(values.quantity);
    if (values.amount !== undefined) values.amount = Number(values.amount);
    if (values.unit_price !== undefined) values.unit_price = Number(values.unit_price);
    if ('payment_amount' in values) values.payment_amount = values.payment_amount === '' ? 0 : Number(values.payment_amount);
    return values;
  }
  function bind() {
    root.querySelectorAll('.billing-tabs [data-tab]').forEach(btn => {
      btn.addEventListener('click', () => { tab = btn.dataset.tab; editingId = null; render(); });
    });
    const kindMeta = {billing: {base: 'lines', label: '청구'}, expenditure: {base: 'expenditures', label: '집행'}, personnel: {base: 'personnel', label: '인원'}, safety_budget: {base: 'safety-budget-lines', label: '안전관리비'}};
    root.querySelectorAll('.billing-line-form').forEach(form => {
      form.addEventListener('submit', event => {
        event.preventDefault();
        const kind = form.dataset.kind;
        const {base, label} = kindMeta[kind];
        run(async () => {
          const values = readForm(form);
          if (form.dataset.id) {
            values.revision = Number(form.dataset.revision);
            await api(`${base}/${form.dataset.id}`, {method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(values)});
            pendingMessage = {text: `${label} 기록을 수정했습니다.`};
            editingId = null;
          } else {
            await api(base, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(values)});
            pendingMessage = {text: `${label} 기록을 추가했습니다.`};
          }
          await refresh();
        });
      });
    });
    root.querySelectorAll('[data-toggle]').forEach(el => {
      el.addEventListener('click', () => {
        const id = el.dataset.toggle;
        editingId = editingId === id ? null : id;
        render();
      });
    });
    root.querySelectorAll('[data-cancel-edit]').forEach(btn => btn.addEventListener('click', () => { editingId = null; render(); }));
    root.querySelectorAll('[data-delete-line]').forEach(btn => btn.addEventListener('click', () => {
      const form = btn.closest('form');
      const {base, label} = kindMeta[form.dataset.kind];
      if (!confirm(`이 ${label} 기록을 삭제할까요?`)) return;
      run(async () => { await api(`${base}/${form.dataset.id}`, {method: 'DELETE'}); editingId = null; pendingMessage = {text: `${label} 기록을 삭제했습니다.`}; await refresh(); });
    }));
    root.querySelectorAll('.billing-file').forEach(input => {
      input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 15 * 1024 * 1024) { message('파일은 15MB 이하로 첨부하세요.', true); return; }
        const {base} = kindMeta[input.dataset.kind];
        run(async () => {
          await fetch(`/api/billing/${base}/${input.dataset.lineId}/attachments?filename=${encodeURIComponent(file.name)}`, {method: 'POST', headers: {'Content-Type': 'application/octet-stream'}, body: file})
            .then(async r => { const result = await r.json(); if (!r.ok) throw new Error(result.error); });
          pendingMessage = {text: '첨부파일을 저장했습니다.'};
          await refresh();
        });
      });
    });
    root.querySelectorAll('select[name=work_type]').forEach(select => {
      select.addEventListener('change', () => {
        if (select.closest('form').dataset.kind !== 'billing') return;
        const facility = billingData.facilities.find(f => f.work_type === select.value);
        const unitPriceInput = select.closest('form').querySelector('input[name=unit_price]');
        if (facility && unitPriceInput) unitPriceInput.value = facility.unit_price;
      });
    });
    root.querySelectorAll('[data-add-settlement]').forEach(btn => btn.addEventListener('click', () => { addingSettlementFor = `new-${btn.dataset.addSettlement}`; render(); }));
    root.querySelectorAll('[data-toggle-settlement]').forEach(el => el.addEventListener('click', () => {
      const key = `edit-${el.dataset.toggleSettlement}`;
      addingSettlementFor = addingSettlementFor === key ? null : key;
      render();
    }));
    root.querySelectorAll('[data-cancel-settlement]').forEach(btn => btn.addEventListener('click', () => { addingSettlementFor = null; render(); }));
    root.querySelectorAll('[data-delete-settlement]').forEach(btn => btn.addEventListener('click', () => {
      const form = btn.closest('form');
      if (!confirm('이 정산 기록을 삭제할까요?')) return;
      run(async () => { await api(`settlements/${form.dataset.id}`, {method: 'DELETE'}); addingSettlementFor = null; pendingMessage = {text: '정산 기록을 삭제했습니다.'}; await refresh(); });
    }));
    root.querySelectorAll('.settlement-form').forEach(form => {
      form.addEventListener('submit', event => {
        event.preventDefault();
        run(async () => {
          const values = readForm(form);
          values.personnel_id = Number(form.dataset.personId);
          if (form.dataset.id) {
            values.revision = Number(form.dataset.revision);
            await api(`settlements/${form.dataset.id}`, {method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(values)});
            pendingMessage = {text: '정산 기록을 수정했습니다.'};
          } else {
            await api('settlements', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(values)});
            pendingMessage = {text: '정산 기록을 추가했습니다.'};
          }
          addingSettlementFor = null;
          await refresh();
        });
      });
    });
    root.querySelectorAll('.billing-person-file').forEach(input => {
      input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 15 * 1024 * 1024) { message('파일은 15MB 이하로 첨부하세요.', true); return; }
        run(async () => {
          await fetch(`/api/billing/personnel/${input.dataset.personId}/attachments?filename=${encodeURIComponent(file.name)}`, {method: 'POST', headers: {'Content-Type': 'application/octet-stream'}, body: file})
            .then(async r => { const result = await r.json(); if (!r.ok) throw new Error(result.error); });
          pendingMessage = {text: '첨부파일을 저장했습니다.'};
          await refresh();
        });
      });
    });
    const headerEdit = root.querySelector('#sb-header-edit');
    if (headerEdit) headerEdit.addEventListener('click', () => { editingHeader = true; render(); });
    const headerCancel = root.querySelector('#sb-header-cancel');
    if (headerCancel) headerCancel.addEventListener('click', () => { editingHeader = false; render(); });
    const headerForm = root.querySelector('#sb-header-form');
    if (headerForm) headerForm.addEventListener('submit', event => {
      event.preventDefault();
      run(async () => {
        const values = Object.fromEntries(new FormData(headerForm));
        values.revision = Number(headerForm.dataset.revision);
        for (const key of ['material_cost', 'government_material_cost', 'direct_labor_cost', 'other_cost', 'budget_amount']) values[key] = Number(values[key] || 0);
        await api('safety-budget-header', {method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(values)});
        editingHeader = false;
        pendingMessage = {text: '일반사항을 저장했습니다.'};
        await refresh();
      });
    });
    root.querySelectorAll('.billing-settlement-file').forEach(input => {
      input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 15 * 1024 * 1024) { message('파일은 15MB 이하로 첨부하세요.', true); return; }
        run(async () => {
          await fetch(`/api/billing/settlements/${input.dataset.settlementId}/attachments?filename=${encodeURIComponent(file.name)}`, {method: 'POST', headers: {'Content-Type': 'application/octet-stream'}, body: file})
            .then(async r => { const result = await r.json(); if (!r.ok) throw new Error(result.error); });
          pendingMessage = {text: '첨부파일을 저장했습니다.'};
          await refresh();
        });
      });
    });
  }
  function canLeave() { return !busy; }
  document.querySelector('#show-billing').addEventListener('click', () => {
    if (busy || !root.hidden) return;
    if (window.Preparation && !window.Preparation.canLeave()) return;
    root.hidden = false;
    document.querySelector('#preparation-root').hidden = true;
    document.querySelector('#safety-root').hidden = true;
    document.querySelector('#show-billing').setAttribute('aria-pressed', 'true');
    document.querySelector('#show-preparation').setAttribute('aria-pressed', 'false');
    document.querySelector('#show-safety').setAttribute('aria-pressed', 'false');
    run(refresh);
  });
  window.Billing = {canLeave};
})();
