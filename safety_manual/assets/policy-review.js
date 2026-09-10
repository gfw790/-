(function () {
    'use strict';
    const form = document.getElementById('review-form');
    const labels = JSON.parse(document.getElementById('review-labels').textContent);
    const historyDialog = document.getElementById('history-dialog');
    const printDialog = document.getElementById('review-print-dialog');
    const message = document.getElementById('review-message');
    let historical = null, dirty = false;
    function node(tag, cls, text) {
        const result = document.createElement(tag); result.className = cls || '';
        if (text !== undefined) result.textContent = text;
        return result;
    }
    function notify(text, error) { message.textContent = text; message.hidden = false; message.classList.toggle('error', !!error); }
    function policyContent(text){
        const content=node('div','policy-content');
        let paragraph=null;
        text.split(/\r?\n/).forEach(line=>{
            if(['안전보건방침','안전보건 목표'].includes(line.trim())){
                content.append(node('strong','policy-heading',line.trim()));paragraph=null;
            }else if(/^\d+\.\s/.test(line.trim())){
                paragraph=node('p','policy-goal',line);content.append(paragraph);
            }else if(paragraph){paragraph.textContent+='\n'+line;}
            else if(line.trim()!==''){content.append(node('div','policy-body-line',line));}
        });
        return content;
    }
    function renderPolicyOutput(){document.getElementById('review-policy-output').replaceChildren(policyContent(document.getElementById('review-policy').value));}
    renderPolicyOutput();
    function collect() {
        return { year: form.dataset.year, review_date: document.getElementById('review-date').value, policy_text: document.getElementById('review-policy').value,
            items: labels.map((_, i) => ({ status: document.getElementById('status-' + i).value, note: document.getElementById('note-' + i).value })) };
    }
    function sheet(data) {
        const page = node('article', 'review-sheet');
        const caption = node('div', 'review-caption');
        caption.append(node('span', '', '양식1) 안전보건방침 및 목표 검토 결과'), node('span', '', data.review_date?'검토일자: '+data.review_date:''));
        const paper = node('div', 'review-paper');
        paper.append(node('h2', '', '안전보건방침 및 목표 검토 보고서'));
        const table = node('table', 'review-table');
        const cols = node('colgroup');
        [50, 32, 9, 9].forEach(width => { const col = node('col'); col.style.width = width + '%'; cols.append(col); });
        const body = node('tbody');
        const head = node('tr');
        const policy = node('td', 'review-policy-cell');policy.append(policyContent(data.policy_text)); policy.rowSpan = 9;
        head.append(policy);
        ['방침 유효성 검토 내용', '적합유무', '비 고'].forEach(text => { const th = node('th', '', text); th.scope = 'col'; head.append(th); });
        head.style.height = '15mm'; body.append(head);
        labels.forEach((text, i) => {
            const row = node('tr'); row.append(node('td', '', (i + 1) + '. ' + text), node('td', 'review-result', data.items[i].status), node('td', '', data.items[i].note.trim()?'하단참조':'')); body.append(row);
        });
        table.append(cols, body); paper.append(table); page.append(caption, paper);
        const notes=data.items.map((item,index)=>({number:index+1,text:item.note.trim()})).filter(item=>item.text!=='');
        if(notes.length){
            page.classList.add('has-notes');
            const box=node('section','review-notes-box');box.setAttribute('aria-label','비고');
            notes.forEach(item=>box.append(node('p','review-note',item.number+'. '+item.text)));
            page.append(box);
        }
        return page;
    }
    document.getElementById('history-open').addEventListener('click', async function () {
        this.disabled = true; historical = null;
        try {
            const response = await fetch('policy_review.php?action=history&year=' + encodeURIComponent(document.getElementById('history-year').value), { headers: { Accept: 'application/json' } });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('로그인 상태를 확인하고 다시 시도해 주세요.');
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || '보고서를 불러오지 못했습니다.');
            historical = result;
            document.getElementById('history-title').textContent = result.year + '년 검토 보고서';
            document.getElementById('history-content').replaceChildren(sheet(result));
            historyDialog.showModal();
        } catch (error) { notify(error.message, true); }
        finally { this.disabled = false; }
    });
    document.getElementById('history-close').addEventListener('click', () => historyDialog.close());
    document.getElementById('history-copy').addEventListener('click', () => {
        if (!historical) return;
        const existing = collect();
        if ((dirty || existing.policy_text || existing.items.some(item => item.status || item.note)) && !confirm('현재 입력 내용을 지난 연도 보고서 내용으로 바꾸시겠습니까?')) return;
        document.getElementById('review-policy').value = historical.policy_text;
        renderPolicyOutput();
        historical.items.forEach((item, i) => { document.getElementById('status-' + i).value = item.status; document.getElementById('note-' + i).value = item.note; });
        dirty = true; historyDialog.close();
        notify(historical.year + '년 내용을 ' + form.dataset.year + '년 입력란에 불러왔습니다. 검토 후 저장하기를 눌러 주세요.');
    });
    function buildPrint() {
        const page = sheet(collect());
        document.getElementById('review-print-pages').replaceChildren(page);
        const overflow = page.getBoundingClientRect().height > 1124;
        document.getElementById('print-overflow').hidden = !overflow;
        document.getElementById('review-print').disabled = overflow;
    }
    document.getElementById('review-print-open').addEventListener('click', async () => { printDialog.showModal(); await document.fonts.ready; buildPrint(); });
    document.getElementById('print-close').addEventListener('click', () => printDialog.close());
    document.getElementById('review-print').addEventListener('click', () => window.print());
    window.addEventListener('beforeprint', () => { if (!printDialog.open) printDialog.showModal(); buildPrint(); });
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; document.getElementById('review-save').disabled = true; document.getElementById('review-save').textContent = '저장 중…'; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    if(document.body.dataset.previewOnly==='1')document.getElementById('review-print-open').click();
}());
