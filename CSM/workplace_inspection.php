<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workplace_inspection_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');

$db = wi_db();

// Inline photo view
if (isset($_GET['view_photo'])) {
    $fn = basename((string)$_GET['view_photo']);
    if (!preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn)) {
        http_response_code(400);
        exit('잘못된 요청입니다.');
    }
    $path = wi_photo_dir() . '/' . $fn;
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

// Evidence download
if (isset($_GET['download_evidence'])) {
    $evId = (int)$_GET['download_evidence'];
    $stmt = $db->prepare('SELECT * FROM wi_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$evId]);
    $ev = $stmt->fetch();
    if (!$ev) {
        http_response_code(404);
        exit('증빙 파일을 찾을 수 없습니다.');
    }
    $path = wi_evidence_dir() . '/' . basename((string)$ev['stored_name']);
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
if (empty($_SESSION['wi_csrf'])) {
    $_SESSION['wi_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['wi_csrf'];

$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) {
    $year = $nowYear;
}
$tab = (string)($_GET['tab'] ?? 's5');
$validTabs = ['s5', 'env', 'sign', 'guard', 'evidence'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 's5';
}

$tabToItem = ['s5' => '3.1.1', 'env' => '3.1.2', 'sign' => '3.1.3', 'guard' => '3.1.4'];

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
        if (in_array($postTab, $validTabs, true)) {
            $tab = $postTab;
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($action === 'save_5s') {
                wi_get_5s_meta($db, $year);
                $db->beginTransaction();
                $db->prepare('UPDATE wi_5s_meta SET location=?, checker_name=?, note=?, updated_by=?, updated_at=? WHERE year=?')
                   ->execute([
                       trim((string)($_POST['location'] ?? '작업장·자재창고·휴게실')),
                       trim((string)($_POST['checker_name'] ?? '김남균')),
                       trim((string)($_POST['note'] ?? '')),
                       $actor,
                       $now,
                       $year,
                   ]);
                $cells = $_POST['s5'] ?? [];
                $upsert = $db->prepare('INSERT INTO wi_5s_records (year, month, item_no, result, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(year, month, item_no) DO UPDATE SET result=excluded.result, updated_by=excluded.updated_by, updated_at=excluded.updated_at');
                for ($itemNo = 1; $itemNo <= 25; $itemNo++) {
                    for ($m = 1; $m <= 12; $m++) {
                        $val = (string)($cells[$itemNo][$m] ?? '');
                        if (!in_array($val, ['', '○', '×'], true)) {
                            $val = '';
                        }
                        $upsert->execute([$year, $m, $itemNo, $val, $actor, $now]);
                    }
                }
                $db->commit();
                $flashSuccess = $year . '년 5S 월별 점검표가 저장되었습니다.';
            } elseif ($action === 'save_check') {
                $itemCode = (string)($_POST['item_code'] ?? '');
                if (!isset(wi_item_meta()[$itemCode])) {
                    throw new InvalidArgumentException('평가 항목을 확인해 주세요.');
                }
                $checkDate = trim((string)($_POST['check_date'] ?? date('Y-m-d')));
                $inspector = trim((string)($_POST['inspector'] ?? $actor));
                $location = trim((string)($_POST['location'] ?? ''));
                $vals = [];
                for ($i = 1; $i <= 6; $i++) {
                    $v = (string)($_POST['chk' . $i] ?? '적합');
                    $vals[$i] = in_array($v, ['적합', '보완필요', '해당없음'], true) ? $v : '적합';
                }
                $overall = in_array('보완필요', $vals, true) ? '보완필요' : '적합';
                $prefix = 'wi_' . str_replace('.', '', $itemCode);
                $photo1 = wi_save_photo_file($_FILES['photo1'] ?? null, (string)($_POST['photo1_base64'] ?? ''), $prefix . '_p1');
                $photo2 = wi_save_photo_file($_FILES['photo2'] ?? null, (string)($_POST['photo2_base64'] ?? ''), $prefix . '_p2');

                $db->prepare('INSERT INTO wi_checks (year, item_code, check_date, inspector, location, chk1, chk2, chk3, chk4, chk5, chk6, overall_result, issue_note, action_note, photo1, photo2, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, $itemCode, $checkDate, $inspector, $location,
                       $vals[1], $vals[2], $vals[3], $vals[4], $vals[5], $vals[6],
                       $overall,
                       trim((string)($_POST['issue_note'] ?? '')),
                       trim((string)($_POST['action_note'] ?? '')),
                       $photo1, $photo2,
                       $actor, $now, $now,
                   ]);
                $flashSuccess = $itemCode . ' 현장점검 기록이 저장되었습니다.';
            } elseif ($action === 'delete_check') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('DELETE FROM wi_checks WHERE id = ? AND year = ?')->execute([$id, $year]);
                    $flashSuccess = '현장점검 기록이 삭제되었습니다.';
                }
            } elseif ($action === 'upload_evidence') {
                $itemCode = (string)($_POST['item_code'] ?? '');
                $kind = (string)($_POST['kind'] ?? '');
                $note = trim((string)($_POST['note'] ?? ''));
                wi_upload_evidence($db, $year, $itemCode, $kind, $_FILES['evidence_file'] ?? null, (string)($_POST['evidence_base64'] ?? ''), $note, $actor);
                $flashSuccess = $itemCode . ' 증빙 자료가 등록되었습니다.';
            } elseif ($action === 'delete_evidence') {
                $evId = (int)($_POST['id'] ?? 0);
                if ($evId > 0) {
                    $db->prepare('UPDATE wi_evidence SET deleted_at = ? WHERE id = ? AND year = ?')->execute([$now, $evId, $year]);
                    $flashSuccess = '증빙 파일이 삭제되었습니다.';
                }
            } elseif ($action === 'save_assessment') {
                $itemCode = (string)($_POST['item_code'] ?? '');
                if (!isset(wi_item_meta()[$itemCode])) {
                    throw new InvalidArgumentException('평가 항목을 확인해 주세요.');
                }
                $multVal = (string)($_POST['multiplier'] ?? 'auto');
                $note = trim((string)($_POST['note'] ?? ''));
                if ($multVal === 'auto') {
                    $db->prepare('DELETE FROM wi_assessments WHERE year = ? AND item_code = ?')->execute([$year, $itemCode]);
                    $flashSuccess = $itemCode . ' 평가 수준이 자동(증빙 등록 시 100%)으로 변경되었습니다.';
                } else {
                    $mult = (float)$multVal;
                    if (!in_array($mult, [1.0, 0.9, 0.8, 0.5, 0.0], true)) {
                        throw new InvalidArgumentException('유효한 평가 수준을 선택해 주세요.');
                    }
                    $db->prepare('INSERT INTO wi_assessments (year, item_code, multiplier, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(year, item_code) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
                       ->execute([$year, $itemCode, $mult, $note, $actor, $now]);
                    $flashSuccess = $itemCode . ' 평가 수준이 저장되었습니다.';
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $flashError = $e->getMessage();
        }
    }
}

$itemMeta = wi_item_meta();
$evidenceKinds = wi_evidence_kinds();
$s5Questions = wi_5s_questions();
$s5Meta = wi_get_5s_meta($db, $year);
$s5Matrix = wi_get_5s_matrix($db, $year);
$s5CompletedMonths = wi_count_5s_completed_months($s5Matrix);

$allChecks = wi_list_checks($db, $year);
$checksByItem = ['3.1.1' => [], '3.1.2' => [], '3.1.3' => [], '3.1.4' => []];
foreach ($allChecks as $ck) {
    if (isset($checksByItem[$ck['item_code']])) {
        $checksByItem[$ck['item_code']][] = $ck;
    }
}

$allEvidence = wi_list_evidence($db, $year);
$evidenceByItem = ['3.1.1' => [], '3.1.2' => [], '3.1.3' => [], '3.1.4' => []];
foreach ($allEvidence as $ev) {
    if (isset($evidenceByItem[$ev['item_code']])) {
        $evidenceByItem[$ev['item_code']][] = $ev;
    }
}

$scores = [];
$totalEarned = 0.0;
foreach (['3.1.1', '3.1.2', '3.1.3', '3.1.4'] as $code) {
    $scores[$code] = csm_wi_score_summary($year, $code, 2.0);
    $totalEarned += $scores[$code]['earned'];
}
$totalEarned = round($totalEarned, 2);

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function wi_fmt_size(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}

function wi_render_check_section(string $itemCode, string $tabName, int $year, array $meta, array $checks, bool $canManage, string $actor, string $csrf, callable $e): void
{
    ?>
    <?php if ($canManage): ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $e($itemCode) ?> 현장점검 등록 — <?= $e($meta['short']) ?></h2>
          <p class="sub"><?= $e($meta['title']) ?> · 현장 사진을 첨부(파일/카메라/Ctrl+V)하면 <?= $e($itemCode) ?> 증빙으로도 자동 반영됩니다.</p>
        </div>
        <a class="btn sm primary" href="workplace_inspection_print.php?mode=check&amp;item=<?= $e($itemCode) ?>&amp;year=<?= $year ?>" target="_blank">🖨️ <?= $e($itemCode) ?> 점검표 A4 출력</a>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_check">
        <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
        <input type="hidden" name="item_code" value="<?= $e($itemCode) ?>">
        <div class="form-grid" style="margin-bottom:14px;">
          <div class="field"><label>점검일자</label><input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label>점검자</label><input type="text" name="inspector" value="<?= $e($actor) ?>" required></div>
          <div class="field"><label>점검 구역 / 대상</label><input type="text" name="location" value="<?= $e($meta['default_location']) ?>" required></div>
        </div>

        <div class="tbl-wrap" style="margin-bottom:14px;">
          <table class="chk-table">
            <thead>
              <tr>
                <th class="ctr" style="width:46px;">No</th>
                <th>점검 항목 (<?= $e($itemCode) ?> 기준)</th>
                <th style="width:240px;">점검 결과</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($meta['checklist'] as $idx => $label): $n = $idx + 1; ?>
              <tr>
                <td class="ctr"><?= $n ?></td>
                <td><?= $e($label) ?></td>
                <td>
                  <div class="radio-grp">
                    <label><input type="radio" name="chk<?= $n ?>" value="적합" checked> 적합</label>
                    <label><input type="radio" name="chk<?= $n ?>" value="보완필요"> 보완필요</label>
                    <label><input type="radio" name="chk<?= $n ?>" value="해당없음"> 해당없음</label>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="form-grid cols-2" style="margin-bottom:14px;">
          <div class="field">
            <label>📷 <?= $e($meta['photo1_label']) ?></label>
            <input type="file" name="photo1" accept="image/*" capture="environment">
            <input type="hidden" name="photo1_base64" class="js-paste-b64" value="">
            <div class="paste-zone js-paste-zone" tabindex="0">
              <span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 로 사진 붙여넣기</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="사진1 미리보기" style="display:none;">
            </div>
          </div>
          <div class="field">
            <label>📷 <?= $e($meta['photo2_label']) ?></label>
            <input type="file" name="photo2" accept="image/*" capture="environment">
            <input type="hidden" name="photo2_base64" class="js-paste-b64" value="">
            <div class="paste-zone js-paste-zone" tabindex="0">
              <span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 로 사진 붙여넣기</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="사진2 미리보기" style="display:none;">
            </div>
          </div>
          <div class="field"><label>지적 / 미흡 사항</label><textarea name="issue_note" rows="2" placeholder="특이사항 없을 시 공란"></textarea></div>
          <div class="field"><label>개선 조치 내용</label><textarea name="action_note" rows="2" placeholder="즉시 조치 또는 개선 완료 내용"></textarea></div>
        </div>

        <div style="text-align:right;">
          <button type="submit" class="btn primary"><?= $e($itemCode) ?> 현장점검 저장</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $year ?>년 <?= $e($itemCode) ?> 현장점검 이력 (<?= count($checks) ?>건)</h2>
          <p class="sub">등록된 점검표와 현장 확인 사진을 확인하고 공식 A4 보고서로 출력할 수 있습니다.</p>
        </div>
        <a class="btn sm ghost" href="workplace_inspection_print.php?mode=check&amp;item=<?= $e($itemCode) ?>&amp;year=<?= $year ?>" target="_blank">🖨️ 전체 이력 A4 출력</a>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th class="ctr">점검일</th>
              <th class="ctr">점검자</th>
              <th>점검 구역</th>
              <th class="ctr">종합결과</th>
              <th>현장 사진</th>
              <th>지적·개선조치</th>
              <th class="ctr">관리</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$checks): ?>
              <tr><td colspan="7" class="ctr" style="padding:24px; color:var(--muted);">등록된 <?= $year ?>년 <?= $e($itemCode) ?> 현장점검 기록이 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($checks as $ck): ?>
              <tr>
                <td class="ctr"><strong><?= $e($ck['check_date']) ?></strong></td>
                <td class="ctr"><?= $e($ck['inspector']) ?></td>
                <td><?= $e($ck['location']) ?></td>
                <td class="ctr"><span class="badge <?= $ck['overall_result'] === '적합' ? 'ok' : 'warn' ?>"><?= $e($ck['overall_result']) ?></span></td>
                <td>
                  <div class="photo-thumbs">
                    <?php if ($ck['photo1'] !== ''): ?>
                      <a href="?view_photo=<?= urlencode((string)$ck['photo1']) ?>" target="_blank"><img class="photo-thumb" src="?view_photo=<?= urlencode((string)$ck['photo1']) ?>" alt="사진1"></a>
                    <?php endif; ?>
                    <?php if ($ck['photo2'] !== ''): ?>
                      <a href="?view_photo=<?= urlencode((string)$ck['photo2']) ?>" target="_blank"><img class="photo-thumb" src="?view_photo=<?= urlencode((string)$ck['photo2']) ?>" alt="사진2"></a>
                    <?php endif; ?>
                    <?php if ($ck['photo1'] === '' && $ck['photo2'] === ''): ?><span class="sub">사진 없음</span><?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($ck['issue_note'] !== ''): ?><div><strong>지적:</strong> <?= $e($ck['issue_note']) ?></div><?php endif; ?>
                  <?php if ($ck['action_note'] !== ''): ?><div class="sub"><strong>조치:</strong> <?= $e($ck['action_note']) ?></div><?php endif; ?>
                  <?php if ($ck['issue_note'] === '' && $ck['action_note'] === ''): ?><span class="sub">양호</span><?php endif; ?>
                </td>
                <td class="ctr" style="white-space:nowrap;">
                  <a class="btn sm ghost" href="workplace_inspection_print.php?mode=check&amp;item=<?= $e($itemCode) ?>&amp;year=<?= $year ?>&amp;check_id=<?= (int)$ck['id'] ?>" target="_blank">🖨️ 출력</a>
                  <?php if ($canManage): ?>
                  <form method="post" onsubmit="return confirm('이 점검 기록을 삭제할까요?');" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="delete_check">
                    <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
                    <input type="hidden" name="id" value="<?= (int)$ck['id'] ?>">
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
    <?php
}

