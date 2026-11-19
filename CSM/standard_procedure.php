<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/standard_procedure_store.php';

$user = csm_require_auth();
$db = sop_db();

// 1. Direct DB image streaming endpoint (?img_id=<id>)
if (isset($_GET['img_id'])) {
    $imgId = filter_var($_GET['img_id'], FILTER_VALIDATE_INT) ?: 0;
    if ($imgId <= 0) {
        http_response_code(404);
        exit;
    }
    $stmt = $db->prepare('SELECT mime, sha256, data FROM csm_sop_images WHERE id = ?');
    $stmt->execute([$imgId]);
    $imgRow = $stmt->fetch();
    if (!$imgRow || empty($imgRow['data'])) {
        http_response_code(404);
        exit;
    }
    $etag = '"' . ($imgRow['sha256'] ?: md5((string)$imgId)) . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . ($imgRow['mime'] ?: 'image/png'));
    header('Cache-Control: private, max-age=604800');
    header('ETag: ' . $etag);
    header('Content-Length: ' . strlen((string)$imgRow['data']));
    echo $imgRow['data'];
    exit;
}

// 2. Backup download endpoint (?download_backup=<file_name>)
if (isset($_GET['download_backup'])) {
    if (!auth_can_manage($user)) {
        http_response_code(403);
        exit('다운로드 권한이 없습니다.');
    }
    $fileName = basename((string)$_GET['download_backup']);
    $filePath = __DIR__ . '/storage/backups/' . $fileName;
    if ($fileName === '' || !preg_match('/^standard_procedure_[0-9A-Za-z_.-]+\.sqlite$/', $fileName) || !is_file($filePath)) {
        http_response_code(404);
        exit('백업 파일을 찾을 수 없습니다.');
    }
    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string)filesize($filePath));
    readfile($filePath);
    exit;
}

header('Cache-Control: no-store');
$_SESSION['csm_standard_procedure_csrf'] ??= bin2hex(random_bytes(32));

// Daily automatic DB backup snapshot
sop_backup_daily();

