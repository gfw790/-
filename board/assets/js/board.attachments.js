// // 4. 첨부파일 관리 — isImageFileName, syncStagedUploadFiles, rebuildAttachmentInputFiles, renderNewAttachmentTokens 등
(function(window) {
    window.BOARD_createAttachments = function(app) {
        const boardCommonUtils = window.BOARD_commonUtils || {};

        app.isImageFileName = (fileName) => {
            if (typeof boardCommonUtils.isImageFileName === 'function') {
                return boardCommonUtils.isImageFileName(fileName, app.imageExts);
            }
            const ext = String(fileName || '').toLowerCase().split('.').pop();
            return app.imageExts.includes(ext);
        };

        app.getUploadFileKey = (file) => String(file?.name || '').toLowerCase();

        app.syncStagedUploadFilesFromInput = () => {
            Array.from(app.attachmentInput?.files || []).forEach((file) => {
                const key = app.getUploadFileKey(file);
                if (!key) return;
                if (app.generatedEditedFiles.has(key)) return;
                app.stagedUploadFiles.set(key, file);
            });
        };

        app.rebuildAttachmentInputFiles = () => {
            if (!app.attachmentInput) return;
            app.syncStagedUploadFilesFromInput();
            try {
                const dt = new DataTransfer();
                const seen = new Set();
                app.stagedUploadFiles.forEach((file, key) => {
                    if (!key || seen.has(key)) return;
                    dt.items.add(file);
                    seen.add(key);
                });
                app.generatedEditedFiles.forEach((file, key) => {
                    if (seen.has(key)) return;
                    dt.items.add(file);
                    seen.add(key);
                });
                app.attachmentInput.files = dt.files;
            } catch (e) {
                // DataTransfer 미지원 브라우저는 기존 파일 입력을 유지한다.
            }
            app.renderNewAttachmentTokens();
        };

        app.getExistingImageAttachments = () => {
            const byId = new Map();
            const byName = new Map();
            const ordered = [];

            document.querySelectorAll('.existing-file[data-attach-id]').forEach((el) => {
                if (el.dataset.deleted === '1') return;
                if (el.dataset.isImage !== '1') return;

                const id = Number(el.dataset.attachId || 0);
                if (!id) return;

                const name = el.dataset.originalName || ('첨부 ' + id);
                const info = {
                    id,
                    name,
                    src: 'download.php?id=' + id
                };
                ordered.push(info);
                byId.set(id, info);
                byName.set(name.toLowerCase(), info);
                // [태그] 접두어를 제거한 파일명으로도 조회 가능하게 (예: "[조치 전] IMG_001.JPG" → "img_001.jpg")
                const stripped = name.replace(/^\[[^\]]+\]\s*/, '');
                if (stripped.toLowerCase() !== name.toLowerCase()) {
                    byName.set(stripped.toLowerCase(), info);
                }
            });

            return { byId, byName, ordered };
        };

        app.getSelectedImageFileByName = () => {
            const byName = new Map();
            // near_miss 조치 전 사진처럼 외부에서 등록한 추가 파일
            if (window.NM_extraImageFiles instanceof Map) {
                window.NM_extraImageFiles.forEach((file, key) => {
                    if (app.isImageFileName(String(file.name))) byName.set(key, file);
                });
            }
            Array.from(app.attachmentInput?.files || []).forEach((file) => {
                if (!app.isImageFileName(file.name)) return;
                byName.set(String(file.name).toLowerCase(), file);
            });
            return byName;
        };

        app.resolveImageFromToken = (tokenValue, existingImages, selectedFiles, objectUrlStore) => {
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

        app.renderNewAttachmentTokens = () => {
            if (!app.attachmentInput || !app.newAttachmentTokenList) return;
            const files = Array.from(app.attachmentInput.files || []);
            const imageFiles = files.filter((file) => app.isImageFileName(file.name));
            if (imageFiles.length === 0) {
                app.newAttachmentTokenList.innerHTML = '';
                app.newAttachmentTokenList.style.display = 'none';
                return;
            }

            const items = imageFiles.map((file) => {
                const token = `[[첨부:${file.name}]]`;
                const key = file.name.toLowerCase();
                return `<span class="existing-file" data-staged-key="${app.escapeHtml(key)}">` +
                    `${app.escapeHtml(file.name)} ` +
                    `<button type="button" class="insert-attachment-token"` +
                    ` data-token="${app.escapeHtml(token)}">본문삽입</button></span>`;
            }).join('');

            app.newAttachmentTokenList.innerHTML = items;
            app.newAttachmentTokenList.style.display = '';
        };
    };
})(window);
