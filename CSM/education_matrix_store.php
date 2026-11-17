<?php
declare(strict_types=1);

require_once __DIR__ . '/leadership_store.php';
require_once __DIR__ . '/activity_organization_store.php';

function education_matrix_special_tasks(int $year): array
{
    return [
        [
            'task_code'        => '#02',
            'task_name'        => '아세틸렌 용접장치 또는 가스집합 용접장치를 사용하는 금속의 용접, 용단 또는 가열작업',
            'task_desc'        => '산소로 철 절단하는 작업, 산소로 달구는 작업 등 화기 고온 용단작업',
            'work_freq'        => '보통',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-02-06', $year), 'hours' => 4, 'method' => '구두 강의 및 실습'],
                ['session' => '2차', 'date' => sprintf('%04d-02-13', $year), 'hours' => 4, 'method' => '현장 안전수칙 회람 및 점검'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제2호',
        ],
        [
            'task_code'        => '#03',
            'task_name'        => '밀폐된 장소(탱크, 보일러, 맨홀 등)에서의 용접 및 절단작업',
            'task_desc'        => '공동구 혹은 밀폐된 장소에서 서포트 테그 등의 설치 및 용접작업',
            'work_freq'        => '낮음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-03-06', $year), 'hours' => 4, 'method' => '질식·유해가스 안전교육'],
                ['session' => '2차', 'date' => sprintf('%04d-03-13', $year), 'hours' => 4, 'method' => '환기장비 가동 및 구조훈련'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제3호',
        ],
        [
            'task_code'        => '#04',
            'task_name'        => '폭발성, 물반응성 물질 및 인화성 액체의 제조 또는 취급작업',
            'task_desc'        => '발전기 가동을 위한 휘발유/경유 취급, 도색작업을 위한 페인트 및 신나 취급',
            'work_freq'        => '높음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-04-03', $year), 'hours' => 4, 'method' => '화재폭발 방지 안전수칙'],
                ['session' => '2차', 'date' => sprintf('%04d-04-10', $year), 'hours' => 4, 'method' => '소화기 사용법 및 유류 보관관리'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉', '정민교', '이돈희'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제4호',
        ],
        [
            'task_code'        => '#05',
            'task_name'        => '인화성 가스 또는 폭발성 물질 중 가스의 발생장치 취급작업',
            'task_desc'        => '절단산소 화구를 사용하여 절단하는 작업, 토치를 이용한 예열작업',
            'work_freq'        => '낮음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-05-08', $year), 'hours' => 4, 'method' => '가스누출 역화방지기 점검'],
                ['session' => '2차', 'date' => sprintf('%04d-05-15', $year), 'hours' => 4, 'method' => '가스 압력조정기 안전취급'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제5호',
        ],
        [
            'task_code'        => '#14',
            'task_name'        => '1톤 이상의 크레인을 사용하는 작업 또는 1톤 미만 크레인/호이스트 5대 이상 보유 사업장 작업',
            'task_desc'        => '카고크레인 및 자재창고 호이스트 크레인을 사용한 자재·기계 상하역 및 줄걸이 작업',
            'work_freq'        => '낮음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-06-05', $year), 'hours' => 4, 'method' => '줄걸이 샤클·와이어로프 점검'],
                ['session' => '2차', 'date' => sprintf('%04d-06-12', $year), 'hours' => 4, 'method' => '신호수 신호체계 및 인양수칙'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제14호',
        ],
        [
            'task_code'        => '#17',
            'task_name'        => '전압이 75V 이상인 정전 및 활선작업',
            'task_desc'        => '수배전반, 전기 모터, 제어반 결선 및 일상적인 전기 가설·정비 보수작업',
            'work_freq'        => '높음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-07-03', $year), 'hours' => 4, 'method' => '절연용 보호구 착용 및 검전'],
                ['session' => '2차', 'date' => sprintf('%04d-07-10', $year), 'hours' => 4, 'method' => 'LOTO 전원차단 및 단락접지'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉', '정민교', '이돈희'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제17호',
        ],
        [
            'task_code'        => '#32',
            'task_name'        => '게이지 압력을 1kg/cm² 이상으로 사용하는 압력용기의 설치 및 취급작업',
            'task_desc'        => '산소·LPG 고압가스 실린더 및 에어 콤프레샤 압력용기 취급 및 밸브조작 작업',
            'work_freq'        => '낮음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-09-04', $year), 'hours' => 4, 'method' => '안전밸브 작동 및 압력게이지 확인'],
                ['session' => '2차', 'date' => sprintf('%04d-09-11', $year), 'hours' => 4, 'method' => '용기 전도방지 및 보관수칙'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제32호',
        ],
        [
            'task_code'        => '#34',
            'task_name'        => '밀폐공간(지하 공동구, 피트, 탱크 등)에서의 작업',
            'task_desc'        => '지하 공동구 등 산소농도 저하 및 유해가스 체류 우려 구역에서의 포설, 결선작업',
            'work_freq'        => '보통',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-10-16', $year), 'hours' => 4, 'method' => '3대 복합가스 측정 및 송풍기 환기'],
                ['session' => '2차', 'date' => sprintf('%04d-10-23', $year), 'hours' => 4, 'method' => '송기마스크 착용 및 비상구조훈련'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉', '정민교', '이돈희'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제34호',
        ],
        [
            'task_code'        => '#35',
            'task_name'        => '허가 및 관리 대상 유해물질의 제조 또는 취급작업',
            'task_desc'        => '도료, 유기용제, 세척제 등 독성·발암성 물질 취급 및 MSDS 적용 물질 작업',
            'work_freq'        => '높음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-11-20', $year), 'hours' => 4, 'method' => '방진·방독마스크 착용 및 환기'],
                ['session' => '2차', 'date' => sprintf('%04d-11-27', $year), 'hours' => 4, 'method' => '비상세안 및 누출 시 응급조치'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉', '정민교', '이돈희'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제35호',
        ],
        [
            'task_code'        => '#38',
            'task_name'        => '가연물이 있는 장소에서 하는 화재위험작업',
            'task_desc'        => '석탄(Coal), 미세분진, 미세플라스틱 등이 비산·퇴적된 구역 인근에서의 화기작업',
            'work_freq'        => '높음',
            'edu_dates'        => [
                ['session' => '1차', 'date' => sprintf('%04d-12-04', $year), 'hours' => 4, 'method' => '불티비산방지포 설치 및 가연물 제거'],
                ['session' => '2차', 'date' => sprintf('%04d-12-11', $year), 'hours' => 4, 'method' => '화재감시자 배치 및 소화용수 확보'],
            ],
            'assigned_workers' => ['진종철', '엄기준', '윤택천', '윤순형', '김남균', '김종훈', '조한봉', '정민교', '이돈희'],
            'legal_basis'      => '산업안전보건법 시행규칙 별표 5 제1호 라목 제38호',
        ],
    ];
}

