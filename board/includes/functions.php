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
            KEY idx_incident_at (incident_at),
            KEY idx_status (status),
            CONSTRAINT fk_near_miss_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db()->prepare(
        "INSERT INTO categories (code, name, sort_order, write_role, is_active)
         VALUES ('near_miss', '아차사고', 5, 'user', 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active)"
    )->execute();

    $done = true;
}

/**
 * 인계사항 카테고리 보정 (가스팀 전용)
 */
function ensureHandoverCategory(): void {
    static $done = false;
    if ($done) {
        return;
    }

    // 도면자료실(dwg) 의 sort_order 바로 뒤에 위치하도록 설정
    db()->prepare(
        "INSERT INTO categories (code, name, sort_order, write_role, is_active)
         VALUES ('handover', '인계사항', 45, 'user', 1)
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
 * 카테고리 목록 조회
 */
function getCategories() {
    static $cats = null;
    if ($cats === null) {
        $cats = db()->query("SELECT * FROM categories WHERE is_active=1 ORDER BY sort_order, id")->fetchAll();
    }
    return $cats;
}

function ensureBoardNoticeTargetSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $stmt = db()->query("SHOW COLUMNS FROM posts LIKE 'notice_target_team'");
        $exists = (bool)$stmt->fetch();
        if (!$exists) {
            db()->exec("ALTER TABLE posts ADD COLUMN notice_target_team VARCHAR(100) NOT NULL DEFAULT 'ALL' AFTER is_notice");
            db()->exec("UPDATE posts SET notice_target_team = 'ALL' WHERE is_notice = 1 AND COALESCE(notice_target_team, '') = ''");
        }
    } catch (Throwable $e) {
        // ignore schema sync failure here; write/list code will continue best-effort
    }

    $done = true;
}

function board_notice_team_options(): array
{
    $teams = [];
    if (function_exists('auth_read_teams')) {
        foreach ((array)auth_read_teams() as $teamName) {
            $teamName = trim((string)$teamName);
            if ($teamName !== '') {
                $teams[] = $teamName;
            }
        }
    }

    return array_values(array_unique($teams));
}

function board_normalize_notice_target_team(?string $team): string
{
    $team = trim((string)$team);
    if ($team === '' || strcasecmp($team, 'ALL') === 0) {
        return 'ALL';
    }

    return $team;
}

function board_notice_visible_to_user(array $post, ?array $user): bool
{
    if (!(int)($post['is_notice'] ?? 0)) {
        return true;
    }

    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['admin', 'administrator'], true)) {
        return true;
    }

    $targetTeam = board_normalize_notice_target_team((string)($post['notice_target_team'] ?? 'ALL'));
    if ($targetTeam === 'ALL') {
        return true;
    }

    $currentDept = trim((string)($user['dept'] ?? ''));
    return $currentDept !== '' && $currentDept === $targetTeam;
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
 * 상대 시간 표시 (방금 전 / n분 전 / n시간 전 / 날짜)
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
 * 페이지네이션 HTML 생성
 */
function paginate($total, $current, $perPage, $urlPattern) {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $current = max(1, min($totalPages, (int)$current));

    $html = '<div class="pagination">';
    $start = max(1, $current - 5);
    $end = min($totalPages, $current + 5);

    if ($current > 1) {
        $html .= '<a href="' . sprintf($urlPattern, 1) . '">처음</a>';
        $html .= '<a href="' . sprintf($urlPattern, $current - 1) . '">이전</a>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current) {
            $html .= '<span class="current">' . $i . '</span>';
        } else {
            $html .= '<a href="' . sprintf($urlPattern, $i) . '">' . $i . '</a>';
        }
    }
    if ($current < $totalPages) {
        $html .= '<a href="' . sprintf($urlPattern, $current + 1) . '">다음</a>';
        $html .= '<a href="' . sprintf($urlPattern, $totalPages) . '">끝</a>';
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
 * 첨부파일이 이미지인지 확인
 */
function isImageAttachment(array $att): bool {
    $mime = strtolower((string)($att['mime_type'] ?? ''));
    if ($mime !== '' && str_starts_with($mime, 'image/')) {
        return true;
    }

    $ext = strtolower(pathinfo((string)($att['original_name'] ?? ''), PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'], true);
}

/**
 * 리치텍스트 인라인 HTML 화이트리스트 정제
 * 허용: b, i, u, s, br, span[style=color|font-size], font[size]
 */
function sanitizeRichtextInline(string $html): string {
    // Use DOMDocument to parse and rebuild with only whitelisted tags/attributes
    if ($html === '') return '';

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // Use a full HTML document with explicit charset declaration — more reliable
    // than LIBXML_HTML_NOIMPLIED on all platforms (esp. Windows libxml builds).
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>'
    );
    libxml_clear_errors();

    $allowedTags = ['b', 'i', 'u', 's', 'br', 'span', 'font'];

    $walk = static function (DOMNode $node) use (&$walk, $allowedTags): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) return '';

        /** @var DOMElement $node */
        $tag = strtolower($node->tagName);

        // Build children content first
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $walk($child);
        }

        // Transparent wrappers — just return children
        if (in_array($tag, ['html', 'head', 'body', 'div'], true)) {
            return $inner;
        }

        if (!in_array($tag, $allowedTags, true)) {
            // Strip tag, keep content
            return $inner;
        }

        if ($tag === 'br') return '<br>';

        $attrs = '';
        if ($tag === 'span') {
            $style = $node->getAttribute('style');
            $allowed = [];
            if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $m)) {
                $color = trim($m[1]);
                if (preg_match('/^#[0-9a-fA-F]{3,6}$|^rgb\(\d+,\s*\d+,\s*\d+\)$/', $color)) {
                    $allowed[] = "color:$color";
                }
            }
            if (preg_match('/(?:^|;)\s*font-size\s*:\s*([^;]+)/i', $style, $m)) {
                $size = trim($m[1]);
                if (preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $size)) {
                    $allowed[] = "font-size:$size";
                }
            }
            if ($allowed) $attrs = ' style="' . implode(';', $allowed) . '"';
        } elseif ($tag === 'font') {
            $size = $node->getAttribute('size');
            if (preg_match('/^[1-7]$/', $size)) $attrs = " size=\"$size\"";
        }

        return "<{$tag}{$attrs}>{$inner}</{$tag}>";
    };

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) return h($html);
    $result = '';
    foreach ($body->childNodes as $child) {
        $result .= $walk($child);
    }
    return $result;
}

