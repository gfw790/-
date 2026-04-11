// 사내 게시판 클라이언트 스크립트

(function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // 좋아요
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const postId = btn.dataset.postId;
            try {
                const fd = new FormData();
                fd.append('post_id', postId);
                fd.append('csrf', csrf);
                const res = await fetch('like.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    btn.classList.toggle('liked', data.liked);
                    btn.querySelector('.like-count').textContent = data.count;
                } else {
                    alert(data.message || '오류가 발생했습니다.');
                }
            } catch (e) {
                alert('네트워크 오류');
            }
        });
    });

    // 답글 폼 토글
    document.querySelectorAll('.reply-toggle').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const id = link.dataset.commentId;
            const form = document.getElementById('reply-form-' + id);
            if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
        });
    });

    // 댓글 삭제
    document.querySelectorAll('.comment-delete').forEach(link => {
        link.addEventListener('click', async (e) => {
            e.preventDefault();
            if (!confirm('이 댓글을 삭제하시겠습니까?')) return;
            const id = link.dataset.commentId;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('comment_id', id);
            fd.append('csrf', csrf);
            const res = await fetch('comment.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) location.reload();
            else alert(data.message || '삭제 실패');
        });
    });

    // 글쓰기 - 첨부파일 삭제 / 본문 삽입
    const writeForm = document.querySelector('form.write-form, form#write-form');
    const contentTextarea = document.getElementById('content') || document.querySelector('textarea[name="content"]');
    const contentEditor = document.getElementById('content-editor');
    const titleInput = writeForm?.querySelector('input[name="title"]');
    const attachmentInput = document.getElementById('attachments') || document.querySelector('input[type="file"][name="attachments[]"]');
    const newAttachmentTokenList = document.getElementById('new-attachment-token-list');
    const previewButton = document.getElementById('preview-write-btn');
    const previewModal = document.getElementById('write-preview-modal');
    const previewCloseButton = document.getElementById('close-write-preview');
    const previewTitle = document.getElementById('write-preview-title');
    const previewContent = document.getElementById('write-preview-content');
    const livePreviewBody = document.getElementById('live-content-preview-body');
    const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    const blockTags = new Set(['DIV', 'P', 'LI', 'UL', 'OL', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BLOCKQUOTE', 'PRE', 'FIGURE']);
    const RICHTEXT_PREFIX = '<!--richtext-->';
    const INLINE_FORMAT_TAGS = new Set(['B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'SPAN', 'FONT']);
    const defaultEmbedOptions = { align: 'center', size: 'medium', rotate: 0, flip: false };
    const defaultTextStyle = {
        size: 22,
        bold: false,
        italic: false,
        underline: false,
        strike: false,
        color: '#ff2d2d',
    };
    const defaultShapeStyle = {
        type: 'rect',
        lineWidth: 2,
        lineStyle: 'solid',
        colorTarget: 'stroke',
        strokeColor: '#ff2d2d',
        strokeAlpha: 1,
        fillColor: '#000000',
        fillAlpha: 0,
    };
    const defaultDrawStyle = {
        lineWidth: 2,
        color: '#ff2d2d',
        alpha: 1,
    };
    const textPresetColors = [
        '#ff0000', '#ff5a00', '#ff9100', '#ffc400', '#ffe600', '#c6d300', '#8bc34a', '#00b894', '#00a8ff', '#2f80ed',
        '#3f51b5', '#673ab7', '#9c27b0', '#e91e63', '#795548', '#9e9e9e', '#607d8b', '#000000', '#ffffff', '#f3e5f5'
    ];
    const shapePresetColors = [
        'transparent',
        '#ff0000', '#ff5a00', '#ff9100', '#ffc400', '#ffe600', '#c6d300', '#8bc34a', '#00b894', '#00a8ff',
        '#2f80ed', '#3f51b5', '#673ab7', '#9c27b0', '#e91e63', '#795548', '#9e9e9e', '#607d8b', '#000000', '#ffffff'
    ];
    let modalPreviewObjectUrls = [];
    let livePreviewObjectUrls = [];
    let editorObjectUrls = [];
    let editorLastRange = null;
    let imageToolbar = null;
    let activeEmbed = null;
    let imageEditorModal = null;
    let imageEditorState = null;
    let generatedEditedFiles = new Map();
    let stagedUploadFiles = new Map();
    let editedEmbedObjectUrls = [];

    const escapeHtml = (str) => String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const isImageFileName = (fileName) => {
        const ext = String(fileName || '').toLowerCase().split('.').pop();
        return imageExts.includes(ext);
    };

    const clearObjectUrls = (list) => {
        list.forEach((url) => URL.revokeObjectURL(url));
        list.length = 0;
    };

    const getUploadFileKey = (file) => String(file?.name || '').toLowerCase();

    const syncStagedUploadFilesFromInput = () => {
        Array.from(attachmentInput?.files || []).forEach((file) => {
            const key = getUploadFileKey(file);
            if (!key) return;
            if (generatedEditedFiles.has(key)) return;
            stagedUploadFiles.set(key, file);
        });
    };

    const rebuildAttachmentInputFiles = () => {
        if (!attachmentInput) return;
        syncStagedUploadFilesFromInput();
        try {
            const dt = new DataTransfer();
            const seen = new Set();
            stagedUploadFiles.forEach((file, key) => {
                if (!key || seen.has(key)) {
                    return;
                }
                dt.items.add(file);
                seen.add(key);
            });
            generatedEditedFiles.forEach((file, key) => {
                if (seen.has(key)) return;
                dt.items.add(file);
                seen.add(key);
            });
            attachmentInput.files = dt.files;
        } catch (e) {
            // DataTransfer 미지원 브라우저는 기존 파일 입력을 유지한다.
        }
        renderNewAttachmentTokens();
    };

    const getExistingImageAttachments = () => {
        const byId = new Map();
        const byName = new Map();
        const ordered = [];

        document.querySelectorAll('.existing-file[data-attach-id]').forEach((el) => {
            if (el.dataset.deleted === '1') return;
            if (el.dataset.isImage !== '1') return;

            const id = Number(el.dataset.attachId || 0);
            if (!id) return;

            const name = el.dataset.originalName || ('첨부 ' + id);
            const info = { id, name, src: 'download.php?id=' + id };
            ordered.push(info);
            byId.set(id, info);
            byName.set(name.toLowerCase(), info);
        });

        return { byId, byName, ordered };
    };

    const getSelectedImageFileByName = () => {
        const byName = new Map();
        Array.from(attachmentInput?.files || []).forEach((file) => {
            if (!isImageFileName(file.name)) return;
            byName.set(String(file.name).toLowerCase(), file);
        });
        return byName;
    };

    const resolveImageFromToken = (tokenValue, existingImages, selectedFiles, objectUrlStore) => {
        const token = String(tokenValue || '').trim();
        if (!token) return null;

        if (token.toLowerCase().startsWith('id:')) {
            const id = Number(token.slice(3).trim());
            if (!id) return null;
            return existingImages.byId.get(id) || null;
        }

        if (/^\d+$/.test(token)) {
            const index = Number(token) - 1;
            if (index >= 0 && index < existingImages.ordered.length) {
                return existingImages.ordered[index];
            }
            return null;
        }

        const normalized = token.toLowerCase();
        if (existingImages.byName.has(normalized)) {
            return existingImages.byName.get(normalized);
        }

        const file = selectedFiles.get(normalized);
        if (!file) return null;

        const objectUrl = URL.createObjectURL(file);
        objectUrlStore.push(objectUrl);
        return {
            id: 0,
            name: file.name,
            src: objectUrl,
        };
    };

    const normalizeEmbedOptions = (opts = {}) => {
        const align = String(opts.align || defaultEmbedOptions.align).toLowerCase();
        const size = String(opts.size || defaultEmbedOptions.size).toLowerCase();
        let rotate = Number(opts.rotate);
        if (!Number.isFinite(rotate)) rotate = defaultEmbedOptions.rotate;
        rotate = ((Math.round(rotate / 90) * 90) % 360 + 360) % 360;
        const flipRaw = opts.flip;
        const flip = flipRaw === true || flipRaw === 1 || flipRaw === '1' || String(flipRaw).toLowerCase() === 'true';
        return {
            align: ['left', 'center', 'right'].includes(align) ? align : defaultEmbedOptions.align,
            size: ['small', 'medium', 'large'].includes(size) ? size : defaultEmbedOptions.size,
            rotate,
            flip,
        };
    };

    const parseAttachmentTokenValue = (tokenValue) => {
        const parts = String(tokenValue || '').split('|').map((x) => x.trim()).filter(Boolean);
        const target = parts.shift() || '';
        const options = normalizeEmbedOptions();
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
                if (Number.isFinite(deg)) {
                    options.rotate = deg;
                }
            }
            if (key === 'flip') {
                options.flip = value === '1' || value === 'true' || value === 'yes';
            }
        });
        return { target, options: normalizeEmbedOptions(options) };
    };

    const buildAttachmentTokenValue = (target, options) => {
        const normalized = normalizeEmbedOptions(options);
        return `${target}|align=${normalized.align}|size=${normalized.size}|rotate=${normalized.rotate}|flip=${normalized.flip ? 1 : 0}`;
    };

    const applyImageTransformToElement = (imgElement, options = {}) => {
        if (!imgElement) return;
        const normalized = normalizeEmbedOptions(options);
        const scaleX = normalized.flip ? -1 : 1;
        imgElement.style.transform = `scaleX(${scaleX}) rotate(${normalized.rotate}deg)`;
        imgElement.style.transformOrigin = 'center center';
    };

    const cycleEmbedSize = (currentSize) => {
        if (currentSize === 'small') return 'medium';
        if (currentSize === 'medium') return 'large';
        return 'small';
    };

    const appendTextWithBreaks = (targetElement, text) => {
        const chunks = String(text || '').split('\n');
        chunks.forEach((chunk, idx) => {
            if (chunk) {
                targetElement.appendChild(document.createTextNode(chunk));
            }
            if (idx < chunks.length - 1) {
                targetElement.appendChild(document.createElement('br'));
            }
        });
    };

    const syncEditorEmptyState = () => {
        if (!contentEditor || contentEditor.hidden) return;
        const hasEmbed = !!contentEditor.querySelector('.editor-embed');
        const text = (contentEditor.textContent || '').replace(/\u00A0/g, ' ').trim();
        if (!hasEmbed && text === '') {
            contentEditor.classList.add('is-empty');
        } else {
            contentEditor.classList.remove('is-empty');
        }
    };

    const applyEmbedVisualClass = (figure, options) => {
        if (!figure) return;
        const normalized = normalizeEmbedOptions(options);
        figure.classList.remove('align-left', 'align-center', 'align-right', 'size-small', 'size-medium', 'size-large');
        figure.classList.add(`align-${normalized.align}`, `size-${normalized.size}`);
        figure.dataset.align = normalized.align;
        figure.dataset.size = normalized.size;
        figure.dataset.rotate = String(normalized.rotate);
        figure.dataset.flip = normalized.flip ? '1' : '0';
        const img = figure.querySelector('img');
        applyImageTransformToElement(img, normalized);
    };

    const createEditorEmbed = (targetToken, imageInfo, options = {}) => {
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

        applyEmbedVisualClass(figure, options);
        return figure;
    };

    const renderEditorFromPlainText = (raw) => {
        const existingImages = getExistingImageAttachments();
        const selectedFiles = getSelectedImageFileByName();
        const tokenRegex = /\[\[첨부:([^[\]]+)\]\]/g;
        let lastIndex = 0;
        let match = null;

        while ((match = tokenRegex.exec(raw)) !== null) {
            const plainText = raw.slice(lastIndex, match.index);
            if (plainText) appendTextWithBreaks(contentEditor, plainText);

            const parsed = parseAttachmentTokenValue(match[1]);
            const imageInfo = resolveImageFromToken(parsed.target, existingImages, selectedFiles, editorObjectUrls);
            if (!imageInfo) {
                appendTextWithBreaks(contentEditor, match[0]);
            } else {
                const embed = createEditorEmbed(parsed.target, imageInfo, parsed.options);
                contentEditor.appendChild(embed);
                contentEditor.appendChild(document.createElement('br'));
            }
            lastIndex = tokenRegex.lastIndex;
        }

        const tail = raw.slice(lastIndex);
        if (tail) appendTextWithBreaks(contentEditor, tail);
    };

    const renderEditorFromRichtext = (htmlContent) => {
        const existingImages = getExistingImageAttachments();
        const selectedFiles = getSelectedImageFileByName();

        // Replace attachment tokens with placeholder spans before parsing HTML
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
                        const parsed = parseAttachmentTokenValue(tokenValue);
                        const imageInfo = resolveImageFromToken(parsed.target, existingImages, selectedFiles, editorObjectUrls);
                        if (imageInfo) {
                            targetParent.appendChild(createEditorEmbed(parsed.target, imageInfo, parsed.options));
                            targetParent.appendChild(document.createElement('br'));
                        } else {
                            targetParent.appendChild(document.createTextNode(`[[첨부:${tokenValue}]]`));
                        }
                        return;
                    }
                    // BR
                    if (el.tagName === 'BR') {
                        targetParent.appendChild(document.createElement('br'));
                        return;
                    }
                    // Inline format tags — clone with safe attributes and recurse
                    if (INLINE_FORMAT_TAGS.has(el.tagName)) {
                        const tag = el.tagName === 'STRONG' ? 'b'
                            : el.tagName === 'EM' ? 'i'
                            : el.tagName === 'STRIKE' ? 's'
                            : el.tagName.toLowerCase();
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
                    if (blockTags.has(el.tagName) && targetParent.childNodes.length > before) {
                        const last = targetParent.lastChild;
                        if (!last || last.tagName !== 'BR') {
                            targetParent.appendChild(document.createElement('br'));
                        }
                    }
                }
            });
        };

        walkAndAppend(temp, contentEditor);
    };

    const renderEditorFromText = (rawText) => {
        if (!contentEditor || !contentTextarea || contentEditor.hidden) return;
        hideImageToolbar();
        clearObjectUrls(editorObjectUrls);
        contentEditor.innerHTML = '';

        const raw = String(rawText || '');
        if (raw.startsWith(RICHTEXT_PREFIX)) {
            renderEditorFromRichtext(raw.slice(RICHTEXT_PREFIX.length));
        } else {
            renderEditorFromPlainText(raw);
        }

        if (contentEditor.childNodes.length === 0) {
            contentEditor.appendChild(document.createElement('br'));
        }
        syncEditorEmptyState();
    };

    const serializeEditorNode = (node) => {
        if (!node) return '';
        if (node.nodeType === Node.TEXT_NODE) {
            return (node.nodeValue || '').replace(/\u00A0/g, ' ');
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }

        const el = node;
        if (el.classList?.contains('editor-embed')) {
            const tokenBase = String(el.dataset.tokenBase || '').trim();
            if (!tokenBase) return '';
            const tokenValue = buildAttachmentTokenValue(tokenBase, {
                align: el.dataset.align,
                size: el.dataset.size,
                rotate: el.dataset.rotate,
                flip: el.dataset.flip,
            });
            return `[[첨부:${tokenValue}]]`;
        }
        if (el.tagName === 'BR') {
            return '\n';
        }

        let out = '';
        el.childNodes.forEach((child) => {
            out += serializeEditorNode(child);
        });

        if (blockTags.has(el.tagName) && out !== '' && !out.endsWith('\n')) {
            out += '\n';
        }
        return out;
    };

    const serializeEditorNodeHTML = (node) => {
        if (!node) return '';
        if (node.nodeType === Node.TEXT_NODE) {
            return escapeHtml((node.nodeValue || '').replace(/\u00A0/g, ' '));
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return '';

        const el = node;
        if (el.classList?.contains('editor-embed')) {
            const tokenBase = String(el.dataset.tokenBase || '').trim();
            if (!tokenBase) return '';
            const tokenValue = buildAttachmentTokenValue(tokenBase, {
                align: el.dataset.align,
                size: el.dataset.size,
                rotate: el.dataset.rotate,
                flip: el.dataset.flip,
            });
            return `[[첨부:${tokenValue}]]`;
        }
        if (el.tagName === 'BR') return '<br>';

        let inner = '';
        el.childNodes.forEach((child) => { inner += serializeEditorNodeHTML(child); });

        if (INLINE_FORMAT_TAGS.has(el.tagName)) {
            const tag = el.tagName === 'STRONG' ? 'b'
                : el.tagName === 'EM' ? 'i'
                : el.tagName === 'STRIKE' ? 's'
                : el.tagName.toLowerCase();
            let attrs = '';
            if (el.tagName === 'SPAN') {
                const parts = [];
                if (el.style.color) parts.push(`color:${el.style.color}`);
                if (el.style.fontSize) parts.push(`font-size:${el.style.fontSize}`);
                if (parts.length) attrs = ` style="${parts.join(';')}"`;
            } else if (el.tagName === 'FONT') {
                if (el.size) attrs = ` size="${escapeHtml(el.size)}"`;
            }
            return `<${tag}${attrs}>${inner}</${tag}>`;
        }

        if (blockTags.has(el.tagName) && inner !== '' && !inner.endsWith('<br>')) {
            inner += '<br>';
        }
        return inner;
    };

    const serializeEditorToText = () => {
        if (!contentEditor || contentEditor.hidden) {
            return contentTextarea?.value || '';
        }
        let out = '';
        contentEditor.childNodes.forEach((child) => {
            out += serializeEditorNodeHTML(child);
        });
        out = out.replace(/(<br\s*\/?>\s*)+$/, '').trim();
        return RICHTEXT_PREFIX + out;
    };

    const syncTextareaFromEditor = () => {
        if (!contentTextarea || !contentEditor || contentEditor.hidden) return;
        contentTextarea.value = serializeEditorToText();
        syncEditorEmptyState();
    };

    const saveEditorRange = () => {
        if (!contentEditor || contentEditor.hidden) return;
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (contentEditor.contains(range.startContainer)) {
            editorLastRange = range.cloneRange();
        }
    };

    const placeCaretAfterNode = (node) => {
        if (!contentEditor) return;
        const range = document.createRange();
        range.setStartAfter(node);
        range.collapse(true);
        const sel = window.getSelection();
        if (sel) {
            sel.removeAllRanges();
            sel.addRange(range);
        }
        editorLastRange = range.cloneRange();
    };

    const getEditorInsertionRange = () => {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            const current = sel.getRangeAt(0);
            if (contentEditor?.contains(current.startContainer)) {
                return current;
            }
        }
        if (editorLastRange && contentEditor?.contains(editorLastRange.startContainer)) {
            return editorLastRange.cloneRange();
        }
        const fallback = document.createRange();
        fallback.selectNodeContents(contentEditor);
        fallback.collapse(false);
        return fallback;
    };

    const insertNodeIntoEditor = (node) => {
        if (!contentEditor || contentEditor.hidden) return false;
        const range = getEditorInsertionRange();
        const sel = window.getSelection();
        contentEditor.focus();
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

        placeCaretAfterNode(br);
        syncTextareaFromEditor();
        updateLivePreview();
        return true;
    };

    const hideImageToolbar = () => {
        if (!imageToolbar) return;
        imageToolbar.classList.remove('is-visible');
        activeEmbed = null;
    };

    const placeImageToolbar = () => {
        if (!imageToolbar || !activeEmbed) return;
        const rect = activeEmbed.getBoundingClientRect();
        const toolbarRect = imageToolbar.getBoundingClientRect();
        const top = Math.max(8, rect.top - toolbarRect.height - 8);
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - toolbarRect.width - 8));
        imageToolbar.style.top = `${top}px`;
        imageToolbar.style.left = `${left}px`;
    };

    const showImageToolbar = (embed) => {
        if (!imageToolbar || !embed) return;
        activeEmbed = embed;
        imageToolbar.classList.add('is-visible');
        placeImageToolbar();
    };

    const applyEmbedOption = (embed, patch = {}) => {
        if (!embed) return;
        applyEmbedVisualClass(embed, {
            align: patch.align || embed.dataset.align || defaultEmbedOptions.align,
            size: patch.size || embed.dataset.size || defaultEmbedOptions.size,
            rotate: patch.rotate ?? embed.dataset.rotate ?? defaultEmbedOptions.rotate,
            flip: patch.flip ?? embed.dataset.flip ?? defaultEmbedOptions.flip,
        });
        syncTextareaFromEditor();
        updateLivePreview();
        showImageToolbar(embed);
    };

    const deleteEmbed = (embed) => {
        if (!embed) return;
        const next = embed.nextSibling;
        if (next && next.nodeType === Node.ELEMENT_NODE && next.tagName === 'BR') {
            next.remove();
        }
        embed.remove();
        hideImageToolbar();
        syncTextareaFromEditor();
        updateLivePreview();
    };

    const getImageEditorCanvas = () => imageEditorModal?.querySelector('.image-edit-canvas');
    const getImageEditorOverlay = () => imageEditorModal?.querySelector('.image-edit-overlay');
    const getImageEditorMeta = () => imageEditorModal?.querySelector('.image-edit-meta');
    const getImageEditorWidthInput = () => imageEditorModal?.querySelector('input[data-field="width"]');
    const getImageEditorHeightInput = () => imageEditorModal?.querySelector('input[data-field="height"]');
    const getImageEditorZoomSelect = () => imageEditorModal?.querySelector('select[data-field="zoom"]');
    const getImageEditorPixelModeCheckbox = () => imageEditorModal?.querySelector('input[data-field="pixel-mode"]');
    const getImageEditorRatioLockButton = () => imageEditorModal?.querySelector('button[data-action="toggle-ratio"]');
    const getImageEditorStage = () => imageEditorModal?.querySelector('.image-edit-canvas-stage');
    const getImageEditorCropActionButton = () => imageEditorModal?.querySelector('.image-crop-action');
    const getImageEditorTextSettingButton = () => imageEditorModal?.querySelector('button[data-action="text-settings"]');
    const getImageEditorTextPanel = () => imageEditorModal?.querySelector('.image-text-panel');
    const getImageEditorTextSizeInput = () => imageEditorModal?.querySelector('input[data-field="text-size"]');
    const getImageEditorTextSizeRange = () => imageEditorModal?.querySelector('input[data-field="text-size-range"]');
    const getImageEditorTextColorDim = () => imageEditorModal?.querySelector('.image-text-color-dim');
    const getImageEditorTextColorPicker = () => imageEditorModal?.querySelector('.image-text-color-picker');
    const getImageEditorTextColorPreview = () => imageEditorModal?.querySelector('.image-text-color-preview');
    const getImageEditorTextColorHexInput = () => imageEditorModal?.querySelector('input[data-field="text-color-hex"]');
    const getImageEditorTextColorSvCanvas = () => imageEditorModal?.querySelector('canvas[data-field="text-color-sv"]');
    const getImageEditorTextColorHueCanvas = () => imageEditorModal?.querySelector('canvas[data-field="text-color-hue"]');
    const getImageEditorTextStyleButtons = () => imageEditorModal?.querySelectorAll('.image-text-style-btn') || [];
    const getImageEditorTextPaletteButtons = () => imageEditorModal?.querySelectorAll('.image-text-color-palette .color-chip') || [];
    const getImageEditorTextEntry = () => imageEditorModal?.querySelector('.image-text-entry');
    const getImageEditorTextEntryInput = () => imageEditorModal?.querySelector('.image-text-entry-input');
    const getImageEditorShapeSettingButton = () => imageEditorModal?.querySelector('button[data-action="shape-settings"]');
    const getImageEditorShapePanel = () => imageEditorModal?.querySelector('.image-shape-panel');
    const getImageEditorShapeTypeButtons = () => imageEditorModal?.querySelectorAll('.image-shape-type-btn') || [];
    const getImageEditorShapeLineStyleButtons = () => imageEditorModal?.querySelectorAll('.image-shape-line-style-btn') || [];
    const getImageEditorShapeColorTabButtons = () => imageEditorModal?.querySelectorAll('.image-shape-color-tab-btn') || [];
    const getImageEditorShapeWidthInput = () => imageEditorModal?.querySelector('input[data-field="shape-line-width"]');
    const getImageEditorShapeWidthRange = () => imageEditorModal?.querySelector('input[data-field="shape-line-width-range"]');
    const getImageEditorShapePaletteButtons = () => imageEditorModal?.querySelectorAll('.image-shape-color-palette .color-chip') || [];
    const getImageEditorShapeColorDim = () => imageEditorModal?.querySelector('.image-shape-color-dim');
    const getImageEditorShapeColorPicker = () => imageEditorModal?.querySelector('.image-shape-color-picker');
    const getImageEditorShapeColorPreview = () => imageEditorModal?.querySelector('.image-shape-color-preview');
    const getImageEditorShapeColorHexInput = () => imageEditorModal?.querySelector('input[data-field="shape-color-hex"]');
    const getImageEditorShapeColorSvCanvas = () => imageEditorModal?.querySelector('canvas[data-field="shape-color-sv"]');
    const getImageEditorShapeColorHueCanvas = () => imageEditorModal?.querySelector('canvas[data-field="shape-color-hue"]');
    const getImageEditorShapeOpacityRange = () => imageEditorModal?.querySelector('input[data-field="shape-color-alpha"]');
    const getImageEditorShapeOpacityLabel = () => imageEditorModal?.querySelector('.shape-color-alpha-label');
    const getImageEditorShapeInlineOpacityRange = () => imageEditorModal?.querySelector('input[data-field="shape-color-alpha-inline"]');
    const getImageEditorShapeInlineOpacityLabel = () => imageEditorModal?.querySelector('.shape-color-alpha-inline-label');
    const getImageEditorDrawSettingButton = () => imageEditorModal?.querySelector('button[data-action="draw-settings"]');
    const getImageEditorDrawPanel = () => imageEditorModal?.querySelector('.image-draw-panel');
    const getImageEditorDrawWidthInput = () => imageEditorModal?.querySelector('input[data-field="draw-line-width"]');
    const getImageEditorDrawWidthRange = () => imageEditorModal?.querySelector('input[data-field="draw-line-width-range"]');
    const getImageEditorDrawPaletteButtons = () => imageEditorModal?.querySelectorAll('.image-draw-color-palette .color-chip') || [];
    const getImageEditorDrawColorDim = () => imageEditorModal?.querySelector('.image-draw-color-dim');
    const getImageEditorDrawColorPicker = () => imageEditorModal?.querySelector('.image-draw-color-picker');
    const getImageEditorDrawColorPreview = () => imageEditorModal?.querySelector('.image-draw-color-preview');
    const getImageEditorDrawColorHexInput = () => imageEditorModal?.querySelector('input[data-field="draw-color-hex"]');
    const getImageEditorDrawColorSvCanvas = () => imageEditorModal?.querySelector('canvas[data-field="draw-color-sv"]');
    const getImageEditorDrawColorHueCanvas = () => imageEditorModal?.querySelector('canvas[data-field="draw-color-hue"]');
    const getImageEditorDrawOpacityRange = () => imageEditorModal?.querySelector('input[data-field="draw-color-alpha"]');
    const getImageEditorDrawOpacityLabel = () => imageEditorModal?.querySelector('.draw-color-alpha-label');
    const getImageEditorDrawInlineOpacityRange = () => imageEditorModal?.querySelector('input[data-field="draw-color-alpha-inline"]');
    const getImageEditorDrawInlineOpacityLabel = () => imageEditorModal?.querySelector('.draw-color-alpha-inline-label');
    const textMeasureCanvas = document.createElement('canvas');
    const textMeasureContext = textMeasureCanvas.getContext('2d');
    const parsePxInputValue = (value) => {
        const match = String(value ?? '').match(/(\d+)/);
        return match ? Number(match[1]) : 0;
    };
    const formatPxValue = (value) => `${Math.max(1, Math.round(Number(value) || 1))}px`;
    const clampTextSize = (value) => Math.max(10, Math.min(120, Math.round(Number(value) || defaultTextStyle.size)));

    const normalizeHexColor = (value, fallback = defaultTextStyle.color) => {
        const raw = String(value || '').trim().toLowerCase();
        const short = raw.match(/^#([0-9a-f]{3})$/i);
        if (short) {
            const [r, g, b] = short[1].split('');
            return `#${r}${r}${g}${g}${b}${b}`;
        }
        if (/^#[0-9a-f]{6}$/i.test(raw)) {
            return raw;
        }
        return fallback;
    };

    const copyTextStyle = (style) => {
        const merged = { ...defaultTextStyle, ...(style || {}) };
        return {
            size: clampTextSize(merged.size),
            bold: !!merged.bold,
            italic: !!merged.italic,
            underline: !!merged.underline,
            strike: !!merged.strike,
            color: normalizeHexColor(merged.color),
        };
    };

    const ensureImageEditorTextStyle = () => {
        if (!imageEditorState) return copyTextStyle(defaultTextStyle);
        if (!imageEditorState.textStyle) {
            imageEditorState.textStyle = copyTextStyle(defaultTextStyle);
        } else {
            imageEditorState.textStyle = copyTextStyle(imageEditorState.textStyle);
        }
        return imageEditorState.textStyle;
    };

    const clampShapeLineWidth = (value) => Math.max(1, Math.min(30, Math.round(Number(value) || defaultShapeStyle.lineWidth)));
    const normalizeAlpha = (value, fallback = 1) => {
        const n = Number(value);
        if (!Number.isFinite(n)) return fallback;
        return Math.max(0, Math.min(1, n));
    };

    const copyShapeStyle = (style) => {
        const merged = { ...defaultShapeStyle, ...(style || {}) };
        return {
            type: ['rect', 'roundRect', 'ellipse', 'line', 'freeQuad'].includes(String(merged.type)) ? String(merged.type) : defaultShapeStyle.type,
            lineWidth: clampShapeLineWidth(merged.lineWidth),
            lineStyle: ['solid', 'dash', 'dot'].includes(String(merged.lineStyle)) ? String(merged.lineStyle) : defaultShapeStyle.lineStyle,
            colorTarget: merged.colorTarget === 'fill' ? 'fill' : 'stroke',
            strokeColor: normalizeHexColor(merged.strokeColor, defaultShapeStyle.strokeColor),
            strokeAlpha: normalizeAlpha(merged.strokeAlpha, defaultShapeStyle.strokeAlpha),
            fillColor: normalizeHexColor(merged.fillColor, defaultShapeStyle.fillColor),
            fillAlpha: normalizeAlpha(merged.fillAlpha, defaultShapeStyle.fillAlpha),
        };
    };

    const ensureImageEditorShapeStyle = () => {
        if (!imageEditorState) return copyShapeStyle(defaultShapeStyle);
        if (!imageEditorState.shapeStyle) {
            imageEditorState.shapeStyle = copyShapeStyle(defaultShapeStyle);
        } else {
            imageEditorState.shapeStyle = copyShapeStyle(imageEditorState.shapeStyle);
        }
        return imageEditorState.shapeStyle;
    };

    const clampDrawLineWidth = (value) => Math.max(1, Math.min(30, Math.round(Number(value) || defaultDrawStyle.lineWidth)));

    const copyDrawStyle = (style) => {
        const merged = { ...defaultDrawStyle, ...(style || {}) };
        return {
            lineWidth: clampDrawLineWidth(merged.lineWidth),
            color: normalizeHexColor(merged.color, defaultDrawStyle.color),
            alpha: normalizeAlpha(merged.alpha, defaultDrawStyle.alpha),
        };
    };

    const ensureImageEditorDrawStyle = () => {
        if (!imageEditorState) return copyDrawStyle(defaultDrawStyle);
        if (!imageEditorState.drawStyle) {
            imageEditorState.drawStyle = copyDrawStyle(defaultDrawStyle);
        } else {
            imageEditorState.drawStyle = copyDrawStyle(imageEditorState.drawStyle);
        }
        return imageEditorState.drawStyle;
    };

    const getShapeColorKeyByTarget = (target) => target === 'fill' ? 'fillColor' : 'strokeColor';
    const getShapeAlphaKeyByTarget = (target) => target === 'fill' ? 'fillAlpha' : 'strokeAlpha';

    const rgbToHex = (r, g, b) => {
        const toHex = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
        return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
    };

    const hexToRgb = (hex) => {
        const normalized = normalizeHexColor(hex, '#000000');
        return {
            r: parseInt(normalized.slice(1, 3), 16),
            g: parseInt(normalized.slice(3, 5), 16),
            b: parseInt(normalized.slice(5, 7), 16),
        };
    };

    const hsvToRgb = (h, s, v) => {
        const hh = ((Number(h) % 360) + 360) % 360;
        const ss = Math.max(0, Math.min(1, Number(s)));
        const vv = Math.max(0, Math.min(1, Number(v)));
        const c = vv * ss;
        const x = c * (1 - Math.abs(((hh / 60) % 2) - 1));
        const m = vv - c;
        let rr = 0, gg = 0, bb = 0;

        if (hh < 60) {
            rr = c; gg = x; bb = 0;
        } else if (hh < 120) {
            rr = x; gg = c; bb = 0;
        } else if (hh < 180) {
            rr = 0; gg = c; bb = x;
        } else if (hh < 240) {
            rr = 0; gg = x; bb = c;
        } else if (hh < 300) {
            rr = x; gg = 0; bb = c;
        } else {
            rr = c; gg = 0; bb = x;
        }

        return {
            r: (rr + m) * 255,
            g: (gg + m) * 255,
            b: (bb + m) * 255,
        };
    };

    const rgbToHsv = (r, g, b) => {
        const rr = Math.max(0, Math.min(255, Number(r))) / 255;
        const gg = Math.max(0, Math.min(255, Number(g))) / 255;
        const bb = Math.max(0, Math.min(255, Number(b))) / 255;
        const max = Math.max(rr, gg, bb);
        const min = Math.min(rr, gg, bb);
        const delta = max - min;

        let h = 0;
        if (delta > 0) {
            if (max === rr) h = 60 * (((gg - bb) / delta) % 6);
            else if (max === gg) h = 60 * (((bb - rr) / delta) + 2);
            else h = 60 * (((rr - gg) / delta) + 4);
        }
        if (h < 0) h += 360;

        const s = max === 0 ? 0 : delta / max;
        const v = max;
        return { h, s, v };
    };

    const colorToRgba = (hex, alpha = 1) => {
        const rgb = hexToRgb(hex);
        const a = normalizeAlpha(alpha, 1);
        return `rgba(${Math.round(rgb.r)}, ${Math.round(rgb.g)}, ${Math.round(rgb.b)}, ${a})`;
    };

    const hideCropActionButton = () => {
        const cropButton = getImageEditorCropActionButton();
        if (!cropButton) return;
        cropButton.hidden = true;
        cropButton.textContent = '';
        cropButton.style.left = '';
        cropButton.style.top = '';
    };

    const showCropActionButton = (rect) => {
        const cropButton = getImageEditorCropActionButton();
        const overlay = getImageEditorOverlay();
        if (!cropButton || !overlay || !rect) return;

        const w = Math.max(1, Math.round(rect.w || 0));
        const h = Math.max(1, Math.round(rect.h || 0));
        if (w < 4 || h < 4) {
            hideCropActionButton();
            return;
        }

        cropButton.textContent = `${w}x${h} 자르기`;
        cropButton.hidden = false;

        const overlayRect = overlay.getBoundingClientRect();
        const scaleX = overlay.width > 0 ? (overlayRect.width / overlay.width) : 1;
        const scaleY = overlay.height > 0 ? (overlayRect.height / overlay.height) : 1;
        const margin = 6;

        let x = overlayRect.left + (rect.x + rect.w) * scaleX - margin;
        let y = overlayRect.top + (rect.y + rect.h) * scaleY - margin;

        const bw = cropButton.offsetWidth || 170;
        const bh = cropButton.offsetHeight || 32;
        const minX = bw + 8;
        const minY = bh + 8;
        const maxX = window.innerWidth - 8;
        const maxY = window.innerHeight - 8;

        x = Math.min(Math.max(x, minX), maxX);
        y = Math.min(Math.max(y, minY), maxY);

        cropButton.style.left = `${Math.round(x)}px`;
        cropButton.style.top = `${Math.round(y)}px`;
    };

    const hideImageEditorTextColorPicker = () => {
        const colorDim = getImageEditorTextColorDim();
        const picker = getImageEditorTextColorPicker();
        if (colorDim) colorDim.hidden = true;
        if (picker) picker.hidden = true;
    };

    const hideImageEditorTextPanel = () => {
        const panel = getImageEditorTextPanel();
        if (!panel) return;
        panel.hidden = true;
        hideImageEditorTextColorPicker();
    };

    const positionImageEditorTextPanel = () => {
        const panel = getImageEditorTextPanel();
        const button = getImageEditorTextSettingButton();
        if (!panel || !button || panel.hidden) return;

        const rect = button.getBoundingClientRect();
        const panelWidth = panel.offsetWidth || 230;
        const panelHeight = panel.offsetHeight || 320;
        let left = rect.left - (panelWidth / 2) + (rect.width / 2);
        let top = rect.bottom + 8;

        left = Math.max(12, Math.min(left, window.innerWidth - panelWidth - 12));
        if (top + panelHeight > window.innerHeight - 12) {
            top = Math.max(12, rect.top - panelHeight - 8);
        }

        panel.style.left = `${Math.round(left)}px`;
        panel.style.top = `${Math.round(top)}px`;
    };

    const ensureTextColorPickerState = () => {
        if (!imageEditorState) return null;
        const style = ensureImageEditorTextStyle();
        if (!imageEditorState.textColorPicker) {
            const rgb = hexToRgb(style.color);
            const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
            imageEditorState.textColorPicker = {
                h: hsv.h,
                s: hsv.s,
                v: hsv.v,
                dragging: '',
            };
        }
        return imageEditorState.textColorPicker;
    };

    const updateImageEditorTextStyleUi = () => {
        if (!imageEditorModal || !imageEditorState) return;
        const style = ensureImageEditorTextStyle();
        const sizeInput = getImageEditorTextSizeInput();
        const sizeRange = getImageEditorTextSizeRange();
        if (sizeInput) sizeInput.value = `${style.size}px`;
        if (sizeRange) sizeRange.value = String(style.size);

        getImageEditorTextStyleButtons().forEach((btn) => {
            const styleKey = String(btn.dataset.style || '');
            btn.classList.toggle('active', !!style[styleKey]);
        });

        getImageEditorTextPaletteButtons().forEach((btn) => {
            const color = normalizeHexColor(btn.dataset.color || '');
            btn.classList.toggle('active', color === style.color);
        });

        const preview = getImageEditorTextColorPreview();
        const hexInput = getImageEditorTextColorHexInput();
        if (preview) preview.style.backgroundColor = style.color;
        if (hexInput) hexInput.value = style.color;
        refreshImageEditorTextEntrySize();
        applyStateStyleToSelectedObject('text');
    };

    // 선택된 객체의 스타일을 편집기 상태 스타일로 동기화 (객체 선택 시 호출)
    const syncSelectedObjectStyleToState = () => {
        if (!imageEditorState?.selectedObjectId) return;
        const sel = (imageEditorState.objects || []).find((o) => o.id === imageEditorState.selectedObjectId);
        if (!sel?.style) return;
        if (sel.type === 'text') imageEditorState.textStyle = copyTextStyle(sel.style);
        else if (sel.type === 'shape') imageEditorState.shapeStyle = copyShapeStyle(sel.style);
        else if (sel.type === 'draw') imageEditorState.drawStyle = copyDrawStyle(sel.style);
    };

    // 편집기 상태 스타일을 선택된 객체에 적용 (select 도구 활성화 상태에서만)
    const applyStateStyleToSelectedObject = (type) => {
        if (!imageEditorState?.selectedObjectId) return false;
        if (imageEditorState.tool !== 'select') return false;
        const sel = (imageEditorState.objects || []).find((o) => o.id === imageEditorState.selectedObjectId);
        if (!sel || sel.type !== type) return false;
        if (type === 'text') {
            sel.style = copyTextStyle(ensureImageEditorTextStyle());
            const m = measureTextEntryBox(sel.text, sel.style);
            const numLines = String(sel.text || '').split(/\r?\n/).length || 1;
            const lineGap = Math.max(4, Math.round(m.fontSize * 0.35));
            const newNW = Math.max(10, m.width - m.paddingX * 2);
            const newNH = Math.max(m.fontSize, numLines * m.lineHeight - lineGap);
            const prevNW = sel.naturalWidth || sel.width;
            const prevNH = sel.naturalHeight || sel.height;
            if (newNW !== prevNW || newNH !== prevNH) {
                // 폰트/스타일 변경으로 자연 크기가 변했으면 width/height도 재설정
                sel.width = newNW;
                sel.height = newNH;
            }
            sel.naturalWidth = newNW;
            sel.naturalHeight = newNH;
        } else if (type === 'shape') {
            sel.style = copyShapeStyle(ensureImageEditorShapeStyle());
        } else if (type === 'draw') {
            sel.style = copyDrawStyle(ensureImageEditorDrawStyle());
        }
        drawImageEditorOverlay();
        return true;
    };

    const setImageEditorTextSize = (value) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorTextStyle();
        style.size = clampTextSize(value);
        updateImageEditorTextStyleUi();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const toggleImageEditorTextStyle = (styleKey) => {
        if (!imageEditorState || !styleKey) return;
        const style = ensureImageEditorTextStyle();
        if (!(styleKey in style)) return;
        style[styleKey] = !style[styleKey];
        updateImageEditorTextStyleUi();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const applyImageEditorTextColor = (hexColor) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorTextStyle();
        style.color = normalizeHexColor(hexColor, style.color);
        const rgb = hexToRgb(style.color);
        const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
        imageEditorState.textColorPicker = {
            ...(imageEditorState.textColorPicker || {}),
            h: hsv.h,
            s: hsv.s,
            v: hsv.v,
            dragging: '',
        };
        updateImageEditorTextStyleUi();
        renderImageEditorTextColorPicker();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const applyImageEditorTextColorFromPickerState = () => {
        if (!imageEditorState) return;
        const pickerState = ensureTextColorPickerState();
        if (!pickerState) return;
        const rgb = hsvToRgb(pickerState.h, pickerState.s, pickerState.v);
        const hex = rgbToHex(rgb.r, rgb.g, rgb.b);
        const style = ensureImageEditorTextStyle();
        style.color = hex;
        updateImageEditorTextStyleUi();
        renderImageEditorTextColorPicker();
    };

    const renderImageEditorTextColorPicker = () => {
        if (!imageEditorState || !imageEditorModal) return;
        const state = ensureTextColorPickerState();
        const svCanvas = getImageEditorTextColorSvCanvas();
        const hueCanvas = getImageEditorTextColorHueCanvas();
        const preview = getImageEditorTextColorPreview();
        const hexInput = getImageEditorTextColorHexInput();
        if (!state || !svCanvas || !hueCanvas) return;

        const svCtx = svCanvas.getContext('2d');
        const hueCtx = hueCanvas.getContext('2d');
        if (!svCtx || !hueCtx) return;

        const hueRgb = hsvToRgb(state.h, 1, 1);
        svCtx.clearRect(0, 0, svCanvas.width, svCanvas.height);
        svCtx.fillStyle = rgbToHex(hueRgb.r, hueRgb.g, hueRgb.b);
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const whiteGrad = svCtx.createLinearGradient(0, 0, svCanvas.width, 0);
        whiteGrad.addColorStop(0, '#ffffff');
        whiteGrad.addColorStop(1, 'rgba(255,255,255,0)');
        svCtx.fillStyle = whiteGrad;
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const blackGrad = svCtx.createLinearGradient(0, 0, 0, svCanvas.height);
        blackGrad.addColorStop(0, 'rgba(0,0,0,0)');
        blackGrad.addColorStop(1, '#000000');
        svCtx.fillStyle = blackGrad;
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const markerX = state.s * svCanvas.width;
        const markerY = (1 - state.v) * svCanvas.height;
        svCtx.save();
        svCtx.strokeStyle = '#ffffff';
        svCtx.lineWidth = 2;
        svCtx.beginPath();
        svCtx.arc(markerX, markerY, 6, 0, Math.PI * 2);
        svCtx.stroke();
        svCtx.strokeStyle = '#000000';
        svCtx.lineWidth = 1;
        svCtx.beginPath();
        svCtx.arc(markerX, markerY, 7, 0, Math.PI * 2);
        svCtx.stroke();
        svCtx.restore();

        const hueGrad = hueCtx.createLinearGradient(0, 0, hueCanvas.width, 0);
        hueGrad.addColorStop(0, '#ff0000');
        hueGrad.addColorStop(1 / 6, '#ffff00');
        hueGrad.addColorStop(2 / 6, '#00ff00');
        hueGrad.addColorStop(3 / 6, '#00ffff');
        hueGrad.addColorStop(4 / 6, '#0000ff');
        hueGrad.addColorStop(5 / 6, '#ff00ff');
        hueGrad.addColorStop(1, '#ff0000');
        hueCtx.clearRect(0, 0, hueCanvas.width, hueCanvas.height);
        hueCtx.fillStyle = hueGrad;
        hueCtx.fillRect(0, 0, hueCanvas.width, hueCanvas.height);

        const hueX = (state.h / 360) * hueCanvas.width;
        hueCtx.save();
        hueCtx.strokeStyle = '#ffffff';
        hueCtx.lineWidth = 2;
        hueCtx.strokeRect(hueX - 2, 0, 4, hueCanvas.height);
        hueCtx.restore();

        const pickedRgb = hsvToRgb(state.h, state.s, state.v);
        const pickedHex = rgbToHex(pickedRgb.r, pickedRgb.g, pickedRgb.b);
        if (preview) preview.style.backgroundColor = pickedHex;
        if (hexInput) hexInput.value = pickedHex;
    };

    const openImageEditorTextColorPicker = () => {
        const dim = getImageEditorTextColorDim();
        const picker = getImageEditorTextColorPicker();
        if (!dim || !picker || !imageEditorState) return;
        ensureTextColorPickerState();
        renderImageEditorTextColorPicker();
        dim.hidden = false;
        picker.hidden = false;
    };

    const updateTextColorPickerFromPointer = (target, event) => {
        if (!imageEditorState) return;
        const state = ensureTextColorPickerState();
        if (!state) return;
        const canvas = target === 'hue' ? getImageEditorTextColorHueCanvas() : getImageEditorTextColorSvCanvas();
        if (!canvas) return;

        const rect = canvas.getBoundingClientRect();
        const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
        const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));

        if (target === 'hue') {
            state.h = (x / rect.width) * 360;
        } else {
            state.s = rect.width > 0 ? x / rect.width : 0;
            state.v = rect.height > 0 ? 1 - (y / rect.height) : 0;
        }

        renderImageEditorTextColorPicker();
    };

    const drawTextToImageEditorCanvas = (ctx, text, x, y, style) => {
        const appliedStyle = copyTextStyle(style);
        const fontWeight = appliedStyle.bold ? '700' : '400';
        const fontStyle = appliedStyle.italic ? 'italic' : 'normal';
        const fontSize = clampTextSize(appliedStyle.size);
        const lines = String(text || '').split(/\r?\n/);
        const lineGap = Math.max(4, Math.round(fontSize * 0.35));
        let currentY = y;

        ctx.save();
        ctx.textBaseline = 'top';
        ctx.fillStyle = normalizeHexColor(appliedStyle.color);
        ctx.font = `${fontStyle} ${fontWeight} ${fontSize}px "Malgun Gothic", sans-serif`;

        lines.forEach((line) => {
            const lineText = line || ' ';
            ctx.fillText(lineText, x, currentY);
            const width = ctx.measureText(lineText).width;

            if (appliedStyle.underline) {
                const underlineY = currentY + fontSize + 1;
                ctx.strokeStyle = normalizeHexColor(appliedStyle.color);
                ctx.lineWidth = Math.max(1, Math.round(fontSize / 12));
                ctx.beginPath();
                ctx.moveTo(x, underlineY);
                ctx.lineTo(x + width, underlineY);
                ctx.stroke();
            }

            if (appliedStyle.strike) {
                const strikeY = currentY + Math.round(fontSize * 0.56);
                ctx.strokeStyle = normalizeHexColor(appliedStyle.color);
                ctx.lineWidth = Math.max(1, Math.round(fontSize / 13));
                ctx.beginPath();
                ctx.moveTo(x, strikeY);
                ctx.lineTo(x + width, strikeY);
                ctx.stroke();
            }

            currentY += fontSize + lineGap;
        });

        ctx.restore();
    };

    const updateImageEditorCanvasCursor = () => {
        const overlay = getImageEditorOverlay();
        if (!overlay || !imageEditorState) return;
        if (imageEditorState.tool === 'select') {
            overlay.style.cursor = 'default';
            return;
        }
        if (imageEditorState.tool === 'text') {
            overlay.style.cursor = 'text';
            return;
        }
        overlay.style.cursor = 'crosshair';
    };

    // =============================================
    // 벡터 객체 시스템 (선택/이동/리사이즈/회전)
    // =============================================
    const OBJ_HANDLE_SIZE = 8;

    // 자유 사각형 바운딩박스 재계산
    const updateFreeQuadBBox = (obj) => {
        if (!obj.corners?.length) return;
        const xs = obj.corners.map((c) => c.x);
        const ys = obj.corners.map((c) => c.y);
        obj.x = Math.min(...xs);
        obj.y = Math.min(...ys);
        obj.width = Math.max(1, Math.max(...xs) - obj.x);
        obj.height = Math.max(1, Math.max(...ys) - obj.y);
    };

    // 점이 볼록/오목 다각형 내부에 있는지 판별 (ray-casting)
    const pointInPolygon = (x, y, corners) => {
        let inside = false;
        const n = corners.length;
        for (let i = 0, j = n - 1; i < n; j = i++) {
            const xi = corners[i].x, yi = corners[i].y;
            const xj = corners[j].x, yj = corners[j].y;
            if (((yi > y) !== (yj > y)) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) {
                inside = !inside;
            }
        }
        return inside;
    };
    const OBJ_ROTATE_DIST = 32;
    const D2R = Math.PI / 180;

    const genObjectId = () => `obj_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;

    const getObjCenter = (obj) => ({
        cx: obj.x + obj.width / 2,
        cy: obj.y + obj.height / 2,
    });

    // 월드 좌표 → 객체 로컬 좌표 (회전 역변환)
    const worldToLocal = (snap, wx, wy) => {
        const cx = snap.x + snap.width / 2;
        const cy = snap.y + snap.height / 2;
        const r = -(snap.rotation || 0) * D2R;
        const cos = Math.cos(r), sin = Math.sin(r);
        const dx = wx - cx, dy = wy - cy;
        return { x: dx * cos - dy * sin, y: dx * sin + dy * cos };
    };

    // 핸들 월드 좌표 반환
    // 일반: 9개 (0:TL 1:TC 2:TR 3:MR 4:BR 5:BC 6:BL 7:ML 8:회전)
    // freeQuad: 4개 꼭지점 (0:TL 1:TR 2:BR 3:BL)
    const getObjHandles = (obj) => {
        if (obj.type === 'shape' && obj.style?.type === 'freeQuad' && obj.corners?.length === 4) {
            return obj.corners.map((c) => ({ x: c.x, y: c.y }));
        }
        const { cx, cy } = getObjCenter(obj);
        const hw = obj.width / 2, hh = obj.height / 2;
        const r = (obj.rotation || 0) * D2R;
        const cos = Math.cos(r), sin = Math.sin(r);
        const rot = (lx, ly) => ({
            x: cx + lx * cos - ly * sin,
            y: cy + lx * sin + ly * cos,
        });
        return [
            rot(-hw, -hh), rot(0, -hh), rot(hw, -hh),
            rot(hw,   0),               rot(hw,  hh),
            rot(0,   hh), rot(-hw,  hh), rot(-hw,  0),
            rot(0, -hh - OBJ_ROTATE_DIST),
        ];
    };

    // 핸들 히트 테스트: 0~8 반환, 없으면 -1
    const hitTestHandle = (obj, wx, wy) => {
        const handles = getObjHandles(obj);
        const hit = OBJ_HANDLE_SIZE + 2;
        for (let i = 0; i < handles.length; i++) {
            if (Math.abs(wx - handles[i].x) <= hit && Math.abs(wy - handles[i].y) <= hit) return i;
        }
        return -1;
    };

    // 객체 내부 히트 테스트
    const hitTestObject = (obj, wx, wy) => {
        if (obj.type === 'shape' && obj.style?.type === 'freeQuad' && obj.corners?.length === 4) {
            return pointInPolygon(wx, wy, obj.corners);
        }
        const local = worldToLocal(obj, wx, wy);
        return Math.abs(local.x) <= obj.width / 2 && Math.abs(local.y) <= obj.height / 2;
    };

    // 전체 객체 중 가장 위(마지막)에 있는 객체 반환
    const hitTestObjects = (wx, wy) => {
        if (!imageEditorState?.objects) return null;
        const objs = imageEditorState.objects;
        for (let i = objs.length - 1; i >= 0; i--) {
            if (hitTestObject(objs[i], wx, wy)) return objs[i];
        }
        return null;
    };

    // 핸들 인덱스에 따른 커서 반환
    const handleCursor = (hi) => {
        if (hi === 8) return 'grab';
        return ['nwse-resize', 'ns-resize', 'nesw-resize', 'ew-resize',
                'nwse-resize', 'ns-resize', 'nesw-resize', 'ew-resize'][hi] || 'pointer';
    };

    // 텍스트 객체 생성
    const createTextObject = (text, x, y, style) => {
        const m = measureTextEntryBox(text, style);
        const numLines = String(text || '').split(/\r?\n/).length || 1;
        // drawTextToImageEditorCanvas는 마지막 줄 뒤 lineGap을 그리지 않으므로
        // 실제 텍스트 높이 = N줄 × lineHeight − lineGap(마지막 줄 간격 제외)
        const lineGap = Math.max(4, Math.round(m.fontSize * 0.35));
        const textW = Math.max(10, m.width - m.paddingX * 2);
        const textH = Math.max(m.fontSize, numLines * m.lineHeight - lineGap);
        return {
            id: genObjectId(), type: 'text',
            text,
            x: x + m.paddingX,
            y: y + m.paddingY,
            width: textW, height: textH,
            naturalWidth: textW, naturalHeight: textH,
            rotation: 0, style: copyTextStyle(style),
        };
    };

    // 선그리기 객체 생성 (points는 바운딩박스 기준 상대 좌표로 저장)
    const createDrawObject = (points, style) => {
        if (!points || points.length < 2) return null;
        const xs = points.map((p) => p.x);
        const ys = points.map((p) => p.y);
        const x = Math.min(...xs), y = Math.min(...ys);
        const w = Math.max(10, Math.max(...xs) - x);
        const h = Math.max(10, Math.max(...ys) - y);
        return {
            id: genObjectId(), type: 'draw',
            points: points.map((p) => ({ x: p.x - x, y: p.y - y })),  // 상대 좌표
            x, y, width: w, height: h,
            rotation: 0, style: copyDrawStyle(style),
        };
    };

    // 도형 객체 생성
    const createShapeObject = (x1, y1, x2, y2, style) => {
        const x = Math.min(x1, x2), y = Math.min(y1, y2);
        const w = Math.max(10, Math.abs(x2 - x1));
        const h = Math.max(10, Math.abs(y2 - y1));
        const obj = {
            id: genObjectId(), type: 'shape',
            x, y, width: w, height: h, rotation: 0,
            lineFlipX: x2 < x1, lineFlipY: y2 < y1,
            style: copyShapeStyle(style),
        };
        if (style.type === 'freeQuad') {
            obj.corners = [
                { x: x,     y: y     },  // 좌상
                { x: x + w, y: y     },  // 우상
                { x: x + w, y: y + h },  // 우하
                { x: x,     y: y + h },  // 좌하
            ];
        }
        return obj;
    };

    // 단일 객체를 ctx에 렌더링
    const renderObjectToCtx = (ctx, obj) => {
        if (!ctx || !obj) return;
        const { cx, cy } = getObjCenter(obj);
        const r = (obj.rotation || 0) * D2R;
        ctx.save();
        ctx.translate(cx, cy);
        if (r) ctx.rotate(r);
        ctx.translate(-cx, -cy);
        if (obj.type === 'text') {
            const nw = obj.naturalWidth || obj.width;
            const nh = obj.naturalHeight || obj.height;
            const sx = (nw > 0 && obj.width > 0) ? obj.width / nw : 1;
            const sy = (nh > 0 && obj.height > 0) ? obj.height / nh : 1;
            ctx.save();
            ctx.translate(obj.x, obj.y);
            ctx.scale(sx, sy);
            drawTextToImageEditorCanvas(ctx, obj.text, 0, 0, obj.style);
            ctx.restore();
        } else if (obj.type === 'shape') {
            if (obj.style?.type === 'freeQuad' && obj.corners?.length === 4) {
                // 꼭지점 좌표 직접 사용 (회전 없음)
                const st = obj.style;
                ctx.save();
                ctx.setLineDash(getShapeLineDash(st));
                ctx.lineWidth = st.lineWidth;
                ctx.lineCap = st.lineStyle === 'dot' ? 'round' : 'butt';
                ctx.lineJoin = 'round';
                ctx.beginPath();
                obj.corners.forEach((c, i) => {
                    if (i === 0) ctx.moveTo(c.x, c.y);
                    else ctx.lineTo(c.x, c.y);
                });
                ctx.closePath();
                if (st.fillAlpha > 0) {
                    ctx.fillStyle = colorToRgba(st.fillColor, st.fillAlpha);
                    ctx.fill();
                }
                if (st.strokeAlpha > 0 && st.lineWidth > 0) {
                    ctx.strokeStyle = colorToRgba(st.strokeColor, st.strokeAlpha);
                    ctx.stroke();
                }
                ctx.restore();
            } else {
            const rx1 = obj.lineFlipX ? obj.x + obj.width : obj.x;
            const ry1 = obj.lineFlipY ? obj.y + obj.height : obj.y;
            const rx2 = obj.lineFlipX ? obj.x : obj.x + obj.width;
            const ry2 = obj.lineFlipY ? obj.y : obj.y + obj.height;
            drawShapeOnContext(ctx, { x1: rx1, y1: ry1, x2: rx2, y2: ry2 }, obj.style);
            }
        } else if (obj.type === 'draw' && obj.points?.length >= 2) {
            ctx.beginPath();
            ctx.lineWidth = clampDrawLineWidth(obj.style.lineWidth);
            ctx.strokeStyle = colorToRgba(obj.style.color, obj.style.alpha);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            // points는 상대 좌표 → obj.x/y 더해서 절대 위치로 변환
            obj.points.forEach((pt, i) => {
                const ax = obj.x + pt.x, ay = obj.y + pt.y;
                if (i === 0) ctx.moveTo(ax, ay);
                else ctx.lineTo(ax, ay);
            });
            ctx.stroke();
        }
        ctx.restore();
    };

    // 선택된 객체의 핸들 그리기
    const drawObjectHandles = (octx, obj) => {
        if (!octx || !obj) return;

        // freeQuad: 다각형 윤곽 + 4개 꼭지점 핸들
        if (obj.type === 'shape' && obj.style?.type === 'freeQuad' && obj.corners?.length === 4) {
            octx.save();
            octx.strokeStyle = '#3a7fc1';
            octx.lineWidth = 1.5;
            octx.setLineDash([4, 3]);
            octx.beginPath();
            obj.corners.forEach((c, i) => {
                if (i === 0) octx.moveTo(c.x, c.y);
                else octx.lineTo(c.x, c.y);
            });
            octx.closePath();
            octx.stroke();
            octx.restore();
            const hs = OBJ_HANDLE_SIZE;
            obj.corners.forEach((c) => {
                octx.save();
                octx.fillStyle = '#ffffff';
                octx.strokeStyle = '#3a7fc1';
                octx.lineWidth = 1.5;
                octx.setLineDash([]);
                octx.fillRect(c.x - hs / 2, c.y - hs / 2, hs, hs);
                octx.strokeRect(c.x - hs / 2, c.y - hs / 2, hs, hs);
                octx.restore();
            });
            return;
        }

        const { cx, cy } = getObjCenter(obj);
        const hw = obj.width / 2, hh = obj.height / 2;
        const r = (obj.rotation || 0) * D2R;

        // 선택 테두리
        octx.save();
        octx.translate(cx, cy);
        if (r) octx.rotate(r);
        octx.strokeStyle = '#3a7fc1';
        octx.lineWidth = 1.5;
        octx.setLineDash([]);
        octx.strokeRect(-hw, -hh, obj.width, obj.height);
        // 회전 연결선
        octx.beginPath();
        octx.moveTo(0, -hh);
        octx.lineTo(0, -hh - OBJ_ROTATE_DIST);
        octx.lineWidth = 1;
        octx.stroke();
        octx.restore();

        // 핸들 사각형/원
        const handles = getObjHandles(obj);
        handles.forEach((h, i) => {
            const hs = OBJ_HANDLE_SIZE;
            octx.save();
            octx.fillStyle = '#ffffff';
            octx.strokeStyle = '#3a7fc1';
            octx.lineWidth = 1.5;
            octx.setLineDash([]);
            if (i === 8) {
                octx.beginPath();
                octx.arc(h.x, h.y, hs / 2, 0, Math.PI * 2);
                octx.fill();
                octx.stroke();
            } else {
                octx.fillRect(h.x - hs / 2, h.y - hs / 2, hs, hs);
                octx.strokeRect(h.x - hs / 2, h.y - hs / 2, hs, hs);
            }
            octx.restore();
        });
    };

    // 오버레이에 모든 객체 + 선택 핸들 렌더링
    const renderObjectsOnOverlay = (octx) => {
        if (!imageEditorState) return;
        if (!octx) {
            const ov = getImageEditorOverlay();
            if (!ov) return;
            octx = ov.getContext('2d');
            if (!octx) return;
        }
        const objects = imageEditorState.objects || [];
        const selectedId = imageEditorState.selectedObjectId;
        objects.forEach((obj) => {
            renderObjectToCtx(octx, obj);
            if (obj.id === selectedId) drawObjectHandles(octx, obj);
        });
    };

    // 모든 객체를 메인 캔버스에 굽기(commit)
    const commitObjectsToCanvas = () => {
        if (!imageEditorState) return;
        const objects = imageEditorState.objects || [];
        if (!objects.length) return;
        const canvas = getImageEditorCanvas();
        const ctx = canvas?.getContext('2d');
        if (!ctx) return;
        objects.forEach((obj) => renderObjectToCtx(ctx, obj));
        imageEditorState.objects = [];
        imageEditorState.selectedObjectId = null;
    };

    // 선택된 객체 삭제
    const deleteSelectedObject = () => {
        if (!imageEditorState?.selectedObjectId) return false;
        const before = (imageEditorState.objects || []).length;
        imageEditorState.objects = (imageEditorState.objects || []).filter(
            (o) => o.id !== imageEditorState.selectedObjectId
        );
        imageEditorState.selectedObjectId = null;
        if ((imageEditorState.objects || []).length !== before) {
            drawImageEditorOverlay();
            pushImageEditorHistory();
            return true;
        }
        return false;
    };

    // 선택 도구 활성화
    const activateSelectTool = () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        hideImageEditorTextPanel();
        hideImageEditorShapePanel();
        hideImageEditorDrawPanel();
        imageEditorState.tool = 'select';
        imageEditorState.previewRect = null;
        imageEditorState.cropRect = null;
        hideCropActionButton();
        updateToolButtonActiveStates();
        drawImageEditorOverlay();
    };

    // 선택 도구 커서 업데이트 (hover 시)
    const updateSelectToolCursor = (p) => {
        const overlay = getImageEditorOverlay();
        if (!overlay || !imageEditorState) return;
        const selectedId = imageEditorState.selectedObjectId;
        const selectedObj = selectedId
            ? (imageEditorState.objects || []).find((o) => o.id === selectedId)
            : null;
        if (selectedObj) {
            const hi = hitTestHandle(selectedObj, p.x, p.y);
            if (hi >= 0) {
                const isFQ = selectedObj.type === 'shape' && selectedObj.style?.type === 'freeQuad';
                overlay.style.cursor = isFQ ? 'move' : handleCursor(hi);
                return;
            }
        }
        const hit = hitTestObjects(p.x, p.y);
        overlay.style.cursor = hit ? 'move' : 'default';
    };

    // 핸들 드래그로 리사이즈
    const applyHandleResize = (obj, ds, p) => {
        // freeQuad: 해당 꼭지점만 이동
        if (obj.type === 'shape' && obj.style?.type === 'freeQuad'
            && obj.corners?.length === 4 && ds.objSnapshot.corners?.length === 4) {
            const hi = ds.handleIndex;
            if (hi >= 0 && hi < 4) {
                const dx = p.x - ds.startX;
                const dy = p.y - ds.startY;
                obj.corners = ds.objSnapshot.corners.map((c, i) =>
                    i === hi ? { x: c.x + dx, y: c.y + dy } : { x: c.x, y: c.y }
                );
                updateFreeQuadBBox(obj);
            }
            return;
        }
        const snap = ds.objSnapshot;
        const snapCx = snap.x + snap.width / 2;
        const snapCy = snap.y + snap.height / 2;
        const local = worldToLocal(snap, p.x, p.y);
        const hw = snap.width / 2, hh = snap.height / 2;
        const hi = ds.handleIndex;

        let nl = -hw, nr = hw, nt = -hh, nb = hh;
        if (hi === 0) { nl = local.x; nt = local.y; }
        else if (hi === 1) { nt = local.y; }
        else if (hi === 2) { nr = local.x; nt = local.y; }
        else if (hi === 3) { nr = local.x; }
        else if (hi === 4) { nr = local.x; nb = local.y; }
        else if (hi === 5) { nb = local.y; }
        else if (hi === 6) { nl = local.x; nb = local.y; }
        else if (hi === 7) { nl = local.x; }

        const newW = Math.max(10, nr - nl);
        const newH = Math.max(10, nb - nt);
        const lCx = (nl + nr) / 2;
        const lCy = (nt + nb) / 2;
        const r = (snap.rotation || 0) * D2R;
        const cos = Math.cos(r), sin = Math.sin(r);
        const newCx = snapCx + lCx * cos - lCy * sin;
        const newCy = snapCy + lCx * sin + lCy * cos;
        obj.x = newCx - newW / 2;
        obj.y = newCy - newH / 2;
        obj.width = newW;
        obj.height = newH;

        // 텍스트: 바운딩박스만 변경, 렌더 시 ctx.scale로 늘림/찌그러뜨림
        // 선그리기: 점들도 새 바운딩박스 크기에 맞춰 비례 스케일
        if (obj.type === 'draw' && ds.objSnapshot.points
            && ds.objSnapshot.width > 0 && ds.objSnapshot.height > 0) {
            const scaleX = newW / ds.objSnapshot.width;
            const scaleY = newH / ds.objSnapshot.height;
            obj.points = ds.objSnapshot.points.map((pt) => ({
                x: pt.x * scaleX,
                y: pt.y * scaleY,
            }));
        }
    };

    // 활성 도구 버튼 강조 표시
    const updateToolButtonActiveStates = () => {
        if (!imageEditorModal || !imageEditorState) return;
        const tool = imageEditorState.tool;
        imageEditorModal.querySelectorAll('.image-edit-tools .tool-btn[data-action]').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.action === tool);
        });
    };

    const getTextEntryFontCss = (style) => {
        const appliedStyle = copyTextStyle(style);
        const fontWeight = appliedStyle.bold ? '700' : '400';
        const fontStyle = appliedStyle.italic ? 'italic' : 'normal';
        const fontSize = clampTextSize(appliedStyle.size);
        return `${fontStyle} ${fontWeight} ${fontSize}px "Malgun Gothic", sans-serif`;
    };

    const measureTextEntryBox = (text, style) => {
        const appliedStyle = copyTextStyle(style);
        const fontSize = clampTextSize(appliedStyle.size);
        const lineHeight = Math.max(14, Math.round(fontSize * 1.35));
        const lines = String(text ?? '').split(/\r?\n/);
        const textLines = lines.length ? lines : [''];
        let widest = Math.max(18, Math.round(fontSize * 1.6));

        if (textMeasureContext) {
            textMeasureContext.font = getTextEntryFontCss(appliedStyle);
            textLines.forEach((line) => {
                const width = textMeasureContext.measureText(line || ' ').width;
                widest = Math.max(widest, Math.ceil(width));
            });
        } else {
            widest = Math.max(widest, Math.ceil((textLines[0] || ' ').length * fontSize * 0.65));
        }

        const paddingX = Math.max(8, Math.round(fontSize * 0.45));
        const paddingY = Math.max(6, Math.round(fontSize * 0.32));
        const minWidth = Math.max(44, Math.round(fontSize * 2.2));
        const minHeight = Math.max(28, Math.round(fontSize * 1.55));
        const width = Math.max(minWidth, Math.round(widest + paddingX * 2));
        const height = Math.max(minHeight, Math.round(textLines.length * lineHeight + paddingY * 2));

        return { width, height, lineHeight, fontSize, paddingX, paddingY };
    };

    const fitTextEntryIntoCanvas = (x, y, width, height) => {
        const canvas = getImageEditorCanvas();
        if (!canvas) return { x, y, width, height };
        const w = Math.min(Math.max(24, width), canvas.width);
        const h = Math.min(Math.max(20, height), canvas.height);
        const px = Math.max(0, Math.min(Math.round(x), Math.max(0, canvas.width - w)));
        const py = Math.max(0, Math.min(Math.round(y), Math.max(0, canvas.height - h)));
        return { x: px, y: py, width: w, height: h };
    };

    const positionImageEditorTextEntryInViewport = () => {
        const entry = getImageEditorTextEntry();
        const overlay = getImageEditorOverlay();
        if (!entry || !overlay || entry.hidden) return;

        const x = Number(entry.dataset.x || 0);
        const y = Number(entry.dataset.y || 0);
        const w = Number(entry.dataset.boxWidth || 0);
        const h = Number(entry.dataset.boxHeight || 0);

        const rect = overlay.getBoundingClientRect();
        const scaleX = overlay.width > 0 ? (rect.width / overlay.width) : 1;
        const scaleY = overlay.height > 0 ? (rect.height / overlay.height) : 1;

        const left = rect.left + x * scaleX;
        const top = rect.top + y * scaleY;

        entry.style.left = `${Math.round(left)}px`;
        entry.style.top = `${Math.round(top)}px`;
        if (w > 0) entry.style.width = `${Math.round(w * scaleX)}px`;
        if (h > 0) entry.style.height = `${Math.round(h * scaleY)}px`;
    };

    const syncImageEditorTextEntryWithStyle = () => {
        const entry = getImageEditorTextEntry();
        const input = getImageEditorTextEntryInput();
        if (!entry || !input || entry.hidden || !imageEditorState) return;

        const style = ensureImageEditorTextStyle();
        const metrics = measureTextEntryBox(input.value || '', style);
        const fit = fitTextEntryIntoCanvas(
            Number(entry.dataset.x || 0),
            Number(entry.dataset.y || 0),
            metrics.width,
            metrics.height
        );

        entry.dataset.x = String(fit.x);
        entry.dataset.y = String(fit.y);
        entry.dataset.boxWidth = String(fit.width);
        entry.dataset.boxHeight = String(fit.height);

        const overlay = getImageEditorOverlay();
        const overlayRect = overlay ? overlay.getBoundingClientRect() : null;
        const scaleX = (overlay && overlay.width > 0 && overlayRect) ? (overlayRect.width / overlay.width) : 1;
        const scaleY = (overlay && overlay.height > 0 && overlayRect) ? (overlayRect.height / overlay.height) : 1;

        const cssFontSize = Math.max(8, Math.round(metrics.fontSize * scaleX));
        const fontWeight = style.bold ? '700' : '400';
        const fontStyleCss = style.italic ? 'italic' : 'normal';

        entry.style.borderWidth = `${Math.max(1, Math.round(style.size / 22 * scaleX))}px`;
        positionImageEditorTextEntryInViewport();

        input.style.font = `${fontStyleCss} ${fontWeight} ${cssFontSize}px "Malgun Gothic", sans-serif`;
        input.style.color = style.color;
        input.style.caretColor = style.color;
        input.style.lineHeight = `${Math.round(metrics.lineHeight * scaleY)}px`;
        input.style.padding = `${Math.round(metrics.paddingY * scaleY)}px ${Math.round(metrics.paddingX * scaleX)}px`;
        input.style.textDecoration = `${style.underline ? 'underline ' : ''}${style.strike ? 'line-through' : ''}`.trim() || 'none';
    };

    const showImageEditorTextEntry = (point) => {
        if (!imageEditorState || !point) return;
        const entry = getImageEditorTextEntry();
        const input = getImageEditorTextEntryInput();
        if (!entry || !input) return;

        const style = ensureImageEditorTextStyle();
        const baseMetrics = measureTextEntryBox('', style);
        const fit = fitTextEntryIntoCanvas(point.x, point.y, baseMetrics.width, baseMetrics.height);

        entry.hidden = false;
        entry.dataset.x = String(fit.x);
        entry.dataset.y = String(fit.y);
        entry.dataset.boxWidth = String(fit.width);
        entry.dataset.boxHeight = String(fit.height);
        input.value = '';
        syncImageEditorTextEntryWithStyle();
        imageEditorState.textEntryIgnoreBlurUntil = Date.now() + 260;
        requestAnimationFrame(() => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            if (entry.hidden) return;
            input.focus();
            const end = input.value.length;
            input.setSelectionRange(end, end);
        });
        imageEditorState.textEntryActive = true;
    };

    const closeImageEditorTextEntry = ({ commit = true } = {}) => {
        if (!imageEditorState) return false;
        const entry = getImageEditorTextEntry();
        const input = getImageEditorTextEntryInput();
        if (!entry || !input || entry.hidden) return false;
        if (entry.dataset.closing === '1') return false;

        entry.dataset.closing = '1';
        const text = input.value || '';
        const x = Number(entry.dataset.x || 0);
        const y = Number(entry.dataset.y || 0);
        const shouldDraw = commit && text.trim() !== '';

        if (shouldDraw) {
            const obj = createTextObject(text, x, y, ensureImageEditorTextStyle());
            if (!imageEditorState.objects) imageEditorState.objects = [];
            imageEditorState.objects.push(obj);
            imageEditorState.selectedObjectId = obj.id;
            imageEditorState.tool = 'select';
            updateToolButtonActiveStates();
            drawImageEditorOverlay();
            pushImageEditorHistory();
        }

        input.value = '';
        entry.hidden = true;
        entry.style.left = '';
        entry.style.top = '';
        entry.style.width = '';
        entry.style.height = '';
        delete entry.dataset.boxWidth;
        delete entry.dataset.boxHeight;
        delete entry.dataset.closing;
        imageEditorState.textEntryActive = false;
        return shouldDraw;
    };

    const refreshImageEditorTextEntrySize = () => {
        const entry = getImageEditorTextEntry();
        if (!entry || entry.hidden || !imageEditorState) return;
        syncImageEditorTextEntryWithStyle();
    };

    const toggleImageEditorTextPanel = () => {
        const panel = getImageEditorTextPanel();
        if (!panel || !imageEditorState) return;
        hideImageEditorShapePanel();
        hideImageEditorDrawPanel();
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            syncSelectedObjectStyleToState();
            updateImageEditorTextStyleUi();
            positionImageEditorTextPanel();
        } else {
            hideImageEditorTextColorPicker();
        }
    };

    const toggleImageEditorShapePanel = () => {
        const panel = getImageEditorShapePanel();
        if (!panel || !imageEditorState) return;
        hideImageEditorTextPanel();
        hideImageEditorDrawPanel();
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            syncSelectedObjectStyleToState();
            updateImageEditorShapeStyleUi();
            positionImageEditorShapePanel();
        } else {
            hideImageEditorShapeColorPicker();
        }
    };

    const hideImageEditorShapeColorPicker = () => {
        const dim = getImageEditorShapeColorDim();
        const picker = getImageEditorShapeColorPicker();
        if (dim) dim.hidden = true;
        if (picker) picker.hidden = true;
    };

    const hideImageEditorShapePanel = () => {
        const panel = getImageEditorShapePanel();
        if (!panel) return;
        panel.hidden = true;
        hideImageEditorShapeColorPicker();
    };

    const positionImageEditorShapePanel = () => {
        const panel = getImageEditorShapePanel();
        const button = getImageEditorShapeSettingButton();
        if (!panel || !button || panel.hidden) return;

        const rect = button.getBoundingClientRect();
        const panelWidth = panel.offsetWidth || 250;
        const panelHeight = panel.offsetHeight || 340;
        let left = rect.left - panelWidth + rect.width;
        let top = rect.bottom + 8;
        left = Math.max(12, Math.min(left, window.innerWidth - panelWidth - 12));
        if (top + panelHeight > window.innerHeight - 12) {
            top = Math.max(12, rect.top - panelHeight - 8);
        }

        panel.style.left = `${Math.round(left)}px`;
        panel.style.top = `${Math.round(top)}px`;
    };

    const ensureShapeColorPickerState = () => {
        if (!imageEditorState) return null;
        const style = ensureImageEditorShapeStyle();
        if (!imageEditorState.shapeColorPicker) {
            const target = style.colorTarget === 'fill' ? 'fill' : 'stroke';
            const colorKey = getShapeColorKeyByTarget(target);
            const rgb = hexToRgb(style[colorKey]);
            const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
            imageEditorState.shapeColorPicker = {
                target,
                h: hsv.h,
                s: hsv.s,
                v: hsv.v,
                dragging: '',
            };
        }
        return imageEditorState.shapeColorPicker;
    };

    const updateImageEditorShapeStyleUi = () => {
        if (!imageEditorModal || !imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        const lineWidthInput = getImageEditorShapeWidthInput();
        const lineWidthRange = getImageEditorShapeWidthRange();
        if (lineWidthInput) lineWidthInput.value = `${style.lineWidth}px`;
        if (lineWidthRange) lineWidthRange.value = String(style.lineWidth);

        getImageEditorShapeTypeButtons().forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.shape === style.type);
        });
        getImageEditorShapeLineStyleButtons().forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.lineStyle === style.lineStyle);
        });
        getImageEditorShapeColorTabButtons().forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.target === style.colorTarget);
        });

        const colorKey = getShapeColorKeyByTarget(style.colorTarget);
        const alphaKey = getShapeAlphaKeyByTarget(style.colorTarget);
        const activeColor = normalizeHexColor(style[colorKey], '#000000');
        const activeAlpha = normalizeAlpha(style[alphaKey], 1);

        getImageEditorShapePaletteButtons().forEach((btn) => {
            const isTransparentChip = btn.dataset.color === 'transparent';
            let active = false;
            if (isTransparentChip) {
                active = activeAlpha <= 0;
            } else {
                active = normalizeHexColor(btn.dataset.color || '', '#000000') === activeColor && activeAlpha > 0;
            }
            btn.classList.toggle('active', active);
        });

        const preview = getImageEditorShapeColorPreview();
        const hexInput = getImageEditorShapeColorHexInput();
        const opacityRange = getImageEditorShapeOpacityRange();
        const opacityLabel = getImageEditorShapeOpacityLabel();
        const inlineOpacityRange = getImageEditorShapeInlineOpacityRange();
        const inlineOpacityLabel = getImageEditorShapeInlineOpacityLabel();
        if (preview) preview.style.backgroundColor = colorToRgba(activeColor, activeAlpha);
        if (hexInput) hexInput.value = activeColor;
        if (opacityRange) opacityRange.value = String(Math.round(activeAlpha * 100));
        if (opacityLabel) opacityLabel.textContent = `${Math.round(activeAlpha * 100)}%`;
        if (inlineOpacityRange) inlineOpacityRange.value = String(Math.round(activeAlpha * 100));
        if (inlineOpacityLabel) inlineOpacityLabel.textContent = `${Math.round(activeAlpha * 100)}%`;
        applyStateStyleToSelectedObject('shape');
    };

    const setImageEditorShapeType = (type) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        style.type = ['rect', 'roundRect', 'ellipse', 'line', 'freeQuad'].includes(String(type)) ? String(type) : style.type;
        updateImageEditorShapeStyleUi();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const setImageEditorShapeLineStyle = (lineStyle) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        style.lineStyle = ['solid', 'dash', 'dot'].includes(String(lineStyle)) ? String(lineStyle) : style.lineStyle;
        updateImageEditorShapeStyleUi();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const setImageEditorShapeLineWidth = (lineWidth) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        style.lineWidth = clampShapeLineWidth(lineWidth);
        updateImageEditorShapeStyleUi();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const setImageEditorShapeColorTarget = (target) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        style.colorTarget = target === 'fill' ? 'fill' : 'stroke';
        const colorKey = getShapeColorKeyByTarget(style.colorTarget);
        const rgb = hexToRgb(style[colorKey]);
        const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
        imageEditorState.shapeColorPicker = {
            ...(imageEditorState.shapeColorPicker || {}),
            target: style.colorTarget,
            h: hsv.h,
            s: hsv.s,
            v: hsv.v,
            dragging: '',
        };
        updateImageEditorShapeStyleUi();
        renderImageEditorShapeColorPicker();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const applyImageEditorShapeColor = (hexColor, alphaValue = null) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        const target = style.colorTarget === 'fill' ? 'fill' : 'stroke';
        const colorKey = getShapeColorKeyByTarget(target);
        const alphaKey = getShapeAlphaKeyByTarget(target);

        style[colorKey] = normalizeHexColor(hexColor, style[colorKey]);
        if (alphaValue !== null) {
            style[alphaKey] = normalizeAlpha(alphaValue, style[alphaKey]);
        }
        const rgb = hexToRgb(style[colorKey]);
        const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
        imageEditorState.shapeColorPicker = {
            ...(imageEditorState.shapeColorPicker || {}),
            target,
            h: hsv.h,
            s: hsv.s,
            v: hsv.v,
            dragging: '',
        };
        updateImageEditorShapeStyleUi();
        renderImageEditorShapeColorPicker();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const applyImageEditorShapeTransparency = (percent) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorShapeStyle();
        const target = style.colorTarget === 'fill' ? 'fill' : 'stroke';
        const alphaKey = getShapeAlphaKeyByTarget(target);
        style[alphaKey] = normalizeAlpha(Number(percent) / 100, 1);
        updateImageEditorShapeStyleUi();
        renderImageEditorShapeColorPicker();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const renderImageEditorShapeColorPicker = () => {
        if (!imageEditorState || !imageEditorModal) return;
        const pickerState = ensureShapeColorPickerState();
        const svCanvas = getImageEditorShapeColorSvCanvas();
        const hueCanvas = getImageEditorShapeColorHueCanvas();
        const style = ensureImageEditorShapeStyle();
        if (!pickerState || !svCanvas || !hueCanvas) return;

        const svCtx = svCanvas.getContext('2d');
        const hueCtx = hueCanvas.getContext('2d');
        if (!svCtx || !hueCtx) return;

        const hueRgb = hsvToRgb(pickerState.h, 1, 1);
        svCtx.clearRect(0, 0, svCanvas.width, svCanvas.height);
        svCtx.fillStyle = rgbToHex(hueRgb.r, hueRgb.g, hueRgb.b);
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const whiteGrad = svCtx.createLinearGradient(0, 0, svCanvas.width, 0);
        whiteGrad.addColorStop(0, '#ffffff');
        whiteGrad.addColorStop(1, 'rgba(255,255,255,0)');
        svCtx.fillStyle = whiteGrad;
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const blackGrad = svCtx.createLinearGradient(0, 0, 0, svCanvas.height);
        blackGrad.addColorStop(0, 'rgba(0,0,0,0)');
        blackGrad.addColorStop(1, '#000000');
        svCtx.fillStyle = blackGrad;
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const markerX = pickerState.s * svCanvas.width;
        const markerY = (1 - pickerState.v) * svCanvas.height;
        svCtx.save();
        svCtx.strokeStyle = '#ffffff';
        svCtx.lineWidth = 2;
        svCtx.beginPath();
        svCtx.arc(markerX, markerY, 6, 0, Math.PI * 2);
        svCtx.stroke();
        svCtx.strokeStyle = '#000000';
        svCtx.lineWidth = 1;
        svCtx.beginPath();
        svCtx.arc(markerX, markerY, 7, 0, Math.PI * 2);
        svCtx.stroke();
        svCtx.restore();

        const hueGrad = hueCtx.createLinearGradient(0, 0, hueCanvas.width, 0);
        hueGrad.addColorStop(0, '#ff0000');
        hueGrad.addColorStop(1 / 6, '#ffff00');
        hueGrad.addColorStop(2 / 6, '#00ff00');
        hueGrad.addColorStop(3 / 6, '#00ffff');
        hueGrad.addColorStop(4 / 6, '#0000ff');
        hueGrad.addColorStop(5 / 6, '#ff00ff');
        hueGrad.addColorStop(1, '#ff0000');
        hueCtx.clearRect(0, 0, hueCanvas.width, hueCanvas.height);
        hueCtx.fillStyle = hueGrad;
        hueCtx.fillRect(0, 0, hueCanvas.width, hueCanvas.height);

        const hueX = (pickerState.h / 360) * hueCanvas.width;
        hueCtx.save();
        hueCtx.strokeStyle = '#ffffff';
        hueCtx.lineWidth = 2;
        hueCtx.strokeRect(hueX - 2, 0, 4, hueCanvas.height);
        hueCtx.restore();

        const pickedRgb = hsvToRgb(pickerState.h, pickerState.s, pickerState.v);
        const pickedHex = rgbToHex(pickedRgb.r, pickedRgb.g, pickedRgb.b);
        const colorKey = getShapeColorKeyByTarget(style.colorTarget);
        const alphaKey = getShapeAlphaKeyByTarget(style.colorTarget);
        style[colorKey] = pickedHex;

        const preview = getImageEditorShapeColorPreview();
        const hexInput = getImageEditorShapeColorHexInput();
        const opacityRange = getImageEditorShapeOpacityRange();
        const opacityLabel = getImageEditorShapeOpacityLabel();
        const alpha = normalizeAlpha(style[alphaKey], 1);
        if (preview) preview.style.backgroundColor = colorToRgba(pickedHex, alpha);
        if (hexInput) hexInput.value = pickedHex;
        if (opacityRange) opacityRange.value = String(Math.round(alpha * 100));
        if (opacityLabel) opacityLabel.textContent = `${Math.round(alpha * 100)}%`;
    };

    const openImageEditorShapeColorPicker = () => {
        const dim = getImageEditorShapeColorDim();
        const picker = getImageEditorShapeColorPicker();
        if (!dim || !picker || !imageEditorState) return;
        ensureShapeColorPickerState();
        updateImageEditorShapeStyleUi();
        renderImageEditorShapeColorPicker();
        dim.hidden = false;
        picker.hidden = false;
    };

    const updateShapeColorPickerFromPointer = (target, event) => {
        if (!imageEditorState) return;
        const state = ensureShapeColorPickerState();
        if (!state) return;
        const canvas = target === 'hue' ? getImageEditorShapeColorHueCanvas() : getImageEditorShapeColorSvCanvas();
        if (!canvas) return;

        const rect = canvas.getBoundingClientRect();
        const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
        const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));

        if (target === 'hue') {
            state.h = (x / rect.width) * 360;
        } else {
            state.s = rect.width > 0 ? x / rect.width : 0;
            state.v = rect.height > 0 ? 1 - (y / rect.height) : 0;
        }
    };

    const hideImageEditorDrawColorPicker = () => {
        const dim = getImageEditorDrawColorDim();
        const picker = getImageEditorDrawColorPicker();
        if (dim) dim.hidden = true;
        if (picker) picker.hidden = true;
    };

    const hideImageEditorDrawPanel = () => {
        const panel = getImageEditorDrawPanel();
        if (!panel) return;
        panel.hidden = true;
        hideImageEditorDrawColorPicker();
    };

    const positionImageEditorDrawPanel = () => {
        const panel = getImageEditorDrawPanel();
        const button = getImageEditorDrawSettingButton();
        if (!panel || !button || panel.hidden) return;

        const rect = button.getBoundingClientRect();
        const panelWidth = panel.offsetWidth || 238;
        const panelHeight = panel.offsetHeight || 286;
        let left = rect.left - panelWidth + rect.width;
        let top = rect.bottom + 8;
        left = Math.max(12, Math.min(left, window.innerWidth - panelWidth - 12));
        if (top + panelHeight > window.innerHeight - 12) {
            top = Math.max(12, rect.top - panelHeight - 8);
        }

        panel.style.left = `${Math.round(left)}px`;
        panel.style.top = `${Math.round(top)}px`;
    };

    const ensureDrawColorPickerState = () => {
        if (!imageEditorState) return null;
        const style = ensureImageEditorDrawStyle();
        if (!imageEditorState.drawColorPicker) {
            const rgb = hexToRgb(style.color);
            const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
            imageEditorState.drawColorPicker = {
                h: hsv.h,
                s: hsv.s,
                v: hsv.v,
                dragging: '',
            };
        }
        return imageEditorState.drawColorPicker;
    };

    const updateImageEditorDrawStyleUi = () => {
        if (!imageEditorModal || !imageEditorState) return;
        const style = ensureImageEditorDrawStyle();
        const widthInput = getImageEditorDrawWidthInput();
        const widthRange = getImageEditorDrawWidthRange();
        const preview = getImageEditorDrawColorPreview();
        const hexInput = getImageEditorDrawColorHexInput();
        const opacityRange = getImageEditorDrawOpacityRange();
        const opacityLabel = getImageEditorDrawOpacityLabel();
        const inlineOpacityRange = getImageEditorDrawInlineOpacityRange();
        const inlineOpacityLabel = getImageEditorDrawInlineOpacityLabel();
        if (widthInput) widthInput.value = `${style.lineWidth}px`;
        if (widthRange) widthRange.value = String(style.lineWidth);
        if (preview) preview.style.backgroundColor = colorToRgba(style.color, style.alpha);
        if (hexInput) hexInput.value = style.color;
        if (opacityRange) opacityRange.value = String(Math.round(style.alpha * 100));
        if (opacityLabel) opacityLabel.textContent = `${Math.round(style.alpha * 100)}%`;
        if (inlineOpacityRange) inlineOpacityRange.value = String(Math.round(style.alpha * 100));
        if (inlineOpacityLabel) inlineOpacityLabel.textContent = `${Math.round(style.alpha * 100)}%`;

        getImageEditorDrawPaletteButtons().forEach((btn) => {
            const color = String(btn.dataset.color || '').trim().toLowerCase();
            const active = normalizeHexColor(color, '') === style.color && style.alpha > 0;
            btn.classList.toggle('active', active);
        });
        applyStateStyleToSelectedObject('draw');
    };

    const setImageEditorDrawLineWidth = (value) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorDrawStyle();
        style.lineWidth = clampDrawLineWidth(value);
        updateImageEditorDrawStyleUi();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const applyImageEditorDrawColor = (hexColor, alphaValue = null) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorDrawStyle();
        style.color = normalizeHexColor(hexColor, style.color);
        if (alphaValue !== null) {
            style.alpha = normalizeAlpha(alphaValue, style.alpha);
        }
        const rgb = hexToRgb(style.color);
        const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
        imageEditorState.drawColorPicker = {
            ...(imageEditorState.drawColorPicker || {}),
            h: hsv.h,
            s: hsv.s,
            v: hsv.v,
            dragging: '',
        };
        updateImageEditorDrawStyleUi();
        renderImageEditorDrawColorPicker();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const applyImageEditorDrawTransparency = (percent) => {
        if (!imageEditorState) return;
        const style = ensureImageEditorDrawStyle();
        style.alpha = normalizeAlpha(Number(percent) / 100, 1);
        updateImageEditorDrawStyleUi();
        renderImageEditorDrawColorPicker();
        if (imageEditorState.tool === 'select') pushImageEditorHistory();
    };

    const renderImageEditorDrawColorPicker = () => {
        if (!imageEditorState || !imageEditorModal) return;
        const pickerState = ensureDrawColorPickerState();
        const svCanvas = getImageEditorDrawColorSvCanvas();
        const hueCanvas = getImageEditorDrawColorHueCanvas();
        const style = ensureImageEditorDrawStyle();
        if (!pickerState || !svCanvas || !hueCanvas) return;

        const svCtx = svCanvas.getContext('2d');
        const hueCtx = hueCanvas.getContext('2d');
        if (!svCtx || !hueCtx) return;

        const hueRgb = hsvToRgb(pickerState.h, 1, 1);
        svCtx.clearRect(0, 0, svCanvas.width, svCanvas.height);
        svCtx.fillStyle = rgbToHex(hueRgb.r, hueRgb.g, hueRgb.b);
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const whiteGrad = svCtx.createLinearGradient(0, 0, svCanvas.width, 0);
        whiteGrad.addColorStop(0, '#ffffff');
        whiteGrad.addColorStop(1, 'rgba(255,255,255,0)');
        svCtx.fillStyle = whiteGrad;
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const blackGrad = svCtx.createLinearGradient(0, 0, 0, svCanvas.height);
        blackGrad.addColorStop(0, 'rgba(0,0,0,0)');
        blackGrad.addColorStop(1, '#000000');
        svCtx.fillStyle = blackGrad;
        svCtx.fillRect(0, 0, svCanvas.width, svCanvas.height);

        const markerX = pickerState.s * svCanvas.width;
        const markerY = (1 - pickerState.v) * svCanvas.height;
        svCtx.save();
        svCtx.strokeStyle = '#ffffff';
        svCtx.lineWidth = 2;
        svCtx.beginPath();
        svCtx.arc(markerX, markerY, 6, 0, Math.PI * 2);
        svCtx.stroke();
        svCtx.strokeStyle = '#000000';
        svCtx.lineWidth = 1;
        svCtx.beginPath();
        svCtx.arc(markerX, markerY, 7, 0, Math.PI * 2);
        svCtx.stroke();
        svCtx.restore();

        const hueGrad = hueCtx.createLinearGradient(0, 0, hueCanvas.width, 0);
        hueGrad.addColorStop(0, '#ff0000');
        hueGrad.addColorStop(1 / 6, '#ffff00');
        hueGrad.addColorStop(2 / 6, '#00ff00');
        hueGrad.addColorStop(3 / 6, '#00ffff');
        hueGrad.addColorStop(4 / 6, '#0000ff');
        hueGrad.addColorStop(5 / 6, '#ff00ff');
        hueGrad.addColorStop(1, '#ff0000');
        hueCtx.clearRect(0, 0, hueCanvas.width, hueCanvas.height);
        hueCtx.fillStyle = hueGrad;
        hueCtx.fillRect(0, 0, hueCanvas.width, hueCanvas.height);

        const hueX = (pickerState.h / 360) * hueCanvas.width;
        hueCtx.save();
        hueCtx.strokeStyle = '#ffffff';
        hueCtx.lineWidth = 2;
        hueCtx.strokeRect(hueX - 2, 0, 4, hueCanvas.height);
        hueCtx.restore();

        const pickedRgb = hsvToRgb(pickerState.h, pickerState.s, pickerState.v);
        const pickedHex = rgbToHex(pickedRgb.r, pickedRgb.g, pickedRgb.b);
        style.color = pickedHex;
        const preview = getImageEditorDrawColorPreview();
        const hexInput = getImageEditorDrawColorHexInput();
        const alpha = normalizeAlpha(style.alpha, 1);
        if (preview) preview.style.backgroundColor = colorToRgba(pickedHex, alpha);
        if (hexInput) hexInput.value = pickedHex;
    };

    const openImageEditorDrawColorPicker = () => {
        const dim = getImageEditorDrawColorDim();
        const picker = getImageEditorDrawColorPicker();
        if (!dim || !picker || !imageEditorState) return;
        ensureDrawColorPickerState();
        updateImageEditorDrawStyleUi();
        renderImageEditorDrawColorPicker();
        dim.hidden = false;
        picker.hidden = false;
    };

    const updateDrawColorPickerFromPointer = (target, event) => {
        if (!imageEditorState) return;
        const state = ensureDrawColorPickerState();
        if (!state) return;
        const canvas = target === 'hue' ? getImageEditorDrawColorHueCanvas() : getImageEditorDrawColorSvCanvas();
        if (!canvas) return;

        const rect = canvas.getBoundingClientRect();
        const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
        const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
        if (target === 'hue') {
            state.h = (x / rect.width) * 360;
        } else {
            state.s = rect.width > 0 ? x / rect.width : 0;
            state.v = rect.height > 0 ? 1 - (y / rect.height) : 0;
        }
    };

    const toggleImageEditorDrawPanel = () => {
        const panel = getImageEditorDrawPanel();
        if (!panel || !imageEditorState) return;
        hideImageEditorTextPanel();
        hideImageEditorShapePanel();
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            syncSelectedObjectStyleToState();
            updateImageEditorDrawStyleUi();
            positionImageEditorDrawPanel();
        } else {
            hideImageEditorDrawColorPicker();
        }
    };

    const getShapeLineDash = (style) => {
        const lineWidth = clampShapeLineWidth(style.lineWidth);
        if (style.lineStyle === 'dash') {
            return [Math.max(6, lineWidth * 4), Math.max(4, lineWidth * 2.5)];
        }
        if (style.lineStyle === 'dot') {
            return [Math.max(1, lineWidth), Math.max(4, lineWidth * 2.2)];
        }
        return [];
    };

    const buildShapePath = (ctx, points, style) => {
        if (!ctx || !points || !style) return;
        const x1 = Number(points.x1);
        const y1 = Number(points.y1);
        const x2 = Number(points.x2);
        const y2 = Number(points.y2);
        const left = Math.min(x1, x2);
        const top = Math.min(y1, y2);
        const width = Math.abs(x2 - x1);
        const height = Math.abs(y2 - y1);

        ctx.beginPath();
        if (style.type === 'freeQuad') {
            // 초기 드래그 시 사각형으로 미리보기 표시
            ctx.rect(left, top, width, height);
            return;
        }
        if (style.type === 'line') {
            ctx.moveTo(x1, y1);
            ctx.lineTo(x2, y2);
            return;
        }
        if (style.type === 'ellipse') {
            const cx = left + width / 2;
            const cy = top + height / 2;
            const rx = Math.max(1, width / 2);
            const ry = Math.max(1, height / 2);
            ctx.ellipse(cx, cy, rx, ry, 0, 0, Math.PI * 2);
            return;
        }
        if (style.type === 'roundRect') {
            const radius = Math.min(Math.max(4, style.lineWidth * 2), width / 2, height / 2);
            ctx.moveTo(left + radius, top);
            ctx.lineTo(left + width - radius, top);
            ctx.quadraticCurveTo(left + width, top, left + width, top + radius);
            ctx.lineTo(left + width, top + height - radius);
            ctx.quadraticCurveTo(left + width, top + height, left + width - radius, top + height);
            ctx.lineTo(left + radius, top + height);
            ctx.quadraticCurveTo(left, top + height, left, top + height - radius);
            ctx.lineTo(left, top + radius);
            ctx.quadraticCurveTo(left, top, left + radius, top);
            return;
        }
        ctx.rect(left, top, width, height);
    };

    const drawShapeOnContext = (ctx, points, shapeStyle) => {
        if (!ctx || !points) return false;
        const style = copyShapeStyle(shapeStyle);
        const length = Math.hypot(Number(points.x2) - Number(points.x1), Number(points.y2) - Number(points.y1));
        if (length < 2) return false;

        ctx.save();
        ctx.lineWidth = style.lineWidth;
        ctx.setLineDash(getShapeLineDash(style));
        ctx.lineCap = style.lineStyle === 'dot' ? 'round' : 'butt';
        ctx.lineJoin = 'round';
        buildShapePath(ctx, points, style);

        if (style.type !== 'line' && style.fillAlpha > 0) {
            ctx.fillStyle = colorToRgba(style.fillColor, style.fillAlpha);
            ctx.fill();
        }
        if (style.strokeAlpha > 0 && style.lineWidth > 0) {
            ctx.strokeStyle = colorToRgba(style.strokeColor, style.strokeAlpha);
            ctx.stroke();
        }
        ctx.restore();
        return true;
    };

    const drawImageEditorOverlay = () => {
        if (!imageEditorState) return;
        const overlay = getImageEditorOverlay();
        if (!overlay) return;
        updateImageEditorCanvasCursor();
        const octx = overlay.getContext('2d');
        if (!octx) return;
        octx.clearRect(0, 0, overlay.width, overlay.height);

        if (imageEditorState.tool === 'crop') {
            const r = imageEditorState.cropRect;
            octx.save();
            octx.fillStyle = 'rgba(6, 14, 26, 0.48)';
            octx.fillRect(0, 0, overlay.width, overlay.height);

            if (r && r.w > 0 && r.h > 0) {
                octx.clearRect(r.x, r.y, r.w, r.h);
                octx.fillStyle = 'rgba(255, 255, 255, 0.08)';
                octx.fillRect(r.x, r.y, r.w, r.h);
                octx.strokeStyle = '#20df7f';
                octx.setLineDash([]);
                octx.strokeRect(r.x + 0.5, r.y + 0.5, Math.max(0, r.w - 1), Math.max(0, r.h - 1));
            }
            octx.restore();
            return;
        }

        hideCropActionButton();
        renderObjectsOnOverlay(octx);
        // 선그리기 진행 중 미리보기
        if (imageEditorState.tool === 'draw' && imageEditorState.currentDrawPoints?.length > 1) {
            const ds = ensureImageEditorDrawStyle();
            octx.beginPath();
            octx.lineWidth = clampDrawLineWidth(ds.lineWidth);
            octx.strokeStyle = colorToRgba(ds.color, ds.alpha);
            octx.lineCap = 'round';
            octx.lineJoin = 'round';
            imageEditorState.currentDrawPoints.forEach((pt, i) => {
                if (i === 0) octx.moveTo(pt.x, pt.y);
                else octx.lineTo(pt.x, pt.y);
            });
            octx.stroke();
        }
        if (imageEditorState.tool === 'shape' && imageEditorState.previewRect) {
            drawShapeOnContext(octx, imageEditorState.previewRect, ensureImageEditorShapeStyle());
        }
        if (imageEditorState.cropRect) {
            const r = imageEditorState.cropRect;
            octx.save();
            octx.strokeStyle = '#3a7fc1';
            octx.lineWidth = 2;
            octx.setLineDash([6, 4]);
            octx.strokeRect(r.x, r.y, r.w, r.h);
            octx.restore();
        }
    };

    const setImageEditorCanvasSize = (width, height) => {
        const canvas = getImageEditorCanvas();
        const overlay = getImageEditorOverlay();
        if (!canvas || !overlay) return;
        canvas.width = Math.max(1, Math.round(width));
        canvas.height = Math.max(1, Math.round(height));
        overlay.width = canvas.width;
        overlay.height = canvas.height;
    };

    const getImageEditorSnapshot = () => {
        const canvas = getImageEditorCanvas();
        if (!canvas) return null;
        return {
            width: canvas.width,
            height: canvas.height,
            dataUrl: canvas.toDataURL('image/png'),
            objects: JSON.parse(JSON.stringify(imageEditorState?.objects || [])),
        };
    };

    const loadImageEditorSnapshot = (snapshot) => {
        if (!snapshot) return Promise.resolve(false);
        return new Promise((resolve) => {
            const canvas = getImageEditorCanvas();
            if (!canvas) return resolve(false);
            const ctx = canvas.getContext('2d');
            if (!ctx) return resolve(false);

            const img = new Image();
            img.onload = () => {
                setImageEditorCanvasSize(snapshot.width, snapshot.height);
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0);
                imageEditorState.cropRect = null;
                imageEditorState.objects = JSON.parse(JSON.stringify(snapshot.objects || []));
                imageEditorState.selectedObjectId = null;
                hideCropActionButton();
                drawImageEditorOverlay();
                resolve(true);
            };
            img.src = snapshot.dataUrl;
        });
    };

    const pushImageEditorHistory = () => {
        if (!imageEditorState) return;
        const snap = getImageEditorSnapshot();
        if (!snap) return;
        imageEditorState.history = imageEditorState.history.slice(0, imageEditorState.historyIndex + 1);
        imageEditorState.history.push(snap);
        imageEditorState.historyIndex = imageEditorState.history.length - 1;
        updateImageEditorUi();
    };

    const refreshImageEditorControlState = () => {
        if (!imageEditorState || !imageEditorModal) return;
        const zoomSelect = getImageEditorZoomSelect();
        const widthInput = getImageEditorWidthInput();
        const heightInput = getImageEditorHeightInput();
        const pixelModeCheckbox = getImageEditorPixelModeCheckbox();
        const ratioLockButton = getImageEditorRatioLockButton();

        const pixelMode = !!imageEditorState.pixelMode;
        if (zoomSelect) zoomSelect.disabled = pixelMode;
        if (widthInput) widthInput.disabled = !pixelMode;
        if (heightInput) heightInput.disabled = !pixelMode;
        if (pixelModeCheckbox) pixelModeCheckbox.checked = pixelMode;
        if (ratioLockButton) {
            ratioLockButton.disabled = !pixelMode;
            ratioLockButton.textContent = imageEditorState.keepRatio ? '🔒' : '🔓';
            ratioLockButton.classList.toggle('is-locked', !!imageEditorState.keepRatio);
            ratioLockButton.title = imageEditorState.keepRatio ? '비율 고정 해제' : '비율 고정';
        }
    };

    const updateImageEditorUi = () => {
        if (!imageEditorState || !imageEditorModal) return;
        const canvas = getImageEditorCanvas();
        const widthInput = getImageEditorWidthInput();
        const heightInput = getImageEditorHeightInput();
        const meta = getImageEditorMeta();
        if (canvas) {
            if (widthInput) widthInput.value = formatPxValue(canvas.width);
            if (heightInput) heightInput.value = formatPxValue(canvas.height);
            if (meta) meta.textContent = `${canvas.width} x ${canvas.height} (px)`;
            if (canvas.height > 0) {
                imageEditorState.aspectRatio = canvas.width / canvas.height;
            }
        }
        imageEditorModal.querySelectorAll('button[data-action="undo"], button[data-action="redo"]').forEach((btn) => {
            if (btn.dataset.action === 'undo') {
                btn.disabled = imageEditorState.historyIndex <= 0;
            }
            if (btn.dataset.action === 'redo') {
                btn.disabled = imageEditorState.historyIndex >= imageEditorState.history.length - 1;
            }
        });
        updateImageEditorCanvasCursor();
        refreshImageEditorControlState();
        updateImageEditorTextStyleUi();
        updateImageEditorShapeStyleUi();
        updateImageEditorDrawStyleUi();
        positionImageEditorShapePanel();
        positionImageEditorDrawPanel();
        positionImageEditorTextPanel();
    };

    const closeImageEditor = () => {
        if (!imageEditorModal) return;
        closeImageEditorTextEntry({ commit: false });
        imageEditorModal.hidden = true;
        hideCropActionButton();
        hideImageEditorTextPanel();
        hideImageEditorShapePanel();
        hideImageEditorDrawPanel();
        imageEditorState = null;
    };

    const applyImageEditorZoom = () => {
        if (!imageEditorModal || !imageEditorState) return;
        const stage = imageEditorModal.querySelector('.image-edit-canvas-stage');
        if (!stage) return;
        stage.style.transform = `scale(${imageEditorState.zoom})`;
        stage.style.transformOrigin = 'top left';
        if (imageEditorState.tool === 'crop' && imageEditorState.cropRect) {
            showCropActionButton(imageEditorState.cropRect);
        }
        positionImageEditorTextEntryInViewport();
        positionImageEditorShapePanel();
        positionImageEditorDrawPanel();
        positionImageEditorTextPanel();
    };

    const applyPixelResizeFromInputs = (changedField = '') => {
        if (!imageEditorState || !imageEditorState.pixelMode) return false;
        const widthInput = getImageEditorWidthInput();
        const heightInput = getImageEditorHeightInput();
        if (!widthInput || !heightInput) return false;

        let w = parsePxInputValue(widthInput.value);
        let h = parsePxInputValue(heightInput.value);
        if (!w || !h) return false;

        const ratio = Number(imageEditorState.aspectRatio) || (h > 0 ? w / h : 0);
        if (imageEditorState.keepRatio && ratio > 0) {
            if (changedField === 'width') {
                h = Math.max(1, Math.round(w / ratio));
            } else if (changedField === 'height') {
                w = Math.max(1, Math.round(h * ratio));
            }
        }

        widthInput.value = formatPxValue(w);
        heightInput.value = formatPxValue(h);
        return resizeImageEditorCanvas(w, h);
    };

    const resizeImageEditorCanvas = (newWidth, newHeight) => {
        const canvas = getImageEditorCanvas();
        if (!canvas) return false;
        const ctx = canvas.getContext('2d');
        if (!ctx) return false;
        const w = Math.max(1, Math.round(newWidth));
        const h = Math.max(1, Math.round(newHeight));
        if (canvas.width === w && canvas.height === h) return false;

        const off = document.createElement('canvas');
        off.width = canvas.width;
        off.height = canvas.height;
        off.getContext('2d')?.drawImage(canvas, 0, 0);

        setImageEditorCanvasSize(w, h);
        ctx.clearRect(0, 0, w, h);
        ctx.drawImage(off, 0, 0, w, h);
        imageEditorState.cropRect = null;
        hideCropActionButton();
        drawImageEditorOverlay();
        pushImageEditorHistory();
        return true;
    };

    const rotateImageEditorCanvas = () => {
        closeImageEditorTextEntry({ commit: true });
        const canvas = getImageEditorCanvas();
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const off = document.createElement('canvas');
        off.width = canvas.width;
        off.height = canvas.height;
        off.getContext('2d')?.drawImage(canvas, 0, 0);

        setImageEditorCanvasSize(off.height, off.width);
        ctx.save();
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate(Math.PI / 2);
        ctx.drawImage(off, -off.width / 2, -off.height / 2);
        ctx.restore();
        imageEditorState.cropRect = null;
        hideCropActionButton();
        drawImageEditorOverlay();
        pushImageEditorHistory();
    };

    const addTextToImageEditor = () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        hideImageEditorShapePanel();
        hideImageEditorDrawPanel();
        imageEditorState.tool = 'text';
        imageEditorState.pendingText = '';
        imageEditorState.cropRect = null;
        hideCropActionButton();
        drawImageEditorOverlay();
    };

    const addShapeToImageEditor = () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        hideImageEditorTextPanel();
        hideImageEditorDrawPanel();
        imageEditorState.tool = 'shape';
        imageEditorState.cropRect = null;
        hideCropActionButton();
        drawImageEditorOverlay();
    };

    const toggleDrawModeInImageEditor = () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        hideImageEditorTextPanel();
        hideImageEditorShapePanel();
        imageEditorState.tool = imageEditorState.tool === 'draw' ? 'none' : 'draw';
        if (imageEditorState.tool !== 'crop') {
            imageEditorState.cropRect = null;
            hideCropActionButton();
        }
        drawImageEditorOverlay();
    };

    const beginCropMode = () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        hideImageEditorTextPanel();
        hideImageEditorShapePanel();
        hideImageEditorDrawPanel();
        imageEditorState.tool = 'crop';
        imageEditorState.cropRect = null;
        hideCropActionButton();
        drawImageEditorOverlay();
    };

    const applyCropFromImageEditor = () => {
        if (!imageEditorState || !imageEditorState.cropRect) return false;
        const canvas = getImageEditorCanvas();
        if (!canvas) return false;
        const ctx = canvas.getContext('2d');
        if (!ctx) return false;
        const r = imageEditorState.cropRect;
        if (r.w < 4 || r.h < 4) return false;

        const off = document.createElement('canvas');
        off.width = canvas.width;
        off.height = canvas.height;
        off.getContext('2d')?.drawImage(canvas, 0, 0);

        setImageEditorCanvasSize(r.w, r.h);
        ctx.clearRect(0, 0, r.w, r.h);
        ctx.drawImage(off, r.x, r.y, r.w, r.h, 0, 0, r.w, r.h);
        imageEditorState.cropRect = null;
        imageEditorState.tool = 'none';
        hideCropActionButton();
        drawImageEditorOverlay();
        pushImageEditorHistory();
        return true;
    };

    const undoImageEditor = async () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        if (imageEditorState.historyIndex <= 0) return;
        imageEditorState.historyIndex -= 1;
        await loadImageEditorSnapshot(imageEditorState.history[imageEditorState.historyIndex]);
        updateImageEditorUi();
    };

    const redoImageEditor = async () => {
        if (!imageEditorState) return;
        closeImageEditorTextEntry({ commit: true });
        if (imageEditorState.historyIndex >= imageEditorState.history.length - 1) return;
        imageEditorState.historyIndex += 1;
        await loadImageEditorSnapshot(imageEditorState.history[imageEditorState.historyIndex]);
        updateImageEditorUi();
    };

    const resetImageEditor = async () => {
        if (!imageEditorState || imageEditorState.history.length === 0) return;
        closeImageEditorTextEntry({ commit: true });
        imageEditorState.historyIndex = 0;
        await loadImageEditorSnapshot(imageEditorState.history[0]);
        updateImageEditorUi();
    };

    const pointerToCanvasPoint = (event) => {
        const overlay = getImageEditorOverlay();
        if (!overlay) return { x: 0, y: 0 };
        const rect = overlay.getBoundingClientRect();
        const x = Math.max(0, Math.min(overlay.width, Math.round((event.clientX - rect.left) * (overlay.width / rect.width))));
        const y = Math.max(0, Math.min(overlay.height, Math.round((event.clientY - rect.top) * (overlay.height / rect.height))));
        return { x, y };
    };

    const bindImageEditorPointerHandlers = () => {
        const overlay = getImageEditorOverlay();
        const canvas = getImageEditorCanvas();
        if (!overlay || !canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const onDown = (e) => {
            if (!imageEditorState) return;
            const p = pointerToCanvasPoint(e);
            imageEditorState.startPoint = p;
            imageEditorState.lastPoint = p;

            if (imageEditorState.tool === 'text') {
                e.preventDefault();
                closeImageEditorTextEntry({ commit: true });
                showImageEditorTextEntry(p);
                imageEditorState.pointerDown = false;
                drawImageEditorOverlay();
                return;
            }

            if (imageEditorState.tool === 'select') {
                e.preventDefault();
                const selectedId = imageEditorState.selectedObjectId;
                const selectedObj = selectedId
                    ? (imageEditorState.objects || []).find((o) => o.id === selectedId)
                    : null;
                // 핸들 먼저 체크
                if (selectedObj) {
                    const hi = hitTestHandle(selectedObj, p.x, p.y);
                    if (hi >= 0) {
                        const { cx, cy } = getObjCenter(selectedObj);
                        imageEditorState.selectDragState = {
                            type: hi === 8 ? 'rotate' : 'resize',
                            handleIndex: hi,
                            startX: p.x, startY: p.y,
                            startAngle: hi === 8 ? Math.atan2(p.y - cy, p.x - cx) : 0,
                            objSnapshot: JSON.parse(JSON.stringify(selectedObj)),
                        };
                        return;
                    }
                }
                // 객체 히트 테스트
                const hit = hitTestObjects(p.x, p.y);
                if (hit) {
                    imageEditorState.selectedObjectId = hit.id;
                    syncSelectedObjectStyleToState();
                    imageEditorState.selectDragState = {
                        type: 'move',
                        startX: p.x, startY: p.y,
                        objSnapshot: JSON.parse(JSON.stringify(hit)),
                    };
                } else {
                    imageEditorState.selectedObjectId = null;
                    imageEditorState.selectDragState = null;
                }
                drawImageEditorOverlay();
                return;
            }

            imageEditorState.pointerDown = true;

            if (imageEditorState.tool === 'draw') {
                imageEditorState.currentDrawPoints = [{ x: p.x, y: p.y }];
            } else if (imageEditorState.tool === 'shape') {
                imageEditorState.previewRect = { x1: p.x, y1: p.y, x2: p.x, y2: p.y };
            } else if (imageEditorState.tool === 'crop') {
                imageEditorState.cropRect = { x: p.x, y: p.y, w: 0, h: 0 };
                hideCropActionButton();
            }
            drawImageEditorOverlay();
        };

        const onMove = (e) => {
            if (!imageEditorState) return;
            const p = pointerToCanvasPoint(e);

            if (imageEditorState.tool === 'select') {
                const ds = imageEditorState.selectDragState;
                if (ds) {
                    const selectedObj = (imageEditorState.objects || []).find(
                        (o) => o.id === imageEditorState.selectedObjectId
                    );
                    if (selectedObj) {
                        if (ds.type === 'move') {
                            const mvDx = p.x - ds.startX;
                            const mvDy = p.y - ds.startY;
                            if (selectedObj.type === 'shape' && selectedObj.style?.type === 'freeQuad'
                                && selectedObj.corners?.length === 4 && ds.objSnapshot.corners?.length === 4) {
                                selectedObj.corners = ds.objSnapshot.corners.map((c) => ({ x: c.x + mvDx, y: c.y + mvDy }));
                                updateFreeQuadBBox(selectedObj);
                            } else {
                            selectedObj.x = ds.objSnapshot.x + mvDx;
                            selectedObj.y = ds.objSnapshot.y + mvDy;
                            }
                        } else if (ds.type === 'rotate') {
                            const { cx, cy } = getObjCenter(selectedObj);
                            const angle = Math.atan2(p.y - cy, p.x - cx);
                            const delta = (angle - ds.startAngle) * (180 / Math.PI);
                            selectedObj.rotation = ((ds.objSnapshot.rotation || 0) + delta + 360) % 360;
                        } else if (ds.type === 'resize') {
                            applyHandleResize(selectedObj, ds, p);
                        }
                        drawImageEditorOverlay();
                    }
                } else {
                    updateSelectToolCursor(p);
                }
                return;
            }

            if (!imageEditorState.pointerDown) return;

            if (imageEditorState.tool === 'draw') {
                if (!imageEditorState.currentDrawPoints) imageEditorState.currentDrawPoints = [];
                imageEditorState.currentDrawPoints.push({ x: p.x, y: p.y });
                imageEditorState.lastPoint = p;
                drawImageEditorOverlay();
                return;
            }

            if (imageEditorState.tool === 'shape') {
                const shapeRect = imageEditorState.previewRect || {
                    x1: imageEditorState.startPoint.x,
                    y1: imageEditorState.startPoint.y,
                    x2: imageEditorState.startPoint.x,
                    y2: imageEditorState.startPoint.y,
                };
                shapeRect.x2 = p.x;
                shapeRect.y2 = p.y;
                imageEditorState.previewRect = shapeRect;
                drawImageEditorOverlay();
                return;
            }

            if (imageEditorState.tool === 'crop') {
                const sx = imageEditorState.startPoint.x;
                const sy = imageEditorState.startPoint.y;
                imageEditorState.cropRect = {
                    x: Math.min(sx, p.x),
                    y: Math.min(sy, p.y),
                    w: Math.abs(p.x - sx),
                    h: Math.abs(p.y - sy),
                };
                drawImageEditorOverlay();
            }
        };

        const onUp = () => {
            if (!imageEditorState) return;

            if (imageEditorState.tool === 'select' && imageEditorState.selectDragState) {
                imageEditorState.selectDragState = null;
                pushImageEditorHistory();
                drawImageEditorOverlay();
                return;
            }

            const wasDrawing = imageEditorState.tool === 'draw' && imageEditorState.pointerDown;
            const wasShape = imageEditorState.tool === 'shape' && imageEditorState.previewRect;
            imageEditorState.pointerDown = false;

            if (wasDrawing) {
                const pts = imageEditorState.currentDrawPoints || [];
                imageEditorState.currentDrawPoints = null;
                if (pts.length >= 2) {
                    const obj = createDrawObject(pts, ensureImageEditorDrawStyle());
                    if (obj) {
                        if (!imageEditorState.objects) imageEditorState.objects = [];
                        imageEditorState.objects.push(obj);
                        imageEditorState.selectedObjectId = obj.id;
                        imageEditorState.tool = 'select';
                        updateToolButtonActiveStates();
                    }
                }
                pushImageEditorHistory();
            } else if (wasShape) {
                const shapeRect = imageEditorState.previewRect;
                if (shapeRect) {
                    const len = Math.hypot(shapeRect.x2 - shapeRect.x1, shapeRect.y2 - shapeRect.y1);
                    if (len >= 2) {
                        const obj = createShapeObject(
                            shapeRect.x1, shapeRect.y1, shapeRect.x2, shapeRect.y2,
                            ensureImageEditorShapeStyle()
                        );
                        if (!imageEditorState.objects) imageEditorState.objects = [];
                        imageEditorState.objects.push(obj);
                        imageEditorState.selectedObjectId = obj.id;
                        imageEditorState.tool = 'select';
                        updateToolButtonActiveStates();
                        pushImageEditorHistory();
                    }
                }
                imageEditorState.previewRect = null;
            }
            drawImageEditorOverlay();

            if (imageEditorState.tool === 'crop') {
                const r = imageEditorState.cropRect;
                if (r && r.w >= 4 && r.h >= 4) {
                    showCropActionButton(r);
                } else {
                    hideCropActionButton();
                }
            }
        };

        overlay.addEventListener('mousedown', (e) => { overlay.focus(); onDown(e); });
        overlay.addEventListener('mousemove', onMove);
        overlay.addEventListener('mouseup', onUp);
        overlay.addEventListener('mouseleave', onUp);
    };

    const applyEditedCanvasToEmbed = () => {
        if (!imageEditorState || !activeEmbed) return;
        // toBlob() 콜백은 비동기로 실행되므로, 클로저가 닫히기 전에 참조를 캡처한다.
        // document click 핸들러가 activeEmbed를 null로 초기화하기 전에 콜백이 실행될 수 없기 때문.
        const targetEmbed = activeEmbed;
        closeImageEditorTextEntry({ commit: true });
        commitObjectsToCanvas();
        const canvas = getImageEditorCanvas();
        if (!canvas) return;

        canvas.toBlob((blob) => {
            if (!blob || !targetEmbed) return;
            const baseNameRaw = (targetEmbed.querySelector('figcaption')?.textContent || 'edited_image').trim();
            const baseName = baseNameRaw.replace(/\.[^/.]+$/, '').replace(/[^\w.-]+/g, '_') || 'edited_image';
            const fileName = `${baseName}_edited_${Date.now()}.png`;
            const file = new File([blob], fileName, { type: 'image/png' });
            generatedEditedFiles.set(fileName.toLowerCase(), file);
            rebuildAttachmentInputFiles();

            const objectUrl = URL.createObjectURL(file);
            editedEmbedObjectUrls.push(objectUrl);
            const img = targetEmbed.querySelector('img');
            const caption = targetEmbed.querySelector('figcaption');
            if (img) {
                img.src = objectUrl;
                img.alt = fileName;
            }
            if (caption) {
                caption.textContent = fileName;
            }
            targetEmbed.dataset.tokenBase = fileName;
            applyEmbedOption(targetEmbed, { rotate: 0, flip: false });
            closeImageEditor();
        }, 'image/png');
    };

    const openImageEditor = (embed) => {
        if (!embed) return;
        ensureImageEditorModal();
        const canvas = getImageEditorCanvas();
        if (!imageEditorModal || !canvas) return;

        const img = embed.querySelector('img');
        if (!img) return;
        activeEmbed = embed;

        // 본문 표시 사이즈(small/medium/large)에 따른 최대 너비
        const embedSizeMaxWidth = { small: 280, medium: 520, large: 840 };
        const embedSize = embed.dataset.size || defaultEmbedOptions.size;
        const displayMaxWidth = embedSizeMaxWidth[embedSize] || 520;

        const load = new Image();
        load.onload = () => {
            const natW = load.naturalWidth || load.width;
            const natH = load.naturalHeight || load.height;
            // 본문 표시 너비로 캔버스 크기 결정 (원본보다 커지지 않음)
            const canvasW = Math.min(natW, displayMaxWidth);
            const canvasH = natH > 0 ? Math.round(canvasW * (natH / natW)) : natH;
            setImageEditorCanvasSize(canvasW, canvasH);
            const ctx = canvas.getContext('2d');
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(load, 0, 0, canvas.width, canvas.height);

            imageEditorState = {
                tool: 'none',
                pendingText: '',
                pointerDown: false,
                startPoint: null,
                lastPoint: null,
                cropRect: null,
                previewRect: null,
                history: [],
                historyIndex: -1,
                zoom: 1,
                pixelMode: false,
                keepRatio: true,
                objects: [],
                selectedObjectId: null,
                selectDragState: null,
                textStyle: copyTextStyle(defaultTextStyle),
                textColorPicker: null,
                shapeStyle: copyShapeStyle(defaultShapeStyle),
                shapeColorPicker: null,
                drawStyle: copyDrawStyle(defaultDrawStyle),
                drawColorPicker: null,
                textEntryActive: false,
                textEntryIgnoreBlurUntil: 0,
                aspectRatio: canvasH > 0 ? canvasW / canvasH : 1,
            };
            const zoomSelect = getImageEditorZoomSelect();
            if (zoomSelect) zoomSelect.value = '100';
            applyImageEditorZoom();
            hideCropActionButton();
            drawImageEditorOverlay();
            pushImageEditorHistory();
            updateImageEditorUi();
            imageEditorModal.hidden = false;
        };
        load.src = img.src;
    };

    const ensureImageEditorModal = () => {
        if (imageEditorModal) return;

        imageEditorModal = document.createElement('div');
        imageEditorModal.className = 'image-edit-modal';
        imageEditorModal.hidden = true;
        imageEditorModal.innerHTML = [
            '<div class="image-edit-dialog" role="dialog" aria-modal="true" aria-label="이미지 편집">',
            '  <div class="image-edit-head">',
            '    <strong>이미지 편집</strong>',
            '    <span class="image-edit-meta"></span>',
            '    <button type="button" class="btn btn-sm" data-action="close">닫기</button>',
            '  </div>',
            '  <div class="image-edit-tools">',
            '    <button type="button" class="tool-btn tool-select-btn" data-action="select" title="선택/이동" aria-label="선택/이동">↖</button>',
            '    <span class="tool-sep"></span>',
            '    <select class="editor-select" data-field="zoom"><option value="25">25%</option><option value="50">50%</option><option value="75">75%</option><option value="100" selected>100%</option><option value="125">125%</option><option value="150">150%</option></select>',
            '    <label class="tool-check"><input type="checkbox" data-field="pixel-mode" title="픽셀 크기 조절"></label>',
            '    <label class="tool-input">W <input type="text" inputmode="numeric" data-field="width" disabled></label>',
            '    <button type="button" class="tool-btn" data-action="toggle-ratio" title="비율 고정">🔒</button>',
            '    <label class="tool-input">H <input type="text" inputmode="numeric" data-field="height" disabled></label>',
            '    <button type="button" class="tool-btn" data-action="crop" title="잘라내기" aria-label="잘라내기"><span class="nm-tool-icon nm-tool-icon-crop" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="rotate" title="회전" aria-label="회전"><span class="nm-tool-icon nm-tool-icon-rotate" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="text" title="텍스트 삽입" aria-label="텍스트 삽입"><span class="nm-tool-icon nm-tool-icon-text" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn tool-btn-setting" data-action="text-settings" title="텍스트 옵션" aria-label="텍스트 옵션"><span class="nm-tool-icon nm-tool-icon-setting" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="shape" title="도형 넣기" aria-label="도형 넣기"><span class="nm-tool-icon nm-tool-icon-shape" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn tool-btn-setting" data-action="shape-settings" title="도형 옵션" aria-label="도형 옵션"><span class="nm-tool-icon nm-tool-icon-setting" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="draw" title="그리기" aria-label="그리기"><span class="nm-tool-icon nm-tool-icon-draw" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn tool-btn-setting" data-action="draw-settings" title="선 옵션" aria-label="선 옵션"><span class="nm-tool-icon nm-tool-icon-setting" aria-hidden="true"></span></button>',
            '    <span class="tool-sep"></span>',
            '    <button type="button" class="tool-btn" data-action="undo" title="전단계" aria-label="전단계"><span class="nm-tool-icon nm-tool-icon-undo" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="redo" title="다음단계" aria-label="다음단계"><span class="nm-tool-icon nm-tool-icon-redo" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="reset" title="되돌리기" aria-label="되돌리기"><span class="nm-tool-icon nm-tool-icon-reset" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn danger" data-action="delete" title="삭제" aria-label="삭제"><span class="nm-tool-icon nm-tool-icon-remove" aria-hidden="true"></span></button>',
            '  </div>',
            '  <div class="image-edit-body">',
            '    <div class="image-edit-canvas-stage-wrap">',
            '      <div class="image-edit-canvas-stage">',
            '        <canvas class="image-edit-canvas"></canvas>',
            '        <canvas class="image-edit-overlay" tabindex="-1"></canvas>',
            '      </div>',
            '    </div>',
            '    <button type="button" class="image-crop-action" data-action="apply-crop-selection" hidden></button>',
            '    <div class="image-text-entry" hidden><textarea class="image-text-entry-input" spellcheck="false" wrap="off"></textarea></div>',
            '    <div class="image-text-panel" hidden>',
            '      <section class="image-text-section">',
            '        <div class="image-text-label">글자크기</div>',
            '        <div class="image-text-size-row">',
            '          <input type="text" class="image-text-size-input" data-field="text-size" value="22px">',
            '          <button type="button" class="image-text-size-btn" data-action="text-size-dec" title="글자크기 감소">−</button>',
            '          <input type="range" class="image-text-size-range" data-field="text-size-range" min="10" max="120" step="1" value="22">',
            '          <button type="button" class="image-text-size-btn" data-action="text-size-inc" title="글자크기 증가">+</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-text-section">',
            '        <div class="image-text-label">글자 스타일</div>',
            '        <div class="image-text-style-row">',
            '          <button type="button" class="image-text-style-btn" data-style="bold" data-action="toggle-text-style" title="굵게">B</button>',
            '          <button type="button" class="image-text-style-btn style-italic" data-style="italic" data-action="toggle-text-style" title="기울임">I</button>',
            '          <button type="button" class="image-text-style-btn style-underline" data-style="underline" data-action="toggle-text-style" title="밑줄">U</button>',
            '          <button type="button" class="image-text-style-btn style-strike" data-style="strike" data-action="toggle-text-style" title="취소선">S</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-text-section">',
            '        <div class="image-text-color-head">',
            '          <span class="image-text-label">글자 색상</span>',
            '          <button type="button" class="image-text-more-btn" data-action="open-text-color-picker">더보기 &gt;</button>',
            '        </div>',
            '        <div class="image-text-color-palette">',
            textPresetColors.map((color) => `          <button type="button" class="color-chip" data-action="set-text-color" data-color="${color}" style="--chip:${color};" title="${color}"></button>`).join(''),
            '        </div>',
            '      </section>',
            '    </div>',
            '    <div class="image-shape-panel" hidden>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-label">도형</div>',
            '        <div class="image-shape-type-row">',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type" data-shape="rect" title="사각형"><span class="shape-type-icon rect" aria-hidden="true"></span></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type" data-shape="roundRect" title="둥근 사각형"><span class="shape-type-icon round-rect" aria-hidden="true"></span></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type" data-shape="ellipse" title="원형"><span class="shape-type-icon ellipse" aria-hidden="true"></span></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type" data-shape="line" title="직선"><span class="shape-type-icon line" aria-hidden="true"></span></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type" data-shape="freeQuad" title="자유 사각형"><span class="shape-type-icon free-quad" aria-hidden="true"></span></button>',
            '        </div>',
            '      </section>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-label">선굵기</div>',
            '        <div class="image-shape-width-row">',
            '          <input type="text" class="image-shape-width-input" data-field="shape-line-width" value="2px">',
            '          <button type="button" class="image-shape-width-btn" data-action="shape-line-width-dec" title="선굵기 감소">−</button>',
            '          <input type="range" class="image-shape-width-range" data-field="shape-line-width-range" min="1" max="30" step="1" value="2">',
            '          <button type="button" class="image-shape-width-btn" data-action="shape-line-width-inc" title="선굵기 증가">+</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-label">선종류</div>',
            '        <div class="image-shape-line-style-row">',
            '          <button type="button" class="image-shape-line-style-btn" data-action="set-shape-line-style" data-line-style="solid" title="실선"><span class="line-style-sample solid"></span></button>',
            '          <button type="button" class="image-shape-line-style-btn" data-action="set-shape-line-style" data-line-style="dash" title="쇄선"><span class="line-style-sample dash"></span></button>',
            '          <button type="button" class="image-shape-line-style-btn" data-action="set-shape-line-style" data-line-style="dot" title="점선"><span class="line-style-sample dot"></span></button>',
            '        </div>',
            '      </section>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-color-tab-row">',
            '          <button type="button" class="image-shape-color-tab-btn" data-action="set-shape-color-tab" data-target="stroke">테두리</button>',
            '          <button type="button" class="image-shape-color-tab-btn" data-action="set-shape-color-tab" data-target="fill">채우기</button>',
            '          <button type="button" class="image-shape-color-tab-btn image-shape-more-btn" data-action="open-shape-color-picker">더보기 &gt;</button>',
            '        </div>',
            '        <div class="image-shape-color-palette">',
            shapePresetColors.map((color) => (
                color === 'transparent'
                    ? '          <button type="button" class="color-chip transparent" data-action="set-shape-color" data-color="transparent" title="투명"></button>'
                    : `          <button type="button" class="color-chip" data-action="set-shape-color" data-color="${color}" style="--chip:${color};" title="${color}"></button>`
            )).join(''),
            '        </div>',
            '        <div class="image-shape-alpha-inline-row">',
            '          <span class="image-shape-alpha-title">투명도</span>',
            '          <input type="range" class="image-shape-alpha-range" data-field="shape-color-alpha-inline" min="0" max="100" step="1" value="100">',
            '          <span class="shape-color-alpha-inline-label">100%</span>',
            '        </div>',
            '      </section>',
            '    </div>',
            '    <div class="image-shape-color-dim" hidden></div>',
            '    <div class="image-shape-color-picker" hidden>',
            '      <div class="image-shape-color-picker-top">',
            '        <span class="image-shape-color-preview"></span>',
            '        <input type="text" class="image-shape-color-hex" data-field="shape-color-hex" value="#ff2d2d">',
            '        <button type="button" class="image-shape-color-apply-btn" data-action="apply-shape-color-hex">입력</button>',
            '        <button type="button" class="image-shape-color-close-btn" data-action="close-shape-color-picker">×</button>',
            '      </div>',
            '      <canvas class="image-shape-color-sv" data-field="shape-color-sv" width="220" height="150"></canvas>',
            '      <canvas class="image-shape-color-hue" data-field="shape-color-hue" width="220" height="12"></canvas>',
            '      <div class="image-shape-alpha-row">',
            '        <span class="image-shape-alpha-title">투명도</span>',
            '        <input type="range" class="image-shape-alpha-range" data-field="shape-color-alpha" min="0" max="100" step="1" value="100">',
            '        <span class="shape-color-alpha-label">100%</span>',
            '      </div>',
            '    </div>',
            '    <div class="image-draw-panel" hidden>',
            '      <section class="image-draw-section">',
            '        <div class="image-draw-label">선굵기</div>',
            '        <div class="image-draw-width-row">',
            '          <input type="text" class="image-draw-width-input" data-field="draw-line-width" value="2px">',
            '          <button type="button" class="image-draw-width-btn" data-action="draw-line-width-dec" title="선굵기 감소">−</button>',
            '          <input type="range" class="image-draw-width-range" data-field="draw-line-width-range" min="1" max="30" step="1" value="2">',
            '          <button type="button" class="image-draw-width-btn" data-action="draw-line-width-inc" title="선굵기 증가">+</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-draw-section">',
            '        <div class="image-draw-color-head">',
            '          <span class="image-draw-label">선색상</span>',
            '          <button type="button" class="image-draw-more-btn" data-action="open-draw-color-picker">더보기 &gt;</button>',
            '        </div>',
            '        <div class="image-draw-color-palette">',
            shapePresetColors.filter((color) => color !== 'transparent').map((color) => `          <button type="button" class="color-chip" data-action="set-draw-color" data-color="${color}" style="--chip:${color};" title="${color}"></button>`).join(''),
            '        </div>',
            '        <div class="image-draw-alpha-inline-row">',
            '          <span class="image-draw-alpha-title">투명도</span>',
            '          <input type="range" class="image-draw-alpha-range" data-field="draw-color-alpha-inline" min="0" max="100" step="1" value="100">',
            '          <span class="draw-color-alpha-inline-label">100%</span>',
            '        </div>',
            '      </section>',
            '    </div>',
            '    <div class="image-draw-color-dim" hidden></div>',
            '    <div class="image-draw-color-picker" hidden>',
            '      <div class="image-draw-color-picker-top">',
            '        <span class="image-draw-color-preview"></span>',
            '        <input type="text" class="image-draw-color-hex" data-field="draw-color-hex" value="#ff2d2d">',
            '        <button type="button" class="image-draw-color-apply-btn" data-action="apply-draw-color-hex">입력</button>',
            '        <button type="button" class="image-draw-color-close-btn" data-action="close-draw-color-picker">×</button>',
            '      </div>',
            '      <canvas class="image-draw-color-sv" data-field="draw-color-sv" width="220" height="150"></canvas>',
            '      <canvas class="image-draw-color-hue" data-field="draw-color-hue" width="220" height="12"></canvas>',
            '      <div class="image-draw-alpha-row">',
            '        <span class="image-draw-alpha-title">투명도</span>',
            '        <input type="range" class="image-draw-alpha-range" data-field="draw-color-alpha" min="0" max="100" step="1" value="100">',
            '        <span class="draw-color-alpha-label">100%</span>',
            '      </div>',
            '    </div>',
            '    <div class="image-text-color-dim" hidden></div>',
            '    <div class="image-text-color-picker" hidden>',
            '      <div class="image-text-color-picker-top">',
            '        <span class="image-text-color-preview"></span>',
            '        <input type="text" class="image-text-color-hex" data-field="text-color-hex" value="#ff2d2d">',
            '        <button type="button" class="image-text-color-apply-btn" data-action="apply-text-color-hex">입력</button>',
            '        <button type="button" class="image-text-color-close-btn" data-action="close-text-color-picker">×</button>',
            '      </div>',
            '      <canvas class="image-text-color-sv" data-field="text-color-sv" width="220" height="150"></canvas>',
            '      <canvas class="image-text-color-hue" data-field="text-color-hue" width="220" height="12"></canvas>',
            '    </div>',
            '  </div>',
            '  <div class="image-edit-foot">',
            '    <button type="button" class="btn" data-action="cancel">취소</button>',
            '    <button type="button" class="btn btn-primary" data-action="apply">적용</button>',
            '  </div>',
            '</div>'
        ].join('');
        document.body.appendChild(imageEditorModal);

        bindImageEditorPointerHandlers();

        const editBody = imageEditorModal.querySelector('.image-edit-body');
        editBody?.addEventListener('scroll', () => {
            if (imageEditorState?.tool === 'crop' && imageEditorState.cropRect) {
                showCropActionButton(imageEditorState.cropRect);
            }
            positionImageEditorTextEntryInViewport();
            positionImageEditorShapePanel();
            positionImageEditorDrawPanel();
            positionImageEditorTextPanel();
        });
        window.addEventListener('resize', () => {
            if (imageEditorState?.tool === 'crop' && imageEditorState.cropRect && imageEditorModal && !imageEditorModal.hidden) {
                showCropActionButton(imageEditorState.cropRect);
            }
            positionImageEditorTextEntryInViewport();
            positionImageEditorShapePanel();
            positionImageEditorDrawPanel();
            positionImageEditorTextPanel();
        });

        imageEditorModal.addEventListener('click', async (e) => {
            if (e.target === imageEditorModal) {
                closeImageEditor();
                return;
            }
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.dataset.action;

            if (action === 'close' || action === 'cancel') {
                closeImageEditor();
                return;
            }
            if (!imageEditorState) return;

            if (action === 'toggle-ratio') {
                imageEditorState.keepRatio = !imageEditorState.keepRatio;
                const canvas = getImageEditorCanvas();
                if (imageEditorState.keepRatio && canvas && canvas.height > 0) {
                    imageEditorState.aspectRatio = canvas.width / canvas.height;
                }
                refreshImageEditorControlState();
                return;
            }
            if (action === 'text-settings') {
                toggleImageEditorTextPanel();
                return;
            }
            if (action === 'shape-settings') {
                toggleImageEditorShapePanel();
                return;
            }
            if (action === 'draw-settings') {
                toggleImageEditorDrawPanel();
                return;
            }
            if (action === 'text-size-dec') {
                const style = ensureImageEditorTextStyle();
                setImageEditorTextSize(style.size - 1);
                return;
            }
            if (action === 'text-size-inc') {
                const style = ensureImageEditorTextStyle();
                setImageEditorTextSize(style.size + 1);
                return;
            }
            if (action === 'toggle-text-style') {
                const styleKey = btn.dataset.style || '';
                toggleImageEditorTextStyle(styleKey);
                return;
            }
            if (action === 'set-text-color') {
                applyImageEditorTextColor(btn.dataset.color || '');
                return;
            }
            if (action === 'open-text-color-picker') {
                openImageEditorTextColorPicker();
                return;
            }
            if (action === 'close-text-color-picker') {
                hideImageEditorTextColorPicker();
                return;
            }
            if (action === 'apply-text-color-hex') {
                const hexInput = getImageEditorTextColorHexInput();
                if (hexInput) applyImageEditorTextColor(hexInput.value || '');
                return;
            }
            if (action === 'shape-line-width-dec') {
                const style = ensureImageEditorShapeStyle();
                setImageEditorShapeLineWidth(style.lineWidth - 1);
                return;
            }
            if (action === 'shape-line-width-inc') {
                const style = ensureImageEditorShapeStyle();
                setImageEditorShapeLineWidth(style.lineWidth + 1);
                return;
            }
            if (action === 'set-shape-type') {
                setImageEditorShapeType(btn.dataset.shape || '');
                return;
            }
            if (action === 'set-shape-line-style') {
                setImageEditorShapeLineStyle(btn.dataset.lineStyle || '');
                return;
            }
            if (action === 'set-shape-color-tab') {
                setImageEditorShapeColorTarget(btn.dataset.target || 'stroke');
                return;
            }
            if (action === 'set-shape-color') {
                const color = String(btn.dataset.color || '').trim().toLowerCase();
                if (color === 'transparent') {
                    applyImageEditorShapeTransparency(0);
                } else {
                    const style = ensureImageEditorShapeStyle();
                    const alphaKey = getShapeAlphaKeyByTarget(style.colorTarget);
                    const alpha = normalizeAlpha(style[alphaKey], 1) <= 0 ? 1 : normalizeAlpha(style[alphaKey], 1);
                    applyImageEditorShapeColor(color, alpha);
                }
                return;
            }
            if (action === 'open-shape-color-picker') {
                openImageEditorShapeColorPicker();
                return;
            }
            if (action === 'close-shape-color-picker') {
                hideImageEditorShapeColorPicker();
                return;
            }
            if (action === 'apply-shape-color-hex') {
                const hexInput = getImageEditorShapeColorHexInput();
                if (hexInput) applyImageEditorShapeColor(hexInput.value || '');
                return;
            }
            if (action === 'draw-line-width-dec') {
                const style = ensureImageEditorDrawStyle();
                setImageEditorDrawLineWidth(style.lineWidth - 1);
                return;
            }
            if (action === 'draw-line-width-inc') {
                const style = ensureImageEditorDrawStyle();
                setImageEditorDrawLineWidth(style.lineWidth + 1);
                return;
            }
            if (action === 'set-draw-color') {
                const color = String(btn.dataset.color || '').trim().toLowerCase();
                if (color) {
                    const style = ensureImageEditorDrawStyle();
                    const alpha = normalizeAlpha(style.alpha, 1) <= 0 ? 1 : normalizeAlpha(style.alpha, 1);
                    applyImageEditorDrawColor(color, alpha);
                }
                return;
            }
            if (action === 'open-draw-color-picker') {
                openImageEditorDrawColorPicker();
                return;
            }
            if (action === 'close-draw-color-picker') {
                hideImageEditorDrawColorPicker();
                return;
            }
            if (action === 'apply-draw-color-hex') {
                const hexInput = getImageEditorDrawColorHexInput();
                if (hexInput) applyImageEditorDrawColor(hexInput.value || '');
                return;
            }
            if (action === 'apply-crop-selection') {
                applyCropFromImageEditor();
                return;
            }
            if (action === 'crop') {
                hideImageEditorTextPanel();
                hideImageEditorDrawPanel();
                beginCropMode();
                return;
            }
            if (action === 'rotate') {
                hideImageEditorTextPanel();
                hideImageEditorShapePanel();
                hideImageEditorDrawPanel();
                rotateImageEditorCanvas();
                return;
            }
            if (action === 'select') {
                activateSelectTool();
                return;
            }
            if (action === 'text') {
                addTextToImageEditor();
                return;
            }
            if (action === 'shape') {
                hideImageEditorTextPanel();
                hideImageEditorDrawPanel();
                addShapeToImageEditor();
                return;
            }
            if (action === 'draw') {
                hideImageEditorTextPanel();
                hideImageEditorShapePanel();
                toggleDrawModeInImageEditor();
                return;
            }
            if (action === 'undo') {
                await undoImageEditor();
                return;
            }
            if (action === 'redo') {
                await redoImageEditor();
                return;
            }
            if (action === 'reset') {
                await resetImageEditor();
                return;
            }
            if (action === 'delete') {
                if (imageEditorState?.tool === 'select' && imageEditorState?.selectedObjectId) {
                    deleteSelectedObject();
                    return;
                }
                if (activeEmbed) {
                    deleteEmbed(activeEmbed);
                }
                closeImageEditor();
                return;
            }
            if (action === 'apply') {
                applyEditedCanvasToEmbed();
            }
        });

        imageEditorModal.addEventListener('change', (e) => {
            if (!imageEditorState) return;
            const zoomSelect = e.target.closest('select[data-field="zoom"]');
            if (zoomSelect) {
                const v = Number(zoomSelect.value || 100);
                imageEditorState.zoom = Math.max(0.25, Math.min(2, v / 100));
                applyImageEditorZoom();
                return;
            }

            const textSizeInput = e.target.closest('input[data-field="text-size"]');
            if (textSizeInput) {
                setImageEditorTextSize(parsePxInputValue(textSizeInput.value));
                return;
            }

            const textSizeRange = e.target.closest('input[data-field="text-size-range"]');
            if (textSizeRange) {
                setImageEditorTextSize(Number(textSizeRange.value || defaultTextStyle.size));
                return;
            }

            const shapeWidthInput = e.target.closest('input[data-field="shape-line-width"]');
            if (shapeWidthInput) {
                setImageEditorShapeLineWidth(parsePxInputValue(shapeWidthInput.value));
                return;
            }

            const shapeWidthRange = e.target.closest('input[data-field="shape-line-width-range"]');
            if (shapeWidthRange) {
                setImageEditorShapeLineWidth(Number(shapeWidthRange.value || defaultShapeStyle.lineWidth));
                return;
            }

            const shapeOpacityRange = e.target.closest('input[data-field="shape-color-alpha"], input[data-field="shape-color-alpha-inline"]');
            if (shapeOpacityRange) {
                applyImageEditorShapeTransparency(Number(shapeOpacityRange.value || 100));
                return;
            }

            const drawWidthRange = e.target.closest('input[data-field="draw-line-width-range"]');
            if (drawWidthRange) {
                setImageEditorDrawLineWidth(Number(drawWidthRange.value || defaultDrawStyle.lineWidth));
                return;
            }

            const drawHexInput = e.target.closest('input[data-field="draw-color-hex"]');
            if (drawHexInput) {
                const normalized = normalizeHexColor(drawHexInput.value || '', '');
                if (normalized) {
                    applyImageEditorDrawColor(normalized);
                }
                return;
            }

            const drawOpacityRange = e.target.closest('input[data-field="draw-color-alpha"], input[data-field="draw-color-alpha-inline"]');
            if (drawOpacityRange) {
                applyImageEditorDrawTransparency(Number(drawOpacityRange.value || 100));
                return;
            }

            const drawWidthInput = e.target.closest('input[data-field="draw-line-width"]');
            if (drawWidthInput) {
                setImageEditorDrawLineWidth(parsePxInputValue(drawWidthInput.value));
                return;
            }

            const pixelMode = e.target.closest('input[data-field="pixel-mode"]');
            if (pixelMode) {
                imageEditorState.pixelMode = !!pixelMode.checked;
                const canvas = getImageEditorCanvas();
                if (imageEditorState.pixelMode && canvas && canvas.height > 0) {
                    imageEditorState.aspectRatio = canvas.width / canvas.height;
                }
                refreshImageEditorControlState();
                return;
            }

            const widthInput = e.target.closest('input[data-field="width"]');
            if (widthInput) {
                applyPixelResizeFromInputs('width');
                return;
            }

            const heightInput = e.target.closest('input[data-field="height"]');
            if (heightInput) {
                applyPixelResizeFromInputs('height');
            }
        });

        imageEditorModal.addEventListener('input', (e) => {
            if (!imageEditorState) return;

            const textSizeRange = e.target.closest('input[data-field="text-size-range"]');
            if (textSizeRange) {
                setImageEditorTextSize(Number(textSizeRange.value || defaultTextStyle.size));
                return;
            }

            const textHexInput = e.target.closest('input[data-field="text-color-hex"]');
            if (textHexInput) {
                const normalized = normalizeHexColor(textHexInput.value || '', '');
                if (normalized) {
                    applyImageEditorTextColor(normalized);
                }
                return;
            }

            const shapeWidthRange = e.target.closest('input[data-field="shape-line-width-range"]');
            if (shapeWidthRange) {
                setImageEditorShapeLineWidth(Number(shapeWidthRange.value || defaultShapeStyle.lineWidth));
                return;
            }

            const shapeHexInput = e.target.closest('input[data-field="shape-color-hex"]');
            if (shapeHexInput) {
                const normalized = normalizeHexColor(shapeHexInput.value || '', '');
                if (normalized) {
                    applyImageEditorShapeColor(normalized);
                }
                return;
            }

            const shapeOpacityRange = e.target.closest('input[data-field="shape-color-alpha"], input[data-field="shape-color-alpha-inline"]');
            if (shapeOpacityRange) {
                applyImageEditorShapeTransparency(Number(shapeOpacityRange.value || 100));
                return;
            }

            const drawWidthRange = e.target.closest('input[data-field="draw-line-width-range"]');
            if (drawWidthRange) {
                setImageEditorDrawLineWidth(Number(drawWidthRange.value || defaultDrawStyle.lineWidth));
                return;
            }

            const drawHexInput = e.target.closest('input[data-field="draw-color-hex"]');
            if (drawHexInput) {
                const normalized = normalizeHexColor(drawHexInput.value || '', '');
                if (normalized) {
                    applyImageEditorDrawColor(normalized);
                }
                return;
            }

            const drawOpacityRange = e.target.closest('input[data-field="draw-color-alpha"], input[data-field="draw-color-alpha-inline"]');
            if (drawOpacityRange) {
                applyImageEditorDrawTransparency(Number(drawOpacityRange.value || 100));
                return;
            }

            if (!imageEditorState.pixelMode || !imageEditorState.keepRatio) return;
            const widthInput = e.target.closest('input[data-field="width"]');
            const heightInput = e.target.closest('input[data-field="height"]');
            if (!widthInput && !heightInput) return;

            const ratio = Number(imageEditorState.aspectRatio) || 0;
            if (ratio <= 0) return;

            if (widthInput) {
                const w = parsePxInputValue(widthInput.value);
                if (w > 0) {
                    const h = Math.max(1, Math.round(w / ratio));
                    const hInput = getImageEditorHeightInput();
                    if (hInput) hInput.value = formatPxValue(h);
                }
                return;
            }
            if (heightInput) {
                const h = parsePxInputValue(heightInput.value);
                if (h > 0) {
                    const w = Math.max(1, Math.round(h * ratio));
                    const wInput = getImageEditorWidthInput();
                    if (wInput) wInput.value = formatPxValue(w);
                }
            }
        });

        imageEditorModal.addEventListener('keydown', (e) => {
            if (!imageEditorState) return;

            if ((e.key === 'Delete' || e.key === 'Backspace') && !imageEditorState.textEntryActive) {
                if (imageEditorState.selectedObjectId) {
                    e.preventDefault();
                    deleteSelectedObject();
                    return;
                }
            }

            if (e.key !== 'Enter') return;

            const textSizeInput = e.target.closest('input[data-field="text-size"]');
            if (textSizeInput) {
                e.preventDefault();
                setImageEditorTextSize(parsePxInputValue(textSizeInput.value));
                return;
            }

            const textHexInput = e.target.closest('input[data-field="text-color-hex"]');
            if (textHexInput) {
                e.preventDefault();
                applyImageEditorTextColor(textHexInput.value || '');
                return;
            }

            const shapeWidthInput = e.target.closest('input[data-field="shape-line-width"]');
            if (shapeWidthInput) {
                e.preventDefault();
                setImageEditorShapeLineWidth(parsePxInputValue(shapeWidthInput.value));
                return;
            }

            const shapeHexInput = e.target.closest('input[data-field="shape-color-hex"]');
            if (shapeHexInput) {
                e.preventDefault();
                applyImageEditorShapeColor(shapeHexInput.value || '');
                return;
            }

            const drawWidthInput = e.target.closest('input[data-field="draw-line-width"]');
            if (drawWidthInput) {
                e.preventDefault();
                setImageEditorDrawLineWidth(parsePxInputValue(drawWidthInput.value));
                return;
            }

            const drawHexInput = e.target.closest('input[data-field="draw-color-hex"]');
            if (drawHexInput) {
                e.preventDefault();
                applyImageEditorDrawColor(drawHexInput.value || '');
                return;
            }

            if (!imageEditorState.pixelMode) return;
            const widthInput = e.target.closest('input[data-field="width"]');
            const heightInput = e.target.closest('input[data-field="height"]');
            if (!widthInput && !heightInput) return;
            e.preventDefault();
            applyPixelResizeFromInputs(widthInput ? 'width' : 'height');
        });

        const svCanvas = getImageEditorTextColorSvCanvas();
        const hueCanvas = getImageEditorTextColorHueCanvas();
        const colorDim = getImageEditorTextColorDim();
        const shapeSvCanvas = getImageEditorShapeColorSvCanvas();
        const shapeHueCanvas = getImageEditorShapeColorHueCanvas();
        const shapeColorDim = getImageEditorShapeColorDim();
        const drawSvCanvas = getImageEditorDrawColorSvCanvas();
        const drawHueCanvas = getImageEditorDrawColorHueCanvas();
        const drawColorDim = getImageEditorDrawColorDim();
        let textColorDragTarget = '';
        let shapeColorDragTarget = '';
        let drawColorDragTarget = '';

        const stopTextColorDragging = () => {
            if (!imageEditorState?.textColorPicker) return;
            const wasDragging = imageEditorState.textColorPicker.dragging !== '';
            imageEditorState.textColorPicker.dragging = '';
            textColorDragTarget = '';
            if (wasDragging && imageEditorState.tool === 'select' && imageEditorState.selectedObjectId) {
                const sel = (imageEditorState.objects || []).find((o) => o.id === imageEditorState.selectedObjectId);
                if (sel?.type === 'text') pushImageEditorHistory();
            }
        };

        const startTextColorDragging = (target, event) => {
            if (!imageEditorState) return;
            ensureTextColorPickerState();
            imageEditorState.textColorPicker.dragging = target;
            textColorDragTarget = target;
            updateTextColorPickerFromPointer(target, event);
            applyImageEditorTextColorFromPickerState();
        };

        const stopShapeColorDragging = () => {
            if (!imageEditorState?.shapeColorPicker) return;
            const wasDragging = imageEditorState.shapeColorPicker.dragging !== '';
            imageEditorState.shapeColorPicker.dragging = '';
            shapeColorDragTarget = '';
            if (wasDragging && imageEditorState.tool === 'select' && imageEditorState.selectedObjectId) {
                const sel = (imageEditorState.objects || []).find((o) => o.id === imageEditorState.selectedObjectId);
                if (sel?.type === 'shape') pushImageEditorHistory();
            }
        };

        const startShapeColorDragging = (target, event) => {
            if (!imageEditorState) return;
            ensureShapeColorPickerState();
            imageEditorState.shapeColorPicker.dragging = target;
            shapeColorDragTarget = target;
            updateShapeColorPickerFromPointer(target, event);
            renderImageEditorShapeColorPicker();
            updateImageEditorShapeStyleUi();
        };

        const stopDrawColorDragging = () => {
            if (!imageEditorState?.drawColorPicker) return;
            const wasDragging = imageEditorState.drawColorPicker.dragging !== '';
            imageEditorState.drawColorPicker.dragging = '';
            drawColorDragTarget = '';
            if (wasDragging && imageEditorState.tool === 'select' && imageEditorState.selectedObjectId) {
                const sel = (imageEditorState.objects || []).find((o) => o.id === imageEditorState.selectedObjectId);
                if (sel?.type === 'draw') pushImageEditorHistory();
            }
        };

        const startDrawColorDragging = (target, event) => {
            if (!imageEditorState) return;
            ensureDrawColorPickerState();
            imageEditorState.drawColorPicker.dragging = target;
            drawColorDragTarget = target;
            updateDrawColorPickerFromPointer(target, event);
            renderImageEditorDrawColorPicker();
            updateImageEditorDrawStyleUi();
        };

        svCanvas?.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startTextColorDragging('sv', e);
        });
        hueCanvas?.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startTextColorDragging('hue', e);
        });
        shapeSvCanvas?.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startShapeColorDragging('sv', e);
        });
        shapeHueCanvas?.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startShapeColorDragging('hue', e);
        });
        drawSvCanvas?.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startDrawColorDragging('sv', e);
        });
        drawHueCanvas?.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startDrawColorDragging('hue', e);
        });

        document.addEventListener('mousemove', (e) => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            if (!textColorDragTarget) return;
            updateTextColorPickerFromPointer(textColorDragTarget, e);
            applyImageEditorTextColorFromPickerState();
        });
        document.addEventListener('mousemove', (e) => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            if (!shapeColorDragTarget) return;
            updateShapeColorPickerFromPointer(shapeColorDragTarget, e);
            renderImageEditorShapeColorPicker();
            updateImageEditorShapeStyleUi();
        });
        document.addEventListener('mousemove', (e) => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            if (!drawColorDragTarget) return;
            updateDrawColorPickerFromPointer(drawColorDragTarget, e);
            renderImageEditorDrawColorPicker();
            updateImageEditorDrawStyleUi();
        });
        document.addEventListener('mouseup', stopTextColorDragging);
        document.addEventListener('mouseup', stopShapeColorDragging);
        document.addEventListener('mouseup', stopDrawColorDragging);

        colorDim?.addEventListener('click', () => {
            hideImageEditorTextColorPicker();
        });
        shapeColorDim?.addEventListener('click', () => {
            hideImageEditorShapeColorPicker();
        });
        drawColorDim?.addEventListener('click', () => {
            hideImageEditorDrawColorPicker();
        });

        document.addEventListener('mousedown', (e) => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            const textPanel = getImageEditorTextPanel();
            const textSettingBtn = getImageEditorTextSettingButton();
            const textPicker = getImageEditorTextColorPicker();
            const shapePanel = getImageEditorShapePanel();
            const shapeSettingBtn = getImageEditorShapeSettingButton();
            const shapePicker = getImageEditorShapeColorPicker();
            const drawPanel = getImageEditorDrawPanel();
            const drawSettingBtn = getImageEditorDrawSettingButton();
            const drawPicker = getImageEditorDrawColorPicker();
            const target = e.target;

            if (textPanel && !textPanel.hidden) {
                const hitTextPanel = textPanel.contains(target);
                const hitTextSetting = textSettingBtn && textSettingBtn.contains(target);
                const hitTextPicker = textPicker && !textPicker.hidden && textPicker.contains(target);
                if (!hitTextPanel && !hitTextSetting && !hitTextPicker) {
                    hideImageEditorTextPanel();
                }
            }

            if (shapePanel && !shapePanel.hidden) {
                const hitShapePanel = shapePanel.contains(target);
                const hitShapeSetting = shapeSettingBtn && shapeSettingBtn.contains(target);
                const hitShapePicker = shapePicker && !shapePicker.hidden && shapePicker.contains(target);
                if (!hitShapePanel && !hitShapeSetting && !hitShapePicker) {
                    hideImageEditorShapePanel();
                }
            }

            if (drawPanel && !drawPanel.hidden) {
                const hitDrawPanel = drawPanel.contains(target);
                const hitDrawSetting = drawSettingBtn && drawSettingBtn.contains(target);
                const hitDrawPicker = drawPicker && !drawPicker.hidden && drawPicker.contains(target);
                if (!hitDrawPanel && !hitDrawSetting && !hitDrawPicker) {
                    hideImageEditorDrawPanel();
                }
            }
        });

        const textEntryInput = getImageEditorTextEntryInput();
        textEntryInput?.addEventListener('input', () => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            refreshImageEditorTextEntrySize();
        });
        textEntryInput?.addEventListener('keydown', (e) => {
            if (!imageEditorState) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closeImageEditorTextEntry({ commit: false });
                return;
            }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                closeImageEditorTextEntry({ commit: true });
                return;
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                closeImageEditorTextEntry({ commit: true });
            }
        });
        textEntryInput?.addEventListener('blur', () => {
            if (!imageEditorModal || imageEditorModal.hidden) return;
            if (imageEditorState && Date.now() < Number(imageEditorState.textEntryIgnoreBlurUntil || 0)) {
                requestAnimationFrame(() => {
                    const entry = getImageEditorTextEntry();
                    const input = getImageEditorTextEntryInput();
                    if (!entry || !input || entry.hidden) return;
                    input.focus();
                    const end = input.value.length;
                    input.setSelectionRange(end, end);
                });
                return;
            }
            setTimeout(() => {
                if (!imageEditorModal || imageEditorModal.hidden) return;
                const active = document.activeElement;
                if (active && active.closest && active.closest('.image-text-entry')) return;
                closeImageEditorTextEntry({ commit: true });
            }, 30);
        });
    };

    const ensureImageToolbar = () => {
        if (imageToolbar) return;
        imageToolbar = document.createElement('div');
        imageToolbar.className = 'editor-image-toolbar';
        imageToolbar.innerHTML = [
            '<button type="button" class="tool-btn" data-action="align" data-value="left" title="왼쪽 정렬">◧</button>',
            '<button type="button" class="tool-btn" data-action="align" data-value="center" title="가운데 정렬">◨</button>',
            '<button type="button" class="tool-btn" data-action="align" data-value="right" title="오른쪽 정렬">◩</button>',
            '<button type="button" class="tool-btn" data-action="size-cycle" title="크기 변경">▣</button>',
            '<button type="button" class="tool-btn" data-action="edit" title="이미지 편집">✎</button>',
            '<button type="button" class="tool-btn danger" data-action="delete" title="삭제">🗑</button>',
        ].join('');
        document.body.appendChild(imageToolbar);

        imageToolbar.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });
        imageToolbar.addEventListener('click', (e) => {
            const button = e.target.closest('.tool-btn');
            if (!button || !activeEmbed) return;

            const action = button.dataset.action;
            const value = button.dataset.value;
            if (action === 'align' && value) {
                applyEmbedOption(activeEmbed, { align: value });
            } else if (action === 'size-cycle') {
                const nextSize = cycleEmbedSize(activeEmbed.dataset.size || defaultEmbedOptions.size);
                applyEmbedOption(activeEmbed, { size: nextSize });
            } else if (action === 'edit') {
                openImageEditor(activeEmbed);
            } else if (action === 'delete') {
                deleteEmbed(activeEmbed);
            }
        });
    };

    const ensureTextToolbar = () => {
        if (document.getElementById('content-text-toolbar')) return;
        if (!contentEditor) return;

        const toolbar = document.createElement('div');
        toolbar.id = 'content-text-toolbar';
        toolbar.className = 'content-text-toolbar';
        toolbar.innerHTML = [
            '<select class="toolbar-select" data-action="fontSize" title="글자 크기">',
            '  <option value="1">소</option>',
            '  <option value="3" selected>중</option>',
            '  <option value="5">대</option>',
            '  <option value="7">특대</option>',
            '</select>',
            '<span class="toolbar-separator"></span>',
            '<button type="button" class="toolbar-btn" data-action="bold" title="굵게 (Ctrl+B)"><b>B</b></button>',
            '<button type="button" class="toolbar-btn" data-action="italic" title="기울임 (Ctrl+I)"><i>I</i></button>',
            '<button type="button" class="toolbar-btn" data-action="underline" title="밑줄 (Ctrl+U)"><u>U</u></button>',
            '<button type="button" class="toolbar-btn" data-action="strikeThrough" title="취소선"><s>S</s></button>',
            '<span class="toolbar-separator"></span>',
            '<button type="button" class="toolbar-btn toolbar-color-btn" data-action="foreColor" title="글자색">',
            '  A',
            '  <span class="color-swatch" id="text-color-swatch" style="background:#e8f2fc"></span>',
            '  <input type="color" id="text-color-picker" value="#e8f2fc" tabindex="-1">',
            '</button>',
            '<span class="toolbar-separator"></span>',
            '<button type="button" class="toolbar-btn" data-action="removeFormat" title="서식 초기화">✕</button>',
        ].join('');

        contentEditor.parentNode.insertBefore(toolbar, contentEditor);
        contentEditor.classList.add('has-toolbar');

        const colorPicker = toolbar.querySelector('#text-color-picker');
        const colorSwatch = toolbar.querySelector('#text-color-swatch');

        const updateToolbarState = () => {
            toolbar.querySelectorAll('.toolbar-btn[data-action]').forEach((btn) => {
                const action = btn.dataset.action;
                if (action === 'foreColor' || action === 'removeFormat') return;
                try {
                    btn.classList.toggle('is-active', document.queryCommandState(action));
                } catch (_) {}
            });
        };

        // Prevent toolbar clicks from stealing editor focus
        toolbar.addEventListener('mousedown', (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn || btn.tagName === 'SELECT') return;
            if (btn.dataset.action === 'foreColor') return; // handled by color picker
            e.preventDefault();
        });

        toolbar.addEventListener('click', (e) => {
            const btn = e.target.closest('.toolbar-btn[data-action]');
            if (!btn) return;
            const action = btn.dataset.action;
            if (action === 'foreColor') {
                colorPicker.click();
                return;
            }
            document.execCommand(action, false, null);
            contentEditor.focus();
            updateToolbarState();
            syncTextareaFromEditor();
        });

        const sizeSelect = toolbar.querySelector('select[data-action="fontSize"]');
        if (sizeSelect) {
            sizeSelect.addEventListener('mousedown', (e) => { e.stopPropagation(); });
            sizeSelect.addEventListener('change', () => {
                document.execCommand('fontSize', false, sizeSelect.value);
                contentEditor.focus();
                updateToolbarState();
                syncTextareaFromEditor();
                sizeSelect.value = '3'; // reset to default after applying
            });
        }

        if (colorPicker) {
            colorPicker.addEventListener('input', () => {
                const color = colorPicker.value;
                if (colorSwatch) colorSwatch.style.background = color;
            });
            colorPicker.addEventListener('change', () => {
                const color = colorPicker.value;
                document.execCommand('foreColor', false, color);
                if (colorSwatch) colorSwatch.style.background = color;
                contentEditor.focus();
                syncTextareaFromEditor();
            });
        }

        contentEditor.addEventListener('keyup', updateToolbarState);
        contentEditor.addEventListener('mouseup', updateToolbarState);
        contentEditor.addEventListener('focus', updateToolbarState);
    };

    const enableRichEditor = () => {
        if (!contentEditor || !contentTextarea) return;
        contentEditor.hidden = false;
        contentEditor.dataset.placeholder = '내용을 입력해 주세요';
        contentTextarea.style.display = 'none';
        contentTextarea.required = false;
        ensureTextToolbar();
        ensureImageToolbar();
        ensureImageEditorModal();
        renderEditorFromText(contentTextarea.value || '');
        syncTextareaFromEditor();
    };

    const renderTokenizedContent = (rawText, targetElement, objectUrlStore, emptyText) => {
        if (!targetElement) return;

        clearObjectUrls(objectUrlStore);
        targetElement.innerHTML = '';

        const existingImages = getExistingImageAttachments();
        const selectedFiles = getSelectedImageFileByName();
        const raw = String(rawText || '');

        // If this is richtext content, strip prefix and parse as HTML with token substitution
        if (raw.startsWith(RICHTEXT_PREFIX)) {
            const htmlContent = raw.slice(RICHTEXT_PREFIX.length);
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
                const parsed = parseAttachmentTokenValue(tokenValue);
                const imageInfo = resolveImageFromToken(parsed.target, existingImages, selectedFiles, objectUrlStore);
                if (imageInfo) {
                    const figure = document.createElement('figure');
                    figure.className = 'write-preview-image-wrap';
                    figure.classList.add(`align-${parsed.options.align}`, `size-${parsed.options.size}`);
                    const img = document.createElement('img');
                    img.src = imageInfo.src;
                    img.alt = imageInfo.name || '첨부 이미지';
                    applyImageTransformToElement(img, parsed.options);
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

                const parsed = parseAttachmentTokenValue(match[1]);
                const imageInfo = resolveImageFromToken(parsed.target, existingImages, selectedFiles, objectUrlStore);
                if (!imageInfo) {
                    targetElement.appendChild(document.createTextNode(match[0]));
                } else {
                    const figure = document.createElement('figure');
                    figure.className = 'write-preview-image-wrap';
                    figure.classList.add(`align-${parsed.options.align}`, `size-${parsed.options.size}`);
                    const img = document.createElement('img');
                    img.src = imageInfo.src;
                    img.alt = imageInfo.name || '첨부 이미지';
                    applyImageTransformToElement(img, parsed.options);
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

    const closePreviewModal = () => {
        if (!previewModal) return;
        previewModal.hidden = true;
        document.body.style.overflow = '';
        clearObjectUrls(modalPreviewObjectUrls);
    };

    const openPreviewModal = () => {
        if (!previewModal || !previewTitle || !previewContent) return;

        syncTextareaFromEditor();
        previewTitle.textContent = (titleInput?.value || '').trim() || '(제목 없음)';
        renderTokenizedContent(contentTextarea?.value || '', previewContent, modalPreviewObjectUrls, '(내용 없음)');

        previewModal.hidden = false;
        document.body.style.overflow = 'hidden';
    };

    const updateLivePreview = () => {
        if (!livePreviewBody) return;
        renderTokenizedContent(
            contentTextarea?.value || '',
            livePreviewBody,
            livePreviewObjectUrls,
            '내용과 첨부 이미지 토큰이 여기에 표시됩니다.'
        );
    };

    const insertTokenAtCursor = (token) => {
        if (contentEditor && !contentEditor.hidden) {
            const tokenMatch = String(token || '').match(/^\[\[\s*첨부\s*:\s*(.+?)\s*\]\]$/);
            const existingImages = getExistingImageAttachments();
            const selectedFiles = getSelectedImageFileByName();
            const parsed = parseAttachmentTokenValue(tokenMatch ? tokenMatch[1] : '');
            const imageInfo = resolveImageFromToken(parsed.target, existingImages, selectedFiles, editorObjectUrls);

            if (imageInfo && tokenMatch) {
                const embed = createEditorEmbed(parsed.target, imageInfo, parsed.options);
                insertNodeIntoEditor(embed);
            } else {
                const textNode = document.createTextNode(String(token || ''));
                insertNodeIntoEditor(textNode);
            }
            return;
        }

        if (!contentTextarea) return;
        const value = contentTextarea.value || '';
        const start = contentTextarea.selectionStart ?? value.length;
        const end = contentTextarea.selectionEnd ?? start;
        const before = value.slice(0, start);
        const after = value.slice(end);
        const prefix = before && !before.endsWith('\n') ? '\n' : '';
        const suffix = after && !after.startsWith('\n') ? '\n' : '';
        const inserted = `${prefix}${token}${suffix}`;
        contentTextarea.value = before + inserted + after;
        const caret = (before + inserted).length;
        contentTextarea.focus();
        contentTextarea.setSelectionRange(caret, caret);
        updateLivePreview();
    };

    const renderNewAttachmentTokens = () => {
        if (!attachmentInput || !newAttachmentTokenList) return;
        const files = Array.from(attachmentInput.files || []);
        const imageFiles = files.filter((file) => isImageFileName(file.name));
        if (imageFiles.length === 0) {
            newAttachmentTokenList.innerHTML = '';
            newAttachmentTokenList.style.display = 'none';
            return;
        }

        const items = imageFiles.map((file) => {
            const token = `[[첨부:${file.name}]]`;
            return `<span class="existing-file">${escapeHtml(file.name)} <button type="button" class="insert-attachment-token" data-token="${escapeHtml(token)}">본문삽입</button></span>`;
        }).join('');

        newAttachmentTokenList.innerHTML = items;
        newAttachmentTokenList.style.display = '';
    };

    document.querySelectorAll('.existing-file .del').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.attachId;
            btn.parentElement.style.opacity = '0.4';
            btn.parentElement.style.textDecoration = 'line-through';
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'delete_attachments[]';
            hidden.value = id;
            if (writeForm) {
                writeForm.appendChild(hidden);
            }
            const tokenBtn = btn.parentElement.querySelector('.insert-attachment-token');
            if (tokenBtn) tokenBtn.style.display = 'none';
            btn.style.display = 'none';
            btn.parentElement.dataset.deleted = '1';
            updateLivePreview();
        });
    });

    document.addEventListener('click', (e) => {
        const button = e.target.closest('.insert-attachment-token');
        if (!button) return;
        e.preventDefault();
        const token = button.dataset.token || '';
        if (!token) return;
        insertTokenAtCursor(token);
    });

    // 첨부 "본문삽입" 버튼 클릭 시 에디터 커서를 유지해 여러 장 연속 삽입을 안정화한다.
    document.addEventListener('mousedown', (e) => {
        const button = e.target.closest('.insert-attachment-token');
        if (!button) return;
        e.preventDefault();
    });

    if (attachmentInput) {
        attachmentInput.addEventListener('change', () => {
            syncStagedUploadFilesFromInput();
            rebuildAttachmentInputFiles();
            updateLivePreview();
            syncTextareaFromEditor();
            renderEditorFromText(contentTextarea?.value || '');
        });
        renderNewAttachmentTokens();
    }

    if (contentTextarea) {
        contentTextarea.addEventListener('input', updateLivePreview);
    }
    if (contentEditor) {
        contentEditor.addEventListener('input', () => {
            syncTextareaFromEditor();
            updateLivePreview();
        });
        contentEditor.addEventListener('click', (e) => {
            const embed = e.target.closest('.editor-embed');
            if (embed && contentEditor.contains(embed)) {
                showImageToolbar(embed);
                return;
            }
            hideImageToolbar();
        });
        contentEditor.addEventListener('keyup', saveEditorRange);
        contentEditor.addEventListener('mouseup', saveEditorRange);
        document.addEventListener('selectionchange', saveEditorRange);
        document.addEventListener('click', (e) => {
            if (!imageToolbar || !imageToolbar.classList.contains('is-visible')) return;
            // 이미지 편집 모달이 열려있는 동안은 툴바를 숨기지 않는다
            if (imageEditorState) return;
            if (imageToolbar.contains(e.target)) return;
            if (contentEditor.contains(e.target) && e.target.closest('.editor-embed')) return;
            hideImageToolbar();
        });
        window.addEventListener('scroll', placeImageToolbar, true);
        window.addEventListener('resize', placeImageToolbar);
    }

    enableRichEditor();
    updateLivePreview();

    if (previewButton && previewModal) {
        previewButton.addEventListener('click', openPreviewModal);
        previewCloseButton?.addEventListener('click', closePreviewModal);
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) {
                closePreviewModal();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !previewModal.hidden) {
                closePreviewModal();
            }
            if (e.key === 'Escape' && imageEditorModal && !imageEditorModal.hidden) {
                closeImageEditor();
            }
        });
    }

    if (writeForm) {
        writeForm.addEventListener('submit', (e) => {
            rebuildAttachmentInputFiles();
            syncTextareaFromEditor();
            if (!String(contentTextarea?.value || '').trim()) {
                e.preventDefault();
                alert('내용을 입력해 주세요.');
                if (contentEditor && !contentEditor.hidden) {
                    contentEditor.focus();
                } else {
                    contentTextarea?.focus();
                }
            }
        });
    }

    // 글쓰기 - 투표 옵션 동적 추가
    const addOptBtn = document.querySelector('.add-opt');
    if (addOptBtn) {
        addOptBtn.addEventListener('click', () => {
            const wrap = document.querySelector('.poll-options-input');
            const div = document.createElement('div');
            div.className = 'poll-opt-input';
            div.innerHTML = '<input type="text" name="poll_options[]" placeholder="선택지"><button type="button" class="add-opt remove-opt">삭제</button>';
            wrap.appendChild(div);
            div.querySelector('.remove-opt').addEventListener('click', () => div.remove());
        });
    }
    document.querySelectorAll('.remove-opt').forEach(b => {
        b.addEventListener('click', () => b.parentElement.remove());
    });

    // 글쓰기 - 투표 사용 여부
    const usePoll = document.getElementById('use_poll');
    if (usePoll) {
        const toggle = () => {
            const section = document.getElementById('poll_section');
            if (section) section.style.display = usePoll.checked ? '' : 'none';
        };
        usePoll.addEventListener('change', toggle);
        toggle();
    }
})();
