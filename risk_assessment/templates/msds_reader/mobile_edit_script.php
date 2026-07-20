<script>
  (function () {
    if (window.__msdsReaderManaged) {
      return;
    }

    var canEditMobileMsds = <?= $canEditMobileMsds ? 'true' : 'false' ?>;
    var recordId = <?= json_encode((string)($record['id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var glossaryEntries = <?= json_encode($mobileGlossary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var mobileTextBody = document.getElementById('mobile-text-body');
    var mobileEditorModal = document.getElementById('mobile-editor-modal');
    var mobileEditorTextarea = document.getElementById('mobile-editor-textarea');
    var mobileEditorCancel = document.getElementById('mobile-editor-cancel');
    var mobileEditorApply = document.getElementById('mobile-editor-apply');
    var mobileTextStatus = document.getElementById('mobile-text-status');
    var mobileGlossaryModal = document.getElementById('mobile-glossary-modal');
    var mobileGlossaryTitle = document.getElementById('mobile-glossary-title');
    var mobileGlossaryContent = document.getElementById('mobile-glossary-content');
    var mobileGlossaryClose = document.getElementById('mobile-glossary-close');
    var mobileGlossaryManageButton = document.getElementById('mobile-glossary-manage-button');
    var mobileGlossaryEditorModal = document.getElementById('mobile-glossary-editor-modal');
    var mobileGlossaryEditorList = document.getElementById('mobile-glossary-editor-list');
    var mobileGlossaryEditorCancel = document.getElementById('mobile-glossary-editor-cancel');
    var mobileGlossaryEditorSave = document.getElementById('mobile-glossary-editor-save');
    var mobileGlossaryAdd = document.getElementById('mobile-glossary-add');
    var editingArticle = null;
    var glossaryObserver = null;

    function isMobileViewportLegacy() {
      return window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
    }

    function normalizeLineLegacy(value) {
      return String(value || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\u0000/g, '').trim();
    }

    function escapeHtmlLegacy(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function escapeRegexLegacy(value) {
      return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function buildGlossaryPattern(term) {
      return escapeRegexLegacy(String(term || ''))
        .replace(/\s+/g, '\\s+')
        .replace(/[:：]/g, '\\s*[:：]\\s*');
    }

    function findMatchingGlossaryEntry(text) {
      var entries = sortedGlossaryEntries();
      for (var i = 0; i < entries.length; i += 1) {
        if (new RegExp(buildGlossaryPattern(entries[i].term || ''), 'i').test(String(text || ''))) {
          return {
            entry: entries[i],
            entryIndex: i,
            labelText: String(text || '')
          };
        }
      }

      return null;
    }

    function sortedGlossaryEntries() {
      return glossaryEntries
        .filter(function (item) {
          return item && normalizeLineLegacy(item.term) && normalizeLineLegacy(item.content);
        })
        .slice()
        .sort(function (left, right) {
          return String(right.term || '').length - String(left.term || '').length;
        });
    }

    function collectArticleText(article) {
      if (!article) {
        return '';
      }

      var parts = [];
      var children = article.children;
      for (var i = 0; i < children.length; i += 1) {
        var child = children[i];
        if (!child || child.classList.contains('mobile-glossary-trigger')) {
          continue;
        }
        if (child.tagName === 'H3') {
          continue;
        }

        var text = normalizeLineLegacy(child.innerText || child.textContent || '');
        if (text) {
          parts.push(text);
        }
      }

      return parts.join('\n');
    }

    function openLegacyEditor(article) {
      if (!canEditMobileMsds || !article || !mobileEditorModal || !mobileEditorTextarea) {
        return;
      }

      editingArticle = article;
      mobileEditorTextarea.value = collectArticleText(article);
      mobileEditorModal.className = 'mobile-editor-modal is-open';
      mobileEditorModal.setAttribute('aria-hidden', 'false');
      window.setTimeout(function () {
        try {
          mobileEditorTextarea.focus();
        } catch (error) {}
      }, 20);
    }

    function closeLegacyEditor() {
      editingArticle = null;
      if (!mobileEditorModal) {
        return;
      }
      mobileEditorModal.className = 'mobile-editor-modal';
      mobileEditorModal.setAttribute('aria-hidden', 'true');
    }

    function saveLegacyEditor() {
      if (!canEditMobileMsds || !editingArticle || !mobileEditorTextarea) {
        closeLegacyEditor();
        return;
      }

      var request = new XMLHttpRequest();
      var payload = 'action=save_mobile_content'
        + '&record_id=' + encodeURIComponent(recordId)
        + '&content=' + encodeURIComponent(mobileEditorTextarea.value || '');

      request.open('POST', window.location.href, true);
      request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      if (mobileEditorApply) {
        mobileEditorApply.disabled = true;
        mobileEditorApply.textContent = '저장 중...';
      }

      request.onreadystatechange = function () {
        if (request.readyState !== 4) {
          return;
        }

        if (mobileEditorApply) {
          mobileEditorApply.disabled = false;
          mobileEditorApply.textContent = '적용';
        }

        if (request.status >= 200 && request.status < 300) {
          if (mobileTextStatus) {
            mobileTextStatus.textContent = '편집 내용을 저장했습니다.';
            mobileTextStatus.classList.remove('is-error');
          }
          closeLegacyEditor();
          window.location.reload();
          return;
        }

        if (mobileTextStatus) {
          mobileTextStatus.textContent = '편집 내용을 저장하지 못했습니다.';
          mobileTextStatus.classList.add('is-error');
        }
        window.alert('편집 내용을 저장하지 못했습니다.');
      };

      request.send(payload);
    }

    function openGlossaryModal(entry) {
      if (!entry || !mobileGlossaryModal || !mobileGlossaryTitle || !mobileGlossaryContent) {
        return;
      }

      mobileGlossaryTitle.textContent = entry.title || entry.term || '용어 설명';
      mobileGlossaryContent.textContent = entry.content || '';
      mobileGlossaryModal.className = 'mobile-glossary-modal is-open';
      mobileGlossaryModal.setAttribute('aria-hidden', 'false');
    }

    function closeGlossaryModal() {
      if (!mobileGlossaryModal) {
        return;
      }

      mobileGlossaryModal.className = 'mobile-glossary-modal';
      mobileGlossaryModal.setAttribute('aria-hidden', 'true');
    }

    function renderGlossaryEditorRows() {
      if (!mobileGlossaryEditorList) {
        return;
      }

      if (!glossaryEntries.length) {
        glossaryEntries.push({ term: '', title: '', content: '' });
      }

      mobileGlossaryEditorList.innerHTML = glossaryEntries.map(function (entry, index) {
        return ''
          + '<div class="mobile-glossary-row" data-index="' + index + '">'
          + '<div class="mobile-glossary-field">'
          + '<label>표시할 용어 또는 문장</label>'
          + '<input class="mobile-glossary-input" data-field="term" type="text" value="' + escapeHtmlLegacy(entry.term || '') + '" placeholder="예: 급성 독성">'
          + '</div>'
          + '<div class="mobile-glossary-field">'
          + '<label>모달 제목</label>'
          + '<input class="mobile-glossary-input" data-field="title" type="text" value="' + escapeHtmlLegacy(entry.title || '') + '" placeholder="비우면 용어와 같게 표시됩니다.">'
          + '</div>'
          + '<div class="mobile-glossary-field">'
          + '<label>설명 내용</label>'
          + '<textarea class="mobile-glossary-textarea" data-field="content" placeholder="작업자가 눌렀을 때 볼 설명을 입력하세요.">' + escapeHtmlLegacy(entry.content || '') + '</textarea>'
          + '</div>'
          + '<button class="mobile-glossary-remove" type="button" data-remove-index="' + index + '">삭제</button>'
          + '</div>';
      }).join('');
    }

    function openGlossaryEditor() {
      if (!canEditMobileMsds || !mobileGlossaryEditorModal) {
        return;
      }

      renderGlossaryEditorRows();
      mobileGlossaryEditorModal.className = 'mobile-glossary-editor-modal is-open';
      mobileGlossaryEditorModal.setAttribute('aria-hidden', 'false');
    }

    function closeGlossaryEditor() {
      if (!mobileGlossaryEditorModal) {
        return;
      }

      mobileGlossaryEditorModal.className = 'mobile-glossary-editor-modal';
      mobileGlossaryEditorModal.setAttribute('aria-hidden', 'true');
    }

    function collectGlossaryEditorRows() {
      if (!mobileGlossaryEditorList) {
        return [];
      }

      var rows = mobileGlossaryEditorList.querySelectorAll('.mobile-glossary-row');
      var nextEntries = [];

      for (var i = 0; i < rows.length; i += 1) {
        var row = rows[i];
        var termInput = row.querySelector('[data-field="term"]');
        var titleInput = row.querySelector('[data-field="title"]');
        var contentInput = row.querySelector('[data-field="content"]');
        var term = normalizeLineLegacy(termInput ? termInput.value : '');
        var title = normalizeLineLegacy(titleInput ? titleInput.value : '');
        var content = normalizeLineLegacy(contentInput ? contentInput.value : '');

        if (!term || !content) {
          continue;
        }

        nextEntries.push({
          term: term,
          title: title || term,
          content: content
        });
      }

      return nextEntries;
    }

    function saveGlossaryEditor() {
      var nextEntries = collectGlossaryEditorRows();
      var request = new XMLHttpRequest();
      var payload = 'action=save_mobile_glossary'
        + '&record_id=' + encodeURIComponent(recordId)
        + '&glossary=' + encodeURIComponent(JSON.stringify(nextEntries));

      request.open('POST', window.location.href, true);
      request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      if (mobileGlossaryEditorSave) {
        mobileGlossaryEditorSave.disabled = true;
        mobileGlossaryEditorSave.textContent = '저장 중...';
      }

      request.onreadystatechange = function () {
        if (request.readyState !== 4) {
          return;
        }

        if (mobileGlossaryEditorSave) {
          mobileGlossaryEditorSave.disabled = false;
          mobileGlossaryEditorSave.textContent = '저장';
        }

        if (request.status >= 200 && request.status < 300) {
          var responseData = null;
          try {
            responseData = JSON.parse(request.responseText || '{}');
          } catch (error) {
            responseData = null;
          }

          glossaryEntries = responseData && responseData.glossary ? responseData.glossary : nextEntries;
          decorateGlossaryTerms();

          if (mobileTextStatus) {
            mobileTextStatus.textContent = '용어 설명을 저장했습니다.';
            mobileTextStatus.classList.remove('is-error');
          }

          closeGlossaryEditor();
          return;
        }

        if (mobileTextStatus) {
          mobileTextStatus.textContent = '용어 설명을 저장하지 못했습니다.';
          mobileTextStatus.classList.add('is-error');
        }
        window.alert('용어 설명을 저장하지 못했습니다.');
      };

      request.send(payload);
    }

    function createGlossaryButton(entry, entryIndex, labelText) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'mobile-glossary-trigger';
      button.setAttribute('data-glossary-index', String(entryIndex));
      button.textContent = labelText || entry.term;
      return button;
    }

    function replaceTextNodeWithGlossary(node, entries) {
      var text = String(node.nodeValue || '');
      if (!normalizeLineLegacy(text)) {
        return;
      }

      var fragment = document.createDocumentFragment();
      var cursor = 0;
      var lowerText = text.toLowerCase();

      while (cursor < text.length) {
        var matchedEntry = null;
        var matchedIndex = -1;

        var matchedLength = 0;

        for (var i = 0; i < entries.length; i += 1) {
          var entry = entries[i];
          var term = String(entry.term || '');
          var slicedText = text.slice(cursor);
          var match = slicedText.match(new RegExp(buildGlossaryPattern(term), 'i'));
          if (!match || typeof match.index !== 'number') {
            continue;
          }

          var index = cursor + match.index;
          var length = String(match[0] || '').length;
          if (matchedIndex === -1 || index < matchedIndex || (index === matchedIndex && length > matchedLength)) {
            matchedIndex = index;
            matchedLength = length;
            matchedEntry = {
              entry: entry,
              labelText: String(match[0] || term),
              entryIndex: i
            };
          }
        }

        if (!matchedEntry || matchedIndex === -1) {
          fragment.appendChild(document.createTextNode(text.slice(cursor)));
          break;
        }

        if (matchedIndex > cursor) {
          fragment.appendChild(document.createTextNode(text.slice(cursor, matchedIndex)));
        }

        fragment.appendChild(createGlossaryButton(matchedEntry.entry, matchedEntry.entryIndex, matchedEntry.labelText));
        cursor = matchedIndex + matchedLength;
      }

      if (fragment.childNodes.length) {
        node.parentNode.replaceChild(fragment, node);
      }
    }

    function decorateGlossaryKeyValueRows() {
      if (!mobileTextBody) {
        return;
      }

      var rows = mobileTextBody.querySelectorAll('.mobile-text-fallback-kv');
      for (var i = 0; i < rows.length; i += 1) {
        var row = rows[i];
        if (!row || row.querySelector('.mobile-glossary-trigger')) {
          continue;
        }

        var rowText = normalizeLineLegacy(row.textContent || '');
        if (!rowText) {
          continue;
        }

        var matched = findMatchingGlossaryEntry(rowText);
        if (!matched) {
          continue;
        }

        row.innerHTML = '';
        row.appendChild(createGlossaryButton(matched.entry, matched.entryIndex, matched.labelText));
        row.classList.add('has-glossary-trigger');
      }
    }

    function decorateGlossaryTerms() {
      if (!mobileTextBody) {
        return;
      }

      var entries = sortedGlossaryEntries();

      if (glossaryObserver) {
        glossaryObserver.disconnect();
      }

      var oldButtons = mobileTextBody.querySelectorAll('.mobile-glossary-trigger');
      for (var b = 0; b < oldButtons.length; b += 1) {
        var oldButton = oldButtons[b];
        if (oldButton.parentNode) {
          oldButton.parentNode.replaceChild(document.createTextNode(oldButton.textContent || ''), oldButton);
        }
      }

      mobileTextBody.normalize();

      if (!entries.length) {
        return;
      }

      decorateGlossaryKeyValueRows();

      var walker = document.createTreeWalker(mobileTextBody, NodeFilter.SHOW_TEXT, {
        acceptNode: function (node) {
          if (!node || !node.parentNode) {
            return NodeFilter.FILTER_REJECT;
          }

          var parent = node.parentNode;
          var parentTag = parent.tagName ? parent.tagName.toUpperCase() : '';

          if (parent.closest && parent.closest('.mobile-pictogram-card, .mobile-glossary-trigger')) {
            return NodeFilter.FILTER_REJECT;
          }

          if (['BUTTON', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION', 'SCRIPT', 'STYLE'].indexOf(parentTag) !== -1) {
            return NodeFilter.FILTER_REJECT;
          }

          if (!normalizeLineLegacy(node.nodeValue || '')) {
            return NodeFilter.FILTER_REJECT;
          }

          var content = String(node.nodeValue || '').toLowerCase();
          for (var i = 0; i < entries.length; i += 1) {
            if (new RegExp(buildGlossaryPattern(entries[i].term || ''), 'i').test(String(node.nodeValue || ''))) {
              return NodeFilter.FILTER_ACCEPT;
            }
          }

          return NodeFilter.FILTER_REJECT;
        }
      });

      var textNodes = [];
      var currentNode = walker.nextNode();
      while (currentNode) {
        textNodes.push(currentNode);
        currentNode = walker.nextNode();
      }

      for (var i = 0; i < textNodes.length; i += 1) {
        replaceTextNodeWithGlossary(textNodes[i], entries);
      }

      glossaryObserver = new MutationObserver(function () {
        if (glossaryObserver) {
          glossaryObserver.disconnect();
        }
        window.requestAnimationFrame(function () {
          decorateGlossaryTerms();
        });
      });

      glossaryObserver.observe(mobileTextBody, { childList: true, subtree: true });
    }

    if (!mobileTextBody || !isMobileViewportLegacy()) {
      return;
    }

    decorateGlossaryTerms();

    mobileTextBody.addEventListener('click', function (event) {
      var target = event.target || event.srcElement;
      if (target && target.classList && target.classList.contains('mobile-glossary-trigger')) {
        event.preventDefault();
        event.stopPropagation();

        var glossaryIndex = Number(target.getAttribute('data-glossary-index'));
        if (!isNaN(glossaryIndex) && glossaryEntries[glossaryIndex]) {
          openGlossaryModal(glossaryEntries[glossaryIndex]);
        }
        return;
      }

      var article = target && target.closest ? target.closest('.mobile-text-section') : null;
      if (!article || !canEditMobileMsds) {
        return;
      }

      openLegacyEditor(article);
    });

    if (mobileGlossaryClose) {
      mobileGlossaryClose.addEventListener('click', closeGlossaryModal);
    }
    if (mobileGlossaryModal) {
      mobileGlossaryModal.addEventListener('click', function (event) {
        if (event.target === mobileGlossaryModal) {
          closeGlossaryModal();
        }
      });
    }
    if (mobileGlossaryManageButton) {
      mobileGlossaryManageButton.addEventListener('click', openGlossaryEditor);
    }
    if (mobileGlossaryAdd) {
      mobileGlossaryAdd.addEventListener('click', function () {
        glossaryEntries.push({ term: '', title: '', content: '' });
        renderGlossaryEditorRows();
      });
    }
    if (mobileGlossaryEditorList) {
      mobileGlossaryEditorList.addEventListener('click', function (event) {
        var target = event.target || event.srcElement;
        var removeIndex = target && target.getAttribute ? target.getAttribute('data-remove-index') : '';
        if (removeIndex === null || removeIndex === '') {
          return;
        }

        glossaryEntries.splice(Number(removeIndex), 1);
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
      mobileGlossaryEditorModal.addEventListener('click', function (event) {
        if (event.target === mobileGlossaryEditorModal) {
          closeGlossaryEditor();
        }
      });
    }

    if (mobileEditorCancel) {
      mobileEditorCancel.addEventListener('click', closeLegacyEditor);
    }
    if (mobileEditorApply) {
      mobileEditorApply.addEventListener('click', saveLegacyEditor);
    }
    if (mobileEditorModal) {
      mobileEditorModal.addEventListener('click', function (event) {
        if (event.target === mobileEditorModal) {
          closeLegacyEditor();
        }
      });
    }

    window.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && mobileGlossaryModal && mobileGlossaryModal.classList.contains('is-open')) {
        closeGlossaryModal();
      }
      if (event.key === 'Escape' && mobileGlossaryEditorModal && mobileGlossaryEditorModal.classList.contains('is-open')) {
        closeGlossaryEditor();
      }
      if (event.key === 'Escape' && mobileEditorModal && mobileEditorModal.classList.contains('is-open')) {
        closeLegacyEditor();
      }
    });
  })();
</script>
