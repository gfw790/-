<?php
declare(strict_types=1);
require_once __DIR__ . '/leadership_store.php';

function activity_org_budget_from_ledger(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];

    try {
        require_once __DIR__ . '/../risk_assessment/db_config.php';
        require_once __DIR__ . '/../safety_manual/safety_budget_storage.php';
        $db = getDB();
        $totals = safety_budget_category_totals($db, $year);
        $categories = safety_budget_categories();
        $items = [];
        foreach ($totals as $code => $planned) {
            if ($planned <= 0) continue;
            $items[] = [
                'code' => $code,
                'name' => $categories[$code],
                'planned' => (int)$planned,
                'executed' => 0,
                'note' => '',
            ];
        }
        $query = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM safety_budget_usage_items WHERE usage_year = ?');
        $query->execute([$year]);
        return $cache[$year] = [
            'total_planned' => array_sum($totals),
            'total_executed' => (int)$query->fetchColumn(),
            'currency' => 'KRW',
            'items' => $items,
            'source' => 'safety_budget_ledger',
        ];
    } catch (Throwable $error) {
        error_log('CSM budget ledger load failure: ' . $error->getMessage());
        return $cache[$year] = [
            'total_planned' => 0,
            'total_executed' => 0,
            'currency' => 'KRW',
            'items' => [],
            'source' => 'unavailable',
        ];
    }
}

function activity_org_file_kinds(): array
{
    return [
        'org_chart'      => '안전보건 조직도',
        'appointment'    => '선임계 / 지정서',
        'authority_doc'  => '권한 부여 및 업무분장 규정',
        'qual_cert'      => '안전보건 자격 / 교육 이수증',
        'budget_plan'    => '연간 안전보건 예산편성표',
        'budget_ledger'  => '예산 집행실적 총괄대장',
        'budget_receipt' => '예산 집행 증빙 (세금계산서·영수증)',
    ];
}

