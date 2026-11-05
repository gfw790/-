<?php
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

require_once __DIR__ . '/db_config.php';

const ORG_CHART_TABS = [
    'general' => '일반 조직도',
    'safety_mgmt' => '안전보건관리 조직도',
    'risk_assessment' => '위험성평가 조직도',
    'committee' => '산업안전보건위원회 조직도',
    'emergency' => '비상대응 조직도',
];
const ORG_CHART_TREE_TABS = ['general', 'risk_assessment']; // 기존 팀 조직도 화면을 그대로 재사용하는 탭
const ORG_CHART_BOX_TABS = ['safety_mgmt', 'committee']; // 직급/성명 박스형 조직도로 새로 구성하는 탭
const ORG_CHART_BOX_DEFAULTS = [
    'safety_mgmt' => [
        'chief_role' => '대표', 'chief_name' => '정연탁',
        'supervisor_role' => '부장', 'supervisor_name' => '진종철',
        'safety_role' => '과장', 'safety_name' => '김남균',
    ],
    'committee' => [
        'chairman_role' => '위원장', 'chairman_name' => '정연탁',
        'secretary_role' => '실장', 'secretary_name' => '정주랑',
        'employer_members' => [
            ['role' => '대표', 'name' => '정연탁'],
            ['role' => '부장', 'name' => '진종철'],
            ['role' => '실장', 'name' => '정주랑'],
        ],
        'worker_members' => [
            ['role' => '공사', 'name' => '윤택천'],
            ['role' => '가스', 'name' => '김도담'],
            ['role' => '제조', 'name' => '우홍기'],
        ],
    ],
];
const ORG_CHART_TABLE_DEFAULTS = [
    'emergency' => [
        ['role' => '총괄지휘', 'name' => '', 'department' => '', 'note' => ''],
        ['role' => '상황전파·비상연락', 'name' => '', 'department' => '', 'note' => ''],
        ['role' => '초기진압·소화', 'name' => '', 'department' => '', 'note' => ''],
        ['role' => '피난유도', 'name' => '', 'department' => '', 'note' => ''],
        ['role' => '응급구조·구호', 'name' => '', 'department' => '', 'note' => ''],
        ['role' => '방호·안전', 'name' => '', 'department' => '', 'note' => ''],
    ],
];

function org_chart_table_init(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS safety_org_charts(chart_key VARCHAR(30) PRIMARY KEY, chart_data LONGTEXT NOT NULL, updated_by VARCHAR(100) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function org_chart_table_load(PDO $db, string $chartKey): array
{
    $stmt = $db->prepare('SELECT chart_data FROM safety_org_charts WHERE chart_key=?');
    $stmt->execute([$chartKey]);
    $saved = $stmt->fetchColumn();
    if (is_string($saved) && is_array($decoded = json_decode($saved, true)) && $decoded !== []) {
        return $decoded;
    }
    return ORG_CHART_TABLE_DEFAULTS[$chartKey] ?? [];
}

function org_chart_table_rows_from_post(array $post): array
{
    $roles = $post['row_role'] ?? [];
    $names = $post['row_name'] ?? [];
    $departments = $post['row_department'] ?? [];
    $notes = $post['row_note'] ?? [];
    $count = max(count($roles), count($names), count($departments), count($notes));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $role = trim((string)($roles[$i] ?? ''));
        $name = trim((string)($names[$i] ?? ''));
        $department = trim((string)($departments[$i] ?? ''));
        $note = trim((string)($notes[$i] ?? ''));
        if ($role === '' && $name === '' && $department === '' && $note === '') {
            continue;
        }
        $rows[] = ['role' => $role, 'name' => $name, 'department' => $department, 'note' => $note];
    }
    return $rows;
}

function org_chart_box_load(PDO $db, string $chartKey): array
{
    $defaults = ORG_CHART_BOX_DEFAULTS[$chartKey] ?? [];
    $stmt = $db->prepare('SELECT chart_data FROM safety_org_charts WHERE chart_key=?');
    $stmt->execute([$chartKey]);
    $saved = $stmt->fetchColumn();
    if (is_string($saved) && is_array($decoded = json_decode($saved, true))) {
        return array_merge($defaults, $decoded);
    }
    return $defaults;
}

function org_chart_box_members_from_post(array $roles, array $names): array
{
    $count = max(count($roles), count($names));
    $members = [];
    for ($i = 0; $i < $count; $i++) {
        $role = trim((string)($roles[$i] ?? ''));
        $name = trim((string)($names[$i] ?? ''));
        if ($role === '' && $name === '') {
            continue;
        }
        $members[] = ['role' => $role, 'name' => $name];
    }
    return $members;
}

function org_chart_box_fields_from_post(array $post, string $chartKey): array
{
    if ($chartKey === 'committee') {
        return [
            'chairman_role' => trim((string)($post['chairman_role'] ?? '')),
            'chairman_name' => trim((string)($post['chairman_name'] ?? '')),
            'secretary_role' => trim((string)($post['secretary_role'] ?? '')),
            'secretary_name' => trim((string)($post['secretary_name'] ?? '')),
            'employer_members' => org_chart_box_members_from_post($post['employer_role'] ?? [], $post['employer_name'] ?? []),
            'worker_members' => org_chart_box_members_from_post($post['worker_role'] ?? [], $post['worker_name'] ?? []),
        ];
    }

    $defaults = ORG_CHART_BOX_DEFAULTS[$chartKey] ?? [];
    $fields = [];
    foreach (array_keys($defaults) as $key) {
        $fields[$key] = trim((string)($post[$key] ?? ''));
    }
    return $fields;
}

$chartType = (string)($_GET['chart'] ?? 'general');
if (!array_key_exists($chartType, ORG_CHART_TABS)) {
    $chartType = 'general';
}
$isTableChart = $chartType === 'emergency';
$isBoxChart = in_array($chartType, ORG_CHART_BOX_TABS, true);
$tableNotice = '';
$tableRows = [];
$boxFields = [];

if ($isTableChart) {
    try {
        $db = getDB();
        org_chart_table_init($db);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && auth_can_manage($user)) {
            $tableRows = org_chart_table_rows_from_post($_POST);
            $stmt = $db->prepare('INSERT INTO safety_org_charts(chart_key, chart_data, updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE chart_data=VALUES(chart_data), updated_by=VALUES(updated_by)');
            $stmt->execute([$chartType, json_encode($tableRows, JSON_UNESCAPED_UNICODE), trim((string)($user['name'] ?? $user['login_id']))]);
            $tableNotice = '저장했습니다.';
        } else {
            $tableRows = org_chart_table_load($db, $chartType);
        }
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $tableRows = ORG_CHART_TABLE_DEFAULTS[$chartType] ?? [];
    }
    if ($tableRows === []) {
        $tableRows = [['role' => '', 'name' => '', 'department' => '', 'note' => '']];
    }
} elseif ($isBoxChart) {
    try {
        $db = getDB();
        org_chart_table_init($db);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && auth_can_manage($user)) {
            $boxFields = org_chart_box_fields_from_post($_POST, $chartType);
            $stmt = $db->prepare('INSERT INTO safety_org_charts(chart_key, chart_data, updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE chart_data=VALUES(chart_data), updated_by=VALUES(updated_by)');
            $stmt->execute([$chartType, json_encode($boxFields, JSON_UNESCAPED_UNICODE), trim((string)($user['name'] ?? $user['login_id']))]);
            $tableNotice = '저장했습니다.';
        } else {
            $boxFields = org_chart_box_load($db, $chartType);
        }
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $boxFields = ORG_CHART_BOX_DEFAULTS[$chartType] ?? [];
    }
}

$stripName = static function(string $name): string {
    return trim((string)preg_replace('/\s*\([^)]*\)/', '', $name));
};

