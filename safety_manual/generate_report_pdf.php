<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$user = auth_current_user();
if (!is_array($user)) { header('Location: /risk_assessment/task_select.php'); exit; }
if (!auth_can_manage($user) || trim((string)($user['login_id'] ?? '')) !== '5878') { http_response_code(403); exit('권한이 없습니다.'); }

function value(array $data, string $key): string { return (string)($data[$key] ?? ''); }
function clean_data(array $source): array { $data = []; foreach ($source as $key => $item) { if (is_string($key) && !is_array($item)) { $data[$key] = mb_substr(trim($item), 0, 1000); } } return $data; }
function checkbox(bool $selected): string { return $selected ? '■' : '□'; }

/** 표에 표기할 내용이 하나도 없으면(모든 행의 모든 칸이 빈 값) true를 반환한다. */
function rows_are_empty(array $rows, array $keys): bool
{
    foreach ($rows as $row) {
        foreach ($keys as $key) {
            if (trim((string)($row[$key] ?? '')) !== '') { return false; }
        }
    }
    return true;
}

function build_review_rows(array $post): array
{
    $types = $post['review_type'] ?? [];
    $works = $post['review_work'] ?? [];
    $hazards = $post['review_hazard'] ?? [];
    $reviews = $post['review_review'] ?? [];
    $actions = $post['review_action'] ?? [];
    $workers = $post['review_worker'] ?? [];
    $count = max(count($types), count($works), count($hazards), count($reviews), count($actions), count($workers));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'type' => mb_substr(trim((string)($types[$i] ?? '')), 0, 200),
            'work' => mb_substr(trim((string)($works[$i] ?? '')), 0, 2000),
            'hazard' => mb_substr(trim((string)($hazards[$i] ?? '')), 0, 2000),
            'review' => mb_substr(trim((string)($reviews[$i] ?? '')), 0, 2000),
            'action' => mb_substr(trim((string)($actions[$i] ?? '')), 0, 2000),
            'worker' => mb_substr(trim((string)($workers[$i] ?? '')), 0, 2000),
        ];
    }
    return $rows;
}

function normalize_review_rows(array $data): array
{
    if (isset($data['review_rows']) && is_array($data['review_rows']) && count($data['review_rows']) > 0) {
        return array_values(array_map(static function ($row) {
            $row = is_array($row) ? $row : [];
            return [
                'type' => (string)($row['type'] ?? ''), 'work' => (string)($row['work'] ?? ''),
                'hazard' => (string)($row['hazard'] ?? ''), 'review' => (string)($row['review'] ?? ''),
                'action' => (string)($row['action'] ?? ''), 'worker' => (string)($row['worker'] ?? ''),
            ];
        }, $data['review_rows']));
    }
    $legacy = [];
    foreach (['regular' => '정기', 'temporary' => '수시', 'additional' => ''] as $key => $label) {
        $legacy[] = [
            'type' => $label, 'work' => value($data, $key . '_work'), 'hazard' => value($data, $key . '_hazard'),
            'review' => value($data, $key . '_review'), 'action' => value($data, $key . '_action'), 'worker' => value($data, $key . '_worker'),
        ];
    }
    return $legacy;
}

function build_reflection_rows(array $post): array
{
    $targets = $post['reflect_target'] ?? []; $dates = $post['reflect_date'] ?? []; $persons = $post['reflect_person'] ?? [];
    $eduTargets = $post['reflect_edu_target'] ?? []; $eduDates = $post['reflect_edu_date'] ?? []; $eduMethods = $post['reflect_edu_method'] ?? [];
    $count = max(count($targets), count($dates), count($persons), count($eduTargets), count($eduDates), count($eduMethods));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'target' => mb_substr(trim((string)($targets[$i] ?? '')), 0, 1000), 'reflect_date' => mb_substr(trim((string)($dates[$i] ?? '')), 0, 200), 'person' => mb_substr(trim((string)($persons[$i] ?? '')), 0, 200),
            'edu_target' => mb_substr(trim((string)($eduTargets[$i] ?? '')), 0, 1000), 'edu_date' => mb_substr(trim((string)($eduDates[$i] ?? '')), 0, 200), 'edu_method' => mb_substr(trim((string)($eduMethods[$i] ?? '')), 0, 1000),
        ];
    }
    return $rows;
}

