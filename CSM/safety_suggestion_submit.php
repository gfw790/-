<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/worker_participation_store.php';

$user = csm_current_user();
$db = worker_part_db();
$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$currentYear = (int)substr($today, 0, 4);

$submitted = false;
$isUpdated = false;
$assignedRegNo = '';
$newId = 0;
$error = '';

$editId = filter_var($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0, FILTER_VALIDATE_INT);
if ($editId === false || $editId < 0) {
    $editId = 0;
}
$isEditing = false;
$editingRecord = null;
if ($editId > 0) {
    $findStmt = $db->prepare('SELECT * FROM csm_suggestions WHERE id = ?');
    $findStmt->execute([$editId]);
    $editingRecord = $findStmt->fetch(PDO::FETCH_ASSOC);
    if ($editingRecord) {
        $isEditing = true;
    } else {
        $editId = 0;
    }
}

// Quick Search by Reg No via GET (?search_reg=...)
$searchReg = trim($_GET['search_reg'] ?? '');
if ($searchReg !== '' && !$isEditing) {
    $sStmt = $db->prepare('SELECT * FROM csm_suggestions WHERE reg_no = ? LIMIT 1');
    $sStmt->execute([$searchReg]);
    $found = $sStmt->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        header('Location: safety_suggestion_submit.php?edit_id=' . $found['id']);
        exit;
    } else {
        $error = '입력하신 접수번호 [' . $searchReg . ']에 해당하는 안전제안을 찾을 수 없습니다.';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $proposer_name = trim($_POST['proposer_name'] ?? '');
        $proposer_dept = trim($_POST['proposer_dept'] ?? '');
        $proposer_phone = trim($_POST['proposer_phone'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '미분류(접수자 분류)');
        if ($category === '') {
            $category = '미분류(접수자 분류)';
        }
        $location = trim($_POST['location'] ?? '');
        $problem = trim($_POST['problem'] ?? '');
        $solution = trim($_POST['solution'] ?? '');
        $expected_effect = trim($_POST['expected_effect'] ?? '');
        $signature_data = trim($_POST['signature_data'] ?? '');
        $removePhoto = (int)($_POST['remove_photo'] ?? 0);

        if ($proposer_name === '') {
            throw new InvalidArgumentException('제안자 성명을 입력해주세요.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('제안 제목을 입력해주세요.');
        }
        if ($problem === '') {
            throw new InvalidArgumentException('현장의 위험요인 또는 문제점을 입력해주세요.');
        }

        // Process Photo Upload (Base64 from canvas or Clipboard / Fallback File Upload)
        $photoPath = worker_part_save_uploaded_image(
            $_POST['photo_base64'] ?? '',
            $_FILES['photo_file'] ?? null,
            'sug'
        );

        $now = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($isEditing && $editingRecord) {
            // UPDATE EXISTING SUGGESTION (수정 및 사진 보완)
            $assignedRegNo = $editingRecord['reg_no'];
            $newId = $editId;

            $finalSign = $signature_data;
            if ($finalSign === '') {
                $finalSign = $editingRecord['proposer_sign'] ?: worker_part_make_signature_svg($proposer_name, '#059669');
            }

            $stmt = $db->prepare('UPDATE csm_suggestions SET
                proposer_name = ?, proposer_dept = ?, proposer_phone = ?,
                title = ?, location = ?, problem = ?, solution = ?, expected_effect = ?,
                proposer_sign = ?, updated_at = ?
                WHERE id = ?');
            $stmt->execute([
                $proposer_name, $proposer_dept, $proposer_phone,
                $title, $location, $problem, $solution, $expected_effect,
                $finalSign, $now, $editId
            ]);

            if ($removePhoto) {
                $db->prepare('UPDATE csm_suggestions SET photo_before = NULL WHERE id = ?')->execute([$editId]);
            } elseif ($photoPath !== null) {
                $db->prepare('UPDATE csm_suggestions SET photo_before = ? WHERE id = ?')->execute([$photoPath, $editId]);
            }

            worker_part_save_signature($db, 'suggestion', $editId, 'proposer', $proposer_name, $finalSign, $ip);

            $submitted = true;
            $isUpdated = true;
        } else {
            // INSERT NEW SUGGESTION (신규 접수)
            $countStmt = $db->prepare('SELECT COUNT(*) FROM csm_suggestions WHERE year = ?');
            $countStmt->execute([$currentYear]);
            $nextNum = (int)$countStmt->fetchColumn() + 1;
            $assignedRegNo = sprintf('%d-S%03d', $currentYear, $nextNum);

            $proposer_login_id = trim($_POST['proposer_login_id'] ?? '') ?: null;
            $submission_channel = trim($_POST['submission_channel'] ?? '모바일접수');
            $proxy_writer_name = trim($_POST['proxy_writer_name'] ?? '') ?: null;

            // Prepare Proposer Signature
            $finalSign = $signature_data;
            if ($finalSign === '') {
                $finalSign = worker_part_make_signature_svg($proposer_name, '#059669');
            }

            $stmt = $db->prepare('INSERT INTO csm_suggestions (
                year, reg_no, reg_date, proposer_name, proposer_dept, proposer_phone,
                proposer_login_id, submission_channel, proxy_writer_name,
                title, category, location, problem, solution, expected_effect,
                status, photo_before, proposer_sign, is_mobile_submit, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                "접수", ?, ?, 1, ?, ?
            )');
            $stmt->execute([
                $currentYear, $assignedRegNo, $today, $proposer_name, $proposer_dept, $proposer_phone,
                $proposer_login_id, $submission_channel, $proxy_writer_name,
                $title, $category, $location, $problem, $solution, $expected_effect,
                $photoPath, $finalSign, $now, $now
            ]);
            $newId = (int)$db->lastInsertId();

            // Audit Signature
            worker_part_save_signature($db, 'suggestion', $newId, 'proposer', $proposer_name, $finalSign, $ip);

            $submitted = true;
            $isUpdated = false;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Logged-in user account info (auto-fill proposer)
$loggedUserLoginId = trim((string)($user['login_id'] ?? ''));
$loggedUserName = trim((string)($user['name'] ?? ''));
$loggedUserDept = trim((string)($user['team'] ?? $user['team_name'] ?? $user['department'] ?? ''));
$loggedUserPhone = trim((string)($user['phone'] ?? ''));
if ($loggedUserLoginId !== '' && function_exists('auth_accounts')) {
    $allAccs = auth_accounts();
    if (isset($allAccs[$loggedUserLoginId]) && is_array($allAccs[$loggedUserLoginId])) {
        $me = $allAccs[$loggedUserLoginId];
        if ($loggedUserName === '') $loggedUserName = trim((string)($me['name'] ?? ''));
        if ($loggedUserDept === '') $loggedUserDept = trim((string)($me['team'] ?? $me['team_name'] ?? $me['department'] ?? ''));
        if ($loggedUserPhone === '') $loggedUserPhone = trim((string)($me['phone'] ?? ''));
    }
}
$isLoggedInUser = ($loggedUserName !== '');

// Prefill values
$prefillName = $isEditing ? ($editingRecord['proposer_name'] ?? '') : $loggedUserName;
$prefillDept = $isEditing ? ($editingRecord['proposer_dept'] ?? '') : $loggedUserDept;
$prefillPhone = $isEditing ? ($editingRecord['proposer_phone'] ?? '') : $loggedUserPhone;
$prefillLoginId = $isEditing ? ($editingRecord['proposer_login_id'] ?? '') : $loggedUserLoginId;
$prefillTitle = $isEditing ? ($editingRecord['title'] ?? '') : '';
$prefillLocation = $isEditing ? ($editingRecord['location'] ?? '') : '';
$prefillProblem = $isEditing ? ($editingRecord['problem'] ?? '') : '';
$prefillSolution = $isEditing ? ($editingRecord['solution'] ?? '') : '';
$prefillExpectedEffect = $isEditing ? ($editingRecord['expected_effect'] ?? '') : '';
$prefillPhoto = $isEditing ? worker_part_resolve_photo_data($editingRecord['photo_before'] ?? '') : '';
$prefillCategory = $isEditing ? ($editingRecord['category'] ?? '미분류(접수자 분류)') : '미분류(접수자 분류)';
$userAccounts = worker_part_get_user_accounts(true);
$activeTeams = worker_part_get_teams();

// Worker Self-Lookup Helper & JSON API (?api=my_suggestions)
$formatSuggestionForWorker = static function(array $r): array {
    return [
        'id' => (int)$r['id'],
        'reg_no' => (string)($r['reg_no'] ?? ''),
        'reg_date' => (string)($r['reg_date'] ?? ''),
        'proposer_name' => worker_part_clean_person_name($r['proposer_name'] ?? ''),
        'proposer_dept' => (string)($r['proposer_dept'] ?? ''),
        'submission_channel' => (string)($r['submission_channel'] ?? '직접등록'),
        'proxy_writer_name' => worker_part_clean_person_name($r['proxy_writer_name'] ?? ''),
        'title' => (string)($r['title'] ?? ''),
        'category' => (string)($r['category'] ?? ''),
        'location' => (string)($r['location'] ?? ''),
        'problem' => (string)($r['problem'] ?? ''),
        'solution' => (string)($r['solution'] ?? ''),
        'expected_effect' => (string)($r['expected_effect'] ?? ''),
        'status' => (string)($r['status'] ?? '접수'),
        'action_manager' => worker_part_clean_person_name($r['action_manager'] ?? ''),
        'action_date' => (string)($r['action_date'] ?? ''),
        'action_deadline' => (string)($r['action_deadline'] ?? ''),
        'action_result' => (string)($r['action_result'] ?? ''),
        'feedback_date' => (string)($r['feedback_date'] ?? ''),
        'reward_points' => (int)($r['reward_points'] ?: ($r['eval_score'] ?: 0)),
        'reviewer_name' => worker_part_clean_person_name($r['reviewer_name'] ?? ''),
        'review_opinion' => (string)($r['review_opinion'] ?: ($r['eval_summary'] ?? '')),
        'photo_before' => worker_part_resolve_photo_data($r['photo_before'] ?? ''),
        'photo_after' => worker_part_resolve_photo_data($r['photo_after'] ?? ''),
        'photo_before_desc' => (string)($r['photo_before_desc'] ?? ''),
        'photo_after_desc' => (string)($r['photo_after_desc'] ?? ''),
    ];
};

$extractLast4 = static function(?string $phone): string {
    $digits = preg_replace('/\D+/', '', (string)($phone ?? ''));
    return strlen($digits) >= 4 ? substr($digits, -4) : '';
};

// Build lookup map of account phones by login_id and clean name (for proxy lookup verification)
$accountPhonesByLogin = [];
$accountPhonesByName = [];
if (function_exists('auth_accounts')) {
    foreach (auth_accounts() as $lid => $acc) {
        $l4 = $extractLast4((string)($acc['phone'] ?? ''));
        if ($l4 !== '') {
            $accountPhonesByLogin[(string)$lid] = $l4;
            $cn = worker_part_clean_person_name((string)($acc['name'] ?? ''));
            if ($cn !== '') {
                $accountPhonesByName[$cn][$l4] = true;
            }
        }
    }
}

if (($_GET['api'] ?? '') === 'my_suggestions') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $qReg = trim((string)($_GET['reg_no'] ?? ''));
    $qName = worker_part_clean_person_name((string)($_GET['name'] ?? ''));
    $qPhone4 = preg_replace('/\D+/', '', (string)($_GET['phone4'] ?? ''));
    if (strlen($qPhone4) > 4) {
        $qPhone4 = substr($qPhone4, -4);
    }

    if ($qReg === '' && ($qName === '' || strlen($qPhone4) !== 4)) {
        echo json_encode(['ok' => false, 'error' => '접수번호 또는 성명과 휴대폰 뒤 4자리를 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allRows = $db->query('SELECT * FROM csm_suggestions ORDER BY reg_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $matched = [];
    foreach ($allRows as $r) {
        if ($qReg !== '') {
            if (strcasecmp(trim((string)($r['reg_no'] ?? '')), $qReg) === 0) {
                $matched[] = $formatSuggestionForWorker($r);
            }
            continue;
        }
        $rowCleanName = worker_part_clean_person_name((string)($r['proposer_name'] ?? ''));
        if ($rowCleanName === '' || $rowCleanName !== $qName) {
            continue;
        }
        $rowPhone4 = $extractLast4((string)($r['proposer_phone'] ?? ''));
        $rowLoginId = trim((string)($r['proposer_login_id'] ?? ''));
        $accPhone4 = ($rowLoginId !== '' && isset($accountPhonesByLogin[$rowLoginId])) ? $accountPhonesByLogin[$rowLoginId] : '';
        $namePhoneMatch = isset($accountPhonesByName[$rowCleanName][$qPhone4]);
        if ($rowPhone4 === $qPhone4 || $accPhone4 === $qPhone4 || $namePhoneMatch) {
            $matched[] = $formatSuggestionForWorker($r);
        }
    }

    echo json_encode(['ok' => true, 'items' => $matched], JSON_UNESCAPED_UNICODE);
    exit;
}

// Preload logged-in worker's own submitted suggestions (including proxy submissions under their name/account)
$mySuggestions = [];
if ($isLoggedInUser) {
    $myCleanName = worker_part_clean_person_name($loggedUserName);
    $allRows = $db->query('SELECT * FROM csm_suggestions ORDER BY reg_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allRows as $r) {
        $rowLoginId = trim((string)($r['proposer_login_id'] ?? ''));
        $rowCleanName = worker_part_clean_person_name((string)($r['proposer_name'] ?? ''));
        if (($loggedUserLoginId !== '' && $rowLoginId === $loggedUserLoginId)
            || ($myCleanName !== '' && $rowCleanName === $myCleanName)) {
            $mySuggestions[] = $formatSuggestionForWorker($r);
        }
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <title>산업안전보건 안전제안 접수창구 · 현대기전 CSM</title>
  <link rel="stylesheet" href="worker_participation.css">
  <style>
    :root {
      --m-primary: #1e3a8a;
      --m-accent: #0284c7;
      --m-success: #059669;
      --m-bg: #f1f5f9;
      --m-card: #ffffff;
      --m-text: #0f172a;
      --m-muted: #64748b;
      --m-border: #cbd5e1;
    }
    body {
      background: var(--m-bg);
      color: var(--m-text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Pretendard", sans-serif;
      margin: 0;
      padding: 0;
      line-height: 1.5;
    }
    .m-shell {
      max-width: 560px;
      margin: 0 auto;
      min-height: 100vh;
      background: #ffffff;
      box-shadow: 0 0 25px rgba(0,0,0,0.06);
      display: flex;
      flex-direction: column;
    }
    /* Mobile Top Header */
    .m-header {
      background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 60%, #0369a1 100%);
      color: #ffffff;
      padding: 24px 20px 20px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 4px 12px rgba(30, 58, 138, 0.15);
    }
    .m-header-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .m-brand {
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: #bfdbfe;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .m-header-title {
      font-size: 21px;
      font-weight: 800;
      margin: 0 0 4px 0;
      letter-spacing: -0.5px;
    }
    .m-header-desc {
      font-size: 13px;
      color: #e0e7ff;
      margin: 0;
      opacity: 0.95;
    }
    .m-slogan-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255, 255, 255, 0.18);
      backdrop-filter: blur(4px);
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11.5px;
      font-weight: 600;
      margin-top: 10px;
    }

    /* Form Content */
    .m-content {
      padding: 20px;
      flex: 1;
    }
    .m-section-title {
      font-size: 15px;
      font-weight: 800;
      color: var(--m-primary);
      margin: 22px 0 10px 0;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .m-section-title:first-child {
      margin-top: 0;
    }
    .m-group {
      margin-bottom: 16px;
    }
    .m-label {
      display: block;
      font-size: 13.5px;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 6px;
    }
    .m-label .req {
      color: #dc2626;
      margin-left: 2px;
    }
    .m-input, .m-select, .m-textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 14px;
      border: 1.5px solid var(--m-border);
      border-radius: 10px;
      font-size: 15px;
      background: #f8fafc;
      color: #0f172a;
      transition: all 0.2s;
    }
    .m-input:focus, .m-select:focus, .m-textarea:focus {
      outline: none;
      border-color: var(--m-accent);
      background: #ffffff;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
    }
    .m-textarea {
      min-height: 85px;
      resize: vertical;
      line-height: 1.5;
    }
    .m-help {
      font-size: 12px;
      color: var(--m-muted);
      margin-top: 5px;
    }

    /* Category Buttons Grid */
    .m-cat-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
      margin-top: 6px;
    }
    .m-cat-btn {
      padding: 10px 4px;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      background: #f8fafc;
      color: #334155;
      font-size: 12.5px;
      font-weight: 600;
      text-align: center;
      cursor: pointer;
      transition: all 0.15s ease;
      user-select: none;
    }
    .m-cat-btn.active {
      background: var(--m-primary);
      border-color: var(--m-primary);
      color: #ffffff;
      box-shadow: 0 2px 6px rgba(30, 58, 138, 0.25);
    }

    /* Dept Quick Pills */
    .m-pills-row {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
    }
    .m-pill {
      padding: 6px 12px;
      background: #e2e8f0;
      color: #334155;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s;
    }
    .m-pill:hover, .m-pill.active {
      background: #0369a1;
      color: #ffffff;
    }

    /* Camera Upload Box */
    .m-photo-box {
      border: 2px dashed #94a3b8;
      border-radius: 12px;
      padding: 18px;
      text-align: center;
      background: #f8fafc;
      cursor: pointer;
      transition: all 0.2s;
    }
    .m-photo-box:hover {
      border-color: var(--m-accent);
      background: #f0f9ff;
    }
    .m-photo-box.dragover {
      border-color: #0284c7;
      background: #e0f2fe;
      transform: scale(1.01);
    }
    .m-btn-paste {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ffffff;
      color: #1e3a8a;
      border: 1.5px solid #0284c7;
      padding: 7px 14px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
      transition: all 0.15s;
    }
    .m-btn-paste:hover {
      background: #f0f9ff;
      border-color: #0369a1;
      color: #0369a1;
    }
    .m-photo-preview {
      display: none;
      position: relative;
      margin-top: 10px;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #cbd5e1;
    }
    .m-photo-preview img {
      width: 100%;
      max-height: 240px;
      object-fit: cover;
      display: block;
    }
    .m-photo-remove {
      position: absolute;
      top: 8px;
      right: 8px;
      background: rgba(220, 38, 38, 0.85);
      color: #ffffff;
      border: none;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
    }

    /* Signature Box */
    .m-sig-container {
      border: 1.5px solid #cbd5e1;
      border-radius: 10px;
      background: #ffffff;
      padding: 8px;
      text-align: center;
    }
    canvas#mSigCanvas {
      touch-action: none;
      background: #ffffff;
      border: 1px dashed #cbd5e1;
      border-radius: 6px;
      cursor: crosshair;
      display: block;
      margin: 0 auto;
      max-width: 100%;
    }
    .m-sig-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 8px;
    }
    .m-btn-sm {
      padding: 6px 12px;
      font-size: 12px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #334155;
      font-weight: 600;
      cursor: pointer;
    }

    /* Submit Button */
    .m-submit-bar {
      margin-top: 28px;
      margin-bottom: 20px;
    }
    .m-btn-submit {
      width: 100%;
      padding: 16px;
      background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%);
      color: #ffffff;
      font-size: 17px;
      font-weight: 800;
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: transform 0.1s, box-shadow 0.2s;
    }
    .m-btn-submit:active {
      transform: scale(0.98);
      box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35);
    }

    /* Success Card */
    .m-success-card {
      text-align: center;
      padding: 40px 20px;
    }
    .m-success-icon {
      font-size: 56px;
      line-height: 1;
      margin-bottom: 16px;
    }
    .m-success-reg {
      display: inline-block;
      background: #eff6ff;
      color: #1e3a8a;
      border: 1px solid #bfdbfe;
      padding: 8px 18px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 17px;
      margin: 12px 0 20px;
    }

    /* Footer */
    .m-footer {
      text-align: center;
      padding: 18px 20px;
      font-size: 11.5px;
      color: var(--m-muted);
      border-top: 1px solid #e2e8f0;
      background: #f8fafc;
    }

    /* My Suggestions Modal */
    .ms-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.62);
      z-index: 1200;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 14px;
    }
    .ms-modal {
      width: 100%;
      max-width: 560px;
      max-height: 90vh;
      background: #ffffff;
      border-radius: 16px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: 0 20px 50px rgba(0,0,0,0.3);
    }
    .ms-header {
      background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
      color: #ffffff;
      padding: 15px 18px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .ms-body {
      padding: 16px;
      overflow-y: auto;
      background: #f1f5f9;
      flex: 1;
    }
    .ms-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      margin-bottom: 12px;
      overflow: hidden;
      box-shadow: 0 2px 6px rgba(15,23,42,0.04);
    }
    .ms-card-head {
      padding: 13px 14px;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .ms-card-head:hover {
      background: #f8fafc;
    }
    .ms-card-detail {
      display: none;
      padding: 12px 14px 14px;
      border-top: 1px dashed #cbd5e1;
      background: #f8fafc;
      font-size: 13px;
      color: #1e293b;
      line-height: 1.55;
    }
    .ms-card.open .ms-card-detail {
      display: block;
    }
    .ms-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
    }
    .ms-badge-done { background: #dcfce7; color: #166534; }
    .ms-badge-progress { background: #dbeafe; color: #1e40af; }
    .ms-badge-pending { background: #fef3c7; color: #92400e; }
    .ms-badge-reject { background: #fee2e2; color: #991b1b; }
    .ms-photo-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin-top: 10px;
    }
    .ms-photo-box {
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      overflow: hidden;
      background: #ffffff;
    }
    .ms-photo-box img {
      width: 100%;
      height: 120px;
      object-fit: cover;
      display: block;
      cursor: zoom-in;
    }
  </style>
</head>
<body>

<div class="m-shell">
  <!-- Top Header -->
  <header class="m-header">
    <div class="m-header-top">
      <div class="m-brand">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        주식회사 현대기전 · CSM
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button type="button" onclick="openMySuggestionsModal()" style="background:rgba(255,255,255,0.22);border:1px solid rgba(255,255,255,0.45);color:#ffffff;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px;cursor:pointer;">
          📋 내 제안 조회<?= $isLoggedInUser ? ' (' . count($mySuggestions) . ')' : '' ?>
        </button>
        <a href="worker_participation.php?tab=suggestions" style="color:#ffffff;text-decoration:none;font-size:12px;opacity:0.9;">관리대장</a>
      </div>
    </div>
    <h1 class="m-header-title"><?= $isEditing ? '안전제안 수정 및 사진 보완' : '산업안전보건 안전제안 접수' ?></h1>
    <p class="m-header-desc"><?= $isEditing ? '누락된 현장 사진을 추가하거나 작성 내용을 수정합니다.' : '현장의 유해·위험요인과 개선 아이디어를 등록해주세요.' ?></p>
    <div class="m-slogan-pill">
      <span>🛡️</span>
      <span><strong>안전은 행동입니다!</strong> 작은 제안이 안전한 현장을 만듭니다.</span>
    </div>
  </header>

  <?php if ($submitted): ?>
    <!-- SUCCESS VIEW -->
    <div class="m-content">
      <div class="m-success-card">
        <?php if (!empty($isUpdated)): ?>
          <div class="m-success-icon">✨</div>
          <h2 style="font-size:22px;color:var(--m-primary);margin:0 0 8px 0;">안전제안이 보완·수정되었습니다!</h2>
          <p style="font-size:14px;color:#475569;margin:0 0 14px 0;">
            추가 첨부하신 현장 사진과 수정 내용이 관리대장에 실시간 업데이트되었습니다.
          </p>
        <?php else: ?>
          <div class="m-success-icon">🎉</div>
          <h2 style="font-size:22px;color:var(--m-primary);margin:0 0 8px 0;">안전제안이 접수되었습니다!</h2>
          <p style="font-size:14px;color:#475569;margin:0 0 14px 0;">
            작성해주신 소중한 제안은 안전관리팀 및 산업안전보건위원회에 실시간 전달되었습니다.
          </p>
        <?php endif; ?>

        <div>
          <span style="font-size:13px;color:var(--m-muted);">접수번호</span><br>
          <div class="m-success-reg"><?= $escape($assignedRegNo) ?></div>
        </div>

        <div style="background:#f0f9ff;border-left:4px solid var(--m-accent);padding:14px 16px;text-align:left;border-radius:6px;margin:16px 0 22px;font-size:13.5px;color:#1e3a8a;line-height:1.5;">
          <strong>💡 사진 누락 또는 추가 보완이 필요하신가요?</strong><br>
          접수할 때 현장 사진을 깜빡하셨거나 내용을 고치고 싶으시면 아래 <strong>[📷 사진 추가 / 내용 수정하기]</strong> 버튼을 누르면 언제든지 사진을 추가하거나 수정하실 수 있습니다.
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;">
          <a href="safety_suggestion_submit.php?edit_id=<?= $newId ?>" class="m-btn-submit" style="background:#ffffff;color:var(--m-primary);border:2px solid var(--m-accent);text-decoration:none;box-shadow:none;">
            📷 방금 접수한 제안 사진 추가 / 내용 수정하기
          </a>
          <a href="safety_suggestion_submit.php" class="m-btn-submit" style="text-decoration:none;">
            + 새로운 안전제안 추가 작성
          </a>
          <a href="worker_participation.php?tab=suggestions" style="text-align:center;font-size:13px;color:#64748b;margin-top:4px;text-decoration:none;">
            ← 안전제안 관리대장으로 이동
          </a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- FORM VIEW -->
    <div class="m-content">
      <?php if ($error): ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:8px;font-size:13.5px;font-weight:600;margin-bottom:16px;">
          ⚠️ <?= $escape($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($isEditing && $editingRecord): ?>
        <div style="background:#eff6ff;border:1.5px solid #bfdbfe;color:#1e3a8a;padding:12px 14px;border-radius:10px;margin-bottom:18px;font-size:13px;line-height:1.5;">
          <div style="font-weight:700;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;">
            <span>✏️ 제안 보완·수정 모드 [접수번호: <?= $escape($editingRecord['reg_no']) ?>]</span>
            <a href="safety_suggestion_submit.php" style="font-size:11.5px;color:#dc2626;text-decoration:none;">✕ 신규작성으로 전환</a>
          </div>
          누락되었던 현장 사진을 첨부(클립보드 붙여넣기 또는 카메라 촬영)하시거나 기존 작성 내용을 수정한 뒤 하단의 <strong>[수정 내용 및 사진 저장하기]</strong>를 눌러주세요.
        </div>
      <?php endif; ?>

      <form method="post" id="formSuggestion" enctype="multipart/form-data">
        <input type="hidden" name="edit_id" value="<?= (int)$editId ?>">
        <input type="hidden" name="remove_photo" id="removePhotoInput" value="0">
        <input type="hidden" name="category" id="catInput" value="<?= $escape($prefillCategory) ?>">
        <input type="hidden" name="photo_base64" id="photoBase64" value="">
        <input type="hidden" name="signature_data" id="sigDataInput" value="">
        <input type="hidden" name="proposer_login_id" id="propLoginId" value="<?= $escape($prefillLoginId) ?>">
        <input type="hidden" name="submission_channel" id="subChannel" value="<?= $isEditing ? $escape($editingRecord['submission_channel'] ?? '모바일수정') : '모바일접수' ?>">
        <input type="hidden" name="proxy_writer_name" id="proxyWriterName" value="<?= $escape($user['name'] ?? '안전관리자') ?>">

        <!-- Verbal Proxy Mode Toggle (안전관리자/동료 대신 접수) -->
        <div id="proxyCard" style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:12px 14px;margin-bottom:18px;<?= $isEditing ? 'display:none;' : '' ?>">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <label style="font-size:13.5px;font-weight:700;color:#166534;display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
              <input type="checkbox" id="chkProxyMode" onchange="toggleProxyMode(this.checked)" style="width:18px;height:18px;accent-color:#16a34a;">
              <span>🗣️ 대신접수 (다른 직원 조회·대리등록)</span>
            </label>
            <span id="proxyStatusPill" style="font-size:11px;color:#15803d;background:#dcfce7;padding:2px 8px;border-radius:999px;font-weight:700;white-space:nowrap;">대신접수</span>
          </div>

          <div id="proxyAccountSection" style="display:none;margin-top:12px;border-top:1px dashed #bbf7d0;padding-top:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
              <label class="m-label" style="font-size:12.5px;color:#1e3a8a;margin:0;">활성 팀 기준 직원 조회</label>
              <span style="font-size:11px;color:#15803d;font-weight:600;">활성 팀 <?= count($activeTeams) ?>개 · <?= count($userAccounts) ?>명</span>
            </div>
            <div class="m-pills-row" id="proxyTeamFilterRow" style="margin-top:0;margin-bottom:8px;">
              <span class="m-pill proxy-team-pill active" data-team="" onclick="filterProxyTeam(this, '')" style="background:#1e3a8a;color:#fff;border-color:#1e3a8a;">전체</span>
              <?php foreach ($activeTeams as $t): ?>
                <span class="m-pill proxy-team-pill" data-team="<?= $escape($t) ?>" onclick="filterProxyTeam(this, '<?= $escape($t) ?>')"><?= $escape($t) ?></span>
              <?php endforeach; ?>
            </div>
            <input type="text" id="proxySearchInput" class="m-input" placeholder="🔍 성명 또는 팀명으로 빠르게 검색..." oninput="filterProxyAccounts()" style="margin-bottom:6px;background:#ffffff;font-size:13.5px;padding:9px 12px;">
            <select id="mProposerSelect" class="m-select" onchange="onSelectMobileWorker(this)" style="background:#ffffff;">
              <option value="">-- 제안한 근로자를 선택하세요 (활성 팀) --</option>
              <?php foreach ($userAccounts as $acc): ?>
                <option value="<?= $escape($acc['login_id']) ?>"
                        data-name="<?= $escape($acc['name']) ?>"
                        data-dept="<?= $escape($acc['dept']) ?>"
                        data-phone="<?= $escape($acc['phone']) ?>">
                  <?= $escape($acc['name']) ?> (<?= $escape($acc['dept'] ?: '현장') ?> / ID: <?= $escape($acc['login_id']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div style="font-size:11.5px;color:#15803d;margin-top:6px;line-height:1.4;">
              * 현재 <strong>활성화된 팀</strong> 소속 직원만 조회됩니다. 선택하신 근로자(해당 계정)의 제안 실적으로 귀속됩니다.
            </div>
          </div>
        </div>

        <!-- Step 1: 제안자 정보 -->
        <div class="m-section-title">
          <span>1️⃣</span> 제안자 정보
        </div>

        <div class="m-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;">
            <label class="m-label" style="margin:0;">성명 <span class="req">*</span></label>
            <div style="display:flex;align-items:center;gap:6px;">
              <?php if ($isLoggedInUser && !$isEditing): ?>
                <span id="autoLoginBadge" style="font-size:11.5px;color:#059669;background:#ecfdf5;border:1px solid #a7f3d0;padding:2px 8px;border-radius:999px;font-weight:700;">✅ 로그인 계정 자동등록</span>
              <?php endif; ?>
              <?php if (!$isEditing): ?>
                <button type="button" id="btnToggleProxyQuick" onclick="toggleProxyModeButton()" style="font-size:11.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;border-radius:999px;padding:3px 9px;cursor:pointer;">🗣️ 대신접수</button>
              <?php endif; ?>
            </div>
          </div>
          <input type="text" name="proposer_name" id="propName" class="m-input" placeholder="제안자 성명" value="<?= $escape($prefillName) ?>" required oninput="onProposerNameInput(this.value)">
        </div>

        <div class="m-group">
          <label class="m-label">소속 / 작업반 (팀)</label>
          <input type="text" name="proposer_dept" id="propDept" class="m-input" placeholder="계정에 연결된 팀이 자동으로 불러와집니다" value="<?= $escape($prefillDept) ?>">
          <div class="m-pills-row">
            <?php foreach ($activeTeams as $t): ?>
              <span class="m-pill" onclick="setDept('<?= $escape($t) ?>')"><?= $escape($t) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="m-help">* 로그인된 계정의 <strong>소속 팀(작업반)</strong>이 자동 입력되며, 대신접수 시 활성 팀 직원을 조회해 선택할 수 있습니다.</div>
        </div>

        <div class="m-group">
          <label class="m-label">휴대폰 번호 (포상 및 조치결과 안내용)</label>
          <input type="tel" name="proposer_phone" id="propPhone" class="m-input" placeholder="010-0000-0000" value="<?= $escape($prefillPhone) ?>">
        </div>

        <!-- Step 2: 제안 내용 -->
        <div class="m-section-title">
          <span>2️⃣</span> 위험요인 및 제안 내용
        </div>

        <div class="m-group">
          <label class="m-label">제안 제목 <span class="req">*</span></label>
          <input type="text" name="title" class="m-input" placeholder="예: 2공장 통로 바닥 요철 및 전선 걸림 위험" value="<?= $escape($prefillTitle) ?>" required>
        </div>

        <div class="m-group">
          <label class="m-label">대상구역 (위험 발생 위치)</label>
          <input type="text" name="location" class="m-input" placeholder="예: 광산현대화 터널 내 원료이송 CON'OR 또는 가공 1라인" value="<?= $escape($prefillLocation) ?>">
        </div>

        <div class="m-group">
          <label class="m-label">현황 및 문제점 (어떤 위험이 있나요?) <span class="req">*</span></label>
          <textarea name="problem" class="m-textarea" placeholder="위험 상황, 걸려 넘어질 위험, 기계 협착 위험 등 구체적으로 적어주세요." required><?= $escape($prefillProblem) ?></textarea>
        </div>

        <div class="m-group">
          <label class="m-label">개선 아이디어 (어떻게 개선하면 좋을까요?)</label>
          <textarea name="solution" class="m-textarea" placeholder="보호덮개 설치, 배선 정리, 미끄럼방지 테이프 부착 등 생각나는 아이디어를 적어주세요."><?= $escape($prefillSolution) ?></textarea>
        </div>

        <div class="m-group">
          <label class="m-label">기대효과 (선택)</label>
          <input type="text" name="expected_effect" class="m-input" placeholder="예: 전도 사고 예방 및 작업 통로 확보" value="<?= $escape($prefillExpectedEffect) ?>">
        </div>

        <!-- Step 3: 현장 사진 첨부 -->
        <div class="m-section-title">
          <span>3️⃣</span> 현장 사진 첨부 (선택)
        </div>

        <div class="m-group">
          <label class="m-label" style="display:flex;justify-content:space-between;align-items:center;">
            <span>현장 사진 촬영 / 선택 또는 클립보드 붙여넣기</span>
            <span style="font-size:11.5px;color:var(--m-accent);font-weight:600;">📋 Ctrl + V 지원</span>
          </label>
          <input type="file" name="photo_file" id="photoInput" accept="image/*" capture="environment" style="display:none;" onchange="handlePhotoSelect(event)">
          
          <div class="m-photo-box" id="mPhotoDropZone" onclick="document.getElementById('photoInput').click()">
            <div style="font-size:32px;margin-bottom:6px;">📸 / 📋</div>
            <div style="font-size:14px;font-weight:700;color:var(--m-primary);">휴대폰 카메라 촬영 또는 사진 선택</div>
            <div class="m-help">터치하면 카메라가 즉시 실행되거나 사진첩을 열 수 있습니다.</div>

            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px;">
              <button type="button" class="m-btn-paste" onclick="event.stopPropagation(); pasteFromClipboard();">
                📋 클립보드 사진 붙여넣기
              </button>
            </div>
            <div style="font-size:11.5px;color:#64748b;margin-top:7px;line-height:1.4;">
              * PC나 캡처도구에서 복사한 이미지는 <strong>Ctrl + V</strong>를 누르면 즉시 등록됩니다.
            </div>
          </div>

          <div id="photoPreviewContainer" class="m-photo-preview" style="<?= $prefillPhoto !== '' ? 'display:block;' : 'display:none;' ?>">
            <img id="photoPreviewImg" src="<?= $escape($prefillPhoto) ?>" alt="미리보기">
            <button type="button" class="m-photo-remove" onclick="removePhoto()">✕ 삭제</button>
            <div style="font-size:11.5px;color:#059669;padding:6px 10px;background:#f0fdf4;border-top:1px solid #dcfce7;display:flex;align-items:center;justify-content:space-between;">
              <span><?= $prefillPhoto !== '' ? '✅ 등록된 현장 사진 (교체 또는 삭제 가능)' : '✅ 사진이 정상 등록되었습니다.' ?></span>
              <span id="photoSizeBadge" style="font-size:11px;color:#64748b;"><?= $prefillPhoto !== '' ? '기존 사진' : '' ?></span>
            </div>
          </div>
        </div>

        <!-- Step 4: 간편 서명 -->
        <div class="m-section-title">
          <span>4️⃣</span> 제안자 서명 / 도장
        </div>

        <div class="m-group">
          <div class="m-sig-container">
            <canvas id="mSigCanvas" width="340" height="120"></canvas>
            <div class="m-sig-actions">
              <button type="button" class="m-btn-sm" onclick="stampAuto()">인감도장 자동생성</button>
              <button type="button" class="m-btn-sm" onclick="clearSig()">지우기</button>
            </div>
          </div>
          <div class="m-help">* 손가락 터치로 직접 서명하시거나 <strong>[인감도장 자동생성]</strong>을 누르시면 성명 도장이 자동 날인됩니다.</div>
        </div>

        <!-- Submit Button -->
        <div class="m-submit-bar">
          <button type="button" class="m-btn-submit" onclick="handleSubmit()">
            <span><?= $isEditing ? '💾 수정 내용 및 사진 저장하기' : '🚀 안전제안 제출하기' ?></span>
          </button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <!-- Footer -->
  <footer class="m-footer">
    <div>주식회사 현대기전 안전보건경영시스템 (CSM 1.3)</div>
    <div style="margin-top:4px;color:#94a3b8;">제출된 모든 제안은 산업안전보건법 및 사내 규정에 따라 철저히 보호·관리됩니다.</div>
  </footer>
</div>

<script>
const userAccountsData = <?= json_encode($userAccounts, JSON_UNESCAPED_UNICODE) ?>;
const loggedUserInfo = <?= json_encode([
    'login_id' => $loggedUserLoginId,
    'name' => $loggedUserName,
    'dept' => $loggedUserDept,
    'phone' => $loggedUserPhone,
], JSON_UNESCAPED_UNICODE) ?>;
let _proxyTeamFilter = '';

function onProposerNameInput(nameVal) {
  const clean = nameVal.trim();
  if (!clean) return;
  const found = userAccountsData.find(a => a.name === clean);
  if (found) {
    document.getElementById('propDept').value = found.dept || '';
    if (found.phone) document.getElementById('propPhone').value = found.phone;
    document.getElementById('propLoginId').value = found.login_id;
  }
}

function toggleProxyModeButton() {
  const chk = document.getElementById('chkProxyMode');
  if (!chk) return;
  chk.checked = !chk.checked;
  toggleProxyMode(chk.checked);
  if (chk.checked) {
    const card = document.getElementById('proxyCard');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

// Proxy Mode Toggle
function toggleProxyMode(checked) {
  const sec = document.getElementById('proxyAccountSection');
  if (sec) sec.style.display = checked ? 'block' : 'none';
  const chInput = document.getElementById('subChannel');
  if (chInput) chInput.value = checked ? '구두제보(대리등록)' : '모바일접수';

  const badge = document.getElementById('autoLoginBadge');
  if (badge) badge.style.display = checked ? 'none' : 'inline-block';

  const quickBtn = document.getElementById('btnToggleProxyQuick');
  if (quickBtn) {
    if (checked) {
      quickBtn.innerText = '↩ 본인접수로 되돌리기';
      quickBtn.style.background = '#fef3c7';
      quickBtn.style.color = '#92400e';
      quickBtn.style.borderColor = '#fcd34d';
    } else {
      quickBtn.innerText = '🗣️ 대신접수';
      quickBtn.style.background = '#dcfce7';
      quickBtn.style.color = '#166534';
      quickBtn.style.borderColor = '#86efac';
    }
  }

  if (checked) {
    filterProxyAccounts();
    const searchInp = document.getElementById('proxySearchInput');
    if (searchInp) setTimeout(() => searchInp.focus(), 60);
  } else {
    // Restore logged-in user account automatically
    const sel = document.getElementById('mProposerSelect');
    if (sel) sel.value = '';
    const searchInp = document.getElementById('proxySearchInput');
    if (searchInp) searchInp.value = '';
    if (loggedUserInfo && loggedUserInfo.name) {
      document.getElementById('propName').value = loggedUserInfo.name;
      document.getElementById('propDept').value = loggedUserInfo.dept || '';
      document.getElementById('propPhone').value = loggedUserInfo.phone || '';
      document.getElementById('propLoginId').value = loggedUserInfo.login_id || '';
    }
  }
}

function filterProxyTeam(el, team) {
  _proxyTeamFilter = team || '';
  document.querySelectorAll('.proxy-team-pill').forEach(p => {
    p.style.background = '#f1f5f9';
    p.style.color = '#334155';
    p.style.borderColor = '#cbd5e1';
  });
  if (el) {
    el.style.background = '#1e3a8a';
    el.style.color = '#ffffff';
    el.style.borderColor = '#1e3a8a';
  }
  filterProxyAccounts();
}

function filterProxyAccounts() {
  const sel = document.getElementById('mProposerSelect');
  if (!sel) return;
  const q = (document.getElementById('proxySearchInput')?.value || '').trim().toLowerCase();
  const prevVal = sel.value;

  sel.innerHTML = '';
  const placeholder = document.createElement('option');
  placeholder.value = '';
  const filtered = userAccountsData.filter(acc => {
    if (_proxyTeamFilter && (acc.dept || '') !== _proxyTeamFilter) return false;
    if (q) {
      const hay = ((acc.name || '') + ' ' + (acc.dept || '') + ' ' + (acc.login_id || '')).toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  });
  placeholder.textContent = filtered.length > 0
    ? '-- 제안한 근로자를 선택하세요 (' + filtered.length + '명) --'
    : '-- 조건에 맞는 활성 팀 직원이 없습니다 --';
  sel.appendChild(placeholder);

  filtered.forEach(acc => {
    const opt = document.createElement('option');
    opt.value = acc.login_id;
    opt.setAttribute('data-name', acc.name || '');
    opt.setAttribute('data-dept', acc.dept || '');
    opt.setAttribute('data-phone', acc.phone || '');
    opt.textContent = (acc.name || '') + ' (' + (acc.dept || '현장') + ' / ID: ' + (acc.login_id || '') + ')';
    if (acc.login_id === prevVal) opt.selected = true;
    sel.appendChild(opt);
  });
}

function onSelectMobileWorker(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt || !sel.value) return;
  const name = opt.getAttribute('data-name') || '';
  const dept = opt.getAttribute('data-dept') || '';
  const phone = opt.getAttribute('data-phone') || '';
  document.getElementById('propName').value = name;
  document.getElementById('propDept').value = dept;
  document.getElementById('propPhone').value = phone;
  document.getElementById('propLoginId').value = sel.value;

  // Auto stamp with worker's name
  if (name) {
    setTimeout(stampAuto, 50);
  }
}

// Category Selector
function setCategory(el, cat) {
  document.querySelectorAll('.m-cat-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('catInput').value = cat;
}

// Dept Quick Pills
function setDept(dept) {
  document.getElementById('propDept').value = dept;
}

// Photo Compression & Preview (File, Clipboard, Drag&Drop)
function processImageFile(file) {
  if (!file) return;
  if (!file.type || !file.type.startsWith('image/')) {
    alert('이미지 파일(JPG, PNG, WebP 등)만 첨부할 수 있습니다.');
    return;
  }

  const reader = new FileReader();
  reader.onload = function(evt) {
    const img = new Image();
    img.onload = function() {
      // Compress with canvas (max dimension 1280px)
      const maxDim = 1280;
      let w = img.width;
      let h = img.height;
      if (w > maxDim || h > maxDim) {
        if (w > h) {
          h = Math.round((h * maxDim) / w);
          w = maxDim;
        } else {
          w = Math.round((w * maxDim) / h);
          h = maxDim;
        }
      }

      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);

      const compressedData = canvas.toDataURL('image/jpeg', 0.82);
      document.getElementById('photoBase64').value = compressedData;
      const rem = document.getElementById('removePhotoInput');
      if (rem) rem.value = '0';

      const previewImg = document.getElementById('photoPreviewImg');
      previewImg.src = compressedData;
      document.getElementById('photoPreviewContainer').style.display = 'block';

      const sizeBadge = document.getElementById('photoSizeBadge');
      if (sizeBadge) {
        const kb = Math.round((compressedData.length * 3 / 4) / 1024);
        sizeBadge.innerText = w + 'x' + h + ' (' + kb + 'KB)';
      }
    };
    img.src = evt.target.result;
  };
  reader.readAsDataURL(file);
}

function handlePhotoSelect(e) {
  const file = e.target.files && e.target.files[0];
  if (file) {
    processImageFile(file);
  }
}

// Clipboard Paste API
async function pasteFromClipboard() {
  if (navigator.clipboard && navigator.clipboard.read) {
    try {
      const items = await navigator.clipboard.read();
      for (const item of items) {
        for (const type of item.types) {
          if (type.startsWith('image/')) {
            const blob = await item.getType(type);
            processImageFile(blob);
            return;
          }
        }
      }
      alert('클립보드에 복사된 이미지가 없습니다.\n먼저 스크린샷이나 사진을 복사(Ctrl+C 또는 캡처)한 후 다시 시도해주세요.');
    } catch (err) {
      console.warn('Clipboard read error:', err);
      alert('브라우저 권한 정책으로 클립보드 직접 읽기가 제한되었습니다.\n키보드의 [Ctrl + V] 단축키를 눌러 바로 붙여넣어주세요.');
    }
  } else {
    alert('현재 브라우저에서는 클립보드 버튼 읽기가 지원되지 않습니다.\n키보드의 [Ctrl + V] 단축키를 누르시면 사진이 즉시 붙여넣어집니다.');
  }
}

// Global Paste Event Listener (Ctrl + V)
window.addEventListener('paste', function(e) {
  const items = e.clipboardData && e.clipboardData.items;
  if (!items) return;

  for (let i = 0; i < items.length; i++) {
    if (items[i].type.indexOf('image') !== -1) {
      const file = items[i].getAsFile();
      if (file) {
        e.preventDefault();
        processImageFile(file);
        const preview = document.getElementById('photoPreviewContainer');
        if (preview) {
          preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        break;
      }
    }
  }
});

// Drag and drop onto photoDropZone
const dropZone = document.getElementById('mPhotoDropZone');
if (dropZone) {
  ['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, function(e) {
      e.preventDefault();
      e.stopPropagation();
      dropZone.classList.add('dragover');
    }, false);
  });
  ['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, function(e) {
      e.preventDefault();
      e.stopPropagation();
      dropZone.classList.remove('dragover');
    }, false);
  });
  dropZone.addEventListener('drop', function(e) {
    const dt = e.dataTransfer;
    const files = dt && dt.files;
    if (files && files.length > 0) {
      for (let i = 0; i < files.length; i++) {
        if (files[i].type.startsWith('image/')) {
          processImageFile(files[i]);
          break;
        }
      }
    }
  }, false);
}

function removePhoto() {
  document.getElementById('photoInput').value = '';
  document.getElementById('photoBase64').value = '';
  document.getElementById('photoPreviewImg').src = '';
  document.getElementById('photoPreviewContainer').style.display = 'none';
  const rem = document.getElementById('removePhotoInput');
  if (rem) rem.value = '1';
}

function openQuickSearchPrompt() {
  const reg = prompt('조회 또는 수정할 제안의 접수번호를 입력해주세요.\n(예: 2026-S001)');
  if (reg && reg.trim()) {
    location.href = 'safety_suggestion_submit.php?search_reg=' + encodeURIComponent(reg.trim());
  }
}

<?php if ($isEditing && !empty($prefillSign)): ?>
window.addEventListener('DOMContentLoaded', function() {
  const signImg = new Image();
  signImg.onload = function() {
    if (canvas && ctx) {
      ctx.drawImage(signImg, (canvas.width - signImg.width) / 2, (canvas.height - signImg.height) / 2);
      hasSigned = true;
    }
  };
  signImg.src = <?= json_encode($prefillSign) ?>;
});
<?php endif; ?>

// Touch Signature Logic
const canvas = document.getElementById('mSigCanvas');
let ctx = null;
let isDrawing = false;
let hasSigned = false;

if (canvas) {
  ctx = canvas.getContext('2d');
  ctx.lineWidth = 2.5;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.strokeStyle = '#0f172a';

  function getCanvasPos(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    if (e.touches && e.touches[0]) {
      return {
        x: (e.touches[0].clientX - rect.left) * scaleX,
        y: (e.touches[0].clientY - rect.top) * scaleY
      };
    }
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY
    };
  }

  function startSig(e) {
    if (e.target !== canvas) return;
    e.preventDefault();
    isDrawing = true;
    hasSigned = true;
    const p = getCanvasPos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }

  function moveSig(e) {
    if (!isDrawing) return;
    e.preventDefault();
    const p = getCanvasPos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }

  function endSig(e) {
    if (!isDrawing) return;
    isDrawing = false;
  }

  canvas.addEventListener('mousedown', startSig);
  canvas.addEventListener('mousemove', moveSig);
  window.addEventListener('mouseup', endSig);

  canvas.addEventListener('touchstart', startSig, { passive: false });
  canvas.addEventListener('touchmove', moveSig, { passive: false });
  window.addEventListener('touchend', endSig, { passive: false });
}

function clearSig() {
  if (!canvas || !ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  hasSigned = false;
}

function stampAuto() {
  const name = document.getElementById('propName').value.trim();
  if (!name) {
    alert('도장에 각인할 제안자 성명을 먼저 입력해주세요.');
    document.getElementById('propName').focus();
    return;
  }
  clearSig();
  const cx = canvas.width / 2;
  const cy = canvas.height / 2;
  const r = 38;

  ctx.save();
  ctx.strokeStyle = '#dc2626';
  ctx.lineWidth = 3;
  ctx.beginPath();
  ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.stroke();

  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.arc(cx, cy, r - 4, 0, Math.PI * 2);
  ctx.stroke();

  ctx.fillStyle = '#dc2626';
  ctx.font = 'bold 18px Batang, Gungsuh, "Malgun Gothic", serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(name, cx, cy + 1);
  ctx.restore();

  hasSigned = true;
}

// Form Submit Handler
function handleSubmit() {
  const form = document.getElementById('formSuggestion');
  const name = document.getElementById('propName').value.trim();
  const title = form.querySelector('[name="title"]').value.trim();
  const problem = form.querySelector('[name="problem"]').value.trim();

  if (!name) {
    alert('제안자 성명을 입력해주세요.');
    document.getElementById('propName').focus();
    return;
  }
  if (!title) {
    alert('제안 제목을 입력해주세요.');
    form.querySelector('[name="title"]').focus();
    return;
  }
  if (!problem) {
    alert('현황 및 문제점을 입력해주세요.');
    form.querySelector('[name="problem"]').focus();
    return;
  }

  if (hasSigned && canvas) {
    document.getElementById('sigDataInput').value = canvas.toDataURL('image/png');
  }

  form.submit();
}

// ==========================================
// 내 제안 내역 조회 모달 (본인 접수 + 대리 접수 명의자 조회)
// ==========================================
const initialMySuggestions = <?= json_encode($mySuggestions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const isLoggedInWorker = <?= $isLoggedInUser ? 'true' : 'false' ?>;
const loggedCleanName = <?= json_encode(worker_part_clean_person_name($loggedUserName), JSON_UNESCAPED_UNICODE) ?>;

function escMs(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function openMySuggestionsModal() {
  const modal = document.getElementById('modalMySuggestions');
  if (!modal) return;
  modal.style.display = 'flex';
  if (isLoggedInWorker) {
    renderMySuggestionList(initialMySuggestions, loggedCleanName + ' 님 명의로 접수된 제안서 (대리접수 포함)');
  } else {
    document.getElementById('msSearchPanel').style.display = 'block';
  }
}

function closeMySuggestionsModal() {
  const modal = document.getElementById('modalMySuggestions');
  if (modal) modal.style.display = 'none';
}

function toggleMsSearchPanel() {
  const p = document.getElementById('msSearchPanel');
  if (!p) return;
  p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
}

async function searchMySuggestions() {
  const regNo = (document.getElementById('msRegNoInput')?.value || '').trim();
  const name = (document.getElementById('msNameInput')?.value || '').trim();
  const phone4 = (document.getElementById('msPhone4Input')?.value || '').replace(/\D+/g, '').slice(-4);
  const errEl = document.getElementById('msSearchErr');
  errEl.style.display = 'none';

  if (!regNo && (!name || phone4.length !== 4)) {
    errEl.innerText = '접수번호를 입력하시거나, 성명과 휴대폰 뒤 4자리를 모두 입력해주세요.';
    errEl.style.display = 'block';
    return;
  }

  const params = new URLSearchParams({ api: 'my_suggestions' });
  if (regNo) params.set('reg_no', regNo);
  else {
    params.set('name', name);
    params.set('phone4', phone4);
  }

  const listEl = document.getElementById('msListContainer');
  listEl.innerHTML = '<div style="text-align:center;padding:30px;color:#64748b;font-size:13px;">조회 중입니다...</div>';

  try {
    const res = await fetch('safety_suggestion_submit.php?' + params.toString(), { cache: 'no-store' });
    const data = await res.json();
    if (!data.ok) {
      errEl.innerText = data.error || '조회 중 오류가 발생했습니다.';
      errEl.style.display = 'block';
      listEl.innerHTML = '';
      return;
    }
    const label = regNo
      ? ('접수번호 [' + regNo + '] 조회 결과')
      : (name + ' 님 명의로 접수된 제안서 (대리접수 포함)');
    renderMySuggestionList(data.items || [], label);
  } catch (e) {
    errEl.innerText = '서버 통신 중 오류가 발생했습니다.';
    errEl.style.display = 'block';
  }
}

function getStatusBadgeHtml(status) {
  if (status === '완료') return '<span class="ms-badge ms-badge-done">조치완료</span>';
  if (status === '채택·진행') return '<span class="ms-badge ms-badge-progress">진행중</span>';
  if (status === '불채택') return '<span class="ms-badge ms-badge-reject">불채택</span>';
  return '<span class="ms-badge ms-badge-pending">' + escMs(status || '접수') + '</span>';
}

function renderMySuggestionList(items, headingText) {
  const headerEl = document.getElementById('msListHeading');
  if (headerEl) {
    headerEl.innerText = headingText + ' (' + items.length + '건)';
  }
  const listEl = document.getElementById('msListContainer');
  if (!listEl) return;

  if (!items || items.length === 0) {
    listEl.innerHTML = '<div style="text-align:center;padding:36px 16px;background:#ffffff;border-radius:12px;border:1px dashed #cbd5e1;color:#64748b;font-size:13.5px;">조회된 안전제안이 없습니다.<br><span style="font-size:12px;color:#94a3b8;">직접 접수하시거나 관리자에게 구두 제보하신 제안서가 여기에 표시됩니다.</span></div>';
    return;
  }

  let html = '';
  items.forEach((s, idx) => {
    const isProxy = (s.submission_channel || '').includes('구두') || (s.submission_channel || '').includes('대리');
    const proxyBadge = isProxy
      ? '<span style="font-size:10.5px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:1px 6px;border-radius:999px;font-weight:700;">🗣️ 대리접수' + (s.proxy_writer_name ? ' (' + escMs(s.proxy_writer_name) + ')' : '') + '</span>'
      : '';

    let photosHtml = '';
    if (s.photo_before || s.photo_after) {
      photosHtml = '<div class="ms-photo-grid">';
      photosHtml += '<div class="ms-photo-box"><div style="font-size:11px;font-weight:700;text-align:center;padding:4px;background:#eff6ff;color:#1e3a8a;">&lt; 개 선 전 &gt;</div>';
      if (s.photo_before) {
        photosHtml += '<img src="' + s.photo_before + '" alt="개선 전" onclick="openMyPhotoZoom(this.src, \'' + escMs(s.title) + ' (개선 전)\')">';
      } else {
        photosHtml += '<div style="height:120px;display:flex;align-items:center;justify-content:center;font-size:11.5px;color:#94a3b8;">미등록</div>';
      }
      if (s.photo_before_desc) {
        photosHtml += '<div style="padding:6px 8px;font-size:11.5px;color:#334155;white-space:pre-wrap;border-top:1px solid #e2e8f0;">' + escMs(s.photo_before_desc) + '</div>';
      }
      photosHtml += '</div>';

      photosHtml += '<div class="ms-photo-box"><div style="font-size:11px;font-weight:700;text-align:center;padding:4px;background:#ecfdf5;color:#047857;">&lt; 개 선 후 &gt;</div>';
      if (s.photo_after) {
        photosHtml += '<img src="' + s.photo_after + '" alt="개선 후" onclick="openMyPhotoZoom(this.src, \'' + escMs(s.title) + ' (개선 후)\')">';
      } else {
        photosHtml += '<div style="height:120px;display:flex;align-items:center;justify-content:center;font-size:11.5px;color:#94a3b8;">미등록</div>';
      }
      if (s.photo_after_desc) {
        photosHtml += '<div style="padding:6px 8px;font-size:11.5px;color:#334155;white-space:pre-wrap;border-top:1px solid #e2e8f0;">' + escMs(s.photo_after_desc) + '</div>';
      }
      photosHtml += '</div></div>';
    }

    let actionHtml = '';
    if (s.action_manager || s.action_date || s.action_result || s.review_opinion) {
      actionHtml = '<div style="margin-top:10px;padding:10px;background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;display:flex;flex-direction:column;gap:5px;">';
      actionHtml += '<div style="font-size:12px;font-weight:800;color:#1e3a8a;">🛠️ 조치 및 평가 현황</div>';
      if (s.action_manager || s.action_date) {
        actionHtml += '<div style="font-size:12.5px;color:#334155;"><strong>담당자:</strong> ' + escMs(s.action_manager || '미지정') + (s.action_date ? ' &nbsp;|&nbsp; <strong>완료일:</strong> ' + escMs(s.action_date) : '') + '</div>';
      }
      if (s.action_result) {
        actionHtml += '<div style="font-size:12.5px;color:#334155;white-space:pre-wrap;background:#f8fafc;padding:7px 9px;border-radius:6px;border:1px solid #e2e8f0;"><strong>조치 결과 요약:</strong>\n' + escMs(s.action_result) + '</div>';
      }
      if (s.reward_points > 0 || s.review_opinion) {
        actionHtml += '<div style="font-size:12.5px;color:#1e293b;">' + (s.reward_points > 0 ? '<strong>안전기여도 평가:</strong> ' + s.reward_points + '점' : '') + (s.reviewer_name ? ' (평가자: ' + escMs(s.reviewer_name) + ')' : '') + '</div>';
        if (s.review_opinion) {
          actionHtml += '<div style="font-size:12.5px;color:#334155;white-space:pre-wrap;"><strong>평가요약:</strong> ' + escMs(s.review_opinion) + '</div>';
        }
      }
      actionHtml += '</div>';
    }

    html += '<div class="ms-card' + (idx === 0 && items.length === 1 ? ' open' : '') + '" id="msCard_' + s.id + '">' +
      '<div class="ms-card-head" onclick="document.getElementById(\'msCard_' + s.id + '\').classList.toggle(\'open\')">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">' +
          '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
            '<span style="font-size:12px;font-weight:800;color:#1e3a8a;">' + escMs(s.reg_no) + '</span>' +
            getStatusBadgeHtml(s.status) +
            proxyBadge +
          '</div>' +
          '<span style="font-size:11.5px;color:#64748b;">' + escMs(s.reg_date) + ' ▾</span>' +
        '</div>' +
        '<div style="font-size:15px;font-weight:800;color:#0f172a;">' + escMs(s.title) + '</div>' +
        '<div style="font-size:12px;color:#64748b;">제안자: <strong>' + escMs(s.proposer_name) + '</strong>' + (s.proposer_dept ? ' (' + escMs(s.proposer_dept) + ')' : '') + ' &middot; 대상구역: ' + escMs(s.location || '현장 전 구역') + '</div>' +
      '</div>' +
      '<div class="ms-card-detail">' +
        '<div style="margin-bottom:6px;"><strong>현황 및 문제점:</strong><div style="white-space:pre-wrap;margin-top:2px;color:#334155;">' + escMs(s.problem || '—') + '</div></div>' +
        '<div style="margin-bottom:6px;"><strong>개선방안:</strong><div style="white-space:pre-wrap;margin-top:2px;color:#334155;">' + escMs(s.solution || '—') + '</div></div>' +
        (s.expected_effect ? '<div style="margin-bottom:6px;"><strong>기대효과:</strong><div style="white-space:pre-wrap;margin-top:2px;color:#334155;">' + escMs(s.expected_effect) + '</div></div>' : '') +
        photosHtml +
        actionHtml +
        '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">' +
          '<a href="safety_suggestion_submit.php?edit_id=' + encodeURIComponent(s.id) + '" style="display:inline-flex;align-items:center;gap:4px;padding:7px 12px;border-radius:8px;background:#1e3a8a;color:#ffffff;font-size:12.5px;font-weight:700;text-decoration:none;">✏️ 내용/사진 수정</a>' +
        '</div>' +
      '</div>' +
    '</div>';
  });
  listEl.innerHTML = html;
}

function openMyPhotoZoom(src, title) {
  if (!src) return;
  document.getElementById('msPhotoZoomTitle').innerText = '📷 ' + (title || '현장 사진');
  document.getElementById('msPhotoZoomImg').src = src;
  document.getElementById('modalMyPhotoZoom').style.display = 'flex';
}
function closeMyPhotoZoom() {
  document.getElementById('modalMyPhotoZoom').style.display = 'none';
  document.getElementById('msPhotoZoomImg').src = '';
}
</script>

<!-- MODAL: MY SUGGESTIONS LOOKUP -->
<div id="modalMySuggestions" class="ms-backdrop" onclick="closeMySuggestionsModal()">
  <div class="ms-modal" onclick="event.stopPropagation()">
    <div class="ms-header">
      <div>
        <div style="font-size:16px;font-weight:800;">📋 내 안전제안 조회</div>
        <div style="font-size:11.5px;opacity:0.88;margin-top:2px;">본인이 직접 접수했거나 대리접수된 제안 내역을 확인할 수 있습니다.</div>
      </div>
      <button type="button" onclick="closeMySuggestionsModal()" style="background:none;border:none;color:#cbd5e1;font-size:26px;line-height:1;cursor:pointer;">&times;</button>
    </div>

    <div class="ms-body">
      <?php if ($isLoggedInUser): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
          <div id="msListHeading" style="font-size:13px;font-weight:800;color:#1e3a8a;"></div>
          <button type="button" onclick="toggleMsSearchPanel()" style="font-size:11.5px;font-weight:700;color:#475569;background:#ffffff;border:1px solid #cbd5e1;border-radius:999px;padding:4px 10px;cursor:pointer;">🔍 접수번호 / 다른 이름 조회</button>
        </div>
      <?php else: ?>
        <div id="msListHeading" style="font-size:13px;font-weight:800;color:#1e3a8a;margin-bottom:10px;">본인 제안 조회 (성명 + 휴대폰 뒤 4자리 또는 접수번호)</div>
      <?php endif; ?>

      <div id="msSearchPanel" style="display:<?= $isLoggedInUser ? 'none' : 'block' ?>;background:#ffffff;border:1px solid #cbd5e1;border-radius:12px;padding:12px;margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
          <div>
            <label style="font-size:11.5px;font-weight:700;color:#475569;display:block;margin-bottom:3px;">제안자 성명</label>
            <input type="text" id="msNameInput" class="m-input" placeholder="예: 윤택천" style="padding:8px 10px;font-size:13.5px;">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:700;color:#475569;display:block;margin-bottom:3px;">휴대폰 뒤 4자리</label>
            <input type="tel" id="msPhone4Input" class="m-input" maxlength="4" placeholder="예: 6209" style="padding:8px 10px;font-size:13.5px;">
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
          <div style="flex:1;">
            <label style="font-size:11.5px;font-weight:700;color:#475569;display:block;margin-bottom:3px;">또는 접수번호로 바로 조회</label>
            <input type="text" id="msRegNoInput" class="m-input" placeholder="예: 26-001 또는 2026-S001" style="padding:8px 10px;font-size:13.5px;" onkeydown="if(event.key==='Enter'){event.preventDefault();searchMySuggestions();}">
          </div>
          <button type="button" onclick="searchMySuggestions()" style="padding:9px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#ffffff;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">조회</button>
        </div>
        <div id="msSearchErr" style="display:none;margin-top:8px;font-size:12px;color:#b91c1c;font-weight:600;"></div>
      </div>

      <div id="msListContainer"></div>
    </div>

    <div style="padding:12px 16px;background:#ffffff;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;">
      <button type="button" onclick="closeMySuggestionsModal()" style="padding:8px 18px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#334155;font-size:13px;font-weight:700;cursor:pointer;">닫기</button>
    </div>
  </div>
</div>

<!-- MODAL: PHOTO ZOOM -->
<div id="modalMyPhotoZoom" class="ms-backdrop" style="z-index:1300;" onclick="closeMyPhotoZoom()">
  <div style="max-width:92vw;max-height:88vh;background:#0f172a;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;" onclick="event.stopPropagation()">
    <div style="padding:10px 14px;background:#1e293b;color:#f8fafc;display:flex;justify-content:space-between;align-items:center;gap:12px;">
      <span id="msPhotoZoomTitle" style="font-size:13.5px;font-weight:700;">📷 현장 사진</span>
      <button type="button" onclick="closeMyPhotoZoom()" style="background:none;border:none;color:#cbd5e1;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:12px;display:flex;align-items:center;justify-content:center;background:#020617;">
      <img id="msPhotoZoomImg" src="" alt="현장 사진 확대" style="max-width:86vw;max-height:76vh;object-fit:contain;border-radius:6px;">
    </div>
  </div>
</div>

</body>
</html>
