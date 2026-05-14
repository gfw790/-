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
    ];
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
            return [
                'id' => $item['id'],
                'group' => $item['group'] ?? '',
                'title' => $item['title'],
                'content' => $item['content'],
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
            $items[] = [
                'id' => $id,
                'group' => $group,
                'title' => $title,
                'content' => $content,
            ];
        } else {
            $updated = false;
            foreach ($items as &$item) {
                if (($item['id'] ?? '') !== $id) {
                    continue;
                }
                $item['group'] = $group;
                $item['title'] = $title;
                $item['content'] = $content;
                $updated = true;
                break;
            }
            unset($item);

            if (!$updated) {
                $items[] = [
                    'id' => $id,
                    'group' => $group,
                    'title' => $title,
                    'content' => $content,
                ];
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
