<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/activity_organization_store.php';
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
$_SESSION['activity_org_csrf'] ??= bin2hex(random_bytes(32));

// Deliver attachment inline or download
if (isset($_GET['file'])) {
    $fileId = filter_var($_GET['file'], FILTER_VALIDATE_INT);
    $stmt = $db->prepare("
        SELECT f.* FROM leadership_files f
        JOIN leadership_records r ON r.id = f.record_id
        WHERE f.id = ? AND r.year = ? AND r.item = '1.2.3' AND f.deleted_at IS NULL
    ");
    $stmt->execute([$fileId ?: 0, $year]);
    $file = $stmt->fetch();
    $path = $file ? __DIR__ . '/storage/leadership_evidence/' . $file['stored_name'] : '';
    if (!$file || !preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png|xlsx)$/D', (string)$file['stored_name']) || !is_file($path)) {
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

$message = $_SESSION['activity_org_message'] ?? '';
$error = '';
unset($_SESSION['activity_org_message']);

$record = activity_org_records($db, $year);
$data = $record['data'] ?? activity_org_default_data($year);
$data['budget'] = activity_org_budget_from_ledger($year);
$files = $record['files'] ?? [];
$revision = (int)($record['revision'] ?? 0);
$currentRoster = activity_org_current_roster();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['activity_org_csrf'], $_POST['csrf'])) {
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
            leadership_change_file($db, $fileId, (int)$record['id'], $revision, '1.2.3', $year, 'delete_file', (string)$user['login_id']);
            $_SESSION['activity_org_message'] = '첨부 증빙이 삭제되었습니다.';
            header('Location: activity_organization.php?year=' . $year . '&tab=files');
            exit;
        }

        if ($action === 'upload_file') {
            if (!$record) {
                throw new InvalidArgumentException('먼저 조직 및 예산 기본정보를 저장해 주세요.');
            }
            $fileKind = trim((string)($_POST['file_kind'] ?? 'org_chart'));
            $allowedKinds = array_keys(activity_org_file_kinds());
            if (!in_array($fileKind, $allowedKinds, true)) {
                $fileKind = 'org_chart';
            }
            $uploads = leadership_uploads($_FILES['evidence'] ?? []);
            if (!$uploads) {
                throw new InvalidArgumentException('첨부할 파일을 선택해 주세요 (PDF, JPG, PNG).');
            }
            foreach ($uploads as &$u) {
                $u['file_kind'] = $fileKind;
            }
            unset($u);

            $recData = [
                'item'          => '1.2.3',
                'year'          => $year,
                'activity_date' => $record['activity_date'] ?? sprintf('%04d-01-01', $year),
                'title'         => $record['title'] ?? sprintf('%04d년 안전보건 예산 반영 및 조직 구성·권한 부여', $year),
                'payload'       => $data,
            ];
            leadership_save($db, $recData, (int)$record['id'], $revision, $uploads, (string)$user['login_id'], true);
            $_SESSION['activity_org_message'] = '새 증빙 파일이 등록되었습니다.';
            header('Location: activity_organization.php?year=' . $year . '&tab=files');
            exit;
        }

        if ($action === 'save_all') {
            $orgName = trim((string)($_POST['org_name'] ?? '(주)현대기전 안전보건관리 조직'));
            $effectiveDate = trim((string)($_POST['effective_date'] ?? sprintf('%04d-01-01', $year)));
            $description = trim((string)($_POST['description'] ?? ''));

            $authGranted = [
                'stop_work'           => !empty($_POST['auth_stop_work']),
                'corrective_action'   => !empty($_POST['auth_corrective_action']),
                'budget_requisition'  => !empty($_POST['auth_budget_requisition']),
                'regulation_enforced' => !empty($_POST['auth_regulation_enforced']),
            ];

            // Reconstruct roles
            $roleTitles = (array)($_POST['role_title'] ?? []);
            $roleNames = (array)($_POST['role_name'] ?? []);
            $rolePositions = (array)($_POST['role_position'] ?? []);
            $roleDates = (array)($_POST['role_appointed_date'] ?? []);
            $roleDuties = (array)($_POST['role_duties'] ?? []);
            $roleAuthorities = (array)($_POST['role_authority'] ?? []);

            $roles = [];
            for ($i = 0; $i < count($roleTitles); $i++) {
                $title = trim((string)($roleTitles[$i] ?? ''));
                $name = trim((string)($roleNames[$i] ?? ''));
                if ($title === '' || $name === '') continue;
                $roles[] = [
                    'role_title'     => $title,
                    'name'           => $name,
                    'position'       => trim((string)($rolePositions[$i] ?? '')),
                    'appointed_date' => trim((string)($roleDates[$i] ?? $effectiveDate)),
                    'duties'         => trim((string)($roleDuties[$i] ?? '')),
                    'authority'      => trim((string)($roleAuthorities[$i] ?? '')),
                ];
            }

            // Reconstruct budget
            $totalPlanned = (float)str_replace(',', '', (string)($_POST['total_planned'] ?? 0));
            $totalExecuted = (float)str_replace(',', '', (string)($_POST['total_executed'] ?? 0));

            $bCodes = (array)($_POST['b_code'] ?? []);
            $bNames = (array)($_POST['b_name'] ?? []);
            $bPlanned = (array)($_POST['b_planned'] ?? []);
            $bExecuted = (array)($_POST['b_executed'] ?? []);
            $bNotes = (array)($_POST['b_note'] ?? []);

            $budgetItems = [];
            for ($i = 0; $i < count($bCodes); $i++) {
                $name = trim((string)($bNames[$i] ?? ''));
                if ($name === '') continue;
                $budgetItems[] = [
                    'code'     => trim((string)($bCodes[$i] ?? 'item_' . $i)),
                    'name'     => $name,
                    'planned'  => (float)str_replace(',', '', (string)($bPlanned[$i] ?? 0)),
                    'executed' => (float)str_replace(',', '', (string)($bExecuted[$i] ?? 0)),
                    'note'     => trim((string)($bNotes[$i] ?? '')),
                ];
            }

            $payload = [
                'organization' => [
                    'org_name'            => $orgName,
                    'effective_date'      => $effectiveDate,
                    'description'         => $description,
                    'roles'               => $roles,
                    'authorities_granted' => $authGranted,
                ],
                'budget' => [
                    'total_planned'  => $totalPlanned,
                    'total_executed' => $totalExecuted,
                    'currency'       => 'KRW',
                    'items'          => $budgetItems,
                ],
            ];
            // Budget amounts belong to the safety budget ledger, not this evidence form.
            $payload['budget'] = activity_org_budget_from_ledger($year);

            $recData = [
                'item'          => '1.2.3',
                'year'          => $year,
                'activity_date' => $effectiveDate,
                'title'         => sprintf('%04d년 안전보건 예산 반영 및 조직 구성·권한 부여', $year),
                'payload'       => $payload,
            ];

            $uploads = leadership_uploads($_FILES['evidence'] ?? []);
            $fileKind = trim((string)($_POST['file_kind'] ?? 'org_chart'));
            foreach ($uploads as &$u) {
                $u['file_kind'] = $fileKind;
            }
            unset($u);

            leadership_save($db, $recData, (int)($record['id'] ?? 0), $revision, $uploads, (string)$user['login_id']);
            $_SESSION['activity_org_message'] = '안전보건 조직 및 예산 관리 데이터가 저장되었습니다.';
            header('Location: activity_organization.php?year=' . $year);
            exit;
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

// Reload after potential changes
$record = activity_org_records($db, $year);
$data = $record['data'] ?? activity_org_default_data($year);
$data['budget'] = activity_org_budget_from_ledger($year);
$files = $record['files'] ?? [];
$revision = (int)($record['revision'] ?? 0);

$status = activity_org_status($data, $files);
$activeTab = (string)($_GET['tab'] ?? 'org');
if (!in_array($activeTab, ['org', 'budget', 'files'], true)) {
    $activeTab = 'org';
}

$catalog = json_decode((string)file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$itemScore = csm_activity_organization_summary($db, $year, 2.0);
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>1.2.3 안전보건활동 예산 및 조직 구성 · CSM</title>
  <link rel="stylesheet" href="leadership.css">
  <link rel="stylesheet" href="management_system.css">
  <style>
    .score-banner-box {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #fff;
      border-radius: 16px;
      padding: 24px;
      margin-bottom: 24px;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 20px;
      align-items: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.12);
    }
    .score-badge-huge {
      background: rgba(255,255,255,0.1);
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 14px;
      padding: 16px 24px;
      text-align: center;
      min-width: 170px;
    }
    .score-badge-huge strong {
      display: block;
      font-size: 32px;
      font-weight: 800;
      color: #38bdf8;
      line-height: 1.1;
    }
    .score-badge-huge small {
      color: #94a3b8;
      font-size: 12px;
    }
    .status-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 12px;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 11.5px;
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
      margin-bottom: 20px;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 8px;
    }
    .sub-tabs a {
      padding: 9px 18px;
      border-radius: 8px;
      font-weight: 700;
      font-size: 13px;
      text-decoration: none;
      color: #64748b;
      transition: all .15s ease;
    }
    .sub-tabs a.current {
      background: #2563eb;
      color: #fff;
    }
    .sub-tabs a:hover:not(.current) {
      background: #f1f5f9;
      color: #1e293b;
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
    .data-table input[type="text"], .data-table input[type="number"], .data-table textarea {
      width: 100%;
      padding: 6px 8px;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      font: inherit;
    }
    .progress-bar-wrap {
      width: 100%;
      height: 10px;
      background: #e2e8f0;
      border-radius: 5px;
      overflow: hidden;
      margin-top: 6px;
    }
    .progress-bar-fill {
      height: 100%;
      background: #10b981;
      transition: width 0.3s ease;
    }

    /* Plan-Style Organization Tree (계획서 제1편 제3장 도표) */
    .plan-org-wrapper {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 12px;
      padding: 24px 20px;
      margin-bottom: 24px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .plan-header-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 18px;
      padding-bottom: 12px;
      border-bottom: 2px solid #e2e8f0;
    }
    .plan-header-title {
      margin: 0;
      font-size: 15px;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .plan-section-subtitle {
      font-size: 13.5px;
      font-weight: 800;
      color: #1e3a8a;
      display: flex;
      align-items: center;
      gap: 6px;
      margin: 22px 0 12px 0;
    }

    /* Tree Diagram Layout */
    .tree-diagram-canvas {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 20px 10px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      margin-bottom: 24px;
    }
    .tree-box {
      border-radius: 8px;
      padding: 12px 18px;
      text-align: center;
      box-shadow: 0 2px 6px rgba(0,0,0,0.04);
      position: relative;
      transition: transform 0.2s;
    }
    .tree-box:hover {
      transform: translateY(-2px);
    }
    .tree-box-root {
      width: 320px;
      background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
      color: #ffffff;
      border: 2px solid #3b82f6;
    }
    .tree-box-manager {
      width: 300px;
      background: #ffffff;
      border: 2px solid #0d9488;
    }
    .tree-box-officer {
      width: 300px;
      background: #ffffff;
      border: 2px solid #059669;
    }
    .tree-box-workers {
      width: 360px;
      background: #ffffff;
      border: 2px solid #6366f1;
    }

    .tree-connector-line {
      width: 2px;
      height: 24px;
      background: #94a3b8;
    }

    /* Branch Container for Committee */
    .tree-branch-area {
      width: 100%;
      max-width: 660px;
      height: 72px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .tree-branch-trunk-line {
      position: absolute;
      left: 50%;
      top: 0;
      bottom: 0;
      width: 2px;
      background: #94a3b8;
      transform: translateX(-50%);
    }
    .tree-branch-horiz-line {
      position: absolute;
      left: calc(50% - 210px);
      width: 210px;
      top: 50%;
      height: 2px;
      border-top: 2px dashed #3b82f6;
    }
    .tree-box-committee {
      position: absolute;
      left: calc(50% - 210px);
      transform: translate(-100%, -50%);
      top: 50%;
      width: 190px;
      background: #eff6ff;
      border: 2px dashed #2563eb;
      border-radius: 8px;
      padding: 8px 12px;
      text-align: center;
      box-shadow: 0 2px 5px rgba(37,99,235,0.08);
      z-index: 2;
    }

    /* Plan Duties Official Table */
    .plan-duties-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 24px;
    }
    .plan-duties-table th {
      background: #f1f5f9;
      color: #1e293b;
      font-weight: 800;
      padding: 11px 14px;
      border: 1px solid #cbd5e1;
      text-align: center;
      font-size: 12.5px;
    }
    .plan-duties-table td {
      padding: 12px 14px;
      border: 1px solid #cbd5e1;
      vertical-align: top;
      line-height: 1.6;
      color: #334155;
    }
    .plan-duties-table tr:hover td {
      background: #fafafa;
    }
    .duty-num-list {
      margin: 0;
      padding-left: 0;
      list-style: none;
    }
    .duty-num-list li {
      margin-bottom: 4px;
      position: relative;
      padding-left: 20px;
      text-indent: -20px;
    }
    .duty-num-list li:last-child {
      margin-bottom: 0;
    }

    /* Roster Breakdown Section */
    .roster-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: 14px;
      margin-top: 10px;
      margin-bottom: 24px;
    }
    .roster-card {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      padding: 14px 16px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .roster-card.team-mgmt { border-top: 4px solid #1e3a8a; }
    .roster-card.team-const { border-top: 4px solid #0d9488; }
    .roster-card.team-gas { border-top: 4px solid #2563eb; }
    .roster-card.team-mfg { border-top: 4px solid #d97706; }

    .roster-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
      padding-bottom: 6px;
      border-bottom: 1px solid #f1f5f9;
    }
    .roster-team-title {
      font-size: 13.5px;
      font-weight: 800;
      color: #0f172a;
    }
    .roster-team-count {
      font-size: 11px;
      font-weight: 700;
      color: #64748b;
      background: #f1f5f9;
      padding: 2px 7px;
      border-radius: 4px;
    }
    .roster-member-pill {
      display: inline-block;
      margin: 3px 4px 3px 0;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #334155;
    }
    .roster-member-pill.leader {
      background: #eff6ff;
      border-color: #bfdbfe;
      color: #1d4ed8;
      font-weight: 700;
    }

    /* Visual Role Division Cards */
    .role-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 16px;
      margin-top: 14px;
    }
    .role-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 18px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.03);
      display: flex;
      flex-direction: column;
      gap: 12px;
      border-left: 5px solid #94a3b8;
      transition: all 0.2s;
    }
    .role-card:hover {
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .role-card.rc-blue { border-left-color: #2563eb; }
    .role-card.rc-teal { border-left-color: #0d9488; }
    .role-card.rc-emerald { border-left-color: #059669; }
    .role-card.rc-purple { border-left-color: #7c3aed; }
    .role-card.rc-amber { border-left-color: #d97706; }

    .role-card-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 1px solid #f1f5f9;
      padding-bottom: 10px;
    }
    .role-badge {
      display: inline-block;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 800;
    }
    .badge-blue { background: #dbeafe; color: #1e40af; }
    .badge-teal { background: #ccfbf1; color: #0f766e; }
    .badge-emerald { background: #d1fae5; color: #065f46; }
    .badge-purple { background: #ede9fe; color: #5b21b6; }
    .badge-amber { background: #fef3c7; color: #92400e; }

    .duty-container {
      background: #f8fafc;
      border-radius: 10px;
      padding: 12px;
      font-size: 12.5px;
      color: #334155;
      line-height: 1.6;
    }
    .duty-container strong {
      display: block;
      color: #1e293b;
      font-size: 11.5px;
      margin-bottom: 4px;
    }
    .auth-container {
      background: #fff1f2;
      border: 1px solid #ffe4e6;
      border-radius: 10px;
      padding: 12px;
      font-size: 12px;
      color: #9f1239;
      line-height: 1.5;
    }
    .auth-container strong {
      display: block;
      color: #e11d48;
      font-size: 11.5px;
      margin-bottom: 4px;
    }

    .edit-toggle-btn {
      padding: 6px 14px;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #334155;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .15s ease;
    }
    .edit-toggle-btn:hover {
      background: #f1f5f9;
      color: #0f172a;
    }
    .edit-toggle-btn.active-edit {
      background: #0f172a;
      color: #fff;
      border-color: #0f172a;
    }

    .file-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .file-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      transition: box-shadow 0.2s;
    }
    .file-card:hover {
      box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }
    .file-kind-badge {
      display: inline-block;
      align-self: flex-start;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .file-actions {
      display: flex;
      gap: 8px;
      margin-top: auto;
      padding-top: 8px;
      border-top: 1px dashed #e2e8f0;
    }

    /* Modal doc viewer */
    #doc-viewer-modal {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: rgba(15, 23, 42, 0.75);
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal-box {
      background: #fff;
      border-radius: 14px;
      width: min(92vw, 1100px);
      height: min(92vh, 850px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 20px;
      background: #1e293b;
      color: #fff;
    }
    .modal-header h3 {
      margin: 0;
      font-size: 15px;
      font-weight: 700;
    }
    .modal-close-btn {
      background: none;
      border: none;
      color: #94a3b8;
      font-size: 22px;
      cursor: pointer;
      padding: 0 6px;
    }
    .modal-close-btn:hover {
      color: #fff;
    }
    .modal-body {
      flex: 1;
      width: 100%;
      height: 100%;
      background: #f8fafc;
    }
    .modal-body iframe {
      width: 100%;
      height: 100%;
      border: none;
    }

        /* Print Stylesheet for Safety Organization & Duties */
    .print-doc-header, .print-doc-footer {
      display: none;
    }
    @media print {
      @page {
        size: A4 portrait;
        margin: 0;
      }
      * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      html, body {
        background: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 210mm !important;
      }

      /* 전체 화면 UI 및 폼 요소 숨김: 오직 '안전보건관리 조직 및 안전관계자의 책무'만 인쇄 */
      header,
      nav,
      .heading,
      .score-banner-box,
      .sub-tabs,
      .year-form,
      .org-info-form-section,
      .no-print,
      .quick-links,
      .edit-toggle-btn,
      .print-btn,
      #org-edit-view,
      .org-emergency-note,
      .modal,
      .modal-backdrop,
      .hint,
      .actions,
      button,
      input,
      textarea,
      select,
      #toggle-edit-btn {
        display: none !important;
      }

      main {
        width: 210mm !important;
        max-width: 210mm !important;
        margin: 0 auto !important;
        padding: 25mm 12mm 10mm 14mm !important; /* 브라우저 여백 없음 인쇄 시 항상 패딩으로 여백 유지 */
        border: none !important;
        background: #ffffff !important;
        box-sizing: border-box !important;
      }

      /* 기본 패널은 전부 숨기고, 오직 printable-org-section 패널만 표시 */
      .panel {
        display: none !important;
      }
      .panel.printable-org-section {
        display: block !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
      }

      .printable-org-section .section-title {
        display: none !important;
      }
      .plan-org-wrapper {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
      }
      .plan-header-row {
        display: none !important;
      }

      /* Print Document Official Header & Footer */
      .print-doc-header {
        display: block !important;
        text-align: center;
        margin-bottom: 14px;
        padding-bottom: 8px;
        border-bottom: 2px solid #0f172a;
      }
      .print-doc-header .doc-corp {
        font-size: 11pt;
        font-weight: 700;
        color: #1e3a8a;
        margin-bottom: 3px;
        letter-spacing: 0.5px;
      }
      .print-doc-header .doc-title {
        font-size: 16pt;
        font-weight: 900;
        color: #0f172a;
        margin: 0 0 3px 0;
        letter-spacing: 1px;
      }
      .print-doc-header .doc-meta {
        font-size: 8.5pt;
        color: #64748b;
      }

      .print-doc-footer {
        display: flex !important;
        justify-content: space-between;
        align-items: center;
        margin-top: 14px;
        padding-top: 6px;
        border-top: 1.5px solid #cbd5e1;
        font-size: 8.5pt;
        color: #475569;
      }

      /* Tree Diagram */
      .tree-diagram-canvas {
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        padding: 12px 6px !important;
        margin-bottom: 14px !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .tree-box {
        box-shadow: none !important;
      }
      .tree-box-root {
        background: #1e3a8a !important;
        color: #ffffff !important;
        border: 2px solid #1e3a8a !important;
      }
      .tree-box-manager {
        background: #f0fdfa !important;
        border: 2px solid #0d9488 !important;
      }
      .tree-box-officer {
        background: #ecfdf5 !important;
        border: 2px solid #059669 !important;
      }
      .tree-box-workers {
        background: #eef2ff !important;
        border: 2px solid #6366f1 !important;
      }
      .tree-box-committee {
        background: #eff6ff !important;
        border: 2px dashed #2563eb !important;
        box-shadow: none !important;
      }

      /* Plan Duties Table */
      .plan-section-subtitle {
        margin: 14px 0 8px 0 !important;
        font-size: 11pt !important;
        color: #0f172a !important;
        page-break-after: avoid !important;
        break-after: avoid !important;
      }
      .plan-duties-table {
        border: 1.5px solid #0f172a !important;
        margin-bottom: 14px !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .plan-duties-table th {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border: 1px solid #475569 !important;
        padding: 5px 7px !important;
        font-size: 9pt !important;
      }
      .plan-duties-table td {
        border: 1px solid #64748b !important;
        padding: 5px 7px !important;
        font-size: 8pt !important;
        line-height: 1.4 !important;
      }
      .plan-duties-table tr {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }

      /* Roster Grid */
      .roster-grid {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 8px !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        margin-bottom: 8px !important;
      }
      .roster-card {
        border: 1px solid #94a3b8 !important;
        padding: 6px 8px !important;
        box-shadow: none !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .roster-team-title {
        font-size: 9pt !important;
      }
      .roster-team-count {
        font-size: 7.5pt !important;
      }
      .roster-member-pill {
        font-size: 7.5pt !important;
        padding: 1px 3px !important;
        margin: 1px 2px 1px 0 !important;
      }
    }
  </style>
</head>
<body>
<header>
  <div>
    <a href="management_system.php?section=1.2&amp;year=<?= $year ?>">← 1.2 안전보건활동</a>
    <strong>1.2.3 안전보건활동계획서 예산 반영 및 조직 구성</strong>
    <span><?= $escape($user['name'] ?? '') ?></span>
  </div>
</header>

<main>
  <div class="heading">
    <div>
      <small>CSM 평가 영역 1 · 모듈 1.2 · 2점 만점</small>
      <h1>안전보건 예산 반영 및 조직 구성·권한 부여</h1>
      <p>안전보건활동계획서에 연간 예산이 적정하게 편성·집행되고 있으며, 명확한 안전보건조직과 작업중지권 등 권한이 실질적으로 부여되었는지를 종합 관리합니다.</p>
    </div>
    <form method="get" class="year-form">
      <input type="hidden" name="tab" value="<?= $escape($activeTab) ?>">
      <label for="score-year">관리 연도</label>
      <input id="score-year" type="number" name="year" min="2000" max="2100" value="<?= $year ?>" required>
      <button class="button">조회</button>
    </form>
  </div>

  <?php if ($message): ?>
    <div style="padding: 12px 18px; background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; color: #065f46; font-weight: 600; margin-bottom: 20px;">
      ✓ <?= $escape($message) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div style="padding: 12px 18px; background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b; font-weight: 600; margin-bottom: 20px;">
      ⚠ <?= $escape($error) ?>
    </div>
  <?php endif; ?>

  <!-- Top Banner: Status & Automatic Score -->
  <section class="score-banner-box">
    <div>
      <div style="font-size: 13px; color: #38bdf8; font-weight: 700; letter-spacing: 1px; margin-bottom: 4px;">CSM 1.2.3 EVALUATION CRITERIA</div>
      <h2 style="margin: 0 0 6px 0; font-size: 20px; color: #fff;">안전보건활동계획서 예산 반영 &amp; 안전보건조직 구성</h2>
      <p style="margin: 0; color: #cbd5e1; font-size: 12.5px;">
        원본 평가 기준: 예산 집행 실적 관리 및 조직 구성·권한 부여 완료 시 <strong>100% 만점 (2.0점)</strong> 부여
      </p>
      <div class="status-chips">
        <span class="chip <?= $status['has_org_roles'] ? 'active' : 'warn' ?>">
          <?= $status['has_org_roles'] ? '✓' : '!' ?> 조직 구성 (<?= count($data['organization']['roles'] ?? []) ?>명)
        </span>
        <span class="chip <?= $status['has_org_authority'] ? 'active' : 'warn' ?>">
          <?= $status['has_org_authority'] ? '✓' : '!' ?> 권한 부여 (작업중지권 등)
        </span>
        <span class="chip <?= $status['has_org_evidence'] ? 'active' : 'warn' ?>">
          <?= $status['has_org_evidence'] ? '✓' : '!' ?> 조직도/선임계 증빙 (<?= ($status['files_by_kind']['org_chart'] ?? 0) + ($status['files_by_kind']['appointment'] ?? 0) ?>건)
        </span>
        <span class="chip <?= $status['has_budget_items'] ? 'active' : 'warn' ?>">
          <?= $status['has_budget_items'] ? '✓' : '!' ?> 예산 편성 (<?= number_format($status['total_planned']) ?>원)
        </span>
        <span class="chip <?= $status['has_budget_evidence'] ? 'active' : 'warn' ?>">
          <?= $status['has_budget_evidence'] ? '✓' : '!' ?> 예산/집행 증빙 (<?= ($status['files_by_kind']['budget_plan'] ?? 0) + ($status['files_by_kind']['budget_ledger'] ?? 0) + ($status['files_by_kind']['budget_receipt'] ?? 0) ?>건)
        </span>
      </div>
    </div>
    <div class="score-badge-huge">
      <small>현재 반영 점수 (배점 2.0점)</small>
      <strong><?= number_format($itemScore['earned'], 1) ?>점</strong>
      <span style="font-size: 11.5px; font-weight: 700; color: <?= $itemScore['earned'] >= 2.0 ? '#34d399' : ($itemScore['earned'] > 0 ? '#fbbf24' : '#f87171') ?>;">
        <?= $escape($status['status_text']) ?>
      </span>
    </div>
  </section>

  <!-- Sub-Tabs Navigation -->
  <nav class="sub-tabs" aria-label="1.2.3 세부 관리 영역">
    <a href="?year=<?= $year ?>&amp;tab=org" class="<?= $activeTab === 'org' ? 'current' : '' ?>">1. 안전보건 조직 구성 및 권한 부여</a>
    <a href="?year=<?= $year ?>&amp;tab=budget" class="<?= $activeTab === 'budget' ? 'current' : '' ?>">2. 안전보건 예산 편성 및 집행 실적</a>
    <a href="?year=<?= $year ?>&amp;tab=files" class="<?= $activeTab === 'files' ? 'current' : '' ?>">3. 첨부 증빙 문서 관리 (<?= count($files) ?>건)</a>
  </nav>

  <!-- TAB 1: Organization & Authority -->
  <?php if ($activeTab === 'org'): ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_org_csrf']) ?>">
      <input type="hidden" name="action" value="save_all">

      <section class="panel org-info-form-section no-print" style="margin-bottom: 20px;">
        <div class="section-title">
          <h3>안전보건 조직 기본정보 및 권한 부여 명문화</h3>
          <span class="hint">대표자 및 관리감독자의 실질적인 안전지휘권 부여 필수</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-top: 14px;">
          <div>
            <label for="org_name" style="display: block; font-weight: 700; margin-bottom: 4px;">조직 명칭</label>
            <input id="org_name" type="text" name="org_name" value="<?= $escape($data['organization']['org_name'] ?? '(주)현대기전 안전보건관리 조직') ?>" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
          </div>
          <div>
            <label for="effective_date" style="display: block; font-weight: 700; margin-bottom: 4px;">조직 구성 및 발효일자</label>
            <input id="effective_date" type="date" name="effective_date" value="<?= $escape($data['organization']['effective_date'] ?? sprintf('%04d-01-01', $year)) ?>" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
          </div>
        </div>

        <div style="margin-top: 14px;">
          <label for="description" style="display: block; font-weight: 700; margin-bottom: 4px;">조직 구성 목적 및 방침</label>
          <textarea id="description" name="description" rows="2" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;"><?= $escape($data['organization']['description'] ?? '') ?></textarea>
        </div>

        <div style="margin-top: 16px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
          <strong style="display: block; margin-bottom: 8px; color: #1e293b;">실질적인 권한 부여 확인 (모회사 CSM 평가 필수 항목)</strong>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; font-size: 12.5px;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
              <input type="checkbox" name="auth_stop_work" value="1" <?= !empty($data['organization']['authorities_granted']['stop_work']) ? 'checked' : '' ?>>
              <span><strong>급박한 위험 시 즉각적인 작업중지권</strong> 부여</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
              <input type="checkbox" name="auth_corrective_action" value="1" <?= !empty($data['organization']['authorities_granted']['corrective_action']) ? 'checked' : '' ?>>
              <span><strong>유해위험요인 발견 시 시정명령 및 지휘권</strong> 부여</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
              <input type="checkbox" name="auth_budget_requisition" value="1" <?= !empty($data['organization']['authorities_granted']['budget_requisition']) ? 'checked' : '' ?>>
              <span><strong>안전보건 예산의 편성 요구 및 집행 품의권</strong> 보장</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
              <input type="checkbox" name="auth_regulation_enforced" value="1" <?= !empty($data['organization']['authorities_granted']['regulation_enforced']) ? 'checked' : '' ?>>
              <span><strong>안전보건관리규정 및 업무분장 명문화</strong> 완료</span>
            </label>
          </div>
        </div>
      </section>

      <!-- Roles Presentation (Visual Tree + Duties Table + Roster + Edit Mode) -->
      <section class="panel printable-org-section" style="margin-bottom: 20px;">
        <div class="section-title">
          <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
              3. 안전보건관리 조직 및 안전관계자의 책무
            </h3>
            <span class="hint">2026년 안전보건활동계획서 (제Ⅰ편 제3장) 기준 법정 조직체계 및 직무·권한 분장</span>
          </div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <a href="activity_organization_print.php?year=<?= $year ?>" target="_blank" rel="noopener" class="button print-btn" style="background: #1e3a8a; color: #fff; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 7px; text-decoration: none; cursor: pointer;">
              🖨️ 조직도 인쇄 / PDF 저장 ↗
            </a>
            <?php if ($canManage): ?>
              <button type="button" id="toggle-edit-btn" class="edit-toggle-btn" onclick="toggleEditMode()">
                ✏️ 조직원 직무·권한 편집하기
              </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- 1. ▣ 안전보건관리 조직도 (계획서 제1편 제3장 표준 도표) -->
        <div class="plan-org-wrapper">
          <!-- Print-Only Official Document Header -->
          <div class="print-doc-header">
            <div class="doc-corp">(주)현대기전 안전보건경영체계</div>
            <div class="doc-title">2026년 안전보건관리 조직 및 안전관계자의 책무</div>
            <div class="doc-meta">표준 규정: 2026년 안전보건활동계획서 (제Ⅰ편 제3장) | 발효일자: <?= $escape($data['organization']['effective_date'] ?? sprintf('%04d-01-01', $year)) ?></div>
          </div>

          <div class="plan-header-row">
            <h4 class="plan-header-title">
              <span style="color: #2563eb;">▣</span> 안전보건관리 조직도
            </h4>
            <span style="font-size: 12px; color: #64748b;">
              발효일자: <strong><?= $escape($data['organization']['effective_date'] ?? sprintf('%04d-01-01', $year)) ?></strong> | 표준 규정: 안전보건활동계획서 제1편 제3장
            </span>
          </div>

          <div class="tree-diagram-canvas">
            <!-- Level 1: 최고경영자 (안전보건관리책임자) -->
            <div class="tree-box tree-box-root">
              <div style="font-size: 11px; font-weight: 800; color: #bfdbfe; letter-spacing: 1px;">안전보건 총괄 책임자</div>
              <div style="font-size: 17px; font-weight: 900; margin: 4px 0 2px;">정연탁 대표이사</div>
              <div style="font-size: 12px; color: #dbeafe;">최고경영자 (안전보건관리책임자)</div>
              <div style="margin-top: 6px; padding-top: 6px; border-top: 1px dashed rgba(255,255,255,0.3); font-size: 11px; font-weight: 700; color: #fef08a;">
                ⚡ 전면 작업중지권 · 예산 최종 승인권
              </div>
            </div>

            <!-- Branch Area: 산업안전보건위원회 (좌측 분기) & 주 간선 수직선 -->
            <div class="tree-branch-area">
              <div class="tree-branch-trunk-line"></div>
              <div class="tree-branch-horiz-line"></div>
              <div class="tree-box-committee">
                <div style="font-size: 10px; font-weight: 800; color: #1d4ed8; letter-spacing: 0.5px;">심의·의결 기구</div>
                <div style="font-size: 13.5px; font-weight: 900; color: #1e3a8a; margin: 2px 0;">산업안전보건위원회</div>
                <div style="font-size: 11px; color: #475569;">위원장: <strong>정연탁</strong> 대표이사</div>
                <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;">(근로자대표 및 노사 동수 구성)</div>
              </div>
            </div>

            <!-- Level 2: 관리감독자 (부서장) -->
            <div class="tree-box tree-box-manager">
              <div style="font-size: 10.5px; font-weight: 800; color: #0d9488; letter-spacing: 0.5px;">현장 안전보건 지휘·감독</div>
              <div style="font-size: 16px; font-weight: 900; color: #0f172a; margin: 3px 0 1px;">진종철 부장</div>
              <div style="font-size: 12px; font-weight: 700; color: #0f766e;">관리감독자 (공사팀장)</div>
              <div style="margin-top: 6px; padding-top: 5px; border-top: 1px dashed #ccfbf1; font-size: 11px; font-weight: 700; color: #0d9488;">
                ⚡ 현장 즉각 작업중지권 · 시정명령권
              </div>
            </div>

            <div class="tree-connector-line"></div>

            <!-- Level 3: 안전담당자 -->
            <div class="tree-box tree-box-officer">
              <div style="font-size: 10.5px; font-weight: 800; color: #059669; letter-spacing: 0.5px;">안전보건 관리 실무 총괄</div>
              <div style="font-size: 16px; font-weight: 900; color: #0f172a; margin: 3px 0 1px;">김남균 과장</div>
              <div style="font-size: 12px; font-weight: 700; color: #047857;">안전담당자 (제조팀)</div>
              <div style="margin-top: 6px; padding-top: 5px; border-top: 1px dashed #d1fae5; font-size: 11px; font-weight: 700; color: #059669;">
                ⚡ 시정요구권 · 안전점검권
              </div>
            </div>

            <div class="tree-connector-line"></div>

            <!-- Level 4: 전 근로자 -->
            <div class="tree-box tree-box-workers">
              <div style="font-size: 10.5px; font-weight: 800; color: #4f46e5; letter-spacing: 0.5px;">안전수칙 준수 및 위험작업 중지 요청</div>
              <div style="font-size: 16px; font-weight: 900; color: #0f172a; margin: 3px 0 1px;">전 근 로 자</div>
              <div style="font-size: 12px; font-weight: 700; color: #4338ca;">
                공사팀(<?= count($currentRoster['teams']['const']['members'] ?? []) ?>명) · GAS팀(<?= count($currentRoster['teams']['gas']['members'] ?? []) ?>명) · 제조팀(<?= count($currentRoster['teams']['mfg']['members'] ?? []) ?>명) 등 재직 근로자 총 <?= $currentRoster['total'] ?>명
              </div>
              <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                근로자대표: <strong>윤택천</strong> 반장
              </div>
              <div style="margin-top: 6px; padding-top: 5px; border-top: 1px dashed #e0e7ff; font-size: 11px; font-weight: 700; color: #4f46e5;">
                ⚡ 급박한 위험 시 대피 및 작업중지 요청권 · 위험 개선의견 개진권
              </div>
            </div>
          </div>

          <!-- 2. ▣ 안전관계자의 책무 (계획서 제1편 제3장 표준 표) -->
          <div class="plan-section-subtitle">
            <span style="color: #2563eb;">▣</span> 안전관계자의 책무 (안전보건활동계획서 제1편 제3장)
          </div>

          <table class="plan-duties-table">
            <thead>
              <tr>
                <th style="width: 170px;">구 분</th>
                <th style="width: 140px;">직위 및 성명</th>
                <th>책무 및 법정 역할</th>
                <th style="width: 230px;">부여된 고유 권한 (CSM 핵심)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['organization']['roles'] ?? [] as $r):
                $title = $r['role_title'];
                $dutiesText = trim((string)($r['duties'] ?? ''));
                $dutyLines = preg_split('/\r\n|\r|\n/', $dutiesText);
              ?>
                <tr>
                  <td style="font-weight: 800; color: #0f172a; text-align: center; vertical-align: middle; background: #fafafa;">
                    <div style="font-size: 13.5px;"><?= $escape($title) ?></div>
                  </td>
                  <td style="text-align: center; vertical-align: middle;">
                    <div style="font-size: 14px; font-weight: 800; color: #1e293b;"><?= $escape($r['name']) ?></div>
                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;"><?= $escape($r['position']) ?></div>
                    <?php if (!empty($r['appointed_date'])): ?>
                      <div style="font-size: 10.5px; color: #94a3b8; margin-top: 4px;">선임: <?= $escape($r['appointed_date']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <ul class="duty-num-list">
                      <?php foreach ($dutyLines as $line):
                        $line = trim($line);
                        if ($line === '') continue;
                      ?>
                        <li><?= $escape($line) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </td>
                  <td style="background: #fff7ed; border-left: 2px solid #fdba74;">
                    <div style="font-size: 11px; font-weight: 800; color: #c2410c; margin-bottom: 4px;">⚡ 법정 고유 권한</div>
                    <div style="font-size: 12px; color: #9a3412; font-weight: 600; line-height: 1.5;">
                      <?= nl2br($escape($r['authority'])) ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <!-- 3. ▣ 부서별 인원 편성 현황 (현재 기준) -->
          <div class="plan-section-subtitle">
            <span style="color: #2563eb;">▣</span> 부서별 인원 편성 현황 (<?= $escape($currentRoster['formatted_date']) ?> 현재 기준 재직 총 <?= $currentRoster['total'] ?>명)
          </div>

          <div class="roster-grid">
            <?php foreach ($currentRoster['teams'] as $team): ?>
              <div class="roster-card <?= $escape($team['class']) ?>">
                <div class="roster-card-header">
                  <span class="roster-team-title"><?= $escape($team['title']) ?></span>
                  <span class="roster-team-count"><?= count($team['members']) ?>명</span>
                </div>
                <div>
                  <?php foreach ($team['members'] as $m): ?>
                    <span class="roster-member-pill <?= $m['leader'] ? 'leader' : '' ?>" <?= !empty($m['badge']) ? 'title="' . $escape($m['badge']) . '"' : '' ?>>
                      <?= $escape($m['pos']) ?> <?= $escape($m['name']) ?><?= !empty($m['badge']) ? ' (' . $escape($m['badge']) . ')' : '' ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Emergency Response Org Guidance Note (Hidden on Print) -->
          <div class="org-emergency-note" style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 16px; margin-top: 10px; display: flex; gap: 12px; align-items: center; font-size: 12px; color: #92400e;">
            <span style="font-size: 20px; flex-shrink: 0;">🚨</span>
            <div>
              <strong>비상대응 조직도 및 비상연락체계 별도 관리 안내:</strong><br>
              화재·누출·중대재해 등 비상사태 시 가동되는 비상대응 조직체계(비상대책본부장, 통제연락반, 대피유도반, 응급구호반, 초기소화반) 및 비상연락망은 평상시 안전보건관리체계(1.2.3)와 구분하여 
              <a href="area.php?group=2" style="color: #b45309; font-weight: 700; text-decoration: underline;">CSM 영역 2 [위험관리 &gt; 2.9 비상대응]</a> 항목에서 전용 시나리오·훈련과 함께 별도 관리됩니다.
            </div>
          </div>

          <!-- Print-Only Official Document Footer -->
          <div class="print-doc-footer">
            <span>주식회사 현대기전 안전보건관리체계 (CSM 1.2.3)</span>
            <span>발효일자: <?= $escape($data['organization']['effective_date'] ?? sprintf('%04d-01-01', $year)) ?> | 대표이사 정연탁 (직인생략)</span>
          </div>
        </div>

        <!-- Edit Form (Revealed via Toggle) -->
        <div id="org-edit-view" style="display: none; margin-top: 24px; padding: 20px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 12px;">
          <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #1e293b; font-weight: 800;">
            ✏️ 조직원 직무 및 권한 편집 모드
          </h4>
          <p class="hint" style="margin-top: 0; margin-bottom: 14px;">
            각 역할의 성명, 직위, 선임일자 및 직무/권한을 직접 수정하고 하단의 저장 버튼을 누르세요.
          </p>

          <div style="overflow-x: auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width: 140px;">조직상 구분</th>
                  <th style="width: 100px;">성명</th>
                  <th style="width: 120px;">소속 / 직위</th>
                  <th style="width: 110px;">선임일자</th>
                  <th>주요 직무 및 책임 (법정 직무)</th>
                  <th style="width: 220px;">부여된 고유 권한</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($data['organization']['roles'] ?? [] as $r): ?>
                  <tr>
                    <td><input type="text" name="role_title[]" value="<?= $escape($r['role_title']) ?>" required style="width:100%;"></td>
                    <td><input type="text" name="role_name[]" value="<?= $escape($r['name']) ?>" required style="width:100%;"></td>
                    <td><input type="text" name="role_position[]" value="<?= $escape($r['position']) ?>" style="width:100%;"></td>
                    <td><input type="date" name="role_appointed_date[]" value="<?= $escape($r['appointed_date']) ?>" style="width:100%;"></td>
                    <td><textarea name="role_duties[]" rows="5" style="width:100%; font-size:12px; font-family:inherit;"><?= $escape($r['duties']) ?></textarea></td>
                    <td><textarea name="role_authority[]" rows="5" style="width:100%; font-size:12px; font-family:inherit;"><?= $escape($r['authority']) ?></textarea></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Hidden Budget Preserves while on Tab 1 -->
          <input type="hidden" name="total_planned" value="<?= $escape($data['budget']['total_planned'] ?? 0) ?>">
          <input type="hidden" name="total_executed" value="<?= $escape($data['budget']['total_executed'] ?? 0) ?>">
          <?php foreach ($data['budget']['items'] ?? [] as $b): ?>
            <input type="hidden" name="b_code[]" value="<?= $escape($b['code']) ?>">
            <input type="hidden" name="b_name[]" value="<?= $escape($b['name']) ?>">
            <input type="hidden" name="b_planned[]" value="<?= $escape($b['planned']) ?>">
            <input type="hidden" name="b_executed[]" value="<?= $escape($b['executed']) ?>">
            <input type="hidden" name="b_note[]" value="<?= $escape($b['note'] ?? '') ?>">
          <?php endforeach; ?>

          <?php if ($canManage): ?>
            <div style="margin-top: 18px; display: flex; justify-content: flex-end; gap: 10px;">
              <button type="button" class="button" onclick="toggleEditMode()">취소</button>
              <button type="submit" class="button primary" style="padding: 10px 24px; font-weight: 700;">조직 구성 및 권한 설정 저장</button>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </form>
  <?php endif; ?>


  <!-- TAB 2: Budget & Execution -->
  <?php if ($activeTab === 'budget'): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_org_csrf']) ?>">
      <input type="hidden" name="action" value="save_all">

      <!-- Hidden Org Preserves while on Tab 2 -->
      <input type="hidden" name="org_name" value="<?= $escape($data['organization']['org_name'] ?? '') ?>">
      <input type="hidden" name="effective_date" value="<?= $escape($data['organization']['effective_date'] ?? '') ?>">
      <input type="hidden" name="description" value="<?= $escape($data['organization']['description'] ?? '') ?>">
      <input type="hidden" name="auth_stop_work" value="<?= !empty($data['organization']['authorities_granted']['stop_work']) ? '1' : '' ?>">
      <input type="hidden" name="auth_corrective_action" value="<?= !empty($data['organization']['authorities_granted']['corrective_action']) ? '1' : '' ?>">
      <input type="hidden" name="auth_budget_requisition" value="<?= !empty($data['organization']['authorities_granted']['budget_requisition']) ? '1' : '' ?>">
      <input type="hidden" name="auth_regulation_enforced" value="<?= !empty($data['organization']['authorities_granted']['regulation_enforced']) ? '1' : '' ?>">
      <?php foreach ($data['organization']['roles'] ?? [] as $r): ?>
        <input type="hidden" name="role_title[]" value="<?= $escape($r['role_title']) ?>">
        <input type="hidden" name="role_name[]" value="<?= $escape($r['name']) ?>">
        <input type="hidden" name="role_position[]" value="<?= $escape($r['position']) ?>">
        <input type="hidden" name="role_appointed_date[]" value="<?= $escape($r['appointed_date']) ?>">
        <input type="hidden" name="role_duties[]" value="<?= $escape($r['duties']) ?>">
        <input type="hidden" name="role_authority[]" value="<?= $escape($r['authority']) ?>">
      <?php endforeach; ?>

      <section class="panel" style="margin-bottom: 20px;">
        <div class="section-title">
          <h3>연간 안전보건 예산 총괄</h3>
          <div class="quick-links">
            <a href="../safety_manual/safety_budget.php" class="button" target="_blank" rel="noopener">안전보건 예산 원장 시스템 ↗</a>
          </div>
        </div>
        <p class="hint" style="margin: 8px 0 0;">편성액은 안전보건 예산 원장의 해당 연도 비목별 세부 편성액 합계입니다. 금액을 바꾸려면 예산 원장에서 수정하세요.</p>
        <?php if (($data['budget']['source'] ?? '') === 'unavailable'): ?>
          <p class="hint" style="color: #b91c1c;">예산 원장을 불러오지 못했습니다. 원장 연결 상태를 확인해 주세요.</p>
        <?php endif; ?>

        <?php
          $totPlan = (float)($data['budget']['total_planned'] ?? 0);
          $totExec = (float)($data['budget']['total_executed'] ?? 0);
          $rate = $totPlan > 0 ? round(($totExec / $totPlan) * 100, 1) : 0.0;
        ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 14px;">
          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
            <span style="font-size: 11.5px; color: #64748b; font-weight: 700;">연간 총 편성 예산 (계획)</span>
            <div style="font-size: 22px; font-weight: 800; color: #1e293b; margin-top: 4px;">
              <?= number_format($totPlan) ?>원
            </div>
            <input type="hidden" name="total_planned" value="<?= $totPlan ?>">
          </div>
          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
            <span style="font-size: 11.5px; color: #64748b; font-weight: 700;">현재 총 집행 실적</span>
            <div style="font-size: 22px; font-weight: 800; color: #059669; margin-top: 4px;">
              <?= number_format($totExec) ?>원
            </div>
            <input type="hidden" name="total_executed" value="<?= $totExec ?>">
          </div>
          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
            <span style="font-size: 11.5px; color: #64748b; font-weight: 700;">예산 집행률</span>
            <div style="font-size: 22px; font-weight: 800; color: #2563eb; margin-top: 4px;">
              <?= $rate ?>%
            </div>
            <div class="progress-bar-wrap">
              <div class="progress-bar-fill" style="width: <?= min(100.0, $rate) ?>%;"></div>
            </div>
          </div>
        </div>

        <!-- Breakdown Table -->
        <h4 style="margin: 22px 0 10px 0; font-size: 14px; color: #334155;">비목별 계획 대비 집행 실적표</h4>
        <div style="overflow-x: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 220px;">비목 구분</th>
                <th style="width: 140px;">편성액 (원)</th>
                <th style="width: 140px;">집행액 (원)</th>
                <th style="width: 90px;">집행률</th>
                <th>주요 집행 내역 및 비고</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['budget']['items'] ?? [] as $b):
                $p = (float)($b['planned'] ?? 0);
                $x = (float)($b['executed'] ?? 0);
                $r = $p > 0 ? round(($x / $p) * 100, 1) : 0.0;
              ?>
                <tr>
                  <td>
                    <input type="hidden" name="b_code[]" value="<?= $escape($b['code']) ?>">
                    <?= $escape($b['name']) ?>
                  </td>
                  <td>
                    <?= number_format($p) ?>
                  </td>
                  <td>
                    <?= number_format($x) ?>
                  </td>
                  <td style="text-align: center; font-weight: 700; color: #2563eb;">
                    <?= $r ?>%
                  </td>
                  <td>
                    <?= $escape($b['note'] ?? '') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </section>
    </form>
  <?php endif; ?>

  <!-- TAB 3: Evidence Files -->
  <?php if ($activeTab === 'files'): ?>
    <section class="panel" style="margin-bottom: 20px;">
      <div class="section-title">
        <h3>1.2.3 보관 증빙 서류 목록 (<?= count($files) ?>건)</h3>
        <span class="hint">조직도, 선임계, 권한부여서, 예산편성표 및 집행대장 보관</span>
      </div>

      <?php if (empty($files)): ?>
        <p class="empty" style="padding: 30px; text-align: center; color: #64748b;">
          등록된 증빙 문서가 없습니다. 아래 업로드 양식을 통해 조직도 및 예산 증빙을 등록해 주세요.
        </p>
      <?php else: ?>
        <div class="file-grid">
          <?php foreach ($files as $f): 
            $kind = $f['file_kind'] ?: 'evidence';
            $kindLabel = activity_org_file_kinds()[$kind] ?? '기타 증빙';
            $sizeKb = round((int)$f['size'] / 1024, 1);
          ?>
            <div class="file-card">
              <span class="file-kind-badge"><?= $escape($kindLabel) ?></span>
              <div style="font-weight: 700; font-size: 13.5px; color: #1e293b; word-break: break-all;">
                📄 <?= $escape($f['original_name']) ?>
              </div>
              <div style="font-size: 11px; color: #64748b;">
                크기: <?= $sizeKb ?> KB
              </div>
              <div class="file-actions">
                <button type="button" class="button" style="padding: 4px 10px; font-size: 11px;" onclick="openDocViewer('activity_organization.php?year=<?= $year ?>&amp;file=<?= $f['id'] ?>&amp;inline=1', '증빙 문서: <?= $escape($f['original_name']) ?>', '<?= $escape($f['original_name']) ?>')">
                  🔍 바로보기
                </button>
                <a href="activity_organization.php?year=<?= $year ?>&amp;file=<?= $f['id'] ?>" class="button" style="padding: 4px 10px; font-size: 11px;">
                  ⬇ 다운로드
                </a>
                <?php if ($canManage): ?>
                  <form method="post" style="margin-left: auto;" onsubmit="return confirm('해당 증빙 문서를 삭제하시겠습니까?');">
                    <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_org_csrf']) ?>">
                    <input type="hidden" name="action" value="delete_file">
                    <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                    <button type="submit" class="button" style="padding: 4px 8px; font-size: 11px; color: #dc2626; border-color: #fca5a5;">
                      삭제
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Upload Form -->
    <?php if ($canManage): ?>
      <section class="panel" style="margin-bottom: 20px;">
        <div class="section-title">
          <h3>새 증빙 파일 등록</h3>
          <span class="hint">PDF, XLSX, JPG, PNG 파일 업로드 가능</span>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top: 14px;">
          <input type="hidden" name="csrf" value="<?= $escape($_SESSION['activity_org_csrf']) ?>">
          <input type="hidden" name="action" value="upload_file">

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; align-items: end;">
            <div>
              <label for="file_kind" style="display: block; font-weight: 700; margin-bottom: 4px;">증빙 분류 선택</label>
              <select id="file_kind" name="file_kind" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit;">
                <?php foreach (activity_org_file_kinds() as $k => $lbl): ?>
                  <option value="<?= $escape($k) ?>"><?= $escape($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="evidence" style="display: block; font-weight: 700; margin-bottom: 4px;">파일 선택</label>
              <input id="evidence" type="file" name="evidence[]" multiple accept=".pdf,.xlsx,.jpg,.jpeg,.png" style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px;" required>
            </div>
            <div>
              <button type="submit" class="button primary" style="padding: 8px 20px; font-weight: 700; height: 38px;">증빙 파일 업로드</button>
            </div>
          </div>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>

</main>

<!-- Inline Document Viewer Modal -->
<div id="doc-viewer-modal" onclick="if(event.target === this) closeDocViewer();">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="doc-viewer-title">증빙 문서 미리보기</h3>
      <button type="button" class="modal-close-btn" onclick="closeDocViewer()">✕</button>
    </div>
    <div class="modal-body">
      <iframe id="doc-viewer-frame" src="about:blank"></iframe>
    </div>
  </div>
</div>

<script>
function toggleEditMode() {
  var editEl = document.getElementById('org-edit-view');
  var btn = document.getElementById('toggle-edit-btn');
  if (!editEl || !btn) return;
  if (editEl.style.display === 'none' || editEl.style.display === '') {
    editEl.style.display = 'block';
    btn.textContent = '✕ 편집 닫기 (시각화 뷰만 보기)';
    btn.classList.add('active-edit');
    editEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } else {
    editEl.style.display = 'none';
    btn.textContent = '✏️ 조직원 직무·권한 편집하기';
    btn.classList.remove('active-edit');
  }
}

function openDocViewer(url, title, filename) {
  document.getElementById('doc-viewer-title').textContent = title;
  var frame = document.getElementById('doc-viewer-frame');
  frame.src = url;
  document.getElementById('doc-viewer-modal').style.display = 'flex';
}
function closeDocViewer() {
  var frame = document.getElementById('doc-viewer-frame');
  frame.src = 'about:blank';
  document.getElementById('doc-viewer-modal').style.display = 'none';
}
</script>
</body>
</html>
