<?php
declare(strict_types=1);

function sop_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage directory');
    }
    $dbPath = $dir . '/standard_procedure.sqlite';
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_sop_master (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        doc_code TEXT NOT NULL UNIQUE,
        category TEXT NOT NULL,
        title TEXT NOT NULL,
        scope TEXT NOT NULL,
        danger_factors TEXT NOT NULL,
        ppe_required TEXT NOT NULL,
        pre_work_safety TEXT NOT NULL,
        in_work_safety TEXT NOT NULL,
        post_work_safety TEXT NOT NULL,
        emergency_action TEXT NOT NULL DEFAULT "",
        revision_no INTEGER NOT NULL DEFAULT 1,
        enact_date TEXT NOT NULL,
        revised_date TEXT NOT NULL,
        author_name TEXT NOT NULL,
        approver_name TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_sop_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sop_id INTEGER NOT NULL,
        revision_no INTEGER NOT NULL,
        revision_date TEXT NOT NULL,
        reason TEXT NOT NULL,
        changes_summary TEXT NOT NULL,
        reviewer_name TEXT NOT NULL,
        approver_name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (sop_id) REFERENCES csm_sop_master(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_sop_distribution (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sop_id INTEGER NOT NULL,
        dist_date TEXT NOT NULL,
        target_group TEXT NOT NULL,
        dist_method TEXT NOT NULL,
        confirmed_count INTEGER NOT NULL DEFAULT 0,
        distributor_name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (sop_id) REFERENCES csm_sop_master(id) ON DELETE CASCADE
    )');

    // Safe schema migrations for 252-item SOP catalog
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN sop_no INTEGER DEFAULT 0"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN volume TEXT DEFAULT ''"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN job_plan_code TEXT DEFAULT ''"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN status TEXT DEFAULT '유효'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN change_reason TEXT DEFAULT ''"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN sheet_name TEXT DEFAULT ''"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN layout_json TEXT"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE csm_sop_master ADD COLUMN page_count INTEGER NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

    $db->exec('CREATE TABLE IF NOT EXISTS csm_sop_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sop_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT "body",
        seq INTEGER NOT NULL DEFAULT 1,
        side TEXT NOT NULL DEFAULT "R",
        from_row INTEGER NOT NULL DEFAULT 0, from_col INTEGER NOT NULL DEFAULT 0,
        from_row_off INTEGER NOT NULL DEFAULT 0, from_col_off INTEGER NOT NULL DEFAULT 0,
        to_row INTEGER NOT NULL DEFAULT 0, to_col INTEGER NOT NULL DEFAULT 0,
        to_row_off INTEGER NOT NULL DEFAULT 0, to_col_off INTEGER NOT NULL DEFAULT 0,
        width_emu INTEGER NOT NULL DEFAULT 0, height_emu INTEGER NOT NULL DEFAULT 0,
        px_w INTEGER NOT NULL DEFAULT 0, px_h INTEGER NOT NULL DEFAULT 0,
        mime TEXT NOT NULL DEFAULT "image/png",
        sha256 TEXT NOT NULL,
        data BLOB NOT NULL,
        orig_name TEXT NOT NULL DEFAULT "",
        orig_sha256 TEXT NOT NULL DEFAULT "",
        orig_data BLOB,
        created_at TEXT NOT NULL,
        FOREIGN KEY (sop_id) REFERENCES csm_sop_master(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sop_images_sop ON csm_sop_images(sop_id)');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_sop_backup_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name TEXT NOT NULL,
        kind TEXT NOT NULL,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        sha256 TEXT NOT NULL DEFAULT "",
        sop_count INTEGER NOT NULL DEFAULT 0,
        image_count INTEGER NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL
    )');

    // Example data is never inserted into an operational database automatically.
    return $db;
}

/**
 * Consistent point-in-time snapshot of the SOP database (SQLite VACUUM INTO).
 * kind: auto | manual | change
 * Keeps the newest $keepAuto auto/change snapshots; manual snapshots are never pruned.
 * @return array{ok:bool,file?:string,size?:int,error?:string}
 */
function sop_backup_db(string $kind = 'manual', string $note = '', int $keepAuto = 30): array
{
    try {
        $db = sop_db();
        $dir = __DIR__ . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => '백업 폴더를 만들 수 없습니다.'];
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        $kind = in_array($kind, ['auto', 'manual', 'change'], true) ? $kind : 'manual';
        $name = 'standard_procedure_' . date('Ymd_His') . '_' . $kind . '.sqlite';
        $path = $dir . '/' . $name;
        $quoted = str_replace("'", "''", $path);
        $db->exec("VACUUM INTO '" . $quoted . "'");
        if (!is_file($path)) {
            return ['ok' => false, 'error' => '백업 파일이 생성되지 않았습니다.'];
        }
        $size = (int)filesize($path);
        $sha = (string)hash_file('sha256', $path);
        $sopCount = (int)$db->query('SELECT COUNT(*) FROM csm_sop_master')->fetchColumn();
        $imgCount = (int)$db->query('SELECT COUNT(*) FROM csm_sop_images')->fetchColumn();
        $db->prepare('INSERT INTO csm_sop_backup_log (file_name, kind, size_bytes, sha256, sop_count, image_count, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now","localtime"))')
           ->execute([$name, $kind, $size, $sha, $sopCount, $imgCount, $note]);

        // prune old auto/change snapshots
        $old = $db->query("SELECT id, file_name FROM csm_sop_backup_log WHERE kind IN ('auto','change') ORDER BY id DESC LIMIT -1 OFFSET " . max(1, $keepAuto))->fetchAll();
        foreach ($old as $o) {
            $f = $dir . '/' . basename((string)$o['file_name']);
            if (is_file($f)) {
                @unlink($f);
            }
            $db->prepare('DELETE FROM csm_sop_backup_log WHERE id = ?')->execute([$o['id']]);
        }
        return ['ok' => true, 'file' => $name, 'size' => $size];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Take one automatic snapshot per calendar day (called on page load). */
function sop_backup_daily(): void
{
    try {
        $db = sop_db();
        $has = (int)$db->query("SELECT COUNT(*) FROM csm_sop_backup_log WHERE date(created_at) = date('now','localtime')")->fetchColumn();
        $sop = (int)$db->query('SELECT COUNT(*) FROM csm_sop_master')->fetchColumn();
        if ($has === 0 && $sop > 0) {
            sop_backup_db('auto', '일일 자동 백업');
        }
    } catch (Throwable $e) {
        // never block the page on backup problems
    }
}

function sop_seed_defaults(PDO $db): void
{
    $count = (int)$db->query('SELECT COUNT(*) FROM csm_sop_master')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $standards = [
        [
            'year' => 2026,
            'doc_code' => 'SOP-ELE-001',
            'category' => '전기공사',
            'title' => '고압 및 특고압 수변전설비 정전·점검 표준작업절차',
            'scope' => '수변전실 내 MOF, VCB, 변압기 정기점검 및 유지보수 작업 일체',
            'danger_factors' => '잔여 전하로 인한 감전, 활선 오접촉 아크 플래시, 밀폐공간 질식 위험',
            'ppe_required' => '절연안전모, 절연장갑(특고압용), 절연화, 방염복, 방진마스크, 산소농도측정기',
            'pre_work_safety' => "1. 한라시멘트 전기팀 사전 작업승인(PTW) 득함\n2. 전원 차단 확인(VCB Off) 및 무전압 시험기 잔류전하 방전 실시\n3. LOTO (잠금장치 및 안전표지판) 부착 철저\n4. 작업 전 TBM 및 감시자(신호수) 배치",
            'in_work_safety' => "1. 2인 1조 작업 원칙 준수 (단독 작업 절대 금지)\n2. 접지봉 설치 후 단락접지 상태 유지\n3. 활선 인접 구역 안전방책 및 접근금지 펜스 설치\n4. 절연 공구만 사용 및 신체 충전부 접촉 방지",
            'post_work_safety' => "1. 작업 공구 및 잔재물 완전 회수 확인 (이물질 방치 금지)\n2. 단락접지봉 철거 확인\n3. LOTO 해제 및 복전 전 현장 인원 전원 대피 확인\n4. 시운전 및 이상 유무 확인 후 원청 인수 보고",
            'emergency_action' => '감전사고 발생 시 즉시 주전원 차단(절연봉 사용), 119 및 사내 응급구조대(비상연락망) 즉시 신고 및 CPR 실시',
            'revision_no' => 3,
            'enact_date' => '2023-01-10',
            'revised_date' => '2026-01-20',
            'author_name' => '진종철 부장',
            'approver_name' => '정연탁 대표',
        ],
        [
            'year' => 2026,
            'doc_code' => 'SOP-ELE-002',
            'category' => '전기공사',
            'title' => '케이블 트레이 설치 및 케이블 포설 작업표준',
            'scope' => '건축물 내부 및 옥외 고소 케이블 트레이 거치, 전선관 배관 및 전력선 포설 작업',
            'danger_factors' => '고소작업 시 추락, 케이블 드럼 전도 및 끼임, 중량물 운반 시 요통 및 근골격계 질환',
            'ppe_required' => '안전모, 2개 고리 안전대(그네형), 안전화, 코팅장갑, 보안경',
            'pre_work_safety' => "1. 트레이 하부 보행통로 안전구획 설정 및 접근금지 테이프 설치\n2. 고소작업대(렌탈) 또는 비계 일상점검 실시\n3. 케이블 드럼 회전 잭 받침목 고정 및 안전핀 확인\n4. 운반 경로 바닥 장애물 제거",
            'in_work_safety' => "1. 2m 이상 고소 작업 시 생명줄에 안전대 고리 100% 체결\n2. 케이블 포설 시 신호수 무전 통제에 따라 일정한 속도로 당김\n3. 드럼 회전 부위에 손발 협착 주의 (말림 위험)\n4. 무리한 단독 중량물 인양 금지 (25kg 초과 2인 이상)",
            'post_work_safety' => "1. 트레이 덮개 견고히 체결\n2. 전선 피복 잔재물 및 결속선 바닥 청소\n3. 렌탈 장비 하강 및 충전 플러그 결합",
            'emergency_action' => '추락자 발생 시 안전대 하네스 압박 방지를 위해 10분 이내 긴급 구조 및 전문 구급대 인계',
            'revision_no' => 2,
            'enact_date' => '2023-03-15',
            'revised_date' => '2025-08-10',
            'author_name' => '정주랑 대리',
            'approver_name' => '정연탁 대표',
        ],
        [
            'year' => 2026,
            'doc_code' => 'SOP-SAF-001',
            'category' => '고소작업',
            'title' => '이동식 말비계 및 사다리 안전작업표준서',
            'scope' => '현장 내 3.5m 이하 경작업 시 사다리 및 이동식 말비계 사용 작업',
            'danger_factors' => '사다리 미끄러짐 및 전도, 발판 딛음 실수로 인한 추락',
            'ppe_required' => '안전모(턱끈 체결), 안전화, 안전장갑',
            'pre_work_safety' => "1. 바닥 요철 및 기울기 없는 평탄하고 단단한 바닥에 거치\n2. 말비계 벌림방지 장치 및 바퀴 스토퍼 체결 확인\n3. A형 사다리는 작업용이 아닌 '이동용 통로' 원칙 준수 (2m 이상 최상단 작업 금지)",
            'in_work_safety' => "1. 2인 1조 작업: 1인은 하부에서 사다리 지지 및 감시\n2. 사다리 상부 1~2계단에는 절대 딛지 않음\n3. 몸의 중심을 발판 중앙에 유지 (양옆으로 몸을 과도하게 기울이지 않음)\n4. 작업 중 양손 작업 필요 시 안전난간대 구비된 말비계 사용",
            'post_work_safety' => "1. 사용 완료 후 지정 보관소에 이동 적재\n2. 손상(발판 휨, 힌지 파손) 여부 확인 후 불량품은 즉시 폐기 태그 부착",
            'emergency_action' => '추락 충격 시 무리하게 일으켜 세우지 않고 경추/척추 보호 고정 후 119 이송',
            'revision_no' => 3,
            'enact_date' => '2022-06-01',
            'revised_date' => '2026-01-25',
            'author_name' => '윤택천 반장',
            'approver_name' => '정연탁 대표',
        ],
        [
            'year' => 2026,
            'doc_code' => 'SOP-MEC-001',
            'category' => '기계정비',
            'title' => '회전기계·컨베이어 벨트 점검 및 롤러 교체 표준절차',
            'scope' => '원료 및 시멘트 이송 컨베이어 벨트, 풀리, 캐리어 롤러 점검 및 보수',
            'danger_factors' => '가동 중 회전체 협착(끼임), 분진 흡입, 인접 구역 낙하물',
            'ppe_required' => '방진안전모, 방진마스크(1급), 안전화, 귀마개, 면장갑 착용 절대 금지(밀착형 안전장갑 착용)',
            'pre_work_safety' => "1. 중앙제어실(CCR) 사전 정지 요청 및 기동 정지 확인\n2. 현장 비상정지 풀코드(Pull Cord) 스위치 동작 확인\n3. 주 전원스위치 LOTO (개인 자물쇠 2중 체결 및 정지 표지판)",
            'in_work_safety' => "1. 기계가 완전히 정지한 것을 육안 확인 후 안전방호덮개 개방\n2. 롤러 회전 부위에 헐렁한 복장, 면장갑 착용 금지\n3. 중량 롤러 운반 시 전용 운반대차 사용\n4. 볼트 조임 시 규격 토크렌치 사용",
            'post_work_safety' => "1. 방호 커버 및 안전망 100% 원상 복구 체결\n2. LOTO 해제 후 CCR에 통보하여 5분간 무부하 시운전 확인",
            'emergency_action' => '끼임 발생 시 즉시 비상정지 스위치 타격, 역회전 절대 금지, 119 구조대 요청',
            'revision_no' => 2,
            'enact_date' => '2023-05-20',
            'revised_date' => '2025-11-15',
            'author_name' => '진종철 부장',
            'approver_name' => '정연탁 대표',
        ],
    ];

    $stmt = $db->prepare('INSERT INTO csm_sop_master (
        year, doc_code, category, title, scope, danger_factors, ppe_required,
        pre_work_safety, in_work_safety, post_work_safety, emergency_action,
        revision_no, enact_date, revised_date, author_name, approver_name,
        created_at, updated_at
    ) VALUES (
        :year, :doc_code, :category, :title, :scope, :danger_factors, :ppe_required,
        :pre_work_safety, :in_work_safety, :post_work_safety, :emergency_action,
        :revision_no, :enact_date, :revised_date, :author_name, :approver_name,
        :created_at, :updated_at
    )');

    $revStmt = $db->prepare('INSERT INTO csm_sop_revisions (
        sop_id, revision_no, revision_date, reason, changes_summary,
        reviewer_name, approver_name, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

    $distStmt = $db->prepare('INSERT INTO csm_sop_distribution (
        sop_id, dist_date, target_group, dist_method, confirmed_count,
        distributor_name, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?)');

    foreach ($standards as $s) {
        $stmt->execute(array_merge($s, ['created_at' => $now, 'updated_at' => $now]));
        $sopId = (int)$db->lastInsertId();

        $revStmt->execute([
            $sopId, $s['revision_no'], $s['revised_date'],
            '2026년도 위험성평가 연계 및 최신 안전지침 전부개정 사항 반영',
            '고소작업 추락방지 및 LOTO 잠금 절차 강화, 착용 보호구 명확화',
            '관리감독자 진종철', '안전보건관리책임자 정연탁', $now
        ]);

        $distStmt->execute([
            $sopId, $s['revised_date'], '전기반 및 기계정비반 전 근로자',
            'TBM 교육 및 현장 안전작업표준서 책자 배포', 6,
            '정주랑 대리', $now
        ]);
    }
}
