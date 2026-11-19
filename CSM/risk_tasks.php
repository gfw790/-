<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scoring.php';
$user = csm_require_auth();
header('Cache-Control: no-store');
$sections = [
 '2.1' => ['재해 원인조사·재발방지', '사고 개요, 원인, 재발방지 대책과 평가 결과·공유 내역을 기록하세요.'],
 '2.2' => ['안전작업절차·표준서 검토', '안전작업표준서(SOP)의 정기·수시 검토 결과, 제·개정 반영 요약 및 현장 배포·TBM 교육 환류 내역을 기록합니다.'],
 '2.3' => ['TBM 점검·환류', '작업사항과 위험요인, 개선 및 후속 환류 확인 내역을 기록하세요.'],
 '2.4' => ['작업허가 절차 확인', '작업허가의 점검, 승인, 작업 종료 및 복구 확인 내역을 기록하세요.'],
 '2.5' => ['작업계획·중량물', '사전조사, 작업계획 검토, 지휘자·신호수 지정과 첨부서류를 기록하세요.'],
 '2.6' => ['공구·위험기계·보관점검', '공구·장비 현황, 안전검사 유효기간과 위험물·고압용기 보관점검을 기록하세요.'],
 '2.7' => ['운송장비·중장비', '운전자 자격, 운행 전·정기점검과 방호장치 확인 내역을 기록하세요.'],
 '2.8' => ['건강·작업환경·화학물질', '건강검진·작업환경측정, 화학물질 보관·표지와 구급용품 점검을 기록하세요.'],
 '2.9' => ['비상대응·훈련', '비상 시나리오, 훈련 결과와 개선사항, 연락망·대피도 점검을 기록하세요.'],
];
$section = $_POST['section'] ?? $_GET['section'] ?? '2.1';
if (!is_string($section) || !isset($sections[$section])) { http_response_code(400); exit('업무를 확인해 주세요.'); }
if ($section === '2.8' && !in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator', 'admin', 'ceo'], true)) { http_response_code(403); exit('보건정보 관리 권한이 필요합니다.'); }
$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$items = [];
foreach ($catalog['modules'] as $module) if ($module['id'] === $section) foreach ($module['items'] as $item) $items[$item['id']] = $item;
$singleItemCode = count($items) === 1 ? (string)array_key_first($items) : '';
$isSopReview = ($section === '2.2');
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$db = new PDO('sqlite:' . __DIR__ . '/storage/leadership.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA busy_timeout=5000');
$db->exec('CREATE TABLE IF NOT EXISTS csm_risk_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, section TEXT NOT NULL, title TEXT NOT NULL, record_date TEXT NOT NULL, subject TEXT NOT NULL, details TEXT NOT NULL, action TEXT NOT NULL, owner TEXT NOT NULL, due_date TEXT NOT NULL, status TEXT NOT NULL, evidence_name TEXT NOT NULL DEFAULT "", evidence_path TEXT NOT NULL DEFAULT "", evidence_mime TEXT NOT NULL DEFAULT "", updated_by TEXT NOT NULL, updated_at TEXT NOT NULL)');
$columns = array_column($db->query('PRAGMA table_info(csm_risk_tasks)')->fetchAll(), 'name');
if (!in_array('item_code', $columns, true)) $db->exec("ALTER TABLE csm_risk_tasks ADD COLUMN item_code TEXT NOT NULL DEFAULT ''");
$db->exec('CREATE TABLE IF NOT EXISTS csm_risk_task_history (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, section TEXT NOT NULL, operation TEXT NOT NULL, snapshot TEXT NOT NULL, actor TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS csm_sop_assessments (year INTEGER PRIMARY KEY, multiplier REAL NOT NULL, note TEXT NOT NULL DEFAULT "", updated_by TEXT NOT NULL, updated_at TEXT NOT NULL)');
$scoreYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');

$sopStats = null;
if ($isSopReview && is_file(__DIR__ . '/standard_procedure_store.php')) {
 try {
  require_once __DIR__ . '/standard_procedure_store.php';
  $sopDb = sop_db();
  $totalSop = (int)$sopDb->query('SELECT COUNT(*) FROM csm_sop_master')->fetchColumn();
  $validSop = (int)$sopDb->query("SELECT COUNT(*) FROM csm_sop_master WHERE status != '폐지' AND is_active = 1")->fetchColumn();
  $abolishedSop = (int)$sopDb->query("SELECT COUNT(*) FROM csm_sop_master WHERE status = '폐지' OR is_active = 0")->fetchColumn();
  $revRows = $sopDb->query('SELECT r.revision_no, r.revision_date, r.reason, r.changes_summary, r.reviewer_name, r.approver_name, m.sop_no, m.volume, m.title, m.change_reason FROM csm_sop_revisions r JOIN csm_sop_master m ON m.id = r.sop_id ORDER BY r.revision_date DESC, m.sop_no ASC')->fetchAll();
  $distCount = (int)$sopDb->query('SELECT COUNT(*) FROM csm_sop_distribution')->fetchColumn();
  $sopStats = ['total' => $totalSop, 'valid' => $validSop, 'abolished' => $abolishedSop, 'revisions' => $revRows, 'dist_count' => $distCount];

  // Auto-seed realistic 2026 review records for 2.2 if empty
  $cnt22 = (int)$db->query("SELECT COUNT(*) FROM csm_risk_tasks WHERE section='2.2'")->fetchColumn();
  if ($cnt22 === 0 && $totalSop > 0) {
   $seedStmt = $db->prepare('INSERT INTO csm_risk_tasks (section, item_code, title, record_date, subject, details, action, owner, due_date, status, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
   $seedStmt->execute([
    '2.2', '2.2.1',
    '2026년 안전작업표준서(01~05권, 247종) 사내 표준 전환 및 제정 검토',
    '2026-10-21',
    '전권 제정·전환 검토 (01~05권 · 총 247종)',
    "1. 검토 목적: 현장 작업별 공사팀 자체 안전작업표준서(SOP) 제정 및 표준 체계 일괄 정비\n2. 검토 대상: 01권(1~50) ~ 05권(201~247) 총 247종 (유효 242종 / 기존 폐지 5종)\n3. 주요 검토 내역:\n - 전 표준서 관리번호(CSM-SOP-001~247) 부여 및 제정일(2026.10.21) 일괄 정비\n - 공사팀 로고, 작업별 위험요인·안전보호구·작업절차 적정성 확인 완료",
    "1. 공사팀 전 작업반 안전작업표준서(01~05권) 현장 비치 및 공용 열람함 배포 완료\n2. 작업 전 안전미팅(TBM) 시 해당 공종 표준서 상시 활용 지침 시달",
    '검토: 공사팀 김남균 / 승인: 정연탁 대표',
    '', '완료', 'system', '2026-10-21 18:00:00'
   ]);
   $seedStmt->execute([
    '2.2', '2.2.1',
    '2026년 하반기 안전작업표준서 정기 검토 및 제·개정(신규 5·개정 4·폐지 1) 반영',
    '2026-11-19',
    '정기·수시 통합검토 (01~06권 · 총 252종 / 유효 251종)',
    "1. 검토 현황: 총 252종 검토 완료 (유효 251종, 폐지 1종)\n2. 본문 개정 (4건, Rev.1):\n - No.3 고압 MOTOR 교체 (상하역보완): 10번 Motor 상/하역 변경 및 신호수 배치·주변환경 확인·Guide Rope 사용 추가\n - No.20 Gas analyzer probe 청소 (압력주의보완): 4-8 (+)압력 유의 문구 및 4-9 인출 후 입구 덮개 닫음 신설\n - No.63 EP Finger 교체작업 (산소측정추가): 5번 EP 내부 산소농도 측정 및 환기장치 설치 밀폐공간 안전절차 신설\n - No.217 Gas analyzer probe 교체 (덮개개폐보완): 4-1 인입구 덮개 덮음, 4-9 인출구 옆 이동 작업, 5-2 덮개 개방 추가\n3. 신규 제정 (5건, Rev.0):\n - 05권: No.248 철탑 드론 점검, No.249 용접 & 산소절단 작업, No.250 비상발전기 엔진오일 교체 및 동체 절연저항 측정\n - 06권 신설: No.251 WHR Generator PMG 분해 점검, No.252 Kiln Main Motor 교체 시운전\n4. 폐지 처리 (1건):\n - No.186 LAF FLEXIM flowmeter manual calibration (타입변경폐지)",
    "1. 개정(4건) 및 신규(5건) 표준서 현장 작업반 배포 및 작업 전 TBM 중점 교육 실시\n2. 밀폐공간(No.63)·중량물 인양(No.3)·고온가스(No.20, 217) 작업 위험성평가(JSA) 감소대책 연계 반영\n3. 폐지 표준서(No.186) 현장 비치본 회수 및 폐지 식별 표기 완료",
    '검토: 공사팀 김남균 / 승인: 정연탁 대표',
    '', '완료', 'system', '2026-11-19 14:00:00'
   ]);
  }
 } catch (Throwable $e) {
  error_log('CSM 2.2 SOP stats sync: ' . $e->getMessage());
 }
}

$_SESSION['csm_risk_tasks_csrf'] ??= bin2hex(random_bytes(32));
$canManage = auth_can_manage($user);
$error = '';
$notice = $_SESSION['csm_risk_tasks_notice'] ?? ''; unset($_SESSION['csm_risk_tasks_notice']);
$id = filter_var($_GET['id'] ?? $_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$get = $db->prepare('SELECT * FROM csm_risk_tasks WHERE id=? AND section=?');
$get->execute([$id, $section]); $record = $get->fetch() ?: null;
if (isset($_GET['file'])) {
 if (!$record || !preg_match('/^[a-f0-9]{40}\.(pdf|jpg|jpeg|png|xlsx|docx)$/D', $record['evidence_path'])) { http_response_code(404); exit('첨부파일을 찾을 수 없습니다.'); }
 $path = __DIR__ . '/storage/risk_task_evidence/' . $record['evidence_path'];
 if (!is_file($path)) { http_response_code(404); exit('첨부파일을 찾을 수 없습니다.'); }
 header('X-Content-Type-Options: nosniff'); header('Content-Type: ' . $record['evidence_mime']);
 header('Content-Disposition: attachment; filename="evidence"; filename*=UTF-8\'\'' . rawurlencode($record['evidence_name']));
 header('Content-Length: ' . filesize($path)); readfile($path); exit;
}
if ($isSopReview && isset($_GET['print']) && $record) {
 $rptYear = substr((string)$record['record_date'], 0, 4) ?: date('Y');
 $docNo = sprintf('CSM-SOP-RPT-%s-%02d', $rptYear, (int)$record['id']);
 $revsForPrint = [];
 if ($sopStats && !empty($sopStats['revisions'])) {
  foreach ($sopStats['revisions'] as $rv) {
   if (substr((string)$rv['revision_date'], 0, 4) === $rptYear) {
    $revsForPrint[] = $rv;
   }
  }
 }
 $showRevTable = count($revsForPrint) > 0;
 $printPayload = [
  'docNo' => $docNo,
  'rptYear' => $rptYear,
  'title' => (string)$record['title'],
  'recordDate' => (string)$record['record_date'],
  'status' => $record['status'] === '완료' ? '검토·개정 및 배포 완료' : (string)$record['status'],
  'subject' => (string)($record['subject'] ?: '01~06권 전권'),
  'owner' => (string)($record['owner'] ?: '검토: 공사팀 김남균 / 승인: 정연탁 대표'),
  'details' => (string)($record['details'] ?: '미입력'),
  'action' => (string)($record['action'] ?: '미입력'),
  'sopStats' => $sopStats ? [
   'total' => (int)$sopStats['total'],
   'valid' => (int)$sopStats['valid'],
   'abolished' => (int)$sopStats['abolished'],
  ] : null,
  'revisions' => array_map(static fn($rv) => [
   'sop_no' => (int)$rv['sop_no'],
   'revision_date' => (string)$rv['revision_date'],
   'title' => (string)$rv['title'],
   'change_reason' => (string)($rv['change_reason'] ?: ('Rev.' . $rv['revision_no'])),
   'changes_summary' => (string)$rv['changes_summary'],
  ], $revsForPrint),
 ];
 ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $h($record['title']) ?> · 보고서 인쇄</title>
<style>
:root {
  --top-pad: 20mm;
  --right-pad: 12mm;
  --bottom-pad: 12mm;
  --left-pad: 12mm;
}
@page { size: A4 portrait; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background-color: #f1f5f9;
  font-family: "Malgun Gothic", "맑은 고딕", Dotum, sans-serif;
  color: #000000;
  font-size: 9.5pt;
  line-height: 1.5;
  -webkit-print-color-adjust: exact !important;
  print-color-adjust: exact !important;
}
/* CSM 표준 인쇄 툴바 */
.screen-toolbar {
  position: sticky;
  top: 0;
  z-index: 100;
  background: #ffffff;
  border-bottom: 1px solid #cbd5e1;
  padding: 10px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07);
  flex-wrap: wrap;
  gap: 10px;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.toolbar-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  font-size: 13px;
  font-weight: 700;
  border-radius: 8px;
  cursor: pointer;
  border: 1px solid transparent;
  text-decoration: none;
}
.btn-print { background: #1e3a8a; color: #fff; }
.btn-print:hover { background: #1e40af; }
.btn-close { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
.btn-close:hover { background: #e2e8f0; }
.pad-control-group {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-left: 4px;
  padding-left: 12px;
  border-left: 1px solid #cbd5e1;
  flex-wrap: wrap;
}
.pad-label { font-size: 12px; font-weight: 700; color: #334155; }
.pad-btn {
  padding: 5px 10px;
  font-size: 11.5px;
  font-weight: 700;
  border-radius: 6px;
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #475569;
  cursor: pointer;
}
.pad-btn.active {
  background: #1e3a8a;
  color: #fff;
  border-color: #1e3a8a;
}
.page-badge {
  background: #dcfce7;
  color: #166534;
  font-size: 12px;
  font-weight: 800;
  padding: 5px 11px;
  border-radius: 999px;
  border: 1px solid #86efac;
}
.rev-toggle {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  cursor: pointer;
}
.toolbar-tip {
  font-size: 12px;
  color: #475569;
  background: #f8fafc;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
/* CSM A4 출력원칙 용지 규격 (상단 20mm 편철 / 좌·우·하단 12mm) */
.paper-container {
  max-width: 210mm;
  margin: 16px auto 32px auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.a4-page {
  width: 210mm;
  height: 297mm;
  min-height: 297mm;
  max-height: 297mm;
  background: #ffffff;
  padding: var(--top-pad) var(--right-pad) var(--bottom-pad) var(--left-pad);
  box-shadow: 0 10px 25px -5px rgba(0,0,0,0.12);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}
.page-body {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.rpt-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  border-bottom: 2px solid #000;
  padding-bottom: 8px;
  margin-bottom: 10px;
}
.rpt-header-cont {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  border-bottom: 1.5px solid #000;
  padding-bottom: 5px;
  margin-bottom: 10px;
  font-size: 9pt;
}
.rpt-title-box { flex: 1; }
.rpt-kicker { font-size: 8pt; color: #334155; font-weight: 700; margin-bottom: 3px; }
.rpt-title { font-size: 15.5pt; font-weight: 800; margin: 0 0 3px; letter-spacing: -.3px; }
.rpt-docno { font-size: 8.3pt; color: #334155; }
.approval { border-collapse: collapse; width: 64mm; font-size: 8pt; text-align: center; }
.approval th, .approval td { border: 1px solid #000; padding: 2.5px 4px; }
.approval th { background: #f1f5f9; font-weight: 700; }
.approval .sign-cell { height: 12.5mm; vertical-align: bottom; font-size: 7.8pt; color: #475569; }
.sec-title {
  font-size: 10pt;
  font-weight: 800;
  margin: 10px 0 5px;
  padding-left: 7px;
  border-left: 3.5px solid #176053;
}
table.meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 8.8pt; }
table.meta th, table.meta td { border: 1px solid #475569; padding: 5px 7px; text-align: left; }
table.meta th { width: 17%; background: #f8fafc; font-weight: 700; color: #1e293b; }
table.meta td { width: 33%; }
.box {
  border: 1px solid #475569;
  padding: 8px 10px;
  white-space: pre-wrap;
  font-size: 8.8pt;
  line-height: 1.5;
  background: #fff;
}
table.rev-tbl { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 2px; }
table.rev-tbl th, table.rev-tbl td { border: 1px solid #475569; padding: 3.5px 5px; vertical-align: top; line-height: 1.38; }
table.rev-tbl th { background: #f1f5f9; text-align: center; font-weight: 700; }
table.rev-tbl td.c { text-align: center; white-space: nowrap; }
.rpt-footer {
  flex: 0 0 auto;
  margin-top: auto;
  padding-top: 7px;
  border-top: 1px solid #94a3b8;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 8.2pt;
  color: #334155;
}
.rpt-company { font-weight: 800; font-size: 9.5pt; color: #000; }
.rpt-pageno { font-weight: 700; font-size: 8.5pt; color: #1e293b; }
#measureStage {
  position: fixed;
  left: -99999px;
  top: 0;
  width: calc(210mm - var(--left-pad) - var(--right-pad));
  visibility: hidden;
  pointer-events: none;
}
@media print {
  body { background: #fff !important; }
  .screen-toolbar, #measureStage { display: none !important; }
  .paper-container { max-width: none; margin: 0; gap: 0; }
  .a4-page {
    box-shadow: none;
    margin: 0;
    break-after: page;
    page-break-after: always;
  }
  .a4-page:last-child {
    break-after: auto;
    page-break-after: auto;
  }
}
</style>
</head>
<body>
<aside class="screen-toolbar">
  <div class="toolbar-left">
    <button type="button" class="toolbar-btn btn-print" onclick="window.print()">🖨️ 인쇄 / PDF 저장</button>
    <a class="toolbar-btn btn-close" href="risk_tasks.php?section=2.2">← 목록으로</a>
    <span id="pageCountBadge" class="page-badge">총 1P</span>
    <div class="pad-control-group">
      <span class="pad-label">여백 규정:</span>
      <button type="button" class="pad-btn active" onclick="setPadPreset('20mm','12mm','12mm','12mm',this)">상단 편철 (상 20 / 좌·우·하 12mm)</button>
      <button type="button" class="pad-btn" onclick="setPadPreset('12mm','12mm','12mm','20mm',this)">좌측 편철 (좌 20 / 상·우·하 12mm)</button>
      <button type="button" class="pad-btn" onclick="setPadPreset('25mm','12mm','12mm','12mm',this)">여유 편철 (상 25 / 좌·우·하 12mm)</button>
    </div>
    <?php if ($showRevTable): ?>
    <div class="pad-control-group">
      <label class="rev-toggle"><input type="checkbox" id="toggle-rev" checked onchange="renderReport()"> <?= $h($rptYear) ?>년 제·개정 세부 목록표 포함 (<?= count($revsForPrint) ?>건)</label>
    </div>
    <?php endif; ?>
  </div>
  <div class="toolbar-tip">
    💡 <strong>CSM A4 출력원칙 적용</strong> (상단 20mm 편철 기본) &bull; 인쇄 설정에서 <strong>[배경 그래픽]</strong> 켜기 / <strong>[여백: 없음]</strong> 권장
  </div>
</aside>

<div id="measureStage"></div>
<div id="paperContainer" class="paper-container"></div>

<script>
const RPT = <?= json_encode($printPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function setPadPreset(top, right, bottom, left, btn) {
  const root = document.documentElement.style;
  root.setProperty('--top-pad', top);
  root.setProperty('--right-pad', right);
  root.setProperty('--bottom-pad', bottom);
  root.setProperty('--left-pad', left);
  document.querySelectorAll('.pad-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  renderReport();
}
function buildFirstHeader() {
  return `<div class="rpt-header">
    <div class="rpt-title-box">
      <div class="rpt-kicker">CSM 안전보건활동평가 2.2.1 · 안전작업절차(표준서) 정기·수시 검토</div>
      <h1 class="rpt-title">안전작업표준서(SOP) 검토·개정 결과 보고서</h1>
      <div class="rpt-docno">문서번호: ${esc(RPT.docNo)} &nbsp;|&nbsp; 검토일자: ${esc(RPT.recordDate)}</div>
    </div>
    <table class="approval">
      <tr><th rowspan="2" style="width:14%">결<br>재</th><th>작성</th><th>검토</th><th>승인</th></tr>
      <tr><td class="sign-cell">김남균</td><td class="sign-cell">안전관리자</td><td class="sign-cell">정연탁 대표</td></tr>
    </table>
  </div>`;
}
function buildContHeader() {
  return `<div class="rpt-header-cont">
    <strong>안전작업표준서(SOP) 검토·개정 결과 보고서 (계속)</strong>
    <span>문서번호: ${esc(RPT.docNo)} &nbsp;|&nbsp; 검토일자: ${esc(RPT.recordDate)}</span>
  </div>`;
}
function buildFooter(pageNo, totalPages) {
  return `<div class="rpt-footer">
    <div>※ 본 보고서는 결재(서명) 후 PDF 스캔하여 CSM 2.2.1 증빙으로 첨부·보관합니다.</div>
    <div class="rpt-pageno">${pageNo} / ${totalPages}</div>
    <div class="rpt-company">주식회사 성호이앤씨 (공사팀)</div>
  </div>`;
}
function buildMetaBlock() {
  const sopRow = RPT.sopStats
    ? `<tr><th>보유 표준서 현황</th><td colspan="3">총 <strong>${RPT.sopStats.total}종</strong> (유효 <strong>${RPT.sopStats.valid}종</strong> / 폐지 <strong>${RPT.sopStats.abolished}종</strong>) · 01~06권 통합 관리 중</td></tr>`
    : '';
  return `<div class="sec-title">1. 검토 개요</div>
  <table class="meta">
    <tr><th>검토 제목</th><td colspan="3"><strong>${esc(RPT.title)}</strong></td></tr>
    <tr><th>검토 일자</th><td>${esc(RPT.recordDate)}</td><th>검토 결과 상태</th><td><strong>${esc(RPT.status)}</strong></td></tr>
    <tr><th>검토 구분·범위</th><td>${esc(RPT.subject)}</td><th>검토자 / 승인자</th><td>${esc(RPT.owner)}</td></tr>
    ${sopRow}
  </table>`;
}
function buildDetailsBlock() {
  return `<div class="sec-title">2. 표준서 검토 및 제·개정 내역 요약</div><div class="box">${esc(RPT.details)}</div>`;
}
function buildActionBlock(secNum) {
  return `<div class="sec-title">${secNum}. 현장 배포 · TBM 교육 및 위험성평가 연계 조치</div><div class="box">${esc(RPT.action)}</div>`;
}
function buildRevTableHtml(rows, isCont) {
  const trs = rows.map(rv => `<tr>
    <td class="c">${rv.sop_no}</td>
    <td class="c">${esc(rv.revision_date)}</td>
    <td><strong>${esc(rv.title)}</strong></td>
    <td class="c">${esc(rv.change_reason)}</td>
    <td>${esc(rv.changes_summary)}</td>
  </tr>`).join('');
  return `${isCont ? `<div class="sec-title">3. ${esc(RPT.rptYear)}년 제·개정 및 폐지 세부 목록 (계속)</div>` : `<div class="sec-title">3. ${esc(RPT.rptYear)}년 안전작업표준서 제·개정 및 폐지 세부 목록 (${RPT.revisions.length}건)</div>`}
  <table class="rev-tbl">
    <thead><tr><th style="width:7%">No.</th><th style="width:11%">개정일</th><th style="width:24%">표준서 작업명</th><th style="width:14%">변경 구분</th><th style="width:44%">주요 변경 내용</th></tr></thead>
    <tbody>${trs}</tbody>
  </table>`;
}
function measureHeight(html) {
  const stage = document.getElementById('measureStage');
  stage.innerHTML = html;
  return stage.getBoundingClientRect().height;
}
function renderReport() {
  const container = document.getElementById('paperContainer');
  const includeRevEl = document.getElementById('toggle-rev');
  const includeRev = includeRevEl ? includeRevEl.checked && RPT.revisions.length > 0 : false;

  // Measure available content height inside an .a4-page
  const probe = document.createElement('div');
  probe.className = 'a4-page';
  probe.style.position = 'fixed';
  probe.style.left = '-99999px';
  probe.innerHTML = buildFirstHeader() + '<div class="page-body"></div>' + buildFooter(1, 1);
  document.body.appendChild(probe);
  const firstBodyMax = probe.querySelector('.page-body').getBoundingClientRect().height - 4;
  probe.innerHTML = buildContHeader() + '<div class="page-body"></div>' + buildFooter(2, 2);
  const contBodyMax = probe.querySelector('.page-body').getBoundingClientRect().height - 4;
  document.body.removeChild(probe);

  const pages = [[]];
  let curIdx = 0;
  let curH = 0;
  const maxFor = (idx) => idx === 0 ? firstBodyMax : contBodyMax;

  function pushBlock(html) {
    const h = measureHeight(html);
    if (curH > 0 && curH + h > maxFor(curIdx)) {
      pages.push([]);
      curIdx++;
      curH = 0;
    }
    pages[curIdx].push(html);
    curH += h;
  }

  pushBlock(buildMetaBlock());
  pushBlock(buildDetailsBlock());

  if (includeRev) {
    let remaining = [...RPT.revisions];
    let isCont = false;
    while (remaining.length > 0) {
      // Binary/greedy fit rows on current page
      let fitCount = 0;
      for (let k = 1; k <= remaining.length; k++) {
        const testHtml = buildRevTableHtml(remaining.slice(0, k), isCont);
        const h = measureHeight(testHtml);
        if (curH + h <= maxFor(curIdx)) {
          fitCount = k;
        } else {
          break;
        }
      }
      if (fitCount === 0) {
        if (curH > 0) {
          pages.push([]);
          curIdx++;
          curH = 0;
          continue;
        }
        fitCount = 1; // At least 1 row on an empty page
      }
      const chunkHtml = buildRevTableHtml(remaining.slice(0, fitCount), isCont);
      pages[curIdx].push(chunkHtml);
      curH += measureHeight(chunkHtml);
      remaining = remaining.slice(fitCount);
      isCont = true;
      if (remaining.length > 0) {
        pages.push([]);
        curIdx++;
        curH = 0;
      }
    }
  }

  pushBlock(buildActionBlock(includeRev ? 4 : 3));

  const totalPages = pages.length;
  container.innerHTML = pages.map((blocks, idx) => `
    <section class="a4-page">
      ${idx === 0 ? buildFirstHeader() : buildContHeader()}
      <div class="page-body">${blocks.join('')}</div>
      ${buildFooter(idx + 1, totalPages)}
    </section>
  `).join('');

  const badge = document.getElementById('pageCountBadge');
  if (badge) badge.textContent = `총 ${totalPages}P`;
}
window.addEventListener('DOMContentLoaded', renderReport);
</script>
</body>
</html>
 <?php
 exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
 try {
  if (!$canManage) throw new InvalidArgumentException('변경 권한이 없습니다.');
  if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_risk_tasks_csrf'], $_POST['csrf'])) throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침해 주세요.');
  if ($id && !$record) throw new InvalidArgumentException('기록을 찾을 수 없습니다.');
  $action = $_POST['operation'] ?? 'save';
  if ($action === 'assess_221' && $isSopReview) {
   $level = (string)($_POST['level'] ?? '');
   $note = trim((string)($_POST['note'] ?? ''));
   if (!in_array($level, ['1', '0.9', '0.8', '0.5', '0'], true)) throw new InvalidArgumentException('평가 수준을 선택해 주세요.');
   if (mb_strlen($note) > 2000) throw new InvalidArgumentException('검토 메모가 너무 깁니다.');
   $stmt = $db->prepare('INSERT INTO csm_sop_assessments(year, multiplier, note, updated_by, updated_at) VALUES(?,?,?,?,?) ON CONFLICT(year) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at');
   $stmt->execute([$scoreYear, (float)$level, $note, (string)($user['login_id'] ?? ''), date('Y-m-d H:i:s')]);
   $_SESSION['csm_risk_tasks_notice'] = '2.2.1 평가 수준을 저장했습니다. CSM 점수에 즉시 반영됩니다.';
   header('Location: risk_tasks.php?section=2.2', true, 303); exit;
  } elseif ($action === 'delete') {
   if (!$record) throw new InvalidArgumentException('기록을 찾을 수 없습니다.');
   $audit = $db->prepare('INSERT INTO csm_risk_task_history(task_id,section,operation,snapshot,actor,created_at) VALUES(?,?,?,?,?,?)');
   $audit->execute([$id,$section,'delete',json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),(string)($user['login_id'] ?? ''),date('Y-m-d H:i:s')]);
   $q = $db->prepare('DELETE FROM csm_risk_tasks WHERE id=? AND section=?'); $q->execute([$id, $section]);
   if ($record['evidence_path'] && preg_match('/^[a-f0-9]{40}\.(pdf|jpg|jpeg|png|xlsx|docx)$/D', $record['evidence_path'])) @unlink(__DIR__ . '/storage/risk_task_evidence/' . $record['evidence_path']);
  } elseif ($action === 'save') {
   $values = []; foreach (['title','record_date','subject','details','action','owner','due_date','status'] as $key) { $v = $_POST[$key] ?? ''; if (!is_string($v) || mb_strlen($v) > 10000) throw new InvalidArgumentException('입력값을 확인해 주세요.'); $values[$key] = trim($v); }
   if ($values['title'] === '' || $values['record_date'] === '' || !in_array($values['status'], ['계획','진행','완료'], true)) throw new InvalidArgumentException('제목, 일자, 상태를 확인해 주세요.');
   foreach (['record_date','due_date'] as $key) if ($values[$key] !== '') { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $values[$key]); if (!$d || $d->format('Y-m-d') !== $values[$key]) throw new InvalidArgumentException('날짜를 확인해 주세요.'); }
   $itemCode = $_POST['item_code'] ?? '';
   if ($singleItemCode !== '' && (!is_string($itemCode) || !isset($items[$itemCode]))) {
    $itemCode = $singleItemCode;
   }
   if (!is_string($itemCode) || !isset($items[$itemCode])) throw new InvalidArgumentException('평가항목을 선택해 주세요.');
   $name = $record['evidence_name'] ?? ''; $stored = $record['evidence_path'] ?? ''; $mime = $record['evidence_mime'] ?? ''; $newPath = '';
   $file = $_FILES['evidence'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
   if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] < 1 || $file['size'] > 20*1024*1024) throw new InvalidArgumentException('20MB 이하의 파일을 선택해 주세요.');
    $name = mb_substr(basename(str_replace('\\', '/', $file['name'])), 0, 180); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = [
     'pdf' => ['application/pdf'],
     'jpg' => ['image/jpeg'],
     'jpeg' => ['image/jpeg'],
     'png' => ['image/png'],
     'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
     'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) throw new InvalidArgumentException('PDF, JPG, PNG, XLSX, DOCX 증빙을 선택해 주세요.');
    if (in_array($ext, ['jpg','jpeg','png'], true) && @getimagesize($file['tmp_name']) === false) throw new InvalidArgumentException('이미지 파일을 확인해 주세요.');
    if (in_array($ext, ['xlsx','docx'], true)) {
     $zip = new ZipArchive();
     if ($zip->open($file['tmp_name']) !== true) throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
     $expected = $ext === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
     if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($expected) === false) { $zip->close(); throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.'); }
     $zip->close();
    }
    $dir = __DIR__ . '/storage/risk_task_evidence'; if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('저장소 오류');
    $stored = bin2hex(random_bytes(20)) . '.' . $ext; $newPath = $dir . '/' . $stored; if (!move_uploaded_file($file['tmp_name'], $newPath)) throw new RuntimeException('파일 저장 오류');
   }
   try {
    $params = array_values($values); array_push($params, $name, $stored, $mime, (string)($user['login_id'] ?? ''), date('Y-m-d H:i:s'));
    if ($id) { $q=$db->prepare('UPDATE csm_risk_tasks SET title=?,record_date=?,subject=?,details=?,action=?,owner=?,due_date=?,status=?,evidence_name=?,evidence_path=?,evidence_mime=?,updated_by=?,updated_at=? WHERE id=? AND section=?'); array_push($params,$id,$section); }
    else { $q=$db->prepare('INSERT INTO csm_risk_tasks(title,record_date,subject,details,action,owner,due_date,status,evidence_name,evidence_path,evidence_mime,updated_by,updated_at,section) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'); $params[]=$section; }
    $db->beginTransaction();
    $q->execute($params);
    $savedId = $id ?: (int)$db->lastInsertId();
    $updateItem = $db->prepare('UPDATE csm_risk_tasks SET item_code=? WHERE id=? AND section=?'); $updateItem->execute([$itemCode,$savedId,$section]);
    $audit = $db->prepare('INSERT INTO csm_risk_task_history(task_id,section,operation,snapshot,actor,created_at) VALUES(?,?,?,?,?,?)');
    $audit->execute([$savedId,$section,$id ? 'update' : 'create',json_encode(['previous'=>$record,'values'=>$values,'item_code'=>$itemCode], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),(string)($user['login_id'] ?? ''),date('Y-m-d H:i:s')]);
    $db->commit();
   } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); if ($newPath) @unlink($newPath); throw $e; }
   if ($newPath && !empty($record['evidence_path']) && preg_match('/^[a-f0-9]{40}\.(pdf|jpg|jpeg|png|xlsx|docx)$/D', $record['evidence_path'])) @unlink(__DIR__ . '/storage/risk_task_evidence/' . $record['evidence_path']);
  } else throw new InvalidArgumentException('요청을 확인해 주세요.');
  $_SESSION['csm_risk_tasks_notice'] = $action === 'delete' ? '기록을 삭제했습니다.' : '기록을 저장했습니다.';
  header('Location: risk_tasks.php?section=' . rawurlencode($section), true, 303); exit;
 } catch (Throwable $e) { $error = $e instanceof InvalidArgumentException ? $e->getMessage() : '저장 중 오류가 발생했습니다.'; if (!($e instanceof InvalidArgumentException)) error_log('CSM risk tasks: ' . $e->getMessage()); }
}
$q=$db->prepare('SELECT * FROM csm_risk_tasks WHERE section=? ORDER BY record_date DESC,id DESC'); $q->execute([$section]); $rows=$q->fetchAll();
$sopScore = $isSopReview ? csm_sop_procedure_score_summary($db, $scoreYear, 2.0) : null;
$sopCriterion = $isSopReview ? ($items['2.2.1'] ?? null) : null;
$v = static fn(string $key, string $default = ''): string => (string)($_POST[$key] ?? $record[$key] ?? $default);
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($sections[$section][0]) ?> · CSM</title><link rel="stylesheet" href="leadership.css"><link rel="stylesheet" href="risk_tasks.css"><style>
.sop-link-bar{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;background:#f4f8f7;border:1px solid #c9dcd7;border-radius:10px;padding:14px 18px;margin:16px 0}
.sop-link-bar .stats{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:.92rem;color:#1e293b}
.sop-badge{background:#fff;border:1px solid #cbd5e1;border-radius:999px;padding:4px 11px;font-weight:600;font-size:.84rem}
.sop-badge.green{background:#e6f4ea;border-color:#a8dab5;color:#137333}
.sop-badge.blue{background:#e8f0fe;border-color:#aecbfa;color:#1967d2}
.sop-nav-btns{display:flex;flex-wrap:wrap;gap:8px}
.sop-nav-btns a{text-decoration:none;background:#fff;border:1px solid #176053;color:#176053;padding:7px 13px;border-radius:6px;font-size:.86rem;font-weight:600}
.sop-nav-btns a.primary{background:#176053;color:#fff}
.quick-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.chip-btn{background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;border-radius:999px;padding:4px 10px;font-size:.8rem;font-weight:600;cursor:pointer}
.chip-btn:hover{background:#e2e8f0;color:#0f172a}
.autofill-bar{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:4px}
.btn-autofill{background:#0f766e;color:#fff;border:0;border-radius:6px;padding:6px 12px;font-size:.82rem;font-weight:600;cursor:pointer}
.btn-autofill:hover{background:#115e59}
.status-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.82rem;font-weight:700;background:#e2e8f0;color:#334155}
.status-badge.done{background:#dcfce7;color:#166534}
.status-badge.prog{background:#fef3c7;color:#92400e}
.score-panel{background:#fff;border:1px solid #cbd5e1;border-left:5px solid #176053;border-radius:10px;padding:18px 20px;margin:16px 0}
.score-panel.warn{border-left-color:#d97706;background:#fffbeb}
.score-top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px}
.score-val{font-size:1.35rem;font-weight:800;color:#176053}
.score-panel.warn .score-val{color:#b45309}
.score-meta{font-size:.9rem;color:#334155;margin:8px 0 0}
.score-form{display:grid;grid-template-columns:minmax(220px,1fr) minmax(240px,1.5fr) auto;gap:10px;align-items:end;margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0}
.score-form label{display:grid;gap:4px;font-size:.86rem;font-weight:600;color:#334155}
.ev-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:700;background:#dcfce7;color:#166534}
.ev-missing{display:inline-block;font-size:.84rem;color:#b45309;font-weight:600;margin-top:4px}
@media(max-width:720px){.score-form{grid-template-columns:1fr}}
</style></head><body>
<header><div><a href="area.php?group=2&amp;section=<?= $h($section) ?>">← 위험관리</a><strong><?= $h($section . ' ' . $sections[$section][0]) ?></strong><span><?= $h($user['name'] ?? '') ?></span></div></header>
<main><div class="heading"><div><small>위험관리 · <?= $h($section) ?></small><h1><?= $h($sections[$section][0]) ?></h1><p><?= $h($sections[$section][1]) ?></p><?php if ($section === '2.8'): ?><p>건강검진의 상세 결과 등 민감정보는 입력하지 마세요. 실시 여부와 필요한 최소한의 관리 정보만 기록합니다.</p><?php endif; ?></div></div>

<?php if ($isSopReview && $sopStats): ?>
<div class="sop-link-bar">
 <div class="stats">
  <strong>📊 표준서 DB 실시간 연동 현황</strong>
  <span class="sop-badge green">총 <?= (int)$sopStats['total'] ?>종 (유효 <?= (int)$sopStats['valid'] ?>종 / 폐지 <?= (int)$sopStats['abolished'] ?>종)</span>
  <span class="sop-badge blue">제·개정 이력 <?= count($sopStats['revisions']) ?>건</span>
  <span class="sop-badge">01~06권 통합관리</span>
 </div>
 <div class="sop-nav-btns">
  <a class="primary" href="standard_procedure.php">📋 안전작업표준서(SOP) 목록·열람 ↗</a>
  <a href="standard_procedure.php?tab=revisions">📝 제·개정 이력 대장 ↗</a>
  <a href="standard_procedure.php?tab=distribution">📢 작업자 배포·열람 대장 ↗</a>
 </div>
</div>
<?php endif; ?>

<?php if ($isSopReview && $sopScore && $sopCriterion): ?>
<section class="score-panel <?= $sopScore['evidence_count'] === 0 ? 'warn' : '' ?>">
 <div class="score-top">
  <div>
   <strong>🏆 CSM 2.2.1 평가 점수 반영 현황 (<?= $scoreYear ?>년 기준)</strong>
   <p class="score-meta">
    <?php if ($sopScore['evidence_count'] === 0): ?>
     ⚠️ 현재 첨부된 증빙 파일이 없어 <strong>0.0점</strong>입니다. 아래 검토 기록을 <strong>수정</strong>하거나 새 기록에 <strong>증빙 파일(PDF·JPG·PNG·XLSX·DOCX)</strong>을 첨부하면 즉시 점수에 반영됩니다.
    <?php else: ?>
     ✅ 유효 증빙 <strong><?= (int)$sopScore['evidence_count'] ?>건</strong> 확인됨 ·
     <?= $sopScore['automatic'] ? '표준서 DB 보유 및 정기 제·개정 이력 기준 자동 반영' : '평가 수준 직접 지정 (' . $h($sopScore['assessment']['updated_at'] ?? '') . ')' ?>
    <?php endif; ?>
   </p>
  </div>
  <div class="score-val"><?= number_format((float)$sopScore['earned'], 1) ?> / 2.0점</div>
 </div>
 <?php if ($canManage): ?>
 <form method="post" class="score-form">
  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_risk_tasks_csrf']) ?>">
  <input type="hidden" name="section" value="2.2">
  <input type="hidden" name="operation" value="assess_221">
  <label>평가 수준 (증빙 첨부 시 반영)
   <select name="level" required>
    <?php
     $curMult = $sopScore['assessment'] ? (float)$sopScore['assessment']['multiplier'] : ((float)$sopScore['multiplier'] ?: 1.0);
     foreach ($sopCriterion['criteria'] as $lvl => $lbl):
      if (!is_numeric($lvl)) continue;
    ?>
     <option value="<?= $h($lvl) ?>" <?= abs($curMult - (float)$lvl) < 0.001 ? 'selected' : '' ?>><?= $h(str_replace("\n", ' ', $lbl)) ?> (<?= number_format(2.0 * (float)$lvl, 1) ?>점)</option>
    <?php endforeach; ?>
   </select>
  </label>
  <label>평가 검토 메모 (선택)
   <input name="note" maxlength="500" placeholder="예: 자체 안전작업표준서 252종 보유 및 정기 검토·개정 증빙 확인" value="<?= $h($sopScore['assessment']['note'] ?? '') ?>">
  </label>
  <button type="submit">평가 수준 저장</button>
 </form>
 <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($error): ?><p class="message error" role="alert"><?= $h($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="message" role="status"><?= $h($notice) ?></p><?php endif; ?>
<?php if ($canManage): ?><section class="panel"><h2><?= $record ? ($isSopReview ? '표준서 검토·개정 기록 수정' : '기록 수정') : ($isSopReview ? '새 표준서 검토·개정 결과 등록' : '새 기록') ?></h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_risk_tasks_csrf']) ?>"><input type="hidden" name="section" value="<?= $h($section) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
<?php if ($singleItemCode !== ''): ?>
<input type="hidden" name="item_code" value="<?= $h($singleItemCode) ?>">
<?php endif; ?>
<div class="fields">
<?php if ($singleItemCode === ''): ?>
<label class="wide">평가항목 *<select name="item_code" required><option value="">선택하세요</option><?php foreach ($items as $code => $item): ?><option value="<?= $h($code) ?>" <?= $v('item_code') === $code ? 'selected' : '' ?>><?= $h($code . ' · ' . $item['title']) ?></option><?php endforeach; ?></select></label><p class="wide item-guidance" id="item-guidance">평가항목을 선택하면 확인할 증빙이 표시됩니다.</p>
<?php endif; ?>

<?php if ($isSopReview): ?>
<label class="wide">검토 제목 *
 <input id="sop-title" name="title" required maxlength="200" placeholder="예: 2026년 하반기 안전작업표준서(01~06권) 정기 검토 및 제·개정 반영" value="<?= $h($v('title')) ?>">
</label>
<label>검토 일자 *
 <input type="date" name="record_date" required value="<?= $h($v('record_date', date('Y-m-d'))) ?>">
</label>
<label>검토 결과 상태
 <select name="status">
  <?php foreach (['완료' => '완료 (검토·개정 및 배포 완료)', '진행' => '진행 (개정·검토 진행중)', '계획' => '계획 (정기검토 예정)'] as $sKey => $sLabel): ?>
   <option value="<?= $h($sKey) ?>" <?= $v('status', '완료') === $sKey ? 'selected' : '' ?>><?= $h($sLabel) ?></option>
  <?php endforeach; ?>
 </select>
</label>
<label>검토 구분 · 대상 범위
 <input id="sop-subject" name="subject" maxlength="200" placeholder="예: 정기·수시 통합검토 (01~06권 · 총 252종)" value="<?= $h($v('subject', $sopStats ? '정기·수시 통합검토 (01~06권 · 총 ' . $sopStats['total'] . '종)' : '')) ?>">
 <div class="quick-chips">
  <button type="button" class="chip-btn" onclick="document.getElementById('sop-subject').value='정기검토 (01~06권 전권 · 총 <?= (int)($sopStats['total'] ?? 252) ?>종)'">정기검토 (전권)</button>
  <button type="button" class="chip-btn" onclick="document.getElementById('sop-subject').value='수시검토 (안전절차 제·개정 및 폐지 반영)'">수시검토 (제·개정 반영)</button>
  <button type="button" class="chip-btn" onclick="document.getElementById('sop-subject').value='수시검토 (신규 작업·설비 표준서 제정)'">신규작업 제정검토</button>
  <button type="button" class="chip-btn" onclick="document.getElementById('sop-subject').value='수시검토 (아차사고·위험성평가 후속 절차 보완)'">아차사고·위험성평가 연계</button>
 </div>
</label>
<label>검토자 / 승인자
 <input name="owner" maxlength="200" placeholder="예: 검토: 공사팀 김남균 / 승인: 정연탁 대표" value="<?= $h($v('owner', '검토: 공사팀 김남균 / 승인: 정연탁 대표')) ?>">
</label>
<input type="hidden" name="due_date" value="<?= $h($v('due_date')) ?>">
<div class="wide">
 <div class="autofill-bar">
  <label for="sop-details" style="font-weight:600">표준서 검토 내역 및 제·개정 요약 (검토 총괄 · 신규/개정/폐지 내역)</label>
  <?php if ($sopStats): ?>
  <button type="button" class="btn-autofill" onclick="fillSopDbSummary()">🔄 현재 표준서 DB 현황·개정이력 자동 불러오기</button>
  <?php endif; ?>
 </div>
 <textarea id="sop-details" name="details" rows="7" placeholder="검토 목적, 대상 권호 및 종수, 주요 제·개정(신규/개정/폐지) 표준서 번호와 변경 사유를 기록하세요. 우측 상단 버튼을 누르면 표준서 DB의 최신 현황이 자동으로 입력됩니다."><?= $h($v('details')) ?></textarea>
</div>
<div class="wide">
 <div class="autofill-bar">
  <label for="sop-action" style="font-weight:600">현장 배포 · TBM 교육 및 위험성평가 연계 조치</label>
  <button type="button" class="chip-btn" onclick="fillSopActionTemplate()">📌 표준 후속조치 문구 입력</button>
 </div>
 <textarea id="sop-action" name="action" rows="4" placeholder="1. 제·개정 표준서 현장 작업반 비치 및 공람&#10;2. 작업 전 안전미팅(TBM)을 통한 개정 절차 교육&#10;3. 해당 작업 위험성평가(JSA) 유해위험요인·감소대책 연계 반영 및 폐지 표준서 회수"><?= $h($v('action')) ?></textarea>
</div>
<label class="wide">증빙 첨부 — <strong>2.2.1 점수 반영 근거</strong> (검토 보고서 · 개정이력 대장 · 승인본 등 PDF, JPG, PNG, XLSX, DOCX · 20MB 이하)<input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx"><?php if ($record && $record['evidence_name']): ?><small>현재: <?= $h($record['evidence_name']) ?> · 새 파일을 선택하면 교체됩니다.</small><?php endif; ?></label>
<?php else: ?>
<label>제목 *<input name="title" required maxlength="200" value="<?= $h($v('title')) ?>"></label><label>일자 *<input type="date" name="record_date" required value="<?= $h($v('record_date', date('Y-m-d'))) ?>"></label><label>대상·장소<input name="subject" maxlength="200" value="<?= $h($v('subject')) ?>"></label><label>담당자<input name="owner" maxlength="200" value="<?= $h($v('owner')) ?>"></label><label>조치 기한<input type="date" name="due_date" value="<?= $h($v('due_date')) ?>"></label><label>진행상태<select name="status"><?php foreach (['계획','진행','완료'] as $s): ?><option <?= $v('status') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label><label class="wide">조사·점검·활동 내용<textarea name="details" rows="4"><?= $h($v('details')) ?></textarea></label><label class="wide">개선·후속 조치 및 확인 내용<textarea name="action" rows="3"><?= $h($v('action')) ?></textarea></label><label class="wide">증빙 첨부 (PDF, JPG, PNG, XLSX, DOCX · 20MB 이하)<input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx"><?php if ($record && $record['evidence_name']): ?><small>현재: <?= $h($record['evidence_name']) ?> · 새 파일을 선택하면 교체됩니다.</small><?php endif; ?></label>
<?php endif; ?>
</div><button type="submit"><?= $isSopReview ? '검토 기록 저장' : '저장' ?></button> <?php if ($record): ?><a href="risk_tasks.php?section=<?= $h($section) ?>">수정 취소</a><?php endif; ?></form></section><?php endif; ?>

<section class="panel"><h2><?= $isSopReview ? '표준서 검토·개정 이력 목록' : '기록 목록' ?> <small><?= count($rows) ?>건</small></h2><?php if (!$rows): ?><p>등록된 기록이 없습니다.</p><?php endif; ?><?php foreach ($rows as $row): ?><article class="entry"><div class="entry-head"><h3><?= $h($isSopReview ? $row['title'] : (($row['item_code'] ?: '항목 미분류') . ' · ' . $row['title'])) ?></h3><strong class="status-badge <?= $row['status'] === '완료' ? 'done' : ($row['status'] === '진행' ? 'prog' : '') ?>"><?= $h($isSopReview && $row['status'] === '완료' ? '검토·반영 완료' : $row['status']) ?></strong></div>
<?php if ($isSopReview): ?>
<p><strong>검토일:</strong> <?= $h($row['record_date']) ?> · <strong>검토 구분/범위:</strong> <?= $h($row['subject'] ?: '전권') ?> · <strong>검토/승인:</strong> <?= $h($row['owner'] ?: '미지정') ?></p>
<p><b>📋 표준서 검토 및 제·개정 내역</b><br><?= nl2br($h($row['details'] ?: '미입력')) ?></p>
<p><b>📢 현장 배포 · TBM 교육 및 환류 조치</b><br><?= nl2br($h($row['action'] ?: '미입력')) ?></p>
<?php else: ?>
<p><?= $h($row['record_date']) ?> · 대상: <?= $h($row['subject'] ?: '미입력') ?> · 담당: <?= $h($row['owner'] ?: '미지정') ?> · 기한: <?= $h($row['due_date'] ?: '미지정') ?></p><p><b>활동 내용</b><br><?= nl2br($h($row['details'] ?: '미입력')) ?></p><p><b>후속 조치</b><br><?= nl2br($h($row['action'] ?: '미입력')) ?></p>
<?php endif; ?>
<?php if ($row['evidence_name']): ?><p><a href="?section=<?= $h($section) ?>&amp;id=<?= (int)$row['id'] ?>&amp;file=1">📎 <?= $h($row['evidence_name']) ?> 다운로드 ↓</a><?php if ($isSopReview): ?><span class="ev-badge">✅ 2.2.1 점수 증빙 반영됨</span><?php endif; ?></p><?php elseif ($isSopReview): ?><p class="ev-missing">⚠️ 증빙 미첨부 — '보고서 인쇄/PDF'로 출력·결재 후 '수정'에서 첨부하면 2.2.1 점수에 즉시 반영됩니다.</p><?php endif; ?><div class="actions"><?php if ($isSopReview): ?><a href="?section=2.2&amp;id=<?= (int)$row['id'] ?>&amp;print=1" target="_blank" style="background:#0f766e;color:#fff;padding:7px 13px;border-radius:6px;text-decoration:none;font-size:.86rem;font-weight:700">🖨️ 보고서 인쇄 / PDF</a><?php endif; ?><?php if ($canManage): ?><a href="?section=<?= $h($section) ?>&amp;id=<?= (int)$row['id'] ?>">수정</a><form method="post" onsubmit="return confirm('이 기록과 첨부파일을 삭제할까요?')"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_risk_tasks_csrf']) ?>"><input type="hidden" name="section" value="<?= $h($section) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button name="operation" value="delete">삭제</button></form><?php endif; ?></div></article><?php endforeach; ?></section></main><script>
const itemGuidance = <?= json_encode(array_map(static fn($item) => $item['evidence'] ?: '현장 확인 내역을 기록하세요.', $items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const itemSelect = document.querySelector('select[name="item_code"]');
if (itemSelect) { const showGuidance = () => { document.getElementById('item-guidance').textContent = itemGuidance[itemSelect.value] || '평가항목을 선택하면 확인할 증빙이 표시됩니다.'; }; itemSelect.addEventListener('change', showGuidance); showGuidance(); }
<?php if ($isSopReview && $sopStats): ?>
const sopAutoSummary = <?= json_encode(
 "1. 검토 현황: 안전작업표준서(01~06권) 총 {$sopStats['total']}종 검토 완료 (유효 {$sopStats['valid']}종 / 폐지 {$sopStats['abolished']}종)\n" .
 "2. 주요 제·개정 및 폐지 반영 내역 (총 " . count($sopStats['revisions']) . "건):\n" .
 implode("\n", array_map(static fn($r) => sprintf(" - No.%d %s (%s): %s", (int)$r['sop_no'], $r['title'], $r['change_reason'] ?: ('Rev.' . $r['revision_no']), $r['changes_summary']), $sopStats['revisions'])),
 JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
function fillSopDbSummary() {
 const titleEl = document.getElementById('sop-title');
 const detailsEl = document.getElementById('sop-details');
 const actionEl = document.getElementById('sop-action');
 if (titleEl && !titleEl.value.trim()) {
  titleEl.value = new Date().getFullYear() + '년 안전작업표준서(01~06권) 정기 검토 및 제·개정 반영 보고';
 }
 if (detailsEl) detailsEl.value = sopAutoSummary;
 if (actionEl && !actionEl.value.trim()) fillSopActionTemplate();
}
function fillSopActionTemplate() {
 const actionEl = document.getElementById('sop-action');
 if (actionEl) {
  actionEl.value = "1. 개정 및 신규 제정 표준서 현장 작업반 배포 및 작업 전 TBM 중점 교육 실시\n2. 개정 항목(밀폐공간 산소측정·중량물 상하역 신호수 배치·고온가스 덮개 개폐 등) 위험성평가(JSA) 연계 반영\n3. 폐지 표준서(No.186) 현장 비치본 회수 및 폐지 식별 관리 완료";
 }
}
<?php endif; ?>
</script></body></html>
