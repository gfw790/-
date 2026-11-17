<?php
declare(strict_types=1);

function ptw_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage directory');
    }
    $dbPath = $dir . '/permit_to_work.sqlite';
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');

    $db->exec('CREATE TABLE IF NOT EXISTS csm_ptw_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        permit_no TEXT NOT NULL UNIQUE,
        work_type TEXT NOT NULL,
        work_title TEXT NOT NULL,
        work_location TEXT NOT NULL,
        start_datetime TEXT NOT NULL,
        end_datetime TEXT NOT NULL,
        applicant_name TEXT NOT NULL,
        applicant_dept TEXT NOT NULL,
        worker_count INTEGER NOT NULL DEFAULT 1,
        workers_text TEXT NOT NULL,
        safety_supervisor TEXT NOT NULL,
        gas_o2 REAL DEFAULT NULL,
        gas_co REAL DEFAULT NULL,
        gas_h2s REAL DEFAULT NULL,
        gas_combustible REAL DEFAULT NULL,
        check_items_json TEXT NOT NULL DEFAULT "{}",
        loto_details TEXT NOT NULL DEFAULT "",
        ppe_details TEXT NOT NULL DEFAULT "",
        emergency_standby TEXT NOT NULL DEFAULT "",
        status TEXT NOT NULL DEFAULT "승인",
        approver_name TEXT NOT NULL,
        approved_at TEXT NOT NULL,
        completed_at TEXT DEFAULT NULL,
        closing_inspector TEXT DEFAULT NULL,
        closing_notes TEXT DEFAULT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    ptw_seed_defaults($db);
    return $db;
}

