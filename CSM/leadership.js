(() => {
  const pdfViewer = document.getElementById('meeting-pdf-viewer');
  if (pdfViewer) {
    const frame = pdfViewer.querySelector('iframe');
    let pdfTrigger;
    document.querySelectorAll('[data-pdf-preview]').forEach(link => {
      link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        pdfTrigger = link;
        document.getElementById('meeting-pdf-title').textContent = link.dataset.pdfName;
        frame.title = link.dataset.pdfName;
        frame.src = link.href;
        pdfViewer.querySelector('[data-pdf-new-tab]').href = link.href;
        pdfViewer.querySelector('[data-pdf-download-link]').href = link.dataset.pdfDownload;
        pdfViewer.showModal();
        pdfViewer.querySelector('[data-pdf-close]').focus();
      });
    });
    pdfViewer.querySelector('[data-pdf-close]').addEventListener('click', () => pdfViewer.close());
    pdfViewer.addEventListener('close', () => {
      frame.removeAttribute('src');
      pdfTrigger?.focus();
    });
  }
  const fitPrintGuide = () => {
    document.querySelectorAll('.vfl-print-guide-line').forEach(line => {
      const text = line.firstElementChild;
      if (!text || !line.clientWidth) return;
      text.style.fontSize = '';
      const baseSize = parseFloat(getComputedStyle(line).fontSize);
      const textWidth = text.getBoundingClientRect().width;
      if (textWidth > line.clientWidth) text.style.fontSize = `${baseSize * line.clientWidth / textWidth}px`;
    });
  };
  window.addEventListener('beforeprint', fitPrintGuide);
  window.matchMedia('print').addEventListener('change', event => { if (event.matches) fitPrintGuide(); });

  document.querySelectorAll('.attachment-action[data-action="delete_file"]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm('이 첨부파일을 삭제하시겠습니까? 삭제 후 복원할 수 있습니다.')) event.preventDefault();
    });
  });
  const photoUrls = new Set();
  document.querySelectorAll('[data-photo-slot]').forEach(slot => {
    const input = slot.querySelector('[data-photo-input]');
    const image = slot.querySelector('[data-photo-preview]');
    const empty = slot.querySelector('[data-photo-empty]');
    const name = slot.querySelector('[data-photo-name]');
    const originalUrl = image.getAttribute('src') || '';
    const originalName = name.textContent;
    const pasteButton = slot.querySelector('[data-photo-paste]');
    const pasteStatus = slot.querySelector('[data-photo-status]');
    pasteButton.addEventListener('click', () => {
      slot.focus();
      pasteStatus.textContent = '이 사진 칸에 붙여넣으려면 Ctrl+V를 누르세요.';
    });
    slot.addEventListener('paste', event => {
      // Keep ordinary text pasting in the dialogue field unchanged.
      if (event.target.matches('input:not([type=file]), textarea, [contenteditable="true"]')) return;
      const files = Array.from(event.clipboardData?.items || [])
        .filter(item => item.kind === 'file').map(item => item.getAsFile()).filter(Boolean);
      if (!files.length) return;
      event.preventDefault();
      const file = files[0];
      if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 10 * 1024 * 1024) {
        pasteStatus.textContent = '10MB 이하의 JPG 또는 PNG 사진을 복사해 주세요.';
        return;
      }
      const transfer = new DataTransfer();
      transfer.items.add(new File([file], `점검사진-${slot.dataset.photoSlot}.${file.type === 'image/png' ? 'png' : 'jpg'}`, {type: file.type}));
      input.files = transfer.files;
      input.dispatchEvent(new Event('change', {bubbles: true}));
      pasteStatus.textContent = files.length > 1 ? '첫 번째 사진을 붙여넣었습니다. 다른 사진은 나머지 칸에 붙여넣으세요.' : '사진을 붙여넣었습니다. 활동기록을 저장하면 등록됩니다.';
    });
    let previewUrl = '';
    input.addEventListener('change', () => {
      if (previewUrl) { URL.revokeObjectURL(previewUrl); photoUrls.delete(previewUrl); previewUrl = ''; }
      const file = input.files[0];
      const invalid = file && (file.size > 10 * 1024 * 1024 || (file.type && !['image/jpeg', 'image/png'].includes(file.type)));
      input.setCustomValidity(invalid ? '10MB 이하의 JPG 또는 PNG 사진을 선택해 주세요.' : '');
      if (file && !invalid) {
        previewUrl = URL.createObjectURL(file);
        photoUrls.add(previewUrl);
      }
      const url = previewUrl || originalUrl;
      if (url) image.src = url;
      else image.removeAttribute('src');
      image.hidden = !url;
      empty.hidden = Boolean(url);
      name.textContent = file ? file.name : originalName;
      if (invalid) input.reportValidity();
    });
  });
  window.addEventListener('pagehide', () => photoUrls.forEach(url => URL.revokeObjectURL(url)));
  const activityForm = document.getElementById('activity-form');
  if (activityForm?.querySelector('[name="item"]')?.value === '1.1.3') {
    const date = activityForm.querySelector('[name="activity_date"]');
    const title = activityForm.querySelector('[name="title"]');
    const isNew = activityForm.querySelector('[name="id"]')?.value === '0';
    let generatedTitle = '';
    const suggestTitle = () => {
      if (!isNew || !date.value) return;
      const [year, month] = date.value.split('-');
      const next = `${year}년 ${Number(month)}월 VFL 안전순찰일지`;
      if (title.value === '' || title.value === generatedTitle) title.value = next;
      generatedTitle = next;
    };
    date.addEventListener('change', suggestTitle);
    suggestTitle();
  }
  const container = document.getElementById('actions');
  const add = document.getElementById('add-action');
  const template = document.getElementById('action-template');
  if (!container || !add || !template) return;
  let index = container.querySelectorAll('.action-row').length;
  const refresh = () => { add.disabled = container.querySelectorAll('.action-row').length >= 30; };
  add.addEventListener('click', () => {
    if (add.disabled) return;
    const row = template.content.firstElementChild.cloneNode(true);
    row.querySelectorAll('[name]').forEach(field => {
      field.name = field.name.replace('__INDEX__', String(index));
    });
    index++;
    container.append(row);
    row.querySelector('textarea').focus();
    refresh();
  });
  container.addEventListener('click', event => {
    if (!event.target.matches('.remove-action')) return;
    event.target.closest('.action-row').remove();
    refresh();
  });
  refresh();
})();
