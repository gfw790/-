<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$target = 'education_report.html?date=' . rawurlencode($date);

try {
    require_once __DIR__ . '/../../tbm/tbm_db.php';
    require_once __DIR__ . '/../../tbm/tbm_functions.php';

    $pdo = tbm_db();
    $stmt = $pdo->prepare(
        'SELECT output_filename
           FROM tbm_documents
          WHERE doc_date = :doc_date
            AND generation_status = "success"
            AND output_filename IS NOT NULL
            AND output_filename <> ""
          ORDER BY COALESCE(generated_at, updated_at) DESC, id DESC
          LIMIT 1'
    );
    $stmt->execute([':doc_date' => $date]);
    $outputFile = trim((string)($stmt->fetchColumn() ?: ''));

    if ($outputFile !== '') {
        $safeFile = tbm_normalize_output_relative_path($outputFile);
        if ($safeFile !== '') {
            $target .= '&file=' . rawurlencode($safeFile);
        }
    }
} catch (Throwable $e) {
    // Fallback to date-only mode if TBM DB lookup is unavailable.
}

header('Location: ' . $target, true, 302);
exit;
