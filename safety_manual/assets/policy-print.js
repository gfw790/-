(function () {
    'use strict';
    const dialog = document.getElementById('policy-preview');
    const pages = document.getElementById('policy-print-pages');
    const select = document.getElementById('policy-print-year');
    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function createSheet(year, date) {
        const sheet = element('article', 'policy-sheet');
        ['left', 'right'].forEach(side => {
            const ribbon = element('img', 'policy-ribbon ' + side);
            ribbon.src = 'assets/policy-ribbon.png';
            ribbon.alt = '';
            sheet.append(ribbon);
        });
        sheet.append(element('h1', '', '안전보건 경영방침'));
        const content = element('div', 'policy-sheet-content');
        const footer = element('footer', 'policy-sheet-footer');
        const dateParts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '');
        const dateLabel = dateParts ? dateParts[1] + '년 ' + dateParts[2] + '월 ' + dateParts[3] + '일' : year + '년';
        footer.append(element('div', '', dateLabel));
        const company = element('div', 'policy-sheet-company');
        const logo = element('img', '');
        logo.src = 'assets/policy-logo.png';
        logo.alt = '현대기전 로고';
        company.append(logo, element('span', '', '주식회사 현대기전 대표이사'));
        footer.append(company);
        sheet.append(content, footer);
        pages.append(sheet);
        return content;
    }
    function textBlock(text, number) {
        if (number === null) return element('p', 'policy-print-intro', text);
        const row = element('div', 'policy-print-goal');
        row.append(element('span', 'policy-print-number', String(number).padStart(2, '0')),
            element('p', 'policy-print-goal-text', text));
        return row;
    }
    function renderYear(key, year) {
        const card = document.querySelector('.page-card.' + key);
        const date = card.querySelector('.policy-date').value;
        const intro = card.querySelector('textarea[name="' + key + '[policy]"]').value.trim();
        const goals = Array.from(card.querySelectorAll('.goal-row textarea')).map(field => field.value.trim()).filter(Boolean);
        let content = createSheet(year, date);
        // Measure at actual A4 dimensions. Split long content without dropping text or shrinking type.
        function appendText(text, number) {
            let remaining = Array.from(text);
            while (remaining.length) {
                const block = textBlock(remaining.join(''), number);
                content.append(block);
                if (content.scrollHeight <= content.clientHeight + 1) return;
                block.remove();
                if (content.children.length) content = createSheet(year, date);
                content.append(block);
                if (content.scrollHeight <= content.clientHeight + 1) return;
                const target = number === null ? block : block.querySelector('.policy-print-goal-text');
                let low = 1, high = remaining.length, fit = 0;
                while (low <= high) {
                    const mid = Math.floor((low + high) / 2);
                    target.textContent = remaining.slice(0, mid).join('');
                    if (content.scrollHeight <= content.clientHeight + 1) { fit = mid; low = mid + 1; }
                    else high = mid - 1;
                }
                if (!fit) throw new Error('인쇄 영역을 구성할 수 없습니다.');
                target.textContent = remaining.slice(0, fit).join('');
                remaining = remaining.slice(fit);
                if (remaining.length) content = createSheet(year, date);
            }
        }
        if (intro) appendText(intro, null);
        goals.forEach((goal, index) => appendText(goal, index + 1));
    }
    function buildPreview() {
        pages.replaceChildren();
        Array.from(select.options).filter(option => option.dataset.year && (select.value === 'both' || option.value === select.value))
            .forEach(option => renderYear(option.value, option.dataset.year));
        const sheets = pages.querySelectorAll('.policy-sheet');
        sheets.forEach((sheet, index) => {
            if (sheets.length > 1) sheet.append(element('div', 'policy-sheet-page', (index + 1) + ' / ' + sheets.length));
        });
    }
    document.getElementById('policy-preview-open').addEventListener('click', async () => {
        dialog.showModal();
        await document.fonts.ready;
        buildPreview();
    });
    select.addEventListener('change', buildPreview);
    document.getElementById('policy-preview-close').addEventListener('click', () => dialog.close());
    document.getElementById('policy-preview-print').addEventListener('click', async () => {
        await Promise.all(Array.from(pages.querySelectorAll('img')).map(img => img.decode().catch(() => {})));
        window.print();
    });
    window.addEventListener('beforeprint', () => {
        if (!dialog.open) dialog.showModal();
        buildPreview();
    });
}());
