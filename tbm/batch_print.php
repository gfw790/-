<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/tbm_db.php';
require_once __DIR__ . '/tbm_functions.php';
require_once __DIR__ . '/../risk_assessment/auth.php';

// ── 인증 확인 ────────────────────────────────────────────────
$raUser = auth_current_user();
if ($raUser === null) {
    if (($_GET['action'] ?? '') === 'search' || ($_POST['action'] ?? '') === 'search') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ../risk_assessment/task_select.php');
    exit;
}

$isAdmin    = auth_is_admin($raUser);
$userTeam   = auth_normalize_team_name((string)($raUser['team'] ?? ''));
$userRole   = (string)($raUser['role'] ?? '');
$isOperator = $isAdmin || ($userRole === 'safety_manager');
$userDisplayTeam = tbm_normalize_display_team_name($userTeam);

// ── 팀별 접근 권한 판별 함수 ─────────────────────────────────
function tbm_batch_allowed_teams(?array $user, bool $isOperator): ?array
{
    if ($isOperator) {
        return null; // 운영자는 모든 팀 조회 가능
    }

    $allowed = ['']; // '공통' 문서는 전원 열람 가능
    $teamName = auth_normalize_team_name((string)($user['team'] ?? ''));
    if ($teamName !== '') {
        $allowed[] = $teamName;
        $allowed[] = tbm_normalize_display_team_name($teamName);
    }

    $visible = auth_work_list_visible_teams($user);
    foreach ($visible as $t) {
        $allowed[] = $t;
        $allowed[] = tbm_normalize_display_team_name($t);
    }

    $supervised = auth_supervised_teams($teamName);
    foreach ($supervised as $st) {
        $allowed[] = $st;
        $allowed[] = tbm_normalize_display_team_name($st);
    }

    return array_values(array_unique(array_filter($allowed, static fn($v) => is_string($v))));
}

// ── 파일 경로 보안 검증 함수 (디렉터리 탈출 방지) ─────────────
function tbm_batch_validate_file_path(string $relativePath): ?string
{
    $safeRelative = tbm_normalize_output_relative_path($relativePath);
    if ($safeRelative === '') {
        return null;
    }

    $fullPath = tbm_resolve_output_full_path($safeRelative);
    $realOutputRoot = realpath(tbm_output_root_dir());
    $realFile = realpath($fullPath);

    if ($realOutputRoot === false || $realFile === false) {
        return null;
    }

    // output 폴더 하위에 실제로 존재하는지 철저 검증
    if (!str_starts_with($realFile, $realOutputRoot)) {
        return null;
    }

    if (!is_file($realFile)) {
        return null;
    }

    return $realFile;
}

