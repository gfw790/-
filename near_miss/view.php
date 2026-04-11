<?php
require_once 'includes/header.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('잘못된 접근입니다.');
}

if (empty($_SESSION['viewed_posts'][$id])) {
    db()->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([$id]);
    $_SESSION['viewed_posts'][$id] = true;
}

$stmt = db()->prepare(
    "SELECT p.*, n.*
     FROM posts p
     JOIN near_miss_reports n ON n.post_id = p.id
     WHERE p.id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    die('아차사고 데이터를 찾을 수 없습니다.');
}

function extractCauseLine(string $text, string $label): string {
    $pattern = '/^' . preg_quote($label, '/') . '\s*:\s*(.*)$/mu';
    if (preg_match($pattern, $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

$attachments = getAttachments($id);
$situationPhotos = [];
$actionPhotos    = [];   // 전체 조치 사진 (토큰 렌더링용)
$beforePhotos    = [];   // 조치 전
$afterPhotos     = [];   // 조치 후

foreach ($attachments as $att) {
    $name = (string)$att['original_name'];
    if (strncmp($name, '[상황] ', strlen('[상황] ')) === 0) {
        $situationPhotos[] = $att;
    } elseif (strncmp($name, '[조치 전] ', strlen('[조치 전] ')) === 0) {
        $actionPhotos[] = $att;
        $beforePhotos[] = $att;
    } elseif (strncmp($name, '[조치 후] ', strlen('[조치 후] ')) === 0) {
        $actionPhotos[] = $att;
        $afterPhotos[] = $att;
    } elseif (strncmp($name, '[조치] ', strlen('[조치] ')) === 0) {
        // 이전 형식 호환: [조치] 조치 전_xxx / [조치] 조치 후_xxx
        $actionPhotos[] = $att;
        $stripped = substr($name, strlen('[조치] '));
        if (str_starts_with($stripped, '조치 전_')) {
            $beforePhotos[] = $att;
        } else {
            $afterPhotos[] = $att;
        }
    }
}

$postContent    = (string)($row['content'] ?? '');
$unsafeState    = extractCauseLine($postContent, '불안전한 상태');
$unsafeAction   = extractCauseLine($postContent, '불안전한 행동');
$carelessAction = extractCauseLine($postContent, '부주의 행동');
$carelessState  = extractCauseLine($postContent, '부주의 상태');
$incidentName   = extractCauseLine($postContent, '아차사고명');

function stripTagInView(string $name): string {
    foreach (['[상황] ', '[조치 전] ', '[조치 후] ', '[조치] '] as $prefix) {
        if (strncmp($name, $prefix, strlen($prefix)) === 0) {
            return substr($name, strlen($prefix));
        }
    }
    return $name;
}

function statusLabelView(string $status): string {
    if ($status === 'closed') return '완료';
    if ($status === 'in_progress') return '조치중';
    return '접수';
}

function renderPhotoGridView(array $photos): string {
    if (empty($photos)) {
        return '<div class="editor-help">첨부된 사진이 없습니다.</div>';
    }
    $html = '<div class="attach-photo-grid">';
    foreach ($photos as $att) {
        $html .= '<a class="attach-photo-item" href="download.php?id=' . (int)$att['id'] . '">';
        $html .= '<img src="' . h(attachmentInlineUrl($att)) . '" alt="' . h(stripTagInView($att['original_name'])) . '">';
        $html .= '<span>' . h(stripTagInView($att['original_name'])) . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

function sanitizeNearMissRichtextInline(string $html): string {
    if ($html === '') return '';

    $doc = new DOMDocument();
    $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
    libxml_use_internal_errors(true);
    $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $allowedTags = ['b', 'strong', 'i', 'em', 'u', 's', 'br', 'span', 'font'];

    $walk = static function (DOMNode $node) use (&$walk, $allowedTags): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) return '';

        /** @var DOMElement $node */
        $tag = strtolower($node->tagName);
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $walk($child);
        }

        if ($tag === 'div' || $tag === 'p' || $tag === 'body' || $tag === 'html') {
            return $inner . '<br>';
        }
        if (!in_array($tag, $allowedTags, true)) {
            return $inner;
        }
        if ($tag === 'br') {
            return '<br>';
        }

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
            if (!empty($allowed)) {
                $attrs = ' style="' . implode(';', $allowed) . '"';
            }
        } elseif ($tag === 'font') {
            $size = $node->getAttribute('size');
            if (preg_match('/^[1-7]$/', $size)) {
                $attrs = ' size="' . $size . '"';
            }
        }

        return "<{$tag}{$attrs}>{$inner}</{$tag}>";
    };

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return '';

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $walk($child);
    }
    return preg_replace('/(?:<br>\s*){3,}/i', '<br><br>', $result) ?? $result;
}

