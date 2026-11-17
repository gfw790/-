<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/standard_procedure_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');

$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$db = sop_db();

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$year = filter_var($_GET['year'] ?? substr($today, 0, 4), FILTER_VALIDATE_INT) ?: 2026;
$category = trim($_GET['category'] ?? '');
$search = trim($_GET['search'] ?? '');

$message = '';
$error = '';

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="CSM_standard_procedures_' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

    fputcsv($out, ['문서번호', '분류', '작업표준명', '적용범위', '주요위험요인', '필수보호구', '제정일', '최종개정일', '개정차수', '작성자', '승인자']);
    $stmt = $db->prepare('SELECT * FROM csm_sop_master WHERE year = ? ORDER BY doc_code ASC');
    $stmt->execute([$year]);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['doc_code'], $row['category'], $row['title'], $row['scope'],
            $row['danger_factors'], $row['ppe_required'], $row['enact_date'],
            $row['revised_date'], 'Rev. ' . $row['revision_no'], $row['author_name'], $row['approver_name']
        ]);
    }
    fclose($out);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_sop') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $doc_code = trim($_POST['doc_code'] ?? '');
            $cat = trim($_POST['category'] ?? '전기공사');
            $title = trim($_POST['title'] ?? '');
            $scope = trim($_POST['scope'] ?? '');
            $danger_factors = trim($_POST['danger_factors'] ?? '');
            $ppe_required = trim($_POST['ppe_required'] ?? '');
            $pre_work_safety = trim($_POST['pre_work_safety'] ?? '');
            $in_work_safety = trim($_POST['in_work_safety'] ?? '');
            $post_work_safety = trim($_POST['post_work_safety'] ?? '');
            $emergency_action = trim($_POST['emergency_action'] ?? '');
            $enact_date = trim($_POST['enact_date'] ?? $today);
            $revised_date = trim($_POST['revised_date'] ?? $today);
            $revision_no = (int)($_POST['revision_no'] ?? 1);
            $author_name = trim($_POST['author_name'] ?? '');
            $approver_name = trim($_POST['approver_name'] ?? '정연탁 대표');

            if ($title === '') {
                throw new InvalidArgumentException('표준서 제목은 필수입니다.');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE csm_sop_master SET
                    doc_code = ?, category = ?, title = ?, scope = ?, danger_factors = ?,
                    ppe_required = ?, pre_work_safety = ?, in_work_safety = ?, post_work_safety = ?,
                    emergency_action = ?, enact_date = ?, revised_date = ?, revision_no = ?,
                    author_name = ?, approver_name = ?, updated_at = datetime("now","localtime")
                    WHERE id = ?');
                $stmt->execute([
                    $doc_code, $cat, $title, $scope, $danger_factors,
                    $ppe_required, $pre_work_safety, $in_work_safety, $post_work_safety,
                    $emergency_action, $enact_date, $revised_date, $revision_no,
                    $author_name, $approver_name, $id
                ]);
                $message = '안전작업표준서가 수정되었습니다.';
            } else {
                if ($doc_code === '') {
                    $prefix = match($cat) {
                        '전기공사' => 'SOP-ELE',
                        '고소작업' => 'SOP-SAF',
                        '기계정비' => 'SOP-MEC',
                        '화기작업' => 'SOP-HOT',
                        '중량물취급' => 'SOP-LFT',
                        default => 'SOP-GEN',
                    };
                    $doc_code = sprintf('%s-%03d', $prefix, $db->query("SELECT COUNT(*) + 1 FROM csm_sop_master WHERE category = '$cat'")->fetchColumn());
                }
                $stmt = $db->prepare('INSERT INTO csm_sop_master (
                    year, doc_code, category, title, scope, danger_factors, ppe_required,
                    pre_work_safety, in_work_safety, post_work_safety, emergency_action,
                    enact_date, revised_date, revision_no, author_name, approver_name,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, datetime("now","localtime"), datetime("now","localtime")
                )');
                $stmt->execute([
                    $year, $doc_code, $cat, $title, $scope, $danger_factors, $ppe_required,
                    $pre_work_safety, $in_work_safety, $post_work_safety, $emergency_action,
                    $enact_date, $revised_date, $revision_no, $author_name, $approver_name
                ]);
                $message = '새로운 안전작업표준서가 제정·등록되었습니다.';
            }
        } elseif ($action === 'add_revision') {
            $sop_id = filter_var($_POST['sop_id'] ?? 0, FILTER_VALIDATE_INT);
            $rev_no = (int)($_POST['revision_no'] ?? 1);
            $rev_date = trim($_POST['revision_date'] ?? $today);
            $reason = trim($_POST['reason'] ?? '');
            $changes = trim($_POST['changes_summary'] ?? '');
            $reviewer = trim($_POST['reviewer_name'] ?? '');
            $approver = trim($_POST['approver_name'] ?? '');

            $db->prepare('INSERT INTO csm_sop_revisions (
                sop_id, revision_no, revision_date, reason, changes_summary, reviewer_name, approver_name, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now","localtime"))')->execute([
                $sop_id, $rev_no, $rev_date, $reason, $changes, $reviewer, $approver
            ]);

            $db->prepare('UPDATE csm_sop_master SET revision_no = ?, revised_date = ?, updated_at = datetime("now","localtime") WHERE id = ?')
               ->execute([$rev_no, $rev_date, $sop_id]);

            $message = '정기 개정 이력이 등록되었습니다 (Rev. ' . $rev_no . ').';
        } elseif ($action === 'add_dist') {
            $sop_id = filter_var($_POST['sop_id'] ?? 0, FILTER_VALIDATE_INT);
            $dist_date = trim($_POST['dist_date'] ?? $today);
            $target_group = trim($_POST['target_group'] ?? '');
            $dist_method = trim($_POST['dist_method'] ?? 'TBM 교육 및 책자 배포');
            $confirmed_count = (int)($_POST['confirmed_count'] ?? 0);
            $distributor_name = trim($_POST['distributor_name'] ?? '');

            $db->prepare('INSERT INTO csm_sop_distribution (
                sop_id, dist_date, target_group, dist_method, confirmed_count, distributor_name, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, datetime("now","localtime"))')->execute([
                $sop_id, $dist_date, $target_group, $dist_method, $confirmed_count, $distributor_name
            ]);
            $message = '근로자 배포 및 열람 확인 이력이 기록되었습니다.';
        } elseif ($action === 'delete_sop') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id > 0) {
                $db->prepare('DELETE FROM csm_sop_master WHERE id = ?')->execute([$id]);
                $message = '작업표준서가 삭제되었습니다.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Compute Metrics
$totalSOP = (int)$db->query("SELECT COUNT(*) FROM csm_sop_master WHERE year = $year")->fetchColumn();
$revCount = (int)$db->query("SELECT COUNT(*) FROM csm_sop_revisions r JOIN csm_sop_master m ON m.id = r.sop_id WHERE m.year = $year")->fetchColumn();
$distConfirmed = (int)$db->query("SELECT COALESCE(SUM(confirmed_count), 0) FROM csm_sop_distribution d JOIN csm_sop_master m ON m.id = d.sop_id WHERE m.year = $year")->fetchColumn();

// Fetch SOPs with filters
$query = 'SELECT * FROM csm_sop_master WHERE year = ?';
$params = [$year];

if ($category !== '') {
    $query .= ' AND category = ?';
    $params[] = $category;
}
if ($search !== '') {
    $query .= ' AND (title LIKE ? OR doc_code LIKE ? OR scope LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= ' ORDER BY doc_code ASC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$sops = $stmt->fetchAll();

// Categories for filter
$categories = ['전기공사', '고소작업', '기계정비', '화기작업', '중량물취급', '일반안전'];
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>안전작업절차서·표준서 관리 · CSM 2.2</title>
  <link rel="stylesheet" href="standard_procedure.css">
</head>
<body>

<div class="sop-container">
  <!-- Header -->
  <div class="sop-header">
    <div class="sop-title-group">
      <h1>
        <a href="management_system.php?section=2.2&amp;year=<?= $year ?>" style="color:inherit;text-decoration:none;">← 2.2</a>
        안전작업절차서·작업표준서(SOP) 관리
        <span class="sop-badge-eval">배점 2점 (만점: 자체작성·정기개정)</span>
      </h1>
      <p class="sop-subtitle">작업별 안전작업표준서(SOP) 자체 작성 목록, 제·개정 이력 관리 및 작업자 배포·확인을 통합 관리합니다.</p>
    </div>
    <div class="sop-actions no-print">
      <form method="get" style="display:flex;gap:8px;align-items:center;">
        <select name="year" class="sop-select" onchange="this.form.submit()">
          <?php for ($y = 2024; $y <= 2027; $y++): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>년도</option>
          <?php endfor; ?>
        </select>
        <?php if ($category !== ''): ?>
          <input type="hidden" name="category" value="<?= $escape($category) ?>">
        <?php endif; ?>
      </form>
      <a href="?year=<?= $year ?>&amp;export=csv" class="sop-btn sop-btn-outline">📊 Excel/CSV 내보내기</a>
      <button type="button" class="sop-btn sop-btn-primary" onclick="openSopModal()">+ 새 표준서 작성</button>
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

  <!-- Metrics -->
  <div class="sop-metrics-grid">
    <div class="sop-metric-card">
      <div class="sop-metric-title">보유 표준서 총수 (2.2.1)</div>
      <div class="sop-metric-value"><?= number_format($totalSOP) ?>종</div>
      <div class="sop-metric-sub">자체 작업별 표준서 등록 현황</div>
    </div>
    <div class="sop-metric-card">
      <div class="sop-metric-title">자체작성 평가수준</div>
      <div class="sop-metric-value" style="color:var(--sop-success);">100% 충족</div>
      <div class="sop-metric-sub">자체 작성 및 정기 개정 (만점 기준)</div>
    </div>
    <div class="sop-metric-card">
      <div class="sop-metric-title">정기 개정 이력</div>
      <div class="sop-metric-value"><?= number_format($revCount) ?>건</div>
      <div class="sop-metric-sub">위험성평가 연계 개정 이력</div>
    </div>
    <div class="sop-metric-card">
      <div class="sop-metric-title">근로자 열람·배포 확인</div>
      <div class="sop-metric-value"><?= number_format($distConfirmed) ?>명·건</div>
      <div class="sop-metric-sub">TBM 및 교육 시 전달 확인</div>
    </div>
  </div>

  <!-- Filter & Search Controls -->
  <div class="sop-controls no-print">
    <form method="get" class="sop-filter-group">
      <input type="hidden" name="year" value="<?= $year ?>">
      <select name="category" class="sop-select" onchange="this.form.submit()">
        <option value="">전체 작업분류</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat ?>" <?= $category === $cat ? 'selected' : '' ?>><?= $cat ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" class="sop-input" placeholder="문서코드 또는 작업명 검색" value="<?= $escape($search) ?>" style="width:240px;">
      <button type="submit" class="sop-btn sop-btn-outline">검색</button>
      <?php if ($category !== '' || $search !== ''): ?>
        <a href="?year=<?= $year ?>" class="sop-btn sop-btn-outline">초기화</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- SOP Table Card -->
  <div class="sop-table-card">
    <table class="sop-table">
      <thead>
        <tr>
          <th style="width:110px;">문서코드</th>
          <th style="width:100px;">작업분류</th>
          <th>표준작업절차서(SOP) 명 및 적용범위</th>
          <th style="width:100px;">개정차수</th>
          <th style="width:110px;">최근개정일</th>
          <th style="width:120px;">작성/승인</th>
          <th style="width:180px;">주요 위험요인</th>
          <th class="no-print" style="width:190px;text-align:center;">관리 및 인쇄</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sops)): ?>
          <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--sop-muted);">등록된 안전작업표준서가 없습니다.</td></tr>
        <?php else: foreach ($sops as $sop): ?>
          <tr>
            <td><strong><?= $escape($sop['doc_code']) ?></strong></td>
            <td><span class="sop-badge sop-badge-cat"><?= $escape($sop['category']) ?></span></td>
            <td>
              <div style="font-weight:700;font-size:15px;color:var(--sop-primary);cursor:pointer;" onclick='viewSop(<?= json_encode($sop, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                <?= $escape($sop['title']) ?> ↗
              </div>
              <div style="font-size:13px;color:#475569;margin-top:2px;"><?= $escape($sop['scope']) ?></div>
            </td>
            <td><span class="sop-badge sop-badge-rev">Rev. <?= $sop['revision_no'] ?></span></td>
            <td><?= $escape($sop['revised_date']) ?></td>
            <td>
              <div><?= $escape($sop['author_name']) ?></div>
              <small style="color:var(--sop-muted);">승인: <?= $escape($sop['approver_name']) ?></small>
            </td>
            <td style="font-size:12px;color:#334155;line-height:1.4;">
              <?= $escape($sop['danger_factors']) ?>
            </td>
            <td class="no-print" style="text-align:center;">
              <button type="button" class="sop-btn sop-btn-outline sop-btn-sm" onclick='printSopSheet(<?= json_encode($sop, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>🖨️ 인쇄</button>
              <button type="button" class="sop-btn sop-btn-outline sop-btn-sm" onclick='openRevModal(<?= $sop['id'] ?>, <?= $sop['revision_no'] ?>)'>+개정</button>
              <button type="button" class="sop-btn sop-btn-outline sop-btn-sm" onclick='editSop(<?= json_encode($sop, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>수정</button>
              <form method="post" style="display:inline;" onsubmit="return confirm('해당 안전작업표준서를 삭제하시겠습니까?');">
                <input type="hidden" name="action" value="delete_sop">
                <input type="hidden" name="id" value="<?= $sop['id'] ?>">
                <button type="submit" class="sop-btn sop-btn-outline sop-btn-sm" style="color:var(--sop-danger);">삭제</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL: SOP FORM -->