// ── HTML에서 .hpa 블록(앞면, 뒷면) 추출 함수 ──────────────────
function tbm_batch_extract_hpa_blocks(string $html): array
{
    $blocks = [];
    $offset = 0;
    while (($pos = stripos($html, '<div class="hpa"', $offset)) !== false) {
        $len = strlen($html);
        $depth = 0;
        $start = $pos;
        $i = $pos;
        while ($i < $len) {
            if (substr($html, $i, 4) === '<div') {
                $depth++;
                $i += 4;
            } elseif (substr($html, $i, 6) === '</div>') {
                $depth--;
                $i += 6;
                if ($depth === 0) {
                    $block = substr($html, $start, $i - $start);
                    // 스크립트 실행 방지를 위한 script 태그 제거
                    $block = preg_replace('~<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>~i', '', $block);
                    $blocks[] = $block;
                    $offset = $i;
                    break;
                }
            } else {
                $i++;
            }
        }
        if ($depth !== 0) {
            break;
        }
    }
    return $blocks;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

// ============================================================
// 1. AJAX 기간 검색 API (action = search)
// ============================================================
if ($action === 'search') {
    header('Content-Type: application/json; charset=utf-8');

    $startDate = trim((string)($_GET['start_date'] ?? $_POST['start_date'] ?? ''));
    $endDate   = trim((string)($_GET['end_date']   ?? $_POST['end_date']   ?? ''));
    $filterTeam = trim((string)($_GET['team']       ?? $_POST['team']       ?? '__ALL__'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '올바른 날짜 형식(YYYY-MM-DD)을 입력하세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($startDate > $endDate) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '시작일이 종료일보다 클 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 최대 366일 검색 제한
    $d1 = new DateTime($startDate);
    $d2 = new DateTime($endDate);
    if ($d1->diff($d2)->days > 366) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '최대 1년(366일) 범위 내에서만 조회할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allowedTeams = tbm_batch_allowed_teams($raUser, $isOperator);

    try {
        $pdo = tbm_db();
        $sql = "
            SELECT d.id, d.doc_date, d.team, d.output_filename, d.generation_status,
                   COALESCE(NULLIF(TRIM(c.edu_title), ''), NULLIF(TRIM(d.today_work_1), ''), 'TBM 일지') AS title,
                   d.created_at
              FROM tbm_documents d
         LEFT JOIN tbm_accident_content c ON d.content_id = c.id
             WHERE d.doc_date BETWEEN :start_date AND :end_date
               AND d.generation_status = 'success'
          ORDER BY d.doc_date ASC, CASE WHEN d.team = '' THEN '공통' ELSE d.team END ASC, d.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);
        $rows = $stmt->fetchAll();

        // 일자별 및 팀별 인덱싱
        $rowsByDate = [];
        foreach ($rows as $row) {
            $d = (string)$row['doc_date'];
            $t = (string)($row['team'] ?? '');
            $rowsByDate[$d][$t] = $row;
        }

        $resultList = [];

        if ($filterTeam !== '__ALL__' && $filterTeam !== '__COMMON__') {
            $normFilter = tbm_normalize_display_team_name($filterTeam);

            foreach ($rowsByDate as $d => $teamDocs) {
                // 1) 해당 팀의 전용 일지가 있는 경우 우선 사용
                $matchedRow = null;
                if (isset($teamDocs[$filterTeam])) {
                    $matchedRow = $teamDocs[$filterTeam];
                } else {
                    foreach ($teamDocs as $tName => $tRow) {
                        if (tbm_normalize_display_team_name($tName) === $normFilter) {
                            $matchedRow = $tRow;
                            break;
                        }
                    }
                }

                // 2) 해당 팀 일지가 없으나 공통(team='') 또는 기준 일지가 있는 경우 자동 연동(Fallback)
                if ($matchedRow === null) {
                    if (isset($teamDocs[''])) {
                        $matchedRow = $teamDocs[''];
                    } elseif (!empty($teamDocs)) {
                        $matchedRow = reset($teamDocs);
                    }
                }

                if ($matchedRow !== null) {
                    $fileName = (string)($matchedRow['output_filename'] ?? '');
                    $realPath = ($fileName !== '') ? tbm_batch_validate_file_path($fileName) : null;
                    $isAvailable = ($realPath !== null);

                    $resultList[] = [
                        'id'              => (int)$matchedRow['id'],
                        'doc_date'        => (string)$matchedRow['doc_date'],
                        'team'            => $filterTeam,
                        'display_team'    => $filterTeam,
                        'title'           => (string)$matchedRow['title'],
                        'output_filename' => $fileName,
                        'is_available'    => $isAvailable,
                        'file_error'      => $isAvailable ? null : 'HTML 파일 누락',
                        'is_fallback'     => ($matchedRow['team'] !== $filterTeam),
                    ];
                }
            }
        } else {
            foreach ($rows as $row) {
                $rawTeam = (string)($row['team'] ?? '');
                $displayTeam = ($rawTeam === '') ? '공통' : tbm_normalize_display_team_name($rawTeam);

                // 비운영자 접근 권한 확인
                if ($allowedTeams !== null) {
                    $hasAccess = in_array($rawTeam, $allowedTeams, true)
                              || in_array($displayTeam, $allowedTeams, true);
                    if (!$hasAccess) {
                        continue;
                    }
                }

                // 공통만 필터링인 경우
                if ($filterTeam === '__COMMON__') {
                    if ($rawTeam !== '' && $displayTeam !== '공통') {
                        continue;
                    }
                }

                // 파일 존재 여부 검증
                $fileName = (string)($row['output_filename'] ?? '');
                $realPath = ($fileName !== '') ? tbm_batch_validate_file_path($fileName) : null;
                $isAvailable = ($realPath !== null);

                $resultList[] = [
                    'id'              => (int)$row['id'],
                    'doc_date'        => (string)$row['doc_date'],
                    'team'            => $rawTeam,
                    'display_team'    => $displayTeam,
                    'title'           => (string)$row['title'],
                    'output_filename' => $fileName,
                    'is_available'    => $isAvailable,
                    'file_error'      => $isAvailable ? null : 'HTML 파일 누락',
                    'is_fallback'     => false,
                ];
            }
        }

        echo json_encode([
            'success'     => true,
            'data'        => $resultList,
            'total_count' => count($resultList),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB 조회 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// 2. 일괄 인쇄 뷰 렌더링 (doc_ids 파라미터 전달 시)
// ============================================================
$rawDocIds = $_POST['doc_ids'] ?? $_GET['doc_ids'] ?? null;
$targetTeamParam = trim((string)($_POST['target_team'] ?? $_GET['target_team'] ?? ''));
if ($rawDocIds !== null) {
    if (is_string($rawDocIds)) {
        $docIds = array_map('intval', explode(',', $rawDocIds));
    } elseif (is_array($rawDocIds)) {
        $docIds = array_map('intval', $rawDocIds);
    } else {
        $docIds = [];
    }

    $docIds = array_values(array_unique(array_filter($docIds, static fn($v) => $v > 0)));

    if (empty($docIds)) {
        http_response_code(400);
        echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>오류</title></head><body>';
        echo '<h3>인쇄할 일지가 선택되지 않았습니다.</h3>';
        echo '<p><button onclick="window.close()">닫기</button> <a href="batch_print.php">돌아가기</a></p>';
        echo '</body></html>';
        exit;
    }

    // 최대 500건(1,000페이지)까지 일괄 출력 허용 (1년치 366건 이상 전체 출력 가능)
    if (count($docIds) > 500) {
        $docIds = array_slice($docIds, 0, 500);
    }

    $allowedTeams = tbm_batch_allowed_teams($raUser, $isOperator);

    try {
        $pdo = tbm_db();
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $sql = "
            SELECT d.id, d.doc_date, d.team, d.output_filename, d.generation_status,
                   COALESCE(NULLIF(TRIM(c.edu_title), ''), NULLIF(TRIM(d.today_work_1), ''), 'TBM 일지') AS title
              FROM tbm_documents d
         LEFT JOIN tbm_accident_content c ON d.content_id = c.id
             WHERE d.id IN ($placeholders)
               AND d.generation_status = 'success'
          ORDER BY d.doc_date ASC, CASE WHEN d.team = '' THEN '공통' ELSE d.team END ASC, d.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($docIds);
        $matchedDocs = $stmt->fetchAll();
    } catch (Throwable $e) {
        http_response_code(500);
        exit('DB 오류: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    $validDocs = [];
    $missingList = [];
    $allPages = [];

    foreach ($matchedDocs as $doc) {
        $rawTeam = (string)($doc['team'] ?? '');
        $displayTeam = ($rawTeam === '') ? '공통' : tbm_normalize_display_team_name($rawTeam);

        // 비운영자 권한 검사
        if ($allowedTeams !== null) {
            $hasAccess = in_array($rawTeam, $allowedTeams, true)
                      || in_array($displayTeam, $allowedTeams, true);
            if (!$hasAccess) {
                continue;
            }
        }

        $fileName = (string)($doc['output_filename'] ?? '');
        $realPath = ($fileName !== '') ? tbm_batch_validate_file_path($fileName) : null;

        if ($realPath === null || !is_file($realPath)) {
            // 파일 누락 시 오류 목록에 기록하고 재생성하지 않음
            $missingList[] = [
                'id'       => (int)$doc['id'],
                'doc_date' => (string)$doc['doc_date'],
                'team'     => $displayTeam,
                'title'    => (string)$doc['title'],
                'file'     => $fileName,
            ];
            continue;
        }

        $htmlContent = file_get_contents($realPath);
        if ($htmlContent === false) {
            $missingList[] = [
                'id'       => (int)$doc['id'],
                'doc_date' => (string)$doc['doc_date'],
                'team'     => $displayTeam,
                'title'    => (string)$doc['title'],
                'file'     => $fileName,
            ];
            continue;
        }

        $blocks = tbm_batch_extract_hpa_blocks($htmlContent);
        if (empty($blocks)) {
            $missingList[] = [
                'id'       => (int)$doc['id'],
                'doc_date' => (string)$doc['doc_date'],
                'team'     => $displayTeam,
                'title'    => (string)$doc['title'],
                'file'     => $fileName,
            ];
            continue;
        }

        // 해당 일자의 직원명부 기준(입사자 포함, 퇴사자 제외)으로 앞면 참석자 명단 표 갱신
        if (isset($blocks[0])) {
            $docDate = (string)($doc['doc_date'] ?? '');
            if ($targetTeamParam !== '' && $targetTeamParam !== '__ALL__' && $targetTeamParam !== '__COMMON__') {
                $targetTeam = $targetTeamParam;
            } else {
                $targetTeam = ($rawTeam === '') ? '공사팀' : $displayTeam;
            }
            $activeNamesOnDate = tbm_attendee_names_for_team($targetTeam, $docDate);
            if (!empty($activeNamesOnDate)) {
                $newAttendeeTable = tbm_build_attendee_table_html($activeNamesOnDate);
                $blocks[0] = preg_replace(
                    '~<table class="tbm-dyn-table tbm-dyn-row" style="z-index: 5;">.*?</table>~is',
                    $newAttendeeTable,
                    $blocks[0],
                    1
                );
            }
        }

        $validDocs[] = $doc;
        foreach ($blocks as $block) {
            $allPages[] = $block;
        }
    }

    if (empty($allPages)) {
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>출력 불가</title></head>';
        echo '<body style="font-family:sans-serif;padding:30px;line-height:1.6;">';
        echo '<h2 style="color:#dc2626;">출력 가능한 일지가 없습니다.</h2>';
        if (!empty($missingList)) {
            echo '<p>요청된 모든 일지의 HTML 파일이 디스크에서 누락되었습니다:</p><ul>';
            foreach ($missingList as $m) {
                echo '<li>' . htmlspecialchars("{$m['doc_date']} [{$m['team']}] {$m['title']} ({$m['file']})", ENT_QUOTES, 'UTF-8') . '</li>';
            }
            echo '</ul>';
        }
        echo '<p><button onclick="window.close()" style="padding:8px 16px;">창 닫기</button> <a href="batch_print.php" style="margin-left:10px;">돌아가기</a></p>';
        echo '</body></html>';
        exit;
    }

    // base href 설정 (상대경로 이미지 및 QR코드 정확 로드)
    [$basePath] = explode('?', $_SERVER['REQUEST_URI'] ?? 'batch_print.php', 2);
    $baseHref = rtrim(dirname($basePath), '/\\');
    if ($baseHref === '.' || $baseHref === '') {
        $baseHref = '';
    }
    $baseHref .= '/output/';

    // 템플릿의 공통 CSS 가져오기 (기존 단건 인쇄 view_output.php와 완전히 동일한 스타일 구조 사용)
    $templatePath = __DIR__ . '/template/TBM_template.html';
    $templateCss = '';
    if (is_file($templatePath)) {
        $tplHtml = file_get_contents($templatePath);
        if ($tplHtml && preg_match('~<style>(.*?)</style>~is', $tplHtml, $m)) {
            $templateCss = $m[1];
        }
    }
?>
<!DOCTYPE html>
<html lang="ko">
 <meta charset="utf-8"/>
 <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible"/>
 <head><base href="<?= htmlspecialchars($baseHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <title>안 전 교 육 일 지 (<?= count($validDocs) ?>건 / <?= count($allPages) ?>페이지)</title>
  <meta content="text/html; charset=utf-8" http_quiv="content-type"/>
  <style>
<?= $templateCss ?>
@media print {
.hpa {margin:0;border:0 black none;box-shadow:none;page-break-after:always;break-after:page;}
.hpa:last-of-type {page-break-after:auto;break-after:auto;}
body {padding:0;}
}
  </style>
  <style>.tbm-floating-print-btn{position:fixed;right:24px;bottom:24px;z-index:9999;display:inline-flex;align-items:center;justify-content:center;min-width:140px;padding:14px 18px;border:none;border-radius:999px;background:#0f172a;color:#fff;font-family:"Malgun Gothic",sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;box-shadow:0 12px 30px rgba(15,23,42,.24);}.tbm-floating-print-btn:hover{background:#1e293b;}.tbm-print-guide{position:fixed;right:24px;bottom:86px;z-index:9998;width:320px;padding:14px 16px;border:1px solid #bfdbfe;border-radius:10px;background:rgba(239,246,255,.97);box-shadow:0 10px 28px rgba(15,23,42,.14);font-family:"Malgun Gothic",sans-serif;font-size:13px;line-height:1.6;color:#1e293b;}.tbm-print-guide strong{display:block;margin-bottom:6px;color:#1d4ed8;font-size:14px;}@media print{.tbm-floating-print-btn{display:none !important;}}@media print{.tbm-print-guide{display:none !important;}}</style>
 </head>
 <body>
<?php
// 모든 페이지 렌더링 (기존 view_output.php와 동일한 DOM 구조)
foreach ($allPages as $pageHtml) {
    echo '  ' . $pageHtml . PHP_EOL;
}
?>
  <div class="tbm-print-guide"><strong>인쇄 요령 (선택 <?= count($validDocs) ?>건 / 총 <?= count($allPages) ?>페이지)</strong>A4 세로 기준으로 인쇄하시고 배율은 100%로 맞춰 주세요.<br>여백은 없음 또는 최소, 머리글/바닥글은 끔으로 설정하는 것을 권장합니다.<br>사진이나 배경이 보이지 않으면 인쇄 설정에서 배경 그래픽 출력을 켜고 다시 인쇄해 주세요.</div><button type="button" class="tbm-floating-print-btn" onclick="window.print()">🖨 인쇄하기</button>
 </body>
</html>
<?php
    exit;
}

// ============================================================
// 3. 독립 페이지 모드 (URL 직접 접근 시 검색 및 출력 화면)
// ============================================================
$allowedTeams = tbm_batch_allowed_teams($raUser, $isOperator);

// 팀 선택 드롭다운용 팀 목록
$availableTeams = [];
if ($isOperator) {
    $availableTeams['__ALL__'] = '전체 팀';
    $availableTeams['__COMMON__'] = '공통';
    $availableTeams['공사팀'] = '공사팀';
    $availableTeams['가스팀'] = '가스팀';
    $availableTeams['제조팀'] = '제조팀';
    $availableTeams['공사팀 전기-4'] = '공사팀 전기-4';
} else {
    if ($userDisplayTeam !== '') {
        $availableTeams[$userDisplayTeam] = $userDisplayTeam;
    }
    if ($allowedTeams !== null) {
        foreach ($allowedTeams as $at) {
            if ($at !== '') {
                $availableTeams[$at] = $at;
            }
        }
    }
    $availableTeams['__COMMON__'] = '공통';
}

$defaultStartDate = date('Y-m-d', strtotime('-7 days'));
$defaultEndDate   = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TBM 일지 기간별 일괄 출력</title>
<style>
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Malgun Gothic", sans-serif;
    background: #f1f5f9;
    margin: 0;
    padding: 30px 20px;
    color: #1e293b;
}
.container {
    max-width: 1050px;
    margin: 0 auto;
    background: #fff;
    padding: 28px;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.06);
}
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 16px;
    margin-bottom: 20px;
}
.header h1 {
    font-size: 1.45rem;
    margin: 0;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.filter-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px 20px;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 16px;
    margin-bottom: 20px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
}
.form-control {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.95rem;
    background: #fff;
}
.btn {
    padding: 9px 18px;
    border-radius: 6px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease-in-out;
}
.btn-primary { background: #2563eb; color: #fff; }
.btn-primary:hover { background: #1d4ed8; }
.btn-success { background: #16a34a; color: #fff; }
.btn-success:hover { background: #15803d; }
.btn-secondary { background: #64748b; color: #fff; }
.btn-secondary:hover { background: #475569; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding: 10px 14px;
    background: #f1f5f9;
    border-radius: 6px;
}
.summary-text {
    font-size: 0.95rem;
    color: #334155;
}
.summary-text strong {
    color: #2563eb;
}
.table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
    text-align: left;
}
th {
    background: #f8fafc;
    padding: 12px 14px;
    border-bottom: 2px solid #e2e8f0;
    font-weight: 700;
    color: #334155;
}
td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
}
tr:hover td {
    background: #f8fafc;
}
.badge {
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-block;
}
.badge-success { background: #dcfce7; color: #166534; }
.badge-danger  { background: #fee2e2; color: #991b1b; }
.alert-box {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 0.9rem;
    line-height: 1.5;
}
.alert-warning {
    background: #fffbeb;
    border: 1px solid #fef08a;
    color: #854d0e;
}
.empty-msg {
    text-align: center;
    padding: 40px;
    color: #94a3b8;
    font-size: 1rem;
}
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🖨️ TBM 일지 기간별 일괄 출력</h1>
        <div>
            <a href="index.php" class="btn btn-secondary">← 일지 작성으로 돌아가기</a>
        </div>
    </div>

    <!-- 검색 필터 영역 -->
    <div class="filter-card">
        <div class="form-group">
            <label for="batch_start_date">시작일</label>
            <input type="date" id="batch_start_date" class="form-control" value="<?= htmlspecialchars($defaultStartDate) ?>">
        </div>
        <div class="form-group">
            <label for="batch_end_date">종료일</label>
            <input type="date" id="batch_end_date" class="form-control" value="<?= htmlspecialchars($defaultEndDate) ?>">
        </div>
        <div class="form-group">
            <label for="batch_team">대상 팀</label>
            <select id="batch_team" class="form-control">
                <?php foreach ($availableTeams as $k => $label): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" class="btn btn-primary" onclick="searchBatchDocs()">
            🔍 조회하기
        </button>
    </div>

    <!-- 대량 인쇄 경고 -->
    <div id="batch_large_warning" class="alert-box alert-warning" style="display:none;">
        ⚠️ <strong>안내:</strong> 선택한 일지가 30건(60페이지) 이상입니다. 브라우저 성능에 따라 인쇄 대화상자 로딩이 다소 지연될 수 있으므로 20~30건 단위 분할 인쇄를 권장합니다.
    </div>

    <!-- 선택 및 실행 바 -->
    <div class="action-bar">
        <div class="summary-text">
            조회: <strong id="total_count_display">0</strong>건 | 
            선택: <strong id="selected_count_display">0</strong>건 
            (예상 인쇄: <strong id="page_count_display">0</strong>페이지)
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-secondary" onclick="toggleSelectAll(true)" style="padding:6px 12px;font-size:0.85rem;">전체 선택</button>
            <button type="button" class="btn btn-secondary" onclick="toggleSelectAll(false)" style="padding:6px 12px;font-size:0.85rem;">전체 해제</button>
            <button type="button" id="btn_execute_print" class="btn btn-success" onclick="executeBatchPrint()" disabled>
                🖨️ 선택 일괄 인쇄 / PDF 저장
            </button>
        </div>
    </div>

    <!-- 검색 결과 테이블 -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:45px;text-align:center;">
                        <input type="checkbox" id="check_all" onchange="toggleSelectAll(this.checked)">
                    </th>
                    <th style="width:110px;">작업일자</th>
                    <th style="width:110px;">팀</th>
                    <th>교육 내용 / 작업 내용</th>
                    <th style="width:110px;text-align:center;">상태</th>
                    <th style="width:90px;text-align:center;">단건 확인</th>
                </tr>
            </thead>
            <tbody id="batch_result_body">
                <tr>
                    <td colspan="6" class="empty-msg">시작일과 종료일을 지정한 후 [🔍 조회하기] 버튼을 누르세요.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- 일괄 인쇄 전송용 숨김 폼 -->
<form id="batch_print_form" action="batch_print.php" method="post" target="_blank" style="display:none;">
    <input type="hidden" name="doc_ids" id="form_doc_ids" value="">
    <input type="hidden" name="target_team" id="form_target_team" value="">
</form>

<script>
let currentDocs = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function searchBatchDocs() {
    const startDate = document.getElementById('batch_start_date').value;
    const endDate = document.getElementById('batch_end_date').value;
    const team = document.getElementById('batch_team').value;
    const tbody = document.getElementById('batch_result_body');

    if (!startDate || !endDate) {
        alert('시작일과 종료일을 입력하세요.');
        return;
    }
    if (startDate > endDate) {
        alert('시작일이 종료일보다 클 수 없습니다.');
        return;
    }

    tbody.innerHTML = '<tr><td colspan="6" class="empty-msg">일지를 검색 중입니다...</td></tr>';

    try {
        const res = await fetch('batch_print.php?action=search&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate) + '&team=' + encodeURIComponent(team));
        const json = await res.json();

        if (!json.success) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-msg" style="color:#dc2626;">조회 오류: ' + escapeHtml(json.error) + '</td></tr>';
            return;
        }

        currentDocs = json.data || [];
        document.getElementById('total_count_display').textContent = currentDocs.length;

        if (currentDocs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-msg">해당 기간에 생성된 일지가 없습니다.</td></tr>';
            updateSelectionCounts();
            return;
        }

        let html = '';
        currentDocs.forEach(doc => {
            const isAvail = doc.is_available;
            const badgeClass = isAvail ? 'badge-success' : 'badge-danger';
            const badgeText  = isAvail ? '출력 가능' : '파일 누락';
            const checkAttr  = isAvail ? 'checked' : 'disabled';
            const viewLink   = doc.output_filename ? '<a href="view_output.php?file=' + encodeURIComponent(doc.output_filename) + '&team=' + encodeURIComponent(doc.display_team || team) + '&date=' + encodeURIComponent(doc.doc_date || '') + '" target="_blank" style="color:#2563eb;font-weight:600;text-decoration:none;">열기</a>' : '-';

            html += '<tr>'
                + '<td style="text-align:center;"><input type="checkbox" class="doc-chk" value="' + doc.id + '" ' + checkAttr + ' onchange="updateSelectionCounts()"></td>'
                + '<td><strong>' + escapeHtml(doc.doc_date) + '</strong></td>'
                + '<td>' + escapeHtml(doc.display_team) + '</td>'
                + '<td>' + escapeHtml(doc.title) + '</td>'
                + '<td style="text-align:center;"><span class="badge ' + badgeClass + '">' + badgeText + '</span></td>'
                + '<td style="text-align:center;">' + viewLink + '</td>'
                + '</tr>';
        });

        tbody.innerHTML = html;
        updateSelectionCounts();
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-msg" style="color:#dc2626;">네트워크 오류가 발생했습니다.</td></tr>';
    }
}

function toggleSelectAll(checked) {
    const checkboxes = document.querySelectorAll('.doc-chk:not(:disabled)');
    checkboxes.forEach(cb => { cb.checked = checked; });
    const checkAll = document.getElementById('check_all');
    if (checkAll) checkAll.checked = checked;
    updateSelectionCounts();
}

function updateSelectionCounts() {
    const selected = document.querySelectorAll('.doc-chk:checked');
    const count = selected.length;
    const pages = count * 2;

    document.getElementById('selected_count_display').textContent = count;
    document.getElementById('page_count_display').textContent = pages;

    const btn = document.getElementById('btn_execute_print');
    btn.disabled = (count === 0);

    const warnBox = document.getElementById('batch_large_warning');
    if (count >= 30) {
        warnBox.style.display = 'block';
    } else {
        warnBox.style.display = 'none';
    }
}

function executeBatchPrint() {
    const selected = document.querySelectorAll('.doc-chk:checked');
    const ids = Array.from(selected).map(cb => cb.value);

    if (ids.length === 0) {
        alert('인쇄할 일지를 최소 1건 이상 선택하세요.');
        return;
    }

    if (ids.length > 30) {
        if (!confirm('선택된 일지가 ' + ids.length + '건(' + (ids.length * 2) + '페이지)입니다.\n계속 진행하시겠습니까?')) {
            return;
        }
    }

    document.getElementById('form_doc_ids').value = ids.join(',');
    document.getElementById('form_target_team').value = document.getElementById('batch_team').value;
    document.getElementById('batch_print_form').submit();
}

// 초기 로드 시 자동 1회 조회
window.addEventListener('DOMContentLoaded', () => {
    searchBatchDocs();
});
</script>

</body>
</html>

