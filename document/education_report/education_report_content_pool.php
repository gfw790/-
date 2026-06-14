<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function pool_json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pool_store_path(): string
{
    return __DIR__ . '/education_report_content_pool.json';
}

function pool_default_items(): array
{
    $defaultGroup = '터널 내 이동식 비계 작업';
    return [
        [
            'id' => 'default-01',
            'group' => $defaultGroup,
            'title' => '개인장구 착용',
            'content' => '개인장구 착용을 철저히 할 것.(안전모, 각반, 안전조끼, 보안경, 안전화 외)',
        ],
        [
            'id' => 'default-02',
            'group' => $defaultGroup,
            'title' => '교통안전 신호수 배치',
            'content' => "교통안전 신호수를 배치하고 무전교신하며 차량이나 통행인 유도안전을\n위하여 최선을 다하여야 한다. 작업위치 전·후방 10M에 경광봉 설치.",
        ],
        [
            'id' => 'default-03',
            'group' => $defaultGroup,
            'title' => '이동식비계 승하강 통제',
            'content' => '이동식비계(대차)에 승 하강 시 이동 차량 통제',
        ],
        [
            'id' => 'default-04',
            'group' => $defaultGroup,
            'title' => '이동식비계 고정',
            'content' => '이동식비계(대차)를 정치 후 브레이크 고정, 고임목 설치',
        ],
        [
            'id' => 'default-05',
            'group' => $defaultGroup,
            'title' => '안전대 착용',
            'content' => '이동식비계(대차)에서 작업 시 전신안전대 착용 및 안전고리 체결',
        ],
        [
            'id' => 'default-06',
            'group' => $defaultGroup,
            'title' => '2인 1조 이동',
            'content' => '이동식비계(대차)를 이동시킬 때 2인 1조로 힘을 나누어 이동시킨다.',
        ],
        [
            'id' => 'default-07',
            'group' => $defaultGroup,
            'title' => '도로측 자재 방치 금지',
            'content' => '도로측에 자재나 공구를 방치하여 통행차량에 장애를 유발하지 않는다.',
        ],
        [
            'id' => 'default-08',
            'group' => $defaultGroup,
            'title' => '앙카작업 낙하물 주의',
            'content' => "벽 또는 천장에 앙카작업 시 부석의 낙하가 발생할 수 있으므로 하부에 인원이\n접근하지 않도록 조치 후 작업을 한다.",
        ],
        [
            'id' => 'default-09',
            'group' => $defaultGroup,
            'title' => '작업 후 정리정돈',
            'content' => '작업 후 현장주변 공사완료 뒷정리를 철저히 할 것.(부산물 및 주변청소)',
        ],
        [
            'id' => 'default-10',
            'group' => $defaultGroup,
            'title' => '지상작업자 낙하물 주시',
            'content' => '지상작업자는 낙하물을 항상 주시하며 작업해야 한다.',
        ],
        [
            'id' => 'default-11',
            'group' => $defaultGroup,
            'title' => '차량 고임목',
            'content' => '각종차량 고임목 철저히 시행할 것.',
        ],
        [
            'id' => 'default-12',
            'group' => $defaultGroup,
            'title' => '차량 이동 시 작업중지',
            'content' => "도로측 작업 시 차량의 이동이 있을 경우 신호수의 지시에 따라 작업을 일시\n중단한다.",
        ],
        [
            'id' => 'default-13',
            'group' => $defaultGroup,
            'title' => '사다리 2인 1조',
            'content' => '사다리 작업 시 2인 1조로 작업을 한다.',
        ],
    ];
}

function pool_default_groups(): array
{
    return ['터널 내 이동식 비계 작업'];
}

function pool_normalize_groups(array $groups, array $items = []): array
{
    $result = [];
    $seen = [];

    foreach ($groups as $group) {
        $group = trim((string)$group);
        if ($group === '' || isset($seen[$group])) {
            continue;
        }
        $seen[$group] = true;
        $result[] = $group;
    }

    foreach ($items as $item) {
        $group = trim((string)($item['group'] ?? ''));
        if ($group === '' || isset($seen[$group])) {
            continue;
        }
        $seen[$group] = true;
        $result[] = $group;
    }

    return $result;
}