$orgCeo = [];
$orgSafety = [];
foreach (auth_accounts() as $account) {
    $role = (string)($account['role'] ?? '');
    $name = $stripName((string)($account['name'] ?? ''));
    if ($name === '' || $role === 'admin') {
        continue;
    }

    $entry = ['name' => $name, 'phone' => trim((string)($account['phone'] ?? ''))];
    if ($role === 'ceo') {
        $orgCeo[] = $entry;
    } elseif ($role === 'safety_manager') {
        $orgSafety[] = $entry;
    }
}

$teamSupervisors = auth_read_team_supervisors();
$orgTeamEntries = [];
foreach (auth_read_teams() as $teamName) {
    if (auth_team_key($teamName) === auth_team_key('안전관리')) {
        continue;
    }

    $toMembers = static function(array $members) use ($stripName): array {
        $result = [];
        foreach ($members as $member) {
            $name = $stripName((string)($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $result[] = [
                'name' => $name,
                'phone' => trim((string)($member['phone'] ?? '')),
            ];
        }
        return $result;
    };

    // 경영지원 팀은 모든 role 포함, 다른 팀은 manager/leader/worker만
    $isAdminTeam = auth_team_key($teamName) === auth_team_key('경영지원');
    $managerRoles = $isAdminTeam ? ['manager', 'safety_manager', 'leader', 'worker', 'administrator', 'admin'] : ['manager'];
    $leaderRoles = $isAdminTeam ? ['leader', 'safety_manager'] : ['leader'];
    $workerRoles = ['worker'];

    $orgTeamEntries[$teamName] = [
        'name' => $teamName,
        'managers' => $toMembers(auth_team_members($teamName, $managerRoles)),
        'leaders' => $toMembers(auth_team_members($teamName, $leaderRoles)),
        'workers' => $toMembers(auth_team_members($teamName, $workerRoles)),
        'children' => [],
    ];
}

$orgTeamParent = [];
foreach ($orgTeamEntries as $teamName => $_) {
    $supervisor = $teamSupervisors[$teamName] ?? '';
    if ($supervisor !== '' && auth_team_exists($supervisor)) {
        $orgTeamParent[$teamName] = $supervisor;
    }
}

$orgTeams = [];
foreach ($orgTeamEntries as $teamName => $entry) {
    if (isset($orgTeamParent[$teamName])) {
        continue;
    }

    $entry['children'] = [];
    foreach ($orgTeamEntries as $possibleChildName => $possibleChildEntry) {
        if (isset($orgTeamParent[$possibleChildName]) && auth_team_key($orgTeamParent[$possibleChildName]) === auth_team_key($teamName)) {
            $entry['children'][] = $possibleChildEntry;
        }
    }

    $orgTeams[] = $entry;
}

$allTeamNames = [];
foreach ($orgTeams as $team) {
  $teamName = (string)($team['name'] ?? '');
  if ($teamName !== '') {
    $allTeamNames[] = $teamName;
  }
  foreach (($team['children'] ?? []) as $childTeam) {
    $childName = (string)($childTeam['name'] ?? '');
    if ($childName !== '') {
      $allTeamNames[] = $childName;
    }
  }
}

$allTeamNames = array_values(array_unique($allTeamNames));
sort($allTeamNames, SORT_STRING);

$orgChart = auth_org_chart_data();
$orgCeo = $orgChart['ceo'];
$orgSafety = $orgChart['safety'];
$orgTeams = $orgChart['teams'];
$allTeamNames = $orgChart['all_team_names'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>(주)현대기전 조직도</title>
<style>
  :root {
    --bg: #f5f7fb;
    --card: #ffffff;
    --line: #d7dce7;
    --text: #1f2937;
    --sub: #5b6475;
    --accent: #e28f20;
    --accent2: #2f6fcb;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: "Malgun Gothic", sans-serif;
    background: var(--bg);
    color: var(--text);
    padding: 20px 18px 28px;
  }
  .shell { max-width: 1240px; margin: 0 auto; }
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
  }
  .title-wrap h1 {
    margin: 0;
    font-size: 22px;
    color: #1c2738;
  }
  .title-wrap p {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--sub);
  }
  .actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .org-chart-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 14px;
  }
  .org-chart-tab {
    border: 1px solid #c9d2e2;
    background: #fff;
    color: #1d2b45;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
  }
  .org-chart-tab.is-active {
    border-color: #d58314;
    background: var(--accent);
    color: #fff;
  }
  .org-table-form { width: 100%; border-collapse: collapse; }
  .org-table-form th, .org-table-form td { border: 1px solid var(--line); padding: 8px; text-align: center; }
  .org-table-form th { background: #f2f4f9; }
  .org-table-form input { width: 100%; border: 1px solid #d5ddea; border-radius: 6px; padding: 6px 8px; font: inherit; }
  @media print {
    .org-chart-tabs { display: none !important; }
  }
  .btn {
    border: 1px solid #c9d2e2;
    background: #fff;
    color: #1d2b45;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
  }
  .btn-primary {
    border-color: #d58314;
    background: var(--accent);
    color: #fff;
  }
  .sheet {
    border: 1px solid var(--line);
    border-radius: 14px;
    background: var(--card);
    padding: 22px 24px 20px;
  }
  .print-title {
    margin: 0 0 16px;
    text-align: center;
    font-size: 24px;
    line-height: 1.2;
    font-weight: 800;
    color: #1c2738;
    letter-spacing: .02em;
  }
  .team-filter-panel {
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    padding: 12px;
    margin-bottom: 12px;
  }
  .team-filter-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    flex-wrap: wrap;
  }
  .team-filter-head strong {
    font-size: 13px;
    color: #1d2b45;
  }
  .team-filter-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  .is-hidden {
    display: none !important;
  }
  .btn-ghost {
    border: 1px solid #d5ddea;
    background: #fff;
    color: #31445f;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
  }
  .team-filter-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 7px 10px;
  }
  .team-filter-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: #233248;
    padding: 3px 0;
  }
  .team-filter-empty {
    font-size: 12px;
    color: var(--sub);
  }
  .team-filter-merge {
    margin-left: auto;
    font-weight: 700;
    color: #2a3d58;
  }
  /* ── 병합 상태 표시 바 ── */
  .merge-status-bar {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: #fff8ed;
    border: 1px solid #f0c060;
    border-radius: 10px;
    margin-bottom: 12px;
    font-size: 13px;
    color: #7a4800;
    flex-wrap: wrap;
  }
  .merge-status-label { font-weight: 700; flex-shrink: 0; }
  #merge-status-text { flex: 1; min-width: 0; }
  /* ── 인쇄 미리보기 바 ── */
  .preview-bar {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 9000;
    background: #1d2b45;
    color: #fff;
    padding: 10px 20px;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.28);
  }
  .preview-bar-title { font-size: 14px; font-weight: 700; letter-spacing: .01em; }
  .preview-bar-hint { font-size: 12px; color: rgba(255,255,255,0.6); margin-left: 10px; }
  .preview-bar-actions { display: flex; gap: 8px; }
  .preview-btn-light {
    border: 1px solid rgba(255,255,255,0.35);
    background: transparent;
    color: #fff;
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
  }
  body.is-preview .preview-bar { display: flex; }
  body.is-preview .toolbar,
  body.is-preview .team-filter-panel,
  body.is-preview .merge-status-bar { display: none !important; }
  body.is-preview {
    background: #4b5563;
    padding-top: 56px;
  }
  body.is-preview .shell {
    display: flex;
    flex-direction: column;
    align-items: center;
    max-width: none;
    padding: 24px 20px 48px;
  }
  body.is-preview .sheet {
    width: min(210mm, calc(100vw - 40px));
    border-radius: 2px;
    box-shadow: 0 4px 32px rgba(0,0,0,0.4);
  }
  /* ── 팀 병합 모달 ── */
  .merge-team-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(15, 22, 34, 0.45);
    padding: 16px;
  }
  .merge-team-modal-backdrop.is-open {
    display: flex;
  }
  .merge-team-modal {
    width: min(540px, 100%);
    background: #fff;
    border: 1px solid #d7dce7;
    border-radius: 14px;
    box-shadow: 0 18px 48px rgba(16, 26, 41, 0.28);
    overflow: hidden;
  }
  .merge-team-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid #e2e7f0;
    background: #f7f9fd;
  }
  .merge-team-modal-head h2 {
    margin: 0;
    font-size: 15px;
    color: #1d2b45;
  }
  .merge-team-modal-close {
    border: 1px solid #d3dbe9;
    background: #fff;
    color: #374b6a;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
  }
  .merge-team-modal-body {
    padding: 12px 14px;
  }
  .merge-team-modal-sub {
    margin: 0 0 10px;
    font-size: 12px;
    color: #5b6475;
  }
  .merge-team-modal-actions {
    display: flex;
    gap: 6px;
    margin-bottom: 10px;
    flex-wrap: wrap;
  }
  .merge-team-list {
    max-height: 280px;
    overflow: auto;
    border: 1px solid #e2e7f0;
    border-radius: 10px;
    padding: 8px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 6px 10px;
  }
  .merge-team-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: #1f2f46;
    padding: 3px 0;
  }
  .merge-team-modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 14px;
    border-top: 1px solid #e2e7f0;
    background: #fbfcff;
  }
  .org-chart-surface {
    --org-team-top-width: 96px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    padding: 14px 16px 14px;
  }
  .org-chart { display: flex; flex-direction: column; align-items: center; }
  .org-vert { width: 2px; height: 34px; background: #cfd6e4; flex-shrink: 0; }
  .org-node {
    border: 1px solid #cad2e2;
    border-radius: 12px;
    padding: 0;
    text-align: center;
    background: #fff;
    overflow: hidden;
  }
  .org-node-label {
    display: block;
    font-size: 10px;
    color: #fff;
    margin: 0;
    letter-spacing: .04em;
    padding: 7px 12px;
    font-weight: 800;
  }
  .org-node-name {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
    padding: 8px 14px 9px;
  }
  .org-node-ceo { border-color: #e2a042; box-shadow: inset 0 0 0 1px #f2d2a1; }
  .org-node-ceo .org-node-label { background: linear-gradient(90deg, #f0a33f, #e58d20); }
  .org-node-safety { border-color: #7dc8a5; }
  .org-node-safety .org-node-label { background: linear-gradient(90deg, #4eb67f, #2f9969); }
  .org-safety-junction { align-self: stretch; position: relative; display: flex; flex-direction: column; align-items: center; }
  .org-junction-stem { display: flex; flex-direction: column; align-items: center; }
  .org-safety-junction-tall .org-vert { height: 50px; }
  .org-junction-branch { position: absolute; left: 50%; top: 50%; transform: translateY(-50%); display: flex; align-items: center; }
  .org-junction-hline { width: 120px; height: 2px; background: #6a7890; flex-shrink: 0; }
  .org-teams-wrap { width: 100%; overflow-x: auto; padding-bottom: 4px; }
  .org-teams-row {
    display: flex;
    gap: 28px;
    align-items: flex-start;
    position: relative;
    width: fit-content;
    margin: 0 auto;
  }
  .org-teams-row::before {
    content: '';
    position: absolute;
    top: 0;
    left: calc(var(--org-team-top-width) / 2);
    right: calc(var(--org-team-top-width) / 2);
    height: 2px;
    background: #6a7890;
  }
  .org-team-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-top: 56px;
    position: relative;
    width: var(--org-team-top-width);
    flex: 0 0 var(--org-team-top-width);
  }
  .org-team-col::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 56px;
    background: #6a7890;
  }
  .org-team-card {
    width: 100%;
    min-width: 0;
    background: #fff;
    border: 1px solid #d8deea;
    border-radius: 10px;
    overflow: hidden;
  }
  .org-team-card-child { min-width: 114px; }
  .org-team-head {
    background: linear-gradient(90deg, #f0a33f, #e58d20);
    color: #fff;
    text-align: center;
    padding: 6px 7px;
    font-size: 10px;
    font-weight: 800;
    line-height: 1.25;
  }
  .org-team-body { padding: 6px 6px 6px; }
  .org-team-children-wrap {
    position: relative;
    width: max-content;
    min-width: 100%;
    margin-top: 16px;
    display: flex;
    justify-content: center;
  }
  .org-team-children-wrap::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 14px;
    background: #6a7890;
  }
  .org-team-children-row {
    display: flex;
    gap: 14px;
    padding-top: 14px;
    width: max-content;
    justify-content: center;
  }
  .org-subteam-col { display: flex; flex-direction: column; align-items: center; }
  .org-role-sec { padding: 6px 0; border-top: 1px dashed #d5dbe7; }
  .org-role-sec:first-child { border-top: none; padding-top: 0; }
  .org-role-lbl {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    color: #6b7280;
    margin-bottom: 5px;
    letter-spacing: .05em;
    font-weight: 700;
  }
  .org-role-lbl::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 999px;
    background: #f0a33f;
  }
  .org-role-lbl.role-manager { color: #b26b00; }
  .org-role-lbl.role-manager::before { background: #e28f20; }
  .org-role-lbl.role-leader { color: #1f5eb8; }
  .org-role-lbl.role-leader::before { background: #3f7fdd; }
  .org-role-lbl.role-worker { color: #1f8c53; }
  .org-role-lbl.role-worker::before { background: #33b375; }
  .org-member-name {
    display: block;
    font-size: 10px;
    color: #1f2937;
    line-height: 1.45;
    padding: 1px 0;
  }
  .org-node-safety .org-member-name {
    display: inline;
  }
  .org-box-chart { width: 100%; max-width: 460px; margin: 0 auto; }
  .org-box-card {
    width: 100%;
    border: 1px solid var(--line);
    border-radius: 0;
    overflow: hidden;
    background: #fff;
  }
  .org-box-committee-link { width: auto; min-width: 200px; }
  .org-box-head {
    display: block;
    text-align: center;
    font-size: 15px;
    font-weight: 800;
    color: #1f2937;
    background: #f2f4f9;
    padding: 8px 12px;
    border-bottom: 1px solid var(--line);
  }
  .org-box-head-full { border-bottom: none; }
  .org-box-row { display: flex; }
  .org-box-cell { flex: 1; text-align: center; padding: 6px 4px; }
  .org-box-cell:first-child { border-right: 1px solid var(--line); }
  .org-box-cell input {
    width: 100%;
    border: none;
    text-align: center;
    font-size: 14px;
    font-weight: 700;
    color: #1f2937;
    background: transparent;
  }
  .org-box-cell input:focus { outline: 2px solid #4f7ad0; outline-offset: 2px; }
  .org-box-workers { padding: 10px 12px; }
  .org-box-workers .org-box-head { border-bottom: none; }
  .org-committee-cols {
    --col-width: 200px;
    display: flex;
    gap: 40px;
    justify-content: center;
    position: relative;
    width: fit-content;
    margin: 0 auto;
  }
  .org-committee-cols::before {
    content: '';
    position: absolute;
    top: 0;
    left: calc(var(--col-width) / 2);
    right: calc(var(--col-width) / 2);
    height: 2px;
    background: #6a7890;
  }
  .org-committee-col {
    width: var(--col-width);
    flex: 0 0 var(--col-width);
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    padding-top: 28px;
  }
  .org-committee-col::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 28px;
    background: #6a7890;
  }
  .org-committee-col .org-box-card { width: 100%; }
  .org-box-member-table { width: 100%; border-collapse: collapse; }
  .org-box-member-table td { border: 1px solid var(--line); padding: 5px 4px; text-align: center; }
  .org-box-member-table td:first-child { width: 36%; }
  .org-box-member-table input {
    width: 100%;
    border: none;
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    background: transparent;
  }
  .org-box-member-table input:focus { outline: 2px solid #4f7ad0; outline-offset: 1px; }
  .org-box-member-remove-cell { border: none !important; width: 44px; padding: 2px !important; }
  @media (max-width: 720px) {
    body { padding: 14px 10px 18px; }
    .org-chart-surface { --org-team-top-width: 92px; padding: 12px 8px 8px; }
    .org-team-card-child { min-width: 112px; }
    .org-team-col { padding-top: 44px; }
    .org-team-col::before { height: 44px; }
  }
  @media print {
    @page {
      size: A4 portrait;
      margin: 20mm 8mm;
    }
    body {
      background: #fff !important;
      padding: 0;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .toolbar,
    .team-filter-panel,
    .preview-bar,
    .merge-status-bar,
    .merge-team-modal-backdrop { display: none !important; }
    /* is-preview 클래스가 인쇄 시 레이아웃에 영향을 주지 않도록 초기화 */
    body.is-preview { padding-top: 0; background: #fff !important; }
    body.is-preview .shell { display: block; max-width: none; padding: 0; }
    body.is-preview .sheet { width: auto; box-shadow: none; border-radius: 0; margin: 0; }
    .shell { max-width: 100%; margin: 0; }
    .sheet {
      border: none;
      border-radius: 0;
      padding: 20mm 3mm 4mm;
    }
    .print-title {
      margin: 0 0 14mm;
      font-size: 18pt;
      text-align: center;
    }
    /* 화면과 동일한 레이아웃 유지 — CSS 연결선이 그대로 작동 */
    .org-chart-surface {
      border: none;
      border-radius: 0;
      padding: 0;
    }
    .org-teams-wrap { overflow: visible; padding-bottom: 0; }
    .org-vert { height: 26px; }
    .org-team-col { padding-top: 32px; }
    .org-team-col::before { height: 32px; }
    .org-junction-hline { width: 60px; }
    .org-node-label { font-size: 9pt; padding: 4px 8px; }
    .org-node-name { font-size: 12pt; padding: 6px 10px; }
    .org-node-safety .org-node-name { font-size: 8pt; padding: 3px 8px; }
    .org-team-head { font-size: 8pt; padding: 4px 5px; }
    .org-team-body { padding: 4px 5px 3px; }
    .org-role-lbl { font-size: 7pt; margin-bottom: 2px; }
    .org-member-name { font-size: 7pt; line-height: 1.3; }
  }
</style>
</head>
<body>
  <div class="shell">
    <!-- 인쇄 미리보기 모드일 때 고정 상단 바 -->
    <div class="preview-bar" id="preview-bar" aria-label="인쇄 미리보기">
      <div style="display:flex;align-items:center;gap:4px;">
        <span class="preview-bar-title">인쇄 미리보기</span>
        <span class="preview-bar-hint">— A4 출력 결과를 확인하세요</span>
      </div>
      <div class="preview-bar-actions">
        <button type="button" class="btn btn-primary" id="preview-do-print-btn">인쇄</button>
        <button type="button" class="preview-btn-light" id="preview-close-btn">닫기</button>
      </div>
    </div>

    <div class="toolbar">
      <div class="title-wrap">
        <h1>(주)현대기전 조직도</h1>
        <p>인쇄할 팀을 선택한 뒤 <strong>미리보기</strong> 버튼으로 확인하고 출력하세요.</p>
      </div>
      <div class="actions">
        <a class="btn" href="work_list.php">목록으로</a>
        <?php if (!$isTableChart && !$isBoxChart): ?><button type="button" class="btn btn-primary" id="print-preview-btn">미리보기 / 인쇄</button><?php endif; ?>
      </div>
    </div>

    <nav class="org-chart-tabs" aria-label="조직도 종류 선택">
      <?php foreach (ORG_CHART_TABS as $tabKey => $tabLabel): ?>
        <a class="org-chart-tab<?= $tabKey === $chartType ? ' is-active' : '' ?>" href="?chart=<?= h($tabKey) ?>"><?= h($tabLabel) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if (!$isTableChart && !$isBoxChart): ?>
    <section class="team-filter-panel" aria-label="인쇄 팀 선택">
      <div class="team-filter-head">
        <strong>인쇄할 팀 선택 (체크 해제 시 출력 제외)</strong>
        <div class="team-filter-actions">
          <button type="button" class="btn-ghost" id="team-select-all">전체 선택</button>
          <button type="button" class="btn-ghost" id="team-clear-all">전체 해제</button>
          <button type="button" class="btn-ghost" id="open-merge-team-modal">팀 병합 설정</button>
          <button type="button" class="btn-ghost" id="reset-merge-team">병합 초기화</button>
        </div>
      </div>
      <?php if (empty($allTeamNames)): ?>
        <div class="team-filter-empty">선택 가능한 팀이 없습니다.</div>
      <?php else: ?>
        <div class="team-filter-list" id="team-filter-list">
          <?php foreach ($allTeamNames as $teamName): ?>
            <label class="team-filter-item">
              <input type="checkbox" class="js-team-filter" value="<?= h($teamName) ?>" checked>
              <span><?= h($teamName) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- 팀 병합 적용 중일 때 상태 표시 -->
    <div class="merge-status-bar" id="merge-status-bar" role="status">
      <span class="merge-status-label">팀 병합 적용 중:</span>
      <span id="merge-status-text"></span>
      <button type="button" class="btn-ghost" id="merge-status-reset-btn">해제</button>
    </div>

    <div class="merge-team-modal-backdrop" id="merge-team-modal" aria-hidden="true">
      <div class="merge-team-modal" role="dialog" aria-modal="true" aria-labelledby="merge-team-modal-title">
        <div class="merge-team-modal-head">
          <h2 id="merge-team-modal-title">병합할 팀 선택</h2>
          <button type="button" class="merge-team-modal-close" id="merge-team-modal-close" aria-label="닫기">&times;</button>
        </div>
        <div class="merge-team-modal-body">
          <p class="merge-team-modal-sub">기준 팀과 포함할 팀을 고르면 현재 미리보기 화면에 즉시 반영됩니다.</p>
          <div class="merge-team-modal-actions" style="margin-bottom:8px;">
            <label class="team-filter-item" style="font-weight:700;color:#2a3d58;">
              <span>기준 팀</span>
              <select id="merge-base-team" style="margin-left:8px;padding:4px 8px;border:1px solid #d5ddea;border-radius:8px;">
                <?php foreach ($allTeamNames as $teamName): ?>
                  <option value="<?= h($teamName) ?>"><?= h($teamName) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="merge-team-modal-actions">
            <button type="button" class="btn-ghost" id="merge-team-select-all">전체 선택</button>
            <button type="button" class="btn-ghost" id="merge-team-clear-all">전체 해제</button>
          </div>
          <div class="merge-team-list" id="merge-team-list">
            <?php foreach ($allTeamNames as $teamName): ?>
              <label class="merge-team-item">
                <input type="checkbox" class="js-merge-team-option" value="<?= h($teamName) ?>" checked>
                <span><?= h($teamName) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="merge-team-modal-foot">
          <button type="button" class="btn" id="merge-team-cancel">취소</button>
          <button type="button" class="btn btn-primary" id="merge-team-apply-print">적용</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <section class="sheet">
      <h2 class="print-title"><?= h(ORG_CHART_TABS[$chartType]) ?></h2>

      <?php if ($isTableChart): ?>
      <?php if ($tableNotice !== ''): ?><p style="color:#1f8c53;font-weight:700;"><?= h($tableNotice) ?></p><?php endif; ?>
      <form method="post">
        <table class="org-table-form">
          <tr><th style="width:22%">구분</th><th style="width:20%">성명</th><th style="width:28%">소속·직책</th><th>비고</th><th style="width:60px"></th></tr>
          <?php foreach ($tableRows as $row): ?>
          <tr>
            <td><input type="text" name="row_role[]" value="<?= h((string)($row['role'] ?? '')) ?>"></td>
            <td><input type="text" name="row_name[]" value="<?= h((string)($row['name'] ?? '')) ?>"></td>
            <td><input type="text" name="row_department[]" value="<?= h((string)($row['department'] ?? '')) ?>"></td>
            <td><input type="text" name="row_note[]" value="<?= h((string)($row['note'] ?? '')) ?>"></td>
            <td><button type="button" class="btn-ghost org-table-row-remove">삭제</button></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button type="button" class="btn-ghost" id="org-table-row-add">+ 행 추가</button>
          <?php if (auth_can_manage($user)): ?><button type="submit" class="btn btn-primary">저장하기</button><?php endif; ?>
        </div>
      </form>
      <?php elseif ($isBoxChart && $chartType === 'safety_mgmt'): ?>
      <?php if ($tableNotice !== ''): ?><p style="color:#1f8c53;font-weight:700;"><?= h($tableNotice) ?></p><?php endif; ?>
      <form method="post">
        <div class="org-chart-surface">
          <div class="org-chart org-box-chart">
            <div class="org-box-card org-box-chief">
              <div class="org-box-head">안전보건관리책임자</div>
              <div class="org-box-row">
                <div class="org-box-cell"><input type="text" name="chief_role" value="<?= h((string)($boxFields['chief_role'] ?? '')) ?>"></div>
                <div class="org-box-cell"><input type="text" name="chief_name" value="<?= h((string)($boxFields['chief_name'] ?? '')) ?>"></div>
              </div>
            </div>

            <div class="org-safety-junction">
              <div class="org-junction-stem">
                <div class="org-vert"></div>
                <div class="org-vert"></div>
              </div>
              <div class="org-junction-branch">
                <div class="org-junction-hline"></div>
                <div class="org-box-card org-box-committee-link">
                  <div class="org-box-head">산업안전보건위원회</div>
                </div>
              </div>
            </div>

            <div class="org-box-card org-box-supervisor">
              <div class="org-box-head">관리감독자</div>
              <div class="org-box-row">
                <div class="org-box-cell"><input type="text" name="supervisor_role" value="<?= h((string)($boxFields['supervisor_role'] ?? '')) ?>"></div>
                <div class="org-box-cell"><input type="text" name="supervisor_name" value="<?= h((string)($boxFields['supervisor_name'] ?? '')) ?>"></div>
              </div>
            </div>

            <div class="org-vert"></div>

            <div class="org-box-card org-box-safety-officer">
              <div class="org-box-head">안전담당자</div>
              <div class="org-box-row">
                <div class="org-box-cell"><input type="text" name="safety_role" value="<?= h((string)($boxFields['safety_role'] ?? '')) ?>"></div>
                <div class="org-box-cell"><input type="text" name="safety_name" value="<?= h((string)($boxFields['safety_name'] ?? '')) ?>"></div>
              </div>
            </div>

            <div class="org-vert"></div>

            <div class="org-box-card org-box-workers">
              <div class="org-box-head org-box-head-full">전 근로자</div>
            </div>
          </div>
          <?php if (auth_can_manage($user)): ?>
          <div style="display:flex;justify-content:center;margin-top:14px;">
            <button type="submit" class="btn btn-primary">저장하기</button>
          </div>
          <?php endif; ?>
        </div>
      </form>
      <?php elseif ($isBoxChart && $chartType === 'committee'): ?>
      <?php if ($tableNotice !== ''): ?><p style="color:#1f8c53;font-weight:700;"><?= h($tableNotice) ?></p><?php endif; ?>
      <?php
      $employerMembers = $boxFields['employer_members'] ?? [];
      $workerMembers = $boxFields['worker_members'] ?? [];
      if ($employerMembers === []) { $employerMembers = [['role' => '', 'name' => '']]; }
      if ($workerMembers === []) { $workerMembers = [['role' => '', 'name' => '']]; }
      ?>
      <form method="post">
        <div class="org-chart-surface">
          <div class="org-chart org-box-chart">
            <div class="org-box-card org-box-chief">
              <div class="org-box-head">산업안전보건위원회</div>
              <div class="org-box-row">
                <div class="org-box-cell"><input type="text" name="chairman_role" value="<?= h((string)($boxFields['chairman_role'] ?? '')) ?>"></div>
                <div class="org-box-cell"><input type="text" name="chairman_name" value="<?= h((string)($boxFields['chairman_name'] ?? '')) ?>"></div>
              </div>
            </div>

            <div class="org-safety-junction org-safety-junction-tall">
              <div class="org-junction-stem">
                <div class="org-vert"></div>
                <div class="org-vert"></div>
              </div>
              <div class="org-junction-branch">
                <div class="org-junction-hline"></div>
                <div class="org-box-card" style="width:200px;">
                  <div class="org-box-head">간사</div>
                  <div class="org-box-row">
                    <div class="org-box-cell"><input type="text" name="secretary_role" value="<?= h((string)($boxFields['secretary_role'] ?? '')) ?>"></div>
                    <div class="org-box-cell"><input type="text" name="secretary_name" value="<?= h((string)($boxFields['secretary_name'] ?? '')) ?>"></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="org-vert"></div>

            <div class="org-committee-cols">
              <div class="org-committee-col">
                <div class="org-box-card">
                  <div class="org-box-head">사용자측 위원</div>
                  <table class="org-box-member-table" id="employer-member-table">
                    <?php foreach ($employerMembers as $member): ?>
                    <tr>
                      <td><input type="text" name="employer_role[]" value="<?= h((string)($member['role'] ?? '')) ?>"></td>
                      <td><input type="text" name="employer_name[]" value="<?= h((string)($member['name'] ?? '')) ?>"></td>
                      <td class="org-box-member-remove-cell"><button type="button" class="btn-ghost org-member-row-remove">삭제</button></td>
                    </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
                <button type="button" class="btn-ghost org-member-row-add" data-target="employer-member-table" data-role-name="employer_role[]" data-name-name="employer_name[]" style="margin-top:8px;">+ 위원 추가</button>
              </div>
              <div class="org-committee-col">
                <div class="org-box-card">
                  <div class="org-box-head">근로자측 위원</div>
                  <table class="org-box-member-table" id="worker-member-table">
                    <?php foreach ($workerMembers as $member): ?>
                    <tr>
                      <td><input type="text" name="worker_role[]" value="<?= h((string)($member['role'] ?? '')) ?>"></td>
                      <td><input type="text" name="worker_name[]" value="<?= h((string)($member['name'] ?? '')) ?>"></td>
                      <td class="org-box-member-remove-cell"><button type="button" class="btn-ghost org-member-row-remove">삭제</button></td>
                    </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
                <button type="button" class="btn-ghost org-member-row-add" data-target="worker-member-table" data-role-name="worker_role[]" data-name-name="worker_name[]" style="margin-top:8px;">+ 위원 추가</button>
              </div>
            </div>
          </div>
          <?php if (auth_can_manage($user)): ?>
          <div style="display:flex;justify-content:center;margin-top:14px;">
            <button type="submit" class="btn btn-primary">저장하기</button>
          </div>
          <?php endif; ?>
        </div>
      </form>
      <?php else: ?>

      <?php
      $orgNameHtml = static function(array $entry): string {
          return '<span class="org-member-name">' . h($entry['name']) . '</span>';
      };
      $renderOrgTeamCard = static function(array $team, callable $orgNameHtml, callable $renderOrgTeamCard, bool $isChild = false): void {
      ?>
        <div class="org-team-card<?= $isChild ? ' org-team-card-child' : '' ?>">
          <div class="org-team-head"><?= h((string)($team['name'] ?? '')) ?></div>
          <div class="org-team-body">
            <?php if (!empty($team['managers'])): ?>
              <div class="org-role-sec">
                <div class="org-role-lbl role-manager"><?= h((string)($team['manager_label'] ?? '관리감독자')) ?></div>
                <?php foreach ($team['managers'] as $member): ?>
                  <?= $orgNameHtml($member) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($team['leaders'])): ?>
              <div class="org-role-sec">
                <div class="org-role-lbl role-leader"><?= h((string)($team['leader_label'] ?? '작업지휘자')) ?></div>
                <?php foreach ($team['leaders'] as $member): ?>
                  <?= $orgNameHtml($member) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($team['workers'])): ?>
              <div class="org-role-sec">
                <div class="org-role-lbl role-worker"><?= h((string)($team['worker_label'] ?? '일반작업자')) ?></div>
                <?php foreach ($team['workers'] as $member): ?>
                  <?= $orgNameHtml($member) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($team['children'])): ?>
          <div class="org-team-children-wrap">
            <div class="org-team-children-row">
              <?php foreach ($team['children'] as $child): ?>
                <div class="org-subteam-col" data-team-name="<?= h((string)($child['name'] ?? '')) ?>">
                  <?php $renderOrgTeamCard($child, $orgNameHtml, $renderOrgTeamCard, true); ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php
      };
      ?>
      <div class="org-chart-surface">
        <div class="org-chart">
          <?php foreach ($orgCeo as $entry): ?>
            <div class="org-node org-node-ceo">
              <div class="org-node-label">대표이사</div>
              <div class="org-node-name"><?= $orgNameHtml($entry) ?></div>
            </div>
          <?php endforeach; ?>

          <?php if (!empty($orgSafety)): ?>
            <div class="org-safety-junction">
              <div class="org-junction-stem">
                <div class="org-vert"></div>
                <div class="org-vert"></div>
              </div>
              <div class="org-junction-branch">
                <div class="org-junction-hline"></div>
                <div class="org-node org-node-safety">
                  <div class="org-node-label">안전관리자</div>
                  <div class="org-node-name">
                    <?php foreach ($orgSafety as $entry): ?>
                      <?= $orgNameHtml($entry) ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="org-vert"></div>
          <?php endif; ?>

          <?php if (!empty($orgTeams)): ?>
            <div class="org-teams-wrap">
              <div class="org-teams-row">
                <?php foreach ($orgTeams as $team): ?>
                  <div class="org-team-col" data-team-name="<?= h((string)($team['name'] ?? '')) ?>">
                    <?php $renderOrgTeamCard($team, $orgNameHtml, $renderOrgTeamCard); continue; ?>
                    <div class="org-team-card">
                      <div class="org-team-head"><?= h($team['name']) ?></div>
                      <div class="org-team-body">
                        <?php if (!empty($team['managers'])): ?>
                          <div class="org-role-sec">
                            <div class="org-role-lbl role-manager">관리감독자</div>
                            <?php foreach ($team['managers'] as $member): ?>
                              <?= $orgNameHtml($member) ?>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($team['leaders'])): ?>
                          <div class="org-role-sec">
                            <div class="org-role-lbl role-leader">작업지휘자</div>
                            <?php foreach ($team['leaders'] as $member): ?>
                              <?= $orgNameHtml($member) ?>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($team['workers'])): ?>
                          <div class="org-role-sec">
                            <div class="org-role-lbl role-worker">일반작업자</div>
                            <?php foreach ($team['workers'] as $member): ?>
                              <?= $orgNameHtml($member) ?>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <?php if (!empty($team['children'])): ?>
                      <div class="org-team-children-wrap">
                        <div class="org-team-children-row">
                          <?php foreach ($team['children'] as $child): ?>
                            <div class="org-subteam-col" data-team-name="<?= h((string)($child['name'] ?? '')) ?>">
                              <div class="org-team-card org-team-card-child">
                                <div class="org-team-head"><?= h($child['name']) ?></div>
                                <div class="org-team-body">
                                  <?php if (!empty($child['managers'])): ?>
                                    <div class="org-role-sec">
                                      <div class="org-role-lbl role-manager">관리감독자</div>
                                      <?php foreach ($child['managers'] as $member): ?>
                                        <?= $orgNameHtml($member) ?>
                                      <?php endforeach; ?>
                                    </div>
                                  <?php endif; ?>
                                  <?php if (!empty($child['leaders'])): ?>
                                    <div class="org-role-sec">
                                      <div class="org-role-lbl role-leader">작업지휘자</div>
                                      <?php foreach ($child['leaders'] as $member): ?>
                                        <?= $orgNameHtml($member) ?>
                                      <?php endforeach; ?>
                                    </div>
                                  <?php endif; ?>
                                  <?php if (!empty($child['workers'])): ?>
                                    <div class="org-role-sec">
                                      <div class="org-role-lbl role-worker">일반작업자</div>
                                      <?php foreach ($child['workers'] as $member): ?>
                                        <?= $orgNameHtml($member) ?>
                                      <?php endforeach; ?>
                                    </div>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>
  </div>
  <script>
    (() => {
      const filterInputs = Array.from(document.querySelectorAll('.js-team-filter'));
      const selectAllBtn = document.getElementById('team-select-all');
      const clearAllBtn = document.getElementById('team-clear-all');
      const printBtn = document.getElementById('print-preview-btn');
      const openMergeModalBtn = document.getElementById('open-merge-team-modal');
      const resetMergeBtn = document.getElementById('reset-merge-team');
      const mergeModal = document.getElementById('merge-team-modal');
      const mergeModalClose = document.getElementById('merge-team-modal-close');
      const mergeModalCancel = document.getElementById('merge-team-cancel');
      const mergeModalApplyPrint = document.getElementById('merge-team-apply-print');
      const mergeModalSelectAll = document.getElementById('merge-team-select-all');
      const mergeModalClearAll = document.getElementById('merge-team-clear-all');
      const mergeBaseTeam = document.getElementById('merge-base-team');
      const mergeOptionInputs = Array.from(document.querySelectorAll('.js-merge-team-option'));
      const topTeamCols = Array.from(document.querySelectorAll('.org-team-col[data-team-name]'));
      const allTeamContainers = Array.from(document.querySelectorAll('.org-team-col[data-team-name], .org-subteam-col[data-team-name]'));
      const hasTeamFilterTargets = filterInputs.length > 0 && topTeamCols.length > 0;

      // 미리보기 / 병합 상태 관련 요소
      const previewBar = document.getElementById('preview-bar');
      const previewDoPrintBtn = document.getElementById('preview-do-print-btn');
      const previewCloseBtn = document.getElementById('preview-close-btn');
      const mergeStatusBar = document.getElementById('merge-status-bar');
      const mergeStatusText = document.getElementById('merge-status-text');
      const mergeStatusResetBtn = document.getElementById('merge-status-reset-btn');

      let mergeConfig = null;

      const normalize = (value) => String(value || '').trim().toLowerCase();

      const roleMeta = {
        managers: { label: '관리감독자', className: 'role-manager' },
        leaders: { label: '작업지휘자', className: 'role-leader' },
        workers: { label: '일반작업자', className: 'role-worker' },
      };

      const teamContainerMap = new Map();
      const teamOriginalState = new Map();

      function roleKeyFromLabel(label) {
        const text = String(label || '').trim();
        if (text === '관리감독자') return 'managers';
        if (text === '작업지휘자') return 'leaders';
        if (text === '일반작업자') return 'workers';
        return '';
      }

      function parseMembersFromHtml(html) {
        const container = document.createElement('div');
        container.innerHTML = String(html || '');
        const result = { managers: [], leaders: [], workers: [] };

        container.querySelectorAll('.org-role-sec').forEach((sec) => {
          const labelNode = sec.querySelector('.org-role-lbl');
          const roleKey = roleKeyFromLabel(labelNode ? labelNode.textContent : '');
          if (!roleKey) {
            return;
          }

          sec.querySelectorAll('.org-member-name').forEach((nameNode) => {
            const name = String(nameNode.textContent || '').trim();
            if (name !== '') {
              result[roleKey].push(name);
            }
          });
        });

        return result;
      }

      function dedupeMembers(list) {
        const seen = new Set();
        const result = [];
        list.forEach((name) => {
          const key = normalize(name);
          if (!key || seen.has(key)) {
            return;
          }
          seen.add(key);
          result.push(name);
        });
        return result;
      }

      function renderMembersIntoBody(body, members) {
        if (!body) {
          return;
        }

        const sections = [];
        ['managers', 'leaders', 'workers'].forEach((roleKey) => {
          const names = Array.isArray(members[roleKey]) ? members[roleKey] : [];
          if (names.length === 0) {
            return;
          }

          const meta = roleMeta[roleKey];
          const namesHtml = names
            .map((name) => `<span class="org-member-name">${name.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span>`)
            .join('');

          sections.push(`
            <div class="org-role-sec">
              <div class="org-role-lbl ${meta.className}">${meta.label}</div>
              ${namesHtml}
            </div>
          `);
        });

        body.innerHTML = sections.join('');
      }

      function refreshChildrenWrapVisibility() {
        topTeamCols.forEach((topCol) => {
          const childrenWrap = topCol.querySelector('.org-team-children-wrap');
          if (!childrenWrap) {
            return;
          }

          const childCols = Array.from(topCol.querySelectorAll('.org-subteam-col[data-team-name]'));
          const hasVisibleChild = childCols.some((childCol) => childCol.style.display !== 'none');
          childrenWrap.style.display = hasVisibleChild ? '' : 'none';
        });
      }

      function equalizeTamCardWidths() {
        const surface = document.querySelector('.org-chart-surface');
        if (!surface) return;

        const visibleCols = Array.from(
          document.querySelectorAll('.org-team-col[data-team-name]')
        ).filter((col) => col.style.display !== 'none');
        if (visibleCols.length === 0) return;

        const cards = visibleCols
          .map((col) => col.querySelector(':scope > .org-team-card'))
          .filter(Boolean);

        // 자식 팀 컨테이너 너비에 영향받지 않도록 카드 자체에 max-content 부여 후 측정
        cards.forEach((card) => { card.style.width = 'max-content'; });

        const maxW = Math.max(...cards.map((card) => card.offsetWidth));

        cards.forEach((card) => { card.style.width = ''; });

        if (maxW > 0) {
          surface.style.setProperty('--org-team-top-width', maxW + 'px');
        } else {
          surface.style.removeProperty('--org-team-top-width');
        }
      }

      allTeamContainers.forEach((container) => {
        const rawName = String(container.getAttribute('data-team-name') || '').trim();
        const teamKey = normalize(rawName);
        const body = container.querySelector('.org-team-card .org-team-body');
        if (!teamKey || !body || teamContainerMap.has(teamKey)) {
          return;
        }

        teamContainerMap.set(teamKey, container);
        teamOriginalState.set(teamKey, {
          rawName,
          display: container.style.display,
          bodyHtml: body.innerHTML,
        });
      });

      function selectedTeamSet() {
        if (!hasTeamFilterTargets) {
          return new Set();
        }
        return new Set(
          filterInputs
            .filter((input) => input.checked)
            .map((input) => normalize(input.value))
        );
      }

      function applyTeamFilter() {
        if (!hasTeamFilterTargets) {
          return;
        }
        const selected = selectedTeamSet();

        topTeamCols.forEach((topCol) => {
          const topName = normalize(topCol.getAttribute('data-team-name'));
          const childCols = Array.from(topCol.querySelectorAll('.org-subteam-col[data-team-name]'));

          let visibleChildCount = 0;
          childCols.forEach((childCol) => {
            const childName = normalize(childCol.getAttribute('data-team-name'));
            const visible = selected.has(childName);
            childCol.style.display = visible ? '' : 'none';
            if (visible) {
              visibleChildCount += 1;
            }
          });

          const childrenWrap = topCol.querySelector('.org-team-children-wrap');
          if (childrenWrap) {
            childrenWrap.style.display = visibleChildCount > 0 ? '' : 'none';
          }

          const showTop = selected.has(topName) || visibleChildCount > 0;
          topCol.style.display = showTop ? '' : 'none';
        });
        equalizeTamCardWidths();
      }

      function openMergeModal() {
        if (!mergeModal) {
          return;
        }

        const selected = selectedTeamSet();
        const selectedFallback = selected.size > 0
          ? Array.from(selected)
          : mergeOptionInputs.map((input) => normalize(input.value));

        mergeOptionInputs.forEach((input) => {
          input.checked = selectedFallback.includes(normalize(input.value));
        });

        if (mergeBaseTeam) {
          const currentBaseKey = normalize(mergeBaseTeam.value);
          if (!currentBaseKey || !selectedFallback.includes(currentBaseKey)) {
            const first = selectedFallback[0] || '';
            const matched = Array.from(mergeBaseTeam.options).find((opt) => normalize(opt.value) === first);
            if (matched) {
              mergeBaseTeam.value = matched.value;
            }
          }
        }

        mergeModal.classList.add('is-open');
        mergeModal.setAttribute('aria-hidden', 'false');
      }

      function closeMergeModal() {
        if (!mergeModal) {
          return;
        }
        mergeModal.classList.remove('is-open');
        mergeModal.setAttribute('aria-hidden', 'true');
      }

      function selectedMergeTeamSet() {
        return new Set(
          mergeOptionInputs
            .filter((input) => input.checked)
            .map((input) => normalize(input.value))
        );
      }

      function collectTeamMembers(teamKey) {
        const state = teamOriginalState.get(teamKey);
        if (!state) {
          return { managers: [], leaders: [], workers: [] };
        }
        return parseMembersFromHtml(state.bodyHtml);
      }

      function clearPrintMergeScope() {
        if (teamOriginalState.size === 0) {
          return;
        }
        teamOriginalState.forEach((state, teamKey) => {
          const container = teamContainerMap.get(teamKey);
          if (!container) {
            return;
          }

          const body = container.querySelector('.org-team-card .org-team-body');
          container.classList.remove('print-hide');
          container.style.display = state.display;
          if (body) {
            body.innerHTML = state.bodyHtml;
          }
        });
        refreshChildrenWrapVisibility();
      }

      function applyMergedPreview(baseKey, selectedTeamKeys) {
        if (!baseKey || teamOriginalState.size === 0) {
          return;
        }

        const baseContainer = teamContainerMap.get(baseKey);
        if (!baseContainer) {
          return;
        }

        const merged = collectTeamMembers(baseKey);
        selectedTeamKeys.forEach((teamKey) => {
          if (teamKey === baseKey) {
            return;
          }

          const sourceMembers = collectTeamMembers(teamKey);
          merged.managers = merged.managers.concat(sourceMembers.managers);
          merged.leaders = merged.leaders.concat(sourceMembers.leaders);
          merged.workers = merged.workers.concat(sourceMembers.workers);

          const sourceContainer = teamContainerMap.get(teamKey);
          if (sourceContainer) {
            sourceContainer.style.display = 'none';
          }
        });

        merged.managers = dedupeMembers(merged.managers);
        merged.leaders = dedupeMembers(merged.leaders);
        merged.workers = dedupeMembers(merged.workers);

        baseContainer.style.display = '';
        renderMembersIntoBody(baseContainer.querySelector('.org-team-card .org-team-body'), merged);
        refreshChildrenWrapVisibility();
      }

      // ── 병합 상태 표시 ──────────────────────────────────────
      function updateMergeStatus() {
        if (!mergeStatusBar || !mergeStatusText) {
          return;
        }
        if (mergeConfig && mergeConfig.baseKey && Array.isArray(mergeConfig.selected) && mergeConfig.selected.length > 1) {
          const others = mergeConfig.selected.filter((k) => k !== mergeConfig.baseKey);
          mergeStatusText.textContent = `${mergeConfig.baseKey} + ${others.join(', ')}`;
          mergeStatusBar.style.display = 'flex';
        } else {
          mergeStatusBar.style.display = 'none';
        }
      }

      // ── 인쇄 콘텐츠 준비 (필터·병합 적용) ──────────────────
      function preparePrintContent() {
        clearPrintMergeScope();
        applyTeamFilter();
        if (mergeConfig && mergeConfig.baseKey && Array.isArray(mergeConfig.selected) && mergeConfig.selected.length > 0) {
          applyMergedPreview(mergeConfig.baseKey, new Set(mergeConfig.selected));
          equalizeTamCardWidths();
        }
      }

      // ── 미리보기 열기 / 닫기 ────────────────────────────────
      function openPreview() {
        preparePrintContent();
        document.body.classList.add('is-preview');
        window.scrollTo(0, 0);
      }

      function closePreview() {
        document.body.classList.remove('is-preview');
        // 닫은 뒤 병합 상태 복원
        clearPrintMergeScope();
        applyTeamFilter();
        if (mergeConfig && mergeConfig.baseKey && Array.isArray(mergeConfig.selected) && mergeConfig.selected.length > 0) {
          applyMergedPreview(mergeConfig.baseKey, new Set(mergeConfig.selected));
          equalizeTamCardWidths();
        }
      }

      if (previewDoPrintBtn) {
        previewDoPrintBtn.addEventListener('click', () => {
          // 두 번의 rAF로 브라우저 렌더링 완료 후 인쇄 다이얼로그 열기
          requestAnimationFrame(() => requestAnimationFrame(() => window.print()));
        });
      }

      if (previewCloseBtn) {
        previewCloseBtn.addEventListener('click', closePreview);
      }

      if (mergeStatusResetBtn) {
        mergeStatusResetBtn.addEventListener('click', () => {
          mergeConfig = null;
          clearPrintMergeScope();
          applyTeamFilter();
          updateMergeStatus();
        });
      }
      // ────────────────────────────────────────────────────────

      if (hasTeamFilterTargets) {
        filterInputs.forEach((input) => {
          input.addEventListener('change', applyTeamFilter);
        });
      }

      if (selectAllBtn && hasTeamFilterTargets) {
        selectAllBtn.addEventListener('click', () => {
          filterInputs.forEach((input) => {
            input.checked = true;
          });
          applyTeamFilter();
        });
      }

      if (clearAllBtn && hasTeamFilterTargets) {
        clearAllBtn.addEventListener('click', () => {
          filterInputs.forEach((input) => {
            input.checked = false;
          });
          applyTeamFilter();
        });
      }

      if (openMergeModalBtn) {
        openMergeModalBtn.addEventListener('click', openMergeModal);
      }

      if (resetMergeBtn) {
        resetMergeBtn.addEventListener('click', () => {
          mergeConfig = null;
          clearPrintMergeScope();
          applyTeamFilter();
          updateMergeStatus();
        });
      }


      if (printBtn) {
        printBtn.addEventListener('click', openPreview);
      }

      if (mergeModalClose) {
        mergeModalClose.addEventListener('click', closeMergeModal);
      }
      if (mergeModalCancel) {
        mergeModalCancel.addEventListener('click', closeMergeModal);
      }
      if (mergeModal) {
        mergeModal.addEventListener('click', (event) => {
          if (event.target === mergeModal) {
            closeMergeModal();
          }
        });
      }
      if (mergeModalSelectAll) {
        mergeModalSelectAll.addEventListener('click', () => {
          mergeOptionInputs.forEach((input) => {
            input.checked = true;
          });
        });
      }
      if (mergeModalClearAll) {
        mergeModalClearAll.addEventListener('click', () => {
          mergeOptionInputs.forEach((input) => {
            input.checked = false;
          });
        });
      }
      if (mergeModalApplyPrint) {
        mergeModalApplyPrint.addEventListener('click', () => {
          const baseKey = normalize(mergeBaseTeam ? mergeBaseTeam.value : '');
          const selected = selectedMergeTeamSet();
          if (selected.size === 0) {
            window.alert('병합할 팀을 1개 이상 선택해 주세요.');
            return;
          }
          if (!baseKey) {
            window.alert('기준 팀을 선택해 주세요.');
            return;
          }

          selected.add(baseKey);
          mergeConfig = {
            baseKey,
            selected: Array.from(selected),
          };
          closeMergeModal();

          clearPrintMergeScope();
          applyTeamFilter();
          applyMergedPreview(mergeConfig.baseKey, new Set(mergeConfig.selected));
          equalizeTamCardWidths();
          updateMergeStatus();
        });
      }

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          if (mergeModal && mergeModal.classList.contains('is-open')) {
            closeMergeModal();
          } else if (document.body.classList.contains('is-preview')) {
            closePreview();
          }
        }
      });

      window.addEventListener('afterprint', () => {
        mergeConfig = null;
        clearPrintMergeScope();
        applyTeamFilter();
        updateMergeStatus();
      });

      applyTeamFilter();
    })();

    (() => {
      const table = document.querySelector('.org-table-form');
      const addBtn = document.getElementById('org-table-row-add');
      if (!table || !addBtn) {
        return;
      }
      addBtn.addEventListener('click', () => {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="row_role[]"></td>'
          + '<td><input type="text" name="row_name[]"></td>'
          + '<td><input type="text" name="row_department[]"></td>'
          + '<td><input type="text" name="row_note[]"></td>'
          + '<td><button type="button" class="btn-ghost org-table-row-remove">삭제</button></td>';
        table.appendChild(tr);
      });
      table.addEventListener('click', (event) => {
        if (!event.target.classList.contains('org-table-row-remove')) {
          return;
        }
        if (table.rows.length > 2) {
          event.target.closest('tr').remove();
        }
      });
    })();

    (() => {
      document.querySelectorAll('.org-member-row-add').forEach((addBtn) => {
        addBtn.addEventListener('click', () => {
          const table = document.getElementById(addBtn.dataset.target);
          if (!table) {
            return;
          }
          const tr = document.createElement('tr');
          tr.innerHTML = '<td><input type="text" name="' + addBtn.dataset.roleName + '"></td>'
            + '<td><input type="text" name="' + addBtn.dataset.nameName + '"></td>'
            + '<td class="org-box-member-remove-cell"><button type="button" class="btn-ghost org-member-row-remove">삭제</button></td>';
          table.appendChild(tr);
        });
      });
      document.querySelectorAll('.org-box-member-table').forEach((table) => {
        table.addEventListener('click', (event) => {
          if (!event.target.classList.contains('org-member-row-remove')) {
            return;
          }
          if (table.rows.length > 1) {
            event.target.closest('tr').remove();
          }
        });
      });
    })();
  </script>
</body>
</html>
