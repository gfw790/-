<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

$user = auth_require_login();
$pdo = getDB();

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensureWorkReportTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report (
            report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            role_code VARCHAR(30) NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            team_name VARCHAR(100) NULL,
            work_title VARCHAR(255) NOT NULL,
            work_date DATE NOT NULL,
            work_place VARCHAR(255) NOT NULL,
            use_equipment_yn CHAR(1) NOT NULL DEFAULT 'N',
            note_html MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (report_id),
            KEY idx_work_report_unit_ra_id (unit_ra_id),
            KEY idx_work_report_work_date (work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try {
        $pdo->exec("ALTER TABLE work_report ADD COLUMN team_name VARCHAR(100) NULL AFTER user_name");
    } catch (Throwable $e) {
        // Column already exists.
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_task (
            report_task_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NULL,
            task_name VARCHAR(255) NOT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            PRIMARY KEY (report_task_id),
            KEY idx_work_report_task_report_id (report_id),
            CONSTRAINT fk_work_report_task_report
                FOREIGN KEY (report_id) REFERENCES work_report(report_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_tool (
            report_tool_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            tool_id INT NOT NULL,
            tool_name VARCHAR(255) NOT NULL,
            PRIMARY KEY (report_tool_id),
            KEY idx_work_report_tool_report_id (report_id),
            CONSTRAINT fk_work_report_tool_report
                FOREIGN KEY (report_id) REFERENCES work_report(report_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_image (
            report_image_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_image_id),
            KEY idx_work_report_image_report_id (report_id),
            CONSTRAINT fk_work_report_image_report
                FOREIGN KEY (report_id) REFERENCES work_report(report_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function decodeDataUrlImage(string $dataUrl): ?array
{
    if (!preg_match('#^data:image/(png|jpeg|jpg|gif);base64,(.+)$#', $dataUrl, $matches)) {
        return null;
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        return null;
    }

    $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
    return [
        'ext' => $ext,
        'binary' => $binary,
    ];
}

function sanitizeNoteHtml(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html) ?? '';
    $html = preg_replace('/\son\w+="[^"]*"/i', '', $html) ?? '';
    $html = preg_replace("/\son\w+='[^']*'/i", '', $html) ?? '';
    return trim(strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><div><span>'));
}

ensureWorkReportTables($pdo);

$unitRaId = isset($_GET['unit_ra_id']) ? (int)$_GET['unit_ra_id'] : (int)($_POST['unit_ra_id'] ?? 0);
$savedId = isset($_GET['saved_id']) ? (int)$_GET['saved_id'] : 0;
$errors = [];
$savedReport = null;

$unit = null;
if ($unitRaId > 0) {
    $stmt = $pdo->prepare("
        SELECT unit_ra_id, unit_code, unit_title, process_name, unit_type
        FROM unit_ra_header
        WHERE unit_ra_id = :unit_ra_id
          AND use_yn = 'Y'
        LIMIT 1
    ");
    $stmt->execute([':unit_ra_id' => $unitRaId]);
    $unit = $stmt->fetch();
}

$taskRows = [];
if ($unitRaId > 0) {
    $stmt = $pdo->prepare("
        SELECT item_id, task_name, sort_no
        FROM unit_ra_item
        WHERE unit_ra_id = :unit_ra_id
          AND use_yn = 'Y'
        ORDER BY sort_no ASC, item_id ASC
    ");
    $stmt->execute([':unit_ra_id' => $unitRaId]);
    $taskRows = $stmt->fetchAll();
}

$tools = $pdo->query("
    SELECT tool_id, tool_name
    FROM tool_master
    WHERE use_yn = 'Y'
    ORDER BY sort_no ASC, tool_id ASC
")->fetchAll();

$defaults = [
    'work_title' => $unit['unit_title'] ?? '',
    'work_date' => date('Y-m-d'),
    'work_place' => $unit['process_name'] ?? '',
    'selected_tasks' => [],
    'use_equipment_yn' => 'N',
    'selected_tools' => [],
    'note_html' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaults['work_title'] = trim((string)($_POST['work_title'] ?? ''));
    $defaults['work_date'] = trim((string)($_POST['work_date'] ?? ''));
    $defaults['work_place'] = trim((string)($_POST['work_place'] ?? ''));
    $defaults['selected_tasks'] = array_map('intval', $_POST['selected_tasks'] ?? []);
    $defaults['use_equipment_yn'] = ($_POST['use_equipment_yn'] ?? 'N') === 'Y' ? 'Y' : 'N';
    $defaults['selected_tools'] = array_map('intval', $_POST['selected_tools'] ?? []);
    $defaults['note_html'] = sanitizeNoteHtml((string)($_POST['note_html'] ?? ''));
    $pastedImages = json_decode((string)($_POST['pasted_images'] ?? '[]'), true);
    if (!is_array($pastedImages)) {
        $pastedImages = [];
    }

    if ($unit === null) {
        $errors[] = '기준 작업을 먼저 선택해주세요.';
    }
    if ($defaults['work_title'] === '') {
        $errors[] = '작업명을 입력해주세요.';
    }
    if ($defaults['work_date'] === '') {
        $errors[] = '작업일자를 입력해주세요.';
    }
    if ($defaults['work_place'] === '') {
        $errors[] = '작업장소를 입력해주세요.';
    }
    if (empty($defaults['selected_tasks'])) {
        $errors[] = '작업내용을 1개 이상 선택해주세요.';
    }
    if ($defaults['use_equipment_yn'] === 'Y' && empty($defaults['selected_tools'])) {
        $errors[] = '장비사용을 체크한 경우 장비를 선택해주세요.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO work_report (
                    unit_ra_id,
                    role_code,
                    user_login_id,
                    user_name,
                    team_name,
                    work_title,
                    work_date,
                    work_place,
                    use_equipment_yn,
                    note_html
                ) VALUES (
                    :unit_ra_id,
                    :role_code,
                    :user_login_id,
                    :user_name,
                    :team_name,
                    :work_title,
                    :work_date,
                    :work_place,
                    :use_equipment_yn,
                    :note_html
                )
            ");
            $stmt->execute([
                ':unit_ra_id' => $unitRaId,
                ':role_code' => $user['role'],
                ':user_login_id' => $user['login_id'],
                ':user_name' => $user['name'],
                ':team_name' => auth_normalize_team_name((string)($user['team'] ?? '')) ?: null,
                ':work_title' => $defaults['work_title'],
                ':work_date' => $defaults['work_date'],
                ':work_place' => $defaults['work_place'],
                ':use_equipment_yn' => $defaults['use_equipment_yn'],
                ':note_html' => $defaults['note_html'] !== '' ? $defaults['note_html'] : null,
            ]);

            $reportId = (int)$pdo->lastInsertId();

            if (!empty($defaults['selected_tasks'])) {
                $taskMap = [];
                foreach ($taskRows as $row) {
                    $taskMap[(int)$row['item_id']] = $row;
                }

                $taskStmt = $pdo->prepare("
                    INSERT INTO work_report_task (
                        report_id,
                        item_id,
                        task_name,
                        sort_no
                    ) VALUES (
                        :report_id,
                        :item_id,
                        :task_name,
                        :sort_no
                    )
                ");

                foreach ($defaults['selected_tasks'] as $taskId) {
                    if (!isset($taskMap[$taskId])) {
                        continue;
                    }
                    $taskStmt->execute([
                        ':report_id' => $reportId,
                        ':item_id' => $taskId,
                        ':task_name' => $taskMap[$taskId]['task_name'],
                        ':sort_no' => (int)$taskMap[$taskId]['sort_no'],
                    ]);
                }
            }

            if ($defaults['use_equipment_yn'] === 'Y' && !empty($defaults['selected_tools'])) {
                $toolMap = [];
                foreach ($tools as $tool) {
                    $toolMap[(int)$tool['tool_id']] = $tool;
                }

                $toolStmt = $pdo->prepare("
                    INSERT INTO work_report_tool (
                        report_id,
                        tool_id,
                        tool_name
                    ) VALUES (
                        :report_id,
                        :tool_id,
                        :tool_name
                    )
                ");

                foreach ($defaults['selected_tools'] as $toolId) {
                    if (!isset($toolMap[$toolId])) {
                        continue;
                    }
                    $toolStmt->execute([
                        ':report_id' => $reportId,
                        ':tool_id' => $toolId,
                        ':tool_name' => $toolMap[$toolId]['tool_name'],
                    ]);
                }
            }

            if (!empty($pastedImages)) {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'work_report';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('이미지 저장 폴더를 만들 수 없습니다.');
                }

                $imgStmt = $pdo->prepare("
                    INSERT INTO work_report_image (
                        report_id,
                        file_name,
                        file_path,
                        sort_no
                    ) VALUES (
                        :report_id,
                        :file_name,
                        :file_path,
                        :sort_no
                    )
                ");

                foreach ($pastedImages as $index => $dataUrl) {
                    if (!is_string($dataUrl)) {
                        continue;
                    }
                    $image = decodeDataUrlImage($dataUrl);
                    if ($image === null) {
                        continue;
                    }

                    $fileName = sprintf('report_%d_%02d.%s', $reportId, $index + 1, $image['ext']);
                    $fullPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                    file_put_contents($fullPath, $image['binary']);

                    $relativePath = 'uploads/work_report/' . $fileName;
                    $imgStmt->execute([
                        ':report_id' => $reportId,
                        ':file_name' => $fileName,
                        ':file_path' => $relativePath,
                        ':sort_no' => $index + 1,
                    ]);
                }
            }

            $pdo->commit();
            header('Location: work_report_form.php?unit_ra_id=' . $unitRaId . '&saved_id=' . $reportId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = '저장 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

if ($savedId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM work_report
        WHERE report_id = :report_id
        LIMIT 1
    ");
    $stmt->execute([':report_id' => $savedId]);
    $savedReport = $stmt->fetch();

    if ($savedReport) {
        $taskStmt = $pdo->prepare("SELECT task_name FROM work_report_task WHERE report_id = :report_id ORDER BY sort_no ASC, report_task_id ASC");
        $taskStmt->execute([':report_id' => $savedId]);
        $savedReport['tasks'] = $taskStmt->fetchAll(PDO::FETCH_COLUMN);

        $toolStmt = $pdo->prepare("SELECT tool_name FROM work_report_tool WHERE report_id = :report_id ORDER BY report_tool_id ASC");
        $toolStmt->execute([':report_id' => $savedId]);
        $savedReport['tools'] = $toolStmt->fetchAll(PDO::FETCH_COLUMN);

        $imgStmt = $pdo->prepare("SELECT file_path, file_name FROM work_report_image WHERE report_id = :report_id ORDER BY sort_no ASC, report_image_id ASC");
        $imgStmt->execute([':report_id' => $savedId]);
        $savedReport['images'] = $imgStmt->fetchAll();
    }
}

$roleLabel = auth_can_manage($user) ? '관리감독자' : (auth_can_lead($user) ? '작업지휘자(작업반장)' : auth_role_label((string)($user['role'] ?? '')));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($roleLabel) ?> 작업 확인 및 저장</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "맑은 고딕", sans-serif;
    background: #eef4f8;
    color: #243447;
    padding: 30px 18px 50px;
  }
  .wrap { max-width: 1120px; margin: 0 auto; }
  .header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }
  .header h1 {
    font-size: 28px;
    color: #12344d;
    margin-bottom: 6px;
  }
  .header p { color: #52667a; line-height: 1.7; }
  .toolbar { display: flex; gap: 8px; flex-wrap: wrap; }
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
  .btn-submit {
    border: none;
    background: #2e75b6;
    color: #fff;
    font-weight: bold;
  }
  .btn-submit:hover { background: #1f4e79; }
  .card,
  .banner,
  .alert,
  .success {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #dbe5ee;
    box-shadow: 0 12px 28px rgba(18, 52, 77, 0.08);
  }
  .banner {
    padding: 18px 20px;
    margin-bottom: 18px;
  }
  .banner strong {
    display: block;
    color: #1f4e79;
    margin-bottom: 8px;
  }
  .chip-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
  .chip {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    background: #ebf3fb;
    color: #1f4e79;
    font-size: 12px;
  }
  .alert, .success { padding: 14px 16px; margin-bottom: 16px; }
  .alert { background: #fff1f1; color: #a61b1b; border-left: 4px solid #d64545; }
  .success { background: #ecf9f0; color: #1d6b3a; border-left: 4px solid #2d9d57; }
  .card { overflow: hidden; }
  .card-head {
    padding: 16px 20px;
    background: #f7fafc;
    border-bottom: 1px solid #e4edf5;
    color: #1f4e79;
    font-weight: bold;
  }
  .card-body { padding: 22px; }
  .grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 20px;
  }
  .field { display: flex; flex-direction: column; gap: 7px; }
  .field.full { grid-column: 1 / -1; }
  label {
    font-size: 13px;
    font-weight: bold;
    color: #1f4e79;
  }
  input[type="text"],
  input[type="date"] {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #c8d8e8;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    background: #fff;
  }
  input:focus { border-color: #2e75b6; box-shadow: 0 0 0 3px rgba(46,117,182,0.12); }
  .section { margin-top: 22px; }
  .section h2 {
    font-size: 17px;
    color: #12344d;
    margin-bottom: 12px;
  }
  .list-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
    line-height: 1.6;
  }
  .note-board {
    border: 1px solid #c8d8e8;
    border-radius: 12px;
    background: #fff;
    min-height: 180px;
    padding: 14px;
    line-height: 1.7;
    outline: none;
    overflow: auto;
  }
  .note-board:focus {
    border-color: #2e75b6;
    box-shadow: 0 0 0 3px rgba(46,117,182,0.12);
  }
  .note-help {
    margin-top: 8px;
    color: #6b7c93;
    font-size: 12px;
  }
  .paste-preview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-top: 14px;
  }
  .paste-preview figure {
    background: #fbfdff;
    border: 1px solid #d9e5f0;
    border-radius: 12px;
    padding: 10px;
  }
  .paste-preview img {
    width: 100%;
    height: 140px;
    object-fit: cover;
    border-radius: 8px;
    display: block;
  }
  .paste-preview figcaption {
    margin-top: 8px;
    font-size: 12px;
    color: #52667a;
  }
  .summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 18px;
  }
  .summary-box {
    padding: 14px 16px;
    border-radius: 12px;
    background: #f8fbfd;
    border: 1px solid #e0e8f0;
  }
  .summary-box strong {
    display: block;
    font-size: 12px;
    color: #486581;
    margin-bottom: 6px;
  }
  .saved-images {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 14px;
  }
  .saved-images img {
    width: 100%;
    height: 160px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid #d9e5f0;
    background: #fff;
  }
  .actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 22px;
    flex-wrap: wrap;
  }
  @media (max-width: 820px) {
    .grid, .summary-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div>
      <h1><?= h($roleLabel) ?> 작업 확인 및 저장</h1>
      <p>작업명, 작업일자, 작업장소는 직접 입력하고, 작업내용과 장비는 데이터베이스에서 선택합니다.</p>
    </div>
    <div class="toolbar">
      <a class="btn-link" href="task_select.php">작업 선택</a>
      <a class="btn-link" href="task_select.php?logout=1">로그아웃</a>
    </div>
  </div>

  <?php if ($unit !== null): ?>
    <div class="banner">
      <strong>선택한 기준 평가서</strong>
      <?= h($unit['unit_title']) ?>
      <div class="chip-row">
        <span class="chip">코드 <?= h($unit['unit_code'] ?: '-') ?></span>
        <span class="chip">공정 <?= h($unit['process_name'] ?: '-') ?></span>
        <span class="chip">역할 <?= h($roleLabel) ?></span>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert">
      <?php foreach ($errors as $error): ?>
        <div><?= h($error) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($savedReport): ?>
    <div class="success">저장이 완료되었습니다. 아래에서 저장된 내용을 바로 확인할 수 있습니다.</div>
    <div class="card" style="margin-bottom: 18px;">
      <div class="card-head">저장된 내용</div>
      <div class="card-body">
        <div class="summary-grid">
          <div class="summary-box"><strong>작성 역할</strong><?= h($roleLabel) ?></div>
          <div class="summary-box"><strong>작성자</strong><?= h($savedReport['user_name']) ?></div>
          <div class="summary-box"><strong>작업명</strong><?= h($savedReport['work_title']) ?></div>
          <div class="summary-box"><strong>작업일자</strong><?= h($savedReport['work_date']) ?></div>
          <div class="summary-box"><strong>작업장소</strong><?= h($savedReport['work_place']) ?></div>
          <div class="summary-box"><strong>장비사용 여부</strong><?= $savedReport['use_equipment_yn'] === 'Y' ? '사용' : '미사용' ?></div>
          <div class="summary-box"><strong>선택 작업내용</strong><?= h(implode(', ', $savedReport['tasks'] ?? [])) ?></div>
          <div class="summary-box"><strong>선택 장비</strong><?= h(implode(', ', $savedReport['tools'] ?? [])) ?: '-' ?></div>
        </div>

        <div class="section">
          <h2>작업 유의사항</h2>
          <div class="summary-box"><?= $savedReport['note_html'] ?: '<span style="color:#7b8794;">입력된 내용이 없습니다.</span>' ?></div>
        </div>

        <?php if (!empty($savedReport['images'])): ?>
          <div class="section">
            <h2>붙여넣은 사진</h2>
            <div class="saved-images">
              <?php foreach ($savedReport['images'] as $image): ?>
                <img src="<?= h($image['file_path']) ?>" alt="<?= h($image['file_name']) ?>">
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="card" id="work-report-form">
    <input type="hidden" name="unit_ra_id" value="<?= (int)$unitRaId ?>">
    <input type="hidden" name="note_html" id="note_html" value="<?= h($defaults['note_html']) ?>">
    <input type="hidden" name="pasted_images" id="pasted_images" value="[]">

    <div class="card-head">입력 화면</div>
    <div class="card-body">
      <div class="grid">
        <div class="field">
          <label for="work_title">작업명</label>
          <input type="text" id="work_title" name="work_title" value="<?= h($defaults['work_title']) ?>">
        </div>
        <div class="field">
          <label for="work_date">작업일자</label>
          <input type="date" id="work_date" name="work_date" value="<?= h($defaults['work_date']) ?>">
        </div>
        <div class="field full">
          <label for="work_place">작업장소</label>
          <input type="text" id="work_place" name="work_place" value="<?= h($defaults['work_place']) ?>">
        </div>
      </div>

      <div class="section">
        <h2>작업내용 선택</h2>
        <?php if (empty($taskRows)): ?>
          <div class="summary-box">선택한 평가서에 작업내용 항목이 없습니다.</div>
        <?php else: ?>
          <div class="list-grid">
            <?php foreach ($taskRows as $row): ?>
              <div class="check-item">
                <label>
                  <input
                    type="checkbox"
                    name="selected_tasks[]"
                    value="<?= (int)$row['item_id'] ?>"
                    <?= in_array((int)$row['item_id'], $defaults['selected_tasks'], true) ? 'checked' : '' ?>
                  >
                  <span><?= h($row['task_name']) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="section">
        <h2>장비사용 여부</h2>
        <div class="check-item" style="max-width: 280px;">
          <label>
            <input
              type="checkbox"
              id="use_equipment_yn"
              name="use_equipment_yn"
              value="Y"
              <?= $defaults['use_equipment_yn'] === 'Y' ? 'checked' : '' ?>
              onchange="toggleTools()"
            >
            <span>장비를 사용합니다.</span>
          </label>
        </div>

        <div class="list-grid" id="tool-section" style="margin-top: 12px; <?= $defaults['use_equipment_yn'] === 'Y' ? '' : 'display:none;' ?>">
          <?php foreach ($tools as $tool): ?>
            <div class="check-item">
              <label>
                <input
                  type="checkbox"
                  name="selected_tools[]"
                  value="<?= (int)$tool['tool_id'] ?>"
                  <?= in_array((int)$tool['tool_id'], $defaults['selected_tools'], true) ? 'checked' : '' ?>
                >
                <span><?= h($tool['tool_name']) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="section">
        <h2>작업 유의사항</h2>
        <div id="note-board" class="note-board" contenteditable="true"><?= $defaults['note_html'] ?></div>
        <div class="note-help">일반 게시판처럼 문장을 입력할 수 있고, 이미지 파일을 복사한 뒤 이 영역에 붙여넣으면 아래 미리보기에 추가됩니다.</div>
        <div class="paste-preview" id="paste-preview"></div>
      </div>

      <div class="actions">
        <a class="btn-link" href="task_select.php">취소</a>
        <button type="submit" class="btn-submit">확인 및 저장</button>
      </div>
    </div>
  </form>
</div>

<script>
const noteBoard = document.getElementById('note-board');
const pastedImagesInput = document.getElementById('pasted_images');
const noteHtmlInput = document.getElementById('note_html');
const pastePreview = document.getElementById('paste-preview');
const pastedImages = [];

function toggleTools() {
  const checked = document.getElementById('use_equipment_yn').checked;
  document.getElementById('tool-section').style.display = checked ? '' : 'none';
}

function renderPastePreview() {
  pastePreview.innerHTML = pastedImages.map((src, index) => `
    <figure>
      <img src="${src}" alt="붙여넣은 이미지 ${index + 1}">
      <figcaption>붙여넣은 사진 ${index + 1}</figcaption>
    </figure>
  `).join('');
  pastedImagesInput.value = JSON.stringify(pastedImages);
}

noteBoard.addEventListener('paste', (event) => {
  const items = event.clipboardData ? event.clipboardData.items : [];
  let handled = false;

  for (const item of items) {
    if (item.type && item.type.startsWith('image/')) {
      handled = true;
      const file = item.getAsFile();
      if (!file) continue;

      const reader = new FileReader();
      reader.onload = () => {
        pastedImages.push(reader.result);
        renderPastePreview();
      };
      reader.readAsDataURL(file);
    }
  }

  if (handled) {
    event.preventDefault();
  }
});

document.getElementById('work-report-form').addEventListener('submit', () => {
  noteHtmlInput.value = noteBoard.innerHTML.trim();
  pastedImagesInput.value = JSON.stringify(pastedImages);
});
</script>
</body>
</html>
