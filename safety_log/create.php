<?php
require_once __DIR__ . '/../../risk_server/db_config.php';

function h($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function restructureFilesArray(array $files): array
{
    $result = [];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    foreach ($files['name'] as $index => $fieldValues) {
        foreach ($fieldValues as $fieldName => $value) {
            $result[$index][$fieldName] = [
                'name' => $files['name'][$index][$fieldName] ?? '',
                'type' => $files['type'][$index][$fieldName] ?? '',
                'tmp_name' => $files['tmp_name'][$index][$fieldName] ?? '',
                'error' => $files['error'][$index][$fieldName] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index][$fieldName] ?? 0,
            ];
        }
    }

    return $result;
}

function weatherCodeToLabel(string $code): string
{
    $map = [
        'CLEAR' => '맑음',
        'MOSTLY_CLEAR' => '대체로 맑음',
        'PARTLY_CLOUDY' => '구름많음',
        'MOSTLY_CLOUDY' => '대체로 흐림',
        'CLOUDY' => '흐림',
        'RAIN' => '비',
        'SNOW' => '눈',
        'RAIN_SNOW' => '비/눈',
        'SHOWER' => '소나기',
    ];

    return $map[$code] ?? $code;
}

function formatWeatherText(array $weatherInfo): string
{
    $parts = [];

    $code = trim((string)($weatherInfo['code'] ?? ''));
    if ($code !== '') {
        $parts[] = weatherCodeToLabel($code);
    }

    $tMin = $weatherInfo['tMin'] ?? null;
    $tMax = $weatherInfo['tMax'] ?? null;
    if ($tMin !== null && $tMax !== null) {
        $parts[] = sprintf('%s~%s℃', (string)(int)round((float)$tMin), (string)(int)round((float)$tMax));
    }

    if (isset($weatherInfo['pop']) && $weatherInfo['pop'] !== '' && $weatherInfo['pop'] !== null) {
        $parts[] = '강수확률 ' . (int)$weatherInfo['pop'] . '%';
    }

    return implode(' / ', $parts);
}

function loadCalendarWeatherMap(): array
{
    $weatherFile = __DIR__ . '/../calendar/weather_cache.json';
    if (!is_file($weatherFile)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($weatherFile), true);
    if (!is_array($decoded) || !isset($decoded['weather']) || !is_array($decoded['weather'])) {
        return [];
    }

    return $decoded['weather'];
}

function buildWorkOptionKey(string $workTitle, string $workPlace, string $teamName): string
{
    return md5(trim($workTitle) . "\n" . trim($workPlace) . "\n" . trim($teamName));
}

function buildReportWorkOptionKey($reportId): string
{
    return 'report_' . (int)$reportId;
}

function extractWorkTypeLabel(string $unitTitle): string
{
    $unitTitle = trim($unitTitle);
    return $unitTitle;
}

function addDetailOption(array &$optionMap, string $workKey, string $workType, string $processName): void
{
    $workType = trim($workType);
    $processName = trim($processName);
    if ($workType === '') {
        return;
    }

    if (!isset($optionMap[$workKey])) {
        $optionMap[$workKey] = [];
    }
    if (!isset($optionMap[$workKey][$workType])) {
        $optionMap[$workKey][$workType] = [];
    }
    if ($processName !== '') {
        $optionMap[$workKey][$workType][$processName] = $processName;
    }
}

function collectTaskNamesByUnitIds(PDO $pdo, array $unitIds): array
{
    $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn($unitId) => $unitId > 0)));
    if (empty($unitIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = $pdo->prepare(
                "SELECT unit_ra_id, task_name, task_code
         FROM unit_ra_item
         WHERE use_yn = 'Y'
           AND unit_ra_id IN ($placeholders)
           AND task_name IS NOT NULL
           AND task_name <> ''
                 ORDER BY unit_ra_id ASC,
                                    CASE WHEN task_code IS NULL OR task_code = '' THEN 1 ELSE 0 END ASC,
                                    task_code ASC,
                                    sort_no ASC,
                                    item_id ASC"
    );
    $stmt->execute($unitIds);

    $taskNamesByUnitId = [];
    foreach ($stmt->fetchAll() as $row) {
        $unitId = (int)($row['unit_ra_id'] ?? 0);
        $taskName = trim((string)($row['task_name'] ?? ''));
        if ($unitId <= 0 || $taskName === '') {
            continue;
        }

        if (!isset($taskNamesByUnitId[$unitId])) {
            $taskNamesByUnitId[$unitId] = [];
        }
        $taskNamesByUnitId[$unitId][$taskName] = $taskName;
    }

    return $taskNamesByUnitId;
}

function extractPreventionMeasureLines(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
    $measures = [];

    foreach ($lines as $line) {
        $line = trim((string)$line);
        $line = preg_replace('/^[\-•·\*\d\)\.\s]+/u', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $measures[$line] = $line;
    }

    if (empty($measures)) {
        $measures[$text] = $text;
    }

    return array_values($measures);
}

function collectPreventionMeasuresByUnitIds(PDO $pdo, array $unitIds): array
{
    $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn($unitId) => $unitId > 0)));
    if (empty($unitIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT unit_ra_id, task_name, current_control_text, additional_control_text, task_code, sort_no, item_id
         FROM unit_ra_item
         WHERE use_yn = 'Y'
           AND unit_ra_id IN ($placeholders)
           AND task_name IS NOT NULL
           AND task_name <> ''
         ORDER BY unit_ra_id ASC,
                  CASE WHEN task_code IS NULL OR task_code = '' THEN 1 ELSE 0 END ASC,
                  task_code ASC,
                  sort_no ASC,
                  item_id ASC"
    );
    $stmt->execute($unitIds);

    $measuresByUnitId = [];
    foreach ($stmt->fetchAll() as $row) {
        $unitId = (int)($row['unit_ra_id'] ?? 0);
        $taskName = trim((string)($row['task_name'] ?? ''));
        if ($unitId <= 0 || $taskName === '') {
            continue;
        }

        if (!isset($measuresByUnitId[$unitId])) {
            $measuresByUnitId[$unitId] = [];
        }
        if (!isset($measuresByUnitId[$unitId][$taskName])) {
            $measuresByUnitId[$unitId][$taskName] = [];
        }

        foreach (['current_control_text', 'additional_control_text'] as $fieldName) {
            foreach (extractPreventionMeasureLines((string)($row[$fieldName] ?? '')) as $measureLine) {
                $measuresByUnitId[$unitId][$taskName][$measureLine] = $measureLine;
            }
        }
    }

    return $measuresByUnitId;
}

