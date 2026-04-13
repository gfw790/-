// // 5. 에디터 렌더/직렬화 — normalizeEmbedOptions, renderEditorFromText, serializeEditorToText, insertNodeIntoEditor 등
(function(window) {
    window.BOARD_createEditor = function(app) {
        app.normalizeEmbedOptions = (opts = {}) => {
            const align = String(opts.align || app.defaultEmbedOptions.align).toLowerCase();
            const size = String(opts.size || app.defaultEmbedOptions.size).toLowerCase();
            let rotate = Number(opts.rotate);
            if (!Number.isFinite(rotate)) rotate = app.defaultEmbedOptions.rotate;
            rotate = ((Math.round(rotate / 90) * 90) % 360 + 360) % 360;
            const flipRaw = opts.flip;
            const flip = flipRaw === true || flipRaw === 1 || flipRaw === '1' ||
                String(flipRaw).toLowerCase() === 'true';
            return {
                align: ['left', 'center', 'right'].includes(align) ? align : app.defaultEmbedOptions.align,
                size: ['small', 'medium', 'large'].includes(size) ? size : app.defaultEmbedOptions.size,
                rotate,
                flip,
            };
        };

        app.parseAttachmentTokenValue = (tokenValue) => {
            const parts = String(tokenValue || '').split('|').map((x) => x.trim()).filter(Boolean);
            const target = parts.shift() || '';
            const options = app.normalizeEmbedOptions();
            parts.forEach((part) => {
                const m = part.match(/^(align|size|rotate|flip)\s*[:=]\s*(.+)$/i);
                if (!m) return;
                const key = m[1].toLowerCase();
                const value = m[2].trim().toLowerCase();
                if (key === 'align' && ['left', 'center', 'right'].includes(value)) {
                    options.align = value;
                }
                if (key === 'size' && ['small', 'medium', 'large'].includes(value)) {
                    options.size = value;
                }
                if (key === 'rotate') {
                    const deg = Number(value);
                    if (Number.isFinite(deg)) options.rotate = deg;
                }
                if (key === 'flip') {
                    options.flip = value === '1' || value === 'true' || value === 'yes';
                }
            });
            return { target, options: app.normalizeEmbedOptions(options) };
        };

        app.buildAttachmentTokenValue = (target, options) => {
            const normalized = app.normalizeEmbedOptions(options);
            return `${target}|align=${normalized.align}|size=${normalized.size}` +
                `|rotate=${normalized.rotate}|flip=${normalized.flip ? 1 : 0}`;
        };

        app.applyImageTransformToElement = (imgElement, options = {}) => {
            if (!imgElement) return;
            const normalized = app.normalizeEmbedOptions(options);
            const scaleX = normalized.flip ? -1 : 1;
            imgElement.style.transform = `scaleX(${scaleX}) rotate(${normalized.rotate}deg)`;
            imgElement.style.transformOrigin = 'center center';
        };

        app.cycleEmbedSize = (currentSize) => {
            if (currentSize === 'small') return 'medium';
            if (currentSize === 'medium') return 'large';
            return 'small';
        };

        app.appendTextWithBreaks = (targetElement, text) => {
            const chunks = String(text || '').split('\n');
            chunks.forEach((chunk, idx) => {
                if (chunk) targetElement.appendChild(document.createTextNode(chunk));
                if (idx < chunks.length - 1) targetElement.appendChild(document.createElement('br'));
            });
        };

        app.syncEditorEmptyState = () => {
            if (!app.contentEditor || app.contentEditor.hidden) return;
            const hasEmbed = !!app.contentEditor.querySelector('.editor-embed');
            const text = (app.contentEditor.textContent || '').replace(/\u00A0/g, ' ').trim();
            if (!hasEmbed && text === '') {
                app.contentEditor.classList.add('is-empty');
            } else {
                app.contentEditor.classList.remove('is-empty');
            }
        };

        app.applyEmbedVisualClass = (figure, options) => {
            if (!figure) return;
            const normalized = app.normalizeEmbedOptions(options);
            figure.classList.remove('align-left', 'align-center', 'align-right',
                'size-small', 'size-medium', 'size-large');
            figure.classList.add(`align-${normalized.align}`, `size-${normalized.size}`);
            figure.dataset.align = normalized.align;
            figure.dataset.size = normalized.size;
            figure.dataset.rotate = String(normalized.rotate);
            figure.dataset.flip = normalized.flip ? '1' : '0';
            const img = figure.querySelector('img');
            app.applyImageTransformToElement(img, normalized);
        };

        app.createEditorEmbed = (targetToken, imageInfo, options = {}) => {
            const figure = document.createElement('figure');
            figure.className = 'editor-embed';
            figure.contentEditable = 'false';
            figure.dataset.tokenBase = String(targetToken || '').trim();

            const img = document.createElement('img');
            img.src = imageInfo.src;
            img.alt = imageInfo.name || '첨부 이미지';
            figure.appendChild(img);

            const caption = document.createElement('figcaption');
            caption.textContent = imageInfo.name || '';
            figure.appendChild(caption);

            app.applyEmbedVisualClass(figure, options);
            return figure;
        };

        app.renderEditorFromPlainText = (raw) => {
            const existingImages = app.getExistingImageAttachments();
            const selectedFiles = app.getSelectedImageFileByName();
            const tokenRegex = /\[\[첨부:([^[\]]+)\]\]/g;
            let lastIndex = 0;
            let match = null;

            while ((match = tokenRegex.exec(raw)) !== null) {
                const plainText = raw.slice(lastIndex, match.index);
                if (plainText) app.appendTextWithBreaks(app.contentEditor, plainText);

                const parsed = app.parseAttachmentTokenValue(match[1]);
                const imageInfo = app.resolveImageFromToken(
                    parsed.target, existingImages, selectedFiles, app.editorObjectUrls);
                if (!imageInfo) {
                    app.appendTextWithBreaks(app.contentEditor, match[0]);
                } else {
                    const embed = app.createEditorEmbed(parsed.target, imageInfo, parsed.options);
                    app.contentEditor.appendChild(embed);
                    app.contentEditor.appendChild(document.createElement('br'));
                }
                lastIndex = tokenRegex.lastIndex;
            }

            const tail = raw.slice(lastIndex);
            if (tail) app.appendTextWithBreaks(app.contentEditor, tail);
        };

        app.renderEditorFromRichtext = (htmlContent) => {
            const existingImages = app.getExistingImageAttachments();
            const selectedFiles = app.getSelectedImageFileByName();

            const embedTokens = [];
            const processedHTML = htmlContent.replace(/\[\[첨부:([^\][\]]+)\]\]/g, (_, tokenValue) => {
                const idx = embedTokens.length;
                embedTokens.push(tokenValue);
                return `<span data-embed-idx="${idx}"></span>`;
            });

            const temp = document.createElement('div');
            temp.innerHTML = processedHTML;

            const walkAndAppend = (sourceNode, targetParent) => {
                sourceNode.childNodes.forEach((child) => {
                    if (child.nodeType === Node.TEXT_NODE) {
                        const text = child.nodeValue || '';
                        if (text) targetParent.appendChild(document.createTextNode(text));
                    } else if (child.nodeType === Node.ELEMENT_NODE) {
                        const el = child;
                        // Embed placeholder
                        if (el.tagName === 'SPAN' && el.dataset.embedIdx !== undefined) {
                            const tokenValue = embedTokens[parseInt(el.dataset.embedIdx, 10)];
                            const parsed = app.parseAttachmentTokenValue(tokenValue);
                            const imageInfo = app.resolveImageFromToken(
                                parsed.target, existingImages, selectedFiles, app.editorObjectUrls);
                            if (imageInfo) {
                                targetParent.appendChild(
                                    app.createEditorEmbed(parsed.target, imageInfo, parsed.options));
                                targetParent.appendChild(document.createElement('br'));
                            } else {
                                targetParent.appendChild(
                                    document.createTextNode(`[[첨부:${tokenValue}]]`));
                            }
                            return;
                        }
                        // BR
                        if (el.tagName === 'BR') {
                            targetParent.appendChild(document.createElement('br'));
                            return;
                        }
                        // Inline format tags — clone with safe attributes and recurse
                        if (app.INLINE_FORMAT_TAGS.has(el.tagName)) {
                            const tag = el.tagName === 'STRONG' ? 'b' :
                                el.tagName === 'EM' ? 'i' :
                                el.tagName === 'STRIKE' ? 's' :
                                el.tagName.toLowerCase();
                            const clone = document.createElement(tag);
                            if (el.tagName === 'SPAN') {
                                if (el.style.color) clone.style.color = el.style.color;
                                if (el.style.fontSize) clone.style.fontSize = el.style.fontSize;
                            } else if (el.tagName === 'FONT' && el.size) {
                                clone.size = el.size;
                            }
                            walkAndAppend(el, clone);
                            targetParent.appendChild(clone);
                            return;
                        }
                        // Block/other elements — recurse into children, add br after if block
                        const before = targetParent.childNodes.length;
                        walkAndAppend(el, targetParent);
                        if (app.blockTags.has(el.tagName) && targetParent.childNodes.length > before) {
                            const last = targetParent.lastChild;
                            if (!last || last.tagName !== 'BR') {
                                targetParent.appendChild(document.createElement('br'));
                            }
                        }
                    }
                });
            };

            walkAndAppend(temp, app.contentEditor);
        };

        app.renderEditorFromText = (rawText) => {
            if (!app.contentEditor || !app.contentTextarea || app.contentEditor.hidden) return;
            app.hideImageToolbar();
            app.clearObjectUrls(app.editorObjectUrls);
            app.contentEditor.innerHTML = '';

            const raw = String(rawText || '');
            if (raw.startsWith(app.RICHTEXT_PREFIX)) {
                app.renderEditorFromRichtext(raw.slice(app.RICHTEXT_PREFIX.length));
            } else {
                app.renderEditorFromPlainText(raw);
            }

            if (app.contentEditor.childNodes.length === 0) {
                app.contentEditor.appendChild(document.createElement('br'));
            }
            app.syncEditorEmptyState();
        };

        app.serializeEditorNode = (node) => {
            if (!node) return '';
            if (node.nodeType === Node.TEXT_NODE) {
                return (node.nodeValue || '').replace(/\u00A0/g, ' ');
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return '';

            const el = node;
            if (el.classList?.contains('editor-embed')) {
                const tokenBase = String(el.dataset.tokenBase || '').trim();
                if (!tokenBase) return '';
                const tokenValue = app.buildAttachmentTokenValue(tokenBase, {
                    align: el.dataset.align,
                    size: el.dataset.size,
                    rotate: el.dataset.rotate,
                    flip: el.dataset.flip,
                });
                return `[[첨부:${tokenValue}]]`;
            }
            if (el.tagName === 'BR') return '\n';

            let out = '';
            el.childNodes.forEach((child) => {
                out += app.serializeEditorNode(child);
            });

            if (app.blockTags.has(el.tagName) && out !== '' && !out.endsWith('\n')) {
                out += '\n';
            }
            return out;
        };

        app.serializeEditorNodeHTML = (node) => {
            if (!node) return '';
            if (node.nodeType === Node.TEXT_NODE) {
                return app.escapeHtml((node.nodeValue || '').replace(/\u00A0/g, ' '));
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return '';

            const el = node;
            if (el.classList?.contains('editor-embed')) {
                const tokenBase = String(el.dataset.tokenBase || '').trim();
                if (!tokenBase) return '';
                const tokenValue = app.buildAttachmentTokenValue(tokenBase, {
                    align: el.dataset.align,
                    size: el.dataset.size,
                    rotate: el.dataset.rotate,
                    flip: el.dataset.flip,
                });
                return `[[첨부:${tokenValue}]]`;
            }
            if (el.tagName === 'BR') return '<br>';

            let inner = '';
            el.childNodes.forEach((child) => {
                inner += app.serializeEditorNodeHTML(child);
            });

            if (app.INLINE_FORMAT_TAGS.has(el.tagName)) {
                const tag = el.tagName === 'STRONG' ? 'b' :
                    el.tagName === 'EM' ? 'i' :
                    el.tagName === 'STRIKE' ? 's' :
                    el.tagName.toLowerCase();
                let attrs = '';
                if (el.tagName === 'SPAN') {
                    const parts = [];
                    if (el.style.color) parts.push(`color:${el.style.color}`);
                    if (el.style.fontSize) parts.push(`font-size:${el.style.fontSize}`);
                    if (parts.length) attrs = ` style="${parts.join(';')}"`;
                } else if (el.tagName === 'FONT') {
                    if (el.size) attrs = ` size="${app.escapeHtml(el.size)}"`;
                }
                return `<${tag}${attrs}>${inner}</${tag}>`;
            }

            if (app.blockTags.has(el.tagName) && inner !== '' && !inner.endsWith('<br>')) {
                inner += '<br>';
            }
            return inner;
        };

        app.serializeEditorToText = () => {
            if (!app.contentEditor || app.contentEditor.hidden) {
                return app.contentTextarea?.value || '';
            }
            let out = '';
            app.contentEditor.childNodes.forEach((child) => {
                out += app.serializeEditorNodeHTML(child);
            });
            out = out.replace(/(<br\s*\/?>\s*)+$/, '').trim();
            return app.RICHTEXT_PREFIX + out;
        };

        app.syncTextareaFromEditor = () => {
            if (!app.contentTextarea || !app.contentEditor || app.contentEditor.hidden) return;
            app.contentTextarea.value = app.serializeEditorToText();
            app.syncEditorEmptyState();
        };

        app.syncEditorOutput = () => {
            app.syncTextareaFromEditor();
            app.updateLivePreview();
        };

        app.saveEditorRange = () => {
            if (!app.contentEditor || app.contentEditor.hidden) return;
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return;
            const range = sel.getRangeAt(0);
            if (app.contentEditor.contains(range.startContainer)) {
                app.editorLastRange = range.cloneRange();
            }
        };

        app.placeCaretAfterNode = (node) => {
            if (!app.contentEditor) return;
            const range = document.createRange();
            range.setStartAfter(node);
            range.collapse(true);
            const sel = window.getSelection();
            if (sel) {
                sel.removeAllRanges();
                sel.addRange(range);
            }
            app.editorLastRange = range.cloneRange();
        };

        app.getEditorInsertionRange = () => {
            const sel = window.getSelection();
            if (sel && sel.rangeCount > 0) {
                const current = sel.getRangeAt(0);
                if (app.contentEditor?.contains(current.startContainer)) {
                    return current;
                }
            }
            if (app.editorLastRange && app.contentEditor?.contains(app.editorLastRange.startContainer)) {
                return app.editorLastRange.cloneRange();
            }
            const fallback = document.createRange();
            fallback.selectNodeContents(app.contentEditor);
            fallback.collapse(false);
            return fallback;
        };

        app.insertNodeIntoEditor = (node) => {
            if (!app.contentEditor || app.contentEditor.hidden) return false;
            const range = app.getEditorInsertionRange();
            const sel = window.getSelection();
            app.contentEditor.focus();
            if (sel) {
                sel.removeAllRanges();
                sel.addRange(range);
            }
            if (!range.collapsed) {
                range.deleteContents();
            }
            range.insertNode(node);

            const br = document.createElement('br');
            if (node.nextSibling) {
                node.parentNode.insertBefore(br, node.nextSibling);
            } else {
                node.parentNode.appendChild(br);
            }

            app.placeCaretAfterNode(br);
            app.syncEditorOutput();
            return true;
        };
    };
})(window);