/**
 * 리치텍스트 HTML 조각 안의 일반 URL을 링크로 변환
 */
function autoLinkHtmlFragment(string $html): string {
    if ($html === '') {
        return '';
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>'
    );
    libxml_clear_errors();

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) {
        return $html;
    }

    $pattern = '#https?://[^\s<]+#iu';

    $walk = static function (DOMNode $parent) use (&$walk, $doc, $pattern): void {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->nodeValue ?? '';
                if ($text === '' || !preg_match($pattern, $text)) {
                    continue;
                }

                $fragment = $doc->createDocumentFragment();
                $offset = 0;
                preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$url, $position]) {
                    $position = (int)$position;
                    $before = substr($text, $offset, $position - $offset);
                    if ($before !== '') {
                        $fragment->appendChild($doc->createTextNode($before));
                    }

                    $anchor = $doc->createElement('a');
                    $anchor->setAttribute('href', $url);
                    $anchor->setAttribute('target', '_blank');
                    $anchor->setAttribute('rel', 'noopener noreferrer');
                    $anchor->appendChild($doc->createTextNode($url));
                    $fragment->appendChild($anchor);

                    $offset = $position + strlen($url);
                }

                $tail = substr($text, $offset);
                if ($tail !== '') {
                    $fragment->appendChild($doc->createTextNode($tail));
                }

                $parent->replaceChild($fragment, $child);
                continue;
            }

            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) !== 'a') {
                $walk($child);
            }
        }
    };

    $walk($body);

    $result = '';
    foreach ($body->childNodes as $child) {
        $result .= $doc->saveHTML($child);
    }

    return $result;
}

/**
 * 본문 일반 텍스트 렌더링 (이스케이프 + URL 링크 + 줄바꿈)
 */
function renderPlainTextContent(string $text): string {
    $text = h($text);
    $text = preg_replace(
        '#(https?://[^\s<]+)#i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $text
    );
    return nl2br($text);
}

/**
 * 첨부 이미지 토큰 렌더링
 * 지원: [[첨부:1]], [[첨부:id:123]], [[첨부:파일명.jpg]]
 * 옵션: |align=left|center|right, |size=small|medium|large, |rotate=0|90|180|270, |flip=0|1
 */
