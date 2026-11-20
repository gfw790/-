<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/accident_investigation_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');

$db = ai_db();

if (isset($_GET['download_evidence'])) {
    $evId = (int)$_GET['download_evidence'];
    $stmt = $db->prepare('SELECT * FROM ai_evidence WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$evId]);
    $ev = $stmt->fetch();
    if (!$ev) {
        http_response_code(404);
        exit('증빙 파일을 찾을 수 없습니다.');
    }
    $path = ai_evidence_dir() . '/' . basename((string)$ev['stored_name']);
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
if (empty($_SESSION['ai_csrf'])) {
    $_SESSION['ai_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['ai_csrf'];

$nowYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $nowYear;
if ($year < 2020 || $year > 2035) {
    $year = $nowYear;
}
$tab = (string)($_GET['tab'] ?? 'status');
if (!in_array($tab, ['status', 'manual', 'evidence'], true)) {
    $tab = 'status';
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
        if (in_array($postTab, ['status', 'manual', 'evidence'], true)) {
            $tab = $postTab;
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($action === 'save_year_status') {
                ai_get_year_status($db, $year);
                $st = (string)($_POST['status'] ?? 'na');
                if (!in_array($st, ['na', 'occurred'], true)) {
                    $st = 'na';
                }
                $db->prepare('UPDATE ai_year_status SET status=?, no_accident_since=?, confirm_date=?, department=?, manager_name=?, ceo_name=?, confirm_note=?, updated_by=?, updated_at=? WHERE year=?')
                   ->execute([
                       $st,
                       trim((string)($_POST['no_accident_since'] ?? '2021-01-01')),
                       trim((string)($_POST['confirm_date'] ?? date('Y-m-d'))),
                       trim((string)($_POST['department'] ?? '공사팀')),
                       trim((string)($_POST['manager_name'] ?? '김남균')),
                       trim((string)($_POST['ceo_name'] ?? '정연탁')),
                       trim((string)($_POST['confirm_note'] ?? '')),
                       $actor,
                       $now,
                       $year,
                   ]);
                $flashSuccess = $st === 'na'
                    ? $year . '년 2.1.1 항목이 [해당없음(N/A · 산업재해 미발생)]으로 저장되었습니다.'
                    : $year . '년 2.1.1 항목이 [산업재해 발생 · 원인조사 관리] 상태로 저장되었습니다.';
            } elseif ($action === 'save_manual') {
                $db->prepare('UPDATE ai_manual SET revision_date=?, manager_name=?, ceo_name=?, step1_title=?, step1_desc=?, step2_title=?, step2_desc=?, step3_title=?, step3_desc=?, step4_title=?, step4_desc=?, step5_title=?, step5_desc=?, step6_title=?, step6_desc=?, emergency_contacts=?, updated_by=?, updated_at=? WHERE id=1')
                   ->execute([
                       trim((string)($_POST['revision_date'] ?? date('Y-m-d'))),
                       trim((string)($_POST['manager_name'] ?? '김남균')),
                       trim((string)($_POST['ceo_name'] ?? '정연탁')),
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
                       trim((string)($_POST['step6_title'] ?? '')),
                       trim((string)($_POST['step6_desc'] ?? '')),
                       trim((string)($_POST['emergency_contacts'] ?? '')),
                       $actor,
                       $now,
                   ]);
                $flashSuccess = '산업재해 발생 시 대응 및 원인조사 매뉴얼이 저장되었습니다.';
            } elseif ($action === 'save_incident') {
                $id = (int)($_POST['id'] ?? 0);
                $occurDate = trim((string)($_POST['occur_date'] ?? ''));
                if ($occurDate === '') {
                    throw new InvalidArgumentException('재해 발생일자를 입력해 주세요.');
                }
                $incYear = (int)substr($occurDate, 0, 4);
                if ($incYear < 2015 || $incYear > 2035) {
                    $incYear = $year;
                }
                $fields = [
                    $occurDate,
                    $incYear,
                    trim((string)($_POST['location'] ?? '')),
                    trim((string)($_POST['victim_info'] ?? '')),
                    trim((string)($_POST['accident_type'] ?? '')),
                    trim((string)($_POST['severity'] ?? '휴업 3일 이상')),
                    trim((string)($_POST['gov_report_date'] ?? '')),
                    trim((string)($_POST['direct_cause'] ?? '')),
                    trim((string)($_POST['root_cause_4m'] ?? '')),
                    trim((string)($_POST['countermeasure'] ?? '')),
                    trim((string)($_POST['action_done_date'] ?? '')),
                    trim((string)($_POST['status'] ?? '완료')),
                ];
                if ($id > 0) {
                    $db->prepare('UPDATE ai_incidents SET occur_date=?, year=?, location=?, victim_info=?, accident_type=?, severity=?, gov_report_date=?, direct_cause=?, root_cause_4m=?, countermeasure=?, action_done_date=?, status=?, updated_at=? WHERE id=?')
                       ->execute([...$fields, $now, $id]);
                    $flashSuccess = '산업재해 원인조사 기록이 수정되었습니다.';
                } else {
                    $db->prepare('INSERT INTO ai_incidents (occur_date, year, location, victim_info, accident_type, severity, gov_report_date, direct_cause, root_cause_4m, countermeasure, action_done_date, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                       ->execute([...$fields, $actor, $now, $now]);
                    $flashSuccess = '산업재해 원인조사 기록이 등록되었습니다.';
                }
            } elseif ($action === 'delete_incident') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('DELETE FROM ai_incidents WHERE id = ?')->execute([$id]);
                    $flashSuccess = '산업재해 기록이 삭제되었습니다.';
                }
            } elseif ($action === 'upload_evidence') {
                $kind = (string)($_POST['kind'] ?? 'no_accident_cert');
                $note = trim((string)($_POST['note'] ?? ''));
                ai_upload_evidence($db, $year, $kind, $_FILES['evidence_file'] ?? null, (string)($_POST['evidence_base64'] ?? ''), $note, $actor);
                $flashSuccess = '2.1.1 증빙 자료가 등록되었습니다.';
            } elseif ($action === 'delete_evidence') {
                $evId = (int)($_POST['id'] ?? 0);
                if ($evId > 0) {
                    $db->prepare('UPDATE ai_evidence SET deleted_at = ? WHERE id = ? AND year = ?')->execute([$now, $evId, $year]);
                    $flashSuccess = '증빙 파일이 삭제되었습니다.';
                }
            } elseif ($action === 'save_assessment') {
                $multVal = (string)($_POST['multiplier'] ?? 'auto');
                $note = trim((string)($_POST['note'] ?? ''));
                if ($multVal === 'auto') {
                    $db->prepare('DELETE FROM ai_assessments WHERE year = ?')->execute([$year]);
                    $flashSuccess = '평가 수준이 자동 반영으로 변경되었습니다.';
                } else {
                    $mult = (float)$multVal;
                    if (!in_array($mult, [1.0, 0.9, 0.8, 0.5, 0.0], true)) {
                        throw new InvalidArgumentException('유효한 평가 수준을 선택해 주세요.');
                    }
                    $db->prepare('INSERT INTO ai_assessments (year, multiplier, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(year) DO UPDATE SET multiplier=excluded.multiplier, note=excluded.note, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
                       ->execute([$year, $mult, $note, $actor, $now]);
                    $flashSuccess = '2.1.1 평가 수준이 저장되었습니다.';
                }
            }
        } catch (Throwable $e) {
            $flashError = $e->getMessage();
        }
    }
}

$yearStatus = ai_get_year_status($db, $year);
$manual = ai_get_manual($db);
$incidents = ai_list_incidents($db, $year);
$evidences = ai_list_evidence($db, $year);
$score = csm_ai_score_summary($year, 1.0);
$kinds = ai_evidence_kinds();
$isNa = ($yearStatus['status'] ?? 'na') === 'na';

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
function ai_fmt_size(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>2.1.1 산업재해 발생원인 조사·대응 매뉴얼 · CSM</title>
<style>
  :root {
    --bg: #f4f6f9;
    --card: #ffffff;
    --line: #dbe2ea;
    --ink: #162231;
    --muted: #5a6978;
    --brand: #0f4c81;
    --brand-dark: #0b3a63;
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
    background: #0f2c4c; color: #fff; padding: 0 20px; height: 54px;
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

  .wrap { max-width: 1380px; margin: 0 auto; padding: 20px 20px 60px; }
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
  .btn.ok-btn { background: #15803d; border-color: #15803d; color: #fff; }
  .btn.ok-btn:hover { background: #166534; }
  .btn.danger { background: #fff; border-color: #fecaca; color: var(--danger); }
  .btn.danger:hover { background: var(--danger-bg); }
  .btn.ghost { background: #f8fafc; }
  .btn.sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 700; font-size: 13px; }
  .flash.ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #bbf7d0; }
  .flash.err { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; }

  .kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
  .kpi { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
  .kpi.na-card { background: linear-gradient(135deg, #15803d, #166534); color: #fff; border-color: #15803d; }
  .kpi.warn-card { background: linear-gradient(135deg, #b45309, #92400e); color: #fff; border-color: #b45309; }
  .kpi-code { font-size: 12px; font-weight: 800; color: var(--brand); }
  .kpi.na-card .kpi-code, .kpi.warn-card .kpi-code { color: rgba(255,255,255,0.85); }
  .kpi-val { font-size: 21px; font-weight: 900; }
  .kpi-sub { font-size: 12px; color: var(--muted); }
  .kpi.na-card .kpi-sub, .kpi.warn-card .kpi-sub { color: rgba(255,255,255,0.9); }

  .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; border-bottom: 2px solid var(--line); }
  .tab-link { padding: 11px 18px; font-weight: 800; font-size: 14px; color: var(--muted); border-bottom: 3px solid transparent; margin-bottom: -2px; border-radius: 8px 8px 0 0; }
  .tab-link:hover { color: var(--ink); background: rgba(15,76,129,0.05); }
  .tab-link.active { color: var(--brand); border-bottom-color: var(--brand); background: #fff; }

  .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); }
  .card-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #edf1f6; }
  .card-head h2, .card-head h3 { margin: 0; font-size: 16px; font-weight: 900; }
  .sub { margin: 2px 0 0; font-size: 12.5px; color: var(--muted); }

  .status-choice { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
  .choice-box {
    border: 2px solid var(--line); border-radius: 12px; padding: 14px 16px; cursor: pointer; display: flex; gap: 10px; align-items: flex-start;
  }
  .choice-box.selected-na { border-color: #15803d; background: #f0fdf4; }
  .choice-box.selected-occ { border-color: #b45309; background: #fffbeb; }
  .choice-box input { margin-top: 4px; }

  .manual-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
  .step-card { background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid var(--brand); border-radius: 10px; padding: 14px 16px; }
  .step-card h4 { margin: 0 0 6px; font-size: 14.5px; color: var(--brand-dark); }
  .step-card p { margin: 0; font-size: 13px; color: #334155; white-space: pre-line; line-height: 1.6; }

  .contact-bar { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #1e3a8a; font-weight: 700; }

  .tbl-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { padding: 9px 10px; border-bottom: 1px solid #e9eef4; text-align: left; vertical-align: middle; }
  th { background: #f1f5f9; font-weight: 800; color: #1e293b; white-space: nowrap; }
  tr:last-child td { border-bottom: none; }
  .ctr { text-align: center; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 800; background: #e0e7ff; color: #1d4ed8; }
  .badge.ok { background: var(--ok-bg); color: var(--ok); }
  .badge.warn { background: var(--warn-bg); color: var(--warn); }

  .form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
  .form-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
  .field { display: flex; flex-direction: column; gap: 4px; }
  .field.span-2 { grid-column: span 2; }
  .field.span-3 { grid-column: span 3; }
  .field label { font-size: 12px; font-weight: 800; color: #334155; }
  .field input[type="text"], .field input[type="date"], .field select, .field textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 13px; background: #fff;
  }

  .paste-zone {
    margin-top: 8px; padding: 10px 12px; border: 2px dashed #93c5fd; border-radius: 10px;
    background: #f5f9ff; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px;
    font-size: 12.5px; color: #1e40af; cursor: pointer;
  }
  .paste-preview { max-height: 110px; border-radius: 6px; border: 1px solid #cbd5e1; margin-top: 6px; }

  .ev-grid { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 18px; align-items: start; }
  .ev-upload-form { background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
  .ev-list { display: flex; flex-direction: column; gap: 8px; }
  .ev-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 9px; background: #fff; }
  .ev-info { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .ev-name { font-weight: 800; color: var(--brand-dark); }
  .ev-meta { font-size: 12px; color: var(--muted); }
  .ev-note { width: 100%; font-size: 12px; color: #475569; }
  .assess-box { background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: 14px; }
  .empty-msg { padding: 16px; text-align: center; color: var(--muted); background: #f9fafb; border: 1px dashed var(--line); border-radius: 10px; font-size: 13px; }

  .modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 200; display: none; align-items: center; justify-content: center; padding: 16px; }
  .modal-backdrop.open { display: flex; }
  .modal { background: #fff; border-radius: 14px; width: 100%; max-width: 780px; max-height: 90vh; overflow-y: auto; padding: 20px 22px; box-shadow: 0 20px 50px rgba(0,0,0,0.28); }
  .modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--line); }
  .modal-head h3 { margin: 0; font-size: 17px; }
  .modal-close { border: none; background: #eef2f6; width: 32px; height: 32px; border-radius: 8px; font-size: 18px; cursor: pointer; }

  @media (max-width: 960px) {
    .kpi-grid, .manual-grid, .status-choice, .ev-grid { grid-template-columns: 1fr; }
    .form-grid, .form-grid.cols-2 { grid-template-columns: 1fr; }
    .field.span-2, .field.span-3 { grid-column: span 1; }
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <a class="topbar-back" href="/CSM/area.php?group=2&amp;section=2.1">← 2.1 영역</a>
    <a class="topbar-back" href="/CSM/">CSM 포털</a>
    <span class="topbar-title">2.1.1 산업재해 발생원인 조사·대응 매뉴얼</span>
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
      <small>CSM 평가 영역 2 · 2.1.1 산업재해 발생원인 조사 (최근 5년간, 1.0점 · 미발생 시 N/A)</small>
      <h1><?= $year ?>년 산업재해 무재해(N/A) 확인 및 발생 시 대응 매뉴얼</h1>
      <p>해당년도 산업재해 미발생 시 <strong>해당없음(N/A)</strong> 확인서를 출력·관리하고, 만일의 재해 발생 시 즉각 대응할 수 있는 <strong>대응·원인조사 매뉴얼</strong>을 상시 비치합니다.</p>
    </div>
    <div class="print-bar">
      <a class="btn ok-btn" href="accident_investigation_print.php?mode=na_cert&amp;year=<?= $year ?>" target="_blank">🖨️ 무재해(N/A) 확인서 출력</a>
      <a class="btn primary" href="accident_investigation_print.php?mode=manual&amp;year=<?= $year ?>" target="_blank">🖨️ 발생 시 대응 매뉴얼 출력</a>
      <a class="btn" href="accident_investigation_print.php?mode=register&amp;year=<?= $year ?>" target="_blank">🖨️ 최근 5년 조사대장 출력</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash ok">✅ <?= $e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash err">⚠️ <?= $e($flashError) ?></div><?php endif; ?>

  <section class="kpi-grid">
    <div class="kpi <?= $isNa ? 'na-card' : 'warn-card' ?>">
      <span class="kpi-code">2.1.1 평가 상태</span>
      <span class="kpi-val"><?= $isNa ? '해당없음 (N/A)' : number_format($score['earned'], 1) . ' / 1.0점' ?></span>
      <span class="kpi-sub"><?= $isNa ? '해당년도 산업재해 미발생 (감점 없음)' : '재해 발생 · 조사 및 대책 평가 대상' ?></span>
    </div>
    <div class="kpi">
      <span class="kpi-code">최근 5년 (<?= $year - 4 ?>~<?= $year ?>년)</span>
      <span class="kpi-val"><?= count($incidents) ?>건</span>
      <span class="kpi-sub"><?= count($incidents) === 0 ? '무재해 기록 유지 중 (개시: ' . $e($yearStatus['no_accident_since']) . ')' : '원인조사 및 재발방지대책 관리' ?></span>
    </div>
    <div class="kpi">
      <span class="kpi-code">발생 시 대응 매뉴얼</span>
      <span class="kpi-val">6단계 수립 완료</span>
      <span class="kpi-sub">개정일: <?= $e($manual['revision_date']) ?> · 상시 비치</span>
    </div>
    <div class="kpi">
      <span class="kpi-code">등록 증빙 자료</span>
      <span class="kpi-val"><?= count($evidences) ?>건</span>
      <span class="kpi-sub">확인서·매뉴얼·조사표 보관</span>
    </div>
  </section>

  <nav class="tabs">
    <a class="tab-link <?= $tab === 'status' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=status">✅ 무재해(N/A) 확인 &amp; 발생 대장</a>
    <a class="tab-link <?= $tab === 'manual' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=manual">📘 산업재해 발생 시 대응·조사 매뉴얼</a>
    <a class="tab-link <?= $tab === 'evidence' ? 'active' : '' ?>" href="?year=<?= $year ?>&amp;tab=evidence">📎 2.1.1 증빙 및 평가 관리</a>
  </nav>

  <?php if ($tab === 'status'): ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2><?= $year ?>년 산업재해 발생 여부 및 무재해(N/A) 확인 설정</h2>
          <p class="sub">평가표 원본 기준: <em>"단, 해당년도 산업재해가 발생하지 않았을 경우 해당없음(N/A) 표기"</em></p>
        </div>
        <a class="btn sm ok-btn" href="accident_investigation_print.php?mode=na_cert&amp;year=<?= $year ?>" target="_blank">🖨️ 무재해(N/A) 확인서 A4 출력</a>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_year_status">
        <input type="hidden" name="tab" value="status">

        <div class="status-choice">
          <label class="choice-box <?= $isNa ? 'selected-na' : '' ?>">
            <input type="radio" name="status" value="na" <?= $isNa ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <div>
              <strong>✅ 산업재해 미발생 — 해당없음 (N/A) 처리</strong>
              <div class="sub">해당년도 산업재해가 없어 CSM 2.1.1 항목을 <strong>해당없음(N/A)</strong>으로 표기하고, 무재해 확인서와 대응 매뉴얼을 비치합니다.</div>
            </div>
          </label>
          <label class="choice-box <?= !$isNa ? 'selected-occ' : '' ?>">
            <input type="radio" name="status" value="occurred" <?= !$isNa ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <div>
              <strong>⚠️ 산업재해 발생 — 원인조사·재발방지대책 관리</strong>
              <div class="sub">산업재해 발생 시 아래 대장에 사고 조사 내역을 기록하고, 산업재해조사표·대책 보고서를 증빙으로 등록합니다.</div>
            </div>
          </label>
        </div>

        <div class="form-grid">
          <div class="field"><label>무재해 개시일</label><input type="date" name="no_accident_since" value="<?= $e($yearStatus['no_accident_since']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>확인서 작성일자</label><input type="date" name="confirm_date" value="<?= $e($yearStatus['confirm_date']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>담당 부서</label><input type="text" name="department" value="<?= $e($yearStatus['department']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>안전관리책임자</label><input type="text" name="manager_name" value="<?= $e($yearStatus['manager_name']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"><label>대표이사</label><input type="text" name="ceo_name" value="<?= $e($yearStatus['ceo_name']) ?>" <?= $canManage ? '' : 'readonly' ?>></div>
          <div class="field"></div>
          <div class="field span-3"><label>무재해(해당없음) 확인 문구</label><textarea name="confirm_note" rows="2" <?= $canManage ? '' : 'readonly' ?>><?= $e($yearStatus['confirm_note']) ?></textarea></div>
        </div>

        <?php if ($canManage): ?>
        <div style="margin-top:12px; text-align:right;">
          <button type="submit" class="btn primary">상태 및 확인서 정보 저장</button>
        </div>
        <?php endif; ?>
      </form>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2>최근 5년간 산업재해 발생원인 조사 및 재발방지대책 대장 (<?= $year - 4 ?> ~ <?= $year ?>년)</h2>
          <p class="sub">재해가 없는 경우 '해당없음(무재해)' 상태로 유지되며, 발생 시 즉시 등록하여 원인과 재발방지대책을 추적 관리합니다.</p>
        </div>
        <?php if ($canManage): ?>
          <button type="button" class="btn primary" onclick="openIncidentModal()">+ 재해 조사 기록 등록</button>
        <?php endif; ?>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th class="ctr">발생일</th>
              <th>발생장소</th>
              <th>재해유형 / 정도</th>
              <th class="ctr">조사표 제출일</th>
              <th>직접·근본원인 (4M)</th>
              <th>재발방지대책</th>
              <th class="ctr">상태</th>
              <th class="ctr">관리</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$incidents): ?>
              <tr><td colspan="8" class="ctr" style="padding:26px; color:var(--ok); font-weight:800;">✅ 최근 5년간(<?= $year - 4 ?>~<?= $year ?>년) 등록된 산업재해가 없습니다. (해당없음 · 무재해 유지)</td></tr>
            <?php else: ?>
              <?php foreach ($incidents as $inc): ?>
              <tr>
                <td class="ctr"><strong><?= $e($inc['occur_date']) ?></strong></td>
                <td><?= $e($inc['location']) ?><div class="sub"><?= $e($inc['victim_info']) ?></div></td>
                <td><strong><?= $e($inc['accident_type']) ?></strong><div class="sub"><?= $e($inc['severity']) ?></div></td>
                <td class="ctr"><?= $e($inc['gov_report_date'] ?: '-') ?></td>
                <td><div><?= $e($inc['direct_cause']) ?></div><div class="sub"><?= $e($inc['root_cause_4m']) ?></div></td>
                <td><?= $e($inc['countermeasure']) ?><?php if ($inc['action_done_date']): ?><div class="sub">완료: <?= $e($inc['action_done_date']) ?></div><?php endif; ?></td>
                <td class="ctr"><span class="badge <?= $inc['status'] === '완료' ? 'ok' : 'warn' ?>"><?= $e($inc['status']) ?></span></td>
                <td class="ctr" style="white-space:nowrap;">
                  <?php if ($canManage): ?>
                    <button type="button" class="btn sm ghost" onclick='openIncidentModal(<?= json_encode($inc, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>수정</button>
                    <form method="post" onsubmit="return confirm('이 재해 조사 기록을 삭제할까요?');" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_incident">
                      <input type="hidden" name="tab" value="status">
                      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
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

  <?php elseif ($tab === 'manual'): ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2>📘 산업재해 발생 시 비상대응 및 원인조사 매뉴얼</h2>
          <p class="sub">주식회사 현대기전 표준 절차 · 관리책임자: <?= $e($manual['manager_name']) ?> · 승인: <?= $e($manual['ceo_name']) ?> 대표이사 · 개정일: <?= $e($manual['revision_date']) ?></p>
        </div>
        <div style="display:flex; gap:8px;">
          <?php if ($canManage): ?>
            <button type="button" class="btn sm ghost" onclick="document.getElementById('manualEditForm').style.display = document.getElementById('manualEditForm').style.display === 'none' ? 'block' : 'none';">✏️ 매뉴얼 편집</button>
          <?php endif; ?>
          <a class="btn sm primary" href="accident_investigation_print.php?mode=manual&amp;year=<?= $year ?>" target="_blank">🖨️ 매뉴얼 A4 공식 출력</a>
        </div>
      </div>

      <div class="manual-grid">
        <?php for ($i = 1; $i <= 6; $i++): ?>
          <div class="step-card">
            <h4><?= $e($manual['step' . $i . '_title']) ?></h4>
            <p><?= $e($manual['step' . $i . '_desc']) ?></p>
          </div>
        <?php endfor; ?>
      </div>

      <div class="tbl-wrap" style="margin-bottom:14px;">
        <table>
          <thead>
            <tr>
              <th>구분</th>
              <th>보고 대상 및 기준 (산업안전보건법 제57조)</th>
              <th>보고 기한</th>
              <th>제출·보존 서류</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>중대재해</strong></td>
              <td>사망자 1명 이상 / 3개월 이상 요양 부상자 동시 2명 이상 / 부상자·직업성질병자 동시 10명 이상</td>
              <td><strong>발생 인지 즉시 (지체 없이)</strong></td>
              <td>관할 지방고용노동관서 즉시 보고 + 현장보존 + 원인조사서</td>
            </tr>
            <tr>
              <td><strong>일반 산업재해</strong></td>
              <td>사망자 또는 <strong>3일 이상의 휴업</strong>이 필요한 부상을 입거나 질병에 걸린 사람이 발생한 경우</td>
              <td><strong>발생일로부터 1개월 이내</strong></td>
              <td><strong>산업재해조사표</strong> 제출 (고용노동부) + 원인조사·재발방지대책서</td>
            </tr>
            <tr>
              <td><strong>기록 보존</strong></td>
              <td>사업장 개요·근로자 인적사항, 재해 발생 일시·장소, 원인 및 과정, 재발방지 계획</td>
              <td><strong>3년 이상 보존 (CSM 5년)</strong></td>
              <td>산업재해 발생 기록부, 조사표 사본, 개선 전·후 증빙, TBM 공유 서명부</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="contact-bar">
        📞 비상 보고·연락망: <?= $e($manual['emergency_contacts']) ?>
      </div>

      <?php if ($canManage): ?>
      <form method="post" id="manualEditForm" style="display:none; margin-top:16px; padding-top:16px; border-top:1px solid var(--line);">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="save_manual">
        <input type="hidden" name="tab" value="manual">
        <div class="form-grid" style="margin-bottom:12px;">
          <div class="field"><label>개정일</label><input type="date" name="revision_date" value="<?= $e($manual['revision_date']) ?>"></div>
          <div class="field"><label>관리책임자</label><input type="text" name="manager_name" value="<?= $e($manual['manager_name']) ?>"></div>
          <div class="field"><label>대표이사</label><input type="text" name="ceo_name" value="<?= $e($manual['ceo_name']) ?>"></div>
        </div>
        <div class="form-grid cols-2">
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="field">
              <label><?= $i ?>단계 제목</label>
              <input type="text" name="step<?= $i ?>_title" value="<?= $e($manual['step' . $i . '_title']) ?>">
              <textarea name="step<?= $i ?>_desc" rows="4" style="margin-top:4px;"><?= $e($manual['step' . $i . '_desc']) ?></textarea>
            </div>
          <?php endfor; ?>
          <div class="field span-2"><label>비상 보고·연락망</label><input type="text" name="emergency_contacts" value="<?= $e($manual['emergency_contacts']) ?>"></div>
        </div>
        <div style="margin-top:12px; text-align:right;">
          <button type="submit" class="btn primary">매뉴얼 저장</button>
        </div>
      </form>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2>📎 2.1.1 증빙 자료 및 평가 수준 관리</h2>
          <p class="sub">무재해 시 근로복지공단 <strong>산재요양승인/반려여부 확인서</strong> 또는 출력한 <strong>무재해 확인서·대응 매뉴얼</strong>을 첨부해 두면 심사에 완벽히 대응할 수 있습니다.</p>
        </div>
      </div>

      <div class="ev-grid">
        <div>
          <?php if ($canManage): ?>
          <form method="post" enctype="multipart/form-data" class="ev-upload-form">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="upload_evidence">
            <input type="hidden" name="tab" value="evidence">
            <input type="hidden" name="evidence_base64" class="js-paste-b64" value="">
            <div class="form-row">
              <div class="field">
                <label>증빙 구분</label>
                <select name="kind">
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
              <span class="js-paste-label">📋 이곳을 클릭 후 <strong>Ctrl+V</strong> 로 스크린샷을 바로 붙여넣거나, 버튼을 누르세요.</span>
              <button type="button" class="btn sm ghost js-paste-btn">📋 스크린샷 붙여넣기</button>
              <img class="paste-preview js-paste-preview" alt="미리보기" style="display:none;">
            </div>
            <div class="form-row" style="margin-top:8px;">
              <div class="field" style="flex:1;"><input type="text" name="note" placeholder="증빙 설명/메모 (선택)"></div>
              <button type="submit" class="btn primary">증빙 등록</button>
            </div>
          </form>
          <?php endif; ?>

          <div class="ev-list">
            <?php if (!$evidences): ?>
              <div class="empty-msg">등록된 2.1.1 증빙 파일이 없습니다.</div>
            <?php else: ?>
              <?php foreach ($evidences as $ev): ?>
                <div class="ev-item">
                  <div class="ev-info">
                    <span class="badge"><?= $e($kinds[$ev['kind']] ?? $ev['kind']) ?></span>
                    <a href="?download_evidence=<?= (int)$ev['id'] ?>&amp;inline=1" target="_blank" class="ev-name"><?= $e($ev['original_name']) ?></a>
                    <span class="ev-meta"><?= ai_fmt_size((int)$ev['size']) ?> · <?= $e($ev['uploaded_by']) ?> · <?= $e(substr((string)$ev['uploaded_at'], 0, 10)) ?></span>
                    <?php if ($ev['note'] !== ''): ?><div class="ev-note"><?= $e($ev['note']) ?></div><?php endif; ?>
                  </div>
                  <div style="display:flex; gap:6px;">
                    <a href="?download_evidence=<?= (int)$ev['id'] ?>" class="btn sm ghost">다운로드</a>
                    <?php if ($canManage): ?>
                    <form method="post" onsubmit="return confirm('이 증빙 파일을 삭제할까요?');" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="action" value="delete_evidence">
                      <input type="hidden" name="tab" value="evidence">
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
          <h4>2.1.1 평가 수준 설정</h4>
          <?php if ($isNa): ?>
            <p class="sub" style="color:var(--ok); font-weight:700;">현재 <strong>해당없음(N/A · 무재해)</strong> 상태입니다. 2영역 환산 점수 계산 시 1.0점 분모에서 자동 제외되어 불이익이 없습니다.</p>
          <?php else:
            $curMult = $score['assessment'] !== null ? (string)$score['assessment']['multiplier'] : 'auto';
            $curNote = $score['assessment']['note'] ?? '';
          ?>
            <p class="sub">재해 발생 시 원인조사·재발방지대책 이행 수준을 설정합니다.</p>
            <?php if ($canManage): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="action" value="save_assessment">
              <input type="hidden" name="tab" value="evidence">
              <div class="field">
                <label>평가 수준</label>
                <select name="multiplier">
                  <option value="auto" <?= $curMult === 'auto' ? 'selected' : '' ?>>자동 (증빙 등록 시 100% · 1.0점)</option>
                  <option value="1" <?= $curMult === '1' || $curMult === '1.0' ? 'selected' : '' ?>>100% (1.0점) — 모두 만족</option>
                  <option value="0.9" <?= $curMult === '0.9' ? 'selected' : '' ?>>90% (0.9점) — 대부분 만족</option>
                  <option value="0.8" <?= $curMult === '0.8' ? 'selected' : '' ?>>80% (0.8점) — 일부 만족</option>
                  <option value="0.5" <?= $curMult === '0.5' ? 'selected' : '' ?>>50% (0.5점) — 미흡</option>
                  <option value="0" <?= $curMult === '0' || $curMult === '0.0' ? 'selected' : '' ?>>0% (0.0점) — 없음</option>
                </select>
              </div>
              <div class="field" style="margin-top:8px;">
                <label>검토 의견</label>
                <textarea name="note" rows="2"><?= $e($curNote) ?></textarea>
              </div>
              <button type="submit" class="btn primary" style="margin-top:8px; width:100%;">평가 수준 저장</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>

<!-- Modal: Incident Add/Edit -->
<div class="modal-backdrop" id="incidentModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="incModalTitle">산업재해 원인조사 기록 등록</h3>
      <button type="button" class="modal-close" onclick="document.getElementById('incidentModal').classList.remove('open')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="save_incident">
      <input type="hidden" name="tab" value="status">
      <input type="hidden" name="id" id="inc_id" value="0">
      <div class="form-grid">
        <div class="field"><label>발생일자 *</label><input type="date" name="occur_date" id="inc_occur_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field"><label>발생장소</label><input type="text" name="location" id="inc_location" placeholder="예: 한라시멘트 옥계공장 전기실"></div>
        <div class="field"><label>재해자 (직종/이니셜)</label><input type="text" name="victim_info" id="inc_victim_info" placeholder="예: 전공 홍○○"></div>
        <div class="field"><label>재해유형</label><input type="text" name="accident_type" id="inc_accident_type" placeholder="예: 떨어짐, 끼임, 감전, 넘어짐"></div>
        <div class="field">
          <label>상해 정도</label>
          <select name="severity" id="inc_severity">
            <option value="휴업 3일 이상">휴업 3일 이상 (조사표 대상)</option>
            <option value="중대재해">중대재해 (즉시 보고 대상)</option>
            <option value="경상(휴업 3일 미만)">경상 (휴업 3일 미만)</option>
          </select>
        </div>
        <div class="field"><label>산업재해조사표 제출일</label><input type="date" name="gov_report_date" id="inc_gov_report_date"></div>
        <div class="field span-3"><label>직접원인</label><textarea name="direct_cause" id="inc_direct_cause" rows="2"></textarea></div>
        <div class="field span-3"><label>근본원인 (4M: 인적·설비·작업·관리)</label><textarea name="root_cause_4m" id="inc_root_cause_4m" rows="2"></textarea></div>
        <div class="field span-3"><label>재발방지대책</label><textarea name="countermeasure" id="inc_countermeasure" rows="3"></textarea></div>
        <div class="field"><label>대책 완료일</label><input type="date" name="action_done_date" id="inc_action_done_date"></div>
        <div class="field">
          <label>진행 상태</label>
          <select name="status" id="inc_status">
            <option value="조사중">조사중</option>
            <option value="대책이행중">대책이행중</option>
            <option value="완료">완료</option>
          </select>
        </div>
      </div>
      <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="btn ghost" onclick="document.getElementById('incidentModal').classList.remove('open')">취소</button>
        <button type="submit" class="btn primary">저장</button>
      </div>
    </form>
  </div>
</div>

<script>
function openIncidentModal(data) {
  document.getElementById('incModalTitle').textContent = data ? '산업재해 조사 기록 수정' : '산업재해 원인조사 기록 등록';
  const fields = ['id','occur_date','location','victim_info','accident_type','severity','gov_report_date','direct_cause','root_cause_4m','countermeasure','action_done_date','status'];
  const defaults = { id: 0, occur_date: '<?= date('Y-m-d') ?>', severity: '휴업 3일 이상', status: '조사중' };
  fields.forEach(f => {
    const el = document.getElementById('inc_' + f);
    if (el) el.value = data ? (data[f] ?? '') : (defaults[f] ?? '');
  });
  document.getElementById('incidentModal').classList.add('open');
}

function applyPastedImage(zone, blob) {
  const container = zone.closest('form') || zone.parentElement;
  const b64Input = container.querySelector('.js-paste-b64');
  const preview = zone.querySelector('.js-paste-preview');
  const label = zone.querySelector('.js-paste-label');
  const reader = new FileReader();
  reader.onload = () => {
    if (b64Input) b64Input.value = reader.result;
    if (preview) { preview.src = reader.result; preview.style.display = 'block'; }
    if (label) label.innerHTML = '✅ <strong>스크린샷이 준비되었습니다.</strong> 증빙 등록 버튼을 눌러주세요.';
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
          if (imgType) { applyPastedImage(zone, await ci.getType(imgType)); return; }
        }
        alert('클립보드에 복사된 이미지가 없습니다.');
      } catch (err) {
        zone.focus();
        alert('영역을 클릭한 뒤 Ctrl+V 를 눌러주세요.');
      }
    });
  }
});
</script>
</body>
</html>
