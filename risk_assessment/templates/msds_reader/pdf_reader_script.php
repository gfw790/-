<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.4.168/pdf.min.js"></script>
<script>
  const pdfUrl = <?= json_encode($pdfUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let manualMobileContent = <?= json_encode($mobileContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const serverOcrStatus = <?= json_encode($ocrStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const serverOcrEngine = <?= json_encode($ocrEngine, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const serverOcrText = <?= json_encode($ocrText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const serverOcrSections = <?= json_encode($ocrSections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const serverOcrError = <?= json_encode($ocrError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const stage = document.getElementById('pdf-stage');
  const canvas = document.getElementById('pdf-canvas');
  const context = canvas.getContext('2d');
  const prevButton = document.getElementById('prev-page');
  const nextButton = document.getElementById('next-page');
  const zoomOutButton = document.getElementById('zoom-out');
  const zoomInButton = document.getElementById('zoom-in');
  const pageSelect = document.getElementById('page-select');
  const pageIndicator = document.getElementById('page-indicator');
  const mobileTextReader = document.getElementById('mobile-text-reader');
  const mobileTextStatus = document.getElementById('mobile-text-status');
  const mobileTextBody = document.getElementById('mobile-text-body');
  const mobileSectionJump = document.getElementById('mobile-section-jump');
  const mobileSectionSelect = document.getElementById('mobile-section-select');
  const mobileScrollTopButton = document.getElementById('mobile-scroll-top');
  const mobileEditorModal = document.getElementById('mobile-editor-modal');
  const mobileEditorTextarea = document.getElementById('mobile-editor-textarea');
  const mobileEditorCancel = document.getElementById('mobile-editor-cancel');
  const mobileEditorApply = document.getElementById('mobile-editor-apply');
  const mobileGlossaryModal = document.getElementById('mobile-glossary-modal');
  const mobileGlossaryTitle = document.getElementById('mobile-glossary-title');
  const mobileGlossaryContent = document.getElementById('mobile-glossary-content');
  const mobileGlossaryClose = document.getElementById('mobile-glossary-close');
  const mobileGlossaryManageButton = document.getElementById('mobile-glossary-manage-button');
  const mobileGlossaryEditorModal = document.getElementById('mobile-glossary-editor-modal');
  const mobileGlossaryEditorList = document.getElementById('mobile-glossary-editor-list');
  const mobileGlossaryEditorCancel = document.getElementById('mobile-glossary-editor-cancel');
  const mobileGlossaryEditorSave = document.getElementById('mobile-glossary-editor-save');
  const mobileGlossaryAdd = document.getElementById('mobile-glossary-add');
  const mobileGlossary = <?= json_encode($mobileGlossary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const msdsPictogramImages = <?= json_encode($msdsPictogramImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const canEditMobileMsds = <?= $canEditMobileMsds ? 'true' : 'false' ?>;
  const recordId = <?= json_encode((string)($record['id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const isMobileViewport = () => window.matchMedia('(max-width: 640px)').matches;

  let pdfDoc = null;
  let pageNum = 1;
  let zoomFactor = 1;
  let rendering = false;
  let pendingPage = null;
  let editingArticle = null;
  let glossaryEntries = Array.isArray(mobileGlossary) ? mobileGlossary.slice() : [];
  let glossaryModalOpenedAt = 0;
  let glossaryScrollRestoreY = 0;

  window.__msdsReaderManaged = true;

  function refreshMobileSectionJump() {
    if (!mobileSectionSelect || !mobileTextBody) {
      return;
    }

    const articles = Array.from(mobileTextBody.querySelectorAll('.mobile-text-section'));
    mobileSectionSelect.innerHTML = '<option value="">항목별 이동</option>';

    articles.forEach((article, index) => {
      const heading = article.querySelector('h3');
      const titleText = normalizeLineText(heading ? heading.textContent : `카드 ${index + 1}`);
      const sectionId = `mobile-msds-section-${index + 1}`;
      article.id = sectionId;

       if (index === 0) {
        return;
      }

      const option = document.createElement('option');
      option.value = sectionId;
      option.textContent = titleText || `카드 ${index + 1}`;
      mobileSectionSelect.appendChild(option);
    });

    if (mobileSectionJump) {
      const hasJumpTargets = mobileSectionSelect.options.length > 1;
      mobileSectionJump.style.display = hasJumpTargets && (isMobileViewport() || canEditMobileMsds) ? 'block' : 'none';
    }
  }

  function updateMobileScrollTopVisibility() {
    if (!mobileScrollTopButton) {
      return;
    }

    const shouldShow = isMobileViewport() && window.scrollY > 140;
    mobileScrollTopButton.classList.toggle('is-visible', shouldShow);
  }

  function buildSectionEditUrl(sectionNumber) {
    const url = new URL(window.location.href);
    url.searchParams.set('edit_section', String(Math.max(1, Number(sectionNumber) || 1)));
    url.hash = 'msds-section-editor';
    return url.toString();
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeLineText(value) {
    return String(value || '')
      .replace(/\s+/g, ' ')
      .replace(/\u0000/g, '')
      .trim();
  }

  function normalizeGlossaryKey(value) {
    return String(value || '')
      .replace(/\u0000/g, '')
      .replace(/\r\n/g, '\n')
      .replace(/\r/g, '\n')
      .replace(/^[\-\•\●\○\▪\‣\◦]+\s*/u, '')
      .replace(/：/g, ':')
      .replace(/\s*:\s*/g, ':')
      .replace(/\s+/g, '')
      .trim();
  }

  function normalizeGlossaryLooseKey(value) {
    return normalizeGlossaryKey(value)
      .replace(/[^0-9A-Za-z가-힣]+/g, '')
      .toLowerCase();
  }

  function findGlossaryEntry(value) {
    const targetKey = normalizeGlossaryKey(value);
    if (!targetKey || !Array.isArray(glossaryEntries)) {
      return null;
    }

    const targetLooseKey = normalizeGlossaryLooseKey(value);
    let bestEntry = null;
    let bestScore = -1;

    for (let index = 0; index < glossaryEntries.length; index += 1) {
      const entry = glossaryEntries[index];
      if (!entry || !entry.term || !entry.content) {
        continue;
      }

      const entryKey = normalizeGlossaryKey(entry.term);
      if (entryKey === targetKey) {
        return { ...entry, _index: index };
      }

      const entryLooseKey = normalizeGlossaryLooseKey(entry.term);
      let score = -1;

      if (entryLooseKey && entryLooseKey === targetLooseKey) {
        score = 900;
      } else if (
        entryKey
        && (
          entryKey.includes(targetKey)
          || targetKey.includes(entryKey)
        )
      ) {
        score = 700;
      } else if (
        entryLooseKey
        && targetLooseKey
        && (
          entryLooseKey.includes(targetLooseKey)
          || targetLooseKey.includes(entryLooseKey)
        )
      ) {
        score = 600;
      }

      if (
        score > bestScore
      ) {
        bestEntry = { ...entry, _index: index };
        bestScore = score;
      }
    }

    return bestEntry;
  }

  function renderGlossaryButtonHtml(entry, label) {
    if (!entry) {
      return escapeHtml(label);
    }

    return `<a class="mobile-glossary-trigger" href="#mobile-glossary-entry-${entry._index}" data-glossary-index="${entry._index}" data-glossary-term="${escapeAttribute(entry.term || '')}" onclick="return window.__openMobileGlossaryFromButton ? window.__openMobileGlossaryFromButton(this) : true;">${escapeHtml(label)}</a>`;
  }

  function openGlossaryFromButton(button) {
    if (!(button instanceof Element)) {
      return false;
    }

    glossaryScrollRestoreY = window.scrollY || window.pageYOffset || 0;
    const glossaryIndex = Number(button.getAttribute('data-glossary-index'));
    const glossaryTerm = button.getAttribute('data-glossary-term') || button.textContent || '';
    openGlossaryModalByTerm(glossaryTerm, Number.isNaN(glossaryIndex) ? null : glossaryIndex);
    return false;
  }

  function resolveGlossaryTriggerFromEvent(event) {
    if (!event) {
      return null;
    }

    if (typeof event.composedPath === 'function') {
      const path = event.composedPath();
      for (let index = 0; index < path.length; index += 1) {
        const node = path[index];
        if (node instanceof Element && node.classList.contains('mobile-glossary-trigger')) {
          return node;
        }
      }
    }

    const rawTarget = event.target;
    if (rawTarget instanceof Element) {
      return rawTarget.closest('.mobile-glossary-trigger');
    }

    if (rawTarget && rawTarget.parentElement) {
      return rawTarget.parentElement.closest('.mobile-glossary-trigger');
    }

    return null;
  }

  function attachGlossaryTriggerHandlers(scope = mobileTextBody) {
    if (!scope) {
      return;
    }

    scope.querySelectorAll('.mobile-glossary-trigger').forEach((button) => {
      if (!(button instanceof Element) || button.dataset.boundGlossaryClick === 'true') {
        return;
      }

      button.dataset.boundGlossaryClick = 'true';
      button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openGlossaryFromButton(button);
      });
    });
  }

  function resolveEditButtonFromEvent(event) {
    if (!event) {
      return null;
    }

    if (typeof event.composedPath === 'function') {
      const path = event.composedPath();
      for (let index = 0; index < path.length; index += 1) {
        const node = path[index];
        if (node instanceof Element && node.classList.contains('mobile-card-edit')) {
          return node;
        }
      }
    }

    const rawTarget = event.target;
    if (rawTarget instanceof Element) {
      return rawTarget.closest('.mobile-card-edit');
    }

    if (rawTarget && rawTarget.parentElement) {
      return rawTarget.parentElement.closest('.mobile-card-edit');
    }

    return null;
  }

  function resolveEditableArticleFromEvent(event) {
    if (!event) {
      return null;
    }

    if (typeof event.composedPath === 'function') {
      const path = event.composedPath();
      for (let index = 0; index < path.length; index += 1) {
        const node = path[index];
        if (node instanceof Element && node.classList.contains('mobile-text-section')) {
          return node;
        }
      }
    }

    const rawTarget = event.target;
    if (rawTarget instanceof Element) {
      return rawTarget.closest('.mobile-text-section');
    }

    if (rawTarget && rawTarget.parentElement) {
      return rawTarget.parentElement.closest('.mobile-text-section');
    }

    return null;
  }

  function openEditorFromButton(button) {
    if (!(button instanceof Element)) {
      return false;
    }

    openEditor(button.closest('.mobile-text-section'));
    return false;
  }

  function hydrateGlossaryTriggers(scope = mobileTextBody) {
    if (!scope || !Array.isArray(glossaryEntries) || !glossaryEntries.length) {
      return;
    }

    scope.querySelectorAll('.mobile-text-table-row').forEach((row) => {
      if (!(row instanceof Element) || row.querySelector('.mobile-glossary-trigger')) {
        return;
      }

      const labelNode = row.querySelector('.mobile-text-table-label');
      const valueNode = row.querySelector('.mobile-text-table-value');
      const label = normalizeLineText(labelNode ? labelNode.textContent : '');
      const value = normalizeLineText(valueNode ? valueNode.textContent : '');
      const combined = [label, value].filter(Boolean).join(' : ');
      const glossaryEntry = findGlossaryEntry(combined);
      if (!glossaryEntry || !valueNode) {
        return;
      }

      row.innerHTML = `<div class="mobile-text-table-value" style="grid-column: 1 / -1;">${renderGlossaryButtonHtml(glossaryEntry, combined)}</div>`;
      row.classList.add('has-glossary-trigger');
    });

    scope.querySelectorAll('.mobile-text-fallback-kv').forEach((node) => {
      if (!(node instanceof Element)) {
        return;
      }

      if (node.querySelector('.mobile-glossary-trigger')) {
        return;
      }

      const labelNode = node.querySelector('.mobile-text-fallback-kv-label');
      const valueNode = node.querySelector('.mobile-text-fallback-kv-value');
      const label = normalizeLineText(labelNode ? labelNode.textContent : '');
      const value = normalizeLineText(valueNode ? valueNode.textContent : '');
      const combined = [label, value].filter(Boolean).join(' ');
      const text = normalizeLineText(combined || node.textContent || '');
      if (!text) {
        return;
      }

      const glossaryEntry = findGlossaryEntry(text);
      if (!glossaryEntry) {
        return;
      }

      node.innerHTML = renderGlossaryButtonHtml(glossaryEntry, text);
      node.classList.add('has-glossary-trigger');
    });

    const targets = scope.querySelectorAll([
      '.mobile-text-paragraph',
      '.mobile-text-fallback-body',
      '.mobile-text-fallback-detail',
      '.mobile-text-table-value',
    ].join(','));

    targets.forEach((node) => {
      if (!(node instanceof Element)) {
        return;
      }

      if (node.closest('.mobile-text-table-row')) {
        return;
      }

      if (node.querySelector('.mobile-glossary-trigger')) {
        return;
      }

      const text = normalizeLineText(node.textContent || '');
      if (!text) {
        return;
      }

      const glossaryEntry = findGlossaryEntry(text);
      if (!glossaryEntry) {
        return;
      }

      node.innerHTML = renderGlossaryButtonHtml(glossaryEntry, text);
    });

    attachGlossaryTriggerHandlers(scope);
  }

  function openGlossaryModalByIndex(index) {
    const entry = glossaryEntries[index];
    if (!entry || !mobileGlossaryModal || !mobileGlossaryTitle || !mobileGlossaryContent) {
      return;
    }

    glossaryModalOpenedAt = Date.now();
    window.requestAnimationFrame(() => {
      mobileGlossaryTitle.textContent = entry.title || entry.term || '용어 설명';
      mobileGlossaryContent.textContent = entry.content || '';
      mobileGlossaryModal.classList.add('is-open');
      mobileGlossaryModal.setAttribute('aria-hidden', 'false');
    });
  }

  function openGlossaryModalByTerm(term, fallbackIndex = null) {
    const normalizedTerm = normalizeGlossaryKey(term);
    if (normalizedTerm && Array.isArray(glossaryEntries)) {
      for (let index = 0; index < glossaryEntries.length; index += 1) {
        const entry = glossaryEntries[index];
        if (!entry || !entry.term || !entry.content) {
          continue;
        }

        if (normalizeGlossaryKey(entry.term) === normalizedTerm) {
          openGlossaryModalByIndex(index);
          return;
        }
      }
    }

    if (fallbackIndex !== null && !Number.isNaN(fallbackIndex)) {
      openGlossaryModalByIndex(fallbackIndex);
    }
  }

  function closeGlossaryModal() {
    if (!mobileGlossaryModal) {
      return;
    }

    mobileGlossaryModal.classList.remove('is-open');
    mobileGlossaryModal.setAttribute('aria-hidden', 'true');
  }

  function closeGlossarySheet(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    const baseUrl = `${window.location.pathname}${window.location.search}`;
    if (window.location.hash && window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState(null, '', baseUrl);
    } else if (window.location.hash) {
      window.location.hash = '';
    }

    window.requestAnimationFrame(() => {
      window.scrollTo(0, glossaryScrollRestoreY);
      window.setTimeout(() => {
        window.scrollTo(0, glossaryScrollRestoreY);
      }, 30);
    });

    return false;
  }

  window.__openMobileGlossaryFromButton = openGlossaryFromButton;
  window.__closeMobileGlossarySheet = closeGlossarySheet;

  function escapeAttribute(value) {
    return escapeHtml(value).replace(/"/g, '&quot;');
  }

  function renderGlossaryEditorRows() {
    if (!mobileGlossaryEditorList) {
      return;
    }

    const rows = glossaryEntries.length ? glossaryEntries : [{ term: '', title: '', content: '' }];
    mobileGlossaryEditorList.innerHTML = rows.map((entry, index) => ''
      + `<div class="mobile-glossary-row" data-index="${index}">`
      + '<div class="mobile-glossary-field">'
      + '<label>표시할 용어 또는 문장</label>'
      + `<input class="mobile-glossary-input" data-field="term" type="text" value="${escapeAttribute(entry.term || '')}" placeholder="예: 고압가스 : 액화가스">`
      + '</div>'
      + '<div class="mobile-glossary-field">'
      + '<label>모달 제목</label>'
      + `<input class="mobile-glossary-input" data-field="title" type="text" value="${escapeAttribute(entry.title || '')}" placeholder="비우면 용어와 같은 제목으로 표시됩니다.">`
      + '</div>'
      + '<div class="mobile-glossary-field">'
      + '<label>설명 내용</label>'
      + `<textarea class="mobile-glossary-textarea" data-field="content" placeholder="작업자가 눌렀을 때 볼 설명을 입력해 주세요.">${escapeHtml(entry.content || '')}</textarea>`
      + '</div>'
      + `<button class="mobile-glossary-remove" type="button" data-remove-index="${index}">삭제</button>`
      + '</div>').join('');
  }

  function openGlossaryEditor() {
    if (!canEditMobileMsds || !mobileGlossaryEditorModal) {
      return;
    }

    renderGlossaryEditorRows();
    mobileGlossaryEditorModal.classList.add('is-open');
    mobileGlossaryEditorModal.setAttribute('aria-hidden', 'false');
  }
  window.__openMobileGlossaryEditor = () => {
    openGlossaryEditor();
    return false;
  };

  function closeGlossaryEditor() {
    if (!mobileGlossaryEditorModal) {
      return;
    }

    mobileGlossaryEditorModal.classList.remove('is-open');
    mobileGlossaryEditorModal.setAttribute('aria-hidden', 'true');
  }

  function collectGlossaryEditorRows() {
    if (!mobileGlossaryEditorList) {
      return [];
    }

    return Array.from(mobileGlossaryEditorList.querySelectorAll('.mobile-glossary-row')).map((row) => {
      const term = normalizeLineText(row.querySelector('[data-field="term"]')?.value || '');
      const title = normalizeLineText(row.querySelector('[data-field="title"]')?.value || '');
      const content = String(row.querySelector('[data-field="content"]')?.value || '')
        .replace(/\r\n/g, '\n')
        .replace(/\r/g, '\n')
        .trim();

      if (!term || !content) {
        return null;
      }

      return {
        term,
        title: title || term,
        content,
      };
    }).filter(Boolean);
  }

  async function persistGlossary(entries) {
    const body = new URLSearchParams();
    body.set('action', 'save_mobile_glossary');
    body.set('record_id', recordId);
    body.set('glossary', JSON.stringify(entries));

    const response = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
      credentials: 'same-origin',
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error(payload && payload.message ? payload.message : '용어 설명을 저장하지 못했습니다.');
    }

    return payload;
  }

  async function saveGlossaryEditor() {
    const nextEntries = collectGlossaryEditorRows();
    const originalButtonText = mobileGlossaryEditorSave ? mobileGlossaryEditorSave.textContent : '';

    try {
      if (mobileGlossaryEditorSave) {
        mobileGlossaryEditorSave.disabled = true;
        mobileGlossaryEditorSave.textContent = '저장 중...';
      }

      const payload = await persistGlossary(nextEntries);
      glossaryEntries = Array.isArray(payload.glossary) ? payload.glossary : nextEntries;

      if (mobileTextStatus) {
        mobileTextStatus.textContent = '용어 설명을 저장했습니다.';
        mobileTextStatus.classList.remove('is-error');
      }

      if (manualMobileContent && renderSavedManualContent('용어 설명을 저장했습니다.')) {
        closeGlossaryEditor();
        return;
      }

      const normalizedSections = normalizeServerSections(serverOcrSections);
      if (normalizedSections.length) {
        renderTextSections(normalizedSections, '용어 설명을 저장했습니다.', false);
      } else if (serverOcrText) {
        const fallbackSections = buildSectionsFromLines(serverOcrText.split(/\r?\n/));
        if (fallbackSections.length) {
          renderTextSections(fallbackSections, '용어 설명을 저장했습니다.', false);
        } else {
          hydrateGlossaryTriggers();
        }
      } else {
        hydrateGlossaryTriggers();
      }

      closeGlossaryEditor();
    } catch (error) {
      if (mobileTextStatus) {
        mobileTextStatus.textContent = error instanceof Error ? error.message : '용어 설명을 저장하지 못했습니다.';
        mobileTextStatus.classList.add('is-error');
      }
      window.alert(error instanceof Error ? error.message : '용어 설명을 저장하지 못했습니다.');
    } finally {
      if (mobileGlossaryEditorSave) {
        mobileGlossaryEditorSave.disabled = false;
        mobileGlossaryEditorSave.textContent = originalButtonText || '저장';
      }
    }
  }

  function isSectionHeading(line) {
    const normalized = normalizeLineText(line);
    return /^\d{1,2}\.\s+/.test(normalized) || /^[①-⑳]\s*/.test(normalized);
  }

  function splitManualSections(rawText) {
    const normalized = String(rawText || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!normalized) {
      return [];
    }

    const lines = normalized
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line && !isPageMarker(line));

    const sections = [];
    let current = null;

    lines.forEach((line) => {
      if (/^\d{1,2}\.\s+/.test(line)) {
        if (current) {
          sections.push(current);
        }
        current = {
          title: line,
          paragraphs: [],
        };
        return;
      }

      if (!current) {
        current = {
          title: '',
          paragraphs: [],
        };
      }

      current.paragraphs.push(line);
    });

    if (current) {
      sections.push(current);
    }

    return sections.filter((section) => section.title || section.paragraphs.length);
  }

  function hasStructuredManualContent(rawText) {
    const normalized = String(rawText || '')
      .replace(/\r\n/g, '\n')
      .replace(/\r/g, '\n')
      .trim();

    if (!normalized) {
      return false;
    }

    return /(^|\n)\d{1,2}\.\s+/u.test(normalized);
  }

  function buildSectionsFromLines(lines) {
    const sections = [];
    let current = null;

    lines.forEach((line) => {
      const cleanLine = normalizeLineText(line);
      if (!cleanLine) {
        return;
      }

      if (!current) {
        current = { title: '추출 본문', paragraphs: [] };
      }

      if (isSectionHeading(cleanLine) && current.paragraphs.length) {
        sections.push(current);
        current = { title: cleanLine, paragraphs: [] };
        return;
      }

      if (isSectionHeading(cleanLine) && current.title === '추출 본문' && !current.paragraphs.length) {
        current.title = cleanLine;
        return;
      }

      current.paragraphs.push(cleanLine);
    });

    if (current && (current.paragraphs.length || current.title !== '추출 본문')) {
      sections.push(current);
    }

    return sections.slice(0, 24);
  }

  function normalizeServerSections(rawSections) {
    if (!Array.isArray(rawSections)) {
      return [];
    }

    const normalizeWarningLabelHeading = (value) => {
      const normalizedValue = normalizeLineText(value);
      const headingMap = {
        '그림문자': '1) 그림문자',
        '신호어': '2) 신호어',
        '유해·위험문구': '3) 유해·위험문구',
        '예방조치문구': '4) 예방조치문구',
      };

      return headingMap[normalizedValue] || normalizedValue;
    };

    const reorderWarningSummaryLines = (lines) => {
      const normalizedLines = lines
        .map((line) => normalizeLineText(line))
        .filter(Boolean)
        .map((line) => line.replace(/^\-\s*/u, '').trim());

      if (!normalizedLines.some((line) => ['그림문자', '1) 그림문자'].includes(line))) {
        return normalizedLines;
      }

      const classificationLines = [];
      const hazardLines = [];
      const preventionLines = [];
      const responseLines = [];
      const storageLines = [];
      const disposalLines = [];
      let signalWord = '';
      let currentBucket = '';

      normalizedLines.forEach((line) => {
        if (/^1\)\s*예방$/u.test(line)) {
          currentBucket = 'prevention';
          return;
        }

        if (/^2\)\s*대응$/u.test(line)) {
          currentBucket = 'response';
          return;
        }

        if (/^3\)\s*저장$/u.test(line)) {
          currentBucket = 'storage';
          return;
        }

        if (/^4\)\s*폐기$/u.test(line)) {
          currentBucket = 'disposal';
          return;
        }

        if (/^H\d{3}/u.test(line)) {
          hazardLines.push(line);
          return;
        }

        if (/^P\d{3}/u.test(line) || /^P\d{3}\+P\d{3}/u.test(line)) {
          if (currentBucket === 'response') {
            responseLines.push(line);
          } else if (currentBucket === 'storage') {
            storageLines.push(line);
          } else if (currentBucket === 'disposal') {
            disposalLines.push(line);
          } else {
            preventionLines.push(line);
          }
          return;
        }

        if (line === '위험' || line === '경고') {
          signalWord = line;
          return;
        }

        if (line.includes('구분')) {
          classificationLines.push(line);
        }
      });

      const ordered = [];
      if (classificationLines.length) {
        ordered.push('가. 유해성·위험성 분류');
        ordered.push(...classificationLines);
      }

      ordered.push('나. 예방조치 문구를 포함한 경고 표지 항목');
      ordered.push('1) 그림문자');
      ordered.push('2) 신호어');
      if (signalWord) {
        ordered.push(signalWord);
      }

      if (hazardLines.length) {
        ordered.push('3) 유해·위험문구');
        ordered.push(...hazardLines);
      }

      ordered.push('4) 예방조치문구');
      if (preventionLines.length) {
        ordered.push('1) 예방');
        ordered.push(...preventionLines);
      }
      if (responseLines.length) {
        ordered.push('2) 대응');
        ordered.push(...responseLines);
      }
      if (storageLines.length) {
        ordered.push('3) 저장');
        ordered.push(...storageLines);
      }
      if (disposalLines.length) {
        ordered.push('4) 폐기');
        ordered.push(...disposalLines);
      }

      return ordered.filter(Boolean);
    };

    const normalizedSections = rawSections.map((section) => {
      const title = normalizeLineText(section && section.title ? section.title : '본문');
      const paragraphs = Array.isArray(section && section.paragraphs)
        ? section.paragraphs.map((paragraph) => {
          const normalizedParagraph = normalizeLineText(paragraph);
          const indicatorMatch = normalizedParagraph.match(/^[○●]\s*(그림문자|신호어|유해·위험 문구|유해·위험문구|예방조치문구)$/);
          if (indicatorMatch) {
            return indicatorMatch[1] === '유해·위험 문구' ? '유해·위험문구' : indicatorMatch[1];
          }

          return normalizedParagraph === '물질안전보건자료 (MSDS)' ? '' : normalizedParagraph;
        }).filter(Boolean)
        : [];

      if (!paragraphs.length) {
        return null;
      }

      return {
        title: title || '본문',
        paragraphs,
      };
    }).filter(Boolean);

    const isMajorSectionTitle = (title) => /^\d{1,2}\.\s+/.test(normalizeLineText(title || ''));
    const prefaceLines = [];
    const mergedSections = [];
    let reachedMajorSection = false;

    normalizedSections.forEach((section) => {
      if (!reachedMajorSection && !isMajorSectionTitle(section.title)) {
        if (section.title && section.title !== '추출 본문') {
          const rawPrefaceTitle = normalizeLineText(section.title);
          const titleMatch = rawPrefaceTitle.match(/^(\d+\))\s*(.+)$/);

          if (titleMatch && ['예방', '대응', '저장', '폐기'].includes(titleMatch[2])) {
            prefaceLines.push(`${titleMatch[1]} ${titleMatch[2]}`);
          } else {
            prefaceLines.push(normalizeWarningLabelHeading(titleMatch ? titleMatch[1] === '1)' ? '예방조치문구' : titleMatch[2] : rawPrefaceTitle));
          }
        }
        prefaceLines.push(...section.paragraphs.map((line) => normalizeWarningLabelHeading(line)));
        return;
      }

      reachedMajorSection = true;
      mergedSections.push(section);
    });

    if (prefaceLines.length) {
      mergedSections.unshift({
        title: '경고표지 요약',
        paragraphs: reorderWarningSummaryLines(prefaceLines),
      });
    }

    return mergedSections;
  }

  function renderTextSections(sections, statusText, isError) {
    if (!mobileTextReader || !mobileTextBody || !mobileTextStatus) {
      return;
    }

    mobileTextStatus.textContent = statusText;
    mobileTextStatus.classList.toggle('is-error', !!isError);

    if (!sections.length) {
      mobileTextBody.innerHTML = '<div class="mobile-text-empty">표시할 텍스트를 찾지 못했습니다. 원본보기로 PDF를 확인해 주세요.</div>';
      refreshMobileSectionJump();
      return;
    }

    mobileTextBody.innerHTML = sections.map((section, sectionIndex) => {
      const originalTitle = normalizeLineText(section.title || '본문');
      const titleInfo = parseSectionTitle(originalTitle);
      const sectionClass = `${titleInfo.index ? 'mobile-text-section has-index' : 'mobile-text-section'}${canEditMobileMsds ? ' is-editable' : ''}`;
      const blocksHtml = renderSectionBlocks(section);
      const editButtonHtml = canEditMobileMsds
        ? `<a class="mobile-card-edit" href="${escapeAttribute(buildSectionEditUrl(sectionIndex + 1))}" aria-label="이 카드 수정">수정</a>`
        : '';
      return `<article class="${sectionClass}">${editButtonHtml}<h3>${escapeHtml(originalTitle || '본문')}</h3>${blocksHtml}</article>`;
    }).join('');
    hydrateGlossaryTriggers();
    decorateEditableCards();
    refreshMobileSectionJump();
  }

  function formatManualBodyLine(line) {
    const normalized = normalizeLineText(line);
    const escaped = escapeHtml(normalized);
    const glossaryEntry = findGlossaryEntry(normalized);
    const indicatorMatch = normalized.match(/^[○●]\s*(그림문자|신호어|유해·위험 문구|유해·위험문구|예방조치문구)$/);
    const indicatorText = indicatorMatch
      ? (indicatorMatch[1] === '유해·위험 문구' ? '유해·위험문구' : indicatorMatch[1])
      : '';

    if (normalized === '그림문자' || normalized === '1) 그림문자' || indicatorText === '그림문자') {
      const labelText = normalized === '그림문자' ? '1) 그림문자' : normalized;
      const finalLabel = indicatorText === '그림문자' ? '1) 그림문자' : labelText;
      return `<div class="mobile-text-fallback-detail">${escapeHtml(finalLabel)}</div>${renderMobilePictogramCard(manualMobileContent, true, '')}`;
    }

    if (indicatorText) {
      const headingMap = {
        '신호어': '2) 신호어',
        '유해·위험문구': '3) 유해·위험문구',
        '예방조치문구': '4) 예방조치문구',
      };
      return `<div class="mobile-text-fallback-detail">${escapeHtml(headingMap[indicatorText] || indicatorText)}</div>`;
    }

    if (/^[가-하]\.\s*/.test(normalized)) {
      return `<div class="mobile-text-fallback-subhead">${escaped}</div>`;
    }

    const kvMatch = normalized.match(/^(.{1,80}?[:：])\s*(.+)$/);
    if (kvMatch) {
      if (glossaryEntry) {
        const detailClass = /^\d+\)\s*/.test(normalized) ? ' mobile-text-fallback-kv-detail' : '';
        return `<div class="mobile-text-fallback-kv has-glossary-trigger${detailClass}">${renderGlossaryButtonHtml(glossaryEntry, normalized)}</div>`;
      }

      const label = escapeHtml(normalizeLineText(kvMatch[1]));
      const value = escapeHtml(normalizeLineText(kvMatch[2]));
      const detailClass = /^\d+\)\s*/.test(normalized) ? ' mobile-text-fallback-kv-detail' : '';
      return `<div class="mobile-text-fallback-kv${detailClass}"><span class="mobile-text-fallback-kv-label">${label}</span><span class="mobile-text-fallback-kv-value">${value}</span></div>`;
    }

    if (/^\d+\)\s*/.test(normalized)) {
      return `<div class="mobile-text-fallback-detail">${glossaryEntry ? renderGlossaryButtonHtml(glossaryEntry, normalized) : escaped}</div>`;
    }

    return `<p class="mobile-text-paragraph mobile-text-fallback-body">${glossaryEntry ? renderGlossaryButtonHtml(glossaryEntry, normalized) : escaped}</p>`;
  }

  function renderSavedManualContent(statusText = '관리자가 정리한 모바일 전용 본문입니다.') {
    if (!hasStructuredManualContent(manualMobileContent)) {
      return false;
    }

    const manualSections = splitManualSections(manualMobileContent);
    if (!manualSections.length) {
      return false;
    }

    if (mobileTextStatus) {
      mobileTextStatus.textContent = statusText;
      mobileTextStatus.classList.remove('is-error');
    }

    mobileTextBody.innerHTML = manualSections.map((section, sectionIndex) => {
      const originalTitle = normalizeLineText(section.title || '');
      const titleHtml = originalTitle ? `<h3>${escapeHtml(originalTitle)}</h3>` : '';
      const normalizedLines = [];
      const sourceLines = (section.paragraphs || []).map((line) => normalizeLineText(line)).filter(Boolean);

      for (let index = 0; index < sourceLines.length; index += 1) {
        const currentLine = sourceLines[index];
        const nextLine = sourceLines[index + 1] || '';

        if (isPictogramLabelLine(currentLine)) {
          continue;
        }

        const shouldMergeWithNext = /[:：]\s*$/.test(currentLine)
          && nextLine
          && !/^\d{1,2}\.\s+/.test(nextLine)
          && !/^[가-하]\.\s*/.test(nextLine)
          && !/^\d+\)\s*/.test(nextLine);

        if (shouldMergeWithNext) {
          normalizedLines.push(`${currentLine} ${nextLine}`.trim());
          index += 1;
          continue;
        }

        normalizedLines.push(currentLine);
      }

      const bodyHtml = normalizedLines.map((line) => formatManualBodyLine(line)).join('');
      const sectionClass = canEditMobileMsds ? 'mobile-text-section is-editable' : 'mobile-text-section';
      const editButtonHtml = canEditMobileMsds
        ? `<a class="mobile-card-edit" href="${escapeAttribute(buildSectionEditUrl(sectionIndex + 1))}" aria-label="이 카드 수정">수정</a>`
        : '';
      return `<article class="${sectionClass}">${editButtonHtml}${titleHtml}${bodyHtml || '<div class="mobile-text-empty">표시할 본문이 없습니다.</div>'}</article>`;
    }).join('');

    hydrateGlossaryTriggers();
    decorateEditableCards();
    refreshMobileSectionJump();
    return true;
  }

  function decorateEditableCards() {
    if (!mobileTextBody || !canEditMobileMsds) {
      return;
    }

    mobileTextBody.querySelectorAll('.mobile-text-section').forEach((article, articleIndex) => {
      article.classList.add('is-editable');

      if (!article.querySelector('.mobile-card-edit')) {
        const editButton = document.createElement('a');
        editButton.className = 'mobile-card-edit';
        editButton.textContent = '수정';
        editButton.href = buildSectionEditUrl(articleIndex + 1);
        editButton.setAttribute('aria-label', '이 카드 수정');
        article.appendChild(editButton);
      }
    });
  }

  function getEditableBodyText(article) {
    if (!article) {
      return '';
    }

    const parts = [];
    Array.from(article.children).forEach((child) => {
      if (child.tagName === 'H3') {
        return;
      }

      const text = normalizeLineText(child.innerText || child.textContent || '');
      if (text) {
        parts.push(text.replace(/\n{2,}/g, '\n'));
      }
    });

    return parts.join('\n');
  }

  function openEditor(article) {
    if (!canEditMobileMsds || !article || !mobileEditorModal || !mobileEditorTextarea) {
      return;
    }

    editingArticle = article;
    mobileEditorTextarea.value = getEditableBodyText(article);
    mobileEditorModal.classList.add('is-open');
    mobileEditorModal.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => mobileEditorTextarea.focus(), 20);
  }

  window.openMobileMsdsEditor = (article) => {
    openEditor(article instanceof Element ? article.closest('.mobile-text-section') || article : null);
  };
  window.__openMobileMsdsEditorButton = (button) => openEditorFromButton(button);

  function closeEditor() {
    if (!mobileEditorModal) {
      return;
    }

    editingArticle = null;
    mobileEditorModal.classList.remove('is-open');
    mobileEditorModal.setAttribute('aria-hidden', 'true');
  }

  function serializeMobileCards() {
    if (!mobileTextBody) {
      return '';
    }

    const blocks = [];
    mobileTextBody.querySelectorAll('.mobile-text-section').forEach((article) => {
      const heading = article.querySelector('h3');
      const title = normalizeLineText(heading ? heading.textContent : '');
      const bodyParts = [];

      Array.from(article.children).forEach((child) => {
        if (child.tagName === 'H3') {
          return;
        }

        if (child.classList.contains('mobile-text-fallback-kv')) {
          const labelNode = child.querySelector('.mobile-text-fallback-kv-label');
          const valueNode = child.querySelector('.mobile-text-fallback-kv-value');
          const label = normalizeLineText(labelNode ? labelNode.textContent : '');
          const value = normalizeLineText(valueNode ? valueNode.textContent : '');
          const combined = [label, value].filter(Boolean).join(' ');
          if (combined) {
            bodyParts.push(combined);
          }
          return;
        }

        const text = String(child.innerText || child.textContent || '')
          .replace(/\r\n/g, '\n')
          .replace(/\r/g, '\n')
          .split('\n')
          .map((line) => normalizeLineText(line))
          .filter(Boolean)
          .join('\n');

        if (text) {
          bodyParts.push(text);
        }
      });

      const chunk = [title, ...bodyParts].filter(Boolean).join('\n');
      if (chunk) {
        blocks.push(chunk);
      }
    });

    return blocks.join('\n\n');
  }

  async function persistMobileContent(content) {
    const body = new URLSearchParams();
    body.set('action', 'save_mobile_content');
    body.set('record_id', recordId);
    body.set('content', content);

    const response = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
      credentials: 'same-origin',
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error(payload && payload.message ? payload.message : '편집 내용을 저장하지 못했습니다.');
    }

    return payload;
  }

  async function applyEditorChanges() {
    if (!editingArticle || !mobileEditorTextarea) {
      closeEditor();
      return;
    }

    const title = editingArticle.querySelector('h3');
    const safeTitle = title ? title.outerHTML : '';
    const lines = mobileEditorTextarea.value
      .replace(/\r\n/g, '\n')
      .replace(/\r/g, '\n')
      .split('\n')
      .map((line) => normalizeLineText(line))
      .filter(Boolean);

    const bodyHtml = lines.length
      ? lines.map((line) => `<p class="mobile-text-paragraph">${escapeHtml(line)}</p>`).join('')
      : '<div class="mobile-text-empty">표시할 본문이 없습니다.</div>';

    editingArticle.innerHTML = `${safeTitle}${bodyHtml}`;
    decorateEditableCards();

    const serializedContent = serializeMobileCards();
    const originalButtonText = mobileEditorApply ? mobileEditorApply.textContent : '';

    try {
      if (mobileEditorApply) {
        mobileEditorApply.disabled = true;
        mobileEditorApply.textContent = '저장 중...';
      }

      await persistMobileContent(serializedContent);
      manualMobileContent = serializedContent;
      if (mobileTextStatus) {
        mobileTextStatus.textContent = '편집 내용을 저장했습니다.';
        mobileTextStatus.classList.remove('is-error');
      }
      renderSavedManualContent('편집 내용을 저장했습니다.');
      closeEditor();
    } catch (error) {
      if (mobileTextStatus) {
        mobileTextStatus.textContent = error instanceof Error ? error.message : '편집 내용을 저장하지 못했습니다.';
        mobileTextStatus.classList.add('is-error');
      }
      window.alert(error instanceof Error ? error.message : '편집 내용을 저장하지 못했습니다.');
    } finally {
      if (mobileEditorApply) {
        mobileEditorApply.disabled = false;
        mobileEditorApply.textContent = originalButtonText || '적용';
      }
    }
  }

  function parseSectionTitle(rawTitle) {
    const title = normalizeLineText(rawTitle || '본문');
    const match = title.match(/^((?:\d{1,2}[.)])|(?:[①-⑳]))\s*(.+)$/);
    if (!match) {
      return { index: '', text: title || '본문' };
    }

    return {
      index: match[1],
      text: match[2] || title,
    };
  }

  function isPageMarker(line) {
    return /^-\s*\d+\s*-$/.test(line) || /^\d+\s*\/\s*\d+$/.test(line);
  }

  function isSubheadingLine(line) {
    return /^[가-하]\.\s*/.test(line);
  }

  function getMsdsPictogramKeys(sourceText) {
    const normalized = normalizeLineText(sourceText);
    const keys = [];

    if (/(인화성 가스|극인화성 가스|인화성 액체|H220|H221|H224|H225|H226)/i.test(normalized)) {
      keys.push('flame');
    }

    if (/(고압가스|압축가스|액화가스|냉동액화가스|용해가스|H280|H281)/i.test(normalized)) {
      keys.push('gas-cylinder');
    }

    if (/(급성 독성\(흡입|피부 부식성\/피부 자극성|눈 자극성|심한 눈 손상성\/눈 자극성|호흡기계자극|H315|H319|H332|H335)/i.test(normalized)) {
      keys.push('exclamation');
    }

    if (/(발암성|생식세포 변이원성|특정표적장기 독성\(반복 노출\)|H340|H341|H350|H351|H372|H373)/i.test(normalized)) {
      keys.push('health-hazard');
    }

    return [...new Set(keys)];
  }

  function getMsdsPictogramDefinitions() {
    return {
      flame: {
        label: '인화성',
        image: msdsPictogramImages.flame || '',
      },
      'gas-cylinder': {
        label: '고압가스',
        image: msdsPictogramImages['gas-cylinder'] || '',
      },
      exclamation: {
        label: '경고',
        image: msdsPictogramImages.exclamation || '',
      },
      'health-hazard': {
        label: '건강유해성',
        image: msdsPictogramImages['health-hazard'] || '',
      },
    };
  }

  function renderMobilePictogramCard(sourceText, isInline = false, titleText = '그림문자') {
    const pictogramKeys = getMsdsPictogramKeys(sourceText);
    if (!pictogramKeys.length) {
      return '<div class="mobile-text-fallback-detail">그림문자 정보가 없습니다.</div>';
    }

    const definitions = getMsdsPictogramDefinitions();
    const itemsHtml = pictogramKeys.map((key) => {
      const definition = definitions[key];
      if (!definition) {
        return '';
      }

      const visualHtml = definition.image
        ? `<img class="mobile-pictogram-image" src="${escapeAttribute(definition.image)}" alt="${escapeAttribute(definition.label)}" loading="lazy">`
        : (definition.svg || '');

      return `<div class="mobile-pictogram-item">${visualHtml}<div class="mobile-pictogram-label">${escapeHtml(definition.label)}</div></div>`;
    }).join('');

    const cardClass = isInline ? 'mobile-pictogram-card is-inline' : 'mobile-pictogram-card';
    const titleHtml = titleText ? `<div class="mobile-pictogram-title">${escapeHtml(titleText)}</div>` : '';
    return `<div class="${cardClass}">${titleHtml}<div class="mobile-pictogram-list">${itemsHtml}</div></div>`;
  }

  function isPictogramLabelLine(line) {
    return ['가스실린더', '고압가스', '불꽃', '인화성', '느낌표', '경고', '건강유해성'].includes(normalizeLineText(line));
  }

  function isLikelyKeyLabel(line) {
    const normalized = normalizeLineText(line);
    if (!normalized || normalized.length > 26) {
      return false;
    }

    if (/[.:：]/.test(normalized)) {
      return false;
    }

    return !/\d{3,}/.test(normalized);
  }

  function splitKnownFieldLine(line) {
    const normalized = normalizeLineText(line);
    if (!normalized) {
      return null;
    }

    const numberedMatch = normalized.match(/^(\d+\))\s*(.+)$/);
    const numberPrefix = numberedMatch ? numberedMatch[1] : '';
    const targetText = numberedMatch ? normalizeLineText(numberedMatch[2]) : normalized;

    const knownLabels = [
      '제품명',
      '제품의 권고 용도',
      '제품의 사용상의 제한',
      '회사명',
      '주소',
      '긴급전화번호',
      '유해·위험성 분류',
      '그림문자',
      '신호어',
      '유해·위험문구',
      '예방조치문구',
      '물질명',
      '이명(관용명)',
      'CAS 번호',
      '함유량(%)',
    ];

    for (const label of knownLabels) {
      if (!targetText.startsWith(label)) {
        continue;
      }

      const remainder = normalizeLineText(targetText.slice(label.length)).replace(/^[:：]?\s*/, '');
      if (!remainder) {
        return null;
      }

      return {
        label: numberPrefix ? `${numberPrefix} ${label}` : label,
        value: remainder,
      };
    }

    return null;
  }
  function splitSubheadingTitle(rawTitle) {
    const normalized = normalizeLineText(rawTitle);
    const match = normalized.match(/^([가-하]\.)\s*(.+)$/);
    if (!match) {
      return { marker: '', title: normalized };
    }

    return {
      marker: match[1],
      title: match[2] || normalized,
    };
  }

  function buildGridTable(lines) {
    if (!lines.length) {
      return '';
    }

    const headerIndex = lines.findIndex((line, index) => /CAS/.test(line) && /함유/.test(lines[Math.min(lines.length - 1, index + 1)] || ''));
    if (headerIndex === -1 || lines.length < headerIndex + 8) {
      return '';
    }

    const headers = lines.slice(headerIndex, headerIndex + 4);
    const values = lines.slice(headerIndex + 4);
    if (headers.length !== 4 || values.length < 4) {
      return '';
    }

    const rows = [];
    for (let index = 0; index < values.length; index += 4) {
      const chunk = values.slice(index, index + 4);
      if (chunk.length === 4) {
        rows.push(chunk);
      }
    }

    if (!rows.length) {
      return '';
    }

    const columns = `repeat(${headers.length}, minmax(0, 1fr))`;
    const headHtml = `<div class="mobile-text-grid-row is-head" style="grid-template-columns: ${columns};">${headers.map((header) => `<div class="mobile-text-grid-cell">${escapeHtml(header)}</div>`).join('')}</div>`;
    const bodyHtml = rows.map((row) => `<div class="mobile-text-grid-row" style="grid-template-columns: ${columns};">${row.map((cell) => `<div class="mobile-text-grid-cell">${escapeHtml(cell)}</div>`).join('')}</div>`).join('');
    return `<div class="mobile-text-grid-table">${headHtml}${bodyHtml}</div>`;
  }

  function buildKeyValueRows(lines) {
    const rows = [];
    const consumedIndexes = new Set();
    let index = 0;

    while (index < lines.length) {
      const current = lines[index];
      const combinedField = splitKnownFieldLine(current);
      if (combinedField) {
        rows.push(combinedField);
        consumedIndexes.add(index);
        index += 1;
        continue;
      }

      const colonMatch = current.match(/^([^:：]{1,30})\s*[:：]\s*(.+)$/);
      if (colonMatch) {
        rows.push({ label: colonMatch[1], value: colonMatch[2] });
        consumedIndexes.add(index);
        index += 1;
        continue;
      }

      const next = lines[index + 1] || '';
      if (isLikelyKeyLabel(current) && next && !isLikelyKeyLabel(next) && !isSubheadingLine(next) && !isSectionHeading(next)) {
        rows.push({ label: current, value: next });
        consumedIndexes.add(index);
        consumedIndexes.add(index + 1);
        index += 2;
        continue;
      }

      index += 1;
    }

    return { rows, consumedIndexes };
  }

  function renderKeyValueTable(rows) {
    if (!rows.length) {
      return '';
    }

    return `<div class="mobile-text-table">${rows.map((row) => {
      const combinedLine = `${normalizeLineText(row.label)} : ${normalizeLineText(row.value)}`;
      const glossaryEntry = findGlossaryEntry(combinedLine);
      if (glossaryEntry) {
        return `<div class="mobile-text-table-row has-glossary-trigger"><div class="mobile-text-table-value" style="grid-column: 1 / -1;">${renderGlossaryButtonHtml(glossaryEntry, combinedLine)}</div></div>`;
      }

      return `<div class="mobile-text-table-row"><div class="mobile-text-table-label">${escapeHtml(row.label)}</div><div class="mobile-text-table-value">${escapeHtml(row.value)}</div></div>`;
    }).join('')}</div>`;
  }

  function renderParagraphLines(lines) {
    return lines.map((line) => {
      const glossaryEntry = findGlossaryEntry(line);
      return `<p class="mobile-text-paragraph">${glossaryEntry ? renderGlossaryButtonHtml(glossaryEntry, line) : escapeHtml(line)}</p>`;
    }).join('');
  }

  function renderSubsectionBlock(title, lines) {
    const cleanLines = lines.filter((line) => !isPageMarker(line));
    const subsectionTitle = splitSubheadingTitle(title);
    const workingLines = [...cleanLines];

    const inlineField = splitKnownFieldLine(subsectionTitle.title);
    if (inlineField) {
      workingLines.unshift(inlineField.value);
      title = `${subsectionTitle.marker} ${inlineField.label}`.trim();
    } else {
      title = [subsectionTitle.marker, subsectionTitle.title].filter(Boolean).join(' ').trim();
    }

    if (!workingLines.length) {
      return `<section class="mobile-text-subsection"><p class="mobile-text-subsection-title">${escapeHtml(title)}</p></section>`;
    }

    const gridTableHtml = buildGridTable(workingLines);
    if (gridTableHtml) {
      return `<section class="mobile-text-subsection"><p class="mobile-text-subsection-title">${escapeHtml(title)}</p>${gridTableHtml}</section>`;
    }

    const { rows: keyValueRows, consumedIndexes } = buildKeyValueRows(workingLines);
    const tableHtml = renderKeyValueTable(keyValueRows);
    const subsectionText = [title, ...workingLines].join('\n');
    const isPictogramHeadingLine = (line) => ['그림문자', '1) 그림문자'].includes(normalizeLineText(line));
    const paragraphLines = workingLines.filter((line, idx) => !consumedIndexes.has(idx) && !isPictogramHeadingLine(line) && !isPictogramLabelLine(line));
    const pictogramHtml = workingLines.some((line) => isPictogramHeadingLine(line))
      ? `<div class="mobile-text-fallback-detail">1) 그림문자</div>${renderMobilePictogramCard(subsectionText, true, '')}`
      : '';
    const paragraphsHtml = paragraphLines.length ? renderParagraphLines(paragraphLines) : '';
    return `<section class="mobile-text-subsection"><p class="mobile-text-subsection-title">${escapeHtml(title)}</p>${tableHtml}${pictogramHtml}${paragraphsHtml}</section>`;
  }

  function renderSectionBlocks(section) {
    const allLines = (section.paragraphs || [])
      .map((line) => normalizeLineText(line))
      .filter((line) => line && !isPageMarker(line));

    if (!allLines.length) {
      return '<div class="mobile-text-empty">표시할 본문이 없습니다.</div>';
    }

    const fullSectionTable = buildGridTable(allLines);
    if (fullSectionTable) {
      return fullSectionTable;
    }

    const subsections = [];
    let currentSubsection = null;
    let rootLines = [];

    allLines.forEach((line) => {
      if (isSubheadingLine(line)) {
        if (currentSubsection) {
          subsections.push(currentSubsection);
        }
        currentSubsection = {
          title: line,
          lines: [],
        };
        return;
      }

      if (currentSubsection) {
        currentSubsection.lines.push(line);
      } else {
        rootLines.push(line);
      }
    });

    if (currentSubsection) {
      subsections.push(currentSubsection);
    }

    let html = '';
    if (rootLines.length) {
      const { rows: rootRows, consumedIndexes: rootConsumedIndexes } = buildKeyValueRows(rootLines);
      const rootTable = renderKeyValueTable(rootRows);
      const rootParagraphLines = rootLines.filter((_, idx) => !rootConsumedIndexes.has(idx));
      const rootParagraphs = rootParagraphLines.length ? renderParagraphLines(rootParagraphLines) : '';
      html += `${rootTable}${rootParagraphs}`;
    }

    if (subsections.length) {
      html += subsections.map((subsection) => renderSubsectionBlock(subsection.title, subsection.lines)).join('');
    }

    return html || renderParagraphLines(allLines);
  }

  async function extractTextSections(documentRef) {
    const allLines = [];

    for (let pageIndex = 1; pageIndex <= documentRef.numPages; pageIndex += 1) {
      const page = await documentRef.getPage(pageIndex);
      const textContent = await page.getTextContent();
      const rows = new Map();

      textContent.items.forEach((item) => {
        const text = normalizeLineText(item.str || '');
        if (!text) {
          return;
        }

        const transform = Array.isArray(item.transform) ? item.transform : [0, 0, 0, 0, 0, 0];
        const x = Number(transform[4] || 0);
        const y = Math.round(Number(transform[5] || 0));
        const key = String(y);

        if (!rows.has(key)) {
          rows.set(key, []);
        }

        rows.get(key).push({ x, text });
      });

      const pageLines = Array.from(rows.entries())
        .sort((a, b) => Number(b[0]) - Number(a[0]))
        .map((entry) => entry[1]
          .sort((a, b) => a.x - b.x)
          .map((part) => part.text)
          .join(' ')
        )
        .map((line) => normalizeLineText(line))
        .filter(Boolean);

      allLines.push(...pageLines);
    }

    return buildSectionsFromLines(allLines);
  }

  function setLoadingState(isLoading) {
    stage.classList.toggle('is-loading', isLoading);
  }

  function updateControls() {
    if (!pdfDoc) {
      pageIndicator.textContent = '불러오는 중...';
      return;
    }

    prevButton.disabled = pageNum <= 1 || rendering;
    nextButton.disabled = pageNum >= pdfDoc.numPages || rendering;
    zoomOutButton.disabled = zoomFactor <= 0.8 || rendering;
    zoomInButton.disabled = zoomFactor >= 2.2 || rendering;
    pageSelect.disabled = rendering;
    pageSelect.value = String(pageNum);
    pageIndicator.textContent = `${pageNum} / ${pdfDoc.numPages} 페이지 · ${Math.round(zoomFactor * 100)}%`;
  }

  async function renderPage(targetPage) {
    if (!pdfDoc) {
      return;
    }

    rendering = true;
    setLoadingState(true);
    updateControls();

    try {
      const page = await pdfDoc.getPage(targetPage);
      const baseViewport = page.getViewport({ scale: 1 });
      const availableWidth = Math.max(280, stage.clientWidth - 36);
      const fitScale = availableWidth / baseViewport.width;
      const renderScale = fitScale * zoomFactor;
      const viewport = page.getViewport({ scale: renderScale });
      const ratio = window.devicePixelRatio || 1;

      canvas.width = Math.floor(viewport.width * ratio);
      canvas.height = Math.floor(viewport.height * ratio);
      canvas.style.width = `${Math.round(viewport.width)}px`;
      canvas.style.height = `${Math.round(viewport.height)}px`;

      const renderContext = {
        canvasContext: context,
        viewport,
        transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null,
      };

      await page.render(renderContext).promise;
      pageNum = targetPage;
      stage.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    } catch (error) {
      pageIndicator.textContent = '문서를 표시하지 못했습니다.';
      console.error(error);
    } finally {
      rendering = false;
      setLoadingState(false);
      updateControls();
      if (pendingPage !== null && pendingPage !== pageNum) {
        const queuedPage = pendingPage;
        pendingPage = null;
        renderPage(queuedPage);
      } else {
        pendingPage = null;
      }
    }
  }

  function queueRender(targetPage) {
    if (!pdfDoc) {
      return;
    }

    const safePage = Math.min(Math.max(targetPage, 1), pdfDoc.numPages);
    if (rendering) {
      pendingPage = safePage;
      return;
    }

    renderPage(safePage);
  }

  function rebuildPageOptions(totalPages) {
    const options = [];
    for (let index = 1; index <= totalPages; index += 1) {
      options.push(`<option value="${index}">${index} 페이지</option>`);
    }
    pageSelect.innerHTML = options.join('');
  }

  prevButton.addEventListener('click', () => queueRender(pageNum - 1));
  nextButton.addEventListener('click', () => queueRender(pageNum + 1));
  zoomOutButton.addEventListener('click', () => {
    zoomFactor = Math.max(0.8, +(zoomFactor - 0.15).toFixed(2));
    queueRender(pageNum);
  });
  zoomInButton.addEventListener('click', () => {
    zoomFactor = Math.min(2.2, +(zoomFactor + 0.15).toFixed(2));
    queueRender(pageNum);
  });
  pageSelect.addEventListener('change', () => queueRender(Number(pageSelect.value)));

  if (mobileSectionSelect) {
    mobileSectionSelect.addEventListener('change', () => {
      const targetId = mobileSectionSelect.value;
      if (!targetId) {
        return;
      }

      const target = document.getElementById(targetId);
      if (!target) {
        return;
      }

      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  if (mobileScrollTopButton) {
    mobileScrollTopButton.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  if (mobileGlossaryClose) {
    mobileGlossaryClose.addEventListener('click', closeGlossaryModal);
  }
  if (mobileGlossaryModal) {
    mobileGlossaryModal.addEventListener('click', (event) => {
      if (Date.now() - glossaryModalOpenedAt < 250) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      if (event.target === mobileGlossaryModal) {
        closeGlossaryModal();
      }
    });
  }
  if (mobileGlossaryManageButton) {
    mobileGlossaryManageButton.addEventListener('click', openGlossaryEditor);
  }
  document.querySelectorAll('.mobile-glossary-sheet-close').forEach((node) => {
    node.addEventListener('click', closeGlossarySheet);
  });
  if (mobileGlossaryAdd) {
    mobileGlossaryAdd.addEventListener('click', () => {
      glossaryEntries.push({ term: '', title: '', content: '' });
      renderGlossaryEditorRows();
    });
  }
  if (mobileGlossaryEditorList) {
    mobileGlossaryEditorList.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-remove-index]') : null;
      if (!target) {
        return;
      }

      const index = Number(target.getAttribute('data-remove-index'));
      if (Number.isNaN(index)) {
        return;
      }

      glossaryEntries.splice(index, 1);
      renderGlossaryEditorRows();
    });
  }
  if (mobileGlossaryEditorCancel) {
    mobileGlossaryEditorCancel.addEventListener('click', closeGlossaryEditor);
  }
  if (mobileGlossaryEditorSave) {
    mobileGlossaryEditorSave.addEventListener('click', saveGlossaryEditor);
  }
  if (mobileGlossaryEditorModal) {
    mobileGlossaryEditorModal.addEventListener('click', (event) => {
      if (event.target === mobileGlossaryEditorModal) {
        closeGlossaryEditor();
      }
    });
  }

  let resizeTimer = null;
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      queueRender(pageNum);
      refreshMobileSectionJump();
      updateMobileScrollTopVisibility();
    }, 180);
  });
  window.addEventListener('load', () => {
    refreshMobileSectionJump();
    updateMobileScrollTopVisibility();
  });
  window.addEventListener('scroll', updateMobileScrollTopVisibility, { passive: true });

  updateControls();
  decorateEditableCards();
  attachGlossaryTriggerHandlers();
  refreshMobileSectionJump();
  updateMobileScrollTopVisibility();

  if (mobileTextBody) {
    mobileTextBody.addEventListener('click', (event) => {
      const glossaryButton = resolveGlossaryTriggerFromEvent(event);
      if (glossaryButton) {
        event.preventDefault();
        event.stopPropagation();
        openGlossaryFromButton(glossaryButton);
        return;
      }

      if (!canEditMobileMsds) {
        return;
      }

    });
  }

  if (mobileEditorCancel) {
    mobileEditorCancel.addEventListener('click', closeEditor);
  }
  if (mobileEditorApply) {
    mobileEditorApply.addEventListener('click', applyEditorChanges);
  }
  if (mobileEditorModal) {
    mobileEditorModal.addEventListener('click', (event) => {
      if (event.target === mobileEditorModal) {
        closeEditor();
      }
    });
  }
  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobileGlossaryModal && mobileGlossaryModal.classList.contains('is-open')) {
      closeGlossaryModal();
    }
    if (event.key === 'Escape' && mobileGlossaryEditorModal && mobileGlossaryEditorModal.classList.contains('is-open')) {
      closeGlossaryEditor();
    }
  });
  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobileEditorModal && mobileEditorModal.classList.contains('is-open')) {
      closeEditor();
    }
  });

  async function loadPdfDocument() {
    pageIndicator.textContent = 'PDF 파일을 불러오는 중...';
    const response = await fetch(pdfUrl, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
    });

    if (!response.ok) {
      throw new Error(`PDF 요청에 실패했습니다. (${response.status})`);
    }

    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    if (contentType.includes('text/html')) {
      throw new Error('PDF 대신 로그인 또는 HTML 응답이 반환되었습니다.');
    }

    const pdfData = await response.arrayBuffer();
    if (!pdfData || !pdfData.byteLength) {
      throw new Error('PDF 데이터가 비어 있습니다.');
    }

    pageIndicator.textContent = 'PDF 문서를 해석하는 중...';
    const loadingTask = pdfjsLib.getDocument({
      data: pdfData,
      cMapPacked: true,
      disableWorker: true,
    });

    return loadingTask.promise;
  }

  (async () => {
    if (!isMobileViewport()) {
      return;
    }

    try {
      pdfDoc = await loadPdfDocument();
      if (mobileTextReader && isMobileViewport()) {
        const serverRenderedGlossaryCount = mobileTextBody
          ? mobileTextBody.querySelectorAll('.mobile-glossary-trigger').length
          : 0;

        if (serverRenderedGlossaryCount > 0) {
          hydrateGlossaryTriggers();
          decorateEditableCards();
          refreshMobileSectionJump();
        } else if (renderSavedManualContent()) {
        } else if (serverOcrText) {
          const fallbackServerSections = buildSectionsFromLines(serverOcrText.split(/\r?\n/));
          if (fallbackServerSections.length) {
            const engineLabel = serverOcrEngine ? `서버 ${serverOcrEngine}` : '서버 OCR';
            renderTextSections(fallbackServerSections, `${engineLabel} 텍스트를 모바일용으로 정리했습니다.`, false);
          } else {
            const normalizedServerSections = normalizeServerSections(serverOcrSections);
            if (normalizedServerSections.length) {
              const engineLabel = serverOcrEngine ? `서버 ${serverOcrEngine}` : '서버 OCR';
              renderTextSections(normalizedServerSections, `${engineLabel} 결과를 모바일용으로 정리했습니다.`, false);
            }
          }
        } else {
          try {
            const extractedSections = await extractTextSections(pdfDoc);
            if (extractedSections.length) {
              renderTextSections(extractedSections, '브라우저에서 PDF 텍스트를 자동 추출해 모바일용으로 정리했습니다.', false);
            } else {
              renderTextSections([], serverOcrError || '자동 추출된 텍스트가 없어 원본 PDF 보기가 필요합니다.', true);
              stage.style.display = 'block';
            }
          } catch (textError) {
            renderTextSections([], serverOcrError || '자동 텍스트 추출에 실패했습니다. 원본 PDF 보기로 확인해 주세요.', true);
            stage.style.display = 'block';
            console.error(textError);
          }
        }
      }
      rebuildPageOptions(pdfDoc.numPages);
      updateControls();
      renderPage(1);
    } catch (error) {
      setLoadingState(false);
      pageIndicator.textContent = '문서를 불러오지 못했습니다.';
      stage.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;min-height:52vh;color:#9fb4c8;text-align:center;line-height:1.7;padding:18px;">모바일 읽기 화면을 준비하지 못했습니다.<br>상단의 원본보기 버튼으로 PDF를 바로 열 수 있습니다.</div>';
      console.error(error);
    }
  })();
</script>
