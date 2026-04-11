<?php
require_once __DIR__ . '/../risk_assessment/db_config.php';

$unitRaId = filter_input(INPUT_GET, 'unit_ra_id', FILTER_VALIDATE_INT);
if (!$unitRaId) { http_response_code(400); exit('잘못된 요청입니다.'); }

try {
    $pdo = getDB();
    $stmtH = $pdo->prepare("SELECT * FROM unit_ra_header WHERE unit_ra_id = :id");
    $stmtH->execute([':id' => $unitRaId]);
    $header = $stmtH->fetch();
    if (!$header) { http_response_code(404); exit('평가서를 찾을 수 없습니다.'); }

    $stmtI = $pdo->prepare("SELECT * FROM unit_ra_item WHERE unit_ra_id = :id AND use_yn = 'Y' ORDER BY sort_no ASC");
    $stmtI->execute([':id' => $unitRaId]);
    $items = $stmtI->fetchAll();
} catch (\PDOException $e) {
    http_response_code(500); exit('DB 오류: ' . $e->getMessage());
}

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function riskColor($score) {
    if ($score === null || $score === '') return ['#e2e8f0','#64748b'];
    $s = (int)$score;
    if ($s >= 12) return ['#fee2e2','#9b1c1c'];
    if ($s >= 6)  return ['#fef3c7','#b45309'];
    if ($s >= 1)  return ['#dcfce7','#166534'];
    return ['#f1f5f9','#64748b'];
}

function riskLabel($score) {
    if ($score === null || $score === '') return '-';
    $s = (int)$score;
    if ($s >= 12) return '높음';
    if ($s >= 6)  return '중간';
    if ($s >= 1)  return '낮음';
    return '-';
}

