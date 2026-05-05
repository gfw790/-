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
    // Remove parenthesized notes and whitespace for loose matching across systems.
    $name = preg_replace('/\([^)]*\)/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', '', $name) ?? $name;
    return trim($name);
}

// Always exclude these names from education report attendee output.
$hardExcludedNames = ['윤장희'];

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_out(['ok' => false, 'error' => 'invalid_date'], 400);
}

$requestedFile = trim((string)($_GET['file'] ?? ''));

try {
    require_once __DIR__ . '/../../tbm/tbm_db.php';
    require_once __DIR__ . '/../../tbm/tbm_functions.php';

    $tbmPdo = tbm_db();
    $docStmt = null;
    $docParams = [];

    if ($requestedFile !== '') {
        $normalizedFile = tbm_normalize_output_relative_path($requestedFile);
        if ($normalizedFile !== '') {
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

    if ($docStmt === null) {
        $docStmt = $tbmPdo->prepare(
            'SELECT id, team, output_filename
               FROM tbm_documents
              WHERE doc_date = :doc_date
              ORDER BY id DESC
              LIMIT 1'
        );
        $docParams = [':doc_date' => $date];
    }

    $docStmt->execute($docParams);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        json_out([
            'ok' => true,
            'date' => $date,
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

    // If output HTML exists, prefer the names actually rendered in today's TBM output.
    $outputFilename = trim((string)($doc['output_filename'] ?? ''));
    if ($outputFilename !== '') {
        $safeRelPath = str_replace(['\\', '..'], ['/', ''], $outputFilename);
        $outputPath = __DIR__ . '/../../tbm/output/' . ltrim($safeRelPath, '/');
        if (is_file($outputPath)) {
            $html = (string)file_get_contents($outputPath);
            if ($html !== '') {
                $fromOutput = [];
                foreach ($orderedNames as $name) {
                    if (mb_strpos($html, $name) !== false) {
                        $fromOutput[] = $name;
                    }
                }
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
            'count' => 0,
            'names' => [],
        ]);
    }

    $employeeDbPath = __DIR__ . '/../../employees/employees.db';
    $excludedByTeam = [];

    if (is_file($employeeDbPath)) {
        $employeePdo = new PDO('sqlite:' . $employeeDbPath);
        $employeePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $placeholders = implode(',', array_fill(0, count($orderedNames), '?'));
        $empStmt = $employeePdo->prepare(
            "SELECT name, team FROM employees WHERE name IN ($placeholders)"
        );
        $empStmt->execute($orderedNames);
        $empRows = $empStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($empRows as $emp) {
            $team = trim((string)($emp['team'] ?? ''));
            if ($team !== '공사팀-모터') {
                continue;
            }
            $empNameKey = normalize_name((string)($emp['name'] ?? ''));
            if ($empNameKey !== '') {
                $excludedByTeam[$empNameKey] = true;
            }
        }
    }

    $filtered = [];
    $hardExcludedKeys = [];
    foreach ($hardExcludedNames as $excludedName) {
        $excludedKey = normalize_name((string)$excludedName);
        if ($excludedKey !== '') {
            $hardExcludedKeys[$excludedKey] = true;
        }
    }

    foreach ($orderedNames as $name) {
        $nameKey = normalize_name($name);
        if ($nameKey !== '' && isset($excludedByTeam[$nameKey])) {
            continue;
        }
        if ($nameKey !== '' && isset($hardExcludedKeys[$nameKey])) {
            continue;
        }
        $filtered[] = $name;
    }

    json_out([
        'ok' => true,
        'date' => $date,
        'doc_id' => (int)$doc['id'],
        'doc_team' => (string)($doc['team'] ?? ''),
        'source_file' => (string)($doc['output_filename'] ?? ''),
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
