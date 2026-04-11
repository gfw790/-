<?php
require_once 'includes/header.php';
$user = requireLogin();

function startsWithText(string $text, string $prefix): bool {
    return strncmp($text, $prefix, strlen($prefix)) === 0;
}

function stripTagPrefix(string $name): string {
    if (startsWithText($name, '[상황] ')) {
        return substr($name, strlen('[상황] '));
    }
    if (startsWithText($name, '[조치] ')) {
        return substr($name, strlen('[조치] '));
    }
    return $name;
}

function handleTaggedPhotoUploads(int $postId, array $files, string $tag, string $namePrefix = ''): void {
    $allowedImages = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $htaccess = $uploadDir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "php_flag engine off\nAddType text/plain .php .phtml .php3 .php4 .php5 .phar\n");
    }

    $count = isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        if (($files['size'][$i] ?? 0) > MAX_UPLOAD_SIZE) {
            continue;
        }

        $origName = (string)($files['name'][$i] ?? '');
        if ($origName === '') {
            continue;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedImages, true)) {
            continue;
        }

        // namePrefix가 지정되면 "조치 전_YYMMDDHHMM.ext" 형식으로 자동 변경
        $displayName = $namePrefix !== ''
            ? $namePrefix . '_' . date('ymdHi') . '.' . $ext
            : $origName;

        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $uploadDir . $stored;
        if (!move_uploaded_file($files['tmp_name'][$i], $target)) {
            continue;
        }

        $stmt = db()->prepare(
            "INSERT INTO attachments (post_id, original_name, stored_name, file_size, mime_type)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $postId,
            '[' . $tag . '] ' . $displayName,
            $stored,
            (int)$files['size'][$i],
            mime_content_type($target) ?: null,
        ]);
    }
}

function normalizeDateTimeInput(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) {
        $value .= ':00';
    }
    return $value;
}

function extractNearMissFieldFromContent(string $content, string $label): string {
    if ($content === '' || $label === '') {
        return '';
    }

    $quoted = preg_quote($label, '/');
    if (preg_match('/^' . $quoted . '\s*:\s*(.+)$/mu', $content, $m)) {
        return trim((string)$m[1]);
    }

    return '';
}

function normalizeSelectChoice(string $value, array $allowed): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return in_array($value, $allowed, true) ? $value : '';
}

function normalizeChecklistChoice(string $choice, string $other, array $allowed, string $otherLabel = '기타'): string {
    $choice = trim($choice);
    if ($choice === '' || !in_array($choice, $allowed, true)) {
        return '';
    }
    if ($choice !== $otherLabel) {
        return $choice;
    }
    $other = preg_replace('/\s+/u', ' ', $other);
    $other = trim((string)$other);
    if ($other === '') {
        return $otherLabel;
    }
    return $otherLabel . ': ' . $other;
}

function splitChecklistChoice(string $value, array $allowed, string $otherLabel = '기타'): array {
    $value = trim($value);
    if ($value === '' || $value === '-') {
        return ['choice' => '', 'other' => ''];
    }
    if (in_array($value, $allowed, true) && $value !== $otherLabel) {
        return ['choice' => $value, 'other' => ''];
    }
    if ($value === $otherLabel) {
        return ['choice' => $otherLabel, 'other' => ''];
    }
    $otherPattern = '/^' . preg_quote($otherLabel, '/') . '\s*[:\-]\s*(.+)$/u';
    if (preg_match($otherPattern, $value, $m)) {
        return ['choice' => $otherLabel, 'other' => trim((string)$m[1])];
    }
    if (in_array($otherLabel, $allowed, true)) {
        return ['choice' => $otherLabel, 'other' => $value];
    }
    return ['choice' => '', 'other' => ''];
}

function checklistSelected(string $storedValue, string $option, array $allowed, string $otherLabel = '기타'): bool {
    $parsed = splitChecklistChoice($storedValue, $allowed, $otherLabel);
    return $parsed['choice'] === $option;
}

function checklistOtherText(string $storedValue, array $allowed, string $otherLabel = '기타'): string {
    $parsed = splitChecklistChoice($storedValue, $allowed, $otherLabel);
    return $parsed['other'];
}

