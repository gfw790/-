<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
$canManageAccounts = auth_can_manage($user);
$canManageTeams = auth_is_admin($user);
$canAssignRoles = auth_is_admin($user);
$registrationOpen = auth_is_worker_registration_open();
if ($user !== null && !$canManageAccounts) {
    header('Location: work_list.php');
    exit;
}
if ($user === null && !$registrationOpen) {
    header('Location: task_select.php');
    exit;
}

$error = '';
$success = '';
$form = [
    'name' => '',
    'login_id' => '',
    'team' => '',
    'role' => 'worker',
    'phone' => '',
];
$teamForm = [
    'team_name' => '',
    'team_supervisor' => '',
    'rename_team_name' => '',
    'rename_team_target' => '',
];
$teams = auth_read_teams();
if ($form['team'] === '' && !empty($teams)) {
    $form['team'] = (string)$teams[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'register'));

    if ($action === 'delete_account') {
        if (!$canManageAccounts) {
            $error = '계정 삭제 권한이 없습니다.';
        } else {
            $deleteLoginId = trim((string)($_POST['delete_login_id'] ?? ''));
            if ($deleteLoginId === (string)($user['login_id'] ?? '')) {
                $error = '현재 로그인한 계정은 삭제할 수 없습니다.';
            } else {
                [$ok, $message] = auth_delete_stored_account($deleteLoginId);
                if ($ok) {
                    $success = $message;
                } else {
                    $error = $message;
                }
            }
        }
    } elseif ($action === 'update_account_phone') {
        if (!$canManageAccounts) {
            $error = '전화번호를 변경할 권한이 없습니다.';
        } else {
            $updateLoginId = trim((string)($_POST['update_login_id'] ?? ''));
            $updatePhone   = trim((string)($_POST['update_phone'] ?? ''));
            [$ok, $message] = auth_update_account_phone($updateLoginId, $updatePhone);
            if ($ok) {
                $success = $message;
            } else {
                $error = $message;
            }
        }
    } elseif ($action === 'update_account_role') {
        if (!$canAssignRoles) {
            $error = '역할을 변경할 권한이 없습니다.';
        } else {
            $updateLoginId = trim((string)($_POST['update_login_id'] ?? ''));
            $updateRole = trim((string)($_POST['update_role'] ?? ''));
            if ($updateLoginId === (string)($user['login_id'] ?? '')) {
                $error = '현재 로그인한 계정의 역할은 변경할 수 없습니다.';
            } else {
                [$ok, $message] = auth_update_stored_account_role($updateLoginId, $updateRole);
                if ($ok) {
                    $success = $message;
                } else {
                    $error = $message;
                }
            }
        }
    } elseif ($action === 'toggle_registration') {
        if (!$canManageAccounts) {
            $error = '회원가입 상태를 변경할 권한이 없습니다.';
        } else {
            $shouldOpen = (string)($_POST['registration_state'] ?? 'closed') === 'open';
            if (auth_set_worker_registration_open($shouldOpen)) {
                $registrationOpen = $shouldOpen;
                $success = $shouldOpen ? '회원가입이 임시로 열렸습니다.' : '회원가입이 다시 닫혔습니다.';
            } else {
                $error = '회원가입 상태를 저장하지 못했습니다.';
            }
        }
    } elseif ($action === 'create_team') {
        $teamForm['team_name'] = trim((string)($_POST['team_name'] ?? ''));
        $teamForm['team_supervisor'] = auth_normalize_team_name((string)($_POST['team_supervisor'] ?? ''));
        if (!$canManageTeams) {
            $error = '팀을 추가할 권한이 없습니다.';
        } else {
            [$ok, $message] = auth_add_team($teamForm['team_name'], $teamForm['team_supervisor']);
            if ($ok) {
                $success = $message;
              $teamForm = [
                'team_name' => '',
                'team_supervisor' => '',
                'rename_team_name' => '',
                'rename_team_target' => '',
              ];
                $teams = auth_read_teams();
                if ($form['team'] === '' && !empty($teams)) {
                    $form['team'] = (string)$teams[0];
                }
            } else {
                $error = $message;
            }
        }
    } elseif ($action === 'set_team_supervisor') {
        $teamName = auth_normalize_team_name((string)($_POST['team_name'] ?? ''));
        $supervisorTeam = auth_normalize_team_name((string)($_POST['team_supervisor'] ?? ''));
        if (!$canManageTeams) {
            $error = '관리감독팀을 지정할 권한이 없습니다.';
        } elseif ($teamName === '') {
            $error = '팀을 선택해주세요.';
        } else {
            if ($supervisorTeam !== '' && !auth_team_exists($supervisorTeam)) {
                $error = '유효한 관리감독팀을 선택해주세요.';
            } elseif ($supervisorTeam !== '' && auth_would_create_supervisor_cycle($teamName, $supervisorTeam)) {
                $error = '관리감독팀 연결에 순환이 생겨 저장할 수 없습니다.';
            } elseif (auth_set_team_supervisor($teamName, $supervisorTeam)) {
                $success = '관리감독팀 정보가 저장되었습니다.';
            } else {
                $error = '관리감독팀 정보를 저장하지 못했습니다.';
            }
        }
    } elseif ($action === 'rename_team') {
        $teamForm['rename_team_name'] = auth_normalize_team_name((string)($_POST['rename_team_name'] ?? ''));
        $teamForm['rename_team_target'] = trim((string)($_POST['rename_team_target'] ?? ''));
        if (!$canManageTeams) {
            $error = '팀 이름을 수정할 권한이 없습니다.';
        } else {
            $previousTeamName = $teamForm['rename_team_name'];
            $renamedTeamName = auth_normalize_team_name($teamForm['rename_team_target']);
            [$ok, $message] = auth_rename_team($previousTeamName, $teamForm['rename_team_target']);
            if ($ok) {
                $success = $message;
                $teams = auth_read_teams();
                $teamForm['rename_team_name'] = '';
                $teamForm['rename_team_target'] = '';
                if ($form['team'] !== '' && auth_team_key($form['team']) === auth_team_key($previousTeamName)) {
                    $form['team'] = $renamedTeamName;
                } elseif ($form['team'] !== '' && !auth_team_exists($form['team'])) {
                    $form['team'] = !empty($teams) ? (string)$teams[0] : '';
                }
            } else {
                $error = $message;
            }
        }
    } elseif ($action === 'delete_team') {
        $deleteTeamName = auth_normalize_team_name((string)($_POST['delete_team_name'] ?? ''));
        if (!$canManageTeams) {
            $error = '팀을 삭제할 권한이 없습니다.';
        } else {
            [$ok, $message] = auth_delete_team($deleteTeamName);
            if ($ok) {
                $success = $message;
                $teams = auth_read_teams();
                if ($form['team'] === $deleteTeamName || !auth_team_exists($form['team'])) {
                    $form['team'] = !empty($teams) ? (string)$teams[0] : '';
                }
            } else {
                $error = $message;
            }
        }
    } else {
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['login_id'] = trim((string)($_POST['login_id'] ?? ''));
              $form['team'] = auth_normalize_team_name((string)($_POST['team'] ?? ''));
        $form['role'] = $canAssignRoles ? trim((string)($_POST['role'] ?? 'worker')) : 'worker';
        $form['phone'] = trim((string)($_POST['phone'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));
        $passwordConfirm = trim((string)($_POST['password_confirm'] ?? ''));

        if ($password !== $passwordConfirm) {
            $error = '비밀번호와 비밀번호 확인이 일치하지 않습니다.';
        } else {
            [$ok, $message] = auth_register_worker($form['login_id'], $password, $form['name'], $form['team'], $form['role'], $form['phone']);
            if ($ok) {
                $success = $message;
                $teams = auth_read_teams();
                $form = ['name' => '', 'login_id' => '', 'team' => !empty($teams) ? (string)$teams[0] : '', 'role' => 'worker', 'phone' => ''];
            } else {
                $error = $message;
            }
        }
    }
}

