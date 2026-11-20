<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/health_management_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');

$db = hmg_db();

if (isset($_GET['view_photo'])) {
    $fn = basename((string)$_GET['view_photo']);
    if (!preg_match('/^[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|webp)$/D', $fn)) { http_response_code(400); exit('잘못된 요청'); }
    $path = hmg_photo_dir() . '/' . $fn;
    if (!is_file($path)) { http_response_code(404); exit('사진 없음'); }
    header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg'));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

if (isset($_GET['download_evidence'])) {
    $evId = (int)$_GET['download_evidence'];
    $stmt = $db->prepare('SELECT * FROM hmg_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$evId]);
    $ev = $stmt->fetch();
    if (!$ev) { http_response_code(404); exit('증빙 없음'); }
    $path = hmg_evidence_dir() . '/' . basename((string)$ev['stored_name']);
    if (!is_file($path)) { http_response_code(404); exit('파일 없음'); }
    $inline = isset($_GET['inline']) && in_array($ev['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    header('Content-Type: ' . $ev['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode((string)$ev['original_name']));
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['hmg_csrf'])) $_SESSION['hmg_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['hmg_csrf'];

$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) $year = $nowYear;

$validTabs = ['checkup', 'env', 'msds', 'chem_check', 'first_aid', 'evidence'];
$tab = (string)($_GET['tab'] ?? 'checkup');
if (!in_array($tab, $validTabs, true)) $tab = 'checkup';

$flashSuccess = '';
$flashError = '';
$pdfPreview = $_SESSION['hmg_pdf_preview'] ?? null;
$pdfJob = $_SESSION['hmg_pdf_job'] ?? null;

if (isset($_GET['pdf_job_status']) && $canManage) {
    header('Content-Type: application/json; charset=utf-8');
    $reqToken = (string)($_GET['token'] ?? '');
    if (!is_array($pdfJob) || $reqToken === '' || !hash_equals((string)($pdfJob['token'] ?? ''), $reqToken)) {
        echo json_encode(['status' => 'expired', 'error' => '진행 중인 PDF 분석 작업이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $statusFile = (string)($pdfJob['tmp_status'] ?? '');
    $jsonFile = (string)($pdfJob['tmp_json'] ?? '');
    $st = is_file($statusFile) ? json_decode((string)@file_get_contents($statusFile), true) : null;
    if (!is_array($st)) {
        $st = ['status' => 'running', 'done' => 0, 'total' => 0, 'message' => '로컬 OCR 엔진 시작 중...'];
    }
    $state = (string)($st['status'] ?? 'running');
    if ($state === 'done' && is_file($jsonFile)) {
        $parsed = json_decode((string)@file_get_contents($jsonFile), true);
        @unlink($jsonFile);
        @unlink($statusFile);
        unset($_SESSION['hmg_pdf_job']);
        if (!is_array($parsed)) {
            if (!empty($pdfJob['tmp_pdf']) && is_file((string)$pdfJob['tmp_pdf'])) @unlink((string)$pdfJob['tmp_pdf']);
            echo json_encode(['status' => 'error', 'error' => 'PDF 분석 결과 파싱에 실패했습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['hmg_pdf_preview'] = [
            'token' => (string)$pdfJob['token'],
            'tmp_pdf' => (string)$pdfJob['tmp_pdf'],
            'orig_name' => (string)$pdfJob['orig_name'],
            'year' => (int)$pdfJob['year'],
            'doc_type' => (string)($parsed['doc_type'] ?? 'general_report'),
            'doc_label' => (string)($parsed['doc_label'] ?? '건강검진 PDF'),
            'exam_date' => (string)($parsed['exam_date'] ?? ''),
            'hospital' => (string)($parsed['hospital'] ?? ''),
            'page_count' => (int)($parsed['page_count'] ?? 0),
            'records' => is_array($parsed['records'] ?? null) ? $parsed['records'] : [],
        ];
        echo json_encode(['status' => 'done', 'count' => count($_SESSION['hmg_pdf_preview']['records'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($state === 'error') {
        @unlink($jsonFile);
        @unlink($statusFile);
        if (!empty($pdfJob['tmp_pdf']) && is_file((string)$pdfJob['tmp_pdf'])) @unlink((string)$pdfJob['tmp_pdf']);
        unset($_SESSION['hmg_pdf_job']);
        echo json_encode(['status' => 'error', 'error' => (string)($st['error'] ?? 'PDF 분석 중 오류가 발생했습니다.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $elapsed = max(0, time() - (int)($pdfJob['started_at'] ?? time()));
    if ($elapsed > 480) {
        @unlink($statusFile);
        unset($_SESSION['hmg_pdf_job']);
        echo json_encode(['status' => 'error', 'error' => 'PDF 분석 시간이 초과되었습니다. 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'status' => 'running',
        'done' => (int)($st['done'] ?? 0),
        'total' => (int)($st['total'] ?? 0),
        'message' => (string)($st['message'] ?? '로컬 OCR 분석 중...'),
        'elapsed' => $elapsed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $flashError = '보안 토큰이 만료되었습니다. 새로고침 후 다시 시도해 주세요.';
    } elseif (!$canManage) {
        $flashError = '관리 권한이 필요합니다.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $postTab = (string)($_POST['tab'] ?? $tab);
        if (in_array($postTab, $validTabs, true)) $tab = $postTab;
        $now = date('Y-m-d H:i:s');
        try {
            if ($action === 'save_checkup') {
                $name = trim((string)($_POST['worker_name'] ?? ''));
                if ($name === '') throw new InvalidArgumentException('근로자 성명을 입력해 주세요.');
                $db->prepare('INSERT INTO hmg_checkups (year, worker_name, dept_name, job_type, exam_type, hazard_factors, exam_date, hospital, general_grade, special_grade, work_fitness, result_grade, finding_summary, followup_note, followup_done, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, $name,
                       trim((string)($_POST['dept_name'] ?? '공사부')),
                       trim((string)($_POST['job_type'] ?? '비사무직')),
                       trim((string)($_POST['exam_type'] ?? '일반+특수')),
                       trim((string)($_POST['hazard_factors'] ?? '')),
                       trim((string)($_POST['exam_date'] ?? '')),
                       trim((string)($_POST['hospital'] ?? '근로복지공단 동해병원')),
                       trim((string)($_POST['general_grade'] ?? 'A')),
                       trim((string)($_POST['special_grade'] ?? 'A')),
                       trim((string)($_POST['work_fitness'] ?? '가')),
                       trim((string)($_POST['result_grade'] ?? '정상(A/B)')),
                       trim((string)($_POST['finding_summary'] ?? '')),
                       trim((string)($_POST['followup_note'] ?? '')),
                       isset($_POST['followup_done']) ? 1 : 0,
                       $actor, $now, $now,
                   ]);
                $flashSuccess = '건강검진·사후관리 기록이 등록되었습니다.';
            } elseif ($action === 'update_followup') {
                $cid = (int)($_POST['id'] ?? 0);
                if ($cid > 0) {
                    $db->prepare('UPDATE hmg_checkups SET followup_note=?, followup_done=?, updated_at=? WHERE id=? AND year=?')
                       ->execute([trim((string)($_POST['followup_note'] ?? '')), isset($_POST['followup_done']) ? 1 : 0, $now, $cid, $year]);
                    $flashSuccess = '사후관리 조치 이행 내역이 저장되었습니다.';
                }
            } elseif ($action === 'parse_checkup_pdf') {
                $f = $_FILES['checkup_pdf'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
                    throw new InvalidArgumentException('분석할 건강검진/사후관리 PDF 파일을 선택해 주세요.');
                }
                if ((int)($f['size'] ?? 0) > 25 * 1024 * 1024) {
                    throw new InvalidArgumentException('25MB 이하의 PDF 파일만 분석할 수 있습니다.');
                }
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
                if ($mime !== 'application/pdf') {
                    throw new InvalidArgumentException('PDF 파일만 업로드할 수 있습니다.');
                }
                $tmpDir = __DIR__ . '/storage/tmp_pdf_parse';
                if (!is_dir($tmpDir)) mkdir($tmpDir, 0750, true);
                $token = bin2hex(random_bytes(12));
                $tmpPdf = $tmpDir . '/' . $token . '.pdf';
                $tmpJson = $tmpDir . '/' . $token . '.json';
                $tmpStatus = $tmpDir . '/' . $token . '.status.json';
                if (!move_uploaded_file((string)$f['tmp_name'], $tmpPdf)) {
                    throw new RuntimeException('임시 PDF 저장에 실패했습니다.');
                }
                $script = __DIR__ . '/scripts/parse_health_pdf.py';
                $pyBin = 'python';
                foreach (['C:\\Python314\\python.exe', 'C:\\Python313\\python.exe', 'C:\\Python312\\python.exe', 'C:\\WINDOWS\\py.exe'] as $cand) {
                    if (is_file($cand)) {
                        $pyBin = $cand;
                        break;
                    }
                }
                file_put_contents($tmpStatus, json_encode([
                    'status' => 'running',
                    'done' => 0,
                    'total' => 0,
                    'message' => '로컬 OCR 분석 시작 중...',
                ], JSON_UNESCAPED_UNICODE));

                $origName = mb_substr(basename(str_replace('\\', '/', (string)$f['name'])), 0, 180);
                if (DIRECTORY_SEPARATOR === '\\') {
                    $psExe = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
                    if (!is_file($psExe)) $psExe = 'powershell';
                    $pyArgs = [$script, '--input', $tmpPdf, '--output', $tmpJson, '--status', $tmpStatus];
                    $psArgList = implode(',', array_map(static fn($a) => "'" . str_replace("'", "''", str_replace('/', '\\', $a)) . "'", $pyArgs));
                    $psCmd = "Start-Process -WindowStyle Hidden -FilePath '" . str_replace("'", "''", $pyBin) . "' -ArgumentList @($psArgList)";
                    $launchCmd = escapeshellarg($psExe) . ' -NoProfile -Command ' . escapeshellarg($psCmd) . ' 2>&1';
                    $launchOut = [];
                    $launchRc = 0;
                    exec($launchCmd, $launchOut, $launchRc);
                    if ($launchRc !== 0) {
                        @unlink($tmpPdf);
                        @unlink($tmpStatus);
                        throw new RuntimeException('백그라운드 PDF 분석 실행에 실패했습니다.');
                    }
                } else {
                    $bgCmd = escapeshellarg($pyBin) . ' ' . escapeshellarg($script) . ' --input ' . escapeshellarg($tmpPdf) . ' --output ' . escapeshellarg($tmpJson) . ' --status ' . escapeshellarg($tmpStatus) . ' > /dev/null 2>&1 &';
                    exec($bgCmd);
                }

                if (is_array($pdfPreview) && !empty($pdfPreview['tmp_pdf']) && is_file((string)$pdfPreview['tmp_pdf'])) {
                    @unlink((string)$pdfPreview['tmp_pdf']);
                }
                unset($_SESSION['hmg_pdf_preview']);
                $pdfPreview = null;
                $pdfJob = [
                    'token' => $token,
                    'tmp_pdf' => $tmpPdf,
                    'tmp_json' => $tmpJson,
                    'tmp_status' => $tmpStatus,
                    'orig_name' => $origName,
                    'year' => $year,
                    'started_at' => time(),
                ];
                $_SESSION['hmg_pdf_job'] = $pdfJob;
                $flashSuccess = sprintf('[%s] 백그라운드 로컬 OCR 분석을 시작했습니다. 완료되면 미리보기가 자동으로 표시됩니다.', $origName);
            } elseif ($action === 'apply_checkup_pdf') {
                $preview = $_SESSION['hmg_pdf_preview'] ?? null;
                $postToken = (string)($_POST['preview_token'] ?? '');
                if (!is_array($preview) || $postToken === '' || !hash_equals((string)($preview['token'] ?? ''), $postToken) || (int)($preview['year'] ?? 0) !== $year) {
                    throw new InvalidArgumentException('미리보기 세션이 만료되었거나 연도가 일치하지 않습니다. PDF를 다시 분석해 주세요.');
                }
                $rowsIn = $_POST['rows'] ?? [];
                $attachEv = isset($_POST['attach_evidence']);
                if (!is_array($rowsIn) || !$rowsIn) {
                    throw new InvalidArgumentException('반영할 행이 없습니다.');
                }
                $docType = (string)($preview['doc_type'] ?? 'general_report');
                $applied = 0;
                $db->beginTransaction();
                try {
                    $findStmt = $db->prepare('SELECT * FROM hmg_checkups WHERE year=? AND worker_name=? LIMIT 1');
                    $updStmt = $db->prepare('UPDATE hmg_checkups SET dept_name=?, exam_date=?, hospital=?, general_grade=?, special_grade=?, work_fitness=?, result_grade=?, finding_summary=?, followup_note=?, followup_done=?, updated_at=? WHERE id=?');
                    $insStmt = $db->prepare('INSERT INTO hmg_checkups (year, worker_name, dept_name, job_type, exam_type, hazard_factors, exam_date, hospital, general_grade, special_grade, work_fitness, result_grade, finding_summary, followup_note, followup_done, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    foreach ($rowsIn as $r) {
                        if (empty($r['include'])) continue;
                        $wName = trim((string)($r['worker_name'] ?? ''));
                        if ($wName === '') continue;
                        $dept = trim((string)($r['dept_name'] ?? ''));
                        $genG = trim((string)($r['general_grade'] ?? ''));
                        $specG = trim((string)($r['special_grade'] ?? ''));
                        $fit = trim((string)($r['work_fitness'] ?? ''));
                        $resG = trim((string)($r['result_grade'] ?? ''));
                        $eDate = trim((string)($r['exam_date'] ?? ''));
                        $hosp = trim((string)($r['hospital'] ?? ''));
                        $find = trim((string)($r['finding_summary'] ?? ''));
                        $fNote = trim((string)($r['followup_note'] ?? ''));
                        $fDone = !empty($r['followup_done']) ? 1 : 0;

                        $findStmt->execute([$year, $wName]);
                        $ex = $findStmt->fetch();
                        if ($ex) {
                            $mDept = $dept !== '' ? $dept : (string)$ex['dept_name'];
                            $mDate = ($docType !== 'followup_cert' && $eDate !== '') ? $eDate : (string)$ex['exam_date'];
                            $mHosp = ($docType !== 'followup_cert' && $hosp !== '') ? $hosp : (string)$ex['hospital'];
                            $mGen = $genG !== '' ? $genG : (string)$ex['general_grade'];
                            $mSpec = $specG !== '' ? $specG : (string)$ex['special_grade'];
                            $mFit = ($fit === '나' || $fit === '다' || $fit === '라' || (string)$ex['work_fitness'] === '') ? ($fit ?: (string)$ex['work_fitness']) : (string)$ex['work_fitness'];
                            $mRes = $resG !== '' ? $resG : (string)$ex['result_grade'];
                            $mFind = (string)$ex['finding_summary'];
                            if ($find !== '' && !str_contains($mFind, $find)) {
                                $mFind = $mFind !== '' ? ($mFind . ' / ' . $find) : $find;
                            }
                            $mFNote = (string)$ex['followup_note'];
                            if ($fNote !== '' && !str_contains($mFNote, $fNote)) {
                                $mFNote = ($docType === 'followup_cert' || $mFNote === '') ? ($mFNote !== '' ? $fNote . ' / ' . $mFNote : $fNote) : $mFNote;
                            }
                            // Only mark done if followup_cert explicitly confirms it, or if no new abnormal finding resets it
                            $hasNewAbnormal = ($docType !== 'followup_cert') && (str_contains($resG, '유소견') || str_contains($resG, '요관찰') || str_contains($resG, '확인필요'));
                            $mFDone = $docType === 'followup_cert' ? ($fDone === 1 ? 1 : (int)$ex['followup_done']) : ($hasNewAbnormal ? $fDone : (($fDone === 1 || (int)$ex['followup_done'] === 1) ? 1 : 0));
                            $updStmt->execute([$mDept, $mDate, $mHosp, $mGen, $mSpec, $mFit, $mRes, $mFind, $mFNote, $mFDone, $now, (int)$ex['id']]);
                        } else {
                            $insStmt->execute([$year, $wName, $dept ?: '공사부', '비사무직', '일반+특수', '', $eDate ?: date('Y-m-d'), $hosp ?: '근로복지공단 동해병원', $genG, $specG, $fit ?: '나', $resG ?: '판정확인필요', $find, $fNote, $fDone, $actor, $now, $now]);
                        }
                        $applied++;
                    }

                    $evAttached = false;
                    if ($attachEv && !empty($preview['tmp_pdf']) && is_file((string)$preview['tmp_pdf'])) {
                        $evDir = hmg_evidence_dir();
                        if (!is_dir($evDir)) mkdir($evDir, 0750, true);
                        $stored = bin2hex(random_bytes(20)) . '.pdf';
                        $dest = $evDir . '/' . $stored;
                        $fSize = (int)filesize((string)$preview['tmp_pdf']);
                        if (@rename((string)$preview['tmp_pdf'], $dest) || (@copy((string)$preview['tmp_pdf'], $dest) && @unlink((string)$preview['tmp_pdf']))) {
                            $evKind = $docType === 'followup_cert' ? 'followup' : 'checkup_result';
                            $db->prepare('INSERT INTO hmg_evidence (year, item_code, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                               ->execute([$year, '2.8.1', $evKind, (string)$preview['orig_name'], $stored, 'application/pdf', $fSize, 'PDF 자동분석 업로드 (' . $applied . '명 반영)', $actor, $now]);
                            $evAttached = true;
                        }
                    } elseif (!empty($preview['tmp_pdf']) && is_file((string)$preview['tmp_pdf'])) {
                        @unlink((string)$preview['tmp_pdf']);
                    }
                    $db->commit();
                } catch (Throwable $txErr) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $txErr;
                }
                unset($_SESSION['hmg_pdf_preview']);
                $pdfPreview = null;
                $flashSuccess = sprintf('총 %d명의 건강검진·사후관리 내역이 일괄 반영되었습니다.%s', $applied, $evAttached ? ' (원본 PDF도 2.8.1 증빙에 자동 첨부 완료)' : '');
            } elseif ($action === 'cancel_checkup_pdf') {
                if (is_array($pdfPreview) && !empty($pdfPreview['tmp_pdf']) && is_file((string)$pdfPreview['tmp_pdf'])) {
                    @unlink((string)$pdfPreview['tmp_pdf']);
                }
                if (is_array($pdfJob)) {
                    foreach (['tmp_pdf', 'tmp_json', 'tmp_status'] as $jk) {
                        if (!empty($pdfJob[$jk]) && is_file((string)$pdfJob[$jk])) @unlink((string)$pdfJob[$jk]);
                    }
                }
                unset($_SESSION['hmg_pdf_preview'], $_SESSION['hmg_pdf_job']);
                $pdfPreview = null;
                $pdfJob = null;
                $flashSuccess = 'PDF 분석 작업을 취소했습니다.';
            } elseif ($action === 'delete_checkup') {
                $db->prepare('DELETE FROM hmg_checkups WHERE id=? AND year=?')->execute([(int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '건강검진 기록이 삭제되었습니다.';
            } elseif ($action === 'save_env') {
                $mDate = trim((string)($_POST['measure_date'] ?? ''));
                if ($mDate === '') throw new InvalidArgumentException('측정일자를 입력해 주세요.');
                $db->prepare('INSERT INTO hmg_env_measurements (year, half_label, measure_date, agency, work_area, hazard_factors, result_status, action_note, action_done_date, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, trim((string)($_POST['half_label'] ?? '상반기')), $mDate,
                       trim((string)($_POST['agency'] ?? '')),
                       trim((string)($_POST['work_area'] ?? '')),
                       trim((string)($_POST['hazard_factors'] ?? '')),
                       trim((string)($_POST['result_status'] ?? '적합(노출기준 미만)')),
                       trim((string)($_POST['action_note'] ?? '')),
                       trim((string)($_POST['action_done_date'] ?? '')),
                       $actor, $now, $now,
                   ]);
                $flashSuccess = '작업환경측정 기록이 등록되었습니다.';
            } elseif ($action === 'delete_env') {
                $db->prepare('DELETE FROM hmg_env_measurements WHERE id=? AND year=?')->execute([(int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '작업환경측정 기록이 삭제되었습니다.';
            } elseif ($action === 'save_chemical') {
                $cName = trim((string)($_POST['chem_name'] ?? ''));
                if ($cName === '') throw new InvalidArgumentException('화학물질명을 입력해 주세요.');
                $db->prepare('INSERT INTO hmg_chemicals (sort_order, chem_name, manufacturer, usage_process, storage_place, msds_rev_date, msds_placed, ghs_labeled, hazard_class, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                   ->execute([
                       (int)($_POST['sort_order'] ?? 10), $cName,
                       trim((string)($_POST['manufacturer'] ?? '')),
                       trim((string)($_POST['usage_process'] ?? '')),
                       trim((string)($_POST['storage_place'] ?? '')),
                       trim((string)($_POST['msds_rev_date'] ?? '')),
                       trim((string)($_POST['msds_placed'] ?? '비치완료')),
                       trim((string)($_POST['ghs_labeled'] ?? '부착완료')),
                       trim((string)($_POST['hazard_class'] ?? '')),
                       $now, $now,
                   ]);
                $flashSuccess = '화학물질·MSDS 항목이 등록되었습니다.';
            } elseif ($action === 'delete_chemical') {
                $db->prepare('UPDATE hmg_chemicals SET active=0, updated_at=? WHERE id=?')->execute([$now, (int)($_POST['id'] ?? 0)]);
                $flashSuccess = '화학물질 항목이 목록에서 제외되었습니다.';
            } elseif ($action === 'save_chem_check') {
                $vals = [
                    (string)($_POST['chk_msds_latest'] ?? '적합'),
                    (string)($_POST['chk_ghs_label'] ?? '적합'),
                    (string)($_POST['chk_small_container'] ?? '적합'),
                    (string)($_POST['chk_vent_lock'] ?? '적합'),
                    (string)($_POST['chk_spill_kit'] ?? '적합'),
                    (string)($_POST['chk_segregation'] ?? '적합'),
                ];
                $overall = in_array('보완필요', $vals, true) ? '보완필요' : '적합';
                $p1 = hmg_save_photo_file($_FILES['photo1'] ?? null, (string)($_POST['photo1_base64'] ?? ''), 'chem_p1');
                $p2 = hmg_save_photo_file($_FILES['photo2'] ?? null, (string)($_POST['photo2_base64'] ?? ''), 'chem_p2');
                $db->prepare('INSERT INTO hmg_chem_checks (year, check_date, inspector, location, chk_msds_latest, chk_ghs_label, chk_small_container, chk_vent_lock, chk_spill_kit, chk_segregation, overall_result, issue_note, action_note, photo1, photo2, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, trim((string)($_POST['check_date'] ?? date('Y-m-d'))),
                       trim((string)($_POST['inspector'] ?? $actor)),
                       trim((string)($_POST['location'] ?? '현대기전 자재창고 화학물질 보관함')),
                       ...$vals, $overall,
                       trim((string)($_POST['issue_note'] ?? '')),
                       trim((string)($_POST['action_note'] ?? '')),
                       $p1, $p2, $actor, $now, $now,
                   ]);
                $flashSuccess = '2.8.4 화학물질 보관·GHS 경고표지 점검이 등록되었습니다.';
            } elseif ($action === 'delete_chem_check') {
                $db->prepare('DELETE FROM hmg_chem_checks WHERE id=? AND year=?')->execute([(int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '화학물질 보관 점검 기록이 삭제되었습니다.';
            } elseif ($action === 'save_first_aid') {
                $vals = [
                    (string)($_POST['chk_med_stock'] ?? '적합'),
                    (string)($_POST['chk_expiry'] ?? '적합'),
                    (string)($_POST['chk_equip_state'] ?? '적합'),
                    (string)($_POST['chk_ledger'] ?? '적합'),
                ];
                $overall = in_array('보완필요', $vals, true) ? '보완필요' : '적합';
                $p1 = hmg_save_photo_file($_FILES['photo1'] ?? null, (string)($_POST['photo1_base64'] ?? ''), 'aid_p1');
                $p2 = hmg_save_photo_file($_FILES['photo2'] ?? null, (string)($_POST['photo2_base64'] ?? ''), 'aid_p2');
                $db->prepare('INSERT INTO hmg_first_aid_checks (year, check_date, inspector, location, set_count, chk_med_stock, chk_expiry, chk_equip_state, chk_ledger, overall_result, refill_note, photo1, photo2, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([
                       $year, trim((string)($_POST['check_date'] ?? date('Y-m-d'))),
                       trim((string)($_POST['inspector'] ?? $actor)),
                       trim((string)($_POST['location'] ?? '본사사무실 / 현장사무실 / 가스사무실 (3SET)')),
                       trim((string)($_POST['set_count'] ?? '3 SET')),
                       ...$vals, $overall,
                       trim((string)($_POST['refill_note'] ?? '')),
                       $p1, $p2, $actor, $now, $now,
                   ]);
                $flashSuccess = '2.8.5 응급처치 장비·의약품 점검이 등록되었습니다.';
            } elseif ($action === 'delete_first_aid') {
                $db->prepare('DELETE FROM hmg_first_aid_checks WHERE id=? AND year=?')->execute([(int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '응급처치 점검 기록이 삭제되었습니다.';
            } elseif ($action === 'upload_evidence') {
                hmg_upload_evidence($db, $year, (string)($_POST['item_code'] ?? ''), (string)($_POST['kind'] ?? ''), $_FILES['evidence_file'] ?? null, (string)($_POST['evidence_base64'] ?? ''), trim((string)($_POST['note'] ?? '')), $actor);
                $flashSuccess = (string)($_POST['item_code'] ?? '') . ' 증빙 자료가 등록되었습니다.';
            } elseif ($action === 'delete_evidence') {
                $db->prepare('UPDATE hmg_evidence SET deleted_at=? WHERE id=? AND year=?')->execute([$now, (int)($_POST['id'] ?? 0), $year]);
                $flashSuccess = '증빙 파일이 삭제되었습니다.';
            } elseif ($action === 'save_assessment') {
                $ic = (string)($_POST['item_code'] ?? '');
                if (!isset(hmg_item_meta()[$ic])) throw new InvalidArgumentException('항목 확인 필요');
                $mv = (string)($_POST['multiplier'] ?? 'auto');
                if ($mv === 'auto') {
                    $db->prepare('DELETE FROM hmg_assessments WHERE year=? AND item_code=?')->execute([$year, $ic]);
                } else {
                    $m = (float)$mv;
                    if (!in_array($m, [1.0, 0.9, 0.8, 0.5, 0.0], true)) throw new InvalidArgumentException('평가 수준 오류');
                    $db->prepare('INSERT INTO hmg_assessments (year, item_code, multiplier, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(year, item_code) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
                       ->execute([$year, $ic, $m, trim((string)($_POST['note'] ?? '')), $actor, $now]);
                }
                $flashSuccess = $ic . ' 평가 수준이 저장되었습니다.';
            }
        } catch (Throwable $ex) {
            $flashError = $ex->getMessage();
        }
    }
}

$checkups = $db->prepare('SELECT * FROM hmg_checkups WHERE year=? ORDER BY id ASC');
$checkups->execute([$year]);
$checkups = $checkups->fetchAll();

$envRows = $db->prepare('SELECT * FROM hmg_env_measurements WHERE year=? ORDER BY measure_date DESC, id DESC');
$envRows->execute([$year]);
$envRows = $envRows->fetchAll();

$chemicals = $db->query('SELECT * FROM hmg_chemicals WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchAll();
$linkedMsds = hmg_load_linked_msds();

$chemChecks = $db->prepare('SELECT * FROM hmg_chem_checks WHERE year=? ORDER BY check_date DESC, id DESC');
$chemChecks->execute([$year]);
$chemChecks = $chemChecks->fetchAll();

$aidChecks = $db->prepare('SELECT * FROM hmg_first_aid_checks WHERE year=? ORDER BY check_date DESC, id DESC');
$aidChecks->execute([$year]);
$aidChecks = $aidChecks->fetchAll();

$evStmt = $db->prepare('SELECT * FROM hmg_evidence WHERE year=? AND deleted_at IS NULL ORDER BY item_code ASC, id DESC');
$evStmt->execute([$year]);
$evByItem = ['2.8.1' => [], '2.8.2' => [], '2.8.3' => [], '2.8.4' => [], '2.8.5' => []];
foreach ($evStmt->fetchAll() as $row) {
    if (isset($evByItem[$row['item_code']])) $evByItem[$row['item_code']][] = $row;
}

$itemMeta = hmg_item_meta();
$evKinds = hmg_evidence_kinds();
$scores = [];
$totalEarned = 0.0;
foreach (array_keys($itemMeta) as $code) {
    $scores[$code] = csm_hmg_score_summary($year, $code, 2.0);
    $totalEarned += $scores[$code]['earned'];
}
$totalEarned = round($totalEarned, 2);

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
function hmg_fmt_size(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1) . 'MB' : ($b >= 1024 ? number_format($b / 1024, 1) . 'KB' : $b . 'B'); }

function hmg_render_ev(string $code, string $tabName, int $year, array $items, array $score, array $kinds, array $meta, bool $canManage, string $csrf, callable $e): void
{
    $curM = $score['assessment'] !== null ? (string)$score['assessment']['multiplier'] : 'auto';
    $curN = $score['assessment']['note'] ?? '';
    ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h3>📎 <?= $e($code) ?> 증빙 자료 및 점수 관리</h3>
          <p class="sub">필요 증빙: <?= $e($meta['evidence_guide']) ?></p>
        </div>
        <div class="score-pill <?= $score['earned'] >= 2.0 ? 'ok' : ($score['evidence_count'] > 0 ? 'warn' : 'empty') ?>">
          현재 점수 <strong><?= number_format($score['earned'], 1) ?> / 2.0점</strong> (증빙·사진 <?= (int)$score['evidence_count'] ?>건)
        </div>
      </div>
      <div class="ev-grid">
        <div>
          <?php if ($canManage): ?>
          <form method="post" enctype="multipart/form-data" class="ev-upload-form">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="upload_evidence">
            <input type="hidden" name="tab" value="<?= $e($tabName) ?>">
            <input type="hidden" name="item_code" value="<?= $e($code) ?>">
            <input type="hidden" name="evidence_base64" class="js-paste-b64" value="">
            <div class="form-row">
              <div class="field"><label>증빙 구분</label><select name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label>파일 선택 (최대 20MB)</label><input type="file" name="evidence_file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.docx"></div>
            </div>
            <div class="paste-zone js-paste-zone" tabindex="0">
              <span class="js-paste-label">📋 클릭 후 <strong>Ctrl+V</strong> 로 스크린샷 붙여넣기</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="미리보기" style="display:none;">
            </div>
            <div class="form-row" style="margin-top:8px;">
              <div class="field" style="flex:1;"><input type="text" name="note" placeholder="증빙 설명 (선택)"></div>
              <button type="submit" class="btn primary">증빙 등록</button>
            </div>
          </form>
          <?php endif; ?>
          <div class="ev-list">
            <?php if (!$items): ?><div class="empty-msg">등록된 증빙 파일이 없습니다.</div><?php else: foreach ($items as $ev): ?>
              <div class="ev-item">
                <div class="ev-info">
                  <span class="badge"><?= $e($kinds[$ev['kind']] ?? $ev['kind']) ?></span>
                  <a href="?download_evidence=<?= (int)$ev['id'] ?>&amp;inline=1" target="_blank" class="ev-name"><?= $e($ev['original_name']) ?></a>
                  <span class="ev-meta"><?= hmg_fmt_size((int)$ev['size']) ?> · <?= $e($ev['uploaded_by']) ?> · <?= $e(substr((string)$ev['uploaded_at'], 0, 10)) ?></span>
                  <?php if ($ev['note'] !== ''): ?><div class="ev-note"><?= $e($ev['note']) ?></div><?php endif; ?>
                </div>
                <div style="display:flex;gap:6px;">
                  <a href="?download_evidence=<?= (int)$ev['id'] ?>" class="btn sm ghost">다운로드</a>
                  <?php if ($canManage): ?>
                  <form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_evidence"><input type="hidden" name="tab" value="<?= $e($tabName) ?>"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                    <button type="submit" class="btn sm danger">삭제</button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="assess-box">
          <h4>평가 수준 설정 (<?= $e($code) ?>)</h4>
          <?php if ($canManage): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_assessment"><input type="hidden" name="tab" value="<?= $e($tabName) ?>"><input type="hidden" name="item_code" value="<?= $e($code) ?>">
            <div class="field"><label>평가 수준</label>
              <select name="multiplier">
                <option value="auto" <?= $curM === 'auto' ? 'selected' : '' ?>>자동 (증빙 등록 시 100% · 2.0점)</option>
                <option value="1" <?= $curM === '1' || $curM === '1.0' ? 'selected' : '' ?>>100% (2.0점) — 충족</option>
                <option value="0.9" <?= $curM === '0.9' ? 'selected' : '' ?>>90% (1.8점) — 우수</option>
                <option value="0.8" <?= $curM === '0.8' ? 'selected' : '' ?>>80% (1.6점) — 미흡</option>
                <option value="0.5" <?= $curM === '0.5' ? 'selected' : '' ?>>50% (1.0점)</option>
                <option value="0" <?= $curM === '0' || $curM === '0.0' ? 'selected' : '' ?>>0% (0.0점)</option>
              </select>
            </div>
            <div class="field" style="margin-top:8px;"><label>검토 메모</label><textarea name="note" rows="2"><?= $e($curN) ?></textarea></div>
            <button type="submit" class="btn primary" style="margin-top:8px;width:100%;">평가 저장</button>
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
<title>2.8 보건·건강·작업환경·화학물질 관리 · CSM</title>
<style>
  :root { --bg:#f4f7f8; --card:#fff; --line:#d8e2e7; --ink:#152530; --muted:#5a6c77; --brand:#0369a1; --brand-dark:#075985; --ok:#15803d; --ok-bg:#dcfce7; --warn:#b45309; --warn-bg:#fef3c7; --danger:#b91c1c; --danger-bg:#fee2e2; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg); color:var(--ink); font-size:14px; line-height:1.5; }
  a { color:inherit; text-decoration:none; }
  .topbar { background:#0c354c; color:#fff; padding:0 20px; height:54px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; }
  .topbar-left { display:flex; align-items:center; gap:12px; }
  .topbar-back { color:#bae6fd; font-size:13px; padding:5px 10px; border-radius:6px; background:rgba(255,255,255,0.1); }
  .topbar-title { font-size:16px; font-weight:800; }
  .year-select { background:rgba(255,255,255,0.14); color:#fff; border:1px solid rgba(255,255,255,0.28); border-radius:6px; padding:5px 10px; font-weight:700; }
  .year-select option { color:#111; }
  .wrap { max-width:1440px; margin:0 auto; padding:20px 20px 60px; }
  .hero { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-end; gap:14px; margin-bottom:18px; }
  .hero small { color:var(--brand); font-weight:800; font-size:12px; }
  .hero h1 { margin:4px 0; font-size:24px; font-weight:900; }
  .hero p { margin:0; color:var(--muted); font-size:13px; }
  .print-bar { display:flex; flex-wrap:wrap; gap:8px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 13px; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink); font-weight:700; font-size:12.5px; cursor:pointer; white-space:nowrap; }
  .btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
  .btn.danger { border-color:#fecaca; color:var(--danger); }
  .btn.ghost { background:#f8fafc; }
  .btn.sm { padding:5px 10px; font-size:12px; border-radius:6px; }
  .flash { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-weight:700; font-size:13px; }
  .flash.ok { background:var(--ok-bg); color:var(--ok); }
  .flash.err { background:var(--danger-bg); color:var(--danger); }
  .kpi-grid { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:10px; margin-bottom:18px; }
  .kpi { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:3px; }
  .kpi.total { background:linear-gradient(135deg,#0369a1,#0c4a6e); color:#fff; border-color:#0369a1; }
  .kpi-code { font-size:11.5px; font-weight:800; color:var(--brand); }
  .kpi.total .kpi-code { color:#bae6fd; }
  .kpi-title { font-size:12.5px; font-weight:700; }
  .kpi-val { font-size:19px; font-weight:900; }
  .kpi-sub { font-size:11.5px; color:var(--muted); }
  .kpi.total .kpi-sub { color:#e0f2fe; }
  .tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:18px; border-bottom:2px solid var(--line); }
  .tab-link { padding:10px 15px; font-weight:800; font-size:13.5px; color:var(--muted); border-bottom:3px solid transparent; margin-bottom:-2px; }
  .tab-link.active { color:var(--brand); border-bottom-color:var(--brand); background:#fff; border-radius:8px 8px 0 0; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px 20px; margin-bottom:18px; }
  .card-head { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #edf2f5; }
  .card-head h2, .card-head h3 { margin:0; font-size:16px; font-weight:900; }
  .sub { margin:2px 0 0; font-size:12.5px; color:var(--muted); }
  .tbl-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:10px; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th, td { padding:8px 10px; border-bottom:1px solid #e9eff2; text-align:left; vertical-align:middle; }
  th { background:#f1f6f9; font-weight:800; white-space:nowrap; }
  .ctr { text-align:center; }
  .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:800; background:#e0f2fe; color:#0369a1; }
  .badge.ok { background:var(--ok-bg); color:var(--ok); }
  .badge.warn { background:var(--warn-bg); color:var(--warn); }
  .form-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
  .form-grid.cols-2 { grid-template-columns:repeat(2, minmax(0, 1fr)); }
  .form-grid.cols-4 { grid-template-columns:repeat(4, minmax(0, 1fr)); }
  .form-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
  .field { display:flex; flex-direction:column; gap:4px; }
  .field.span-2 { grid-column:span 2; }
  .field label { font-size:12px; font-weight:800; color:#334e5c; }
  .field input[type="text"], .field input[type="date"], .field input[type="number"], .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid #c7d5de; border-radius:8px; font-family:inherit; font-size:13px; background:#fff; }
  .paste-zone { margin-top:8px; padding:10px 12px; border:2px dashed #7dd3fc; border-radius:10px; background:#f0f9ff; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px; font-size:12.5px; color:#075985; cursor:pointer; }
  .paste-preview { max-height:110px; border-radius:6px; border:1px solid #c7d5de; margin-top:6px; }
  .score-pill { padding:6px 12px; border-radius:999px; font-size:13px; font-weight:700; }
  .score-pill.ok { background:var(--ok-bg); color:var(--ok); }
  .score-pill.warn { background:var(--warn-bg); color:var(--warn); }
  .score-pill.empty { background:#eef2f5; color:var(--muted); }
  .ev-grid { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:18px; align-items:start; }
  .ev-upload-form { background:#f8fbfc; border:1px solid var(--line); border-radius:10px; padding:12px; margin-bottom:12px; }
  .ev-list { display:flex; flex-direction:column; gap:8px; }
  .ev-item { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--line); border-radius:9px; background:#fff; }
  .ev-info { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
  .ev-name { font-weight:800; color:var(--brand-dark); }
  .ev-meta { font-size:12px; color:var(--muted); }
  .ev-note { width:100%; font-size:12px; color:#475569; }
  .assess-box { background:#f8fbfc; border:1px solid var(--line); border-radius:10px; padding:14px; }
  .empty-msg { padding:16px; text-align:center; color:var(--muted); background:#f9fbfc; border:1px dashed var(--line); border-radius:10px; }
  .radio-grp { display:flex; gap:10px; }
  .radio-grp label { display:inline-flex; align-items:center; gap:4px; font-weight:700; font-size:12.5px; cursor:pointer; }
  .photo-thumbs { display:flex; gap:8px; }
  .photo-thumb { width:72px; height:54px; object-fit:cover; border-radius:6px; border:1px solid #c7d5de; }
  @media (max-width:1200px) { .kpi-grid { grid-template-columns:repeat(3,1fr); } .ev-grid { grid-template-columns:1fr; } }
  @media (max-width:720px) { .kpi-grid, .form-grid, .form-grid.cols-2, .form-grid.cols-4 { grid-template-columns:1fr; } .field.span-2 { grid-column:span 1; } }
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a class="topbar-back" href="/CSM/area.php?group=2&amp;section=2.8">← 2.8 영역</a>
    <a class="topbar-back" href="/CSM/">CSM 포털</a>
    <span class="topbar-title">2.8 보건·건강·작업환경·화학물질 관리</span>
  </div>
  <div style="display:flex;align-items:center;gap:10px;">
    <form method="get" style="display:inline;">
      <input type="hidden" name="tab" value="<?= $e($tab) ?>">
      <select name="year" class="year-select" onchange="this.form.submit()">
        <?php for ($y = $nowYear + 1; $y >= 2024; $y--): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년</option><?php endfor; ?>
      </select>
    </form>
    <span><?= $e($actor) ?></span>
  </div>
</header>

<main class="wrap">
  <div class="hero">
    <div>
      <small>CSM 평가 영역 2 · 2.8 보건 · 2.8.1 ~ 2.8.5 (총 10.0점)</small>
      <h1><?= $year ?>년 건강검진·작업환경측정·화학물질·구급용품 관리</h1>
      <p>주식회사 현대기전의 건강검진(2.8.1), 작업환경측정(2.8.2), 화학물질·MSDS(2.8.3), 보관·GHS표지(2.8.4), 응급처치장비·의약품(2.8.5)을 통합 관리합니다.</p>
    </div>
    <div class="print-bar">
      <a class="btn" href="health_management_print.php?mode=checkup&amp;year=<?= $year ?>" target="_blank">🖨️ 2.8.1 검진대장</a>
      <a class="btn" href="health_management_print.php?mode=env&amp;year=<?= $year ?>" target="_blank">🖨️ 2.8.2 환경측정</a>
      <a class="btn" href="health_management_print.php?mode=msds&amp;year=<?= $year ?>" target="_blank">🖨️ 2.8.3 MSDS대장</a>
      <a class="btn" href="health_management_print.php?mode=chem_check&amp;year=<?= $year ?>" target="_blank">🖨️ 2.8.4 보관점검표</a>
      <a class="btn" href="health_management_print.php?mode=first_aid&amp;year=<?= $year ?>" target="_blank">🖨️ 2.8.5 구급용품점검</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash ok">✅ <?= $e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash err">⚠️ <?= $e($flashError) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <?php foreach ($itemMeta as $code => $m): ?>
    <a class="kpi" href="?year=<?= $year ?>&amp;tab=<?= $e($m['tab']) ?>">
      <span class="kpi-code"><?= $e($code) ?> (2.0점)</span>
      <span class="kpi-title"><?= $e($m['short']) ?></span>
      <span class="kpi-val"><?= number_format($scores[$code]['earned'], 1) ?> / 2.0점</span>
      <span class="kpi-sub">증빙·사진 <?= (int)$scores[$code]['evidence_count'] ?>건</span>
    </a>
    <?php endforeach; ?>
    <a class="kpi total" href="?year=<?= $year ?>&amp;tab=evidence">
      <span class="kpi-code">2.8 보건 합계</span>
      <span class="kpi-title">포털 실시간 반영</span>
      <span class="kpi-val"><?= number_format($totalEarned, 1) ?> / 10.0점</span>
      <span class="kpi-sub">달성률 <?= number_format(($totalEarned / 10.0) * 100, 1) ?>%</span>
    </a>
  </section>

  <nav class="tabs">
    <a class="tab-link <?= $tab === 'checkup' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=checkup">2.8.1 건강검진</a>
    <a class="tab-link <?= $tab === 'env' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=env">2.8.2 작업환경측정</a>
    <a class="tab-link <?= $tab === 'msds' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=msds">2.8.3 화학물질·MSDS</a>
    <a class="tab-link <?= $tab === 'chem_check' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=chem_check">2.8.4 보관·GHS 점검</a>
    <a class="tab-link <?= $tab === 'first_aid' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=first_aid">2.8.5 응급장비·의약품</a>
    <a class="tab-link <?= $tab === 'evidence' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=evidence">📎 2.8 통합 증빙·점수</a>
  </nav>

  <?php if ($tab === 'checkup'):
    $isNormalRow = static function (array $r): bool {
        $g = strtoupper(trim((string)($r['general_grade'] ?? '')));
        $s = strtoupper(trim((string)($r['special_grade'] ?? '')));
        $rg = (string)($r['result_grade'] ?? '');
        $hasAbn = str_contains($g, 'D') || str_contains($g, 'C') || str_contains($s, 'D') || str_contains($s, 'C')
            || str_contains($rg, '유소견') || str_contains($rg, '요관찰') || str_contains($rg, '확인');
        return !$hasAbn && ($g !== '' || $s !== '');
    };
    $cntD = count(array_filter($checkups, static fn($r) => str_contains(strtoupper((string)$r['general_grade'] . ' ' . (string)$r['special_grade']), 'D') || str_contains((string)$r['result_grade'], '유소견')));
    $cntC = count(array_filter($checkups, static fn($r) => !str_contains(strtoupper((string)$r['general_grade'] . ' ' . (string)$r['special_grade']), 'D') && (str_contains(strtoupper((string)$r['general_grade'] . ' ' . (string)$r['special_grade']), 'C') || str_contains((string)$r['result_grade'], '요관찰'))));
    $cntNormal = count(array_filter($checkups, $isNormalRow));
    $followupTargets = array_filter($checkups, static fn($r) => !$isNormalRow($r));
    $cntDone = count(array_filter($followupTargets, static fn($r) => (int)($r['followup_done'] ?? 0) === 1));
  ?>
    <section class="card" style="background:#f0f9ff;border-color:#bae6fd;">
      <div class="card-head" style="margin-bottom:8px;padding-bottom:8px;">
        <div>
          <h2><?= $year ?>년 일반·특수 건강진단 결과표 및 사후관리 총괄</h2>
          <p class="sub">검진기관: 근로복지공단 동해병원 (일반 3/9~3/12 · 특수 1차 3/9~3/12, 2차 3/18~3/20) · 사후관리: 강원근로자건강센터 (9/17 상담 8명)</p>
        </div>
        <a class="btn sm primary" href="health_management_print.php?mode=checkup&amp;year=<?= $year ?>" target="_blank">🖨️ 사후관리 대장 A4 가로 출력</a>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:13px;">
        <span>📋 수검 인원: <strong><?= count($checkups) ?>명</strong></span>
        <span>🟢 정상(조치불필요): <strong style="color:var(--ok);"><?= $cntNormal ?>명</strong></span>
        <span>🔴 유소견(D1/D2/DN): <strong style="color:var(--danger);"><?= $cntD ?>명</strong></span>
        <span>🟠 요관찰(C1/C2): <strong style="color:var(--warn);"><?= $cntC ?>명</strong></span>
        <span>✅ 사후관리 조치·상담 완료: <strong style="color:var(--ok);"><?= $cntDone ?> / <?= count($followupTargets) ?>명</strong></span>
      </div>
    </section>

    <?php if ($canManage): ?>
    <section class="card" style="border-color:#93c5fd;">
      <div class="card-head" style="margin-bottom:10px;padding-bottom:8px;">
        <div>
          <h3>📄 건강검진·사후관리 PDF 자동 분석 및 일괄 반영 (로컬 OCR · 외부 전송 없음)</h3>
          <p class="sub">병원에서 받은 <strong>사후관리 소견서(일반/특수 PDF)</strong> 또는 <strong>사후관리 조치 확인서 PDF</strong>를 올리면 서버 내부에서 자동 판독하여 미리보기 후 한 번에 반영합니다.</p>
        </div>
      </div>
      <form method="post" enctype="multipart/form-data" class="form-row" onsubmit="this.querySelector('button[type=submit]').textContent='⏳ 업로드 및 분석 시작 중...';">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="parse_checkup_pdf"><input type="hidden" name="tab" value="checkup">
        <div class="field" style="flex:1;min-width:260px;">
          <input type="file" name="checkup_pdf" accept=".pdf,application/pdf" required>
        </div>
        <button type="submit" class="btn primary">🔍 PDF 자동 분석 (미리보기)</button>
      </form>

      <?php if (is_array($pdfJob) && (int)($pdfJob['year'] ?? 0) === $year): ?>
      <div id="pdfJobBox" style="margin-top:14px;padding:14px;border:1px solid #93c5fd;border-radius:10px;background:#eff6ff;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
          <div>
            <strong>⏳ 로컬 OCR 분석 진행 중: <?= $e($pdfJob['orig_name']) ?></strong>
            <span id="pdfJobMsg" class="sub" style="margin-left:8px;">진행 상태 확인 중...</span>
          </div>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="cancel_checkup_pdf"><input type="hidden" name="tab" value="checkup">
            <button type="submit" class="btn sm ghost">취소</button>
          </form>
        </div>
        <div style="height:10px;background:#dbeafe;border-radius:999px;overflow:hidden;">
          <div id="pdfJobBar" style="height:100%;width:5%;background:var(--brand);transition:width .4s ease;"></div>
        </div>
        <div id="pdfJobErr" style="display:none;margin-top:8px;color:var(--danger);font-size:13px;font-weight:700;"></div>
      </div>
      <script>
      (function(){
        const token = <?= json_encode((string)$pdfJob['token']) ?>;
        const statusUrl = '?tab=checkup&year=<?= $year ?>&pdf_job_status=1&token=' + encodeURIComponent(token);
        const msgEl = document.getElementById('pdfJobMsg');
        const barEl = document.getElementById('pdfJobBar');
        const errEl = document.getElementById('pdfJobErr');
        async function poll() {
          try {
            const res = await fetch(statusUrl, {cache: 'no-store'});
            const d = await res.json();
            if (d.status === 'done') {
              barEl.style.width = '100%';
              msgEl.textContent = '분석 완료! 미리보기를 불러오는 중...';
              window.location.href = '?tab=checkup&year=<?= $year ?>';
              return;
            }
            if (d.status === 'error' || d.status === 'expired') {
              errEl.style.display = 'block';
              errEl.textContent = '⚠️ ' + (d.error || '분석 중 오류가 발생했습니다.');
              msgEl.textContent = '중단됨';
              return;
            }
            const total = d.total || 0;
            const done = d.done || 0;
            const pct = total > 0 ? Math.max(5, Math.min(98, Math.round((done / total) * 100))) : 10;
            barEl.style.width = pct + '%';
            msgEl.textContent = (d.message || '로컬 OCR 분석 중...') + (d.elapsed ? ' · ' + d.elapsed + '초 경과' : '');
          } catch (e) {
            msgEl.textContent = '상태 확인 재시도 중...';
          }
          setTimeout(poll, 2000);
        }
        setTimeout(poll, 1000);
      })();
      </script>
      <?php endif; ?>

      <?php if (is_array($pdfPreview) && (int)($pdfPreview['year'] ?? 0) === $year): ?>
      <div style="margin-top:14px;padding-top:14px;border-top:2px dashed #93c5fd;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
          <div>
            <strong>✅ 분석 완료: <?= $e($pdfPreview['orig_name']) ?></strong>
            <span class="badge"><?= $e($pdfPreview['doc_label']) ?></span>
            <span class="sub">추출 인원 <?= count($pdfPreview['records']) ?>명 · 오인식된 글자가 있으면 아래에서 바로 수정 후 [일괄 반영]을 누르세요.</span>
          </div>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="cancel_checkup_pdf"><input type="hidden" name="tab" value="checkup">
            <button type="submit" class="btn sm ghost">취소</button>
          </form>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="apply_checkup_pdf"><input type="hidden" name="tab" value="checkup">
          <input type="hidden" name="preview_token" value="<?= $e($pdfPreview['token']) ?>">
          <input type="hidden" name="doc_type" value="<?= $e($pdfPreview['doc_type']) ?>">
          <div class="tbl-wrap" style="max-height:420px;overflow:auto;">
            <table>
              <thead><tr><th class="ctr">반영</th><th>성명</th><th>부서</th><th>일반</th><th>특수</th><th>적합</th><th>주요 소견</th><th>사후관리 조치</th><th class="ctr">완료</th></tr></thead>
              <tbody>
                <?php foreach ($pdfPreview['records'] as $idx => $pr): ?>
                <tr>
                  <td class="ctr"><input type="checkbox" name="rows[<?= $idx ?>][include]" value="1" checked></td>
                  <td><input type="text" name="rows[<?= $idx ?>][worker_name]" value="<?= $e($pr['worker_name']) ?>" style="width:76px;padding:4px 6px;"></td>
                  <td><input type="text" name="rows[<?= $idx ?>][dept_name]" value="<?= $e($pr['dept_name']) ?>" style="width:100px;padding:4px 6px;"></td>
                  <td><input type="text" name="rows[<?= $idx ?>][general_grade]" value="<?= $e($pr['general_grade']) ?>" style="width:52px;padding:4px 6px;"></td>
                  <td><input type="text" name="rows[<?= $idx ?>][special_grade]" value="<?= $e($pr['special_grade']) ?>" style="width:70px;padding:4px 6px;"></td>
                  <td><input type="text" name="rows[<?= $idx ?>][work_fitness]" value="<?= $e($pr['work_fitness']) ?>" style="width:36px;padding:4px 6px;"></td>
                  <td>
                    <input type="hidden" name="rows[<?= $idx ?>][result_grade]" value="<?= $e($pr['result_grade']) ?>">
                    <input type="hidden" name="rows[<?= $idx ?>][exam_date]" value="<?= $e($pr['exam_date']) ?>">
                    <input type="hidden" name="rows[<?= $idx ?>][hospital]" value="<?= $e($pr['hospital']) ?>">
                    <input type="text" name="rows[<?= $idx ?>][finding_summary]" value="<?= $e($pr['finding_summary']) ?>" style="width:220px;padding:4px 6px;">
                  </td>
                  <td><input type="text" name="rows[<?= $idx ?>][followup_note]" value="<?= $e($pr['followup_note']) ?>" style="width:220px;padding:4px 6px;"></td>
                  <td class="ctr"><input type="checkbox" name="rows[<?= $idx ?>][followup_done]" value="1" <?= !empty($pr['followup_done']) ? 'checked' : '' ?>></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <label style="font-size:13px;font-weight:700;"><input type="checkbox" name="attach_evidence" value="1" checked> 이 PDF 원본 파일도 2.8.1 증빙 자료에 자동 첨부하기</label>
            <button type="submit" class="btn primary">✅ 선택한 인원 관리대장에 일괄 반영</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $year ?>년 근로자 건강진단 및 사후관리 관리대장 (<?= count($checkups) ?>명)</h2>
          <p class="sub">질병유소견자(D1/D2/DN) 및 요관찰자(C1/C2)의 검진소견·업무적합성(가/나)·사후관리 조치 이행을 개별 추적 관리합니다.</p>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          <button type="button" class="btn sm ghost" onclick="filterCheckups('all')">전체</button>
          <button type="button" class="btn sm ghost" onclick="filterCheckups('d')">유소견(D)</button>
          <button type="button" class="btn sm ghost" onclick="filterCheckups('c')">요관찰(C)</button>
          <button type="button" class="btn sm ghost" onclick="filterCheckups('pending')">조치 필요</button>
          <?php if ($canManage): ?>
            <button type="button" class="btn sm primary" onclick="document.getElementById('addCheckupBox').style.display=document.getElementById('addCheckupBox').style.display==='none'?'block':'none';">+ 대상자 추가</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canManage): ?>
      <form method="post" id="addCheckupBox" style="display:none;margin-bottom:14px;padding:12px;background:#f8fbfc;border:1px solid var(--line);border-radius:10px;">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_checkup"><input type="hidden" name="tab" value="checkup">
        <div class="form-grid cols-4">
          <div class="field"><label>성명 *</label><input type="text" name="worker_name" required></div>
          <div class="field"><label>부서/공정</label><input type="text" name="dept_name" value="공사부"></div>
          <div class="field"><label>일반 판정</label><input type="text" name="general_grade" value="A" placeholder="예: A, C, D2"></div>
          <div class="field"><label>특수 판정</label><input type="text" name="special_grade" value="A" placeholder="예: A, C1, C2, D1, DN"></div>
          <div class="field"><label>업무적합성</label><select name="work_fitness"><option value="가">가 (현재작업 가능)</option><option value="나">나 (조건부 가능)</option><option value="다">다 (한시적 불가)</option><option value="라">라 (작업 불가)</option></select></div>
          <div class="field"><label>종합 구분</label><select name="result_grade"><option value="정상(A/B)">정상(A/B)</option><option value="요관찰(C1)">요관찰(C1)</option><option value="요관찰(C2)">요관찰(C2)</option><option value="유소견(D2)">유소견(D2)</option><option value="유소견(DN)">유소견(DN)</option><option value="직업병유소견(D1)">직업병유소견(D1)</option></select></div>
          <div class="field"><label>검진일자</label><input type="date" name="exam_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>검진기관</label><input type="text" name="hospital" value="근로복지공단 동해병원"></div>
          <div class="field span-2"><label>주요 검진소견·권고</label><input type="text" name="finding_summary" placeholder="예: 소음성난청주의(C1), 고혈압 관리필요 등"></div>
          <div class="field span-2"><label>사후관리 조치 내용</label><input type="text" name="followup_note" placeholder="예: 청력보호구 착용 지도, 건강센터 상담 완료"></div>
        </div>
        <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;">
          <label style="font-size:12.5px;font-weight:700;"><input type="checkbox" name="followup_done" value="1"> 사후관리 조치 완료</label>
          <button type="submit" class="btn primary">등록</button>
        </div>
      </form>
      <?php endif; ?>

      <div class="tbl-wrap"><table id="checkupTable"><thead><tr><th class="ctr">No</th><th>부서</th><th>성명</th><th class="ctr">일반</th><th class="ctr">특수</th><th class="ctr">업무<br>적합</th><th>주요 검진소견 및 권고</th><th style="min-width:250px;">사후관리 조치 이행</th><th class="ctr">관리</th></tr></thead><tbody>
        <?php if (!$checkups): ?><tr><td colspan="9" class="ctr" style="padding:20px;color:var(--muted);">등록된 <?= $year ?>년 건강검진 기록이 없습니다.</td></tr>
        <?php else: foreach ($checkups as $i => $r):
          $norm = $isNormalRow($r);
          $gsUpper = strtoupper((string)$r['general_grade'] . ' ' . (string)$r['special_grade']);
          $isD = str_contains($gsUpper, 'D') || str_contains((string)$r['result_grade'], '유소견');
          $isC = !$isD && (str_contains($gsUpper, 'C') || str_contains((string)$r['result_grade'], '요관찰'));
          $done = (int)($r['followup_done'] ?? 0) === 1;
          $rowTag = ($isD ? 'd' : ($isC ? 'c' : 'a')) . ($norm ? ' normal' : ($done ? ' done' : ' pending'));
        ?>
          <tr data-tag="<?= $e($rowTag) ?>">
            <td class="ctr"><?= $i + 1 ?></td>
            <td><?= $e($r['dept_name'] ?? '') ?></td>
            <td><strong><?= $e($r['worker_name']) ?></strong><div class="sub"><?= $e($r['exam_date']) ?></div></td>
            <td class="ctr"><span class="badge <?= str_contains((string)$r['general_grade'],'D') ? 'warn' : '' ?>"><?= $e($r['general_grade'] ?: '-') ?></span></td>
            <td class="ctr"><span class="badge <?= str_contains((string)$r['special_grade'],'D') ? 'warn' : (str_contains((string)$r['special_grade'],'C') ? '' : 'ok') ?>"><?= $e($r['special_grade'] ?: '-') ?></span></td>
            <td class="ctr"><strong><?= $e($r['work_fitness'] ?: '가') ?></strong></td>
            <td style="font-size:12.5px;"><?= $e($r['finding_summary'] ?: $r['hazard_factors']) ?></td>
            <td>
              <?php if ($norm): ?>
                <span class="badge ok">정상 (조치 불필요)</span>
              <?php elseif ($canManage): ?>
              <form method="post" style="display:flex;flex-direction:column;gap:4px;">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="update_followup"><input type="hidden" name="tab" value="checkup"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="followup_note" value="<?= $e($r['followup_note']) ?>" style="padding:5px 7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                  <label style="font-size:11.5px;font-weight:700;color:<?= $done ? 'var(--ok)' : 'var(--warn)' ?>;"><input type="checkbox" name="followup_done" value="1" <?= $done ? 'checked' : '' ?>> <?= $done ? '조치·상담 완료' : '추적관리 중' ?></label>
                  <button type="submit" class="btn sm ghost">저장</button>
                </div>
              </form>
              <?php else: ?>
                <div><?= $e($r['followup_note']) ?></div>
                <span class="badge <?= $done ? 'ok' : 'warn' ?>"><?= $done ? '조치 완료' : '추적관리 중' ?></span>
              <?php endif; ?>
            </td>
            <td class="ctr"><?php if ($canManage): ?><form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_checkup"><input type="hidden" name="tab" value="checkup"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>
    <script>
    function filterCheckups(type) {
      document.querySelectorAll('#checkupTable tbody tr[data-tag]').forEach(tr => {
        const t = tr.dataset.tag || '';
        tr.style.display = (type === 'all' || t.split(' ').includes(type)) ? '' : 'none';
      });
    }
    </script>
    <?php hmg_render_ev('2.8.1', 'checkup', $year, $evByItem['2.8.1'], $scores['2.8.1'], $evKinds['2.8.1'], $itemMeta['2.8.1'], $canManage, $csrf, $e); ?>

  <?php elseif ($tab === 'env'): ?>
    <?php if ($canManage): ?>
    <section class="card">
      <div class="card-head"><div><h2>2.8.2 작업환경측정 결과 및 개선조치 등록</h2></div></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_env"><input type="hidden" name="tab" value="env">
        <div class="form-grid cols-4">
          <div class="field"><label>구분</label><select name="half_label"><option value="상반기">상반기</option><option value="하반기">하반기</option><option value="수시">수시</option></select></div>
          <div class="field"><label>측정일자 *</label><input type="date" name="measure_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label>측정기관</label><input type="text" name="agency" placeholder="예: 대한산업보건협회"></div>
          <div class="field"><label>측정 구역 / 공정</label><input type="text" name="work_area" placeholder="예: 옥계공장 전기·기계 보수작업"></div>
          <div class="field"><label>측정 유해인자</label><input type="text" name="hazard_factors" placeholder="예: 소음, 분진, 용접흄"></div>
          <div class="field"><label>측정 결과</label><select name="result_status"><option value="적합(노출기준 미만)">적합 (노출기준 미만)</option><option value="개선필요(기준 초과)">개선필요 (기준 초과)</option></select></div>
          <div class="field"><label>개선 조치 내용</label><input type="text" name="action_note" placeholder="환기강화·보호구 착용 등"></div>
          <div class="field"><label>개선 완료일</label><input type="date" name="action_done_date"></div>
        </div>
        <div style="margin-top:10px;text-align:right;"><button type="submit" class="btn primary">측정 결과 등록</button></div>
      </form>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="card-head"><div><h2><?= $year ?>년 작업환경측정 이력 (<?= count($envRows) ?>건)</h2></div><a class="btn sm primary" href="health_management_print.php?mode=env&amp;year=<?= $year ?>" target="_blank">🖨️ 대장 A4 출력</a></div>
      <div class="tbl-wrap"><table><thead><tr><th class="ctr">구분</th><th class="ctr">측정일</th><th>측정기관</th><th>측정 구역/공정</th><th>유해인자</th><th class="ctr">결과</th><th>개선조치</th><th class="ctr">관리</th></tr></thead><tbody>
        <?php if (!$envRows): ?><tr><td colspan="8" class="ctr" style="padding:20px;color:var(--muted);">등록된 <?= $year ?>년 작업환경측정 기록이 없습니다.</td></tr>
        <?php else: foreach ($envRows as $r): ?>
          <tr><td class="ctr"><?= $e($r['half_label']) ?></td><td class="ctr"><strong><?= $e($r['measure_date']) ?></strong></td><td><?= $e($r['agency']) ?></td><td><?= $e($r['work_area']) ?></td><td><?= $e($r['hazard_factors']) ?></td><td class="ctr"><span class="badge <?= str_contains($r['result_status'],'적합') ? 'ok' : 'warn' ?>"><?= $e($r['result_status']) ?></span></td><td><?= $e($r['action_note']) ?><?= $r['action_done_date'] ? ' (' . $e($r['action_done_date']) . ')' : '' ?></td><td class="ctr"><?php if ($canManage): ?><form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_env"><input type="hidden" name="tab" value="env"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>
    <?php hmg_render_ev('2.8.2', 'env', $year, $evByItem['2.8.2'], $scores['2.8.2'], $evKinds['2.8.2'], $itemMeta['2.8.2'], $canManage, $csrf, $e); ?>

  <?php elseif ($tab === 'msds'): ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2>2.8.3 취급 화학물질 및 MSDS 최신본 실시간 연동 대장 (<?= count($linkedMsds) ?>종)</h2>
          <p class="sub">기존 <strong>위험성평가 MSDS 자료실(`msds_records.json`)</strong>과 실시간 자동 연동됩니다. PDF 원문 보유 항목은 2.8.3 증빙으로 자동 반영됩니다.</p>
        </div>
        <div style="display:flex;gap:8px;">
          <a class="btn sm ghost" href="../risk_assessment/msds_list.php" target="_blank">📂 MSDS 자료실 등록·수정 ↗</a>
          <a class="btn sm primary" href="health_management_print.php?mode=msds&amp;year=<?= $year ?>" target="_blank">🖨️ MSDS 대장 A4 출력</a>
        </div>
      </div>
      <div class="tbl-wrap"><table><thead><tr><th class="ctr">No</th><th>화학물질명</th><th>제조사</th><th class="ctr">최초작성일</th><th class="ctr">최신 개정일</th><th class="ctr">개정차수</th><th>비고</th><th class="ctr">MSDS 원문</th></tr></thead><tbody>
        <?php if (!$linkedMsds): ?>
          <tr><td colspan="8" class="ctr" style="padding:20px;color:var(--muted);">연동된 MSDS 자료가 없습니다.</td></tr>
        <?php else: foreach ($linkedMsds as $i => $m): ?>
          <tr>
            <td class="ctr"><?= $i + 1 ?></td>
            <td><strong><?= $e($m['material_name']) ?></strong></td>
            <td><?= $e($m['manufacturer'] ?: '-') ?></td>
            <td class="ctr"><?= $e($m['created_date'] ?: '-') ?></td>
            <td class="ctr"><strong><?= $e($m['revised_date'] ?: '-') ?></strong></td>
            <td class="ctr"><?= $m['revision_count'] !== '' ? $e($m['revision_count']) . '차' : '-' ?></td>
            <td><?= $e($m['note'] ?: '-') ?></td>
            <td class="ctr" style="white-space:nowrap;">
              <?php if ($m['has_file']): ?>
                <a class="btn sm ghost" href="../risk_assessment/msds_list.php?download_file=<?= urlencode($m['id']) ?>" target="_blank">📄 PDF 다운로드</a>
              <?php else: ?>
                <span class="badge warn">파일 없음</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>

    <section class="card">
      <div class="card-head">
        <div><h3>추가 현장 취급 화학물질 메모 (선택 · <?= count($chemicals) ?>종)</h3><p class="sub">MSDS 자료실 외 현장에서 임시·추가 관리가 필요한 항목을 기록할 수 있습니다.</p></div>
      </div>
      <?php if ($canManage): ?>
      <form method="post" style="margin-bottom:14px;padding:12px;background:#f8fbfc;border:1px solid var(--line);border-radius:10px;">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_chemical"><input type="hidden" name="tab" value="msds">
        <div class="form-grid cols-4">
          <div class="field"><label>화학물질명 *</label><input type="text" name="chem_name" required placeholder="예: 방청유(WD-40)"></div>
          <div class="field"><label>제조사/공급사</label><input type="text" name="manufacturer"></div>
          <div class="field"><label>사용 공정</label><input type="text" name="usage_process"></div>
          <div class="field"><label>보관 장소</label><input type="text" name="storage_place" value="자재창고 화학물질 보관함"></div>
          <div class="field"><label>MSDS 최신 개정일</label><input type="date" name="msds_rev_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>유해성 분류</label><input type="text" name="hazard_class" placeholder="예: 인화성 액체"></div>
          <div class="field"><label>MSDS 비치 / GHS 표지</label><div style="display:flex;gap:6px;"><select name="msds_placed"><option value="비치완료">비치완료</option><option value="보완필요">보완필요</option></select><select name="ghs_labeled"><option value="부착완료">부착완료</option><option value="보완필요">보완필요</option></select></div></div>
          <div class="field" style="justify-content:flex-end;"><button type="submit" class="btn primary">+ 화학물질 추가</button></div>
        </div>
      </form>
      <?php endif; ?>
      <div class="tbl-wrap"><table><thead><tr><th class="ctr">No</th><th>화학물질명</th><th>제조사</th><th>사용 공정</th><th>보관장소</th><th class="ctr">MSDS 개정일</th><th class="ctr">MSDS 비치</th><th class="ctr">GHS 표지</th><th class="ctr">관리</th></tr></thead><tbody>
        <?php foreach ($chemicals as $i => $c): ?>
          <tr><td class="ctr"><?= $i + 1 ?></td><td><strong><?= $e($c['chem_name']) ?></strong><div class="sub"><?= $e($c['hazard_class']) ?></div></td><td><?= $e($c['manufacturer']) ?></td><td><?= $e($c['usage_process']) ?></td><td><?= $e($c['storage_place']) ?></td><td class="ctr"><?= $e($c['msds_rev_date']) ?></td><td class="ctr"><span class="badge ok"><?= $e($c['msds_placed']) ?></span></td><td class="ctr"><span class="badge ok"><?= $e($c['ghs_labeled']) ?></span></td><td class="ctr"><?php if ($canManage): ?><form method="post" onsubmit="return confirm('제외할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_chemical"><input type="hidden" name="tab" value="msds"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </section>
    <?php hmg_render_ev('2.8.3', 'msds', $year, $evByItem['2.8.3'], $scores['2.8.3'], $evKinds['2.8.3'], $itemMeta['2.8.3'], $canManage, $csrf, $e); ?>

  <?php elseif ($tab === 'chem_check'): ?>
    <?php if ($canManage): ?>
    <section class="card">
      <div class="card-head"><div><h2>2.8.4 화학물질 보관 및 경고표지(MSDS-GHS) 현장점검 등록</h2><p class="sub">현장 사진을 첨부(파일/카메라/Ctrl+V)하면 2.8.4 증빙으로도 자동 반영됩니다.</p></div></div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_chem_check"><input type="hidden" name="tab" value="chem_check">
        <div class="form-grid" style="margin-bottom:12px;">
          <div class="field"><label>점검일</label><input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label>점검자</label><input type="text" name="inspector" value="<?= $e($actor) ?>" required></div>
          <div class="field"><label>점검장소</label><input type="text" name="location" value="현대기전 자재창고 화학물질 보관함" required></div>
        </div>
        <div class="tbl-wrap" style="margin-bottom:12px;"><table><thead><tr><th class="ctr" style="width:40px;">No</th><th>점검 항목</th><th style="width:230px;">결과</th></tr></thead><tbody>
          <?php $cDefs = ['chk_msds_latest'=>'MSDS 최신본 현장 비치 및 근로자 열람 가능 여부','chk_ghs_label'=>'화학물질 용기·보관함 MSDS-GHS 경고표지 부착 및 시인성','chk_small_container'=>'소분 용기 사용 시 경고표지 부착 및 내용물 명칭 표기','chk_vent_lock'=>'전용 보관함 시건장치, 환기 상태 및 화기 엄금 표지','chk_spill_kit'=>'누출방지 트레이(방유턱), 흡착포 등 방제용품 비치 상태','chk_segregation'=>'인화성·산화성·부식성 등 성상별 분리 보관 여부'];
          $n = 1; foreach ($cDefs as $k => $l): ?>
            <tr><td class="ctr"><?= $n++ ?></td><td><?= $e($l) ?></td><td><div class="radio-grp"><label><input type="radio" name="<?= $k ?>" value="적합" checked> 적합</label><label><input type="radio" name="<?= $k ?>" value="보완필요"> 보완필요</label><label><input type="radio" name="<?= $k ?>" value="해당없음"> 해당없음</label></div></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-grid cols-2" style="margin-bottom:12px;">
          <div class="field"><label>📷 현장 사진 1 (화학물질 보관함 상태)</label><input type="file" name="photo1" accept="image/*" capture="environment"><input type="hidden" name="photo1_base64" class="js-paste-b64" value=""><div class="paste-zone js-paste-zone" tabindex="0"><span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 붙여넣기</span><button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button><img class="paste-preview js-paste-preview" style="display:none;"></div></div>
          <div class="field"><label>📷 현장 사진 2 (MSDS-GHS 경고표지 부착)</label><input type="file" name="photo2" accept="image/*" capture="environment"><input type="hidden" name="photo2_base64" class="js-paste-b64" value=""><div class="paste-zone js-paste-zone" tabindex="0"><span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 붙여넣기</span><button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button><img class="paste-preview js-paste-preview" style="display:none;"></div></div>
          <div class="field"><label>지적 사항</label><textarea name="issue_note" rows="2"></textarea></div>
          <div class="field"><label>개선 조치</label><textarea name="action_note" rows="2"></textarea></div>
        </div>
        <div style="text-align:right;"><button type="submit" class="btn primary">보관·GHS 점검 저장</button></div>
      </form>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="card-head"><div><h2><?= $year ?>년 화학물질 보관·GHS 점검 이력 (<?= count($chemChecks) ?>건)</h2></div><a class="btn sm primary" href="health_management_print.php?mode=chem_check&amp;year=<?= $year ?>" target="_blank">🖨️ 점검표 A4 출력</a></div>
      <div class="tbl-wrap"><table><thead><tr><th class="ctr">점검일</th><th class="ctr">점검자</th><th>점검장소</th><th class="ctr">종합결과</th><th>현장사진</th><th>지적·조치</th><th class="ctr">관리</th></tr></thead><tbody>
        <?php if (!$chemChecks): ?><tr><td colspan="7" class="ctr" style="padding:20px;color:var(--muted);">등록된 점검 기록이 없습니다.</td></tr>
        <?php else: foreach ($chemChecks as $r): ?>
          <tr><td class="ctr"><strong><?= $e($r['check_date']) ?></strong></td><td class="ctr"><?= $e($r['inspector']) ?></td><td><?= $e($r['location']) ?></td><td class="ctr"><span class="badge <?= $r['overall_result']==='적합'?'ok':'warn' ?>"><?= $e($r['overall_result']) ?></span></td><td><div class="photo-thumbs"><?php if ($r['photo1']): ?><a href="?view_photo=<?= urlencode($r['photo1']) ?>" target="_blank"><img class="photo-thumb" src="?view_photo=<?= urlencode($r['photo1']) ?>"></a><?php endif; ?><?php if ($r['photo2']): ?><a href="?view_photo=<?= urlencode($r['photo2']) ?>" target="_blank"><img class="photo-thumb" src="?view_photo=<?= urlencode($r['photo2']) ?>"></a><?php endif; ?></div></td><td><?= $e($r['issue_note'] ?: '양호') ?></td><td class="ctr"><a class="btn sm ghost" href="health_management_print.php?mode=chem_check&amp;year=<?= $year ?>&amp;id=<?= (int)$r['id'] ?>" target="_blank">🖨️</a> <?php if ($canManage): ?><form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_chem_check"><input type="hidden" name="tab" value="chem_check"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>
    <?php hmg_render_ev('2.8.4', 'chem_check', $year, $evByItem['2.8.4'], $scores['2.8.4'], $evKinds['2.8.4'], $itemMeta['2.8.4'], $canManage, $csrf, $e); ?>

  <?php elseif ($tab === 'first_aid'): ?>
    <?php if ($canManage): ?>
    <section class="card">
      <div class="card-head"><div><h2>2.8.5 응급처치 장비(구호용품) 및 의약품 월간점검 등록</h2><p class="sub">현대기전 3개소(본사·현장·가스사무실 3SET) 구급함 및 응급장비 상태를 점검하고 현장 사진을 첨부합니다.</p></div></div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="save_first_aid"><input type="hidden" name="tab" value="first_aid">
        <div class="form-grid cols-4" style="margin-bottom:12px;">
          <div class="field"><label>점검일</label><input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label>점검자</label><input type="text" name="inspector" value="<?= $e($actor) ?>" required></div>
          <div class="field"><label>비치 위치</label><input type="text" name="location" value="본사사무실 / 현장사무실 / 가스사무실" required></div>
          <div class="field"><label>비치 수량</label><input type="text" name="set_count" value="3 SET"></div>
        </div>
        <div class="tbl-wrap" style="margin-bottom:12px;"><table><thead><tr><th class="ctr" style="width:40px;">No</th><th>점검 항목</th><th style="width:230px;">결과</th></tr></thead><tbody>
          <?php $aDefs = ['chk_med_stock'=>'필수 의약품(소독약·거즈·붕대·연고·지혈대 등) 비치 수량 적정 여부','chk_expiry'=>'비치 의약품 유효기간 경과 여부 (유효기간 만료품 즉시 교체)','chk_equip_state'=>'응급구조장비(들것·부목·안구세척기 등) 파손·분실 없이 사용가능 상태','chk_ledger'=>'의약품·응급장비 관리대장 비치 및 월간 점검 기록 상태'];
          $n = 1; foreach ($aDefs as $k => $l): ?>
            <tr><td class="ctr"><?= $n++ ?></td><td><?= $e($l) ?></td><td><div class="radio-grp"><label><input type="radio" name="<?= $k ?>" value="적합" checked> 적합</label><label><input type="radio" name="<?= $k ?>" value="보완필요"> 보완필요</label><label><input type="radio" name="<?= $k ?>" value="해당없음"> 해당없음</label></div></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-grid cols-2" style="margin-bottom:12px;">
          <div class="field"><label>📷 현장 사진 1 (구급함·의약품 비치 상태)</label><input type="file" name="photo1" accept="image/*" capture="environment"><input type="hidden" name="photo1_base64" class="js-paste-b64" value=""><div class="paste-zone js-paste-zone" tabindex="0"><span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 붙여넣기</span><button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button><img class="paste-preview js-paste-preview" style="display:none;"></div></div>
          <div class="field"><label>📷 현장 사진 2 (응급구조장비 비치 상태)</label><input type="file" name="photo2" accept="image/*" capture="environment"><input type="hidden" name="photo2_base64" class="js-paste-b64" value=""><div class="paste-zone js-paste-zone" tabindex="0"><span class="js-paste-label">클릭 후 <strong>Ctrl+V</strong> 붙여넣기</span><button type="button" class="btn sm ghost js-paste-btn">📋 붙여넣기</button><img class="paste-preview js-paste-preview" style="display:none;"></div></div>
          <div class="field span-2"><label>보충 / 교체 / 비고 사항</label><textarea name="refill_note" rows="2" placeholder="예: 유효기간 도래 소독약·밴드 보충 완료"></textarea></div>
        </div>
        <div style="text-align:right;"><button type="submit" class="btn primary">구급용품 점검 저장</button></div>
      </form>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="card-head"><div><h2><?= $year ?>년 응급처치 장비·의약품 점검 이력 (<?= count($aidChecks) ?>건)</h2></div><a class="btn sm primary" href="health_management_print.php?mode=first_aid&amp;year=<?= $year ?>" target="_blank">🖨️ 점검표 A4 출력</a></div>
      <div class="tbl-wrap"><table><thead><tr><th class="ctr">점검일</th><th class="ctr">점검자</th><th>비치 위치 (수량)</th><th class="ctr">종합결과</th><th>현장사진</th><th>보충·비고</th><th class="ctr">관리</th></tr></thead><tbody>
        <?php if (!$aidChecks): ?><tr><td colspan="7" class="ctr" style="padding:20px;color:var(--muted);">등록된 점검 기록이 없습니다.</td></tr>
        <?php else: foreach ($aidChecks as $r): ?>
          <tr><td class="ctr"><strong><?= $e($r['check_date']) ?></strong></td><td class="ctr"><?= $e($r['inspector']) ?></td><td><?= $e($r['location']) ?> (<?= $e($r['set_count']) ?>)</td><td class="ctr"><span class="badge <?= $r['overall_result']==='적합'?'ok':'warn' ?>"><?= $e($r['overall_result']) ?></span></td><td><div class="photo-thumbs"><?php if ($r['photo1']): ?><a href="?view_photo=<?= urlencode($r['photo1']) ?>" target="_blank"><img class="photo-thumb" src="?view_photo=<?= urlencode($r['photo1']) ?>"></a><?php endif; ?><?php if ($r['photo2']): ?><a href="?view_photo=<?= urlencode($r['photo2']) ?>" target="_blank"><img class="photo-thumb" src="?view_photo=<?= urlencode($r['photo2']) ?>"></a><?php endif; ?></div></td><td><?= $e($r['refill_note'] ?: '양호') ?></td><td class="ctr"><a class="btn sm ghost" href="health_management_print.php?mode=first_aid&amp;year=<?= $year ?>&amp;id=<?= (int)$r['id'] ?>" target="_blank">🖨️</a> <?php if ($canManage): ?><form method="post" onsubmit="return confirm('삭제할까요?');" style="display:inline;"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="delete_first_aid"><input type="hidden" name="tab" value="first_aid"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">삭제</button></form><?php endif; ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>
    <?php hmg_render_ev('2.8.5', 'first_aid', $year, $evByItem['2.8.5'], $scores['2.8.5'], $evKinds['2.8.5'], $itemMeta['2.8.5'], $canManage, $csrf, $e); ?>

  <?php else: ?>
    <?php foreach (array_keys($itemMeta) as $code): ?>
      <?php hmg_render_ev($code, 'evidence', $year, $evByItem[$code], $scores[$code], $evKinds[$code], $itemMeta[$code], $canManage, $csrf, $e); ?>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<script>
function applyPastedImage(zone, blob) {
  const b64 = (zone.previousElementSibling && zone.previousElementSibling.classList.contains('js-paste-b64'))
    ? zone.previousElementSibling : (zone.closest('form') || zone.parentElement).querySelector('.js-paste-b64');
  const prev = zone.querySelector('.js-paste-preview');
  const lbl = zone.querySelector('.js-paste-label');
  const r = new FileReader();
  r.onload = () => {
    if (b64) b64.value = r.result;
    if (prev) { prev.src = r.result; prev.style.display = 'block'; }
    if (lbl) lbl.innerHTML = '✅ <strong>스크린샷 준비 완료</strong>';
  };
  r.readAsDataURL(blob);
}
document.querySelectorAll('.js-paste-zone').forEach(zone => {
  zone.addEventListener('paste', e => {
    for (const it of (e.clipboardData || window.clipboardData)?.items || []) {
      if (it.type && it.type.startsWith('image/')) { e.preventDefault(); applyPastedImage(zone, it.getAsFile()); return; }
    }
  });
  zone.querySelector('.js-paste-btn')?.addEventListener('click', async e => {
    e.stopPropagation();
    if (!navigator.clipboard || !navigator.clipboard.read) { zone.focus(); alert('클릭 후 Ctrl+V 를 눌러주세요.'); return; }
    try {
      for (const ci of await navigator.clipboard.read()) {
        const t = ci.types.find(x => x.startsWith('image/'));
        if (t) { applyPastedImage(zone, await ci.getType(t)); return; }
      }
      alert('클립보드에 이미지가 없습니다.');
    } catch { zone.focus(); alert('클릭 후 Ctrl+V 를 눌러주세요.'); }
  });
});
</script>
</body>
</html>
