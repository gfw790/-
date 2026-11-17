<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csm_functions.php';

$user = csm_require_auth();
$id = (int)($_GET['id'] ?? 0);

function render_csm_view_content_summary(string $text): string {
    $lines = explode("\n", $text);
    $html = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $html .= '<div class="h-2"></div>';
            continue;
        }
        
        // Header e.g. [좌측 본문], [2일간...]
        if (preg_match('/^(\[[^\]]+\]|◈[^◈]+◈)(.*)$/u', $trimmed)) {
            $html .= '<div class="font-bold text-slate-900 mt-1 mb-0.5">' . htmlspecialchars($trimmed) . '</div>';
            continue;
        }
        
        // Level 3 outline: 2.1.1, a., (a), etc.
        if (preg_match('/^(\d+\.\d+\.\d+(\.|\))?|[a-z]\.|\([a-z]\))\s*(.*)$/ui', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-6 py-0.5 text-slate-700">' .
                     '<span class="font-bold mr-1.5 shrink-0 text-slate-500">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[3]) . '</span></div>';
            continue;
        }
        
        // Level 2 outline: 2.1, 2.1., 2.1), 1), (1), ①, etc. (2에서 2.1로 증가 시 들여쓰기)
        if (preg_match('/^(\d+\.\d+(\.|\))?|\d+\)|\([0-9]+\)|[①-⑳])\s*(.*)$/u', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-3.5 py-0.5 text-slate-800">' .
                     '<span class="font-bold mr-1.5 shrink-0 text-indigo-700">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[3]) . '</span></div>';
            continue;
        }
        
        // Bullets: -, ·, *, ※, ★
        if (preg_match('/^([\-|\·|\*|※|★])\s*(.*)$/u', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-4 py-0.5 text-slate-600">' .
                     '<span class="font-bold mr-1.5 shrink-0 text-slate-400">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[2]) . '</span></div>';
            continue;
        }
        
        // Level 1 outline: 1., 2., 3., 가., I., etc.
        if (preg_match('/^(\d+\.|[가-하]\.|[IVXLCDM]+\.)\s*(.*)$/u', $trimmed, $m)) {
            $html .= '<div class="flex items-start pl-0 py-0.5 font-bold text-slate-900 mt-1">' .
                     '<span class="mr-1.5 shrink-0 text-slate-900">' . htmlspecialchars($m[1]) . '</span>' .
                     '<span>' . htmlspecialchars($m[2]) . '</span></div>';
            continue;
        }
        
        // Plain line
        $html .= '<div class="pl-2 py-0.5 text-slate-800">' . htmlspecialchars($trimmed) . '</div>';
    }
    return $html;
}

$db = get_csm_db();
$stmt = $db->prepare("SELECT * FROM csm_trainings WHERE id = ?");
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>교육일지를 찾을 수 없음 - CSM</title>
      <script src="https://cdn.tailwindcss.com"></script>
      <script src="https://unpkg.com/lucide@latest"></script>
    </head>
    <body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
      <div class="max-w-md w-full bg-white rounded-2xl shadow-xl border border-slate-200 p-6 text-center space-y-5">
        <div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center mx-auto shadow-inner">
          <i data-lucide="file-question" class="w-8 h-8"></i>
        </div>
        <div class="space-y-2">
          <h2 class="text-lg font-bold text-slate-900">교육일지 기록을 찾을 수 없습니다</h2>
          <p class="text-xs text-slate-500 leading-relaxed">
            요청하신 교육일지 (ID: <strong class="font-mono text-slate-800"><?= $id ?></strong>)는 이미 삭제되었거나, 
            2026년 공종별 최신 특별안전교육 또는 정기교육 데이터로 재편되어 번호가 갱신되었습니다.
          </p>
        </div>
        <div class="bg-slate-50 rounded-xl p-3 border border-slate-100 text-[11px] text-slate-600 space-y-1 text-left">
          <div class="flex items-center space-x-1.5 font-bold text-slate-700">
            <i data-lucide="info" class="w-3.5 h-3.5 text-blue-600"></i>
            <span>안내</span>
          </div>
          <p>연간 교육계획 목록 페이지를 <strong>[새로고침]</strong> 하시면 최신 일지 번호가 정상 반영되어 확인하실 수 있습니다.</p>
        </div>
        <div class="flex items-center justify-center space-x-3 pt-2">
          <a href="plan.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 transition flex items-center space-x-1.5">
            <i data-lucide="calendar" class="w-4 h-4"></i>
            <span>연간 교육계획으로 이동</span>
          </a>
          <a href="index.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition">
            대시보드 메인
          </a>
        </div>
      </div>
      <script>lucide.createIcons();</script>
    </body>
    </html>
    <?php
    exit;
}

