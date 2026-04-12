(function(window) {
    const initWritePage = (cfg = {}) => {
        const {
            writeForm,
            contentTextarea,
            contentEditor,
            attachmentInput,
            previewButton,
            previewModal,
            previewCloseButton,
            stagedUploadFiles,
            generatedEditedFiles,
            syncStagedUploadFilesFromInput,
            rebuildAttachmentInputFiles,
            updateLivePreview,
            syncTextareaFromEditor,
            renderEditorFromText,
            renderNewAttachmentTokens,
            insertTokenAtCursor,
            openImageEditorForFile,
            syncEditorOutput,
            showImageToolbar,
            hideImageToolbar,
            saveEditorRange,
            placeImageToolbar,
            enableRichEditor,
            openPreviewModal,
            closePreviewModal,
            closeImageEditor,
            getImageToolbar = () => null,
            getImageEditorState = () => null,
            getImageEditorModal = () => null,
        } = cfg;

        document.querySelectorAll('.existing-file .del').forEach((btn) => {
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
                if (typeof updateLivePreview === 'function') {
                    updateLivePreview();
                }
            });
        });

        document.addEventListener('click', (e) => {
            const button = e.target.closest('.insert-attachment-token');
            if (!button) return;
            e.preventDefault();
            const token = button.dataset.token || '';
            if (!token) return;
            if (typeof insertTokenAtCursor === 'function') {
                insertTokenAtCursor(token);
            }
        });

        // 첨부 "본문삽입" 버튼 클릭 시 에디터 커서를 유지해 여러 장 연속 삽입을 안정화한다.
        document.addEventListener('mousedown', (e) => {
            const button = e.target.closest('.insert-attachment-token');
            if (!button) return;
            e.preventDefault();
        });

        // 조치 후 스테이지 파일 "편집" 버튼
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.nm-edit-staged-file');
            if (!btn) return;
            e.preventDefault();

            const key = btn.dataset.stagedKey || '';
            if (!key) return;
            const file = stagedUploadFiles?.get(key) || generatedEditedFiles?.get(key);
            if (!file || typeof openImageEditorForFile !== 'function') return;

            openImageEditorForFile(file, (editedFile) => {
                const newKey = editedFile.name.toLowerCase();
                stagedUploadFiles?.delete(key);
                generatedEditedFiles?.delete(key);
                stagedUploadFiles?.set(newKey, editedFile);
                if (typeof rebuildAttachmentInputFiles === 'function') {
                    rebuildAttachmentInputFiles();
                }
                if (typeof updateLivePreview === 'function') {
                    updateLivePreview();
                }
            });
        });

        if (attachmentInput) {
            attachmentInput.addEventListener('change', () => {
                if (typeof syncStagedUploadFilesFromInput === 'function') {
                    syncStagedUploadFilesFromInput();
                }
                if (typeof rebuildAttachmentInputFiles === 'function') {
                    rebuildAttachmentInputFiles();
                }
                if (typeof updateLivePreview === 'function') {
                    updateLivePreview();
                }
                if (typeof syncTextareaFromEditor === 'function') {
                    syncTextareaFromEditor();
                }
                if (typeof renderEditorFromText === 'function') {
                    renderEditorFromText(contentTextarea?.value || '');
                }
            });
            if (typeof renderNewAttachmentTokens === 'function') {
                renderNewAttachmentTokens();
            }
        }

        if (contentTextarea && typeof updateLivePreview === 'function') {
            contentTextarea.addEventListener('input', updateLivePreview);
        }

        if (contentEditor) {
            if (typeof syncEditorOutput === 'function') {
                contentEditor.addEventListener('input', () => {
                    syncEditorOutput();
                });
            }

            contentEditor.addEventListener('click', (e) => {
                const embed = e.target.closest('.editor-embed');
                if (embed && contentEditor.contains(embed)) {
                    if (typeof showImageToolbar === 'function') {
                        showImageToolbar(embed);
                    }
                    return;
                }
                if (typeof hideImageToolbar === 'function') {
                    hideImageToolbar();
                }
            });

            if (typeof saveEditorRange === 'function') {
                contentEditor.addEventListener('keyup', saveEditorRange);
                contentEditor.addEventListener('mouseup', saveEditorRange);
                document.addEventListener('selectionchange', saveEditorRange);
            }

            document.addEventListener('click', (e) => {
                const imageToolbar = getImageToolbar();
                if (!imageToolbar || !imageToolbar.classList.contains('is-visible')) return;
                // 이미지 편집 모달이 열려있는 동안은 툴바를 숨기지 않는다
                if (getImageEditorState()) return;
                if (imageToolbar.contains(e.target)) return;
                if (contentEditor.contains(e.target) && e.target.closest('.editor-embed')) return;
                if (typeof hideImageToolbar === 'function') {
                    hideImageToolbar();
                }
            });

            if (typeof placeImageToolbar === 'function') {
                window.addEventListener('scroll', placeImageToolbar, true);
                window.addEventListener('resize', placeImageToolbar);
            }
        }

        if (typeof enableRichEditor === 'function') {
            enableRichEditor();
        }
        if (typeof updateLivePreview === 'function') {
            updateLivePreview();
        }

        if (previewButton && previewModal) {
            if (typeof openPreviewModal === 'function') {
                previewButton.addEventListener('click', openPreviewModal);
            }
            if (typeof closePreviewModal === 'function') {
                previewCloseButton?.addEventListener('click', closePreviewModal);
                previewModal.addEventListener('click', (e) => {
                    if (e.target === previewModal) {
                        closePreviewModal();
                    }
                });
            }
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !previewModal.hidden && typeof closePreviewModal ===
                    'function') {
                    closePreviewModal();
                }
                const imageEditorModal = getImageEditorModal();
                if (e.key === 'Escape' && imageEditorModal && !imageEditorModal.hidden &&
                    typeof closeImageEditor === 'function') {
                    closeImageEditor();
                }
            });
        }

        if (writeForm) {
            writeForm.addEventListener('submit', (e) => {
                if (typeof rebuildAttachmentInputFiles === 'function') {
                    rebuildAttachmentInputFiles();
                }
                if (typeof syncTextareaFromEditor === 'function') {
                    syncTextareaFromEditor();
                }
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
                div.innerHTML =
                    '<input type="text" name="poll_options[]" placeholder="선택지">' +
                    '<button type="button" class="add-opt remove-opt">삭제</button>';
                wrap.appendChild(div);
                div.querySelector('.remove-opt').addEventListener('click', () => div.remove());
            });
        }
        document.querySelectorAll('.remove-opt').forEach((b) => {
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
    };

    window.BOARD_initWritePage = initWritePage;
})(window);
