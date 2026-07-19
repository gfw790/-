  </main>

  <footer class="board-footer">
    <div class="footer-inner">
      <span>&copy; <?= date('Y') ?> <?= h(BOARD_TITLE) ?></span>
      <span class="footer-sep">|</span>
      <span>문의: 관리자</span>
    </div>
  </footer>

</div>
<style>
  body.board-mobile-nav-open {
    overflow: hidden;
  }
  .board-mobile-bottom-nav,
  .board-mobile-sheet,
  .board-mobile-scrim {
    display: none;
  }
  @media (max-width: 820px) {
    body {
      padding-bottom: 118px;
    }
    .board-mobile-bottom-nav {
      display: block;
      position: fixed;
      left: 12px;
      right: 12px;
      bottom: max(10px, env(safe-area-inset-bottom));
      z-index: 1000;
      border: 1px solid var(--border2);
      border-radius: 20px;
      background: rgba(10, 17, 28, 0.96);
      backdrop-filter: blur(14px);
      box-shadow: 0 18px 40px rgba(0,0,0,0.38);
      padding: 8px;
    }
    .board-mobile-bottom-nav-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 6px;
    }
    .board-mobile-nav-link,
    .board-mobile-nav-button {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 4px;
      min-height: 58px;
      border-radius: 14px;
      border: 1px solid transparent;
      background: transparent;
      color: var(--text);
      text-decoration: none;
      font: inherit;
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
    }
    .board-mobile-nav-link.is-active,
    .board-mobile-nav-button.is-active {
      background: linear-gradient(180deg, rgba(245,166,35,0.2), rgba(245,166,35,0.1));
      border-color: rgba(245,166,35,0.35);
      color: #fff4df;
    }
    .board-mobile-nav-icon {
      font-size: 18px;
      line-height: 1;
    }
    .board-mobile-scrim {
      position: fixed;
      inset: 0;
      z-index: 1001;
      background: rgba(5,10,18,0.58);
    }
    .board-mobile-sheet {
      position: fixed;
      left: 12px;
      right: 12px;
      bottom: calc(max(10px, env(safe-area-inset-bottom)) + 84px);
      z-index: 1002;
      border: 1px solid var(--border2);
      border-radius: 22px;
      background: #101b2b;
      box-shadow: 0 24px 48px rgba(0,0,0,0.42);
      padding: 16px;
    }
    .board-mobile-sheet-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }
    .board-mobile-sheet-title {
      color: var(--text-hi);
      font-size: 16px;
      font-weight: 800;
    }
    .board-mobile-sheet-close {
      border: none;
      background: rgba(255,255,255,0.08);
      color: var(--text-hi);
      border-radius: 999px;
      width: 34px;
      height: 34px;
      font-size: 18px;
      cursor: pointer;
    }
    .board-mobile-sheet-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .board-mobile-sheet-grid .btn-link,
    .board-mobile-sheet-grid .btn-link-cta {
      width: 100%;
      min-height: 46px;
    }
  }
</style>
<div class="board-mobile-scrim" id="board-mobile-scrim" hidden></div>
<div class="board-mobile-sheet" id="board-mobile-sheet" hidden>
  <div class="board-mobile-sheet-head">
    <div class="board-mobile-sheet-title">추가 메뉴</div>
    <button type="button" class="board-mobile-sheet-close" id="board-mobile-sheet-close" aria-label="닫기">&times;</button>
  </div>
  <div class="board-mobile-sheet-grid">
    <a class="btn-link" href="<?= h($_mainPageHref) ?>">메인으로</a>
    <?php if ($_currentUser): ?>
      <?php if (in_array($_currentUser['role'] ?? '', ['admin', 'administrator'], true)): ?>
        <a class="btn-link" href="admin.php">관리</a>
      <?php endif; ?>
      <a class="btn-link btn-link-cta" href="../near_miss/">아차사고</a>
    <?php endif; ?>
  </div>
</div>
<nav class="board-mobile-bottom-nav" aria-label="게시판 모바일 하단 메뉴">
  <div class="board-mobile-bottom-nav-grid">
    <a class="board-mobile-nav-link" href="../risk_assessment/index.php">
      <span class="board-mobile-nav-icon">⌂</span>
      <span>홈</span>
    </a>
    <a class="board-mobile-nav-link" href="../calendar/index.html">
      <span class="board-mobile-nav-icon">◫</span>
      <span>달력</span>
    </a>
    <a class="board-mobile-nav-link" href="../risk_assessment/work_list.php">
      <span class="board-mobile-nav-icon">≡</span>
      <span>목록</span>
    </a>
    <a class="board-mobile-nav-link is-active" href="index.php">
      <span class="board-mobile-nav-icon">▣</span>
      <span>게시판</span>
    </a>
    <button type="button" class="board-mobile-nav-button" id="board-mobile-nav-more">
      <span class="board-mobile-nav-icon">◎</span>
      <span>더보기</span>
    </button>
  </div>
</nav>
<script src="assets/js/board.common-utils.js"></script>
<script src="assets/js/board.likes.js"></script>
<script src="assets/js/board.comments.js"></script>
<script src="assets/js/board.init.js"></script>
<script src="assets/js/board.image-editor-template.js"></script>
<script src="assets/js/board.attachments.js"></script>
<script src="assets/js/board.editor.js"></script>
<script src="assets/js/board.image-toolbar.js"></script>
<script src="assets/js/board.image-editor.js"></script>
<script src="assets/js/board.preview.js"></script>
<script src="assets/js/board.js"></script>
<script>
  (() => {
    const mobileNavMore = document.getElementById('board-mobile-nav-more');
    const mobileSheet = document.getElementById('board-mobile-sheet');
    const mobileScrim = document.getElementById('board-mobile-scrim');
    const mobileClose = document.getElementById('board-mobile-sheet-close');

    const setMobileSheetOpen = (open) => {
      if (!mobileNavMore || !mobileSheet || !mobileScrim) {
        return;
      }

      mobileSheet.hidden = !open;
      mobileScrim.hidden = !open;
      mobileNavMore.classList.toggle('is-active', open);
      document.body.classList.toggle('board-mobile-nav-open', open);
    };

    if (mobileNavMore && mobileSheet && mobileScrim && mobileClose) {
      mobileNavMore.addEventListener('click', () => setMobileSheetOpen(mobileSheet.hidden));
      mobileClose.addEventListener('click', () => setMobileSheetOpen(false));
      mobileScrim.addEventListener('click', () => setMobileSheetOpen(false));
    }
  })();
</script>
</body>
</html>
