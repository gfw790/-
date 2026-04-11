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

function handleTaggedPhotoUploads(int $postId, array $files, string $tag): void {
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
            '[' . $tag . '] ' . $origName,
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
$actionAtts = [];
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
            $actionAtts[] = $att;
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
            if (!empty($_FILES['action_photos']['name'][0])) {
                handleTaggedPhotoUploads($postId, $_FILES['action_photos'], '조치');
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
                <div class="plain-editor" data-plain-editor>
                    <div class="plain-editor-toolbar">
                        <select class="editor-font-size" data-editor-font-size>
                            <option value="1">매우 작게</option>
                            <option value="2">작게</option>
                            <option value="3" selected>중</option>
                            <option value="4">크게</option>
                            <option value="5">매우 크게</option>
                            <option value="6">제목1</option>
                            <option value="7">제목2</option>
                        </select>
                        <button type="button" class="btn btn-sm editor-tool-btn" data-editor-action="bold" title="굵게"><b>B</b></button>
                        <button type="button" class="btn btn-sm editor-tool-btn" data-editor-action="italic" title="기울임"><i>I</i></button>
                        <button type="button" class="btn btn-sm editor-tool-btn" data-editor-action="underline" title="밑줄"><u>U</u></button>
                        <button type="button" class="btn btn-sm editor-tool-btn" data-editor-action="strikeThrough" title="취소선"><s>S</s></button>
                        <button type="button" class="btn btn-sm editor-tool-btn editor-color-btn" data-editor-action="foreColor" title="글자색">
                            A
                            <span class="color-swatch" data-editor-color-swatch></span>
                            <input type="color" class="editor-color-input" data-editor-color value="#e8f2fc" tabindex="-1">
                        </button>
                        <button type="button" class="btn btn-sm editor-tool-btn" data-editor-action="removeFormat" title="서식 초기화">✕</button>
                        <span class="toolbar-separator"></span>
                        <button type="button" class="btn btn-sm" data-photo-picker="situation" data-photo-token="[상황사진]">상황사진 본문에 넣기</button>
                        <button type="button" class="btn btn-sm" data-photo-picker="action" data-photo-token="[조치사진]">조치사진 본문에 넣기</button>
                    </div>
                    <div class="plain-editor-area" contenteditable="true" data-editor-area></div>
                </div>
                <textarea name="action_taken" required data-editor-source hidden><?= h($form['action_taken']) ?></textarea>
                <div class="editor-help">사진 버튼을 누르면 파일 선택창이 바로 열리고, 선택 시 본문에 위치표시가 삽입됩니다.</div>
                <div class="editor-help" id="inline-photo-status">선택된 신규 사진이 없습니다.</div>
                <div id="inline-photo-inputs" hidden></div>
            </div>

            <div class="survey-field">
                <label>추가 재발방지 메모</label>
                <textarea name="prevention_plan"><?= h($form['prevention_plan']) ?></textarea>
            </div>

            <div class="survey-field">
                <label>기존 첨부 사진</label>
                <?php if (empty($situationAtts) && empty($actionAtts)): ?>
                    <div class="editor-help">기존 첨부 사진이 없습니다.</div>
                <?php else: ?>
                    <div class="file-list">
                        <?php foreach ($situationAtts as $att): ?>
                            <span class="existing-file">
                                [상황] <?= h(stripTagPrefix($att['original_name'])) ?> (<?= formatBytes($att['file_size']) ?>)
                                <span class="del" data-attach-id="<?= (int)$att['id'] ?>">✕</span>
                            </span>
                        <?php endforeach; ?>
                        <?php foreach ($actionAtts as $att): ?>
                            <span class="existing-file">
                                [조치] <?= h(stripTagPrefix($att['original_name'])) ?> (<?= formatBytes($att['file_size']) ?>)
                                <span class="del" data-attach-id="<?= (int)$att['id'] ?>">✕</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
    var otherGroups = ['risk_type', 'unsafe_state', 'unsafe_action', 'careless_action', 'careless_state'];

    otherGroups.forEach(function (groupName) {
        var radios = Array.prototype.slice.call(document.querySelectorAll('input[type="radio"][name="' + groupName + '"]'));
        var wrap = document.querySelector('[data-other-wrap="' + groupName + '"]');
        if (!radios.length || !wrap) {
            return;
        }
        var input = wrap.querySelector('input[type="text"]');
        if (!input) {
            return;
        }

        var sync = function () {
            var selected = radios.find(function (radio) { return radio.checked; });
            var show = !!selected && selected.value === '기타';
            wrap.hidden = !show;
            input.disabled = !show;
            input.required = show;
            if (!show) {
                input.value = '';
            }
        };

        radios.forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        sync();
    });

    var editorRoot = document.querySelector('[data-plain-editor]');
    if (!editorRoot) {
        return;
    }
    var editorArea = editorRoot.querySelector('[data-editor-area]');
    var sourceTextarea = document.querySelector('textarea[data-editor-source]');
    if (!editorArea || !sourceTextarea) {
        return;
    }
    var TOKEN_REGEX = /(\[[^\]\r\n]*상황사진[^\]\r\n]*\]|\[[^\]\r\n]*조치사진[^\]\r\n]*\])/gu;
    var TOKEN_SITUATION = '[상황사진]';
    var TOKEN_ACTION = '[조치사진]';
    var RICHTEXT_PREFIX = '<!--richtext-->';
    var savedRange = null;

    function saveEditorRange() {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;
        var range = selection.getRangeAt(0);
        if (editorArea.contains(range.startContainer) && editorArea.contains(range.endContainer)) {
            savedRange = range.cloneRange();
        }
    }

    function bindThumbnailSource(img, file) {
        if (!img || !file) return;
        var useDataUrl = function () {
            if (!window.FileReader) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                img.src = String((e && e.target && e.target.result) || '');
            };
            reader.readAsDataURL(file);
        };
        if (window.URL && typeof window.URL.createObjectURL === 'function') {
            try {
                var objectUrl = window.URL.createObjectURL(file);
                img.onload = function () { try { window.URL.revokeObjectURL(objectUrl); } catch (_) {} };
                img.onerror = useDataUrl;
                img.src = objectUrl;
                return;
            } catch (_) {}
        }
        useDataUrl();
    }

    function createTokenNode(kind, fileList) {
        var tokenNode = document.createElement('span');
        tokenNode.className = 'editor-embed ' + (kind === 'situation' ? 'editor-token-situation' : 'editor-token-action');
        tokenNode.setAttribute('contenteditable', 'false');
        tokenNode.setAttribute('data-token', kind === 'situation' ? TOKEN_SITUATION : TOKEN_ACTION);
        tokenNode.setAttribute('data-kind', kind);

        var count = fileList && fileList.length ? fileList.length : 0;
        var label = document.createElement('span');
        label.className = 'editor-embed-label';
        label.textContent = (kind === 'situation' ? '상황사진' : '조치사진') + (count > 0 ? (' ' + count + '장') : '');
        tokenNode.appendChild(label);

        if (count > 0) {
            var thumbs = document.createElement('span');
            thumbs.className = 'editor-embed-thumbs';
            var thumbCount = Math.min(count, 2);
            for (var i = 0; i < thumbCount; i++) {
                var img = document.createElement('img');
                img.alt = '미리보기';
                bindThumbnailSource(img, fileList[i]);
                thumbs.appendChild(img);
            }
            tokenNode.appendChild(thumbs);
        }
        return tokenNode;
    }

    function appendTextWithBreaks(parent, text) {
        if (!text) return;
        var lines = String(text).split('\n');
        for (var i = 0; i < lines.length; i++) {
            if (lines[i] !== '') parent.appendChild(document.createTextNode(lines[i]));
            if (i < lines.length - 1) parent.appendChild(document.createElement('br'));
        }
    }

    function appendParsedText(parent, text) {
        var sourceText = String(text || '');
        var cursor = 0;
        var match;
        TOKEN_REGEX.lastIndex = 0;
        while ((match = TOKEN_REGEX.exec(sourceText)) !== null) {
            appendTextWithBreaks(parent, sourceText.slice(cursor, match.index));
            parent.appendChild(/상황사진/u.test(match[0]) ? createTokenNode('situation') : createTokenNode('action'));
            cursor = TOKEN_REGEX.lastIndex;
        }
        appendTextWithBreaks(parent, sourceText.slice(cursor));
    }

    function hydrateTokenTextNodes(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        textNodes.forEach(function (textNode) {
            var text = textNode.nodeValue || '';
            TOKEN_REGEX.lastIndex = 0;
            if (!TOKEN_REGEX.test(text)) return;

            var fragment = document.createDocumentFragment();
            var cursor = 0;
            TOKEN_REGEX.lastIndex = 0;
            var m;
            while ((m = TOKEN_REGEX.exec(text)) !== null) {
                fragment.appendChild(document.createTextNode(text.slice(cursor, m.index)));
                fragment.appendChild(/상황사진/u.test(m[0]) ? createTokenNode('situation') : createTokenNode('action'));
                cursor = TOKEN_REGEX.lastIndex;
            }
            fragment.appendChild(document.createTextNode(text.slice(cursor)));
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    }

    function renderEditorFromSource(raw) {
        editorArea.innerHTML = '';
        var source = String(raw || '');
        if (source.startsWith(RICHTEXT_PREFIX)) {
            var temp = document.createElement('div');
            temp.innerHTML = source.slice(RICHTEXT_PREFIX.length);
            hydrateTokenTextNodes(temp);
            while (temp.firstChild) editorArea.appendChild(temp.firstChild);
            return;
        }
        appendParsedText(editorArea, source);
    }

    function placeCaretAfter(node) {
        var range = document.createRange();
        var selection = window.getSelection();
        range.setStartAfter(node);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        savedRange = range.cloneRange();
    }

    function getSafeRange() {
        var selection = window.getSelection();
        if (selection && selection.rangeCount > 0) {
            var active = selection.getRangeAt(0);
            if (editorArea.contains(active.startContainer)) {
                return active;
            }
        }
        if (savedRange && editorArea.contains(savedRange.startContainer)) {
            return savedRange.cloneRange();
        }
        var fallback = document.createRange();
        fallback.selectNodeContents(editorArea);
        fallback.collapse(false);
        return fallback;
    }

    function syncEditorValue() {
        var clone = editorArea.cloneNode(true);
        clone.querySelectorAll('.editor-embed').forEach(function (embed) {
            var token = embed.getAttribute('data-token') || '';
            embed.replaceWith(document.createTextNode(token));
        });
        clone.querySelectorAll('[contenteditable]').forEach(function (el) {
            el.removeAttribute('contenteditable');
        });
        sourceTextarea.value = RICHTEXT_PREFIX + clone.innerHTML;
    }

    function insertNodeIntoEditor(node) {
        editorArea.focus();
        var range = getSafeRange();
        range.deleteContents();
        range.insertNode(node);
        placeCaretAfter(node);
        syncEditorValue();
    }

    function insertTextAtCursor(text) {
        editorArea.focus();
        var range = getSafeRange();
        range.deleteContents();
        var fragment = document.createDocumentFragment();
        appendTextWithBreaks(fragment, text);
        var lastNode = fragment.lastChild;
        range.insertNode(fragment);
        if (lastNode) {
            placeCaretAfter(lastNode);
        } else {
            saveEditorRange();
        }
        syncEditorValue();
    }

    function insertTokenAtCursor(kind, fileList) {
        insertNodeIntoEditor(createTokenNode(kind, fileList));
    }

    editorArea.addEventListener('paste', function (event) {
        event.preventDefault();
        var text = '';
        if (event.clipboardData && event.clipboardData.getData) {
            text = event.clipboardData.getData('text/plain') || '';
        } else if (window.clipboardData && window.clipboardData.getData) {
            text = window.clipboardData.getData('Text') || '';
        }
        if (text !== '') insertTextAtCursor(text);
    });

    editorArea.addEventListener('keyup', saveEditorRange);
    editorArea.addEventListener('mouseup', saveEditorRange);
    editorArea.addEventListener('focus', saveEditorRange);
    document.addEventListener('selectionchange', saveEditorRange);
    editorArea.addEventListener('input', syncEditorValue);
    editorArea.addEventListener('blur', syncEditorValue);

    var toolbar = editorRoot.querySelector('.plain-editor-toolbar');
    var sizeSelect = toolbar ? toolbar.querySelector('[data-editor-font-size]') : null;
    var colorInput = toolbar ? toolbar.querySelector('[data-editor-color]') : null;
    var colorSwatch = toolbar ? toolbar.querySelector('[data-editor-color-swatch]') : null;

    function updateToolbarState() {
        if (!toolbar) return;
        toolbar.querySelectorAll('[data-editor-action]').forEach(function (btn) {
            var action = btn.getAttribute('data-editor-action');
            if (action === 'foreColor' || action === 'removeFormat') return;
            try {
                btn.classList.toggle('is-active', document.queryCommandState(action));
            } catch (_) {}
        });
    }

    function runEditorCommand(action, value) {
        editorArea.focus();
        var range = getSafeRange();
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        document.execCommand(action, false, value == null ? null : value);
        saveEditorRange();
        updateToolbarState();
        syncEditorValue();
    }

    if (toolbar) {
        toolbar.addEventListener('mousedown', function (event) {
            var toolBtn = event.target.closest('[data-editor-action]');
            if (toolBtn && toolBtn.getAttribute('data-editor-action') !== 'foreColor') {
                event.preventDefault();
            }
        });

        toolbar.addEventListener('click', function (event) {
            var toolBtn = event.target.closest('[data-editor-action]');
            if (!toolBtn) return;
            var action = toolBtn.getAttribute('data-editor-action') || '';
            if (action === 'foreColor') {
                if (colorInput) colorInput.click();
                return;
            }
            runEditorCommand(action, null);
        });
    }

    if (sizeSelect) {
        sizeSelect.addEventListener('change', function () {
            runEditorCommand('fontSize', sizeSelect.value || '3');
            sizeSelect.value = '3';
        });
    }

    if (colorInput) {
        colorInput.addEventListener('input', function () {
            if (colorSwatch) colorSwatch.style.background = colorInput.value;
        });
        colorInput.addEventListener('change', function () {
            runEditorCommand('foreColor', colorInput.value || '#e8f2fc');
        });
    }

    var inlineBucket = document.getElementById('inline-photo-inputs');
    var inlineStatus = document.getElementById('inline-photo-status');

    function updateInlineStatus() {
        if (!inlineStatus || !inlineBucket) return;
        var inputs = inlineBucket.querySelectorAll('input[type="file"]');
        var situationCount = 0;
        var actionCount = 0;
        inputs.forEach(function (input) {
            var fileCount = input.files ? input.files.length : 0;
            if (input.name === 'situation_photos[]') situationCount += fileCount;
            if (input.name === 'action_photos[]') actionCount += fileCount;
        });
        var totalCount = situationCount + actionCount;
        inlineStatus.textContent = totalCount === 0
            ? '선택된 신규 사진이 없습니다.'
            : ('신규 사진 선택: 상황 ' + situationCount + '개, 조치 ' + actionCount + '개');
    }

    function openInlinePicker(kind) {
        if (!inlineBucket) return;
        var input = document.createElement('input');
        input.type = 'file';
        input.hidden = true;
        input.multiple = true;
        input.accept = 'image/*';
        input.name = kind === 'situation' ? 'situation_photos[]' : 'action_photos[]';
        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                insertTokenAtCursor(kind, input.files);
                updateInlineStatus();
            } else {
                input.remove();
                updateInlineStatus();
            }
        });
        inlineBucket.appendChild(input);
        input.click();
    }

    editorRoot.querySelectorAll('[data-photo-picker]').forEach(function (button) {
        button.addEventListener('click', function () {
            var kind = button.getAttribute('data-photo-picker') || '';
            openInlinePicker(kind);
        });
    });

    renderEditorFromSource(sourceTextarea.value || '');
    syncEditorValue();
    updateToolbarState();
    updateInlineStatus();

    var form = document.getElementById('write-form');
    if (form) form.addEventListener('submit', syncEditorValue);
})();
</script>

<?php require_once 'includes/footer.php'; ?>