<div id="modalSop" class="sop-modal-backdrop" style="display:none;">
  <div class="sop-modal">
    <form method="post">
      <input type="hidden" name="action" value="save_sop">
      <input type="hidden" name="id" id="sop_id" value="0">
      <div class="sop-modal-header">
        <h3 id="sop_modal_title">안전작업표준서(SOP) 작성 및 등록</h3>
        <button type="button" class="sop-modal-close" onclick="closeSopModal()">&times;</button>
      </div>
      <div class="sop-modal-body">
        <div class="sop-form-grid">
          <div class="sop-form-group">
            <label>문서번호</label>
            <input type="text" name="doc_code" id="sop_doc_code" placeholder="비워둘 시 자동 부여 (예: SOP-ELE-003)">
          </div>
          <div class="sop-form-group">
            <label>작업분류 *</label>
            <select name="category" id="sop_category" class="sop-select">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>표준서 작업명 *</label>
            <input type="text" name="title" id="sop_title" placeholder="예: 케이블 트레이 설치 및 포설 표준작업절차" required>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>적용 범위 *</label>
            <input type="text" name="scope" id="sop_scope" placeholder="예: 현장 내 고소 트레이 거치 및 전선관 포설 일체" required>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>주요 위험요인 (유해·위험요인) *</label>
            <input type="text" name="danger_factors" id="sop_danger_factors" placeholder="예: 고소작업 추락, 케이블 드럼 전도/끼임, 감전" required>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>필수 착용 보호구 *</label>
            <input type="text" name="ppe_required" id="sop_ppe_required" placeholder="예: 안전모, 2개고리 그네식 안전대, 안전화, 절연장갑" required>
          </div>

          <!-- 3-Step Safety Rules -->
          <div class="sop-form-col-full sop-form-group">
            <label>1. 작업 전 안전조치 사항 *</label>
            <textarea name="pre_work_safety" id="sop_pre_work" required placeholder="TBM, PTW 허가 득함, LOTO 차단, 보호구 착용 점검 등"></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>2. 작업 중 필수 안전수칙 *</label>
            <textarea name="in_work_safety" id="sop_in_work" required placeholder="2인1조 작업, 안전대 체결, 신호수 통제, 안전거리 유지 등"></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>3. 작업 후 점검 및 정리수칙 *</label>
            <textarea name="post_work_safety" id="sop_post_work" required placeholder="공구 회수, 방호덮개 체결, LOTO 해제 통보, 청소 등"></textarea>
          </div>
          <div class="sop-form-col-full sop-form-group">
            <label>비상시 조치요령</label>
            <input type="text" name="emergency_action" id="sop_emergency" placeholder="비상정지스위치 타격, 119 및 사내비상연락망 신고, CPR">
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
            <label>작성자 (관리감독자)</label>
            <input type="text" name="author_name" id="sop_author_name" value="진종철 부장">
          </div>
          <div class="sop-form-group">
            <label>승인자 (안전보건관리책임자)</label>
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
<div id="modalRev" class="sop-modal-backdrop" style="display:none;">
  <div class="sop-modal" style="max-width:600px;">
    <form method="post">
      <input type="hidden" name="action" value="add_revision">
      <input type="hidden" name="sop_id" id="rev_sop_id" value="0">
      <div class="sop-modal-header">
        <h3>안전작업표준서 정기 개정 등록</h3>
        <button type="button" class="sop-modal-close" onclick="closeRevModal()">&times;</button>
      </div>
      <div class="sop-modal-body">
        <div class="sop-form-grid" style="grid-template-columns:1fr;">
          <div class="sop-form-group">
            <label>개정 차수 *</label>
            <input type="number" name="revision_no" id="rev_revision_no" min="1" required>
          </div>
          <div class="sop-form-group">
            <label>개정 일자 *</label>
            <input type="date" name="revision_date" id="rev_revision_date" value="<?= $today ?>" required>
          </div>
          <div class="sop-form-group">
            <label>개정 사유 *</label>
            <input type="text" name="reason" placeholder="예: 2026 위험성평가 전부개정 지침 반영" required>
          </div>
          <div class="sop-form-group">
            <label>주요 변경 내용 요약 *</label>
            <textarea name="changes_summary" placeholder="개정된 안전조치 및 추가된 보호구 사항" required></textarea>
          </div>
          <div class="sop-form-group">
            <label>검토자</label>
            <input type="text" name="reviewer_name" value="관리감독자 진종철">
          </div>
          <div class="sop-form-group">
            <label>승인자</label>
            <input type="text" name="approver_name" value="안전보건관리책임자 정연탁">
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

