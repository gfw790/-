<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hazardous_machinery_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$canManage = auth_can_manage($user);
$db = hm_db();
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year = filter_var($_GET['year'] ?? $_POST['year'] ?? date('Y'), FILTER_VALIDATE_INT) ?: (int)date('Y');
$_SESSION['csm_hm_csrf'] ??= bin2hex(random_bytes(32));

// Serve equipment photo securely
if (isset($_GET['photo'])) {
    $file = basename((string)$_GET['photo']);
    if (!preg_match('/^[a-z0-9_.-]+\.(png|jpg|jpeg)$/i', $file)) {
        http_response_code(404);
        exit;
    }
    $path = hm_photo_dir() . '/' . $file;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $mime = str_ends_with(strtolower($file), '.png') ? 'image/png' : 'image/jpeg';
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Serve checklist field inspection photo securely
if (isset($_GET['check_photo'])) {
    $file = basename((string)$_GET['check_photo']);
    if (!preg_match('/^chk_e\d+_i\d+_r[12]_[a-f0-9]+\.(jpg|png|webp)$/i', $file)) {
        http_response_code(404);
        exit;
    }
    $path = hm_check_photo_dir() . '/' . $file;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Serve evidence file securely
if (isset($_GET['evidence_file'])) {
    $eid = filter_var($_GET['evidence_file'], FILTER_VALIDATE_INT) ?: 0;
    $stmt = $db->prepare('SELECT * FROM hm_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$eid]);
    $ev = $stmt->fetch();
    $path = $ev ? hm_evidence_dir() . '/' . $ev['stored_name'] : '';
    if (!$ev || !preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', (string)$ev['stored_name']) || !is_file($path)) {
        http_response_code(404);
        exit('증빙 파일을 찾을 수 없습니다.');
    }
    $inline = in_array($ev['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $ev['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="evidence"; filename*=UTF-8\'\'' . rawurlencode((string)$ev['original_name']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$error = '';
$notice = $_SESSION['csm_hm_notice'] ?? '';
unset($_SESSION['csm_hm_notice']);
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'register');
if (!in_array($tab, ['register', 'checklist', 'evidence'], true)) {
    $tab = 'register';
}
$selectedId = filter_var($_GET['id'] ?? $_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (str_starts_with($action, 'ajax_')) {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            if (!$canManage) {
                throw new InvalidArgumentException('변경 권한이 없습니다.');
            }
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_hm_csrf'], $_POST['csrf'])) {
                throw new InvalidArgumentException('세션이 만료되었습니다. 새로고침해 주세요.');
            }
            $eqId = filter_var($_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $itemId = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $round = (int)($_POST['round'] ?? 1);
            if ($eqId <= 0 || $itemId <= 0 || !in_array($round, [1, 2], true)) {
                throw new InvalidArgumentException('잘못된 점검 항목 요청입니다.');
            }
            $resCol = $round === 1 ? 'round1_result' : 'round2_result';
            $photoCol = $round === 1 ? 'round1_photo' : 'round2_photo';

            if ($action === 'ajax_set_item_result') {
                $val = (string)($_POST['result'] ?? '');
                if (!in_array($val, ['적정', '부적정', ''], true)) {
                    throw new InvalidArgumentException('판정 값을 확인해 주세요.');
                }
                $db->prepare("UPDATE hm_check_items SET {$resCol} = ? WHERE id = ? AND equipment_id = ?")
                   ->execute([$val, $itemId, $eqId]);
                $summary = hm_sync_equipment_round_summary($db, $eqId);
                echo json_encode(['ok' => true, 'result' => $val, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
                exit;
            } elseif ($action === 'ajax_upload_check_photo') {
                $oldStmt = $db->prepare("SELECT {$photoCol} FROM hm_check_items WHERE id = ? AND equipment_id = ?");
                $oldStmt->execute([$itemId, $eqId]);
                $oldFile = (string)($oldStmt->fetchColumn() ?: '');
                $newFile = hm_save_check_photo($_FILES['photo'] ?? [], $eqId, $itemId, $round);
                $db->prepare("UPDATE hm_check_items SET {$photoCol} = ? WHERE id = ? AND equipment_id = ?")
                   ->execute([$newFile, $itemId, $eqId]);
                if ($oldFile !== '' && $oldFile !== $newFile) {
                    @unlink(hm_check_photo_dir() . '/' . basename($oldFile));
                }
                echo json_encode([
                    'ok' => true,
                    'photo' => $newFile,
                    'url' => 'hazardous_machinery.php?check_photo=' . rawurlencode($newFile),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } elseif ($action === 'ajax_delete_check_photo') {
                $oldStmt = $db->prepare("SELECT {$photoCol} FROM hm_check_items WHERE id = ? AND equipment_id = ?");
                $oldStmt->execute([$itemId, $eqId]);
                $oldFile = (string)($oldStmt->fetchColumn() ?: '');
                $db->prepare("UPDATE hm_check_items SET {$photoCol} = '' WHERE id = ? AND equipment_id = ?")
                   ->execute([$itemId, $eqId]);
                if ($oldFile !== '') {
                    @unlink(hm_check_photo_dir() . '/' . basename($oldFile));
                }
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            throw new InvalidArgumentException('지원하지 않는 요청입니다.');
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    try {
        if (!$canManage) {
            throw new InvalidArgumentException('변경 권한이 없습니다.');
        }
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_hm_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청이 만료되었습니다. 새로고침해 주세요.');
        }

        if ($action === 'save_equipment') {
            $eqId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $code = trim((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($code === '' || $name === '') {
                throw new InvalidArgumentException('관리번호와 설비명은 필수입니다.');
            }
            $seqNo = (int)($_POST['seq_no'] ?? 0);
            $spec = trim((string)($_POST['spec'] ?? ''));
            $qty = max(1, (int)($_POST['qty'] ?? 1));
            $inspTarget = trim((string)($_POST['inspection_target'] ?? '자체점검 대상'));
            $certNo = trim((string)($_POST['cert_no'] ?? ''));
            $certValid = trim((string)($_POST['cert_valid_until'] ?? ''));
            $guard = trim((string)($_POST['guard_device'] ?? ''));
            $cycle = trim((string)($_POST['check_cycle'] ?? '6개월'));
            $hazard = trim((string)($_POST['hazard_type'] ?? ''));
            $status = trim((string)($_POST['status'] ?? '사용 가능'));
            $remarks = trim((string)($_POST['remarks'] ?? ''));
            $uploadedPhoto = hm_upload_photo($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], $code);

            if ($eqId > 0) {
                $old = $db->prepare('SELECT photo_file FROM hm_equipment WHERE id = ?');
                $old->execute([$eqId]);
                $oldRow = $old->fetch();
                if (!$oldRow) {
                    throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
                }
                $photoFile = $uploadedPhoto !== '' ? $uploadedPhoto : (string)$oldRow['photo_file'];
                $stmt = $db->prepare('UPDATE hm_equipment SET seq_no=?, code=?, name=?, spec=?, qty=?, inspection_target=?, cert_no=?, cert_valid_until=?, guard_device=?, check_cycle=?, hazard_type=?, status=?, photo_file=?, remarks=?, updated_at=? WHERE id=?');
                $stmt->execute([$seqNo, $code, $name, $spec, $qty, $inspTarget, $certNo, $certValid, $guard, $cycle, $hazard, $status, $photoFile, $remarks, date('Y-m-d H:i:s'), $eqId]);
                $_SESSION['csm_hm_notice'] = "장비 [{$code} · {$name}] 정보를 수정했습니다.";
            } else {
                if ($seqNo <= 0) {
                    $seqNo = ((int)$db->query('SELECT COALESCE(MAX(seq_no), 0) FROM hm_equipment')->fetchColumn()) + 1;
                }
                $stmt = $db->prepare('INSERT INTO hm_equipment (year, seq_no, code, name, spec, qty, inspection_target, cert_no, cert_valid_until, guard_device, check_cycle, hazard_type, status, photo_file, sheet_name, remarks, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$year, $seqNo, $code, $name, $spec, $qty, $inspTarget, $certNo, $certValid, $guard, $cycle, $hazard, $status, $uploadedPhoto, $name, $remarks, date('Y-m-d H:i:s')]);
                $newId = (int)$db->lastInsertId();
                // Default 5 basic checklist items for newly added equipment
                $defaults = [
                    '외관 손상, 부식, 균열 및 변형 등 이상 유무는 양호한가?',
                    '방호장치(덮개, 안전장치, 비상정지장치 등)는 정상 설치·작동되는가?',
                    '전원·동력부 및 절연·접지 상태는 양호한가?',
                    '작업 전 시운전 시 이상 소음·진동·발열이 없는가?',
                    '정격 하중·사용 기준 준수 및 점검필증(스티커)이 부착되어 있는가?',
                ];
                $ciStmt = $db->prepare('INSERT INTO hm_check_items (equipment_id, item_no, category, content) VALUES (?, ?, "", ?)');
                foreach ($defaults as $idx => $txt) {
                    $ciStmt->execute([$newId, $idx + 1, $txt]);
                }
                $_SESSION['csm_hm_notice'] = "신규 위험기계·기구 [{$code} · {$name}]를 등록했습니다.";
            }
            header('Location: hazardous_machinery.php?tab=register&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'delete_equipment') {
            $eqId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $db->prepare('DELETE FROM hm_equipment WHERE id = ?')->execute([$eqId]);
            $_SESSION['csm_hm_notice'] = '장비를 삭제했습니다.';
            header('Location: hazardous_machinery.php?tab=register&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'save_checklist') {
            $eqId = filter_var($_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($eqId <= 0) {
                throw new InvalidArgumentException('장비를 선택해 주세요.');
            }
            $c1Date = trim((string)($_POST['check1_date'] ?? ''));
            $c1Insp = trim((string)($_POST['check1_inspector'] ?? ''));
            $c1Sticker = in_array($_POST['check1_sticker'] ?? '', ['발부', '미발부'], true) ? (string)$_POST['check1_sticker'] : '미발부';
            $c2Date = trim((string)($_POST['check2_date'] ?? ''));
            $c2Insp = trim((string)($_POST['check2_inspector'] ?? ''));
            $c2Sticker = in_array($_POST['check2_sticker'] ?? '', ['발부', '미발부'], true) ? (string)$_POST['check2_sticker'] : '미발부';
            $actionNote = trim((string)($_POST['action_note'] ?? ''));

            $r1Post = $_POST['r1'] ?? [];
            $r2Post = $_POST['r2'] ?? [];
            $items = hm_fetch_check_items($db, $eqId);

            $r1Total = 0; $r1Fail = 0;
            $r2Total = 0; $r2Fail = 0;

            $db->beginTransaction();
            $upItem = $db->prepare('UPDATE hm_check_items SET round1_result = ?, round2_result = ? WHERE id = ? AND equipment_id = ?');
            foreach ($items as $it) {
                $iid = (int)$it['id'];
                $v1 = in_array($r1Post[$iid] ?? '', ['적정', '부적정', ''], true) ? (string)($r1Post[$iid] ?? '') : '';
                $v2 = in_array($r2Post[$iid] ?? '', ['적정', '부적정', ''], true) ? (string)($r2Post[$iid] ?? '') : '';
                $upItem->execute([$v1, $v2, $iid, $eqId]);
                if ($v1 !== '') { $r1Total++; if ($v1 === '부적정') $r1Fail++; }
                if ($v2 !== '') { $r2Total++; if ($v2 === '부적정') $r2Fail++; }
            }

            $c1Result = $r1Total === 0 ? '' : ($r1Fail > 0 ? '부적정(' . $r1Fail . '건)' : '적정');
            $c2Result = $r2Total === 0 ? '' : ($r2Fail > 0 ? '부적정(' . $r2Fail . '건)' : '적정');

            $db->prepare('UPDATE hm_equipment SET check1_date=?, check1_result=?, check1_sticker=?, check1_inspector=?, check2_date=?, check2_result=?, check2_sticker=?, check2_inspector=?, action_note=?, updated_at=? WHERE id=?')
               ->execute([$c1Date, $c1Result, $c1Sticker, $c1Insp, $c2Date, $c2Result, $c2Sticker, $c2Insp, $actionNote, date('Y-m-d H:i:s'), $eqId]);
            $db->commit();

            $_SESSION['csm_hm_notice'] = '안전점검표 결과를 저장하고 총괄 관리대장에 자동 반영했습니다.';
            header('Location: hazardous_machinery.php?tab=checklist&id=' . $eqId . '&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'add_check_item') {
            $eqId = filter_var($_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $cat = trim((string)($_POST['category'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            if ($eqId <= 0 || $content === '') {
                throw new InvalidArgumentException('점검 내용을 입력해 주세요.');
            }
            $nextNo = ((int)$db->query("SELECT COALESCE(MAX(item_no), 0) FROM hm_check_items WHERE equipment_id = {$eqId}")->fetchColumn()) + 1;
            $db->prepare('INSERT INTO hm_check_items (equipment_id, item_no, category, content) VALUES (?, ?, ?, ?)')
               ->execute([$eqId, $nextNo, $cat, $content]);
            $_SESSION['csm_hm_notice'] = '점검 항목을 추가했습니다.';
            header('Location: hazardous_machinery.php?tab=checklist&id=' . $eqId . '&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'upload_evidence') {
            hm_upload_evidence($db, $year, (string)($_POST['kind'] ?? 'register'), $_FILES['evidence'] ?? [], (string)($user['login_id'] ?? ''));
            $_SESSION['csm_hm_notice'] = '2.6.3 증빙 파일을 첨부했습니다. CSM 점수에 즉시 반영됩니다.';
            header('Location: hazardous_machinery.php?tab=evidence&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'delete_evidence') {
            $eid = filter_var($_POST['evidence_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $db->prepare('UPDATE hm_evidence SET deleted_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $eid]);
            $_SESSION['csm_hm_notice'] = '증빙 파일을 삭제했습니다.';
            header('Location: hazardous_machinery.php?tab=evidence&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'assess_263') {
            $level = (string)($_POST['level'] ?? '');
            $note = trim((string)($_POST['note'] ?? ''));
            if (!in_array($level, ['1', '0.9', '0'], true)) {
                throw new InvalidArgumentException('평가 수준을 선택해 주세요.');
            }
            $db->prepare('INSERT INTO hm_assessments (year, multiplier, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(year) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
               ->execute([$year, (float)$level, $note, (string)($user['login_id'] ?? ''), date('Y-m-d H:i:s')]);
            $_SESSION['csm_hm_notice'] = '2.6.3 평가 수준을 저장했습니다.';
            header('Location: hazardous_machinery.php?tab=evidence&year=' . $year, true, 303);
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : '처리 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'ALL');
$allEquipment = hm_fetch_all_equipment($db);
$filteredEquipment = ($q !== '' || $statusFilter !== 'ALL') ? hm_fetch_all_equipment($db, $q, $statusFilter) : $allEquipment;

if ($selectedId === 0 && !empty($allEquipment)) {
    $selectedId = (int)$allEquipment[0]['id'];
}
$selectedEq = null;
foreach ($allEquipment as $eq) {
    if ((int)$eq['id'] === $selectedId) {
        $selectedEq = $eq;
        break;
    }
}
$selectedItems = $selectedEq ? hm_fetch_check_items($db, (int)$selectedEq['id']) : [];
$evidences = hm_fetch_evidence($db, $year);
$score263 = csm_hm_score_summary($year, 2.0);
$evidenceKinds = hm_evidence_kinds();

// Summary KPIs
$totalEq = count($allEquipment);
$r1DoneCount = 0; $r1StickerCount = 0;
$r2DoneCount = 0; $r2StickerCount = 0;
$unsafeCount = 0; $certExpiredCount = 0;
foreach ($allEquipment as $eq) {
    if ($eq['check1_result'] !== '') $r1DoneCount++;
    if ($eq['check1_sticker'] === '발부') $r1StickerCount++;
    if ($eq['check2_result'] !== '') $r2DoneCount++;
    if ($eq['check2_sticker'] === '발부') $r2StickerCount++;
    if ($eq['status'] !== '사용 가능') $unsafeCount++;
    if ($eq['cert_valid_until'] !== '' && $eq['cert_valid_until'] < $today) $certExpiredCount++;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>2.6.3 위험기계·기구 관리대장 및 안전점검표 · CSM</title>
<link rel="stylesheet" href="leadership.css">
<style>
main { max-width: 1560px; margin: 0 auto; padding: 22px 20px 60px; }
.hm-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 14px; margin-bottom: 18px; }
.hm-top h1 { margin: 4px 0 6px; font-size: 1.65rem; }
.hm-top p { margin: 0; color: #475569; font-size: .94rem; }
.hm-top-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #1e293b; font-weight: 700; font-size: .88rem; cursor: pointer; text-decoration: none; }
.btn:hover { background: #f1f5f9; }
.btn-primary { background: #176053; border-color: #176053; color: #fff; }
.btn-primary:hover { background: #114b41; }
.btn-blue { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.btn-blue:hover { background: #1e40af; }
.btn-danger { background: #b91c1c; border-color: #b91c1c; color: #fff; }
.btn-sm { padding: 5px 10px; font-size: .8rem; border-radius: 6px; }

.kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
.kpi-card { background: #fff; border: 1px solid #dbe2e8; border-radius: 12px; padding: 14px 16px; }
.kpi-card small { display: block; color: #64748b; font-weight: 700; font-size: .8rem; margin-bottom: 6px; }
.kpi-card strong { font-size: 1.35rem; color: #0f172a; }
.kpi-card span { display: block; font-size: .8rem; color: #475569; margin-top: 4px; }
.kpi-card.accent { border-left: 4px solid #176053; background: #f4fbf9; }
.kpi-card.warn { border-left: 4px solid #d97706; background: #fffbeb; }

.tabs { display: flex; gap: 8px; border-bottom: 2px solid #dbe2e8; margin-bottom: 18px; flex-wrap: wrap; }
.tab-link { padding: 11px 18px; text-decoration: none; font-weight: 700; color: #475569; border-bottom: 3px solid transparent; margin-bottom: -2px; font-size: .94rem; }
.tab-link.active { color: #176053; border-bottom-color: #176053; background: #f4fbf9; border-radius: 8px 8px 0 0; }

.panel { background: #fff; border: 1px solid #dbe2e8; border-radius: 12px; padding: 20px; margin-bottom: 18px; }
.msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; background: #e6f4ea; color: #137333; border: 1px solid #a8dab5; }
.msg.error { background: #fce8e8; color: #b91c1c; border-color: #f5c2c7; }

.filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.filter-bar form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.filter-bar input[type="text"] { width: 270px; padding: 8px 11px; border: 1px solid #cbd5e1; border-radius: 7px; font: inherit; font-size: .88rem; }
.filter-bar select { width: 135px; padding: 8px 11px; border: 1px solid #cbd5e1; border-radius: 7px; font: inherit; font-size: .88rem; }

.tbl-wrap { overflow-x: auto; }
table.hm-tbl { width: 100%; border-collapse: collapse; font-size: .83rem; }
table.hm-tbl th, table.hm-tbl td { border: 1px solid #dbe2e8; padding: 7px 6px; text-align: center; vertical-align: middle; }
table.hm-tbl th { background: #f8fafc; font-weight: 700; color: #1e293b; white-space: nowrap; }
table.hm-tbl td.left { text-align: left; }
table.hm-tbl td.nowrap { white-space: nowrap; }
table.hm-tbl tbody tr:hover { background: #f8fafc; }
table.hm-tbl th.sticky-col, table.hm-tbl td.sticky-col { position: sticky; right: 0; background: #fff; box-shadow: -2px 0 4px rgba(15,23,42,.06); z-index: 2; }
table.hm-tbl th.sticky-col { background: #f8fafc; z-index: 3; }
table.hm-tbl tbody tr:hover td.sticky-col { background: #f8fafc; }
.round-cell { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.thumb { width: 48px; height: 38px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd5e1; cursor: pointer; background: #f1f5f9; }
.no-thumb { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 38px; border-radius: 6px; border: 1px dashed #cbd5e1; color: #94a3b8; font-size: .7rem; }
.badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: .76rem; font-weight: 700; white-space: nowrap; }
.b-ok { background: #dcfce7; color: #166534; }
.b-warn { background: #fef3c7; color: #92400e; }
.b-bad { background: #fee2e2; color: #991b1b; }
.b-gray { background: #f1f5f9; color: #475569; }
.b-blue { background: #dbeafe; color: #1e40af; }

/* Checklist Split Layout */
.chk-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 18px; align-items: start; }
.eq-sidebar { background: #fff; border: 1px solid #dbe2e8; border-radius: 12px; overflow: hidden; max-height: 820px; overflow-y: auto; }
.eq-sidebar-head { padding: 12px 14px; background: #f8fafc; border-bottom: 1px solid #dbe2e8; font-weight: 800; font-size: .9rem; }
.eq-nav-item { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: #1e293b; font-size: .86rem; }
.eq-nav-item:hover { background: #f8fafc; }
.eq-nav-item.active { background: #ecfdf5; border-left: 4px solid #176053; font-weight: 700; }
.eq-nav-meta { font-size: .74rem; color: #64748b; }

.chk-head-card { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0; margin-bottom: 14px; }
.chk-eq-info { display: flex; align-items: center; gap: 14px; }
.chk-eq-photo { width: 82px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; }
.chk-meta-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 14px; }
.chk-meta-grid label { display: grid; gap: 4px; font-size: .82rem; font-weight: 700; color: #334155; }
.chk-meta-grid input, .chk-meta-grid select { padding: 7px 9px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; font-size: .86rem; }

.radio-pair { display: inline-flex; gap: 10px; align-items: center; justify-content: center; }
.radio-pair label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-weight: 600; font-size: .83rem; }
.quick-bar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 12px; }

/* Mobile-First Field Inspection Checklist UI */
.mobile-eq-bar { display: none; gap: 8px; align-items: center; margin-bottom: 12px; background: #fff; border: 1px solid #dbe2e8; border-radius: 12px; padding: 10px; }
.mobile-eq-bar select { flex: 1; min-width: 0; padding: 10px 12px; font-size: .95rem; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; }
.round-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #f1f5f9; padding: 5px; border-radius: 12px; margin-bottom: 14px; }
.round-tab-btn { border: 0; background: transparent; padding: 11px 10px; border-radius: 9px; font-weight: 800; font-size: .95rem; color: #475569; cursor: pointer; transition: all .15s; }
.round-tab-btn.active { background: #176053; color: #fff; box-shadow: 0 2px 6px rgba(23,96,83,.25); }
.round-meta-box { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; background: #f8fafc; padding: 12px 14px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 14px; }
.round-meta-box label { display: grid; gap: 4px; font-size: .82rem; font-weight: 700; color: #334155; }
.round-meta-box input, .round-meta-box select { padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; font-size: .92rem; background: #fff; width: 100%; box-sizing: border-box; }

.m-chk-list { display: flex; flex-direction: column; gap: 12px; }
.m-chk-card { border: 1.5px solid #dbe2e8; border-radius: 14px; padding: 14px; background: #fff; transition: border-color .15s, background .15s; }
.m-chk-card.is-ok { border-color: #86efac; background: #f8fffb; }
.m-chk-card.is-bad { border-color: #fca5a5; background: #fff8f8; }
.m-chk-top { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
.m-chk-num { flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; background: #0f172a; color: #fff; font-weight: 800; font-size: .84rem; display: inline-flex; align-items: center; justify-content: center; }
.m-chk-title { flex: 1; font-size: .98rem; line-height: 1.48; color: #0f172a; font-weight: 600; word-break: keep-all; }
.m-chk-cat { display: inline-block; margin-right: 6px; padding: 2px 8px; border-radius: 6px; background: #e2e8f0; color: #1e293b; font-size: .78rem; font-weight: 800; }
.m-save-state { font-size: .74rem; font-weight: 700; color: #16a34a; white-space: nowrap; }

.m-judge-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.m-judge-btn { min-height: 46px; border-radius: 10px; border: 2px solid #cbd5e1; background: #fff; color: #334155; font-size: .98rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .12s; -webkit-tap-highlight-color: transparent; }
.m-judge-btn.ok.active { background: #16a34a; border-color: #16a34a; color: #fff; box-shadow: 0 3px 8px rgba(22,163,74,.28); }
.m-judge-btn.bad.active { background: #dc2626; border-color: #dc2626; color: #fff; box-shadow: 0 3px 8px rgba(220,38,38,.28); }

.m-photo-area { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #cbd5e1; }
.m-photo-area.open { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.m-photo-btns { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.m-cam-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 8px 14px; border-radius: 9px; background: #0f766e; color: #fff; font-size: .88rem; font-weight: 700; cursor: pointer; border: 0; }
.m-cam-btn.gallery { background: #fff; color: #1e293b; border: 1.5px solid #cbd5e1; }
.m-thumb-wrap { display: inline-flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 4px 8px 4px 4px; }
.m-thumb-img { width: 72px; height: 54px; object-fit: cover; border-radius: 7px; cursor: pointer; display: block; border: 1px solid #cbd5e1; }
.m-thumb-del { border: 0; background: #fee2e2; color: #b91c1c; width: 28px; height: 28px; border-radius: 50%; font-weight: 800; cursor: pointer; font-size: .9rem; }
.m-upload-status { font-size: .8rem; font-weight: 700; color: #0f766e; }

.sticky-mobile-bar { position: sticky; bottom: 10px; z-index: 40; margin-top: 16px; background: rgba(15,23,42,.95); color: #fff; border-radius: 14px; padding: 12px 16px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; box-shadow: 0 10px 25px rgba(15,23,42,.28); }
.sticky-mobile-bar .prog { font-size: .88rem; font-weight: 700; }
.sticky-mobile-bar .btns { display: flex; gap: 8px; }

@media (max-width: 1024px) {
  .mobile-eq-bar { display: flex; }
  .eq-sidebar { display: none; }
}
@media (max-width: 640px) {
  main { padding: 12px 10px 80px; }
  .panel { padding: 14px 12px; }
  .hm-top h1 { font-size: 1.25rem; }
  .kpi-grid { display: none; }
  .round-meta-box { grid-template-columns: 1fr; }
  .tabs { gap: 4px; }
  .tab-link { padding: 9px 10px; font-size: .84rem; }
  .sticky-mobile-bar { bottom: 6px; padding: 10px 12px; }
}

/* Modal */
.modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, .55); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.modal-backdrop.open { display: flex; }
.modal-box { background: #fff; border-radius: 14px; max-width: 760px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 22px 24px; box-shadow: 0 20px 40px rgba(0,0,0,.22); }
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.form-grid label { display: grid; gap: 5px; font-size: .84rem; font-weight: 700; color: #334155; }
.form-grid .wide { grid-column: 1 / -1; }
.form-grid input, .form-grid select, .form-grid textarea { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font: inherit; width: 100%; box-sizing: border-box; }

@media (max-width: 1024px) {
  .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .chk-layout { grid-template-columns: 1fr; }
  .eq-sidebar { max-height: 260px; }
  .chk-meta-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .kpi-grid, .form-grid, .chk-meta-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<header>
  <div>
    <a href="area.php?group=2&amp;section=2.6">← 위험관리 2.6</a>
    <strong>2.6.3 위험기계·기구 관리대장 및 안전점검표</strong>
    <span><?= $h($user['name'] ?? '') ?></span>
  </div>
</header>

<main>
  <div class="hm-top">
    <div>
      <small>CSM 안전보건활동평가 2.6.3 · 산업안전보건기준에 관한 규칙 제35조 제2항 별표3</small>
      <h1>위험기계·기구 관리대장 및 안전점검표 (<?= $year ?>년)</h1>
      <p>보유 위험기계·기구 21종의 총괄 관리대장, 안전검사 유효기간, 반기별(1·2차) 개별 안전점검표 및 스티커 발부 이력을 통합 관리합니다.</p>
    </div>
    <div class="hm-top-actions">
      <a class="btn btn-blue" href="hazardous_machinery_print.php?mode=register&amp;year=<?= $year ?>" target="_blank">🖨️ 관리대장 A4 인쇄</a>
      <a class="btn" href="hazardous_machinery_print.php?mode=all_checklists&amp;year=<?= $year ?>" target="_blank">📑 전체 21종 점검표 일괄 인쇄</a>
      <?php if ($canManage): ?>
      <button type="button" class="btn btn-primary" onclick="openEqModal(null)">+ 위험기계·기구 신규 등록</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?><div class="msg error" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="msg" role="status"><?= $h($notice) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <div class="kpi-card">
      <small>관리 대상 위험기계·기구</small>
      <strong><?= $totalEq ?>종</strong>
      <span>사용 가능 <?= $totalEq - $unsafeCount ?>종 · 중지/수리 <?= $unsafeCount ?>종</span>
    </div>
    <div class="kpi-card">
      <small>1차(상반기) 점검 · 스티커</small>
      <strong><?= $r1DoneCount ?> / <?= $totalEq ?></strong>
      <span>스티커 발부 <?= $r1StickerCount ?>건</span>
    </div>
    <div class="kpi-card">
      <small>2차(하반기) 점검 · 스티커</small>
      <strong><?= $r2DoneCount ?> / <?= $totalEq ?></strong>
      <span>스티커 발부 <?= $r2StickerCount ?>건</span>
    </div>
    <div class="kpi-card <?= $certExpiredCount > 0 ? 'warn' : '' ?>">
      <small>안전검사 유효기간 알림</small>
      <strong><?= $certExpiredCount ?>건</strong>
      <span><?= $certExpiredCount > 0 ? '유효기간 경과 장비 즉시 점검 필요' : '검사기한 경과 장비 없음' ?></span>
    </div>
    <div class="kpi-card <?= $score263['evidence_count'] > 0 ? 'accent' : 'warn' ?>">
      <small>CSM 2.6.3 평가 점수 (<?= $year ?>년)</small>
      <strong><?= number_format((float)$score263['earned'], 1) ?> / 2.0점</strong>
      <span><?= $score263['evidence_count'] > 0 ? '유효 증빙 ' . (int)$score263['evidence_count'] . '건 반영 중' : '증빙 탭에 스캔본 첨부 시 반영' ?></span>
    </div>
  </section>

  <nav class="tabs">
    <a class="tab-link <?= $tab === 'register' ? 'active' : '' ?>" href="?tab=register&amp;year=<?= $year ?>">📋 위험기계·기구 관리대장 (<?= $totalEq ?>)</a>
    <a class="tab-link <?= $tab === 'checklist' ? 'active' : '' ?>" href="?tab=checklist&amp;id=<?= $selectedId ?>&amp;year=<?= $year ?>">✅ 장비별 안전점검표 (21종 체크리스트)</a>
    <a class="tab-link <?= $tab === 'evidence' ? 'active' : '' ?>" href="?tab=evidence&amp;year=<?= $year ?>">📎 2.6.3 증빙 및 점수 관리 (<?= count($evidences) ?>)</a>
  </nav>

  <?php if ($tab === 'register'): ?>
  <section class="panel">
    <div class="filter-bar">
      <form method="get">
        <input type="hidden" name="tab" value="register">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="관리번호, 설비명, 규격, 재해형태 검색...">
        <select name="status">
          <option value="ALL">전체 상태</option>
          <?php foreach (['사용 가능', '사용 중지', '수리 중', '폐기'] as $st): ?>
          <option value="<?= $h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= $h($st) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">검색</button>
        <?php if ($q !== '' || $statusFilter !== 'ALL'): ?><a class="btn" href="?tab=register&amp;year=<?= $year ?>">초기화</a><?php endif; ?>
      </form>
      <div style="font-size:.84rem;color:#64748b">행 우측 <strong>점검표</strong>를 누르면 해당 장비의 1·2차 세부 체크리스트로 이동합니다.</div>
    </div>

    <div class="tbl-wrap">
      <table class="hm-tbl">
        <thead>
          <tr>
            <th>순번</th>
            <th>사진</th>
            <th>관리번호</th>
            <th>기계·기구 설비명</th>
            <th>규격</th>
            <th>수량</th>
            <th>검사대상 / 유효기간</th>
            <th>방호장치</th>
            <th>주기</th>
            <th>발생가능 재해형태</th>
            <th>1차 점검 (상반기)</th>
            <th>2차 점검 (하반기)</th>
            <th>상태</th>
            <th class="sticky-col">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filteredEquipment as $eq):
            $expired = ($eq['cert_valid_until'] !== '' && $eq['cert_valid_until'] < $today);
          ?>
          <tr>
            <td class="nowrap"><?= (int)$eq['seq_no'] ?></td>
            <td>
              <?php if ($eq['photo_file'] !== ''): ?>
                <img class="thumb" src="?photo=<?= $h($eq['photo_file']) ?>" alt="<?= $h($eq['name']) ?>" onclick="openPhotoModal(this.src, '<?= $h($eq['code'] . ' · ' . $eq['name']) ?>')">
              <?php else: ?>
                <span class="no-thumb">미등록</span>
              <?php endif; ?>
            </td>
            <td class="nowrap"><strong><?= $h($eq['code']) ?></strong></td>
            <td class="left nowrap"><strong><?= $h($eq['name']) ?></strong><br><small style="color:#64748b">점검항목 <?= (int)$eq['item_count'] ?>개</small></td>
            <td class="nowrap"><?= $h($eq['spec'] ?: '-') ?></td>
            <td class="nowrap"><?= (int)$eq['qty'] ?></td>
            <td class="nowrap">
              <span class="badge <?= str_contains($eq['inspection_target'], '법정') ? 'b-blue' : 'b-gray' ?>"><?= $h($eq['inspection_target']) ?></span>
              <?php if ($eq['cert_valid_until'] !== ''): ?>
                <div style="font-size:.74rem;margin-top:2px;<?= $expired ? 'color:#b91c1c;font-weight:700' : 'color:#475569' ?>">
                  ~<?= $h($eq['cert_valid_until']) ?><?= $expired ? ' (경과)' : '' ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="left"><?= $h($eq['guard_device'] ?: '-') ?></td>
            <td class="nowrap"><?= $h($eq['check_cycle']) ?></td>
            <td class="left"><?= $h($eq['hazard_type'] ?: '-') ?></td>
            <td class="nowrap">
              <div class="round-cell">
                <?php if ($eq['check1_result'] !== ''): ?>
                  <span class="badge <?= str_starts_with($eq['check1_result'], '부적정') ? 'b-bad' : 'b-ok' ?>"><?= $h($eq['check1_result']) ?></span>
                <?php else: ?>
                  <span class="badge b-gray">미점검</span>
                <?php endif; ?>
                <span class="badge <?= $eq['check1_sticker'] === '발부' ? 'b-ok' : 'b-gray' ?>" style="font-size:.7rem">스티커: <?= $h($eq['check1_sticker']) ?></span>
              </div>
            </td>
            <td class="nowrap">
              <div class="round-cell">
                <?php if ($eq['check2_result'] !== ''): ?>
                  <span class="badge <?= str_starts_with($eq['check2_result'], '부적정') ? 'b-bad' : 'b-ok' ?>"><?= $h($eq['check2_result']) ?></span>
                <?php else: ?>
                  <span class="badge b-gray">미점검</span>
                <?php endif; ?>
                <span class="badge <?= $eq['check2_sticker'] === '발부' ? 'b-ok' : 'b-gray' ?>" style="font-size:.7rem">스티커: <?= $h($eq['check2_sticker']) ?></span>
              </div>
            </td>
            <td class="nowrap"><span class="badge <?= $eq['status'] === '사용 가능' ? 'b-ok' : ($eq['status'] === '수리 중' ? 'b-warn' : 'b-bad') ?>"><?= $h($eq['status']) ?></span></td>
            <td class="nowrap sticky-col">
              <a class="btn btn-sm btn-primary" href="?tab=checklist&amp;id=<?= (int)$eq['id'] ?>&amp;year=<?= $year ?>">점검표</a>
              <?php if ($canManage): ?>
              <button type="button" class="btn btn-sm" onclick='openEqModal(<?= json_encode($eq, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>수정</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php elseif ($tab === 'checklist' && $selectedEq):
    $activeRound = (int)($_GET['round'] ?? ((int)date('n') >= 7 ? 2 : 1));
    if (!in_array($activeRound, [1, 2], true)) $activeRound = 1;
    $eqIds = array_map(static fn($e) => (int)$e['id'], $allEquipment);
    $curPos = array_search((int)$selectedEq['id'], $eqIds, true);
    $prevEqId = ($curPos !== false && $curPos > 0) ? $eqIds[$curPos - 1] : 0;
    $nextEqId = ($curPos !== false && $curPos < count($eqIds) - 1) ? $eqIds[$curPos + 1] : 0;
  ?>
  <!-- Mobile Quick Equipment Selector -->
  <div class="mobile-eq-bar">
    <?php if ($prevEqId): ?><a class="btn btn-sm" href="?tab=checklist&amp;id=<?= $prevEqId ?>&amp;round=<?= $activeRound ?>&amp;year=<?= $year ?>">◀</a><?php endif; ?>
    <select onchange="location.href='?tab=checklist&id='+this.value+'&round='+activeRound+'&year=<?= $year ?>'">
      <?php foreach ($allEquipment as $eq): ?>
      <option value="<?= (int)$eq['id'] ?>" <?= (int)$eq['id'] === (int)$selectedEq['id'] ? 'selected' : '' ?>>
        <?= $h($eq['code'] . ' · ' . $eq['name'] . ($eq['check' . $activeRound . '_result'] ? ' [' . $eq['check' . $activeRound . '_result'] . ']' : '')) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php if ($nextEqId): ?><a class="btn btn-sm" href="?tab=checklist&amp;id=<?= $nextEqId ?>&amp;round=<?= $activeRound ?>&amp;year=<?= $year ?>">▶</a><?php endif; ?>
  </div>

  <div class="chk-layout">
    <aside class="eq-sidebar">
      <div class="eq-sidebar-head">위험기계·기구 선택 (<?= $totalEq ?>종)</div>
      <?php foreach ($allEquipment as $eq): ?>
      <a class="eq-nav-item <?= (int)$eq['id'] === (int)$selectedEq['id'] ? 'active' : '' ?>" href="?tab=checklist&amp;id=<?= (int)$eq['id'] ?>&amp;round=<?= $activeRound ?>&amp;year=<?= $year ?>">
        <div>
          <div><?= $h($eq['code']) ?> · <?= $h($eq['name']) ?></div>
          <div class="eq-nav-meta"><?= $h($eq['spec'] ?: '규격 미기재') ?> · 항목 <?= (int)$eq['item_count'] ?>개</div>
        </div>
        <div style="display:flex;gap:3px;flex-shrink:0">
          <span class="badge <?= $eq['check1_result'] === '적정' ? 'b-ok' : ($eq['check1_result'] !== '' ? 'b-bad' : 'b-gray') ?>">1차</span>
          <span class="badge <?= $eq['check2_result'] === '적정' ? 'b-ok' : ($eq['check2_result'] !== '' ? 'b-bad' : 'b-gray') ?>">2차</span>
        </div>
      </a>
      <?php endforeach; ?>
    </aside>

    <section class="panel">
      <div class="chk-head-card">
        <div class="chk-eq-info">
          <?php if ($selectedEq['photo_file'] !== ''): ?>
            <img class="chk-eq-photo" src="?photo=<?= $h($selectedEq['photo_file']) ?>" alt="<?= $h($selectedEq['name']) ?>" onclick="openPhotoModal(this.src, '<?= $h($selectedEq['code'] . ' · ' . $selectedEq['name']) ?>')">
          <?php endif; ?>
          <div>
            <div style="font-size:.78rem;color:#176053;font-weight:700"><?= $h($selectedEq['legal_basis']) ?></div>
            <h2 style="margin:2px 0 4px;font-size:1.2rem"><?= $h($selectedEq['code'] . ' · ' . $selectedEq['name']) ?></h2>
            <div style="font-size:.82rem;color:#475569">규격: <?= $h($selectedEq['spec'] ?: '-') ?> · 방호장치: <?= $h($selectedEq['guard_device'] ?: '-') ?> · 재해: <?= $h($selectedEq['hazard_type'] ?: '-') ?></div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn btn-sm btn-blue" href="hazardous_machinery_print.php?mode=checklist&amp;id=<?= (int)$selectedEq['id'] ?>&amp;year=<?= $year ?>" target="_blank">🖨️ A4 인쇄</a>
        </div>
      </div>

      <!-- Round Switcher (1차 상반기 / 2차 하반기) -->
      <div class="round-tabs">
        <button type="button" id="rtab-1" class="round-tab-btn <?= $activeRound === 1 ? 'active' : '' ?>" onclick="switchRound(1)">1차 점검 (상반기)</button>
        <button type="button" id="rtab-2" class="round-tab-btn <?= $activeRound === 2 ? 'active' : '' ?>" onclick="switchRound(2)">2차 점검 (하반기)</button>
      </div>

      <form method="post" id="checklistForm">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_hm_csrf']) ?>">
        <input type="hidden" name="action" value="save_checklist">
        <input type="hidden" name="equipment_id" value="<?= (int)$selectedEq['id'] ?>">
        <input type="hidden" name="year" value="<?= $year ?>">

        <div id="meta-r1" class="round-meta-box" style="<?= $activeRound === 1 ? '' : 'display:none' ?>">
          <label>1차 점검일자 <input type="date" name="check1_date" value="<?= $h($selectedEq['check1_date']) ?>"></label>
          <label>1차 점검자 <input type="text" name="check1_inspector" value="<?= $h($selectedEq['check1_inspector']) ?>"></label>
          <label>1차 스티커 발부
            <select name="check1_sticker">
              <option value="발부" <?= $selectedEq['check1_sticker'] === '발부' ? 'selected' : '' ?>>발부 완료</option>
              <option value="미발부" <?= $selectedEq['check1_sticker'] !== '발부' ? 'selected' : '' ?>>미발부</option>
            </select>
          </label>
        </div>
        <div id="meta-r2" class="round-meta-box" style="<?= $activeRound === 2 ? '' : 'display:none' ?>">
          <label>2차 점검일자 <input type="date" name="check2_date" value="<?= $h($selectedEq['check2_date']) ?>"></label>
          <label>2차 점검자 <input type="text" name="check2_inspector" value="<?= $h($selectedEq['check2_inspector']) ?>"></label>
          <label>2차 스티커 발부
            <select name="check2_sticker">
              <option value="발부" <?= $selectedEq['check2_sticker'] === '발부' ? 'selected' : '' ?>>발부 완료</option>
              <option value="미발부" <?= $selectedEq['check2_sticker'] !== '발부' ? 'selected' : '' ?>>미발부</option>
            </select>
          </label>
        </div>

        <div class="quick-bar">
          <div style="font-size:.84rem;color:#475569">💡 <strong>적정 / 부적정</strong>을 터치하면 즉시 서버에 저장되고 <strong>📷 사진 촬영</strong> 버튼이 열립니다.</div>
        </div>

        <!-- Mobile-Friendly Card Checklist -->
        <div class="m-chk-list">
          <?php foreach ($selectedItems as $it):
            $iid = (int)$it['id'];
            $r1 = (string)$it['round1_result'];
            $r2 = (string)$it['round2_result'];
            $p1 = (string)($it['round1_photo'] ?? '');
            $p2 = (string)($it['round2_photo'] ?? '');
            $curRes = $activeRound === 1 ? $r1 : $r2;
            $curPhoto = $activeRound === 1 ? $p1 : $p2;
          ?>
          <div class="m-chk-card <?= $curRes === '적정' ? 'is-ok' : ($curRes === '부적정' ? 'is-bad' : '') ?>"
               id="card-<?= $iid ?>"
               data-item-id="<?= $iid ?>"
               data-r1="<?= $h($r1) ?>" data-r2="<?= $h($r2) ?>"
               data-p1="<?= $h($p1) ?>" data-p2="<?= $h($p2) ?>">
            <input type="hidden" name="r1[<?= $iid ?>]" id="hid-r1-<?= $iid ?>" value="<?= $h($r1) ?>">
            <input type="hidden" name="r2[<?= $iid ?>]" id="hid-r2-<?= $iid ?>" value="<?= $h($r2) ?>">

            <div class="m-chk-top">
              <span class="m-chk-num"><?= (int)$it['item_no'] ?></span>
              <div class="m-chk-title">
                <?php if ($it['category'] !== ''): ?><span class="m-chk-cat"><?= $h($it['category']) ?></span><?php endif; ?>
                <?= $h($it['content']) ?>
              </div>
              <span class="m-save-state" id="state-<?= $iid ?>"></span>
            </div>

            <div class="m-judge-row">
              <button type="button" class="m-judge-btn ok <?= $curRes === '적정' ? 'active' : '' ?>" id="btn-ok-<?= $iid ?>" onclick="selectJudge(<?= $iid ?>, '적정')" <?= $canManage ? '' : 'disabled' ?>>✅ 적정</button>
              <button type="button" class="m-judge-btn bad <?= $curRes === '부적정' ? 'active' : '' ?>" id="btn-bad-<?= $iid ?>" onclick="selectJudge(<?= $iid ?>, '부적정')" <?= $canManage ? '' : 'disabled' ?>>❌ 부적정</button>
            </div>

            <div class="m-photo-area <?= ($curRes !== '' || $curPhoto !== '') ? 'open' : '' ?>" id="photo-area-<?= $iid ?>">
              <?php if ($canManage): ?>
              <div class="m-photo-btns">
                <label class="m-cam-btn">
                  📷 사진 촬영
                  <input type="file" accept="image/*" capture="environment" style="display:none" onchange="handleCheckPhoto(<?= $iid ?>, this)">
                </label>
                <label class="m-cam-btn gallery">
                  🖼️ 앨범
                  <input type="file" accept="image/*" style="display:none" onchange="handleCheckPhoto(<?= $iid ?>, this)">
                </label>
                <span class="m-upload-status" id="upst-<?= $iid ?>"></span>
              </div>
              <?php endif; ?>
              <div id="thumb-slot-<?= $iid ?>">
                <?php if ($curPhoto !== ''): ?>
                <div class="m-thumb-wrap">
                  <img class="m-thumb-img" src="?check_photo=<?= $h($curPhoto) ?>" alt="현장점검 사진" onclick="openPhotoModal(this.src, '항목 <?= (int)$it['item_no'] ?> 점검 사진')">
                  <?php if ($canManage): ?><button type="button" class="m-thumb-del" title="사진 삭제" onclick="deleteCheckPhoto(<?= $iid ?>)">×</button><?php endif; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:16px">
          <label style="display:grid;gap:6px;font-weight:700;font-size:.88rem">점검결과 주요 조치 및 요구사항
            <textarea name="action_note" rows="3" style="padding:10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit" placeholder="부적정 항목에 대한 개선조치, 부품 교체, 사용 중지 및 수리 이력 등을 기록하세요."><?= $h($selectedEq['action_note']) ?></textarea>
          </label>
        </div>

        <?php if ($canManage): ?>
        <div class="sticky-mobile-bar">
          <div class="prog" id="stickyProg">진행률 계산 중...</div>
          <div class="btns">
            <button type="button" class="btn btn-sm" onclick="markAllCurrentRound('적정')">✅ 전체 적정</button>
            <button type="submit" class="btn btn-sm btn-primary">💾 저장 완료</button>
          </div>
        </div>
        <?php endif; ?>
      </form>

      <?php if ($canManage): ?>
      <form method="post" style="margin-top:18px;padding-top:14px;border-top:1px dashed #cbd5e1;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_hm_csrf']) ?>">
        <input type="hidden" name="action" value="add_check_item">
        <input type="hidden" name="equipment_id" value="<?= (int)$selectedEq['id'] ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <strong style="font-size:.84rem">+ 점검 항목 추가:</strong>
        <input type="text" name="content" required placeholder="추가할 안전점검 내용을 입력하세요" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;flex:1;min-width:200px">
        <button type="submit" class="btn btn-sm">항목 추가</button>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <?php elseif ($tab === 'evidence'): ?>
  <section class="panel" style="border-left:5px solid <?= $score263['evidence_count'] > 0 ? '#176053' : '#d97706' ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div>
        <h2 style="margin:0 0 6px">🏆 CSM 2.6.3 평가 점수 반영 현황 (<?= $year ?>년)</h2>
        <p style="margin:0;color:#475569;font-size:.9rem">
          <?php if ($score263['evidence_count'] > 0): ?>
            ✅ 유효 증빙 <strong><?= (int)$score263['evidence_count'] ?>건</strong>이 확인되어 CSM 2.6.3 점수에 <strong><?= number_format((float)$score263['earned'], 1) ?> / 2.0점</strong>이 반영 중입니다.
          <?php else: ?>
            ⚠️ 현재 첨부된 증빙 파일이 없어 <strong>0.0점</strong>입니다. 결재 완료된 관리대장·점검표 스캔본 또는 안전검사필증 사본을 첨부하면 즉시 점수에 반영됩니다.
          <?php endif; ?>
        </p>
      </div>
      <div style="font-size:1.6rem;font-weight:800;color:<?= $score263['evidence_count'] > 0 ? '#176053' : '#b45309' ?>"><?= number_format((float)$score263['earned'], 1) ?> / 2.0점</div>
    </div>

    <?php if ($canManage): ?>
    <form method="post" style="display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.6fr) auto;gap:10px;align-items:end;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_hm_csrf']) ?>">
      <input type="hidden" name="action" value="assess_263">
      <input type="hidden" name="year" value="<?= $year ?>">
      <label style="display:grid;gap:4px;font-size:.84rem;font-weight:700">평가 수준 (증빙 첨부 시 반영)
        <select name="level" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px">
          <?php $curMult = $score263['assessment'] ? (float)$score263['assessment']['multiplier'] : ((float)$score263['multiplier'] ?: 1.0); ?>
          <option value="1" <?= abs($curMult - 1.0) < 0.001 ? 'selected' : '' ?>>충족 (100% 만점 · 2.0점)</option>
          <option value="0.9" <?= abs($curMult - 0.9) < 0.001 ? 'selected' : '' ?>>실시 (90% · 1.8점)</option>
          <option value="0" <?= abs($curMult - 0.0) < 0.001 ? 'selected' : '' ?>>없음 (0% · 0.0점)</option>
        </select>
      </label>
      <label style="display:grid;gap:4px;font-size:.84rem;font-weight:700">검토 메모 (선택)
        <input type="text" name="note" value="<?= $h($score263['assessment']['note'] ?? '') ?>" placeholder="예: 위험기계·기구 21종 반기 점검 및 스티커 발부 완료" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px">
      </label>
      <button type="submit" class="btn btn-primary">평가 수준 저장</button>
    </form>
    <?php endif; ?>
  </section>

  <?php if ($canManage): ?>
  <section class="panel">
    <h2 style="margin:0 0 12px">📎 2.6.3 증빙 파일 첨부</h2>
    <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.5fr) auto;gap:12px;align-items:end">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_hm_csrf']) ?>">
      <input type="hidden" name="action" value="upload_evidence">
      <input type="hidden" name="year" value="<?= $year ?>">
      <label style="display:grid;gap:5px;font-size:.84rem;font-weight:700">증빙 구분
        <select name="kind" style="padding:9px;border:1px solid #cbd5e1;border-radius:7px">
          <?php foreach ($evidenceKinds as $k => $lbl): ?>
          <option value="<?= $h($k) ?>"><?= $h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:grid;gap:5px;font-size:.84rem;font-weight:700">파일 선택 (PDF, JPG, PNG, XLSX, DOCX · 20MB 이하)
        <input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx" required style="padding:7px;border:1px solid #cbd5e1;border-radius:7px">
      </label>
      <button type="submit" class="btn btn-primary">증빙 업로드</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="panel">
    <h2 style="margin:0 0 12px">첨부된 증빙 목록 (<?= count($evidences) ?>건)</h2>
    <?php if (!$evidences): ?>
      <p style="color:#64748b">등록된 증빙 파일이 없습니다. 상단의 <strong>관리대장 A4 인쇄</strong> 또는 <strong>전체 21종 점검표 일괄 인쇄</strong>로 출력·결재 후 스캔본을 업로드하세요.</p>
    <?php else: ?>
      <table class="hm-tbl">
        <thead>
          <tr><th>구분</th><th>파일명</th><th>크기</th><th>등록일시</th><th>관리</th></tr>
        </thead>
        <tbody>
          <?php foreach ($evidences as $ev): ?>
          <tr>
            <td><span class="badge b-blue"><?= $h($evidenceKinds[$ev['kind']] ?? $ev['kind']) ?></span></td>
            <td class="left"><a href="?evidence_file=<?= (int)$ev['id'] ?>" target="_blank" style="font-weight:700;color:#176053">📎 <?= $h($ev['original_name']) ?></a></td>
            <td><?= number_format((int)$ev['size'] / 1024, 1) ?> KB</td>
            <td><?= $h($ev['uploaded_at']) ?></td>
            <td>
              <a class="btn btn-sm" href="?evidence_file=<?= (int)$ev['id'] ?>" target="_blank">보기</a>
              <?php if ($canManage): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('이 증빙 파일을 삭제할까요?')">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_hm_csrf']) ?>">
                <input type="hidden" name="action" value="delete_evidence">
                <input type="hidden" name="evidence_id" value="<?= (int)$ev['id'] ?>">
                <input type="hidden" name="year" value="<?= $year ?>">
                <button type="submit" class="btn btn-sm btn-danger">삭제</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</main>

<!-- Equipment Add/Edit Modal -->
<div class="modal-backdrop" id="eqModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="eqModalTitle" style="margin:0">위험기계·기구 등록</h2>
      <button type="button" class="btn btn-sm" onclick="document.getElementById('eqModal').classList.remove('open')">닫기</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_hm_csrf']) ?>">
      <input type="hidden" name="action" value="save_equipment">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="form-grid">
        <label>순번 <input type="number" name="seq_no" id="f_seq_no" min="1"></label>
        <label>관리번호 * <input type="text" name="code" id="f_code" required placeholder="예: D-HD-23"></label>
        <label>기계·기구 설비명 * <input type="text" name="name" id="f_name" required placeholder="예: 체인블록(5)"></label>
        <label>규격 <input type="text" name="spec" id="f_spec" placeholder="예: 1.5ton, 11KW"></label>
        <label>수량 <input type="number" name="qty" id="f_qty" value="1" min="1"></label>
        <label>점검주기 <input type="text" name="check_cycle" id="f_check_cycle" value="6개월"></label>
        <label>안전검사 대상 구분
          <select name="inspection_target" id="f_inspection_target">
            <option value="자체점검 대상">자체점검 대상</option>
            <option value="법정 안전검사 대상">법정 안전검사 대상 (크레인·리프트·호이스트 등)</option>
            <option value="해당없음">해당없음</option>
          </select>
        </label>
        <label>안전검사 유효기간 (법정 대상 시) <input type="date" name="cert_valid_until" id="f_cert_valid_until"></label>
        <label>검사필증 번호 <input type="text" name="cert_no" id="f_cert_no" placeholder="선택 입력"></label>
        <label>장비 상태
          <select name="status" id="f_status">
            <option value="사용 가능">사용 가능</option>
            <option value="수리 중">수리 중</option>
            <option value="사용 중지">사용 중지</option>
            <option value="폐기">폐기</option>
          </select>
        </label>
        <label>방호장치 <input type="text" name="guard_device" id="f_guard_device" placeholder="예: 회전부 방호덮개, 과부하방지장치"></label>
        <label>발생가능 재해형태 <input type="text" name="hazard_type" id="f_hazard_type" placeholder="예: 끼임, 절단, 떨어짐, 감전"></label>
        <label class="wide">장비 실물 사진 (JPG, PNG · 10MB 이하) <input type="file" name="photo" accept=".jpg,.jpeg,.png"></label>
        <label class="wide">비고 <textarea name="remarks" id="f_remarks" rows="2"></textarea></label>
      </div>
      <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center">
        <div id="deleteWrap"></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn" onclick="document.getElementById('eqModal').classList.remove('open')">취소</button>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Photo Lightbox -->
<div class="modal-backdrop" id="photoModal" onclick="this.classList.remove('open')">
  <div class="modal-box" style="max-width:560px;text-align:center">
    <h3 id="photoModalTitle" style="margin:0 0 12px"></h3>
    <img id="photoModalImg" src="" alt="" style="max-width:100%;max-height:70vh;border-radius:8px;border:1px solid #cbd5e1">
  </div>
</div>

<script>
const HM_CSRF = <?= json_encode($_SESSION['csm_hm_csrf']) ?>;
const HM_EQ_ID = <?= (int)($selectedEq['id'] ?? 0) ?>;
const HM_CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
let activeRound = <?= isset($activeRound) ? (int)$activeRound : ((int)date('n') >= 7 ? 2 : 1) ?>;

function openPhotoModal(src, title) {
  document.getElementById('photoModalTitle').textContent = title;
  document.getElementById('photoModalImg').src = src;
  document.getElementById('photoModal').classList.add('open');
}
function openEqModal(eq) {
  const isEdit = !!eq;
  document.getElementById('eqModalTitle').textContent = isEdit ? ('장비 수정 · ' + eq.code) : '위험기계·기구 신규 등록';
  document.getElementById('f_id').value = isEdit ? eq.id : 0;
  document.getElementById('f_seq_no').value = isEdit ? eq.seq_no : '';
  document.getElementById('f_code').value = isEdit ? eq.code : '';
  document.getElementById('f_name').value = isEdit ? eq.name : '';
  document.getElementById('f_spec').value = isEdit ? eq.spec : '';
  document.getElementById('f_qty').value = isEdit ? eq.qty : 1;
  document.getElementById('f_check_cycle').value = isEdit ? eq.check_cycle : '6개월';
  document.getElementById('f_inspection_target').value = isEdit ? eq.inspection_target : '자체점검 대상';
  document.getElementById('f_cert_valid_until').value = isEdit ? eq.cert_valid_until : '';
  document.getElementById('f_cert_no').value = isEdit ? eq.cert_no : '';
  document.getElementById('f_status').value = isEdit ? eq.status : '사용 가능';
  document.getElementById('f_guard_device').value = isEdit ? eq.guard_device : '';
  document.getElementById('f_hazard_type').value = isEdit ? eq.hazard_type : '';
  document.getElementById('f_remarks').value = isEdit ? eq.remarks : '';
  document.getElementById('deleteWrap').innerHTML = '';
  document.getElementById('eqModal').classList.add('open');
}

function renderCardState(card) {
  const iid = card.dataset.itemId;
  const res = activeRound === 1 ? (card.dataset.r1 || '') : (card.dataset.r2 || '');
  const photo = activeRound === 1 ? (card.dataset.p1 || '') : (card.dataset.p2 || '');
  card.classList.toggle('is-ok', res === '적정');
  card.classList.toggle('is-bad', res === '부적정');
  const btnOk = document.getElementById('btn-ok-' + iid);
  const btnBad = document.getElementById('btn-bad-' + iid);
  if (btnOk) btnOk.classList.toggle('active', res === '적정');
  if (btnBad) btnBad.classList.toggle('active', res === '부적정');
  const photoArea = document.getElementById('photo-area-' + iid);
  if (photoArea) photoArea.classList.toggle('open', res !== '' || photo !== '');
  const slot = document.getElementById('thumb-slot-' + iid);
  if (slot) {
    if (photo) {
      const url = 'hazardous_machinery.php?check_photo=' + encodeURIComponent(photo);
      slot.innerHTML = `<div class="m-thumb-wrap">
        <img class="m-thumb-img" src="${url}" alt="현장점검 사진" onclick="openPhotoModal('${url}', '현장점검 촬영 사진')">
        ${HM_CAN_MANAGE ? `<button type="button" class="m-thumb-del" title="사진 삭제" onclick="deleteCheckPhoto(${iid})">×</button>` : ''}
      </div>`;
    } else {
      slot.innerHTML = '';
    }
  }
}

function switchRound(r) {
  activeRound = r;
  const t1 = document.getElementById('rtab-1');
  const t2 = document.getElementById('rtab-2');
  const m1 = document.getElementById('meta-r1');
  const m2 = document.getElementById('meta-r2');
  if (t1) t1.classList.toggle('active', r === 1);
  if (t2) t2.classList.toggle('active', r === 2);
  if (m1) m1.style.display = r === 1 ? '' : 'none';
  if (m2) m2.style.display = r === 2 ? '' : 'none';
  document.querySelectorAll('.m-chk-card').forEach(renderCardState);
  updateStickyProgress();
}

function updateStickyProgress() {
  const prog = document.getElementById('stickyProg');
  if (!prog) return;
  const cards = document.querySelectorAll('.m-chk-card');
  let done = 0, photos = 0;
  cards.forEach(c => {
    const res = activeRound === 1 ? (c.dataset.r1 || '') : (c.dataset.r2 || '');
    const ph = activeRound === 1 ? (c.dataset.p1 || '') : (c.dataset.p2 || '');
    if (res !== '') done++;
    if (ph !== '') photos++;
  });
  const label = activeRound === 1 ? '1차(상반기)' : '2차(하반기)';
  prog.textContent = `${label} · 점검 ${done}/${cards.length} · 사진 ${photos}장`;
}

async function selectJudge(itemId, val) {
  if (!HM_CAN_MANAGE) return;
  const card = document.getElementById('card-' + itemId);
  if (!card) return;
  if (activeRound === 1) {
    card.dataset.r1 = val;
    document.getElementById('hid-r1-' + itemId).value = val;
  } else {
    card.dataset.r2 = val;
    document.getElementById('hid-r2-' + itemId).value = val;
  }
  renderCardState(card);
  updateStickyProgress();

  const st = document.getElementById('state-' + itemId);
  if (st) st.textContent = '저장중...';
  try {
    const fd = new FormData();
    fd.append('csrf', HM_CSRF);
    fd.append('action', 'ajax_set_item_result');
    fd.append('equipment_id', String(HM_EQ_ID));
    fd.append('item_id', String(itemId));
    fd.append('round', String(activeRound));
    fd.append('result', val);
    const res = await fetch('hazardous_machinery.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || '저장 실패');
    if (st) {
      st.textContent = '✓ 저장됨';
      setTimeout(() => { if (st.textContent === '✓ 저장됨') st.textContent = ''; }, 1800);
    }
  } catch (e) {
    if (st) st.textContent = '⚠ 오류';
    alert(e.message || '점검 결과 저장 중 오류가 발생했습니다.');
  }
}

function compressImage(file, maxSide = 1280, quality = 0.82) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      let w = img.naturalWidth, h = img.naturalHeight;
      if (w > maxSide || h > maxSide) {
        if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
        else { w = Math.round(w * maxSide / h); h = maxSide; }
      }
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);
      canvas.toBlob(blob => {
        if (!blob) return reject(new Error('이미지 압축 실패'));
        resolve(new File([blob], 'inspection.jpg', { type: 'image/jpeg' }));
      }, 'image/jpeg', quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });
}

async function handleCheckPhoto(itemId, input) {
  const rawFile = input.files && input.files[0];
  input.value = '';
  if (!rawFile) return;
  const upst = document.getElementById('upst-' + itemId);
  if (upst) upst.textContent = '⏳ 사진 업로드 중...';
  try {
    const compressed = await compressImage(rawFile);
    const fd = new FormData();
    fd.append('csrf', HM_CSRF);
    fd.append('action', 'ajax_upload_check_photo');
    fd.append('equipment_id', String(HM_EQ_ID));
    fd.append('item_id', String(itemId));
    fd.append('round', String(activeRound));
    fd.append('photo', compressed);
    const res = await fetch('hazardous_machinery.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || '업로드 실패');
    const card = document.getElementById('card-' + itemId);
    if (card) {
      if (activeRound === 1) card.dataset.p1 = data.photo;
      else card.dataset.p2 = data.photo;
      renderCardState(card);
    }
    updateStickyProgress();
    if (upst) {
      upst.textContent = '✅ 서버 저장 완료';
      setTimeout(() => { if (upst.textContent === '✅ 서버 저장 완료') upst.textContent = ''; }, 2000);
    }
  } catch (e) {
    if (upst) upst.textContent = '';
    alert(e.message || '사진 업로드 중 오류가 발생했습니다.');
  }
}

async function deleteCheckPhoto(itemId) {
  if (!confirm('이 점검 사진을 삭제할까요?')) return;
  try {
    const fd = new FormData();
    fd.append('csrf', HM_CSRF);
    fd.append('action', 'ajax_delete_check_photo');
    fd.append('equipment_id', String(HM_EQ_ID));
    fd.append('item_id', String(itemId));
    fd.append('round', String(activeRound));
    const res = await fetch('hazardous_machinery.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || '삭제 실패');
    const card = document.getElementById('card-' + itemId);
    if (card) {
      if (activeRound === 1) card.dataset.p1 = '';
      else card.dataset.p2 = '';
      renderCardState(card);
    }
    updateStickyProgress();
  } catch (e) {
    alert(e.message || '사진 삭제 중 오류가 발생했습니다.');
  }
}

function markAllCurrentRound(val) {
  document.querySelectorAll('.m-chk-card').forEach(card => {
    const iid = Number(card.dataset.itemId);
    selectJudge(iid, val);
  });
}

window.addEventListener('DOMContentLoaded', updateStickyProgress);
</script>
</body>
</html>
