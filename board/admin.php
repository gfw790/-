<?php
require_once 'includes/header.php';
$user = requireAdmin();

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $writeRole = $_POST['write_role'] ?? 'user';
        $sort = (int)($_POST['sort_order'] ?? 99);

        if ($code !== '' && $name !== '') {
            try {
                db()->prepare(
                    "INSERT INTO categories (code, name, sort_order, write_role)
                     VALUES (?, ?, ?, ?)"
                )->execute([$code, $name, $sort, $writeRole]);
                $msg = '카테고리가 추가되었습니다.';
            } catch (PDOException $e) {
                $msg = '추가에 실패했습니다. 코드 중복 여부를 확인해 주세요.';
            }
        }
    } elseif ($action === 'update_category') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare(
            "UPDATE categories
             SET name = ?, sort_order = ?, write_role = ?, is_active = ?
             WHERE id = ?"
        )->execute([
            trim($_POST['name'] ?? ''),
            (int)($_POST['sort_order'] ?? 0),
            $_POST['write_role'] ?? 'user',
            !empty($_POST['is_active']) ? 1 : 0,
            $id,
        ]);
        $msg = '카테고리가 수정되었습니다.';
    } elseif ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        $countStmt = db()->prepare("SELECT COUNT(*) FROM posts WHERE category_id = ?");
        $countStmt->execute([$id]);

        if ((int)$countStmt->fetchColumn() > 0) {
            $msg = '게시글이 있는 카테고리는 삭제할 수 없습니다. 비활성화로 관리해 주세요.';
        } else {
            db()->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $msg = '카테고리가 삭제되었습니다.';
        }
    }
}

$stats = [
    'posts' => (int)db()->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
    'comments' => (int)db()->query("SELECT COUNT(*) FROM comments WHERE is_deleted = 0")->fetchColumn(),
    'users' => (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'today' => (int)db()->query("SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
];

$cats = db()->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll();
$users = db()->query("SELECT * FROM users ORDER BY last_seen DESC LIMIT 20")->fetchAll();

$pageTitle = '관리자';
?>

<h2 class="page-title"><span>관리자 페이지</span></h2>

<?php if ($msg): ?>
    <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>

<section class="admin-section">
    <h2>통계</h2>
    <div class="admin-stat-grid">
        <div class="admin-stat-card">
            <div class="admin-stat-label">전체 게시글</div>
            <div class="admin-stat-value"><?= number_format($stats['posts']) ?></div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-label">전체 댓글</div>
            <div class="admin-stat-value"><?= number_format($stats['comments']) ?></div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-label">사용자</div>
            <div class="admin-stat-value"><?= number_format($stats['users']) ?></div>
        </div>
        <div class="admin-stat-card admin-stat-card--accent">
            <div class="admin-stat-label">오늘 등록</div>
            <div class="admin-stat-value"><?= number_format($stats['today']) ?></div>
        </div>
    </div>
</section>

<section class="admin-section">
    <h2>카테고리 관리</h2>
    <table class="post-table">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th style="width:120px;">코드</th>
                <th>이름</th>
                <th style="width:90px;">정렬</th>
                <th style="width:110px;">쓰기권한</th>
                <th style="width:80px;">활성</th>
                <th style="width:140px;">관리</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cats as $cat): ?>
                <tr>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="update_category">
                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                        <td><?= (int)$cat['id'] ?></td>
                        <td><code><?= h($cat['code']) ?></code></td>
                        <td><input type="text" name="name" value="<?= h($cat['name']) ?>" style="width:100%;"></td>
                        <td><input type="number" name="sort_order" value="<?= (int)$cat['sort_order'] ?>" style="width:72px;"></td>
                        <td>
                            <select name="write_role">
                                <option value="user" <?= $cat['write_role'] === 'user' ? 'selected' : '' ?>>일반</option>
                                <option value="admin" <?= $cat['write_role'] === 'admin' ? 'selected' : '' ?>>관리자</option>
                            </select>
                        </td>
                        <td><input type="checkbox" name="is_active" value="1" <?= $cat['is_active'] ? 'checked' : '' ?>></td>
                        <td>
                            <button type="submit" class="btn btn-sm">저장</button>
                    </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('정말 삭제하시겠습니까?');">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">삭제</button>
                            </form>
                        </td>
                </tr>
            <?php endforeach; ?>
            <tr class="admin-add-row">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_category">
                    <td>+</td>
                    <td><input type="text" name="code" placeholder="code" required style="width:100%;"></td>
                    <td><input type="text" name="name" placeholder="이름" required style="width:100%;"></td>
                    <td><input type="number" name="sort_order" value="99" style="width:72px;"></td>
                    <td>
                        <select name="write_role">
                            <option value="user">일반</option>
                            <option value="admin">관리자</option>
                        </select>
                    </td>
                    <td>-</td>
                    <td><button type="submit" class="btn btn-sm btn-primary">추가</button></td>
                </form>
            </tr>
        </tbody>
    </table>
</section>

<section class="admin-section">
    <h2>최근 활동 사용자 (최근 20명)</h2>
    <table class="post-table">
        <thead>
            <tr>
                <th style="width:140px;">ID</th>
                <th style="width:120px;">이름</th>
                <th style="width:160px;">부서</th>
                <th style="width:90px;">권한</th>
                <th>최근 접속</th>
                <th>가입일</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $row): ?>
                <tr>
                    <td><?= h($row['id']) ?></td>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h($row['dept']) ?></td>
                    <td>
                        <?php if ($row['role'] === 'admin'): ?>
                            <span class="admin-role-badge">관리자</span>
                        <?php else: ?>
                            <span class="admin-role-text">일반</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $row['last_seen'] ? timeAgo($row['last_seen']) : '-' ?></td>
                    <td><?= dateFormat($row['created_at'], 'Y-m-d') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <tr class="empty-row"><td colspan="6">사용자 데이터가 없습니다.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <p class="admin-note">
        권한 변경은 <code>includes/auth.php</code> 설정 또는 DB <code>users</code> 테이블의 role 값으로 직접 조정해 주세요.
    </p>
</section>

<?php require_once 'includes/footer.php'; ?>
