(function(window) {
    const bindCommentInteractions = ({
        csrfToken = '',
        replyToggleSelector = '.reply-toggle',
        commentDeleteSelector = '.comment-delete',
        endpoint = 'comment.php',
    } = {}) => {
        document.querySelectorAll(replyToggleSelector).forEach((link) => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const id = link.dataset.commentId;
                const form = document.getElementById('reply-form-' + id);
                if (form) {
                    form.style.display = form.style.display === 'none' ? 'block' : 'none';
                }
            });
        });

        document.querySelectorAll(commentDeleteSelector).forEach((link) => {
            link.addEventListener('click', async (e) => {
                e.preventDefault();
                if (!confirm('이 댓글을 삭제하시겠습니까?')) return;

                const id = link.dataset.commentId;
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('comment_id', id);
                fd.append('csrf', csrfToken);

                const res = await fetch(endpoint, {
                    method: 'POST',
                    body: fd,
                });
                const data = await res.json();
                if (data.ok) {
                    location.reload();
                } else {
                    alert(data.message || '삭제 실패');
                }
            });
        });
    };

    window.BOARD_bindCommentInteractions = bindCommentInteractions;
})(window);
