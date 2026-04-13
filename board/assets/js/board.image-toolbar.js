// // 6. 이미지 툴바 — hideImageToolbar, showImageToolbar, placeImageToolbar, applyEmbedOption, deleteEmbed
(function(window) {
    window.BOARD_createImageToolbar = function(app) {
        app.hideImageToolbar = () => {
            if (!app.imageToolbar) return;
            app.imageToolbar.classList.remove('is-visible');
            app.activeEmbed = null;
        };

        app.placeImageToolbar = () => {
            if (!app.imageToolbar || !app.activeEmbed) return;
            const rect = app.activeEmbed.getBoundingClientRect();
            const toolbarRect = app.imageToolbar.getBoundingClientRect();
            const top = Math.max(8, rect.top - toolbarRect.height - 8);
            const left = Math.max(8, Math.min(rect.left, window.innerWidth - toolbarRect.width - 8));
            app.imageToolbar.style.top = `${top}px`;
            app.imageToolbar.style.left = `${left}px`;
        };

        app.showImageToolbar = (embed) => {
            if (!app.imageToolbar || !embed) return;
            app.activeEmbed = embed;
            app.imageToolbar.classList.add('is-visible');
            app.placeImageToolbar();
        };

        app.applyEmbedOption = (embed, patch = {}) => {
            if (!embed) return;
            app.applyEmbedVisualClass(embed, {
                align: patch.align || embed.dataset.align || app.defaultEmbedOptions.align,
                size: patch.size || embed.dataset.size || app.defaultEmbedOptions.size,
                rotate: patch.rotate ?? embed.dataset.rotate ?? app.defaultEmbedOptions.rotate,
                flip: patch.flip ?? embed.dataset.flip ?? app.defaultEmbedOptions.flip,
            });
            app.syncEditorOutput();
            app.showImageToolbar(embed);
        };

        app.deleteEmbed = (embed) => {
            if (!embed) return;
            const next = embed.nextSibling;
            if (next && next.nodeType === Node.ELEMENT_NODE && next.tagName === 'BR') {
                next.remove();
            }
            embed.remove();
            app.hideImageToolbar();
            app.syncEditorOutput();
        };
    };
})(window);