function normalize_reflection_rows(array $data): array
{
    if (isset($data['reflection_rows']) && is_array($data['reflection_rows']) && count($data['reflection_rows']) > 0) {
        return array_values(array_map(static function ($row) {
            $row = is_array($row) ? $row : [];
            if (isset($row['date_person']) && !isset($row['reflect_date'])) {
                $row['reflect_date'] = ''; $row['person'] = (string)$row['date_person'];
            }
            return ['target' => (string)($row['target'] ?? ''), 'reflect_date' => (string)($row['reflect_date'] ?? ''), 'person' => (string)($row['person'] ?? ''), 'edu_target' => (string)($row['edu_target'] ?? ''), 'edu_date' => (string)($row['edu_date'] ?? ''), 'edu_method' => (string)($row['edu_method'] ?? '')];
        }, $data['reflection_rows']));
    }
    if (value($data, 'reflection_target') !== '' || value($data, 'reflection_date_person') !== '' || value($data, 'education_target') !== '' || value($data, 'education_method') !== '') {
        return [['target' => value($data, 'reflection_target'), 'reflect_date' => '', 'person' => value($data, 'reflection_date_person'), 'edu_target' => value($data, 'education_target'), 'edu_date' => '', 'edu_method' => value($data, 'education_method')]];
    }
    return [];
}

/**
 * 위험성평가 결과 및 검토 보고서를 TCPDF로 처음부터 직접 그리는 빌더.
 * 고정된 배경 서식 위에 값만 얹던 기존 방식과 달리, 모든 표가 내용에 맞춰
 * 높이가 늘어나고 필요하면 자동으로 새 페이지를 추가한다(행 수 제한 없음).
 */
final class ReportPdf
{
    private const MARGIN_LEFT = 19.0;
    private const MARGIN_RIGHT = 19.0;
    private const MARGIN_TOP = 25.4;
    private const PAGE_WIDTH = 210.0;
    private const CONTENT_WIDTH = 172.0;
    private const CONTENT_BOTTOM = 279.0;
    private const LABEL_TOP = 14.0;
    private const FOOTER_TOP = 285.5;

    public TCPDF $pdf;
    private float $y = 0.0;
    private int $pageNumber = 0;

