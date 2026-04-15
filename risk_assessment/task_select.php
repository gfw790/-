<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

$selfPage = basename((string)($_SERVER['PHP_SELF'] ?? 'task_select.php'));
$isLeaderPage = $selfPage === 'leader_task_select.php';
$loginTargetPage = $isLeaderPage ? 'leader_task_select.php' : 'task_select.php';
$boardPageUrl = '../board/index.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_page_url(string $path, array $params = []): string
{
    $queryParams = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_string($value) && $value === '') {
            continue;
        }
        $queryParams[$key] = $value;
    }

    if (empty($queryParams)) {
        return $path;
    }

    return $path . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
}

function resolve_team_from_list(array $teams, string $requestedTeam): string
{
    $normalizedRequestedTeam = auth_normalize_team_name($requestedTeam);
    if ($normalizedRequestedTeam === '') {
        return '';
    }

    foreach ($teams as $teamName) {
        $canonicalTeamName = auth_normalize_team_name((string)$teamName);
        if ($canonicalTeamName !== '' && auth_team_key($canonicalTeamName) === auth_team_key($normalizedRequestedTeam)) {
            return $canonicalTeamName;
        }
    }

    return '';
}

function display_process_name_label(string $name): string
{
    return $name === '결선' ? '결선작업' : $name;
}

function detail_selection_value(string $type, string $title): string
{
    return $type . '|' . $title;
}

function detail_sub_selection_value(string $type, string $parent, string $title): string
{
    return $type . '|' . $parent . '|' . $title;
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

function normalize_detail_task_values($values): array
{
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        $values
    ))));
}

function normalize_detail_code_values($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $key => $value) {
        $detailKey = trim((string)$key);
        if ($detailKey === '') {
            continue;
        }

        $normalized[$detailKey] = trim((string)$value);
    }

    return $normalized;
}