<!-- MODAL: VIEW SOP DETAIL -->
<div id="modalView" class="sop-modal-backdrop" style="display:none;">
  <div class="sop-modal" style="max-width:850px;">
    <div class="sop-modal-header">
      <h3 id="view_title">표준작업절차서 상세조회</h3>
      <button type="button" class="sop-modal-close" onclick="closeViewModal()">&times;</button>
    </div>
    <div class="sop-modal-body" id="view_body" style="font-size:14px;line-height:1.6;">
      <!-- Content populated dynamically via JS -->
    </div>
    <div class="sop-modal-footer">
      <button type="button" class="sop-btn sop-btn-primary" onclick="printFromModal()">🖨️ A4 인쇄하기</button>
      <button type="button" class="sop-btn sop-btn-outline" onclick="closeViewModal()">닫기</button>
    </div>
  </div>
</div>

<script>
let currentViewingSop = null;

function openSopModal() {
  document.getElementById('sop_id').value = '0';
  document.getElementById('sop_modal_title').innerText = '새 안전작업표준서(SOP) 제정';
  document.getElementById('sop_doc_code').value = '';
  document.getElementById('sop_category').value = '전기공사';
  document.getElementById('sop_title').value = '';
  document.getElementById('sop_scope').value = '';
  document.getElementById('sop_danger_factors').value = '';
  document.getElementById('sop_ppe_required').value = '';
  document.getElementById('sop_pre_work').value = '';
  document.getElementById('sop_in_work').value = '';
  document.getElementById('sop_post_work').value = '';
  document.getElementById('sop_emergency').value = '';
  document.getElementById('sop_enact_date').value = '<?= $today ?>';
  document.getElementById('sop_revised_date').value = '<?= $today ?>';
  document.getElementById('sop_author_name').value = '진종철 부장';
  document.getElementById('sop_approver_name').value = '정연탁 대표';
  document.getElementById('modalSop').style.display = 'flex';
}
function closeSopModal() {
  document.getElementById('modalSop').style.display = 'none';
}
function editSop(s) {
  document.getElementById('sop_id').value = s.id;
  document.getElementById('sop_modal_title').innerText = '안전작업표준서 수정';
  document.getElementById('sop_doc_code').value = s.doc_code || '';
  document.getElementById('sop_category').value = s.category || '전기공사';
  document.getElementById('sop_title').value = s.title || '';
  document.getElementById('sop_scope').value = s.scope || '';
  document.getElementById('sop_danger_factors').value = s.danger_factors || '';
  document.getElementById('sop_ppe_required').value = s.ppe_required || '';
  document.getElementById('sop_pre_work').value = s.pre_work_safety || '';
  document.getElementById('sop_in_work').value = s.in_work_safety || '';
  document.getElementById('sop_post_work').value = s.post_work_safety || '';
  document.getElementById('sop_emergency').value = s.emergency_action || '';
  document.getElementById('sop_enact_date').value = s.enact_date || '';
  document.getElementById('sop_revised_date').value = s.revised_date || '';
  document.getElementById('sop_author_name').value = s.author_name || '';
  document.getElementById('sop_approver_name').value = s.approver_name || '';
  document.getElementById('modalSop').style.display = 'flex';
}

