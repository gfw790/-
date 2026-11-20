<?php
declare(strict_types=1);

function pt_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('저장소 디렉터리를 생성할 수 없습니다.');
    }
    $db = new PDO('sqlite:' . $dir . '/portable_tools.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    $db->exec('CREATE TABLE IF NOT EXISTS pt_equipment (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL DEFAULT 2026,
        seq_no INTEGER NOT NULL DEFAULT 0,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        voltage TEXT NOT NULL DEFAULT "220",
        current_amp TEXT NOT NULL DEFAULT "",
        spec TEXT NOT NULL DEFAULT "",
        cable_len TEXT NOT NULL DEFAULT "",
        socket_type TEXT NOT NULL DEFAULT "접지형",
        indoor_outdoor TEXT NOT NULL DEFAULT "옥내·외",
        double_insulated TEXT NOT NULL DEFAULT "확인",
        qty INTEGER NOT NULL DEFAULT 1,
        prev_result TEXT NOT NULL DEFAULT "합격",
        status TEXT NOT NULL DEFAULT "사용 가능",
        photo_file TEXT NOT NULL DEFAULT "",
        check1_date TEXT NOT NULL DEFAULT "2026-11-07",
        check1_result TEXT NOT NULL DEFAULT "",
        check1_sticker TEXT NOT NULL DEFAULT "미발부",
        check1_inspector TEXT NOT NULL DEFAULT "김남균",
        check2_date TEXT NOT NULL DEFAULT "2026-11-07",
        check2_result TEXT NOT NULL DEFAULT "",
        check2_sticker TEXT NOT NULL DEFAULT "미발부",
        check2_inspector TEXT NOT NULL DEFAULT "김남균",
        action_note TEXT NOT NULL DEFAULT "",
        remarks TEXT NOT NULL DEFAULT "",
        updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS pt_check_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        equipment_id INTEGER NOT NULL,
        item_no INTEGER NOT NULL,
        category TEXT NOT NULL DEFAULT "",
        content TEXT NOT NULL,
        round1_result TEXT NOT NULL DEFAULT "",
        round2_result TEXT NOT NULL DEFAULT "",
        round1_photo TEXT NOT NULL DEFAULT "",
        round2_photo TEXT NOT NULL DEFAULT "",
        FOREIGN KEY (equipment_id) REFERENCES pt_equipment(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS pt_evidence (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        kind TEXT NOT NULL DEFAULT "register",
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        mime TEXT NOT NULL,
        size INTEGER NOT NULL,
        uploaded_by TEXT NOT NULL,
        uploaded_at TEXT NOT NULL,
        deleted_at TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS pt_assessments (
        year INTEGER PRIMARY KEY,
        multiplier REAL NOT NULL,
        note TEXT NOT NULL DEFAULT "",
        updated_by TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    return $db;
}

function pt_photo_dir(): string
{
    return __DIR__ . '/storage/portable_tools_photos';
}

function pt_check_photo_dir(): string
{
    return __DIR__ . '/storage/portable_tools_check_photos';
}

function pt_evidence_dir(): string
{
    return __DIR__ . '/storage/portable_tools_evidence';
}

function pt_evidence_kinds(): array
{
    return [
        'register' => '휴대용 공구·기구 관리대장 (결재본)',
        'checklist' => '공구별 Check Sheet 점검표 (스캔본)',
        'sticker' => '현장 점검필증(스티커) 부착 사진',
        'insulation' => '절연저항·접지 측정 기록표',
    ];
}

function pt_fetch_all_equipment(PDO $db, string $q = '', string $status = ''): array
{
    $sql = 'SELECT e.*,
        (SELECT COUNT(*) FROM pt_check_items c WHERE c.equipment_id = e.id) AS item_count,
        (SELECT COUNT(*) FROM pt_check_items c WHERE c.equipment_id = e.id AND c.round1_result != "") AS r1_done,
        (SELECT COUNT(*) FROM pt_check_items c WHERE c.equipment_id = e.id AND c.round2_result != "") AS r2_done
        FROM pt_equipment e WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (e.code LIKE ? OR e.name LIKE ? OR e.spec LIKE ? OR e.socket_type LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '' && $status !== 'ALL') {
        $sql .= ' AND e.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY e.seq_no ASC, e.id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pt_fetch_check_items(PDO $db, int $equipmentId): array
{
    $stmt = $db->prepare('SELECT * FROM pt_check_items WHERE equipment_id = ? ORDER BY item_no ASC, id ASC');
    $stmt->execute([$equipmentId]);
    return $stmt->fetchAll();
}

function pt_fetch_evidence(PDO $db, int $year): array
{
    $stmt = $db->prepare('SELECT * FROM pt_evidence WHERE year = ? AND deleted_at IS NULL ORDER BY id DESC');
    $stmt->execute([$year]);
    return $stmt->fetchAll();
}

function pt_upload_photo(array $file, string $code): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('공구 사진은 10MB 이하의 JPG 또는 PNG 파일을 선택해 주세요.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed[$ext]) || $mime !== $allowed[$ext] || @getimagesize((string)$file['tmp_name']) === false) {
        throw new InvalidArgumentException('유효한 JPG 또는 PNG 이미지 파일만 업로드할 수 있습니다.');
    }
    $dir = pt_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('사진 저장소를 만들 수 없습니다.');
    }
    $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($code)) ?: 'tool';
    $fileName = $safe . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $fileName)) {
        throw new RuntimeException('사진 저장에 실패했습니다.');
    }
    return $fileName;
}

function pt_save_photo_base64(string $dataUrl, string $code): string
{
    $dataUrl = trim($dataUrl);
    if ($dataUrl === '') return '';
    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $dataUrl, $m)) {
        throw new InvalidArgumentException('올바른 이미지 데이터가 아닙니다.');
    }
    $raw = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) < 10 || strlen($raw) > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('10MB 이하의 유효한 이미지를 붙여넣어 주세요.');
    }
    if (@getimagesizefromstring($raw) === false) {
        throw new InvalidArgumentException('유효한 이미지 형식이 아닙니다.');
    }
    $ext = $m[1] === 'png' ? 'png' : 'jpg';
    $dir = pt_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('사진 저장소를 만들 수 없습니다.');
    }
    $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($code)) ?: 'tool';
    $fileName = $safe . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $fileName, $raw) === false) {
        throw new RuntimeException('사진 저장에 실패했습니다.');
    }
    return $fileName;
}

