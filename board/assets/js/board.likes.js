(function(window) {
    const bindLikeButtons = ({
        csrfToken = '',
        selector = '.like-btn',
        endpoint = 'like.php',
    } = {}) => {
        document.querySelectorAll(selector).forEach((btn) => {
            btn.addEventListener('click', async () => {
                const postId = btn.dataset.postId;
                try {
                    const fd = new FormData();
                    fd.append('post_id', postId);
                    fd.append('csrf', csrfToken);

                    const res = await fetch(endpoint, {
                        method: 'POST',
                        body: fd,
                    });
                    const data = await res.json();

                    if (data.ok) {
                        btn.classList.toggle('liked', data.liked);
                        const countEl = btn.querySelector('.like-count');
                        if (countEl) {
                            countEl.textContent = data.count;
                        }
                        return;
                    }

                    alert(data.message || '오류가 발생했습니다.');
                } catch (e) {
                    alert('네트워크 오류');
                }
            });
        });
    };

    window.BOARD_bindLikeButtons = bindLikeButtons;
})(window);