function education_matrix_msds_substances(int $year): array
{
    $jsonPath = 'A:/risk_server/project/risk_assessment/msds_records.json';
    if (!file_exists($jsonPath)) {
        $jsonPath = dirname(__DIR__) . '/risk_assessment/msds_records.json';
    }

    $systemItems = [];
    if (file_exists($jsonPath)) {
        $raw = file_get_contents($jsonPath);
        $systemItems = json_decode($raw, true) ?: [];
    }

    $metaByName = [
        '락카-은색' => [
            'category'    => '도색용 도료 (유기용제 함유 에어로졸)',
            'usage_place' => '목재·철재 상도용 도색 (공사 및 제작구역)',
            'danger_point'=> '극인화성 가스(H220), 고인화성 액체·증기(H225), 흡인유해, 눈·피부 자극',
            'ppe_required'=> '방독마스크(유기화합물용), 보안경, 내화학장갑',
            'dates'       => [['상반기', sprintf('%04d-01-08', $year), 2, 'MSDS 경고표지 및 환기설비 가동 수칙'], ['하반기', sprintf('%04d-07-09', $year), 2, '유기용제 흡입 방지 및 잔포제 보관수칙']],
            'workers'     => ['진종철', '윤택천', '엄기준', '윤순형', '조한봉', '김남균', '우홍기'],
        ],
        '락카-연회색' => [
            'category'    => '도색용 도료 (유기용제 함유 에어로졸)',
            'usage_place' => '철구조물 및 배관 마감도색',
            'danger_point'=> '극인화성 가스(H220), 고인화성 액체·증기(H225), 피부·눈 자극',
            'ppe_required'=> '방독마스크(유기화합물용), 보안경, 내화학장갑',
            'dates'       => [['상반기', sprintf('%04d-01-15', $year), 2, '도장 작업 전 환기 및 화기 격리수칙'], ['하반기', sprintf('%04d-07-16', $year), 2, '보호구 착용 및 폐페인트 캔 분리수거']],
            'workers'     => ['진종철', '윤택천', '엄기준', '윤순형', '조한봉', '김남균', '우홍기'],
        ],
        '락카-적색' => [
            'category'    => '도색용 도료 (유기용제 함유 에어로졸)',
            'usage_place' => '배관 식별표시 및 위험부위 표식 도색',
            'danger_point'=> '극인화성 가스(H220), 고인화성 액체·증기(H225), 흡인유해',
            'ppe_required'=> '방독마스크(유기화합물용), 보안경, 내화학장갑',
            'dates'       => [['상반기', sprintf('%04d-01-22', $year), 2, 'MSDS 16개 항목 숙지 및 응급처치 요령'], ['하반기', sprintf('%04d-07-23', $year), 2, '위험표식 도색작업 시 안전간격 확보']],
            'workers'     => ['진종철', '윤택천', '엄기준', '윤순형', '조한봉', '김남균', '우홍기'],
        ],
        '락카-징크코트' => [
            'category'    => '방청도료 (아연말 중방식 프라이머)',
            'usage_place' => '용접 접합부 및 철구조물 녹방지 방청도장',
            'danger_point'=> '극인화성 가스(H220), 고인화성 액체(H225), 자연발화성(H250), 분진 흡입위험',
            'ppe_required'=> '방독·방진 겸용 마스크, 내화학장갑, 보안경',
            'dates'       => [['상반기', sprintf('%04d-02-05', $year), 2, '아연말 분진 흡입 방지 및 방청작업 수칙'], ['하반기', sprintf('%04d-08-06', $year), 2, '밀폐공간 내 방청도료 도포 금지 수칙']],
            'workers'     => ['진종철', '윤택천', '엄기준', '윤순형', '조한봉', '김남균', '우홍기'],
        ],
        '가솔린' => [
            'category'    => '인화성 액체 (제4류 제1석유류)',
            'usage_place' => '야외 이동식 발전기 및 소형 엔진 연료 주입',
            'danger_point'=> '극인화성 액체·증기, 정전기 인화폭발 위험, 흡인유해',
            'ppe_required'=> '정전기 방지 안전화, 방독마스크, 내유성 보호장갑',
            'dates'       => [['상반기', sprintf('%04d-02-19', $year), 2, '엔진 정지 후 주유 및 정전기 스파크 방지'], ['하반기', sprintf('%04d-08-20', $year), 2, '전용 금속 안전용기 취급 및 화기엄금']],
            'workers'     => ['진종철', '윤택천', '김종훈', '엄기준', '윤순형', '김남균', '우홍기'],
        ],
        '초저유황경유(ULSD)' => [
            'category'    => '인화성 액체 (제4류 제2석유류)',
            'usage_place' => '대형 디젤 발전기 및 중장비 연료탱크',
            'danger_point'=> '인화성 액체, 누출 시 토양·수질 오염, 피부 자극',
            'ppe_required'=> '내유성 안전장갑, 안전화, 흡착포 비치',
            'dates'       => [['상반기', sprintf('%04d-03-05', $year), 2, '주유 시 누유 차단 및 방유제·오일펜스 점검'], ['하반기', sprintf('%04d-09-03', $year), 2, '비상 누출 흡착재 사용법 및 폐유 회수 절차']],
            'workers'     => ['진종철', '윤택천', '김종훈', '엄기준', '윤순형', '김남균', '우홍기'],
        ],
        'LPG' => [
            'category'    => '고압가스 / 극인화성 가스',
            'usage_place' => '철재류 가열 및 가스절단 연료용',
            'danger_point'=> '극인화성 가스, 공기보다 무거워 바닥 체류·폭발 위험, 질식',
            'ppe_required'=> '휴대용 가스검지기, 정전기 방지 장갑, 절단보안경',
            'dates'       => [['상반기', sprintf('%04d-03-19', $year), 2, '배관 연결부 비눗물 누출검사 및 환기 절차'], ['하반기', sprintf('%04d-09-17', $year), 2, '산소 용기와의 격리보관(격벽) 및 밸브 보호캡']],
            'workers'     => ['진종철', '윤택천', '김종훈', '엄기준', '윤순형', '김남균'],
        ],
        '용접봉' => [
            'category'    => '용접재료 (연강용 피복아크 용접봉 / 망간 함유)',
            'usage_place' => '철구조물 및 배관 전기아크 용접 접합부',
            'danger_point'=> '용접흄 흡입 시 진폐증·신경계 영향, 아크광 피부화상 및 안염',
            'ppe_required'=> '1급 방진마스크, 차광보안면, 용접용 가죽장갑, 가죽앞치마',
            'dates'       => [['상반기', sprintf('%04d-04-09', $year), 2, '용접흄 포집 이동식 국소배기장치 가동 실습'], ['하반기', sprintf('%04d-10-15', $year), 2, '망간 등 유해인자 노출 저감 및 차광구 점검']],
            'workers'     => ['진종철', '윤택천', '김종훈', '엄기준', '윤순형', '김남균'],
        ],
        '산소' => [
            'category'    => '고압가스 / 조연성(지연성) 가스',
            'usage_place' => '철재류 고온 산소 가스절단 작업부',
            'danger_point'=> '급격한 연소 촉진, 고압용기 파열, 밸브 유지류 접촉 시 폭발',
            'ppe_required'=> '절단보안경, 방열장갑, 전도방지 체인, 압력조정기',
            'dates'       => [['상반기', sprintf('%04d-04-23', $year), 2, '용기 밸브 주위 유지류 엄금 및 역화방지기 점검'], ['하반기', sprintf('%04d-10-22', $year), 2, '고압용기 직사광선 차단 및 전도방지 고정']],
            'workers'     => ['진종철', '윤택천', '김종훈', '엄기준', '윤순형', '김남균'],
        ],
        '윤활방청제' => [
            'category'    => '윤활제 / 에어로졸 (LK-770 광유 및 방청첨가제)',
            'usage_place' => '장비 베어링 및 금속 마찰면 윤활·방청 정비구역',
            'danger_point'=> '극인화성 가스(H220), 고압가스 폭발위험(H280), 피부 자극(H315)',
            'ppe_required'=> '내유성 보호장갑, 보안경, 정비용 흡착포',
            'dates'       => [['상반기', sprintf('%04d-05-14', $year), 2, '기계 정지 후 도포 및 고열 마찰부 화재 예방'], ['하반기', sprintf('%04d-11-12', $year), 2, '에어로졸 용기 잔압 배출 후 전용 폐기 절차']],
            'workers'     => ['진종철', '윤택천', '김종훈', '엄기준', '윤순형', '조한봉', '김남균', '우홍기'],
        ],
        'CO (0.0001~0.2999) cmol/mol / Air' => [
            'category'    => '표준가스 (일산화탄소 0.0001~0.2999 cmol/mol in Air)',
            'usage_place' => 'GAS팀 가스분석기 및 누출검지센서 스팬 교정(Calibration)',
            'danger_point'=> '고압가스 포함 가열 폭발위험(H280), 일산화탄소 흡입 중독',
            'ppe_required'=> '휴대용 일산화탄소 경보기, 방독마스크, 내화장갑, 안전모',
            'dates'       => [['상반기', sprintf('%04d-05-28', $year), 2, '가스분석기 제로/스팬 교정 중 환기 및 감압밸브 취급'], ['하반기', sprintf('%04d-11-26', $year), 2, '표준가스 실린더 보관 랙 점검 및 비상 누출 대응']],
            'workers'     => ['김도담', '박재민', '이윤성', '장진혁', '정재호', '최정섭', '김남균'],
        ],
        'NO (0.0001~0.0999) cmol/mol / N2' => [
            'category'    => '표준가스 (일산화질소 0.0001~0.0999 cmol/mol in N2)',
            'usage_place' => '질소산화물(NOx) 측정기 및 정밀 센서 교정 작업부',
            'danger_point'=> '고압가스 포함 가열 폭발위험(H280), 표적장기 독성(호흡기 자극)',
            'ppe_required'=> '방독마스크(산화질소용), 보안경, 보호장갑, 실린더 카트',
            'dates'       => [['상반기', sprintf('%04d-06-11', $year), 2, '정밀 교정작업 표준작업절차(SOP) 및 잔류가스 배기'], ['하반기', sprintf('%04d-12-10', $year), 2, '실린더 전도방지 고정체결 및 긴급차단밸브 조작']],
            'workers'     => ['김도담', '박재민', '이윤성', '장진혁', '정재호', '최정섭', '김남균'],
        ],
    ];

    $list = [];
    $num = 1;
    if (!empty($systemItems)) {
        foreach ($systemItems as $item) {
            $name = trim((string)($item['material_name'] ?? ''));
            $m = $metaByName[$name] ?? null;
            if (!$m) continue;

            $code = sprintf('M-%02d', $num++);
            $list[] = [
                'code'             => $code,
                'msds_id'          => $item['id'] ?? '',
                'name'             => $name,
                'manufacturer'     => $item['manufacturer'] ?? '',
                'original_name'    => $item['original_name'] ?? '',
                'stored_name'      => $item['stored_name'] ?? '',
                'category'         => $m['category'],
                'usage_place'      => $m['usage_place'],
                'danger_point'     => $m['danger_point'],
                'ppe_required'     => $m['ppe_required'],
                'edu_dates'        => [
                    ['session' => $m['dates'][0][0], 'date' => $m['dates'][0][1], 'hours' => $m['dates'][0][2], 'method' => $m['dates'][0][3]],
                    ['session' => $m['dates'][1][0], 'date' => $m['dates'][1][1], 'hours' => $m['dates'][1][2], 'method' => $m['dates'][1][3]],
                ],
                'assigned_workers' => $m['workers'],
            ];
        }
    }

    // Fallback if msds_records.json was empty or missing
    if (count($list) < 12) {
        $fallbackDefaults = [
            ['name' => '락카-은색', 'mfg' => '주식회사 럭키산업', 'file' => '02.럭키락카-은색.pdf'],
            ['name' => '락카-연회색', 'mfg' => '주식회사 럭키산업', 'file' => '03.럭키락카-연회색.pdf'],
            ['name' => '락카-적색', 'mfg' => '주식회사 럭키산업', 'file' => '01.럭키락카-적색.pdf'],
            ['name' => '락카-징크코트', 'mfg' => '주식회사 럭키산업', 'file' => '04.징크코트(LK-702).pdf'],
            ['name' => '가솔린', 'mfg' => 'GS 칼텍스', 'file' => '10.휘발유.pdf'],
            ['name' => '초저유황경유(ULSD)', 'mfg' => 'GS 칼텍스', 'file' => '09.경유.pdf'],
            ['name' => 'LPG', 'mfg' => 'GS 칼텍스', 'file' => '08.LPG.pdf'],
            ['name' => '용접봉', 'mfg' => '조선선재(주)', 'file' => '06.용접재료(연강용 피복아크 용접봉)_(국문)v9_2026.06.01.pdf'],
            ['name' => '산소', 'mfg' => '한국특수가스(주)', 'file' => '07.산소_한국특수가스(주).pdf'],
            ['name' => '윤활방청제', 'mfg' => '주식회사 럭키산업', 'file' => '05.윤활방청제(LK-770).pdf'],
            ['name' => 'CO (0.0001~0.2999) cmol/mol / Air', 'mfg' => '어프로티움 주식회사', 'file' => '86. CO (0.0001~0.2999) cmol-Air(20260306)-KOSHA.pdf'],
            ['name' => 'NO (0.0001~0.0999) cmol/mol / N2', 'mfg' => '어프로티움 주식회사', 'file' => 'NO (0.0001~0.0999) cmol-N2(20240425)-KOSHA 어프로티움 (1).pdf'],
        ];
        $list = [];
        $num = 1;
        foreach ($fallbackDefaults as $f) {
            $name = $f['name'];
            $m = $metaByName[$name];
            $list[] = [
                'code'             => sprintf('M-%02d', $num++),
                'msds_id'          => '',
                'name'             => $name,
                'manufacturer'     => $f['mfg'],
                'original_name'    => $f['file'],
                'stored_name'      => '',
                'category'         => $m['category'],
                'usage_place'      => $m['usage_place'],
                'danger_point'     => $m['danger_point'],
                'ppe_required'     => $m['ppe_required'],
                'edu_dates'        => [
                    ['session' => $m['dates'][0][0], 'date' => $m['dates'][0][1], 'hours' => $m['dates'][0][2], 'method' => $m['dates'][0][3]],
                    ['session' => $m['dates'][1][0], 'date' => $m['dates'][1][1], 'hours' => $m['dates'][1][2], 'method' => $m['dates'][1][3]],
                ],
                'assigned_workers' => $m['workers'],
            ];
        }
    }

    return $list;
}