function riskValueLabel($likelihood, $severity, $score): string
{
    $parts = [];
    if ($likelihood !== null && $likelihood !== '') {
        $parts[] = '가능성 ' . (int)$likelihood;
    }
    if ($severity !== null && $severity !== '') {
        $parts[] = '중대성 ' . (int)$severity;
    }
    if ($score !== null && $score !== '') {
        $parts[] = '위험성 ' . (int)$score;
    }

    return implode(' / ', $parts);
}

function collectPreventionSummaryByUnitIds(PDO $pdo, array $unitIds): array
{
    $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn($unitId) => $unitId > 0)));
    if (empty($unitIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT item_id, unit_ra_id, task_code, task_name, hazard_name, accident_type, injury_result,
                cause_text, current_control_text, additional_control_text,
                likelihood_before, severity_before, risk_score_before,
                likelihood_current, severity_current, risk_score_current,
                likelihood_after, severity_after, risk_score_after,
                improvement_due_date, remark, sort_no
         FROM unit_ra_item
         WHERE use_yn = 'Y'
           AND unit_ra_id IN ($placeholders)
           AND task_name IS NOT NULL
           AND task_name <> ''
         ORDER BY unit_ra_id ASC,
                  CASE WHEN task_code IS NULL OR task_code = '' THEN 1 ELSE 0 END ASC,
                  task_code ASC,
                  sort_no ASC,
                  item_id ASC"
    );
    $stmt->execute($unitIds);

    $summaryByUnitId = [];
    foreach ($stmt->fetchAll() as $row) {
        $unitId = (int)($row['unit_ra_id'] ?? 0);
        $taskName = trim((string)($row['task_name'] ?? ''));
        $itemId = (int)($row['item_id'] ?? 0);
        if ($unitId <= 0 || $taskName === '' || $itemId <= 0) {
            continue;
        }

        $summaryItem = [
            'summary_key' => (string)$itemId,
            'task_code' => trim((string)($row['task_code'] ?? '')),
            'hazard_name' => trim((string)($row['hazard_name'] ?? '')),
            'accident_type' => trim((string)($row['accident_type'] ?? '')),
            'injury_result' => trim((string)($row['injury_result'] ?? '')),
            'cause_text' => trim((string)($row['cause_text'] ?? '')),
            'current_control_text' => trim((string)($row['current_control_text'] ?? '')),
            'additional_control_text' => trim((string)($row['additional_control_text'] ?? '')),
            'risk_before' => riskValueLabel($row['likelihood_before'] ?? null, $row['severity_before'] ?? null, $row['risk_score_before'] ?? null),
            'risk_current' => riskValueLabel($row['likelihood_current'] ?? null, $row['severity_current'] ?? null, $row['risk_score_current'] ?? null),
            'risk_after' => riskValueLabel($row['likelihood_after'] ?? null, $row['severity_after'] ?? null, $row['risk_score_after'] ?? null),
            'improvement_due_date' => trim((string)($row['improvement_due_date'] ?? '')),
            'remark' => trim((string)($row['remark'] ?? '')),
        ];

        foreach (['current_control_text' => '기존대책', 'additional_control_text' => '추가대책'] as $fieldName => $controlType) {
            foreach (extractPreventionMeasureLines((string)($row[$fieldName] ?? '')) as $measureLine) {
                if (!isset($summaryByUnitId[$unitId])) {
                    $summaryByUnitId[$unitId] = [];
                }
                if (!isset($summaryByUnitId[$unitId][$taskName])) {
                    $summaryByUnitId[$unitId][$taskName] = [];
                }
                if (!isset($summaryByUnitId[$unitId][$taskName][$measureLine])) {
                    $summaryByUnitId[$unitId][$taskName][$measureLine] = [];
                }

                $summaryByUnitId[$unitId][$taskName][$measureLine][$itemId] = $summaryItem + [
                    'control_type' => $controlType,
                ];
            }
        }
    }

    return $summaryByUnitId;
}

function normalizeDetailOptionMap(array $optionMap): array
{
    $normalized = [];

    foreach ($optionMap as $workKey => $workTypes) {
        ksort($workTypes, SORT_NATURAL | SORT_FLAG_CASE);
        $normalized[$workKey] = [];

        foreach ($workTypes as $workType => $processes) {
            $normalized[$workKey][] = [
                'value' => $workType,
                'label' => $workType,
                'processes' => array_values($processes),
            ];
        }
    }

    return $normalized;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $stmt->execute([':table_name' => $tableName]);

    return (int)$stmt->fetchColumn() > 0;
}

function renderCurrentSelectOption(string $value, string $placeholder): string
{
    $value = trim($value);
    if ($value === '') {
        return '<option value="">' . h($placeholder) . '</option>';
    }

    return '<option value="' . h($value) . '" selected>' . h($value) . '</option>';
}

function renderActivityOptionsHtml(array $detailOptionMap, string $selectedValue = ''): string
{
    $selectedValue = trim($selectedValue);
    $options = ['<option value="">작업 선택 후 작업유형 선택</option>'];

    if ($selectedValue !== '') {
        $options[] = '<option value="' . h($selectedValue) . '" selected>' . h($selectedValue) . '</option>';
    }

    return implode('', $options);
}

function addReportOptionFromUnitTitle(array &$optionMap, string $workKey, string $unitTitle, array $taskNames = []): void
{
    $workType = extractWorkTypeLabel($unitTitle);
    if ($workType === '') {
        return;
    }

    if (empty($taskNames)) {
        addDetailOption($optionMap, $workKey, $workType, '');
        return;
    }

    foreach ($taskNames as $taskName) {
        addDetailOption($optionMap, $workKey, $workType, (string)$taskName);
    }
}

function addPreventionMeasures(array &$preventionMap, string $workKey, string $activity, string $processName, array $measures): void
{
    $activity = trim($activity);
    $processName = trim($processName);
    if ($activity === '' || $processName === '' || empty($measures)) {
        return;
    }

    if (!isset($preventionMap[$workKey])) {
        $preventionMap[$workKey] = [];
    }
    if (!isset($preventionMap[$workKey][$activity])) {
        $preventionMap[$workKey][$activity] = [];
    }
    if (!isset($preventionMap[$workKey][$activity][$processName])) {
        $preventionMap[$workKey][$activity][$processName] = [];
    }

    foreach ($measures as $measure) {
        $measure = trim((string)$measure);
        if ($measure === '') {
            continue;
        }
        $preventionMap[$workKey][$activity][$processName][$measure] = $measure;
    }
}

function normalizePreventionMap(array $preventionMap): array
{
    $normalized = [];

    foreach ($preventionMap as $workKey => $activities) {
        $normalized[$workKey] = [];
        foreach ($activities as $activity => $processMap) {
            $normalized[$workKey][$activity] = [];
            foreach ($processMap as $processName => $measures) {
                $normalized[$workKey][$activity][$processName] = array_values($measures);
            }
        }
    }

    return $normalized;
}

