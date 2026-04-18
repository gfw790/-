<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/tbm_db.php';
require_once __DIR__ . '/tbm_functions.php';

const TBM_MIGRATION_SOURCE_TEAM = '공사팀';
const TBM_MIGRATION_TARGET_TEAM = '';
const TBM_MIGRATION_TARGET_LABEL = '공통';

$apply = in_array('--apply', $argv, true);

function tbm_migration_out(array $payload): void
{
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

function tbm_migration_target_relative_path(string $currentRelativePath): string
{
	$normalized = tbm_normalize_output_relative_path($currentRelativePath);
	if ($normalized === '') {
		return '';
	}

	$fileName = basename($normalized);
	return tbm_build_output_relative_path($fileName, TBM_MIGRATION_TARGET_TEAM);
}

try {
	$pdo = tbm_db();

	$stmt = $pdo->prepare(
		'SELECT DISTINCT d.id, d.doc_date, d.team, d.output_filename, d.generation_status
		   FROM tbm_documents d
		   JOIN tbm_generation_log gl
			 ON gl.doc_id = d.id
			AND gl.trigger_type = "cron"
		  WHERE d.team = :team
		  ORDER BY d.doc_date ASC, d.id ASC'
	);
	$stmt->execute([':team' => TBM_MIGRATION_SOURCE_TEAM]);
	$documents = $stmt->fetchAll();

	$report = [
		'mode' => $apply ? 'apply' : 'dry-run',
		'source_team' => TBM_MIGRATION_SOURCE_TEAM,
		'target_team' => TBM_MIGRATION_TARGET_LABEL,
		'candidate_count' => count($documents),
		'migrated_count' => 0,
		'skipped_count' => 0,
		'items' => [],
	];

	foreach ($documents as $document) {
		$docId = (int)($document['id'] ?? 0);
		$docDate = (string)($document['doc_date'] ?? '');
		$currentOutput = trim((string)($document['output_filename'] ?? ''));

		$sharedStmt = $pdo->prepare(
			'SELECT id, output_filename
			   FROM tbm_documents
			  WHERE doc_date = :doc_date
				AND team = ""
			  LIMIT 1'
		);
		$sharedStmt->execute([':doc_date' => $docDate]);
		$sharedExisting = $sharedStmt->fetch();

		$item = [
			'id' => $docId,
			'doc_date' => $docDate,
			'from_team' => (string)($document['team'] ?? ''),
			'to_team' => TBM_MIGRATION_TARGET_LABEL,
			'generation_status' => (string)($document['generation_status'] ?? ''),
			'old_output' => $currentOutput,
			'new_output' => tbm_migration_target_relative_path($currentOutput),
			'status' => 'pending',
		];

		if ($sharedExisting && (int)($sharedExisting['id'] ?? 0) !== $docId) {
			$item['status'] = 'skipped';
			$item['reason'] = 'same-date shared document already exists';
			$item['shared_id'] = (int)($sharedExisting['id'] ?? 0);
			$item['shared_output'] = (string)($sharedExisting['output_filename'] ?? '');
			$report['skipped_count']++;
			$report['items'][] = $item;
			continue;
		}

		if (!$apply) {
			$item['status'] = 'ready';
			$report['items'][] = $item;
			continue;
		}

		$pdo->beginTransaction();
		try {
			$newOutput = $item['new_output'];
			$movedFile = false;

			if ($currentOutput !== '' && $newOutput !== '' && $newOutput !== $currentOutput) {
				$sourcePath = tbm_resolve_output_full_path($currentOutput);
				$targetPath = tbm_resolve_output_full_path($newOutput);
				$targetDir = dirname($targetPath);

				if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
					throw new RuntimeException('target directory create failed: ' . $targetDir);
				}

				if (!is_file($sourcePath)) {
					throw new RuntimeException('source output file missing: ' . $sourcePath);
				}

				if (is_file($targetPath)) {
					throw new RuntimeException('target output file already exists: ' . $targetPath);
				}

				if (!rename($sourcePath, $targetPath)) {
					throw new RuntimeException('output file move failed: ' . $sourcePath . ' -> ' . $targetPath);
				}

				$movedFile = true;
			}

			$updateStmt = $pdo->prepare(
				'UPDATE tbm_documents
					SET team = :team,
						output_filename = :output_filename,
						updated_at = NOW()
				  WHERE id = :id'
			);
			$updateStmt->execute([
				':team' => TBM_MIGRATION_TARGET_TEAM,
				':output_filename' => $newOutput !== '' ? $newOutput : $currentOutput,
				':id' => $docId,
			]);

			tbm_log($docId, 'migration', 'success', sprintf(
				'공용 TBM 이관: %s -> %s%s',
				$item['from_team'],
				TBM_MIGRATION_TARGET_LABEL,
				$movedFile ? ' / 파일 이동 완료' : ''
			));

			$pdo->commit();

			$item['status'] = 'migrated';
			$item['file_moved'] = $movedFile;
			$report['migrated_count']++;
		} catch (Throwable $e) {
			$pdo->rollBack();
			$item['status'] = 'error';
			$item['reason'] = $e->getMessage();
			$report['skipped_count']++;
		}

		$report['items'][] = $item;
	}

	tbm_migration_out($report);
} catch (Throwable $e) {
	tbm_migration_out([
		'mode' => $apply ? 'apply' : 'dry-run',
		'error' => $e->getMessage(),
	]);
	exit(1);
}
