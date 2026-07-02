<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

$user = auth_require_login();
$pdo = getDB();
ensure_work_report_image_schema($pdo);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => $tableName]);
    $cache[$tableName] = (bool)$stmt->fetchColumn();
    return $cache[$tableName];
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!table_exists($pdo, $tableName)) {
        $cache[$cacheKey] = false;
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);
    $cache[$cacheKey] = (bool)$stmt->fetchColumn();
    return $cache[$cacheKey];
}

function ensure_work_report_image_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'work_report_image')) {
        return;
    }

    if (!column_exists($pdo, 'work_report_image', 'description')) {
        $pdo->exec("ALTER TABLE work_report_image ADD COLUMN description VARCHAR(255) NULL AFTER file_name");
    }
}

function report_team_context(array $report): string
{
    $reportTeam = auth_normalize_team_name((string)($report['team_name'] ?? ''));
    if ($reportTeam !== '') {
        return $reportTeam;
    }

    $ownerAccount = auth_find_user((string)($report['user_login_id'] ?? ''));
    return auth_normalize_team_name((string)($ownerAccount['team'] ?? ''));
}

function user_can_view_report(array $user, array $report): bool
{
    $userRole = (string)($user['role'] ?? '');
    if (auth_is_admin($user) || in_array($userRole, ['safety_manager', 'administrator'], true)) {
        return true;
    }

    $visibleTeams = auth_work_list_visible_teams($user);
    $userLoginId = trim((string)($user['login_id'] ?? ''));
    $reportTeam = report_team_context($report);

    if (!empty($visibleTeams)) {
        $visibleTeamKeys = array_fill_keys(array_map('auth_team_key', $visibleTeams), true);
        return $reportTeam !== '' && isset($visibleTeamKeys[auth_team_key($reportTeam)]);
    }

    return $userLoginId !== '' && $userLoginId === (string)($report['user_login_id'] ?? '');
}

function format_note_html(string $html): string
{
    $normalized = trim($html);
    if ($normalized === '') {
        return '<p class="empty-text">등록된 작업 메모가 없습니다.</p>';
    }

    $safe = strip_tags($normalized, '<p><br><ul><ol><li><b><strong><i><em><u><div><span>');
    $safe = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $safe) ?? '';
    $safe = preg_replace('/\son\w+="[^"]*"/i', '', $safe) ?? '';
    $safe = preg_replace("/\son\w+='[^']*'/i", '', $safe) ?? '';

    return trim($safe) !== '' ? $safe : '<p class="empty-text">등록된 작업 메모가 없습니다.</p>';
}

function ensure_directory_exists(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('업로드 폴더를 생성하지 못했습니다.');
    }
}

function work_report_upload_dir(): string
{
    return 'A:\\risk_server\\uploads\\2026\\work_report';
}

function work_report_upload_public_base(): string
{
    return '/uploads/2026/work_report';
}

function resolve_stored_file_path(string $filePath): string
{
    $normalized = trim($filePath);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $normalized) === 1) {
        return $normalized;
    }

    $normalized = str_replace('\\', '/', $normalized);
    if (str_starts_with($normalized, '/uploads/2026/work_report/')) {
        return 'A:\\risk_server' . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    return __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($normalized, '/'));
}

function summarize_risk_level(?string $riskScore): array
{
    $score = (int)trim((string)$riskScore);
    if ($score <= 0) {
        return ['label' => '-', 'class' => 'risk-neutral'];
    }
    if ($score <= 6) {
        return ['label' => '저위험', 'class' => 'risk-low'];
    }
    if ($score >= 15) {
        return ['label' => '고위험', 'class' => 'risk-high'];
    }
    return ['label' => '중위험', 'class' => 'risk-medium'];
}

