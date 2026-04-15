<?php
require_once __DIR__ . '/db.php';

/**
 * HTML 이스케이프
 */
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * CSRF 토큰
 */
function csrfToken() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        http_response_code(400);
        die('잘못된 요청입니다. (CSRF)');
    }
}

/**
 * 아차사고 기능 테이블/카테고리 보정
 */
function ensureNearMissSchema() {
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS near_miss_reports (
            id INT NOT NULL AUTO_INCREMENT,
            post_id INT NOT NULL,
            source_excel_id BIGINT DEFAULT NULL COMMENT '엑셀 원본 ID',
            source_written_at DATETIME DEFAULT NULL COMMENT '엑셀 작성 시간',
            incident_at DATETIME NOT NULL,
            location VARCHAR(200) NOT NULL,
            work_type VARCHAR(100) NOT NULL,
            risk_type VARCHAR(100) DEFAULT NULL,
            description TEXT NOT NULL,
            cause TEXT NOT NULL,
            action_taken TEXT NOT NULL,
            prevention_plan TEXT DEFAULT NULL,
            reporter_contact VARCHAR(100) DEFAULT NULL,
            status ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_post_id (post_id),
            UNIQUE KEY uk_source_excel_id (source_excel_id),
            KEY idx_incident_at (incident_at),
            KEY idx_status (status),
            CONSTRAINT fk_near_miss_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // 기존 DB에도 엑셀 동기화 컬럼/인덱스를 안전하게 추가
    $col = db()->query("SHOW COLUMNS FROM near_miss_reports LIKE 'source_excel_id'")->fetch();
    if (!$col) {
        db()->exec("ALTER TABLE near_miss_reports ADD COLUMN source_excel_id BIGINT DEFAULT NULL COMMENT '엑셀 원본 ID' AFTER post_id");
    }

    $col = db()->query("SHOW COLUMNS FROM near_miss_reports LIKE 'source_written_at'")->fetch();
    if (!$col) {
        db()->exec("ALTER TABLE near_miss_reports ADD COLUMN source_written_at DATETIME DEFAULT NULL COMMENT '엑셀 작성 시간' AFTER source_excel_id");
    }

    $idx = db()->query("SHOW INDEX FROM near_miss_reports WHERE Key_name = 'uk_source_excel_id'")->fetch();
    if (!$idx) {
        db()->exec("ALTER TABLE near_miss_reports ADD UNIQUE KEY uk_source_excel_id (source_excel_id)");
    }

    db()->prepare(
        "INSERT INTO categories (code, name, sort_order, write_role, is_active)
         VALUES ('near_miss', '아차사고', 5, 'user', 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active)"
    )->execute();

    db()->exec(
        "CREATE TABLE IF NOT EXISTS near_miss_photo_links (
            id INT NOT NULL AUTO_INCREMENT,
            post_id INT NOT NULL,
            report_id INT DEFAULT NULL,
            attachment_id INT NOT NULL,
            photo_key VARCHAR(80) NOT NULL,
            photo_role ENUM('situation','action_before','action_after','action','other') NOT NULL DEFAULT 'other',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_attachment_id (attachment_id),
            UNIQUE KEY uk_photo_key (photo_key),
            KEY idx_post_id (post_id),
            KEY idx_report_id (report_id),
            KEY idx_photo_role (photo_role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function nearMissCategoryId(): int {
    ensureNearMissSchema();
    $stmt = db()->prepare("SELECT id FROM categories WHERE code = 'near_miss' LIMIT 1");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

/**
 * (레거시 호환) 직원 목록 스키마 함수
 * HR 시스템(auth_accounts) 연동 방식에서는 별도 테이블이 필요하지 않음
 */
function ensureEmployeeSchema() {
    // no-op
}

/**
 * 팀별 직원 목록 조회
 */
function getEmployeeDirectory(): array {
    $out = [];
    if (function_exists('auth_accounts')) {
        $accounts = auth_accounts();
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $name = trim((string)($account['name'] ?? ''));
            $teamRaw = (string)($account['team'] ?? '');
            $team = function_exists('auth_normalize_team_name')
                ? auth_normalize_team_name($teamRaw)
                : trim($teamRaw);

            if ($name === '' || $team === '') {
                continue;
            }
            if (!isset($out[$team])) {
                $out[$team] = [];
            }
            if (!in_array($name, $out[$team], true)) {
                $out[$team][] = $name;
            }
        }
    } else {
        // fallback: users 캐시에서 생성
        $rows = db()->query(
            "SELECT dept, name
             FROM users
             WHERE TRIM(COALESCE(name, '')) <> ''"
        )->fetchAll();

        foreach ($rows as $row) {
            $team = trim((string)($row['dept'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($team === '' || $name === '') {
                continue;
            }
            if (!isset($out[$team])) {
                $out[$team] = [];
            }
            if (!in_array($name, $out[$team], true)) {
                $out[$team][] = $name;
            }
        }
    }

    foreach ($out as &$names) {
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    }
    unset($names);
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function employeeExists(string $teamName, string $employeeName): bool {
    $teamName = trim($teamName);
    $employeeName = trim($employeeName);
    if ($teamName === '' || $employeeName === '') {
        return false;
    }

    $directory = getEmployeeDirectory();
    return isset($directory[$teamName]) && in_array($employeeName, $directory[$teamName], true);
}

/**
 * 카테고리 목록 조회
 */
function getCategories() {
    static $cats = null;
    if ($cats === null) {
        $cats = db()->query("SELECT * FROM categories WHERE is_active=1 ORDER BY sort_order, id")->fetchAll();
    }
    return $cats;
}

function getCategoryById($id) {
    foreach (getCategories() as $c) {
        if ($c['id'] == $id) return $c;
    }
    return null;
}

function getCategoryByCode($code) {
    foreach (getCategories() as $c) {
        if ($c['code'] === $code) return $c;
    }
    return null;
}

/**
 * 상대 시간 표시 (방금 전, n분 전, n시간 전, 날짜)
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60) return '방금 전';
    if ($diff < 3600) return floor($diff / 60) . '분 전';
    if ($diff < 86400) return floor($diff / 3600) . '시간 전';
    if ($diff < 86400 * 7) return floor($diff / 86400) . '일 전';
    return date('Y-m-d', $time);
}

function dateFormat($datetime, $format = 'Y-m-d H:i') {
    return date($format, strtotime($datetime));
}

/**
 * 페이징 HTML 생성
 */
function paginate($total, $current, $perPage, $urlPattern) {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $current = max(1, min($totalPages, (int)$current));

    $html = '<div class="pagination">';
    $start = max(1, $current - 5);
    $end = min($totalPages, $current + 5);

    if ($current > 1) {
        $html .= '<a href="' . sprintf($urlPattern, 1) . '">«</a>';
        $html .= '<a href="' . sprintf($urlPattern, $current - 1) . '">‹</a>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current) {
            $html .= '<span class="current">' . $i . '</span>';
        } else {
            $html .= '<a href="' . sprintf($urlPattern, $i) . '">' . $i . '</a>';
        }
    }
    if ($current < $totalPages) {
        $html .= '<a href="' . sprintf($urlPattern, $current + 1) . '">›</a>';
        $html .= '<a href="' . sprintf($urlPattern, $totalPages) . '">»</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 파일 크기 포맷
 */
function formatBytes($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function normalizeUploadDirPath(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

function legacyUploadDir(): string {
    return normalizeUploadDirPath(__DIR__ . '/../uploads');
}

function categoryUploadDirMap(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $rawMap = [
        'free' => defined('BOARD_UPLOAD_DIR_CHAT') ? (string)BOARD_UPLOAD_DIR_CHAT : '',
        'qna' => defined('BOARD_UPLOAD_DIR_QNA') ? (string)BOARD_UPLOAD_DIR_QNA : '',
        'data' => defined('BOARD_UPLOAD_DIR_DATA') ? (string)BOARD_UPLOAD_DIR_DATA : '',
        'dwg' => defined('BOARD_UPLOAD_DIR_DWG') ? (string)BOARD_UPLOAD_DIR_DWG : '',
        'near_miss' => defined('BOARD_UPLOAD_DIR_NEAR_MISS') ? (string)BOARD_UPLOAD_DIR_NEAR_MISS : '',
    ];

    $map = [];
    foreach ($rawMap as $key => $path) {
        $normalized = normalizeUploadDirPath($path);
        if ($normalized !== '') {
            $map[$key] = $normalized;
        }
    }

    return $map;
}

function legacyCategoryUploadDirMap(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $rawMap = [
        'free' => 'A:\\risk_server\\uploads\\board-upload\\01_chat',
        'qna' => 'A:\\risk_server\\uploads\\board-upload\\02_Q&A',
        'data' => 'A:\\risk_server\\uploads\\board-upload\\03_data',
        'dwg' => 'A:\\risk_server\\uploads\\board-upload\\04_dwg',
        'near_miss' => 'A:\\risk_server\\uploads\\board-upload\\05_naer_miss',
    ];

    $map = [];
    foreach ($rawMap as $key => $path) {
        $normalized = normalizeUploadDirPath($path);
        if ($normalized !== '') {
            $map[$key] = $normalized;
        }
    }

    return $map;
}

function categoryUploadKey(string $code, string $name): ?string {
    $map = categoryUploadDirMap();
    $codeKey = strtolower(trim($code));

    if ($codeKey !== '') {
        if (isset($map[$codeKey])) {
            return $codeKey;
        }
        if (in_array($codeKey, ['drawing', 'cad', 'dwg_data'], true) && isset($map['dwg'])) {
            return 'dwg';
        }
    }

    $nameKey = trim($name);
    if ($nameKey === '') {
        return null;
    }
    $nameKey = preg_replace('/\s+/u', '', $nameKey) ?? $nameKey;
    $nameKey = function_exists('mb_strtolower')
        ? mb_strtolower($nameKey, 'UTF-8')
        : strtolower($nameKey);

    $aliases = [
        '자유게시판' => 'free',
        'q&a' => 'qna',
        'qna' => 'qna',
        '자료실' => 'data',
        '도면자료실' => 'dwg',
        '아차사고' => 'near_miss',
        'nearmiss' => 'near_miss',
    ];

    if (isset($aliases[$nameKey]) && isset($map[$aliases[$nameKey]])) {
        return $aliases[$nameKey];
    }

    return null;
}

function postCategoryMeta(int $postId): array {
    static $cache = [];
    if ($postId <= 0) {
        return ['code' => '', 'name' => ''];
    }
    if (isset($cache[$postId])) {
        return $cache[$postId];
    }

    $stmt = db()->prepare(
        "SELECT c.code, c.name
         FROM posts p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ?
         LIMIT 1"
    );
    $stmt->execute([$postId]);
    $row = $stmt->fetch();

    $cache[$postId] = [
        'code' => trim((string)($row['code'] ?? '')),
        'name' => trim((string)($row['name'] ?? '')),
    ];
    return $cache[$postId];
}

function getUploadDirForPostId(int $postId): string {
    $meta = postCategoryMeta($postId);
    $key = categoryUploadKey($meta['code'], $meta['name']);
    $map = categoryUploadDirMap();

    if ($key !== null && isset($map[$key])) {
        return $map[$key];
    }
    return legacyUploadDir();
}

function ensureUploadDir(string $uploadDir): void {
    if ($uploadDir === '') {
        return;
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $htaccess = $uploadDir . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "<FilesMatch \"\\.php[0-9s]?$|\\.phtml$|\\.phar$\">\n    Require all denied\n</FilesMatch>\n");
    }
}

function getAttachmentStoredPath(array $att): ?string {
    $storedName = basename((string)($att['stored_name'] ?? ''));
    if ($storedName === '') {
        return null;
    }

    $candidates = [];
    $postId = (int)($att['post_id'] ?? 0);
    if ($postId > 0) {
        $candidates[] = getUploadDirForPostId($postId) . $storedName;
    }

    foreach (categoryUploadDirMap() as $dir) {
        $candidates[] = $dir . $storedName;
    }
    foreach (legacyCategoryUploadDirMap() as $dir) {
        $candidates[] = $dir . $storedName;
    }
    $candidates[] = legacyUploadDir() . $storedName;

    foreach (array_unique($candidates) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function deleteAttachmentPhysicalFile(array $att): void {
    $path = getAttachmentStoredPath($att);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

function attachmentInlineUrl(array $att): string {
    return 'download.php?' . http_build_query(
        ['id' => (int)($att['id'] ?? 0), 'inline' => 1],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

/**
 * 첨부파일 업로드 처리
 */
function attachmentDownloadUrl(array $att): string {
    return 'download.php?' . http_build_query(
        ['id' => (int)($att['id'] ?? 0)],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function nearMissAttachmentDownloadAbsoluteUrl(array $att): string {
    $relative = attachmentDownloadUrl($att);
    if (PHP_SAPI === 'cli') {
        return $relative;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $relative;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)($_SERVER['HTTPS']) !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(dirname($scriptName), '/\\');
    if ($basePath === '.' || $basePath === '\\' || $basePath === '/') {
        $basePath = '';
    }

    return $scheme . '://' . $host . ($basePath !== '' ? $basePath . '/' : '/') . $relative;
}

function isImageAttachment(array $att): bool {
    $mime = strtolower(trim((string)($att['mime_type'] ?? '')));
    if ($mime !== '' && str_starts_with($mime, 'image/')) {
        return true;
    }

    $ext = strtolower(pathinfo((string)($att['original_name'] ?? ''), PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'], true);
}

function nearMissNormalizeText(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function nearMissPhotoRoleFromAttachmentName(string $originalName): string {
    $tag = '';
    if (preg_match('/^\[([^\]]+)\]/u', $originalName, $m)) {
        $tag = (string)($m[1] ?? '');
    }

    $normalizedTag = nearMissNormalizeText($tag);
    $normalizedName = nearMissNormalizeText($originalName);
    $target = $normalizedTag !== '' ? $normalizedTag : $normalizedName;

    if ($target === '') {
        return 'other';
    }

    if (str_contains($target, '상황')) {
        return 'situation';
    }

    if ((str_contains($target, '조치') && str_contains($target, '전')) || str_contains($target, 'before')) {
        return 'action_before';
    }

    if ((str_contains($target, '조치') && str_contains($target, '후')) || str_contains($target, 'after')) {
        return 'action_after';
    }

    if (str_contains($target, '조치') || str_contains($target, 'action')) {
        return 'action';
    }

    return 'other';
}

function nearMissPhotoRoleSortWeight(string $role): int {
    static $weights = [
        'situation' => 10,
        'action_before' => 20,
        'action_after' => 30,
        'action' => 40,
        'other' => 90,
    ];
    return $weights[$role] ?? 90;
}

function nearMissPhotoKey(int $postId, int $attachmentId): string {
    return 'NM-' . $postId . '-' . $attachmentId;
}

function isNearMissPost(int $postId): bool {
    if ($postId <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT 1
         FROM posts p
         JOIN categories c ON c.id = p.category_id
         WHERE p.id = ?
           AND c.code = 'near_miss'
         LIMIT 1"
    );
    $stmt->execute([$postId]);
    return (bool)$stmt->fetchColumn();
}

function getNearMissReportIdByPostId(int $postId): ?int {
    if ($postId <= 0) {
        return null;
    }
    $stmt = db()->prepare("SELECT id FROM near_miss_reports WHERE post_id = ? LIMIT 1");
    $stmt->execute([$postId]);
    $id = $stmt->fetchColumn();
    if ($id === false || $id === null) {
        return null;
    }
    return (int)$id;
}

function syncNearMissPhotoLinks(int $postId): void {
    if ($postId <= 0) {
        return;
    }

    ensureNearMissSchema();

    if (!isNearMissPost($postId)) {
        db()->prepare("DELETE FROM near_miss_photo_links WHERE post_id = ?")->execute([$postId]);
        return;
    }

    $attachments = getAttachments($postId);
    $rows = [];
    $roleSeq = [];
    foreach ($attachments as $att) {
        $attachmentId = (int)($att['id'] ?? 0);
        if ($attachmentId <= 0 || !isImageAttachment($att)) {
            continue;
        }

        $role = nearMissPhotoRoleFromAttachmentName((string)($att['original_name'] ?? ''));
        $roleSeq[$role] = ($roleSeq[$role] ?? 0) + 1;
        $rows[] = [
            'attachment_id' => $attachmentId,
            'photo_key' => nearMissPhotoKey($postId, $attachmentId),
            'photo_role' => $role,
            'sort_order' => nearMissPhotoRoleSortWeight($role) * 10000 + $roleSeq[$role],
        ];
    }

    if (empty($rows)) {
        db()->prepare("DELETE FROM near_miss_photo_links WHERE post_id = ?")->execute([$postId]);
        return;
    }

    $existingStmt = db()->prepare("SELECT attachment_id FROM near_miss_photo_links WHERE post_id = ?");
    $existingStmt->execute([$postId]);
    $existingIds = array_map('intval', array_column($existingStmt->fetchAll(), 'attachment_id'));
    $activeIds = array_map(static fn(array $row): int => (int)$row['attachment_id'], $rows);
    $deleteIds = array_values(array_diff($existingIds, $activeIds));

    if (!empty($deleteIds)) {
        $ph = implode(',', array_fill(0, count($deleteIds), '?'));
        $params = array_merge([$postId], $deleteIds);
        $deleteStmt = db()->prepare("DELETE FROM near_miss_photo_links WHERE post_id = ? AND attachment_id IN ($ph)");
        $deleteStmt->execute($params);
    }

    $reportId = getNearMissReportIdByPostId($postId);
    $upsert = db()->prepare(
        "INSERT INTO near_miss_photo_links
            (post_id, report_id, attachment_id, photo_key, photo_role, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            post_id = VALUES(post_id),
            report_id = VALUES(report_id),
            photo_key = VALUES(photo_key),
            photo_role = VALUES(photo_role),
            sort_order = VALUES(sort_order)"
    );

    foreach ($rows as $row) {
        $upsert->execute([
            $postId,
            $reportId,
            (int)$row['attachment_id'],
            (string)$row['photo_key'],
            (string)$row['photo_role'],
            (int)$row['sort_order'],
        ]);
    }
}

function syncAllNearMissPhotoLinks(): int {
    ensureNearMissSchema();

    $stmt = db()->query(
        "SELECT p.id
         FROM posts p
         JOIN categories c ON c.id = p.category_id
         WHERE c.code = 'near_miss'
         ORDER BY p.id"
    );
    $postIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    foreach ($postIds as $postId) {
        syncNearMissPhotoLinks($postId);
    }

    return count($postIds);
}

function getNearMissPhotoSummaryMap(array $postIds): array {
    ensureNearMissSchema();

    $ids = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn($id) => $id > 0)));
    if (empty($ids)) {
        return [];
    }

    $map = [];
    foreach ($ids as $postId) {
        $map[$postId] = [
            'photo_count' => 0,
            'photo_keys' => [],
            'photo_roles' => [],
            'photo_urls' => [],
        ];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT l.post_id, l.photo_key, l.photo_role, l.sort_order, a.id AS attachment_id,
                   a.original_name, a.stored_name, a.file_size, a.mime_type, a.post_id
            FROM near_miss_photo_links l
            JOIN attachments a ON a.id = l.attachment_id
            WHERE l.post_id IN ($ph)
            ORDER BY l.post_id ASC, l.sort_order ASC, l.attachment_id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $postId = (int)($row['post_id'] ?? 0);
        if ($postId <= 0 || !isset($map[$postId])) {
            continue;
        }
        $map[$postId]['photo_count']++;
        $map[$postId]['photo_keys'][] = (string)($row['photo_key'] ?? '');
        $map[$postId]['photo_roles'][] = (string)($row['photo_role'] ?? 'other');
        $map[$postId]['photo_urls'][] = nearMissAttachmentDownloadAbsoluteUrl($row);
    }

    return $map;
}

function handleUploads($postId, $files) {
    $allowed = explode(',', ALLOWED_EXTENSIONS);
    $blocked = explode(',', BLOCKED_EXTENSIONS);
    $uploadDir = getUploadDirForPostId((int)$postId);
    ensureUploadDir($uploadDir);

    $count = is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > MAX_UPLOAD_SIZE) continue;

        $origName = $files['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (in_array($ext, $blocked, true)) continue;
        if (!in_array($ext, $allowed, true)) continue;

        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $uploadDir . $stored;

        if (move_uploaded_file($files['tmp_name'][$i], $target)) {
            $stmt = db()->prepare(
                "INSERT INTO attachments (post_id, original_name, stored_name, file_size, mime_type)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $postId,
                $origName,
                $stored,
                $files['size'][$i],
                mime_content_type($target) ?: null,
            ]);
        }
    }
}

/**
 * 게시글의 첨부파일 목록
 */
function getAttachments($postId) {
    $stmt = db()->prepare("SELECT * FROM attachments WHERE post_id = ? ORDER BY id");
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

/**
 * 본문 안전 출력 (줄바꿈 보존, 링크 자동 변환)
 */
function renderContent($text) {
    $text = h($text);
    // URL 자동 링크
    $text = preg_replace(
        '#(https?://[^\s<]+)#i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $text
    );
    return nl2br($text);
}

/**
 * 검색용 본문 요약
 */
function summarize($text, $length = 100) {
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    if (mb_strlen($text) > $length) {
        $text = mb_substr($text, 0, $length) . '…';
    }
    return $text;
}

/**
 * 댓글 수 재계산
 */
function recalcCommentCount($postId) {
    $stmt = db()->prepare("UPDATE posts SET comment_count = (SELECT COUNT(*) FROM comments WHERE post_id = ? AND is_deleted = 0) WHERE id = ?");
    $stmt->execute([$postId, $postId]);
}

/**
 * 좋아요 수 재계산
 */
function recalcLikeCount($postId) {
    $stmt = db()->prepare("UPDATE posts SET like_count = (SELECT COUNT(*) FROM likes WHERE post_id = ?) WHERE id = ?");
    $stmt->execute([$postId, $postId]);
}
