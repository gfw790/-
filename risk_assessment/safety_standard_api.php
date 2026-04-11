<?php

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth.php';

    if (auth_current_user() === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => '로그인이 필요합니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');

function safety_standard_json_path(): string
{
    return dirname(dirname(__DIR__))
        . DIRECTORY_SEPARATOR . 'xampp'
        . DIRECTORY_SEPARATOR . 'htdocs'
        . DIRECTORY_SEPARATOR . 'safety'
        . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'combined.generated.json';
}

function normalize_standard_key(string $value): string
{
    $value = preg_replace('/\s+/u', '', trim($value)) ?? '';
    return strtoupper($value);
}

function split_standard_candidates(string $value): array
{
    $parts = preg_split('/[\r\n,;\/|]+/', trim($value)) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }
        $result[] = $part;
    }

    if (empty($result) && trim($value) !== '') {
        $result[] = trim($value);
    }

    return array_values(array_unique($result));
}

function load_safety_standard_dataset(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $path = safety_standard_json_path();
    if (!is_file($path)) {
        throw new RuntimeException('안전작업표준서 데이터 파일을 찾을 수 없습니다.');
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('안전작업표준서 데이터 파일을 읽을 수 없습니다.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['index']) || !is_array($data['index'])) {
        throw new RuntimeException('안전작업표준서 데이터 형식이 올바르지 않습니다.');
    }

    $cached = $data;
    return $cached;
}

function build_safety_standard_payload(array $dataset, array $matchedIndex, string $matchedCandidate = ''): array
{
    $matchedNo = (string)($matchedIndex['no'] ?? '');
    $detail = [];
    if (
        $matchedNo !== ''
        && isset($dataset['details'])
        && is_array($dataset['details'])
        && isset($dataset['details'][$matchedNo])
        && is_array($dataset['details'][$matchedNo])
    ) {
        $detail = $dataset['details'][$matchedNo];
    }

    $jobPlanValue = (string)($matchedIndex['jobPlan'] ?? $matchedCandidate);
    $sourceUrl = '/safety/index.html?' . http_build_query([
        'jobPlan' => $jobPlanValue,
        'query' => $jobPlanValue,
    ], '', '&', PHP_QUERY_RFC3986);

    return [
        'no' => (int)($matchedIndex['no'] ?? 0),
        'job_plan' => $jobPlanValue,
        'matched_candidate' => $matchedCandidate,
        'name' => (string)($detail['name'] ?? $matchedIndex['name'] ?? ''),
        'author' => (string)($detail['author'] ?? $matchedIndex['author'] ?? ''),
        'created' => (string)($matchedIndex['created'] ?? ''),
        'revised' => (string)($matchedIndex['revised'] ?? $detail['date'] ?? ''),
        'note' => (string)($matchedIndex['note'] ?? ''),
        'rev' => (string)($detail['rev'] ?? ''),
        'file_no' => (int)($detail['fileNo'] ?? $matchedIndex['fileNo'] ?? 0),
        'sheet_name' => (string)($detail['sheetName'] ?? ''),
        'source_url' => $sourceUrl,
        'sections' => [
            'prep' => array_values(array_filter((array)($detail['prep'] ?? []), 'is_string')),
            'safety' => array_values(array_filter((array)($detail['safety'] ?? []), 'is_string')),
            'steps' => array_values(array_filter((array)($detail['steps'] ?? []), 'is_string')),
            'safe_steps' => array_values(array_filter((array)($detail['safeSteps'] ?? []), 'is_string')),
        ],
    ];
}

try {
    $query = isset($_GET['query']) ? trim((string)$_GET['query']) : '';
    if ($query !== '') {
        $dataset = load_safety_standard_dataset();
        $needle = normalize_standard_key($query);
        $results = [];
        $seen = [];

        foreach ($dataset['index'] as $row) {
            $jobPlan = (string)($row['jobPlan'] ?? '');
            $name = (string)($row['name'] ?? '');
            $author = (string)($row['author'] ?? '');
            $sheetName = (string)(
                $dataset['details'][(string)($row['no'] ?? '')]['sheetName'] ?? ''
            );

            $fields = [
                normalize_standard_key($jobPlan),
                normalize_standard_key($name),
                normalize_standard_key($author),
                normalize_standard_key($sheetName),
            ];

            $isMatch = false;
            foreach ($fields as $field) {
                if ($field !== '' && str_contains($field, $needle)) {
                    $isMatch = true;
                    break;
                }
            }

            if (!$isMatch) {
                continue;
            }

            $jobPlanKey = normalize_standard_key($jobPlan);
            if ($jobPlanKey !== '' && isset($seen[$jobPlanKey])) {
                continue;
            }

            if ($jobPlanKey !== '') {
                $seen[$jobPlanKey] = true;
            }

            $results[] = build_safety_standard_payload($dataset, $row);
            if (count($results) >= 20) {
                break;
            }
        }

        usort($results, static function (array $left, array $right) use ($needle): int {
            $leftExact = normalize_standard_key((string)($left['job_plan'] ?? '')) === $needle ? 0 : 1;
            $rightExact = normalize_standard_key((string)($right['job_plan'] ?? '')) === $needle ? 0 : 1;
            if ($leftExact !== $rightExact) {
                return $leftExact <=> $rightExact;
            }

            return ((int)($left['no'] ?? 0)) <=> ((int)($right['no'] ?? 0));
        });

        echo json_encode([
            'success' => true,
            'data' => [
                'query' => $query,
                'results' => $results,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jobPlan = isset($_GET['job_plan']) ? trim((string)$_GET['job_plan']) : '';
    if ($jobPlan === '') {
        echo json_encode([
            'success' => false,
            'message' => 'job_plan 값이 필요합니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dataset = load_safety_standard_dataset();
    $candidates = split_standard_candidates($jobPlan);
    if (empty($candidates)) {
        echo json_encode([
            'success' => false,
            'message' => '조회할 작업표준서번호가 없습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $matchedIndex = null;
    $matchedCandidate = '';
    foreach ($candidates as $candidate) {
        $wanted = normalize_standard_key($candidate);
        foreach ($dataset['index'] as $row) {
            $rowJobPlan = (string)($row['jobPlan'] ?? '');
            if ($wanted !== '' && normalize_standard_key($rowJobPlan) === $wanted) {
                $matchedIndex = $row;
                $matchedCandidate = $candidate;
                break 2;
            }
        }
    }

    if (!is_array($matchedIndex)) {
        echo json_encode([
            'success' => false,
            'message' => '해당 작업표준서번호를 찾을 수 없습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => build_safety_standard_payload($dataset, $matchedIndex, $matchedCandidate),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