function renderAttachmentTokenNM(string $tokenValue, array $photos): ?string {
    $tokenValue = trim($tokenValue);
    if ($tokenValue === '') return null;

    $parts = array_values(array_filter(array_map('trim', explode('|', $tokenValue)), static fn($v) => $v !== ''));
    $targetToken = $parts[0] ?? '';
    if ($targetToken === '') return null;

    $align = 'center';
    $size = 'medium';
    $rotate = 0;
    $flip = false;
    for ($i = 1, $n = count($parts); $i < $n; $i++) {
        if (!preg_match('/^(align|size|rotate|flip)\s*[:=]\s*(.+)$/i', $parts[$i], $m)) continue;
        $key = strtolower($m[1]);
        $value = strtolower(trim($m[2]));
        if ($key === 'align' && in_array($value, ['left', 'center', 'right'], true)) $align = $value;
        if ($key === 'size' && in_array($value, ['small', 'medium', 'large'], true)) $size = $value;
        if ($key === 'rotate' && is_numeric($value)) $rotate = (int)$value;
        if ($key === 'flip') $flip = in_array($value, ['1', 'true', 'yes'], true);
    }
    $rotate = (($rotate % 360) + 360) % 360;

    $imagePhotos = array_values(array_filter($photos, static function (array $att): bool {
        $mime = strtolower((string)($att['mime_type'] ?? ''));
        if ($mime !== '' && str_starts_with($mime, 'image/')) return true;
        $ext = strtolower(pathinfo((string)($att['original_name'] ?? ''), PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'], true);
    }));
    if (empty($imagePhotos)) return null;

    $target = null;
    if (preg_match('/^id:(\d+)$/i', $targetToken, $m)) {
        $targetId = (int)$m[1];
        foreach ($imagePhotos as $att) {
            if ((int)($att['id'] ?? 0) === $targetId) { $target = $att; break; }
        }
    } elseif (ctype_digit($targetToken)) {
        $index = (int)$targetToken;
        if ($index >= 1 && isset($imagePhotos[$index - 1])) $target = $imagePhotos[$index - 1];
    } else {
        // 파일명 토큰: [태그] 접두사를 제거한 뒤 대소문자 무시 매칭
        $needle = mb_strtolower(trim($targetToken), 'UTF-8');
        foreach ($imagePhotos as $att) {
            $rawName = (string)($att['original_name'] ?? '');
            $strippedName = preg_replace('/^\[[^\]]+\]\s*/u', '', $rawName);
            if (mb_strtolower($strippedName, 'UTF-8') === $needle) { $target = $att; break; }
            $storedName = (string)($att['stored_name'] ?? '');
            if ($storedName !== '' && mb_strtolower($storedName, 'UTF-8') === $needle) { $target = $att; break; }
        }
    }
    if (!$target) return null;

    $src = attachmentInlineUrl($target);
    $downloadUrl = 'download.php?id=' . (int)$target['id'];
    $alt = h(stripTagInView((string)$target['original_name']));
    $classes = 'inline-attachment-image align-' . h($align) . ' size-' . h($size);
    $scaleX = $flip ? -1 : 1;
    $imgStyle = 'transform:scaleX(' . $scaleX . ') rotate(' . $rotate . 'deg);transform-origin:center center;';

    return '<figure class="' . $classes . '">'
         . '<img src="' . h($src) . '" alt="' . $alt . '" loading="lazy" style="' . h($imgStyle) . '">'
         . '<figcaption><a href="' . h($downloadUrl) . '">' . $alt . '</a></figcaption>'
         . '</figure>';
}

function renderActionWithPhotoTokens(string $text, array $situationPhotos, array $actionPhotos): string {
    $richPrefix = '<!--richtext-->';
    $isRich = false;
    if (str_starts_with($text, $richPrefix)) {
        $isRich = true;
        $text = substr($text, strlen($richPrefix));
    }

    $text = str_replace(['&#91;', '&#93;'], ['[', ']'], $text);
    // [[첨부:...]] (board 에디터) + [상황사진]/[조치사진] (기존 토큰) 동시 처리
    $tokenPattern = '/(\[\[\s*첨부\s*:\s*.+?\s*\]\]|\[[^\]\r\n]*상황사진[^\]\r\n]*\]|\[[^\]\r\n]*조치사진[^\]\r\n]*\])/u';
    $parts = preg_split($tokenPattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $isRich ? sanitizeNearMissRichtextInline($text) : nl2br(h($text));
    }

    $html = '';
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed !== '' && preg_match('/^\[\[\s*첨부\s*:\s*(.+?)\s*\]\]$/u', $trimmed, $m)) {
            $rendered = renderAttachmentTokenNM($m[1], $actionPhotos);
            $html .= $rendered ?? '';
            continue;
        }
        if ($trimmed !== '' && preg_match('/^\[[^\]]*상황사진[^\]]*\]$/u', $trimmed)) {
            $html .= renderPhotoGridView($situationPhotos);
            continue;
        }
        if ($trimmed !== '' && preg_match('/^\[[^\]]*조치사진[^\]]*\]$/u', $trimmed)) {
            $html .= renderPhotoGridView($actionPhotos);
            continue;
        }
        if ($part === '') {
            continue;
        }
        $html .= $isRich ? sanitizeNearMissRichtextInline($part) : nl2br(h($part));
    }

    return $html === '' ? '-' : $html;
}

