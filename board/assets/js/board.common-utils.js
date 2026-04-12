(function(window) {
    const boardCommonUtils = window.BOARD_commonUtils || {};

    boardCommonUtils.escapeHtml = (str) => String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    boardCommonUtils.isImageFileName = (fileName, imageExts = []) => {
        const ext = String(fileName || '').toLowerCase().split('.').pop();
        return Array.isArray(imageExts) && imageExts.includes(ext);
    };

    boardCommonUtils.clearObjectUrls = (list) => {
        if (!Array.isArray(list)) return;
        list.forEach((url) => URL.revokeObjectURL(url));
        list.length = 0;
    };

    window.BOARD_commonUtils = boardCommonUtils;
})(window);
