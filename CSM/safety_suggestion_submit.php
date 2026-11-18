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
$assignedRegNo = '';
$error = '';

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

        if ($proposer_name === '') {
            throw new InvalidArgumentException('제안자 성명을 입력해주세요.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('제안 제목을 입력해주세요.');
        }
        if ($problem === '') {
            throw new InvalidArgumentException('현장의 위험요인 또는 문제점을 입력해주세요.');
        }

        // Process Photo Upload
        $photoPath = null;
        $uploadDir = __DIR__ . '/storage/suggestions';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('저장소 디렉토리를 생성할 수 없습니다.');
        }

        // Option A: Base64 data from compressed mobile canvas
        $photoBase64 = trim($_POST['photo_base64'] ?? '');
        if ($photoBase64 !== '' && preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $photoBase64)) {
            $parts = explode(',', $photoBase64, 2);
            $imgData = base64_decode($parts[1] ?? '', true);
            if ($imgData !== false && strlen($imgData) > 50) {
                $fileName = 'sug_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                $dest = $uploadDir . '/' . $fileName;
                if (file_put_contents($dest, $imgData) !== false) {
                    $photoPath = 'storage/suggestions/' . $fileName;
                }
            }
        }

        // Option B: Fallback Standard File Upload
        if ($photoPath === null && isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['photo_file'];
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $fileName = 'sug_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $uploadDir . '/' . $fileName;
                if (move_uploaded_file((string)$f['tmp_name'], $dest)) {
                    $photoPath = 'storage/suggestions/' . $fileName;
                }
            }
        }

        // Generate Registration Number
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

        $now = date('Y-m-d H:i:s');
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        worker_part_save_signature($db, 'suggestion', $newId, 'proposer', $proposer_name, $finalSign, $ip);

        $submitted = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Prefill values from logged in user if available