function pt_save_check_photo(array $file, int $equipmentId, int $itemId, int $round): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('사진 파일 업로드에 실패했습니다.');
    }
    if ((int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new InvalidArgumentException('15MB 이하의 사진만 업로드할 수 있습니다.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime]) || @getimagesize((string)$file['tmp_name']) === false) {
        throw new InvalidArgumentException('JPG, PNG, WEBP 이미지 파일만 업로드할 수 있습니다.');
    }
    $dir = pt_check_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('점검 사진 저장소를 만들 수 없습니다.');
    }
    $fileName = sprintf('ptchk_e%d_i%d_r%d_%s.%s', $equipmentId, $itemId, $round, bin2hex(random_bytes(5)), $extMap[$mime]);
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $fileName)) {
        throw new RuntimeException('점검 사진 저장에 실패했습니다.');
    }
    return $fileName;
}

function pt_sync_equipment_round_summary(PDO $db, int $equipmentId): array
{
    $items = pt_fetch_check_items($db, $equipmentId);
    $r1Total = 0; $r1Fail = 0;
    $r2Total = 0; $r2Fail = 0;
    foreach ($items as $it) {
        if ($it['round1_result'] !== '') { $r1Total++; if ($it['round1_result'] === '부적정') $r1Fail++; }
        if ($it['round2_result'] !== '') { $r2Total++; if ($it['round2_result'] === '부적정') $r2Fail++; }
    }
    $c1Result = $r1Total === 0 ? '' : ($r1Fail > 0 ? '부적정(' . $r1Fail . '건)' : '적정');
    $c2Result = $r2Total === 0 ? '' : ($r2Fail > 0 ? '부적정(' . $r2Fail . '건)' : '적정');
    $db->prepare('UPDATE pt_equipment SET check1_result = ?, check2_result = ?, updated_at = ? WHERE id = ?')
       ->execute([$c1Result, $c2Result, date('Y-m-d H:i:s'), $equipmentId]);
    return ['check1_result' => $c1Result, 'check2_result' => $c2Result, 'r1_done' => $r1Total, 'r2_done' => $r2Total];
}

function pt_upload_evidence(PDO $db, int $year, string $kind, array $file, string $actor): void
{
    if (!isset(pt_evidence_kinds()[$kind])) {
        throw new InvalidArgumentException('증빙 구분을 확인해 주세요.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? '')) || (int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new InvalidArgumentException('20MB 이하의 증빙 파일을 선택해 주세요.');
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
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
        throw new InvalidArgumentException('PDF, JPG, PNG, XLSX, DOCX 파일만 첨부할 수 있습니다.');
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
    $dir = pt_evidence_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('증빙 저장소를 만들 수 없습니다.');
    }
    $stored = bin2hex(random_bytes(20)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('증빙 파일 저장에 실패했습니다.');
    }
    $db->prepare('INSERT INTO pt_evidence (year, kind, original_name, stored_name, mime, size, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$year, $kind, $origName, $stored, $mime, (int)$file['size'], $actor, date('Y-m-d H:i:s')]);
}

function csm_pt_score_summary(int $year, float $points = 2.0): array
{
    $empty = [
        'earned' => 0.0,
        'evidence_count' => 0,
        'state' => 'no_evidence',
        'multiplier' => 0.0,
        'revision' => 0,
        'automatic' => true,
        'assessment' => null,
    ];
    $dbPath = __DIR__ . '/storage/portable_tools.sqlite';
    if (!is_file($dbPath)) {
        return $empty;
    }
    try {
        $db = new PDO('sqlite:file:' . str_replace('\\', '/', $dbPath) . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $evCount = 0;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pt_evidence'")->fetch()) {
            $q = $db->prepare('SELECT stored_name FROM pt_evidence WHERE year = ? AND deleted_at IS NULL');
            $q->execute([$year]);
            foreach ($q->fetchAll() as $row) {
                $s = (string)$row['stored_name'];
                if (preg_match('/^[a-f0-9]{40}\.(?:pdf|jpg|jpeg|png|xlsx|docx)$/D', $s)) {
                    $p = pt_evidence_dir() . '/' . $s;
                    if (is_file($p) && filesize($p) > 0) {
                        $evCount++;
                    }
                }
            }
        }
        $assessment = null;
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pt_assessments'")->fetch()) {
            $aq = $db->prepare('SELECT multiplier, note, updated_by, updated_at FROM pt_assessments WHERE year = ?');
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
            'assessment' => null,
        ];
    } catch (Throwable $e) {
        error_log('csm_pt_score_summary error: ' . $e->getMessage());
        return $empty;
    }
}
