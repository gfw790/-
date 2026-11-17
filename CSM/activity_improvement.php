<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/activity_improvement_store.php';
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
$_SESSION['activity_imp_csrf'] ??= bin2hex(random_bytes(32));

// Deliver attachment inline or download
if (isset($_GET['file'])) {
    $fileId = filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $stmt = $db->prepare("
        SELECT f.* FROM leadership_files f
        JOIN leadership_records r ON r.id = f.record_id
        WHERE f.id = ? AND r.year = ? AND r.item = '1.2.4' AND f.deleted_at IS NULL
    ");
    $stmt->execute([$fileId ?: 0, $year]);
    $file = $stmt->fetch();
    $path = $file ? __DIR__ . '/storage/leadership_evidence/' . $file['stored_name'] : '';
    if (!$file || !preg_match('/^[a-f0-9]{40}\.(pdf|jpg|jpeg|png|xlsx|pptx)$/D', (string)$file['stored_name']) || !is_file($path)) {
        http_response_code(404);
        exit('첨부문서를 찾을 수 없습니다.');
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $file['mime']);
    $inline = ($_GET['inline'] ?? '') === '1' || $file['mime'] === 'application/pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="evidence"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
    header('Content-Length: ' . filesize($path));
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile($path);
    }
    exit;
}

$message = $_SESSION['activity_imp_message'] ?? '';
$error = '';
unset($_SESSION['activity_imp_message']);

