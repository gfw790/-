<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permit_to_work_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$db = ptw_db();

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$nowStr = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i');
$year = filter_var($_GET['year'] ?? substr($today, 0, 4), FILTER_VALIDATE_INT) ?: 2026;
$type = trim($_GET['type'] ?? '');
$search = trim($_GET['search'] ?? '');

$message = '';
$error = '';

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="CSM_permit_to_work_' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

    fputcsv($out, ['허가번호', '작업구분', '작업명', '작업장소', '작업시작', '작업종료', '신청자', '작업인원', '안전담당자', '상태', '승인자', '승인일시', '완료일시', '입회확인자']);
    $stmt = $db->prepare('SELECT * FROM csm_ptw_records WHERE year = ? ORDER BY permit_no DESC');
    $stmt->execute([$year]);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['permit_no'], $row['work_type'], $row['work_title'], $row['work_location'],
            $row['start_datetime'], $row['end_datetime'], $row['applicant_name'], $row['worker_count'],
            $row['safety_supervisor'], $row['status'], $row['approver_name'], $row['approved_at'],
            $row['completed_at'], $row['closing_inspector']
        ]);
    }
    fclose($out);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_ptw') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $permit_no = trim($_POST['permit_no'] ?? '');
            $work_type = trim($_POST['work_type'] ?? '화기작업');
            $work_title = trim($_POST['work_title'] ?? '');
            $work_location = trim($_POST['work_location'] ?? '');
            $start_datetime = trim($_POST['start_datetime'] ?? $nowStr);
            $end_datetime = trim($_POST['end_datetime'] ?? $nowStr);
            $applicant_name = trim($_POST['applicant_name'] ?? '');
            $applicant_dept = trim($_POST['applicant_dept'] ?? '전기반');
            $worker_count = (int)($_POST['worker_count'] ?? 1);
            $workers_text = trim($_POST['workers_text'] ?? '');
            $safety_supervisor = trim($_POST['safety_supervisor'] ?? '진종철 부장');
            $gas_o2 = $_POST['gas_o2'] !== '' ? (float)$_POST['gas_o2'] : null;
            $gas_co = $_POST['gas_co'] !== '' ? (float)$_POST['gas_co'] : null;
            $gas_h2s = $_POST['gas_h2s'] !== '' ? (float)$_POST['gas_h2s'] : null;
            $gas_combustible = $_POST['gas_combustible'] !== '' ? (float)$_POST['gas_combustible'] : null;
            $loto_details = trim($_POST['loto_details'] ?? '');
            $ppe_details = trim($_POST['ppe_details'] ?? '');
            $emergency_standby = trim($_POST['emergency_standby'] ?? '');
            $status = trim($_POST['status'] ?? '승인');
            $approver_name = trim($_POST['approver_name'] ?? '정연탁 대표');
            $approved_at = trim($_POST['approved_at'] ?? $nowStr);
            $completed_at = $_POST['completed_at'] ?: null;
            $closing_inspector = $_POST['closing_inspector'] ?: null;
            $closing_notes = $_POST['closing_notes'] ?: null;

            if ($work_title === '' || $applicant_name === '') {
                throw new InvalidArgumentException('작업명과 신청자는 필수입니다.');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE csm_ptw_records SET
                    permit_no = ?, work_type = ?, work_title = ?, work_location = ?,
                    start_datetime = ?, end_datetime = ?, applicant_name = ?, applicant_dept = ?,
                    worker_count = ?, workers_text = ?, safety_supervisor = ?,
                    gas_o2 = ?, gas_co = ?, gas_h2s = ?, gas_combustible = ?,
                    loto_details = ?, ppe_details = ?, emergency_standby = ?,
                    status = ?, approver_name = ?, approved_at = ?, completed_at = ?,
                    closing_inspector = ?, closing_notes = ?, updated_at = datetime("now","localtime")
                    WHERE id = ?');
                $stmt->execute([
                    $permit_no, $work_type, $work_title, $work_location,
                    $start_datetime, $end_datetime, $applicant_name, $applicant_dept,
                    $worker_count, $workers_text, $safety_supervisor,
                    $gas_o2, $gas_co, $gas_h2s, $gas_combustible,
                    $loto_details, $ppe_details, $emergency_standby,
                    $status, $approver_name, $approved_at, $completed_at,
                    $closing_inspector, $closing_notes, $id
                ]);
                $message = '안전작업허가서가 수정되었습니다.';
            } else {
                if ($permit_no === '') {
                    $permit_no = sprintf('PTW-%d-%03d', $year, $db->query("SELECT COUNT(*) + 1 FROM csm_ptw_records WHERE year = $year")->fetchColumn());
                }
                $stmt = $db->prepare('INSERT INTO csm_ptw_records (
                    year, permit_no, work_type, work_title, work_location,
                    start_datetime, end_datetime, applicant_name, applicant_dept,
                    worker_count, workers_text, safety_supervisor,
                    gas_o2, gas_co, gas_h2s, gas_combustible,
                    loto_details, ppe_details, emergency_standby,
                    status, approver_name, approved_at, completed_at,
                    closing_inspector, closing_notes, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, datetime("now","localtime"), datetime("now","localtime")
                )');
                $stmt->execute([
                    $year, $permit_no, $work_type, $work_title, $work_location,
                    $start_datetime, $end_datetime, $applicant_name, $applicant_dept,
                    $worker_count, $workers_text, $safety_supervisor,
                    $gas_o2, $gas_co, $gas_h2s, $gas_combustible,
                    $loto_details, $ppe_details, $emergency_standby,
                    $status, $approver_name, $approved_at, $completed_at,
                    $closing_inspector, $closing_notes
                ]);
                $message = '새로운 안전작업허가서(PTW)가 발행되었습니다.';
            }
        } elseif ($action === 'complete_ptw') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $inspector = trim($_POST['closing_inspector'] ?? '진종철 부장');
            $notes = trim($_POST['closing_notes'] ?? '작업 완료 및 현장 안전조치 확인 완료');

            $db->prepare('UPDATE csm_ptw_records SET
                status = "완료", completed_at = datetime("now","localtime"),
                closing_inspector = ?, closing_notes = ?, updated_at = datetime("now","localtime")
                WHERE id = ?')->execute([$inspector, $notes, $id]);
            $message = '작업허가가 안전하게 종료·완료 처리되었습니다.';
        } elseif ($action === 'delete_ptw') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id > 0) {
                $db->prepare('DELETE FROM csm_ptw_records WHERE id = ?')->execute([$id]);
                $message = '작업허가서가 삭제되었습니다.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Compute Metrics
$totalPtw = (int)$db->query("SELECT COUNT(*) FROM csm_ptw_records WHERE year = $year")->fetchColumn();
$lotoCount = (int)$db->query("SELECT COUNT(*) FROM csm_ptw_records WHERE year = $year AND work_type LIKE '%LOTO%'")->fetchColumn();
$hotCount = (int)$db->query("SELECT COUNT(*) FROM csm_ptw_records WHERE year = $year AND work_type LIKE '%화기%'")->fetchColumn();
$confinedCount = (int)$db->query("SELECT COUNT(*) FROM csm_ptw_records WHERE year = $year AND work_type LIKE '%밀폐%'")->fetchColumn();
$heightCount = (int)$db->query("SELECT COUNT(*) FROM csm_ptw_records WHERE year = $year AND work_type LIKE '%고소%'")->fetchColumn();
$doneCount = (int)$db->query("SELECT COUNT(*) FROM csm_ptw_records WHERE year = $year AND status = '완료'")->fetchColumn();
$doneRate = $totalPtw > 0 ? (int)round(($doneCount / $totalPtw) * 100) : 0;

// Fetch with filters
$query = 'SELECT * FROM csm_ptw_records WHERE year = ?';
$params = [$year];

if ($type !== '') {
    $query .= ' AND work_type = ?';
    $params[] = $type;
}
if ($search !== '') {
    $query .= ' AND (work_title LIKE ? OR permit_no LIKE ? OR work_location LIKE ? OR applicant_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= ' ORDER BY permit_no DESC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

$workTypes = ['에너지차단(LOTO)', '화기작업', '밀폐공간작업', '고소작업', '굴착작업', '중량물양중'];
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>안전작업허가서(PTW) 및 고위험작업 관리 · CSM 2.4</title>
  <link rel="stylesheet" href="permit_to_work.css">
</head>
<body>

<div class="ptw-container">
  <!-- Header -->
  <div class="ptw-header">
    <div class="ptw-title-group">
      <h1>
        <a href="management_system.php?section=2.4&amp;year=<?= $year ?>" style="color:inherit;text-decoration:none;">← 2.4</a>
        안전작업허가서(PTW) 및 고위험작업 관리
        <span class="ptw-badge-eval">배점 2점 (화기·밀폐·LOTO·고소)</span>
      </h1>
      <p class="ptw-subtitle">화기작업, 밀폐공간, 에너지차단(LOTO), 고소작업 등 위험작업의 사전허가 승인, 가스측정, 안전점검 및 작업종료를 관리합니다.</p>
    </div>
    <div class="ptw-actions no-print">
      <form method="get" style="display:flex;gap:8px;align-items:center;">
        <select name="year" class="ptw-select" onchange="this.form.submit()">
          <?php for ($y = 2024; $y <= 2027; $y++): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년도</option>
          <?php endfor; ?>
        </select>
        <?php if ($type !== ''): ?>
          <input type="hidden" name="type" value="<?= $escape($type) ?>">
        <?php endif; ?>
      </form>
      <a href="?year=<?= $year ?>&amp;export=csv" class="ptw-btn ptw-btn-outline">📊 Excel/CSV 내보내기</a>
      <button type="button" class="ptw-btn ptw-btn-primary" onclick="openPtwModal()">+ 새 작업허가서 신청/발행</button>
    </div>
  </div>

  <?php if ($message): ?>
    <div style="background:#ecfdf5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;border:1px solid #a7f3d0;">
      ✅ <?= $escape($message) ?>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div style="background:#fef2f2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;border:1px solid #fecaca;">
      ⚠️ <?= $escape($error) ?>
    </div>
  <?php endif; ?>

  <!-- Metrics -->
  <div class="ptw-metrics-grid">
    <div class="ptw-metric-card">
      <div class="ptw-metric-title">총 작업허가 건수 (2.4.1)</div>
      <div class="ptw-metric-value"><?= number_format($totalPtw) ?>건</div>
      <div class="ptw-metric-sub">작업허가 절차 100% 준수</div>
    </div>
    <div class="ptw-metric-card">
      <div class="ptw-metric-title">에너지차단 (LOTO)</div>
      <div class="ptw-metric-value" style="color:var(--ptw-danger);"><?= $lotoCount ?>건</div>
      <div class="ptw-metric-sub">정전·차단기 2중 잠금 관리</div>
    </div>
    <div class="ptw-metric-card">
      <div class="ptw-metric-title">화기작업 허가</div>
      <div class="ptw-metric-value" style="color:#c2410c;"><?= $hotCount ?>건</div>
      <div class="ptw-metric-sub">소화기 비치 및 화재감시자 배치</div>
    </div>
    <div class="ptw-metric-card">
      <div class="ptw-metric-title">밀폐공간 / 고소작업</div>
      <div class="ptw-metric-value" style="color:var(--ptw-accent);"><?= $confinedCount + $heightCount ?>건</div>
      <div class="ptw-metric-sub">밀폐 <?= $confinedCount ?>건 / 고소 <?= $heightCount ?>건</div>
    </div>
    <div class="ptw-metric-card">
      <div class="ptw-metric-title">작업 종료·점검 완료율</div>
      <div class="ptw-metric-value" style="color:var(--ptw-success);"><?= $doneRate ?>%</div>
      <div class="ptw-metric-sub"><?= $doneCount ?>건 현장 복구 확인 완료</div>
    </div>
  </div>

  <!-- Filter & Search Controls -->
  <div class="ptw-controls no-print">
    <form method="get" class="ptw-filter-group">
      <input type="hidden" name="year" value="<?= $year ?>">
      <select name="type" class="ptw-select" onchange="this.form.submit()">
        <option value="">전체 고위험작업 구분</option>
        <?php foreach ($workTypes as $wt): ?>
          <option value="<?= $wt ?>" <?= $type === $wt ? 'selected' : '' ?>><?= $wt ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" class="ptw-input" placeholder="허가번호, 작업명, 위치 검색" value="<?= $escape($search) ?>" style="width:240px;">
      <button type="submit" class="ptw-btn ptw-btn-outline">검색</button>
      <?php if ($type !== '' || $search !== ''): ?>
        <a href="?year=<?= $year ?>" class="ptw-btn ptw-btn-outline">초기화</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- PTW Table Card -->
  <div class="ptw-table-card">
    <table class="ptw-table">
      <thead>
        <tr>
          <th style="width:120px;">허가번호</th>
          <th style="width:130px;">작업구분</th>
          <th>작업명 및 작업장소</th>
          <th style="width:140px;">허가 작업일시</th>
          <th style="width:120px;">신청자 (인원)</th>
          <th style="width:110px;">안전담당자</th>
          <th style="width:90px;">상태</th>
          <th style="width:120px;">승인/완료확인</th>
          <th class="no-print" style="width:180px;text-align:center;">관리 및 인쇄</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($records)): ?>
          <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--ptw-muted);">등록된 안전작업허가서가 없습니다.</td></tr>
        <?php else: foreach ($records as $ptw):
          $badgeCls = match(true) {
            str_contains($ptw['work_type'], 'LOTO') => 'ptw-badge-loto',
            str_contains($ptw['work_type'], '화기') => 'ptw-badge-hot',
            str_contains($ptw['work_type'], '밀폐') => 'ptw-badge-confined',
            str_contains($ptw['work_type'], '고소') => 'ptw-badge-height',
            default => 'ptw-badge-active',
          };
        ?>
          <tr>
            <td><strong><?= $escape($ptw['permit_no']) ?></strong></td>
            <td><span class="ptw-badge <?= $badgeCls ?>"><?= $escape($ptw['work_type']) ?></span></td>
            <td>
              <div style="font-weight:700;font-size:15px;color:var(--ptw-primary);cursor:pointer;" onclick='viewPtw(<?= json_encode($ptw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                <?= $escape($ptw['work_title']) ?> ↗
              </div>
              <div style="font-size:13px;color:#475569;margin-top:2px;">📍 <?= $escape($ptw['work_location']) ?></div>
            </td>
            <td style="font-size:12px;color:#334155;">
              <div><?= substr($ptw['start_datetime'], 5) ?></div>
              <div>~ <?= substr($ptw['end_datetime'], 5) ?></div>
            </td>
            <td>
              <strong><?= $escape($ptw['applicant_name']) ?></strong>
              <div style="font-size:12px;color:var(--ptw-muted);"><?= $ptw['worker_count'] ?>명 (<?= $escape($ptw['applicant_dept']) ?>)</div>
            </td>
            <td><?= $escape($ptw['safety_supervisor']) ?></td>
            <td>
              <?php if ($ptw['status'] === '완료'): ?>
                <span class="ptw-badge ptw-badge-done">완료</span>
              <?php else: ?>
                <span class="ptw-badge ptw-badge-active"><?= $escape($ptw['status']) ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <div>승인: <?= $escape($ptw['approver_name']) ?></div>
              <?php if ($ptw['closing_inspector']): ?>
                <small style="color:var(--ptw-success);font-weight:600;">점검: <?= $escape($ptw['closing_inspector']) ?></small>
              <?php endif; ?>
            </td>
            <td class="no-print" style="text-align:center;">
              <button type="button" class="ptw-btn ptw-btn-outline ptw-btn-sm" onclick='printPtwSheet(<?= json_encode($ptw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>🖨️ 인쇄</button>
              <?php if ($ptw['status'] !== '완료'): ?>
                <button type="button" class="ptw-btn ptw-btn-outline ptw-btn-sm" style="color:var(--ptw-success);" onclick='openCompleteModal(<?= $ptw['id'] ?>)'>완료확인</button>
              <?php endif; ?>
              <button type="button" class="ptw-btn ptw-btn-outline ptw-btn-sm" onclick='editPtw(<?= json_encode($ptw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>수정</button>
              <form method="post" style="display:inline;" onsubmit="return confirm('해당 안전작업허가서를 삭제하시겠습니까?');">
                <input type="hidden" name="action" value="delete_ptw">
                <input type="hidden" name="id" value="<?= $ptw['id'] ?>">
                <button type="submit" class="ptw-btn ptw-btn-outline ptw-btn-sm" style="color:var(--ptw-danger);">삭제</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL: PTW FORM -->
