<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function nullable_text($value): ?string
{
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function nullable_int_value($value, ?int $min = null, ?int $max = null): ?int
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    $intValue = (int)$value;
    if ($min !== null && $intValue < $min) {
        return null;
    }
    if ($max !== null && $intValue > $max) {
        return null;
    }

    return $intValue;
}

function score_from_pair(?int $likelihood, ?int $severity): ?int
{
    if ($likelihood === null || $severity === null) {
        return null;
    }

    return $likelihood * $severity;
}

function blank_requested_hazard_item(array $defaults = []): array
{
    return array_merge([
        'unit_ra_id' => 0,
        'unit_title' => '',
        'unit_code' => '',
        'sort_no' => '',
        'task_code' => '',
        'task_name' => '',
        'hazard_name' => '',
        'accident_type' => '',
        'injury_result' => '',
        'cause_text' => '',
        'current_control_text' => '',
        'additional_control_text' => '',
        'likelihood_before' => '',
        'severity_before' => '',
        'risk_score_before' => '',
        'likelihood_current' => '',
        'severity_current' => '',
        'risk_score_current' => '',
        'likelihood_after' => '',
        'severity_after' => '',
        'risk_score_after' => '',
        'improvement_due_date' => '',
        'remark' => '',
        'use_yn' => 'Y',
    ], $defaults);
}

function normalize_requested_hazard_items($payload): array
{
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($payload)) {
        return [];
    }

    $items = [];
    foreach ($payload as $item) {
        if (!is_array($item)) {
            continue;
        }

        $unitRaId = (int)($item['unit_ra_id'] ?? 0);
        $taskName = trim((string)($item['task_name'] ?? ''));
        $hazardName = trim((string)($item['hazard_name'] ?? ''));
        if ($unitRaId <= 0 || $taskName === '' || $hazardName === '') {
            continue;
        }

        $likelihoodBefore = nullable_int_value($item['likelihood_before'] ?? null, 1, 5);
        $severityBefore = nullable_int_value($item['severity_before'] ?? null, 1, 5);
        $likelihoodCurrent = nullable_int_value($item['likelihood_current'] ?? null, 1, 5);
        $severityCurrent = $severityBefore;
        $likelihoodAfter = nullable_int_value($item['likelihood_after'] ?? null, 1, 5);
        $severityAfter = nullable_int_value($item['severity_after'] ?? null, 1, 5);

        $items[] = blank_requested_hazard_item([
            'unit_ra_id' => $unitRaId,
            'unit_title' => trim((string)($item['unit_title'] ?? '')),
            'unit_code' => trim((string)($item['unit_code'] ?? '')),
            'sort_no' => nullable_int_value($item['sort_no'] ?? null) ?? '',
            'task_code' => trim((string)($item['task_code'] ?? '')),
            'task_name' => $taskName,
            'hazard_name' => $hazardName,
            'accident_type' => trim((string)($item['accident_type'] ?? '')),
            'injury_result' => trim((string)($item['injury_result'] ?? '')),
            'cause_text' => trim((string)($item['cause_text'] ?? '')),
            'current_control_text' => trim((string)($item['current_control_text'] ?? '')),
            'additional_control_text' => trim((string)($item['additional_control_text'] ?? '')),
            'likelihood_before' => $likelihoodBefore ?? '',
            'severity_before' => $severityBefore ?? '',
            'risk_score_before' => score_from_pair($likelihoodBefore, $severityBefore) ?? '',
            'likelihood_current' => $likelihoodCurrent ?? '',
            'severity_current' => $severityCurrent ?? '',
            'risk_score_current' => score_from_pair($likelihoodCurrent, $severityCurrent) ?? '',
            'likelihood_after' => $likelihoodAfter ?? '',
            'severity_after' => $severityAfter ?? '',
            'risk_score_after' => score_from_pair($likelihoodAfter, $severityAfter) ?? '',
            'improvement_due_date' => trim((string)($item['improvement_due_date'] ?? '')),
            'remark' => trim((string)($item['remark'] ?? '')),
            'use_yn' => ((string)($item['use_yn'] ?? 'Y')) === 'N' ? 'N' : 'Y',
        ]);
    }

    return $items;
}

function ensure_table_column_exists(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
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

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec(sprintf(
        'ALTER TABLE `%s` ADD COLUMN `%s` %s',
        str_replace('`', '``', $tableName),
        str_replace('`', '``', $columnName),
        $definition
    ));
}

function get_board_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=board;charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

function hazard_board_role(array $user): string
{
    $role = (string)($user['role'] ?? '');
    return in_array($role, ['admin', 'manager'], true) ? 'admin' : 'user';
}

