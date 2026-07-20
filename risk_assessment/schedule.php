<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_schedule_url(array $params = []): string
{
    $query = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $query[$key] = $value;
    }

    return empty($query) ? 'schedule.php' : 'schedule.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

$boardPageUrl = '../board/index.php';

$user = auth_current_user();
$userTeamName = auth_normalize_team_name((string)($user['team'] ?? ''));
$displayName = trim((string)auth_display_name($user));
$canViewGasTeamSchedule = (string)($user['role'] ?? '') === 'safety_manager'
    || in_array($displayName, ['진종철', '정주랑', '정연탁'], true);

// ?붿껌??? ?뚮씪誘명꽣 ?뺤씤 (怨듭궗?-?꾧린 愿由ш컧?낆옄 ?뱀? ?댁쁺???덉쟾愿由ъ옄媛 ?ㅻⅨ ????쇱젙??蹂???
$requestedTeamName = '';
$isViewingOtherTeam = false;
$canViewOtherTeam = auth_is_admin($user) || (string)($user['role'] ?? '') === 'safety_manager' || (
    auth_can_manage($user) && auth_team_key($userTeamName) === auth_team_key('怨듭궗?-?꾧린')
) || $canViewGasTeamSchedule;

if (!empty($_GET['view_team'])) {
    $requestedTeamName = auth_normalize_team_name((string)($_GET['view_team']));
    if ($requestedTeamName !== '' && $requestedTeamName !== $userTeamName) {
        if ($canViewOtherTeam) {
            $isViewingOtherTeam = true;
        } else {
            header('Location: task_select.php');
            exit;
        }
    }
}

// ?쒖떆??? 寃곗젙 (?붿껌??????덉쑝硫?洹??, ?놁쑝硫??ъ슜?먯쓽 ?)
$teamName = $isViewingOtherTeam ? $requestedTeamName : $userTeamName;
$teamScheduleKey = $teamName !== '' ? $teamName : 'unknown-team';

$userRole = (string)($user['role'] ?? '');
$canEditOwnSchedule = auth_can_manage($user) || in_array($userRole, ['leader', 'administrator'], true);
$canEditViewedTeam = auth_is_admin($user);

// ?쎄린 ?꾩슜 紐⑤뱶: ?ㅻⅨ ????쇱젙??蹂닿굅??沅뚰븳???놁쑝硫?읽기 ?꾩슜
$isReadOnly = !$canEditOwnSchedule || ($isViewingOtherTeam && !$canEditViewedTeam);

if (!$isViewingOtherTeam && $isReadOnly) {
    // ?묒뾽?먮뒗 ?먯떊??? ?쇱젙留??대엺 媛??
    if (!in_array($userRole, ['worker', 'leader', 'administrator'], true)) {
        header('Location: task_select.php');
        exit;
    }
}

function schedule_data_path(): string
{
    return __DIR__ . '/schedule_data.json';
}

function load_legacy_schedule_data(): array
{
    $path = schedule_data_path();
    if (!is_file($path)) {
        return ['teams' => []];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return ['teams' => []];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return ['teams' => []];
    }

    if (!isset($decoded['teams']) || !is_array($decoded['teams'])) {
        $decoded['teams'] = [];
    }

    return $decoded;
}