// Attendees
$attStmt = $db->prepare("SELECT * FROM csm_training_attendees WHERE training_id = ? ORDER BY id ASC");
$attStmt->execute([$id]);
$attendees = $attStmt->fetchAll();

// Photos
$pStmt = $db->prepare("SELECT * FROM csm_training_photos WHERE training_id = ? ORDER BY display_order ASC, id ASC");
$pStmt->execute([$id]);
$photos = $pStmt->fetchAll();

// Revisions
$revStmt = $db->prepare("SELECT * FROM csm_training_revisions WHERE training_id = ? ORDER BY revision_num DESC");
$revStmt->execute([$id]);
$revisions = $revStmt->fetchAll();

// Peer session if Special Training
$peerSession = null;
if (!empty($t['course_id'])) {
    $pSessionStmt = $db->prepare("SELECT id, training_no, session_round, training_date, start_time, end_time, status FROM csm_trainings WHERE course_id = ? AND id != ?");
    $pSessionStmt->execute([$t['course_id'], $id]);
    $peerSession = $pSessionStmt->fetch();
}

$typeLabels = [
    'REGULAR' => '2. 정기안전 보건교육',
    'SPECIAL' => '5. 특별안전교육',
    'NEW_HIRE' => '1. 신규채용시 교육',
    'JOB_CHANGE' => '3. 작업내용변경시 교육',
    'SUPERVISOR' => '4. 관리감독자교육',
    'EXTERNAL' => '6. 외부강사초빙 교육',
    'OTHER' => '7. 기타 교육',
    'MSDS' => '8. MSDS 교육'
];