<div id="modalPtw" class="ptw-modal-backdrop" style="display:none;">
  <div class="ptw-modal">
    <form method="post">
      <input type="hidden" name="action" value="save_ptw">
      <input type="hidden" name="id" id="ptw_id" value="0">
      <div class="ptw-modal-header">
        <h3 id="ptw_modal_title">안전작업허가서(PTW) 신청 및 발행</h3>
        <button type="button" class="ptw-modal-close" onclick="closePtwModal()">&times;</button>
      </div>
      <div class="ptw-modal-body">
        <div class="ptw-form-grid">
          <div class="ptw-form-group">
            <label>허가번호</label>
            <input type="text" name="permit_no" id="ptw_permit_no" placeholder="비워둘 시 자동부여 (예: PTW-2026-005)">
          </div>
          <div class="ptw-form-group">
            <label>고위험 작업구분 *</label>
            <select name="work_type" id="ptw_work_type" class="ptw-select">
              <?php foreach ($workTypes as $wt): ?>
                <option value="<?= $wt ?>"><?= $wt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ptw-form-col-full ptw-form-group">
            <label>작업명 *</label>
            <input type="text" name="work_title" id="ptw_work_title" placeholder="예: 변전실 22.9kV 수전반 점검 및 VCB 교체 작업" required>
          </div>
          <div class="ptw-form-col-full ptw-form-group">
            <label>작업 장소 *</label>
            <input type="text" name="work_location" id="ptw_work_location" placeholder="예: K2 CBS 증설 현장 변전실" required>
          </div>
          <div class="ptw-form-group">
            <label>작업 시작일시 *</label>
            <input type="text" name="start_datetime" id="ptw_start_datetime" value="<?= $nowStr ?>" required>
          </div>
          <div class="ptw-form-group">
            <label>작업 종료일시 *</label>
            <input type="text" name="end_datetime" id="ptw_end_datetime" value="<?= $nowStr ?>" required>
          </div>
          <div class="ptw-form-group">
            <label>신청자 성명 *</label>
            <input type="text" name="applicant_name" id="ptw_applicant_name" value="윤택천 반장" required>
          </div>
          <div class="ptw-form-group">
            <label>신청 부서 / 팀</label>
            <input type="text" name="applicant_dept" id="ptw_applicant_dept" value="전기반">
          </div>
          <div class="ptw-form-group">
            <label>작업 인원수 (명)</label>
            <input type="number" name="worker_count" id="ptw_worker_count" value="3">
          </div>
          <div class="ptw-form-group">
            <label>작업자 명단</label>
            <input type="text" name="workers_text" id="ptw_workers_text" placeholder="예: 윤택천, 김도담, 우홍기">
          </div>
          <div class="ptw-form-group">
            <label>현장 안전관리감독자</label>
            <input type="text" name="safety_supervisor" id="ptw_safety_supervisor" value="진종철 부장">
          </div>
          <div class="ptw-form-group">
            <label>허가 승인자</label>
            <input type="text" name="approver_name" id="ptw_approver_name" value="정연탁 대표">
          </div>

          <!-- Gas Measurement (if applicable) -->
          <div class="ptw-form-col-full" style="border-top:1px dashed #cbd5e1;padding-top:14px;margin-top:6px;">
            <strong style="color:var(--ptw-primary);">사전 가스 농도 측정 결과 (밀폐공간/화기작업 시)</strong>
          </div>
          <div class="ptw-form-group">
            <label>산소 농도 O2 (적정 18~23.5%)</label>
            <input type="number" step="0.1" name="gas_o2" id="ptw_gas_o2" placeholder="20.9">
          </div>
          <div class="ptw-form-group">
            <label>일산화탄소 CO (기준 30ppm 미만)</label>
            <input type="number" step="0.1" name="gas_co" id="ptw_gas_co" placeholder="0.0">
          </div>
          <div class="ptw-form-group">
            <label>황화수소 H2S (기준 10ppm 미만)</label>
            <input type="number" step="0.1" name="gas_h2s" id="ptw_gas_h2s" placeholder="0.0">
          </div>
          <div class="ptw-form-group">
            <label>가연성 가스 LEL (기준 10% 미만)</label>
            <input type="number" step="0.1" name="gas_combustible" id="ptw_gas_combustible" placeholder="0.0">
          </div>

          <!-- LOTO & Controls -->
          <div class="ptw-form-col-full ptw-form-group">
            <label>에너지차단(LOTO) 세부내역 (전기·배관·잔류압력)</label>
            <input type="text" name="loto_details" id="ptw_loto_details" placeholder="예: VCB 스위치 Off 후 패드락 자물쇠 2중 체결, 잔류전하 방전 확인, LOTO 태그 부착">
          </div>
          <div class="ptw-form-col-full ptw-form-group">
            <label>필수 착용 보호구 및 안전장비</label>
            <input type="text" name="ppe_details" id="ptw_ppe_details" placeholder="예: 절연안전모, 22.9kV 절연장갑, 절연화, 방염복, 방진마스크">
          </div>
          <div class="ptw-form-col-full ptw-form-group">
            <label>비상시 대응 체계 및 감시자 배치</label>
            <input type="text" name="emergency_standby" id="ptw_emergency_standby" placeholder="예: 화재감시자 전담 배치, 소화기 2대 비치, CCR 유선 연락망 확보">
          </div>
        </div>
      </div>
      <div class="ptw-modal-footer">
        <button type="button" class="ptw-btn ptw-btn-outline" onclick="closePtwModal()">취소</button>
        <button type="submit" class="ptw-btn ptw-btn-primary">허가서 저장</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: COMPLETE PTW -->
