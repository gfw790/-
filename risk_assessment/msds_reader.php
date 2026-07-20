<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function msds_reader_storage_path(): string
{
    return __DIR__ . '/msds_records.json';
}

function msds_reader_read_records(): array
{
    $path = msds_reader_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function msds_reader_find_record(array $records, string $id): ?array
{
    foreach ($records as $record) {
        if ((string)($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

function msds_reader_extension(array $record): string
{
    $originalName = trim((string)($record['original_name'] ?? ''));
    $storedName = trim((string)($record['stored_name'] ?? ''));
    $candidate = $originalName !== '' ? $originalName : $storedName;
    return strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
}

$records = msds_reader_read_records();
$recordId = trim((string)($_GET['id'] ?? ''));
$record = $recordId !== '' ? msds_reader_find_record($records, $recordId) : null;
$isPdf = $record !== null && msds_reader_extension($record) === 'pdf';

if ($record === null || !$isPdf) {
    http_response_code(404);
}

$materialName = (string)($record['material_name'] ?? '');
$manufacturer = (string)($record['manufacturer'] ?? '');
$createdDate = (string)($record['created_date'] ?? '');
$revisedDate = (string)($record['revised_date'] ?? '');
$revisionCount = (string)($record['revision_count'] ?? '');
$note = (string)($record['note'] ?? '');
$mobileContent = (string)($record['mobile_content'] ?? '');
$pdfUrl = $record !== null ? 'msds_list.php?view_file=' . rawurlencode((string)($record['id'] ?? '')) : '';
$downloadUrl = $record !== null ? 'msds_list.php?download_file=' . rawurlencode((string)($record['id'] ?? '')) : 'msds_list.php';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>MSDS 보기</title>
<style>
  * { box-sizing: border-box; }
  :root {
    --bg: #08111d;
    --panel: #0f1d31;
    --panel-soft: #13243c;
    --line: rgba(194, 211, 229, 0.18);
    --text: #f5f8fc;
    --muted: #9fb4c8;
    --accent: #ffb11a;
    --accent-dark: #1f2937;
    --shadow: 0 24px 50px rgba(0, 0, 0, 0.28);
  }
  html {
    background: var(--bg);
    scroll-behavior: smooth;
  }
  body {
    margin: 0;
    min-height: 100vh;
    background:
      radial-gradient(circle at top, rgba(255, 177, 26, 0.16), transparent 28%),
      linear-gradient(180deg, #091220 0%, #0a1423 100%);
    color: var(--text);
    font-family: "Malgun Gothic", sans-serif;
  }
  a { color: inherit; }
  .reader-shell {
    width: min(100%, 1240px);
    margin: 0 auto;
    padding: 22px 18px 40px;
  }
  .reader-topbar {
    position: sticky;
    top: 0;
    z-index: 50;
    margin-bottom: 16px;
    padding-top: max(12px, env(safe-area-inset-top));
    backdrop-filter: blur(18px);
  }
  .reader-topbar-inner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 22px;
    background: rgba(11, 22, 38, 0.82);
    box-shadow: var(--shadow);
  }
  .reader-title {
    min-width: 0;
  }
  .reader-title .eyebrow {
    margin: 0 0 6px;
    color: var(--muted);
    font-size: 12px;
    letter-spacing: 0.16em;
    text-transform: uppercase;
  }
  .reader-title h1 {
    margin: 0;
    font-size: 22px;
    line-height: 1.3;
    word-break: keep-all;
  }
  .reader-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 14px;
    border: 1px solid transparent;
    border-radius: 14px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
  }
  .btn-accent {
    background: var(--accent);
    color: var(--accent-dark);
  }
  .btn-ghost {
    background: rgba(255, 255, 255, 0.06);
    border-color: var(--line);
    color: var(--text);
  }
  .btn-soft {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.12);
    color: var(--text);
  }
  .info-card,
  .viewer-card,
  .status-card {
    border: 1px solid var(--line);
    border-radius: 28px;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.96), rgba(12, 23, 38, 0.98));
    box-shadow: var(--shadow);
  }
  .info-card {
    padding: 20px;
    margin-bottom: 16px;
  }
  .info-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .info-item {
    padding: 14px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .info-item dt {
    margin: 0 0 8px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
  }
  .info-item dd {
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
    word-break: break-word;
  }
  .status-card {
    padding: 24px 20px;
    text-align: center;
  }
  .status-card h2 {
    margin: 0 0 10px;
    font-size: 24px;
  }
  .status-card p {
    margin: 0;
    color: var(--muted);
    line-height: 1.7;
  }
  .viewer-card {
    padding: 16px;
  }
  .mobile-text-reader {
    display: none;
    margin-bottom: 14px;
    border: 1px solid var(--line);
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.98), rgba(11, 21, 35, 0.98));
    box-shadow: var(--shadow);
    overflow: hidden;
  }
  .mobile-text-head {
    padding: 16px 18px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    background: linear-gradient(135deg, rgba(255, 177, 26, 0.15), rgba(255, 255, 255, 0.02));
  }
  .mobile-text-head h2 {
    margin: 0 0 6px;
    font-size: 18px;
  }
  .mobile-text-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
  }
  .mobile-text-status {
    padding: 12px 18px 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
  }
  .mobile-text-status.is-error {
    color: #ffcabd;
  }
  .mobile-text-body {
    display: grid;
    gap: 10px;
    padding: 14px;
  }
  .mobile-text-section {
    padding: 14px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.045);
    border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .mobile-text-section h3 {
    margin: 0 0 10px;
    font-size: 16px;
    line-height: 1.4;
    color: #ffd27a;
  }
  .mobile-text-paragraph {
    margin: 0;
    color: #f4f7fb;
    font-size: 15px;
    line-height: 1.8;
    word-break: keep-all;
    white-space: pre-wrap;
  }
  .mobile-text-paragraph + .mobile-text-paragraph {
    margin-top: 10px;
  }
  .mobile-text-empty {
    padding: 18px;
    color: var(--muted);
    text-align: center;
    line-height: 1.7;
  }
  .viewer-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
  }
  .viewer-toolbar-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
  .viewer-toolbar select,
  .viewer-toolbar button {
    font: inherit;
  }
  .viewer-page-select {
    min-width: 116px;
    min-height: 42px;
    padding: 0 12px;
    border-radius: 12px;
    border: 1px solid var(--line);
    background: rgba(255, 255, 255, 0.05);
    color: var(--text);
  }
  .viewer-page-indicator {
    color: var(--muted);
    font-size: 13px;
    font-weight: 700;
  }
  .viewer-canvas-wrap {
    position: relative;
    overflow: auto;
    border-radius: 22px;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.02)),
      #09101a;
    border: 1px solid rgba(255, 255, 255, 0.08);
    min-height: 58vh;
    padding: 18px;
    touch-action: pan-x pan-y pinch-zoom;
  }
  .viewer-canvas-wrap.is-loading::after {
    content: "페이지를 읽기 좋은 크기로 준비하고 있습니다.";
    position: absolute;
    inset: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    border-radius: 18px;
    background: rgba(7, 15, 27, 0.84);
    color: var(--muted);
    text-align: center;
    line-height: 1.6;
  }
  #pdf-canvas {
    display: block;
    margin: 0 auto;
    border-radius: 18px;
    background: #ffffff;
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
  }
  .viewer-help {
    margin-top: 14px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    text-align: center;
  }
  .mobile-bottom-nav {
    display: none;
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: max(10px, env(safe-area-inset-bottom));
    z-index: 60;
    padding: 8px;
    border: 1px solid rgba(24, 59, 86, 0.10);
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 40px rgba(17, 52, 77, 0.18);
  }
  .mobile-bottom-nav-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
  }
  .mobile-nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    border-radius: 14px;
    color: #45627b;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
  }
  .mobile-nav-link.is-active {
    background: linear-gradient(180deg, rgba(35, 104, 162, 0.14), rgba(35, 104, 162, 0.08));
    color: #17486f;
  }
  .mobile-nav-icon {
    font-size: 18px;
    line-height: 1;
  }
  @media (max-width: 960px) {
    .info-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 640px) {
    body {
      padding-bottom: 112px;
    }
    .reader-topbar,
    .info-card {
      display: none;
    }
    .reader-shell {
      padding: 12px 12px 28px;
    }
    .mobile-text-reader {
      display: block;
    }
    .reader-topbar-inner,
    .info-card,
    .viewer-card,
    .status-card {
      border-radius: 22px;
    }
    .reader-topbar-inner {
      padding: 10px 12px;
      border-radius: 18px;
    }
    .reader-topbar {
      margin-bottom: 10px;
    }
    .reader-title .eyebrow {
      margin-bottom: 2px;
      font-size: 10px;
      letter-spacing: 0.12em;
    }
    .reader-title h1 {
      font-size: 16px;
      line-height: 1.25;
    }
    .reader-actions,
    .reader-actions .btn {
      width: 100%;
    }
    .reader-actions .btn {
      min-height: 38px;
      font-size: 13px;
    }
    .info-card {
      padding: 14px;
    }
    .info-grid {
      grid-template-columns: 1fr;
    }
    .info-item {
      padding: 12px;
    }
    .viewer-card {
      padding: 10px;
    }
    .mobile-text-reader {
      border-radius: 20px;
      margin-bottom: 10px;
    }
    .mobile-text-head {
      padding: 14px 14px 12px;
    }
    .mobile-text-head h2 {
      font-size: 17px;
    }
    .mobile-text-head p,
    .mobile-text-status {
      font-size: 12px;
    }
    .mobile-text-status {
      padding: 10px 14px 0;
    }
    .mobile-text-body {
      padding: 12px;
      gap: 8px;
    }
    .mobile-text-section {
      padding: 12px;
      border-radius: 16px;
    }
    .mobile-text-section h3 {
      font-size: 15px;
    }
    .mobile-text-paragraph {
      font-size: 14px;
      line-height: 1.75;
    }
    .viewer-toolbar {
      margin-bottom: 10px;
      align-items: stretch;
    }
    .viewer-toolbar-group {
      width: 100%;
      justify-content: space-between;
    }
    .viewer-toolbar-group .btn,
    .viewer-toolbar-group .viewer-page-select {
      flex: 1 1 calc(50% - 4px);
      min-width: 0;
    }
    .viewer-page-indicator {
      width: 100%;
      text-align: center;
    }
    .viewer-canvas-wrap {
      min-height: 52vh;
      padding: 10px;
    }
    .viewer-help {
      font-size: 12px;
    }
    .mobile-bottom-nav {
      display: block;
    }
  }
