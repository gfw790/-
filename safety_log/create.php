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

    if ($workKey !== '__all__') {
        addDetailOption($optionMap, '__all__', $workType, $processName);
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
        $line = preg_replace('/^(?:[\-•·\*]\s*|\d+[\.)]\s+)/u', '', $line) ?? $line;
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
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

                        $allTargetUnitRows = $raPdo->query(
                                "SELECT unit_ra_id, unit_title
                                 FROM unit_ra_header
                                 WHERE use_yn = 'Y'
                                     AND unit_type = 'target'
                                 ORDER BY sort_no ASC, unit_ra_id ASC"
                        )->fetchAll();
                        $allTargetUnitIds = array_values(array_unique(array_filter(array_map(
                                static fn($row) => (int)($row['unit_ra_id'] ?? 0),
                                $allTargetUnitRows
                        ), static fn($unitId) => $unitId > 0)));

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

            $targetUnitIds = array_values(array_unique(array_merge($targetUnitIds, $allTargetUnitIds)));

            $taskNamesByUnitId = collectTaskNamesByUnitIds($raPdo, $targetUnitIds);
            $preventionMeasuresByUnitId = collectPreventionMeasuresByUnitIds($raPdo, $targetUnitIds);
            $preventionSummaryByUnitId = collectPreventionSummaryByUnitIds($raPdo, $targetUnitIds);

    foreach ($allTargetUnitRows as $targetUnitRow) {
        $targetUnitId = (int)($targetUnitRow['unit_ra_id'] ?? 0);
        $activityTitle = (string)($targetUnitRow['unit_title'] ?? '');
        if ($targetUnitId <= 0 || trim($activityTitle) === '') {
            continue;
        }

        $taskNames = array_values($taskNamesByUnitId[$targetUnitId] ?? []);
        addReportOptionFromUnitTitle(
            $detailOptionMap,
            '__all__',
            $activityTitle,
            $taskNames
        );

        foreach ($taskNames as $taskName) {
            addPreventionMeasures(
                $preventionMeasureMap,
                '__all__',
                $activityTitle,
                (string)$taskName,
                array_values($preventionMeasuresByUnitId[$targetUnitId][$taskName] ?? [])
            );

            foreach (($preventionSummaryByUnitId[$targetUnitId][$taskName] ?? []) as $measure => $summaryItems) {
                addPreventionSummaryDetails(
                    $preventionDetailMap,
                    '__all__',
                    $activityTitle,
                    (string)$taskName,
                    (string)$measure,
                    array_values($summaryItems)
                );
            }
        }
    }

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

    $workKey = (string)($detail['work_key'] ?? '');
    $activityOptionHtml = renderActivityOptionsHtml($detailOptionMap, (string)($detail['activity'] ?? ''));
    $workKeyOptionHtml = renderCurrentSelectOption($workKey, '현장 선택');
    $descriptionOptionHtml = renderCurrentSelectOption((string)($detail['description'] ?? ''), '작업유형 먼저 선택');
    $preventionData = h((string)($detail['prevention_data'] ?? ''));
    $photo1Temp = h((string)($detail['photo_1_temp'] ?? ''));
    $photo2Temp = h((string)($detail['photo_2_temp'] ?? ''));
    $photo3Temp = h((string)($detail['photo_3_temp'] ?? ''));
    $photo1TempName = h(basename((string)($detail['photo_1_temp'] ?? '')));
    $photo2TempName = h(basename((string)($detail['photo_2_temp'] ?? '')));
    $photo3TempName = h(basename((string)($detail['photo_3_temp'] ?? '')));

    return sprintf(
        '<tr class="detail-row">
            <td data-label="No."><input type="hidden" name="details[%1$d][item_no]" value="%2$d"><input type="hidden" name="details[%1$d][prevention_data]" class="js-prevention-data-input" value="%7$s"><span class="form-control-plaintext">%2$d</span></td>
            <td data-label="시간"><input type="time" name="details[%1$d][work_time]" class="form-control" value="%3$s" step="600"></td>
            <td data-label="현장">
                <select name="details[%1$d][work_key]" class="form-select js-detail-work-key-select" data-selected="%4$s">
                    %8$s
                </select>
            </td>
            <td data-label="관찰 업무">
                <select name="details[%1$d][activity]" class="form-select js-activity-select" data-selected="%5$s">
                    %9$s
                </select>
            </td>
            <td data-label="공정">
                <select name="details[%1$d][description]" class="form-select js-process-select" data-selected="%6$s">
                    %10$s
                </select>
            </td>
            <td data-label="사진1"><input type="file" name="details[%1$d][photo_1]" class="form-control js-detail-photo-input" data-photo-field="photo_1"><input type="hidden" name="details[%1$d][photo_1_temp]" class="js-detail-photo-temp" data-photo-field="photo_1" value="%11$s"><div class="form-text js-detail-photo-status" data-photo-field="photo_1">%14$s</div></td>
            <td data-label="사진2"><input type="file" name="details[%1$d][photo_2]" class="form-control js-detail-photo-input" data-photo-field="photo_2"><input type="hidden" name="details[%1$d][photo_2_temp]" class="js-detail-photo-temp" data-photo-field="photo_2" value="%12$s"><div class="form-text js-detail-photo-status" data-photo-field="photo_2">%15$s</div></td>
            <td data-label="사진3"><input type="file" name="details[%1$d][photo_3]" class="form-control js-detail-photo-input" data-photo-field="photo_3"><input type="hidden" name="details[%1$d][photo_3_temp]" class="js-detail-photo-temp" data-photo-field="photo_3" value="%13$s"><div class="form-text js-detail-photo-status" data-photo-field="photo_3">%16$s</div></td>
            <td data-label="삭제"><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
        </tr>',
        $index,
        $detail['item_no'] ?? ($index + 1),
        h($detail['work_time'] ?? ''),
        h($workKey),
        h($detail['activity'] ?? ''),
        h($detail['description'] ?? ''),
        $preventionData,
        $workKeyOptionHtml,
        $activityOptionHtml,
        $descriptionOptionHtml,
        $photo1Temp,
        $photo2Temp,
        $photo3Temp,
        $photo1TempName !== '' ? '임시저장된 사진: ' . $photo1TempName : '선택된 파일 없음',
        $photo2TempName !== '' ? '임시저장된 사진: ' . $photo2TempName : '선택된 파일 없음',
        $photo3TempName !== '' ? '임시저장된 사진: ' . $photo3TempName : '선택된 파일 없음'
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
    .draft-status {
        color: #94a3b8;
        font-size: 13px;
    }
    .site-visit-toolbar {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 10px;
    }
    .site-visit-manual-meta {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .site-visit-manual-delete {
        white-space: nowrap;
    }
    .js-detail-photo-status {
        min-height: 18px;
    }
    .prevention-detail-card-title {
        font-weight: 700;
        line-height: 1.4;
    }
    .preview-section + .preview-section {
        margin-top: 16px;
    }
    .preview-section-title {
        font-weight: 700;
        margin-bottom: 8px;
    }
    .preview-empty {
        color: #94a3b8;
    }
    .preview-photo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 10px;
    }
    .preview-photo-card {
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 12px;
        padding: 10px;
        background: rgba(15, 23, 42, 0.3);
    }
    .preview-photo-label {
        font-size: 12px;
        font-weight: 700;
        color: #94a3b8;
        margin-bottom: 6px;
    }
    .preview-photo-image {
        width: 100%;
        height: 120px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(15, 23, 42, 0.45);
    }
    .preview-photo-empty {
        color: #94a3b8;
        font-size: 12px;
        min-height: 120px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        border: 1px dashed rgba(148, 163, 184, 0.22);
        border-radius: 10px;
        background: rgba(15, 23, 42, 0.18);
        padding: 8px;
    }
    .prevention-mobile-list {
        display: none;
    }
    @media (max-width: 767.98px) {
        .container.py-4 {
            padding-left: 12px;
            padding-right: 12px;
        }
        .card-body {
            padding: 14px;
        }
        .table-responsive {
            overflow: visible;
        }
        .site-visit-table,
        .site-visit-table tbody,
        .site-visit-table tr,
        .site-visit-table td,
        .detail-table,
        .detail-table tbody,
        .detail-table tr,
        .detail-table td,
        .prevention-group-table,
        .prevention-group-table tbody,
        .prevention-group-table tr,
        .prevention-group-table td {
            display: block;
            width: 100%;
        }
        .site-visit-table thead,
        .detail-table thead,
        .prevention-group-table thead {
            display: none;
        }
        .site-visit-table tr,
        .detail-table tr,
        .prevention-group-table tr {
            border: 1px solid rgba(214, 165, 69, 0.22);
            border-radius: 12px;
            margin-bottom: 12px;
            overflow: hidden;
            background: rgba(15, 23, 42, 0.55);
        }
        .site-visit-table td,
        .detail-table td,
        .prevention-group-table td {
            border: 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.16);
            padding: 12px 14px;
        }
        .site-visit-table td:last-child,
        .detail-table td:last-child,
        .prevention-group-table td:last-child {
            border-bottom: 0;
        }
        .site-visit-table td[data-label],
        .detail-table td[data-label],
        .prevention-group-table td[data-label] {
            display: grid;
            grid-template-columns: 84px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
        }
        .site-visit-table td[data-label]::before,
        .detail-table td[data-label]::before,
        .prevention-group-table td[data-label]::before {
            content: attr(data-label);
            color: #94a3b8;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            padding-top: 2px;
        }
        .site-visit-table td[data-label="선택"] input,
        .detail-table td[data-label="삭제"] .btn,
        .detail-table td[data-label^="사진"] input[type="file"] {
            width: 100%;
        }
        .detail-table td[data-label="No."] .form-control-plaintext {
            padding-top: 0;
        }
        .detail-table td[data-label="삭제"] .btn {
            min-height: 40px;
        }
        .mobile-form-actions {
            display: grid !important;
            grid-template-columns: 1fr;
        }
        .mobile-form-actions .btn {
            width: 100%;
        }
        .draft-status {
            text-align: center;
        }
        .prevention-work-group {
            padding: 12px;
            margin-bottom: 12px;
        }
        .prevention-work-group .fw-semibold {
            line-height: 1.4;
        }
        .prevention-detail-card-title {
            font-size: 14px;
        }
        .prevention-work-group .table-responsive {
            display: none;
        }
        .prevention-mobile-list {
            display: grid;
            gap: 10px;
        }
        .prevention-mobile-card {
            border: 1px solid rgba(214, 165, 69, 0.2);
            border-radius: 12px;
            padding: 12px;
            background: rgba(15, 23, 42, 0.45);
        }
        .prevention-mobile-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .prevention-mobile-number {
            min-width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(214, 165, 69, 0.16);
            color: #fbbf24;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .prevention-mobile-title {
            flex: 1;
            line-height: 1.5;
            word-break: keep-all;
        }
        .prevention-mobile-view {
            width: 100%;
        }
        .prevention-mobile-status-label {
            display: block;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .site-visit-toolbar {
            justify-content: stretch;
        }
        .site-visit-toolbar .btn {
            width: 100%;
        }
        .site-visit-manual-meta {
            display: grid;
            grid-template-columns: 1fr;
        }
        .site-visit-manual-delete {
            width: 100%;
        }
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
                        <div class="site-visit-toolbar">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="add-custom-site-row">직접입력 행 추가</button>
                        </div>
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
                                <tbody id="site-visit-body">
                                <?php if (empty($todayWorkList)): ?>
                                    <tr class="js-site-visit-empty-row">
                                        <td colspan="4" class="text-center text-muted py-4">조회된 작업이 없습니다.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($todayWorkList as $index => $w): ?>
                                        <tr class="site-visit-row">
                                            <td class="text-center" data-label="선택">
                                                <input type="checkbox"
                                                       class="form-check-input js-site-visit-check"
                                                       id="site_visit_<?= (int)$index ?>"
                                                       data-work-key="<?= h(buildReportWorkOptionKey((int)($w['report_id'] ?? 0))) ?>"
                                                       data-title="<?= h((string)($w['work_title'] ?? '')) ?>"
                                                       data-place="<?= h((string)($w['work_place'] ?? '')) ?>"
                                                       data-team="<?= h((string)($w['team_name'] ?? '')) ?>">
                                            </td>
                                            <td data-label="작업명">
                                                <label for="site_visit_<?= (int)$index ?>" class="mb-0 w-100">
                                                    <?= h((string)($w['work_title'] ?? '')) ?>
                                                </label>
                                            </td>
                                            <td data-label="작업장소"><?= h((string)($w['work_place'] ?? '')) ?></td>
                                            <td data-label="미방문사유">
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
                        <label class="form-label">작업자 의견사항</label>
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
                            <th style="width: 180px;">현장</th>
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

        <div class="modal fade" id="finalPreviewModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="finalPreviewModalLabel">등록 미리보기</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
                    </div>
                    <div class="modal-body" id="finalPreviewModalBody"></div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label">비고</label>
            <textarea name="remark" class="form-control" rows="3"><?= h($values['remark']) ?></textarea>
        </div>

        <div class="d-flex gap-2 mobile-form-actions">
            <button type="button" id="save-draft" class="btn btn-outline-warning">1차저장</button>
            <button type="button" id="preview-submit" class="btn btn-outline-info">미리보기</button>
            <button type="submit" class="btn btn-primary">등록</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.reload()">취소</button>
        </div>
        <div class="draft-status mt-2" id="draft-status">임시저장 없음</div>
    </form>

<script>
    const detailBody = document.getElementById('details-body');
    const addDetailButton = document.getElementById('add-detail');
    const saveDraftButton = document.getElementById('save-draft');
    const previewSubmitButton = document.getElementById('preview-submit');
    const draftStatus = document.getElementById('draft-status');
    const siteVisitTableBody = document.getElementById('site-visit-body');
    const addCustomSiteRowButton = document.getElementById('add-custom-site-row');
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
    const finalPreviewModalElement = document.getElementById('finalPreviewModal');
    const finalPreviewModalBody = document.getElementById('finalPreviewModalBody');
    const draftStorageKey = 'safety_log_create_draft_v1';
    const draftPhotoUploadUrl = 'draft_upload.php';
    let currentDraftData = null;
    let currentSelectionDraftKey = '__none__';
    const weatherMap = <?= json_encode($calendarWeatherMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const detailOptionsByWorkKey = <?= json_encode($detailOptionMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const preventionMeasuresByWorkKey = <?= json_encode($preventionMeasureMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const preventionSummaryByWorkKey = <?= json_encode($preventionDetailMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let preventionSummaryModal = null;
    let finalPreviewModal = null;
    let manualSiteRowCounter = 0;
    let previewObjectUrls = [];

    function getPreventionSummaryModalInstance() {
        if (!preventionSummaryModalElement || !window.bootstrap || !window.bootstrap.Modal) {
            return null;
        }

        if (!preventionSummaryModal) {
            preventionSummaryModal = new window.bootstrap.Modal(preventionSummaryModalElement);
        }

        return preventionSummaryModal;
    }

    function getFinalPreviewModalInstance() {
        if (!finalPreviewModalElement || !window.bootstrap || !window.bootstrap.Modal) {
            return null;
        }

        if (!finalPreviewModal) {
            finalPreviewModal = new window.bootstrap.Modal(finalPreviewModalElement);
        }

        return finalPreviewModal;
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

    function normalizeMeasureText(value) {
        return String(value ?? '')
            .replace(/^(?:[\-•·*]\s*|\d+[.)]\s+)/u, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function getSiteVisitRows() {
        return Array.from(document.querySelectorAll('.site-visit-row'));
    }

    function removeSiteVisitEmptyRow() {
        const emptyRow = siteVisitTableBody ? siteVisitTableBody.querySelector('.js-site-visit-empty-row') : null;
        if (emptyRow) {
            emptyRow.remove();
        }
    }

    function ensureSiteVisitEmptyRow() {
        if (!siteVisitTableBody) {
            return;
        }

        if (siteVisitTableBody.querySelector('.site-visit-row')) {
            return;
        }

        if (siteVisitTableBody.querySelector('.js-site-visit-empty-row')) {
            return;
        }

        siteVisitTableBody.insertAdjacentHTML('beforeend', '<tr class="js-site-visit-empty-row"><td colspan="4" class="text-center text-muted py-4">조회된 작업이 없습니다.</td></tr>');
    }

    function buildManualSiteWorkKey() {
        manualSiteRowCounter += 1;
        return `manual_${Date.now()}_${manualSiteRowCounter}`;
    }

    function getManualSiteRowHtml(workKey, data = {}) {
        const title = escapeHtml(String(data.work_title || '').trim());
        const place = escapeHtml(String(data.work_place || '').trim());
        const reason = escapeHtml(String(data.non_visit_reason || '').trim());
        const checked = data.selected ? ' checked' : '';

        return `
            <tr class="site-visit-row site-visit-row-manual">
                <td class="text-center" data-label="선택">
                    <input type="checkbox"
                           class="form-check-input js-site-visit-check"
                           data-work-key="${escapeHtml(workKey)}"
                           data-title="${title}"
                           data-place="${place}"
                           data-team=""
                           data-manual="1"${checked}>
                </td>
                <td data-label="작업명">
                    <input type="text" class="form-control form-control-sm js-site-visit-title" value="${title}" placeholder="작업명 직접 입력">
                </td>
                <td data-label="작업장소">
                    <input type="text" class="form-control form-control-sm js-site-visit-place" value="${place}" placeholder="작업장소 직접 입력">
                </td>
                <td data-label="미방문사유">
                    <div class="site-visit-manual-meta">
                        <input type="text" class="form-control form-control-sm js-site-visit-reason" value="${reason}" placeholder="미방문 시 사유 입력">
                        <button type="button" class="btn btn-outline-danger btn-sm site-visit-manual-delete js-site-visit-delete">행 삭제</button>
                    </div>
                </td>
            </tr>`;
    }

    function appendManualSiteRow(data = {}) {
        if (!siteVisitTableBody) {
            return null;
        }

        removeSiteVisitEmptyRow();
        const workKey = String(data.work_key || '').trim() || buildManualSiteWorkKey();
        siteVisitTableBody.insertAdjacentHTML('beforeend', getManualSiteRowHtml(workKey, data));
        return siteVisitTableBody.querySelector(`.js-site-visit-check[data-work-key="${CSS.escape(workKey)}"]`)?.closest('.site-visit-row') || null;
    }

    function removeManualSiteRows() {
        getSiteVisitRows().forEach((row) => {
            const checkbox = row.querySelector('.js-site-visit-check');
            if (checkbox && checkbox.dataset.manual === '1') {
                row.remove();
            }
        });
        ensureSiteVisitEmptyRow();
    }

    function syncManualSiteRow(row) {
        const checkbox = row ? row.querySelector('.js-site-visit-check') : null;
        if (!checkbox || checkbox.dataset.manual !== '1') {
            return;
        }

        const titleInput = row.querySelector('.js-site-visit-title');
        const placeInput = row.querySelector('.js-site-visit-place');
        checkbox.dataset.title = titleInput ? String(titleInput.value || '').trim() : '';
        checkbox.dataset.place = placeInput ? String(placeInput.value || '').trim() : '';
    }

    function getSelectedWorkKeys() {
        return getSiteVisitRows()
            .map((row) => row.querySelector('.js-site-visit-check'))
            .filter((checkbox) => checkbox && checkbox.checked)
            .map((checkbox) => checkbox.dataset.workKey || '')
            .filter((workKey) => workKey !== '');
    }

    function hasSelectedManualSiteRow() {
        return getSiteVisitRows().some((row) => {
            const checkbox = row.querySelector('.js-site-visit-check');
            return !!(checkbox && checkbox.checked && checkbox.dataset.manual === '1');
        });
    }

    function getSelectedWorkInfos() {
        return getSiteVisitRows()
            .map((row) => {
                syncManualSiteRow(row);
                const checkbox = row.querySelector('.js-site-visit-check');
                if (!checkbox || !checkbox.checked) {
                    return null;
                }

                return {
                    work_key: String(checkbox.dataset.workKey || ''),
                    work_title: String(checkbox.dataset.title || '').trim(),
                    work_place: String(checkbox.dataset.place || '').trim(),
                    team_name: String(checkbox.dataset.team || '').trim()
                };
            })
            .filter((item) => item && item.work_key !== '')
            ;
    }

    function getSelectedWorkInfosForKeys(workKeys) {
        const selectedKeySet = new Set((Array.isArray(workKeys) ? workKeys : [workKeys])
            .map((workKey) => String(workKey || '').trim())
            .filter((workKey) => workKey !== ''));

        return getSelectedWorkInfos().filter((workInfo) => selectedKeySet.has(String(workInfo.work_key || '').trim()));
    }

    function formatWorkInfoLabel(workInfo) {
        const place = String(workInfo.work_place || '').trim();
        const title = String(workInfo.work_title || '').trim();
        const team = String(workInfo.team_name || '').trim();

        if (place) {
            return place;
        }

        return title || team || String(workInfo.work_key || '현장');
    }

    function getCurrentSelectionDraftKey() {
        const selectedWorkKeys = getSelectedWorkKeys().slice().sort();
        return selectedWorkKeys.length > 0 ? selectedWorkKeys.join('||') : '__none__';
    }

    function getDetailRowWorkKey(row) {
        return String(row?.querySelector('.js-detail-work-key-select')?.value || '').trim();
    }

    function getAvailableWorkOptions() {
        return getSelectedWorkInfos().map((workInfo) => ({
            value: String(workInfo.work_key || '').trim(),
            label: formatWorkInfoLabel(workInfo) || String(workInfo.work_key || '').trim()
        })).filter((option) => option.value !== '');
    }

    function getAvailableActivityOptions(workKeys = null) {
        const selectedWorkKeys = Array.isArray(workKeys)
            ? workKeys.map((workKey) => String(workKey || '').trim()).filter((workKey) => workKey !== '')
            : getSelectedWorkKeys();
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

        if (hasSelectedManualSiteRow()) {
            appendOptionsFromKeys(['__all__']);
        }

        if (merged.size === 0) {
            appendOptionsFromKeys(['__all__']);
        }

        return Array.from(merged.values()).sort((left, right) => left.label.localeCompare(right.label, 'ko'));
    }

    function getAvailableProcessOptions(activityValue, workKeys = null) {
        const normalizedActivity = String(activityValue || '').trim();
        if (!normalizedActivity) {
            return [];
        }

        const matched = getAvailableActivityOptions(workKeys).find((option) => option.value === normalizedActivity);
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
                    work_key: String(item.work_key || ''),
                    work_label: String(item.work_label || ''),
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
            const activityMap = preventionMeasuresByWorkKey[workKey] || preventionMeasuresByWorkKey.__all__ || {};
            const processMap = activityMap[normalizedActivity] || {};
            const currentMeasures = Array.isArray(processMap[normalizedProcess]) ? processMap[normalizedProcess] : [];
            currentMeasures.forEach((measure) => {
                const normalizedMeasure = normalizeMeasureText(measure);
                if (!normalizedMeasure || seen.has(normalizedMeasure)) {
                    return;
                }

                seen.add(normalizedMeasure);
                measures.push(normalizedMeasure);
            });
        });

        return measures;
    }

    function getPreventionSummaryItems(activityValue, processValue, measure, workKey = '') {
        const normalizedActivity = String(activityValue || '').trim();
        const normalizedProcess = String(processValue || '').trim();
        const normalizedMeasure = normalizeMeasureText(measure);
        const normalizedWorkKey = String(workKey || '').trim();
        if (!normalizedActivity || !normalizedProcess || !normalizedMeasure) {
            return [];
        }

        const selectedWorkKeys = normalizedWorkKey !== ''
            ? [normalizedWorkKey]
            : getSelectedWorkKeys();
        const items = [];
        const seen = new Set();

        selectedWorkKeys.forEach((workKey) => {
            const activityMap = preventionSummaryByWorkKey[workKey] || preventionSummaryByWorkKey.__all__ || {};
            const processMap = activityMap[normalizedActivity] || {};
            const measureMap = processMap[normalizedProcess] || {};
            const matchedMeasureKey = Object.keys(measureMap).find((key) => normalizeMeasureText(key) === normalizedMeasure) || '';
            const summaryItems = Array.isArray(measureMap[matchedMeasureKey])
                ? measureMap[matchedMeasureKey]
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

    function showPreventionSummaryModal(activityValue, processValue, measure, workKey = '', workLabel = '') {
        if (!preventionSummaryModalBody || !preventionSummaryModalLabel) {
            return;
        }

        const summaryItems = getPreventionSummaryItems(activityValue, processValue, measure, workKey);
        preventionSummaryModalLabel.textContent = workLabel
            ? `예방대책 상세 - ${workLabel} - ${measure}`
            : `예방대책 상세 - ${measure}`;

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

    function buildPreventionData(activityValue, processValue, previousItems, workInfos = null) {
        const previousStatusMap = new Map();
        (Array.isArray(previousItems) ? previousItems : []).forEach((item) => {
            const workKey = String(item.work_key || '').trim();
            const measure = normalizeMeasureText(item.measure);
            const compositeKey = `${workKey}::${measure}`;
            if (!measure || previousStatusMap.has(compositeKey)) {
                return;
            }
            previousStatusMap.set(compositeKey, {
                work_key: workKey,
                work_label: String(item.work_label || ''),
                status: String(item.status || '')
            });
        });

        const items = [];
        const seen = new Set();
        const targetWorkInfos = Array.isArray(workInfos) && workInfos.length > 0 ? workInfos : getSelectedWorkInfos();
        targetWorkInfos.forEach((workInfo) => {
            const activityMap = preventionMeasuresByWorkKey[workInfo.work_key] || preventionMeasuresByWorkKey.__all__ || {};
            const processMap = activityMap[String(activityValue || '').trim()] || {};
            const measures = Array.isArray(processMap[String(processValue || '').trim()]) ? processMap[String(processValue || '').trim()] : [];

            measures.forEach((measure) => {
                const normalizedMeasure = normalizeMeasureText(measure);
                const compositeKey = `${workInfo.work_key}::${normalizedMeasure}`;
                if (!normalizedMeasure || seen.has(compositeKey)) {
                    return;
                }

                seen.add(compositeKey);
                const previousItem = previousStatusMap.get(compositeKey);
                items.push({
                    work_key: workInfo.work_key,
                    work_label: formatWorkInfoLabel(workInfo) || previousItem?.work_label || '',
                    measure: normalizedMeasure,
                    status: previousItem?.status || ''
                });
            });
        });

        return {
            activity: String(activityValue || ''),
            process: String(processValue || ''),
            items
        };
    }

    function updateRowPreventionInput(row, preventionData) {
        const preventionInput = row.querySelector('.js-prevention-data-input');
        if (!preventionInput) {
            return;
        }

        preventionInput.value = JSON.stringify(preventionData);
    }

    function updateDraftStatus(message) {
        if (!draftStatus) {
            return;
        }

        draftStatus.textContent = message;
    }

    function getPhotoTempInput(row, fieldName) {
        return row ? row.querySelector(`.js-detail-photo-temp[data-photo-field="${fieldName}"]`) : null;
    }

    function getPhotoStatusElement(row, fieldName) {
        return row ? row.querySelector(`.js-detail-photo-status[data-photo-field="${fieldName}"]`) : null;
    }

    function getPhotoInput(row, fieldName) {
        return row ? row.querySelector(`.js-detail-photo-input[data-photo-field="${fieldName}"]`) : null;
    }

    function getSavedPhotoLabel(tempPath) {
        const normalizedPath = String(tempPath || '').trim();
        if (!normalizedPath) {
            return '선택된 파일 없음';
        }

        const segments = normalizedPath.split(/[\\/]/);
        return `임시저장된 사진: ${segments[segments.length - 1] || normalizedPath}`;
    }

    function revokePreviewObjectUrls() {
        previewObjectUrls.forEach((url) => {
            try {
                URL.revokeObjectURL(url);
            } catch (error) {
                // ignore revoke failures
            }
        });
        previewObjectUrls = [];
    }

    function buildDraftPreviewImageUrl(tempPath) {
        const normalizedPath = String(tempPath || '').trim();
        if (!normalizedPath) {
            return '';
        }

        return `show_draft_image.php?file=${encodeURIComponent(normalizedPath)}`;
    }

    function getPreviewPhotoMeta(row, fieldName) {
        const photoInput = getPhotoInput(row, fieldName);
        if (photoInput && photoInput.files && photoInput.files.length > 0) {
            const objectUrl = URL.createObjectURL(photoInput.files[0]);
            previewObjectUrls.push(objectUrl);
            return {
                name: photoInput.files[0].name,
                url: objectUrl,
                hasImage: true,
            };
        }

        const tempPath = getPhotoTempInput(row, fieldName)?.value || '';
        if (tempPath) {
            return {
                name: getSavedPhotoLabel(tempPath).replace('임시저장된 사진: ', ''),
                url: buildDraftPreviewImageUrl(tempPath),
                hasImage: true,
            };
        }

        return {
            name: '없음',
            url: '',
            hasImage: false,
        };
    }

    function renderPreviewPhotoGrid(row) {
        const photoCards = ['photo_1', 'photo_2', 'photo_3'].map((fieldName, index) => {
            const photoMeta = getPreviewPhotoMeta(row, fieldName);
            const contentHtml = photoMeta.hasImage
                ? `<a href="${escapeHtml(photoMeta.url)}" target="_blank" rel="noopener noreferrer"><img src="${escapeHtml(photoMeta.url)}" alt="사진${index + 1}" class="preview-photo-image"></a><div class="form-text mt-2">${escapeHtml(photoMeta.name)}</div>`
                : '<div class="preview-photo-empty">등록된 사진 없음</div>';

            return `
                <div class="preview-photo-card">
                    <div class="preview-photo-label">사진${index + 1}</div>
                    ${contentHtml}
                </div>`;
        }).join('');

        return `<div class="preview-photo-grid">${photoCards}</div>`;
    }

    function renderPreviewTable(headers, rows) {
        return `
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr>
                    </thead>
                    <tbody>${rows.join('')}</tbody>
                </table>
            </div>`;
    }

    function renderPreviewSection(title, contentHtml) {
        return `
            <div class="preview-section">
                <div class="preview-section-title">${escapeHtml(title)}</div>
                ${contentHtml}
            </div>`;
    }

    function getPreviewBasicInfoHtml() {
        const form = document.querySelector('form[action="store.php"]');
        const managerValue = form ? form.querySelector('[name="manager_name"]')?.value || '' : '';
        const subjectValue = form ? form.querySelector('[name="subject"]')?.value || '' : '';
        const summaryValue = form ? form.querySelector('[name="summary"]')?.value || '' : '';
        const remarkValue = form ? form.querySelector('[name="remark"]')?.value || '' : '';
        const rows = [
            ['작성일', logDateInput ? logDateInput.value : ''],
            ['작성자', managerValue],
            ['날씨', weatherInput ? weatherInput.value : ''],
            ['현장명', siteNameInput ? siteNameInput.value : ''],
            ['작업장소', workLocationInput ? workLocationInput.value : ''],
            ['제목', subjectValue],
            ['작업자 의견사항', summaryValue],
            ['비고', remarkValue],
        ].map(([label, value]) => `<tr><th style="width: 140px;">${escapeHtml(label)}</th><td>${escapeHtml(String(value || '')).replace(/\n/g, '<br>') || '&nbsp;'}</td></tr>`);

        return renderPreviewTable(['항목', '내용'], rows);
    }

    function getPreviewSiteRowsHtml() {
        const selectedRows = getSelectedSitePreviewRows();

        if (selectedRows.length === 0) {
            return '<div class="preview-empty">선택된 현장이 없습니다.</div>';
        }

        return renderPreviewTable(
            ['작업명', '작업장소', '미방문사유'],
            selectedRows.map((row) => `<tr><td>${escapeHtml(row.workTitle || '') || '&nbsp;'}</td><td>${escapeHtml(row.workPlace || '') || '&nbsp;'}</td><td>${escapeHtml(row.nonVisitReason || '') || '&nbsp;'}</td></tr>`)
        );
    }

    function getSelectedSitePreviewRows() {
        return getSiteVisitRows().map((row) => {
            syncManualSiteRow(row);
            const checkbox = row.querySelector('.js-site-visit-check');
            const reasonInput = row.querySelector('.js-site-visit-reason');
            const titleInput = row.querySelector('.js-site-visit-title');
            const placeInput = row.querySelector('.js-site-visit-place');
            if (!checkbox || !checkbox.checked) {
                return null;
            }

            return {
                workKey: String(checkbox.dataset.workKey || '').trim(),
                workTitle: titleInput ? titleInput.value.trim() : String(checkbox.dataset.title || '').trim(),
                workPlace: placeInput ? placeInput.value.trim() : String(checkbox.dataset.place || '').trim(),
                nonVisitReason: reasonInput ? reasonInput.value.trim() : ''
            };
        }).filter(Boolean);
    }

    function renderPreviewPreventionItems(preventionData, workKey = '') {
        const filteredItems = (Array.isArray(preventionData.items) ? preventionData.items : []).filter((item) => {
            const itemWorkKey = String(item.work_key || '').trim();
            return workKey === '' ? true : itemWorkKey === workKey;
        });

        if (filteredItems.length === 0) {
            return '';
        }

        return `
            <div class="mt-3">
                ${renderPreviewTable(
                    ['No.', '예방대책', '상태'],
                    filteredItems.map((item, index) => `<tr><td>${index + 1}</td><td>${escapeHtml(item.measure || '')}</td><td>${escapeHtml(item.status || '') || '&nbsp;'}</td></tr>`)
                )}
            </div>`;
    }

    function collectPreviewDetailEntries() {
        const selectedSiteRows = getSelectedSitePreviewRows();
        const fallbackSiteKeys = selectedSiteRows.map((site) => site.workKey).filter((workKey) => workKey !== '');

        return Array.from(detailBody.querySelectorAll('.detail-row')).map((row, index) => {
            const rowWorkKey = getDetailRowWorkKey(row);
            const workTime = row.querySelector('[name$="[work_time]"]')?.value || '';
            const activity = row.querySelector('.js-activity-select')?.value || '';
            const description = row.querySelector('.js-process-select')?.value || '';
            const photoMetas = ['photo_1', 'photo_2', 'photo_3'].map((fieldName) => getPreviewPhotoMeta(row, fieldName));
            const preventionInput = row.querySelector('.js-prevention-data-input');
            const preventionData = parsePreventionData(preventionInput ? preventionInput.value : '');
            const mappedSiteKeys = rowWorkKey !== ''
                ? [rowWorkKey]
                : Array.from(new Set((preventionData.items || []).map((item) => String(item.work_key || '').trim()).filter((workKey) => workKey !== '')));

            if (!workTime && !activity && !description && photoMetas.every((photoMeta) => !photoMeta.hasImage)) {
                return null;
            }

            return {
                index,
                row,
                workTime,
                activity,
                description,
                preventionData,
                mappedSiteKeys: mappedSiteKeys.length > 0 ? mappedSiteKeys : (fallbackSiteKeys.length === 1 ? fallbackSiteKeys : []),
            };
        }).filter(Boolean);
    }

    function renderPreviewDetailCard(entry, siteWorkKey = '') {
        return `
            <div class="border rounded p-3 mb-3 bg-body-tertiary">
                <div class="fw-semibold mb-2">No.${entry.index + 1}</div>
                ${renderPreviewTable(
                    ['항목', '내용'],
                    [
                        `<tr><th style="width: 140px;">시간</th><td>${escapeHtml(entry.workTime) || '&nbsp;'}</td></tr>`,
                        `<tr><th>관찰 업무</th><td>${escapeHtml(entry.activity) || '&nbsp;'}</td></tr>`,
                        `<tr><th>공정</th><td>${escapeHtml(entry.description) || '&nbsp;'}</td></tr>`
                    ]
                )}
                <div class="mt-3">${renderPreviewPhotoGrid(entry.row)}</div>
                ${renderPreviewPreventionItems(entry.preventionData, siteWorkKey)}
            </div>`;
    }

    function getPreviewDetailsHtml() {
        const selectedSiteRows = getSelectedSitePreviewRows();
        const detailEntries = collectPreviewDetailEntries();

        if (selectedSiteRows.length === 0) {
            return '<div class="preview-empty">선택된 현장이 없습니다.</div>';
        }

        if (detailEntries.length === 0) {
            return '<div class="preview-empty">입력된 세부기록이 없습니다.</div>';
        }

        const siteSections = selectedSiteRows.map((site) => {
            const matchedEntries = detailEntries.filter((entry) => entry.mappedSiteKeys.includes(site.workKey));
            return `
                <div class="mb-4">
                    <div class="fw-semibold mb-2">현장: ${escapeHtml(site.workPlace || site.workTitle || '현장')}</div>
                    ${matchedEntries.length > 0 ? matchedEntries.map((entry) => renderPreviewDetailCard(entry, site.workKey)).join('') : '<div class="preview-empty">이 현장에 연결된 세부기록이 없습니다.</div>'}
                </div>`;
        });

        return siteSections.join('');
    }

    function showFinalPreview() {
        updateSiteVisitSummary(false);
        renderPreventionSections();
        revokePreviewObjectUrls();

        if (!finalPreviewModalBody) {
            return;
        }

        finalPreviewModalBody.innerHTML = [
            renderPreviewSection('기본 정보', getPreviewBasicInfoHtml()),
            renderPreviewSection('현장 선택', getPreviewSiteRowsHtml()),
            renderPreviewSection('세부 기록', getPreviewDetailsHtml()),
        ].join('');

        const modalInstance = getFinalPreviewModalInstance();
        if (modalInstance) {
            modalInstance.show();
        }
    }

    function updatePhotoStatus(row, fieldName, message = '') {
        const statusElement = getPhotoStatusElement(row, fieldName);
        if (!statusElement) {
            return;
        }

        if (message) {
            statusElement.textContent = message;
            return;
        }

        const tempInput = getPhotoTempInput(row, fieldName);
        statusElement.textContent = getSavedPhotoLabel(tempInput ? tempInput.value : '');
    }

    function setTempPhotoPath(row, fieldName, tempPath) {
        const tempInput = getPhotoTempInput(row, fieldName);
        if (tempInput) {
            tempInput.value = String(tempPath || '');
        }
        updatePhotoStatus(row, fieldName);
    }

    async function uploadDraftPhoto(row, fieldName) {
        const photoInput = getPhotoInput(row, fieldName);
        if (!photoInput || !photoInput.files || photoInput.files.length === 0) {
            return getPhotoTempInput(row, fieldName)?.value || '';
        }

        const uploadFile = photoInput.files[0];
        const formData = new FormData();
        formData.append('photo', uploadFile);
        formData.append('field_name', fieldName);

        updatePhotoStatus(row, fieldName, '사진 임시업로드 중...');
        const response = await fetch(draftPhotoUploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const result = await response.json();
        if (!response.ok || !result || !result.success) {
            throw new Error(result && result.message ? result.message : '사진 임시업로드에 실패했습니다.');
        }

        setTempPhotoPath(row, fieldName, result.temp_path || '');
        photoInput.value = '';
        return result.temp_path || '';
    }

    async function uploadDraftPhotos() {
        const rows = Array.from(detailBody.querySelectorAll('.detail-row'));
        for (const row of rows) {
            for (const fieldName of ['photo_1', 'photo_2', 'photo_3']) {
                await uploadDraftPhoto(row, fieldName);
            }
        }
    }

    function collectCurrentDetailDrafts() {
        return Array.from(detailBody.querySelectorAll('.detail-row')).map((row, index) => ({
            item_no: index + 1,
            work_key: getDetailRowWorkKey(row),
            work_time: row.querySelector('[name$="[work_time]"]')?.value || '',
            activity: row.querySelector('.js-activity-select')?.value || '',
            description: row.querySelector('.js-process-select')?.value || '',
            prevention_data: row.querySelector('.js-prevention-data-input')?.value || '',
            photo_1_temp: getPhotoTempInput(row, 'photo_1')?.value || '',
            photo_2_temp: getPhotoTempInput(row, 'photo_2')?.value || '',
            photo_3_temp: getPhotoTempInput(row, 'photo_3')?.value || ''
        }));
    }

    function setDetailRowsFromDrafts(detailDrafts) {
        detailBody.innerHTML = '';
        if (!Array.isArray(detailDrafts) || detailDrafts.length === 0) {
            addDetailRow();
        } else {
            detailDrafts.forEach((detail) => addDetailRow(detail || {}));
        }
        refreshAllDetailRows();
    }

    function persistCurrentSelectionDetails() {
        if (!currentDraftData || typeof currentDraftData !== 'object') {
            currentDraftData = {};
        }

        if (!currentDraftData.site_detail_map || typeof currentDraftData.site_detail_map !== 'object') {
            currentDraftData.site_detail_map = {};
        }

        currentDraftData.site_detail_map[currentSelectionDraftKey] = collectCurrentDetailDrafts();
    }

    function buildMergedDetailDraftsForSelection(workKeys) {
        if (!currentDraftData || !currentDraftData.site_detail_map || typeof currentDraftData.site_detail_map !== 'object') {
            return [];
        }

        const mergedDrafts = [];
        workKeys.forEach((workKey) => {
            const draftRows = currentDraftData.site_detail_map[workKey];
            if (!Array.isArray(draftRows)) {
                return;
            }

            draftRows.forEach((detail) => {
                if (!detail || typeof detail !== 'object') {
                    return;
                }

                mergedDrafts.push({
                    item_no: mergedDrafts.length + 1,
                    work_key: String(detail.work_key || workKey || ''),
                    work_time: String(detail.work_time || ''),
                    activity: String(detail.activity || ''),
                    description: String(detail.description || ''),
                    prevention_data: String(detail.prevention_data || ''),
                    photo_1_temp: String(detail.photo_1_temp || ''),
                    photo_2_temp: String(detail.photo_2_temp || ''),
                    photo_3_temp: String(detail.photo_3_temp || ''),
                });
            });
        });

        return mergedDrafts;
    }

    function normalizeDetailDraftsForSelection(detailDrafts, workKeys) {
        if (!Array.isArray(detailDrafts) || detailDrafts.length === 0) {
            return detailDrafts;
        }

        const normalizedWorkKeys = Array.isArray(workKeys)
            ? workKeys.map((workKey) => String(workKey || '').trim()).filter((workKey) => workKey !== '')
            : [];

        if (normalizedWorkKeys.length <= 1) {
            return detailDrafts;
        }

        const hasAssignedWorkKey = detailDrafts.some((detail) => String(detail?.work_key || '').trim() !== '');
        if (hasAssignedWorkKey) {
            return detailDrafts;
        }

        const mergedDrafts = buildMergedDetailDraftsForSelection(normalizedWorkKeys);
        return mergedDrafts.length > 0 ? mergedDrafts : detailDrafts;
    }

    function collectDraftData() {
        const form = document.querySelector('form[action="store.php"]');
        const managerInput = form ? form.querySelector('[name="manager_name"]') : null;
        const subjectInput = form ? form.querySelector('[name="subject"]') : null;
        const summaryInput = form ? form.querySelector('[name="summary"]') : null;
        const remarkInput = form ? form.querySelector('[name="remark"]') : null;
        const siteDetailMap = (currentDraftData && typeof currentDraftData.site_detail_map === 'object' && currentDraftData.site_detail_map)
            ? { ...currentDraftData.site_detail_map }
            : {};
        siteDetailMap[getCurrentSelectionDraftKey()] = collectCurrentDetailDrafts();

        return {
            saved_at: new Date().toISOString(),
            log_date: logDateInput ? logDateInput.value : '',
            manager_name: managerInput ? managerInput.value : '',
            weather: weatherInput ? weatherInput.value : '',
            subject: subjectInput ? subjectInput.value : '',
            summary: summaryInput ? summaryInput.value : '',
            remark: remarkInput ? remarkInput.value : '',
            site_visit_rows: getSiteVisitRows().map((row) => {
                syncManualSiteRow(row);
                const checkbox = row.querySelector('.js-site-visit-check');
                const reasonInput = row.querySelector('.js-site-visit-reason');
                const titleInput = row.querySelector('.js-site-visit-title');
                const placeInput = row.querySelector('.js-site-visit-place');
                return {
                    work_key: checkbox ? String(checkbox.dataset.workKey || '') : '',
                    is_manual: checkbox ? String(checkbox.dataset.manual || '') === '1' : false,
                    selected: checkbox ? checkbox.checked : false,
                    work_title: titleInput ? String(titleInput.value || '').trim() : (checkbox ? String(checkbox.dataset.title || '').trim() : ''),
                    work_place: placeInput ? String(placeInput.value || '').trim() : (checkbox ? String(checkbox.dataset.place || '').trim() : ''),
                    team_name: checkbox ? String(checkbox.dataset.team || '').trim() : '',
                    non_visit_reason: reasonInput ? reasonInput.value : ''
                };
            }),
            site_detail_map: siteDetailMap
        };
    }

    function saveDraft() {
        const draftData = collectDraftData();
        currentDraftData = draftData;
        currentSelectionDraftKey = getCurrentSelectionDraftKey();
        window.localStorage.setItem(draftStorageKey, JSON.stringify(draftData));
        const savedAt = new Date(draftData.saved_at);
        updateDraftStatus(`1차저장 완료: ${savedAt.toLocaleString('ko-KR')}`);
    }

    function loadDraftData() {
        const rawDraft = window.localStorage.getItem(draftStorageKey);
        if (!rawDraft) {
            updateDraftStatus('임시저장 없음');
            return null;
        }

        try {
            const parsed = JSON.parse(rawDraft);
            if (!parsed || typeof parsed !== 'object') {
                updateDraftStatus('임시저장 없음');
                return null;
            }

            if (parsed.saved_at) {
                updateDraftStatus(`1차저장 불러옴: ${new Date(parsed.saved_at).toLocaleString('ko-KR')}`);
            }

            currentDraftData = parsed;
            return parsed;
        } catch (error) {
            updateDraftStatus('임시저장 읽기 실패');
            return null;
        }
    }

    function applyDraftData(draftData) {
        if (!draftData || typeof draftData !== 'object') {
            return;
        }

        const form = document.querySelector('form[action="store.php"]');
        const managerInput = form ? form.querySelector('[name="manager_name"]') : null;
        const subjectInput = form ? form.querySelector('[name="subject"]') : null;
        const summaryInput = form ? form.querySelector('[name="summary"]') : null;
        const remarkInput = form ? form.querySelector('[name="remark"]') : null;

        if (logDateInput && draftData.log_date) {
            logDateInput.value = String(draftData.log_date);
        }
        if (managerInput && draftData.manager_name !== undefined) {
            managerInput.value = String(draftData.manager_name || '');
        }
        if (subjectInput && draftData.subject !== undefined) {
            subjectInput.value = String(draftData.subject || '');
        }
        if (summaryInput && draftData.summary !== undefined) {
            summaryInput.value = String(draftData.summary || '');
        }
        if (remarkInput && draftData.remark !== undefined) {
            remarkInput.value = String(draftData.remark || '');
        }

        removeManualSiteRows();

        (Array.isArray(draftData.site_visit_rows) ? draftData.site_visit_rows : []).forEach((row) => {
            if (!row || !row.is_manual) {
                return;
            }

            appendManualSiteRow(row);
        });

        const rowMap = new Map((Array.isArray(draftData.site_visit_rows) ? draftData.site_visit_rows : []).map((row) => [String(row.work_key || ''), row]));
        getSiteVisitRows().forEach((row) => {
            const checkbox = row.querySelector('.js-site-visit-check');
            const reasonInput = row.querySelector('.js-site-visit-reason');
            const titleInput = row.querySelector('.js-site-visit-title');
            const placeInput = row.querySelector('.js-site-visit-place');
            if (!checkbox) {
                return;
            }

            const savedRow = rowMap.get(String(checkbox.dataset.workKey || ''));
            checkbox.checked = !!(savedRow && savedRow.selected);
            if (titleInput) {
                titleInput.value = savedRow ? String(savedRow.work_title || '') : '';
            }
            if (placeInput) {
                placeInput.value = savedRow ? String(savedRow.work_place || '') : '';
            }
            if (reasonInput) {
                reasonInput.value = savedRow ? String(savedRow.non_visit_reason || '') : '';
            }
            syncManualSiteRow(row);
        });

        currentSelectionDraftKey = getCurrentSelectionDraftKey();
        let detailDrafts = draftData.site_detail_map && Array.isArray(draftData.site_detail_map[currentSelectionDraftKey])
            ? draftData.site_detail_map[currentSelectionDraftKey]
            : (Array.isArray(draftData.details) ? draftData.details : []);
        detailDrafts = normalizeDetailDraftsForSelection(detailDrafts, getSelectedWorkKeys());
        if (draftData.site_detail_map && typeof draftData.site_detail_map === 'object') {
            draftData.site_detail_map[currentSelectionDraftKey] = detailDrafts;
        }
        setDetailRowsFromDrafts(detailDrafts);
        updateSiteVisitSummary(false);
        if (weatherInput && draftData.weather !== undefined) {
            weatherInput.value = String(draftData.weather || '');
        }
    }

    function switchDetailRowsForSelection() {
        const nextSelectionDraftKey = getCurrentSelectionDraftKey();
        if (nextSelectionDraftKey === currentSelectionDraftKey) {
            refreshAllDetailRows();
            return;
        }

        const currentDetailDrafts = collectCurrentDetailDrafts();
        persistCurrentSelectionDetails();

        let nextDetailDrafts = currentDraftData && currentDraftData.site_detail_map && Array.isArray(currentDraftData.site_detail_map[nextSelectionDraftKey])
            ? currentDraftData.site_detail_map[nextSelectionDraftKey]
            : [];

        nextDetailDrafts = normalizeDetailDraftsForSelection(nextDetailDrafts, getSelectedWorkKeys());
        if (currentDraftData && currentDraftData.site_detail_map) {
            currentDraftData.site_detail_map[nextSelectionDraftKey] = nextDetailDrafts;
        }

        if (nextDetailDrafts.length === 0) {
            const selectedWorkKeys = getSelectedWorkKeys();
            const mergedDrafts = selectedWorkKeys.length > 1
                ? buildMergedDetailDraftsForSelection(selectedWorkKeys)
                : [];
            nextDetailDrafts = mergedDrafts.length > 0 ? mergedDrafts : currentDetailDrafts;
        }

        if (currentDraftData && currentDraftData.site_detail_map && !Array.isArray(currentDraftData.site_detail_map[nextSelectionDraftKey])) {
            currentDraftData.site_detail_map[nextSelectionDraftKey] = nextDetailDrafts;
        }

        currentSelectionDraftKey = nextSelectionDraftKey;
        setDetailRowsFromDrafts(nextDetailDrafts);
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

        if (!hasSelected && normalizedSelected !== '') {
            html.push(`<option value="${escapeHtml(normalizedSelected)}" selected>${escapeHtml(normalizedSelected)} (임시유지)</option>`);
            hasSelected = true;
        }

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

        const rowWorkKey = getDetailRowWorkKey(row);
        const workKeys = rowWorkKey !== '' ? [rowWorkKey] : [];

        const processOptions = getAvailableProcessOptions(activitySelect.value, workKeys).map((processName) => ({
            value: processName,
            label: processName
        }));

        setSelectOptions(
            processSelect,
            processOptions,
            rowWorkKey !== ''
                ? (processOptions.length > 0 ? '공정 선택' : '선택한 현장에 공정이 없습니다')
                : '현장 먼저 선택',
            selectedProcess !== null ? selectedProcess : (processSelect.dataset.selected || processSelect.value),
            processOptions.length === 0 || rowWorkKey === ''
        );
        processSelect.dataset.selected = processSelect.value;
    }

    function refreshDetailWorkKeySelect(row, selectedWorkKey = null) {
        const workKeySelect = row.querySelector('.js-detail-work-key-select');
        if (!workKeySelect) {
            return;
        }

        const workOptions = getAvailableWorkOptions();
        const normalizedSelectedWorkKey = selectedWorkKey !== null ? selectedWorkKey : (workKeySelect.dataset.selected || workKeySelect.value);
        setSelectOptions(
            workKeySelect,
            workOptions,
            workOptions.length > 0 ? '현장 선택' : '현장 먼저 선택',
            normalizedSelectedWorkKey,
            workOptions.length === 0
        );

        if (workOptions.length === 1 && !workKeySelect.value) {
            workKeySelect.value = workOptions[0].value;
        }

        workKeySelect.dataset.selected = workKeySelect.value;
    }

    function refreshActivitySelect(row, selectedActivity = null, selectedProcess = null) {
        const activitySelect = row.querySelector('.js-activity-select');
        if (!activitySelect) {
            return;
        }

        const rowWorkKey = getDetailRowWorkKey(row);
        const workKeys = rowWorkKey !== '' ? [rowWorkKey] : [];
        const activityOptions = getAvailableActivityOptions(workKeys);
        setSelectOptions(
            activitySelect,
            activityOptions,
            rowWorkKey !== ''
                ? (activityOptions.length > 0 ? '작업유형 선택' : '선택한 현장에 작업유형이 없습니다')
                : '현장 먼저 선택',
            selectedActivity !== null ? selectedActivity : (activitySelect.dataset.selected || activitySelect.value),
            activityOptions.length === 0 || rowWorkKey === ''
        );
        activitySelect.dataset.selected = activitySelect.value;
        refreshProcessSelect(row, selectedProcess);
    }

    function refreshAllDetailRows() {
        detailBody.querySelectorAll('.detail-row').forEach((row) => {
            const workKeySelect = row.querySelector('.js-detail-work-key-select');
            const activitySelect = row.querySelector('.js-activity-select');
            const processSelect = row.querySelector('.js-process-select');
            refreshDetailWorkKeySelect(
                row,
                workKeySelect ? workKeySelect.value || workKeySelect.dataset.selected || '' : ''
            );
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
            const rowWorkKey = getDetailRowWorkKey(row);
            const activitySelect = row.querySelector('.js-activity-select');
            const processSelect = row.querySelector('.js-process-select');
            const preventionInput = row.querySelector('.js-prevention-data-input');
            const activityValue = activitySelect ? String(activitySelect.value || '').trim() : '';
            const processValue = processSelect ? String(processSelect.value || '').trim() : '';
            const rowWorkInfos = rowWorkKey !== '' ? getSelectedWorkInfosForKeys([rowWorkKey]) : [];

            if (!rowWorkKey || !activityValue || !processValue) {
                updateRowPreventionInput(row, { activity: activityValue, process: processValue, items: [] });
                return;
            }

            const previousData = parsePreventionData(preventionInput ? preventionInput.value : '');
            const preventionData = buildPreventionData(activityValue, processValue, previousData.items || [], rowWorkInfos);
            updateRowPreventionInput(row, preventionData);

            if (preventionData.items.length === 0) {
                return;
            }

            const groupHtml = rowWorkInfos.map((workInfo) => {
                const workKey = String(workInfo.work_key || '').trim();
                const workLabel = formatWorkInfoLabel(workInfo) || workKey;
                const groupItems = preventionData.items.filter((item) => String(item.work_key || '').trim() === workKey);

                if (groupItems.length === 0) {
                    return `
                        <div class="border rounded p-3 mb-3 bg-body-tertiary prevention-work-group">
                            <div class="prevention-detail-card-title mb-2">No.${index + 1} / ${escapeHtml(activityValue)} / ${escapeHtml(processValue)}</div>
                            <div class="fw-semibold mb-2">현장: ${escapeHtml(workLabel)}</div>
                            <div class="text-muted small">표시할 예방대책이 없습니다.</div>
                        </div>`;
                }

                const rowsHtml = groupItems.map((item, preventionIndex) => `
                    <tr>
                        <td data-label="No.">${preventionIndex + 1}</td>
                        <td data-label="예방대책">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <span>${escapeHtml(item.measure)}</span>
                                <button type="button" class="btn btn-outline-secondary btn-sm js-prevention-view" data-row-index="${index}" data-work-key="${escapeHtml(workKey)}" data-work-label="${escapeHtml(workLabel)}" data-activity="${escapeHtml(activityValue)}" data-process="${escapeHtml(processValue)}" data-measure="${escapeHtml(item.measure)}">보기</button>
                            </div>
                        </td>
                        <td data-label="상태" style="width: 180px;">
                            <select class="form-select form-select-sm js-prevention-status-select" data-row-index="${index}" data-work-key="${escapeHtml(workKey)}" data-measure="${escapeHtml(item.measure)}">
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

                const mobileCardsHtml = groupItems.map((item, preventionIndex) => `
                    <div class="prevention-mobile-card">
                        <div class="prevention-mobile-card-header">
                            <span class="prevention-mobile-number">${preventionIndex + 1}</span>
                            <div class="prevention-mobile-title">${escapeHtml(item.measure)}</div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm js-prevention-view prevention-mobile-view mb-3" data-row-index="${index}" data-work-key="${escapeHtml(workKey)}" data-work-label="${escapeHtml(workLabel)}" data-activity="${escapeHtml(activityValue)}" data-process="${escapeHtml(processValue)}" data-measure="${escapeHtml(item.measure)}">상세 보기</button>
                        <label class="prevention-mobile-status-label">상태</label>
                        <select class="form-select form-select-sm js-prevention-status-select" data-row-index="${index}" data-work-key="${escapeHtml(workKey)}" data-measure="${escapeHtml(item.measure)}">
                            <option value="">선택</option>
                            <option value="양호"${item.status === '양호' ? ' selected' : ''}>양호</option>
                            <option value="불량"${item.status === '불량' ? ' selected' : ''}>불량</option>
                            <option value="조치완료"${item.status === '조치완료' ? ' selected' : ''}>조치완료</option>
                            <option value="후속조치필요"${item.status === '후속조치필요' ? ' selected' : ''}>후속조치필요</option>
                            <option value="[평가서 수정필요]"${item.status === '[평가서 수정필요]' ? ' selected' : ''}>[평가서 수정필요]</option>
                            <option value="해당없음"${item.status === '해당없음' ? ' selected' : ''}>해당없음</option>
                        </select>
                    </div>`).join('');

                return `
                    <div class="border rounded p-3 mb-3 bg-body-tertiary prevention-work-group">
                        <div class="prevention-detail-card-title mb-2">No.${index + 1} / ${escapeHtml(activityValue)} / ${escapeHtml(processValue)}</div>
                        <div class="fw-semibold mb-2">현장: ${escapeHtml(workLabel)}</div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 prevention-group-table">
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
                        <div class="prevention-mobile-list">${mobileCardsHtml}</div>
                    </div>`;
            }).join('');

            sectionHtml.push(groupHtml);
        });

        preventionSections.innerHTML = sectionHtml.join('');
        preventionEmpty.classList.toggle('d-none', sectionHtml.length > 0);
    }

    function getDetailRowHtml(index, data = {}) {
        const workKey = data.work_key ?? '';
        const workTime = data.work_time ?? '';
        const activity = data.activity ?? '';
        const description = data.description ?? '';
        const preventionData = data.prevention_data ?? '';
        const photo1Temp = data.photo_1_temp ?? '';
        const photo2Temp = data.photo_2_temp ?? '';
        const photo3Temp = data.photo_3_temp ?? '';
        const workOptionsHtml = ['<option value="">현장 선택</option>'];
        if (workKey) {
            workOptionsHtml.push(`<option value="${escapeHtml(workKey)}" selected>${escapeHtml(workKey)}</option>`);
        }
        const activityOptionsHtml = ['<option value="">작업 선택 후 작업유형 선택</option>'];
        if (activity) {
            activityOptionsHtml.push(`<option value="${escapeHtml(activity)}" selected>${escapeHtml(activity)}</option>`);
        }

        return `
            <tr class="detail-row">
                <td data-label="No."><input type="hidden" name="details[${index}][item_no]" value="${index + 1}"><input type="hidden" name="details[${index}][prevention_data]" class="js-prevention-data-input" value="${escapeHtml(preventionData)}"><span class="form-control-plaintext">${index + 1}</span></td>
                <td data-label="시간"><input type="time" name="details[${index}][work_time]" class="form-control" value="${workTime}" step="600"></td>
                <td data-label="현장">
                    <select name="details[${index}][work_key]" class="form-select js-detail-work-key-select" data-selected="${escapeHtml(workKey)}">
                        ${workOptionsHtml.join('')}
                    </select>
                </td>
                <td data-label="관찰 업무">
                    <select name="details[${index}][activity]" class="form-select js-activity-select" data-selected="${escapeHtml(activity)}">
                        ${activityOptionsHtml.join('')}
                    </select>
                </td>
                <td data-label="공정">
                    <select name="details[${index}][description]" class="form-select js-process-select" data-selected="${escapeHtml(description)}">
                        <option value="">작업유형 먼저 선택</option>
                    </select>
                </td>
                <td data-label="사진1"><input type="file" name="details[${index}][photo_1]" class="form-control js-detail-photo-input" data-photo-field="photo_1"><input type="hidden" name="details[${index}][photo_1_temp]" class="js-detail-photo-temp" data-photo-field="photo_1" value="${escapeHtml(photo1Temp)}"><div class="form-text js-detail-photo-status" data-photo-field="photo_1">${escapeHtml(getSavedPhotoLabel(photo1Temp))}</div></td>
                <td data-label="사진2"><input type="file" name="details[${index}][photo_2]" class="form-control js-detail-photo-input" data-photo-field="photo_2"><input type="hidden" name="details[${index}][photo_2_temp]" class="js-detail-photo-temp" data-photo-field="photo_2" value="${escapeHtml(photo2Temp)}"><div class="form-text js-detail-photo-status" data-photo-field="photo_2">${escapeHtml(getSavedPhotoLabel(photo2Temp))}</div></td>
                <td data-label="사진3"><input type="file" name="details[${index}][photo_3]" class="form-control js-detail-photo-input" data-photo-field="photo_3"><input type="hidden" name="details[${index}][photo_3_temp]" class="js-detail-photo-temp" data-photo-field="photo_3" value="${escapeHtml(photo3Temp)}"><div class="form-text js-detail-photo-status" data-photo-field="photo_3">${escapeHtml(getSavedPhotoLabel(photo3Temp))}</div></td>
                <td data-label="삭제"><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
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
        refreshDetailWorkKeySelect(row, data.work_key ?? '');
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
    previewSubmitButton?.addEventListener('click', () => {
        showFinalPreview();
    });
    saveDraftButton?.addEventListener('click', async () => {
        const originalText = saveDraftButton.textContent;
        saveDraftButton.disabled = true;
        saveDraftButton.textContent = '사진 업로드 중...';

        try {
            await uploadDraftPhotos();
            updateSiteVisitSummary();
            saveDraft();
        } catch (error) {
            updateDraftStatus(error instanceof Error ? error.message : '사진 임시저장 중 오류가 발생했습니다.');
        } finally {
            saveDraftButton.disabled = false;
            saveDraftButton.textContent = originalText;
        }
    });

    detailBody.addEventListener('click', onDeleteRow);
    detailBody.addEventListener('change', (event) => {
        if (!event.target.classList.contains('js-detail-photo-input')) {
            return;
        }

        const row = event.target.closest('.detail-row');
        const fieldName = event.target.dataset.photoField || '';
        if (!row || !fieldName) {
            return;
        }

        updatePhotoStatus(row, fieldName, event.target.files && event.target.files.length > 0 ? `선택됨: ${event.target.files[0].name}` : getSavedPhotoLabel(getPhotoTempInput(row, fieldName)?.value || ''));
    });
    detailBody.addEventListener('change', (event) => {
        if (!event.target.classList.contains('js-detail-work-key-select') && !event.target.classList.contains('js-activity-select') && !event.target.classList.contains('js-process-select')) {
            return;
        }

        const row = event.target.closest('.detail-row');
        if (!row) {
            return;
        }

        event.target.dataset.selected = event.target.value;
        if (event.target.classList.contains('js-detail-work-key-select')) {
            refreshActivitySelect(row, '');
        }
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
        const workKey = String(event.target.dataset.workKey || '').trim();
        const measure = normalizeMeasureText(event.target.dataset.measure);
        const rows = Array.from(detailBody.querySelectorAll('.detail-row'));
        const row = rows[rowIndex] || null;
        if (!row || !measure) {
            return;
        }

        const preventionInput = row.querySelector('.js-prevention-data-input');
        const preventionData = parsePreventionData(preventionInput ? preventionInput.value : '');
        preventionData.items = (preventionData.items || []).map((item) => {
            if (String(item.work_key || '').trim() !== workKey || normalizeMeasureText(item.measure) !== measure) {
                return item;
            }

            return {
                work_key: item.work_key,
                work_label: item.work_label,
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
            String(button.dataset.measure || ''),
            String(button.dataset.workKey || ''),
            String(button.dataset.workLabel || '')
        );
    });

    function updateSiteVisitSummary(shouldSwitchDetails = true) {
        const rows = getSiteVisitRows().map((row) => {
            syncManualSiteRow(row);
            const checkbox = row.querySelector('.js-site-visit-check');
            const reasonInput = row.querySelector('.js-site-visit-reason');
            const titleInput = row.querySelector('.js-site-visit-title');
            const placeInput = row.querySelector('.js-site-visit-place');
            return {
                work_key: checkbox ? checkbox.dataset.workKey || '' : '',
                is_manual: checkbox ? String(checkbox.dataset.manual || '') === '1' : false,
                selected: checkbox ? checkbox.checked : false,
                work_title: titleInput ? titleInput.value.trim() : (checkbox ? checkbox.dataset.title || '' : ''),
                work_place: placeInput ? placeInput.value.trim() : (checkbox ? checkbox.dataset.place || '' : ''),
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
        if (currentDraftData) {
            currentDraftData.site_visit_rows = rows;
        }

        if (shouldSwitchDetails) {
            switchDetailRowsForSelection();
            return;
        }

        refreshAllDetailRows();
    }

    if (siteVisitTableBody) {
        siteVisitTableBody.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.classList.contains('js-site-visit-check')) {
                updateSiteVisitSummary();
                return;
            }

            if (target.classList.contains('js-site-visit-title') || target.classList.contains('js-site-visit-place')) {
                const row = target.closest('.site-visit-row');
                if (row) {
                    syncManualSiteRow(row);
                }
                updateSiteVisitSummary(false);
                return;
            }
        });

        siteVisitTableBody.addEventListener('input', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.classList.contains('js-site-visit-reason')) {
                updateSiteVisitSummary(false);
                return;
            }

            if (target.classList.contains('js-site-visit-title') || target.classList.contains('js-site-visit-place')) {
                const row = target.closest('.site-visit-row');
                if (row) {
                    syncManualSiteRow(row);
                }
                updateSiteVisitSummary(false);
            }
        });

        siteVisitTableBody.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement) || !target.classList.contains('js-site-visit-delete')) {
                return;
            }

            const row = target.closest('.site-visit-row');
            if (!row) {
                return;
            }

            row.remove();
            ensureSiteVisitEmptyRow();
            updateSiteVisitSummary();
        });
    }

    if (addCustomSiteRowButton) {
        addCustomSiteRowButton.addEventListener('click', () => {
            const row = appendManualSiteRow({ selected: true });
            updateSiteVisitSummary();
            const titleInput = row ? row.querySelector('.js-site-visit-title') : null;
            if (titleInput) {
                titleInput.focus();
            }
        });
    }

    const createForm = document.querySelector('form[action="store.php"]');
    if (createForm) {
        createForm.addEventListener('submit', () => {
            updateSiteVisitSummary();
            window.localStorage.removeItem(draftStorageKey);
            updateDraftStatus('임시저장 없음');
        });
    }

    const loadedDraftData = loadDraftData();
    if (loadedDraftData) {
        applyDraftData(loadedDraftData);
    } else {
        updateSiteVisitSummary();
        refreshAllDetailRows();
    }

    if (logDateInput) {
        logDateInput.addEventListener('change', syncWeatherByDate);
    }

    finalPreviewModalElement?.addEventListener('hidden.bs.modal', () => {
        revokePreviewObjectUrls();
    });

    syncWeatherByDate();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