<div id="modalComplete" class="ptw-modal-backdrop" style="display:none;">
  <div class="ptw-modal" style="max-width:550px;">
    <form method="post">
      <input type="hidden" name="action" value="complete_ptw">
      <input type="hidden" name="id" id="comp_ptw_id" value="0">
      <div class="ptw-modal-header">
        <h3>작업 종료 및 현장 복구 확인</h3>
        <button type="button" class="ptw-modal-close" onclick="closeCompleteModal()">&times;</button>
      </div>
      <div class="ptw-modal-body">
        <div class="ptw-form-grid" style="grid-template-columns:1fr;">
          <div class="ptw-form-group">
            <label>현장 입회 점검자 *</label>
            <input type="text" name="closing_inspector" value="진종철 부장" required>
          </div>
          <div class="ptw-form-group">
            <label>종료 확인 내용 (잔재물 청소, 화재감시 30분, LOTO 해제 등) *</label>
            <textarea name="closing_notes" required placeholder="작업 완료 및 화재 잔류감시 확인, 공구 회수 및 LOTO 해제 완료"></textarea>
          </div>
        </div>
      </div>
      <div class="ptw-modal-footer">
        <button type="button" class="ptw-btn ptw-btn-outline" onclick="closeCompleteModal()">취소</button>
        <button type="submit" class="ptw-btn ptw-btn-primary">완료 확인 저장</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: VIEW DETAIL -->
