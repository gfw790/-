<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';

$user = auth_current_user();
if (!is_array($user)) {
    header('Location: /risk_assessment/task_select.php');
    exit;
}

if (!auth_can_manage($user)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>권한이 없습니다</title>
        <style>
            body { margin: 0; padding: 40px 20px; background: #f5f7fb; color: #1f2937; font-family: "Malgun Gothic", sans-serif; }
            .panel { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #dbe2ea; border-radius: 20px; padding: 28px; }
            a { color: #0b4ea2; }
        </style>
    </head>
    <body>
        <div class="panel">
            <h1>권한이 없습니다</h1>
            <p>이 페이지는 관리자 권한이 필요한 메뉴입니다. 접근 권한이 필요하면 관리자에게 문의해 주세요.</p>
            <p><a href="/risk_assessment/work_list.php">작업 목록으로 돌아가기</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function safety_manual_pdf_autoload_path(): string
{
    return dirname(__DIR__) . '/risk_assessment/vendor/autoload.php';
}

function safety_manual_build_pdf_filename(?array $current = null): string
{
    $base = '중대재해등에관한매뉴얼';
    $updatedAt = trim((string)($current['updated_at'] ?? ''));
    if ($updatedAt !== '') {
        $timestamp = strtotime($updatedAt);
        if ($timestamp !== false) {
            return $base . '_' . date('Ymd_His', $timestamp) . '.pdf';
        }
    }

    return $base . '.pdf';
}

function safety_manual_build_pdf_html(string $title, string $contentHtml, ?array $current = null): string
{
    $updatedAt = trim((string)($current['updated_at'] ?? ''));
    $updatedBy = trim((string)($current['updated_by'] ?? ''));
    $metaParts = [];
    if ($updatedAt !== '') {
        $metaParts[] = '최종 반영일 ' . h($updatedAt);
    }
    if ($updatedBy !== '') {
        $metaParts[] = '작성자 ' . h($updatedBy);
    }
    $metaHtml = $metaParts !== [] ? '<div class="doc-meta">' . implode(' | ', $metaParts) . '</div>' : '';

    return '<!DOCTYPE html>'
        . '<html lang="ko">'
        . '<head>'
        . '<meta charset="UTF-8">'
        . '<title>' . h($title) . '</title>'
        . '<style>'
        . '@page { size: A4; margin: 20mm 16mm 22mm 16mm; }'
        . 'body { margin: 0; color: #1f2937; font-family: dejavusans, sans-serif; font-size: 11pt; line-height: 1.9; }'
        . '.document-shell { width: 100%; }'
        . '.doc-title { margin: 0 0 8mm; text-align: center; font-size: 20pt; font-weight: 700; color: #17315c; letter-spacing: -0.02em; }'
        . '.doc-meta { margin: 0 0 10mm; text-align: right; color: #5b6777; font-size: 9pt; }'
        . '.document { margin: 0; padding: 0; }'
        . '.document h2, .document h3, .document p, .document li, .document table, .document tr, .document td { page-break-inside: avoid; }'
        . '.document h2 { margin: 0 0 5mm; padding-bottom: 3mm; border-bottom: 0.5mm solid #d7e1ef; font-size: 16pt; color: #17315c; page-break-after: avoid; }'
        . '.document h2:not(:first-of-type) { page-break-before: always; margin-top: 0; }'
        . '.document h3 { margin: 7mm 0 3mm; font-size: 13pt; color: #17315c; page-break-after: avoid; }'
        . '.document p { margin: 0 0 3mm; }'
        . '.document p.bullet { padding-left: 4mm; color: #374151; }'
        . '.document p.related-basis { color: #475569; }'
        . '.document table { width: 100%; border-collapse: collapse; margin: 5mm 0; }'
        . '.document td, .document th { border: 0.3mm solid #d7e1ef; padding: 2.5mm 3mm; vertical-align: top; font-size: 10pt; }'
        . '.document a { color: #1f2937; text-decoration: none; }'
        . '</style>'
        . '</head>'
        . '<body>'
        . '<div class="document-shell">'
        . '<h1 class="doc-title">' . h($title) . '</h1>'
        . $metaHtml
        . '<div class="document">' . $contentHtml . '</div>'
        . '</div>'
        . '</body>'
        . '</html>';
}

function safety_manual_output_pdf(string $title, string $contentHtml, ?array $current = null): void
{
    $autoloadPath = safety_manual_pdf_autoload_path();
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('PDF 라이브러리를 찾을 수 없습니다.');
    }

    require_once $autoloadPath;

    $tempDir = dirname(__DIR__) . '/uploads/tmp';
    if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        throw new RuntimeException('PDF 임시 폴더를 생성하지 못했습니다.');
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_top' => 20,
        'margin_right' => 16,
        'margin_bottom' => 22,
        'margin_left' => 16,
        'tempDir' => $tempDir,
    ]);
    $mpdf->SetTitle($title);
    $mpdf->SetAuthor(trim((string)($current['updated_by'] ?? $current['uploaded_by'] ?? '관리자')));
    $mpdf->SetDisplayMode('fullpage');
    $mpdf->WriteHTML(safety_manual_build_pdf_html($title, $contentHtml, $current));
    $mpdf->Output(safety_manual_build_pdf_filename($current), \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

function safety_manual_storage_path(): string
{
    return __DIR__ . '/data.json';
}

function safety_manual_upload_root(): string
{
    return dirname(__DIR__) . '/uploads/safety_manual';
}

function safety_manual_load_data(): array
{
    $path = safety_manual_storage_path();
    if (!is_file($path)) {
        return [
            'current' => null,
        ];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [
            'current' => null,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'current' => null,
        ];
    }

    if (!array_key_exists('current', $decoded)) {
        $decoded['current'] = null;
    }

    return $decoded;
}

function safety_manual_save_data(array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('데이터를 JSON으로 인코딩하지 못했습니다.');
    }

    if (file_put_contents(safety_manual_storage_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('데이터 파일을 저장하지 못했습니다.');
    }
}

function safety_manual_flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['safety_manual_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
        return null;
    }

    $flash = $_SESSION['safety_manual_flash'] ?? null;
    unset($_SESSION['safety_manual_flash']);

    return is_array($flash) ? $flash : null;
}

function safety_manual_normalize_whitespace(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    return trim((string)$text);
}

function safety_manual_decode_preview_text(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }

    if (str_starts_with($bytes, "\xFF\xFE")) {
        $bytes = substr($bytes, 2);
        return safety_manual_normalize_whitespace((string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'));
    }

    if (str_starts_with($bytes, "\xFE\xFF")) {
        $bytes = substr($bytes, 2);
        return safety_manual_normalize_whitespace((string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE'));
    }

    $utf8 = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    if (is_string($utf8) && $utf8 !== '') {
        return safety_manual_normalize_whitespace($utf8);
    }

    return safety_manual_normalize_whitespace((string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'));
}

function safety_manual_detect_heading(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    if (preg_match('/^\s*\x{C81C}\s*\d+\s*\x{C7A5}\b/u', $line) === 1) {
        return ['tag' => 'h2', 'level' => 2];
    }

    if (preg_match('/^\s*\x{C81C}\s*\d+\s*\x{C870}(?:\s*\x{C758}\s*\d+)?\b/u', $line) === 1) {
        return ['tag' => 'h3', 'level' => 3];
    }

    $normalizedHeading = preg_replace('/\s+/u', '', $line);
    $genericHeadings = [
        '총칙',
        '안전보건경영방침',
        '안전보건경영방침및목표',
        '안전보건조직및인력',
        '안전보건관리조직',
        '유해위험요인확인및개선',
        '위험성평가',
        '안전보건예산',
        '안전보건예산편성및집행',
        '비상조치계획',
        '비상대응및사고조사',
        '도급용역위탁',
        '도급용역위탁시안전보건확보',
        '종사자의견청취',
        '안전보건교육',
        '부칙',
        '별지서식',
        '별표',
    ];
    if (mb_strlen($line, 'UTF-8') <= 24 && in_array($normalizedHeading, $genericHeadings, true)) {
        return ['tag' => 'h2', 'level' => 2];
    }

    return null;
}

function safety_manual_slugify(string $text, int $index): string
{
    $slug = preg_replace('/[^a-zA-Z0-9\x{AC00}-\x{D7A3}\-_]+/u', '-', trim($text));
    $slug = trim((string)$slug, '-');
    if ($slug === '') {
        $slug = 'section-' . $index;
    }
    return $slug . '-' . $index;
}

function safety_manual_law_api_oc(): string
{
    return 'riskserver_law';
}

function safety_manual_normalize_law_reference(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\u{300C}", "\u{300D}", '"', "'"], '', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function safety_manual_normalize_law_title(string $value): string
{
    $value = safety_manual_normalize_law_reference($value);
    $value = str_replace(["\u{318D}", "\u{00B7}", ' '], '', $value);
    return mb_strtolower($value, 'UTF-8');
}

function safety_manual_build_law_article_code(int $articleNo, int $articleSubNo = 0, bool $forApi = false): string
{
    if ($forApi) {
        return sprintf('%04d%02d', $articleNo, $articleSubNo);
    }

    return sprintf('%04d%02d000', $articleNo, $articleSubNo);
}

function safety_manual_build_law_article_label(int $articleNo, int $articleSubNo = 0, string $articleTitle = ''): string
{
    $label = '제' . $articleNo . '조';
    if ($articleSubNo > 0) {
        $label .= '의' . $articleSubNo;
    }
    if ($articleTitle !== '') {
        $label .= '(' . $articleTitle . ')';
    }

    return $label;
}

function safety_manual_parse_law_reference(string $query): ?array
{
    $query = safety_manual_normalize_law_reference($query);
    if ($query === '') {
        return null;
    }

    if (preg_match('/^(.+?)\s*\x{C81C}\s*(\d+)\s*\x{C870}(?:\s*\x{C758}\s*(\d+))?(?:\s*\x{C81C}\s*(\d+)\s*\x{D56D}(?:\s*\x{C81C}\s*(\d+)\s*\x{D638})?)?$/u', $query, $matches) === 1) {
        $lawName = safety_manual_normalize_law_reference((string)($matches[1] ?? ''));
        $articleNo = (int)($matches[2] ?? 0);
        $articleSubNo = (int)($matches[3] ?? 0);
        $paragraphNo = (int)($matches[4] ?? 0);
        $itemNo = (int)($matches[5] ?? 0);
        if ($lawName === '' || $articleNo <= 0) {
            return null;
        }

        return [
            'query' => $query,
            'law_name' => $lawName,
            'article_no' => $articleNo,
            'article_sub_no' => $articleSubNo,
            'paragraph_no' => $paragraphNo,
            'item_no' => $itemNo,
        ];
    }

    $lawName = $query;
    if ($lawName === '') {
        return null;
    }

    return [
        'query' => $query,
        'law_name' => $lawName,
        'article_no' => 0,
        'article_sub_no' => 0,
        'paragraph_no' => 0,
        'item_no' => 0,
    ];
}

function safety_manual_build_law_article_url(string $lawName, int $articleNo, int $articleSubNo = 0): string
{
    return 'https://www.law.go.kr/LSW/lsLinkProc.do?lsNm='
        . rawurlencode($lawName)
        . '&joNo='
        . rawurlencode(safety_manual_build_law_article_code($articleNo, $articleSubNo, false))
        . '&mode=10&lsClsCd=010101L';
}

function safety_manual_build_law_search_url(string $query): string
{
    $reference = safety_manual_parse_law_reference($query);
    if ($reference !== null && (int)($reference['article_no'] ?? 0) > 0) {
        return safety_manual_build_law_article_url(
            (string)$reference['law_name'],
            (int)$reference['article_no'],
            (int)$reference['article_sub_no']
        );
    }

    $query = safety_manual_normalize_law_reference($query);
    if ($query === '') {
        return 'https://www.law.go.kr/';
    }

    return 'https://www.law.go.kr/lsSc.do?menuId=1&query=' . rawurlencode($query) . '&subMenuId=15&tabMenuId=81';
}

function safety_manual_build_open_api_url(string $endpoint, array $params): string
{
    return 'https://www.law.go.kr/DRF/' . ltrim($endpoint, '/')
        . '?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function safety_manual_fetch_remote_html(string $url): string
{
    $headers = "User-Agent: Mozilla/5.0\r\nAccept-Language: ko-KR,ko;q=0.9,en;q=0.8\r\n";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0',
                'Accept-Language: ko-KR,ko;q=0.9,en;q=0.8',
            ],
        ]);
        $result = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($result) && $result !== '' && $statusCode >= 200 && $statusCode < 400) {
            return $result;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'header' => $headers,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    return is_string($result) ? $result : '';
}

function safety_manual_fetch_remote_json(string $url): array
{
    $raw = safety_manual_fetch_remote_html($url);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function safety_manual_value_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    if (function_exists('array_is_list')) {
        return array_is_list($value) ? $value : [$value];
    }

    $expected = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expected) {
            return [$value];
        }
        $expected++;
    }

    return $value;
}

function safety_manual_extract_content_value(mixed $value): string
{
    if (is_array($value)) {
        return trim((string)($value['content'] ?? ''));
    }

    return trim((string)$value);
}

function safety_manual_pick_law_search_result(array $items, string $lawName): ?array
{
    $normalizedLawName = safety_manual_normalize_law_title($lawName);

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $candidateName = safety_manual_normalize_law_title((string)($item['법령명한글'] ?? ''));
        $candidateAlias = safety_manual_normalize_law_title((string)($item['법령약칭명'] ?? ''));
        if ($candidateName === $normalizedLawName || $candidateAlias === $normalizedLawName) {
            return $item;
        }
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $candidateName = safety_manual_normalize_law_title((string)($item['법령명한글'] ?? ''));
        $candidateAlias = safety_manual_normalize_law_title((string)($item['법령약칭명'] ?? ''));
        if (
            ($candidateName !== '' && str_contains($candidateName, $normalizedLawName))
            || ($candidateAlias !== '' && str_contains($candidateAlias, $normalizedLawName))
        ) {
            return $item;
        }
    }

    return isset($items[0]) && is_array($items[0]) ? $items[0] : null;
}

function safety_manual_format_law_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches) === 1) {
        return (int)$matches[1] . '. ' . (int)$matches[2] . '. ' . (int)$matches[3] . '.';
    }

    return $value;
}