    public function __construct(string $docTitle)
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $pdf->SetAutoPageBreak(false);
        $pdf->setCellPaddings(1.2, 1.0, 1.2, 1.0);
        $pdf->SetCreator('Safety Management System');
        $pdf->SetTitle($docTitle);
        $this->pdf = $pdf;
        $this->newPage();
    }

    private function newPage(): void
    {
        $this->pageNumber++;
        $this->pdf->AddPage();
        $this->y = self::MARGIN_TOP;
        $this->pdf->SetFont('cid0kr', '', 9);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->SetXY(self::MARGIN_LEFT, self::LABEL_TOP);
        $this->pdf->Cell(0, 4, '양식 3) 위험성평가 결과 및 검토보고서', 0, 0, 'L');
        $this->pdf->SetXY(0, self::FOOTER_TOP);
        $this->pdf->Cell(self::PAGE_WIDTH, 4, '', 0, 0, 'C'); // 실제 텍스트는 finalize()에서 총 페이지 수 확정 후 기록
        $this->pdf->SetTextColor(0, 0, 0);
    }

    private function ensureSpace(float $height): void
    {
        if ($this->y + $height > self::CONTENT_BOTTOM) {
            $this->newPage();
        }
    }

    public function heading(string $text): void
    {
        $this->ensureSpace(10.0);
        $this->pdf->SetFont('cid0kr', '', 12);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->y);
        $this->pdf->Cell(self::CONTENT_WIDTH, 6, $text, 0, 0, 'L');
        $this->y += 8.0;
    }

    public function paragraph(string $text, float $size = 10.0): void
    {
        if ($text === '') { return; }
        $this->pdf->SetFont('cid0kr', '', $size);
        $height = $this->pdf->getStringHeight(self::CONTENT_WIDTH, $text);
        $this->ensureSpace($height + 2.0);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->y);
        $this->pdf->MultiCell(self::CONTENT_WIDTH, $height, $text, 0, 'L', false, 1, self::MARGIN_LEFT, $this->y);
        $this->y += $height + 2.0;
    }

    public function note(string $text): void { $this->paragraph($text, 8.5); }

    /** 라벨(고정 폭) + 값(나머지 폭) 쌍으로 구성된 표. 값이 길면 행 높이가 자동으로 늘어난다. */
    public function labeledTable(array $rows, float $labelWidth = 34.0): void
    {
        $valueWidth = self::CONTENT_WIDTH - $labelWidth;
        foreach ($rows as [$label, $value]) {
            $this->pdf->SetFont('cid0kr', '', 9);
            $height = max(8.0, $this->pdf->getStringHeight($valueWidth, (string)$value));
            $this->ensureSpace($height);
            $x = self::MARGIN_LEFT;
            $this->pdf->SetFillColor(235, 238, 242);
            $this->pdf->SetTextColor(0, 0, 0);
            $this->pdf->MultiCell($labelWidth, $height, (string)$label, 1, 'C', true, 0, $x, $this->y, true, 0, false, true, $height, 'M');
            $this->pdf->MultiCell($valueWidth, $height, (string)$value, 1, 'L', false, 1, $x + $labelWidth, $this->y, true, 0, false, true, $height, 'M');
            $this->y += $height;
        }
    }

    /**
     * 일반 표(헤더 + N행). $columns = [['key'=>..,'label'=>..,'width'=>mm], ...]
     * 행 수 제한 없이 내용에 맞춰 높이가 늘어나고, 페이지를 넘기면 헤더를 반복한다.
     */
    public function table(array $columns, array $rows, float $minRowHeight = 8.0): void
    {
        $colX = [];
        $x = self::MARGIN_LEFT;
        foreach ($columns as $col) { $colX[] = $x; $x += $col['width']; }
        $this->pdf->SetFont('cid0kr', '', 9);
        $headerHeight = $minRowHeight;
        foreach ($columns as $col) {
            $h = $this->pdf->getStringHeight($col['width'], $col['label']);
            if ($h > $headerHeight) { $headerHeight = $h; }
        }

        $drawHeader = function () use ($columns, $colX, $headerHeight): void {
            $this->ensureSpace($headerHeight);
            $this->pdf->SetFont('cid0kr', '', 9);
            $this->pdf->SetFillColor(235, 238, 242);
            $this->pdf->SetTextColor(0, 0, 0);
            foreach ($columns as $i => $col) {
                $this->pdf->MultiCell($col['width'], $headerHeight, $col['label'], 1, 'C', true, 0, $colX[$i], $this->y, true, 0, false, true, $headerHeight, 'M');
            }
            $this->y += $headerHeight;
        };
        $drawHeader();

        foreach ($rows as $row) {
            $this->pdf->SetFont('cid0kr', '', 9);
            $height = $minRowHeight;
            foreach ($columns as $col) {
                $h = $this->pdf->getStringHeight($col['width'], (string)($row[$col['key']] ?? ''));
                if ($h > $height) { $height = $h; }
            }
            if ($this->y + $height > self::CONTENT_BOTTOM) {
                $this->newPage();
                $drawHeader();
            }
            foreach ($columns as $i => $col) {
                $this->pdf->MultiCell($col['width'], $height, (string)($row[$col['key']] ?? ''), 1, $col['align'] ?? 'L', false, 0, $colX[$i], $this->y, true, 0, false, true, $height, 'M');
            }
            $this->y += $height;
        }
    }

    public function gap(float $height): void { $this->y += $height; }

    /**
     * 제목 행: 왼쪽은 테두리 없는 셀에 제목+부제목을 넣고, 오른쪽은 작성/검토/승인 3단 결재란(테두리 있음)을 붙여 그린다.
     */
    public function titleRow(string $title, string $subtitle): void
    {
        $rowHeight = 24.0;
        $this->ensureSpace($rowHeight + 3.0);
        $boxWidth = 54.0;
        $leftWidth = self::CONTENT_WIDTH - $boxWidth;
        $x = self::MARGIN_LEFT;
        $y = $this->y;

        // 왼쪽: 제목 + 부제목 (테두리 없는 한 셀)
        $this->pdf->SetFont('cid0kr', '', 16);
        $this->pdf->SetXY($x, $y + 4.0);
        $this->pdf->Cell($leftWidth, 8, $title, 0, 2, 'C');
        $this->pdf->SetFont('cid0kr', '', 11);
        $this->pdf->SetX($x);
        $this->pdf->Cell($leftWidth, 7, $subtitle, 0, 0, 'C');

        // 오른쪽: 작성/검토/승인 3단 결재란 (사인란은 제목 행 전체 높이보다 작게)
        $approvalX = $x + $leftWidth;
        $colWidth = $boxWidth / 3;
        $headerHeight = 6.0;
        $dataHeight = 13.0;
        $this->pdf->SetFont('cid0kr', '', 9);
        foreach (['작성', '검토', '승인'] as $i => $label) {
            $cx = $approvalX + $i * $colWidth;
            $this->pdf->SetFillColor(235, 238, 242);
            $this->pdf->SetTextColor(0, 0, 0);
            $this->pdf->MultiCell($colWidth, $headerHeight, $label, 1, 'C', true, 0, $cx, $y, true, 0, false, true, $headerHeight, 'M');
            $this->pdf->MultiCell($colWidth, $dataHeight, '', 1, 'C', false, 0, $cx, $y + $headerHeight, true, 0, false, true, $dataHeight, 'M');
        }

        $this->y = $y + $rowHeight + 3.0;
    }

    /** 모든 내용을 다 그린 뒤, 실제 총 페이지 수를 알고 나서 각 페이지 하단에 "n / 총N" 형식으로 기록한다. */
    public function finalize(): void
    {
        $totalPages = $this->pdf->getNumPages();
        for ($page = 1; $page <= $totalPages; $page++) {
            $this->pdf->setPage($page);
            $this->pdf->SetFont('cid0kr', '', 9);
            $this->pdf->SetXY(0, self::FOOTER_TOP);
            $this->pdf->Cell(self::PAGE_WIDTH, 4, '반기 위험성평가 결과 및 검토 보고서 | ' . $page . ' / ' . $totalPages, 0, 0, 'C');
        }
    }
}