</style>
</head>
<body>
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
        <p>해당 MSDS PDF가 없거나 열람할 수 없는 상태입니다. 목록으로 돌아가 다시 선택해주세요.</p>
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
            <dd><?= h(($revisionCount !== '' ? $revisionCount . '회' : '-') . ' / ' . ($note !== '' ? $note : '-')) ?></dd>
          </div>
        </dl>
      </section>

      <section class="mobile-text-reader" id="mobile-text-reader">
        <div class="mobile-text-head">
          <h2>모바일 읽기</h2>
          <p>정리된 본문이 있으면 우선 표시하고, 없으면 PDF에서 텍스트를 자동 추출해 읽기 좋게 정렬합니다.</p>
        </div>
        <div class="mobile-text-status" id="mobile-text-status">모바일 본문을 준비하고 있습니다.</div>
        <div class="mobile-text-body" id="mobile-text-body">
          <div class="mobile-text-empty">본문을 불러오는 중입니다.</div>
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
          <div class="viewer-page-indicator" id="page-indicator">불러오는 중</div>
        </div>

        <div class="viewer-canvas-wrap is-loading" id="pdf-stage">
          <canvas id="pdf-canvas"></canvas>
        </div>
        <div class="viewer-help">
          모바일에서는 한 페이지씩 크게 보여주도록 구성했습니다. 확대 버튼으로 글자를 더 키워 볼 수 있고, 원본이 필요하면 상단의 원본보기 버튼을 사용할 수 있습니다.
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
        <span class="mobile-nav-icon">◫</span>
        <span>달력</span>
      </a>
      <a class="mobile-nav-link" href="work_list.php">
        <span class="mobile-nav-icon">▤</span>
        <span>작업목록</span>
      </a>
      <a class="mobile-nav-link is-active" href="msds_list.php">
        <span class="mobile-nav-icon">⌘</span>
        <span>MSDS</span>
      </a>
      <a class="mobile-nav-link" href="more.php">
        <span class="mobile-nav-icon">⋯</span>
        <span>더보기</span>
      </a>
    </div>
  </nav>