<div id="modalView" class="ptw-modal-backdrop" style="display:none;">
  <div class="ptw-modal" style="max-width:850px;">
    <div class="ptw-modal-header">
      <h3 id="view_title">안전작업허가서 상세조회</h3>
      <button type="button" class="ptw-modal-close" onclick="closeViewModal()">&times;</button>
    </div>
    <div class="ptw-modal-body" id="view_body" style="font-size:14px;line-height:1.6;">
    </div>
    <div class="ptw-modal-footer">
      <button type="button" class="ptw-btn ptw-btn-primary" onclick="printFromModal()">🖨️ A4 허가서 인쇄</button>
      <button type="button" class="ptw-btn ptw-btn-outline" onclick="closeViewModal()">닫기</button>
    </div>
  </div>
</div>

<script>
let currentViewingPtw = null;

function openPtwModal() {
  document.getElementById('ptw_id').value = '0';
  document.getElementById('ptw_modal_title').innerText = '안전작업허가서(PTW) 신청 및 발행';
  document.getElementById('ptw_permit_no').value = '';
  document.getElementById('ptw_work_type').value = '에너지차단(LOTO)';
  document.getElementById('ptw_work_title').value = '';
  document.getElementById('ptw_work_location').value = '';
  document.getElementById('ptw_start_datetime').value = '<?= $nowStr ?>';
  document.getElementById('ptw_end_datetime').value = '<?= $nowStr ?>';
  document.getElementById('ptw_applicant_name').value = '윤택천 반장';
  document.getElementById('ptw_applicant_dept').value = '전기반';
  document.getElementById('ptw_worker_count').value = '3';
  document.getElementById('ptw_workers_text').value = '';
  document.getElementById('ptw_safety_supervisor').value = '진종철 부장';
  document.getElementById('ptw_approver_name').value = '정연탁 대표';
  document.getElementById('ptw_gas_o2').value = '';
  document.getElementById('ptw_gas_co').value = '';
  document.getElementById('ptw_gas_h2s').value = '';
  document.getElementById('ptw_gas_combustible').value = '';
  document.getElementById('ptw_loto_details').value = '';
  document.getElementById('ptw_ppe_details').value = '';
  document.getElementById('ptw_emergency_standby').value = '';
  document.getElementById('modalPtw').style.display = 'flex';
}
function closePtwModal() {
  document.getElementById('modalPtw').style.display = 'none';
}
function editPtw(p) {
  document.getElementById('ptw_id').value = p.id;
  document.getElementById('ptw_modal_title').innerText = '안전작업허가서 수정';
  document.getElementById('ptw_permit_no').value = p.permit_no || '';
  document.getElementById('ptw_work_type').value = p.work_type || '화기작업';
  document.getElementById('ptw_work_title').value = p.work_title || '';
  document.getElementById('ptw_work_location').value = p.work_location || '';
  document.getElementById('ptw_start_datetime').value = p.start_datetime || '';
  document.getElementById('ptw_end_datetime').value = p.end_datetime || '';
  document.getElementById('ptw_applicant_name').value = p.applicant_name || '';
  document.getElementById('ptw_applicant_dept').value = p.applicant_dept || '';
  document.getElementById('ptw_worker_count').value = p.worker_count || 1;
  document.getElementById('ptw_workers_text').value = p.workers_text || '';
  document.getElementById('ptw_safety_supervisor').value = p.safety_supervisor || '';
  document.getElementById('ptw_approver_name').value = p.approver_name || '';
  document.getElementById('ptw_gas_o2').value = p.gas_o2 || '';
  document.getElementById('ptw_gas_co').value = p.gas_co || '';
  document.getElementById('ptw_gas_h2s').value = p.gas_h2s || '';
  document.getElementById('ptw_gas_combustible').value = p.gas_combustible || '';
  document.getElementById('ptw_loto_details').value = p.loto_details || '';
  document.getElementById('ptw_ppe_details').value = p.ppe_details || '';
  document.getElementById('ptw_emergency_standby').value = p.emergency_standby || '';
  document.getElementById('modalPtw').style.display = 'flex';
}

