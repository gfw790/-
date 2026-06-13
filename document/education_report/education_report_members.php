<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\([^)]*\)/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', '', $name) ?? $name;
    return trim($name);
}

function extract_names_from_tbm_output_html(string $html): array
{
    if ($html === '') {
        return [];
    }

    if (!preg_match('/<table class="tbm-dyn-table tbm-dyn-row"[^>]*>.*?<\/table>/si', $html, $tableMatch)) {
        return [];
    }

    if (!preg_match_all('/<td>(.*?)<\/td>/si', $tableMatch[0], $cellMatches)) {
        return [];
    }

    $names = [];
    $seen = [];
    foreach ($cellMatches[1] as $index => $cellHtml) {
        if ($index % 2 === 1) {
            continue;
        }

        $value = html_entity_decode(strip_tags((string)$cellHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim($value);
        if ($value === '') {
            continue;
        }
        if (in_array($value, ['이름', '성명'], true)) {
            continue;
        }

        $key = normalize_name($value);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $names[] = $value;
    }

    return $names;
}

function auth_team_names(string $teamName): array
{
    $teamName = trim($teamName);
    if ($teamName === '') {
        return [];
    }

    require_once __DIR__ . '/../../risk_assessment/auth.php';

    $names = [];
    $seen = [];

    foreach (auth_accounts() as $account) {
        if (!is_array($account) || auth_is_retired_account($account)) {
            continue;
        }

        $accountTeam = auth_normalize_team_name((string)($account['team'] ?? ''));
        if ($accountTeam !== auth_normalize_team_name($teamName)) {
            continue;
        }

        $name = trim((string)($account['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $key = normalize_name($name);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $names[] = $name;
    }

    sort($names, SORT_STRING);
    return array_values($names);
}

function auth_available_teams(): array
{
    require_once __DIR__ . '/../../risk_assessment/auth.php';

    $teams = [];
    foreach (auth_read_teams() as $teamName) {
        $teamName = auth_normalize_team_name((string)$teamName);
        if ($teamName === '') {
            continue;
        }
        $teams[] = $teamName;
    }

    return array_values(array_unique($teams));
}

$hardExcludedNames = ['소장님', '김종훈', '조한봉'];

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_out(['ok' => false, 'error' => 'invalid_date'], 400);
}

$requestedFile = trim((string)($_GET['file'] ?? ''));
$requestedTeam = trim((string)($_GET['team'] ?? '공사팀'));
$availableTeams = [];

try {
    $availableTeams = auth_available_teams();
    $authNames = auth_team_names($requestedTeam);
    if ($authNames !== []) {
        json_out([
            'ok' => true,
            'date' => $date,
            'team' => $requestedTeam,
            'source' => 'auth_team',
            'available_teams' => $availableTeams,
            'count' => count($authNames),
            'names' => array_slice($authNames, 0, 12),
        ]);
    }

    require_once __DIR__ . '/../../tbm/tbm_db.php';
    require_once __DIR__ . '/../../tbm/tbm_functions.php';

    $tbmPdo = tbm_db();
    $docStmt = null;
    $docParams = [];

    if ($requestedFile !== '') {
        $normalizedFile = tbm_normalize_output_relative_path($requestedFile);
        if ($normalizedFile !== '') {
            if ($requestedTeam !== '') {
                $docStmt = $tbmPdo->prepare(
                    'SELECT id, team, output_filename
                       FROM tbm_documents
                      WHERE output_filename = :output_filename
                        AND team = :team
                      ORDER BY id DESC
                      LIMIT 1'
                );
                $docParams = [
                    ':output_filename' => $normalizedFile,
                    ':team' => $requestedTeam,
                ];
            } else {
                $docStmt = $tbmPdo->prepare(
                    'SELECT id, team, output_filename
                       FROM tbm_documents
                      WHERE output_filename = :output_filename
                      ORDER BY id DESC
                      LIMIT 1'
                );
                $docParams = [':output_filename' => $normalizedFile];
            }
        }
    }

    if ($docStmt === null) {
        if ($requestedTeam !== '') {
            $docStmt = $tbmPdo->prepare(
                'SELECT id, team, output_filename
                   FROM tbm_documents
                  WHERE doc_date = :doc_date
                    AND team = :team
                  ORDER BY id DESC
                  LIMIT 1'
            );
            $docParams = [
                ':doc_date' => $date,
                ':team' => $requestedTeam,
            ];
        } else {
            $docStmt = $tbmPdo->prepare(
                'SELECT id, team, output_filename
                   FROM tbm_documents
                  WHERE doc_date = :doc_date
                  ORDER BY id DESC
                  LIMIT 1'
            );
            $docParams = [':doc_date' => $date];
        }
    }

    $docStmt->execute($docParams);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        json_out([
            'ok' => true,
            'date' => $date,
            'team' => $requestedTeam,
            'available_teams' => $availableTeams,
            'count' => 0,
            'names' => [],
        ]);
    }

    $memberStmt = $tbmPdo->prepare(
        'SELECT m.name, dm.slot_order
           FROM tbm_document_members dm
           JOIN tbm_members m ON m.id = dm.member_id
          WHERE dm.doc_id = :doc_id
          ORDER BY dm.slot_order ASC, dm.member_id ASC'
    );
    $memberStmt->execute([':doc_id' => (int)$doc['id']]);
    $rows = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderedNames = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $key = normalize_name($name);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $orderedNames[] = $name;
    }

    $outputFilename = trim((string)($doc['output_filename'] ?? ''));
    if ($outputFilename !== '') {
        $safeRelPath = str_replace(['\\', '..'], ['/', ''], $outputFilename);
        $outputPath = __DIR__ . '/../../tbm/output/' . ltrim($safeRelPath, '/');
        if (is_file($outputPath)) {
            $html = (string)file_get_contents($outputPath);
            if ($html !== '') {
                $fromOutput = extract_names_from_tbm_output_html($html);
                if ($fromOutput !== []) {
                    $orderedNames = $fromOutput;
                }
            }
        }
    }

    if ($orderedNames === []) {
        json_out([
            'ok' => true,
            'date' => $date,
            'team' => $requestedTeam,
            'doc_id' => (int)$doc['id'],
            'doc_team' => (string)($doc['team'] ?? ''),
            'source_file' => (string)($doc['output_filename'] ?? ''),
            'available_teams' => $availableTeams,
            'count' => 0,
            'names' => [],
        ]);
    }

    $hardExcludedKeys = [];
    foreach ($hardExcludedNames as $excludedName) {
        $excludedKey = normalize_name((string)$excludedName);
        if ($excludedKey !== '') {
            $hardExcludedKeys[$excludedKey] = true;
        }
    }

    $filtered = [];
    foreach ($orderedNames as $name) {
        $nameKey = normalize_name($name);
        if ($nameKey !== '' && isset($hardExcludedKeys[$nameKey])) {
            continue;
        }
        $filtered[] = $name;
    }

    json_out([
        'ok' => true,
        'date' => $date,
        'team' => $requestedTeam,
        'doc_id' => (int)$doc['id'],
        'doc_team' => (string)($doc['team'] ?? ''),
        'source_file' => (string)($doc['output_filename'] ?? ''),
        'available_teams' => $availableTeams,
        'count' => count($filtered),
        'names' => array_slice($filtered, 0, 12),
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], 500);
}
