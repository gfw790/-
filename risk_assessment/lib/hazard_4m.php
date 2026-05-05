<?php

const HAZARD_4M_COMMENT = '4M 분류: M1 인적, M2 기계적, M3 관리적, M4 물질환경적';

function hazard_4m_label(?string $code): string
{
    return match (strtoupper(trim((string)$code))) {
        'M1' => '인적',
        'M2' => '기계적',
        'M3' => '관리적',
        'M4' => '물질·환경적',
        'REVIEW' => '검토필요',
        default => '-',
    };
}

function hazard_4m_manual_options(): array
{
    return [
        '' => '자동판단 사용',
        'M1' => 'M1 인적',
        'M2' => 'M2 기계적',
        'M3' => 'M3 관리적',
        'M4' => 'M4 물질·환경적',
        'REVIEW' => 'REVIEW 검토필요',
    ];
}

function hazard_4m_normalize_manual($value): ?string
{
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['M1', 'M2', 'M3', 'M4', 'REVIEW'], true) ? $value : null;
}

function ensure_unit_ra_item_hazard_4m_column(PDO $pdo): void
{
    $exists = (int)$pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unit_ra_item'
          AND COLUMN_NAME = 'hazard_4m'
    ")->fetchColumn() > 0;

    if (!$exists) {
        $pdo->exec("
            ALTER TABLE unit_ra_item
            ADD COLUMN hazard_4m VARCHAR(10) NULL
            COMMENT '" . HAZARD_4M_COMMENT . "'
            AFTER hazard_name
        ");
    }
}

function hazard_4m_options(): array
{
    return [
        'M1' => [
            '미착용', '미사용', '미체결', '미확인', '오조작', '오인', '착각', '부주의', '방심',
            '무리한 작업', '무리한 자세', '불안정한 자세', '과도한 힘', '과신',
            '숙련도 부족', '경험 부족', '교육 미숙지', '작업 미숙',
            '주의 부족', '전방주시 미흡', '주변 확인 미흡', '확인 소홀',
            '의사소통 부족', '신호 오해', '합 맞지 않음', '동시작업 중 접촉',
            '급하게 작업', '서두름', '피로', '졸음', '집중력 저하',
            '임의작업', '단독판단', '임의조작',
            '손 넣음', '발 넣음', '몸을 넣음', '접근', '근접', '접촉',
            '헛디딤', '중심 상실', '넘어짐', '미끄러짐',
            '손 끼임', '손가락 협착', '손 베임',
            '들어올림', '당김', '밀기', '운반 중 무리',
            '안전대 미체결', '안전고리 미체결', '안전모 턱끈 미체결',
            '절연장갑 미착용', '보안경 미착용', '방진마스크 미착용',
            '보호구 미착용', '안전대 미착용', '작업자 실수', '신체 접촉',
            '발 헛디딤', '회전체에 접근', '절단부에 접근', '충전부에 접근',
            '위험구역 접근', '작업반경 내 접근', '주변을 확인하지 않음',
            '전원 차단 여부를 직접 확인하지 않음', '휴대폰', '주의가 분산', '양손 작업 중 중심을 잃음',
            '고소부에서 몸을 과도하게 내밈', '중량물을 무리하게', '무리하게 들', '무리하게 당김',
        ],
        'M3' => [
            '작업허가', '작업계획', '작업절차', '절차 미준수', '교육 미실시',
            '감독 미흡', '신호수', '작업지휘', '점검 미실시', '사전점검',
            '위험성평가', 'tbm', '통제 미흡', '출입통제', '정리정돈 미흡',
            '양중계획', '전원 차단 미확인', '전원 미차단', 'loto', '유도자',
            '접근금지', '안전거리 확보', '상하동시작업 금지', '화기관리',
            '검전 미실시', '배선 점검', '누전차단기 점검', '가스측정',
            '안전조치 미흡',
            '통로 확보', '표지 부착',
        ],
        'M2' => [
            '공구', '장비', '설비', '기계', '전동공구', '그라인더', '드릴',
            '절단기', '용접기', '발전기', '크레인', '지게차', '스카이',
            '사다리', '말비계', '체인블럭', '레버블럭', '윈치', '절연불량',
            '누전', '감전', '회전체', '날', '방호장치', '끼임', '협착',
            '충전부', '아크', '단락', '배선오류', '누전차단기', '중장비',
            '비산물', '스파크', '불꽃', '절단공구', '안전덮개', '기동',
            '오작동', '오조작',
        ],
        'M4' => [
            '자재', '중량물', '케이블', '판넬', '분진', '소음', '진동',
            '고온', '저온', '혹한', '습기', '우천', '강우', '물기', '협소',
            '밀폐', '조도', '어두움', '바닥', '미끄러움', '경사', '단차',
            '유류', '화학물질', '가스', '산소결핍', '가연물', '오염 바닥',
            '유분', '수분', '환기 부족', '유해가스', '유해물질', '반복작업',
            '자세 불량',
        ],
    ];
}

function hazard_4m_clear_override_options(): array
{
    return [
        'M2' => [
            '기계 결함', '설비 결함', '장비 결함', '공구 결함', '절연불량',
            '방호장치 미설치', '방호장치 미흡', '회전체', '절단공구', '충전부',
            '누전차단기 불량', '설비 갑작스러운 가동', '돌발 기동', '단락',
        ],
        'M3' => [
            '작업허가', '교육 미실시', '감독 미흡', '작업계획', '작업절차',
            '점검 미실시', '양중계획', 'loto', '위험성평가', 'tbm',
        ],
        'M4' => [
            '우천', '강우', '습기', '분진', '소음', '진동', '자재', '중량물',
            '유류', '화학물질', '가스', '산소결핍', '바닥', '미끄러운 바닥',
            '저온', '고온',
        ],
    ];
}

function hazard_4m_strong_m1_keywords(): array
{
    return [
        '미착용', '미체결', '미확인', '오조작', '오인', '착각', '부주의', '방심',
        '무리한 작업', '무리한 자세', '불안정한 자세', '과도한 힘', '숙련도 부족',
        '경험 부족', '작업 미숙', '주의 부족', '전방주시 미흡', '주변 확인 미흡',
        '확인 소홀', '의사소통 부족', '신호 오해', '급하게 작업', '서두름',
        '피로', '졸음', '집중력 저하', '임의작업', '단독판단', '임의조작',
        '손 넣음', '발 넣음', '몸을 넣음', '헛디딤', '중심 상실', '손 끼임',
        '손가락 협착', '들어올림', '당김', '밀기', '안전대 미체결', '보호구 미착용',
        '절연장갑 미착용', '보안경 미착용', '방진마스크 미착용', '무리하게',
    ];
}

function hazard_4m_text_contains(string $text, array $keywords): bool
{
    foreach ($keywords as $keyword) {
        if ($keyword !== '' && mb_strpos($text, mb_strtolower($keyword, 'UTF-8')) !== false) {
            return true;
        }
    }

    return false;
}

function hazard_4m_text_fields(array $row): array
{
    return [
        (string)($row['hazard_name'] ?? ''),
        (string)($row['hazard_group'] ?? ''),
        (string)($row['accident_type'] ?? ''),
        (string)($row['injury_result'] ?? ''),
        (string)($row['description'] ?? ''),
        (string)($row['cause_text'] ?? ''),
        (string)($row['default_control_text'] ?? ''),
        (string)($row['required_ppe'] ?? ''),
        (string)($row['task_name'] ?? ''),
        (string)($row['current_control_text'] ?? ''),
        (string)($row['additional_control_text'] ?? ''),
        (string)($row['improvement_text'] ?? ''),
        (string)($row['remark'] ?? ''),
    ];
}

function hazard_4m_classify(array $row, bool $allowReview = true): ?string
{
    $fields = hazard_4m_text_fields($row);
    $joined = mb_strtolower(implode("\n", $fields), 'UTF-8');
    $causeFocus = mb_strtolower(implode("\n", [
        (string)($row['hazard_name'] ?? ''),
        (string)($row['cause_text'] ?? ''),
        (string)($row['description'] ?? ''),
        (string)($row['default_control_text'] ?? ''),
    ]), 'UTF-8');

    $strongM2 = ['결함', '불량', '파손', '고장', '절연불량', '누전차단기 불량', '방호장치 미설치'];
    $strongM3 = ['작업허가', '교육 미실시', '감독 미흡', '작업계획', '작업절차', '점검 미실시', 'loto'];
    $strongM4 = ['우천', '강우', '습기', '분진', '소음', '진동', '환기 부족', '가스', '산소결핍', '유류', '화학물질', '바닥'];

    if (hazard_4m_text_contains($causeFocus, hazard_4m_strong_m1_keywords())
        && !hazard_4m_text_contains($causeFocus, $strongM2)
        && !hazard_4m_text_contains($causeFocus, $strongM3)
        && !hazard_4m_text_contains($causeFocus, $strongM4)
    ) {
        return 'M1';
    }

    foreach (hazard_4m_clear_override_options() as $code => $keywords) {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && mb_strpos($joined, mb_strtolower($keyword, 'UTF-8')) !== false) {
                return $code;
            }
        }
    }

    foreach (hazard_4m_options() as $code => $keywords) {
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            if (mb_strpos($joined, mb_strtolower($keyword, 'UTF-8')) !== false) {
                return $code;
            }
        }
    }

    return $allowReview ? 'REVIEW' : null;
}

