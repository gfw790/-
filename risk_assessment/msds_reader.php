<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$currentUserName = is_array($user) ? trim((string)($user['name'] ?? '')) : '';
$canEditMobileMsds = is_array($user) && $currentUserName === '김남균';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function msds_reader_storage_path(): string
{
    return __DIR__ . '/msds_records.json';
}

function msds_reader_ghs_asset_paths(): array
{
    return [
        'flame' => 'A:\\risk_server\\data\\GHS\\2.인화성_물반응성_자기반응성_자연발화성_자기발열성_유기과산화물.gif',
        'gas-cylinder' => 'A:\\risk_server\\data\\GHS\\7.고압가스.gif',
        'exclamation' => 'A:\\risk_server\\data\\GHS\\9.경고.gif',
        'health-hazard' => 'A:\\risk_server\\data\\GHS\\4.호흡기과민성_발암성_생식세포변이원성_생식독성_특정표적장기독성.gif',
    ];
}

function msds_reader_ghs_image_data_uri(string $key): string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $paths = msds_reader_ghs_asset_paths();
    $path = $paths[$key] ?? '';
    if ($path === '' || !is_file($path)) {
        $cache[$key] = '';
        return '';
    }

    $binary = file_get_contents($path);
    if ($binary === false || $binary === '') {
        $cache[$key] = '';
        return '';
    }

    $cache[$key] = 'data:image/gif;base64,' . base64_encode($binary);
    return $cache[$key];
}

function msds_reader_ghs_image_map(): array
{
    $map = [];
    foreach (array_keys(msds_reader_ghs_asset_paths()) as $key) {
        $map[$key] = msds_reader_ghs_image_data_uri($key);
    }
    return $map;
}

