<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if ($user === null || !auth_can_manage($user)) {
    header('Location: ../risk_assessment/task_select.php');
    exit;
}

$dbPath = __DIR__ . '/employees.db';
if (!is_file($dbPath)) {
    header('Location: index.php');
    exit;
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_GET['id'] ?? 0);

// ── POST: 수정 저장 ──────────────────────────────────────────
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'edit') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $error = '이름은 필수 항목입니다.';
    } else {
        $pdo->prepare("
            UPDATE employees SET
                employee_no=:no, name=:name, team=:team, position=:pos,
                phone=:phone, email=:email, join_date=:join, birth_date=:birth,
                address=:addr, emergency_contact=:emgc, memo=:memo,
                updated_at=datetime('now','localtime')
            WHERE id=:id
        ")->execute([
            ':id'    => $id,
            ':no'    => trim((string)($_POST['employee_no']      ?? '')),
            ':name'  => $name,
            ':team'  => trim((string)($_POST['team']             ?? '')),
            ':pos'   => trim((string)($_POST['position']         ?? '')),
            ':phone' => trim((string)($_POST['phone']            ?? '')),
            ':email' => trim((string)($_POST['email']            ?? '')),
            ':join'  => trim((string)($_POST['join_date']        ?? '')),
            ':birth' => trim((string)($_POST['birth_date']       ?? '')),
            ':addr'  => trim((string)($_POST['address']          ?? '')),
            ':emgc'  => trim((string)($_POST['emergency_contact'] ?? '')),
            ':memo'  => trim((string)($_POST['memo']             ?? '')),
        ]);
        $success = '직원 정보가 저장되었습니다.';
    }
}

// ── POST: 삭제 ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
    $pdo->prepare("DELETE FROM employees WHERE id=:id")->execute([':id' => $id]);
    header('Location: index.php');
    exit;
}

// ── 직원 데이터 로드 ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
$stmt->execute([':id' => $id]);
$emp  = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    header('Location: index.php');
    exit;
}

