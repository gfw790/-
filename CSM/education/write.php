<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

$user = csm_require_auth();
$id = (int)($_GET['id'] ?? 0);

$training = null;
$existingAttendees = [];
$existingPhotos = [];

if ($id > 0) {
    $db = get_csm_db();
    $stmt = $db->prepare("SELECT * FROM csm_trainings WHERE id = ?");
    $stmt->execute([$id]);
    $training = $stmt->fetch();
    if ($training) {
        $attStmt = $db->prepare("SELECT * FROM csm_training_attendees WHERE training_id = ?");
        $attStmt->execute([$id]);
        $existingAttendees = $attStmt->fetchAll();

        $pStmt = $db->prepare("SELECT * FROM csm_training_photos WHERE training_id = ? ORDER BY display_order ASC, id ASC");
        $pStmt->execute([$id]);
        $existingPhotos = $pStmt->fetchAll();
    }
}

$fromPlanId = (int)($_GET['from_plan_id'] ?? 0);
if ($id === 0 && $fromPlanId > 0) {
    $fromPlan = csm_get_training_plan_by_id(get_csm_db(), $fromPlanId);
    if ($fromPlan) {
        $endH = 13 + (int)ceil($fromPlan['planned_hours']);
        $training = [
            'id' => 0,
            'training_no' => '',
            'training_type' => $fromPlan['training_type'],
            'sub_type' => $fromPlan['sub_type'] ?? '',
            'subject' => $fromPlan['subject'],
            'training_date' => $fromPlan['planned_date'] ?: date('Y-m-d'),
            'start_time' => '13:00',
            'end_time' => sprintf("%02d:00", min($endH, 20)),
            'planned_hours' => $fromPlan['planned_hours'],
            'location' => $fromPlan['location'] ?: '현장 사무실',
            'method' => $fromPlan['method'] ?: '시청각 및 실습',
            'instructor_name' => $fromPlan['instructor_name'] ?: '김남균',
            'instructor_position' => $fromPlan['instructor_position'] ?: '안전관리자',
            'content_summary' => $fromPlan['content_outline'],
            'content_summary_left' => $fromPlan['content_outline_left'] ?? '',
            'content_summary_right' => $fromPlan['content_outline_right'] ?? '',
            'evaluation_opinion' => '전원 교육내용 숙지 및 현장 실습 완료',
            'planned_content_source' => $fromPlan['plan_source'] ?: '(주)현대기전 계획서 연동',
            'designated_by' => '김남균',
            'is_custom_schedule' => 0,
            'is_demo' => 0,
            'is_estimated' => 0,
            'status' => 'DRAFT',
            'revision_num' => 1,
            'session_round' => 1,
            'course_id' => '',
            'msds_material_id' => '',
            'msds_material_name' => '',
            'msds_revision_date' => '',
            'msds_revision_count' => '',
            'scanned_doc_filename' => '',
            'cert_doc_filename' => '',
        ];
    }
}

$reqCsmItem = trim((string)($_GET['csm_item'] ?? $_GET['csm_item_code'] ?? ''));
$reqType = trim((string)($_GET['type'] ?? $_GET['training_type'] ?? ''));
$reqSubType = trim((string)($_GET['sub_type'] ?? ''));

