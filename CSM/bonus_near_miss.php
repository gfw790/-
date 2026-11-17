<?php
declare(strict_types=1);

function bonus_near_miss_summary(int $year, ?DateTimeImmutable $asOf = null): array
{
    if (!defined('DB_HOST')) require_once dirname(__DIR__) . '/near_miss/includes/config.php';
    // The shared process may already have loaded the risk-assessment DB constants.
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=board;charset=' . DB_CHARSET,
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $from = sprintf('%04d-01-01 00:00:00', $year);
    $yearEnd = sprintf('%04d-01-01 00:00:00', $year + 1);
    $to = $year === (int)$asOf->format('Y') ? $asOf->modify('+1 day')->format('Y-m-d 00:00:00') : $yearEnd;
    if ($year > (int)$asOf->format('Y')) $to = $from;
    // Match near_miss/includes/statistics.php: its period filter uses incident_at.
    $date = 'n.incident_at';
    $query = $db->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(n.source_excel_id IS NOT NULL),0) AS imported
        FROM near_miss_reports n JOIN posts p ON p.id=n.post_id
        WHERE $date >= ? AND $date < ?");
    $query->execute([$from, $to]);
    $row = $query->fetch();
    $query = $db->prepare("SELECT MONTH($date) AS month,COUNT(*) AS total
        FROM near_miss_reports n JOIN posts p ON p.id=n.post_id
        WHERE $date >= ? AND $date < ? GROUP BY MONTH($date) ORDER BY month");
    $query->execute([$from, $to]);
    $monthly = array_fill(1, 12, 0);
    foreach ($query->fetchAll() as $month) $monthly[(int)$month['month']] = (int)$month['total'];
    $total = (int)$row['total'];
    $imported = (int)$row['imported'];
    // Keep the written-year count visible so users can reconcile older incidents entered this year.
    $written = 'COALESCE(n.source_written_at,n.created_at)';
    $query = $db->prepare("SELECT COUNT(*) FROM near_miss_reports n JOIN posts p ON p.id=n.post_id
        WHERE $written >= ? AND $written < ?");
    $query->execute([$from, $yearEnd]);
    $writtenTotal = (int)$query->fetchColumn();
    return ['total' => $total, 'written_total' => $writtenTotal,
        'imported' => $imported, 'local' => $total - $imported, 'months' => $monthly];
}

function bonus_current_headcount(?DateTimeImmutable $asOf = null): array
{
    require_once __DIR__ . '/headcount_functions.php';
    $asOf ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $date = $asOf->format('Y-m-d');
    $counts = ['confirmed' => 0, 'unknown' => 0];
    foreach (csm_headcount_rows() as $employee) {
        $state = csm_headcount_classify($employee, $date)['state'];
        if (isset($counts[$state])) $counts[$state]++;
    }
    return ['date' => $date] + $counts;
}

function bonus_near_miss_points(int $reports, array $headcount): array
{
    $people = (int)($headcount['confirmed'] ?? 0);
    $unknown = (int)($headcount['unknown'] ?? 0);
    if ($people <= 0 || $unknown > 0) return ['earned' => 0.0, 'rate' => null, 'state' => 'pending'];
    $rate = $reports / $people;
    $earned = $rate >= 3.0 ? 2.0 : ($rate >= 2.0 ? 1.5 : ($rate >= 1.0 ? 1.0 : 0.0));
    return ['earned' => $earned, 'rate' => $rate, 'state' => $reports > 0 ? 'assessed' : 'no_evidence'];
}