function safety_manual_collect_law_text_lines(mixed $node, array &$lines): void
{
    if (!is_array($node)) {
        return;
    }

    foreach (['조문내용', '항내용', '호내용', '목내용'] as $textKey) {
        $value = $node[$textKey] ?? '';
        if (is_array($value)) {
            $fragments = [];
            array_walk_recursive($value, static function ($part) use (&$fragments): void {
                if (is_scalar($part) || $part === null) {
                    $text = trim((string)$part);
                    if ($text !== '') {
                        $fragments[] = $text;
                    }
                }
            });
            $value = implode(' ', $fragments);
        }
        $value = safety_manual_normalize_whitespace(trim((string)$value));
        if ($value !== '' && !in_array($value, $lines, true)) {
            $lines[] = $value;
        }
    }

    foreach (['항', '호', '목'] as $childKey) {
        foreach (safety_manual_value_list($node[$childKey] ?? []) as $child) {
            safety_manual_collect_law_text_lines($child, $lines);
        }
    }
}

function safety_manual_pick_child_unit_by_number(array $units, string $numberKey, int $number): ?array
{
    foreach ($units as $unit) {
        if (!is_array($unit)) {
            continue;
        }

        $rawNumber = safety_manual_extract_content_value($unit[$numberKey] ?? '');
        if ($rawNumber === '') {
            continue;
        }

        if (preg_match('/\d+/', $rawNumber, $matches) !== 1) {
            continue;
        }

        if ((int)($matches[0] ?? 0) === $number) {
            return $unit;
        }
    }

    $fallbackIndex = $number - 1;
    return isset($units[$fallbackIndex]) && is_array($units[$fallbackIndex]) ? $units[$fallbackIndex] : null;
}

function safety_manual_strip_article_heading(string $line, int $articleNo, int $articleSubNo, string $articleTitle): string
{
    $patterns = [
        safety_manual_build_law_article_label($articleNo, $articleSubNo, $articleTitle),
        safety_manual_build_law_article_label($articleNo, $articleSubNo),
    ];

    foreach ($patterns as $pattern) {
        if ($pattern === '') {
            continue;
        }

        $quoted = preg_quote($pattern, '/');
        $line = preg_replace('/^\s*' . $quoted . '\s*/u', '', $line, 1) ?? $line;
    }

    return trim($line);
}

function safety_manual_pick_law_article_unit(array $articleUnits): ?array
{
    foreach ($articleUnits as $unit) {
        if (!is_array($unit)) {
            continue;
        }

        if (trim((string)($unit['조문내용'] ?? '')) !== '' || trim((string)($unit['조문여부'] ?? '')) === '조문') {
            return $unit;
        }
    }

    return isset($articleUnits[0]) && is_array($articleUnits[0]) ? $articleUnits[0] : null;
}

function safety_manual_collect_full_law_lines(array $articleUnits): array
{
    $lines = [];

    foreach ($articleUnits as $unit) {
        if (!is_array($unit)) {
            continue;
        }

        $unitLines = [];
        safety_manual_collect_law_text_lines($unit, $unitLines);
        foreach ($unitLines as $value) {
            $value = safety_manual_normalize_whitespace(trim((string)$value));
            if ($value === '' || in_array($value, $lines, true)) {
                continue;
            }

            $lines[] = $value;
        }
    }

    return $lines;
}

function safety_manual_build_law_api_payload(string $query): array
{
    $reference = safety_manual_parse_law_reference($query);
    if ($reference === null) {
        throw new InvalidArgumentException('법령명을 입력해 주세요. 조문번호를 함께 입력하면 해당 조문을, 법령명만 입력하면 전체 법령을 보여드립니다.');
    }

    $searchUrl = safety_manual_build_open_api_url('lawSearch.do', [
        'OC' => safety_manual_law_api_oc(),
        'target' => 'law',
        'type' => 'JSON',
        'query' => (string)$reference['law_name'],
    ]);
    $searchData = safety_manual_fetch_remote_json($searchUrl);
    $searchRoot = is_array($searchData['LawSearch'] ?? null) ? $searchData['LawSearch'] : [];
    $searchItems = safety_manual_value_list($searchRoot['law'] ?? []);
    $selectedLaw = safety_manual_pick_law_search_result($searchItems, (string)$reference['law_name']);
    if (!is_array($selectedLaw)) {
        throw new RuntimeException('법제처에서 해당 법령을 찾지 못했습니다.');
    }

    $lawId = trim((string)($selectedLaw['법령ID'] ?? $selectedLaw['id'] ?? ''));
    if ($lawId === '') {
        throw new RuntimeException('법령 ID를 확인하지 못했습니다.');
    }

    $articleNo = (int)($reference['article_no'] ?? 0);
    $articleSubNo = (int)($reference['article_sub_no'] ?? 0);
    $paragraphNo = (int)($reference['paragraph_no'] ?? 0);
    $itemNo = (int)($reference['item_no'] ?? 0);

    $detailParams = [
        'OC' => safety_manual_law_api_oc(),
        'target' => $articleNo > 0 ? 'lawjosub' : 'eflaw',
        'type' => 'JSON',
        'ID' => $lawId,
    ];
    if ($articleNo > 0) {
        $detailParams['JO'] = safety_manual_build_law_article_code($articleNo, $articleSubNo, true);
    }

    $detailUrl = safety_manual_build_open_api_url('lawService.do', $detailParams);
    $detailData = safety_manual_fetch_remote_json($detailUrl);
    $lawData = is_array($detailData['법령'] ?? null) ? $detailData['법령'] : [];
    if ($lawData === []) {
        throw new RuntimeException($articleNo > 0
            ? '선택한 조문의 상세 내용을 불러오지 못했습니다.'
            : '선택한 법령의 전체 내용을 불러오지 못했습니다.'
        );
    }

    $baseInfo = is_array($lawData['기본정보'] ?? null) ? $lawData['기본정보'] : [];
    $articleUnits = safety_manual_value_list($lawData['조문']['조문단위'] ?? []);

    $lawName = trim((string)($baseInfo['법령명_한글'] ?? $selectedLaw['법령명한글'] ?? $reference['law_name']));
    $ministry = safety_manual_extract_content_value($baseInfo['소관부처'] ?? '');
    $lawKind = safety_manual_extract_content_value($baseInfo['법종구분'] ?? '');
    $effectiveAt = safety_manual_format_law_date((string)($baseInfo['시행일자'] ?? ''));
    $promulgationAt = safety_manual_format_law_date((string)($baseInfo['공포일자'] ?? ''));
    $promulgationNo = ltrim(trim((string)($baseInfo['공포번호'] ?? '')), '0');
    $revisionType = trim((string)($baseInfo['제개정구분'] ?? ''));
    $contactPhone = trim((string)($baseInfo['전화번호'] ?? ''));

    if ($articleNo <= 0) {
        $bodyLines = safety_manual_collect_full_law_lines($articleUnits);
        if ($bodyLines === []) {
            throw new RuntimeException('법령 전체 본문을 찾지 못했습니다.');
        }

        return [
            'query' => (string)$reference['query'],
            'law_name' => $lawName,
            'article_label' => $lawName,
            'article_title' => '',
            'law_kind' => $lawKind,
            'ministry' => $ministry,
            'effective_at' => $effectiveAt,
            'promulgation_at' => $promulgationAt,
            'promulgation_no' => $promulgationNo,
            'revision_type' => $revisionType,
            'contact_phone' => $contactPhone,
            'body_lines' => $bodyLines,
            'open_url' => safety_manual_build_law_search_url((string)$reference['law_name']),
            'law_id' => $lawId,
            'is_full_law' => true,
        ];
    }

    $articleUnit = safety_manual_pick_law_article_unit($articleUnits);
    if (!is_array($articleUnit)) {
        throw new RuntimeException('선택한 조문을 찾지 못했습니다.');
    }

    $articleTitle = safety_manual_extract_content_value($articleUnit['조문제목'] ?? '');
    $articleLabel = safety_manual_build_law_article_label($articleNo, $articleSubNo, $articleTitle);
    $articleLines = [];
    safety_manual_collect_law_text_lines($articleUnit, $articleLines);

    if ($paragraphNo > 0) {
        $paragraphs = safety_manual_value_list($articleUnit['항'] ?? []);
        $paragraphUnit = safety_manual_pick_child_unit_by_number($paragraphs, '항번호', $paragraphNo);
        if (is_array($paragraphUnit)) {
            if ($itemNo > 0) {
                $items = safety_manual_value_list($paragraphUnit['호'] ?? []);
                $itemUnit = safety_manual_pick_child_unit_by_number($items, '호번호', $itemNo);
                if (is_array($itemUnit)) {
                    $itemLines = [];
                    safety_manual_collect_law_text_lines($itemUnit, $itemLines);
                    if ($itemLines !== []) {
                        $articleLines = $itemLines;
                    }
                }
            } else {
                $paragraphLines = [];
                safety_manual_collect_law_text_lines($paragraphUnit, $paragraphLines);
                if ($paragraphLines !== []) {
                    $articleLines = $paragraphLines;
                }
            }
        }
    }

    return [
        'query' => (string)$reference['query'],
        'law_name' => $lawName,
        'article_label' => $articleLabel,
        'article_title' => $articleTitle,
        'law_kind' => $lawKind,
        'ministry' => $ministry,
        'effective_at' => $effectiveAt,
        'promulgation_at' => $promulgationAt,
        'promulgation_no' => $promulgationNo,
        'revision_type' => $revisionType,
        'contact_phone' => $contactPhone,
        'body_lines' => $articleLines,
        'open_url' => safety_manual_build_law_article_url($lawName, $articleNo, $articleSubNo),
        'law_id' => $lawId,
        'is_full_law' => false,
    ];
}

