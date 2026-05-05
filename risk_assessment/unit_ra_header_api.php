<?php
// ================================================================
// unit_ra_header_api.php
// 단위 위험성평가서 헤더 등록 API
// GET  ?action=work_target  → work_target_master 목록 반환
// POST ?action=save         → unit_ra_header INSERT
// ================================================================

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/lib/hazard_4m.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

function ensure_work_type_sub_master(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_type_sub_master (
            sub_id INT NOT NULL AUTO_INCREMENT,
            process_category VARCHAR(100) NOT NULL,
            sub_name VARCHAR(255) NOT NULL,
            use_yn CHAR(1) NOT NULL DEFAULT 'Y',
            sort_no INT NOT NULL DEFAULT 0,
            PRIMARY KEY (sub_id),
            UNIQUE KEY uk_work_type_sub_master (process_category, sub_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO work_type_sub_master (process_category, sub_name, use_yn, sort_no)
        VALUES (:process_category, :sub_name, 'Y', :sort_no)
        ON DUPLICATE KEY UPDATE
            use_yn = VALUES(use_yn),
            sort_no = VALUES(sort_no)
    ");

    foreach ([
        ['결선', '결선', 10],
        ['결선', '해선', 20],
        ['결선', '해선 및 결선', 30],
    ] as [$processCategory, $subName, $sortNo]) {
        $stmt->execute([
            ':process_category' => $processCategory,
            ':sub_name' => $subName,
            ':sort_no' => $sortNo,
        ]);
    }
}

function decode_json_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function ensure_unit_ra_header_safe_work_standard_no(PDO $pdo): void
{
    $columnExists = (int)$pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unit_ra_header'
          AND COLUMN_NAME = 'safe_work_standard_no'
    ")->fetchColumn() > 0;

    if (!$columnExists) {
        $pdo->exec("
            ALTER TABLE unit_ra_header
            ADD COLUMN safe_work_standard_no VARCHAR(100) NULL AFTER sort_no
        ");
    }

    $indexExists = (int)$pdo->query("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unit_ra_header'
          AND INDEX_NAME = 'idx_unit_ra_header_safe_work_standard_no'
    ")->fetchColumn() > 0;

    if (!$indexExists) {
        $pdo->exec("
            ALTER TABLE unit_ra_header
            ADD INDEX idx_unit_ra_header_safe_work_standard_no (safe_work_standard_no)
        ");
    }
}

function text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function validate_process_category_name(string $processCategory, string $emptyMessage): ?string
{
    if ($processCategory === '') {
        return $emptyMessage;
    }

    if (text_length($processCategory) > 100) {
        return '공정명은 100자 이내로 입력해주세요.';
    }

    return null;
}

function process_category_exists(PDO $pdo, string $processCategory): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM work_target_master
        WHERE process_category = :process_category
    ");
    $stmt->execute([
        ':process_category' => $processCategory,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function count_process_category_unit_ra_usage(PDO $pdo, string $processCategory): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM unit_ra_header
        WHERE unit_type = 'target'
          AND process_name = :process_name
    ");
    $stmt->execute([
        ':process_name' => $processCategory,
    ]);

    return (int)$stmt->fetchColumn();
}

function validate_major_category_name(string $majorCategory, string $emptyMessage): ?string
{
    if ($majorCategory === '') {
        return $emptyMessage;
    }

    if (text_length($majorCategory) > 100) {
        return '대분류명은 100자 이내로 입력해주세요.';
        return '?遺꾨쪟紐낆? 100???대궡濡??낅젰?댁＜?몄슂.';
    }

    return null;
}

function validate_work_type_name(string $workType, string $emptyMessage): ?string
{
    if ($workType === '') {
        return $emptyMessage;
    }

    if (text_length($workType) > 255) {
        return '작업유형명은 255자 이내로 입력해주세요.';
        return '?묒뾽?좏삎紐낆? 255???대궡濡??낅젰?댁＜?몄슂.';
    }

    return null;
}

function build_target_unit_title(string $majorCategory, string $workType): string
{
    return $majorCategory . ' - ' . $workType;
}

function major_category_exists(PDO $pdo, string $processCategory, string $majorCategory): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM work_target_master
        WHERE process_category = :process_category
          AND major_category = :major_category
    ");
    $stmt->execute([
        ':process_category' => $processCategory,
        ':major_category' => $majorCategory,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function work_type_exists(PDO $pdo, string $processCategory, string $majorCategory, string $workType): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM work_target_master
        WHERE process_category = :process_category
          AND major_category = :major_category
          AND work_type = :work_type
    ");
    $stmt->execute([
        ':process_category' => $processCategory,
        ':major_category' => $majorCategory,
        ':work_type' => $workType,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function count_major_category_unit_ra_usage(PDO $pdo, string $processCategory, string $majorCategory): int
{
    $majorPrefix = $majorCategory . ' - ';
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM unit_ra_header
        WHERE unit_type = 'target'
          AND process_name = :process_name
          AND LEFT(unit_title, CHAR_LENGTH(:major_prefix_length)) = :major_prefix_value
    ");
    $stmt->execute([
        ':process_name' => $processCategory,
        ':major_prefix_length' => $majorPrefix,
        ':major_prefix_value' => $majorPrefix,
    ]);

    return (int)$stmt->fetchColumn();
}

function count_work_type_unit_ra_usage(PDO $pdo, string $processCategory, string $majorCategory, string $workType): int
{
    $unitTitle = build_target_unit_title($majorCategory, $workType);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM unit_ra_header
        WHERE unit_type = 'target'
          AND process_name = :process_name
          AND unit_title = :unit_title
    ");
    $stmt->execute([
        ':process_name' => $processCategory,
        ':unit_title' => $unitTitle,
    ]);

    return (int)$stmt->fetchColumn();
}

function count_non_placeholder_work_types(PDO $pdo, string $processCategory, string $majorCategory): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM work_target_master
        WHERE process_category = :process_category
          AND major_category = :major_category
          AND work_type IS NOT NULL
    ");
    $stmt->execute([
        ':process_category' => $processCategory,
        ':major_category' => $majorCategory,
    ]);

    return (int)$stmt->fetchColumn();
}

function is_reserved_process_category(string $processCategory): bool
{
    return in_array($processCategory, ['결선', '시운전'], true);
}

// ── work_target_master 목록 조회 ─────────────────────────────────
if ($action === 'work_target') {
    try {
        $pdo  = getDB();
        $rows = $pdo->query("
            SELECT
                target_id,
                process_category,
                major_category,
                work_type
            FROM work_target_master
            WHERE use_yn = 'Y'
            ORDER BY sort_no ASC, process_category ASC,
                     major_category ASC, work_type ASC
        ")->fetchAll();

        echo json_encode([
            'success' => true,
            'data'    => $rows,
        ], JSON_UNESCAPED_UNICODE);

    } catch (\PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── major_work_master 목록 조회 ──────────────────────────────────────
if ($action === 'major_work') {
    try {
        $pdo  = getDB();
        $rows = $pdo->query("
            SELECT major_work_id, major_work_name
            FROM major_work_master
            WHERE use_yn = 'Y'
            ORDER BY sort_no ASC
        ")->fetchAll();

        echo json_encode(['success'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── major_work_sub_master 하위 항목 조회 ─────────────────────────────
if ($action === 'major_work_sub') {
    $majorWorkId = filter_input(INPUT_GET, 'major_work_id', FILTER_VALIDATE_INT);
    if (!$majorWorkId) {
        echo json_encode(['success'=>false,'message'=>'major_work_id 필요'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT sub_id, sub_name
            FROM major_work_sub_master
            WHERE major_work_id = :id AND use_yn = 'Y'
            ORDER BY sort_no ASC
        ");
        $stmt->execute([':id' => $majorWorkId]);
        $rows = $stmt->fetchAll();

        echo json_encode(['success'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── tool_master 목록 조회 ─────────────────────────────────────────────
if ($action === 'tool') {
    try {
        $pdo  = getDB();
        $rows = $pdo->query("
            SELECT tool_id, tool_name
            FROM tool_master
            WHERE use_yn = 'Y'
            ORDER BY sort_no ASC
        ")->fetchAll();

        echo json_encode(['success'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'add_tool' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $toolName = trim((string)($body['tool_name'] ?? ''));
    if ($toolName === '') {
        echo json_encode(['success'=>false,'message'=>'추가할 공구/장비명을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $toolNameLength = function_exists('mb_strlen')
        ? mb_strlen($toolName, 'UTF-8')
        : strlen($toolName);
    if ($toolNameLength > 255) {
        echo json_encode(['success'=>false,'message'=>'공구/장비명은 255자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT tool_id, use_yn FROM tool_master WHERE tool_name = :tool_name LIMIT 1");
        $stmt->execute([':tool_name' => $toolName]);
        $existing = $stmt->fetch();

        if ($existing) {
            if (($existing['use_yn'] ?? 'Y') === 'Y') {
                echo json_encode(['success'=>false,'message'=>'이미 등록된 공구/장비입니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $updateStmt = $pdo->prepare("UPDATE tool_master SET use_yn = 'Y' WHERE tool_id = :tool_id");
            $updateStmt->execute([':tool_id' => $existing['tool_id']]);
            $toolId = (int)$existing['tool_id'];
        } else {
            $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM tool_master")->fetchColumn() + 1;
            $insertStmt = $pdo->prepare("
                INSERT INTO tool_master (tool_name, use_yn, sort_no)
                VALUES (:tool_name, 'Y', :sort_no)
            ");
            $insertStmt->execute([
                ':tool_name' => $toolName,
                ':sort_no' => $nextSortNo,
            ]);
            $toolId = (int)$pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => '공구/장비가 추가되었습니다.',
            'data' => [
                'tool_id' => $toolId,
                'tool_name' => $toolName,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── env_master 목록 조회 ──────────────────────────────────────────────
if ($action === 'env') {
    try {
        $pdo  = getDB();
        $rows = $pdo->query("
            SELECT env_id, env_name
            FROM env_master
            WHERE use_yn = 'Y'
            ORDER BY sort_no ASC
        ")->fetchAll();

        echo json_encode(['success'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'add_env' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $envName = trim((string)($body['env_name'] ?? ''));
    if ($envName === '') {
        echo json_encode(['success'=>false,'message'=>'추가할 작업환경명을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $envNameLength = function_exists('mb_strlen')
        ? mb_strlen($envName, 'UTF-8')
        : strlen($envName);
    if ($envNameLength > 255) {
        echo json_encode(['success'=>false,'message'=>'작업환경명은 255자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT env_id, use_yn FROM env_master WHERE env_name = :env_name LIMIT 1");
        $stmt->execute([':env_name' => $envName]);
        $existing = $stmt->fetch();

        if ($existing) {
            if (($existing['use_yn'] ?? 'Y') === 'Y') {
                echo json_encode(['success'=>false,'message'=>'이미 등록된 작업환경입니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $updateStmt = $pdo->prepare("UPDATE env_master SET use_yn = 'Y' WHERE env_id = :env_id");
            $updateStmt->execute([':env_id' => $existing['env_id']]);
            $envId = (int)$existing['env_id'];
        } else {
            $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM env_master")->fetchColumn() + 1;
            $insertStmt = $pdo->prepare("
                INSERT INTO env_master (env_name, use_yn, sort_no)
                VALUES (:env_name, 'Y', :sort_no)
            ");
            $insertStmt->execute([
                ':env_name' => $envName,
                ':sort_no' => $nextSortNo,
            ]);
            $envId = (int)$pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => '작업환경이 추가되었습니다.',
            'data' => [
                'env_id' => $envId,
                'env_name' => $envName,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'work_type_sub') {
    $processCategory = trim((string)($_GET['process_category'] ?? ''));
    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'process_category 필요'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo = getDB();
        ensure_work_type_sub_master($pdo);
        $stmt = $pdo->prepare("
            SELECT sub_id, sub_name
            FROM work_type_sub_master
            WHERE process_category = :process_category
              AND use_yn = 'Y'
            ORDER BY sort_no ASC, sub_id ASC
        ");
        $stmt->execute([':process_category' => $processCategory]);
        $rows = $stmt->fetchAll();
        echo json_encode(['success'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── unit_ra_header 저장 ───────────────────────────────────────────
if ($action === 'add_process_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'추가할 공정명을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $processCategoryLength = function_exists('mb_strlen')
        ? mb_strlen($processCategory, 'UTF-8')
        : strlen($processCategory);
    if ($processCategoryLength > 100) {
        echo json_encode(['success'=>false,'message'=>'공정명은 100자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        $existsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM work_target_master
            WHERE process_category = :process_category
        ");
        $existsStmt->execute([
            ':process_category' => $processCategory,
        ]);

        if ((int)$existsStmt->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'이미 등록된 공정명입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM work_target_master")->fetchColumn() + 1;
        $insertStmt = $pdo->prepare("
            INSERT INTO work_target_master (
                process_category,
                major_category,
                work_type,
                description,
                use_yn,
                sort_no
            ) VALUES (
                :process_category,
                NULL,
                NULL,
                NULL,
                'Y',
                :sort_no
            )
        ");
        $insertStmt->execute([
            ':process_category' => $processCategory,
            ':sort_no' => $nextSortNo,
        ]);

        echo json_encode([
            'success' => true,
            'message' => '공정명이 추가되었습니다.',
            'data' => [
                'process_category' => $processCategory,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;

    if ($templateProcessCategory === '') {
        echo json_encode(['success'=>false,'message'=>'기준 공정명을 선택해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $processCategoryLength = function_exists('mb_strlen')
        ? mb_strlen($processCategory, 'UTF-8')
        : strlen($processCategory);
    if ($processCategoryLength > 100) {
        echo json_encode(['success'=>false,'message'=>'공정명은 100자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($processCategory === $templateProcessCategory) {
        echo json_encode(['success'=>false,'message'=>'새 공정명은 기준 공정명과 다르게 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        $existsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM work_target_master
            WHERE process_category = :process_category
        ");
        $existsStmt->execute([
            ':process_category' => $processCategory,
        ]);

        if ((int)$existsStmt->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'이미 등록된 공정명입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $templateStmt = $pdo->prepare("
            SELECT major_category, work_type, description
            FROM work_target_master
            WHERE process_category = :template_process_category
              AND use_yn = 'Y'
            ORDER BY sort_no ASC, major_category ASC, target_id ASC
        ");
        $templateStmt->execute([
            ':template_process_category' => $templateProcessCategory,
        ]);
        $templateRows = $templateStmt->fetchAll();

        if (!$templateRows) {
            echo json_encode(['success'=>false,'message'=>'기준 공정의 대분류/작업유형 데이터를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM work_target_master")->fetchColumn() + 1;
        $insertStmt = $pdo->prepare("
            INSERT INTO work_target_master (
                process_category,
                major_category,
                work_type,
                description,
                use_yn,
                sort_no
            ) VALUES (
                :process_category,
                :major_category,
                :work_type,
                :description,
                'Y',
                :sort_no
            )
        ");

        $insertedPairs = [];
        $insertedMajorCategories = [];
        foreach ($templateRows as $templateRow) {
            $majorCategory = trim((string)($templateRow['major_category'] ?? ''));
            $workType = trim((string)($templateRow['work_type'] ?? ''));
            $pairKey = $majorCategory . "\n" . $workType;

            if ($majorCategory === '' || $workType === '' || isset($insertedPairs[$pairKey])) {
                continue;
            }

            $insertStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
                ':work_type' => $workType,
                ':description' => $templateRow['description'] ?? null,
                ':sort_no' => $nextSortNo,
            ]);

            $insertedPairs[$pairKey] = true;
            $insertedMajorCategories[$majorCategory] = true;
            $nextSortNo++;
        }

        if (!$insertedPairs) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'복사할 작업유형 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '공정명이 추가되었습니다.',
            'data' => [
                'process_category' => $processCategory,
                'template_process_category' => $templateProcessCategory,
                'major_categories' => array_keys($insertedMajorCategories),
                'work_type_count' => count($insertedPairs),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'rename_process_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $oldProcessCategory = trim((string)($body['old_process_category'] ?? ''));
    $newProcessCategory = trim((string)($body['new_process_category'] ?? ''));

    if ($oldProcessCategory === '') {
        echo json_encode(['success'=>false,'message'=>'변경할 기존 공정명이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (is_reserved_process_category($oldProcessCategory)) {
        echo json_encode([
            'success' => false,
            'message' => '이 공정명은 현재 작업유형 규칙에 사용되고 있어 변경할 수 없습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validationMessage = validate_process_category_name($newProcessCategory, '변경할 새 공정명을 입력해주세요.');
    if ($validationMessage !== null) {
        echo json_encode(['success'=>false,'message'=>$validationMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($oldProcessCategory === $newProcessCategory) {
        echo json_encode(['success'=>false,'message'=>'기존 공정명과 다른 이름을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        ensure_work_type_sub_master($pdo);

        if (!process_category_exists($pdo, $oldProcessCategory)) {
            echo json_encode(['success'=>false,'message'=>'변경할 공정명을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (process_category_exists($pdo, $newProcessCategory)) {
            echo json_encode(['success'=>false,'message'=>'이미 등록된 공정명입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $subExistsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM work_type_sub_master
            WHERE process_category = :process_category
        ");
        $subExistsStmt->execute([
            ':process_category' => $newProcessCategory,
        ]);
        if ((int)$subExistsStmt->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'이미 등록된 공정명입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        $updateTargetStmt = $pdo->prepare("
            UPDATE work_target_master
            SET process_category = :new_process_category
            WHERE process_category = :old_process_category
        ");
        $updateTargetStmt->execute([
            ':new_process_category' => $newProcessCategory,
            ':old_process_category' => $oldProcessCategory,
        ]);

        $updateSubStmt = $pdo->prepare("
            UPDATE work_type_sub_master
            SET process_category = :new_process_category
            WHERE process_category = :old_process_category
        ");
        $updateSubStmt->execute([
            ':new_process_category' => $newProcessCategory,
            ':old_process_category' => $oldProcessCategory,
        ]);

        $updateHeaderStmt = $pdo->prepare("
            UPDATE unit_ra_header
            SET process_name = :new_process_category
            WHERE unit_type = 'target'
              AND process_name = :old_process_category
        ");
        $updateHeaderStmt->execute([
            ':new_process_category' => $newProcessCategory,
            ':old_process_category' => $oldProcessCategory,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '공정명이 변경되었습니다.',
            'data' => [
                'old_process_category' => $oldProcessCategory,
                'new_process_category' => $newProcessCategory,
                'updated_target_count' => $updateTargetStmt->rowCount(),
                'updated_unit_ra_count' => $updateHeaderStmt->rowCount(),
                'updated_sub_count' => $updateSubStmt->rowCount(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'delete_process_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $validationMessage = validate_process_category_name($processCategory, '삭제할 공정명을 선택해주세요.');
    if ($validationMessage !== null) {
        echo json_encode(['success'=>false,'message'=>$validationMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (is_reserved_process_category($processCategory)) {
        echo json_encode([
            'success' => false,
            'message' => '이 공정명은 현재 작업유형 규칙에 사용되고 있어 삭제할 수 없습니다.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        ensure_work_type_sub_master($pdo);

        if (!process_category_exists($pdo, $processCategory)) {
            echo json_encode(['success'=>false,'message'=>'삭제할 공정명을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $usageCount = count_process_category_unit_ra_usage($pdo, $processCategory);
        if ($usageCount > 0) {
            echo json_encode([
                'success' => false,
                'message' => "이 공정명을 사용하는 단위평가서 {$usageCount}건이 있어 삭제할 수 없습니다. 먼저 해당 평가서의 공정명을 변경해주세요.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        $deleteSubStmt = $pdo->prepare("
            DELETE FROM work_type_sub_master
            WHERE process_category = :process_category
        ");
        $deleteSubStmt->execute([
            ':process_category' => $processCategory,
        ]);

        $deleteTargetStmt = $pdo->prepare("
            DELETE FROM work_target_master
            WHERE process_category = :process_category
        ");
        $deleteTargetStmt->execute([
            ':process_category' => $processCategory,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '공정명이 삭제되었습니다.',
            'data' => [
                'process_category' => $processCategory,
                'deleted_target_count' => $deleteTargetStmt->rowCount(),
                'deleted_sub_count' => $deleteSubStmt->rowCount(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'add_major_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $majorCategory = trim((string)($body['major_category'] ?? ''));
    $templateMajorCategory = trim((string)($body['template_major_category'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'공정명을 먼저 선택해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($majorCategory === '') {
        echo json_encode(['success'=>false,'message'=>'추가할 대분류명을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($templateMajorCategory === '') {
        $majorCategoryLength = function_exists('mb_strlen')
            ? mb_strlen($majorCategory, 'UTF-8')
            : strlen($majorCategory);
        if ($majorCategoryLength > 100) {
            echo json_encode(['success'=>false,'message'=>'대분류명은 100자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $pdo = getDB();

            $existsStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM work_target_master
                WHERE process_category = :process_category
                  AND major_category = :major_category
            ");
            $existsStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
            ]);

            if ((int)$existsStmt->fetchColumn() > 0) {
                echo json_encode(['success'=>false,'message'=>'이미 등록된 대분류입니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM work_target_master")->fetchColumn() + 1;
            $insertStmt = $pdo->prepare("
                INSERT INTO work_target_master (
                    process_category,
                    major_category,
                    work_type,
                    description,
                    use_yn,
                    sort_no
                ) VALUES (
                    :process_category,
                    :major_category,
                    NULL,
                    NULL,
                    'Y',
                    :sort_no
                )
            ");
            $insertStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
                ':sort_no' => $nextSortNo,
            ]);

            echo json_encode([
                'success' => true,
                'message' => '대분류가 추가되었습니다.',
                'data' => [
                    'process_category' => $processCategory,
                    'major_category' => $majorCategory,
                    'work_types' => [],
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'DB 오류: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
        echo json_encode(['success'=>false,'message'=>'기준이 될 대분류를 선택해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $majorCategoryLength = function_exists('mb_strlen')
        ? mb_strlen($majorCategory, 'UTF-8')
        : strlen($majorCategory);
    if ($majorCategoryLength > 100) {
        echo json_encode(['success'=>false,'message'=>'대분류명은 100자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($majorCategory === $templateMajorCategory) {
        echo json_encode(['success'=>false,'message'=>'새 대분류명은 기준 대분류와 다르게 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        $existsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM work_target_master
            WHERE process_category = :process_category
              AND major_category = :major_category
        ");
        $existsStmt->execute([
            ':process_category' => $processCategory,
            ':major_category' => $majorCategory,
        ]);

        if ((int)$existsStmt->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'이미 등록된 대분류입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $templateStmt = $pdo->prepare("
            SELECT work_type, description
            FROM work_target_master
            WHERE process_category = :process_category
              AND major_category = :template_major_category
              AND use_yn = 'Y'
            ORDER BY sort_no ASC, target_id ASC
        ");
        $templateStmt->execute([
            ':process_category' => $processCategory,
            ':template_major_category' => $templateMajorCategory,
        ]);
        $templateRows = $templateStmt->fetchAll();

        if (!$templateRows) {
            echo json_encode(['success'=>false,'message'=>'기준 대분류의 작업유형을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM work_target_master")->fetchColumn() + 1;
        $insertStmt = $pdo->prepare("
            INSERT INTO work_target_master (
                process_category,
                major_category,
                work_type,
                description,
                use_yn,
                sort_no
            ) VALUES (
                :process_category,
                :major_category,
                :work_type,
                :description,
                'Y',
                :sort_no
            )
        ");

        $insertedWorkTypes = [];
        foreach ($templateRows as $templateRow) {
            $workType = trim((string)($templateRow['work_type'] ?? ''));
            if ($workType === '' || in_array($workType, $insertedWorkTypes, true)) {
                continue;
            }

            $insertStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
                ':work_type' => $workType,
                ':description' => $templateRow['description'] ?? null,
                ':sort_no' => $nextSortNo,
            ]);

            $insertedWorkTypes[] = $workType;
            $nextSortNo++;
        }

        if (!$insertedWorkTypes) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'복사할 작업유형이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '대분류가 추가되었습니다.',
            'data' => [
                'process_category' => $processCategory,
                'major_category' => $majorCategory,
                'template_major_category' => $templateMajorCategory,
                'work_types' => $insertedWorkTypes,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'add_work_type' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $majorCategory = trim((string)($body['major_category'] ?? ''));
    $workType = trim((string)($body['work_type'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'공정명을 먼저 선택해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($majorCategory === '') {
        echo json_encode(['success'=>false,'message'=>'대분류를 먼저 선택해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($workType === '') {
        echo json_encode(['success'=>false,'message'=>'추가할 작업유형명을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $workTypeLength = function_exists('mb_strlen')
        ? mb_strlen($workType, 'UTF-8')
        : strlen($workType);
    if ($workTypeLength > 255) {
        echo json_encode(['success'=>false,'message'=>'작업유형명은 255자 이내로 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        $majorExistsStmt = $pdo->prepare("
            SELECT target_id
            FROM work_target_master
            WHERE process_category = :process_category
              AND major_category = :major_category
            ORDER BY sort_no ASC, target_id ASC
            LIMIT 1
        ");
        $majorExistsStmt->execute([
            ':process_category' => $processCategory,
            ':major_category' => $majorCategory,
        ]);

        if (!$majorExistsStmt->fetch()) {
            echo json_encode(['success'=>false,'message'=>'선택한 대분류를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $existsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM work_target_master
            WHERE process_category = :process_category
              AND major_category = :major_category
              AND work_type = :work_type
        ");
        $existsStmt->execute([
            ':process_category' => $processCategory,
            ':major_category' => $majorCategory,
            ':work_type' => $workType,
        ]);

        if ((int)$existsStmt->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'이미 등록된 작업유형입니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $placeholderStmt = $pdo->prepare("
            SELECT target_id
            FROM work_target_master
            WHERE process_category = :process_category
              AND major_category = :major_category
              AND work_type IS NULL
            ORDER BY sort_no ASC, target_id ASC
            LIMIT 1
        ");
        $placeholderStmt->execute([
            ':process_category' => $processCategory,
            ':major_category' => $majorCategory,
        ]);
        $placeholderId = (int)$placeholderStmt->fetchColumn();

        if ($placeholderId > 0) {
            $updateStmt = $pdo->prepare("
                UPDATE work_target_master
                SET work_type = :work_type,
                    use_yn = 'Y'
                WHERE target_id = :target_id
            ");
            $updateStmt->execute([
                ':work_type' => $workType,
                ':target_id' => $placeholderId,
            ]);
        } else {
            $nextSortNo = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM work_target_master")->fetchColumn() + 1;
            $insertStmt = $pdo->prepare("
                INSERT INTO work_target_master (
                    process_category,
                    major_category,
                    work_type,
                    description,
                    use_yn,
                    sort_no
                ) VALUES (
                    :process_category,
                    :major_category,
                    :work_type,
                    NULL,
                    'Y',
                    :sort_no
                )
            ");
            $insertStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
                ':work_type' => $workType,
                ':sort_no' => $nextSortNo,
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => '작업유형이 추가되었습니다.',
            'data' => [
                'process_category' => $processCategory,
                'major_category' => $majorCategory,
                'work_type' => $workType,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'DB 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'rename_major_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'?붿껌 ?곗씠?곌? ?놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $oldMajorCategory = trim((string)($body['old_major_category'] ?? ''));
    $newMajorCategory = trim((string)($body['new_major_category'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'怨듭젙紐낆쓣 癒쇱? ?좏깮?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($oldMajorCategory === '') {
        echo json_encode(['success'=>false,'message'=>'蹂寃쏀븷 湲곗〈 ?遺꾨쪟紐낆씠 ?놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validationMessage = validate_major_category_name($newMajorCategory, '蹂寃쏀븷 ??遺꾨쪟紐낆쓣 ?낅젰?댁＜?몄슂.');
    if ($validationMessage !== null) {
        echo json_encode(['success'=>false,'message'=>$validationMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($oldMajorCategory === $newMajorCategory) {
        echo json_encode(['success'=>false,'message'=>'湲곗〈 ?遺꾨쪟紐낃낵 ?ㅻⅨ ?대쫫???낅젰?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        if (!major_category_exists($pdo, $processCategory, $oldMajorCategory)) {
            echo json_encode(['success'=>false,'message'=>'蹂寃쏀븷 ?遺꾨쪟瑜?李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (major_category_exists($pdo, $processCategory, $newMajorCategory)) {
            echo json_encode(['success'=>false,'message'=>'?대? ?깅줉???遺꾨쪟?낅땲??'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $oldMajorPrefix = $oldMajorCategory . ' - ';
        $newMajorPrefix = $newMajorCategory . ' - ';

        $pdo->beginTransaction();

        $updateTargetStmt = $pdo->prepare("
            UPDATE work_target_master
            SET major_category = :new_major_category
            WHERE process_category = :process_category
              AND major_category = :old_major_category
        ");
        $updateTargetStmt->execute([
            ':new_major_category' => $newMajorCategory,
            ':process_category' => $processCategory,
            ':old_major_category' => $oldMajorCategory,
        ]);

        $updateHeaderStmt = $pdo->prepare("
            UPDATE unit_ra_header
            SET unit_title = CONCAT(:new_major_prefix, SUBSTRING(unit_title, CHAR_LENGTH(:old_major_prefix_length) + 1))
            WHERE unit_type = 'target'
              AND process_name = :process_name
              AND LEFT(unit_title, CHAR_LENGTH(:old_major_prefix_match_length)) = :old_major_prefix_match_value
        ");
        $updateHeaderStmt->execute([
            ':new_major_prefix' => $newMajorPrefix,
            ':old_major_prefix_length' => $oldMajorPrefix,
            ':old_major_prefix_match_length' => $oldMajorPrefix,
            ':old_major_prefix_match_value' => $oldMajorPrefix,
            ':process_name' => $processCategory,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '?遺꾨쪟媛 蹂寃쎈릺?덉뒿?덈떎.',
            'data' => [
                'process_category' => $processCategory,
                'old_major_category' => $oldMajorCategory,
                'new_major_category' => $newMajorCategory,
                'updated_target_count' => $updateTargetStmt->rowCount(),
                'updated_unit_ra_count' => $updateHeaderStmt->rowCount(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB ?ㅻ쪟: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'delete_major_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'?붿껌 ?곗씠?곌? ?놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $majorCategory = trim((string)($body['major_category'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'怨듭젙紐낆쓣 癒쇱? ?좏깮?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validationMessage = validate_major_category_name($majorCategory, '??젣???遺꾨쪟瑜??좏깮?댁＜?몄슂.');
    if ($validationMessage !== null) {
        echo json_encode(['success'=>false,'message'=>$validationMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        if (!major_category_exists($pdo, $processCategory, $majorCategory)) {
            echo json_encode(['success'=>false,'message'=>'??젣???遺꾨쪟瑜?李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $usageCount = count_major_category_unit_ra_usage($pdo, $processCategory, $majorCategory);
        if ($usageCount > 0) {
            echo json_encode([
                'success' => false,
                'message' => "???遺꾨쪟瑜? ?ъ슜?섎뒗 ?⑥쐞?됯???{$usageCount}嫄댁씠 ?덉뼱 ??젣?????놁뒿?덈떎. 癒쇱? ?대떦 ?됯??쒖쓽 ?遺꾨쪟瑜?蹂寃쏀빐二쇱꽭??",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        $deleteTargetStmt = $pdo->prepare("
            DELETE FROM work_target_master
            WHERE process_category = :process_category
              AND major_category = :major_category
        ");
        $deleteTargetStmt->execute([
            ':process_category' => $processCategory,
            ':major_category' => $majorCategory,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '?遺꾨쪟媛 ??젣?섏뿀?듬땲??',
            'data' => [
                'process_category' => $processCategory,
                'major_category' => $majorCategory,
                'deleted_target_count' => $deleteTargetStmt->rowCount(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB ?ㅻ쪟: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'rename_work_type' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'?붿껌 ?곗씠?곌? ?놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $majorCategory = trim((string)($body['major_category'] ?? ''));
    $oldWorkType = trim((string)($body['old_work_type'] ?? ''));
    $newWorkType = trim((string)($body['new_work_type'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'怨듭젙紐낆쓣 癒쇱? ?좏깮?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($majorCategory === '') {
        echo json_encode(['success'=>false,'message'=>'?遺꾨쪟瑜?癒쇱? ?좏깮?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($oldWorkType === '') {
        echo json_encode(['success'=>false,'message'=>'蹂寃쏀븷 湲곗〈 ?묒뾽?좏삎紐낆씠 ?놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validationMessage = validate_work_type_name($newWorkType, '蹂寃쏀븷 ??묒뾽?좏삎紐낆쓣 ?낅젰?댁＜?몄슂.');
    if ($validationMessage !== null) {
        echo json_encode(['success'=>false,'message'=>$validationMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($oldWorkType === $newWorkType) {
        echo json_encode(['success'=>false,'message'=>'湲곗〈 ?묒뾽?좏삎紐낃낵 ?ㅻⅨ ?대쫫???낅젰?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        if (!major_category_exists($pdo, $processCategory, $majorCategory)) {
            echo json_encode(['success'=>false,'message'=>'?좏깮???遺꾨쪟瑜?李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!work_type_exists($pdo, $processCategory, $majorCategory, $oldWorkType)) {
            echo json_encode(['success'=>false,'message'=>'蹂寃쏀븷 ?묒뾽?좏삎???李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (work_type_exists($pdo, $processCategory, $majorCategory, $newWorkType)) {
            echo json_encode(['success'=>false,'message'=>'?대? ?깅줉???묒뾽?좏삎?낅땲??'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $oldUnitTitle = build_target_unit_title($majorCategory, $oldWorkType);
        $newUnitTitle = build_target_unit_title($majorCategory, $newWorkType);

        $pdo->beginTransaction();

        $updateTargetStmt = $pdo->prepare("
            UPDATE work_target_master
            SET work_type = :new_work_type
            WHERE process_category = :process_category
              AND major_category = :major_category
              AND work_type = :old_work_type
        ");
        $updateTargetStmt->execute([
            ':new_work_type' => $newWorkType,
            ':process_category' => $processCategory,
            ':major_category' => $majorCategory,
            ':old_work_type' => $oldWorkType,
        ]);

        $updateHeaderStmt = $pdo->prepare("
            UPDATE unit_ra_header
            SET unit_title = :new_unit_title
            WHERE unit_type = 'target'
              AND process_name = :process_name
              AND unit_title = :old_unit_title
        ");
        $updateHeaderStmt->execute([
            ':new_unit_title' => $newUnitTitle,
            ':process_name' => $processCategory,
            ':old_unit_title' => $oldUnitTitle,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '?묒뾽?좏삎??蹂寃쎈릺?덉뒿?덈떎.',
            'data' => [
                'process_category' => $processCategory,
                'major_category' => $majorCategory,
                'old_work_type' => $oldWorkType,
                'new_work_type' => $newWorkType,
                'updated_target_count' => $updateTargetStmt->rowCount(),
                'updated_unit_ra_count' => $updateHeaderStmt->rowCount(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB ?ㅻ쪟: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'delete_work_type' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'?붿껌 ?곗씠?곌? ?놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processCategory = trim((string)($body['process_category'] ?? ''));
    $majorCategory = trim((string)($body['major_category'] ?? ''));
    $workType = trim((string)($body['work_type'] ?? ''));

    if ($processCategory === '') {
        echo json_encode(['success'=>false,'message'=>'怨듭젙紐낆쓣 癒쇱? ?좏깮?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($majorCategory === '') {
        echo json_encode(['success'=>false,'message'=>'?遺꾨쪟瑜?癒쇱? ?좏깮?댁＜?몄슂.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validationMessage = validate_work_type_name($workType, '??젣???묒뾽?좏삎???좏깮?댁＜?몄슂.');
    if ($validationMessage !== null) {
        echo json_encode(['success'=>false,'message'=>$validationMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();

        if (!major_category_exists($pdo, $processCategory, $majorCategory)) {
            echo json_encode(['success'=>false,'message'=>'?좏깮???遺꾨쪟瑜?李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!work_type_exists($pdo, $processCategory, $majorCategory, $workType)) {
            echo json_encode(['success'=>false,'message'=>'??젣???묒뾽?좏삎???李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $usageCount = count_work_type_unit_ra_usage($pdo, $processCategory, $majorCategory, $workType);
        if ($usageCount > 0) {
            echo json_encode([
                'success' => false,
                'message' => "???묒뾽?좏삎???ъ슜?섎뒗 ?⑥쐞?됯???{$usageCount}嫄댁씠 ?덉뼱 ??젣?????놁뒿?덈떎. 癒쇱? ?대떦 ?됯??쒖쓽 ?묒뾽?좏삎???蹂寃쏀빐二쇱꽭??",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $nonPlaceholderCount = count_non_placeholder_work_types($pdo, $processCategory, $majorCategory);

        $pdo->beginTransaction();

        $deletedTargetCount = 0;
        $placeholderPreserved = false;

        if ($nonPlaceholderCount <= 1) {
            $placeholderStmt = $pdo->prepare("
                SELECT target_id
                FROM work_target_master
                WHERE process_category = :process_category
                  AND major_category = :major_category
                  AND work_type IS NULL
                ORDER BY sort_no ASC, target_id ASC
                LIMIT 1
            ");
            $placeholderStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
            ]);
            $placeholderId = (int)$placeholderStmt->fetchColumn();

            if ($placeholderId > 0) {
                $deleteTargetStmt = $pdo->prepare("
                    DELETE FROM work_target_master
                    WHERE process_category = :process_category
                      AND major_category = :major_category
                      AND work_type = :work_type
                ");
                $deleteTargetStmt->execute([
                    ':process_category' => $processCategory,
                    ':major_category' => $majorCategory,
                    ':work_type' => $workType,
                ]);
                $deletedTargetCount = $deleteTargetStmt->rowCount();
                $placeholderPreserved = true;
            } else {
                $targetStmt = $pdo->prepare("
                    SELECT target_id
                    FROM work_target_master
                    WHERE process_category = :process_category
                      AND major_category = :major_category
                      AND work_type = :work_type
                    ORDER BY sort_no ASC, target_id ASC
                    LIMIT 1
                ");
                $targetStmt->execute([
                    ':process_category' => $processCategory,
                    ':major_category' => $majorCategory,
                    ':work_type' => $workType,
                ]);
                $targetId = (int)$targetStmt->fetchColumn();

                if ($targetId <= 0) {
                    $pdo->rollBack();
                    echo json_encode(['success'=>false,'message'=>'??젣???묒뾽?좏삎???李얠쓣 ???놁뒿?덈떎.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $updatePlaceholderStmt = $pdo->prepare("
                    UPDATE work_target_master
                    SET work_type = NULL,
                        description = NULL,
                        use_yn = 'Y'
                    WHERE target_id = :target_id
                ");
                $updatePlaceholderStmt->execute([
                    ':target_id' => $targetId,
                ]);

                $deleteExtraStmt = $pdo->prepare("
                    DELETE FROM work_target_master
                    WHERE process_category = :process_category
                      AND major_category = :major_category
                      AND work_type = :work_type
                ");
                $deleteExtraStmt->execute([
                    ':process_category' => $processCategory,
                    ':major_category' => $majorCategory,
                    ':work_type' => $workType,
                ]);

                $deletedTargetCount = $deleteExtraStmt->rowCount();
                $placeholderPreserved = true;
            }
        } else {
            $deleteTargetStmt = $pdo->prepare("
                DELETE FROM work_target_master
                WHERE process_category = :process_category
                  AND major_category = :major_category
                  AND work_type = :work_type
            ");
            $deleteTargetStmt->execute([
                ':process_category' => $processCategory,
                ':major_category' => $majorCategory,
                ':work_type' => $workType,
            ]);
            $deletedTargetCount = $deleteTargetStmt->rowCount();
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '?묒뾽?좏삎??젣?섏뿀?듬땲??',
            'data' => [
                'process_category' => $processCategory,
                'major_category' => $majorCategory,
                'work_type' => $workType,
                'deleted_target_count' => $deletedTargetCount,
                'preserved_major_category' => $placeholderPreserved,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'message' => 'DB ?ㅻ쪟: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'detail') {
    $unitRaId = filter_input(INPUT_GET, 'unit_ra_id', FILTER_VALIDATE_INT);
    if (!$unitRaId) {
        echo json_encode(['success'=>false,'message'=>'unit_ra_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        ensure_unit_ra_header_safe_work_standard_no($pdo);
        $stmt = $pdo->prepare("
            SELECT
                unit_ra_id,
                unit_type,
                unit_title,
                unit_code,
                process_name,
                use_yn,
                sort_no,
                safe_work_standard_no,
                remark,
                created_by,
                evaluator_name,
                created_at,
                updated_at
            FROM unit_ra_header
            WHERE unit_ra_id = :unit_ra_id
            LIMIT 1
        ");
        $stmt->execute([':unit_ra_id' => $unitRaId]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['success'=>false,'message'=>'평가 정보를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success'=>true,'data'=>$row], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'preview') {
    $unitRaId = filter_input(INPUT_GET, 'unit_ra_id', FILTER_VALIDATE_INT);
    if (!$unitRaId) {
        echo json_encode(['success'=>false,'message'=>'unit_ra_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        ensure_unit_ra_header_safe_work_standard_no($pdo);

        $headerStmt = $pdo->prepare("
            SELECT
                unit_ra_id,
                unit_type,
                unit_title,
                unit_code,
                process_name,
                use_yn,
                sort_no,
                safe_work_standard_no,
                remark,
                created_by,
                evaluator_name,
                created_at,
                updated_at
            FROM unit_ra_header
            WHERE unit_ra_id = :unit_ra_id
            LIMIT 1
        ");
        $headerStmt->execute([':unit_ra_id' => $unitRaId]);
        $header = $headerStmt->fetch();

        if (!$header) {
            echo json_encode(['success'=>false,'message'=>'평가 정보를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $itemsStmt = $pdo->prepare("
            SELECT
                item_id,
                sort_no,
                task_code,
                task_name,
                hazard_name,
                hazard_4m,
                accident_type,
                injury_result,
                cause_text,
                current_control_text,
                additional_control_text,
                likelihood_before,
                severity_before,
                risk_score_before,
                likelihood_current,
                severity_current,
                risk_score_current,
                likelihood_after,
                severity_after,
                risk_score_after,
                improvement_due_date,
                remark
            FROM unit_ra_item
            WHERE unit_ra_id = :unit_ra_id
              AND use_yn = 'Y'
            ORDER BY sort_no ASC, item_id ASC
        ");
        $itemsStmt->execute([':unit_ra_id' => $unitRaId]);
        $items = array_map(
            static fn(array $item): array => hazard_4m_enrich($item, true),
            $itemsStmt->fetchAll() ?: []
        );

        echo json_encode([
            'success' => true,
            'data' => [
                'header' => $header,
                'items' => $items,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 필수값 검증
    if (empty($body['unit_title'])) {
        echo json_encode(['success'=>false,'message'=>'단위평가서명은 필수입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($body['unit_type'])) {
        echo json_encode(['success'=>false,'message'=>'평가유형은 필수입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $validTypes = ['target','major_work','tool','env'];
    if (!in_array($body['unit_type'], $validTypes, true)) {
        echo json_encode(['success'=>false,'message'=>'평가유형이 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDB();
        ensure_unit_ra_header_safe_work_standard_no($pdo);
        $unitRaId = isset($body['unit_ra_id']) ? (int)$body['unit_ra_id'] : 0;
        $isEdit = $unitRaId > 0;

        $unitCode = isset($body['unit_code']) ? strtoupper(trim((string)$body['unit_code'])) : '';

        // unit_code 중복 체크
        if ($unitCode !== '') {
            $chkSql = "SELECT COUNT(*) FROM unit_ra_header WHERE unit_code = :code";
            $chkParams = [':code' => $unitCode];
            if ($isEdit) {
                $chkSql .= " AND unit_ra_id <> :unit_ra_id";
                $chkParams[':unit_ra_id'] = $unitRaId;
            }
            $chk = $pdo->prepare($chkSql);
            $chk->execute($chkParams);
            if ($chk->fetchColumn() > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => "평가서 코드 '{$unitCode}' 가 이미 존재합니다.",
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $params = [
            ':unit_type'    => $body['unit_type'],
            ':unit_title'   => $body['unit_title'],
            ':unit_code'    => $unitCode !== '' ? $unitCode : null,
            ':process_name' => $body['process_name'] ?? null,
            ':use_yn'       => $body['use_yn']       ?? 'Y',
            ':sort_no'      => $body['sort_no']      ?? 0,
            ':safe_work_standard_no' => $body['safe_work_standard_no'] ?? null,
            ':remark'       => $body['remark']       ?? null,
            ':created_by'   => $body['created_by']   ?? null,
            ':evaluator_name' => $body['evaluator_name'] ?? null,
        ];

        if ($isEdit) {
            $exists = $pdo->prepare("
                SELECT unit_ra_id
                FROM unit_ra_header
                WHERE unit_ra_id = :unit_ra_id
                LIMIT 1
            ");
            $exists->execute([':unit_ra_id' => $unitRaId]);
            if (!$exists->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => '수정할 평가 정보를 찾을 수 없습니다.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sql = "
                UPDATE unit_ra_header
                SET unit_type = :unit_type,
                    unit_title = :unit_title,
                    unit_code = :unit_code,
                    process_name = :process_name,
                    use_yn = :use_yn,
                    sort_no = :sort_no,
                    safe_work_standard_no = :safe_work_standard_no,
                    remark = :remark,
                    created_by = :created_by,
                    evaluator_name = :evaluator_name,
                    updated_at = NOW()
                WHERE unit_ra_id = :unit_ra_id
            ";
            $params[':unit_ra_id'] = $unitRaId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $sql = "
                INSERT INTO unit_ra_header
                    (unit_type, unit_title, unit_code, process_name,
                     use_yn, sort_no, safe_work_standard_no, remark, created_by, evaluator_name,
                     created_at, updated_at)
                VALUES
                    (:unit_type, :unit_title, :unit_code, :process_name,
                     :use_yn, :sort_no, :safe_work_standard_no, :remark, :created_by, :evaluator_name,
                     NOW(), NOW())
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $unitRaId = (int)$pdo->lastInsertId();
        }

        if ($unitCode === '') {
            $unitCode = sprintf('URA-%s-%03d', date('Y'), $unitRaId);
            $upd = $pdo->prepare("
                UPDATE unit_ra_header
                SET unit_code = :unit_code
                WHERE unit_ra_id = :unit_ra_id
            ");
            $upd->execute([
                ':unit_code' => $unitCode,
                ':unit_ra_id' => $unitRaId,
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => '저장 완료',
            'data'    => [
                'unit_ra_id'  => $unitRaId,
                'unit_title'  => $body['unit_title'],
                'unit_code'   => $unitCode,
                'unit_type'   => $body['unit_type'],
                'safe_work_standard_no' => $body['safe_work_standard_no'] ?? null,
                'process_name'=> $body['process_name'] ?? '',
            ],
        ], JSON_UNESCAPED_UNICODE);

    } catch (\PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'DB 저장 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'update_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = decode_json_body();
    if (!$body) {
        echo json_encode(['success'=>false,'message'=>'요청 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $unitRaId = isset($body['unit_ra_id']) ? (int)$body['unit_ra_id'] : 0;
    if ($unitRaId <= 0) {
        echo json_encode(['success'=>false,'message'=>'unit_ra_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $unitCode = isset($body['unit_code']) ? strtoupper(trim((string)$body['unit_code'])) : '';

    try {
        $pdo = getDB();

        $exists = $pdo->prepare("
            SELECT unit_ra_id
            FROM unit_ra_header
            WHERE unit_ra_id = :unit_ra_id
              AND use_yn = 'Y'
            LIMIT 1
        ");
        $exists->execute([':unit_ra_id' => $unitRaId]);
        if (!$exists->fetch()) {
            echo json_encode(['success'=>false,'message'=>'대상 평가서를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($unitCode === '') {
            $unitCode = sprintf('URA-%s-%03d', date('Y'), $unitRaId);
        }

        $chk = $pdo->prepare("
            SELECT COUNT(*)
            FROM unit_ra_header
            WHERE unit_code = :code
              AND unit_ra_id <> :unit_ra_id
        ");
        $chk->execute([
            ':code' => $unitCode,
            ':unit_ra_id' => $unitRaId,
        ]);
        if ($chk->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => "평가서 코드 '{$unitCode}' 가 이미 존재합니다.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $upd = $pdo->prepare("
            UPDATE unit_ra_header
            SET unit_code = :unit_code,
                updated_at = NOW()
            WHERE unit_ra_id = :unit_ra_id
        ");
        $upd->execute([
            ':unit_code' => $unitCode,
            ':unit_ra_id' => $unitRaId,
        ]);

        echo json_encode([
            'success' => true,
            'message' => '평가서 코드가 수정되었습니다.',
            'data' => [
                'unit_ra_id' => $unitRaId,
                'unit_code' => $unitCode,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'DB 저장 오류: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── 잘못된 요청 ───────────────────────────────────────────────────
echo json_encode([
    'success' => false,
    'message' => '잘못된 요청입니다. action 파라미터를 확인하세요.',
], JSON_UNESCAPED_UNICODE);
