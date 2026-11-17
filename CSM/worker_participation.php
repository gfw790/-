<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/worker_participation_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$db = worker_part_db();

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year = filter_var($_GET['year'] ?? substr($today, 0, 4), FILTER_VALIDATE_INT) ?: 2026;
$tab = $_GET['tab'] ?? 'suggestions';
if (!in_array($tab, ['suggestions', 'meetings', 'champions', 'feedback'], true)) {
    $tab = 'suggestions';
}

$message = '';
$error = '';

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="CSM_worker_participation_' . $tab . '_' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

    if ($tab === 'suggestions') {
        fputcsv($out, ['접수번호', '접수일자', '제안자', '소속', '제안명', '유형', '위치', '문제점', '개선방안', '상태', '조치담당자', '조치완료일', '환류일자', '포상']);
        $stmt = $db->prepare('SELECT * FROM csm_suggestions WHERE year = ? ORDER BY reg_date DESC');
        $stmt->execute([$year]);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['reg_no'], $row['reg_date'], $row['proposer_name'], $row['proposer_dept'],
                $row['title'], $row['category'], $row['location'], $row['problem'],
                $row['solution'], $row['status'], $row['action_manager'], $row['action_date'],
                $row['feedback_date'], $row['reward_note']
            ]);
        }
    } elseif ($tab === 'meetings') {
        fputcsv($out, ['구분', '회의명', '일시', '장소', '주관자', '참석인원', '주요안건', '의결사항', '공유방법']);
        $stmt = $db->prepare('SELECT * FROM csm_safety_meetings WHERE year = ? ORDER BY meeting_date DESC');
        $stmt->execute([$year]);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['meeting_type'], $row['meeting_name'], $row['meeting_date'], $row['meeting_place'],
                $row['chairperson'], $row['attendees_count'], $row['agenda'], $row['decisions'], $row['result_sharing']
            ]);
        }
    }
    fclose($out);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_suggestion') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $reg_no = trim($_POST['reg_no'] ?? '');
            $reg_date = trim($_POST['reg_date'] ?? $today);
            $proposer_name = trim($_POST['proposer_name'] ?? '');
            $proposer_dept = trim($_POST['proposer_dept'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $category = trim($_POST['category'] ?? '작업장 안전');
            $location = trim($_POST['location'] ?? '');
            $problem = trim($_POST['problem'] ?? '');
            $solution = trim($_POST['solution'] ?? '');
            $expected_effect = trim($_POST['expected_effect'] ?? '');
            $status = trim($_POST['status'] ?? '접수');
            $review_date = $_POST['review_date'] ?: null;
            $reviewer_name = $_POST['reviewer_name'] ?: null;
            $review_opinion = $_POST['review_opinion'] ?: null;
            $action_plan = $_POST['action_plan'] ?: null;
            $action_manager = $_POST['action_manager'] ?: null;
            $action_deadline = $_POST['action_deadline'] ?: null;
            $action_date = $_POST['action_date'] ?: null;
            $action_result = $_POST['action_result'] ?: null;
            $feedback_date = $_POST['feedback_date'] ?: null;
            $feedback_method = $_POST['feedback_method'] ?: null;
            $reward_points = (float)($_POST['reward_points'] ?? 0);
            $reward_note = $_POST['reward_note'] ?: null;

            if ($title === '' || $proposer_name === '') {
                throw new InvalidArgumentException('제안명과 제안자 성명은 필수입니다.');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE csm_suggestions SET
                    reg_no = ?, reg_date = ?, proposer_name = ?, proposer_dept = ?, title = ?,
                    category = ?, location = ?, problem = ?, solution = ?, expected_effect = ?,
                    status = ?, review_date = ?, reviewer_name = ?, review_opinion = ?, action_plan = ?,
                    action_manager = ?, action_deadline = ?, action_date = ?, action_result = ?,
                    feedback_date = ?, feedback_method = ?, reward_points = ?, reward_note = ?, updated_at = datetime("now","localtime")
                    WHERE id = ?');
                $stmt->execute([
                    $reg_no, $reg_date, $proposer_name, $proposer_dept, $title,
                    $category, $location, $problem, $solution, $expected_effect,
                    $status, $review_date, $reviewer_name, $review_opinion, $action_plan,
                    $action_manager, $action_deadline, $action_date, $action_result,
                    $feedback_date, $feedback_method, $reward_points, $reward_note, $id
                ]);
                $message = '안전제안 내역이 성공적으로 수정되었습니다.';
            } else {
                if ($reg_no === '') {
                    $reg_no = sprintf('%d-S%03d', $year, $db->query("SELECT COUNT(*) + 1 FROM csm_suggestions WHERE year = $year")->fetchColumn());
                }
                $stmt = $db->prepare('INSERT INTO csm_suggestions (
                    year, reg_no, reg_date, proposer_name, proposer_dept, title, category, location,
                    problem, solution, expected_effect, status, review_date, reviewer_name, review_opinion,
                    action_plan, action_manager, action_deadline, action_date, action_result,
                    feedback_date, feedback_method, reward_points, reward_note, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, datetime("now","localtime"), datetime("now","localtime")
                )');
                $stmt->execute([
                    $year, $reg_no, $reg_date, $proposer_name, $proposer_dept, $title, $category, $location,
                    $problem, $solution, $expected_effect, $status, $review_date, $reviewer_name, $review_opinion,
                    $action_plan, $action_manager, $action_deadline, $action_date, $action_result,
                    $feedback_date, $feedback_method, $reward_points, $reward_note
                ]);
                $message = '새로운 안전제안이 접수·등록되었습니다.';
            }
        } elseif ($action === 'delete_suggestion') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id > 0) {
                $db->prepare('DELETE FROM csm_suggestions WHERE id = ?')->execute([$id]);
                $message = '안전제안 건이 삭제되었습니다.';
            }
        } elseif ($action === 'save_meeting') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $meeting_type = trim($_POST['meeting_type'] ?? '안전보건협의회');
            $meeting_name = trim($_POST['meeting_name'] ?? '');
            $meeting_date = trim($_POST['meeting_date'] ?? $today);
            $meeting_place = trim($_POST['meeting_place'] ?? '');
            $chairperson = trim($_POST['chairperson'] ?? '');
            $attendees_text = trim($_POST['attendees_text'] ?? '');
            $attendees_count = (int)($_POST['attendees_count'] ?? 0);
            $agenda = trim($_POST['agenda'] ?? '');
            $deliberation_content = trim($_POST['deliberation_content'] ?? '');
            $decisions = trim($_POST['decisions'] ?? '');
            $result_sharing = trim($_POST['result_sharing'] ?? '');

            if ($meeting_name === '') {
                throw new InvalidArgumentException('회의명은 필수입니다.');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE csm_safety_meetings SET
                    meeting_type = ?, meeting_name = ?, meeting_date = ?, meeting_place = ?,
                    chairperson = ?, attendees_text = ?, attendees_count = ?, agenda = ?,
                    deliberation_content = ?, decisions = ?, result_sharing = ?, updated_at = datetime("now","localtime")
                    WHERE id = ?');
                $stmt->execute([
                    $meeting_type, $meeting_name, $meeting_date, $meeting_place,
                    $chairperson, $attendees_text, $attendees_count, $agenda,
                    $deliberation_content, $decisions, $result_sharing, $id
                ]);
                $message = '회의록이 수정되었습니다.';
            } else {
                $stmt = $db->prepare('INSERT INTO csm_safety_meetings (
                    year, meeting_type, meeting_name, meeting_date, meeting_place,
                    chairperson, attendees_text, attendees_count, agenda,
                    deliberation_content, decisions, result_sharing, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, datetime("now","localtime"), datetime("now","localtime")
                )');
                $stmt->execute([
                    $year, $meeting_type, $meeting_name, $meeting_date, $meeting_place,
                    $chairperson, $attendees_text, $attendees_count, $agenda,
                    $deliberation_content, $decisions, $result_sharing
                ]);
                $message = '새로운 안전보건 회의록이 등록되었습니다.';
            }
        } elseif ($action === 'delete_meeting') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id > 0) {
                $db->prepare('DELETE FROM csm_safety_meetings WHERE id = ?')->execute([$id]);
                $message = '회의록이 삭제되었습니다.';
            }
        } elseif ($action === 'save_feedback') {
            $half = (int)($_POST['half'] ?? 1);
            $report_title = trim($_POST['report_title'] ?? '');
            $report_date = trim($_POST['report_date'] ?? $today);
            $manager_name = trim($_POST['manager_name'] ?? '');
            $summary_opinions = trim($_POST['summary_opinions'] ?? '');
            $action_status_summary = trim($_POST['action_status_summary'] ?? '');
            $feedback_notice_summary = trim($_POST['feedback_notice_summary'] ?? '');
            $future_improvements = trim($_POST['future_improvements'] ?? '');
            $status = trim($_POST['status'] ?? '작성');

            $stmt = $db->prepare('INSERT INTO csm_feedback_reports (
                year, half, report_title, report_date, manager_name,
                summary_opinions, action_status_summary, feedback_notice_summary, future_improvements,
                status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now","localtime"), datetime("now","localtime"))
            ON CONFLICT(year, half) DO UPDATE SET
                report_title = excluded.report_title,
                report_date = excluded.report_date,
                manager_name = excluded.manager_name,
                summary_opinions = excluded.summary_opinions,
                action_status_summary = excluded.action_status_summary,
                feedback_notice_summary = excluded.feedback_notice_summary,
                future_improvements = excluded.future_improvements,
                status = excluded.status,
                updated_at = datetime("now","localtime")');
            $stmt->execute([
                $year, $half, $report_title, $report_date, $manager_name,
                $summary_opinions, $action_status_summary, $feedback_notice_summary, $future_improvements, $status
            ]);
            $message = ($half === 1 ? '상반기' : '하반기') . ' 의견수렴·환류 보고서가 저장되었습니다.';
        } elseif ($action === 'save_champion') {
            $month = (int)($_POST['month'] ?? 1);
            $recipient_name = trim($_POST['recipient_name'] ?? '');
            $recipient_role = trim($_POST['recipient_role'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $score_near_miss = (int)($_POST['score_near_miss'] ?? 0);
            $score_suggestion = (int)($_POST['score_suggestion'] ?? 0);
            $score_attendance = (int)($_POST['score_attendance'] ?? 0);
            $score_other = (int)($_POST['score_other'] ?? 0);
            $total_score = $score_near_miss + $score_suggestion + $score_attendance + $score_other;
            $reward_content = trim($_POST['reward_content'] ?? '');

            $stmt = $db->prepare('INSERT INTO csm_safety_champions (
                year, month, recipient_name, recipient_role, award_title, reason,
                score_near_miss, score_suggestion, score_attendance, score_other, total_score, reward_content,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, "이달의 안전인", ?, ?, ?, ?, ?, ?, ?, datetime("now","localtime"), datetime("now","localtime"))
            ON CONFLICT(year, month) DO UPDATE SET
                recipient_name = excluded.recipient_name,
                recipient_role = excluded.recipient_role,
                reason = excluded.reason,
                score_near_miss = excluded.score_near_miss,
                score_suggestion = excluded.score_suggestion,
                score_attendance = excluded.score_attendance,
                score_other = excluded.score_other,
                total_score = excluded.total_score,
                reward_content = excluded.reward_content,
                updated_at = datetime("now","localtime")');
            $stmt->execute([
                $year, $month, $recipient_name, $recipient_role, $reason,
                $score_near_miss, $score_suggestion, $score_attendance, $score_other, $total_score, $reward_content
            ]);
            $message = $month . '월 이달의 안전인이 선정·저장되었습니다.';
        } elseif ($action === 'save_signature') {
            $doc_type = trim($_POST['doc_type'] ?? 'meeting');
            $doc_id = filter_var($_POST['doc_id'] ?? 0, FILTER_VALIDATE_INT);
            $signer_role = trim($_POST['signer_role'] ?? 'attendee');
            $signer_name = trim($_POST['signer_name'] ?? '');
            $sig_data = trim($_POST['signature_data'] ?? '');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            if ($doc_id <= 0 || $signer_name === '' || $sig_data === '') {
                throw new InvalidArgumentException('서명 대상 및 서명 데이터가 누락되었습니다.');
            }

            worker_part_save_signature($db, $doc_type, $doc_id, $signer_role, $signer_name, $sig_data, $ip);
            $message = $signer_name . ' 님의 전자서명이 안전하게 저장·DB 등록되었습니다.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Compute Metrics
$sTotal = (int)$db->query("SELECT COUNT(*) FROM csm_suggestions WHERE year = $year")->fetchColumn();
$sDone = (int)$db->query("SELECT COUNT(*) FROM csm_suggestions WHERE year = $year AND status = '완료'")->fetchColumn();
$sRate = $sTotal > 0 ? (int)round(($sDone / $sTotal) * 100) : 0;
$mTotal = (int)$db->query("SELECT COUNT(*) FROM csm_safety_meetings WHERE year = $year")->fetchColumn();
$cTotal = (int)$db->query("SELECT COUNT(*) FROM csm_safety_champions WHERE year = $year")->fetchColumn();

// Load Data based on active tab
$suggestions = [];
$meetings = [];
$champions = [];
$reports = [];

if ($tab === 'suggestions') {
    $stmt = $db->prepare('SELECT * FROM csm_suggestions WHERE year = ? ORDER BY reg_date DESC, id DESC');
    $stmt->execute([$year]);
    $suggestions = $stmt->fetchAll();
} elseif ($tab === 'meetings') {
    $stmt = $db->prepare('SELECT * FROM csm_safety_meetings WHERE year = ? ORDER BY meeting_date DESC, id DESC');
    $stmt->execute([$year]);
    $meetings = $stmt->fetchAll();
} elseif ($tab === 'champions') {
    $stmt = $db->prepare('SELECT * FROM csm_safety_champions WHERE year = ? ORDER BY month ASC');
    $stmt->execute([$year]);
    $champions = $stmt->fetchAll();
} elseif ($tab === 'feedback') {
    $stmt = $db->prepare('SELECT * FROM csm_feedback_reports WHERE year = ? ORDER BY half ASC');
    $stmt->execute([$year]);
    $reports = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>근로자 참여 및 안전제안·회의록 관리 · CSM 1.3</title>
  <link rel="stylesheet" href="worker_participation.css">
</head>
<body>

<div class="wp-container">
  <!-- Top Navigation & Title -->
  <div class="wp-header">
    <div class="wp-title-group">
      <h1>
        <a href="management_system.php?section=1.3&amp;year=<?= $year ?>" style="color:inherit;text-decoration:none;">← 1.3</a>
        근로자 참여 및 안전제안·회의록 관리
        <span class="wp-badge-eval">배점 6점</span>
      </h1>
      <p class="wp-subtitle">산업안전보건위원회 회의록(1.3.1), 근로자 안전제안(1.3.2) 및 반기별 의견수렴·환류(1.3.3)를 통합 관리합니다.</p>
    </div>
    <div class="wp-actions no-print">
      <form method="get" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="tab" value="<?= $escape($tab) ?>">
        <select name="year" class="wp-select" onchange="this.form.submit()">
          <?php for ($y = 2024; $y <= 2027; $y++): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년도</option>
          <?php endfor; ?>
        </select>
      </form>
      <a href="?tab=<?= $escape($tab) ?>&amp;year=<?= $year ?>&amp;export=csv" class="wp-btn wp-btn-outline">📊 Excel/CSV 내보내기</a>
      <?php if ($tab === 'meetings'): ?>
        <a href="worker_participation_meeting_print.php?year=<?= $year ?>" target="_blank" class="wp-btn wp-btn-outline">🖨️ 회의록 일괄인쇄</a>
      <?php elseif ($tab === 'suggestions'): ?>
        <a href="worker_participation_suggestion_print.php?year=<?= $year ?>" target="_blank" class="wp-btn wp-btn-outline">🖨️ 제안서 일괄인쇄</a>
      <?php else: ?>
        <button type="button" class="wp-btn wp-btn-outline" onclick="window.print()">🖨️ A4 인쇄</button>
      <?php endif; ?>
      <?php if ($tab === 'suggestions'): ?>
        <button type="button" class="wp-btn wp-btn-primary" onclick="openSuggestionModal()">+ 새 안전제안 접수</button>
      <?php elseif ($tab === 'meetings'): ?>
        <button type="button" class="wp-btn wp-btn-primary" onclick="openMeetingModal()">+ 새 회의록 작성</button>
      <?php elseif ($tab === 'champions'): ?>
        <button type="button" class="wp-btn wp-btn-primary" onclick="openChampionModal()">+ 이달의 안전인 선정</button>
      <?php elseif ($tab === 'feedback'): ?>
        <button type="button" class="wp-btn wp-btn-primary" onclick="openFeedbackModal()">+ 반기 환류 보고서 작성</button>
      <?php endif; ?>
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

  <!-- KPI Metrics -->
  <div class="wp-metrics-grid">
    <div class="wp-metric-card">
      <div class="wp-metric-title">총 안전제안 접수 (1.3.2)</div>
      <div class="wp-metric-value"><?= number_format($sTotal) ?>건</div>
      <div class="wp-metric-sub">위험요인 발굴 및 개선 제안</div>
    </div>
    <div class="wp-metric-card">
      <div class="wp-metric-title">제안 개선조치율</div>
      <div class="wp-metric-value" style="color:<?= $sRate >= 80 ? 'var(--wp-success)' : 'var(--wp-warning)' ?>;"><?= $sRate ?>%</div>
      <div class="wp-metric-sub"><?= $sDone ?>건 완료 / 총 <?= $sTotal ?>건</div>
    </div>
    <div class="wp-metric-card">
      <div class="wp-metric-title">산업안전보건위원회 회의 (1.3.1)</div>
      <div class="wp-metric-value"><?= number_format($mTotal) ?>회</div>
      <div class="wp-metric-sub">분기별 정기·수시 회의 개최</div>
    </div>
    <div class="wp-metric-card">
      <div class="wp-metric-title">이달의 안전인 포상</div>
      <div class="wp-metric-value"><?= number_format($cTotal) ?>명</div>
      <div class="wp-metric-sub">제안 및 안전활동 우수자 포상</div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <nav class="wp-tabs no-print">
    <a href="?tab=suggestions&amp;year=<?= $year ?>" class="wp-tab <?= $tab === 'suggestions' ? 'active' : '' ?>">
      📝 1.3.2 산업안전보건 제안 관리대장 (<?= count($suggestions) ?>)
    </a>
    <a href="?tab=meetings&amp;year=<?= $year ?>" class="wp-tab <?= $tab === 'meetings' ? 'active' : '' ?>">
      👥 1.3.1 산업안전보건위원회 회의록 (<?= count($meetings) ?>)
    </a>
    <a href="?tab=champions&amp;year=<?= $year ?>" class="wp-tab <?= $tab === 'champions' ? 'active' : '' ?>">
      🏆 이달의 안전인 선정·심사 (<?= count($champions) ?>)
    </a>
    <a href="?tab=feedback&amp;year=<?= $year ?>" class="wp-tab <?= $tab === 'feedback' ? 'active' : '' ?>">
      📢 1.3.3 반기별 의견수렴 및 환류 보고서
    </a>
  </nav>

  <!-- TAB 1: SUGGESTIONS -->
  <?php if ($tab === 'suggestions'): ?>
    <div class="wp-table-card">
      <table class="wp-table">
        <thead>
          <tr>
            <th style="width:100px;">접수번호</th>
            <th style="width:110px;">접수일자</th>
            <th style="width:130px;">제안자 (소속)</th>
            <th style="width:110px;">유형</th>
            <th>제안명 및 문제점 / 개선방안</th>
            <th style="width:90px;">진행상태</th>
            <th style="width:100px;">조치담당</th>
            <th style="width:100px;">조치일자</th>
            <th style="width:130px;">환류·포상</th>
            <th class="no-print" style="width:100px;text-align:center;">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($suggestions)): ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--wp-muted);">등록된 안전제안이 없습니다. 상단 '새 안전제안 접수' 버튼으로 등록해주세요.</td></tr>
          <?php else: foreach ($suggestions as $s): ?>
            <tr>
              <td><strong><?= $escape($s['reg_no']) ?></strong></td>
              <td><?= $escape($s['reg_date']) ?></td>
              <td>
                <strong><?= $escape($s['proposer_name']) ?></strong>
                <div style="font-size:12px;color:var(--wp-muted);"><?= $escape($s['proposer_dept']) ?></div>
              </td>
              <td><span class="wp-badge wp-badge-info"><?= $escape($s['category']) ?></span></td>
              <td>
                <div style="font-weight:700;font-size:15px;margin-bottom:4px;color:var(--wp-primary);"><?= $escape($s['title']) ?></div>
                <div style="font-size:13px;color:#475569;margin-bottom:3px;"><strong>위치:</strong> <?= $escape($s['location'] ?: '현장 전 구역') ?></div>
                <div style="font-size:13px;color:#334155;background:#f8fafc;padding:6px 10px;border-radius:4px;border-left:3px solid var(--wp-accent);margin-top:4px;">
                  <div><strong>현황:</strong> <?= $escape($s['problem']) ?></div>
                  <div style="margin-top:2px;"><strong>개선:</strong> <?= $escape($s['solution']) ?></div>
                </div>
              </td>
              <td>
                <?php if ($s['status'] === '완료'): ?>
                  <span class="wp-badge wp-badge-done">조치완료</span>
                <?php elseif ($s['status'] === '채택·진행'): ?>
                  <span class="wp-badge wp-badge-progress">진행중</span>
                <?php else: ?>
                  <span class="wp-badge wp-badge-pending"><?= $escape($s['status']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= $escape($s['action_manager'] ?: '—') ?></td>
              <td><?= $escape($s['action_date'] ?: ($s['action_deadline'] ? '기한: ' . $s['action_deadline'] : '—')) ?></td>
              <td>
                <?php if ($s['feedback_date']): ?>
                  <div style="font-size:12px;color:var(--wp-success);font-weight:600;">공지: <?= $escape($s['feedback_date']) ?></div>
                <?php endif; ?>
                <?php if ($s['reward_note']): ?>
                  <div style="font-size:11px;color:var(--wp-muted);"><?= $escape($s['reward_note']) ?></div>
                <?php endif; ?>
              </td>
              <td class="no-print" style="text-align:center;white-space:nowrap;">
                <a href="worker_participation_suggestion_print.php?id=<?= $s['id'] ?>" target="_blank" class="wp-btn wp-btn-outline wp-btn-sm">🖨️ 인쇄</a>
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick="openSignModal('suggestion', <?= $s['id'] ?>, '<?= $escape($s['proposer_name']) ?>', '<?= $escape($s['title']) ?>')">✍️ 서명</button>
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick='editSuggestion(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>수정</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('해당 안전제안을 삭제하시겠습니까?');">
                  <input type="hidden" name="action" value="delete_suggestion">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <button type="submit" class="wp-btn wp-btn-outline wp-btn-sm" style="color:var(--wp-danger);">삭제</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- TAB 2: MEETINGS -->
  <?php if ($tab === 'meetings'): ?>
    <div class="wp-table-card">
      <table class="wp-table">
        <thead>
          <tr>
            <th style="width:140px;">회의구분</th>
            <th style="width:110px;">개최일시</th>
            <th>회의명 및 주요 안건</th>
            <th style="width:140px;">장소 / 주관</th>
            <th style="width:180px;">참석자 (인원)</th>
            <th>심의·의결 및 결정사항</th>
            <th style="width:120px;">결과 공유</th>
            <th class="no-print" style="width:100px;text-align:center;">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($meetings)): ?>
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--wp-muted);">등록된 회의록이 없습니다. 상단 '새 회의록 작성' 버튼으로 등록해주세요.</td></tr>
          <?php else: foreach ($meetings as $m): ?>
            <tr>
              <td><span class="wp-badge wp-badge-info"><?= $escape($m['meeting_type']) ?></span></td>
              <td><strong><?= $escape($m['meeting_date']) ?></strong></td>
              <td>
                <div style="font-weight:700;font-size:15px;color:var(--wp-primary);margin-bottom:4px;"><?= $escape($m['meeting_name']) ?></div>
                <div style="font-size:13px;color:#334155;white-space:pre-line;"><?= $escape($m['agenda']) ?></div>
              </td>
              <td>
                <div><?= $escape($m['meeting_place']) ?></div>
                <small style="color:var(--wp-muted);">주관: <?= $escape($m['chairperson']) ?></small>
              </td>
              <td>
                <strong><?= $m['attendees_count'] ?>명 참석</strong>
                <div style="font-size:12px;color:#475569;margin-top:2px;"><?= $escape($m['attendees_text']) ?></div>
              </td>
              <td>
                <div style="font-size:13px;color:#0f172a;white-space:pre-line;background:#f8fafc;padding:6px 10px;border-radius:4px;border-left:3px solid var(--wp-success);"><?= $escape($m['decisions']) ?></div>
              </td>
              <td>
                <div style="font-size:12px;color:#334155;"><?= $escape($m['result_sharing'] ?: '게시판 공지') ?></div>
              </td>
              <td class="no-print" style="text-align:center;white-space:nowrap;">
                <a href="worker_participation_meeting_print.php?id=<?= $m['id'] ?>" target="_blank" class="wp-btn wp-btn-outline wp-btn-sm">🖨️ 인쇄</a>
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick="openSignModal('meeting', <?= $m['id'] ?>, '', '<?= $escape($m['meeting_name']) ?>')">✍️ 서명</button>
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick='editMeeting(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>수정</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('해당 회의록을 삭제하시겠습니까?');">
                  <input type="hidden" name="action" value="delete_meeting">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <button type="submit" class="wp-btn wp-btn-outline wp-btn-sm" style="color:var(--wp-danger);">삭제</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- TAB 3: CHAMPIONS -->
  <?php if ($tab === 'champions'): ?>
    <div class="wp-table-card">
      <table class="wp-table">
        <thead>
          <tr>
            <th style="width:90px;">해당월</th>
            <th style="width:130px;">선정자 (직위)</th>
            <th style="width:130px;">포상명</th>
            <th>선정 사유 및 공적 내용</th>
            <th style="width:80px;text-align:center;">아차사고</th>
            <th style="width:80px;text-align:center;">안전제안</th>
            <th style="width:80px;text-align:center;">근태관리</th>
            <th style="width:80px;text-align:center;">기타</th>
            <th style="width:90px;text-align:center;">총점</th>
            <th style="width:160px;">포상내용</th>
            <th class="no-print" style="width:80px;text-align:center;">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($champions)): ?>
            <tr><td colspan="11" style="text-align:center;padding:40px;color:var(--wp-muted);">선정된 이달의 안전인이 없습니다.</td></tr>
          <?php else: foreach ($champions as $c): ?>
            <tr>
              <td><strong><?= $c['month'] ?>월</strong></td>
              <td><strong><?= $escape($c['recipient_name']) ?></strong> <span style="font-size:12px;color:var(--wp-muted);">(<?= $escape($c['recipient_role']) ?>)</span></td>
              <td><span class="wp-badge wp-badge-done"><?= $escape($c['award_title']) ?></span></td>
              <td style="font-size:13px;line-height:1.5;"><?= $escape($c['reason']) ?></td>
              <td style="text-align:center;"><?= $c['score_near_miss'] ?>점</td>
              <td style="text-align:center;"><?= $c['score_suggestion'] ?>점</td>
              <td style="text-align:center;"><?= $c['score_attendance'] ?>점</td>
              <td style="text-align:center;"><?= $c['score_other'] ?>점</td>
              <td style="text-align:center;font-weight:800;color:var(--wp-primary);"><?= $c['total_score'] ?>점</td>
              <td><span style="font-weight:600;color:var(--wp-accent);"><?= $escape($c['reward_content']) ?></span></td>
              <td class="no-print" style="text-align:center;">
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick='editChampion(<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>수정</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- TAB 4: FEEDBACK -->
  <?php if ($tab === 'feedback'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(480px, 1fr));gap:20px;">
      <?php for ($h = 1; $h <= 2; $h++):
        $r = current(array_filter($reports, static fn($x) => (int)$x['half'] === $h)) ?: null;
      ?>
      <div class="wp-table-card" style="padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid var(--wp-border);padding-bottom:12px;margin-bottom:16px;">
          <div>
            <span class="wp-badge wp-badge-info"><?= $year ?>년 <?= $h === 1 ? '상반기' : '하반기' ?></span>
            <h2 style="margin:6px 0 0;font-size:18px;color:var(--wp-primary);"><?= $r ? $escape($r['report_title']) : ($year . '년 ' . ($h === 1 ? '상반기' : '하반기') . ' 근로자 의견청취 및 환류 보고서') ?></h2>
          </div>
          <?php if ($r): ?>
            <span class="wp-badge wp-badge-done"><?= $escape($r['status']) ?></span>
          <?php else: ?>
            <span class="wp-badge wp-badge-pending">미작성</span>
          <?php endif; ?>
        </div>

        <?php if ($r): ?>
          <div style="font-size:13px;display:flex;flex-direction:column;gap:12px;">
            <div><strong>보고일자:</strong> <?= $escape($r['report_date']) ?> | <strong>관리책임자:</strong> <?= $escape($r['manager_name']) ?></div>
            <div>
              <div style="font-weight:700;color:#1e3a8a;margin-bottom:4px;">1. 수렴된 근로자 의견 및 안전제안 현황</div>
              <div style="background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e2e8f0;white-space:pre-line;"><?= $escape($r['summary_opinions']) ?></div>
            </div>
            <div>
              <div style="font-weight:700;color:#1e3a8a;margin-bottom:4px;">2. 개선 조치 및 이행 완료 현황</div>
              <div style="background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e2e8f0;white-space:pre-line;"><?= $escape($r['action_status_summary']) ?></div>
            </div>
            <div>
              <div style="font-weight:700;color:#1e3a8a;margin-bottom:4px;">3. 근로자 결과 공지 및 환류 방식</div>
              <div style="background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e2e8f0;white-space:pre-line;"><?= $escape($r['feedback_notice_summary']) ?></div>
            </div>
            <div>
              <div style="font-weight:700;color:#1e3a8a;margin-bottom:4px;">4. 향후 개선 추진 방향</div>
              <div style="background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e2e8f0;white-space:pre-line;"><?= $escape($r['future_improvements']) ?></div>
            </div>
            <div class="no-print" style="margin-top:10px;display:flex;justify-content:flex-end;">
              <button type="button" class="wp-btn wp-btn-outline" onclick='editFeedback(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>보고서 수정</button>
            </div>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:40px 20px;color:var(--wp-muted);">
            <p>아직 작성된 보고서가 없습니다.</p>
            <button type="button" class="wp-btn wp-btn-primary" onclick="openFeedbackModal(<?= $h ?>)">+ <?= $h === 1 ? '상반기' : '하반기' ?> 보고서 작성</button>
          </div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</div>

<!-- MODAL: SUGGESTION FORM -->
<div id="modalSuggestion" class="wp-modal-backdrop" style="display:none;">
  <div class="wp-modal">
    <form method="post">
      <input type="hidden" name="action" value="save_suggestion">
      <input type="hidden" name="id" id="sug_id" value="0">
      <div class="wp-modal-header">
        <h3 id="sug_modal_title">산업안전보건 안전제안 접수 및 조치 등록</h3>
        <button type="button" class="wp-modal-close" onclick="closeSuggestionModal()">&times;</button>
      </div>
      <div class="wp-modal-body">
        <div class="wp-form-grid">
          <div class="wp-form-group">
            <label>접수번호</label>
            <input type="text" name="reg_no" id="sug_reg_no" placeholder="비워둘 시 자동생성">
          </div>
          <div class="wp-form-group">
            <label>접수일자 *</label>
            <input type="date" name="reg_date" id="sug_reg_date" value="<?= $today ?>" required>
          </div>
          <div class="wp-form-group">
            <label>제안자 성명 *</label>
            <input type="text" name="proposer_name" id="sug_proposer_name" required>
          </div>
          <div class="wp-form-group">
            <label>소속 / 부서</label>
            <input type="text" name="proposer_dept" id="sug_proposer_dept" placeholder="예: 전기반, 기계정비반">
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>제안명 (안전 건의 제목) *</label>
            <input type="text" name="title" id="sug_title" placeholder="예: 고소작업 안전발판 교체 제안" required>
          </div>
          <div class="wp-form-group">
            <label>제안유형 *</label>
            <select name="category" id="sug_category" class="wp-select">
              <option value="작업장 안전">작업장 안전</option>
              <option value="건설 안전">건설 안전</option>
              <option value="시설물 안전">시설물 안전</option>
              <option value="작업환경">작업환경</option>
              <option value="건강관리(보건)">건강관리(보건)</option>
              <option value="기타">기타</option>
            </select>
          </div>
          <div class="wp-form-group">
            <label>대상 위치 / 구역</label>
            <input type="text" name="location" id="sug_location" placeholder="예: 옥외 자재보관소">
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>현황 및 문제점 (위험요인) *</label>
            <textarea name="problem" id="sug_problem" required placeholder="발견된 유해·위험요인 및 사고 우려 사항"></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>개선방안 (제안 내용) *</label>
            <textarea name="solution" id="sug_solution" required placeholder="구체적인 설비 개선, 방호장치 장착, 작업방식 변경 등"></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>기대효과</label>
            <input type="text" name="expected_effect" id="sug_expected_effect" placeholder="예: 추락사고 원천 차단, 편의성 향상">
          </div>

          <!-- Review & Action Section -->
          <div class="wp-form-col-full" style="border-top:1px dashed #cbd5e1;padding-top:14px;margin-top:6px;">
            <strong style="color:var(--wp-primary);">심사 및 개선조치 계획 (관리자)</strong>
          </div>
          <div class="wp-form-group">
            <label>진행상태</label>
            <select name="status" id="sug_status" class="wp-select">
              <option value="접수">접수</option>
              <option value="검토중">검토중</option>
              <option value="채택·진행">채택·진행</option>
              <option value="완료">완료</option>
              <option value="불채택">불채택</option>
            </select>
          </div>
          <div class="wp-form-group">
            <label>조치 담당자</label>
            <input type="text" name="action_manager" id="sug_action_manager" placeholder="예: 정주랑 대리">
          </div>
          <div class="wp-form-group">
            <label>조치 완료일 (또는 기한)</label>
            <input type="date" name="action_date" id="sug_action_date">
          </div>
          <div class="wp-form-group">
            <label>환류(공지) 일자</label>
            <input type="date" name="feedback_date" id="sug_feedback_date">
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>조치 결과 요약</label>
            <textarea name="action_result" id="sug_action_result" placeholder="개선 조치 완료 내역 및 구매·설치 현황"></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>포상 내역 및 가점</label>
            <input type="text" name="reward_note" id="sug_reward_note" placeholder="예: 상품권 5만원 수여 및 이달의 안전인 30점 부여">
          </div>
        </div>
      </div>
      <div class="wp-modal-footer">
        <button type="button" class="wp-btn wp-btn-outline" onclick="closeSuggestionModal()">취소</button>
        <button type="submit" class="wp-btn wp-btn-primary">저장하기</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: MEETING FORM -->
<div id="modalMeeting" class="wp-modal-backdrop" style="display:none;">
  <div class="wp-modal">
    <form method="post">
      <input type="hidden" name="action" value="save_meeting">
      <input type="hidden" name="id" id="meet_id" value="0">
      <div class="wp-modal-header">
        <h3 id="meet_modal_title">산업안전보건위원회 회의록 등록</h3>
        <button type="button" class="wp-modal-close" onclick="closeMeetingModal()">&times;</button>
      </div>
      <div class="wp-modal-body">
        <div class="wp-form-grid">
          <div class="wp-form-group">
            <label>회의구분 *</label>
            <select name="meeting_type" id="meet_meeting_type" class="wp-select">
              <option value="산업안전보건위원회">산업안전보건위원회</option>
              <option value="협력업체 안전보건협의회">협력업체 안전보건협의회</option>
              <option value="노사협의체 회의">노사협의체 회의</option>
              <option value="안전보건회의">안전보건회의</option>
            </select>
          </div>
          <div class="wp-form-group">
            <label>개최일시 *</label>
            <input type="date" name="meeting_date" id="meet_meeting_date" value="<?= $today ?>" required>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>회의명 *</label>
            <input type="text" name="meeting_name" id="meet_meeting_name" placeholder="예: 2026년 1분기 정기 산업안전보건위원회 회의" required>
          </div>
          <div class="wp-form-group">
            <label>회의장소</label>
            <input type="text" name="meeting_place" id="meet_meeting_place" placeholder="예: 본관 회의실">
          </div>
          <div class="wp-form-group">
            <label>주관자 (의장)</label>
            <input type="text" name="chairperson" id="meet_chairperson" placeholder="예: 안전보건관리책임자 정연탁">
          </div>
          <div class="wp-form-group">
            <label>참석자 명단</label>
            <input type="text" name="attendees_text" id="meet_attendees_text" placeholder="예: 진종철, 정주랑, 윤택천, 김도담 등">
          </div>
          <div class="wp-form-group">
            <label>참석 인원수 (명)</label>
            <input type="number" name="attendees_count" id="meet_attendees_count" value="5">
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>주요 안건 *</label>
            <textarea name="agenda" id="meet_agenda" required placeholder="회의 안건 목록"></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>심의·의결 및 결정사항 *</label>
            <textarea name="decisions" id="meet_decisions" required placeholder="의결된 사항 및 안전개선 이행 결정 내용"></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>근로자 결과 공유 및 환류 방법</label>
            <input type="text" name="result_sharing" id="meet_result_sharing" placeholder="예: 사내 게시판 공지, 1/26 TBM 교육 전파">
          </div>
        </div>
      </div>
      <div class="wp-modal-footer">
        <button type="button" class="wp-btn wp-btn-outline" onclick="closeMeetingModal()">취소</button>
        <button type="submit" class="wp-btn wp-btn-primary">저장하기</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: CHAMPION FORM -->
<div id="modalChampion" class="wp-modal-backdrop" style="display:none;">
  <div class="wp-modal">
    <form method="post">
      <input type="hidden" name="action" value="save_champion">
      <div class="wp-modal-header">
        <h3>이달의 안전인 선정 및 심사</h3>
        <button type="button" class="wp-modal-close" onclick="closeChampionModal()">&times;</button>
      </div>
      <div class="wp-modal-body">
        <div class="wp-form-grid">
          <div class="wp-form-group">
            <label>해당월 *</label>
            <select name="month" id="champ_month" class="wp-select">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>"><?= $m ?>월</option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="wp-form-group">
            <label>선정자 성명 *</label>
            <input type="text" name="recipient_name" id="champ_name" required>
          </div>
          <div class="wp-form-group">
            <label>직위 / 직무</label>
            <input type="text" name="recipient_role" id="champ_role" placeholder="예: 전기반 반장">
          </div>
          <div class="wp-form-group">
            <label>포상 내용</label>
            <input type="text" name="reward_content" id="champ_reward" placeholder="예: 상품권 10만원 및 상장 수여">
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>선정 사유 및 공적 내용 *</label>
            <textarea name="reason" id="champ_reason" required placeholder="안전제안, 위험요인 발굴, 모범적 안전활동 사례"></textarea>
          </div>
          <div class="wp-form-group">
            <label>아차사고 배점 (30점 만점)</label>
            <input type="number" name="score_near_miss" id="champ_score_near" value="30" max="30">
          </div>
          <div class="wp-form-group">
            <label>안전제안 배점 (30점 만점)</label>
            <input type="number" name="score_suggestion" id="champ_score_sug" value="30" max="30">
          </div>
          <div class="wp-form-group">
            <label>근태관리 배점 (30점 만점)</label>
            <input type="number" name="score_attendance" id="champ_score_att" value="30" max="30">
          </div>
          <div class="wp-form-group">
            <label>기타 가점 (10점 만점)</label>
            <input type="number" name="score_other" id="champ_score_oth" value="10" max="10">
          </div>
        </div>
      </div>
      <div class="wp-modal-footer">
        <button type="button" class="wp-btn wp-btn-outline" onclick="closeChampionModal()">취소</button>
        <button type="submit" class="wp-btn wp-btn-primary">선정 및 저장</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: FEEDBACK REPORT FORM -->
<div id="modalFeedback" class="wp-modal-backdrop" style="display:none;">
  <div class="wp-modal">
    <form method="post">
      <input type="hidden" name="action" value="save_feedback">
      <div class="wp-modal-header">
        <h3>반기별 근로자 의견수렴 및 환류 보고서 (1.3.3)</h3>
        <button type="button" class="wp-modal-close" onclick="closeFeedbackModal()">&times;</button>
      </div>
      <div class="wp-modal-body">
        <div class="wp-form-grid">
          <div class="wp-form-group">
            <label>구분 *</label>
            <select name="half" id="fb_half" class="wp-select">
              <option value="1">상반기</option>
              <option value="2">하반기</option>
            </select>
          </div>
          <div class="wp-form-group">
            <label>보고일자 *</label>
            <input type="date" name="report_date" id="fb_date" value="<?= $today ?>" required>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>보고서 제목 *</label>
            <input type="text" name="report_title" id="fb_title" required>
          </div>
          <div class="wp-form-group">
            <label>관리책임자</label>
            <input type="text" name="manager_name" id="fb_manager" value="안전보건관리책임자 정연탁">
          </div>
          <div class="wp-form-group">
            <label>결재 상태</label>
            <select name="status" id="fb_status" class="wp-select">
              <option value="작성">작성</option>
              <option value="검토">검토</option>
              <option value="승인·공지">승인·공지</option>
            </select>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>1. 수렴된 의견 및 안전제안 요약 *</label>
            <textarea name="summary_opinions" id="fb_summary" required></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>2. 조치 이행 및 개선 현황 *</label>
            <textarea name="action_status_summary" id="fb_action" required></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>3. 근로자 결과 공지 및 환류 방식 *</label>
            <textarea name="feedback_notice_summary" id="fb_notice" required></textarea>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <label>4. 향후 개선 추진 과제</label>
            <textarea name="future_improvements" id="fb_future"></textarea>
          </div>
        </div>
      </div>
      <div class="wp-modal-footer">
        <button type="button" class="wp-btn wp-btn-outline" onclick="closeFeedbackModal()">취소</button>
        <button type="submit" class="wp-btn wp-btn-primary">보고서 저장</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: SIGNATURE PAD -->
<div id="modalSign" class="wp-modal-backdrop" style="display:none;">
  <div class="wp-modal" style="max-width:560px;">
    <form method="post" id="formSignature">
      <input type="hidden" name="action" value="save_signature">
      <input type="hidden" name="doc_type" id="sign_doc_type" value="meeting">
      <input type="hidden" name="doc_id" id="sign_doc_id" value="0">
      <input type="hidden" name="signature_data" id="sign_data" value="">
      <div class="wp-modal-header">
        <h3 id="sign_modal_title">✍️ 전자서명 등록</h3>
        <button type="button" class="wp-modal-close" onclick="closeSignModal()">&times;</button>
      </div>
      <div class="wp-modal-body">
        <div class="wp-form-grid" style="grid-template-columns:1fr 1fr;">
          <div class="wp-form-group">
            <label>서명 구분 / 역할 *</label>
            <select name="signer_role" id="sign_role" class="wp-input" required>
              <!-- Dynamic -->
            </select>
          </div>
          <div class="wp-form-group">
            <label>서명자 성명 *</label>
            <input type="text" name="signer_name" id="sign_name" class="wp-input" placeholder="성명 입력" required>
          </div>
          <div class="wp-form-col-full wp-form-group">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
              <label style="margin:0;font-weight:600;">서명 / 인감 날인 (마우스/터치 가능) *</label>
              <div style="display:flex;gap:6px;">
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick="generateAutoStamp()">인감도장 자동생성</button>
                <button type="button" class="wp-btn wp-btn-outline wp-btn-sm" onclick="clearSignatureCanvas()">지우기</button>
              </div>
            </div>
            <div style="border:2px dashed #94a3b8;background:#ffffff;border-radius:8px;padding:4px;display:flex;justify-content:center;align-items:center;position:relative;">
              <canvas id="sigCanvas" width="480" height="170" style="touch-action:none;cursor:crosshair;background:#ffffff;max-width:100%;display:block;"></canvas>
            </div>
            <small style="color:var(--wp-muted);margin-top:6px;display:block;">* 마우스 드래그 또는 터치로 직접 서명하거나, 성명 입력 후 <strong>[인감도장 자동생성]</strong>을 클릭하여 공인 직인을 날인할 수 있습니다.</small>
          </div>
        </div>
      </div>
      <div class="wp-modal-footer">
        <button type="button" class="wp-btn wp-btn-outline" onclick="closeSignModal()">취소</button>
        <button type="button" class="wp-btn wp-btn-primary" onclick="submitSignature()">서명 확정 및 DB 저장</button>
      </div>
    </form>
  </div>
</div>

<script>
function openSuggestionModal() {
  document.getElementById('sug_id').value = '0';
  document.getElementById('sug_modal_title').innerText = '산업안전보건 안전제안 접수';
  document.getElementById('sug_reg_no').value = '';
  document.getElementById('sug_reg_date').value = '<?= $today ?>';
  document.getElementById('sug_proposer_name').value = '';
  document.getElementById('sug_proposer_dept').value = '';
  document.getElementById('sug_title').value = '';
  document.getElementById('sug_category').value = '작업장 안전';
  document.getElementById('sug_location').value = '';
  document.getElementById('sug_problem').value = '';
  document.getElementById('sug_solution').value = '';
  document.getElementById('sug_expected_effect').value = '';
  document.getElementById('sug_status').value = '접수';
  document.getElementById('sug_action_manager').value = '';
  document.getElementById('sug_action_date').value = '';
  document.getElementById('sug_feedback_date').value = '';
  document.getElementById('sug_action_result').value = '';
  document.getElementById('sug_reward_note').value = '';
  document.getElementById('modalSuggestion').style.display = 'flex';
}
function closeSuggestionModal() {
  document.getElementById('modalSuggestion').style.display = 'none';
}
function editSuggestion(s) {
  document.getElementById('sug_id').value = s.id;
  document.getElementById('sug_modal_title').innerText = '안전제안 수정 및 조치 이행 기록';
  document.getElementById('sug_reg_no').value = s.reg_no || '';
  document.getElementById('sug_reg_date').value = s.reg_date || '';
  document.getElementById('sug_proposer_name').value = s.proposer_name || '';
  document.getElementById('sug_proposer_dept').value = s.proposer_dept || '';
  document.getElementById('sug_title').value = s.title || '';
  document.getElementById('sug_category').value = s.category || '작업장 안전';
  document.getElementById('sug_location').value = s.location || '';
  document.getElementById('sug_problem').value = s.problem || '';
  document.getElementById('sug_solution').value = s.solution || '';
  document.getElementById('sug_expected_effect').value = s.expected_effect || '';
  document.getElementById('sug_status').value = s.status || '접수';
  document.getElementById('sug_action_manager').value = s.action_manager || '';
  document.getElementById('sug_action_date').value = s.action_date || '';
  document.getElementById('sug_feedback_date').value = s.feedback_date || '';
  document.getElementById('sug_action_result').value = s.action_result || '';
  document.getElementById('sug_reward_note').value = s.reward_note || '';
  document.getElementById('modalSuggestion').style.display = 'flex';
}

function openMeetingModal() {
  document.getElementById('meet_id').value = '0';
  document.getElementById('meet_modal_title').innerText = '새 산업안전보건위원회 회의록 등록';
  document.getElementById('meet_meeting_type').value = '산업안전보건위원회';
  document.getElementById('meet_meeting_date').value = '<?= $today ?>';
  document.getElementById('meet_meeting_name').value = '';
  document.getElementById('meet_meeting_place').value = '본관 회의실';
  document.getElementById('meet_chairperson').value = '안전보건관리책임자 정연탁';
  document.getElementById('meet_attendees_text').value = '';
  document.getElementById('meet_attendees_count').value = '5';
  document.getElementById('meet_agenda').value = '';
  document.getElementById('meet_decisions').value = '';
  document.getElementById('meet_result_sharing').value = '사내 게시판 공지 및 TBM 교육 전파';
  document.getElementById('modalMeeting').style.display = 'flex';
}
function closeMeetingModal() {
  document.getElementById('modalMeeting').style.display = 'none';
}
function editMeeting(m) {
  document.getElementById('meet_id').value = m.id;
  document.getElementById('meet_modal_title').innerText = '회의록 수정';
  document.getElementById('meet_meeting_type').value = m.meeting_type || '';
  document.getElementById('meet_meeting_date').value = m.meeting_date || '';
  document.getElementById('meet_meeting_name').value = m.meeting_name || '';
  document.getElementById('meet_meeting_place').value = m.meeting_place || '';
  document.getElementById('meet_chairperson').value = m.chairperson || '';
  document.getElementById('meet_attendees_text').value = m.attendees_text || '';
  document.getElementById('meet_attendees_count').value = m.attendees_count || 0;
  document.getElementById('meet_agenda').value = m.agenda || '';
  document.getElementById('meet_decisions').value = m.decisions || '';
  document.getElementById('meet_result_sharing').value = m.result_sharing || '';
  document.getElementById('modalMeeting').style.display = 'flex';
}

function openChampionModal() {
  document.getElementById('modalChampion').style.display = 'flex';
}
function closeChampionModal() {
  document.getElementById('modalChampion').style.display = 'none';
}
function editChampion(c) {
  document.getElementById('champ_month').value = c.month;
  document.getElementById('champ_name').value = c.recipient_name;
  document.getElementById('champ_role').value = c.recipient_role;
  document.getElementById('champ_reward').value = c.reward_content;
  document.getElementById('champ_reason').value = c.reason;
  document.getElementById('champ_score_near').value = c.score_near_miss;
  document.getElementById('champ_score_sug').value = c.score_suggestion;
  document.getElementById('champ_score_att').value = c.score_attendance;
  document.getElementById('champ_score_oth').value = c.score_other;
  document.getElementById('modalChampion').style.display = 'flex';
}

function openFeedbackModal(half = 1) {
  document.getElementById('fb_half').value = half;
  document.getElementById('fb_date').value = '<?= $today ?>';
  document.getElementById('fb_title').value = '<?= $year ?>년 ' + (half === 1 ? '상반기' : '하반기') + ' 근로자 의견수렴 및 환류 보고서';
  document.getElementById('fb_manager').value = '안전보건관리책임자 정연탁';
  document.getElementById('fb_status').value = '승인·공지';
  document.getElementById('fb_summary').value = '';
  document.getElementById('fb_action').value = '';
  document.getElementById('fb_notice').value = '사내 게시판 및 월별 TBM 미팅을 통한 전파 공유';
  document.getElementById('fb_future').value = '';
  document.getElementById('modalFeedback').style.display = 'flex';
}
function closeFeedbackModal() {
  document.getElementById('modalFeedback').style.display = 'none';
}
function editFeedback(r) {
  document.getElementById('fb_half').value = r.half;
  document.getElementById('fb_date').value = r.report_date;
  document.getElementById('fb_title').value = r.report_title;
  document.getElementById('fb_manager').value = r.manager_name;
  document.getElementById('fb_status').value = r.status;
  document.getElementById('fb_summary').value = r.summary_opinions;
  document.getElementById('fb_action').value = r.action_status_summary;
  document.getElementById('fb_notice').value = r.feedback_notice_summary;
  document.getElementById('fb_future').value = r.future_improvements;
  document.getElementById('modalFeedback').style.display = 'flex';
}

// DIGITAL SIGNATURE CANVAS LOGIC
let sigCanvas = null;
let sigCtx = null;
let isDrawing = false;
let hasDrawn = false;

function initSignatureCanvas() {
  sigCanvas = document.getElementById('sigCanvas');
  if (!sigCanvas) return;
  sigCtx = sigCanvas.getContext('2d');
  sigCtx.lineWidth = 2.8;
  sigCtx.lineCap = 'round';
  sigCtx.lineJoin = 'round';
  sigCtx.strokeStyle = '#0f172a';

  function getPos(e) {
    const rect = sigCanvas.getBoundingClientRect();
    const scaleX = sigCanvas.width / rect.width;
    const scaleY = sigCanvas.height / rect.height;
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

  function startDraw(e) {
    if (e.target !== sigCanvas) return;
    e.preventDefault();
    isDrawing = true;
    hasDrawn = true;
    const p = getPos(e);
    sigCtx.beginPath();
    sigCtx.moveTo(p.x, p.y);
  }

  function drawMove(e) {
    if (!isDrawing) return;
    e.preventDefault();
    const p = getPos(e);
    sigCtx.lineTo(p.x, p.y);
    sigCtx.stroke();
  }

  function endDraw(e) {
    if (!isDrawing) return;
    isDrawing = false;
  }

  sigCanvas.addEventListener('mousedown', startDraw);
  sigCanvas.addEventListener('mousemove', drawMove);
  window.addEventListener('mouseup', endDraw);

  sigCanvas.addEventListener('touchstart', startDraw, { passive: false });
  sigCanvas.addEventListener('touchmove', drawMove, { passive: false });
  window.addEventListener('touchend', endDraw, { passive: false });
}

function clearSignatureCanvas() {
  if (!sigCanvas || !sigCtx) {
    initSignatureCanvas();
  }
  if (sigCanvas && sigCtx) {
    sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
    hasDrawn = false;
  }
}

function generateAutoStamp() {
  const nameInput = document.getElementById('sign_name');
  const name = nameInput ? nameInput.value.trim() : '';
  if (!name) {
    alert('도장에 각인할 서명자 성명을 먼저 입력해주세요.');
    if (nameInput) nameInput.focus();
    return;
  }
  if (!sigCanvas || !sigCtx) {
    initSignatureCanvas();
  }
  clearSignatureCanvas();

  const cx = sigCanvas.width / 2;
  const cy = sigCanvas.height / 2;
  const r = 44;

  sigCtx.save();
  sigCtx.strokeStyle = '#dc2626';
  sigCtx.lineWidth = 3.5;
  sigCtx.beginPath();
  sigCtx.arc(cx, cy, r, 0, Math.PI * 2);
  sigCtx.stroke();

  sigCtx.lineWidth = 1.2;
  sigCtx.beginPath();
  sigCtx.arc(cx, cy, r - 5, 0, Math.PI * 2);
  sigCtx.stroke();

  sigCtx.fillStyle = '#dc2626';
  sigCtx.font = 'bold 22px Batang, Gungsuh, "Malgun Gothic", serif';
  sigCtx.textAlign = 'center';
  sigCtx.textBaseline = 'middle';
  sigCtx.fillText(name, cx, cy + 1);
  sigCtx.restore();

  hasDrawn = true;
}

function openSignModal(docType, docId, defaultName, titleInfo) {
  document.getElementById('sign_doc_type').value = docType;
  document.getElementById('sign_doc_id').value = docId;
  const roleSelect = document.getElementById('sign_role');
  roleSelect.innerHTML = '';

  if (docType === 'meeting') {
    document.getElementById('sign_modal_title').innerText = '✍️ 산업안전보건위원회 회의록 전자서명 날인 (' + (titleInfo || '회의록') + ')';
    const optAttendee = document.createElement('option');
    optAttendee.value = 'attendee'; optAttendee.text = '참석자 (근로자/위원 서명)'; optAttendee.selected = true;
    const optDrafter = document.createElement('option');
    optDrafter.value = 'drafter'; optDrafter.text = '기안자 (작성/담당)';
    const optApprover = document.createElement('option');
    optApprover.value = 'approver'; optApprover.text = '승인자 (안전보건관리책임자)';
    roleSelect.appendChild(optAttendee);
    roleSelect.appendChild(optDrafter);
    roleSelect.appendChild(optApprover);
  } else {
    document.getElementById('sign_modal_title').innerText = '✍️ 안전제안 전자서명 날인 (' + (titleInfo || '제안서') + ')';
    const optProposer = document.createElement('option');
    optProposer.value = 'proposer'; optProposer.text = '제안자 (근로자)'; optProposer.selected = true;
    const optEvaluator = document.createElement('option');
    optEvaluator.value = 'evaluator'; optEvaluator.text = '평가자 (안전보건관리책임자)';
    roleSelect.appendChild(optProposer);
    roleSelect.appendChild(optEvaluator);
  }

  document.getElementById('sign_name').value = defaultName || '';
  document.getElementById('modalSign').style.display = 'flex';
  setTimeout(function() {
    initSignatureCanvas();
    clearSignatureCanvas();
  }, 50);
}

function closeSignModal() {
  document.getElementById('modalSign').style.display = 'none';
}

function submitSignature() {
  const name = document.getElementById('sign_name').value.trim();
  if (!name) {
    alert('서명자 성명을 입력해주세요.');
    document.getElementById('sign_name').focus();
    return;
  }
  if (!hasDrawn) {
    alert('서명란에 직접 서명하시거나 [인감도장 자동생성] 버튼을 눌러 직인을 생성해주세요.');
    return;
  }
  const data = sigCanvas.toDataURL('image/png');
  document.getElementById('sign_data').value = data;
  document.getElementById('formSignature').submit();
}
</script>

</body>
</html>