<?php if ($record !== null && $isPdf): ?>
<script type="module">
  import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.4.168/pdf.min.mjs';

  pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.4.168/pdf.worker.min.mjs';

  const pdfUrl = <?= json_encode($pdfUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const manualMobileContent = <?= json_encode($mobileContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const stage = document.getElementById('pdf-stage');
  const canvas = document.getElementById('pdf-canvas');
  const context = canvas.getContext('2d');
  const prevButton = document.getElementById('prev-page');
  const nextButton = document.getElementById('next-page');
  const zoomOutButton = document.getElementById('zoom-out');
  const zoomInButton = document.getElementById('zoom-in');
  const pageSelect = document.getElementById('page-select');
  const pageIndicator = document.getElementById('page-indicator');
  const mobileTextReader = document.getElementById('mobile-text-reader');
  const mobileTextStatus = document.getElementById('mobile-text-status');
  const mobileTextBody = document.getElementById('mobile-text-body');
  const isMobileViewport = () => window.matchMedia('(max-width: 640px)').matches;

  let pdfDoc = null;
  let pageNum = 1;
  let zoomFactor = 1;
  let rendering = false;
  let pendingPage = null;

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function normalizeLineText(value) {
    return String(value || '')
      .replace(/\s+/g, ' ')
      .replace(/\u0000/g, '')
      .trim();
  }

  function isSectionHeading(line) {
    return /^(?:\d{1,2}[.)]?\s*|[①-⑳]\s*)/.test(line)
      || /(응급조치|유해성|위험성|취급|저장|노출방지|보호구|물리화학적|안정성|독성|환경|폐기|운송|법적|기타 참고|성분|구성)/.test(line);
  }

  function splitManualSections(rawText) {
    const normalized = String(rawText || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!normalized) {
      return [];
    }

    const blocks = normalized.split(/\n{2,}/).map((block) => block.trim()).filter(Boolean);
    return blocks.map((block, index) => {
      const lines = block.split('\n').map((line) => line.trim()).filter(Boolean);
      if (!lines.length) {
        return null;
      }

      let title = `정리 내용 ${index + 1}`;
      let paragraphs = lines;
      if (lines.length >= 2 && lines[0].length <= 40) {
        title = lines[0];
        paragraphs = lines.slice(1);
      }

      if (!paragraphs.length) {
        paragraphs = [''];
      }

      return { title, paragraphs };
    }).filter(Boolean);
  }

  function buildSectionsFromLines(lines) {
    const sections = [];
    let current = null;

    lines.forEach((line) => {
      const cleanLine = normalizeLineText(line);
      if (!cleanLine) {
        return;
      }

      if (!current) {
        current = { title: '추출 본문', paragraphs: [] };
      }

      if (isSectionHeading(cleanLine) && current.paragraphs.length) {
        sections.push(current);
        current = { title: cleanLine, paragraphs: [] };
        return;
      }

      if (isSectionHeading(cleanLine) && current.title === '추출 본문' && !current.paragraphs.length) {
        current.title = cleanLine;
        return;
      }

      current.paragraphs.push(cleanLine);
    });

    if (current && (current.paragraphs.length || current.title !== '추출 본문')) {
      sections.push(current);
    }

    return sections.slice(0, 24);
  }

  function renderTextSections(sections, statusText, isError) {
    if (!mobileTextReader || !mobileTextBody || !mobileTextStatus) {
      return;
    }

    mobileTextStatus.textContent = statusText;
    mobileTextStatus.classList.toggle('is-error', !!isError);

    if (!sections.length) {
      mobileTextBody.innerHTML = '<div class="mobile-text-empty">표시할 텍스트를 찾지 못했습니다. 원본보기로 PDF를 확인해주세요.</div>';
      return;
    }

    mobileTextBody.innerHTML = sections.map((section) => {
      const paragraphs = (section.paragraphs || []).map((paragraph) => `<p class="mobile-text-paragraph">${escapeHtml(paragraph)}</p>`).join('');
      return `<article class="mobile-text-section"><h3>${escapeHtml(section.title || '본문')}</h3>${paragraphs}</article>`;
    }).join('');
  }

  async function extractTextSections(documentRef) {
    const allLines = [];

    for (let pageIndex = 1; pageIndex <= documentRef.numPages; pageIndex += 1) {
      const page = await documentRef.getPage(pageIndex);
      const textContent = await page.getTextContent();
      const rows = new Map();

      textContent.items.forEach((item) => {
        const text = normalizeLineText(item.str || '');
        if (!text) {
          return;
        }

        const transform = Array.isArray(item.transform) ? item.transform : [0, 0, 0, 0, 0, 0];
        const x = Number(transform[4] || 0);
        const y = Math.round(Number(transform[5] || 0));
        const key = String(y);

        if (!rows.has(key)) {
          rows.set(key, []);
        }

        rows.get(key).push({ x, text });
      });

      const pageLines = Array.from(rows.entries())
        .sort((a, b) => Number(b[0]) - Number(a[0]))
        .map((entry) => entry[1]
          .sort((a, b) => a.x - b.x)
          .map((part) => part.text)
          .join(' ')
        )
        .map((line) => normalizeLineText(line))
        .filter(Boolean);

      allLines.push(...pageLines);
    }

    return buildSectionsFromLines(allLines);
  }

  function setLoadingState(isLoading) {
    stage.classList.toggle('is-loading', isLoading);
  }

  function updateControls() {
    if (!pdfDoc) {
      pageIndicator.textContent = '불러오는 중';
      return;
    }

    prevButton.disabled = pageNum <= 1 || rendering;
    nextButton.disabled = pageNum >= pdfDoc.numPages || rendering;
    zoomOutButton.disabled = zoomFactor <= 0.8 || rendering;
    zoomInButton.disabled = zoomFactor >= 2.2 || rendering;
    pageSelect.disabled = rendering;
    pageSelect.value = String(pageNum);
    pageIndicator.textContent = `${pageNum} / ${pdfDoc.numPages} 페이지 · ${Math.round(zoomFactor * 100)}%`;
  }

  async function renderPage(targetPage) {
    if (!pdfDoc) {
      return;
    }

    rendering = true;
    setLoadingState(true);
    updateControls();

    try {
      const page = await pdfDoc.getPage(targetPage);
      const baseViewport = page.getViewport({ scale: 1 });
      const availableWidth = Math.max(280, stage.clientWidth - 36);
      const fitScale = availableWidth / baseViewport.width;
      const renderScale = fitScale * zoomFactor;
      const viewport = page.getViewport({ scale: renderScale });
      const ratio = window.devicePixelRatio || 1;

      canvas.width = Math.floor(viewport.width * ratio);
      canvas.height = Math.floor(viewport.height * ratio);
      canvas.style.width = `${Math.round(viewport.width)}px`;
      canvas.style.height = `${Math.round(viewport.height)}px`;

      const renderContext = {
        canvasContext: context,
        viewport,
        transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null,
      };

      await page.render(renderContext).promise;
      pageNum = targetPage;
      stage.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    } catch (error) {
      pageIndicator.textContent = '문서를 표시하지 못했습니다';
      console.error(error);
    } finally {
      rendering = false;
      setLoadingState(false);
      updateControls();
      if (pendingPage !== null && pendingPage !== pageNum) {
        const queuedPage = pendingPage;
        pendingPage = null;
        renderPage(queuedPage);
      } else {
        pendingPage = null;
      }
    }
  }

  function queueRender(targetPage) {
    if (!pdfDoc) {
      return;
    }

    const safePage = Math.min(Math.max(targetPage, 1), pdfDoc.numPages);
    if (rendering) {
      pendingPage = safePage;
      return;
    }

    renderPage(safePage);
  }

  function rebuildPageOptions(totalPages) {
    const options = [];
    for (let index = 1; index <= totalPages; index += 1) {
      options.push(`<option value="${index}">${index} 페이지</option>`);
    }
    pageSelect.innerHTML = options.join('');
  }

  prevButton.addEventListener('click', () => queueRender(pageNum - 1));
  nextButton.addEventListener('click', () => queueRender(pageNum + 1));
  zoomOutButton.addEventListener('click', () => {
    zoomFactor = Math.max(0.8, +(zoomFactor - 0.15).toFixed(2));
    queueRender(pageNum);
  });
  zoomInButton.addEventListener('click', () => {
    zoomFactor = Math.min(2.2, +(zoomFactor + 0.15).toFixed(2));
    queueRender(pageNum);
  });
  pageSelect.addEventListener('change', () => queueRender(Number(pageSelect.value)));

  let resizeTimer = null;
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => queueRender(pageNum), 180);
  });

  updateControls();

  try {
    const loadingTask = pdfjsLib.getDocument({
      url: pdfUrl,
      cMapPacked: true,
    });
    pdfDoc = await loadingTask.promise;
    if (mobileTextReader && isMobileViewport()) {
      const manualSections = splitManualSections(manualMobileContent);
      if (manualSections.length) {
        renderTextSections(manualSections, '관리자가 정리한 모바일 전용 본문입니다.', false);
      } else {
        try {
          const extractedSections = await extractTextSections(pdfDoc);
          if (extractedSections.length) {
            renderTextSections(extractedSections, 'PDF 텍스트를 자동 추출해 모바일용으로 정리했습니다.', false);
          } else {
            renderTextSections([], '자동 추출된 텍스트가 없어 원본 PDF 보기가 필요합니다.', true);
            stage.style.display = 'block';
          }
        } catch (textError) {
          renderTextSections([], '자동 텍스트 추출에 실패했습니다. 원본 PDF 보기로 확인해주세요.', true);
          stage.style.display = 'block';
          console.error(textError);
        }
      }
    }
    rebuildPageOptions(pdfDoc.numPages);
    updateControls();
    renderPage(1);
  } catch (error) {
    setLoadingState(false);
    pageIndicator.textContent = '문서를 불러오지 못했습니다';
    stage.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;min-height:52vh;color:#9fb4c8;text-align:center;line-height:1.7;padding:18px;">모바일 읽기 화면을 준비하지 못했습니다.<br>상단의 원본보기 버튼으로 PDF를 바로 열 수 있습니다.</div>';
    console.error(error);
  }
</script>
<?php endif; ?>
</body>
</html>
