<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Seoul');

// ============================================================
// index.php  —  TBM 문서 생성 입력 화면
// ============================================================

require_once __DIR__ . '/tbm_db.php';
require_once __DIR__ . '/tbm_functions.php';
require_once __DIR__ . '/../risk_assessment/auth.php';

// risk_assessment 세션 인증 — 미로그인 시 작업목록으로 리다이렉트
$raUser = auth_current_user();
if ($raUser === null) {
    header('Location: ../risk_assessment/task_select.php');
    exit;
}

$isAdmin    = auth_is_admin($raUser);
$userTeam   = auth_normalize_team_name((string)($raUser['team'] ?? ''));
$userRole   = (string)($raUser['role'] ?? '');

// 운영자 = admin 또는 safety_manager (안전관리자)
$isOperator = $isAdmin || $userRole === 'safety_manager';

// AI 버튼은 운영자만 볼 수 있음
$showAiButtons = $isOperator;

// 팀 표시명 매핑: risk_assessment 팀 키 → TBM 표시명
// 공사팀-전기와 공사팀-모터는 '공사팀'으로 통합
$teamDisplayMap = [
    '공사팀-전기' => '공사팀',
    '공사팀-모터' => '공사팀',
    '가스팀'     => '가스팀',
    '제조팀'     => '제조팀',
    '안전관리'   => '운영자',
];

// 현재 사용자 표시 레이블
$userDisplayTeam = tbm_normalize_display_team_name($userTeam);
$userLabel = $isOperator ? '운영자' : ($userDisplayTeam ?: auth_role_label($userRole));

// TBM 참석 인명부에서 제외할 이름 (괄호 표기 제거 후 기준)
$tbmExcludeNames = ['진종철'];

// 이름에서 "(역할)" 괄호 표기 제거: "윤택천(작업지휘자)" → "윤택천"
$tbmCleanName = static function(string $name): string {
    return trim((string)preg_replace('/\s*\([^)]*\)/', '', $name));
};

// ── 팀별 인명부 구성 (risk_assessment auth 기반) ─────────────────────────
$teamMembers = [];
$allTeams = auth_read_teams(); // ['공사팀-전기','공사팀-모터','가스팀','제조팀','안전관리', ...]

foreach ($allTeams as $raTeam) {
    $displayName = tbm_normalize_display_team_name($raTeam);

    // 비운영자: 본인 표시팀(통합명 기준)만 표시
    if (!$isOperator && $displayName !== $userDisplayTeam) {
        continue;
    }

    // 운영자(안전관리) 팀은 인명부 선택에서 제외 (TBM 참석자 아님)
    if (auth_team_key($raTeam) === auth_team_key('안전관리')) {
        continue;
    }

    if (!isset($teamMembers[$displayName])) {
        $teamMembers[$displayName] = [];
    }

    $names = auth_team_member_names($raTeam, ['worker', 'leader', 'manager']);
    foreach ($names as $rawName) {
        $cleanName = $tbmCleanName($rawName);
        if ($cleanName === '' || in_array($cleanName, $tbmExcludeNames, true)) {
            continue;
        }
        $teamMembers[$displayName][] = ['id' => 0, 'name' => $cleanName, 'position' => ''];
    }
}

$requestedTeam = trim((string)($_GET['team'] ?? ''));
$initialTeamValue = '';
if ($requestedTeam !== '' && isset($teamMembers[$requestedTeam])) {
    $initialTeamValue = $requestedTeam;
} elseif (isset($teamMembers[$userDisplayTeam])) {
    $initialTeamValue = $userDisplayTeam;
} elseif (!empty($teamMembers)) {
    $initialTeamValue = array_keys($teamMembers)[0];
}

$selectedTeam = $initialTeamValue;

// ── 강사 정보 로드 (TBM DB) ────────────────────────────────────────
$instructor = ['name' => '김남균', 'position' => '과장'];
try {
    $pdo = tbm_db();
    $stmtInst = $pdo->query("SELECT name, position FROM tbm_instructors WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    if ($row = $stmtInst->fetch()) {
        $instructor = $row;
    }
} catch (Throwable $e) {
    // 기본값 사용
}

$teamMembersJson = json_encode($teamMembers, JSON_UNESCAPED_UNICODE);
$initialTeamJson = json_encode($initialTeamValue, JSON_UNESCAPED_UNICODE);


// ── 날짜 선택에 따라 기존 일지 로드 (GET ?date=) ──────────────

$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$existingDoc = null;
try {
    $existingDoc = tbm_get_document_for_team($selectedDate, $selectedTeam);
} catch (Throwable $e) {
    $existingDoc = null;
}

$sharedContentDoc = $existingDoc;
if ($sharedContentDoc === null) {
    try {
        $sharedContentDoc = tbm_get_document($selectedDate);
    } catch (Throwable $e) {
        $sharedContentDoc = null;
    }
}

