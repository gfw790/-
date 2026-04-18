<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/tbm_db.php';

function tbm_quiz_suffix_cleanup(string $text): string
{
	$patterns = [
		'/\s*현장 안전기준으로 판단한다\./u',
		'/\s*재발 방지 원칙을 함께 고려한다\./u',
		'/\s*작업 전 점검 기준을 반영한다\./u',
	];

	$cleaned = preg_replace($patterns, '', $text);
	$cleaned = preg_replace('/\s{2,}/u', ' ', (string)$cleaned);

	return trim((string)$cleaned);
}

function tbm_cleanup_quiz_multiline(string $quiz): string
{
	$quiz = str_replace(["\r\n", "\r"], "\n", trim($quiz));
	if ($quiz === '') {
		return '';
	}

	$lines = preg_split('/\n+/u', $quiz) ?: [];
	foreach ($lines as $index => $line) {
		$line = trim((string)$line);
		if ($index === 0) {
			$line = tbm_quiz_suffix_cleanup($line);
		}
		$lines[$index] = $line;
	}

	$lines = array_values(array_filter($lines, static fn($line): bool => trim((string)$line) !== ''));
	return implode("\n", $lines);
}

$report = [
	'db_rows_updated' => 0,
	'cache_files_updated' => 0,
	'html_files_updated' => 0,
	'details' => [],
];

try {
	$pdo = tbm_db();
	$stmt = $pdo->query('SELECT id, quiz_1, quiz_2, quiz_3 FROM tbm_accident_content');
	$rows = $stmt ? $stmt->fetchAll() : [];

	foreach ($rows as $row) {
		$updated = false;
		$payload = [];
		foreach (['quiz_1', 'quiz_2', 'quiz_3'] as $key) {
			$original = (string)($row[$key] ?? '');
			$cleaned = tbm_cleanup_quiz_multiline($original);
			$payload[$key] = $cleaned;
			if ($cleaned !== $original) {
				$updated = true;
			}
		}

		if ($updated) {
			$updateStmt = $pdo->prepare(
				'UPDATE tbm_accident_content
					SET quiz_1 = :quiz_1,
						quiz_2 = :quiz_2,
						quiz_3 = :quiz_3
				  WHERE id = :id'
			);
			$updateStmt->execute([
				':quiz_1' => $payload['quiz_1'],
				':quiz_2' => $payload['quiz_2'],
				':quiz_3' => $payload['quiz_3'],
				':id' => (int)$row['id'],
			]);
			$report['db_rows_updated']++;
		}
	}

	foreach (glob(__DIR__ . '/cache/tbm_ai_*.json') ?: [] as $cacheFile) {
		$raw = file_get_contents($cacheFile);
		if ($raw === false) {
			continue;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			continue;
		}

		$updated = false;
		foreach (['quiz_1', 'quiz_2', 'quiz_3'] as $key) {
			$original = (string)($decoded[$key] ?? '');
			$cleaned = tbm_cleanup_quiz_multiline($original);
			if ($cleaned !== $original) {
				$decoded[$key] = $cleaned;
				$updated = true;
			}
		}

		if ($updated) {
			file_put_contents($cacheFile, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$report['cache_files_updated']++;
		}
	}

	$htmlFiles = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator(__DIR__ . '/output', FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $fileInfo) {
		if ($fileInfo->isFile() && strcasecmp($fileInfo->getExtension(), 'html') === 0) {
			$htmlFiles[] = $fileInfo->getPathname();
		}
	}

	foreach ($htmlFiles as $htmlFile) {
		$html = file_get_contents($htmlFile);
		if ($html === false) {
			continue;
		}

		$cleaned = tbm_quiz_suffix_cleanup($html);
		if ($cleaned !== $html) {
			file_put_contents($htmlFile, $cleaned);
			$report['html_files_updated']++;
		}
	}
} catch (Throwable $e) {
	$report['error'] = $e->getMessage();
	echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
	exit(1);
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
