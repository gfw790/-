<script>
  (function () {
    var canEditMobileMsds = <?= $canEditMobileMsds ? 'true' : 'false' ?>;
    var recordId = <?= json_encode((string)($record['id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var mobileTextBody = document.getElementById('mobile-text-body');
    var mobileEditorModal = document.getElementById('mobile-editor-modal');
    var mobileEditorTextarea = document.getElementById('mobile-editor-textarea');
    var mobileEditorCancel = document.getElementById('mobile-editor-cancel');
    var mobileEditorApply = document.getElementById('mobile-editor-apply');
    var mobileTextStatus = document.getElementById('mobile-text-status');
    var editingArticle = null;

    function isMobileViewportLegacy() {
      return window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
    }

    function normalizeLineLegacy(value) {
      return String(value || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\u0000/g, '').trim();
    }

    function collectArticleText(article) {
      if (!article) {
        return '';
      }

      var parts = [];
      var children = article.children;
      for (var i = 0; i < children.length; i += 1) {
        var child = children[i];
        if (!child || child.classList.contains('mobile-card-edit')) {
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

    if (!canEditMobileMsds || !mobileTextBody || !isMobileViewportLegacy()) {
      return;
    }

    mobileTextBody.addEventListener('click', function (event) {
      var target = event.target || event.srcElement;
      var article = target && target.closest ? target.closest('.mobile-text-section') : null;
      if (!article) {
        return;
      }

      if (target && target.classList && target.classList.contains('mobile-card-edit')) {
        event.preventDefault();
        event.stopPropagation();
      }

      openLegacyEditor(article);
    });

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
      if (event.key === 'Escape' && mobileEditorModal && mobileEditorModal.classList.contains('is-open')) {
        closeLegacyEditor();
      }
    });
  })();
</script>
