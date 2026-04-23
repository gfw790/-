<?php
require_once __DIR__ . '/csi_search.php';

$q       = trim($_GET['q'] ?? '');
$data    = $q !== '' ? csi_do_search($q) : null;
$items   = $data['items'] ?? [];
$searchError = $data['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>CSI 사고사례 검색</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Malgun Gothic', sans-serif; margin: 0; background: #f4f6fb; color: #1a2235; }
  .wrap { max-width: 820px; margin: 0 auto; padding: 36px 20px 60px; }
  h1 { font-size: 22px; font-weight: 800; margin: 0 0 6px; }
  .subtitle { font-size: 13px; color: #6b7280; margin: 0 0 24px; }

  /* 검색 폼 */
  .search-form { display: flex; gap: 8px; margin-bottom: 28px; }
  .search-form input[type=text] {
    flex: 1; padding: 11px 14px; border: 1.5px solid #c8d0e0;
    border-radius: 8px; font-size: 14px; outline: none;
  }
  .search-form input[type=text]:focus { border-color: #3b82f6; }
  .search-form button {
    padding: 11px 20px; background: #2563eb; color: #fff;
    border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;
  }
  .search-form button:hover { background: #1d4ed8; }

  /* AI 질문 패널 */
  .ask-panel {
    background: #eff6ff; border: 1.5px solid #bfdbfe;
    border-radius: 10px; padding: 16px 18px; margin-bottom: 24px;
  }
  .ask-panel-title { font-size: 13px; font-weight: 700; color: #1d4ed8; margin: 0 0 10px; }
  .ask-row { display: flex; gap: 8px; }
  .ask-row input[type=text] {
    flex: 1; padding: 9px 12px; border: 1.5px solid #93c5fd;
    border-radius: 7px; font-size: 13px; outline: none; background: #fff;
  }
  .ask-row input[type=text]:focus { border-color: #2563eb; }
  .ask-row button {
    padding: 9px 16px; background: #2563eb; color: #fff;
    border: none; border-radius: 7px; font-size: 13px; font-weight: 700; cursor: pointer;
    white-space: nowrap;
  }
  .ask-row button:hover { background: #1d4ed8; }
  .ask-row button:disabled { background: #93c5fd; cursor: default; }
  .ask-hint { font-size: 11px; color: #6b7280; margin: 6px 0 0; }
  #ask-answer {
    margin-top: 12px; font-size: 13px; line-height: 1.7;
    background: #fff; border-radius: 7px; padding: 12px 14px;
    border: 1px solid #bfdbfe; display: none; white-space: pre-wrap;
  }

  /* 결과 */
  .result-meta { font-size: 12px; color: #6b7280; margin-bottom: 12px; }
  .result-card {
    background: #fff; border: 1px solid #e2e7f0; border-radius: 10px;
    padding: 14px 16px; margin-bottom: 10px;
  }
  .result-no { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
  .result-title { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
  .result-meta-row { display: flex; gap: 16px; font-size: 12px; color: #6b7280; margin-bottom: 8px; flex-wrap: wrap; }
  .result-situation { font-size: 12px; color: #374151; line-height: 1.6; }
  .result-link { display: inline-block; margin-top: 8px; font-size: 12px; color: #2563eb; text-decoration: none; }
  .result-link:hover { text-decoration: underline; }

  .msg { font-size: 14px; color: #6b7280; padding: 24px 0; }
  .msg.error { color: #dc2626; }
</style>
</head>
<body>
<div class="wrap">
  <h1>CSI 사고사례 검색</h1>
  <p class="subtitle">건설공사 안전관리 종합정보망 사고사례를 검색하고 AI에게 질문하세요.</p>

  <form class="search-form" method="get">
    <input type="text" name="q" placeholder="사고 키워드 입력 (예: 감전, 추락, 끼임)" value="<?= htmlspecialchars($q) ?>">
    <button type="submit">검색</button>
  </form>

  <?php
  // AI 질문 패널에 넘길 컨텍스트 구성
  $caseContext = '';
  if (!empty($items)) {
      foreach ($items as $item) {
          $caseContext .= "【사고번호 {$item['accident_no']}】 {$item['title']}\n";
          $caseContext .= "일시: {$item['accident_date']} / 장소: {$item['accident_place']}\n";
          if (!empty($item['circumstances'])) $caseContext .= "사고경위: {$item['circumstances']}\n";
          if (!empty($item['cause']))         $caseContext .= "원인: {$item['cause']}\n";
          if (!empty($item['prevention']))    $caseContext .= "재발방지: {$item['prevention']}\n";
          $caseContext .= "\n";
      }
  }
  $hasContext = $caseContext !== '';
  ?>

  <div class="ask-panel">
    <div class="ask-panel-title">🤖 AI 자연어 질문</div>
    <div class="ask-row">
      <input type="text" id="ask-q" placeholder="<?= $hasContext ? '검색된 사고사례에 대해 질문하세요' : '먼저 키워드로 검색 후 질문하세요' ?>" <?= $hasContext ? '' : 'disabled' ?>>
      <button type="button" id="ask-btn" <?= $hasContext ? '' : 'disabled' ?>>질문하기</button>
    </div>
    <?php if ($hasContext): ?>
      <div class="ask-hint"><?= count($items) ?>건의 사고사례 (상세내용 최대 15건 포함)를 바탕으로 답변합니다.</div>
    <?php else: ?>
      <div class="ask-hint">위 검색창에서 키워드를 검색하면 AI 질문이 활성화됩니다.</div>
    <?php endif; ?>
    <div id="ask-answer"></div>
  </div>

  <?php if ($q !== ''): ?>
    <?php if ($searchError): ?>
      <p class="msg error">오류: <?= htmlspecialchars($searchError) ?></p>
    <?php elseif (empty($items)): ?>
      <p class="msg">"<?= htmlspecialchars($q) ?>" 검색 결과가 없습니다.</p>
    <?php else: ?>
      <div class="result-meta"><?= count($items) ?>건의 사고사례를 찾았습니다.</div>
      <?php foreach ($items as $item): ?>
        <div class="result-card">
          <div class="result-no">사고번호: <?= htmlspecialchars($item['accident_no']) ?></div>
          <div class="result-title"><?= htmlspecialchars($item['title']) ?></div>
          <div class="result-meta-row">
            <span>📅 <?= htmlspecialchars($item['accident_date']) ?></span>
            <span>📍 <?= htmlspecialchars($item['accident_place']) ?></span>
          </div>
          <?php if (!empty($item['circumstances'])): ?>
            <div class="result-situation"><?= htmlspecialchars(mb_strimwidth($item['circumstances'], 0, 160, '…')) ?></div>
          <?php endif; ?>
          <a class="result-link" href="<?= htmlspecialchars($item['url']) ?>" target="_blank">상세보기 →</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
const caseContext = <?= json_encode($caseContext, JSON_UNESCAPED_UNICODE) ?>;

document.getElementById('ask-btn')?.addEventListener('click', async () => {
  const q = document.getElementById('ask-q').value.trim();
  if (!q) return;
  const btn = document.getElementById('ask-btn');
  const box = document.getElementById('ask-answer');
  btn.disabled = true;
  btn.textContent = '생성 중…';
  box.style.display = 'block';
  box.textContent = '';

  try {
    const res = await fetch('ask.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'q=' + encodeURIComponent(q) + '&cases=' + encodeURIComponent(caseContext),
    });
    const data = await res.json();
    box.textContent = data.answer || data.error || '답변을 가져오지 못했습니다.';
  } catch {
    box.textContent = '오류가 발생했습니다.';
  } finally {
    btn.disabled = false;
    btn.textContent = '질문하기';
  }
});

document.getElementById('ask-q')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('ask-btn').click();
});
</script>
</body>
</html>
