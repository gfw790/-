<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/hazard_4m.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function parse_detail_selection(string $value): array
{
    $parts = explode('|', $value, 3);
    return [
        'type' => $parts[0] ?? '',
        'parent' => $parts[1] ?? '',
        'title' => count($parts) >= 3 ? ($parts[2] ?? '') : ($parts[1] ?? ''),
    ];
}

function unit_type_label(string $unitType): string
{
    return match ($unitType) {
        'target' => '작업관련 위험성평가',
        'major_work' => '중대재해관련 위험성평가',
        'tool' => '위험공구관련 위험성평가',
        'env' => '작업환경관련 위험성평가',
        default => $unitType,
    };
}

function unit_footer_tone_class(string $unitType): string
{
    return match ($unitType) {
        'env' => 'footer-tone-env',
        'major_work' => 'footer-tone-major',
        'tool' => 'footer-tone-tool',
        'target' => 'footer-tone-target',
        default => 'footer-tone-default',
    };
}

function resolve_selected_risk_assessments(PDO $pdo, array $report): array
{
    $selected = [];

    $baseUnitId = (int)($report['unit_ra_id'] ?? 0);
    if ($baseUnitId > 0) {
        $selected[$baseUnitId] = $baseUnitId;
    }

    $detailStmt = $pdo->prepare("
        SELECT task_name
        FROM work_report_detail
        WHERE report_id = :report_id
        ORDER BY report_detail_id ASC
    ");
    $detailStmt->execute([':report_id' => (int)$report['report_id']]);

    $lookupStmt = $pdo->prepare("
        SELECT unit_ra_id
        FROM unit_ra_header
        WHERE use_yn = 'Y'
          AND unit_type = :unit_type
          AND unit_title = :unit_title
        ORDER BY sort_no ASC, unit_ra_id DESC
        LIMIT 1
    ");

    foreach ($detailStmt->fetchAll(PDO::FETCH_COLUMN) as $detailValue) {
        $parsed = parse_detail_selection((string)$detailValue);
        $lookupType = '';
        $lookupTitle = '';

        if ($parsed['type'] === 'major_work' && $parsed['title'] !== '') {
            $lookupType = 'major_work';
            $lookupTitle = $parsed['title'];
        } elseif ($parsed['type'] === 'major_work_sub' && $parsed['parent'] !== '' && $parsed['title'] !== '') {
            $lookupType = 'major_work';
            $lookupTitle = $parsed['parent'] . ' - ' . $parsed['title'];
        } elseif (in_array($parsed['type'], ['env', 'tool'], true) && $parsed['title'] !== '') {
            $lookupType = $parsed['type'];
            $lookupTitle = $parsed['title'];
        }

        if ($lookupType === '' || $lookupTitle === '') {
            continue;
        }

        $lookupStmt->execute([
            ':unit_type' => $lookupType,
            ':unit_title' => $lookupTitle,
        ]);
        $unitId = (int)$lookupStmt->fetchColumn();
        if ($unitId > 0) {
            $selected[$unitId] = $unitId;
        }
    }

    return array_values($selected);
}

function risk_text(array $item, string $prefix): string
{
    return sprintf(
        'P %s / S %s / R %s',
        h($item[$prefix . '_likelihood'] ?? $item['likelihood_' . $prefix] ?? '-'),
        h($item[$prefix . '_severity'] ?? $item['severity_' . $prefix] ?? '-'),
        h($item[$prefix . '_score'] ?? $item['risk_score_' . $prefix] ?? '-')
    );
}

function parse_request_id_list(string $rawValue): array
{
    $ids = [];
    foreach (explode(',', $rawValue) as $value) {
        $id = (int)trim($value);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function build_title_print_pages(array $headerRows, int $itemsPerColumn = 14, int $columnsPerPage = 3): array
{
    if ($itemsPerColumn < 1) {
        $itemsPerColumn = 1;
    }
    if ($columnsPerPage < 1) {
        $columnsPerPage = 1;
    }

    $pageSize = $itemsPerColumn * $columnsPerPage;
    $pages = [];
    $totalRows = count($headerRows);

    for ($pageOffset = 0; $pageOffset < $totalRows; $pageOffset += $pageSize) {
        $pageRows = array_slice($headerRows, $pageOffset, $pageSize);
        $pageColumns = array_fill(0, $columnsPerPage, []);

        foreach ($pageRows as $pageIndex => $headerRow) {
            $columnIndex = intdiv($pageIndex, $itemsPerColumn);
            $pageColumns[$columnIndex][] = [
                'display_index' => $pageOffset + $pageIndex + 1,
                'header' => $headerRow,
            ];
        }

        $pages[] = $pageColumns;
    }

    return $pages;
}

$requestedUnitIds = parse_request_id_list((string)($_POST['unit_ra_ids'] ?? $_GET['unit_ra_ids'] ?? ''));
$requestedReportIds = parse_request_id_list((string)($_POST['report_ids'] ?? $_GET['report_ids'] ?? ''));
$requestSource = (string)($_POST['source'] ?? $_GET['source'] ?? '');
$isDirectUnitRequest = !empty($requestedUnitIds);

$user = auth_current_user();
if ($user === null && !$isDirectUnitRequest) {
    header('Location: task_select.php');
    exit;
}

$pdo = getDB();

$unitIds = [];
$unitSourceMap = [];

if ($isDirectUnitRequest) {
    $unitIds = $requestedUnitIds;
} else {
    $reportRows = $pdo->query("
        SELECT
            report_id,
            unit_ra_id,
            work_title,
            work_date,
            work_place
        FROM work_report
        ORDER BY work_date DESC, report_id DESC
    ")->fetchAll() ?: [];

    if (!empty($requestedReportIds)) {
        $reportIdMap = array_fill_keys($requestedReportIds, true);
        $reportRows = array_values(array_filter(
            $reportRows,
            static fn(array $row): bool => isset($reportIdMap[(int)($row['report_id'] ?? 0)])
        ));
    }

    foreach ($reportRows as $reportRow) {
        foreach (resolve_selected_risk_assessments($pdo, $reportRow) as $unitRaId) {
            if ($unitRaId <= 0) {
                continue;
            }

            $unitIds[$unitRaId] = $unitRaId;
            $sourceKey = $reportRow['work_date'] . '|' . $reportRow['work_title'] . '|' . $reportRow['work_place'];
            $unitSourceMap[$unitRaId][$sourceKey] = [
                'work_date' => (string)($reportRow['work_date'] ?? ''),
                'work_title' => (string)($reportRow['work_title'] ?? ''),
                'work_place' => (string)($reportRow['work_place'] ?? ''),
            ];
        }
    }

    $unitIds = array_values($unitIds);
}

if (empty($unitIds)) {
    $headerRows = [];
    $itemsByUnit = [];
} else {
    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));

    $headerStmt = $pdo->prepare("
        SELECT
            unit_ra_id,
            unit_code,
            unit_title,
            process_name,
            unit_type,
            evaluator_name,
            remark
        FROM unit_ra_header
        WHERE unit_ra_id IN ($placeholders)
        ORDER BY sort_no ASC, unit_ra_id ASC
    ");
    $headerStmt->execute(array_values($unitIds));
    $headerRows = $headerStmt->fetchAll() ?: [];
    if ($isDirectUnitRequest) {
        $unitOrder = array_flip($unitIds);
        usort($headerRows, static function (array $left, array $right) use ($unitOrder): int {
            $leftOrder = $unitOrder[(int)($left['unit_ra_id'] ?? 0)] ?? PHP_INT_MAX;
            $rightOrder = $unitOrder[(int)($right['unit_ra_id'] ?? 0)] ?? PHP_INT_MAX;
            return $leftOrder <=> $rightOrder;
        });
    }

    $itemStmt = $pdo->prepare("
        SELECT
            unit_ra_id,
            sort_no,
            task_code,
            task_name,
            hazard_name,
            hazard_4m,
            accident_type,
            injury_result,
            cause_text,
            current_control_text,
            additional_control_text,
            likelihood_before,
            severity_before,
            risk_score_before,
            likelihood_current,
            severity_current,
            risk_score_current,
            likelihood_after,
            severity_after,
            risk_score_after,
            remark
        FROM unit_ra_item
        WHERE unit_ra_id IN ($placeholders)
          AND use_yn = 'Y'
        ORDER BY unit_ra_id ASC, sort_no ASC, item_id ASC
    ");
    $itemStmt->execute(array_values($unitIds));
    $itemsByUnit = [];
    foreach ($itemStmt->fetchAll() as $itemRow) {
        $itemsByUnit[(int)$itemRow['unit_ra_id']][] = hazard_4m_enrich($itemRow, true);
    }
}

$pageTitle = $isDirectUnitRequest
    ? '단위 위험성평가 선택 출력 미리보기'
    : '단위 위험성평가 전체 출력 미리보기';
$introText = $isDirectUnitRequest
    ? '목록 화면에서 선택한 단위 위험성평가를 한 번에 인쇄할 수 있도록 정리한 화면입니다. 인쇄 시에는 평가서마다 페이지가 자동으로 나뉩니다.'
    : '현재 작업들에 연결된 단위 위험성평가 원본을 중복 없이 모아서 보여주는 화면입니다. 같은 평가서는 한 번만 출력되며, 인쇄 시에는 평가서마다 페이지가 나뉩니다.';
$backHref = $requestSource === 'list' ? 'list.html' : 'hazard_review.php';
$displayRoleLabel = (string)($user['role_label'] ?? '선택 출력');
$displayName = $user ? auth_display_name($user) : '단위 위험성평가 인쇄';
if ($user === null) {
    $user = [
        'role_label' => $displayRoleLabel,
        'name' => $displayName,
        'role' => '',
        'login_id' => '',
    ];
}

$totalAssessments = count($headerRows);
$totalRiskItems = 0;
foreach ($itemsByUnit as $unitItemRows) {
    $totalRiskItems += count($unitItemRows);
}
$totalLinkedSources = 0;
foreach ($unitSourceMap as $sourceRows) {
    $totalLinkedSources += count($sourceRows);
}
$titlePrintPages = build_title_print_pages($headerRows, 14, 3);
$printEvaluationDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$previewModeLabel = $isDirectUnitRequest ? '선택 출력' : '전체 출력';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
<style>
  :root {
    --preview-ink: #16324a;
    --preview-muted: #5b7188;
    --preview-line: #d9e5f0;
    --preview-line-strong: #c5d6e6;
    --preview-soft: #f7fbff;
    --preview-card: rgba(255, 255, 255, 0.88);
    --preview-primary: #245f97;
    --preview-accent: #d67c1c;
    --preview-shadow: 0 24px 70px rgba(17, 39, 59, 0.12);
    --preview-shadow-soft: 0 12px 30px rgba(17, 39, 59, 0.08);
    --title-print-page-height: 289mm;
  }
  body {
    padding: 24px 18px 48px;
    background:
      radial-gradient(circle at top left, rgba(36, 95, 151, 0.18), transparent 30%),
      radial-gradient(circle at top right, rgba(214, 124, 28, 0.14), transparent 24%),
      linear-gradient(180deg, #edf4fa 0%, #f7fbff 100%);
    color: var(--preview-ink);
  }
  .shell {
    position: relative;
    max-width: 1380px;
    margin: 0 auto;
  }
  .shell::before,
  .shell::after {
    content: "";
    position: absolute;
    z-index: 0;
    pointer-events: none;
    border-radius: 999px;
    filter: blur(10px);
    opacity: 0.8;
  }
  .shell::before {
    width: 240px;
    height: 240px;
    top: 80px;
    right: -40px;
    background: radial-gradient(circle, rgba(36, 95, 151, 0.12) 0%, transparent 70%);
  }
  .shell::after {
    width: 220px;
    height: 220px;
    bottom: 120px;
    left: -30px;
    background: radial-gradient(circle, rgba(214, 124, 28, 0.10) 0%, transparent 70%);
  }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    position: sticky;
    top: 16px;
    z-index: 20;
    margin-bottom: 18px;
    padding: 14px 18px;
    border: 1px solid rgba(197, 214, 230, 0.82);
    border-radius: 0;
    background: rgba(255, 255, 255, 0.76);
    box-shadow: var(--preview-shadow-soft);
    backdrop-filter: blur(18px);
  }
  .identity,
  .actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .role-badge {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 6px 14px;
    border-radius: 999px;
    background: rgba(36, 95, 151, 0.10);
    color: var(--preview-primary);
    border: 1px solid rgba(36, 95, 151, 0.14);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: -0.01em;
  }
  .identity-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--preview-ink);
  }
  .btn-secondary,
  .btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 11px 18px;
    border-radius: 14px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, border-color 0.18s ease;
  }
  .btn-secondary {
    background: rgba(255, 255, 255, 0.92);
    color: var(--preview-muted);
    border: 1px solid var(--preview-line-strong);
  }
  .btn-primary {
    background: linear-gradient(180deg, #2a6ca8 0%, #1f578d 100%);
    color: #fff;
    border: none;
    box-shadow: 0 10px 24px rgba(36, 95, 151, 0.20);
  }
  .btn-title-print {
    background: rgba(238, 246, 255, 0.98);
    color: #1f578d;
    border: 1px solid #c7dcf0;
  }
  .btn-secondary:hover,
  .btn-primary:hover {
    transform: translateY(-1px);
  }
  .btn-secondary:hover {
    background: #fff;
    color: var(--preview-ink);
    border-color: #b6cadc;
  }
  .btn-primary:hover {
    background: linear-gradient(180deg, #235f95 0%, #19496f 100%);
  }
  .intro {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(300px, 0.9fr);
    gap: 22px;
    overflow: hidden;
    margin-bottom: 22px;
    padding: 30px;
    border-radius: 0;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background:
      radial-gradient(circle at top right, rgba(255, 255, 255, 0.20), transparent 34%),
      linear-gradient(135deg, #163d63 0%, #245f97 52%, #3f80b7 100%);
    box-shadow: var(--preview-shadow);
    color: #fff;
  }
  .intro::after {
    content: "";
    position: absolute;
    width: 360px;
    height: 360px;
    right: -80px;
    top: -140px;
    border-radius: 999px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.18) 0%, transparent 68%);
    pointer-events: none;
  }
  .intro-copy,
  .intro-side {
    position: relative;
    z-index: 1;
  }
  .intro-kicker {
    display: inline-flex;
    align-items: center;
    padding: 7px 12px;
    margin-bottom: 14px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.12);
    color: rgba(255, 255, 255, 0.92);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
  }
  .intro h1 {
    max-width: 14ch;
    margin-bottom: 12px;
    font-size: clamp(32px, 4vw, 40px);
    line-height: 1.15;
    letter-spacing: -0.03em;
    color: #fff;
  }
  .intro p {
    max-width: 720px;
    color: rgba(238, 246, 255, 0.86);
    line-height: 1.75;
    font-size: 15px;
  }
  .intro-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
  }
  .intro-stat-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }
  .intro-stat,
  .intro-note {
    border-radius: 0;
    border: 1px solid rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(12px);
  }
  .intro-stat {
    min-height: 112px;
    padding: 16px 16px 14px;
  }
  .intro-stat strong,
  .intro-note strong {
    display: block;
    margin-bottom: 8px;
    color: rgba(238, 246, 255, 0.72);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }
  .intro-stat span {
    display: block;
    font-size: clamp(28px, 3vw, 34px);
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.04em;
  }
  .intro-stat small,
  .intro-note p {
    display: block;
    margin-top: 10px;
    color: rgba(238, 246, 255, 0.72);
    font-size: 12px;
    line-height: 1.55;
  }
  .intro-note {
    margin-top: 12px;
    padding: 16px;
    background: rgba(16, 37, 59, 0.20);
  }
  .sheet-list {
    display: grid;
    gap: 22px;
  }
  .title-print-list {
    display: none;
    position: relative;
    z-index: 1;
  }
  .title-print-sheet {
    padding: 28px;
    border: 1px solid rgba(197, 214, 230, 0.86);
    background: rgba(255, 255, 255, 0.94);
    box-shadow: var(--preview-shadow-soft);
  }
  .title-print-pages {
    display: none;
  }
  .title-print-source h2 {
    margin-bottom: 8px;
    font-size: clamp(28px, 3vw, 34px);
    line-height: 1.15;
    letter-spacing: -0.03em;
    color: var(--preview-ink);
  }
  .title-print-source > p {
    color: var(--preview-muted);
    font-size: 15px;
    line-height: 1.7;
    margin-bottom: 18px;
  }
  .title-print-columns {
    column-count: 1;
    column-gap: 18px;
    column-fill: balance;
    border-top: 1.5px solid var(--preview-ink);
  }
  .title-print-entry {
    break-inside: avoid;
    page-break-inside: avoid;
    padding: 7px 0 8px;
    border-bottom: 1px solid var(--preview-line);
  }
  .title-print-entry-line {
    display: flex;
    align-items: flex-start;
    gap: 8px;
  }
  .title-print-no {
    flex: 0 0 28px;
    text-align: right;
    color: var(--preview-ink);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.45;
  }
  .title-print-body {
    min-width: 0;
  }
  .title-print-title {
    color: var(--preview-ink);
    font-size: 13px;
    font-weight: 700;
    line-height: 1.42;
    letter-spacing: -0.02em;
    word-break: keep-all;
  }
  .title-print-detail {
    margin-top: 2px;
    color: var(--preview-muted);
    font-size: 10px;
    line-height: 1.45;
  }
  .print-sheet {
    position: relative;
    z-index: 1;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.92);
    border: 1px solid rgba(197, 214, 230, 0.86);
    border-radius: 0;
    box-shadow: var(--preview-shadow-soft);
  }
  .print-sheet::before {
    content: "";
    display: block;
    height: 6px;
    background: linear-gradient(90deg, #f1b469 0%, #d67c1c 20%, #245f97 58%, #173f69 100%);
  }
  .sheet-head {
    padding: 26px 28px 14px;
    border-bottom: 1px solid #dfe8f1;
    background: linear-gradient(180deg, rgba(250, 252, 255, 0.96) 0%, rgba(244, 248, 252, 0.82) 100%);
  }
  .sheet-evaluator-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 12px;
  }
  .sheet-evaluator-text {
    color: #50657a;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.4;
    text-align: right;
  }
  .sheet-title-row {
    display: flex;
    align-items: flex-start;
    gap: 18px;
    margin-bottom: 16px;
  }
  .sheet-title {
    min-width: 0;
  }
  .sheet-title h2 {
    margin-bottom: 8px;
    font-size: clamp(30px, 4vw, 36px);
    line-height: 1.1;
    letter-spacing: -0.04em;
    color: var(--preview-ink);
  }
  .sheet-title p {
    color: #4a647d;
    font-size: 16.5px;
    font-weight: 600;
    line-height: 1.68;
  }
  .source-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
  }
  .task-flow-wrap {
    margin-top: 14px;
    padding: 12px 14px;
    border: none;
    background: rgba(255, 255, 255, 0.9);
  }
  .task-flow-label {
    margin-bottom: 8px;
    color: #4d647a;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.35;
  }
  .task-flow-list {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 10px;
  }
  .task-flow-node {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 7px 12px;
    border: 1px solid #cbd9e6;
    background: linear-gradient(180deg, #ffffff 0%, #f5f9fd 100%);
    color: #23415d;
    font-size: 16.6px;
    font-weight: 700;
    line-height: 1.4;
  }
  .task-flow-arrow {
    color: #7a90a5;
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
  }
  .source-chip {
    display: inline-flex;
    align-items: center;
    padding: 7px 11px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.96);
    color: #35516b;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.35;
    border: 1px solid var(--preview-line);
    box-shadow: 0 6px 12px rgba(17, 39, 59, 0.04);
  }
  .sheet-body {
    padding: 10px 28px 30px;
  }
  .table-print-footer {
    display: none;
  }
  .table-print-footer-cell {
    padding: 14px 0 0;
    border-right: none !important;
    border-bottom: none !important;
    background: #fff !important;
    text-align: center !important;
  }
  .footer-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 16px;
    border: 1px solid transparent;
    color: #5b7188;
    font-size: 15.2px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: 0.01em;
    white-space: nowrap;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .footer-tone-env {
    background: #e7f3ff;
    border-color: #9bc3ea;
    color: #205d92;
  }
  .footer-tone-major {
    background: #fff0e7;
    border-color: #e4af88;
    color: #a75413;
  }
  .footer-tone-tool {
    background: #e8f8ec;
    border-color: #9fcfa9;
    color: #2d6c3a;
  }
  .footer-tone-target {
    background: #eef2f7;
    border-color: #b9c6d4;
    color: #3b556e;
  }
  .footer-tone-default {
    background: #f3f6f9;
    border-color: #c7d2de;
    color: #5b7188;
  }
  .sheet-page-footer {
    margin-top: 14px;
    text-align: center;
  }
  .sheet-page-footer-text {
    display: inline-flex;
  }
  .table-wrap {
    margin-left: -28px;
    margin-right: -28px;
    overflow-x: auto;
    border-top: 1px solid var(--preview-line);
    border-bottom: 1px solid var(--preview-line);
    border-left: none;
    border-right: none;
    border-radius: 0;
    background: linear-gradient(180deg, rgba(241, 247, 253, 0.96) 0%, rgba(255, 255, 255, 0.98) 44px, #ffffff 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.94);
  }
  table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1460px;
    border-radius: 0 !important;
    overflow: visible !important;
  }
  th,
  td {
    padding: 11px 11px;
    border-bottom: 1px solid #e5edf5;
    border-right: 1px solid #e5edf5;
    font-size: 15.6px;
    vertical-align: top;
    line-height: 1.72;
    text-align: left;
    color: var(--preview-ink);
  }
  th:last-child,
  td:last-child {
    border-right: none;
  }
  th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: linear-gradient(180deg, #edf5fc 0%, #f5f9fd 100%);
    color: #4d647a;
    font-size: 15.2px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  thead,
  thead tr,
  thead th,
  thead th:first-child,
  thead th:last-child {
    border-radius: 0 !important;
  }
  thead th:first-child {
    border-top-left-radius: 0 !important;
  }
  thead th:last-child {
    border-top-right-radius: 0 !important;
  }
  td.center,
  th.center {
    text-align: center;
  }
  .row-odd td {
    background: #fbfdff;
  }
  tbody tr:hover td {
    background: #f7fbff;
  }
  .risk-box {
    white-space: nowrap;
    text-align: center;
    font-weight: 800;
    color: var(--preview-ink);
    background-image: linear-gradient(180deg, rgba(36, 95, 151, 0.05) 0%, rgba(36, 95, 151, 0.02) 100%);
  }
  .empty-box {
    padding: 32px 22px;
    border: 1px dashed #c9d7e5;
    border-radius: 0;
    background: linear-gradient(180deg, #fbfdff 0%, #f5f9fd 100%);
    text-align: center;
    color: #6f8092;
    font-size: 15px;
    line-height: 1.7;
  }
  .print-measure-root {
    position: absolute;
    left: -99999px;
    top: 0;
    width: 285mm;
    visibility: hidden;
    pointer-events: none;
    overflow: hidden;
  }
  .print-title-measure-root {
    width: 210mm;
  }
  .print-measure-root .title-print-page {
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    width: 210mm;
    height: var(--title-print-page-height);
    padding: 4mm 4mm 4mm 20mm;
    overflow: hidden;
    background: #fff;
    break-inside: avoid-page;
    page-break-inside: avoid;
  }
  .print-measure-root .title-print-page:nth-child(even) {
    padding: 4mm 20mm 4mm 4mm;
  }
  .print-measure-root .title-print-page-columns {
    display: flex;
    gap: 4mm;
    align-items: stretch;
    flex: 1 1 auto;
    min-height: 0;
    padding-top: 1.2mm;
    border-top: 0.45mm solid #333;
    box-sizing: border-box;
  }
  .print-measure-root .title-print-page-column {
    flex: 1 1 0;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
  }
  .print-measure-root .title-print-entry {
    padding: 1.6mm 0 1.8mm;
  }
  .print-measure-root .title-print-no {
    flex-basis: 6mm;
    font-size: 10.5pt;
    line-height: 1.22;
  }
  .print-measure-root .title-print-title {
    font-size: 12.6pt;
    line-height: 1.24;
  }
  .print-measure-root .title-print-detail {
    font-size: 10.5pt;
    line-height: 1.24;
  }
  .print-measure-root .title-print-empty-box {
    margin: 0;
  }
  .print-measure-root .print-sheet {
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    height: calc(210mm - 12mm);
    margin: 0;
    border: none;
    border-radius: 0;
    box-shadow: none;
    background: #fff;
    overflow: hidden;
  }
  .print-measure-root .sheet-head {
    padding: 10mm 0 1.6mm;
    background: none;
    border-bottom: 0.45mm solid #333;
  }
  .print-measure-root .sheet-evaluator-row {
    margin-top: 2.2mm;
  }
  .print-measure-root .sheet-evaluator-text {
    color: #333;
    font-size: 10px;
  }
  .print-measure-root .task-flow-wrap {
    margin-top: 2mm;
    padding: 2mm 2.4mm;
    border: none;
    background: #fff;
  }
  .print-measure-root .task-flow-label {
    margin-bottom: 1.2mm;
    color: #465c72;
    font-size: 11px;
  }
  .print-measure-root .task-flow-list {
    gap: 1.2mm 1.6mm;
  }
  .print-measure-root .task-flow-node {
    min-height: 0;
    padding: 1mm 1.8mm;
    border: 0.25mm solid #cfd9e3;
    background: #fff;
    color: #1f3750;
    font-size: 11.5px;
    line-height: 1.35;
  }
  .print-measure-root .task-flow-arrow {
    color: #70859a;
    font-size: 10px;
  }
  .print-measure-root .sheet-body {
    flex: 1 1 auto;
    padding: 0.8mm 0 0;
    min-height: 0;
  }
  .print-measure-root .sheet-page-footer {
    margin-top: 0;
    padding-top: 3mm;
  }
  .print-measure-root .footer-badge {
    padding: 1.3mm 2.6mm;
    font-size: 11.9px;
    border-width: 0.25mm;
  }
  .print-measure-root .table-wrap {
    margin: 0;
    overflow: visible;
    border: none;
    border-radius: 0;
    background: #fff;
    box-shadow: none;
    width: 100%;
  }
  .print-measure-root table {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed;
    border-radius: 0 !important;
    overflow: visible !important;
    background: #fff;
  }
  .print-measure-root thead {
    display: table-header-group;
  }
  .print-measure-root th,
  .print-measure-root td {
    color: #000;
    white-space: normal;
    word-break: keep-all;
    overflow-wrap: break-word;
  }
  .print-measure-root th {
    position: static;
    padding: 5px 5px;
    background: #f1f1f1 !important;
    font-size: 9px;
    line-height: 1.4;
    white-space: normal;
  }
  .print-measure-root td {
    padding: 6px 5px;
    font-size: 10.8px;
    line-height: 1.56;
  }
  .print-measure-root tbody tr {
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .print-measure-root .risk-box {
    white-space: normal;
    text-align: left;
    font-size: 10.2px;
    line-height: 1.5;
    background-image: none;
  }
  .print-measure-root .source-chip {
    background: #fff !important;
    box-shadow: none;
  }
  .print-measure-root th:nth-child(1),
  .print-measure-root td:nth-child(1) {
    width: 3%;
  }
  .print-measure-root th:nth-child(2),
  .print-measure-root td:nth-child(2) {
    width: 11%;
  }
  .print-measure-root th:nth-child(3),
  .print-measure-root td:nth-child(3) {
    width: 6%;
  }
  .print-measure-root th:nth-child(4),
  .print-measure-root td:nth-child(4) {
    width: 11%;
  }
  .print-measure-root th:nth-child(5),
  .print-measure-root td:nth-child(5) {
    width: 6%;
  }
  .print-measure-root th:nth-child(6),
  .print-measure-root td:nth-child(6) {
    width: 7%;
  }
  .print-measure-root th:nth-child(7),
  .print-measure-root td:nth-child(7) {
    width: 16%;
  }
  .print-measure-root th:nth-child(8),
  .print-measure-root td:nth-child(8) {
    width: 5%;
  }
  .print-measure-root th:nth-child(9),
  .print-measure-root td:nth-child(9) {
    width: 12%;
  }
  .print-measure-root th:nth-child(10),
  .print-measure-root td:nth-child(10) {
    width: 5%;
  }
  .print-measure-root th:nth-child(11),
  .print-measure-root td:nth-child(11) {
    width: 12%;
  }
  .print-measure-root th:nth-child(12),
  .print-measure-root td:nth-child(12) {
    width: 5%;
  }
  .print-measure-root th:nth-child(13),
  .print-measure-root td:nth-child(13) {
    width: 7%;
  }
  @page {
    size: A4 landscape;
    margin: 6mm;
  }
  @media print {
    body {
      background: #fff;
      margin: 0;
      padding: 0;
    }
    .shell::before,
    .shell::after {
      display: none;
    }
    .topbar,
    .intro {
      display: none !important;
    }
    body[data-print-mode="title-only"] .sheet-list,
    body[data-print-mode="title-only"] .print-measure-root {
      display: none !important;
    }
    body[data-print-mode="title-only"] .title-print-list {
      display: block !important;
      border: none;
      box-shadow: none;
      padding: 0;
    }
    body[data-print-mode="title-only"] .title-print-sheet {
      padding: 0;
      border: none;
      box-shadow: none;
      background: none;
    }
    body[data-print-mode="title-only"] .title-print-source {
      display: none !important;
    }
    body[data-print-mode="title-only"] .title-print-pages {
      display: block !important;
    }
    body[data-print-mode="title-only"] .title-print-page {
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
      height: var(--title-print-page-height);
      margin: 0;
      padding: 4mm 4mm 4mm 20mm;
      overflow: hidden;
      background: #fff;
      -webkit-box-decoration-break: clone;
      box-decoration-break: clone;
      break-inside: avoid-page;
      page-break-inside: avoid;
      page-break-after: always;
      break-after: page;
    }
    body[data-print-mode="title-only"] .title-print-page:nth-child(even) {
      padding: 4mm 20mm 4mm 4mm;
    }
    body[data-print-mode="title-only"] .title-print-page:last-child {
      page-break-after: auto;
      break-after: auto;
    }
    body[data-print-mode="title-only"] .title-print-source h2 {
      display: none;
    }
    body[data-print-mode="title-only"] .title-print-source > p {
      display: none;
    }
    body[data-print-mode="title-only"] .title-print-page-columns {
      display: flex;
      gap: 4mm;
      align-items: stretch;
      flex: 1 1 auto;
      min-height: 0;
      padding-top: 1.2mm;
      border-top: 0.45mm solid #333;
      box-sizing: border-box;
    }
    body[data-print-mode="title-only"] .title-print-page-column {
      flex: 1 1 0;
      min-width: 0;
      min-height: 0;
      overflow: hidden;
    }
    body[data-print-mode="title-only"] .title-print-empty-box {
      margin: 0;
    }
    body[data-print-mode="title-only"] .title-print-entry {
      padding: 1.6mm 0 1.8mm;
    }
    body[data-print-mode="title-only"] .title-print-no {
      flex-basis: 6mm;
      font-size: 10.5pt;
      line-height: 1.22;
    }
    body[data-print-mode="title-only"] .title-print-title {
      font-size: 12.6pt;
      line-height: 1.24;
    }
    body[data-print-mode="title-only"] .title-print-detail {
      font-size: 10.5pt;
      line-height: 1.24;
    }
    body:not([data-print-mode="title-only"]) .title-print-list {
      display: none !important;
    }
    .intro::after,
    .intro-kicker {
      display: none;
    }
    .sheet-list {
      gap: 0;
    }
    .print-sheet {
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
      height: calc(210mm - 12mm);
      margin: 0;
      border: none;
      border-radius: 0;
      box-shadow: none;
      overflow: hidden;
      page-break-after: always;
      break-after: page;
    }
    .print-sheet::before {
      display: none;
    }
    .print-sheet:last-child {
      page-break-after: auto;
      break-after: auto;
    }
    .sheet-head {
      padding: 10mm 0 1.6mm;
      background: none;
      border-bottom: 0.45mm solid #333;
    }
    .sheet-evaluator-row {
      margin-top: 2.2mm;
    }
    .sheet-evaluator-text {
      color: #333;
      font-size: 10px;
    }
    .task-flow-wrap {
      margin-top: 2mm;
      padding: 2mm 2.4mm;
      border: none;
      background: #fff;
    }
    .task-flow-label {
      margin-bottom: 1.2mm;
      color: #465c72;
      font-size: 11px;
    }
    .task-flow-list {
      gap: 1.2mm 1.6mm;
    }
    .task-flow-node {
      min-height: 0;
      padding: 1mm 1.8mm;
      border: 0.25mm solid #cfd9e3;
      background: #fff;
      color: #1f3750;
      font-size: 11.5px;
      line-height: 1.35;
    }
    .task-flow-arrow {
      color: #70859a;
      font-size: 10px;
    }
    .sheet-body {
      flex: 1 1 auto;
      padding: 0.8mm 0 0;
      min-height: 0;
    }
    .sheet-page-footer {
      margin-top: 0;
      padding-top: 3mm;
    }
    .footer-badge {
      padding: 1.3mm 2.6mm;
      font-size: 11.9px;
      border-width: 0.25mm;
    }
    thead {
      display: table-header-group;
    }
    .table-print-footer {
      display: table-footer-group;
    }
    .table-print-footer-cell {
      padding: 2.5mm 0 0;
      border: none !important;
      background: #fff !important;
      text-align: center !important;
    }
    .table-wrap {
      margin: 0;
      overflow: visible;
      border: none;
      border-radius: 0;
      background: #fff;
      box-shadow: none;
      width: 100%;
    }
    table {
      width: 100% !important;
      min-width: 0 !important;
      table-layout: fixed;
      border-radius: 0 !important;
      overflow: visible !important;
    }
    th,
    td {
      color: #000;
      white-space: normal;
      word-break: keep-all;
      overflow-wrap: break-word;
    }
    th {
      position: static;
      padding: 5px 5px;
      background: #f1f1f1 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      font-size: 10px;
      line-height: 1.4;
      white-space: normal;
    }
    td {
      padding: 6px 5px;
      font-size: 10.8px;
      line-height: 1.56;
    }
    tbody tr,
    tfoot tr {
      break-inside: avoid;
      page-break-inside: avoid;
    }
    th:nth-child(1),
    td:nth-child(1) {
      width: 3%;
    }
    th:nth-child(2),
    td:nth-child(2) {
      width: 11%;
    }
    th:nth-child(3),
    td:nth-child(3) {
      width: 11%;
    }
    th:nth-child(4),
    td:nth-child(4) {
      width: 6%;
    }
    th:nth-child(5),
    td:nth-child(5) {
      width: 7%;
    }
    th:nth-child(6),
    td:nth-child(6) {
      width: 16%;
    }
    th:nth-child(7),
    td:nth-child(7) {
      width: 5%;
    }
    th:nth-child(8),
    td:nth-child(8) {
      width: 12%;
    }
    th:nth-child(9),
    td:nth-child(9) {
      width: 5%;
    }
    th:nth-child(10),
    td:nth-child(10) {
      width: 12%;
    }
    th:nth-child(11),
    td:nth-child(11) {
      width: 5%;
    }
    th:nth-child(12),
    td:nth-child(12) {
      width: 7%;
    }
    .source-chip {
      background: #fff !important;
      box-shadow: none;
    }
    tbody tr:hover td {
      background: inherit;
    }
    .risk-box {
      white-space: normal;
      text-align: left;
      font-size: 10.2px;
      line-height: 1.5;
      background-image: none;
    }
  }
  @media (max-width: 1100px) {
    .intro {
      grid-template-columns: 1fr;
    }
    .intro h1 {
      max-width: none;
    }
  }
  @media (max-width: 720px) {
    body {
      padding: 16px 12px 28px;
    }
    .topbar {
      position: static;
      padding: 14px;
      border-radius: 0;
    }
    .actions {
      width: 100%;
    }
    .btn-secondary,
    .btn-primary {
      flex: 1 1 0;
    }
    .intro-actions .btn-secondary,
    .intro-actions .btn-primary {
      flex: 1 1 0;
    }
    .intro {
      padding: 24px 18px;
    }
    .sheet-head,
    .sheet-body {
      padding-left: 18px;
      padding-right: 18px;
    }
    .table-wrap {
      margin-left: -18px;
      margin-right: -18px;
    }
    .sheet-title-row {
      flex-direction: column;
      align-items: flex-start;
    }
    .intro-stat-grid {
      grid-template-columns: 1fr;
    }
    .title-print-title {
      font-size: 12px;
    }
    .title-print-detail {
      font-size: 9px;
    }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div class="identity">
        <span class="role-badge"><?= h($displayRoleLabel) ?></span>
        <span class="identity-name"><?= h($displayName) ?></span>
      </div>
      <div class="actions">
        <a class="btn-secondary" href="<?= h($backHref) ?>">목록으로</a>
        <button type="button" class="btn-secondary btn-title-print" onclick="startUnitRaPrint('title-only')">제목만 인쇄</button>
        <button type="button" class="btn-primary" onclick="startUnitRaPrint('full')">인쇄하기</button>
      </div>
    </div>

    <section class="intro">
      <div class="intro-copy">
        <span class="intro-kicker"><?= h($previewModeLabel) ?></span>
        <h1><?= h($pageTitle) ?></h1>
        <p><?= h($introText) ?></p>
        <div class="intro-actions">
          <button type="button" class="btn-secondary btn-title-print" onclick="startUnitRaPrint('title-only')">제목만 인쇄</button>
          <button type="button" class="btn-primary" onclick="startUnitRaPrint('full')">전체 인쇄</button>
        </div>
      </div>
      <div class="intro-side">
        <div class="intro-stat-grid">
          <div class="intro-stat">
            <strong>평가서</strong>
            <span><?= h($totalAssessments) ?></span>
            <small>현재 화면에 포함된 인쇄 대상 수</small>
          </div>
          <div class="intro-stat">
            <strong>위험 항목</strong>
            <span><?= h($totalRiskItems) ?></span>
            <small>선택된 평가서의 전체 세부 항목 수</small>
          </div>
          <div class="intro-stat">
            <strong>연결 작업</strong>
            <span><?= h($totalLinkedSources) ?></span>
            <small>보고서 기준으로 묶인 작업 이력 수</small>
          </div>
          <div class="intro-stat">
            <strong>출력 방식</strong>
            <span><?= h($isDirectUnitRequest ? '직접' : '일괄') ?></span>
            <small>현재 페이지 구성 기준</small>
          </div>
        </div>
        <div class="intro-note">
          <strong>Print Guide</strong>
          <p>상단 인쇄 버튼으로 현재 보이는 순서 그대로 출력됩니다. 실제 인쇄 시에는 화면용 장식이 빠지고 표 중심의 인쇄 레이아웃만 남도록 정리됩니다.</p>
        </div>
      </div>
    </section>

    <div class="sheet-list">
      <?php if (empty($headerRows)): ?>
        <div class="empty-box">출력할 단위 위험성평가가 없습니다.</div>
      <?php else: ?>
        <?php foreach ($headerRows as $sheetIndex => $headerRow): ?>
          <?php $unitRaId = (int)($headerRow['unit_ra_id'] ?? 0); ?>
          <?php $unitItems = $itemsByUnit[$unitRaId] ?? []; ?>
          <?php
            $footerParts = [];
            $flowTaskNames = [];
            $flowTaskMap = [];
            $unitTitle = trim((string)($headerRow['unit_title'] ?? ''));
            $unitCode = trim((string)($headerRow['unit_code'] ?? ''));
            $unitType = trim((string)($headerRow['unit_type'] ?? ''));
            $footerToneClass = unit_footer_tone_class($unitType);
            $evaluatorName = trim((string)($headerRow['evaluator_name'] ?? ''));
            foreach ($unitItems as $flowItem) {
                $flowTaskName = trim((string)($flowItem['task_name'] ?? ''));
                if ($flowTaskName === '' || isset($flowTaskMap[$flowTaskName])) {
                    continue;
                }
                $flowTaskMap[$flowTaskName] = true;
                $flowTaskNames[] = $flowTaskName;
            }
            $flowTaskCount = count($flowTaskNames);
            $evaluatorMetaParts = [];
            if ($evaluatorName !== '') {
                $evaluatorMetaParts[] = '평가자 ' . $evaluatorName;
            }
            $evaluatorMetaParts[] = '평가일 ' . $printEvaluationDate;
            $evaluatorMetaText = implode(' / ', $evaluatorMetaParts);
            if ($unitTitle !== '') {
                $footerParts[] = $unitTitle;
            }
            if ($unitCode !== '') {
                $footerParts[] = $unitCode;
            }
            $footerLabel = implode('-', $footerParts);
          ?>
          <article class="print-sheet" data-footer-label="<?= h($footerLabel) ?>" data-footer-tone="<?= h($footerToneClass) ?>">
            <div class="sheet-head">
              <div class="sheet-title-row">
                <div class="sheet-title">
                  <h2><?= h(unit_type_label((string)($headerRow['unit_type'] ?? ''))) ?></h2>
                  <p><?= h($headerRow['unit_title']) ?><?php if (!empty($headerRow['unit_code'])): ?> (<?= h($headerRow['unit_code']) ?>)<?php endif; ?></p>
                </div>
              </div>
              <?php if (!empty($unitSourceMap[$unitRaId])): ?>
                <div class="source-row">
                  <?php foreach (array_slice(array_values($unitSourceMap[$unitRaId]), 0, 6) as $sourceRow): ?>
                    <span class="source-chip"><?= h($sourceRow['work_date']) ?> / <?= h($sourceRow['work_title']) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($flowTaskCount > 0): ?>
                <div class="task-flow-wrap">
                  <div class="task-flow-label">세부작업 흐름</div>
                  <div class="task-flow-list">
                    <?php foreach ($flowTaskNames as $flowIndex => $flowTaskName): ?>
                      <span class="task-flow-node"><?= h($flowTaskName) ?></span>
                      <?php if ($flowIndex < $flowTaskCount - 1): ?>
                        <span class="task-flow-arrow" aria-hidden="true">&rarr;</span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if ($evaluatorMetaText !== ''): ?>
                <div class="sheet-evaluator-row">
                  <div class="sheet-evaluator-text"><?= h($evaluatorMetaText) ?></div>
                </div>
              <?php endif; ?>
            </div>

            <div class="sheet-body">
              <?php if (empty($unitItems)): ?>
                <div class="empty-box">표시할 위험성평가 항목이 없습니다.</div>
              <?php else: ?>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th class="center">No</th>
                        <th>세부작업명</th>
                        <th>4M분류</th>
                        <th>위험요인</th>
                        <th>재해형태</th>
                        <th>상해결과</th>
                        <th>원인/위험상황</th>
                        <th>현재 위험도</th>
                        <th>현재조치사항</th>
                        <th>조치후위험도</th>
                        <th>추가개선대책</th>
                        <th>개선후위험도</th>
                        <th>비고</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($unitItems as $index => $item): ?>
                        <tr class="<?= $index % 2 === 0 ? 'row-odd' : '' ?>">
                          <td class="center"><?= h($item['sort_no'] !== null ? $item['sort_no'] : ($index + 1)) ?></td>
                          <td><?= h($item['task_name']) ?></td>
                          <td><?= h($item['hazard_4m_label'] ?? hazard_4m_label($item['hazard_4m'] ?? null)) ?></td>
                          <td><?= h($item['hazard_name']) ?></td>
                          <td><?= h($item['accident_type']) ?></td>
                          <td><?= h($item['injury_result']) ?></td>
                          <td><?= h($item['cause_text']) ?></td>
                          <td class="risk-box">
                            P <?= h($item['likelihood_before'] ?? '-') ?><br>
                            S <?= h($item['severity_before'] ?? '-') ?><br>
                            R <?= h($item['risk_score_before'] ?? '-') ?>
                          </td>
                          <td><?= h($item['current_control_text']) ?></td>
                          <td class="risk-box">
                            P <?= h($item['likelihood_current'] ?? '-') ?><br>
                            S <?= h($item['severity_current'] ?? '-') ?><br>
                            R <?= h($item['risk_score_current'] ?? '-') ?>
                          </td>
                          <td><?= h($item['additional_control_text']) ?></td>
                          <td class="risk-box">
                            P <?= h($item['likelihood_after'] ?? '-') ?><br>
                            S <?= h($item['severity_after'] ?? '-') ?><br>
                            R <?= h($item['risk_score_after'] ?? '-') ?>
                          </td>
                          <td><?= h($item['remark']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <?php if ($footerLabel !== ''): ?>
                      <tfoot class="table-print-footer">
                        <tr>
                          <td colspan="13" class="table-print-footer-cell">
                            <span class="footer-badge <?= h($footerToneClass) ?>"><?= h($footerLabel) ?></span>
                          </td>
                        </tr>
                      </tfoot>
                    <?php endif; ?>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <section class="title-print-list">
      <div class="title-print-sheet">
        <div class="title-print-source">
      <h2>단위 위험성평가 제목 출력</h2>
      <p>현재 화면에 포함된 단위 위험성평가의 제목만 따로 모아 인쇄합니다.</p>
      <?php if (empty($headerRows)): ?>
        <div class="empty-box">출력할 단위 위험성평가가 없습니다.</div>
      <?php else: ?>
        <div class="title-print-columns">
          <?php foreach ($headerRows as $index => $headerRow): ?>
            <?php
              $titleText = trim((string)($headerRow['unit_title'] ?? ''));
              $codeText = trim((string)($headerRow['unit_code'] ?? ''));
              $processText = trim((string)($headerRow['process_name'] ?? ''));
            ?>
            <div class="title-print-entry">
              <div class="title-print-entry-line">
                <div class="title-print-no"><?= (int)($index + 1) ?>.</div>
                <div class="title-print-body">
                  <div class="title-print-title"><?= h($titleText !== '' ? $titleText : '제목 없음') ?><?php if ($codeText !== ''): ?> (<?= h($codeText) ?>)<?php endif; ?></div>
                  <?php if ($processText !== ''): ?>
                    <div class="title-print-detail">공정명: <?= h($processText) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
          <?php endif; ?>
        </div>
        <div class="title-print-pages" aria-hidden="true">
          <?php if (empty($headerRows)): ?>
            <section class="title-print-page">
              <div class="empty-box title-print-empty-box">출력할 단위 위험성평가가 없습니다.</div>
            </section>
          <?php else: ?>
            <?php foreach ($titlePrintPages as $pageColumns): ?>
              <section class="title-print-page">
                <div class="title-print-page-columns">
                  <?php foreach ($pageColumns as $columnEntries): ?>
                    <div class="title-print-page-column">
                      <?php foreach ($columnEntries as $entry): ?>
                        <?php
                          $titleHeader = $entry['header'];
                          $titleText = trim((string)($titleHeader['unit_title'] ?? ''));
                          $codeText = trim((string)($titleHeader['unit_code'] ?? ''));
                          $processText = trim((string)($titleHeader['process_name'] ?? ''));
                        ?>
                        <div class="title-print-entry">
                          <div class="title-print-entry-line">
                            <div class="title-print-no"><?= (int)$entry['display_index'] ?>.</div>
                            <div class="title-print-body">
                              <div class="title-print-title"><?= h($titleText !== '' ? $titleText : '제목 없음') ?><?php if ($codeText !== ''): ?> (<?= h($codeText) ?>)<?php endif; ?></div>
                              <?php if ($processText !== ''): ?>
                                <div class="title-print-detail">공정명: <?= h($processText) ?></div>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
<script>
    (function () {
      const TABLE_OVERFLOW_PX = 2;
      const TITLE_PAGE_OVERFLOW_PX = 2;
      const defaultTitle = document.title;
      const printPageStyleId = 'dynamic-print-page-style';

      function setPrintPageStyle(mode) {
        let styleEl = document.getElementById(printPageStyleId);
        if (!styleEl) {
          styleEl = document.createElement('style');
          styleEl.id = printPageStyleId;
          document.head.appendChild(styleEl);
        }

        styleEl.textContent = mode === 'title-only'
          ? '@page { size: A4 portrait; margin: 0; }'
          : '@page { size: A4 landscape; margin: 6mm; }';
      }

      setPrintPageStyle('full');

      window.startUnitRaPrint = function (mode) {
        document.body.setAttribute('data-print-mode', mode);
        document.title = mode === 'title-only' ? '단위 위험성평가 제목 출력' : defaultTitle;
        setPrintPageStyle(mode);

        if (mode === 'title-only') {
          window.requestAnimationFrame(function () {
            window.print();
          });
          return;
        }

        paginateSheetsForChromePrint();
        window.requestAnimationFrame(function () {
          window.print();
        });
      };

      window.addEventListener('afterprint', function () {
        document.body.removeAttribute('data-print-mode');
        document.title = defaultTitle;
        setPrintPageStyle('full');
      });

      function isPrintPageOverflowing(page) {
        if (!page) {
          return false;
        }
        return page.scrollHeight > (page.clientHeight + TABLE_OVERFLOW_PX);
      }

      function isTitleColumnOverflowing(column) {
        return column.scrollHeight > (column.clientHeight + TITLE_PAGE_OVERFLOW_PX);
      }

      function buildTitlePrintPage(columnEntryNodes, emptyNode) {
        const page = document.createElement('section');
        page.className = 'title-print-page';

        if (emptyNode) {
          const emptyClone = emptyNode.cloneNode(true);
          emptyClone.classList.add('title-print-empty-box');
          page.appendChild(emptyClone);
          return page;
        }

        const grid = document.createElement('div');
        grid.className = 'title-print-page-columns';

        columnEntryNodes.forEach(function (entryNodes) {
          const column = document.createElement('div');
          column.className = 'title-print-page-column';
          entryNodes.forEach(function (entryNode) {
            column.appendChild(entryNode.cloneNode(true));
          });
          grid.appendChild(column);
        });

        page.appendChild(grid);
        return page;
      }

      function paginateTitleListForPrint() {
        const titleList = document.querySelector('.title-print-list');
        if (!titleList || titleList.dataset.paginatedForPrint === 'true') {
          return;
        }

        const pagesRoot = titleList.querySelector('.title-print-pages');
        const sourceRoot = titleList.querySelector('.title-print-source');
        if (!pagesRoot || !sourceRoot) {
          return;
        }

        const sourceEntries = Array.from(sourceRoot.querySelectorAll('.title-print-entry'));
        const sourceEmptyBox = sourceRoot.querySelector('.empty-box');
        pagesRoot.innerHTML = '';

        if (sourceEntries.length === 0) {
          if (sourceEmptyBox) {
            pagesRoot.appendChild(buildTitlePrintPage([], sourceEmptyBox));
          }
          titleList.dataset.paginatedForPrint = 'true';
          return;
        }

        const measureRoot = document.createElement('div');
        measureRoot.className = 'print-measure-root print-title-measure-root';
        document.body.appendChild(measureRoot);

        const pageChunks = [];
        let currentChunk = [[], [], []];
        let currentColumnIndex = 0;
        let measurePage = buildTitlePrintPage([[], [], []]);
        measureRoot.appendChild(measurePage);
        let measureColumns = Array.from(measurePage.querySelectorAll('.title-print-page-column'));

        sourceEntries.forEach(function (sourceEntry) {
          let placed = false;

          while (!placed) {
            const targetColumn = measureColumns[currentColumnIndex];
            const probeEntry = sourceEntry.cloneNode(true);
            targetColumn.appendChild(probeEntry);

            if (isTitleColumnOverflowing(targetColumn) && targetColumn.children.length > 1) {
              probeEntry.remove();
              currentColumnIndex += 1;

              if (currentColumnIndex > 2) {
                pageChunks.push(currentChunk);
                measurePage.remove();

                currentChunk = [[], [], []];
                currentColumnIndex = 0;
                measurePage = buildTitlePrintPage([[], [], []]);
                measureRoot.appendChild(measurePage);
                measureColumns = Array.from(measurePage.querySelectorAll('.title-print-page-column'));
              }

              continue;
            }

            currentChunk[currentColumnIndex].push(sourceEntry.cloneNode(true));
            placed = true;
          }
        });

        measurePage.remove();
        measureRoot.remove();

        if (currentChunk.some(function (columnEntries) { return columnEntries.length > 0; })) {
          pageChunks.push(currentChunk);
        }

        const fragment = document.createDocumentFragment();
        pageChunks.forEach(function (chunkEntries) {
          fragment.appendChild(buildTitlePrintPage(chunkEntries));
        });

        pagesRoot.appendChild(fragment);
        titleList.dataset.paginatedForPrint = 'true';
      }

      function buildPaginatedPage(sourceSheet, footerLabel, footerToneClass, headerHtml, tableHeadHtml, rowNodes, isFirstPage) {
        const page = document.createElement('article');
        page.className = 'print-sheet';
        page.setAttribute('data-footer-label', footerLabel);
        page.setAttribute('data-footer-tone', footerToneClass);

        const head = document.createElement('div');
        head.className = 'sheet-head';
        head.innerHTML = headerHtml;
        if (!isFirstPage) {
          const taskFlowWrap = head.querySelector('.task-flow-wrap');
          if (taskFlowWrap) {
            taskFlowWrap.remove();
          }
          const evaluatorRow = head.querySelector('.sheet-evaluator-row');
          if (evaluatorRow) {
            evaluatorRow.remove();
          }
        }

        const body = document.createElement('div');
        body.className = 'sheet-body';

        const tableWrap = document.createElement('div');
        tableWrap.className = 'table-wrap';

        const table = document.createElement('table');
        table.innerHTML = tableHeadHtml + '<tbody></tbody>';

        const tbody = table.querySelector('tbody');
        rowNodes.forEach(function (rowNode) {
          tbody.appendChild(rowNode.cloneNode(true));
        });

        tableWrap.appendChild(table);
        body.appendChild(tableWrap);

        if (footerLabel !== '') {
          const footer = document.createElement('div');
          footer.className = 'sheet-page-footer';

          const footerText = document.createElement('span');
          footerText.className = `sheet-page-footer-text footer-badge ${footerToneClass}`.trim();
          footerText.textContent = footerLabel;

          footer.appendChild(footerText);
          body.appendChild(footer);
        }

        page.appendChild(head);
        page.appendChild(body);
        return page;
      }

      function splitSheetIntoPages(sourceSheet, measureRoot) {
        const header = sourceSheet.querySelector('.sheet-head');
        const table = sourceSheet.querySelector('table');
        const emptyBox = sourceSheet.querySelector('.empty-box');
        const footerLabel = (sourceSheet.getAttribute('data-footer-label') || '').trim();
        const footerToneClass = (sourceSheet.getAttribute('data-footer-tone') || 'footer-tone-default').trim();

        if (!header) {
          return [sourceSheet.cloneNode(true)];
        }

        if (!table || emptyBox) {
          const clonedSheet = sourceSheet.cloneNode(true);
          const fallbackFooter = clonedSheet.querySelector('.sheet-page-footer');
          if (fallbackFooter) {
            fallbackFooter.remove();
          }
          if (footerLabel !== '') {
            const footer = document.createElement('div');
            footer.className = 'sheet-page-footer';
            footer.innerHTML = '<span class="sheet-page-footer-text footer-badge"></span>';
            footer.querySelector('.sheet-page-footer-text').classList.add(footerToneClass);
            footer.querySelector('.sheet-page-footer-text').textContent = footerLabel;
            const body = clonedSheet.querySelector('.sheet-body');
            if (body) {
              body.appendChild(footer);
            }
          }
          return [clonedSheet];
        }

        const thead = table.querySelector('thead');
        const rows = Array.from(table.querySelectorAll('tbody > tr'));
        if (!thead || rows.length === 0) {
          return [sourceSheet.cloneNode(true)];
        }

        const headerHtml = header.innerHTML;
        const tableHeadHtml = thead.outerHTML;
        const pageChunks = [];

        let currentChunk = [];
        let currentPageIsFirst = true;
        let measurePage = buildPaginatedPage(sourceSheet, footerLabel, footerToneClass, headerHtml, tableHeadHtml, [], currentPageIsFirst);
        measureRoot.appendChild(measurePage);
        let measureTbody = measurePage.querySelector('tbody');

        rows.forEach(function (sourceRow) {
          const probeRow = sourceRow.cloneNode(true);
          measureTbody.appendChild(probeRow);

          if (isPrintPageOverflowing(measurePage) && currentChunk.length > 0) {
            probeRow.remove();
            pageChunks.push(currentChunk);
            measurePage.remove();

            currentChunk = [];
            currentPageIsFirst = false;
            measurePage = buildPaginatedPage(sourceSheet, footerLabel, footerToneClass, headerHtml, tableHeadHtml, [], currentPageIsFirst);
            measureRoot.appendChild(measurePage);
            measureTbody = measurePage.querySelector('tbody');

            const firstRowOnNextPage = sourceRow.cloneNode(true);
            measureTbody.appendChild(firstRowOnNextPage);
            currentChunk.push(sourceRow.cloneNode(true));
          } else {
            currentChunk.push(sourceRow.cloneNode(true));
          }
        });

        measurePage.remove();

        if (currentChunk.length > 0) {
          pageChunks.push(currentChunk);
        }

        return pageChunks.map(function (chunkRows, pageIndex) {
          return buildPaginatedPage(sourceSheet, footerLabel, footerToneClass, headerHtml, tableHeadHtml, chunkRows, pageIndex === 0);
        });
      }

      function paginateSheetsForChromePrint() {
        const sheetList = document.querySelector('.sheet-list');
        if (!sheetList || sheetList.dataset.paginatedForChrome === 'true') {
          return;
        }

        const sourceSheets = Array.from(sheetList.querySelectorAll('.print-sheet'));
        if (sourceSheets.length === 0) {
          return;
        }

        const measureRoot = document.createElement('div');
        measureRoot.className = 'print-measure-root';
        document.body.appendChild(measureRoot);

        const fragment = document.createDocumentFragment();
        sourceSheets.forEach(function (sourceSheet) {
          splitSheetIntoPages(sourceSheet, measureRoot).forEach(function (page) {
            fragment.appendChild(page);
          });
        });

        measureRoot.remove();
        sheetList.innerHTML = '';
        sheetList.appendChild(fragment);
        sheetList.dataset.paginatedForChrome = 'true';
      }

      window.addEventListener('load', paginateSheetsForChromePrint, { once: true });
    })();
  </script>
</body>
</html>
