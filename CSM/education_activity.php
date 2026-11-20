<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/education_activity_store.php';
require_once __DIR__ . '/scoring.php';

$catalog = json_decode(file_get_contents(__DIR__ . '/evaluation_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$user = csm_require_auth();
header('Cache-Control: no-store');

$canManage = auth_can_manage($user);
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year = filter_var($_POST['year'] ?? $_GET['year'] ?? substr($today, 0, 4), FILTER_VALIDATE_INT);

$tabTitles = [
    '5.1.1' => '직무교육 (책임자/관리자)',
    '5.1.2' => '정기교육 (근로자/관리감독자)',
    '5.1.3' => '채용·직무변경 교육',
    '5.1.4' => '특별안전교육 (Matrix)',
    '5.1.5' => 'MSDS 교육',
    '5.2.2' => '안전체험교육장 이수'
];

$item = $_POST['item'] ?? $_GET['item'] ?? '5.1.1';
if (!$year || $year < 2000 || $year > 2100 || !is_string($item) || !isset($tabTitles[$item])) {
    http_response_code(400);
    exit('평가 연도와 교육 항목을 확인해 주세요.');
}

$base = '/CSM/education_activity.php?year=' . $year . '&item=' . $item;
$allMonthsBase = $base;
$selectedMonth = null;
if ($item === '5.1.2' && isset($_GET['month'])) {
    $selectedMonth = filter_var($_GET['month'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    if ($selectedMonth === false) {
        http_response_code(400);
        exit('조회할 월은 1~12월로 선택해 주세요.');
    }
    $base .= '&month=' . $selectedMonth;
}
$error = '';
$message = $_SESSION['education_activity_message'] ?? '';
unset($_SESSION['education_activity_message']);
$_SESSION['education_activity_csrf'] ??= bin2hex(random_bytes(32));

$db = leadership_db();
$csmDb = education_csm_db();

$records = [];
$allRecords = [];
$files = [];
$deletedFiles = [];
$history = [];
$id = filter_var($_POST['id'] ?? $_GET['edit'] ?? 0, FILTER_VALIDATE_INT);
$form = [
    'activity_date' => $year === (int)substr($today, 0, 4) ? $today : $year . '-01-01',
    'title'         => '',
    'audience'      => '',
    'instructor'    => '',
    'hours'         => '',
    'location'      => '',
    'status'        => 'completed',
    'notes'         => ''
];
$revision = 0;

if ($id === false || $id < 0) {
    http_response_code(400);
    exit('기록 번호를 확인해 주세요.');
}

try {
    // 1. File Download Handler
    if (isset($_GET['file'])) {
        $fileId = filter_var($_GET['file'], FILTER_VALIDATE_INT);
        $statement = $db->prepare('SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE f.id=? AND f.deleted_at IS NULL AND r.item=? AND r.year=?');
        $statement->execute([$fileId ?: 0, $item, $year]);
        $file = $statement->fetch();
        $path = $file ? __DIR__ . '/storage/leadership_evidence/' . $file['stored_name'] : '';
        if (!$file || !is_file($path)) {
            http_response_code(404);
            exit('증빙 파일을 찾을 수 없습니다.');
        }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $file['mime']);
        $inline = ($_GET['inline'] ?? '') === '1' && in_array($file['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"evidence\"; filename*=UTF-8''" . rawurlencode($file['original_name']));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // 2. Load record for editing if specified
    if ($id > 0) {
        $statement = $db->prepare('SELECT * FROM leadership_records WHERE id=? AND item=? AND year=?');
        $statement->execute([$id, $item, $year]);
        $saved = $statement->fetch();
        if (!$saved) {
            http_response_code(404);
            exit('해당 활동기록을 찾을 수 없습니다.');
        }
        $form = array_merge($form, json_decode($saved['payload'], true, 512, JSON_THROW_ON_ERROR), [
            'activity_date' => $saved['activity_date'],
            'title'         => $saved['title']
        ]);
        $revision = (int)$saved['revision'];
    }

    // 3. POST Handlers
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['education_activity_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
        }
        if (!$canManage) {
            throw new InvalidArgumentException('수정 및 저장 권한이 없습니다.');
        }

        $formAction = $_POST['form_action'] ?? '';

        // Assessment level save
        if ($formAction === 'assess') {
            if ($item === '5.1.2' || $item === '5.1.3' || $item === '5.2.2') {
                throw new InvalidArgumentException('5.1.2, 5.1.3 및 5.2.2 항목은 실적 기반 실시간 자동 평가 항목이므로 수동 평가를 저장할 수 없습니다.');
            }
            $assessmentRevision = filter_var($_POST['assessment_revision'] ?? -1, FILTER_VALIDATE_INT);
            if ($assessmentRevision === false || $assessmentRevision < 0) {
                throw new InvalidArgumentException('평가 정보를 확인해 주세요.');
            }
            education_save_assessment($db, $catalog, $year, $item, $_POST['multiplier'] ?? null, $assessmentRevision, (string)$user['login_id']);
            $_SESSION['education_activity_message'] = '평가 수준을 저장했습니다. 유효한 증빙 또는 연동 실적이 있는 경우 점수가 반영됩니다.';
            header('Location: ' . $base, true, 303);
            exit;
        }

        // File delete / restore
        if (isset($_POST['file_action'])) {
            $action = $_POST['file_action'];
            $fileId = filter_var($_POST['file_id'] ?? 0, FILTER_VALIDATE_INT);
            $fileRevision = filter_var($_POST['revision'] ?? -1, FILTER_VALIDATE_INT);
            if (!is_string($action) || !$fileId || $fileRevision === false || $fileRevision < 1) {
                throw new InvalidArgumentException('첨부파일 요청을 확인해 주세요.');
            }
            leadership_change_file($db, $fileId, $id, $fileRevision, $item, $year, $action, (string)$user['login_id']);
            $_SESSION['education_activity_message'] = $action === 'delete_file' ? '첨부 증빙파일을 삭제했습니다.' : '첨부 증빙파일을 복원했습니다.';
            header('Location: ' . $base, true, 303);
            exit;
        }

        // Save activity record
        $record = education_validate($_POST, $item, $year, array_keys($tabTitles));
        $uploads = leadership_uploads($_FILES['evidence'] ?? []);
        $postedRevision = filter_var($_POST['revision'] ?? 0, FILTER_VALIDATE_INT);
        if ($postedRevision === false || $postedRevision < 0) {
            throw new InvalidArgumentException('수정 정보를 확인해 주세요.');
        }

        leadership_save($db, $record, $id, $postedRevision, $uploads, (string)$user['login_id']);
        $_SESSION['education_activity_message'] = '안전보건교육 활동 기록과 첨부 증빙을 성공적으로 저장했습니다.';
        header('Location: ' . $base, true, 303);
        exit;
    }
} catch (Throwable $exception) {
    if ($exception instanceof InvalidArgumentException) {
        $error = $exception->getMessage();
        http_response_code(422);
    } else {
        $error = '활동 기록을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        error_log('CSM education_activity: ' . $exception->getMessage());
        http_response_code(500);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach (array_keys($form) as $key) {
            if (is_string($_POST[$key] ?? null)) {
                $form[$key] = $_POST[$key];
            }
        }
    }
}

// 4. Load Records, Files, and Integrated Trainings
if ($db) {
    try {
        $itemCodesList = "'" . implode("','", array_keys($tabTitles)) . "'";
        $statement = $db->prepare("SELECT * FROM leadership_records WHERE year=? AND item IN ($itemCodesList) ORDER BY activity_date DESC, id DESC");
        $statement->execute([$year]);
        foreach ($statement->fetchAll() as $row) {
            $row['data'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $allRecords[] = $row;
            if ($row['item'] === $item) $records[] = $row;
        }

        $statement = $db->prepare('SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE r.year=? AND r.item=? ORDER BY f.id');
        $statement->execute([$year, $item]);
        foreach ($statement->fetchAll() as $file) {
            if ($file['deleted_at'] !== null) $deletedFiles[$file['record_id']][] = $file;
            else $files[$file['record_id']][] = $file;
        }

        if ($id > 0) {
            $statement = $db->prepare('SELECT * FROM leadership_history WHERE record_id=? ORDER BY revision DESC');
            $statement->execute([$id]);
            $history = $statement->fetchAll();
        }
    } catch (Throwable $exception) {
        $error = '기록 조회에 실패했습니다. 화면을 새로고침해 주세요.';
        error_log('CSM education_activity listing: ' . $exception->getMessage());
    }
}

$counts = array_fill_keys(array_keys($tabTitles), 0);
foreach ($allRecords as $row) {
    if (isset($counts[$row['item']])) {
        $counts[$row['item']]++;
    }
}

// Fetch integrated education sessions from csm.db for this item
$allTrainings = education_trainings($csmDb, $item, $year);

// Monthly stats for 5.1.2 and new hire stats for 5.1.3
$monthlyStats = [];
$regMonthly = null;
$nhSummary = null;
$nhList = [];
$expSummary = null;
if ($item === '5.1.2') {
    require_once __DIR__ . '/scoring.php';
    $regMonthly = csm_regular_edu_monthly_summary($year, 2.0);
    for ($m = 1; $m <= 12; $m++) {
        $monthlyStats[$m] = [
            'count' => 0,
            'hours' => 0.0,
            'attendees' => 0,
            'has_scan' => false,
            'is_finalized' => false,
        ];
    }
    foreach ($allTrainings as $t) {
        $m = (int)substr($t['training_date'], 5, 2);
        if (isset($monthlyStats[$m])) {
            $monthlyStats[$m]['count']++;
            $monthlyStats[$m]['hours'] += (float)$t['planned_hours'];
            $monthlyStats[$m]['attendees'] = max($monthlyStats[$m]['attendees'], (int)$t['completed_attendees']);
            if (!empty($t['scanned_doc_filename']) || !empty($t['cert_doc_filename'])) {
                $monthlyStats[$m]['has_scan'] = true;
            }
            if ($t['status'] === 'FINALIZED') {
                $monthlyStats[$m]['is_finalized'] = true;
            }
        }
    }
} elseif ($item === '5.1.3') {
    require_once __DIR__ . '/scoring.php';
    $nhSummary = csm_new_hire_edu_summary($year, 2.0);
    if ($csmDb) {
        $stmtNh = $csmDb->prepare("
            SELECT t.id, t.training_no, t.training_date, t.planned_hours, t.status, 
                   t.scanned_doc_filename, t.cert_doc_filename,
                   a.employee_no, a.name_snapshot, a.team_snapshot, a.position_snapshot, 
                   a.is_completed, a.completed_hours
            FROM csm_trainings t
            JOIN csm_training_attendees a ON t.id = a.training_id
            WHERE t.csm_item_code = '5.1.3'
              AND strftime('%Y', t.training_date) = :year
              AND a.name_snapshot NOT IN ('김종훈', '진종철', '김남균')
            ORDER BY t.training_date ASC, t.id ASC
        ");
        $stmtNh->execute([':year' => (string)$year]);
        foreach ($stmtNh->fetchAll() as $row) {
            $hasScan = (!empty($row['scanned_doc_filename']) || !empty($row['cert_doc_filename']));
            $isDone = ($hasScan && (int)$row['is_completed'] === 1 && (float)$row['completed_hours'] >= 8.0);
            $row['is_done'] = $isDone;
            $row['has_scan'] = $hasScan;
            $nhList[] = $row;
        }
    }
} elseif ($item === '5.2.2') {
    require_once __DIR__ . '/scoring.php';
    $expSummary = csm_experience_edu_summary($db, $year, 2.0);
}

// Filter trainings & records if a month is selected
$trainings = $allTrainings;
if ($selectedMonth !== null) {
    $trainings = array_values(array_filter($allTrainings, static fn(array $t): bool => (int)substr($t['training_date'], 5, 2) === $selectedMonth));
    $records = array_values(array_filter($records, static fn(array $r): bool => (int)substr($r['activity_date'], 5, 2) === $selectedMonth));
}

// Fetch item catalog metadata & criteria
$catalogItems = education_item_catalog($catalog);
$criterion = $catalogItems[$item] ?? [
    'id' => $item,
    'title' => $tabTitles[$item],
    'points' => 2,
    'evidence' => '',
    'note' => '',
    'criteria' => []
];

// Fetch assessment score from scoring summary
$scoringSummary = $db ? csm_score_summary($db, $catalog, $year)['items'] : [];
$itemScore = $scoringSummary[$item] ?? null;

$openForm = $id > 0 || $error !== '' || (count($records) === 0 && count($trainings) === 0);
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>5. 안전보건교육 활동관리 · CSM</title>
  <link rel="stylesheet" href="leadership.css">
  <link rel="stylesheet" href="management_system.css">
  <link rel="stylesheet" href="education_activity.css">
</head>
<body>

<header>
  <div>
    <a href="/CSM/area.php?group=5">← 안전보건교육</a>
    <strong>5. 안전보건교육 활동관리</strong>
    <span><?= $escape($user['name']) ?></span>
  </div>
</header>

<main>
  <!-- Heading & Year Filter -->
  <div class="heading">
    <div>
      <small>CSM 평가 영역 5 · 법정 교육 및 체험교육 (배점 10점)</small>
      <h1>안전보건교육 활동관리</h1>
      <p>법정 직무교육, 정기·채용·특별·MSDS 교육 및 안전체험교육장 활동기록과 증빙을 한곳에서 관리합니다.</p>
    </div>
    <form method="get" action="/CSM/education_activity.php">
      <input type="hidden" name="item" value="<?= $escape($item) ?>">
      <label for="year">관리 연도</label>
      <input id="year" name="year" type="number" min="2000" max="2100" value="<?= $year ?>" required>
      <button class="primary">조회</button>
    </form>
  </div>

  <!-- Top System Integration Banner -->
  <div class="edu-system-banner">
    <div>
      <strong>교육일지 및 연간계획 시스템 연동 안내</strong>
      <p>실제 실시된 정기·특별·채용·MSDS 교육일지는 교육시스템과 실시간 연동되며, 외부 직무교육 및 체험교육 증빙은 하단에서 등록·보관합니다.</p>
    </div>
    <div class="edu-actions-group">
      <a href="education_matrix.php?year=<?= $year ?>" class="training-btn" style="background: #1e40af; color: #fff; font-weight: bold;">📊 교육 메트릭스 (특별·MSDS) ↗</a>
      <a href="education/" class="training-btn primary">교육일지 시스템 ↗</a>
      <a href="education/plan.php" class="training-btn">연간 교육계획 ↗</a>
      <a href="education/write.php?csm_item=<?= $escape($item) ?>" class="training-btn export">+ 새 교육일지 작성</a>
    </div>
  </div>

  <!-- Tabs (6 items in Group 5) -->
  <nav class="tabs" aria-label="5. 안전보건교육 세부 평가항목" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
    <?php foreach ($tabTitles as $code => $title): 
      $recCount = $counts[$code] ?? 0;
      $pts = $catalogItems[$code]['points'] ?? 2;
      $curScore = isset($scoringSummary[$code]) ? $scoringSummary[$code]['earned'] . "점" : "0점";
    ?>
      <a href="?year=<?= $year ?>&amp;item=<?= $code ?>" <?= $item === $code ? 'aria-current="page"' : '' ?>>
        <small><?= $code ?> · 배점 <?= $pts ?>점 · 현재 <?= $curScore ?></small>
        <strong><?= $escape($title) ?></strong>
        <span>활동 기록 <?= $recCount ?>건</span>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- Messages -->
  <?php if ($message): ?>
    <p class="message" role="status"><?= $escape($message) ?></p>
  <?php endif; ?>
  <?php if ($error): ?>
    <p class="error" role="alert"><?= $escape($error) ?></p>
  <?php endif; ?>

  <!-- Panel 1: Criteria & Self-Assessment -->
  <section class="panel criteria" aria-labelledby="criteria-title">
    <div>
      <div class="section-title">
        <h2 id="criteria-title"><?= $item ?> <?= $escape($criterion['title']) ?></h2>
        <span>배점 <?= $criterion['points'] ?>점</span>
      </div>
      <h3>필요 증빙 및 확인 사항</h3>
      <p class="pre"><?= $escape($criterion['evidence'] ?: '관련 법정 교육일지 및 이수 증빙서류') ?></p>
      <?php if (!empty($criterion['note'])): ?>
        <p class="hint"><?= $escape($criterion['note']) ?></p>
      <?php endif; ?>
    </div>

    <div>
      <details>
        <summary style="cursor: pointer; font-weight: bold; color: var(--blue);">원본 평가 기준 및 배점 산정</summary>
        <dl>
          <?php foreach ($criterion['criteria'] as $level => $text): ?>
            <dt><?= $escape($level) ?></dt>
            <dd><?= $escape($text ?: '—') ?></dd>
          <?php endforeach; ?>
        </dl>
      </details>

      <?php if ($item === '5.1.2'): ?>
        <p class="hint" style="margin-top: 12px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #166534; font-size: 11.5px;">
          ✓ <strong>실시간 자동 집계:</strong> 5.1.2 정기교육은 1.1.3/1.1.4와 같이 월별 서명본 스캔 등록 실적(현재 <?= $regMonthly['completed_months'] ?? 0 ?>/<?= $regMonthly['cutoff'] ?? 11 ?>개월)에 따라 점수가 실시간 자동 반영됩니다.
        </p>
      <?php elseif ($item === '5.1.3'): ?>
        <p class="hint" style="margin-top: 12px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #166534; font-size: 11.5px;">
          ✓ <strong>실시간 자동 집계:</strong> 5.1.3 신규채용시 교육은 5.1.2와 같이 입사자 인원 대비 8시간 교육 및 서명본 스캔 등록 실적(현재 <?= $nhSummary['completed_count'] ?? 0 ?>/<?= $nhSummary['target_count'] ?? 0 ?>명 완료)에 따라 점수가 실시간 자동 반영됩니다.
        </p>
      <?php elseif ($item === '5.1.4' || $item === '5.1.5'):
        $mDone = (int)($itemScore['completed_count'] ?? 0);
        $mDue = (int)($itemScore['due_count'] ?? 0);
        $mEarned = (float)($itemScore['earned'] ?? 0.0);
      ?>
        <p class="hint" style="margin-top: 12px; padding: 10px 14px; background: <?= $mDone > 0 ? '#f0fdf4' : '#fffbeb' ?>; border: 1px solid <?= $mDone > 0 ? '#bbf7d0' : '#fde68a' ?>; border-radius: 6px; color: <?= $mDone > 0 ? '#166534' : '#92400e' ?>; font-size: 12px; line-height: 1.45;">
          ✓ <strong>실시간 자동 집계 (서명본 기준):</strong> 현재 날짜 기준 도래 교육일지 <?= $mDue ?>건 중 서명본 스캔 완료 <strong><?= $mDone ?>/<?= $mDue ?>건</strong> → <strong><?= number_format($mEarned, 2) ?> / 1.0점</strong> 반영 중입니다. (교육일지 스캔본 등록 시 자동 가산)
          <br><a href="education_matrix.php?year=<?= $year ?>&amp;tab=<?= $item === '5.1.4' ? 'special' : 'msds' ?>" style="display: inline-block; margin-top: 6px; font-weight: bold; color: #1e3a8a; text-decoration: underline;">👉 교육 메트릭스(작업내용/물질·교육일·교육받는사람) 상세 관리 바로가기</a>
        </p>
      <?php elseif ($item === '5.2.2' && $expSummary !== null): ?>
        <p class="hint" style="margin-top: 12px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #166534; font-size: 11.5px;">
          ✓ <strong>실시간 자동 집계 (상·하반기):</strong> 현재 날짜(<?= $escape($expSummary['as_of']) ?>) 기준 평가 대상 <?= $expSummary['due_halves'] ?>개 반기 중 <strong><?= $expSummary['completed_halves'] ?>/<?= $expSummary['due_halves'] ?>개 반기</strong> 안전체험교육장 이수·증빙 완료로 <strong><?= number_format($expSummary['earned'], 1) ?> / 2.0점</strong>이 자동 반영됩니다.
        </p>
      <?php elseif ($canManage): ?>
        <form method="post" action="<?= $escape($base) ?>" style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line);">
          <input type="hidden" name="csrf" value="<?= $escape($_SESSION['education_activity_csrf']) ?>">
          <input type="hidden" name="form_action" value="assess">
          <input type="hidden" name="assessment_revision" value="<?= (int)($itemScore['revision'] ?? 0) ?>">
          <label for="multiplier" style="font-size: 11px; font-weight: bold; color: var(--muted);">평가 수준 설정 (증빙 첨부 시 반영)</label>
          <div style="display: flex; gap: 8px; margin-top: 5px;">
            <select id="multiplier" name="multiplier" style="max-width: 260px;">
              <option value="">평가 수준 선택</option>
              <?php foreach ($criterion['criteria'] as $lvl => $lbl): if (is_numeric($lvl)): 
                $calcPts = round((float)$criterion['points'] * (float)$lvl, 2);
                $displayLabel = ($lbl === '-' || trim($lbl) === '') ? '충족 (만점)' : $lbl;
              ?>
                <option value="<?= $lvl ?>" <?= isset($itemScore['multiplier']) && (string)$itemScore['multiplier'] === (string)(float)$lvl ? 'selected' : '' ?>>
                  <?= $lvl ?> · <?= $escape(str_replace("\n", ' ', $displayLabel)) ?> (<?= $calcPts ?>점)
                </option>
              <?php endif; endforeach; ?>
            </select>
            <button class="primary" style="padding: 6px 12px;">평가 저장</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <!-- Panel 1.5: 5.1.2 Monthly Regular Training Board (leadership.php 1.1.3 style) -->
  <?php if ($item === '5.1.2' && $regMonthly !== null): ?>
    <section class="panel" id="monthly-edu">
      <div class="section-title">
        <h2>월별 정기안전보건교육 실시 현황 (1~12월)</h2>
        <span>서명본 등록 월수 비례 실시간 점수 반영</span>
      </div>

      <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin: 10px 0 16px;">
        <div style="font-size: 15px; font-weight: bold; color: #1e3a8a;">
          1월~<?= $regMonthly['cutoff'] ?>월 합계 <?= $regMonthly['total'] ?>점 ÷ <?= $regMonthly['cutoff'] ?>개월 = <span style="color: #2563eb; font-size: 17px;"><?= $regMonthly['earned'] ?>점</span>
          <span style="font-size: 12px; font-weight: normal; color: #3b82f6; margin-left: 8px;">(현재 <?= $regMonthly['completed_months'] ?>/<?= $regMonthly['cutoff'] ?>개월 서명본 완료)</span>
        </div>
        <p class="hint" style="margin: 6px 0 0; color: #475569; font-size: 11.5px;">
          올해는 현재 월(11월)까지, 지난 연도는 12개월을 평균합니다. 미실시·서명본 미첨부 월은 0점으로 포함하고, 미래 월은 제외합니다.
        </p>
      </div>

      <div class="months">
        <?php for ($month = 1; $month <= 12; $month++): 
          $st = $monthlyStats[$month];
          $mCount = $st['count'];
          $mHours = $st['hours'];
          $mAtt = $st['attendees'];
          $mHasScan = $st['has_scan'];
          $mFinalized = $st['is_finalized'];
          $isDone = ($mHasScan || $mFinalized);
          $isFuture = ($month > $regMonthly['cutoff']);
          
          $mScoreText = $isFuture ? '0 / 2점' : ($isDone ? '2 / 2점' : '0 / 2점');
          $stateText = $isFuture ? '평가 전' : ($isDone ? '서명본 완료' : '서명본 없음');
          $stateColor = $isFuture ? 'color: var(--muted);' : ($isDone ? 'color: #059669; font-weight: bold;' : 'color: #dc2626;');
        ?>
          <a href="<?= $escape($allMonthsBase) ?>&amp;month=<?= $month ?>#monthly-edu" <?= $selectedMonth === $month ? 'aria-current="true"' : '' ?> aria-label="<?= $month ?>월 정기교육 기록 <?= $mCount ?>건 보기">
            <small><?= $month ?>월</small>
            <strong><?= $mCount > 0 ? $mCount . '회 (' . (float)$mHours . 'h)' : '미실시' ?></strong>
            <span class="month-score"><?= $mScoreText ?></span>
            <span class="month-rating-state" style="<?= $stateColor ?>"><?= $stateText ?></span>
          </a>
        <?php endfor; ?>
      </div>
      <div class="month-filter-status" style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 12px;">
        <span><?= $selectedMonth !== null ? '<strong>' . $selectedMonth . '월</strong> 정기교육 기록 조회 중' : '전체 월 (1~12월) 기록 조회 중' ?></span>
        <a href="<?= $escape($allMonthsBase) ?>#monthly-edu" style="color: var(--blue); text-decoration: underline; font-weight: bold;">전체 월 기록 보기</a>
      </div>
      <p class="hint">
        사내 운영규칙: 매월 4주차 금요일 13:00~15:00 정기교육(2h) 실시. 6월 관리감독자 16시간 교육 포함.<br>
        ※ 서명본 스캔이 등록된 월만 2점 만점으로 인정되며, 미등록 월은 0점으로 평균 산정됩니다.
      </p>
    </section>
  <?php endif; ?>

  <!-- Panel 1.5b: 5.1.3 New Hire Training Board (5.1.2 style) -->
  <?php if ($item === '5.1.3' && $nhSummary !== null): ?>
    <section class="panel" id="newhire-edu">
      <div class="section-title">
        <h2>신규 채용 근로자 안전보건교육(8h) 실시 현황</h2>
        <span>입사자별 서명본 등록 실적 비례 실시간 점수 반영</span>
      </div>

      <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin: 10px 0 16px;">
        <div style="font-size: 15px; font-weight: bold; color: #1e3a8a;">
          전체 대상 <?= $nhSummary['target_count'] ?>명 중 서명본 완료 <?= $nhSummary['completed_count'] ?>명 = <span style="color: #2563eb; font-size: 17px;"><?= $nhSummary['earned'] ?>점</span>
          <span style="font-size: 12px; font-weight: normal; color: #3b82f6; margin-left: 8px;">(현재 반영률 <?= round($nhSummary['multiplier'] * 100) ?>% · 2.0점 만점)</span>
        </div>
        <p class="hint" style="margin: 6px 0 0; color: #475569; font-size: 11.5px;">
          산안법 제29조 기준 신규 채용 근로자는 8시간 교육 이수 및 자필 서명본 스캔 등록 시 정상 인정됩니다. 미실시·서명본 미등록 인원은 0점으로 계산되어 비율 반영됩니다.
        </p>
      </div>

      <div class="months" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
        <?php foreach ($nhList as $nh): 
          $isDone = !empty($nh['is_done']);
          $ptsPerPerson = $nhSummary['target_count'] > 0 ? round(2.0 / $nhSummary['target_count'], 2) : 0.0;
          $scoreText = $isDone ? $ptsPerPerson . ' / ' . $ptsPerPerson . '점' : '0 / ' . $ptsPerPerson . '점';
          $stateText = $isDone ? '서명본 완료' : (!empty($nh['has_scan']) ? '이수 확인중' : '서명본 없음');
          $stateColor = $isDone ? 'color: #059669; font-weight: bold;' : 'color: #dc2626;';
        ?>
          <a href="education/view.php?id=<?= $nh['id'] ?>" style="background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 12px; text-align: left; display: flex; flex-direction: column; gap: 4px; text-decoration: none; color: inherit; transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 2px 6px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow='none'">
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <strong style="font-size: 14px; color: var(--text);"><?= $escape($nh['name_snapshot']) ?></strong>
              <small style="color: var(--muted);"><?= $escape($nh['team_snapshot']) ?> (<?= $escape($nh['position_snapshot'] ?: '사원') ?>)</small>
            </div>
            <div style="font-size: 11.5px; color: #475569;">
              교육일자: <strong><?= $escape($nh['training_date']) ?></strong>
            </div>
            <div style="font-size: 11.5px; color: #475569;">
              교육시간: <strong><?= (float)$nh['completed_hours'] ?>h / 8.0h</strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--line); font-size: 11.5px;">
              <span class="month-score" style="font-weight: bold;"><?= $scoreText ?></span>
              <span style="<?= $stateColor ?>"><?= $stateText ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="hint" style="margin-top: 10px;">
        ※ 각 신규 입사자 교육일지의 서명 스캔본 등록 시 자동으로 실시완료 및 점수가 실시간 갱신됩니다. 카드를 클릭하면 해당 일지 상세 페이지로 이동합니다.
      </p>
    </section>
  <?php endif; ?>

  <!-- Panel 1.5c: 5.2.2 Half-Year Experience Training Board -->
  <?php if ($item === '5.2.2' && $expSummary !== null): ?>
    <section class="panel" id="experience-edu">
      <div class="section-title">
        <h2>안전체험교육장 상·하반기 이수 현황</h2>
        <span>현재 날짜 기준 상반기·하반기 완료 실적 자동 점수 반영</span>
      </div>

      <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin: 10px 0 16px;">
        <div style="font-size: 15px; font-weight: bold; color: #1e3a8a;">
          기준일(<?= $escape($expSummary['as_of']) ?>) 대상 <?= $expSummary['due_halves'] ?>개 반기 중 완료 <?= $expSummary['completed_halves'] ?>개 반기 =
          <span style="color: #2563eb; font-size: 17px;"><?= number_format($expSummary['earned'], 1) ?>점</span>
          <span style="font-size: 12px; font-weight: normal; color: #3b82f6; margin-left: 8px;">(반영률 <?= round($expSummary['multiplier'] * 100) ?>% · 2.0점 만점)</span>
        </div>
        <p class="hint" style="margin: 6px 0 0; color: #475569; font-size: 11.5px;">
          상반기(1~6월)에는 상반기 완료 시 2.0점(100%), 하반기(7~12월)에는 상·하반기 각 1.0점씩 합산하여 2.0점 만점으로 자동 산정됩니다. (교육일지 스캔본/수료증 등록 및 교육일자 경과 기준)
        </p>
      </div>

      <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;">
        <?php foreach ([1, 2] as $h):
          $hf = $expSummary['halves'][$h];
          $perHalfPts = $expSummary['due_halves'] > 0 ? round(2.0 / $expSummary['due_halves'], 1) : 1.0;
          $earnedHalf = ($hf['due'] && $hf['completed']) ? $perHalfPts : 0.0;
          $stateLabel = !$hf['due'] ? '평가 전 (예정)' : ($hf['completed'] ? '이수·증빙 완료' : '미완료 (증빙 없음)');
          $stateColor = !$hf['due'] ? '#64748b' : ($hf['completed'] ? '#059669' : '#dc2626');
          $borderColor = !$hf['due'] ? 'var(--line)' : ($hf['completed'] ? '#86efac' : '#fecaca');
        ?>
          <div style="background: #fff; border: 1.5px solid <?= $borderColor ?>; border-radius: 10px; padding: 14px 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <strong style="font-size: 15px;"><?= $escape($hf['label']) ?></strong>
              <span style="font-size: 12.5px; font-weight: bold; color: <?= $stateColor ?>;"><?= $stateLabel ?></span>
            </div>
            <div style="font-size: 13px; margin-bottom: 10px; color: #334155;">
              반영 점수: <strong><?= number_format($earnedHalf, 1) ?> / <?= number_format($hf['due'] ? $perHalfPts : 0.0, 1) ?>점</strong>
              (증빙 <?= (int)$hf['evidence_count'] ?>건)
            </div>
            <?php if (!empty($hf['sessions'])): ?>
              <div style="display: flex; flex-direction: column; gap: 6px;">
                <?php foreach ($hf['sessions'] as $sess): ?>
                  <a href="education/view.php?id=<?= (int)$sess['id'] ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 7px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; text-decoration: none; color: inherit;">
                    <span><strong><?= $escape($sess['training_date']) ?></strong> · <?= $escape($sess['subject']) ?></span>
                    <span style="font-weight: bold; color: <?= $sess['is_done'] ? '#059669' : '#d97706' ?>; white-space: nowrap; margin-left: 8px;"><?= $sess['is_done'] ? '✓ 완료' : '증빙 대기' ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div style="font-size: 12px; color: #64748b; padding: 8px; background: #f8fafc; border-radius: 6px;">등록된 안전체험교육장 이수 실적이 없습니다.</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- Panel 2: Integrated Training Sessions from education/ (csm_trainings) -->
  <section class="training-panel" aria-labelledby="training-section-title">
    <div class="training-panel-header">
      <h3 id="training-section-title">
        <span>연동된 안전보건교육 실시 실적</span>
        <span class="training-count-badge"><?= count($trainings) ?>건</span>
      </h3>
      <div class="training-btn-group">
        <a href="education/write.php?csm_item=<?= $escape($item) ?>" class="training-btn primary">+ 새 교육일지 작성</a>
        <a href="education/" class="training-btn">전체 교육일지 조회 ↗</a>
      </div>
    </div>

    <?php if (empty($trainings)): ?>
      <p class="empty">선택한 연도(<?= $year ?>년)에 등록된 해당 항목의 교육일지 데이터가 없습니다.<br>오른쪽 상단의 <strong>[+ 새 교육일지 작성]</strong> 버튼을 통해 교육일지를 등록할 수 있습니다.</p>
    <?php else: ?>
      <div class="training-list">
        <?php foreach ($trainings as $t): 
          $isFinalized = ($t['status'] ?? '') === 'FINALIZED';
          $badgeClass = $isFinalized ? 'status-finalized' : 'status-draft';
          $badgeText = $isFinalized ? '실시완료' : '작성중(예정)';
        ?>
          <div class="training-item-card">
            <div class="training-info">
              <div class="training-meta">
                <span class="training-no"><?= $escape($t['training_no']) ?></span>
                <span>•</span>
                <span>실시일자: <strong><?= $escape($t['training_date']) ?></strong> (<?= $escape($t['start_time'] ?: '13:00') ?>~<?= $escape($t['end_time'] ?: '15:00') ?>)</span>
                <span>•</span>
                <span class="training-badge-status <?= $badgeClass ?>"><?= $badgeText ?></span>
                <?php if ($t['is_demo']): ?>
                  <span style="background: #fee2e2; color: #991b1b; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 4px;">시연용 가명</span>
                <?php endif; ?>
              </div>
              <div class="training-subject"><?= $escape($t['subject']) ?></div>
              <div class="training-details">
                <span><i style="color:var(--muted)">이수/대상:</i> <strong><?= (int)$t['completed_attendees'] ?>명</strong> / <?= (int)$t['total_attendees'] ?>명</span>
                <span><i style="color:var(--muted)">교육시간:</i> <?= $escape($t['planned_hours']) ?>시간</span>
                <span><i style="color:var(--muted)">강사:</i> <?= $escape($t['instructor_name'] ?: '김남균') ?> (<?= $escape($t['instructor_position'] ?: '과장') ?>)</span>
                <span><i style="color:var(--muted)">장소:</i> <?= $escape($t['location'] ?: '현장 사무실') ?></span>
                <?php if (!empty($t['msds_material_name'])): ?>
                  <span><i style="color:var(--muted)">MSDS:</i> <?= $escape($t['msds_material_name']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="training-btn-group">
              <a href="education/view.php?id=<?= $t['id'] ?>" class="training-btn primary">일지 확인 ↗</a>
              <a href="education/print.php?id=<?= $t['id'] ?>&amp;print=1" class="training-btn" target="_blank" rel="noopener" style="background:#2563eb; border-color:#2563eb; color:#fff; font-weight:bold;">🖨️ 바로 인쇄</a>
              <?php if (!empty($t['scanned_doc_filename'])): ?>
                <a href="education/secure_file.php?type=scan&amp;file=<?= urlencode($t['scanned_doc_filename']) ?>" onclick="openDocViewer(this.href, '교육일지 자필 서명본 (<?= htmlspecialchars($t['training_no']) ?>)', '<?= htmlspecialchars($t['scanned_doc_filename']) ?>'); return false;" class="training-btn cert" target="_blank" rel="noopener" style="background:#059669; border-color:#059669; color:#fff; font-weight:bold;">
                  📄 서명본 스캔 ↗
                </a>
              <?php endif; ?>
              <?php if (!empty($t['cert_doc_filename'])): ?>
                <a href="education/secure_file.php?type=scan&amp;file=<?= urlencode($t['cert_doc_filename']) ?>" onclick="openDocViewer(this.href, '공식 교육이수증 (<?= htmlspecialchars($t['training_no']) ?>)', '<?= htmlspecialchars($t['cert_doc_filename']) ?>'); return false;" class="training-btn cert" target="_blank" rel="noopener" style="background:#2563eb; border-color:#2563eb; color:#fff; font-weight:bold;">
                  🏅 교육이수증 ↗
                </a>
              <?php endif; ?>
              <?php if ($canManage): ?>
                <a href="education/write.php?id=<?= $t['id'] ?>" class="training-btn">수정 ↗</a>
              <?php endif; ?>
              <a href="education/export.php?id=<?= $t['id'] ?>&amp;format=xlsx" class="training-btn export">엑셀 서식</a>
              <a href="education/export.php?id=<?= $t['id'] ?>&amp;format=pdf" class="training-btn export" target="_blank" rel="noopener">PDF 인쇄</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Panel 3: Activity Records & Attached Evidence Files (leadership_records) -->
  <section class="panel" aria-labelledby="activity-records-title">
    <div class="section-title">
      <h3 id="activity-records-title" style="margin:0; font-size:15px; color:#1e3a8a;">
        활동 기록 및 증빙 파일 관리 (직무교육 이수증, 체험교육 수료증, 특별교육 Matrix 등)
      </h3>
      <span>등록된 증빙 <?= count($records) ?>건</span>
    </div>

    <!-- Record List -->
    <?php if (empty($records)): ?>
      <p class="empty" style="padding: 16px 0;">등록된 별도 활동기록/증빙 파일이 없습니다. 외부 직무교육 이수증, 안전체험교육장 수료증 또는 특별교육 Matrix 문서는 아래 <strong>[활동 기록 및 증빙 등록]</strong>에서 첨부하세요.</p>
    <?php else: ?>
      <?php foreach ($records as $r): 
        $rFiles = $files[$r['id']] ?? [];
        $p = $r['data'] ?? [];
      ?>
        <article class="record" id="record-<?= $r['id'] ?>">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; flex-wrap: wrap;">
            <div>
              <div style="font-size: 11px; color: var(--muted); margin-bottom: 2px;">
                실시일자: <strong><?= $escape($r['activity_date']) ?></strong>
                <?php if (!empty($p['instructor'])): ?> · 강사/기관: <?= $escape($p['instructor']) ?><?php endif; ?>
                <?php if (!empty($p['hours'])): ?> · 시간: <?= $escape($p['hours']) ?><?php endif; ?>
                <?php if (!empty($p['location'])): ?> · 장소: <?= $escape($p['location']) ?><?php endif; ?>
              </div>
              <h4 style="margin: 3px 0 6px; font-size: 15px; color: #0f172a;"><?= $escape($r['title']) ?></h4>
              <?php if (!empty($p['audience'])): ?>
                <div style="font-size: 12px; color: #475569; margin-bottom: 4px;">
                  <strong>교육 대상/이수자:</strong> <?= $escape($p['audience']) ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($p['notes'])): ?>
                <p class="pre" style="margin: 6px 0; color: #334155; font-size: 12px;"><?= $escape($p['notes']) ?></p>
              <?php endif; ?>
            </div>
            <?php if ($canManage): ?>
              <div style="display: flex; gap: 6px;">
                <a href="<?= $escape($base) ?>&amp;edit=<?= $r['id'] ?>#record-editor" class="button">수정</a>
              </div>
            <?php endif; ?>
          </div>

          <!-- File Attachments -->
          <?php if (!empty($rFiles)): ?>
            <div class="file-links" style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
              <span style="font-weight: bold; font-size: 11px; color: #475569;">첨부 증빙문서:</span>
              <?php foreach ($rFiles as $f): 
                $isPdf = $f['mime'] === 'application/pdf';
                $isImg = in_array($f['mime'], ['image/jpeg', 'image/png'], true);
              ?>
                <a href="<?= $escape($base) ?>&amp;file=<?= $f['id'] ?>&amp;inline=1" onclick="openDocViewer(this.href, '증빙 파일: <?= $escape($f['original_name']) ?>', '<?= $escape($f['original_name']) ?>'); return false;" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 7px; color: #15803d; font-size: 12px; font-weight: 600; text-decoration: none;">
                  <span><?= $isPdf ? '📄' : ($isImg ? '🖼️' : '📎') ?></span>
                  <span><?= $escape($f['original_name']) ?></span>
                  <span style="font-size: 10px; color: #166534; font-weight: normal;">(<?= number_format($f['size'] / 1024, 1) ?>KB)</span>
                  <span style="background: #22c55e; color: #fff; font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 4px; margin-left: 2px;">열람 ↗</span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Record Editor (Add / Modify) -->
    <?php if ($canManage): ?>
      <details class="editor" id="record-editor" <?= $openForm ? 'open' : '' ?> style="margin-top: 20px; border-top: 2px dashed var(--line); padding-top: 16px;">
        <summary style="font-size: 14px; font-weight: bold; color: var(--blue);">
          <?= $id > 0 ? "활동 기록 수정 (기록번호 #$id)" : "+ 새 활동 기록 및 증빙 파일 등록" ?>
        </summary>

        <form method="post" action="<?= $escape($base) ?>" enctype="multipart/form-data" style="margin-top: 15px;">
          <input type="hidden" name="csrf" value="<?= $escape($_SESSION['education_activity_csrf']) ?>">
          <input type="hidden" name="form_action" value="save_record">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="revision" value="<?= $revision ?>">

          <div class="fields">
            <div>
              <label for="activity_date">교육/활동 실시일 <span style="color:red">*</span></label>
              <input type="date" id="activity_date" name="activity_date" value="<?= $escape($form['activity_date']) ?>" required>
            </div>
            <div>
              <label for="title">활동명 / 교육 과정명 <span style="color:red">*</span></label>
              <input type="text" id="title" name="title" value="<?= $escape($form['title']) ?>" placeholder="예: 2026년 상반기 안전보건관리책임자 직무보수교육 이수" required>
            </div>
            <div>
              <label for="audience">교육 대상 및 이수자</label>
              <input type="text" id="audience" name="audience" value="<?= $escape($form['audience']) ?>" placeholder="예: 안전보건관리책임자 1명 (김남균 과장)">
            </div>
            <div>
              <label for="instructor">강사명 또는 주관 교육기관</label>
              <input type="text" id="instructor" name="instructor" value="<?= $escape($form['instructor']) ?>" placeholder="예: 한국산업안전보건공단 / 한라시멘트 안전체험교육장">
            </div>
            <div>
              <label for="hours">교육 시간</label>
              <input type="text" id="hours" name="hours" value="<?= $escape($form['hours']) ?>" placeholder="예: 6시간, 16시간, 34시간 등">
            </div>
            <div>
              <label for="location">교육 장소</label>
              <input type="text" id="location" name="location" value="<?= $escape($form['location']) ?>" placeholder="예: 공단 강원지역본부 교육장 / 사내 교육장">
            </div>
            <div class="wide">
              <label for="notes">활동 내용 요약 및 특이사항</label>
              <textarea id="notes" name="notes" rows="4" placeholder="교육 이수 내용, 주요 확인 사항, 이수 번호 또는 수료 특이사항을 기록합니다."><?= $escape($form['notes']) ?></textarea>
            </div>
            <div class="wide">
              <label for="evidence">증빙 파일 첨부 (이수증/수료증 스캔본, 영수증, 출석부, 공문 등 - PDF/JPG/PNG, 최대 5개)</label>
              <input type="file" id="evidence" name="evidence[]" multiple accept=".pdf,.jpg,.jpeg,.png">
              <p class="hint">파일당 최대 10MB, 총 30MB까지 첨부할 수 있습니다.</p>
            </div>
          </div>

          <!-- Attached files for current record being edited -->
          <?php if ($id > 0 && !empty($files[$id])): ?>
            <div style="margin: 14px 0; padding: 12px; background: #f8fafc; border-radius: 8px;">
              <strong>현재 첨부된 파일:</strong>
              <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 6px;">
                <?php foreach ($files[$id] as $f): ?>
                  <div style="display: flex; align-items: center; gap: 6px; background: #fff; padding: 4px 8px; border: 1px solid var(--line); border-radius: 5px;">
                    <a href="<?= $escape($base) ?>&amp;file=<?= $f['id'] ?>" target="_blank" rel="noopener"><?= $escape($f['original_name']) ?></a>
                    <button type="submit" name="file_action" value="delete_file" onclick="document.getElementById('file_id_input').value='<?= $f['id'] ?>';" style="padding: 2px 5px; font-size: 10px; color: #dc2626;">삭제</button>
                  </div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" id="file_id_input" name="file_id" value="">
            </div>
          <?php endif; ?>

          <div class="form-actions">
            <button class="primary" style="padding: 9px 20px; font-size: 13px;">
              <?= $id > 0 ? "활동 기록 수정 완료" : "활동 기록 및 증빙 등록" ?>
            </button>
            <?php if ($id > 0): ?>
              <a href="<?= $escape($base) ?>" class="button">수정 취소</a>
            <?php endif; ?>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <p class="hint">5. 안전보건교육 활동관리는 법정 의무 교육과 안전체험교육장의 실적 및 증빙자료를 통합 관리하며, 저장된 증빙 및 실적은 CSM 평가 집계에 반영됩니다.</p>
</main>

<?php require_once __DIR__ . '/education/doc_viewer_modal.php'; ?>
</body>
</html>
