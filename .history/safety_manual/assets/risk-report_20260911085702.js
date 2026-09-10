(function () {
    'use strict';

    const form = document.querySelector('form[method="post"]');
    const previewButton = document.querySelector('[name="preview_print"]');
    if (!form || !previewButton) return;

    const dialog = document.createElement('dialog');
    dialog.id = 'risk-report-preview';
    dialog.setAttribute('aria-labelledby', 'risk-report-preview-title');
    dialog.innerHTML = '<div class="preview-toolbar"><h2 id="risk-report-preview-title">인쇄 미리보기</h2><button type="button" id="risk-report-print">인쇄하기</button><button type="button" class="secondary" id="risk-report-preview-close">닫기</button><p id="risk-report-overflow" hidden role="alert">내용이 A4 용지 크기를 초과합니다. 긴 입력 내용을 줄여 주세요.</p></div><div id="risk-report-pages"></div>';
    document.body.append(dialog);

    const pages = dialog.querySelector('#risk-report-pages');
    async function buildPreview() {
        const data = new FormData(form);
        data.set('preview_print', '1');
        const response = await fetch(location.href, { method: 'POST', body: data });
        if (!response.ok) throw new Error('미리보기를 만들지 못했습니다.');
        const source = new DOMParser().parseFromString(await response.text(), 'text/html');
        const titleTable = source.querySelector('.title-table');
        const titleCell = titleTable?.querySelector('.report-title');
        const secondTitleRow = titleTable?.rows[1];
        if (titleCell && secondTitleRow?.cells[0]) {
            titleCell.rowSpan = 2;
            secondTitleRow.deleteCell(0);
        }
        const reportPages = Array.from(source.querySelectorAll('.page'));
        if (reportPages.length === 0) throw new Error('보고서 양식을 불러오지 못했습니다.');
        pages.replaceChildren(...reportPages.map(page => document.importNode(page, true)));
        await document.fonts.ready;
        const overflow = Array.from(pages.querySelectorAll('.page')).some(page => page.scrollHeight > page.clientHeight + 1);
        dialog.querySelector('#risk-report-overflow').hidden = !overflow;
        dialog.querySelector('#risk-report-print').disabled = overflow;
    }

    previewButton.addEventListener('click', async event => {
        event.preventDefault();
        dialog.showModal();
        pages.textContent = '미리보기를 만드는 중입니다.';
        try {
            await buildPreview();
        } catch (error) {
            pages.textContent = error instanceof Error ? error.message : '미리보기를 만들지 못했습니다.';
        }
    });
    dialog.querySelector('#risk-report-preview-close').addEventListener('click', () => dialog.close());
    dialog.querySelector('#risk-report-print').addEventListener('click', () => window.print());
}());
