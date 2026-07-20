  <div class="reader-shell">
    <div class="reader-topbar">
      <div class="reader-topbar-inner">
        <div class="reader-title">
          <p class="eyebrow">MSDS MOBILE READER</p>
          <h1><?= h($materialName !== '' ? $materialName : 'MSDS 문서 보기') ?></h1>
        </div>
        <div class="reader-actions">
          <a class="btn btn-soft" href="msds_list.php">목록</a>
          <?php if ($record !== null): ?>
            <a class="btn btn-ghost" href="<?= h($downloadUrl) ?>">다운로드</a>
            <a class="btn btn-accent" href="<?= h($pdfUrl) ?>" target="_blank" rel="noopener">원본보기</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($record === null || !$isPdf): ?>
      <section class="status-card">
        <h2>문서를 찾을 수 없습니다</h2>
        <p>해당 MSDS PDF가 없거나 열 수 없는 상태입니다. 목록으로 돌아가 다시 선택해 주세요.</p>
      </section>
    <?php else: ?>
      <section class="info-card">
        <dl class="info-grid">
          <div class="info-item">
            <dt>물질명</dt>
            <dd><?= h($materialName !== '' ? $materialName : '-') ?></dd>
          </div>
          <div class="info-item">
            <dt>제조사</dt>
            <dd><?= h($manufacturer !== '' ? $manufacturer : '-') ?></dd>
          </div>
          <div class="info-item">
            <dt>작성일자 / 개정일자</dt>
            <dd><?= h(($createdDate !== '' ? $createdDate : '-') . ' / ' . ($revisedDate !== '' ? $revisedDate : '-')) ?></dd>
          </div>
          <div class="info-item">
            <dt>개정횟수 / 비고</dt>
            <dd><?= h(($revisionCount !== '' ? $revisionCount : '-') . ' / ' . ($note !== '' ? $note : '-')) ?></dd>
          </div>
        </dl>
      </section>

      <section class="mobile-text-reader" id="mobile-text-reader">
        <div class="mobile-text-head">
          <h2>MSDS 리더</h2>
        </div>
        <div class="mobile-section-jump" id="mobile-section-jump">
          <select class="mobile-section-select" id="mobile-section-select" aria-label="카드별 이동">
            <option value="">항목별 이동</option>
          </select>
        </div>
        <div class="mobile-text-status" id="mobile-text-status"><?= h($serverRenderStatus) ?></div>
        <div class="mobile-text-body" id="mobile-text-body">
          <?= $serverRenderHtml ?>
        </div>
      </section>

      <section class="viewer-card">
        <div class="viewer-toolbar">
          <div class="viewer-toolbar-group">
            <button class="btn btn-ghost" type="button" id="prev-page">이전 페이지</button>
            <button class="btn btn-ghost" type="button" id="next-page">다음 페이지</button>
          </div>
          <div class="viewer-toolbar-group">
            <button class="btn btn-ghost" type="button" id="zoom-out">축소</button>
            <button class="btn btn-ghost" type="button" id="zoom-in">확대</button>
            <select class="viewer-page-select" id="page-select" aria-label="페이지 선택"></select>
          </div>
          <div class="viewer-page-indicator" id="page-indicator">불러오는 중...</div>
        </div>

        <div class="viewer-canvas-wrap is-loading" id="pdf-stage">
          <canvas id="pdf-canvas"></canvas>
        </div>
        <div class="viewer-embed-wrap">
          <iframe class="viewer-embed-frame" src="<?= h($pdfUrl) ?>#toolbar=1&navpanes=0&view=FitH" title="MSDS PDF 보기"></iframe>
        </div>
        <div class="viewer-help">
          모바일에서는 한 페이지씩 크게 볼 수 있도록 구성했습니다. 확대 버튼으로 글자와 그림을 더 쉽게 볼 수 있고, 필요하면 상단의 원본보기 버튼으로 PDF 원문도 확인할 수 있습니다.
        </div>
      </section>
    <?php endif; ?>
  </div>

  <nav class="mobile-bottom-nav" aria-label="모바일 하단 메뉴">
    <div class="mobile-bottom-nav-grid">
      <a class="mobile-nav-link" href="index.php">
        <span class="mobile-nav-icon">⌂</span>
        <span>홈</span>
      </a>
      <a class="mobile-nav-link" href="../calendar/index.html">
        <span class="mobile-nav-icon">◷</span>
        <span>달력</span>
      </a>
      <a class="mobile-nav-link" href="work_list.php">
        <span class="mobile-nav-icon">▤</span>
        <span>작업목록</span>
      </a>
      <a class="mobile-nav-link is-active" href="msds_list.php">
        <span class="mobile-nav-icon">M</span>
        <span>MSDS</span>
      </a>
      <a class="mobile-nav-link" href="more.php">
        <span class="mobile-nav-icon">⋯</span>
        <span>더보기</span>
      </a>
    </div>
  </nav>

  <button class="mobile-scroll-top" id="mobile-scroll-top" type="button" aria-label="맨 위로 이동">맨 위</button>

  <div class="mobile-editor-modal" id="mobile-editor-modal" aria-hidden="true">
    <div class="mobile-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-editor-title">
      <div class="mobile-editor-head">
        <h3 id="mobile-editor-title">본문 편집</h3>
        <p>카드 내용을 수정한 뒤 적용을 누르면 현재 화면에 바로 반영됩니다.</p>
      </div>
      <textarea class="mobile-editor-textarea" id="mobile-editor-textarea" spellcheck="false"></textarea>
      <div class="mobile-editor-actions">
        <button class="btn btn-ghost" type="button" id="mobile-editor-cancel">닫기</button>
        <button class="btn btn-accent" type="button" id="mobile-editor-apply">적용</button>
      </div>
    </div>
  </div>
