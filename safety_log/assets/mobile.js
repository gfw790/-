(() => {
    'use strict';
    const form = document.querySelector('form[action="store.php"]');
    if (!form) return;
    const cards = Array.from(form.children).filter(node => node.classList.contains('card'));
    const remark = form.querySelector('[name="remark"]').parentElement;
    const panels = [[cards[0]], [cards[1]], [cards[2], remark]];
    const nav = document.querySelector('.mobile-steps');
    const actions = form.querySelector('.mobile-form-actions');
    const save = document.getElementById('save-draft');
    const preview = document.getElementById('preview-submit');
    const submit = actions.querySelector('[type="submit"]');
    const cancel = actions.querySelector('[onclick]');
    const status = document.getElementById('draft-status');
    const previous = document.createElement('button');
    previous.type = 'button';
    previous.className = 'btn btn-outline-secondary';
    previous.textContent = '이전';
    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'btn btn-primary';
    next.textContent = '다음 단계';
    save.textContent = '임시저장';
    submit.textContent = '업무일지 등록';
    cancel.hidden = true;
    actions.prepend(previous);
    actions.append(next, status);
    status.setAttribute('role', 'status');
    const note = document.createElement('p');
    note.className = 'mobile-draft-note';
    note.textContent = '임시저장은 이 휴대폰의 현재 브라우저에 보관됩니다. 사진도 함께 보관하려면 임시저장을 눌러 주세요.';
    form.insertBefore(note, actions);
    let step = 0;
    function showStep(value, scroll = true) {
        step = value;
        panels.forEach((nodes, index) => nodes.forEach(node => { node.hidden = index !== step; }));
        nav.querySelectorAll('button').forEach((button, index) => {
            if (index === step) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        previous.hidden = step === 0;
        next.hidden = step === 2;
        preview.hidden = step !== 2;
        submit.hidden = step !== 2;
        if (scroll) nav.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
    previous.addEventListener('click', () => showStep(step - 1));
    next.addEventListener('click', () => {
        const invalid = panels[step].flatMap(panel => Array.from(panel.querySelectorAll('input, select, textarea')))
            .find(input => !input.validity.valid);
        if (invalid) { invalid.reportValidity(); return; }
        showStep(step + 1);
    });
    nav.addEventListener('click', event => {
        const button = event.target.closest('[data-step]');
        if (button) showStep(Number(button.dataset.step));
    });
    form.addEventListener('invalid', event => {
        const index = panels.findIndex(nodes => nodes.some(node => node.contains(event.target)));
        if (index >= 0 && index !== step) showStep(index, false);
    }, true);
    const photoUrls = new Map();
    function enhancePhotos() {
        form.querySelectorAll('.js-detail-photo-input').forEach(input => {
            input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            input.setAttribute('aria-label', input.closest('td').dataset.label + ' 촬영 또는 앨범에서 선택');
            if (input.dataset.mobileEnhanced) return;
            input.dataset.mobileEnhanced = '1';
            const image = document.createElement('img');
            image.className = 'mobile-photo-preview';
            image.alt = '첨부 사진 미리보기';
            image.hidden = true;
            input.parentElement.append(image);
            const temp = input.parentElement.querySelector('.js-detail-photo-temp');
            if (temp?.value) {
                image.src = 'show_draft_image.php?file=' + encodeURIComponent(temp.value);
                image.hidden = false;
            }
            input.addEventListener('change', () => {
                if (photoUrls.has(input)) URL.revokeObjectURL(photoUrls.get(input));
                photoUrls.delete(input);
                const file = input.files?.[0];
                if (file) {
                    const url = URL.createObjectURL(file);
                    photoUrls.set(input, url);
                    image.src = url;
                    image.hidden = false;
                } else {
                    image.hidden = !temp?.value;
                    if (temp?.value) image.src = 'show_draft_image.php?file=' + encodeURIComponent(temp.value);
                    else image.removeAttribute('src');
                }
            });
        });
        for (const [input, url] of photoUrls) {
            if (!input.isConnected) { URL.revokeObjectURL(url); photoUrls.delete(input); }
        }
    }
    new MutationObserver(enhancePhotos).observe(document.getElementById('details-body'), { childList: true });
    enhancePhotos();
    nav.hidden = false;
    document.body.classList.add('mobile-ready');
    showStep(0, false);
})();