$prefillName = $user['name'] ?? '';
$prefillDept = $user['team'] ?? $user['team_name'] ?? $user['department'] ?? '';
$prefillLoginId = $user['login_id'] ?? '';
$userAccounts = worker_part_get_user_accounts();
$activeTeams = worker_part_get_teams();
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
      <a href="worker_participation.php?tab=suggestions" style="color:#ffffff;text-decoration:none;font-size:12px;opacity:0.85;">관리대장</a>
    </div>
    <h1 class="m-header-title">산업안전보건 안전제안 접수</h1>
    <p class="m-header-desc">현장의 유해·위험요인과 개선 아이디어를 등록해주세요.</p>
    <div class="m-slogan-pill">
      <span>🛡️</span>
      <span><strong>안전은 행동입니다!</strong> 작은 제안이 안전한 현장을 만듭니다.</span>
    </div>
  </header>

  <?php if ($submitted): ?>
    <!-- SUCCESS VIEW -->
    <div class="m-content">
      <div class="m-success-card">
        <div class="m-success-icon">🎉</div>
        <h2 style="font-size:22px;color:var(--m-primary);margin:0 0 8px 0;">안전제안이 접수되었습니다!</h2>
        <p style="font-size:14px;color:#475569;margin:0 0 14px 0;">
          작성해주신 소중한 제안은 안전관리팀 및 산업안전보건위원회에 실시간 전달되었습니다.
        </p>
        <div>
          <span style="font-size:13px;color:var(--m-muted);">접수번호</span><br>
          <div class="m-success-reg"><?= $escape($assignedRegNo) ?></div>
        </div>
        <div style="background:#f8fafc;border-left:4px solid var(--m-success);padding:14px 16px;text-align:left;border-radius:6px;margin:16px 0 24px;font-size:13.5px;color:#334155;">
          <strong>💡 향후 진행 절차 안내:</strong><br>
          1. 안전보건관리책임자 검토 및 개선조치 계획 수립<br>
          2. 현장 위험요인 즉시 시정 및 개선 이행<br>
          3. 우수 제안자 선정 및 <strong>이달의 안전인 포상금</strong> 지급
        </div>
        <a href="safety_suggestion_submit.php" class="m-btn-submit" style="text-decoration:none;">
          + 새로운 안전제안 추가 작성
        </a>
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

      <form method="post" id="formSuggestion" enctype="multipart/form-data">
        <input type="hidden" name="category" id="catInput" value="미분류(접수자 분류)">
        <input type="hidden" name="photo_base64" id="photoBase64" value="">
        <input type="hidden" name="signature_data" id="sigDataInput" value="">
        <input type="hidden" name="proposer_login_id" id="propLoginId" value="">
        <input type="hidden" name="submission_channel" id="subChannel" value="모바일접수">
        <input type="hidden" name="proxy_writer_name" id="proxyWriterName" value="<?= $escape($user['name'] ?? '안전관리자') ?>">

        <!-- Verbal Proxy Mode Toggle (안전관리자 구두 제보 대신 접수) -->
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:12px 14px;margin-bottom:18px;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <label style="font-size:13.5px;font-weight:700;color:#166534;display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
              <input type="checkbox" id="chkProxyMode" onchange="toggleProxyMode(this.checked)" style="width:18px;height:18px;accent-color:#16a34a;">
              <span>🗣️ 현장 근로자 구두 제보 대신 접수</span>
            </label>
            <span style="font-size:11px;color:#15803d;background:#dcfce7;padding:2px 8px;border-radius:999px;font-weight:600;">대리등록</span>
          </div>

          <div id="proxyAccountSection" style="display:none;margin-top:12px;border-top:1px dashed #bbf7d0;padding-top:10px;">
            <label class="m-label" style="font-size:12.5px;color:#1e3a8a;">사내 등록 근로자/계정 선택</label>
            <select id="mProposerSelect" class="m-select" onchange="onSelectMobileWorker(this)" style="background:#ffffff;">
              <option value="">-- 제안한 근로자를 선택하세요 --</option>
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
              * 안전관리자가 대신 작성하더라도, 접수 완료 시 <strong>선택하신 근로자(해당 계정)의 제안 실적</strong>으로 온전히 귀속됩니다.
            </div>
          </div>
        </div>

        <!-- Step 1: 제안자 정보 -->
        <div class="m-section-title">
          <span>1️⃣</span> 제안자 정보
        </div>

        <div class="m-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label class="m-label" style="margin:0;">성명 <span class="req">*</span></label>
            <span style="font-size:12px;color:var(--m-accent);cursor:pointer;" onclick="document.getElementById('mWorkerPicker').focus()">🔍 사내 계정에서 선택</span>
          </div>
          <div style="display:grid;grid-template-columns:1fr;gap:6px;">
            <select id="mWorkerPicker" class="m-select" onchange="onSelectWorkerPicker(this)" style="background:#f8fafc;font-size:13.5px;">
              <option value="">-- 사내 계정/성명 선택 시 소속(팀) 자동 입력 --</option>
              <?php foreach ($userAccounts as $acc): ?>
                <option value="<?= $escape($acc['login_id']) ?>"
                        data-name="<?= $escape($acc['name']) ?>"
                        data-team="<?= $escape($acc['dept']) ?>"
                        data-phone="<?= $escape($acc['phone']) ?>"
                        <?= ($prefillName !== '' && $acc['name'] === $prefillName) ? 'selected' : '' ?>>
                  <?= $escape($acc['name']) ?> (<?= $escape($acc['dept'] ?: '현장') ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="proposer_name" id="propName" class="m-input" placeholder="성명 직접 입력 또는 위에서 선택" value="<?= $escape($prefillName) ?>" required oninput="onProposerNameInput(this.value)">
          </div>
        </div>

        <div class="m-group">
          <label class="m-label">소속 / 작업반 (팀)</label>
          <input type="text" name="proposer_dept" id="propDept" class="m-input" placeholder="계정에 연결된 팀이 자동으로 불러와집니다" value="<?= $escape($prefillDept) ?>">
          <div class="m-pills-row">
            <?php foreach ($activeTeams as $t): ?>
              <span class="m-pill" onclick="setDept('<?= $escape($t) ?>')"><?= $escape($t) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="m-help">* 성명을 선택하시면 계정에 연결되어 있는 <strong>소속 팀(작업반)</strong>이 자동으로 입력됩니다.</div>
        </div>

        <div class="m-group">
          <label class="m-label">휴대폰 번호 (포상 및 조치결과 안내용)</label>
          <input type="tel" name="proposer_phone" id="propPhone" class="m-input" placeholder="010-0000-0000">
        </div>

        <!-- Step 2: 제안 내용 -->
        <div class="m-section-title">
          <span>2️⃣</span> 위험요인 및 제안 내용
        </div>

        <div class="m-group">
          <label class="m-label">제안 제목 <span class="req">*</span></label>
          <input type="text" name="title" class="m-input" placeholder="예: 2공장 통로 바닥 요철 및 전선 걸림 위험" required>
        </div>

        <div class="m-group">
          <label class="m-label">위험 발생 위치</label>
          <input type="text" name="location" class="m-input" placeholder="예: 2공장 메인 배전반 앞 또는 가공 1라인">
        </div>

        <div class="m-group">
          <label class="m-label">현황 및 문제점 (어떤 위험이 있나요?) <span class="req">*</span></label>
          <textarea name="problem" class="m-textarea" placeholder="위험 상황, 걸려 넘어질 위험, 기계 협착 위험 등 구체적으로 적어주세요." required></textarea>
        </div>

        <div class="m-group">
          <label class="m-label">개선 아이디어 (어떻게 개선하면 좋을까요?)</label>
          <textarea name="solution" class="m-textarea" placeholder="보호덮개 설치, 배선 정리, 미끄럼방지 테이프 부착 등 생각나는 아이디어를 적어주세요."></textarea>
        </div>

        <!-- Step 3: 현장 사진 첨부 -->
        <div class="m-section-title">
          <span>3️⃣</span> 현장 사진 첨부 (선택)
        </div>

        <div class="m-group">
          <label class="m-label">현장 사진 촬영 또는 갤러리 선택</label>
          <input type="file" name="photo_file" id="photoInput" accept="image/*" capture="environment" style="display:none;" onchange="handlePhotoSelect(event)">
          
          <div class="m-photo-box" onclick="document.getElementById('photoInput').click()">
            <div style="font-size:32px;margin-bottom:6px;">📸</div>
            <div style="font-size:14px;font-weight:700;color:var(--m-primary);">휴대폰 카메라로 촬영 또는 사진 선택</div>
            <div class="m-help">터치하면 카메라가 즉시 실행되거나 사진첩을 열 수 있습니다.</div>
          </div>

          <div id="photoPreviewContainer" class="m-photo-preview">
            <img id="photoPreviewImg" src="" alt="미리보기">
            <button type="button" class="m-photo-remove" onclick="removePhoto()">✕ 삭제</button>
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
            <span>🚀 안전제안 제출하기</span>
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

function onSelectWorkerPicker(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt || !sel.value) return;
  const name = opt.getAttribute('data-name') || '';
  const team = opt.getAttribute('data-team') || opt.getAttribute('data-dept') || '';
  const phone = opt.getAttribute('data-phone') || '';
  document.getElementById('propName').value = name;
  document.getElementById('propDept').value = team;
  if (phone) {
    document.getElementById('propPhone').value = phone;
  }
  document.getElementById('propLoginId').value = sel.value;
  if (name) {
    setTimeout(stampAuto, 50);
  }
}

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

// Proxy Mode Toggle
function toggleProxyMode(checked) {
  const sec = document.getElementById('proxyAccountSection');
  if (sec) sec.style.display = checked ? 'block' : 'none';
  const chInput = document.getElementById('subChannel');
  if (chInput) chInput.value = checked ? '구두제보(대리등록)' : '모바일접수';
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

// Photo Compression & Preview
function handlePhotoSelect(e) {
  const file = e.target.files && e.target.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = function(evt) {
    const img = new Image();
    img.onload = function() {
      // Compress with canvas (max width 1280px)
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

      document.getElementById('photoPreviewImg').src = compressedData;
      document.getElementById('photoPreviewContainer').style.display = 'block';
    };
    img.src = evt.target.result;
  };
  reader.readAsDataURL(file);
}

function removePhoto() {
  document.getElementById('photoInput').value = '';
  document.getElementById('photoBase64').value = '';
  document.getElementById('photoPreviewImg').src = '';
  document.getElementById('photoPreviewContainer').style.display = 'none';
}

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
</script>

</body>
</html>
