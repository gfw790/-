(function(){
    'use strict';
    const form=document.getElementById('quarter-plan-form'),dialog=document.getElementById('quarter-preview');
    const template=JSON.parse(document.getElementById('quarter-template').textContent);
    let dirty=false;
    const format=value=>String(Number(value.toFixed(6)));
    function calculate(item){
        const row=form.querySelector('[data-row-id="'+item.id+'"]');
        const values=['m1','m2','m3'].map(key=>row.querySelector('[data-field="'+key+'"]').value).filter(value=>value!==''&&value!=='-').map(Number);
        const result=values.reduce((sum,value)=>sum+value,0)/(item.mode==='average'&&values.length?values.length:1);
        row.querySelector('[data-field="result"]').textContent=Number.isFinite(result)?format(item.percent_result?result*100:result)+(item.percent_result?'%':''):'—';
    }
    function element(tag,text,cls){const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(cls)node.className=cls;return node;}
    function cell(tag,text,colspan,rowspan){const node=element(tag,text);if(colspan)node.colSpan=colspan;if(rowspan)node.rowSpan=rowspan;return node;}
    function buildPrint(){
        const year=Number(form.dataset.year),quarter=Number(form.dataset.quarter),start=(quarter-1)*3+1;
        const page=element('article',undefined,'goal-plan-sheet quarter-sheet');page.append(element('p','양식-2.'+quarter+') 분기별 추진 계획 및 실적','plan-caption'));
        const table=element('table',undefined,'plan-print-table'),cols=element('colgroup');
        [32,11,8,8,9,8,8,8,8].forEach(width=>{const col=element('col');col.style.width=width+'%';cols.append(col);});
        const head=element('thead');let tr=element('tr');const title=cell('th',year+'년 안전보건활동 '+quarter+'분기 추진계획 및 실적',9);title.className='quarter-title';tr.append(title);head.append(tr);
        tr=element('tr');tr.append(cell('th','구분',1,2),cell('th','단위',1,2),cell('th',(year-1)+'년'),cell('th',year+'년',6));head.append(tr);
        tr=element('tr');['실적','계획',quarter+'분기',start+'월',(start+1)+'월',(start+2)+'월','비고'].forEach(label=>tr.append(cell('th',label)));head.append(tr);
        const body=element('tbody');template.forEach(item=>{
            const tr=element('tr',undefined,item.type==='section'?'print-section':'print-item');
            if(item.type==='section'){tr.append(cell('th',item.title,2),cell('td','',7));}
            else{
                const source=form.querySelector('[data-row-id="'+item.id+'"]');
                tr.append(element('td',item.name,'print-description print-label'),element('td',item.unit,'print-description'));
                ['previous','plan','result','m1','m2','m3','note'].forEach(key=>{
                    const field=source.querySelector('[data-field="'+key+'"]');tr.append(element('td',field.tagName==='OUTPUT'?field.textContent:field.value,key==='note'?'print-label':''));
                });
            }
            body.append(tr);
        });table.append(cols,head,body);page.append(table,element('div','주식회사 현대기전','plan-footer'));document.getElementById('quarter-pages').replaceChildren(page);
        const overflow=page.getBoundingClientRect().height>1124;document.getElementById('quarter-overflow').hidden=!overflow;document.getElementById('quarter-print').disabled=overflow;
    }
    form.addEventListener('input',event=>{
        dirty=true;const row=event.target.closest('[data-row-id]');
        if(row&&['m1','m2','m3'].includes(event.target.dataset.field))calculate(template.find(item=>item.id===row.dataset.rowId));
    });
    form.addEventListener('submit',()=>{dirty=false;document.getElementById('quarter-plan-save').disabled=true;document.getElementById('quarter-plan-save').textContent='저장 중…';});
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    document.getElementById('quarter-preview-open').addEventListener('click',async()=>{dialog.showModal();await document.fonts.ready;buildPrint();});
    document.getElementById('quarter-preview-close').addEventListener('click',()=>dialog.close());
    document.getElementById('quarter-print').addEventListener('click',()=>window.print());
    window.addEventListener('beforeprint',()=>{if(!dialog.open)dialog.showModal();buildPrint();});
}());
