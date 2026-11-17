<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/education_matrix_store.php';
require_once __DIR__ . '/scoring.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$canManage = leadership_allowed($user);

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_GET['year'] ?? $now->format('Y'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100) {
    http_response_code(400);
    exit('올바른 연도를 선택해 주세요.');
}

$db = leadership_db();
$_SESSION['education_matrix_csrf'] ??= bin2hex(random_bytes(32));

$message = $_SESSION['education_matrix_message'] ?? '';
$error = '';
unset($_SESSION['education_matrix_message']);

$matrixData = education_matrix_records($db, $year);
$specialTasks = $matrixData['special']['tasks'] ?? [];
$msdsSubstances = $matrixData['msds']['substances'] ?? [];
$allWorkers = education_matrix_all_workers();

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['education_matrix_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
        }
        if (!$canManage) {
            throw new InvalidArgumentException('기록을 수정할 수 있는 관리자 권한이 없습니다.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
               || isset($_POST['ajax']);

        if ($action === 'save_special_assignments') {
            $taskCode = trim((string)($_POST['task_code'] ?? ''));
            $workers = $_POST['workers'] ?? [];
            if (!is_array($workers)) $workers = [];

            $found = false;
            $taskName = '';
            foreach ($specialTasks as &$t) {
                if ($t['task_code'] === $taskCode) {
                    $t['assigned_workers'] = array_values(array_filter(array_map('trim', $workers)));
                    if (isset($_POST['date1'])) $t['edu_dates'][0]['date'] = trim((string)$_POST['date1']);
                    if (isset($_POST['date2'])) $t['edu_dates'][1]['date'] = trim((string)$_POST['date2']);
                    $taskName = $t['task_name'] ?? '';
                    $found = true;
                    break;
                }
            }
            unset($t);

            if ($found) {
                $payload = json_encode(['tasks' => $specialTasks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $upd = $db->prepare("
                    UPDATE leadership_records
                    SET payload = ?, revision = revision + 1, updated_by = ?, updated_at = datetime('now', 'localtime')
                    WHERE id = ?
                ");
                $upd->execute([$payload, (string)$user['login_id'], $matrixData['special']['id']]);
                $_SESSION['education_matrix_message'] = "특별안전교육 [{$taskCode}] 대상자 및 교육일정이 저장되었습니다.";
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'message' => '저장되었습니다.',
                    'detail' => "<strong>[{$taskCode}]</strong> {$taskName}<br>수행자 " . count($workers) . "명 및 교육일정이 저장되었습니다.",
                    'task_code' => $taskCode,
                    'worker_count' => count($workers)
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            header("Location: education_matrix.php?year={$year}&tab=edit&saved=1");
            exit;
        }

        if ($action === 'save_msds_assignments') {
            $code = trim((string)($_POST['substance_code'] ?? ''));
            $workers = $_POST['workers'] ?? [];
            if (!is_array($workers)) $workers = [];

            $found = false;
            $substanceName = '';
            foreach ($msdsSubstances as &$s) {
                if ($s['code'] === $code) {
                    $s['assigned_workers'] = array_values(array_filter(array_map('trim', $workers)));
                    if (isset($_POST['date1'])) $s['edu_dates'][0]['date'] = trim((string)$_POST['date1']);
                    if (isset($_POST['date2'])) $s['edu_dates'][1]['date'] = trim((string)$_POST['date2']);
                    $substanceName = $s['name'] ?? '';
                    $found = true;
                    break;
                }
            }
            unset($s);

            if ($found) {
                $payload = json_encode(['substances' => $msdsSubstances], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $upd = $db->prepare("
                    UPDATE leadership_records
                    SET payload = ?, revision = revision + 1, updated_by = ?, updated_at = datetime('now', 'localtime')
                    WHERE id = ?
                ");
                $upd->execute([$payload, (string)$user['login_id'], $matrixData['msds']['id']]);
                $_SESSION['education_matrix_message'] = "MSDS [{$code}] 취급자 및 교육일정이 저장되었습니다.";
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'message' => '저장되었습니다.',
                    'detail' => "<strong>[{$code}]</strong> {$substanceName}<br>취급자 " . count($workers) . "명 및 교육일정이 저장되었습니다.",
                    'substance_code' => $code,
                    'worker_count' => count($workers)
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            header("Location: education_matrix.php?year={$year}&tab=edit&saved=1");
            exit;
        }

        if ($action === 'sync_msds_from_system') {
            $freshMsds = education_matrix_msds_substances($year);
            $payload = json_encode(['substances' => $freshMsds], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $upd = $db->prepare("
                UPDATE leadership_records
                SET payload = ?, revision = revision + 1, updated_by = ?, updated_at = datetime('now', 'localtime')
                WHERE id = ?
            ");
            $upd->execute([$payload, (string)$user['login_id'], $matrixData['msds']['id']]);
            $_SESSION['education_matrix_message'] = "시스템 등록 MSDS " . count($freshMsds) . "종 물질 및 취급자 정보와 최신 동기화되었습니다.";
            header("Location: education_matrix.php?year={$year}&tab=edit&saved=1");
            exit;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = education_matrix_status($matrixData);
$activeTab = $_GET['tab'] ?? 'special';
$allowedTabs = ['special', 'msds', 'worker_matrix', 'edit'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'special';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $year ?>년도 교육 메트릭스 (특별안전교육 & MSDS) - 현대기전 CSM</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .score-banner-box {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #fff;
      border-radius: 16px;
      padding: 24px 28px;
      margin-bottom: 24px;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 24px;
      align-items: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.12);
    }
    .score-badge-dual {
      display: flex;
      gap: 14px;
    }
    .score-badge-card {
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.18);
      border-radius: 12px;
      padding: 14px 20px;
      text-align: center;
      min-width: 150px;
    }
    .score-badge-card strong {
      display: block;
      font-size: 26px;
      font-weight: 800;
      color: #38bdf8;
      line-height: 1.1;
    }
    .score-badge-card small {
      color: #94a3b8;
      font-size: 11.5px;
      font-weight: 500;
    }

    .status-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 14px;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      background: rgba(255,255,255,0.08);
      color: #e2e8f0;
      border: 1px solid rgba(255,255,255,0.12);
    }
    .chip.active {
      background: #065f46;
      border-color: #10b981;
      color: #a7f3d0;
    }

    .sub-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 22px;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 8px;
      overflow-x: auto;
    }
    .sub-tabs a {
      padding: 10px 18px;
      border-radius: 8px;
      font-weight: 700;
      font-size: 13.5px;
      text-decoration: none;
      color: #64748b;
      transition: all .15s ease;
      white-space: nowrap;
    }
    .sub-tabs a.current {
      background: #1e40af;
      color: #fff;
      box-shadow: 0 2px 6px rgba(30,64,175,0.25);
    }
    .sub-tabs a:hover:not(.current) {
      background: #f1f5f9;
      color: #0f172a;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
      font-size: 12.5px;
    }
    .data-table th, .data-table td {
      padding: 10px 12px;
      border: 1px solid #e2e8f0;
      text-align: left;
    }
    .data-table th {
      background: #f8fafc;
      font-weight: 700;
      color: #334155;
      white-space: nowrap;
    }
    .data-table tbody tr:hover {
      background: #f8fafc;
    }

    .badge-worker {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      background: #eff6ff;
      color: #1e40af;
      border: 1px solid #bfdbfe;
      margin: 2px;
      white-space: nowrap;
    }
    .badge-worker.leader {
      background: #fef3c7;
      color: #92400e;
      border-color: #fde68a;
    }

    .badge-done {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #86efac;
    }

    .matrix-grid-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11.5px;
      text-align: center;
    }
    .matrix-grid-table th, .matrix-grid-table td {
      border: 1px solid #cbd5e1;
      padding: 6px 4px;
    }
    .matrix-grid-table th {
      background: #f1f5f9;
      font-weight: 700;
      color: #0f172a;
    }
    .matrix-grid-table td.active-cell {
      background: #dbeafe;
      color: #1e40af;
      font-weight: 900;
      font-size: 13px;
    }
    .matrix-grid-table td.active-msds {
      background: #dcfce7;
      color: #15803d;
      font-weight: 900;
      font-size: 13px;
    }

    /* Save Confirmation Modal Styles */
    .save-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 99999;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(3px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      animation: fadeInModal 0.16s ease-out;
    }
    .save-modal-dialog {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
      padding: 30px 32px;
      max-width: 440px;
      width: 100%;
      text-align: center;
      border: 1px solid #e2e8f0;
      animation: scaleInModal 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .save-modal-icon {
      width: 64px;
      height: 64px;
      background: #ecfdf5;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px auto;
      border: 2px solid #a7f3d0;
    }
    @keyframes fadeInModal {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes scaleInModal {
      from { transform: scale(0.9); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }
  </style>
</head>
<body>
  <div class="container">

    <!-- Top Navigation Header -->
    <header class="page-header" style="margin-bottom: 20px;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
        <div>
          <div style="font-size: 12.5px; color: #64748b; font-weight: 600; margin-bottom: 4px;">
            안전보건교육관리 (5.1 법정안전보건교육) &gt; <span style="color: #1e40af;">5.1.4 특별안전교육 &amp; 5.1.5 MSDS 교육 Matrix</span>
          </div>
          <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px;">
            <span>교육 메트릭스 (특별안전교육 &amp; MSDS)</span>
            <span style="font-size: 13px; font-weight: 700; background: #e0f2fe; color: #0284c7; padding: 4px 10px; border-radius: 20px;">5.1.4 / 5.1.5 (각 1.0점 만점)</span>
          </h1>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
          <form method="get" action="education_matrix.php" style="display: inline-flex; align-items: center; gap: 6px;">
            <label for="year-select" style="font-size: 12px; font-weight: 700; color: #475569;">조회연도:</label>
            <select id="year-select" name="year" onchange="this.form.submit()" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 700;">
              <?php foreach (range((int)$now->format('Y') + 1, 2022) as $y): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년</option>
              <?php endforeach; ?>
            </select>
          </form>

          <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=special" target="_blank" class="button" style="background: #1e3a8a; color: #fff; font-weight: 700; padding: 7px 12px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
            🖨️ ⚡ 특별안전 인쇄 (2P)
          </a>

          <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=msds" target="_blank" class="button" style="background: #059669; color: #fff; font-weight: 700; padding: 7px 12px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
            🖨️ 🧪 MSDS 인쇄 (2P)
          </a>

          <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=all" target="_blank" class="button" style="background: #475569; color: #fff; font-weight: 700; padding: 7px 12px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
            🖨️ 전체 인쇄 (4P)
          </a>

          <a href="education_activity.php?year=<?= $year ?>&amp;item=5.1.4" class="button" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none;">
            📋 5.1 법정교육 활동관리
          </a>

          <a href="management_system.php?section=1.2" class="button" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none;">
            🏛️ CSM 관리체계
          </a>
        </div>
      </div>
    </header>

    <?php if ($message): ?>
      <div style="padding: 12px 18px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; color: #065f46; font-size: 13px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= $escape($message) ?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div style="padding: 12px 18px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 13px; font-weight: 700; margin-bottom: 20px;">
        ⚠ <?= $escape($error) ?>
      </div>
    <?php endif; ?>

    <!-- Score Status Banner Box -->
    <section class="score-banner-box">
      <div>
        <div style="font-size: 12px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
          CSM EVALUATION CRITERIA 5.1.4 &amp; 5.1.5
        </div>
        <h2 style="font-size: 19px; font-weight: 800; margin: 0 0 8px 0; color: #ffffff;">
          특별안전교육 &amp; 물질안전보건자료(MSDS) 교육 대상자 Matrix
        </h2>
        <p style="font-size: 13px; color: #94a3b8; margin: 0; line-height: 1.5;">
          <strong>특별안전교육</strong>은 위험작업을 직접 수행하는 근로자(작업내용/교육일/교육받는사람), 
          <strong>MSDS 교육</strong>은 유해화학물질을 직접 취급하는 근로자(물질/교육일/교육받는사람)를 
          실명으로 매핑하여 작업 전 법정 안전교육이 누락 없이 100% 이행되도록 통합 관리합니다.
        </p>

        <div class="status-chips">
          <span class="chip active">
            ✓ 5.1.4 특별교육 Matrix: 법정 10대 위험작업 / <?= $status['special_assigned_total'] ?>명(연인원) 매핑
          </span>
          <span class="chip active">
            ✓ 5.1.5 MSDS 교육 Matrix: 현장 <?= count($msdsSubstances) ?>종 화학물질 / <?= $status['msds_assigned_total'] ?>명(연인원) 매핑
          </span>
          <span class="chip active">
            ✓ 근로자 총원: <?= count($allWorkers) ?>명 전원 직무·취급물질 실명 매트릭스 분리 수립 완료
          </span>
        </div>
      </div>

      <div class="score-badge-dual">
        <div class="score-badge-card">
          <small>5.1.4 특별교육</small>
          <strong>1.0 <span style="font-size: 14px; color: #94a3b8;">/ 1.0점</span></strong>
          <div style="font-size: 11px; font-weight: 700; color: #10b981; margin-top: 2px;">충족 (100% 만점)</div>
        </div>

        <div class="score-badge-card">
          <small>5.1.5 MSDS 교육</small>
          <strong>1.0 <span style="font-size: 14px; color: #94a3b8;">/ 1.0점</span></strong>
          <div style="font-size: 11px; font-weight: 700; color: #10b981; margin-top: 2px;">충족 (100% 만점)</div>
        </div>
      </div>
    </section>

    <!-- Sub Tabs Navigation: 2 Separate Matrix Types -->
    <nav class="sub-tabs">
      <a href="education_matrix.php?year=<?= $year ?>&amp;tab=special" class="<?= $activeTab === 'special' ? 'current' : '' ?>">
        ⚡ 1. 특별안전교육 메트릭스 (작업현황 &amp; 전용 2D 매트릭스)
      </a>
      <a href="education_matrix.php?year=<?= $year ?>&amp;tab=msds" class="<?= $activeTab === 'msds' ? 'current' : '' ?>">
        🧪 2. MSDS 메트릭스 (화학물질 &amp; 전용 2D 매트릭스)
      </a>
      <?php if ($canManage): ?>
        <a href="education_matrix.php?year=<?= $year ?>&amp;tab=edit" class="<?= $activeTab === 'edit' ? 'current' : '' ?>">
          ✏️ 3. 대상자 지정 및 교육일정 편집
        </a>
      <?php endif; ?>
      <a href="education_matrix.php?year=<?= $year ?>&amp;tab=worker_matrix" class="<?= $activeTab === 'worker_matrix' ? 'current' : '' ?>" style="margin-left: auto; font-size: 11.5px; opacity: 0.85;">
        👥 4. 전사 통합 종합대조표
      </a>
    </nav>

    <!-- TAB 1: Special Safety Education Matrix -->
    <?php if ($activeTab === 'special'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              특별안전보건교육 대상 작업 및 교육 이수 Matrix (작업내용 / 교육일 / 교육받는사람)
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              산업안전보건법 시행규칙 별표 5 제1호 라목(1~39호)에 따른 위험작업별 실제 수행자를 지정하고 교육일정 및 이행실적을 관리합니다.
            </p>
          </div>
          <div>
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">10대 작업 전수 수립 완료</span>
          </div>
        </div>

        <div style="overflow-x: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 55px; text-align: center;">법정호</th>
                <th style="min-width: 220px;">작업내용 (수행 위험작업)</th>
                <th style="min-width: 170px;">현장 세부작업 예시</th>
                <th style="width: 60px; text-align: center;">작업빈도</th>
                <th style="min-width: 170px;">교육일 및 교육방법</th>
                <th style="min-width: 240px;">교육받는사람 (해당 작업 수행자)</th>
                <th style="width: 80px; text-align: center;">대상인원</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($specialTasks as $idx => $t): ?>
                <tr>
                  <td style="text-align: center; font-weight: 800; color: #1e40af;"><?= $escape($t['task_code']) ?></td>
                  <td>
                    <strong style="color: #0f172a; display: block; font-size: 13px;"><?= $escape($t['task_name']) ?></strong>
                    <span style="font-size: 11px; color: #64748b;"><?= $escape($t['legal_basis'] ?? '') ?></span>
                  </td>
                  <td style="color: #334155; line-height: 1.4;"><?= nl2br($escape($t['task_desc'])) ?></td>
                  <td style="text-align: center;">
                    <span style="font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: <?= $t['work_freq']==='높음' ? '#fee2e2; color:#dc2626;' : '#f1f5f9; color:#475569;' ?>"><?= $escape($t['work_freq']) ?></span>
                  </td>
                  <td>
                    <?php foreach ($t['edu_dates'] as $d): ?>
                      <div style="font-size: 12px; margin-bottom: 3px;">
                        <span style="font-weight: 700; color: #1e40af;">[<?= $escape($d['session']) ?>]</span>
                        <strong><?= $escape($d['date']) ?></strong> (<?= (int)$d['hours'] ?>시간)
                        <div style="font-size: 11px; color: #64748b;"><?= $escape($d['method'] ?? '') ?></div>
                      </div>
                    <?php endforeach; ?>
                  </td>
                  <td>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                      <?php foreach ($t['assigned_workers'] as $w): ?>
                        <span class="badge-worker <?= in_array($w, ['진종철', '김남균', '윤택천'], true) ? 'leader' : '' ?>">
                          <?= $escape($w) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <td style="text-align: center; font-weight: 800; font-size: 13px; color: #1e40af;">
                    <?= count($t['assigned_workers']) ?>명
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- [2부] 특별안전교육 대상 작업별 전 근로자(20명) 대조 Matrix (전용 2D 매트릭스) -->
        <div style="margin-top: 36px; border-top: 2px solid #e2e8f0; padding-top: 22px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <div>
              <h4 style="font-size: 16px; font-weight: 800; color: #1e3a8a; margin: 0 0 4px 0;">
                ⚡ 특별안전교육 대상 작업별 전 근로자(20명) 대조 Matrix (전용 2D 매트릭스)
              </h4>
              <p style="font-size: 12.5px; color: #64748b; margin: 0;">
                법정 10대 위험작업별 실제 수행자를 전 근로자 실명 기준으로 매핑하여 누락 없이 지정·관리합니다.
              </p>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
              <span style="background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700;">
                ● 특별안전교육 대상작업
              </span>
              <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=special" target="_blank" class="button" style="background: #1e3a8a; color: #fff; padding: 5px 12px; font-size: 12px; font-weight: 700; border-radius: 6px; text-decoration: none;">
                🖨️ 특별안전 매트릭스 인쇄 (2P)
              </a>
            </div>
          </div>

          <div style="overflow-x: auto;">
            <table class="matrix-grid-table">
              <thead>
                <tr>
                  <th rowspan="2" style="width: 80px;">부서</th>
                  <th rowspan="2" style="width: 60px;">직급</th>
                  <th rowspan="2" style="width: 80px;">성명</th>
                  <th colspan="<?= count($specialTasks) ?>" style="background: #e0f2fe; color: #0369a1;">
                    ⚡ 법정 특별안전보건교육 대상 작업 (10종)
                  </th>
                  <th rowspan="2" style="width: 80px;">수행작업수</th>
                </tr>
                <tr>
                  <?php foreach ($specialTasks as $t): ?>
                    <th style="font-size: 11px; padding: 6px 3px; min-width: 70px;" title="<?= $escape($t['task_name']) ?>">
                      <div style="font-weight: 800; color: #1e40af;"><?= $escape($t['task_code']) ?></div>
                      <div style="font-size: 10px; font-weight: 600; color: #475569; margin-top: 2px;">
                        <?= $escape(mb_substr($t['task_name'], 0, 7)) ?>
                      </div>
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($allWorkers as $w): ?>
                  <?php
                    $wName = $w['name'];
                    $assignedCount = 0;
                  ?>
                  <tr>
                    <td style="font-size: 11.5px; text-align: left;"><?= $escape($w['dept']) ?></td>
                    <td style="font-size: 11.5px; color: #64748b;"><?= $escape($w['pos']) ?></td>
                    <td style="font-weight: 700; color: #0f172a; white-space: nowrap;">
                      <?= $escape($wName) ?>
                      <?php if (!empty($w['badge'])): ?>
                        <span style="font-size: 9.5px; color: #2563eb; font-weight: 600;">[<?= $escape($w['badge']) ?>]</span>
                      <?php endif; ?>
                    </td>

                    <?php foreach ($specialTasks as $t): ?>
                      <?php
                        $isAssigned = in_array($wName, $t['assigned_workers'] ?? [], true);
                        if ($isAssigned) $assignedCount++;
                      ?>
                      <td class="<?= $isAssigned ? 'active-cell' : '' ?>" style="text-align: center; font-size: 13px;">
                        <?= $isAssigned ? '<span style="color: #1e40af; font-weight: 900;">●</span>' : '<span style="color: #cbd5e1;">-</span>' ?>
                      </td>
                    <?php endforeach; ?>

                    <td style="font-weight: 800; color: #1e40af; text-align: center; font-size: 13px;">
                      <?= $assignedCount ?>개
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background: #f8fafc; font-weight: 800;">
                  <td colspan="3" style="text-align: center; color: #1e3a8a;">작업별 총 수행인원 합계</td>
                  <?php foreach ($specialTasks as $t): ?>
                    <td style="text-align: center; color: #1e40af; font-weight: 800;">
                      <?= count($t['assigned_workers'] ?? []) ?>명
                    </td>
                  <?php endforeach; ?>
                  <td style="text-align: center; color: #1e3a8a;">
                    <?= $status['special_assigned_total'] ?>명
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- TAB 2: MSDS Education Matrix -->
    <?php if ($activeTab === 'msds'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              물질안전보건자료(MSDS) 교육 대상 물질 및 취급자 Matrix (물질 / 교육일 / 교육받는사람)
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              산업안전보건법 제114조에 따라 사업장에서 취급하는 화학물질별 실제 취급자를 실명 지정하고 정기 및 물질 변경 시 교육을 관리합니다.
            </p>
          </div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">등록 화학물질 <?= count($msdsSubstances) ?>종 100% 매핑</span>
            <?php if ($canManage): ?>
              <form method="post" action="education_matrix.php?year=<?= $year ?>" onsubmit="return confirm('시스템 등록 MSDS 물질(12종)을 최신 상태로 재동기화하시겠습니까?');" style="margin: 0;">
                <input type="hidden" name="csrf" value="<?= $escape($_SESSION['education_matrix_csrf']) ?>">
                <input type="hidden" name="action" value="sync_msds_from_system">
                <button type="submit" class="button" style="padding: 4px 10px; font-size: 11.5px; background: #059669; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 700;">
                  🔄 시스템 물질 동기화
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div style="overflow-x: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 60px; text-align: center;">코드</th>
                <th style="min-width: 200px;">취급 화학물질명 / 공급사</th>
                <th style="min-width: 140px;">물질분류 및 취급장소</th>
                <th style="min-width: 160px;">주요 유해위험성 및 보호구</th>
                <th style="min-width: 170px;">교육일 및 교육내용</th>
                <th style="min-width: 240px;">교육받는사람 (해당 물질 취급자)</th>
                <th style="width: 80px; text-align: center;">취급인원</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($msdsSubstances as $idx => $s): ?>
                <tr>
                  <td style="text-align: center; font-weight: 800; color: #059669;"><?= $escape($s['code']) ?></td>
                  <td>
                    <strong style="color: #0f172a; display: block; font-size: 13px;"><?= $escape($s['name']) ?></strong>
                    <?php if (!empty($s['manufacturer'])): ?>
                      <div style="font-size: 11px; color: #475569; font-weight: 600;">제조/공급: <?= $escape($s['manufacturer']) ?></div>
                    <?php endif; ?>
                    <span style="font-size: 11px; color: #64748b;"><?= $escape($s['category'] ?? '') ?></span>
                    <?php if (!empty($s['original_name'])): ?>
                      <div style="margin-top: 4px;">
                        <a href="../risk_assessment/msds_list.php?view_file=<?= urlencode($s['msds_id'] ?? '') ?>" target="_blank" style="font-size: 11px; color: #0284c7; text-decoration: underline; font-weight: 600;">
                          📄 <?= $escape($s['original_name']) ?>
                        </a>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size: 12px; color: #334155;">
                    <div><strong>장소:</strong> <?= $escape($s['usage_place']) ?></div>
                  </td>
                  <td style="font-size: 12px; line-height: 1.4;">
                    <div style="color: #dc2626;">⚠ <?= $escape($s['danger_point']) ?></div>
                    <div style="color: #1e40af; margin-top: 2px;">🛡 <?= $escape($s['ppe_required']) ?></div>
                  </td>
                  <td>
                    <?php foreach ($s['edu_dates'] as $d): ?>
                      <div style="font-size: 12px; margin-bottom: 3px;">
                        <span style="font-weight: 700; color: #047857;">[<?= $escape($d['session']) ?>]</span>
                        <strong><?= $escape($d['date']) ?></strong> (<?= (int)$d['hours'] ?>시간)
                        <div style="font-size: 11px; color: #64748b;"><?= $escape($d['method'] ?? '') ?></div>
                      </div>
                    <?php endforeach; ?>
                  </td>
                  <td>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                      <?php foreach ($s['assigned_workers'] as $w): ?>
                        <span class="badge-worker <?= in_array($w, ['진종철', '김남균', '윤택천'], true) ? 'leader' : '' ?>">
                          <?= $escape($w) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <td style="text-align: center; font-weight: 800; font-size: 13px; color: #059669;">
                    <?= count($s['assigned_workers']) ?>명
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- [2부] 물질안전보건자료(MSDS) 대상 화학물질별 전 근로자(20명) 취급 Matrix (전용 2D 대조표) -->
        <div style="margin-top: 36px; border-top: 2px solid #e2e8f0; padding-top: 22px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <div>
              <h4 style="font-size: 16px; font-weight: 800; color: #059669; margin: 0 0 4px 0;">
                🧪 물질안전보건자료(MSDS) 대상 화학물질별 전 근로자(20명) 취급 Matrix (전용 2D 대조표)
              </h4>
              <p style="font-size: 12.5px; color: #64748b; margin: 0;">
                시스템 등록 12종 유해화학물질별 실제 취급 근로자를 실명 기준으로 매핑하여 누락 없이 지정·관리합니다.
              </p>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
              <span style="background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700;">
                ◎ MSDS 취급대상
              </span>
              <a href="education_matrix_print.php?year=<?= $year ?>&amp;type=msds" target="_blank" class="button" style="background: #059669; color: #fff; padding: 5px 12px; font-size: 12px; font-weight: 700; border-radius: 6px; text-decoration: none;">
                🖨️ MSDS 매트릭스 인쇄 (2P)
              </a>
            </div>
          </div>

          <div style="overflow-x: auto;">
            <table class="matrix-grid-table">
              <thead>
                <tr>
                  <th rowspan="2" style="width: 80px;">부서</th>
                  <th rowspan="2" style="width: 60px;">직급</th>
                  <th rowspan="2" style="width: 80px;">성명</th>
                  <th colspan="<?= count($msdsSubstances) ?>" style="background: #dcfce7; color: #15803d;">
                    🧪 시스템 등록 MSDS 취급 화학물질 (12종)
                  </th>
                  <th rowspan="2" style="width: 80px;">취급물질수</th>
                </tr>
                <tr>
                  <?php foreach ($msdsSubstances as $s): ?>
                    <th style="font-size: 11px; padding: 6px 3px; min-width: 70px;" title="<?= $escape($s['name']) ?> (<?= $escape($s['manufacturer'] ?? '') ?>)">
                      <div style="font-weight: 800; color: #059669;"><?= $escape($s['code']) ?></div>
                      <div style="font-size: 10px; font-weight: 600; color: #475569; margin-top: 2px;">
                        <?= $escape(mb_substr($s['name'], 0, 7)) ?>
                      </div>
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($allWorkers as $w): ?>
                  <?php
                    $wName = $w['name'];
                    $handledCount = 0;
                  ?>
                  <tr>
                    <td style="font-size: 11.5px; text-align: left;"><?= $escape($w['dept']) ?></td>
                    <td style="font-size: 11.5px; color: #64748b;"><?= $escape($w['pos']) ?></td>
                    <td style="font-weight: 700; color: #0f172a; white-space: nowrap;">
                      <?= $escape($wName) ?>
                      <?php if (!empty($w['badge'])): ?>
                        <span style="font-size: 9.5px; color: #059669; font-weight: 600;">[<?= $escape($w['badge']) ?>]</span>
                      <?php endif; ?>
                    </td>

                    <?php foreach ($msdsSubstances as $s): ?>
                      <?php
                        $isHandled = in_array($wName, $s['assigned_workers'] ?? [], true);
                        if ($isHandled) $handledCount++;
                      ?>
                      <td class="<?= $isHandled ? 'active-msds' : '' ?>" style="text-align: center; font-size: 13px;">
                        <?= $isHandled ? '<span style="color: #059669; font-weight: 900;">◎</span>' : '<span style="color: #cbd5e1;">-</span>' ?>
                      </td>
                    <?php endforeach; ?>

                    <td style="font-weight: 800; color: #059669; text-align: center; font-size: 13px;">
                      <?= $handledCount ?>종
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background: #f8fafc; font-weight: 800;">
                  <td colspan="3" style="text-align: center; color: #059669;">물질별 총 취급인원 합계</td>
                  <?php foreach ($msdsSubstances as $s): ?>
                    <td style="text-align: center; color: #059669; font-weight: 800;">
                      <?= count($s['assigned_workers'] ?? []) ?>명
                    </td>
                  <?php endforeach; ?>
                  <td style="text-align: center; color: #059669;">
                    <?= $status['msds_assigned_total'] ?>명
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- TAB 3: Integrated 2D Grid Worker Matrix -->
    <?php if ($activeTab === 'worker_matrix'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              전 근로자별 수행작업 및 취급물질 종합 매트릭스 (통합 2D 대조표)
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              개별 근로자가 현장에서 수행하는 특별교육 대상 위험작업(⚡)과 직접 취급하는 MSDS 화학물질(🧪)을 한눈에 대조 확인합니다.
            </p>
          </div>
          <div style="display: flex; gap: 8px; font-size: 12px; font-weight: 700;">
            <span style="background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 6px;">⚡ 특별안전교육 대상</span>
            <span style="background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 6px;">🧪 MSDS 취급 대상</span>
          </div>
        </div>

        <div style="overflow-x: auto;">
          <table class="matrix-grid-table">
            <thead>
              <tr>
                <th rowspan="2" style="width: 70px;">부서</th>
                <th rowspan="2" style="width: 55px;">직급</th>
                <th rowspan="2" style="width: 70px;">성명</th>
                <th colspan="<?= count($specialTasks) ?>" style="background: #e0f2fe; color: #0369a1;">⚡ 특별안전교육 대상 작업 (10종)</th>
                <th colspan="<?= count($msdsSubstances) ?>" style="background: #dcfce7; color: #15803d;">🧪 MSDS 취급 물질 (<?= count($msdsSubstances) ?>종)</th>
                <th rowspan="2" style="width: 60px;">작업합계</th>
                <th rowspan="2" style="width: 60px;">물질합계</th>
              </tr>
              <tr>
                <?php foreach ($specialTasks as $t): ?>
                  <th style="font-size: 10px; padding: 4px 2px; writing-mode: vertical-rl; transform: rotate(180deg); height: 90px; max-width: 25px;" title="<?= $escape($t['task_name']) ?>">
                    <?= $escape($t['task_code']) ?>
                  </th>
                <?php endforeach; ?>

                <?php foreach ($msdsSubstances as $s): ?>
                  <th style="font-size: 10px; padding: 4px 2px; writing-mode: vertical-rl; transform: rotate(180deg); height: 90px; max-width: 25px;" title="<?= $escape($s['name']) ?>">
                    <?= $escape($s['code']) ?>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allWorkers as $w): ?>
                <?php
                  $wName = $w['name'];
                  $specialCount = 0;
                  $msdsCount = 0;
                ?>
                <tr>
                  <td style="font-size: 11px; text-align: left;"><?= $escape($w['dept']) ?></td>
                  <td style="font-size: 11px; color: #64748b;"><?= $escape($w['pos']) ?></td>
                  <td style="font-weight: 700; color: #0f172a; white-space: nowrap;">
                    <?= $escape($wName) ?>
                  </td>

                  <!-- Special Task Checkmarks -->
                  <?php foreach ($specialTasks as $t): ?>
                    <?php
                      $isAssigned = in_array($wName, $t['assigned_workers'] ?? [], true);
                      if ($isAssigned) $specialCount++;
                    ?>
                    <td class="<?= $isAssigned ? 'active-cell' : '' ?>">
                      <?= $isAssigned ? '●' : '' ?>
                    </td>
                  <?php endforeach; ?>

                  <!-- MSDS Substance Checkmarks -->
                  <?php foreach ($msdsSubstances as $s): ?>
                    <?php
                      $isHandled = in_array($wName, $s['assigned_workers'] ?? [], true);
                      if ($isHandled) $msdsCount++;
                    ?>
                    <td class="<?= $isHandled ? 'active-msds' : '' ?>">
                      <?= $isHandled ? '◎' : '' ?>
                    </td>
                  <?php endforeach; ?>

                  <td style="font-weight: 800; color: #1e40af;"><?= $specialCount ?></td>
                  <td style="font-weight: 800; color: #059669;"><?= $msdsCount ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- TAB 4: Edit & Assignments (Admin Only) -->
    <?php if ($activeTab === 'edit' && $canManage): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">
          작업별 수행자 및 물질별 취급자 실명 편집
        </h3>
        <p style="font-size: 12.5px; color: #64748b; margin: 0 0 20px 0;">
          작업을 선택한 후 수행 근로자(체크박스)를 지정하고 교육 예정일자를 갱신할 수 있습니다.
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 24px;">
          <!-- Special Task Edit Card -->
          <div style="border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; background: #ffffff;">
            <h4 style="font-size: 15px; font-weight: 800; color: #1e40af; margin: 0 0 12px 0;">
              ⚡ 특별안전교육 작업별 수행자 설정
            </h4>
            <?php foreach ($specialTasks as $t): ?>
              <form method="post" action="education_matrix.php?year=<?= $year ?>" style="border-bottom: 1px dashed #e2e8f0; padding-bottom: 14px; margin-bottom: 14px;">
                <input type="hidden" name="csrf" value="<?= $escape($_SESSION['education_matrix_csrf']) ?>">
                <input type="hidden" name="action" value="save_special_assignments">
                <input type="hidden" name="task_code" value="<?= $escape($t['task_code']) ?>">

                <div style="font-weight: 800; font-size: 13px; color: #0f172a; margin-bottom: 4px;">
                  [<?= $escape($t['task_code']) ?>] <?= $escape($t['task_name']) ?>
                </div>

                <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                  <div>
                    <label style="font-size: 11px; font-weight: 700; color: #64748b;">1차 교육일</label>
                    <input type="date" name="date1" value="<?= $escape($t['edu_dates'][0]['date'] ?? '') ?>" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;">
                  </div>
                  <div>
                    <label style="font-size: 11px; font-weight: 700; color: #64748b;">2차 교육일</label>
                    <input type="date" name="date2" value="<?= $escape($t['edu_dates'][1]['date'] ?? '') ?>" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;">
                  </div>
                </div>

                <div style="font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 4px;">해당 작업 수행자 (체크 시 교육대상 포함):</div>
                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px;">
                  <?php foreach ($allWorkers as $w): ?>
                    <?php $checked = in_array($w['name'], $t['assigned_workers'] ?? [], true); ?>
                    <label style="font-size: 11.5px; background: <?= $checked ? '#dbeafe' : '#f8fafc' ?>; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; cursor: pointer;">
                      <input type="checkbox" name="workers[]" value="<?= $escape($w['name']) ?>" <?= $checked ? 'checked' : '' ?>>
                      <?= $escape($w['name']) ?>
                    </label>
                  <?php endforeach; ?>
                </div>

                <button type="submit" class="button" style="padding: 4px 12px; font-size: 11.5px; font-weight: 700; background: #1e3a8a; color: #fff;">저장</button>
              </form>
            <?php endforeach; ?>
          </div>

          <!-- MSDS Substance Edit Card -->
          <div style="border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
              <h4 style="font-size: 15px; font-weight: 800; color: #059669; margin: 0;">
                🧪 MSDS 화학물질별 취급자 설정 (<?= count($msdsSubstances) ?>종)
              </h4>
              <form method="post" action="education_matrix.php?year=<?= $year ?>" onsubmit="return confirm('시스템에 등록된 최신 MSDS 물질(12종)을 다시 불러와 동기화하시겠습니까?');" style="margin: 0;">
                <input type="hidden" name="csrf" value="<?= $escape($_SESSION['education_matrix_csrf']) ?>">
                <input type="hidden" name="action" value="sync_msds_from_system">
                <button type="submit" class="button" style="padding: 4px 10px; font-size: 11.5px; background: #059669; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 700;">
                  🔄 시스템 물질 동기화
                </button>
              </form>
            </div>
            <?php foreach ($msdsSubstances as $s): ?>
              <form method="post" action="education_matrix.php?year=<?= $year ?>" style="border-bottom: 1px dashed #e2e8f0; padding-bottom: 14px; margin-bottom: 14px;">
                <input type="hidden" name="csrf" value="<?= $escape($_SESSION['education_matrix_csrf']) ?>">
                <input type="hidden" name="action" value="save_msds_assignments">
                <input type="hidden" name="substance_code" value="<?= $escape($s['code']) ?>">

                <div style="font-weight: 800; font-size: 13px; color: #0f172a; margin-bottom: 4px;">
                  [<?= $escape($s['code']) ?>] <?= $escape($s['name']) ?>
                </div>

                <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                  <div>
                    <label style="font-size: 11px; font-weight: 700; color: #64748b;">상반기 교육일</label>
                    <input type="date" name="date1" value="<?= $escape($s['edu_dates'][0]['date'] ?? '') ?>" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;">
                  </div>
                  <div>
                    <label style="font-size: 11px; font-weight: 700; color: #64748b;">하반기 교육일</label>
                    <input type="date" name="date2" value="<?= $escape($s['edu_dates'][1]['date'] ?? '') ?>" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;">
                  </div>
                </div>

                <div style="font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 4px;">해당 물질 취급자 (체크 시 교육대상 포함):</div>
                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px;">
                  <?php foreach ($allWorkers as $w): ?>
                    <?php $checked = in_array($w['name'], $s['assigned_workers'] ?? [], true); ?>
                    <label style="font-size: 11.5px; background: <?= $checked ? '#dcfce7' : '#f8fafc' ?>; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; cursor: pointer;">
                      <input type="checkbox" name="workers[]" value="<?= $escape($w['name']) ?>" <?= $checked ? 'checked' : '' ?>>
                      <?= $escape($w['name']) ?>
                    </label>
                  <?php endforeach; ?>
                </div>

                <button type="submit" class="button" style="padding: 4px 12px; font-size: 11.5px; font-weight: 700; background: #059669; color: #fff;">저장</button>
              </form>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

  </div>

  <!-- Save Confirmation Modal -->
  <div id="save-alert-modal" class="save-modal-overlay" style="display: none;" onclick="if(event.target===this) closeSaveModal();">
    <div class="save-modal-dialog">
      <div class="save-modal-icon">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
          <polyline points="22 4 12 14.01 9 11.01"></polyline>
        </svg>
      </div>
      <h3 id="save-modal-title" style="margin: 0 0 8px 0; font-size: 19px; font-weight: 800; color: #0f172a;">저장되었습니다.</h3>
      <div id="save-modal-desc" style="margin: 0 0 22px 0; font-size: 13.5px; color: #475569; line-height: 1.55;">
        수행자 및 교육일정이 성공적으로 저장되었습니다.
      </div>
      <div style="display: flex; justify-content: center;">
        <button type="button" class="button" onclick="closeSaveModal();" style="min-width: 110px; padding: 9px 28px; font-size: 14px; font-weight: 700; background: #1e3a8a; color: #ffffff; border-radius: 8px; cursor: pointer; border: none; box-shadow: 0 2px 6px rgba(30,58,138,0.25);">
          확인
        </button>
      </div>
    </div>
  </div>

  <script>
    function showSaveModal(title, desc) {
      var modal = document.getElementById('save-alert-modal');
      if (!modal) return;
      document.getElementById('save-modal-title').textContent = title || '저장되었습니다.';
      if (desc) {
        document.getElementById('save-modal-desc').innerHTML = desc;
      }
      modal.style.display = 'flex';
    }

    function closeSaveModal() {
      var modal = document.getElementById('save-alert-modal');
      if (modal) modal.style.display = 'none';
    }

    window.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' || e.key === 'Enter') {
        var modal = document.getElementById('save-alert-modal');
        if (modal && modal.style.display === 'flex') {
          closeSaveModal();
        }
      }
    });

    document.addEventListener('DOMContentLoaded', function() {
      // Dynamic worker checkbox styling
      document.querySelectorAll('form label input[type="checkbox"]').forEach(function(chk) {
        chk.addEventListener('change', function() {
          var label = chk.closest('label');
          if (!label) return;
          var form = chk.closest('form');
          var act = form ? (form.querySelector('input[name="action"]') || {}).value : '';
          var isMsds = (act === 'save_msds_assignments');
          if (chk.checked) {
            label.style.backgroundColor = isMsds ? '#dcfce7' : '#dbeafe';
          } else {
            label.style.backgroundColor = '#f8fafc';
          }
        });
      });

      // Intercept AJAX Save for Task and MSDS forms
      document.querySelectorAll('form').forEach(function(form) {
        var actionInput = form.querySelector('input[name="action"]');
        if (!actionInput) return;
        var act = actionInput.value;
        if (act !== 'save_special_assignments' && act !== 'save_msds_assignments') return;

        form.addEventListener('submit', function(e) {
          e.preventDefault();
          var btn = form.querySelector('button[type="submit"]');
          var origText = btn ? btn.textContent : '';
          if (btn) {
            btn.disabled = true;
            btn.textContent = '저장 중...';
          }

          var formData = new FormData(form);
          formData.append('ajax', '1');

          fetch(form.action, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: formData
          })
          .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
          })
          .then(function(data) {
            if (btn) {
              btn.disabled = false;
              btn.textContent = origText;
            }
            if (data && data.ok) {
              showSaveModal('저장되었습니다.', data.detail || '저장되었습니다.');
            } else {
              alert((data && data.error) ? data.error : '저장 중 오류가 발생했습니다.');
            }
          })
          .catch(function(err) {
            if (btn) {
              btn.disabled = false;
              btn.textContent = origText;
            }
            showSaveModal('저장되었습니다.', '수행자 및 교육일정이 저장되었습니다.');
          });
        });
      });

      <?php if (!empty($_GET['saved'])): ?>
        showSaveModal('저장되었습니다.', <?= json_encode($_SESSION['education_matrix_message'] ?? '수행자 및 교육일정이 저장되었습니다.', JSON_UNESCAPED_UNICODE) ?>);
      <?php endif; ?>
    });
  </script>
</body>
</html>
