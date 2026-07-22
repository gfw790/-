<?php
/** @var array<int, array<string, mixed>> $mobileGlossary */
/** @var string $materialName */
/** @var ?array<string, mixed> $record */
/** @var string $downloadUrl */
/** @var string $pdfUrl */
/** @var bool $isPdf */
/** @var string $manufacturer */
/** @var string $createdDate */
/** @var string $revisedDate */
/** @var string $revisionCount */
/** @var string $note */
/** @var bool $canEditMobileMsds */
/** @var array<int, array<string, mixed>> $serverRenderSections */
/** @var string $serverRenderStatus */
/** @var string $serverRenderHtml */
/** @var ?array<string, mixed> $editSection */
/** @var string $editSectionBody */
/** @var string $editSectionTitle */
/** @var bool $editSectionSaved */
/** @var int $editSectionNumber */
/** @var bool $glossaryEditorOpen */
/** @var bool $glossaryEditorSaved */
/** @var array<int, array<string, mixed>> $glossaryEditorRows */
?>
<?php foreach ($mobileGlossary as $index => $entry): ?>
  <div class="mobile-glossary-sheet" id="<?= h('mobile-glossary-entry-' . $index) ?>" aria-hidden="true">
    <a class="mobile-glossary-sheet-backdrop mobile-glossary-sheet-close" href="#mobile-glossary-dismiss" onclick="return window.__closeMobileGlossarySheet ? window.__closeMobileGlossarySheet(event) : true;" aria-label="닫기"></a>
    <div class="mobile-glossary-sheet-dialog" role="dialog" aria-modal="true" aria-labelledby="<?= h('mobile-glossary-sheet-title-' . $index) ?>">
      <div class="mobile-glossary-head">
        <p class="mobile-glossary-eyebrow">TERM GUIDE</p>
        <h3 id="<?= h('mobile-glossary-sheet-title-' . $index) ?>"><?= h(trim((string)($entry['title'] ?? $entry['term'] ?? '용어 설명'))) ?></h3>
      </div>
      <div class="mobile-glossary-content"><?= h(trim((string)($entry['content'] ?? ''))) ?></div>
      <div class="mobile-glossary-actions">
        <a class="btn btn-ghost mobile-glossary-sheet-close" href="#mobile-glossary-dismiss" onclick="return window.__closeMobileGlossarySheet ? window.__closeMobileGlossarySheet(event) : true;">닫기</a>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<div class="reader-shell<?= $canEditMobileMsds ? ' can-edit-mobile-msds' : '' ?>">
  <div id="msds-reader-top"></div>
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

    <div class="reader-content-grid">
    <section class="mobile-text-reader" id="mobile-text-reader">
      <div class="mobile-text-head">
        <h2>MSDS 리더</h2>
      </div>
      <?php if ($canEditMobileMsds): ?>
        <div class="mobile-glossary-manage">
          <a class="btn btn-ghost mobile-glossary-manage-pc" href="<?= h(msds_reader_glossary_editor_url((string)($record['id'] ?? ''))) ?>" target="_blank" rel="noopener">용어 설명 관리</a>
        </div>
      <?php endif; ?>
      <div class="mobile-section-jump" id="mobile-section-jump">
        <select class="mobile-section-select" id="mobile-section-select" aria-label="카드별 이동" onchange="if(this.value){ window.location.hash = this.value; }">
          <option value="">항목별 이동</option>
          <?php foreach (array_values(array_slice($serverRenderSections, 1)) as $index => $section): ?>
            <?php $sectionTitle = trim((string)($section['title'] ?? '')); ?>
            <?php if ($sectionTitle === '') { continue; } ?>
            <option value="<?= h('mobile-msds-section-' . ($index + 2)) ?>"><?= h($sectionTitle) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mobile-text-status" id="mobile-text-status"><?= h($serverRenderStatus) ?></div>
      <?php if ($canEditMobileMsds && $editSection !== null): ?>
        <section class="pc-section-editor" id="msds-section-editor">
          <div class="pc-section-editor-head">
            <h3><?= h($editSectionTitle !== '' ? $editSectionTitle : '선택 항목 편집') ?></h3>
            <p>PC 브라우저에서 이 카드 내용을 바로 수정하고 저장할 수 있습니다.</p>
          </div>
          <?php if ($editSectionSaved): ?>
            <div class="pc-section-editor-notice">저장되었습니다.</div>
          <?php endif; ?>
          <form class="pc-section-editor-form" method="post" action="msds_reader.php?id=<?= h((string)($record['id'] ?? '')) ?>#msds-section-editor">
            <input type="hidden" name="action" value="save_mobile_section">
            <input type="hidden" name="record_id" value="<?= h((string)($record['id'] ?? '')) ?>">
            <input type="hidden" name="section_number" value="<?= h((string)$editSectionNumber) ?>">
            <input type="hidden" name="section_title" value="<?= h($editSectionTitle) ?>">
            <textarea class="pc-section-editor-textarea" name="section_body" spellcheck="false"><?= h($editSectionBody) ?></textarea>
            <div class="pc-section-editor-actions">
              <button class="btn btn-accent" type="submit">저장</button>
              <a class="btn btn-ghost" href="msds_reader.php?id=<?= h((string)($record['id'] ?? '')) ?>">닫기</a>
            </div>
          </form>
        </section>
      <?php endif; ?>
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
    </div>
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

<a class="mobile-scroll-top" id="mobile-scroll-top" href="#msds-reader-top" aria-label="맨 위로 이동">맨 위</a>

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

<div class="mobile-glossary-modal" id="mobile-glossary-modal" aria-hidden="true">
  <div class="mobile-glossary-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-glossary-title">
    <div class="mobile-glossary-head">
      <p class="mobile-glossary-eyebrow">TERM GUIDE</p>
      <h3 id="mobile-glossary-title">용어 설명</h3>
    </div>
    <div class="mobile-glossary-content" id="mobile-glossary-content"></div>
    <div class="mobile-glossary-actions">
      <button class="btn btn-ghost" type="button" id="mobile-glossary-close">닫기</button>
    </div>
  </div>
</div>

<?php if ($canEditMobileMsds): ?>
  <div class="mobile-glossary-editor-modal" id="mobile-glossary-editor-modal" aria-hidden="true">
    <div class="mobile-glossary-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="mobile-glossary-editor-title">
      <div class="mobile-glossary-editor-head">
        <h3 id="mobile-glossary-editor-title">용어 설명 관리</h3>
        <p>특정 용어나 문장을 입력하면 모바일 본문에서 눌러 볼 수 있는 설명 링크로 연결됩니다.</p>
      </div>
      <div class="mobile-glossary-editor-list" id="mobile-glossary-editor-list"></div>
      <button class="btn btn-soft mobile-glossary-add" type="button" id="mobile-glossary-add">용어 추가</button>
      <div class="mobile-glossary-editor-actions">
        <button class="btn btn-ghost" type="button" id="mobile-glossary-editor-cancel">닫기</button>
        <button class="btn btn-accent" type="button" id="mobile-glossary-editor-save">저장</button>
      </div>
    </div>
  </div>
<?php endif; ?>