function activity_org_default_data(int $year): array
{
    return [
        'organization' => [
            'org_name'        => '안전보건관리 조직 및 안전관계자의 책무 (안전보건활동계획서)',
            'effective_date'  => sprintf('%04d-01-01', $year),
            'description'     => '2026년 안전보건활동계획서 제Ⅰ편 제3장 안전보건관리 조직 및 안전관계자의 책무',
            'roles' => [
                [
                    'role_title'     => '최고경영자 (안전보건관리책임자)',
                    'name'           => '정연탁',
                    'position'       => '대표이사',
                    'appointed_date' => sprintf('%04d-01-01', $year),
                    'duties'         => "1. 안전보건경영방침의 수립, 안전보건경영체계의 이행 및 유지에 대한 확인\n2. 안전보건경영의 이행과 관리에 필요한 수단 및 적절한 자원의 제공\n3. 안전보건목표와 세부목표 및 추진계획의 승인\n4. 안전보건관리 종사자 지휘·감독\n5. 비상상황 발생 시 총괄 지휘\n6. 자체 발주공사 및 도급용역 등 안전관리 총괄",
                    'authority'      => '안전보건 총괄결정권, 예산집행 최종결정권, 중대재해 발생 시 전면 작업중지 및 복구 승인권',
                ],
                [
                    'role_title'     => '관리감독자 (부서장)',
                    'name'           => '진종철',
                    'position'       => '공사팀 부장',
                    'appointed_date' => sprintf('%04d-01-01', $year),
                    'duties'         => "1. 안전보건방침, 목표 및 세부목표에 대한 부서(팀)원 교육 실시\n2. 부서 안전보건성과의 측정 및 평가\n3. 안전보건경영 추진계획의 작성/관리 등\n4. 근로자 및 작업장의 안전보건 조치",
                    'authority'      => '위험작업 즉시 중지권, 현장 시정명령 및 지휘권, 필요 안전보호구 긴급 구매요구권',
                ],
                [
                    'role_title'     => '안전담당자',
                    'name'           => '김남균',
                    'position'       => '과장',
                    'appointed_date' => sprintf('%04d-01-01', $year),
                    'duties'         => "1. 안전보건관련 법규 및 그 밖의 요구 사항의 수집, 등록, 개정관리\n2. 안전보건경영체계의 문서화, 유지 및 배포\n3. 내부 비상상황 대비, 대응관리 및 대응\n4. 위험성평가, 안전보건목표, 안전경영책임계획 수립·이행\n5. 자체 발주공사 및 도급용역 등 재해예방 계획 수립\n6. 산업재해·안전사고 발생의 원인조사 및 대책수립 등\n7. 안전보건 교육훈련, 성과평가 총괄, 시정조치 이행 총괄 등\n8. 직원 건강진단 계획 수립 및 관리\n9. 안전보건관련 근로자 및 이해관계자의 의사소통을 위한 업무 주관",
                    'authority'      => '현장 유해·위험요인 시정조치 요구권, 안전교육 미이수자 작업배치 제한 건의권',
                ],
                [
                    'role_title'     => '근로자',
                    'name'           => '전 근로자 (근로자대표 윤택천)',
                    'position'       => '공사팀 / GAS팀 / 제조팀',
                    'appointed_date' => sprintf('%04d-01-01', $year),
                    'duties'         => "1. 사업주가 실시하는 안전보건교육의 이수\n2. 개인보호구 착용 및 안전작업수칙 준수\n3. 안전보건활동(위험성평가 등)에 적극 참여 및 관련 법규와 규정 준수\n4. 효율적인 상향식 의사전달체계 참여 및 지시사항의 이행\n5. 기타 제반 규정 운영절차의 준수 등",
                    'authority'      => '급박한 위험 시 대피 및 작업중지 요청권, 안전보건 개선의견 개진권',
                ],
            ],
            'authorities_granted' => [
                'stop_work'            => true,
                'corrective_action'    => true,
                'budget_requisition'   => true,
                'regulation_enforced'  => true,
            ],
        ],
        'budget' => [
            'total_planned'  => 32500000,
            'total_executed' => 18500000,
            'currency'       => 'KRW',
            'items' => [
                ['code' => 'safety_wage',       'name' => '안전·보건관리자 임금/수당', 'planned' => 6000000, 'executed' => 3000000, 'note' => '법정 직무수행 및 선임자 수당'],
                ['code' => 'safety_facility',   'name' => '안전시설비 등',           'planned' => 7500000, 'executed' => 3800000, 'note' => '안전난간, 방호덮개, 작업발판 등 시설보강'],
                ['code' => 'ppe',               'name' => '개인보호구 및 장구류',     'planned' => 8000000, 'executed' => 4500000, 'note' => '안전모, 안전화, 안전대, 방진마스크'],
                ['code' => 'education',         'name' => '안전보건교육 및 훈련비',   'planned' => 3000000, 'executed' => 2000000, 'note' => '위탁교육, 안전교육 교재, 강사료'],
                ['code' => 'health_prevention', 'name' => '근로자 건강장해예방비',     'planned' => 3500000, 'executed' => 2200000, 'note' => '일반/특수건강진단, 구급약품류'],
                ['code' => 'risk_assessment',   'name' => '위험성평가 소요비용',       'planned' => 2500000, 'executed' => 1800000, 'note' => '위험성평가 회의 및 개선대책 실행'],
                ['code' => 'other_activity',    'name' => '기타 안전활동비',         'planned' => 2000000, 'executed' => 1200000, 'note' => '안전포상, 안전홍보물 제작'],
            ],
        ],
    ];
}

function activity_org_records(PDO $db, int $year): ?array
{
    $query = $db->prepare("SELECT * FROM leadership_records WHERE item = '1.2.3' AND year = ? ORDER BY id DESC LIMIT 1");
    $query->execute([$year]);
    $record = $query->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }

    try {
        $record['data'] = json_decode((string)$record['payload'], true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $record['data'] = activity_org_default_data($year);
    }
    $record['data']['budget'] = activity_org_budget_from_ledger($year);

    $fileQuery = $db->prepare('SELECT * FROM leadership_files WHERE record_id = ? AND deleted_at IS NULL ORDER BY id ASC');
    $fileQuery->execute([(int)$record['id']]);
    $files = $fileQuery->fetchAll(PDO::FETCH_ASSOC);

    $record['files'] = $files;
    $record['files_by_kind'] = [];
    foreach ($files as $file) {
        $kind = $file['file_kind'] ?: 'evidence';
        $record['files_by_kind'][$kind][] = $file;
    }

    return $record;
}

