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
  const canEditMobileMsds = <?= $canEditMobileMsds ? 'true' : 'false' ?>;
  const recordId = <?= json_encode((string)($record['id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const isMobileViewport = () => window.matchMedia('(max-width: 640px)').matches;

  let pdfDoc = null;
  let pageNum = 1;
  let zoomFactor = 1;
  let rendering = false;
  let pendingPage = null;
  let editingArticle = null;

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
      mobileSectionJump.style.display = isMobileViewport() && articles.length > 0 ? 'block' : 'none';
    }
  }

  function updateMobileScrollTopVisibility() {
    if (!mobileScrollTopButton) {
      return;
    }

    const shouldShow = isMobileViewport() && window.scrollY > 320;
    mobileScrollTopButton.classList.toggle('is-visible', shouldShow);
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

    mobileTextBody.innerHTML = sections.map((section) => {
      const originalTitle = normalizeLineText(section.title || '본문');
      const titleInfo = parseSectionTitle(originalTitle);
      const sectionClass = titleInfo.index ? 'mobile-text-section has-index' : 'mobile-text-section';
      const blocksHtml = renderSectionBlocks(section);
      return `<article class="${sectionClass} is-editable"><h3>${escapeHtml(originalTitle || '본문')}</h3>${blocksHtml}</article>`;
    }).join('');
    decorateEditableCards();
    refreshMobileSectionJump();
  }

  function formatManualBodyLine(line) {
    const normalized = normalizeLineText(line);
    const escaped = escapeHtml(normalized);

    if (normalized === '그림문자' || normalized === '1) 그림문자') {
      const labelText = normalized === '그림문자' ? '1) 그림문자' : normalized;
      return `<div class="mobile-text-fallback-detail">${escapeHtml(labelText)}</div>${renderMobilePictogramCard(manualMobileContent, true, '')}`;
    }

    if (/^[가-하]\.\s*/.test(normalized)) {
      return `<div class="mobile-text-fallback-subhead">${escaped}</div>`;
    }

    const kvMatch = normalized.match(/^(.{1,80}?[:：])\s*(.+)$/);
    if (kvMatch) {
      const label = escapeHtml(normalizeLineText(kvMatch[1]));
      const value = escapeHtml(normalizeLineText(kvMatch[2]));
      const detailClass = /^\d+\)\s*/.test(normalized) ? ' mobile-text-fallback-kv-detail' : '';
      return `<div class="mobile-text-fallback-kv${detailClass}"><span class="mobile-text-fallback-kv-label">${label}</span><span class="mobile-text-fallback-kv-value">${value}</span></div>`;
    }

    if (/^\d+\)\s*/.test(normalized)) {
      return `<div class="mobile-text-fallback-detail">${escaped}</div>`;
    }

    return `<p class="mobile-text-paragraph mobile-text-fallback-body">${escaped}</p>`;
  }

  function renderSavedManualContent(statusText = '관리자가 정리한 모바일 전용 본문입니다.') {
    const manualSections = splitManualSections(manualMobileContent);
    if (!manualSections.length) {
      return false;
    }

    if (mobileTextStatus) {
      mobileTextStatus.textContent = statusText;
      mobileTextStatus.classList.remove('is-error');
    }

    mobileTextBody.innerHTML = manualSections.map((section) => {
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
      return `<article class="mobile-text-section is-editable">${titleHtml}${bodyHtml || '<div class="mobile-text-empty">표시할 본문이 없습니다.</div>'}</article>`;
    }).join('');

    decorateEditableCards();
    refreshMobileSectionJump();
    return true;
  }

  function decorateEditableCards() {
    if (!mobileTextBody || !canEditMobileMsds) {
      return;
    }

    mobileTextBody.querySelectorAll('.mobile-text-section').forEach((article) => {
      article.classList.add('is-editable');
      if (!article.hasAttribute('tabindex')) {
        article.setAttribute('tabindex', '0');
      }
      article.setAttribute('role', 'button');
      article.setAttribute('aria-label', '본문 편집 열기');

      if (!article.querySelector('.mobile-card-edit')) {
        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'mobile-card-edit';
        editButton.textContent = '수정';
        editButton.setAttribute('aria-label', '이 카드 수정');
        editButton.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          openEditor(article);
        });
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
        label: '불꽃',
        svg: `
          <svg class="mobile-pictogram-svg" viewBox="0 0 88 88" aria-hidden="true" focusable="false">
            <rect x="16" y="16" width="56" height="56" transform="rotate(45 44 44)" fill="#ffffff" stroke="#e6331f" stroke-width="5.5"/>
            <path d="M45.6 27.4c3.9 5 5.2 9 4.1 12.2 2.5-1.1 4.5-3.4 5.2-6.2 5.3 4.9 8.4 10.3 8.4 16.1 0 8.5-7.2 14.4-16.5 14.4-9.7 0-16.3-6.5-16.3-15.3 0-6.1 3.2-11.5 9-16 0 3.1 1.1 5.8 3.1 8-0.2-4.8 0.6-9.3 3-13.2z" fill="#211a18"/>
          </svg>
        `.trim(),
      },
      'gas-cylinder': {
        label: '가스실린더',
        svg: `
          <svg class="mobile-pictogram-svg" viewBox="0 0 88 88" aria-hidden="true" focusable="false">
            <rect x="16" y="16" width="56" height="56" transform="rotate(45 44 44)" fill="#ffffff" stroke="#e6331f" stroke-width="5.5"/>
            <g transform="rotate(-14 44 44)">
              <rect x="23" y="38" width="33" height="10" rx="3.6" fill="#211a18"/>
              <rect x="54.8" y="40.4" width="11.6" height="4.5" rx="1.8" fill="#211a18"/>
            </g>
          </svg>
        `.trim(),
      },
      exclamation: {
        label: '느낌표',
        svg: `
          <svg class="mobile-pictogram-svg" viewBox="0 0 88 88" aria-hidden="true" focusable="false">
            <rect x="16" y="16" width="56" height="56" transform="rotate(45 44 44)" fill="#ffffff" stroke="#e6331f" stroke-width="5.5"/>
            <rect x="40.2" y="27" width="7.6" height="23" rx="3.8" fill="#211a18"/>
            <circle cx="44" cy="57.2" r="4.6" fill="#211a18"/>
          </svg>
        `.trim(),
      },
      'health-hazard': {
        label: '건강유해성',
        svg: `
          <svg class="mobile-pictogram-svg" viewBox="0 0 88 88" aria-hidden="true" focusable="false">
            <rect x="16" y="16" width="56" height="56" transform="rotate(45 44 44)" fill="#ffffff" stroke="#e6331f" stroke-width="5.5"/>
            <circle cx="44" cy="31" r="6.2" fill="#211a18"/>
            <path d="M33 57.5c1.4-8.6 5.1-13.8 11-13.8 6 0 9.6 5.2 11 13.8h-6.9c-0.8-3.8-2.1-5.9-4.1-5.9-2 0-3.3 2.1-4.1 5.9H33z" fill="#211a18"/>
            <path d="M44 44.7l2.2 3.8 4.4 0.9-3 3.2 0.4 4.4-4-2-4 2 0.4-4.4-3-3.2 4.4-0.9z" fill="#ffffff"/>
          </svg>
        `.trim(),
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

      return `<div class="mobile-pictogram-item">${definition.svg}<div class="mobile-pictogram-label">${escapeHtml(definition.label)}</div></div>`;
    }).join('');

    const cardClass = isInline ? 'mobile-pictogram-card is-inline' : 'mobile-pictogram-card';
    const titleHtml = titleText ? `<div class="mobile-pictogram-title">${escapeHtml(titleText)}</div>` : '';
    return `<div class="${cardClass}">${titleHtml}<div class="mobile-pictogram-list">${itemsHtml}</div></div>`;
  }

  function isPictogramLabelLine(line) {
    return ['가스실린더'].includes(normalizeLineText(line));
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

    return `<div class="mobile-text-table">${rows.map((row) => `<div class="mobile-text-table-row"><div class="mobile-text-table-label">${escapeHtml(row.label)}</div><div class="mobile-text-table-value">${escapeHtml(row.value)}</div></div>`).join('')}</div>`;
  }

  function renderParagraphLines(lines) {
    return lines.map((line) => `<p class="mobile-text-paragraph">${escapeHtml(line)}</p>`).join('');
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

  let resizeTimer = null;
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      queueRender(pageNum);
      refreshMobileSectionJump();
      updateMobileScrollTopVisibility();
    }, 180);
  });
  window.addEventListener('scroll', updateMobileScrollTopVisibility, { passive: true });

  updateControls();
  decorateEditableCards();
  refreshMobileSectionJump();
  updateMobileScrollTopVisibility();

  if (mobileTextBody) {
    mobileTextBody.addEventListener('click', (event) => {
      if (!canEditMobileMsds) {
        return;
      }

      const article = event.target instanceof Element ? event.target.closest('.mobile-text-section') : null;
      if (article) {
        openEditor(article);
      }
    });

    mobileTextBody.addEventListener('keydown', (event) => {
      if (!canEditMobileMsds) {
        return;
      }

      if (!(event.target instanceof Element) || !event.target.classList.contains('mobile-text-section')) {
        return;
      }

      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openEditor(event.target);
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
        if (renderSavedManualContent()) {
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
