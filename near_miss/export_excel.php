<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
ensureNearMissSchema();
syncAllNearMissPhotoLinks();

$autoloadCandidates = [
    __DIR__ . '/../risk_assessment/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
$autoloadPath = '';
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "엑셀 내보내기 라이브러리를 찾을 수 없습니다. (vendor/autoload.php)";
    exit;
}

require_once $autoloadPath;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function nearMissCleanIncidentName(string $title): string {
    $title = trim($title);
    $title = preg_replace('/^\[[^\]]+\]\s*/u', '', $title) ?? $title;
    return trim($title);
}

$rows = db()->query(
    "SELECT
        p.id AS post_id,
        p.title,
        p.author_id,
        p.author_name,
        p.author_dept,
        p.created_at AS post_created_at,
        p.updated_at AS post_updated_at,
        n.id AS report_id,
        n.source_excel_id,
        n.source_written_at,
        n.incident_at,
        n.location,
        n.work_type,
        n.risk_type,
        n.unsafe_state,
        n.unsafe_action,
        n.careless_action,
        n.careless_state,
        n.description,
        n.cause,
        n.action_taken,
        n.prevention_plan,
        n.reporter_contact,
        n.status,
        n.created_at AS report_created_at,
        n.updated_at AS report_updated_at
     FROM near_miss_reports n
     JOIN posts p ON p.id = n.post_id
     ORDER BY n.incident_at DESC, p.id DESC"
)->fetchAll();

$postIds = array_map('intval', array_column($rows, 'post_id'));
$photoMap = getNearMissPhotoSummaryMap($postIds);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('near_miss');

$headers = [
    'post_id',
    'report_id',
    'source_excel_id',
    'source_written_at',
    'incident_at',
    'incident_name',
    'location',
    'work_type',
    'risk_type',
    'unsafe_state',
    'unsafe_action',
    'careless_action',
    'careless_state',
    'description',
    'cause',
    'action_taken',
    'prevention_plan',
    'reporter_contact',
    'status',
    'author_id',
    'author_name',
    'author_dept',
    'post_created_at',
    'post_updated_at',
    'report_created_at',
    'report_updated_at',
    'photo_count',
    'photo_keys',
    'photo_roles',
    'photo_urls',
];
$sheet->fromArray($headers, null, 'A1');
$lastColumn = Coordinate::stringFromColumnIndex(count($headers));

$rowNum = 2;
foreach ($rows as $row) {
    $postId = (int)($row['post_id'] ?? 0);
    $photo = $photoMap[$postId] ?? [
        'photo_count' => 0,
        'photo_keys' => [],
        'photo_roles' => [],
        'photo_urls' => [],
    ];

    $sheet->fromArray([
        $postId,
        (int)($row['report_id'] ?? 0),
        $row['source_excel_id'],
        $row['source_written_at'],
        $row['incident_at'],
        nearMissCleanIncidentName((string)($row['title'] ?? '')),
        $row['location'],
        $row['work_type'],
        $row['risk_type'],
        $row['unsafe_state'],
        $row['unsafe_action'],
        $row['careless_action'],
        $row['careless_state'],
        $row['description'],
        $row['cause'],
        $row['action_taken'],
        $row['prevention_plan'],
        $row['reporter_contact'],
        $row['status'],
        $row['author_id'],
        $row['author_name'],
        $row['author_dept'],
        $row['post_created_at'],
        $row['post_updated_at'],
        $row['report_created_at'],
        $row['report_updated_at'],
        (int)$photo['photo_count'],
        implode("\n", array_filter($photo['photo_keys'], static fn($v) => $v !== '')),
        implode("\n", array_filter($photo['photo_roles'], static fn($v) => $v !== '')),
        implode("\n", array_filter($photo['photo_urls'], static fn($v) => $v !== '')),
    ], null, 'A' . $rowNum);
    $rowNum++;
}

$maxRow = max(1, $rowNum - 1);
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:' . $lastColumn . '1');
$sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
if ($maxRow >= 2) {
    $sheet->getStyle('J2:' . $lastColumn . $maxRow)->getAlignment()->setWrapText(true);
}

for ($i = 1, $n = count($headers); $i <= $n; $i++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}

$fileName = 'near_miss_export_' . date('Ymd_His') . '.xlsx';
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fileName));
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
