// 사내 게시판 클라이언트 스크립트

(function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (typeof window.BOARD_bindLikeButtons === 'function') {
        window.BOARD_bindLikeButtons({
            csrfToken: csrf,
        });
    }
    if (typeof window.BOARD_bindCommentInteractions === 'function') {
        window.BOARD_bindCommentInteractions({
            csrfToken: csrf,
        });
    }

    // ── DOM 참조 ────────────────────────────────────────────────────────────
    const writeForm = document.querySelector('form.write-form, form#write-form');
    const contentTextarea = document.getElementById('content') ||
        document.querySelector('textarea[name="content"]');
    const contentEditor = document.getElementById('content-editor');
    const titleInput = writeForm?.querySelector('input[name="title"]');
    const attachmentInput = document.getElementById('attachments') ||
        document.querySelector('input[type="file"][name="attachments[]"]');
    const newAttachmentTokenList = document.getElementById('new-attachment-token-list');
    const previewButton = document.getElementById('preview-write-btn');
    const previewModal = document.getElementById('write-preview-modal');
    const previewCloseButton = document.getElementById('close-write-preview');
    const previewTitle = document.getElementById('write-preview-title');
    const previewContent = document.getElementById('write-preview-content');
    const livePreviewBody = document.getElementById('live-content-preview-body');

    // ── 상수 ────────────────────────────────────────────────────────────────
    const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    const blockTags = new Set([
        'DIV', 'P', 'LI', 'UL', 'OL', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
        'BLOCKQUOTE', 'PRE', 'FIGURE',
    ]);
    const RICHTEXT_PREFIX = '<!--richtext-->';
    const INLINE_FORMAT_TAGS = new Set(['B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'SPAN', 'FONT']);
    const defaultEmbedOptions = { align: 'center', size: 'medium', rotate: 0, flip: false };
    const defaultTextStyle = {
        size: 22, bold: false, italic: false, underline: false, strike: false, color: '#ff2d2d',
    };
    const defaultShapeStyle = {
        type: 'rect', lineWidth: 2, lineStyle: 'solid', colorTarget: 'stroke',
        strokeColor: '#ff2d2d', strokeAlpha: 1, fillColor: '#000000', fillAlpha: 0,
    };
    const defaultDrawStyle = { lineWidth: 2, color: '#ff2d2d', alpha: 1 };
    const textPresetColors = [
        '#ff0000', '#ff5a00', '#ff9100', '#ffc400', '#ffe600', '#c6d300', '#8bc34a', '#00b894',
        '#00a8ff', '#2f80ed', '#3f51b5', '#673ab7', '#9c27b0', '#e91e63', '#795548', '#9e9e9e',
        '#607d8b', '#000000', '#ffffff', '#f3e5f5',
    ];
    const shapePresetColors = [
        'transparent',
        '#ff0000', '#ff5a00', '#ff9100', '#ffc400', '#ffe600', '#c6d300', '#8bc34a', '#00b894',
        '#00a8ff', '#2f80ed', '#3f51b5', '#673ab7', '#9c27b0', '#e91e63', '#795548', '#9e9e9e',
        '#607d8b', '#000000', '#ffffff',
    ];

    // ── 공통 헬퍼 ────────────────────────────────────────────────────────────
    const boardCommonUtils = window.BOARD_commonUtils || {};
    const escapeHtml = boardCommonUtils.escapeHtml || ((str) => String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;'));
    const clearObjectUrls = boardCommonUtils.clearObjectUrls || ((list) => {
        list.forEach((url) => URL.revokeObjectURL(url));
        list.length = 0;
    });

    // ── 공유 상태 + 컨텍스트 객체 ─────────────────────────────────────────
    const app = {
        // DOM 참조
        writeForm, contentTextarea, contentEditor, titleInput,
        attachmentInput, newAttachmentTokenList,
        previewButton, previewModal, previewCloseButton,
        previewTitle, previewContent, livePreviewBody,

        // 상수
        imageExts, blockTags, RICHTEXT_PREFIX, INLINE_FORMAT_TAGS,
        defaultEmbedOptions, defaultTextStyle, defaultShapeStyle, defaultDrawStyle,
        textPresetColors, shapePresetColors,

        // 헬퍼
        escapeHtml, clearObjectUrls,

        // 가변 상태 (스칼라)
        editorLastRange: null,
        imageToolbar: null,
        activeEmbed: null,
        imageEditorModal: null,
        imageEditorState: null,
        nmFileEditCallback: null,

        // 가변 상태 (컬렉션)
        modalPreviewObjectUrls: [],
        livePreviewObjectUrls: [],
        editorObjectUrls: [],
        editedEmbedObjectUrls: [],
        generatedEditedFiles: new Map(),
        stagedUploadFiles: new Map(),

        // board.init.js 호환용 게터 함수 (비구조화 후에도 this 유지되도록 화살표 함수 사용)
        // eslint-disable-next-line no-use-before-define
        getImageToolbar: () => app.imageToolbar,
        // eslint-disable-next-line no-use-before-define
        getImageEditorState: () => app.imageEditorState,
        // eslint-disable-next-line no-use-before-define
        getImageEditorModal: () => app.imageEditorModal,
    };

    // ── 모듈 초기화 (순서 중요) ──────────────────────────────────────────────
    // 4. 첨부파일
    window.BOARD_createAttachments?.(app);
    // 5. 에디터 렌더/직렬화
    window.BOARD_createEditor?.(app);
    // 6. 이미지 툴바 (에디터 의존)
    window.BOARD_createImageToolbar?.(app);
    // 7. 이미지 편집 모달 (툴바·에디터·첨부파일 의존)
    window.BOARD_createImageEditor?.(app);
    // 8. 미리보기 (모든 모듈 의존)
    window.BOARD_createPreview?.(app);

    // 9. 이벤트 바인딩
    if (typeof window.BOARD_initWritePage === 'function') {
        window.BOARD_initWritePage(app);
    }
})();
