<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/accident_investigation_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$db = ai_db();
$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) {
    $year = $nowYear;
}
$mode = (string)($_GET['mode'] ?? 'na_cert');
if (!in_array($mode, ['na_cert', 'manual', 'register'], true)) {
    $mode = 'na_cert';
}

$isLandscape = ($mode === 'register');
$yearStatus = ai_get_year_status($db, $year);
$manual = ai_get_manual($db);
$incidents = ai_list_incidents($db, $year);
$yearIncidentsCount = count(array_filter($incidents, static fn($r) => (int)($r['year'] ?? 0) === $year));
if ($mode === 'na_cert' && ($yearIncidentsCount > 0 || ($yearStatus['status'] ?? 'na') !== 'na')) {
    http_response_code(409);
    exit($year . '년에 산업재해 기록이 존재하거나 재해발생 상태이므로 무재해(해당없음) 확인서를 출력할 수 없습니다.');
}

$logoSrc = is_file(__DIR__ . '/assets/policy-logo.png')
    ? 'assets/policy-logo.png'
    : (is_file(dirname(__DIR__) . '/safety_manual/assets/policy-logo.png') ? '../safety_manual/assets/policy-logo.png' : '');

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$docTitleMap = [
    'na_cert' => $year . '년 산업재해 미발생(해당없음) 확인서 (2.1.1)',
    'manual' => '산업재해 발생 시 비상대응 및 원인조사 매뉴얼 (2.1.1)',
    'register' => '최근 5년간 산업재해 발생원인 조사 및 재발방지대책 대장 (2.1.1)',
];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $e($docTitleMap[$mode]) ?> · 주식회사 현대기전</title>
<style>
  @page {
    size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>;
    margin: 0;
  }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  body {
    margin: 0;
    background: #e5e9ec;
    color: #111;
    font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
    font-size: 9.5pt;
    line-height: 1.5;
  }
  .toolbar {
    position: sticky; top: 0; z-index: 100;
    background: #0f2c4c; color: #fff; padding: 10px 20px;
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  }
  .toolbar-left { font-weight: 800; font-size: 13.5px; }
  .toolbar-right { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .tbtn {
    border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); color: #fff;
    padding: 6px 12px; border-radius: 7px; font-weight: 700; font-size: 12.5px; cursor: pointer; text-decoration: none;
  }
  .tbtn:hover { background: rgba(255,255,255,0.22); }
  .tbtn.active { background: #fff; color: #0f2c4c; border-color: #fff; }
  .tbtn.print { background: #0f4c81; border-color: #93c5fd; }

  .sheet {
    width: <?= $isLandscape ? '297mm' : '210mm' ?>;
    min-height: <?= $isLandscape ? '210mm' : '297mm' ?>;
    margin: 14px auto;
    background: #fff;
    padding: 20mm 12mm 12mm 12mm;
    box-shadow: 0 6px 24px rgba(0,0,0,0.14);
    display: flex;
    flex-direction: column;
  }
  body.margin-left .sheet { padding: 14mm 12mm 12mm 20mm; }
  body.margin-wide .sheet { padding: 18mm 16mm 16mm 16mm; }
  body.hide-approval .approval-wrap { display: none !important; }

  .doc-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
    margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #111;
  }
  .doc-title-area small { display: block; font-size: 8.4pt; color: #475569; font-weight: 700; }
  .doc-title-area h1 { margin: 2px 0 4px; font-size: 16.5pt; font-weight: 900; letter-spacing: -0.4px; }
  .doc-meta-line { font-size: 8.6pt; color: #334155; }

  .approval-wrap { display: flex; flex-shrink: 0; border: 1pt solid #000; }
  .ap-side {
    width: 18px; display: flex; align-items: center; justify-content: center; text-align: center;
    font-weight: 800; font-size: 8.3pt; line-height: 1.4; border-right: 1pt solid #000; background: #f8fafc;
  }
  .ap-tbl { border-collapse: collapse; width: 128px; table-layout: fixed; }
  .ap-tbl th, .ap-tbl td { border: 1pt solid #000; text-align: center; padding: 2px 4px; width: 50%; }
  .ap-tbl tr:first-child th { border-top: none; background: #f8fafc; font-size: 8.3pt; font-weight: 800; height: 19px; }
  .ap-tbl tr:last-child td { border-bottom: none; height: 40px; }
  .ap-tbl th:first-child, .ap-tbl td:first-child { border-left: none; }
  .ap-tbl th:last-child, .ap-tbl td:last-child { border-right: none; }

  .sec-title { font-size: 10.2pt; font-weight: 900; margin: 12px 0 6px; padding-left: 7px; border-left: 3.5px solid #0f4c81; }
  table.data-tbl { width: 100%; border-collapse: collapse; font-size: 8.6pt; }
  table.data-tbl th, table.data-tbl td { border: 0.75pt solid #334155; padding: 5px 7px; vertical-align: middle; }
  table.data-tbl th { background: #eef2f7; font-weight: 800; text-align: center; }
  .ctr { text-align: center; }

  .cert-box {
    border: 1.2pt solid #1e293b; padding: 18px 20px; margin: 14px 0; background: #fafcff; line-height: 1.85; font-size: 10.5pt;
  }
  .cert-sign {
    margin-top: 28px; text-align: center; font-size: 11pt; line-height: 2;
  }
  .cert-sign .ceo-line { margin-top: 14px; font-size: 13pt; font-weight: 900; letter-spacing: 2px; }

  .manual-print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
  .mstep { border: 0.8pt solid #334155; border-top: 2.5pt solid #0f4c81; padding: 8px 10px; background: #fafcff; }
  .mstep h4 { margin: 0 0 4px; font-size: 9.2pt; color: #0f4c81; }
  .mstep p { margin: 0; font-size: 8.1pt; line-height: 1.48; white-space: pre-line; }

  .contact-strip { border: 0.8pt solid #0f4c81; background: #eff6ff; padding: 7px 10px; font-size: 8.4pt; font-weight: 700; margin-top: 8px; }

  .doc-footer {
    margin-top: auto;
    padding-top: 8px;
    border-top: 0.75pt solid #cbd5e1;
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }
  .doc-footer-logo { height: 6mm; width: auto; object-fit: contain; }
  .doc-footer-company { font-size: 10pt; font-weight: 800; letter-spacing: 2px; color: #111; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .sheet { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body class="<?= $mode === 'manual' ? 'hide-approval' : '' ?>">

<div class="toolbar">
  <div class="toolbar-left">🖨️ <?= $e($docTitleMap[$mode]) ?></div>
  <div class="toolbar-right">
    <button type="button" class="tbtn <?= $mode === 'manual' ? '' : 'active' ?>" id="btnApproval" onclick="toggleApproval()">결재란 <?= $mode === 'manual' ? 'OFF' : 'ON' ?></button>
    <button type="button" class="tbtn active" id="mTop" onclick="setMargin('')">상단 편철 (기본 20/12mm)</button>
    <button type="button" class="tbtn" id="mLeft" onclick="setMargin('margin-left')">좌측 편철 (좌 20mm)</button>
    <button type="button" class="tbtn" id="mWide" onclick="setMargin('margin-wide')">여유 편철 (18/16mm)</button>
    <button type="button" class="tbtn print" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
    <a class="tbtn" href="accident_investigation.php?year=<?= $year ?>">돌아가기</a>
  </div>
</div>

<?php if ($mode === 'na_cert'): ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 2.1.1 위험성평가·재해예방 · 산업재해 발생원인 조사 (최근 5년간)</small>
        <h1>산업재해 미발생 (해당없음 · N/A) 확인서</h1>
        <div class="doc-meta-line">
          확인 대상연도: <?= $year ?>년 &nbsp;|&nbsp;
          담당부서: <?= $e($yearStatus['department']) ?> &nbsp;|&nbsp;
          관리책임자: <?= $e($yearStatus['manager_name']) ?>
        </div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
      </div>
    </div>

    <div class="sec-title">1. 사업장 개요 및 확인 현황</div>
    <table class="data-tbl">
      <tr>
        <th style="width:22%;">업체명</th>
        <td style="width:28%;"><strong>주식회사 현대기전</strong></td>
        <th style="width:22%;">확인 대상기간</th>
        <td style="width:28%;"><?= $year ?>년 (최근 5년: <?= $year - 4 ?> ~ <?= $year ?>년)</td>
      </tr>
      <tr>
        <th>무재해 개시일</th>
        <td><?= $e($yearStatus['no_accident_since']) ?></td>
        <th>산업재해 발생 건수</th>
        <td><strong><?= count($incidents) ?>건 (<?= count($incidents) === 0 ? '무재해 · 해당없음' : '발생내역 별첨' ?>)</strong></td>
      </tr>
      <tr>
        <th>CSM 2.1.1 평가 표기</th>
        <td><strong>해당없음 (N/A)</strong></td>
        <th>발생 시 대응 매뉴얼</th>
        <td>수립·비치 완료 (개정일: <?= $e($manual['revision_date']) ?>)</td>
      </tr>
    </table>

    <div class="sec-title">2. 확인 내용</div>
    <div class="cert-box">
      <p style="margin:0 0 10px;">
        당사(<strong>주식회사 현대기전</strong>)는 <?= $year ?>년도 기준 산업재해가 발생하지 않았음을 확인합니다.
      </p>
      <p style="margin:0 0 10px;">
        이에 2025 협력업체 안전활동평가표 <strong>2.1.1 산업재해 발생원인 조사</strong> 항목의 예외 기준
        (<em>"단, 해당년도 산업재해가 발생하지 않았을 경우 해당없음 표기"</em>)에 따라 <strong>해당없음(N/A)</strong>으로 보고하며,
        만일의 산업재해 발생 시 즉각적인 초동조치·법정보고·4M 원인조사 및 재발방지대책 수립이 가능하도록
        <strong>「산업재해 발생 시 비상대응 및 원인조사 매뉴얼」</strong>을 상시 비치·운영하고 있습니다.
      </p>
      <?php if (trim((string)$yearStatus['confirm_note']) !== ''): ?>
        <p style="margin:0; font-size:9.3pt; color:#334155;">※ 비고: <?= $e($yearStatus['confirm_note']) ?></p>
      <?php endif; ?>
    </div>

    <div class="cert-sign">
      <div>확인일자: <?= $e(str_replace('-', '년 ', substr((string)$yearStatus['confirm_date'], 0, 7))) ?>월 <?= $e(substr((string)$yearStatus['confirm_date'], 8, 2)) ?>일</div>
      <div class="ceo-line">주식회사 현대기전 &nbsp; 대표이사 &nbsp; <?= $e($yearStatus['ceo_name']) ?> &nbsp; (인)</div>
    </div>

    <div class="doc-footer">
      <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
      <span class="doc-footer-company">주식회사 현대기전</span>
    </div>
  </section>

<?php elseif ($mode === 'manual'): ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 2.1.1 위험성평가·재해예방 · 비상대응 및 사고조사 표준 절차</small>
        <h1>산업재해 발생 시 비상대응 및 원인조사 매뉴얼</h1>
        <div class="doc-meta-line">
          관리책임자: <?= $e($manual['manager_name']) ?> &nbsp;|&nbsp;
          승인: <?= $e($manual['ceo_name']) ?> 대표이사 &nbsp;|&nbsp;
          제·개정일: <?= $e($manual['revision_date']) ?>
        </div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
      </div>
    </div>

    <div class="sec-title">1. 산업재해 발생 시 6단계 대응 및 원인조사 절차</div>
    <div class="manual-print-grid">
      <?php for ($i = 1; $i <= 6; $i++): ?>
        <div class="mstep">
          <h4><?= $e($manual['step' . $i . '_title']) ?></h4>
          <p><?= $e($manual['step' . $i . '_desc']) ?></p>
        </div>
      <?php endfor; ?>
    </div>

    <div class="sec-title">2. 법정 보고 기한 및 기록 보존 기준 (산업안전보건법 제57조)</div>
    <table class="data-tbl">
      <thead>
        <tr>
          <th style="width:15%;">구분</th>
          <th>보고 대상 및 기준</th>
          <th style="width:18%;">기한</th>
          <th style="width:28%;">제출·보존 서류</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="ctr"><strong>중대재해</strong></td>
          <td>사망 1명 이상 / 3개월 이상 요양 부상자 동시 2명 이상 / 부상·직업성질병 동시 10명 이상</td>
          <td class="ctr"><strong>인지 즉시<br>(지체 없이)</strong></td>
          <td>관할 지방고용노동관서 즉시 보고, 현장 보존, 원인조사 보고서</td>
        </tr>
        <tr>
          <td class="ctr"><strong>일반 산업재해</strong></td>
          <td>사망 또는 <strong>3일 이상 휴업</strong>이 필요한 부상·질병 발생 시</td>
          <td class="ctr"><strong>발생일로부터<br>1개월 이내</strong></td>
          <td><strong>산업재해조사표</strong> 제출 (고용노동부), 원인조사·재발방지대책서</td>
        </tr>
        <tr>
          <td class="ctr"><strong>기록 보존</strong></td>
          <td>사업장·근로자 개요, 발생 일시·장소, 원인·과정, 재발방지 계획</td>
          <td class="ctr"><strong>3년 이상<br>(CSM 5년)</strong></td>
          <td>산업재해 발생 기록부, 조사표 사본, 개선 증빙, TBM 공유 서명부</td>
        </tr>
      </tbody>
    </table>

    <div class="contact-strip">
      📞 비상 보고·연락망: <?= $e($manual['emergency_contacts']) ?>
    </div>

    <div class="doc-footer">
      <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
      <span class="doc-footer-company">주식회사 현대기전</span>
    </div>
  </section>

<?php else: ?>
  <section class="sheet">
    <div class="doc-head">
      <div class="doc-title-area">
        <small>CSM 2.1.1 위험성평가·재해예방 · 최근 5년간 산업재해 기록부 (<?= $year - 4 ?> ~ <?= $year ?>년)</small>
        <h1>산업재해 발생원인 조사 및 재발방지대책 대장</h1>
        <div class="doc-meta-line">
          관리기간: <?= $year - 4 ?>년 ~ <?= $year ?>년 &nbsp;|&nbsp;
          관리책임자: <?= $e($yearStatus['manager_name']) ?> &nbsp;|&nbsp;
          출력일: <?= date('Y. m. d.') ?>
        </div>
      </div>
      <div class="approval-wrap">
        <div class="ap-side">결<br>재</div>
        <table class="ap-tbl"><tr><th>작 성</th><th>승 인</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>
      </div>
    </div>

    <table class="data-tbl">
      <thead>
        <tr>
          <th style="width:28px;">No</th>
          <th style="width:68px;">발생일</th>
          <th style="width:95px;">발생장소 / 재해자</th>
          <th style="width:82px;">유형 / 정도</th>
          <th style="width:66px;">조사표<br>제출일</th>
          <th>직접원인 및 근본원인 (4M)</th>
          <th>재발방지대책</th>
          <th style="width:64px;">완료일 /<br>상태</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$incidents): ?>
          <tr>
            <td colspan="8" class="ctr" style="padding:36px; font-size:10.5pt; font-weight:800; color:#15803d;">
              최근 5년간 (<?= $year - 4 ?>년 ~ <?= $year ?>년) 산업재해 발생 내역 없음 — 해당없음 (N/A · 무재해)
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($incidents as $idx => $inc): ?>
          <tr>
            <td class="ctr"><?= $idx + 1 ?></td>
            <td class="ctr"><?= $e($inc['occur_date']) ?></td>
            <td><?= $e($inc['location']) ?><br><span style="color:#475569;"><?= $e($inc['victim_info']) ?></span></td>
            <td class="ctr"><strong><?= $e($inc['accident_type']) ?></strong><br><?= $e($inc['severity']) ?></td>
            <td class="ctr"><?= $e($inc['gov_report_date'] ?: '-') ?></td>
            <td><?= $e($inc['direct_cause']) ?><?= $inc['root_cause_4m'] !== '' ? '<br><strong>[4M]</strong> ' . $e($inc['root_cause_4m']) : '' ?></td>
            <td><?= nl2br($e($inc['countermeasure'])) ?></td>
            <td class="ctr"><?= $e($inc['action_done_date'] ?: '-') ?><br><strong><?= $e($inc['status']) ?></strong></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="doc-footer">
      <?php if ($logoSrc !== ''): ?><img class="doc-footer-logo" src="<?= $e($logoSrc) ?>" alt="로고"><?php endif; ?>
      <span class="doc-footer-company">주식회사 현대기전</span>
    </div>
  </section>
<?php endif; ?>

<script>
function setMargin(cls) {
  document.body.classList.remove('margin-left', 'margin-wide');
  if (cls) document.body.classList.add(cls);
  document.getElementById('mTop')?.classList.toggle('active', cls === '');
  document.getElementById('mLeft')?.classList.toggle('active', cls === 'margin-left');
  document.getElementById('mWide')?.classList.toggle('active', cls === 'margin-wide');
}
function toggleApproval() {
  const hidden = document.body.classList.toggle('hide-approval');
  const btn = document.getElementById('btnApproval');
  if (btn) {
    btn.classList.toggle('active', !hidden);
    btn.textContent = hidden ? '결재란 OFF' : '결재란 ON';
  }
}
</script>
</body>
</html>
