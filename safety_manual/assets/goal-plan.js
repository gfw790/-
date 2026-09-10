(function () {
    'use strict';
    const view = document.getElementById('goal-plan-view');
    const template = JSON.parse(document.getElementById('plan-template').textContent);
    const dialog = document.getElementById('goal-plan-preview');
    const fields = ['name','frequency','unit','previous','plan','rate','q1','q2','q3','q4','note'];
    const details=JSON.parse(document.getElementById('plan-breakdown').textContent);
    const detailDialog=document.getElementById('plan-detail-dialog');
    view.addEventListener('click',event=>{
        const link=event.target.closest('[data-plan-id]');if(!link)return;
        const detail=details[link.dataset.planId];if(!detail)return;
        document.getElementById('plan-detail-title').textContent=view.dataset.year+'년 분기별 계획';
        document.getElementById('plan-detail-name').textContent=link.closest('[data-row-id]').querySelector('[data-field="name"]').textContent;
        const rows=detail.quarters.map((value,index)=>{const tr=node('tr');tr.append(node('th',(index+1)+'분기'),node('td',value===''?'미입력':value));return tr;});
        document.getElementById('plan-detail-rows').replaceChildren(...rows);
        document.getElementById('plan-detail-total').textContent=detail.total===''?'미입력':detail.total;
        document.getElementById('plan-detail-total').previousElementSibling.textContent=detail.mode==='average'?'평균':'합계';
        detailDialog.querySelector('.plan-detail-body > p:last-child').textContent=detail.mode==='average'?'입력된 분기의 계획값으로 평균을 계산합니다. 미입력 분기는 제외됩니다.':'미입력 항목은 합계에서 제외됩니다.';
        detailDialog.showModal();
    });
    document.getElementById('plan-detail-close').addEventListener('click',()=>detailDialog.close());
    function node(tag, text, cls) { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; if (cls) element.className = cls; return element; }
    function cell(tag, text, colspan, rowspan) { const result = node(tag,text); if(colspan) result.colSpan=colspan; if(rowspan) result.rowSpan=rowspan; return result; }
    function buildPrint() {
        const year = Number(view.dataset.year);
        const page = node('article',undefined,'goal-plan-sheet');
        page.append(node('p','양식2) 안전보건목표 및 세부추진계획','plan-caption'),node('h2',year+'년 안전보건활동 목표 및 추진계획'));
        const table = node('table',undefined,'plan-print-table');
        const cols = node('colgroup');
        [27,9,4,7,7,7,7.5,7.5,7.5,7.5,9].forEach(width=>{const col=node('col');col.style.width=width+'%';cols.append(col);});
        const head=node('thead');
        let tr=node('tr');tr.append(cell('th','구분',2,3),cell('th','단위',1,3),cell('th','연간 계획 및 실적',8));head.append(tr);
        tr=node('tr');tr.append(cell('th',(year-1)+'년'),cell('th',year+'년',6),cell('th','비고',1,2));head.append(tr);
        tr=node('tr');['실적','계획','실적율','1분기','2분기','3분기','4분기'].forEach(text=>tr.append(cell('th',text)));head.append(tr);
        const body=node('tbody');
        template.forEach(row=>{
            const tr=node('tr',undefined,row.type==='section'?'print-section':'print-item');
            if(row.type==='section') { tr.append(cell('th',row.title,3),cell('td','',8)); }
            else {
                const source=view.querySelector('[data-row-id="'+row.id+'"]');
                fields.forEach((field,index)=>{
                    const td=node('td',source.querySelector('[data-field="'+field+'"]').textContent);
                    if(index<3) td.classList.add('print-description');
                    if(field==='name'||field==='note') td.classList.add('print-label');
                    tr.append(td);
                });
            }
            body.append(tr);
        });
        table.append(cols,head,body);page.append(table,node('div','주식회사 현대기전','plan-footer'));
        document.getElementById('goal-plan-pages').replaceChildren(page);
        const overflow=page.getBoundingClientRect().height>1124;
        document.getElementById('plan-overflow').hidden=!overflow;
        document.getElementById('goal-plan-print').disabled=overflow;
    }
    document.getElementById('goal-plan-preview-open').addEventListener('click',async()=>{dialog.showModal();await document.fonts.ready;buildPrint();});
    document.getElementById('goal-plan-preview-close').addEventListener('click',()=>dialog.close());
    document.getElementById('goal-plan-print').addEventListener('click',()=>window.print());
    window.addEventListener('beforeprint',()=>{if(!dialog.open)dialog.showModal();buildPrint();});
    if(document.body.dataset.previewOnly==='1')document.getElementById('goal-plan-preview-open').click();
}());