function pool_normalize_lines(string $content): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    if (strpos($content, "\n") === false && strpos($content, '\\n') !== false) {
        $content = str_replace('\\n', "\n", $content);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $result = [];

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $result[] = $line;
    }

    return $result;
}

function pool_normalize_item(array $item): ?array
{
    $id = trim((string)($item['id'] ?? ''));
    $group = trim((string)($item['group'] ?? ''));
    $title = trim((string)($item['title'] ?? ''));
    $content = trim((string)($item['content'] ?? ''));
    $lines = pool_normalize_lines($content);
    $translations = pool_normalize_translations((array)($item['translations'] ?? []));

    if ($title === '' && !empty($lines)) {
        $title = $lines[0];
    }

    if ($id === '' || $title === '' || empty($lines)) {
        return null;
    }

    return [
        'id' => $id,
        'group' => $group,
        'title' => $title,
        'content' => implode("\n", $lines),
        'lines' => $lines,
        'line_count' => count($lines),
        'translations' => $translations,
    ];
}

function pool_translation_config(): array
{
    $config = [
        'provider' => 'google_basic',
        'endpoint' => '',
        'api_key' => '',
        'source_language' => 'ko',
        'request_timeout' => 15,
    ];

    $configPath = __DIR__ . '/education_report_translation_config.php';
    if (is_file($configPath)) {
        $fileConfig = require $configPath;
        if (is_array($fileConfig)) {
            $config = array_merge($config, $fileConfig);
        }
    }

    $envMap = [
        'provider' => 'EDUCATION_REPORT_TRANSLATION_PROVIDER',
        'endpoint' => 'EDUCATION_REPORT_TRANSLATION_ENDPOINT',
        'api_key' => 'EDUCATION_REPORT_TRANSLATION_API_KEY',
        'source_language' => 'EDUCATION_REPORT_TRANSLATION_SOURCE',
        'request_timeout' => 'EDUCATION_REPORT_TRANSLATION_TIMEOUT',
    ];

    foreach ($envMap as $key => $envName) {
        $value = getenv($envName);
        if ($value === false || $value === '') {
            continue;
        }
        $config[$key] = $value;
    }

    $config['provider'] = strtolower(trim((string)($config['provider'] ?? 'google_basic')));
    $config['endpoint'] = trim((string)($config['endpoint'] ?? ''));
    $config['api_key'] = trim((string)($config['api_key'] ?? ''));
    $config['source_language'] = trim((string)($config['source_language'] ?? 'ko')) ?: 'ko';
    $config['request_timeout'] = max(3, (int)($config['request_timeout'] ?? 15));
    if ($config['provider'] === 'google_basic') {
        $config['enabled'] = ($config['api_key'] !== '');
    } else {
        $config['enabled'] = ($config['provider'] === 'libretranslate' && $config['endpoint'] !== '');
    }

    return $config;
}