function addPreventionSummaryDetails(array &$preventionDetailMap, string $workKey, string $activity, string $processName, string $measure, array $summaryItems): void
{
    $activity = trim($activity);
    $processName = trim($processName);
    $measure = trim($measure);
    if ($activity === '' || $processName === '' || $measure === '' || empty($summaryItems)) {
        return;
    }

    if (!isset($preventionDetailMap[$workKey])) {
        $preventionDetailMap[$workKey] = [];
    }
    if (!isset($preventionDetailMap[$workKey][$activity])) {
        $preventionDetailMap[$workKey][$activity] = [];
    }
    if (!isset($preventionDetailMap[$workKey][$activity][$processName])) {
        $preventionDetailMap[$workKey][$activity][$processName] = [];
    }
    if (!isset($preventionDetailMap[$workKey][$activity][$processName][$measure])) {
        $preventionDetailMap[$workKey][$activity][$processName][$measure] = [];
    }

    foreach ($summaryItems as $summaryItem) {
        $summaryKey = trim((string)($summaryItem['summary_key'] ?? ''));
        if ($summaryKey === '') {
            continue;
        }
        $preventionDetailMap[$workKey][$activity][$processName][$measure][$summaryKey] = $summaryItem;
    }
}

function normalizePreventionDetailMap(array $preventionDetailMap): array
{
    $normalized = [];

    foreach ($preventionDetailMap as $workKey => $activities) {
        $normalized[$workKey] = [];
        foreach ($activities as $activity => $processMap) {
            $normalized[$workKey][$activity] = [];
            foreach ($processMap as $processName => $measureMap) {
                $normalized[$workKey][$activity][$processName] = [];
                foreach ($measureMap as $measure => $summaryItems) {
                    $normalized[$workKey][$activity][$processName][$measure] = array_values($summaryItems);
                }
            }
        }
    }

    return $normalized;
}

function saveUploadedFile(array $file, string $uploadDir): string
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return '';
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = sprintf('%s_%s.%s', $safeName, uniqid('', true), $extension ?: 'dat');
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/safety_manager/' . $filename;
    }

    return '';
}

$message = '';
$error = '';
$values = [
    'log_date' => date('Y-m-d'),
    'manager_name' => '',
    'site_name' => '',
    'work_location' => '',
    'weather' => '',
    'subject' => '',
    'summary' => '',
    'remark' => '',
];
$details = [];
$calendarWeatherMap = loadCalendarWeatherMap();

// ── 안전관리자 목록 (auth_users.json) ──────────────────────
$safetyManagers = [];
$authUsersFile = __DIR__ . '/../risk_assessment/auth_users.json';
if (is_file($authUsersFile)) {
    $stored = json_decode((string)file_get_contents($authUsersFile), true) ?? [];
    foreach ($stored as $account) {
        if (!is_array($account)) {
            continue;
        }
        if ((string)($account['role'] ?? '') === 'safety_manager') {
            $name = trim((string)($account['name'] ?? ''));
            if ($name !== '') {
                $safetyManagers[] = $name;
            }
        }
    }
    sort($safetyManagers);
}

if ($values['manager_name'] === '' && count($safetyManagers) === 1) {
    $values['manager_name'] = $safetyManagers[0];
}

if ($values['weather'] === '' && isset($calendarWeatherMap[$values['log_date']]) && is_array($calendarWeatherMap[$values['log_date']])) {
    $values['weather'] = formatWeatherText($calendarWeatherMap[$values['log_date']]);
}

// ── 금일 작업목록 (risk_assessment DB) ─────────────────────
$todayWorkList = [];
$detailOptionMap = ['__all__' => []];
$preventionMeasureMap = [];
$preventionDetailMap = [];
try {
    $raPdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=risk_assessment;charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $wStmt = $raPdo->prepare(
                "SELECT wr.report_id,
                                wr.work_title,
                                wr.work_place,
                                wr.team_name,
                                wr.user_name,
                                wr.role_code,
                base_h.unit_ra_id AS base_unit_ra_id,
                                base_h.unit_title AS base_unit_title,
                                GROUP_CONCAT(
                                        DISTINCT CASE
                                                WHEN selected_h.unit_ra_id IS NOT NULL
                        THEN CONCAT(selected_h.unit_ra_id, '\\t', selected_h.unit_title)
                                                ELSE NULL
                                        END
                                        ORDER BY selected_h.sort_no ASC, selected_h.unit_ra_id ASC
                                        SEPARATOR '\\n'
                                ) AS selected_target_rows
                 FROM work_report wr
                 LEFT JOIN unit_ra_header base_h
                     ON base_h.unit_ra_id = wr.unit_ra_id
                    AND base_h.use_yn = 'Y'
                    AND base_h.unit_type = 'target'
                 LEFT JOIN work_report_selected_unit wsu
                     ON wsu.report_id = wr.report_id
                 LEFT JOIN unit_ra_header selected_h
                     ON selected_h.unit_ra_id = wsu.unit_ra_id
                    AND selected_h.use_yn = 'Y'
                    AND selected_h.unit_type = 'target'
                 WHERE wr.work_date = :today
                     AND wr.role_code IN ('manager', 'leader')
                 GROUP BY wr.report_id, wr.work_title, wr.work_place, wr.team_name, wr.user_name, wr.role_code, base_h.unit_ra_id, base_h.unit_title
                 ORDER BY wr.team_name, wr.user_name, wr.work_title, wr.report_id"
    );
    $wStmt->execute([':today' => date('Y-m-d')]);
    $todayWorkList = $wStmt->fetchAll();

            $targetUnitIds = [];
            foreach ($todayWorkList as $workRow) {
                $baseUnitRaId = (int)($workRow['base_unit_ra_id'] ?? 0);
                if ($baseUnitRaId > 0) {
                    $targetUnitIds[] = $baseUnitRaId;
                }

                $selectedTargetRows = trim((string)($workRow['selected_target_rows'] ?? ''));
                if ($selectedTargetRows === '') {
                    continue;
                }

                foreach (preg_split('/\r\n|\r|\n/', $selectedTargetRows) ?: [] as $selectedRow) {
                    $selectedRow = trim((string)$selectedRow);
                    if ($selectedRow === '') {
                        continue;
                    }

                    $parts = explode("\t", $selectedRow, 2);
                    $selectedUnitRaId = (int)($parts[0] ?? 0);
                    if ($selectedUnitRaId > 0) {
                        $targetUnitIds[] = $selectedUnitRaId;
                    }
                }
            }

            $taskNamesByUnitId = collectTaskNamesByUnitIds($raPdo, $targetUnitIds);
            $preventionMeasuresByUnitId = collectPreventionMeasuresByUnitIds($raPdo, $targetUnitIds);
            $preventionSummaryByUnitId = collectPreventionSummaryByUnitIds($raPdo, $targetUnitIds);

    foreach ($todayWorkList as $workRow) {
        $workKey = buildReportWorkOptionKey((int)($workRow['report_id'] ?? 0));
        if ($workKey === buildReportWorkOptionKey(0)) {
            continue;
        }

        $selectedTargetRows = trim((string)($workRow['selected_target_rows'] ?? ''));
        if ($selectedTargetRows !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $selectedTargetRows) ?: [] as $selectedRow) {
                $selectedRow = trim((string)$selectedRow);
                if ($selectedRow === '') {
                    continue;
                }

                $parts = explode("\t", $selectedRow, 3);
                $selectedUnitRaId = (int)($parts[0] ?? 0);
                $activityTitle = (string)($parts[1] ?? '');
                $taskNames = array_values($taskNamesByUnitId[$selectedUnitRaId] ?? []);
                addReportOptionFromUnitTitle(
                    $detailOptionMap,
                    $workKey,
                    $activityTitle,
                    $taskNames
                );

                foreach ($taskNames as $taskName) {
                    addPreventionMeasures(
                        $preventionMeasureMap,
                        $workKey,
                        $activityTitle,
                        (string)$taskName,
                        array_values($preventionMeasuresByUnitId[$selectedUnitRaId][$taskName] ?? [])
                    );

                    foreach (($preventionSummaryByUnitId[$selectedUnitRaId][$taskName] ?? []) as $measure => $summaryItems) {
                        addPreventionSummaryDetails(
                            $preventionDetailMap,
                            $workKey,
                            $activityTitle,
                            (string)$taskName,
                            (string)$measure,
                            array_values($summaryItems)
                        );
                    }
                }
            }
        } else {
            $baseUnitRaId = (int)($workRow['base_unit_ra_id'] ?? 0);
            $activityTitle = (string)($workRow['base_unit_title'] ?? '');
            $taskNames = array_values($taskNamesByUnitId[$baseUnitRaId] ?? []);
            addReportOptionFromUnitTitle(
                $detailOptionMap,
                $workKey,
                $activityTitle,
                $taskNames
            );

            foreach ($taskNames as $taskName) {
                addPreventionMeasures(
                    $preventionMeasureMap,
                    $workKey,
                    $activityTitle,
                    (string)$taskName,
                    array_values($preventionMeasuresByUnitId[$baseUnitRaId][$taskName] ?? [])
                );

                foreach (($preventionSummaryByUnitId[$baseUnitRaId][$taskName] ?? []) as $measure => $summaryItems) {
                    addPreventionSummaryDetails(
                        $preventionDetailMap,
                        $workKey,
                        $activityTitle,
                        (string)$taskName,
                        (string)$measure,
                        array_values($summaryItems)
                    );
                }
            }
        }
    }
} catch (Throwable $e) {
    // risk_assessment DB 연결 실패 시 빈 배열 유지
}

