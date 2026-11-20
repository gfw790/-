<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/waste_management_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');

$db = wm_db();

// Serve storage check photo inline
if (isset($_GET['view_photo'])) {
    $fn = basename((string)$_GET['view_photo']);
    if (!preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn)) {
        http_response_code(400);
        exit('잘못된 요청입니다.');
    }
    $path = wm_photo_dir() . '/' . $fn;
    if (!is_file($path)) {
        http_response_code(404);
        exit('사진을 찾을 수 없습니다.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

// Serve evidence download
if (isset($_GET['download_evidence'])) {
    $evId = (int)$_GET['download_evidence'];
    $stmt = $db->prepare('SELECT * FROM wm_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$evId]);
    $ev = $stmt->fetch();
    if (!$ev) {
        http_response_code(404);
        exit('증빙 파일을 찾을 수 없습니다.');
    }
    $path = wm_evidence_dir() . '/' . basename((string)$ev['stored_name']);
    if (!is_file($path)) {
        http_response_code(404);
        exit('증빙 파일이 존재하지 않습니다.');
    }
    $inline = isset($_GET['inline']) && in_array($ev['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('Content-Type: ' . $ev['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode((string)$ev['original_name']));
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['wm_csrf'])) {
    $_SESSION['wm_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['wm_csrf'];

$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) {
    $year = $nowYear;
}
$tab = (string)($_GET['tab'] ?? 'process');
if (!in_array($tab, ['process', 'log', 'storage', 'evidence'], true)) {
    $tab = 'process';
}

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postCsrf)) {
        $flashError = '보안 토큰이 만료되었습니다. 새로고침 후 다시 시도해 주세요.';
    } elseif (!$canManage) {
        $flashError = '관리 권한이 필요합니다.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $postTab = (string)($_POST['tab'] ?? $tab);
        if (in_array($postTab, ['process', 'log', 'storage', 'evidence'], true)) {
            $tab = $postTab;
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($action === 'save_process_doc') {
                wm_get_process_doc($db, $year);
                $db->prepare('UPDATE wm_process_doc SET department=?, manager_name=?, approver_name=?, revision_date=?, step1_title=?, step1_desc=?, step2_title=?, step2_desc=?, step3_title=?, step3_desc=?, step4_title=?, step4_desc=?, step5_title=?, step5_desc=?, updated_by=?, updated_at=? WHERE year=?')
                   ->execute([
                       trim((string)($_POST['department'] ?? '공사팀')),
                       trim((string)($_POST['manager_name'] ?? '김남균')),
                       trim((string)($_POST['approver_name'] ?? '정연탁')),
                       trim((string)($_POST['revision_date'] ?? ($year . '-01-02'))),
                       trim((string)($_POST['step1_title'] ?? '')),
                       trim((string)($_POST['step1_desc'] ?? '')),
                       trim((string)($_POST['step2_title'] ?? '')),
                       trim((string)($_POST['step2_desc'] ?? '')),
                       trim((string)($_POST['step3_title'] ?? '')),
                       trim((string)($_POST['step3_desc'] ?? '')),
                       trim((string)($_POST['step4_title'] ?? '')),
                       trim((string)($_POST['step4_desc'] ?? '')),
                       trim((string)($_POST['step5_title'] ?? '')),
                       trim((string)($_POST['step5_desc'] ?? '')),
                       $actor,
                       $now,
                       $year,
                   ]);
                $flashSuccess = '4.1.1 폐기물 처리 프로세스(업무 Flow)가 저장되었습니다.';
            } elseif ($action === 'save_waste_type') {
                $id = (int)($_POST['id'] ?? 0);
                $wasteName = trim((string)($_POST['waste_name'] ?? ''));
                if ($wasteName === '') {
                    throw new InvalidArgumentException('폐기물명을 입력해 주세요.');
                }
                $fields = [
                    (int)($_POST['sort_order'] ?? 10),
                    trim((string)($_POST['category'] ?? '일반폐기물')),
                    $wasteName,
                    trim((string)($_POST['waste_code'] ?? '')),
                    trim((string)($_POST['state_form'] ?? '고체')),
                    trim((string)($_POST['source_process'] ?? '')),
                    trim((string)($_POST['storage_place'] ?? '')),
                    trim((string)($_POST['storage_capacity'] ?? '')),
                    trim((string)($_POST['storage_period'] ?? '90일간')),
                    trim((string)($_POST['characteristic'] ?? '')),
                    trim((string)($_POST['manager_name'] ?? '김남균')),
                    trim((string)($_POST['caution_storage'] ?? '')),
                    trim((string)($_POST['caution_transport'] ?? '')),
                    trim((string)($_POST['caution_disposal'] ?? '')),
                    trim((string)($_POST['disposal_method'] ?? '')),
                    trim((string)($_POST['disposal_vendor'] ?? '')),
                    in_array((string)($_POST['sign_theme'] ?? 'general'), ['general', 'recycle', 'designated'], true) ? (string)$_POST['sign_theme'] : 'general',
                ];
                if ($id > 0) {
                    $db->prepare('UPDATE wm_waste_types SET sort_order=?, category=?, waste_name=?, waste_code=?, state_form=?, source_process=?, storage_place=?, storage_capacity=?, storage_period=?, characteristic=?, manager_name=?, caution_storage=?, caution_transport=?, caution_disposal=?, disposal_method=?, disposal_vendor=?, sign_theme=?, updated_at=? WHERE id=?')
                       ->execute([...$fields, $now, $id]);
                    $flashSuccess = '폐기물 배출 현황 항목이 수정되었습니다.';
                } else {
                    $db->prepare('INSERT INTO wm_waste_types (sort_order, category, waste_name, waste_code, state_form, source_process, storage_place, storage_capacity, storage_period, characteristic, manager_name, caution_storage, caution_transport, caution_disposal, disposal_method, disposal_vendor, sign_theme, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                       ->execute([...$fields, $now, $now]);
                    $flashSuccess = '새 폐기물 배출 현황 항목이 등록되었습니다.';
                }
            } elseif ($action === 'delete_waste_type') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('UPDATE wm_waste_types SET active = 0, updated_at = ? WHERE id = ?')->execute([$now, $id]);
                    $flashSuccess = '폐기물 항목이 목록에서 비활성화되었습니다.';
                }
            } elseif ($action === 'save_reporting_info') {
                wm_get_reporting_info($db, $year);
                $db->prepare('UPDATE wm_reporting_info SET report_status=?, report_number=?, report_date=?, allbaro_id=?, vendor_name=?, contract_period=?, department=?, manager_name=?, notes=?, updated_by=?, updated_at=? WHERE year=?')
                   ->execute([
                       trim((string)($_POST['report_status'] ?? '해당(신고완료)')),
                       trim((string)($_POST['report_number'] ?? '')),
                       trim((string)($_POST['report_date'] ?? '')),
                       trim((string)($_POST['allbaro_id'] ?? '')),
                       trim((string)($_POST['vendor_name'] ?? '')),
                       trim((string)($_POST['contract_period'] ?? '')),
                       trim((string)($_POST['department'] ?? '공사팀')),
                       trim((string)($_POST['manager_name'] ?? '김남균')),
                       trim((string)($_POST['notes'] ?? '')),
                       $actor,
                       $now,
                       $year,
                   ]);
                $flashSuccess = '4.1.2 폐기물 배출자 신고·위탁계약 정보가 저장되었습니다.';
            } elseif ($action === 'save_disposal_log') {
                $id = (int)($_POST['id'] ?? 0);
                $wasteName = trim((string)($_POST['waste_name'] ?? ''));
                $occurDate = trim((string)($_POST['occur_date'] ?? ''));
                if ($wasteName === '' || $occurDate === '') {
                    throw new InvalidArgumentException('발생일자와 폐기물 종류를 입력해 주세요.');
                }
                $wtId = (int)($_POST['waste_type_id'] ?? 0);
                $params = [
                    $wtId > 0 ? $wtId : null,
                    $wasteName,
                    trim((string)($_POST['category'] ?? '일반폐기물')),
                    trim((string)($_POST['unit'] ?? 'kg')),
                    $occurDate,
                    (float)($_POST['occur_qty'] ?? 0),
                    trim((string)($_POST['self_date'] ?? '')),
                    (float)($_POST['self_qty'] ?? 0),
                    trim((string)($_POST['self_method'] ?? '')),
                    trim((string)($_POST['consign_date'] ?? '')),
                    (float)($_POST['consign_qty'] ?? 0),
                    trim((string)($_POST['consign_method'] ?? '')),
                    trim((string)($_POST['consign_vendor'] ?? '')),
                    trim((string)($_POST['manifest_no'] ?? '')),
                    trim((string)($_POST['note'] ?? '')),
                ];
                if ($id > 0) {
                    $db->prepare('UPDATE wm_disposal_log SET waste_type_id=?, waste_name=?, category=?, unit=?, occur_date=?, occur_qty=?, self_date=?, self_qty=?, self_method=?, consign_date=?, consign_qty=?, consign_method=?, consign_vendor=?, manifest_no=?, note=?, updated_at=? WHERE id=? AND year=?')
                       ->execute([...$params, $now, $id, $year]);
                    $flashSuccess = '폐기물 관리대장 기록이 수정되었습니다.';
                } else {
                    $db->prepare('INSERT INTO wm_disposal_log (year, waste_type_id, waste_name, category, unit, occur_date, occur_qty, self_date, self_qty, self_method, consign_date, consign_qty, consign_method, consign_vendor, manifest_no, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$year, ...$params, $actor, $now, $now]);
                    $flashSuccess = '폐기물 관리대장에 새 기록이 등록되었습니다.';
                }
            } elseif ($action === 'delete_disposal_log') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('DELETE FROM wm_disposal_log WHERE id = ? AND year = ?')->execute([$id, $year]);
                    $flashSuccess = '관리대장 기록이 삭제되었습니다.';
                }
            } elseif ($action === 'save_storage_check') {
                $checkDate = trim((string)($_POST['check_date'] ?? date('Y-m-d')));
                $inspector = trim((string)($_POST['inspector'] ?? $actor));
                $location = trim((string)($_POST['location'] ?? '현대기전 자재창고 및 현장 폐기물 보관장소'));
                $items = [
                    'chk_sign_installed' => (string)($_POST['chk_sign_installed'] ?? '적합'),
                    'chk_bin_labeled' => (string)($_POST['chk_bin_labeled'] ?? '적합'),
                    'chk_segregated' => (string)($_POST['chk_segregated'] ?? '적합'),
                    'chk_containment' => (string)($_POST['chk_containment'] ?? '적합'),
                    'chk_capacity_period' => (string)($_POST['chk_capacity_period'] ?? '적합'),
                    'chk_housekeeping' => (string)($_POST['chk_housekeeping'] ?? '적합'),
                ];
                $hasFail = in_array('보완필요', $items, true);
                $overall = $hasFail ? '보완필요' : '적합';

                $photoSign = wm_save_photo_file($_FILES['photo_sign'] ?? null, (string)($_POST['photo_sign_base64'] ?? ''), 'sign');
                $photoBin = wm_save_photo_file($_FILES['photo_bin'] ?? null, (string)($_POST['photo_bin_base64'] ?? ''), 'bin');

                $db->prepare('INSERT INTO wm_storage_checks (year, check_date, inspector, location, chk_sign_installed, chk_bin_labeled, chk_segregated, chk_containment, chk_capacity_period, chk_housekeeping, overall_result, issue_note, action_note, photo_sign, photo_bin, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, $checkDate, $inspector, $location,
                       $items['chk_sign_installed'], $items['chk_bin_labeled'], $items['chk_segregated'],
                       $items['chk_containment'], $items['chk_capacity_period'], $items['chk_housekeeping'],
                       $overall,
                       trim((string)($_POST['issue_note'] ?? '')),
                       trim((string)($_POST['action_note'] ?? '')),
                       $photoSign, $photoBin,
                       $actor, $now, $now,
                   ]);
                $flashSuccess = '4.1.3 폐기물 보관·분리배출 정기점검이 등록되었습니다.';
            } elseif ($action === 'delete_storage_check') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('DELETE FROM wm_storage_checks WHERE id = ? AND year = ?')->execute([$id, $year]);
                    $flashSuccess = '보관·분리배출 점검 기록이 삭제되었습니다.';
                }
            } elseif ($action === 'upload_evidence') {
                $itemCode = (string)($_POST['item_code'] ?? '');
                $kind = (string)($_POST['kind'] ?? '');
                $note = trim((string)($_POST['note'] ?? ''));
                wm_upload_evidence($db, $year, $itemCode, $kind, $_FILES['evidence_file'] ?? null, (string)($_POST['evidence_base64'] ?? ''), $note, $actor);
                $flashSuccess = $itemCode . ' 증빙 자료가 등록되었습니다.';
            } elseif ($action === 'delete_evidence') {
                $evId = (int)($_POST['id'] ?? 0);
                if ($evId > 0) {
                    $db->prepare('UPDATE wm_evidence SET deleted_at = ? WHERE id = ? AND year = ?')->execute([$now, $evId, $year]);
                    $flashSuccess = '증빙 파일이 삭제되었습니다.';
                }
            } elseif ($action === 'save_assessment') {
                $itemCode = (string)($_POST['item_code'] ?? '');
                if (!isset(wm_item_meta()[$itemCode])) {
                    throw new InvalidArgumentException('평가 항목을 확인해 주세요.');
                }
                $multVal = (string)($_POST['multiplier'] ?? 'auto');
                $note = trim((string)($_POST['note'] ?? ''));
                if ($multVal === 'auto') {
                    $db->prepare('DELETE FROM wm_assessments WHERE year = ? AND item_code = ?')->execute([$year, $itemCode]);
                    $flashSuccess = $itemCode . ' 평가 수준이 자동(증빙 등록 시 100%)으로 변경되었습니다.';
                } else {
                    $mult = (float)$multVal;
                    if (!in_array($mult, [1.0, 0.9, 0.8, 0.5, 0.0], true)) {
                        throw new InvalidArgumentException('유효한 평가 수준을 선택해 주세요.');
                    }
                    $db->prepare('INSERT INTO wm_assessments (year, item_code, multiplier, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(year, item_code) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
                       ->execute([$year, $itemCode, $mult, $note, $actor, $now]);
                    $flashSuccess = $itemCode . ' 평가 수준이 저장되었습니다.';
                }
            }
        } catch (Throwable $e) {
            $flashError = $e->getMessage();
        }
    }
}