$storedAccounts = auth_read_stored_accounts();
uksort($storedAccounts, static fn(string $left, string $right): int => strnatcasecmp($left, $right));
$teams = auth_read_teams();
$roleOptions = auth_allowed_roles();
$teamCounts = auth_team_member_counts();
foreach ($teams as $teamName) {
    if (!isset($teamCounts[$teamName])) {
        $teamCounts[$teamName] = 0;
    }
}
$unassignedStoredAccountGroupLabel = '팀 미지정';
$storedAccountGroups = [];
$unassignedStoredAccounts = [];
foreach ($storedAccounts as $loginId => $account) {
    $accountTeam = auth_normalize_team_name((string)($account['team'] ?? ''));
    if ($accountTeam === '') {
        $unassignedStoredAccounts[$loginId] = $account;
        continue;
    }

    if (!isset($storedAccountGroups[$accountTeam])) {
        $storedAccountGroups[$accountTeam] = [];
    }
    $storedAccountGroups[$accountTeam][$loginId] = $account;
}

$orderedStoredAccountGroups = [];
foreach ($teams as $teamName) {
    $normalizedTeamName = auth_normalize_team_name((string)$teamName);
    if ($normalizedTeamName === '' || !isset($storedAccountGroups[$normalizedTeamName])) {
        continue;
    }

    $orderedStoredAccountGroups[$normalizedTeamName] = $storedAccountGroups[$normalizedTeamName];
    unset($storedAccountGroups[$normalizedTeamName]);
}