$detailOptionMap = normalizeDetailOptionMap($detailOptionMap);
$preventionMeasureMap = normalizePreventionMap($preventionMeasureMap);
$preventionDetailMap = normalizePreventionDetailMap($preventionDetailMap);

function renderDetailRow(array $detail, int $index): string
{
    global $detailOptionMap;

    $activityOptionHtml = renderActivityOptionsHtml($detailOptionMap, (string)($detail['activity'] ?? ''));
    $descriptionOptionHtml = renderCurrentSelectOption((string)($detail['description'] ?? ''), '작업유형 먼저 선택');
    $preventionData = h((string)($detail['prevention_data'] ?? ''));

    return sprintf(
        '<tr class="detail-row">
            <td><input type="hidden" name="details[%1$d][item_no]" value="%2$d"><input type="hidden" name="details[%1$d][prevention_data]" class="js-prevention-data-input" value="%6$s"><span class="form-control-plaintext">%2$d</span></td>
            <td><input type="time" name="details[%1$d][work_time]" class="form-control" value="%3$s" step="600"></td>
            <td>
                <select name="details[%1$d][activity]" class="form-select js-activity-select" data-selected="%4$s">
                    %7$s
                </select>
            </td>
            <td>
                <select name="details[%1$d][description]" class="form-select js-process-select" data-selected="%5$s">
                    %8$s
                </select>
            </td>
            <td><input type="file" name="details[%1$d][photo_1]" class="form-control"></td>
            <td><input type="file" name="details[%1$d][photo_2]" class="form-control"></td>
            <td><input type="file" name="details[%1$d][photo_3]" class="form-control"></td>
            <td><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
        </tr>',
        $index,
        $detail['item_no'] ?? ($index + 1),
        h($detail['work_time'] ?? ''),
        h($detail['activity'] ?? ''),
        h($detail['description'] ?? ''),
        $preventionData,
        $activityOptionHtml,
        $descriptionOptionHtml
    );
}

function formatWorkLabel(array $work): string
{
    $title = trim((string)($work['work_title'] ?? ''));
    $teamName = trim((string)($work['team_name'] ?? ''));

    if ($teamName === '') {
        return $title;
    }

    return $title . ' (' . $teamName . ')';
}
?>
<?php
$pageTitle = '안전관리자 업무일지 등록';
$extraHead = '<style>
    .detail-table th, .detail-table td { vertical-align: middle; }
    .site-visit-table th, .site-visit-table td { vertical-align: middle; }
    .form-control-plaintext { margin-bottom: 0; }
    .form-select {
        color: #f8fafc;
        background-color: #162033;
        border-color: #8b6b2f;
    }
    .form-select:focus {
        color: #f8fafc;
        background-color: #162033;
        border-color: #d6a545;
        box-shadow: 0 0 0 0.2rem rgba(214, 165, 69, 0.2);
    }
    .form-select option {
        color: #0f172a;
        background-color: #f8fafc;
    }
    .form-select option[value=""] {
        color: #475569;
    }
    .site-visit-meta {
        display: block;
        margin-top: 4px;
        color: #94a3b8;
        font-size: 12px;
    }
    .weather-field[readonly] {
        background-color: #f8fafc;
        cursor: default;
    }