if (($_GET['action'] ?? '') === 'law_api') {
    header('Content-Type: application/json; charset=UTF-8');
    $query = trim((string)($_GET['query'] ?? ''));

    try {
        if ($query === '') {
            throw new InvalidArgumentException('검색할 법령명 또는 조문을 입력해 주세요.');
        }

        $payload = safety_manual_build_law_api_payload($query);
        echo json_encode([
            'success' => true,
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

if (($_GET['action'] ?? '') === 'law_proxy') {
    $query = trim((string)($_GET['query'] ?? ''));
    if ($query === '') {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ko"><meta charset="UTF-8"><body style="font-family:Malgun Gothic,sans-serif;padding:24px;">검색어를 입력해 주세요.</body></html>';
        exit;
    }

    $remoteUrl = safety_manual_build_law_search_url($query);
    $html = safety_manual_fetch_remote_html($remoteUrl);
    if ($html === '') {
        http_response_code(502);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ko"><meta charset="UTF-8"><body style="font-family:Malgun Gothic,sans-serif;padding:24px;">법령 페이지를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</body></html>';
        exit;
    }

    if (stripos($html, '<head') !== false) {
        $html = preg_replace('/<head([^>]*)>/i', '<head$1><base href="https://www.law.go.kr/">', $html, 1) ?? $html;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

function safety_manual_build_html_from_text(string $text): array
{
    $lines = preg_split("/\n+/u", $text) ?: [];
    $html = [];
    $toc = [];
    $headingIndex = 0;

    foreach ($lines as $line) {
        $line = safety_manual_normalize_whitespace($line);
        if ($line === '') {
            continue;
        }

        $line = trim($line, " \t\n\r\0\x0B\x{FEFF}");
        if ($line === '') {
            continue;
        }

        $heading = safety_manual_detect_heading($line);
        if ($heading !== null) {
            $headingIndex++;
            $id = safety_manual_slugify($line, $headingIndex);
            $html[] = sprintf('<%1$s id="%2$s">%3$s</%1$s>', $heading['tag'], h($id), h($line));
            $toc[] = [
                'id' => $id,
                'title' => $line,
                'level' => $heading['level'],
            ];
            continue;
        }

        if (preg_match('/^[\-\*\x{2022}]\s+/u', $line) === 1) {
            $html[] = '<p class="bullet">' . h($line) . '</p>';
            continue;
        }

        $html[] = '<p>' . h($line) . '</p>';
    }

    return [
        'html' => implode("\n", $html),
        'toc' => $toc,
    ];
}

function safety_manual_extract_summary_from_plain_text(string $plainText, string $title = '중대재해 등에 관한 매뉴얼'): string
{
    $lines = preg_split("/\n+/u", $plainText) ?: [];
    foreach ($lines as $line) {
        $line = safety_manual_normalize_whitespace((string)$line);
        if ($line === '' || $line === $title) {
            continue;
        }

        return $line;
    }

    return '';
}

function safety_manual_dom_inner_html(DOMNode $node): string
{
    $owner = $node->ownerDocument;
    if (!$owner instanceof DOMDocument) {
        return '';
    }

    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $owner->saveHTML($child);
    }

    return $html;
}

function safety_manual_normalize_saved_content_html(string $contentHtml): array
{
    $wrapper = '<div id="safety-manual-root">' . $contentHtml . '</div>';
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML(
        '<?xml encoding="utf-8" ?>' . $wrapper,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
    );

    if ($loaded === false) {
        throw new RuntimeException('저장할 문서 HTML을 해석하지 못했습니다.');
    }

    $xpath = new DOMXPath($dom);
    $rootList = $xpath->query('//*[@id="safety-manual-root"]');
    if (!$rootList instanceof DOMNodeList || $rootList->length < 1) {
        throw new RuntimeException('문서 루트를 찾지 못했습니다.');
    }

    $root = $rootList->item(0);
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('문서 루트를 찾지 못했습니다.');
    }

    foreach (['script', 'style'] as $tag) {
        while (true) {
            $nodes = $root->getElementsByTagName($tag);
            if ($nodes->length < 1) {
                break;
            }

            $node = $nodes->item(0);
            if ($node instanceof DOMNode && $node->parentNode instanceof DOMNode) {
                $node->parentNode->removeChild($node);
                continue;
            }

            break;
        }
    }

    $toc = [];
    $headingIndex = 0;
    $headingNodes = $xpath->query('.//h2 | .//h3', $root);
    if ($headingNodes instanceof DOMNodeList) {
        foreach ($headingNodes as $headingNode) {
            if (!$headingNode instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($headingNode->tagName);
            $level = $tag === 'h2' ? 2 : 3;
            $title = safety_manual_normalize_whitespace($headingNode->textContent ?? '');
            if ($title === '') {
                continue;
            }

            $headingIndex++;
            $id = trim((string)$headingNode->getAttribute('id'));
            if ($id === '') {
                $id = safety_manual_slugify($title, $headingIndex);
                $headingNode->setAttribute('id', $id);
            }

            $toc[] = [
                'id' => $id,
                'title' => $title,
                'level' => $level,
            ];
        }
    }

    $plainLines = [];
    $lineNodes = $xpath->query('.//h2 | .//h3 | .//p | .//td', $root);
    if ($lineNodes instanceof DOMNodeList) {
        foreach ($lineNodes as $lineNode) {
            $line = safety_manual_normalize_whitespace($lineNode->textContent ?? '');
            if ($line !== '') {
                $plainLines[] = $line;
            }
        }
    }

    $plainText = trim(implode("\n", $plainLines));
    $normalizedHtml = trim(safety_manual_dom_inner_html($root));

    return [
        'content_html' => $normalizedHtml,
        'toc' => $toc,
        'plain_text' => $plainText,
    ];
}

function safety_manual_extract_table_html(DOMElement $tableNode): string
{
    $rows = [];
    foreach ($tableNode->childNodes as $rowNode) {
        if (!$rowNode instanceof DOMElement || $rowNode->localName !== 'tr') {
            continue;
        }

        $cells = [];
        foreach ($rowNode->childNodes as $cellNode) {
            if (!$cellNode instanceof DOMElement || $cellNode->localName !== 'tc') {
                continue;
            }

            $texts = [];
            $textNodes = $cellNode->getElementsByTagNameNS('*', 't');
            foreach ($textNodes as $textNode) {
                $value = safety_manual_normalize_whitespace($textNode->textContent);
                if ($value !== '') {
                    $texts[] = $value;
                }
            }

            $cellText = trim(implode(' ', $texts));
            $attrs = '';
            foreach ($cellNode->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->localName !== 'cellSpan') {
                    continue;
                }

                $colSpan = (int)$child->getAttribute('colSpan');
                $rowSpan = (int)$child->getAttribute('rowSpan');
                if ($colSpan > 1) {
                    $attrs .= ' colspan="' . $colSpan . '"';
                }
                if ($rowSpan > 1) {
                    $attrs .= ' rowspan="' . $rowSpan . '"';
                }
                break;
            }

            $cells[] = '<td' . $attrs . '>' . h($cellText) . '</td>';
        }

        if ($cells !== []) {
            $rows[] = '<tr>' . implode('', $cells) . '</tr>';
        }
    }

    if ($rows === []) {
        return '';
    }

    return '<div class="rule-table-wrap"><table class="rule-table"><tbody>' . implode('', $rows) . '</tbody></table></div>';
}

function safety_manual_extract_from_sections(ZipArchive $zip): array
{
    $xmlParts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('#^Contents/section\d+\.xml$#', $name) === 1) {
            $xmlParts[] = $name;
        }
    }

    sort($xmlParts, SORT_NATURAL);

    $html = [];
    $toc = [];
    $headingIndex = 0;

    foreach ($xmlParts as $partName) {
        $xml = $zip->getFromName($partName);
        if (!is_string($xml) || trim($xml) === '') {
            continue;
        }

        $dom = new DOMDocument();
        if (@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
            continue;
        }

        $paragraphs = $dom->getElementsByTagNameNS('*', 'p');
        foreach ($paragraphs as $paragraph) {
            if (!$paragraph instanceof DOMElement) {
                continue;
            }

            $tables = $paragraph->getElementsByTagNameNS('*', 'tbl');
            if ($tables->length > 0) {
                foreach ($tables as $tableNode) {
                    if ($tableNode instanceof DOMElement) {
                        $tableHtml = safety_manual_extract_table_html($tableNode);
                        if ($tableHtml !== '') {
                            $html[] = $tableHtml;
                        }
                    }
                }
                continue;
            }

            $texts = [];
            $textNodes = $paragraph->getElementsByTagNameNS('*', 't');
            foreach ($textNodes as $textNode) {
                $value = safety_manual_normalize_whitespace($textNode->textContent);
                if ($value !== '') {
                    $texts[] = $value;
                }
            }

            $line = safety_manual_normalize_whitespace(implode(' ', $texts));
            if ($line === '') {
                continue;
            }

            $heading = safety_manual_detect_heading($line);
            if ($heading !== null) {
                $headingIndex++;
                $id = safety_manual_slugify($line, $headingIndex);
                $html[] = sprintf('<%1$s id="%2$s">%3$s</%1$s>', $heading['tag'], h($id), h($line));
                $toc[] = [
                    'id' => $id,
                    'title' => $line,
                    'level' => $heading['level'],
                ];
                continue;
            }

            $html[] = '<p>' . h($line) . '</p>';
        }
    }

    return [
        'html' => implode("\n", $html),
        'toc' => $toc,
    ];
}

function safety_manual_parse_hwpx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('HWPX 파일을 열 수 없습니다.');
    }

    try {
        $previewText = '';
        $previewBytes = $zip->getFromName('Preview/PrvText.txt');
        if (is_string($previewBytes) && $previewBytes !== '') {
            $previewText = safety_manual_decode_preview_text($previewBytes);
        }

        $previewBuilt = ['html' => '', 'toc' => []];
        if ($previewText !== '') {
            $previewBuilt = safety_manual_build_html_from_text($previewText);
        }

        $sectionBuilt = safety_manual_extract_from_sections($zip);
        $previewPlainText = trim(strip_tags(str_replace(['</p>', '</h2>', '</h3>'], ["\n", "\n", "\n"], $previewBuilt['html'])));
        $sectionPlainText = trim(strip_tags(str_replace(['</p>', '</h2>', '</h3>'], ["\n", "\n", "\n"], $sectionBuilt['html'])));

        $built = $previewBuilt;
        if ($sectionPlainText !== '') {
            $previewLength = mb_strlen($previewPlainText, 'UTF-8');
            $sectionLength = mb_strlen($sectionPlainText, 'UTF-8');

            if ($sectionLength > $previewLength) {
                $built = $sectionBuilt;
            }
        } elseif ($previewPlainText === '') {
            $built = $sectionBuilt;
        }

        $plainText = trim(strip_tags(str_replace(['</p>', '</h2>', '</h3>'], ["\n", "\n", "\n"], $built['html'])));
        if ($plainText === '') {
            throw new RuntimeException('문서에서 추출 가능한 본문이 없습니다. HWPX 파일 내용을 확인해 주세요.');
        }

        $lines = preg_split("/\n+/u", $plainText) ?: [];
        $title = '중대재해 등에 관한 매뉴얼';

        $summary = '';
        foreach ($lines as $line) {
            $line = safety_manual_normalize_whitespace($line);
            if ($line === '' || $line === $title) {
                continue;
            }
            $summary = $line;
            break;
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'content_html' => $built['html'],
            'toc' => $built['toc'],
            'plain_text' => $plainText,
        ];
    } finally {
        $zip->close();
    }
}

