// // 8. 미리보기 / 라이브 프리뷰 — enableRichEditor, renderTokenizedContent, openPreviewModal, updateLivePreview, insertTokenAtCursor
(function(window) {
    window.BOARD_createPreview = function(app) {
        app.enableRichEditor = () => {
            if (!app.contentEditor || !app.contentTextarea) return;
            app.contentEditor.hidden = false;
            app.contentEditor.dataset.placeholder = '내용을 입력해 주세요';
            app.contentTextarea.style.display = 'none';
            app.contentTextarea.required = false;
            app.ensureTextToolbar();
            app.ensureImageToolbar();
            app.ensureImageEditorModal();
            app.renderEditorFromText(app.contentTextarea.value || '');
            app.syncTextareaFromEditor();
        };

        app.renderTokenizedContent = (rawText, targetElement, objectUrlStore, emptyText) => {
            if (!targetElement) return;

            app.clearObjectUrls(objectUrlStore);
            targetElement.innerHTML = '';

            const existingImages = app.getExistingImageAttachments();
            const selectedFiles = app.getSelectedImageFileByName();
            const raw = String(rawText || '');

            // If this is richtext content, strip prefix and parse as HTML with token substitution
            if (raw.startsWith(app.RICHTEXT_PREFIX)) {
                const htmlContent = raw.slice(app.RICHTEXT_PREFIX.length);
                const embedTokens = [];
                const processedHTML = htmlContent.replace(/\[\[첨부:([^\][\]]+)\]\]/g, (_, tokenValue) => {
                    const idx = embedTokens.length;
                    embedTokens.push(tokenValue);
                    return `<span data-preview-embed-idx="${idx}"></span>`;
                });
                const temp = document.createElement('div');
                temp.innerHTML = processedHTML;
                // Replace placeholder spans with preview figures
                temp.querySelectorAll('[data-preview-embed-idx]').forEach((placeholder) => {
                    const tokenValue = embedTokens[parseInt(placeholder.dataset.previewEmbedIdx, 10)];
                    const parsed = app.parseAttachmentTokenValue(tokenValue);
                    const imageInfo = app.resolveImageFromToken(
                        parsed.target, existingImages, selectedFiles, objectUrlStore);
                    if (imageInfo) {
                        const figure = document.createElement('figure');
                        figure.className = 'write-preview-image-wrap';
                        figure.classList.add(`align-${parsed.options.align}`,
                            `size-${parsed.options.size}`);
                        const img = document.createElement('img');
                        img.src = imageInfo.src;
                        img.alt = imageInfo.name || '첨부 이미지';
                        app.applyImageTransformToElement(img, parsed.options);
                        figure.appendChild(img);
                        const caption = document.createElement('figcaption');
                        caption.textContent = imageInfo.name || '';
                        figure.appendChild(caption);
                        placeholder.replaceWith(figure);
                    } else {
                        placeholder.replaceWith(document.createTextNode(`[[첨부:${tokenValue}]]`));
                    }
                });
                targetElement.appendChild(temp);
            } else {
                const tokenRegex = /\[\[첨부:([^[\]]+)\]\]/g;
                let lastIndex = 0;
                let match = null;

                while ((match = tokenRegex.exec(raw)) !== null) {
                    const plainText = raw.slice(lastIndex, match.index);
                    if (plainText) targetElement.appendChild(document.createTextNode(plainText));

                    const parsed = app.parseAttachmentTokenValue(match[1]);
                    const imageInfo = app.resolveImageFromToken(
                        parsed.target, existingImages, selectedFiles, objectUrlStore);
                    if (!imageInfo) {
                        targetElement.appendChild(document.createTextNode(match[0]));
                    } else {
                        const figure = document.createElement('figure');
                        figure.className = 'write-preview-image-wrap';
                        figure.classList.add(`align-${parsed.options.align}`,
                            `size-${parsed.options.size}`);
                        const img = document.createElement('img');
                        img.src = imageInfo.src;
                        img.alt = imageInfo.name || '첨부 이미지';
                        app.applyImageTransformToElement(img, parsed.options);
                        figure.appendChild(img);
                        const caption = document.createElement('figcaption');
                        caption.textContent = imageInfo.name || '';
                        figure.appendChild(caption);
                        targetElement.appendChild(figure);
                    }
                    lastIndex = tokenRegex.lastIndex;
                }

                const tail = raw.slice(lastIndex);
                if (tail) targetElement.appendChild(document.createTextNode(tail));
            }

            if (targetElement.childNodes.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'live-preview-empty';
                empty.textContent = emptyText;
                targetElement.appendChild(empty);
            }
        };

        app.closePreviewModal = () => {
            if (!app.previewModal) return;
            app.previewModal.hidden = true;
            document.body.style.overflow = '';
            app.clearObjectUrls(app.modalPreviewObjectUrls);
        };

        app.openPreviewModal = () => {
            if (!app.previewModal || !app.previewTitle || !app.previewContent) return;

            app.syncTextareaFromEditor();
            app.previewTitle.textContent = (app.titleInput?.value || '').trim() || '(제목 없음)';
            app.renderTokenizedContent(
                app.contentTextarea?.value || '',
                app.previewContent,
                app.modalPreviewObjectUrls,
                '(내용 없음)'
            );

            app.previewModal.hidden = false;
            document.body.style.overflow = 'hidden';
        };

        app.updateLivePreview = () => {
            if (!app.livePreviewBody) return;
            app.renderTokenizedContent(
                app.contentTextarea?.value || '',
                app.livePreviewBody,
                app.livePreviewObjectUrls,
                '내용과 첨부 이미지 토큰이 여기에 표시됩니다.'
            );
        };

        app.insertTokenAtCursor = (token) => {
            if (app.contentEditor && !app.contentEditor.hidden) {
                const tokenMatch = String(token || '').match(/^\[\[\s*첨부\s*:\s*(.+?)\s*\]\]$/);
                const existingImages = app.getExistingImageAttachments();
                const selectedFiles = app.getSelectedImageFileByName();
                const parsed = app.parseAttachmentTokenValue(tokenMatch ? tokenMatch[1] : '');
                const imageInfo = app.resolveImageFromToken(
                    parsed.target, existingImages, selectedFiles, app.editorObjectUrls);

                if (imageInfo && tokenMatch) {
                    const embed = app.createEditorEmbed(parsed.target, imageInfo, parsed.options);
                    app.insertNodeIntoEditor(embed);
                } else {
                    const textNode = document.createTextNode(String(token || ''));
                    app.insertNodeIntoEditor(textNode);
                }
                return;
            }

            if (!app.contentTextarea) return;
            const value = app.contentTextarea.value || '';
            const start = app.contentTextarea.selectionStart ?? value.length;
            const end = app.contentTextarea.selectionEnd ?? start;
            const before = value.slice(0, start);
            const after = value.slice(end);
            const prefix = before && !before.endsWith('\n') ? '\n' : '';
            const suffix = after && !after.startsWith('\n') ? '\n' : '';
            const inserted = `${prefix}${token}${suffix}`;
            app.contentTextarea.value = before + inserted + after;
            const caret = (before + inserted).length;
            app.contentTextarea.focus();
            app.contentTextarea.setSelectionRange(caret, caret);
            app.updateLivePreview();
        };
    };
})(window);