function msds_reader_read_records(): array
{
    $path = msds_reader_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function msds_reader_find_record(array $records, string $id): ?array
{
    foreach ($records as $record) {
        if ((string)($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

function msds_reader_find_record_index(array $records, string $id): ?int
{
    foreach ($records as $index => $record) {
        if ((string)($record['id'] ?? '') === $id) {
            return $index;
        }
    }

    return null;
}

function msds_reader_write_records(array $records): bool
{
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) && file_put_contents(msds_reader_storage_path(), $json, LOCK_EX) !== false;
}

function msds_reader_extension(array $record): string
{
    $originalName = trim((string)($record['original_name'] ?? ''));
    $storedName = trim((string)($record['stored_name'] ?? ''));
    $candidate = $originalName !== '' ? $originalName : $storedName;
    return strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
}

function msds_reader_normalize_block_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", trim($value));
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    $value = preg_replace("/\n{3,}/u", "\n\n", $value) ?? $value;
    $lines = array_map('trim', explode("\n", $value));
    $lines = array_values(array_filter($lines, static function ($line) {
        return $line !== '' && !in_array($line, ['본문', '추출 본문'], true);
    }));
    return trim(implode("\n", $lines));
}

function msds_reader_normalize_pictogram_key(string $value): string
{
    $normalized = trim(mb_strtolower($value, 'UTF-8'));

    return match ($normalized) {
        'flame', '인화성', '불꽃' => 'flame',
        'gas-cylinder', '고압가스', '가스실린더' => 'gas-cylinder',
        'exclamation', '경고', '느낌표' => 'exclamation',
        'health-hazard', '건강유해성' => 'health-hazard',
        default => '',
    };
}

function msds_reader_normalize_pictogram_exclusions(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) {
            continue;
        }

        $key = msds_reader_normalize_pictogram_key((string)$item);
        if ($key !== '') {
            $normalized[] = $key;
        }
    }

    return array_values(array_unique($normalized));
}

function msds_reader_is_top_level_section_heading(string $line): bool
{
    if (preg_match('/^(\d{1,2})\.\s+(.+)$/u', trim($line), $matches) !== 1) {
        return false;
    }

    $number = (int)$matches[1];
    $title = preg_replace('/\s+/u', '', trim((string)$matches[2])) ?? trim((string)$matches[2]);
    if ($number < 1 || $number > 16 || $title === '') {
        return false;
    }

    if (str_contains($title, ':') || str_contains($title, '：')) {
        return false;
    }

    $keywordsByNumber = [
        1 => ['화학제품과회사', '화학제품과회사에관한정보'],
        2 => ['유해성·위험성', '유해성위험성'],
        3 => ['구성성분의명칭및함유량', '구성성분'],
        4 => ['응급조치요령', '응급조치'],
        5 => ['폭발·화재시대처방법', '폭발화재시대처방법', '폭발·화재대처방법'],
        6 => ['누출사고시대처방법', '누출사고대처방법', '누출시대처방법'],
        7 => ['취급및저장방법', '취급저장방법', '취급및저장'],
        8 => ['노출방지및개인보호구', '노출방지및개인보호'],
        9 => ['물리화학적특성'],
        10 => ['안정성및반응성'],
        11 => ['독성에관한정보', '독성정보'],
        12 => ['환경에미치는영향', '환경영향정보', '환경에관한정보'],
        13 => ['폐기시주의사항', '폐기상의주의사항'],
        14 => ['운송에필요한정보', '운송정보'],
        15 => ['법적규제현황', '법적규제'],
        16 => ['그밖의참고사항', '기타참고사항', '기타정보'],
    ];

    foreach ($keywordsByNumber[$number] ?? [] as $keyword) {
        if ($keyword !== '' && str_contains($title, $keyword)) {
            return true;
        }
    }

    return false;
}

function msds_reader_normalize_glossary(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $term = trim((string)($item['term'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        $content = trim((string)($item['content'] ?? ''));

        if ($term === '' || $content === '') {
            continue;
        }

        $normalized[] = [
            'term' => $term,
            'title' => $title !== '' ? $title : $term,
            'content' => $content,
        ];
    }

    return array_values($normalized);
}

function msds_reader_glossary_match_key(string $value): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($value));
    $normalized = preg_replace('/^[\-\•\●\○\▪\‣\◦]+\s*/u', '', $normalized) ?? $normalized;
    $normalized = str_replace('：', ':', $normalized);
    $normalized = preg_replace('/\s*:\s*/u', ':', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
    return $normalized;
}

function msds_reader_glossary_loose_key(string $value): string
{
    $normalized = msds_reader_glossary_match_key($value);
    $normalized = preg_replace('/[^[:alnum:][:alpha:]\p{Hangul}]+/u', '', $normalized) ?? $normalized;
    return mb_strtolower($normalized, 'UTF-8');
}

function msds_reader_find_glossary_entry(array $glossary, string $text): ?array
{
    $targetKey = msds_reader_glossary_match_key($text);
    if ($targetKey === '') {
        return null;
    }

    $targetLooseKey = msds_reader_glossary_loose_key($text);
    $bestEntry = null;
    $bestScore = -1;

    foreach (array_values($glossary) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $term = trim((string)($entry['term'] ?? ''));
        $content = trim((string)($entry['content'] ?? ''));
        if ($term === '' || $content === '') {
            continue;
        }

        $entryKey = msds_reader_glossary_match_key($term);
        if ($entryKey === $targetKey) {
            $entry['_index'] = $index;
            return $entry;
        }

        $entryLooseKey = msds_reader_glossary_loose_key($term);
        $score = -1;

        if ($entryLooseKey !== '' && $entryLooseKey === $targetLooseKey) {
            $score = 900;
        } elseif (
            $entryKey !== ''
            && (
                str_contains($entryKey, $targetKey)
                || str_contains($targetKey, $entryKey)
            )
        ) {
            $score = 700;
        } elseif (
            $entryLooseKey !== ''
            && $targetLooseKey !== ''
            && (
                str_contains($entryLooseKey, $targetLooseKey)
                || str_contains($targetLooseKey, $entryLooseKey)
            )
        ) {
            $score = 600;
        }

        if ($score > $bestScore) {
            $entry['_index'] = $index;
            $bestEntry = $entry;
            $bestScore = $score;
        }
    }

    return $bestEntry;
}

function msds_reader_render_glossary_button(array $entry, string $label): string
{
    $index = (int)($entry['_index'] ?? 0);
    $term = trim((string)($entry['term'] ?? ''));
    return '<a class="mobile-glossary-trigger" href="#mobile-glossary-entry-' . $index . '" data-glossary-index="' . $index . '" data-glossary-term="' . h($term) . '">'
        . h($label)
        . '</a>';
}

function msds_reader_fallback_sections(string $rawText): array
{
    $normalized = msds_reader_normalize_block_text($rawText);
    if ($normalized === '') {
        return [];
    }

    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $normalized)),
        static fn ($line) => $line !== '' && !preg_match('/^-\s*\d+\s*-$/u', $line)
    ));

    $sections = [];
    $current = null;

    foreach ($lines as $line) {
        if (msds_reader_is_top_level_section_heading($line)) {
            if ($current !== null) {
                if ($current['body'] === []) {
                    $current['body'][] = '내용이 없습니다.';
                }
                $sections[] = [
                    'title' => $current['title'],
                    'body' => implode("\n", $current['body']),
                ];
            }

            $current = [
                'title' => $line,
                'body' => [],
            ];
            continue;
        }

        if ($current === null) {
            $current = [
                'title' => '',
                'body' => [],
            ];
        }

        $current['body'][] = $line;
    }

    if ($current !== null) {
        if ($current['body'] === []) {
            $current['body'][] = '내용이 없습니다.';
        }
        $sections[] = [
            'title' => $current['title'],
            'body' => implode("\n", $current['body']),
        ];
    }

    return $sections;
}