$teams      = auth_read_teams();
$showEdit   = isset($_GET['edit']) || $error !== '';  // 수정 폼 표시 여부

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function val(mixed $v): string { return h(trim((string)($v ?? ''))); }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= val($emp['name']) ?> — 직원 상세</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background:
      radial-gradient(circle at top left, rgba(69,123,157,0.16), transparent 34%),
      linear-gradient(180deg, #eff5fb 0%, #f7fafc 100%);
    min-height: 100vh;
    color: #243447;
    padding: 28px 16px 60px;
  }
  .shell { max-width: 680px; margin: 0 auto; }

  .page-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    white-space: nowrap;
  }
  .btn-secondary { background: #fff; color: #486581; border: 1px solid #c8d8e8; }
  .btn-secondary:hover { background: #f3f8fc; }
  .btn-primary   { background: #2a7ab8; color: #fff; }
  .btn-primary:hover { background: #1e5f8e; }
  .btn-danger    { background: #fff; color: #c0392b; border: 1px solid #f5b7b1; }
  .btn-danger:hover { background: #fdf2f2; }
  .btn-cancel    { background: #fff; color: #6b7c93; border: 1px solid #c8d8e8; }
  .btn-cancel:hover { background: #f8fbfe; }

  /* 카드 */
  .card {
    background: rgba(255,255,255,0.97);
    border: 1px solid #d7e3ef;
    border-radius: 20px;
    box-shadow: 0 16px 40px rgba(18,52,77,0.08);
    overflow: hidden;
    margin-bottom: 16px;
  }

  /* 상세 카드 — 프로필 헤더 */
  .profile-header {
    background: linear-gradient(135deg, #1e5f8e 0%, #2a7ab8 100%);
    padding: 28px 28px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .avatar {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: #fff; font-weight: 700;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.35);
  }
  .profile-info { color: #fff; }
  .profile-info h1 { font-size: 22px; margin-bottom: 4px; }
  .profile-info .sub { font-size: 13px; opacity: 0.82; display: flex; gap: 10px; flex-wrap: wrap; }
  .badge {
    display: inline-block; padding: 2px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
    background: rgba(255,255,255,0.22); color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
  }

  /* 정보 섹션 */
  .info-section { padding: 20px 24px; }
  .info-section-title {
    font-size: 12px; font-weight: 700; color: #7a9ab8;
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 12px;
  }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; }
  .info-row  { display: contents; }
  .info-label {
    padding: 10px 0; font-size: 12px; color: #7a9ab8; font-weight: 600;
    border-bottom: 1px solid #eef3f8;
  }
  .info-value {
    padding: 10px 0 10px 12px; font-size: 14px; color: #12344d;
    border-bottom: 1px solid #eef3f8; word-break: break-all;
  }
  .info-row:last-child .info-label,
  .info-row:last-child .info-value { border-bottom: none; }
  .info-value.empty { color: #bbb; font-style: italic; }

  .memo-box {
    background: #f8fbfe; border: 1px solid #d7e3ef; border-radius: 10px;
    padding: 12px 14px; font-size: 13px; color: #243447;
    line-height: 1.7; white-space: pre-wrap; min-height: 50px;
  }
  .memo-box.empty { color: #bbb; font-style: italic; }

  .card-actions {
    padding: 14px 24px; border-top: 1px solid #eef3f8;
    display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;
  }

  /* 수정 카드 */
  .edit-card {
    border: 2px solid #2a7ab8;
    scroll-margin-top: 20px;
  }
  .edit-card-head {
    padding: 16px 24px 0;
    display: flex; align-items: center; justify-content: space-between;
  }
  .edit-card-head .title {
    font-size: 15px; font-weight: 700; color: #12344d;
  }
  .edit-card-head .close-btn {
    background: none; border: none; cursor: pointer;
    font-size: 20px; color: #aab8c6; line-height: 1; padding: 2px 6px;
  }
  .edit-card-head .close-btn:hover { color: #c0392b; }
  .edit-card-body { padding: 16px 24px 24px; }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
  }
  .field label { display: block; font-size: 12px; color: #486581; font-weight: 600; margin-bottom: 5px; }
  .field input, .field select, .field textarea {
    width: 100%; padding: 8px 10px;
    border: 1px solid #c8d8e8; border-radius: 9px;
    font-size: 13px; font-family: inherit; color: #243447;
    background: #fff;
  }
  .field input:focus, .field select:focus, .field textarea:focus {
    outline: none; border-color: #2a7ab8;
    box-shadow: 0 0 0 3px rgba(42,122,184,0.12);
  }
  .field textarea { resize: vertical; min-height: 60px; }
  .field-full { grid-column: 1 / -1; }
  .form-actions { display: flex; gap: 8px; flex-wrap: wrap; }

  .msg { padding: 10px 14px; border-radius: 9px; font-size: 13px; margin-bottom: 14px; }
  .msg-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5b7b1; }
  .msg-success { background: #edfaf4; color: #1a7a4a; border: 1px solid #a8e6c3; }
</style>
</head>
<body>
<div class="shell">

  <div class="page-head">
    <a href="index.php" class="btn btn-secondary">← 목록으로</a>
  </div>

  <?php if ($success !== ''): ?>
    <div class="msg msg-success"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="msg msg-error"><?= h($error) ?></div>
  <?php endif; ?>

  <!-- 상세 카드 -->
  <div class="card" id="detail-card">
    <div class="profile-header">
      <div class="avatar"><?= mb_substr(val($emp['name']), 0, 1, 'UTF-8') ?></div>
      <div class="profile-info">
        <h1><?= val($emp['name']) ?></h1>
        <div class="sub">
          <?php if (val($emp['employee_no']) !== ''): ?>
            <span>사원번호: <?= val($emp['employee_no']) ?></span>
          <?php endif; ?>
          <?php if (val($emp['team']) !== ''): ?>
            <span class="badge"><?= val($emp['team']) ?></span>
          <?php endif; ?>
          <?php if (val($emp['position']) !== ''): ?>
            <span class="badge"><?= val($emp['position']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="info-section">
      <div class="info-section-title">기본 정보</div>
      <div class="info-grid">
        <?php
        $fields = [
            '연락처'     => $emp['phone'],
            '이메일'     => $emp['email'],
            '입사일'     => $emp['join_date'],
            '생년월일'   => $emp['birth_date'],
            '비상연락처' => $emp['emergency_contact'],
            '주소'       => $emp['address'],
        ];
        foreach ($fields as $label => $value):
            $v = val($value);
        ?>
          <div class="info-row">
            <div class="info-label"><?= h($label) ?></div>
            <div class="info-value <?= $v === '' ? 'empty' : '' ?>"><?= $v !== '' ? $v : '—' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="info-section" style="padding-top:0">
      <div class="info-section-title">메모</div>
      <?php $memo = val($emp['memo']); ?>
      <div class="memo-box <?= $memo === '' ? 'empty' : '' ?>"><?= $memo !== '' ? $memo : '메모 없음' ?></div>
    </div>

    <div class="info-section" style="padding-top:0; padding-bottom:14px;">
      <div style="font-size:11px; color:#aab8c6; text-align:right;">
        등록: <?= val($emp['created_at']) ?> &nbsp;·&nbsp; 최종수정: <?= val($emp['updated_at']) ?>
      </div>
    </div>

    <div class="card-actions">
      <button type="button" class="btn btn-primary" onclick="toggleEdit()">수정</button>
      <form method="post" onsubmit="return confirm('<?= val($emp['name']) ?> 직원을 삭제하시겠습니까?')" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger">삭제</button>
      </form>
    </div>
  </div>

  <!-- 수정 카드 -->
  <div class="card edit-card" id="edit-card" style="<?= $showEdit ? '' : 'display:none' ?>">
    <div class="edit-card-head">
      <span class="title">직원 정보 수정</span>
      <button type="button" class="close-btn" onclick="toggleEdit(false)" title="닫기">×</button>
    </div>
    <div class="edit-card-body">
      <form method="post">
        <input type="hidden" name="action" value="edit">
        <div class="form-grid">
          <div class="field">
            <label>사원번호</label>
            <input type="text" name="employee_no" value="<?= val($emp['employee_no']) ?>" placeholder="예: EMP-001">
          </div>
          <div class="field">
            <label>이름 <span style="color:#c0392b">*</span></label>
            <input type="text" name="name" value="<?= val($emp['name']) ?>" required>
          </div>
          <div class="field">
            <label>소속팀</label>
            <select name="team">
              <option value="">-- 팀 선택 --</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?= val($t) ?>" <?= val($emp['team']) === val($t) ? 'selected' : '' ?>><?= val($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>직책</label>
            <input type="text" name="position" value="<?= val($emp['position']) ?>" placeholder="예: 과장">
          </div>
          <div class="field">
            <label>연락처</label>
            <input type="tel" name="phone" value="<?= val($emp['phone']) ?>" placeholder="010-0000-0000">
          </div>
          <div class="field">
            <label>이메일</label>
            <input type="email" name="email" value="<?= val($emp['email']) ?>" placeholder="example@email.com">
          </div>
          <div class="field">
            <label>입사일</label>
            <input type="date" name="join_date" value="<?= val($emp['join_date']) ?>">
          </div>
          <div class="field">
            <label>생년월일</label>
            <input type="date" name="birth_date" value="<?= val($emp['birth_date']) ?>">
          </div>
          <div class="field">
            <label>비상연락처</label>
            <input type="tel" name="emergency_contact" value="<?= val($emp['emergency_contact']) ?>" placeholder="010-0000-0000">
          </div>
          <div class="field field-full">
            <label>주소</label>
            <input type="text" name="address" value="<?= val($emp['address']) ?>">
          </div>
          <div class="field field-full">
            <label>메모</label>
            <textarea name="memo"><?= val($emp['memo']) ?></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">저장</button>
          <button type="button" class="btn btn-cancel" onclick="toggleEdit(false)">취소</button>
        </div>
      </form>
    </div>
  </div>

</div>
<script>
function toggleEdit(forceShow) {
  const card = document.getElementById('edit-card');
  const show  = forceShow !== undefined ? forceShow : card.style.display === 'none';
  card.style.display = show ? '' : 'none';
  if (show) {
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    card.querySelector('input[name="name"]').focus();
  }
}
<?php if ($showEdit && $error === '' && $success === ''): ?>
document.addEventListener('DOMContentLoaded', () => toggleEdit(true));
<?php endif; ?>
</script>
</body>
</html>
