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

foreach ([
    'job_title', 'height', 'weight', 'shoe_size', 'blood_type',
    'uniform_winter_top', 'uniform_winter_bottom',
    'uniform_spring_top', 'uniform_spring_bottom',
    'uniform_summer_top', 'uniform_summer_bottom', 'uniform_shortsleeve',
    'uniform_heat_top', 'uniform_heat_bottom',
    'emergency_contact_relation',
] as $col) {
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN {$col} TEXT");
    } catch (PDOException) {
    }
}
try { $pdo->exec("ALTER TABLE employees ADD COLUMN employment_status TEXT NOT NULL DEFAULT 'active'"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE employees ADD COLUMN retired_at TEXT"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE employees ADD COLUMN retired_reason TEXT"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE employees ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1"); } catch (PDOException) {}

$pdo->exec("
CREATE TABLE IF NOT EXISTS employee_documents (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id   INTEGER NOT NULL,
    doc_type      TEXT NOT NULL,
    filename      TEXT NOT NULL,
    original_name TEXT,
    uploaded_at   TEXT DEFAULT (datetime('now','localtime'))
)
");

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$id = (int)($_GET['id'] ?? 0);

if (isset($_GET['download']) && $id > 0) {
    $docId = (int)$_GET['download'];
    $doc = $pdo->prepare("SELECT * FROM employee_documents WHERE id=:did AND employee_id=:eid");
    $doc->execute([':did' => $docId, ':eid' => $id]);
    $docRow = $doc->fetch(PDO::FETCH_ASSOC);
    if ($docRow) {
        $filePath = $uploadDir . $docRow['filename'];
        if (is_file($filePath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode((string)$docRow['original_name']) . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    header('Location: view.php?id=' . $id);
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'edit') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $error = '이름은 필수 항목입니다.';
    } else {
        $employmentStatus = trim((string)($_POST['employment_status'] ?? 'active')) === 'retired' ? 'retired' : 'active';
        $retiredAt = trim((string)($_POST['retired_at'] ?? ''));
        $retiredReason = trim((string)($_POST['retired_reason'] ?? ''));
        $pdo->prepare("
            UPDATE employees SET
                employee_no=:no, name=:name, team=:team, position=:pos, job_title=:jt,
                phone=:phone, email=:email, join_date=:join, birth_date=:birth,
                address=:addr, emergency_contact=:emgc, emergency_contact_relation=:emgcrel, memo=:memo,
                employment_status=:employment_status, retired_at=:retired_at, retired_reason=:retired_reason, is_active=:is_active,
                height=:height, weight=:weight, shoe_size=:shoe, blood_type=:blood,
                uniform_winter_top=:uwt, uniform_winter_bottom=:uwb,
                uniform_spring_top=:ust, uniform_spring_bottom=:usb,
                uniform_summer_top=:uht, uniform_summer_bottom=:uhb, uniform_shortsleeve=:uss,
                uniform_heat_top=:uhtop, uniform_heat_bottom=:uhbot,
                updated_at=datetime('now','localtime')
            WHERE id=:id
        ")->execute([
            ':id' => $id,
            ':no' => trim((string)($_POST['employee_no'] ?? '')),
            ':name' => $name,
            ':team' => trim((string)($_POST['team'] ?? '')),
            ':pos' => trim((string)($_POST['position'] ?? '')),
            ':jt' => trim((string)($_POST['job_title'] ?? '')),
            ':phone' => trim((string)($_POST['phone'] ?? '')),
            ':email' => trim((string)($_POST['email'] ?? '')),
            ':join' => trim((string)($_POST['join_date'] ?? '')),
            ':birth' => trim((string)($_POST['birth_date'] ?? '')),
            ':addr' => trim((string)($_POST['address'] ?? '')),
            ':emgc' => trim((string)($_POST['emergency_contact'] ?? '')),
            ':emgcrel' => trim((string)($_POST['emergency_contact_relation'] ?? '')),
            ':memo' => trim((string)($_POST['memo'] ?? '')),
            ':employment_status' => $employmentStatus,
            ':retired_at' => $employmentStatus === 'retired' ? $retiredAt : '',
            ':retired_reason' => $employmentStatus === 'retired' ? $retiredReason : '',
            ':is_active' => $employmentStatus === 'retired' ? 0 : 1,
            ':height' => trim((string)($_POST['height'] ?? '')),
            ':weight' => trim((string)($_POST['weight'] ?? '')),
            ':shoe' => trim((string)($_POST['shoe_size'] ?? '')),
            ':blood' => trim((string)($_POST['blood_type'] ?? '')),
            ':uwt' => trim((string)($_POST['uniform_winter_top'] ?? '')),
            ':uwb' => trim((string)($_POST['uniform_winter_bottom'] ?? '')),
            ':ust' => trim((string)($_POST['uniform_spring_top'] ?? '')),
            ':usb' => trim((string)($_POST['uniform_spring_bottom'] ?? '')),
            ':uht' => trim((string)($_POST['uniform_summer_top'] ?? '')),
            ':uhb' => trim((string)($_POST['uniform_summer_bottom'] ?? '')),
            ':uss' => trim((string)($_POST['uniform_shortsleeve'] ?? '')),
            ':uhtop' => trim((string)($_POST['uniform_heat_top'] ?? '')),
            ':uhbot' => trim((string)($_POST['uniform_heat_bottom'] ?? '')),
        ]);
        $success = '직원 정보가 저장되었습니다.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
    $pdo->prepare("DELETE FROM employees WHERE id=:id")->execute([':id' => $id]);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'retire') {
    $pdo->prepare("
        UPDATE employees
        SET employment_status = 'retired',
            is_active = 0,
            retired_at = :retired_at,
            retired_reason = :retired_reason,
            updated_at = datetime('now','localtime')
        WHERE id = :id
    ")->execute([
        ':id' => $id,
        ':retired_at' => trim((string)($_POST['retired_at'] ?? date('Y-m-d'))),
        ':retired_reason' => trim((string)($_POST['retired_reason'] ?? '')),
    ]);
    header('Location: view.php?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'restore') {
    $pdo->prepare("
        UPDATE employees
        SET employment_status = 'active',
            is_active = 1,
            retired_at = '',
            retired_reason = '',
            updated_at = datetime('now','localtime')
        WHERE id = :id
    ")->execute([':id' => $id]);
    header('Location: view.php?id=' . $id);
    exit;
}

$allowedDocTypes = ['resident_cert', 'medical_exam', 'drivers_license', 'safety_edu', 'bank_copy', 'resume', 'labor_contract', 'resignation_letter'];
$allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload_doc') {
    $docType = trim((string)($_POST['doc_type'] ?? ''));
    $file = $_FILES['doc_file'] ?? null;
    if (in_array($docType, $allowedDocTypes, true) && $file && $file['error'] === UPLOAD_ERR_OK) {
        $origName = basename((string)$file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts, true)) {
            $old = $pdo->prepare("SELECT filename FROM employee_documents WHERE employee_id=:eid AND doc_type=:dt");
            $old->execute([':eid' => $id, ':dt' => $docType]);
            if ($oldRow = $old->fetch(PDO::FETCH_ASSOC)) {
                @unlink($uploadDir . $oldRow['filename']);
                $pdo->prepare("DELETE FROM employee_documents WHERE employee_id=:eid AND doc_type=:dt")
                    ->execute([':eid' => $id, ':dt' => $docType]);
            }
            $storedName = $id . '_' . $docType . '_' . time() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $uploadDir . $storedName);
            $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type, filename, original_name) VALUES (:eid,:dt,:fn,:on)")
                ->execute([':eid' => $id, ':dt' => $docType, ':fn' => $storedName, ':on' => $origName]);
            $success = '서류가 등록되었습니다.';
        } else {
            $error = '허용되지 않는 파일 형식입니다.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_doc') {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $row = $pdo->prepare("SELECT filename FROM employee_documents WHERE id=:did AND employee_id=:eid");
    $row->execute([':did' => $docId, ':eid' => $id]);
    if ($r = $row->fetch(PDO::FETCH_ASSOC)) {
        @unlink($uploadDir . $r['filename']);
        $pdo->prepare("DELETE FROM employee_documents WHERE id=:did")->execute([':did' => $docId]);
        $success = '서류가 삭제되었습니다.';
    }
}

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
$stmt->execute([':id' => $id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    header('Location: index.php');
    exit;
}

$teams = auth_read_teams();
$showEdit = isset($_GET['edit']) || $error !== '';

$docStmt = $pdo->prepare("SELECT * FROM employee_documents WHERE employee_id=:eid");
$docStmt->execute([':eid' => $id]);
$docRows = $docStmt->fetchAll(PDO::FETCH_ASSOC);
$docMap = [];
foreach ($docRows as $dr) {
    $docMap[$dr['doc_type']] = $dr;
}

$docTypeLabels = [
    'resident_cert' => '주민등록등본',
    'medical_exam' => '채용신체검사서',
    'drivers_license' => '운전면허증',
    'safety_edu' => '기초건설안전보건교육이수증',
    'bank_copy' => '통장사본',
    'resume' => '이력서',
    'labor_contract' => '근로계약서',
    'resignation_letter' => '사직서 사본',
];

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function val(mixed $v): string { return h(trim((string)($v ?? ''))); }
function display_name(string $name): string { return h(preg_replace('/\s*\(.*?\)\s*$/', '', $name)); }
function employment_status_text(array $emp): string {
    $status = trim((string)($emp['employment_status'] ?? ''));
    $isActive = (int)($emp['is_active'] ?? 1) === 1;
    if ($status === 'retired' || !$isActive) {
        $retiredAt = trim((string)($emp['retired_at'] ?? ''));
        return $retiredAt !== '' ? '퇴사 (' . $retiredAt . ')' : '퇴사';
    }
    return '재직중';
}

function employment_duration_text(array $emp): string {
    $joinDate = trim((string)($emp['join_date'] ?? ''));
    if ($joinDate === '') {
        return '';
    }

    try {
        $start = new DateTimeImmutable($joinDate);
    } catch (Throwable $e) {
        return '';
    }

    $status = trim((string)($emp['employment_status'] ?? ''));
    $isActive = (int)($emp['is_active'] ?? 1) === 1;
    $retiredAt = trim((string)($emp['retired_at'] ?? ''));

    if (($status === 'retired' || !$isActive) && $retiredAt !== '') {
        try {
            $end = new DateTimeImmutable($retiredAt);
        } catch (Throwable $e) {
            $end = new DateTimeImmutable('today');
        }
    } else {
        $end = new DateTimeImmutable('today');
    }

    if ($end < $start) {
        return '';
    }

    $diff = $start->diff($end);
    $parts = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . '년';
    }
    if ($diff->m > 0) {
        $parts[] = $diff->m . '개월';
    }
    if ($diff->y === 0 && $diff->m === 0) {
        $days = max(1, $diff->days + 1);
        $parts[] = $days . '일';
    }

    return implode(' ', $parts);
}

function team_badge_colors(string $team): array {
    $map = [
        '대표이사' => ['#7c3aed', 'rgba(255,255,255,0.25)'],
        '공사팀-전기' => ['#1d4ed8', 'rgba(255,255,255,0.25)'],
        '공사팀-전기2' => ['#2563eb', 'rgba(255,255,255,0.25)'],
        '공사팀-계측' => ['#0369a1', 'rgba(255,255,255,0.25)'],
        '가스팀' => ['#b45309', 'rgba(255,255,255,0.25)'],
        '관리팀' => ['#047857', 'rgba(255,255,255,0.25)'],
        '안전관리' => ['#be123c', 'rgba(255,255,255,0.25)'],
        '공사2팀' => ['#0f766e', 'rgba(255,255,255,0.25)'],
    ];
    return $map[$team] ?? ['#1e5f8e', 'rgba(255,255,255,0.22)'];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= val($emp['name']) ?> - 직원 상세</title>
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
  .shell { max-width: 960px; margin: 0 auto; }
  .page-head { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
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
  .btn-primary { background: #2a7ab8; color: #fff; }
  .btn-primary:hover { background: #1e5f8e; }
  .btn-danger { background: #fff; color: #c0392b; border: 1px solid #f5b7b1; }
  .btn-danger:hover { background: #fdf2f2; }
  .btn-cancel { background: #fff; color: #6b7c93; border: 1px solid #c8d8e8; }
  .btn-cancel:hover { background: #f8fbfe; }
  .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 7px; }
  .btn-upload { background: #f0f7fd; color: #1a5f8e; border: 1px solid #b3d4ee; }
  .btn-upload:hover { background: #deeef9; }
  .btn-download { background: #edfaf4; color: #1a7a4a; border: 1px solid #a8e6c3; }
  .btn-download:hover { background: #d5f5e3; }
  .doc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .doc-table th { background: #f0f6fb; color: #486581; font-weight: 600; padding: 8px 10px; text-align: left; border-bottom: 1px solid #d7e3ef; }
  .doc-table td { padding: 8px 10px; border-bottom: 1px solid #eef3f8; vertical-align: middle; }
  .doc-table tr:last-child td { border-bottom: none; }
  .doc-status-ok { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #edfaf4; color: #1a7a4a; }
  .doc-status-no { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #f3f4f6; color: #9ca3af; }
  .doc-actions { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
  .doc-upload-label { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; background: #f0f7fd; color: #1a5f8e; border: 1px solid #b3d4ee; }
  .doc-upload-label:hover { background: #deeef9; }
  .doc-upload-input { display: none; }
  .card {
    background: rgba(255,255,255,0.97);
    border: 1px solid #d7e3ef;
    border-radius: 20px;
    box-shadow: 0 16px 40px rgba(18,52,77,0.08);
    overflow: hidden;
    margin-bottom: 16px;
  }
  .profile-header {
    background: linear-gradient(135deg, #1e5f8e 0%, #2a7ab8 100%);
    padding: 28px 28px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #fff;
    font-weight: 700;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.35);
  }
  .profile-info { color: #fff; }
  .profile-info h1 { font-size: 22px; margin-bottom: 4px; }
  .profile-info .sub { font-size: 13px; opacity: 0.82; display: flex; gap: 10px; flex-wrap: wrap; }
  .badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(255,255,255,0.22);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
  }
  .info-section { padding: 20px 24px; }
  .info-section-title {
    font-size: 12px;
    font-weight: 700;
    color: #7a9ab8;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 12px;
  }
  .info-grid { display: grid; grid-template-columns: minmax(120px, 180px) minmax(0, 1fr); column-gap: 18px; }
  .info-row { display: contents; }
  .info-label {
    padding: 10px 0;
    font-size: 12px;
    color: #7a9ab8;
    font-weight: 600;
    border-bottom: 1px solid #eef3f8;
    white-space: nowrap;
  }
  .info-value {
    padding: 10px 0 10px 12px;
    font-size: 14px;
    color: #12344d;
    border-bottom: 1px solid #eef3f8;
    white-space: nowrap;
    word-break: normal;
    overflow-wrap: normal;
  }
  .info-row:last-child .info-label,
  .info-row:last-child .info-value { border-bottom: none; }
  .info-value.empty { color: #bbb; font-style: italic; }
  .memo-box {
    background: #f8fbfe;
    border: 1px solid #d7e3ef;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    color: #243447;
    line-height: 1.7;
    white-space: pre-wrap;
    min-height: 50px;
  }
  .memo-box.empty { color: #bbb; font-style: italic; }
  .card-actions {
    padding: 14px 24px;
    border-top: 1px solid #eef3f8;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
  .edit-card { border: 2px solid #2a7ab8; scroll-margin-top: 20px; }
  .edit-card-head {
    padding: 16px 24px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .edit-card-head .title { font-size: 15px; font-weight: 700; color: #12344d; }
  .edit-card-head .close-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 20px;
    color: #aab8c6;
    line-height: 1;
    padding: 2px 6px;
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
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #c8d8e8;
    border-radius: 9px;
    font-size: 13px;
    font-family: inherit;
    color: #243447;
    background: #fff;
  }
  .field input:focus, .field select:focus, .field textarea:focus {
    outline: none;
    border-color: #2a7ab8;
    box-shadow: 0 0 0 3px rgba(42,122,184,0.12);
  }
  .field textarea { resize: vertical; min-height: 60px; }
  .field-full { grid-column: 1 / -1; }
  .form-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .msg { padding: 10px 14px; border-radius: 9px; font-size: 13px; margin-bottom: 14px; }
  .msg-error { background: #fdf2f2; color: #c0392b; border: 1px solid #f5b7b1; }
  .msg-success { background: #edfaf4; color: #1a7a4a; border: 1px solid #a8e6c3; }
  @media (max-width: 768px) {
    .shell { max-width: 100%; }
    .info-grid { grid-template-columns: 1fr; column-gap: 0; }
    .info-label, .info-value { white-space: normal; }
    .info-value { padding-left: 0; }
  }
</style>
</head>
<body>
<div class="shell">
  <div class="page-head">
    <a href="index.php" class="btn btn-secondary">직원목록으로</a>
  </div>

  <?php if ($success !== ''): ?>
    <div class="msg msg-success"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="msg msg-error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card" id="detail-card">
    <?php [$hdrColor, $bdgBg] = team_badge_colors(trim((string)($emp['team'] ?? ''))); ?>
    <div class="profile-header" style="background:linear-gradient(135deg,<?= $hdrColor ?> 0%,<?= $hdrColor ?>cc 100%)">
      <div class="avatar"><?= mb_substr(strip_tags(display_name((string)$emp['name'])), 0, 1, 'UTF-8') ?></div>
      <div class="profile-info">
        <h1><?= display_name((string)$emp['name']) ?></h1>
        <div class="sub">
          <?php if (val($emp['employee_no']) !== ''): ?>
            <span>사원번호: <?= val($emp['employee_no']) ?></span>
          <?php endif; ?>
          <?php if (val($emp['team']) !== ''): ?>
            <span class="badge" style="background:<?= $bdgBg ?>"><?= val($emp['team']) ?></span>
          <?php endif; ?>
          <?php if (val($emp['position']) !== ''): ?>
            <span class="badge" style="background:<?= $bdgBg ?>"><?= val($emp['position']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="info-section">
      <div class="info-section-title">기본 정보</div>
      <div class="info-grid">
        <?php
        $fields = [
            '역할' => $emp['position'],
            '직책' => $emp['job_title'],
            '연락처' => $emp['phone'],
            '이메일' => $emp['email'],
            '재직상태' => employment_status_text($emp),
            '입사일' => $emp['join_date'],
            '재직기간' => employment_duration_text($emp),
            '퇴사일' => $emp['retired_at'] ?? '',
            '퇴사사유' => $emp['retired_reason'] ?? '',
            '생년월일' => $emp['birth_date'],
            '비상연락처' => $emp['emergency_contact'],
            '비상연락처 관계' => $emp['emergency_contact_relation'],
            '주소' => $emp['address'],
        ];
        foreach ($fields as $label => $value):
            $v = val($value);
        ?>
          <div class="info-row">
            <div class="info-label"><?= h($label) ?></div>
            <div class="info-value <?= $v === '' ? 'empty' : '' ?>"><?= $v !== '' ? $v : '-' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="info-section" style="padding-top:0">
      <div class="info-section-title">신체 정보</div>
      <div class="info-grid">
        <?php
        $bodyFields = [
            '키' => ($emp['height'] ?? '') !== '' ? val($emp['height']) . ' cm' : '',
            '몸무게' => ($emp['weight'] ?? '') !== '' ? val($emp['weight']) . ' kg' : '',
            '신발 사이즈' => ($emp['shoe_size'] ?? '') !== '' ? val($emp['shoe_size']) . ' mm' : '',
            '혈액형' => ($emp['blood_type'] ?? '') !== '' ? val($emp['blood_type']) . '형' : '',
            '동복 상의' => $emp['uniform_winter_top'] ?? '',
            '동복 하의' => $emp['uniform_winter_bottom'] ?? '',
            '춘추복 상의' => $emp['uniform_spring_top'] ?? '',
            '춘추복 하의' => $emp['uniform_spring_bottom'] ?? '',
            '하복 상의' => $emp['uniform_summer_top'] ?? '',
            '하복 하의' => $emp['uniform_summer_bottom'] ?? '',
            '반팔티' => $emp['uniform_shortsleeve'] ?? '',
        ];
        if (($emp['team'] ?? '') === '가스팀') {
            $bodyFields += [
                '방열복 상의' => $emp['uniform_heat_top'] ?? '',
                '방열복 하의' => $emp['uniform_heat_bottom'] ?? '',
            ];
        }
        foreach ($bodyFields as $label => $value):
            $v = val($value);
        ?>
          <div class="info-row">
            <div class="info-label"><?= h($label) ?></div>
            <div class="info-value <?= $v === '' ? 'empty' : '' ?>"><?= $v !== '' ? $v : '-' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="info-section" style="padding-top:0">
      <div class="info-section-title">첨부 서류</div>
      <table class="doc-table">
        <thead>
          <tr>
            <th style="width:36%">서류명</th>
            <th style="width:14%">상태</th>
            <th>파일명</th>
            <th style="width:180px">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($docTypeLabels as $dtype => $dlabel):
              $doc = $docMap[$dtype] ?? null;
          ?>
          <tr>
            <td><?= h($dlabel) ?></td>
            <td><?= $doc ? '<span class="doc-status-ok">등록됨</span>' : '<span class="doc-status-no">미등록</span>' ?></td>
            <td style="color:#486581;font-size:12px"><?= $doc ? h((string)$doc['original_name']) : '-' ?></td>
            <td>
              <div class="doc-actions">
                <?php if ($doc): ?>
                  <a href="?id=<?= $id ?>&download=<?= (int)$doc['id'] ?>" class="btn btn-sm btn-download">다운로드</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('서류를 삭제하시겠습니까?')">
                    <input type="hidden" name="action" value="delete_doc">
                    <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">삭제</button>
                  </form>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" style="display:inline">
                  <input type="hidden" name="action" value="upload_doc">
                  <input type="hidden" name="doc_type" value="<?= h($dtype) ?>">
                  <label class="doc-upload-label">
                    파일 <?= $doc ? '재업로드' : '업로드' ?>
                    <input type="file" name="doc_file" class="doc-upload-input" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx" onchange="this.form.submit()">
                  </label>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="info-section" style="padding-top:0">
      <div class="info-section-title">메모</div>
      <?php $memo = val($emp['memo']); ?>
      <div class="memo-box <?= $memo === '' ? 'empty' : '' ?>"><?= $memo !== '' ? $memo : '메모가 없습니다.' ?></div>
    </div>

    <div class="info-section" style="padding-top:0; padding-bottom:14px;">
      <div style="font-size:11px; color:#aab8c6; text-align:right;">
        등록: <?= val((string)($emp['created_at'] ?? '')) ?> &nbsp;|&nbsp; 최종수정: <?= val((string)($emp['updated_at'] ?? '')) ?>
      </div>
    </div>

    <div class="card-actions">
      <button type="button" class="btn btn-primary" onclick="toggleEdit()">수정</button>
      <?php if (((string)($emp['employment_status'] ?? 'active')) === 'retired' || (int)($emp['is_active'] ?? 1) === 0): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="restore">
          <button type="submit" class="btn btn-secondary">복원</button>
        </form>
      <?php else: ?>
        <form method="post" style="display:inline" onsubmit="return confirm('<?= val((string)($emp['name'] ?? '')) ?> 직원을 퇴사 처리하시겠습니까?')">
          <input type="hidden" name="action" value="retire">
          <input type="hidden" name="retired_at" value="<?= h(date('Y-m-d')) ?>">
          <input type="hidden" name="retired_reason" value="">
          <button type="submit" class="btn btn-secondary">퇴사처리</button>
        </form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('<?= val((string)($emp['name'] ?? '')) ?> 직원을 삭제하시겠습니까?')" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger">삭제</button>
      </form>
    </div>
  </div>

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
                <option value="<?= val((string)$t) ?>" <?= val((string)($emp['team'] ?? '')) === val((string)$t) ? 'selected' : '' ?>><?= val((string)$t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>역할</label>
            <input type="text" name="position" value="<?= val($emp['position']) ?>" placeholder="예: 작업지시자">
          </div>
          <div class="field">
            <label>직책</label>
            <input type="text" name="job_title" value="<?= val($emp['job_title'] ?? '') ?>" placeholder="예: 과장">
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
            <label>재직상태</label>
            <?php $employmentStatusValue = (string)($emp['employment_status'] ?? 'active'); ?>
            <select name="employment_status">
              <option value="active"<?= $employmentStatusValue !== 'retired' ? ' selected' : '' ?>>재직중</option>
              <option value="retired"<?= $employmentStatusValue === 'retired' ? ' selected' : '' ?>>퇴사</option>
            </select>
          </div>
          <div class="field">
            <label>퇴사일</label>
            <input type="date" name="retired_at" value="<?= val($emp['retired_at'] ?? '') ?>">
          </div>
          <div class="field">
            <label>퇴사사유</label>
            <input type="text" name="retired_reason" value="<?= val($emp['retired_reason'] ?? '') ?>" placeholder="예: 계약만료, 자진퇴사">
          </div>
          <div class="field">
            <label>생년월일</label>
            <input type="date" name="birth_date" value="<?= val($emp['birth_date']) ?>">
          </div>
          <div class="field">
            <label>비상연락처</label>
            <input type="tel" name="emergency_contact" value="<?= val($emp['emergency_contact']) ?>" placeholder="010-0000-0000">
          </div>
          <div class="field">
            <label>비상연락처 관계</label>
            <input type="text" name="emergency_contact_relation" value="<?= val($emp['emergency_contact_relation'] ?? '') ?>" placeholder="예: 배우자">
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

        <div style="font-size:13px;font-weight:700;color:#486581;margin:4px 0 10px;padding-top:10px;border-top:1px solid #e8f0f8;">신체 정보</div>
        <div class="form-grid">
          <div class="field">
            <label>키(cm)</label>
            <input type="text" name="height" value="<?= val($emp['height'] ?? '') ?>" placeholder="예: 175">
          </div>
          <div class="field">
            <label>몸무게(kg)</label>
            <input type="text" name="weight" value="<?= val($emp['weight'] ?? '') ?>" placeholder="예: 70">
          </div>
          <div class="field">
            <label>신발 사이즈(mm)</label>
            <input type="text" name="shoe_size" value="<?= val($emp['shoe_size'] ?? '') ?>" placeholder="예: 270">
          </div>
          <div class="field">
            <label>혈액형</label>
            <select name="blood_type">
              <option value="">선택</option>
              <?php foreach (['A', 'B', 'O', 'AB'] as $bt): ?>
                <option value="<?= $bt ?>" <?= val($emp['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?>형</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>동복 상의</label>
            <input type="text" name="uniform_winter_top" value="<?= val($emp['uniform_winter_top'] ?? '') ?>" placeholder="예: 105">
          </div>
          <div class="field">
            <label>동복 하의</label>
            <input type="text" name="uniform_winter_bottom" value="<?= val($emp['uniform_winter_bottom'] ?? '') ?>" placeholder="예: 32">
          </div>
          <div class="field">
            <label>춘추복 상의</label>
            <input type="text" name="uniform_spring_top" value="<?= val($emp['uniform_spring_top'] ?? '') ?>" placeholder="예: 105">
          </div>
          <div class="field">
            <label>춘추복 하의</label>
            <input type="text" name="uniform_spring_bottom" value="<?= val($emp['uniform_spring_bottom'] ?? '') ?>" placeholder="예: 32">
          </div>
          <div class="field">
            <label>하복 상의</label>
            <input type="text" name="uniform_summer_top" value="<?= val($emp['uniform_summer_top'] ?? '') ?>" placeholder="예: 105">
          </div>
          <div class="field">
            <label>하복 하의</label>
            <input type="text" name="uniform_summer_bottom" value="<?= val($emp['uniform_summer_bottom'] ?? '') ?>" placeholder="예: 32">
          </div>
          <div class="field">
            <label>반팔티</label>
            <input type="text" name="uniform_shortsleeve" value="<?= val($emp['uniform_shortsleeve'] ?? '') ?>" placeholder="예: XL">
          </div>
          <?php if (($emp['team'] ?? '') === '가스팀'): ?>
          <div class="field">
            <label>방열복 상의</label>
            <input type="text" name="uniform_heat_top" value="<?= val($emp['uniform_heat_top'] ?? '') ?>" placeholder="예: 105">
          </div>
          <div class="field">
            <label>방열복 하의</label>
            <input type="text" name="uniform_heat_bottom" value="<?= val($emp['uniform_heat_bottom'] ?? '') ?>" placeholder="예: 32">
          </div>
          <?php endif; ?>
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
  const show = forceShow !== undefined ? forceShow : card.style.display === 'none';
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