function education_matrix_all_workers(): array
{
    $roster = activity_org_current_roster();
    $teams = $roster['teams'] ?? [];
    $workers = [];
    foreach ($teams as $dept) {
        $deptName = $dept['name'] ?? '';
        foreach ($dept['members'] ?? [] as $m) {
            $workers[] = [
                'name'  => $m['name'],
                'pos'   => $m['pos'],
                'dept'  => $deptName,
                'badge' => $m['badge'] ?? '',
            ];
        }
    }
    return $workers;
}

function education_matrix_records(PDO $db, int $year): array
{
    // Special Education Matrix (5.1.4)
    $stmtSpecial = $db->prepare("SELECT * FROM leadership_records WHERE item = '5.1.4' AND year = ? ORDER BY id DESC LIMIT 1");
    $stmtSpecial->execute([$year]);
    $recSpecial = $stmtSpecial->fetch(PDO::FETCH_ASSOC);

    if (!$recSpecial) {
        $defaultSpecial = education_matrix_special_tasks($year);
        $payload = json_encode(['tasks' => $defaultSpecial], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $ins = $db->prepare("
            INSERT INTO leadership_records (item, year, activity_date, title, payload, revision, updated_by, updated_at)
            VALUES ('5.1.4', ?, ?, ?, ?, 1, '안전담당자 김남균', datetime('now', 'localtime'))
        ");
        $ins->execute([$year, sprintf('%04d-01-02', $year), "{$year}년도 특별안전교육 대상 작업 및 교육 Matrix", $payload]);
        $stmtSpecial->execute([$year]);
        $recSpecial = $stmtSpecial->fetch(PDO::FETCH_ASSOC);
    }

    try {
        $specialTasks = json_decode((string)$recSpecial['payload'], true, 512, JSON_THROW_ON_ERROR)['tasks'] ?? education_matrix_special_tasks($year);
    } catch (Throwable) {
        $specialTasks = education_matrix_special_tasks($year);
    }

    // MSDS Education Matrix (5.1.5)
    $stmtMsds = $db->prepare("SELECT * FROM leadership_records WHERE item = '5.1.5' AND year = ? ORDER BY id DESC LIMIT 1");
    $stmtMsds->execute([$year]);
    $recMsds = $stmtMsds->fetch(PDO::FETCH_ASSOC);

    if (!$recMsds) {
        $defaultMsds = education_matrix_msds_substances($year);
        $payload = json_encode(['substances' => $defaultMsds], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $ins = $db->prepare("
            INSERT INTO leadership_records (item, year, activity_date, title, payload, revision, updated_by, updated_at)
            VALUES ('5.1.5', ?, ?, ?, ?, 1, '안전담당자 김남균', datetime('now', 'localtime'))
        ");
        $ins->execute([$year, sprintf('%04d-01-02', $year), "{$year}년도 물질안전보건자료(MSDS) 교육 계획 및 Matrix", $payload]);
        $stmtMsds->execute([$year]);
        $recMsds = $stmtMsds->fetch(PDO::FETCH_ASSOC);
    }

    try {
        $msdsSubstances = json_decode((string)$recMsds['payload'], true, 512, JSON_THROW_ON_ERROR)['substances'] ?? education_matrix_msds_substances($year);
    } catch (Throwable) {
        $msdsSubstances = education_matrix_msds_substances($year);
    }

    // Auto-sync if MSDS substances are from older dummy template (contains cement or count < 12)
    $needMsdsSync = false;
    if (count($msdsSubstances) < 12) {
        $needMsdsSync = true;
    } else {
        foreach ($msdsSubstances as $sub) {
            if (strpos((string)($sub['name'] ?? ''), '시멘트') !== false || strpos((string)($sub['name'] ?? ''), '일반 화학물질') !== false) {
                $needMsdsSync = true;
                break;
            }
        }
    }
    if ($needMsdsSync && !empty($recMsds['id'])) {
        $msdsSubstances = education_matrix_msds_substances($year);
        $payload = json_encode(['substances' => $msdsSubstances], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $upd = $db->prepare("UPDATE leadership_records SET payload = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
        $upd->execute([$payload, $recMsds['id']]);
    }

    return [
        'special' => [
            'id'       => (int)($recSpecial['id'] ?? 0),
            'revision' => (int)($recSpecial['revision'] ?? 1),
            'tasks'    => $specialTasks,
        ],
        'msds' => [
            'id'         => (int)($recMsds['id'] ?? 0),
            'revision'   => (int)($recMsds['revision'] ?? 1),
            'substances' => $msdsSubstances,
        ],
    ];
}

function education_matrix_status(array $matrixData): array
{
    $specialTasks = $matrixData['special']['tasks'] ?? [];
    $msdsSubstances = $matrixData['msds']['substances'] ?? [];

    $specialTaskCount = count($specialTasks);
    $specialAssignedTotal = 0;
    foreach ($specialTasks as $t) {
        $specialAssignedTotal += count($t['assigned_workers'] ?? []);
    }

    $msdsSubstanceCount = count($msdsSubstances);
    $msdsAssignedTotal = 0;
    foreach ($msdsSubstances as $s) {
        $msdsAssignedTotal += count($s['assigned_workers'] ?? []);
    }

    $specialComplete = $specialTaskCount >= 5 && $specialAssignedTotal >= 10;
    $msdsComplete = $msdsSubstanceCount >= 5 && $msdsAssignedTotal >= 10;

    return [
        'special_task_count'     => $specialTaskCount,
        'special_assigned_total' => $specialAssignedTotal,
        'special_multiplier'     => $specialComplete ? 1.0 : ($specialTaskCount > 0 ? 0.8 : 0.0),
        'special_status_text'    => $specialComplete ? '충족 (100% 만점, 1.0점)' : '보통 (80%, 0.8점)',
        'msds_substance_count'   => $msdsSubstanceCount,
        'msds_assigned_total'    => $msdsAssignedTotal,
        'msds_multiplier'        => $msdsComplete ? 1.0 : ($msdsSubstanceCount > 0 ? 0.8 : 0.0),
        'msds_status_text'       => $msdsComplete ? '충족 (100% 만점, 1.0점)' : '보통 (80%, 0.8점)',
    ];
}