$otherTypesMap = [];
try {
    foreach ($db->query("SELECT code, name FROM csm_other_training_types")->fetchAll() as $row) {
        $otherTypesMap[$row['code']] = $row['name'];
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($t['training_no']) ?> - 안전보건 교육일지</title>
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
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <a href="index.php" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-500 transition" title="목록으로">
          <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <a href="../" title="CSM 통합 포털로 이동" class="text-xs text-slate-500 hover:text-blue-600 flex items-center space-x-1 font-semibold px-2 py-1 bg-slate-100 rounded-lg border border-slate-200">
          <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i>
          <span>CSM 포털</span>
        </a>
        <div>
          <div class="flex items-center space-x-2">
            <h1 class="text-base font-bold text-slate-900"><?= htmlspecialchars($t['training_no']) ?></h1>
            <?php if ($t['status'] === 'FINALIZED'): ?>
              <span class="bg-emerald-600 text-white text-[10px] font-bold px-2 py-0.5 rounded">확정완료 (Revision <?= $t['revision_num'] ?>)</span>
            <?php else: ?>
              <span class="bg-amber-500 text-white text-[10px] font-bold px-2 py-0.5 rounded">작성중 (DRAFT)</span>
            <?php endif; ?>
            <?php if ($t['is_demo'] == 1): ?>
              <span class="bg-purple-100 text-purple-800 text-[10px] font-bold px-2 py-0.5 rounded">시연 가명</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="flex items-center space-x-2">
        <?php if ($t['status'] === 'DRAFT'): ?>
          <?php $hasScan = !empty($t['scanned_doc_filename']) || !empty($t['cert_doc_filename']); ?>
          <button onclick="finalizeTraining(<?= $t['id'] ?>, <?= $hasScan ? 'true' : 'false' ?>)" 
            class="px-3 py-1.5 <?= $hasScan ? 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm' : 'bg-slate-100 hover:bg-amber-50 text-slate-700 hover:text-amber-900 border border-slate-300' ?> rounded-xl text-xs font-bold flex items-center space-x-1.5 transition"
            title="<?= $hasScan ? '확정완료 처리' : '서명본 또는 이수증 스캔 파일 등록 필요' ?>">
            <i data-lucide="<?= $hasScan ? 'check-circle' : 'alert-circle' ?>" class="w-3.5 h-3.5 <?= $hasScan ? 'text-white' : 'text-amber-600' ?>"></i>
            <span>확정완료<?= $hasScan ? '' : ' (증빙 필요)' ?></span>
          </button>
        <?php endif; ?>

        <a href="write.php?id=<?= $t['id'] ?>" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold flex items-center space-x-1 transition">
          <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
          <span>수정하기</span>
        </a>

        <a href="print.php?id=<?= $t['id'] ?>&print=1" target="_blank" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold flex items-center space-x-1 transition shadow-sm">
          <i data-lucide="printer" class="w-3.5 h-3.5"></i>
          <span>바로 인쇄</span>
        </a>

        <a href="export.php?id=<?= $t['id'] ?>&format=xlsx" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-xl text-xs font-bold flex items-center space-x-1 transition">
          <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5"></i>
          <span>엑셀 서식 출력</span>
        </a>

        <a href="export.php?id=<?= $t['id'] ?>&format=pdf" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-800 border border-rose-200 rounded-xl text-xs font-bold flex items-center space-x-1 transition">
          <i data-lucide="file-down" class="w-3.5 h-3.5"></i>
          <span>양면 PDF 다운</span>
        </a>
      </div>
    </div>
  </header>

  <main class="max-w-4xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <!-- Duplex Printing Guide Banner -->
    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 text-xs text-indigo-950 flex items-center justify-between shadow-sm">
      <div class="flex items-center space-x-3">
        <div class="bg-indigo-600 text-white p-2 rounded-xl">
          <i data-lucide="printer" class="w-5 h-5"></i>
        </div>
        <div>
          <strong class="font-bold text-sm text-indigo-900">🖨️ 회사 운영 표준 출력: A4 / 양면 인쇄 / 긴 쪽 넘김</strong>
          <p class="text-xs text-indigo-800 mt-0.5">
            1페이지: <strong>교육일지(수기 서명용 공란)</strong> &bull; 2페이지: <strong>증빙 사진대지(2장 배치)</strong>로 회사 표준 서식에 맞춰 즉시 인쇄됩니다.
          </p>
        </div>
      </div>
      <div class="flex items-center space-x-2 shrink-0">
        <a href="print.php?id=<?= $t['id'] ?>&print=1" target="_blank" class="px-3.5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 flex items-center space-x-1">
          <i data-lucide="printer" class="w-4 h-4"></i>
          <span>바로 인쇄</span>
        </a>
        <a href="export.php?id=<?= $t['id'] ?>&format=pdf" class="px-3 py-2 bg-white hover:bg-indigo-50 text-indigo-700 border border-indigo-300 rounded-xl text-xs font-bold transition">
          PDF 저장
        </a>
      </div>
    </div>

    <!-- 1. 자필 서명 교육일지 스캔본 (확정완료 필수 서류) -->
    <?php if (!empty($t['scanned_doc_filename'])): ?>
      <div class="bg-emerald-50 border border-emerald-300 rounded-2xl p-4 text-xs text-emerald-950 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-sm">
        <div class="flex items-center space-x-3">
          <i data-lucide="file-signature" class="w-6 h-6 text-emerald-600 shrink-0"></i>
          <div>
            <div class="flex items-center space-x-2">
              <strong class="font-bold text-emerald-900 text-sm">교육일지 자필 서명 스캔본 등록됨</strong>
              <span class="bg-emerald-200 text-emerald-900 text-[10px] font-bold px-2 py-0.5 rounded">서명본 검증완료</span>
            </div>
            <p class="text-emerald-700 mt-0.5 text-xs">
              등록일시: <?= $t['scanned_doc_uploaded_at'] ?> &bull; 파일: <?= htmlspecialchars($t['scanned_doc_filename']) ?>
            </p>
          </div>
        </div>
        <div class="flex items-center space-x-2 shrink-0">
          <input type="file" id="direct-scan-file" accept=".pdf,image/jpeg,image/png" class="hidden" onchange="uploadDirectDoc(<?= $t['id'] ?>, 'signed', this)">
          <button type="button" onclick="document.getElementById('direct-scan-file').click()" class="px-3 py-2 bg-white hover:bg-emerald-100 text-emerald-800 border border-emerald-300 rounded-xl text-xs font-bold transition">
            서명본 변경
          </button>
          <a href="secure_file.php?type=scan&file=<?= urlencode($t['scanned_doc_filename']) ?>" onclick="openDocViewer(this.href, '교육일지 자필 서명본 (<?= htmlspecialchars($t['training_no']) ?>)', '<?= htmlspecialchars($t['scanned_doc_filename']) ?>'); return false;" 
            class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold flex items-center space-x-1.5 shadow-sm transition">
            <i data-lucide="file-text" class="w-4 h-4"></i>
            <span>서명본 열람 ↗</span>
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="<?= empty($t['cert_doc_filename']) ? 'bg-amber-50 border-2 border-amber-300' : 'bg-slate-50 border border-slate-300' ?> rounded-2xl p-4 text-xs text-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-sm">
        <div class="flex items-center space-x-3">
          <div class="p-2.5 <?= empty($t['cert_doc_filename']) ? 'bg-amber-500 text-white' : 'bg-slate-400 text-white' ?> rounded-xl shrink-0">
            <i data-lucide="<?= empty($t['cert_doc_filename']) ? 'file-warning' : 'file-text' ?>" class="w-5 h-5"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <strong class="font-bold <?= empty($t['cert_doc_filename']) ? 'text-amber-900' : 'text-slate-800' ?> text-sm">교육일지 자필 서명본 <?= empty($t['cert_doc_filename']) ? '등록 필수 (확정 대기)' : '등록 (선택)' ?></strong>
              <span class="<?= empty($t['cert_doc_filename']) ? 'bg-amber-200 text-amber-900' : 'bg-slate-200 text-slate-700' ?> text-[10px] font-bold px-2 py-0.5 rounded">서명본 미등록</span>
              <?php if (!empty($t['cert_doc_filename'])): ?>
                <span class="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded">공식 이수증으로 확정 가능</span>
              <?php endif; ?>
            </div>
            <p class="<?= empty($t['cert_doc_filename']) ? 'text-amber-800' : 'text-slate-500' ?> mt-0.5 text-xs">
              <?= empty($t['cert_doc_filename']) 
                  ? '인쇄된 교육일지에 근로자/결재권자의 자필 서명을 완료한 스캔본 또는 외부 기관 교육이수증을 첨부해야 <strong>확정완료(FINALIZED)</strong>가 가능합니다.' 
                  : '공식 교육이수증이 등록되어 있어 <strong>즉시 확정완료(FINALIZED)</strong>가 가능하며, 필요한 경우 사내 교육일지 서명본을 추가 첨부할 수 있습니다.' ?>
            </p>
          </div>
        </div>
        <div class="flex items-center space-x-2 shrink-0">
          <input type="file" id="direct-scan-file" accept=".pdf,image/jpeg,image/png" class="hidden" onchange="uploadDirectDoc(<?= $t['id'] ?>, 'signed', this)">
          <button type="button" onclick="document.getElementById('direct-scan-file').click()" 
            class="px-4 py-2 <?= empty($t['cert_doc_filename']) ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-300' ?> rounded-xl text-xs font-bold flex items-center space-x-1.5 shadow-sm transition">
            <i data-lucide="upload" class="w-4 h-4"></i>
            <span>교육일지 서명 스캔본 업로드</span>
          </button>
        </div>
      </div>
    <?php endif; ?>

    <!-- 2. 공식 교육이수증 / 수료증 (직무교육, 체험교육, 위탁교육 등) -->
    <?php if (!empty($t['cert_doc_filename'])): ?>
      <div class="bg-blue-50 border border-blue-300 rounded-2xl p-4 text-xs text-blue-950 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-sm">
        <div class="flex items-center space-x-3">
          <i data-lucide="award" class="w-6 h-6 text-blue-600 shrink-0"></i>
          <div>
            <div class="flex items-center space-x-2">
              <strong class="font-bold text-blue-900 text-sm">공식 교육이수증(수료증) 등록됨</strong>
              <span class="bg-blue-200 text-blue-900 text-[10px] font-bold px-2 py-0.5 rounded">공단·기관 발급</span>
            </div>
            <p class="text-blue-700 mt-0.5 text-xs">
              등록일시: <?= $t['cert_doc_uploaded_at'] ?> &bull; 파일: <?= htmlspecialchars($t['cert_doc_filename']) ?>
            </p>
          </div>
        </div>
        <div class="flex items-center space-x-2 shrink-0">
          <input type="file" id="direct-cert-file" accept=".pdf,image/jpeg,image/png" class="hidden" onchange="uploadDirectDoc(<?= $t['id'] ?>, 'cert', this)">
          <button type="button" onclick="document.getElementById('direct-cert-file').click()" class="px-3 py-2 bg-white hover:bg-blue-100 text-blue-800 border border-blue-300 rounded-xl text-xs font-bold transition">
            이수증 변경
          </button>
          <a href="secure_file.php?type=scan&file=<?= urlencode($t['cert_doc_filename']) ?>" onclick="openDocViewer(this.href, '공식 교육이수증 (<?= htmlspecialchars($t['training_no']) ?>)', '<?= htmlspecialchars($t['cert_doc_filename']) ?>'); return false;" 
            class="px-3.5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold flex items-center space-x-1.5 shadow-sm transition">
            <i data-lucide="award" class="w-4 h-4"></i>
            <span>공식 이수증 열람 ↗</span>
          </a>
        </div>
      </div>
    <?php elseif (in_array($t['csm_item_code'] ?? '', ['5.1.1', '5.2.2'], true) || in_array($t['training_type'] ?? '', ['SUPERVISOR', 'EXTERNAL', 'OTHER'], true)): ?>
      <div class="bg-slate-50 border border-dashed border-blue-300 rounded-2xl p-3.5 text-xs text-slate-700 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div class="flex items-center space-x-3">
          <i data-lucide="award" class="w-5 h-5 text-blue-500 shrink-0"></i>
          <div>
            <strong class="font-bold text-slate-800 text-xs">공식 교육이수증(수료증) 추가 등록</strong>
            <p class="text-slate-500 text-[11px] mt-0.5">외부 직무교육·안전체험장 수료증 등 공단 발급 서류가 있는 경우 첨부해 보관할 수 있습니다.</p>
          </div>
        </div>
        <div class="shrink-0">
          <input type="file" id="direct-cert-file" accept=".pdf,image/jpeg,image/png" class="hidden" onchange="uploadDirectDoc(<?= $t['id'] ?>, 'cert', this)">
          <button type="button" onclick="document.getElementById('direct-cert-file').click()" class="px-3 py-1.5 bg-white hover:bg-blue-50 text-blue-700 border border-blue-300 rounded-xl text-xs font-bold transition flex items-center space-x-1">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
            <span>이수증 등록</span>
          </button>
        </div>
      </div>
    <?php endif; ?>

    <!-- Special Training Peer Session Alert -->
    <?php if ($peerSession): ?>
      <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-900 flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <i data-lucide="link" class="w-4 h-4 text-blue-600"></i>
          <span>
            <strong>특별안전교육 연계 회차:</strong> 현재 회차(<strong><?= $t['session_round'] ?>회차</strong>) &harr; 
            연계 회차: <strong><?= $peerSession['session_round'] ?>회차</strong> (<?= $peerSession['training_date'] ?> <?= $peerSession['start_time'] ?>~<?= $peerSession['end_time'] ?>)
          </span>
        </div>
        <a href="view.php?id=<?= $peerSession['id'] ?>" class="px-2.5 py-1 bg-white hover:bg-blue-50 text-blue-700 border border-blue-300 rounded-lg font-bold text-xs">
          연계 회차 확인
        </a>
      </div>
    <?php endif; ?>

    <!-- Training Form Layout Card -->
    <div class="bg-white rounded-2xl p-8 border border-slate-200 shadow-sm space-y-6">
      
      <!-- Document Title -->
      <div class="text-center border-b border-slate-200 pb-4">
        <h2 class="text-xl font-extrabold text-slate-900 tracking-wider">안 전 보 건 교 육 일 지</h2>
        <?php if ($t['is_demo'] == 1): ?>
          <p class="text-xs text-rose-600 font-bold mt-1">[ 시연용 가명 데이터 - 대외 제출 불가 ]</p>
        <?php endif; ?>
      </div>

      <!-- Header Info Table -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs bg-slate-50 p-4 rounded-xl border border-slate-200">
        <div>
          <span class="text-slate-400 block font-semibold">교육 일시</span>
          <strong class="text-slate-900 text-sm">
            <?= htmlspecialchars($t['training_date']) ?>
            <?php if (str_contains($t['subject'] ?? '', '2일간') || ($t['training_no'] ?? '') === 'EDU-2026-0118'): ?>
              ~ <?= date('Y-m-d', strtotime($t['training_date'] . ' +1 day')) ?>
            <?php endif; ?>
          </strong>
          <span class="text-slate-500 block">
            <?= htmlspecialchars($t['start_time']) ?> ~ <?= htmlspecialchars($t['end_time']) ?>
            <?php if (str_contains($t['subject'] ?? '', '2일간') || ($t['training_no'] ?? '') === 'EDU-2026-0118'): ?>
              <span class="text-emerald-700 font-bold">(2일간 총 <?= (int)$t['planned_hours'] ?>h)</span>
            <?php endif; ?>
          </span>
          <span class="text-[10px] text-slate-400 mt-0.5 block">
            <?= (!empty($t['is_custom_schedule'])) ? '지정: ' . htmlspecialchars($t['designated_by'] ?? '김남균') : '기본 운영규칙 일정' ?>
          </span>
        </div>
        <div>
          <span class="text-slate-400 block font-semibold">교육 구분</span>
          <strong class="text-blue-700 text-sm"><?= $typeLabels[$t['training_type']] ?? $t['training_type'] ?></strong>
          <?php if (!empty($t['sub_type'])): ?>
            <span class="text-[10px] text-amber-700 block font-semibold">[<?= htmlspecialchars($otherTypesMap[$t['sub_type']] ?? $t['sub_type']) ?>]</span>
          <?php endif; ?>
        </div>
        <div>
          <span class="text-slate-400 block font-semibold">계획시간</span>
          <strong class="text-slate-900 text-sm"><?= $t['planned_hours'] ?> 시간 (HR)</strong>
          <?php if ($t['training_type'] === 'SPECIAL'): ?>
            <span class="text-[10px] text-purple-700 block font-semibold"><?= $t['session_round'] ?>회차 (총 <?= $t['total_rounds'] ?>회/8h)</span>
          <?php endif; ?>
        </div>
        <div>
          <span class="text-slate-400 block font-semibold">강사 (직위)</span>
          <strong class="text-slate-900 text-sm"><?= htmlspecialchars($t['instructor_name']) ?> (<?= htmlspecialchars($t['instructor_position'] ?: '과장') ?>)</strong>
          <span class="text-slate-500 block text-[10px]">장소: <?= htmlspecialchars($t['location'] ?: '현장 사무실') ?></span>
        </div>
      </div>

      <!-- Plan Source Badge if available -->
      <?php if (!empty($t['planned_content_source'])): ?>
        <div class="text-[11px] text-indigo-700 bg-indigo-50 border border-indigo-200 p-2.5 rounded-xl flex items-center justify-between">
          <span class="flex items-center space-x-1.5">
            <i data-lucide="book-open" class="w-4 h-4 text-indigo-600"></i>
            <span><strong>계획서 기본값 출처:</strong> <?= htmlspecialchars($t['planned_content_source']) ?></span>
          </span>
          <?php if (!empty($t['is_estimated'])): ?>
            <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded font-bold text-[10px]">불명확 연결 (추정)</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- MSDS Banner if linked (Item 8) -->
      <?php if ($t['training_type'] === 'MSDS' && $t['msds_material_name']): ?>
        <div class="bg-purple-50 p-3 rounded-xl border border-purple-200 text-xs text-purple-900 flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <i data-lucide="flask-conical" class="w-4 h-4 text-purple-600"></i>
            <span><strong>8번 MSDS 대상물질:</strong> <?= htmlspecialchars($t['msds_material_name']) ?></span>
          </div>
          <div class="font-mono text-purple-700">
            <span>자료ID: <?= htmlspecialchars((string)$t['msds_material_id']) ?></span> &bull; 
            <span>개정일자: <?= htmlspecialchars((string)$t['msds_revision_date']) ?></span> &bull; 
            <span>차수: <?= htmlspecialchars((string)$t['msds_revision_count']) ?>차</span>
          </div>
        </div>
      <?php endif; ?>

      <!-- Content (2-Column Grid or Full) -->
      <div class="space-y-2 text-xs">
        <span class="text-slate-400 font-bold uppercase tracking-wider block">◈ 교육 내용 요약 ◈</span>
        <?php if (!empty($t['content_summary_left']) || !empty($t['content_summary_right'])): ?>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 bg-indigo-50/50 rounded-xl border border-indigo-100 leading-relaxed font-mono text-slate-800">
              <strong class="text-indigo-900 block border-b border-indigo-200/60 pb-1 mb-2">[좌측 서식 칸: 법정 공통사항]</strong>
              <?= render_csm_view_content_summary($t['content_summary_left'] ?? '') ?>
            </div>
            <div class="p-4 bg-emerald-50/50 rounded-xl border border-emerald-100 leading-relaxed font-mono text-slate-800">
              <strong class="text-emerald-900 block border-b border-emerald-200/60 pb-1 mb-2">[우측 서식 칸: 당월 중점실무]</strong>
              <?= render_csm_view_content_summary($t['content_summary_right'] ?? '') ?>
            </div>
          </div>
        <?php else: ?>
          <div class="p-4 bg-slate-50 rounded-xl border border-slate-200 leading-relaxed font-mono text-slate-800">
            <?= render_csm_view_content_summary($t['content_summary'] ?? '') ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Opinion -->
      <?php if (!empty($t['evaluation_opinion'])): ?>
        <div class="space-y-2 text-xs">
          <span class="text-slate-400 font-bold uppercase tracking-wider block">◈ 교육평가 및 의견 ◈</span>
          <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 text-slate-800">
            <?= htmlspecialchars($t['evaluation_opinion']) ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Attendees Grid -->
      <div class="space-y-3 pt-2">
        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
          <div>
            <span class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center space-x-1.5">
              <i data-lucide="users" class="w-4 h-4 text-emerald-600"></i>
              <span>참석자 명단 (총 <?= count($attendees) ?>명)</span>
            </span>
            <span class="text-[11px] text-slate-400">※ 출력 서식의 서명란은 수기 서명 원칙에 따라 공란으로 유지됩니다.</span>
          </div>
          <span class="text-xs font-mono text-slate-500">
            실시: <strong class="text-blue-600"><?= count(array_filter($attendees, fn($a) => $a['is_completed'] == 1)) ?></strong>명 / 
            불참: <strong class="text-rose-600"><?= count(array_filter($attendees, fn($a) => $a['is_completed'] == 0)) ?></strong>명
          </span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-xs">
          <?php foreach ($attendees as $att): ?>
            <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
              <div>
                <strong class="text-slate-900"><?= htmlspecialchars($att['name_snapshot']) ?></strong>
                <span class="text-[10px] text-slate-400 block"><?= htmlspecialchars($att['team_snapshot']) ?> &bull; <?= htmlspecialchars($att['position_snapshot']) ?></span>
              </div>
              <div class="text-right">
                <?php if ($att['is_completed'] == 1): ?>
                  <span class="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-0.5 rounded">이수완료 (<?= $att['completed_hours'] ?>h)</span>
                <?php else: ?>
                  <span class="bg-rose-100 text-rose-800 text-[10px] font-bold px-2 py-0.5 rounded">불참</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Evidence Photos Section (Page 2 Preview) -->
      <div class="space-y-3 pt-2">
        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
          <span class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center space-x-1.5">
            <i data-lucide="camera" class="w-4 h-4 text-purple-600"></i>
            <span>현장 증빙사진 대지 (2페이지 출력물 미리보기, <?= count($photos) ?>건 등록)</span>
          </span>
          <span class="text-[11px] text-slate-500">기본 2장 배치 &bull; 원본 비율 보존</span>
        </div>

        <?php if (!empty($photos)): ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($photos as $idx => $p): ?>
              <div class="rounded-xl border border-slate-200 overflow-hidden bg-slate-100 flex flex-col shadow-sm">
                <div class="bg-slate-200/60 px-3 py-1.5 text-xs font-bold text-slate-700 flex items-center justify-between">
                  <span>[사진 <?= $idx + 1 ?>] <?= htmlspecialchars($p['caption'] ?: '현장 사진') ?></span>
                  <span class="text-[10px] bg-white px-1.5 py-0.5 rounded border border-slate-300">순서 <?= $p['display_order'] ?></span>
                </div>
                <div class="p-2 flex items-center justify-center bg-white h-48 cursor-pointer hover:opacity-90 transition" onclick="openDocViewer('secure_file.php?type=photo&file=<?= urlencode($p['stored_name']) ?>', '<?= htmlspecialchars($p['caption'] ?: '현장 사진') ?>', '<?= htmlspecialchars($p['stored_name']) ?>')" title="클릭하여 모달로 크게 보기">
                  <img src="secure_file.php?type=photo&file=<?= urlencode($p['stored_name']) ?>" class="max-h-full max-w-full object-contain">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="p-8 border-2 border-dashed border-slate-200 rounded-xl text-center text-xs text-slate-400">
            <i data-lucide="image" class="w-8 h-8 mx-auto mb-2 text-slate-300"></i>
            <p class="font-bold text-slate-500">[사진 미등록 상태]</p>
            <p class="text-[11px] mt-0.5">출력 시 사진대지의 빈 틀이 유지됩니다. (수정하기를 통해 사진을 첨부할 수 있습니다)</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- Revision Audit Trail -->
      <?php if (!empty($revisions)): ?>
        <div class="space-y-3 pt-2">
          <span class="text-xs font-bold text-amber-900 uppercase tracking-wider flex items-center space-x-1.5 border-b border-amber-100 pb-2">
            <i data-lucide="history" class="w-4 h-4 text-amber-600"></i>
            <span>변경 및 개정 이력 (Revision History)</span>
          </span>
          <div class="space-y-2">
            <?php foreach ($revisions as $rev): ?>
              <div class="p-3 bg-amber-50/60 rounded-xl border border-amber-200 text-xs space-y-1">
                <div class="flex justify-between items-center">
                  <span class="font-bold text-amber-900">Revision <?= $rev['revision_num'] ?></span>
                  <span class="text-[11px] text-amber-700 font-mono"><?= $rev['changed_at'] ?> by <?= htmlspecialchars($rev['changed_by']) ?></span>
                </div>
                <p class="text-slate-700 text-[11px]"><strong>변경 사유:</strong> <?= htmlspecialchars($rev['change_reason']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

  </main>

  <script>
    async function uploadDirectDoc(id, docType, input) {
      if (!input.files || !input.files[0]) return;
      const file = input.files[0];

      const fd = new FormData();
      fd.append('action', 'upload_scan');
      fd.append('id', id);
      fd.append('doc_type', docType);
      fd.append('scan_file', file);

      try {
        const res = await fetch('api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          alert(data.message || '파일이 성공적으로 등록되었습니다.');
          location.reload();
        } else {
          alert('업로드 실패: ' + (data.error || '오류 발생'));
        }
      } catch (err) {
        alert('업로드 통신 오류: ' + err.message);
      }
    }

    async function finalizeTraining(id, hasScan) {
      if (!hasScan) {
        alert('확정완료를 하려면 자필 서명된 [교육일지 서명 스캔본] 또는 [공식 교육이수증 스캔본] 중 하나가 등록되어 있어야 합니다.\n\n먼저 증빙 스캔본을 업로드해 주세요.');
        document.getElementById('direct-scan-file')?.click();
        return;
      }
      if (!confirm('이 교육일지를 [확정(FINALIZED)]하시겠습니까?\n\n확정 후 수정 시에는 변경 사유 및 Revision 이력이 필수적으로 기록됩니다.')) return;
      const fd = new FormData();
      fd.append('action', 'finalize');
      fd.append('id', id);

      const res = await fetch('api.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        alert('교육일지가 성공적으로 확정완료되었습니다.');
        location.reload();
      } else {
        alert('확정 실패: ' + data.error);
      }
    }
    lucide.createIcons();
  </script>

  <?php require_once __DIR__ . '/doc_viewer_modal.php'; ?>
</body>
</html>