function msds_reader_has_structured_mobile_content(string $content): bool
{
    $normalized = msds_reader_normalize_block_text($content);
    if ($normalized === '') {
        return false;
    }

    foreach (explode("\n", $normalized) as $line) {
        if (msds_reader_is_top_level_section_heading($line)) {
            return true;
        }
    }

    return false;
}

function msds_reader_serialize_sections(array $sections): string
{
    $chunks = [];

    foreach (array_values($sections) as $section) {
        if (!is_array($section)) {
            continue;
        }

        $title = trim((string)($section['title'] ?? ''));
        $body = msds_reader_normalize_block_text((string)($section['body'] ?? ''));
        $chunkParts = array_values(array_filter([$title, $body], static fn ($value) => $value !== ''));
        if ($chunkParts !== []) {
            $chunks[] = implode("\n", $chunkParts);
        }
    }

    return trim(implode("\n\n", $chunks));
}

function msds_reader_edit_section_url(string $recordId, int $sectionNumber, bool $saved = false): string
{
    $params = [
        'id' => $recordId,
        'edit_section' => max(1, $sectionNumber),
    ];

    if ($saved) {
        $params['saved'] = '1';
    }

    return 'msds_reader.php?' . http_build_query($params) . '#msds-section-editor';
}

function msds_reader_glossary_editor_url(string $recordId, bool $saved = false): string
{
    $params = [
        'id' => $recordId,
        'glossary_editor' => '1',
    ];

    if ($saved) {
        $params['glossary_saved'] = '1';
    }

    return 'msds_reader.php?' . http_build_query($params) . '#msds-glossary-editor';
}

function msds_reader_server_pictogram_keys(string $sourceText, array $excludeKeys = []): array
{
    $normalized = preg_replace('/\s+/u', ' ', trim($sourceText)) ?? trim($sourceText);
    $keys = [];

    if (preg_match('/(인화성 가스|극인화성 가스|인화성 액체|H220|H221|H224|H225|H226)/iu', $normalized)) {
        $keys[] = 'flame';
    }

    if (preg_match('/(고압가스|압축가스|액화가스|냉동액화가스|용해가스|H280|H281)/iu', $normalized)) {
        $keys[] = 'gas-cylinder';
    }

    if (preg_match('/(급성 독성\(흡입|피부 부식성\/피부 자극성|눈 자극성|심한 눈 손상성\/눈 자극성|호흡기계자극|H315|H319|H332|H335)/iu', $normalized)) {
        $keys[] = 'exclamation';
    }

    if (preg_match('/(발암성|생식세포 변이원성|특정표적장기 독성\(반복 노출\)|H340|H341|H350|H351|H372|H373)/iu', $normalized)) {
        $keys[] = 'health-hazard';
    }

    $excludeLookup = array_fill_keys(msds_reader_normalize_pictogram_exclusions($excludeKeys), true);
    $keys = array_values(array_unique($keys));

    if ($excludeLookup !== []) {
        $keys = array_values(array_filter($keys, static fn (string $key): bool => !isset($excludeLookup[$key])));
    }

    return $keys;
}

