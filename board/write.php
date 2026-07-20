<?php
require_once 'includes/header.php';
$user = requireLogin();
ensureBoardNoticeTargetSchema();

$editId = (int)($_GET['id'] ?? 0);
$post = null;
$existingAtts = [];
$poll = null;
$pollOpts = [];
$error = '';

if ($editId > 0) {
    $stmt = db()->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->execute([$editId]);
    $post = $stmt->fetch();
    if (!$post) {
        die('게시글을 찾을 수 없습니다.');
    }
    if ($post['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
        die('수정 권한이 없습니다.');
    }

    $existingAtts = getAttachments($editId);

    $ps = db()->prepare("SELECT * FROM polls WHERE post_id = ?");
    $ps->execute([$editId]);
    $poll = $ps->fetch();
    if ($poll) {
        $os = db()->prepare("SELECT * FROM poll_options WHERE poll_id = ? ORDER BY sort_order, id");
        $os->execute([$poll['id']]);
        $pollOpts = $os->fetchAll();
    }
}

$canWriteAdminCat = $user['role'] === 'admin' || ($user['original_role'] ?? '') === 'safety_manager';
$noticeTeamOptions = board_notice_team_options();
$selectedNoticeTargetTeam = board_normalize_notice_target_team((string)($post['notice_target_team'] ?? 'ALL'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST['csrf'] ?? '');

    $title = trim($_POST['title'] ?? '');
    $rawContent = trim($_POST['content'] ?? '');
    // 리치텍스트 형식이면 인라인 HTML을 화이트리스트로 정제
    $richtextPrefix = '<!--richtext-->';
    if (str_starts_with($rawContent, $richtextPrefix)) {
        $body = substr($rawContent, strlen($richtextPrefix));
        // Sanitize each text segment between attachment tokens
        $sanitized = preg_replace_callback(
            '/(\[\[\s*첨부\s*:[^\]]+\]\])|((?:(?!\[\[첨부).)+)/us',
            static function (array $m): string {
                if ($m[1] !== '') return $m[1]; // keep token as-is
                return sanitizeRichtextInline($m[2]);
            },
            $body
        );
        $content = $richtextPrefix . ($sanitized ?? $body);
    } else {
        $content = $rawContent;
    }
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isNotice = !empty($_POST['is_notice']) && $canWriteAdminCat ? 1 : 0;
    $noticeTargetTeam = board_normalize_notice_target_team($_POST['notice_target_team'] ?? 'ALL');
    if (!$isNotice) {
        $noticeTargetTeam = 'ALL';
    }
    $selectedNoticeTargetTeam = $noticeTargetTeam;

    if ($title === '' || $content === '' || $categoryId === 0) {
        $error = '제목, 분류, 내용을 모두 입력해 주세요.';
    } else {
        $cat = getCategoryById($categoryId);
        if (!$cat) {
            $error = '올바르지 않은 분류입니다.';
        } elseif ($cat['write_role'] === 'admin' && !$canWriteAdminCat) {
            $error = '해당 분류는 관리자만 작성할 수 있습니다.';
        }
    }

    if ($error === '') {
        try {
            db()->beginTransaction();

            if ($editId > 0) {
                $stmt = db()->prepare(
                    "UPDATE posts
                     SET category_id = ?, title = ?, content = ?, is_notice = ?, notice_target_team = ?
                     WHERE id = ?"
                );
                $stmt->execute([$categoryId, $title, $content, $isNotice, $noticeTargetTeam, $editId]);
                $postId = $editId;

                if (!empty($_POST['delete_attachments'])) {
                    $delIds = array_map('intval', $_POST['delete_attachments']);
                    if (!empty($delIds)) {
                        $in = implode(',', array_fill(0, count($delIds), '?'));
                        $stmt = db()->prepare("SELECT * FROM attachments WHERE post_id = ? AND id IN ($in)");
                        $stmt->execute(array_merge([$postId], $delIds));
                        foreach ($stmt->fetchAll() as $att) {
                            deleteAttachmentPhysicalFile($att);
                        }

                        $stmt = db()->prepare("DELETE FROM attachments WHERE post_id = ? AND id IN ($in)");
                        $stmt->execute(array_merge([$postId], $delIds));
                    }
                }

                db()->prepare("DELETE FROM polls WHERE post_id = ?")->execute([$postId]);
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO posts (
                        category_id, title, content, author_id, author_name, author_dept, is_notice, notice_target_team
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $categoryId,
                    $title,
                    $content,
                    $user['id'],
                    $user['name'],
                    $user['dept'],
                    $isNotice,
                    $noticeTargetTeam,
                ]);
                $postId = (int)db()->lastInsertId();
            }

            if (!empty($_FILES['attachments']['name'][0])) {
                handleUploads($postId, $_FILES['attachments']);
            }

            if (!empty($_POST['use_poll']) && !empty($_POST['poll_question'])) {
                $opts = array_filter(array_map('trim', $_POST['poll_options'] ?? []), fn($x) => $x !== '');
                if (count($opts) >= 2) {
                    $stmt = db()->prepare(
                        "INSERT INTO polls (post_id, question, multi_select, is_anonymous, closes_at)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $postId,
                        trim($_POST['poll_question']),
                        !empty($_POST['poll_multi']) ? 1 : 0,
                        !empty($_POST['poll_anon']) ? 1 : 0,
                        !empty($_POST['poll_closes_at']) ? str_replace('T', ' ', $_POST['poll_closes_at']) . ':00' : null,
                    ]);

                    $pollId = (int)db()->lastInsertId();
                    $optStmt = db()->prepare("INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (?, ?, ?)");
                    foreach (array_values($opts) as $i => $opt) {
                        $optStmt->execute([$pollId, $opt, $i]);
                    }
                }
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

$pageTitle = $editId ? '글 수정' : '글쓰기';
$selectedCat = $post['category_id'] ?? 0;
if (!$selectedCat && !empty($_GET['cat'])) {
    $c = getCategoryByCode($_GET['cat']);
    if ($c) {
        $selectedCat = $c['id'];
    }
}
?>

<h2 class="page-title">
    <span><?= $editId ? '글 수정' : '글쓰기' ?></span>
</h2>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<form id="write-form" class="write-form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

    <div class="form-row">
        <div class="form-label">분류<span class="req">*</span></div>
        <div class="form-input">
            <select name="category_id" required>
                <option value="">선택</option>
                <?php foreach (getCategories() as $cat): ?>
                    <?php if ($cat['write_role'] === 'admin' && !$canWriteAdminCat) continue; ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $selectedCat == $cat['id'] ? 'selected' : '' ?>>
                        <?= h($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($canWriteAdminCat): ?>
                <label style="margin-left:14px;font-size:12px;">
                    <input type="checkbox" name="is_notice" value="1" <?= !empty($post['is_notice']) ? 'checked' : '' ?>>
                    공지글로 등록
                </label>
                <label style="margin-left:14px;font-size:12px;">
                    공지 노출팀
                    <select name="notice_target_team" style="margin-left:6px;">
                        <option value="ALL" <?= $selectedNoticeTargetTeam === 'ALL' ? 'selected' : '' ?>>전체 공지</option>
                        <?php foreach ($noticeTeamOptions as $teamName): ?>
                            <option value="<?= h($teamName) ?>" <?= $selectedNoticeTargetTeam === $teamName ? 'selected' : '' ?>>
                                <?= h($teamName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="form-label">제목<span class="req">*</span></div>
        <div class="form-input">
            <input type="text" name="title" maxlength="200" required value="<?= h($post['title'] ?? '') ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="form-label">작성자</div>
        <div class="form-input">
            <span><?= h($user['dept']) ?> <strong><?= h($user['name']) ?></strong></span>
        </div>
    </div>

    <div class="form-row">
        <div class="form-label">내용<span class="req">*</span></div>
        <div class="form-input">
            <textarea id="content" name="content" required><?= h($post['content'] ?? '') ?></textarea>
            <div id="content-editor" class="content-editor" contenteditable="true" hidden></div>
            <div class="help">입력창에서 텍스트와 이미지를 함께 편집할 수 있습니다. (저장은 토큰 형식으로 자동 변환)</div>
        </div>
    </div>

    <div class="form-row">
        <div class="form-label">첨부파일</div>
        <div class="form-input" style="flex-direction:column;align-items:flex-start;">
            <input type="file" id="attachments" name="attachments[]" multiple>
            <div class="help">
                최대 <?= formatBytes(MAX_UPLOAD_SIZE) ?>, 허용: <?= str_replace(',', ', ', ALLOWED_EXTENSIONS) ?>
            </div>
            <div class="help">
                본문 중간 삽입: <code>[[첨부:1]]</code>, <code>[[첨부:id:첨부ID]]</code>, <code>[[첨부:파일명.jpg]]</code>
            </div>
            <div id="new-attachment-token-list" class="file-token-list" style="display:none;"></div>
            <?php if (!empty($existingAtts)): ?>
                <div class="file-list">
                    <?php foreach ($existingAtts as $att): ?>
                        <span class="existing-file"
                              data-attach-id="<?= (int)$att['id'] ?>"
                              data-original-name="<?= h($att['original_name']) ?>"
                              data-is-image="<?= isImageAttachment($att) ? '1' : '0' ?>">
                            <?= h($att['original_name']) ?> (<?= formatBytes($att['file_size']) ?>)
                            <?php if (isImageAttachment($att)): ?>
                                <button type="button" class="insert-attachment-token" data-token="<?= h('[[첨부:id:' . (int)$att['id'] . ']]') ?>" title="본문에 이미지 토큰 넣기">본문삽입</button>
                            <?php endif; ?>
                            <span class="del" data-attach-id="<?= (int)$att['id'] ?>">×</span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="form-label">투표</div>
        <div class="form-input" style="flex-direction:column;align-items:flex-start;">
            <label style="font-size:12px;">
                <input type="checkbox" id="use_poll" name="use_poll" value="1" <?= $poll ? 'checked' : '' ?>>
                투표 첨부하기
            </label>
            <div id="poll_section" class="poll-builder" style="margin-top:8px;width:100%;">
                <input type="text" name="poll_question" placeholder="투표 질문을 입력하세요"
                       value="<?= h($poll['question'] ?? '') ?>" style="width:100%;margin-bottom:8px;">
                <div class="poll-options-input">
                    <?php
                    $initOpts = !empty($pollOpts) ? array_column($pollOpts, 'option_text') : ['', ''];
                    foreach ($initOpts as $i => $opt):
                    ?>
                        <div class="poll-opt-input">
                            <input type="text" name="poll_options[]" placeholder="선택지" value="<?= h($opt) ?>">
                            <?php if ($i >= 2): ?>
                                <button type="button" class="add-opt remove-opt">삭제</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="add-opt">+ 선택지 추가</button>
                <div class="poll-meta">
                    <label><input type="checkbox" name="poll_multi" value="1" <?= !empty($poll['multi_select']) ? 'checked' : '' ?>> 복수선택</label>
                    <label><input type="checkbox" name="poll_anon" value="1" <?= !empty($poll['is_anonymous']) ? 'checked' : '' ?>> 익명</label>
                    <label>마감일시:
                        <input type="datetime-local" name="poll_closes_at"
                               value="<?= !empty($poll['closes_at']) ? date('Y-m-d\TH:i', strtotime($poll['closes_at'])) : '' ?>">
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions write-actions" style="padding:16px;">
        <a href="<?= $editId ? 'view.php?id=' . $editId : 'index.php' ?>" class="btn">취소</a>
        <div class="write-actions-right">
            <button type="button" id="preview-write-btn" class="btn">미리보기</button>
            <button type="submit" class="btn btn-primary"><?= $editId ? '수정 완료' : '등록' ?></button>
        </div>
    </div>
</form>

<div id="write-preview-modal" class="write-preview-modal" hidden>
    <div class="write-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="write-preview-heading">
        <div class="write-preview-head">
            <strong id="write-preview-heading">게시글 미리보기</strong>
            <button type="button" id="close-write-preview" class="btn btn-sm">닫기</button>
        </div>
        <h3 id="write-preview-title" class="write-preview-title"></h3>
        <div id="write-preview-content" class="write-preview-content"></div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
