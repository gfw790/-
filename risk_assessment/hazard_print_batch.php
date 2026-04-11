<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensureWorkerHazardSelectionTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_report_worker_hazard_selection (
            worker_selection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_login_id VARCHAR(100) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            unit_ra_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (worker_selection_id),
            UNIQUE KEY uk_work_report_worker_hazard_selection (report_id, user_login_id, item_id),
            KEY idx_work_report_worker_hazard_selection_report (report_id),
            KEY idx_work_report_worker_hazard_selection_user (user_login_id),
            KEY idx_work_report_worker_hazard_selection_unit (unit_ra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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

function resolveSelectedRiskAssessments(PDO $pdo, array $report): array
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

    if (empty($selected)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $stmt = $pdo->prepare("
        SELECT unit_ra_id, unit_code, unit_title, unit_type, sort_no
        FROM unit_ra_header
        WHERE unit_ra_id IN ($placeholders)
        ORDER BY sort_no ASC, unit_ra_id ASC
    ");
    $stmt->execute(array_values($selected));

    $assessments = $stmt->fetchAll() ?: [];
    $typeOrder = [
        'major_work' => 1,
        'tool' => 2,
        'env' => 3,
    ];

    usort($assessments, static function (array $left, array $right) use ($baseUnitId, $typeOrder): int {
        $leftRank = ((int)($left['unit_ra_id'] ?? 0) === $baseUnitId)
            ? 0
            : ($typeOrder[(string)($left['unit_type'] ?? '')] ?? 9);
        $rightRank = ((int)($right['unit_ra_id'] ?? 0) === $baseUnitId)
            ? 0
            : ($typeOrder[(string)($right['unit_type'] ?? '')] ?? 9);

        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        $leftSort = (int)($left['sort_no'] ?? 0);
        $rightSort = (int)($right['sort_no'] ?? 0);
        if ($leftSort !== $rightSort) {
            return $leftSort <=> $rightSort;
        }

        return ((int)($left['unit_ra_id'] ?? 0)) <=> ((int)($right['unit_ra_id'] ?? 0));
    });

    return $assessments;
}

function buildTaskSteps(PDO $pdo, int $unitRaId, int $limit = 5): array
{
    if ($unitRaId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT task_name
        FROM unit_ra_item
        WHERE unit_ra_id = :unit_ra_id
          AND use_yn = 'Y'
        ORDER BY sort_no ASC, item_id ASC
    ");
    $stmt->execute([':unit_ra_id' => $unitRaId]);

    $steps = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $taskName) {
        $taskName = trim((string)$taskName);
        if ($taskName === '' || $taskName === '공통' || isset($seen[$taskName])) {
            continue;
        }

        $seen[$taskName] = true;
        $steps[] = $taskName;
        if (count($steps) >= $limit) {
            break;
        }
    }

    return $steps;
}

function is_other_recommended_unit_type(string $unitType): bool
{
    return in_array($unitType, ['major_work', 'tool', 'env'], true);
}

function empty_recommended_hazard_groups(): array
{
    return [
        'work_related' => [],
        'other' => [],
    ];
}

function merge_recommended_hazard_rows(array $primaryRows, array $fallbackRows, int $limit): array
{
    $merged = [];
    $seen = [];

    foreach (array_merge($primaryRows, $fallbackRows) as $row) {
        $key = implode('|', [
            trim((string)($row['unit_title'] ?? '')),
            trim((string)($row['unit_code'] ?? '')),
            trim((string)($row['hazard_name'] ?? '')),
        ]);
        if ($key === '||' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[] = $row;
        if (count($merged) >= $limit) {
            break;
        }
    }

    return $merged;
}

function fetch_recommended_hazards_by_group(PDO $pdo, array $report, bool $includeOther, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $typeCondition = $includeOther
        ? "AND h.unit_type IN ('major_work', 'tool', 'env')"
        : "AND (h.unit_type IS NULL OR h.unit_type NOT IN ('major_work', 'tool', 'env'))";

    $recommendStmt = $pdo->prepare("
        SELECT
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_title,
            h.unit_code,
            h.unit_type,
            COUNT(*) AS selected_count,
            COUNT(DISTINCT s.user_login_id) AS worker_count
        FROM work_report_worker_hazard_selection s
        INNER JOIN work_report wr
            ON wr.report_id = s.report_id
        INNER JOIN unit_ra_item i
            ON i.item_id = s.item_id
        INNER JOIN unit_ra_header h
            ON h.unit_ra_id = s.unit_ra_id
        WHERE wr.work_title = :work_title
          AND wr.work_date = :work_date
          AND wr.work_place = :work_place
          {$typeCondition}
        GROUP BY
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_title,
            h.unit_code,
            h.unit_type
        ORDER BY selected_count DESC, worker_count DESC, i.hazard_name ASC
        LIMIT {$limit}
    ");
    $recommendStmt->execute([
        ':work_title' => (string)($report['work_title'] ?? ''),
        ':work_date' => (string)($report['work_date'] ?? ''),
        ':work_place' => (string)($report['work_place'] ?? ''),
    ]);

    return $recommendStmt->fetchAll() ?: [];
}

function fetch_fallback_hazards_by_group(PDO $pdo, array $riskAssessments, bool $includeOther, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $filteredAssessments = array_values(array_filter(
        $riskAssessments,
        static function (array $assessment) use ($includeOther): bool {
            $isOther = is_other_recommended_unit_type((string)($assessment['unit_type'] ?? ''));
            return $includeOther ? $isOther : !$isOther;
        }
    ));

    if (empty($filteredAssessments)) {
        return [];
    }

    $unitOrderMap = [];
    $unitIds = [];
    foreach ($filteredAssessments as $index => $assessment) {
        $unitId = (int)($assessment['unit_ra_id'] ?? 0);
        if ($unitId <= 0) {
            continue;
        }
        $unitOrderMap[$unitId] = $index;
        $unitIds[] = $unitId;
    }

    if (empty($unitIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $fallbackStmt = $pdo->prepare("
        SELECT
            i.hazard_name,
            i.accident_type,
            i.injury_result,
            i.current_control_text,
            i.likelihood_before,
            i.severity_before,
            i.risk_score_before,
            i.likelihood_current,
            i.severity_current,
            i.risk_score_current,
            h.unit_ra_id,
            h.unit_title,
            h.unit_code,
            h.unit_type,
            i.sort_no AS item_sort_no,
            i.item_id,
            0 AS selected_count,
            0 AS worker_count
        FROM unit_ra_item i
        INNER JOIN unit_ra_header h
            ON h.unit_ra_id = i.unit_ra_id
        WHERE i.use_yn = 'Y'
          AND i.unit_ra_id IN ($placeholders)
    ");
    $fallbackStmt->execute($unitIds);

    $rows = $fallbackStmt->fetchAll() ?: [];
    usort($rows, static function (array $left, array $right) use ($unitOrderMap): int {
        $leftOrder = $unitOrderMap[(int)($left['unit_ra_id'] ?? 0)] ?? 999;
        $rightOrder = $unitOrderMap[(int)($right['unit_ra_id'] ?? 0)] ?? 999;
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }

        $leftSort = (int)($left['item_sort_no'] ?? 0);
        $rightSort = (int)($right['item_sort_no'] ?? 0);
        if ($leftSort !== $rightSort) {
            return $leftSort <=> $rightSort;
        }

        return ((int)($left['item_id'] ?? 0)) <=> ((int)($right['item_id'] ?? 0));
    });

    return array_slice($rows, 0, $limit);
}

function buildRecommendedHazardGroups(PDO $pdo, array $report, array $riskAssessments): array
{
    $groups = empty_recommended_hazard_groups();
    $workLimit = 5;
    $otherLimit = 5;

    $recommendedWorkRows = fetch_recommended_hazards_by_group($pdo, $report, false, $workLimit);
    $recommendedOtherRows = fetch_recommended_hazards_by_group($pdo, $report, true, $otherLimit);

    $fallbackWorkRows = count($recommendedWorkRows) < $workLimit
        ? fetch_fallback_hazards_by_group($pdo, $riskAssessments, false, $workLimit)
        : [];
    $fallbackOtherRows = count($recommendedOtherRows) < $otherLimit
        ? fetch_fallback_hazards_by_group($pdo, $riskAssessments, true, $otherLimit)
        : [];

    $groups['work_related'] = merge_recommended_hazard_rows($recommendedWorkRows, $fallbackWorkRows, $workLimit);
    $groups['other'] = merge_recommended_hazard_rows($recommendedOtherRows, $fallbackOtherRows, $otherLimit);

    return $groups;
}

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$pdo = getDB();
ensureWorkerHazardSelectionTable($pdo);

$reportIds = [];
if (!empty($_GET['report_ids'])) {
    foreach (explode(',', (string)$_GET['report_ids']) as $reportId) {
        $reportId = (int)trim($reportId);
        if ($reportId > 0) {
            $reportIds[$reportId] = $reportId;
        }
    }
}

if (empty($reportIds)) {
    $reportRows = $pdo->query("
        SELECT
            wr.report_id,
            wr.unit_ra_id,
            wr.work_title,
            wr.work_date,
            wr.work_place,
            h.unit_code,
            h.unit_title
        FROM work_report wr
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = wr.unit_ra_id
        ORDER BY wr.work_date DESC, wr.report_id DESC
    ")->fetchAll() ?: [];
} else {
    $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            wr.report_id,
            wr.unit_ra_id,
            wr.work_title,
            wr.work_date,
            wr.work_place,
            h.unit_code,
            h.unit_title
        FROM work_report wr
        LEFT JOIN unit_ra_header h
            ON h.unit_ra_id = wr.unit_ra_id
        WHERE wr.report_id IN ($placeholders)
        ORDER BY wr.work_date DESC, wr.report_id DESC
    ");
    $stmt->execute(array_values($reportIds));
    $reportRows = $stmt->fetchAll() ?: [];
}

$participantStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_login_id)
    FROM work_report_worker_hazard_selection
    WHERE report_id = :report_id
");

$documents = [];
foreach ($reportRows as $reportRow) {
    $riskAssessments = resolveSelectedRiskAssessments($pdo, $reportRow);
    $openedAssessment = null;
    foreach ($riskAssessments as $assessment) {
        if ((int)($assessment['unit_ra_id'] ?? 0) === (int)($reportRow['unit_ra_id'] ?? 0)) {
            $openedAssessment = $assessment;
            break;
        }
    }
    if ($openedAssessment === null && !empty($riskAssessments)) {
        $openedAssessment = $riskAssessments[0];
    }

    $taskSteps = buildTaskSteps($pdo, (int)($reportRow['unit_ra_id'] ?? 0));
    $recommendedHazardGroups = buildRecommendedHazardGroups($pdo, $reportRow, $riskAssessments);
    $recommendedHazards = array_merge(
        $recommendedHazardGroups['work_related'],
        $recommendedHazardGroups['other']
    );

    $participantStmt->execute([':report_id' => (int)$reportRow['report_id']]);
    $participantCount = (int)$participantStmt->fetchColumn();

    $documents[] = [
        'report' => $reportRow,
        'participant_count' => $participantCount,
        'task_steps' => $taskSteps,
        'recommended_hazards' => $recommendedHazards,
        'recommended_hazard_groups' => $recommendedHazardGroups,
        'risk_assessments' => $riskAssessments,
    ];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>위험성평가 전체 출력 미리보기</title>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
<style>
  body {
    padding: 24px 18px 40px;
  }
  .shell {
    max-width: 1120px;
    margin: 0 auto;
  }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .identity {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .role-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 999px;
    background: #dce9f8;
    color: #1f4e79;
    font-size: 12px;
    font-weight: 700;
  }
  .actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
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
  }
  .btn-secondary {
    background: #fff;
    color: #486581;
    border: 1px solid #c8d8e8;
  }
  .btn-primary {
    background: linear-gradient(180deg, #2a6ca8 0%, #1f578d 100%);
    color: #fff;
    border: none;
  }
  .btn-title-print {
    background: #eef6ff;
    color: #1f578d;
    border: 1px solid #c7dcf0;
  }
  .intro {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #d9e5f0;
    border-radius: 24px;
    box-shadow: 0 20px 48px rgba(19, 47, 71, 0.10);
    padding: 22px 24px;
    margin-bottom: 18px;
  }
  .intro h1 {
    font-size: 30px;
    color: #16324a;
    line-height: 1.15;
    letter-spacing: -0.03em;
    margin-bottom: 10px;
  }
  .intro p {
    color: #52667a;
    line-height: 1.6;
  }
  .intro-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
  }
  .document-list {
    display: grid;
    gap: 18px;
  }
  .title-print-list {
    display: none;
    background: #fff;
    border: 1px solid #d3deea;
    border-radius: 24px;
    box-shadow: 0 18px 42px rgba(19, 47, 71, 0.10);
    padding: 28px 30px 30px;
  }
  .title-print-list h2 {
    font-size: 28px;
    color: #16324a;
    margin-bottom: 8px;
    letter-spacing: -0.03em;
  }
  .title-print-list > p {
    color: #52667a;
    line-height: 1.6;
    margin-bottom: 18px;
  }
  .title-print-columns {
    column-count: 1;
    column-gap: 28px;
    column-fill: balance;
    border-top: 2px solid #16324a;
  }
  .title-print-entry {
    break-inside: avoid;
    page-break-inside: avoid;
    padding: 10px 0 12px;
    border-bottom: 1px solid #d9e5f0;
  }
  .title-print-entry-line {
    display: flex;
    align-items: flex-start;
    gap: 10px;
  }
  .title-print-no {
    flex: 0 0 34px;
    text-align: right;
    color: #16324a;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.6;
  }
  .title-print-body {
    min-width: 0;
  }
  .title-print-title {
    color: #16324a;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.58;
    letter-spacing: -0.02em;
    word-break: keep-all;
  }
  .title-print-detail {
    margin-top: 4px;
    color: #52667a;
    font-size: 12px;
    line-height: 1.65;
  }
  .title-print-side {
    color: #52667a;
    font-size: 12px;
    line-height: 1.65;
    margin-top: 4px;
  }
  .title-print-sublist {
    margin-top: 7px;
    padding-top: 7px;
    padding-left: 44px;
    border-top: 1px dashed #d9e5f0;
    display: grid;
    gap: 2px;
  }
  .title-print-subitem {
    color: #44586c;
    font-size: 12px;
    line-height: 1.6;
    word-break: keep-all;
  }
  .title-print-subtag {
    display: inline-block;
    min-width: 60px;
    color: #245f97;
    font-weight: 700;
  }
  .print-sheet {
    background: #fff;
    border: 1px solid #d3deea;
    border-radius: 24px;
    box-shadow: 0 18px 42px rgba(19, 47, 71, 0.10);
    overflow: hidden;
  }
  .sheet-head {
    padding: 28px 30px 18px;
    border-bottom: 1px solid #dfe8f1;
    background: linear-gradient(180deg, #f8fbfe 0%, #ffffff 100%);
  }
  .sheet-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 18px;
  }
  .sheet-title h2 {
    font-size: 30px;
    color: #16324a;
    line-height: 1.1;
    letter-spacing: -0.04em;
    margin-bottom: 8px;
  }
  .sheet-title p {
    color: #52667a;
    font-size: 14px;
  }
  .sheet-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 14px;
    border-radius: 999px;
    background: #eaf3fb;
    color: #245f97;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
  }
  .meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .meta-item {
    border: 1px solid #d9e5f0;
    border-radius: 16px;
    background: #f7fbff;
    padding: 14px 16px;
  }
  .meta-item strong {
    display: block;
    margin-bottom: 6px;
    color: #6f8092;
    font-size: 12px;
  }
  .meta-item span {
    display: block;
    color: #16324a;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.45;
    word-break: keep-all;
  }
  .sheet-body {
    padding: 24px 30px 30px;
    display: grid;
    gap: 20px;
  }
  .section-card {
    border: 1px solid #d9e5f0;
    border-radius: 20px;
    background: #fbfdff;
    padding: 20px;
  }
  .section-card h3 {
    font-size: 18px;
    color: #16324a;
    margin-bottom: 14px;
    letter-spacing: -0.02em;
  }
  .step-flow {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 14px;
  }
  .step-item {
    position: relative;
    flex: 1 1 170px;
    min-width: 170px;
    border: 1px solid #d9e5f0;
    border-radius: 18px;
    background: #fff;
    padding: 14px 16px;
    box-shadow: 0 6px 18px rgba(19, 47, 71, 0.05);
  }
  .step-item:not(:last-child)::after {
    content: '>';
    position: absolute;
    right: -10px;
    top: 50%;
    transform: translateY(-50%);
    color: #88a7c3;
    font-size: 18px;
    font-weight: 700;
  }
  .step-label {
    display: block;
    margin-bottom: 6px;
    color: #6f8092;
    font-size: 11px;
    font-weight: 700;
  }
  .step-value {
    color: #16324a;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.55;
    word-break: keep-all;
  }
  .hazard-list {
    display: grid;
    gap: 12px;
  }
  .hazard-group {
    display: grid;
    gap: 12px;
  }
  .hazard-group + .hazard-group {
    margin-top: 18px;
  }
  .hazard-group-title {
    font-size: 16px;
    font-weight: 700;
    color: #245f97;
  }
  .hazard-item {
    display: grid;
    grid-template-columns: 56px 1fr;
    gap: 14px;
    align-items: start;
    border: 1px solid #d9e5f0;
    border-radius: 18px;
    background: #fff;
    padding: 14px;
  }
  .rank-badge {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: #245f97;
    color: #fff;
    font-size: 15px;
    font-weight: 700;
  }
  .hazard-title {
    font-size: 18px;
    color: #16324a;
    line-height: 1.35;
    letter-spacing: -0.02em;
    margin-bottom: 8px;
  }
  .chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
  }
  .chip {
    display: inline-flex;
    align-items: center;
    padding: 7px 10px;
    border-radius: 999px;
    background: #eaf3fb;
    color: #245f97;
    font-size: 12px;
    font-weight: 700;
  }
  .hazard-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
  .hazard-meta {
    border: 1px solid #d9e5f0;
    border-radius: 14px;
    background: #f7fbff;
    padding: 11px 12px;
    color: #3d5266;
    font-size: 13px;
    line-height: 1.55;
  }
  .hazard-meta strong {
    display: block;
    margin-bottom: 4px;
    color: #16324a;
  }
  .empty-box {
    border: 1px dashed #c9d7e5;
    border-radius: 16px;
    background: #fbfdff;
    padding: 28px 20px;
    text-align: center;
    color: #6f8092;
  }
  @page {
    size: A4;
    margin: 14mm;
  }
  @media print {
    body {
      background: #fff;
      padding: 0;
    }
    .topbar,
    .intro {
      display: none !important;
    }
    .title-print-list {
      box-shadow: none;
    }
    body[data-print-mode="title-only"] .document-list {
      display: none !important;
    }
    body[data-print-mode="title-only"] .title-print-list {
      display: block !important;
      border: none;
      border-radius: 0;
      padding: 0;
    }
    body[data-print-mode="title-only"] .title-print-columns {
      column-count: 2;
      column-gap: 24px;
    }
    body:not([data-print-mode="title-only"]) .title-print-list {
      display: none !important;
    }
    .document-list {
      gap: 0;
    }
    .print-sheet {
      border: none;
      border-radius: 0;
      box-shadow: none;
      margin: 0;
      page-break-after: always;
      break-after: page;
    }
    .print-sheet:last-child {
      page-break-after: auto;
      break-after: auto;
    }
    .sheet-head,
    .sheet-body,
    .section-card,
    .meta-item,
    .hazard-item,
    .hazard-meta,
    .step-item {
      box-shadow: none;
    }
  }
  @media (max-width: 900px) {
    .meta-grid,
    .hazard-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 720px) {
    body {
      padding: 18px 12px 28px;
    }
    .sheet-head,
    .sheet-body {
      padding-left: 18px;
      padding-right: 18px;
    }
    .sheet-title-row {
      flex-direction: column;
      align-items: flex-start;
    }
    .meta-grid,
    .hazard-grid,
    .hazard-item {
      grid-template-columns: 1fr;
    }
    .step-item {
      min-width: 0;
      flex-basis: 100%;
    }
    .step-item:not(:last-child)::after {
      display: none;
    }
    .title-print-sublist {
      padding-left: 0;
    }
  }
</style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div class="identity">
        <span class="role-badge"><?= h($user['role_label']) ?></span>
        <span><?= h(auth_display_name($user)) ?></span>
      </div>
      <div class="actions">
        <a class="btn-secondary" href="hazard_review.php">목록으로</a>
        <button type="button" class="btn-secondary btn-title-print" onclick="startHazardPrint('title-only')">제목만 인쇄</button>
        <button type="button" class="btn-primary" onclick="startHazardPrint('full')">인쇄하기</button>
      </div>
    </div>

    <section class="intro">
      <h1>위험성평가 전체 출력 미리보기</h1>
      <p>현재 저장된 위험성평가서를 한 번에 종이 출력할 수 있는 형태로 정리한 화면입니다. 실제 인쇄 시에는 보고서마다 페이지가 자동으로 나뉩니다.</p>
      <div class="intro-actions">
        <button type="button" class="btn-secondary btn-title-print" onclick="startHazardPrint('title-only')">제목만 인쇄</button>
        <button type="button" class="btn-primary" onclick="startHazardPrint('full')">전체 인쇄</button>
      </div>
    </section>

    <div class="document-list">
      <?php if (empty($documents)): ?>
        <div class="empty-box">출력할 위험성평가서가 없습니다.</div>
      <?php else: ?>
        <?php foreach ($documents as $document): ?>
          <?php $report = $document['report']; ?>
          <article class="print-sheet">
            <div class="sheet-head">
              <div class="sheet-title-row">
                <div class="sheet-title">
                  <h2>위험성평가서</h2>
                  <p><?= h($report['work_date']) ?> / <?= h($report['work_title']) ?> / <?= h($report['work_place']) ?></p>
                </div>
                <div class="sheet-badge">
                  <?= h($report['unit_title'] ?: '작업유형') ?><?php if (!empty($report['unit_code'])): ?> (<?= h($report['unit_code']) ?>)<?php endif; ?>
                </div>
              </div>
              <div class="meta-grid">
                <div class="meta-item">
                  <strong>날짜</strong>
                  <span><?= h($report['work_date']) ?></span>
                </div>
                <div class="meta-item">
                  <strong>작업명</strong>
                  <span><?= h($report['work_title']) ?></span>
                </div>
                <div class="meta-item">
                  <strong>작업장소</strong>
                  <span><?= h($report['work_place']) ?></span>
                </div>
                <div class="meta-item">
                  <strong>참여자</strong>
                  <span><?= h($document['participant_count']) ?>명</span>
                </div>
              </div>
            </div>

            <div class="sheet-body">
              <section class="section-card">
                <h3>작업순서</h3>
                <?php if (empty($document['task_steps'])): ?>
                  <div class="empty-box">표시할 작업순서가 없습니다.</div>
                <?php else: ?>
                  <div class="step-flow">
                    <?php foreach ($document['task_steps'] as $index => $taskStep): ?>
                      <div class="step-item">
                        <span class="step-label">STEP <?= (int)($index + 1) ?></span>
                        <div class="step-value"><?= h($taskStep) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>

              <section class="section-card">
                <h3>금일 주요 위험요소</h3>
                <?php if (empty($document['recommended_hazards'])): ?>
                  <div class="empty-box">표시할 위험요소가 없습니다.</div>
                <?php else: ?>
                  <?php
                  $hazardSections = [
                      'work_related' => '작업관련 5가지',
                      'other' => '그 외 5가지 (중대재해, 위험공구, 환경)',
                  ];
                  ?>
                  <?php foreach ($hazardSections as $sectionKey => $sectionTitle): ?>
                    <div class="hazard-group">
                      <div class="hazard-group-title"><?= h($sectionTitle) ?></div>
                      <?php if (empty($document['recommended_hazard_groups'][$sectionKey] ?? [])): ?>
                        <div class="empty-box">표시할 위험요소가 없습니다.</div>
                      <?php else: ?>
                        <div class="hazard-list">
                          <?php foreach (($document['recommended_hazard_groups'][$sectionKey] ?? []) as $index => $hazard): ?>
                            <div class="hazard-item">
                              <div class="rank-badge"><?= (int)($index + 1) ?></div>
                              <div>
                                <div class="hazard-title"><?= h($hazard['hazard_name']) ?></div>
                                <div class="chip-row">
                                  <span class="chip"><?= h($hazard['unit_title']) ?><?php if (!empty($hazard['unit_code'])): ?> (<?= h($hazard['unit_code']) ?>)<?php endif; ?></span>
                                  <span class="chip">추천 <?= h($hazard['selected_count'] ?? 0) ?>회</span>
                                  <span class="chip">참여 <?= h($hazard['worker_count'] ?? 0) ?>명</span>
                                </div>
                                <div class="hazard-grid">
                                  <?php if (!empty($hazard['accident_type'])): ?>
                                    <div class="hazard-meta"><strong>재해형태</strong><?= h($hazard['accident_type']) ?></div>
                                  <?php endif; ?>
                                  <?php if (!empty($hazard['injury_result'])): ?>
                                    <div class="hazard-meta"><strong>상해결과</strong><?= h($hazard['injury_result']) ?></div>
                                  <?php endif; ?>
                                  <div class="hazard-meta">
                                    <strong>현재 위험도</strong>
                                    P <?= h($hazard['likelihood_before'] ?? '-') ?> / S <?= h($hazard['severity_before'] ?? '-') ?> / R <?= h($hazard['risk_score_before'] ?? '-') ?>
                                  </div>
                                  <div class="hazard-meta">
                                    <strong>조치후위험도</strong>
                                    P <?= h($hazard['likelihood_current'] ?? '-') ?> / S <?= h($hazard['severity_current'] ?? '-') ?> / R <?= h($hazard['risk_score_current'] ?? '-') ?>
                                  </div>
                                  <?php if (!empty($hazard['current_control_text'])): ?>
                                    <div class="hazard-meta" style="grid-column: 1 / -1;">
                                      <strong>현재조치사항</strong>
                                      <?= h($hazard['current_control_text']) ?>
                                    </div>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </section>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <section class="title-print-list">
      <h2>위험성평가 제목 출력</h2>
      <p>선택된 위험성평가서의 제목 정보만 모아 인쇄하는 전용 레이아웃입니다.</p>
      <?php if (empty($documents)): ?>
        <div class="empty-box">출력할 위험성평가서가 없습니다.</div>
      <?php else: ?>
        <div class="title-print-columns">
          <?php foreach ($documents as $index => $document): ?>
            <?php $report = $document['report']; ?>
            <div class="title-print-entry">
              <div class="title-print-entry-line">
                <div class="title-print-no"><?= (int)($index + 1) ?>.</div>
                <div class="title-print-body">
                  <div class="title-print-title"><?= h($report['work_title'] ?: '작업명 미입력') ?></div>
                  <div class="title-print-detail"><?= h($report['work_date']) ?> / <?= h($report['work_place']) ?></div>
                  <div class="title-print-side">
                    <?= h($report['unit_title'] ?: '작업유형') ?><?php if (!empty($report['unit_code'])): ?> (<?= h($report['unit_code']) ?>)<?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="title-print-sublist">
                <?php if (!empty($document['risk_assessments'])): ?>
                  <?php foreach ($document['risk_assessments'] as $assessment): ?>
                    <?php
                      $isMainAssessment = (int)($assessment['unit_ra_id'] ?? 0) === (int)($report['unit_ra_id'] ?? 0);
                      $assessmentTitle = trim((string)($assessment['unit_title'] ?? ''));
                      $assessmentCode = trim((string)($assessment['unit_code'] ?? ''));
                    ?>
                    <div class="title-print-subitem">
                      <span class="title-print-subtag"><?= $isMainAssessment ? '마스터' : '서브' ?></span>
                      <?= h($assessmentTitle !== '' ? $assessmentTitle : '평가명 없음') ?><?php if ($assessmentCode !== ''): ?> (<?= h($assessmentCode) ?>)<?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="title-print-subitem">
                    <span class="title-print-subtag">마스터</span>
                    <?= h($report['unit_title'] ?: '작업유형') ?><?php if (!empty($report['unit_code'])): ?> (<?= h($report['unit_code']) ?>)<?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <script>
    (function () {
      const defaultTitle = document.title;

      window.startHazardPrint = function (mode) {
        document.body.setAttribute('data-print-mode', mode);
        document.title = mode === 'title-only' ? '위험성평가 제목 출력' : defaultTitle;
        window.print();
      };

      window.addEventListener('afterprint', function () {
        document.body.removeAttribute('data-print-mode');
        document.title = defaultTitle;
      });
    }());
  </script>
</body>
</html>