function msds_reader_server_is_pictogram_label(string $line): bool
{
    return in_array(trim($line), ['고압가스', '인화성', '경고', '건강유해성', '가스실린더', '불꽃', '느낌표'], true);
}

function msds_reader_server_render_pictogram_html(string $sourceText, string $titleText = '그림문자', array $excludeKeys = []): string
{
    $keys = msds_reader_server_pictogram_keys($sourceText, $excludeKeys);
    if ($keys === []) {
        return '<div class="mobile-text-fallback-detail">그림문자 정보가 없습니다.</div>';
    }

    $imageMap = msds_reader_ghs_image_map();
    $items = [];
    foreach ($keys as $key) {
        if ($key === 'flame') {
            $items[] = '<div class="mobile-pictogram-item">'
                . '<img class="mobile-pictogram-image" src="' . h($imageMap['flame'] ?? '') . '" alt="인화성 그림문자" loading="lazy">'
                . '<div class="mobile-pictogram-label">인화성</div>'
                . '</div>';
            continue;
        }

        if ($key === 'gas-cylinder') {
            $items[] = '<div class="mobile-pictogram-item">'
                . '<img class="mobile-pictogram-image" src="' . h($imageMap['gas-cylinder'] ?? '') . '" alt="고압가스 그림문자" loading="lazy">'
                . '<div class="mobile-pictogram-label">고압가스</div>'
                . '</div>';
            continue;
        }

        if ($key === 'exclamation') {
            $items[] = '<div class="mobile-pictogram-item">'
                . '<img class="mobile-pictogram-image" src="' . h($imageMap['exclamation'] ?? '') . '" alt="경고 그림문자" loading="lazy">'
                . '<div class="mobile-pictogram-label">경고</div>'
                . '</div>';
            continue;
        }

        if ($key === 'health-hazard') {
            $items[] = '<div class="mobile-pictogram-item">'
                . '<img class="mobile-pictogram-image" src="' . h($imageMap['health-hazard'] ?? '') . '" alt="건강유해성 그림문자" loading="lazy">'
                . '<div class="mobile-pictogram-label">건강유해성</div>'
                . '</div>';
        }
    }

    if ($items === []) {
        return '<div class="mobile-text-fallback-detail">그림문자 정보가 없습니다.</div>';
    }

    $titleHtml = $titleText !== '' ? '<div class="mobile-pictogram-title">' . h($titleText) . '</div>' : '';

    return '<div class="mobile-pictogram-card is-inline">'
        . $titleHtml
        . '<div class="mobile-pictogram-list">' . implode('', $items) . '</div>'
        . '</div>';
}

