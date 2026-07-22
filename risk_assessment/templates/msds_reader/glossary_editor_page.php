<?php
/** @var string $materialName */
/** @var ?array<string, mixed> $record */
/** @var bool $glossaryEditorSaved */
/** @var array<int, array<string, mixed>> $glossaryEditorRows */
?>
<div class="reader-shell can-edit-mobile-msds">
  <div class="reader-topbar">
    <div class="reader-topbar-inner">
      <div class="reader-title">
        <p class="eyebrow">MSDS GLOSSARY EDITOR</p>
        <h1><?= h($materialName !== '' ? $materialName : '용어 설명 관리') ?></h1>
      </div>
      <div class="reader-actions">
        <a class="btn btn-ghost" href="msds_reader.php?id=<?= h((string)($record['id'] ?? '')) ?>">MSDS 보기로 돌아가기</a>
      </div>
    </div>
  </div>

  <section class="pc-glossary-editor pc-glossary-editor-page" id="msds-glossary-editor">
    <div class="pc-glossary-editor-head">
      <h3>용어 설명 관리</h3>
      <p>PC 브라우저에서 모바일 리더용 용어 설명을 별도 화면에서 정리할 수 있습니다.</p>
    </div>
    <form class="pc-glossary-editor-form" method="post" action="msds_reader.php?id=<?= h((string)($record['id'] ?? '')) ?>&glossary_editor=1#msds-glossary-editor">
      <input type="hidden" name="action" value="save_mobile_glossary_form">
      <input type="hidden" name="record_id" value="<?= h((string)($record['id'] ?? '')) ?>">
      <div class="pc-glossary-editor-toolbar">
        <?php if ($glossaryEditorSaved): ?>
          <div class="pc-section-editor-notice">저장되었습니다.</div>
        <?php endif; ?>
        <div class="pc-glossary-editor-actions">
          <button class="btn btn-accent" type="submit">저장</button>
          <a class="btn btn-ghost" href="msds_reader.php?id=<?= h((string)($record['id'] ?? '')) ?>">닫기</a>
        </div>
      </div>
      <div class="pc-glossary-editor-list">
        <?php foreach ($glossaryEditorRows as $index => $entry): ?>
          <div class="pc-glossary-row<?= $index === 0 ? ' pc-glossary-row-new' : '' ?>">
            <label class="pc-glossary-field">
              <span><?= $index === 0 ? '새 용어 또는 문장 추가' : '표시할 용어 또는 문장' ?></span>
              <input type="text" name="glossary_term[]" value="<?= h((string)($entry['term'] ?? '')) ?>" placeholder="예: 고압가스 : 액화가스">
            </label>
            <label class="pc-glossary-field">
              <span>모달 제목</span>
              <input type="text" name="glossary_title[]" value="<?= h((string)($entry['title'] ?? '')) ?>" placeholder="비우면 용어와 같은 제목으로 표시됩니다.">
            </label>
            <label class="pc-glossary-field pc-glossary-field-wide">
              <span>설명 내용</span>
              <textarea name="glossary_content[]" rows="5" placeholder="작업자가 눌렀을 때 볼 설명을 입력해 주세요."><?= h((string)($entry['content'] ?? '')) ?></textarea>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </form>
  </section>
</div>