function activity_org_status(array $data, array $files): array
{
    $org = $data['organization'] ?? [];
    $roles = $org['roles'] ?? [];
    $auth = $org['authorities_granted'] ?? [];
    $budget = $data['budget'] ?? [];
    $budgetItems = $budget['items'] ?? [];

    $filesByKind = [];
    foreach ($files as $f) {
        if (!empty($f['deleted_at'])) continue;
        $kind = $f['file_kind'] ?: 'evidence';
        $filesByKind[$kind] = ($filesByKind[$kind] ?? 0) + 1;
    }

    $hasOrgRoles = count($roles) >= 2;
    $hasOrgAuthority = !empty($auth['stop_work']) && !empty($auth['corrective_action']);
    $hasOrgEvidence = ($filesByKind['org_chart'] ?? 0) > 0 || ($filesByKind['appointment'] ?? 0) > 0;

    $totalPlanned = (float)($budget['total_planned'] ?? 0);
    $totalExecuted = (float)($budget['total_executed'] ?? 0);
    $hasBudgetItems = count($budgetItems) >= 3 && $totalPlanned > 0;
    $hasBudgetEvidence = ($filesByKind['budget_plan'] ?? 0) > 0 || ($filesByKind['budget_ledger'] ?? 0) > 0 || ($filesByKind['budget_receipt'] ?? 0) > 0;

    $orgComplete = $hasOrgRoles && $hasOrgAuthority && $hasOrgEvidence;
    $budgetComplete = $hasBudgetItems && $hasBudgetEvidence;

    $multiplier = 0.0;
    $statusText = '편성 안됨 (0점)';

    if ($orgComplete && $budgetComplete) {
        $multiplier = 1.0;
        $statusText = '충족 (100% 만점)';
    } elseif ($orgComplete || $budgetComplete) {
        $multiplier = 0.8;
        $statusText = '일부 만족 (80%)';
    } elseif ($hasOrgRoles || $hasBudgetItems) {
        $multiplier = 0.5;
        $statusText = '미흡함 (50%)';
    }

    return [
        'multiplier'          => $multiplier,
        'status_text'         => $statusText,
        'has_org_roles'       => $hasOrgRoles,
        'has_org_authority'   => $hasOrgAuthority,
        'has_org_evidence'    => $hasOrgEvidence,
        'has_budget_items'    => $hasBudgetItems,
        'has_budget_evidence' => $hasBudgetEvidence,
        'total_planned'       => $totalPlanned,
        'total_executed'      => $totalExecuted,
        'files_count'         => array_sum($filesByKind),
        'files_by_kind'       => $filesByKind,
    ];
}

