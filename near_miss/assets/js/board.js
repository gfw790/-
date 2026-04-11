// 사내 게시판 클라이언트 스크립트

(function() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.content : '';

    // 좋아요 토글
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
            } catch (e) { alert('네트워크 오류'); }
        });
    });

    // 댓글 답글 토글
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

    // 글쓰기 - 첨부파일 삭제 표시
    document.querySelectorAll('.existing-file .del').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.attachId;
            btn.parentElement.style.opacity = '0.4';
            btn.parentElement.style.textDecoration = 'line-through';
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'delete_attachments[]';
            hidden.value = id;
            document.querySelector('form.write-form, form#write-form').appendChild(hidden);
            btn.style.display = 'none';
        });
    });

    // 글쓰기 - 투표 추가
    const addOptBtn = document.querySelector('.add-opt');
    if (addOptBtn) {
        addOptBtn.addEventListener('click', () => {
            const wrap = document.querySelector('.poll-options-input');
            const div = document.createElement('div');
            div.className = 'poll-opt-input';
            div.innerHTML = '<input type="text" name="poll_options[]" placeholder="선택지"><button type="button" class="add-opt remove-opt">−</button>';
            wrap.appendChild(div);
            div.querySelector('.remove-opt').addEventListener('click', () => div.remove());
        });
    }
    document.querySelectorAll('.remove-opt').forEach(b => {
        b.addEventListener('click', () => b.parentElement.remove());
    });

    // 글쓰기 - 투표 사용 토글
    const usePoll = document.getElementById('use_poll');
    if (usePoll) {
        const toggle = () => {
            document.getElementById('poll_section').style.display = usePoll.checked ? '' : 'none';
        };
        usePoll.addEventListener('change', toggle);
        toggle();
    }

    // 아차사고 설문 카드 슬라이더
    const slider = document.querySelector('[data-survey-slider]');
    if (slider && slider.getAttribute('data-wired') !== '1') {
        slider.setAttribute('data-wired', '1');
        const cards = Array.from(slider.querySelectorAll('[data-survey-step]'));
        const prevBtn = slider.querySelector('[data-survey-prev]');
        const nextBtn = slider.querySelector('[data-survey-next]');
        const submitBtn = slider.querySelector('[data-survey-submit]');
        const progressBar = slider.querySelector('[data-survey-progress]');
        const progressText = slider.querySelector('[data-survey-progress-text]');
        let current = Math.max(0, cards.findIndex(c => c.classList.contains('is-active')));
        if (current < 0) current = 0;

        const render = () => {
            cards.forEach((card, idx) => {
                card.classList.toggle('is-active', idx === current);
            });

            const total = cards.length || 1;
            const percent = ((current + 1) / total) * 100;
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressText) progressText.textContent = (current + 1) + ' / ' + total + ' 단계';

            if (prevBtn) prevBtn.disabled = current === 0;
            if (nextBtn) nextBtn.style.display = current === total - 1 ? 'none' : '';
            if (submitBtn) submitBtn.style.display = current === total - 1 ? '' : 'none';
        };

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (current > 0) {
                    current -= 1;
                    render();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (current < cards.length - 1) {
                    current += 1;
                    render();
                }
            });
        }

        render();
    }
})();