function msds_reader_render_fallback_html(array $sections, bool $canEditMobileMsds = false, array $glossary = [], string $recordId = '', array $pictogramExcludeKeys = []): string
{
    if ($sections === []) {
        return '<div class="mobile-text-empty">표시할 본문이 없습니다.</div>';
    }

    $html = '';
    foreach (array_values($sections) as $index => $section) {
        $rawTitle = trim((string)($section['title'] ?? ''));
        $title = h($rawTitle);
        $bodyLines = array_values(array_filter(
            array_map('trim', explode("\n", (string)($section['body'] ?? ''))),
            static fn ($line) => $line !== ''
        ));
        $bodyChunks = [];
        foreach ($bodyLines as $line) {
            $escaped = h($line);
            $glossaryEntry = msds_reader_find_glossary_entry($glossary, $line);
            $indicatorMatch = preg_match('/^[○●]\s*(그림문자|신호어|유해·위험 문구|유해·위험문구|예방조치문구)$/u', $line, $indicatorMatches) === 1
                ? ($indicatorMatches[1] === '유해·위험 문구' ? '유해·위험문구' : $indicatorMatches[1])
                : null;
            if ($line === '그림문자' || $line === '1) 그림문자' || $indicatorMatch === '그림문자') {
                $labelText = ($line === '1) 그림문자' || $line === '그림문자') ? ($line === '그림문자' ? '1) 그림문자' : $line) : '1) 그림문자';
                $bodyChunks[] = '<div class="mobile-text-fallback-detail">' . h($labelText) . '</div>'
                    . msds_reader_server_render_pictogram_html(
                        implode("\n", array_merge([$rawTitle], $bodyLines)),
                        '',
                        $pictogramExcludeKeys
                    );
                continue;
            }

            if ($indicatorMatch !== null) {
                $headingMap = [
                    '신호어' => '2) 신호어',
                    '유해·위험문구' => '3) 유해·위험문구',
                    '예방조치문구' => '4) 예방조치문구',
                ];
                $bodyChunks[] = '<div class="mobile-text-fallback-detail">' . h($headingMap[$indicatorMatch] ?? $indicatorMatch) . '</div>';
                continue;
            }

            if (msds_reader_server_is_pictogram_label($line)) {
                continue;
            }

            if (preg_match('/^[가-하]\.\s*/u', $line)) {
                $bodyChunks[] = '<div class="mobile-text-fallback-subhead">' . $escaped . '</div>';
                continue;
            }

            if (preg_match('/^(.{1,80}?[:：])\s*(.+)$/u', $line, $matches)) {
                if ($glossaryEntry !== null) {
                    $prefixClass = preg_match('/^\d+\)\s*/u', $line) ? ' mobile-text-fallback-kv-detail' : '';
                    $bodyChunks[] = '<div class="mobile-text-fallback-kv has-glossary-trigger' . $prefixClass . '">'
                        . msds_reader_render_glossary_button($glossaryEntry, $line)
                        . '</div>';
                } else {
                    $label = h(trim((string)$matches[1]));
                    $value = h(trim((string)$matches[2]));
                    $prefixClass = preg_match('/^\d+\)\s*/u', $line) ? ' mobile-text-fallback-kv-detail' : '';
                    $bodyChunks[] = '<div class="mobile-text-fallback-kv' . $prefixClass . '">'
                        . '<span class="mobile-text-fallback-kv-label">' . $label . '</span>'
                        . '<span class="mobile-text-fallback-kv-value">' . $value . '</span>'
                        . '</div>';
                }
                continue;
            }

            if (preg_match('/^\d+\)\s*/u', $line)) {
                if ($glossaryEntry !== null) {
                    $bodyChunks[] = '<div class="mobile-text-fallback-detail">'
                        . msds_reader_render_glossary_button($glossaryEntry, $line)
                        . '</div>';
                } else {
                    $bodyChunks[] = '<div class="mobile-text-fallback-detail">' . $escaped . '</div>';
                }
                continue;
            }

            if ($glossaryEntry !== null) {
                $bodyChunks[] = '<p class="mobile-text-paragraph mobile-text-fallback-body">'
                    . msds_reader_render_glossary_button($glossaryEntry, $line)
                    . '</p>';
            } else {
                $bodyChunks[] = '<p class="mobile-text-paragraph mobile-text-fallback-body">' . $escaped . '</p>';
            }
        }
        $sectionId = 'mobile-msds-section-' . ($index + 1);
        $articleClass = $canEditMobileMsds ? 'mobile-text-section is-editable' : 'mobile-text-section';
        $articleAttrs = ' class="' . $articleClass . '" id="' . h($sectionId) . '"';
        $html .= '<article' . $articleAttrs . '>';
        if ($canEditMobileMsds) {
            $editUrl = $recordId !== '' ? msds_reader_edit_section_url($recordId, $index + 1) : '#';
            $html .= '<a class="mobile-card-edit" href="' . h($editUrl) . '" aria-label="이 카드 수정">수정</a>';
        }
        if ($rawTitle !== '') {
            $html .= '<h3>' . $title . '</h3>';
        }
        $html .= implode('', $bodyChunks);
        $html .= '</article>';
    }

    return $html;
}

