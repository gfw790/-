<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/portable_tools_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$canManage = auth_can_manage($user);
$db = pt_db();
$year = filter_var($_GET['year'] ?? $_POST['year'] ?? date('Y'), FILTER_VALIDATE_INT) ?: (int)date('Y');
$_SESSION['csm_pt_csrf'] ??= bin2hex(random_bytes(32));

// Serve tool photo securely
if (isset($_GET['photo'])) {
    $file = basename((string)$_GET['photo']);
    if (!preg_match('/^[a-z0-9_.-]+\.(png|jpg|jpeg)$/i', $file)) { http_response_code(404); exit; }
    $path = pt_photo_dir() . '/' . $file;
    if (!is_file($path)) { http_response_code(404); exit; }
    $mime = str_ends_with(strtolower($file), '.png') ? 'image/png' : 'image/jpeg';
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Serve field inspection photo securely
if (isset($_GET['check_photo'])) {
    $file = basename((string)$_GET['check_photo']);
    if (!preg_match('/^ptchk_e\d+_i\d+_r[12]_[a-f0-9]+\.(jpg|png|webp)$/i', $file)) { http_response_code(404); exit; }
    $path = pt_check_photo_dir() . '/' . $file;
    if (!is_file($path)) { http_response_code(404); exit; }
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
    $stmt = $db->prepare('SELECT * FROM pt_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$eid]);
    $ev = $stmt->fetch();
    $path = $ev ? pt_evidence_dir() . '/' . $ev['stored_name'] : '';
    if (!$ev || !preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', (string)$ev['stored_name']) || !is_file($path)) {
        http_response_code(404); exit('증빙 파일을 찾을 수 없습니다.');
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
$notice = $_SESSION['csm_pt_notice'] ?? '';
unset($_SESSION['csm_pt_notice']);
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'register');
if (!in_array($tab, ['register', 'checklist', 'evidence'], true)) $tab = 'register';
$selectedId = filter_var($_GET['id'] ?? $_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (str_starts_with($action, 'ajax_')) {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            if (!$canManage) throw new InvalidArgumentException('변경 권한이 없습니다.');
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_pt_csrf'], $_POST['csrf'])) {
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
                if (!in_array($val, ['적정', '부적정', ''], true)) throw new InvalidArgumentException('판정 값을 확인해 주세요.');
                $db->prepare("UPDATE pt_check_items SET {$resCol} = ? WHERE id = ? AND equipment_id = ?")->execute([$val, $itemId, $eqId]);
                $summary = pt_sync_equipment_round_summary($db, $eqId);
                echo json_encode(['ok' => true, 'result' => $val, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
                exit;
            } elseif ($action === 'ajax_upload_check_photo') {
                $oldStmt = $db->prepare("SELECT {$photoCol} FROM pt_check_items WHERE id = ? AND equipment_id = ?");
                $oldStmt->execute([$itemId, $eqId]);
                $oldFile = (string)($oldStmt->fetchColumn() ?: '');
                $newFile = pt_save_check_photo($_FILES['photo'] ?? [], $eqId, $itemId, $round);
                $db->prepare("UPDATE pt_check_items SET {$photoCol} = ? WHERE id = ? AND equipment_id = ?")->execute([$newFile, $itemId, $eqId]);
                if ($oldFile !== '' && $oldFile !== $newFile) @unlink(pt_check_photo_dir() . '/' . basename($oldFile));
                echo json_encode(['ok' => true, 'photo' => $newFile, 'url' => 'portable_tools.php?check_photo=' . rawurlencode($newFile)], JSON_UNESCAPED_UNICODE);
                exit;
            } elseif ($action === 'ajax_delete_check_photo') {
                $oldStmt = $db->prepare("SELECT {$photoCol} FROM pt_check_items WHERE id = ? AND equipment_id = ?");
                $oldStmt->execute([$itemId, $eqId]);
                $oldFile = (string)($oldStmt->fetchColumn() ?: '');
                $db->prepare("UPDATE pt_check_items SET {$photoCol} = '' WHERE id = ? AND equipment_id = ?")->execute([$itemId, $eqId]);
                if ($oldFile !== '') @unlink(pt_check_photo_dir() . '/' . basename($oldFile));
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
        if (!$canManage) throw new InvalidArgumentException('변경 권한이 없습니다.');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_pt_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청이 만료되었습니다. 새로고침해 주세요.');
        }

        if ($action === 'save_equipment') {
            $eqId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $code = trim((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($code === '' || $name === '') throw new InvalidArgumentException('관리번호와 기기명은 필수입니다.');
            $seqNo = (int)($_POST['seq_no'] ?? 0);
            $voltage = trim((string)($_POST['voltage'] ?? '220'));
            $amp = trim((string)($_POST['current_amp'] ?? ''));
            $spec = trim((string)($_POST['spec'] ?? ''));
            $cable = trim((string)($_POST['cable_len'] ?? ''));
            $socket = trim((string)($_POST['socket_type'] ?? '접지형'));
            $ioType = trim((string)($_POST['indoor_outdoor'] ?? '옥내·외'));
            $dblIns = trim((string)($_POST['double_insulated'] ?? '확인'));
            $qty = max(1, (int)($_POST['qty'] ?? 1));
            $status = trim((string)($_POST['status'] ?? '사용 가능'));
            $remarks = trim((string)($_POST['remarks'] ?? ''));
            $uploadedPhoto = '';
            if (!empty($_POST['photo_base64']) && is_string($_POST['photo_base64'])) {
                $uploadedPhoto = pt_save_photo_base64($_POST['photo_base64'], $code);
            } else {
                $uploadedPhoto = pt_upload_photo($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], $code);
            }

            if ($eqId > 0) {
                $old = $db->prepare('SELECT photo_file FROM pt_equipment WHERE id = ?');
                $old->execute([$eqId]);
                $oldRow = $old->fetch();
                if (!$oldRow) throw new InvalidArgumentException('공구를 찾을 수 없습니다.');
                $photoFile = $uploadedPhoto !== '' ? $uploadedPhoto : (string)$oldRow['photo_file'];
                $db->prepare('UPDATE pt_equipment SET seq_no=?, code=?, name=?, voltage=?, current_amp=?, spec=?, cable_len=?, socket_type=?, indoor_outdoor=?, double_insulated=?, qty=?, status=?, photo_file=?, remarks=?, updated_at=? WHERE id=?')
                   ->execute([$seqNo, $code, $name, $voltage, $amp, $spec, $cable, $socket, $ioType, $dblIns, $qty, $status, $photoFile, $remarks, date('Y-m-d H:i:s'), $eqId]);
                $_SESSION['csm_pt_notice'] = "공구 [{$code} · {$name}] 정보를 수정했습니다.";
            } else {
                if ($seqNo <= 0) $seqNo = ((int)$db->query('SELECT COALESCE(MAX(seq_no), 0) FROM pt_equipment')->fetchColumn()) + 1;
                $db->prepare('INSERT INTO pt_equipment (year, seq_no, code, name, voltage, current_amp, spec, cable_len, socket_type, indoor_outdoor, double_insulated, qty, status, photo_file, remarks, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$year, $seqNo, $code, $name, $voltage, $amp, $spec, $cable, $socket, $ioType, $dblIns, $qty, $status, $uploadedPhoto, $remarks, date('Y-m-d H:i:s')]);
                $newId = (int)$db->lastInsertId();
                $defaults = [
                    ['플러그/소켓', '플러그 핀 변형·파손 및 충전부 노출이 없는가?'],
                    ['케이블/피복', '전원 케이블 피복 손상·절연파괴·도체 노출 및 연장 길이가 적정한가?'],
                    ['외부케이스', '본체 케이스 균열·파손 및 과열·탄 흔적이 없는가?'],
                    ['방호/작동부', '회전부 방호덮개·안전스위치·고정척 등 방호장치 및 작동 상태가 양호한가?'],
                    ['절연/접지', '이중절연 마크(回) 확인 또는 접지선·누전차단기(ELB) 상태가 양호한가?'],
                    ['절연저항', '절연저항(1MΩ 이상) 및 접지 도통 상태가 적정한가?'],
                    ['점검필증', '관리번호 및 안전점검 필증(스티커)이 식별 가능하게 부착되어 있는가?'],
                ];
                $ciStmt = $db->prepare('INSERT INTO pt_check_items (equipment_id, item_no, category, content) VALUES (?, ?, ?, ?)');
                foreach ($defaults as $idx => $d) $ciStmt->execute([$newId, $idx + 1, $d[0], $d[1]]);
                $_SESSION['csm_pt_notice'] = "신규 휴대용 공구 [{$code} · {$name}]를 등록했습니다.";
            }
            header('Location: portable_tools.php?tab=register&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'save_checklist') {
            $eqId = filter_var($_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($eqId <= 0) throw new InvalidArgumentException('공구를 선택해 주세요.');
            $c1Date = trim((string)($_POST['check1_date'] ?? ''));
            $c1Insp = trim((string)($_POST['check1_inspector'] ?? ''));
            $c1Sticker = in_array($_POST['check1_sticker'] ?? '', ['발부', '미발부'], true) ? (string)$_POST['check1_sticker'] : '미발부';
            $c2Date = trim((string)($_POST['check2_date'] ?? ''));
            $c2Insp = trim((string)($_POST['check2_inspector'] ?? ''));
            $c2Sticker = in_array($_POST['check2_sticker'] ?? '', ['발부', '미발부'], true) ? (string)$_POST['check2_sticker'] : '미발부';
            $actionNote = trim((string)($_POST['action_note'] ?? ''));
            $r1Post = $_POST['r1'] ?? [];
            $r2Post = $_POST['r2'] ?? [];
            $items = pt_fetch_check_items($db, $eqId);

            $db->beginTransaction();
            $upItem = $db->prepare('UPDATE pt_check_items SET round1_result = ?, round2_result = ? WHERE id = ? AND equipment_id = ?');
            foreach ($items as $it) {
                $iid = (int)$it['id'];
                $v1 = in_array($r1Post[$iid] ?? '', ['적정', '부적정', ''], true) ? (string)($r1Post[$iid] ?? '') : '';
                $v2 = in_array($r2Post[$iid] ?? '', ['적정', '부적정', ''], true) ? (string)($r2Post[$iid] ?? '') : '';
                $upItem->execute([$v1, $v2, $iid, $eqId]);
            }
            $db->prepare('UPDATE pt_equipment SET check1_date=?, check1_sticker=?, check1_inspector=?, check2_date=?, check2_sticker=?, check2_inspector=?, action_note=?, updated_at=? WHERE id=?')
               ->execute([$c1Date, $c1Sticker, $c1Insp, $c2Date, $c2Sticker, $c2Insp, $actionNote, date('Y-m-d H:i:s'), $eqId]);
            pt_sync_equipment_round_summary($db, $eqId);
            $db->commit();

            $_SESSION['csm_pt_notice'] = '안전점검표 결과를 저장하고 관리대장에 자동 반영했습니다.';
            header('Location: portable_tools.php?tab=checklist&id=' . $eqId . '&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'add_check_item') {
            $eqId = filter_var($_POST['equipment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $cat = trim((string)($_POST['category'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            if ($eqId <= 0 || $content === '') throw new InvalidArgumentException('점검 내용을 입력해 주세요.');
            $nextNo = ((int)$db->query("SELECT COALESCE(MAX(item_no), 0) FROM pt_check_items WHERE equipment_id = {$eqId}")->fetchColumn()) + 1;
            $db->prepare('INSERT INTO pt_check_items (equipment_id, item_no, category, content) VALUES (?, ?, ?, ?)')
               ->execute([$eqId, $nextNo, $cat, $content]);
            $_SESSION['csm_pt_notice'] = '점검 항목을 추가했습니다.';
            header('Location: portable_tools.php?tab=checklist&id=' . $eqId . '&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'upload_evidence') {
            pt_upload_evidence($db, $year, (string)($_POST['kind'] ?? 'register'), $_FILES['evidence'] ?? [], (string)($user['login_id'] ?? ''));
            $_SESSION['csm_pt_notice'] = '2.6.2 증빙 파일을 첨부했습니다. CSM 점수에 즉시 반영됩니다.';
            header('Location: portable_tools.php?tab=evidence&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'delete_evidence') {
            $eid = filter_var($_POST['evidence_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $db->prepare('UPDATE pt_evidence SET deleted_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $eid]);
            $_SESSION['csm_pt_notice'] = '증빙 파일을 삭제했습니다.';
            header('Location: portable_tools.php?tab=evidence&year=' . $year, true, 303);
            exit;
        } elseif ($action === 'assess_262') {
            $level = (string)($_POST['level'] ?? '');
            $note = trim((string)($_POST['note'] ?? ''));
            if (!in_array($level, ['1', '0.9', '0.8', '0.5', '0'], true)) throw new InvalidArgumentException('평가 수준을 선택해 주세요.');
            $db->prepare('INSERT INTO pt_assessments (year, multiplier, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(year) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
               ->execute([$year, (float)$level, $note, (string)($user['login_id'] ?? ''), date('Y-m-d H:i:s')]);
            $_SESSION['csm_pt_notice'] = '2.6.2 평가 수준을 저장했습니다.';
            header('Location: portable_tools.php?tab=evidence&year=' . $year, true, 303);
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : '처리 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'ALL');
$allEquipment = pt_fetch_all_equipment($db);
$filteredEquipment = ($q !== '' || $statusFilter !== 'ALL') ? pt_fetch_all_equipment($db, $q, $statusFilter) : $allEquipment;

if ($selectedId === 0 && !empty($allEquipment)) $selectedId = (int)$allEquipment[0]['id'];
$selectedEq = null;
foreach ($allEquipment as $eq) {
    if ((int)$eq['id'] === $selectedId) { $selectedEq = $eq; break; }
}
$selectedItems = $selectedEq ? pt_fetch_check_items($db, (int)$selectedEq['id']) : [];
$evidences = pt_fetch_evidence($db, $year);
$score262 = csm_pt_score_summary($year, 2.0);
$evidenceKinds = pt_evidence_kinds();

$totalEq = count($allEquipment);
$r1DoneCount = 0; $r1StickerCount = 0;
$r2DoneCount = 0; $r2StickerCount = 0;
$unsafeCount = 0;
foreach ($allEquipment as $eq) {
    if ($eq['check1_result'] !== '') $r1DoneCount++;
    if ($eq['check1_sticker'] === '발부') $r1StickerCount++;
    if ($eq['check2_result'] !== '') $r2DoneCount++;
    if ($eq['check2_sticker'] === '발부') $r2StickerCount++;
    if ($eq['status'] !== '사용 가능') $unsafeCount++;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>2.6.2 휴대용 공구·기구 관리대장 및 안전점검표 · CSM</title>
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

.kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
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
.badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: .75rem; font-weight: 700; white-space: nowrap; }
.b-ok { background: #dcfce7; color: #166534; }
.b-warn { background: #fef3c7; color: #92400e; }
.b-bad { background: #fee2e2; color: #991b1b; }
.b-gray { background: #f1f5f9; color: #475569; }
.b-blue { background: #dbeafe; color: #1e40af; }

.chk-layout { display: grid; grid-template-columns: 310px minmax(0, 1fr); gap: 18px; align-items: start; }
.eq-sidebar { background: #fff; border: 1px solid #dbe2e8; border-radius: 12px; overflow: hidden; max-height: 820px; overflow-y: auto; }
.eq-sidebar-head { padding: 12px 14px; background: #f8fafc; border-bottom: 1px solid #dbe2e8; font-weight: 800; font-size: .9rem; }
.eq-nav-item { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 9px 12px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: #1e293b; font-size: .84rem; }
.eq-nav-item:hover { background: #f8fafc; }
.eq-nav-item.active { background: #ecfdf5; border-left: 4px solid #176053; font-weight: 700; }
.eq-nav-meta { font-size: .72rem; color: #64748b; }

.chk-head-card { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0; margin-bottom: 14px; }
.chk-eq-info { display: flex; align-items: center; gap: 14px; }
.chk-eq-photo { width: 82px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; cursor: pointer; }
.chk-meta-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 14px; }
.chk-meta-grid label { display: grid; gap: 4px; font-size: .82rem; font-weight: 700; color: #334155; }
.chk-meta-grid input, .chk-meta-grid select { padding: 7px 9px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; font-size: .86rem; }
.radio-pair { display: inline-flex; gap: 10px; align-items: center; justify-content: center; }
.radio-pair label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-weight: 600; font-size: .83rem; }
.quick-bar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 12px; }

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
    <strong>2.6.2 휴대용 공구·기구 관리대장 및 안전점검표</strong>
    <span><?= $h($user['name'] ?? '') ?></span>
  </div>
</header>

<main>
  <div class="hm-top">
    <div>
      <small>CSM 안전보건활동평가 2.6.2 · 휴대용 공구와 기구 관리대장 및 전기·안전 Check Sheet</small>
      <h1>휴대용 공구·기구 관리대장 및 안전점검표 (<?= $year ?>년)</h1>
      <p>보유 휴대용 공구·기구 <?= $totalEq ?>종의 전기 사양(전압·전류·케이블·접지), 실물 사진, 점검필증(스티커) 및 개별 안전점검표를 통합 관리합니다.</p>
    </div>
    <div class="hm-top-actions">
      <a class="btn btn-primary" href="portable_tools_mobile.php?year=<?= $year ?>">📱 모바일 현장점검</a>
      <a class="btn btn-blue" href="portable_tools_print.php?mode=register&amp;year=<?= $year ?>" target="_blank">🖨️ 관리대장 A4 인쇄</a>
      <a class="btn" href="portable_tools_print.php?mode=all_checklists&amp;year=<?= $year ?>" target="_blank">📑 전체 <?= $totalEq ?>종 점검표 일괄 인쇄</a>
      <?php if ($canManage): ?>
      <button type="button" class="btn" onclick="openEqModal(null)">+ 공구 신규 등록</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?><div class="msg error" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="msg" role="status"><?= $h($notice) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <div class="kpi-card">
      <small>관리 대상 휴대용 공구·기구</small>
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
    <div class="kpi-card <?= $score262['evidence_count'] > 0 ? 'accent' : 'warn' ?>">
      <small>CSM 2.6.2 평가 점수 (<?= $year ?>년)</small>
      <strong><?= number_format((float)$score262['earned'], 1) ?> / 2.0점</strong>
      <span><?= $score262['evidence_count'] > 0 ? '유효 증빙 ' . (int)$score262['evidence_count'] . '건 반영 중' : '증빙 탭에 스캔본 첨부 시 반영' ?></span>
    </div>
  </section>

  <nav class="tabs">
    <a class="tab-link <?= $tab === 'register' ? 'active' : '' ?>" href="?tab=register&amp;year=<?= $year ?>">📋 휴대용 공구·기구 관리대장 (<?= $totalEq ?>)</a>
    <a class="tab-link <?= $tab === 'checklist' ? 'active' : '' ?>" href="?tab=checklist&amp;id=<?= $selectedId ?>&amp;year=<?= $year ?>">✅ 공구별 안전점검표 (<?= $totalEq ?>종)</a>
    <a class="tab-link <?= $tab === 'evidence' ? 'active' : '' ?>" href="?tab=evidence&amp;year=<?= $year ?>">📎 2.6.2 증빙 및 점수 관리 (<?= count($evidences) ?>)</a>
  </nav>

  <?php if ($tab === 'register'): ?>
  <section class="panel">
    <div class="filter-bar">
      <form method="get">
        <input type="hidden" name="tab" value="register">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="관리번호(E-HD), 기기명, 규격 검색...">
        <select name="status">
          <option value="ALL">전체 상태</option>
          <?php foreach (['사용 가능', '사용 중지', '수리 중', '폐기'] as $st): ?>
          <option value="<?= $h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= $h($st) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">검색</button>
        <?php if ($q !== '' || $statusFilter !== 'ALL'): ?><a class="btn" href="?tab=register&amp;year=<?= $year ?>">초기화</a><?php endif; ?>
      </form>
      <div style="font-size:.84rem;color:#64748b">우측 <strong>점검표</strong>를 누르면 해당 공구의 안전점검 체크리스트로 이동합니다.</div>
    </div>

    <div class="tbl-wrap">
      <table class="hm-tbl">
        <thead>
          <tr>
            <th>순번</th>
            <th>사진</th>
            <th>관리번호</th>
            <th>기기명</th>
            <th>전압(V)</th>
            <th>전류(A)</th>
            <th>규격</th>
            <th>케이블(m)</th>
            <th>소켓/플러그</th>
            <th>옥내·외</th>
            <th>수량</th>
            <th>1차 점검</th>
            <th>2차 점검</th>
            <th>상태</th>
            <th class="sticky-col">관리</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filteredEquipment as $eq): ?>
          <tr>
            <td class="nowrap"><?= (int)$eq['seq_no'] ?></td>
            <td>
              <?php if ($eq['photo_file'] !== ''): ?>
                <img class="thumb" src="?photo=<?= $h($eq['photo_file']) ?>" alt="<?= $h($eq['name']) ?>" onclick="openPhotoModal(this.src, '<?= $h($eq['code'] . ' · ' . $eq['name']) ?>')">
              <?php else: ?>
                <span style="color:#94a3b8;font-size:.72rem">미등록</span>
              <?php endif; ?>
            </td>
            <td class="nowrap"><strong><?= $h($eq['code']) ?></strong></td>
            <td class="left nowrap"><strong><?= $h($eq['name']) ?></strong></td>
            <td class="nowrap"><?= $h($eq['voltage'] ?: '-') ?></td>
            <td class="nowrap"><?= $h($eq['current_amp'] ?: '-') ?></td>
            <td class="nowrap"><?= $h($eq['spec'] ?: '-') ?></td>
            <td class="nowrap"><?= $h($eq['cable_len'] ? $eq['cable_len'] . 'm' : '-') ?></td>
            <td class="nowrap"><?= $h($eq['socket_type'] ?: '-') ?></td>
            <td class="nowrap"><?= $h($eq['indoor_outdoor'] ?: '-') ?></td>
            <td class="nowrap"><?= (int)$eq['qty'] ?></td>
            <td class="nowrap">
              <div class="round-cell">
                <span class="badge <?= $eq['check1_result'] === '적정' ? 'b-ok' : ($eq['check1_result'] !== '' ? 'b-bad' : 'b-gray') ?>"><?= $h($eq['check1_result'] ?: '미점검') ?></span>
                <span class="badge <?= $eq['check1_sticker'] === '발부' ? 'b-ok' : 'b-gray' ?>" style="font-size:.69rem">스티커: <?= $h($eq['check1_sticker']) ?></span>
              </div>
            </td>
            <td class="nowrap">
              <div class="round-cell">
                <span class="badge <?= $eq['check2_result'] === '적정' ? 'b-ok' : ($eq['check2_result'] !== '' ? 'b-bad' : 'b-gray') ?>"><?= $h($eq['check2_result'] ?: '미점검') ?></span>
                <span class="badge <?= $eq['check2_sticker'] === '발부' ? 'b-ok' : 'b-gray' ?>" style="font-size:.69rem">스티커: <?= $h($eq['check2_sticker']) ?></span>
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

  <?php elseif ($tab === 'checklist' && $selectedEq): ?>
  <div class="chk-layout">
    <aside class="eq-sidebar">
      <div class="eq-sidebar-head">휴대용 공구·기구 선택 (<?= $totalEq ?>종)</div>
      <?php foreach ($allEquipment as $eq): ?>
      <a class="eq-nav-item <?= (int)$eq['id'] === (int)$selectedEq['id'] ? 'active' : '' ?>" href="?tab=checklist&amp;id=<?= (int)$eq['id'] ?>&amp;year=<?= $year ?>">
        <div>
          <div><?= $h($eq['code']) ?> · <?= $h($eq['name']) ?></div>
          <div class="eq-nav-meta"><?= $h($eq['spec'] ?: '-') ?> · <?= $h($eq['voltage']) ?>V</div>
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
            <div style="font-size:.8rem;color:#176053;font-weight:700">Check Sheet (휴대용 공구와 기구) · CSM 2.6.2</div>
            <h2 style="margin:2px 0 4px"><?= $h($selectedEq['code'] . ' · ' . $selectedEq['name']) ?> 안전점검표</h2>
            <div style="font-size:.84rem;color:#475569">정격: <?= $h($selectedEq['voltage']) ?>V / <?= $h($selectedEq['current_amp'] ?: '-') ?> · 규격: <?= $h($selectedEq['spec'] ?: '-') ?> · 케이블: <?= $h($selectedEq['cable_len'] ? $selectedEq['cable_len'] . 'm' : '-') ?> · <?= $h($selectedEq['socket_type']) ?> (<?= $h($selectedEq['indoor_outdoor']) ?>)</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn btn-sm btn-primary" href="portable_tools_mobile.php?id=<?= (int)$selectedEq['id'] ?>&amp;year=<?= $year ?>">📱 이 공구 모바일 점검</a>
          <a class="btn btn-sm btn-blue" href="portable_tools_print.php?mode=checklist&amp;id=<?= (int)$selectedEq['id'] ?>&amp;year=<?= $year ?>" target="_blank">🖨️ A4 인쇄</a>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
        <input type="hidden" name="action" value="save_checklist">
        <input type="hidden" name="equipment_id" value="<?= (int)$selectedEq['id'] ?>">
        <input type="hidden" name="year" value="<?= $year ?>">

        <div class="chk-meta-grid">
          <label>1차(상반기) 점검일자 <input type="date" name="check1_date" value="<?= $h($selectedEq['check1_date']) ?>"></label>
          <label>1차 점검자 <input type="text" name="check1_inspector" value="<?= $h($selectedEq['check1_inspector']) ?>"></label>
          <label>1차 스티커 발부
            <select name="check1_sticker">
              <option value="발부" <?= $selectedEq['check1_sticker'] === '발부' ? 'selected' : '' ?>>발부 완료</option>
              <option value="미발부" <?= $selectedEq['check1_sticker'] !== '발부' ? 'selected' : '' ?>>미발부</option>
            </select>
          </label>
          <label>2차(하반기) 점검일자 <input type="date" name="check2_date" value="<?= $h($selectedEq['check2_date']) ?>"></label>
          <label>2차 점검자 <input type="text" name="check2_inspector" value="<?= $h($selectedEq['check2_inspector']) ?>"></label>
          <label>2차 스티커 발부
            <select name="check2_sticker">
              <option value="발부" <?= $selectedEq['check2_sticker'] === '발부' ? 'selected' : '' ?>>발부 완료</option>
              <option value="미발부" <?= $selectedEq['check2_sticker'] !== '발부' ? 'selected' : '' ?>>미발부</option>
            </select>
          </label>
        </div>

        <?php if ($canManage): ?>
        <div class="quick-bar">
          <div style="font-size:.85rem;color:#475569"><strong>세부 기기 상태 점검 항목 (<?= count($selectedItems) ?>개)</strong> — 모바일 촬영 사진이 실시간 표시됩니다.</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm" onclick="markAllPcRound(1, '적정')">✅ 1차 전체 적정</button>
            <button type="button" class="btn btn-sm" onclick="markAllPcRound(2, '적정')">✅ 2차 전체 적정</button>
            <button type="submit" class="btn btn-sm btn-primary">💾 점검표 저장</button>
          </div>
        </div>
        <?php endif; ?>

        <div class="tbl-wrap">
          <table class="hm-tbl">
            <thead>
              <tr>
                <th style="width:46px">순번</th>
                <th style="width:110px">점검부위</th>
                <th><?= $h($selectedEq['name']) ?> 점검내용</th>
                <th style="width:155px">1차 점검결과</th>
                <th style="width:92px">1차 사진</th>
                <th style="width:155px">2차 점검결과</th>
                <th style="width:92px">2차 사진</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($selectedItems as $it):
                $iid = (int)$it['id'];
                $p1 = (string)($it['round1_photo'] ?? '');
                $p2 = (string)($it['round2_photo'] ?? '');
              ?>
              <tr>
                <td><?= (int)$it['item_no'] ?></td>
                <td><span class="badge b-gray"><?= $h($it['category'] ?: '-') ?></span></td>
                <td class="left"><?= $h($it['content']) ?></td>
                <td>
                  <div class="radio-pair">
                    <label><input type="radio" name="r1[<?= $iid ?>]" value="적정" <?= $it['round1_result'] === '적정' ? 'checked' : '' ?>> 적정</label>
                    <label><input type="radio" name="r1[<?= $iid ?>]" value="부적정" <?= $it['round1_result'] === '부적정' ? 'checked' : '' ?>> 부적정</label>
                  </div>
                </td>
                <td id="pc-ph-1-<?= $iid ?>">
                  <?php if ($p1 !== ''): ?>
                    <img class="thumb" src="?check_photo=<?= $h($p1) ?>" alt="1차 사진" onclick="openPhotoModal(this.src, '1차 항목 <?= (int)$it['item_no'] ?> 사진')">
                  <?php elseif ($canManage): ?>
                    <label class="btn btn-sm" style="cursor:pointer">📷<input type="file" accept="image/*" style="display:none" onchange="pcUploadPhoto(<?= $iid ?>, 1, this)"></label>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-size:.76rem">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="radio-pair">
                    <label><input type="radio" name="r2[<?= $iid ?>]" value="적정" <?= $it['round2_result'] === '적정' ? 'checked' : '' ?>> 적정</label>
                    <label><input type="radio" name="r2[<?= $iid ?>]" value="부적정" <?= $it['round2_result'] === '부적정' ? 'checked' : '' ?>> 부적정</label>
                  </div>
                </td>
                <td id="pc-ph-2-<?= $iid ?>">
                  <?php if ($p2 !== ''): ?>
                    <img class="thumb" src="?check_photo=<?= $h($p2) ?>" alt="2차 사진" onclick="openPhotoModal(this.src, '2차 항목 <?= (int)$it['item_no'] ?> 사진')">
                  <?php elseif ($canManage): ?>
                    <label class="btn btn-sm" style="cursor:pointer">📷<input type="file" accept="image/*" style="display:none" onchange="pcUploadPhoto(<?= $iid ?>, 2, this)"></label>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-size:.76rem">-</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:14px">
          <label style="display:grid;gap:6px;font-weight:700;font-size:.88rem">점검결과 주요 조치 및 요구사항
            <textarea name="action_note" rows="3" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit" placeholder="부적정 항목 조치 내용, 케이블 교체, 수리 및 폐기 이력 등을 기록하세요."><?= $h($selectedEq['action_note']) ?></textarea>
          </label>
        </div>

        <?php if ($canManage): ?>
        <div style="margin-top:14px;display:flex;justify-content:flex-end">
          <button type="submit" class="btn btn-primary">💾 안전점검표 저장</button>
        </div>
        <?php endif; ?>
      </form>

      <?php if ($canManage): ?>
      <form method="post" style="margin-top:18px;padding-top:14px;border-top:1px dashed #cbd5e1;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
        <input type="hidden" name="action" value="add_check_item">
        <input type="hidden" name="equipment_id" value="<?= (int)$selectedEq['id'] ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <strong style="font-size:.84rem">+ 점검 항목 추가:</strong>
        <input type="text" name="category" placeholder="점검부위" style="padding:7px 9px;border:1px solid #cbd5e1;border-radius:6px;width:120px">
        <input type="text" name="content" required placeholder="추가할 안전점검 내용을 입력하세요" style="padding:7px 9px;border:1px solid #cbd5e1;border-radius:6px;flex:1;min-width:220px">
        <button type="submit" class="btn btn-sm">항목 추가</button>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <?php elseif ($tab === 'evidence'): ?>
  <section class="panel" style="border-left:5px solid <?= $score262['evidence_count'] > 0 ? '#176053' : '#d97706' ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div>
        <h2 style="margin:0 0 6px">🏆 CSM 2.6.2 평가 점수 반영 현황 (<?= $year ?>년)</h2>
        <p style="margin:0;color:#475569;font-size:.9rem">
          <?php if ($score262['evidence_count'] > 0): ?>
            ✅ 유효 증빙 <strong><?= (int)$score262['evidence_count'] ?>건</strong>이 확인되어 CSM 2.6.2 점수에 <strong><?= number_format((float)$score262['earned'], 1) ?> / 2.0점</strong>이 반영 중입니다.
          <?php else: ?>
            ⚠️ 현재 첨부된 증빙 파일이 없어 <strong>0.0점</strong>입니다. 결재 완료된 관리대장·점검표 스캔본 또는 점검필증(스티커) 부착 사진을 첨부하면 즉시 점수에 반영됩니다.
          <?php endif; ?>
        </p>
      </div>
      <div style="font-size:1.6rem;font-weight:800;color:<?= $score262['evidence_count'] > 0 ? '#176053' : '#b45309' ?>"><?= number_format((float)$score262['earned'], 1) ?> / 2.0점</div>
    </div>

    <?php if ($canManage): ?>
    <form method="post" style="display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.6fr) auto;gap:10px;align-items:end;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
      <input type="hidden" name="action" value="assess_262">
      <input type="hidden" name="year" value="<?= $year ?>">
      <label style="display:grid;gap:4px;font-size:.84rem;font-weight:700">평가 수준 (증빙 첨부 시 반영)
        <select name="level" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px">
          <?php $curMult = $score262['assessment'] ? (float)$score262['assessment']['multiplier'] : ((float)$score262['multiplier'] ?: 1.0); ?>
          <option value="1" <?= abs($curMult - 1.0) < 0.001 ? 'selected' : '' ?>>모든 대상 관리 중 (100% · 2.0점)</option>
          <option value="0.9" <?= abs($curMult - 0.9) < 0.001 ? 'selected' : '' ?>>대부분 관리 중 (90% · 1.8점)</option>
          <option value="0.8" <?= abs($curMult - 0.8) < 0.001 ? 'selected' : '' ?>>일부 관리 중 (80% · 1.6점)</option>
          <option value="0.5" <?= abs($curMult - 0.5) < 0.001 ? 'selected' : '' ?>>미흡함 (50% · 1.0점)</option>
          <option value="0" <?= abs($curMult - 0.0) < 0.001 ? 'selected' : '' ?>>없음 (0% · 0.0점)</option>
        </select>
      </label>
      <label style="display:grid;gap:4px;font-size:.84rem;font-weight:700">검토 메모 (선택)
        <input type="text" name="note" value="<?= $h($score262['assessment']['note'] ?? '') ?>" placeholder="예: 휴대용 공구·기구 41종 관리대장 및 점검필증 부착 완료" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px">
      </label>
      <button type="submit" class="btn btn-primary">평가 수준 저장</button>
    </form>
    <?php endif; ?>
  </section>

  <?php if ($canManage): ?>
  <section class="panel">
    <h2 style="margin:0 0 12px">📎 2.6.2 증빙 파일 첨부</h2>
    <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.5fr) auto;gap:12px;align-items:end">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
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
      <p style="color:#64748b">등록된 증빙 파일이 없습니다. 상단의 <strong>관리대장 A4 인쇄</strong>로 출력·결재 후 스캔본을 업로드하세요.</p>
    <?php else: ?>
      <table class="hm-tbl">
        <thead><tr><th>구분</th><th>파일명</th><th>크기</th><th>등록일시</th><th>관리</th></tr></thead>
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
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
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

<!-- Add/Edit Modal -->
<div class="modal-backdrop" id="eqModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="eqModalTitle" style="margin:0">휴대용 공구·기구 등록</h2>
      <button type="button" class="btn btn-sm" onclick="document.getElementById('eqModal').classList.remove('open')">닫기</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_pt_csrf']) ?>">
      <input type="hidden" name="action" value="save_equipment">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="form-grid">
        <label>순번 <input type="number" name="seq_no" id="f_seq_no" min="1"></label>
        <label>관리번호 * <input type="text" name="code" id="f_code" required placeholder="예: E-HD-42"></label>
        <label>기기명 * <input type="text" name="name" id="f_name" required placeholder="예: 그라인더-7 4&quot;"></label>
        <label>규격 <input type="text" name="spec" id="f_spec" placeholder="예: 710W, 3구"></label>
        <label>정격전압(V) <input type="text" name="voltage" id="f_voltage" value="220"></label>
        <label>정격전류(A) <input type="text" name="current_amp" id="f_current_amp" placeholder="예: 3.1, 15A"></label>
        <label>케이블 연장(m) <input type="text" name="cable_len" id="f_cable_len" placeholder="예: 2, 15"></label>
        <label>소켓/플러그 <input type="text" name="socket_type" id="f_socket_type" value="접지형"></label>
        <label>옥내/옥외형
          <select name="indoor_outdoor" id="f_indoor_outdoor">
            <option value="옥내·외">옥내·외</option>
            <option value="옥내">옥내</option>
            <option value="옥외">옥외</option>
          </select>
        </label>
        <label>수량 <input type="number" name="qty" id="f_qty" value="1" min="1"></label>
        <label>이중절연마크 <input type="text" name="double_insulated" id="f_double_insulated" value="확인"></label>
        <label>장비 상태
          <select name="status" id="f_status">
            <option value="사용 가능">사용 가능</option>
            <option value="수리 중">수리 중</option>
            <option value="사용 중지">사용 중지</option>
            <option value="폐기">폐기</option>
          </select>
        </label>
        <label class="wide">공구 실물 사진 (<strong>스크린샷 Ctrl+V 붙여넣기</strong> · 파일 선택 · 드래그 지원)
          <input type="hidden" name="photo_base64" id="f_photo_base64" value="">
          <div id="photoPasteZone" tabindex="0" style="border:2px dashed #0f766e;border-radius:10px;padding:12px;background:#f0fdfa;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <img id="photoPreview" src="" alt="" style="display:none;width:80px;height:62px;object-fit:cover;border-radius:7px;border:1px solid #cbd5e1;background:#fff">
            <div style="flex:1;min-width:180px">
              <div id="photoPasteText" style="font-size:.86rem;font-weight:700;color:#0f4c43">📋 스크린샷(Win+Shift+S) 복사 후 여기서 <strong>Ctrl+V</strong>를 누르세요</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                <button type="button" class="btn btn-sm btn-primary" onclick="pasteFromClipboardBtn()">📋 스크린샷 바로 붙여넣기</button>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('f_photo').click()">📁 파일 선택</button>
              </div>
            </div>
            <input type="file" name="photo" id="f_photo" accept=".jpg,.jpeg,.png" style="display:none">
          </div>
        </label>
        <label class="wide">비고 <textarea name="remarks" id="f_remarks" rows="2"></textarea></label>
      </div>
      <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="btn" onclick="document.getElementById('eqModal').classList.remove('open')">취소</button>
        <button type="submit" class="btn btn-primary">저장</button>
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
const PT_CSRF = <?= json_encode($_SESSION['csm_pt_csrf']) ?>;
const PT_EQ_ID = <?= (int)($selectedEq['id'] ?? 0) ?>;

function openPhotoModal(src, title) {
  document.getElementById('photoModalTitle').textContent = title;
  document.getElementById('photoModalImg').src = src;
  document.getElementById('photoModal').classList.add('open');
}
function openEqModal(eq) {
  const isEdit = !!eq;
  document.getElementById('eqModalTitle').textContent = isEdit ? ('공구 수정 · ' + eq.code) : '휴대용 공구·기구 신규 등록';
  document.getElementById('f_id').value = isEdit ? eq.id : 0;
  document.getElementById('f_seq_no').value = isEdit ? eq.seq_no : '';
  document.getElementById('f_code').value = isEdit ? eq.code : '';
  document.getElementById('f_name').value = isEdit ? eq.name : '';
  document.getElementById('f_spec').value = isEdit ? eq.spec : '';
  document.getElementById('f_voltage').value = isEdit ? eq.voltage : '220';
  document.getElementById('f_current_amp').value = isEdit ? eq.current_amp : '';
  document.getElementById('f_cable_len').value = isEdit ? eq.cable_len : '';
  document.getElementById('f_socket_type').value = isEdit ? eq.socket_type : '접지형';
  document.getElementById('f_indoor_outdoor').value = isEdit ? eq.indoor_outdoor : '옥내·외';
  document.getElementById('f_qty').value = isEdit ? eq.qty : 1;
  document.getElementById('f_double_insulated').value = isEdit ? eq.double_insulated : '확인';
  document.getElementById('f_status').value = isEdit ? eq.status : '사용 가능';
  document.getElementById('f_remarks').value = isEdit ? eq.remarks : '';
  document.getElementById('f_photo').value = '';
  document.getElementById('f_photo_base64').value = '';
  const prev = document.getElementById('photoPreview');
  const txt = document.getElementById('photoPasteText');
  if (isEdit && eq.photo_file) {
    prev.src = '?photo=' + encodeURIComponent(eq.photo_file);
    prev.style.display = 'block';
    txt.innerHTML = '현재 사진 등록됨 · 스크린샷 <strong>Ctrl+V</strong> 또는 버튼으로 교체';
  } else {
    prev.src = '';
    prev.style.display = 'none';
    txt.innerHTML = '📋 스크린샷(Win+Shift+S) 복사 후 여기서 <strong>Ctrl+V</strong>를 누르세요';
  }
  document.getElementById('eqModal').classList.add('open');
  setTimeout(() => { document.getElementById('photoPasteZone').focus(); }, 50);
}
function applyPhotoBlob(blob, label) {
  if (!blob) return;
  const reader = new FileReader();
  reader.onload = function (ev) {
    const dataUrl = String(ev.target.result || '');
    if (!dataUrl.startsWith('data:image/')) return;
    document.getElementById('f_photo_base64').value = dataUrl;
    document.getElementById('f_photo').value = '';
    const prev = document.getElementById('photoPreview');
    prev.src = dataUrl;
    prev.style.display = 'block';
    document.getElementById('photoPasteText').innerHTML = '✅ <strong>' + label + '</strong> 붙여넣기 완료! 아래 [저장]을 누르세요';
  };
  reader.readAsDataURL(blob);
}
async function pasteFromClipboardBtn() {
  try {
    if (!navigator.clipboard || !navigator.clipboard.read) {
      alert('이 브라우저에서는 버튼 읽기가 제한됩니다. 이 박스를 클릭한 뒤 Ctrl+V를 눌러주세요.');
      return;
    }
    const items = await navigator.clipboard.read();
    for (const item of items) {
      const imgType = item.types.find(t => t.startsWith('image/'));
      if (imgType) {
        const blob = await item.getType(imgType);
        applyPhotoBlob(blob, '클립보드 스크린샷');
        return;
      }
    }
    alert('클립보드에 복사된 스크린샷/이미지가 없습니다. Win+Shift+S로 먼저 캡처해 주세요.');
  } catch (err) {
    alert('클립보드 접근이 차단되었습니다. 이 점선 박스를 클릭하고 Ctrl+V를 눌러주세요.');
  }
}
document.getElementById('f_photo').addEventListener('change', function () {
  if (this.files && this.files[0]) applyPhotoBlob(this.files[0], this.files[0].name);
});
document.addEventListener('paste', function (e) {
  if (!document.getElementById('eqModal').classList.contains('open')) return;
  const cd = e.clipboardData || window.clipboardData;
  if (!cd) return;
  if (cd.files && cd.files.length) {
    for (const f of cd.files) {
      if (f.type && f.type.startsWith('image/')) {
        e.preventDefault();
        applyPhotoBlob(f, '스크린샷');
        return;
      }
    }
  }
  if (cd.items && cd.items.length) {
    for (const it of cd.items) {
      if (it.type && it.type.startsWith('image/')) {
        e.preventDefault();
        applyPhotoBlob(it.getAsFile(), '스크린샷');
        return;
      }
    }
  }
});
const pz = document.getElementById('photoPasteZone');
pz.addEventListener('dragover', e => { e.preventDefault(); pz.style.borderColor = '#1e3a8a'; pz.style.background = '#eff6ff'; });
pz.addEventListener('dragleave', () => { pz.style.borderColor = '#0f766e'; pz.style.background = '#f0fdfa'; });
pz.addEventListener('drop', e => {
  e.preventDefault();
  pz.style.borderColor = '#0f766e'; pz.style.background = '#f0fdfa';
  if (e.dataTransfer.files && e.dataTransfer.files[0]) applyPhotoBlob(e.dataTransfer.files[0], e.dataTransfer.files[0].name);
});
function markAllPcRound(roundNo, val) {
  document.querySelectorAll('input[type="radio"][name^="r' + roundNo + '["][value="' + val + '"]').forEach(el => { el.checked = true; });
}
function compressImage(file, maxSide = 1280, quality = 0.82) {
  return new Promise(resolve => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      let w = img.naturalWidth, h = img.naturalHeight;
      if (w > maxSide || h > maxSide) {
        if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
        else { w = Math.round(w * maxSide / h); h = maxSide; }
      }
      const cv = document.createElement('canvas');
      cv.width = w; cv.height = h;
      cv.getContext('2d').drawImage(img, 0, 0, w, h);
      cv.toBlob(b => resolve(b ? new File([b], 'chk.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });
}
async function pcUploadPhoto(itemId, roundNo, input) {
  const rawFile = input.files && input.files[0];
  input.value = '';
  if (!rawFile) return;
  const cell = document.getElementById(`pc-ph-${roundNo}-${itemId}`);
  if (cell) cell.textContent = '업로드중...';
  try {
    const compressed = await compressImage(rawFile);
    const fd = new FormData();
    fd.append('csrf', PT_CSRF);
    fd.append('action', 'ajax_upload_check_photo');
    fd.append('equipment_id', String(PT_EQ_ID));
    fd.append('item_id', String(itemId));
    fd.append('round', String(roundNo));
    fd.append('photo', compressed);
    const res = await fetch('portable_tools.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || '업로드 실패');
    if (cell) cell.innerHTML = `<img class="thumb" src="${data.url}" alt="점검사진" onclick="openPhotoModal('${data.url}', '${roundNo}차 점검 사진')">`;
  } catch (e) {
    alert(e.message || '사진 업로드 오류');
    location.reload();
  }
}
</script>
</body>
</html>
