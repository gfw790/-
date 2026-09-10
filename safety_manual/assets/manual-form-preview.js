(function(){
    'use strict';
    const dialog=document.createElement('dialog');dialog.id='manual-form-dialog';
    dialog.innerHTML='<form id="manual-form-select"><div class="manual-form-heading"><h2 id="manual-form-title">양식 인쇄 미리보기</h2><button type="button" id="manual-form-close">닫기</button></div><div class="manual-form-options"><label>양식 선택 <select id="manual-form-mode"><option value="blank">빈 양식</option><option value="current">당해년도 작성된 양식</option><option value="year">입력한 연도의 작성된 양식</option></select></label><label id="manual-form-year-label" hidden>연도 <input id="manual-form-year" type="number" min="1000" max="9999" step="1" required></label><button type="submit">인쇄 미리보기</button></div></form><iframe id="manual-form-frame" title="선택한 양식의 인쇄 미리보기" hidden></iframe>';
    document.body.append(dialog);
    const year=Number(document.body.dataset.manualYear),mode=dialog.querySelector('#manual-form-mode'),yearInput=dialog.querySelector('#manual-form-year'),frame=dialog.querySelector('iframe');
    let kind='review';yearInput.value=year;
    document.addEventListener('click',event=>{
        const link=event.target.closest('a[data-manual-form]');if(!link)return;
        event.preventDefault();kind=link.dataset.manualForm;
        dialog.querySelector('h2').textContent=link.textContent;
        mode.value='current';yearInput.value=year;dialog.querySelector('#manual-form-year-label').hidden=true;
        frame.hidden=true;frame.removeAttribute('src');dialog.showModal();
    });
    mode.addEventListener('change',()=>{dialog.querySelector('#manual-form-year-label').hidden=mode.value!=='year';});
    dialog.querySelector('#manual-form-close').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{frame.removeAttribute('src');frame.hidden=true;});
    dialog.querySelector('form').addEventListener('submit',event=>{
        event.preventDefault();
        const selectedYear=mode.value==='year'?Number(yearInput.value):year;
        if(!Number.isInteger(selectedYear)||selectedYear<1000||selectedYear>9999){yearInput.reportValidity();return;}
        const url=new URL(kind==='review'?'policy_review.php':'goal_plan.php',location.href);
        url.searchParams.set('preview','1');url.searchParams.set('year',selectedYear);
        if(mode.value==='blank')url.searchParams.set('blank','1');
        frame.src=url.href;frame.hidden=false;
    });
}());