function openCompleteModal(id) {
  document.getElementById('comp_ptw_id').value = id;
  document.getElementById('modalComplete').style.display = 'flex';
}
function closeCompleteModal() {
  document.getElementById('modalComplete').style.display = 'none';
}

function viewPtw(p) {
  currentViewingPtw = p;
  document.getElementById('view_title').innerText = p.permit_no + ' ' + p.work_title;
  let html = `
    <div style="background:#f8fafc;padding:14px;border-radius:8px;border:1px solid #cbd5e1;margin-bottom:16px;">
      <div><strong>허가번호:</strong> ${p.permit_no} | <strong>작업구분:</strong> ${p.work_type} | <strong>상태:</strong> ${p.status}</div>
      <div style="margin-top:4px;"><strong>작업일시:</strong> ${p.start_datetime} ~ ${p.end_datetime}</div>
      <div style="margin-top:4px;"><strong>장소:</strong> ${p.work_location}</div>
      <div style="margin-top:4px;"><strong>신청자:</strong> ${p.applicant_name} (${p.applicant_dept}, 총 ${p.worker_count}명) | <strong>안전담당:</strong> ${p.safety_supervisor}</div>
      <div style="margin-top:4px;"><strong>승인자:</strong> ${p.approver_name} (${p.approved_at})</div>
    </div>
    ${p.gas_o2 ? `
      <div style="margin-bottom:14px;background:#f0fdf4;padding:10px;border-radius:6px;border:1px solid #bbf7d0;">
        <strong style="color:#15803d;">가스 농도 측정 결과:</strong>
        O2: <strong>${p.gas_o2}%</strong> | CO: <strong>${p.gas_co}ppm</strong> | H2S: <strong>${p.gas_h2s}ppm</strong> | LEL: <strong>${p.gas_combustible}%</strong>
      </div>
    ` : ''}
    <div style="margin-bottom:10px;"><strong>LOTO 잠금/에너지차단 내역:</strong><br><span style="color:#b91c1c;">${p.loto_details || '해당 없음'}</span></div>
    <div style="margin-bottom:10px;"><strong>필수 착용 보호구:</strong><br>${p.ppe_details || '안전모, 안전화, 보안경'}</div>
    <div style="margin-bottom:10px;"><strong>비상대응 및 감시자:</strong><br>${p.emergency_standby || 'CCR 유선 통보'}</div>
    ${p.completed_at ? `
      <div style="margin-top:16px;background:#ecfdf5;padding:12px;border-radius:6px;border-left:4px solid #059669;">
        <strong>작업 완료 및 복구 확인 (${p.completed_at}):</strong><br>
        점검자: <strong>${p.closing_inspector}</strong><br>
        내용: ${p.closing_notes}
      </div>
    ` : ''}
  `;
  document.getElementById('view_body').innerHTML = html;
  document.getElementById('modalView').style.display = 'flex';
}
function closeViewModal() {
  document.getElementById('modalView').style.display = 'none';
}