function renderAttachmentToken(string $tokenValue, array $attachments): ?string {
    $tokenValue = trim($tokenValue);
    if ($tokenValue === '') {
        return null;
    }

    $parts = array_values(array_filter(array_map('trim', explode('|', $tokenValue)), static fn($v) => $v !== ''));
    $targetToken = $parts[0] ?? '';
    if ($targetToken === '') {
        return null;
    }

    $align = 'center';
    $size = 'medium';
    $rotate = 0;
    $flip = false;
    for ($i = 1, $n = count($parts); $i < $n; $i++) {
        if (!preg_match('/^(align|size|rotate|flip)\s*[:=]\s*(.+)$/i', $parts[$i], $m)) {
            continue;
        }
        $key = strtolower($m[1]);
        $value = strtolower(trim($m[2]));
        if ($key === 'align' && in_array($value, ['left', 'center', 'right'], true)) {
            $align = $value;
        }
        if ($key === 'size' && in_array($value, ['small', 'medium', 'large'], true)) {
            $size = $value;
        }
        if ($key === 'rotate' && is_numeric($value)) {
            $rotate = (int)$value;
        }
        if ($key === 'flip') {
            $flip = in_array($value, ['1', 'true', 'yes'], true);
        }
    }
    $rotate = (($rotate % 360) + 360) % 360;

    $imageAttachments = array_values(array_filter($attachments, 'isImageAttachment'));
    if (empty($imageAttachments)) {
        return null;
    }

    $target = null;
    if (preg_match('/^id:(\d+)$/i', $targetToken, $m)) {
        $targetId = (int)$m[1];
        foreach ($imageAttachments as $att) {
            if ((int)($att['id'] ?? 0) === $targetId) {
                $target = $att;
                break;
            }
        }
    } elseif (ctype_digit($targetToken)) {
        $index = (int)$targetToken;
        if ($index >= 1 && isset($imageAttachments[$index - 1])) {
            $target = $imageAttachments[$index - 1];
        }
    } else {
        // 파일명 토큰은 대소문자/경로 표기 차이를 허용해 매칭한다.
        $normalizedToken = trim($targetToken, " \t\n\r\0\x0B\"'");
        $normalizedToken = str_replace('\\', '/', $normalizedToken);
        $normalizedToken = basename($normalizedToken);
        $normalizedTokenLower = mb_strtolower($normalizedToken, 'UTF-8');

        foreach ($imageAttachments as $att) {
            $originalName = (string)($att['original_name'] ?? '');
            if ($originalName === $targetToken) {
                $target = $att;
                break;
            }

            $originalNameNorm = str_replace('\\', '/', $originalName);
            $originalNameNorm = basename($originalNameNorm);
            if (mb_strtolower($originalNameNorm, 'UTF-8') === $normalizedTokenLower) {
                $target = $att;
                break;
            }

            $storedName = (string)($att['stored_name'] ?? '');
            if ($storedName !== '' && mb_strtolower($storedName, 'UTF-8') === $normalizedTokenLower) {
                $target = $att;
                break;
            }
        }
    }

    if (!$target) {
        return null;
    }

    $src = attachmentInlineUrl($target);
    $downloadUrl = 'download.php?id=' . (int)$target['id'];
    $alt = h((string)$target['original_name']);
    $classes = 'inline-attachment-image align-' . h($align) . ' size-' . h($size);
    $scaleX = $flip ? -1 : 1;
    $imgStyle = 'transform:scaleX(' . $scaleX . ') rotate(' . $rotate . 'deg);transform-origin:center center;';

    return
        '<figure class="' . $classes . '">'
        . '<img src="' . h($src) . '" alt="' . $alt . '" loading="lazy" style="' . h($imgStyle) . '">'
        . '<figcaption><a href="' . h($downloadUrl) . '">' . $alt . '</a></figcaption>'
        . '</figure>';
}

/**
 * 본문 렌더링 (텍스트 + 첨부 이미지 토큰 치환)
 * <!--richtext--> 접두사가 있으면 인라인 HTML 보존 모드로 렌더링
 */
function renderContent($text, array $attachments = []) {
    $text = (string)$text;
    if ($text === '') {
        return '';
    }

    $richtextPrefix = '<!--richtext-->';
    $isRichtext = str_starts_with($text, $richtextPrefix);
    if ($isRichtext) {
        $text = substr($text, strlen($richtextPrefix));
    }

    $pattern = '/\[\[\s*첨부\s*:\s*(.+?)\s*\]\]/u';
    $hasTokens = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) && !empty($matches[0]);

    if (!$hasTokens) {
        return $isRichtext
            ? autoLinkHtmlFragment(sanitizeRichtextInline($text))
            : renderPlainTextContent($text);
    }

    $result = '';
    $cursor = 0;
    $count = count($matches[0]);
    for ($i = 0; $i < $count; $i++) {
        $fullMatch = $matches[0][$i][0];
        $fullPos = (int)$matches[0][$i][1];
        $tokenValue = (string)$matches[1][$i][0];

        $before = substr($text, $cursor, $fullPos - $cursor);
        if ($before !== '') {
            $result .= $isRichtext
                ? autoLinkHtmlFragment(sanitizeRichtextInline($before))
                : renderPlainTextContent($before);
        }

        $renderedImage = renderAttachmentToken($tokenValue, $attachments);
        if ($renderedImage !== null) {
            $result .= $renderedImage;
        } else {
            $fallback = "[[첨부:{$tokenValue}]]";
            $result .= $isRichtext ? h($fallback) : renderPlainTextContent($fallback);
        }

        $cursor = $fullPos + strlen($fullMatch);
    }

    $tail = substr($text, $cursor);
    if ($tail !== '') {
        $result .= $isRichtext
            ? autoLinkHtmlFragment(sanitizeRichtextInline($tail))
            : renderPlainTextContent($tail);
    }

    return $result;
}

/**
 * 검색용 본문 요약
 */
function summarize($text, $length = 100) {
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    if (mb_strlen($text) > $length) {
        $text = mb_substr($text, 0, $length) . '...';
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