function normalize_unit_ra_id_values($values): array
{
    if (is_string($values)) {
        $decoded = json_decode($values, true);
        if (is_array($decoded)) {
            $values = $decoded;
        } else {
            $values = preg_split('/\s*,\s*/', trim($values), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }

    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $value) {
        $unitRaId = (int)$value;
        if ($unitRaId <= 0) {
            continue;
        }
        $normalized[$unitRaId] = $unitRaId;
    }

    return array_values($normalized);
}

function build_detail_grouped_state(array $detailTasks, array $detailCodeMap): array
{
    $grouped = [
        'major_work' => [],
        'major_work_map' => [],
        'major_work_sub' => [],
        'major_work_sub_map' => [],
        'env' => [],
        'tool' => [],
    ];

    foreach ($detailTasks as $detailValue) {
        $parsed = parse_detail_selection((string)$detailValue);
        if (!isset($grouped[$parsed['type']]) || $parsed['title'] === '') {
            continue;
        }

        $grouped[$parsed['type']][] = $parsed['title'];
        if ($parsed['type'] === 'major_work_sub' && $parsed['parent'] !== '') {
            $grouped['major_work_sub_map'][$parsed['parent']][] = [
                'title' => $parsed['title'],
                'risk_code' => $detailCodeMap[$detailValue] ?? '',
            ];
        } elseif ($parsed['type'] === 'major_work') {
            $grouped['major_work_map'][$parsed['title']] = $detailCodeMap[$detailValue] ?? '';
        }
    }

    return $grouped;
}

function resolve_selected_tool_names(array $selectedToolIds, array $tools): array
{
    if (empty($selectedToolIds) || empty($tools)) {
        return [];
    }

    $toolNameMap = [];
    foreach ($tools as $tool) {
        $toolId = (int)($tool['tool_id'] ?? 0);
        $toolName = trim((string)($tool['tool_name'] ?? ''));
        if ($toolId <= 0 || $toolName === '') {
            continue;
        }
        $toolNameMap[$toolId] = $toolName;
    }

    $selectedToolNames = [];
    foreach ($selectedToolIds as $toolId) {
        $toolId = (int)$toolId;
        if ($toolId > 0 && isset($toolNameMap[$toolId])) {
            $selectedToolNames[] = $toolNameMap[$toolId];
        }
    }

    return array_values(array_unique($selectedToolNames));
}

function normalize_match_text(string $value): string
{
    $value = trim($value);
    $normalized = preg_replace('/\s+/u', '', $value);
    return is_string($normalized) ? $normalized : $value;
}

function lookup_unit_code(array $lookup, string $title): string
{
    $title = trim($title);
    if ($title === '') {
        return '';
    }

    $exactCode = trim((string)($lookup[$title] ?? ''));
    if ($exactCode !== '') {
        return $exactCode;
    }

    $normalizedTitle = normalize_match_text($title);
    foreach ($lookup as $lookupTitle => $lookupCode) {
        $lookupCode = trim((string)$lookupCode);
        if ($lookupCode === '') {
            continue;
        }

        if (normalize_match_text((string)$lookupTitle) === $normalizedTitle) {
            return $lookupCode;
        }
    }

    return '';
}

function resolve_detail_lookup_code(array $unitCodeLookup, string $detailType, string $title, string $parentTitle = ''): string
{
    if ($detailType === 'major_work') {
        return lookup_unit_code($unitCodeLookup['major_work'] ?? [], $title);
    }
    if ($detailType === 'major_work_sub') {
        return lookup_unit_code($unitCodeLookup['major_work'] ?? [], $parentTitle . ' - ' . $title);
    }
    if ($detailType === 'tool') {
        return lookup_unit_code($unitCodeLookup['tool'] ?? [], $title);
    }
    if ($detailType === 'env') {
        return lookup_unit_code($unitCodeLookup['env'] ?? [], $title);
    }

    return '';
}

function should_default_equipment_major_work(string $detailTitle, array $savedReport): bool
{
    if (($savedReport['use_equipment_yn'] ?? 'N') !== 'Y') {
        return false;
    }

    if (!empty($savedReport['detail_tasks'])) {
        return false;
    }

    return is_equipment_related_title($detailTitle, $savedReport);
}

function is_equipment_related_title(string $title, array $savedReport): bool
{
    $normalizedTitle = normalize_match_text($title);
    if ($normalizedTitle === '') {
        return false;
    }

    if (mb_stripos($normalizedTitle, '중장비') !== false) {
        return true;
    }

    foreach (($savedReport['tools'] ?? []) as $toolName) {
        $normalizedToolName = normalize_match_text((string)$toolName);
        if ($normalizedToolName === '') {
            continue;
        }

        if (mb_stripos($normalizedTitle, $normalizedToolName) !== false) {
            return true;
        }

        // Tool-specific aliases for major/sub defaults.
        if (mb_stripos($normalizedToolName, normalize_match_text('용접기')) !== false) {
            foreach (['불꽃', '불꽃작업', '용접', '용접작업'] as $aliasKeyword) {
                if (mb_stripos($normalizedTitle, normalize_match_text($aliasKeyword)) !== false) {
                    return true;
                }
            }
        }

        if (mb_stripos($normalizedToolName, normalize_match_text('가스절단기')) !== false) {
            foreach (['불꽃', '불꽃작업', '가스절단작업', '가스절단', '가스절단기'] as $aliasKeyword) {
                if (mb_stripos($normalizedTitle, normalize_match_text($aliasKeyword)) !== false) {
                    return true;
                }
            }
        }
    }

    return false;
}

function should_default_equipment_major_work_sub(string $parentTitle, string $subTitle, array $savedReport): bool
{
    if (($savedReport['use_equipment_yn'] ?? 'N') !== 'Y') {
        return false;
    }

    if (!empty($savedReport['detail_tasks'])) {
        return false;
    }

    if (!is_equipment_related_title($parentTitle, $savedReport)) {
        return false;
    }

    return is_equipment_related_title($subTitle, $savedReport);
}

function ensureWorkReportTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS heavy_equipment_master (
            equipment_id INT NOT NULL AUTO_INCREMENT,
            equipment_name VARCHAR(255) NOT NULL,
            use_yn CHAR(1) NOT NULL DEFAULT 'Y',
            sort_no INT NOT NULL DEFAULT 0,
            PRIMARY KEY (equipment_id),
            UNIQUE KEY uk_heavy_equipment_master_name (equipment_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $seedItems = [
        ['크레인', 10],
        ['지게차', 20],
        ['스카이', 30],
    ];
    $seedStmt = $pdo->prepare("
        INSERT INTO heavy_equipment_master (equipment_name, use_yn, sort_no)
        VALUES (:equipment_name, 'Y', :sort_no)
        ON DUPLICATE KEY UPDATE
            use_yn = VALUES(use_yn),
            sort_no = VALUES(sort_no)
    ");
    $skyExistsStmt = $pdo->query("SELECT COUNT(*) FROM heavy_equipment_master WHERE equipment_name = '스카이'");
    $skyExists = (int)$skyExistsStmt->fetchColumn() > 0;
    if ($skyExists) {
        $pdo->exec("DELETE FROM heavy_equipment_master WHERE equipment_name = '고소작업대'");
    } else {
        $pdo->exec("UPDATE heavy_equipment_master SET equipment_name = '스카이' WHERE equipment_name = '고소작업대'");
    }
    // 과거 인코딩 깨짐으로 저장된 장비명을 정리한다.
    $pdo->exec("DELETE FROM heavy_equipment_master WHERE equipment_name REGEXP '[^가-힣A-Za-z0-9 ()_/.-]'");
    $pdo->exec("DELETE FROM heavy_equipment_master WHERE equipment_name IN ('굴착기', '굴삭기', '로더')");

    foreach ($seedItems as [$equipmentName, $sortNo]) {
        $seedStmt->execute([
            ':equipment_name' => $equipmentName,
            ':sort_no' => $sortNo,
        ]);
    }

    // tool_master에 없는 항목만 추가 (엑셀 동기화와 충돌 없이 보완)
    $toolSeeds = [
        ['전동드릴(유선)', 500],
        ['전동드릴(무선)', 510],
    ];
    foreach ($toolSeeds as [$toolName, $sortNo]) {
        $pdo->exec("
            INSERT INTO tool_master (tool_name, use_yn, sort_no)
            SELECT '{$toolName}', 'Y', {$sortNo} FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM tool_master WHERE tool_name = '{$toolName}')
        ");
    }

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
        CREATE TABLE IF NOT EXISTS work_report_selected_unit (
            report_selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            sort_no INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_selection_id),
            UNIQUE KEY uk_work_report_selected_unit (report_id, unit_ra_id),
            KEY idx_work_report_selected_unit_report (report_id),
            KEY idx_work_report_selected_unit_unit (unit_ra_id),
            CONSTRAINT fk_work_report_selected_unit_report
                FOREIGN KEY (report_id) REFERENCES work_report(report_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_detail (
            report_detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            task_name VARCHAR(255) NOT NULL,
            risk_code VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_detail_id),
            KEY idx_work_report_detail_report_id (report_id),
            CONSTRAINT fk_work_report_detail_report
                FOREIGN KEY (report_id) REFERENCES work_report(report_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try {
        $pdo->exec("ALTER TABLE work_report_detail ADD COLUMN risk_code VARCHAR(100) NULL AFTER task_name");
    } catch (Throwable $e) {
        // Column already exists.
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_type_sub_master (
            sub_id INT NOT NULL AUTO_INCREMENT,
            process_category VARCHAR(100) NOT NULL,
            sub_name VARCHAR(255) NOT NULL,
            use_yn CHAR(1) NOT NULL DEFAULT 'Y',
            sort_no INT NOT NULL DEFAULT 0,
            PRIMARY KEY (sub_id),
            UNIQUE KEY uk_work_type_sub_master (process_category, sub_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $seedWorkTypeSubStmt = $pdo->prepare("
        INSERT INTO work_type_sub_master (process_category, sub_name, use_yn, sort_no)
        VALUES (:process_category, :sub_name, 'Y', :sort_no)
        ON DUPLICATE KEY UPDATE
            use_yn = VALUES(use_yn),
            sort_no = VALUES(sort_no)
    ");
    foreach ([
        ['결선', '결선', 10],
        ['결선', '배선', 20],
        ['결선', '배선 및 결선', 30],
    ] as [$processCategory, $subName, $sortNo]) {
        $seedWorkTypeSubStmt->execute([
            ':process_category' => $processCategory,
            ':sub_name' => $subName,
            ':sort_no' => $sortNo,
        ]);
    }
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
    return ['ext' => $ext, 'binary' => $binary];
}

function ensure_directory_exists(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('이미지 업로드 폴더를 만들 수 없습니다.');
    }
}

function work_report_temp_directory(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'work_report_temp';
}

function is_work_report_temp_path(string $filePath): bool
{
    $normalized = str_replace('\\', '/', $filePath);
    return str_starts_with($normalized, 'uploads/work_report_temp/');
}

function save_temp_work_report_image(string $dataUrl, string $loginId): array
{
    $image = decodeDataUrlImage($dataUrl);
    if ($image === null) {
        throw new RuntimeException('업로드할 이미지 데이터를 읽을 수 없습니다.');
    }

    $uploadDir = work_report_temp_directory();
    ensure_directory_exists($uploadDir);

    $safeLoginId = preg_replace('/[^A-Za-z0-9_-]/', '', $loginId) ?: 'user';
    $fileName = sprintf(
        'tmp_%s_%s.%s',
        $safeLoginId,
        bin2hex(random_bytes(8)),
        $image['ext']
    );

    $fullPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($fullPath, $image['binary']) === false) {
        throw new RuntimeException('임시 이미지를 저장하지 못했습니다.');
    }

    return [
        'file_name' => $fileName,
        'file_path' => 'uploads/work_report_temp/' . $fileName,
    ];
}

function save_uploaded_temp_work_report_image(array $uploadedFile, string $loginId): array
{
    $tmpName = (string)($uploadedFile['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('업로드한 이미지 파일을 확인할 수 없습니다.');
    }

    $originalName = (string)($uploadedFile['name'] ?? '');
    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $ext = 'jpg';
    }
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    $uploadDir = work_report_temp_directory();
    ensure_directory_exists($uploadDir);

    $safeLoginId = preg_replace('/[^A-Za-z0-9_-]/', '', $loginId) ?: 'user';
    $fileName = sprintf(
        'tmp_%s_%s.%s',
        $safeLoginId,
        bin2hex(random_bytes(8)),
        $ext
    );

    $fullPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $fullPath)) {
        throw new RuntimeException('임시 이미지를 저장하지 못했습니다.');
    }

    return [
        'file_name' => $fileName,
        'file_path' => 'uploads/work_report_temp/' . $fileName,
    ];
}

function delete_temp_work_report_image(string $filePath): void
{
    if ($filePath === '' || !is_work_report_temp_path($filePath)) {
        return;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function move_temp_work_report_image_to_final(string $filePath, int $reportId, int $sortNo): ?array
{
    if ($filePath === '' || !is_work_report_temp_path($filePath)) {
        return null;
    }

    $sourcePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
    if (!is_file($sourcePath)) {
        return null;
    }

    $finalDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'work_report';
    ensure_directory_exists($finalDir);

    $ext = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }

    $fileName = sprintf('report_%d_%02d.%s', $reportId, $sortNo, $ext);
    $targetPath = $finalDir . DIRECTORY_SEPARATOR . $fileName;

    if (!@rename($sourcePath, $targetPath)) {
        if (!@copy($sourcePath, $targetPath)) {
            throw new RuntimeException('첨부 이미지를 최종 저장하지 못했습니다.');
        }
        @unlink($sourcePath);
    }

    return [
        'file_name' => $fileName,
        'file_path' => 'uploads/work_report/' . $fileName,
    ];
}

function sanitizeNoteHtml(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html) ?? '';
    $html = preg_replace('/\son\w+="[^"]*"/i', '', $html) ?? '';
    $html = preg_replace("/\son\w+='[^']*'/i", '', $html) ?? '';
    $html = preg_replace('/\s(href|src)=["\']\s*javascript:[^"\']*["\']/i', '', $html) ?? '';
    return trim(strip_tags($html, '<p><br><b><strong><i><em><u><s><strike><sup><sub><ul><ol><li><div><span><img><figure><figcaption><blockquote><hr><h3><h4><a><table><thead><tbody><tfoot><tr><td><th><colgroup><col>'));
}

function filterReferencedTempImagePaths(array $filePaths, string $html): array
{
    $normalizedHtml = str_replace('\\', '/', $html);
    $referencedPaths = [];
    $seen = [];

    foreach ($filePaths as $filePath) {
        $normalizedPath = str_replace('\\', '/', trim((string)$filePath));
        if ($normalizedPath === '' || !is_work_report_temp_path($normalizedPath)) {
            continue;
        }
        if (!str_contains($normalizedHtml, $normalizedPath)) {
            continue;
        }
        if (isset($seen[$normalizedPath])) {
            continue;
        }
        $seen[$normalizedPath] = true;
        $referencedPaths[] = $normalizedPath;
    }

    return $referencedPaths;
}

function deleteReportImages(PDO $pdo, int $reportId): void
{
    $stmt = $pdo->prepare("SELECT file_path FROM work_report_image WHERE report_id = :report_id");
    $stmt->execute([':report_id' => $reportId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
        if (!is_string($filePath) || $filePath === '') {
            continue;
        }
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

$error = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$registrationOpen = auth_is_worker_registration_open();

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    auth_logout();
    header('Location: ' . $loginTargetPage);
    exit;
}

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    if (!auth_login($loginId, $password)) {
        $error = '로그인 정보가 올바르지 않습니다.';
    } else {
        $loggedInUser = auth_current_user();
        $loggedInRole = (string)($loggedInUser['role'] ?? '');
        if ($loggedInRole === 'safety_manager') {
            $nextPage = 'work_list.php';
        } elseif ($loggedInUser !== null && auth_is_worker($loggedInUser)) {
            $nextPage = 'work_list.php';
        } elseif ($loggedInUser !== null && auth_can_lead($loggedInUser) && !auth_can_manage($loggedInUser)) {
            $nextPage = 'work_list.php';
        } elseif ($loggedInUser !== null && auth_can_manage($loggedInUser) && auth_team_key((string)($loggedInUser['team'] ?? '')) === auth_team_key('가스팀')) {
            $nextPage = 'work_list.php';
        } elseif ($loggedInUser !== null && auth_can_manage($loggedInUser)) {
            $nextPage = 'work_list.php';
        } else {
            $nextPage = 'work_list.php';
        }
        header('Location: ' . $nextPage);
        exit;
    }
}

$user = auth_current_user();
$userRole = (string)($user['role'] ?? '');
$isAdmin = auth_is_admin($user);
$canManage = auth_can_manage($user);
$canLead = auth_can_lead($user);
$isWorker = auth_is_worker($user);
$managerShortcutTeams = [];
if (!$isLeaderPage) {
    if ($isAdmin) {
        $managerShortcutTeams = auth_read_teams();
    } elseif ($canManage) {
        $currentTeam = auth_normalize_team_name((string)($user['team'] ?? ''));
        $supervisedTeams = auth_supervised_teams($currentTeam);
        $managerShortcutTeams = auth_unique_team_list(array_merge(
            $currentTeam !== '' ? [$currentTeam] : [],
            $supervisedTeams
        ));
    }
}
$selectedManagerTeam = resolve_team_from_list(
    $managerShortcutTeams,
    trim((string)($_GET['manager_team'] ?? $_POST['manager_team'] ?? ''))
);
$effectiveTeamUser = $user;
if ($effectiveTeamUser !== null && $selectedManagerTeam !== '') {
    $effectiveTeamUser['team'] = $selectedManagerTeam;
}
$pageRole = $isLeaderPage ? ($canLead ? 'leader' : $userRole) : ($canManage ? 'manager' : $userRole);
$teamProcessPreferences = auth_team_process_preferences($effectiveTeamUser);
$defaultManagerProcessCategory = (!$isLeaderPage && $pageRole === 'manager')
    ? (string)($teamProcessPreferences['default_manager_process_category'] ?? '')
    : '';
$allowedManagerProcessCategories = (!$isLeaderPage && $pageRole === 'manager')
    ? array_values(array_filter(array_map(
        static fn($value) => trim((string)$value),
        (array)($teamProcessPreferences['allowed_manager_process_categories'] ?? [])
    )))
    : [];
$excludedManagerProcessCategories = (!$isLeaderPage && $pageRole === 'manager')
    ? array_values(array_filter(array_map(
        static fn($value) => trim((string)$value),
        (array)($teamProcessPreferences['excluded_manager_process_categories'] ?? [])
    )))
    : [];
$managerContextParams = (!$isLeaderPage && $selectedManagerTeam !== '') ? ['manager_team' => $selectedManagerTeam] : [];
$effectiveReportTeamName = $selectedManagerTeam !== ''
    ? $selectedManagerTeam
    : auth_normalize_team_name((string)($user['team'] ?? ''));
$managerCoversLeaderRole = !$isLeaderPage && auth_manager_can_cover_leader_role($effectiveTeamUser);
$canLeadInCurrentContext = $canLead || $managerCoversLeaderRole;
$showLeaderDetailSection = $pageRole === 'leader' || $managerCoversLeaderRole;
if ($user !== null) {
    if ($userRole === 'leader' && !$isLeaderPage) {
        header('Location: work_list.php');
        exit;
    }
    if ($isWorker && (int)($_GET['saved_report_id'] ?? 0) <= 0) {
        header('Location: work_list.php');
        exit;
    }
    if (!$canLeadInCurrentContext && $isLeaderPage) {
        header('Location: task_select.php');
        exit;
    }
}
$savedRaId = isset($_GET['saved_ra_id']) ? (int)$_GET['saved_ra_id'] : 0;
$savedReportId = isset($_GET['saved_report_id']) ? (int)$_GET['saved_report_id'] : 0;
$editingReportId = isset($_GET['edit_report_id']) ? (int)$_GET['edit_report_id'] : (int)($_POST['report_id'] ?? 0);
$addMode = isset($_GET['add_mode']) && trim((string)$_GET['add_mode']) === '1';
$tasks = [];
$targetOptions = [];
$workTypeSubOptions = [];
$taskItemsByUnit = [];
$taskMapById = [];
$detailOptionGroups = [
    'major_work' => [],
    'env' => [],
    'tool' => [],
];
$majorWorkSubOptions = [];
$tools = [];
$selectedUnitRaId = isset($_GET['unit_ra_id']) ? (int)$_GET['unit_ra_id'] : (int)($_POST['unit_ra_id'] ?? 0);
$selectedUnitRaIds = normalize_unit_ra_id_values($_POST['selected_unit_ra_ids'] ?? []);
if ($selectedUnitRaId > 0 && !in_array($selectedUnitRaId, $selectedUnitRaIds, true)) {
    array_unshift($selectedUnitRaIds, $selectedUnitRaId);
}
if (!empty($selectedUnitRaIds)) {
    $selectedUnitRaId = (int)$selectedUnitRaIds[0];
}
$savedReport = null;
$leaderElectricalDetailOnlyMode = false;
$formErrors = [];
$formDefaults = [
    'work_title' => '',
    'work_date' => date('Y-m-d'),
    'work_place' => '',
    'use_equipment_yn' => 'N',
    'selected_tools' => [],
    'detail_tasks' => [],
    'detail_code_map' => [],
    'note_html' => '',
    'pasted_images' => [],
];

if ($user !== null) {
    $pdo = getDB();
    ensureWorkReportTables($pdo);

    if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'upload_pasted_image') {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (!empty($_FILES['image_file']) && is_array($_FILES['image_file'])) {
                $uploaded = save_uploaded_temp_work_report_image($_FILES['image_file'], (string)($user['login_id'] ?? 'user'));
            } else {
                $imageData = (string)($_POST['image_data'] ?? '');
                if ($imageData === '') {
                    throw new RuntimeException('업로드할 이미지가 없습니다.');
                }

                $uploaded = save_temp_work_report_image($imageData, (string)($user['login_id'] ?? 'user'));
            }
            echo json_encode([
                'success' => true,
                'file_name' => $uploaded['file_name'],
                'file_path' => $uploaded['file_path'],
                'preview_url' => $uploaded['file_path'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'delete_pasted_image') {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            delete_temp_work_report_image((string)($_POST['file_path'] ?? ''));
            echo json_encode([
                'success' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    $stmt = $pdo->query("
        SELECT
            h.unit_ra_id,
            h.unit_code,
            h.unit_title,
            h.unit_type,
            h.process_name,
            h.created_by,
            h.created_at,
            COUNT(i.item_id) AS item_count
        FROM unit_ra_header h
        LEFT JOIN unit_ra_item i
            ON i.unit_ra_id = h.unit_ra_id
           AND i.use_yn = 'Y'
        WHERE h.use_yn = 'Y'
        GROUP BY h.unit_ra_id
        ORDER BY h.sort_no ASC, h.unit_ra_id DESC
    ");
    $tasks = $stmt->fetchAll();
    $taskMapById = [];
    foreach ($tasks as $task) {
        $taskMapById[(int)$task['unit_ra_id']] = $task;
    }

    $majorWorkRows = $pdo->query("
        SELECT major_work_id, major_work_name
        FROM major_work_master
        WHERE use_yn = 'Y'
        ORDER BY sort_no ASC, major_work_id ASC
    ")->fetchAll();
    foreach ($majorWorkRows as $majorWorkRow) {
        $majorWorkId = (int)($majorWorkRow['major_work_id'] ?? 0);
        $majorWorkName = trim((string)($majorWorkRow['major_work_name'] ?? ''));
        if ($majorWorkId <= 0 || $majorWorkName === '') {
            continue;
        }
        $detailOptionGroups['major_work'][] = [
            'id' => $majorWorkId,
            'title' => $majorWorkName,
        ];
        $majorWorkSubOptions[$majorWorkName] = [];
    }

    $majorWorkSubRows = $pdo->query("
        SELECT m.major_work_name, s.sub_name
        FROM major_work_sub_master s
        INNER JOIN major_work_master m
            ON m.major_work_id = s.major_work_id
        WHERE s.use_yn = 'Y'
          AND m.use_yn = 'Y'
        ORDER BY m.sort_no ASC, m.major_work_id ASC, s.sort_no ASC, s.sub_id ASC
    ")->fetchAll();
    foreach ($majorWorkSubRows as $subRow) {
        $majorWorkName = trim((string)($subRow['major_work_name'] ?? ''));
        $subName = trim((string)($subRow['sub_name'] ?? ''));
        if ($majorWorkName === '' || $subName === '') {
            continue;
        }
        if (!isset($majorWorkSubOptions[$majorWorkName])) {
            $majorWorkSubOptions[$majorWorkName] = [];
        }
        $majorWorkSubOptions[$majorWorkName][$subName] = $subName;
    }
    foreach ($majorWorkSubOptions as $majorWorkName => $subOptions) {
        $majorWorkSubOptions[$majorWorkName] = array_values($subOptions);
    }

    $detailOptionGroups['env'] = $pdo->query("
        SELECT env_name
        FROM env_master
        WHERE use_yn = 'Y'
        ORDER BY sort_no ASC, env_id ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $detailOptionGroups['tool'] = $pdo->query("
        SELECT tool_name
        FROM tool_master
        WHERE use_yn = 'Y'
        ORDER BY sort_no ASC, tool_id ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach (['env', 'tool'] as $type) {
        $detailOptionGroups[$type] = array_values(array_unique(array_filter(array_map(
            static fn($value) => trim((string)$value),
            $detailOptionGroups[$type]
        ))));
    }

    // 위험성평가번호 자동 조회용 맵
    $unitCodeLookup = ['env' => [], 'tool' => [], 'major_work' => []];
    $unitCodeRows = $pdo->query("
        SELECT unit_type, unit_title, unit_code
        FROM unit_ra_header
        WHERE unit_type IN ('env','tool','major_work')
          AND use_yn = 'Y'
          AND unit_code IS NOT NULL AND unit_code != ''
    ")->fetchAll();
    foreach ($unitCodeRows as $ucRow) {
        $uType  = (string)$ucRow['unit_type'];
        $uTitle = trim((string)$ucRow['unit_title']);
        $uCode  = (string)$ucRow['unit_code'];
        if (isset($unitCodeLookup[$uType]) && $uTitle !== '') {
            $unitCodeLookup[$uType][$uTitle] = $uCode;
        }
    }

    $itemStmt = $pdo->query("
        SELECT unit_ra_id, item_id, task_name, sort_no
        FROM unit_ra_item
        WHERE use_yn = 'Y'
        ORDER BY unit_ra_id ASC, sort_no ASC, item_id ASC
    ");
    foreach ($itemStmt->fetchAll() as $row) {
        $unitId = (int)$row['unit_ra_id'];
        if (!isset($taskItemsByUnit[$unitId])) {
            $taskItemsByUnit[$unitId] = [];
        }
        $taskItemsByUnit[$unitId][] = [
            'item_id' => (int)$row['item_id'],
            'task_name' => (string)$row['task_name'],
            'sort_no' => (int)$row['sort_no'],
        ];
    }

    $tools = $pdo->query("
        SELECT equipment_id AS tool_id, equipment_name AS tool_name
        FROM heavy_equipment_master
        WHERE use_yn = 'Y'
        ORDER BY sort_no ASC, equipment_id ASC
    ")->fetchAll();

    if ($canManage) {
        $stmt = $pdo->query("
            SELECT
                w.process_category,
                w.major_category,
                w.work_type,
                MIN(NULLIF(h.unit_code, '')) AS unit_code
            FROM work_target_master w
            LEFT JOIN unit_ra_header h
              ON h.use_yn = 'Y'
             AND h.unit_type = 'target'
             AND h.process_name = w.process_category
             AND h.unit_title = CONCAT(w.major_category, ' - ', w.work_type)
            WHERE w.use_yn = 'Y'
              AND w.process_category IS NOT NULL
              AND w.major_category IS NOT NULL
              AND w.work_type IS NOT NULL
            GROUP BY w.process_category, w.major_category, w.work_type
            ORDER BY w.process_category ASC, w.major_category ASC, w.work_type ASC
        ");
        $targetOptions = $stmt->fetchAll();
        $normalizedAllowedManagerProcessCategories = array_map('normalize_match_text', $allowedManagerProcessCategories);
        $normalizedExcludedManagerProcessCategories = array_map('normalize_match_text', $excludedManagerProcessCategories);
        if (!empty($allowedManagerProcessCategories)) {
            $targetOptions = array_values(array_filter(
                $targetOptions,
                static fn($item) => in_array(
                    normalize_match_text(trim((string)($item['process_category'] ?? ''))),
                    $normalizedAllowedManagerProcessCategories,
                    true
                )
            ));
        }
        if (!empty($excludedManagerProcessCategories)) {
            $targetOptions = array_values(array_filter(
                $targetOptions,
                static fn($item) => !in_array(
                    normalize_match_text(trim((string)($item['process_category'] ?? ''))),
                    $normalizedExcludedManagerProcessCategories,
                    true
                )
            ));
        }

        $subStmt = $pdo->query("
            SELECT process_category, sub_name
            FROM work_type_sub_master
            WHERE use_yn = 'Y'
            ORDER BY process_category ASC, sort_no ASC, sub_id ASC
        ");
        foreach ($subStmt->fetchAll() as $subRow) {
            $processCategory = trim((string)($subRow['process_category'] ?? ''));
            $subName = trim((string)($subRow['sub_name'] ?? ''));
            if ($processCategory === '' || $subName === '') {
                continue;
            }
            if (!empty($allowedManagerProcessCategories) && !in_array(normalize_match_text($processCategory), $normalizedAllowedManagerProcessCategories, true)) {
                continue;
            }
            if (in_array(normalize_match_text($processCategory), $normalizedExcludedManagerProcessCategories, true)) {
                continue;
            }
            if (!isset($workTypeSubOptions[$processCategory])) {
                $workTypeSubOptions[$processCategory] = [];
            }
            $workTypeSubOptions[$processCategory][] = $subName;
        }
        foreach ($workTypeSubOptions as $processCategory => $options) {
            $workTypeSubOptions[$processCategory] = array_values(array_unique($options));
        }
    }

    if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'delete_report') {
        $deleteReportId = (int)($_POST['report_id'] ?? 0);
        if ($deleteReportId <= 0) {
            $formErrors[] = '삭제할 작업을 찾을 수 없습니다.';
        } else {
            $ownerStmt = $pdo->prepare("
                SELECT report_id, unit_ra_id
                FROM work_report
                WHERE report_id = :report_id
                  AND user_login_id = :user_login_id
                LIMIT 1
            ");
            $ownerStmt->execute([
                ':report_id' => $deleteReportId,
                ':user_login_id' => $user['login_id'],
            ]);
            $deleteTarget = $ownerStmt->fetch();

            if (!$deleteTarget) {
                $formErrors[] = '삭제할 작업을 찾을 수 없거나 삭제 권한이 없습니다.';
            } else {
                try {
                    $pdo->beginTransaction();
                    deleteReportImages($pdo, $deleteReportId);
                    $deleteStmt = $pdo->prepare("DELETE FROM work_report WHERE report_id = :report_id");
                    $deleteStmt->execute([':report_id' => $deleteReportId]);
                    $pdo->commit();
                    header('Location: ' . build_page_url($selfPage, array_merge($managerContextParams, [
                        'unit_ra_id' => (int)$deleteTarget['unit_ra_id'],
                    ])));
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $formErrors[] = '삭제 중 오류가 발생했습니다: ' . $e->getMessage();
                }
            }
        }
    }

    if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'save_report') {
        $editingReportId = (int)($_POST['report_id'] ?? 0);
        $selectedUnitRaIds = normalize_unit_ra_id_values($_POST['selected_unit_ra_ids'] ?? []);
        if ($selectedUnitRaId > 0 && !in_array($selectedUnitRaId, $selectedUnitRaIds, true)) {
            array_unshift($selectedUnitRaIds, $selectedUnitRaId);
        }
        $selectedUnitRaIds = array_values(array_filter(
            $selectedUnitRaIds,
            static fn($unitRaId) => isset($taskMapById[(int)$unitRaId])
        ));
        if (empty($selectedUnitRaIds) && $selectedUnitRaId > 0 && isset($taskMapById[$selectedUnitRaId])) {
            $selectedUnitRaIds = [$selectedUnitRaId];
        }
        $selectedUnitRaId = !empty($selectedUnitRaIds) ? (int)$selectedUnitRaIds[0] : 0;

        $formDefaults['work_title'] = trim((string)($_POST['work_title'] ?? ''));
        $formDefaults['work_date'] = trim((string)($_POST['work_date'] ?? ''));
        $formDefaults['work_place'] = trim((string)($_POST['work_place'] ?? ''));
        $formDefaults['use_equipment_yn'] = ($_POST['use_equipment_yn'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $formDefaults['selected_tools'] = array_map('intval', $_POST['selected_tools'] ?? []);
        if ($showLeaderDetailSection) {
            $formDefaults['detail_tasks'] = normalize_detail_task_values($_POST['detail_tasks'] ?? []);
            $formDefaults['detail_code_map'] = normalize_detail_code_values($_POST['detail_codes'] ?? []);
        }
        $formDefaults['note_html'] = sanitizeNoteHtml((string)($_POST['note_html'] ?? ''));
        $pastedImagesRaw = json_decode((string)($_POST['pasted_images'] ?? '[]'), true);
        if (!is_array($pastedImagesRaw)) {
            $pastedImagesRaw = [];
        }
        $pastedImages = array_values(array_filter(array_map(
            static function ($value): string {
                if (is_string($value)) {
                    return trim($value);
                }
                if (is_array($value) && isset($value['file_path']) && is_string($value['file_path'])) {
                    return trim($value['file_path']);
                }
                return '';
            },
            $pastedImagesRaw
        )));
        $pastedImages = filterReferencedTempImagePaths($pastedImages, $formDefaults['note_html']);
        $formDefaults['pasted_images'] = $pastedImages;

        $selectedTask = $taskMapById[$selectedUnitRaId] ?? null;
        if ($selectedTask === null) {
            $formErrors[] = '기초 작업을 먼저 선택해 주세요.';
        }
        if ($formDefaults['work_title'] === '') {
            $formErrors[] = '작업명을 입력해 주세요.';
        }
        if ($formDefaults['work_date'] === '') {
            $formErrors[] = '작업일자를 입력해 주세요.';
        }
        if ($formDefaults['work_place'] === '') {
            $formErrors[] = '작업장소를 입력해 주세요.';
        }
        if ($formDefaults['use_equipment_yn'] === 'Y' && empty($formDefaults['selected_tools'])) {
            $formErrors[] = '장비사용 여부를 체크한 경우 중장비를 선택해 주세요.';
        }

        if (empty($formErrors)) {
            try {
                $pdo->beginTransaction();

                $reportId = $editingReportId;
                if ($editingReportId > 0) {
                    // 권한 확인: 작성자이거나, leader이면서 saved_report_id가 있는 경우 (기존 작업 열기)
                    $reportCheckStmt = $pdo->prepare("
                        SELECT report_id
                        FROM work_report
                        WHERE report_id = :report_id
                        LIMIT 1
                    ");
                    $reportCheckStmt->execute([
                        ':report_id' => $editingReportId,
                    ]);
                    $existingReport = $reportCheckStmt->fetch();

                    if (!$existingReport) {
                        throw new RuntimeException('수정할 작업을 찾을 수 없거나 수정 권한이 없습니다.');
                    }

                    // 권한 체크: 작성자이거나, leader 또는 관리권한이 있는 사용자
                    $isOwner = (string)($existingReport['user_login_id'] ?? '') === (string)($user['login_id'] ?? '');
                    $isLeader = $pageRole === 'leader';
                    $isManager = auth_can_manage($user);

                    if (!$isOwner && !$isLeader && !$isManager) {
                        throw new RuntimeException('수정할 작업을 찾을 수 없거나 수정 권한이 없습니다.');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE work_report
                        SET unit_ra_id = :unit_ra_id,
                            role_code = :role_code,
                            user_name = :user_name,
                            team_name = :team_name,
                            work_title = :work_title,
                            work_date = :work_date,
                            work_place = :work_place,
                            use_equipment_yn = :use_equipment_yn,
                            note_html = :note_html
                        WHERE report_id = :report_id
                    ");
                    $stmt->execute([
                        ':unit_ra_id' => $selectedUnitRaId,
                        ':role_code' => $pageRole,
                        ':user_name' => $user['name'],
                        ':team_name' => $effectiveReportTeamName !== '' ? $effectiveReportTeamName : null,
                        ':work_title' => $formDefaults['work_title'],
                        ':work_date' => $formDefaults['work_date'],
                        ':work_place' => $formDefaults['work_place'],
                        ':use_equipment_yn' => $formDefaults['use_equipment_yn'],
                        ':note_html' => $formDefaults['note_html'] !== '' ? $formDefaults['note_html'] : null,
                        ':report_id' => $editingReportId,
                    ]);

                    $pdo->prepare("DELETE FROM work_report_task WHERE report_id = :report_id")
                        ->execute([':report_id' => $reportId]);
                    $pdo->prepare("DELETE FROM work_report_tool WHERE report_id = :report_id")
                        ->execute([':report_id' => $reportId]);
                } else {
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
                        ':unit_ra_id' => $selectedUnitRaId,
                        ':role_code' => $pageRole,
                        ':user_login_id' => $user['login_id'],
                        ':user_name' => $user['name'],
                        ':team_name' => $effectiveReportTeamName !== '' ? $effectiveReportTeamName : null,
                        ':work_title' => $formDefaults['work_title'],
                        ':work_date' => $formDefaults['work_date'],
                        ':work_place' => $formDefaults['work_place'],
                        ':use_equipment_yn' => $formDefaults['use_equipment_yn'],
                        ':note_html' => $formDefaults['note_html'] !== '' ? $formDefaults['note_html'] : null,
                    ]);

                    $reportId = (int)$pdo->lastInsertId();
                }
                $itemMap = [];
                $taskSortNo = 1;
                foreach ($selectedUnitRaIds as $selectedTaskUnitRaId) {
                    foreach ($taskItemsByUnit[$selectedTaskUnitRaId] ?? [] as $item) {
                        $itemId = (int)($item['item_id'] ?? 0);
                        if ($itemId <= 0 || isset($itemMap[$itemId])) {
                            continue;
                        }
                        $itemMap[$itemId] = [
                            'item_id' => $itemId,
                            'task_name' => (string)($item['task_name'] ?? ''),
                            'sort_no' => $taskSortNo++,
                        ];
                    }
                }

                $pdo->prepare("DELETE FROM work_report_selected_unit WHERE report_id = :report_id")
                    ->execute([':report_id' => $reportId]);

                if (!empty($selectedUnitRaIds)) {
                    $selectedUnitStmt = $pdo->prepare("
                        INSERT INTO work_report_selected_unit (
                            report_id, unit_ra_id, sort_no
                        ) VALUES (
                            :report_id, :unit_ra_id, :sort_no
                        )
                    ");
                    foreach ($selectedUnitRaIds as $sortNo => $selectedTaskUnitRaId) {
                        $selectedUnitStmt->execute([
                            ':report_id' => $reportId,
                            ':unit_ra_id' => (int)$selectedTaskUnitRaId,
                            ':sort_no' => $sortNo + 1,
                        ]);
                    }
                }

                if (!empty($itemMap)) {
                    $taskStmt = $pdo->prepare("
                        INSERT INTO work_report_task (
                            report_id, item_id, task_name, sort_no
                        ) VALUES (
                            :report_id, :item_id, :task_name, :sort_no
                        )
                    ");
                    foreach ($itemMap as $taskId => $item) {
                        $taskStmt->execute([
                            ':report_id' => $reportId,
                            ':item_id' => $taskId,
                            ':task_name' => $item['task_name'],
                            ':sort_no' => $item['sort_no'],
                        ]);
                    }
                }

                if ($formDefaults['use_equipment_yn'] === 'Y' && !empty($formDefaults['selected_tools'])) {
                    $toolMap = [];
                    foreach ($tools as $tool) {
                        $toolMap[(int)$tool['tool_id']] = $tool;
                    }
                    $toolStmt = $pdo->prepare("
                        INSERT INTO work_report_tool (
                            report_id, tool_id, tool_name
                        ) VALUES (
                            :report_id, :tool_id, :tool_name
                        )
                    ");
                    foreach ($formDefaults['selected_tools'] as $toolId) {
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

                if ($showLeaderDetailSection) {
                    $pdo->prepare("DELETE FROM work_report_detail WHERE report_id = :report_id")
                        ->execute([':report_id' => $reportId]);

                    if (!empty($formDefaults['detail_tasks'])) {
                        $detailStmt = $pdo->prepare("
                            INSERT INTO work_report_detail (
                                report_id, task_name, risk_code
                            ) VALUES (
                                :report_id, :task_name, :risk_code
                            )
                        ");
                        foreach ($formDefaults['detail_tasks'] as $detailTask) {
                            $riskCode = trim((string)($formDefaults['detail_code_map'][$detailTask] ?? ''));
                            $detailStmt->execute([
                                ':report_id' => $reportId,
                                ':task_name' => $detailTask,
                                ':risk_code' => $riskCode !== '' ? $riskCode : null,
                            ]);
                        }
                    }
                }

                $finalNoteHtml = $formDefaults['note_html'];

                if (!empty($pastedImages)) {
                    $sortNo = 0;
                    $imagePathReplacements = [];
                    if ($editingReportId > 0) {
                        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_no), 0) FROM work_report_image WHERE report_id = :report_id");
                        $sortStmt->execute([':report_id' => $reportId]);
                        $sortNo = (int)$sortStmt->fetchColumn();
                    }
                    $imgStmt = $pdo->prepare("
                        INSERT INTO work_report_image (
                            report_id, file_name, file_path, sort_no
                        ) VALUES (
                            :report_id, :file_name, :file_path, :sort_no
                        )
                    ");
                    foreach ($pastedImages as $filePath) {
                        $movedImage = move_temp_work_report_image_to_final($filePath, $reportId, $sortNo + 1);
                        if ($movedImage === null) {
                            continue;
                        }
                        $sortNo++;
                        $imagePathReplacements[$filePath] = $movedImage['file_path'];
                        $imgStmt->execute([
                            ':report_id' => $reportId,
                            ':file_name' => $movedImage['file_name'],
                            ':file_path' => $movedImage['file_path'],
                            ':sort_no' => $sortNo,
                        ]);
                    }

                    if (!empty($imagePathReplacements)) {
                        $finalNoteHtml = str_replace(
                            array_keys($imagePathReplacements),
                            array_values($imagePathReplacements),
                            $formDefaults['note_html']
                        );

                        $pdo->prepare("
                            UPDATE work_report
                            SET note_html = :note_html
                            WHERE report_id = :report_id
                        ")->execute([
                            ':note_html' => $finalNoteHtml !== '' ? $finalNoteHtml : null,
                            ':report_id' => $reportId,
                        ]);
                    }
                }

                if ($editingReportId > 0) {
                    $existingImageStmt = $pdo->prepare("
                        SELECT report_image_id, file_path
                        FROM work_report_image
                        WHERE report_id = :report_id
                    ");
                    $existingImageStmt->execute([':report_id' => $reportId]);
                    $existingImages = $existingImageStmt->fetchAll();

                    foreach ($existingImages as $existingImage) {
                        $savedFilePath = trim((string)($existingImage['file_path'] ?? ''));
                        if ($savedFilePath === '' || str_contains($finalNoteHtml, $savedFilePath)) {
                            continue;
                        }

                        $pdo->prepare("
                            DELETE FROM work_report_image
                            WHERE report_image_id = :report_image_id
                        ")->execute([
                            ':report_image_id' => (int)($existingImage['report_image_id'] ?? 0),
                        ]);

                        $savedFullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $savedFilePath);
                        if (is_file($savedFullPath)) {
                            @unlink($savedFullPath);
                        }
                    }
                }

                $pdo->commit();
                header('Location: ' . build_page_url($selfPage, array_merge($managerContextParams, [
                    'unit_ra_id' => $selectedUnitRaId,
                    'saved_report_id' => $reportId,
                ])));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formErrors[] = '저장 중 오류가 발생했습니다: ' . $e->getMessage();
            }
        }
    }

    if ($savedReportId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM work_report WHERE report_id = :report_id LIMIT 1");
        $stmt->execute([':report_id' => $savedReportId]);
        $savedReport = $stmt->fetch();
        if ($savedReport) {
            $savedTask = $taskMapById[(int)$savedReport['unit_ra_id']] ?? null;
            $selectedUnitStmt = $pdo->prepare("
                SELECT unit_ra_id
                FROM work_report_selected_unit
                WHERE report_id = :report_id
                ORDER BY sort_no ASC, report_selection_id ASC
            ");
            $selectedUnitStmt->execute([':report_id' => $savedReportId]);
            $savedSelectedUnitRaIds = normalize_unit_ra_id_values($selectedUnitStmt->fetchAll(PDO::FETCH_COLUMN));
            if (empty($savedSelectedUnitRaIds) && (int)$savedReport['unit_ra_id'] > 0) {
                $savedSelectedUnitRaIds[] = (int)$savedReport['unit_ra_id'];
            }
            $savedReport['selected_unit_ra_ids'] = $savedSelectedUnitRaIds;
            $savedReport['selected_units'] = [];
            foreach ($savedSelectedUnitRaIds as $selectedUnitId) {
                $selectedUnitTask = $taskMapById[$selectedUnitId] ?? null;
                if ($selectedUnitTask === null) {
                    continue;
                }
                $savedReport['selected_units'][] = [
                    'unit_ra_id' => (int)($selectedUnitTask['unit_ra_id'] ?? 0),
                    'unit_title' => (string)($selectedUnitTask['unit_title'] ?? ''),
                    'unit_code' => (string)($selectedUnitTask['unit_code'] ?? ''),
                ];
            }
            if (empty($savedReport['selected_units']) && $savedTask !== null) {
                $savedReport['selected_units'][] = [
                    'unit_ra_id' => (int)($savedTask['unit_ra_id'] ?? 0),
                    'unit_title' => (string)($savedTask['unit_title'] ?? ''),
                    'unit_code' => (string)($savedTask['unit_code'] ?? ''),
                ];
            }

            $savedReport['selected_unit_title'] = $savedTask['unit_title'] ?? '';
            $savedReport['selected_unit_code'] = $savedTask['unit_code'] ?? '';
            if (!empty($savedReport['selected_units'])) {
                $savedReport['selected_unit_title'] = (string)($savedReport['selected_units'][0]['unit_title'] ?? '');
                $savedReport['selected_unit_code'] = (string)($savedReport['selected_units'][0]['unit_code'] ?? '');
            }
            $savedReportTeamName = auth_normalize_team_name((string)($savedReport['team_name'] ?? ''));
            $savedReportContextTeamName = $savedReportTeamName !== '' ? $savedReportTeamName : $effectiveReportTeamName;
            $leaderElectricalDetailOnlyMode = $pageRole === 'leader' && $savedReportId > 0;

            $taskStmt = $pdo->prepare("SELECT task_name FROM work_report_task WHERE report_id = :report_id ORDER BY sort_no ASC, report_task_id ASC");
            $taskStmt->execute([':report_id' => $savedReportId]);
            $savedReport['tasks'] = array_values(array_unique(array_filter(
                $taskStmt->fetchAll(PDO::FETCH_COLUMN),
                static fn($value) => $value !== null && $value !== ''
            )));

            $toolStmt = $pdo->prepare("SELECT tool_name FROM work_report_tool WHERE report_id = :report_id ORDER BY report_tool_id ASC");
            $toolStmt->execute([':report_id' => $savedReportId]);
            $savedReport['tools'] = $toolStmt->fetchAll(PDO::FETCH_COLUMN);

            $imgStmt = $pdo->prepare("SELECT file_path, file_name FROM work_report_image WHERE report_id = :report_id ORDER BY sort_no ASC, report_image_id ASC");
            $imgStmt->execute([':report_id' => $savedReportId]);
            $savedReport['images'] = $imgStmt->fetchAll();

            $detailStmt = $pdo->prepare("SELECT task_name, risk_code FROM work_report_detail WHERE report_id = :report_id ORDER BY report_detail_id ASC");
            $detailStmt->execute([':report_id' => $savedReportId]);
            $savedReport['detail_tasks'] = [];
            $savedReport['detail_code_map'] = [];
            foreach ($detailStmt->fetchAll() as $detailRow) {
                $taskName = (string)($detailRow['task_name'] ?? '');
                if ($taskName === '') {
                    continue;
                }
                $savedReport['detail_tasks'][] = $taskName;
                $savedReport['detail_code_map'][$taskName] = (string)($detailRow['risk_code'] ?? '');
            }
            $savedReport['detail_grouped'] = build_detail_grouped_state(
                $savedReport['detail_tasks'],
                $savedReport['detail_code_map']
            );

            $savedReport['detail_code_summary'] = [
                'major_work' => [],
                'env' => [],
                'tool' => [],
            ];
            $detailDisplayBuckets = [
                'tool' => [],
                'env' => [],
                'major_work' => [],
            ];
            foreach ($savedReport['detail_tasks'] as $detailValue) {
                $parsed = parse_detail_selection((string)$detailValue);
                $riskCode = trim((string)($savedReport['detail_code_map'][$detailValue] ?? ''));
                $summaryKey = match ($parsed['type']) {
                    'major_work', 'major_work_sub' => 'major_work',
                    'tool' => 'tool',
                    'env' => 'env',
                    default => '',
                };

                if ($riskCode === '') {
                    $riskCode = resolve_detail_lookup_code(
                        $unitCodeLookup,
                        (string)$parsed['type'],
                        (string)$parsed['title'],
                        (string)$parsed['parent']
                    );
                }

                if ($riskCode === '') {
                    continue;
                }

                if ($summaryKey !== '' && !in_array($riskCode, $savedReport['detail_code_summary'][$summaryKey], true)) {
                    $savedReport['detail_code_summary'][$summaryKey][] = $riskCode;
                }

                $displayTitle = '';
                if ($parsed['type'] === 'major_work_sub' && $parsed['parent'] !== '' && $parsed['title'] !== '') {
                    $displayTitle = $parsed['parent'] . ' - ' . $parsed['title'];
                } elseif ($parsed['type'] === 'major_work' && $parsed['title'] !== '') {
                    if (!empty($savedReport['detail_grouped']['major_work_sub_map'][$parsed['title']])) {
                        continue;
                    }
                    $displayTitle = $parsed['title'];
                } elseif (in_array($parsed['type'], ['tool', 'env'], true) && $parsed['title'] !== '') {
                    $displayTitle = $parsed['title'];
                }

                if ($summaryKey !== '' && $displayTitle !== '') {
                    $displayText = $displayTitle . '(' . $riskCode . ')';
                    if (!in_array($displayText, $detailDisplayBuckets[$summaryKey], true)) {
                        $detailDisplayBuckets[$summaryKey][] = $displayText;
                    }
                }
            }
                $savedReport['detail_code_display'] = array_merge(
                    $detailDisplayBuckets['tool'],
                    $detailDisplayBuckets['env'],
                    $detailDisplayBuckets['major_work']
                );

            if ($isWorker && empty($savedReport['detail_tasks'])) {
                // 팀에 작업지휘자가 없으면 작업자가 직접 열 수 있음
                $workerTeamName = auth_normalize_team_name((string)($user['team'] ?? ''));
                $workerTeamHasLeader = !empty(auth_team_members($workerTeamName, ['leader']));
                if ($workerTeamHasLeader) {
                    header('Location: work_list.php');
                    exit;
                }
            }

            if ($savedReportId > 0 && $requestMethod !== 'POST') {
                $selectedUnitRaIds = normalize_unit_ra_id_values($savedReport['selected_unit_ra_ids'] ?? []);
                if (empty($selectedUnitRaIds) && (int)$savedReport['unit_ra_id'] > 0) {
                    $selectedUnitRaIds[] = (int)$savedReport['unit_ra_id'];
                }
                $selectedUnitRaId = !empty($selectedUnitRaIds)
                    ? (int)$selectedUnitRaIds[0]
                    : (int)$savedReport['unit_ra_id'];
                $formDefaults['work_title'] = (string)($savedReport['work_title'] ?? '');
                $formDefaults['work_date'] = (string)($savedReport['work_date'] ?? date('Y-m-d'));
                $formDefaults['work_place'] = (string)($savedReport['work_place'] ?? '');
                $formDefaults['use_equipment_yn'] = (string)($savedReport['use_equipment_yn'] ?? 'N');
                $formDefaults['selected_tools'] = [];
                foreach ($tools as $tool) {
                    if (in_array($tool['tool_name'], $savedReport['tools'] ?? [], true)) {
                        $formDefaults['selected_tools'][] = (int)$tool['tool_id'];
                    }
                }
                $formDefaults['note_html'] = (string)($savedReport['note_html'] ?? '');
                $formDefaults['pasted_images'] = array_values(array_filter(array_map(
                    static fn($image) => is_array($image) ? trim((string)($image['file_path'] ?? '')) : '',
                    $savedReport['images'] ?? []
                )));
                $formDefaults['detail_tasks'] = $savedReport['detail_tasks'] ?? [];
                $formDefaults['detail_code_map'] = $savedReport['detail_code_map'] ?? [];
            }
        }
    }
}

if (!empty($taskMapById)) {
    $selectedUnitRaIds = array_values(array_filter(
        normalize_unit_ra_id_values($selectedUnitRaIds),
        static fn($unitRaId) => isset($taskMapById[(int)$unitRaId])
    ));
}
if (empty($selectedUnitRaIds) && $selectedUnitRaId > 0) {
    $selectedUnitRaIds = [$selectedUnitRaId];
}
if (!empty($selectedUnitRaIds)) {
    $selectedUnitRaId = (int)$selectedUnitRaIds[0];
}

$detailLabels = [
    'major_work' => '중대위험작업',
    'tool' => '공구/장비',
    'env' => '작업환경',
];
$detailFormState = [
    'use_equipment_yn' => $formDefaults['use_equipment_yn'],
    'detail_tasks' => $formDefaults['detail_tasks'],
    'detail_code_map' => $formDefaults['detail_code_map'],
    'detail_grouped' => build_detail_grouped_state($formDefaults['detail_tasks'], $formDefaults['detail_code_map']),
    'tools' => resolve_selected_tool_names($formDefaults['selected_tools'], $tools),
];

$roleDescriptions = [
    'admin' => [
        'title' => '운영자 작업 선택',
        'description' => '관리감독자와 작업지휘자 기능을 모두 사용할 수 있습니다.',
        'button' => '운영자로 시작',
    ],
    'manager' => [
        'title' => '관리감독자 작업 선택',
        'description' => '공정명, 대분류, 작업유형을 순서대로 선택하면 하위 위험평가가 자동으로 연결됩니다.',
        'button' => '관리감독자로 시작',
    ],
    'leader' => [
        'title' => '작업지휘자 작업 선택',
        'description' => '작업반장과 현장 작업에 맞는 기초 위험성평가를 고른 뒤 등록으로 이어갈 수 있습니다.',
        'button' => '작업지휘자로 시작',
    ],
    'worker' => [
        'title' => '작업 선택',
        'description' => '저장된 작업을 확인하고, 오늘 작업에 필요한 위험성평가를 진행할 수 있습니다.',
        'button' => '작업자로 시작',
    ],
];

$roleMeta = $user ? ($roleDescriptions[$userRole] ?? null) : null;
function type_label(string $type): string
{
    return match ($type) {
        'major_work' => '중대위험작업',
        'target' => '작업대상',
        'tool' => '공구/장비',
        'env' => '작업환경',
        default => $type,
    };
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>작업 선택</title>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
<link rel="stylesheet" href="assets/toastui/toastui-editor.min.css">
<link rel="stylesheet" href="assets/toastui-image-editor/tui-image-editor.css">
<script src="assets/toastui/toastui-editor-all.min.js"></script>
<script src="assets/toastui/i18n/ko-kr.js"></script>
<script src="assets/toastui-image-editor/tui-image-editor.min.js"></script>
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
    padding: 32px 20px 48px;
  }
  .shell {
    max-width: 1180px;
    margin: 0 auto;
  }
  .hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 22px;
  }
  .hero h1 {
    font-size: 28px;
    font-weight: 900;
    color: var(--text-hi);
    margin-bottom: 8px;
  }
  .hero h1 span { color: var(--accent2); }
  .hero p {
    color: var(--text-dim);
    line-height: 1.7;
    font-size: 14px;
  }
  .panel {
    background: var(--bg2) !important;
    border: 1px solid var(--border) !important;
    border-radius: 18px !important;
    box-shadow: none !important;
    overflow: hidden;
  }
  .login-wrap {
    max-width: 480px;
    margin: 40px auto 0;
  }
  .login-head {
    background: var(--bg3);
    color: var(--text-hi);
    padding: 22px 24px;
    border-bottom: 1px solid var(--border);
  }
  .login-head h2 {
    font-size: 22px;
    margin-bottom: 8px;
    color: var(--text-hi);
  }
  .login-head p { color: var(--text-dim); font-size: 13px; }
  .login-body {
    padding: 24px;
    background: var(--bg2);
  }
  .field {
    margin-bottom: 16px;
  }
  label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: bold;
    color: var(--text);
  }
  input[type="text"],
  input[type="password"] {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border2);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    background: var(--bg3);
    color: var(--text-hi);
  }
  input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(232,146,10,0.18);
  }
  .btn-primary,
  .btn-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 10px;
    cursor: pointer;
    padding: 11px 18px;
    font-size: 14px;
    font-family: inherit;
    border: none;
  }
  .btn-primary {
    background: var(--accent) !important;
    color: #fff !important;
    font-weight: bold;
    border: none !important;
  }
  .btn-primary:hover { background: var(--accent2) !important; }
  .btn-secondary {
    background: rgba(255,255,255,0.06) !important;
    color: var(--text) !important;
    border: 1px solid var(--border2) !important;
  }
  .btn-ra {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 10px;
    cursor: pointer;
    padding: 11px 18px;
    font-size: 14px;
    font-family: inherit;
    background: var(--accent);
    color: #fff;
    border: none;
    font-weight: bold;
    white-space: nowrap;
  }
  .btn-ra:hover { background: var(--accent2); }
  .btn-secondary:hover { background: rgba(255,255,255,0.10) !important; }
  .error {
    margin-bottom: 16px;
    padding: 12px 14px;
    background: rgba(200,50,50,0.12);
    color: #f08080;
    border-left: 4px solid #c04040;
    border-radius: 10px;
    font-size: 13px;
  }
  .success {
    margin-bottom: 16px;
    padding: 12px 14px;
    background: rgba(30,120,60,0.15);
    color: #6fcf97;
    border-left: 4px solid #2d9d57;
    border-radius: 10px;
    font-size: 13px;
  }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }
  .identity {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .team-context-bar {
    margin-bottom: 18px;
    padding: 16px 18px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: linear-gradient(180deg, rgba(25, 39, 60, 0.92), rgba(18, 30, 48, 0.92));
  }
  .team-context-label {
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 6px;
  }
  .team-context-text {
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 700;
    line-height: 1.5;
  }
  .team-context-note {
    margin-top: 6px;
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.5;
  }
  .team-context-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 14px;
  }
  .btn-team-active {
    background: rgba(58,127,193,0.22) !important;
    border-color: rgba(58,127,193,0.55) !important;
    color: var(--text-hi) !important;
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
  .toolbar {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 18px;
  }
  .selector-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 18px;
  }
  .selector-box {
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--bg3);
  }
  .selector-box h2 {
    font-size: 15px;
    color: var(--text-hi);
    margin-bottom: 10px;
  }
  .selector-box p {
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.6;
    margin-bottom: 12px;
  }
  .selector-box select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border2);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    background: var(--bg2);
    color: var(--text-hi);
  }
  .selector-box select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(232,146,10,0.18);
  }
  .selector-box select:disabled {
    background: rgba(255,255,255,0.03);
    color: var(--text-dim);
  }
  .selector-add-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
  }
  .selector-add-row span {
    color: var(--text-dim);
    font-size: 12px;
  }
  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
  }
  .chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(255,255,255,0.05);
    color: var(--text-dim);
    border: 1px solid var(--border2);
    font-size: 12px;
  }
  .task-info {
    font-size: 13px;
    color: var(--text-dim);
    line-height: 1.7;
    margin-bottom: 16px;
  }
  .task-inline-info {
    font-size: 16px;
    color: var(--text-hi);
    line-height: 1.8;
    font-weight: 600;
  }
  .selected-task {
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--bg3);
  }
  .selected-task h2 {
    font-size: 18px;
    color: var(--text-hi);
    margin-bottom: 10px;
  }
  .selected-task-detail-block {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
  }
  .selected-task-detail-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dim);
    margin-bottom: 10px;
  }
  .selected-task-detail-lines {
    display: grid;
    gap: 8px;
  }
  .selected-task-detail-line {
    font-size: 15px;
    line-height: 1.7;
  }
  .selected-task-detail-line.is-removable {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: rgba(255,255,255,0.02);
  }
  .selected-task-detail-line-text {
    flex: 1;
    min-width: 0;
  }
  .selected-task-remove-btn {
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid rgba(240,120,120,0.45);
    background: rgba(180,60,60,0.2);
    color: #ffd0d0;
    font-size: 12px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    white-space: nowrap;
  }
  .selected-task-remove-btn:hover {
    background: rgba(200,70,70,0.35);
  }
  .selected-task-detail-empty {
    font-size: 13px;
    color: var(--text-dim);
  }
  .selected-task .task-code {
    color: var(--accent2);
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 10px;
  }
  .selected-task .task-title {
    font-size: 20px;
    font-weight: bold;
    line-height: 1.5;
    margin-bottom: 12px;
    color: var(--text-hi);
  }
  .selected-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
  }
  .entry-card,
  .summary-card {
    margin-top: 18px;
    padding: 24px 28px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--bg3);
  }
  .entry-card > h2,
  .summary-card > h2 {
    font-size: 16px;
    font-weight: 700;
    color: var(--accent2);
    background: rgba(232,146,10,0.10);
    border-bottom: 1px solid rgba(232,146,10,0.22);
    margin: -24px -28px 20px;
    padding: 12px 20px;
    border-radius: 16px 16px 0 0;
    letter-spacing: 0.3px;
  }
  .entry-card div > h2 {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dim);
    background: none;
    border: none;
    border-left: 3px solid var(--accent);
    margin: 0 0 12px;
    padding: 0 0 0 10px;
    border-radius: 0;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }
  .summary-section-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--accent2);
    border-left: 4px solid var(--accent);
    padding-left: 10px;
    margin: 20px 0 12px;
  }
  .summary-section-title:first-of-type { margin-top: 0; }
  .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }
  .form-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
  }
  .form-field.full {
    grid-column: 1 / -1;
  }
  input[type="date"],
  .selector-box select,
  .form-field input[type="text"] {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border2);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    background: var(--bg3);
    color: var(--text-hi);
  }
  input[type="date"] {
    color-scheme: dark;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 18px 18px;
    padding-right: 42px;
  }
  input[type="date"] {
    cursor: pointer;
  }
  input[type="date"]::-webkit-calendar-picker-indicator {
    opacity: 0;
    pointer-events: none;
    width: 0;
    height: 0;
  }
  input[type="date"]:focus,
  .form-field input[type="text"]:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(232,146,10,0.18);
  }
  .list-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 10px;
    margin-top: 12px;
  }
  .check-item {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--bg3);
    transition: background .15s;
  }
  .check-item:hover { background: rgba(255,255,255,0.04); }
  .check-item label {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 12px 14px;
    cursor: pointer;
    color: var(--text);
    font-weight: normal;
    line-height: 1.6;
    margin-bottom: 0;
  }
  .check-item-body {
    flex: 1;
  }
  .detail-code-input {
    display: none;
  }
  .note-editor-shell {
    border: 0;
    border-radius: 16px;
    background: transparent;
    padding: 0;
  }
  .note-editor-source {
    display: none;
  }
  .note-editor-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 6px;
  }
  .note-editor-scroll::-webkit-scrollbar {
    height: 12px;
  }
  .note-editor-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.44);
    border-radius: 999px;
    border: 3px solid transparent;
    background-clip: padding-box;
  }
  .note-editor-scroll::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.06);
    border-radius: 999px;
  }
  .note-toolbar {
    display: grid;
    gap: 10px;
    margin-bottom: 14px;
  }
  .note-toolbar-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .note-toolbar button {
    padding: 9px 12px;
    border-radius: 10px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.05);
    color: var(--text);
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
  }
  .note-toolbar button:hover {
    background: rgba(255,255,255,0.10);
  }
  .note-toolbar button.is-accent {
    border-color: rgba(245,166,35,0.45);
    background: rgba(245,166,35,0.12);
    color: #ffe1ad;
  }
  .note-toolbar button.note-icon-button {
    width: 44px;
    height: 44px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .note-toolbar button.note-icon-button.note-icon-button-wide {
    width: auto;
    min-width: 44px;
    padding: 0 12px;
    gap: 8px;
  }
  .note-toolbar button.note-icon-button .note-icon {
    width: 20px;
    height: 20px;
    display: inline-block;
    line-height: 0;
  }
  .note-toolbar button.note-icon-button .note-icon svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
    fill: none;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
  }
  .note-toolbar button.note-icon-button .note-icon svg.fill-current {
    fill: currentColor;
    stroke: none;
  }
  .note-toolbar button.note-icon-button .note-short-label {
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0.01em;
  }
  .note-visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }
  .note-render {
    color: var(--text-hi);
    line-height: 1.8;
    min-width: 0;
    max-width: 100%;
  }
  .smarteditor-textarea {
    width: 100%;
    min-width: 0;
    height: 420px;
    font-family: inherit;
    font-size: 15px;
    line-height: 1.7;
    resize: vertical;
  }
  .note-editor-host {
    min-width: 0;
  }
  .note-editor-scroll .toastui-editor-defaultUI {
    border: 1px solid #d4dce7;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 18px 36px rgba(3, 8, 16, 0.18);
    background: #ffffff;
  }
  .note-editor-scroll .toastui-editor-toolbar {
    background: #ffffff;
    border-bottom-color: #d4dce7;
  }
  .note-editor-scroll .toastui-editor-main,
  .note-editor-scroll .toastui-editor-ww-container {
    background: #ffffff;
  }
  .note-editor-scroll .toastui-editor-contents,
  .note-editor-scroll .toastui-editor-ww-container .ProseMirror {
    font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
    font-size: 15px;
    line-height: 1.8;
    color: #111827;
    word-break: keep-all;
  }
  .note-editor-scroll .toastui-editor-ww-container .ProseMirror.note-editor-empty {
    position: relative;
  }
  .note-editor-scroll .toastui-editor-ww-container .ProseMirror.note-editor-empty::before {
    content: attr(data-note-placeholder);
    position: absolute;
    top: 0;
    left: 0;
    color: #9ca3af;
    pointer-events: none;
  }
  .note-editor-scroll .toastui-editor-contents figure.note-media,
  .note-editor-scroll .toastui-editor-contents img.note-inline-image {
    cursor: pointer;
  }
  .note-editor-scroll .toastui-editor-contents figure.note-media.is-selected {
    border-color: #f59e0b !important;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.22);
    transform: translateY(-1px);
    transition: box-shadow 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
  }
  .note-editor-scroll .toastui-editor-contents img.note-inline-image.is-selected {
    outline: 3px solid rgba(245, 158, 11, 0.55);
    outline-offset: 4px;
  }
  .note-prose h3 {
    font-size: 24px;
    line-height: 1.45;
    color: #fff;
    margin: 6px 0 16px;
  }
  .note-prose h4 {
    font-size: 18px;
    line-height: 1.55;
    color: #ffd797;
    margin: 24px 0 12px;
  }
  .note-prose p {
    margin: 0 0 14px;
    word-break: keep-all;
  }
  .note-prose ul,
  .note-prose ol {
    margin: 0 0 18px;
    padding-left: 22px;
  }
  .note-prose table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
  }
  .note-prose th,
  .note-prose td {
    border: 1px solid var(--border2);
    padding: 10px 12px;
    vertical-align: top;
  }
  .note-prose th {
    background: rgba(255,255,255,0.06);
    color: var(--text-hi);
    font-weight: 700;
  }
  .note-prose li + li {
    margin-top: 6px;
  }
  .note-prose blockquote {
    margin: 18px 0;
    padding: 14px 16px;
    border-left: 4px solid var(--accent);
    border-radius: 0 12px 12px 0;
    background: rgba(232,146,10,0.10);
    color: var(--text-hi);
  }
  .note-prose hr {
    border: none;
    height: 1px;
    margin: 24px 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.22) 18%, rgba(255,255,255,0.22) 82%, transparent 100%);
  }
  .note-prose a {
    color: #9ed0ff;
    text-decoration: underline;
  }
  .note-prose figure.note-media {
    margin: 22px 0;
    padding: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow: visible;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.04);
  }
  .note-prose figure.note-media[data-note-image-align="left"] {
    align-items: flex-start;
  }
  .note-prose figure.note-media[data-note-image-align="right"] {
    align-items: flex-end;
  }
  .note-prose figure.note-media img,
  .note-prose .note-inline-image {
    width: auto;
    max-width: 100% !important;
    height: auto !important;
    box-sizing: border-box;
    border-radius: 12px;
    border: 1px solid var(--border2);
    display: block;
    margin: 0 auto;
    background: var(--bg2);
  }
  .note-prose figure.note-media figcaption {
    margin-top: 10px;
    font-size: 13px;
    color: var(--text-dim);
    text-align: center;
    line-height: 1.6;
  }
  .note-prose figure.note-media[data-note-image-align="left"] figcaption {
    text-align: left;
  }
  .note-prose figure.note-media[data-note-image-align="right"] figcaption {
    text-align: right;
  }
  .note-render > :last-child {
    margin-bottom: 0;
  }
  .note-help {
    margin-top: 10px;
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.6;
  }
  .paste-preview,
  .saved-images {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    margin-top: 14px;
  }
  .paste-preview figure {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 10px;
    margin: 0;
  }
  .paste-preview figure.is-selected {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(232,146,10,0.2);
  }
  .paste-preview img,
  .saved-images img {
    width: 100%;
    height: 180px;
    object-fit: contain;
    border-radius: 8px;
    display: block;
    border: 1px solid var(--border2);
    background: var(--bg2);
  }
  .paste-preview figcaption {
    margin-top: 8px;
    font-size: 12px;
    color: var(--text-dim);
  }
  .summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px 14px;
  }
  .summary-box {
    padding: 12px 14px;
    min-width: 0;
    overflow: visible;
    border-radius: 10px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
  }
  .summary-box.span2 { grid-column: span 2; }
  .summary-box.span3 { grid-column: span 3; }
  .summary-box strong {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #8aadcc;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  .summary-box .val {
    font-size: 14px;
    color: var(--text-hi);
    font-weight: 500;
    word-break: break-all;
  }
  .summary-risk-line {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    column-gap: 10px;
    row-gap: 4px;
    word-break: keep-all;
    line-height: 1.7;
  }
  .summary-risk-item {
    display: inline-block;
  }
  .summary-code-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 4px;
  }
  .summary-code-group {
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
    min-width: 0;
  }
  .summary-code-label {
    font-size: 12px;
    font-weight: 700;
    color: #8aadcc;
    margin-bottom: 8px;
  }
  .summary-code-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .summary-code-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    background: rgba(232,146,10,0.15);
    color: var(--accent2);
    border: 1px solid rgba(232,146,10,0.3);
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
  }
  .summary-code-empty {
    color: var(--text-dim);
    font-size: 13px;
  }
  .image-actions {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
  }
  .image-actions button {
    flex: 1 1 90px;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.05);
    color: var(--text);
    cursor: pointer;
    font-size: 12px;
    font-family: inherit;
  }
  .image-actions button:hover {
    background: rgba(255,255,255,0.10);
  }
  .detail-task-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 10px;
    margin-top: 14px;
  }
  .detail-group {
    margin-top: 18px;
  }
  .detail-group h3 {
    font-size: 15px;
    color: var(--text-hi);
    margin-bottom: 10px;
  }
  .detail-subgroup {
    margin-top: 12px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--bg3);
  }
  .detail-subgroup.is-hidden {
    display: none;
  }
  .detail-subgroup h4 {
    font-size: 13px;
    color: var(--text-dim);
    margin-bottom: 10px;
  }
  .detail-task-list {
    margin-top: 0;
  }
  .detail-group-block {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 10px;
  }
  .detail-group-block:last-child { margin-bottom: 0; }
  .detail-group-label {
    font-size: 11px;
    font-weight: 700;
    color: #8aadcc;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 10px;
  }
  .detail-task-list h3 {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-hi);
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .detail-task-list h3::before {
    content: '';
    display: inline-block;
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
  }
  .detail-task-list ul {
    list-style: none;
    margin-left: 12px;
  }
  .detail-task-list li {
    padding: 5px 0 5px 12px;
    border-bottom: 1px dashed var(--border2);
    font-size: 13px;
    color: var(--text);
    position: relative;
  }
  .detail-task-list li::before {
    content: '-';
    position: absolute;
    left: 0;
    color: var(--text-dim);
  }
  .detail-task-list li:last-child { border-bottom: none; }
  .risk-code-badge {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 7px;
    background: rgba(232,146,10,0.15);
    color: var(--accent2);
    border-radius: 99px;
    font-size: 11px;
    font-weight: 700;
  }
  .summary-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 14px;
    flex-wrap: wrap;
  }
  .inline-form {
    margin: 0;
  }
  .page-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 18px;
    flex-wrap: wrap;
  }
  .is-hidden {
    display: none;
  }
  .empty {
    padding: 50px 20px;
    text-align: center;
    color: var(--text-dim);
    font-size: 14px;
    border: 1px dashed var(--border2);
    border-radius: 16px;
    background: var(--bg3);
  }
  .image-editor-modal {
    position: fixed;
    inset: 0;
    background: rgba(4,10,18,0.80);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
  }
  .image-editor-modal.is-open {
    display: flex;
  }
  .image-editor-panel {
    width: min(1180px, 100%);
    max-height: 92vh;
    background: var(--bg2);
    border: 1px solid var(--border2);
    border-radius: 18px;
    padding: 18px 18px 20px;
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.50);
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .image-editor-headerbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
  }
  .image-editor-title {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-hi);
  }
  .image-editor-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .image-editor-actions .btn-warning {
    background: rgba(255, 188, 66, 0.16);
    border: 1px solid rgba(255, 188, 66, 0.35);
    color: #ffd98b;
  }
  .image-editor-actions .btn-warning:hover {
    background: rgba(255, 188, 66, 0.24);
  }
  .image-editor-host {
    width: 100%;
    min-height: min(72vh, 820px);
    height: min(72vh, 820px);
    border: 1px solid var(--border2);
    border-radius: 14px;
    background: var(--bg3);
    overflow: hidden;
  }
  .image-editor-tools {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 14px;
    border: 1px solid var(--border2);
    border-radius: 14px;
    background: var(--bg3);
  }
  .image-editor-tools label {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-dim);
  }
  .image-editor-tools select,
  .image-editor-tools input[type="range"] {
    min-height: 36px;
  }
  .image-editor-canvas-wrap {
    width: 100%;
    min-height: min(72vh, 820px);
    max-height: min(72vh, 820px);
    overflow: auto;
    border: 1px solid var(--border2);
    border-radius: 14px;
    background: #101828;
    padding: 14px;
  }
  #image-editor-canvas {
    display: block;
    margin: 0 auto;
    background: #ffffff;
    box-shadow: 0 10px 32px rgba(0, 0, 0, 0.28);
    cursor: crosshair;
  }
  .advanced-image-editor-modal {
    z-index: 1015;
  }
  .advanced-image-editor-panel {
    width: min(1320px, 100%);
  }
  .advanced-image-editor-host {
    width: 100%;
    min-height: min(74vh, 860px);
    height: min(74vh, 860px);
    border: 1px solid var(--border2);
    border-radius: 14px;
    background: var(--bg3);
    overflow: hidden;
  }
  .advanced-canvas-editor {
    display: flex;
    flex-direction: column;
    gap: 12px;
    height: 100%;
    padding: 14px;
  }
  .advanced-canvas-toolbar {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .advanced-canvas-toolbar button {
    min-height: 36px;
  }
  .advanced-canvas-tip {
    font-size: 12px;
    color: var(--text-dim);
  }
  .advanced-canvas-wrap {
    flex: 1;
    min-height: 0;
    overflow: auto;
    border: 1px solid var(--border2);
    border-radius: 12px;
    background: #101828;
    padding: 14px;
  }
  #advanced-image-editor-canvas {
    display: block;
    margin: 0 auto;
    background: #ffffff;
    box-shadow: 0 10px 32px rgba(0, 0, 0, 0.28);
    cursor: crosshair;
  }
  #advanced-image-editor-root .tui-image-editor-container,
  #image-editor-root .tui-image-editor-container {
    min-height: 100% !important;
    height: 100% !important;
    border-radius: 14px;
    overflow: hidden;
  }
  #advanced-image-editor-root .tui-image-editor-header,
  #image-editor-root .tui-image-editor-header {
    display: none !important;
  }
  #advanced-image-editor-root .tui-image-editor-main-container,
  #image-editor-root .tui-image-editor-main-container {
    top: 0 !important;
  }
  #advanced-image-editor-root .tui-image-editor-main,
  #image-editor-root .tui-image-editor-main {
    top: 0 !important;
  }
  #advanced-image-editor-root .tui-image-editor-menu,
  #image-editor-root .tui-image-editor-menu {
    font-family: "Malgun Gothic", "맑은 고딕", sans-serif;
  }
  #advanced-image-editor-root .tui-image-editor-submenu .tie-btn-reset,
  #image-editor-root .tui-image-editor-submenu .tie-btn-reset {
    display: none !important;
  }
  @media (max-width: 720px) {
    .hero h1 { font-size: 24px; }
    .selector-grid { grid-template-columns: 1fr; }
    .form-grid,
    .summary-grid { grid-template-columns: 1fr; }
    .summary-code-grid { grid-template-columns: 1fr; }
  }
  @media print {
    body {
      background: #fff;
      padding: 0;
    }
    .hero,
    .topbar,
    .success,
    .error,
    #work-report-form,
    #show-entry-form,
    .summary-actions {
      display: none !important;
    }
    .panel,
    .summary-card,
    .summary-box {
      box-shadow: none;
      border-color: #c8d8e8;
    }
    .panel {
      border: none;
      padding: 0;
    }
    .printable-report {
      margin-top: 0;
    }
  }
