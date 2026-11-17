<?php
declare(strict_types=1);
require_once __DIR__ . '/leadership_store.php';

function activity_monitor_records(PDO $db, int $year): array
{
    $query = $db->prepare("SELECT * FROM leadership_records WHERE item='1.2.2' AND year=? ORDER BY id");
    $query->execute([$year]);
    $records = [];
    foreach ($query->fetchAll() as $row) {
        try { $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { continue; }
        $kind = $payload['kind'] ?? '';
        $number = (int)($payload['number'] ?? 0);
        if (!in_array($kind, ['month', 'quarter'], true) || $number < 1 || $number > ($kind === 'month' ? 12 : 4)) continue;
        $row['data'] = $payload;
        $records[$kind][$number] = $row;
    }
    return $records;
}

function activity_monitor_files(PDO $db, int $year): array
{
    $query = $db->prepare("SELECT f.* FROM leadership_files f JOIN leadership_records r ON r.id=f.record_id WHERE r.item='1.2.2' AND r.year=? ORDER BY f.id");
    $query->execute([$year]);
    $files = [];
    foreach ($query->fetchAll() as $file) $files[(int)$file['record_id']][] = $file;
    return $files;
}

function activity_monitor_verified_files(array $files): array
{
    return array_values(array_filter($files, static fn(array $file): bool =>
        $file['deleted_at'] === null
        && preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png)$/D', (string)$file['stored_name']) === 1
        && is_file(__DIR__ . '/storage/leadership_evidence/' . $file['stored_name'])));
}

function activity_monitor_source(int $year): array
{
    require_once dirname(__DIR__) . '/risk_assessment/db_config.php';
    require_once dirname(__DIR__) . '/safety_manual/quarter_plan_storage.php';
    $db = getDB();
    $template = array_values(array_filter(quarter_plan_template()['rows'], static fn($row) => ($row['type'] ?? '') === 'item'));
    $result = [];
    for ($month = 1; $month <= 12; $month++) {
        $quarter = intdiv($month - 1, 3) + 1;
        if (!isset($plans[$quarter])) $plans[$quarter] = quarter_plan_load($db, $year, $quarter);
        $key = 'm' . (($month - 1) % 3 + 1);
        $rows = activity_monitor_month_rows($template, $plans[$quarter]['rows'] ?? [], $key);
        $result[$month] = ['rows' => $rows, 'revision' => $plans[$quarter]['revision'] ?? null,
            'total' => count($template)];
    }
    return $result;
}

function activity_monitor_month_rows(array $template, array $planRows, string $monthKey): array
{
    $rows = [];
    $planKey = 'p' . substr($monthKey, 1);
    foreach ($template as $item) {
        $plan = trim((string)($planRows[$item['id']][$planKey] ?? ''));
        $value = trim((string)($planRows[$item['id']][$monthKey] ?? ''));
        if ($plan === '') continue;
        $rows[] = ['id' => $item['id'], 'name' => $item['name'], 'plan' => $plan,
            'value' => $value === '-' ? '' : $value, 'unit' => $item['unit'] ?? ''];
    }
    return $rows;
}

function activity_monitor_display_quantity(array $item, string $field): string
{
    $value = (string)($item[$field] ?? '');
    if ($value === '' || $value === '-' || !is_numeric($value)) return $value;
    if (($item['id'] ?? '') !== 'qr8' && ($item['name'] ?? '') !== '안전활동평가(CSM) 개선율') return $value;
    if ($field === 'plan' && (float)$value > 1) return $value;
    $percent = (float)$value * 100;
    return rtrim(rtrim(number_format($percent, 6, '.', ''), '0'), '.');
}

function activity_monitor_quarter_source(int $year, int $quarter): array
{
    require_once dirname(__DIR__) . '/risk_assessment/db_config.php';
    require_once dirname(__DIR__) . '/safety_manual/quarter_plan_storage.php';
    $plan = quarter_plan_load(getDB(), $year, $quarter);
    $items = array_values(array_filter(quarter_plan_template()['rows'], static fn($row) => ($row['type'] ?? '') === 'item'));
    $rows = [];
    foreach ($items as $item) {
        $values = $plan['rows'][$item['id']] ?? [];
        if (!array_filter([$values['plan'] ?? '', $values['result'] ?? '', $values['m1'] ?? '', $values['m2'] ?? '', $values['m3'] ?? ''], static fn($value) => (string)$value !== '')) continue;
        $rows[] = ['id' => $item['id'], 'name' => $item['name'], 'plan' => $values['plan'] ?? '',
            'result' => $values['result'] ?? '', 'unit' => $item['unit'] ?? '',
            'months' => [$values['m1'] ?? '', $values['m2'] ?? '', $values['m3'] ?? '']];
    }
    return ['rows' => $rows, 'revision' => $plan['revision'] ?? null, 'total' => count($items)];
}
