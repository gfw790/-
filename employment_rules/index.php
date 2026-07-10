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

function employment_rules_storage_path(): string
{
    return __DIR__ . '/data.json';
}

function employment_rules_upload_root(): string
{
    return dirname(__DIR__) . '/uploads/employment_rules';
}

function employment_rules_load_data(): array
{
    $path = employment_rules_storage_path();
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

function employment_rules_save_data(array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('데이터를 JSON으로 인코딩하지 못했습니다.');
    }

    if (file_put_contents(employment_rules_storage_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('데이터 파일을 저장하지 못했습니다.');
    }
}

function employment_rules_flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['employment_rules_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
        return null;
    }

    $flash = $_SESSION['employment_rules_flash'] ?? null;
    unset($_SESSION['employment_rules_flash']);

    return is_array($flash) ? $flash : null;
}

function employment_rules_normalize_whitespace(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    return trim((string)$text);
}

function employment_rules_decode_preview_text(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }

    if (str_starts_with($bytes, "\xFF\xFE")) {
        $bytes = substr($bytes, 2);
        return employment_rules_normalize_whitespace((string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'));
    }

    if (str_starts_with($bytes, "\xFE\xFF")) {
        $bytes = substr($bytes, 2);
        return employment_rules_normalize_whitespace((string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE'));
    }

    $utf8 = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    if (is_string($utf8) && $utf8 !== '') {
        return employment_rules_normalize_whitespace($utf8);
    }

    return employment_rules_normalize_whitespace((string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'));
}

function employment_rules_detect_heading(string $line): ?array
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
        "\u{CD1D}\u{CE59}",
        "\u{C778}\u{C0AC}",
        "\u{BCF5}\u{BB34}",
        "\u{ADFC}\u{B85C}\u{C2DC}\u{AC04}",
        "\u{D734}\u{C77C}",
        "\u{D734}\u{AC00}",
        "\u{C784}\u{AE08}",
        "\u{D1F4}\u{C9C1}",
        "\u{C9D5}\u{ACC4}",
        "\u{AD50}\u{C721}",
        "\u{C548}\u{C804}\u{BCF4}\u{AC74}",
        "\u{C7AC}\u{D574}\u{BCF4}\u{C0C1}",
        "\u{C9C1}\u{C7A5}\u{B0B4}\u{AD34}\u{B86D}\u{D798}\u{C608}\u{BC29}",
        "\u{C9C1}\u{C7A5}\u{ADDC}\u{C728}\u{ACFC}\u{C608}\u{C808}",
        "\u{BD80}\u{CE59}",
        "\u{BCC4}\u{C9C0}\u{C11C}\u{C2DD}",
    ];
    if (mb_strlen($line, 'UTF-8') <= 24 && in_array($normalizedHeading, $genericHeadings, true)) {
        return ['tag' => 'h2', 'level' => 2];
    }

    return null;
}

function employment_rules_slugify(string $text, int $index): string
{
    $slug = preg_replace('/[^a-zA-Z0-9\x{AC00}-\x{D7A3}\-_]+/u', '-', trim($text));
    $slug = trim((string)$slug, '-');
    if ($slug === '') {
        $slug = 'section-' . $index;
    }
    return $slug . '-' . $index;
}

function employment_rules_law_api_oc(): string
{
    return 'riskserver_law';
}

function employment_rules_normalize_law_reference(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\u{300C}", "\u{300D}", '"', "'"], '', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function employment_rules_normalize_law_title(string $value): string
{
    $value = employment_rules_normalize_law_reference($value);
    $value = str_replace(["\u{318D}", "\u{00B7}", ' '], '', $value);
    return mb_strtolower($value, 'UTF-8');
}

function employment_rules_build_law_article_code(int $articleNo, int $articleSubNo = 0, bool $forApi = false): string
{
    if ($forApi) {
        return sprintf('%04d%02d', $articleNo, $articleSubNo);
    }

    return sprintf('%04d%02d000', $articleNo, $articleSubNo);
}

function employment_rules_build_law_article_label(int $articleNo, int $articleSubNo = 0, string $articleTitle = ''): string
{
    $label = "\u{C81C}" . $articleNo . "\u{C870}";
    if ($articleSubNo > 0) {
        $label .= "\u{C758}" . $articleSubNo;
    }
    if ($articleTitle !== '') {
        $label .= '(' . $articleTitle . ')';
    }

    return $label;
}

function employment_rules_parse_law_reference(string $query): ?array
{
    $query = employment_rules_normalize_law_reference($query);
    if ($query === '') {
        return null;
    }

    if (preg_match('/^(.+?)\s*\x{C81C}\s*(\d+)\s*\x{C870}(?:\s*\x{C758}\s*(\d+))?(?:\s*\x{C81C}\s*(\d+)\s*\x{D56D}(?:\s*\x{C81C}\s*(\d+)\s*\x{D638})?)?$/u', $query, $matches) === 1) {
        $lawName = employment_rules_normalize_law_reference((string)($matches[1] ?? ''));
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

function employment_rules_build_law_article_url(string $lawName, int $articleNo, int $articleSubNo = 0): string
{
    return 'https://www.law.go.kr/LSW/lsLinkProc.do?lsNm='
        . rawurlencode($lawName)
        . '&joNo='
        . rawurlencode(employment_rules_build_law_article_code($articleNo, $articleSubNo, false))
        . '&mode=10&lsClsCd=010101L';
}

function employment_rules_build_law_search_url(string $query): string
{
    $reference = employment_rules_parse_law_reference($query);
    if ($reference !== null && (int)($reference['article_no'] ?? 0) > 0) {
        return employment_rules_build_law_article_url(
            (string)$reference['law_name'],
            (int)$reference['article_no'],
            (int)$reference['article_sub_no']
        );
    }

    $query = employment_rules_normalize_law_reference($query);
    if ($query === '') {
        return 'https://www.law.go.kr/';
    }

    return 'https://www.law.go.kr/lsSc.do?menuId=1&query=' . rawurlencode($query) . '&subMenuId=15&tabMenuId=81';
}

function employment_rules_build_open_api_url(string $endpoint, array $params): string
{
    return 'https://www.law.go.kr/DRF/' . ltrim($endpoint, '/')
        . '?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function employment_rules_fetch_remote_html(string $url): string
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

function employment_rules_fetch_remote_json(string $url): array
{
    $raw = employment_rules_fetch_remote_html($url);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function employment_rules_value_list(mixed $value): array
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

function employment_rules_extract_content_value(mixed $value): string
{
    if (is_array($value)) {
        return trim((string)($value['content'] ?? ''));
    }

    return trim((string)$value);
}

function employment_rules_pick_law_search_result(array $items, string $lawName): ?array
{
    $normalizedLawName = employment_rules_normalize_law_title($lawName);

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $candidateName = employment_rules_normalize_law_title((string)($item["\u{BC95}\u{B839}\u{BA85}\u{D55C}\u{AE00}"] ?? ""));
        $candidateAlias = employment_rules_normalize_law_title((string)($item["\u{BC95}\u{B839}\u{C57D}\u{CE6D}\u{BA85}"] ?? ""));
        if ($candidateName === $normalizedLawName || $candidateAlias === $normalizedLawName) {
            return $item;
        }
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $candidateName = employment_rules_normalize_law_title((string)($item["\u{BC95}\u{B839}\u{BA85}\u{D55C}\u{AE00}"] ?? ""));
        $candidateAlias = employment_rules_normalize_law_title((string)($item["\u{BC95}\u{B839}\u{C57D}\u{CE6D}\u{BA85}"] ?? ""));
        if (
            ($candidateName !== "" && str_contains($candidateName, $normalizedLawName))
            || ($candidateAlias !== "" && str_contains($candidateAlias, $normalizedLawName))
        ) {
            return $item;
        }
    }

    return isset($items[0]) && is_array($items[0]) ? $items[0] : null;
}

function employment_rules_format_law_date(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches) === 1) {
        return (int)$matches[1] . '. ' . (int)$matches[2] . '. ' . (int)$matches[3] . '.';
    }

    return $value;
}

function employment_rules_collect_law_text_lines(mixed $node, array &$lines): void
{
    if (!is_array($node)) {
        return;
    }

    foreach (["\u{C870}\u{BB38}\u{B0B4}\u{C6A9}", "\u{D56D}\u{B0B4}\u{C6A9}", "\u{D638}\u{B0B4}\u{C6A9}", "\u{BAA9}\u{B0B4}\u{C6A9}"] as $textKey) {
        $value = $node[$textKey] ?? "";
        if (is_array($value)) {
            $fragments = [];
            array_walk_recursive($value, static function ($part) use (&$fragments): void {
                if (is_scalar($part) || $part === null) {
                    $text = trim((string)$part);
                    if ($text !== "") {
                        $fragments[] = $text;
                    }
                }
            });
            $value = implode(" ", $fragments);
        }
        $value = employment_rules_normalize_whitespace(trim((string)$value));
        if ($value !== "" && !in_array($value, $lines, true)) {
            $lines[] = $value;
        }
    }

    foreach (["\u{D56D}", "\u{D638}", "\u{BAA9}"] as $childKey) {
        foreach (employment_rules_value_list($node[$childKey] ?? []) as $child) {
            employment_rules_collect_law_text_lines($child, $lines);
        }
    }
}

function employment_rules_pick_child_unit_by_number(array $units, string $numberKey, int $number): ?array
{
    foreach ($units as $unit) {
        if (!is_array($unit)) {
            continue;
        }

        $rawNumber = employment_rules_extract_content_value($unit[$numberKey] ?? '');
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

function employment_rules_strip_article_heading(string $line, int $articleNo, int $articleSubNo, string $articleTitle): string
{
    $patterns = [
        employment_rules_build_law_article_label($articleNo, $articleSubNo, $articleTitle),
        employment_rules_build_law_article_label($articleNo, $articleSubNo),
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

function employment_rules_pick_law_article_unit(array $articleUnits): ?array
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

function employment_rules_collect_full_law_lines(array $articleUnits): array
{
    $lines = [];

    foreach ($articleUnits as $unit) {
        if (!is_array($unit)) {
            continue;
        }

        $unitLines = [];
        employment_rules_collect_law_text_lines($unit, $unitLines);
        foreach ($unitLines as $value) {
            $value = employment_rules_normalize_whitespace(trim((string)$value));
            if ($value === '' || in_array($value, $lines, true)) {
                continue;
            }

            $lines[] = $value;
        }
    }

    return $lines;
}

function employment_rules_build_law_api_payload(string $query): array
{
    return employment_rules_build_law_api_payload_v2($query);
}

function employment_rules_build_law_api_payload_v2(string $query): array
{
    $reference = employment_rules_parse_law_reference($query);
    if ($reference === null) {
        throw new InvalidArgumentException("\u{BC95}\u{B839}\u{BA85}\u{C744} \u{C785}\u{B825}\u{D574} \u{C8FC}\u{C138}\u{C694}. \u{C870}\u{BB38}\u{BC88}\u{D638}\u{B97C} \u{D568}\u{AED8} \u{C785}\u{B825}\u{D558}\u{BA74} \u{D574}\u{B2F9} \u{C870}\u{BB38}\u{C744}, \u{BC95}\u{B839}\u{BA85}\u{B9CC} \u{C785}\u{B825}\u{D558}\u{BA74} \u{C804}\u{CCB4} \u{BC95}\u{B839}\u{C744} \u{BCF4}\u{C5EC}\u{B4DC}\u{B9BD}\u{B2C8}\u{B2E4}.");
    }

    $searchUrl = employment_rules_build_open_api_url('lawSearch.do', [
        'OC' => employment_rules_law_api_oc(),
        'target' => 'law',
        'type' => 'JSON',
        'query' => (string)$reference['law_name'],
    ]);
    $searchData = employment_rules_fetch_remote_json($searchUrl);
    $searchRoot = is_array($searchData['LawSearch'] ?? null) ? $searchData['LawSearch'] : [];
    $searchItems = employment_rules_value_list($searchRoot['law'] ?? []);
    $selectedLaw = employment_rules_pick_law_search_result($searchItems, (string)$reference['law_name']);
    if (!is_array($selectedLaw)) {
        throw new RuntimeException("\u{BC95}\u{C81C}\u{CC98}\u{C5D0}\u{C11C} \u{D574}\u{B2F9} \u{BC95}\u{B839}\u{C744} \u{CC3E}\u{C9C0} \u{BABB}\u{D588}\u{C2B5}\u{B2C8}\u{B2E4}.");
    }

    $lawId = trim((string)($selectedLaw["\u{BC95}\u{B839}ID"] ?? $selectedLaw['id'] ?? ''));
    if ($lawId === '') {
        throw new RuntimeException("\u{BC95}\u{B839} ID\u{B97C} \u{D655}\u{C778}\u{D558}\u{C9C0} \u{BABB}\u{D588}\u{C2B5}\u{B2C8}\u{B2E4}.");
    }

    $articleNo = (int)($reference['article_no'] ?? 0);
    $articleSubNo = (int)($reference['article_sub_no'] ?? 0);
    $paragraphNo = (int)($reference['paragraph_no'] ?? 0);
    $itemNo = (int)($reference['item_no'] ?? 0);

    $detailParams = [
        'OC' => employment_rules_law_api_oc(),
        'target' => $articleNo > 0 ? 'lawjosub' : 'eflaw',
        'type' => 'JSON',
        'ID' => $lawId,
    ];
    if ($articleNo > 0) {
        $detailParams['JO'] = employment_rules_build_law_article_code($articleNo, $articleSubNo, true);
    }

    $detailUrl = employment_rules_build_open_api_url('lawService.do', $detailParams);
    $detailData = employment_rules_fetch_remote_json($detailUrl);
    $lawData = is_array($detailData["\u{BC95}\u{B839}"] ?? null) ? $detailData["\u{BC95}\u{B839}"] : [];
    if ($lawData === []) {
        throw new RuntimeException($articleNo > 0
            ? "\u{C120}\u{D0DD}\u{D55C} \u{C870}\u{BB38}\u{C758} \u{C0C1}\u{C138} \u{B0B4}\u{C6A9}\u{C744} \u{BD88}\u{B7EC}\u{C624}\u{C9C0} \u{BABB}\u{D588}\u{C2B5}\u{B2C8}\u{B2E4}."
            : "\u{C120}\u{D0DD}\u{D55C} \u{BC95}\u{B839}\u{C758} \u{C804}\u{CCB4} \u{B0B4}\u{C6A9}\u{C744} \u{BD88}\u{B7EC}\u{C624}\u{C9C0} \u{BABB}\u{D588}\u{C2B5}\u{B2C8}\u{B2E4}."
        );
    }

    $baseInfo = is_array($lawData["\u{AE30}\u{BCF8}\u{C815}\u{BCF4}"] ?? null) ? $lawData["\u{AE30}\u{BCF8}\u{C815}\u{BCF4}"] : [];
    $articleUnits = employment_rules_value_list($lawData["\u{C870}\u{BB38}"]["\u{C870}\u{BB38}\u{B2E8}\u{C704}"] ?? []);

    $lawName = trim((string)($baseInfo["\u{BC95}\u{B839}\u{BA85}_\u{D55C}\u{AE00}"] ?? $selectedLaw["\u{BC95}\u{B839}\u{BA85}\u{D55C}\u{AE00}"] ?? $reference['law_name']));
    $ministry = employment_rules_extract_content_value($baseInfo["\u{C18C}\u{AD00}\u{BD80}\u{CC98}"] ?? '');
    $lawKind = employment_rules_extract_content_value($baseInfo["\u{BC95}\u{C885}\u{AD6C}\u{BD84}"] ?? '');
    $effectiveAt = employment_rules_format_law_date((string)($baseInfo["\u{C2DC}\u{D589}\u{C77C}\u{C790}"] ?? ''));
    $promulgationAt = employment_rules_format_law_date((string)($baseInfo["\u{ACF5}\u{D3EC}\u{C77C}\u{C790}"] ?? ''));
    $promulgationNo = ltrim(trim((string)($baseInfo["\u{ACF5}\u{D3EC}\u{BC88}\u{D638}"] ?? '')), '0');
    $revisionType = trim((string)($baseInfo["\u{C81C}\u{AC1C}\u{C815}\u{AD6C}\u{BD84}"] ?? ''));
    $contactPhone = trim((string)($baseInfo["\u{C804}\u{D654}\u{BC88}\u{D638}"] ?? ''));

    if ($articleNo <= 0) {
        $bodyLines = employment_rules_collect_full_law_lines($articleUnits);
        if ($bodyLines === []) {
            throw new RuntimeException("\u{BC95}\u{B839} \u{C804}\u{CCB4} \u{BCF8}\u{BB38}\u{C744} \u{CC3E}\u{C9C0} \u{BABB}\u{D588}\u{C2B5}\u{B2C8}\u{B2E4}.");
        }

        $articleCount = 0;
        foreach ($articleUnits as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            if (trim((string)($unit["\u{C870}\u{BB38}\u{C5EC}\u{BD80}"] ?? '')) === "\u{C870}\u{BB38}") {
                $articleCount++;
            }
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
            'open_url' => employment_rules_build_law_search_url((string)$reference['law_name']),
            'law_id' => $lawId,
            'is_full_law' => true,
            'article_count' => $articleCount > 0 ? $articleCount : count($bodyLines),
        ];
    }

    $articleUnit = employment_rules_pick_law_article_unit($articleUnits);
    if (!is_array($articleUnit)) {
        throw new RuntimeException("\u{C870}\u{BB38} \u{BCF8}\u{BB38}\u{C744} \u{CC3E}\u{C9C0} \u{BABB}\u{D588}\u{C2B5}\u{B2C8}\u{B2E4}.");
    }

    $articleTitle = trim((string)($articleUnit["\u{C870}\u{BB38}\u{C81C}\u{BAA9}"] ?? ''));
    $articleLabel = employment_rules_build_law_article_label($articleNo, $articleSubNo, $articleTitle);
    if ($paragraphNo > 0) {
        $articleLabel .= ' ' . "\u{C81C}" . $paragraphNo . "\u{D56D}";
    }
    if ($itemNo > 0) {
        $articleLabel .= ' ' . "\u{C81C}" . $itemNo . "\u{D638}";
    }

    $bodySourceNode = $articleUnit;
    if ($paragraphNo > 0) {
        $paragraphUnits = employment_rules_value_list($articleUnit["\u{D56D}"] ?? []);
        $selectedParagraph = employment_rules_pick_child_unit_by_number($paragraphUnits, "\u{D56D}\u{BC88}\u{D638}", $paragraphNo);
        if (is_array($selectedParagraph)) {
            $bodySourceNode = $selectedParagraph;

            if ($itemNo > 0) {
                $itemUnits = employment_rules_value_list($selectedParagraph["\u{D638}"] ?? []);
                $selectedItem = employment_rules_pick_child_unit_by_number($itemUnits, "\u{D638}\u{BC88}\u{D638}", $itemNo);
                if (is_array($selectedItem)) {
                    $bodySourceNode = $selectedItem;
                }
            }
        }
    }

    $bodyLines = [];
    employment_rules_collect_law_text_lines($bodySourceNode, $bodyLines);
    if ($bodyLines !== [] && $paragraphNo <= 0) {
        $bodyLines[0] = employment_rules_strip_article_heading((string)$bodyLines[0], $articleNo, $articleSubNo, $articleTitle);
        if ($bodyLines[0] === '') {
            array_shift($bodyLines);
        }
    }

    if ($effectiveAt === '') {
        $effectiveAt = employment_rules_format_law_date((string)($articleUnit["\u{C870}\u{BB38}\u{C2DC}\u{D589}\u{C77C}\u{C790}"] ?? ''));
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
        'body_lines' => array_values(array_filter($bodyLines, static fn ($line): bool => trim((string)$line) !== '')),
        'open_url' => employment_rules_build_law_article_url((string)$reference['law_name'], $articleNo, $articleSubNo),
        'law_id' => $lawId,
        'is_full_law' => false,
    ];
}

if (($_GET['action'] ?? '') === 'law_api') {
    header('Content-Type: application/json; charset=UTF-8');
    $query = trim((string)($_GET['query'] ?? ''));

    try {
        if ($query === '' || mb_strlen($query, 'UTF-8') > 200) {
            throw new InvalidArgumentException('법령 검색어를 1자 이상 200자 이하로 입력해 주세요.');
        }

        echo json_encode(
            array_merge(['success' => true], employment_rules_build_law_api_payload_v2($query)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 502);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'law_proxy') {
    $query = trim((string)($_GET['query'] ?? ''));
    if ($query === '' || mb_strlen($query, 'UTF-8') > 200) {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ko"><meta charset="UTF-8"><body style="font-family:Malgun Gothic,sans-serif;padding:24px;">법령 검색어를 1자 이상 200자 이하로 입력해 주세요.</body></html>';
        exit;
    }

    $remoteUrl = employment_rules_build_law_search_url($query);
    $html = employment_rules_fetch_remote_html($remoteUrl);
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

function employment_rules_build_html_from_text(string $text): array
{
    $lines = preg_split("/\n+/u", $text) ?: [];
    $html = [];
    $toc = [];
    $headingIndex = 0;

    foreach ($lines as $line) {
        $line = employment_rules_normalize_whitespace($line);
        if ($line === '') {
            continue;
        }

        $line = trim($line, " \t\n\r\0\x0B\x{FEFF}");
        if ($line === '') {
            continue;
        }

        $heading = employment_rules_detect_heading($line);
        if ($heading !== null) {
            $headingIndex++;
            $id = employment_rules_slugify($line, $headingIndex);
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

function employment_rules_extract_summary_from_plain_text(string $plainText, string $title = "\u{CDE8}\u{C5C5}\u{ADDC}\u{CE59}"): string
{
    $lines = preg_split("/\n+/u", $plainText) ?: [];
    foreach ($lines as $line) {
        $line = employment_rules_normalize_whitespace((string)$line);
        if ($line === '' || $line === $title) {
            continue;
        }

        return $line;
    }

    return '';
}

function employment_rules_dom_inner_html(DOMNode $node): string
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

function employment_rules_normalize_saved_content_html(string $contentHtml): array
{
    $wrapper = '<div id="employment-rules-root">' . $contentHtml . '</div>';
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML(
        '<?xml encoding="utf-8" ?>' . $wrapper,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
    );

    if ($loaded === false) {
        throw new RuntimeException('\uC800\uC7A5\uD560 \uBB38\uC11C HTML\uC744 \uD574\uC11D\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
    }

    $xpath = new DOMXPath($dom);
    $rootList = $xpath->query('//*[@id="employment-rules-root"]');
    if (!$rootList instanceof DOMNodeList || $rootList->length < 1) {
        throw new RuntimeException('\uBB38\uC11C \uB8E8\uD2B8\uB97C \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
    }

    $root = $rootList->item(0);
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('\uBB38\uC11C \uB8E8\uD2B8\uB97C \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
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
            $title = employment_rules_normalize_whitespace($headingNode->textContent ?? '');
            if ($title === '') {
                continue;
            }

            $headingIndex++;
            $id = trim((string)$headingNode->getAttribute('id'));
            if ($id === '') {
                $id = employment_rules_slugify($title, $headingIndex);
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
            $line = employment_rules_normalize_whitespace($lineNode->textContent ?? '');
            if ($line !== '') {
                $plainLines[] = $line;
            }
        }
    }

    $plainText = trim(implode("\n", $plainLines));
    $normalizedHtml = trim(employment_rules_dom_inner_html($root));

    return [
        'content_html' => $normalizedHtml,
        'toc' => $toc,
        'plain_text' => $plainText,
    ];
}

function employment_rules_extract_table_html(DOMElement $tableNode): string
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
                $value = employment_rules_normalize_whitespace($textNode->textContent);
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

function employment_rules_extract_from_sections(ZipArchive $zip): array
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
                        $tableHtml = employment_rules_extract_table_html($tableNode);
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
                $value = employment_rules_normalize_whitespace($textNode->textContent);
                if ($value !== '') {
                    $texts[] = $value;
                }
            }

            $line = employment_rules_normalize_whitespace(implode(' ', $texts));
            if ($line === '') {
                continue;
            }

            $heading = employment_rules_detect_heading($line);
            if ($heading !== null) {
                $headingIndex++;
                $id = employment_rules_slugify($line, $headingIndex);
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

function employment_rules_parse_hwpx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
    throw new RuntimeException('HWPX 파일을 열 수 없습니다.');
    }

    try {
        $previewText = '';
        $previewBytes = $zip->getFromName('Preview/PrvText.txt');
        if (is_string($previewBytes) && $previewBytes !== '') {
            $previewText = employment_rules_decode_preview_text($previewBytes);
        }

        $previewBuilt = ['html' => '', 'toc' => []];
        if ($previewText !== '') {
            $previewBuilt = employment_rules_build_html_from_text($previewText);
        }

        $sectionBuilt = employment_rules_extract_from_sections($zip);
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
        $title = "\u{CDE8}\u{C5C5}\u{ADDC}\u{CE59}";

        $summary = '';
        foreach ($lines as $line) {
            $line = employment_rules_normalize_whitespace($line);
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

function employment_rules_handle_upload(array $user): void
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

    $uploadRoot = employment_rules_upload_root();
    $relativeDir = date('Y/m');
    $targetDir = $uploadRoot . '/' . $relativeDir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('업로드 폴더를 생성하지 못했습니다.');
    }

    $safeBaseName = preg_replace('/[^a-zA-Z0-9\x{AC00}-\x{D7A3}_\-]/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBaseName = trim((string)$safeBaseName, '_');
    if ($safeBaseName === '') {
        $safeBaseName = 'employment_rules';
    }

    $storedName = sprintf('%s_%s_%s.hwpx', $safeBaseName, date('Ymd_His'), substr(bin2hex(random_bytes(4)), 0, 8));
    $targetPath = $targetDir . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('업로드 파일을 저장하지 못했습니다.');
    }

    $parsed = employment_rules_parse_hwpx($targetPath);
    $now = date('Y-m-d H:i:s');

    employment_rules_save_data([
        'current' => [
            'title' => $parsed['title'],
            'summary' => $parsed['summary'],
            'content_html' => $parsed['content_html'],
            'toc' => $parsed['toc'],
            'plain_text' => $parsed['plain_text'],
            'source_name' => $originalName,
            'source_path' => '/uploads/employment_rules/' . $relativeDir . '/' . $storedName,
            'uploaded_at' => $now,
            'uploaded_by' => trim((string)($user['name'] ?? $user['login_id'] ?? "\u{AD00}\u{B9AC}\u{C790}")),
        ],
    ]);
}

function employment_rules_handle_save_edits(array $user): void
{
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'POST \uC694\uCCAD\uB9CC \uD5C8\uC6A9\uB429\uB2C8\uB2E4.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('\uC800\uC7A5 \uC694\uCCAD \uB370\uC774\uD130\uB97C \uD574\uC11D\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
        }

        $contentHtml = trim((string)($payload['content_html'] ?? ''));
        if ($contentHtml === '') {
            throw new InvalidArgumentException('\uC800\uC7A5\uD560 \uBCF8\uBB38\uC774 \uC5C6\uC2B5\uB2C8\uB2E4.');
        }
        if (mb_strlen($contentHtml, '8bit') > 2_000_000) {
            throw new InvalidArgumentException('\uBB38\uC11C\uAC00 \uB108\uBB34 \uD07D\uB2C8\uB2E4. \uBCF8\uBB38 \uD06C\uAE30\uB97C \uC904\uC778 \uD6C4 \uB2E4\uC2DC \uC2DC\uB3C4\uD574 \uC8FC\uC138\uC694.');
        }

        $data = employment_rules_load_data();
        $current = is_array($data['current'] ?? null) ? $data['current'] : null;
        if (!is_array($current)) {
            throw new InvalidArgumentException('\uC800\uC7A5\uD560 \uB300\uC0C1 \uBB38\uC11C\uAC00 \uC5C6\uC2B5\uB2C8\uB2E4.');
        }

        $normalized = employment_rules_normalize_saved_content_html($contentHtml);
        $now = date('Y-m-d H:i:s');
        $title = trim((string)($current['title'] ?? "\u{CDE8}\u{C5C5}\u{ADDC}\u{CE59}"));

        $current['content_html'] = (string)$normalized['content_html'];
        $current['toc'] = is_array($normalized['toc'] ?? null) ? $normalized['toc'] : [];
        $current['plain_text'] = (string)($normalized['plain_text'] ?? '');
        $current['summary'] = employment_rules_extract_summary_from_plain_text($current['plain_text'], $title);
        $current['updated_at'] = $now;
        $current['updated_by'] = trim((string)($user['name'] ?? $user['login_id'] ?? "\u{AD00}\u{B9AC}\u{C790}"));

        $data['current'] = $current;
        employment_rules_save_data($data);

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
    employment_rules_handle_save_edits($user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        employment_rules_handle_upload($user);
        employment_rules_flash('success', 'HWPX 파일을 업로드하여 취업규칙을 반영했습니다.');
    } catch (Throwable $e) {
        employment_rules_flash('error', $e->getMessage());
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$data = employment_rules_load_data();
$current = is_array($data['current'] ?? null) ? $data['current'] : null;
$flash = employment_rules_flash();
$pageTitle = "\u{CDE8}\u{C5C5}\u{ADDC}\u{CE59}";
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

    $isAppendixHeading = preg_match('/^\x{BD80}\x{CE59}(?:\s|\(|$)/u', $title) === 1;
    $isAnnexHeading = preg_match('/^\x{BCC4}\x{C9C0}(?:\s*\x{C11C}\x{C2DD})?(?:\s|\(|$)/u', $title) === 1;
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
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background:
                linear-gradient(180deg, #ebf1f8 0%, #f7f9fc 220px, #f4f7fb 220px, #f4f7fb 100%);
            color: var(--law-text);
            font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
        }

        a { color: var(--law-accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: linear-gradient(180deg, var(--law-blue), var(--law-deep-blue));
            color: #fff;
            border-bottom: 4px solid #0d2649;
        }

        .topbar-inner {
            max-width: min(1920px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: url("현대기전 로고.png") center / 72% 72% no-repeat;
            box-shadow: none;
        }

        .brand-copy small {
            display: block;
            color: rgba(255,255,255,0.75);
            font-size: 12px;
            letter-spacing: 0.08em;
        }

        .brand-copy strong {
            display: block;
            font-size: 25px;
            letter-spacing: -0.04em;
            margin-top: 4px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-chip {
            padding: 10px 14px;
            border: 1px solid rgba(255,255,255,0.22);
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            font-size: 13px;
            color: rgba(255,255,255,0.92);
            white-space: nowrap;
        }

        .search-band {
            max-width: min(1920px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 18px 24px 26px;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            border: 1px solid #c1d0e5;
            border-radius: 16px;
            padding: 14px 18px;
            box-shadow: 0 10px 30px rgba(15, 39, 74, 0.08);
        }

        .search-box .label {
            color: var(--law-blue);
            font-weight: 700;
            white-space: nowrap;
        }

        .search-box .hint {
            color: var(--law-muted);
            font-size: 14px;
        }

        .layout {
            max-width: min(1920px, calc(100vw - 32px));
            margin: 20px auto 0;
            padding: 0 24px 48px;
            display: grid;
            grid-template-columns: 220px minmax(0, 1.15fr) 540px;
            gap: 24px;
        }

        .sidebar,
        .law-panel,
        .content-card,
        .upload-card {
            background: var(--law-card);
            border: 1px solid var(--law-line);
            border-radius: 20px;
            box-shadow: 0 18px 38px rgba(21, 45, 83, 0.05);
        }

        .sidebar {
            padding: 20px;
            position: sticky;
            top: calc(var(--header-sticky-offset) + var(--panel-sticky-gap));
            align-self: start;
            max-height: calc(100vh - var(--header-sticky-offset) - (var(--panel-sticky-gap) * 2));
            display: flex;
            flex-direction: column;
        }

        .sidebar h2,
        .upload-card h2,
        .content-card h1 {
            margin: 0;
        }

        .sidebar h2 {
            font-size: 18px;
            color: var(--law-heading);
            padding-bottom: 14px;
            border-bottom: 2px solid #e8eef7;
        }

        .meta-list {
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
            font-size: 14px;
        }

        .sidebar-scroll {
            margin-top: 16px;
            padding-right: 6px;
            overflow-y: auto;
            min-height: 0;
        }

        .sidebar-scroll::-webkit-scrollbar {
            width: 10px;
        }

        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: #c5d3e7;
            border-radius: 999px;
            border: 2px solid #f8fbff;
        }

        .meta-list strong {
            display: block;
            color: var(--law-heading);
            font-size: 12px;
            margin-bottom: 3px;
        }

        .toc {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #e8eef7;
        }

        .sidebar-scroll > .toc:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .toc h3 {
            margin: 0 0 12px;
            font-size: 16px;
            color: var(--law-heading);
        }

        .toc-header {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
            padding: 0 0 10px;
            background: var(--law-card);
        }

        .toc-header h3 {
            margin: 0;
        }

        .toc ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .toc-list.is-collapsed {
            display: none;
        }

        .toc-group {
            border-top: 1px solid #edf2f8;
            padding-top: 10px;
            margin-top: 10px;
        }

        .toc-group:first-child {
            border-top: 0;
            padding-top: 0;
            margin-top: 0;
        }

        .toc-group-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid #d7e2f0;
            background: #f8fbff;
            color: var(--law-heading);
            border-radius: 12px;
            padding: 10px 12px;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
        }

        .toc-group-toggle:hover {
            background: #eef4fc;
        }

        .toc-group-toggle .chevron {
            flex: 0 0 auto;
            color: #5d7397;
            transition: transform 0.18s ease;
        }

        .toc-group.is-collapsed .toc-group-toggle .chevron {
            transform: rotate(-90deg);
        }

        .toc-group-items {
            list-style: none;
            margin: 10px 0 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .toc-group.is-collapsed .toc-group-items {
            display: none;
        }

        .toc a {
            display: block;
            color: #334155;
            font-size: 14px;
            padding: 7px 10px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid transparent;
        }

        .toc a.level-3 {
            margin-left: 14px;
            background: #fbfcfe;
        }

        .toc a:hover {
            border-color: #c7d7ee;
            color: var(--law-accent);
            text-decoration: none;
        }

        .main {
            display: grid;
            gap: 20px;
        }

        .law-panel {
            position: sticky;
            top: calc(var(--header-sticky-offset) + var(--panel-sticky-gap));
            align-self: start;
            height: calc(100vh - var(--header-sticky-offset) - (var(--panel-sticky-gap) * 2));
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .law-panel-head {
            padding: 18px 18px 14px;
            border-bottom: 1px solid #e7eef8;
            background: linear-gradient(180deg, #f8fbff, #ffffff);
        }

        .law-panel-head h2 {
            margin: 0;
            font-size: 18px;
            color: var(--law-heading);
        }

        .law-panel-head p {
            margin: 8px 0 0;
            color: var(--law-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .law-panel-meta {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .law-panel-query {
            padding: 10px 12px;
            border-radius: 12px;
            background: #f4f8fd;
            border: 1px solid #dbe5f3;
            color: #1f355c;
            font-size: 13px;
            line-height: 1.5;
            word-break: keep-all;
        }

        .law-panel-search {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
        }

        .law-panel-search-input {
            width: 100%;
            min-width: 0;
            min-height: 40px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid #cdd9eb;
            background: #fff;
            color: #17345c;
            font-size: 13px;
            outline: none;
        }

        .law-panel-search-input:focus {
            border-color: #2d63ae;
            box-shadow: 0 0 0 3px rgba(45, 99, 174, 0.14);
        }

        .law-panel-search-button {
            min-height: 40px;
            padding: 0 14px;
            border: 0;
            border-radius: 12px;
            background: #dfeafb;
            color: #1d4d93;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .law-panel-search-button:hover {
            filter: brightness(0.98);
        }

        .law-panel-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 12px;
            border-radius: 12px;
            background: #1d4d93;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .law-panel-link:hover {
            color: #fff;
            text-decoration: none;
            filter: brightness(1.03);
        }

        .law-panel-body {
            flex: 1 1 auto;
            min-height: 0;
            background: #eef4fb;
            overflow-y: auto;
        }

        .law-panel-content {
            min-height: 100%;
            height: auto;
            padding: 20px 18px 24px;
            overflow: visible;
            background: #fff;
            color: #20324f;
        }

        .law-panel-placeholder,
        .law-panel-loading,
        .law-panel-error {
            display: flex;
            min-height: 560px;
            align-items: center;
            justify-content: center;
            text-align: center;
            line-height: 1.8;
            color: #50627f;
        }

        .law-panel-placeholder strong,
        .law-panel-loading strong,
        .law-panel-error strong {
            display: block;
            margin-bottom: 10px;
            font-size: 19px;
            color: #17315b;
        }

        .law-panel-card {
            display: grid;
            gap: 18px;
        }

        .law-panel-card-head {
            padding: 16px 18px;
            border: 1px solid #dbe5f3;
            border-radius: 16px;
            background: linear-gradient(180deg, #f8fbff, #f2f7fd);
        }

        .law-panel-title {
            margin: 0;
            font-size: 22px;
            line-height: 1.45;
            color: #163157;
        }

        .law-panel-subtitle {
            margin: 8px 0 0;
            color: #4f6283;
            font-size: 14px;
        }

        .law-panel-section {
            padding: 16px 18px;
            border: 1px solid #e3ebf7;
            border-radius: 16px;
            background: #fdfefe;
        }

        .law-panel-section h3 {
            margin: 0 0 12px;
            font-size: 15px;
            color: #1e355d;
        }

        .law-panel-section p {
            margin: 0 0 10px;
            color: #243754;
            line-height: 1.8;
            font-size: 14px;
            word-break: keep-all;
        }

        .law-panel-section p:last-child {
            margin-bottom: 0;
        }

        .law-panel-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
        }

        .law-panel-list li {
            display: grid;
            grid-template-columns: 80px minmax(0, 1fr);
            gap: 10px;
            font-size: 13px;
            color: #334866;
        }

        .law-panel-list strong {
            color: #18345f;
        }

        .law-panel-frame {
            width: 100%;
            height: 100%;
            min-height: 560px;
            border: 0;
            background: #fff;
        }

        .upload-card {
            padding: 22px 22px 20px;
        }

        .upload-card h2 {
            font-size: 21px;
            color: var(--law-heading);
            margin-bottom: 8px;
        }

        .upload-card p {
            margin: 0;
            color: var(--law-muted);
            line-height: 1.6;
        }

        .upload-form {
            margin-top: 18px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
        }

        .file-input {
            position: relative;
            overflow: hidden;
            border: 1px dashed #9db3d6;
            background: linear-gradient(180deg, #f9fbfe, #f2f6fc);
            border-radius: 14px;
            padding: 16px 18px;
        }

        .file-input input {
            width: 100%;
            font: inherit;
        }

        .button {
            border: 0;
            border-radius: 14px;
            padding: 0 20px;
            min-height: 56px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(180deg, #2c67bd, #124686);
            box-shadow: 0 10px 24px rgba(18, 70, 134, 0.22);
        }

        .button:hover {
            filter: brightness(1.03);
        }

        .flash {
            margin-top: 14px;
            border-radius: 14px;
            padding: 13px 15px;
            font-size: 14px;
            border: 1px solid transparent;
        }

        .flash.success {
            background: #edf7ee;
            color: #165c2b;
            border-color: #b7dfbf;
        }

        .flash.error {
            background: #fff1f1;
            color: #8c1d1d;
            border-color: #f0b8b8;
        }

        .content-card {
            overflow: hidden;
        }

        .content-header {
            padding: 28px 30px 20px;
            border-bottom: 1px solid #e6edf7;
            background:
                linear-gradient(180deg, rgba(245, 249, 255, 0.98), rgba(255,255,255,0.98)),
                linear-gradient(135deg, rgba(30,79,149,0.08), transparent 48%);
        }

        .content-header .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #eef4fc;
            color: var(--law-blue);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .content-header h1 {
            font-size: 34px;
            color: var(--law-heading);
            margin-top: 16px;
            letter-spacing: -0.05em;
        }

        .content-header p {
            margin: 14px 0 0;
            color: var(--law-muted);
            line-height: 1.8;
            font-size: 15px;
        }

        .content-header-meta {
            margin-top: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .content-header-button {
            border: 1px solid #184d94;
            border-radius: 12px;
            background: #1d4d93;
            color: #fff;
            padding: 10px 14px;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(18, 70, 134, 0.16);
        }

        .content-header-button:hover {
            filter: brightness(1.03);
        }

        .law-link-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eef6ff;
            border: 1px solid #d7e5f7;
            color: #1d4d93;
            font-size: 13px;
            font-weight: 700;
        }

        .document {
            padding: 32px 30px 36px;
            line-height: 1.95;
            font-size: 16px;
        }

        .document h2,
        .document h3 {
            color: var(--law-heading);
            letter-spacing: -0.02em;
        }

        .document h2 {
            margin: 34px 0 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #d9e4f4;
            font-size: 24px;
        }

        .document h3 {
            margin: 28px 0 10px;
            font-size: 19px;
        }

        .document p {
            margin: 10px 0;
            color: #1f2937;
        }

        .document p.bullet {
            padding-left: 12px;
            color: #374151;
        }

        .document p.related-basis {
            white-space: normal;
        }

        .rule-editable {
            cursor: pointer;
            border-radius: 8px;
            transition: background-color 0.16s ease;
        }

        .rule-editable:hover {
            background: #f6faff;
        }

        .rule-edit-modal {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(12, 22, 38, 0.56);
        }

        .rule-edit-modal.is-open {
            display: flex;
        }

        .rule-edit-dialog {
            width: min(1120px, 100%);
            max-height: calc(100vh - 40px);
            overflow: hidden;
            background: #fff;
            border: 1px solid #d6e0ee;
            border-radius: 18px;
            box-shadow: 0 24px 56px rgba(12, 26, 46, 0.24);
            display: grid;
            grid-template-rows: auto 1fr auto;
        }

        .rule-edit-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid #e7eef8;
            background: #f8fbff;
        }

        .rule-edit-head h3 {
            margin: 0;
            color: #163965;
            font-size: 18px;
        }

        .rule-edit-close {
            border: 1px solid #d3deed;
            background: #fff;
            color: #334155;
            border-radius: 10px;
            width: 34px;
            height: 34px;
            cursor: pointer;
            font-size: 20px;
            line-height: 1;
        }

        .rule-edit-body {
            padding: 16px 18px;
            display: grid;
            gap: 10px;
        }

        .rule-edit-group {
            display: grid;
            gap: 8px;
        }

        .rule-edit-group.is-hidden {
            display: none;
        }

        .rule-edit-select {
            width: 100%;
            min-height: 46px;
            border: 1px solid #cdd9ea;
            border-radius: 12px;
            padding: 10px 12px;
            font: inherit;
            font-size: 14px;
            color: #0f172a;
            background: #fff;
        }

        .rule-edit-body label {
            font-size: 13px;
            color: #475569;
            font-weight: 700;
        }

        .rule-edit-textarea {
            width: 100%;
            min-height: 260px;
            resize: vertical;
            border: 1px solid #cdd9ea;
            border-radius: 12px;
            padding: 12px 13px;
            font: inherit;
            font-size: 15px;
            line-height: 1.7;
            color: #0f172a;
            background: #fff;
        }

        .rule-edit-textarea.secondary {
            min-height: 110px;
        }

        .rule-edit-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 18px 16px;
            border-top: 1px solid #e7eef8;
            background: #fcfdff;
        }

        .rule-edit-btn {
            border-radius: 10px;
            border: 1px solid #c9d7ea;
            background: #fff;
            color: #334155;
            padding: 9px 13px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .rule-edit-btn.primary {
            border-color: #184d94;
            background: #1d4d93;
            color: #fff;
        }

        .rule-edit-btn.danger {
            margin-right: auto;
            border-color: #d9b6b6;
            background: #fff4f4;
            color: #9a1f1f;
        }

        .law-ref-link {
            color: var(--law-accent);
            text-decoration: underline;
            text-decoration-style: dotted;
            text-underline-offset: 2px;
            font-weight: 600;
        }

        .law-ref-link:hover {
            color: #083f97;
        }

        .rule-table-wrap {
            margin: 18px 0;
            overflow-x: auto;
            border: 1px solid #d6dfed;
            border-radius: 14px;
        }

        .rule-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            min-width: 560px;
        }

        .rule-table td {
            border: 1px solid #dbe3ef;
            padding: 10px 12px;
            vertical-align: top;
            font-size: 14px;
            line-height: 1.7;
        }

        .empty-state {
            padding: 56px 30px 64px;
            text-align: center;
            color: var(--law-muted);
        }

        .empty-state strong {
            display: block;
            color: var(--law-heading);
            font-size: 24px;
            margin-bottom: 12px;
        }

        .footer-note {
            max-width: min(1920px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 0 24px 44px;
            color: #6b7280;
            font-size: 13px;
        }

        @media (max-width: 1080px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: static;
                max-height: none;
            }

            .law-panel {
                position: static;
                height: auto;
                max-height: none;
            }
        }

        @media (max-width: 720px) {
            .topbar-inner,
            .search-band,
            .layout,
            .footer-note {
                padding-left: 16px;
                padding-right: 16px;
            }

            .brand-copy strong {
                font-size: 22px;
            }

            .user-chip {
                white-space: normal;
            }

            .upload-form {
                grid-template-columns: 1fr;
            }

            .content-header,
            .document,
            .upload-card,
            .sidebar {
                padding-left: 18px;
                padding-right: 18px;
            }

            .content-header h1 {
                font-size: 28px;
            }

            .rule-edit-modal {
                padding: 12px;
            }

            .rule-edit-dialog {
                max-height: calc(100vh - 24px);
            }

            .rule-edit-textarea {
                min-height: 200px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true"></div>
                <div class="brand-copy">
                    <small>EMPLOYMENT RULES SYSTEM</small>
                    <strong>현대기전 취업규칙</strong>
                </div>
            </div>
            <div class="topbar-actions">
                <button type="button" class="content-header-button" id="rule-add-article">새 조항 추가</button>
                <div class="user-chip"><?= h(trim((string)($user['name'] ?? $user['login_id'] ?? "\u{AD00}\u{B9AC}\u{C790}"))) ?> 님</div>
            </div>
        </div>
    </header>

    <main class="layout">
        <aside class="sidebar">
            <div class="sidebar-scroll" id="sidebar-scroll-area">
                <?php if ($tocGroups !== [] || $appendixGroups !== [] || $annexGroups !== []): ?>
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
                                    <h3>별지 서식</h3>
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
                <?php if ($current): ?>
                    <div class="content-header">
                        <span class="badge">최신 반영본</span>
                        <h1>취업규칙</h1>
                    </div>
                    <div class="document">
                        <?= $renderedContentHtml ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <strong>아직 반영된 취업규칙이 없습니다.</strong>
                        <div>취업규칙 본문 데이터가 없습니다.</div>
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
                            placeholder="&#50696;: &#44540;&#47196;&#44592;&#51456;&#48277; / &#44540;&#47196;&#44592;&#51456;&#48277; &#51228;93&#51312;"
                            aria-label="&#44288;&#47144; &#48277;&#51312;&#47928; &#44160;&#49353;"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <button class="law-panel-search-button" type="submit">&#44160;&#49353;</button>
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
                    <textarea id="rule-edit-basis-textarea" class="rule-edit-textarea secondary" placeholder="예: 근로기준법 제93조, 산업안전보건법 제41조 제1항"></textarea>
                </div>
            </div>
            <div class="rule-edit-foot">
                <button type="button" class="rule-edit-btn danger" id="rule-edit-delete">삭제</button>
                <button type="button" class="rule-edit-btn" id="rule-edit-cancel">취소</button>
                <button type="button" class="rule-edit-btn primary" id="rule-edit-save">저장</button>
            </div>
        </div>
    </div>

    <div class="footer-note">취업규칙 문서는 관리자 권한으로만 수정할 수 있습니다.</div>
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

            function buildLawSearchUrl(query) {
                var normalizedQuery = normalizeLawReference(query);
                var matched = normalizedQuery.match(/(.+?(?:\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59))\s*\uC81C\s*(\d+)\s*\uC870(?:\s*\uC758\s*(\d+))?(?:\s*\uC81C\s*(\d+)\s*\uD56D(?:\s*\uC81C\s*(\d+)\s*\uD638)?)?/);
                if (!matched) {
                    return 'https://www.law.go.kr/lsSc.do?menuId=1&query=' + encodeURIComponent(normalizedQuery) + '&subMenuId=15&tabMenuId=81';
                }

                var lawName = normalizeLawReference(matched[1] || '');
                var articleNumber = parseInt(matched[2] || '0', 10);
                var articleSubNumber = parseInt(matched[3] || '0', 10);
                if (!lawName || !articleNumber) {
                    return 'https://www.law.go.kr/lsSc.do?menuId=1&query=' + encodeURIComponent(normalizedQuery) + '&subMenuId=15&tabMenuId=81';
                }

                return 'https://www.law.go.kr/LSW/lsLinkProc.do?lsNm='
                    + encodeURIComponent(lawName)
                    + '&joNo='
                    + encodeURIComponent(buildLawArticleNo(articleNumber, articleSubNumber))
                    + '&mode=10&lsClsCd=010101L';
            }

            function escapeHtml(text) {
                return String(text)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function normalizeLawReference(text) {
                return String(text || '')
                    .replace(/[\u300C\u300D"']/g, '')
                    .replace(/\s+/g, ' ')
                    .replace(/[\s,.;:]+$/g, '')
                    .trim();
            }

            function splitRelatedBasisItems(text) {
                var source = String(text || '')
                    .replace(/^\s*\[?\s*\uAD00\uB828\uADFC\uAC70\s*[:\uFF1A]?\s*/u, '')
                    .replace(/\]\s*$/u, '');

                var citationMatches = source.match(/([\uAC00-\uD7A3A-Za-z0-9\s\(\)\-\u00B7\u318D]+?(?:\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59)\s*\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?(?:\s*\uC81C\s*\d+\s*\uD56D(?:\s*\uC81C\s*\d+\s*\uD638)?)?)/gu);
                if (citationMatches && citationMatches.length > 0) {
                    return citationMatches
                        .map(function (item) {
                            return normalizeLawReference(item);
                        })
                        .filter(function (item) {
                            return item !== '';
                        });
                }

                var rawItems = source.split(/[,;\n]+/);
                var output = [];
                var lastLawName = '';

                rawItems.forEach(function (item) {
                    var normalized = normalizeLawReference(item);
                    if (!normalized) {
                        return;
                    }

                    var lawMatch = normalized.match(/(.+?(?:\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59))\s*(\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?)/u);
                    if (lawMatch) {
                        lastLawName = normalizeLawReference(lawMatch[1] || '');
                        output.push(normalizeLawReference((lawMatch[1] || '') + ' ' + (lawMatch[2] || '')));
                        return;
                    }

                    if (/^\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?/u.test(normalized) && lastLawName) {
                        output.push(normalizeLawReference(lastLawName + ' ' + normalized));
                        return;
                    }

                    output.push(normalized);
                });

                return output.filter(function (item) {
                    return item !== '';
                });
            }

            function padNumber(value, size) {
                var output = String(Math.max(0, parseInt(value || 0, 10) || 0));
                while (output.length < size) {
                    output = '0' + output;
                }
                return output;
            }

            function buildLawArticleNo(articleNumber, articleSubNumber) {
                return padNumber(articleNumber, 4) + padNumber(articleSubNumber || 0, 2) + '000';
            }

            function buildLawReferenceLink(lawName, articleNumber, articleSubNumber, labelText) {
                var queryText = normalizeLawReference(
                    lawName + ' ' + '\uC81C' + articleNumber + '\uC870' + (articleSubNumber ? '\uC758' + articleSubNumber : '')
                );
                return {
                    href: buildLawSearchUrl(queryText),
                    queryText: queryText,
                    labelText: labelText
                };
            }

            function buildLawAnchorHtmlFromItem(itemText) {
                var normalized = normalizeLawReference(itemText);
                if (!normalized) {
                    return '';
                }

                var matched = normalized.match(/(.+?(?:\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59))\s*\uC81C\s*(\d+)\s*\uC870(?:\s*\uC758\s*(\d+))?((?:\s*\uC81C\s*\d+\s*\uD56D(?:\s*\uC81C\s*\d+\s*\uD638)?)?)/u);
                if (!matched) {
                    return escapeHtml(normalized);
                }

                var lawName = normalizeLawReference(matched[1] || '');
                var articleNumber = parseInt(matched[2] || '0', 10);
                var articleSubNumber = parseInt(matched[3] || '0', 10);
                var extraClause = normalizeLawReference(matched[4] || '');
                if (!lawName || !articleNumber) {
                    return escapeHtml(normalized);
                }

                var labelText = lawName + ' ' + '\uC81C' + articleNumber + '\uC870' + (articleSubNumber ? '\uC758' + articleSubNumber : '');
                if (extraClause) {
                    labelText += ' ' + extraClause;
                }
                var queryText = normalizeLawReference(labelText);
                var linkData = {
                    href: buildLawSearchUrl(queryText),
                    queryText: queryText,
                    labelText: labelText,
                };

                return '<a class="law-ref-link" href="'
                    + escapeHtml(linkData.href)
                    + '" target="_blank" rel="noopener" data-law-query="'
                    + escapeHtml(linkData.queryText)
                    + '">'
                    + escapeHtml(linkData.labelText)
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
                    element.innerHTML = '\uAD00\uB828\uADFC\uAC70: ' + items.map(function (item) {
                        return buildLawAnchorHtmlFromItem(item);
                    }).join(', ');
                });
            }

            function rebuildArticleRelatedBasisLinks(root, articleNo) {
                if (!root || !articleNo) {
                    return;
                }

                var headingPattern = new RegExp('^\\s*\\uC81C\\s*' + String(articleNo) + '\\s*\\uC870(?:\\s*\\uC758\\s*\\d+)?');

                root.querySelectorAll('h3').forEach(function (heading) {
                    var headingText = String(heading.textContent || '').trim();
                    if (!headingPattern.test(headingText)) {
                        return;
                    }

                    var current = heading.nextElementSibling;
                    while (current) {
                        if (current.tagName === 'H2' || current.tagName === 'H3') {
                            break;
                        }

                        if (current.tagName === 'P') {
                            var text = String(current.textContent || '').trim();
                            if (/^\[?\s*\uAD00\uB828\uADFC\uAC70\s*[:\uFF1A]?/u.test(text)) {
                                current.classList.add('related-basis');
                                var items = splitRelatedBasisItems(text);
                                current.innerHTML = items.length > 0
                                    ? '\uAD00\uB828\uADFC\uAC70: ' + items.map(function (item) {
                                        return buildLawAnchorHtmlFromItem(item);
                                    }).join(', ')
                                    : '\uAD00\uB828\uADFC\uAC70 \uC5C6\uC74C';
                            }
                        }

                        current = current.nextElementSibling;
                    }
                });
            }

            function linkLawReferences(root) {
                if (!root) {
                    return;
                }

                var pattern = /([^\[\]\n,;:<]*?(?:\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59)\s*\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?(?:\s*\uC81C\s*\d+\s*\uD56D(?:\s*\uC81C\s*\d+\s*\uD638)?)?)/g;
                root.querySelectorAll('p, h2, h3, td').forEach(function (element) {
                    if (element.querySelector('a')) {
                        return;
                    }

                    var text = element.textContent || '';
                    if (!pattern.test(text)) {
                        pattern.lastIndex = 0;
                        return;
                    }

                    pattern.lastIndex = 0;
                    var replacedHtml = escapeHtml(text).replace(pattern, function (matchedText) {
                        var queryText = matchedText.replace(/[\[\]"']/g, '').replace(/\s+/g, ' ').trim();
                        var href = buildLawSearchUrl(queryText);
                        return '<a class=\"law-ref-link\" href=\"' + href + '\" target=\"_blank\" rel=\"noopener\" data-law-query=\"' + escapeHtml(queryText) + '\">' + escapeHtml(matchedText) + '</a>';
                    });
                    element.innerHTML = replacedHtml;
                });
            }

            function linkLawReferencesSafe(root) {
                if (!root) {
                    return 0;
                }

                var citationPattern = /([^\[\]\n;:<]*?(?:\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59))\s*\uC81C\s*(\d+)\s*\uC870(?:\s*\uC758\s*(\d+))?(?:\s*\uC81C\s*(\d+)\s*\uD56D(?:\s*\uC81C\s*(\d+)\s*\uD638)?)?((?:\s*,\s*\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?(?:\s*\uC81C\s*\d+\s*\uD56D(?:\s*\uC81C\s*\d+\s*\uD638)?)?)*)/g;
                var trailingPattern = /(\s*,\s*)\uC81C\s*(\d+)\s*\uC870(?:\s*\uC758\s*(\d+))?(?:\s*\uC81C\s*(\d+)\s*\uD56D(?:\s*\uC81C\s*(\d+)\s*\uD638)?)?/g;
                var linkCount = 0;

                root.querySelectorAll('p, h2, h3, td').forEach(function (element) {
                    var plainText = String(element.textContent || '').trim();
                    if (/^\[?\s*\uAD00\uB828\uADFC\uAC70\s*[:\uFF1A]?/u.test(plainText) || element.classList.contains('related-basis')) {
                        return;
                    }

                    if (element.querySelector('a')) {
                        return;
                    }

                    var text = element.textContent || '';
                    if (!citationPattern.test(text)) {
                        citationPattern.lastIndex = 0;
                        return;
                    }

                    citationPattern.lastIndex = 0;
                    var replacedHtml = escapeHtml(text).replace(citationPattern, function (matchedText, lawNameText, articleNumber, articleSubNumber, paragraphNumber, itemNumber, trailingArticles) {
                        var rawLawName = normalizeLawReference(lawNameText);
                        var lawName = rawLawName;
                        var stopWords = ['\uBCF8', '\uC774', '\uD574\uB2F9', '\uB3D9', '\uADDC\uC815\uC740', '\uADDC\uC815', '\uC870\uBB38\uC740', '\uC870\uBB38', '\uBC0F', '\uC640', '\uACFC'];
                        var parts = rawLawName.split(' ').filter(Boolean);
                        for (var i = 0; i < parts.length; i++) {
                            var candidate = parts.slice(i).join(' ');
                            if (!/(\uBC95\uB960|\uBC95|\uC2DC\uD589\uB839|\uC2DC\uD589\uADDC\uCE59)$/u.test(candidate)) {
                                continue;
                            }
                            if (stopWords.indexOf(parts[i]) >= 0) {
                                continue;
                            }
                            lawName = candidate;
                            break;
                        }

                        var prefixText = '';
                        var prefixIndex = rawLawName.lastIndexOf(lawName);
                        if (prefixIndex > 0) {
                            prefixText = rawLawName.slice(0, prefixIndex);
                        }

                        var firstArticleNumber = parseInt(articleNumber || '0', 10);
                        var firstArticleSubNumber = parseInt(articleSubNumber || '0', 10);
                        var firstParagraphNumber = parseInt(paragraphNumber || '0', 10);
                        var firstItemNumber = parseInt(itemNumber || '0', 10);
                        if (!lawName || !firstArticleNumber) {
                            return escapeHtml(matchedText);
                        }

                        var firstLabelText = normalizeLawReference(
                            lawName
                            + ' '
                            + '\uC81C' + firstArticleNumber + '\uC870'
                            + (firstArticleSubNumber ? '\uC758' + firstArticleSubNumber : '')
                            + (firstParagraphNumber ? ' \uC81C' + firstParagraphNumber + '\uD56D' : '')
                            + (firstItemNumber ? ' \uC81C' + firstItemNumber + '\uD638' : '')
                        );
                        var firstLink = {
                            href: buildLawSearchUrl(firstLabelText),
                            queryText: firstLabelText,
                            labelText: firstLabelText,
                        };
                        linkCount += 1;

                        var trailingHtml = escapeHtml(trailingArticles || '').replace(trailingPattern, function (subMatchedText, delimiter, nextArticleNumber, nextArticleSubNumber, nextParagraphNumber, nextItemNumber) {
                            var nextArticle = parseInt(nextArticleNumber || '0', 10);
                            var nextSubArticle = parseInt(nextArticleSubNumber || '0', 10);
                            var nextParagraph = parseInt(nextParagraphNumber || '0', 10);
                            var nextItem = parseInt(nextItemNumber || '0', 10);
                            if (!nextArticle) {
                                return escapeHtml(subMatchedText);
                            }

                            var nextLabelText = normalizeLawReference(
                                lawName
                                + ' '
                                + '\uC81C' + nextArticle + '\uC870'
                                + (nextSubArticle ? '\uC758' + nextSubArticle : '')
                                + (nextParagraph ? ' \uC81C' + nextParagraph + '\uD56D' : '')
                                + (nextItem ? ' \uC81C' + nextItem + '\uD638' : '')
                            );
                            var nextLink = {
                                href: buildLawSearchUrl(nextLabelText),
                                queryText: nextLabelText,
                                labelText: nextLabelText,
                            };
                            linkCount += 1;

                            return escapeHtml(delimiter)
                                + '<a class="law-ref-link" href="'
                                + nextLink.href
                                + '" target="_blank" rel="noopener" data-law-query="'
                                + escapeHtml(nextLink.queryText)
                                + '">'
                                + escapeHtml(nextLink.labelText)
                                + '</a>';
                        });

                        return escapeHtml(prefixText)
                            + '<a class="law-ref-link" href="'
                            + firstLink.href
                            + '" target="_blank" rel="noopener" data-law-query="'
                            + escapeHtml(firstLink.queryText)
                            + '">'
                            + escapeHtml(firstLink.labelText)
                            + '</a>'
                            + trailingHtml;
                    });

                    element.innerHTML = replacedHtml;
                });

                return linkCount;
            }

            var documentRoot = document.querySelector('.document');
            var lawLinkStatus = document.getElementById('law-link-status');
            var tocList = document.getElementById('toc-list');

            function isArticleHeading(node) {
                if (!node || node.tagName !== 'H3') {
                    return false;
                }

                return /^\s*(?:#+\s*)?\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?/.test((node.textContent || '').trim());
            }

            function collectClauseNodes(headingNode) {
                var nodes = [headingNode];
                var current = headingNode.nextElementSibling;
                while (current) {
                    if (current.tagName === 'H2' || current.tagName === 'H3') {
                        break;
                    }

                    nodes.push(current);
                    current = current.nextElementSibling;
                }

                return nodes;
            }

            function getArticleHeadingNodes() {
                if (!documentRoot) {
                    return [];
                }

                return Array.prototype.filter.call(documentRoot.querySelectorAll('h3'), function (node) {
                    return isArticleHeading(node);
                });
            }

            function normalizeHeadingText(text) {
                return String(text || '').replace(/\s+/g, ' ').trim();
            }

            function slugifyHeading(text, index) {
                var slug = String(text || '')
                    .trim()
                    .replace(/[^a-zA-Z0-9\uAC00-\uD7A3\-_]+/g, '-')
                    .replace(/^-+|-+$/g, '');
                if (!slug) {
                    slug = 'section-' + index;
                }
                return slug + '-' + index;
            }

            function formatArticleHeadingText(articleNumber, headingText) {
                var remainder = normalizeHeadingText(headingText).replace(/^(?:#+\s*)?\uC81C\s*\d+\s*\uC870(?:\s*\uC758\s*\d+)?\s*/u, '');
                var prefix = '\uC81C' + articleNumber + '\uC870';
                if (!remainder) {
                    return prefix;
                }

                return prefix + (/^\(/.test(remainder) ? '' : ' ') + remainder;
            }

            function markArticleHeadingsEditable() {
                getArticleHeadingNodes().forEach(function (node) {
                    node.classList.add('rule-editable');
                });
            }

            function renumberArticleHeadings() {
                var nextNumber = 1;
                getArticleHeadingNodes().forEach(function (node) {
                    node.textContent = formatArticleHeadingText(nextNumber, node.textContent || '');
                    nextNumber += 1;
                });
            }

            function buildTocGroupsFromDocument() {
                var groups = {
                    chapters: [],
                    appendix: [],
                    annex: [],
                };
                var currentCollection = 'chapters';
                var currentGroup = null;

                if (!documentRoot) {
                    return groups;
                }

                documentRoot.querySelectorAll('h2, h3').forEach(function (node, index) {
                    var level = node.tagName === 'H2' ? 2 : 3;
                    var title = normalizeHeadingText(node.textContent || '');
                    if (!title) {
                        return;
                    }

                    var id = slugifyHeading(title, index + 1);
                    node.id = id;

                    var item = {
                        id: id,
                        title: title,
                        level: level,
                    };
                    var isAppendixHeading = /^\uBD80\uCE59(?:\s|\(|$)/.test(title);
                    var isAnnexHeading = /^\uBCC4\uC9C0(?:\s*\uC11C\uC2DD)?(?:\s|\(|$)/.test(title);

                    if (level === 2 || !currentGroup) {
                        currentCollection = isAppendixHeading ? 'appendix' : (isAnnexHeading ? 'annex' : 'chapters');
                        currentGroup = {
                            heading: item,
                            items: [],
                        };
                        groups[currentCollection].push(currentGroup);
                        return;
                    }

                    currentGroup.items.push(item);
                });

                return groups;
            }

            function buildTocGroupHtml(groups, domIdPrefix) {
                return groups.map(function (group, groupIndex) {
                    var heading = group.heading || {};
                    var items = Array.isArray(group.items) ? group.items : [];
                    var domId = domIdPrefix + '-' + groupIndex;
                    var itemsHtml = items.map(function (item) {
                        var levelClass = 'level-' + (item.level || 3);
                        return '<li><a class="' + escapeHtml(levelClass) + '" href="#' + escapeHtml(item.id || '') + '">' + escapeHtml(item.title || '') + '</a></li>';
                    }).join('');

                    return ''
                        + '<div class="toc-group is-collapsed" data-toc-group>'
                        + '  <button type="button" class="toc-group-toggle" data-toc-toggle aria-expanded="false" aria-controls="' + escapeHtml(domId) + '">'
                        + '    <span>' + escapeHtml(heading.title || '') + '</span>'
                        + '    <span class="chevron">&#9662;</span>'
                        + '  </button>'
                        + '  <ul class="toc-group-items" id="' + escapeHtml(domId) + '">'
                        + '    <li><a class="level-2" href="#' + escapeHtml(heading.id || '') + '">' + escapeHtml(heading.title || '') + '</a></li>'
                        + itemsHtml
                        + '  </ul>'
                        + '</div>';
                }).join('');
            }

            function rebuildTocFromDocument() {
                if (!tocList) {
                    return;
                }

                var groups = buildTocGroupsFromDocument();
                var html = buildTocGroupHtml(groups.chapters, 'toc-group-items');

                if (groups.appendix.length > 0) {
                    html += '<div class="toc-header" style="margin-top:16px;"><h3>부칙</h3></div>';
                    html += buildTocGroupHtml(groups.appendix, 'toc-appendix-items');
                }

                if (groups.annex.length > 0) {
                    html += '<div class="toc-header" style="margin-top:16px;"><h3>별지 서식</h3></div>';
                    html += buildTocGroupHtml(groups.annex, 'toc-annex-items');
                }

                tocList.innerHTML = html;
                bindTocToggleEvents(tocList);
            }

            function syncDocumentStructure() {
                if (!documentRoot) {
                    return;
                }

                renumberArticleHeadings();
                markArticleHeadingsEditable();
                rebuildTocFromDocument();
            }

            function buildPersistableDocumentHtml() {
                if (!documentRoot) {
                    return '';
                }

                var clone = documentRoot.cloneNode(true);
                clone.querySelectorAll('a.law-ref-link').forEach(function (anchor) {
                    var textNode = document.createTextNode(anchor.textContent || '');
                    anchor.replaceWith(textNode);
                });

                clone.querySelectorAll('[data-bound-law-click]').forEach(function (node) {
                    node.removeAttribute('data-bound-law-click');
                });

                return clone.innerHTML;
            }

            function persistCurrentDocumentEdits() {
                if (!documentRoot) {
                    return Promise.reject(new Error('\uBCF8\uBB38 \uC601\uC5ED\uC744 \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.'));
                }

                return fetch('index.php?action=save_edits', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        content_html: buildPersistableDocumentHtml()
                    })
                })
                    .then(function (response) {
                        return response.json()
                            .catch(function () {
                                return {
                                    success: false,
                                    message: '\uC800\uC7A5 \uC751\uB2F5\uC744 \uD574\uC11D\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.'
                                };
                            })
                            .then(function (payload) {
                                if (!response.ok || !payload.success) {
                                    throw new Error(payload.message || '\uBCC0\uACBD\uC0AC\uD56D\uC744 \uC800\uC7A5\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
                                }
                                return payload;
                            });
                    });
            }

            function updateLawLinkStatusCount() {
                if (!lawLinkStatus || !documentRoot) {
                    return;
                }

                lawLinkStatus.textContent = '\uBC95\uB839 \uB9C1\uD06C \uAC10\uC9C0 ' + documentRoot.querySelectorAll('.law-ref-link').length + '\uAC74';
            }

            var lawPanelContent = document.getElementById('law-panel-content');
            var lawPanelQuery = document.getElementById('law-panel-query');
            var lawPanelOpenLink = document.getElementById('law-panel-open-link');
            var lawPanelSearchForm = document.getElementById('law-panel-search-form');
            var lawPanelSearchInput = document.getElementById('law-panel-search-input');
            var lawPanelHeading = document.querySelector('.law-panel-head h2');
            var lawPanelDescription = document.querySelector('.law-panel-head p');

            function normalizeLawPanelCopy() {
                if (lawPanelHeading) {
                    lawPanelHeading.textContent = '\uAD00\uB828 \uBC95\uC870\uBB38';
                }
                if (lawPanelDescription) {
                    lawPanelDescription.textContent = '\uBCF8\uBB38\uC758 \uBC95\uB839 \uB9C1\uD06C\uB97C \uB204\uB974\uAC70\uB098 \uC9C1\uC811 \uAC80\uC0C9\uD558\uBA74 \uC120\uD0DD\uD55C \uC870\uBB38\uC744 \uC774 \uC601\uC5ED\uC5D0\uC11C \uBC14\uB85C \uD655\uC778\uD560 \uC218 \uC788\uC2B5\uB2C8\uB2E4.';
                }
                if (lawPanelQuery) {
                    lawPanelQuery.textContent = '\uC544\uC9C1 \uC120\uD0DD\uD55C \uBC95\uB839\uC774 \uC5C6\uC2B5\uB2C8\uB2E4.';
                }
                if (lawPanelOpenLink) {
                    lawPanelOpenLink.textContent = '\uBC95\uC81C\uCC98 \uC6D0\uBB38 \uC5F4\uAE30';
                }
                if (lawPanelContent && lawPanelContent.classList.contains('law-panel-placeholder')) {
                    lawPanelContent.innerHTML = '<div><strong>\uAD00\uB828 \uBC95\uC870\uBB38 \uBCF4\uAE30</strong><span>\uBCF8\uBB38\uC758 \uBC95\uB839 \uB9C1\uD06C\uB97C \uD074\uB9AD\uD558\uAC70\uB098 \uAC80\uC0C9\uD558\uBA74 \uC120\uD0DD\uD55C \uC870\uBB38\uC744 \uC774\uACF3\uC5D0 \uD45C\uC2DC\uD569\uB2C8\uB2E4.</span></div>';
                }
            }

            normalizeLawPanelCopy();

            function renderLawPanelState(className, title, description) {
                if (!lawPanelContent) {
                    return;
                }

                lawPanelContent.className = 'law-panel-content ' + className;
                lawPanelContent.innerHTML = '<div><strong>' + escapeHtml(title) + '</strong><span>' + escapeHtml(description) + '</span></div>';
            }

            function renderLawPanelResult(payload) {
                if (!lawPanelContent) {
                    return;
                }

                var infoItems = [];
                if (payload.is_full_law) {
                    infoItems.push('<li><strong>\uBCF4\uAE30\uBC94\uC704</strong><span>\uC804\uCCB4 \uBC95\uB839</span></li>');
                }
                if (payload.law_kind) {
                    infoItems.push('<li><strong>\uBC95\uC885</strong><span>' + escapeHtml(payload.law_kind) + '</span></li>');
                }
                if (payload.ministry) {
                    infoItems.push('<li><strong>\uC18C\uAD00\uBD80\uCC98</strong><span>' + escapeHtml(payload.ministry) + '</span></li>');
                }
                if (payload.effective_at) {
                    infoItems.push('<li><strong>\uC2DC\uD589\uC77C</strong><span>' + escapeHtml(payload.effective_at) + '</span></li>');
                }
                if (payload.promulgation_at || payload.promulgation_no || payload.revision_type) {
                    var promulgationMeta = [];
                    if (payload.promulgation_no) {
                        promulgationMeta.push('\uC81C' + escapeHtml(payload.promulgation_no) + '\uD638');
                    }
                    if (payload.promulgation_at) {
                        promulgationMeta.push(escapeHtml(payload.promulgation_at));
                    }
                    if (payload.revision_type) {
                        promulgationMeta.push(escapeHtml(payload.revision_type));
                    }
                    infoItems.push('<li><strong>\uACF5\uD3EC\uC815\uBCF4</strong><span>' + promulgationMeta.join(' / ') + '</span></li>');
                }
                if (payload.contact_phone) {
                    infoItems.push('<li><strong>\uBB38\uC758\uC804\uD654</strong><span>' + escapeHtml(payload.contact_phone) + '</span></li>');
                }
                if (payload.is_full_law && payload.article_count) {
                    infoItems.push('<li><strong>\uC870\uBB38\uC218</strong><span>' + escapeHtml(payload.article_count) + '</span></li>');
                }

                var bodyLines = Array.isArray(payload.body_lines) ? payload.body_lines : [];
                var bodyHtml = bodyLines.length
                    ? bodyLines.map(function (line) { return '<p>' + escapeHtml(line) + '</p>'; }).join('')
                    : '<p>' + (payload.is_full_law
                        ? '\uBC95\uB839 \uC804\uCCB4 \uBCF8\uBB38\uC744 \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.'
                        : '\uC870\uBB38 \uBCF8\uBB38\uC744 \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.') + '</p>';
                var headerTitle = payload.article_label || payload.query || '';
                var headerSubtitle = payload.law_name || '';
                if (payload.is_full_law && headerSubtitle === headerTitle) {
                    headerSubtitle = payload.law_kind || '';
                }
                var bodySectionTitle = payload.is_full_law ? '\uBC95\uB839 \uC804\uCCB4' : '\uC870\uBB38 \uB0B4\uC6A9';

                lawPanelContent.className = 'law-panel-content';
                lawPanelContent.innerHTML = ''
                    + '<div class="law-panel-card">'
                    + '  <div class="law-panel-card-head">'
                    + '    <h3 class="law-panel-title">' + escapeHtml(headerTitle) + '</h3>'
                    + '    <p class="law-panel-subtitle">' + escapeHtml(headerSubtitle) + '</p>'
                    + '  </div>'
                    + '  <section class="law-panel-section">'
                    + '    <h3>' + bodySectionTitle + '</h3>'
                    +      bodyHtml
                    + '  </section>'
                    + '  <section class="law-panel-section">'
                    + '    <h3>\uAE30\uBCF8 \uC815\uBCF4</h3>'
                    + '    <ul class="law-panel-list">' + infoItems.join('') + '</ul>'
                    + '  </section>'
                    + '</div>';
            }

            function loadLawPanel(queryText, openUrl) {
                if (!queryText || !lawPanelContent || !lawPanelQuery || !lawPanelOpenLink) {
                    return;
                }

                if (lawPanelSearchInput) {
                    lawPanelSearchInput.value = queryText;
                }

                lawPanelQuery.textContent = queryText;
                lawPanelOpenLink.href = openUrl || buildLawSearchUrl(queryText);
                renderLawPanelState(
                    'law-panel-loading',
                    '\uBD88\uB7EC\uC624\uB294 \uC911',
                    ''
                );

                fetch('index.php?action=law_api&query=' + encodeURIComponent(queryText), {
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                    .then(function (response) {
                        return response.json()
                            .catch(function () {
                                return {
                                    success: false,
                                    message: '\uBC95\uB839 \uC751\uB2F5\uC744 \uD574\uC11D\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.'
                                };
                            })
                            .then(function (payload) {
                                if (!response.ok || !payload.success) {
                                    throw new Error(payload.message || '\uBC95\uB839 \uC815\uBCF4\uB97C \uBD88\uB7EC\uC624\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
                                }

                                return payload;
                            });
                    })
                    .then(function (payload) {
                        lawPanelOpenLink.href = payload.open_url || lawPanelOpenLink.href;
                        renderLawPanelResult(payload);
                    })
                    .catch(function (error) {
                        renderLawPanelState(
                            'law-panel-error',
                            '\uBC95\uB839 \uC870\uD68C \uC2E4\uD328',
                            error && error.message ? error.message : '\uC7A0\uC2DC \uD6C4 \uB2E4\uC2DC \uC2DC\uB3C4\uD574 \uC8FC\uC138\uC694.'
                        );
                    });
            }

            if (lawPanelSearchForm && lawPanelSearchInput) {
                lawPanelSearchForm.addEventListener('submit', function (event) {
                    event.preventDefault();

                    var queryText = normalizeLawReference(lawPanelSearchInput.value || '');
                    if (!queryText) {
                        if (lawPanelQuery) {
                            lawPanelQuery.textContent = '\uAC80\uC0C9\uC5B4\uB97C \uC785\uB825\uD574 \uC8FC\uC138\uC694.';
                        }
                        if (lawPanelOpenLink) {
                            lawPanelOpenLink.href = 'https://www.law.go.kr/';
                        }
                        renderLawPanelState(
                            'law-panel-error',
                            '\uAC80\uC0C9\uC5B4 \uC785\uB825 \uD544\uC694',
                            '\uC608: \uADFC\uB85C\uAE30\uC900\uBC95 \uB610\uB294 \uADFC\uB85C\uAE30\uC900\uBC95 \uC81C93\uC870'
                        );
                        lawPanelSearchInput.focus();
                        return;
                    }

                    loadLawPanel(queryText, buildLawSearchUrl(queryText));
                });
            }

            function bindLawRefLinkEvents() {
                if (!documentRoot) {
                    return;
                }

                documentRoot.querySelectorAll('.law-ref-link').forEach(function (link) {
                    if (link.dataset.boundLawClick === '1') {
                        return;
                    }

                    link.dataset.boundLawClick = '1';
                    link.addEventListener('click', function (event) {
                        var queryText = normalizeLawReference(String(link.dataset.lawQuery || link.textContent || ''));
                        if (!queryText || !lawPanelContent || !lawPanelQuery || !lawPanelOpenLink) {
                            return;
                        }

                        event.preventDefault();
                        loadLawPanel(queryText, link.href);
                    });
                });
            }

            function refreshLawReferences() {
                if (!documentRoot) {
                    return;
                }

                linkRelatedBasisReferences(documentRoot);
                rebuildArticleRelatedBasisLinks(documentRoot, 2);
                linkLawReferencesSafe(documentRoot);
                bindLawRefLinkEvents();
                updateLawLinkStatusCount();
            }

            function setupClauseEditorModal() {
                if (!documentRoot) {
                    return;
                }

                var modal = document.getElementById('rule-edit-modal');
                var closeBtn = document.getElementById('rule-edit-close');
                var cancelBtn = document.getElementById('rule-edit-cancel');
                var deleteBtn = document.getElementById('rule-edit-delete');
                var addArticleBtn = document.getElementById('rule-add-article');
                var saveBtn = document.getElementById('rule-edit-save');
                var textarea = document.getElementById('rule-edit-textarea');
                var insertAfterSelect = document.getElementById('rule-edit-insert-after');
                var insertAfterGroup = insertAfterSelect ? insertAfterSelect.closest('.rule-edit-group') : null;
                var basisTextarea = document.getElementById('rule-edit-basis-textarea');
                var modalTitle = document.getElementById('rule-edit-title');
                var editingClause = null;

                if (!modal || !closeBtn || !cancelBtn || !deleteBtn || !saveBtn || !textarea || !insertAfterSelect || !basisTextarea || !modalTitle || !insertAfterGroup || !addArticleBtn) {
                    return;
                }

                function setModalBusyState(isBusy, activeButton, pendingText) {
                    [deleteBtn, cancelBtn, saveBtn, closeBtn].forEach(function (button) {
                        button.disabled = isBusy;
                    });

                    [deleteBtn, saveBtn].forEach(function (button) {
                        if (!button.dataset.originalText) {
                            button.dataset.originalText = button.textContent;
                        }
                        button.textContent = isBusy && button === activeButton
                            ? pendingText
                            : button.dataset.originalText;
                    });
                }

                function closeModal() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                    editingClause = null;
                    setModalBusyState(false);
                }

                function setModalMode(mode) {
                    var isInsertMode = mode === 'insert';
                    modalTitle.textContent = isInsertMode ? '\uC0C8 \uC870\uD56D \uCD94\uAC00' : '\uC870\uBB38 \uB0B4\uC6A9 \uC218\uC815';
                    saveBtn.textContent = isInsertMode ? '\uCD94\uAC00' : '\uC800\uC7A5';
                    saveBtn.dataset.originalText = saveBtn.textContent;
                    deleteBtn.style.display = isInsertMode ? 'none' : '';
                    insertAfterGroup.classList.toggle('is-hidden', !isInsertMode);
                }

                function populateInsertAfterOptions(selectedHeading) {
                    var articleNodes = getArticleHeadingNodes();
                    insertAfterSelect.innerHTML = '';

                    articleNodes.forEach(function (node, index) {
                        var option = document.createElement('option');
                        option.value = String(index);
                        option.textContent = normalizeHeadingText(node.textContent || '') + ' \uB4A4\uC5D0 \uC0BD\uC785';
                        if (selectedHeading && node === selectedHeading) {
                            option.selected = true;
                        }
                        insertAfterSelect.appendChild(option);
                    });

                    insertAfterSelect.disabled = articleNodes.length === 0;
                }

                function getClauseDraftFromModal() {
                    var lines = textarea.value
                        .replace(/\r\n?/g, '\n')
                        .split('\n')
                        .map(function (line) {
                            return line.trim();
                        })
                        .filter(function (line) {
                            return line !== '';
                        });

                    var basisLines = basisTextarea.value
                        .replace(/\r\n?/g, '\n')
                        .split('\n')
                        .map(function (line) {
                            return line.trim();
                        })
                        .filter(function (line) {
                            return line !== '';
                        });

                    var normalizedLines = [];
                    lines.forEach(function (line) {
                        var movedInline = [];
                        var cleanedLine = line.replace(/\[\s*\uAD00\uB828\s*\uADFC\uAC70\s*[:\uFF1A]\s*([^\]]+)\]/gu, function (_, basisText) {
                            var normalized = String(basisText || '').trim();
                            if (normalized !== '') {
                                movedInline.push(normalized);
                            }
                            return '';
                        }).trim();

                        movedInline.forEach(function (basisText) {
                            splitRelatedBasisItems(basisText).forEach(function (item) {
                                basisLines.push(item);
                            });
                        });

                        if (/^\s*\[?\s*\uAD00\uB828\s*\uADFC\uAC70\s*[:\uFF1A]?/u.test(cleanedLine)) {
                            var extracted = cleanedLine
                                .replace(/^\s*\[?\s*\uAD00\uB828\s*\uADFC\uAC70\s*[:\uFF1A]?\s*/u, '')
                                .replace(/\]\s*$/u, '')
                                .trim();
                            if (extracted !== '') {
                                splitRelatedBasisItems(extracted).forEach(function (item) {
                                    basisLines.push(item);
                                });
                            }
                            return;
                        }

                        if (cleanedLine !== '') {
                            normalizedLines.push(cleanedLine);
                        }
                    });

                    if (normalizedLines.length === 0) {
                        return null;
                    }

                    return {
                        headingText: normalizedLines[0],
                        bodyLines: normalizedLines.slice(1),
                        basisLines: Array.from(new Set(basisLines)),
                    };
                }

                function buildClauseDomNodes(draft) {
                    var nodes = [];
                    var headingNode = document.createElement('h3');
                    headingNode.className = 'rule-editable';
                    headingNode.textContent = draft.headingText;
                    nodes.push(headingNode);

                    draft.bodyLines.forEach(function (line) {
                        var p = document.createElement('p');
                        p.textContent = line;
                        nodes.push(p);
                    });

                    if (draft.basisLines.length > 0) {
                        var basisParagraph = document.createElement('p');
                        basisParagraph.className = 'related-basis';
                        basisParagraph.textContent = '\uAD00\uB828\uADFC\uAC70: ' + draft.basisLines.join(', ');
                        nodes.push(basisParagraph);
                    }

                    return nodes;
                }

                function replaceExistingClause(clause, draft) {
                    var headingNode = clause.heading;
                    var oldNodes = clause.nodes || [headingNode];

                    headingNode.textContent = draft.headingText;
                    oldNodes.slice(1).forEach(function (node) {
                        if (node && node.parentNode) {
                            node.parentNode.removeChild(node);
                        }
                    });

                    var insertBeforeNode = headingNode.nextElementSibling;
                    draft.bodyLines.forEach(function (line) {
                        var p = document.createElement('p');
                        p.textContent = line;
                        headingNode.parentNode.insertBefore(p, insertBeforeNode);
                    });

                    if (draft.basisLines.length > 0) {
                        var basisParagraph = document.createElement('p');
                        basisParagraph.className = 'related-basis';
                        basisParagraph.textContent = '\uAD00\uB828\uADFC\uAC70: ' + draft.basisLines.join(', ');
                        headingNode.parentNode.insertBefore(basisParagraph, insertBeforeNode);
                    }
                }

                function insertClauseAfterHeading(afterHeading, draft) {
                    if (!afterHeading || !afterHeading.parentNode) {
                        return;
                    }

                    var parentNode = afterHeading.parentNode;
                    var insertBeforeNode = afterHeading.nextElementSibling;
                    while (insertBeforeNode && insertBeforeNode.tagName !== 'H2' && insertBeforeNode.tagName !== 'H3') {
                        insertBeforeNode = insertBeforeNode.nextElementSibling;
                    }

                    buildClauseDomNodes(draft).forEach(function (node) {
                        parentNode.insertBefore(node, insertBeforeNode);
                    });
                }

                function persistModalMutation(activeButton, pendingText, mutation) {
                    setModalBusyState(true, activeButton, pendingText);
                    try {
                        mutation();
                        syncDocumentStructure();
                        refreshLawReferences();
                    } catch (error) {
                        setModalBusyState(false);
                        alert(error && error.message ? error.message : '\uBCC0\uACBD\uC744 \uCC98\uB9AC\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4.');
                        return;
                    }

                    persistCurrentDocumentEdits()
                        .then(function () {
                            closeModal();
                        })
                        .catch(function (error) {
                            alert(error && error.message ? error.message : '\uC800\uC7A5\uC5D0 \uC2E4\uD328\uD588\uC2B5\uB2C8\uB2E4. \uC7A0\uC2DC \uD6C4 \uB2E4\uC2DC \uC2DC\uB3C4\uD574 \uC8FC\uC138\uC694.');
                        })
                        .finally(function () {
                            setModalBusyState(false);
                        });
                }

                function openModal(clause) {
                    editingClause = clause;
                    setModalMode(clause && clause.mode ? clause.mode : 'edit');
                    var clauseLines = clause.nodes
                        .map(function (node) {
                            return (node.textContent || '').trim();
                        })
                        .filter(function (line) {
                            return line !== '';
                        });

                    var contentLines = [];
                    var basisLines = [];

                    function extractInlineBasis(line) {
                        var extracted = [];
                        var cleaned = line.replace(/\[\s*\uAD00\uB828\s*\uADFC\uAC70\s*[:\uFF1A]\s*([^\]]+)\]/gu, function (_, basisText) {
                            var normalized = String(basisText || '').trim();
                            if (normalized !== '') {
                                extracted.push(normalized);
                            }
                            return '';
                        });

                        return {
                            cleaned: cleaned.trim(),
                            extracted: extracted,
                        };
                    }

                    clauseLines.forEach(function (line) {
                        if (/^\s*\[?\s*\uAD00\uB828\s*\uADFC\uAC70\s*[:\uFF1A]?/u.test(line)) {
                            var extracted = line
                                .replace(/^\s*\[?\s*\uAD00\uB828\s*\uADFC\uAC70\s*[:\uFF1A]?\s*/u, '')
                                .replace(/\]\s*$/u, '')
                                .trim();
                            if (extracted !== '') {
                                splitRelatedBasisItems(extracted).forEach(function (item) {
                                    basisLines.push(item);
                                });
                            }
                            return;
                        }

                        var inlineSplit = extractInlineBasis(line);
                        inlineSplit.extracted.forEach(function (basisText) {
                            splitRelatedBasisItems(basisText).forEach(function (item) {
                                basisLines.push(item);
                            });
                        });
                        if (inlineSplit.cleaned !== '') {
                            contentLines.push(inlineSplit.cleaned);
                        }
                    });

                    textarea.value = contentLines.join('\n');
                    basisTextarea.value = Array.from(new Set(basisLines)).join('\n');
                    populateInsertAfterOptions(clause.heading);

                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }

                function openInsertModal() {
                    var articleNodes = getArticleHeadingNodes();
                    if (articleNodes.length === 0) {
                        alert('\uC0BD\uC785\uD560 \uAE30\uC900 \uC870\uD56D\uC774 \uC5C6\uC2B5\uB2C8\uB2E4.');
                        return;
                    }

                    openModal({
                        mode: 'insert',
                        heading: articleNodes[articleNodes.length - 1],
                        nodes: [{ textContent: '' }],
                    });
                    textarea.value = '';
                    basisTextarea.value = '';
                    populateInsertAfterOptions(articleNodes[articleNodes.length - 1]);
                }

                markArticleHeadingsEditable();

                documentRoot.addEventListener('click', function (event) {
                    if (event.target.closest('.law-ref-link')) {
                        return;
                    }

                    var target = event.target.closest('h3');
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
                addArticleBtn.addEventListener('click', openInsertModal);

                deleteBtn.addEventListener('click', function () {
                    if (!editingClause || editingClause.mode === 'insert' || !editingClause.heading) {
                        closeModal();
                        return;
                    }

                    if (!window.confirm('\uC774 \uC870\uD56D\uC744 \uC0AD\uC81C\uD560\uAE4C\uC694?')) {
                        return;
                    }

                    persistModalMutation(deleteBtn, '\uC0AD\uC81C \uC911...', function () {
                        (editingClause.nodes || []).forEach(function (node) {
                            if (node && node.parentNode) {
                                node.parentNode.removeChild(node);
                            }
                        });
                    });
                });

                saveBtn.addEventListener('click', function () {
                    if (!editingClause || !editingClause.heading) {
                        closeModal();
                        return;
                    }

                    var draft = getClauseDraftFromModal();
                    if (!draft) {
                        alert(editingClause.mode === 'insert'
                            ? '\uC0C8 \uC870\uD56D \uB0B4\uC6A9\uC744 \uC785\uB825\uD574 \uC8FC\uC138\uC694.'
                            : '\uC218\uC815\uD560 \uB0B4\uC6A9\uC744 \uC785\uB825\uD574 \uC8FC\uC138\uC694.');
                        textarea.focus();
                        return;
                    }

                    if (editingClause.mode === 'insert') {
                        var articleNodes = getArticleHeadingNodes();
                        var targetIndex = parseInt(insertAfterSelect.value || '-1', 10);
                        var targetHeading = articleNodes[targetIndex] || editingClause.heading;
                        if (!targetHeading) {
                            alert('\uC0BD\uC785\uD560 \uC870\uD56D \uC704\uCE58\uB97C \uC120\uD0DD\uD574 \uC8FC\uC138\uC694.');
                            insertAfterSelect.focus();
                            return;
                        }

                        persistModalMutation(saveBtn, '\uCD94\uAC00 \uC911...', function () {
                            insertClauseAfterHeading(targetHeading, draft);
                        });
                        return;
                    }

                    persistModalMutation(saveBtn, '\uC800\uC7A5 \uC911...', function () {
                        replaceExistingClause(editingClause, draft);
                    });
                });

                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        closeModal();
                    }
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                        closeModal();
                    }
                });
            }

            refreshLawReferences();
            setupClauseEditorModal();
        }());
    </script>
</body>
</html>