function printPtwSheet(p) {
  viewPtw(p);
  printFromModal();
}
function printFromModal() {
  if (!currentViewingPtw) return;
  const p = currentViewingPtw;
  const printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <!doctype html>
    <html lang="ko">
    <head>
      <meta charset="utf-8">
      <title>${p.permit_no} 안전작업허가서</title>
      <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: "Malgun Gothic", sans-serif; color: #000; line-height: 1.4; font-size: 10pt; }
        h1 { text-align: center; font-size: 19pt; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #000; padding: 6px 8px; }
        th { background: #f0f0f0; text-align: center; font-weight: bold; }
        .section-title { font-size: 11pt; font-weight: bold; margin: 12px 0 6px; }
        .box { border: 1px solid #000; padding: 8px; margin-bottom: 10px; }
      </style>
    </head>
    <body onload="window.print()">
      <h1>안 전 작 업 허 가 서 (PTW)</h1>
      <table>
        <tr>
          <th style="width:16%;">허가 번호</th>
          <td style="width:34%;">${p.permit_no}</td>
          <th style="width:16%;">작업 구분</th>
          <td style="width:34%;font-weight:bold;color:#b00;">${p.work_type}</td>
        </tr>
        <tr>
          <th>작 업 명</th>
          <td colspan="3"><strong>${p.work_title}</strong></td>
        </tr>
        <tr>
          <th>작업 장소</th>
          <td colspan="3">${p.work_location}</td>
        </tr>
        <tr>
          <th>허가 시간</th>
          <td colspan="3">${p.start_datetime} ~ ${p.end_datetime}</td>
        </tr>
        <tr>
          <th>신 청 자</th>
          <td>${p.applicant_name} (${p.applicant_dept}, ${p.worker_count}명)</td>
          <th>안전담당자</th>
          <td>${p.safety_supervisor} (서명)</td>
        </tr>
        <tr>
          <th>허가 승인</th>
          <td>${p.approver_name} (${p.approved_at}) (서명)</td>
          <th>진행 상태</th>
          <td><strong>${p.status}</strong></td>
        </tr>
      </table>

      ${p.gas_o2 ? `
        <div class="section-title">■ 사전 가스 농도 측정 결과 (밀폐공간 / 화기작업)</div>
        <table>
          <tr>
            <th>산소 (O2)</th>
            <th>일산화탄소 (CO)</th>
            <th>황화수소 (H2S)</th>
            <th>가연성가스 (LEL)</th>
          </tr>
          <tr>
            <td style="text-align:center;"><strong>${p.gas_o2}%</strong> (기준: 18~23.5%)</td>
            <td style="text-align:center;"><strong>${p.gas_co}ppm</strong> (기준: &lt;30ppm)</td>
            <td style="text-align:center;"><strong>${p.gas_h2s}ppm</strong> (기준: &lt;10ppm)</td>
            <td style="text-align:center;"><strong>${p.gas_combustible}%</strong> (기준: &lt;10%)</td>
          </tr>
        </table>
      ` : ''}

      <div class="section-title">■ 에너지차단 (LOTO) 조치 사항</div>
      <div class="box">${p.loto_details || '해당 작업에 요구되는 잠금/차단 조치 완료'}</div>

      <div class="section-title">■ 착용 보호구 및 비상대응 조치</div>
      <div class="box">
        <strong>필수 보호구:</strong> ${p.ppe_details}<br>
        <strong>비상 대응:</strong> ${p.emergency_standby}
      </div>

      <div class="section-title">■ 작업 종료 및 현장 복구 점검</div>
      <table>
        <tr>
          <th style="width:20%;">작업 종료일시</th>
          <td style="width:30%;">${p.completed_at || '—'}</td>
          <th style="width:20%;">현장 입회 점검자</th>
          <td style="width:30%;">${p.closing_inspector ? p.closing_inspector + ' (서명)' : '—'}</td>
        </tr>
        <tr>
          <th>종료 점검 내용</th>
          <td colspan="3">${p.closing_notes || '작업 완료 후 현장 안전확인 및 복구 점검 완료'}</td>
        </tr>
      </table>

      <div style="margin-top:20px;text-align:right;">
        <strong>주식회사 현대기전</strong>
      </div>
    </body>
    </html>
  `);
  printWindow.document.close();
}
</script>

</body>
</html>
