<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if ($user === null || !auth_can_manage($user)) {
    header('Location: ../risk_assessment/task_select.php');
    exit;
}

// ── SQLite DB 초기화 ──────────────────────────────────────────
$dbPath = __DIR__ . '/employees.db';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode=WAL");

$pdo->exec("
CREATE TABLE IF NOT EXISTS employees (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_no  TEXT,
    name         TEXT NOT NULL,
    team         TEXT,
    position     TEXT,
    phone        TEXT,
    email        TEXT,
    join_date    TEXT,
    birth_date   TEXT,
    address      TEXT,
    emergency_contact TEXT,
    memo         TEXT,
    created_at   TEXT DEFAULT (datetime('now','localtime')),
    updated_at   TEXT DEFAULT (datetime('now','localtime'))
)
");

$teams = auth_read_teams();

// ── 최초 실행 시 계정관리 목록으로 직원 테이블 초기화 ─────────
$rowCount = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
if ($rowCount === 0) {
    $roleLabels = [
        'worker'         => '일반작업자',
        'leader'         => '작업지휘자',
        'manager'        => '관리감독자',
        'safety_manager' => '안전관리자',
        'admin'          => '운영자',
        'ceo'            => '대표이사',
    ];
    $accounts = auth_read_stored_accounts();
    $insert = $pdo->prepare("
        INSERT INTO employees (employee_no, name, team, position, phone)
        VALUES (:no, :name, :team, :pos, :phone)
    ");
    foreach ($accounts as $loginId => $acc) {
        $insert->execute([
            ':no'    => (string)$loginId,
            ':name'  => (string)($acc['name'] ?? ''),
            ':team'  => (string)($acc['team'] ?? ''),
            ':pos'   => $roleLabels[$acc['role'] ?? ''] ?? (string)($acc['role'] ?? ''),
            ':phone' => (string)($acc['phone'] ?? ''),
        ]);
    }
    unset($accounts, $insert);
}

// ── POST 처리 ─────────────────────────────────────────────────
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $error = '이름은 필수 항목입니다.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO employees
                    (employee_no, name, team, position, phone, email, join_date, birth_date, address, emergency_contact, memo)
                VALUES
                    (:no, :name, :team, :pos, :phone, :email, :join, :birth, :addr, :emgc, :memo)
            ");
            $stmt->execute([
                ':no'    => trim((string)($_POST['employee_no'] ?? '')),
                ':name'  => $name,
                ':team'  => trim((string)($_POST['team'] ?? '')),
                ':pos'   => trim((string)($_POST['position'] ?? '')),
                ':phone' => trim((string)($_POST['phone'] ?? '')),
                ':email' => trim((string)($_POST['email'] ?? '')),
                ':join'  => trim((string)($_POST['join_date'] ?? '')),
                ':birth' => trim((string)($_POST['birth_date'] ?? '')),
                ':addr'  => trim((string)($_POST['address'] ?? '')),
                ':emgc'  => trim((string)($_POST['emergency_contact'] ?? '')),
                ':memo'  => trim((string)($_POST['memo'] ?? '')),
            ]);
            $success = '직원 정보가 등록되었습니다.';
        }

    } elseif ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            $error = '잘못된 요청입니다.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE employees SET
                    employee_no=:no, name=:name, team=:team, position=:pos,
                    phone=:phone, email=:email, join_date=:join, birth_date=:birth,
                    address=:addr, emergency_contact=:emgc, memo=:memo,
                    updated_at=datetime('now','localtime')
                WHERE id=:id
            ");
            $stmt->execute([
                ':id'    => $id,
                ':no'    => trim((string)($_POST['employee_no'] ?? '')),
                ':name'  => $name,
                ':team'  => trim((string)($_POST['team'] ?? '')),
                ':pos'   => trim((string)($_POST['position'] ?? '')),
                ':phone' => trim((string)($_POST['phone'] ?? '')),
                ':email' => trim((string)($_POST['email'] ?? '')),
                ':join'  => trim((string)($_POST['join_date'] ?? '')),
                ':birth' => trim((string)($_POST['birth_date'] ?? '')),
                ':addr'  => trim((string)($_POST['address'] ?? '')),
                ':emgc'  => trim((string)($_POST['emergency_contact'] ?? '')),
                ':memo'  => trim((string)($_POST['memo'] ?? '')),
            ]);
            $success = '직원 정보가 수정되었습니다.';
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM employees WHERE id=:id")->execute([':id' => $id]);
            $success = '직원 정보가 삭제되었습니다.';
        }
    }
}

// ── 검색/목록 ────────────────────────────────────────────────
$search    = trim((string)($_GET['q'] ?? ''));
$filterTeam = trim((string)($_GET['team'] ?? ''));

$where  = [];
$params = [];
if ($search !== '') {
    $where[]          = "(name LIKE :q OR employee_no LIKE :q OR phone LIKE :q OR position LIKE :q)";
    $params[':q']     = '%' . $search . '%';
}
if ($filterTeam !== '') {
    $where[]             = "team = :team";
    $params[':team']     = $filterTeam;
}