function activity_org_current_roster(?string $asOfDate = null): array
{
    require_once __DIR__ . '/headcount_functions.php';
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $date = $asOfDate ?: $now->format('Y-m-d');

    $teams = [
        'mgmt' => ['key' => 'mgmt', 'title' => '🏢 경영진', 'class' => 'team-mgmt', 'members' => []],
        'const' => ['key' => 'const', 'title' => '🏗️ 공사팀', 'class' => 'team-const', 'members' => []],
        'gas' => ['key' => 'gas', 'title' => '⚡ GAS팀', 'class' => 'team-gas', 'members' => []],
        'mfg' => ['key' => 'mfg', 'title' => '⚙️ 제조팀', 'class' => 'team-mfg', 'members' => []],
    ];

    try {
        $rows = csm_headcount_rows();
        foreach ($rows as $r) {
            $c = csm_headcount_classify($r, $date);
            if ($c['state'] === 'excluded') continue;

            $name = trim((string)$r['name']);
            $rawTeam = trim((string)($r['team'] ?? ''));
            $pureName = preg_replace('/\s*\(.*?\)\s*/u', '', $name);

            $isLeader = false;
            $badge = '';
            $pos = '사원';
            $order = 90;

            if ($pureName === '정연탁') {
                $teams['mgmt']['members'][] = [
                    'name' => '정연탁', 'pos' => '대표이사', 'badge' => '',
                    'leader' => true, 'order' => 1
                ];
                continue;
            }
            if ($pureName === '정주랑') {
                $teams['mgmt']['members'][] = [
                    'name' => '정주랑', 'pos' => '실장', 'badge' => '',
                    'leader' => false, 'order' => 2
                ];
                continue;
            }

            if (str_contains($rawTeam, '공사팀') || str_contains($rawTeam, '전기') || str_contains($rawTeam, '모터')) {
                if ($pureName === '진종철') { $pos = '부장'; $badge = '관리감독자'; $isLeader = true; $order = 1; }
                elseif ($pureName === '윤택천') { $pos = '반장'; $badge = '근로자대표'; $isLeader = true; $order = 2; }
                elseif ($pureName === '김종훈') { $pos = '반장'; $badge = '관리감독자'; $isLeader = true; $order = 3; }
                else { $order = 10; }
                $teams['const']['members'][] = ['name' => $pureName, 'pos' => $pos, 'badge' => $badge, 'leader' => $isLeader, 'order' => $order];
            } elseif (str_contains($rawTeam, '가스') || str_contains($rawTeam, 'GAS')) {
                if ($pureName === '김도담') { $pos = '반장'; $badge = '관리감독자'; $isLeader = true; $order = 1; }
                else { $order = 10; }
                $teams['gas']['members'][] = ['name' => $pureName, 'pos' => $pos, 'badge' => $badge, 'leader' => $isLeader, 'order' => $order];
            } elseif (str_contains($rawTeam, '제조') || str_contains($rawTeam, '안전')) {
                if ($pureName === '우홍기') { $pos = '부장'; $badge = '관리감독자'; $isLeader = true; $order = 1; }
                elseif ($pureName === '김남균') { $pos = '과장'; $badge = '안전담당자'; $isLeader = true; $order = 2; }
                else { $order = 10; }
                $teams['mfg']['members'][] = ['name' => $pureName, 'pos' => $pos, 'badge' => $badge, 'leader' => $isLeader, 'order' => $order];
            }
        }

        foreach ($teams as &$t) {
            usort($t['members'], static function($a, $b) {
                if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
                return strcmp($a['name'], $b['name']);
            });
        }
        unset($t);

    } catch (Throwable) {
        $teams['mgmt']['members'] = [
            ['name' => '정연탁', 'pos' => '대표이사', 'badge' => '', 'leader' => true],
            ['name' => '정주랑', 'pos' => '실장', 'badge' => '', 'leader' => false],
        ];
        $teams['const']['members'] = [
            ['name' => '진종철', 'pos' => '부장', 'badge' => '관리감독자', 'leader' => true],
            ['name' => '윤택천', 'pos' => '반장', 'badge' => '근로자대표', 'leader' => true],
            ['name' => '김종훈', 'pos' => '반장', 'badge' => '관리감독자', 'leader' => true],
            ['name' => '엄기준', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '윤순형', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '조한봉', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '윤동은', 'pos' => '사원', 'badge' => '', 'leader' => false],
        ];
        $teams['gas']['members'] = [
            ['name' => '김도담', 'pos' => '반장', 'badge' => '관리감독자', 'leader' => true],
            ['name' => '박재민', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '이윤성', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '장진혁', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '정재호', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '최정섭', 'pos' => '사원', 'badge' => '', 'leader' => false],
        ];
        $teams['mfg']['members'] = [
            ['name' => '우홍기', 'pos' => '부장', 'badge' => '관리감독자', 'leader' => true],
            ['name' => '김남균', 'pos' => '과장', 'badge' => '안전담당자', 'leader' => true],
            ['name' => '김진환', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '정영교', 'pos' => '사원', 'badge' => '', 'leader' => false],
            ['name' => '장용하', 'pos' => '사원', 'badge' => '', 'leader' => false],
        ];
    }

    $total = 0;
    foreach ($teams as $t) {
        $total += count($t['members']);
    }

    return [
        'date' => $date,
        'formatted_date' => (new DateTimeImmutable($date))->format('Y년 n월 j일'),
        'total' => $total,
        'teams' => $teams,
    ];
}