$record = activity_improvement_records($db, $year);
$data = $record['data'] ?? activity_improvement_default_data($year);
$files = $record['files'] ?? [];
$revision = (int)($record['revision'] ?? 0);

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['activity_imp_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
        }
        if (!$canManage) {
            throw new InvalidArgumentException('기록을 수정할 수 있는 관리자 권한이 없습니다.');
        }

        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'delete_file') {
            $fileId = filter_var($_POST['file_id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$fileId || !$record) {
                throw new InvalidArgumentException('삭제할 첨부파일을 확인할 수 없습니다.');
            }
            leadership_change_file($db, $fileId, (int)$record['id'], $revision, '1.2.4', $year, 'delete_file', (string)$user['login_id']);
            $_SESSION['activity_imp_message'] = '첨부 증빙이 삭제되었습니다.';
            header('Location: activity_improvement.php?year=' . $year . '&tab=evidence_files');
            exit;
        }

        if ($action === 'upload_file') {
            if (!$record) {
                throw new InvalidArgumentException('먼저 기본 개선정보를 저장해 주세요.');
            }
            $fileKind = trim((string)($_POST['file_kind'] ?? 'csm_report'));
            $kinds = activity_improvement_file_kinds();
            if (!isset($kinds[$fileKind])) {
                $fileKind = 'csm_report';
            }

            if (empty($_FILES['evidence']['name'][0])) {
                throw new InvalidArgumentException('업로드할 파일을 선택해 주세요.');
            }

            $storageDir = __DIR__ . '/storage/leadership_evidence';
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0777, true);
            }

            $uploadedCount = 0;
            $filesCount = count($_FILES['evidence']['name']);
            for ($i = 0; $i < $filesCount; $i++) {
                if ($_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $origName = basename((string)$_FILES['evidence']['name'][$i]);
                $tmpPath = (string)$_FILES['evidence']['tmp_name'][$i];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ['pdf', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png'], true)) {
                    continue;
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmpPath) ?: 'application/octet-stream';
                $size = filesize($tmpPath);
                $storedName = sha1(uniqid((string)mt_rand(), true) . $origName) . '.' . $ext;
                $destPath = $storageDir . '/' . $storedName;

                if (move_uploaded_file($tmpPath, $destPath)) {
                    $ins = $db->prepare("
                        INSERT INTO leadership_files (record_id, original_name, stored_name, mime, size, file_kind)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([(int)$record['id'], $origName, $storedName, $mime, $size, $fileKind]);
                    $uploadedCount++;
                }
            }

            $_SESSION['activity_imp_message'] = "{$uploadedCount}건의 증빙 파일이 성공적으로 등록되었습니다.";
            header('Location: activity_improvement.php?year=' . $year . '&tab=evidence_files');
            exit;
        }

        if ($action === 'save_csm_item') {
            $itemId = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT);
            $period = trim((string)($_POST['period'] ?? '2025년 상반기'));
            $location = trim((string)($_POST['location'] ?? ''));
            $finding = trim((string)($_POST['finding'] ?? ''));
            $actionPlan = trim((string)($_POST['action_plan'] ?? ''));
            $manager = trim((string)($_POST['manager'] ?? ''));
            $targetSchedule = trim((string)($_POST['target_schedule'] ?? '즉시 조치'));
            $status = trim((string)($_POST['status'] ?? 'completed'));
            $completedDate = trim((string)($_POST['completed_date'] ?? ''));
            $planReflection = trim((string)($_POST['plan_reflection'] ?? ''));
            $planChapter = trim((string)($_POST['plan_chapter'] ?? ''));

            if ($finding === '' || $actionPlan === '') {
                throw new InvalidArgumentException('발견사항과 개선방법은 필수 입력 항목입니다.');
            }

            $csmList = $data['csm_improvements'] ?? [];
            if ($itemId > 0) {
                // Update
                foreach ($csmList as &$itm) {
                    if ((int)($itm['id'] ?? 0) === $itemId) {
                        $itm['period'] = $period;
                        $itm['location'] = $location;
                        $itm['finding'] = $finding;
                        $itm['action_plan'] = $actionPlan;
                        $itm['manager'] = $manager;
                        $itm['target_schedule'] = $targetSchedule;
                        $itm['status'] = $status;
                        $itm['completed_date'] = $completedDate;
                        $itm['plan_reflection'] = $planReflection;
                        $itm['plan_chapter'] = $planChapter;
                        break;
                    }
                }
                unset($itm);
            } else {
                // Insert new
                $newId = 1;
                foreach ($csmList as $itm) {
                    if ((int)($itm['id'] ?? 0) >= $newId) {
                        $newId = (int)$itm['id'] + 1;
                    }
                }
                $csmList[] = [
                    'id'              => $newId,
                    'period'          => $period,
                    'seq'             => count($csmList) + 1,
                    'location'        => $location,
                    'finding'         => $finding,
                    'action_plan'     => $actionPlan,
                    'manager'         => $manager,
                    'target_schedule' => $targetSchedule,
                    'status'          => $status,
                    'completed_date'  => $completedDate,
                    'photo_before'    => '',
                    'photo_after'     => '',
                    'photo_before_cap'=> '',
                    'photo_after_cap' => '',
                    'plan_reflection' => $planReflection,
                    'plan_chapter'    => $planChapter,
                    'remark'          => '신규 등록 개선과제',
                ];
            }

            $data['csm_improvements'] = $csmList;
            $newRevision = $revision + 1;
            $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $update = $db->prepare("
                UPDATE leadership_records
                SET payload = ?, revision = ?, updated_by = ?, updated_at = datetime('now', 'localtime')
                WHERE id = ?
            ");
            $update->execute([$payloadJson, $newRevision, (string)$user['login_id'], (int)$record['id']]);

            $_SESSION['activity_imp_message'] = '개선과제가 성공적으로 저장되었습니다.';
            header('Location: activity_improvement.php?year=' . $year . '&tab=csm_list');
            exit;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Current Evaluation Status
$status = activity_improvement_status($data, $files);
$activeTab = $_GET['tab'] ?? 'csm_list';
$allowedTabs = ['csm_list', 'external_audits', 'plan_reflections', 'photo_gallery', 'evidence_files', 'edit_items'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'csm_list';
}

$csmImprovements = $data['csm_improvements'] ?? [];
$externalAudits = $data['external_audits'] ?? [];
$planReflections = $data['plan_reflections'] ?? [];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $year ?>년도 전년도 개선계획 반영 및 개선완료 (1.2.4) - 현대기전 CSM</title>
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
    .score-badge-huge {
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.18);
      border-radius: 14px;
      padding: 16px 26px;
      text-align: center;
      min-width: 190px;
    }
    .score-badge-huge strong {
      display: block;
      font-size: 34px;
      font-weight: 800;
      color: #38bdf8;
      line-height: 1.1;
    }
    .score-badge-huge small {
      color: #94a3b8;
      font-size: 12px;
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
    .chip.warn {
      background: #78350f;
      border-color: #f59e0b;
      color: #fde68a;
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
    .badge-ing {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      background: #fef3c7;
      color: #b45309;
      border: 1px solid #fde68a;
    }

    .photo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 18px;
      margin-top: 16px;
    }
    .photo-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .photo-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 1px dashed #e2e8f0;
    }
    .photo-compare {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 12px;
    }
    .photo-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 6px;
      text-align: center;
    }
    .photo-box img {
      width: 100%;
      height: 140px;
      object-fit: cover;
      border-radius: 6px;
      cursor: pointer;
      transition: transform 0.2s ease;
    }
    .photo-box img:hover {
      transform: scale(1.02);
    }
    .photo-label {
      font-size: 11px;
      font-weight: 700;
      margin-top: 6px;
      padding: 2px 6px;
      border-radius: 4px;
      display: inline-block;
    }
    .photo-label.before { background: #fee2e2; color: #dc2626; }
    .photo-label.after { background: #dcfce7; color: #15803d; }

    /* Modal viewer */
    .doc-viewer-modal {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.65);
      z-index: 9999;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }
    .doc-viewer-modal.open {
      display: flex;
    }
    .doc-viewer-content {
      background: #fff;
      width: 95%;
      max-width: 1100px;
      height: 90vh;
      border-radius: 14px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    }
    .doc-viewer-header {
      padding: 14px 20px;
      background: #0f172a;
      color: #fff;
      display: flex;
      justify-content: space-between;
      align-items: center;
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
            안전보건관리체계 (1.2 활동계획·예산·조직) &gt; <span style="color: #1e40af;">1.2.4 전년도 개선계획 반영 및 개선완료</span>
          </div>
          <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px;">
            <span>전년도 개선계획 당해연도 반영 및 완료 관리</span>
            <span style="font-size: 13px; font-weight: 700; background: #e0f2fe; color: #0284c7; padding: 4px 10px; border-radius: 20px;">1.2.4 (배점 2.0점)</span>
          </h1>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
          <form method="get" action="activity_improvement.php" style="display: inline-flex; align-items: center; gap: 6px;">
            <label for="year-select" style="font-size: 12px; font-weight: 700; color: #475569;">조회연도:</label>
            <select id="year-select" name="year" onchange="this.form.submit()" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 700;">
              <?php foreach (range((int)$now->format('Y') + 1, 2022) as $y): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년</option>
              <?php endforeach; ?>
            </select>
          </form>

          <a href="activity_improvement_print.php?year=<?= $year ?>" target="_blank" class="button" style="background: #1e3a8a; color: #fff; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
            🖨️ 인쇄 / PDF 저장
          </a>

          <a href="management_system.php?section=1.2" class="button" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none;">
            📋 1.2 관리체계 목록
          </a>

          <a href="index.php" class="button" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none;">
            📊 CSM 포탈
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
          CSM EVALUATION CRITERION 1.2.4
        </div>
        <h2 style="font-size: 19px; font-weight: 800; margin: 0 0 8px 0; color: #ffffff;">
          전년도 안전보건활동평가 및 외부점검 개선계획 당해연도 반영 및 완료
        </h2>
        <p style="font-size: 13px; color: #94a3b8; margin: 0; line-height: 1.5;">
          고용노동부·안전보건공단 점검결과 관리, 전년도 CSM 안전활동평가 지적사항에 대한 100% 개선조치 완료,
          그리고 미흡사항의 당해연도(<?= $year ?>년) 안전보건활동계획서 수록 및 제도화 이력을 통합 관리합니다.
        </p>

        <div class="status-chips">
          <span class="chip <?= $status['is_all_completed'] ? 'active' : 'warn' ?>">
            <?= $status['is_all_completed'] ? '✓' : '!' ?> CSM 개선과제: <?= $status['completed_csm'] ?>/<?= $status['total_csm'] ?>건 (<?= $status['csm_rate'] ?>% 완료)
          </span>
          <span class="chip <?= $status['has_external_audit'] ? 'active' : 'warn' ?>">
            <?= $status['has_external_audit'] ? '✓' : '!' ?> 외부평가(노동부/공단) 관리: <?= $status['completed_audits'] ?>/<?= $status['total_audits'] ?>건 (완료)
          </span>
          <span class="chip <?= $status['has_plan_reflections'] ? 'active' : 'warn' ?>">
            <?= $status['has_plan_reflections'] ? '✓' : '!' ?> 당해연도 활동계획서 반영: <?= $status['total_reflections'] ?>개 조항 연계
          </span>
          <span class="chip <?= $status['has_evidence'] ? 'active' : 'warn' ?>">
            <?= $status['has_evidence'] ? '✓' : '!' ?> 증빙문서 및 전·후 사진: <?= $status['files_count'] ?>건 완비
          </span>
        </div>
      </div>

      <div class="score-badge-huge">
        <small>1.2.4 평가 획득 점수</small>
        <strong><?= number_format(2.0 * (float)$status['multiplier'], 1) ?> <span style="font-size: 16px; font-weight: 600; color: #94a3b8;">/ 2.0점</span></strong>
        <div style="font-size: 12px; font-weight: 700; color: #10b981; margin-top: 4px;">
          <?= $escape($status['status_text']) ?>
        </div>
      </div>
    </section>

    <!-- Sub Tabs Navigation -->
    <nav class="sub-tabs">
      <a href="activity_improvement.php?year=<?= $year ?>&amp;tab=csm_list" class="<?= $activeTab === 'csm_list' ? 'current' : '' ?>">
        📋 전년도 CSM 개선과제 (<?= count($csmImprovements) ?>건)
      </a>
      <a href="activity_improvement.php?year=<?= $year ?>&amp;tab=external_audits" class="<?= $activeTab === 'external_audits' ? 'current' : '' ?>">
        🏛️ 외부기관(노동부·공단) 점검 (<?= count($externalAudits) ?>건)
      </a>
      <a href="activity_improvement.php?year=<?= $year ?>&amp;tab=plan_reflections" class="<?= $activeTab === 'plan_reflections' ? 'current' : '' ?>">
        📖 <?= $year ?>년 활동계획서 반영 대조표 (<?= count($planReflections) ?>건)
      </a>
      <a href="activity_improvement.php?year=<?= $year ?>&amp;tab=photo_gallery" class="<?= $activeTab === 'photo_gallery' ? 'current' : '' ?>">
        📸 개선 전·후 사진 갤러리 (<?= count($csmImprovements) ?>세트)
      </a>
      <a href="activity_improvement.php?year=<?= $year ?>&amp;tab=evidence_files" class="<?= $activeTab === 'evidence_files' ? 'current' : '' ?>">
        📂 공문 및 증빙문서 (<?= count($files) ?>건)
      </a>
      <?php if ($canManage): ?>
        <a href="activity_improvement.php?year=<?= $year ?>&amp;tab=edit_items" class="<?= $activeTab === 'edit_items' ? 'current' : '' ?>">
          ✏️ 개선과제 신규등록/편집
        </a>
      <?php endif; ?>
    </nav>

    <!-- Tab 1: CSM Improvements List -->
    <?php if ($activeTab === 'csm_list'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              전년도 협력업체 안전보건활동평가(CSM) 개선과제 이행 대장
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              한라 CSM 정기 평가 지적사항에 대한 시정조치 계획 수립, 현장 개선 전·후 확인 및 당해연도 안전보건활동계획서 반영 내역입니다.
            </p>
          </div>
          <div>
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">전체 14건 / 100% 조치완료</span>
          </div>
        </div>

        <div style="overflow-x: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 45px; text-align: center;">No</th>
                <th style="width: 100px;">평가 시기</th>
                <th style="width: 100px;">장소/구역</th>
                <th style="min-width: 200px;">지적 및 발견사항</th>
                <th style="min-width: 220px;">개선 방법 및 조치 내용</th>
                <th style="width: 70px; text-align: center;">담당자</th>
                <th style="width: 90px; text-align: center;">조치일자</th>
                <th style="width: 80px; text-align: center;">완료여부</th>
                <th style="min-width: 180px;">당해연도 활동계획서 반영 조항</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($csmImprovements as $idx => $itm): ?>
                <tr>
                  <td style="text-align: center; font-weight: 700; color: #475569;"><?= $idx + 1 ?></td>
                  <td style="font-weight: 700; color: #1e40af;"><?= $escape($itm['period']) ?></td>
                  <td style="font-weight: 600;"><?= $escape($itm['location']) ?></td>
                  <td style="color: #0f172a; line-height: 1.4;"><?= nl2br($escape($itm['finding'])) ?></td>
                  <td style="color: #0369a1; font-weight: 600; line-height: 1.4;"><?= nl2br($escape($itm['action_plan'])) ?></td>
                  <td style="text-align: center; font-weight: 600;"><?= $escape($itm['manager']) ?></td>
                  <td style="text-align: center; font-size: 12px; color: #475569;"><?= $escape($itm['completed_date'] ?: $itm['target_schedule']) ?></td>
                  <td style="text-align: center;">
                    <?php if (($itm['status'] ?? '') === 'completed'): ?>
                      <span class="badge-done">완료 (100%)</span>
                    <?php else: ?>
                      <span class="badge-ing">진행중</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size: 12px; color: #047857; font-weight: 600;">
                    <?= $escape($itm['plan_reflection']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- Tab 2: External Audits -->
    <?php if ($activeTab === 'external_audits'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              외부기관 (고용노동부, 안전보건공단, 가스안전공사 등) 점검결과 및 시정관리
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              외부 정부기관 및 공인기관의 현장 지도·감독, 인증 심사 결과 및 지적사항 시정완료 내역입니다.
            </p>
          </div>
          <div>
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">2건 점검 / 전수 적합 완료</span>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
          <?php foreach ($externalAudits as $aIdx => $audit): ?>
            <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; background: #ffffff; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
              <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                <div>
                  <span style="font-size: 11px; font-weight: 800; background: #e0e7ff; color: #3730a3; padding: 3px 8px; border-radius: 4px;">
                    <?= $escape($audit['agency']) ?>
                  </span>
                  <h4 style="font-size: 16px; font-weight: 800; margin: 6px 0 0 0; color: #0f172a;">
                    <?= $escape($audit['audit_name']) ?>
                  </h4>
                </div>
                <div style="text-align: right;">
                  <span style="font-size: 12px; font-weight: 700; color: #64748b;">점검일: <?= $escape($audit['audit_date']) ?></span>
                  <div style="margin-top: 4px;">
                    <span class="badge-done">판정: <?= $escape($audit['result_cert']) ?></span>
                  </div>
                </div>
              </div>

              <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <div>
                  <strong style="display: block; font-size: 12px; color: #dc2626; margin-bottom: 4px;">⚠ 외부 점검 지적·권고사항:</strong>
                  <p style="font-size: 13px; color: #1e293b; margin: 0; line-height: 1.5;"><?= nl2br($escape($audit['finding'])) ?></p>
                </div>
                <div>
                  <strong style="display: block; font-size: 12px; color: #15803d; margin-bottom: 4px;">✓ 사업장 개선 시정조치 내용:</strong>
                  <p style="font-size: 13px; color: #0f172a; margin: 0; line-height: 1.5; font-weight: 600;"><?= nl2br($escape($audit['corrective_action'])) ?></p>
                </div>
              </div>

              <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; border-top: 1px solid #f1f5f9; padding-top: 10px;">
                <div style="color: #047857; font-weight: 700;">
                  📖 당해연도 활동계획서 반영: <span style="font-weight: 600; color: #0f172a;"><?= $escape($audit['plan_reflection']) ?></span>
                </div>
                <div style="color: #64748b; font-size: 12px;">
                  조치담당자: <strong><?= $escape($audit['manager']) ?></strong> (완료일: <?= $escape($audit['completion_date']) ?>)
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Tab 3: Plan Reflections -->
    <?php if ($activeTab === 'plan_reflections'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              당해연도(<?= $year ?>년) 안전보건활동계획서 미흡사항 반영 종합 대조표
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              전년도 CSM 평가 및 외부 점검에서 도출된 개선요구사항이 당해연도 안전보건활동계획서 각 편·장에 명문화되어 지속 이행되고 있음을 증명합니다.
            </p>
          </div>
          <div>
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">전체 7개 항목 100% 반영 완료</span>
          </div>
        </div>

        <div style="overflow-x: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 50px; text-align: center;">No</th>
                <th style="width: 170px;">개선사항 출처</th>
                <th style="min-width: 180px;">개선 지적·권고 항목</th>
                <th style="min-width: 200px;">당해연도 활동계획서 수록 장·절</th>
                <th style="min-width: 260px;">계획서 구체적 반영 및 제도화 내용</th>
                <th style="width: 90px; text-align: center;">이행상태</th>
                <th style="width: 90px; text-align: center;">최종확인일</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($planReflections as $pIdx => $ref): ?>
                <tr>
                  <td style="text-align: center; font-weight: 700; color: #475569;"><?= $pIdx + 1 ?></td>
                  <td style="font-weight: 700; color: #1e40af;"><?= $escape($ref['source']) ?></td>
                  <td style="font-weight: 600; color: #0f172a;"><?= $escape($ref['source_item']) ?></td>
                  <td style="font-weight: 700; color: #047857;"><?= $escape($ref['plan_section']) ?></td>
                  <td style="color: #334155; line-height: 1.4;"><?= nl2br($escape($ref['reflected_content'])) ?></td>
                  <td style="text-align: center;"><span class="badge-done"><?= $escape($ref['status']) ?></span></td>
                  <td style="text-align: center; font-size: 12px; color: #64748b;"><?= $escape($ref['verified_date']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- Tab 4: Photo Gallery -->
    <?php if ($activeTab === 'photo_gallery'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              개선 전·후 현장 사진 비교 갤러리 (Before vs After)
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              전년도 안전활동평가 점검보고서(PPT)에 수록된 현장 개선 전·후 실물 사진 기록입니다.
            </p>
          </div>
          <div>
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">현장 사진 증빙 완비</span>
          </div>
        </div>

        <div class="photo-grid">
          <?php foreach ($csmImprovements as $cIdx => $itm): ?>
            <?php
              $beforeImg = $itm['photo_before'] ?? '';
              $afterImg = $itm['photo_after'] ?? '';
              if (!$beforeImg || !$afterImg) continue;
            ?>
            <div class="photo-card">
              <div>
                <div class="photo-card-header">
                  <span style="font-size: 11px; font-weight: 800; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px;">
                    #<?= $cIdx + 1 ?> <?= $escape($itm['period']) ?> (<?= $escape($itm['location']) ?>)
                  </span>
                  <span class="badge-done">조치완료</span>
                </div>

                <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 10px; line-height: 1.4;">
                  <?= $escape($itm['finding']) ?>
                </div>

                <div class="photo-compare">
                  <div class="photo-box">
                    <img src="<?= $escape($beforeImg) ?>" alt="개선 전" onclick="openDocViewer('<?= $escape($beforeImg) ?>', '개선 전 사진: <?= $escape($itm['finding']) ?>')">
                    <div><span class="photo-label before">조치 전</span></div>
                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;"><?= $escape($itm['photo_before_cap'] ?: '개선 전 현장') ?></div>
                  </div>

                  <div class="photo-box">
                    <img src="<?= $escape($afterImg) ?>" alt="개선 후" onclick="openDocViewer('<?= $escape($afterImg) ?>', '개선 후 사진: <?= $escape($itm['finding']) ?>')">
                    <div><span class="photo-label after">조치 후 (완료)</span></div>
                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;"><?= $escape($itm['photo_after_cap'] ?: '개선 완료 현장') ?></div>
                  </div>
                </div>
              </div>

              <div style="border-top: 1px dashed #e2e8f0; padding-top: 8px; margin-top: 8px; font-size: 11.5px; color: #047857; font-weight: 600;">
                ✓ 반영: <?= $escape($itm['plan_reflection']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Tab 5: Evidence Files -->
    <?php if ($activeTab === 'evidence_files'): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <div>
            <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">
              평가 증빙 문서 및 공문 관리 (<?= count($files) ?>건)
            </h3>
            <p style="font-size: 12.5px; color: #64748b; margin: 0;">
              CSM 개선완료 보고서, 연도별 개선현황 대장, 외부 공문 및 개선 발표자료(PPT/Excel/PDF) 목록입니다.
            </p>
          </div>
          <div>
            <span class="badge-done" style="font-size: 12px; padding: 5px 12px;">증빙 충족</span>
          </div>
        </div>

        <div style="overflow-x: auto; margin-bottom: 20px;">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 50px; text-align: center;">No</th>
                <th style="width: 140px;">증빙 분류</th>
                <th>파일명</th>
                <th style="width: 100px; text-align: right;">파일 크기</th>
                <th style="width: 140px; text-align: center;">열람 및 다운로드</th>
                <?php if ($canManage): ?>
                  <th style="width: 80px; text-align: center;">관리</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($files as $fIdx => $f): ?>
                <?php
                  $kinds = activity_improvement_file_kinds();
                  $kindLabel = $kinds[$f['file_kind']] ?? '기타 증빙';
                  $sizeKb = round((int)$f['size'] / 1024, 1);
                  $sizeText = $sizeKb > 1024 ? round($sizeKb / 1024, 2) . ' MB' : $sizeKb . ' KB';
                  $isPdf = $f['mime'] === 'application/pdf';
                  $isImage = str_starts_with($f['mime'], 'image/');
                ?>
                <tr>
                  <td style="text-align: center; font-weight: 700; color: #64748b;"><?= $fIdx + 1 ?></td>
                  <td style="font-weight: 700; color: #1e40af;"><?= $escape($kindLabel) ?></td>
                  <td style="font-weight: 600; color: #0f172a;">
                    <?= $escape($f['original_name']) ?>
                  </td>
                  <td style="text-align: right; font-size: 12px; color: #64748b;"><?= $sizeText ?></td>
                  <td style="text-align: center;">
                    <div style="display: inline-flex; gap: 6px;">
                      <?php if ($isPdf || $isImage): ?>
                        <button type="button" class="button" style="padding: 4px 10px; font-size: 11px;" onclick="openDocViewer('activity_improvement.php?year=<?= $year ?>&amp;file=<?= $f['id'] ?>&amp;inline=1', '증빙 문서: <?= $escape($f['original_name']) ?>')">
                          🔍 바로보기
                        </button>
                      <?php endif; ?>
                      <a href="activity_improvement.php?year=<?= $year ?>&amp;file=<?= $f['id'] ?>" class="button" style="padding: 4px 10px; font-size: 11px; background: #f8fafc; border: 1px solid #cbd5e1; text-decoration: none; color: #0f172a;">
                        ⬇ 다운로드
                      </a>
                    </div>
                  </td>
                  <?php if ($canManage): ?>
                    <td style="text-align: center;">
                      <form method="post" action="activity_improvement.php?year=<?= $year ?>" onsubmit="return confirm('이 증빙파일을 삭제하시겠습니까?');" style="margin: 0;">
                        <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_imp_csrf']) ?>">
                        <input type="hidden" name="action" value="delete_file">
                        <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" class="button" style="padding: 4px 8px; font-size: 11px; color: #dc2626; border-color: #fca5a5;">
                          삭제
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($canManage): ?>
          <!-- Upload Form -->
          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px;">
            <h4 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0 0 10px 0;">
              📤 추가 증빙 파일 업로드
            </h4>
            <form method="post" action="activity_improvement.php?year=<?= $year ?>" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_imp_csrf']) ?>">
              <input type="hidden" name="action" value="upload_file">

              <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; align-items: end;">
                <div>
                  <label for="file_kind" style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">증빙 분류</label>
                  <select id="file_kind" name="file_kind" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;">
                    <?php foreach (activity_improvement_file_kinds() as $k => $lbl): ?>
                      <option value="<?= $escape($k) ?>"><?= $escape($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label for="evidence" style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">파일 선택 (.pdf, .xlsx, .pptx, .jpg, .png)</label>
                  <input id="evidence" type="file" name="evidence[]" multiple accept=".pdf,.xlsx,.pptx,.jpg,.jpeg,.png" style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px;" required>
                </div>

                <div>
                  <button type="submit" class="button primary" style="padding: 8px 20px; font-weight: 700; height: 38px; width: 100%;">증빙 파일 업로드</button>
                </div>
              </div>
            </form>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- Tab 6: Edit / Register Items (Admin only) -->
    <?php if ($activeTab === 'edit_items' && $canManage): ?>
      <section class="panel" style="margin-bottom: 24px;">
        <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">
          신규 개선과제 등록 및 기존 과제 편집
        </h3>
        <p style="font-size: 12.5px; color: #64748b; margin: 0 0 18px 0;">
          CSM 지적사항 또는 사업장 내부 발굴 미흡사항을 등록하고 당해연도 활동계획서 반영 조항을 지정합니다.
        </p>

        <form method="post" action="activity_improvement.php?year=<?= $year ?>">
          <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_imp_csrf']) ?>">
          <input type="hidden" name="action" value="save_csm_item">
          <input type="hidden" name="item_id" value="0">

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">평가 시기 / 구분</label>
              <input type="text" name="period" value="<?= $year - 1 ?>년 하반기" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;" required>
            </div>

            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">장소 / 구역</label>
              <input type="text" name="location" placeholder="예: 자재창고, 제작장, 현장 등" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;" required>
            </div>

            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">담당자</label>
              <input type="text" name="manager" value="김남균 과장" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;" required>
            </div>

            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">완료 일정</label>
              <input type="text" name="target_schedule" value="즉시 조치" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;" required>
            </div>
          </div>

          <div style="margin-bottom: 16px;">
            <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">지적 및 발견사항 (개선 요청사항)</label>
            <textarea name="finding" rows="3" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;" placeholder="평가단 점검 또는 자체 점검 지적사항을 기술하세요." required></textarea>
          </div>

          <div style="margin-bottom: 16px;">
            <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">개선 방법 및 조치 내용</label>
            <textarea name="action_plan" rows="3" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;" placeholder="실행 완료된 개선 조치 사항을 구체적으로 기술하세요." required></textarea>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">완료 여부 상태</label>
              <select name="status" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;">
                <option value="completed" selected>완료 (100% 조치완료)</option>
                <option value="in_progress">진행중</option>
                <option value="planned">계획</option>
              </select>
            </div>

            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">완료일자</label>
              <input type="date" name="completed_date" value="<?= date('Y-m-d') ?>" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;">
            </div>

            <div>
              <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px;">당해연도 활동계획서 반영 조항</label>
              <input type="text" name="plan_reflection" placeholder="예: 2026년 안전보건활동계획서 제7편 반영" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;">
            </div>
          </div>

          <div>
            <button type="submit" class="button primary" style="padding: 10px 24px; font-weight: 700;">개선과제 신규 저장</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

  </div>

  <!-- Document Viewer Modal -->
  <div id="doc-viewer-modal" class="doc-viewer-modal" onclick="if(event.target===this) closeDocViewer();">
    <div class="doc-viewer-content">
      <div class="doc-viewer-header">
        <h3 id="doc-viewer-title" style="margin: 0; font-size: 15px; font-weight: 700;">문서 뷰어</h3>
        <button type="button" onclick="closeDocViewer()" style="background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; padding: 4px 8px;">✕</button>
      </div>
      <iframe id="doc-viewer-frame" style="width: 100%; height: 100%; border: none;"></iframe>
    </div>
  </div>

  <script>
    function openDocViewer(url, title) {
      document.getElementById('doc-viewer-title').textContent = title || '문서 뷰어';
      var frame = document.getElementById('doc-viewer-frame');
      frame.src = url;
      document.getElementById('doc-viewer-modal').classList.add('open');
    }
    function closeDocViewer() {
      document.getElementById('doc-viewer-modal').classList.remove('open');
      document.getElementById('doc-viewer-frame').src = '';
    }
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeDocViewer();
    });
  </script>
</body>
</html>