$sql = "SELECT * FROM employees" . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY team, name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 수정 대상 불러오기
$editTarget = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id=:id");
    $stmt->execute([':id' => $eid]);
    $editTarget = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>직원명부</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background:
      radial-gradient(circle at top left, rgba(69,123,157,0.16), transparent 34%),
      linear-gradient(180deg, #eff5fb 0%, #f7fafc 100%);
    min-height: 100vh;
    color: #243447;
    padding: 28px 16px 40px;
  }
  .shell { max-width: 1100px; margin: 0 auto; }
  .panel {
    background: rgba(255,255,255,0.96);
    border: 1px solid #d7e3ef;
    border-radius: 20px;
    box-shadow: 0 16px 40px rgba(18,52,77,0.08);
    overflow: hidden;
    margin-bottom: 18px;
  }
  .panel-head { padding: 24px 24px 12px; }
  .panel-head-bar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .panel-head h1 { font-size: 26px; color: #12344d; margin-bottom: 8px; }
  .panel-head p  { color: #6b7c93; font-size: 13px; line-height: 1.6; }
  .panel-body  { padding: 18px 24px 24px; }
  .btn {
    display: inline-block;
    padding: 8px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    white-space: nowrap;
  }
  .btn-primary   { background: #2a7ab8; color: #fff; }
  .btn-primary:hover { background: #1e5f8e; }
  .btn-secondary { background: #fff; color: #486581; border: 1px solid #c8d8e8; }
  .btn-secondary:hover { background: #f3f8fc; }
  .btn-danger    { background: #fff; color: #c0392b; border: 1px solid #f5b7b1; font-size: 12px; padding: 5px 10px; }
  .btn-danger:hover { background: #fdf2f2; }
  .btn-edit      { background: #fff; color: #2a7ab8; border: 1px solid #b3d4ee; font-size: 12px; padding: 5px 10px; }
  .btn-edit:hover { background: #f0f7fd; }
  .name-link { color: #1a5f8e; font-weight: 700; text-decoration: none; }
  .name-link:hover { text-decoration: underline; color: #2a7ab8; }

  /* 검색바 */
  .search-bar {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    align-items: center;
  }
  .search-bar input, .search-bar select {
    padding: 8px 12px;
    border: 1px solid #c8d8e8;
    border-radius: 9px;
    font-size: 13px;
    color: #243447;
    background: #fff;
    min-width: 160px;
  }
  .count-badge {
    font-size: 13px;
    color: #6b7c93;
    margin-left: auto;
  }

  /* 테이블 */
  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th {
    background: #f0f6fb;
    color: #486581;
    font-weight: 600;
    padding: 9px 10px;
    text-align: left;
    border-bottom: 1px solid #d7e3ef;
    white-space: nowrap;
  }
  td {
    padding: 9px 10px;
    border-bottom: 1px solid #eef3f8;
    vertical-align: middle;
  }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #f7fbff; }
  .td-actions { display: flex; gap: 5px; white-space: nowrap; }
  .empty-row td { text-align: center; color: #999; padding: 30px; }

  /* 폼 패널 */
  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 14px;
  }
  .field label {
    display: block;
    font-size: 12px;
    color: #486581;
    font-weight: 600;
    margin-bottom: 5px;
  }
  .field input, .field select, .field textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #c8d8e8;
    border-radius: 9px;
    font-size: 13px;
    font-family: inherit;
    color: #243447;
    background: #fff;
  }
  .field textarea { resize: vertical; min-height: 60px; }
  .field-full { grid-column: 1 / -1; }
  .form-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .msg { padding: 10px 14px; border-radius: 9px; font-size: 13px; margin-bottom: 14px; }
  .msg-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5b7b1; }
  .msg-success { background: #edfaf4; color: #1a7a4a; border: 1px solid #a8e6c3; }
  .section-title {
    font-size: 15px;
    font-weight: 700;
    color: #12344d;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e8f0f8;
  }
</style>
</head>
<body>
<div class="shell">

  <!-- 헤더 -->
  <div class="panel">
    <div class="panel-head">
      <div class="panel-head-bar">
        <div>
          <h1>직원명부</h1>
          <p>직원 기본 정보를 등록·관리합니다. 총 <?= count($employees) ?>명이 조회됩니다.</p>
        </div>
        <a class="btn btn-secondary" href="../risk_assessment/register_worker.php">계정 관리로 돌아가기</a>
      </div>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="msg msg-error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success !== ''): ?>
    <div class="msg msg-success"><?= h($success) ?></div>
  <?php endif; ?>

  <!-- 등록/수정 폼 -->
  <div class="panel">
    <div class="panel-body">
      <div class="section-title"><?= $editTarget ? '직원 정보 수정' : '직원 등록' ?></div>
      <form method="post">
        <input type="hidden" name="action" value="<?= $editTarget ? 'edit' : 'add' ?>">
        <?php if ($editTarget): ?>
          <input type="hidden" name="id" value="<?= (int)$editTarget['id'] ?>">
        <?php endif; ?>
        <div class="form-grid">
          <div class="field">
            <label>사원번호</label>
            <input type="text" name="employee_no" value="<?= h((string)($editTarget['employee_no'] ?? '')) ?>" placeholder="예: EMP-001">
          </div>
          <div class="field">
            <label>이름 <span style="color:#c0392b">*</span></label>
            <input type="text" name="name" value="<?= h((string)($editTarget['name'] ?? '')) ?>" placeholder="홍길동" required>
          </div>
          <div class="field">
            <label>소속팀</label>
            <select name="team">
              <option value="">-- 팀 선택 --</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?= h((string)$t) ?>" <?= ((string)($editTarget['team'] ?? '')) === (string)$t ? 'selected' : '' ?>><?= h((string)$t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>직책</label>
            <input type="text" name="position" value="<?= h((string)($editTarget['position'] ?? '')) ?>" placeholder="예: 과장">
          </div>
          <div class="field">
            <label>연락처</label>
            <input type="tel" name="phone" value="<?= h((string)($editTarget['phone'] ?? '')) ?>" placeholder="010-0000-0000">
          </div>
          <div class="field">
            <label>이메일</label>
            <input type="email" name="email" value="<?= h((string)($editTarget['email'] ?? '')) ?>" placeholder="example@email.com">
          </div>
          <div class="field">
            <label>입사일</label>
            <input type="date" name="join_date" value="<?= h((string)($editTarget['join_date'] ?? '')) ?>">
          </div>
          <div class="field">
            <label>생년월일</label>
            <input type="date" name="birth_date" value="<?= h((string)($editTarget['birth_date'] ?? '')) ?>">
          </div>
          <div class="field">
            <label>비상연락처</label>
            <input type="tel" name="emergency_contact" value="<?= h((string)($editTarget['emergency_contact'] ?? '')) ?>" placeholder="010-0000-0000">
          </div>
          <div class="field field-full">
            <label>주소</label>
            <input type="text" name="address" value="<?= h((string)($editTarget['address'] ?? '')) ?>" placeholder="주소를 입력하세요">
          </div>
          <div class="field field-full">
            <label>메모</label>
            <textarea name="memo" placeholder="특이사항 등 자유롭게 입력"><?= h((string)($editTarget['memo'] ?? '')) ?></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $editTarget ? '수정 저장' : '등록' ?></button>
          <?php if ($editTarget): ?>
            <a href="?" class="btn btn-secondary">취소</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- 목록 -->
  <div class="panel">
    <div class="panel-body">
      <div class="section-title">직원 목록</div>
      <form method="get" class="search-bar">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="이름·사원번호·연락처·직책 검색">
        <select name="team">
          <option value="">전체 팀</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?= h((string)$t) ?>" <?= $filterTeam === (string)$t ? 'selected' : '' ?>><?= h((string)$t) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">검색</button>
        <?php if ($search !== '' || $filterTeam !== ''): ?>
          <a href="?" class="btn btn-secondary">초기화</a>
        <?php endif; ?>
        <span class="count-badge">총 <?= count($employees) ?>명</span>
      </form>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>사원번호</th>
              <th>이름</th>
              <th>소속팀</th>
              <th>직책</th>
              <th>연락처</th>
              <th>이메일</th>
              <th>입사일</th>
              <th>비상연락처</th>
              <th>메모</th>
              <th>관리</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($employees)): ?>
              <tr class="empty-row"><td colspan="10">등록된 직원이 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($employees as $emp): ?>
                <tr>
                  <td><?= h((string)($emp['employee_no'] ?? '')) ?></td>
                  <td><a href="view.php?id=<?= (int)$emp['id'] ?>" class="name-link"><?= h((string)$emp['name']) ?></a></td>
                  <td><?= h((string)($emp['team'] ?? '')) ?></td>
                  <td><?= h((string)($emp['position'] ?? '')) ?></td>
                  <td><?= h((string)($emp['phone'] ?? '')) ?></td>
                  <td><?= h((string)($emp['email'] ?? '')) ?></td>
                  <td><?= h((string)($emp['join_date'] ?? '')) ?></td>
                  <td><?= h((string)($emp['emergency_contact'] ?? '')) ?></td>
                  <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h((string)($emp['memo'] ?? '')) ?>"><?= h((string)($emp['memo'] ?? '')) ?></td>
                  <td>
                    <div class="td-actions">
                      <a href="?edit=<?= (int)$emp['id'] ?>" class="btn btn-edit">수정</a>
                      <form method="post" onsubmit="return confirm('<?= h((string)$emp['name']) ?> 직원을 삭제하시겠습니까?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                        <button type="submit" class="btn btn-danger">삭제</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
