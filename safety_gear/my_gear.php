<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/common.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function my_gear_redirect(string $statusFilter): void
{
    header('Location: /safety_gear/my_gear.php?status_filter=' . rawurlencode($statusFilter));
    exit;
}

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

if (!sg_can_access_my_gear($user)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>나의 보호구</title>
        <style>
            body { font-family: "Malgun Gothic", sans-serif; background:#f3f7fb; color:#122033; margin:0; padding:32px; }
            .panel { max-width:720px; margin:0 auto; background:#fff; border:1px solid #d7e0ea; border-radius:20px; padding:24px; }
            .actions { margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; }
            .button { display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0f766e; color:#fff; text-decoration:none; }
            .button.secondary { background:#e2e8f0; color:#0f172a; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>나의 보호구</h1>
            <p>현재 이 기능은 테스트 계정에만 열려 있습니다.</p>
            <div class="actions">
                <a class="button secondary" href="/risk_assessment/work_list.php">작업목록으로 돌아가기</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = sg_get_pdo();
$statusFilter = sg_normalize_text($_GET['status_filter'] ?? 'active');
if (!in_array($statusFilter, sg_status_filter_options(), true)) {
    $statusFilter = 'active';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStatusFilter = sg_normalize_text($_POST['status_filter'] ?? $statusFilter);
    if (!in_array($postStatusFilter, sg_status_filter_options(), true)) {
        $postStatusFilter = 'active';
    }

    $ownedItems = sg_fetch_my_items($pdo, $user, 'all');
    $ownedMap = [];
    foreach ($ownedItems as $ownedItem) {
        $ownedMap[(string)($ownedItem['id'] ?? '')] = $ownedItem;
    }

    $selectedGearUids = [];
    foreach ((array)($_POST['gear_uid'] ?? []) as $gearUid) {
        $gearUid = sg_normalize_text($gearUid);
        if ($gearUid !== '' && isset($ownedMap[$gearUid])) {
            $selectedGearUids[] = $gearUid;
        }
    }
    $selectedGearUids = array_values(array_unique($selectedGearUids));

    $action = sg_normalize_text($_POST['form_action'] ?? '');
    $message = '';
    $isError = false;

    try {
        if ($action === 'sign_selected') {
            if (empty($selectedGearUids)) {
                throw new RuntimeException('서명할 보호구를 먼저 선택해 주세요.');
            }
            $pdo->beginTransaction();
            $count = sg_sign_user_items($pdo, $user, $selectedGearUids);
            $pdo->commit();
            $message = $count . '건 서명이 완료되었습니다.';
        } elseif ($action === 'external_sign_selected') {
            if (empty($selectedGearUids)) {
                throw new RuntimeException('외부 간편인증 서명할 보호구를 먼저 선택해 주세요.');
            }
            $pdo->beginTransaction();
            $requestToken = sg_create_external_signature_request($pdo, $user, $selectedGearUids, 'pass');
            $pdo->commit();
            header('Location: /safety_gear/external_signature_start.php?request=' . rawurlencode($requestToken));
            exit;
        } elseif ($action === 'sign_all_visible') {
            $visibleItems = sg_fetch_my_items($pdo, $user, $postStatusFilter);
            $visibleIds = array_values(array_filter(array_map(static function (array $item): string {
                return sg_normalize_text($item['id'] ?? '');
            }, $visibleItems)));
            if (empty($visibleIds)) {
                throw new RuntimeException('현재 조건에서 서명할 보호구가 없습니다.');
            }
            $pdo->beginTransaction();
            $count = sg_sign_user_items($pdo, $user, $visibleIds);
            $pdo->commit();
            $message = $count . '건 일괄 서명이 완료되었습니다.';
        } elseif ($action === 'external_sign_all_visible') {
            $visibleItems = sg_fetch_my_items($pdo, $user, $postStatusFilter);
            $visibleIds = array_values(array_filter(array_map(static function (array $item): string {
                return sg_normalize_text($item['id'] ?? '');
            }, $visibleItems)));
            if (empty($visibleIds)) {
                throw new RuntimeException('현재 조건에서 외부 간편인증 서명할 보호구가 없습니다.');
            }
            $pdo->beginTransaction();
            $requestToken = sg_create_external_signature_request($pdo, $user, $visibleIds, 'pass');
            $pdo->commit();
            header('Location: /safety_gear/external_signature_start.php?request=' . rawurlencode($requestToken));
            exit;
        } elseif ($action === 'change_status') {
            throw new RuntimeException('작업자 페이지에서는 보호구 상태를 변경할 수 없습니다. 조회만 가능합니다.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
        $isError = true;
    }

    $_SESSION['my_gear_flash'] = [
        'message' => $message,
        'is_error' => $isError,
    ];
    my_gear_redirect($postStatusFilter);
}

$flash = $_SESSION['my_gear_flash'] ?? null;
unset($_SESSION['my_gear_flash']);

$items = sg_fetch_my_items($pdo, $user, $statusFilter);
$allItems = sg_fetch_my_items($pdo, $user, 'all');
$signedCount = 0;
$unsignedCount = 0;
foreach ($allItems as $item) {
    if (!empty($item['signature_completed'])) {
        $signedCount++;
    } else {
        $unsignedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>나의 보호구</title>
    <style>
        :root {
            --bg: #edf4f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #132238;
            --muted: #64748b;
            --accent: #0f766e;
            --accent-soft: #ccfbf1;
            --danger: #b91c1c;
            --warn: #d97706;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Malgun Gothic", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 20%),
                linear-gradient(180deg, #f8fbfd 0%, var(--bg) 100%);
        }

        .page {
            width: min(1240px, calc(100vw - 24px));
            margin: 18px auto 28px;
            display: grid;
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
            padding: 18px;
        }

        h1, h2, h3 { margin: 0; }
        .lead { margin: 8px 0 0; color: var(--muted); font-size: 14px; line-height: 1.6; }
        .topbar, .actions, .filter-row, .bulk-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .topbar { justify-content: space-between; }
        .button, button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            border: 0;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            font: inherit;
        }
        .button.secondary, button.secondary { background: #e2e8f0; color: #0f172a; }
        .button.ghost, button.ghost { background: var(--accent-soft); color: #115e59; }
        .button.warn, button.warn { background: var(--warn); color: #fff; }
        .button.danger, button.danger { background: var(--danger); color: #fff; }
        .summary-grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; margin-top:16px; }
        .summary-card { border:1px solid var(--line); border-radius:16px; padding:14px; background:#f8fafc; }
        .summary-card .label { display:block; font-size:12px; font-weight:700; color:var(--muted); margin-bottom:6px; }
        .summary-card .value { font-size:20px; font-weight:800; }
        .flash { padding: 12px 14px; border-radius: 12px; margin-top: 14px; }
        .flash.ok { background: #ecfeff; color: #155e75; }
        .flash.error { background: #fef2f2; color: #991b1b; }
        .filter-row { margin-top: 16px; justify-content: space-between; }
        .filter-row form { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        select, input[type="text"] {
            border:1px solid var(--line);
            border-radius:12px;
            padding:10px 12px;
            font: inherit;
            background:#fff;
        }
        table { width:100%; border-collapse:collapse; margin-top:14px; }
        th, td { border:1px solid var(--line); padding:10px 12px; text-align:left; vertical-align:top; font-size:13px; line-height:1.5; }
        th { background:#f8fafc; font-size:12px; }
        .status-badge, .sign-badge {
            display:inline-flex;
            align-items:center;
            padding:4px 8px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
        }
        .status-badge { background:#eef2ff; color:#4338ca; }
        .status-badge.is-hidden { background:#fff7ed; color:#c2410c; }
        .sign-badge.complete { background:#dcfce7; color:#166534; }
        .sign-badge.pending { background:#fef3c7; color:#92400e; }
        .bulk-panel { margin-top:16px; border:1px solid var(--line); border-radius:16px; padding:14px; background:#f8fafc; }
        .empty { margin-top:14px; padding:24px 18px; border:1px dashed var(--line); border-radius:16px; text-align:center; color:var(--muted); line-height:1.8; }
        .muted { color: var(--muted); font-size: 12px; }
        @media (max-width: 980px) {
            .summary-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
        }
        @media (max-width: 720px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="panel">
            <div class="topbar">
                <div>
                    <h1>나의 보호구</h1>
                    <p class="lead"><?= h((string)($user['name'] ?? '')) ?> 님에게 지급된 보호구 목록입니다. 서명 완료 여부를 확인하고, 일부 선택 또는 일괄 서명을 진행할 수 있습니다.</p>
                </div>
                <div class="actions">
                    <a class="button secondary" href="/risk_assessment/task_select.php">작업 페이지</a>
                    <a class="button secondary" href="/risk_assessment/work_list.php">작업목록</a>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <span class="label">총 보호구</span>
                    <div class="value"><?= count($allItems) ?>건</div>
                </div>
                <div class="summary-card">
                    <span class="label">서명 완료</span>
                    <div class="value"><?= $signedCount ?>건</div>
                </div>
                <div class="summary-card">
                    <span class="label">서명 미완료</span>
                    <div class="value"><?= $unsignedCount ?>건</div>
                </div>
                <div class="summary-card">
                    <span class="label">현재 필터 결과</span>
                    <div class="value"><?= count($items) ?>건</div>
                </div>
            </div>

            <?php if (is_array($flash) && trim((string)($flash['message'] ?? '')) !== ''): ?>
                <div class="flash <?= !empty($flash['is_error']) ? 'error' : 'ok' ?>"><?= h((string)$flash['message']) ?></div>
            <?php endif; ?>

            <div class="filter-row">
                <form method="get">
                    <label for="status_filter"><strong>상태 보기</strong></label>
                    <select id="status_filter" name="status_filter" onchange="this.form.submit()">
                        <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>기본 목록</option>
                        <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>전체 상태</option>
                        <?php foreach (array_merge(sg_visible_statuses(), sg_hidden_statuses()) as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>"<?= $statusFilter === $statusOption ? ' selected' : '' ?>><?= h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="muted">`반납`, `교체`, `폐기` 상태는 기본 목록에서 숨겨지고 상태 필터를 바꿨을 때만 보입니다.</div>
            </div>

            <form method="post" id="my-gear-form">
                <input type="hidden" name="status_filter" value="<?= h($statusFilter) ?>">

                <?php if (empty($items)): ?>
                    <div class="empty">현재 조건에 맞는 보호구가 없습니다.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="select-all"></th>
                                <th>보호구</th>
                                <th>식별값</th>
                                <th>상태</th>
                                <th>서명 상태</th>
                                <th>서명일시</th>
                                <th>지급일시</th>
                                <th>메모</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $itemId = (string)($item['id'] ?? '');
                                $status = (string)($item['status'] ?? '');
                                $isHiddenStatus = sg_status_label_is_hidden($status);
                                ?>
                                <tr>
                                    <td><input class="js-item-check" type="checkbox" name="gear_uid[]" value="<?= h($itemId) ?>"></td>
                                    <td>
                                        <strong><?= h((string)($item['gear_type'] ?? '-')) ?></strong><br>
                                        <?= h((string)($item['item_name'] ?? '-')) ?>
                                        <?php if (trim((string)($item['model_name'] ?? '')) !== ''): ?>
                                            / <?= h((string)($item['model_name'] ?? '')) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h((string)($item['identifier_value'] ?? '-')) ?></td>
                                    <td><span class="status-badge<?= $isHiddenStatus ? ' is-hidden' : '' ?>"><?= h($status !== '' ? $status : '-') ?></span></td>
                                    <td>
                                        <?php if (!empty($item['signature_completed'])): ?>
                                            <span class="sign-badge complete">서명 완료</span>
                                        <?php else: ?>
                                            <span class="sign-badge pending">서명 미완료</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h((string)($item['signature_signed_at'] ?? '-')) ?></td>
                                    <td><?= h((string)($item['assigned_at'] ?? '-')) ?></td>
                                    <td><?= nl2br(h((string)($item['notes'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="bulk-panel">
                        <div class="bulk-actions">
                            <button type="submit" name="form_action" value="sign_selected">선택 서명하기</button>
                            <button type="submit" name="form_action" value="sign_all_visible" class="ghost">일괄 서명하기</button>
                            <button type="submit" name="form_action" value="external_sign_selected" class="ghost">외부 간편인증 선택 서명</button>
                            <button type="submit" name="form_action" value="external_sign_all_visible" class="ghost">외부 간편인증 일괄 서명</button>
                        </div>
                        <div class="muted" style="margin-top:10px;">선택된 항목 수: <span id="selected-count">0</span>건</div>
                        <div class="muted" style="margin-top:6px;">외부 간편인증은 현재 PASS 연동 시도용 테스트 흐름입니다. 설정 전에는 시뮬레이션 완료 버튼으로 끝까지 점검할 수 있습니다.</div>
                        <div class="muted" style="margin-top:6px;">반납, 교체, 폐기 상태는 관리자 쪽 이력 처리 결과를 조회만 할 수 있습니다.</div>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    </div>

    <script>
        (function () {
            const selectAll = document.getElementById('select-all');
            const itemChecks = Array.from(document.querySelectorAll('.js-item-check'));
            const selectedCount = document.getElementById('selected-count');

            function updateSelectedCount() {
                if (!selectedCount) {
                    return;
                }
                const count = itemChecks.filter((checkbox) => checkbox.checked).length;
                selectedCount.textContent = String(count);
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    itemChecks.forEach((checkbox) => {
                        checkbox.checked = selectAll.checked;
                    });
                    updateSelectedCount();
                });
            }

            itemChecks.forEach((checkbox) => {
                checkbox.addEventListener('change', updateSelectedCount);
            });

            updateSelectedCount();
        })();
    </script>
</body>
</html>
