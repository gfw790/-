<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/tbm_db.php';
require_once __DIR__ . '/tbm_ai.php';
require_once __DIR__ . '/tbm_functions.php';

define('TBM_AUTO_OUTPUT_DIR', __DIR__ . '/output');
define('TBM_AUTO_LOG_FILE', __DIR__ . '/cache/auto_ai_generate.log');
define('TBM_AUTO_MAX_ATTEMPTS', 3);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function tbm_auto_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $logDir = dirname(TBM_AUTO_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    @file_put_contents(TBM_AUTO_LOG_FILE, $line, FILE_APPEND);
}

function tbm_auto_out(string $message, bool $stderr = false): void
{
    $stream = $stderr ? STDERR : STDOUT;
    fwrite($stream, $message . PHP_EOL);
    tbm_auto_log($message);
}

function tbm_auto_blank_risk_rows(): array
{
    return array_fill(0, 10, [
        'work' => '',
        'hazard' => '',
        'control' => '',
        'freq' => '',
        'strength' => '',
        'risk' => '',
    ]);
}

function tbm_auto_decode_array(mixed $value, array $fallback): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $fallback;
}

function tbm_auto_extract_retry_seconds(string $message): int
{
    $patterns = [
        '/retry in ([0-9]+(?:\.[0-9]+)?)s/i',
        '/"retryDelay"\s*:\s*"([0-9]+)s"/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $matches)) {
            $seconds = (int)ceil((float)$matches[1]) + 2;
            return max(5, min(180, $seconds));
        }
    }

    return 0;
}

function tbm_auto_generate_content_with_retry(string $targetDate, bool $forceNew): array
{
    $attempt = 0;

    while (true) {
        $attempt++;

        try {
            return tbm_ai_generate_content($targetDate, $forceNew);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $isQuotaError = str_contains($message, 'GEMINI_QUOTA_EXCEEDED::');
            $waitSeconds = $isQuotaError ? tbm_auto_extract_retry_seconds($message) : 0;

            if (!$isQuotaError || $attempt >= TBM_AUTO_MAX_ATTEMPTS || $waitSeconds <= 0) {
                throw $e;
            }

            tbm_auto_out(
                sprintf(
                    '[TBM AUTO] quota exceeded, waiting %ds before retry (%d/%d)',
                    $waitSeconds,
                    $attempt,
                    TBM_AUTO_MAX_ATTEMPTS
                )
            );

            sleep($waitSeconds);
            $forceNew = false;
        }
    }
}

function tbm_auto_get_existing_member_names(int $docId): array
{
    $pdo = tbm_db();
    $stmt = $pdo->prepare(
        'SELECT m.name
           FROM tbm_document_members dm
           JOIN tbm_members m ON m.id = dm.member_id
          WHERE dm.doc_id = ?
          ORDER BY dm.slot_order ASC, dm.id ASC
          LIMIT 8'
    );
    $stmt->execute([$docId]);

    $names = array_map(
        static fn(array $row): string => trim((string)($row['name'] ?? '')),
        $stmt->fetchAll()
    );

    while (count($names) < 8) {
        $names[] = '';
    }

    return array_slice($names, 0, 8);
}

function tbm_auto_resolve_instructor_id(string $preferredName): int
{
    $pdo = tbm_db();

    if ($preferredName !== '') {
        $stmt = $pdo->prepare(
            'SELECT id
               FROM tbm_instructors
              WHERE name = ? AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$preferredName]);
        $preferredId = (int)($stmt->fetchColumn() ?: 0);
        if ($preferredId > 0) {
            return $preferredId;
        }
    }

    $activeInstructor = tbm_get_active_instructor();
    $activeName = trim((string)($activeInstructor['name'] ?? ''));

    if ($activeName !== '') {
        $stmt = $pdo->prepare(
            'SELECT id
               FROM tbm_instructors
              WHERE name = ? AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$activeName]);
        $activeId = (int)($stmt->fetchColumn() ?: 0);
        if ($activeId > 0) {
            return $activeId;
        }
    }

    return 1;
}

function tbm_auto_default_team(): string
{
    return tbm_normalize_display_team_name('공사팀');
}

function tbm_auto_get_completed_document(string $targetDate): ?array
{
    $doc = tbm_get_document_for_team($targetDate, tbm_auto_default_team());
    if (!$doc) {
        return null;
    }

    $status = trim((string)($doc['generation_status'] ?? ''));
    $outputFile = trim((string)($doc['output_filename'] ?? ''));

    if ($status === 'success' && $outputFile !== '') {
        return $doc;
    }

    return null;
}