$year = max(2000, min(2100, (int)($_REQUEST['year'] ?? date('Y'))));
$half = ($_REQUEST['half'] ?? ((int)date('n') <= 6 ? '상반기' : '하반기')) === '하반기' ? '하반기' : '상반기';
$blank = ($_GET['blank'] ?? '') === '1'; // 매뉴얼 본문 링크 등에서 저장된 데이터 없이 빈 양식만 보여줄 때 사용
$data = ['year' => (string)$year, 'half' => $half, 'report_date' => date('Y-m-d'), 'acceptable_standard' => '6'];

if (!$blank) {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS safety_risk_assessment_reports(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,report_year SMALLINT UNSIGNED NOT NULL,half_year TINYINT UNSIGNED NOT NULL,report_data LONGTEXT NOT NULL,updated_by VARCHAR(100) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_period(report_year,half_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = clean_data($_POST) + $data;
            $data['review_rows'] = build_review_rows($_POST);
            $data['reflection_rows'] = build_reflection_rows($_POST);
            $statement = $db->prepare('INSERT INTO safety_risk_assessment_reports(report_year,half_year,report_data,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE report_data=VALUES(report_data),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
            $statement->execute([$year, $half === '상반기' ? 1 : 2, json_encode($data, JSON_UNESCAPED_UNICODE), trim((string)($user['name'] ?? $user['login_id']))]);
        } else {
            $statement = $db->prepare('SELECT report_data FROM safety_risk_assessment_reports WHERE report_year=? AND half_year=?');
            $statement->execute([$year, $half === '상반기' ? 1 : 2]);
            $saved = $statement->fetchColumn();
            if (is_string($saved) && is_array($decoded = json_decode($saved, true))) { $data = $decoded + $data; }
        }
    } catch (Throwable $exception) { error_log($exception->getMessage()); }
}

$reviewRows = normalize_review_rows($data);
$reflectionRows = normalize_reflection_rows($data);

// 문서번호는 작성일에서 바로 유추할 수 있는 자동 키 (예: SM-RA-20260630-H1)
$reportDateDigits = value($data, 'report_date') !== '' ? str_replace('-', '', value($data, 'report_date')) : date('Ymd');
$data['document_number'] = 'SM-RA-' . $reportDateDigits . '-' . ($half === '상반기' ? 'H1' : 'H2');

// 개선율/합계는 화면에서 자동 계산되는 값이지만, 서버에서도 항상 다시 계산해 어긋남을 방지한다
$toNumber = static fn(string $raw): float => (float)str_replace(',', '', $raw);
$summaryTarget = $toNumber(value($data, 'summary_target'));
$summaryCompleted = $toNumber(value($data, 'summary_completed'));
$data['summary_rate'] = (string)($summaryTarget > 0 ? round($summaryCompleted / $summaryTarget * 100) : 0);
foreach (['target', 'completed', 'pending', 'cost'] as $counterKey) {
    $sum = 0.0;
    foreach (['remove', 'replace', 'engineering', 'management', 'ppe'] as $typeKey) {
        $sum += $toNumber(value($data, 'counter_' . $counterKey . '_' . $typeKey));
    }
    $data['counter_' . $counterKey . '_total'] = (string)$sum;
}
foreach (['remove', 'replace', 'engineering', 'management', 'ppe', 'total'] as $typeKey) {
    $costKey = 'counter_cost_' . $typeKey;
    $raw = $toNumber(value($data, $costKey));
    $data[$costKey] = $raw == 0 && value($data, $costKey) === '' ? '' : number_format($raw);
}

$doc = new ReportPdf($year . '년 ' . $half . ' 위험성평가 결과 및 검토 보고서');
$pdf = $doc->pdf;

$doc->titleRow(
    '위험성평가 결과 및 검토 보고서',
    $year . '년도    ' . checkbox($half === '상반기') . ' 상반기    ' . checkbox($half === '하반기') . ' 하반기'
);

$doc->labeledTable([
    ['문서번호', value($data, 'document_number')],
    ['작성일', value($data, 'report_date')],
], 34.0);
$doc->gap(3.0);
$doc->paragraph('해당 반기의 위험성평가 결과와 개선 이행 현황을 검토하여 보고합니다.');

$doc->heading('1. 평가 개요');
$doc->labeledTable([
    ['평가기간', value($data, 'evaluation_start') . '  ~  ' . value($data, 'evaluation_end')],
    ['평가구분 및 대상', checkbox(value($data, 'evaluation_type') === '정기') . ' 정기  ' . checkbox(value($data, 'evaluation_type') === '수시') . ' 수시   /   대상 부서·공정: ' . value($data, 'evaluation_target')],
    ['평가방법', '4M / 인적·기계적·물질 및 환경적·관리적 요인'],
    ['위험도 산출기준', '빈도(1~5) × 강도(1~4) = 위험도(1~20)   허용가능 판단기준: ' . value($data, 'acceptable_standard')],
    ['참여자', '평가담당자: ' . value($data, 'evaluator') . '   관리감독자: ' . value($data, 'supervisor') . '   참여 근로자·근로자대표: ' . value($data, 'workers')],
    ['검토기간 및 중점', '검토기간: ' . value($data, 'review_period') . '   중점사항: ' . value($data, 'focus')],
]);

$doc->heading('2. 종합 결과');
$doc->paragraph('집계기준일: ' . value($data, 'aggregation_date') . ' / 집계대상: 해당 반기 평가요인(중복 제외)');
$doc->table(
    [
        ['key' => 'total', 'label' => '전체 유해위험요인', 'width' => 28.67, 'align' => 'C'],
        ['key' => 'acceptable', 'label' => '허용가능', 'width' => 28.67, 'align' => 'C'],
        ['key' => 'target', 'label' => '개선대상', 'width' => 28.67, 'align' => 'C'],
        ['key' => 'completed', 'label' => '개선완료', 'width' => 28.67, 'align' => 'C'],
        ['key' => 'pending', 'label' => '미개선', 'width' => 28.66, 'align' => 'C'],
        ['key' => 'rate', 'label' => '개선율', 'width' => 28.66, 'align' => 'C'],
    ],
    [[
        'total' => value($data, 'summary_total') . ' 건', 'acceptable' => value($data, 'summary_acceptable') . ' 건',
        'target' => value($data, 'summary_target') . ' 건', 'completed' => value($data, 'summary_completed') . ' 건',
        'pending' => value($data, 'summary_pending') . ' 건', 'rate' => value($data, 'summary_rate') . ' %',
    ]]
);
$doc->note('※ 전체 = 허용가능 + 개선대상 / 개선대상 = 개선완료 + 미개선');
$doc->note('※ 허용가능은 개선 전 판단 기준. 개선율 = 개선완료 ÷ 개선대상 × 100(대상 0건은 해당 없음).');

$doc->heading('3. 정기 및 수시 위험성평가 검토 결과');
$doc->table(
    [
        ['key' => 'type', 'label' => '구분', 'width' => 15.0, 'align' => 'C'],
        ['key' => 'work', 'label' => '공정·작업명', 'width' => 32.0],
        ['key' => 'hazard', 'label' => '주요 위험요인', 'width' => 32.0],
        ['key' => 'review', 'label' => '검토결과', 'width' => 32.0],
        ['key' => 'action', 'label' => '조치사항', 'width' => 32.0],
        ['key' => 'worker', 'label' => '근로자 참여', 'width' => 29.0],
    ],
    $reviewRows
);
$doc->note('※ 검토결과: 신규·변경 요인, 누락 및 평가 반영 여부 / 조치사항: 담당자·기한 또는 5항 연계번호');
$doc->note('※ 근로자 참여: 성명·참여내용 또는 증빙번호. 상세내역은 별첨하며 동일 위험요인은 중복 집계하지 않음.');

$doc->heading('4. 개선대책 유형별 현황');
$counterTypeLabels = ['remove' => '제거', 'replace' => '대체·변경', 'engineering' => '공학적', 'management' => '관리적', 'ppe' => '보호구', 'total' => '합계'];
$counterRows = [];
foreach (['target' => '개선대상(건)', 'completed' => '개선완료(건)', 'pending' => '미개선(건)', 'cost' => '개선비용(천원)'] as $rowKey => $rowLabel) {
    $row = ['label' => $rowLabel];
    foreach ($counterTypeLabels as $typeKey => $_) { $row[$typeKey] = value($data, 'counter_' . $rowKey . '_' . $typeKey); }
    $counterRows[] = $row;
}
$doc->table(
    array_merge(
        [['key' => 'label', 'label' => '구분', 'width' => 25.0, 'align' => 'C']],
        array_map(static fn($key, $label) => ['key' => $key, 'label' => $label, 'width' => (172.0 - 25.0) / 6, 'align' => 'C'], array_keys($counterTypeLabels), array_values($counterTypeLabels))
    ),
    $counterRows
);
$doc->note('※ 위험요인별 주된 대책 1개로 분류하여 2항의 건수와 일치시킴. 복수 대책은 세부 평가표에 기록.');
$doc->note('※ 개선비용은 집계기준일까지 실제 집행한 금액으로 기재.');

$planRows = [];
foreach ([1, 2, 3] as $number) {
    $planRows[] = [
        'number' => value($data, 'plan_' . $number . '_number'), 'reason' => value($data, 'plan_' . $number . '_reason'),
        'measure' => value($data, 'plan_' . $number . '_measure'), 'person' => value($data, 'plan_' . $number . '_person'),
        'due' => value($data, 'plan_' . $number . '_due'), 'check' => value($data, 'plan_' . $number . '_check'),
    ];
}
if (!rows_are_empty($planRows, ['number', 'reason', 'measure', 'person', 'due', 'check'])) {
    $doc->heading('5. 미개선 및 향후 계획');
    $doc->table(
        [
            ['key' => 'number', 'label' => '관리번호 및 공정·작업명', 'width' => 30.0],
            ['key' => 'reason', 'label' => '미개선 사유 및 잔여 위험요인', 'width' => 34.0],
            ['key' => 'measure', 'label' => '개선대책 및 완료 전 임시조치', 'width' => 34.0],
            ['key' => 'person', 'label' => '담당자', 'width' => 20.0, 'align' => 'C'],
            ['key' => 'due', 'label' => '완료예정일', 'width' => 24.0, 'align' => 'C'],
            ['key' => 'check', 'label' => '확인계획', 'width' => 30.0],
        ],
        $planRows
    );
    $doc->note('※ 미개선에는 진행 중인 건을 포함. 확인계획에 조치 후 위험도 재평가 및 완료 확인 일정을 기재.');
    $doc->gap(2.0);
}

if (!rows_are_empty($reflectionRows, ['target', 'reflect_date', 'person', 'edu_target', 'edu_date', 'edu_method'])) {
    $doc->heading('정기평가 반영계획 및 결과 공유·교육');
    $doc->table(
        [
            ['key' => 'target', 'label' => '정기평가 반영 대상 공정·작업', 'width' => 40.0],
            ['key' => 'reflect_date', 'label' => '반영 예정일', 'width' => 22.0, 'align' => 'C'],
            ['key' => 'person', 'label' => '담당자', 'width' => 22.0, 'align' => 'C'],
            ['key' => 'edu_target', 'label' => '공유·교육 대상', 'width' => 30.0, 'align' => 'C'],
            ['key' => 'edu_date', 'label' => '실시 예정일', 'width' => 22.0, 'align' => 'C'],
            ['key' => 'edu_method', 'label' => '방법', 'width' => 36.0],
        ],
        $reflectionRows
    );
    $doc->gap(2.0);
}

if (value($data, 'opinion') !== '') {
    $doc->heading('종합 검토의견');
    $doc->paragraph(value($data, 'opinion'));
}

$attachmentRows = [];
foreach ([1, 2, 3, 4] as $number) {
    $attachmentRows[] = [
        'no' => (string)$number, 'name' => value($data, 'attachment_' . $number . '_name'),
        'period' => value($data, 'attachment_' . $number . '_period'), 'quantity' => value($data, 'attachment_' . $number . '_quantity'),
        'note' => value($data, 'attachment_' . $number . '_note'), 'file' => value($data, 'attachment_' . $number . '_file') !== '' ? '첨부됨' : '',
    ];
}
if (!rows_are_empty($attachmentRows, ['name', 'period', 'quantity', 'note', 'file'])) {
    $doc->heading('6. 첨부문서 목록');
    $doc->table(
        [
            ['key' => 'no', 'label' => '번호', 'width' => 14.0, 'align' => 'C'],
            ['key' => 'name', 'label' => '문서명', 'width' => 54.0],
            ['key' => 'period', 'label' => '문서번호 또는 대상기간', 'width' => 40.0, 'align' => 'C'],
            ['key' => 'quantity', 'label' => '수량', 'width' => 16.0, 'align' => 'C'],
            ['key' => 'note', 'label' => '비고', 'width' => 32.0],
            ['key' => 'file', 'label' => '스캔본', 'width' => 16.0, 'align' => 'C'],
        ],
        $attachmentRows
    );
    $doc->note('※ 위험성평가표, 개선 전후 사진, 근로자 참여·의견 기록 등 실제 첨부한 문서만 기재.');
}

$doc->finalize();

$filename = $year . '년_' . $half . '_위험성평가_결과_및_검토_보고서.pdf';
$pdf->Output($filename, 'I');