function safety_manual_handle_upload(array $user): void
{
    $file = $_FILES['draft_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('업로드할 HWPX 파일을 선택해 주세요.');
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'hwpx') {
        throw new RuntimeException('HWPX 확장자 파일만 업로드할 수 있습니다.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('업로드된 임시 파일을 확인하지 못했습니다.');
    }

    $uploadRoot = safety_manual_upload_root();
    $relativeDir = date('Y/m');
    $targetDir = $uploadRoot . '/' . $relativeDir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('업로드 폴더를 생성하지 못했습니다.');
    }

    $safeBaseName = preg_replace('/[^a-zA-Z0-9\x{AC00}-\x{D7A3}_\-]/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBaseName = trim((string)$safeBaseName, '_');
    if ($safeBaseName === '') {
        $safeBaseName = 'safety_manual';
    }

    $storedName = sprintf('%s_%s_%s.hwpx', $safeBaseName, date('Ymd_His'), substr(bin2hex(random_bytes(4)), 0, 8));
    $targetPath = $targetDir . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('업로드 파일을 저장하지 못했습니다.');
    }

    $parsed = safety_manual_parse_hwpx($targetPath);
    $now = date('Y-m-d H:i:s');

    safety_manual_save_data([
        'current' => [
            'title' => $parsed['title'],
            'summary' => $parsed['summary'],
            'content_html' => $parsed['content_html'],
            'toc' => $parsed['toc'],
            'plain_text' => $parsed['plain_text'],
            'source_name' => $originalName,
            'source_path' => '/uploads/safety_manual/' . $relativeDir . '/' . $storedName,
            'uploaded_at' => $now,
            'uploaded_by' => trim((string)($user['name'] ?? $user['login_id'] ?? '관리자')),
        ],
    ]);
}

