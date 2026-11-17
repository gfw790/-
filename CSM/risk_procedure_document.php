<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/risk_procedure_document_store.php';

$user = csm_require_auth();
header('Cache-Control: no-store');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if (!function_exists("csm_ra_org_chart_html")) {
function csm_ra_org_chart_html(array $rows): string
{
    $manager = null; $assessor = null; $supervisor = null; $workers = [];
    foreach ($rows as $row) {
        $role = trim((string)($row[0] ?? ''));
        if ($role === '') continue;
        if (str_contains($role, '안전보건관리책임자')) $manager = $row;
        elseif (str_contains($role, '위험성평가 담당자')) $assessor = $row;
        elseif (str_contains($role, '관리감독자')) $supervisor = $row;
        else $workers[] = $row;
    }
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    
    $nodeTable = static function (string $kind, array $row, string $style) use ($escape): string {
        $title = $escape($row[0] ?? '');
        $pos = trim((string)($row[1] ?? ''));
        if ($pos === '—') $pos = '';
        $name = $escape($row[2] ?? '');
        
        $html = '<table class="org-node-table org-node-' . $kind . '" style="' . $style . '">';
        if ($kind === 'worker') {
            $html .= '<thead><tr><th class="org-node-title">' . ($title ?: '근로자') . '</th></tr></thead>';
            $html .= '<tbody><tr><td class="org-worker-val">' . $name . '</td></tr></tbody>';
        } elseif ($pos !== '') {
            $html .= '<thead><tr><th colspan="2" class="org-node-title">' . $title . '</th></tr></thead>';
            $html .= '<tbody>';
            $html .= '<tr><td class="org-lbl">직위</td><td class="org-val">' . $escape($pos) . '</td></tr>';
            $html .= '<tr><td class="org-lbl">성명</td><td class="org-val">' . $name . '</td></tr>';
            $html .= '</tbody>';
        } else {
            $html .= '<thead><tr><th colspan="2" class="org-node-title">' . $title . '</th></tr></thead>';
            $html .= '<tbody><tr><td class="org-lbl">성명</td><td class="org-val">' . $name . '</td></tr></tbody>';
        }
        $html .= '</table>';
        return $html;
    };

    $html = '<div class="org-chart" role="group" aria-label="위험성평가 실시 담당 조직도">';
    if ($manager) $html .= $nodeTable('manager', $manager, 'left:35%;top:0%;width:30%;height:16.3%;');
    if ($assessor) $html .= $nodeTable('assessor', $assessor, 'left:70%;top:19.02%;width:30%;height:16.3%;');
    if ($supervisor) $html .= $nodeTable('supervisor', $supervisor, 'left:28%;top:38.04%;width:44%;height:23.91%;');

    $n = count($workers);
    $centers = [];
    if ($n === 3) {
        $workersMeta = [
            ['left' => '0%', 'width' => '30%', 'center' => 150],
            ['left' => '35%', 'width' => '30%', 'center' => 500],
            ['left' => '70%', 'width' => '30%', 'center' => 850],
        ];
        foreach ($workers as $idx => $worker) {
            $meta = $workersMeta[$idx] ?? ['left' => '0%', 'width' => '30%', 'center' => 500];
            $centers[] = $meta['center'];
            $html .= $nodeTable('worker', $worker, 'left:' . $meta['left'] . ';top:77.17%;width:' . $meta['width'] . ';height:16.3%;');
        }
    } else {
        $margin = 2.0; $gap = 6.0;
        $w = $n > 0 ? (96.0 - ($n - 1) * $gap) / $n : 28.0;
        foreach ($workers as $idx => $worker) {
            $left = $margin + $idx * ($w + $gap);
            $center = $left + $w / 2.0;
            $centers[] = (int)round($center * 10);
            $html .= $nodeTable('worker', $worker, sprintf('left:%.2f%%;top:77.17%%;width:%.2f%%;height:16.3%%;', $left, $w));
        }
    }

    // Connector SVG (viewBox 0 0 1000 460)
    $html .= '<svg class="org-chart-svg" viewBox="0 0 1000 460" preserveAspectRatio="none">';
    if ($manager && $supervisor) {
        $html .= '<line x1="500" y1="75" x2="500" y2="175" stroke="#334155" stroke-width="2" />';
    }
    if ($assessor) {
        $html .= '<line x1="500" y1="125" x2="700" y2="125" stroke="#334155" stroke-width="2" />';
    }
    if ($supervisor && count($centers) > 0) {
        $html .= '<line x1="500" y1="285" x2="500" y2="320" stroke="#334155" stroke-width="2" />';
        $minX = $centers[0];
        $maxX = end($centers);
        $html .= '<line x1="' . $minX . '" y1="320" x2="' . $maxX . '" y2="320" stroke="#334155" stroke-width="2" />';
        foreach ($centers as $cx) {
            $html .= '<line x1="' . $cx . '" y1="320" x2="' . $cx . '" y2="355" stroke="#334155" stroke-width="2" />';
        }
    }
    $html .= '</svg>';
    $html .= '</div>';
    return $html;
}
}