if (!empty($storedAccountGroups)) {
    uksort($storedAccountGroups, static fn(string $left, string $right): int => strnatcasecmp($left, $right));
    foreach ($storedAccountGroups as $teamName => $accounts) {
        $orderedStoredAccountGroups[$teamName] = $accounts;
    }
}

if (!empty($unassignedStoredAccounts)) {
    $orderedStoredAccountGroups[$unassignedStoredAccountGroupLabel] = $unassignedStoredAccounts;
}

if ($form['team'] === '' && !empty($teams)) {
    $form['team'] = (string)$teams[0];
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>회원가입</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background:
      radial-gradient(circle at top left, rgba(69, 123, 157, 0.16), transparent 34%),
      linear-gradient(180deg, #eff5fb 0%, #f7fafc 100%);
    min-height: 100vh;
    color: #243447;
    padding: 28px 16px 40px;
  }
  .shell {
    max-width: 900px;
    margin: 0 auto;
  }
  .panel {
    background: rgba(255,255,255,0.96);
    border: 1px solid #d7e3ef;
    border-radius: 20px;
    box-shadow: 0 16px 40px rgba(18, 52, 77, 0.08);
    overflow: hidden;
  }
  .panel-head {
    padding: 24px 24px 12px;
  }
  .panel-head-bar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .panel-head h1 {
    font-size: 28px;
    color: #12344d;
    margin-bottom: 10px;
  }
  .panel-head p {
    color: #6b7c93;
    font-size: 14px;
    line-height: 1.6;
  }
  .panel-body {
    padding: 20px 24px 24px;
  }
  .field {
    margin-bottom: 14px;
  }
  label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #12344d;
  }
  select,
  input[type="text"],
  input[type="password"] {
    width: 100%;
    border: 1px solid #c8d8e8;
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #243447;
    background: #fff;
  }
  select {
    appearance: none;
  }
  .helper {
    margin-top: 6px;
    color: #6b7c93;
    font-size: 12px;
    line-height: 1.5;
  }
  .error,
  .success {
    border-radius: 12px;
    padding: 13px 14px;
    font-size: 14px;
    margin-bottom: 14px;
  }
  .error {
    background: #fff1f1;
    border: 1px solid #efc3c3;
    color: #a33a3a;
  }
  .success {
    background: #eef8f0;
    border: 1px solid #bfe1c5;
    color: #22643a;
  }
  .btn-primary,
  .btn-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 12px;
    cursor: pointer;
    padding: 12px 18px;
    font-size: 14px;
    font-family: inherit;
  }
  .btn-primary {
    width: 100%;
    border: none;
    background: #2e75b6;
    color: #fff;
  }
  .btn-primary:hover {
    background: #1f4e79;
  }
  .btn-danger {
    width: 100%;
    border: 1px solid #d8a9a9;
    background: #fff4f4;
    color: #a33a3a;
  }
  .btn-danger:hover {
    background: #ffe8e8;
  }
  .btn-secondary {
    width: 100%;
    margin-top: 10px;
    background: #fff;
    color: #486581;
    border: 1px solid #c8d8e8;
  }
  .btn-secondary:hover {
    background: #f3f8fc;
  }
  .btn-head-back {
    width: auto;
    margin-top: 0;
    white-space: nowrap;
  }
  .grid {
    display: grid;
    grid-template-columns: minmax(0, 360px) minmax(0, 1fr);
    gap: 18px;
  }
  .account-panel {
    border: 1px solid #d7e3ef;
    border-radius: 16px;
    background: #f8fbfe;
    padding: 18px;
  }
  .account-panel h2 {
    font-size: 20px;
    color: #12344d;
    margin-bottom: 8px;
  }
  .account-panel p {
    color: #6b7c93;
    font-size: 13px;
    line-height: 1.6;
    margin-bottom: 14px;
  }
  .account-list {
    display: grid;
    gap: 10px;
  }
  .account-group-list {
    display: grid;
    gap: 14px;
  }
  .account-group {
    border: 1px solid #d7e3ef;
    border-radius: 16px;
    background: #fff;
    padding: 14px;
  }
  .account-group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #edf2f7;
    list-style: none;
    cursor: pointer;
  }
  .account-group-head::-webkit-details-marker {
    display: none;
  }
  .account-group-title {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .account-group-title h3 {
    font-size: 16px;
    color: #12344d;
  }
  .account-group-count {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    background: #edf7ef;
    color: #2f855a;
    font-size: 12px;
    font-weight: 700;
  }
  .account-group-note {
    color: #6b7c93;
    font-size: 12px;
  }
  .account-group-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-left: auto;
  }
  .account-group-toggle {
    display: inline-flex;
    align-items: center;
    padding: 6px 11px;
    border-radius: 999px;
    border: 1px solid #c8d8e8;
    background: #f7fbff;
    color: #486581;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .account-group-toggle-open,
  .account-group-toggle-closed {
    display: none;
  }
  .account-group[open] .account-group-toggle-open {
    display: inline;
  }
  .account-group:not([open]) .account-group-toggle-closed {
    display: inline;
  }
  .account-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(180px, 240px);
    gap: 10px;
    align-items: center;
    border: 1px solid #d7e3ef;
    border-radius: 14px;
    background: #fff;
    padding: 12px 14px;
  }
  .account-item-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: stretch;
  }
  .account-item-actions form {
    margin: 0;
  }
  .account-role-form {
    display: grid;
    gap: 8px;
  }
  .account-role-form select {
    width: 100%;
  }
  .btn-role-save {
    margin-top: 0;
  }
  .account-name {
    font-size: 15px;
    font-weight: 700;
    color: #12344d;
  }
  .account-meta {
    margin-top: 4px;
    color: #6b7c93;
    font-size: 13px;
    line-height: 1.5;
  }
  .role-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    background: #e7f1fb;
    color: #2e75b6;
    font-size: 11px;
    font-weight: 700;
    margin-right: 6px;
  }
  .team-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    background: #edf7ef;
    color: #2f855a;
    font-size: 11px;
    font-weight: 700;
    margin-right: 6px;
  }
  .empty-box {
    border: 1px dashed #c8d8e8;
    border-radius: 14px;
    padding: 18px 14px;
    color: #6b7c93;
    font-size: 13px;
    background: #fff;
    text-align: center;
  }
  .status-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 1px solid #d7e3ef;
    border-radius: 14px;
    padding: 12px 14px;
    background: #fff;
    margin-bottom: 14px;
  }
  .status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
  }
  .status-badge.is-open {
    background: #eaf7ec;
    color: #22643a;
  }
  .status-badge.is-closed {
    background: #fff4e8;
    color: #9a5a12;
  }
  .btn-inline {
    width: auto;
    margin-top: 0;
    white-space: nowrap;
  }
  .team-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 10px;
    margin-top: 14px;
  }
  .team-pill {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    border-radius: 16px;
    border: 1px solid #d7e3ef;
    background: #fff;
    color: #12344d;
    font-size: 13px;
    font-weight: 400;
  }
  .team-pill-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    font-weight: 700;
  }
  .team-pill-supervisor {
    color: #49637a;
    font-size: 12px;
    padding: 6px 10px;
    border-radius: 12px;
    background: #f5f8fb;
  }
  .team-pill form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin: 0;
    width: 100%;
  }
  .team-pill form select {
    flex: 1;
    min-width: 0;
  }
  .btn-team-delete {
    width: auto;
    margin-top: 0;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid #d8a9a9;
    background: #fff4f4;
    color: #a33a3a;
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
  }
  .btn-team-delete:hover:not(:disabled) {
    background: #ffe8e8;
  }
  .btn-team-delete:disabled {
    border-color: #d7e3ef;
    background: #f5f7fa;
    color: #9aa7b5;
    cursor: not-allowed;
  }
  .phone-form {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-top: 7px;
  }
  .phone-input {
    flex: 1;
    padding: 6px 10px;
    font-size: 12px;
    border-radius: 8px;
    border: 1px solid #c8d8e8;
    background: #fff;
    color: #243447;
    font-family: inherit;
    min-width: 0;
  }
  .phone-save-btn {
    flex-shrink: 0;
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid #c8d8e8;
    background: #f3f8fc;
    color: #486581;
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
    white-space: nowrap;
  }
  .phone-save-btn:hover { background: #e3f0fb; }
  .account-phone-display {
    margin-top: 5px;
    font-size: 12px;
    color: #486581;
  }
  @media (max-width: 860px) {
    .grid {
      grid-template-columns: 1fr;
    }
  }
  @media (max-width: 640px) {
    .account-item {
      grid-template-columns: 1fr;
    }
  }
</style>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
</head>
<body>
  <div class="shell">
    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-bar">
          <div>
            <h1><?= $canManageAccounts ? '계정 관리' : '회원가입' ?></h1>
            <p><?= $canAssignRoles ? '관리자는 계정을 추가하고, 저장된 계정의 역할을 변경하거나 삭제할 수 있습니다.' : ($canManageAccounts ? '계정을 추가하고, 저장된 계정을 직접 삭제할 수 있습니다.' : '일반작업자 계정을 직접 생성할 수 있습니다. 가입 후 로그인하면 바로 작업목록으로 이동할 수 있습니다.') ?></p>
          </div>
          <?php if ($canManageAccounts): ?>
            <a class="btn-secondary btn-head-back" href="work_list.php">뒤로가기</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="panel-body">
        <?php if ($error !== ''): ?>
          <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
          <div class="success"><?= h($success) ?></div>
        <?php endif; ?>
        <div class="grid">
          <div class="account-panel">
            <h2><?= $canManageAccounts ? '계정 추가' : '회원가입' ?></h2>
            <p><?= $canAssignRoles ? '관리자는 계정을 추가하면서 역할까지 함께 지정할 수 있습니다.' : '현재 화면에서는 일반작업자 계정을 새로 등록하고 소속 팀을 함께 지정할 수 있습니다.' ?></p>
            <form method="post">
              <input type="hidden" name="action" value="register">
              <div class="field">
                <label for="name">이름</label>
                <input type="text" id="name" name="name" value="<?= h($form['name']) ?>" placeholder="예: 홍길동" required>
              </div>
              <div class="field">
                <label for="login_id">아이디</label>
                <input type="text" id="login_id" name="login_id" value="<?= h($form['login_id']) ?>" placeholder="예: worker02" required>
                <div class="helper">아이디는 영문, 숫자, -, _ 만 사용할 수 있습니다.</div>
              </div>
              <?php if ($canAssignRoles): ?>
                <div class="field">
                  <label for="role">역할</label>
                  <select id="role" name="role" required onchange="onRoleChange(this.value)">
                    <?php foreach ($roleOptions as $roleOption): ?>
                      <option value="<?= h($roleOption) ?>" <?= $form['role'] === $roleOption ? 'selected' : '' ?>><?= h(auth_role_label($roleOption)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
              <input type="hidden" name="team" value="">
              <div class="field" id="field-team">
                <label for="team">소속 팀</label>
                <select id="team" name="team">
                  <option value="">팀 미지정</option>
                  <?php foreach ($teams as $teamName): ?>
                    <option value="<?= h($teamName) ?>" <?= $form['team'] === $teamName ? 'selected' : '' ?>><?= h($teamName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="phone">전화번호 <span style="font-weight:400;color:#6b7c93;">(선택)</span></label>
                <input type="text" id="phone" name="phone" value="<?= h($form['phone']) ?>" placeholder="예: 010-1234-5678" maxlength="20">
              </div>
              <div class="field">
                <label for="password">비밀번호</label>
                <input type="password" id="password" name="password" placeholder="비밀번호 입력" required>
              </div>
              <div class="field">
                <label for="password_confirm">비밀번호 확인</label>
                <input type="password" id="password_confirm" name="password_confirm" placeholder="비밀번호 다시 입력" required>
              </div>
              <button class="btn-primary" type="submit"><?= $canManageAccounts ? '계정 추가' : '회원가입' ?></button>
            </form>

            <?php if (!$canManageAccounts): ?>
              <a class="btn-secondary" href="<?= $user !== null ? 'work_list.php' : 'task_select.php' ?>"><?= $user !== null ? '작업목록으로 돌아가기' : '로그인으로 돌아가기' ?></a>
            <?php endif; ?>
          </div>

          <?php if ($canManageAccounts): ?>
            <div class="account-panel">
              <h2>저장된 계정</h2>
              <p><?= $canAssignRoles ? '저장된 계정을 팀별로 묶어서 표시합니다. 관리자는 각 계정의 역할을 바로 변경할 수 있고, 현재 로그인한 계정은 삭제되거나 역할이 바뀌지 않도록 보호됩니다.' : '저장된 계정을 팀별로 묶어서 표시합니다. 팀 헤더를 눌러 명단을 감추거나 펼칠 수 있고, 현재 로그인한 계정은 삭제되지 않도록 보호됩니다.' ?></p>
              <?php if (!empty($orderedStoredAccountGroups)): ?>
                <div class="account-group-list">
                  <?php foreach ($orderedStoredAccountGroups as $groupName => $groupAccounts): ?>
                    <?php $isUnassignedGroup = $groupName === $unassignedStoredAccountGroupLabel; ?>
                    <details
                      class="account-group"
                      data-group-key="<?= h($isUnassignedGroup ? 'unassigned' : auth_team_key($groupName)) ?>"
                      open
                    >
                      <summary class="account-group-head">
                        <div class="account-group-title">
                          <h3><?= h($groupName) ?></h3>
                          <span class="account-group-count"><?= count($groupAccounts) ?>명</span>
                        </div>
                        <div class="account-group-actions">
                          <div class="account-group-note"><?= $isUnassignedGroup ? '팀 정보가 없는 계정' : '소속 팀 계정' ?></div>
                          <span class="account-group-toggle">
                            <span class="account-group-toggle-open">명단 숨기기</span>
                            <span class="account-group-toggle-closed">명단 펼치기</span>
                          </span>
                        </div>
                      </summary>
                      <div class="account-list">
                        <?php foreach ($groupAccounts as $loginId => $account): ?>
                          <div class="account-item">
                            <div>
                              <div class="account-name"><?= h((string)($account['name'] ?? $loginId)) ?></div>
                              <div class="account-meta">
                                <span class="role-chip"><?= h(auth_role_label((string)($account['role'] ?? ''))) ?></span>
                                아이디 <?= h($loginId) ?> / 비밀번호 <?= h((string)($account['password'] ?? '')) ?>
                              </div>
                              <?php if ($canManageAccounts): ?>
                                <form method="post" class="phone-form">
                                  <input type="hidden" name="action" value="update_account_phone">
                                  <input type="hidden" name="update_login_id" value="<?= h($loginId) ?>">
                                  <input type="text" name="update_phone" value="<?= h((string)($account['phone'] ?? '')) ?>" placeholder="전화번호" maxlength="20" class="phone-input" aria-label="전화번호">
                                  <button type="submit" class="phone-save-btn">저장</button>
                                </form>
                              <?php elseif (!empty($account['phone'])): ?>
                                <div class="account-phone-display"><?= h((string)($account['phone'])) ?></div>
                              <?php endif; ?>
                            </div>
                            <div class="account-item-actions">
                              <?php if ($loginId === (string)($user['login_id'] ?? '')): ?>
                                <button class="btn-secondary" type="button" disabled>현재 계정</button>
                              <?php else: ?>
                                <?php if ($canAssignRoles): ?>
                                  <form method="post" class="account-role-form">
                                    <input type="hidden" name="action" value="update_account_role">
                                    <input type="hidden" name="update_login_id" value="<?= h($loginId) ?>">
                                    <select name="update_role" aria-label="역할 변경">
                                      <?php foreach ($roleOptions as $roleOption): ?>
                                        <option value="<?= h($roleOption) ?>" <?= (string)($account['role'] ?? '') === $roleOption ? 'selected' : '' ?>><?= h(auth_role_label($roleOption)) ?></option>
                                      <?php endforeach; ?>
                                    </select>
                                    <button class="btn-secondary btn-role-save" type="submit">역할 저장</button>
                                  </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('이 계정을 삭제하시겠습니까?');">
                                  <input type="hidden" name="action" value="delete_account">
                                  <input type="hidden" name="delete_login_id" value="<?= h($loginId) ?>">
                                  <button class="btn-danger" type="submit">삭제</button>
                                </form>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </details>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="empty-box">삭제 가능한 저장 계정이 아직 없습니다.</div>
              <?php endif; ?>
            </div>

            <?php if ($canManageTeams): ?>
              <div class="account-panel">
                <h2>팀 관리</h2>
                <p>새 팀을 추가하고 현재 사용할 팀 목록을 관리할 수 있습니다. 팀명이 바뀌면 계정 소속팀, 관리감독팀 연결, 저장된 작업 팀명도 함께 갱신됩니다.</p>
                <form method="post">
                  <input type="hidden" name="action" value="create_team">
                  <div class="field">
                    <label for="team_name">새 팀 이름</label>
                    <input type="text" id="team_name" name="team_name" value="<?= h($teamForm['team_name']) ?>" placeholder="예: 품질팀" required>
                  </div>
                  <div class="field">
                    <label for="team_supervisor">관리감독팀 (선택)</label>
                    <select id="team_supervisor" name="team_supervisor">
                      <option value="">선택 안함</option>
                      <?php foreach ($teams as $supervisorTeamName): ?>
                        <option value="<?= h($supervisorTeamName) ?>" <?= $teamForm['team_supervisor'] === $supervisorTeamName ? 'selected' : '' ?>><?= h($supervisorTeamName) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="helper">팀에 내부 관리감독자가 없는 경우, 이 팀을 관리할 감독팀을 선택하세요.</div>
                  </div>
                  <button class="btn-primary" type="submit">팀 만들기</button>
                </form>
                <?php if (!empty($teams)): ?>
                  <div class="team-list">
                    <?php foreach ($teams as $teamName): ?>
                      <?php $memberCount = (int)($teamCounts[$teamName] ?? 0); ?>
                      <?php $supervisorTeam = auth_get_team_supervisor($teamName); ?>
                      <div class="team-pill">
                        <div class="team-pill-header">
                          <span><?= h($teamName) ?></span>
                          <small><?= $memberCount ?>명</small>
                        </div>
                        <?php $isProtectedTeam = auth_is_protected_team_name($teamName); ?>
                        <?php if ($supervisorTeam !== ''): ?>
                          <div class="team-pill-supervisor">관리감독팀: <?= h($supervisorTeam) ?></div>
                        <?php endif; ?>
                        <form method="post">
                          <input type="hidden" name="action" value="rename_team">
                          <input type="hidden" name="rename_team_name" value="<?= h($teamName) ?>">
                          <input
                            type="text"
                            name="rename_team_target"
                            value="<?= $teamForm['rename_team_name'] === $teamName ? h($teamForm['rename_team_target']) : h($teamName) ?>"
                            placeholder="새 팀 이름"
                            maxlength="30"
                            <?= $isProtectedTeam ? 'disabled' : '' ?>
                            required
                          >
                          <button class="btn-secondary btn-inline" type="submit" <?= $isProtectedTeam ? 'disabled' : '' ?>>팀명 수정</button>
                        </form>
                        <?php if ($isProtectedTeam): ?>
                          <div class="helper">이 팀은 화면 규칙과 권한 정책에 연결되어 있어 이름을 바꿀 수 없습니다.</div>
                        <?php endif; ?>
                        <form method="post" class="team-supervisor-form">
                          <input type="hidden" name="action" value="set_team_supervisor">
                          <input type="hidden" name="team_name" value="<?= h($teamName) ?>">
                          <select name="team_supervisor">
                            <option value="" <?= $supervisorTeam === '' ? 'selected' : '' ?>>미지정</option>
                            <?php foreach ($teams as $supervisorTeamName): ?>
                              <?php if (auth_team_key($supervisorTeamName) === auth_team_key($teamName)) { continue; } ?>
                              <option value="<?= h($supervisorTeamName) ?>" <?= $supervisorTeam === $supervisorTeamName ? 'selected' : '' ?>><?= h($supervisorTeamName) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn-secondary btn-inline" type="submit">저장</button>
                        </form>
                        <form method="post" onsubmit="return confirm('이 팀을 삭제하시겠습니까?');">
                          <input type="hidden" name="action" value="delete_team">
                          <input type="hidden" name="delete_team_name" value="<?= h($teamName) ?>">
                          <button class="btn-team-delete" type="submit" <?= $memberCount > 0 ? 'disabled' : '' ?>>삭제</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="account-panel">
              <h2>회원가입 열기</h2>
              <p>로그인 화면의 회원가입 버튼을 필요할 때만 잠깐 열 수 있습니다.</p>
              <div class="status-row">
                <div>
                  <div class="account-name">현재 상태</div>
                  <div class="account-meta">
                    <span class="status-badge <?= $registrationOpen ? 'is-open' : 'is-closed' ?>">
                      <?= $registrationOpen ? '열림' : '닫힘' ?>
                    </span>
                    <?= $registrationOpen ? '로그인 화면에서 회원가입 버튼이 보입니다.' : '로그인 화면에서 회원가입 버튼이 숨겨져 있습니다.' ?>
                  </div>
                </div>
                <form method="post">
                  <input type="hidden" name="action" value="toggle_registration">
                  <input type="hidden" name="registration_state" value="<?= $registrationOpen ? 'closed' : 'open' ?>">
                  <button class="btn-secondary btn-inline" type="submit"><?= $registrationOpen ? '회원가입 닫기' : '회원가입 열기' ?></button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <script>
    // 팀 선택 불필요 역할 (운영자 권한)
    const NO_TEAM_ROLES = new Set(['admin', 'ceo']);

    function onRoleChange(role) {
      const teamSel = document.getElementById('team');
      if (!teamSel) return;

      if (NO_TEAM_ROLES.has(role)) {
        teamSel.value = '';        // 팀 미지정 선택
        teamSel.disabled = true;
      } else {
        teamSel.disabled = false;
      }
    }

    // 페이지 로드 시 현재 역할에 맞게 초기화
    (function initRoleField() {
      const roleSel = document.getElementById('role');
      if (roleSel) onRoleChange(roleSel.value);
    })();

    (() => {
      const storageKey = 'register_worker.account_group_visibility.v1';
      const groups = Array.from(document.querySelectorAll('.account-group[data-group-key]'));

      if (groups.length === 0) {
        return;
      }

      const readState = () => {
        try {
          const raw = window.localStorage.getItem(storageKey);
          if (!raw) {
            return {};
          }

          const parsed = JSON.parse(raw);
          return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
          return {};
        }
      };

      const writeState = (state) => {
        try {
          window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (error) {
        }
      };

      const state = readState();

      groups.forEach((group) => {
        const groupKey = group.dataset.groupKey || '';
        if (groupKey !== '' && Object.prototype.hasOwnProperty.call(state, groupKey)) {
          group.open = state[groupKey] !== false;
        }

        group.addEventListener('toggle', () => {
          const nextKey = group.dataset.groupKey || '';
          if (nextKey === '') {
            return;
          }

          state[nextKey] = group.open;
          writeState(state);
        });
      });
    })();
  </script>
</body>
</html>