function fetch_selected_units(PDO $pdo, int $reportId): array
{
    if (!table_exists($pdo, 'work_report_selected_unit')) {
        return [];
    }

    $hasSafeWorkStandardNo = false;
    $colStmt = $pdo->query("SHOW COLUMNS FROM unit_ra_header LIKE 'safe_work_standard_no'");
    if ($colStmt) {
        $hasSafeWorkStandardNo = (bool)$colStmt->fetch();
    }

    $safeWorkStandardSelect = $hasSafeWorkStandardNo
        ? 'h.safe_work_standard_no'
        : 'NULL AS safe_work_standard_no';

    $stmt = $pdo->prepare("
        SELECT
            su.unit_ra_id,
            su.sort_no,
            h.unit_code,
            h.unit_title,
            h.unit_type,
            h.process_name,
            {$safeWorkStandardSelect}
        FROM work_report_selected_unit su
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = su.unit_ra_id
        WHERE su.report_id = :report_id
        ORDER BY su.sort_no ASC, su.report_selection_id ASC
    ");
    $stmt->execute([':report_id' => $reportId]);
    return $stmt->fetchAll() ?: [];
}

function selected_tool_labels(array $selectedUnits): array
{
    $labels = [];
    $seen = [];

    foreach ($selectedUnits as $unit) {
        if (trim((string)($unit['unit_type'] ?? '')) !== 'tool') {
            continue;
        }

        $label = trim((string)($unit['unit_title'] ?? ''));
        if ($label === '') {
            $label = trim((string)($unit['unit_code'] ?? ''));
        }
        if ($label === '' || isset($seen[$label])) {
            continue;
        }

        $seen[$label] = true;
        $labels[] = $label;
    }

    return $labels;
}

function parse_detail_selection(string $value): array
{
    $parts = explode('|', $value, 3);
    return [
        'type' => $parts[0] ?? '',
        'parent' => $parts[1] ?? '',
        'title' => count($parts) >= 3 ? ($parts[2] ?? '') : ($parts[1] ?? ''),
    ];
}

function detail_tool_labels(array $detailValues): array
{
    $labels = [];
    $seen = [];

    foreach ($detailValues as $detailValue) {
        $parsed = parse_detail_selection((string)$detailValue);
        if (($parsed['type'] ?? '') !== 'tool') {
            continue;
        }

        $label = trim((string)($parsed['title'] ?? ''));
        if ($label === '' || isset($seen[$label])) {
            continue;
        }

        $seen[$label] = true;
        $labels[] = $label;
    }

    return $labels;
}

function fetch_leader_detail_entries(PDO $pdo, int $reportId): array
{
    if (!table_exists($pdo, 'work_report_detail')) {
        return [];
    }

    $entries = [];
    $stmt = $pdo->prepare("
        SELECT
            wd.report_detail_id,
            wd.task_name,
            wd.risk_code,
            h.unit_ra_id,
            h.unit_code,
            h.unit_title,
            h.unit_type,
            h.process_name
        FROM work_report_detail wd
        LEFT JOIN unit_ra_header h
            ON TRIM(h.unit_code) COLLATE utf8mb4_unicode_ci = TRIM(wd.risk_code) COLLATE utf8mb4_unicode_ci
        WHERE wd.report_id = :report_id
        ORDER BY wd.report_detail_id ASC
    ");
    $stmt->execute([':report_id' => $reportId]);

    foreach ($stmt->fetchAll() ?: [] as $row) {
        $rawValue = trim((string)($row['task_name'] ?? ''));
        if ($rawValue === '') {
            continue;
        }

        $parsed = parse_detail_selection($rawValue);
        $title = trim((string)($parsed['title'] ?? ''));
        $parent = trim((string)($parsed['parent'] ?? ''));
        $type = trim((string)($parsed['type'] ?? ''));
        $riskCode = trim((string)($row['risk_code'] ?? ''));

        if ($title === '') {
            $title = $rawValue;
        }

        $displayTitle = $title;
        if ($type === 'major_work_sub' && $parent !== '' && $parent !== $title) {
            $displayTitle = $parent . ' - ' . $title;
        }

        $metaParts = [];
        if ($parent !== '' && $parent !== $title && $type !== 'major_work_sub') {
            $metaParts[] = $parent;
        }
        if ($type !== '' && $type !== $title) {
            $metaParts[] = $type;
        }

        $entries[] = [
            'report_detail_id' => (int)($row['report_detail_id'] ?? 0),
            'task_name' => $rawValue,
            'title' => $displayTitle,
            'meta' => implode(' / ', array_unique($metaParts)),
            'risk_code' => $riskCode,
            'unit_ra_id' => (int)($row['unit_ra_id'] ?? 0),
            'unit_code' => trim((string)($row['unit_code'] ?? '')),
            'unit_title' => trim((string)($row['unit_title'] ?? '')),
            'unit_type' => trim((string)($row['unit_type'] ?? '')),
            'process_name' => trim((string)($row['process_name'] ?? '')),
        ];
    }

    return $entries;
}

function fetch_simple_list(PDO $pdo, string $tableName, string $columnName, int $reportId, string $orderColumn = ''): array
{
    if (!table_exists($pdo, $tableName)) {
        return [];
    }

    $orderSql = $orderColumn !== '' ? $orderColumn : $columnName;
    $stmt = $pdo->prepare("
        SELECT {$columnName}
        FROM {$tableName}
        WHERE report_id = :report_id
        ORDER BY {$orderSql} ASC
    ");
    $stmt->execute([':report_id' => $reportId]);

    $values = array_values(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
    ), static fn(string $value): bool => $value !== ''));

    $uniqueValues = [];
    $seen = [];
    foreach ($values as $value) {
        if (isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $uniqueValues[] = $value;
    }

    return $uniqueValues;
}

function fetch_image_count(PDO $pdo, int $reportId): int
{
    if (!table_exists($pdo, 'work_report_image')) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_report_image WHERE report_id = :report_id");
    $stmt->execute([':report_id' => $reportId]);
    return (int)$stmt->fetchColumn();
}

function fetch_report_images(PDO $pdo, int $reportId): array
{
    if (!table_exists($pdo, 'work_report_image')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT report_image_id, file_name, description, file_path, sort_no, created_at
        FROM work_report_image
        WHERE report_id = :report_id
        ORDER BY sort_no ASC, report_image_id ASC
    ");
    $stmt->execute([':report_id' => $reportId]);
    return $stmt->fetchAll() ?: [];
}

function delete_report_image(PDO $pdo, int $reportId, int $reportImageId): bool
{
    if (!table_exists($pdo, 'work_report_image')) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT report_image_id, file_path
        FROM work_report_image
        WHERE report_id = :report_id
          AND report_image_id = :report_image_id
        LIMIT 1
    ");
    $stmt->execute([
        ':report_id' => $reportId,
        ':report_image_id' => $reportImageId,
    ]);
    $image = $stmt->fetch();
    if (!$image) {
        return false;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM work_report_image
        WHERE report_id = :report_id
          AND report_image_id = :report_image_id
    ");
    $deleteStmt->execute([
        ':report_id' => $reportId,
        ':report_image_id' => $reportImageId,
    ]);

    $filePath = trim((string)($image['file_path'] ?? ''));
    if ($filePath !== '') {
        $fullPath = resolve_stored_file_path($filePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    return true;
}

function delete_report_images_bulk(PDO $pdo, int $reportId, array $reportImageIds): int
{
    $deletedCount = 0;
    foreach ($reportImageIds as $reportImageId) {
        $imageId = (int)$reportImageId;
        if ($imageId <= 0) {
            continue;
        }
        if (delete_report_image($pdo, $reportId, $imageId)) {
            $deletedCount++;
        }
    }

    return $deletedCount;
}

function fetch_hazard_participant_count(PDO $pdo, int $reportId): int
{
    if (!table_exists($pdo, 'work_report_worker_hazard_selection')) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT user_login_id)
        FROM work_report_worker_hazard_selection
        WHERE report_id = :report_id
    ");
    $stmt->execute([':report_id' => $reportId]);
    return (int)$stmt->fetchColumn();
}

function fetch_hazard_participants(PDO $pdo, int $reportId): array
{
    if (!table_exists($pdo, 'work_report_worker_hazard_selection')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT user_name, user_login_id
        FROM work_report_worker_hazard_selection
        WHERE report_id = :report_id
        ORDER BY user_name ASC, user_login_id ASC
    ");
    $stmt->execute([':report_id' => $reportId]);

    $names = [];
    $seen = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = trim((string)($row['user_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['user_login_id'] ?? ''));
        }
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $names[] = $name;
    }

    return $names;
}

function fetch_hazard_summary(PDO $pdo, int $reportId, array $selectedUnits, int $limit = 8): array
{
    if ($limit <= 0) {
        return [];
    }

    if (table_exists($pdo, 'work_report_worker_hazard_selection')) {
        $stmt = $pdo->prepare("
            SELECT
                i.hazard_name,
                i.accident_type,
                i.injury_result,
                i.current_control_text,
                i.additional_control_text,
                i.likelihood_before,
                i.severity_before,
                i.risk_score_before,
                i.likelihood_current,
                i.severity_current,
                i.risk_score_current,
                h.unit_title,
                h.unit_code,
                h.unit_type,
                COUNT(*) AS selected_count,
                COUNT(DISTINCT s.user_login_id) AS worker_count
            FROM work_report_worker_hazard_selection s
            INNER JOIN unit_ra_item i
                ON i.item_id = s.item_id
            INNER JOIN unit_ra_header h
                ON h.unit_ra_id = s.unit_ra_id
            WHERE s.report_id = :report_id
            GROUP BY
                i.hazard_name,
                i.accident_type,
                i.injury_result,
                i.current_control_text,
                i.additional_control_text,
                i.likelihood_before,
                i.severity_before,
                i.risk_score_before,
                i.likelihood_current,
                i.severity_current,
                i.risk_score_current,
                h.unit_title,
                h.unit_code,
                h.unit_type
            ORDER BY selected_count DESC, worker_count DESC, i.hazard_name ASC
            LIMIT {$limit}
        ");
        $stmt->execute([':report_id' => $reportId]);
        $rows = $stmt->fetchAll() ?: [];
        if (!empty($rows)) {
            return $rows;
        }
    }

    if (table_exists($pdo, 'work_report_hazard_selection')) {
        $stmt = $pdo->prepare("
            SELECT
                i.hazard_name,
                i.accident_type,
                i.injury_result,
                i.current_control_text,
                i.additional_control_text,
                i.likelihood_before,
                i.severity_before,
                i.risk_score_before,
                i.likelihood_current,
                i.severity_current,
                i.risk_score_current,
                h.unit_title,
                h.unit_code,
                h.unit_type,
                COUNT(*) AS selected_count,
                0 AS worker_count
            FROM work_report_hazard_selection s
            INNER JOIN unit_ra_item i
                ON i.item_id = s.item_id
            INNER JOIN unit_ra_header h
                ON h.unit_ra_id = s.unit_ra_id
            WHERE s.report_id = :report_id
            GROUP BY
                i.hazard_name,
                i.accident_type,
                i.injury_result,
                i.current_control_text,
                i.additional_control_text,
                i.likelihood_before,
                i.severity_before,
                i.risk_score_before,
                i.likelihood_current,
                i.severity_current,
                i.risk_score_current,
                h.unit_title,
                h.unit_code,
                h.unit_type
            ORDER BY selected_count DESC, i.hazard_name ASC
            LIMIT {$limit}
        ");
        $stmt->execute([':report_id' => $reportId]);
        $rows = $stmt->fetchAll() ?: [];
        if (!empty($rows)) {
            return $rows;
        }
    }

    $unitIds = array_values(array_filter(array_map(
        static fn(array $unit): int => (int)($unit['unit_ra_id'] ?? 0),
        $selectedUnits
    )));
    if (empty($unitIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.additional_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_title,
            h.unit_code,
            h.unit_type,
            0 AS selected_count,
            0 AS worker_count
        FROM unit_ra_item i
        INNER JOIN unit_ra_header h
            ON h.unit_ra_id = i.unit_ra_id
        WHERE i.use_yn = 'Y'
          AND i.unit_ra_id IN ({$placeholders})
        ORDER BY FIELD(i.unit_ra_id, " . implode(',', array_map('intval', $unitIds)) . "), i.sort_no ASC, i.item_id ASC
        LIMIT {$limit}
    ");
    $stmt->execute($unitIds);
    return $stmt->fetchAll() ?: [];
}

$reportId = (int)($_GET['report_id'] ?? 0);
if ($reportId <= 0) {
    http_response_code(400);
    exit('잘못된 접근입니다.');
}

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
        wr.use_equipment_yn,
        wr.note_html,
        wr.created_at,
        wr.updated_at,
        h.unit_code,
        h.unit_title,
        h.unit_type,
        h.process_name,
        (
            SELECT COUNT(*)
            FROM work_report_detail wd
            WHERE wd.report_id = wr.report_id
        ) AS leader_detail_count
    FROM work_report wr
    LEFT JOIN unit_ra_header h
        ON h.unit_ra_id = wr.unit_ra_id
    WHERE wr.report_id = :report_id
    LIMIT 1
");
$stmt->execute([':report_id' => $reportId]);
$report = $stmt->fetch();

if (!$report) {
    http_response_code(404);
    exit('작업 정보를 찾을 수 없습니다.');
}

$report['team_name_display'] = report_team_context($report);
if (!user_can_view_report($user, $report)) {
    http_response_code(403);
    exit('이 작업 상세를 볼 권한이 없습니다.');
}

$flashMessage = isset($_SESSION['work_list_detail_flash']) ? (string)$_SESSION['work_list_detail_flash'] : '';
$errorMessage = isset($_SESSION['work_list_detail_error']) ? (string)$_SESSION['work_list_detail_error'] : '';
unset($_SESSION['work_list_detail_flash'], $_SESSION['work_list_detail_error']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upload_work_photo') {
    if (!isset($_FILES['work_photo']) || !is_array($_FILES['work_photo'])) {
        $errorMessage = '업로드할 사진을 찾을 수 없습니다.';
    } else {
        $uploadedFile = $_FILES['work_photo'];
        $photoDescription = trim((string)($_POST['photo_description'] ?? ''));
        if (function_exists('mb_substr')) {
            $photoDescription = mb_substr($photoDescription, 0, 255, 'UTF-8');
        } else {
            $photoDescription = substr($photoDescription, 0, 255);
        }
        $tmpName = (string)($uploadedFile['tmp_name'] ?? '');
        $originalName = trim((string)($uploadedFile['name'] ?? ''));
        $uploadError = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
            $errorMessage = '사진 업로드에 실패했습니다.';
        } else {
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $errorMessage = '이미지 파일만 업로드할 수 있습니다.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = strtolower((string)$finfo->file($tmpName));
                if (!str_starts_with($mimeType, 'image/')) {
                    $errorMessage = '유효한 이미지 파일만 업로드할 수 있습니다.';
                } else {
                    try {
                        $uploadDir = work_report_upload_dir();
                        ensure_directory_exists($uploadDir);

                        $safeBaseName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'work_photo';
                        $safeBaseName = trim((string)$safeBaseName, '._-');
                        if ($safeBaseName === '') {
                            $safeBaseName = 'work_photo';
                        }

                        $fileName = sprintf(
                            'report_%d_%s_%s.%s',
                            $reportId,
                            date('YmdHis'),
                            bin2hex(random_bytes(4)),
                            $extension === 'jpeg' ? 'jpg' : $extension
                        );
                        $fullPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($tmpName, $fullPath)) {
                            throw new RuntimeException('업로드 파일을 저장하지 못했습니다.');
                        }

                        $relativePath = work_report_upload_public_base() . '/' . $fileName;
                        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_no), 0) FROM work_report_image WHERE report_id = :report_id");
                        $sortStmt->execute([':report_id' => $reportId]);
                        $sortNo = (int)$sortStmt->fetchColumn() + 1;

                        $insertStmt = $pdo->prepare("
                            INSERT INTO work_report_image (
                                report_id, file_name, description, file_path, sort_no
                            ) VALUES (
                                :report_id, :file_name, :description, :file_path, :sort_no
                            )
                        ");
                        $insertStmt->execute([
                            ':report_id' => $reportId,
                            ':file_name' => $originalName !== '' ? $originalName : $fileName,
                            ':description' => $photoDescription !== '' ? $photoDescription : null,
                            ':file_path' => $relativePath,
                            ':sort_no' => $sortNo,
                        ]);

                        $flashMessage = '작업사진이 저장되었습니다.';
                    } catch (Throwable $e) {
                        $errorMessage = '작업사진 저장 중 오류가 발생했습니다: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    $_SESSION['work_list_detail_flash'] = $flashMessage;
    $_SESSION['work_list_detail_error'] = $errorMessage;
    header('Location: work_list_detail.php?report_id=' . $reportId);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_work_photos') {
    $targetImageIds = $_POST['report_image_ids'] ?? [];
    if (!is_array($targetImageIds) || empty($targetImageIds)) {
        $errorMessage = '삭제할 사진을 선택해 주세요.';
    } else {
        $deletedCount = delete_report_images_bulk($pdo, $reportId, $targetImageIds);
        if ($deletedCount > 0) {
            $flashMessage = '선택한 작업사진이 삭제되었습니다.';
        } else {
            $errorMessage = '삭제할 사진을 찾을 수 없습니다.';
        }
    }

    $_SESSION['work_list_detail_flash'] = $flashMessage;
    $_SESSION['work_list_detail_error'] = $errorMessage;
    header('Location: work_list_detail.php?report_id=' . $reportId);
    exit;
}

$selectedUnits = fetch_selected_units($pdo, $reportId);
if (empty($selectedUnits) && (int)($report['unit_ra_id'] ?? 0) > 0) {
    $selectedUnits[] = [
        'unit_ra_id' => (int)$report['unit_ra_id'],
        'sort_no' => 0,
        'unit_code' => (string)($report['unit_code'] ?? ''),
        'unit_title' => (string)($report['unit_title'] ?? ''),
        'unit_type' => (string)($report['unit_type'] ?? ''),
        'process_name' => (string)($report['process_name'] ?? ''),
        'safe_work_standard_no' => '',
    ];
}
$selectedUnits = array_values(array_filter($selectedUnits, static function (array $unit): bool {
    return trim((string)($unit['unit_code'] ?? '')) !== '';
}));

$tasks = fetch_simple_list($pdo, 'work_report_task', 'task_name', $reportId, 'sort_no');
$tools = fetch_simple_list($pdo, 'work_report_tool', 'tool_name', $reportId, 'tool_name');
$leaderDetails = fetch_simple_list($pdo, 'work_report_detail', 'task_name', $reportId, 'report_detail_id');
$leaderDetailEntries = fetch_leader_detail_entries($pdo, $reportId);
$leaderDetailEntries = array_values(array_filter($leaderDetailEntries, static function (array $entry): bool {
    $entryCode = trim((string)($entry['unit_code'] ?? ''));
    if ($entryCode === '') {
        $entryCode = trim((string)($entry['risk_code'] ?? ''));
    }

    return $entryCode !== '' && (int)($entry['unit_ra_id'] ?? 0) > 0;
}));
$imageCount = fetch_image_count($pdo, $reportId);
$images = fetch_report_images($pdo, $reportId);
$participantCount = fetch_hazard_participant_count($pdo, $reportId);
$participantNames = fetch_hazard_participants($pdo, $reportId);
$hazards = fetch_hazard_summary($pdo, $reportId, $selectedUnits, 8);
$selectedToolLabels = array_values(array_unique(array_merge(
    selected_tool_labels($selectedUnits),
    detail_tool_labels($leaderDetails)
)));

$pageTitle = trim((string)($report['work_title'] ?? '')) ?: '작업 상세';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> | 작업 상세</title>
<style>
  :root {
    --bg: #0c1420;
    --panel: #111d2e;
    --panel-2: #162033;
    --border: rgba(255,255,255,0.08);
    --text: #c5d8eb;
    --text-hi: #edf5ff;
    --text-dim: #7f9ab4;
    --accent: #f5a623;
    --accent-2: #ffcb66;
    --success: #7fe0af;
    --danger: #ffb4af;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: "Malgun Gothic", sans-serif;
    background: var(--bg);
    color: var(--text);
    padding: 28px 18px 48px;
  }
  a { color: inherit; }
  .shell { max-width: 1180px; margin: 0 auto; }
  .topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
  }
  .eyebrow { color: var(--text-dim); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; margin-bottom: 6px; }
  .title { margin: 0; color: var(--text-hi); font-size: 28px; line-height: 1.25; }
  .subtitle { margin-top: 8px; color: var(--text-dim); font-size: 14px; }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 16px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.05);
    color: var(--text-hi);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
  }
  .btn:hover { background: rgba(255,255,255,0.1); }
  .grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 18px;
  }
  .card {
    grid-column: span 12;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
  }
  .card-head {
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
  }
  .card-head h2 {
    margin: 0;
    color: var(--text-hi);
    font-size: 18px;
  }
  .card-head p {
    margin: 8px 0 0;
    color: var(--text-dim);
    font-size: 13px;
    line-height: 1.5;
  }
  .card-body { padding: 20px 22px 22px; }
  .meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .meta-item {
    background: var(--panel-2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 15px;
  }
  .meta-label {
    color: var(--text-dim);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
  }
  .meta-value {
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 700;
    line-height: 1.5;
    word-break: keep-all;
  }
  .summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
  .summary-block {
    background: var(--panel-2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px;
  }
  .summary-block h3 {
    margin: 0 0 10px;
    color: var(--text-hi);
    font-size: 15px;
  }
  .chip-list, .plain-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
  }
  .chip {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
    font-size: 12px;
    line-height: 1.35;
  }
  .plain-list {
    flex-direction: column;
    gap: 10px;
  }
  .plain-list li {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    line-height: 1.55;
  }
  .inline-text-list {
    margin-top: 10px;
    color: var(--text-hi);
    font-size: 13px;
    line-height: 1.65;
  }
  .inline-text-list strong {
    color: var(--text-dim);
    font-size: 12px;
    margin-right: 8px;
  }
  .note-box {
    background: var(--panel-2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px;
    line-height: 1.7;
    color: var(--text);
  }
  .note-box p:first-child { margin-top: 0; }
  .note-box p:last-child { margin-bottom: 0; }
  .empty-text { color: var(--text-dim); margin: 0; }
  .unit-grid, .hazard-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
  .toggle-summary-button {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
    font: inherit;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    text-align: left;
  }
  .toggle-summary-button:hover {
    background: rgba(255,255,255,0.08);
  }
  .toggle-summary-button .toggle-hint {
    color: var(--accent-2);
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .toggle-summary-panel[hidden] {
    display: none;
  }
  .toggle-summary-panel {
    margin-top: 14px;
  }
  .unit-card, .hazard-card {
    background: var(--panel-2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
  }
  .unit-card.is-clickable {
    cursor: pointer;
    transition: transform .15s ease, border-color .15s ease, background .15s ease;
  }
  .unit-card.is-clickable:hover {
    transform: translateY(-1px);
    border-color: rgba(245,166,35,0.35);
    background: rgba(255,255,255,0.05);
  }
  .unit-code {
    color: var(--accent-2);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .04em;
    margin-bottom: 8px;
  }
  .unit-title, .hazard-title {
    color: var(--text-hi);
    font-size: 16px;
    font-weight: 800;
    line-height: 1.45;
    margin-bottom: 10px;
  }
  .muted {
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.55;
  }
  .hazard-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
  }
  .hazard-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 800;
  }
  .risk-low { color: var(--success); border-color: rgba(127,224,175,0.35); background: rgba(127,224,175,0.1); }
  .risk-medium { color: var(--accent-2); border-color: rgba(245,166,35,0.35); background: rgba(245,166,35,0.1); }
  .risk-high { color: var(--danger); border-color: rgba(255,180,175,0.35); background: rgba(255,180,175,0.1); }
  .risk-neutral { color: var(--text-dim); }
  .hazard-section-label {
    color: var(--text-dim);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin: 14px 0 6px;
  }
  .hazard-text {
    color: var(--text);
    font-size: 13px;
    line-height: 1.6;
  }
  .flash-message,
  .error-message {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
  }
  .flash-message {
    background: rgba(54, 179, 126, 0.12);
    border: 1px solid rgba(54, 179, 126, 0.35);
    color: #99efc3;
  }
  .error-message {
    background: rgba(214, 69, 65, 0.12);
    border: 1px solid rgba(214, 69, 65, 0.35);
    color: #ffd3d1;
  }
  .upload-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 16px;
  }
  .upload-form input[type="file"] {
    color: var(--text);
    font: inherit;
    max-width: 100%;
  }
  .upload-form input[type="text"] {
    min-width: min(320px, 100%);
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.06);
    color: var(--text);
    font: inherit;
  }
  .upload-form input[type="text"]::placeholder {
    color: var(--text-dim);
  }
  .photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
  }
  .photo-card {
    background: var(--panel-2);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
  }
  .photo-thumb {
    display: block;
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    background: rgba(255,255,255,0.04);
  }
  .photo-meta {
    padding: 10px 12px 12px;
  }
  .photo-selection-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .photo-selection-help {
    color: var(--text-dim);
    font-size: 12px;
  }
  .photo-delete-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid rgba(214, 69, 65, 0.4);
    background: rgba(214, 69, 65, 0.14);
    color: #ffd8d6;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
  }
  .photo-delete-button:hover {
    background: rgba(214, 69, 65, 0.24);
  }
  .photo-check {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
  }
  .photo-check input[type="checkbox"] {
    width: 13px;
    height: 13px;
    margin: 0;
    accent-color: var(--accent);
  }
  .photo-date-row {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .photo-date {
    margin-top: 0;
    color: var(--text-dim);
    font-size: 11px;
  }
  .photo-description {
    margin-top: 8px;
    color: var(--text);
    font-size: 12px;
    line-height: 1.5;
    word-break: keep-all;
  }
  .photo-link {
    display: block;
  }
  .photo-viewer {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: rgba(6, 10, 18, 0.82);
    backdrop-filter: blur(4px);
  }
  .photo-viewer.is-open {
    display: flex;
  }
  .photo-viewer-dialog {
    position: relative;
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .photo-viewer-image {
    display: block;
    max-width: 100vw;
    max-height: 100vh;
    border-radius: 0;
    box-shadow: 0 24px 60px rgba(0,0,0,0.45);
    cursor: grab;
    user-select: none;
    transition: transform 0.05s linear;
    transform-origin: center center;
  }
  .photo-viewer-image.is-panning {
    cursor: grabbing;
  }
  .photo-viewer-close {
    position: fixed;
    top: 18px;
    right: 18px;
    z-index: 10001;
    width: 44px;
    height: 44px;
    border: 1px solid rgba(255,255,255,0.22);
    border-radius: 999px;
    background: rgba(12, 20, 32, 0.94);
    color: #fff;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 10px 30px rgba(0,0,0,0.35);
  }
  .photo-viewer-close:hover {
    background: rgba(24, 36, 54, 0.98);
  }
  .modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(5, 10, 18, 0.78);
    backdrop-filter: blur(4px);
  }
  .modal-backdrop.is-open { display: flex; }
  .unit-preview-modal {
    width: min(1180px, 100%);
    max-height: calc(100vh - 40px);
    overflow: hidden;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: linear-gradient(180deg, rgba(18, 30, 48, 0.98), rgba(12, 20, 32, 0.98));
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
  }
  .unit-preview-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 20px 22px 16px;
    border-bottom: 1px solid var(--border);
  }
  .unit-preview-head h2 {
    margin: 0 0 6px;
    color: var(--text-hi);
    font-size: 22px;
    line-height: 1.3;
  }
  .unit-preview-head p {
    margin: 0;
    color: var(--text-dim);
    font-size: 13px;
    line-height: 1.5;
  }
  .modal-close {
    flex: 0 0 auto;
    min-width: 42px;
    height: 42px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    color: var(--text-hi);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
  }
  .modal-close:hover { background: rgba(255,255,255,0.11); }
  .unit-preview-body {
    padding: 18px 22px 22px;
    max-height: calc(100vh - 156px);
    overflow: auto;
  }
  .unit-preview-loading,
  .unit-preview-error,
  .unit-preview-empty {
    padding: 32px 18px;
    border: 1px dashed var(--border);
    border-radius: 14px;
    text-align: center;
    color: var(--text-dim);
    background: rgba(255,255,255,0.03);
  }
  .unit-preview-error { color: #ffd3d1; border-color: rgba(214, 69, 65, 0.45); }
  .unit-preview-meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
  }
  .unit-preview-meta-card {
    min-width: 0;
    padding: 12px 13px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.035);
  }
  .unit-preview-meta-card strong {
    display: block;
    margin-bottom: 6px;
    color: var(--text-dim);
    font-size: 11px;
    letter-spacing: .04em;
  }
  .unit-preview-meta-card span {
    display: block;
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 700;
    line-height: 1.45;
    word-break: break-word;
  }
  .unit-preview-section {
    margin-top: 16px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: rgba(255,255,255,0.025);
  }
  .unit-preview-section h3 {
    margin: 0 0 10px;
    color: var(--text-hi);
    font-size: 16px;
  }
  .unit-preview-remark {
    color: var(--text);
    font-size: 13px;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .unit-preview-table-wrap {
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 14px;
  }
  .unit-preview-table {
    width: 100%;
    min-width: 880px;
    border-collapse: collapse;
  }
  .unit-preview-table th,
  .unit-preview-table td {
    padding: 11px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: top;
    font-size: 13px;
    line-height: 1.55;
  }
  .unit-preview-table th {
    position: sticky;
    top: 0;
    background: rgba(18, 30, 48, 0.98);
    color: var(--text-dim);
    font-size: 12px;
    letter-spacing: .04em;
  }
  .unit-preview-table td { color: var(--text); }
  .unit-preview-table td strong { color: var(--text-hi); }
  .sub-text { color: var(--text-dim); font-size: 12px; line-height: 1.6; }
  .risk-badge-line {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 6px;
  }
  .risk-level-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 8px;
    border-radius: 999px;
    border: 1px solid var(--border);
    font-size: 11px;
    font-weight: 800;
  }
  .risk-level-badge.is-low { color: var(--success); border-color: rgba(127,224,175,0.35); background: rgba(127,224,175,0.1); }
  .risk-level-badge.is-medium { color: var(--accent-2); border-color: rgba(245,166,35,0.35); background: rgba(245,166,35,0.1); }
  .risk-level-badge.is-high { color: var(--danger); border-color: rgba(255,180,175,0.35); background: rgba(255,180,175,0.1); }
  .risk-score-text { color: var(--text-hi); font-weight: 700; }
  .span-7 { grid-column: span 7; }
  .span-5 { grid-column: span 5; }
  @media (max-width: 900px) {
    .meta-grid, .summary-grid, .unit-grid, .hazard-grid { grid-template-columns: 1fr; }
    .span-7, .span-5 { grid-column: span 12; }
    .title { font-size: 24px; }
    .unit-preview-meta-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
</style>
</head>
<body>
  <div class="shell">
    <?php if ($flashMessage !== ''): ?>
      <div class="flash-message"><?= h($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
      <div class="error-message"><?= h($errorMessage) ?></div>
    <?php endif; ?>
    <div class="topbar">
      <div>
        <div class="eyebrow">WORK LIST DETAIL</div>
        <h1 class="title"><?= h($pageTitle) ?></h1>
        <div class="subtitle"><?= h($report['work_date']) ?> · <?= h($report['work_place']) ?> · <?= h($report['team_name_display'] !== '' ? $report['team_name_display'] : '-') ?></div>
      </div>
      <div class="actions">
        <a class="btn" href="work_list.php">작업목록으로</a>
      </div>
    </div>

    <div class="grid">
      <section class="card">
        <div class="card-head">
          <h2>기본 정보</h2>
          <p>등록된 작업의 핵심 정보와 진행 상태를 한눈에 볼 수 있도록 정리했습니다.</p>
        </div>
        <div class="card-body">
          <div class="meta-grid">
            <div class="meta-item"><div class="meta-label">작업일자</div><div class="meta-value"><?= h($report['work_date']) ?></div></div>
            <div class="meta-item"><div class="meta-label">작업장소</div><div class="meta-value"><?= h($report['work_place']) ?></div></div>
            <div class="meta-item"><div class="meta-label">작업팀</div><div class="meta-value"><?= h($report['team_name_display'] !== '' ? $report['team_name_display'] : '-') ?></div></div>
            <div class="meta-item"><div class="meta-label">작성자</div><div class="meta-value"><?= h($report['user_name']) ?></div></div>
            <div class="meta-item"><div class="meta-label">중장비 사용</div><div class="meta-value"><?= $report['use_equipment_yn'] === 'Y' ? '사용' : '미사용' ?></div></div>
            <div class="meta-item"><div class="meta-label">선택 평가서</div><div class="meta-value"><?= number_format(count($selectedUnits)) ?>건</div></div>
            <div class="meta-item"><div class="meta-label">작업자</div><div class="meta-value"><?= number_format($participantCount) ?>명/<?= !empty($participantNames) ? h(implode(', ', $participantNames)) : '-' ?></div></div>
            <div class="meta-item"><div class="meta-label">등록일</div><div class="meta-value"><?= h(substr((string)$report['created_at'], 0, 16)) ?></div></div>
          </div>
        </div>
      </section>

      <section class="card span-7">
        <div class="card-head">
          <h2>작업내용 요약</h2>
          <p>현장에서 입력된 작업절차와 사용공구, 관리감독자가 남긴 메모를 빠르게 확인할 수 있습니다.</p>
        </div>
        <div class="card-body">
          <div class="summary-grid">
            <div class="summary-block">
              <h3>작업절차</h3>
              <?php if (!empty($tasks)): ?>
                <ul class="plain-list">
                  <?php foreach ($tasks as $task): ?>
                    <li><?= h($task) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="empty-text">등록된 작업절차가 없습니다.</p>
              <?php endif; ?>
            </div>
            <div class="summary-block">
              <h3>공구 및 입력 현황</h3>
              <ul class="chip-list">
                <?php foreach ($tools as $tool): ?>
                  <li class="chip"><?= h($tool) ?></li>
                <?php endforeach; ?>
              </ul>
              <div class="inline-text-list">
                <strong>사용공구</strong>
                <?= !empty($selectedToolLabels) ? h(implode(', ', $selectedToolLabels)) : '없음' ?>
              </div>
              <div class="hazard-section-label">입력현황</div>
              <div class="note-box"><?= format_note_html((string)($report['note_html'] ?? '')) ?></div>
            </div>
          </div>
        </div>
      </section>

      <section class="card span-5">
        <div class="card-head">
          <h2>연결된 위험성평가서</h2>
          <p>관리감독자가 연결한 위험성평가서와 작업지휘자가 입력한 위험성평가서 항목을 함께 보여줍니다.</p>
        </div>
        <div class="card-body">
          <?php if (!empty($selectedUnits)): ?>
            <div class="unit-grid">
              <?php foreach ($selectedUnits as $unit): ?>
                <article
                  class="unit-card is-clickable js-unit-preview-card"
                  data-unit-ra-id="<?= (int)($unit['unit_ra_id'] ?? 0) ?>"
                  role="button"
                  tabindex="0"
                  aria-label="<?= h(trim((string)($unit['unit_title'] ?? '')) !== '' ? $unit['unit_title'] . ' 위험성평가서 미리보기' : '위험성평가서 미리보기') ?>"
                >
                  <?php if (trim((string)($unit['unit_code'] ?? '')) !== ''): ?>
                    <div class="unit-code"><?= h((string)$unit['unit_code']) ?></div>
                  <?php endif; ?>
                  <div class="unit-title"><?= h(trim((string)($unit['unit_title'] ?? '')) !== '' ? $unit['unit_title'] : '제목 없음') ?></div>
                  <div class="muted">유형: <?= h(trim((string)($unit['unit_type'] ?? '')) !== '' ? $unit['unit_type'] : '-') ?></div>
                  <div class="muted">대분류: <?= h(trim((string)($unit['process_name'] ?? '')) !== '' ? $unit['process_name'] : '-') ?></div>
                  <?php if (trim((string)($unit['safe_work_standard_no'] ?? '')) !== ''): ?>
                    <div class="muted">작업표준서: <?= h((string)$unit['safe_work_standard_no']) ?></div>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="empty-text">연결된 위험성평가서가 없습니다.</p>
          <?php endif; ?>

          <div class="hazard-section-label" style="margin-top:18px;">작업지휘자 입력 위험성평가서 목록</div>
          <?php if (!empty($leaderDetailEntries)): ?>
            <div class="unit-grid">
              <?php foreach ($leaderDetailEntries as $entry): ?>
                <article
                  class="unit-card<?= (int)($entry['unit_ra_id'] ?? 0) > 0 ? ' is-clickable js-unit-preview-card' : '' ?>"
                  <?php if ((int)($entry['unit_ra_id'] ?? 0) > 0): ?>
                    data-unit-ra-id="<?= (int)($entry['unit_ra_id'] ?? 0) ?>"
                    role="button"
                    tabindex="0"
                    aria-label="<?= h(((string)($entry['unit_title'] ?? '') !== '' ? $entry['unit_title'] : (string)($entry['title'] ?? '위험성평가서')) . ' 위험성평가서 미리보기') ?>"
                  <?php endif; ?>
                >
                  <?php
                    $entryCode = trim((string)($entry['unit_code'] ?? ''));
                    if ($entryCode === '') {
                        $entryCode = trim((string)($entry['risk_code'] ?? ''));
                    }
                  ?>
                  <?php if ($entryCode !== ''): ?>
                    <div class="unit-code"><?= h($entryCode) ?></div>
                  <?php endif; ?>
                  <div class="unit-title"><?= h(trim((string)($entry['unit_title'] ?? '')) !== '' ? $entry['unit_title'] : (string)($entry['title'] ?? '항목 없음')) ?></div>
                  <div class="muted">선택항목: <?= h((string)($entry['title'] ?? '-')) ?></div>
                  <div class="muted">유형: <?= h(trim((string)($entry['unit_type'] ?? '')) !== '' ? $entry['unit_type'] : ((string)($entry['meta'] ?? '') !== '' ? (string)$entry['meta'] : '작업지휘자 선택 항목')) ?></div>
                  <div class="muted">대분류: <?= h(trim((string)($entry['process_name'] ?? '')) !== '' ? $entry['process_name'] : '-') ?></div>
                  <?php if ((int)($entry['unit_ra_id'] ?? 0) <= 0): ?>
                    <div class="muted">연결된 평가서를 찾지 못했습니다.</div>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="empty-text">작업지휘자가 입력한 위험성평가서 목록이 없습니다.</p>
          <?php endif; ?>
        </div>
      </section>

      <section class="card">
        <div class="card-head">
          <h2>위험성평가 핵심 요약</h2>
          <p>필요할 때만 펼쳐서 볼 수 있도록 기본은 숨겨두었습니다.</p>
        </div>
        <div class="card-body">
          <button type="button" class="toggle-summary-button" id="hazard-summary-toggle" aria-expanded="false" aria-controls="hazard-summary-panel">
            <span>위험성평가 핵심요약 펼쳐보기</span>
            <span class="toggle-hint">클릭해서 보기</span>
          </button>
          <div class="toggle-summary-panel" id="hazard-summary-panel" hidden>
            <?php if (!empty($hazards)): ?>
              <div class="hazard-grid">
                <?php foreach ($hazards as $hazard): ?>
                  <?php $risk = summarize_risk_level((string)($hazard['risk_score_current'] ?? $hazard['risk_score_before'] ?? '')); ?>
                  <article class="hazard-card">
                    <div class="unit-code"><?= h(trim((string)($hazard['unit_code'] ?? '')) !== '' ? $hazard['unit_code'] : '번호 미등록') ?></div>
                    <div class="hazard-title"><?= h(trim((string)($hazard['hazard_name'] ?? '')) !== '' ? $hazard['hazard_name'] : '위험요인 없음') ?></div>
                    <div class="hazard-meta">
                      <span class="hazard-badge <?= h($risk['class']) ?>"><?= h($risk['label']) ?></span>
                      <?php if ((int)($hazard['selected_count'] ?? 0) > 0): ?>
                        <span class="hazard-badge"><?= h((string)$hazard['selected_count']) ?>회 선택</span>
                      <?php endif; ?>
                      <?php if ((int)($hazard['worker_count'] ?? 0) > 0): ?>
                        <span class="hazard-badge"><?= h((string)$hazard['worker_count']) ?>명 참여</span>
                      <?php endif; ?>
                    </div>
                    <div class="muted">평가서: <?= h(trim((string)($hazard['unit_title'] ?? '')) !== '' ? $hazard['unit_title'] : '-') ?></div>
                    <div class="muted">사고유형/상해결과: <?= h(trim((string)($hazard['accident_type'] ?? '')) !== '' || trim((string)($hazard['injury_result'] ?? '')) !== '' ? trim((string)$hazard['accident_type']) . (trim((string)$hazard['accident_type']) !== '' && trim((string)$hazard['injury_result']) !== '' ? ' / ' : '') . trim((string)$hazard['injury_result']) : '-') ?></div>
                    <div class="hazard-section-label">위험도</div>
                    <div class="hazard-text">
                      현재 P <?= h((string)($hazard['likelihood_before'] ?? '-')) ?> / S <?= h((string)($hazard['severity_before'] ?? '-')) ?> / R <?= h((string)($hazard['risk_score_before'] ?? '-')) ?><br>
                      조치후 P <?= h((string)($hazard['likelihood_current'] ?? '-')) ?> / S <?= h((string)($hazard['severity_current'] ?? '-')) ?> / R <?= h((string)($hazard['risk_score_current'] ?? '-')) ?>
                    </div>
                    <div class="hazard-section-label">현재 조치</div>
                    <div class="hazard-text"><?= nl2br(h(trim((string)($hazard['current_control_text'] ?? '')) !== '' ? $hazard['current_control_text'] : '-')) ?></div>
                    <div class="hazard-section-label">추가 조치</div>
                    <div class="hazard-text"><?= nl2br(h(trim((string)($hazard['additional_control_text'] ?? '')) !== '' ? $hazard['additional_control_text'] : '-')) ?></div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="empty-text">표시할 위험성평가 요약이 아직 없습니다.</p>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card-head">
          <h2>작업사진</h2>
          <p>사진과 함께 짧은 설명을 적어두고, 등록 후 썸네일에서 바로 확인할 수 있습니다.</p>
        </div>
        <div class="card-body">
          <form class="upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_work_photo">
            <input type="file" name="work_photo" accept="image/*" required>
            <input type="text" name="photo_description" maxlength="255" placeholder="작업사진 내용을 간단히 입력하세요">
            <button type="submit" class="btn">사진 저장</button>
          </form>

          <?php if (!empty($images)): ?>
            <form method="post" onsubmit="return confirm('선택한 작업사진을 삭제하시겠습니까?');">
              <input type="hidden" name="action" value="delete_work_photos">
              <div class="photo-selection-bar">
                <div class="photo-selection-help">삭제할 사진을 체크한 뒤 삭제 버튼을 눌러주세요.</div>
                <button type="submit" class="photo-delete-button">선택 사진 삭제</button>
              </div>
              <div class="photo-grid">
                <?php foreach ($images as $image): ?>
                  <article class="photo-card">
                    <a href="<?= h((string)($image['file_path'] ?? '')) ?>" class="photo-link js-photo-viewer-trigger" data-photo-src="<?= h((string)($image['file_path'] ?? '')) ?>" data-photo-alt="<?= h((string)($image['file_name'] ?? '작업사진')) ?>">
                      <img class="photo-thumb" src="<?= h((string)($image['file_path'] ?? '')) ?>" alt="<?= h((string)($image['file_name'] ?? '작업사진')) ?>">
                    </a>
                    <div class="photo-meta">
                      <div class="photo-date-row">
                        <label class="photo-check">
                          <input type="checkbox" name="report_image_ids[]" value="<?= (int)($image['report_image_id'] ?? 0) ?>">
                        </label>
                        <div class="photo-date"><?= h(substr((string)($image['created_at'] ?? ''), 0, 16)) ?></div>
                      </div>
                      <?php if (trim((string)($image['description'] ?? '')) !== ''): ?>
                        <div class="photo-description"><?= nl2br(h((string)$image['description'])) ?></div>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </form>
          <?php else: ?>
            <p class="empty-text">등록된 작업사진이 없습니다.</p>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
  <div class="photo-viewer" id="photo-viewer" aria-hidden="true">
    <div class="photo-viewer-dialog" role="dialog" aria-modal="true" aria-label="작업사진 보기">
      <button type="button" class="photo-viewer-close" id="photo-viewer-close" aria-label="닫기">&times;</button>
      <img class="photo-viewer-image" id="photo-viewer-image" src="" alt="">
    </div>
  </div>
  <div class="modal-backdrop" id="unit-preview-modal" aria-hidden="true">
    <div class="unit-preview-modal" role="dialog" aria-modal="true" aria-labelledby="unit-preview-title">
      <div class="unit-preview-head">
        <div>
          <h2 id="unit-preview-title">위험성평가 미리보기</h2>
          <p id="unit-preview-subtitle">데이터를 불러오는 중입니다.</p>
        </div>
        <button type="button" class="modal-close" id="unit-preview-close" aria-label="닫기">&times;</button>
      </div>
      <div class="unit-preview-body" id="unit-preview-body">
        <div class="unit-preview-loading">위험성평가 상세 정보를 불러오는 중입니다.</div>
      </div>
    </div>
  </div>
  <script>
    (() => {
      const modal = document.getElementById('unit-preview-modal');
      const closeButton = document.getElementById('unit-preview-close');
      const titleNode = document.getElementById('unit-preview-title');
      const subtitleNode = document.getElementById('unit-preview-subtitle');
      const bodyNode = document.getElementById('unit-preview-body');
      const triggers = document.querySelectorAll('.js-unit-preview-card');
      if (!modal || !closeButton || !titleNode || !subtitleNode || !bodyNode || !triggers.length) {
        return;
      }

      let requestToken = 0;
      let previousBodyOverflow = '';
      const unitTypeLabels = {
        target: '작업대상',
        major_work: '중대위험작업',
        tool: '공구/장비',
        env: '작업환경',
      };

      function escapeHtml(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function displayValue(value) {
        const text = String(value ?? '').trim();
        return text === '' ? '-' : escapeHtml(text);
      }

      function displayTextBlock(value) {
        const text = String(value ?? '').trim();
        return text === '' ? '-' : escapeHtml(text).replace(/\n/g, '<br>');
      }

      function formatDate(value) {
        const text = String(value ?? '').trim();
        return text === '' ? '' : text;
      }

      function renderMetaCard(label, value) {
        return `
          <div class="unit-preview-meta-card">
            <strong>${escapeHtml(label)}</strong>
            <span>${value}</span>
          </div>
        `;
      }

      function renderItems(items) {
        if (!Array.isArray(items) || items.length === 0) {
          return '<div class="unit-preview-empty">등록된 위험성평가 항목이 없습니다.</div>';
        }

        const renderRiskLine = (label, probability, severity, riskScore) => {
          const hasValue = String(probability ?? '').trim() !== ''
            || String(severity ?? '').trim() !== ''
            || String(riskScore ?? '').trim() !== '';

          if (!hasValue) {
            return '';
          }

          const p = String(probability ?? '').trim() || '-';
          const s = String(severity ?? '').trim() || '-';
          const r = String(riskScore ?? '').trim() || '-';
          const numericScore = Number.parseInt(String(riskScore ?? '').trim(), 10);
          let riskLevelClass = 'is-medium';
          let riskLevelLabel = '중위험';
          if (Number.isFinite(numericScore)) {
            if (numericScore <= 6) {
              riskLevelClass = 'is-low';
              riskLevelLabel = '저위험';
            } else if (numericScore >= 15) {
              riskLevelClass = 'is-high';
              riskLevelLabel = '고위험';
            }
          }

          return `
            <div class="risk-badge-line">
              <span class="risk-level-badge ${riskLevelClass}">${escapeHtml(riskLevelLabel)}</span>
              <span class="risk-score-text"><strong>${escapeHtml(label)}</strong> P ${escapeHtml(p)} / S ${escapeHtml(s)} / R ${escapeHtml(r)}</span>
            </div>
          `;
        };

        const rows = items.map((item, index) => {
          const sortNo = String(item.sort_no ?? '').trim() !== '' ? item.sort_no : (index + 1);
          const accidentSummary = [item.accident_type, item.injury_result]
            .map((part) => String(part ?? '').trim())
            .filter(Boolean)
            .join(' / ');
          const riskSummary = [
            renderRiskLine('현재', item.likelihood_before, item.severity_before, item.risk_score_before),
            renderRiskLine('조치후', item.likelihood_current, item.severity_current, item.risk_score_current),
            renderRiskLine('개선후', item.likelihood_after, item.severity_after, item.risk_score_after),
          ].filter(Boolean).join('');

          return `
            <tr>
              <td>${escapeHtml(sortNo)}</td>
              <td>${displayValue(item.task_name)}</td>
              <td>${escapeHtml(String(item.hazard_4m_label || item.hazard_4m || '-').trim() || '-')}</td>
              <td>
                <strong>${displayValue(item.hazard_name)}</strong>
                ${String(item.cause_text ?? '').trim() !== '' ? `<div class="sub-text" style="margin-top:6px">원인/위험상황: ${displayTextBlock(item.cause_text)}</div>` : ''}
              </td>
              <td>${accidentSummary !== '' ? escapeHtml(accidentSummary) : '-'}</td>
              <td>${riskSummary !== '' ? `<div class="sub-text">${riskSummary}</div>` : '-'}</td>
              <td>${displayTextBlock(item.current_control_text)}</td>
              <td>${displayTextBlock(item.additional_control_text)}</td>
            </tr>
          `;
        }).join('');

        return `
          <div class="unit-preview-table-wrap">
            <table class="unit-preview-table">
              <thead>
                <tr>
                  <th>No</th>
                  <th>작업절차</th>
                  <th>4M분류</th>
                  <th>유해위험요인</th>
                  <th>사고유형/상해결과</th>
                  <th>위험도</th>
                  <th>현재 조치</th>
                  <th>추가 조치</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        `;
      }

      function renderPreview(payload) {
        const header = payload && payload.header ? payload.header : {};
        const items = payload && Array.isArray(payload.items) ? payload.items : [];
        const unitTitle = String(header.unit_title ?? '').trim();
        const unitCode = String(header.unit_code ?? '').trim();
        titleNode.textContent = unitTitle || unitCode || '위험성평가 미리보기';
        subtitleNode.textContent = unitCode
          ? `${unitCode} · 항목 ${items.length}건`
          : `항목 ${items.length}건`;

        bodyNode.innerHTML = `
          <div class="unit-preview-meta-grid">
            ${renderMetaCard('위험성평가번호', displayValue(header.unit_code))}
            ${renderMetaCard('작업표준서번호', displayValue(header.safe_work_standard_no))}
            ${renderMetaCard('평가유형', displayValue(unitTypeLabels[String(header.unit_type ?? '').trim()] || header.unit_type))}
            ${renderMetaCard('공정명', displayValue(header.process_name))}
            ${renderMetaCard('평가서명', displayValue(header.unit_title))}
            ${renderMetaCard('평가자', displayValue(header.evaluator_name))}
            ${renderMetaCard('등록일', displayValue(formatDate(header.created_at)))}
            ${renderMetaCard('수정일', displayValue(formatDate(header.updated_at)))}
          </div>
          <section class="unit-preview-section">
            <h3>비고</h3>
            <div class="unit-preview-remark">${displayTextBlock(header.remark)}</div>
          </section>
          <section class="unit-preview-section">
            <h3>위험성평가 항목</h3>
            ${renderItems(items)}
          </section>
        `;
      }

      function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousBodyOverflow;
      }

      function openModal(unitRaId) {
        if (!Number.isInteger(unitRaId) || unitRaId <= 0) {
          return;
        }

        requestToken += 1;
        const currentToken = requestToken;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        titleNode.textContent = '위험성평가 미리보기';
        subtitleNode.textContent = '데이터를 불러오는 중입니다.';
        bodyNode.innerHTML = '<div class="unit-preview-loading">위험성평가 상세 정보를 불러오는 중입니다.</div>';

        fetch(`unit_ra_header_api.php?action=preview&unit_ra_id=${encodeURIComponent(unitRaId)}`)
          .then((response) => response.json())
          .then((json) => {
            if (currentToken !== requestToken) {
              return;
            }
            if (!json || !json.success) {
              throw new Error(json && json.message ? json.message : '위험성평가 정보를 불러오지 못했습니다.');
            }
            renderPreview(json.data || {});
          })
          .catch((error) => {
            if (currentToken !== requestToken) {
              return;
            }
            titleNode.textContent = '위험성평가 미리보기';
            subtitleNode.textContent = '데이터를 불러오지 못했습니다.';
            bodyNode.innerHTML = `<div class="unit-preview-error">${escapeHtml(error && error.message ? error.message : '오류가 발생했습니다.')}</div>`;
          });
      }

      triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
          openModal(Number(trigger.dataset.unitRaId || 0));
        });
        trigger.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openModal(Number(trigger.dataset.unitRaId || 0));
          }
        });
      });

      closeButton.addEventListener('click', closeModal);
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal();
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
          closeModal();
        }
      });
    })();

    (() => {
      const toggleButton = document.getElementById('hazard-summary-toggle');
      const summaryPanel = document.getElementById('hazard-summary-panel');
      if (!toggleButton || !summaryPanel) {
        return;
      }

      toggleButton.addEventListener('click', () => {
        const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';
        const nextExpanded = !isExpanded;
        toggleButton.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
        summaryPanel.hidden = !nextExpanded;
        toggleButton.querySelector('.toggle-hint').textContent = nextExpanded ? '클릭해서 닫기' : '클릭해서 보기';
      });
    })();

    (() => {
      const viewer = document.getElementById('photo-viewer');
      const viewerImage = document.getElementById('photo-viewer-image');
      const viewerClose = document.getElementById('photo-viewer-close');
      const triggers = document.querySelectorAll('.js-photo-viewer-trigger');
      if (!viewer || !viewerImage || !viewerClose || !triggers.length) {
        return;
      }

      let zoomScale = 1;
      let panX = 0;
      let panY = 0;
      let isPanning = false;
      let panStartX = 0;
      let panStartY = 0;
      let panOriginX = 0;
      let panOriginY = 0;

      function clampPan() {
        if (zoomScale <= 1) {
          panX = 0;
          panY = 0;
          return;
        }

        const rect = viewerImage.getBoundingClientRect();
        const scaledWidth = rect.width * zoomScale;
        const scaledHeight = rect.height * zoomScale;
        const maxPanX = Math.max(0, (scaledWidth - rect.width) / 2);
        const maxPanY = Math.max(0, (scaledHeight - rect.height) / 2);

        panX = Math.min(maxPanX, Math.max(-maxPanX, panX));
        panY = Math.min(maxPanY, Math.max(-maxPanY, panY));
      }

      function applyZoom() {
        clampPan();
        viewerImage.style.transform = `translate(${panX}px, ${panY}px) scale(${zoomScale})`;
      }

      function closeViewer() {
        viewer.classList.remove('is-open');
        viewer.setAttribute('aria-hidden', 'true');
        viewerImage.src = '';
        viewerImage.alt = '';
        zoomScale = 1;
        panX = 0;
        panY = 0;
        isPanning = false;
        viewerImage.classList.remove('is-panning');
        applyZoom();
        document.body.style.overflow = '';
      }

      function openViewer(src, alt) {
        viewerImage.src = src;
        viewerImage.alt = alt || '작업사진';
        zoomScale = 1;
        panX = 0;
        panY = 0;
        applyZoom();
        viewer.classList.add('is-open');
        viewer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      }

      triggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          openViewer(trigger.dataset.photoSrc || '', trigger.dataset.photoAlt || '');
        });
      });

      viewerClose.addEventListener('click', closeViewer);
      viewer.addEventListener('click', (event) => {
        if (event.target === viewer) {
          closeViewer();
        }
      });
      viewerImage.addEventListener('mousedown', (event) => {
        if (event.button !== 0 || !viewer.classList.contains('is-open')) {
          return;
        }
        event.preventDefault();
        isPanning = true;
        panStartX = event.clientX;
        panStartY = event.clientY;
        panOriginX = panX;
        panOriginY = panY;
        viewerImage.classList.add('is-panning');
      });
      document.addEventListener('mousemove', (event) => {
        if (!isPanning || !viewer.classList.contains('is-open')) {
          return;
        }
        event.preventDefault();
        panX = panOriginX + (event.clientX - panStartX);
        panY = panOriginY + (event.clientY - panStartY);
        applyZoom();
      });
      document.addEventListener('mouseup', () => {
        isPanning = false;
        viewerImage.classList.remove('is-panning');
      });
      viewerImage.addEventListener('wheel', (event) => {
        if (!viewer.classList.contains('is-open')) {
          return;
        }
        event.preventDefault();
        const delta = event.deltaY < 0 ? 0.12 : -0.12;
        const nextScale = zoomScale + delta;
        zoomScale = Math.min(4, Math.max(0.5, nextScale));
        applyZoom();
      });
      viewerImage.addEventListener('dragstart', (event) => {
        event.preventDefault();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && viewer.classList.contains('is-open')) {
          closeViewer();
        }
      });
    })();
  </script>
</body>
</html>
