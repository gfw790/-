<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/hazard_participant_print_access.php';

function print_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$user = auth_current_user();
if ($user === null) { header('Location: task_select.php'); exit; }
$ids = [];
foreach (explode(',', (string)($_POST['report_ids'] ?? '')) as $part) {
    $part = trim($part);
    if ($part !== '' && ctype_digit($part) && (int)$part > 0) $ids[(int)$part] = (int)$part;
}
if (count($ids) > 500) { http_response_code(400); exit('한 번에 선택할 수 있는 평가서는 500건까지입니다.'); }
$reports = [];
if ($ids) {
    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT wr.report_id, wr.user_login_id, wr.user_name, wr.team_name, wr.work_date, wr.work_title, wr.work_place,
        (SELECT COUNT(*) FROM work_report_detail wd WHERE wd.report_id=wr.report_id) AS leader_detail_count,
        COUNT(DISTINCT ws.user_login_id) AS participant_count
        FROM work_report wr
        JOIN work_report_worker_hazard_selection ws ON ws.report_id=wr.report_id
        WHERE wr.report_id IN ($placeholders) AND wr.work_date <= ?
        GROUP BY wr.report_id, wr.user_login_id, wr.user_name, wr.team_name, wr.work_date, wr.work_title, wr.work_place
        ORDER BY wr.work_date DESC, wr.report_id DESC");
    $stmt->execute([...array_values($ids), (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d')]);
    $reports = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        static fn(array $row): bool => (int)$row['participant_count'] > 0 && hazard_participant_print_can_view($user, $row)));

    $participantStmt = $db->prepare('SELECT user_login_id, MAX(user_name) AS user_name FROM work_report_worker_hazard_selection WHERE report_id=? GROUP BY user_login_id ORDER BY user_name');
    $hazardStmt = $db->prepare("SELECT i.hazard_name, i.accident_type, i.injury_result,
        i.current_control_text, i.additional_control_text,
        i.likelihood_before, i.severity_before, i.risk_score_before,
        i.likelihood_current, i.severity_current, i.risk_score_current,
        h.unit_title, h.unit_code, h.unit_type,
        COUNT(*) AS selected_count, COUNT(DISTINCT s.user_login_id) AS worker_count
        FROM work_report_worker_hazard_selection s
        JOIN work_report wr ON wr.report_id=s.report_id
        JOIN unit_ra_item i ON i.item_id=s.item_id
        JOIN unit_ra_header h ON h.unit_ra_id=s.unit_ra_id
        WHERE wr.work_title=? AND wr.work_date=? AND wr.work_place=?
        GROUP BY i.hazard_name, i.accident_type, i.injury_result,
          i.current_control_text, i.additional_control_text,
          i.likelihood_before, i.severity_before, i.risk_score_before,
          i.likelihood_current, i.severity_current, i.risk_score_current,
          h.unit_title, h.unit_code, h.unit_type
        ORDER BY selected_count DESC, worker_count DESC, i.hazard_name ASC");
    foreach ($reports as &$report) {
        $participantStmt->execute([(int)$report['report_id']]);
        $report['participants'] = $participantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hazardStmt->execute([$report['work_title'], $report['work_date'], $report['work_place']]);
        $report['hazards'] = ['work_related'=>[], 'other'=>[]];
        foreach ($hazardStmt->fetchAll(PDO::FETCH_ASSOC) as $hazard) {
            $section = in_array((string)$hazard['unit_type'], ['major_work','tool','env'], true) ? 'other' : 'work_related';
            if (count($report['hazards'][$section]) < 5) $report['hazards'][$section][] = $hazard;
        }
    }
    unset($report);
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>참여자 수시위험성평가 출력</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#e9eef4;color:#192b3d;font-family:"Malgun Gothic",sans-serif}.toolbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;background:#fff;border-bottom:1px solid #cbd7e5;box-shadow:0 2px 8px #173f6920}.toolbar strong{font-size:16px}.toolbar small{display:block;color:#62758a;margin-top:3px}.toolbar-actions{display:flex;gap:8px}.toolbar a,.toolbar button{border:1px solid #245f97;border-radius:7px;padding:9px 14px;background:#245f97;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.toolbar a{background:#fff;color:#245f97}.empty{max-width:700px;margin:40px auto;padding:28px;background:#fff;border-radius:12px;text-align:center}.sheet{width:210mm;min-height:297mm;margin:20px auto;padding:18mm 11mm 12mm;background:#fff;box-shadow:0 6px 25px #193a5929;display:flex;flex-direction:column;gap:3mm;page-break-after:always;break-after:page}.sheet:last-child{page-break-after:auto;break-after:auto}.sheet-header{display:flex;justify-content:space-between;align-items:center;border-bottom:1.2px solid #243c58;padding-bottom:3mm}.brand{display:flex;align-items:center;gap:5px;font-size:9pt;font-weight:700}.brand img{width:18px;height:20px;object-fit:contain}.sheet-header h1{margin:0;font-size:17pt;letter-spacing:-.04em}.sheet-header small{font-size:8pt;color:#506782}.meta{display:grid;grid-template-columns:25mm 1fr 25mm 1fr;border-top:1px solid #8ca1b6;border-left:1px solid #8ca1b6;font-size:8pt}.meta>*{min-height:8mm;padding:1.5mm 2mm;border-right:1px solid #8ca1b6;border-bottom:1px solid #8ca1b6;overflow-wrap:anywhere}.meta dt{margin:0;background:#edf3f8;font-weight:700}.meta dd{margin:0}.participants{padding:2.5mm 3mm;border:1px solid #8ca1b6;font-size:8pt;line-height:1.45}.participants strong{margin-right:2mm}.hazard-columns{display:grid;grid-template-columns:1fr 1fr;gap:3mm;flex:1;min-height:0}.hazard-section{border:1px solid #8ca1b6;padding:2mm;min-width:0}.hazard-section h2{margin:0 0 2mm;padding:1.5mm 2mm;background:#e8f0f8;font-size:10pt}.hazard-card{border-top:1px solid #cbd7e5;padding:2mm 1mm;font-size:7.6pt;line-height:1.27;overflow-wrap:anywhere}.hazard-card:first-of-type{border-top:0}.hazard-card h3{margin:0 0 1mm;font-size:8.5pt;line-height:1.25}.hazard-card .source{color:#48627b;font-size:7pt}.hazard-card p{margin:1mm 0}.hazard-card b{color:#243f5b}.risk-values{font-size:7.2pt;color:#385b79}.no-hazards{padding:8mm 2mm;color:#728397;text-align:center;font-size:8pt}.sheet-footer{display:flex;justify-content:space-between;border-top:1px solid #a7b8ca;padding-top:2mm;color:#60748a;font-size:7pt}@page{size:A4 portrait;margin:0}@media print{html,body{width:210mm;margin:0;background:#fff}.toolbar{display:none}.sheet{width:210mm;height:297mm;min-height:0;margin:0;padding:18mm 11mm 12mm;box-shadow:none;overflow:hidden;print-color-adjust:exact;-webkit-print-color-adjust:exact}}@media(max-width:800px){.sheet{transform:scale(.72);transform-origin:top center;margin-bottom:-80mm}.toolbar{flex-wrap:wrap}}@media(max-width:550px){.sheet{transform:scale(.52);margin-bottom:-140mm}}
@media print{.sheet{transform:none!important;margin:0!important}}
.current-control-highlight{background:#fff36d;color:#192b3d;padding:0 .3mm;-webkit-box-decoration-break:clone;box-decoration-break:clone;-webkit-print-color-adjust:exact;print-color-adjust:exact}
</style></head><body>
<div class="toolbar"><div><strong>참여자 수시위험성평가 출력</strong><small>참여자가 있는 <?= number_format(count($reports)) ?>건 · 평가서마다 A4 1페이지 · 주요 위험요소 각 구분 상위 5건</small></div><div class="toolbar-actions"><a href="hazard_review.php">열람으로 돌아가기</a><?php if ($reports): ?><button type="button" onclick="window.print()">인쇄 / PDF 저장</button><?php endif; ?></div></div>
<?php if (!$reports): ?><div class="empty">현재 선택 조건에 참여자가 있는 평가서가 없습니다.</div><?php endif; ?>
<?php foreach ($reports as $report): ?>
<article class="sheet" data-report-id="<?= (int)$report['report_id'] ?>">
  <div class="sheet-header"><div class="brand"><img src="../safety_manual/assets/policy-logo.png" alt=""><span>주식회사 현대기전</span></div><h1>수시위험성평가서</h1><small>주요 위험요소</small></div>
  <dl class="meta"><dt>작업일자</dt><dd><?= print_h($report['work_date']) ?></dd><dt>팀</dt><dd><?= print_h(hazard_participant_print_team($report) ?: '미지정') ?></dd><dt>작업명</dt><dd><?= print_h($report['work_title']) ?></dd><dt>작업장소</dt><dd><?= print_h($report['work_place']) ?></dd><dt>작성자</dt><dd><?= print_h($report['user_name']) ?></dd><dt>참여자 수</dt><dd><?= (int)$report['participant_count'] ?>명</dd></dl>
  <div class="participants"><strong>참여자</strong><?php foreach ($report['participants'] as $index=>$participant): ?><?= $index ? ' · ' : '' ?><?= print_h($participant['user_name'] ?: $participant['user_login_id']) ?><?php endforeach; ?></div>
  <div class="hazard-columns">
    <?php foreach (['work_related'=>'작업 관련 위험요소','other'=>'중대재해·공구·환경 위험요소'] as $sectionKey=>$sectionTitle): ?>
    <section class="hazard-section"><h2><?= print_h($sectionTitle) ?></h2>
      <?php if (!$report['hazards'][$sectionKey]): ?><div class="no-hazards">참여자가 선택한 위험요소가 없습니다.</div><?php endif; ?>
      <?php foreach ($report['hazards'][$sectionKey] as $index=>$hazard): ?>
      <div class="hazard-card"><h3><?= $index+1 ?>. <?= print_h($hazard['hazard_name']) ?></h3><div class="source"><?= print_h($hazard['unit_title']) ?> · 선택 <?= (int)$hazard['selected_count'] ?>회 / 참여 <?= (int)$hazard['worker_count'] ?>명</div>
      <?php if (trim((string)$hazard['accident_type']) !== ''): ?><p><b>재해형태</b> <?= print_h($hazard['accident_type']) ?><?php if (trim((string)$hazard['injury_result']) !== ''): ?> · <?= print_h($hazard['injury_result']) ?><?php endif; ?></p><?php endif; ?>
      <?php if (trim((string)$hazard['current_control_text']) !== ''): ?><p><b>현재조치</b> <mark class="current-control-highlight"><?= nl2br(print_h($hazard['current_control_text'])) ?></mark></p><?php endif; ?>
      <?php if (trim((string)$hazard['additional_control_text']) !== ''): ?><p><b>추가조치</b> <?= nl2br(print_h($hazard['additional_control_text'])) ?></p><?php endif; ?>
      <div class="risk-values">현재 위험도 <?= print_h($hazard['risk_score_before'] ?? '-') ?> → 조치 후 <?= print_h($hazard['risk_score_current'] ?? '-') ?></div></div>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
  </div>
</article>
<?php endforeach; ?>
</body></html>
