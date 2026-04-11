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

/**
 * 첨부파일 업로드 처리
 */
function handleUploads($postId, $files) {
    $allowed = explode(',', ALLOWED_EXTENSIONS);
    $blocked = explode(',', BLOCKED_EXTENSIONS);
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    // .htaccess로 PHP 실행 차단
    $htaccess = $uploadDir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "php_flag engine off\nAddType text/plain .php .phtml .php3 .php4 .php5 .phar\n");
    }

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