function ensure_schedule_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS team_schedule (
            schedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_key VARCHAR(120) NOT NULL,
            team_name VARCHAR(120) NOT NULL,
            schedule_date DATE NOT NULL,
            shift_type ENUM('day', 'night', 'off') NOT NULL,
            worker_names VARCHAR(255) NOT NULL,
            updated_by_name VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (schedule_id),
            UNIQUE KEY uk_team_schedule_entry (team_key, schedule_date, shift_type),
            KEY idx_team_schedule_lookup (team_key, schedule_date),
            KEY idx_team_schedule_team_name (team_name, schedule_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function load_schedule_data(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT team_key, team_name, schedule_date, shift_type, worker_names, updated_at, updated_by_name
        FROM team_schedule
        ORDER BY team_name ASC, schedule_date ASC, shift_type ASC
    ");

    $data = ['teams' => []];
    $lastUpdatedAt = null;
    $lastUpdatedBy = null;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $teamKey = (string)($row['team_key'] ?? '');
        $date = (string)($row['schedule_date'] ?? '');
        $shift = (string)($row['shift_type'] ?? '');
        $value = trim((string)($row['worker_names'] ?? ''));

        if ($teamKey === '' || $date === '' || $shift === '' || $value === '') {
            continue;
        }

        if (!isset($data['teams'][$teamKey]) || !is_array($data['teams'][$teamKey])) {
            $data['teams'][$teamKey] = [];
        }
        if (!isset($data['teams'][$teamKey][$date]) || !is_array($data['teams'][$teamKey][$date])) {
            $data['teams'][$teamKey][$date] = [];
        }

        $data['teams'][$teamKey][$date][$shift] = $value;

        $updatedAt = (string)($row['updated_at'] ?? '');
        if ($updatedAt !== '' && ($lastUpdatedAt === null || $updatedAt > $lastUpdatedAt)) {
            $lastUpdatedAt = $updatedAt;
            $lastUpdatedBy = (string)($row['updated_by_name'] ?? '');
        }
    }

    if ($lastUpdatedAt !== null) {
        $data['updated_at'] = $lastUpdatedAt;
        $data['updated_by'] = $lastUpdatedBy;
    }

    return $data;
}

function save_team_schedule(PDO $pdo, string $teamKey, string $teamName, array $teamSchedule, string $updatedByName): bool
{
    try {
        $pdo->beginTransaction();

        $deleteStmt = $pdo->prepare("DELETE FROM team_schedule WHERE team_key = :team_key");
        $deleteStmt->execute(['team_key' => $teamKey]);

        $insertStmt = $pdo->prepare("
            INSERT INTO team_schedule (
                team_key,
                team_name,
                schedule_date,
                shift_type,
                worker_names,
                updated_by_name
            ) VALUES (
                :team_key,
                :team_name,
                :schedule_date,
                :shift_type,
                :worker_names,
                :updated_by_name
            )
        ");

        foreach ($teamSchedule as $date => $shifts) {
            if (!is_array($shifts)) {
                continue;
            }

            foreach ($shifts as $shift => $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }

                $insertStmt->execute([
                    'team_key' => $teamKey,
                    'team_name' => $teamName !== '' ? $teamName : $teamKey,
                    'schedule_date' => $date,
                    'shift_type' => $shift,
                    'worker_names' => $value,
                    'updated_by_name' => $updatedByName,
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function migrate_legacy_schedule_data(PDO $pdo): void
{
    $tableCount = (int)$pdo->query("SELECT COUNT(*) FROM team_schedule")->fetchColumn();
    if ($tableCount > 0) {
        return;
    }

    $legacy = load_legacy_schedule_data();
    $teams = $legacy['teams'] ?? [];
    if (!is_array($teams) || $teams === []) {
        return;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO team_schedule (
            team_key,
            team_name,
            schedule_date,
            shift_type,
            worker_names,
            updated_by_name
        ) VALUES (
            :team_key,
            :team_name,
            :schedule_date,
            :shift_type,
            :worker_names,
            :updated_by_name
        )
    ");

    $updatedBy = trim((string)($legacy['updated_by'] ?? ''));

    $pdo->beginTransaction();
    try {
        foreach ($teams as $teamKey => $dates) {
            if (!is_array($dates)) {
                continue;
            }

            foreach ($dates as $scheduleDate => $shifts) {
                if (!is_array($shifts)) {
                    continue;
                }

                foreach ($shifts as $shiftType => $workerNames) {
                    $workerNames = trim((string)$workerNames);
                    if ($workerNames === '') {
                        continue;
                    }

                    $insertStmt->execute([
                        'team_key' => (string)$teamKey,
                        'team_name' => (string)$teamKey,
                        'schedule_date' => (string)$scheduleDate,
                        'shift_type' => (string)$shiftType,
                        'worker_names' => $workerNames,
                        'updated_by_name' => $updatedBy !== '' ? $updatedBy : null,
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function normalize_schedule_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime(str_replace(['.', '/'], '-', $value));
    if ($timestamp === false) {
        return null;
    }

    $normalized = date('Y-m-d', $timestamp);
    if ($normalized === '1970-01-01') {
        return null;
    }

    return $normalized;
}

function normalize_shift_key(string $shift): string
{
    $shift = trim($shift);
    $shift = preg_replace('/[\s\-_]+/u', '', mb_strtolower($shift, 'UTF-8'));

    return match ($shift) {
        '주간작업자', '주간', 'day', 'dayshift', '1차', '1차근무자', '1cha' => 'day',
        '야간작업자', '야간', 'night', 'nightshift', '2차', '2차근무자', '2cha' => 'night',
        '휴무작업자', '휴무', 'off', 'rest' => 'off',
        default => 'day',
    };
}

function schedule_shift_label(string $shift): string
{
    return match ($shift) {
        'day' => '주간작업자',
        'night' => '야간작업자',
        'off' => '휴무작업자',
        default => $shift,
    };
}

function build_month_start(string $currentDate): string
{
    $timestamp = strtotime($currentDate);
    if ($timestamp === false) {
        $timestamp = time();
    }

    return date('Y-m-01', $timestamp);
}

function get_month_dates(string $monthStart): array
{
    $timestamp = strtotime($monthStart);
    if ($timestamp === false) {
        $timestamp = time();
    }

    $daysInMonth = (int)date('t', $timestamp);
    $dates = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dates[] = date('Y-m-d', strtotime(sprintf('%s +%d days', $monthStart, $day - 1)));
    }

    return $dates;
}

function get_month_weeks(string $monthStart): array
{
    $timestamp = strtotime($monthStart);
    if ($timestamp === false) {
        $timestamp = time();
    }

    $firstDayOfWeek = (int)date('w', $timestamp);
    $calendarStart = strtotime(sprintf('%s -%d days', $monthStart, $firstDayOfWeek));
    $lastDayOfMonth = date('t', $timestamp);
    $monthEnd = strtotime(sprintf('%s +%d days', $monthStart, $lastDayOfMonth - 1));
    $lastDayOfWeek = (int)date('w', $monthEnd);
    $calendarEnd = strtotime(sprintf('%s +%d days', date('Y-m-d', $monthEnd), 6 - $lastDayOfWeek));

    $weeks = [];
    $current = $calendarStart;
    while ($current <= $calendarEnd) {
        $weekIndex = floor((strtotime(date('Y-m-d', $current)) - $calendarStart) / 86400 / 7);
        if (!isset($weeks[$weekIndex])) {
            $weeks[$weekIndex] = [];
        }
        $day = date('Y-m-d', $current);
        $weeks[$weekIndex][] = date('Ym', strtotime($day)) === date('Ym', $timestamp) ? $day : null;
        $current = strtotime('+1 day', $current);
    }

    return $weeks;
}

function build_month_label(array $monthDates): string
{
    $firstDate = $monthDates[0] ?? '';
    if ($firstDate === '') {
        return '';
    }

    return date('Y년 n월', strtotime($firstDate));
}

function load_team_schedule(array $data, string $teamKey): array
{
    if (!isset($data['teams'][$teamKey]) || !is_array($data['teams'][$teamKey])) {
        return [];
    }

    return $data['teams'][$teamKey];
}

function set_team_schedule_entry(array &$data, string $teamKey, string $date, string $shift, string $value): void
{
    if (!isset($data['teams'][$teamKey]) || !is_array($data['teams'][$teamKey])) {
        $data['teams'][$teamKey] = [];
    }

    if ($value === '') {
        if (isset($data['teams'][$teamKey][$date][$shift])) {
            unset($data['teams'][$teamKey][$date][$shift]);
        }
        if (empty($data['teams'][$teamKey][$date])) {
            unset($data['teams'][$teamKey][$date]);
        }
        return;
    }

    $data['teams'][$teamKey][$date][$shift] = $value;
}

function delete_team_schedule_entry(array &$data, string $teamKey, string $date, string $shift): void
{
    set_team_schedule_entry($data, $teamKey, $date, $shift, '');
}

function clear_team_schedule_month(array &$data, string $teamKey, string $monthStart): void
{
    if (!isset($data['teams'][$teamKey]) || !is_array($data['teams'][$teamKey])) {
        return;
    }

    $monthPrefix = date('Y-m', strtotime($monthStart));
    foreach (array_keys($data['teams'][$teamKey]) as $dateKey) {
        if (strncmp((string)$dateKey, $monthPrefix, 7) === 0) {
            unset($data['teams'][$teamKey][$dateKey]);
        }
    }

    if (empty($data['teams'][$teamKey])) {
        unset($data['teams'][$teamKey]);
    }
}

function parse_csv_rows(string $filePath): array
{
    $rows = [];
    if (($handle = fopen($filePath, 'rb')) === false) {
        return $rows;
    }

    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function normalize_uploaded_entry_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $normalized = preg_replace('/[\r\n]+/', ', ', $value);
    $normalized = preg_replace('/\s*[,;\/]+\s*/u', ', ', $normalized);
    $normalized = preg_replace('/\s{2,}/u', ' ', $normalized);

    $parts = array_filter(array_map('trim', explode(',', $normalized)), static fn($item) => $item !== '');
    return implode(', ', $parts);
}

function build_uploaded_entry_value(array $parts): string
{
    $cleaned = [];
    foreach ($parts as $part) {
        $part = normalize_uploaded_entry_value((string)$part);
        if ($part !== '') {
            $cleaned[] = $part;
        }
    }

    if (empty($cleaned)) {
        return '';
    }

    return implode(', ', $cleaned);
}

function normalize_schedule_date_or_excel_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
        $number = (float)$value;
        if ($number > 31) {
            if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($number)->format('Y-m-d');
                } catch (Throwable $e) {
                    // fallback to manual conversion
                }
            }

            try {
                $base = new DateTimeImmutable('1899-12-30');
                return $base->modify(sprintf('+%d days', (int)floor($number)))->format('Y-m-d');
            } catch (Throwable $e) {
                // fallback to string parse below
            }
        }
    }

    return normalize_schedule_date($value);
}

function find_valid_worker_name(string $name, array $validWorkerNames): ?string
{
    $candidate = trim($name);
    if ($candidate === '') {
        return null;
    }

    if (empty($validWorkerNames)) {
        return $candidate;
    }

    foreach ($validWorkerNames as $validName) {
        if (mb_strtolower($candidate, 'UTF-8') === mb_strtolower($validName, 'UTF-8')) {
            return $validName;
        }
    }

    return null;
}

function normalize_matrix_cell_shift(string $value): ?string
{
    $clean = mb_strtolower(trim($value), 'UTF-8');
    if ($clean === '') {
        return null;
    }

    $clean = preg_replace('/[^\p{L}\d]+/u', '', $clean);
    return match ($clean) {
        '1', '1차', '1차근무자', '1차근무', '1cha', '주간', 'day', 'dayshift', '주간작업자', '주간근무' => 'day',
        '2', '2차', '2차근무자', '2차근무', '2cha', '야간', 'night', 'nightshift', '야간작업자', '야간근무' => 'night',
        default => null,
    };
}

function parse_matrix_schedule_upload(array $rows, array $validWorkerNames, string $monthStart = ''): array
{
    if (count($rows) < 2) {
        return [];
    }

    $headerRow = $rows[0];
    if (!is_array($headerRow)) {
        return [];
    }

    $dateColumns = [];
    foreach ($headerRow as $index => $cell) {
        if ($index === 0) {
            continue;
        }

        $date = normalize_schedule_date_or_excel_date((string)$cell);
        if ($date !== null) {
            $dateColumns[$index] = $date;
        }
    }

    if (empty($dateColumns)) {
        return [];
    }

    $assignments = [];
    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex === 0 || !is_array($row)) {
            continue;
        }

        $rowName = find_valid_worker_name((string)($row[0] ?? ''), $validWorkerNames);
        if ($rowName === null) {
            continue;
        }

        foreach ($dateColumns as $colIndex => $date) {
            $cellValue = trim((string)($row[$colIndex] ?? ''));
            $shift = normalize_matrix_cell_shift($cellValue);
            if ($shift === null) {
                continue;
            }

            $assignments[$date][$shift][] = $rowName;
        }
    }

    $mappedRows = [];
    foreach ($assignments as $date => $shiftMap) {
        foreach ($shiftMap as $shift => $names) {
            $mappedRows[] = [
                'date' => $date,
                'shift' => $shift,
                'value' => implode(', ', array_unique($names)),
            ];
        }
    }

    return $mappedRows;
}

function filter_uploaded_worker_value(string $value, array $validWorkerNames): string
{
    $normalized = normalize_uploaded_entry_value($value);
    if ($normalized === '') {
        return '';
    }

    if (empty($validWorkerNames)) {
        return $normalized;
    }

    $validMap = [];
    foreach ($validWorkerNames as $workerName) {
        $normalizedWorker = mb_strtolower($workerName, 'UTF-8');
        if ($normalizedWorker === '') {
            continue;
        }
        $validMap[$normalizedWorker] = $workerName;
    }

    $selected = [];
    $usedKeys = [];
    foreach (array_filter(array_map('trim', explode(',', $normalized)), static fn($item) => $item !== '') as $item) {
        $lower = mb_strtolower($item, 'UTF-8');
        if (isset($validMap[$lower]) && !isset($usedKeys[$lower])) {
            $selected[] = $validMap[$lower];
            $usedKeys[$lower] = true;
        }
    }

    return implode(', ', $selected);
}

function parse_korean_shift_schedule(array $rows, string $monthStart, array $validWorkerNames = []): array
{
    $year  = (int)date('Y', strtotime($monthStart));
    $month = (int)date('n', strtotime($monthStart));

    // Build case-insensitive lookup for valid worker names
    $validWorkerMap = [];
    foreach ($validWorkerNames as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $validWorkerMap[mb_strtolower($name, 'UTF-8')] = $name;
        }
    }

    // Find the date row: a row where columns 2+ contain sequential day numbers 1-31
    // PhpSpreadsheet returns Excel text-prefixed numbers (apostrophe+N) with a backtick: "`1"
    $dateRowIndex = -1;
    $dateColMap   = [];

    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) {
            continue;
        }
        $tempCols = [];
        foreach ($row as $colIndex => $cell) {
            if ($colIndex < 2) {
                continue;
            }
            $raw = trim((string)($cell ?? ''));
            $num = ltrim($raw, '`\'');   // strip backtick or apostrophe prefix
            if (!is_numeric($num)) {
                continue;
            }
            $day = (int)$num;
            if ($day >= 1 && $day <= 31 && checkdate($month, $day, $year)) {
                $tempCols[$colIndex] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        // Accept row if it covers at least 20 days (handles partial months at edges)
        if (count($tempCols) >= 20) {
            $dateRowIndex = $rowIndex;
            $dateColMap   = $tempCols;
            break;
        }
    }

    if ($dateRowIndex === -1 || empty($dateColMap)) {
        return [];
    }

    // Parse data rows: worker name in col 0 (carried forward), shift in col 1, 1=working
    $assignments     = [];
    $currentName     = null;

    for ($i = $dateRowIndex + 1, $total = count($rows); $i < $total; $i++) {
        $row = $rows[$i];
        if (!is_array($row)) {
            continue;
        }

        $nameCell  = trim((string)($row[0] ?? ''));
        $shiftCell = trim((string)($row[1] ?? ''));

        if ($nameCell !== '') {
            $lower = mb_strtolower($nameCell, 'UTF-8');
            // Use canonical name from validWorkerMap; skip if not in the list (e.g. 愿由ш컧?낆옄)
            $currentName = !empty($validWorkerMap)
                ? ($validWorkerMap[$lower] ?? null)
                : $nameCell;
        }
        if ($currentName === null || $currentName === '') {
            continue;
        }

        // Normalise shift label: "1차" => day, "2차" => night
        $shiftNorm = preg_replace('/[\s\-_]+/u', '', mb_strtolower($shiftCell, 'UTF-8'));
        $shift = match ($shiftNorm) {
            '1차', '1차근무', '1차근무자', '주간', 'day', 'dayshift', '1' => 'day',
            '2차', '2차근무', '2차근무자', '야간', 'night', 'nightshift', '2' => 'night',
            default => null,
        };
        if ($shift === null) {
            continue;
        }

        foreach ($dateColMap as $colIndex => $date) {
            $cellVal = $row[$colIndex] ?? null;
            // Any truthy non-zero value means "working this day"
            if ($cellVal !== null && $cellVal !== '' && $cellVal != 0) {
                $assignments[$date][$shift][] = $currentName;
            }
        }
    }

    // ?대Т 怨꾩궛: validWorkerMap 湲곗??쇰줈 二쇨컙/?쇨컙 紐⑤몢 ?녿뒗 ??= ?대Т
    if (!empty($validWorkerMap)) {
        foreach (array_unique(array_values($dateColMap)) as $date) {
            $offWorkers = [];
            foreach (array_values($validWorkerMap) as $workerName) {
                $inDay   = in_array($workerName, $assignments[$date]['day']   ?? [], true);
                $inNight = in_array($workerName, $assignments[$date]['night'] ?? [], true);
                if (!$inDay && !$inNight) {
                    $offWorkers[] = $workerName;
                }
            }
            if (!empty($offWorkers)) {
                $assignments[$date]['off'] = $offWorkers;
            }
        }
    }

    $mappedRows = [];
    foreach ($assignments as $date => $shiftMap) {
        foreach ($shiftMap as $shift => $names) {
            $mappedRows[] = [
                'date'  => $date,
                'shift' => $shift,
                'value' => implode(', ', array_unique($names)),
            ];
        }
    }

    return $mappedRows;
}

function parse_schedule_upload(string $filePath, string $originalName, array $validWorkerNames, string $monthStart): array
{
    $rows = [];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension === 'csv') {
        $rows = parse_csv_rows($filePath);
    } else {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PHPExcel autoload file not found.');
        }

        require_once $autoload;
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        if (!empty($rows)) {
            $rows = array_values($rows);
        }
    }

    if (empty($rows)) {
        return [];
    }

    $mappedRows = [];
    $headerRow = [];
    $firstRow = $rows[0];

    if (is_array($firstRow)) {
        $headerRow = array_map(static fn($cell) => trim((string)$cell), $firstRow);
    }

    $hasHeader = false;
    $headerMap = [];

    foreach ($headerRow as $index => $header) {
        $lower = mb_strtolower(trim($header), 'UTF-8');
        if ($lower === '') {
            continue;
        }

        if (in_array($lower, ['date', '일자', '근무일', '근무일자', 'date(yyyy-mm-dd)'], true)) {
            $headerMap['date'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['shift', '근무형태', '근무구분', '구분'], true)) {
            $headerMap['shift'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['name', '근무자', '작업자', '이름', '내용'], true)) {
            $headerMap['value'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['worker1', '작업자1', '근무자1', 'name1', '이름1'], true)) {
            $headerMap['value1'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['worker2', '작업자2', '근무자2', 'name2', '이름2'], true)) {
            $headerMap['value2'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['memo', '메모', 'note'], true)) {
            $headerMap['memo'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['day', '주간', '주간작업자', '주간근무자', '1차', '1차근무자', '1 차', '1 차근무자'], true)) {
            $headerMap['day'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['night', '야간', '야간작업자', '야간근무자', '2차', '2차근무자', '2 차', '2 차근무자'], true)) {
            $headerMap['night'] = $index;
            $hasHeader = true;
        }
        if (in_array($lower, ['off', '휴무', '휴무작업자', '휴무근무자'], true)) {
            $headerMap['off'] = $index;
            $hasHeader = true;
        }
    }

    if ($hasHeader && isset($headerMap['date']) && (isset($headerMap['day']) || isset($headerMap['night']) || isset($headerMap['off']))) {
        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            if (!is_array($row)) {
                continue;
            }

            $rowDate = $row[$headerMap['date']] ?? '';
            $rowDate = trim((string)$rowDate);
            if ($rowDate === '') {
                continue;
            }

            $date = $rowDate;
            if (is_numeric($date) && class_exists('\PhpOffice\PhpSpreadsheet\Shared\Date')) {
                try {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$date)->format('Y-m-d');
                } catch (Throwable $e) {
                    // leave as-is and let normalize_schedule_date decide
                }
            }

            $dayValue = '';
            $nightValue = '';
            $offValue = '';

            if (isset($headerMap['day'])) {
                $rawValue = trim((string)($row[$headerMap['day']] ?? ''));
                $dayValue = filter_uploaded_worker_value($rawValue, $validWorkerNames);
                if ($dayValue !== '') {
                    $mappedRows[] = ['date' => $date, 'shift' => 'day', 'value' => $dayValue];
                }
            }

            if (isset($headerMap['night'])) {
                $rawValue = trim((string)($row[$headerMap['night']] ?? ''));
                $nightValue = filter_uploaded_worker_value($rawValue, $validWorkerNames);
                if ($nightValue !== '') {
                    $mappedRows[] = ['date' => $date, 'shift' => 'night', 'value' => $nightValue];
                }
            }

            if (isset($headerMap['off'])) {
                $rawValue = trim((string)($row[$headerMap['off']] ?? ''));
                $offValue = normalize_uploaded_entry_value($rawValue);
                if ($offValue !== '') {
                    $mappedRows[] = ['date' => $date, 'shift' => 'off', 'value' => $offValue];
                }
            }

            if (!isset($headerMap['off']) && ($dayValue === '' && $nightValue === '') && (isset($headerMap['day']) || isset($headerMap['night']))) {
                $mappedRows[] = ['date' => $date, 'shift' => 'off', 'value' => '?대Т'];
            }
        }

        return $mappedRows;
    }

    if ($hasHeader && isset($headerMap['date']) && isset($headerMap['shift'])) {
        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            if (!is_array($row)) {
                continue;
            }
            $nameParts = [];
            if (isset($headerMap['value'])) {
                $nameParts[] = (string)($row[$headerMap['value']] ?? '');
            }
            if (isset($headerMap['value1'])) {
                $nameParts[] = (string)($row[$headerMap['value1']] ?? '');
            }
            if (isset($headerMap['value2'])) {
                $nameParts[] = (string)($row[$headerMap['value2']] ?? '');
            }
            $memoPart = isset($headerMap['memo']) ? trim((string)($row[$headerMap['memo']] ?? '')) : '';

            $filteredNames = build_uploaded_entry_value(array_map(static fn($part) => filter_uploaded_worker_value((string)$part, $validWorkerNames), $nameParts));
            if ($memoPart !== '') {
                $filteredNames = build_uploaded_entry_value([$filteredNames, $memoPart]);
            }

            $mappedRows[] = [
                'date' => trim((string)($row[$headerMap['date']] ?? '')),
                'shift' => trim((string)($row[$headerMap['shift']] ?? '')),
                'value' => $filteredNames,
            ];
        }

        return $mappedRows;
    }

    if (empty($mappedRows)) {
        // Try Korean shift-table format (worker rows 횞 day columns, 1李?2李?per worker)
        $koreanRows = parse_korean_shift_schedule($rows, $monthStart, $validWorkerNames);
        if (!empty($koreanRows)) {
            return $koreanRows;
        }
    }

    if (empty($mappedRows)) {
        $matrixRows = parse_matrix_schedule_upload($rows, $validWorkerNames, $monthStart);
        if (!empty($matrixRows)) {
            return $matrixRows;
        }
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $date = trim((string)($row[0] ?? ''));
        $shift = trim((string)($row[1] ?? ''));
        $valueParts = [
            filter_uploaded_worker_value((string)($row[2] ?? ''), $validWorkerNames),
            filter_uploaded_worker_value((string)($row[3] ?? ''), $validWorkerNames),
        ];
        $value = build_uploaded_entry_value($valueParts);

        if ($date === '' && $shift === '' && $value === '') {
            continue;
        }

        $mappedRows[] = ['date' => $date, 'shift' => $shift, 'value' => $value];
    }

    return $mappedRows;
}

$errors = [];
$messages = [];
$pdo = getDB();
ensure_schedule_table($pdo);
migrate_legacy_schedule_data($pdo);
$data = load_schedule_data($pdo);
$teamSchedule = load_team_schedule($data, $teamScheduleKey);

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDate = normalize_schedule_date($selectedDate) ?? date('Y-m-d');
$monthStart = build_month_start($selectedDate);
$monthDates = get_month_dates($monthStart);
$monthWeeks = get_month_weeks($monthStart);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_schedule_entry'])) {
        $entryDate = normalize_schedule_date((string)($_POST['entry_date'] ?? ''));
        $entryShift = normalize_shift_key((string)($_POST['entry_shift'] ?? ''));
        $entryValue = trim((string)($_POST['entry_value'] ?? ''));

        if ($entryDate === null) {
            $errors[] = '유효한 날짜를 선택해 주세요.';
        } elseif (!in_array($entryShift, ['day', 'night', 'off'], true)) {
            $errors[] = '유효한 근무 유형을 선택해 주세요.';
        } else {
            set_team_schedule_entry($data, $teamScheduleKey, $entryDate, $entryShift, $entryValue);
            $teamSchedule = load_team_schedule($data, $teamScheduleKey);

            if (!save_team_schedule($pdo, $teamScheduleKey, $teamName, $teamSchedule, auth_display_name(auth_current_user()))) {
                $errors[] = '일정을 저장하지 못했습니다.';
            } else {
                $messages[] = '일정을 저장했습니다.';
                $data = load_schedule_data($pdo);
                $teamSchedule = load_team_schedule($data, $teamScheduleKey);
            }
        }
    }

    if (isset($_POST['delete_schedule_entry'])) {
        $entryDate = normalize_schedule_date((string)($_POST['entry_date'] ?? ''));
        $entryShift = normalize_shift_key((string)($_POST['entry_shift'] ?? ''));
        if ($entryDate === null || !in_array($entryShift, ['day', 'night', 'off'], true)) {
            $errors[] = '삭제할 일정 정보를 확인해 주세요.';
        } else {
            delete_team_schedule_entry($data, $teamScheduleKey, $entryDate, $entryShift);
            $teamSchedule = load_team_schedule($data, $teamScheduleKey);

            if (!save_team_schedule($pdo, $teamScheduleKey, $teamName, $teamSchedule, auth_display_name(auth_current_user()))) {
                $errors[] = '일정을 삭제하지 못했습니다.';
            } else {
                $messages[] = '일정을 삭제했습니다.';
                $data = load_schedule_data($pdo);
                $teamSchedule = load_team_schedule($data, $teamScheduleKey);
            }
        }
    }

    if (isset($_POST['upload_schedule']) && isset($_FILES['schedule_file']) && $_FILES['schedule_file']['error'] === UPLOAD_ERR_OK) {
        $uploadedFile = $_FILES['schedule_file'];
        $tmpPath = $uploadedFile['tmp_name'];
        $originalName = $uploadedFile['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $errors[] = '지원되는 파일 형식은 xlsx, xls, csv 입니다.';
        } else {
            try {
                $validWorkerNames = auth_team_member_names($teamName, ['worker', 'leader']);
                $rows = parse_schedule_upload($tmpPath, $originalName, $validWorkerNames, $monthStart);
                clear_team_schedule_month($data, $teamScheduleKey, $monthStart);
                $importedCount = 0;

                foreach ($rows as $row) {
                    $rowDate = normalize_schedule_date($row['date'] ?? '');
                    $rowShift = normalize_shift_key($row['shift'] ?? '');
                    $rowValue = trim($row['value'] ?? '');
                    if ($rowDate === null || !in_array($rowShift, ['day', 'night', 'off'], true) || $rowValue === '') {
                        continue;
                    }
                    set_team_schedule_entry($data, $teamScheduleKey, $rowDate, $rowShift, $rowValue);
                    $importedCount++;
                }

                if ($importedCount === 0) {
                    $errors[] = '업로드한 파일에서 유효한 일정을 찾지 못했습니다.';
                } else {
                    $teamSchedule = load_team_schedule($data, $teamScheduleKey);
                    if (!save_team_schedule($pdo, $teamScheduleKey, $teamName, $teamSchedule, auth_display_name(auth_current_user()))) {
                        $errors[] = '업로드한 일정을 저장하지 못했습니다.';
                    } else {
                        $messages[] = "근무표 업로드가 완료되었습니다. {$importedCount}개 항목이 반영되었습니다.";
                        $data = load_schedule_data($pdo);
                        $teamSchedule = load_team_schedule($data, $teamScheduleKey);
                    }
                }
            } catch (Throwable $e) {
                $errors[] = '업로드 처리 중 오류가 발생했습니다: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['clear_schedule']) && !$isReadOnly) {
        clear_team_schedule_month($data, $teamScheduleKey, $monthStart);
        $teamSchedule = load_team_schedule($data, $teamScheduleKey);

        if (!save_team_schedule($pdo, $teamScheduleKey, $teamName, $teamSchedule, auth_display_name(auth_current_user()))) {
            $errors[] = '초기화 중 오류가 발생했습니다.';
        } else {
            $messages[] = '이번 달 일정이 모두 초기화되었습니다.';
            $data = load_schedule_data($pdo);
            $teamSchedule = load_team_schedule($data, $teamScheduleKey);
        }
    }
}
$teamWorkers = auth_team_members($teamName);
$teamWorkerPhoneMap = [];
foreach ($teamWorkers as $worker) {
    $workerName = trim((string)($worker['name'] ?? ''));
    if ($workerName === '') {
        continue;
    }
    $teamWorkerPhoneMap[$workerName] = trim((string)($worker['phone'] ?? ''));
}

$monthLabel = build_month_label($monthDates);
$nextMonth = date('Y-m-d', strtotime('+1 month', strtotime($monthStart)));
$prevMonth = date('Y-m-d', strtotime('-1 month', strtotime($monthStart)));
$scheduleViewTeamParam = $isViewingOtherTeam ? $requestedTeamName : '';
$prevMonthUrl = build_schedule_url(['date' => $prevMonth, 'view_team' => $scheduleViewTeamParam]);
$nextMonthUrl = build_schedule_url(['date' => $nextMonth, 'view_team' => $scheduleViewTeamParam]);
$todayScheduleUrl = build_schedule_url(['date' => date('Y-m-d'), 'view_team' => $scheduleViewTeamParam]) . '#schedule-today';
$currentMonthActionUrl = build_schedule_url(['date' => $selectedDate, 'view_team' => $scheduleViewTeamParam]);
$scheduleListUrl = build_schedule_url(['view_team' => $scheduleViewTeamParam]);
$hasExplicitSelectedDate = !empty($_GET['date']);

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>근무 일정표</title>
<link rel="stylesheet" href="modern_ui.css?v=20260406a">
<style>
  :root {
    --bg:       #0c1420;
    --bg2:      #111d2e;
    --bg3:      #162033;
    --border:   rgba(255,255,255,0.07);
    --border2:  rgba(255,255,255,0.12);
    --text:     #c5d8eb;
    --text-dim: #5d7a96;
    --text-hi:  #e8f2fc;
    --accent:   #e8920a;
    --accent2:  #f5a623;
    --blue:     #3a7fc1;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: "Malgun Gothic", sans-serif; background: var(--bg) !important; min-height: 100vh; color: var(--text) !important; padding: 28px 20px 48px; }
  .shell { max-width: 1400px; margin: 0 auto; }
  .topbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 22px; padding-bottom: 18px; border-bottom: 1px solid var(--border); }
  .identity { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .btn-secondary { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border-radius: 9px; cursor: pointer; padding: 10px 16px; font-size: 13px; font-weight: 600; font-family: inherit; background: rgba(255,255,255,0.07) !important; color: var(--text) !important; border: 1px solid var(--border2) !important; }
  .btn-secondary:hover { background: rgba(255,255,255,0.12) !important; }
  .panel { background: var(--bg2) !important; border: 1px solid var(--border) !important; border-radius: 16px !important; overflow: hidden; }
  .panel-head { padding: 22px 24px 14px; background: var(--bg2) !important; border-bottom: 1px solid var(--border); }
  .panel-head-label { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .panel-head h1 { font-size: 24px; font-weight: 900; color: var(--text-hi); margin-bottom: 6px; }
  .panel-head p { color: var(--text-dim); font-size: 13px; }
  .error { background: rgba(214,69,65,0.12); border: 1px solid rgba(214,69,65,0.35); color: #ffd8d6; border-radius: 10px; padding: 12px 16px; margin: 16px 24px; font-size: 13px; }
  .success { background: rgba(46,160,67,0.12); border: 1px solid rgba(46,160,67,0.35); color: #aff5b4; border-radius: 10px; padding: 12px 16px; margin: 16px 24px; font-size: 13px; }

  /* ?? ?щ젰 ?ㅻ퉬寃뚯씠???? */
  .schedule-nav { display: grid; gap: 10px; margin-bottom: 18px; }
  .schedule-nav-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
  .schedule-nav-row .btn-secondary { min-width: 80px; padding: 7px 12px; font-size: 12px; }
  .schedule-nav strong { color: var(--text-hi); font-size: 20px; font-weight: 900; }
  .schedule-date-search {
    display: none;
    gap: 8px;
    align-items: center;
  }
  .schedule-date-search-input {
    flex: 1 1 auto;
    min-width: 0;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid var(--border2);
    background: rgba(255,255,255,0.04);
    color: var(--text-hi);
    font: inherit;
    color-scheme: dark;
  }
  .schedule-date-search-input::-webkit-calendar-picker-indicator {
    filter: brightness(0) invert(1);
    opacity: 1;
    cursor: pointer;
  }
  .schedule-date-search-input::-webkit-date-and-time-value {
    color: var(--text-hi);
    -webkit-text-fill-color: var(--text-hi);
  }
  .schedule-date-search-input::-webkit-datetime-edit,
  .schedule-date-search-input::-webkit-datetime-edit-fields-wrapper,
  .schedule-date-search-input::-webkit-datetime-edit-text,
  .schedule-date-search-input::-webkit-datetime-edit-year-field,
  .schedule-date-search-input::-webkit-datetime-edit-month-field,
  .schedule-date-search-input::-webkit-datetime-edit-day-field {
    color: var(--text-hi);
    -webkit-text-fill-color: var(--text-hi);
  }

  /* ?? ?щ젰 洹몃━???? */
  .schedule-grid { display: grid; gap: 10px; width: 100%; margin-bottom: 24px; }
  .calendar-header,
  .calendar-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; }
  .calendar-header-cell { border: 1px solid var(--border); border-radius: 10px; padding: 8px; text-align: center; font-weight: 700; font-size: 13px; background: var(--bg3); color: var(--text-dim); }
  .calendar-day { min-width: 0; border: 1px solid var(--border); border-radius: 12px; padding: 10px; background: var(--bg3); display: flex; flex-direction: column; gap: 6px; }
  .calendar-day-empty { background: var(--bg2); border-color: var(--border); opacity: 0.4; }
  .calendar-day-selected {
    border-color: rgba(245,166,35,0.55);
    box-shadow: 0 0 0 1px rgba(245,166,35,0.18), 0 14px 28px rgba(0,0,0,0.18);
  }
  .calendar-day-title { display: flex; justify-content: space-between; align-items: center; gap: 4px; margin-bottom: 2px; }
  .calendar-day-number { font-size: 15px; font-weight: 700; color: var(--text-hi); }
  .calendar-day-today .calendar-day-number { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: var(--accent); color: #fff; font-size: 13px; }
  .calendar-day-weekday { font-size: 11px; color: var(--text-dim); }
  .calendar-day-entries { display: grid; gap: 5px; }

  /* ?? ??ぉ 移대뱶 ?? */
  .calendar-entry { box-sizing: border-box; width: 100%; border: 1px solid var(--border2); border-radius: 8px; background: var(--bg2); color: var(--text); text-align: left; padding: 6px 9px; cursor: pointer; transition: background .15s; }
  .calendar-entry:hover { background: rgba(255,255,255,0.06); }
  body.readonly .calendar-entry { cursor: default; }
  body.readonly .calendar-entry:hover { background: var(--bg2); }
  .calendar-entry-label { display: block; font-size: 10px; color: var(--text-dim); margin-bottom: 3px; letter-spacing: .04em; }
  .calendar-entry-text { display: block; font-size: 12px; line-height: 1.45; color: var(--text-hi); white-space: pre-wrap; word-break: break-word; }
  .calendar-entry-day   { border-color: rgba(58,127,193,0.45); }
  .calendar-entry-night { border-color: rgba(130,90,200,0.45); }
  .calendar-entry-off   { border-color: rgba(232,146,10,0.35); }
  .schedule-day-modal {
    position: fixed;
    inset: 0;
    z-index: 1100;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(6, 11, 19, 0.68);
  }
  .schedule-day-modal.is-open {
    display: flex;
  }
  .schedule-day-panel {
    width: min(460px, 100%);
    max-height: min(78vh, 720px);
    overflow: hidden;
    border: 1px solid var(--border2);
    border-radius: 18px;
    background: #132238;
    box-shadow: 0 24px 50px rgba(0,0,0,0.42);
    display: grid;
    grid-template-rows: auto 1fr;
  }
  .schedule-day-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 18px 14px;
    border-bottom: 1px solid var(--border);
  }
  .schedule-day-head h2 {
    color: var(--text-hi);
    font-size: 18px;
    font-weight: 900;
    margin-bottom: 4px;
  }
  .schedule-day-head p {
    color: var(--text-dim);
    font-size: 12px;
    line-height: 1.5;
  }
  .schedule-day-close {
    flex: 0 0 auto;
  }
  .schedule-day-body {
    position: relative;
    overflow-y: auto;
    padding: 16px 18px 18px;
    display: grid;
    gap: 12px;
  }
  .schedule-day-section {
    border: 1px solid var(--border);
    border-radius: 14px;
    background: rgba(255,255,255,0.03);
    padding: 14px;
    display: grid;
    gap: 10px;
  }
  .schedule-day-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }
  .schedule-day-section-title {
    color: var(--text-hi);
    font-size: 14px;
    font-weight: 800;
  }
  .schedule-day-names {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .schedule-day-name {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 36px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: var(--text-hi);
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
  }
  .schedule-day-name.is-empty {
    cursor: default;
    color: var(--text-dim);
  }
  .schedule-day-name.has-phone {
    border-color: rgba(245,166,35,0.24);
  }
  .schedule-day-phone-tip {
    position: absolute;
    display: none;
    min-width: 172px;
    max-width: min(220px, calc(100vw - 48px));
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid rgba(245,166,35,0.28);
    background: rgba(12, 20, 32, 0.96);
    box-shadow: 0 18px 34px rgba(0,0,0,0.35);
  }
  .schedule-day-phone-tip.is-open {
    display: block;
  }
  .schedule-day-phone-tip .tip-name {
    color: rgba(255,255,255,.78);
    font-size: 11px;
    margin-bottom: 4px;
  }
  .schedule-day-phone-tip .tip-phone {
    color: #ffd56d;
    font-size: 15px;
    font-weight: 800;
    text-decoration: none;
  }

  /* ?? ?묒뾽???좏깮 ?앹뾽 ?? */
  .calendar-worker-popup { position: absolute; z-index: 1000; min-width: 260px; max-height: 320px; overflow-y: hidden; border: 1px solid var(--border2); border-radius: 12px; background: #12203a; box-shadow: 0 20px 40px rgba(0,0,0,0.45); display: none; }
  .calendar-worker-popup.open { display: block; }
  .calendar-worker-list { max-height: 240px; overflow-y: auto; }
  .calendar-worker-item { padding: 10px 14px; cursor: pointer; color: var(--text); font-size: 13px; }
  .calendar-worker-item:hover { background: rgba(255,255,255,0.06); }
  .calendar-worker-item.active { background: rgba(232,146,10,0.18); color: var(--accent2); font-weight: 700; }
  .calendar-worker-item + .calendar-worker-item { border-top: 1px solid var(--border); }
  .calendar-worker-footer { display: flex; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 12px; border-top: 1px solid var(--border); background: var(--bg3); }
  .calendar-worker-hint { color: var(--text-dim); font-size: 12px; }

  /* ?? ?낅줈???⑤꼸 ?? */
  .schedule-note { font-size: 13px; color: var(--text-dim); margin-top: 4px; }
  .schedule-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
  .upload-panel { border: 1px solid var(--border); padding: 18px; border-radius: 12px; margin-bottom: 28px; background: var(--bg3); }
  .upload-panel legend { font-weight: 700; margin-bottom: 12px; color: var(--text-hi); font-size: 13px; }
  .upload-panel input[type="file"] { color: var(--text); font: inherit; }
    .upload-panel label { display: grid; gap: 8px; }
  .mobile-bottom-nav {
    display: none;
  }
  .mobile-bottom-nav {
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: max(10px, env(safe-area-inset-bottom));
    z-index: 1000;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 20px;
    background: rgba(10, 17, 28, 0.96);
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 40px rgba(0,0,0,0.38);
    padding: 8px;
  }
  .mobile-bottom-nav-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
  }
  .mobile-nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    border-radius: 14px;
    border: 1px solid transparent;
    background: transparent;
    color: #c5d8eb;
    text-decoration: none;
    font: inherit;
    font-size: 11px;
    font-weight: 700;
  }
  .mobile-nav-link.is-active {
    background: linear-gradient(180deg, rgba(245,166,35,0.2), rgba(245,166,35,0.1));
    border-color: rgba(245,166,35,0.35);
    color: #fff4df;
  }
  .mobile-nav-icon {
    font-size: 18px;
    line-height: 1;
  }
  .scroll-top-fab {
    display: none;
  }
  .scroll-top-fab {
    position: fixed;
    right: 16px;
    bottom: calc(max(10px, env(safe-area-inset-bottom)) + 92px);
    z-index: 1001;
    width: 48px;
    height: 48px;
    border: 1px solid rgba(245,166,35,0.34);
    border-radius: 999px;
    background: rgba(245,166,35,0.92);
    color: #111827;
    box-shadow: 0 16px 30px rgba(0,0,0,0.32);
    font: inherit;
    font-size: 18px;
    font-weight: 900;
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transform: translateY(8px);
    transition: opacity .18s ease, transform .18s ease;
  }
  .scroll-top-fab.is-visible {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
  }

  @media (max-width: 1200px) {
    .calendar-header,
    .calendar-week { grid-template-columns: repeat(7, minmax(110px, 1fr)); }
  }
  @media (max-width: 900px) {
    .calendar-header,
    .calendar-week { grid-template-columns: repeat(7, minmax(90px, 1fr)); }
  }
    @media (max-width: 768px) {
        body { padding: 16px 12px 110px; }
        .topbar { align-items: stretch; }
        .identity { width: 100%; }
        .identity:last-child { gap: 8px; }
        .identity:last-child .btn-secondary { flex: 1 1 calc(50% - 4px); min-width: 0; }
        .panel-head { padding: 18px 16px 12px; }
        .panel-head h1 { font-size: 20px; }
        .panel-head p { line-height: 1.5; }
        .error,
        .success { margin: 12px 16px; }
        .schedule-nav { gap: 8px; }
        .schedule-nav-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .schedule-nav-row .btn-secondary { width: 100%; min-width: 0; padding-left: 0; padding-right: 0; }
        .schedule-nav strong { font-size: 18px; text-align: center; }
        .schedule-date-search { display: flex; }
        .schedule-date-search-input {
            appearance: none;
            -webkit-appearance: none;
            background: rgba(255,255,255,0.04) !important;
            background-color: rgba(255,255,255,0.04) !important;
            color: var(--text-hi) !important;
            -webkit-text-fill-color: var(--text-hi) !important;
            opacity: 1;
        }
        .schedule-date-search-input::-webkit-date-and-time-value,
        .schedule-date-search-input::-webkit-datetime-edit,
        .schedule-date-search-input::-webkit-datetime-edit-fields-wrapper,
        .schedule-date-search-input::-webkit-datetime-edit-text,
        .schedule-date-search-input::-webkit-datetime-edit-year-field,
        .schedule-date-search-input::-webkit-datetime-edit-month-field,
        .schedule-date-search-input::-webkit-datetime-edit-day-field {
            color: var(--text-hi) !important;
            -webkit-text-fill-color: var(--text-hi) !important;
            opacity: 1;
        }
        .schedule-date-search .btn-secondary { flex: 0 0 auto; min-width: 84px; }
        .schedule-grid { gap: 12px; }
        .calendar-header { display: none; }
        .calendar-week { grid-template-columns: 1fr; gap: 10px; }
        .calendar-day { padding: 12px; }
        .calendar-day-empty { display: none; }
        .calendar-day-title { margin-bottom: 6px; }
        .calendar-day-weekday { font-size: 12px; }
        .calendar-entry { padding: 8px 10px; }
        .calendar-entry-label { font-size: 11px; }
        .calendar-entry-text { font-size: 13px; }
        .upload-panel { padding: 14px; margin-bottom: 20px; }
        .schedule-actions { flex-direction: column; }
        .schedule-actions .btn-secondary,
        form[onsubmit] .btn-secondary { width: 100%; }
        .calendar-worker-popup {
            position: fixed;
            left: 12px !important;
            right: 12px;
            top: auto;
            bottom: 12px;
            min-width: 0 !important;
            max-height: min(60vh, 420px);
        }
        .calendar-worker-list { max-height: calc(min(60vh, 420px) - 64px); }
        .mobile-bottom-nav { display: block; }
        .scroll-top-fab { display: inline-flex; align-items: center; justify-content: center; }
        .schedule-day-modal {
            align-items: flex-end;
            padding: 12px;
        }
        .schedule-day-panel {
            width: 100%;
            max-height: min(72vh, 640px);
            border-radius: 18px 18px 14px 14px;
        }
    }
</style>
</head>
<body<?= $isReadOnly ? ' class="readonly"' : '' ?>>
  <div class="shell">
    <div class="topbar">
      <div class="identity">
        <span style="color:var(--text-hi);font-size:14px;font-weight:700"><?= h(auth_display_name($user)) ?></span>
      </div>
      <div class="identity">
        <a class="btn-secondary" href="<?= h(auth_main_page_path($user)) ?>">작업목록</a>
        <a class="btn-secondary" href="schedule.php">근무일정표</a>
        <a class="btn-secondary" href="<?= h($boardPageUrl ?? '../board/index.php') ?>">게시판</a>
        <a class="btn-secondary" href="<?= h('task_select.php?logout=1') ?>">로그아웃</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="panel-head-label">SCHEDULE</div>
        <h1>근무 일정표</h1>
        <p>현재 팀: <?= h($teamName !== '' ? $teamName : '미지정 팀') ?>. 1개월 단위로 주간/야간/휴무 작업자를 달력 형식으로 등록하고 파일로 업로드할 수 있습니다.</p>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="error">
          <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($messages)): ?>
        <div class="success">
          <?php foreach ($messages as $message): ?>
            <div><?= h($message) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div style="padding: 20px 24px 24px;">
      <div class="schedule-nav">
        <div class="schedule-nav-row">
          <a class="btn-secondary" href="<?= h($prevMonthUrl) ?>">이전 달</a>
          <a class="btn-secondary" href="<?= h($todayScheduleUrl) ?>">오늘</a>
          <a class="btn-secondary" href="<?= h($nextMonthUrl) ?>">다음 달</a>
        </div>
        <strong><?= h($monthLabel) ?></strong>
        <form class="schedule-date-search" method="get" action="<?= h($scheduleListUrl) ?>">
          <input
            type="date"
            name="date"
            class="schedule-date-search-input"
            value="<?= h($selectedDate) ?>"
            aria-label="조회할 날짜"
          >
          <?php if ($scheduleViewTeamParam !== ''): ?>
            <input type="hidden" name="view_team" value="<?= h($scheduleViewTeamParam) ?>">
          <?php endif; ?>
          <button type="submit" class="btn-secondary">날짜 조회</button>
        </form>
      </div>

      <div class="schedule-grid">
        <div class="calendar-header">
          <?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $weekday): ?>
            <div class="calendar-header-cell"><?= h($weekday) ?></div>
          <?php endforeach; ?>
        </div>

        <?php foreach ($monthWeeks as $week): ?>
          <div class="calendar-week">
            <?php foreach ($week as $date): ?>
              <div
                class="calendar-day <?= $date === null ? 'calendar-day-empty' : ($date === date('Y-m-d') ? 'calendar-day-today' : '') ?><?= $date !== null && $date === $selectedDate ? ' calendar-day-selected' : '' ?>"
                <?= $date === date('Y-m-d') ? 'id="schedule-today"' : '' ?>
                <?= $date !== null && $date === $selectedDate ? 'data-selected-date="1" id="schedule-selected-date"' : '' ?>
                <?= $date !== null ? 'data-schedule-date="' . h($date) . '"' : '' ?>
              >
                <?php if ($date !== null): ?>
                  <div class="calendar-day-title">
                    <span class="calendar-day-number"><?= date('j', strtotime($date)) ?></span>
                    <span class="calendar-day-weekday"><?= date('D', strtotime($date)) ?></span>
                  </div>
                  <div class="calendar-day-entries">
                    <?php foreach (['day', 'night', 'off'] as $shift): ?>
                      <?php
                        $value = $teamSchedule[$date][$shift] ?? '';
                        $display = $value === '' ? '등록 없음' : h($value);
                      ?>
                      <div class="calendar-entry calendar-entry-<?= h($shift) ?>" data-date="<?= h($date) ?>" data-shift="<?= h($shift) ?>">
                        <span class="calendar-entry-label"><?= h(schedule_shift_label($shift)) ?></span>
                        <span class="calendar-entry-text" contenteditable="false" role="textbox" aria-label="<?= h(schedule_shift_label($shift)) ?> 내용"><?= $display ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!$isReadOnly): ?>
      <fieldset class="upload-panel">
        <legend>근무표 업로드</legend>
        <form method="post" enctype="multipart/form-data" action="<?= h($currentMonthActionUrl) ?>">
          <label>
            파일 선택
            <input type="file" name="schedule_file" accept=".xlsx,.xls,.csv" required>
          </label>
          <div class="schedule-actions">
            <button type="submit" name="upload_schedule" class="btn-secondary">업로드</button>
          </div>
        </form>
      </fieldset>

      <form method="post" action="<?= h($currentMonthActionUrl) ?>" style="margin-top:12px;" onsubmit="return confirm('이번 달 일정을 모두 초기화하시겠습니까?\n이 작업은 되돌릴 수 없습니다.');">
        <button type="submit" name="clear_schedule" class="btn-secondary" style="border-color:rgba(214,69,65,0.45);color:#ffd8d6;background:rgba(214,69,65,0.15);">이번 달 초기화</button>
      </form>
      <?php endif; ?>
      </div><!-- /padding wrapper -->

      <?php if (!$isReadOnly): ?>
      <form id="inline-save-form" method="post" action="<?= h($currentMonthActionUrl) ?>" style="display:none;">
        <input type="hidden" name="entry_date" id="inline-entry-date" value="">
        <input type="hidden" name="entry_shift" id="inline-entry-shift" value="">
        <input type="hidden" name="entry_value" id="inline-entry-value" value="">
        <input type="hidden" name="save_schedule_entry" value="1">
      </form>
      <div id="calendar-worker-popup" class="calendar-worker-popup" role="dialog" aria-label="달력 작업자 선택 목록">
        <div class="calendar-worker-list">
          <?php foreach ($teamWorkers as $worker): ?>
            <div class="calendar-worker-item" data-worker-name="<?= h($worker['name']) ?>">
              <?= h($worker['name']) ?><?= $worker['role'] !== 'worker' ? ' (' . h(auth_role_label($worker['role'])) . ')' : '' ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="calendar-worker-footer">
          <button type="button" id="calendar-worker-save" class="btn-secondary">확인</button>
          <button type="button" id="calendar-worker-clear" class="btn-secondary">초기화</button>
          <span class="calendar-worker-hint">최대 2명 선택</span>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="schedule-day-modal" id="schedule-day-modal" aria-hidden="true">
    <div class="schedule-day-panel" role="dialog" aria-modal="true" aria-labelledby="schedule-day-modal-title">
      <div class="schedule-day-head">
        <div>
          <h2 id="schedule-day-modal-title">일정 상세</h2>
          <p id="schedule-day-modal-subtitle">날짜별 근무 내용을 확인합니다.</p>
        </div>
        <button type="button" class="btn-secondary schedule-day-close" id="schedule-day-modal-close">닫기</button>
      </div>
      <div class="schedule-day-body" id="schedule-day-modal-body">
        <div class="schedule-day-phone-tip" id="schedule-day-phone-tip" aria-hidden="true">
          <div class="tip-name" id="schedule-day-tip-name"></div>
          <a class="tip-phone" id="schedule-day-tip-phone" href="#"></a>
        </div>
      </div>
    </div>
  </div>

  <nav class="mobile-bottom-nav" aria-label="모바일 하단 메뉴">
    <div class="mobile-bottom-nav-grid">
      <a class="mobile-nav-link" href="index.php">
        <span class="mobile-nav-icon">⌂</span>
        <span>홈</span>
      </a>
      <a class="mobile-nav-link" href="../calendar/index.html">
        <span class="mobile-nav-icon">◫</span>
        <span>달력</span>
      </a>
      <a class="mobile-nav-link" href="work_list.php">
        <span class="mobile-nav-icon">≡</span>
        <span>목록</span>
      </a>
      <a class="mobile-nav-link" href="../board/index.php">
        <span class="mobile-nav-icon">▣</span>
        <span>게시판</span>
      </a>
      <a class="mobile-nav-link is-active" href="more.php">
        <span class="mobile-nav-icon">⋯</span>
        <span>더보기</span>
      </a>
    </div>
  </nav>
  <button type="button" class="scroll-top-fab" id="scroll-top-fab" aria-label="맨 위로 이동">↑</button>

  <script>
    var isReadOnly = <?= $isReadOnly ? 'true' : 'false' ?>;
    var teamWorkerPhoneMap = <?= json_encode($teamWorkerPhoneMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function saveInlineEntry(date, shift, value) {
      var form = document.getElementById('inline-save-form');
      if (!form) {
        return;
      }
      document.getElementById('inline-entry-date').value = date;
      document.getElementById('inline-entry-shift').value = shift;
      document.getElementById('inline-entry-value').value = value.trim();
      form.submit();
    }

    var calendarWorkerPopup = document.getElementById('calendar-worker-popup');
    var activeWorkerEntry = null;
    var activeWorkerTextNode = null;
    var activeWorkerDate = '';
    var activeWorkerShift = '';
    var activeWorkerNames = [];
    var calendarWorkerSave = document.getElementById('calendar-worker-save');
    var calendarWorkerClear = document.getElementById('calendar-worker-clear');
    var scheduleDayModal = document.getElementById('schedule-day-modal');
    var scheduleDayModalBody = document.getElementById('schedule-day-modal-body');
    var scheduleDayModalTitle = document.getElementById('schedule-day-modal-title');
    var scheduleDayModalSubtitle = document.getElementById('schedule-day-modal-subtitle');
    var scheduleDayModalClose = document.getElementById('schedule-day-modal-close');
    var scheduleDayPhoneTip = document.getElementById('schedule-day-phone-tip');
    var scheduleDayTipName = document.getElementById('schedule-day-tip-name');
    var scheduleDayTipPhone = document.getElementById('schedule-day-tip-phone');

    function isMobileScheduleView() {
      return window.matchMedia('(max-width: 768px)').matches;
    }

    function hideScheduleDayPhoneTip() {
      if (!scheduleDayPhoneTip) {
        return;
      }
      scheduleDayPhoneTip.classList.remove('is-open');
      scheduleDayPhoneTip.setAttribute('aria-hidden', 'true');
    }

    function showScheduleDayPhoneTip(name, phone, anchor) {
      if (!scheduleDayPhoneTip || !scheduleDayTipName || !scheduleDayTipPhone || !scheduleDayModalBody || !anchor) {
        return;
      }
      var normalizedPhone = String(phone || '').replace(/[^0-9+]/g, '');
      scheduleDayTipName.textContent = name || '전화번호';
      scheduleDayTipPhone.textContent = phone || '번호 없음';
      scheduleDayTipPhone.setAttribute('href', normalizedPhone ? 'tel:' + normalizedPhone : '#');
      scheduleDayPhoneTip.classList.add('is-open');
      scheduleDayPhoneTip.setAttribute('aria-hidden', 'false');
      scheduleDayPhoneTip.style.left = '12px';
      scheduleDayPhoneTip.style.top = '12px';

      var bodyRect = scheduleDayModalBody.getBoundingClientRect();
      var anchorRect = anchor.getBoundingClientRect();
      var tipWidth = scheduleDayPhoneTip.offsetWidth || 180;
      var tipHeight = scheduleDayPhoneTip.offsetHeight || 66;
      var left = Math.min(
        Math.max(anchorRect.left - bodyRect.left, 0),
        Math.max(bodyRect.width - tipWidth - 4, 0)
      );
      var top = anchorRect.bottom - bodyRect.top + 8;
      if (top + tipHeight > bodyRect.height - 4) {
        top = Math.max(anchorRect.top - bodyRect.top - tipHeight - 8, 0);
      }
      scheduleDayPhoneTip.style.left = left + 'px';
      scheduleDayPhoneTip.style.top = top + 'px';
    }

    function closeScheduleDayModal() {
      if (!scheduleDayModal) {
        return;
      }
      scheduleDayModal.classList.remove('is-open');
      scheduleDayModal.setAttribute('aria-hidden', 'true');
      hideScheduleDayPhoneTip();
    }

    function renderScheduleNames(rawValue) {
      var trimmed = String(rawValue || '').trim();
      if (trimmed === '' || trimmed === '등록 없음') {
        return '<span class="schedule-day-name is-empty">등록 없음</span>';
      }

      return trimmed.split(',').map(function(name) {
        var cleanName = name.trim();
        if (!cleanName) {
          return '';
        }
        var phone = teamWorkerPhoneMap[cleanName] || '';
        return '<button type="button" class="schedule-day-name' + (phone ? ' has-phone' : '') + '" data-worker-name="' + cleanName.replace(/"/g, '&quot;') + '" data-worker-phone="' + String(phone).replace(/"/g, '&quot;') + '">' + cleanName + '</button>';
      }).filter(Boolean).join('');
    }

    function openScheduleDayModal(dayCard) {
      if (!scheduleDayModal || !scheduleDayModalBody || !scheduleDayModalTitle || !scheduleDayModalSubtitle || !dayCard) {
        return;
      }

      var date = dayCard.getAttribute('data-schedule-date') || '';
      var weekdayNode = dayCard.querySelector('.calendar-day-weekday');
      var dayNumberNode = dayCard.querySelector('.calendar-day-number');
      var weekdayText = weekdayNode ? weekdayNode.textContent.trim() : '';
      var dayNumberText = dayNumberNode ? dayNumberNode.textContent.trim() : '';
      scheduleDayModalTitle.textContent = date ? (date + ' 일정') : '일정 상세';
      scheduleDayModalSubtitle.textContent = weekdayText !== '' ? ('선택 날짜: ' + weekdayText + ' ' + dayNumberText + '일') : '날짜별 근무 내용을 확인합니다.';

      var sections = [];
      dayCard.querySelectorAll('.calendar-entry[data-date][data-shift]').forEach(function(entry) {
        var labelNode = entry.querySelector('.calendar-entry-label');
        var textNode = entry.querySelector('.calendar-entry-text');
        var shift = entry.getAttribute('data-shift') || '';
        var label = labelNode ? labelNode.textContent.trim() : '';
        var text = textNode ? textNode.textContent.trim() : '';
        var editButton = !isReadOnly
          ? '<button type="button" class="btn-secondary schedule-day-edit" data-date="' + (entry.getAttribute('data-date') || '') + '" data-shift="' + shift + '">편집</button>'
          : '';
        sections.push(
          '<section class="schedule-day-section">'
          + '<div class="schedule-day-section-head">'
          + '<div class="schedule-day-section-title">' + label + '</div>'
          + editButton
          + '</div>'
          + '<div class="schedule-day-names">' + renderScheduleNames(text) + '</div>'
          + '</section>'
        );
      });

      scheduleDayModalBody.innerHTML = sections.join('') + scheduleDayPhoneTip.outerHTML;
      scheduleDayPhoneTip = document.getElementById('schedule-day-phone-tip');
      scheduleDayTipName = document.getElementById('schedule-day-tip-name');
      scheduleDayTipPhone = document.getElementById('schedule-day-tip-phone');
      scheduleDayModal.classList.add('is-open');
      scheduleDayModal.setAttribute('aria-hidden', 'false');
    }

    function hideCalendarWorkerPopup() {
      if (calendarWorkerPopup) {
        calendarWorkerPopup.classList.remove('open');
      }
      activeWorkerEntry = null;
      activeWorkerTextNode = null;
      activeWorkerDate = '';
      activeWorkerShift = '';
      activeWorkerNames = [];
      updateCalendarWorkerSelection();
    }

    function parseWorkerNames(value) {
      if (typeof value !== 'string' || value.trim() === '' || value.trim() === '등록 없음') {
        return [];
      }
      return value.split(',').map(function(name) {
        return name.trim();
      }).filter(function(name) {
        return name !== '' && name !== '등록 없음';
      }).slice(0, 2);
    }

    function updateCalendarWorkerSelection() {
      if (!calendarWorkerPopup) {
        return;
      }
      calendarWorkerPopup.querySelectorAll('.calendar-worker-item').forEach(function(item) {
        var workerName = item.getAttribute('data-worker-name') || '';
        if (activeWorkerNames.indexOf(workerName) !== -1) {
          item.classList.add('active');
        } else {
          item.classList.remove('active');
        }
      });
    }

    function showCalendarWorkerPopup(entry, textNode, date, shift) {
      if (!calendarWorkerPopup || !entry) {
        return;
      }
      activeWorkerEntry = entry;
      activeWorkerTextNode = textNode;
      activeWorkerDate = date;
      activeWorkerShift = shift;
      activeWorkerNames = parseWorkerNames(textNode.textContent);
      updateCalendarWorkerSelection();
      var rect = entry.getBoundingClientRect();
            if (window.matchMedia('(max-width: 768px)').matches) {
                calendarWorkerPopup.style.minWidth = '';
                calendarWorkerPopup.style.left = '12px';
                calendarWorkerPopup.style.top = '';
            } else {
                var popupWidth = Math.max(rect.width, 260);
                var maxLeft = window.scrollX + window.innerWidth - popupWidth - 16;
                var left = Math.min(window.scrollX + rect.left, Math.max(window.scrollX + 16, maxLeft));
                calendarWorkerPopup.style.minWidth = rect.width + 'px';
                calendarWorkerPopup.style.left = left + 'px';
                calendarWorkerPopup.style.top = window.scrollY + rect.bottom + 'px';
            }
      calendarWorkerPopup.classList.add('open');
    }

    function applyCalendarWorkerSelection() {
      if (!activeWorkerTextNode || !activeWorkerDate || !activeWorkerShift) {
        return;
      }
      var workerName = activeWorkerNames.join(', ');
      var display = workerName === '' ? '등록 없음' : workerName;
      activeWorkerTextNode.textContent = display;
      saveInlineEntry(activeWorkerDate, activeWorkerShift, workerName);
      hideCalendarWorkerPopup();
    }

    function toggleCalendarWorkerSelection(workerName) {
      var index = activeWorkerNames.indexOf(workerName);
      if (index !== -1) {
        activeWorkerNames.splice(index, 1);
      } else if (activeWorkerNames.length < 2) {
        activeWorkerNames.push(workerName);
      }
      updateCalendarWorkerSelection();
    }

    if (calendarWorkerPopup) {
      calendarWorkerPopup.addEventListener('click', function(event) {
        var item = event.target.closest('.calendar-worker-item');
        if (!item) {
          return;
        }
        toggleCalendarWorkerSelection(item.getAttribute('data-worker-name') || '');
      });
    }

    if (calendarWorkerSave) {
      calendarWorkerSave.addEventListener('click', function() {
        applyCalendarWorkerSelection();
      });
    }

    if (calendarWorkerClear) {
      calendarWorkerClear.addEventListener('click', function() {
        activeWorkerNames = [];
        updateCalendarWorkerSelection();
      });
    }

    document.addEventListener('click', function(event) {
      if (calendarWorkerPopup && !calendarWorkerPopup.contains(event.target) && !event.target.closest('.calendar-entry')) {
        hideCalendarWorkerPopup();
      }
    });

    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        hideCalendarWorkerPopup();
        closeScheduleDayModal();
      }
    });

    if (!isReadOnly) {
      document.querySelectorAll('.calendar-entry[data-date][data-shift]').forEach(function(entry) {
        var textNode = entry.querySelector('.calendar-entry-text');
        if (!textNode) {
          return;
        }

        entry.addEventListener('click', function(event) {
          event.stopPropagation();
          var date = this.getAttribute('data-date');
          var shift = this.getAttribute('data-shift');
          showCalendarWorkerPopup(this, textNode, date, shift);
        });
      });
    }

    document.querySelectorAll('.calendar-day[data-schedule-date]').forEach(function(dayCard) {
      dayCard.addEventListener('click', function(event) {
        if (!isMobileScheduleView()) {
          return;
        }
        if (event.target.closest('.calendar-entry')) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        openScheduleDayModal(dayCard);
      });
    });

    if (scheduleDayModal && scheduleDayModalClose) {
      scheduleDayModalClose.addEventListener('click', closeScheduleDayModal);
      scheduleDayModal.addEventListener('click', function(event) {
        if (event.target === scheduleDayModal) {
          closeScheduleDayModal();
          return;
        }

        var nameButton = event.target.closest('.schedule-day-name[data-worker-name]');
        if (nameButton) {
          var workerPhone = nameButton.getAttribute('data-worker-phone') || '';
          if (workerPhone !== '') {
            showScheduleDayPhoneTip(
              nameButton.getAttribute('data-worker-name') || '',
              workerPhone,
              nameButton
            );
          }
          return;
        }

        var editButton = event.target.closest('.schedule-day-edit[data-date][data-shift]');
        if (editButton) {
          closeScheduleDayModal();
          var targetEntry = document.querySelector(
            '.calendar-entry[data-date="' + editButton.getAttribute('data-date') + '"][data-shift="' + editButton.getAttribute('data-shift') + '"]'
          );
          if (targetEntry && !isReadOnly) {
            var targetTextNode = targetEntry.querySelector('.calendar-entry-text');
            showCalendarWorkerPopup(
              targetEntry,
              targetTextNode,
              editButton.getAttribute('data-date') || '',
              editButton.getAttribute('data-shift') || ''
            );
          }
          return;
        }

        if (!event.target.closest('.schedule-day-phone-tip')) {
          hideScheduleDayPhoneTip();
        }
      });
    }

    if (window.location.hash === '#schedule-today') {
      var todayCard = document.getElementById('schedule-today');
      if (todayCard) {
        window.requestAnimationFrame(function() {
          todayCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
      }
    }

    var selectedDateCard = document.getElementById('schedule-selected-date');
    if (selectedDateCard && <?= $hasExplicitSelectedDate ? 'true' : 'false' ?>) {
      window.requestAnimationFrame(function() {
        selectedDateCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    }

    var scrollTopFab = document.getElementById('scroll-top-fab');
    if (scrollTopFab) {
      var syncScrollTopFab = function() {
        if (!window.matchMedia('(max-width: 768px)').matches) {
          scrollTopFab.classList.remove('is-visible');
          return;
        }

        scrollTopFab.classList.toggle('is-visible', window.scrollY > 240);
      };

      scrollTopFab.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });

      window.addEventListener('scroll', syncScrollTopFab, { passive: true });
      window.addEventListener('resize', syncScrollTopFab);
      syncScrollTopFab();
    }
  </script>
</body>
</html>


