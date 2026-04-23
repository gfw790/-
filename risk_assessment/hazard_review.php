<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensureWorkerHazardSelectionTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_worker_hazard_selection (
            worker_selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (worker_selection_id),
            UNIQUE KEY uk_work_report_worker_hazard_selection (report_id, user_login_id, item_id),
            KEY idx_work_report_worker_hazard_selection_report (report_id),
            KEY idx_work_report_worker_hazard_selection_user (user_login_id),
            KEY idx_work_report_worker_hazard_selection_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function hazard_review_report_team_context(array $report): string
{
    $reportTeam = auth_normalize_team_name((string)($report['team_name'] ?? ''));
    if ($reportTeam !== '') {
        return $reportTeam;
    }

    $ownerAccount = auth_find_user((string)($report['user_login_id'] ?? ''));
    return auth_normalize_team_name((string)($ownerAccount['team'] ?? ''));
}

function hazard_review_user_can_view_report(array $user, array $report): bool
{
    if (auth_is_worker($user) && (int)($report['leader_detail_count'] ?? 0) <= 0) {
        return false;
    }

  $userRole = (string)($user['role'] ?? '');
  if (auth_is_admin($user) || in_array($userRole, ['safety_manager', 'administrator'], true)) {
        return true;
    }

    $visibleTeams = auth_work_list_visible_teams($user);
    if (!empty($visibleTeams)) {
        $reportTeam = hazard_review_report_team_context($report);
        if ($reportTeam === '') {
            return false;
        }

        $visibleTeamKeys = array_fill_keys(array_map('auth_team_key', $visibleTeams), true);
        return isset($visibleTeamKeys[auth_team_key($reportTeam)]);
    }

    $userLoginId = trim((string)($user['login_id'] ?? ''));
    return $userLoginId !== '' && (string)($report['user_login_id'] ?? '') === $userLoginId;
}

  function hazard_review_resolve_role_people(array $report): array
  {
    $teamName = hazard_review_report_team_context($report);
    $reportRole = auth_normalize_role((string)($report['role_code'] ?? ''));
    $ownerName = trim((string)($report['user_name'] ?? ''));
    $ownerLoginId = trim((string)($report['user_login_id'] ?? ''));
    $ownerDisplayName = $ownerName !== '' ? $ownerName : ($ownerLoginId !== '' ? $ownerLoginId : '');

    $leaderName = '';
    $managerName = '';

    if ($reportRole === 'leader') {
      $leaderName = $ownerDisplayName;
    }
    if (in_array($reportRole, ['manager', 'safety_manager', 'admin', 'ceo'], true)) {
      $managerName = $ownerDisplayName;
    }

    $findMemberName = static function (string $baseTeamName, array $roles): string {
      $currentTeam = auth_normalize_team_name($baseTeamName);
      $visited = [];

      while ($currentTeam !== '') {
        $teamKey = auth_team_key($currentTeam);
        if ($teamKey === '' || isset($visited[$teamKey])) {
          break;
        }
        $visited[$teamKey] = true;

        $members = auth_team_members($currentTeam, $roles);
        if (!empty($members)) {
          $name = trim((string)($members[0]['name'] ?? ''));
          if ($name !== '') {
            return $name;
          }
        }

        $currentTeam = auth_get_team_supervisor($currentTeam);
      }

      return '';
    };

    if ($teamName !== '' && $leaderName === '') {
      $leaderMembers = auth_team_members($teamName, ['leader']);
      if (!empty($leaderMembers)) {
        $leaderName = trim((string)($leaderMembers[0]['name'] ?? ''));
      }
    }

    if ($teamName !== '' && $managerName === '') {
      $managerName = $findMemberName($teamName, ['manager', 'safety_manager', 'admin', 'ceo']);
    }

    return [
      'leader_name' => $leaderName,
      'manager_name' => $managerName,
    ];
  }

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$pdo = getDB();
ensureWorkerHazardSelectionTable($pdo);

$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$submitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$error = '';
$report = null;

if ($reportId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            wr.report_id,
            wr.unit_ra_id,
            wr.role_code,
            wr.user_login_id,
            wr.user_name,
            wr.team_name,
            wr.work_title,
            wr.work_date,
            wr.work_place,
            (
                SELECT COUNT(*)
                FROM work_report_detail wd
                WHERE wd.report_id = wr.report_id
            ) AS leader_detail_count,
            h.unit_code,
            h.unit_title
        FROM work_report wr
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = wr.unit_ra_id
        WHERE wr.report_id = :report_id
        LIMIT 1
    ");
    $stmt->execute([':report_id' => $reportId]);
    $report = $stmt->fetch();

    if ($report && !hazard_review_user_can_view_report($user, $report)) {
        $report = null;
    }

    if (!$report) {
        http_response_code(404);
        $error = '작업 정보를 찾을 수 없습니다.';
    }
}

$reportListStmt = $pdo->query("
    SELECT
        wr.report_id,
    wr.role_code,
        wr.user_login_id,
    wr.user_name,
        wr.team_name,
        wr.work_date,
        wr.work_title,
        (
            SELECT COUNT(*)
            FROM work_report_detail wd
            WHERE wd.report_id = wr.report_id
        ) AS leader_detail_count,
        COUNT(DISTINCT ws.user_login_id) AS participant_count
    FROM work_report wr
    LEFT JOIN work_report_worker_hazard_selection ws
        ON ws.report_id = wr.report_id
    GROUP BY wr.report_id, wr.work_date, wr.work_title
    ORDER BY wr.work_date DESC, wr.report_id DESC
");
$reportList = $reportListStmt->fetchAll() ?: [];
$reportList = array_values(array_filter(
    $reportList,
    static fn(array $listReport) => hazard_review_user_can_view_report($user, $listReport)
));

$todayKey = date('Y-m-d');
$teamCounts = [];
$totalParticipants = 0;
$reportsWithParticipants = 0;
$todayReports = 0;

foreach ($reportList as $listReport) {
  $participantCount = (int)($listReport['participant_count'] ?? 0);
  if ($participantCount > 0) {
    $reportsWithParticipants++;
  }
  $totalParticipants += max(0, $participantCount);

  $workDate = substr((string)($listReport['work_date'] ?? ''), 0, 10);
  if ($workDate === $todayKey) {
    $todayReports++;
  }

  $teamName = hazard_review_report_team_context($listReport);
  if ($teamName === '') {
    $teamName = '미지정';
  }
  if (!isset($teamCounts[$teamName])) {
    $teamCounts[$teamName] = 0;
  }
  $teamCounts[$teamName]++;
}

arsort($teamCounts);
$topTeams = array_slice($teamCounts, 0, 5, true);
$topTeamName = '-';
$topTeamCount = 0;
if (!empty($teamCounts)) {
  $topTeamName = (string)array_key_first($teamCounts);
  $topTeamCount = (int)($teamCounts[$topTeamName] ?? 0);
}

$reportCount = count($reportList);
$averageParticipants = $reportCount > 0 ? round($totalParticipants / $reportCount, 1) : 0;

$participantRows = $pdo->query("
    SELECT
        report_id,
        user_login_id,
        user_name
    FROM work_report_worker_hazard_selection
    GROUP BY report_id, user_login_id, user_name
    ORDER BY user_name ASC, user_login_id ASC
")->fetchAll() ?: [];

$participantMap = [];
foreach ($participantRows as $participantRow) {
    $mapReportId = (int)($participantRow['report_id'] ?? 0);
    if ($mapReportId <= 0) {
        continue;
    }
    $participantMap[$mapReportId][] = [
        'user_name' => (string)($participantRow['user_name'] ?? ''),
        'user_login_id' => (string)($participantRow['user_login_id'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>위험성평가 목록</title>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
<style>
  :root {
    --bg:       #0c1420;
    --bg2:      #111d2e;
    --bg3:      #162033;
    --border:   rgba(255,255,255,0.07);
    --border2:  rgba(255,255,255,0.12);
    --text:     #c5d8eb;
    --text-dim: #5d7a96;
    --text-hi:  #e8f2fc;
    --accent:   #e8920a;
    --accent2:  #f5a623;
    --blue:     #3a7fc1;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background: var(--bg) !important;
    min-height: 100vh;
    color: var(--text) !important;
    padding: 28px 20px 48px;
  }
  .shell { max-width: 1100px; margin: 0 auto; }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 22px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
  }
  .topbar-label {
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 4px;
  }
  .topbar-title {
    font-size: 22px;
    font-weight: 900;
    color: var(--text-hi);
    line-height: 1.2;
  }
  .topbar-title span { color: var(--accent2); }
  .identity {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .role-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 11px;
    border-radius: 999px;
    background: rgba(232,146,10,0.15);
    color: var(--accent2);
    font-size: 12px;
    font-weight: bold;
    border: 1px solid rgba(232,146,10,0.35);
  }
  .actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
  }
  .btn-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 9px;
    cursor: pointer;
    padding: 11px 18px;
    font-size: 13px;
    font-family: inherit;
    font-weight: 600;
    background: rgba(255,255,255,0.05);
    color: var(--text);
    border: 1px solid var(--border2);
  }
  .btn-secondary:hover { background: rgba(255,255,255,0.09); }
  .participant-text {
    color: var(--blue);
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    background: transparent;
    border: none;
    padding: 0;
    font-family: inherit;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .participant-text:hover { color: var(--accent2); }
  .participant-text:disabled { cursor: default; text-decoration: none; opacity: 0.5; }
  .panel {
    background: var(--bg2) !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    box-shadow: none !important;
    overflow: hidden;
  }
  .panel-head {
    padding: 24px 28px 16px;
    border-bottom: 1px solid var(--border) !important;
    background: var(--bg2) !important;
  }
  .panel-head-label {
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 6px;
  }
  .panel-head h1 {
    font-size: 26px;
    font-weight: 900;
    color: var(--text-hi);
    margin-bottom: 6px;
  }
  .panel-head h1 span { color: var(--accent2); }
  .panel-head p { color: var(--text-dim); font-size: 13px; line-height: 1.6; }
  .report-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-top: 16px;
  }
  .meta-box {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
    border-radius: 12px;
    padding: 12px 14px;
  }
  .meta-box strong { display: block; font-size: 11px; color: var(--text-dim); margin-bottom: 5px; }
  .meta-box span   { color: var(--text-hi); font-weight: 700; font-size: 14px; }
  .content { padding: 22px 28px 28px; display: grid; gap: 20px; }
  .message, .error { border-radius: 10px; padding: 12px 16px; font-size: 13px; }
  .message { background: rgba(30,90,50,.35); border: 1px solid rgba(60,180,90,.3); color: #6de09a; }
  .error   { background: rgba(90,20,20,.35); border: 1px solid rgba(200,60,60,.3); color: #f09090; }
  .section-title {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-hi);
    margin-bottom: 12px;
    letter-spacing: .04em;
  }
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
    margin-bottom: 16px;
  }
  .stat-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
  }
  .stat-label {
    color: var(--text-dim);
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 6px;
    letter-spacing: .04em;
  }
  .stat-value {
    color: var(--text-hi);
    font-size: 20px;
    font-weight: 900;
    line-height: 1.1;
  }
  .stat-sub {
    margin-top: 4px;
    color: var(--text-dim);
    font-size: 11px;
  }
  .team-mini-list {
    margin-top: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .team-mini-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.04);
    color: var(--text);
    font-size: 12px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
  }
  .team-mini-item:hover { background: rgba(255,255,255,0.09); }
  .team-mini-item.is-active {
    border-color: rgba(245,166,35,0.55);
    background: rgba(245,166,35,0.18);
    color: #ffe8c3;
  }
  .team-mini-item em {
    font-style: normal;
    color: var(--accent2);
  }
  .team-filter-empty {
    margin-top: 10px;
    border: 1px dashed var(--border2);
    border-radius: 10px;
    padding: 12px;
    color: var(--text-dim);
    font-size: 12px;
    display: none;
  }
  .report-list { display: grid; gap: 8px; }
  .report-row {
    display: grid;
    grid-template-columns: 140px 1fr auto;
    gap: 12px;
    align-items: center;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    transition: background .15s;
  }
  .report-row:hover { background: rgba(255,255,255,0.055); }
  .report-head { color: var(--text-dim); font-size: 11px; font-weight: 700; margin-bottom: 6px; }
  .report-value { color: var(--text-hi); font-size: 14px; font-weight: 700; }
  .empty {
    border: 1px dashed var(--border2);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    color: var(--text-dim);
  }
  .modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
  }
  .modal-backdrop.is-open { display: flex; }
  .modal-panel {
    width: min(420px, 100%);
    background: var(--bg3);
    border: 1px solid var(--border2);
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.5);
    overflow: hidden;
  }
  .modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
  }
  .modal-head h2 { font-size: 18px; font-weight: 800; color: var(--text-hi); }
  .modal-close {
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.05);
    color: var(--text);
    border-radius: 9px;
    padding: 7px 12px;
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
  }
  .modal-close:hover { background: rgba(255,255,255,0.09); }
  .modal-body { padding: 16px 20px 20px; }
  .participant-list { display: grid; gap: 8px; list-style: none; }
  .participant-item {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 14px;
  }
  .participant-name { color: var(--text-hi); font-size: 14px; font-weight: 700; }
  .participant-role-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
  }
  .participant-role-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
    border-radius: 999px;
    padding: 6px 10px;
    color: var(--text);
    font-size: 12px;
    font-weight: 700;
  }
  .participant-role-chip strong {
    color: var(--accent2);
    font-size: 11px;
    font-weight: 700;
  }
  @media (max-width: 720px) {
    .panel-head, .content { padding-left: 18px; padding-right: 18px; }
    .report-row { grid-template-columns: 1fr; }
    .actions { justify-content: space-between; }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div>
        <div class="topbar-label">RISK ASSESSMENT · REVIEW</div>
        <div class="topbar-title">위험성평가 <span>목록</span></div>
      </div>
      <div class="identity">
        <?php if (!auth_is_worker($user)): ?>
          <span class="role-badge"><?= h($user['role_label']) ?></span>
        <?php endif; ?>
        <span style="color:var(--text-dim);font-size:13px"><?= h(auth_display_name($user)) ?></span>
        <div class="actions">
          <a class="btn-secondary" href="work_list.php">작업목록</a>
          <a class="btn-secondary" href="unit_ra_print_batch.php">단위 위험성평가 출력</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-label">HAZARD REVIEW</div>
        <h1>위험성평가 <span>열람</span></h1>
        <p>금일 위험성평가서를 열람해주세요</p>
        <?php if ($report): ?>
          <div class="report-meta">
            <div class="meta-box">
              <strong>작업명</strong>
              <span><?= h($report['work_title']) ?></span>
            </div>
            <div class="meta-box">
              <strong>작업일자</strong>
              <span><?= h($report['work_date']) ?></span>
            </div>
            <div class="meta-box">
              <strong>작업장소</strong>
              <span><?= h($report['work_place']) ?></span>
            </div>
            <div class="meta-box">
              <strong>작성자</strong>
              <span><?= h($report['user_name']) ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="content">
        <?php if ($submitted): ?>
          <div class="message">위험성평가 제출이 완료되었습니다. 금일 위험성평가서를 열람해주세요.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="error"><?= h($error) ?></div>
        <?php else: ?>
          <section>
            <div class="section-title">위험성평가 목록</div>
            <div class="stats-grid">
              <div class="stat-card">
                <div class="stat-label">총 평가 건수</div>
                <div class="stat-value"><?= number_format($reportCount) ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">금일 평가 건수</div>
                <div class="stat-value"><?= number_format($todayReports) ?></div>
                <div class="stat-sub"><?= h($todayKey) ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">팀 수</div>
                <div class="stat-value"><?= number_format(count($teamCounts)) ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">참여자 제출 건수</div>
                <div class="stat-value"><?= number_format($reportsWithParticipants) ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">전체 참여자 수</div>
                <div class="stat-value"><?= number_format($totalParticipants) ?></div>
                <div class="stat-sub">보고서 기준 합계</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">보고서당 평균 참여자</div>
                <div class="stat-value"><?= number_format($averageParticipants, 1) ?></div>
                <div class="stat-sub"><?= h($topTeamName) ?> 최다(<?= number_format($topTeamCount) ?>건)</div>
              </div>
            </div>

            <?php if (!empty($topTeams)): ?>
              <div class="team-mini-list">
                <button type="button" class="team-mini-item is-active" data-team-filter="all">전체 <em><?= number_format($reportCount) ?>건</em></button>
                <?php foreach ($topTeams as $teamName => $teamCount): ?>
                  <button
                    type="button"
                    class="team-mini-item"
                    data-team-filter="<?= h(auth_team_key((string)$teamName)) ?>"
                    data-team-name="<?= h((string)$teamName) ?>"
                  ><?= h((string)$teamName) ?> <em><?= number_format((int)$teamCount) ?>건</em></button>
                <?php endforeach; ?>
              </div>
              <div class="team-filter-empty" id="team-filter-empty">선택한 팀의 위험성평가 목록이 없습니다.</div>
            <?php endif; ?>

            <?php if (empty($reportList)): ?>
              <div class="empty">표시할 위험성평가 목록이 없습니다.</div>
            <?php else: ?>
              <div class="report-list">
                <?php foreach ($reportList as $listReport): ?>
                  <?php
                    $listTeamName = hazard_review_report_team_context($listReport);
                    if ($listTeamName === '') {
                        $listTeamName = '미지정';
                    }
                  ?>
                  <div class="report-row" data-team-key="<?= h(auth_team_key($listTeamName)) ?>" data-team-name="<?= h($listTeamName) ?>">
                    <div>
                      <div class="report-head">날짜</div>
                      <div class="report-value"><?= h($listReport['work_date']) ?></div>
                    </div>
                    <div>
                      <div class="report-head">작업명</div>
                      <div class="report-value"><?= h($listReport['work_title']) ?></div>
                    </div>
                    <div class="actions">
                      <?php $rolePeople = hazard_review_resolve_role_people($listReport); ?>
                      <?php $participants = $participantMap[(int)$listReport['report_id']] ?? []; ?>
                      <button
                        type="button"
                        class="participant-text"
                        data-report-title="<?= h($listReport['work_title']) ?>"
                        data-leader-name="<?= h((string)($rolePeople['leader_name'] ?? '')) ?>"
                        data-manager-name="<?= h((string)($rolePeople['manager_name'] ?? '')) ?>"
                        data-participants='<?= h(json_encode($participants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                        <?= empty($participants) ? 'disabled' : '' ?>
                      >참여자 <?= (int)($listReport['participant_count'] ?? 0) ?>명</button>
                      <a class="btn-secondary" href="hazard_review_detail.php?report_id=<?= (int)$listReport['report_id'] ?>">열기</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="modal-backdrop" id="participant-modal" aria-hidden="true">
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="participant-modal-title">
      <div class="modal-head">
        <h2 id="participant-modal-title">참여자 명단</h2>
        <button type="button" class="modal-close" id="participant-modal-close">닫기</button>
      </div>
      <div class="modal-body">
        <div class="participant-role-meta" id="participant-role-meta"></div>
        <ul class="participant-list" id="participant-modal-list">
          <li class="participant-item">
            <div class="participant-name">참여자 정보가 없습니다.</div>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const modal = document.getElementById('participant-modal');
      const closeButton = document.getElementById('participant-modal-close');
      const list = document.getElementById('participant-modal-list');
      const title = document.getElementById('participant-modal-title');
      const roleMeta = document.getElementById('participant-role-meta');
      const triggers = document.querySelectorAll('.participant-text[data-participants]');
      const teamFilterButtons = document.querySelectorAll('.team-mini-item[data-team-filter]');
      const reportRows = document.querySelectorAll('.report-row[data-team-key]');
      const teamFilterEmpty = document.getElementById('team-filter-empty');

      if (teamFilterButtons.length && reportRows.length) {
        const setActiveButton = (activeButton) => {
          teamFilterButtons.forEach((button) => {
            button.classList.toggle('is-active', button === activeButton);
          });
        };

        const applyTeamFilter = (teamKey) => {
          let visibleCount = 0;
          reportRows.forEach((row) => {
            const rowTeamKey = row.getAttribute('data-team-key') || '';
            const visible = teamKey === 'all' || rowTeamKey === teamKey;
            row.style.display = visible ? '' : 'none';
            if (visible) {
              visibleCount += 1;
            }
          });

          if (teamFilterEmpty) {
            teamFilterEmpty.style.display = visibleCount > 0 ? 'none' : 'block';
          }
        };

        teamFilterButtons.forEach((button) => {
          button.addEventListener('click', () => {
            const teamKey = button.getAttribute('data-team-filter') || 'all';
            setActiveButton(button);
            applyTeamFilter(teamKey);
          });
        });
      }

      if (modal && closeButton && list && title && roleMeta && triggers.length) {
        const closeModal = () => {
          modal.classList.remove('is-open');
          modal.setAttribute('aria-hidden', 'true');
        };

        const openModal = (workTitle, participants, leaderName, managerName) => {
          title.textContent = workTitle ? workTitle + ' 참여자 명단' : '참여자 명단';

          const leaderText = leaderName && leaderName.trim() !== '' ? leaderName : '';
          const managerText = managerName && managerName.trim() !== '' ? managerName : '미지정';
          const chips = [];
          if (leaderText !== '') {
            chips.push('<span class="participant-role-chip"><strong>작업지휘자</strong>' + leaderText + '</span>');
          }
          chips.push('<span class="participant-role-chip"><strong>관리감독자</strong>' + managerText + '</span>');
          roleMeta.innerHTML = chips.join('');

          if (!participants.length) {
            list.innerHTML = '<li class="participant-item"><div class="participant-name">참여자 정보가 없습니다.</div></li>';
          } else {
            list.innerHTML = participants.map((participant) => {
              const name = participant.user_name || '-';
              return '<li class="participant-item">'
                + '<div class="participant-name">' + name + '</div>'
                + '</li>';
            }).join('');
          }

          modal.classList.add('is-open');
          modal.setAttribute('aria-hidden', 'false');
        };

        triggers.forEach((trigger) => {
          trigger.addEventListener('click', () => {
            const workTitle = trigger.getAttribute('data-report-title') || '';
            const leaderName = trigger.getAttribute('data-leader-name') || '';
            const managerName = trigger.getAttribute('data-manager-name') || '';
            const raw = trigger.getAttribute('data-participants') || '[]';
            let participants = [];

            try {
              participants = JSON.parse(raw);
            } catch (error) {
              participants = [];
            }

            openModal(workTitle, Array.isArray(participants) ? participants : [], leaderName, managerName);
          });
        });

        closeButton.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
          if (event.target === modal) {
            closeModal();
          }
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            closeModal();
          }
        });
      }
    }());
  </script>
</body>
</html>
