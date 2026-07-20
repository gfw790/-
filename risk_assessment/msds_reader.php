<?php
require_once __DIR__ . '/auth.php';

$user = auth_current_user();
if ($user === null) {
    header('Location: task_select.php');
    exit;
}

$canEditMobileMsds = is_array($user);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function msds_reader_storage_path(): string
{
    return __DIR__ . '/msds_records.json';
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
        if (preg_match('/^\d{1,2}\.\s+/u', $line)) {
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

function msds_reader_server_pictogram_keys(string $sourceText): array
{
    $normalized = preg_replace('/\s+/u', ' ', trim($sourceText)) ?? trim($sourceText);
    $keys = [];

    if (preg_match('/(고압가스|압축가스|액화가스|냉동액화가스|용해가스|H280|H281)/iu', $normalized)) {
        $keys[] = 'gas-cylinder';
    }

    return array_values(array_unique($keys));
}

function msds_reader_server_is_pictogram_label(string $line): bool
{
    return in_array(trim($line), ['가스실린더'], true);
}

function msds_reader_server_render_pictogram_html(string $sourceText): string
{
    $keys = msds_reader_server_pictogram_keys($sourceText);
    if ($keys === []) {
        return '<div class="mobile-text-fallback-detail">그림문자 정보가 없습니다.</div>';
    }

    $items = [];
    foreach ($keys as $key) {
        if ($key !== 'gas-cylinder') {
            continue;
        }

        $items[] = '<div class="mobile-pictogram-item">'
            . '<svg class="mobile-pictogram-svg" viewBox="0 0 88 88" aria-hidden="true" focusable="false">'
            . '<rect x="16" y="16" width="56" height="56" transform="rotate(45 44 44)" fill="#ffffff" stroke="#e6331f" stroke-width="5.5"/>'
            . '<g transform="rotate(-14 44 44)">'
            . '<rect x="23" y="38" width="33" height="10" rx="3.6" fill="#211a18"/>'
            . '<rect x="54.8" y="40.4" width="11.6" height="4.5" rx="1.8" fill="#211a18"/>'
            . '</g>'
            . '</svg>'
            . '<div class="mobile-pictogram-label">가스실린더</div>'
            . '</div>';
    }

    if ($items === []) {
        return '<div class="mobile-text-fallback-detail">그림문자 정보가 없습니다.</div>';
    }

    return '<div class="mobile-pictogram-card is-inline">'
        . '<div class="mobile-pictogram-title">그림문자</div>'
        . '<div class="mobile-pictogram-list">' . implode('', $items) . '</div>'
        . '</div>';
}

function msds_reader_render_fallback_html(array $sections, bool $canEditMobileMsds = false): string
{
    if ($sections === []) {
        return '<div class="mobile-text-empty">표시할 본문이 없습니다.</div>';
    }

    $html = '';
    foreach ($sections as $section) {
        $rawTitle = trim((string)($section['title'] ?? ''));
        $title = h($rawTitle);
        $bodyLines = array_values(array_filter(
            array_map('trim', explode("\n", (string)($section['body'] ?? ''))),
            static fn ($line) => $line !== ''
        ));
        $bodyChunks = [];
        foreach ($bodyLines as $line) {
            $escaped = h($line);
            if ($line === '그림문자') {
                $bodyChunks[] = msds_reader_server_render_pictogram_html(
                    implode("\n", array_merge([$rawTitle], $bodyLines))
                );
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
                $label = h(trim((string)$matches[1]));
                $value = h(trim((string)$matches[2]));
                $prefixClass = preg_match('/^\d+\)\s*/u', $line) ? ' mobile-text-fallback-kv-detail' : '';
                $bodyChunks[] = '<div class="mobile-text-fallback-kv' . $prefixClass . '">'
                    . '<span class="mobile-text-fallback-kv-label">' . $label . '</span>'
                    . '<span class="mobile-text-fallback-kv-value">' . $value . '</span>'
                    . '</div>';
                continue;
            }

            if (preg_match('/^\d+\)\s*/u', $line)) {
                $bodyChunks[] = '<div class="mobile-text-fallback-detail">' . $escaped . '</div>';
                continue;
            }

            $bodyChunks[] = '<p class="mobile-text-paragraph mobile-text-fallback-body">' . $escaped . '</p>';
        }
        $articleClass = $canEditMobileMsds ? 'mobile-text-section is-editable' : 'mobile-text-section';
        $html .= '<article class="' . $articleClass . '">';
        if ($canEditMobileMsds) {
            $html .= '<button class="mobile-card-edit" type="button" aria-label="이 카드 수정">수정</button>';
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
$ocrStatus = (string)($record['ocr_status'] ?? '');
$ocrEngine = (string)($record['ocr_engine'] ?? '');
$ocrText = (string)($record['ocr_text'] ?? '');
$ocrSections = is_array($record['ocr_sections'] ?? null) ? $record['ocr_sections'] : [];
$ocrError = (string)($record['ocr_error'] ?? '');
$pdfUrl = $record !== null ? 'msds_list.php?view_file=' . rawurlencode((string)($record['id'] ?? '')) : '';
$downloadUrl = $record !== null ? 'msds_list.php?download_file=' . rawurlencode((string)($record['id'] ?? '')) : 'msds_list.php';
$serverRenderSource = $mobileContent !== '' ? $mobileContent : $ocrText;
$serverRenderSections = msds_reader_fallback_sections($serverRenderSource);
$serverRenderHtml = msds_reader_render_fallback_html($serverRenderSections, $canEditMobileMsds);
$serverRenderStatus = $mobileContent !== ''
    ? '관리자가 정리한 모바일 전용 본문입니다.'
    : ($ocrText !== '' ? '서버 OCR 텍스트를 불러왔습니다.' : '표시할 본문을 준비하지 못했습니다.');
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
<?php require __DIR__ . '/templates/msds_reader/body.php'; ?>
<?php if ($record !== null && $isPdf): ?>
<?php require __DIR__ . '/templates/msds_reader/mobile_edit_script.php'; ?>
<?php require __DIR__ . '/templates/msds_reader/pdf_reader_script.php'; ?>
<?php endif; ?>
</body>
</html>