// When creating a new training log with specific item/type parameter (e.g. from education_activity.php?item=5.2.2)
if ($id === 0 && empty($training)) {
    if ($reqCsmItem === '5.2.2' || $reqSubType === 'EXPERIENCE_TRAINING') {
        $curr = csm_get_plan_curriculum('OTHER', null, 'EXPERIENCE_TRAINING');
        $training = [
            'id' => 0,
            'training_no' => '',
            'training_type' => 'OTHER',
            'sub_type' => 'EXPERIENCE_TRAINING',
            'csm_item_code' => '5.2.2',
            'subject' => $curr['subject'] ?? (date('Y') . '년 한라시멘트 사업장 안전체험교육장 이수 실적 (16명 수료)'),
            'training_date' => date('Y') . '-05-20',
            'start_time' => '13:00',
            'end_time' => '17:00',
            'planned_hours' => (float)($curr['hours'] ?? 4.0),
            'location' => $curr['location'] ?? '한라시멘트 옥계공장 안전체험교육장',
            'method' => $curr['method'] ?? '체험식 실습 및 모의훈련',
            'instructor_name' => $curr['instructor_name'] ?? '한라 안전관리팀 교관',
            'instructor_position' => $curr['instructor_position'] ?? '전문강사',
            'content_summary' => $curr['combined_content'] ?? '',
            'content_summary_left' => $curr['left_content'] ?? '',
            'content_summary_right' => $curr['right_content'] ?? '',
            'evaluation_opinion' => '한라시멘트 사업장 투입 근로자 16명 전원 안전체험교육장 이수 완료 (참여실적 100%)',
            'planned_content_source' => $curr['source'] ?? '한라시멘트 옥계공장 안전체험교육장 체험식 안전보건교육 계획',
            'designated_by' => '김남균',
            'is_custom_schedule' => 0,
            'is_demo' => 0,
            'is_estimated' => 0,
            'status' => 'DRAFT',
            'revision_num' => 1,
            'session_round' => 1,
            'course_id' => '',
            'msds_material_id' => '',
            'msds_material_name' => '',
            'msds_revision_date' => '',
            'msds_revision_count' => '',
            'scanned_doc_filename' => '',
            'cert_doc_filename' => '',
        ];
    } elseif ($reqCsmItem === '5.1.3' || $reqType === 'NEW_HIRE') {
        $curr = csm_get_plan_curriculum('NEW_HIRE');
        $training = [
            'id' => 0,
            'training_no' => '',
            'training_type' => 'NEW_HIRE',
            'sub_type' => '',
            'csm_item_code' => '5.1.3',
            'subject' => $curr['subject'] ?? (date('Y') . '년 신규 채용 근로자 안전보건교육 (8시간)'),
            'training_date' => date('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'planned_hours' => 8.0,
            'location' => '현대기전 교육실 및 현장',
            'method' => '강의 및 시청각, 현장실습',
            'instructor_name' => '김남균',
            'instructor_position' => '안전관리자/과장',
            'content_summary' => $curr['combined_content'] ?? '',
            'content_summary_left' => $curr['left_content'] ?? '',
            'content_summary_right' => $curr['right_content'] ?? '',
            'evaluation_opinion' => '신규입사자 기본 안전수칙 및 법정 교육 8시간 이수 완료',
            'planned_content_source' => $curr['source'] ?? '(주)현대기전 신규채용시 안전보건교육 기준',
            'designated_by' => '김남균',
            'is_custom_schedule' => 0,
            'is_demo' => 0,
            'is_estimated' => 0,
            'status' => 'DRAFT',
            'revision_num' => 1,
            'session_round' => 1,
            'course_id' => '',
            'msds_material_id' => '',
            'msds_material_name' => '',
            'msds_revision_date' => '',
            'msds_revision_count' => '',
            'scanned_doc_filename' => '',
            'cert_doc_filename' => '',
        ];
    } elseif ($reqCsmItem === '5.1.4' || $reqType === 'SPECIAL') {
        $reqRound = (int)($_GET['session_round'] ?? $_GET['round'] ?? 1);
        if ($reqRound !== 2) $reqRound = 1;
        $tDate = trim((string)($_GET['training_date'] ?? $_GET['date'] ?? '')) ?: date('Y-m-d');
        $m = (int)date('n', strtotime($tDate));
        $curr = csm_get_plan_curriculum('SPECIAL', $m, null, $reqRound);
        $training = [
            'id' => 0,
            'training_no' => '',
            'training_type' => 'SPECIAL',
            'sub_type' => '',
            'csm_item_code' => '5.1.4',
            'subject' => $curr['subject'] ?? (date('Y') . "년 {$m}월 특별안전보건교육 {$reqRound}회차"),
            'training_date' => $tDate,
            'start_time' => '13:00',
            'end_time' => '17:00',
            'planned_hours' => 4.0,
            'location' => '현대기전 교육실 및 현장',
            'method' => '강의 및 시청각, 현장실습',
            'instructor_name' => '진종철',
            'instructor_position' => '관리감독자',
            'content_summary' => $curr['combined_content'] ?? '',
            'content_summary_left' => $curr['left_content'] ?? '',
            'content_summary_right' => $curr['right_content'] ?? '',
            'evaluation_opinion' => '특별작업 투입 근로자 법정 특별안전보건교육 이수 완료',
            'planned_content_source' => $curr['source'] ?? '1.2.1 안전보건활동계획서 특별안전보건교육 기준',
            'designated_by' => '김남균',
            'is_custom_schedule' => 0,
            'is_demo' => 0,
            'is_estimated' => 0,
            'status' => 'DRAFT',
            'revision_num' => 1,
            'session_round' => $reqRound,
            'course_id' => '',
            'msds_material_id' => '',
            'msds_material_name' => '',
            'msds_revision_date' => '',
            'msds_revision_count' => '',
            'scanned_doc_filename' => '',
            'cert_doc_filename' => '',
        ];
    }
}

$msdsList = csm_get_msds_list();
$trainingDate = $training['training_date'] ?? trim((string)($_GET['training_date'] ?? $_GET['date'] ?? '')) ?: date('Y-m-d');
$curMonth = (int)date('n', strtotime($trainingDate));
$defaultCurriculum = csm_get_plan_curriculum('REGULAR', $curMonth);

if ($training) {
    $contentLeft = (string)($training['content_summary_left'] ?? '');
    $contentRight = (string)($training['content_summary_right'] ?? '');
    if ($contentLeft === '' && $contentRight === '' && !empty($training['content_summary'])) {
        $rawLines = array_values(array_filter(array_map('trim', explode("\n", $training['content_summary']))));
        $half = (int)ceil(count($rawLines) / 2);
        $contentLeft = implode("\n", array_slice($rawLines, 0, max(4, $half)));
        $contentRight = implode("\n", array_slice($rawLines, max(4, $half)));
    }
} else {
    $contentLeft = $defaultCurriculum['left_content'] ?? '';
    $contentRight = $defaultCurriculum['right_content'] ?? '';
}

$allEmployees = csm_get_employees($trainingDate, false);
$otherTypes = csm_get_other_training_types(get_csm_db());

// Group employees by active teams (strictly exclude inactive teams like 공사팀-전기2)
$inactiveTeams = csm_get_inactive_teams();
$teams = [];
foreach ($allEmployees as $emp) {
    $tname = trim((string)($emp['team'] ?? ''));
    if ($tname === '') $tname = '경영/기타';
    
    // Exclude inactive teams according to company auth_team_statuses.json
    if (!csm_is_team_active($tname) || in_array($tname, $inactiveTeams, true)) {
        continue;
    }
    
    $teams[$tname][] = $emp;
}
ksort($teams);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $training ? '교육일지 수정' : '신규 교육일지 작성' ?> - CSM 안전보건교육</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Pretendard:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Pretendard', system-ui, sans-serif; }
  </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen pb-20">

  <!-- Header -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <a href="index.php" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500 transition" title="목록으로">
          <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <a href="../" title="CSM 통합 포털로 이동" class="text-xs text-slate-500 hover:text-blue-600 flex items-center space-x-1 font-semibold px-2 py-1 bg-slate-100 rounded-lg border border-slate-200">
          <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i>
          <span>CSM 포털</span>
        </a>
        <h1 class="text-base font-bold text-slate-900">
          <?= $training ? '교육일지 수정: ' . htmlspecialchars($training['training_no']) : '신규 안전보건 교육일지 작성' ?>
        </h1>
        <?php if ($training && $training['status'] === 'FINALIZED'): ?>
          <span class="bg-emerald-600 text-white text-[10px] font-bold px-2 py-0.5 rounded">확정완료 (Revision <?= $training['revision_num'] ?>)</span>
        <?php endif; ?>
        <a href="plan.php" class="text-xs text-indigo-600 hover:text-indigo-800 flex items-center space-x-1 font-medium ml-2 px-2 py-1 bg-indigo-50 rounded-lg border border-indigo-200">
          <i data-lucide="calendar-range" class="w-3.5 h-3.5"></i>
          <span>연간 교육계획</span>
        </a>
      </div>
      <div class="flex items-center space-x-2">
        <button type="button" onclick="document.getElementById('write-form').requestSubmit()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 flex items-center space-x-1.5 transition">
          <i data-lucide="save" class="w-4 h-4"></i>
          <span>저장하기</span>
        </button>
      </div>
    </div>
  </header>

  <main class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <!-- Collision Warning Banner (Dynamic) -->
    <div id="collision-banner" class="hidden bg-rose-50 border-2 border-rose-400 rounded-2xl p-4 text-xs text-rose-950 flex items-start space-x-3 shadow-md animate-pulse">
      <i data-lucide="alert-octagon" class="w-6 h-6 text-rose-600 shrink-0 mt-0.5"></i>
      <div>
        <strong class="font-bold text-sm text-rose-800">⚠️ [참석 근로자 중복 편성 경고]</strong>
        <p id="collision-msg" class="mt-1 leading-relaxed text-xs"></p>
        <p class="mt-1 text-[11px] text-rose-700 font-semibold">※ 동일 인원이 같은 시간에 2개 이상의 교육을 중복 이수할 수 없으므로, 해당 인원을 명단에서 제외하거나 일자를 변경해 주십시오.</p>
      </div>
    </div>

    <!-- Separate Audience Safe Notice (Dynamic) -->
    <div id="separate-audience-banner" class="hidden bg-blue-50 border border-blue-300 rounded-2xl p-3.5 text-xs text-blue-950 flex items-start space-x-3 shadow-xs">
      <i data-lucide="info" class="w-5 h-5 text-blue-600 shrink-0 mt-0.5"></i>
      <div>
        <strong class="font-bold text-xs text-blue-900">ℹ️ [동일 일자 대상자 분리 교육 안내]</strong>
        <p id="separate-audience-msg" class="mt-0.5 leading-relaxed text-xs text-blue-800"></p>
      </div>
    </div>

    <?php if ($training && $training['status'] === 'FINALIZED'): ?>
      <div class="bg-amber-50 border border-amber-300 rounded-2xl p-4 text-xs text-amber-900 flex items-start space-x-3">
        <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
        <div>
          <strong class="font-bold">확정된 교육일지 수정 경고</strong>
          <p class="mt-0.5 leading-relaxed">
            본 교육일지는 이미 확정(FINALIZED) 상태입니다. 내용을 수정하여 저장하면 <strong>Revision 번호가 증가(R<?= $training['revision_num'] + 1 ?>)</strong>하며, 
            수정 전 전체 원본과 사진 스냅샷이 이력 테이블에 영구 보존됩니다. <strong>[변경 사유]</strong> 입력이 필수입니다.
          </p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($fromPlanId > 0 && !empty($fromPlan)): ?>
      <!-- Linked From Plan Banner -->
      <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 text-xs text-indigo-900 flex items-start space-x-3">
        <i data-lucide="calendar-check" class="w-5 h-5 text-indigo-600 shrink-0 mt-0.5"></i>
        <div>
          <span class="font-bold">2026 안전보건활동계획서(1.2.1) 연동:</span>
          <span class="ml-1 text-slate-700">사전 수립된 연간 교육계획(<strong><?= htmlspecialchars($fromPlan['subject']) ?></strong>, 예정일: <?= htmlspecialchars($fromPlan['planned_date']) ?>)의 교육내용, 대상, 시간이 자동 적용되었습니다. 참석자를 확인하신 후 교육일지를 저장하십시오.</span>
        </div>
      </div>
    <?php endif; ?>

    <!-- Signature Principle Banner -->
    <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-900 flex items-start space-x-3">
      <i data-lucide="pen-tool" class="w-5 h-5 text-blue-600 shrink-0 mt-0.5"></i>
      <div>
        <span class="font-bold">회사 운영규칙 - 서명 원칙:</span>
        <span class="ml-1 text-slate-700">모든 안전교육 서명은 <strong>출력 후 수기 작성</strong>이 원칙입니다. 엑셀·PDF의 서명란은 공란으로 생성되며, 서명 완료된 문서는 하단의 <strong>[수기 서명 스캔본 첨부]</strong>를 통해 전자 보관할 수 있습니다.</span>
      </div>
    </div>

    <form id="write-form" onsubmit="submitForm(event)" class="space-y-6" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="from_plan_id" value="<?= $fromPlanId ?>">
      <input type="hidden" name="course_id" id="course_id" value="<?= $training ? $training['course_id'] : '' ?>">
      <input type="hidden" name="planned_content_source" id="planned_content_source" value="<?= $training ? htmlspecialchars((string)$training['planned_content_source']) : '' ?>">

      <!-- Section 1: Basic Info & Schedule Rule -->
      <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center space-x-2">
            <i data-lucide="calendar" class="w-4 h-4 text-blue-600"></i>
            <span>1. 교육 구분 및 일정 지정 (회사 운영규칙)</span>
          </h2>
          <div class="flex items-center space-x-2">
            <span class="text-[11px] text-slate-500">일정 지정자:</span>
            <input type="text" name="designated_by" value="<?= $training ? htmlspecialchars($training['designated_by'] ?? '김남균') : '김남균' ?>" class="px-2 py-0.5 border border-slate-200 rounded text-xs font-bold text-slate-800 bg-slate-50 w-20 text-center">
          </div>
        </div>

        <!-- Classification & CSM Category Options -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 구분 (8개 정규 구분) <span class="text-rose-500">*</span></label>
            <select name="training_type" id="training_type" onchange="onTypeChanged()" required class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-500 font-bold text-slate-900">
              <option value="REGULAR" <?= ($training && $training['training_type'] === 'REGULAR') ? 'selected' : '' ?>>2. 정기안전 보건교육 (매월 4번째 금요일 2h)</option>
              <option value="SPECIAL" <?= ($training && $training['training_type'] === 'SPECIAL') ? 'selected' : '' ?>>5. 특별안전교육 (총 8h, 3·4번째 금요일 각 4h)</option>
              <option value="NEW_HIRE" <?= ($training && $training['training_type'] === 'NEW_HIRE') ? 'selected' : '' ?>>1. 신규채용시 교육 (8h)</option>
              <option value="JOB_CHANGE" <?= ($training && $training['training_type'] === 'JOB_CHANGE') ? 'selected' : '' ?>>3. 작업내용변경시 교육 (2h)</option>
              <option value="SUPERVISOR" <?= ($training && $training['training_type'] === 'SUPERVISOR') ? 'selected' : '' ?>>4. 관리감독자교육 (16h)</option>
              <option value="EXTERNAL" <?= ($training && $training['training_type'] === 'EXTERNAL') ? 'selected' : '' ?>>6. 외부강사초빙 교육</option>
              <option value="OTHER" <?= ($training && $training['training_type'] === 'OTHER') ? 'selected' : '' ?>>7. 기타</option>
              <option value="MSDS" <?= ($training && $training['training_type'] === 'MSDS') ? 'selected' : '' ?>>8. MSDS 교육 (2h, 화학물질 연계)</option>
            </select>
          </div>

          <!-- CSM Chapter 5 Mapping Field -->
          <div>
            <label class="block font-semibold text-indigo-900 mb-1">CSM 제5장 평가항목 연계 (10점 만점)</label>
            <select name="csm_item_code" id="csm_item_code" onchange="onCsmItemCodeChanged()" class="w-full px-3 py-2 border border-indigo-200 rounded-xl bg-indigo-50/50 focus:bg-white text-xs font-bold text-indigo-900">
              <option value="">-- 자동 연계 (교육구분에 따름) --</option>
              <option value="5.1.1" <?= ($training && ($training['csm_item_code'] ?? '') === '5.1.1') ? 'selected' : '' ?>>[5.1.1] 직무교육 (2.0점 - 책임자/담당자)</option>
              <option value="5.1.2" <?= ($training && ($training['csm_item_code'] ?? '') === '5.1.2') ? 'selected' : '' ?>>[5.1.2] 정기안전보건교육 (2.0점 - 근로자/관리감독자)</option>
              <option value="5.1.3" <?= ($training && ($training['csm_item_code'] ?? '') === '5.1.3') ? 'selected' : '' ?>>[5.1.3] 신규채용시 교육 (2.0점 - 입사자 8h)</option>
              <option value="5.1.4" <?= ($training && ($training['csm_item_code'] ?? '') === '5.1.4') ? 'selected' : '' ?>>[5.1.4] 특별안전보건교육 (1.0점 - Matrix 5개 공종)</option>
              <option value="5.1.5" <?= ($training && ($training['csm_item_code'] ?? '') === '5.1.5') ? 'selected' : '' ?>>[5.1.5] MSDS 화학물질교육 (1.0점 - 12개 물질)</option>
              <option value="5.2.2" <?= ($training && ($training['csm_item_code'] ?? '') === '5.2.2') ? 'selected' : '' ?>>[5.2.2] 안전체험교육장 (2.0점 - 16명 전원)</option>
              <option value="기타" <?= ($training && ($training['csm_item_code'] ?? '') === '기타') ? 'selected' : '' ?>>기타 법정의무교육</option>
            </select>
          </div>

          <!-- Other sub-types -->
          <div id="other-type-box" class="<?= ($training && $training['training_type'] === 'OTHER') ? '' : 'hidden' ?>">
            <div class="flex items-center justify-between mb-1.5">
              <label class="block font-semibold text-slate-700">기타 교육 세부과목 <span class="text-rose-500">*</span></label>
              <button type="button" onclick="openAddOtherTypeModal()" class="text-[11px] px-2.5 py-1 bg-amber-100 hover:bg-amber-200 text-amber-900 rounded-lg font-bold flex items-center space-x-1 border border-amber-300 shadow-xs transition">
                <i data-lucide="plus-circle" class="w-3.5 h-3.5 text-amber-700"></i>
                <span>+ 새 교육과목 추가</span>
              </button>
            </div>
            <select name="sub_type" id="sub_type" onchange="onSubTypeChanged()" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-amber-50 focus:bg-white text-xs font-semibold">
              <option value="">-- 기타 교육과목 선택 --</option>
              <?php foreach ($otherTypes as $ot): ?>
                <option value="<?= htmlspecialchars($ot['code']) ?>" <?= ($training && ($training['sub_type'] ?? '') === $ot['code']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($ot['name']) ?> (<?= $ot['default_hours'] ?>시간)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Special Training Round Selector -->
          <div id="special-round-box" class="<?= ($training && $training['training_type'] === 'SPECIAL') ? '' : 'hidden' ?>">
            <label class="block font-semibold text-slate-700 mb-1">특별교육 회차 (총 8h / 2회 분할) <span class="text-rose-500">*</span></label>
            <div class="grid grid-cols-2 gap-2">
              <label class="flex items-center space-x-1.5 p-2 bg-slate-50 border border-slate-200 rounded-lg cursor-pointer text-xs">
                <input type="radio" name="session_round" value="1" onchange="applySpecialRound(1)" <?= (!$training || $training['session_round'] == 1) ? 'checked' : '' ?>>
                <span class="font-bold">1회차 (3번째 금, 4h)</span>
              </label>
              <label class="flex items-center space-x-1.5 p-2 bg-slate-50 border border-slate-200 rounded-lg cursor-pointer text-xs">
                <input type="radio" name="session_round" value="2" onchange="applySpecialRound(2)" <?= ($training && $training['session_round'] == 2) ? 'checked' : '' ?>>
                <span class="font-bold text-amber-700">2회차 (4번째 금, 4h)</span>
              </label>
            </div>
          </div>

          <!-- Schedule Type -->
          <div>
            <label class="block font-semibold text-slate-700 mb-1">일정 지정 방식</label>
            <select name="is_custom_schedule" id="is_custom_schedule" onchange="onScheduleModeChanged()" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white text-xs">
              <option value="0" <?= (!$training || empty($training['is_custom_schedule'])) ? 'selected' : '' ?>>기본 운영규칙 일정 (Default)</option>
              <option value="1" <?= ($training && !empty($training['is_custom_schedule'])) ? 'selected' : '' ?>>김남균 사용자 지정 일정 (Custom)</option>
            </select>
          </div>
        </div>

        <!-- Date & Time & Hours Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs pt-1">
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="font-semibold text-slate-700">실시 일자 <span class="text-rose-500">*</span></label>
              <button type="button" onclick="calcDefaultDate()" class="text-[10px] text-blue-600 hover:underline font-semibold">기본규칙일자 계산</button>
            </div>
            <input type="date" name="training_date" id="training_date" value="<?= htmlspecialchars($trainingDate) ?>" required onchange="onTrainingDateChange(); triggerCollisionCheck();"
              class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-500 font-bold">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">시작 시각 <span class="text-rose-500">*</span></label>
            <input type="time" name="start_time" id="start_time" value="<?= $training ? $training['start_time'] : '13:00' ?>" onchange="calcHours(); triggerCollisionCheck();" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">종료 시각 <span class="text-rose-500">*</span></label>
            <input type="time" name="end_time" id="end_time" value="<?= $training ? $training['end_time'] : '15:00' ?>" onchange="calcHours(); triggerCollisionCheck();" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 시간 (HR) <span class="text-rose-500">*</span></label>
            <input type="number" step="0.5" name="planned_hours" id="planned_hours" value="<?= $training ? $training['planned_hours'] : '2.0' ?>" required 
              class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white font-black text-blue-600 text-sm">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs pt-1">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 방법</label>
            <input type="text" name="method" value="<?= $training ? htmlspecialchars($training['method']) : '시청각 및 실습' ?>" required class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">교육 장소</label>
            <input type="text" name="location" value="<?= $training ? htmlspecialchars($training['location']) : '현장 사무실' ?>" class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">강사 성명 / 직위</label>
            <div class="flex items-center space-x-1.5">
              <input type="text" name="instructor_name" value="<?= $training ? htmlspecialchars($training['instructor_name']) : '김남균' ?>" required class="w-full px-3 py-2 border border-slate-200 rounded-xl bg-slate-50 font-bold">
              <input type="text" name="instructor_position" value="<?= $training ? htmlspecialchars($training['instructor_position']) : '과장' ?>" class="w-24 px-2 py-2 border border-slate-200 rounded-xl bg-slate-50 text-center">
            </div>
          </div>
        </div>

        <!-- Item 8: MSDS Dynamic Linked Box -->
        <div id="msds-box" class="p-4 bg-purple-50 border border-purple-200 rounded-xl space-y-3 <?= ($training && $training['training_type'] === 'MSDS') ? '' : 'hidden' ?>">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-purple-900 flex items-center space-x-1.5">
              <i data-lucide="flask-conical" class="w-4 h-4 text-purple-600"></i>
              <span>8번 MSDS 화학물질 연계 (기존 msds_records.json 12종 실시간 연동)</span>
            </span>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div>
              <label class="block font-semibold text-purple-800 mb-1">대상 화학물질 선택</label>
              <select id="msds_selector" onchange="pickMsds(this.value)" class="w-full px-3 py-2 border border-purple-300 rounded-xl bg-white text-xs">
                <option value="">-- 화학물질 선택 --</option>
                <?php foreach ($msdsList as $m): ?>
                  <option value="<?= htmlspecialchars($m['id']) ?>" 
                    data-name="<?= htmlspecialchars($m['material_name']) ?>"
                    data-date="<?= htmlspecialchars($m['revised_date']) ?>"
                    data-count="<?= htmlspecialchars($m['revision_count']) ?>"
                    <?= ($training && ($training['msds_material_id'] ?? '') === $m['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['material_name']) ?> (<?= htmlspecialchars($m['manufacturer']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block font-semibold text-purple-800 mb-1">개정일자 (스냅샷)</label>
              <input type="text" name="msds_revision_date" id="msds_revision_date" value="<?= htmlspecialchars((string)($training['msds_revision_date'] ?? '')) ?>" readonly class="w-full px-3 py-2 bg-purple-100/50 border border-purple-200 rounded-xl font-mono text-purple-900">
            </div>
            <div>
              <label class="block font-semibold text-purple-800 mb-1">개정차수 (스냅샷)</label>
              <input type="text" name="msds_revision_count" id="msds_revision_count" value="<?= htmlspecialchars((string)($training['msds_revision_count'] ?? '')) ?>" readonly class="w-full px-3 py-2 bg-purple-100/50 border border-purple-200 rounded-xl font-mono text-purple-900">
            </div>
          </div>
          <input type="hidden" name="msds_material_id" id="msds_material_id" value="<?= htmlspecialchars((string)($training['msds_material_id'] ?? '')) ?>">
          <input type="hidden" name="msds_material_name" id="msds_material_name" value="<?= htmlspecialchars((string)($training['msds_material_name'] ?? '')) ?>">
        </div>

        <!-- Subject & Curriculum Suggestion Box -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-xs font-semibold text-slate-700">교육과목 (제목) <span class="text-rose-500">*</span></label>
            <button type="button" onclick="loadCurriculumSuggestion()" class="px-2.5 py-0.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded text-[11px] font-bold flex items-center space-x-1">
              <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
              <span>1.2.1 계획서 기본값 불러오기</span>
            </button>
          </div>
          <input type="text" name="subject" id="subject" value="<?= $training ? htmlspecialchars($training['subject']) : '안전보건교육' ?>" required 
            class="w-full px-3.5 py-2 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-500">
        </div>

        <!-- Plan Source Badge -->
        <div id="plan-source-badge" class="<?= ($training && !empty($training['planned_content_source'])) ? '' : 'hidden' ?> text-[11px] text-indigo-700 bg-indigo-50/70 border border-indigo-200 px-3 py-1.5 rounded-lg flex items-center justify-between">
          <span class="flex items-center space-x-1">
            <i data-lucide="book-open" class="w-3.5 h-3.5"></i>
            <span id="plan-source-text"><?= $training ? htmlspecialchars((string)$training['planned_content_source']) : '' ?></span>
          </span>
          <label class="flex items-center space-x-1 text-slate-600 font-medium">
            <input type="checkbox" name="is_estimated" value="1" <?= ($training && !empty($training['is_estimated'])) ? 'checked' : '' ?> class="rounded text-indigo-600">
            <span>불명확 연결 (추정 표시)</span>
          </label>
        </div>

        <!-- 2-Column Curriculum Input (Matching Excel / PDF Form Layout) -->
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <label class="block text-xs font-bold text-slate-800">
              교육 내용 분할 입력 (표 서식 좌·우 2단 규격에 맞춰 행별 입력) <span class="text-rose-500">*</span>
            </label>
            <span class="text-[11px] text-slate-500">※ 정기교육일지 좌측(A열) 및 우측(K열) 칸에 각각 분배되어 출력됩니다.</span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Left Column -->
            <div class="bg-indigo-50/40 p-3.5 rounded-xl border border-indigo-100 space-y-1.5">
              <div class="flex items-center justify-between">
                <label for="content_summary_left" class="text-xs font-bold text-indigo-900 flex items-center space-x-1">
                  <i data-lucide="align-left" class="w-3.5 h-3.5 text-indigo-600"></i>
                  <span>◈ 교육내용 좌측 칸 (법정공통/기본)</span>
                </label>
                <span class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded font-semibold">Row 16~24 (Col A)</span>
              </div>
              <textarea name="content_summary_left" id="content_summary_left" rows="7" required
                placeholder="1. 산업안전 및 사고 예방에 관한 사항&#10;1) 4대 중대 재해 유형&#10;   a.추락, b.끼임, c.충돌, d.질식&#10;2) 예방대책..."
                class="w-full px-3 py-2 border border-slate-200 rounded-lg text-xs leading-relaxed bg-white focus:ring-2 focus:ring-indigo-500 font-mono"><?= htmlspecialchars($contentLeft) ?></textarea>
              <p class="text-[10px] text-slate-400">출력 서식 좌측 '◈ 교육내용 ◈' 하단 행부터 최대 9행까지 순차 배치됩니다.</p>
            </div>

            <!-- Right Column -->
            <div class="bg-emerald-50/40 p-3.5 rounded-xl border border-emerald-100 space-y-1.5">
              <div class="flex items-center justify-between">
                <label for="content_summary_right" class="text-xs font-bold text-emerald-900 flex items-center space-x-1">
                  <i data-lucide="align-right" class="w-3.5 h-3.5 text-emerald-600"></i>
                  <span>◈ 교육내용 우측 칸 (당월 중점테마/실무)</span>
                </label>
                <span class="text-[10px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded font-semibold">Row 15~24 (Col K)</span>
              </div>
              <textarea name="content_summary_right" id="content_summary_right" rows="7" required
                placeholder="2. 고소작업안전 교육&#10;1) 교육목적 및 기대사항&#10;2) 고소작업의 정의&#10;3. 사다리 재해 사례..."
                class="w-full px-3 py-2 border border-slate-200 rounded-lg text-xs leading-relaxed bg-white focus:ring-2 focus:ring-emerald-500 font-mono"><?= htmlspecialchars($contentRight) ?></textarea>
              <p class="text-[10px] text-slate-400">출력 서식 우측 상단 첫 행(Row 15)부터 최대 10행까지 순차 배치됩니다.</p>
            </div>
          </div>
          
          <input type="hidden" name="content_summary" id="content_summary" value="<?= htmlspecialchars((string)($training['content_summary'] ?? '')) ?>">
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">교육평가 및 의견</label>
          <input type="text" name="evaluation_opinion" value="<?= htmlspecialchars((string)($training['evaluation_opinion'] ?? '전원 교육내용 숙지 및 현장 실습 완료')) ?>" 
            class="w-full px-3.5 py-2 border border-slate-200 rounded-xl text-xs bg-slate-50 focus:bg-white">
        </div>

        <div class="pt-2 flex items-center space-x-2">
          <input type="checkbox" name="is_demo" id="is_demo" value="1" <?= ($training && !empty($training['is_demo'])) ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
          <label for="is_demo" class="text-xs text-slate-600 font-medium">이 교육일지를 <strong>시연용 가명 데이터</strong>로 등록 (출력물 상단에 워터마크 자동 표기)</label>
        </div>

        <?php if ($training && $training['status'] === 'FINALIZED'): ?>
          <div class="pt-2">
            <label class="block text-xs font-bold text-amber-900 mb-1">수정 사유 (Revision <?= $training['revision_num'] + 1 ?> 감사 이력) <span class="text-rose-500">*</span></label>
            <input type="text" name="change_reason" required placeholder="예: 참석자 이수시간 오기 정정, 교육 사진 추가 등록 등" 
              class="w-full px-3.5 py-2 border border-amber-300 rounded-xl text-xs bg-amber-50 focus:bg-white focus:ring-2 focus:ring-amber-500 font-semibold">
          </div>
        <?php endif; ?>

      </div>

      <!-- Section 2: Attendees Selection (Snapshots) -->
      <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
          <div>
            <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center space-x-2">
              <i data-lucide="users" class="w-4 h-4 text-emerald-600"></i>
              <span>2. 참석자 명단 및 이수 확인 (당시 인적사항 스냅샷 보존)</span>
            </h2>
            <p class="text-[11px] text-slate-500 mt-0.5 leading-relaxed">
              • <strong>이름 앞 체크박스 [ ✓ ]</strong>: 이 교육에 참석한 근로자를 선택합니다. (체크 해제 시 교육 대상에서 완전히 제외됨)<br>
              • <strong>우측 [ ✓ ] 이수</strong>: 정상 수료하여 법정 교육시간을 인정합니다. (기본값 ON, 조퇴·미이수 시에만 체크 해제)
            </p>
          </div>
          <div class="flex items-center space-x-1.5 text-xs">
            <button type="button" onclick="selectExperienceTraining16()" id="btn-select-exp-16" 
              class="px-2.5 py-1 bg-cyan-50 hover:bg-cyan-100 text-cyan-800 border border-cyan-200 rounded-lg font-bold flex items-center space-x-1 transition shadow-xs" 
              title="한라시멘트 안전체험교육장 이수 대상 16명(가스 6, 모터 2, 전기 7, 안전 1)을 즉시 선택합니다">
              <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-600"></i>
              <span>안전체험 16명 선택</span>
            </button>
            <button type="button" onclick="toggleAllAttendees(true)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-semibold">전체선택</button>
            <button type="button" onclick="toggleAllAttendees(false)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-semibold">선택해제</button>
          </div>
        </div>

        <!-- Team Quick Toggles -->
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
          <span class="text-slate-400 font-medium mr-1">팀별 빠른 선택:</span>
          <?php foreach ($teams as $tname => $members): ?>
            <?php 
              $tKey = md5($tname);
              $actCount = count(array_filter($members, fn($m) => !empty($m['was_active_at_date'])));
            ?>
            <button type="button" id="quick-btn-<?= $tKey ?>" data-quick-team="<?= htmlspecialchars($tname) ?>"
              onclick="toggleTeam('<?= htmlspecialchars($tname) ?>', true)" 
              class="team-quick-btn px-2.5 py-1 bg-slate-100 hover:bg-blue-50 hover:text-blue-700 border border-slate-200 rounded-lg text-xs font-medium transition flex items-center space-x-1.5 <?= $actCount === 0 ? 'hidden' : '' ?>">
              <span><?= htmlspecialchars($tname) ?></span>
              <span class="text-[10px] bg-white text-blue-700 px-1.5 py-0.2 rounded border border-slate-200 font-bold team-quick-count"><?= $actCount ?></span>
            </button>
          <?php endforeach; ?>
        </div>

        <!-- Attendees Grid by Team -->
        <div class="space-y-4 pt-2">
          <?php 
          $existingMap = [];
          foreach ($existingAttendees as $ea) {
              $existingMap[$ea['employee_no']] = $ea;
          }
          ?>
          <?php foreach ($teams as $teamName => $members): ?>
            <?php 
              $tKey = md5($teamName);
              $actCount = count(array_filter($members, fn($m) => !empty($m['was_active_at_date'])));
            ?>
            <div id="team-card-<?= $tKey ?>" class="team-card border border-slate-200/80 rounded-xl overflow-hidden <?= $actCount === 0 ? 'hidden' : '' ?>" data-team="<?= htmlspecialchars($teamName) ?>" data-team-key="<?= $tKey ?>">
              <div class="bg-slate-100/80 px-3.5 py-2 font-bold text-xs text-slate-700 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200">
                <div class="flex items-center space-x-2">
                  <span class="text-slate-900 font-bold text-xs sm:text-sm"><?= htmlspecialchars($teamName) ?></span>
                  <span class="px-2 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded text-[11px] font-semibold">
                    재직 <strong class="team-active-count"><?= $actCount ?></strong>명
                  </span>
                </div>
                <div class="flex items-center space-x-1.5">
                  <button type="button" onclick="toggleTeam('<?= htmlspecialchars($teamName) ?>', true)" 
                    class="px-2.5 py-1 bg-white hover:bg-blue-50 text-blue-700 border border-slate-300 hover:border-blue-300 rounded text-xs font-semibold shadow-sm transition flex items-center space-x-1">
                    <i data-lucide="check-square" class="w-3.5 h-3.5"></i>
                    <span>팀 전체선택</span>
                  </button>
                  <button type="button" onclick="toggleTeam('<?= htmlspecialchars($teamName) ?>', false)" 
                    class="px-2.5 py-1 bg-white hover:bg-slate-100 text-slate-600 border border-slate-300 rounded text-xs font-medium shadow-sm transition flex items-center space-x-1">
                    <i data-lucide="square" class="w-3.5 h-3.5"></i>
                    <span>해제</span>
                  </button>
                </div>
              </div>
              <div class="p-3 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                <?php foreach ($members as $m): ?>
                  <?php 
                    $eno = (string)$m['employee_no'];
                    $joinDateStr = trim((string)($m['join_date'] ?? ''));
                    $retiredAtStr = trim((string)($m['retired_at'] ?? ''));
                    $isEmpRetired = ($m['employment_status'] === 'retired' || (int)$m['is_active'] === 0);
                    $isEligibleInitial = !empty($m['was_active_at_date']);

                    if ($id > 0) {
                        $isChecked = isset($existingMap[$eno]);
                    } elseif (($training['sub_type'] ?? '') === 'EXPERIENCE_TRAINING') {
                        $isChecked = in_array($eno, ['9247', '4879', '4456', '8466', '2313', '5349', '1117', '2559', '7204', '1280', '3092', '1234', '0639', '9460', '0185', '5878'], true);
                    } elseif (($training['training_type'] ?? '') === 'NEW_HIRE') {
                        $isChecked = false;
                    } else {
                        $isChecked = $isEligibleInitial;
                    }
                    $isCompleted = isset($existingMap[$eno]) ? ($existingMap[$eno]['is_completed'] == 1) : true;
                  ?>
                  <label class="emp-card flex items-center justify-between p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer text-xs transition bg-white <?= !$isEligibleInitial ? 'hidden' : '' ?>"
                    data-team="<?= htmlspecialchars($teamName) ?>"
                    data-is-retired="<?= $isEmpRetired ? '1' : '0' ?>"
                    data-join-date="<?= htmlspecialchars($joinDateStr) ?>"
                    data-retired-at="<?= htmlspecialchars($retiredAtStr) ?>">
                    <div class="flex items-center space-x-2">
                      <input type="checkbox" class="att-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500" 
                        data-team="<?= htmlspecialchars($teamName) ?>"
                        data-id="<?= $m['id'] ?>"
                        data-no="<?= htmlspecialchars($m['employee_no']) ?>"
                        data-name="<?= htmlspecialchars($m['name']) ?>"
                        data-team-name="<?= htmlspecialchars($m['team'] ?? '') ?>"
                        data-position="<?= htmlspecialchars($m['position'] ?? '') ?>"
                        <?= ($isChecked && $isEligibleInitial) ? 'checked' : '' ?>
                        onchange="updateCount()">
                      <div>
                        <span class="font-bold text-slate-900"><?= htmlspecialchars($m['name']) ?></span>
                        <span class="text-[10px] text-slate-500 ml-1"><?= htmlspecialchars($m['position'] ?: '사원') ?></span>
                      </div>
                    </div>
                    <div class="comp-wrap flex items-center space-x-1 transition-opacity" onclick="event.stopPropagation()">
                      <span class="text-[10px] text-slate-400 font-medium">이수</span>
                      <input type="checkbox" class="att-comp-checkbox rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" data-no="<?= htmlspecialchars($m['employee_no']) ?>" <?= $isCompleted ? 'checked' : '' ?> title="교육 수료 및 시간 인정 여부">
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="pt-2 text-right text-xs text-slate-500 font-medium">
          총 선택 참석자: <strong id="selected-att-count" class="text-blue-600 font-bold text-sm">0</strong>명
        </div>
      </div>

      <!-- Section 3: Evidence Photos & Scanned Hand-Signed Document -->
      <!-- Section 3: Evidence Files & Photos -->
      <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-4">
        <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center space-x-2 border-b border-slate-100 pb-3">
          <i data-lucide="camera" class="w-4 h-4 text-purple-600"></i>
          <span>3. 교육 증빙 자료 (서명본, 이수증 및 사진대지)</span>
        </h2>

        <!-- 1) 교육일지 서명 스캔본 첨부 (확정완료 필수) -->
        <div class="p-4 bg-emerald-50/40 border border-emerald-300 rounded-xl space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-800 flex items-center space-x-1.5">
              <i data-lucide="file-signature" class="w-4 h-4 text-emerald-600"></i>
              <span>① 교육일지 자필 서명본 스캔 첨부 (출력 후 근로자/결재권자 자필 서명 원본)</span>
              <span class="bg-emerald-600 text-white text-[10px] font-bold px-1.5 py-0.2 rounded">확정완료 필수</span>
            </span>
            <?php if (!empty($training['scanned_doc_filename'])): ?>
              <div class="flex items-center space-x-2">
                <span class="text-[11px] text-emerald-700 font-bold flex items-center space-x-1">
                  <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                  <span>기등록 서명본: <?= htmlspecialchars($training['scanned_doc_filename']) ?></span>
                </span>
                <a href="secure_file.php?type=scan&file=<?= urlencode($training['scanned_doc_filename']) ?>" onclick="openDocViewer(this.href, '교육일지 자필 서명본', '<?= htmlspecialchars($training['scanned_doc_filename']) ?>'); return false;" class="px-2.5 py-1 bg-white hover:bg-emerald-100 text-emerald-800 border border-emerald-300 rounded-lg text-xs font-bold flex items-center space-x-1">
                  <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                  <span>서명본 열람</span>
                </a>
              </div>
            <?php endif; ?>
          </div>
          <p class="text-[11px] text-slate-500">인쇄된 교육일지에 근로자 및 관리자의 자필 서명을 완료한 스캔본(PDF/이미지)을 첨부합니다. <strong class="text-emerald-700 font-bold">※ 서명본 스캔 또는 아래 교육이수증 중 하나가 등록되면 최종 [확정완료] 처리가 가능합니다.</strong></p>
          <input type="file" name="scanned_doc_file" accept=".pdf,image/jpeg,image/png" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-emerald-100 file:text-emerald-800 hover:file:bg-emerald-200">
        </div>

        <!-- 2) 공식 교육이수증 / 수료증 첨부 (직무/체험/위탁교육 해당) -->
        <div class="p-4 bg-blue-50/40 border border-blue-300 rounded-xl space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-800 flex items-center space-x-1.5">
              <i data-lucide="award" class="w-4 h-4 text-blue-600"></i>
              <span>② 공식 교육이수증(수료증) 스캔본 첨부</span>
              <span class="bg-blue-100 text-blue-800 border border-blue-300 text-[10px] font-bold px-1.5 py-0.2 rounded">직무·체험·위탁교육</span>
            </span>
            <?php if (!empty($training['cert_doc_filename'])): ?>
              <div class="flex items-center space-x-2">
                <span class="text-[11px] text-blue-700 font-bold flex items-center space-x-1">
                  <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                  <span>기등록 이수증: <?= htmlspecialchars($training['cert_doc_filename']) ?></span>
                </span>
                <a href="secure_file.php?type=scan&file=<?= urlencode($training['cert_doc_filename']) ?>" onclick="openDocViewer(this.href, '공식 교육이수증', '<?= htmlspecialchars($training['cert_doc_filename']) ?>'); return false;" class="px-2.5 py-1 bg-white hover:bg-blue-100 text-blue-800 border border-blue-300 rounded-lg text-xs font-bold flex items-center space-x-1">
                  <i data-lucide="award" class="w-3.5 h-3.5"></i>
                  <span>이수증 열람</span>
                </a>
              </div>
            <?php endif; ?>
          </div>
          <p class="text-[11px] text-slate-500">한국산업안전보건공단 또는 직무교육기관 등에서 발급된 법정 교육이수확인서(수료증) PDF/이미지 원본을 첨부합니다.</p>
          <input type="file" name="cert_doc_file" accept=".pdf,image/jpeg,image/png" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-100 file:text-blue-800 hover:file:bg-blue-200">
        </div>

        <!-- Evidence Photos for Page 2 -->
        <div class="space-y-3 pt-2">
          <div class="flex items-center justify-between">
            <div>
              <span class="text-xs font-bold text-slate-800">현장 증빙사진 등록 (양면 인쇄 시 2페이지 사진대지에 자동 배치)</span>
              <p class="text-[11px] text-slate-500">원본 비율 및 촬영 방향(EXIF 회전)이 그대로 보존되어 A4 세로 2페이지에 인쇄됩니다.</p>
            </div>
            <span class="text-[11px] bg-purple-50 text-purple-700 border border-purple-200 px-2 py-0.5 rounded font-bold">기본 2장 배치</span>
          </div>

          <!-- Existing Photos List -->
          <?php if (!empty($existingPhotos)): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="existing-photos-grid">
              <?php foreach ($existingPhotos as $idx => $p): ?>
                <div class="p-3 border border-slate-200 rounded-xl bg-slate-50 flex items-center space-x-3">
                  <img src="secure_file.php?type=photo&file=<?= urlencode($p['stored_name']) ?>" class="w-20 h-16 object-cover rounded-lg border border-slate-200">
                  <div class="flex-1 min-w-0 text-xs space-y-1">
                    <input type="text" class="photo-caption-input w-full px-2 py-1 border border-slate-200 rounded bg-white text-xs font-medium" 
                      data-id="<?= $p['id'] ?>" value="<?= htmlspecialchars($p['caption'] ?: '현장 사진 ' . ($idx+1)) ?>">
                    <div class="flex items-center justify-between text-[11px] text-slate-500">
                      <span>순서: <?= $p['display_order'] ?></span>
                      <button type="button" onclick="deletePhoto(<?= $p['id'] ?>)" class="text-rose-600 hover:underline">삭제</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Hidden Native File Input for Photos -->
          <input type="file" id="photos_input" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" class="hidden">

          <!-- Interactive Drop & Paste Box for Photos (사진대지) -->
          <div id="photos-dropzone" tabindex="0" class="border-2 border-dashed border-blue-300 hover:border-blue-500 bg-white hover:bg-blue-50/40 transition-all rounded-xl p-4 text-center cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-2xs">
            <div class="flex flex-col items-center justify-center space-y-1.5 py-1">
              <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mb-0.5">
                <i data-lucide="image-plus" class="w-5 h-5"></i>
              </div>
              <p class="text-xs font-bold text-slate-800">
                <span class="text-blue-700 underline font-semibold">사진 파일 선택</span>을 클릭하거나, 이미지를 드래그 또는 <kbd class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded font-mono font-bold text-[11px] border border-blue-200">Ctrl + V</kbd> 붙여넣기
              </p>
              <p class="text-[11px] text-slate-500">
                스마트폰 사진 복사나 캡처 도구(Win+Shift+S)로 스크린샷 복사 후 바로 <kbd class="font-mono bg-slate-100 px-1 py-0.2 rounded text-[10px]">Ctrl+V</kbd>를 누르면 사진대지에 즉시 등록됩니다. (여러 장 연속 붙여넣기 가능)
              </p>
            </div>
          </div>

          <!-- Newly Added (Pending) Photos Grid -->
          <div id="pending-photos-list" class="grid grid-cols-1 sm:grid-cols-2 gap-3 empty:hidden pt-1"></div>
        </div>
      </div>

    </form>
  </main>

  <script>
    lucide.createIcons();
    initPhotoDropAndPaste();

    // Support Tab key indent in content summary textareas
    ['content_summary_left', 'content_summary_right'].forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
          e.preventDefault();
          const start = this.selectionStart;
          const end = this.selectionEnd;
          this.value = this.value.substring(0, start) + '  ' + this.value.substring(end);
          this.selectionStart = this.selectionEnd = start + 2;
        }
      });
    });

    // Helper to calculate 4th Friday
    function getFourthFriday(year, month) {
      let fridays = [];
      let d = new Date(year, month - 1, 1);
      while (d.getMonth() === month - 1) {
        if (d.getDay() === 5) { // Friday
          fridays.push(d.getDate());
        }
        d.setDate(d.getDate() + 1);
      }
      let day = fridays[3] || fridays[fridays.length - 1];
      return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    // Helper to calculate 3rd Friday
    function getThirdFriday(year, month) {
      let fridays = [];
      let d = new Date(year, month - 1, 1);
      while (d.getMonth() === month - 1) {
        if (d.getDay() === 5) {
          fridays.push(d.getDate());
        }
        d.setDate(d.getDate() + 1);
      }
      let day = fridays[2] || fridays[0];
      return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    // Helper to calculate 1st Friday of Jan
    function getFirstFriday(year, month = 1) {
      let d = new Date(year, month - 1, 1);
      while (d.getMonth() === month - 1) {
        if (d.getDay() === 5) {
          return `${year}-${String(month).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }
        d.setDate(d.getDate() + 1);
      }
      return `${year}-01-02`;
    }

    function onTypeChanged() {
      const type = document.getElementById('training_type').value;
      const msdsBox = document.getElementById('msds-box');
      const otherBox = document.getElementById('other-type-box');
      const specialBox = document.getElementById('special-round-box');

      msdsBox.classList.toggle('hidden', type !== 'MSDS');
      otherBox.classList.toggle('hidden', type !== 'OTHER');
      specialBox.classList.toggle('hidden', type !== 'SPECIAL');

      const csmSelect = document.getElementById('csm_item_code');
      if (csmSelect) {
        if (type === 'REGULAR' || type === 'SUPERVISOR') csmSelect.value = '5.1.2';
        else if (type === 'NEW_HIRE') csmSelect.value = '5.1.3';
        else if (type === 'SPECIAL') csmSelect.value = '5.1.4';
        else if (type === 'MSDS') csmSelect.value = '5.1.5';
        else if (type === 'OTHER') {
          const sub = document.getElementById('sub_type').value;
          if (sub === 'EXPERIENCE_TRAINING' || sub === 'PORT_SAFETY') csmSelect.value = '5.2.2';
          else if (sub === 'SAFETY_DIRECTOR' || sub === 'SAFETY_OFFICER') csmSelect.value = '5.1.1';
          else csmSelect.value = '기타';
        }
      }

      calcDefaultDate();
      calcHours();
      loadCurriculumSuggestion();
    }

    function onCsmItemCodeChanged() {
      const code = document.getElementById('csm_item_code').value;
      if (code === '5.2.2') {
        document.getElementById('training_type').value = 'OTHER';
        onTypeChanged();
        document.getElementById('sub_type').value = 'EXPERIENCE_TRAINING';
        onSubTypeChanged();
      } else if (code === '5.1.3') {
        document.getElementById('training_type').value = 'NEW_HIRE';
        onTypeChanged();
      } else if (code === '5.1.2') {
        document.getElementById('training_type').value = 'REGULAR';
        onTypeChanged();
      } else if (code === '5.1.4') {
        document.getElementById('training_type').value = 'SPECIAL';
        onTypeChanged();
      } else if (code === '5.1.5') {
        document.getElementById('training_type').value = 'MSDS';
        onTypeChanged();
      }
    }

    function onSubTypeChanged() {
      const type = document.getElementById('training_type').value;
      if (type === 'OTHER') {
        const sub = document.getElementById('sub_type').value;
        const csmSelect = document.getElementById('csm_item_code');
        if (csmSelect) {
          if (sub === 'EXPERIENCE_TRAINING' || sub === 'PORT_SAFETY') {
            csmSelect.value = '5.2.2';
            if (sub === 'EXPERIENCE_TRAINING') {
              document.getElementById('start_time').value = '13:00';
              document.getElementById('end_time').value = '17:00';
              document.getElementById('planned_hours').value = '4.0';
              const opInput = document.querySelector('input[name="evaluation_opinion"]');
              if (opInput) opInput.value = '한라시멘트 사업장 투입 근로자 16명 전원 안전체험교육장 이수 완료 (참여실적 100%)';
              if (!IS_EDIT_MODE) {
                selectExperienceTraining16();
              }
            }
          }
          else if (sub === 'SAFETY_DIRECTOR' || sub === 'SAFETY_OFFICER') csmSelect.value = '5.1.1';
          else csmSelect.value = '기타';
        }
      }
      loadCurriculumSuggestion();
    }

    function onScheduleModeChanged() {
      const isCustom = document.getElementById('is_custom_schedule').value === '1';
      if (!isCustom) {
        calcDefaultDate();
      }
    }

    function calcDefaultDate() {
      const type = document.getElementById('training_type').value;
      const dateInput = document.getElementById('training_date');
      const curDate = new Date(dateInput.value || new Date());
      const year = curDate.getFullYear();
      const month = curDate.getMonth() + 1;

      if (type === 'REGULAR') {
        dateInput.value = getFourthFriday(year, month);
        document.getElementById('start_time').value = '13:00';
        document.getElementById('end_time').value = '15:00';
        document.getElementById('planned_hours').value = '2.0';
      } else if (type === 'SPECIAL') {
        const round = document.querySelector('input[name="session_round"]:checked')?.value || '1';
        if (round === '1') {
          dateInput.value = getThirdFriday(year, month);
        } else {
          dateInput.value = getFourthFriday(year, month);
        }
        document.getElementById('start_time').value = '13:00';
        document.getElementById('end_time').value = '17:00';
        document.getElementById('planned_hours').value = '4.0';
      } else if (type === 'OTHER') {
        const sub = document.getElementById('sub_type')?.value || '';
        if (sub === 'EXPERIENCE_TRAINING' || sub === 'PORT_SAFETY') {
          document.getElementById('start_time').value = '13:00';
          document.getElementById('end_time').value = '17:00';
          document.getElementById('planned_hours').value = '4.0';
        } else {
          dateInput.value = getFirstFriday(year, 1);
          document.getElementById('planned_hours').value = '1.0';
        }
      } else if (type === 'MSDS') {
        document.getElementById('planned_hours').value = '2.0';
      } else if (type === 'NEW_HIRE') {
        document.getElementById('planned_hours').value = '8.0';
        document.getElementById('start_time').value = '09:00';
        document.getElementById('end_time').value = '18:00';
      }

      triggerCollisionCheck();
      onTrainingDateChange();
    }

    function applySpecialRound(round) {
      calcDefaultDate();
      loadCurriculumSuggestion();
    }

    function calcHours() {
      const start = document.getElementById('start_time').value;
      const end = document.getElementById('end_time').value;
      if (start && end) {
        const [sh, sm] = start.split(':').map(Number);
        const [eh, em] = end.split(':').map(Number);
        let diff = (eh * 60 + em) - (sh * 60 + sm);
        if (diff > 0) {
          let hrs = (diff / 60);
          // Round to nearest 0.5
          document.getElementById('planned_hours').value = (Math.round(hrs * 2) / 2).toFixed(1);
        }
      }
    }

    function triggerCollisionCheck() {
      const date = document.getElementById('training_date').value;
      const start = document.getElementById('start_time').value;
      const end = document.getElementById('end_time').value;
      const curId = '<?= $id ?>';

      if (!date || !start || !end) return;

      const checkedBoxes = Array.from(document.querySelectorAll('.emp-card:not(.hidden) .att-checkbox:checked'));
      const attendeeNos = checkedBoxes.map(cb => cb.getAttribute('data-no')).filter(Boolean).join(',');

      fetch(`api.php?action=check_collision&date=${date}&start_time=${start}&end_time=${end}&exclude_id=${curId}&attendee_nos=${encodeURIComponent(attendeeNos)}`)
        .then(r => r.json())
        .then(data => {
          const banner = document.getElementById('collision-banner');
          const msg = document.getElementById('collision-msg');
          const noticeBanner = document.getElementById('separate-audience-banner');
          const noticeMsg = document.getElementById('separate-audience-msg');

          if (data.ok && data.has_collision) {
            // Case 1: Same person is enrolled in both overlapping trainings! Real collision!
            if (data.has_person_collision) {
              banner.classList.remove('hidden');
              if (noticeBanner) noticeBanner.classList.add('hidden');
              let personCollisions = data.collisions.filter(c => c.has_person_collision);
              let details = personCollisions.map(c => `[${c.training_no}] ${c.subject}: 중복 인원 (${c.overlapping_attendees.join(', ')})`).join(', ');
              msg.innerHTML = `선택하신 일시(${date} ${start}~${end})에 다음 교육과 <strong>참석 근로자 중복</strong>이 감지되었습니다: ${details}`;
            } 
            // Case 2: Time overlaps, but attendees are completely different workers (or attendees specified)! Safe!
            else if (data.has_attendees_specified) {
              banner.classList.add('hidden');
              if (noticeBanner) {
                noticeBanner.classList.remove('hidden');
                let collisionList = data.collisions.map(c => `[${c.training_no}] ${c.subject}`).join(', ');
                noticeMsg.textContent = `동일 일자 타 교육(${collisionList})과 교육 대상 근로자가 분리(다른 근로자)되어 있어 정상 등록 및 인정됩니다.`;
              }
            } else {
              // Default if no attendees checked yet
              banner.classList.remove('hidden');
              if (noticeBanner) noticeBanner.classList.add('hidden');
              let collisionList = data.collisions.map(c => `[${c.training_no}] ${c.subject} (${c.start_time}~${c.end_time})`).join(', ');
              msg.innerHTML = `선택하신 일시(${date} ${start}~${end})에 다음 교육이 등록되어 있습니다: ${collisionList}<br><span class="text-[11px] text-slate-600 font-normal">※ 교육 대상 근로자가 서로 다른 분리 교육인 경우 아래 참석자 선택 시 자동으로 분리 인정됩니다.</span>`;
            }
          } else {
            banner.classList.add('hidden');
            if (noticeBanner) noticeBanner.classList.add('hidden');
          }
        })
        .catch(console.error);
    }

    function loadCurriculumSuggestion() {
      const type = document.getElementById('training_type').value;
      const dateVal = document.getElementById('training_date').value;
      const month = dateVal ? parseInt(dateVal.split('-')[1], 10) : new Date().getMonth() + 1;
      const subType = document.getElementById('sub_type')?.value || '';
      const round = document.querySelector('input[name="session_round"]:checked')?.value || '1';

      fetch(`api.php?action=plan_curriculum&type=${encodeURIComponent(type)}&month=${month}&sub_type=${encodeURIComponent(subType)}&round=${round}`)
        .then(r => r.json())
        .then(data => {
          if (data.ok && data.curriculum) {
            const curr = data.curriculum;
            if (curr.subject) document.getElementById('subject').value = curr.subject;
            if (curr.hours) document.getElementById('planned_hours').value = curr.hours;
            if (curr.method) document.getElementById('method').value = curr.method;
            if (curr.location) document.getElementById('location').value = curr.location;
            if (curr.instructor_name) document.getElementById('instructor_name').value = curr.instructor_name;
            if (curr.instructor_position) document.getElementById('instructor_position').value = curr.instructor_position;
            
            if (curr.left_content) {
              document.getElementById('content_summary_left').value = curr.left_content;
            }
            if (curr.right_content) {
              document.getElementById('content_summary_right').value = curr.right_content;
            }
            
            let combined = '';
            if (curr.combined_content) {
              combined = curr.combined_content;
            } else if (curr.left_content || curr.right_content) {
              combined = (curr.left_content || '') + '\n\n' + (curr.right_content || '');
            } else if (curr.contents) {
              combined = curr.contents.join('\n');
            }
            document.getElementById('content_summary').value = combined;

            // Source badge
            const badge = document.getElementById('plan-source-badge');
            const txt = document.getElementById('plan-source-text');
            const sourceStr = curr.source || `(주)현대기전 정기안전교육계획서 출처: '${curr.sheet || '공통'}'`;
            txt.innerText = sourceStr;
            document.getElementById('planned_content_source').value = sourceStr;
            badge.classList.remove('hidden');
          }
        })
        .catch(console.error);
    }

    function pickMsds(id) {
      const sel = document.getElementById('msds_selector');
      const opt = sel.options[sel.selectedIndex];
      if (!opt || !id) {
        document.getElementById('msds_material_id').value = '';
        document.getElementById('msds_material_name').value = '';
        document.getElementById('msds_revision_date').value = '';
        document.getElementById('msds_revision_count').value = '';
        return;
      }
      const name = opt.getAttribute('data-name');
      const date = opt.getAttribute('data-date');
      const count = opt.getAttribute('data-count');

      document.getElementById('msds_material_id').value = id;
      document.getElementById('msds_material_name').value = name;
      document.getElementById('msds_revision_date').value = date;
      document.getElementById('msds_revision_count').value = count;
      document.getElementById('subject').value = `2026년 MSDS 교육 (${name})`;
    }

    const INACTIVE_TEAMS = <?= json_encode($inactiveTeams) ?>;
    const IS_EDIT_MODE = <?= ($id > 0) ? 'true' : 'false' ?>;

    function onTrainingDateChange() {
      const d = document.getElementById('training_date').value;
      filterAttendeesByDate(d);
    }

    function filterAttendeesByDate(dateStr) {
      if (!dateStr) return;
      
      const currentType = document.getElementById('training_type')?.value || '';

      // 1. Filter each employee card based on employment status relative to dateStr
      document.querySelectorAll('.emp-card').forEach(card => {
        const team = card.getAttribute('data-team') || '';
        const isRetired = card.getAttribute('data-is-retired') === '1';
        const joinDate = card.getAttribute('data-join-date') || '';
        const retiredAt = card.getAttribute('data-retired-at') || '';
        const cb = card.querySelector('.att-checkbox');
        
        let isEligible = true;
        // Inactive team members are completely excluded
        if (INACTIVE_TEAMS.includes(team)) {
          isEligible = false;
        } else if (joinDate && dateStr < joinDate) {
          // Exclude if training date is prior to join date (미입사자 제외)
          isEligible = false;
        } else if (retiredAt && dateStr >= retiredAt) {
          // Exclude if training date is on or after retirement date (퇴사자 제외)
          isEligible = false;
        } else if (isRetired && !retiredAt) {
          // Exclude if marked retired without retirement date
          isEligible = false;
        }
        
        if (isEligible) {
          const wasHidden = card.classList.contains('hidden');
          card.classList.remove('hidden');
          // For newly created training log, auto-check newly eligible active employees on this date
          // Only for REGULAR training
          if (!IS_EDIT_MODE && wasHidden && cb && currentType === 'REGULAR') {
            cb.checked = true;
          }
        } else {
          card.classList.add('hidden');
          if (cb) cb.checked = false; // Exclude from selection and uncheck
        }
      });

      // 2. Filter teams based on whether they have active members on this date
      document.querySelectorAll('.team-card').forEach(teamCard => {
        const teamName = teamCard.getAttribute('data-team');
        const teamKey = teamCard.getAttribute('data-team-key');
        const visibleCount = teamCard.querySelectorAll('.emp-card:not(.hidden)').length;
        const quickBtn = document.getElementById('quick-btn-' + teamKey);
        const countBadge = teamCard.querySelector('.team-active-count');
        const quickCountBadge = quickBtn ? quickBtn.querySelector('.team-quick-count') : null;

        if (INACTIVE_TEAMS.includes(teamName) || visibleCount === 0) {
          // Hide inactive team completely
          teamCard.classList.add('hidden');
          if (quickBtn) quickBtn.classList.add('hidden');
        } else {
          teamCard.classList.remove('hidden');
          if (quickBtn) quickBtn.classList.remove('hidden');
          if (countBadge) countBadge.innerText = visibleCount;
          if (quickCountBadge) quickCountBadge.innerText = visibleCount;
        }
      });

      // 3. Update total selected count
      updateCount();
    }

    // Safety Experience Training 16 target workers helper
    function selectExperienceTraining16() {
      // 16 Target Workers for Safety Experience Training (5.2.2):
      // - 가스팀 (6): 김도담, 박재민, 이윤성, 장진혁, 정재호, 최정섭
      // - 공사팀-모터 (2): 김종훈, 조한봉
      // - 공사팀-전기 (7): 진종철, 엄기준, 윤순형, 윤택천, 이돈희, 정민교, 한지민
      // - 안전관리 (1): 김남균
      const targetNos = ['9247', '4879', '4456', '8466', '2313', '5349', '1117', '2559', '7204', '1280', '3092', '1234', '0639', '9460', '0185', '5878'];

      // Uncheck all first
      document.querySelectorAll('.emp-card .att-checkbox').forEach(cb => cb.checked = false);

      let matched = 0;
      document.querySelectorAll('.emp-card:not(.hidden)').forEach(card => {
        const cb = card.querySelector('.att-checkbox');
        const compCb = card.querySelector('.att-comp-checkbox');
        const eno = cb ? cb.getAttribute('data-no') : '';
        if (targetNos.includes(eno)) {
          if (cb) { cb.checked = true; matched++; }
          if (compCb) compCb.checked = true;
        }
      });
      updateCount();
    }

    function toggleAllAttendees(select) {
      document.querySelectorAll('.emp-card:not(.hidden) .att-checkbox').forEach(cb => cb.checked = select);
      updateCount();
    }

    function toggleTeam(teamName, select) {
      const teamCard = document.querySelector(`.team-card[data-team="${teamName}"]`);
      if (!teamCard) return;
      teamCard.querySelectorAll('.emp-card:not(.hidden) .att-checkbox').forEach(cb => cb.checked = select);
      updateCount();
    }

    function updateCount() {
      const allCards = document.querySelectorAll('.emp-card:not(.hidden)');
      let checked = 0;
      allCards.forEach(card => {
        const cb = card.querySelector('.att-checkbox');
        const compWrap = card.querySelector('.comp-wrap');
        const compCb = card.querySelector('.att-comp-checkbox');
        if (cb && cb.checked) {
          checked++;
          card.classList.add('border-blue-400', 'bg-blue-50/40');
          card.classList.remove('border-slate-200', 'bg-white');
          if (compWrap) compWrap.style.opacity = '1';
          if (compCb) compCb.disabled = false;
        } else {
          card.classList.remove('border-blue-400', 'bg-blue-50/40');
          card.classList.add('border-slate-200', 'bg-white');
          if (compWrap) compWrap.style.opacity = '0.35';
          if (compCb) compCb.disabled = true;
        }
      });
      document.getElementById('selected-att-count').innerText = checked;
      triggerCollisionCheck();
    }

    function deletePhoto(pid) {
      if (!confirm('이 사진을 삭제하시겠습니까?')) return;
      const fd = new FormData();
      fd.append('action', 'delete_photo');
      fd.append('photo_id', pid);
      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (d.ok) location.reload();
          else alert(d.error);
        });
    }

    function submitForm(e) {
      e.preventDefault();
      const form = document.getElementById('write-form');
      const fd = new FormData(form);
      fd.append('action', 'save');

      const leftVal = document.getElementById('content_summary_left')?.value || '';
      const rightVal = document.getElementById('content_summary_right')?.value || '';
      fd.set('content_summary_left', leftVal);
      fd.set('content_summary_right', rightVal);
      fd.set('content_summary', (leftVal ? ('[좌측 본문]\n' + leftVal) : '') + (rightVal ? ('\n\n[우측 본문]\n' + rightVal) : ''));

      // Collect Attendees: ONLY include checked attendees that are NOT hidden (active at date)
      const attendees = [];
      document.querySelectorAll('.emp-card:not(.hidden) .att-checkbox:checked').forEach(cb => {
        const eno = cb.getAttribute('data-no');
        const compCb = document.querySelector(`.att-comp-checkbox[data-no="${eno}"]`);
        attendees.push({
          employee_id: cb.getAttribute('data-id'),
          employee_no: eno,
          name: cb.getAttribute('data-name'),
          team: cb.getAttribute('data-team-name'),
          position: cb.getAttribute('data-position'),
          is_completed: compCb && compCb.checked ? 1 : 0
        });
      });
      fd.append('attendees_json', JSON.stringify(attendees));

      // Check if scanned document or cert attached separately
      const scanFile = form.querySelector('input[name="scanned_doc_file"]')?.files[0];
      const certFile = form.querySelector('input[name="cert_doc_file"]')?.files[0];

      fetch('api.php', { method: 'POST', body: fd })
        .then(async r => {
          const text = await r.text();
          try {
            return JSON.parse(text);
          } catch (e) {
            throw new Error(text.replace(/<[^>]*>/g, '').trim() || `서버 오류 (HTTP ${r.status})`);
          }
        })
        .then(async data => {
          if (data.ok) {
            const newId = data.id;
            // If signed log scan attached, upload it
            if (scanFile) {
              const sfd = new FormData();
              sfd.append('action', 'upload_scan');
              sfd.append('id', newId);
              sfd.append('doc_type', 'signed');
              sfd.append('scan_file', scanFile);
              await fetch('api.php', { method: 'POST', body: sfd });
            }
            // If cert attached, upload it
            if (certFile) {
              const cfd = new FormData();
              cfd.append('action', 'upload_scan');
              cfd.append('id', newId);
              cfd.append('doc_type', 'cert');
              cfd.append('scan_file', certFile);
              await fetch('api.php', { method: 'POST', body: cfd });
            }
            return newId;
          } else {
            throw new Error(data.error);
          }
        })
        .then((finalId) => {
          alert('성공적으로 저장되었습니다.');
          location.href = `view.php?id=${finalId}`;
        })
        .catch(err => alert('저장 실패: ' + err.message));
    }

    // -------------------------------------------------------------
    // Photos Drop & Paste (사진대지 Ctrl+V 붙여넣기 및 파일선택)
    // -------------------------------------------------------------
    const photoDt = new DataTransfer();

    function initPhotoDropAndPaste() {
      const dropzone = document.getElementById('photos-dropzone');
      const fileInput = document.getElementById('photos_input');
      if (!dropzone || !fileInput) return;

      // Click dropzone to open native file picker
      dropzone.addEventListener('click', (e) => {
        if (e.target.closest('button') || e.target.closest('input')) return;
        fileInput.click();
      });

      // When user selects files via native file picker dialog
      fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
          addPhotoFiles(fileInput.files);
        }
      });

      // Drag and drop events
      ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.add('border-blue-500', 'bg-blue-50/70', 'ring-2', 'ring-blue-400');
        });
      });

      ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.remove('border-blue-500', 'bg-blue-50/70', 'ring-2', 'ring-blue-400');
        });
      });

      dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        if (dt && dt.files && dt.files.length > 0) {
          addPhotoFiles(dt.files);
        }
      });

      // Global Clipboard Paste Listener (Ctrl+V)
      document.addEventListener('paste', handlePhotoPaste);
    }

    function addPhotoFiles(fileList) {
      let added = 0;
      for (let i = 0; i < fileList.length; i++) {
        const f = fileList[i];
        if (!f.type.startsWith('image/')) continue;
        if (f.size > 10 * 1024 * 1024) {
          alert(`[${f.name}] 파일 용량이 10MB를 초과하여 제외되었습니다.`);
          continue;
        }

        let finalFile = f;
        if (f.name === 'image.png' || !f.name) {
          const now = new Date();
          const pad = n => String(n).padStart(2, '0');
          const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
          finalFile = new File([f], `현장사진_${ts}_${photoDt.files.length + 1}.png`, { type: f.type || 'image/png' });
        }

        photoDt.items.add(finalFile);
        added++;
      }

      document.getElementById('photos_input').files = photoDt.files;
      renderPendingPhotos();
      return added;
    }

    function removePendingPhoto(index) {
      const newDt = new DataTransfer();
      const files = photoDt.files;
      for (let i = 0; i < files.length; i++) {
        if (i !== index) newDt.items.add(files[i]);
      }
      photoDt.items.clear();
      for (let f of newDt.files) photoDt.items.add(f);
      document.getElementById('photos_input').files = photoDt.files;
      renderPendingPhotos();
    }

    function renderPendingPhotos() {
      const container = document.getElementById('pending-photos-list');
      if (!container) return;
      container.innerHTML = '';

      const files = photoDt.files;
      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const card = document.createElement('div');
        card.className = 'p-3 border-2 border-blue-200 rounded-xl bg-blue-50/50 flex items-center space-x-3 shadow-xs';
        const url = URL.createObjectURL(file);
        const safeName = file.name.replace(/"/g, '&quot;');

        card.innerHTML = `
          <img src="${url}" class="w-20 h-16 object-cover rounded-lg border border-blue-200 shrink-0 bg-white">
          <div class="flex-1 min-w-0 text-xs space-y-1">
            <div class="flex items-center justify-between">
              <span class="font-bold text-slate-800 truncate" title="${safeName}">${safeName}</span>
              <span class="text-[10px] text-blue-700 bg-blue-100 px-1.5 py-0.2 rounded font-mono shrink-0">${(file.size / 1024).toFixed(0)}KB</span>
            </div>
            <input type="text" name="new_photo_captions[]" value="현장 사진 (${i + 1})" 
              class="w-full px-2 py-1 border border-slate-200 rounded bg-white text-xs font-medium" placeholder="사진 설명 입력">
            <div class="flex items-center justify-between text-[11px] text-slate-500 pt-0.5">
              <span class="text-emerald-600 font-semibold flex items-center space-x-1">
                <i data-lucide="check" class="w-3 h-3"></i>
                <span>저장 시 사진대지 배치</span>
              </span>
              <button type="button" onclick="removePendingPhoto(${i})" class="text-rose-600 hover:underline font-bold">삭제</button>
            </div>
          </div>
        `;
        container.appendChild(card);
      }
      if (window.lucide) lucide.createIcons();
    }

    function handlePhotoPaste(e) {
      const activeEl = document.activeElement;
      // If user is focused on a normal text input/textarea and pasting plain text without files, don't intercept!
      const items = e.clipboardData?.items;
      const files = e.clipboardData?.files;

      let imageFiles = [];
      if (files && files.length > 0) {
        for (let f of files) {
          if (f.type.startsWith('image/')) imageFiles.push(f);
        }
      } else if (items && items.length > 0) {
        for (let it of items) {
          if (it.kind === 'file') {
            const f = it.getAsFile();
            if (f && f.type.startsWith('image/')) imageFiles.push(f);
          }
        }
      }

      // No image files found on clipboard -> regular text paste proceeds
      if (imageFiles.length === 0) return;

      // An image was pasted! Intercept and attach to 사진대지
      e.preventDefault();

      const count = addPhotoFiles(imageFiles);
      if (count > 0) {
        showPhotoToast(`📷 사진대지에 ${count}장의 사진이 클립보드에서 붙여넣어졌습니다.`);
        const dropzone = document.getElementById('photos-dropzone');
        if (dropzone) {
          dropzone.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }
    }

    function showPhotoToast(msg) {
      let toast = document.getElementById('photo-paste-toast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'photo-paste-toast';
        toast.className = 'fixed bottom-6 right-6 z-50 bg-slate-900/95 text-white px-4 py-2.5 rounded-xl shadow-2xl text-xs font-bold flex items-center space-x-2 transition-all duration-300 transform translate-y-4 opacity-0 pointer-events-none backdrop-blur-sm border border-slate-700';
        document.body.appendChild(toast);
      }
      toast.innerHTML = `<i data-lucide="camera" class="w-4 h-4 text-emerald-400"></i><span>${msg}</span>`;
      if (window.lucide) lucide.createIcons();
      toast.classList.remove('translate-y-4', 'opacity-0');
      toast.classList.add('translate-y-0', 'opacity-100');
      setTimeout(() => {
        toast.classList.remove('translate-y-0', 'opacity-100');
        toast.classList.add('translate-y-4', 'opacity-0');
      }, 2500);
    }

    // Modal: Add New Other Training Type
    function openAddOtherTypeModal() {
      const modal = document.getElementById('add-other-type-modal');
      modal.classList.remove('hidden');
      setTimeout(() => document.getElementById('new-other-name')?.focus(), 50);
      if (window.lucide) lucide.createIcons();
    }

    function closeAddOtherTypeModal() {
      document.getElementById('add-other-type-modal')?.classList.add('hidden');
    }

    function submitNewOtherType(e) {
      e.preventDefault();
      const name = document.getElementById('new-other-name').value.trim();
      const hours = parseFloat(document.getElementById('new-other-hours').value) || 2.0;
      const desc = document.getElementById('new-other-desc').value.trim();
      const csmItem = document.getElementById('new-other-csm-item').value;
      const btn = document.getElementById('new-other-submit-btn');

      if (!name) {
        alert('교육과목명을 입력해 주세요.');
        return;
      }

      btn.disabled = true;
      btn.innerText = '등록 중...';

      const fd = new FormData();
      fd.append('action', 'other_types');
      fd.append('name', name);
      fd.append('default_hours', hours);
      fd.append('description', desc);

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          btn.innerHTML = '<span>등록 및 즉시 선택</span>';
          if (!data.ok) throw new Error(data.error || '등록 실패');

          const t = data.type;
          // Add to select
          const sel = document.getElementById('sub_type');
          const opt = document.createElement('option');
          opt.value = t.code;
          opt.text = `${t.name} (${t.default_hours}시간)`;
          opt.selected = true;
          sel.appendChild(opt);

          // Update related inputs
          const curYear = new Date().getFullYear();
          document.getElementById('subject').value = `${curYear}년 ${t.name}`;
          document.getElementById('planned_hours').value = t.default_hours;
          const csmSelect = document.getElementById('csm_item_code');
          if (csmSelect) csmSelect.value = csmItem;

          // Reset inputs and close
          document.getElementById('new-other-name').value = '';
          document.getElementById('new-other-desc').value = '';
          closeAddOtherTypeModal();
          alert(`새 기타 교육과목 '${t.name}'이(가) 등록되었습니다.`);
        })
        .catch(err => {
          btn.disabled = false;
          btn.innerHTML = '<span>등록 및 즉시 선택</span>';
          alert('등록 오류: ' + err.message);
        });
    }

    // Init
    onTrainingDateChange();
    <?php if ($id === 0 && ($training['sub_type'] ?? '') === 'EXPERIENCE_TRAINING'): ?>
    selectExperienceTraining16();
    <?php endif; ?>
    updateCount();
    triggerCollisionCheck();
    if (window.lucide) lucide.createIcons();
  </script>

  <!-- Modal: Add New Other Training Type -->
  <div id="add-other-type-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all animate-in fade-in zoom-in-95 duration-150">
      <div class="bg-amber-500/10 px-5 py-4 border-b border-amber-200/60 flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <div class="p-1.5 bg-amber-500 text-white rounded-lg shadow-xs">
            <i data-lucide="plus-circle" class="w-4 h-4"></i>
          </div>
          <h3 class="font-bold text-slate-900 text-sm">새 기타 교육과목 추가</h3>
        </div>
        <button type="button" onclick="closeAddOtherTypeModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <form id="add-other-type-form" onsubmit="submitNewOtherType(event)" class="p-5 space-y-3.5 text-xs">
        <div>
          <label class="block font-bold text-slate-800 mb-1">교육과목명 <span class="text-rose-500">*</span></label>
          <input type="text" id="new-other-name" required placeholder="예: 심폐소생술(CPR) 및 응급처치 실습교육"
            class="w-full px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-amber-500 font-semibold text-slate-900 bg-slate-50 focus:bg-white">
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block font-bold text-slate-800 mb-1">기본 교육시간 (HR) <span class="text-rose-500">*</span></label>
            <input type="number" step="0.5" id="new-other-hours" required value="2.0" min="0.5" max="40.0"
              class="w-full px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-amber-500 font-bold text-blue-600 bg-slate-50 focus:bg-white">
          </div>
          <div>
            <label class="block font-bold text-slate-800 mb-1">CSM 평가 연동 항목</label>
            <select id="new-other-csm-item" class="w-full px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-amber-500 font-semibold bg-slate-50 focus:bg-white">
              <option value="5.2.2">[5.2.2] 안전체험 및 특화교육</option>
              <option value="5.1.1">[5.1.1] 직무교육 (책임자/담당자)</option>
              <option value="기타" selected>기타 법정의무교육</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block font-bold text-slate-800 mb-1">법적 근거 / 교육 목적 (선택)</label>
          <input type="text" id="new-other-desc" placeholder="예: 응급의료에 관한 법률 및 사업장 비상대응"
            class="w-full px-3 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-amber-500 text-slate-700 bg-slate-50 focus:bg-white">
        </div>

        <div class="pt-2 flex items-center justify-end space-x-2 border-t border-slate-100">
          <button type="button" onclick="closeAddOtherTypeModal()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-semibold transition">
            취소
          </button>
          <button type="submit" id="new-other-submit-btn" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold shadow-md shadow-amber-500/20 transition flex items-center space-x-1">
            <i data-lucide="check" class="w-3.5 h-3.5"></i>
            <span>등록 및 즉시 선택</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php require_once __DIR__ . '/doc_viewer_modal.php'; ?>
</body>
</html>
