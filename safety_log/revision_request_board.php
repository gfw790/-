<?php

require_once __DIR__ . '/../risk_assessment/auth.php';

function safetyLogConnectBoardDb(): ?PDO
{
    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_CHARSET')) {
        $riskServerDbConfig = dirname(__DIR__, 2) . '/risk_server/db_config.php';
        if (is_file($riskServerDbConfig)) {
            require_once $riskServerDbConfig;
        }
    }

    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_CHARSET')) {
        $boardConfig = __DIR__ . '/../board/includes/config.php';
        if (is_file($boardConfig)) {
            require_once $boardConfig;
        }
    }

    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_CHARSET')) {
        return null;
    }

    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=board;charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}

function safetyLogFindRevisionCategoryId(PDO $boardPdo): int
{
    $findByNameStmt = $boardPdo->prepare(
        "SELECT id FROM categories
         WHERE is_active = 1
           AND name = :name
         ORDER BY sort_order ASC, id ASC
         LIMIT 1"
    );
    $findByNameStmt->execute([':name' => '수정요청']);
    $existingId = (int)$findByNameStmt->fetchColumn();
    if ($existingId > 0) {
        return $existingId;
    }

    $findByCodeStmt = $boardPdo->prepare("SELECT id FROM categories WHERE code = 'revision_request' LIMIT 1");
    $findByCodeStmt->execute();
    $existingCodeId = (int)$findByCodeStmt->fetchColumn();
    if ($existingCodeId > 0) {
        $activateStmt = $boardPdo->prepare(
            "UPDATE categories
             SET name = '수정요청', is_active = 1
             WHERE id = :id"
        );
        $activateStmt->execute([':id' => $existingCodeId]);
        return $existingCodeId;
    }

    $stmt = $boardPdo->prepare(
        "INSERT INTO categories (code, name, sort_order, write_role, is_active)
         VALUES ('revision_request', '수정요청', 46, 'user', 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            name = VALUES(name),
            is_active = VALUES(is_active)"
    );
    $stmt->execute();

    $categoryId = (int)$boardPdo->lastInsertId();
    if ($categoryId > 0) {
        return $categoryId;
    }

    $findStmt = $boardPdo->prepare("SELECT id FROM categories WHERE code = 'revision_request' LIMIT 1");
    $findStmt->execute();
    return (int)$findStmt->fetchColumn();
}

function safetyLogSyncBoardUser(PDO $boardPdo, array $author): void
{
    $stmt = $boardPdo->prepare(
        "INSERT INTO users (id, name, dept, role, last_seen)
         VALUES (:id, :name, :dept, :role, NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            dept = VALUES(dept),
            role = VALUES(role),
            last_seen = NOW()"
    );
    $stmt->execute([
        ':id' => $author['id'],
        ':name' => $author['name'],
        ':dept' => $author['dept'],
        ':role' => $author['role'],
    ]);
}

function safetyLogBuildBoardAuthor(string $managerName, array $siteVisitRows = []): array
{
    $currentUser = function_exists('auth_current_user') ? auth_current_user() : null;
    if (is_array($currentUser)) {
        return [
            'id' => trim((string)($currentUser['login_id'] ?? '')) ?: 'safety_log_system',
            'name' => trim((string)($currentUser['name'] ?? '')) ?: ($managerName !== '' ? $managerName : '안전관리자'),
            'dept' => trim((string)($currentUser['team'] ?? '')),
            'role' => in_array((string)($currentUser['role'] ?? ''), ['admin', 'manager'], true) ? 'admin' : 'user',
        ];
    }

    $dept = '';
    foreach ($siteVisitRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $teamName = trim((string)($row['team_name'] ?? ''));
        if ($teamName !== '') {
            $dept = $teamName;
            break;
        }
    }

    return [
        'id' => 'safety_log_system',
        'name' => $managerName !== '' ? $managerName : '안전관리자',
        'dept' => $dept,
        'role' => 'admin',
    ];
}

function safetyLogCollectRevisionRequestItems(array $details): array
{
    $requests = [];
    $seen = [];

    foreach ($details as $index => $detail) {
        if (!is_array($detail)) {
            continue;
        }

        $rawPreventionData = (string)($detail['prevention_data'] ?? '');
        if (trim($rawPreventionData) === '') {
            continue;
        }

        $decoded = json_decode($rawPreventionData, true);
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            continue;
        }

        $itemNo = isset($detail['item_no']) ? (int)$detail['item_no'] : ($index + 1);
        $activity = trim((string)($detail['activity'] ?? $decoded['activity'] ?? ''));
        $process = trim((string)($detail['description'] ?? $decoded['process'] ?? ''));
        $workTime = trim((string)($detail['work_time'] ?? ''));

        foreach ($decoded['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            $measure = trim((string)($item['measure'] ?? ''));
            if ($status !== '[평가서 수정필요]' || $measure === '') {
                continue;
            }

            $request = [
                'item_no' => $itemNo,
                'work_time' => $workTime,
                'activity' => $activity,
                'process' => $process,
                'measure' => $measure,
                'work_key' => trim((string)($item['work_key'] ?? '')),
                'work_label' => trim((string)($item['work_label'] ?? '')),
            ];

            $dedupeKey = sha1(json_encode([
                'measure' => $request['measure'],
                'work_key' => $request['work_key'],
                'work_label' => $request['work_label'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $requests[] = $request;
        }
    }

    return $requests;
}

function safetyLogBuildRevisionRequestTitle(int $logId, array $request): string
{
    $parts = [
        '[수정요청]',
        '안전일지 #' . $logId,
        'No.' . (int)($request['item_no'] ?? 0),
        trim((string)($request['activity'] ?? '')),
        trim((string)($request['process'] ?? '')),
    ];

    $title = implode(' ', array_values(array_filter($parts, static fn($value) => trim((string)$value) !== '')));
    if (function_exists('mb_substr')) {
        return mb_substr($title, 0, 200, 'UTF-8');
    }

    return substr($title, 0, 200);
}

function safetyLogBuildRevisionRequestMarker(int $logId, array $request): string
{
    return 'safety-log-revision-request:' . sha1(json_encode([
        'log_id' => $logId,
        'item_no' => (int)($request['item_no'] ?? 0),
        'activity' => trim((string)($request['activity'] ?? '')),
        'process' => trim((string)($request['process'] ?? '')),
        'measure' => trim((string)($request['measure'] ?? '')),
        'work_label' => trim((string)($request['work_label'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function safetyLogBuildStableRevisionRequestMarker(int $logId, array $request): string
{
    return 'safety-log-revision-stable:' . sha1(json_encode([
        'log_id' => $logId,
        'measure' => trim((string)($request['measure'] ?? '')),
        'work_key' => trim((string)($request['work_key'] ?? '')),
        'work_label' => trim((string)($request['work_label'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function safetyLogFindExistingRevisionPostId(PDO $boardPdo, int $categoryId, int $logId, array $request): int
{
    $stableMarker = safetyLogBuildStableRevisionRequestMarker($logId, $request);
    $findByStableStmt = $boardPdo->prepare(
        'SELECT id
         FROM posts
         WHERE category_id = :category_id
           AND content LIKE :stable_marker
         ORDER BY id ASC
         LIMIT 1'
    );
    $findByStableStmt->execute([
        ':category_id' => $categoryId,
        ':stable_marker' => '%' . $stableMarker . '%',
    ]);
    $postId = (int)$findByStableStmt->fetchColumn();
    if ($postId > 0) {
        return $postId;
    }

    $legacyMarker = safetyLogBuildRevisionRequestMarker($logId, $request);
    $findByLegacyStmt = $boardPdo->prepare(
        'SELECT id
         FROM posts
         WHERE category_id = :category_id
           AND content LIKE :legacy_marker
         ORDER BY id ASC
         LIMIT 1'
    );
    $findByLegacyStmt->execute([
        ':category_id' => $categoryId,
        ':legacy_marker' => '%' . $legacyMarker . '%',
    ]);
    $postId = (int)$findByLegacyStmt->fetchColumn();
    if ($postId > 0) {
        return $postId;
    }

    $measure = trim((string)($request['measure'] ?? ''));
    if ($measure === '') {
        return 0;
    }

    $fallbackSql =
        'SELECT id
         FROM posts
         WHERE category_id = :category_id
           AND content LIKE :auto_marker
           AND content LIKE :log_id_line
           AND content LIKE :measure_line';
    $params = [
        ':category_id' => $categoryId,
        ':auto_marker' => '%<!-- safety-log-revision-%',
        ':log_id_line' => '%업무일지 번호: ' . $logId . '%',
        ':measure_line' => '%수정 요청 대책: ' . $measure . '%',
    ];

    $workKey = trim((string)($request['work_key'] ?? ''));
    $workLabel = trim((string)($request['work_label'] ?? ''));
    if ($workKey !== '' || $workLabel !== '') {
        $fallbackSql .= ' AND content LIKE :work_label_line';
        $params[':work_label_line'] = '%현장 구분: ' . ($workLabel !== '' ? $workLabel : $workKey) . '%';
    }

    $fallbackSql .= ' ORDER BY id ASC LIMIT 1';
    $fallbackStmt = $boardPdo->prepare($fallbackSql);
    $fallbackStmt->execute($params);

    return (int)$fallbackStmt->fetchColumn();
}

function safetyLogExtractRevisionRequestCleanupKey(string $content): string
{
    if (preg_match('/<!--\s*(safety-log-revision-stable:[a-f0-9]+)\s*-->/i', $content, $matches)) {
        return trim((string)$matches[1]);
    }

    $logId = 0;
    $measure = '';
    $workLabel = '';

    if (preg_match('/^업무일지 번호:\s*(\d+)$/mu', $content, $matches)) {
        $logId = (int)$matches[1];
    }
    if (preg_match('/^수정 요청 대책:\s*(.+)$/mu', $content, $matches)) {
        $measure = trim((string)$matches[1]);
    }
    if (preg_match('/^현장 구분:\s*(.+)$/mu', $content, $matches)) {
        $workLabel = trim((string)$matches[1]);
    }

    if ($logId > 0 && $measure !== '') {
        return 'fallback:' . sha1(json_encode([
            'log_id' => $logId,
            'measure' => $measure,
            'work_label' => $workLabel,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if (preg_match('/<!--\s*(safety-log-revision-request:[a-f0-9]+)\s*-->/i', $content, $matches)) {
        return trim((string)$matches[1]);
    }

    return '';
}

function safetyLogCleanupDuplicateRevisionRequestPosts(?PDO $boardPdo = null, ?int $categoryId = null): array
{
    $boardPdo = $boardPdo ?: safetyLogConnectBoardDb();
    if (!$boardPdo) {
        return ['groups' => 0, 'deleted' => 0, 'updated' => 0, 'error' => '게시판 DB 연결 실패'];
    }

    try {
        $categoryId = $categoryId ?: safetyLogFindRevisionCategoryId($boardPdo);
        if ($categoryId <= 0) {
            return ['groups' => 0, 'deleted' => 0, 'updated' => 0, 'error' => '수정요청 카테고리를 확인할 수 없습니다.'];
        }

        $selectStmt = $boardPdo->prepare(
            'SELECT id, title, content, author_id, author_name, author_dept, created_at, updated_at
             FROM posts
             WHERE category_id = :category_id
               AND content LIKE :marker
             ORDER BY id ASC'
        );
        $selectStmt->execute([
            ':category_id' => $categoryId,
            ':marker' => '%<!-- safety-log-revision-%',
        ]);
        $posts = $selectStmt->fetchAll();

        $groupedPosts = [];
        foreach ($posts as $post) {
            $cleanupKey = safetyLogExtractRevisionRequestCleanupKey((string)($post['content'] ?? ''));
            if ($cleanupKey === '') {
                continue;
            }
            $groupedPosts[$cleanupKey][] = $post;
        }

        if (empty($groupedPosts)) {
            return ['groups' => 0, 'deleted' => 0, 'updated' => 0, 'error' => ''];
        }

        $updatePostStmt = $boardPdo->prepare(
            'UPDATE posts
             SET title = :title,
                 content = :content,
                 author_id = :author_id,
                 author_name = :author_name,
                 author_dept = :author_dept
             WHERE id = :id'
        );
        $moveCommentsStmt = $boardPdo->prepare('UPDATE comments SET post_id = :target_id WHERE post_id = :source_id');
        $moveAttachmentsStmt = $boardPdo->prepare('UPDATE attachments SET post_id = :target_id WHERE post_id = :source_id');
        $copyLikesStmt = $boardPdo->prepare(
            'INSERT IGNORE INTO likes (post_id, user_id, created_at)
             SELECT :target_id, user_id, created_at
             FROM likes
             WHERE post_id = :source_id'
        );
        $deleteLikesStmt = $boardPdo->prepare('DELETE FROM likes WHERE post_id = :source_id');
        $selectPollStmt = $boardPdo->prepare('SELECT id FROM polls WHERE post_id = :post_id LIMIT 1');
        $movePollStmt = $boardPdo->prepare('UPDATE polls SET post_id = :target_id WHERE id = :poll_id');
        $deletePollVotesStmt = $boardPdo->prepare('DELETE FROM poll_votes WHERE poll_id = :poll_id');
        $deletePollOptionsStmt = $boardPdo->prepare('DELETE FROM poll_options WHERE poll_id = :poll_id');
        $deletePollStmt = $boardPdo->prepare('DELETE FROM polls WHERE id = :poll_id');
        $deletePostStmt = $boardPdo->prepare('DELETE FROM posts WHERE id = :id');
        $recalcCommentStmt = $boardPdo->prepare(
            'UPDATE posts
             SET comment_count = (SELECT COUNT(*) FROM comments WHERE post_id = :post_id AND is_deleted = 0)
             WHERE id = :post_id'
        );
        $recalcLikeStmt = $boardPdo->prepare(
            'UPDATE posts
             SET like_count = (SELECT COUNT(*) FROM likes WHERE post_id = :post_id)
             WHERE id = :post_id'
        );

        $groupsCleaned = 0;
        $deletedCount = 0;
        $updatedCount = 0;

        $boardPdo->beginTransaction();

        foreach ($groupedPosts as $groupPosts) {
            if (count($groupPosts) <= 1) {
                continue;
            }

            usort($groupPosts, static function (array $left, array $right): int {
                return ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
            });

            $canonicalPost = $groupPosts[0];
            $latestPost = $groupPosts[0];
            foreach ($groupPosts as $post) {
                $postUpdatedAt = strtotime((string)($post['updated_at'] ?? '')) ?: 0;
                $latestUpdatedAt = strtotime((string)($latestPost['updated_at'] ?? '')) ?: 0;
                if ($postUpdatedAt > $latestUpdatedAt || ($postUpdatedAt === $latestUpdatedAt && (int)$post['id'] > (int)$latestPost['id'])) {
                    $latestPost = $post;
                }
            }

            if ((int)$canonicalPost['id'] !== (int)$latestPost['id']
                || (string)$canonicalPost['content'] !== (string)$latestPost['content']
                || (string)$canonicalPost['title'] !== (string)$latestPost['title']) {
                $updatePostStmt->execute([
                    ':id' => (int)$canonicalPost['id'],
                    ':title' => (string)$latestPost['title'],
                    ':content' => (string)$latestPost['content'],
                    ':author_id' => (string)$latestPost['author_id'],
                    ':author_name' => (string)$latestPost['author_name'],
                    ':author_dept' => (string)$latestPost['author_dept'],
                ]);
                $updatedCount++;
            }

            foreach (array_slice($groupPosts, 1) as $duplicatePost) {
                $duplicatePostId = (int)($duplicatePost['id'] ?? 0);
                $canonicalPostId = (int)($canonicalPost['id'] ?? 0);
                if ($duplicatePostId <= 0 || $canonicalPostId <= 0 || $duplicatePostId === $canonicalPostId) {
                    continue;
                }

                $moveCommentsStmt->execute([
                    ':target_id' => $canonicalPostId,
                    ':source_id' => $duplicatePostId,
                ]);
                $moveAttachmentsStmt->execute([
                    ':target_id' => $canonicalPostId,
                    ':source_id' => $duplicatePostId,
                ]);
                $copyLikesStmt->execute([
                    ':target_id' => $canonicalPostId,
                    ':source_id' => $duplicatePostId,
                ]);
                $deleteLikesStmt->execute([':source_id' => $duplicatePostId]);

                $selectPollStmt->execute([':post_id' => $duplicatePostId]);
                $duplicatePollId = (int)$selectPollStmt->fetchColumn();
                if ($duplicatePollId > 0) {
                    $selectPollStmt->execute([':post_id' => $canonicalPostId]);
                    $canonicalPollId = (int)$selectPollStmt->fetchColumn();
                    if ($canonicalPollId > 0) {
                        $deletePollVotesStmt->execute([':poll_id' => $duplicatePollId]);
                        $deletePollOptionsStmt->execute([':poll_id' => $duplicatePollId]);
                        $deletePollStmt->execute([':poll_id' => $duplicatePollId]);
                    } else {
                        $movePollStmt->execute([
                            ':target_id' => $canonicalPostId,
                            ':poll_id' => $duplicatePollId,
                        ]);
                    }
                }

                $deletePostStmt->execute([':id' => $duplicatePostId]);
                $deletedCount++;
            }

            $recalcCommentStmt->execute([':post_id' => (int)$canonicalPost['id']]);
            $recalcLikeStmt->execute([':post_id' => (int)$canonicalPost['id']]);
            $groupsCleaned++;
        }

        $boardPdo->commit();

        return ['groups' => $groupsCleaned, 'deleted' => $deletedCount, 'updated' => $updatedCount, 'error' => ''];
    } catch (Throwable $e) {
        if ($boardPdo->inTransaction()) {
            $boardPdo->rollBack();
        }

        return ['groups' => 0, 'deleted' => 0, 'updated' => 0, 'error' => $e->getMessage()];
    }
}

function safetyLogCreateRevisionRequestPosts(array $payload): array
{
    $requests = safetyLogCollectRevisionRequestItems($payload['details'] ?? []);
    if (empty($requests)) {
        return ['created' => 0, 'error' => ''];
    }

    $boardPdo = safetyLogConnectBoardDb();
    if (!$boardPdo) {
        return ['created' => 0, 'error' => '게시판 DB 연결 실패'];
    }

    try {
        $categoryId = safetyLogFindRevisionCategoryId($boardPdo);
        if ($categoryId <= 0) {
            return ['created' => 0, 'error' => '수정요청 카테고리를 확인할 수 없습니다.'];
        }

        $author = safetyLogBuildBoardAuthor(
            trim((string)($payload['manager_name'] ?? '')),
            is_array($payload['site_visit_rows'] ?? null) ? $payload['site_visit_rows'] : []
        );
        safetyLogSyncBoardUser($boardPdo, $author);

        $createdCount = 0;
        $updatedCount = 0;
        $insertStmt = $boardPdo->prepare(
            'INSERT INTO posts (category_id, title, content, author_id, author_name, author_dept, is_notice)
             VALUES (:category_id, :title, :content, :author_id, :author_name, :author_dept, 0)'
        );
        $updateStmt = $boardPdo->prepare(
            'UPDATE posts
             SET title = :title,
                 content = :content,
                 author_id = :author_id,
                 author_name = :author_name,
                 author_dept = :author_dept
             WHERE id = :id'
        );

        foreach ($requests as $request) {
            $logId = (int)($payload['log_id'] ?? 0);
            $marker = safetyLogBuildRevisionRequestMarker($logId, $request);
            $stableMarker = safetyLogBuildStableRevisionRequestMarker($logId, $request);

            $contentLines = [
                '안전관리자 업무일지에서 평가서 수정 요청이 등록되었습니다.',
                '',
                '업무일지 번호: ' . $logId,
                '작성일: ' . trim((string)($payload['log_date'] ?? '')),
                '작성자: ' . trim((string)($payload['manager_name'] ?? '')),
                '현장명: ' . trim((string)($payload['site_name'] ?? '')),
                '작업장소: ' . trim((string)($payload['work_location'] ?? '')),
                '제목: ' . trim((string)($payload['subject'] ?? '')),
                '세부기록 No.: ' . (int)($request['item_no'] ?? 0),
                '시간: ' . trim((string)($request['work_time'] ?? '')),
                '관찰업무: ' . trim((string)($request['activity'] ?? '')),
                '공정: ' . trim((string)($request['process'] ?? '')),
                '현장 구분: ' . trim((string)($request['work_label'] ?? '')),
                '수정 요청 대책: ' . trim((string)($request['measure'] ?? '')),
                '',
                '업무일지 비고:',
                trim((string)($payload['remark'] ?? '')),
                '',
                '<!-- ' . $stableMarker . ' -->',
                '<!-- ' . $marker . ' -->',
            ];

            $content = implode("\n", array_values(array_filter($contentLines, static fn($line) => $line !== '현장 구분: ' && $line !== '업무일지 비고:')));
            $title = safetyLogBuildRevisionRequestTitle($logId, $request);
            $existingPostId = safetyLogFindExistingRevisionPostId($boardPdo, $categoryId, $logId, $request);

            if ($existingPostId > 0) {
                $updateStmt->execute([
                    ':id' => $existingPostId,
                    ':title' => $title,
                    ':content' => $content,
                    ':author_id' => $author['id'],
                    ':author_name' => $author['name'],
                    ':author_dept' => $author['dept'],
                ]);
                $updatedCount++;
                continue;
            }

            $insertStmt->execute([
                ':category_id' => $categoryId,
                ':title' => $title,
                ':content' => $content,
                ':author_id' => $author['id'],
                ':author_name' => $author['name'],
                ':author_dept' => $author['dept'],
            ]);
            $createdCount++;
        }

        $cleanupResult = safetyLogCleanupDuplicateRevisionRequestPosts($boardPdo, $categoryId);

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
            'deleted' => (int)($cleanupResult['deleted'] ?? 0),
            'cleanup_groups' => (int)($cleanupResult['groups'] ?? 0),
            'error' => !empty($cleanupResult['error']) ? (string)$cleanupResult['error'] : '',
        ];
    } catch (Throwable $e) {
        return ['created' => 0, 'error' => $e->getMessage()];
    }
}