function ptw_seed_defaults(PDO $db): void
{
    $count = (int)$db->query('SELECT COUNT(*) FROM csm_ptw_records')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $records = [
        [
            'year' => 2026,
            'permit_no' => 'PTW-2026-001',
            'work_type' => '에너지차단(LOTO)',
            'work_title' => 'K2 CBS 증설 변전실 22.9kV 수전반 점검 및 차단기 교체 작업',
            'work_location' => 'K2 본관 1변전실 VCB 반',
            'start_datetime' => '2026-01-20 08:30',
            'end_datetime' => '2026-01-20 17:00',
            'applicant_name' => '윤택천 반장',
            'applicant_dept' => '전기반',
            'worker_count' => 4,
            'workers_text' => '윤택천, 우홍기, 김도담, 정주랑',
            'safety_supervisor' => '진종철 부장',
            'gas_o2' => null,
            'gas_co' => null,
            'gas_h2s' => null,
            'gas_combustible' => null,
            'check_items_json' => json_encode([
                'power_cut' => true,
                'loto_locked' => true,
                'discharge_done' => true,
                'grounding_done' => true,
                'ppe_checked' => true,
                'warning_sign' => true,
                'fire_extinguisher' => true,
            ]),
            'loto_details' => 'VCB 주회로 전원 스위치 Off 후 패드락 자물쇠 2중 체결 (키 보관: 진종철 부장), LOTO 식별 태그 부착',
            'ppe_details' => '절연안전모, 22.9kV 특고압 절연장갑, 절연화, 방염복',
            'emergency_standby' => '비상 시 CCR 직통 유선 및 안전관리팀 긴급호출 체계',
            'status' => '완료',
            'approver_name' => '안전보건관리책임자 정연탁',
            'approved_at' => '2026-01-20 08:00',
            'completed_at' => '2026-01-20 16:40',
            'closing_inspector' => '진종철 부장',
            'closing_notes' => '접지선 철거 및 공구 회수 확인 완료. LOTO 정상 해제 후 복전 시운전 양호',
        ],
        [
            'year' => 2026,
            'permit_no' => 'PTW-2026-002',
            'work_type' => '화기작업',
            'work_title' => 'RDF 투입설비 지지 철골 브라켓 용접 및 절단 작업',
            'work_location' => 'RDF 이송 투입 타워 2층',
            'start_datetime' => '2026-02-12 09:00',
            'end_datetime' => '2026-02-12 16:30',
            'applicant_name' => '김도담 사원',
            'applicant_dept' => '정비반',
            'worker_count' => 3,
            'workers_text' => '김도담, 윤택천, 화재감시자 1명',
            'safety_supervisor' => '진종철 부장',
            'gas_o2' => 20.9,
            'gas_co' => 0.0,
            'gas_h2s' => 0.0,
            'gas_combustible' => 0.0,
            'check_items_json' => json_encode([
                'fire_watcher' => true,
                'spark_shield' => true,
                'flammable_removed' => true,
                'extinguisher_2ea' => true,
                'water_spray' => true,
                'ventilation' => true,
            ]),
            'loto_details' => '인접 컨베이어 가동 중지 및 전원 스위치 잠금',
            'ppe_details' => '용접안전면, 가죽제 방열장갑, 방진마스크(1급), 가죽앞치마, 각반',
            'emergency_standby' => '분말소화기 2대 및 물통 비치, 화재감시자 전담 배치',
            'status' => '완료',
            'approver_name' => '안전보건관리책임자 정연탁',
            'approved_at' => '2026-02-12 08:40',
            'completed_at' => '2026-02-12 16:10',
            'closing_inspector' => '진종철 부장',
            'closing_notes' => '용접 종료 후 45분간 잔여 불씨 감시 완료. 이상 무',
        ],
        [
            'year' => 2026,
            'permit_no' => 'PTW-2026-003',
            'work_type' => '밀폐공간작업',
            'work_title' => '원료 사일로 내부 하부 피트 케이블 덕트 점검',
            'work_location' => '사일로 하부 지하 피트 #3',
            'start_datetime' => '2026-03-08 10:00',
            'end_datetime' => '2026-03-08 15:00',
            'applicant_name' => '우홍기 사원',
            'applicant_dept' => '전기반',
            'worker_count' => 3,
            'workers_text' => '우홍기, 윤택천, 외부감시인 1명',
            'safety_supervisor' => '정주랑 대리',
            'gas_o2' => 21.0,
            'gas_co' => 0.0,
            'gas_h2s' => 0.0,
            'gas_combustible' => 0.0,
            'check_items_json' => json_encode([
                'gas_measured' => true,
                'forced_ventilation' => true,
                'entry_monitor' => true,
                'rescue_tripod' => true,
                'communication' => true,
                'loto_inlet' => true,
            ]),
            'loto_details' => '상부 투입 슈트 밸브 기계적 잠금 및 구동 모터 LOTO',
            'ppe_details' => '송기마스크(또는 공기호흡기 대기), 안전모, 전신하네스, 휴대용 복합가스농도측정기, 방폭랜턴',
            'emergency_standby' => '삼각대 및 윈치 설치, 무전기 통신 10분 주기 체크',
            'status' => '완료',
            'approver_name' => '안전보건관리책임자 정연탁',
            'approved_at' => '2026-03-08 09:30',
            'completed_at' => '2026-03-08 14:30',
            'closing_inspector' => '정주랑 대리',
            'closing_notes' => '사내 밀폐공간 점검표 기준 전 항목 양호. 작업 인원 전원 안전 출입 확인',
        ],
        [
            'year' => 2026,
            'permit_no' => 'PTW-2026-004',
            'work_type' => '고소작업',
            'work_title' => '옥외 조명 타워 투광등 교체 및 배선 정비',
            'work_location' => '원료 야적장 #2 조명 타워 (높이 12m)',
            'start_datetime' => '2026-04-14 09:00',
            'end_datetime' => '2026-04-14 16:00',
            'applicant_name' => '윤택천 반장',
            'applicant_dept' => '전기반',
            'worker_count' => 3,
            'workers_text' => '윤택천, 우홍기, 신호수 1명',
            'safety_supervisor' => '진종철 부장',
            'gas_o2' => null,
            'gas_co' => null,
            'gas_h2s' => null,
            'gas_combustible' => null,
            'check_items_json' => json_encode([
                'lift_inspected' => true,
                'outrigger_deployed' => true,
                'safety_harness_2hook' => true,
                'ground_cordon' => true,
                'signalman_deployed' => true,
                'weather_checked' => true,
            ]),
            'loto_details' => '조명 타워 분전반 전원 차단기 Lock 부착',
            'ppe_details' => '안전모, 그네식 2개고리 안전대, 안전화, 가죽장갑, 공구낙하방지줄',
            'emergency_standby' => '강풍(풍속 10m/s 이상) 발생 시 즉시 작업 중단 기준 숙지',
            'status' => '완료',
            'approver_name' => '안전보건관리책임자 정연탁',
            'approved_at' => '2026-04-14 08:30',
            'completed_at' => '2026-04-14 15:30',
            'closing_inspector' => '진종철 부장',
            'closing_notes' => '고소작업대 아웃리거 설치 상태 양호, 안전대 100% 체결 작업 준수 확인',
        ]
    ];

    $stmt = $db->prepare('INSERT INTO csm_ptw_records (
        year, permit_no, work_type, work_title, work_location, start_datetime, end_datetime,
        applicant_name, applicant_dept, worker_count, workers_text, safety_supervisor,
        gas_o2, gas_co, gas_h2s, gas_combustible, check_items_json, loto_details, ppe_details,
        emergency_standby, status, approver_name, approved_at, completed_at, closing_inspector, closing_notes,
        created_at, updated_at
    ) VALUES (
        :year, :permit_no, :work_type, :work_title, :work_location, :start_datetime, :end_datetime,
        :applicant_name, :applicant_dept, :worker_count, :workers_text, :safety_supervisor,
        :gas_o2, :gas_co, :gas_h2s, :gas_combustible, :check_items_json, :loto_details, :ppe_details,
        :emergency_standby, :status, :approver_name, :approved_at, :completed_at, :closing_inspector, :closing_notes,
        :created_at, :updated_at
    )');

    foreach ($records as $r) {
        $stmt->execute(array_merge($r, ['created_at' => $now, 'updated_at' => $now]));
    }
}