</style>
</head>
<body>
  <div class="shell">
    <?php if ($user === null): ?>
      <div class="login-wrap">
        <div class="panel">
          <div class="login-head">
            <h2>위험성평가 로그인</h2>
          </div>
          <div class="login-body">
            <?php if ($error !== ''): ?>
              <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="action" value="login">
              <div class="field">
                <label for="login_id">로그인 ID</label>
                <input type="text" id="login_id" name="login_id" placeholder="예: manager01" required>
              </div>
              <div class="field">
                <label for="password">비밀번호</label>
                <input type="password" id="password" name="password" placeholder="비밀번호 입력" required>
              </div>
              <button class="btn-primary" type="submit" style="width:100%;">로그인</button>
            </form>
            <?php if ($registrationOpen): ?>
              <div class="page-actions" style="margin-top:10px; justify-content:center;">
                <a class="btn-secondary" href="register_worker.php">회원가입</a>
              </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="topbar">
        <div class="identity">
          <span style="color:var(--text-hi);font-size:14px;font-weight:700"><?= h(auth_display_name($user)) ?></span>
        </div>
        <div class="identity">
          <a class="btn-secondary" href="work_list.php">작업목록</a>
          <?php if ($isAdmin): ?>
            <a class="btn-secondary" href="register_worker.php">계정관리</a>
          <?php endif; ?>
          <?php
            $currentTeam = auth_normalize_team_name((string)($user['team'] ?? ''));
            $activeGasTeam = auth_team_key($currentTeam) === auth_team_key('가스팀')
                || auth_team_key((string)($selectedManagerTeam ?? '')) === auth_team_key('가스팀');
            $isElectricalManager = auth_can_manage($user) && auth_team_key($currentTeam) === auth_team_key('공사팀-전기');
            $isOperatorViewGasSchedule = auth_can_manage($user) && (
                auth_is_admin($user)
                || (string)($user['role'] ?? '') === 'safety_manager'
            );
          ?>
          <?php if ($activeGasTeam && auth_can_manage($user)): ?>
            <a class="btn-secondary" href="schedule.php">근무일정표</a>
          <?php endif; ?>
          <?php if ($isElectricalManager || $isOperatorViewGasSchedule): ?>
            <a class="btn-secondary" href="schedule.php?view_team=가스팀">가스팀근무표</a>
          <?php endif; ?>
          <a class="btn-secondary" href="<?= h($boardPageUrl) ?>">게시판</a>
          <a class="btn-secondary" href="../calendar/index.html">달력</a>
          <a class="btn-secondary" href="hazard_review.php">위험성평가목록</a>
          <a class="btn-secondary" href="<?= h($selfPage) ?>?logout=1">로그아웃</a>
        </div>
      </div>

      <?php if ($savedRaId > 0): ?>
        <div class="success">위험성평가 등록이 완료되었습니다. 저장된 문서 번호는 RA_ID <?= (int)$savedRaId ?> 입니다.</div>
      <?php endif; ?>

      <?php if (!$isLeaderPage && count($managerShortcutTeams) > 1): ?>
        <div class="team-context-bar">
          <div class="team-context-label"><?= $isAdmin ? '운영자 팀 컨텍스트' : '관리감독팀 작업팀 선택' ?></div>
          <div class="team-context-text">
            <?= $selectedManagerTeam !== '' ? h($selectedManagerTeam) . ' 기준으로 관리등록 중입니다.' : '아래 버튼을 눌러 해당 팀 기준으로 관리등록을 시작할 수 있습니다.' ?>
          </div>
          <div class="team-context-note">
            <?= $isAdmin ? '운영자는 팀별 버튼으로 관리등록 화면을 바로 전환할 수 있고, 저장한 작업도 같은 팀 문맥으로 이어집니다.' : '관리감독자는 자신의 팀 또는 관리감독을 받는 작업팀 중 하나를 선택하여 작업지휘자를 결정할 수 있습니다.' ?>
          </div>
          <div class="team-context-actions">
            <?php foreach ($managerShortcutTeams as $teamName): ?>
              <?php $isActiveManagerTeam = $selectedManagerTeam === $teamName; ?>
              <a class="btn-secondary<?= $isActiveManagerTeam ? ' btn-team-active' : '' ?>" href="<?= h(build_page_url($selfPage, ['manager_team' => $teamName])) ?>"><?= h($teamName) ?> 관리등록</a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="panel" style="padding: 20px;">
        <?php if (!empty($formErrors)): ?>
          <div class="error">
            <?php foreach ($formErrors as $formError): ?>
              <div><?= h($formError) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($savedReport): ?>
          <div class="success">저장이 완료되었습니다. 아래에서 저장한 내용을 바로 확인할 수 있습니다.</div>
          <div class="summary-card printable-report" id="saved-report-summary">
            <h2><?= $pageRole === 'leader' ? '작업 요약' : '저장된 내용' ?></h2>

            <div class="summary-section-title">기본정보</div>
            <div class="summary-grid">
              <div class="summary-box span2">
                <strong>작업명</strong>
                <div class="val"><?= h($savedReport['work_title']) ?></div>
              </div>
              <div class="summary-box">
                <strong>작업일자</strong>
                <div class="val"><?= h($savedReport['work_date']) ?></div>
              </div>
              <div class="summary-box span2">
                <strong>작업장소</strong>
                <div class="val"><?= h($savedReport['work_place']) ?></div>
              </div>
              <div class="summary-box span3">
                <strong>위험성평가(코드번호)</strong>
                <div class="val summary-risk-line">
                  <?php if (!empty($savedReport['selected_units']) && is_array($savedReport['selected_units'])): ?>
                    <?php foreach ($savedReport['selected_units'] as $selectedUnit): ?>
                      <span class="summary-risk-item">
                        <?= h((string)($selectedUnit['unit_title'] ?? '-')) ?>
                        <?php if (!empty($selectedUnit['unit_code'])): ?>
                          <?= ' (' . h((string)$selectedUnit['unit_code']) . ')' ?>
                        <?php endif; ?>
                      </span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="summary-risk-item">
                      <?= h($savedReport['selected_unit_title'] ?: '-') ?>
                      <?php if (!empty($savedReport['selected_unit_code'])): ?>
                        <?= ' (' . h($savedReport['selected_unit_code']) . ')' ?>
                      <?php endif; ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($savedReport['detail_code_display'])): ?>
                    <?php foreach ($savedReport['detail_code_display'] as $detailCodeDisplay): ?>
                      <span class="summary-risk-item"><?= h($detailCodeDisplay) ?></span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="summary-section-title">작업 유의사항</div>
            <div class="summary-box span3" style="grid-column:unset;">
              <?php if (!empty($savedReport['note_html'])): ?>
                <div class="note-render note-prose"><?= $savedReport['note_html'] ?></div>
              <?php else: ?>
                <span style="color:#9ab0c8; font-size:13px;">입력된 내용이 없습니다.</span>
              <?php endif; ?>
            </div>

            <?php if ($pageRole !== 'leader'): ?>
              <div class="summary-actions" style="margin-top:20px;">
                <?php if ($isWorker): ?>
                  <a class="btn-ra" href="hazard_survey.php?report_id=<?= (int)$savedReport['report_id'] ?>">위험성평가하기</a>
                <?php else: ?>
                  <a
                    href="<?= h(build_page_url($selfPage, array_merge($managerContextParams, [
                      'unit_ra_id' => (int)$savedReport['unit_ra_id'],
                      'saved_report_id' => (int)$savedReport['report_id'],
                      'edit_report_id' => (int)$savedReport['report_id'],
                    ]))) ?>"
                    class="btn-secondary"
                  >수정</a>
                  <form method="post" class="inline-form" onsubmit="return confirm('저장한 내용을 삭제하시겠습니까?');">
                    <input type="hidden" name="action" value="delete_report">
                    <input type="hidden" name="report_id" value="<?= (int)$savedReport['report_id'] ?>">
                    <button type="submit" class="btn-secondary">삭제</button>
                  </form>
                  <button type="button" class="btn-secondary" id="print-saved-report">인쇄</button>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if (!$isWorker): ?>
            <div class="page-actions">
              <button
                type="button"
                class="btn-secondary"
                id="show-entry-form"
                data-edit-url="<?= h(build_page_url($selfPage, array_merge($managerContextParams, [
                  'add_mode' => '1',
                ]))) ?>"
              ><?= $leaderElectricalDetailOnlyMode ? '입력' : '작업 추가' ?></button>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($tasks)): ?>
          <div class="empty">선택 가능한 작업이 아직 없습니다. 먼저 단위 위험성평가서를 등록하거나 업로드해 주세요.</div>
        <?php else: ?>
          <form method="post" class="entry-card <?= ($savedReport && empty($formErrors) && $editingReportId <= 0) ? 'is-hidden' : '' ?>" id="work-report-form">
            <input type="hidden" name="action" value="save_report">
            <input type="hidden" name="report_id" value="<?= (int)$editingReportId ?>">
            <input type="hidden" name="unit_ra_id" id="unit_ra_id" value="<?= (int)$selectedUnitRaId ?>">
            <input type="hidden" name="selected_unit_ra_ids" id="selected_unit_ra_ids" value="<?= h(json_encode($selectedUnitRaIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
            <input type="hidden" name="pasted_images" id="pasted_images" value="<?= h(json_encode($formDefaults['pasted_images'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">

            <?php if ($leaderElectricalDetailOnlyMode): ?>
              <input type="hidden" name="work_title" value="<?= h($formDefaults['work_title']) ?>">
              <input type="hidden" name="work_place" value="<?= h($formDefaults['work_place']) ?>">
              <input type="hidden" name="work_date" value="<?= h($formDefaults['work_date']) ?>">
              <input type="hidden" name="use_equipment_yn" value="<?= h($formDefaults['use_equipment_yn']) ?>">
              <?php foreach ($formDefaults['selected_tools'] as $selectedToolId): ?>
                <input type="hidden" name="selected_tools[]" value="<?= (int)$selectedToolId ?>">
              <?php endforeach; ?>
              <textarea name="note_html" id="note_html" class="note-editor-source" hidden><?= h($formDefaults['note_html']) ?></textarea>
              <h2><?= $editingReportId > 0 ? '세부사항 입력 수정' : '세부사항 입력' ?></h2>
            <?php else: ?>
              <h2><?= $editingReportId > 0 ? '입력 화면 수정' : '입력 화면' ?></h2>
              <div class="form-grid">
                <div class="form-field full">
                  <label for="work_title">작업명</label>
                  <input type="text" id="work_title" name="work_title" value="<?= h($formDefaults['work_title']) ?>" placeholder="여기에 작업명을 적어주세요.">
                </div>
                <div class="form-field">
                  <label for="work_place">작업장소</label>
                  <input type="text" id="work_place" name="work_place" value="<?= h($formDefaults['work_place']) ?>" placeholder="여기에 작업장소를 적어주세요.">
                </div>
                <div class="form-field">
                  <label for="work_date">작업일자</label>
                  <input type="date" id="work_date" name="work_date" value="<?= h($formDefaults['work_date']) ?>">
                </div>
                <?php if (!($activeGasTeam && auth_can_manage($user))): ?>
                <div class="form-field full">
                  <label>중장비 사용 여부</label>
                  <input type="hidden" name="use_equipment_yn" value="N">
                  <div class="check-item">
                    <label>
                      <input
                        type="checkbox"
                        id="use_equipment_yn"
                        name="use_equipment_yn"
                        value="Y"
                        <?= $formDefaults['use_equipment_yn'] === 'Y' ? 'checked' : '' ?>
                      >
                      <span class="check-item-body">중장비를 사용하는 작업입니다.</span>
                    </label>
                  </div>
                  <div
                    class="list-grid"
                    id="equipment-tool-section"
                    style="<?= $formDefaults['use_equipment_yn'] === 'Y' ? '' : 'display:none;' ?>"
                  >
                    <?php if (!empty($tools)): ?>
                      <?php foreach ($tools as $tool): ?>
                        <div class="check-item">
                          <label>
                            <input
                              type="checkbox"
                              class="equipment-tool-checkbox"
                              name="selected_tools[]"
                              value="<?= (int)$tool['tool_id'] ?>"
                              <?= in_array((int)$tool['tool_id'], $formDefaults['selected_tools'], true) ? 'checked' : '' ?>
                            >
                            <span class="check-item-body"><?= h($tool['tool_name']) ?></span>
                          </label>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="empty" style="padding:14px;">선택 가능한 중장비 항목이 없습니다.</div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>


              <div style="margin-top: 22px;">
                <h2 style="font-size:17px;">공정명 / 대분류 / 작업유형</h2>
                <div class="selector-grid">
                  <section class="selector-box">
                    <h2><?= $pageRole === 'manager' ? '공정명' : '대분류' ?></h2>
                    <p><?= $pageRole === 'manager' ? '작업유형 기준으로 공정명을 먼저 선택합니다.' : '평가유형 기준으로 대분류를 먼저 선택합니다.' ?></p>
                    <select id="group1" onchange="onGroup1Change()">
                      <option value=""><?= $pageRole === 'manager' ? '공정명을 선택하세요' : '대분류를 선택하세요' ?></option>
                      <?php if ($pageRole === 'manager'): ?>
                        <?php
                        $managerProcessOptions = array_values(array_unique(array_filter(array_map(
                            static fn($item) => trim((string)($item['process_category'] ?? '')),
                            $targetOptions
                        ))));
                        ?>
                        <?php foreach ($managerProcessOptions as $processOption): ?>
                          <option value="<?= h($processOption) ?>"><?= h(display_process_name_label($processOption)) ?></option>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <?php
                        $unitTypeOptions = array_values(array_unique(array_filter(array_map(
                            static fn($task) => trim((string)($task['unit_type'] ?? '')),
                            $tasks
                        ))));
                        ?>
                        <?php foreach ($unitTypeOptions as $unitTypeOption): ?>
                          <?php
                          $unitTypeLabel = $unitTypeOption;
                          foreach ($tasks as $taskOption) {
                              if ((string)($taskOption['unit_type'] ?? '') === $unitTypeOption) {
                                  $unitTypeLabel = type_label($unitTypeOption);
                                  break;
                              }
                          }
                          ?>
                          <option value="<?= h($unitTypeOption) ?>"><?= h($unitTypeLabel) ?></option>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </select>
                  </section>
                  <section class="selector-box">
                    <h2><?= $pageRole === 'manager' ? '대분류' : '중분류' ?></h2>
                    <p><?= $pageRole === 'manager' ? '선택한 공정명에 해당하는 대분류를 선택합니다.' : '공정명 또는 작업 그룹 기준으로 두 번째를 고릅니다.' ?></p>
                    <select id="group2" onchange="onGroup2Change()" disabled>
                      <option value=""><?= $pageRole === 'manager' ? '공정명을 먼저 선택하세요' : '대분류를 먼저 선택하세요' ?></option>
                    </select>
                  </section>
                  <section class="selector-box">
                    <h2><?= $pageRole === 'manager' ? '작업유형' : '소분류' ?></h2>
                    <p><?= $pageRole === 'manager' ? '작업유형 선택이 끝나면 하위 위험평가가 자동 조합됩니다.' : '최종 소분류를 선택하면 아래에 상세 정보가 표시됩니다.' ?></p>
                    <select id="group3" onchange="onGroup3Change()" disabled>
                      <option value=""><?= $pageRole === 'manager' ? '대분류를 먼저 선택하세요' : '중분류를 먼저 선택하세요' ?></option>
                    </select>
                  </section>
                </div>
                <div class="selector-add-row">
                  <button type="button" class="btn-secondary" id="add-selected-task">추가</button>
                  <span>현재 선택 작업을 목록에 추가하고, 공정명/대분류/작업유형 선택을 초기화합니다.</span>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($showLeaderDetailSection): ?>
            <div style="margin-top: 22px;">
              <h2 style="font-size:17px;">세부사항</h2>
              <div class="task-info"><?= $leaderElectricalDetailOnlyMode ? '관리감독자가 저장한 기본 정보를 바탕으로 필요한 세부사항만 선택해 저장합니다.' : '세부사항을 선택하면 상단 선택된 작업 카드와 같은 형식으로 함께 표시됩니다.' ?></div>
              <?php
                $gasTeamAllowedTools = ['작업선', '그라인더(유선)', '그라인더(무선)', '전동드릴(유선)', '전동드릴(무선)','위험수공구(파이프렌치/몽키)'];
              ?>
              <?php foreach ($detailLabels as $detailType => $detailLabel): ?>
                <?php if ($activeGasTeam && auth_can_manage($user) && $detailType === 'major_work') continue; ?>
                <div class="detail-group">
                  <h3><?= h($detailLabel) ?></h3>
                  <?php if (!empty($detailOptionGroups[$detailType])): ?>
                    <?php if ($detailType === 'major_work'): ?>
                      <div class="detail-task-grid">
                        <?php foreach ($detailOptionGroups[$detailType] as $detailOption): ?>
                          <?php
                            $detailTitle = (string)$detailOption['title'];
                            $detailValue = detail_selection_value($detailType, $detailTitle);
                            $subOptions = $majorWorkSubOptions[$detailTitle] ?? [];
                            $isDetailChecked = in_array($detailValue, $detailFormState['detail_tasks'] ?? [], true);
                            $detailRiskCodeValue = trim((string)($detailFormState['detail_code_map'][$detailValue] ?? ''));
                            if ($detailRiskCodeValue === '') {
                                $detailRiskCodeValue = resolve_detail_lookup_code($unitCodeLookup, 'major_work', $detailTitle);
                            }
                          ?>
                          <div>
                            <div class="check-item">
                              <label>
                                <input
                                  type="checkbox"
                                  name="detail_tasks[]"
                                  value="<?= h($detailValue) ?>"
                                  class="major-work-checkbox"
                                  data-major-work="<?= h($detailTitle) ?>"
                                  <?= $isDetailChecked ? 'checked' : '' ?>
                                >
                                <span class="check-item-body">
                                  <?= h($detailTitle) ?>
                                  <input
                                    type="text"
                                    name="detail_codes[<?= h($detailValue) ?>]"
                                    value="<?= h($detailRiskCodeValue) ?>"
                                    class="detail-code-input"
                                    placeholder="위험성평가 코드번호"
                                  >
                                </span>
                              </label>
                            </div>
                            <?php if (!empty($subOptions)): ?>
                              <div class="detail-subgroup <?= $isDetailChecked ? '' : 'is-hidden' ?>" data-major-work-subgroup="<?= h($detailTitle) ?>">
                                <h4><?= h($detailTitle) ?> 세부 작업</h4>
                                <div class="detail-task-grid">
                                  <?php foreach ($subOptions as $subTitle): ?>
                                    <?php
                                      $subValue = detail_sub_selection_value('major_work_sub', $detailTitle, $subTitle);
                                      $subRiskCodeValue = trim((string)($detailFormState['detail_code_map'][$subValue] ?? ''));
                                      if ($subRiskCodeValue === '') {
                                          $subRiskCodeValue = resolve_detail_lookup_code($unitCodeLookup, 'major_work_sub', $subTitle, $detailTitle);
                                      }
                                    ?>
                                    <div class="check-item">
                                      <label>
                                        <input
                                          type="checkbox"
                                          name="detail_tasks[]"
                                          class="detail-sub-checkbox"
                                          data-parent-title="<?= h($detailTitle) ?>"
                                          data-sub-title="<?= h($subTitle) ?>"
                                          value="<?= h($subValue) ?>"
                                          <?= in_array($subValue, $detailFormState['detail_tasks'] ?? [], true) ? 'checked' : '' ?>
                                        >
                                        <span class="check-item-body">
                                          <?= h($subTitle) ?>
                                          <input
                                            type="text"
                                            name="detail_codes[<?= h($subValue) ?>]"
                                            value="<?= h($subRiskCodeValue) ?>"
                                            class="detail-code-input"
                                            placeholder="위험성평가 코드번호"
                                          >
                                        </span>
                                      </label>
                                    </div>
                                  <?php endforeach; ?>
                                </div>
                              </div>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <div class="detail-task-grid">
                        <?php foreach ($detailOptionGroups[$detailType] as $detailTitle): ?>
                          <?php
                            // 가스팀 관리자: 공구/장비는 지정 항목만, 작업환경은 기름기 제외
                            if ($activeGasTeam && auth_can_manage($user)) {
                                if ($detailType === 'tool' && !in_array($detailTitle, $gasTeamAllowedTools, true)) continue;
                                if ($detailType === 'env' && $detailTitle === '기름기') continue;
                            }
                          ?>
                          <?php
                            $detailValue = detail_selection_value($detailType, $detailTitle);
                            $isToolDetail = $detailType === 'tool';
                            $detailRiskCodeValue = trim((string)($detailFormState['detail_code_map'][$detailValue] ?? ''));
                            if ($detailRiskCodeValue === '') {
                                $detailRiskCodeValue = resolve_detail_lookup_code($unitCodeLookup, $detailType, $detailTitle);
                            }
                          ?>
                          <div class="check-item">
                            <label>
                              <input
                                type="checkbox"
                                name="detail_tasks[]"
                                <?= $isToolDetail ? 'class="detail-tool-checkbox" data-tool-title="' . h($detailTitle) . '"' : '' ?>
                                value="<?= h($detailValue) ?>"
                                <?= in_array($detailValue, $detailFormState['detail_tasks'] ?? [], true) ? 'checked' : '' ?>
                              >
                              <span class="check-item-body">
                                <?= h($detailTitle) ?>
                                <input
                                  type="text"
                                  name="detail_codes[<?= h($detailValue) ?>]"
                                  value="<?= h($detailRiskCodeValue) ?>"
                                  class="detail-code-input"
                                  placeholder="위험성평가 코드번호"
                                >
                              </span>
                            </label>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="empty" style="margin-top:12px; padding:18px 14px;">선택할 수 있는 항목이 없습니다.</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

              <div class="selected-task" id="selected-task" style="margin-top: 22px;">
                <h2>선택된 작업</h2>
                <div class="task-info"><?= $pageRole === 'manager' ? '공정명, 대분류, 작업유형을 선택한 뒤 추가 버튼을 누르면 위험성평가 코드와 함께 아래 목록에 누적됩니다.' : '대분류, 중분류, 소분류를 선택한 뒤 추가 버튼으로 작업을 누적할 수 있습니다.' ?></div>
              </div>

              <div style="margin-top: 22px;">
                <h2 style="font-size:17px;">작업 유의사항</h2>
                <div class="note-editor-shell">
                    <div class="note-toolbar-group">
                      <button type="button" id="note-upload-image" class="note-icon-button" title="이미지 업로드" aria-label="이미지 업로드">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M12 16V4"></path>
                            <path d="M8 8l4-4 4 4"></path>
                            <path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">이미지 업로드</span>
                      </button>
                      <button type="button" id="note-link-image" class="note-icon-button" title="이미지 링크" aria-label="이미지 링크">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M10 13a5 5 0 0 1 0-7l1.2-1.2a5 5 0 0 1 7 7L17 13"></path>
                            <path d="M14 11a5 5 0 0 1 0 7l-1.2 1.2a5 5 0 0 1-7-7L7 11"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">이미지 링크</span>
                      </button>
                      <button type="button" id="note-insert-selected-image" class="note-icon-button" title="사진 블록" aria-label="사진 블록">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                            <circle cx="9" cy="10" r="1.5"></circle>
                            <path d="M7 16l3.5-3.5L14 16l2.5-2.5L20 16"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">사진 블록</span>
                      </button>
                      <button type="button" id="note-mark-image" class="note-icon-button" title="이미지 편집" aria-label="이미지 편집">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M3 17.5V21h3.5L18 9.5 14.5 6 3 17.5z"></path>
                            <path d="M13.5 7L17 10.5"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">이미지 편집</span>
                      </button>
                    </div>
                    <div class="note-toolbar-group">
                      <button type="button" id="note-image-size-small" class="note-icon-button" title="작게" aria-label="작게">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <rect x="8" y="8" width="8" height="8" rx="1.5"></rect>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">작게</span>
                      </button>
                      <button type="button" id="note-image-size-medium" class="note-icon-button" title="보통" aria-label="보통">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <rect x="6" y="6" width="12" height="12" rx="1.5"></rect>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">보통</span>
                      </button>
                      <button type="button" id="note-image-size-large" class="note-icon-button" title="크게" aria-label="크게">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <rect x="4" y="4" width="16" height="16" rx="1.5"></rect>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">크게</span>
                      </button>
                      <button type="button" id="note-image-size-custom" class="note-icon-button" title="크기 입력" aria-label="크기 입력">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M4 9V4h5"></path>
                            <path d="M20 9V4h-5"></path>
                            <path d="M4 15v5h5"></path>
                            <path d="M20 15v5h-5"></path>
                            <path d="M9 4L4 9"></path>
                            <path d="M15 4l5 5"></path>
                            <path d="M9 20l-5-5"></path>
                            <path d="M15 20l5-5"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">크기 입력</span>
                      </button>
                    </div>
                    <div class="note-toolbar-group">
                      <button type="button" id="note-image-align-left" class="note-icon-button" title="왼쪽 정렬" aria-label="왼쪽 정렬">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M4 6h16"></path>
                            <path d="M4 10h10"></path>
                            <path d="M4 14h14"></path>
                            <path d="M4 18h8"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">왼쪽 정렬</span>
                      </button>
                      <button type="button" id="note-image-align-center" class="note-icon-button" title="가운데 정렬" aria-label="가운데 정렬">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M4 6h16"></path>
                            <path d="M7 10h10"></path>
                            <path d="M5 14h14"></path>
                            <path d="M8 18h8"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">가운데 정렬</span>
                      </button>
                      <button type="button" id="note-image-align-right" class="note-icon-button" title="오른쪽 정렬" aria-label="오른쪽 정렬">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M4 6h16"></path>
                            <path d="M10 10h10"></path>
                            <path d="M6 14h14"></path>
                            <path d="M12 18h8"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">오른쪽 정렬</span>
                      </button>
                      <button type="button" id="note-image-move-up" class="note-icon-button" title="위로 이동" aria-label="위로 이동">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M12 18V6"></path>
                            <path d="M7 11l5-5 5 5"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">위로 이동</span>
                      </button>
                      <button type="button" id="note-image-move-down" class="note-icon-button" title="아래로 이동" aria-label="아래로 이동">
                        <span class="note-icon" aria-hidden="true">
                          <svg viewBox="0 0 24 24">
                            <path d="M12 6v12"></path>
                            <path d="M7 13l5 5 5-5"></path>
                          </svg>
                        </span>
                        <span class="note-visually-hidden">아래로 이동</span>
                      </button>
                    </div>
                  </div>
                  <input type="file" id="note-image-file" accept="image/*" multiple style="display:none;">
                  <div class="note-editor-scroll">
                    <div id="note-editor-root" class="note-editor-host"></div>
                    <textarea
                      name="note_html"
                      id="note_html"
                      class="smarteditor-textarea note-editor-source"
                    ><?= h($formDefaults['note_html']) ?></textarea>
                  </div>
                </div>
                <div class="note-help">TOAST UI Editor로 본문을 직접 편집하고, 아래 버튼이나 첨부 이미지 목록에서 사진을 골라 본문에 넣을 수 있습니다. 에디터 안의 이미지를 클릭하면 선택되고, 크기와 위치를 바로 조절할 수 있습니다.</div>
                <div class="paste-preview" id="paste-preview"></div>
              </div>

            <div class="page-actions">
              <button type="submit" class="btn-primary"><?= $leaderElectricalDetailOnlyMode ? '세부사항 저장' : '확인 및 저장' ?></button>
            </div>
          </form>
          <div class="image-editor-modal" id="image-editor-modal">
            <div class="image-editor-panel">
              <div class="image-editor-headerbar">
                <div class="image-editor-title">이미지 편집</div>
                <div class="image-editor-actions">
                  <button type="button" class="btn-warning" id="image-editor-advanced">Advanced</button>
                  <button type="button" class="btn-secondary" id="image-editor-reset">원본 복원</button>
                  <button type="button" class="btn-secondary" id="image-editor-cancel">닫기</button>
                  <button type="button" class="btn-primary" id="image-editor-save">적용</button>
                </div>
              </div>
              <div class="image-editor-tools">
                <label for="image-editor-tool">도구</label>
                <select id="image-editor-tool">
                  <option value="pen">펜</option>
                  <option value="arrow">화살표</option>
                  <option value="rect">사각형</option>
                  <option value="circle">원</option>
                  <option value="text">텍스트</option>
                </select>
                <label for="image-editor-color">색상</label>
                <input type="color" id="image-editor-color" value="#d64545">
                <label for="image-editor-size">굵기</label>
                <input type="range" id="image-editor-size" min="2" max="18" value="4">
                <button type="button" class="btn-secondary" id="image-editor-undo">되돌리기</button>
              </div>
              <div class="image-editor-canvas-wrap">
                <canvas id="image-editor-canvas"></canvas>
              </div>
            </div>
          </div>
          <div class="image-editor-modal advanced-image-editor-modal" id="advanced-image-editor-modal">
            <div class="image-editor-panel advanced-image-editor-panel">
              <div class="image-editor-headerbar">
                <div class="image-editor-title">Advanced Image Editor</div>
                <div class="image-editor-actions">
                  <button type="button" class="btn-secondary" id="advanced-image-editor-reset">Reset</button>
                  <button type="button" class="btn-secondary" id="advanced-image-editor-cancel">Close</button>
                  <button type="button" class="btn-primary" id="advanced-image-editor-apply">Apply</button>
                </div>
              </div>
              <div class="advanced-image-editor-host" id="advanced-image-editor-root"></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
  const tasks = <?= json_encode(array_map(static function ($task) {
      return [
          'unit_ra_id' => (int)$task['unit_ra_id'],
          'unit_code' => (string)($task['unit_code'] ?? ''),
          'unit_title' => (string)($task['unit_title'] ?? ''),
          'unit_type' => (string)($task['unit_type'] ?? ''),
          'unit_type_label' => type_label((string)$task['unit_type']),
          'process_name' => (string)($task['process_name'] ?? ''),
          'created_by' => (string)($task['created_by'] ?? ''),
          'created_at' => !empty($task['created_at']) ? substr((string)$task['created_at'], 0, 10) : '-',
          'item_count' => (int)$task['item_count'],
      ];
  }, $tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const targetOptions = <?= json_encode($targetOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const workTypeSubOptions = <?= json_encode($workTypeSubOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const taskItemsByUnit = <?= json_encode($taskItemsByUnit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const currentRole = <?= json_encode($pageRole, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const showLeaderDetailSection = <?= $showLeaderDetailSection ? 'true' : 'false' ?>;
  const leaderElectricalDetailOnlyMode = <?= $leaderElectricalDetailOnlyMode ? 'true' : 'false' ?>;
  const detailCodeLookup = <?= json_encode($unitCodeLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const detailSummaryLabels = <?= json_encode(array_merge($detailLabels, [
      'major_work_sub' => '중대위험작업 세부',
  ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const defaultManagerProcessCategory = <?= json_encode($defaultManagerProcessCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const initialSelectedUnitRaIds = <?= json_encode($selectedUnitRaIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const initialSelectedUnitRaId = <?= (int)$selectedUnitRaId ?>;
  const uploadActionUrl = <?= json_encode($selfPage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const initialUploadedImages = <?= json_encode(array_map(
      static fn($filePath) => [
          'filePath' => (string)$filePath,
          'previewUrl' => (string)$filePath,
      ],
      $formDefaults['pasted_images']
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const isAddMode = <?= $addMode ? 'true' : 'false' ?>;

  const group1 = document.getElementById('group1');
  const group2 = document.getElementById('group2');
  const group3 = document.getElementById('group3');
  const selectedTaskBox = document.getElementById('selected-task');
  const unitRaIdInput = document.getElementById('unit_ra_id');
  const selectedUnitRaIdsInput = document.getElementById('selected_unit_ra_ids');
  const addSelectedTaskButton = document.getElementById('add-selected-task');
  const pastedImagesInput = document.getElementById('pasted_images');
  const noteHtmlInput = document.getElementById('note_html');
  const noteEditorRoot = document.getElementById('note-editor-root');
  const noteHelpBox = document.querySelector('.note-help');
  const pastePreview = document.getElementById('paste-preview');
  const noteUploadImageButton = document.getElementById('note-upload-image');
  const noteLinkImageButton = document.getElementById('note-link-image');
  const noteInsertSelectedImageButton = document.getElementById('note-insert-selected-image');
  const noteMarkImageButton = document.getElementById('note-mark-image');
  const noteImageSizeSmallButton = document.getElementById('note-image-size-small');
  const noteImageSizeMediumButton = document.getElementById('note-image-size-medium');
  const noteImageSizeLargeButton = document.getElementById('note-image-size-large');
  const noteImageSizeCustomButton = document.getElementById('note-image-size-custom');
  const noteImageAlignLeftButton = document.getElementById('note-image-align-left');
  const noteImageAlignCenterButton = document.getElementById('note-image-align-center');
  const noteImageAlignRightButton = document.getElementById('note-image-align-right');
  const noteImageMoveUpButton = document.getElementById('note-image-move-up');
  const noteImageMoveDownButton = document.getElementById('note-image-move-down');
  const NOTE_EDITOR_PLACEHOLDER = '내용을 입력해 주세요';
  const noteImageFileInput = document.getElementById('note-image-file');
  const reportForm = document.getElementById('work-report-form');
  const showEntryFormButton = document.getElementById('show-entry-form');
  const printSavedReportButton = document.getElementById('print-saved-report');
  const useEquipmentCheckbox = document.getElementById('use_equipment_yn');
  const equipmentToolSection = document.getElementById('equipment-tool-section');
  const equipmentToolCheckboxes = Array.from(document.querySelectorAll('.equipment-tool-checkbox'));
  const imageEditorModal = document.getElementById('image-editor-modal');
  const imageEditorRoot = document.getElementById('image-editor-root');
  const imageEditorCanvas = document.getElementById('image-editor-canvas');
  const imageEditorTool = document.getElementById('image-editor-tool');
  const imageEditorColor = document.getElementById('image-editor-color');
  const imageEditorSize = document.getElementById('image-editor-size');
  const imageEditorUndo = document.getElementById('image-editor-undo');
  const imageEditorAdvanced = document.getElementById('image-editor-advanced');
  const imageEditorReset = document.getElementById('image-editor-reset');
  const imageEditorCancel = document.getElementById('image-editor-cancel');
  const imageEditorSave = document.getElementById('image-editor-save');
  const advancedImageEditorModal = document.getElementById('advanced-image-editor-modal');
  const advancedImageEditorRoot = document.getElementById('advanced-image-editor-root');
  const advancedImageEditorReset = document.getElementById('advanced-image-editor-reset');
  const advancedImageEditorCancel = document.getElementById('advanced-image-editor-cancel');
  const advancedImageEditorApply = document.getElementById('advanced-image-editor-apply');
  const advancedImageEditorTitle = advancedImageEditorModal
    ? advancedImageEditorModal.querySelector('.image-editor-title')
    : null;
  const selectedUnitRaIds = Array.isArray(initialSelectedUnitRaIds)
    ? initialSelectedUnitRaIds
      .map((value) => Number.parseInt(String(value), 10))
      .filter((value) => Number.isInteger(value) && value > 0)
    : [];
  const pastedImages = [...initialUploadedImages];
  let currentSelectedTask = null;
  let selectedPreviewIndex = -1;
  let editingImageIndex = -1;
  let editorOriginalImageSrc = '';
  let imageEditorInstance = null;
  let advancedImageEditorInstance = null;
  let advancedImageEditorSource = '';
  let advancedImageEditorCanvas = null;
  let advancedImageEditorContext = null;
  let advancedImageEditorOriginalDataUrl = '';
  let advancedImageEditorColorDataUrl = '';
  let advancedImageEditorCurrentDataUrl = '';
  let advancedImageEditorImage = null;
  let advancedImageEditorCropMode = false;
  let advancedImageEditorCropRect = null;
  let advancedImageEditorCropStart = null;

  const syncEquipmentToolSection = () => {
    if (!useEquipmentCheckbox || !equipmentToolSection) {
      return;
    }

    const isEnabled = useEquipmentCheckbox.checked;
    equipmentToolSection.style.display = isEnabled ? '' : 'none';
    equipmentToolCheckboxes.forEach((checkbox) => {
      checkbox.disabled = !isEnabled;
    });
  };
  let editorHistory = [];
  let editorIsDrawing = false;
  let editorStartPoint = null;
  let editorSnapshot = null;
  let selectedNoteImageId = '';
  let lastSelectedNoteImagePath = '';
  let imageEditorOpenToken = 0;
  let noteImageSelectionLockUntil = 0;
  let noteImageIdSeed = 0;
  let noteEditorInstance = null;
  let noteEditorAutoHeightTimer = 0;
  let noteEditorAutoHeightFollowupTimer = 0;
  const MAX_IMAGE_WIDTH = 1280;
  const MAX_IMAGE_HEIGHT = 1280;
  const MAX_SINGLE_UPLOAD_BYTES = 90000;
  const SMARTEDITOR_MIN_HEIGHT = 420;
  const SMARTEDITOR_AUTO_HEIGHT_PADDING = 14;
  const SMARTEDITOR_MAX_HEIGHT = 2400;
  const NOTE_IMAGE_SIZE_PRESETS = {
    small: 40,
    medium: 68,
    large: 92,
  };
  const ADVANCED_IMAGE_EDITOR_MENUS = ['crop', 'resize', 'flip', 'rotate', 'draw', 'shape', 'icon', 'text', 'mask', 'filter'];
  const IMAGE_EDITOR_MENUS = ['crop', 'flip', 'rotate', 'draw', 'shape', 'icon', 'text', 'filter'];
  const EMPTY_IMAGE_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn8nW0AAAAASUVORK5CYII=';
  const imageEditorLocale = {
    Apply: '\uC801\uC6A9',
    Cancel: '\uCDE8\uC18C',
    Crop: '\uC790\uB974\uAE30',
    Delete: '\uC0AD\uC81C',
    'Delete-all': '\uC804\uCCB4 \uC0AD\uC81C',
    Download: '\uB2E4\uC6B4\uB85C\uB4DC',
    Draw: '\uADF8\uB9AC\uAE30',
    Filter: '\uD544\uD130',
    Flip: '\uB4A4\uC9D1\uAE30',
    Hand: '\uC774\uB3D9',
    History: '\uC774\uB825',
    Icon: '\uC544\uC774\uCF58',
    Load: '\uBD88\uB7EC\uC624\uAE30',
    Mask: '\uB9C8\uC2A4\uD06C',
    Redo: '\uB2E4\uC2DC\uC2E4\uD589',
    Reset: '\uC6D0\uBCF8 \uBCF5\uC6D0',
    Resize: '\uD06C\uAE30 \uC870\uC815',
    Rotate: '\uD68C\uC804',
    Shape: '\uB3C4\uD615',
    Text: '\uD14D\uC2A4\uD2B8',
    Undo: '\uB418\uB3CC\uB9AC\uAE30',
    ZoomIn: '\uD655\uB300',
    ZoomOut: '\uCD95\uC18C',
  };

  if (imageEditorAdvanced) {
    imageEditorAdvanced.textContent = '\uACE0\uAE09 \uD3B8\uC9D1';
    imageEditorAdvanced.title = '\uC790\uB974\uAE30, \uD68C\uC804, \uD544\uD130 \uB3C4\uAD6C \uC5F4\uAE30';
  }
  if (advancedImageEditorTitle) {
    advancedImageEditorTitle.textContent = '\uACE0\uAE09 \uC774\uBBF8\uC9C0 \uD3B8\uC9D1';
  }
  if (advancedImageEditorReset) {
    advancedImageEditorReset.textContent = '\uC6D0\uBCF8 \uBCF5\uC6D0';
  }
  if (advancedImageEditorCancel) {
    advancedImageEditorCancel.textContent = '\uB2EB\uAE30';
  }
  if (advancedImageEditorApply) {
    advancedImageEditorApply.textContent = '\uC801\uC6A9';
  }

  function resetSelect(select, placeholder, disabled = true) {
    if (!select) return;
    select.innerHTML = `<option value="">${placeholder}</option>`;
    select.disabled = disabled;
  }

  function uniqueValues(list) {
    return [...new Set(list)].filter(Boolean);
  }

  function compareNaturalValues(left, right) {
    return String(left || '').localeCompare(String(right || ''), 'ko', {
      numeric: true,
      sensitivity: 'base',
    });
  }

  function getTaskByUnitRaId(unitRaId) {
    const parsedUnitRaId = Number.parseInt(String(unitRaId || '0'), 10);
    if (!Number.isInteger(parsedUnitRaId) || parsedUnitRaId <= 0) {
      return null;
    }
    return tasks.find((item) => item.unit_ra_id === parsedUnitRaId) || null;
  }

  function syncSelectedUnitRaIdsField() {
    if (selectedUnitRaIdsInput) {
      selectedUnitRaIdsInput.value = JSON.stringify(selectedUnitRaIds);
    }
    if (unitRaIdInput) {
      unitRaIdInput.value = selectedUnitRaIds.length > 0 ? String(selectedUnitRaIds[0]) : '';
    }
  }

  function renderSelectedUnitSummaryHtml() {
    if (selectedUnitRaIds.length === 0) {
      return `
        <div class="selected-task-detail-block">
          <div class="selected-task-detail-title">선택 목록</div>
          <div class="selected-task-detail-empty">아직 추가된 작업이 없습니다. 위에서 선택 후 추가 버튼을 눌러주세요.</div>
        </div>
      `;
    }

    const lines = selectedUnitRaIds.map((unitRaId) => {
      const task = getTaskByUnitRaId(unitRaId);
      if (!task) {
        return '';
      }
      return `
        <div class="selected-task-detail-line is-removable">
          <span class="task-inline-info selected-task-detail-line-text">작업유형: ${escapeHtml(task.unit_title)} / 위험성평가번호: ${escapeHtml(task.unit_code || '코드 없음')}</span>
          <button type="button" class="selected-task-remove-btn" data-action="remove-selected-unit" data-unit-ra-id="${unitRaId}">삭제</button>
        </div>
      `;
    }).filter(Boolean).join('');

    if (!lines) {
      return '';
    }

    return `
      <div class="selected-task-detail-block">
        <div class="selected-task-detail-title">선택 목록</div>
        <div class="selected-task-detail-lines">${lines}</div>
      </div>
    `;
  }

  function resetTaskSelectors() {
    if (group1) {
      group1.value = '';
    }
    resetSelect(group2, currentRole === 'manager' ? '공정명을 먼저 선택하세요' : '대분류를 먼저 선택하세요', true);
    resetSelect(group3, currentRole === 'manager' ? '대분류를 먼저 선택하세요' : '중분류를 먼저 선택하세요', true);
  }

  function addCurrentSelectedTask() {
    if (!currentSelectedTask || currentSelectedTask.missing) {
      window.alert('추가할 수 있는 작업유형을 먼저 선택해주세요.');
      return false;
    }

    const selectedTaskUnitRaId = Number.parseInt(String(currentSelectedTask.unit_ra_id || '0'), 10);
    if (!Number.isInteger(selectedTaskUnitRaId) || selectedTaskUnitRaId <= 0) {
      window.alert('선택한 작업의 위험성평가 코드 정보를 찾을 수 없습니다.');
      return false;
    }

    if (!selectedUnitRaIds.includes(selectedTaskUnitRaId)) {
      selectedUnitRaIds.push(selectedTaskUnitRaId);
    }

    syncSelectedUnitRaIdsField();
    resetTaskSelectors();
    renderSelectedTask(null);
    return true;
  }

  function removeSelectedTaskByUnitRaId(unitRaId) {
    const selectedTaskUnitRaId = Number.parseInt(String(unitRaId || '0'), 10);
    if (!Number.isInteger(selectedTaskUnitRaId) || selectedTaskUnitRaId <= 0) {
      return false;
    }

    const removeIndex = selectedUnitRaIds.indexOf(selectedTaskUnitRaId);
    if (removeIndex < 0) {
      return false;
    }

    selectedUnitRaIds.splice(removeIndex, 1);
    syncSelectedUnitRaIdsField();
    renderSelectedTask(currentSelectedTask);
    return true;
  }

  function getManagerMajorOptions(processCategory) {
    const majorMap = new Map();

    targetOptions
      .filter((item) => item.process_category === processCategory)
      .forEach((item) => {
        const majorCategory = String(item.major_category || '').trim();
        const unitCode = String(item.unit_code || '').trim();
        if (!majorCategory) return;

        const current = majorMap.get(majorCategory) || {
          majorCategory,
          unitCode: '',
        };

        if (!current.unitCode || (unitCode && compareNaturalValues(unitCode, current.unitCode) < 0)) {
          current.unitCode = unitCode;
        }

        majorMap.set(majorCategory, current);
      });

    return Array.from(majorMap.values())
      .sort((left, right) => {
        if (left.unitCode && right.unitCode) {
          const codeCompare = compareNaturalValues(left.unitCode, right.unitCode);
          if (codeCompare !== 0) return codeCompare;
        } else if (left.unitCode) {
          return -1;
        } else if (right.unitCode) {
          return 1;
        }

        return compareNaturalValues(left.majorCategory, right.majorCategory);
      })
      .map((item) => item.majorCategory);
  }

  function onGroup1Change() {
    const value = group1.value;
    resetSelect(group2, currentRole === 'manager' ? '공정명을 먼저 선택하세요' : '대분류를 먼저 선택하세요', true);
    resetSelect(group3, currentRole === 'manager' ? '대분류를 먼저 선택하세요' : '중분류를 먼저 선택하세요', true);
    renderSelectedTask(null);

    if (!value) return;

    const options = currentRole === 'manager'
      ? getManagerMajorOptions(value)
      : uniqueValues(
          tasks
            .filter((task) => task.unit_type === value)
            .map((task) => task.process_name || '기타')
        );

    resetSelect(group2, currentRole === 'manager' ? '대분류를 선택하세요' : '중분류를 선택하세요', false);
    options.forEach((option) => {
      group2.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`);
    });
  }

  function onGroup2Change() {
    const firstValue = group1.value;
    const groupValue = group2.value;
    resetSelect(group3, currentRole === 'manager' ? '대분류를 먼저 선택하세요' : '중분류를 먼저 선택하세요', true);
    renderSelectedTask(null);

    if (!firstValue || !groupValue) return;

    if (currentRole === 'manager') {
      const targetTypeOptions = uniqueValues(
        targetOptions
          .filter((item) => item.process_category === firstValue && item.major_category === groupValue)
          .map((item) => item.work_type)
      );

      let options = [...targetTypeOptions];

      // form.html 규칙과 동일: 결선/판넬은 work_type_sub를 합쳐서 보여줌
      if (firstValue === '결선' && groupValue === '판넬') {
        const subMasterOptions = uniqueValues(workTypeSubOptions[firstValue] || []);
        options = uniqueValues([
          ...targetTypeOptions,
          ...subMasterOptions,
        ]);
      }

      // form.html 규칙과 동일: 시운전/판넬은 판넬시운전을 최상단에 추가
      if (firstValue === '시운전' && groupValue === '판넬') {
        options = uniqueValues([
          '판넬시운전',
          ...options,
        ]);
      }

      resetSelect(group3, '작업유형을 선택하세요', false);
      options.forEach((option) => {
        group3.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`);
      });
      return;
    }

    const options = tasks.filter((task) => task.unit_type === firstValue && (task.process_name || '기타') === groupValue);
    resetSelect(group3, '소분류를 선택하세요', false);
    options.forEach((task) => {
      const label = `${task.unit_title}${task.unit_code ? ` (${task.unit_code})` : ''}`;
      group3.insertAdjacentHTML('beforeend', `<option value="${task.unit_ra_id}">${escapeHtml(label)}</option>`);
    });
  }

  function onGroup3Change() {
    let task = null;

    if (currentRole === 'manager') {
      const processName = group1.value;
      const majorCategory = group2.value;
      const workType = group3.value;
      const unitTitle = majorCategory && workType ? `${majorCategory} - ${workType}` : '';
      task = tasks.find((item) =>
        item.unit_type === 'target' &&
        item.process_name === processName &&
        item.unit_title === unitTitle
      ) || null;

      if (!task && unitTitle) {
        renderSelectedTask({
          unit_ra_id: 0,
          unit_code: '',
          unit_title: unitTitle,
          unit_type_label: '작업대상',
          process_name: processName,
          created_by: '',
          created_at: '-',
          item_count: 0,
          missing: true,
        });
        return;
      }
    } else {
      const taskId = Number(group3.value || 0);
      task = tasks.find((item) => item.unit_ra_id === taskId) || null;
    }

    renderSelectedTask(task);
  }

  function renderSelectedTask(task) {
    if (!selectedTaskBox) return;
    currentSelectedTask = task || null;
    syncSelectedUnitRaIdsField();

    const detailSummaryHtml = buildSelectedTaskDetailSummaryHtml();
    const selectedUnitSummaryHtml = renderSelectedUnitSummaryHtml();
    const introText = currentRole === 'leader' && leaderElectricalDetailOnlyMode
      ? '관리감독자가 저장한 기본 정보를 바탕으로 필요한 세부사항을 선택해 저장합니다.'
      : (currentRole === 'manager'
        ? '공정명, 대분류, 작업유형을 선택한 뒤 추가 버튼을 눌러 작업을 계속 누적할 수 있습니다.'
        : '대분류, 중분류, 소분류를 선택한 뒤 추가 버튼으로 작업을 누적할 수 있습니다.');

    if (!task) {
      selectedTaskBox.innerHTML = `
        <h2>선택된 작업</h2>
        <div class="task-info">${introText}</div>
        ${selectedUnitSummaryHtml}
        ${detailSummaryHtml}
      `;
      return;
    }

    if (task.missing) {
      selectedTaskBox.innerHTML = `
        <h2>선택된 작업</h2>
        <div class="task-info">${introText}</div>
        ${selectedUnitSummaryHtml}
        <div class="task-info">현재 이 조합과 일치하는 기초 위험성평가가 아직 등록되지 않았습니다.</div>
        ${detailSummaryHtml}
      `;
      return;
    }

    selectedTaskBox.innerHTML = `
      <h2>선택된 작업</h2>
      <div class="task-info">${introText}</div>
      ${selectedUnitSummaryHtml}
      <div class="task-inline-info">
        현재 선택: ${escapeHtml(task.unit_title)} / 위험성평가번호: ${escapeHtml(task.unit_code || '코드 없음')}
      </div>
      ${detailSummaryHtml}
    `;
  }

  function parseDetailSelectionValue(value) {
    const parts = String(value || '').split('|', 3);
    return {
      type: parts[0] || '',
      parent: parts.length >= 3 ? (parts[1] || '') : '',
      title: parts.length >= 3 ? (parts[2] || '') : (parts[1] || ''),
    };
  }

  function resolveAutoDetailRiskCode(detailValue) {
    const parsed = typeof detailValue === 'string'
      ? parseDetailSelectionValue(detailValue)
      : (detailValue || {});
    const detailType = String(parsed.type || '');
    const detailTitle = String(parsed.title || '');
    const parentTitle = String(parsed.parent || '');

    const normalizeDetailLookupKey = (value) => String(value || '')
      .replace(/\s+/g, '')
      .replace(/[^0-9A-Za-z가-힣]/g, '')
      .toLowerCase();

    const findLookupRiskCode = (sourceMap, lookupTitle) => {
      if (!sourceMap || !lookupTitle) {
        return '';
      }

      const exactCode = String(sourceMap[lookupTitle] || '').trim();
      if (exactCode !== '') {
        return exactCode;
      }

      const normalizedLookupTitle = normalizeDetailLookupKey(lookupTitle);
      if (normalizedLookupTitle === '') {
        return '';
      }

      for (const [title, code] of Object.entries(sourceMap)) {
        const normalizedTitle = normalizeDetailLookupKey(title);
        const normalizedCode = String(code || '').trim();
        if (normalizedTitle !== '' && normalizedTitle === normalizedLookupTitle && normalizedCode !== '') {
          return normalizedCode;
        }
      }

      return '';
    };

    if (detailType === 'major_work' && detailTitle) {
      return findLookupRiskCode(detailCodeLookup.major_work, detailTitle);
    }
    if (detailType === 'major_work_sub' && parentTitle && detailTitle) {
      return findLookupRiskCode(detailCodeLookup.major_work, `${parentTitle} - ${detailTitle}`);
    }
    if (detailType === 'tool' && detailTitle) {
      return findLookupRiskCode(detailCodeLookup.tool, detailTitle);
    }
    if (detailType === 'env' && detailTitle) {
      return findLookupRiskCode(detailCodeLookup.env, detailTitle);
    }

    return '';
  }

  function getDetailCodeInput(detailValue) {
    const inputs = document.getElementsByName(`detail_codes[${detailValue}]`);
    if (!inputs || inputs.length === 0) {
      return null;
    }
    return inputs[0];
  }

  function syncAutoDetailCodeInput(detailValue, options = {}) {
    const { force = false } = options;
    const input = getDetailCodeInput(detailValue);
    if (!input) {
      return '';
    }

    const currentValue = String(input.value || '').trim();
    if (!force && currentValue !== '') {
      return currentValue;
    }

    const autoRiskCode = resolveAutoDetailRiskCode(detailValue);
    if (autoRiskCode !== '') {
      input.value = autoRiskCode;
    }

    return String(input.value || '').trim();
  }

  function syncCheckedDetailCodeInputs() {
    document.querySelectorAll('input[name="detail_tasks[]"]:checked').forEach((checkbox) => {
      syncAutoDetailCodeInput(String(checkbox.value || ''));
    });
  }

  function getDetailCodeValue(detailValue) {
    const input = getDetailCodeInput(detailValue);
    const currentValue = String(input?.value || '').trim();
    if (currentValue !== '') {
      return currentValue;
    }
    return resolveAutoDetailRiskCode(detailValue);
  }

  function collectSelectedTaskDetailSummary() {
    const grouped = {
      major_work: [],
      major_work_sub: [],
      env: [],
      tool: [],
    };
    const majorWorkSubParents = new Set();

    document.querySelectorAll('input[name="detail_tasks[]"]:checked').forEach((checkbox) => {
      const parsed = parseDetailSelectionValue(checkbox.value);
      if (!parsed.type || !parsed.title || !Object.prototype.hasOwnProperty.call(grouped, parsed.type)) {
        return;
      }

      if (parsed.type === 'major_work_sub' && parsed.parent) {
        majorWorkSubParents.add(parsed.parent);
      }

      grouped[parsed.type].push({
        title: parsed.type === 'major_work_sub' && parsed.parent
          ? `${parsed.parent} > ${parsed.title}`
          : parsed.title,
        riskCode: getDetailCodeValue(checkbox.value),
      });
    });

    if (majorWorkSubParents.size > 0) {
      grouped.major_work = grouped.major_work.filter((item) => !majorWorkSubParents.has(item.title));
    }

    return grouped;
  }

  function buildSelectedTaskDetailSummaryHtml() {
    if (!showLeaderDetailSection) {
      return '';
    }

    const detailSummary = collectSelectedTaskDetailSummary();
    const detailTypes = ['major_work', 'major_work_sub', 'tool', 'env'];
    const sections = detailTypes
      .map((type) => ({
        type,
        label: detailSummaryLabels[type] || type,
        items: detailSummary[type] || [],
      }))
      .filter((section) => section.items.length > 0);

    if (sections.length === 0) {
      return `
        <div class="selected-task-detail-block">
          <div class="selected-task-detail-title">세부사항</div>
          <div class="selected-task-detail-empty">아직 선택된 세부사항이 없습니다.</div>
        </div>
      `;
    }

    const lines = sections.flatMap((section) => section.items.map((item) => ({
      label: section.label,
      title: item.title,
      riskCode: item.riskCode || '코드 없음',
    })));

    return `
      <div class="selected-task-detail-block">
        <div class="selected-task-detail-title">세부사항</div>
        <div class="selected-task-detail-lines">
          ${lines.map((line) => `
            <div class="task-inline-info selected-task-detail-line">
              ${escapeHtml(line.label)}: ${escapeHtml(line.title)} / 위험성평가번호: ${escapeHtml(line.riskCode)}
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function displayProcessName(name) {
    return name === '결선' ? '결선작업' : name;
  }

  function refreshSelectedTaskBox() {
    renderSelectedTask(currentSelectedTask);
  }

  function cssEscapeSelector(value) {
    const rawValue = String(value || '');
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(rawValue);
    }
    return rawValue.replace(/["\\]/g, '\\$&');
  }

  function isElementNode(node) {
    return Boolean(node) && node.nodeType === Node.ELEMENT_NODE;
  }

  function isImageNode(node) {
    return isElementNode(node) && String(node.tagName || '').toUpperCase() === 'IMG';
  }

  function isIframeNode(node) {
    return isElementNode(node) && String(node.tagName || '').toUpperCase() === 'IFRAME';
  }

  function isTextAreaNode(node) {
    return isElementNode(node) && String(node.tagName || '').toUpperCase() === 'TEXTAREA';
  }

  function isStyleNode(node) {
    return isElementNode(node) && String(node.tagName || '').toUpperCase() === 'STYLE';
  }

  function getNoteEditorApp() {
    return noteEditorInstance;
  }

  function getNoteEditorRootElement() {
    return isElementNode(noteEditorRoot) ? noteEditorRoot : null;
  }

  function syncNoteEditorField() {
    if (noteHtmlInput) {
      const normalizedHtml = normalizeNoteEditorContentMarkup(getCurrentNoteEditorHtml());
      noteHtmlInput.value = hasMeaningfulNoteHtml(normalizedHtml)
        ? normalizeEditorHtmlImagePaths(normalizedHtml, { forEditor: false })
        : '';
    }
    syncNoteEditorPlaceholderState();
    return noteHtmlInput ? String(noteHtmlInput.value || '') : '';
  }

  function getNoteEditorHtml() {
    return syncNoteEditorField();
  }

  function hasMeaningfulNoteHtml(html) {
    const rawHtml = String(html || '').trim();
    if (rawHtml === '') {
      return false;
    }

    const plainText = rawHtml
      .replace(/<br\s*\/?>/gi, '')
      .replace(/&nbsp;/gi, ' ')
      .replace(/<[^>]+>/g, '')
      .trim();

    return plainText !== '' || /<(img|figure|table|ul|ol|blockquote|hr)\b/i.test(rawHtml);
  }

  function toAbsoluteAppUrl(path) {
    const rawPath = String(path || '').trim();
    if (rawPath === '') {
      return '';
    }

    try {
      return new URL(rawPath, window.location.href).href;
    } catch (error) {
      return rawPath;
    }
  }

  function toStoredAppPath(path) {
    const rawPath = String(path || '').trim();
    if (rawPath === '') {
      return '';
    }

    if (/^(data:|blob:|javascript:|mailto:|tel:)/i.test(rawPath)) {
      return rawPath;
    }

    try {
      const parsedUrl = new URL(rawPath, window.location.href);
      if (parsedUrl.origin !== window.location.origin) {
        return rawPath;
      }

      const appBaseUrl = new URL('./', window.location.href);
      const appBasePath = appBaseUrl.pathname.endsWith('/')
        ? appBaseUrl.pathname
        : `${appBaseUrl.pathname}/`;

      if (!parsedUrl.pathname.startsWith(appBasePath)) {
        return rawPath;
      }

      let relativePath = parsedUrl.pathname.slice(appBasePath.length);
      if (parsedUrl.search) {
        relativePath += parsedUrl.search;
      }
      if (parsedUrl.hash) {
        relativePath += parsedUrl.hash;
      }
      return decodeURIComponent(relativePath);
    } catch (error) {
      return rawPath;
    }
  }

  function getImageStoredPath(image) {
    if (!isImageNode(image)) {
      return '';
    }

    const dataFilePath = String(image.getAttribute('data-file-path') || '').trim();
    if (dataFilePath !== '') {
      return dataFilePath;
    }

    return toStoredAppPath(image.getAttribute('src') || '');
  }

  function findPastedImageIndexByPath(path) {
    const normalizedPath = toStoredAppPath(path || '');
    if (normalizedPath === '') {
      return -1;
    }

    return pastedImages.findIndex((image) => {
      const filePath = toStoredAppPath(image.filePath || '');
      const previewPath = toStoredAppPath(image.previewUrl || '');
      return filePath === normalizedPath || previewPath === normalizedPath;
    });
  }

  function generateNoteImageId() {
    noteImageIdSeed += 1;
    return `note-image-${Date.now()}-${noteImageIdSeed}`;
  }

  function normalizeNoteImageAlign(value) {
    const rawValue = String(value || '').trim().toLowerCase();
    if (rawValue === 'left' || rawValue === 'right') {
      return rawValue;
    }
    return 'center';
  }

  function clampNoteImageWidth(value) {
    const parsedValue = Number.parseFloat(String(value || '').replace('%', '').trim());
    if (!Number.isFinite(parsedValue)) {
      return 100;
    }
    return Math.max(20, Math.min(100, Math.round(parsedValue)));
  }

  function getNoteImageWidth(image) {
    if (!isImageNode(image)) {
      return 100;
    }

    const dataWidth = String(image.getAttribute('data-note-image-width') || '').trim();
    if (dataWidth !== '') {
      return clampNoteImageWidth(dataWidth);
    }

    const styleWidth = String(image.style.width || '').trim();
    const widthMatch = styleWidth.match(/^([0-9]+(?:\.[0-9]+)?)%$/);
    if (widthMatch) {
      return clampNoteImageWidth(widthMatch[1]);
    }

    return 100;
  }

  function getNoteImageAlign(image) {
    if (!isImageNode(image)) {
      return 'center';
    }

    const dataAlign = String(image.getAttribute('data-note-image-align') || '').trim();
    if (dataAlign !== '') {
      return normalizeNoteImageAlign(dataAlign);
    }

    return 'center';
  }

  function getNoteEditorDocument() {
    const editorRootElement = getNoteEditorRootElement();
    if (!editorRootElement) {
      return null;
    }

    return editorRootElement.querySelector(
      '.toastui-editor-ww-container .ProseMirror, .toastui-editor-ww-container [contenteditable="true"], .toastui-editor-contents[contenteditable="true"]'
    );
  }

  function syncNoteEditorPlaceholderState(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc) {
      return;
    }

    const currentHtml = typeof noteEditorInstance?.getHTML === 'function'
      ? String(noteEditorInstance.getHTML() || '')
      : String(noteHtmlInput?.value || '');
    const isEmpty = !hasMeaningfulNoteHtml(currentHtml);

    editorDoc.classList.remove('toastui-editor-contents-placeholder');
    editorDoc.removeAttribute('data-placeholder');
    editorDoc.classList.toggle('note-editor-empty', isEmpty);
    if (isEmpty) {
      editorDoc.setAttribute('data-note-placeholder', NOTE_EDITOR_PLACEHOLDER);
    } else {
      editorDoc.removeAttribute('data-note-placeholder');
    }
  }

  function getNoteImagePath(image) {
    if (!isImageNode(image)) {
      return '';
    }
    return getImageStoredPath(image) || String(image.getAttribute('src') || '').trim();
  }

  function getNoteImageFromTarget(target) {
    let currentTarget = target;
    if (currentTarget && currentTarget.nodeType === Node.TEXT_NODE) {
      currentTarget = currentTarget.parentNode;
    }

    if (!isElementNode(currentTarget)) {
      return null;
    }

    if (isImageNode(currentTarget)) {
      return currentTarget;
    }

    const directImage = currentTarget.querySelector('img.note-inline-image, img[data-note-image-id], img');
    if (isImageNode(directImage)) {
      return directImage;
    }

    const figure = currentTarget.closest('figure.note-media');
    if (isElementNode(figure)) {
      const image = figure.querySelector('img.note-inline-image, img[data-note-image-id], img');
      return isImageNode(image) ? image : null;
    }

    return null;
  }

  function getNoteImageFromEvent(event) {
    if (event && typeof event.composedPath === 'function') {
      const eventPath = event.composedPath();
      for (const node of eventPath) {
        const image = getNoteImageFromTarget(node);
        if (isImageNode(image)) {
          return image;
        }
      }
    }

    return getNoteImageFromTarget(event?.target || null);
  }

  function syncSelectedPreviewFromNoteImage(image, options = {}) {
    const { render = false } = options;
    const imagePath = getNoteImagePath(image);
    if (imagePath === '') {
      return -1;
    }

    lastSelectedNoteImagePath = imagePath;
    const imageIndex = findPastedImageIndexByPath(imagePath);
    if (imageIndex >= 0) {
      setSelectedPreviewIndex(imageIndex);
      if (render) {
        renderPastePreview();
      }
    }

    return imageIndex;
  }

  function isIgnorableNoteNode(node) {
    return Boolean(node) && node.nodeType === Node.TEXT_NODE && String(node.textContent || '').trim() === '';
  }

  function isNoteSpacerElement(node) {
    if (!isElementNode(node) || node.tagName !== 'P') {
      return false;
    }

    if (node.querySelector('img, figure, table, ul, ol, blockquote, h3, h4')) {
      return false;
    }

    const normalizedHtml = String(node.innerHTML || '')
      .replace(/&nbsp;/gi, '')
      .replace(/<br\s*\/?>/gi, '')
      .replace(/\s+/g, '');

    return normalizedHtml === '';
  }

  function applyNoteImagePresentation(image) {
    if (!isImageNode(image)) {
      return false;
    }

    let changed = false;
    const width = getNoteImageWidth(image);
    const align = getNoteImageAlign(image);
    const figure = image.closest('figure.note-media');
    const caption = figure?.querySelector('figcaption');

    if (!image.hasAttribute('data-note-image-id')) {
      image.setAttribute('data-note-image-id', generateNoteImageId());
      changed = true;
    }

    if (!image.classList.contains('note-inline-image')) {
      image.classList.add('note-inline-image');
      changed = true;
    }

    if (image.getAttribute('data-note-image-width') !== String(width)) {
      image.setAttribute('data-note-image-width', String(width));
      changed = true;
    }

    if (image.getAttribute('data-note-image-align') !== align) {
      image.setAttribute('data-note-image-align', align);
      changed = true;
    }

    const targetMaxWidth = '100%';
    if (image.style.maxWidth !== targetMaxWidth) {
      image.style.maxWidth = targetMaxWidth;
      changed = true;
    }

    if (image.style.height !== 'auto') {
      image.style.height = 'auto';
      changed = true;
    }

    const targetWidth = `${width}%`;
    if (image.style.width !== targetWidth) {
      image.style.width = targetWidth;
      changed = true;
    }

    if (image.style.display !== 'block') {
      image.style.display = 'block';
      changed = true;
    }

    const marginLeft = align === 'right' ? 'auto' : (align === 'center' ? 'auto' : '0');
    const marginRight = align === 'left' ? 'auto' : (align === 'center' ? 'auto' : '0');

    if (image.style.marginLeft !== marginLeft) {
      image.style.marginLeft = marginLeft;
      changed = true;
    }

    if (image.style.marginRight !== marginRight) {
      image.style.marginRight = marginRight;
      changed = true;
    }

    if (isElementNode(figure)) {
      if (!figure.classList.contains('note-media')) {
        figure.classList.add('note-media');
        changed = true;
      }

      if (figure.getAttribute('data-note-image-align') !== align) {
        figure.setAttribute('data-note-image-align', align);
        changed = true;
      }

      if (figure.style.maxWidth !== '100%') {
        figure.style.maxWidth = '100%';
        changed = true;
      }

      if (figure.style.width !== '100%') {
        figure.style.width = '100%';
        changed = true;
      }

      if (figure.style.display !== 'flex') {
        figure.style.display = 'flex';
        changed = true;
      }

      if (figure.style.flexDirection !== 'column') {
        figure.style.flexDirection = 'column';
        changed = true;
      }

      const figureAlignItems = align === 'right' ? 'flex-end' : (align === 'center' ? 'center' : 'flex-start');
      if (figure.style.alignItems !== figureAlignItems) {
        figure.style.alignItems = figureAlignItems;
        changed = true;
      }

      if (figure.style.boxSizing !== 'border-box') {
        figure.style.boxSizing = 'border-box';
        changed = true;
      }

      if (figure.style.overflow !== 'visible') {
        figure.style.overflow = 'visible';
        changed = true;
      }

      if (figure.style.textAlign !== align) {
        figure.style.textAlign = align;
        changed = true;
      }
    }

    if (isElementNode(caption) && caption.style.textAlign !== align) {
      caption.style.textAlign = align;
      changed = true;
    }

    return changed;
  }

  function findNoteImageCandidate(node) {
    let currentNode = node;

    if (currentNode && currentNode.nodeType === Node.TEXT_NODE) {
      currentNode = currentNode.parentNode;
    }

    if (!isElementNode(currentNode)) {
      return null;
    }

    if (isImageNode(currentNode)) {
      return currentNode;
    }

    const descendantImage = currentNode.querySelector('img.note-inline-image, img[data-note-image-id], figure.note-media img, img');
    if (isImageNode(descendantImage)) {
      return descendantImage;
    }

    return null;
  }

  function resolveSelectedNoteImageFromSelection(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc) {
      return null;
    }

    const ownerDocument = editorDoc.ownerDocument || document;
    const candidateNodes = [];
    const addCandidateNode = (node) => {
      if (!node || candidateNodes.includes(node)) {
        return;
      }
      candidateNodes.push(node);
    };

    addCandidateNode(ownerDocument.activeElement);

    const editorSelection = ownerDocument.defaultView?.getSelection?.() || null;
    if (editorSelection && editorSelection.rangeCount > 0) {
      const selectionRange = editorSelection.getRangeAt(0);
      addCandidateNode(selectionRange.commonAncestorContainer);
      addCandidateNode(selectionRange.startContainer);
      addCandidateNode(selectionRange.endContainer);
    }

    for (const candidateNode of candidateNodes) {
      if (candidateNode !== editorDoc && !editorDoc.contains(candidateNode)) {
        continue;
      }

      const image = findNoteImageCandidate(candidateNode);
      if (!isImageNode(image) || !editorDoc.contains(image)) {
        continue;
      }

      selectedNoteImageId = image.getAttribute('data-note-image-id') || '';
      return image;
    }

    return null;
  }

  function getSelectedNoteImage(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc) {
      return null;
    }

    if (selectedNoteImageId) {
      const image = editorDoc.querySelector(`img[data-note-image-id="${cssEscapeSelector(selectedNoteImageId)}"]`);
      if (isImageNode(image)) {
        return image;
      }
    }

    const resolvedImage = resolveSelectedNoteImageFromSelection(editorDoc);
    if (isImageNode(resolvedImage)) {
      return resolvedImage;
    }

    if (lastSelectedNoteImagePath !== '') {
      const fallbackImage = Array.from(
        editorDoc.querySelectorAll('img.note-inline-image, img[data-note-image-id], img')
      ).find((image) => getNoteImagePath(image) === lastSelectedNoteImagePath);
      if (isImageNode(fallbackImage)) {
        selectedNoteImageId = fallbackImage.getAttribute('data-note-image-id') || selectedNoteImageId;
        return fallbackImage;
      }
    }

    const editorImages = editorDoc.querySelectorAll('img.note-inline-image, img[data-note-image-id], img');
    if (editorImages.length === 1 && isImageNode(editorImages[0])) {
      selectedNoteImageId = editorImages[0].getAttribute('data-note-image-id') || selectedNoteImageId;
      lastSelectedNoteImagePath = getNoteImagePath(editorImages[0]);
      return editorImages[0];
    }

    return null;
  }

  function getFallbackNoteImage(editorDoc = getNoteEditorDocument()) {
    const editorImages = [];
    const addImagesFromRoot = (root) => {
      if (!root || typeof root.querySelectorAll !== 'function') {
        return;
      }
      Array.from(root.querySelectorAll('img.note-inline-image, img[data-note-image-id], img')).forEach((image) => {
        if (isImageNode(image) && !editorImages.includes(image)) {
          editorImages.push(image);
        }
      });
    };

    addImagesFromRoot(editorDoc);
    if (editorImages.length === 0) {
      addImagesFromRoot(getNoteEditorRootElement());
    }

    if (editorImages.length === 0) {
      return null;
    }

    const previewCandidate = selectedPreviewIndex >= 0 ? pastedImages[selectedPreviewIndex] || null : null;
    const previewPath = previewCandidate
      ? toStoredAppPath(previewCandidate.filePath || previewCandidate.previewUrl || '')
      : '';

    if (previewPath !== '') {
      const previewMatch = editorImages.find((image) => getNoteImagePath(image) === previewPath);
      if (isImageNode(previewMatch)) {
        return previewMatch;
      }
    }

    if (lastSelectedNoteImagePath !== '') {
      const lastMatch = editorImages.find((image) => getNoteImagePath(image) === lastSelectedNoteImagePath);
      if (isImageNode(lastMatch)) {
        return lastMatch;
      }
    }

    if (editorImages.length === 1) {
      return editorImages[0];
    }

    return editorImages[0];
  }

  function hasSelectedNoteImageInEditor(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc || !selectedNoteImageId) {
      return false;
    }

    const image = editorDoc.querySelector(`img[data-note-image-id="${cssEscapeSelector(selectedNoteImageId)}"]`);
    return isImageNode(image);
  }

  function updateNoteImageToolbarState(editorDoc = getNoteEditorDocument()) {
    const accentButtons = [
      noteImageSizeSmallButton,
      noteImageSizeMediumButton,
      noteImageSizeLargeButton,
      noteImageSizeCustomButton,
      noteImageAlignLeftButton,
      noteImageAlignCenterButton,
      noteImageAlignRightButton,
    ];

    accentButtons.forEach((button) => button?.classList.remove('is-accent'));

    const image = getSelectedNoteImage(editorDoc);
    if (!isImageNode(image)) {
      return;
    }

    const width = getNoteImageWidth(image);
    const align = getNoteImageAlign(image);

    if (width === NOTE_IMAGE_SIZE_PRESETS.small) {
      noteImageSizeSmallButton?.classList.add('is-accent');
    } else if (width === NOTE_IMAGE_SIZE_PRESETS.medium) {
      noteImageSizeMediumButton?.classList.add('is-accent');
    } else if (width === NOTE_IMAGE_SIZE_PRESETS.large) {
      noteImageSizeLargeButton?.classList.add('is-accent');
    } else {
      noteImageSizeCustomButton?.classList.add('is-accent');
    }

    if (align === 'left') {
      noteImageAlignLeftButton?.classList.add('is-accent');
    } else if (align === 'right') {
      noteImageAlignRightButton?.classList.add('is-accent');
    } else {
      noteImageAlignCenterButton?.classList.add('is-accent');
    }
  }

  function syncSelectedNoteImageHighlight(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc) {
      updateNoteImageToolbarState(null);
      return;
    }

    let hasSelectedImage = false;
    editorDoc.querySelectorAll('figure.note-media').forEach((figure) => {
      figure.classList.remove('is-selected');
    });
    editorDoc.querySelectorAll('img.note-inline-image, img[data-note-image-id]').forEach((image) => {
      if (!isImageNode(image)) {
        return;
      }

      image.classList.remove('is-selected');
      const isSelected = image.getAttribute('data-note-image-id') === selectedNoteImageId;
      const container = image.closest('figure.note-media');

      if (isElementNode(container)) {
        container.classList.toggle('is-selected', isSelected);
      } else {
        image.classList.toggle('is-selected', isSelected);
      }

      if (isSelected) {
        hasSelectedImage = true;
      }
    });

    if (!hasSelectedImage) {
      selectedNoteImageId = '';
    }

    updateNoteImageToolbarState(editorDoc);
  }

  function refreshNoteEditorInteractiveImages(options = {}) {
    const { syncField = false } = options;
    const editorDoc = getNoteEditorDocument();
    if (!editorDoc) {
      return;
    }

    syncSelectedNoteImageHighlight(editorDoc);

    if (syncField) {
      syncNoteEditorField();
    }
  }

  function installNoteEditorInteractions(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc || editorDoc.dataset.noteImageInteractionBound === '1') {
      return;
    }

    const ownerDocument = editorDoc.ownerDocument || document;
    const syncSelectionFromEditor = () => {
      window.setTimeout(() => {
        if (Date.now() < noteImageSelectionLockUntil || hasSelectedNoteImageInEditor(editorDoc)) {
          syncSelectedNoteImageHighlight(editorDoc);
          return;
        }
        resolveSelectedNoteImageFromSelection(editorDoc);
        syncSelectedNoteImageHighlight(editorDoc);
      }, 0);
    };
    const syncEditorState = () => {
      syncNoteEditorPlaceholderState(editorDoc);
    };

    const handleNoteImageSelection = (event) => {
      const image = getNoteImageFromEvent(event);
      if (isImageNode(image) && editorDoc.contains(image)) {
        if (!image.hasAttribute('data-note-image-id')) {
          const currentImagePath = getNoteImagePath(image);
          const wrapper = document.createElement('div');
          wrapper.innerHTML = normalizeNoteEditorContentMarkup(getCurrentNoteEditorHtml());
          const matchingImage = Array.from(wrapper.querySelectorAll('img')).find((candidateImage) => {
            return getNoteImagePath(candidateImage) === currentImagePath;
          });

          if (isImageNode(matchingImage)) {
            selectedNoteImageId = matchingImage.getAttribute('data-note-image-id') || '';
            lastSelectedNoteImagePath = currentImagePath;
            setNoteEditorHtml(wrapper.innerHTML);
            noteImageSelectionLockUntil = Date.now() + 500;
            const matchedIndex = findPastedImageIndexByPath(currentImagePath);
            if (matchedIndex >= 0) {
              setSelectedPreviewIndex(matchedIndex);
              renderPastePreview();
            }
            return;
          }
        }

        selectedNoteImageId = image.getAttribute('data-note-image-id') || '';
        syncSelectedPreviewFromNoteImage(image, { render: true });
        noteImageSelectionLockUntil = Date.now() + 400;
      } else {
        selectedNoteImageId = '';
        noteImageSelectionLockUntil = Date.now() + 250;
      }
      syncSelectedNoteImageHighlight(editorDoc);
    };

    const handleNoteImageDoubleClick = (event) => {
      const image = getNoteImageFromEvent(event);
      if (!isImageNode(image) || !editorDoc.contains(image)) {
        return;
      }

      event.preventDefault();
      const imageIndex = syncSelectedPreviewFromNoteImage(image, { render: true });
      if (imageIndex < 0) {
        alert('본문 이미지와 연결된 첨부 사진을 찾지 못했습니다.');
        return;
      }

      selectedNoteImageId = image.getAttribute('data-note-image-id') || selectedNoteImageId;
      noteImageSelectionLockUntil = Date.now() + 500;
      openImageEditor(imageIndex);
    };

    editorDoc.dataset.noteImageInteractionBound = '1';
    editorDoc.addEventListener('pointerdown', handleNoteImageSelection, true);
    editorDoc.addEventListener('mousedown', handleNoteImageSelection, true);
    editorDoc.addEventListener('click', handleNoteImageSelection, true);
    editorDoc.addEventListener('dblclick', handleNoteImageDoubleClick, true);
    editorDoc.addEventListener('mouseup', syncSelectionFromEditor, true);
    editorDoc.addEventListener('keyup', syncSelectionFromEditor, true);
    editorDoc.addEventListener('input', syncEditorState, true);
    editorDoc.addEventListener('focus', syncEditorState, true);
    editorDoc.addEventListener('blur', syncEditorState, true);
    ownerDocument.addEventListener('selectionchange', syncSelectionFromEditor);
    syncNoteEditorPlaceholderState(editorDoc);
  }

  function withSelectedNoteImage(handler) {
    const editorDoc = getNoteEditorDocument();
    let selectedImage = getSelectedNoteImage(editorDoc);
    if (!isImageNode(selectedImage)) {
      selectedImage = getFallbackNoteImage(editorDoc);
    }

    if (!isImageNode(selectedImage)) {
      alert('본문에서 이미지를 먼저 클릭해 선택해 주세요.');
      return false;
    }

    if (applyNoteImagePresentation(selectedImage)) {
      lastSelectedNoteImagePath = getNoteImagePath(selectedImage);
    }

    if (!selectedNoteImageId) {
      selectedNoteImageId = selectedImage.getAttribute('data-note-image-id') || '';
    }

    if (!selectedNoteImageId) {
      alert('본문에서 이미지를 먼저 클릭해 선택해 주세요.');
      return false;
    }

    const changed = handler(selectedImage, editorDoc?.ownerDocument || document);
    if (changed === false) {
      return false;
    }

    applyNoteImagePresentation(selectedImage);
    selectedNoteImageId = selectedImage.getAttribute('data-note-image-id') || selectedNoteImageId;
    lastSelectedNoteImagePath = getNoteImagePath(selectedImage);
    noteImageSelectionLockUntil = Date.now() + 500;
    refreshNoteEditorInteractiveImages();
    syncNoteEditorField();
    queueNoteEditorAutoHeight({ force: true });
    return true;
  }

  function setSelectedNoteImageWidth(width) {
    return withSelectedNoteImage((image) => {
      image.setAttribute('data-note-image-width', String(clampNoteImageWidth(width)));
    });
  }

  function setSelectedNoteImageAlign(align) {
    return withSelectedNoteImage((image) => {
      image.setAttribute('data-note-image-align', normalizeNoteImageAlign(align));
    });
  }

  function moveSelectedNoteImage(direction) {
    return withSelectedNoteImage((image, editorDoc) => {
      const blockStart = image.closest('figure.note-media') || image;
      if (!blockStart.parentNode) {
        return false;
      }

      const blockNodes = [blockStart];
      let trailingNode = blockStart.nextSibling;
      while (isIgnorableNoteNode(trailingNode)) {
        blockNodes.push(trailingNode);
        trailingNode = trailingNode.nextSibling;
      }
      if (isNoteSpacerElement(trailingNode)) {
        blockNodes.push(trailingNode);
      }

      const blockEnd = blockNodes[blockNodes.length - 1];
      const parentNode = blockStart.parentNode;

      if (direction === 'up') {
        let previousNode = blockStart.previousSibling;
        while (isIgnorableNoteNode(previousNode)) {
          previousNode = previousNode.previousSibling;
        }
        if (!previousNode) {
          return false;
        }
        if (isNoteSpacerElement(previousNode)) {
          let previousBlock = previousNode.previousSibling;
          while (isIgnorableNoteNode(previousBlock)) {
            previousBlock = previousBlock.previousSibling;
          }
          if (previousBlock) {
            previousNode = previousBlock;
          }
        }

        const fragment = editorDoc.createDocumentFragment();
        blockNodes.forEach((node) => fragment.appendChild(node));
        parentNode.insertBefore(fragment, previousNode);
        return true;
      }

      let nextNode = blockEnd.nextSibling;
      while (isIgnorableNoteNode(nextNode)) {
        nextNode = nextNode.nextSibling;
      }
      if (!nextNode) {
        return false;
      }

      let insertBefore = nextNode.nextSibling;
      while (isIgnorableNoteNode(insertBefore)) {
        insertBefore = insertBefore.nextSibling;
      }
      if (isNoteSpacerElement(insertBefore)) {
        insertBefore = insertBefore.nextSibling;
      }

      const fragment = editorDoc.createDocumentFragment();
      blockNodes.forEach((node) => fragment.appendChild(node));
      parentNode.insertBefore(fragment, insertBefore);
      return true;
    });
  }

  function normalizeEditorHtmlImagePaths(html, options = {}) {
    const {
      forEditor = false,
      stripDataFilePath = false,
    } = options;

    const wrapper = document.createElement('div');
    wrapper.innerHTML = String(html || '');

    wrapper.querySelectorAll('img').forEach((image) => {
      if (!isImageNode(image)) {
        return;
      }

      const storedPath = getImageStoredPath(image);
      if (storedPath !== '') {
        if (forEditor) {
          image.setAttribute('src', toAbsoluteAppUrl(storedPath));
          image.setAttribute('data-file-path', storedPath);
        } else {
          image.setAttribute('src', storedPath);
          if (stripDataFilePath) {
            image.removeAttribute('data-file-path');
          } else {
            image.setAttribute('data-file-path', storedPath);
          }
        }
      }
    });

    wrapper.querySelectorAll('figure.note-media.is-selected').forEach((figure) => {
      figure.classList.remove('is-selected');
    });
    wrapper.querySelectorAll('img.note-inline-image.is-selected, img[data-note-image-id].is-selected').forEach((image) => {
      image.classList.remove('is-selected');
    });

    return wrapper.innerHTML;
  }

  function normalizeRenderedNoteImages() {
    document.querySelectorAll('.note-render').forEach((container) => {
      if (!container || container.dataset.noteRenderNormalized === '1') {
        return;
      }

      container.style.maxWidth = '100%';
      container.style.overflow = 'visible';

      container.querySelectorAll('figure').forEach((figure) => {
        if (!isElementNode(figure)) {
          return;
        }

        const figureWidth = String(figure.style.width || '').trim();
        figure.style.maxWidth = '100%';
        figure.style.width = figureWidth !== '' && !figureWidth.startsWith('min(')
          ? `min(${figureWidth}, 100%)`
          : '100%';
        figure.style.boxSizing = 'border-box';
        figure.style.overflow = 'visible';
      });

      container.querySelectorAll('img').forEach((image) => {
        if (!isImageNode(image)) {
          return;
        }

        const currentWidth = String(image.style.width || '').trim();
        image.style.maxWidth = '100%';
        image.style.height = 'auto';
        image.style.boxSizing = 'border-box';
        image.style.objectFit = 'contain';
        image.style.display = 'block';
        image.style.marginLeft = 'auto';
        image.style.marginRight = 'auto';
        image.style.width = currentWidth !== '' && !currentWidth.startsWith('min(')
          ? `min(${currentWidth}, 100%)`
          : '100%';
      });

      container.dataset.noteRenderNormalized = '1';
    });
  }

  function getCurrentNoteEditorHtml() {
    const editorDoc = getNoteEditorDocument();
    const editorDomHtml = String(editorDoc?.innerHTML || '').trim();
    if (editorDomHtml !== '') {
      return editorDomHtml;
    }

    const editorApp = getNoteEditorApp();
    if (editorApp && typeof editorApp.getHTML === 'function') {
      const appHtml = String(editorApp.getHTML() || '').trim();
      if (appHtml !== '') {
        return appHtml;
      }
    }

    return String(noteHtmlInput?.value || '');
  }

  function normalizeNoteEditorContentMarkup(html) {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = normalizeEditorHtmlImagePaths(html, { forEditor: true });

    wrapper.querySelectorAll('img').forEach((image) => {
      if (isImageNode(image)) {
        applyNoteImagePresentation(image);
      }
    });

    return wrapper.innerHTML;
  }

  function setNoteEditorHtml(html) {
    if (!noteHtmlInput) return;

    const editorHtml = normalizeNoteEditorContentMarkup(html);
    const hasMeaningfulHtml = hasMeaningfulNoteHtml(editorHtml);
    noteHtmlInput.value = hasMeaningfulHtml
      ? normalizeEditorHtmlImagePaths(editorHtml, { forEditor: false })
      : '';

    const editorApp = getNoteEditorApp();
    if (editorApp && typeof editorApp.setHTML === 'function') {
      if (!hasMeaningfulHtml && typeof editorApp.setMarkdown === 'function') {
        editorApp.setMarkdown('', false);
      } else {
        editorApp.setHTML(editorHtml, false);
      }
      window.setTimeout(() => {
        syncNoteEditorPlaceholderState();
        refreshNoteEditorInteractiveImages({ syncField: true });
        queueNoteEditorAutoHeight({ force: true });
      }, 80);
    }
  }

  function insertNoteHtml(html) {
    if (!noteHtmlInput) return;

    const editorApp = getNoteEditorApp();
    if (editorApp && typeof editorApp.getHTML === 'function') {
      const currentHtml = normalizeNoteEditorContentMarkup(editorApp.getHTML() || '');
      setNoteEditorHtml(`${currentHtml}${html}`);
      return;
    }

    noteHtmlInput.value = normalizeEditorHtmlImagePaths(`${noteHtmlInput.value || ''}${html}`, { forEditor: false });
  }

  function createNoteImageBlock(src, altText = '첨부 이미지', captionText = '', storedPath = '') {
    const caption = (captionText || '').trim() || '이미지 설명을 입력해 주세요.';
    const normalizedStoredPath = String(storedPath || '').trim() || toStoredAppPath(src);
    const displaySrc = normalizedStoredPath !== '' ? toAbsoluteAppUrl(normalizedStoredPath) : String(src || '').trim();
    const filePathAttribute = normalizedStoredPath !== ''
      ? ` data-file-path="${escapeHtml(normalizedStoredPath)}"`
      : '';
    return `<figure class="note-media" data-note-image-align="center" style="text-align:center;"><img src="${escapeHtml(displaySrc)}"${filePathAttribute} alt="${escapeHtml(altText)}" class="note-inline-image" data-note-image-id="${escapeHtml(generateNoteImageId())}" data-note-image-width="100" data-note-image-align="center" style="max-width:100%;width:100%;display:block;margin-left:auto;margin-right:auto;"><figcaption style="text-align:center;">${escapeHtml(caption)}</figcaption></figure><p><br></p>`;
  }

  function syncNoteEditorImageUrl(oldUrl, newUrl = '') {
    if (!noteHtmlInput || !oldUrl) return;

    const oldStoredPath = toStoredAppPath(oldUrl);
    const newStoredPath = toStoredAppPath(newUrl);
    const wrapper = document.createElement('div');
    wrapper.innerHTML = normalizeEditorHtmlImagePaths(getNoteEditorHtml(), { forEditor: true });
    wrapper.querySelectorAll('img').forEach((image) => {
      if (getImageStoredPath(image) !== oldStoredPath) {
        return;
      }

      if (newUrl) {
        if (newStoredPath !== '') {
          image.setAttribute('src', toAbsoluteAppUrl(newStoredPath));
          image.setAttribute('data-file-path', newStoredPath);
        } else {
          image.setAttribute('src', newUrl);
          image.removeAttribute('data-file-path');
        }
        return;
      }

      const figure = image.closest('figure.note-media');
      if (figure && figure.querySelectorAll('img').length <= 1) {
        figure.remove();
      } else {
        image.remove();
      }
    });

    setNoteEditorHtml(wrapper.innerHTML);
  }

  function collectReferencedImagePathsFromEditor() {
    const seen = new Set();
    const wrapper = document.createElement('div');
    wrapper.innerHTML = normalizeEditorHtmlImagePaths(getNoteEditorHtml(), { forEditor: true });

    wrapper.querySelectorAll('img').forEach((image) => {
      const storedPath = getImageStoredPath(image);
      if (storedPath !== '') {
        seen.add(storedPath);
      }
    });

    return pastedImages
      .map((image) => String(image.filePath || '').trim())
      .filter((filePath) => {
        if (!filePath) {
          return false;
        }
        return seen.has(filePath);
      });
  }

  function isMeaningfulNoteEditorElement(element) {
    if (!isElementNode(element)) {
      return false;
    }

    const tagName = String(element.tagName || '').toUpperCase();
    if (['SCRIPT', 'STYLE', 'META', 'LINK', 'BR'].includes(tagName)) {
      return false;
    }

    if (isImageNode(element)) {
      return true;
    }

    if (['FIGURE', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'HR', 'IFRAME', 'VIDEO', 'CANVAS', 'SVG'].includes(tagName)) {
      return true;
    }

    const plainText = String(element.textContent || '')
      .replace(/\u200B/g, '')
      .replace(/\u00A0/g, ' ')
      .trim();

    return plainText !== '';
  }

  function measureNoteEditorTextNodeBottom(textNode, bodyRect) {
    if (!textNode || textNode.nodeType !== Node.TEXT_NODE) {
      return 0;
    }

    const plainText = String(textNode.textContent || '')
      .replace(/\u200B/g, '')
      .replace(/\u00A0/g, ' ')
      .trim();

    if (plainText === '') {
      return 0;
    }

    try {
      const textRange = textNode.ownerDocument.createRange();
      textRange.selectNodeContents(textNode);

      let maxBottom = 0;
      Array.from(textRange.getClientRects()).forEach((rect) => {
        if (!rect || !Number.isFinite(rect.bottom)) {
          return;
        }
        maxBottom = Math.max(maxBottom, rect.bottom - bodyRect.top);
      });

      if (typeof textRange.detach === 'function') {
        textRange.detach();
      }

      return maxBottom;
    } catch (error) {
      return 0;
    }
  }

  function measureNoteEditorContentHeight(editorDoc) {
    if (!editorDoc) {
      return 0;
    }

    const bodyRect = editorDoc.getBoundingClientRect();
    const bodyStyle = window.getComputedStyle(editorDoc);
    const paddingTop = parseFloat(bodyStyle?.paddingTop || '0') || 0;
    const paddingBottom = parseFloat(bodyStyle?.paddingBottom || '0') || 0;
    let maxBottom = paddingTop;

    editorDoc.querySelectorAll('*').forEach((element) => {
      if (!isMeaningfulNoteEditorElement(element)) {
        return;
      }

      const rect = element.getBoundingClientRect();
      if (!rect || !Number.isFinite(rect.bottom) || (rect.width <= 0 && rect.height <= 0)) {
        return;
      }

      maxBottom = Math.max(maxBottom, rect.bottom - bodyRect.top);
    });

    Array.from(editorDoc.childNodes || []).forEach((node) => {
      maxBottom = Math.max(maxBottom, measureNoteEditorTextNodeBottom(node, bodyRect));
    });

    return Math.max(0, Math.ceil(maxBottom + paddingBottom + SMARTEDITOR_AUTO_HEIGHT_PADDING));
  }

  function resizeNoteEditorToContent(options = {}) {
    const {
      force = false,
    } = options;

    const editorApp = getNoteEditorApp();
    const editorDoc = getNoteEditorDocument();
    const editorRootElement = getNoteEditorRootElement();
    if (!editorApp || !editorDoc || !editorRootElement || typeof editorApp.setHeight !== 'function') {
      return;
    }

    const currentHtml = typeof editorApp.getHTML === 'function'
      ? String(editorApp.getHTML() || '')
      : (noteHtmlInput ? String(noteHtmlInput.value || '') : '');
    if (!force && !hasMeaningfulNoteHtml(currentHtml)) {
      return;
    }

    const measuredHeight = measureNoteEditorContentHeight(editorDoc);
    const fallbackHeight = Math.max(
      editorDoc.scrollHeight || 0,
      editorDoc.offsetHeight || 0
    );
    const contentHeight = measuredHeight > 0 ? measuredHeight : fallbackHeight;
    const editorUi = editorRootElement.querySelector('.toastui-editor-defaultUI');
    const editorUiRect = editorUi?.getBoundingClientRect() || null;
    const surfaceRect = editorDoc.getBoundingClientRect();
    const chromeHeight = editorUiRect
      ? Math.max(0, Math.ceil(editorUiRect.height - surfaceRect.height))
      : 110;
    const targetHeight = Math.max(
      SMARTEDITOR_MIN_HEIGHT,
      Math.min(SMARTEDITOR_MAX_HEIGHT, contentHeight + chromeHeight)
    );

    if (typeof editorApp.getHeight === 'function' && editorApp.getHeight() === `${targetHeight}px`) {
      return;
    }

    editorApp.setHeight(`${targetHeight}px`);
  }

  function bindNoteEditorAutoHeight(editorDoc = getNoteEditorDocument()) {
    if (!editorDoc) {
      return;
    }

    editorDoc.querySelectorAll('img').forEach((image) => {
      if (!isImageNode(image) || image.dataset.noteAutoHeightBound === '1') {
        return;
      }

      image.dataset.noteAutoHeightBound = '1';
      image.addEventListener('load', () => {
        window.setTimeout(() => {
          resizeNoteEditorToContent({ force: true });
        }, 30);
      });
    });
  }

  function queueNoteEditorAutoHeight(options = {}) {
    const {
      force = false,
    } = options;

    window.clearTimeout(noteEditorAutoHeightTimer);
    window.clearTimeout(noteEditorAutoHeightFollowupTimer);

    noteEditorAutoHeightTimer = window.setTimeout(() => {
      resizeNoteEditorToContent({ force });
    }, 70);

    noteEditorAutoHeightFollowupTimer = window.setTimeout(() => {
      resizeNoteEditorToContent({ force });
    }, 260);
  }

  function initializeNoteEditor() {
    if (!noteHtmlInput || !noteEditorRoot || !(window.toastui && toastui.Editor)) {
      return;
    }

    const initialHtml = normalizeNoteEditorContentMarkup(noteHtmlInput.value || '');
    const hasInitialHtml = hasMeaningfulNoteHtml(initialHtml);
    noteHtmlInput.value = hasInitialHtml
      ? normalizeEditorHtmlImagePaths(initialHtml, { forEditor: false })
      : '';

    noteEditorInstance = new toastui.Editor({
      el: noteEditorRoot,
      height: `${SMARTEDITOR_MIN_HEIGHT}px`,
      minHeight: `${SMARTEDITOR_MIN_HEIGHT}px`,
      initialEditType: 'wysiwyg',
      initialValue: '',
      previewStyle: 'vertical',
      language: 'ko-KR',
      hideModeSwitch: true,
      usageStatistics: false,
      autofocus: false,
      placeholder: '',
      toolbarItems: [
        ['heading', 'bold', 'italic', 'strike'],
        ['hr', 'quote'],
        ['ul', 'ol', 'task'],
        ['table', 'link'],
      ],
      customHTMLSanitizer: (html) => html,
    });

    if (hasInitialHtml) {
      noteEditorInstance.setHTML(initialHtml, false);
    }
    noteEditorInstance.on('change', () => {
      syncNoteEditorField();
      const editorDoc = getNoteEditorDocument();
      installNoteEditorInteractions(editorDoc);
      syncNoteEditorPlaceholderState(editorDoc);
      refreshNoteEditorInteractiveImages();
      bindNoteEditorAutoHeight(editorDoc);
      queueNoteEditorAutoHeight({ force: true });
    });

    window.setTimeout(() => {
      syncNoteEditorField();
      const editorDoc = getNoteEditorDocument();
      installNoteEditorInteractions(editorDoc);
      syncNoteEditorPlaceholderState(editorDoc);
      refreshNoteEditorInteractiveImages({ syncField: true });
      bindNoteEditorAutoHeight(editorDoc);
      queueNoteEditorAutoHeight({ force: true });
    }, 120);
  }

  function setSelectedPreviewIndex(index) {
    if (pastedImages.length === 0) {
      selectedPreviewIndex = -1;
      return;
    }
    selectedPreviewIndex = Math.max(0, Math.min(index, pastedImages.length - 1));
  }

  function renderPastePreview() {
    if (!pastePreview || !pastedImagesInput) return;
    if (pastedImages.length === 0) {
      selectedPreviewIndex = -1;
      pastePreview.innerHTML = '';
      pastedImagesInput.value = '[]';
      return;
    }

    if (selectedPreviewIndex < 0 || selectedPreviewIndex >= pastedImages.length) {
      selectedPreviewIndex = 0;
    }

    pastePreview.innerHTML = pastedImages.map((image, index) => `
      <figure class="${selectedPreviewIndex === index ? 'is-selected' : ''}">
        <img src="${escapeHtml(image.previewUrl || image.filePath || '')}" alt="첨부 이미지 ${index + 1}">
        <figcaption>첨부 이미지 ${index + 1}</figcaption>
        <div class="image-actions">
          <button type="button" data-action="select" data-index="${index}">${selectedPreviewIndex === index ? '선택됨' : '선택'}</button>
          <button type="button" data-action="mark" data-index="${index}">편집</button>
          <button type="button" data-action="remove" data-index="${index}">삭제</button>
        </div>
      </figure>
    `).join('');
    pastePreview.querySelectorAll('button[data-action="mark"]').forEach((button) => {
      button.textContent = '편집';
      button.setAttribute('title', '이미지 편집');
      button.setAttribute('aria-label', '이미지 편집');
    });
    pastedImagesInput.value = JSON.stringify(
      pastedImages
        .map((image) => image.filePath || '')
        .filter(Boolean)
    );
  }

  function dataUrlByteLength(dataUrl) {
    const parts = String(dataUrl || '').split(',');
    if (parts.length < 2) return 0;
    const base64 = parts[1];
    const padding = (base64.match(/=*$/) || [''])[0].length;
    return Math.floor((base64.length * 3) / 4) - padding;
  }

  function loadImageElement(src) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = src;
    });
  }

  async function compressDataUrlImage(src) {
    const image = await loadImageElement(src);
    let width = image.naturalWidth || image.width;
    let height = image.naturalHeight || image.height;

    if (!width || !height) {
      return src;
    }

    const ratio = Math.min(
      1,
      MAX_IMAGE_WIDTH / width,
      MAX_IMAGE_HEIGHT / height
    );

    width = Math.max(1, Math.round(width * ratio));
    height = Math.max(1, Math.round(height * ratio));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(image, 0, 0, width, height);

    let quality = 0.86;
    let output = canvas.toDataURL('image/jpeg', quality);

    while (dataUrlByteLength(output) > MAX_SINGLE_UPLOAD_BYTES && quality > 0.42) {
      quality -= 0.08;
      output = canvas.toDataURL('image/jpeg', quality);
    }

    while (dataUrlByteLength(output) > MAX_SINGLE_UPLOAD_BYTES && canvas.width > 640 && canvas.height > 640) {
      const resizedCanvas = document.createElement('canvas');
      resizedCanvas.width = Math.max(640, Math.round(canvas.width * 0.85));
      resizedCanvas.height = Math.max(640, Math.round(canvas.height * 0.85));
      const resizedCtx = resizedCanvas.getContext('2d');
      resizedCtx.fillStyle = '#ffffff';
      resizedCtx.fillRect(0, 0, resizedCanvas.width, resizedCanvas.height);
      resizedCtx.drawImage(canvas, 0, 0, resizedCanvas.width, resizedCanvas.height);
      quality = 0.8;
      output = resizedCanvas.toDataURL('image/jpeg', quality);
      while (dataUrlByteLength(output) > MAX_SINGLE_UPLOAD_BYTES && quality > 0.42) {
        quality -= 0.08;
        output = resizedCanvas.toDataURL('image/jpeg', quality);
      }
      canvas.width = resizedCanvas.width;
      canvas.height = resizedCanvas.height;
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(resizedCanvas, 0, 0);
    }

    return output;
  }

  async function dataUrlToBlob(dataUrl) {
    const response = await fetch(dataUrl);
    return await response.blob();
  }

  async function uploadPastedImage(dataUrl) {
    const blob = await dataUrlToBlob(dataUrl);
    const formData = new FormData();
    formData.append('action', 'upload_pasted_image');
    formData.append('image_file', blob, `note-image.${blob.type.includes('png') ? 'png' : 'jpg'}`);

    const response = await fetch(uploadActionUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
    });

    const result = await response.json().catch(() => null);
    if (!response.ok || !result || !result.success) {
      throw new Error(result?.message || '이미지를 업로드하지 못했습니다.');
    }

    return {
      filePath: result.file_path,
      previewUrl: result.preview_url || result.file_path,
    };
  }

  async function deletePastedImageFile(filePath) {
    if (!filePath) return;

    const formData = new FormData();
    formData.append('action', 'delete_pasted_image');
    formData.append('file_path', filePath);

    await fetch(uploadActionUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
    }).catch(() => {});
  }

  async function addPastedImage(src) {
    try {
      const compressed = await compressDataUrlImage(src);
      const uploadedImage = await uploadPastedImage(compressed);
      pastedImages.push(uploadedImage);
      setSelectedPreviewIndex(pastedImages.length - 1);
      renderPastePreview();
      insertNoteHtml(createNoteImageBlock(
        uploadedImage.previewUrl || uploadedImage.filePath || '',
        '첨부 이미지',
        '',
        uploadedImage.filePath || ''
      ));
    } catch (error) {
      alert(error instanceof Error ? error.message : '이미지를 처리하는 중 오류가 발생했습니다.');
    }
  }

  function readFilesAsImages(fileList) {
    [...fileList].forEach((file) => {
      if (!file.type.startsWith('image/')) {
        return;
      }
      const reader = new FileReader();
      reader.onload = async () => {
        await addPastedImage(reader.result);
      };
      reader.readAsDataURL(file);
    });
  }

  function getCanvasPoint(event) {
    if (!imageEditorCanvas) {
      return { x: 0, y: 0 };
    }
    const rect = imageEditorCanvas.getBoundingClientRect();
    const scaleX = imageEditorCanvas.width / rect.width;
    const scaleY = imageEditorCanvas.height / rect.height;
    return {
      x: (event.clientX - rect.left) * scaleX,
      y: (event.clientY - rect.top) * scaleY,
    };
  }

  function loadEditorImage(src) {
    if (!imageEditorCanvas) return;
    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = () => {
      const ctx = imageEditorCanvas.getContext('2d');
      imageEditorCanvas.width = image.naturalWidth;
      imageEditorCanvas.height = image.naturalHeight;
      ctx.clearRect(0, 0, imageEditorCanvas.width, imageEditorCanvas.height);
      ctx.drawImage(image, 0, 0);
      editorHistory = [];
    };
    image.onerror = () => {
      alert('이미지를 편집창에 불러오지 못했습니다.');
      closeImageEditor();
    };
    image.src = toAbsoluteAppUrl(src);
  }

  function pushEditorHistory() {
    if (!imageEditorCanvas) return;
    const ctx = imageEditorCanvas.getContext('2d');
    editorHistory.push(ctx.getImageData(0, 0, imageEditorCanvas.width, imageEditorCanvas.height));
    if (editorHistory.length > 20) {
      editorHistory.shift();
    }
  }

  function drawArrow(start, end) {
    if (!imageEditorCanvas) return;
    const ctx = imageEditorCanvas.getContext('2d');
    const headLength = 14 + Number(imageEditorSize?.value || 4);
    const angle = Math.atan2(end.y - start.y, end.x - start.x);

    ctx.beginPath();
    ctx.moveTo(start.x, start.y);
    ctx.lineTo(end.x, end.y);
    ctx.stroke();

    ctx.beginPath();
    ctx.moveTo(end.x, end.y);
    ctx.lineTo(
      end.x - headLength * Math.cos(angle - Math.PI / 6),
      end.y - headLength * Math.sin(angle - Math.PI / 6)
    );
    ctx.moveTo(end.x, end.y);
    ctx.lineTo(
      end.x - headLength * Math.cos(angle + Math.PI / 6),
      end.y - headLength * Math.sin(angle + Math.PI / 6)
    );
    ctx.stroke();
  }

  function addEditorText(point) {
    if (!imageEditorCanvas) return;
    const text = window.prompt('이미지 위에 넣을 문구를 입력해 주세요.');
    if (!text) return;

    pushEditorHistory();
    const ctx = imageEditorCanvas.getContext('2d');
    ctx.fillStyle = imageEditorColor?.value || '#d64545';
    ctx.font = `${Math.max(14, Number(imageEditorSize?.value || 4) * 4)}px "Malgun Gothic", sans-serif`;
    ctx.textBaseline = 'top';
    ctx.fillText(text, point.x, point.y);
  }

  function openImageEditor(index) {
    if (!imageEditorModal || !pastedImages[index]) {
      alert('편집할 이미지를 먼저 선택해 주세요.');
      return;
    }
    editingImageIndex = index;
    editorOriginalImageSrc = pastedImages[index].previewUrl || pastedImages[index].filePath || '';
    imageEditorModal.classList.add('is-open');
    loadEditorImage(editorOriginalImageSrc);
  }

  function closeImageEditor() {
    if (!imageEditorModal) return;
    imageEditorModal.classList.remove('is-open');
    editingImageIndex = -1;
    editorIsDrawing = false;
    editorStartPoint = null;
    editorSnapshot = null;
    editorHistory = [];
  }

  function drawShape(start, end, preview = false) {
    if (!imageEditorCanvas) return;
    const ctx = imageEditorCanvas.getContext('2d');
    const tool = imageEditorTool?.value || 'pen';

    ctx.strokeStyle = imageEditorColor?.value || '#d64545';
    ctx.lineWidth = Number(imageEditorSize?.value || 4);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    if (preview && editorSnapshot) {
      ctx.putImageData(editorSnapshot, 0, 0);
    }

    if (tool === 'rect') {
      ctx.strokeRect(start.x, start.y, end.x - start.x, end.y - start.y);
      return;
    }

    if (tool === 'arrow') {
      drawArrow(start, end);
      return;
    }

    if (tool === 'circle') {
      const radius = Math.hypot(end.x - start.x, end.y - start.y);
      ctx.beginPath();
      ctx.arc(start.x, start.y, radius, 0, Math.PI * 2);
      ctx.stroke();
      return;
    }

    ctx.beginPath();
    ctx.moveTo(start.x, start.y);
    ctx.lineTo(end.x, end.y);
    ctx.stroke();
  }

  function destroyImageEditorInstance() {
    if (imageEditorInstance && typeof imageEditorInstance.destroy === 'function') {
      try {
        imageEditorInstance.destroy();
      } catch (error) {
        console.warn('Failed to destroy image editor instance.', error);
      }
    }
    imageEditorInstance = null;
    if (imageEditorRoot) {
      imageEditorRoot.innerHTML = '';
    }
  }

  function resizeImageEditorUi() {
    if (!imageEditorInstance) {
      return;
    }
    const resizeEditor = imageEditorInstance?.ui?.resizeEditor;
    if (typeof resizeEditor === 'function') {
      try {
        resizeEditor.call(imageEditorInstance.ui);
      } catch (error) {
        console.warn('Failed to resize image editor UI.', error);
      }
    }
  }

  function queueImageEditorResize(delay = 80) {
    window.setTimeout(() => {
      resizeImageEditorUi();
    }, delay);
  }

  function createImageEditorTheme() {
    return {
      'common.bi.image': '',
      'common.backgroundImage': 'none',
      'common.backgroundColor': '#172133',
      'common.border': '0px',
      'header.backgroundImage': 'none',
      'header.backgroundColor': 'transparent',
      'header.border': '0px',
      'menu.normalIcon.color': '#93a4c7',
      'menu.activeIcon.color': '#ffffff',
      'menu.disabledIcon.color': '#5f718f',
      'menu.hoverIcon.color': '#ffbc42',
      'submenu.backgroundColor': '#0e1625',
      'submenu.partition.color': '#26344d',
      'submenu.normalIcon.color': '#8fa4c3',
      'submenu.activeIcon.color': '#ffffff',
      'submenu.normalLabel.color': '#c7d4ea',
      'submenu.activeLabel.color': '#ffffff',
      'range.pointer.color': '#ffbc42',
      'range.bar.color': '#2a3956',
      'range.subbar.color': '#ffbc42',
      'range.value.color': '#ffffff',
      'range.title.color': '#d9e5f6',
      'colorpicker.button.border': '1px solid #25344f',
      'colorpicker.title.color': '#ffffff',
    };
  }

  function destroyAdvancedImageEditorInstance() {
    if (advancedImageEditorInstance && typeof advancedImageEditorInstance.destroy === 'function') {
      try {
        advancedImageEditorInstance.destroy();
      } catch (error) {
        console.warn('Failed to destroy advanced image editor instance.', error);
      }
    }
    advancedImageEditorInstance = null;
    if (advancedImageEditorRoot) {
      advancedImageEditorRoot.innerHTML = '';
    }
  }

  function resizeAdvancedImageEditorUi() {
    if (!advancedImageEditorInstance) {
      return;
    }
    const resizeEditor = advancedImageEditorInstance?.ui?.resizeEditor;
    if (typeof resizeEditor === 'function') {
      try {
        resizeEditor.call(advancedImageEditorInstance.ui);
      } catch (error) {
        console.warn('Failed to resize advanced image editor UI.', error);
      }
    }
  }

  function queueAdvancedImageEditorResize(delay = 80) {
    window.setTimeout(() => {
      resizeAdvancedImageEditorUi();
    }, delay);
  }

  function getCurrentCanvasEditorSource() {
    if (!imageEditorCanvas || !imageEditorCanvas.width || !imageEditorCanvas.height) {
      return '';
    }
    try {
      return imageEditorCanvas.toDataURL('image/png');
    } catch (error) {
      return '';
    }
  }

  function clampAdvancedCropRect(rect) {
    if (!advancedImageEditorCanvas || !rect) {
      return null;
    }

    const maxWidth = advancedImageEditorCanvas.width;
    const maxHeight = advancedImageEditorCanvas.height;
    const left = Math.max(0, Math.min(maxWidth, rect.left));
    const top = Math.max(0, Math.min(maxHeight, rect.top));
    const right = Math.max(0, Math.min(maxWidth, rect.left + rect.width));
    const bottom = Math.max(0, Math.min(maxHeight, rect.top + rect.height));
    const width = Math.max(0, right - left);
    const height = Math.max(0, bottom - top);

    if (width < 2 || height < 2) {
      return null;
    }

    return { left, top, width, height };
  }

  function getAdvancedCanvasPoint(event) {
    if (!advancedImageEditorCanvas) {
      return { x: 0, y: 0 };
    }

    const rect = advancedImageEditorCanvas.getBoundingClientRect();
    const scaleX = advancedImageEditorCanvas.width / rect.width;
    const scaleY = advancedImageEditorCanvas.height / rect.height;
    return {
      x: Math.max(0, Math.min(advancedImageEditorCanvas.width, (event.clientX - rect.left) * scaleX)),
      y: Math.max(0, Math.min(advancedImageEditorCanvas.height, (event.clientY - rect.top) * scaleY)),
    };
  }

  function renderAdvancedCanvasEditor() {
    if (!advancedImageEditorCanvas || !advancedImageEditorContext || !advancedImageEditorImage) {
      return;
    }

    advancedImageEditorContext.clearRect(0, 0, advancedImageEditorCanvas.width, advancedImageEditorCanvas.height);
    advancedImageEditorContext.drawImage(
      advancedImageEditorImage,
      0,
      0,
      advancedImageEditorCanvas.width,
      advancedImageEditorCanvas.height
    );

    if (advancedImageEditorCropRect) {
      const cropRect = clampAdvancedCropRect(advancedImageEditorCropRect);
      if (!cropRect) {
        return;
      }
      advancedImageEditorContext.save();
      advancedImageEditorContext.fillStyle = 'rgba(0, 0, 0, 0.30)';
      advancedImageEditorContext.fillRect(0, 0, advancedImageEditorCanvas.width, advancedImageEditorCanvas.height);
      advancedImageEditorContext.drawImage(
        advancedImageEditorImage,
        cropRect.left,
        cropRect.top,
        cropRect.width,
        cropRect.height,
        cropRect.left,
        cropRect.top,
        cropRect.width,
        cropRect.height
      );
      advancedImageEditorContext.strokeStyle = '#ffbc42';
      advancedImageEditorContext.lineWidth = 2;
      advancedImageEditorContext.setLineDash([10, 6]);
      advancedImageEditorContext.strokeRect(cropRect.left, cropRect.top, cropRect.width, cropRect.height);
      advancedImageEditorContext.restore();
    }
  }

  function loadAdvancedEditorImage(src, { asOriginal = false, updateColorBase = false } = {}) {
    return new Promise((resolve, reject) => {
      if (!advancedImageEditorCanvas || !advancedImageEditorRoot) {
        reject(new Error('Advanced canvas editor is not ready.'));
        return;
      }

      const image = new Image();
      image.onload = () => {
        advancedImageEditorImage = image;
        advancedImageEditorCanvas.width = image.naturalWidth || image.width || 1;
        advancedImageEditorCanvas.height = image.naturalHeight || image.height || 1;
        advancedImageEditorCurrentDataUrl = src;
        if (asOriginal || advancedImageEditorOriginalDataUrl === '') {
          advancedImageEditorOriginalDataUrl = src;
        }
        if (asOriginal || updateColorBase || advancedImageEditorColorDataUrl === '') {
          advancedImageEditorColorDataUrl = src;
        }
        advancedImageEditorCropRect = null;
        advancedImageEditorCropStart = null;
        renderAdvancedCanvasEditor();
        resolve();
      };
      image.onerror = () => reject(new Error('Failed to load advanced editor image.'));
      image.src = src;
    });
  }

  async function replaceAdvancedEditorImageFromCanvas(renderer) {
    if (!advancedImageEditorImage) {
      return;
    }

    const tempCanvas = document.createElement('canvas');
    const size = renderer(tempCanvas, advancedImageEditorImage);
    if (size && Number.isFinite(size.width) && Number.isFinite(size.height)) {
      tempCanvas.width = Math.max(1, Math.round(size.width));
      tempCanvas.height = Math.max(1, Math.round(size.height));
      renderer(tempCanvas, advancedImageEditorImage, true);
    }

    const nextDataUrl = tempCanvas.toDataURL('image/png');
    await loadAdvancedEditorImage(nextDataUrl);
  }

  function ensureAdvancedCanvasEditorUi() {
    if (!advancedImageEditorRoot) {
      return null;
    }

    if (!advancedImageEditorRoot.querySelector('#advanced-image-editor-canvas')) {
      advancedImageEditorRoot.innerHTML = `
        <div class="advanced-canvas-editor">
          <div class="advanced-canvas-toolbar">
            <button type="button" class="btn-secondary" data-advanced-action="crop-toggle">자르기 선택</button>
            <button type="button" class="btn-secondary" data-advanced-action="crop-apply">자르기 적용</button>
            <button type="button" class="btn-secondary" data-advanced-action="rotate-left">왼쪽 회전</button>
            <button type="button" class="btn-secondary" data-advanced-action="rotate-right">오른쪽 회전</button>
            <button type="button" class="btn-secondary" data-advanced-action="flip-x">좌우 뒤집기</button>
            <button type="button" class="btn-secondary" data-advanced-action="flip-y">상하 뒤집기</button>
            <button type="button" class="btn-secondary" data-advanced-action="grayscale">흑백</button>
            <button type="button" class="btn-secondary" data-advanced-action="restore-color">원본색</button>
            <button type="button" class="btn-secondary" data-advanced-action="brighten">밝게</button>
            <button type="button" class="btn-secondary" data-advanced-action="darken">어둡게</button>
          </div>
          <div class="advanced-canvas-tip">자르기 선택 후 원하는 영역을 이미지 위에서 드래그하면 적용할 수 있습니다.</div>
          <div class="advanced-canvas-wrap">
            <canvas id="advanced-image-editor-canvas"></canvas>
          </div>
        </div>
      `;
    }

    advancedImageEditorCanvas = advancedImageEditorRoot.querySelector('#advanced-image-editor-canvas');
    advancedImageEditorContext = advancedImageEditorCanvas ? advancedImageEditorCanvas.getContext('2d') : null;
    if (!advancedImageEditorCanvas || !advancedImageEditorContext) {
      return null;
    }

    if (!advancedImageEditorCanvas.dataset.bound) {
      advancedImageEditorCanvas.dataset.bound = '1';
      advancedImageEditorCanvas.addEventListener('pointerdown', (event) => {
        if (!advancedImageEditorCropMode) {
          return;
        }
        const point = getAdvancedCanvasPoint(event);
        advancedImageEditorCropStart = point;
        advancedImageEditorCropRect = {
          left: point.x,
          top: point.y,
          width: 0,
          height: 0,
        };
        renderAdvancedCanvasEditor();
      });
      advancedImageEditorCanvas.addEventListener('pointermove', (event) => {
        if (!advancedImageEditorCropMode || !advancedImageEditorCropStart) {
          return;
        }
        const point = getAdvancedCanvasPoint(event);
        advancedImageEditorCropRect = {
          left: Math.min(advancedImageEditorCropStart.x, point.x),
          top: Math.min(advancedImageEditorCropStart.y, point.y),
          width: Math.abs(point.x - advancedImageEditorCropStart.x),
          height: Math.abs(point.y - advancedImageEditorCropStart.y),
        };
        renderAdvancedCanvasEditor();
      });
      const finishAdvancedCropSelection = () => {
        if (!advancedImageEditorCropMode) {
          return;
        }
        advancedImageEditorCropStart = null;
        advancedImageEditorCropRect = clampAdvancedCropRect(advancedImageEditorCropRect);
        renderAdvancedCanvasEditor();
      };
      advancedImageEditorCanvas.addEventListener('pointerup', finishAdvancedCropSelection);
      advancedImageEditorCanvas.addEventListener('pointerleave', finishAdvancedCropSelection);
    }

    advancedImageEditorRoot.querySelectorAll('[data-advanced-action]').forEach((button) => {
      if (button.dataset.bound) {
        return;
      }
      button.dataset.bound = '1';
      button.addEventListener('click', async () => {
        const action = button.getAttribute('data-advanced-action') || '';
        if (!advancedImageEditorImage) {
          return;
        }

        if (action === 'crop-toggle') {
          advancedImageEditorCropMode = !advancedImageEditorCropMode;
          if (!advancedImageEditorCropMode) {
            advancedImageEditorCropRect = null;
            advancedImageEditorCropStart = null;
          }
          renderAdvancedCanvasEditor();
          return;
        }

        if (action === 'crop-apply') {
          const cropRect = clampAdvancedCropRect(advancedImageEditorCropRect);
          if (!cropRect) {
            alert('잘라낼 영역을 먼저 선택해 주세요.');
            return;
          }
          const tempCanvas = document.createElement('canvas');
          tempCanvas.width = cropRect.width;
          tempCanvas.height = cropRect.height;
          const tempContext = tempCanvas.getContext('2d');
          tempContext.drawImage(
            advancedImageEditorImage,
            cropRect.left,
            cropRect.top,
            cropRect.width,
            cropRect.height,
            0,
            0,
            cropRect.width,
            cropRect.height
          );
          advancedImageEditorCropMode = false;
          advancedImageEditorCropRect = null;
          advancedImageEditorCropStart = null;
          await loadAdvancedEditorImage(tempCanvas.toDataURL('image/png'), { updateColorBase: true });
          return;
        }

        if (action === 'rotate-left' || action === 'rotate-right') {
          const rotateRight = action === 'rotate-right';
          const sourceWidth = advancedImageEditorImage.naturalWidth || advancedImageEditorImage.width;
          const sourceHeight = advancedImageEditorImage.naturalHeight || advancedImageEditorImage.height;
          const tempCanvas = document.createElement('canvas');
          tempCanvas.width = sourceHeight;
          tempCanvas.height = sourceWidth;
          const tempContext = tempCanvas.getContext('2d');
          tempContext.save();
          if (rotateRight) {
            tempContext.translate(sourceHeight, 0);
            tempContext.rotate(Math.PI / 2);
          } else {
            tempContext.translate(0, sourceWidth);
            tempContext.rotate(-Math.PI / 2);
          }
          tempContext.drawImage(advancedImageEditorImage, 0, 0);
          tempContext.restore();
          await loadAdvancedEditorImage(tempCanvas.toDataURL('image/png'), { updateColorBase: true });
          return;
        }

        if (action === 'flip-x' || action === 'flip-y') {
          const sourceWidth = advancedImageEditorImage.naturalWidth || advancedImageEditorImage.width;
          const sourceHeight = advancedImageEditorImage.naturalHeight || advancedImageEditorImage.height;
          const tempCanvas = document.createElement('canvas');
          tempCanvas.width = sourceWidth;
          tempCanvas.height = sourceHeight;
          const tempContext = tempCanvas.getContext('2d');
          tempContext.save();
          tempContext.translate(action === 'flip-x' ? sourceWidth : 0, action === 'flip-y' ? sourceHeight : 0);
          tempContext.scale(action === 'flip-x' ? -1 : 1, action === 'flip-y' ? -1 : 1);
          tempContext.drawImage(advancedImageEditorImage, 0, 0);
          tempContext.restore();
          await loadAdvancedEditorImage(tempCanvas.toDataURL('image/png'), { updateColorBase: true });
          return;
        }

        if (action === 'restore-color') {
          if (!advancedImageEditorColorDataUrl) {
            alert('복원할 컬러 이미지가 없습니다.');
            return;
          }
          advancedImageEditorCropMode = false;
          advancedImageEditorCropRect = null;
          advancedImageEditorCropStart = null;
          await loadAdvancedEditorImage(advancedImageEditorColorDataUrl);
          return;
        }

        if (action === 'grayscale' || action === 'brighten' || action === 'darken') {
          const sourceWidth = advancedImageEditorImage.naturalWidth || advancedImageEditorImage.width;
          const sourceHeight = advancedImageEditorImage.naturalHeight || advancedImageEditorImage.height;
          const tempCanvas = document.createElement('canvas');
          tempCanvas.width = sourceWidth;
          tempCanvas.height = sourceHeight;
          const tempContext = tempCanvas.getContext('2d');
          tempContext.drawImage(advancedImageEditorImage, 0, 0);
          const imageData = tempContext.getImageData(0, 0, sourceWidth, sourceHeight);
          const pixels = imageData.data;
          for (let i = 0; i < pixels.length; i += 4) {
            if (action === 'grayscale') {
              const gray = Math.round((pixels[i] + pixels[i + 1] + pixels[i + 2]) / 3);
              pixels[i] = gray;
              pixels[i + 1] = gray;
              pixels[i + 2] = gray;
            } else {
              const delta = action === 'brighten' ? 18 : -18;
              pixels[i] = Math.max(0, Math.min(255, pixels[i] + delta));
              pixels[i + 1] = Math.max(0, Math.min(255, pixels[i + 1] + delta));
              pixels[i + 2] = Math.max(0, Math.min(255, pixels[i + 2] + delta));
            }
          }
          tempContext.putImageData(imageData, 0, 0);
          await loadAdvancedEditorImage(
            tempCanvas.toDataURL('image/png'),
            { updateColorBase: action !== 'grayscale' }
          );
        }
      });
    });

    return advancedImageEditorCanvas;
  }

  function getPastedImageEditorUrl(index) {
    const image = pastedImages[index] || null;
    if (!image) {
      return '';
    }

    return toAbsoluteAppUrl(image.previewUrl || image.filePath || '');
  }

  async function getPastedImageEditorSource(index) {
    const directUrl = getPastedImageEditorUrl(index);
    if (directUrl === '') {
      return '';
    }

    try {
      const image = await loadImageElement(directUrl);
      const width = image.naturalWidth || image.width;
      const height = image.naturalHeight || image.height;
      if (!width || !height) {
        return directUrl;
      }

      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(image, 0, 0, width, height);
      return canvas.toDataURL('image/png');
    } catch (error) {
      return directUrl;
    }
  }

  async function getPreviewImageDataUrl(index) {
    if (!pastePreview) return '';
    const figures = Array.from(pastePreview.querySelectorAll('figure'));
    const figure = figures[index];
    const img = figure?.querySelector('img');
    if (!isImageNode(img) || !(img.complete) || !img.naturalWidth || !img.naturalHeight) {
      return '';
    }
    try {
      const canvas = document.createElement('canvas');
      canvas.width = img.naturalWidth;
      canvas.height = img.naturalHeight;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      return canvas.toDataURL('image/png');
    } catch (error) {
      return '';
    }
  }

  async function openImageEditor(index) {
    if (!imageEditorModal || !imageEditorRoot || !pastedImages[index]) {
      alert('편집할 이미지를 먼저 선택해 주세요.');
      return;
    }
    if (!window.tui || typeof window.tui.ImageEditor !== 'function') {
      alert('이미지 편집기를 불러오지 못했습니다.');
      return;
    }

    editingImageIndex = index;
    editorOriginalImageSrc = getPastedImageEditorUrl(index);
    if (!editorOriginalImageSrc) {
      alert('편집할 이미지 경로를 찾지 못했습니다.');
      return;
    }

    const openToken = ++imageEditorOpenToken;
    imageEditorModal.classList.add('is-open');
    destroyImageEditorInstance();
    imageEditorRoot.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#d9e5f6;font-weight:700;">이미지를 불러오는 중입니다...</div>';

    imageEditorInstance = new window.tui.ImageEditor(imageEditorRoot, {
      includeUI: {
        loadImage: {
          path: EMPTY_IMAGE_DATA_URL,
          name: 'loading-image',
        },
        locale: imageEditorLocale,
        menu: IMAGE_EDITOR_MENUS,
        initMenu: 'draw',
        menuBarPosition: 'bottom',
        theme: createImageEditorTheme(),
      },
      cssMaxWidth: 1080,
      cssMaxHeight: 720,
      selectionStyle: {
        cornerSize: 18,
        rotatingPointOffset: 70,
      },
      usageStatistics: false,
    });

    const currentEditorInstance = imageEditorInstance;
    try {
      const previewDataUrl = await getPreviewImageDataUrl(index);
      const editorSource = previewDataUrl || await getPastedImageEditorSource(index) || editorOriginalImageSrc || EMPTY_IMAGE_DATA_URL;
      if (openToken !== imageEditorOpenToken || imageEditorInstance !== currentEditorInstance || editingImageIndex !== index) {
        return;
      }

      await currentEditorInstance.loadImageFromURL(editorSource, 'risk-note-image');
      if (imageEditorInstance !== currentEditorInstance) {
        return;
      }
      if (typeof currentEditorInstance.clearUndoStack === 'function') {
        currentEditorInstance.clearUndoStack();
      }
      if (typeof currentEditorInstance.clearRedoStack === 'function') {
        currentEditorInstance.clearRedoStack();
      }
      queueImageEditorResize(30);
      queueImageEditorResize(140);
    } catch (error) {
      console.error('Failed to load image into TOAST UI Image Editor.', error);
      if (imageEditorInstance !== currentEditorInstance) {
        return;
      }
      alert('이미지를 편집기에 불러오지 못했습니다.');
      closeImageEditor();
      return;
    }

    queueImageEditorResize(40);
    queueImageEditorResize(180);
  }

  function closeImageEditor() {
    if (!imageEditorModal) {
      return;
    }
    imageEditorOpenToken += 1;
    imageEditorModal.classList.remove('is-open');
    destroyImageEditorInstance();
    editingImageIndex = -1;
    editorOriginalImageSrc = '';
  }

  function resetImageEditorToOriginal() {
    if (editingImageIndex < 0 || !editorOriginalImageSrc) {
      return;
    }
    openImageEditor(editingImageIndex);
  }

  async function saveEditedImage() {
    if (editingImageIndex < 0 || !imageEditorInstance) {
      closeImageEditor();
      return;
    }

    try {
      const previousImage = pastedImages[editingImageIndex] || null;
      const editedDataUrl = imageEditorInstance.toDataURL();
      const optimizedEditedImage = await compressDataUrlImage(editedDataUrl);
      const uploadedImage = await uploadPastedImage(optimizedEditedImage);
      pastedImages[editingImageIndex] = uploadedImage;
      setSelectedPreviewIndex(editingImageIndex);
      renderPastePreview();
      closeImageEditor();
      if (previousImage?.filePath) {
        syncNoteEditorImageUrl(
          previousImage.previewUrl || previousImage.filePath || '',
          uploadedImage.previewUrl || uploadedImage.filePath || ''
        );
        await deletePastedImageFile(previousImage.filePath);
      }
    } catch (error) {
      alert(error instanceof Error ? error.message : '수정한 이미지를 저장하지 못했습니다.');
    }
  }

  openImageEditor = async function(index) {
    if (!imageEditorModal || !imageEditorCanvas || !pastedImages[index]) {
      alert('편집할 이미지를 먼저 선택해 주세요.');
      return;
    }

    editingImageIndex = index;
    editorOriginalImageSrc = pastedImages[index].previewUrl || pastedImages[index].filePath || '';
    if (!editorOriginalImageSrc) {
      alert('편집할 이미지 경로를 찾지 못했습니다.');
      return;
    }

    imageEditorOpenToken += 1;
    imageEditorInstance = null;
    editorIsDrawing = false;
    editorStartPoint = null;
    editorSnapshot = null;
    editorHistory = [];
    imageEditorModal.classList.add('is-open');
    loadEditorImage(editorOriginalImageSrc);
  };

  closeImageEditor = function() {
    if (!imageEditorModal) {
      return;
    }

    imageEditorOpenToken += 1;
    closeAdvancedImageEditor();
    imageEditorModal.classList.remove('is-open');
    imageEditorInstance = null;
    editingImageIndex = -1;
    editorOriginalImageSrc = '';
    editorIsDrawing = false;
    editorStartPoint = null;
    editorSnapshot = null;
    editorHistory = [];
  };

  resetImageEditorToOriginal = function() {
    if (editingImageIndex < 0 || !editorOriginalImageSrc) {
      return;
    }

    loadEditorImage(editorOriginalImageSrc);
  };

  saveEditedImage = async function() {
    if (editingImageIndex < 0 || !imageEditorCanvas) {
      closeImageEditor();
      return;
    }

    try {
      const previousImage = pastedImages[editingImageIndex] || null;
      const optimizedEditedImage = await compressDataUrlImage(imageEditorCanvas.toDataURL('image/jpeg', 0.86));
      const uploadedImage = await uploadPastedImage(optimizedEditedImage);
      pastedImages[editingImageIndex] = uploadedImage;
      setSelectedPreviewIndex(editingImageIndex);
      renderPastePreview();
      closeImageEditor();
      if (previousImage?.filePath) {
        syncNoteEditorImageUrl(
          previousImage.previewUrl || previousImage.filePath || '',
          uploadedImage.previewUrl || uploadedImage.filePath || ''
        );
        await deletePastedImageFile(previousImage.filePath);
      }
    } catch (error) {
      alert(error instanceof Error ? error.message : '수정한 이미지를 저장하지 못했습니다.');
    }
  };

  async function getAdvancedEditorSource() {
    const canvasSource = getCurrentCanvasEditorSource();
    if (canvasSource !== '') {
      return canvasSource;
    }

    if (editingImageIndex < 0) {
      return '';
    }

    return await getPreviewImageDataUrl(editingImageIndex)
      || await getPastedImageEditorSource(editingImageIndex)
      || getPastedImageEditorUrl(editingImageIndex)
      || '';
  }

  async function openAdvancedImageEditor() {
    if (!advancedImageEditorModal || !advancedImageEditorRoot) {
      return;
    }

    if (!(window.tui && typeof window.tui.ImageEditor === 'function')) {
      alert('\uACE0\uAE09 \uC774\uBBF8\uC9C0 \uD3B8\uC9D1\uAE30\uB97C \uBD88\uB7EC\uC624\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
      return;
    }

    const source = await getAdvancedEditorSource();
    if (source === '') {
      alert('\uBA3C\uC800 \uC774\uBBF8\uC9C0\uB97C \uC5F4\uACE0 \uACE0\uAE09 \uB3C4\uAD6C\uB97C \uC0AC\uC6A9\uD574\uC8FC\uC138\uC694.');
      return;
    }

    advancedImageEditorSource = source;
    destroyAdvancedImageEditorInstance();
    advancedImageEditorRoot.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#d9e5f6;font-weight:700;">Loading advanced tools...</div>';
    advancedImageEditorModal.classList.add('is-open');
    await new Promise((resolve) => window.setTimeout(resolve, 30));

    if (!advancedImageEditorModal.classList.contains('is-open')) {
      return;
    }

    advancedImageEditorInstance = new window.tui.ImageEditor(advancedImageEditorRoot, {
      includeUI: {
        loadImage: {
          path: advancedImageEditorSource,
          name: 'advanced-note-image',
        },
        locale: imageEditorLocale,
        menu: ADVANCED_IMAGE_EDITOR_MENUS,
        initMenu: 'filter',
        menuBarPosition: 'bottom',
        theme: createImageEditorTheme(),
      },
      cssMaxWidth: 1180,
      cssMaxHeight: 780,
      selectionStyle: {
        cornerSize: 18,
        rotatingPointOffset: 70,
      },
      usageStatistics: false,
    });

    try {
      await advancedImageEditorInstance.loadImageFromURL(advancedImageEditorSource, 'advanced-note-image');
      if (typeof advancedImageEditorInstance.clearUndoStack === 'function') {
        advancedImageEditorInstance.clearUndoStack();
      }
      if (typeof advancedImageEditorInstance.clearRedoStack === 'function') {
        advancedImageEditorInstance.clearRedoStack();
      }
    } catch (error) {
      console.error('Failed to load advanced image editor source.', error);
      closeAdvancedImageEditor();
      alert('\uACE0\uAE09 \uD3B8\uC9D1\uAE30\uC5D0 \uD604\uC7AC \uC774\uBBF8\uC9C0\uB97C \uBD88\uB7EC\uC624\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
      return;
    }

    queueAdvancedImageEditorResize(60);
    queueAdvancedImageEditorResize(180);
  }

  function closeAdvancedImageEditor() {
    if (!advancedImageEditorModal) {
      return;
    }

    advancedImageEditorModal.classList.remove('is-open');
    destroyAdvancedImageEditorInstance();
    advancedImageEditorSource = '';
  }

  function resetAdvancedImageEditor() {
    if (advancedImageEditorSource === '') {
      return;
    }

    openAdvancedImageEditor();
  }

  async function applyAdvancedImageEditor() {
    if (!advancedImageEditorInstance) {
      closeAdvancedImageEditor();
      return;
    }

    try {
      const editedDataUrl = advancedImageEditorInstance.toDataURL();
      if (!editedDataUrl) {
        throw new Error('No edited image data.');
      }
      loadEditorImage(editedDataUrl);
      closeAdvancedImageEditor();
    } catch (error) {
      alert(error instanceof Error ? error.message : '\uACE0\uAE09 \uD3B8\uC9D1 \uACB0\uACFC\uB97C \uC801\uC6A9\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
    }
  }

  openAdvancedImageEditor = async function() {
    if (!advancedImageEditorModal || !advancedImageEditorRoot) {
      return;
    }

    const source = await getAdvancedEditorSource();
    if (source === '') {
      alert('먼저 이미지를 연 뒤 고급 편집기를 열어 주세요.');
      return;
    }

    advancedImageEditorModal.classList.add('is-open');
    ensureAdvancedCanvasEditorUi();
    advancedImageEditorCropMode = false;
    advancedImageEditorCropRect = null;
    advancedImageEditorCropStart = null;
    await loadAdvancedEditorImage(source, { asOriginal: true });
  };

  closeAdvancedImageEditor = function() {
    if (!advancedImageEditorModal) {
      return;
    }

    advancedImageEditorModal.classList.remove('is-open');
    advancedImageEditorCropMode = false;
    advancedImageEditorCropRect = null;
    advancedImageEditorCropStart = null;
    advancedImageEditorImage = null;
    advancedImageEditorColorDataUrl = '';
    advancedImageEditorCurrentDataUrl = '';
  };

  resetAdvancedImageEditor = function() {
    if (advancedImageEditorOriginalDataUrl === '') {
      return;
    }

    advancedImageEditorCropMode = false;
    advancedImageEditorCropRect = null;
    advancedImageEditorCropStart = null;
    loadAdvancedEditorImage(advancedImageEditorOriginalDataUrl, { asOriginal: true, updateColorBase: true });
  };

  applyAdvancedImageEditor = async function() {
    if (!advancedImageEditorCurrentDataUrl) {
      closeAdvancedImageEditor();
      return;
    }

    loadEditorImage(advancedImageEditorCurrentDataUrl);
    closeAdvancedImageEditor();
  };

  function openSelectedNoteImageEditor() {
    let selectedImage = getSelectedNoteImage();
    if (!isImageNode(selectedImage)) {
      selectedImage = getFallbackNoteImage();
      if (isImageNode(selectedImage)) {
        applyNoteImagePresentation(selectedImage);
        selectedNoteImageId = selectedImage.getAttribute('data-note-image-id') || selectedNoteImageId;
        lastSelectedNoteImagePath = getNoteImagePath(selectedImage);
        syncSelectedPreviewFromNoteImage(selectedImage, { render: true });
        syncSelectedNoteImageHighlight();
      }
    }

    if (!isImageNode(selectedImage)) {
      if (selectedPreviewIndex >= 0 && pastedImages[selectedPreviewIndex]) {
        openImageEditor(selectedPreviewIndex);
        return true;
      }
      if (pastedImages.length === 1) {
        setSelectedPreviewIndex(0);
        renderPastePreview();
        openImageEditor(0);
        return true;
      }
      alert('본문에서 이미지를 먼저 클릭해 선택해 주세요.');
      return false;
    }

    const imageIndex = syncSelectedPreviewFromNoteImage(selectedImage, { render: true });
    if (imageIndex < 0) {
      alert('본문 이미지와 연결된 첨부 사진을 찾지 못했습니다.');
      return false;
    }

    setSelectedPreviewIndex(imageIndex);
    renderPastePreview();
    openImageEditor(imageIndex);
    return true;
  }

  if (group1 && group1.options.length <= 1) {
    if (currentRole === 'manager') {
      const options = uniqueValues(targetOptions.map((item) => item.process_category));
      options.forEach((option) => {
        group1.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(option)}">${escapeHtml(displayProcessName(option))}</option>`);
      });
    } else {
      const options = uniqueValues(tasks.map((task) => task.unit_type));
      options.forEach((option) => {
        const label = tasks.find((task) => task.unit_type === option)?.unit_type_label || option;
        group1.insertAdjacentHTML('beforeend', `<option value="${option}">${escapeHtml(label)}</option>`);
      });
    }
  }

  if (currentRole === 'manager' && initialSelectedUnitRaId <= 0 && defaultManagerProcessCategory && group1 && !group1.value) {
    const normalizedDefaultManagerProcessCategory = defaultManagerProcessCategory.replace(/\s+/g, '');
    const matchedOption = Array.from(group1.options).find((option) => {
      const value = String(option.value || '').trim();
      return value === defaultManagerProcessCategory || value.replace(/\s+/g, '') === normalizedDefaultManagerProcessCategory;
    });
    if (matchedOption) {
      group1.value = matchedOption.value;
      onGroup1Change();
    }
  }

  initializeNoteEditor();

  if (noteHelpBox) {
    noteHelpBox.textContent = 'TOAST UI Editor로 본문을 직접 편집할 수 있습니다. 아래 첨부 이미지에서 넣을 사진을 고르거나, 본문 이미지를 더블클릭하면 이미지 편집기가 열립니다.';
  }

  if (noteUploadImageButton && noteImageFileInput) {
    noteUploadImageButton.addEventListener('click', () => {
      noteImageFileInput.click();
    });
    noteImageFileInput.addEventListener('change', () => {
      if (noteImageFileInput.files) {
        readFilesAsImages(noteImageFileInput.files);
      }
      noteImageFileInput.value = '';
    });
  }

  if (noteLinkImageButton) {
    noteLinkImageButton.addEventListener('click', () => {
      const url = window.prompt('삽입할 이미지 링크를 입력해 주세요.');
      if (!url) return;
      const caption = window.prompt('이미지 설명을 입력해 주세요. 비워두면 기본 문구가 들어갑니다.', '');
      insertNoteHtml(createNoteImageBlock(url.trim(), '링크 이미지', caption || ''));
    });
  }

  if (noteInsertSelectedImageButton) {
    noteInsertSelectedImageButton.addEventListener('click', () => {
      if (selectedPreviewIndex < 0 || !pastedImages[selectedPreviewIndex]) {
        alert('아래 첨부 이미지에서 본문에 넣을 사진을 먼저 선택해 주세요.');
        return;
      }
      const selectedImage = pastedImages[selectedPreviewIndex];
      const caption = window.prompt('사진 설명을 입력해 주세요. 비워두면 기본 문구가 들어갑니다.', '');
      insertNoteHtml(createNoteImageBlock(
        selectedImage.previewUrl || selectedImage.filePath || '',
        `첨부 이미지 ${selectedPreviewIndex + 1}`,
        caption || '',
        selectedImage.filePath || ''
      ));
    });
  }

  if (noteMarkImageButton) {
    noteMarkImageButton.classList.add('note-icon-button-wide', 'is-accent');
    noteMarkImageButton.title = '이미지 편집';
    noteMarkImageButton.setAttribute('aria-label', '이미지 편집');
    const hiddenLabel = noteMarkImageButton.querySelector('.note-visually-hidden');
    if (hiddenLabel) {
      hiddenLabel.textContent = '이미지 편집';
    }
    if (!noteMarkImageButton.querySelector('.note-short-label')) {
      noteMarkImageButton.insertAdjacentHTML('beforeend', '<span class="note-short-label">편집</span>');
    }
    noteMarkImageButton.addEventListener('click', () => {
      if (selectedPreviewIndex < 0) {
        if (openSelectedNoteImageEditor()) {
          return;
        }
        alert('아래 첨부 이미지에서 편집할 이미지를 먼저 선택해 주세요.');
        return;
      }
      openImageEditor(selectedPreviewIndex);
    });
  }

  if (noteImageSizeSmallButton) {
    noteImageSizeSmallButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      setSelectedNoteImageWidth(NOTE_IMAGE_SIZE_PRESETS.small);
    });
  }

  if (noteImageSizeMediumButton) {
    noteImageSizeMediumButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      setSelectedNoteImageWidth(NOTE_IMAGE_SIZE_PRESETS.medium);
    });
  }

  if (noteImageSizeLargeButton) {
    noteImageSizeLargeButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      setSelectedNoteImageWidth(NOTE_IMAGE_SIZE_PRESETS.large);
    });
  }

  if (noteImageSizeCustomButton) {
    noteImageSizeCustomButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      const selectedImage = getSelectedNoteImage();
      if (!isImageNode(selectedImage)) {
        alert('본문에서 이미지를 먼저 클릭해 선택해 주세요.');
        return;
      }

      const currentWidth = getNoteImageWidth(selectedImage);
      const answer = window.prompt('이미지 가로 크기를 %로 입력해 주세요. 예: 55', String(currentWidth));
      if (answer === null) {
        return;
      }

      const parsedWidth = Number.parseFloat(String(answer).replace('%', '').trim());
      if (!Number.isFinite(parsedWidth)) {
        alert('숫자만 입력해 주세요.');
        return;
      }

      setSelectedNoteImageWidth(parsedWidth);
    });
  }

  if (noteImageAlignLeftButton) {
    noteImageAlignLeftButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      setSelectedNoteImageAlign('left');
    });
  }

  if (noteImageAlignCenterButton) {
    noteImageAlignCenterButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      setSelectedNoteImageAlign('center');
    });
  }

  if (noteImageAlignRightButton) {
    noteImageAlignRightButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      setSelectedNoteImageAlign('right');
    });
  }

  if (noteImageMoveUpButton) {
    noteImageMoveUpButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      moveSelectedNoteImage('up');
    });
  }

  if (noteImageMoveDownButton) {
    noteImageMoveDownButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      moveSelectedNoteImage('down');
    });
  }

  if (pastePreview) {
    pastePreview.addEventListener('click', async (event) => {
      const button = event.target.closest('button[data-action]');
      if (!button) {
        const figure = event.target.closest('figure');
        if (!figure) {
          return;
        }
        const figures = Array.from(pastePreview.querySelectorAll('figure'));
        const index = figures.indexOf(figure);
        if (index < 0) {
          return;
        }
        setSelectedPreviewIndex(index);
        renderPastePreview();
        return;
      }

      const action = button.dataset.action;
      const index = Number(button.dataset.index);
      if (!Number.isInteger(index)) return;

      if (action === 'select') {
        setSelectedPreviewIndex(index);
        renderPastePreview();
        return;
      }

      if (action === 'mark') {
        setSelectedPreviewIndex(index);
        renderPastePreview();
        openImageEditor(index);
        return;
      }

      if (action === 'remove') {
        const removedImage = pastedImages[index] || null;
        pastedImages.splice(index, 1);
        if (selectedPreviewIndex >= pastedImages.length) {
          selectedPreviewIndex = pastedImages.length - 1;
        }
        renderPastePreview();
        if (removedImage?.filePath) {
          syncNoteEditorImageUrl(removedImage.previewUrl || removedImage.filePath || '', '');
          await deletePastedImageFile(removedImage.filePath);
        }
      }
    });

    pastePreview.addEventListener('dblclick', (event) => {
      const image = event.target.closest('img');
      if (!image) {
        return;
      }

      const figures = Array.from(pastePreview.querySelectorAll('figure'));
      const figure = image.closest('figure');
      const index = figures.indexOf(figure);
      if (index < 0) {
        return;
      }

      setSelectedPreviewIndex(index);
      renderPastePreview();
      openImageEditor(index);
    });
  }

  if (imageEditorCanvas) {
    imageEditorCanvas.addEventListener('pointerdown', (event) => {
      if ((imageEditorTool?.value || 'pen') === 'text') {
        addEditorText(getCanvasPoint(event));
        return;
      }
      pushEditorHistory();
      editorIsDrawing = true;
      editorStartPoint = getCanvasPoint(event);
      if ((imageEditorTool?.value || 'pen') !== 'pen') {
        editorSnapshot = imageEditorCanvas.getContext('2d').getImageData(0, 0, imageEditorCanvas.width, imageEditorCanvas.height);
      } else {
        editorSnapshot = null;
      }
    });

    imageEditorCanvas.addEventListener('pointermove', (event) => {
      if (!editorIsDrawing || !editorStartPoint) return;
      const point = getCanvasPoint(event);
      if ((imageEditorTool?.value || 'pen') === 'pen') {
        drawShape(editorStartPoint, point);
        editorStartPoint = point;
      } else {
        drawShape(editorStartPoint, point, true);
      }
    });

    const finishDrawing = (event) => {
      if (!editorIsDrawing || !editorStartPoint) return;
      const point = getCanvasPoint(event);
      if ((imageEditorTool?.value || 'pen') !== 'pen') {
        drawShape(editorStartPoint, point, true);
      }
      editorIsDrawing = false;
      editorStartPoint = null;
      editorSnapshot = null;
    };

    imageEditorCanvas.addEventListener('pointerup', finishDrawing);
    imageEditorCanvas.addEventListener('pointerleave', finishDrawing);
  }

  if (imageEditorUndo) {
    imageEditorUndo.addEventListener('click', () => {
      if (!imageEditorCanvas || editorHistory.length === 0) {
        return;
      }
      const ctx = imageEditorCanvas.getContext('2d');
      const lastState = editorHistory.pop();
      if (lastState) {
        ctx.putImageData(lastState, 0, 0);
      }
    });
  }

  if (imageEditorReset) {
    imageEditorReset.addEventListener('click', () => {
      resetImageEditorToOriginal();
    });
  }

  if (imageEditorCancel) {
    imageEditorCancel.addEventListener('click', closeImageEditor);
  }

  if (imageEditorModal) {
    imageEditorModal.addEventListener('click', (event) => {
      if (event.target === imageEditorModal) {
        closeImageEditor();
      }
    });
  }

  window.addEventListener('resize', () => {
    if (imageEditorModal?.classList.contains('is-open')) {
      queueImageEditorResize(60);
    }
    if (advancedImageEditorModal?.classList.contains('is-open')) {
      queueAdvancedImageEditorResize(60);
    }
  });

  if (imageEditorSave) {
    imageEditorSave.addEventListener('click', async () => {
      await saveEditedImage();
      return;
      if (editingImageIndex < 0 || !imageEditorCanvas) {
        closeImageEditor();
        return;
      }
      try {
        const previousImage = pastedImages[editingImageIndex] || null;
        const optimizedEditedImage = await compressDataUrlImage(imageEditorCanvas.toDataURL('image/jpeg', 0.86));
        const uploadedImage = await uploadPastedImage(optimizedEditedImage);
        pastedImages[editingImageIndex] = uploadedImage;
        setSelectedPreviewIndex(editingImageIndex);
        renderPastePreview();
        closeImageEditor();
        if (previousImage?.filePath) {
          syncNoteEditorImageUrl(
            previousImage.previewUrl || previousImage.filePath || '',
            uploadedImage.previewUrl || uploadedImage.filePath || ''
          );
          await deletePastedImageFile(previousImage.filePath);
        }
      } catch (error) {
        alert(error instanceof Error ? error.message : '수정한 이미지를 저장하지 못했습니다.');
      }
    });
  }

  if (imageEditorAdvanced) {
    imageEditorAdvanced.addEventListener('click', async () => {
      await openAdvancedImageEditor();
    });
  }

  if (advancedImageEditorReset) {
    advancedImageEditorReset.addEventListener('click', () => {
      resetAdvancedImageEditor();
    });
  }

  if (advancedImageEditorCancel) {
    advancedImageEditorCancel.addEventListener('click', () => {
      closeAdvancedImageEditor();
    });
  }

  if (advancedImageEditorApply) {
    advancedImageEditorApply.addEventListener('click', async () => {
      await applyAdvancedImageEditor();
    });
  }

  if (advancedImageEditorModal) {
    advancedImageEditorModal.addEventListener('click', (event) => {
      if (event.target === advancedImageEditorModal) {
        closeAdvancedImageEditor();
      }
    });
  }

  if (addSelectedTaskButton) {
    addSelectedTaskButton.addEventListener('click', () => {
      addCurrentSelectedTask();
    });
  }

  if (selectedTaskBox) {
    selectedTaskBox.addEventListener('click', (event) => {
      const clickTarget = event.target;
      if (!(clickTarget instanceof Element)) {
        return;
      }
      const removeButton = clickTarget.closest('button[data-action="remove-selected-unit"]');
      if (!removeButton) {
        return;
      }
      const unitRaId = Number.parseInt(String(removeButton.dataset.unitRaId || '0'), 10);
      removeSelectedTaskByUnitRaId(unitRaId);
    });
  }

  if (reportForm) {
    reportForm.addEventListener('submit', (event) => {
      if ((!selectedUnitRaIds || selectedUnitRaIds.length === 0) && currentSelectedTask && !currentSelectedTask.missing) {
        const selectedTaskUnitRaId = Number.parseInt(String(currentSelectedTask.unit_ra_id || '0'), 10);
        if (Number.isInteger(selectedTaskUnitRaId) && selectedTaskUnitRaId > 0) {
          selectedUnitRaIds.push(selectedTaskUnitRaId);
        }
      }
      syncSelectedUnitRaIdsField();
      if (!selectedUnitRaIds || selectedUnitRaIds.length === 0) {
        event.preventDefault();
        window.alert('기초 작업을 선택한 뒤 추가 버튼을 눌러주세요.');
        return;
      }
      if (noteHtmlInput) {
        syncNoteEditorField();
        noteHtmlInput.value = normalizeEditorHtmlImagePaths(noteHtmlInput.value || '', { forEditor: false });
      }
      if (pastedImagesInput) {
        pastedImagesInput.value = JSON.stringify(collectReferencedImagePathsFromEditor());
      }
    });
  }

  normalizeRenderedNoteImages();

  if (showEntryFormButton && reportForm) {
    showEntryFormButton.addEventListener('click', () => {
      const editUrl = String(showEntryFormButton.dataset.editUrl || '').trim();
      if (editUrl !== '') {
        window.location.assign(editUrl);
        return;
      }
      reportForm.classList.remove('is-hidden');
      showEntryFormButton.classList.add('is-hidden');
      reportForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  if (useEquipmentCheckbox) {
    useEquipmentCheckbox.addEventListener('change', syncEquipmentToolSection);
    syncEquipmentToolSection();
  }

  document.querySelectorAll('.major-work-checkbox').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      const majorWork = checkbox.dataset.majorWork || '';
      if (checkbox.checked) {
        syncAutoDetailCodeInput(String(checkbox.value || ''));
      }
      const subgroup = document.querySelector(`[data-major-work-subgroup="${CSS.escape(majorWork)}"]`);
      if (!subgroup) {
        refreshSelectedTaskBox();
        return;
      }
      subgroup.classList.toggle('is-hidden', !checkbox.checked);
      if (!checkbox.checked) {
        subgroup.querySelectorAll('input[type="checkbox"]').forEach((subCheckbox) => {
          subCheckbox.checked = false;
        });
      }
      refreshSelectedTaskBox();
    });
  });

  document.querySelectorAll('input[name="detail_tasks[]"]').forEach((input) => {
    input.addEventListener('change', () => {
      if (input.checked) {
        syncAutoDetailCodeInput(String(input.value || ''));
      }
      refreshSelectedTaskBox();
    });
  });

  document.querySelectorAll('input[name^="detail_codes["]').forEach((input) => {
    input.addEventListener('input', refreshSelectedTaskBox);
    input.addEventListener('change', refreshSelectedTaskBox);
  });

  const normalizeAutoLinkText = (value) => String(value || '')
    .replace(/[\s()>\-\/]+/g, '')
    .toLowerCase();

  const normalizeAutoLinkKeywords = (keywords) => (keywords || [])
    .map((keyword) => normalizeAutoLinkText(keyword))
    .filter(Boolean);

  const matchesAutoLinkKeywordSet = (normalizedValue, keywords) => {
    const normalizedKeywords = normalizeAutoLinkKeywords(keywords);
    if (!normalizedValue || normalizedKeywords.length === 0) {
      return false;
    }
    return normalizedKeywords.some((keyword) => normalizedValue.includes(keyword));
  };

  const toolAutoLinkRules = [
    {
      toolKeywords: ['용접기', '용접'],
      majorKeywords: ['화기작업', '불꽃작업'],
      subKeywords: ['용접작업'],
      enableToolToTask: true,
      enableTaskToTool: true,
    },
    {
      toolKeywords: ['산소절단기', '가스절단기', '가스절단'],
      majorKeywords: ['화기작업', '불꽃작업'],
      subKeywords: ['산소절단작업', '가스절단작업'],
      enableToolToTask: true,
      enableTaskToTool: true,
    },
    {
      toolKeywords: ['사다리'],
      majorKeywords: ['고소작업'],
      subKeywords: [
        '높이가 1.8m 이상의 사다리 작업',
        '높이가 1.8m 이상의 사다리작업',
        '높이가 1.8m 이상인 사다리작업',
      ],
      enableToolToTask: true,
      enableTaskToTool: true,
    },
    {
      toolKeywords: ['그라인더(유선)', '유선 전동드릴', '유선 해머드릴', '코어드릴', '발전기', '윈치', '고속절단기'],
      linkedToolKeywords: ['작업선'],
      enableToolToTask: false,
      enableTaskToTool: false,
      enableToolToTool: true,
    },
  ];

  const findMajorCheckboxByKeywords = (keywords) => {
    const normalizedKeywords = normalizeAutoLinkKeywords(keywords);
    if (normalizedKeywords.length === 0) return null;
    return Array.from(document.querySelectorAll('.major-work-checkbox')).find((checkbox) => {
      const majorWorkTitle = normalizeAutoLinkText(checkbox.dataset.majorWork || '');
      return normalizedKeywords.some((keyword) => majorWorkTitle.includes(keyword));
    }) || null;
  };

  const findSubCheckboxByKeywords = (majorKeywords, subKeywords) => {
    const normalizedMajorKeywords = normalizeAutoLinkKeywords(majorKeywords);
    const normalizedSubKeywords = normalizeAutoLinkKeywords(subKeywords);
    if (normalizedMajorKeywords.length === 0 || normalizedSubKeywords.length === 0) return null;

    return Array.from(document.querySelectorAll('.detail-sub-checkbox')).find((checkbox) => {
      const parentTitle = normalizeAutoLinkText(checkbox.dataset.parentTitle || '');
      const subTitle = normalizeAutoLinkText(checkbox.dataset.subTitle || '');
      return normalizedMajorKeywords.some((keyword) => parentTitle.includes(keyword))
        && normalizedSubKeywords.some((keyword) => subTitle.includes(keyword));
    }) || null;
  };

  const findToolCheckboxByKeywords = (keywords) => {
    const normalizedKeywords = (keywords || [])
      .map((keyword) => normalizeAutoLinkText(keyword))
      .filter(Boolean);
    if (normalizedKeywords.length === 0) return null;

    return Array.from(document.querySelectorAll('.detail-tool-checkbox')).find((checkbox) => {
      const toolTitle = normalizeAutoLinkText(checkbox.dataset.toolTitle || '');
      return normalizedKeywords.some((keyword) => toolTitle.includes(keyword));
    }) || null;
  };

  const applyToolAutoLink = (toolTitle) => {
    const normalizedToolTitle = normalizeAutoLinkText(toolTitle);
    if (!normalizedToolTitle) return;

    toolAutoLinkRules.forEach((rule) => {
      const matched = (rule.toolKeywords || []).some((keyword) => {
        return normalizedToolTitle.includes(normalizeAutoLinkText(keyword));
      });
      if (!matched) return;

      if (rule.enableToolToTask !== false) {
        const majorCheckbox = findMajorCheckboxByKeywords(rule.majorKeywords || []);
        if (majorCheckbox) {
          majorCheckbox.checked = true;
          majorCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const subCheckbox = findSubCheckboxByKeywords(rule.majorKeywords || [], rule.subKeywords || []);
        if (subCheckbox) {
          subCheckbox.checked = true;
          syncAutoDetailCodeInput(String(subCheckbox.value || ''));
        }
      }

      if (rule.enableToolToTool) {
        const linkedToolCheckbox = findToolCheckboxByKeywords(rule.linkedToolKeywords || []);
        if (linkedToolCheckbox) {
          linkedToolCheckbox.checked = true;
          syncAutoDetailCodeInput(String(linkedToolCheckbox.value || ''));
        }
      }
    });
  };

  const applyMajorSubAutoLink = (majorTitle, subTitle) => {
    const normalizedMajorTitle = normalizeAutoLinkText(majorTitle);
    const normalizedSubTitle = normalizeAutoLinkText(subTitle);
    if (!normalizedMajorTitle || !normalizedSubTitle) return;

    toolAutoLinkRules.forEach((rule) => {
      if (rule.enableTaskToTool === false) {
        return;
      }
      const isMatched = matchesAutoLinkKeywordSet(normalizedMajorTitle, rule.majorKeywords || [])
        && matchesAutoLinkKeywordSet(normalizedSubTitle, rule.subKeywords || []);
      if (!isMatched) return;

      const toolCheckbox = findToolCheckboxByKeywords(rule.toolKeywords || []);
      if (toolCheckbox) {
        toolCheckbox.checked = true;
        syncAutoDetailCodeInput(String(toolCheckbox.value || ''));
      }
    });
  };

  document.querySelectorAll('.detail-tool-checkbox').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      if (!checkbox.checked) {
        refreshSelectedTaskBox();
        return;
      }
      applyToolAutoLink(checkbox.dataset.toolTitle || '');
      refreshSelectedTaskBox();
    });
  });

  document.querySelectorAll('.detail-sub-checkbox').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      if (!checkbox.checked) {
        refreshSelectedTaskBox();
        return;
      }
      applyMajorSubAutoLink(
        checkbox.dataset.parentTitle || '',
        checkbox.dataset.subTitle || ''
      );
      refreshSelectedTaskBox();
    });
  });

  if (printSavedReportButton) {
    printSavedReportButton.addEventListener('click', () => {
      window.print();
    });
  }

  syncCheckedDetailCodeInputs();

  if (pastedImages.length > 0) {
    setSelectedPreviewIndex(0);
    renderPastePreview();
  }

  syncSelectedUnitRaIdsField();
  if (initialSelectedUnitRaId > 0 && !isAddMode) {
    const initialTask = tasks.find((item) => item.unit_ra_id === initialSelectedUnitRaId) || null;
    renderSelectedTask(initialTask);
  } else {
    renderSelectedTask(null);
  }

  // 날짜 입력 달력 아이콘 클릭 영역 불일치 해결:
  // indicator에 pointer-events:none을 주어 클릭이 input으로 통과되게 하고
  // input 클릭 시 showPicker()로 달력을 연다.
  document.querySelectorAll('input[type="date"]').forEach(function(input) {
    input.addEventListener('click', function() {
      try { this.showPicker(); } catch(e) {}
    });
  });
  </script>
</body>
</html>