$processDoc = wm_get_process_doc($db, $year);
$reportingInfo = wm_get_reporting_info($db, $year);
$wasteTypes = wm_list_waste_types($db, true);
$disposalLogs = wm_list_disposal_logs($db, $year);
$storageChecks = wm_list_storage_checks($db, $year);
$allEvidence = wm_list_evidence($db, $year);

$evidenceByItem = ['4.1.1' => [], '4.1.2' => [], '4.1.3' => []];
foreach ($allEvidence as $ev) {
    if (isset($evidenceByItem[$ev['item_code']])) {
        $evidenceByItem[$ev['item_code']][] = $ev;
    }
}

$scores = [
    '4.1.1' => csm_wm_score_summary($year, '4.1.1', 1.0),
    '4.1.2' => csm_wm_score_summary($year, '4.1.2', 1.0),
    '4.1.3' => csm_wm_score_summary($year, '4.1.3', 1.0),
];
$totalEarned = round($scores['4.1.1']['earned'] + $scores['4.1.2']['earned'] + $scores['4.1.3']['earned'], 2);

$itemMeta = wm_item_meta();
$evidenceKinds = wm_evidence_kinds();
$signThemes = wm_sign_themes();

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function wm_fmt_size(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}

function wm_render_evidence_box(string $itemCode, string $tabName, int $year, array $items, array $score, array $kinds, array $meta, bool $canManage, string $csrf, callable $e): void
{
    $curMult = $score['assessment'] !== null ? (string)$score['assessment']['multiplier'] : 'auto';
    $curNote = $score['assessment']['note'] ?? '';
    ?>
    <section class="card ev-card" id="ev-<?= $e($itemCode) ?>">
      <div class="card-head">
        <div>
          <h3>📎 <?= $e($itemCode) ?> 증빙 자료 및 점수 관리</h3>
          <p class="sub">필요 증빙: <?= $e($meta['evidence_guide']) ?></p>
        </div>
        <div class="score-pill <?= $score['earned'] >= 1.0 ? 'ok' : ($score['evidence_count'] > 0 ? 'warn' : 'empty') ?>">
          현재 점수 <strong><?= number_format($score['earned'], 1) ?> / <?= number_format($meta['points'], 1) ?>점</strong>
          (증빙 <?= (int)$score['evidence_count'] ?>건)
        </div>
      </div>

      <div class="ev-grid">
        <div>
          <?php if ($canManage): ?>
          <form method="post" enctype="multipart/form-data" class="ev-upload-form">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="upload_evidence">
            <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
            <input type="hidden" name="item_code" value="<?= $e($itemCode) ?>">
            <input type="hidden" name="evidence_base64" class="js-paste-b64" value="">
            <div class="form-row">
              <div class="field">
                <label>증빙 구분</label>
                <select name="kind" required>
                  <?php foreach ($kinds as $k => $lbl): ?>
                    <option value="<?= $e($k) ?>"><?= $e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>파일 선택 (PDF/이미지/엑셀/워드, 최대 20MB)</label>
                <input type="file" name="evidence_file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.docx">
              </div>
            </div>
            <div class="paste-zone js-paste-zone" tabindex="0">
              <span class="js-paste-label">📋 이곳을 클릭 후 <strong>Ctrl+V</strong> 로 스크린샷을 바로 붙여넣거나, 아래 버튼을 누르세요.</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 스크린샷 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="붙여넣기 미리보기" style="display:none;">
            </div>
            <div class="form-row" style="margin-top:8px;">
              <div class="field" style="flex:1;">
                <input type="text" name="note" placeholder="증빙 설명/메모 (선택)">
              </div>
              <button type="submit" class="btn primary">증빙 등록</button>
            </div>
          </form>
          <?php endif; ?>

          <div class="ev-list">
            <?php if (!$items): ?>
              <div class="empty-msg">등록된 증빙 파일이 없습니다. 증빙을 등록하면 CSM 포털 점수가 즉시 반영됩니다.</div>
            <?php else: ?>
              <?php foreach ($items as $ev): ?>
                <div class="ev-item">
                  <div class="ev-info">
                    <span class="badge"><?= $e($kinds[$ev['kind']] ?? $ev['kind']) ?></span>
                    <a href="?download_evidence=<?= (int)$ev['id'] ?>&amp;inline=1" target="_blank" class="ev-name"><?= $e($ev['original_name']) ?></a>
                    <span class="ev-meta"><?= wm_fmt_size((int)$ev['size']) ?> · <?= $e($ev['uploaded_by']) ?> · <?= $e(substr((string)$ev['uploaded_at'], 0, 10)) ?></span>
                    <?php if ($ev['note'] !== ''): ?><div class="ev-note"><?= $e($ev['note']) ?></div><?php endif; ?>
                  </div>
                  <div class="ev-actions">
                    <a href="?download_evidence=<?= (int)$ev['id'] ?>" class="btn sm ghost">다운로드</a>
                    <?php if ($canManage): ?>
                    <form method="post" onsubmit="return confirm('이 증빙 파일을 삭제할까요?');" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_evidence">
                      <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
                      <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                      <button type="submit" class="btn sm danger">삭제</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="assess-box">
          <h4>평가 수준 설정 (<?= $e($itemCode) ?>)</h4>
          <p class="sub">기본값은 증빙 등록 시 100%(1.0점) 자동 반영됩니다. 자체 점검 결과에 따라 수준을 조정할 수 있습니다.</p>
          <?php if ($canManage): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="save_assessment">
            <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
            <input type="hidden" name="item_code" value="<?= $e($itemCode) ?>">
            <div class="field">
              <label>평가 수준</label>
              <select name="multiplier">
                <option value="auto" <?= $curMult === 'auto' ? 'selected' : '' ?>>자동 (증빙 등록 시 100% · 1.0점)</option>
                <option value="1" <?= $curMult === '1' || $curMult === '1.0' ? 'selected' : '' ?>>100% (1.0점) — 완전 이행</option>
                <option value="0.9" <?= $curMult === '0.9' ? 'selected' : '' ?>>90% (0.9점) — 우수 이행</option>
                <option value="0.8" <?= $curMult === '0.8' ? 'selected' : '' ?>>80% (0.8점) — 양호 이행</option>
                <option value="0.5" <?= $curMult === '0.5' ? 'selected' : '' ?>>50% (0.5점) — 일부 보완 필요</option>
                <option value="0" <?= $curMult === '0' || $curMult === '0.0' ? 'selected' : '' ?>>0% (0.0점) — 미이행</option>
              </select>
            </div>
            <div class="field" style="margin-top:8px;">
              <label>검토 의견</label>
              <textarea name="note" rows="2" placeholder="자체 평가 및 보완사항 메모"><?= $e($curNote) ?></textarea>
            </div>
            <button type="submit" class="btn primary" style="margin-top:8px; width:100%;">평가 수준 저장</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>4.1 폐기물·환경관리 · CSM</title>
<style>
  :root {
    --bg: #f4f7f6;
    --card: #ffffff;
    --line: #dbe4e0;
    --ink: #172521;
    --muted: #5b6e68;
    --brand: #0f766e;
    --brand-dark: #0d5e58;
    --brand-soft: #e6f6f4;
    --ok: #15803d;
    --ok-bg: #dcfce7;
    --warn: #b45309;
    --warn-bg: #fef3c7;
    --danger: #b91c1c;
    --danger-bg: #fee2e2;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif; background: var(--bg); color: var(--ink); font-size: 14px; line-height: 1.5; }
  a { color: inherit; text-decoration: none; }

  .topbar {
    background: #103b35; color: #fff; padding: 0 20px; height: 54px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    position: sticky; top: 0; z-index: 50; box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  }
  .topbar-left { display: flex; align-items: center; gap: 14px; }
  .topbar-back { color: #b7e4dc; font-size: 13px; padding: 5px 10px; border-radius: 6px; background: rgba(255,255,255,0.08); }
  .topbar-back:hover { background: rgba(255,255,255,0.16); color: #fff; }
  .topbar-title { font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }
  .topbar-right { display: flex; align-items: center; gap: 10px; font-size: 13px; }
  .year-select { background: rgba(255,255,255,0.14); color: #fff; border: 1px solid rgba(255,255,255,0.25); border-radius: 6px; padding: 5px 10px; font-weight: 700; cursor: pointer; }
  .year-select option { color: #111; }

  .wrap { max-width: 1420px; margin: 0 auto; padding: 20px 20px 60px; }

  .hero {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px;
    margin-bottom: 18px;
  }
  .hero small { color: var(--brand); font-weight: 800; font-size: 12px; letter-spacing: 0.4px; }
  .hero h1 { margin: 4px 0 4px; font-size: 24px; font-weight: 900; letter-spacing: -0.5px; }
  .hero p { margin: 0; color: var(--muted); font-size: 13px; }
  .print-bar { display: flex; flex-wrap: wrap; gap: 8px; }

  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px; border: 1px solid var(--line);
    background: #fff; color: var(--ink); font-weight: 700; font-size: 13px; cursor: pointer;
    transition: all 0.14s ease; white-space: nowrap;
  }
  .btn:hover { background: #f1f5f4; border-color: #b7c8c2; }
  .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn.primary:hover { background: var(--brand-dark); }
  .btn.sign-btn { background: #009245; border-color: #007a3a; color: #fff; }
  .btn.sign-btn:hover { background: #007a3a; }
  .btn.danger { background: #fff; border-color: #fecaca; color: var(--danger); }
  .btn.danger:hover { background: var(--danger-bg); }
  .btn.ghost { background: #f8faf9; }
  .btn.sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 700; font-size: 13px; }
  .flash.ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #bbf7d0; }
  .flash.err { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; }

  .kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
  .kpi {
    background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px;
    display: flex; flex-direction: column; gap: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  }
  .kpi.total { background: linear-gradient(135deg, #0f766e, #115e59); color: #fff; border-color: #0f766e; }
  .kpi-code { font-size: 12px; font-weight: 800; color: var(--brand); }
  .kpi.total .kpi-code { color: #99f6e4; }
  .kpi-title { font-size: 13px; font-weight: 700; }
  .kpi-val { font-size: 22px; font-weight: 900; margin-top: 2px; }
  .kpi-sub { font-size: 12px; color: var(--muted); }
  .kpi.total .kpi-sub { color: #ccfbf1; }

  .tabs {
    display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px;
    border-bottom: 2px solid var(--line); padding-bottom: 0;
  }
  .tab-link {
    padding: 11px 18px; font-weight: 800; font-size: 14px; color: var(--muted);
    border-bottom: 3px solid transparent; margin-bottom: -2px; border-radius: 8px 8px 0 0;
  }
  .tab-link:hover { color: var(--ink); background: rgba(15,118,110,0.05); }
  .tab-link.active { color: var(--brand); border-bottom-color: var(--brand); background: #fff; }

  .card {
    background: var(--card); border: 1px solid var(--line); border-radius: 14px;
    padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);
  }
  .card-head {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px;
    margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #edf2f0;
  }
  .card-head h2, .card-head h3 { margin: 0; font-size: 16px; font-weight: 900; }
  .sub { margin: 2px 0 0; font-size: 12.5px; color: var(--muted); }

  /* 5-step process flow */
  .flow-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
  .flow-step {
    background: #f8fbfa; border: 1px solid #cfe2dc; border-top: 4px solid var(--brand);
    border-radius: 10px; padding: 12px; position: relative;
  }
  .flow-step strong { display: block; font-size: 13.5px; color: var(--brand-dark); margin-bottom: 6px; }
  .flow-step p { margin: 0; font-size: 12.5px; color: #334540; line-height: 1.5; }

  /* Tables */
  .tbl-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { padding: 9px 10px; border-bottom: 1px solid #e9efec; text-align: left; vertical-align: middle; }
  th { background: #f2f7f5; font-weight: 800; color: #243b35; white-space: nowrap; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #fafdfc; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .ctr { text-align: center; }

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 800;
    background: #e6f4f1; color: #0f766e; white-space: nowrap;
  }
  .badge.recycle { background: #fef9c3; color: #854d0e; }
  .badge.designated { background: #fee2e2; color: #991b1b; }
  .badge.life { background: #e0f2fe; color: #075985; }
  .badge.ok { background: var(--ok-bg); color: var(--ok); }
  .badge.warn { background: var(--warn-bg); color: var(--warn); }

  /* Forms */
  .form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
  .form-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .form-grid.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
  .field { display: flex; flex-direction: column; gap: 4px; }
  .field.span-2 { grid-column: span 2; }
  .field.span-3 { grid-column: span 3; }
  .field label { font-size: 12px; font-weight: 800; color: #364b45; }
  .field input[type="text"], .field input[type="date"], .field input[type="number"], .field select, .field textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #cbd7d2; border-radius: 8px;
    font-family: inherit; font-size: 13px; background: #fff; color: var(--ink);
  }
  .field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(15,118,110,0.12); }

  /* Paste zone */
  .paste-zone {
    margin-top: 8px; padding: 10px 12px; border: 2px dashed #9cc5bc; border-radius: 10px;
    background: #f5fbf9; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px;
    font-size: 12.5px; color: #2d554c; cursor: pointer;
  }
  .paste-zone:focus { outline: none; border-color: var(--brand); background: #eaf7f4; }
  .paste-preview { max-height: 110px; border-radius: 6px; border: 1px solid #cbd7d2; margin-top: 6px; }

  /* Evidence box */
  .score-pill { padding: 6px 12px; border-radius: 999px; font-size: 13px; font-weight: 700; }
  .score-pill.ok { background: var(--ok-bg); color: var(--ok); }
  .score-pill.warn { background: var(--warn-bg); color: var(--warn); }
  .score-pill.empty { background: #eef2f0; color: var(--muted); }
  .ev-grid { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 18px; align-items: start; }
  .ev-upload-form { background: #f8fbfa; border: 1px solid var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
  .ev-list { display: flex; flex-direction: column; gap: 8px; }
  .ev-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 9px; background: #fff; }
  .ev-info { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .ev-name { font-weight: 800; color: var(--brand-dark); }
  .ev-name:hover { text-decoration: underline; }
  .ev-meta { font-size: 12px; color: var(--muted); }
  .ev-note { width: 100%; font-size: 12px; color: #475569; }
  .ev-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .assess-box { background: #f8fbfa; border: 1px solid var(--line); border-radius: 10px; padding: 14px; }
  .assess-box h4 { margin: 0 0 4px; font-size: 14px; }
  .empty-msg { padding: 18px; text-align: center; color: var(--muted); background: #f9fbfa; border: 1px dashed var(--line); border-radius: 10px; font-size: 13px; }

  /* Storage check items */
  .chk-table th, .chk-table td { padding: 8px 10px; }
  .radio-grp { display: flex; gap: 10px; }
  .radio-grp label { display: inline-flex; align-items: center; gap: 4px; font-weight: 700; font-size: 12.5px; cursor: pointer; }
  .photo-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
  .photo-thumb { width: 72px; height: 54px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd7d2; cursor: pointer; }
  .sign-quick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
  .sign-card {
    border-radius: 10px; padding: 12px 14px; display: flex; flex-direction: column; justify-content: space-between; gap: 8px;
    border: 2px solid transparent;
  }
  .sign-card.general { background: #009245; color: #fff; }
  .sign-card.recycle { background: #fff200; color: #111; border-color: #d4c400; }
  .sign-card.designated { background: #c81e1e; color: #fff; }
  .sign-card small { opacity: 0.9; font-weight: 700; }
  .sign-card strong { font-size: 15px; }

  /* Modal */
  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 200;
    display: none; align-items: center; justify-content: center; padding: 16px;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 780px; max-height: 90vh; overflow-y: auto;
    padding: 20px 22px; box-shadow: 0 20px 50px rgba(0,0,0,0.28);
  }
  .modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--line); }
  .modal-head h3 { margin: 0; font-size: 17px; }
  .modal-close { border: none; background: #eef2f0; width: 32px; height: 32px; border-radius: 8px; font-size: 18px; cursor: pointer; }

  @media (max-width: 1024px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .flow-grid { grid-template-columns: repeat(2, 1fr); }
    .ev-grid { grid-template-columns: 1fr; }
    .form-grid, .form-grid.cols-4 { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 640px) {
    .kpi-grid, .flow-grid, .form-grid, .form-grid.cols-2, .form-grid.cols-4 { grid-template-columns: 1fr; }
    .field.span-2, .field.span-3 { grid-column: span 1; }
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <a class="topbar-back" href="/CSM/area.php?group=4&amp;section=4.1">← 4.1 영역</a>
    <a class="topbar-back" href="/CSM/">CSM 포털</a>
    <span class="topbar-title">4.1 폐기물·환경관리 활동기록</span>
  </div>
  <div class="topbar-right">
    <form method="get" style="display:inline;">
      <input type="hidden" name="tab" value="<?= $e($tab) ?>">
      <select name="year" class="year-select" onchange="this.form.submit()">
        <?php for ($y = $nowYear + 1; $y >= 2024; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년</option>
        <?php endfor; ?>
      </select>
    </form>
    <span><?= $e($actor) ?></span>
  </div>
</header>

<main class="wrap">
  <div class="hero">
    <div>
      <small>CSM 평가 영역 4 · 환경관리 · 4.1.1 ~ 4.1.3 (총 3.0점)</small>
      <h1><?= $year ?>년 폐기물 배출·신고·보관 관리</h1>
      <p>주식회사 현대기전 현장의 폐기물 배출현황·처리프로세스(4.1.1), 신고·관리대장(4.1.2), 보관표지·분리배출 점검(4.1.3)을 통합 관리합니다.</p>
    </div>
    <div class="print-bar">
      <a class="btn" href="waste_management_print.php?mode=process&amp;year=<?= $year ?>" target="_blank">🖨️ 4.1.1 프로세스·목록 출력</a>
      <a class="btn" href="waste_management_print.php?mode=log&amp;year=<?= $year ?>" target="_blank">🖨️ 4.1.2 관리대장 출력</a>
      <a class="btn" href="waste_management_print.php?mode=storage&amp;year=<?= $year ?>" target="_blank">🖨️ 4.1.3 보관점검표 출력</a>
      <a class="btn sign-btn" href="waste_management_print.php?mode=sign&amp;year=<?= $year ?>" target="_blank">🪧 보관표지판 A4 출력</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash ok">✅ <?= $e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash err">⚠️ <?= $e($flashError) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <a class="kpi" href="?year=<?= $year ?>&amp;tab=process">
      <span class="kpi-code">4.1.1 (1.0점)</span>
      <span class="kpi-title">배출현황·처리프로세스</span>
      <span class="kpi-val"><?= number_format($scores['4.1.1']['earned'], 1) ?> / 1.0점</span>
      <span class="kpi-sub">등록 폐기물 <?= count($wasteTypes) ?>종 · 증빙 <?= (int)$scores['4.1.1']['evidence_count'] ?>건</span>
    </a>
    <a class="kpi" href="?year=<?= $year ?>&amp;tab=log">
      <span class="kpi-code">4.1.2 (1.0점)</span>
      <span class="kpi-title">배출신고·관리대장</span>
      <span class="kpi-val"><?= number_format($scores['4.1.2']['earned'], 1) ?> / 1.0점</span>
      <span class="kpi-sub">관리대장 <?= count($disposalLogs) ?>건 · 증빙 <?= (int)$scores['4.1.2']['evidence_count'] ?>건</span>
    </a>
    <a class="kpi" href="?year=<?= $year ?>&amp;tab=storage">
      <span class="kpi-code">4.1.3 (1.0점)</span>
      <span class="kpi-title">보관표지·분리배출 점검</span>
      <span class="kpi-val"><?= number_format($scores['4.1.3']['earned'], 1) ?> / 1.0점</span>
      <span class="kpi-sub">정기점검 <?= count($storageChecks) ?>건 · 사진·증빙 <?= (int)$scores['4.1.3']['evidence_count'] ?>건</span>
    </a>
    <a class="kpi total" href="?year=<?= $year ?>&amp;tab=evidence">
      <span class="kpi-code">4.1 환경·폐기물 합계</span>
      <span class="kpi-title">CSM 포털 실시간 반영 점수</span>
      <span class="kpi-val"><?= number_format($totalEarned, 1) ?> / 3.0점</span>
      <span class="kpi-sub">달성률 <?= number_format(($totalEarned / 3.0) * 100, 1) ?>% · 상·하반기 평가</span>
    </a>
  </section>

  <nav class="tabs">
    <a class="tab-link <?= $tab === 'process' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=process">4.1.1 배출현황·처리프로세스</a>
    <a class="tab-link <?= $tab === 'log' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=log">4.1.2 배출신고·관리대장</a>
    <a class="tab-link <?= $tab === 'storage' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=storage">4.1.3 보관표지·분리배출 점검</a>
    <a class="tab-link <?= $tab === 'evidence' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=evidence">📎 4.1 통합 증빙·점수 관리</a>
  </nav>

  <?php if ($tab === 'process'): ?>
    <!-- TAB 1: 4.1.1 -->
    <section class="card">
      <div class="card-head">
        <div>
          <h2>폐기물 처리 업무 Flow (프로세스)</h2>
          <p class="sub">부서: <?= $e($processDoc['department']) ?> · 관리책임자: <?= $e($processDoc['manager_name']) ?> · 승인: <?= $e($processDoc['approver_name']) ?> · 개정일: <?= $e($processDoc['revision_date']) ?></p>
        </div>
        <div style="display:flex; gap:8px;">
          <?php if ($canManage): ?>
            <button type="button" class="btn sm ghost" onclick="document.getElementById('processEditBox').style.display = document.getElementById('processEditBox').style.display === 'none' ? 'block' : 'none';">✏️ 프로세스 편집</button>
          <?php endif; ?>
          <a class="btn sm primary" href="waste_management_print.php?mode=process&amp;year=<?= $year ?>" target="_blank">🖨️ 공식 A4 출력</a>
        </div>
      </div>

      <div class="flow-grid">
        <?php for ($i = 1; $i <= 5; $i++): ?>
          <div class="flow-step">
            <strong><?= $e($processDoc['step' . $i . '_title']) ?></strong>
            <p><?= nl2br($e($processDoc['step' . $i . '_desc'])) ?></p>
          </div>
        <?php endfor; ?>
      </div>

      <?php if ($canManage): ?>
      <form method="post" id="processEditBox" style="display:none; margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_process_doc">
        <input type="hidden" name="tab" value="process">
        <div class="form-grid cols-4" style="margin-bottom:12px;">
          <div class="field"><label>담당 부서</label><input type="text" name="department" value="<?= $e($processDoc['department']) ?>"></div>
          <div class="field"><label>관리책임자</label><input type="text" name="manager_name" value="<?= $e($processDoc['manager_name']) ?>"></div>
          <div class="field"><label>승인자</label><input type="text" name="approver_name" value="<?= $e($processDoc['approver_name']) ?>"></div>
          <div class="field"><label>제·개정일</label><input type="date" name="revision_date" value="<?= $e($processDoc['revision_date']) ?>"></div>
        </div>
        <div class="form-grid cols-2">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="field">
              <label><?= $i ?>단계 제목</label>
              <input type="text" name="step<?= $i ?>_title" value="<?= $e($processDoc['step' . $i . '_title']) ?>">
              <textarea name="step<?= $i ?>_desc" rows="2" style="margin-top:4px;"><?= $e($processDoc['step' . $i . '_desc']) ?></textarea>
            </div>
          <?php endfor; ?>
        </div>
        <div style="margin-top:12px; text-align:right;">
          <button type="submit" class="btn primary">프로세스 저장</button>
        </div>
      </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2>폐기물 배출 현황 목록 (List)</h2>
          <p class="sub">사업장에서 발생하는 폐기물 종류별 성상·보관장소·처리방법·위탁업체를 관리하며, 각 항목의 현장 부착용 보관표지판을 즉시 출력할 수 있습니다.</p>
        </div>
        <?php if ($canManage): ?>
          <button type="button" class="btn primary" onclick="openWasteTypeModal()">+ 폐기물 종류 추가</button>
        <?php endif; ?>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th class="ctr">No</th>
              <th>분류</th>
              <th>폐기물명 (분류번호)</th>
              <th class="ctr">성상</th>
              <th>발생 공정</th>
              <th>보관장소 / 용량·기간</th>
              <th>처리방법 / 처리(예정)장소</th>
              <th class="ctr">관리</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($wasteTypes as $idx => $wt):
              $bClass = $wt['sign_theme'] === 'recycle' ? 'recycle' : ($wt['sign_theme'] === 'designated' ? 'designated' : ($wt['category'] === '생활폐기물' ? 'life' : ''));
            ?>
            <tr>
              <td class="ctr"><?= $idx + 1 ?></td>
              <td><span class="badge <?= $bClass ?>"><?= $e($wt['category']) ?></span></td>
              <td>
                <strong><?= $e($wt['waste_name']) ?></strong>
                <?php if ($wt['waste_code'] !== ''): ?><div class="sub"><?= $e($wt['waste_code']) ?> · <?= $e($wt['characteristic']) ?></div><?php endif; ?>
              </td>
              <td class="ctr"><?= $e($wt['state_form']) ?></td>
              <td><?= $e($wt['source_process']) ?></td>
              <td>
                <div><?= $e($wt['storage_place']) ?></div>
                <div class="sub">용량: <?= $e($wt['storage_capacity']) ?> / 기간: <?= $e($wt['storage_period']) ?></div>
              </td>
              <td>
                <div><?= $e($wt['disposal_method']) ?></div>
                <div class="sub"><?= $e($wt['disposal_vendor']) ?></div>
              </td>
              <td class="ctr" style="white-space:nowrap;">
                <a class="btn sm sign-btn" href="waste_management_print.php?mode=sign&amp;year=<?= $year ?>&amp;type_id=<?= (int)$wt['id'] ?>" target="_blank" title="현장 부착용 보관표지판 A4 인쇄">🪧 표지판</a>
                <?php if ($canManage): ?>
                  <button type="button" class="btn sm ghost js-edit-wt" data-record="<?= $e(json_encode($wt, JSON_UNESCAPED_UNICODE)) ?>">수정</button>
                  <form method="post" onsubmit="return confirm('이 폐기물 항목을 목록에서 제외할까요?');" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="delete_waste_type">
                    <input type="hidden" name="tab" value="process">
                    <input type="hidden" name="id" value="<?= (int)$wt['id'] ?>">
                    <button type="submit" class="btn sm danger">삭제</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <?php wm_render_evidence_box('4.1.1', 'process', $year, $evidenceByItem['4.1.1'], $scores['4.1.1'], $evidenceKinds['4.1.1'], $itemMeta['4.1.1'], $canManage, $csrf, $e); ?>

  <?php elseif ($tab === 'log'): ?>
    <!-- TAB 2: 4.1.2 -->
    <section class="card">
      <div class="card-head">
        <div>
          <h2>폐기물 배출자 신고 및 위탁처리 계약 현황</h2>
          <p class="sub">폐기물 배출자 신고 여부(또는 소량배출·원청 일괄처리 기준), 위탁처리 업체 및 계약기간을 기록합니다.</p>
        </div>
        <a class="btn sm primary" href="waste_management_print.php?mode=log&amp;year=<?= $year ?>" target="_blank">🖨️ 관리대장 A4 가로 출력</a>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_reporting_info">
        <input type="hidden" name="tab" value="log">
        <div class="form-grid cols-4">
          <div class="field">
            <label>배출자 신고 구분</label>
            <select name="report_status" <?= $canManage ? '' : 'disabled' ?>>
              <?php foreach (['해당(신고완료)', '원청(한라시멘트) 일괄처리·선별장 이용', '소량배출(신고 비대상) · 자체 관리대장 운영', '신고 진행중'] as $st): ?>
                <option value="<?= $e($st) ?>" <?= $reportingInfo['report_status'] === $st ? 'selected' : '' ?>><?= $e($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>신고번호 / 관리번호</label><input type="text" name="report_number" value="<?= $e($reportingInfo['report_number']) ?>" placeholder="예: 제2026-000호 또는 해당없음" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>신고일자</label><input type="date" name="report_date" value="<?= $e($reportingInfo['report_date']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>올바로(Allbaro) ID</label><input type="text" name="allbaro_id" value="<?= $e($reportingInfo['allbaro_id']) ?>" placeholder="올바로 시스템 ID (해당 시)" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field span-2"><label>주요 수거·위탁처리 업체명</label><input type="text" name="vendor_name" value="<?= $e($reportingInfo['vendor_name']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>위탁계약 기간</label><input type="text" name="contract_period" value="<?= $e($reportingInfo['contract_period']) ?>" placeholder="2026-01-01 ~ 2026-12-31" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>담당부서 / 관리책임자</label>
            <div style="display:flex; gap:6px;">
              <input type="text" name="department" value="<?= $e($reportingInfo['department']) ?>" style="width:48%;" <?= $canManage ? '' : 'readonly' ?>>
              <input type="text" name="manager_name" value="<?= $e($reportingInfo['manager_name']) ?>" style="width:52%;" <?= $canManage ? '' : 'readonly' ?>>
            </div>
          </div>
          <div class="field span-3"><label>비고 (신고·위탁처리 기준 설명)</label><input type="text" name="notes" value="<?= $e($reportingInfo['notes']) ?>" placeholder="예: 일반폐기물(고철·폐전선) 지정 수거업체 인계, 재활용품은 한라시멘트 內 선별장 분리배출" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field" style="justify-content:flex-end;">
            <?php if ($canManage): ?>
              <button type="submit" class="btn primary">신고·계약 정보 저장</button>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $year ?>년 사업장폐기물처리 관리대장</h2>
          <p class="sub">발생내역, 자가처리내역, 위탁처리내역과 품목별 누계가 자동 계산됩니다.</p>
        </div>
        <?php if ($canManage): ?>
          <button type="button" class="btn primary" onclick="openLogModal()">+ 배출·처리 기록 추가</button>
        <?php endif; ?>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th rowspan="2" class="ctr">No</th>
              <th rowspan="2">폐기물 종류</th>
              <th colspan="3" class="ctr" style="background:#e8f4f1;">발생 내역</th>
              <th colspan="4" class="ctr" style="background:#eef4fb;">자가처리 내역</th>
              <th colspan="5" class="ctr" style="background:#fef6e7;">위탁처리 내역</th>
              <th rowspan="2">비고</th>
              <th rowspan="2" class="ctr">관리</th>
            </tr>
            <tr>
              <th class="ctr">발생일</th>
              <th class="num">발생량</th>
              <th class="num">누계</th>
              <th class="ctr">처리일</th>
              <th class="num">처리량</th>
              <th>처리방법</th>
              <th class="num">누계</th>
              <th class="ctr">위탁일</th>
              <th class="num">위탁량</th>
              <th>처리방법/업체</th>
              <th>인계서번호</th>
              <th class="num">누계</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$disposalLogs): ?>
              <tr><td colspan="16" class="ctr" style="padding:26px; color:var(--muted);">등록된 <?= $year ?>년 폐기물 관리대장 기록이 없습니다. 우측 상단의 [+ 배출·처리 기록 추가] 버튼을 눌러 등록하세요.</td></tr>
            <?php else: ?>
              <?php foreach ($disposalLogs as $idx => $row): ?>
              <tr>
                <td class="ctr"><?= $idx + 1 ?></td>
                <td><strong><?= $e($row['waste_name']) ?></strong> <span class="sub">(<?= $e($row['unit']) ?>)</span></td>
                <td class="ctr"><?= $e($row['occur_date']) ?></td>
                <td class="num"><?= number_format((float)$row['occur_qty'], 1) ?></td>
                <td class="num"><strong><?= number_format((float)$row['cum_occur'], 1) ?></strong></td>
                <td class="ctr"><?= $e($row['self_date'] ?: '-') ?></td>
                <td class="num"><?= (float)$row['self_qty'] > 0 ? number_format((float)$row['self_qty'], 1) : '-' ?></td>
                <td><?= $e($row['self_method'] ?: '-') ?></td>
                <td class="num"><?= (float)$row['cum_self'] > 0 ? number_format((float)$row['cum_self'], 1) : '-' ?></td>
                <td class="ctr"><?= $e($row['consign_date'] ?: '-') ?></td>
                <td class="num"><?= (float)$row['consign_qty'] > 0 ? number_format((float)$row['consign_qty'], 1) : '-' ?></td>
                <td>
                  <?= $e($row['consign_method'] ?: '-') ?>
                  <?php if ($row['consign_vendor'] !== ''): ?><div class="sub"><?= $e($row['consign_vendor']) ?></div><?php endif; ?>
                </td>
                <td><?= $e($row['manifest_no'] ?: '-') ?></td>
                <td class="num"><?= (float)$row['cum_consign'] > 0 ? number_format((float)$row['cum_consign'], 1) : '-' ?></td>
                <td><?= $e($row['note']) ?></td>
                <td class="ctr" style="white-space:nowrap;">
                  <?php if ($canManage): ?>
                    <button type="button" class="btn sm ghost js-edit-log" data-record="<?= $e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>">수정</button>
                    <form method="post" onsubmit="return confirm('이 관리대장 기록을 삭제할까요?');" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_disposal_log">
                      <input type="hidden" name="tab" value="log">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" class="btn sm danger">삭제</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <?php wm_render_evidence_box('4.1.2', 'log', $year, $evidenceByItem['4.1.2'], $scores['4.1.2'], $evidenceKinds['4.1.2'], $itemMeta['4.1.2'], $canManage, $csrf, $e); ?>

  <?php elseif ($tab === 'storage'): ?>
    <!-- TAB 3: 4.1.3 -->
    <section class="card">
      <div class="card-head">
        <div>
          <h2>🪧 현장 부착용 폐기물 보관표지판 즉시 출력</h2>
          <p class="sub">4.1.1 목록에 등록된 폐기물 종류별 보관표지판을 A4 가로 규격으로 바로 출력·코팅하여 현장에 부착할 수 있습니다.</p>
        </div>
        <a class="btn sm sign-btn" href="waste_management_print.php?mode=sign&amp;year=<?= $year ?>" target="_blank">🖨️ 전체 표지판 일괄 출력</a>
      </div>
      <div class="sign-quick-grid">
        <?php foreach ($wasteTypes as $wt): ?>
          <div class="sign-card <?= $e($wt['sign_theme']) ?>">
            <div>
              <small><?= $e($signThemes[$wt['sign_theme']]['title'] ?? '일반 폐기물 보관표지') ?></small>
              <div><strong><?= $e($wt['waste_name']) ?></strong></div>
              <small>용량: <?= $e($wt['storage_capacity']) ?> · 책임자: <?= $e($wt['manager_name']) ?></small>
            </div>
            <a class="btn sm" style="background:rgba(255,255,255,0.92); color:#111; border:none;" href="waste_management_print.php?mode=sign&amp;year=<?= $year ?>&amp;type_id=<?= (int)$wt['id'] ?>" target="_blank">🖨️ A4 표지판 인쇄</a>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if ($canManage): ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2>폐기물 보관장소·표지판·분리배출 정기점검 등록</h2>
          <p class="sub">점검 시 보관표지판 및 분리수거함 현장 사진을 첨부(파일 선택 또는 Ctrl+V 붙여넣기)하면 4.1.3 증빙으로도 자동 반영됩니다.</p>
        </div>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_storage_check">
        <input type="hidden" name="tab" value="storage">
        <div class="form-grid" style="margin-bottom:14px;">
          <div class="field"><label>점검일자</label><input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label>점검자</label><input type="text" name="inspector" value="<?= $e($actor) ?>" required></div>
          <div class="field"><label>점검 장소</label><input type="text" name="location" value="현대기전 자재창고 및 현장 폐기물 보관장소" required></div>
        </div>

        <div class="tbl-wrap" style="margin-bottom:14px;">
          <table class="chk-table">
            <thead>
              <tr>
                <th class="ctr" style="width:46px;">No</th>
                <th>점검 항목 (4.1.3 평가 기준)</th>
                <th style="width:240px;">점검 결과</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $chkDefs = [
                'chk_sign_installed' => '폐기물 보관표지판 설치 및 기재사항(종류·용량·관리책임자·주의사항) 최신화 여부',
                'chk_bin_labeled' => '분리수거함 종류별 분리 설치 및 품목 명칭 표기 여부 (일반/재활용/폐전선·고철 등)',
                'chk_segregated' => '성상·종류별 혼합보관 금지 및 지정구역 내 분리배출 준수 여부',
                'chk_containment' => '침출수·비산·유출 방지 조치 및 보관마대/덮개 파손 여부',
                'chk_capacity_period' => '보관기간 준수 및 적정 보관용량 초과 여부',
                'chk_housekeeping' => '보관장소 주변 정리정돈 및 화기 접근 금지 등 안전관리 상태',
              ];
              $n = 1;
              foreach ($chkDefs as $name => $label):
              ?>
              <tr>
                <td class="ctr"><?= $n++ ?></td>
                <td><?= $e($label) ?></td>
                <td>
                  <div class="radio-grp">
                    <label><input type="radio" name="<?= $name ?>" value="적합" checked> 적합</label>
                    <label><input type="radio" name="<?= $name ?>" value="보완필요"> 보완필요</label>
                    <label><input type="radio" name="<?= $name ?>" value="해당없음"> 해당없음</label>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="form-grid cols-2" style="margin-bottom:14px;">
          <div class="field">
            <label>📷 현장 사진 1 — 폐기물 보관표지판 설치 상태</label>
            <input type="file" name="photo_sign" accept="image/*" capture="environment">
            <input type="hidden" name="photo_sign_base64" class="js-paste-b64" value="">
            <div class="paste-zone js-paste-zone" tabindex="0">
              <span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 로 사진 붙여넣기</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="보관표지판 미리보기" style="display:none;">
            </div>
          </div>
          <div class="field">
            <label>📷 현장 사진 2 — 분리수거함 분리·표기 상태</label>
            <input type="file" name="photo_bin" accept="image/*" capture="environment">
            <input type="hidden" name="photo_bin_base64" class="js-paste-b64" value="">
            <div class="paste-zone js-paste-zone" tabindex="0">
              <span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 로 사진 붙여넣기</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="분리수거함 미리보기" style="display:none;">
            </div>
          </div>
          <div class="field"><label>지적 / 미흡 사항</label><textarea name="issue_note" rows="2" placeholder="특이사항 없을 시 공란"></textarea></div>
          <div class="field"><label>개선 조치 내용</label><textarea name="action_note" rows="2" placeholder="즉시 조치 또는 개선 계획"></textarea></div>
        </div>

        <div style="text-align:right;">
          <button type="submit" class="btn primary">정기점검 기록 저장</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $year ?>년 보관·분리배출 정기점검 이력</h2>
          <p class="sub">등록된 점검표와 현장 사진을 확인하고 공식 A4 보고서로 출력할 수 있습니다.</p>
        </div>
        <a class="btn sm primary" href="waste_management_print.php?mode=storage&amp;year=<?= $year ?>" target="_blank">🖨️ 전체 점검표 A4 출력</a>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th class="ctr">점검일</th>
              <th class="ctr">점검자</th>
              <th>점검 장소</th>
              <th class="ctr">표지판</th>
              <th class="ctr">분리수거함</th>
              <th class="ctr">종합결과</th>
              <th>현장 사진</th>
              <th>지적·조치사항</th>
              <th class="ctr">관리</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$storageChecks): ?>
              <tr><td colspan="9" class="ctr" style="padding:24px; color:var(--muted);">등록된 <?= $year ?>년 보관·분리배출 점검 기록이 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($storageChecks as $sc): ?>
              <tr>
                <td class="ctr"><strong><?= $e($sc['check_date']) ?></strong></td>
                <td class="ctr"><?= $e($sc['inspector']) ?></td>
                <td><?= $e($sc['location']) ?></td>
                <td class="ctr"><span class="badge <?= $sc['chk_sign_installed'] === '적합' ? 'ok' : 'warn' ?>"><?= $e($sc['chk_sign_installed']) ?></span></td>
                <td class="ctr"><span class="badge <?= $sc['chk_bin_labeled'] === '적합' ? 'ok' : 'warn' ?>"><?= $e($sc['chk_bin_labeled']) ?></span></td>
                <td class="ctr"><span class="badge <?= $sc['overall_result'] === '적합' ? 'ok' : 'warn' ?>"><?= $e($sc['overall_result']) ?></span></td>
                <td>
                  <div class="photo-thumbs">
                    <?php if ($sc['photo_sign'] !== ''): ?>
                      <a href="?view_photo=<?= urlencode((string)$sc['photo_sign']) ?>" target="_blank" title="보관표지판 사진 크게 보기"><img class="photo-thumb" src="?view_photo=<?= urlencode((string)$sc['photo_sign']) ?>" alt="표지판"></a>
                    <?php endif; ?>
                    <?php if ($sc['photo_bin'] !== ''): ?>
                      <a href="?view_photo=<?= urlencode((string)$sc['photo_bin']) ?>" target="_blank" title="분리수거함 사진 크게 보기"><img class="photo-thumb" src="?view_photo=<?= urlencode((string)$sc['photo_bin']) ?>" alt="분리수거함"></a>
                    <?php endif; ?>
                    <?php if ($sc['photo_sign'] === '' && $sc['photo_bin'] === ''): ?><span class="sub">사진 없음</span><?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($sc['issue_note'] !== ''): ?><div><strong>지적:</strong> <?= $e($sc['issue_note']) ?></div><?php endif; ?>
                  <?php if ($sc['action_note'] !== ''): ?><div class="sub"><strong>조치:</strong> <?= $e($sc['action_note']) ?></div><?php endif; ?>
                  <?php if ($sc['issue_note'] === '' && $sc['action_note'] === ''): ?><span class="sub">양호</span><?php endif; ?>
                </td>
                <td class="ctr" style="white-space:nowrap;">
                  <a class="btn sm ghost" href="waste_management_print.php?mode=storage&amp;year=<?= $year ?>&amp;check_id=<?= (int)$sc['id'] ?>" target="_blank">🖨️ 출력</a>
                  <?php if ($canManage): ?>
                  <form method="post" onsubmit="return confirm('이 점검 기록을 삭제할까요?');" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="delete_storage_check">
                    <input type="hidden" name="tab" value="storage">
                    <input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
                    <button type="submit" class="btn sm danger">삭제</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <?php wm_render_evidence_box('4.1.3', 'storage', $year, $evidenceByItem['4.1.3'], $scores['4.1.3'], $evidenceKinds['4.1.3'], $itemMeta['4.1.3'], $canManage, $csrf, $e); ?>

  <?php else: ?>
    <!-- TAB 4: 통합 증빙·점수 관리 -->
    <?php foreach (['4.1.1', '4.1.2', '4.1.3'] as $code): ?>
      <?php wm_render_evidence_box($code, 'evidence', $year, $evidenceByItem[$code], $scores[$code], $evidenceKinds[$code], $itemMeta[$code], $canManage, $csrf, $e); ?>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<!-- Modal: Waste Type Add/Edit -->
<div class="modal-backdrop" id="wasteTypeModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="wtModalTitle">폐기물 배출 현황 등록</h3>
      <button type="button" class="modal-close" onclick="closeModal('wasteTypeModal')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="save_waste_type">
      <input type="hidden" name="tab" value="process">
      <input type="hidden" name="id" id="wt_id" value="0">
      <div class="form-grid">
        <div class="field"><label>정렬순서</label><input type="number" name="sort_order" id="wt_sort_order" value="10"></div>
        <div class="field">
          <label>폐기물 분류</label>
          <select name="category" id="wt_category">
            <option value="일반폐기물">일반폐기물</option>
            <option value="재활용">재활용</option>
            <option value="생활폐기물">생활폐기물</option>
            <option value="지정폐기물">지정폐기물</option>
          </select>
        </div>
        <div class="field">
          <label>보관표지판 색상 테마</label>
          <select name="sign_theme" id="wt_sign_theme">
            <option value="general">일반 폐기물 보관표지 (녹색)</option>
            <option value="recycle">재활용 보관표지 (노란색)</option>
            <option value="designated">지정 폐기물 보관표지 (적색)</option>
          </select>
        </div>
        <div class="field span-2"><label>폐기물명 *</label><input type="text" name="waste_name" id="wt_waste_name" required placeholder="예: 고철, 폐전선, 재활용 용기"></div>
        <div class="field"><label>분류번호</label><input type="text" name="waste_code" id="wt_waste_code" placeholder="예: 51-29-01"></div>
        <div class="field"><label>성상</label><input type="text" name="state_form" id="wt_state_form" value="고체"></div>
        <div class="field"><label>특성</label><input type="text" name="characteristic" id="wt_characteristic" placeholder="예: 부식성, 가연성"></div>
        <div class="field"><label>관리책임자</label><input type="text" name="manager_name" id="wt_manager_name" value="김남균"></div>
        <div class="field span-3"><label>발생 공정</label><input type="text" name="source_process" id="wt_source_process" placeholder="예: 전기·기계 배관 및 철거 작업"></div>
        <div class="field"><label>보관 장소</label><input type="text" name="storage_place" id="wt_storage_place" placeholder="지정 폐기물 보관장소"></div>
        <div class="field"><label>보관가능용량</label><input type="text" name="storage_capacity" id="wt_storage_capacity" placeholder="예: 5 톤, 100 kg"></div>
        <div class="field"><label>보관기간</label><input type="text" name="storage_period" id="wt_storage_period" value="90일간"></div>
        <div class="field"><label>처리 방법</label><input type="text" name="disposal_method" id="wt_disposal_method" value="위탁처리"></div>
        <div class="field span-2"><label>운반(처리) 예정장소 / 위탁업체</label><input type="text" name="disposal_vendor" id="wt_disposal_vendor" placeholder="예: 지정된 고철 수거 업체 / 한라시멘트 內 폐기물 선별장"></div>
        <div class="field span-3"><label>취급 시 주의사항 — 보관 시</label><textarea name="caution_storage" id="wt_caution_storage" rows="2"></textarea></div>
        <div class="field span-3"><label>취급 시 주의사항 — 운반 시</label><textarea name="caution_transport" id="wt_caution_transport" rows="2"></textarea></div>
        <div class="field span-3"><label>취급 시 주의사항 — 처리 시 (선택)</label><textarea name="caution_disposal" id="wt_caution_disposal" rows="2"></textarea></div>
      </div>
      <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="btn ghost" onclick="closeModal('wasteTypeModal')">취소</button>
        <button type="submit" class="btn primary">저장</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Disposal Log Add/Edit -->
<div class="modal-backdrop" id="logModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="logModalTitle">사업장폐기물처리 관리대장 기록 등록</h3>
      <button type="button" class="modal-close" onclick="closeModal('logModal')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="save_disposal_log">
      <input type="hidden" name="tab" value="log">
      <input type="hidden" name="id" id="log_id" value="0">
      <div class="form-grid">
        <div class="field">
          <label>등록 폐기물 선택 (빠른 입력)</label>
          <select name="waste_type_id" id="log_waste_type_id" onchange="syncLogWasteType(this)">
            <option value="0">-- 직접 입력 --</option>
            <?php foreach ($wasteTypes as $wt): ?>
              <option value="<?= (int)$wt['id'] ?>" data-name="<?= $e($wt['waste_name']) ?>" data-cat="<?= $e($wt['category']) ?>" data-vendor="<?= $e($wt['disposal_vendor']) ?>" data-method="<?= $e($wt['disposal_method']) ?>"><?= $e($wt['waste_name']) ?> (<?= $e($wt['category']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>폐기물명 *</label><input type="text" name="waste_name" id="log_waste_name" required></div>
        <div class="field">
          <label>단위</label>
          <select name="unit" id="log_unit">
            <option value="kg">kg</option>
            <option value="톤">톤</option>
            <option value="EA">EA</option>
            <option value="마대">마대</option>
          </select>
        </div>
        <input type="hidden" name="category" id="log_category" value="일반폐기물">

        <div class="field"><label>발생일자 *</label><input type="date" name="occur_date" id="log_occur_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field"><label>발생량</label><input type="number" step="0.1" min="0" name="occur_qty" id="log_occur_qty" value="0"></div>
        <div class="field"></div>

        <div class="field"><label>자가처리 일자</label><input type="date" name="self_date" id="log_self_date"></div>
        <div class="field"><label>자가처리량</label><input type="number" step="0.1" min="0" name="self_qty" id="log_self_qty" value="0"></div>
        <div class="field"><label>자가처리 방법</label><input type="text" name="self_method" id="log_self_method" placeholder="예: 선별·재사용"></div>

        <div class="field"><label>위탁처리 일자</label><input type="date" name="consign_date" id="log_consign_date"></div>
        <div class="field"><label>위탁처리량</label><input type="number" step="0.1" min="0" name="consign_qty" id="log_consign_qty" value="0"></div>
        <div class="field"><label>위탁처리 방법</label><input type="text" name="consign_method" id="log_consign_method" placeholder="예: 재활용 매각 / 위탁소각"></div>

        <div class="field span-2"><label>위탁처리 업체 / 선별장</label><input type="text" name="consign_vendor" id="log_consign_vendor" placeholder="예: 지정된 고철 수거 업체"></div>
        <div class="field"><label>인계서 / 올바로 번호</label><input type="text" name="manifest_no" id="log_manifest_no" placeholder="선택 입력"></div>

        <div class="field span-3"><label>비고</label><input type="text" name="note" id="log_note"></div>
      </div>
      <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="btn ghost" onclick="closeModal('logModal')">취소</button>
        <button type="submit" class="btn primary">저장</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.js-edit-wt').forEach(btn => {
  btn.addEventListener('click', () => { try { openWasteTypeModal(JSON.parse(btn.dataset.record || '{}')); } catch {} });
});
document.querySelectorAll('.js-edit-log').forEach(btn => {
  btn.addEventListener('click', () => { try { openLogModal(JSON.parse(btn.dataset.record || '{}')); } catch {} });
});

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function openWasteTypeModal(data) {
  document.getElementById('wtModalTitle').textContent = data ? '폐기물 배출 현황 수정' : '폐기물 배출 현황 등록';
  const fields = ['id','sort_order','category','sign_theme','waste_name','waste_code','state_form','characteristic','manager_name','source_process','storage_place','storage_capacity','storage_period','disposal_method','disposal_vendor','caution_storage','caution_transport','caution_disposal'];
  const defaults = { id: 0, sort_order: 10, category: '일반폐기물', sign_theme: 'general', state_form: '고체', manager_name: '김남균', storage_period: '90일간', disposal_method: '위탁처리' };
  fields.forEach(f => {
    const el = document.getElementById('wt_' + f);
    if (!el) return;
    el.value = data ? (data[f] ?? '') : (defaults[f] ?? '');
  });
  document.getElementById('wasteTypeModal').classList.add('open');
}

function openLogModal(data) {
  document.getElementById('logModalTitle').textContent = data ? '관리대장 기록 수정' : '사업장폐기물처리 관리대장 기록 등록';
  const fields = ['id','waste_type_id','waste_name','category','unit','occur_date','occur_qty','self_date','self_qty','self_method','consign_date','consign_qty','consign_method','consign_vendor','manifest_no','note'];
  const defaults = { id: 0, waste_type_id: 0, category: '일반폐기물', unit: 'kg', occur_date: '<?= date('Y-m-d') ?>', occur_qty: 0, self_qty: 0, consign_qty: 0 };
  fields.forEach(f => {
    const el = document.getElementById('log_' + f);
    if (!el) return;
    el.value = data ? (data[f] ?? '') : (defaults[f] ?? '');
  });
  document.getElementById('logModal').classList.add('open');
}

function syncLogWasteType(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt || sel.value === '0') return;
  document.getElementById('log_waste_name').value = opt.dataset.name || '';
  document.getElementById('log_category').value = opt.dataset.cat || '일반폐기물';
  if (!document.getElementById('log_consign_vendor').value) {
    document.getElementById('log_consign_vendor').value = opt.dataset.vendor || '';
  }
  if (!document.getElementById('log_consign_method').value) {
    document.getElementById('log_consign_method').value = opt.dataset.method || '';
  }
}

// Clipboard Ctrl+V screenshot paste handler for all .js-paste-zone elements
function applyPastedImage(zone, blob) {
  const container = zone.closest('form') || zone.parentElement;
  const b64Input = zone.previousElementSibling && zone.previousElementSibling.classList.contains('js-paste-b64')
    ? zone.previousElementSibling
    : container.querySelector('.js-paste-b64');
  const preview = zone.querySelector('.js-paste-preview');
  const label = zone.querySelector('.js-paste-label');
  const reader = new FileReader();
  reader.onload = () => {
    if (b64Input) b64Input.value = reader.result;
    if (preview) {
      preview.src = reader.result;
      preview.style.display = 'block';
    }
    if (label) label.innerHTML = '✅ <strong>스크린샷이 준비되었습니다.</strong> 저장/등록 버튼을 눌러주세요.';
  };
  reader.readAsDataURL(blob);
}

document.querySelectorAll('.js-paste-zone').forEach(zone => {
  zone.addEventListener('paste', e => {
    const items = (e.clipboardData || window.clipboardData)?.items || [];
    for (const item of items) {
      if (item.type && item.type.startsWith('image/')) {
        e.preventDefault();
        applyPastedImage(zone, item.getAsFile());
        return;
      }
    }
  });
  const btn = zone.querySelector('.js-paste-btn');
  if (btn) {
    btn.addEventListener('click', async e => {
      e.stopPropagation();
      if (!navigator.clipboard || !navigator.clipboard.read) {
        zone.focus();
        alert('영역을 클릭한 뒤 Ctrl+V 키를 눌러 붙여넣어 주세요.');
        return;
      }
      try {
        const clipItems = await navigator.clipboard.read();
        for (const ci of clipItems) {
          const imgType = ci.types.find(t => t.startsWith('image/'));
          if (imgType) {
            const blob = await ci.getType(imgType);
            applyPastedImage(zone, blob);
            return;
          }
        }
        alert('클립보드에 복사된 이미지가 없습니다. Win+Shift+S 등으로 캡처 후 다시 시도해 주세요.');
      } catch (err) {
        zone.focus();
        alert('브라우저 권한으로 자동 읽기가 제한되었습니다. 이 영역을 클릭한 뒤 Ctrl+V 를 눌러주세요.');
      }
    });
  }
});
</script>
</body>
</html>