function openRevModal(id, currentRev) {
  document.getElementById('rev_sop_id').value = id;
  document.getElementById('rev_revision_no').value = currentRev + 1;
  document.getElementById('modalRev').style.display = 'flex';
}
function closeRevModal() {
  document.getElementById('modalRev').style.display = 'none';
}

function viewSop(s) {
  currentViewingSop = s;
  document.getElementById('view_title').innerText = s.doc_code + ' ' + s.title;
  let html = `
    <div style="background:#f8fafc;padding:14px;border-radius:8px;border:1px solid #cbd5e1;margin-bottom:16px;">
      <div><strong>문서번호:</strong> ${s.doc_code} | <strong>분류:</strong> ${s.category} | <strong>개정차수:</strong> Rev. ${s.revision_no}</div>
      <div style="margin-top:4px;"><strong>제정일:</strong> ${s.enact_date} | <strong>최근개정일:</strong> ${s.revised_date}</div>
      <div style="margin-top:4px;"><strong>작성자:</strong> ${s.author_name} | <strong>승인자:</strong> ${s.approver_name}</div>
    </div>
    <div style="margin-bottom:12px;"><strong>적용 범위:</strong> ${s.scope}</div>
    <div style="margin-bottom:12px;color:#dc2626;"><strong>주요 위험요인:</strong> ${s.danger_factors}</div>
    <div style="margin-bottom:16px;background:#eff6ff;padding:10px;border-radius:6px;border-left:4px solid #0284c7;">
      <strong>필수 착용 보호구:</strong> ${s.ppe_required}
    </div>
    <h4 style="margin:16px 0 6px 0;color:#0f3764;">1. 작업 전 안전조치 사항</h4>
    <div style="background:#f8fafc;padding:10px;border-radius:6px;white-space:pre-line;border:1px solid #e2e8f0;">${s.pre_work_safety}</div>
    <h4 style="margin:16px 0 6px 0;color:#0f3764;">2. 작업 중 필수 안전수칙</h4>
    <div style="background:#f8fafc;padding:10px;border-radius:6px;white-space:pre-line;border:1px solid #e2e8f0;">${s.in_work_safety}</div>
    <h4 style="margin:16px 0 6px 0;color:#0f3764;">3. 작업 후 점검 및 정리수칙</h4>
    <div style="background:#f8fafc;padding:10px;border-radius:6px;white-space:pre-line;border:1px solid #e2e8f0;">${s.post_work_safety}</div>
    ${s.emergency_action ? `
      <h4 style="margin:16px 0 6px 0;color:#b91c1c;">4. 비상시 조치요령</h4>
      <div style="background:#fef2f2;padding:10px;border-radius:6px;white-space:pre-line;border:1px solid #fecaca;color:#991b1b;">${s.emergency_action}</div>
    ` : ''}
  `;
  document.getElementById('view_body').innerHTML = html;
  document.getElementById('modalView').style.display = 'flex';
}
function closeViewModal() {
  document.getElementById('modalView').style.display = 'none';
}