function tbm_auto_build_document_data(string $targetDate, array $content): array
{
    $existingDoc = tbm_get_document_for_team($targetDate, tbm_auto_default_team());
    $activeInstructor = tbm_get_active_instructor();
    $blankRiskRows = tbm_auto_blank_risk_rows();

    $names = $existingDoc
        ? tbm_auto_get_existing_member_names((int)$existingDoc['id'])
        : tbm_get_member_names();

    return [
        'doc_date' => $targetDate,
        'instructor_name' => trim((string)($existingDoc['instructor_name'] ?? $activeInstructor['name'] ?? '')),
        'instructor_position' => trim((string)($existingDoc['instructor_position'] ?? $activeInstructor['position'] ?? '')),
        'instructor_note' => '',
        'names' => $names,
        'today_work_1' => trim((string)($existingDoc['today_work_1'] ?? '')),
        'today_work_2' => trim((string)($existingDoc['today_work_2'] ?? '')),
        'risk_checks' => tbm_auto_decode_array($existingDoc['risk_checks'] ?? [], []),
        'risk_rows' => tbm_auto_decode_array($existingDoc['risk_rows'] ?? [], $blankRiskRows),
        'remarks' => trim((string)($existingDoc['remarks'] ?? '')),
        'edu_title' => trim((string)($content['edu_title'] ?? '')),
        'left_content' => trim((string)($content['body_text'] ?? '')),
        'quiz_1' => trim((string)($content['quiz_1'] ?? '')),
        'quiz_2' => trim((string)($content['quiz_2'] ?? '')),
        'quiz_3' => trim((string)($content['quiz_3'] ?? '')),
        'image_file' => trim((string)($content['image_file'] ?? '')),
        'source_url' => trim((string)($content['source_url'] ?? '')),
    ];
}

