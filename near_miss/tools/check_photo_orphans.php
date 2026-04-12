<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI에서만 실행할 수 있습니다.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';

ensureNearMissSchema();
syncAllNearMissPhotoLinks();

$options = getopt('', ['post-id::', 'delete-orphans', 'json']);
$postIdFilter = isset($options['post-id']) ? (int)$options['post-id'] : 0;
$deleteOrphans = array_key_exists('delete-orphans', $options);
$asJson = array_key_exists('json', $options);

function nmNormalizePath(string $path): string {
    $path = str_replace('/', '\\', trim($path));
    $path = rtrim($path, "\\");
    return strtolower($path);
}

function nmIsUnderRoot(string $path, string $root): bool {
    $pathNorm = nmNormalizePath($path);
    $rootNorm = nmNormalizePath($root);
    if ($rootNorm === '') {
        return false;
    }
    return $pathNorm === $rootNorm || str_starts_with($pathNorm, $rootNorm . '\\');
}

function nmCollectImageFiles(string $root): array {
    if ($root === '' || !is_dir($root)) {
        return [];
    }

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $files = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $baseName = strtolower($fileInfo->getBasename());
        if ($baseName === '.htaccess' || $baseName === '.gitkeep') {
            continue;
        }

        $ext = strtolower($fileInfo->getExtension());
        if (!in_array($ext, $imageExts, true)) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $files[nmNormalizePath($path)] = $path;
    }

    return $files;
}

$sql = "SELECT a.id, a.post_id, a.original_name, a.stored_name, a.mime_type, a.file_size
        FROM attachments a
        JOIN posts p ON p.id = a.post_id
        JOIN categories c ON c.id = p.category_id
        WHERE c.code = 'near_miss'";
$params = [];
if ($postIdFilter > 0) {
    $sql .= " AND p.id = ?";
    $params[] = $postIdFilter;
}
$sql .= " ORDER BY a.id ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$attachments = $stmt->fetchAll();

$referencedPathMap = [];
$missingFiles = [];
$imageAttachmentCount = 0;
foreach ($attachments as $att) {
    if (!isImageAttachment($att)) {
        continue;
    }
    $imageAttachmentCount++;

    $path = getAttachmentStoredPath($att);
    if ($path === null || !is_file($path)) {
        $missingFiles[] = [
            'attachment_id' => (int)$att['id'],
            'post_id' => (int)$att['post_id'],
            'stored_name' => (string)($att['stored_name'] ?? ''),
            'original_name' => (string)($att['original_name'] ?? ''),
        ];
        continue;
    }

    $pathNorm = nmNormalizePath($path);
    $referencedPathMap[$pathNorm] = [
        'attachment_id' => (int)$att['id'],
        'post_id' => (int)$att['post_id'],
        'path' => $path,
    ];
}

$activeMap = categoryUploadDirMap();
$legacyMap = legacyCategoryUploadDirMap();
$scanRoots = [
    'active_near_miss' => $activeMap['near_miss'] ?? '',
    'legacy_near_miss' => $legacyMap['near_miss'] ?? '',
    'legacy_local' => legacyUploadDir(),
];

$existingRoots = [];
$allScannedFiles = [];
foreach ($scanRoots as $label => $root) {
    if ($root === '' || !is_dir($root)) {
        continue;
    }
    $existingRoots[$label] = $root;
    $allScannedFiles += nmCollectImageFiles($root);
}

$orphanFiles = [];
foreach ($allScannedFiles as $normPath => $realPath) {
    if (isset($referencedPathMap[$normPath])) {
        continue;
    }

    $orphanFiles[] = [
        'path' => $realPath,
        'root_label' => '',
        'deleted' => false,
    ];
}

foreach ($orphanFiles as &$orphan) {
    foreach ($existingRoots as $label => $root) {
        if (nmIsUnderRoot($orphan['path'], $root)) {
            $orphan['root_label'] = $label;
            break;
        }
    }
}
unset($orphan);

$deletedCount = 0;
if ($deleteOrphans) {
    foreach ($orphanFiles as &$orphan) {
        $path = (string)($orphan['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }

        $safe = false;
        foreach ($existingRoots as $root) {
            if (nmIsUnderRoot($path, $root)) {
                $safe = true;
                break;
            }
        }
        if (!$safe) {
            continue;
        }

        if (@unlink($path)) {
            $orphan['deleted'] = true;
            $deletedCount++;
        }
    }
    unset($orphan);
}

$result = [
    'scanned_at' => date('c'),
    'post_id_filter' => $postIdFilter > 0 ? $postIdFilter : null,
    'scan_roots' => $existingRoots,
    'db_image_attachment_count' => $imageAttachmentCount,
    'missing_file_count' => count($missingFiles),
    'orphan_file_count' => count($orphanFiles),
    'deleted_orphan_count' => $deletedCount,
    'missing_files' => $missingFiles,
    'orphan_files' => $orphanFiles,
];

if ($asJson) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

echo "[Near Miss Photo Check]" . PHP_EOL;
echo "Scanned at: " . $result['scanned_at'] . PHP_EOL;
if ($result['post_id_filter'] !== null) {
    echo "Post filter: " . $result['post_id_filter'] . PHP_EOL;
}
echo "Image attachments in DB: " . $result['db_image_attachment_count'] . PHP_EOL;
echo "Missing files: " . $result['missing_file_count'] . PHP_EOL;
echo "Orphan files: " . $result['orphan_file_count'] . PHP_EOL;
if ($deleteOrphans) {
    echo "Deleted orphans: " . $result['deleted_orphan_count'] . PHP_EOL;
}

if (!empty($result['missing_files'])) {
    echo PHP_EOL . "[Missing Files]" . PHP_EOL;
    foreach ($result['missing_files'] as $item) {
        echo "- attachment_id=" . $item['attachment_id']
            . ", post_id=" . $item['post_id']
            . ", stored_name=" . $item['stored_name']
            . PHP_EOL;
    }
}

if (!empty($result['orphan_files'])) {
    echo PHP_EOL . "[Orphan Files]" . PHP_EOL;
    foreach ($result['orphan_files'] as $item) {
        $line = "- " . $item['path'];
        if (!empty($item['root_label'])) {
            $line .= " (" . $item['root_label'] . ")";
        }
        if (!empty($item['deleted'])) {
            $line .= " [deleted]";
        }
        echo $line . PHP_EOL;
    }
}

exit(0);
