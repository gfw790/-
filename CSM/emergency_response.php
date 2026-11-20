<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/emergency_response_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');
$year = er_year($_GET['year'] ?? null);
$items = er_items();
$tab = er_input($_GET, 'tab');
if ($tab !== 'evidence' && !isset($items[$tab])) {
    $tab = 'scenarios';
}
$url = 'emergency_response.php?year=' . $year . '&tab=' . $tab;
$printUrl = 'emergency_response_print.php?year=' . $year . '&mode=';
$db = er_db();
$e = 'er_escape';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['er_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['er_csrf'];
$message = $_SESSION['er_flash'] ?? '';
unset($_SESSION['er_flash']);
$errorMessage = '';
$action = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, er_input($_POST, 'csrf')) || !$canManage) {
        http_response_code(403);
        $errorMessage = '관리 권한 또는 보안 토큰을 확인해 주세요.';
    } else {
        $action = er_input($_POST, 'action');
        $code = er_input($_POST, 'item_code');
        $knownCodes = array_column($items, 0);
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        try {
            if ($id === false) {
                throw new InvalidArgumentException('기록 번호를 확인해 주세요.');
            }
            if ($action === 'save_record' && isset($items[$tab])) {
                er_save_record($db, $tab, $year, $id, $_POST, $_FILES, $actor);
                $_SESSION['er_flash'] = '기록을 저장했습니다.';
            } else {
                if (!in_array($code, $knownCodes, true) || ($tab !== 'evidence' && $items[$tab][0] !== $code)) {
                    throw new InvalidArgumentException('평가 항목을 확인해 주세요.');
                }
                $note = er_input($_POST, 'note');
                if (mb_strlen($note) > 1000) {
                    throw new InvalidArgumentException('메모는 1,000자 이내로 입력해 주세요.');
                }
                switch ($action) {
                    case 'upload_evidence':
                        er_upload_evidence($db, $year, $code, er_input($_POST, 'kind'), er_selected_file($_FILES, 'evidence_file'),
                            er_input($_POST, 'evidence_file_base64'), $note, $actor);
                        $_SESSION['er_flash'] = '증빙을 등록했습니다.';
                        break;
                    case 'delete_evidence':
                        $stmt = $db->prepare('UPDATE er_evidence SET deleted_at = ? WHERE id = ? AND year = ? AND item_code = ? AND deleted_at IS NULL');
                        $stmt->execute([date(DATE_ATOM), $id, $year, $code]);
                        if ($stmt->rowCount() !== 1) {
                            throw new InvalidArgumentException('삭제할 증빙을 찾을 수 없습니다.');
                        }
                        $_SESSION['er_flash'] = '증빙을 삭제했습니다.';
                        break;
                    case 'save_assessment':
                        er_save_assessment($db, $year, $code, er_input($_POST, 'multiplier'), $note, $actor);
                        $_SESSION['er_flash'] = '평가 수준을 저장했습니다.';
                        break;
                    default:
                        throw new InvalidArgumentException('요청한 작업을 확인해 주세요.');
                }
            }
            header('Location: ' . $url . ($action === 'save_record' ? '#records' : '#item-' . $code), true, 303);
            exit;
        } catch (InvalidArgumentException $error) {
            http_response_code(422);
            $errorMessage = $error->getMessage();
        } catch (Throwable $error) {
            error_log('비상대응 저장 오류: ' . $error->getMessage());
            http_response_code(500);
            $errorMessage = '저장 중 오류가 발생했습니다. 다시 시도해 주세요.';
        }
    }
} elseif (isset($_GET['view_photo']) || isset($_GET['download_evidence'])) {
    session_write_close();
    $path = null;
    if (isset($_GET['view_photo'])) {
        $path = er_file_path(er_input($_GET, 'view_photo'), true);
        $mime = $path !== null ? er_photo_mime($path) : null;
        if ($mime === null) {
            $path = null;
        }
    } else {
        $id = filter_var($_GET['download_evidence'], FILTER_VALIDATE_INT);
        $stmt = $db->prepare('SELECT original_name, stored_name, mime, size FROM er_evidence WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id ?: 0]);
        $file = $stmt->fetch();
        if ($file) {
            $path = er_file_path($file['stored_name'], false);
            if ($path !== null && @filesize($path) !== (int)$file['size']) {
                $path = null;
            }
            $mime = $file['mime'];
            $inline = isset($_GET['inline']) && isset(ER_IMAGE_TYPES[$mime]) && $path !== null && er_photo_mime($path) === $mime;
            $disposition = ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['original_name']);
            if (!$inline) {
                $mime = 'application/octet-stream';
            }
        }
    }
    $stream = $path !== null ? @fopen($path, 'rb') : false;
    if ($stream === false) {
        http_response_code(404);
        exit('첨부 파일을 찾을 수 없습니다.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . fstat($stream)['size']);
    if (isset($disposition)) {
        header('Content-Disposition: ' . $disposition);
    }
    fpassthru($stream);
    fclose($stream);
    exit;
}

$scores = [];
foreach ($items as [$code]) {
    $scores[$code] = csm_er_score_summary($year, $code);
}
$evidenceByCode = [];
foreach (er_list_evidence($db, $year) as $entry) {
    $evidenceByCode[$entry['item_code']][] = $entry;
}
$visibleItems = $tab === 'evidence' ? $items : [$tab => $items[$tab]];
$tabLabels = ['scenarios' => '시나리오', 'drills' => '훈련 결과', 'contacts' => '연락망·대피도', 'reports' => '보고·공유', 'evidence' => '통합 증빙·점수'];
$records = isset($items[$tab]) ? er_list_records($db, $tab, $year, $canManage) : [];
$fields = isset($items[$tab]) ? er_record_fields($tab) : [];
$photoFields = er_photo_fields($tab);
$globalRecords = in_array($tab, ['scenarios', 'contacts'], true);
$editId = $canManage ? (int)(filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT) ?: 0) : 0;
$edit = $editId > 0 ? (array_column($records, null, 'id')[$editId] ?? null) : null;
$isRecordError = $action === 'save_record' && $errorMessage !== '';
$today = er_today();
$recordDate = (int)substr($today, 0, 4) === $year ? $today : $year . '-01-01';
$defaults = [
    'sort_order' => 10, 'active' => 1, 'reported_to' => '정연탁 대표이사', 'leader_name' => '김남균',
    'revision_date' => $today, 'drill_date' => $recordDate, 'report_date' => $recordDate,
    'half_label' => (int)substr($recordDate, 5, 2) <= 6 ? '상반기' : '하반기',
];
$formValues = $edit ?? $defaults;
if ($isRecordError) {
    foreach (array_keys($fields + ['sort_order' => null]) as $key) {
        $formValues[$key] = er_input($_POST, $key);
    }
    $formValues['active'] = er_input($_POST, 'active');
}

function er_render_attachment_input(string $name, string $label, bool $photo): void
{
    $e = 'er_escape';
    $accept = $photo ? 'image/*' : 'image/*,.pdf,.docx,.xlsx';
    ?>
    <fieldset class="attachment" data-attachment>
        <legend><?= $e($label) ?></legend>
        <label>파일 선택 <input type="file" name="<?= $e($name) ?>" accept="<?= $accept ?>"></label>
        <label>카메라 촬영 <input type="file" accept="image/*" capture="environment" name="<?= $e($name) ?>_camera"></label>
        <input type="hidden" name="<?= $e($name) ?>_base64">
        <button type="button" class="paste" data-paste>여기를 선택한 뒤 Ctrl+V</button>
        <button type="button" class="quiet" data-clear hidden>첨부 선택 취소</button>
        <span data-status role="status" class="muted"></span>
    </fieldset>
    <?php
}

$drillCounts = ['상반기' => 0, '하반기' => 0];
if ($tab === 'drills') {
    foreach ($records as $record) {
        $half = (int)substr($record['drill_date'], 5, 2) <= 6 ? '상반기' : '하반기';
        $drillCounts[$half]++;
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>2.9 비상대응 · 주식회사 현대기전</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #182c29; background: #f2f6f5; font: 15px/1.5 "Malgun Gothic", sans-serif; }
    a { color: #0c6557; }
    .banner { background: #103b35; color: white; }
    .wrap { width: min(1180px, calc(100% - 32px)); margin: auto; }
    .banner .wrap { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px; padding: 22px 0; }
    .banner a { color: #d6ece6; }
    .banner h1, h2, h3 { margin: 0; }
    h1 { font-size: 28px; }
    h2 { font-size: 20px; }
    h3 { font-size: 16px; }
    main { padding: 18px 0 40px; }
    .row, nav { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .split { justify-content: space-between; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 10px; margin-bottom: 16px; }
    .kpi, .panel, article { background: white; border: 1px solid #d4e1dd; border-radius: 10px; }
    .kpi { display: block; padding: 12px 14px; color: inherit; text-decoration: none; }
    .kpi strong { display: block; font-size: 20px; margin-top: 3px; }
    .kpi.total { background: #e0f0eb; border-color: #a8cfc1; }
    .panel { margin-top: 12px; padding: clamp(12px, 3vw, 22px); }
    article { padding: 14px; margin: 10px 0; }
    nav { margin-bottom: 12px; }
    .btn, button { display: inline-block; padding: 6px 11px; border: 1px solid #bfd2cc; border-radius: 6px; background: white; color: #174c40; font: inherit; text-decoration: none; cursor: pointer; }
    .primary, [aria-current="page"] { color: white; background: #146858; border-color: #146858; }
    .muted { color: #4f655f; }
    .notice { background: #dfefe7; padding: 10px 12px; border-radius: 6px; }
    .error { background: #fae5e1; color: #86251a; }
    .danger { color: #962a21; }
    .fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 10px; }
    .fields .wide { grid-column: 1 / -1; }
    .record-form, .evidence-form, .assessment { margin-top: 12px; }
    .record-form .row, .evidence-form .row, .assessment .row { margin-top: 10px; }
    label { display: block; font-weight: 600; }
    input:not([type="checkbox"]), textarea, select { width: 100%; min-width: 0; border: 1px solid #bdccc7; border-radius: 5px; padding: 6px 8px; background: white; color: inherit; font: inherit; }
    input[type="file"] { padding: 4px; font-size: 13px; }
    textarea { resize: vertical; }
    .banner input { color: #182c29; max-width: 98px; }
    .attachment { min-width: 0; border: 1px solid #cbdad5; margin: 10px 0 0; padding: 8px 10px 10px; border-radius: 6px; }
    .attachment label { margin: 4px 0; }
    .paste { border-style: dashed; margin: 4px 0; }
    .paste:focus { outline: 2px solid #167664; outline-offset: 2px; }
    .quiet { border-color: transparent; }
    [hidden] { display: none !important; }
    details { margin-top: 12px; }
    summary { cursor: pointer; font-weight: 700; }
    dl { display: grid; grid-template-columns: minmax(105px, 28%) 1fr; margin: 8px 0 0; }
    dt, dd { padding: 6px 8px; margin: 0; border-top: 1px solid #e1eae7; overflow-wrap: anywhere; }
    dt { font-weight: 700; }
    dd { white-space: pre-wrap; }
    .scroll { overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; margin-top: 8px; }
    th, td { padding: 8px; border-bottom: 1px solid #dce6e2; text-align: left; overflow-wrap: anywhere; }
    th { background: #eef5f2; }
    td form { margin: 0; }
    .preview { display: block; width: 140px; height: 95px; object-fit: contain; background: #eef5f2; margin: 6px 0; }
    .photo-links { margin-top: 10px; }
</style>
</head>
<body>
<header class="banner">
    <div class="wrap">
        <div><a href="area.php?group=2&amp;year=<?= $year ?>">CSM 안전관리</a><h1>2.9 비상대응</h1></div>
        <form class="row" method="get">
            <input type="hidden" name="tab" value="<?= $e($tab) ?>">
            <label for="year">평가 연도</label>
            <input type="number" id="year" name="year" value="<?= $year ?>" min="2000" max="9999" required>
            <button type="submit">조회</button>
        </form>
    </div>
</header>
<main class="wrap">
    <div class="kpis" aria-label="비상대응 점수">
        <?php foreach ($items as $mode => [$code]): ?>
            <a class="kpi" href="?year=<?= $year ?>&amp;tab=<?= $e($mode) ?>">
                <?= $e($code) ?> <?= $e($tabLabels[$mode]) ?>
                <strong><?= number_format($scores[$code]['earned'] ?? 0, 1) ?> / 2.0점</strong>
                <span class="muted">유효 증빙 <?= $scores[$code]['evidence_count'] ?? 0 ?>건</span>
            </a>
        <?php endforeach; ?>
        <a class="kpi total" href="?year=<?= $year ?>&amp;tab=evidence">
            2.9 합계 <strong><?= number_format(array_sum(array_column($scores, 'earned')), 1) ?> / 8.0점</strong>
        </a>
    </div>
    <nav aria-label="비상대응 메뉴">
        <?php foreach ($tabLabels as $mode => $label): ?>
            <a class="btn" href="?year=<?= $year ?>&amp;tab=<?= $e($mode) ?>" <?= $tab === $mode ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <nav aria-label="빠른 인쇄">
        <?php foreach ($items as $mode => [$code]): ?>
            <a class="btn" href="<?= $e($printUrl . $mode) ?>"><?= $e($tabLabels[$mode]) ?> 인쇄</a>
        <?php endforeach; ?>
    </nav>
    <?php if ($errorMessage !== ''): ?><p class="notice error" role="alert"><?= $e($errorMessage) ?></p><?php endif; ?>
    <?php if ($message !== ''): ?><p class="notice" role="status"><?= $e($message) ?></p><?php endif; ?>

    <?php if (isset($items[$tab])): ?>
    <section class="panel" id="records">
        <h2><?= $e($items[$tab][0] . ' ' . $items[$tab][1]) ?></h2>
        <?php if ($tab === 'drills'): ?>
            <p>반기별 1회 이상 · 상반기 <?= $drillCounts['상반기'] ?>회 · 하반기 <?= $drillCounts['하반기'] ?>회</p>
        <?php elseif ($tab === 'contacts'): ?>
            <p class="muted">현장별 연락처를 확인하고 최신 비상대피도와 게시 사진을 아래 증빙에 등록해 주세요.</p>
        <?php endif; ?>

        <?php if ($canManage): ?>
        <details <?= $edit !== null || $isRecordError || !$records ? 'open' : '' ?> id="record-form">
            <summary><?= $edit !== null ? '기록 수정' : '새 기록 등록' ?></summary>
            <form class="record-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="fields">
                <?php foreach ($fields as $name => $spec):
                    [$label, $type] = $spec;
                    $required = ($spec[2] ?? true) ? 'required' : '';
                    $value = $formValues[$name] ?? '';
                ?>
                    <label class="<?= $type === 'textarea' ? 'wide' : '' ?>"><?= $e($label) ?>
                    <?php if (is_array($type)): ?>
                        <select name="<?= $e($name) ?>" <?= $required ?>>
                            <?php foreach ($type as $option): ?>
                                <option <?= $option === $value ? 'selected' : '' ?>><?= $e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'textarea'): ?>
                        <textarea name="<?= $e($name) ?>" maxlength="3000" rows="2" <?= $required ?>><?= $e($value) ?></textarea>
                    <?php else: ?>
                        <input type="<?= $e($type) ?>" name="<?= $e($name) ?>" value="<?= $e($value) ?>" <?= $required ?>
                            <?= $type === 'number' ? 'min="0" max="999999" step="1"' : 'maxlength="180"' ?>>
                    <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <?php if ($globalRecords): ?>
                    <label>표시 순서 <input type="number" name="sort_order" min="0" max="9999" value="<?= $e($formValues['sort_order']) ?>" required></label>
                    <label><input type="checkbox" name="active" value="1" <?= (int)$formValues['active'] === 1 ? 'checked' : '' ?>> 현재 사용</label>
                <?php endif; ?>
                </div>
                <?php foreach ($photoFields as $key => $label): ?>
                    <?php er_render_attachment_input($key, $label . ' (14MB 이하)', true); ?>
                    <?php if (!empty($edit[$key])): ?>
                        <label><input type="checkbox" name="remove_<?= $e($key) ?>" value="1"> 기존 <?= $e($label) ?> 삭제</label>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="row">
                    <button class="primary" type="submit">기록 저장</button>
                    <?php if ($edit !== null): ?><a class="btn" href="<?= $e($url) ?>#records">수정 취소</a><?php endif; ?>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <?php if (!$records): ?><p class="muted">등록된 기록이 없습니다.</p><?php endif; ?>
        <?php if ($tab === 'contacts' && $records): ?>
            <div class="scroll"><table>
                <thead><tr><th>소속 구분</th><th>직책·역할</th><th>담당자·기관</th><th>연락처</th><th>비고</th><th>최종 수정일</th><?php if ($canManage): ?><th>관리</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($records as $row): ?>
                    <tr>
                        <?php foreach ($fields as $name => $spec): ?><td><?= $e($name === 'phone' && $row[$name] === '' ? '미등록' : $row[$name]) ?></td><?php endforeach; ?>
                        <td><?= $e(substr($row['updated_at'], 0, 10)) ?></td>
                        <?php if ($canManage): ?>
                            <td><?= (int)$row['active'] === 0 ? '미사용 ' : '' ?><a href="<?= $e($url . '&edit=' . $row['id']) ?>#record-form">수정</a></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <?php foreach ($records as $row):
                $titleKey = $tab === 'reports' ? 'report_title' : 'scenario_title';
            ?>
            <article>
                <div class="row split">
                    <h3><?= $e($row[$titleKey]) ?><?= isset($row['active']) && (int)$row['active'] === 0 ? ' (미사용)' : '' ?></h3>
                    <div class="row">
                        <?php if (!isset($row['active']) || (int)$row['active'] === 1): ?>
                            <a class="btn" href="<?= $e($printUrl . $tab . '&id=' . $row['id']) ?>">인쇄</a>
                        <?php endif; ?>
                        <?php if ($canManage): ?><a class="btn" href="<?= $e($url . '&edit=' . $row['id']) ?>#record-form">수정</a><?php endif; ?>
                    </div>
                </div>
                <dl>
                    <?php foreach ($fields as $name => [$label]): ?>
                        <?php if ($name !== $titleKey): ?><dt><?= $e($label) ?></dt><dd><?= $e($row[$name]) ?></dd><?php endif; ?>
                    <?php endforeach; ?>
                </dl>
                <div class="row photo-links">
                    <?php foreach ($photoFields as $key => $label): ?>
                        <?php if (er_file_path($row[$key], true) !== null): ?>
                            <a href="?view_photo=<?= $e($row[$key]) ?>" target="_blank" rel="noopener"><img class="preview" src="?view_photo=<?= $e($row[$key]) ?>" alt="<?= $e($label) ?>" loading="lazy"><?= $e($label) ?> 보기</a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php foreach ($visibleItems as $mode => [$code, $title, $kinds]):
        $score = $scores[$code];
        $assessment = $score['assessment'] ?? null;
        $currentLevel = $assessment === null ? 'auto' : (string)$assessment['multiplier'];
        $entries = $evidenceByCode[$code] ?? [];
    ?>
    <section class="panel" id="item-<?= $e($code) ?>">
        <div class="row split">
            <h2><?= $e($code . ' ' . ($tab === 'evidence' ? $title : '증빙·점수')) ?></h2>
            <strong><?= number_format($score['earned'] ?? 0, 1) ?> / 2.0점 · 유효 증빙 <?= $score['evidence_count'] ?? 0 ?>건</strong>
        </div>
        <?php if (!$entries): ?><p class="muted">별도로 등록된 증빙 파일이 없습니다.</p><?php endif; ?>
        <?php if ($entries): ?>
        <div class="scroll"><table>
            <thead><tr><th>증빙 종류</th><th>첨부 파일</th><th>메모</th><th>등록일·등록자</th><?php if ($canManage): ?><th>관리</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($entries as $entry):
                $path = er_file_path($entry['stored_name'], false);
                $available = $path !== null && @filesize($path) === (int)$entry['size'];
                $fileUrl = '?download_evidence=' . (int)$entry['id'];
            ?>
                <tr>
                    <td><?= $e($kinds[$entry['kind']] ?? $entry['kind']) ?></td>
                    <td>
                        <?php if ($available): ?>
                            <a href="<?= $e($fileUrl) ?>"><?= $e($entry['original_name']) ?></a>
                            <?php if (isset(ER_IMAGE_TYPES[$entry['mime']])): ?>
                                <a href="<?= $e($fileUrl) ?>&amp;inline=1" target="_blank" rel="noopener">사진 보기</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= $e($entry['original_name']) ?> <span class="danger">파일 확인 필요</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $e($entry['note']) ?></td><td><?= $e(substr($entry['uploaded_at'], 0, 10) . ' · ' . $entry['uploaded_by']) ?></td>
                    <?php if ($canManage): ?>
                        <td><form method="post" data-confirm="이 증빙을 삭제하시겠습니까?">
                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="action" value="delete_evidence">
                            <input type="hidden" name="item_code" value="<?= $e($code) ?>">
                            <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                            <button class="danger" type="submit">삭제</button>
                        </form></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($canManage): ?>
        <details>
            <summary>증빙 등록</summary>
            <form class="evidence-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="upload_evidence">
                <input type="hidden" name="item_code" value="<?= $e($code) ?>">
                <div class="fields">
                    <label>증빙 종류 <select name="kind">
                        <?php foreach ($kinds as $kind => $label): ?><option value="<?= $e($kind) ?>"><?= $e($label) ?></option><?php endforeach; ?>
                    </select></label>
                    <label>메모 <input name="note" maxlength="1000"></label>
                </div>
                <?php er_render_attachment_input('evidence_file', 'PDF, DOCX, XLSX, JPG, PNG, WEBP (14MB 이하)', false); ?>
                <div class="row"><button class="primary" type="submit">증빙 등록</button></div>
            </form>
        </details>
        <form class="assessment" method="post">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="save_assessment">
            <input type="hidden" name="item_code" value="<?= $e($code) ?>">
            <div class="fields">
                <label>평가 수준 <select name="multiplier">
                    <?php foreach (er_score_levels() as $level => $label): ?>
                        <option value="<?= $e($level) ?>" <?= (string)$level === $currentLevel ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>검토 의견 <input name="note" maxlength="1000" value="<?= $e($assessment['note'] ?? '') ?>"></label>
            </div>
            <div class="row"><button type="submit">평가 수준 저장</button></div>
        </form>
        <?php elseif ($assessment !== null): ?>
            <p>평가 수준 <?= $e($currentLevel * 100) ?>% · <?= $e($assessment['note']) ?></p>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</main>
<script>
document.querySelectorAll('[data-attachment]').forEach((box) => {
    const files = [...box.querySelectorAll('input[type=file]')];
    const pasted = box.querySelector('input[type=hidden]');
    const status = box.querySelector('[data-status]');
    const clear = box.querySelector('[data-clear]');
    let reader = null;

    function reset(keepFile) {
        if (reader?.readyState === FileReader.LOADING) reader.abort();
        reader = null;
        files.filter((file) => file !== keepFile).forEach((file) => { file.value = ''; });
        pasted.value = '';
        status.textContent = '';
        clear.hidden = true;
    }
    clear.addEventListener('click', () => reset());
    files.forEach((input) => input.addEventListener('change', () => {
        reset(input);
        if (input.files.length) {
            status.textContent = input.files[0].name;
            clear.hidden = false;
        }
    }));
    box.querySelector('[data-paste]').addEventListener('paste', (event) => {
        const item = [...(event.clipboardData?.items ?? [])].find((entry) => entry.kind === 'file' && entry.type.startsWith('image/'));
        const image = item?.getAsFile();
        event.preventDefault();
        if (!image || image.size > <?= ER_FILE_LIMIT ?> || !['image/png', 'image/jpeg', 'image/webp'].includes(image.type)) {
            status.textContent = '14MB 이하의 JPG, PNG, WEBP 이미지를 붙여넣어 주세요.';
            return;
        }
        reset();
        clear.hidden = false;
        status.textContent = '스크린샷을 읽는 중입니다.';
        const pending = new FileReader();
        reader = pending;
        pending.onload = () => {
            if (reader !== pending) return;
            pasted.value = pending.result;
            status.textContent = '스크린샷이 첨부되었습니다.';
        };
        pending.onerror = () => { status.textContent = '스크린샷을 읽지 못했습니다.'; };
        pending.readAsDataURL(image);
    });
    box.closest('form').addEventListener('submit', (event) => {
        if (reader?.readyState === FileReader.LOADING) event.preventDefault();
    });
});
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
</script>
</body>
</html>