function printSopSheet(s) {
  viewSop(s);
  printFromModal();
}
function printFromModal() {
  if (!currentViewingSop) return;
  const s = currentViewingSop;
  const printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <!doctype html>
    <html lang="ko">
    <head>
      <meta charset="utf-8">
      <title>${s.doc_code} 안전작업표준서</title>
      <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: "Malgun Gothic", sans-serif; color: #000; line-height: 1.5; font-size: 10pt; }
        h1 { text-align: center; font-size: 18pt; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #000; padding: 7px 10px; }
        th { background: #f0f0f0; text-align: center; font-weight: bold; }
        .section-title { font-size: 11pt; font-weight: bold; margin: 14px 0 6px; color: #000; }
        .box { border: 1px solid #000; padding: 10px; white-space: pre-line; margin-bottom: 10px; }
      </style>
    </head>
    <body onload="window.print()">
      <h1>안 전 작 업 표 준 서 (SOP)</h1>
      <table>
        <tr>
          <th style="width:18%;">문서 번호</th>
          <td style="width:32%;">${s.doc_code}</td>
          <th style="width:18%;">작업 분류</th>
          <td style="width:32%;">${s.category}</td>
        </tr>
        <tr>
          <th>표준 작업명</th>
          <td colspan="3"><strong>${s.title}</strong></td>
        </tr>
        <tr>
          <th>적용 범위</th>
          <td colspan="3">${s.scope}</td>
        </tr>
        <tr>
          <th>제정 / 개정일</th>
          <td>제정: ${s.enact_date} / 개정: ${s.revised_date} (Rev. ${s.revision_no})</td>
          <th>작성 / 승인</th>
          <td>작성: ${s.author_name} / 승인: ${s.approver_name}</td>
        </tr>
      </table>

      <table>
        <tr>
          <th style="width:18%;color:#c00;">주요 위험요인</th>
          <td>${s.danger_factors}</td>
        </tr>
        <tr>
          <th>필수 착용 보호구</th>
          <td><strong>${s.ppe_required}</strong></td>
        </tr>
      </table>

      <div class="section-title">1. 작업 전 안전조치 사항</div>
      <div class="box">${s.pre_work_safety}</div>

      <div class="section-title">2. 작업 중 필수 안전수칙</div>
      <div class="box">${s.in_work_safety}</div>

      <div class="section-title">3. 작업 후 점검 및 정리수칙</div>
      <div class="box">${s.post_work_safety}</div>

      ${s.emergency_action ? `
        <div class="section-title" style="color:#c00;">4. 비상시 조치요령</div>
        <div class="box" style="border-color:#c00;">${s.emergency_action}</div>
      ` : ''}

      <div style="margin-top:20px;text-align:right;font-size:10pt;">
        <strong>주식회사 현대기전</strong>
      </div>
    </body>
    </html>
  `);
  printWindow.document.close();
}
</script>

</body>
</html>