$cachedAiDoc = null;
$cachePath = __DIR__ . '/cache/tbm_ai_' . preg_replace('/[^0-9\-]/', '', $selectedDate) . '.json';
if (is_file($cachePath)) {
    $rawCache = file_get_contents($cachePath);
    if ($rawCache !== false) {
        $decodedCache = json_decode($rawCache, true);
        if (is_array($decodedCache)) {
            $cachedAiDoc = $decodedCache;
        }
    }
}

// 폼 기본값: 기존 일지가 있으면 해당 값, 없으면 기본값
$defaults = tbm_default_data();

$formEduTitle    = $sharedContentDoc['edu_title']  ?? $cachedAiDoc['edu_title']  ?? $defaults['edu_title'];
$formLeftContent = $sharedContentDoc['body_text']  ?? $cachedAiDoc['body_text']  ?? '';
$formQuiz1       = $sharedContentDoc['quiz_1']     ?? $cachedAiDoc['quiz_1']     ?? '';
$formQuiz2       = $sharedContentDoc['quiz_2']     ?? $cachedAiDoc['quiz_2']     ?? '';
$formQuiz3       = $sharedContentDoc['quiz_3']     ?? $cachedAiDoc['quiz_3']     ?? '';
$formWork1       = $existingDoc['today_work_1'] ?? '';
$formWork2       = $existingDoc['today_work_2'] ?? '';
$formRemarks     = $existingDoc['remarks']    ?? '';
$formSourceUrl   = $sharedContentDoc['source_url'] ?? $cachedAiDoc['source_url'] ?? '';
$formImageFile   = $sharedContentDoc['image_file'] ?? $cachedAiDoc['image_file'] ?? '';

// ── 최근 생성 목록 (최근 10건) ────────────────────────────────

$recentDocs = [];
$teamRecentDocs = array_fill_keys(array_keys($teamMembers), []);
$fallbackRecentDocs = [];
try {
    $pdo  = tbm_db();
    $stmt = $pdo->query(
        'SELECT d.doc_date, d.team, d.generation_status, d.output_filename,
                d.generated_at, d.updated_at,
                COALESCE(d.generated_at, d.updated_at) AS recent_at,
                c.edu_title
           FROM tbm_documents d
      LEFT JOIN tbm_accident_content c ON d.content_id = c.id
          ORDER BY COALESCE(d.generated_at, d.updated_at) DESC, d.id DESC
          LIMIT 200'
    );
    $recentDocs = $stmt->fetchAll();
    foreach ($recentDocs as $doc) {
        $rawTeamName = trim((string)($doc['team'] ?? ''));
        if ($rawTeamName === '') {
            if (count($fallbackRecentDocs) < 10) {
                $fallbackRecentDocs[] = $doc;
            }
            continue;
        }

        $teamName = tbm_normalize_display_team_name(auth_normalize_team_name($rawTeamName));
        if ($teamName === '') {
            $fallbackRecentDocs[] = $doc;
            continue;
        }

        if ($isOperator && !isset($teamRecentDocs[$teamName])) {
            $teamRecentDocs[$teamName] = [];
        }

        if (isset($teamRecentDocs[$teamName])) {
            if (count($teamRecentDocs[$teamName]) >= 10) {
                continue;
            }
            $doc['team'] = $teamName;
            $teamRecentDocs[$teamName][] = $doc;
        }
    }
} catch (Throwable $e) {
    $recentDocs = [];
}