if (!function_exists("csm_ra_matrix_table_html")) {
function csm_ra_matrix_table_html(array $rows): string
{
    $escape = static fn($v) => htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8');
    $colorClass = static function(string $val): string {
        if (str_contains($val, '매우높음') || str_contains($val, '20') || str_contains($val, '16')) return 'matrix-cell-vhigh';
        if (str_contains($val, '높음') || str_contains($val, '15') || str_contains($val, '12') || str_contains($val, '10') || str_contains($val, '9')) return 'matrix-cell-high';
        if (str_contains($val, '보통') || str_contains($val, '8')) return 'matrix-cell-med';
        if (str_contains($val, '낮음') && !str_contains($val, '매우낮음')) return 'matrix-cell-low';
        return 'matrix-cell-vlow';
    };

    $html = '<div class="manual-table-wrap">';
    $html .= '<table class="risk-matrix-table">';
    $html .= '<thead>';
    $html .= '<tr>';
    $html .= '<th rowspan="2" colspan="2" class="matrix-top-corner">구 분</th>';
    $html .= '<th colspan="4" class="matrix-sev-top">중대성(강도)</th>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<th class="matrix-sev-sub">최대(4)</th>';
    $html .= '<th class="matrix-sev-sub">대(3)</th>';
    $html .= '<th class="matrix-sev-sub">중(2)</th>';
    $html .= '<th class="matrix-sev-sub">소(1)</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';

    $rowHeaders = ['최상(5)', '상(4)', '중(3)', '하(2)', '최하(1)'];
    foreach ($rows as $i => $row) {
        $html .= '<tr>';
        if ($i === 0) {
            $html .= '<th rowspan="5" class="matrix-freq-side">가능성<br>(빈도)</th>';
        }
        $rTitle = $rowHeaders[$i] ?? ($row[0] ?? '');
        $html .= '<th class="matrix-freq-level">' . $escape($rTitle) . '</th>';
        for ($c = 1; $c <= 4; $c++) {
            $cellVal = $row[$c] ?? '';
            $cls = $colorClass($cellVal);
            $html .= '<td class="' . $cls . '">' . $escape($cellVal) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}
}

if (!function_exists("csm_ra_action_criteria_table_html")) {
function csm_ra_action_criteria_table_html(array $rows): string
{
    $html = '<div class="manual-table-wrap">';
    $html .= '<table class="risk-criteria-table">';
    $html .= '<thead><tr>';
    $html .= '<th colspan="2" style="width:28%;">위험성(도) 크기</th>';
    $html .= '<th style="width:24%;">허용 가능 여부</th>';
    $html .= '<th>개선방법</th>';
    $html .= '</tr></thead><tbody>';

    $items = [
        ['score' => '16~20', 'level' => '매우높음', 'level_cls' => 'matrix-cell-vhigh', 'method' => '<strong class="method-safety-call">즉시 개선</strong> <span class="method-sub">(작업 중지 : Safety call)</span>'],
        ['score' => '9~15',  'level' => '높음',     'level_cls' => 'matrix-cell-high',  'method' => '<strong>신속하게 개선</strong>'],
        ['score' => '8',     'level' => '보통',     'level_cls' => 'matrix-cell-med',   'method' => '계획적으로 개선'],
        ['score' => '4~6',   'level' => '낮음',     'level_cls' => 'matrix-cell-low',   'method' => '필요에 따라 개선'],
        ['score' => '1~3',   'level' => '매우 낮음', 'level_cls' => 'matrix-cell-vlow',  'method' => '<span style="color:#64748b;">유지 및 관리 (현상태 유지)</span>'],
    ];

    foreach ($items as $idx => $it) {
        $html .= '<tr>';
        $html .= '<td style="text-align:center; font-weight:700; width:14%;">' . $it['score'] . '</td>';
        $html .= '<td style="text-align:center; font-weight:700; width:14%;" class="' . $it['level_cls'] . '">' . $it['level'] . '</td>';
        if ($idx === 0) {
            $html .= '<td rowspan="3" class="criteria-unacceptable"><span class="badge-unacceptable">허용 불가능</span></td>';
        } elseif ($idx === 3) {
            $html .= '<td rowspan="2" class="criteria-acceptable"><span class="badge-acceptable">허용 가능</span></td>';
        }
        $html .= '<td style="padding:8px 12px;">' . $it['method'] . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}
}

if (!function_exists("csm_ra_form_html")) {
function csm_ra_form_html(string $text): string
{
    $escape = static fn($v) => htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8');
    $isEdu = str_contains($text, '교육일시') || str_contains($text, '교육장소');
    $isTbm = str_contains($text, '작업 전 안전점검회의') || str_contains($text, '작업일 현재');
    
    $dateLabel = $isEdu ? '교육일시' : '회의일시';
    $placeLabel = $isEdu ? '교육장소' : '회의장소';
    $contentLabel = $isEdu ? '□ 교육내용' : '□ 회의내용';
    
    $lines = preg_split('/\R/u', $text) ?: [];
    $dateVal = '20 &nbsp; 년 &nbsp; &nbsp; 월 &nbsp; &nbsp; 일 &nbsp; &nbsp; &nbsp; : &nbsp; ～ &nbsp; &nbsp; :';
    $placeVal = $isEdu ? '(교육장)' : ($isTbm ? '(현장)' : '(회의실)');
    $contentItems = [];
    $photoLabel = $isEdu ? '위험성평가 교육실시 사진 또는 교육자료 등' : ($isTbm ? '작업 전 안전점검회의 사진 또는 회의자료 등' : '위험성평가 회의 사진 또는 회의자료 등');
    
    $inContent = false;
    foreach ($lines as $line) {
        $l = trim($line);
        if ($l === '') continue;
        if (str_contains($l, '년') && str_contains($l, '월') && str_contains($l, '일')) {
            $dateVal = $escape($l);
        } elseif (str_starts_with($l, '(') && str_ends_with($l, ')')) {
            $placeVal = $escape($l);
        } elseif (str_contains($l, '교육내용') || str_contains($l, '회의내용')) {
            $inContent = true;
        } elseif (str_contains($l, '사진') || str_contains($l, '자료')) {
            $inContent = false;
            $photoLabel = $escape($l);
        } elseif (str_contains($l, '참석자 명단')) {
            $inContent = false;
        } elseif ($inContent) {
            $cleanItem = ltrim($l, '●○◦- \t');
            if ($cleanItem !== '') {
                $contentItems[] = $escape($cleanItem);
            }
        }
    }
    
    if (!$contentItems) {
        if ($isEdu) {
            $contentItems = [
                '「위험성평가」를 위한 사업주의 방침과 추진목표',
                '「위험성평가」를 위한 사전준비 및 유해 · 위험요인 파악 방법',
                '유해 · 위험요인에 대한 위험성 결정방법',
                '위험성 감소대책 수립 및 실행의 절차와 기록유지 방법',
            ];
        } elseif ($isTbm) {
            $contentItems = [
                '작업일 현재 핵심 유해 · 위험요인',
                '핵심 유해 · 위험요인에 따른 근로자 준수사항 · 유의사항',
                '최근 동종업계 재해사례 등',
            ];
        } else {
            $contentItems = [
                '위험성평가 추진을 위한 계획수립의 적정성',
                '위험성평가 실시에 따른 책임과 역할 부여',
                '위험성평가와 관련한 관심사항 토론 등',
            ];
        }
    }

    $html = '<div class="doc-form-card">';
    $html .= '<table class="form-meta-table"><tr>';
    $html .= '<th>' . $dateLabel . '</th><td>' . $dateVal . '</td>';
    $html .= '<th>' . $placeLabel . '</th><td>' . $placeVal . '</td>';
    $html .= '</tr></table>';

    $html .= '<div class="form-content-block">';
    $html .= '<h4>' . $contentLabel . '</h4>';
    $html .= '<ul>';
    foreach ($contentItems as $it) {
        $html .= '<li>' . $it . '</li>';
    }
    $html .= '</ul></div>';

    $html .= '<div class="form-photo-frame">' . $photoLabel . '</div>';

    $html .= '<div class="form-roster-title">□ 참석자 명단</div>';
    $html .= '<table class="form-roster-table">';
    $html .= '<thead><tr>';
    $html .= '<th style="width:20%;">소속/직책</th><th style="width:18%;">성 &nbsp; &nbsp; 명</th><th style="width:12%;">서 명</th>';
    $html .= '<th style="width:20%;">소속/직책</th><th style="width:18%;">성 &nbsp; &nbsp; 명</th><th style="width:12%;">서 명</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody>';
    for ($r = 0; $r < 6; $r++) {
        $html .= '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>';
    }
    $html .= '</tbody></table>';

    $html .= '</div>';
    return $html;
}
}

if (!function_exists("csm_ra_body_html")) {
function csm_ra_body_html(string $text): string
{
    // If text represents a form template (서식 1, 2, 3)
    if ((str_contains($text, '교육일시') || str_contains($text, '회의일시')) && str_contains($text, '참석자 명단')) {
        return csm_ra_form_html($text);
    }

    $parts = [];
    $lines = preg_split('/\R/u', $text) ?: [];
    
    // Check if this is the policy section with goals
    $policyGoals = [];
    $hasPolicyGoals = str_contains($text, '산업재해 발생 제로') && str_contains($text, '위험성평가 우수사업장 인정');
    
    for ($index = 0; $index < count($lines); $index++) {
        $line = trim($lines[$index]);
        if ($line === '') continue;

        // Policy goals banner
        if ($hasPolicyGoals && in_array($line, ['산업재해 발생 제로', '위험성평가 우수사업장 인정', '상시적인 위험성평가 실시'], true)) {
            $policyGoals[] = $line;
            if (count($policyGoals) === 3) {
                $gHtml = '<div class="policy-goals-grid">';
                foreach ($policyGoals as $g) {
                    $gHtml .= '<div class="policy-goal-card">[ ' . htmlspecialchars($g, ENT_QUOTES, 'UTF-8') . ' ]</div>';
                }
                $gHtml .= '</div>';
                $parts[] = $gHtml;
            }
            continue;
        }

        // Reduction precautions callout box [감소대책 수립 시 주의사항]
        if (str_starts_with($line, '[감소대책')) {
            $wItems = [];
            $wTitle = $line;
            $index++;
            while ($index < count($lines)) {
                $wLine = trim($lines[$index]);
                if ($wLine === '' || preg_match('/^[②-⑳]/u', $wLine) || str_starts_with($wLine, '제')) {
                    $index--;
                    break;
                }
                $wItems[] = ltrim($wLine, '1234567890. -');
                $index++;
            }
            $wHtml = '<div class="ra-warning-box"><div class="ra-warning-title">' . htmlspecialchars($wTitle, ENT_QUOTES, 'UTF-8') . '</div><ol>';
            foreach ($wItems as $wi) {
                $wHtml .= '<li>' . htmlspecialchars($wi, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $wHtml .= '</ol></div>';
            $parts[] = $wHtml;
            continue;
        }

        // Table detection
        if (str_starts_with($line, '|') && str_ends_with($line, '|')) {
            $rows = [];
            while ($index < count($lines)) {
                $candidate = trim($lines[$index]);
                if (!str_starts_with($candidate, '|') || !str_ends_with($candidate, '|')) break;
                $rows[] = array_map('trim', explode('|', trim($candidate, '|')));
                $index++;
            }
            $index--;
            if ($rows) {
                $head = array_shift($rows);
                
                // 1. Org Chart (<표 1>)
                if (($head[0] ?? '') === '조직' && ($head[1] ?? '') === '직위' && ($head[2] ?? '') === '성명') {
                    $parts[] = csm_ra_org_chart_html($rows);
                    continue;
                }

                // 2. Risk Matrix 5x4 Grid (<표 6>)
                if (in_array('최대(4)', $head, true) && in_array('대(3)', $head, true)) {
                    $parts[] = csm_ra_matrix_table_html($rows);
                    continue;
                }

                // 3. Action Criteria (<표 7>)
                if (in_array('허용 가능 여부', $head, true) || in_array('개선방법', $head, true)) {
                    $parts[] = csm_ra_action_criteria_table_html($rows);
                    continue;
                }

                // Specific class for other standard tables
                $tableClass = 'manual-table';
                if (in_array('가능성', $head, true) && in_array('빈도', $head, true)) {
                    $tableClass .= ' table-likelihood';
                } elseif (in_array('중대성', $head, true) && in_array('점수', $head, true)) {
                    $tableClass .= ' table-severity';
                } elseif (in_array('구분', $head, true) && in_array('유해위험요인', $head, true)) {
                    $tableClass .= ' table-4m';
                } elseif (in_array('조직', $head, true) && in_array('역할과 책임(권한)', $head, true)) {
                    $tableClass .= ' table-roles';
                }

                $table = '<div class="manual-table-wrap"><table class="' . $tableClass . '"><thead><tr>';
                foreach ($head as $cell) {
                    $table .= '<th scope="col">' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</th>';
                }
                $table .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $table .= '<tr>';
                    foreach ($row as $cIdx => $cell) {
                        $items = array_filter(array_map('trim', explode(';', $cell)), static fn($item) => $item !== '');
                        if (count($items) > 1) {
                            $content = '<ul><li>' . implode('</li><li>', array_map(static fn($item) => htmlspecialchars($item, ENT_QUOTES, 'UTF-8'), $items)) . '</li></ul>';
                        } else {
                            $content = htmlspecialchars($cell, ENT_QUOTES, 'UTF-8');
                        }
                        $table .= '<td>' . $content . '</td>';
                    }
                    $table .= '</tr>';
                }
                $parts[] = $table . '</tbody></table></div>';
            }
            continue;
        }

        $class = 'manual-line';
        if (preg_match('/^[①-⑳]/u', $line)) $class .= ' manual-clause';
        elseif (preg_match('/^(?:[0-9]+[.)]|[가-하][.]|[-◦●⦁])/u', $line)) $class .= ' manual-item';
        elseif (preg_match('/^(?:<[^>]+>|\[[^]]+\]|《[^》]+》)/u', $line)) $class .= ' manual-subheading';
        $parts[] = '<p class="' . $class . '">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    return implode("\n", $parts);
}
}

$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$year = filter_var($_POST['year'] ?? $_GET['year'] ?? $today->format('Y'), FILTER_VALIDATE_INT);
if (!$year || $year < 2000 || $year > 2100) { http_response_code(400); exit('연도를 확인해 주세요.'); }
$canManage = auth_can_manage($user);
$db = csm_ra_procedure_db();
csm_ra_document_schema($db);
$_SESSION['csm_ra_document_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$notice = $_SESSION['csm_ra_document_notice'] ?? '';
unset($_SESSION['csm_ra_document_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canManage) throw new InvalidArgumentException('절차서를 수정할 권한이 없습니다.');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csm_ra_document_csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('요청을 확인할 수 없습니다. 새로고침해 주세요.');
        }
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($revision === false || $revision === null || $revision < 0) throw new InvalidArgumentException('문서 버전을 확인해 주세요.');
        $status = ($_POST['action'] ?? '') === 'issue' ? 'issued' : 'draft';
        csm_ra_document_save($db, $year, $_POST, $revision, $status, (string)$user['login_id']);
        $_SESSION['csm_ra_document_notice'] = $status === 'issued' ? '위험성평가 절차서를 발행했습니다.' : '위험성평가 절차서 초안을 저장했습니다.';
        header('Location: /CSM/risk_procedure_document.php?year=' . $year, true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : '문서를 저장하지 못했습니다.';
        if (!($exception instanceof InvalidArgumentException)) error_log('CSM RA procedure document: ' . $exception->getMessage());
    }
}

$record = csm_ra_document($db, $year);
$issuedRecord = csm_ra_document_issued($db, $year);
$history = csm_ra_document_history($db, $year);
$sections = csm_ra_document_sections();
$isPrint = ($_GET['print'] ?? '') === '1';
$requestedRevision = filter_var($_GET['revision'] ?? null, FILTER_VALIDATE_INT);
$historicalRecord = $requestedRevision !== null && $requestedRevision !== false && $requestedRevision > 0
    ? csm_ra_document_revision($db, $year, $requestedRevision) : null;
if (isset($_GET['revision']) && !$historicalRecord) { http_response_code(404); exit('해당 개정본을 찾을 수 없습니다.'); }
$displayRecord = $historicalRecord ?: ($isPrint && ($_GET['issued'] ?? '') === '1' ? $issuedRecord : $record);
$data = $displayRecord ? array_replace(csm_ra_document_defaults($year), $displayRecord['data']) : csm_ra_document_defaults($year);
if ($error && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($data as $key => $value) if (is_string($_POST[$key] ?? null)) $data[$key] = (string)$_POST[$key];
}
if ($isPrint && !$displayRecord) { http_response_code(404); exit('먼저 절차서를 저장해 주세요.'); }
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $h($year) ?>년 위험성평가 절차서 · CSM</title>
  <link rel="stylesheet" href="risk_procedure_document.css?v=<?= (int)filemtime(__DIR__ . '/risk_procedure_document.css') ?>">
</head>
<body>
<?php if (!$isPrint): ?>
<header class="app-head"><div><a href="/CSM/risk_procedure.php?year=<?= $year ?>">← 2.1.2 증빙관리</a><strong>위험성평가 절차서 작성</strong><span><?= $h($user['name'] ?? '') ?></span></div></header>
<main class="editor">
  <div class="editor-intro">
    <div>
      <small>CSM · 2.1.2 · <?= $year ?>년</small>
      <h1>위험성평가 실시규정</h1>
      <p>회사 규정의 제1조~제15조를 바탕으로 조문별 내용을 수정하고 인쇄할 수 있습니다. 초안은 평가 근거로 집계되지 않으며 발행본만 반영됩니다.</p>
    </div>
    <div class="editor-actions">
      <?php if ($canManage && !$historicalRecord): ?><a href="#revision-note-field">이번 개정 사유 입력 ↓</a><?php endif; ?>
      <a href="?year=<?= $year ?>&amp;print=1<?= $historicalRecord ? '&amp;revision=' . (int)$historicalRecord['revision'] : '' ?>" <?= !$displayRecord ? 'aria-disabled="true"' : '' ?> target="_blank" rel="noopener">인쇄 미리보기 ↗</a>
      <?php if ($issuedRecord): ?><a href="?year=<?= $year ?>&amp;print=1&amp;issued=1" target="_blank" rel="noopener">최근 발행본 보기 ↗</a><?php endif; ?>
    </div>
  </div>
  <?php if ($error): ?><p class="alert error" role="alert"><?= $h($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="alert success" role="status"><?= $h($notice) ?></p><?php endif; ?>
  <div class="status-line"><strong><?= $displayRecord && $displayRecord['status'] === 'issued' ? '발행본' : '초안' ?></strong><span><?= $displayRecord ? '저장 ' . $h($displayRecord['saved_at'] ?? $displayRecord['updated_at'] ?? '') . ' · 이력 ' . (int)$displayRecord['revision'] : '아직 저장되지 않음' ?></span></div>
  <p class="source-note">기본 문안 출처: 회사 「위험성평가 실시규정_2024.08.27.hwp」(2015.07.01 제정, 2024.08.27 8차 개정). 담당자 이름은 당시 기록이므로 현재 발행 전 확인하세요. <a href="https://moel.go.kr/info/lawinfo/instruction/view.do?bbs_seq=20261100126" target="_blank" rel="noopener">2026년 위험성평가 지침 ↗</a> 변경사항도 검토해 반영할 수 있습니다.</p>
  <?php if ($historicalRecord): ?><p class="alert history-view">개정 이력 <?= (int)$historicalRecord['revision'] ?>번을 읽는 중입니다. <a href="?year=<?= $year ?>">최신 편집본으로 돌아가기 →</a></p><?php endif; ?>
  
  <?php if ($canManage && !$historicalRecord): ?>
  <form method="post" class="document-form">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csm_ra_document_csrf']) ?>">
    <input type="hidden" name="year" value="<?= $year ?>">
    <input type="hidden" name="revision" value="<?= (int)($record['revision'] ?? 0) ?>">
    <section class="card">
      <h2>문서 정보</h2>
      <div class="metadata-grid">
        <label>회사명 <input name="company" value="<?= $h($data['company']) ?>" required></label>
        <label>적용 사업장 <input name="site" value="<?= $h($data['site']) ?>"></label>
        <label>문서명 <input name="document_name" value="<?= $h($data['document_name']) ?>" required></label>
        <label>개정번호 <input name="version" value="<?= $h($data['version']) ?>" required></label>
        <label>제정일 <input type="date" name="enacted_date" value="<?= $h($data['enacted_date']) ?>"></label>
        <label>시행일 <input type="date" name="effective_date" value="<?= $h($data['effective_date']) ?>"></label>
        <label>작성자 <input name="writer" value="<?= $h($data['writer']) ?>"></label>
        <label>검토자 <input name="reviewer" value="<?= $h($data['reviewer']) ?>"></label>
        <label>승인자 <input name="approver" value="<?= $h($data['approver']) ?>"></label>
      </div>
    </section>
    <div class="manual-workspace">
      <nav class="manual-toc" aria-label="실시규정 목차">
        <strong>목차</strong>
        <?php foreach ($sections as $key => $section): ?>
          <a href="#section-<?= $h($key) ?>"><?= $h($section['title']) ?></a>
        <?php endforeach; ?>
        <a href="#revision-history">개정 이력</a>
      </nav>
      <div class="manual-content">
        <div class="manual-banner">
          <small><?= $h($data['company']) ?> · <?= $h($data['site']) ?></small>
          <h2><?= $h($data['document_name']) ?></h2>
          <span>제정 <?= $h($data['enacted_date']) ?>　|　개정 <?= $h($data['version']) ?>　|　시행 <?= $h($data['effective_date']) ?></span>
        </div>
        <?php foreach ($sections as $key => $section): ?>
          <section class="manual-article" id="section-<?= $h($key) ?>">
            <h3><?= $h($section['title']) ?></h3>
            <div class="manual-paragraphs" id="preview-<?= $h($key) ?>"><?= csm_ra_body_html($data[$key]) ?></div>
            <details class="article-edit">
              <summary>이 부분 수정</summary>
              <label for="edit-<?= $h($key) ?>"><?= $h($section['title']) ?> 내용</label>
              <textarea id="edit-<?= $h($key) ?>" name="<?= $h($key) ?>" rows="9" data-preview="preview-<?= $h($key) ?>"><?= $h($data[$key]) ?></textarea>
              <?php if (in_array($key, ['article_3', 'article_4', 'article_8', 'article_9'], true)): ?>
                <p class="table-help">표는 한 행을 한 줄로 쓰고, 셀은 | 기호로 구분합니다. 한 셀 안의 항목은 ; 기호로 나눕니다.</p>
              <?php endif; ?>
            </details>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
    <section class="card revision-card" id="revision-note-field">
      <h2>이번 개정 사유</h2>
      <p>이번에 바꾼 내용을 적으면 저장 시 개정 이력에 함께 기록됩니다.</p>
      <label for="revision-note-input">개정 사유</label>
      <input id="revision-note-input" name="revision_note" value="<?= $h($data['revision_note']) ?>" placeholder="예: 위험성평가 조치기준 및 본문 구성 변경">
    </section>
    <div class="save-bar">
      <button name="action" value="draft">초안 저장</button>
      <button name="action" value="issue" class="primary" onclick="return confirm('현재 내용을 절차서 발행본으로 저장할까요?')">발행본 저장</button>
      <span>저장할 때마다 개정 이력이 남습니다. 발행에는 시행일·승인자·방침·본문 15개 조문과 서식 3종이 필요합니다.</span>
    </div>
  </form>
  <?php else: ?>
  <div class="manual-workspace">
    <nav class="manual-toc" aria-label="실시규정 목차">
      <strong>목차</strong>
      <?php foreach ($sections as $key => $section): ?>
        <a href="#section-<?= $h($key) ?>"><?= $h($section['title']) ?></a>
      <?php endforeach; ?>
      <a href="#revision-history">개정 이력</a>
    </nav>
    <div class="manual-content">
      <div class="manual-banner">
        <small><?= $h($data['company']) ?> · <?= $h($data['site']) ?></small>
        <h2><?= $h($data['document_name']) ?></h2>
        <span>제정 <?= $h($data['enacted_date']) ?>　|　개정 <?= $h($data['version']) ?>　|　시행 <?= $h($data['effective_date']) ?></span>
      </div>
      <?php foreach ($sections as $key => $section): ?>
        <section class="manual-article" id="section-<?= $h($key) ?>">
          <h3><?= $h($section['title']) ?></h3>
          <div class="manual-paragraphs"><?= csm_ra_body_html($data[$key]) ?></div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <section class="card history-card" id="revision-history">
    <h2>개정 이력</h2>
    <?php if (!$history): ?>
      <p>아직 저장된 개정 이력이 없습니다.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>저장 순서</th>
              <th>개정번호</th>
              <th>상태</th>
              <th>저장일시</th>
              <th>저장자</th>
              <th>개정 사유</th>
              <th>판본</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history as $entry): ?>
              <tr>
                <td><?= (int)$entry['revision'] ?></td>
                <td><?= $h($entry['data']['version'] ?? '') ?></td>
                <td><?= $entry['status'] === 'issued' ? '발행본' : '초안' ?></td>
                <td><?= $h($entry['saved_at']) ?></td>
                <td><?= $h($entry['saved_by']) ?></td>
                <td><?= $h($entry['data']['revision_note'] ?? '') ?></td>
                <td><a href="?year=<?= $year ?>&amp;revision=<?= (int)$entry['revision'] ?>">보기</a> · <a href="?year=<?= $year ?>&amp;print=1&amp;revision=<?= (int)$entry['revision'] ?>" target="_blank" rel="noopener">인쇄</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php else: ?>
<!-- Print view: content is split into A4 pages below. -->
<div class="print-toolbar">
  <a href="?year=<?= $year ?><?= $historicalRecord ? '&amp;revision=' . (int)$historicalRecord['revision'] : '' ?>">← 작성 화면</a>
  <strong><?= $h($data['document_name']) ?> · <?= $displayRecord['status'] === 'issued' ? '발행본' : '초안' ?></strong>
  <button type="button" onclick="window.print()">인쇄 / PDF 저장</button>
</div>

<?php
require_once __DIR__ . '/risk_procedure_print_pages.php';
$docPages = csm_ra_document_print_pages($sections, $data);

$enactedDateRaw = trim((string)($data['enacted_date'] ?? ''));
$enactedFormatted = $enactedDateRaw ? date('Y. m. d', strtotime($enactedDateRaw)) : '2015. 07. 01';

$effectiveDateRaw = trim((string)($data['effective_date'] ?? ''));
$versionStr = trim((string)($data['version'] ?? ''));
$versionSuffix = $versionStr !== '' ? '(' . $versionStr . ')' : '';
$effectiveFormatted = $effectiveDateRaw ? date('Y. m. d', strtotime($effectiveDateRaw)) . $versionSuffix : '2024. 08. 27' . $versionSuffix;

$approverName = trim((string)($data['approver'] ?? ''));
if ($approverName === '') $approverName = '정연탁';
$approverFormatted = mb_strlen($approverName, 'UTF-8') === 3
    ? mb_substr($approverName, 0, 1, 'UTF-8') . '  ' . mb_substr($approverName, 1, 1, 'UTF-8') . '  ' . mb_substr($approverName, 2, 1, 'UTF-8')
    : $approverName;

$writerName = trim((string)($data['writer'] ?? ''));
if ($writerName === '') $writerName = '정주랑';
$writerFormatted = mb_strlen($writerName, 'UTF-8') === 3
    ? mb_substr($writerName, 0, 1, 'UTF-8') . '  ' . mb_substr($writerName, 1, 1, 'UTF-8') . '  ' . mb_substr($writerName, 2, 1, 'UTF-8')
    : $writerName;
?>

<?php foreach ($docPages as $pIndex => $pDef): ?>
  <?php if (!empty($pDef['is_cover'])): ?>
    <!-- ================= PAGE 1: DEDICATED HWP COVER PAGE ================= -->
    <div class="paper cover-paper">
      <div class="cover-container">
        <!-- Top Title & Business Info -->
        <div class="cover-header-section">
          <h1 class="cover-main-title"><?= $h($data['document_name']) ?></h1>
          <div class="cover-business-info-wrap">
            <div class="cover-business-info">
              <div><span class="info-bullet">▢</span> 사업장명 : <strong><?= $h($data['company']) ?></strong></div>
              <div><span class="info-bullet">▢</span> 작성일자 : <strong><?= $h($data['effective_date'] ? date('Y년 m월 d일', strtotime($data['effective_date'])) : ($data['enacted_date'] ?: '2024년 08월 27일')) ?></strong></div>
            </div>
          </div>
        </div>

        <!-- 4-Column Formal Approval Stamp Table -->
        <div class="cover-approval-wrapper">
          <table class="cover-hwp-approval-table">
            <tr>
              <th class="approval-col-date-th">최종 제 · 개정일</th>
              <th class="approval-col-title-th">작 성 자</th>
              <th class="approval-col-title-th">검 토 자</th>
              <th class="approval-col-title-th">승 인 자</th>
            </tr>
            <tr>
              <td class="approval-col-date-td"><?= $h($data['effective_date'] ? str_replace('-', '. ', $data['effective_date']) : '2024. 08. 27') ?></td>
              <td class="approval-col-name-td"><?= $h($data['writer'] ?: '정   주   랑') ?></td>
              <td class="approval-col-name-td"><?= $h($data['reviewer'] ?: '진   종   철') ?></td>
              <td class="approval-col-name-td"><?= $h($data['approver'] ?: '정   연   탁') ?></td>
            </tr>
            <tr>
              <td class="approval-col-date-sub">&nbsp;</td>
              <td class="approval-col-stamp-td">(서명 / 확인)</td>
              <td class="approval-col-stamp-td">(서명 / 확인)</td>
              <td class="approval-col-stamp-td">(서명 / 확인)</td>
            </tr>
          </table>
        </div>

        <!-- Safety & Health Policy Banner -->
        <div class="cover-policy-banner">
          안전보건방침 및 추진목표
        </div>

        <!-- Policy Statements Card -->
        <div class="cover-policy-box">
          <div class="cover-policy-item">⦁ 근로자의 안전과 건강을 최우선으로 하는 무재해 사업장</div>
          <div class="cover-policy-item">⦁ 안전보건법규 준수 및 지속적인 위험성평가 활동</div>
          <div class="cover-policy-item">⦁ 위험성평가를 통한 안전하고 건강한 일터</div>
        </div>

        <!-- 3 Big Core Goal Boxes -->
        <div class="cover-goals-grid">
          <div class="cover-goal-card goal-card-1">
            <div class="goal-tag">목표 1</div>
            <div class="goal-main-text">산업재해<br><span class="goal-highlight">발생 제로</span></div>
          </div>
          <div class="cover-goal-card goal-card-2">
            <div class="goal-tag">목표 2</div>
            <div class="goal-main-text">위험성평가<br>우수사업장<br><span class="goal-highlight">인정</span></div>
          </div>
          <div class="cover-goal-card goal-card-3">
            <div class="goal-tag">목표 3</div>
            <div class="goal-main-text">상시적인<br>위험성평가<br><span class="goal-highlight">실시</span></div>
          </div>
        </div>

        <!-- Cover Footer Brand -->
        <div class="cover-footer-brand">
          <div class="footer-title"><?= $h($data['document_name']) ?></div>
          <div class="footer-company"><?= $h($data['company']) ?></div>
        </div>
      </div>
    </div>
  <?php elseif (!empty($pDef['is_articles_1_3'])): ?>
    <!-- ================= PAGE 2: ARTICLES 1 ~ 3 WITH HWP PROCEDURE HEADER ================= -->
    <div class="paper procedure-page-articles-1-3">
      <div class="document-box">
        <!-- 1. Top Header Block: Title + Approval Table + Date Table -->
        <div class="procedure-p2-header">
          <h1 class="procedure-p2-title"><?= $h($data['document_name']) ?></h1>
          <div class="procedure-p2-approval-wrap">
            <table class="procedure-p2-approval-table">
              <tr>
                <th class="p2-appr-role">승&nbsp;&nbsp;인</th>
                <td class="p2-appr-name"><?= $h($approverFormatted) ?>&nbsp;&nbsp;&nbsp;&nbsp;(인)</td>
              </tr>
              <tr>
                <th class="p2-appr-role">기&nbsp;&nbsp;안</th>
                <td class="p2-appr-name"><?= $h($writerFormatted) ?>&nbsp;&nbsp;&nbsp;&nbsp;(인)</td>
              </tr>
            </table>
          </div>
          <div class="procedure-p2-date-wrap">
            <table class="procedure-p2-date-table">
              <tr>
                <td class="p2-date-cell">제&nbsp;&nbsp;&nbsp;&nbsp;정 : <?= $h($enactedFormatted) ?></td>
                <td class="p2-date-cell">개&nbsp;&nbsp;&nbsp;&nbsp;정 : <?= $h($effectiveFormatted) ?></td>
              </tr>
            </table>
          </div>
        </div>

        <!-- 2. Bottom Articles Block (1조, 2조, 3조) aligned to bottom -->
        <div class="procedure-p2-articles">
          <?php foreach ($pDef['sections'] as $sec): ?>
            <section class="document-section">
              <h2><?= $h($sec['title']) ?></h2>
              <div class="manual-paragraphs"><?= csm_ra_body_html($sec['text']) ?></div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="document-footer">
        <div class="page-number"><?= $pIndex + 1 ?> / <?= count($docPages) ?></div>
        <div class="footer-brand">
          <img src="assets/policy-logo.png" alt="">
          <span><?= $h($data['company']) ?></span>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- ================= PAGES 2 ~ 10: INNER PAGES ================= -->
    <div class="paper<?= !empty($pDef['is_overview']) ? ' procedure-overview-page' : '' ?><?= !empty($pDef['is_step_tables']) ? ' step-tables-page' : '' ?>">
      <div class="document-box">
        <?php foreach ($pDef['sections'] as $sec): ?>
          <section class="document-section">
            <h2><?= $h($sec['title']) ?></h2>
            <div class="manual-paragraphs"><?= csm_ra_body_html($sec['text']) ?></div>
          </section>
        <?php endforeach; ?>
      </div>
      <div class="document-footer">
        <div class="page-number"><?= $pIndex + 1 ?> / <?= count($docPages) ?></div>
        <div class="footer-brand">
          <img src="assets/policy-logo.png" alt="">
          <span><?= $h($data['company']) ?></span>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php endforeach; ?>

<?php endif; ?>

<script>
function buildOrgChart(rows) {
    var manager = null, assessor = null, supervisor = null, workers = [];
    rows.forEach(function (row) {
        var role = row[0] || '';
        if (role.includes('안전보건관리책임자')) manager = row;
        else if (role.includes('위험성평가 담당자')) assessor = row;
        else if (role.includes('관리감독자')) supervisor = row;
        else workers.push(row);
    });

    var chart = document.createElement('div');
    chart.className = 'org-chart';
    chart.setAttribute('role', 'group');
    chart.setAttribute('aria-label', '위험성평가 실시 담당 조직도');

    function createTable(kind, row, styleStr) {
        var table = document.createElement('table');
        table.className = 'org-node-table org-node-' + kind;
        table.style.cssText = styleStr;
        var pos = row[1] && row[1] !== '—' ? row[1] : '';
        var name = row[2] || '';
        if (kind === 'worker') {
            table.innerHTML = '<thead><tr><th class="org-node-title">' + (row[0] || '근로자') + '</th></tr></thead>' +
                              '<tbody><tr><td class="org-worker-val">' + name + '</td></tr></tbody>';
        } else if (pos) {
            table.innerHTML = '<thead><tr><th colspan="2" class="org-node-title">' + row[0] + '</th></tr></thead>' +
                              '<tbody><tr><td class="org-lbl">직위</td><td class="org-val">' + pos + '</td></tr>' +
                              '<tr><td class="org-lbl">성명</td><td class="org-val">' + name + '</td></tr></tbody>';
        } else {
            table.innerHTML = '<thead><tr><th colspan="2" class="org-node-title">' + row[0] + '</th></tr></thead>' +
                              '<tbody><tr><td class="org-lbl">성명</td><td class="org-val">' + name + '</td></tr></tbody>';
        }
        return table;
    }

    if (manager) chart.append(createTable('manager', manager, 'left:35%;top:0%;width:30%;height:16.3%;'));
    if (assessor) chart.append(createTable('assessor', assessor, 'left:70%;top:19.02%;width:30%;height:16.3%;'));
    if (supervisor) chart.append(createTable('supervisor', supervisor, 'left:28%;top:38.04%;width:44%;height:23.91%;'));

    var n = workers.length;
    var centers = [];
    if (n === 3) {
        var workersMeta = [
            { left: '0%', width: '30%', center: 150 },
            { left: '35%', width: '30%', center: 500 },
            { left: '70%', width: '30%', center: 850 }
        ];
        workers.forEach(function (worker, idx) {
            var meta = workersMeta[idx];
            centers.push(meta.center);
            chart.append(createTable('worker', worker, 'left:' + meta.left + ';top:77.17%;width:' + meta.width + ';height:16.3%;'));
        });
    } else {
        var margin = 2.0; var gap = 6.0;
        var w = n > 0 ? (96.0 - (n - 1) * gap) / n : 28.0;
        workers.forEach(function (worker, idx) {
            var left = margin + idx * (w + gap);
            var center = left + w / 2.0;
            centers.push(Math.round(center * 10));
            chart.append(createTable('worker', worker, 'left:' + left.toFixed(2) + '%;top:77.17%;width:' + w.toFixed(2) + '%;height:16.3%;'));
        });
    }

    // Create SVG (viewBox 0 0 1000 460)
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'org-chart-svg');
    svg.setAttribute('viewBox', '0 0 1000 460');
    svg.setAttribute('preserveAspectRatio', 'none');

    function addLine(x1, y1, x2, y2) {
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', String(x1));
        line.setAttribute('y1', String(y1));
        line.setAttribute('x2', String(x2));
        line.setAttribute('y2', String(y2));
        line.setAttribute('stroke', '#334155');
        line.setAttribute('stroke-width', '2');
        svg.appendChild(line);
    }

    if (manager && supervisor) addLine(500, 75, 500, 175);
    if (assessor) addLine(500, 125, 700, 125);
    if (supervisor && centers.length > 0) {
        addLine(500, 285, 500, 320);
        var minX = centers[0];
        var maxX = centers[centers.length - 1];
        addLine(minX, 320, maxX, 320);
        centers.forEach(function (cx) {
            addLine(cx, 320, cx, 355);
        });
    }
    chart.append(svg);
    return chart;
}

function buildRiskMatrix(rows) {
    var wrap = document.createElement('div'); wrap.className = 'manual-table-wrap';
    var table = document.createElement('table'); table.className = 'risk-matrix-table';
    var thead = document.createElement('thead');
    thead.innerHTML = '<tr><th rowspan="2" colspan="2" class="matrix-top-corner">구 분</th><th colspan="4" class="matrix-sev-top">중대성(강도)</th></tr>' +
                      '<tr><th class="matrix-sev-sub">최대(4)</th><th class="matrix-sev-sub">대(3)</th><th class="matrix-sev-sub">중(2)</th><th class="matrix-sev-sub">소(1)</th></tr>';
    table.append(thead);
    var tbody = document.createElement('tbody');
    var rowHeaders = ['최상(5)', '상(4)', '중(3)', '하(2)', '최하(1)'];
    function getCls(val) {
        if (val.includes('매우높음') || val.includes('20') || val.includes('16')) return 'matrix-cell-vhigh';
        if (val.includes('높음') || val.includes('15') || val.includes('12') || val.includes('10') || val.includes('9')) return 'matrix-cell-high';
        if (val.includes('보통') || val.includes('8')) return 'matrix-cell-med';
        if (val.includes('낮음') && !val.includes('매우낮음')) return 'matrix-cell-low';
        return 'matrix-cell-vlow';
    }
    rows.forEach(function(row, idx) {
        var tr = document.createElement('tr');
        if (idx === 0) {
            var thSide = document.createElement('th');
            thSide.rowSpan = 5;
            thSide.className = 'matrix-freq-side';
            thSide.innerHTML = '가능성<br>(빈도)';
            tr.append(thSide);
        }
        var thLvl = document.createElement('th');
        thLvl.className = 'matrix-freq-level';
        thLvl.textContent = rowHeaders[idx] || (row[0] || '');
        tr.append(thLvl);
        for (var c = 1; c <= 4; c++) {
            var td = document.createElement('td');
            var val = row[c] || '';
            td.className = getCls(val);
            td.textContent = val;
            tr.append(td);
        }
        tbody.append(tr);
    });
    table.append(tbody); wrap.append(table);
    return wrap;
}

function buildActionCriteria() {
    var wrap = document.createElement('div'); wrap.className = 'manual-table-wrap';
    var table = document.createElement('table'); table.className = 'risk-criteria-table';
    table.innerHTML = '<thead><tr><th colspan="2" style="width:28%;">위험성(도) 크기</th><th style="width:24%;">허용 가능 여부</th><th>개선방법</th></tr></thead>' +
        '<tbody>' +
        '<tr><td style="text-align:center;font-weight:700;width:14%;">16~20</td><td style="text-align:center;font-weight:700;width:14%;" class="matrix-cell-vhigh">매우높음</td><td rowspan="3" class="criteria-unacceptable"><span class="badge-unacceptable">허용 불가능</span></td><td style="padding:8px 12px;"><strong class="method-safety-call">즉시 개선</strong> <span class="method-sub">(작업 중지 : Safety call)</span></td></tr>' +
        '<tr><td style="text-align:center;font-weight:700;width:14%;">9~15</td><td style="text-align:center;font-weight:700;width:14%;" class="matrix-cell-high">높음</td><td style="padding:8px 12px;"><strong>신속하게 개선</strong></td></tr>' +
        '<tr><td style="text-align:center;font-weight:700;width:14%;">8</td><td style="text-align:center;font-weight:700;width:14%;" class="matrix-cell-med">보통</td><td style="padding:8px 12px;">계획적으로 개선</td></tr>' +
        '<tr><td style="text-align:center;font-weight:700;width:14%;">4~6</td><td style="text-align:center;font-weight:700;width:14%;" class="matrix-cell-low">낮음</td><td rowspan="2" class="criteria-acceptable"><span class="badge-acceptable">허용 가능</span></td><td style="padding:8px 12px;">필요에 따라 개선</td></tr>' +
        '<tr><td style="text-align:center;font-weight:700;width:14%;">1~3</td><td style="text-align:center;font-weight:700;width:14%;" class="matrix-cell-vlow">매우 낮음</td><td style="padding:8px 12px;"><span style="color:#64748b;">유지 및 관리 (현상태 유지)</span></td></tr>' +
        '</tbody>';
    wrap.append(table);
    return wrap;
}

document.querySelectorAll('textarea[data-preview]').forEach(function (field) {
    field.addEventListener('input', function () {
        var preview = document.getElementById(field.dataset.preview);
        if (!preview) return;
        var text = field.value;

        // If form
        if ((text.includes('교육일시') || text.includes('회의일시')) && text.includes('참석자 명단')) {
            var isEdu = text.includes('교육일시');
            var isTbm = text.includes('작업 전 안전점검회의');
            var dLabel = isEdu ? '교육일시' : '회의일시';
            var pLabel = isEdu ? '교육장소' : '회의장소';
            var cLabel = isEdu ? '□ 교육내용' : '□ 회의내용';
            var card = document.createElement('div'); card.className = 'doc-form-card';
            card.innerHTML = '<table class="form-meta-table"><tr><th>' + dLabel + '</th><td>20 &nbsp; 년 &nbsp; &nbsp; 월 &nbsp; &nbsp; 일 &nbsp; &nbsp; &nbsp; : &nbsp; ～ &nbsp; &nbsp; :</td><th>' + pLabel + '</th><td>' + (isEdu ? '(교육장)' : (isTbm ? '(현장)' : '(회의실)')) + '</td></tr></table>' +
                '<div class="form-content-block"><h4>' + cLabel + '</h4><ul><li>위험성평가 추진을 위한 항목 및 절차</li><li>유해위험요인 파악 및 감소대책 수립·실행</li></ul></div>' +
                '<div class="form-photo-frame">' + (isEdu ? '위험성평가 교육실시 사진 또는 교육자료 등' : (isTbm ? '작업 전 안전점검회의 사진 또는 회의자료 등' : '위험성평가 회의 사진 또는 회의자료 등')) + '</div>' +
                '<div class="form-roster-title">□ 참석자 명단</div>' +
                '<table class="form-roster-table"><thead><tr><th style="width:20%;">소속/직책</th><th style="width:18%;">성 &nbsp; &nbsp; 명</th><th style="width:12%;">서 명</th><th style="width:20%;">소속/직책</th><th style="width:18%;">성 &nbsp; &nbsp; 명</th><th style="width:12%;">서 명</th></tr></thead><tbody>' +
                '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>' +
                '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>' +
                '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>' +
                '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>' +
                '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>' +
                '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>' +
                '</tbody></table>';
            preview.replaceChildren(card);
            return;
        }

        var lines = text.split(/\r?\n/).map(function (line) { return line.trim(); });
        var nodes = [];
        var pGoals = [];
        for (var index = 0; index < lines.length; index++) {
            var line = lines[index];
            if (!line) continue;

            if (['산업재해 발생 제로', '위험성평가 우수사업장 인정', '상시적인 위험성평가 실시'].includes(line)) {
                pGoals.push(line);
                if (pGoals.length === 3) {
                    var gGrid = document.createElement('div'); gGrid.className = 'policy-goals-grid';
                    pGoals.forEach(function(g) {
                        var gc = document.createElement('div'); gc.className = 'policy-goal-card';
                        gc.textContent = '[ ' + g + ' ]';
                        gGrid.append(gc);
                    });
                    nodes.push(gGrid);
                }
                continue;
            }

            if (line.startsWith('[감소대책')) {
                var wBox = document.createElement('div'); wBox.className = 'ra-warning-box';
                var wT = document.createElement('div'); wT.className = 'ra-warning-title'; wT.textContent = line;
                wBox.append(wT);
                var ol = document.createElement('ol');
                index++;
                while (index < lines.length) {
                    var wl = lines[index];
                    if (!wl || /^[②-⑳]/u.test(wl) || wl.startsWith('제')) { index--; break; }
                    var li = document.createElement('li'); li.textContent = wl.replace(/^[0-9.]+\s*/, '');
                    ol.append(li);
                    index++;
                }
                wBox.append(ol); nodes.push(wBox); continue;
            }

            if (line.startsWith('|') && line.endsWith('|')) {
                var rows = [];
                while (index < lines.length && lines[index].startsWith('|') && lines[index].endsWith('|')) {
                    rows.push(lines[index].slice(1, -1).split('|').map(function (cell) { return cell.trim(); }));
                    index++;
                }
                index--;
                if (rows[0] && rows[0][0] === '조직' && rows[0][1] === '직위' && rows[0][2] === '성명') {
                    nodes.push(buildOrgChart(rows.slice(1))); continue;
                }
                if (rows[0] && rows[0].includes('최대(4)') && rows[0].includes('대(3)')) {
                    nodes.push(buildRiskMatrix(rows.slice(1))); continue;
                }
                if (rows[0] && (rows[0].includes('허용 가능 여부') || rows[0].includes('개선방법'))) {
                    nodes.push(buildActionCriteria()); continue;
                }
                var wrap = document.createElement('div'); wrap.className = 'manual-table-wrap';
                var table = document.createElement('table'); table.className = 'manual-table';
                if (rows[0].includes('가능성') && rows[0].includes('빈도')) table.classList.add('table-likelihood');
                else if (rows[0].includes('중대성') && rows[0].includes('점수')) table.classList.add('table-severity');
                else if (rows[0].includes('구분') && rows[0].includes('유해위험요인')) table.classList.add('table-4m');
                else if (rows[0].includes('조직') && rows[0].includes('역할과 책임(권한)')) table.classList.add('table-roles');

                var head = document.createElement('thead'); var headRow = document.createElement('tr');
                rows.shift().forEach(function (cell) { var th = document.createElement('th'); th.scope = 'col'; th.textContent = cell; headRow.append(th); });
                head.append(headRow); table.append(head);
                var body = document.createElement('tbody');
                rows.forEach(function (row) {
                    var tr = document.createElement('tr');
                    row.forEach(function (cell) {
                        var td = document.createElement('td'); var items = cell.split(';').map(function (item) { return item.trim(); }).filter(Boolean);
                        if (items.length > 1) { var list = document.createElement('ul'); items.forEach(function (item) { var li = document.createElement('li'); li.textContent = item; list.append(li); }); td.append(list); }
                        else td.textContent = cell;
                        tr.append(td);
                    });
                    body.append(tr);
                });
                table.append(body); wrap.append(table); nodes.push(wrap); continue;
            }
            var paragraph = document.createElement('p');
            paragraph.className = 'manual-line';
            if (/^[①-⑳]/u.test(line)) paragraph.classList.add('manual-clause');
            else if (/^(?:[0-9]+[.)]|[가-하][.]|[-◦●⦁])/u.test(line)) paragraph.classList.add('manual-item');
            else if (/^(?:<[^>]+>|\[[^]]+\]|《[^》]+》)/u.test(line)) paragraph.classList.add('manual-subheading');
            paragraph.textContent = line;
            nodes.push(paragraph);
        }
        preview.replaceChildren(...nodes);
    });
});
</script>
</body>
</html>
