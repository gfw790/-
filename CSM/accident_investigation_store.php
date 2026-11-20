<?php
declare(strict_types=1);

function ai_db_path(): string
{
    return __DIR__ . '/storage/accident_investigation.sqlite';
}

function ai_evidence_dir(): string
{
    return __DIR__ . '/storage/accident_investigation_evidence';
}

function ai_evidence_kinds(): array
{
    return [
        'no_accident_cert' => '무재해 / 산재요양승인·반려여부 확인서 (근로복지공단)',
        'response_manual' => '산업재해 발생 시 대응 및 원인조사 매뉴얼',
        'gov_report' => '산업재해조사표 (고용노동부 제출 사본)',
        'root_cause_report' => '사고 원인조사 및 재발방지대책 보고서',
        'sharing_edu' => '재해사례 전파·TBM 공유 증빙',
        'etc_211' => '기타 2.1.1 관련 증빙',
    ];
}

function ai_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(ai_db_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . ai_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_year_status (
            year INTEGER PRIMARY KEY,
            status TEXT NOT NULL DEFAULT 'na',
            no_accident_since TEXT NOT NULL DEFAULT '2021-01-01',
            confirm_date TEXT NOT NULL DEFAULT '',
            department TEXT NOT NULL DEFAULT '공사팀',
            manager_name TEXT NOT NULL DEFAULT '김남균',
            ceo_name TEXT NOT NULL DEFAULT '정연탁',
            confirm_note TEXT NOT NULL DEFAULT '해당년도 산업재해가 발생하지 않았으므로 CSM 2.1.1 평가 기준에 따라 해당없음(N/A)으로 표기하며, 비상 시 즉각 대응을 위한 산업재해 대응 및 원인조사 매뉴얼을 상시 비치·운영합니다.',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS ai_manual (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            revision_date TEXT NOT NULL DEFAULT '',
            manager_name TEXT NOT NULL DEFAULT '김남균',
            ceo_name TEXT NOT NULL DEFAULT '정연탁',
            step1_title TEXT NOT NULL DEFAULT '1단계. 초동 조치 및 긴급 구호 (발생 즉시)',
            step1_desc TEXT NOT NULL DEFAULT '• 즉시 작업을 중지하고 2차 재해 예방 조치(전원 차단, 위험구역 통제, 가스·유체 차단)를 실시한다.\n• 재해자 의식·호흡 확인 후 응급처치 및 119 신고·지정병원 긴급 이송을 진행한다.\n• 원인 조사를 위해 사고 현장·기계·공구 상태를 원형 그대로 보존한다.',
            step2_title TEXT NOT NULL DEFAULT '2단계. 사내 및 원청(한라시멘트) 긴급 보고 (발생 즉시 ~ 1시간 이내)',
            step2_desc TEXT NOT NULL DEFAULT '• 현장 작업자/반장 → 안전관리책임자(김남균) → 대표이사(정연탁) 순으로 즉시 유선 보고한다.\n• 한라시멘트 안전환경팀 및 발주 담당부서에 발생 일시·장소·재해자 상태·조치사항을 신속 보고한다.',
            step3_title TEXT NOT NULL DEFAULT '3단계. 법정 보고 (고용노동부 / 산업안전보건법 제57조)',
            step3_desc TEXT NOT NULL DEFAULT '• 중대재해(사망 1명 이상, 3개월 이상 요양 부상자 동시 2명 이상 등): 발생 사실을 알게 된 즉시 지방고용노동관서에 전화·팩스 등으로 지체 없이 보고한다.\n• 일반 산업재해(휴업 3일 이상): 발생일로부터 1개월 이내에 산업재해조사표를 작성하여 관할 지방고용노동관서에 제출한다.',
            step4_title TEXT NOT NULL DEFAULT '4단계. 사고 원인 조사 (4M 기법 분석)',
            step4_desc TEXT NOT NULL DEFAULT '• 현장 확인, 사진 촬영, 목격자·관련자 면담을 통해 객관적 사실관계를 조사한다.\n• 인적(Man)·설비(Machine)·작업방법(Media)·관리(Management) 4M 관점으로 직접원인과 근본원인을 도출한다.',
            step5_title TEXT NOT NULL DEFAULT '5단계. 재발방지대책 수립 및 위험성평가 수시 반영',
            step5_desc TEXT NOT NULL DEFAULT '• 공학적 대책(방호장치·설비개선), 관리적 대책(SOP 개정·교육), 개인보호구 대책을 수립하고 대표이사 승인을 받는다.\n• 수시 위험성평가를 즉시 실시하고 안전작업표준서(SOP) 및 작업계획서에 반영한다.',
            step6_title TEXT NOT NULL DEFAULT '6단계. 전 근로자 공유·교육 및 기록 보존 (3년 이상 / CSM 5년)',
            step6_desc TEXT NOT NULL DEFAULT '• TBM 및 특별안전보건교육을 통해 전 근로자에게 사고 원인과 재발방지대책을 전파한다.\n• 산업재해조사표, 원인조사 보고서, 개선 전·후 사진 및 교육 서명부를 최소 3년(CSM 평가 기준 5년) 이상 보존한다.',
            emergency_contacts TEXT NOT NULL DEFAULT '응급구조·화재: 119 | 고용노동부: 1350 | 안전보건공단: 1644-4544 | 원청(한라시멘트) 안전환경팀: 현장 비상연락망 참조 | 주식회사 현대기전 대표이사(정연탁) / 관리책임자(김남균)',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS ai_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            occur_date TEXT NOT NULL,
            year INTEGER NOT NULL,
            location TEXT NOT NULL DEFAULT '',
            victim_info TEXT NOT NULL DEFAULT '',
            accident_type TEXT NOT NULL DEFAULT '',
            severity TEXT NOT NULL DEFAULT '휴업 3일 이상',
            gov_report_date TEXT NOT NULL DEFAULT '',
            direct_cause TEXT NOT NULL DEFAULT '',
            root_cause_4m TEXT NOT NULL DEFAULT '',
            countermeasure TEXT NOT NULL DEFAULT '',
            action_done_date TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT '완료',
            created_by TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS ai_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            kind TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            uploaded_by TEXT NOT NULL,
            uploaded_at TEXT NOT NULL,
            deleted_at TEXT NULL
        );

        CREATE TABLE IF NOT EXISTS ai_assessments (
            year INTEGER PRIMARY KEY,
            multiplier REAL NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            updated_by TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        );
    ");

    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT OR IGNORE INTO ai_manual (id, revision_date, updated_at) VALUES (1, ?, ?)')
        ->execute([date('Y') . '-01-02', $now]);

    return $pdo;
}

function ai_get_year_status(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM ai_year_status WHERE year = ?');
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT OR IGNORE INTO ai_year_status (year, status, confirm_date, updated_at) VALUES (?, ?, ?, ?)')
       ->execute([$year, 'na', date('Y-m-d'), $now]);
    $stmt->execute([$year]);
    return $stmt->fetch() ?: [];
}

function ai_get_manual(PDO $db): array
{
    return $db->query('SELECT * FROM ai_manual WHERE id = 1')->fetch() ?: [];
}

function ai_list_incidents(PDO $db, int $year): array
{
    // Show last 5 years up to selected year
    $stmt = $db->prepare('SELECT * FROM ai_incidents WHERE year BETWEEN ? AND ? ORDER BY occur_date DESC, id DESC');
    $stmt->execute([$year - 4, $year]);
    return $stmt->fetchAll();
}

function ai_list_evidence(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM ai_evidence WHERE year = ? AND deleted_at IS NULL ORDER BY id DESC');
    $stmt->execute([$year]);
    return $stmt->fetchAll();
}

function ai_upload_evidence(PDO $db, int $year, string $kind, ?array $file, string $base64Data, string $note, string $actor): void
{
    $kinds = ai_evidence_kinds();
    if (!isset($kinds[$kind])) {
        throw new InvalidArgumentException('증빙 구분을 확인해 주세요.');
    }
    $dir = ai_evidence_dir();
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
            throw new RuntimeException('스크린샷 증빙 저장에 실패했습니다.');
        }
        $origName = sprintf('스크린샷_2.1.1_%s.%s', date('Ymd_His'), $ext);
        $db->prepare('INSERT INTO ai_evidence (year, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$year, $kind, $origName, $stored, (string)$info['mime'], strlen($raw), $note, $actor, date('Y-m-d H:i:s')]);
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
        throw new RuntimeException('증빙 파일 저장에 실패했습니다.');
    }
    $db->prepare('INSERT INTO ai_evidence (year, kind, original_name, stored_name, mime, size, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$year, $kind, $origName, $stored, $mime, (int)$file['size'], $note, $actor, date('Y-m-d H:i:s')]);
}

function csm_ai_score_summary(int $year, float $points = 1.0): array
{
    $empty = [
        'earned' => 0.0,
        'evidence_count' => 0,
        'state' => 'no_evidence',
        'multiplier' => 0.0,
        'revision' => 0,
        'automatic' => true,
        'is_na' => false,
        'status_text' => '',
        'assessment' => null,
    ];
    $dbPath = ai_db_path();
    if (!is_file($dbPath)) {
        return $empty;
    }
    try {
        $db = new PDO('sqlite:file:' . str_replace('\\', '/', $dbPath) . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $evCount = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ai_evidence'")->fetch()) {
            $q = $db->prepare('SELECT stored_name FROM ai_evidence WHERE year = ? AND deleted_at IS NULL');
            $q->execute([$year]);
            foreach ($q->fetchAll() as $row) {
                $s = (string)$row['stored_name'];
                if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|xls|docx)$/D', $s)) {
                    $p = ai_evidence_dir() . '/' . $s;
                    if (is_file($p) && filesize($p) > 0) {
                        $evCount++;
                    }
                }
            }
        }
        $yearStatus = null;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ai_year_status'")->fetch()) {
            $sq = $db->prepare('SELECT status FROM ai_year_status WHERE year = ?');
            $sq->execute([$year]);
            $yearStatus = $sq->fetchColumn() ?: null;
        }
        $yearIncidents = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ai_incidents'")->fetch()) {
            $iq = $db->prepare('SELECT COUNT(*) FROM ai_incidents WHERE year = ?');
            $iq->execute([$year]);
            $yearIncidents = (int)$iq->fetchColumn();
        }
        // Only allow N/A when no industrial accident is recorded for the selected year
        if ($yearStatus === 'na' && $yearIncidents === 0) {
            return [
                'earned' => 0.0,
                'evidence_count' => $evCount,
                'state' => 'na',
                'multiplier' => null,
                'revision' => 0,
                'automatic' => true,
                'is_na' => true,
                'status_text' => '해당없음(N/A · 무재해)',
                'assessment' => null,
            ];
        }
        $assessment = null;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ai_assessments'")->fetch()) {
            $aq = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM ai_assessments WHERE year = ?');
            $aq->execute([$year]);
            $assessment = $aq->fetch() ?: null;
        }
        if ($evCount === 0) {
            return array_merge($empty, ['assessment' => $assessment, 'automatic' => $assessment === null]);
        }
        if ($assessment !== null) {
            $mult = (float)$assessment['multiplier'];
            return [
                'earned' => round($points * $mult, 2),
                'evidence_count' => $evCount,
                'state' => 'assessed',
                'multiplier' => $mult,
                'revision' => 0,
                'automatic' => false,
                'is_na' => false,
                'status_text' => '재해조사 평가완료',
                'assessment' => $assessment,
            ];
        }
        return [
            'earned' => round($points * 1.0, 2),
            'evidence_count' => $evCount,
            'state' => 'assessed',
            'multiplier' => 1.0,
            'revision' => 0,
            'automatic' => true,
            'is_na' => false,
            'status_text' => '재해조사 증빙등록',
            'assessment' => null,
        ];
    } catch (Throwable $e) {
        error_log('csm_ai_score_summary error: ' . $e->getMessage());
        return $empty;
    }
}
