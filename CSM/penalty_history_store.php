<?php
declare(strict_types=1);

function pen_db_path(): string
{
    return __DIR__ . '/storage/penalty_history.sqlite';
}

function pen_evidence_dir(): string
{
    return __DIR__ . '/storage/penalty_history_evidence';
}

function pen_item_meta(): array
{
    return [
        '6.2.1' => [
            'code' => '6.2.1',
            'title' => '안전 "경고장" 발행건수',
            'short' => '안전 경고장',
            'subtypes' => [
                'general_manager' => ['label' => '안전보건총괄책임자 명의 발행 (-2점/건)', 'points' => -2.0],
                'safety_team' => ['label' => '안전관리팀 / 분공장 자체 발행 (-1점/건)', 'points' => -1.0],
            ],
            'evidence_guide' => '경고장 사본, 시정조치 완료 보고서 (미발행 시 0점)',
        ],
        '6.2.2' => [
            'code' => '6.2.2',
            'title' => '근로손실재해(LTI), 의료처치재해(MI)',
            'short' => 'LTI · MI 재해',
            'subtypes' => [
                'lti' => ['label' => '근로손실재해 LTI (-5점/건)', 'points' => -5.0],
                'mi' => ['label' => '의료처치재해 MI (-1점/건)', 'points' => -1.0],
            ],
            'evidence_guide' => '산재요양승인/반려여부 확인서 (근로복지공단), 재해조사서 (무재해 시 0점)',
        ],
        '6.2.3' => [
            'code' => '6.2.3',
            'title' => '환경사고',
            'short' => '환경사고',
            'subtypes' => [
                'legal' => ['label' => '환경사고 — 법적처분 (-3점/건)', 'points' => -3.0],
                'complaint' => ['label' => '환경사고 — 민원 발생 (-1점/건)', 'points' => -1.0],
            ],
            'evidence_guide' => '환경사고 보고서, 법적처분/민원 처리 및 개선 증빙 (무사고 시 0점)',
        ],
    ];
}

function pen_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dir = dirname(pen_db_path());
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . pen_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pen_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            subtype TEXT NOT NULL,
            subtype_label TEXT NOT NULL,
            deduction_points REAL NOT NULL DEFAULT 0.0,
            occur_date TEXT NOT NULL,
            issuer_or_place TEXT NOT NULL DEFAULT '',
            summary TEXT NOT NULL,
            corrective_action TEXT NOT NULL DEFAULT '',
            action_done_date TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT '조치완료',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS pen_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            item_code TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            uploaded_by TEXT NOT NULL,
            uploaded_at TEXT NOT NULL,
            deleted_at TEXT NULL
        );
    ");
    return $pdo;
}

function pen_upload_evidence(PDO $db, int $year, string $itemCode, ?array $file, string $base64Data, string $note, string $actor): void
{
    if (!isset(pen_item_meta()[$itemCode])) {
        throw new InvalidArgumentException('감점 항목을 확인해 주세요.');
    }
    $dir = pen_evidence_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    }

    if ($base64Data !== '' && preg_match('/^data:(image\/(?:png|jpeg|jpg|webp));base64,(.+)$/s', trim($base64Data), $m)) {
        $raw = base64_decode($m[2], true);
        if ($raw === false || strlen($raw) < 16 || strlen($raw) > 20 * 1024 * 1024) {
            throw new InvalidArgumentException('붙여넣기 이미지 크기를 확인해 주세요 (최대 20MB).');
        }
        $info = @getimagesizefromstring($raw);
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'png'];
        if ($info === false || !isset($extMap[$info['mime'] ?? ''])) {
            throw new InvalidArgumentException('유효한 이미지가 아닙니다.');
        }
        $ext = $extMap[$info['mime']];
        $stored = bin2hex(random_bytes(20)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $stored, $raw) === false) {
            throw new RuntimeException('스크린샷 증빙 저장 실패');
        }
        $origName = sprintf('증빙_%s_%s.%s', $itemCode, date('Ymd_His'), $ext);
        $db->prepare('INSERT INTO pen_evidence (year, item_code, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$year, $itemCode, $origName, $stored, (string)$info['mime'], strlen($raw), $note, $actor, date('Y-m-d H:i:s')]);
        return;
    }

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? '')) || (int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new InvalidArgumentException('20MB 이하의 증빙 파일을 선택하거나 스크린샷을 붙여넣어 주세요.');
    }
    $origName = mb_substr(basename(str_replace('\\', '/', (string)$file['name'])), 0, 180);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
        throw new InvalidArgumentException('PDF, JPG, PNG, XLSX, XLS, DOCX 파일만 첨부할 수 있습니다.');
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && @getimagesize((string)$file['tmp_name']) === false) {
        throw new InvalidArgumentException('이미지 파일을 확인해 주세요.');
    }
    if (in_array($ext, ['xlsx', 'docx'], true)) {
        $zip = new ZipArchive();
        if ($zip->open((string)$file['tmp_name']) !== true) {
            throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
        }
        $expected = $ext === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($expected) === false) {
            $zip->close();
            throw new InvalidArgumentException('Office 문서 형식을 확인해 주세요.');
        }
        $zip->close();
    }
    $stored = bin2hex(random_bytes(20)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('증빙 파일 저장 실패');
    }
    $db->prepare('INSERT INTO pen_evidence (year, item_code, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$year, $itemCode, $origName, $stored, $mime, (int)$file['size'], $note, $actor, date('Y-m-d H:i:s')]);
}

function csm_penalty_score_summary(int $year, string $itemCode): array
{
    $empty = [
        'earned' => 0.0,
        'incident_count' => 0,
        'evidence_count' => 0,
        'state' => 'assessed',
        'multiplier' => null,
        'revision' => 0,
        'automatic' => true,
        'status_text' => '감점 없음 (0건)',
    ];
    $dbPath = pen_db_path();
    if (!is_file($dbPath)) {
        return $empty;
    }
    try {
        $db = new PDO('sqlite:file:' . str_replace('\\', '/', $dbPath) . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $incCount = 0;
        $deduction = 0.0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pen_records'")->fetch()) {
            $q = $db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(deduction_points), 0) AS pts FROM pen_records WHERE year = ? AND item_code = ?');
            $q->execute([$year, $itemCode]);
            $r = $q->fetch();
            $incCount = (int)($r['cnt'] ?? 0);
            $deduction = round((float)($r['pts'] ?? 0.0), 2);
        }
        $evCount = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pen_evidence'")->fetch()) {
            $eq = $db->prepare('SELECT stored_name FROM pen_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL');
            $eq->execute([$year, $itemCode]);
            foreach ($eq->fetchAll() as $er) {
                $s = (string)$er['stored_name'];
                if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|xls|docx)$/D', $s) && is_file(pen_evidence_dir() . '/' . $s)) {
                    $evCount++;
                }
            }
        }
        return [
            'earned' => $deduction,
            'incident_count' => $incCount,
            'evidence_count' => $evCount,
            'state' => 'assessed',
            'multiplier' => null,
            'revision' => 0,
            'automatic' => true,
            'status_text' => $incCount === 0 ? '감점 없음 (0건)' : "{$incCount}건 발생 ({$deduction}점)",
        ];
    } catch (Throwable $e) {
        error_log('csm_penalty_score_summary error: ' . $e->getMessage());
        return $empty;
    }
}