$typeMap = [
    'target'     => '작업유형',
    'major_work' => '중대위험작업',
    'tool'       => '공구/장비',
    'env'        => '작업환경',
];
$unitTypeLabel = $typeMap[$header['unit_type']] ?? $header['unit_type'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($header['unit_title']) ?> — 위험성평가</title>
<style>
  :root {
    --blue-dark: #1a3a5c;
    --blue-mid:  #2E75B6;
    --blue-light:#e8f2fb;
    --border:    #e2e8f0;
    --gray-bg:   #f1f5f9;
    --text:      #1e293b;
    --text-sub:  #64748b;
    --radius:    14px;
    --shadow:    0 2px 12px rgba(0,0,0,0.08);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
  body {
    font-family: "맑은 고딕", "Apple SD Gothic Neo", sans-serif;
    background: #f0f4f8;
    color: var(--text);
    font-size: 1rem;
  }

  
  /* 헤더 */
  .header {
    background: var(--blue-dark);
    padding: 14px 16px;
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  }
  .btn-back {
    color: white;
    text-decoration: none;
    font-size: 1.571rem;
    line-height: 1;
    padding: 2px 4px;
  }
  .header-text h1 {
    color: white;
    font-size: 1.071rem;
    font-weight: 700;
    line-height: 1.3;
  }
  .header-text p { color: rgba(255,255,255,0.6); font-size: 0.786rem; margin-top: 1px; }

  /* 기본정보 카드 */
  .info-card {
    background: white;
    margin: 12px 12px 0;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    border: 1px solid var(--border);
  }
  .info-card-header {
    background: var(--blue-dark);
    padding: 10px 14px;
    font-size: 0.857rem;
    font-weight: 700;
    color: white;
    letter-spacing: 0.3px;
  }
  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .info-item {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
  }
  .info-item:nth-child(2n) { border-right: none; }
  .info-item.full {
    grid-column: span 2;
    border-right: none;
  }
  .info-item:last-child, .info-item:nth-last-child(2):not(.full) { border-bottom: none; }
  .info-label {
    font-size: 0.714rem;
    color: var(--text-sub);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
  }
  .info-value {
    font-size: 0.929rem;
    font-weight: 600;
    color: var(--text);
    line-height: 1.4;
  }
  .type-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 99px;
    font-size: 0.857rem;
    font-weight: 700;
    background: var(--blue-light);
    color: var(--blue-dark);
  }

  /* 섹션 타이틀 */
  .section-title {
    padding: 16px 12px 8px;
    font-size: 0.929rem;
    font-weight: 700;
    color: var(--text-sub);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .section-title .count {
    background: var(--blue-light);
    color: var(--blue-dark);
    font-size: 0.786rem;
    padding: 2px 8px;
    border-radius: 99px;
  }

  /* 항목 카드 */
  .item-card {
    background: white;
    margin: 0 12px 10px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    border: 1px solid var(--border);
  }
  .item-header {
    padding: 12px 14px;
    background: var(--gray-bg);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .item-no {
    width: 26px; height: 26px;
    background: var(--blue-dark);
    color: white;
    border-radius: 50%;
    font-size: 0.857rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .item-task {
    font-size: 0.929rem;
    font-weight: 700;
    color: var(--text);
    flex: 1;
  }
  .item-code {
    font-size: 0.786rem;
    color: var(--text-sub);
    font-family: monospace;
  }

  .item-body { padding: 12px 14px; }
  .item-row {
    margin-bottom: 10px;
  }
  .item-row:last-child { margin-bottom: 0; }
  .item-row-label {
    font-size: 0.714rem;
    font-weight: 700;
    color: var(--text-sub);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 4px;
  }
  .item-row-value {
    font-size: 0.929rem;
    color: var(--text);
    line-height: 1.6;
  }

  /* 위험도 섹션 */
  .risk-section {
    margin: 10px 14px;
    padding: 12px;
    background: var(--gray-bg);
    border-radius: 10px;
  }
  .risk-section-title {
    font-size: 0.786rem;
    font-weight: 700;
    color: var(--text-sub);
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  .risk-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
  }
  .risk-row:last-child { margin-bottom: 0; }
  .risk-stage {
    font-size: 0.786rem;
    color: var(--text-sub);
    width: 60px;
    flex-shrink: 0;
  }
  .risk-scores {
    display: flex;
    gap: 6px;
    flex: 1;
    align-items: center;
  }
  .score-box {
    background: white;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 4px 8px;
    text-align: center;
    min-width: 36px;
  }
  .score-label { font-size: 0.643rem; color: var(--text-sub); }
  .score-value { font-size: 1.0rem; font-weight: 700; color: var(--text); }
  .risk-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.857rem;
    font-weight: 700;
    text-align: center;
    min-width: 50px;
  }
  .arrow-icon { color: var(--text-sub); font-size: 1.0rem; }

  /* 개선일자 */
  .improve-bar {
    margin: 0 14px 12px;
    padding: 10px 12px;
    background: #eff6ff;
    border-radius: 8px;
    border-left: 3px solid var(--blue-mid);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.857rem;
    color: var(--blue-dark);
  }
  .improve-bar b { font-weight: 700; }

  .bottom-pad { height: 32px; }
</style>
</head>
<body>

<div class="header">
  <a class="btn-back" href="view_list.html">‹</a>
  <div class="header-text" style="flex:1;min-width:0">
    <h1><?= h($header['unit_title']) ?></h1>
    <p><?= h($unitTypeLabel) ?> · <?= h($header['process_name'] ?? '') ?></p>
  </div>

</div>

<!-- 기본정보 -->
<div class="info-card">
  <div class="info-card-header">▶ 평가서 기본정보</div>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">평가서 코드</div>
      <div class="info-value"><?= h($header['unit_code'] ?? '-') ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">평가유형</div>
      <div class="info-value"><span class="type-badge"><?= h($unitTypeLabel) ?></span></div>
    </div>
    <div class="info-item">
      <div class="info-label">공정명</div>
      <div class="info-value"><?= h($header['process_name'] ?? '-') ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">항목 수</div>
      <div class="info-value"><?= count($items) ?>건</div>
    </div>
    <div class="info-item">
      <div class="info-label">작성자</div>
      <div class="info-value"><?= h($header['created_by'] ?? '-') ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">평가자</div>
      <div class="info-value"><?= h($header['evaluator_name'] ?? '-') ?></div>
    </div>
    <?php if (!empty($header['remark'])): ?>
    <div class="info-item full">
      <div class="info-label">비고</div>
      <div class="info-value"><?= h($header['remark']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- 항목 목록 -->
<div class="section-title">
  위험성평가 항목
  <span class="count"><?= count($items) ?>건</span>
</div>

<?php foreach ($items as $idx => $item):
  [$rb_bg, $rb_fg] = riskColor($item['risk_score_before']);
  [$rc_bg, $rc_fg] = riskColor($item['risk_score_current']);
  [$ra_bg, $ra_fg] = riskColor($item['risk_score_after']);
?>
<div class="item-card">
  <div class="item-header">
    <div class="item-no"><?= $idx + 1 ?></div>
    <div style="flex:1">
      <div class="item-task"><?= h($item['task_name']) ?></div>
      <?php if ($item['task_code']): ?>
      <div class="item-code"><?= h($item['task_code']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="item-body">
    <div class="item-row">
      <div class="item-row-label">유해·위험요인</div>
      <div class="item-row-value"><?= h($item['hazard_name']) ?></div>
    </div>

    <?php if ($item['accident_type'] || $item['injury_result']): ?>
    <div class="item-row">
      <div class="item-row-label">재해유형 / 결과</div>
      <div class="item-row-value">
        <?= h($item['accident_type'] ?? '') ?>
        <?php if ($item['accident_type'] && $item['injury_result']): ?> / <?php endif; ?>
        <?= h($item['injury_result'] ?? '') ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($item['cause_text']): ?>
    <div class="item-row">
      <div class="item-row-label">원인 / 위험상황</div>
      <div class="item-row-value"><?= h($item['cause_text']) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($item['current_control_text']): ?>
    <div class="item-row">
      <div class="item-row-label">현재 안전보건조치</div>
      <div class="item-row-value"><?= h($item['current_control_text']) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($item['additional_control_text']): ?>
    <div class="item-row">
      <div class="item-row-label">추가 개선대책</div>
      <div class="item-row-value"><?= h($item['additional_control_text']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- 위험도 -->
  <div class="risk-section">
    <div class="risk-section-title">위험도 평가</div>

    <div class="risk-row">
      <div class="risk-stage">개선 전</div>
      <div class="risk-scores">
        <div class="score-box">
          <div class="score-label">가능성</div>
          <div class="score-value"><?= $item['likelihood_before'] ?? '-' ?></div>
        </div>
        <span style="color:#cbd5e1">×</span>
        <div class="score-box">
          <div class="score-label">중대성</div>
          <div class="score-value"><?= $item['severity_before'] ?? '-' ?></div>
        </div>
        <span style="color:#cbd5e1">=</span>
        <div class="risk-badge" style="background:<?= $rb_bg ?>;color:<?= $rb_fg ?>">
          <?= $item['risk_score_before'] ?? '-' ?>
        </div>
        <span style="font-size:11px;color:<?= $rb_fg ?>;font-weight:700"><?= riskLabel($item['risk_score_before']) ?></span>
      </div>
    </div>

    <div class="risk-row">
      <div class="risk-stage">조치 후</div>
      <div class="risk-scores">
        <div class="score-box">
          <div class="score-label">가능성</div>
          <div class="score-value"><?= $item['likelihood_current'] ?? '-' ?></div>
        </div>
        <span style="color:#cbd5e1">×</span>
        <div class="score-box">
          <div class="score-label">중대성</div>
          <div class="score-value"><?= $item['severity_current'] ?? '-' ?></div>
        </div>
        <span style="color:#cbd5e1">=</span>
        <div class="risk-badge" style="background:<?= $rc_bg ?>;color:<?= $rc_fg ?>">
          <?= $item['risk_score_current'] ?? '-' ?>
        </div>
        <span style="font-size:11px;color:<?= $rc_fg ?>;font-weight:700"><?= riskLabel($item['risk_score_current']) ?></span>
      </div>
    </div>

    <div class="risk-row">
      <div class="risk-stage">개선 후</div>
      <div class="risk-scores">
        <div class="score-box">
          <div class="score-label">가능성</div>
          <div class="score-value"><?= $item['likelihood_after'] ?? '-' ?></div>
        </div>
        <span style="color:#cbd5e1">×</span>
        <div class="score-box">
          <div class="score-label">중대성</div>
          <div class="score-value"><?= $item['severity_after'] ?? '-' ?></div>
        </div>
        <span style="color:#cbd5e1">=</span>
        <div class="risk-badge" style="background:<?= $ra_bg ?>;color:<?= $ra_fg ?>">
          <?= $item['risk_score_after'] ?? '-' ?>
        </div>
        <span style="font-size:11px;color:<?= $ra_fg ?>;font-weight:700"><?= riskLabel($item['risk_score_after']) ?></span>
      </div>
    </div>
  </div>

  <?php if (!empty($item['improvement_due_date']) && $item['improvement_due_date'] !== '0000-00-00'): ?>
  <div class="improve-bar">
    📅 개선일자: <b><?= h($item['improvement_due_date']) ?></b>
  </div>
  <?php endif; ?>

</div>
<?php endforeach; ?>

<div class="bottom-pad"></div>
<script>
function setFont(scale) {
  document.documentElement.style.setProperty('--font-scale', scale);
  localStorage.setItem('fontScale', scale);
  document.querySelectorAll('.font-btn').forEach(b => b.classList.remove('active'));
  if (scale <= 0.85) document.getElementById('btn-sm').classList.add('active');
  else if (scale >= 1.2) document.getElementById('btn-lg').classList.add('active');
  else document.getElementById('btn-md').classList.add('active');
}
// 스크롤 내리면 헤더 숨김
let lastY = 0;
window.addEventListener('scroll', () => {
  const y = window.scrollY;
  const header = document.querySelector('.header');
  if (y > lastY && y > 60) header.classList.add('hidden');
  else header.classList.remove('hidden');
  lastY = y;
}, { passive: true });

const savedScale = localStorage.getItem('fontScale');
if (savedScale) setFont(parseFloat(savedScale));
</script>
</body>
</html>