function pool_translate_http_post_json(string $url, array $payload, int $timeoutSeconds): ?array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $json,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function pool_translate_text(string $text, string $targetLanguage): ?string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $config = pool_translation_config();
    if (!$config['enabled']) {
        return null;
    }

    $targetLanguage = trim($targetLanguage);
    if ($targetLanguage === '') {
        return null;
    }

    if ($config['provider'] === 'google_basic') {
        $endpoint = 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($config['api_key']);
        $payload = [
            'q' => $text,
            'source' => $config['source_language'],
            'target' => $targetLanguage,
            'format' => 'text',
        ];

        $response = pool_translate_http_post_json($endpoint, $payload, $config['request_timeout']);
        $translatedText = trim((string)($response['data']['translations'][0]['translatedText'] ?? ''));
        if ($translatedText === '') {
            return null;
        }
        return html_entity_decode($translatedText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if ($config['provider'] === 'libretranslate') {
        $endpoint = rtrim($config['endpoint'], '/');
        if ($endpoint === '') {
            return null;
        }

        $payload = [
            'q' => $text,
            'source' => $config['source_language'],
            'target' => $targetLanguage,
            'format' => 'text',
        ];

        if ($config['api_key'] !== '') {
            $payload['api_key'] = $config['api_key'];
        }

        $response = pool_translate_http_post_json($endpoint . '/translate', $payload, $config['request_timeout']);
        $translatedText = trim((string)($response['translatedText'] ?? ''));
        return $translatedText !== '' ? $translatedText : null;
    }

    return null;
}

function pool_build_translation_entry(string $translatedContent): ?array
{
    $lines = pool_normalize_lines($translatedContent);
    if ($lines === []) {
        return null;
    }

    return [
        'title' => trim((string)($lines[0] ?? '')),
        'content' => implode("\n", $lines),
        'lines' => $lines,
        'line_count' => count($lines),
        'updated_at' => date('c'),
    ];
}

function pool_normalize_translation_entry(array $entry): ?array
{
    $content = trim((string)($entry['content'] ?? ''));
    $lines = pool_normalize_lines($content);
    if ($lines === []) {
        $lines = array_values(array_filter(array_map('trim', (array)($entry['lines'] ?? [])), static function(string $line): bool {
            return $line !== '';
        }));
        $content = implode("\n", $lines);
    }

    if ($lines === [] || $content === '') {
        return null;
    }

    $title = trim((string)($entry['title'] ?? ''));
    if ($title === '') {
        $title = trim((string)($lines[0] ?? ''));
    }

    return [
        'title' => $title,
        'content' => $content,
        'lines' => $lines,
        'line_count' => count($lines),
        'updated_at' => trim((string)($entry['updated_at'] ?? '')),
    ];
}

function pool_normalize_translations(array $translations): array
{
    $result = [];
    foreach ($translations as $language => $entry) {
        $language = strtolower(trim((string)$language));
        if ($language === '' || !is_array($entry)) {
            continue;
        }
        $normalized = pool_normalize_translation_entry($entry);
        if ($normalized === null) {
            continue;
        }
        $result[$language] = $normalized;
    }
    return $result;
}

function pool_apply_translation(array $item, string $language): array
{
    $language = strtolower(trim($language));
    if ($language === '') {
        return $item;
    }

    $translatedContent = pool_translate_text((string)($item['content'] ?? ''), $language);
    if ($translatedContent === null || trim($translatedContent) === '') {
        return $item;
    }

    $entry = pool_build_translation_entry($translatedContent);
    if ($entry === null) {
        return $item;
    }

    $item['translations'] = pool_normalize_translations((array)($item['translations'] ?? []));
    $item['translations'][$language] = $entry;
    return $item;
}

function pool_translation_missing(array $item, string $language): bool
{
    $language = strtolower(trim($language));
    if ($language === '') {
        return false;
    }

    $translations = pool_normalize_translations((array)($item['translations'] ?? []));
    if (!isset($translations[$language])) {
        return true;
    }

    return trim((string)($translations[$language]['content'] ?? '')) === '';
}

function pool_fill_missing_translations(array $items, string $language, ?array &$translatedIds = null): array
{
    $translatedIds = [];
    $result = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (pool_translation_missing($item, $language)) {
            $updatedItem = pool_apply_translation($item, $language);
            $before = trim((string)(((array)($item['translations'][$language] ?? []))['content'] ?? ''));
            $after = trim((string)(((array)($updatedItem['translations'][$language] ?? []))['content'] ?? ''));
            if ($after !== '' && $after !== $before) {
                $translatedIds[] = (string)($updatedItem['id'] ?? '');
            }
            $item = $updatedItem;
        }

        $result[] = $item;
    }

    return $result;
}

function pool_find_item_by_id(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && (string)($item['id'] ?? '') === $id) {
            return $item;
        }
    }

    return null;
}

function pool_prepare_items(array $items): array
{
    $result = [];
    $seen = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = pool_normalize_item($item);
        if ($normalized === null) {
            continue;
        }
        if (isset($seen[$normalized['id']])) {
            continue;
        }
        $seen[$normalized['id']] = true;
        $result[] = $normalized;
    }

    return $result;
}