function wi_render_evidence_box(string $itemCode, string $tabName, int $year, array $items, array $score, array $kinds, array $meta, bool $canManage, string $csrf, callable $e): void
{
    $curMult = $score['assessment'] !== null ? (string)$score['assessment']['multiplier'] : 'auto';
    $curNote = $score['assessment']['note'] ?? '';
    ?>
    <section class="card" id="ev-<?= $e($itemCode) ?>">
      <div class="card-head">
        <div>
          <h3>📎 <?= $e($itemCode) ?> 증빙 자료 및 점수 관리</h3>
          <p class="sub">필요 증빙: <?= $e($meta['evidence_guide']) ?></p>
        </div>
        <div class="score-pill <?= $score['earned'] >= $meta['points'] ? 'ok' : ($score['evidence_count'] > 0 ? 'warn' : 'empty') ?>">
          현재 점수 <strong><?= number_format($score['earned'], 1) ?> / <?= number_format($meta['points'], 1) ?>점</strong>
          (증빙·사진 <?= (int)$score['evidence_count'] ?>건)
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
              <div class="empty-msg">등록된 별도 증빙 파일이 없습니다. (현장점검에 첨부한 사진도 <?= $e($itemCode) ?> 증빙으로 자동 인정됩니다.)</div>
            <?php else: ?>
              <?php foreach ($items as $ev): ?>
                <div class="ev-item">
                  <div class="ev-info">
                    <span class="badge"><?= $e($kinds[$ev['kind']] ?? $ev['kind']) ?></span>
                    <a href="?download_evidence=<?= (int)$ev['id'] ?>&amp;inline=1" target="_blank" class="ev-name"><?= $e($ev['original_name']) ?></a>
                    <span class="ev-meta"><?= wi_fmt_size((int)$ev['size']) ?> · <?= $e($ev['uploaded_by']) ?> · <?= $e(substr((string)$ev['uploaded_at'], 0, 10)) ?></span>
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
          <h4>평가 수준 설정 (<?= $e($itemCode) ?> · <?= number_format($meta['points'], 1) ?>점)</h4>
          <p class="sub">기본값은 증빙(또는 현장 사진) 등록 시 100%(2.0점) 자동 반영됩니다.</p>
          <?php if ($canManage): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="save_assessment">
            <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
            <input type="hidden" name="item_code" value="<?= $e($itemCode) ?>">
            <div class="field">
              <label>평가 수준</label>
              <select name="multiplier">
                <option value="auto" <?= $curMult === 'auto' ? 'selected' : '' ?>>자동 (증빙 등록 시 100% · 2.0점)</option>
                <option value="1" <?= $curMult === '1' || $curMult === '1.0' ? 'selected' : '' ?>>100% (2.0점) — 충족</option>
                <option value="0.9" <?= $curMult === '0.9' ? 'selected' : '' ?>>90% (1.8점) — 우수</option>
                <option value="0.8" <?= $curMult === '0.8' ? 'selected' : '' ?>>80% (1.6점) — 양호</option>
                <option value="0.5" <?= $curMult === '0.5' ? 'selected' : '' ?>>50% (1.0점) — 미흡</option>
                <option value="0" <?= $curMult === '0' || $curMult === '0.0' ? 'selected' : '' ?>>0% (0.0점) — 없음</option>
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
<title>3.1 현장점검·작업환경 관리 · CSM</title>
<style>
  :root {
    --bg: #f4f6f9;
    --card: #ffffff;
    --line: #dbe2ea;
    --ink: #162231;
    --muted: #5a6978;
    --brand: #1d4ed8;
    --brand-dark: #1e40af;
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
    background: #172a46; color: #fff; padding: 0 20px; height: 54px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    position: sticky; top: 0; z-index: 50; box-shadow: 0 2px 8px rgba(0,0,0,0.14);
  }
  .topbar-left { display: flex; align-items: center; gap: 12px; }
  .topbar-back { color: #bfdbfe; font-size: 13px; padding: 5px 10px; border-radius: 6px; background: rgba(255,255,255,0.09); }
  .topbar-back:hover { background: rgba(255,255,255,0.18); color: #fff; }
  .topbar-title { font-size: 16px; font-weight: 800; }
  .topbar-right { display: flex; align-items: center; gap: 10px; font-size: 13px; }
  .year-select { background: rgba(255,255,255,0.14); color: #fff; border: 1px solid rgba(255,255,255,0.28); border-radius: 6px; padding: 5px 10px; font-weight: 700; cursor: pointer; }
  .year-select option { color: #111; }

  .wrap { max-width: 1440px; margin: 0 auto; padding: 20px 20px 60px; }

  .hero { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 18px; }
  .hero small { color: var(--brand); font-weight: 800; font-size: 12px; }
  .hero h1 { margin: 4px 0 4px; font-size: 24px; font-weight: 900; letter-spacing: -0.5px; }
  .hero p { margin: 0; color: var(--muted); font-size: 13px; }
  .print-bar { display: flex; flex-wrap: wrap; gap: 8px; }

  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px; border: 1px solid var(--line);
    background: #fff; color: var(--ink); font-weight: 700; font-size: 13px; cursor: pointer; white-space: nowrap;
  }
  .btn:hover { background: #f1f5f9; border-color: #b8c4d4; }
  .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn.primary:hover { background: var(--brand-dark); }
  .btn.danger { background: #fff; border-color: #fecaca; color: var(--danger); }
  .btn.danger:hover { background: var(--danger-bg); }
  .btn.ghost { background: #f8fafc; }
  .btn.sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 700; font-size: 13px; }
  .flash.ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #bbf7d0; }
  .flash.err { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; }

  .kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
  .kpi {
    background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 13px 15px;
    display: flex; flex-direction: column; gap: 3px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  }
  .kpi.total { background: linear-gradient(135deg, #1d4ed8, #1e3a8a); color: #fff; border-color: #1d4ed8; }
  .kpi-code { font-size: 11.5px; font-weight: 800; color: var(--brand); }
  .kpi.total .kpi-code { color: #bfdbfe; }
  .kpi-title { font-size: 13px; font-weight: 700; }
  .kpi-val { font-size: 21px; font-weight: 900; margin-top: 2px; }
  .kpi-sub { font-size: 11.5px; color: var(--muted); }
  .kpi.total .kpi-sub { color: #dbeafe; }

  .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; border-bottom: 2px solid var(--line); }
  .tab-link { padding: 11px 16px; font-weight: 800; font-size: 13.5px; color: var(--muted); border-bottom: 3px solid transparent; margin-bottom: -2px; border-radius: 8px 8px 0 0; }
  .tab-link:hover { color: var(--ink); background: rgba(29,78,216,0.05); }
  .tab-link.active { color: var(--brand); border-bottom-color: var(--brand); background: #fff; }

  .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); }
  .card-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #edf1f6; }
  .card-head h2, .card-head h3 { margin: 0; font-size: 16px; font-weight: 900; }
  .sub { margin: 2px 0 0; font-size: 12.5px; color: var(--muted); }

  .info-banner {
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; font-size: 13px; color: #1e3a8a;
  }

  .tbl-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { padding: 8px 10px; border-bottom: 1px solid #e9eef4; text-align: left; vertical-align: middle; }
  th { background: #f1f5f9; font-weight: 800; color: #1e293b; white-space: nowrap; }
  tr:last-child td { border-bottom: none; }
  .ctr { text-align: center; }

  /* 5S Matrix */
  .s5-table th, .s5-table td { padding: 5px 6px; font-size: 12.5px; border-right: 1px solid #e9eef4; }
  .s5-table th:last-child, .s5-table td:last-child { border-right: none; }
  .s5-grp { font-weight: 900; background: #f8fafc; text-align: center; color: #1e3a8a; width: 54px; }
  .s5-cell-btn {
    width: 34px; height: 28px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;
    font-weight: 900; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
  }
  .s5-cell-btn[data-val="○"] { background: #dcfce7; border-color: #86efac; color: #15803d; }
  .s5-cell-btn[data-val="×"] { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }
  .s5-month-btn {
    display: block; width: 100%; margin-top: 3px; font-size: 10.5px; padding: 2px 3px; border: 1px solid #cbd5e1;
    border-radius: 4px; background: #fff; color: #334155; cursor: pointer; font-weight: 700;
  }
  .s5-month-btn:hover { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 800; background: #e0e7ff; color: #1d4ed8; white-space: nowrap; }
  .badge.ok { background: var(--ok-bg); color: var(--ok); }
  .badge.warn { background: var(--warn-bg); color: var(--warn); }

  .form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
  .form-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
  .field { display: flex; flex-direction: column; gap: 4px; }
  .field label { font-size: 12px; font-weight: 800; color: #334155; }
  .field input[type="text"], .field input[type="date"], .field select, .field textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 13px; background: #fff;
  }
  .field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(29,78,216,0.12); }

  .paste-zone {
    margin-top: 8px; padding: 10px 12px; border: 2px dashed #93c5fd; border-radius: 10px;
    background: #f5f9ff; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px;
    font-size: 12.5px; color: #1e40af; cursor: pointer;
  }
  .paste-zone:focus { outline: none; border-color: var(--brand); background: #eff6ff; }
  .paste-preview { max-height: 110px; border-radius: 6px; border: 1px solid #cbd5e1; margin-top: 6px; }

  .score-pill { padding: 6px 12px; border-radius: 999px; font-size: 13px; font-weight: 700; }
  .score-pill.ok { background: var(--ok-bg); color: var(--ok); }
  .score-pill.warn { background: var(--warn-bg); color: var(--warn); }
  .score-pill.empty { background: #eef2f6; color: var(--muted); }
  .ev-grid { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 18px; align-items: start; }
  .ev-upload-form { background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
  .ev-list { display: flex; flex-direction: column; gap: 8px; }
  .ev-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 9px; background: #fff; }
  .ev-info { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .ev-name { font-weight: 800; color: var(--brand-dark); }
  .ev-name:hover { text-decoration: underline; }
  .ev-meta { font-size: 12px; color: var(--muted); }
  .ev-note { width: 100%; font-size: 12px; color: #475569; }
  .ev-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .assess-box { background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: 14px; }
  .assess-box h4 { margin: 0 0 4px; font-size: 14px; }
  .empty-msg { padding: 16px; text-align: center; color: var(--muted); background: #f9fafb; border: 1px dashed var(--line); border-radius: 10px; font-size: 13px; }

  .radio-grp { display: flex; gap: 10px; }
  .radio-grp label { display: inline-flex; align-items: center; gap: 4px; font-weight: 700; font-size: 12.5px; cursor: pointer; }
  .photo-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
  .photo-thumb { width: 72px; height: 54px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd5e1; }

  @media (max-width: 1120px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .ev-grid { grid-template-columns: 1fr; }
  }
  @media (max-width: 680px) {
    .kpi-grid, .form-grid, .form-grid.cols-2 { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <a class="topbar-back" href="/CSM/area.php?group=3&amp;section=3.1">← 3.1 영역</a>
    <a class="topbar-back" href="/CSM/">CSM 포털</a>
    <span class="topbar-title">3.1 현장점검·작업환경 관리</span>
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
      <small>CSM 평가 영역 3 · 작업장 관리 · 3.1.1 ~ 3.1.4 (총 8.0점)</small>
      <h1><?= $year ?>년 현장점검·작업환경 활동기록</h1>
      <p>주식회사 현대기전 현장의 5S·청결(3.1.1), 조명·환기·휴게실(3.1.2), 표지판·위험구역 통제(3.1.3), 유해위험기계 안전장치(3.1.4) 점검을 관리합니다.</p>
    </div>
    <div class="print-bar">
      <a class="btn primary" href="workplace_inspection_print.php?mode=5s&amp;year=<?= $year ?>" target="_blank">🖨️ 3.1.1 연간 5S 점검표 출력</a>
      <a class="btn" href="workplace_inspection_print.php?mode=check&amp;item=all&amp;year=<?= $year ?>" target="_blank">🖨️ 3.1 현장점검표 일괄 출력</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash ok">✅ <?= $e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash err">⚠️ <?= $e($flashError) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <?php foreach (['3.1.1', '3.1.2', '3.1.3', '3.1.4'] as $code): $m = $itemMeta[$code]; ?>
    <a class="kpi" href="?year=<?= $year ?>&amp;tab=<?= $e($m['tab']) ?>">
      <span class="kpi-code"><?= $e($code) ?> (2.0점)</span>
      <span class="kpi-title"><?= $e($m['short']) ?></span>
      <span class="kpi-val"><?= number_format($scores[$code]['earned'], 1) ?> / 2.0점</span>
      <span class="kpi-sub">
        <?php if ($code === '3.1.1'): ?>5S <?= $s5CompletedMonths ?>개월 · <?php endif; ?>
        점검 <?= count($checksByItem[$code]) ?>건 · 증빙 <?= (int)$scores[$code]['evidence_count'] ?>건
      </span>
    </a>
    <?php endforeach; ?>
    <a class="kpi total" href="?year=<?= $year ?>&amp;tab=evidence">
      <span class="kpi-code">3.1 작업장 관리 합계</span>
      <span class="kpi-title">CSM 포털 실시간 반영</span>
      <span class="kpi-val"><?= number_format($totalEarned, 1) ?> / 8.0점</span>
      <span class="kpi-sub">달성률 <?= number_format(($totalEarned / 8.0) * 100, 1) ?>% · 상·하반기</span>
    </a>
  </section>

  <nav class="tabs">
    <a class="tab-link <?= $tab === 's5' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=s5">3.1.1 5S·청결 관리</a>
    <a class="tab-link <?= $tab === 'env' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=env">3.1.2 조명·환기·휴게실</a>
    <a class="tab-link <?= $tab === 'sign' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=sign">3.1.3 표지판·위험구역 통제</a>
    <a class="tab-link <?= $tab === 'guard' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=guard">3.1.4 위험기계 안전장치</a>
    <a class="tab-link <?= $tab === 'evidence' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=evidence">📎 3.1 통합 증빙·점수</a>
  </nav>

  <?php if ($tab === 's5'): ?>
    <!-- TAB 1: 3.1.1 5S Annual Matrix + Field Check -->
    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $year ?>년 작업장 정리정돈 (5S) 월별 점검표</h2>
          <p class="sub">각 칸을 클릭하면 <strong>공란 → ○(양호) → ×(불량)</strong> 순으로 전환됩니다. 월 헤더의 [전체○] 버튼으로 빠르게 입력할 수 있습니다.</p>
        </div>
        <a class="btn sm primary" href="workplace_inspection_print.php?mode=5s&amp;year=<?= $year ?>" target="_blank">🖨️ 5S 점검표 A4 가로 출력</a>
      </div>

      <form method="post" id="s5Form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_5s">
        <input type="hidden" name="tab" value="s5">

        <div class="form-grid" style="margin-bottom:12px;">
          <div class="field"><label>점검 장소</label><input type="text" name="location" value="<?= $e($s5Meta['location']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>확인자</label><input type="text" name="checker_name" value="<?= $e($s5Meta['checker_name']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>비고</label><input type="text" name="note" value="<?= $e($s5Meta['note']) ?>" placeholder="특이사항 및 개선 메모" <?= $canManage ? '' : 'readonly' ?>></div>
        </div>

        <div class="tbl-wrap">
          <table class="s5-table">
            <thead>
              <tr>
                <th class="ctr">구분</th>
                <th class="ctr" style="width:34px;">No</th>
                <th style="min-width:280px;">평가 내용 (5S 25개 항목)</th>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                  <th class="ctr" style="width:46px;">
                    <?= $m ?>월
                    <?php if ($canManage): ?>
                      <button type="button" class="s5-month-btn" onclick="fillMonthOk(<?= $m ?>)" title="<?= $m ?>월 전체 양호(○) 입력">전체○</button>
                    <?php endif; ?>
                  </th>
                <?php endfor; ?>
              </tr>
            </thead>
            <tbody>
              <?php
              $lastGrp = '';
              foreach ($s5Questions as $q):
                $no = $q['no'];
              ?>
              <tr>
                <?php if ($q['group'] !== $lastGrp): $lastGrp = $q['group']; ?>
                  <td rowspan="5" class="s5-grp"><?= $e($q['group']) ?></td>
                <?php endif; ?>
                <td class="ctr"><?= $q['sub_no'] ?></td>
                <td><?= $e($q['text']) ?></td>
                <?php for ($m = 1; $m <= 12; $m++):
                  $val = $s5Matrix[$no][$m] ?? '';
                ?>
                  <td class="ctr">
                    <input type="hidden" name="s5[<?= $no ?>][<?= $m ?>]" id="s5_<?= $no ?>_<?= $m ?>" value="<?= $e($val) ?>">
                    <button type="button" class="s5-cell-btn" data-no="<?= $no ?>" data-m="<?= $m ?>" data-val="<?= $e($val) ?>" <?= $canManage ? 'onclick="cycle5s(this)"' : 'disabled' ?>><?= $val !== '' ? $e($val) : '·' ?></button>
                  </td>
                <?php endfor; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($canManage): ?>
        <div style="margin-top:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <span class="sub">범례: <strong>○</strong> 양호 &nbsp;|&nbsp; <strong>×</strong> 불량 &nbsp;|&nbsp; <strong>·</strong> 미점검</span>
          <button type="submit" class="btn primary">💾 <?= $year ?>년 5S 점검표 저장</button>
        </div>
        <?php endif; ?>
      </form>
    </section>

    <?php wi_render_check_section('3.1.1', 's5', $year, $itemMeta['3.1.1'], $checksByItem['3.1.1'], $canManage, $actor, $csrf, $e); ?>
    <?php wi_render_evidence_box('3.1.1', 's5', $year, $evidenceByItem['3.1.1'], $scores['3.1.1'], $evidenceKinds['3.1.1'], $itemMeta['3.1.1'], $canManage, $csrf, $e); ?>

  <?php elseif (isset($tabToItem[$tab])):
    $code = $tabToItem[$tab];
  ?>
    <?php if ($code === '3.1.4'): ?>
      <div class="info-banner">
        <div>
          <strong>🔗 2.6.3 위험기계·기구 관리대장 연동 안내</strong><br>
          개별 기계기구별 정밀 안전점검표(21대)는 <strong>2.6.3 위험기계·기구 대장</strong>에서 관리하며, 이 화면에서는 <strong>작업장 현장 순회 확인(방호장치 설치·작동 여부)</strong> 기록과 현장 사진을 관리합니다.
        </div>
        <a class="btn sm primary" href="hazardous_machinery.php?year=<?= $year ?>">2.6.3 위험기계·기구 대장 열기 ↗</a>
      </div>
    <?php endif; ?>

    <?php wi_render_check_section($code, $tab, $year, $itemMeta[$code], $checksByItem[$code], $canManage, $actor, $csrf, $e); ?>
    <?php wi_render_evidence_box($code, $tab, $year, $evidenceByItem[$code], $scores[$code], $evidenceKinds[$code], $itemMeta[$code], $canManage, $csrf, $e); ?>

  <?php else: ?>
    <!-- TAB 5: 통합 증빙·점수 관리 -->
    <?php foreach (['3.1.1', '3.1.2', '3.1.3', '3.1.4'] as $code): ?>
      <?php wi_render_evidence_box($code, 'evidence', $year, $evidenceByItem[$code], $scores[$code], $evidenceKinds[$code], $itemMeta[$code], $canManage, $csrf, $e); ?>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<script>
function cycle5s(btn) {
  const cur = btn.dataset.val || '';
  const next = cur === '' ? '○' : (cur === '○' ? '×' : '');
  btn.dataset.val = next;
  btn.textContent = next === '' ? '·' : next;
  const inp = document.getElementById('s5_' + btn.dataset.no + '_' + btn.dataset.m);
  if (inp) inp.value = next;
}

function fillMonthOk(month) {
  const btns = document.querySelectorAll('.s5-cell-btn[data-m="' + month + '"]');
  const allOk = Array.from(btns).every(b => b.dataset.val === '○');
  const next = allOk ? '' : '○';
  btns.forEach(btn => {
    btn.dataset.val = next;
    btn.textContent = next === '' ? '·' : next;
    const inp = document.getElementById('s5_' + btn.dataset.no + '_' + month);
    if (inp) inp.value = next;
  });
}

// Clipboard Ctrl+V screenshot paste handler
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