function hazard_4m_m1_candidate(array $row): bool
{
    $text = mb_strtolower(implode("\n", [
        (string)($row['hazard_name'] ?? ''),
        (string)($row['cause_text'] ?? ''),
        (string)($row['default_control_text'] ?? ''),
    ]), 'UTF-8');

    $candidateKeywords = [
        '미착용', '미체결', '미확인', '오조작', '착각', '부주의', '방심',
        '무리한', '불안정한 자세', '확인 소홀', '주변 확인', '의사소통',
        '신호', '급하게', '서두름', '피로', '졸음', '집중력', '임의',
        '접근', '근접', '접촉', '헛디딤', '중심 상실', '손 끼임',
        '손가락 협착', '들어올림', '당김', '밀기', '보호구', '안전대',
        '회전체', '절단부', '충전부',
    ];

    foreach ($candidateKeywords as $keyword) {
        if (mb_strpos($text, mb_strtolower($keyword, 'UTF-8')) !== false) {
            return true;
        }
    }

    return false;
}

function hazard_4m_enrich(array $row, bool $allowReview = true): array
{
    $manual = hazard_4m_normalize_manual($row['hazard_4m'] ?? null);
    $row['hazard_4m'] = $manual ?? hazard_4m_classify($row, $allowReview);
    $row['hazard_4m_label'] = hazard_4m_label($row['hazard_4m'] ?? null);
    return $row;
}