function safety_manual_handle_save_edits(array $user): void
{
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'POST 요청만 허용됩니다.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('저장 요청 데이터를 해석하지 못했습니다.');
        }

        $contentHtml = trim((string)($payload['content_html'] ?? ''));
        if ($contentHtml === '') {
            throw new InvalidArgumentException('저장할 본문이 없습니다.');
        }
        if (mb_strlen($contentHtml, '8bit') > 2_000_000) {
            throw new InvalidArgumentException('문서가 너무 큽니다. 본문 크기를 줄인 후 다시 시도해 주세요.');
        }

        $data = safety_manual_load_data();
        $current = is_array($data['current'] ?? null) ? $data['current'] : null;
        if (!is_array($current)) {
            $current = [
                'title' => '중대재해 등에 관한 매뉴얼',
                'summary' => '',
                'content_html' => '',
                'toc' => [],
                'plain_text' => '',
            ];
        }

        $normalized = safety_manual_normalize_saved_content_html($contentHtml);
        $now = date('Y-m-d H:i:s');
        $title = trim((string)($current['title'] ?? '중대재해 등에 관한 매뉴얼'));

        $current['content_html'] = (string)$normalized['content_html'];
        $current['toc'] = is_array($normalized['toc'] ?? null) ? $normalized['toc'] : [];
        $current['plain_text'] = (string)($normalized['plain_text'] ?? '');
        $current['summary'] = safety_manual_extract_summary_from_plain_text($current['plain_text'], $title);
        $current['updated_at'] = $now;
        $current['updated_by'] = trim((string)($user['name'] ?? $user['login_id'] ?? '관리자'));

        $data['current'] = $current;
        safety_manual_save_data($data);

        echo json_encode([
            'success' => true,
            'updated_at' => $now,
            'summary' => $current['summary'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

if (($_GET['action'] ?? '') === 'save_edits') {
    safety_manual_handle_save_edits($user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        safety_manual_handle_upload($user);
        safety_manual_flash('success', 'HWPX 파일을 업로드하여 중대재해 매뉴얼을 반영했습니다.');
    } catch (Throwable $e) {
        safety_manual_flash('error', $e->getMessage());
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$data = safety_manual_load_data();
$current = is_array($data['current'] ?? null) ? $data['current'] : null;
$flash = safety_manual_flash();
$pageTitle = '중대재해 등에 관한 매뉴얼';
$summary = trim((string)($current['summary'] ?? ''));
$toc = is_array($current['toc'] ?? null) ? $current['toc'] : [];
$renderedContentHtml = (string)($current['content_html'] ?? '');
$tocGroups = [];
$appendixGroups = [];
$annexGroups = [];
$currentGroupIndex = -1;
$currentCollection = 'chapters';

foreach ($toc as $item) {
    $level = (int)($item['level'] ?? 0);
    $title = trim((string)($item['title'] ?? ''));
    $id = trim((string)($item['id'] ?? ''));
    if ($title === '' || $id === '') {
        continue;
    }

    $isAppendixHeading = preg_match('/^부칙(?:\s|\(|$)/u', $title) === 1;
    $isAnnexHeading = preg_match('/^별지(?:\s*서식)?(?:\s|\(|$)|^별표(?:\s|\(|$)/u', $title) === 1;
    if ($level === 2 || $currentGroupIndex < 0) {
        if ($isAppendixHeading) {
            $appendixGroups[] = [
                'heading' => [
                    'id' => $id,
                    'title' => $title,
                    'level' => $level > 0 ? $level : 2,
                ],
                'items' => [],
            ];
            $currentCollection = 'appendix';
            $currentGroupIndex = count($appendixGroups) - 1;
            continue;
        }
        if ($isAnnexHeading) {
            $annexGroups[] = [
                'heading' => [
                    'id' => $id,
                    'title' => $title,
                    'level' => $level > 0 ? $level : 2,
                ],
                'items' => [],
            ];
            $currentCollection = 'annex';
            $currentGroupIndex = count($annexGroups) - 1;
            continue;
        }

        $tocGroups[] = [
            'heading' => [
                'id' => $id,
                'title' => $title,
                'level' => $level > 0 ? $level : 2,
            ],
            'items' => [],
        ];
        $currentCollection = 'chapters';
        $currentGroupIndex = count($tocGroups) - 1;
        continue;
    }

    if ($currentCollection === 'appendix') {
        $appendixGroups[$currentGroupIndex]['items'][] = [
            'id' => $id,
            'title' => $title,
            'level' => $level,
        ];
        continue;
    }
    if ($currentCollection === 'annex') {
        $annexGroups[$currentGroupIndex]['items'][] = [
            'id' => $id,
            'title' => $title,
            'level' => $level,
        ];
        continue;
    }

    $tocGroups[$currentGroupIndex]['items'][] = [
        'id' => $id,
        'title' => $title,
        'level' => $level,
    ];
}

if (($_GET['action'] ?? '') === 'download_pdf') {
    if (!$current || trim((string)($current['content_html'] ?? '')) === '') {
        http_response_code(404);
        echo 'PDF로 다운로드할 매뉴얼 문서가 없습니다.';
        exit;
    }

    safety_manual_output_pdf($pageTitle, $renderedContentHtml, $current);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <style>
        :root {
            --law-blue: #1e4f95;
            --law-deep-blue: #12386f;
            --law-line: #d7e1ef;
            --law-bg: #f4f7fb;
            --law-card: #ffffff;
            --law-text: #1f2937;
            --law-muted: #5b6777;
            --law-accent: #0b5bd3;
            --law-heading: #17315c;
            --law-gold: #b08a3c;
            --header-sticky-offset: 104px;
            --panel-sticky-gap: 8px;
            --document-print-margin-top: 20mm;
            --document-print-margin-right: 16mm;
            --document-print-margin-bottom: 22mm;
            --document-print-margin-left: 16mm;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--law-bg);
            color: var(--law-text);
            font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
            font-size: 14.5px;
            line-height: 1.7;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid var(--law-line);
            backdrop-filter: blur(10px);
        }

        .topbar-inner {
            max-width: 1720px;
            margin: 0 auto;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #d9383a 0%, #b82224 100%);
            display: grid;
            place-items: center;
            color: #fff;
            font-weight: 800;
            font-size: 18px;
            box-shadow: 0 4px 10px rgba(217, 56, 58, 0.25);
        }

        .brand-copy small {
            display: block;
            color: var(--law-muted);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .brand-copy strong {
            font-size: 19px;
            color: var(--law-heading);
            letter-spacing: -0.02em;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .content-header-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 36px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid #1e4f95;
            background: #1e4f95;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .content-header-button:hover {
            background: #153c75;
            border-color: #153c75;
            color: #ffffff;
        }

        .content-header-button-secondary {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #334155;
        }

        .content-header-button-secondary:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }

        .user-chip {
            padding: 6px 12px;
            background: #eef3f9;
            border-radius: 20px;
            font-size: 12.5px;
            color: var(--law-deep-blue);
            font-weight: 600;
        }

        .flash-msg {
            max-width: 1720px;
            margin: 12px auto 0;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
        }

        .flash-msg.success { background: #e6f7ec; color: #0a6932; border: 1px solid #bce6c9; }
        .flash-msg.error { background: #fde8e8; color: #9b1c1c; border: 1px solid #f8b4b4; }

        .layout {
            max-width: 1720px;
            margin: 16px auto 40px;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 310px minmax(0, 1fr) 390px;
            gap: 20px;
            align-items: start;
        }

        .sidebar {
            background: var(--law-card);
            border: 1px solid var(--law-line);
            border-radius: 14px;
            padding: 18px 16px;
            position: sticky;
            top: 68px;
            max-height: calc(100vh - 84px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .sidebar-scroll {
            overflow-y: auto;
            padding-right: 4px;
        }

        .toc-search-sticky {
            margin-bottom: 12px;
        }

        .toc-search-label {
            display: block;
            font-size: 11.5px;
            color: var(--law-muted);
            margin-bottom: 4px;
            font-weight: 600;
        }

        .toc-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--law-line);
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            transition: border-color 0.15s;
        }

        .toc-search-input:focus {
            border-color: var(--law-accent);
        }

        .toc-header h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: var(--law-heading);
            font-weight: 700;
        }

        .toc-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .toc-group {
            border: 1px solid #edf2f7;
            border-radius: 8px;
            background: #fafcff;
            overflow: hidden;
        }

        .toc-group-toggle {
            width: 100%;
            padding: 9px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: transparent;
            border: none;
            font-size: 13px;
            font-weight: 700;
            color: var(--law-deep-blue);
            cursor: pointer;
            text-align: left;
        }

        .toc-group-toggle:hover {
            background: #edf4fe;
        }

        .toc-group-toggle .chevron {
            font-size: 11px;
            transition: transform 0.2s;
        }

        .toc-group.is-collapsed .toc-group-toggle .chevron {
            transform: rotate(-90deg);
        }

        .toc-group.is-collapsed .toc-group-items {
            display: none;
        }

        .toc-group-items {
            list-style: none;
            margin: 0;
            padding: 4px 8px 8px 8px;
            border-top: 1px solid #edf2f7;
            background: #ffffff;
        }

        .toc-group-items li a {
            display: block;
            padding: 5px 8px;
            border-radius: 5px;
            color: #374151;
            text-decoration: none;
            font-size: 12.5px;
            line-height: 1.4;
            transition: all 0.15s;
        }

        .toc-group-items li a:hover,
        .toc-group-items li a.is-active {
            background: #eef4fc;
            color: var(--law-accent);
            font-weight: 600;
        }

        .toc-group-items li a.level-2 {
            font-weight: 700;
            color: var(--law-heading);
            margin-bottom: 2px;
        }

        .content-card {
            background: var(--law-card);
            border: 1px solid var(--law-line);
            border-radius: 14px;
            padding: 36px 40px;
            min-height: 600px;
        }

        .content-header {
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--law-deep-blue);
        }

        .content-header .badge {
            display: inline-block;
            padding: 3px 10px;
            background: #fee2e2;
            color: #b91c1c;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .content-header h1 {
            margin: 0;
            font-size: 26px;
            color: var(--law-heading);
            letter-spacing: -0.02em;
        }

        .document h2 {
            margin: 36px 0 16px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid var(--law-line);
            font-size: 19px;
            color: var(--law-heading);
            scroll-margin-top: 80px;
        }

        .document h2:first-of-type {
            margin-top: 0;
        }

        .document h3 {
            margin: 22px 0 8px;
            font-size: 15.5px;
            color: var(--law-deep-blue);
            scroll-margin-top: 80px;
            cursor: pointer;
            padding: 4px 6px;
            margin-left: -6px;
            border-radius: 6px;
            transition: background 0.15s;
        }

        .document h3:hover {
            background: #edf4fe;
        }

        .document p {
            margin: 0 0 10px;
            color: #374151;
            font-size: 14px;
            line-height: 1.8;
        }

        .document p.bullet {
            padding-left: 14px;
            position: relative;
        }

        .document p.related-basis {
            margin: 8px 0 16px;
            padding: 6px 12px;
            background: #f8fafc;
            border-left: 3px solid var(--law-gold);
            font-size: 12.5px;
            color: #64748b;
            border-radius: 0 6px 6px 0;
        }

        .law-ref-link {
            color: #1e40af;
            text-decoration: underline;
            text-underline-offset: 3px;
            cursor: pointer;
            font-weight: 600;
            padding: 1px 3px;
            border-radius: 3px;
        }

        .law-ref-link:hover {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .rule-table-wrap {
            margin: 16px 0;
            overflow-x: auto;
        }

        .rule-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .rule-table td, .rule-table th {
            border: 1px solid var(--law-line);
            padding: 8px 12px;
            text-align: left;
        }

        .law-panel {
            background: var(--law-card);
            border: 1px solid var(--law-line);
            border-radius: 14px;
            position: sticky;
            top: 68px;
            max-height: calc(100vh - 84px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .law-panel-head {
            padding: 16px;
            border-bottom: 1px solid var(--law-line);
            background: #fafcff;
        }

        .law-panel-head h2 {
            margin: 0 0 4px;
            font-size: 15px;
            color: var(--law-heading);
            font-weight: 700;
        }

        .law-panel-head p {
            margin: 0 0 10px;
            font-size: 11.5px;
            color: var(--law-muted);
        }

        .law-panel-search {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }

        .law-panel-search-input {
            flex: 1;
            padding: 6px 10px;
            border: 1px solid var(--law-line);
            border-radius: 6px;
            font-size: 12.5px;
            outline: none;
        }

        .law-panel-search-input:focus {
            border-color: var(--law-accent);
        }

        .law-panel-search-button {
            padding: 0 10px;
            border: 1px solid var(--law-accent);
            background: var(--law-accent);
            color: #fff;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .law-panel-query {
            font-size: 12px;
            font-weight: 600;
            color: var(--law-deep-blue);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .law-panel-link {
            font-size: 11.5px;
            color: #0b5bd3;
            text-decoration: underline;
        }

        .law-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            font-size: 13px;
            line-height: 1.6;
        }

        .law-panel-placeholder {
            text-align: center;
            color: var(--law-muted);
            padding: 40px 10px;
        }

        .law-panel-placeholder strong {
            display: block;
            margin-bottom: 6px;
            color: #475569;
        }

        .law-article-meta {
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #edf2f7;
            font-size: 12px;
            color: #64748b;
        }

        .law-article-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--law-heading);
            margin-bottom: 10px;
        }

        .law-article-body {
            white-space: pre-line;
            color: #1f2937;
        }

        /* Modal Styles */
        .rule-edit-modal, .print-preview-modal, .upload-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: none;
            place-items: center;
            z-index: 100;
            padding: 20px;
        }

        .rule-edit-modal.is-open, .print-preview-modal.is-open, .upload-modal.is-open {
            display: grid;
        }

        .rule-edit-dialog, .upload-dialog {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 640px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .rule-edit-head, .upload-head, .print-preview-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--law-line);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .rule-edit-head h3, .upload-head h3, .print-preview-head h3 {
            margin: 0;
            font-size: 16px;
            color: var(--law-heading);
            font-weight: 700;
        }

        .rule-edit-close, .upload-close {
            background: none;
            border: none;
            font-size: 22px;
            color: #94a3b8;
            cursor: pointer;
        }

        .rule-edit-body, .upload-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .rule-edit-group label, .upload-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .rule-edit-textarea, .rule-edit-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--law-line);
            border-radius: 8px;
            font-size: 13.5px;
            font-family: inherit;
            line-height: 1.6;
            outline: none;
        }

        .rule-edit-textarea {
            min-height: 180px;
            resize: vertical;
        }

        .rule-edit-textarea.secondary {
            min-height: 60px;
        }

        .rule-edit-foot, .upload-foot {
            padding: 14px 20px;
            background: #f8fafc;
            border-top: 1px solid var(--law-line);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .rule-edit-btn, .upload-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--law-line);
            background: #fff;
            color: #334155;
        }

        .rule-edit-btn.primary, .upload-btn.primary {
            background: var(--law-accent);
            border-color: var(--law-accent);
            color: #fff;
        }

        .rule-edit-btn.danger {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c;
            margin-right: auto;
        }

        .upload-dropzone {
            border: 2px dashed #94a3b8;
            border-radius: 10px;
            padding: 28px 16px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s;
        }

        .upload-dropzone:hover {
            border-color: var(--law-accent);
            background: #edf4fe;
        }

        .upload-dropzone-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .upload-dropzone-text {
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
        }

        .upload-dropzone-sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Print Preview Modal */
        .print-preview-dialog {
            background: #e2e8f0;
            border-radius: 14px;
            width: 100%;
            max-width: 960px;
            max-height: calc(100vh - 40px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .print-preview-head {
            background: #ffffff;
        }

        .print-preview-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .print-preview-paper {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .print-preview-page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 16mm 22mm 16mm;
            background: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            color: #1f2937;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--law-muted);
        }

        .empty-state strong {
            display: block;
            font-size: 17px;
            margin-bottom: 8px;
            color: #334155;
        }

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 24px;
        }

        @media (max-width: 1280px) {
            .layout {
                grid-template-columns: 280px minmax(0, 1fr);
            }
            .law-panel {
                grid-column: 1 / -1;
                position: static;
                max-height: none;
            }
        }

        @media (max-width: 860px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                position: static;
                max-height: none;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true">안</div>
                <div class="brand-copy">
                    <small>SAFETY MANAGEMENT MANUAL</small>
                    <strong>중대재해 등에 관한 매뉴얼</strong>
                </div>
            </div>
            <div class="topbar-actions">
                <button type="button" class="content-header-button" id="rule-add-article">+ 새 조항 추가</button>
                <button type="button" class="content-header-button content-header-button-secondary" id="manual-open-upload">📁 HWPX 업로드</button>
                <button type="button" class="content-header-button" id="rule-download-pdf">🖨 출력 / PDF</button>
                <a class="content-header-button content-header-button-secondary" href="/risk_assessment/work_list.php">메인으로 돌아가기</a>
                <div class="user-chip"><?= h(trim((string)($user['name'] ?? $user['login_id'] ?? '관리자'))) ?> 님</div>
            </div>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash-msg <?= h($flash['type'] ?? 'info') ?>">
            <?= h($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <main class="layout">
        <aside class="sidebar">
            <div class="sidebar-scroll" id="sidebar-scroll-area">
                <?php if ($tocGroups !== [] || $appendixGroups !== [] || $annexGroups !== []): ?>
                    <div class="toc-search-sticky">
                        <label for="toc-keyword-search" class="toc-search-label">키워드 검색</label>
                        <input type="search" id="toc-keyword-search" class="toc-search-input" placeholder="조문 제목으로 검색" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="toc">
                        <div class="toc-header">
                            <h3>목차</h3>
                        </div>
                        <div class="toc-list" id="toc-list">
                            <?php foreach ($tocGroups as $groupIndex => $group): ?>
                                <?php
                                $heading = $group['heading'];
                                $items = $group['items'];
                                $headingId = trim((string)($heading['id'] ?? ''));
                                $headingTitle = trim((string)($heading['title'] ?? ''));
                                if ($headingId === '' || $headingTitle === '') {
                                    continue;
                                }
                                ?>
                                <div class="toc-group is-collapsed" data-toc-group>
                                    <button type="button" class="toc-group-toggle" data-toc-toggle aria-expanded="false" aria-controls="toc-group-items-<?= $groupIndex ?>">
                                        <span><?= h($headingTitle) ?></span>
                                        <span class="chevron">&#9662;</span>
                                    </button>
                                    <ul class="toc-group-items" id="toc-group-items-<?= $groupIndex ?>">
                                        <li><a class="level-2" href="#<?= h($headingId) ?>"><?= h($headingTitle) ?></a></li>
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                            $anchorId = trim((string)($item['id'] ?? ''));
                                            $anchorTitle = trim((string)($item['title'] ?? ''));
                                            $levelClass = 'level-' . (int)($item['level'] ?? 3);
                                            if ($anchorId === '' || $anchorTitle === '') {
                                                continue;
                                            }
                                            ?>
                                            <li><a class="<?= h($levelClass) ?>" href="#<?= h($anchorId) ?>"><?= h($anchorTitle) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($appendixGroups !== []): ?>
                                <div class="toc-header" style="margin-top:16px;">
                                    <h3>부칙</h3>
                                </div>
                                <?php foreach ($appendixGroups as $groupIndex => $group): ?>
                                    <?php
                                    $heading = $group['heading'];
                                    $items = $group['items'];
                                    $headingId = trim((string)($heading['id'] ?? ''));
                                    $headingTitle = trim((string)($heading['title'] ?? ''));
                                    if ($headingId === '' || $headingTitle === '') {
                                        continue;
                                    }
                                    $appendixDomId = 'toc-appendix-items-' . $groupIndex;
                                    ?>
                                    <div class="toc-group is-collapsed" data-toc-group>
                                        <button type="button" class="toc-group-toggle" data-toc-toggle aria-expanded="false" aria-controls="<?= h($appendixDomId) ?>">
                                            <span><?= h($headingTitle) ?></span>
                                            <span class="chevron">&#9662;</span>
                                        </button>
                                        <ul class="toc-group-items" id="<?= h($appendixDomId) ?>">
                                            <li><a class="level-2" href="#<?= h($headingId) ?>"><?= h($headingTitle) ?></a></li>
                                            <?php foreach ($items as $item): ?>
                                                <?php
                                                $anchorId = trim((string)($item['id'] ?? ''));
                                                $anchorTitle = trim((string)($item['title'] ?? ''));
                                                $levelClass = 'level-' . (int)($item['level'] ?? 3);
                                                if ($anchorId === '' || $anchorTitle === '') {
                                                    continue;
                                                }
                                                ?>
                                                <li><a class="<?= h($levelClass) ?>" href="#<?= h($anchorId) ?>"><?= h($anchorTitle) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($annexGroups !== []): ?>
                                <div class="toc-header" style="margin-top:16px;">
                                    <h3>별지 서식 및 별표</h3>
                                </div>
                                <?php foreach ($annexGroups as $groupIndex => $group): ?>
                                    <?php
                                    $heading = $group['heading'];
                                    $items = $group['items'];
                                    $headingId = trim((string)($heading['id'] ?? ''));
                                    $headingTitle = trim((string)($heading['title'] ?? ''));
                                    if ($headingId === '' || $headingTitle === '') {
                                        continue;
                                    }
                                    $annexDomId = 'toc-annex-items-' . $groupIndex;
                                    ?>
                                    <div class="toc-group is-collapsed" data-toc-group>
                                        <button type="button" class="toc-group-toggle" data-toc-toggle aria-expanded="false" aria-controls="<?= h($annexDomId) ?>">
                                            <span><?= h($headingTitle) ?></span>
                                            <span class="chevron">&#9662;</span>
                                        </button>
                                        <ul class="toc-group-items" id="<?= h($annexDomId) ?>">
                                            <li><a class="level-2" href="#<?= h($headingId) ?>"><?= h($headingTitle) ?></a></li>
                                            <?php foreach ($items as $item): ?>
                                                <?php
                                                $anchorId = trim((string)($item['id'] ?? ''));
                                                $anchorTitle = trim((string)($item['title'] ?? ''));
                                                $levelClass = 'level-' . (int)($item['level'] ?? 3);
                                                if ($anchorId === '' || $anchorTitle === '') {
                                                    continue;
                                                }
                                                ?>
                                                <li><a class="<?= h($levelClass) ?>" href="#<?= h($anchorId) ?>"><?= h($anchorTitle) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <section class="main">
            <article class="content-card">
                <?php if ($current && trim($renderedContentHtml) !== ''): ?>
                    <div class="content-header">
                        <span class="badge">최신 반영본</span>
                        <h1><?= h($pageTitle) ?></h1>
                    </div>
                    <div class="document">
                        <?= $renderedContentHtml ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <strong>아직 반영된 매뉴얼 내용이 없습니다.</strong>
                        <div>상단의 [📁 HWPX 업로드] 버튼 또는 [새 조항 추가] 버튼을 눌러 매뉴얼을 등록해 주세요.</div>
                    </div>
                <?php endif; ?>
            </article>
        </section>

        <aside class="law-panel">
            <div class="law-panel-head">
                <h2>관련 법조문</h2>
                <p>본문의 법령 링크를 누르면 선택한 조문을 이 영역에서 바로 확인할 수 있습니다.</p>
                <div class="law-panel-meta">
                    <form class="law-panel-search" id="law-panel-search-form" action="#" method="get" novalidate>
                        <input
                            class="law-panel-search-input"
                            id="law-panel-search-input"
                            type="search"
                            placeholder="예: 중대재해처벌법 / 중대재해처벌법 제4조"
                            aria-label="관련 법조문 검색"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <button class="law-panel-search-button" type="submit">검색</button>
                    </form>
                    <div class="law-panel-query" id="law-panel-query">아직 선택한 법령이 없습니다.</div>
                    <a class="law-panel-link" id="law-panel-open-link" href="https://www.law.go.kr/" target="_blank" rel="noopener">법제처 원문 열기</a>
                </div>
            </div>
            <div class="law-panel-body">
                <div class="law-panel-content law-panel-placeholder" id="law-panel-content">
                    <div>
                        <strong>관련 법조문 보기</strong>
                        <span>본문의 법령 링크를 클릭하면 선택한 조문을 이곳에 표시합니다.</span>
                    </div>
                </div>
            </div>
        </aside>
    </main>

    <!-- HWPX Upload Modal -->
    <div class="upload-modal" id="manual-upload-modal" aria-hidden="true">
        <div class="upload-dialog" role="dialog" aria-modal="true" aria-labelledby="upload-modal-title">
            <div class="upload-head">
                <h3 id="upload-modal-title">HWPX 매뉴얼 파일 업로드</h3>
                <button type="button" class="upload-close" id="manual-upload-close" aria-label="닫기">&times;</button>
            </div>
            <form action="index.php" method="post" enctype="multipart/form-data" id="manual-upload-form">
                <div class="upload-body">
                    <p style="margin:0 0 10px; font-size:13px; color:#475569;">
                        한글 문서(.hwpx)를 업로드하면 자동으로 본문과 목차를 분석하여 시스템에 반영합니다.
                    </p>
                    <div class="upload-dropzone" id="upload-dropzone">
                        <div class="upload-dropzone-icon">📄</div>
                        <div class="upload-dropzone-text" id="upload-dropzone-filename">여기를 클릭하여 .hwpx 파일을 선택하세요</div>
                        <div class="upload-dropzone-sub">또는 파일을 여기에 끌어다 놓으세요</div>
                        <input type="file" name="draft_file" id="draft_file_input" accept=".hwpx" style="display:none;" required>
                    </div>
                </div>
                <div class="upload-foot">
                    <button type="button" class="upload-btn" id="manual-upload-cancel">취소</button>
                    <button type="submit" class="upload-btn primary" id="manual-upload-submit">업로드 및 반영</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rule Edit Modal -->
    <div class="rule-edit-modal" id="rule-edit-modal" aria-hidden="true">
        <div class="rule-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="rule-edit-title">
            <div class="rule-edit-head">
                <h3 id="rule-edit-title">조문 내용 수정</h3>
                <button type="button" class="rule-edit-close" id="rule-edit-close" aria-label="닫기">&times;</button>
            </div>
            <div class="rule-edit-body">
                <div class="rule-edit-group">
                    <label for="rule-edit-textarea">조문(제목 + 본문)</label>
                    <textarea id="rule-edit-textarea" class="rule-edit-textarea"></textarea>
                </div>
                <div class="rule-edit-group">
                    <label for="rule-edit-insert-after">새 조항 위치</label>
                    <select id="rule-edit-insert-after" class="rule-edit-select"></select>
                </div>
                <div class="rule-edit-group">
                    <label for="rule-edit-basis-textarea">관련근거</label>
                    <textarea id="rule-edit-basis-textarea" class="rule-edit-textarea secondary" placeholder="예: 중대재해처벌법 제4조, 산업안전보건법 제36조"></textarea>
                </div>
            </div>
            <div class="rule-edit-foot">
                <button type="button" class="rule-edit-btn danger" id="rule-edit-delete">삭제</button>
                <button type="button" class="rule-edit-btn" id="rule-edit-cancel">취소</button>
                <button type="button" class="rule-edit-btn primary" id="rule-edit-save">저장</button>
            </div>
        </div>
    </div>

    <!-- Print Preview Modal -->
    <div class="print-preview-modal" id="print-preview-modal" aria-hidden="true">
        <div class="print-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="print-preview-title">
            <div class="print-preview-head">
                <div class="print-preview-copy">
                    <h3 id="print-preview-title">PDF 문서 미리보기</h3>
                    <p style="margin:2px 0 0; font-size:12px; color:#64748b;">문서 배치를 확인한 뒤 출력 또는 PDF 저장을 진행할 수 있습니다.</p>
                </div>
                <div class="topbar-actions">
                    <button type="button" class="content-header-button" id="print-preview-download">다운로드</button>
                    <button type="button" class="content-header-button content-header-button-secondary" id="print-preview-print">출력</button>
                    <button type="button" class="content-header-button content-header-button-secondary" id="print-preview-close">닫기</button>
                </div>
            </div>
            <div class="print-preview-body">
                <div class="print-preview-paper" id="print-preview-paper"></div>
            </div>
        </div>
    </div>

    <div class="footer-note">중대재해 등에 관한 매뉴얼은 관리자 권한으로만 수정할 수 있습니다.</div>

    <script>
        (function () {
            function bindTocToggleEvents(scope) {
                var root = scope || document;
                root.querySelectorAll('[data-toc-toggle]').forEach(function (toggle) {
                    if (toggle.dataset.boundTocToggle === '1') {
                        return;
                    }

                    toggle.dataset.boundTocToggle = '1';
                    toggle.addEventListener('click', function () {
                        var group = toggle.closest('[data-toc-group]');
                        if (!group) {
                            return;
                        }

                        var isCollapsed = group.classList.toggle('is-collapsed');
                        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    });
                });
            }

            bindTocToggleEvents();

            function escapeHtml(text) {
                return String(text || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function normalizeLawReference(value) {
                return String(value || '')
                    .replace(/[\u300C\u300D"']/g, '')
                    .replace(/\s+/g, ' ')
                    .trim();
            }

            function buildLawSearchUrl(query) {
                var normalized = normalizeLawReference(query);
                if (!normalized) {
                    return 'https://www.law.go.kr/';
                }
                return 'https://www.law.go.kr/lsSc.do?menuId=1&query=' + encodeURIComponent(normalized) + '&subMenuId=15&tabMenuId=81';
            }

            function splitRelatedBasisItems(text) {
                var cleaned = String(text || '')
                    .replace(/^\s*\[?\s*\uAD00\uB828\uADFC\uAC70\s*[:\uFF1A]?\s*/u, '')
                    .replace(/\]\s*$/u, '')
                    .trim();

                if (!cleaned) {
                    return [];
                }

                return cleaned.split(/\s*,\s*|\s*;\s*/).map(function (item) {
                    return normalizeLawReference(item);
                }).filter(Boolean);
            }

            function buildLawAnchorHtmlFromItem(itemText) {
                var normalized = normalizeLawReference(itemText);
                if (!normalized) {
                    return '';
                }

                var pattern = /^([^\d]+?)\s*\uC81C\s*(\d+)\s*\uC870(?:\uC758(\d+))?(?:\s*(.+))?$/u;
                var match = normalized.match(pattern);
                if (!match) {
                    var safeText = escapeHtml(normalized);
                    return '<a class="law-ref-link" href="' + escapeHtml(buildLawSearchUrl(normalized)) + '" target="_blank" rel="noopener" data-law-query="' + safeText + '">' + safeText + '</a>';
                }

                var lawName = normalizeLawReference(match[1]);
                var articleNumber = match[2];
                var articleSubNumber = match[3] || '';
                var extraClause = match[4] ? normalizeLawReference(match[4]) : '';

                var labelText = lawName + ' 제' + articleNumber + '조' + (articleSubNumber ? '의' + articleSubNumber : '');
                if (extraClause) {
                    labelText += ' ' + extraClause;
                }
                var queryText = normalizeLawReference(labelText);

                return '<a class="law-ref-link" href="'
                    + escapeHtml(buildLawSearchUrl(queryText))
                    + '" target="_blank" rel="noopener" data-law-query="'
                    + escapeHtml(queryText)
                    + '">'
                    + escapeHtml(labelText)
                    + '</a>';
            }

            function linkRelatedBasisReferences(root) {
                if (!root) {
                    return;
                }

                root.querySelectorAll('p').forEach(function (element) {
                    var rawText = String(element.textContent || '').trim();
                    if (!/^\[?\s*\uAD00\uB828\uADFC\uAC70\s*[:\uFF1A]?/u.test(rawText)) {
                        return;
                    }

                    var items = splitRelatedBasisItems(rawText);
                    if (items.length === 0) {
                        return;
                    }

                    element.classList.add('related-basis');
                    element.innerHTML = '관련근거: ' + items.map(function (item) {
                        return buildLawAnchorHtmlFromItem(item);
                    }).join(', ');
                });
            }

            var documentRoot = document.querySelector('.document');
            var tocSearchInput = document.getElementById('toc-keyword-search');
            var tocList = document.getElementById('toc-list');

            function applyTocFilter(keyword) {
                if (!tocList) {
                    return;
                }

                var term = String(keyword || '').trim().toLowerCase();
                var groups = tocList.querySelectorAll('[data-toc-group]');

                groups.forEach(function (group) {
                    var items = group.querySelectorAll('.toc-group-items li');
                    var groupMatches = false;

                    items.forEach(function (li) {
                        var a = li.querySelector('a');
                        var text = String(a ? a.textContent : '').toLowerCase();
                        var matches = term === '' || text.indexOf(term) >= 0;
                        li.style.display = matches ? '' : 'none';
                        if (matches) {
                            groupMatches = true;
                        }
                    });

                    if (term !== '') {
                        group.style.display = groupMatches ? '' : 'none';
                        if (groupMatches) {
                            group.classList.remove('is-collapsed');
                        }
                    } else {
                        group.style.display = '';
                    }
                });
            }

            if (tocSearchInput) {
                tocSearchInput.addEventListener('input', function () {
                    applyTocFilter(tocSearchInput.value);
                });
            }

            function isArticleHeading(node) {
                if (!node || node.tagName !== 'H3') {
                    return false;
                }
                var text = (node.textContent || '').trim();
                return /^\s*\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?\b/u.test(text);
            }

            function getArticleHeadingNodes() {
                if (!documentRoot) {
                    return [];
                }
                return Array.prototype.filter.call(documentRoot.querySelectorAll('h3'), isArticleHeading);
            }

            function markArticleHeadingsEditable() {
                if (!documentRoot) {
                    return;
                }
                documentRoot.querySelectorAll('h3').forEach(function (heading) {
                    if (isArticleHeading(heading)) {
                        heading.classList.add('rule-editable');
                        heading.title = '클릭하여 조항을 수정합니다';
                    }
                });
            }

            function collectClauseNodes(heading) {
                var nodes = [heading];
                var current = heading.nextElementSibling;
                while (current) {
                    if (current.tagName === 'H2' || current.tagName === 'H3') {
                        break;
                    }
                    nodes.push(current);
                    current = current.nextElementSibling;
                }
                return nodes;
            }

            function rebuildTocFromDocument() {
                if (!documentRoot || !tocList) {
                    return;
                }

                var headings = documentRoot.querySelectorAll('h2, h3');
                var groups = [];
                var currentGroup = null;

                headings.forEach(function (heading, index) {
                    var title = (heading.textContent || '').trim();
                    if (!title) {
                        return;
                    }
                    var id = heading.id || ('section-' + (index + 1));
                    heading.id = id;

                    if (heading.tagName === 'H2' || !currentGroup) {
                        currentGroup = {
                            id: id,
                            title: title,
                            level: heading.tagName === 'H2' ? 2 : 3,
                            items: [],
                        };
                        groups.push(currentGroup);
                        return;
                    }

                    currentGroup.items.push({
                        id: id,
                        title: title,
                        level: 3,
                    });
                });

                var html = '';
                groups.forEach(function (group, idx) {
                    html += '<div class="toc-group" data-toc-group>';
                    html += '<button type="button" class="toc-group-toggle" data-toc-toggle aria-expanded="true">';
                    html += '<span>' + escapeHtml(group.title) + '</span>';
                    html += '<span class="chevron">&#9662;</span>';
                    html += '</button>';
                    html += '<ul class="toc-group-items">';
                    html += '<li><a class="level-2" href="#' + escapeHtml(group.id) + '">' + escapeHtml(group.title) + '</a></li>';
                    group.items.forEach(function (item) {
                        html += '<li><a class="level-3" href="#' + escapeHtml(item.id) + '">' + escapeHtml(item.title) + '</a></li>';
                    });
                    html += '</ul></div>';
                });

                tocList.innerHTML = html;
                bindTocToggleEvents(tocList);
            }

            function syncDocumentStructure() {
                markArticleHeadingsEditable();
                rebuildTocFromDocument();
            }

            function getCleanDocumentHtml() {
                if (!documentRoot) {
                    return '';
                }
                var clone = documentRoot.cloneNode(true);
                clone.querySelectorAll('.rule-editable').forEach(function (el) {
                    el.classList.remove('rule-editable');
                    el.removeAttribute('title');
                });
                return clone.innerHTML.trim();
            }

            function persistCurrentDocumentEdits() {
                var contentHtml = getCleanDocumentHtml();
                return fetch('index.php?action=save_edits', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=UTF-8',
                    },
                    body: JSON.stringify({
                        content_html: contentHtml,
                    }),
                }).then(function (response) {
                    if (!response.ok) {
                        return response.json().then(function (data) {
                            throw new Error((data && data.message) || '저장에 실패했습니다.');
                        });
                    }
                    return response.json();
                });
            }

            // Law Panel Functions
            var lawPanelContent = document.getElementById('law-panel-content');
            var lawPanelQuery = document.getElementById('law-panel-query');
            var lawPanelOpenLink = document.getElementById('law-panel-open-link');
            var lawPanelSearchForm = document.getElementById('law-panel-search-form');
            var lawPanelSearchInput = document.getElementById('law-panel-search-input');

            function renderLawArticle(data) {
                if (!lawPanelContent) {
                    return;
                }

                var label = data.article_label || data.law_name || '';
                var meta = [data.law_kind, data.ministry, data.effective_at ? '시행 ' + data.effective_at : '']
                    .filter(Boolean)
                    .join(' | ');

                var html = '<div class="law-article-card">';
                if (meta) {
                    html += '<div class="law-article-meta">' + escapeHtml(meta) + '</div>';
                }
                html += '<div class="law-article-title">' + escapeHtml(label) + '</div>';
                html += '<div class="law-article-body">' + escapeHtml((data.body_lines || []).join('\n\n')) + '</div>';
                html += '</div>';

                lawPanelContent.innerHTML = html;
                lawPanelContent.classList.remove('law-panel-placeholder');

                if (lawPanelQuery) {
                    lawPanelQuery.textContent = label;
                }
                if (lawPanelOpenLink && data.open_url) {
                    lawPanelOpenLink.href = data.open_url;
                }
            }

            function fetchLawArticle(queryText) {
                if (!queryText || !lawPanelContent) {
                    return;
                }

                lawPanelContent.innerHTML = '<div class="law-panel-placeholder"><div>법령 정보를 불러오는 중입니다...</div></div>';
                if (lawPanelQuery) {
                    lawPanelQuery.textContent = queryText;
                }

                fetch('index.php?action=law_api&query=' + encodeURIComponent(queryText), {
                    headers: { 'Accept': 'application/json' },
                })
                .then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.success && result.data) {
                        renderLawArticle(result.data);
                    } else {
                        lawPanelContent.innerHTML = '<div class="law-panel-placeholder"><div>' + escapeHtml((result && result.message) || '해당 법령 조문을 찾지 못했습니다.') + '</div></div>';
                    }
                })
                .catch(function () {
                    lawPanelContent.innerHTML = '<div class="law-panel-placeholder"><div>법령 정보를 불러오는 중 오류가 발생했습니다.</div></div>';
                });
            }

            if (lawPanelSearchForm && lawPanelSearchInput) {
                lawPanelSearchForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var q = lawPanelSearchInput.value.trim();
                    if (q) {
                        fetchLawArticle(q);
                    }
                });
            }

            function refreshLawReferences() {
                linkRelatedBasisReferences(documentRoot);

                if (documentRoot) {
                    documentRoot.querySelectorAll('.law-ref-link').forEach(function (link) {
                        link.addEventListener('click', function (event) {
                            event.preventDefault();
                            var query = link.getAttribute('data-law-query') || link.textContent.trim();
                            fetchLawArticle(query);
                        });
                    });
                }
            }

            // Clause Edit Modal Setup
            function setupClauseEditorModal() {
                var modal = document.getElementById('rule-edit-modal');
                var closeBtn = document.getElementById('rule-edit-close');
                var cancelBtn = document.getElementById('rule-edit-cancel');
                var deleteBtn = document.getElementById('rule-edit-delete');
                var addArticleBtn = document.getElementById('rule-add-article');
                var saveBtn = document.getElementById('rule-edit-save');
                var textarea = document.getElementById('rule-edit-textarea');
                var insertAfterSelect = document.getElementById('rule-edit-insert-after');
                var basisTextarea = document.getElementById('rule-edit-basis-textarea');
                var modalTitle = document.getElementById('rule-edit-title');

                if (!modal || !documentRoot) {
                    return;
                }

                var editingClause = null;

                function closeModal() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                    editingClause = null;
                }

                function setModalMode(mode) {
                    if (mode === 'insert') {
                        modalTitle.textContent = '새 조항 추가';
                        deleteBtn.style.display = 'none';
                        insertAfterSelect.closest('.rule-edit-group').style.display = 'block';
                    } else {
                        modalTitle.textContent = '조문 내용 수정';
                        deleteBtn.style.display = 'inline-block';
                        insertAfterSelect.closest('.rule-edit-group').style.display = 'none';
                    }
                }

                function populateInsertAfterOptions(activeHeading) {
                    insertAfterSelect.innerHTML = '';
                    var articles = getArticleHeadingNodes();
                    articles.forEach(function (hNode, idx) {
                        var opt = document.createElement('option');
                        opt.value = idx;
                        opt.textContent = (hNode.textContent || '').trim() + ' 뒤';
                        if (hNode === activeHeading) {
                            opt.selected = true;
                        }
                        insertAfterSelect.appendChild(opt);
                    });
                }

                function openModal(clause) {
                    editingClause = clause;
                    setModalMode(clause.mode || 'edit');

                    var lines = (clause.nodes || []).map(function (n) { return (n.textContent || '').trim(); }).filter(Boolean);
                    var bodyLines = [];
                    var basisLines = [];

                    lines.forEach(function (line) {
                        if (/^관련근거\s*[:\uFF1A]/u.test(line)) {
                            splitRelatedBasisItems(line).forEach(function (it) { basisLines.push(it); });
                        } else {
                            bodyLines.push(line);
                        }
                    });

                    textarea.value = bodyLines.join('\n');
                    basisTextarea.value = Array.from(new Set(basisLines)).join('\n');
                    populateInsertAfterOptions(clause.heading);

                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                    textarea.focus();
                }

                function openInsertModal() {
                    var articles = getArticleHeadingNodes();
                    var last = articles.length > 0 ? articles[articles.length - 1] : null;

                    openModal({
                        mode: 'insert',
                        heading: last,
                        nodes: [],
                    });
                    textarea.value = '';
                    basisTextarea.value = '';
                }

                markArticleHeadingsEditable();

                documentRoot.addEventListener('click', function (e) {
                    if (e.target.closest('.law-ref-link')) {
                        return;
                    }

                    var target = e.target.closest('h3');
                    if (!target || !documentRoot.contains(target) || !isArticleHeading(target)) {
                        return;
                    }

                    openModal({
                        heading: target,
                        nodes: collectClauseNodes(target),
                    });
                });

                closeBtn.addEventListener('click', closeModal);
                cancelBtn.addEventListener('click', closeModal);
                if (addArticleBtn) {
                    addArticleBtn.addEventListener('click', openInsertModal);
                }

                deleteBtn.addEventListener('click', function () {
                    if (!editingClause || !editingClause.heading) {
                        return;
                    }
                    if (!window.confirm('이 조항을 삭제할까요?')) {
                        return;
                    }

                    (editingClause.nodes || []).forEach(function (n) {
                        if (n && n.parentNode) {
                            n.parentNode.removeChild(n);
                        }
                    });

                    syncDocumentStructure();
                    persistCurrentDocumentEdits().then(closeModal).catch(function (err) { alert(err.message); });
                });

                saveBtn.addEventListener('click', function () {
                    var raw = textarea.value.trim();
                    if (!raw) {
                        alert('내용을 입력해 주세요.');
                        return;
                    }

                    var lines = raw.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
                    var headingText = lines[0];
                    var contentLines = lines.slice(1);
                    var basisList = basisTextarea.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);

                    var newNodes = [];
                    var hNode = document.createElement('h3');
                    hNode.className = 'rule-editable';
                    hNode.textContent = headingText;
                    newNodes.push(hNode);

                    contentLines.forEach(function (cl) {
                        var p = document.createElement('p');
                        p.textContent = cl;
                        newNodes.push(p);
                    });

                    if (basisList.length > 0) {
                        var bp = document.createElement('p');
                        bp.className = 'related-basis';
                        bp.textContent = '관련근거: ' + basisList.join(', ');
                        newNodes.push(bp);
                    }

                    if (editingClause.mode === 'insert') {
                        var articles = getArticleHeadingNodes();
                        var targetIdx = parseInt(insertAfterSelect.value || '0', 10);
                        var targetHeading = articles[targetIdx] || editingClause.heading;

                        if (targetHeading && targetHeading.parentNode) {
                            var insertBefore = targetHeading.nextElementSibling;
                            while (insertBefore && insertBefore.tagName !== 'H2' && insertBefore.tagName !== 'H3') {
                                insertBefore = insertBefore.nextElementSibling;
                            }
                            newNodes.forEach(function (nn) {
                                targetHeading.parentNode.insertBefore(nn, insertBefore);
                            });
                        } else {
                            newNodes.forEach(function (nn) { documentRoot.appendChild(nn); });
                        }
                    } else {
                        var oldHeading = editingClause.heading;
                        var oldNodes = editingClause.nodes || [oldHeading];
                        oldHeading.textContent = headingText;
                        oldNodes.slice(1).forEach(function (n) { if (n && n.parentNode) n.parentNode.removeChild(n); });

                        var nextSib = oldHeading.nextElementSibling;
                        newNodes.slice(1).forEach(function (nn) {
                            oldHeading.parentNode.insertBefore(nn, nextSib);
                        });
                    }

                    syncDocumentStructure();
                    refreshLawReferences();

                    persistCurrentDocumentEdits()
                        .then(closeModal)
                        .catch(function (err) { alert(err.message); });
                });

                modal.addEventListener('click', function (e) {
                    if (e.target === modal) {
                        closeModal();
                    }
                });
            }

            // Upload Modal Setup
            function setupUploadModal() {
                var openBtn = document.getElementById('manual-open-upload');
                var modal = document.getElementById('manual-upload-modal');
                var closeBtn = document.getElementById('manual-upload-close');
                var cancelBtn = document.getElementById('manual-upload-cancel');
                var dropzone = document.getElementById('upload-dropzone');
                var fileInput = document.getElementById('draft_file_input');
                var filenameText = document.getElementById('upload-dropzone-filename');

                if (!modal || !openBtn) {
                    return;
                }

                function openModal() {
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                }

                function closeModal() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                }

                openBtn.addEventListener('click', openModal);
                closeBtn.addEventListener('click', closeModal);
                cancelBtn.addEventListener('click', closeModal);

                dropzone.addEventListener('click', function () {
                    fileInput.click();
                });

                fileInput.addEventListener('change', function () {
                    if (fileInput.files && fileInput.files.length > 0) {
                        filenameText.textContent = '선택된 파일: ' + fileInput.files[0].name;
                    }
                });

                dropzone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    dropzone.style.borderColor = 'var(--law-accent)';
                    dropzone.style.background = '#edf4fe';
                });

                dropzone.addEventListener('dragleave', function () {
                    dropzone.style.borderColor = '#94a3b8';
                    dropzone.style.background = '#f8fafc';
                });

                dropzone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    dropzone.style.borderColor = '#94a3b8';
                    dropzone.style.background = '#f8fafc';
                    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        fileInput.files = e.dataTransfer.files;
                        filenameText.textContent = '선택된 파일: ' + e.dataTransfer.files[0].name;
                    }
                });
            }

            // Print Preview Modal Setup
            function setupPrintPreviewModal() {
                var pdfBtn = document.getElementById('rule-download-pdf');
                var modal = document.getElementById('print-preview-modal');
                var paper = document.getElementById('print-preview-paper');
                var closeBtn = document.getElementById('print-preview-close');
                var printBtn = document.getElementById('print-preview-print');
                var downloadBtn = document.getElementById('print-preview-download');

                if (!modal || !pdfBtn) {
                    return;
                }

                function collectChapters(container) {
                    var chapters = [];
                    var current = [];
                    Array.prototype.forEach.call(container.children || [], function (node) {
                        if (node.tagName === 'H2' && current.length > 0) {
                            chapters.push(current);
                            current = [];
                        }
                        current.push(node);
                    });
                    if (current.length > 0) {
                        chapters.push(current);
                    }
                    return chapters;
                }

                function buildPreview() {
                    if (!paper || !documentRoot) return;
                    paper.innerHTML = '';
                    var header = document.querySelector('.content-header');
                    var clone = documentRoot.cloneNode(true);
                    var chapters = collectChapters(clone);

                    if (chapters.length === 0) chapters.push([]);

                    chapters.forEach(function (chNodes, idx) {
                        var page = document.createElement('section');
                        page.className = 'print-preview-page';
                        if (idx === 0 && header) {
                            page.appendChild(header.cloneNode(true));
                        }
                        var docWrap = document.createElement('div');
                        docWrap.className = 'document';
                        chNodes.forEach(function (n) { docWrap.appendChild(n); });
                        page.appendChild(docWrap);
                        paper.appendChild(page);
                    });
                }

                function openModal() {
                    buildPreview();
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                }

                function closeModal() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                }

                pdfBtn.addEventListener('click', openModal);
                closeBtn.addEventListener('click', closeModal);
                printBtn.addEventListener('click', function () { window.print(); });
                downloadBtn.addEventListener('click', function () {
                    var url = new URL(window.location.href);
                    url.searchParams.set('action', 'download_pdf');
                    window.location.href = url.toString();
                });

                modal.addEventListener('click', function (e) {
                    if (e.target === modal) closeModal();
                });
            }

            refreshLawReferences();
            setupClauseEditorModal();
            setupUploadModal();
            setupPrintPreviewModal();
        }());
    </script>
</body>
</html>