function extractEditorPlainText(string $value): string {
    $richPrefix = '<!--richtext-->';
    $text = (string)$value;
    if (str_starts_with($text, $richPrefix)) {
        $text = substr($text, strlen($richPrefix));
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $text = str_replace("\r", '', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim((string)$text);
}

function buildNearMissPostContent(array $form): string {
    $lines = [];
    $actionTakenText = extractEditorPlainText((string)($form['action_taken'] ?? ''));
    $lines[] = '발생일시: ' . $form['incident_at'];
    $lines[] = '아차사고명: ' . $form['incident_name'];
    $lines[] = '발생장소: ' . $form['location'];
    $lines[] = '작업유형: ' . $form['work_type'];
    $lines[] = '사고유형: ' . ($form['risk_type'] !== '' ? $form['risk_type'] : '-');
    $lines[] = '불안전한 상태: ' . ($form['unsafe_state'] !== '' ? $form['unsafe_state'] : '-');
    $lines[] = '불안전한 행동: ' . ($form['unsafe_action'] !== '' ? $form['unsafe_action'] : '-');
    $lines[] = '부주의 행동: ' . ($form['careless_action'] !== '' ? $form['careless_action'] : '-');
    $lines[] = '부주의 상태: ' . ($form['careless_state'] !== '' ? $form['careless_state'] : '-');
    $lines[] = '제보자: ' . $form['reporter_contact'];
    $lines[] = '';
    $lines[] = '';
    $lines[] = '[상황 설명]';
    $lines[] = $form['description'];
    $lines[] = '';
    $lines[] = '';
    $lines[] = '[원인]';
    $lines[] = $form['cause'];
    $lines[] = '';
    $lines[] = '';
    $lines[] = '[즉시 조치]';
    $lines[] = ($actionTakenText !== '' ? $actionTakenText : '-');
    $lines[] = '';
    $lines[] = '';
    $lines[] = '[재발 방지 대책]';
    $lines[] = ($form['prevention_plan'] !== '' ? $form['prevention_plan'] : '-');
    return implode("\n", $lines);
}

$editId = (int)($_GET['id'] ?? 0);
$nearMiss = null;
$error = '';

$situationAtts = [];
$beforeAtts = [];   // 조치 전 사진
$afterAtts  = [];   // 조치 후 사진
$currentTeam = trim((string)($user['dept'] ?? ''));
$currentName = trim((string)($user['name'] ?? ''));

$riskTypeOptions = [
    '추락', '전도', '충돌', '낙하', '비래', '붕괴', '협착', '감전', '요통', '화상/동상',
    '설비 사고', '유해물질 접촉', '화재/폭발', '익사', '절단/찰과상', '분류 불능', '차량사고', '기타',
];
$unsafeStateOptions = [
    '통로', '돌출부', '안전난간', '작업발판', '개구부', '전기', '운전장비', '설비', '정리정돈',
    '자연재해', '계단', '낙석', '폭설/결빙', '도구 및 장비', '동물/뱀/벌', '제3자', '조명/환경', '기타',
];
$unsafeActionOptions = [
    '규정위반', '도구의 잘못 사용', '보호구', '불안전한 자세', '소통 미비', '평소 습관', '기타',
];
$carelessActionOptions = [
    '위험경로에 위치함', '몸의 균형이 불안정해짐', '위험을 보지 않음', '위험을 생각하지 않음', '기타',
];
$carelessStateOptions = ['서두름', '피로', '스트레스', '자만심', '기타'];

if ($editId > 0) {
    $stmt = db()->prepare(
        "SELECT p.*, n.*
         FROM posts p
         JOIN near_miss_reports n ON n.post_id = p.id
         WHERE p.id = ?"
    );
    $stmt->execute([$editId]);
    $nearMiss = $stmt->fetch();
    if (!$nearMiss) {
        die('아차사고 데이터를 찾을 수 없습니다.');
    }
    if ($nearMiss['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
        die('수정 권한이 없습니다.');
    }

    $existingAtts = getAttachments($editId);
    foreach ($existingAtts as $att) {
        $name = (string)$att['original_name'];
        if (startsWithText($name, '[상황] ')) {
            $situationAtts[] = $att;
        } elseif (startsWithText($name, '[조치] ')) {
            $stripped = substr($name, strlen('[조치] '));
            if (str_starts_with($stripped, '조치 전_')) {
                $beforeAtts[] = $att;
            } else {
                $afterAtts[] = $att;
            }
        }
    }
}

$incidentNameDefault = '';
$nearMissContent = '';
if ($nearMiss) {
    $incidentNameDefault = trim((string)$nearMiss['title']);
    $incidentNameDefault = preg_replace('/^\\[[^\\]]+\\]\\s*/u', '', $incidentNameDefault) ?? $incidentNameDefault;
    $nearMissContent = (string)($nearMiss['content'] ?? '');
}

$form = [
    'incident_at' => $nearMiss['incident_at'] ?? date('Y-m-d H:i:00'),
    'incident_name' => $incidentNameDefault,
    'location' => $nearMiss['location'] ?? '',
    'work_type' => $nearMiss['work_type'] ?? '일반',
    'risk_type' => $nearMiss['risk_type'] ?? extractNearMissFieldFromContent($nearMissContent, '사고유형'),
    'unsafe_state' => extractNearMissFieldFromContent($nearMissContent, '불안전한 상태'),
    'unsafe_action' => extractNearMissFieldFromContent($nearMissContent, '불안전한 행동'),
    'careless_action' => extractNearMissFieldFromContent($nearMissContent, '부주의 행동'),
    'careless_state' => extractNearMissFieldFromContent($nearMissContent, '부주의 상태'),
    'description' => $nearMiss['description'] ?? '',
    'cause' => $nearMiss['cause'] ?? '',
    'action_taken' => $nearMiss['action_taken'] ?? '',
    'prevention_plan' => $nearMiss['prevention_plan'] ?? '',
    'reporter_team' => $nearMiss['author_dept'] ?? ($currentTeam !== '' ? $currentTeam : 'ETC'),
    'reporter_name' => $nearMiss['author_name'] ?? $currentName,
    'reporter_contact' => $nearMiss['reporter_contact'] ?? '',
    'status' => $nearMiss['status'] ?? 'open',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST['csrf'] ?? '');

    $form = [
        'incident_at' => normalizeDateTimeInput($_POST['incident_at'] ?? ''),
        'incident_name' => trim($_POST['incident_name'] ?? ''),
        'location' => trim($_POST['location'] ?? ''),
        'work_type' => trim($_POST['work_type'] ?? ''),
        'risk_type' => normalizeChecklistChoice($_POST['risk_type'] ?? '', $_POST['risk_type_other'] ?? '', $riskTypeOptions),
        'unsafe_state' => normalizeChecklistChoice($_POST['unsafe_state'] ?? '', $_POST['unsafe_state_other'] ?? '', $unsafeStateOptions),
        'unsafe_action' => normalizeChecklistChoice($_POST['unsafe_action'] ?? '', $_POST['unsafe_action_other'] ?? '', $unsafeActionOptions),
        'careless_action' => normalizeChecklistChoice($_POST['careless_action'] ?? '', $_POST['careless_action_other'] ?? '', $carelessActionOptions),
        'careless_state' => normalizeChecklistChoice($_POST['careless_state'] ?? '', $_POST['careless_state_other'] ?? '', $carelessStateOptions),
        'description' => trim($_POST['description'] ?? ''),
        'cause' => trim($_POST['cause'] ?? ''),
        'action_taken' => trim($_POST['action_taken'] ?? ''),
        'prevention_plan' => trim($_POST['prevention_plan'] ?? ''),
        'reporter_team' => trim($_POST['reporter_team'] ?? ''),
        'reporter_name' => trim($_POST['reporter_name'] ?? ''),
        'reporter_contact' => '',
        'status' => trim($_POST['status'] ?? 'open'),
    ];

    if ($form['work_type'] === '') {
        $form['work_type'] = '일반';
    }
    if ($form['status'] === '') {
        $form['status'] = 'open';
    }
    if ($form['reporter_team'] === '') {
        $form['reporter_team'] = 'ETC';
    }
    $form['reporter_contact'] = $form['reporter_team'] . ' / ' . $form['reporter_name'];
    $actionTakenPlain = extractEditorPlainText($form['action_taken']);

    if ($form['reporter_name'] === '' || $form['reporter_team'] === '') {
        $error = '소속/성명 정보를 확인할 수 없습니다. 다시 로그인해 주세요.';
    } elseif ($form['incident_at'] === '' || $form['incident_name'] === '' || $form['location'] === '' || $form['work_type'] === '' ||
        $form['description'] === '' || $form['cause'] === '' || $actionTakenPlain === '') {
        $error = '필수 항목을 모두 입력해 주세요.';
    } elseif (!in_array($form['status'], ['open', 'in_progress', 'closed'], true)) {
        $error = '상태 값이 올바르지 않습니다.';
    }

    if ($error === '') {
        try {
            db()->beginTransaction();

            $categoryId = nearMissCategoryId();
            $title = '[아차사고] ' . $form['incident_name'];
            $content = buildNearMissPostContent($form);

            if ($editId > 0) {
                db()->prepare(
                    "UPDATE posts
                     SET category_id = ?, title = ?, content = ?, author_name = ?, author_dept = ?
                     WHERE id = ?"
                )->execute([
                    $categoryId,
                    $title,
                    $content,
                    $form['reporter_name'],
                    $form['reporter_team'],
                    $editId
                ]);

                db()->prepare(
                    "UPDATE near_miss_reports
                     SET incident_at = ?, location = ?, work_type = ?, risk_type = ?,
                         description = ?, cause = ?, action_taken = ?, prevention_plan = ?,
                         reporter_contact = ?, status = ?
                     WHERE post_id = ?"
                )->execute([
                    $form['incident_at'],
                    $form['location'],
                    $form['work_type'],
                    $form['risk_type'] !== '' ? $form['risk_type'] : null,
                    $form['description'],
                    $form['cause'],
                    $form['action_taken'],
                    $form['prevention_plan'] !== '' ? $form['prevention_plan'] : null,
                    $form['reporter_contact'],
                    $form['status'],
                    $editId,
                ]);

                $postId = $editId;
            } else {
                db()->prepare(
                    "INSERT INTO posts (category_id, title, content, author_id, author_name, author_dept, is_notice)
                     VALUES (?, ?, ?, ?, ?, ?, 0)"
                )->execute([
                    $categoryId,
                    $title,
                    $content,
                    $user['id'],
                    $form['reporter_name'],
                    $form['reporter_team'],
                ]);
                $postId = (int)db()->lastInsertId();

                db()->prepare(
                    "INSERT INTO near_miss_reports
                     (post_id, incident_at, location, work_type, risk_type, description, cause,
                      action_taken, prevention_plan, reporter_contact, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $postId,
                    $form['incident_at'],
                    $form['location'],
                    $form['work_type'],
                    $form['risk_type'] !== '' ? $form['risk_type'] : null,
                    $form['description'],
                    $form['cause'],
                    $form['action_taken'],
                    $form['prevention_plan'] !== '' ? $form['prevention_plan'] : null,
                    $form['reporter_contact'],
                    $form['status'],
                ]);
            }

            if (!empty($_POST['delete_attachments'])) {
                $delIds = array_map('intval', $_POST['delete_attachments']);
                if (!empty($delIds)) {
                    $in = implode(',', array_fill(0, count($delIds), '?'));
                    $stmt = db()->prepare("SELECT * FROM attachments WHERE post_id = ? AND id IN ($in)");
                    $stmt->execute(array_merge([$postId], $delIds));
                    foreach ($stmt->fetchAll() as $att) {
                        @unlink(__DIR__ . '/uploads/' . $att['stored_name']);
                    }
                    $stmt = db()->prepare("DELETE FROM attachments WHERE post_id = ? AND id IN ($in)");
                    $stmt->execute(array_merge([$postId], $delIds));
                }
            }

            if (!empty($_FILES['situation_photos']['name'][0])) {
                handleTaggedPhotoUploads($postId, $_FILES['situation_photos'], '상황');
            }
            if (!empty($_FILES['action_before_photos']['name'][0])) {
                handleTaggedPhotoUploads($postId, $_FILES['action_before_photos'], '조치', '조치 전');
            }
            if (!empty($_FILES['action_photos']['name'][0])) {
                handleTaggedPhotoUploads($postId, $_FILES['action_photos'], '조치', '조치 후');
            }

            db()->commit();
            header('Location: view.php?id=' . $postId);
            exit;
        } catch (Throwable $e) {
            db()->rollBack();
            $error = '저장 중 오류가 발생했습니다.' . (DEBUG ? ' ' . $e->getMessage() : '');
        }
    }
}

$pageTitle = $editId > 0 ? '아차사고 수정' : '아차사고 작성';
$incidentAtInput = '';
if ($form['incident_at'] !== '') {
    $ts = strtotime($form['incident_at']);
    if ($ts !== false) {
        $incidentAtInput = date('Y-m-d\TH:i', $ts);
    }
}
?>

<h2 class="page-title">
    <span><?= h($pageTitle) ?></span>
</h2>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<form id="write-form" class="write-form survey-slider" data-survey-slider method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="reporter_team" value="<?= h($form['reporter_team']) ?>">
    <input type="hidden" name="reporter_name" value="<?= h($form['reporter_name']) ?>">
    <input type="hidden" name="work_type" value="<?= h($form['work_type']) ?>">
    <input type="hidden" name="status" value="<?= h($form['status']) ?>">

    <div class="survey-progress-wrap">
        <div class="survey-progress-bar"><span data-survey-progress></span></div>
        <div class="survey-progress-text" data-survey-progress-text></div>
    </div>

    <div class="survey-cards">
        <section class="survey-card is-active" data-survey-step>
            <h3 class="survey-card-title">1. 소속 / 성명</h3>
            <p class="survey-card-desc">소속/성명은 자동 적용됩니다.</p>

            <div class="survey-field">
                <label>소속</label>
                <input type="text" value="<?= h($form['reporter_team'] !== '' ? $form['reporter_team'] : '-') ?>" readonly tabindex="-1">
            </div>

            <div class="survey-field">
                <label>성명</label>
                <input type="text" value="<?= h($form['reporter_name'] !== '' ? $form['reporter_name'] : '-') ?>" readonly tabindex="-1">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">2. 아차사고명</h3>
            <div class="survey-field">
                <label>아차사고명 <span class="req">*</span></label>
                <input type="text" name="incident_name" maxlength="200" required value="<?= h($form['incident_name']) ?>" placeholder="예: 사다리 이동 중 미끄러짐">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">3. 발생일자 및 시간</h3>
            <div class="survey-field">
                <label>발생일시 <span class="req">*</span></label>
                <input type="datetime-local" name="incident_at" required value="<?= h($incidentAtInput) ?>">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">4. 발생장소</h3>
            <div class="survey-field">
                <label>발생장소 <span class="req">*</span></label>
                <input type="text" name="location" maxlength="200" required value="<?= h($form['location']) ?>" placeholder="예: 4P34 전기실 앞">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">5. 내용과 원인</h3>
            <div class="survey-field">
                <label>내용 <span class="req">*</span></label>
                <textarea name="description" required><?= h($form['description']) ?></textarea>
            </div>
            <div class="survey-field">
                <label>원인 <span class="req">*</span></label>
                <textarea name="cause" required><?= h($form['cause']) ?></textarea>
            </div>
            <div class="survey-field">
                <label>현장 사진</label>
                <div class="photo-upload-row">
                    <label class="btn btn-sm" for="situation_photos_file">사진 선택</label>
                    <input type="file" id="situation_photos_file" name="situation_photos[]" multiple accept="image/*" hidden>
                    <span class="photo-upload-hint" id="situation-file-hint">선택된 파일 없음</span>
                </div>
                <div class="photo-thumb-row" id="situation-new-thumbs"></div>
                <?php if (!empty($situationAtts)): ?>
                    <div class="file-list" style="margin-top:8px;">
                        <?php foreach ($situationAtts as $att): ?>
                            <span class="existing-file">
                                [상황] <?= h(stripTagPrefix($att['original_name'])) ?> (<?= formatBytes($att['file_size']) ?>)
                                <span class="del" data-attach-id="<?= (int)$att['id'] ?>">✕</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="editor-help">현장 상황 사진을 첨부하세요. (여러 장 선택 가능)</p>
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">6. 사고유형</h3>
            <section class="checklist-card-block">
                <div class="checklist-chip-list">
                    <?php foreach ($riskTypeOptions as $idx => $opt): ?>
                        <?php $id = 'risk_type_' . $idx; ?>
                        <label class="checklist-chip" for="<?= h($id) ?>">
                            <input type="radio" id="<?= h($id) ?>" name="risk_type" value="<?= h($opt) ?>" <?= checklistSelected($form['risk_type'], $opt, $riskTypeOptions) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="survey-field checklist-other-field" data-other-wrap="risk_type" <?= checklistSelected($form['risk_type'], '기타', $riskTypeOptions) ? '' : 'hidden' ?>>
                <label>기타 입력</label>
                <input type="text" name="risk_type_other" maxlength="80" value="<?= h(checklistOtherText($form['risk_type'], $riskTypeOptions)) ?>" placeholder="기타 사고유형 입력">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">7. 불안전한 상태</h3>
            <section class="checklist-card-block">
                <div class="checklist-chip-list">
                    <?php foreach ($unsafeStateOptions as $idx => $opt): ?>
                        <?php $id = 'unsafe_state_' . $idx; ?>
                        <label class="checklist-chip" for="<?= h($id) ?>">
                            <input type="radio" id="<?= h($id) ?>" name="unsafe_state" value="<?= h($opt) ?>" <?= checklistSelected($form['unsafe_state'], $opt, $unsafeStateOptions) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="survey-field checklist-other-field" data-other-wrap="unsafe_state" <?= checklistSelected($form['unsafe_state'], '기타', $unsafeStateOptions) ? '' : 'hidden' ?>>
                <label>기타 입력</label>
                <input type="text" name="unsafe_state_other" maxlength="80" value="<?= h(checklistOtherText($form['unsafe_state'], $unsafeStateOptions)) ?>" placeholder="기타 불안전한 상태 입력">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">8. 불안전한 행동</h3>
            <section class="checklist-card-block">
                <div class="checklist-chip-list">
                    <?php foreach ($unsafeActionOptions as $idx => $opt): ?>
                        <?php $id = 'unsafe_action_' . $idx; ?>
                        <label class="checklist-chip" for="<?= h($id) ?>">
                            <input type="radio" id="<?= h($id) ?>" name="unsafe_action" value="<?= h($opt) ?>" <?= checklistSelected($form['unsafe_action'], $opt, $unsafeActionOptions) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="survey-field checklist-other-field" data-other-wrap="unsafe_action" <?= checklistSelected($form['unsafe_action'], '기타', $unsafeActionOptions) ? '' : 'hidden' ?>>
                <label>기타 입력</label>
                <input type="text" name="unsafe_action_other" maxlength="80" value="<?= h(checklistOtherText($form['unsafe_action'], $unsafeActionOptions)) ?>" placeholder="기타 불안전한 행동 입력">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">9. 부주의한 행동</h3>
            <section class="checklist-card-block">
                <div class="checklist-chip-list">
                    <?php foreach ($carelessActionOptions as $idx => $opt): ?>
                        <?php $id = 'careless_action_' . $idx; ?>
                        <label class="checklist-chip" for="<?= h($id) ?>">
                            <input type="radio" id="<?= h($id) ?>" name="careless_action" value="<?= h($opt) ?>" <?= checklistSelected($form['careless_action'], $opt, $carelessActionOptions) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="survey-field checklist-other-field" data-other-wrap="careless_action" <?= checklistSelected($form['careless_action'], '기타', $carelessActionOptions) ? '' : 'hidden' ?>>
                <label>기타 입력</label>
                <input type="text" name="careless_action_other" maxlength="80" value="<?= h(checklistOtherText($form['careless_action'], $carelessActionOptions)) ?>" placeholder="기타 부주의한 행동 입력">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">10. 부주의한 상태</h3>
            <section class="checklist-card-block">
                <div class="checklist-chip-list">
                    <?php foreach ($carelessStateOptions as $idx => $opt): ?>
                        <?php $id = 'careless_state_' . $idx; ?>
                        <label class="checklist-chip" for="<?= h($id) ?>">
                            <input type="radio" id="<?= h($id) ?>" name="careless_state" value="<?= h($opt) ?>" <?= checklistSelected($form['careless_state'], $opt, $carelessStateOptions) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="survey-field checklist-other-field" data-other-wrap="careless_state" <?= checklistSelected($form['careless_state'], '기타', $carelessStateOptions) ? '' : 'hidden' ?>>
                <label>기타 입력</label>
                <input type="text" name="careless_state_other" maxlength="80" value="<?= h(checklistOtherText($form['careless_state'], $carelessStateOptions)) ?>" placeholder="기타 부주의한 상태 입력">
            </div>
        </section>

        <section class="survey-card" data-survey-step>
            <h3 class="survey-card-title">11. 사고 방지를 위해 이렇게 조치하였습니다.</h3>

            <div class="survey-field">
                <label>조치 내용 <span class="req">*</span></label>
                <textarea id="content" name="action_taken" required hidden><?= h($form['action_taken']) ?></textarea>
                <div id="content-editor" class="content-editor" contenteditable="true" hidden></div>
                <div class="editor-help">텍스트와 이미지를 함께 편집할 수 있습니다. 조치사진을 첨부하면 본문에 삽입할 수 있습니다.</div>
            </div>

            <div class="survey-field">
                <label>조치사진 첨부</label>
                <div class="nm-photo-split">

                    <div class="nm-photo-group">
                        <div class="nm-photo-group-label">조치 전 사진</div>
                        <label class="btn btn-sm nm-file-btn" for="nm-before-photos">파일 선택</label>
                        <input type="file" id="nm-before-photos" name="action_before_photos[]" multiple accept="image/*" class="nm-hidden-file">
                        <div class="editor-help">최대 <?= formatBytes(MAX_UPLOAD_SIZE) ?> · 이미지 선택 시 본문에 삽입 가능</div>
                        <div id="nm-before-token-list" class="file-token-list"></div>
                        <?php if (!empty($beforeAtts)): ?>
                            <div class="file-list">
                                <?php foreach ($beforeAtts as $att): ?>
                                    <span class="existing-file"
                                          data-attach-id="<?= (int)$att['id'] ?>"
                                          data-original-name="<?= h($att['original_name']) ?>"
                                          data-is-image="1">
                                        <?= h(stripTagPrefix($att['original_name'])) ?> (<?= formatBytes($att['file_size']) ?>)
                                        <button type="button" class="insert-attachment-token"
                                                data-token="<?= h('[[첨부:id:' . (int)$att['id'] . ']]') ?>"
                                                title="본문에 이미지 삽입">본문삽입</button>
                                        <span class="del" data-attach-id="<?= (int)$att['id'] ?>">×</span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="nm-photo-group">
                        <div class="nm-photo-group-label">조치 후 사진</div>
                        <label class="btn btn-sm nm-file-btn" for="attachments">파일 선택</label>
                        <input type="file" id="attachments" name="action_photos[]" multiple accept="image/*" class="nm-hidden-file">
                        <div class="editor-help">최대 <?= formatBytes(MAX_UPLOAD_SIZE) ?> · 이미지 선택 시 본문에 삽입 가능</div>
                        <div id="new-attachment-token-list" class="file-token-list"></div>
                        <?php if (!empty($afterAtts)): ?>
                            <div class="file-list">
                                <?php foreach ($afterAtts as $att): ?>
                                    <span class="existing-file"
                                          data-attach-id="<?= (int)$att['id'] ?>"
                                          data-original-name="<?= h($att['original_name']) ?>"
                                          data-is-image="1">
                                        <?= h(stripTagPrefix($att['original_name'])) ?> (<?= formatBytes($att['file_size']) ?>)
                                        <button type="button" class="insert-attachment-token"
                                                data-token="<?= h('[[첨부:id:' . (int)$att['id'] . ']]') ?>"
                                                title="본문에 이미지 삽입">본문삽입</button>
                                        <span class="del" data-attach-id="<?= (int)$att['id'] ?>">×</span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <div class="survey-field">
                <label>추가 재발방지 메모</label>
                <textarea name="prevention_plan"><?= h($form['prevention_plan']) ?></textarea>
            </div>
        </section>
    </div>

    <div class="survey-actions">
        <button type="button" class="btn" data-survey-prev>이전</button>
        <div class="survey-actions-right">
            <a href="<?= $editId > 0 ? 'view.php?id=' . $editId : 'index.php' ?>" class="btn">취소</a>
            <button type="button" class="btn" data-survey-next>다음</button>
            <button type="submit" class="btn btn-primary" data-survey-submit style="display:none;"><?= $editId > 0 ? '수정 완료' : '등록' ?></button>
        </div>
    </div>
</form>

<script>
(function () {
    // 체크리스트 '기타' 입력 토글
    var otherGroups = ['risk_type', 'unsafe_state', 'unsafe_action', 'careless_action', 'careless_state'];
    otherGroups.forEach(function (groupName) {
        var radios = Array.prototype.slice.call(document.querySelectorAll('input[type="radio"][name="' + groupName + '"]'));
        var wrap = document.querySelector('[data-other-wrap="' + groupName + '"]');
        if (!radios.length || !wrap) return;
        var input = wrap.querySelector('input[type="text"]');
        if (!input) return;

        var sync = function () {
            var selected = radios.find(function (r) { return r.checked; });
            var show = !!selected && selected.value === '기타';
            wrap.hidden = !show;
            input.disabled = !show;
            input.required = show;
            if (!show) input.value = '';
        };
        radios.forEach(function (radio) { radio.addEventListener('change', sync); });
        sync();
    });

    // 상황사진 업로드 (Step 5) - 썸네일 미리보기
    function bindThumb(img, file) {
        if (!img || !file) return;
        var useDataUrl = function () {
            if (!window.FileReader) return;
            var reader = new FileReader();
            reader.onload = function (e) { img.src = String((e && e.target && e.target.result) || ''); };
            reader.readAsDataURL(file);
        };
        if (window.URL && typeof window.URL.createObjectURL === 'function') {
            try {
                var url = window.URL.createObjectURL(file);
                img.onload = function () { try { window.URL.revokeObjectURL(url); } catch (_) {} };
                img.onerror = useDataUrl;
                img.src = url;
                return;
            } catch (_) {}
        }
        useDataUrl();
    }

    // 조치 전 사진 - 파일 스테이징 관리 (조치 후와 동일한 방식)
    var beforeStaged = []; // Array of { file }
    var beforeFileInput = document.getElementById('nm-before-photos');
    var beforeTokenList = document.getElementById('nm-before-token-list');
    // board.js의 resolveImageFromToken이 조치 전 파일을 찾을 수 있도록 공유 Map
    window.NM_extraImageFiles = new Map();

    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function rebuildBeforeInput() {
        // board.js가 조치 전 파일을 이미지 embed로 삽입할 수 있도록 공유 Map 갱신
        window.NM_extraImageFiles = new Map();
        beforeStaged.forEach(function(item) {
            window.NM_extraImageFiles.set(item.file.name.toLowerCase(), item.file);
        });
        if (!beforeFileInput) return;
        try {
            var dt = new DataTransfer();
            beforeStaged.forEach(function(item) { dt.items.add(item.file); });
            beforeFileInput.files = dt.files;
        } catch(e) {}
    }

    function renderBeforeList() {
        if (!beforeTokenList) return;
        if (beforeStaged.length === 0) {
            beforeTokenList.innerHTML = '';
            beforeTokenList.style.display = 'none';
            return;
        }
        beforeTokenList.style.display = '';
        beforeTokenList.innerHTML = beforeStaged.map(function(item, idx) {
            var token = '[[첨부:' + item.file.name + ']]';
            return '<span class="existing-file" data-before-idx="' + idx + '">'
                 + esc(item.file.name)
                 + ' <button type="button" class="insert-attachment-token" data-token="' + esc(token) + '">본문삽입</button>'
                 + '</span>';
        }).join('');
    }


    if (beforeFileInput) {
        beforeFileInput.addEventListener('change', function () {
            var files = beforeFileInput.files;
            var count = files ? files.length : 0;
            for (var i = 0; i < count; i++) {
                beforeStaged.push({ file: files[i] });
            }
            beforeFileInput.value = '';
            rebuildBeforeInput();
            renderBeforeList();
        });
    }

    var sitFileInput  = document.getElementById('situation_photos_file');
    var sitThumbsWrap = document.getElementById('situation-new-thumbs');
    var sitFileHint   = document.getElementById('situation-file-hint');
    if (sitFileInput) {
        sitFileInput.addEventListener('change', function () {
            var files = sitFileInput.files;
            var count = files ? files.length : 0;
            if (sitFileHint) sitFileHint.textContent = count === 0 ? '선택된 파일 없음' : count + '개 선택됨';
            if (sitThumbsWrap) {
                sitThumbsWrap.innerHTML = '';
                for (var i = 0; i < count; i++) {
                    var img = document.createElement('img');
                    img.className = 'photo-thumb';
                    img.alt = '현장사진 ' + (i + 1);
                    bindThumb(img, files[i]);
                    sitThumbsWrap.appendChild(img);
                }
            }
        });
    }
})();
</script>

<script src="../board/assets/js/board.js"></script>
<?php require_once 'includes/footer.php'; ?>