$canEdit = $_currentUser && ($_currentUser['id'] === $row['author_id'] || $_currentUser['role'] === 'admin');
$actionTaken = (string)($row['action_taken'] ?? '');
$tokenScanText = str_replace(['&#91;', '&#93;'], ['[', ']'], $actionTaken);
$hasSituationTokenInAction = (bool)preg_match('/\[[^\]\r\n]*상황사진[^\]\r\n]*\]/u', $tokenScanText);
$hasActionTokenInAction = (bool)preg_match('/\[[^\]\r\n]*조치사진[^\]\r\n]*\]/u', $tokenScanText)
    || (bool)preg_match('/\[\[\s*첨부\s*:\s*id:\d+/u', $tokenScanText);
$pageTitle = '아차사고 상세';
?>

<h2 class="page-title">
    <span>아차사고 상세</span>
    <span class="sub"><a href="index.php">목록</a></span>
</h2>

<article class="post-view">
    <header class="post-view-head">
        <h1 class="title"><?= h($incidentName !== '' ? $incidentName : $row['location']) ?></h1>
        <div class="post-meta">
            <span class="meta-item">발생일시 <strong><?= dateFormat($row['incident_at'], 'Y-m-d H:i') ?></strong></span>
            <span class="meta-item">작업유형 <strong><?= h($row['work_type']) ?></strong></span>
            <span class="meta-item">상태 <strong><?= statusLabelView($row['status']) ?></strong></span>
            <span class="meta-item">조회 <strong><?= number_format($row['views']) ?></strong></span>
            <span class="meta-item">작성자 <strong><?= h($row['author_name']) ?></strong></span>
        </div>
    </header>

    <div class="post-view-body">
        <div class="detail-grid">
            <div class="detail-item">
                <h3>사고유형</h3>
                <p><?= $row['risk_type'] !== null && $row['risk_type'] !== '' ? h($row['risk_type']) : '-' ?></p>
            </div>
            <div class="detail-item">
                <h3>불안전한 상태</h3>
                <p><?= $unsafeState !== '' ? h($unsafeState) : '-' ?></p>
            </div>
            <div class="detail-item">
                <h3>불안전한 행동</h3>
                <p><?= $unsafeAction !== '' ? h($unsafeAction) : '-' ?></p>
            </div>
            <div class="detail-item">
                <h3>부주의한 행동</h3>
                <p><?= $carelessAction !== '' ? h($carelessAction) : '-' ?></p>
            </div>
            <div class="detail-item">
                <h3>부주의한 상태</h3>
                <p><?= $carelessState !== '' ? h($carelessState) : '-' ?></p>
            </div>
            <div class="detail-item">
                <h3>제보 구분</h3>
                <p><?= $row['reporter_contact'] !== null && $row['reporter_contact'] !== '' ? h($row['reporter_contact']) : '-' ?></p>
            </div>
            <div class="detail-item">
                <h3>상황 설명</h3>
                <p><?= nl2br(h($row['description'])) ?></p>
                <?php if (!empty($situationPhotos) && !$hasSituationTokenInAction): ?>
                    <?= renderPhotoGridView($situationPhotos) ?>
                <?php endif; ?>
            </div>
            <div class="detail-item">
                <h3>원인</h3>
                <p><?= nl2br(h($row['cause'])) ?></p>
            </div>
            <div class="detail-item">
                <h3>즉시 조치</h3>
                <div><?= renderActionWithPhotoTokens($actionTaken, $situationPhotos, $actionPhotos) ?></div>
                <?php if (!empty($actionPhotos) && !$hasActionTokenInAction): ?>
                    <?php if (!empty($beforePhotos)): ?>
                        <div class="action-photo-group">
                            <div class="action-photo-group-label">조치 전</div>
                            <?= renderPhotoGridView($beforePhotos) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($afterPhotos)): ?>
                        <div class="action-photo-group">
                            <div class="action-photo-group-label">조치 후</div>
                            <?= renderPhotoGridView($afterPhotos) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($beforePhotos) && empty($afterPhotos)): ?>
                        <?= renderPhotoGridView($actionPhotos) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="detail-item">
                <h3>재발 방지 대책</h3>
                <p><?= $row['prevention_plan'] !== null && $row['prevention_plan'] !== '' ? nl2br(h($row['prevention_plan'])) : '-' ?></p>
            </div>
        </div>
    </div>

    <div class="post-actions">
        <span></span>
        <div>
            <?php if ($canEdit): ?>
                <a href="write.php?id=<?= (int)$id ?>" class="btn btn-sm">수정</a>
            <?php endif; ?>
            <?php if ($_currentUser && $_currentUser['role'] === 'admin'): ?>
                <a href="delete.php?id=<?= (int)$id ?>&csrf=<?= h(csrfToken()) ?>"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('이 아차사고 게시물을 삭제하시겠습니까?\n삭제 후 복구할 수 없습니다.');">삭제</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-sm">목록</a>
        </div>
    </div>
</article>

<?php require_once 'includes/footer.php'; ?>
