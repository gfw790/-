<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/auth.php';

function h($str): string
{
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$user = auth_require_login();
$pdo = getDB();

$selectedUnitRaId = isset($_GET['unit_ra_id']) ? (int)$_GET['unit_ra_id'] : (int)($_POST['unit_ra_id'] ?? 0);
$selectedTask = null;

if ($selectedUnitRaId > 0) {
    $stmt = $pdo->prepare("
        SELECT unit_ra_id, unit_code, unit_title, process_name, unit_type
        FROM unit_ra_header
        WHERE unit_ra_id = :unit_ra_id
          AND use_yn = 'Y'
        LIMIT 1
    ");
    $stmt->execute([':unit_ra_id' => $selectedUnitRaId]);
    $selectedTask = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$defaults = [
    'work_date' => date('Y-m-d'),
    'work_title' => $selectedTask['unit_title'] ?? '',
    'work_location' => $selectedTask['process_name'] ?? '',
    'contractor_name' => '',
    'manager_name' => in_array((string)($user['role'] ?? ''), ['manager', 'safety_manager'], true) ? $user['name'] : '',
    'leader_name' => $user['role'] === 'leader' ? $user['name'] : '',
    'remark' => '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_date       = trim((string)($_POST['work_date'] ?? ''));
    $work_title      = trim((string)($_POST['work_title'] ?? ''));
    $work_location   = trim((string)($_POST['work_location'] ?? ''));
    $contractor_name = trim((string)($_POST['contractor_name'] ?? ''));
    $manager_name    = trim((string)($_POST['manager_name'] ?? ''));
    $leader_name     = trim((string)($_POST['leader_name'] ?? ''));
    $remark          = trim((string)($_POST['remark'] ?? ''));

    $major_work_ids = $_POST['major_work_ids'] ?? [];
    $target_ids     = $_POST['target_ids'] ?? [];
    $env_ids        = $_POST['env_ids'] ?? [];
    $tool_ids       = $_POST['tool_ids'] ?? [];

    $defaults = [
        'work_date' => $work_date,
        'work_title' => $work_title,
        'work_location' => $work_location,
        'contractor_name' => $contractor_name,
        'manager_name' => $manager_name,
        'leader_name' => $leader_name,
        'remark' => $remark,
    ];

    if ($work_date === '') {
        $errors[] = '작업일자를 입력해주세요.';
    }
    if ($work_title === '') {
        $errors[] = '작업명을 입력해주세요.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO ra_header (
                    work_date,
                    work_title,
                    work_location,
                    contractor_name,
                    manager_name,
                    leader_name,
                    remark
                ) VALUES (
                    :work_date,
                    :work_title,
                    :work_location,
                    :contractor_name,
                    :manager_name,
                    :leader_name,
                    :remark
                )
            ");
            $stmt->execute([
                ':work_date' => $work_date,
                ':work_title' => $work_title,
                ':work_location' => $work_location ?: null,
                ':contractor_name' => $contractor_name ?: null,
                ':manager_name' => $manager_name ?: null,
                ':leader_name' => $leader_name ?: null,
                ':remark' => $remark ?: null,
            ]);

            $raId = (int)$pdo->lastInsertId();

            if (!empty($major_work_ids)) {
                $stmt = $pdo->prepare("INSERT INTO ra_major_work (ra_id, major_work_id) VALUES (:ra_id, :major_work_id)");
                foreach ($major_work_ids as $id) {
                    $stmt->execute([
                        ':ra_id' => $raId,
                        ':major_work_id' => (int)$id,
                    ]);
                }
            }

            if (!empty($target_ids)) {
                $stmt = $pdo->prepare("INSERT INTO ra_target (ra_id, target_id) VALUES (:ra_id, :target_id)");
                foreach ($target_ids as $id) {
                    $stmt->execute([
                        ':ra_id' => $raId,
                        ':target_id' => (int)$id,
                    ]);
                }
            }

            if (!empty($env_ids)) {
                $stmt = $pdo->prepare("INSERT INTO ra_env (ra_id, env_id) VALUES (:ra_id, :env_id)");
                foreach ($env_ids as $id) {
                    $stmt->execute([
                        ':ra_id' => $raId,
                        ':env_id' => (int)$id,
                    ]);
                }
            }

            if (!empty($tool_ids)) {
                $stmt = $pdo->prepare("INSERT INTO ra_tool (ra_id, tool_id) VALUES (:ra_id, :tool_id)");
                foreach ($tool_ids as $id) {
                    $stmt->execute([
                        ':ra_id' => $raId,
                        ':tool_id' => (int)$id,
                    ]);
                }
            }

            $pdo->commit();
            header('Location: task_select.php?saved_ra_id=' . $raId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = '저장 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

$majorWorks = $pdo->query("SELECT major_work_id, major_work_name FROM major_work_master WHERE use_yn = 'Y' ORDER BY sort_no, major_work_id")->fetchAll(PDO::FETCH_ASSOC);
$targets = $pdo->query("SELECT target_id, process_category, major_category, work_type FROM work_target_master WHERE use_yn = 'Y' ORDER BY sort_no, target_id")->fetchAll(PDO::FETCH_ASSOC);
$envs = $pdo->query("SELECT env_id, env_name FROM env_master WHERE use_yn = 'Y' ORDER BY sort_no, env_id")->fetchAll(PDO::FETCH_ASSOC);
$tools = $pdo->query("SELECT tool_id, tool_name FROM tool_master WHERE use_yn = 'Y' ORDER BY sort_no, tool_id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>위험성평가 등록</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "맑은 고딕", sans-serif;
            background: #f0f4f8;
            color: #243447;
            padding: 30px 20px 60px;
        }
        .wrap {
            max-width: 1120px;
            margin: 0 auto;
        }
        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .page-head h1 {
            font-size: 28px;
            color: #12344d;
            margin-bottom: 8px;
        }
        .page-head p {
            color: #52667a;
            line-height: 1.6;
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-link,
        .btn-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border-radius: 10px;
            padding: 11px 18px;
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
        }
        .btn-link {
            background: #fff;
            color: #486581;
            border: 1px solid #c8d8e8;
        }
        .btn-link:hover { background: #f5f9fc; }
        .btn-submit {
            border: none;
            background: #2e75b6;
            color: #fff;
            font-weight: bold;
        }
        .btn-submit:hover { background: #1f4e79; }
        .alert,
        .task-banner,
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(18, 52, 77, 0.08);
            border: 1px solid #dde7f0;
        }
        .alert {
            margin-bottom: 16px;
            padding: 16px 18px;
            color: #a61b1b;
            background: #fff2f2;
            border-left: 4px solid #d64545;
        }
        .task-banner {
            padding: 18px 20px;
            margin-bottom: 18px;
            background: linear-gradient(135deg, #f5fbff, #ffffff);
        }
        .task-banner strong {
            color: #1f4e79;
            display: block;
            margin-bottom: 6px;
        }
        .task-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ebf3fb;
            color: #1f4e79;
            font-size: 12px;
        }
        .card {
            overflow: hidden;
        }
        .card-head {
            padding: 18px 22px;
            border-bottom: 1px solid #e6eef5;
            background: #f8fbfd;
            font-weight: bold;
            color: #1f4e79;
        }
        .card-body {
            padding: 22px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .field.full {
            grid-column: 1 / -1;
        }
        label {
            font-size: 13px;
            font-weight: bold;
            color: #1f4e79;
        }
        input[type="text"],
        input[type="date"],
        textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #c8d8e8;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            background: #fff;
        }
        input:focus,
        textarea:focus {
            border-color: #2e75b6;
            box-shadow: 0 0 0 3px rgba(46,117,182,0.12);
        }
        textarea {
            min-height: 90px;
            resize: vertical;
        }
        .section {
            margin-top: 18px;
        }
        .section h2 {
            font-size: 17px;
            color: #12344d;
            margin-bottom: 12px;
        }
        .check-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
        }
        .check-item {
            border: 1px solid #d9e5f0;
            border-radius: 12px;
            background: #fbfdff;
        }
        .check-item label {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px 14px;
            cursor: pointer;
            color: #334e68;
            font-weight: normal;
            line-height: 1.5;
        }
        .check-item input {
            margin-top: 2px;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        @media (max-width: 800px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="page-head">
        <div>
            <h1>위험성평가 등록</h1>
            <p><?= h($user['role_label']) ?>로 로그인 중이며, 선택한 작업을 기준으로 등록을 이어갑니다.</p>
        </div>
        <div class="actions">
            <a class="btn-link" href="task_select.php">작업 선택으로 돌아가기</a>
            <a class="btn-link" href="task_select.php?logout=1">로그아웃</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert">
            <?php foreach ($errors as $error): ?>
                <div><?= h($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedTask !== null): ?>
        <div class="task-banner">
            <strong>선택한 기준 작업</strong>
            <?= h($selectedTask['unit_title']) ?>
            <div class="task-meta">
                <span class="chip">코드 <?= h($selectedTask['unit_code'] ?: '-') ?></span>
                <span class="chip">공정 <?= h($selectedTask['process_name'] ?: '-') ?></span>
                <span class="chip">유형 <?= h($selectedTask['unit_type']) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" class="card">
        <input type="hidden" name="unit_ra_id" value="<?= (int)$selectedUnitRaId ?>">
        <div class="card-head">기본 정보</div>
        <div class="card-body">
            <div class="grid">
                <div class="field">
                    <label for="work_date">작업일자</label>
                    <input type="date" name="work_date" id="work_date" value="<?= h($defaults['work_date']) ?>">
                </div>
                <div class="field">
                    <label for="contractor_name">도급사명</label>
                    <input type="text" name="contractor_name" id="contractor_name" value="<?= h($defaults['contractor_name']) ?>">
                </div>
                <div class="field full">
                    <label for="work_title">작업명</label>
                    <input type="text" name="work_title" id="work_title" value="<?= h($defaults['work_title']) ?>">
                </div>
                <div class="field full">
                    <label for="work_location">작업위치 / 공정</label>
                    <input type="text" name="work_location" id="work_location" value="<?= h($defaults['work_location']) ?>">
                </div>
                <div class="field">
                    <label for="manager_name">관리감독자</label>
                    <input type="text" name="manager_name" id="manager_name" value="<?= h($defaults['manager_name']) ?>">
                </div>
                <div class="field">
                    <label for="leader_name">작업지휘자(작업반장)</label>
                    <input type="text" name="leader_name" id="leader_name" value="<?= h($defaults['leader_name']) ?>">
                </div>
                <div class="field full">
                    <label for="remark">비고</label>
                    <textarea name="remark" id="remark"><?= h($defaults['remark']) ?></textarea>
                </div>
            </div>

            <div class="section">
                <h2>주요작업 선택</h2>
                <div class="check-group">
                    <?php foreach ($majorWorks as $row): ?>
                        <div class="check-item">
                            <label>
                                <input type="checkbox" name="major_work_ids[]" value="<?= (int)$row['major_work_id'] ?>">
                                <span><?= h($row['major_work_name']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section">
                <h2>작업대상 선택</h2>
                <div class="check-group">
                    <?php foreach ($targets as $row): ?>
                        <div class="check-item">
                            <label>
                                <input type="checkbox" name="target_ids[]" value="<?= (int)$row['target_id'] ?>">
                                <span>
                                    <?= h($row['process_category']) ?>
                                    <?php if (!empty($row['major_category'])): ?>
                                        / <?= h($row['major_category']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($row['work_type'])): ?>
                                        / <?= h($row['work_type']) ?>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section">
                <h2>작업환경 선택</h2>
                <div class="check-group">
                    <?php foreach ($envs as $row): ?>
                        <div class="check-item">
                            <label>
                                <input type="checkbox" name="env_ids[]" value="<?= (int)$row['env_id'] ?>">
                                <span><?= h($row['env_name']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section">
                <h2>사용공구 선택</h2>
                <div class="check-group">
                    <?php foreach ($tools as $row): ?>
                        <div class="check-item">
                            <label>
                                <input type="checkbox" name="tool_ids[]" value="<?= (int)$row['tool_id'] ?>">
                                <span><?= h($row['tool_name']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-actions">
                <a class="btn-link" href="task_select.php">다른 작업 선택</a>
                <button type="submit" class="btn-submit">저장 후 작업조건 화면으로 이동</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>