$teamRecentDocsJson = json_encode($teamRecentDocs, JSON_UNESCAPED_UNICODE);
$fallbackRecentDocsJson = json_encode($fallbackRecentDocs, JSON_UNESCAPED_UNICODE);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>TBM 일지 생성</title>
<style>
* { box-sizing: border-box; }
body { font-family: "Malgun Gothic", sans-serif; margin: 0; background: #f5f6f7; color: #1a1a1a; }
.wrap { display: flex; gap: 20px; max-width: 1300px; margin: 30px auto; padding: 0 20px; }
.form-panel { flex: 1 1 700px; background: #fff; border: 1px solid #dde0e4; border-radius: 6px; padding: 28px 32px; }
.form-panel h2 { margin: 0 0 20px; font-size: 1.2rem; border-bottom: 2px solid #1a56db; padding-bottom: 10px; color: #1a56db; }
.side-panel { flex: 0 0 280px; display: flex; flex-direction: column; gap: 16px; }
.side-box { background: #fff; border: 1px solid #dde0e4; border-radius: 6px; padding: 18px 20px; }
.side-box h3 { margin: 0 0 12px; font-size: 0.95rem; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
.field-group { margin-bottom: 16px; }
.field-group label { display: block; font-weight: 600; font-size: 0.88rem; color: #374151; margin-bottom: 5px; }
.field-group input[type="date"], .field-group input[type="text"], .field-group select, .field-group textarea { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-family: inherit; font-size: 0.9rem; background: #fafafa; }
.field-group textarea { resize: vertical; min-height: 110px; line-height: 1.6; }
.field-group input:focus, .field-group textarea:focus, .field-group select:focus { outline: none; border-color: #1a56db; background: #fff; }
.section-title { font-size: 0.82rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 22px 0 10px; padding-bottom: 4px; border-bottom: 1px dashed #e5e7eb; }

/* ── 인명부 그리드 & 삭제 버튼 스타일 ── */
.names-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.name-wrapper { position: relative; display: flex; align-items: center; }
.name-wrapper input { width: 100%; padding: 6px 22px 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.88rem; text-align: center; background: #fafafa; font-family: inherit; transition: border-color .2s; }
.name-wrapper input:focus { outline: none; border-color: #1a56db; background: #fff; }
.name-del-btn { position: absolute; right: 2px; background: transparent; border: none; color: #9ca3af; font-size: 1.2rem; cursor: pointer; padding: 0 5px; line-height: 1; }
.name-del-btn:hover { color: #ef4444; }

.btn-row { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
.btn { padding: 10px 20px; border: none; border-radius: 4px; font-family: inherit; font-size: 0.92rem; font-weight: 600; cursor: pointer; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.btn-primary { background: #1a56db; color: #fff; }
.btn-secondary { background: #6b7280; color: #fff; }
.btn-ai { background: #7c3aed; color: #fff; }
.btn-sm { padding: 5px 12px; font-size: 0.82rem; border: none; border-radius: 3px; cursor: pointer; font-family: inherit; }
.btn-sm-primary { background: #1a56db; color: #fff; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.78rem; font-weight: 600; }
.badge-success { background: #dcfce7; color: #166534; }
.badge-pending { background: #fef9c3; color: #854d0e; }
.badge-failed { background: #fee2e2; color: #991b1b; }
.notice-existing { background: #eff6ff; border: 1px solid #93c5fd; border-radius: 4px; padding: 10px 14px; font-size: 0.88rem; color: #1d4ed8; margin-bottom: 16px; }
.recent-groups { display: flex; flex-direction: column; gap: 12px; }
.recent-group { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; background: #f8fafc; }
.recent-group.is-selected { border-color: #93c5fd; background: #eff6ff; }
.recent-group-title { font-size: 0.86rem; font-weight: 700; color: #1f2937; margin: 0 0 8px; }
.recent-list { list-style: none; margin: 0; padding: 0; }
.recent-list li { padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 0.84rem; }
.recent-list li:last-child { border-bottom: none; }
.recent-list a { color: #1a56db; text-decoration: none; }
.recent-list a:hover { text-decoration: underline; }
.recent-date { font-weight: 600; }
.recent-meta { margin-top: 3px; font-size: .76rem; color: #6b7280; }
#ai-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 999; align-items: center; justify-content: center; flex-direction: column; color: #fff; font-size: 1.1rem; gap: 14px; }
#ai-overlay.active { display: flex; }
.spinner { width: 40px; height: 40px; border: 4px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div id="ai-overlay">
    <div class="spinner"></div>
    <div>AI가 중대재해 내용을 생성하고 있습니다…</div>
    <div style="font-size:.88rem;opacity:.8;">약 10~20초 소요됩니다</div>
</div>

<div class="wrap">

    <div class="form-panel">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <h2 style="margin-bottom:0;">📋 TBM 일지 생성</h2>
            <div style="font-size:.85rem;color:#475569;">
                <strong><?= h($userLabel) ?></strong>
                <a href="../risk_assessment/task_select.php" style="margin-left:10px;">작업목록</a>
                <a href="../risk_assessment/task_select.php?logout=1" style="margin-left:10px;">로그아웃</a>
            </div>
        </div>

        <?php if ($existingDoc !== null): ?>
        <div class="notice-existing">
            ℹ️ <strong><?= h($selectedDate) ?></strong> 일지가 이미 존재합니다. 수정 후 생성하면 덮어씁니다.
            <?php if (!empty($existingDoc['output_filename'])): ?>
            — <a href="view_output.php?file=<?= h(rawurlencode($existingDoc['output_filename'])) ?>" target="_blank">기존 파일 열기</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form action="generate.php" method="post" id="tbm-form">

            <div class="field-group">
                <label for="doc_date">작업일자</label>
                <input type="date" id="doc_date" name="doc_date" value="<?= h($selectedDate) ?>" onchange="location.href='index.php?date='+this.value">
            </div>

            <div class="section-title">강사 정보</div>
            <div style="display:flex;gap:12px;">
                <div class="field-group" style="flex:1;">
                    <label for="instructor_name">강사 성명</label>
                    <input type="text" id="instructor_name" name="instructor_name" value="<?= h($instructor['name']) ?>">
                </div>
                <div class="field-group" style="flex:1;">
                    <label for="instructor_position">직위</label>
                    <input type="text" id="instructor_position" name="instructor_position" value="<?= h($instructor['position']) ?>">
                </div>
            </div>

            <div class="section-title">
                참석 인명부
                <span style="font-size:0.75rem; color:#6b7280; font-weight:normal; margin-left:10px;">* 우측에서 팀을 선택하거나 인원을 관리하세요.</span>
            </div>
            <div class="names-grid" id="names-grid">
                </div>

            <div class="section-title">금일 예정 작업</div>
            <div class="field-group">
                <label for="today_work_1">작업 내용 1</label>
                <input type="text" id="today_work_1" name="today_work_1" value="<?= h($formWork1) ?>" placeholder="예) 3층 배관 보온재 설치">
            </div>
            <div class="field-group">
                <label for="today_work_2">작업 내용 2</label>
                <input type="text" id="today_work_2" name="today_work_2" value="<?= h($formWork2) ?>" placeholder="예) 지하 1층 전기 배선">
            </div>

            <div class="section-title">
                교육내용 (뒷면 2페이지)
                <?php if ($showAiButtons): ?>
                <button type="button" class="btn btn-sm btn-ai" style="margin-left:10px;" onclick="aiGenerate(false)">✨ AI 자동생성</button>
                <button type="button" class="btn btn-sm btn-ai" style="margin-left:6px;background:#dc2626;" onclick="aiGenerate(true)">🆕 새 기사로 생성</button>
                <?php endif; ?>
            </div>

            <div class="field-group">
                <label for="edu_title">교육 제목</label>
                <input type="text" id="edu_title" name="edu_title" value="<?= h($formEduTitle) ?>">
            </div>

            <div class="field-group">
                <label for="left_content">중대재해 전파 본문</label>
                <textarea id="left_content" name="left_content" style="min-height:160px;" oninput="updateBodyCounter()"><?= h($formLeftContent) ?></textarea>
                <div id="body-counter" style="margin-top:5px; font-size:0.82rem; padding:5px 8px; border-radius:4px; background:#f3f4f6; color:#374151;">
                    줄수 계산 중…
                </div>
            </div>

            <div class="field-group"><label for="quiz1">안전퀴즈 1번</label><textarea id="quiz1" name="quiz1"><?= h($formQuiz1) ?></textarea></div>
            <div class="field-group"><label for="quiz2">안전퀴즈 2번</label><textarea id="quiz2" name="quiz2"><?= h($formQuiz2) ?></textarea></div>
            <div class="field-group"><label for="quiz3">안전퀴즈 3번</label><textarea id="quiz3" name="quiz3"><?= h($formQuiz3) ?></textarea></div>

            <div class="section-title">특기사항</div>
            <div class="field-group">
                <textarea name="remarks" style="min-height:60px;" placeholder="특이사항 없음"><?= h($formRemarks) ?></textarea>
            </div>

            <input type="hidden" id="selected_team" name="selected_team" value="<?= h($initialTeamValue) ?>">
            <input type="hidden" id="source_url" name="source_url" value="<?= h($formSourceUrl) ?>">
            <input type="hidden" id="image_file" name="image_file" value="<?= h($formImageFile) ?>">

            <div class="btn-row">
                <button type="submit" class="btn btn-primary">📄 문서 생성</button>
                <button type="button" class="btn btn-secondary" onclick="resetDraftAndReload()">🔄 전체 폼 초기화</button>
            </div>
        </form>
    </div><div class="side-panel">

        <div class="side-box">
            <h3>👥 참석 인원 관리</h3>
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:#374151; margin:0;">1. 팀 선택 (자동 채움)</label>
                <button type="button" class="btn btn-sm" style="background:#f3f4f6; color:#4b5563; border:1px solid #d1d5db;" onclick="clearAllSlots()">전체 비우기</button>
            </div>
            <div style="display:flex; gap:5px; margin-bottom:15px; flex-wrap:wrap;">
                <?php foreach(array_keys($teamMembers) as $tName): ?>
                    <button type="button" class="btn btn-sm btn-secondary team-btn" onclick="applyTeam('<?= h($tName) ?>')">
                        <?= h($tName) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            
            <div style="border-top: 1px dashed #e5e7eb; padding-top: 12px; margin-top: 10px;">
                <label style="display:block; font-size:0.85rem; font-weight:600; color:#374151; margin-bottom:8px;">2. 인원 직접 추가</label>
                <div style="display:flex; gap:6px; margin-bottom:8px;">
                    <input type="text" id="add_name" placeholder="이름 입력" style="flex:1; padding:6px; border:1px solid #d1d5db; border-radius:4px; font-size:0.85rem;" onkeypress="if(event.keyCode===13){event.preventDefault();addNameSlot();}">
                    <button type="button" class="btn btn-sm btn-primary" onclick="addNameSlot()">이름 추가</button>
                </div>
                <div style="display:flex; gap:6px;">
                    <input type="number" id="add_slots" min="1" placeholder="칸 수 입력" style="flex:1; padding:6px; border:1px solid #d1d5db; border-radius:4px; font-size:0.85rem;" onkeypress="if(event.keyCode===13){event.preventDefault();addBlankSlots();}">
                    <button type="button" class="btn btn-sm btn-primary" style="white-space:nowrap;" onclick="addBlankSlots()">빈칸 추가</button>
                    <button type="button" class="btn btn-sm btn-danger" style="white-space:nowrap; background:#ef4444; color:#fff;" onclick="removeLastSlot()">끝칸 삭제</button>
                </div>
                <p style="font-size:0.75rem; color:#9ca3af; margin:8px 0 0; line-height:1.4;">
                    * 특정 인원을 빼고 싶을 땐 입력칸 우측의 <b>'X'</b> 버튼을 누르면 즉시 삭제되고 번호가 당겨집니다.
                </p>
            </div>
        </div>

        <div class="side-box">
            <h3>📁 최근 생성 일지</h3>
            <p style="font-size:.85rem; color:#475569; margin:0 0 10px;">팀별 최근 생성 일지를 구분해서 표시합니다. 현재 선택한 팀은 맨 위에 강조됩니다.</p>
            <div id="recent-list-container"></div>
        </div>
    </div>
</div>

<script>
// PHP에서 넘겨받은 팀별 인명부 JSON
const teamData = <?= $teamMembersJson ?>;
const recentDocsByTeam = <?= $teamRecentDocsJson ?>;
const fallbackRecentDocs = <?= $fallbackRecentDocsJson ?>;
const initialTeam = <?= $initialTeamJson ?>;
let currentSlotCount = 0;
let selectedRecentTeam = '';
const formDraftVersion = 'v1';
let draftAutosaveEnabled = false;

function getDraftDateValue() {
    return document.getElementById('doc_date')?.value || 'unknown-date';
}

function getDraftStorageKey(dateValue = getDraftDateValue()) {
    return 'tbm-form-draft:' + formDraftVersion + ':' + String(dateValue || 'unknown-date');
}

function collectNameSlotValues() {
    return Array.from(document.querySelectorAll('#names-grid .name-wrapper input')).map(input => input.value || '');
}

function collectDraftPayload() {
    return {
        doc_date: document.getElementById('doc_date')?.value || '',
        instructor_name: document.getElementById('instructor_name')?.value || '',
        instructor_position: document.getElementById('instructor_position')?.value || '',
        today_work_1: document.getElementById('today_work_1')?.value || '',
        today_work_2: document.getElementById('today_work_2')?.value || '',
        edu_title: document.getElementById('edu_title')?.value || '',
        left_content: document.getElementById('left_content')?.value || '',
        quiz1: document.getElementById('quiz1')?.value || '',
        quiz2: document.getElementById('quiz2')?.value || '',
        quiz3: document.getElementById('quiz3')?.value || '',
        remarks: document.querySelector('textarea[name="remarks"]')?.value || '',
        selected_team: document.getElementById('selected_team')?.value || '',
        source_url: document.getElementById('source_url')?.value || '',
        image_file: document.getElementById('image_file')?.value || '',
        names: collectNameSlotValues(),
        saved_at: new Date().toISOString(),
    };
}

function saveFormDraft() {
    if (!draftAutosaveEnabled) {
        return;
    }
    try {
        const payload = collectDraftPayload();
        localStorage.setItem(getDraftStorageKey(payload.doc_date), JSON.stringify(payload));
    } catch (error) {
        console.warn('TBM draft save failed:', error);
    }
}

function loadFormDraft(dateValue = getDraftDateValue()) {
    try {
        const raw = localStorage.getItem(getDraftStorageKey(dateValue));
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
        console.warn('TBM draft load failed:', error);
        return null;
    }
}

function applyDraftToForm(draft) {
    if (!draft || typeof draft !== 'object') {
        return;
    }

    const assignValue = (id, value) => {
        const element = document.getElementById(id);
        if (element && typeof value === 'string') {
            element.value = value;
        }
    };

    assignValue('instructor_name', draft.instructor_name || '');
    assignValue('instructor_position', draft.instructor_position || '');
    assignValue('today_work_1', draft.today_work_1 || '');
    assignValue('today_work_2', draft.today_work_2 || '');
    assignValue('edu_title', draft.edu_title || '');
    assignValue('left_content', draft.left_content || '');
    assignValue('quiz1', draft.quiz1 || '');
    assignValue('quiz2', draft.quiz2 || '');
    assignValue('quiz3', draft.quiz3 || '');
    assignValue('source_url', draft.source_url || '');
    assignValue('image_file', draft.image_file || '');

    const remarks = document.querySelector('textarea[name="remarks"]');
    if (remarks && typeof draft.remarks === 'string') {
        remarks.value = draft.remarks;
    }

    const draftTeam = typeof draft.selected_team === 'string' ? draft.selected_team : '';
    if (draftTeam && teamData[draftTeam]) {
        applyTeam(draftTeam);
    }

    if (Array.isArray(draft.names)) {
        const grid = document.getElementById('names-grid');
        if (grid) {
            grid.innerHTML = '';
            currentSlotCount = 0;
            const names = draft.names.length > 0 ? draft.names : [''];
            names.forEach(name => addInputSlot(String(name || '')));
            while (currentSlotCount < 8) {
                addInputSlot('');
            }
        }
    }

    updateBodyCounter();
}

function clearFormDraft(dateValue = getDraftDateValue()) {
    try {
        localStorage.removeItem(getDraftStorageKey(dateValue));
    } catch (error) {
        console.warn('TBM draft clear failed:', error);
    }
}

function resetDraftAndReload() {
    if (!confirm('폼을 초기화하시겠습니까?')) {
        return;
    }
    clearFormDraft();
    location.href = 'index.php?date=' + encodeURIComponent(getDraftDateValue());
}

function bindDraftAutosave() {
    const form = document.getElementById('tbm-form');
    if (!form) {
        return;
    }

    form.addEventListener('input', saveFormDraft);
    form.addEventListener('change', saveFormDraft);
    form.addEventListener('submit', saveFormDraft);
}

function renderRecentDocs(teamName) {
    selectedRecentTeam = teamName;
    const container = document.getElementById('recent-list-container');
    const docs = [
        ...(recentDocsByTeam[teamName] ?? []),
        ...fallbackRecentDocs,
    ];

    if (!container) {
        return;
    }

    if (docs.length === 0) {
        container.innerHTML = '<p style="font-size:.88rem;color:#6b7280;">선택한 팀의 최근 생성된 일지가 없습니다.</p>';
        return;
    }

    const items = docs.slice(0, 10).map(doc => {
        const statusLabel = doc.generation_status === 'success' ? '완료' : (doc.generation_status === 'pending' ? '대기' : '실패');
        const titleText = doc.edu_title ? doc.edu_title : '(제목 없음)';
        const fileLink = doc.output_filename && doc.generation_status === 'success'
            ? '<br><a href="view_output.php?file=' + encodeURIComponent(doc.output_filename) + '" target="_blank" style="font-size:.78rem;">📄 열기</a>'
            : '';

        return '<li style="padding:10px 0; border-bottom:1px solid #f3f4f6;">'
            + '<div><a href="index.php?date=' + encodeURIComponent(doc.doc_date) + '&team=' + encodeURIComponent(teamName) + '" class="recent-date">' + escapeHtml(doc.doc_date) + '</a>'
            + ' <span class="badge badge-' + escapeHtml(doc.generation_status) + '" style="margin-left:4px;">' + escapeHtml(statusLabel) + '</span></div>'
            + '<div style="margin-top:4px; font-size:.85rem; color:#475569;">' + escapeHtml(titleText) + '</div>'
            + fileLink
            + '</li>';
    }).join('');

    container.innerHTML = '<ul class="recent-list" style="margin:0;">' + items + '</ul>';
}

renderRecentDocs = function(teamName) {
    selectedRecentTeam = teamName || '';
    const container = document.getElementById('recent-list-container');

    if (!container) {
        return;
    }

    const teamNames = Object.keys(recentDocsByTeam).filter(name => Array.isArray(recentDocsByTeam[name]) && recentDocsByTeam[name].length > 0);
    const orderedTeams = [];

    if (selectedRecentTeam && teamNames.includes(selectedRecentTeam)) {
        orderedTeams.push(selectedRecentTeam);
    }

    teamNames.forEach(name => {
        if (!orderedTeams.includes(name)) {
            orderedTeams.push(name);
        }
    });

    const renderDocItems = (docs, teamNameForLink) => docs.map(doc => {
        const statusLabel = doc.generation_status === 'success' ? '완료' : (doc.generation_status === 'pending' ? '대기' : '실패');
        const titleText = doc.edu_title ? doc.edu_title : '(제목 없음)';
        const hasTeam = teamNameForLink !== '';
        const dateLink = 'index.php?date=' + encodeURIComponent(doc.doc_date)
            + (hasTeam ? '&team=' + encodeURIComponent(teamNameForLink) : '');
        const fileLink = doc.output_filename && doc.generation_status === 'success'
            ? '<br><a href="view_output.php?file=' + encodeURIComponent(doc.output_filename) + '" target="_blank" style="font-size:.78rem;">열기</a>'
            : '';
        const recentAt = doc.recent_at
            ? '<div class="recent-meta">생성시각: ' + escapeHtml(doc.recent_at) + '</div>'
            : '';

        return '<li style="padding:10px 0; border-bottom:1px solid #f3f4f6;">'
            + '<div><a href="' + dateLink + '" class="recent-date">' + escapeHtml(doc.doc_date) + '</a>'
            + ' <span class="badge badge-' + escapeHtml(doc.generation_status) + '" style="margin-left:4px;">' + escapeHtml(statusLabel) + '</span></div>'
            + '<div style="margin-top:4px; font-size:.85rem; color:#475569;">' + escapeHtml(titleText) + '</div>'
            + recentAt
            + fileLink
            + '</li>';
    }).join('');

    const sections = orderedTeams.map(name => {
        const docs = recentDocsByTeam[name] ?? [];
        if (docs.length === 0) {
            return '';
        }

        const groupClass = name === selectedRecentTeam ? 'recent-group is-selected' : 'recent-group';
        return '<div class="' + groupClass + '">'
            + '<div class="recent-group-title">' + escapeHtml(name) + '</div>'
            + '<ul class="recent-list">' + renderDocItems(docs, name) + '</ul>'
            + '</div>';
    }).filter(Boolean);

    if (Array.isArray(fallbackRecentDocs) && fallbackRecentDocs.length > 0) {
        sections.push(
            '<div class="recent-group">'
            + '<div class="recent-group-title">공통 / 팀 미지정</div>'
            + '<ul class="recent-list">' + renderDocItems(fallbackRecentDocs, '') + '</ul>'
            + '</div>'
        );
    }

    if (sections.length === 0) {
        container.innerHTML = '<p style="font-size:.88rem;color:#6b7280;">최근 생성된 일지가 없습니다.</p>';
        return;
    }

    container.innerHTML = '<div class="recent-groups">' + sections.join('') + '</div>';
};

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// 팀 선택 시 좌측 표 다시 그리기
function applyTeam(teamName) {
    const grid = document.getElementById('names-grid');
    grid.innerHTML = '';
    currentSlotCount = 0;
    
    // 선택 팀 저장
    const selectedTeamInput = document.getElementById('selected_team');
    if (selectedTeamInput) {
        selectedTeamInput.value = teamName;
    }

    // 버튼 색상 하이라이트
    document.querySelectorAll('.team-btn').forEach(btn => {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
        if(btn.innerText.trim() === teamName) {
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-primary');
        }
    });

    // 선택된 팀의 인원들 배치
    if (teamData[teamName]) {
        teamData[teamName].forEach(member => {
            addInputSlot(member.name);
        });
    }
    
    // 양식에 맞게 기본 8칸은 보장되도록 빈칸 채우기
    while (currentSlotCount < 8) {
        addInputSlot('');
    }

    renderRecentDocs(teamName);
    saveFormDraft();
}

// 📌 칸 생성 로직 (개별 X 버튼 포함)
function addInputSlot(val) {
    currentSlotCount++;
    const grid = document.getElementById('names-grid');
    
    // 개별 칸 래퍼 (X버튼 위치를 잡기 위해 div로 감쌈)
    const wrapper = document.createElement('div');
    wrapper.className = 'name-wrapper';

    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'name' + currentSlotCount;
    input.value = val;
    input.placeholder = currentSlotCount + '번';

    // 개별 삭제 (X) 버튼
    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'name-del-btn';
    delBtn.innerHTML = '&times;';
    delBtn.title = '삭제';
    delBtn.onclick = function() {
        wrapper.remove(); // 칸 삭제
        reindexSlots();   // 번호 재정렬
    };

    wrapper.appendChild(input);
    wrapper.appendChild(delBtn);
    grid.appendChild(wrapper);
    saveFormDraft();
}

// 📌 중간에 지워졌을 때 1번부터 번호를 다시 깔끔하게 매겨주는 함수
function reindexSlots() {
    const grid = document.getElementById('names-grid');
    const wrappers = grid.children;
    currentSlotCount = 0;
    for(let i=0; i<wrappers.length; i++) {
        currentSlotCount++;
        const input = wrappers[i].querySelector('input');
        input.name = 'name' + currentSlotCount;
        input.placeholder = currentSlotCount + '번';
    }
    saveFormDraft();
}

// 📌 맨 끝 칸 하나 삭제
function removeLastSlot() {
    const grid = document.getElementById('names-grid');
    if (grid.lastChild) {
        grid.removeChild(grid.lastChild);
        reindexSlots();
    }
}

// 📌 전체 비우기 (초기화)
function clearAllSlots() {
    const grid = document.getElementById('names-grid');
    grid.innerHTML = '';
    currentSlotCount = 0;
    
    // 기본 양식 유지를 위해 최소 8칸의 빈칸을 깔아줌
    while (currentSlotCount < 8) {
        addInputSlot('');
    }
    
    // 활성화된 팀 버튼 색상 초기화
    document.querySelectorAll('.team-btn').forEach(btn => {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
    });
}

// 빈 숫자 칸 대량 추가
function addBlankSlots() {
    const count = parseInt(document.getElementById('add_slots').value, 10);
    if (isNaN(count) || count <= 0) return;
    for(let i=0; i<count; i++) {
        addInputSlot('');
    }
    document.getElementById('add_slots').value = '';
}

// 이름 직접 추가
function addNameSlot() {
    const nameInput = document.getElementById('add_name');
    const name = nameInput.value.trim();
    if (!name) return;
    addInputSlot(name);
    nameInput.value = '';
}

// 페이지 로드 시 첫 번째 팀 자동 선택 + 카운터 초기화
window.addEventListener('DOMContentLoaded', () => {
    const teams = Object.keys(teamData);
    const chooseTeam = initialTeam && teamData[initialTeam] ? initialTeam : (teams[0] ?? '');
    if (chooseTeam) {
        applyTeam(chooseTeam);
    } else {
        while(currentSlotCount < 8) addInputSlot('');
        renderRecentDocs('');
    }
    bindDraftAutosave();
    const draft = loadFormDraft();
    if (draft) {
        applyDraftToForm(draft);
    }
    draftAutosaveEnabled = true;
    saveFormDraft();
    updateBodyCounter();
});


// ── 중대재해 본문 줄수 카운터 ──────────────────────────────────
const BODY_MAX_CHARS = 33;  // PHP의 $maxChars와 동일
const BODY_MAX_LINES = 20;  // PHP의 $maxLines와 동일

function countBodyLines(text) {
    text = text.replace(/\r\n/g, '\n').trim();
    if (!text) return 0;

    const paragraphs = text.split('\n');
    const lines = [];
    let prevWasContent = false;

    for (let para of paragraphs) {
        para = para.trim();
        // [예방대책] 앞에 빈 줄 삽입 (PHP 로직과 동일)
        if (para === '[예방대책]' && prevWasContent) {
            lines.push('');
        }
        if (para === '') continue;

        // 33자 단위로 줄 바꿈
        let cur = '';
        for (const ch of [...para]) {
            if ([...cur + ch].length > BODY_MAX_CHARS) {
                lines.push(cur);
                cur = ch;
            } else {
                cur += ch;
            }
        }
        if (cur !== '') lines.push(cur);
        prevWasContent = true;
    }
    return lines.length;
}

function updateBodyCounter() {
    const ta = document.getElementById('left_content');
    const el = document.getElementById('body-counter');
    const lines = countBodyLines(ta.value);
    const chars = [...ta.value.trim()].length;

    let color, bgColor, msg;
    if (lines > BODY_MAX_LINES) {
        color   = '#991b1b';
        bgColor = '#fee2e2';
        msg = `⚠️ 초과! ${lines}줄 / 최대 ${BODY_MAX_LINES}줄 (${chars}자) — 넘친 내용은 잘립니다`;
    } else if (lines >= BODY_MAX_LINES - 2) {
        color   = '#854d0e';
        bgColor = '#fef9c3';
        msg = `⚡ 거의 꽉 참: ${lines}줄 / 최대 ${BODY_MAX_LINES}줄 (${chars}자)`;
    } else {
        color   = '#166534';
        bgColor = '#dcfce7';
        msg = `✅ ${lines}줄 / 최대 ${BODY_MAX_LINES}줄 (${chars}자)`;
    }

    el.style.color      = color;
    el.style.background = bgColor;
    el.textContent      = msg;
}

// AI 자동 생성
async function aiGenerate(forceNew = false) {
    const date = document.getElementById('doc_date').value;
    if (!date) { alert('날짜를 먼저 선택해 주세요.'); return; }
    const msg = forceNew ? '새 기사 기준으로 다시 생성하시겠습니까?\n(캐시 무시)' : 'AI 자동생성 하시겠습니까?\n(약 10~20초 소요)';
    if (!confirm(msg)) return;

    const overlay = document.getElementById('ai-overlay');
    overlay.classList.add('active');

    try {
        const response = await fetch('ajax_ai_generate.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'ajax=1&date=' + encodeURIComponent(date) + '&force_new=' + (forceNew ? '1' : '0')
        });
        const contentType = response.headers.get('Content-Type') || '';
        if (!contentType.includes('application/json')) {
            const text = await response.text();
            throw new Error('서버 응답이 JSON이 아닙니다. ' + text.substring(0, 200));
        }
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || '서버 인증이 필요합니다.');
        if (data.error) throw new Error(data.error);

        document.getElementById('edu_title').value = data.edu_title || '';
        document.getElementById('left_content').value = data.body_text || '';
        updateBodyCounter();
        document.getElementById('quiz1').value = data.quiz_1 || '';
        document.getElementById('quiz2').value = data.quiz_2 || '';
        document.getElementById('quiz3').value = data.quiz_3 || '';
        document.getElementById('source_url').value = data.source_url || '';
        document.getElementById('image_file').value = data.image_file || '';
        saveFormDraft();

        overlay.classList.remove('active');
        alert('AI 생성 완료');
    } catch (err) {
        overlay.classList.remove('active');
        alert('AI 오류: ' + err.message);
    }
}
</script>
</body>
</html>