function ensure_board_change_request_category(PDO $boardPdo): int
{
    $stmt = $boardPdo->prepare("
        SELECT id
        FROM categories
        WHERE code = :code
        LIMIT 1
    ");
    $stmt->execute([':code' => 'change_request']);
    $categoryId = (int)$stmt->fetchColumn();
    if ($categoryId > 0) {
        return $categoryId;
    }

    $sortOrder = (int)$boardPdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories")->fetchColumn();
    $insertStmt = $boardPdo->prepare("
        INSERT INTO categories (code, name, sort_order, write_role, is_active)
        VALUES (:code, :name, :sort_order, :write_role, 1)
    ");
    $insertStmt->execute([
        ':code' => 'change_request',
        ':name' => '수정요청',
        ':sort_order' => $sortOrder,
        ':write_role' => 'user',
    ]);

    return (int)$boardPdo->lastInsertId();
}

function sync_board_user_record(PDO $boardPdo, array $user): void
{
    $loginId = trim((string)($user['login_id'] ?? ''));
    if ($loginId === '') {
        return;
    }

    $stmt = $boardPdo->prepare("
        INSERT INTO users (id, name, dept, role, last_seen)
        VALUES (:id, :name, :dept, :role, NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            dept = VALUES(dept),
            role = VALUES(role),
            last_seen = NOW()
    ");
    $stmt->execute([
        ':id' => $loginId,
        ':name' => (string)($user['name'] ?? $loginId),
        ':dept' => trim((string)($user['team'] ?? '')),
        ':role' => hazard_board_role($user),
    ]);
}

function trim_board_post_title(string $title, int $maxLength = 200): string
{
    $title = trim($title);
    if ($title === '') {
        return '위험성평가 수정요청';
    }

    if (function_exists('mb_strimwidth')) {
        return rtrim(mb_strimwidth($title, 0, $maxLength, '', 'UTF-8'));
    }

    return substr($title, 0, $maxLength);
}

function build_hazard_change_request_board_title(array $report, array $user): string
{
    $date = trim((string)($report['work_date'] ?? ''));
    $title = trim((string)($report['work_title'] ?? ''));
    $name = trim((string)($user['name'] ?? ''));

    $parts = ['[위험성평가 수정요청]'];
    if ($date !== '') {
        $parts[] = $date;
    }
    if ($title !== '') {
        $parts[] = $title;
    }
    if ($name !== '') {
        $parts[] = $name;
    }

    return trim_board_post_title(implode(' / ', $parts));
}

function build_hazard_change_request_board_content(array $report, array $user, string $requestText): string
{
    $lines = [
        '위험성평가 수정요청이 위험성평가 설문 화면에서 자동 등록되었습니다.',
        '',
        '보고서 정보',
        '- 보고서 ID: ' . (int)($report['report_id'] ?? 0),
        '- 작업일자: ' . trim((string)($report['work_date'] ?? '')),
        '- 작업명: ' . trim((string)($report['work_title'] ?? '')),
        '- 작업장소: ' . trim((string)($report['work_place'] ?? '')),
        '- 기준 위험성평가: ' . trim((string)($report['unit_title'] ?? '')),
        '- 요청자: ' . trim((string)($user['name'] ?? '')),
        '- 요청자 계정: ' . trim((string)($user['login_id'] ?? '')),
        '- 요청 시각: ' . date('Y-m-d H:i:s'),
        '',
        '수정요청 내용',
        $requestText,
    ];

    return implode("\n", $lines);
}

function sync_hazard_change_request_board_post(PDO $boardPdo, array $report, array $user, string $requestText, int $existingBoardPostId = 0): int
{
    sync_board_user_record($boardPdo, $user);
    $categoryId = ensure_board_change_request_category($boardPdo);
    $title = build_hazard_change_request_board_title($report, $user);
    $content = build_hazard_change_request_board_content($report, $user, $requestText);
    $authorId = trim((string)($user['login_id'] ?? ''));
    $authorName = trim((string)($user['name'] ?? $authorId));
    $authorDept = trim((string)($user['team'] ?? ''));

    if ($existingBoardPostId > 0) {
        $existsStmt = $boardPdo->prepare("SELECT id FROM posts WHERE id = :id LIMIT 1");
        $existsStmt->execute([':id' => $existingBoardPostId]);
        if ((int)$existsStmt->fetchColumn() > 0) {
            $updateStmt = $boardPdo->prepare("
                UPDATE posts
                SET category_id = :category_id,
                    title = :title,
                    content = :content,
                    author_name = :author_name,
                    author_dept = :author_dept,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':category_id' => $categoryId,
                ':title' => $title,
                ':content' => $content,
                ':author_name' => $authorName,
                ':author_dept' => nullable_text($authorDept),
                ':id' => $existingBoardPostId,
            ]);

            return $existingBoardPostId;
        }
    }

    $insertStmt = $boardPdo->prepare("
        INSERT INTO posts (
            category_id, title, content, author_id, author_name, author_dept, is_notice
        ) VALUES (
            :category_id, :title, :content, :author_id, :author_name, :author_dept, 0
        )
    ");
    $insertStmt->execute([
        ':category_id' => $categoryId,
        ':title' => $title,
        ':content' => $content,
        ':author_id' => $authorId,
        ':author_name' => $authorName,
        ':author_dept' => nullable_text($authorDept),
    ]);

    return (int)$boardPdo->lastInsertId();
}

function upsert_hazard_change_request_record(PDO $pdo, int $reportId, array $user, string $requestText, int $boardPostId = 0): void
{
    $stmt = $pdo->prepare("
        INSERT INTO work_report_hazard_change_request (
            report_id, user_login_id, user_name, request_text, board_post_id
        ) VALUES (
            :report_id, :user_login_id, :user_name, :request_text, :board_post_id
        )
        ON DUPLICATE KEY UPDATE
            user_name = VALUES(user_name),
            request_text = VALUES(request_text),
            board_post_id = CASE
                WHEN VALUES(board_post_id) IS NULL OR VALUES(board_post_id) = 0 THEN board_post_id
                ELSE VALUES(board_post_id)
            END,
            updated_at = NOW()
    ");
    $stmt->execute([
        ':report_id' => $reportId,
        ':user_login_id' => (string)($user['login_id'] ?? ''),
        ':user_name' => (string)($user['name'] ?? ''),
        ':request_text' => $requestText,
        ':board_post_id' => $boardPostId > 0 ? $boardPostId : null,
    ]);
}

function ensureHazardSurveyTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_selected_unit (
            report_selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_selection_id),
            UNIQUE KEY uk_work_report_selected_unit (report_id, unit_ra_id),
            KEY idx_work_report_selected_unit_report (report_id),
            KEY idx_work_report_selected_unit_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_hazard_selection (
            selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (selection_id),
            UNIQUE KEY uk_work_report_hazard_selection (report_id, item_id),
            KEY idx_work_report_hazard_selection_report (report_id),
            KEY idx_work_report_hazard_selection_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_hazard_change_request (
            request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            request_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (request_id),
            UNIQUE KEY uk_work_report_hazard_change_request (report_id, user_login_id),
            KEY idx_work_report_hazard_change_request_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_hazard_addition (
            addition_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            sort_no INT NULL,
            task_code VARCHAR(100) NULL,
            task_name VARCHAR(255) NOT NULL,
            hazard_name TEXT NOT NULL,
            accident_type VARCHAR(255) NULL,
            injury_result VARCHAR(255) NULL,
            cause_text TEXT NULL,
            current_control_text TEXT NULL,
            additional_control_text TEXT NULL,
            likelihood_before TINYINT NULL,
            severity_before TINYINT NULL,
            risk_score_before INT NULL,
            likelihood_current TINYINT NULL,
            severity_current TINYINT NULL,
            risk_score_current INT NULL,
            likelihood_after TINYINT NULL,
            severity_after TINYINT NULL,
            risk_score_after INT NULL,
            improvement_due_date DATE NULL,
            remark TEXT NULL,
            use_yn CHAR(1) NOT NULL DEFAULT 'Y',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (addition_id),
            KEY idx_work_report_hazard_addition_report (report_id),
            KEY idx_work_report_hazard_addition_user (user_login_id),
            KEY idx_work_report_hazard_addition_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensure_table_column_exists(
        $pdo,
        'work_report_hazard_change_request',
        'board_post_id',
        'INT NULL AFTER request_text'
    );
}

function resolveSelectedRiskAssessments(PDO $pdo, array $report): array
{
    $selected = [];

    $baseUnitId = (int)($report['unit_ra_id'] ?? 0);
    if ($baseUnitId > 0) {
        $selected[$baseUnitId] = $baseUnitId;
    }

    $selectedUnitStmt = $pdo->prepare("
        SELECT unit_ra_id
        FROM work_report_selected_unit
        WHERE report_id = :report_id
        ORDER BY sort_no ASC, report_selection_id ASC
    ");
    $selectedUnitStmt->execute([':report_id' => (int)$report['report_id']]);
    foreach ($selectedUnitStmt->fetchAll(PDO::FETCH_COLUMN) as $selectedUnitId) {
        $unitId = (int)$selectedUnitId;
        if ($unitId > 0) {
            $selected[$unitId] = $unitId;
        }
    }

    $detailStmt = $pdo->prepare("
        SELECT task_name
        FROM work_report_detail
        WHERE report_id = :report_id
        ORDER BY report_detail_id ASC
    ");
    $detailStmt->execute([':report_id' => (int)$report['report_id']]);
    $detailValues = $detailStmt->fetchAll(PDO::FETCH_COLUMN);

    $lookupStmt = $pdo->prepare("
        SELECT unit_ra_id
        FROM unit_ra_header
        WHERE use_yn = 'Y'
          AND unit_type = :unit_type
          AND unit_title = :unit_title
        ORDER BY sort_no ASC, unit_ra_id DESC
        LIMIT 1
    ");

    foreach ($detailValues as $detailValue) {
        $parsed = parse_detail_selection((string)$detailValue);
        $lookupType = '';
        $lookupTitle = '';

        if ($parsed['type'] === 'major_work' && $parsed['title'] !== '') {
            $lookupType = 'major_work';
            $lookupTitle = $parsed['title'];
        } elseif ($parsed['type'] === 'major_work_sub' && $parsed['parent'] !== '' && $parsed['title'] !== '') {
            $lookupType = 'major_work';
            $lookupTitle = $parsed['parent'] . ' - ' . $parsed['title'];
        } elseif (in_array($parsed['type'], ['env', 'tool'], true) && $parsed['title'] !== '') {
            $lookupType = $parsed['type'];
            $lookupTitle = $parsed['title'];
        }

        if ($lookupType === '' || $lookupTitle === '') {
            continue;
        }

        $lookupStmt->execute([
            ':unit_type' => $lookupType,
            ':unit_title' => $lookupTitle,
        ]);
        $unitId = (int)$lookupStmt->fetchColumn();
        if ($unitId > 0) {
            $selected[$unitId] = $unitId;
        }
    }

    if (empty($selected)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $stmt = $pdo->prepare("
        SELECT unit_ra_id, unit_code, unit_title, unit_type, process_name, sort_no
        FROM unit_ra_header
        WHERE unit_ra_id IN ($placeholders)
        ORDER BY sort_no ASC, unit_ra_id ASC
    ");
    $stmt->execute(array_values($selected));

    $assessments = $stmt->fetchAll() ?: [];
    $typeOrder = [
        'major_work' => 1,
        'tool' => 2,
        'env' => 3,
    ];

    usort($assessments, static function (array $left, array $right) use ($baseUnitId, $typeOrder): int {
        $leftRank = ((int)($left['unit_ra_id'] ?? 0) === $baseUnitId)
            ? 0
            : ($typeOrder[(string)($left['unit_type'] ?? '')] ?? 9);
        $rightRank = ((int)($right['unit_ra_id'] ?? 0) === $baseUnitId)
            ? 0
            : ($typeOrder[(string)($right['unit_type'] ?? '')] ?? 9);

        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        $leftSort = (int)($left['sort_no'] ?? 0);
        $rightSort = (int)($right['sort_no'] ?? 0);
        if ($leftSort !== $rightSort) {
            return $leftSort <=> $rightSort;
        }

        return ((int)($left['unit_ra_id'] ?? 0)) <=> ((int)($right['unit_ra_id'] ?? 0));
    });

    return $assessments;
}

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

// Prevent browser form-state/cache reuse across different user sessions.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = getDB();
ensureHazardSurveyTables($pdo);
$isWorkerSurveyUser = auth_is_worker($user);

$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : (int)($_POST['report_id'] ?? 0);
$message = '';
$error = '';
$report = null;

if ($reportId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            wr.report_id,
            wr.unit_ra_id,
            wr.user_name,
            wr.work_title,
            wr.work_date,
            wr.work_place,
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
}

if (!$report) {
    http_response_code(404);
    $error = '작업 정보를 찾을 수 없습니다.';
}

$requestAction = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $report && $requestAction === 'save_change_request') {
    header('Content-Type: application/json; charset=UTF-8');

    $changeRequestText = trim((string)($_POST['change_request_text'] ?? ''));
    if ($changeRequestText === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => '수정요청 내용을 입력해주세요.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $existingStmt = $pdo->prepare("
            SELECT board_post_id
            FROM work_report_hazard_change_request
            WHERE report_id = :report_id
              AND user_login_id = :user_login_id
            LIMIT 1
        ");
        $existingStmt->execute([
            ':report_id' => $reportId,
            ':user_login_id' => (string)$user['login_id'],
        ]);
        $existingBoardPostId = (int)$existingStmt->fetchColumn();

        $boardPostId = sync_hazard_change_request_board_post(
            get_board_db(),
            $report,
            $user,
            $changeRequestText,
            $existingBoardPostId
        );

        upsert_hazard_change_request_record(
            $pdo,
            $reportId,
            $user,
            $changeRequestText,
            $boardPostId
        );

        echo json_encode([
            'success' => true,
            'message' => '수정요청이 저장되었고 게시판에도 등록되었습니다.',
            'board_post_id' => $boardPostId,
            'board_url' => '../board/view.php?id=' . $boardPostId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '수정요청 저장 중 오류가 발생했습니다: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $report && $requestAction !== 'save_change_request') {
    $selectedItems = array_values(array_unique(array_map('intval', $_POST['selected_items'] ?? [])));
    $selectedMap = array_fill_keys(array_filter($selectedItems, static fn($value) => $value > 0), true);
    $changeRequestText = trim((string)($_POST['change_request_text'] ?? ''));
    $additionalRequestItems = normalize_requested_hazard_items($_POST['additional_items_json'] ?? '[]');

    try {
        $pdo->beginTransaction();
        if ($isWorkerSurveyUser) {
            $pdo->prepare("
                DELETE FROM work_report_worker_hazard_selection
                WHERE report_id = :report_id
                  AND user_login_id = :user_login_id
            ")->execute([
                ':report_id' => $reportId,
                ':user_login_id' => (string)$user['login_id'],
            ]);
        } else {
            $pdo->prepare("DELETE FROM work_report_hazard_selection WHERE report_id = :report_id")
                ->execute([':report_id' => $reportId]);
        }

        if (!empty($selectedMap)) {
            $riskAssessments = resolveSelectedRiskAssessments($pdo, $report);
            $allowedItems = [];
            $itemStmt = $pdo->prepare("
                SELECT item_id, unit_ra_id
                FROM unit_ra_item
                WHERE unit_ra_id = :unit_ra_id
                  AND use_yn = 'Y'
            ");
            foreach ($riskAssessments as $assessment) {
                $itemStmt->execute([':unit_ra_id' => (int)$assessment['unit_ra_id']]);
                foreach ($itemStmt->fetchAll() as $itemRow) {
                    $allowedItems[(int)$itemRow['item_id']] = (int)$itemRow['unit_ra_id'];
                }
            }

            if ($isWorkerSurveyUser) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO work_report_worker_hazard_selection (
                        report_id, user_login_id, user_name, unit_ra_id, item_id
                    ) VALUES (
                        :report_id, :user_login_id, :user_name, :unit_ra_id, :item_id
                    )
                ");
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO work_report_hazard_selection (
                        report_id, unit_ra_id, item_id
                    ) VALUES (
                        :report_id, :unit_ra_id, :item_id
                    )
                ");
            }

            foreach (array_keys($selectedMap) as $itemId) {
                if (!isset($allowedItems[$itemId])) {
                    continue;
                }
                if ($isWorkerSurveyUser) {
                    $insertStmt->execute([
                        ':report_id' => $reportId,
                        ':user_login_id' => (string)$user['login_id'],
                        ':user_name' => (string)$user['name'],
                        ':unit_ra_id' => $allowedItems[$itemId],
                        ':item_id' => $itemId,
                    ]);
                } else {
                    $insertStmt->execute([
                        ':report_id' => $reportId,
                        ':unit_ra_id' => $allowedItems[$itemId],
                        ':item_id' => $itemId,
                    ]);
                }
            }
        }

        $pdo->prepare("
            DELETE FROM work_report_hazard_addition
            WHERE report_id = :report_id
              AND user_login_id = :user_login_id
        ")->execute([
            ':report_id' => $reportId,
            ':user_login_id' => (string)$user['login_id'],
        ]);

        if ($changeRequestText !== '') {
            upsert_hazard_change_request_record(
                $pdo,
                $reportId,
                $user,
                $changeRequestText
            );
        }

        if (!empty($additionalRequestItems)) {
            $additionInsertStmt = $pdo->prepare("
                INSERT INTO work_report_hazard_addition (
                    report_id, user_login_id, user_name, unit_ra_id, sort_no, task_code,
                    task_name, hazard_name, accident_type, injury_result, cause_text,
                    current_control_text, additional_control_text,
                    likelihood_before, severity_before, risk_score_before,
                    likelihood_current, severity_current, risk_score_current,
                    likelihood_after, severity_after, risk_score_after,
                    improvement_due_date, remark, use_yn
                ) VALUES (
                    :report_id, :user_login_id, :user_name, :unit_ra_id, :sort_no, :task_code,
                    :task_name, :hazard_name, :accident_type, :injury_result, :cause_text,
                    :current_control_text, :additional_control_text,
                    :likelihood_before, :severity_before, :risk_score_before,
                    :likelihood_current, :severity_current, :risk_score_current,
                    :likelihood_after, :severity_after, :risk_score_after,
                    :improvement_due_date, :remark, :use_yn
                )
            ");

            foreach ($additionalRequestItems as $requestItem) {
                $additionInsertStmt->execute([
                    ':report_id' => $reportId,
                    ':user_login_id' => (string)$user['login_id'],
                    ':user_name' => (string)$user['name'],
                    ':unit_ra_id' => (int)$requestItem['unit_ra_id'],
                    ':sort_no' => $requestItem['sort_no'] === '' ? null : (int)$requestItem['sort_no'],
                    ':task_code' => nullable_text($requestItem['task_code']),
                    ':task_name' => (string)$requestItem['task_name'],
                    ':hazard_name' => (string)$requestItem['hazard_name'],
                    ':accident_type' => nullable_text($requestItem['accident_type']),
                    ':injury_result' => nullable_text($requestItem['injury_result']),
                    ':cause_text' => nullable_text($requestItem['cause_text']),
                    ':current_control_text' => nullable_text($requestItem['current_control_text']),
                    ':additional_control_text' => nullable_text($requestItem['additional_control_text']),
                    ':likelihood_before' => $requestItem['likelihood_before'] === '' ? null : (int)$requestItem['likelihood_before'],
                    ':severity_before' => $requestItem['severity_before'] === '' ? null : (int)$requestItem['severity_before'],
                    ':risk_score_before' => $requestItem['risk_score_before'] === '' ? null : (int)$requestItem['risk_score_before'],
                    ':likelihood_current' => $requestItem['likelihood_current'] === '' ? null : (int)$requestItem['likelihood_current'],
                    ':severity_current' => $requestItem['severity_current'] === '' ? null : (int)$requestItem['severity_current'],
                    ':risk_score_current' => $requestItem['risk_score_current'] === '' ? null : (int)$requestItem['risk_score_current'],
                    ':likelihood_after' => $requestItem['likelihood_after'] === '' ? null : (int)$requestItem['likelihood_after'],
                    ':severity_after' => $requestItem['severity_after'] === '' ? null : (int)$requestItem['severity_after'],
                    ':risk_score_after' => $requestItem['risk_score_after'] === '' ? null : (int)$requestItem['risk_score_after'],
                    ':improvement_due_date' => nullable_text($requestItem['improvement_due_date']),
                    ':remark' => nullable_text($requestItem['remark']),
                    ':use_yn' => (string)$requestItem['use_yn'] === 'N' ? 'N' : 'Y',
                ]);
            }
        }

        $pdo->commit();
        header('Location: hazard_review.php?report_id=' . $reportId . '&submitted=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '저장 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

$riskAssessments = [];
$selectedItemIds = [];
$changeRequestText = '';
$additionalRequestItems = [];
$existingTaskCodesByUnit = [];

if ($report) {
    $riskAssessments = resolveSelectedRiskAssessments($pdo, $report);

    if ($isWorkerSurveyUser) {
        $savedStmt = $pdo->prepare("
            SELECT item_id
            FROM work_report_worker_hazard_selection
            WHERE report_id = :report_id
              AND user_login_id = :user_login_id
        ");
        $savedStmt->execute([
            ':report_id' => $reportId,
            ':user_login_id' => (string)$user['login_id'],
        ]);
    } else {
        $savedStmt = $pdo->prepare("
            SELECT item_id
            FROM work_report_hazard_selection
            WHERE report_id = :report_id
        ");
        $savedStmt->execute([':report_id' => $reportId]);
    }
    $selectedItemIds = array_map('intval', $savedStmt->fetchAll(PDO::FETCH_COLUMN));

    $itemStmt = $pdo->prepare("
        SELECT
            item_id,
            unit_ra_id,
            sort_no,
            task_code,
            task_name,
            hazard_name,
            accident_type,
            injury_result,
            current_control_text,
            additional_control_text
        FROM unit_ra_item
        WHERE unit_ra_id = :unit_ra_id
          AND use_yn = 'Y'
        ORDER BY sort_no ASC, item_id ASC
    ");

    foreach ($riskAssessments as &$assessment) {
        $itemStmt->execute([':unit_ra_id' => (int)$assessment['unit_ra_id']]);
        $assessment['items'] = $itemStmt->fetchAll() ?: [];
    }
    unset($assessment);

    foreach ($riskAssessments as $assessment) {
        $unitRaId = (int)($assessment['unit_ra_id'] ?? 0);
        if ($unitRaId <= 0) {
            continue;
        }

        foreach ($assessment['items'] as $item) {
            $taskCode = trim((string)($item['task_code'] ?? ''));
            if ($taskCode === '') {
                continue;
            }

            if (!isset($existingTaskCodesByUnit[$unitRaId])) {
                $existingTaskCodesByUnit[$unitRaId] = [];
            }
            $existingTaskCodesByUnit[$unitRaId][] = $taskCode;
        }
    }

    $changeRequestStmt = $pdo->prepare("
        SELECT request_text
        FROM work_report_hazard_change_request
        WHERE report_id = :report_id
          AND user_login_id = :user_login_id
        LIMIT 1
    ");
    $changeRequestStmt->execute([
        ':report_id' => $reportId,
        ':user_login_id' => (string)$user['login_id'],
    ]);
    $changeRequestText = trim((string)$changeRequestStmt->fetchColumn());

    $additionStmt = $pdo->prepare("
        SELECT
            a.unit_ra_id,
            h.unit_title,
            h.unit_code,
            a.sort_no,
            a.task_code,
            a.task_name,
            a.hazard_name,
            a.accident_type,
            a.injury_result,
            a.cause_text,
            a.current_control_text,
            a.additional_control_text,
            a.likelihood_before,
            a.severity_before,
            a.risk_score_before,
            a.likelihood_current,
            a.severity_current,
            a.risk_score_current,
            a.likelihood_after,
            a.severity_after,
            a.risk_score_after,
            a.improvement_due_date,
            a.remark,
            a.use_yn
        FROM work_report_hazard_addition a
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = a.unit_ra_id
        WHERE a.report_id = :report_id
          AND a.user_login_id = :user_login_id
        ORDER BY a.unit_ra_id ASC, a.sort_no ASC, a.addition_id ASC
    ");
    $additionStmt->execute([
        ':report_id' => $reportId,
        ':user_login_id' => (string)$user['login_id'],
    ]);
    $additionalRequestItems = normalize_requested_hazard_items($additionStmt->fetchAll() ?: []);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>위험성평가</title>
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
    --blue-dim: rgba(58,127,193,0.18);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Malgun Gothic", sans-serif;
    background: var(--bg) !important;
    min-height: 100vh;
    color: var(--text) !important;
    padding: 28px 20px 48px;
  }
  .shell { max-width: 1080px; margin: 0 auto; }

  /* ── topbar ── */
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

  /* ── main panel ── */
  .panel {
    background: var(--bg2) !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    overflow: hidden;
    box-shadow: none !important;
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
  .panel-head p {
    color: var(--text-dim);
    font-size: 13px;
    line-height: 1.6;
  }
  .content { padding: 22px 28px 28px; }

  /* ── alerts ── */
  .message, .error {
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    font-size: 13px;
  }
  .message { background: rgba(30,90,50,.35); border: 1px solid rgba(60,180,90,.3); color: #6de09a; }
  .error    { background: rgba(90,20,20,.35); border: 1px solid rgba(200,60,60,.3); color: #f09090; }

  /* ── progress bar ── */
  .progress {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
  }
  .progress strong { color: var(--text-hi); font-size: 16px; }
  .progress span   { color: var(--text-dim); font-size: 13px; }
  .page-dots { display: flex; gap: 6px; flex-wrap: wrap; }
  .page-dots button {
    width: 10px; height: 10px;
    border-radius: 999px;
    border: none;
    background: rgba(255,255,255,0.12);
    cursor: pointer;
    transition: background .15s;
  }
  .page-dots button.is-active { background: var(--accent); }

  /* ── survey page ── */
  .survey-page {
    display: none;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    background: var(--bg3);
  }
  .survey-page.is-active { display: block; }
  .survey-header { margin-bottom: 16px; }
  .survey-header h2 {
    font-size: 20px;
    font-weight: 800;
    color: var(--text-hi);
    margin-bottom: 6px;
  }
  .survey-header .sub { color: var(--text-dim); font-size: 13px; line-height: 1.55; }
  .survey-header .sub .code-tag {
    display: inline-block;
    background: rgba(232,146,10,0.18);
    color: var(--accent2);
    border: 1px solid rgba(232,146,10,0.3);
    border-radius: 6px;
    padding: 1px 8px;
    font-size: 12px;
    font-weight: 700;
    margin-left: 6px;
  }

  /* ── item cards ── */
  .item-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .item-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    background: rgba(255,255,255,0.03);
    transition: border-color .15s, background .15s;
  }
  .item-card:hover { background: rgba(255,255,255,0.055); }
  .item-card.is-checked {
    border-color: var(--accent);
    background: rgba(232,146,10,0.08);
  }
  .item-card label { display: block; cursor: pointer; }
  .item-label-row { display: flex; align-items: flex-start; gap: 9px; }
  .item-card input[type="checkbox"] {
    flex-shrink: 0;
    margin-top: 3px;
    width: 15px; height: 15px;
    accent-color: var(--accent);
  }
  .item-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-hi);
    line-height: 1.45;
  }
  .item-chips { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 5px; }
  .chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border2);
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 11px;
    color: var(--text);
    white-space: nowrap;
  }
  .chip strong { color: var(--text-dim); font-size: 10px; }
  .detail-toggle {
    margin-top: 8px;
    border-top: 1px solid var(--border);
    padding-top: 8px;
  }
  .detail-toggle summary {
    cursor: pointer;
    list-style: none;
    font-size: 11px;
    font-weight: 700;
    color: var(--blue);
  }
  .detail-toggle summary::-webkit-details-marker { display: none; }
  .detail-toggle summary::before { content: '▸ 대책 보기 '; }
  .detail-toggle[open] summary::before { content: '▾ 대책 닫기 '; }
  .item-meta {
    margin-top: 8px;
    display: grid;
    gap: 5px;
    color: var(--text-dim);
    font-size: 11px;
    line-height: 1.5;
  }
  .item-meta strong { color: var(--text); }
  .empty {
    border: 1px dashed var(--border2);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    color: var(--text-dim);
  }

  /* ── action buttons ── */
  .actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 20px;
    flex-wrap: wrap;
  }
  .action-group { display: flex; gap: 8px; flex-wrap: wrap; }
  .btn-secondary, .btn-primary {
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
  }
  .btn-secondary {
    background: rgba(255,255,255,0.05);
    color: var(--text);
    border: 1px solid var(--border2);
  }
  .btn-secondary:hover { background: rgba(255,255,255,0.09); }
  .btn-primary {
    background: var(--accent);
    color: #fff;
    border: none;
  }
  .btn-primary:hover { background: var(--accent2); }
  .btn-primary[hidden], .btn-secondary[hidden] { display: none; }

  .request-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 20px;
  }
  .summary-block {
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 18px;
    background: rgba(255,255,255,0.03);
  }
  .summary-label {
    font-size: 11px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 8px;
  }
  .summary-text {
    color: var(--text-hi);
    font-size: 13px;
    line-height: 1.65;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .summary-empty {
    color: var(--text-dim);
    font-size: 13px;
    line-height: 1.6;
  }
  .summary-count {
    color: var(--text-hi);
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 10px;
  }
  .summary-list {
    display: grid;
    gap: 10px;
  }
  .summary-item {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    background: rgba(255,255,255,0.04);
  }
  .summary-item.is-current {
    border-color: rgba(232,146,10,0.35);
    background: rgba(232,146,10,0.08);
  }
  .summary-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 8px;
  }
  .summary-item-title {
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 700;
    line-height: 1.5;
  }
  .summary-item-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    flex-shrink: 0;
  }
  .summary-action-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.05);
    color: var(--text);
    padding: 6px 10px;
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
  }
  .summary-action-button:hover { background: rgba(255,255,255,0.1); }
  .summary-item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 8px;
  }
  .summary-item-body {
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.6;
  }

  .modal-backdrop {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    background: rgba(5,10,18,0.78);
    z-index: 1000;
  }
  .modal-backdrop[hidden] { display: none; }
  .modal-window {
    width: min(960px, 100%);
    max-height: calc(100vh - 48px);
    overflow: auto;
    border-radius: 16px;
    border: 1px solid var(--border2);
    background: var(--bg2);
    box-shadow: 0 18px 50px rgba(0,0,0,0.35);
  }
  .modal-window.modal-window-sm { width: min(640px, 100%); }
  .modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 20px 22px 14px;
    border-bottom: 1px solid var(--border);
  }
  .modal-head h3 {
    color: var(--text-hi);
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 6px;
  }
  .modal-head p {
    color: var(--text-dim);
    font-size: 13px;
    line-height: 1.6;
  }
  .modal-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.05);
    color: var(--text);
    font-size: 18px;
    cursor: pointer;
    flex-shrink: 0;
  }
  .modal-close:hover { background: rgba(255,255,255,0.1); }
  .modal-body {
    padding: 18px 22px 20px;
    display: grid;
    gap: 16px;
  }
  .modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px 14px;
  }
  .field,
  .field-span-2 {
    display: grid;
    gap: 6px;
  }
  .field-span-2 { grid-column: 1 / -1; }
  .field label,
  .field-span-2 label {
    color: var(--text-hi);
    font-size: 12px;
    font-weight: 700;
  }
  .field input,
  .field select,
  .field textarea,
  .field-span-2 input,
  .field-span-2 select,
  .field-span-2 textarea {
    width: 100%;
    border-radius: 10px;
    border: 1px solid var(--border2);
    background: var(--bg3);
    color: var(--text-hi);
    padding: 10px 12px;
    font-size: 13px;
    font-family: inherit;
  }
  .field textarea,
  .field-span-2 textarea {
    min-height: 94px;
    resize: vertical;
    line-height: 1.55;
  }
  .field input[readonly],
  .field textarea[readonly],
  .field-span-2 input[readonly],
  .field-span-2 textarea[readonly] {
    background: rgba(255,255,255,0.05);
    color: var(--text);
  }
  .input-hint {
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.5;
  }
  .score-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }
  .score-group {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px;
    background: rgba(255,255,255,0.03);
  }
  .score-group-title {
    color: var(--text-hi);
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 10px;
  }
  .modal-error {
    border-radius: 10px;
    padding: 11px 13px;
    background: rgba(90,20,20,.35);
    border: 1px solid rgba(200,60,60,.3);
    color: #f09090;
    font-size: 13px;
    line-height: 1.5;
  }
  .modal-error[hidden] { display: none; }
  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 4px;
  }

  /* ── responsive ── */
  @media (max-width: 860px) {
    .item-list { grid-template-columns: 1fr; }
    .request-summary,
    .score-grid {
      grid-template-columns: 1fr;
    }
  }
  @media (max-width: 720px) {
    .panel-head, .content { padding-left: 18px; padding-right: 18px; }
    .survey-page { padding: 14px; }
    .survey-header h2 { font-size: 17px; }
    .actions { align-items: stretch; }
    .action-group, .btn-secondary, .btn-primary { width: 100%; }
    .summary-item-header,
    .modal-head,
    .modal-actions {
      flex-direction: column;
      align-items: stretch;
    }
    .summary-item-actions { width: 100%; }
    .summary-action-button { flex: 1 1 0; }
    .modal-window {
      max-height: calc(100vh - 24px);
    }
    .modal-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div>
        <div class="topbar-label">RISK ASSESSMENT · HAZARD SURVEY</div>
        <div class="topbar-title">위험성<span>평가</span> 설문</div>
      </div>
      <div class="identity">
        <?php if (!auth_is_worker($user)): ?>
          <span class="role-badge"><?= h($user['role_label']) ?></span>
        <?php endif; ?>
        <span style="color:var(--text-dim);font-size:13px"><?= h(auth_display_name($user)) ?></span>
        <a class="btn-secondary" href="work_list.php">작업목록</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-label">HAZARD SELECTION</div>
        <h1>위험요소 <span>선택</span></h1>
        <p>금일 작업에 최우선 고려되어야 하는 위험요소를 선택해주세요</p>
      </div>

      <div class="content">
        <?php if ($message !== ''): ?>
          <div class="message"><?= h($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($report && empty($riskAssessments)): ?>
          <div class="empty">현재 작업에 연결된 위험성평가가 없습니다.</div>
        <?php elseif ($report): ?>
          <form method="post" id="hazard-survey-form" autocomplete="off">
            <input type="hidden" name="report_id" value="<?= (int)$reportId ?>">
            <textarea name="change_request_text" id="change-request-input" hidden><?= h($changeRequestText) ?></textarea>
            <textarea name="additional_items_json" id="additional-items-input" hidden><?= h(json_encode($additionalRequestItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>

            <div class="progress">
              <div>
                <strong id="progress-title"></strong>
                <span id="progress-subtitle"></span>
              </div>
              <div class="page-dots" id="page-dots"></div>
            </div>

            <?php foreach ($riskAssessments as $index => $assessment): ?>
              <section
                class="survey-page<?= $index === 0 ? ' is-active' : '' ?>"
                data-page-index="<?= (int)$index ?>"
                data-unit-ra-id="<?= (int)$assessment['unit_ra_id'] ?>"
                data-unit-title="<?= h($assessment['unit_title']) ?>"
                data-unit-code="<?= h((string)($assessment['unit_code'] ?? '')) ?>"
              >
                <div class="survey-header">
                  <h2><?= h($assessment['unit_title']) ?></h2>
                  <div class="sub">
                    <?= h($assessment['process_name'] ?: '프로세스 정보 없음') ?>
                    <?php if (!empty($assessment['unit_code'])): ?>
                      <span class="code-tag"><?= h($assessment['unit_code']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (empty($assessment['items'])): ?>
                  <div class="empty">이 위험성평가에 등록된 위험요소가 없습니다.</div>
                <?php else: ?>
                  <div class="item-list">
                    <?php foreach ($assessment['items'] as $item): ?>
                      <?php $isChecked = in_array((int)$item['item_id'], $selectedItemIds, true); ?>
                      <div class="item-card<?= $isChecked ? ' is-checked' : '' ?>">
                        <label>
                          <div class="item-label-row">
                            <input
                              type="checkbox"
                              name="selected_items[]"
                              value="<?= (int)$item['item_id'] ?>"
                              data-server-checked="<?= $isChecked ? '1' : '0' ?>"
                              <?= $isChecked ? 'checked' : '' ?>
                            >
                            <span class="item-title"><?= h($item['hazard_name']) ?></span>
                          </div>
                          <div class="item-chips">
                            <span class="chip"><strong>작업</strong><?= h($item['task_name']) ?></span>
                            <?php if (!empty($item['accident_type'])): ?>
                              <span class="chip"><strong>재해</strong><?= h($item['accident_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['injury_result'])): ?>
                              <span class="chip"><strong>상해</strong><?= h($item['injury_result']) ?></span>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($item['current_control_text']) || !empty($item['additional_control_text'])): ?>
                          <details class="detail-toggle">
                            <summary>대책보기</summary>
                            <div class="item-meta">
                              <?php if (!empty($item['current_control_text'])): ?>
                                <div><strong>현재 대책</strong> <?= h($item['current_control_text']) ?></div>
                              <?php endif; ?>
                              <?php if (!empty($item['additional_control_text'])): ?>
                                <div><strong>추가 대책</strong> <?= h($item['additional_control_text']) ?></div>
                              <?php endif; ?>
                            </div>
                          </details>
                          <?php endif; ?>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>
            <?php endforeach; ?>

            <section class="request-summary" aria-label="요청 요약">
              <div class="summary-block">
                <div class="summary-label">수정요청</div>
                <div class="summary-text" id="change-request-preview"></div>
              </div>
              <div class="summary-block">
                <div class="summary-label">추가 위험성평가</div>
                <div class="summary-count" id="additional-items-count"></div>
                <div class="summary-list" id="additional-items-list"></div>
              </div>
            </section>

            <div class="actions">
              <div class="action-group">
                <a class="btn-secondary" href="work_list.php">목록으로</a>
              </div>
              <div class="action-group">
                <button type="button" class="btn-secondary" id="prev-page">이전</button>
                <button type="button" class="btn-secondary" id="open-change-request">수정요청</button>
                <button type="button" class="btn-secondary" id="open-addition-modal">추가</button>
                <button type="button" class="btn-secondary" id="next-page">다음</button>
                <button type="submit" class="btn-primary" id="submit-survey" hidden>제출</button>
              </div>
            </div>
          </form>

          <div class="modal-backdrop" id="change-request-modal" hidden>
            <div class="modal-window modal-window-sm" role="dialog" aria-modal="true" aria-labelledby="change-request-title">
              <div class="modal-head">
                <div>
                  <h3 id="change-request-title">수정요청 입력</h3>
                  <p id="change-request-context">위험성평가 내용 변경이나 삭제 요청을 자유롭게 입력할 수 있습니다.</p>
                </div>
                <button type="button" class="modal-close" data-close-modal="change-request-modal" aria-label="닫기">×</button>
              </div>
              <div class="modal-body">
                <div class="field-span-2">
                  <label for="change-request-editor">수정요청 내용</label>
                  <textarea id="change-request-editor" placeholder="위험성평가의 내용변경요청이나 내용삭제요청 등을 자유롭게 적어주세요."></textarea>
                  <div class="input-hint">저장한 내용은 설문 제출과 함께 같이 저장됩니다.</div>
                </div>
                <div class="modal-actions">
                  <button type="button" class="btn-secondary" data-close-modal="change-request-modal">취소</button>
                  <button type="button" class="btn-primary" id="save-change-request">요청 저장</button>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-backdrop" id="addition-modal" hidden>
            <div class="modal-window" role="dialog" aria-modal="true" aria-labelledby="addition-modal-title">
              <div class="modal-head">
                <div>
                  <h3 id="addition-modal-title">추가 위험성평가 입력</h3>
                  <p id="addition-context">현재 위험성평가 기준으로 추가할 항목을 입력합니다.</p>
                </div>
                <button type="button" class="modal-close" data-close-modal="addition-modal" aria-label="닫기">×</button>
              </div>
              <div class="modal-body">
                <div class="modal-error" id="addition-form-error" hidden></div>
                <input type="hidden" id="addition-item-index" value="">
                <input type="hidden" id="addition-unit-ra-id" value="">
                <input type="hidden" id="addition-unit-title" value="">
                <input type="hidden" id="addition-unit-code" value="">

                <div class="modal-grid">
                  <div class="field-span-2">
                    <label for="addition-unit-label">위험성평가 기준</label>
                    <input type="text" id="addition-unit-label" readonly>
                  </div>
                  <div class="field">
                    <label for="addition-sort-no">정렬</label>
                    <input type="number" id="addition-sort-no" min="1" placeholder="정렬순서">
                  </div>
                  <div class="field">
                    <label for="addition-task-code">작업코드</label>
                    <input type="text" id="addition-task-code" placeholder="안전작업표준서 번호">
                  </div>
                  <div class="field">
                    <label for="addition-task-name">작업명</label>
                    <input type="text" id="addition-task-name" placeholder="작업명을 입력하세요">
                  </div>
                  <div class="field">
                    <label for="addition-use-yn">사용 여부</label>
                    <select id="addition-use-yn">
                      <option value="Y">Y</option>
                      <option value="N">N</option>
                    </select>
                  </div>
                  <div class="field-span-2">
                    <label for="addition-hazard-name">주요 위험요소</label>
                    <textarea id="addition-hazard-name" placeholder="추가할 주요 위험요소를 입력하세요"></textarea>
                  </div>
                  <div class="field">
                    <label for="addition-accident-type">사고유형</label>
                    <input type="text" id="addition-accident-type" placeholder="사고유형">
                  </div>
                  <div class="field">
                    <label for="addition-injury-result">상해결과</label>
                    <input type="text" id="addition-injury-result" placeholder="상해결과">
                  </div>
                  <div class="field-span-2">
                    <label for="addition-cause-text">원인</label>
                    <textarea id="addition-cause-text" placeholder="위험요소의 원인을 입력하세요"></textarea>
                  </div>
                  <div class="field-span-2">
                    <label for="addition-current-control-text">현재 조치사항</label>
                    <textarea id="addition-current-control-text" placeholder="현재 조치사항을 입력하세요"></textarea>
                  </div>
                  <div class="field-span-2">
                    <label for="addition-additional-control-text">추가 조치사항</label>
                    <textarea id="addition-additional-control-text" placeholder="추가 조치사항을 입력하세요"></textarea>
                  </div>
                </div>

                <div class="score-grid">
                  <div class="score-group">
                    <div class="score-group-title">개선 전 위험도</div>
                    <div class="field">
                      <label for="addition-likelihood-before">L</label>
                      <input type="number" id="addition-likelihood-before" min="1" max="5">
                    </div>
                    <div class="field">
                      <label for="addition-severity-before">S</label>
                      <input type="number" id="addition-severity-before" min="1" max="5">
                    </div>
                    <div class="field">
                      <label for="addition-risk-score-before">점수</label>
                      <input type="text" id="addition-risk-score-before" readonly>
                    </div>
                  </div>
                  <div class="score-group">
                    <div class="score-group-title">현재 위험도</div>
                    <div class="field">
                      <label for="addition-likelihood-current">L</label>
                      <input type="number" id="addition-likelihood-current" min="1" max="5">
                    </div>
                    <div class="field">
                      <label for="addition-severity-current">S</label>
                      <input type="number" id="addition-severity-current" readonly>
                    </div>
                    <div class="field">
                      <label for="addition-risk-score-current">점수</label>
                      <input type="text" id="addition-risk-score-current" readonly>
                    </div>
                  </div>
                  <div class="score-group">
                    <div class="score-group-title">개선 후 위험도</div>
                    <div class="field">
                      <label for="addition-likelihood-after">L</label>
                      <input type="number" id="addition-likelihood-after" min="1" max="5">
                    </div>
                    <div class="field">
                      <label for="addition-severity-after">S</label>
                      <input type="number" id="addition-severity-after" min="1" max="5">
                    </div>
                    <div class="field">
                      <label for="addition-risk-score-after">점수</label>
                      <input type="text" id="addition-risk-score-after" readonly>
                    </div>
                  </div>
                </div>

                <div class="modal-grid">
                  <div class="field">
                    <label for="addition-improvement-due-date">개선기한</label>
                    <input type="date" id="addition-improvement-due-date">
                  </div>
                  <div class="field">
                    <label for="addition-remark">비고</label>
                    <textarea id="addition-remark" style="min-height: 46px;" placeholder="비고"></textarea>
                  </div>
                </div>

                <div class="modal-actions">
                  <button type="button" class="btn-secondary" data-close-modal="addition-modal">취소</button>
                  <button type="button" class="btn-primary" id="save-addition-item">항목 저장</button>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($report && !empty($riskAssessments)): ?>
  <script>
    const pages = [...document.querySelectorAll('.survey-page')];
    const prevButton = document.getElementById('prev-page');
    const nextButton = document.getElementById('next-page');
    const submitButton = document.getElementById('submit-survey');
    const progressTitle = document.getElementById('progress-title');
    const progressSubtitle = document.getElementById('progress-subtitle');
    const pageDots = document.getElementById('page-dots');
    let currentPage = 0;

    // Always apply server selection state first to avoid browser form-state carryover.
    document.querySelectorAll('.item-card input[type="checkbox"][name="selected_items[]"]').forEach((checkbox) => {
      const shouldCheck = checkbox.dataset.serverChecked === '1';
      checkbox.checked = shouldCheck;
      const card = checkbox.closest('.item-card');
      if (card) {
        card.classList.toggle('is-checked', shouldCheck);
      }
    });

    function renderPageDots() {
      pageDots.innerHTML = '';
      pages.forEach((page, index) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = index === currentPage ? 'is-active' : '';
        dot.setAttribute('aria-label', `${index + 1}페이지로 이동`);
        dot.addEventListener('click', () => {
          currentPage = index;
          renderSurveyPage();
        });
        pageDots.appendChild(dot);
      });
    }

    function renderSurveyPage() {
      pages.forEach((page, index) => {
        page.classList.toggle('is-active', index === currentPage);
      });
      progressTitle.textContent = `위험성평가 ${currentPage + 1} / ${pages.length}`;
      progressSubtitle.textContent = '';
      prevButton.hidden = currentPage === 0;
      nextButton.hidden = currentPage === pages.length - 1;
      submitButton.hidden = currentPage !== pages.length - 1;
      renderPageDots();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function hasSelectionOnCurrentPage() {
      const page = pages[currentPage];
      if (!page) {
        return false;
      }
      return !!page.querySelector('input[type="checkbox"]:checked');
    }

    prevButton.addEventListener('click', () => {
      if (currentPage > 0) {
        currentPage -= 1;
        renderSurveyPage();
      }
    });

    nextButton.addEventListener('click', () => {
      if (!hasSelectionOnCurrentPage()) {
        window.alert('위험요소를 1개 이상 선택해야 다음으로 이동할 수 있습니다.');
        return;
      }
      if (currentPage < pages.length - 1) {
        currentPage += 1;
        renderSurveyPage();
      }
    });

    renderSurveyPage();

    // 체크 상태에 따라 카드 하이라이트
    document.querySelectorAll('.item-card input[type="checkbox"]').forEach(function(cb) {
      cb.addEventListener('change', function() {
        this.closest('.item-card').classList.toggle('is-checked', this.checked);
      });
    });
  </script>

  <script>
    const hazardSurveyForm = document.getElementById('hazard-survey-form');
    const hazardSurveyPages = [...document.querySelectorAll('.survey-page')];
    const hazardPrevButton = document.getElementById('prev-page');
    const hazardNextButton = document.getElementById('next-page');
    const hazardSubmitButton = document.getElementById('submit-survey');
    const hazardOpenChangeRequestButton = document.getElementById('open-change-request');
    const hazardOpenAdditionButton = document.getElementById('open-addition-modal');
    const hazardProgressTitle = document.getElementById('progress-title');
    const hazardProgressSubtitle = document.getElementById('progress-subtitle');
    const hazardPageDots = document.getElementById('page-dots');
    const hazardChangeRequestInput = document.getElementById('change-request-input');
    const hazardAdditionalItemsInput = document.getElementById('additional-items-input');
    const hazardChangeRequestPreview = document.getElementById('change-request-preview');
    const hazardAdditionalItemsCount = document.getElementById('additional-items-count');
    const hazardAdditionalItemsList = document.getElementById('additional-items-list');
    const hazardChangeRequestModal = document.getElementById('change-request-modal');
    const hazardChangeRequestContext = document.getElementById('change-request-context');
    const hazardChangeRequestEditor = document.getElementById('change-request-editor');
    const hazardSaveChangeRequestButton = document.getElementById('save-change-request');
    const hazardAdditionModal = document.getElementById('addition-modal');
    const hazardAdditionModalTitle = document.getElementById('addition-modal-title');
    const hazardAdditionContext = document.getElementById('addition-context');
    const hazardAdditionFormError = document.getElementById('addition-form-error');
    const hazardAdditionItemIndex = document.getElementById('addition-item-index');
    const hazardAdditionUnitRaId = document.getElementById('addition-unit-ra-id');
    const hazardAdditionUnitTitle = document.getElementById('addition-unit-title');
    const hazardAdditionUnitCode = document.getElementById('addition-unit-code');
    const hazardAdditionUnitLabel = document.getElementById('addition-unit-label');
    const hazardAdditionSortNo = document.getElementById('addition-sort-no');
    const hazardAdditionTaskCode = document.getElementById('addition-task-code');
    const hazardAdditionTaskName = document.getElementById('addition-task-name');
    const hazardAdditionHazardName = document.getElementById('addition-hazard-name');
    const hazardAdditionAccidentType = document.getElementById('addition-accident-type');
    const hazardAdditionInjuryResult = document.getElementById('addition-injury-result');
    const hazardAdditionCauseText = document.getElementById('addition-cause-text');
    const hazardAdditionCurrentControlText = document.getElementById('addition-current-control-text');
    const hazardAdditionAdditionalControlText = document.getElementById('addition-additional-control-text');
    const hazardAdditionLikelihoodBefore = document.getElementById('addition-likelihood-before');
    const hazardAdditionSeverityBefore = document.getElementById('addition-severity-before');
    const hazardAdditionRiskScoreBefore = document.getElementById('addition-risk-score-before');
    const hazardAdditionLikelihoodCurrent = document.getElementById('addition-likelihood-current');
    const hazardAdditionSeverityCurrent = document.getElementById('addition-severity-current');
    const hazardAdditionRiskScoreCurrent = document.getElementById('addition-risk-score-current');
    const hazardAdditionLikelihoodAfter = document.getElementById('addition-likelihood-after');
    const hazardAdditionSeverityAfter = document.getElementById('addition-severity-after');
    const hazardAdditionRiskScoreAfter = document.getElementById('addition-risk-score-after');
    const hazardAdditionImprovementDueDate = document.getElementById('addition-improvement-due-date');
    const hazardAdditionRemark = document.getElementById('addition-remark');
    const hazardAdditionUseYn = document.getElementById('addition-use-yn');
    const hazardSaveAdditionButton = document.getElementById('save-addition-item');
    const hazardBaseTaskCodesByUnit = <?= json_encode($existingTaskCodesByUnit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let hazardCurrentPageIndex = 0;
    let hazardAdditionalItems = [];

    function hazardEscapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function hazardParseInteger(value, min = null, max = null) {
      const normalized = String(value ?? '').trim();
      if (!/^-?\d+$/.test(normalized)) {
        return '';
      }

      const parsed = Number.parseInt(normalized, 10);
      if (!Number.isInteger(parsed)) {
        return '';
      }
      if (min !== null && parsed < min) {
        return '';
      }
      if (max !== null && parsed > max) {
        return '';
      }

      return String(parsed);
    }

    function hazardScoreFromPair(likelihood, severity) {
      const left = Number.parseInt(String(likelihood ?? ''), 10);
      const right = Number.parseInt(String(severity ?? ''), 10);
      return Number.isInteger(left) && Number.isInteger(right)
        ? String(left * right)
        : '';
    }

    function hazardSplitTaskCode(taskCode) {
      const normalized = String(taskCode ?? '').trim();
      if (normalized === '') {
        return null;
      }

      const match = normalized.match(/^(.*?)(\d+)(\D*)$/);
      if (!match) {
        return null;
      }

      return {
        prefix: match[1] || '',
        number: Number.parseInt(match[2], 10),
        width: match[2].length,
        suffix: match[3] || ''
      };
    }

    function hazardGetAutoTaskCode(unitRaId) {
      const baseCodes = hazardBaseTaskCodesByUnit && Array.isArray(hazardBaseTaskCodesByUnit[unitRaId])
        ? hazardBaseTaskCodesByUnit[unitRaId]
        : [];
      const addedCodes = hazardAdditionalItems
        .filter((item) => Number(item.unit_ra_id) === Number(unitRaId))
        .map((item) => String(item.task_code || '').trim())
        .filter((value) => value !== '');

      const parsedCodes = [...baseCodes, ...addedCodes]
        .map(hazardSplitTaskCode)
        .filter((item) => item && Number.isInteger(item.number));

      if (parsedCodes.length > 0) {
        const lastCode = parsedCodes.reduce((selected, current) => {
          if (!selected) {
            return current;
          }
          if (current.number !== selected.number) {
            return current.number > selected.number ? current : selected;
          }
          return current.width >= selected.width ? current : selected;
        }, null);

        if (lastCode) {
          return `${lastCode.prefix}${String(lastCode.number + 1).padStart(lastCode.width, '0')}${lastCode.suffix}`;
        }
      }

      const fallbackNumber = baseCodes.length + addedCodes.length + 1;
      return String(fallbackNumber).padStart(2, '0');
    }

    function hazardGetPage(pageIndex = hazardCurrentPageIndex) {
      return hazardSurveyPages[pageIndex] || null;
    }

    function hazardGetPageContext(page = hazardGetPage()) {
      if (!page) {
        return {
          unit_ra_id: 0,
          unit_title: '',
          unit_code: ''
        };
      }

      return {
        unit_ra_id: Number.parseInt(page.dataset.unitRaId || '0', 10) || 0,
        unit_title: page.dataset.unitTitle || '',
        unit_code: page.dataset.unitCode || ''
      };
    }

    function hazardGetUnitLabel(context) {
      const title = String(context?.unit_title || '').trim();
      const code = String(context?.unit_code || '').trim();
      if (!title) {
        return '위험성평가';
      }
      return code ? `${title} (${code})` : title;
    }

    function hazardNormalizeAdditionalItem(item) {
      const context = {
        unit_ra_id: Number.parseInt(String(item?.unit_ra_id ?? '0'), 10) || 0,
        unit_title: String(item?.unit_title ?? '').trim(),
        unit_code: String(item?.unit_code ?? '').trim()
      };
      const taskName = String(item?.task_name ?? '').trim();
      const hazardName = String(item?.hazard_name ?? '').trim();
      if (context.unit_ra_id <= 0 || taskName === '' || hazardName === '') {
        return null;
      }

      const likelihoodBefore = hazardParseInteger(item?.likelihood_before, 1, 5);
      const severityBefore = hazardParseInteger(item?.severity_before, 1, 5);
      const likelihoodCurrent = hazardParseInteger(item?.likelihood_current, 1, 5);
      const severityCurrent = severityBefore;
      const likelihoodAfter = hazardParseInteger(item?.likelihood_after, 1, 5);
      const severityAfter = hazardParseInteger(item?.severity_after, 1, 5);

      return {
        unit_ra_id: context.unit_ra_id,
        unit_title: context.unit_title,
        unit_code: context.unit_code,
        sort_no: hazardParseInteger(item?.sort_no, 1),
        task_code: String(item?.task_code ?? '').trim(),
        task_name: taskName,
        hazard_name: hazardName,
        accident_type: String(item?.accident_type ?? '').trim(),
        injury_result: String(item?.injury_result ?? '').trim(),
        cause_text: String(item?.cause_text ?? '').trim(),
        current_control_text: String(item?.current_control_text ?? '').trim(),
        additional_control_text: String(item?.additional_control_text ?? '').trim(),
        likelihood_before: likelihoodBefore,
        severity_before: severityBefore,
        risk_score_before: hazardScoreFromPair(likelihoodBefore, severityBefore),
        likelihood_current: likelihoodCurrent,
        severity_current: severityCurrent,
        risk_score_current: hazardScoreFromPair(likelihoodCurrent, severityCurrent),
        likelihood_after: likelihoodAfter,
        severity_after: severityAfter,
        risk_score_after: hazardScoreFromPair(likelihoodAfter, severityAfter),
        improvement_due_date: String(item?.improvement_due_date ?? '').trim(),
        remark: String(item?.remark ?? '').trim(),
        use_yn: String(item?.use_yn ?? 'Y') === 'N' ? 'N' : 'Y'
      };
    }

    function hazardSyncAdditionalItemsField() {
      hazardAdditionalItemsInput.value = JSON.stringify(hazardAdditionalItems);
    }

    function hazardCountAdditionalItemsForUnit(unitRaId) {
      return hazardAdditionalItems.filter((item) => Number(item.unit_ra_id) === Number(unitRaId)).length;
    }

    function hazardHasInputOnPage(pageIndex = hazardCurrentPageIndex) {
      const page = hazardGetPage(pageIndex);
      if (!page) {
        return false;
      }

      const unitRaId = Number.parseInt(page.dataset.unitRaId || '0', 10) || 0;
      const hasCheckedItem = !!page.querySelector('input[type="checkbox"]:checked');
      const hasAdditionalItem = hazardCountAdditionalItemsForUnit(unitRaId) > 0;
      const hasChangeRequest = String(hazardChangeRequestInput.value || '').trim() !== '';
      return hasCheckedItem || hasAdditionalItem || hasChangeRequest;
    }

    function hazardRenderPageDots() {
      hazardPageDots.innerHTML = '';
      hazardSurveyPages.forEach((page, index) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = index === hazardCurrentPageIndex ? 'is-active' : '';
        dot.setAttribute('aria-label', `${index + 1}페이지로 이동`);
        dot.addEventListener('click', () => {
          hazardCurrentPageIndex = index;
          hazardRenderSurveyPage();
        });
        hazardPageDots.appendChild(dot);
      });
    }

    function hazardRenderSurveyPage() {
      hazardSurveyPages.forEach((page, index) => {
        page.classList.toggle('is-active', index === hazardCurrentPageIndex);
      });

      const page = hazardGetPage();
      const context = hazardGetPageContext(page);
      const selectionCount = page ? page.querySelectorAll('input[type="checkbox"]:checked').length : 0;
      const additionCount = hazardCountAdditionalItemsForUnit(context.unit_ra_id);

      hazardProgressTitle.textContent = `위험성평가 ${hazardCurrentPageIndex + 1} / ${hazardSurveyPages.length}`;
      hazardProgressSubtitle.textContent = `${hazardGetUnitLabel(context)} · 선택 ${selectionCount}건 · 추가 ${additionCount}건`;
      hazardPrevButton.hidden = hazardCurrentPageIndex === 0;
      hazardNextButton.hidden = hazardCurrentPageIndex === hazardSurveyPages.length - 1;
      hazardSubmitButton.hidden = hazardCurrentPageIndex !== hazardSurveyPages.length - 1;
      hazardRenderPageDots();
    }

    function hazardRenderChangeRequestPreview() {
      const text = String(hazardChangeRequestInput.value || '').trim();
      hazardChangeRequestPreview.textContent = text !== '' ? text : '입력된 수정요청이 없습니다.';
      hazardChangeRequestPreview.classList.toggle('summary-empty', text === '');
    }

    function hazardBuildAdditionalItemMarkup(item, index) {
      const metaChips = [
        `<span class="chip"><strong>평가</strong>${hazardEscapeHtml(hazardGetUnitLabel(item))}</span>`,
        `<span class="chip"><strong>작업</strong>${hazardEscapeHtml(item.task_name)}</span>`
      ];
      if (item.task_code) {
        metaChips.push(`<span class="chip"><strong>코드</strong>${hazardEscapeHtml(item.task_code)}</span>`);
      }
      if (item.use_yn === 'N') {
        metaChips.push('<span class="chip"><strong>사용</strong>N</span>');
      }

      const detailParts = [];
      if (item.accident_type) {
        detailParts.push(`사고유형: ${hazardEscapeHtml(item.accident_type)}`);
      }
      if (item.injury_result) {
        detailParts.push(`상해결과: ${hazardEscapeHtml(item.injury_result)}`);
      }
      if (item.current_control_text) {
        detailParts.push(`현재 조치: ${hazardEscapeHtml(item.current_control_text)}`);
      }

      return `
        <div class="summary-item${Number(item.unit_ra_id) === Number(hazardGetPageContext().unit_ra_id) ? ' is-current' : ''}">
          <div class="summary-item-header">
            <div class="summary-item-title">${hazardEscapeHtml(item.hazard_name)}</div>
            <div class="summary-item-actions">
              <button type="button" class="summary-action-button" data-edit-addition="${index}">수정</button>
              <button type="button" class="summary-action-button" data-delete-addition="${index}">삭제</button>
            </div>
          </div>
          <div class="summary-item-meta">${metaChips.join('')}</div>
          <div class="summary-item-body">${detailParts.length > 0 ? detailParts.join('<br>') : '추가 입력된 세부 설명이 없습니다.'}</div>
        </div>
      `;
    }

    function hazardRenderAdditionalItems() {
      if (hazardAdditionalItems.length === 0) {
        hazardAdditionalItemsCount.textContent = '등록된 추가 항목이 없습니다.';
        hazardAdditionalItemsList.innerHTML = '<div class="summary-empty">필요한 경우 현재 페이지 기준으로 추가 위험성평가 항목을 등록할 수 있습니다.</div>';
        return;
      }

      const currentUnitCount = hazardCountAdditionalItemsForUnit(hazardGetPageContext().unit_ra_id);
      hazardAdditionalItemsCount.textContent = `총 ${hazardAdditionalItems.length}건 등록됨 · 현재 페이지 ${currentUnitCount}건`;
      hazardAdditionalItemsList.innerHTML = hazardAdditionalItems
        .map((item, index) => hazardBuildAdditionalItemMarkup(item, index))
        .join('');
    }

    function hazardRefreshBodyScroll() {
      const isAnyModalOpen = !hazardChangeRequestModal.hidden || !hazardAdditionModal.hidden;
      document.body.style.overflow = isAnyModalOpen ? 'hidden' : '';
    }

    function hazardOpenModal(modal) {
      if (!modal) {
        return;
      }
      modal.hidden = false;
      hazardRefreshBodyScroll();
    }

    function hazardCloseModal(modal) {
      if (!modal) {
        return;
      }
      modal.hidden = true;
      hazardRefreshBodyScroll();
    }

    function hazardOpenChangeRequestModal() {
      hazardChangeRequestContext.textContent = `${hazardGetUnitLabel(hazardGetPageContext())}에 대한 내용변경요청이나 삭제요청을 자유롭게 적어주세요.`;
      hazardChangeRequestEditor.value = hazardChangeRequestInput.value;
      hazardOpenModal(hazardChangeRequestModal);
      window.setTimeout(() => {
        hazardChangeRequestEditor.focus();
      }, 0);
    }

    function hazardUpdateAdditionScores() {
      hazardAdditionSeverityCurrent.value = hazardAdditionSeverityBefore.value;
      hazardAdditionRiskScoreBefore.value = hazardScoreFromPair(hazardAdditionLikelihoodBefore.value, hazardAdditionSeverityBefore.value);
      hazardAdditionRiskScoreCurrent.value = hazardScoreFromPair(hazardAdditionLikelihoodCurrent.value, hazardAdditionSeverityCurrent.value);
      hazardAdditionRiskScoreAfter.value = hazardScoreFromPair(hazardAdditionLikelihoodAfter.value, hazardAdditionSeverityAfter.value);
    }

    function hazardFillAdditionForm(item, index = '') {
      hazardAdditionItemIndex.value = index;
      hazardAdditionUnitRaId.value = String(item.unit_ra_id || '');
      hazardAdditionUnitTitle.value = item.unit_title || '';
      hazardAdditionUnitCode.value = item.unit_code || '';
      hazardAdditionUnitLabel.value = hazardGetUnitLabel(item);
      hazardAdditionSortNo.value = item.sort_no || '';
      hazardAdditionTaskCode.value = item.task_code || '';
      hazardAdditionTaskName.value = item.task_name || '';
      hazardAdditionHazardName.value = item.hazard_name || '';
      hazardAdditionAccidentType.value = item.accident_type || '';
      hazardAdditionInjuryResult.value = item.injury_result || '';
      hazardAdditionCauseText.value = item.cause_text || '';
      hazardAdditionCurrentControlText.value = item.current_control_text || '';
      hazardAdditionAdditionalControlText.value = item.additional_control_text || '';
      hazardAdditionLikelihoodBefore.value = item.likelihood_before || '';
      hazardAdditionSeverityBefore.value = item.severity_before || '';
      hazardAdditionLikelihoodCurrent.value = item.likelihood_current || '';
      hazardAdditionLikelihoodAfter.value = item.likelihood_after || '';
      hazardAdditionSeverityAfter.value = item.severity_after || '';
      hazardAdditionImprovementDueDate.value = item.improvement_due_date || '';
      hazardAdditionRemark.value = item.remark || '';
      hazardAdditionUseYn.value = item.use_yn === 'N' ? 'N' : 'Y';
      hazardAdditionFormError.hidden = true;
      hazardAdditionFormError.textContent = '';
      hazardUpdateAdditionScores();
    }

    function hazardOpenAdditionModal(index = null) {
      let item = null;
      const parsedIndex = Number.parseInt(String(index ?? ''), 10);
      if (Number.isInteger(parsedIndex) && parsedIndex >= 0 && parsedIndex < hazardAdditionalItems.length) {
        item = hazardNormalizeAdditionalItem(hazardAdditionalItems[parsedIndex]);
        hazardAdditionModalTitle.textContent = '추가 위험성평가 수정';
      } else {
        const context = hazardGetPageContext();
        item = hazardNormalizeAdditionalItem({
          unit_ra_id: context.unit_ra_id,
          unit_title: context.unit_title,
          unit_code: context.unit_code,
          sort_no: String(hazardCountAdditionalItemsForUnit(context.unit_ra_id) + 1),
          task_code: hazardGetAutoTaskCode(context.unit_ra_id),
          task_name: '공통',
          hazard_name: '신규 위험요소',
          use_yn: 'Y'
        });
        hazardAdditionModalTitle.textContent = '추가 위험성평가 입력';
      }

      if (!item) {
        window.alert('현재 페이지의 위험성평가 정보를 불러오지 못했습니다.');
        return;
      }

      hazardAdditionContext.textContent = `${hazardGetUnitLabel(item)} 기준으로 추가할 위험성평가 항목을 입력합니다.`;
      hazardFillAdditionForm(item, Number.isInteger(parsedIndex) && parsedIndex >= 0 ? String(parsedIndex) : '');
      hazardOpenModal(hazardAdditionModal);
      window.setTimeout(() => {
        hazardAdditionTaskCode.focus();
      }, 0);
    }

    function hazardCollectAdditionFormData() {
      const unitRaId = Number.parseInt(hazardAdditionUnitRaId.value || '0', 10) || 0;
      const taskName = hazardAdditionTaskName.value.trim();
      const hazardName = hazardAdditionHazardName.value.trim();

      if (unitRaId <= 0) {
        hazardAdditionFormError.textContent = '추가할 위험성평가 기준을 찾지 못했습니다. 현재 페이지에서 다시 시도해주세요.';
        hazardAdditionFormError.hidden = false;
        return null;
      }
      if (taskName === '') {
        hazardAdditionFormError.textContent = '작업명을 입력해주세요.';
        hazardAdditionFormError.hidden = false;
        hazardAdditionTaskName.focus();
        return null;
      }
      if (hazardName === '') {
        hazardAdditionFormError.textContent = '주요 위험요소를 입력해주세요.';
        hazardAdditionFormError.hidden = false;
        hazardAdditionHazardName.focus();
        return null;
      }

      hazardAdditionFormError.hidden = true;
      hazardAdditionFormError.textContent = '';

      return hazardNormalizeAdditionalItem({
        unit_ra_id: unitRaId,
        unit_title: hazardAdditionUnitTitle.value.trim(),
        unit_code: hazardAdditionUnitCode.value.trim(),
        sort_no: hazardAdditionSortNo.value,
        task_code: hazardAdditionTaskCode.value,
        task_name: taskName,
        hazard_name: hazardName,
        accident_type: hazardAdditionAccidentType.value,
        injury_result: hazardAdditionInjuryResult.value,
        cause_text: hazardAdditionCauseText.value,
        current_control_text: hazardAdditionCurrentControlText.value,
        additional_control_text: hazardAdditionAdditionalControlText.value,
        likelihood_before: hazardAdditionLikelihoodBefore.value,
        severity_before: hazardAdditionSeverityBefore.value,
        likelihood_current: hazardAdditionLikelihoodCurrent.value,
        likelihood_after: hazardAdditionLikelihoodAfter.value,
        severity_after: hazardAdditionSeverityAfter.value,
        improvement_due_date: hazardAdditionImprovementDueDate.value,
        remark: hazardAdditionRemark.value,
        use_yn: hazardAdditionUseYn.value
      });
    }

    hazardPrevButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (hazardCurrentPageIndex > 0) {
        hazardCurrentPageIndex -= 1;
        hazardRenderSurveyPage();
      }
    }, true);

    hazardNextButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (!hazardHasInputOnPage()) {
        window.alert('위험요소를 선택하거나 추가 버튼 또는 수정요청 버튼을 통해 내용을 입력한 뒤 다음으로 이동해주세요.');
        return;
      }
      if (hazardCurrentPageIndex < hazardSurveyPages.length - 1) {
        hazardCurrentPageIndex += 1;
        hazardRenderSurveyPage();
      }
    }, true);

    document.querySelectorAll('.item-card input[type="checkbox"]').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        hazardRenderSurveyPage();
      });
    });

    hazardOpenChangeRequestButton.addEventListener('click', hazardOpenChangeRequestModal);
    hazardOpenAdditionButton.addEventListener('click', () => hazardOpenAdditionModal());

    hazardSaveChangeRequestButton.addEventListener('click', async () => {
      const changeRequestText = hazardChangeRequestEditor.value.trim();
      if (changeRequestText === '') {
        window.alert('수정요청 내용을 입력해주세요.');
        hazardChangeRequestEditor.focus();
        return;
      }

      const reportIdField = hazardSurveyForm.querySelector('input[name="report_id"]');
      if (!reportIdField || String(reportIdField.value || '').trim() === '') {
        window.alert('보고서 정보를 찾지 못했습니다.');
        return;
      }

      hazardSaveChangeRequestButton.disabled = true;
      const originalLabel = hazardSaveChangeRequestButton.textContent;
      hazardSaveChangeRequestButton.textContent = '저장 중...';

      try {
        const formData = new FormData();
        formData.append('action', 'save_change_request');
        formData.append('report_id', reportIdField.value);
        formData.append('change_request_text', changeRequestText);

        const response = await fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        const result = await response.json().catch(() => null);
        if (!response.ok || !result || result.success !== true) {
          throw new Error(result && result.message ? result.message : '수정요청 저장에 실패했습니다.');
        }

        hazardChangeRequestInput.value = changeRequestText;
        hazardRenderChangeRequestPreview();
        hazardRenderSurveyPage();
        hazardCloseModal(hazardChangeRequestModal);
        window.alert(result.message || '수정요청이 저장되었습니다.');
      } catch (error) {
        window.alert(error instanceof Error ? error.message : '수정요청 저장 중 오류가 발생했습니다.');
      } finally {
        hazardSaveChangeRequestButton.disabled = false;
        hazardSaveChangeRequestButton.textContent = originalLabel;
      }
    });

    [
      hazardAdditionLikelihoodBefore,
      hazardAdditionSeverityBefore,
      hazardAdditionLikelihoodCurrent,
      hazardAdditionLikelihoodAfter,
      hazardAdditionSeverityAfter
    ].forEach((input) => {
      input.addEventListener('input', hazardUpdateAdditionScores);
    });

    hazardSaveAdditionButton.addEventListener('click', () => {
      const item = hazardCollectAdditionFormData();
      if (!item) {
        return;
      }

      const index = Number.parseInt(hazardAdditionItemIndex.value || '', 10);
      if (Number.isInteger(index) && index >= 0 && index < hazardAdditionalItems.length) {
        hazardAdditionalItems[index] = item;
      } else {
        hazardAdditionalItems.push(item);
      }

      hazardSyncAdditionalItemsField();
      hazardRenderAdditionalItems();
      hazardRenderSurveyPage();
      hazardCloseModal(hazardAdditionModal);
    });

    hazardAdditionalItemsList.addEventListener('click', (event) => {
      const editButton = event.target.closest('[data-edit-addition]');
      if (editButton) {
        hazardOpenAdditionModal(editButton.dataset.editAddition);
        return;
      }

      const deleteButton = event.target.closest('[data-delete-addition]');
      if (deleteButton) {
        const index = Number.parseInt(deleteButton.dataset.deleteAddition || '', 10);
        if (!Number.isInteger(index) || index < 0 || index >= hazardAdditionalItems.length) {
          return;
        }
        hazardAdditionalItems.splice(index, 1);
        hazardSyncAdditionalItemsField();
        hazardRenderAdditionalItems();
        hazardRenderSurveyPage();
      }
    });

    document.querySelectorAll('[data-close-modal]').forEach((button) => {
      button.addEventListener('click', () => {
        const modalId = button.getAttribute('data-close-modal');
        const modal = modalId ? document.getElementById(modalId) : null;
        hazardCloseModal(modal);
      });
    });

    [hazardChangeRequestModal, hazardAdditionModal].forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          hazardCloseModal(modal);
        }
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }
      if (!hazardAdditionModal.hidden) {
        hazardCloseModal(hazardAdditionModal);
      } else if (!hazardChangeRequestModal.hidden) {
        hazardCloseModal(hazardChangeRequestModal);
      }
    });

    hazardSurveyForm.addEventListener('submit', (event) => {
      if (!hazardHasInputOnPage()) {
        window.alert('마지막 페이지에서도 위험요소를 선택하거나 추가/수정요청을 입력해주세요.');
        event.preventDefault();
        return;
      }
      hazardSyncAdditionalItemsField();
    });

    try {
      const parsedItems = JSON.parse(hazardAdditionalItemsInput.value || '[]');
      hazardAdditionalItems = Array.isArray(parsedItems)
        ? parsedItems.map(hazardNormalizeAdditionalItem).filter(Boolean)
        : [];
    } catch (error) {
      hazardAdditionalItems = [];
    }

    hazardSyncAdditionalItemsField();
    hazardRenderChangeRequestPreview();
    hazardRenderAdditionalItems();
    hazardRenderSurveyPage();
  </script>
  <?php endif; ?>
</body>
</html>