$records = msds_reader_read_records();
$recordId = trim((string)($_GET['id'] ?? ''));
$record = $recordId !== '' ? msds_reader_find_record($records, $recordId) : null;
$isPdf = $record !== null && msds_reader_extension($record) === 'pdf';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_mobile_content') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!$canEditMobileMsds) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '편집 권한이 없습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $targetId = trim((string)($_POST['record_id'] ?? ''));
    $content = msds_reader_normalize_block_text((string)($_POST['content'] ?? ''));
    $recordIndex = msds_reader_find_record_index($records, $targetId);

    if ($targetId === '' || $recordIndex === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => '대상 문서를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $records[$recordIndex]['mobile_content'] = $content;
    $records[$recordIndex]['mobile_content_updated_at'] = date('c');

    if (!msds_reader_write_records($records)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => '편집 내용을 저장하지 못했습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => '저장되었습니다.',
        'content' => $content,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_mobile_glossary') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!$canEditMobileMsds) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '편집 권한이 없습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $targetId = trim((string)($_POST['record_id'] ?? ''));
    $recordIndex = msds_reader_find_record_index($records, $targetId);
    $items = json_decode((string)($_POST['glossary'] ?? '[]'), true);

    if ($targetId === '' || $recordIndex === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => '대상 문서를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!is_array($items)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => '용어 데이터를 읽지 못했습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $glossary = msds_reader_normalize_glossary($items);
    $records[$recordIndex]['mobile_glossary'] = $glossary;
    $records[$recordIndex]['mobile_glossary_updated_at'] = date('c');

    if (!msds_reader_write_records($records)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => '용어 설명을 저장하지 못했습니다.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => '저장되었습니다.',
        'glossary' => $glossary,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_mobile_glossary_form') {
    if (!$canEditMobileMsds) {
        http_response_code(403);
        exit('편집 권한이 없습니다.');
    }

    $targetId = trim((string)($_POST['record_id'] ?? ''));
    $recordIndex = msds_reader_find_record_index($records, $targetId);

    if ($targetId === '' || $recordIndex === null) {
        http_response_code(404);
        exit('대상 문서를 찾지 못했습니다.');
    }

    $terms = is_array($_POST['glossary_term'] ?? null) ? $_POST['glossary_term'] : [];
    $titles = is_array($_POST['glossary_title'] ?? null) ? $_POST['glossary_title'] : [];
    $contents = is_array($_POST['glossary_content'] ?? null) ? $_POST['glossary_content'] : [];
    $items = [];
    $rowCount = max(count($terms), count($titles), count($contents));

    for ($index = 0; $index < $rowCount; $index += 1) {
        $items[] = [
            'term' => trim((string)($terms[$index] ?? '')),
            'title' => trim((string)($titles[$index] ?? '')),
            'content' => trim((string)($contents[$index] ?? '')),
        ];
    }

    $glossary = msds_reader_normalize_glossary($items);
    $records[$recordIndex]['mobile_glossary'] = $glossary;
    $records[$recordIndex]['mobile_glossary_updated_at'] = date('c');

    if (!msds_reader_write_records($records)) {
        http_response_code(500);
        exit('용어 설명을 저장하지 못했습니다.');
    }

    header('Location: ' . msds_reader_glossary_editor_url($targetId, true));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_mobile_section') {
    if (!$canEditMobileMsds) {
        http_response_code(403);
        exit('편집 권한이 없습니다.');
    }

    $targetId = trim((string)($_POST['record_id'] ?? ''));
    $sectionNumber = max(1, (int)($_POST['section_number'] ?? 0));
    $sectionTitle = trim((string)($_POST['section_title'] ?? ''));
    $sectionBody = msds_reader_normalize_block_text((string)($_POST['section_body'] ?? ''));
    $recordIndex = msds_reader_find_record_index($records, $targetId);

    if ($targetId === '' || $recordIndex === null) {
        http_response_code(404);
        exit('대상 문서를 찾지 못했습니다.');
    }

    $currentContent = (string)($records[$recordIndex]['mobile_content'] ?? '');
    $currentSource = msds_reader_has_structured_mobile_content($currentContent)
        ? $currentContent
        : (string)($records[$recordIndex]['ocr_text'] ?? '');
    $sections = msds_reader_fallback_sections($currentSource);
    $sectionIndex = $sectionNumber - 1;

    if (!isset($sections[$sectionIndex])) {
        http_response_code(404);
        exit('수정할 항목을 찾지 못했습니다.');
    }

    $sections[$sectionIndex]['title'] = $sectionTitle;
    $sections[$sectionIndex]['body'] = $sectionBody !== '' ? $sectionBody : '내용이 없습니다.';

    $records[$recordIndex]['mobile_content'] = msds_reader_serialize_sections($sections);
    $records[$recordIndex]['mobile_content_updated_at'] = date('c');

    if (!msds_reader_write_records($records)) {
        http_response_code(500);
        exit('편집 내용을 저장하지 못했습니다.');
    }

    header('Location: ' . msds_reader_edit_section_url($targetId, $sectionNumber, true));
    exit;
}

if ($record === null || !$isPdf) {
    http_response_code(404);
}

$materialName = (string)($record['material_name'] ?? '');
$manufacturer = (string)($record['manufacturer'] ?? '');
$createdDate = (string)($record['created_date'] ?? '');
$revisedDate = (string)($record['revised_date'] ?? '');
$revisionCount = (string)($record['revision_count'] ?? '');
$note = (string)($record['note'] ?? '');
$mobileContent = (string)($record['mobile_content'] ?? '');
$hasStructuredMobileContent = msds_reader_has_structured_mobile_content($mobileContent);
$ocrStatus = (string)($record['ocr_status'] ?? '');
$ocrEngine = (string)($record['ocr_engine'] ?? '');
$ocrText = (string)($record['ocr_text'] ?? '');
$ocrSections = is_array($record['ocr_sections'] ?? null) ? $record['ocr_sections'] : [];
$ocrError = (string)($record['ocr_error'] ?? '');
$mobileGlossary = msds_reader_normalize_glossary(is_array($record['mobile_glossary'] ?? null) ? $record['mobile_glossary'] : []);
$mobilePictogramExcludeKeys = msds_reader_normalize_pictogram_exclusions($record['mobile_pictogram_exclude'] ?? null);
$pdfUrl = $record !== null ? 'msds_list.php?view_file=' . rawurlencode((string)($record['id'] ?? '')) : '';
$downloadUrl = $record !== null ? 'msds_list.php?download_file=' . rawurlencode((string)($record['id'] ?? '')) : 'msds_list.php';
$serverRenderSource = $hasStructuredMobileContent ? $mobileContent : $ocrText;
$serverRenderSections = msds_reader_fallback_sections($serverRenderSource);
$serverRenderHtml = msds_reader_render_fallback_html($serverRenderSections, $canEditMobileMsds, $mobileGlossary, $recordId, $mobilePictogramExcludeKeys);
$msdsPictogramImages = msds_reader_ghs_image_map();
$serverRenderStatus = $hasStructuredMobileContent
    ? '관리자가 정리한 모바일 전용 본문입니다.'
    : ($ocrText !== '' ? '서버 OCR 텍스트를 불러왔습니다.' : '표시할 본문을 준비하지 못했습니다.');
$editSectionNumber = max(0, (int)($_GET['edit_section'] ?? 0));
$editSection = ($canEditMobileMsds && $editSectionNumber >= 1 && isset($serverRenderSections[$editSectionNumber - 1]))
    ? $serverRenderSections[$editSectionNumber - 1]
    : null;
$editSectionBody = $editSection !== null ? msds_reader_normalize_block_text((string)($editSection['body'] ?? '')) : '';
$editSectionTitle = $editSection !== null ? trim((string)($editSection['title'] ?? '')) : '';
$editSectionSaved = (string)($_GET['saved'] ?? '') === '1';
$glossaryEditorOpen = $canEditMobileMsds && (string)($_GET['glossary_editor'] ?? '') === '1';
$glossaryEditorSaved = (string)($_GET['glossary_saved'] ?? '') === '1';
$glossaryEditorRows = array_merge(
    [['term' => '', 'title' => '', 'content' => '']],
    $mobileGlossary
);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>MSDS 보기</title>
<?php require __DIR__ . '/templates/msds_reader/styles.php'; ?>
</head>
<body>
<?php if ($glossaryEditorOpen): ?>
<?php require __DIR__ . '/templates/msds_reader/glossary_editor_page.php'; ?>
<?php else: ?>
<?php require __DIR__ . '/templates/msds_reader/body.php'; ?>
<?php if ($record !== null && $isPdf): ?>
<?php require __DIR__ . '/templates/msds_reader/pdf_reader_script.php'; ?>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