function tbm_auto_write_document(string $targetDate, array $content): array
{
    if (!is_dir(TBM_AUTO_OUTPUT_DIR)) {
        mkdir(TBM_AUTO_OUTPUT_DIR, 0777, true);
    }

    $data = tbm_auto_build_document_data($targetDate, $content);
    $docId = 0;

    $contentPayload = [
        'accident_date' => null,
        'accident_title' => $data['edu_title'],
        'edu_title' => $data['edu_title'],
        'body_text' => $data['left_content'],
        'image_file' => $data['image_file'],
        'source_url' => $data['source_url'] !== '' ? $data['source_url'] : null,
        'quiz_1' => $data['quiz_1'],
        'quiz_2' => $data['quiz_2'],
        'quiz_3' => $data['quiz_3'],
        'ai_generated' => 1,
    ];

    $contentId = tbm_insert_content($contentPayload);
    $defaultTeam = tbm_auto_default_team();
    $existingDoc = tbm_get_document_for_team($targetDate, $defaultTeam);
    $instructorId = tbm_auto_resolve_instructor_id($data['instructor_name']);

    try {
        $pdo = tbm_db();
        $documentTeam = tbm_normalize_display_team_name(trim((string)($existingDoc['team'] ?? $defaultTeam)));
        if ($documentTeam === '') {
            $documentTeam = $defaultTeam;
        }

        if ($existingDoc) {
            $docId = (int)$existingDoc['id'];
            $stmt = $pdo->prepare(
                'UPDATE tbm_documents
                    SET team = :team,
                        instructor_id = :iid,
                        content_id = :cid,
                        today_work_1 = :tw1,
                        today_work_2 = :tw2,
                        risk_checks = :checks,
                        risk_rows = :rows,
                        remarks = :remarks,
                        generation_status = "pending",
                        updated_at = NOW()
                  WHERE id = :id'
            );
            $stmt->execute([
                ':team' => $documentTeam,
                ':iid' => $instructorId,
                ':cid' => $contentId,
                ':tw1' => $data['today_work_1'],
                ':tw2' => $data['today_work_2'],
                ':checks' => json_encode($data['risk_checks'], JSON_UNESCAPED_UNICODE),
                ':rows' => json_encode($data['risk_rows'], JSON_UNESCAPED_UNICODE),
                ':remarks' => $data['remarks'],
                ':id' => $docId,
            ]);
        } else {
            $docId = tbm_create_document($targetDate, $instructorId, $contentId, $documentTeam);
            $stmt = $pdo->prepare(
                'UPDATE tbm_documents
                    SET team = :team,
                        instructor_id = :iid,
                        content_id = :cid,
                        today_work_1 = :tw1,
                        today_work_2 = :tw2,
                        risk_checks = :checks,
                        risk_rows = :rows,
                        remarks = :remarks,
                        generation_status = "pending",
                        updated_at = NOW()
                  WHERE id = :id'
            );
            $stmt->execute([
                ':team' => $documentTeam,
                ':iid' => $instructorId,
                ':cid' => $contentId,
                ':tw1' => $data['today_work_1'],
                ':tw2' => $data['today_work_2'],
                ':checks' => json_encode($data['risk_checks'], JSON_UNESCAPED_UNICODE),
                ':rows' => json_encode($data['risk_rows'], JSON_UNESCAPED_UNICODE),
                ':remarks' => $data['remarks'],
                ':id' => $docId,
            ]);
        }

        if (!$existingDoc) {
            tbm_link_document_members($docId, tbm_get_active_members());
        } else {
            $existingNames = array_filter(
                tbm_auto_get_existing_member_names($docId),
                static fn(string $name): bool => $name !== ''
            );
            if ($existingNames === []) {
                tbm_link_document_members($docId, tbm_get_active_members());
            }
        }

        $htmlContent = tbm_render_template($data);
        $fileName = 'tbm_' . date('Ymd_His') . '.html';
        [, $outputDir] = tbm_prepare_output_directory($documentTeam);
        $outputRelativePath = tbm_build_output_relative_path($fileName, $documentTeam);
        $outputPath = $outputDir . '/' . $fileName;

        if (file_put_contents($outputPath, $htmlContent) === false) {
            throw new RuntimeException('failed to write output file: ' . $outputPath);
        }

        tbm_update_document_result($docId, $outputRelativePath, 'success');
        tbm_log($docId, 'cron', 'success', '자동 생성: ' . $outputRelativePath);

        return [
            'doc_id' => $docId,
            'file_name' => $outputRelativePath,
            'output_path' => $outputPath,
            'title' => $data['edu_title'],
            'image_file' => $data['image_file'],
        ];
    } catch (Throwable $e) {
        if ($docId > 0) {
            tbm_update_document_result($docId, '', 'failed', $e->getMessage());
            tbm_log($docId, 'cron', 'failed', $e->getMessage());
        }

        throw $e;
    }
}

$targetDate = $argv[1] ?? date('Y-m-d');
$forceNewArg = strtolower(trim((string)($argv[2] ?? '0')));
$forceNew = in_array($forceNewArg, ['1', 'true', 'y', 'yes', 'on'], true);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    tbm_auto_out('[TBM AUTO] invalid date: ' . $targetDate, true);
    exit(1);
}

try {
    if (!$forceNew) {
        $completedDoc = tbm_auto_get_completed_document($targetDate);
        if ($completedDoc) {
            tbm_auto_out('[TBM AUTO] already exists');
            tbm_auto_out('date=' . $targetDate);
            tbm_auto_out('force_new=0');
            tbm_auto_out('title=' . trim((string)($completedDoc['edu_title'] ?? '')));
            tbm_auto_out('image_file=' . trim((string)($completedDoc['image_file'] ?? '')));
            tbm_auto_out('output_file=' . trim((string)($completedDoc['output_filename'] ?? '')));
            exit(0);
        }
    }

    $content = tbm_auto_generate_content_with_retry($targetDate, $forceNew);
    $result = tbm_auto_write_document($targetDate, $content);

    tbm_auto_out('[TBM AUTO] success');
    tbm_auto_out('date=' . $targetDate);
    tbm_auto_out('force_new=' . ($forceNew ? '1' : '0'));
    tbm_auto_out('title=' . trim((string)($result['title'] ?? '')));
    tbm_auto_out('image_file=' . trim((string)($result['image_file'] ?? '')));
    tbm_auto_out('output_file=' . trim((string)($result['file_name'] ?? '')));
    tbm_auto_out('doc_id=' . (int)($result['doc_id'] ?? 0));
    exit(0);
} catch (Throwable $e) {
    tbm_auto_out('[TBM AUTO] failed: ' . $e->getMessage(), true);
    exit(1);
}