$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year = filter_var($_GET['year'] ?? 2026, FILTER_VALIDATE_INT) ?: 2026;
$tab = $_GET['tab'] ?? 'list';
if (!in_array($tab, ['list', 'revisions', 'distribution', 'backups'], true)) {
    $tab = 'list';
}
$volume = trim((string)($_GET['volume'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'active')); // active, all, recent, deprecated
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (($_GET['show'] ?? '') === 'all') ? 500 : 50;

$message = '';
$error = '';

// Logo base64 for A4 print
$logoPath = __DIR__ . '/assets/sop-word-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = __DIR__ . '/assets/policy-logo.png';
}
if (!file_exists($logoPath)) {
    $logoPath = dirname(__DIR__) . '/safety_manual/assets/policy-logo.png';
}
$logoBase64 = file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath))) : '';

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($tab === 'revisions') {
        header('Content-Disposition: attachment; filename="CSM_SOP_revisions_' . $year . '.csv"');
        fputcsv($out, ['개정일', 'No.', '권호', '문서번호', '작업표준명', '개정차수', '개정사유', '주요변경내용', '검토자', '승인자']);
        $stmt = $db->prepare('SELECT r.*, m.sop_no, m.volume, m.job_plan_code, m.doc_code, m.title FROM csm_sop_revisions r JOIN csm_sop_master m ON m.id = r.sop_id WHERE m.year = ? ORDER BY r.revision_date DESC, m.sop_no ASC');
        $stmt->execute([$year]);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['revision_date'], $row['sop_no'], $row['volume'],
                $row['job_plan_code'] ?: $row['doc_code'], $row['title'],
                'Rev.' . $row['revision_no'], $row['reason'], $row['changes_summary'],
                $row['reviewer_name'], $row['approver_name']
            ]);
        }
    } else {
        header('Content-Disposition: attachment; filename="CSM_standard_procedures_' . $year . '.csv"');
        fputcsv($out, ['No.', '권호', '문서번호', '작업분류', '작업표준명', '상태', '개정차수', '제정일', '최종개정일', '관리이력', '작성자', '승인자']);
        $stmt = $db->prepare('SELECT * FROM csm_sop_master WHERE year = ? ORDER BY sop_no ASC, id ASC');
        $stmt->execute([$year]);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['sop_no'], $row['volume'], $row['job_plan_code'] ?: $row['doc_code'],
                $row['category'], $row['title'], $row['status'] ?: '유효',
                'Rev.' . $row['revision_no'], $row['enact_date'], $row['revised_date'],
                $row['change_reason'], $row['author_name'], $row['approver_name']
            ]);
        }
    }
    fclose($out);
    exit;
}

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!auth_can_manage($user)) {
        http_response_code(403);
        exit('변경 권한이 없습니다.');
    }
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_standard_procedure_csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('요청을 확인할 수 없습니다.');
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_sop') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $sop_no = filter_var($_POST['sop_no'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $vol = trim((string)($_POST['volume'] ?? '06권'));
            $job_plan_code = trim((string)($_POST['job_plan_code'] ?? ''));
            $doc_code = trim((string)($_POST['doc_code'] ?? ''));
            $cat = trim((string)($_POST['category'] ?? '고압·수변전·전기'));
            $title = trim((string)($_POST['title'] ?? ''));
            $scope = trim((string)($_POST['scope'] ?? ''));
            $danger_factors = trim((string)($_POST['danger_factors'] ?? ''));
            $ppe_required = trim((string)($_POST['ppe_required'] ?? ''));
            $pre_work_safety = trim((string)($_POST['pre_work_safety'] ?? ''));
            $in_work_safety = trim((string)($_POST['in_work_safety'] ?? ''));
            $post_work_safety = trim((string)($_POST['post_work_safety'] ?? ''));
            $emergency_action = trim((string)($_POST['emergency_action'] ?? ''));
            $enact_date = trim((string)($_POST['enact_date'] ?? $today));
            $revised_date = trim((string)($_POST['revised_date'] ?? $today));
            $revision_no = max(0, (int)($_POST['revision_no'] ?? 0));
            $status = (($_POST['status'] ?? '유효') === '폐지') ? '폐지' : '유효';
            $is_active = ($status === '유효') ? 1 : 0;
            $change_reason = trim((string)($_POST['change_reason'] ?? ''));
            $author_name = trim((string)($_POST['author_name'] ?? '공사팀 김남균'));
            $approver_name = trim((string)($_POST['approver_name'] ?? '정연탁 대표'));

            if ($title === '') {
                throw new InvalidArgumentException('표준서 작업명은 필수입니다.');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE csm_sop_master SET
                    sop_no = ?, volume = ?, job_plan_code = ?, doc_code = ?, category = ?, title = ?, scope = ?,
                    danger_factors = ?, ppe_required = ?, pre_work_safety = ?, in_work_safety = ?, post_work_safety = ?,
                    emergency_action = ?, enact_date = ?, revised_date = ?, revision_no = ?, status = ?, is_active = ?,
                    change_reason = ?, author_name = ?, approver_name = ?, updated_at = datetime("now","localtime")
                    WHERE id = ?');
                $stmt->execute([
                    $sop_no, $vol, $job_plan_code, $doc_code, $cat, $title, $scope,
                    $danger_factors, $ppe_required, $pre_work_safety, $in_work_safety, $post_work_safety,
                    $emergency_action, $enact_date, $revised_date, $revision_no, $status, $is_active,
                    $change_reason, $author_name, $approver_name, $id
                ]);
                sop_backup_db('change', '표준서 수정: No.' . $sop_no . ' ' . $title);
                $message = '안전작업표준서가 수정되었으며 DB 백업 스냅샷이 자동 생성되었습니다.';
            } else {
                if ($sop_no <= 0) {
                    $sop_no = (int)$db->query('SELECT COALESCE(MAX(sop_no), 0) + 1 FROM csm_sop_master')->fetchColumn();
                }
                if ($doc_code === '') {
                    $doc_code = sprintf('SOP-%03d', $sop_no) . ($job_plan_code !== '' ? '-' . $job_plan_code : '');
                }
                if ($scope === '') {
                    $scope = $vol . ' · ' . $sop_no . '. ' . $title;
                }
                $stmt = $db->prepare('INSERT INTO csm_sop_master (
                    year, sop_no, volume, job_plan_code, doc_code, category, title, scope, danger_factors, ppe_required,
                    pre_work_safety, in_work_safety, post_work_safety, emergency_action,
                    enact_date, revised_date, revision_no, status, is_active, change_reason, sheet_name,
                    author_name, approver_name, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, datetime("now","localtime"), datetime("now","localtime")
                )');
                $stmt->execute([
                    $year, $sop_no, $vol, $job_plan_code, $doc_code, $cat, $title, $scope, $danger_factors, $ppe_required,
                    $pre_work_safety, $in_work_safety, $post_work_safety, $emergency_action,
                    $enact_date, $revised_date, $revision_no, $status, $is_active, $change_reason ?: '신규등록',
                    $sop_no . '. ' . $title, $author_name, $approver_name
                ]);
                $newSopId = (int)$db->lastInsertId();
                $db->prepare('INSERT INTO csm_sop_revisions (
                    sop_id, revision_no, revision_date, reason, changes_summary, reviewer_name, approver_name, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now","localtime"))')->execute([
                    $newSopId, $revision_no, $enact_date, '[신규] 사내 안전작업표준서 신규 제정',
                    $change_reason ?: ($title . ' 신규 등록'), $author_name, $approver_name
                ]);
                sop_backup_db('change', '표준서 신규제정: No.' . $sop_no . ' ' . $title);
                $message = '새로운 안전작업표준서(No.' . $sop_no . ')가 제정·등록되었으며 DB가 자동 백업되었습니다.';
            }
        } elseif ($action === 'add_revision') {
            $sop_id = filter_var($_POST['sop_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $rev_no = max(0, (int)($_POST['revision_no'] ?? 1));
            $rev_date = trim((string)($_POST['revision_date'] ?? $today));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $changes = trim((string)($_POST['changes_summary'] ?? ''));
            $reviewer = trim((string)($_POST['reviewer_name'] ?? '공사팀 김남균'));
            $approver = trim((string)($_POST['approver_name'] ?? '정연탁 대표'));
            $new_status = (($_POST['new_status'] ?? '유효') === '폐지') ? '폐지' : '유효';

            $db->prepare('INSERT INTO csm_sop_revisions (
                sop_id, revision_no, revision_date, reason, changes_summary, reviewer_name, approver_name, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now","localtime"))')->execute([
                $sop_id, $rev_no, $rev_date, $reason, $changes, $reviewer, $approver
            ]);

            $db->prepare('UPDATE csm_sop_master SET revision_no = ?, revised_date = ?, status = ?, is_active = ?, change_reason = ?, updated_at = datetime("now","localtime") WHERE id = ?')
               ->execute([$rev_no, $rev_date, $new_status, $new_status === '유효' ? 1 : 0, $reason, $sop_id]);

            sop_backup_db('change', '개정이력 등록: SOP #' . $sop_id . ' Rev.' . $rev_no);
            $message = '제·개정 이력이 등록되었으며 DB 백업이 완료되었습니다 (Rev.' . $rev_no . ').';
        } elseif ($action === 'add_dist') {
            $sop_id = filter_var($_POST['sop_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $dist_date = trim((string)($_POST['dist_date'] ?? $today));
            $target_group = trim((string)($_POST['target_group'] ?? ''));
            $dist_method = trim((string)($_POST['dist_method'] ?? 'TBM 교육 및 현장 표준서 배포'));
            $confirmed_count = max(0, (int)($_POST['confirmed_count'] ?? 0));
            $distributor_name = trim((string)($_POST['distributor_name'] ?? '공사팀 김남균'));

            if ($sop_id <= 0 || $target_group === '') {
                throw new InvalidArgumentException('대상 표준서와 배포 대상을 입력해주세요.');
            }
            $db->prepare('INSERT INTO csm_sop_distribution (
                sop_id, dist_date, target_group, dist_method, confirmed_count, distributor_name, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, datetime("now","localtime"))')->execute([
                $sop_id, $dist_date, $target_group, $dist_method, $confirmed_count, $distributor_name
            ]);
            $message = '근로자 배포 및 열람 확인 이력이 기록되었습니다.';
        } elseif ($action === 'delete_sop') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($id > 0) {
                $db->prepare('DELETE FROM csm_sop_master WHERE id = ?')->execute([$id]);
                sop_backup_db('change', '표준서 삭제: ID #' . $id);
                $message = '작업표준서가 삭제되었으며 DB 백업이 갱신되었습니다.';
            }
        } elseif ($action === 'create_backup') {
            $note = trim((string)($_POST['backup_note'] ?? '관리자 수동 백업'));
            $res = sop_backup_db('manual', $note ?: '관리자 수동 백업');
            if (!empty($res['ok'])) {
                $message = 'DB 전체 백업(본문 252종 + 내장 이미지 BLOB 포함)이 생성되었습니다: ' . ($res['file'] ?? '');
            } else {
                $error = '백업 생성 실패: ' . ($res['error'] ?? '알 수 없는 오류');
            }
            $tab = 'backups';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Compute KPI Metrics
$totalAll = (int)$db->query("SELECT COUNT(*) FROM csm_sop_master WHERE year = $year")->fetchColumn();
$totalActive = (int)$db->query("SELECT COUNT(*) FROM csm_sop_master WHERE year = $year AND status != '폐지'")->fetchColumn();
$totalDeprecated = $totalAll - $totalActive;
$revCount = (int)$db->query("SELECT COUNT(*) FROM csm_sop_revisions r JOIN csm_sop_master m ON m.id = r.sop_id WHERE m.year = $year")->fetchColumn();
$distConfirmed = (int)$db->query("SELECT COALESCE(SUM(confirmed_count), 0) FROM csm_sop_distribution d JOIN csm_sop_master m ON m.id = d.sop_id WHERE m.year = $year")->fetchColumn();
$distRowsCount = (int)$db->query("SELECT COUNT(*) FROM csm_sop_distribution d JOIN csm_sop_master m ON m.id = d.sop_id WHERE m.year = $year")->fetchColumn();
$totalDbImages = (int)$db->query("SELECT COUNT(*) FROM csm_sop_images")->fetchColumn();
$bodyDbImages = (int)$db->query("SELECT COUNT(*) FROM csm_sop_images WHERE role = 'body'")->fetchColumn();
$backupCount = (int)$db->query("SELECT COUNT(*) FROM csm_sop_backup_log")->fetchColumn();

// Categories & Volumes
$categories = ['고압·수변전·전기', '전동기(Motor)', '계장·제어', '케이블·배관', '기계·설비정비', '고소·철탑·양중', '화기·용접', '일반안전'];
$volumes = ['01권', '02권', '03권', '04권', '05권', '06권'];

// Revision map per sop_id for quick badge & detail display
$revsBySop = [];
$revAllStmt = $db->prepare('SELECT r.*, m.sop_no, m.volume, m.job_plan_code, m.doc_code, m.title, m.status AS sop_status
    FROM csm_sop_revisions r
    JOIN csm_sop_master m ON m.id = r.sop_id
    WHERE m.year = ?
    ORDER BY r.revision_date DESC, m.sop_no ASC, r.id DESC');
$revAllStmt->execute([$year]);
$allRevisions = $revAllStmt->fetchAll();
foreach ($allRevisions as $rv) {
    $revsBySop[(int)$rv['sop_id']][] = $rv;
}

// Body image metadata map per sop_id (without heavy BLOB data)
$imagesBySop = [];
$imgMetaStmt = $db->query("SELECT id, sop_id, role, seq, side, from_row, from_col, to_row, to_col, width_emu, height_emu, px_w, px_h, orig_name
    FROM csm_sop_images
    WHERE role = 'body'
    ORDER BY sop_id ASC, from_row ASC, seq ASC");
foreach ($imgMetaStmt->fetchAll() as $im) {
    $imagesBySop[(int)$im['sop_id']][] = [
        'id' => (int)$im['id'],
        'seq' => (int)$im['seq'],
        'side' => (string)$im['side'],
        'from_row' => (int)$im['from_row'],
        'from_col' => (int)$im['from_col'],
        'to_row' => (int)$im['to_row'],
        'to_col' => (int)$im['to_col'],
        'w_emu' => (int)$im['width_emu'],
        'h_emu' => (int)$im['height_emu'],
        'px_w' => (int)$im['px_w'],
        'px_h' => (int)$im['px_h'],
    ];
}

// Fetch filtered SOP list
$where = ['year = ?'];
$params = [$year];
if ($volume !== '') {
    $where[] = 'volume = ?';
    $params[] = $volume;
}
if ($category !== '') {
    $where[] = 'category = ?';
    $params[] = $category;
}
if ($statusFilter === 'active') {
    $where[] = "status != '폐지'";
} elseif ($statusFilter === 'deprecated') {
    $where[] = "status = '폐지'";
} elseif ($statusFilter === 'recent') {
    $where[] = "id IN (SELECT sop_id FROM csm_sop_revisions)";
}
if ($search !== '') {
    $where[] = '(title LIKE ? OR doc_code LIKE ? OR job_plan_code LIKE ? OR CAST(sop_no AS TEXT) = ? OR pre_work_safety LIKE ? OR in_work_safety LIKE ? OR post_work_safety LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $search, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$cntStmt = $db->prepare("SELECT COUNT(*) FROM csm_sop_master WHERE $whereSql");
$cntStmt->execute($params);
$filteredTotal = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare("SELECT * FROM csm_sop_master WHERE $whereSql ORDER BY sop_no ASC, id ASC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$sops = $listStmt->fetchAll();

// Fetch distributions for distribution tab
$distStmt = $db->prepare('SELECT d.*, m.sop_no, m.volume, m.job_plan_code, m.doc_code, m.title
    FROM csm_sop_distribution d
    JOIN csm_sop_master m ON m.id = d.sop_id
    WHERE m.year = ?
    ORDER BY d.dist_date DESC, d.id DESC');
$distStmt->execute([$year]);
$distributions = $distStmt->fetchAll();

// Fetch backup logs for backups tab
$backupLogs = $db->query('SELECT * FROM csm_sop_backup_log ORDER BY id DESC LIMIT 50')->fetchAll();

// Light list for distribution modal dropdown
$sopOptionsStmt = $db->prepare("SELECT id, sop_no, volume, job_plan_code, title FROM csm_sop_master WHERE year = ? AND status != '폐지' ORDER BY sop_no ASC");
$sopOptionsStmt->execute([$year]);
$sopOptions = $sopOptionsStmt->fetchAll();

$queryBase = http_build_query(array_filter([
    'year' => $year,
    'tab' => $tab,
    'volume' => $volume,
    'category' => $category,
    'status' => $statusFilter,
    'search' => $search,
], static fn($v) => $v !== ''));
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>안전작업절차서·표준서 관리 · CSM 2.2</title>
  <link rel="stylesheet" href="standard_procedure.css">
  <style>
    .sop-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 16px;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 10px;
      flex-wrap: wrap;
    }
    .sop-tab {
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 700;
      text-decoration: none;
      color: #475569;
      background: #f1f5f9;
      border: 1px solid #cbd5e1;
      transition: all 0.14s ease;
    }
    .sop-tab:hover { background: #e2e8f0; color: #0f172a; }
    .sop-tab.active {
      background: #1e3a8a;
      color: #ffffff;
      border-color: #1e3a8a;
    }
    .sop-row-click { cursor: pointer; transition: background-color 0.12s ease; }
    .sop-row-click:hover { background-color: #f0f7ff !important; }
    .sop-row-deprecated { background-color: #fef2f2 !important; opacity: 0.78; }
    .sop-badge-new { background: #dcfce7; color: #166534; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 800; }
    .sop-badge-upd { background: #fef3c7; color: #92400e; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 800; }
    .sop-badge-del { background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 800; }
    .sop-badge-img { background: #e0f2fe; color: #0369a1; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 800; }
    .sop-badge-vol { background: #e0e7ff; color: #1e40af; padding: 2px 7px; border-radius: 6px; font-size: 11px; font-weight: 700; }
    .sop-pagination {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 16px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      flex-wrap: wrap;
      gap: 10px;
      font-size: 13px;
    }
    .sop-page-links { display: flex; gap: 6px; flex-wrap: wrap; }
    .sop-page-btn {
      padding: 5px 11px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #334155;
      text-decoration: none;
      font-weight: 700;
      font-size: 12.5px;
    }
    .sop-page-btn.active { background: #1e3a8a; color: #fff; border-color: #1e3a8a; }

    /* Option B (기존 엑셀 PDF 서식 1:1) Preview & Print Shared Styles */
    .sop-b-sheet {
      font-family: "Malgun Gothic", "맑은 고딕", Dotum, sans-serif;
      color: #000;
      background: #fff;
      width: 100%;
    }
    .sop-b-head {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      border: 2pt solid #000;
      margin-bottom: 4px;
    }
    .sop-b-head td {
      border: 0.6pt solid #000;
      padding: 3px 5px;
      font-size: 12px;
      line-height: 1.25;
      vertical-align: middle;
      color: #000;
    }
    .sop-b-title-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      padding-left: 4px;
    }
    .sop-b-title-text {
      font-size: 18px;
      font-weight: 800;
      letter-spacing: 3px;
      white-space: nowrap;
    }
    .sop-b-body {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      border: 2pt solid #000;
    }
    .sop-b-body td {
      vertical-align: top;
      font-size: 11.5px;
      line-height: 1.38;
      padding: 1px 6px;
      color: #000;
      word-break: keep-all;
      overflow-wrap: break-word;
    }
    .sop-b-vbar {
      background-color: #CCFFCC !important;
      border-right: 0.7pt solid #000;
      border-left: 1.3pt solid #000;
      width: 26px;
      text-align: center;
      vertical-align: middle !important;
      font-weight: 800;
      font-size: 11.5px;
      line-height: 1.35;
      padding: 6px 2px !important;
      user-select: none;
    }
    .sop-b-vbar-left {
      border-left: none !important;
    }
    .sop-b-divider-row td {
      border-top: 1.6pt solid #000 !important;
    }
    .sop-b-cell-left {
      border-right: 1.3pt solid #000;
    }
    .sop-b-img-box {
      margin: 4px 0;
      text-align: center;
    }
    .sop-b-img-box img {
      max-width: 94%;
      height: auto;
      display: inline-block;
    }
    .sop-b-footer-sh {
      text-align: center;
      font-size: 11.5px;
      font-weight: 600;
      margin-top: 2px;
      color: #000;
    }
  </style>
</head>
<body>

<div class="sop-container">
  <!-- Header -->
  <div class="sop-header">
    <div class="sop-title-group">
      <h1>
        <a href="area.php?group=2&amp;section=2.2" style="color:inherit;text-decoration:none;">← 2.2</a>
        안전작업절차서·작업표준서(SOP) 관리
        <span class="sop-badge-eval">배점 2점 · 공사팀 최종본(01~06권)</span>
      </h1>
      <p class="sop-subtitle">현대기전 공사팀 안전작업표준서 <?= number_format($totalActive) ?>종(유효)의 절차 본문, 내장 도해·사진(<?= number_format($bodyDbImages) ?>건 DB 내장), 제·개정 이력 및 DB 백업을 통합 관리합니다.</p>
    </div>
    <div class="sop-actions no-print">
      <a href="?<?= $escape($queryBase) ?>&amp;export=csv" class="sop-btn sop-btn-outline">📊 Excel/CSV 내보내기</a>
      <a href="?year=<?= $year ?>&amp;tab=backups" class="sop-btn sop-btn-outline">💾 DB 백업 (<?= $backupCount ?>)</a>
      <button type="button" class="sop-btn sop-btn-outline" onclick="openDistModal(0)">📢 배포 기록</button>
      <button type="button" class="sop-btn sop-btn-primary" onclick="openSopModal()">+ 새 표준서 제정</button>
    </div>
  </div>

  <?php if ($message): ?>
    <div style="background:#ecfdf5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;border:1px solid #a7f3d0;">
      ✅ <?= $escape($message) ?>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div style="background:#fef2f2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;border:1px solid #fecaca;">
      ⚠️ <?= $escape($error) ?>
    </div>
  <?php endif; ?>

  <!-- KPI Metrics -->
  <div class="sop-metrics-grid">
    <a href="?year=<?= $year ?>&amp;tab=list&amp;status=active" class="sop-metric-card" style="text-decoration:none;color:inherit;">
      <div class="sop-metric-title">보유 유효 표준서 (2.2.1)</div>
      <div class="sop-metric-value"><?= number_format($totalActive) ?>종</div>
      <div class="sop-metric-sub">전체 <?= number_format($totalAll) ?>건 중 폐지 <?= number_format($totalDeprecated) ?>건 제외</div>
    </a>
    <a href="?year=<?= $year ?>&amp;tab=list&amp;status=recent" class="sop-metric-card" style="text-decoration:none;color:inherit;">
      <div class="sop-metric-title">2026년 11월 개정·신규 반영</div>
      <div class="sop-metric-value" style="color:var(--sop-success);">100% 반영</div>
      <div class="sop-metric-sub">신규 5건 · 본문개정 4건 · 폐지 1건</div>
    </a>
    <a href="?year=<?= $year ?>&amp;tab=revisions" class="sop-metric-card" style="text-decoration:none;color:inherit;">
      <div class="sop-metric-title">제·개정 이력 (2.2.2)</div>
      <div class="sop-metric-value"><?= number_format($revCount) ?>건</div>
      <div class="sop-metric-sub">개정 대장 및 변경사유 추적 관리</div>
    </a>
    <a href="?year=<?= $year ?>&amp;tab=backups" class="sop-metric-card" style="text-decoration:none;color:inherit;">
      <div class="sop-metric-title">DB 이미지 내장 · 백업</div>
      <div class="sop-metric-value"><?= number_format($totalDbImages) ?>개</div>
      <div class="sop-metric-sub">본문 도해 <?= number_format($bodyDbImages) ?>개 · 스냅샷 <?= number_format($backupCount) ?>회</div>
    </a>
  </div>

  <!-- Navigation Tabs -->
  <div class="sop-tabs no-print">
    <a href="?year=<?= $year ?>&amp;tab=list" class="sop-tab <?= $tab === 'list' ? 'active' : '' ?>">📋 표준서 전체목록 (<?= number_format($totalAll) ?>)</a>
    <a href="?year=<?= $year ?>&amp;tab=revisions" class="sop-tab <?= $tab === 'revisions' ? 'active' : '' ?>">📝 제·개정 이력 대장 (<?= number_format($revCount) ?>)</a>
    <a href="?year=<?= $year ?>&amp;tab=distribution" class="sop-tab <?= $tab === 'distribution' ? 'active' : '' ?>">📢 근로자 배포·열람 대장 (<?= number_format($distRowsCount) ?>)</a>
    <a href="?year=<?= $year ?>&amp;tab=backups" class="sop-tab <?= $tab === 'backups' ? 'active' : '' ?>">💾 DB 백업 관리 (<?= number_format($backupCount) ?>)</a>
  </div>

  <?php if ($tab === 'list'): ?>
    <!-- Filter & Search Controls -->
    <div class="sop-controls no-print">
      <form method="get" class="sop-filter-group" style="flex-wrap:wrap;">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="tab" value="list">
        <select name="volume" class="sop-select" onchange="this.form.submit()">
          <option value="">전체 권호 (01~06권)</option>
          <?php foreach ($volumes as $v): ?>
            <option value="<?= $escape($v) ?>" <?= $volume === $v ? 'selected' : '' ?>><?= $escape($v) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="category" class="sop-select" onchange="this.form.submit()">
          <option value="">전체 작업분류</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $escape($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= $escape($cat) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" class="sop-select" onchange="this.form.submit()">
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>유효 표준서만 (<?= $totalActive ?>)</option>
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>전체 보기 (<?= $totalAll ?>)</option>
          <option value="recent" <?= $statusFilter === 'recent' ? 'selected' : '' ?>>2026 개정·신규·폐지 (<?= count($revsBySop) ?>)</option>
          <option value="deprecated" <?= $statusFilter === 'deprecated' ? 'selected' : '' ?>>폐지 문서만 (<?= $totalDeprecated ?>)</option>
        </select>
        <input type="text" name="search" class="sop-input" placeholder="번호·문서코드·작업명·본문 검색" value="<?= $escape($search) ?>" style="width:240px;">
        <button type="submit" class="sop-btn sop-btn-outline">검색</button>
        <?php if ($volume !== '' || $category !== '' || $search !== '' || $statusFilter !== 'active'): ?>
          <a href="?year=<?= $year ?>&amp;tab=list" class="sop-btn sop-btn-outline">초기화</a>
        <?php endif; ?>
      </form>
      <div style="font-size:12.5px;color:#64748b;font-weight:600;">
        조회 결과: <strong style="color:#1e3a8a;"><?= number_format($filteredTotal) ?>건</strong> (행 클릭 시 원본 서식 조회 및 인쇄)
      </div>
    </div>

    <!-- SOP Table -->
    <div class="sop-table-card">
      <table class="sop-table">
        <thead>
          <tr>
            <th style="width:62px;text-align:center;">No.</th>
            <th style="width:74px;text-align:center;">권호</th>
            <th style="width:115px;">문서번호</th>
            <th style="width:125px;">작업분류</th>
            <th>표준작업절차서(SOP) 명 및 변경 요약</th>
            <th style="width:82px;text-align:center;">개정차수</th>
            <th style="width:105px;text-align:center;">등록/개정일</th>
            <th style="width:110px;">담당/승인</th>
            <th class="no-print" style="width:195px;text-align:center;">관리 및 인쇄</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sops)): ?>
            <tr><td colspan="9" style="text-align:center;padding:44px;color:var(--sop-muted);">조건에 해당하는 안전작업표준서가 없습니다.</td></tr>
          <?php else: foreach ($sops as $sop):
            $sid = (int)$sop['id'];
            $isDep = (($sop['status'] ?? '유효') === '폐지');
            $hasRevs = !empty($revsBySop[$sid]);
            $imgCnt = count($imagesBySop[$sid] ?? []);
          ?>
            <tr class="sop-row-click <?= $isDep ? 'sop-row-deprecated' : '' ?>" onclick="onSopRowClick(event, <?= $sid ?>)">
              <td style="text-align:center;font-weight:800;color:#1e3a8a;"><?= (int)$sop['sop_no'] ?></td>
              <td style="text-align:center;"><span class="sop-badge-vol"><?= $escape($sop['volume'] ?: '—') ?></span></td>
              <td><strong style="font-size:12.5px;"><?= $escape($sop['job_plan_code'] ?: $sop['doc_code']) ?></strong></td>
              <td><span class="sop-badge sop-badge-cat" style="font-size:11.5px;"><?= $escape($sop['category']) ?></span></td>
              <td>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                  <span style="font-weight:700;font-size:14.5px;color:var(--sop-primary);<?= $isDep ? 'text-decoration:line-through;color:#991b1b;' : '' ?>">
                    <?= $escape($sop['title']) ?>
                  </span>
                  <?php if ($isDep): ?>
                    <span class="sop-badge-del">폐지</span>
                  <?php elseif ((int)$sop['sop_no'] >= 248): ?>
                    <span class="sop-badge-new">신규</span>
                  <?php elseif ($hasRevs): ?>
                    <span class="sop-badge-upd">개정</span>
                  <?php endif; ?>
                  <?php if ($imgCnt > 0): ?>
                    <span class="sop-badge-img" title="DB 내장 도해/사진 <?= $imgCnt ?>개">🖼️ 도해 <?= $imgCnt ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($sop['change_reason']) && $sop['change_reason'] !== '사내전환'): ?>
                  <div style="font-size:12px;color:#475569;margin-top:2px;">관리이력: <?= $escape($sop['change_reason']) ?></div>
                <?php endif; ?>
              </td>
              <td style="text-align:center;">
                <?php if ($isDep): ?>
                  <span class="sop-badge-del">폐지</span>
                <?php else: ?>
                  <span class="sop-badge sop-badge-rev">Rev.<?= (int)$sop['revision_no'] ?></span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;font-size:12.5px;"><?= $escape($sop['revised_date']) ?></td>
              <td style="font-size:12px;">
                <div style="font-weight:700;"><?= $escape($sop['author_name']) ?></div>
                <div style="color:#64748b;font-size:11px;">승인: <?= $escape($sop['approver_name']) ?></div>
              </td>
              <td class="no-print" style="text-align:center;white-space:nowrap;">
                <button type="button" class="sop-btn sop-btn-outline sop-btn-sm" onclick="printSopById(<?= $sid ?>)">🖨️ 인쇄</button>
                <button type="button" class="sop-btn sop-btn-outline sop-btn-sm" onclick="openRevModal(<?= $sid ?>, <?= (int)$sop['revision_no'] ?>, '<?= $escape($sop['title']) ?>')">+개정</button>
                <button type="button" class="sop-btn sop-btn-outline sop-btn-sm" onclick="editSopById(<?= $sid ?>)">수정</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('해당 안전작업표준서를 삭제하시겠습니까?');">
                  <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_standard_procedure_csrf']) ?>">
                  <input type="hidden" name="action" value="delete_sop">
                  <input type="hidden" name="id" value="<?= $sid ?>">
                  <button type="submit" class="sop-btn sop-btn-outline sop-btn-sm" style="color:var(--sop-danger);">삭제</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <?php if ($filteredTotal > 0): ?>
        <div class="sop-pagination no-print">
          <div>
            전체 <strong><?= number_format($filteredTotal) ?>건</strong> 중
            <strong><?= number_format($offset + 1) ?> ~ <?= number_format(min($offset + count($sops), $filteredTotal)) ?></strong> 표시
          </div>
          <div class="sop-page-links">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <a href="?<?= $escape($queryBase) ?>&amp;page=<?= $p ?>" class="sop-page-btn <?= ($p === $page && ($_GET['show'] ?? '') !== 'all') ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if (($_GET['show'] ?? '') === 'all'): ?>
              <a href="?<?= $escape($queryBase) ?>&amp;page=1" class="sop-page-btn">50건씩 보기</a>
            <?php elseif ($filteredTotal > 50): ?>
              <a href="?<?= $escape($queryBase) ?>&amp;show=all" class="sop-page-btn">전체보기</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php
      $sopJsMap = [];
      foreach ($sops as $item) {
          $sid = (int)$item['id'];
          $item['revisions'] = $revsBySop[$sid] ?? [];
          $item['images'] = $imagesBySop[$sid] ?? [];
          $item['layout'] = !empty($item['layout_json']) ? json_decode((string)$item['layout_json'], true) : null;
          unset($item['layout_json']);
          $sopJsMap[$sid] = $item;
      }
    ?>
    <script>
      window._sopMap = <?= json_encode($sopJsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    </script>

  <?php elseif ($tab === 'revisions'): ?>
    <!-- TAB 2: REVISION HISTORY -->
    <div class="sop-table-card">
      <table class="sop-table">
        <thead>
          <tr>
            <th style="width:105px;text-align:center;">개정일자</th>
            <th style="width:65px;text-align:center;">No.</th>
            <th style="width:72px;text-align:center;">권호</th>
            <th style="width:110px;">문서번호</th>
            <th style="width:220px;">작업표준명</th>
            <th style="width:80px;text-align:center;">개정차수</th>
            <th>개정 사유 및 주요 변경 내용</th>
            <th style="width:125px;">검토 / 승인</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allRevisions)): ?>
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--sop-muted);">등록된 제·개정 이력이 없습니다.</td></tr>
          <?php else: foreach ($allRevisions as $rv): ?>
            <tr>
              <td style="text-align:center;font-weight:700;"><?= $escape($rv['revision_date']) ?></td>
              <td style="text-align:center;font-weight:800;color:#1e3a8a;"><?= (int)$rv['sop_no'] ?></td>
              <td style="text-align:center;"><span class="sop-badge-vol"><?= $escape($rv['volume']) ?></span></td>
              <td><strong><?= $escape($rv['job_plan_code'] ?: $rv['doc_code']) ?></strong></td>
              <td>
                <a href="?year=<?= $year ?>&amp;tab=list&amp;status=all&amp;search=<?= urlencode((string)$rv['sop_no']) ?>" style="font-weight:700;color:#1e3a8a;text-decoration:none;">
                  <?= $escape($rv['title']) ?> ↗
                </a>
              </td>
              <td style="text-align:center;">
                <?php if (($rv['sop_status'] ?? '') === '폐지'): ?>
                  <span class="sop-badge-del">폐지</span>
                <?php else: ?>
                  <span class="sop-badge sop-badge-rev">Rev.<?= (int)$rv['revision_no'] ?></span>
                <?php endif; ?>
              </td>
              <td style="font-size:13px;line-height:1.5;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:3px;"><?= $escape($rv['reason']) ?></div>
                <div style="color:#334155;white-space:pre-wrap;"><?= $escape($rv['changes_summary']) ?></div>
              </td>
              <td style="font-size:12px;">
                <div><?= $escape($rv['reviewer_name']) ?></div>
                <div style="color:#64748b;">승인: <?= $escape($rv['approver_name']) ?></div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'distribution'): ?>
    <!-- TAB 3: DISTRIBUTION LOG -->
    <div class="sop-table-card">
      <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:14px;font-weight:800;color:#1e3a8a;">근로자 안전작업표준서 배포 및 TBM 교육 확인 대장</div>
        <button type="button" class="sop-btn sop-btn-primary sop-btn-sm" onclick="openDistModal(0)">+ 배포·열람 기록 추가</button>
      </div>
      <table class="sop-table">
        <thead>
          <tr>
            <th style="width:110px;text-align:center;">배포일자</th>
            <th style="width:70px;text-align:center;">No.</th>
            <th>표준서 작업명</th>
            <th style="width:180px;">배포 대상 (팀/작업반)</th>
            <th style="width:200px;">배포·공유 방법</th>
            <th style="width:100px;text-align:center;">확인 인원</th>
            <th style="width:120px;">배포 담당자</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($distributions)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--sop-muted);">등록된 근로자 배포·열람 이력이 없습니다. 우측 상단 '+ 배포·열람 기록 추가' 버튼으로 기록해 주세요.</td></tr>
          <?php else: foreach ($distributions as $d): ?>
            <tr>
              <td style="text-align:center;font-weight:700;"><?= $escape($d['dist_date']) ?></td>
              <td style="text-align:center;font-weight:800;color:#1e3a8a;"><?= (int)$d['sop_no'] ?></td>
              <td><strong><?= $escape($d['title']) ?></strong> <span style="font-size:11.5px;color:#64748b;">(<?= $escape($d['job_plan_code'] ?: $d['doc_code']) ?>)</span></td>
              <td><?= $escape($d['target_group']) ?></td>
              <td><?= $escape($d['dist_method']) ?></td>
              <td style="text-align:center;font-weight:800;color:#059669;"><?= (int)$d['confirmed_count'] ?>명</td>
              <td><?= $escape($d['distributor_name']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'backups'): ?>
    <!-- TAB 4: DB BACKUP MANAGEMENT -->
    <div class="sop-table-card">
      <div style="padding:16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;background:#f8fafc;">
        <div>
          <div style="font-size:15px;font-weight:800;color:#1e3a8a;">💾 안전작업표준서 SQLite 데이터베이스 통합 백업 관리</div>
          <div style="font-size:12.5px;color:#475569;margin-top:4px;">
            전체 표준서 본문(<?= number_format($totalAll) ?>종), 행 정렬 레이아웃(`layout_json`), 내장 이미지 BLOB(<?= number_format($totalDbImages) ?>개: 본문 도해 <?= number_format($bodyDbImages) ?>개 + 로고 <?= number_format($totalDbImages - $bodyDbImages) ?>개)이 단일 DB 파일에 완전 내장되어 원본 엑셀 유실과 무관하게 영구 보존됩니다.
          </div>
        </div>
        <form method="post" style="display:flex;gap:8px;align-items:center;">
          <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_standard_procedure_csrf']) ?>">
          <input type="hidden" name="action" value="create_backup">
          <input type="text" name="backup_note" class="sop-input" placeholder="백업 메모 (예: 2026년 11월 개정 완료본)" style="width:240px;">
          <button type="submit" class="sop-btn sop-btn-primary">+ 즉시 DB 백업 생성</button>
        </form>
      </div>
      <table class="sop-table">
        <thead>
          <tr>
            <th style="width:155px;text-align:center;">백업 일시</th>
            <th style="width:90px;text-align:center;">구분</th>
            <th>백업 파일명 및 무결성 해시 (SHA-256)</th>
            <th style="width:95px;text-align:center;">용량</th>
            <th style="width:110px;text-align:center;">표준서 / 이미지</th>
            <th style="width:220px;">백업 사유 / 메모</th>
            <th style="width:115px;text-align:center;">다운로드</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($backupLogs)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--sop-muted);">생성된 백업 이력이 없습니다.</td></tr>
          <?php else: foreach ($backupLogs as $bk):
            $kindLabel = $bk['kind'] === 'manual' ? '수동 영구' : ($bk['kind'] === 'change' ? '변경시 자동' : '일일 자동');
            $kindStyle = $bk['kind'] === 'manual' ? 'background:#dcfce7;color:#166534;' : ($bk['kind'] === 'change' ? 'background:#fef3c7;color:#92400e;' : 'background:#e0e7ff;color:#1e40af;');
            $sizeMb = number_format(((int)$bk['size_bytes']) / 1048576, 2) . ' MB';
          ?>
            <tr>
              <td style="text-align:center;font-weight:700;font-size:12.5px;"><?= $escape($bk['created_at']) ?></td>
              <td style="text-align:center;"><span style="<?= $kindStyle ?>padding:3px 8px;border-radius:999px;font-size:11.5px;font-weight:800;"><?= $kindLabel ?></span></td>
              <td>
                <div style="font-weight:700;font-size:13px;color:#0f172a;"><?= $escape($bk['file_name']) ?></div>
                <div style="font-family:monospace;font-size:11px;color:#64748b;">SHA256: <?= $escape(substr((string)$bk['sha256'], 0, 24)) ?>...</div>
              </td>
              <td style="text-align:center;font-weight:700;"><?= $sizeMb ?></td>
              <td style="text-align:center;font-size:12.5px;">
                <strong><?= (int)$bk['sop_count'] ?>종</strong> / <?= (int)$bk['image_count'] ?>개
              </td>
              <td style="font-size:12.5px;color:#334155;"><?= $escape($bk['note']) ?></td>
              <td style="text-align:center;">
                <a href="?download_backup=<?= urlencode((string)$bk['file_name']) ?>" class="sop-btn sop-btn-outline sop-btn-sm">⬇ 다운로드</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- MODAL: SOP CREATE / EDIT -->
<div id="modalSop" class="sop-modal-backdrop" style="display:none;" onclick="closeSopModal()">
  <div class="sop-modal" style="max-width:860px;" onclick="event.stopPropagation()">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_standard_procedure_csrf']) ?>">
      <input type="hidden" name="action" value="save_sop">
      <input type="hidden" name="id" id="sop_id" value="0">
      <div class="sop-modal-header">
        <h3 id="sop_modal_title">안전작업표준서(SOP) 제정 및 수정</h3>
        <button type="button" class="sop-modal-close" onclick="closeSopModal()">&times;</button>
      </div>
      <div class="sop-modal-body">
        <div class="sop-form-grid">
          <div class="sop-form-group">
            <label>연번 (No.)</label>
            <input type="number" name="sop_no" id="sop_no" min="1" placeholder="자동 부여">
          </div>
          <div class="sop-form-group">
            <label>권호</label>
            <select name="volume" id="sop_volume" class="sop-select">
              <?php foreach ($volumes as $v): ?>
                <option value="<?= $escape($v) ?>"><?= $escape($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sop-form-group">
            <label>문서번호 (Job Plan)</label>
            <input type="text" name="job_plan_code" id="sop_job_plan_code" placeholder="예: RLCMT012">
            <input type="hidden" name="doc_code" id="sop_doc_code" value="">
          </div>
          <div class="sop-form-group">
            <label>작업분류 *</label>
            <select name="category" id="sop_category" class="sop-select">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $escape($cat) ?>"><?= $escape($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>표준서 작업명 *</label>
            <input type="text" name="title" id="sop_title" placeholder="예: 고압 MOTOR 교체" required>
          </div>
          <div class="sop-form-group">
            <label>문서 상태</label>
            <select name="status" id="sop_status" class="sop-select">
              <option value="유효">유효 (사용중)</option>
              <option value="폐지">폐지 (삭제)</option>
            </select>
          </div>
          <div class="sop-form-group">
            <label>개정 차수 (Rev.)</label>
            <input type="number" name="revision_no" id="sop_revision_no" min="0" value="0">
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>관리이력 / 개정사유</label>
            <input type="text" name="change_reason" id="sop_change_reason" placeholder="예: 모회사 개정반영 / 신규등록">
            <input type="hidden" name="scope" id="sop_scope" value="">
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>필수 착용 보호구</label>
            <input type="text" name="ppe_required" id="sop_ppe_required" placeholder="예: 4대 안전보호구, 절연장갑, 전신안전대">
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>① 작업준비사항 *</label>
            <textarea name="pre_work_safety" id="sop_pre_work" rows="4" required></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>② 안전일반 (위험요인 및 일반안전수칙) *</label>
            <textarea name="danger_factors" id="sop_danger_factors" rows="4" required></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>③ 작업순서 *</label>
            <textarea name="in_work_safety" id="sop_in_work" rows="6" required></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>④ 안전작업순서 *</label>
            <textarea name="post_work_safety" id="sop_post_work" rows="6" required></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>비상시 조치요령</label>
            <input type="text" name="emergency_action" id="sop_emergency" value="비상상황 발생 시 즉시 작업중지, 전원차단(LOTO), 119 및 사내 비상연락망(안전관리자·CCR) 즉시 신고">
          </div>
          <div class="sop-form-group">
            <label>제정일자</label>
            <input type="date" name="enact_date" id="sop_enact_date" value="<?= $today ?>">
          </div>
          <div class="sop-form-group">
            <label>최근 개정일자</label>
            <input type="date" name="revised_date" id="sop_revised_date" value="<?= $today ?>">
          </div>
          <div class="sop-form-group">
            <label>관리담당자</label>
            <input type="text" name="author_name" id="sop_author_name" value="공사팀 김남균">
          </div>
          <div class="sop-form-group">
            <label>승인자</label>
            <input type="text" name="approver_name" id="sop_approver_name" value="정연탁 대표">
          </div>
        </div>
      </div>
      <div class="sop-modal-footer">
        <button type="button" class="sop-btn sop-btn-outline" onclick="closeSopModal()">취소</button>
        <button type="submit" class="sop-btn sop-btn-primary">저장하기</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: ADD REVISION -->
<div id="modalRev" class="sop-modal-backdrop" style="display:none;" onclick="closeRevModal()">
  <div class="sop-modal" style="max-width:600px;" onclick="event.stopPropagation()">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_standard_procedure_csrf']) ?>">
      <input type="hidden" name="action" value="add_revision">
      <input type="hidden" name="sop_id" id="rev_sop_id" value="0">
      <div class="sop-modal-header">
        <h3 id="rev_modal_title">안전작업표준서 제·개정 이력 등록</h3>
        <button type="button" class="sop-modal-close" onclick="closeRevModal()">&times;</button>
      </div>
      <div class="sop-modal-body">
        <div class="sop-form-grid" style="grid-template-columns:1fr 1fr;">
          <div class="sop-form-group">
            <label>개정 차수 (Rev.) *</label>
            <input type="number" name="revision_no" id="rev_revision_no" min="0" required>
          </div>
          <div class="sop-form-group">
            <label>문서 상태</label>
            <select name="new_status" class="sop-select">
              <option value="유효">유효</option>
              <option value="폐지">폐지</option>
            </select>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>개정 일자 *</label>
            <input type="date" name="revision_date" id="rev_revision_date" value="<?= $today ?>" required>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>개정 사유 *</label>
            <input type="text" name="reason" placeholder="예: [개정] 모회사 안전절차 보완 및 위험성평가 연계 반영" required>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>주요 변경 내용 요약 *</label>
            <textarea name="changes_summary" rows="4" placeholder="추가·변경된 안전수칙 및 작업절차를 구체적으로 기재" required></textarea>
          </div>
          <div class="sop-form-group">
            <label>검토자</label>
            <input type="text" name="reviewer_name" value="공사팀 김남균">
          </div>
          <div class="sop-form-group">
            <label>승인자</label>
            <input type="text" name="approver_name" value="정연탁 대표">
          </div>
        </div>
      </div>
      <div class="sop-modal-footer">
        <button type="button" class="sop-btn sop-btn-outline" onclick="closeRevModal()">취소</button>
        <button type="submit" class="sop-btn sop-btn-primary">개정 등록</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: ADD DISTRIBUTION -->
<div id="modalDist" class="sop-modal-backdrop" style="display:none;" onclick="closeDistModal()">
  <div class="sop-modal" style="max-width:600px;" onclick="event.stopPropagation()">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csm_standard_procedure_csrf']) ?>">
      <input type="hidden" name="action" value="add_dist">
      <div class="sop-modal-header">
        <h3>근로자 배포·열람 확인 기록</h3>
        <button type="button" class="sop-modal-close" onclick="closeDistModal()">&times;</button>
      </div>
      <div class="sop-modal-body">
        <div class="sop-form-grid" style="grid-template-columns:1fr;">
          <div class="sop-form-group">
            <label>대상 안전작업표준서 *</label>
            <select name="sop_id" id="dist_sop_id" class="sop-select" required>
              <option value="">-- 배포한 표준서를 선택하세요 --</option>
              <?php foreach ($sopOptions as $opt): ?>
                <option value="<?= (int)$opt['id'] ?>">No.<?= (int)$opt['sop_no'] ?> [<?= $escape($opt['volume']) ?>] <?= $escape($opt['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sop-form-group">
            <label>배포 일자 *</label>
            <input type="date" name="dist_date" value="<?= $today ?>" required>
          </div>
          <div class="sop-form-group">
            <label>배포 대상 (팀 / 작업반) *</label>
            <input type="text" name="target_group" placeholder="예: 공사팀-전기, 공사팀-모터 전 작업자" required>
          </div>
          <div class="sop-form-group">
            <label>배포 및 교육 방법 *</label>
            <input type="text" name="dist_method" value="TBM 교육 및 현장 안전작업표준서 책자·모바일 배포" required>
          </div>
          <div class="sop-form-group">
            <label>열람·서명 확인 인원 (명) *</label>
            <input type="number" name="confirmed_count" min="1" value="10" required>
          </div>
          <div class="sop-form-group">
            <label>배포 담당자</label>
            <input type="text" name="distributor_name" value="공사팀 김남균">
          </div>
        </div>
      </div>
      <div class="sop-modal-footer">
        <button type="button" class="sop-btn sop-btn-outline" onclick="closeDistModal()">취소</button>
        <button type="submit" class="sop-btn sop-btn-primary">기록 저장</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: VIEW SOP DETAIL (Option B Form Preview) -->
<div id="modalView" class="sop-modal-backdrop" style="display:none;" onclick="closeViewModal()">
  <div class="sop-modal" style="max-width:980px;width:96%;" onclick="event.stopPropagation()">
    <div class="sop-modal-header">
      <h3 id="view_title">표준작업절차서 상세조회</h3>
      <button type="button" class="sop-modal-close" onclick="closeViewModal()">&times;</button>
    </div>
    <div class="sop-modal-body" id="view_body" style="font-size:13px;line-height:1.5;max-height:78vh;overflow-y:auto;"></div>
    <div class="sop-modal-footer" style="justify-content:space-between;">
      <div style="display:flex;gap:8px;">
        <button type="button" class="sop-btn sop-btn-primary" onclick="printFromModal()">🖨️ A4 표준서 인쇄 (원본 서식)</button>
        <button type="button" class="sop-btn sop-btn-outline" onclick="openRevFromView()">+ 개정 등록</button>
        <button type="button" class="sop-btn sop-btn-outline" onclick="openDistFromView()">📢 배포 기록</button>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" class="sop-btn sop-btn-outline" onclick="editFromView()">✏️ 수정</button>
        <button type="button" class="sop-btn sop-btn-outline" onclick="closeViewModal()">닫기</button>
      </div>
    </div>
  </div>
</div>

<script>
const COMPANY_LOGO = <?= json_encode($logoBase64) ?>;
let currentViewingSop = null;

function escHtml(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function getSop(id) {
  return (window._sopMap && window._sopMap[id]) ? window._sopMap[id] : null;
}

function onSopRowClick(e, id) {
  if (e && e.target && e.target.closest('button, a, select, input, form')) return;
  const s = getSop(id);
  if (s) viewSop(s);
}

function openSopModal() {
  document.getElementById('sop_id').value = '0';
  document.getElementById('sop_modal_title').innerText = '새 안전작업표준서(SOP) 제정';
  document.getElementById('sop_no').value = '';
  document.getElementById('sop_volume').value = '06권';
  document.getElementById('sop_job_plan_code').value = '';
  document.getElementById('sop_doc_code').value = '';
  document.getElementById('sop_category').value = '고압·수변전·전기';
  document.getElementById('sop_title').value = '';
  document.getElementById('sop_scope').value = '';
  document.getElementById('sop_status').value = '유효';
  document.getElementById('sop_revision_no').value = '0';
  document.getElementById('sop_change_reason').value = '신규등록';
  document.getElementById('sop_ppe_required').value = '4대 안전보호구 (안전모, 안전화, 보안경, 안전장갑)';
  document.getElementById('sop_pre_work').value = '';
  document.getElementById('sop_danger_factors').value = '';
  document.getElementById('sop_in_work').value = '';
  document.getElementById('sop_post_work').value = '';
  document.getElementById('sop_emergency').value = '비상상황 발생 시 즉시 작업중지, 전원차단(LOTO), 119 및 사내 비상연락망(안전관리자·CCR) 즉시 신고';
  document.getElementById('sop_enact_date').value = '<?= $today ?>';
  document.getElementById('sop_revised_date').value = '<?= $today ?>';
  document.getElementById('sop_author_name').value = '공사팀 김남균';
  document.getElementById('sop_approver_name').value = '정연탁 대표';
  document.getElementById('modalSop').style.display = 'flex';
}
function closeSopModal() {
  document.getElementById('modalSop').style.display = 'none';
}

function editSopById(id) {
  const s = getSop(id);
  if (s) editSop(s);
}
function editSop(s) {
  document.getElementById('sop_id').value = s.id;
  document.getElementById('sop_modal_title').innerText = '안전작업표준서 수정 (No.' + (s.sop_no || s.id) + ')';
  document.getElementById('sop_no').value = s.sop_no || '';
  document.getElementById('sop_volume').value = s.volume || '01권';
  document.getElementById('sop_job_plan_code').value = s.job_plan_code || '';
  document.getElementById('sop_doc_code').value = s.doc_code || '';
  document.getElementById('sop_category').value = s.category || '고압·수변전·전기';
  document.getElementById('sop_title').value = s.title || '';
  document.getElementById('sop_scope').value = s.scope || '';
  document.getElementById('sop_status').value = s.status || '유효';
  document.getElementById('sop_revision_no').value = s.revision_no ?? 0;
  document.getElementById('sop_change_reason').value = s.change_reason || '';
  document.getElementById('sop_ppe_required').value = s.ppe_required || '';
  document.getElementById('sop_pre_work').value = s.pre_work_safety || '';
  document.getElementById('sop_danger_factors').value = s.danger_factors || '';
  document.getElementById('sop_in_work').value = s.in_work_safety || '';
  document.getElementById('sop_post_work').value = s.post_work_safety || '';
  document.getElementById('sop_emergency').value = s.emergency_action || '';
  document.getElementById('sop_enact_date').value = s.enact_date || '';
  document.getElementById('sop_revised_date').value = s.revised_date || '';
  document.getElementById('sop_author_name').value = s.author_name || '공사팀 김남균';
  document.getElementById('sop_approver_name').value = s.approver_name || '정연탁 대표';
  document.getElementById('modalSop').style.display = 'flex';
}

function openRevModal(id, currentRev, title) {
  document.getElementById('rev_sop_id').value = id;
  document.getElementById('rev_revision_no').value = (parseInt(currentRev, 10) || 0) + 1;
  document.getElementById('rev_modal_title').innerText = '제·개정 등록 — ' + (title || '');
  document.getElementById('modalRev').style.display = 'flex';
}
function closeRevModal() {
  document.getElementById('modalRev').style.display = 'none';
}

function openDistModal(sopId) {
  const sel = document.getElementById('dist_sop_id');
  if (sel && sopId) sel.value = String(sopId);
  document.getElementById('modalDist').style.display = 'flex';
}
function closeDistModal() {
  document.getElementById('modalDist').style.display = 'none';
}

function formatDotDate(d) {
  const m = String(d || '').match(/(\d{4})[-./](\d{1,2})[-./](\d{1,2})/);
  if (!m) return d || '';
  return m[1] + '. ' + m[2].padStart(2, '0') + '. ' + m[3].padStart(2, '0') + '.';
}

/**
 * Render cell items from layout_json row ('l' or 'rt') with original indentation, red colors & bold runs.
 */
function renderSideCellsHtml(items, baseCol) {
  if (!items || !items.length) return '';
  return items.map(it => {
    const colOffset = Math.max(0, (it.col || baseCol) - baseCol);
    const padLeft = colOffset > 0 ? (colOffset * 14) : 0;
    const runsHtml = (it.runs || []).map(run => {
      let style = '';
      if (run.b) style += 'font-weight:700;';
      if (run.c) style += 'color:#' + escHtml(run.c) + ';';
      const txt = escHtml(run.t || '').replace(/\r?\n/g, '<br>');
      return style ? `<span style="${style}">${txt}</span>` : txt;
    }).join('');
    return `<div style="${padLeft ? 'padding-left:' + padLeft + 'px;' : ''}">${runsHtml}</div>`;
  }).join('');
}

/**
 * Build Option B (기존 엑셀 PDF 서식 1:1) HTML for both Modal Preview and A4 Print.
 */
function buildOptionBSheetHtml(s, forPrint) {
  const code = s.job_plan_code || s.doc_code || '';
  const isDep = (s.status === '폐지');
  const revLabel = isDep ? '폐지' : String(s.revision_no ?? 0);
  const dateLabel = (parseInt(s.revision_no, 10) > 0 || isDep) ? '사내개정일' : '사내등록일';
  const dateDot = formatDotDate(s.revised_date || s.enact_date);
  const historyText = s.change_reason || (isDep ? '폐지(타입변경삭제)' : '사내전환');
  const rawAuthor = String(s.author_name || '공사팀 김남균').trim();
  const authorParts = rawAuthor.split(/[\s/]+/).filter(Boolean);
  const teamLine = authorParts[0] || '공사팀';
  const personLine = authorParts.slice(1).join(' ') || '김남균';
  const logoHtml = COMPANY_LOGO
    ? `<img src="${COMPANY_LOGO}" alt="현대기전" style="height:${forPrint ? '23px' : '22px'};width:auto;display:block;">`
    : '';

  const imgs = Array.isArray(s.images) ? s.images : [];
  const usedImgIds = new Set();

  function renderImagesAtRow(side, rowNum) {
    const matched = imgs.filter(im => im.side === side && im.from_row === rowNum);
    if (!matched.length) return '';
    return matched.map(im => {
      usedImgIds.add(im.id);
      const maxH = forPrint ? 210 : 230;
      const wStyle = im.px_w ? `max-width:min(94%, ${Math.round(im.px_w * 0.78)}px);max-height:${maxH}px;` : `max-width:92%;max-height:${maxH}px;`;
      return `<div class="sop-b-img-box"><img src="standard_procedure.php?img_id=${im.id}" alt="SOP 도해" style="${wStyle}"></div>`;
    }).join('');
  }

  let bodyRowsHtml = '';
  const lay = s.layout;

  if (lay && Array.isArray(lay.up) && Array.isArray(lay.lo) && (lay.up.length > 0 || lay.lo.length > 0)) {
    const upRows = lay.up;
    const loRows = lay.lo;
    const upSpan = Math.max(1, upRows.length);
    const loSpan = Math.max(1, loRows.length);

    upRows.forEach((rObj, idx) => {
      const leftHtml = renderSideCellsHtml(rObj.l, 2) + renderImagesAtRow('L', rObj.r);
      const rightHtml = renderSideCellsHtml(rObj.rt, 15) + renderImagesAtRow('R', rObj.r);
      const hPx = forPrint ? Math.max(14, Math.round((rObj.h || 14.25) * 1.05)) : Math.max(16, Math.round((rObj.h || 14.25) * 1.15));
      bodyRowsHtml += `<tr style="min-height:${hPx}px;">`;
      if (idx === 0) {
        bodyRowsHtml += `<td rowspan="${upSpan}" class="sop-b-vbar sop-b-vbar-left">①<br>작<br>업<br>준<br>비<br>사<br>항</td>`;
      }
      bodyRowsHtml += `<td class="sop-b-cell-left" style="height:${hPx}px;">${leftHtml || '&nbsp;'}</td>`;
      if (idx === 0) {
        bodyRowsHtml += `<td rowspan="${upSpan}" class="sop-b-vbar">②<br>안<br>전<br>일<br>반</td>`;
      }
      bodyRowsHtml += `<td style="height:${hPx}px;">${rightHtml || '&nbsp;'}</td></tr>`;
    });

    loRows.forEach((rObj, idx) => {
      let leftHtml = renderSideCellsHtml(rObj.l, 2) + renderImagesAtRow('L', rObj.r);
      let rightHtml = renderSideCellsHtml(rObj.rt, 15) + renderImagesAtRow('R', rObj.r);
      if (idx === loRows.length - 1) {
        // append any remaining unanchored images
        imgs.forEach(im => {
          if (!usedImgIds.has(im.id)) {
            usedImgIds.add(im.id);
            const tag = `<div class="sop-b-img-box"><img src="standard_procedure.php?img_id=${im.id}" alt="SOP 도해" style="max-width:92%;max-height:210px;"></div>`;
            if (im.side === 'L') leftHtml += tag; else rightHtml += tag;
          }
        });
      }
      const hPx = forPrint ? Math.max(14, Math.round((rObj.h || 14.25) * 1.05)) : Math.max(16, Math.round((rObj.h || 14.25) * 1.15));
      bodyRowsHtml += `<tr class="${idx === 0 ? 'sop-b-divider-row' : ''}" style="min-height:${hPx}px;">`;
      if (idx === 0) {
        bodyRowsHtml += `<td rowspan="${loSpan}" class="sop-b-vbar sop-b-vbar-left">③<br><br>작<br><br>업<br><br>순<br><br>서</td>`;
      }
      bodyRowsHtml += `<td class="sop-b-cell-left" style="height:${hPx}px;">${leftHtml || '&nbsp;'}</td>`;
      if (idx === 0) {
        bodyRowsHtml += `<td rowspan="${loSpan}" class="sop-b-vbar">④<br><br>안<br><br>전<br><br>작<br><br>업<br><br>순<br><br>서</td>`;
      }
      bodyRowsHtml += `<td style="height:${hPx}px;">${rightHtml || '&nbsp;'}</td></tr>`;
    });
  } else {
    // Fallback for newly created SOPs without Excel layout_json
    const preLines = String(s.pre_work_safety || '').split(/\r?\n/);
    const genLines = String(s.danger_factors || '').split(/\r?\n/);
    const upMax = Math.max(8, preLines.length, genLines.length);
    for (let i = 0; i < upMax; i++) {
      const lTxt = escHtml(preLines[i] || '');
      const rRaw = genLines[i] || '';
      const isRed = /^(1\.|2\.|\*)/.test(rRaw.trim()) || /LOTOTO|위험성\s*평가|전도\s*주의/.test(rRaw);
      const rTxt = isRed ? `<span style="color:#FF0000;font-weight:700;">${escHtml(rRaw)}</span>` : escHtml(rRaw);
      bodyRowsHtml += `<tr>`;
      if (i === 0) bodyRowsHtml += `<td rowspan="${upMax}" class="sop-b-vbar sop-b-vbar-left">①<br>작<br>업<br>준<br>비<br>사<br>항</td>`;
      bodyRowsHtml += `<td class="sop-b-cell-left" style="height:18px;">${lTxt || '&nbsp;'}</td>`;
      if (i === 0) bodyRowsHtml += `<td rowspan="${upMax}" class="sop-b-vbar">②<br>안<br>전<br>일<br>반</td>`;
      bodyRowsHtml += `<td style="height:18px;">${rTxt || '&nbsp;'}</td></tr>`;
    }
    const inLines = String(s.in_work_safety || '').split(/\r?\n/);
    const postLines = String(s.post_work_safety || '').split(/\r?\n/);
    const loMax = Math.max(26, inLines.length, postLines.length);
    for (let i = 0; i < loMax; i++) {
      bodyRowsHtml += `<tr class="${i === 0 ? 'sop-b-divider-row' : ''}">`;
      if (i === 0) bodyRowsHtml += `<td rowspan="${loMax}" class="sop-b-vbar sop-b-vbar-left">③<br><br>작<br><br>업<br><br>순<br><br>서</td>`;
      bodyRowsHtml += `<td class="sop-b-cell-left" style="height:18px;">${escHtml(inLines[i] || '') || '&nbsp;'}</td>`;
      if (i === 0) bodyRowsHtml += `<td rowspan="${loMax}" class="sop-b-vbar">④<br><br>안<br><br>전<br><br>작<br><br>업<br><br>순<br><br>서</td>`;
      bodyRowsHtml += `<td style="height:18px;">${escHtml(postLines[i] || '') || '&nbsp;'}</td></tr>`;
    }
  }

  return `
    <div class="sop-b-sheet">
      <table class="sop-b-head">
        <colgroup>
          <col style="width:7%;">
          <col style="width:35.5%;">
          <col style="width:8.5%;">
          <col style="width:11%;">
          <col style="width:6.5%;">
          <col style="width:7.5%;">
          <col style="width:8.5%;">
          <col style="width:15.5%;">
        </colgroup>
        <tr>
          <td colspan="2" style="height:36px;">
            <div class="sop-b-title-wrap">
              ${logoHtml}
              <span class="sop-b-title-text">안 전 작 업 표 준 서</span>
            </div>
          </td>
          <td style="text-align:center;">NO</td>
          <td style="text-align:center;font-weight:600;">${escHtml(code)}</td>
          <td style="text-align:center;line-height:1.15;font-size:11px;">Team/<br>Person</td>
          <td colspan="3" style="text-align:center;line-height:1.25;">${escHtml(teamLine)}<br>${escHtml(personLine)}</td>
        </tr>
        <tr>
          <td style="text-align:center;height:26px;">작업명</td>
          <td style="font-weight:800;font-size:13px;">${escHtml(s.title)}</td>
          <td style="text-align:center;font-size:11px;">${escHtml(dateLabel)}</td>
          <td style="text-align:center;font-weight:800;font-size:11.5px;">${escHtml(dateDot)}</td>
          <td style="text-align:center;">Rev'</td>
          <td style="text-align:center;font-weight:800;">${escHtml(revLabel)}</td>
          <td style="text-align:center;font-size:11px;">관리이력</td>
          <td style="text-align:center;font-weight:700;">${escHtml(historyText)}</td>
        </tr>
      </table>

      <table class="sop-b-body">
        <colgroup>
          <col style="width:3.6%;">
          <col style="width:46.4%;">
          <col style="width:3.6%;">
          <col style="width:46.4%;">
        </colgroup>
        ${bodyRowsHtml}
      </table>
      <div class="sop-b-footer-sh">1/1,SH</div>
    </div>
  `;
}

function viewSop(s) {
  currentViewingSop = s;
  const code = s.job_plan_code || s.doc_code || '';
  document.getElementById('view_title').innerText = 'No.' + (s.sop_no || '') + ' · ' + (s.title || '') + (code ? ' [' + code + ']' : '');

  let revHtml = '';
  if (s.revisions && s.revisions.length > 0) {
    revHtml = '<div style="margin-bottom:12px;border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;padding:10px 12px;">' +
      '<div style="font-weight:800;font-size:12.5px;color:#92400e;margin-bottom:6px;">📝 제·개정 이력 (' + s.revisions.length + '건)</div>';
    s.revisions.forEach(r => {
      revHtml += '<div style="font-size:12.5px;color:#334155;padding:4px 0;border-top:1px dashed #fde68a;">' +
        '<strong>[' + escHtml(r.revision_date) + ' · Rev.' + escHtml(r.revision_no) + ']</strong> ' + escHtml(r.reason) +
        '<div style="color:#475569;margin-top:2px;white-space:pre-wrap;">' + escHtml(r.changes_summary) + '</div></div>';
    });
    revHtml += '</div>';
  }

  document.getElementById('view_body').innerHTML = revHtml + buildOptionBSheetHtml(s, false);
  document.getElementById('modalView').style.display = 'flex';
}
function closeViewModal() {
  document.getElementById('modalView').style.display = 'none';
}
function editFromView() {
  const s = currentViewingSop;
  closeViewModal();
  if (s) editSop(s);
}
function openRevFromView() {
  const s = currentViewingSop;
  closeViewModal();
  if (s) openRevModal(s.id, s.revision_no, s.title);
}
function openDistFromView() {
  const s = currentViewingSop;
  closeViewModal();
  if (s) openDistModal(s.id);
}

function printSopById(id) {
  const s = getSop(id);
  if (!s) return;
  currentViewingSop = s;
  printFromModal();
}

function printFromModal() {
  if (!currentViewingSop) return;
  const s = currentViewingSop;
  const sheetHtml = buildOptionBSheetHtml(s, true);

  const pw = window.open('', '_blank');
  pw.document.write(`
    <!doctype html>
    <html lang="ko">
    <head>
      <meta charset="utf-8">
      <title>No.${escHtml(s.sop_no)} ${escHtml(s.title)} · 안전작업표준서</title>
      <style>
        @page { size: A4 portrait; margin: 10mm 11mm 9mm 11mm; }
        * { box-sizing: border-box; }
        body {
          font-family: "Malgun Gothic", "맑은 고딕", Dotum, sans-serif;
          color: #000000;
          margin: 0;
          padding: 0;
          -webkit-print-color-adjust: exact !important;
          print-color-adjust: exact !important;
        }
        .sop-b-sheet {
          width: 100%;
          min-height: 274mm;
          display: flex;
          flex-direction: column;
        }
        .sop-b-head {
          width: 100%;
          border-collapse: collapse;
          table-layout: fixed;
          border: 1.8pt solid #000;
          margin-bottom: 1.5mm;
        }
        .sop-b-head td {
          border: 0.5pt solid #000;
          padding: 2pt 4pt;
          font-size: 8.5pt;
          line-height: 1.2;
          vertical-align: middle;
          color: #000;
        }
        .sop-b-title-wrap {
          display: flex;
          align-items: center;
          gap: 8px;
          padding-left: 3px;
        }
        .sop-b-title-text {
          font-size: 13.5pt;
          font-weight: 800;
          letter-spacing: 2.5px;
          white-space: nowrap;
        }
        .sop-b-body {
          width: 100%;
          flex: 1 1 auto;
          border-collapse: collapse;
          table-layout: fixed;
          border: 1.8pt solid #000;
        }
        .sop-b-body td {
          vertical-align: top;
          font-size: 8pt;
          line-height: 1.28;
          padding: 0.6pt 4pt;
          color: #000;
          word-break: keep-all;
          overflow-wrap: break-word;
        }
        .sop-b-vbar {
          background-color: #CCFFCC !important;
          border-right: 0.6pt solid #000;
          border-left: 1.2pt solid #000;
          text-align: center;
          vertical-align: middle !important;
          font-weight: 800;
          font-size: 8.2pt;
          line-height: 1.28;
          padding: 3pt 1pt !important;
        }
        .sop-b-vbar-left {
          border-left: none !important;
        }
        .sop-b-divider-row td {
          border-top: 1.5pt solid #000 !important;
        }
        .sop-b-cell-left {
          border-right: 1.2pt solid #000;
        }
        .sop-b-img-box {
          margin: 2pt 0;
          text-align: center;
        }
        .sop-b-img-box img {
          max-width: 92%;
          height: auto;
          display: inline-block;
        }
        .sop-b-footer-sh {
          text-align: center;
          font-size: 8pt;
          font-weight: 600;
          margin-top: 1mm;
          color: #000;
        }
      </style>
    </head>
    <body>
      ${sheetHtml}
      <script>
        window.addEventListener('load', function() {
          setTimeout(function() { window.print(); }, 180);
        });
      <\/script>
    </body>
    </html>
  `);
  pw.document.close();
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeViewModal();
    closeSopModal();
    closeRevModal();
    closeDistModal();
  }
});
</script>

</body>
</html>