function pool_write_store(array $items, array $groups = []): bool
{
    $normalizedItems = pool_prepare_items($items);
    $normalizedGroups = pool_normalize_groups($groups, $normalizedItems);

    $payload = [
        'updated_at' => date('c'),
        'groups' => $normalizedGroups,
        'items' => array_map(static function(array $item): array {
            $translations = [];
            foreach ((array)($item['translations'] ?? []) as $language => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $translations[$language] = [
                    'title' => (string)($entry['title'] ?? ''),
                    'content' => (string)($entry['content'] ?? ''),
                    'updated_at' => (string)($entry['updated_at'] ?? ''),
                ];
            }

            return [
                'id' => $item['id'],
                'group' => $item['group'] ?? '',
                'title' => $item['title'],
                'content' => $item['content'],
                'translations' => $translations,
            ];
        }, $normalizedItems),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(pool_store_path(), $json, LOCK_EX) !== false;
}

function pool_read_store(): array
{
    $path = pool_store_path();
    if (!is_file($path)) {
        $defaults = pool_prepare_items(pool_default_items());
        $groups = pool_normalize_groups(pool_default_groups(), $defaults);
        pool_write_store($defaults, $groups);
        return ['groups' => $groups, 'items' => $defaults];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        $defaults = pool_prepare_items(pool_default_items());
        $groups = pool_normalize_groups(pool_default_groups(), $defaults);
        pool_write_store($defaults, $groups);
        return ['groups' => $groups, 'items' => $defaults];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        $defaults = pool_prepare_items(pool_default_items());
        $groups = pool_normalize_groups(pool_default_groups(), $defaults);
        pool_write_store($defaults, $groups);
        return ['groups' => $groups, 'items' => $defaults];
    }

    $items = pool_prepare_items((array)($decoded['items'] ?? []));
    $groups = pool_normalize_groups((array)($decoded['groups'] ?? []), $items);
    if ($items === []) {
        $items = pool_prepare_items(pool_default_items());
        $groups = pool_normalize_groups(pool_default_groups(), $items);
        pool_write_store($items, $groups);
    }

    if ($groups === []) {
        $groups = pool_normalize_groups(pool_default_groups(), $items);
        pool_write_store($items, $groups);
    }

    return ['groups' => $groups, 'items' => $items];
}

function pool_request_value(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $store = pool_read_store();
        pool_json_out([
            'ok' => true,
            'groups' => $store['groups'],
            'items' => $store['items'],
            'translation_enabled' => pool_translation_config()['enabled'],
        ]);
    }

    if ($method !== 'POST') {
        pool_json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    $action = pool_request_value('action');
    $store = pool_read_store();
    $items = $store['items'];
    $groups = $store['groups'];

    if ($action === 'create_group') {
        $group = pool_request_value('group');
        if ($group === '') {
            pool_json_out(['ok' => false, 'error' => 'group_required'], 400);
        }

        $groups = pool_normalize_groups(array_merge($groups, [$group]), $items);
        if (!pool_write_store($items, $groups)) {
            pool_json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }

        pool_json_out([
            'ok' => true,
            'groups' => $groups,
            'items' => $items,
            'created_group' => $group,
        ]);
    }

    if ($action === 'save_item') {
        $id = pool_request_value('id');
        $group = pool_request_value('group');
        $title = pool_request_value('title');
        $content = trim((string)($_POST['content'] ?? ''));

        if ($content === '') {
            pool_json_out(['ok' => false, 'error' => 'content_required'], 400);
        }

        if ($title === '') {
            $normalizedLines = pool_normalize_lines($content);
            $title = $normalizedLines[0] ?? '';
        }

        if ($title === '') {
            pool_json_out(['ok' => false, 'error' => 'title_required'], 400);
        }

        if ($id === '') {
            $id = 'pool-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
            $newItem = [
                'id' => $id,
                'group' => $group,
                'title' => $title,
                'content' => $content,
            ];
            $items[] = pool_apply_translation($newItem, 'zh');
        } else {
            $updated = false;
            foreach ($items as &$item) {
                if (($item['id'] ?? '') !== $id) {
                    continue;
                }
                $item['group'] = $group;
                $item['title'] = $title;
                $item['content'] = $content;
                $item = pool_apply_translation($item, 'zh');
                $updated = true;
                break;
            }
            unset($item);

            if (!$updated) {
                $newItem = [
                    'id' => $id,
                    'group' => $group,
                    'title' => $title,
                    'content' => $content,
                ];
                $items[] = pool_apply_translation($newItem, 'zh');
            }
        }

        $items = pool_prepare_items($items);
        $groups = pool_normalize_groups(array_merge($groups, [$group]), $items);
        if (!pool_write_store($items, $groups)) {
            pool_json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }

        pool_json_out([
            'ok' => true,
            'groups' => $groups,
            'items' => $items,
            'saved_id' => $id,
        ]);
    }

    if ($action === 'translate_items') {
        $idsRaw = trim((string)($_POST['ids'] ?? ''));
        $language = strtolower(pool_request_value('language'));
        if ($language === '') {
            $language = 'zh';
        }

        $ids = json_decode($idsRaw, true);
        if (!is_array($ids)) {
            $ids = [];
        }

        $requestedIds = [];
        foreach ($ids as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $requestedIds[$value] = true;
        }

        $translatedIds = [];
        if ($requestedIds !== []) {
            foreach ($items as $index => $item) {
                $itemId = (string)($item['id'] ?? '');
                if ($itemId === '' || !isset($requestedIds[$itemId])) {
                    continue;
                }

                $before = trim((string)(((array)($item['translations'][$language] ?? []))['content'] ?? ''));
                $items[$index] = pool_apply_translation($item, $language);
                $after = trim((string)(((array)($items[$index]['translations'][$language] ?? []))['content'] ?? ''));
                if ($after !== '' && $after !== $before) {
                    $translatedIds[] = $itemId;
                }
            }
        }

        $items = pool_prepare_items($items);
        $groups = pool_normalize_groups($groups, $items);
        if (!pool_write_store($items, $groups)) {
            pool_json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }

        pool_json_out([
            'ok' => true,
            'groups' => $groups,
            'items' => $items,
            'translated_ids' => array_values(array_unique($translatedIds)),
            'translation_enabled' => pool_translation_config()['enabled'],
        ]);
    }

    if ($action === 'translate_missing') {
        $language = strtolower(pool_request_value('language'));
        if ($language === '') {
            $language = 'zh';
        }

        $translatedIds = [];
        $items = pool_fill_missing_translations($items, $language, $translatedIds);
        $items = pool_prepare_items($items);
        $groups = pool_normalize_groups($groups, $items);
        if (!pool_write_store($items, $groups)) {
            pool_json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }

        pool_json_out([
            'ok' => true,
            'groups' => $groups,
            'items' => $items,
            'translated_ids' => array_values(array_unique($translatedIds)),
            'translation_enabled' => pool_translation_config()['enabled'],
        ]);
    }

    if ($action === 'delete_item') {
        $id = pool_request_value('id');
        if ($id === '') {
            pool_json_out(['ok' => false, 'error' => 'id_required'], 400);
        }

        $items = array_values(array_filter($items, static function(array $item) use ($id): bool {
            return (string)($item['id'] ?? '') !== $id;
        }));

        $groups = pool_normalize_groups($groups, $items);
        if (!pool_write_store($items, $groups)) {
            pool_json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }

        pool_json_out([
            'ok' => true,
            'groups' => $groups,
            'items' => pool_prepare_items($items),
            'deleted_id' => $id,
        ]);
    }

    if ($action === 'reset_defaults') {
        $items = pool_prepare_items(pool_default_items());
        $groups = pool_normalize_groups(pool_default_groups(), $items);
        if (!pool_write_store($items, $groups)) {
            pool_json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }

        pool_json_out([
            'ok' => true,
            'groups' => $groups,
            'items' => $items,
        ]);
    }

    pool_json_out(['ok' => false, 'error' => 'invalid_action'], 400);
} catch (Throwable $e) {
    pool_json_out([
        'ok' => false,
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], 500);
}