</style>';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4">안전관리자 업무일지 등록</h1>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="store.php" enctype="multipart/form-data">
        <input type="hidden" name="site_name" id="site_name" value="<?= h($values['site_name']) ?>">
        <input type="hidden" name="work_location" id="work_location" value="<?= h($values['work_location']) ?>">
        <input type="hidden" name="site_visit_data" id="site_visit_data" value="[]">
        <div class="card mb-4">
            <div class="card-header">기본 정보</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">날짜</label>
                        <input type="date" name="log_date" id="log_date" class="form-control" value="<?= h($values['log_date']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">작성자</label>
                        <?php if (!empty($safetyManagers)): ?>
                            <select name="manager_name" class="form-select" required>
                                <?php foreach ($safetyManagers as $mgr): ?>
                                    <option value="<?= h($mgr) ?>" <?= $values['manager_name'] === $mgr ? 'selected' : '' ?>>
                                        <?= h($mgr) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="manager_name" class="form-control" value="<?= h($values['manager_name']) ?>" required placeholder="작성자 이름">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">날씨</label>
                        <input type="text" name="weather" id="weather" class="form-control weather-field" value="<?= h($values['weather']) ?>" readonly>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">제목</label>
                        <input type="text" name="subject" class="form-control" value="<?= h($values['subject']) ?>" required>
                    </div>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-12">
                        <label class="form-label">현장명</label>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0 site-visit-table">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 72px;">선택</th>
                                    <th style="width: 34%;">작업명</th>
                                    <th style="width: 34%;">작업장소</th>
                                    <th>미방문사유</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($todayWorkList)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">조회된 작업이 없습니다.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($todayWorkList as $index => $w): ?>
                                        <tr class="site-visit-row">
                                            <td class="text-center">
                                                <input type="checkbox"
                                                       class="form-check-input js-site-visit-check"
                                                       id="site_visit_<?= (int)$index ?>"
                                                       data-work-key="<?= h(buildReportWorkOptionKey((int)($w['report_id'] ?? 0))) ?>"
                                                       data-title="<?= h((string)($w['work_title'] ?? '')) ?>"
                                                       data-place="<?= h((string)($w['work_place'] ?? '')) ?>"
                                                       data-team="<?= h((string)($w['team_name'] ?? '')) ?>">
                                            </td>
                                            <td>
                                                <label for="site_visit_<?= (int)$index ?>" class="mb-0 w-100">
                                                    <?= h((string)($w['work_title'] ?? '')) ?>
                                                </label>
                                            </td>
                                            <td><?= h((string)($w['work_place'] ?? '')) ?></td>
                                            <td>
                                                <input type="text"
                                                       class="form-control form-control-sm js-site-visit-reason"
                                                       placeholder="미방문 시 사유 입력">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-12">
                        <label class="form-label">요약</label>
                        <textarea name="summary" class="form-control" rows="3"><?= h($values['summary']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>세부 기록</span>
                <button type="button" id="add-detail" class="btn btn-primary btn-sm">세부기록 추가</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0 detail-table">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">No.</th>
                            <th style="width: 120px;">시간</th>
                            <th style="width: 180px;">관찰 업무</th>
                            <th style="width: 220px;">공정</th>
                            <th style="width: 140px;">사진1</th>
                            <th style="width: 140px;">사진2</th>
                            <th style="width: 140px;">사진3</th>
                            <th style="width: 90px;">삭제</th>
                        </tr>
                        </thead>
                        <tbody id="details-body">
                        <?php if (empty($details)): ?>
                            <?= renderDetailRow([], 0) ?>
                        <?php else: ?>
                            <?php foreach ($details as $index => $detail): ?>
                                <?= renderDetailRow($detail ?? [], $index) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">예방대책 확인</div>
            <div class="card-body">
                <div id="prevention-empty" class="text-muted">공정을 선택하면 해당 위험성평가 예방대책이 표시됩니다.</div>
                <div id="prevention-sections" class="d-flex flex-column gap-3"></div>
            </div>
        </div>

        <div class="modal fade" id="preventionSummaryModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="preventionSummaryModalLabel">예방대책 상세</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
                    </div>
                    <div class="modal-body" id="preventionSummaryModalBody"></div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label">비고</label>
            <textarea name="remark" class="form-control" rows="3"><?= h($values['remark']) ?></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">등록</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.reload()">취소</button>
        </div>
    </form>

<script>
    const detailBody = document.getElementById('details-body');
    const addDetailButton = document.getElementById('add-detail');
    const siteVisitRows = document.querySelectorAll('.site-visit-row');
    const siteNameInput = document.getElementById('site_name');
    const workLocationInput = document.getElementById('work_location');
    const siteVisitDataInput = document.getElementById('site_visit_data');
    const logDateInput = document.getElementById('log_date');
    const weatherInput = document.getElementById('weather');
    const preventionEmpty = document.getElementById('prevention-empty');
    const preventionSections = document.getElementById('prevention-sections');
    const preventionSummaryModalElement = document.getElementById('preventionSummaryModal');
    const preventionSummaryModalLabel = document.getElementById('preventionSummaryModalLabel');
    const preventionSummaryModalBody = document.getElementById('preventionSummaryModalBody');
    const weatherMap = <?= json_encode($calendarWeatherMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const detailOptionsByWorkKey = <?= json_encode($detailOptionMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const preventionMeasuresByWorkKey = <?= json_encode($preventionMeasureMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const preventionSummaryByWorkKey = <?= json_encode($preventionDetailMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let preventionSummaryModal = null;

    function getPreventionSummaryModalInstance() {
        if (!preventionSummaryModalElement || !window.bootstrap || !window.bootstrap.Modal) {
            return null;
        }

        if (!preventionSummaryModal) {
            preventionSummaryModal = new window.bootstrap.Modal(preventionSummaryModalElement);
        }

        return preventionSummaryModal;
    }

    function formatWeatherText(weatherInfo) {
        if (!weatherInfo || typeof weatherInfo !== 'object') {
            return '';
        }

        const labelMap = {
            CLEAR: '맑음',
            MOSTLY_CLEAR: '대체로 맑음',
            PARTLY_CLOUDY: '구름많음',
            MOSTLY_CLOUDY: '대체로 흐림',
            CLOUDY: '흐림',
            RAIN: '비',
            SNOW: '눈',
            RAIN_SNOW: '비/눈',
            SHOWER: '소나기'
        };

        const parts = [];
        if (weatherInfo.code) {
            parts.push(labelMap[weatherInfo.code] || weatherInfo.code);
        }

        if (weatherInfo.tMin !== undefined && weatherInfo.tMin !== null && weatherInfo.tMax !== undefined && weatherInfo.tMax !== null) {
            parts.push(`${Math.round(Number(weatherInfo.tMin))}~${Math.round(Number(weatherInfo.tMax))}℃`);
        }

        if (weatherInfo.pop !== undefined && weatherInfo.pop !== null && weatherInfo.pop !== '') {
            parts.push(`강수확률 ${parseInt(weatherInfo.pop, 10)}%`);
        }

        return parts.join(' / ');
    }

    function syncWeatherByDate() {
        if (!logDateInput || !weatherInput) {
            return;
        }

        const selectedDate = logDateInput.value;
        weatherInput.value = selectedDate && weatherMap[selectedDate] ? formatWeatherText(weatherMap[selectedDate]) : '';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSelectedWorkKeys() {
        return Array.from(siteVisitRows)
            .map((row) => row.querySelector('.js-site-visit-check'))
            .filter((checkbox) => checkbox && checkbox.checked)
            .map((checkbox) => checkbox.dataset.workKey || '')
            .filter((workKey) => workKey !== '');
    }

    function getAvailableActivityOptions() {
        const selectedWorkKeys = getSelectedWorkKeys();
        const merged = new Map();

        function appendOptionsFromKeys(sourceKeys) {
            sourceKeys.forEach((workKey) => {
                const options = Array.isArray(detailOptionsByWorkKey[workKey]) ? detailOptionsByWorkKey[workKey] : [];
                options.forEach((option) => {
                    const optionValue = String(option.value || '').trim();
                    if (!optionValue) {
                        return;
                    }

                    if (!merged.has(optionValue)) {
                        merged.set(optionValue, {
                            value: optionValue,
                            label: String(option.label || optionValue),
                            processes: []
                        });
                    }

                    const current = merged.get(optionValue);
                    (Array.isArray(option.processes) ? option.processes : []).forEach((processName) => {
                        const normalizedProcess = String(processName || '').trim();
                        if (normalizedProcess && !current.processes.includes(normalizedProcess)) {
                            current.processes.push(normalizedProcess);
                        }
                    });
                });
            });
        }

        if (selectedWorkKeys.length === 0) {
            return [];
        }

        appendOptionsFromKeys(selectedWorkKeys);

        return Array.from(merged.values()).sort((left, right) => left.label.localeCompare(right.label, 'ko'));
    }

    function getAvailableProcessOptions(activityValue) {
        const normalizedActivity = String(activityValue || '').trim();
        if (!normalizedActivity) {
            return [];
        }

        const matched = getAvailableActivityOptions().find((option) => option.value === normalizedActivity);
        if (!matched || !Array.isArray(matched.processes)) {
            return [];
        }

        return matched.processes.slice();
    }

    function parsePreventionData(rawValue) {
        if (!rawValue) {
            return { activity: '', process: '', items: [] };
        }

        try {
            const parsed = JSON.parse(rawValue);
            return {
                activity: String(parsed.activity || ''),
                process: String(parsed.process || ''),
                items: Array.isArray(parsed.items) ? parsed.items.map((item) => ({
                    measure: String(item.measure || ''),
                    status: String(item.status || '')
                })).filter((item) => item.measure !== '') : []
            };
        } catch (error) {
            return { activity: '', process: '', items: [] };
        }
    }

    function getAvailablePreventionMeasures(activityValue, processValue) {
        const normalizedActivity = String(activityValue || '').trim();
        const normalizedProcess = String(processValue || '').trim();
        if (!normalizedActivity || !normalizedProcess) {
            return [];
        }

        const selectedWorkKeys = getSelectedWorkKeys();
        if (selectedWorkKeys.length === 0) {
            return [];
        }

        const measures = [];
        const seen = new Set();

        selectedWorkKeys.forEach((workKey) => {
            const activityMap = preventionMeasuresByWorkKey[workKey] || {};
            const processMap = activityMap[normalizedActivity] || {};
            const currentMeasures = Array.isArray(processMap[normalizedProcess]) ? processMap[normalizedProcess] : [];
            currentMeasures.forEach((measure) => {
                const normalizedMeasure = String(measure || '').trim();
                if (!normalizedMeasure || seen.has(normalizedMeasure)) {
                    return;
                }

                seen.add(normalizedMeasure);
                measures.push(normalizedMeasure);
            });
        });

        return measures;
    }

    function getPreventionSummaryItems(activityValue, processValue, measure) {
        const normalizedActivity = String(activityValue || '').trim();
        const normalizedProcess = String(processValue || '').trim();
        const normalizedMeasure = String(measure || '').trim();
        if (!normalizedActivity || !normalizedProcess || !normalizedMeasure) {
            return [];
        }

        const selectedWorkKeys = getSelectedWorkKeys();
        const items = [];
        const seen = new Set();

        selectedWorkKeys.forEach((workKey) => {
            const activityMap = preventionSummaryByWorkKey[workKey] || {};
            const processMap = activityMap[normalizedActivity] || {};
            const summaryItems = Array.isArray((processMap[normalizedProcess] || {})[normalizedMeasure])
                ? (processMap[normalizedProcess] || {})[normalizedMeasure]
                : [];

            summaryItems.forEach((item) => {
                const summaryKey = String(item.summary_key || '').trim();
                if (!summaryKey || seen.has(summaryKey)) {
                    return;
                }
                seen.add(summaryKey);
                items.push(item);
            });
        });

        return items;
    }

    function renderSummaryField(label, value) {
        const normalizedValue = String(value || '').trim();
        if (!normalizedValue) {
            return '';
        }

        return `<tr><th style="width: 160px;">${escapeHtml(label)}</th><td>${escapeHtml(normalizedValue).replace(/\n/g, '<br>')}</td></tr>`;
    }

    function showPreventionSummaryModal(activityValue, processValue, measure) {
        if (!preventionSummaryModalBody || !preventionSummaryModalLabel) {
            return;
        }

        const summaryItems = getPreventionSummaryItems(activityValue, processValue, measure);
        preventionSummaryModalLabel.textContent = `예방대책 상세 - ${measure}`;

        if (summaryItems.length === 0) {
            preventionSummaryModalBody.innerHTML = '<p class="text-muted mb-0">표시할 위험성평가 상세 요약이 없습니다.</p>';
        } else {
            preventionSummaryModalBody.innerHTML = summaryItems.map((item, index) => {
                const rows = [
                    renderSummaryField('세부작업코드', item.task_code),
                    renderSummaryField('위험요인', item.hazard_name),
                    renderSummaryField('재해형태', item.accident_type),
                    renderSummaryField('상해결과', item.injury_result),
                    renderSummaryField('원인', item.cause_text),
                    renderSummaryField('대책구분', item.control_type),
                    renderSummaryField('기존대책', item.current_control_text),
                    renderSummaryField('추가대책', item.additional_control_text),
                    renderSummaryField('개선전', item.risk_before),
                    renderSummaryField('현재', item.risk_current),
                    renderSummaryField('개선후', item.risk_after),
                    renderSummaryField('개선기한', item.improvement_due_date),
                    renderSummaryField('비고', item.remark)
                ].filter(Boolean).join('');

                return `
                    <div class="${index > 0 ? 'mt-4' : ''}">
                        <div class="fw-semibold mb-2">상세 ${index + 1}</div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>`;
            }).join('');
        }

        const modalInstance = getPreventionSummaryModalInstance();
        if (modalInstance) {
            modalInstance.show();
        }
    }

    function buildPreventionData(activityValue, processValue, measures, previousItems) {
        const previousStatusMap = new Map();
        (Array.isArray(previousItems) ? previousItems : []).forEach((item) => {
            const measure = String(item.measure || '').trim();
            if (!measure || previousStatusMap.has(measure)) {
                return;
            }
            previousStatusMap.set(measure, String(item.status || ''));
        });

        return {
            activity: String(activityValue || ''),
            process: String(processValue || ''),
            items: measures.map((measure) => ({
                measure,
                status: previousStatusMap.get(measure) || ''
            }))
        };
    }

    function updateRowPreventionInput(row, preventionData) {
        const preventionInput = row.querySelector('.js-prevention-data-input');
        if (!preventionInput) {
            return;
        }

        preventionInput.value = JSON.stringify(preventionData);
    }

    function setSelectOptions(selectElement, options, placeholder, selectedValue, disabled) {
        if (!selectElement) {
            return;
        }

        const normalizedSelected = String(selectedValue || '').trim();
        const html = [`<option value="">${escapeHtml(placeholder)}</option>`];
        let hasSelected = false;

        options.forEach((option) => {
            const value = String((option && option.value !== undefined) ? option.value : option).trim();
            const label = String((option && option.label !== undefined) ? option.label : value).trim();
            if (!value) {
                return;
            }

            const isSelected = normalizedSelected !== '' && value === normalizedSelected;
            if (isSelected) {
                hasSelected = true;
            }

            html.push(`<option value="${escapeHtml(value)}"${isSelected ? ' selected' : ''}>${escapeHtml(label)}</option>`);
        });

        selectElement.innerHTML = html.join('');
        if (!hasSelected) {
            selectElement.value = '';
        }
        selectElement.disabled = !!disabled;
    }

    function refreshProcessSelect(row, selectedProcess = null) {
        const activitySelect = row.querySelector('.js-activity-select');
        const processSelect = row.querySelector('.js-process-select');
        if (!activitySelect || !processSelect) {
            return;
        }

        const processOptions = getAvailableProcessOptions(activitySelect.value).map((processName) => ({
            value: processName,
            label: processName
        }));

        setSelectOptions(
            processSelect,
            processOptions,
            processOptions.length > 0 ? '공정 선택' : '작업유형 먼저 선택',
            selectedProcess !== null ? selectedProcess : (processSelect.dataset.selected || processSelect.value),
            processOptions.length === 0
        );
        processSelect.dataset.selected = processSelect.value;
    }

    function refreshActivitySelect(row, selectedActivity = null, selectedProcess = null) {
        const activitySelect = row.querySelector('.js-activity-select');
        if (!activitySelect) {
            return;
        }

        const activityOptions = getAvailableActivityOptions();
        setSelectOptions(
            activitySelect,
            activityOptions,
            activityOptions.length > 0 ? '작업유형 선택' : '작업 선택 후 작업유형 선택',
            selectedActivity !== null ? selectedActivity : (activitySelect.dataset.selected || activitySelect.value),
            activityOptions.length === 0
        );
        activitySelect.dataset.selected = activitySelect.value;
        refreshProcessSelect(row, selectedProcess);
    }

    function refreshAllDetailRows() {
        detailBody.querySelectorAll('.detail-row').forEach((row) => {
            const activitySelect = row.querySelector('.js-activity-select');
            const processSelect = row.querySelector('.js-process-select');
            refreshActivitySelect(
                row,
                activitySelect ? activitySelect.value || activitySelect.dataset.selected || '' : '',
                processSelect ? processSelect.value || processSelect.dataset.selected || '' : ''
            );
        });

        renderPreventionSections();
    }

    function renderPreventionSections() {
        if (!preventionSections || !preventionEmpty) {
            return;
        }

        const sectionHtml = [];
        const rows = Array.from(detailBody.querySelectorAll('.detail-row'));

        rows.forEach((row, index) => {
            const activitySelect = row.querySelector('.js-activity-select');
            const processSelect = row.querySelector('.js-process-select');
            const preventionInput = row.querySelector('.js-prevention-data-input');
            const activityValue = activitySelect ? String(activitySelect.value || '').trim() : '';
            const processValue = processSelect ? String(processSelect.value || '').trim() : '';

            if (!activityValue || !processValue) {
                updateRowPreventionInput(row, { activity: activityValue, process: processValue, items: [] });
                return;
            }

            const availableMeasures = getAvailablePreventionMeasures(activityValue, processValue);
            const previousData = parsePreventionData(preventionInput ? preventionInput.value : '');
            const preventionData = buildPreventionData(activityValue, processValue, availableMeasures, previousData.items || []);
            updateRowPreventionInput(row, preventionData);

            if (preventionData.items.length === 0) {
                return;
            }

            const rowsHtml = preventionData.items.map((item, preventionIndex) => `
                <tr>
                    <td>${preventionIndex + 1}</td>
                    <td>
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <span>${escapeHtml(item.measure)}</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm js-prevention-view" data-row-index="${index}" data-activity="${escapeHtml(activityValue)}" data-process="${escapeHtml(processValue)}" data-measure="${escapeHtml(item.measure)}">보기</button>
                        </div>
                    </td>
                    <td style="width: 180px;">
                        <select class="form-select form-select-sm js-prevention-status-select" data-row-index="${index}" data-measure="${escapeHtml(item.measure)}">
                            <option value="">선택</option>
                            <option value="양호"${item.status === '양호' ? ' selected' : ''}>양호</option>
                            <option value="불량"${item.status === '불량' ? ' selected' : ''}>불량</option>
                            <option value="조치완료"${item.status === '조치완료' ? ' selected' : ''}>조치완료</option>
                            <option value="후속조치필요"${item.status === '후속조치필요' ? ' selected' : ''}>후속조치필요</option>
                            <option value="[평가서 수정필요]"${item.status === '[평가서 수정필요]' ? ' selected' : ''}>[평가서 수정필요]</option>
                            <option value="해당없음"${item.status === '해당없음' ? ' selected' : ''}>해당없음</option>
                        </select>
                    </td>
                </tr>`).join('');

            sectionHtml.push(`
                <div class="border rounded p-3 bg-light-subtle">
                    <div class="fw-semibold mb-2">No.${index + 1} / ${escapeHtml(activityValue)} / ${escapeHtml(processValue)}</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">No.</th>
                                    <th>예방대책</th>
                                    <th style="width: 180px;">상태</th>
                                </tr>
                            </thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>
                    </div>
                </div>`);
        });

        preventionSections.innerHTML = sectionHtml.join('');
        preventionEmpty.classList.toggle('d-none', sectionHtml.length > 0);
    }

    function getDetailRowHtml(index, data = {}) {
        const workTime = data.work_time ?? '';
        const activity = data.activity ?? '';
        const description = data.description ?? '';
        const preventionData = data.prevention_data ?? '';
        const activityOptionsHtml = ['<option value="">작업 선택 후 작업유형 선택</option>'];
        if (activity) {
            activityOptionsHtml.push(`<option value="${escapeHtml(activity)}" selected>${escapeHtml(activity)}</option>`);
        }

        return `
            <tr class="detail-row">
                <td><input type="hidden" name="details[${index}][item_no]" value="${index + 1}"><input type="hidden" name="details[${index}][prevention_data]" class="js-prevention-data-input" value="${escapeHtml(preventionData)}"><span class="form-control-plaintext">${index + 1}</span></td>
                <td><input type="time" name="details[${index}][work_time]" class="form-control" value="${workTime}" step="600"></td>
                <td>
                    <select name="details[${index}][activity]" class="form-select js-activity-select" data-selected="${escapeHtml(activity)}">
                        ${activityOptionsHtml.join('')}
                    </select>
                </td>
                <td>
                    <select name="details[${index}][description]" class="form-select js-process-select" data-selected="${escapeHtml(description)}">
                        <option value="">작업유형 먼저 선택</option>
                    </select>
                </td>
                <td><input type="file" name="details[${index}][photo_1]" class="form-control"></td>
                <td><input type="file" name="details[${index}][photo_2]" class="form-control"></td>
                <td><input type="file" name="details[${index}][photo_3]" class="form-control"></td>
                <td><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
            </tr>`;
    }

    function reindexRows() {
        const rows = detailBody.querySelectorAll('.detail-row');
        rows.forEach((row, index) => {
            const itemNoInput = row.querySelector('input[type="hidden"][name^="details"][name$="[item_no]"]');
            if (itemNoInput) {
                itemNoInput.name = `details[${index}][item_no]`;
                itemNoInput.value = index + 1;
            }

            const inputs = row.querySelectorAll('input, textarea, select');
            inputs.forEach((input) => {
                const nameParts = input.name.match(/^details\[(\d+)\]\[(.+)\]$/);
                if (nameParts) {
                    input.name = `details[${index}][${nameParts[2]}]`;
                }
            });
        });
    }

    function addDetailRow(data = {}) {
        const index = detailBody.querySelectorAll('.detail-row').length;
        const row = document.createElement('tr');
        row.className = 'detail-row';
        row.innerHTML = getDetailRowHtml(index, data);
        detailBody.appendChild(row);
        refreshActivitySelect(row, data.activity ?? '', data.description ?? '');
    }

    function onDeleteRow(event) {
        if (!event.target.classList.contains('delete-row')) {
            return;
        }
        const row = event.target.closest('tr');
        if (row) {
            row.remove();
            reindexRows();
            renderPreventionSections();
        }
    }

    addDetailButton.addEventListener('click', () => {
        addDetailRow();
    });

    detailBody.addEventListener('click', onDeleteRow);
    detailBody.addEventListener('change', (event) => {
        if (!event.target.classList.contains('js-activity-select') && !event.target.classList.contains('js-process-select')) {
            return;
        }

        const row = event.target.closest('.detail-row');
        if (!row) {
            return;
        }

        event.target.dataset.selected = event.target.value;
        if (event.target.classList.contains('js-activity-select')) {
            refreshProcessSelect(row, '');
        }
        renderPreventionSections();
    });
    preventionSections?.addEventListener('change', (event) => {
        if (!event.target.classList.contains('js-prevention-status-select')) {
            return;
        }

        const rowIndex = Number.parseInt(String(event.target.dataset.rowIndex || '-1'), 10);
        const measure = String(event.target.dataset.measure || '').trim();
        const rows = Array.from(detailBody.querySelectorAll('.detail-row'));
        const row = rows[rowIndex] || null;
        if (!row || !measure) {
            return;
        }

        const preventionInput = row.querySelector('.js-prevention-data-input');
        const preventionData = parsePreventionData(preventionInput ? preventionInput.value : '');
        preventionData.items = (preventionData.items || []).map((item) => {
            if (String(item.measure || '').trim() !== measure) {
                return item;
            }

            return {
                measure: item.measure,
                status: String(event.target.value || '')
            };
        });
        updateRowPreventionInput(row, preventionData);
    });
    preventionSections?.addEventListener('click', (event) => {
        const button = event.target.closest('.js-prevention-view');
        if (!button) {
            return;
        }

        showPreventionSummaryModal(
            String(button.dataset.activity || ''),
            String(button.dataset.process || ''),
            String(button.dataset.measure || '')
        );
    });

    function updateSiteVisitSummary() {
        const rows = Array.from(siteVisitRows).map((row) => {
            const checkbox = row.querySelector('.js-site-visit-check');
            const reasonInput = row.querySelector('.js-site-visit-reason');
            return {
                selected: checkbox ? checkbox.checked : false,
                work_title: checkbox ? checkbox.dataset.title || '' : '',
                work_place: checkbox ? checkbox.dataset.place || '' : '',
                team_name: checkbox ? checkbox.dataset.team || '' : '',
                non_visit_reason: reasonInput ? reasonInput.value.trim() : ''
            };
        });

        const selectedTitles = [];
        const selectedPlaces = [];

        rows.forEach((row) => {
            if (!row.selected) {
                return;
            }
            if (row.work_title && !selectedTitles.includes(row.work_title)) {
                selectedTitles.push(row.work_title);
            }
            if (row.work_place && !selectedPlaces.includes(row.work_place)) {
                selectedPlaces.push(row.work_place);
            }
        });

        siteNameInput.value = selectedTitles.join(', ');
        workLocationInput.value = selectedPlaces.join(', ');
        siteVisitDataInput.value = JSON.stringify(rows);
        refreshAllDetailRows();
    }

    siteVisitRows.forEach((row) => {
        const checkbox = row.querySelector('.js-site-visit-check');
        const reasonInput = row.querySelector('.js-site-visit-reason');

        if (checkbox) {
            checkbox.addEventListener('change', updateSiteVisitSummary);
        }
        if (reasonInput) {
            reasonInput.addEventListener('input', updateSiteVisitSummary);
        }
    });

    const createForm = document.querySelector('form[action="store.php"]');
    if (createForm) {
        createForm.addEventListener('submit', updateSiteVisitSummary);
    }

    updateSiteVisitSummary();
    refreshAllDetailRows();

    if (logDateInput) {
        logDateInput.addEventListener('change', syncWeatherByDate);
    }

    syncWeatherByDate();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
