<?php
declare(strict_types=1);

require_once __DIR__ . '/../CSM/scoring.php';

function safety_vfl_months(int $year, ?PDO $source = null): array
{
    $months = array_fill(1, 12, 0);
    $source ??= csm_scoring_db();
    if ($source === null || !$source->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leadership_records'")->fetch()) return $months;
    // Count activities, not photos or signed attachments; edits retain the same record ID.
    $query = $source->prepare("SELECT substr(activity_date,6,2) AS month_no, COUNT(*) AS total
        FROM leadership_records WHERE item='1.1.3' AND year=?
        AND activity_date >= ? AND activity_date <= ? GROUP BY substr(activity_date,6,2)");
    $query->execute([$year, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $month = (int)$row['month_no'];
        if ($month >= 1 && $month <= 12) $months[$month] = (int)$row['total'];
    }
    return $months;
}

function safety_vfl_quarter(int $year, int $quarter): array
{
    $months = safety_vfl_months($year);
    $previous = safety_vfl_months($year - 1);
    $start = ($quarter - 1) * 3 + 1;
    return ['previous' => (string)array_sum(array_slice($previous, $start - 1, 3)),
        'm1' => (string)$months[$start], 'm2' => (string)$months[$start + 1], 'm3' => (string)$months[$start + 2],
        'result' => (string)array_sum(array_slice($months, $start - 1, 3))];
}

function safety_vfl_annual(array $row, array $months): array
{
    for ($quarter = 1; $quarter <= 4; $quarter++) $row['q'.$quarter] = array_sum(array_slice($months, ($quarter - 1) * 3, 3)).'회';
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:회)?$/u', $row['plan'], $match) && (float)$match[1] > 0) {
        $row['rate'] = rtrim(rtrim(number_format(array_sum($months) / (float)$match[1] * 100, 2, '.', ''), '0'), '.').'%';
    } else $row['rate'] = '';
    return $row;
}
