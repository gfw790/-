<?php
declare(strict_types=1);

require_once __DIR__ . '/leadership_store.php';

function activity_improvement_file_kinds(): array
{
    return [
        'csm_report'     => '전년도 CSM 안전활동평가 개선보고서 (Excel/PPT)',
        'external_audit' => '외부평가(노동부·공단·가스안전공사) 점검결과 및 조치확인서',
        'plan_reflect'   => '당해년도(2026) 활동계획서 미흡사항 반영 확인서',
        'photo_evidence' => '개선 전·후 현장 사진 증빙 대장',
    ];
}

function activity_improvement_default_data(int $year): array
{
    return [
        'csm_improvements' => [
            // 2025년 상반기 CSM 개선사항 (7건)
            [
                'id'              => 1,
                'period'          => '2025년 상반기',
                'seq'             => 1,
                'location'        => '사무실 (교육장)',
                'finding'         => '신규 입사자 교육에 근로자의 이해도 평가 추가 필요',
                'action_plan'     => '모바일 설문(구글폼 퀴즈)을 통해 교육내용 이해도 측정 및 정답률(80% 이상) 관리 체계 도입',
                'manager'         => '김남균 과장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image5.jpg',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image4.jpeg',
                'photo_before_cap'=> '강의식 단방향 신규교육 실시 (이해도 확인 절차 없음)',
                'photo_after_cap' => '모바일 QR 설문 퀴즈 도입 및 80점 이상 이수 확인',
                'plan_reflection' => '2026년 안전보건활동계획서 제4편 안전보건교육계획 (제2장 신규채용자 이해도 평가 기준 명문화)',
                'plan_chapter'    => '제4편 안전보건교육계획',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 2,
                'period'          => '2025년 상반기',
                'seq'             => 2,
                'location'        => '사무실 (보건관리)',
                'finding'         => 'C1, D1 직업병 유소견자 발생에 따른 직종 변경 또는 집중관리 필요',
                'action_plan'     => '소음 노출 근로자 청력 전수검사 후 맞춤형 청력보호구(차음 귀마개) 지급 및 2026.05 건강진단 1:1 추적검사 계획 수립',
                'manager'         => '김남균 과장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image7.JPG',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image6.png',
                'photo_before_cap'=> '일반 보호구 착용 및 사후관리 기록 분리 미흡',
                'photo_after_cap' => '청력보호구 전용함 설치 및 유소견자 1:1 추적상담',
                'plan_reflection' => '2026년 안전보건활동계획서 제5편 근로자 건강증진계획 (제3장 직업병 유소견자 사후관리 대책 반영)',
                'plan_chapter'    => '제5편 근로자 건강증진계획',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 3,
                'period'          => '2025년 상반기',
                'seq'             => 3,
                'location'        => '사무실 (회의실)',
                'finding'         => '산안위 의결사항 외 종사자 의견청취 유무 및 결과 공유 체계 보완 필요',
                'action_plan'     => '분기별 회의록 양식에 현장 종사자 의견수렴란 신설 및 사내 휴게실 게시판을 통한 회의결과 전사 공지',
                'manager'         => '김남균 과장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image9.JPG',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image8.png',
                'photo_before_cap'=> '단순 회의 의결사항만 기록된 종전 회의록',
                'photo_after_cap' => '종사자 건의사항 및 환류결과 반영 회의록·게시판 공지',
                'plan_reflection' => '2026년 안전보건활동계획서 제2편 목표 및 제3편 산업안전보건위원회 운영규정 반영',
                'plan_chapter'    => '제3편 산업안전보건위원회',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 4,
                'period'          => '2025년 상반기',
                'seq'             => 4,
                'location'        => '자재창고',
                'finding'         => '고장난 Hoist에 대해 안전인증 확인 불가 및 안전표식 미부착',
                'action_plan'     => '명판 훼손 제품 현장 점검 후 즉시 적색 미사용 금지 Tag 부착 및 차기 정기안전검사 신청',
                'manager'         => '진종철 부장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image11.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image10.png',
                'photo_before_cap'=> '표지 없이 방치된 미작동 호이스트 크레인',
                'photo_after_cap' => '사용금지 식별 Tag 부착 및 전원 차단 조치',
                'plan_reflection' => '2026년 안전보건활동계획서 제7편 유해·위험기계기구 점검계획 (양중기 LOTO 및 Tag아웃 절차화)',
                'plan_chapter'    => '제7편 유해위험 기계기구점검',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 5,
                'period'          => '2025년 상반기',
                'seq'             => 5,
                'location'        => '자재창고',
                'finding'         => '누전차단기 미부착 콘센트 사용에 따른 감전 위험',
                'action_plan'     => '현장 방치된 노후 콘센트 즉시 전량 수거 및 폐기 처분, 고감도 누전차단형 멀티콘센트로 전면 교체',
                'manager'         => '진종철 부장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image13.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image12.png',
                'photo_before_cap'=> '누전차단기 없는 노후 이동식 콘센트 노출',
                'photo_after_cap' => '규격 방우형 누전차단 콘센트 교체 및 노후품 폐기',
                'plan_reflection' => '2026년 안전보건활동계획서 일상 안전점검 기준 (전기기계기구 월 1회 누전차단 시험 점검표 반영)',
                'plan_chapter'    => '제7편 유해위험 기계기구점검',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 6,
                'period'          => '2025년 상반기',
                'seq'             => 6,
                'location'        => '제작장',
                'finding'         => '회전체 말림점에 대한 방호울 미설치로 협착 위험 노출',
                'action_plan'     => '원형 롤러 및 회전 말림점에 철망 규격 방호울 제작 설치 및 접근 금지 표지 부착',
                'manager'         => '진종철 부장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image15.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image14.png',
                'photo_before_cap'=> '회전 롤러 말림점 개방 상태 가동',
                'photo_after_cap' => '견고한 안전 방호울 및 경고표지 설치 완료',
                'plan_reflection' => '2026년 안전보건활동계획서 제7편 기계기구 안전방호장치 점검계획 반영',
                'plan_chapter'    => '제7편 유해위험 기계기구점검',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 7,
                'period'          => '2025년 상반기',
                'seq'             => 7,
                'location'        => '제작장',
                'finding'         => '고속절단기 추가 안전보호커버 미설치로 파편 비산 위험',
                'action_plan'     => '보호커버 일체형 최신 안전인증 고속절단기로 즉시 교체 및 작업자 보안경/안면보호구 지급',
                'manager'         => '진종철 부장',
                'target_schedule' => '즉시 조치',
                'status'          => 'completed',
                'completed_date'  => '2025-08-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image17.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image16.png',
                'photo_before_cap'=> '추가 방호커버가 결여된 기존 절단기',
                'photo_after_cap' => '완전 방호커버 장착 규격 제품 교체 및 점검표 부착',
                'plan_reflection' => '2026년 안전보건활동계획서 공도구 방호장치 점검 기준 반영',
                'plan_chapter'    => '제7편 유해위험 기계기구점검',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],

            // 2025년 하반기 CSM 개선사항 (7건)
            [
                'id'              => 8,
                'period'          => '2025년 하반기',
                'seq'             => 1,
                'location'        => '사무실 및 현장',
                'finding'         => '안전작업표준서가 공용 양식 위주로 운영되어 2023년 이후 안전작업허가서 양식 갱신 필요',
                'action_plan'     => '현대기전 자체 공정에 부합하는 맞춤형 안전작업표준서 및 2026년형 위험작업허가서(화기·고소·밀폐) 양식 전면 개정',
                'manager'         => '김남균 과장',
                'target_schedule' => '완료 (2025.12.18)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-18',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p2_image10.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p2_image12.png',
                'photo_before_cap'=> '과거 2023년 공용 안전작업허가서 양식',
                'photo_after_cap' => '2026년형 자체 안전작업허가서 및 표준작업서 개편',
                'plan_reflection' => '2026년 안전보건활동계획서 제2편 안전관리체계 (자체 표준작업절차서 갱신 반영)',
                'plan_chapter'    => '제2편 안전보건관리체계',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 9,
                'period'          => '2025년 하반기',
                'seq'             => 2,
                'location'        => '가스보관소',
                'finding'         => '산소 용기와 잔가스 실린더가 구분은 되어 있으나 물리적 칸막이 미설치',
                'action_plan'     => '불연성 차단 칸막이 및 충전용기/잔가스용기 분리 보관대 제작 설치 완료',
                'manager'         => '진종철 부장',
                'target_schedule' => '완료 (2025.12.20)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-20',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p2_image13.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p2_image13.png',
                'photo_before_cap'=> '물리적 격벽 없이 혼재 구역에 놓인 가스통',
                'photo_after_cap' => '전용 철재 격벽 설치 및 충전/공병 분리 보관 완료',
                'plan_reflection' => '2026년 안전보건활동계획서 제8편 위험물질 안전관리지침 반영',
                'plan_chapter'    => '제8편 위험물질 관리지침',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 10,
                'period'          => '2025년 하반기',
                'seq'             => 3,
                'location'        => '구급함 / 보건실',
                'finding'         => '구급함 비치 상태는 양호하나 일부 의약품 유통기한 경과 확인',
                'action_plan'     => '전 사업장 구급함 전수조사 후 유통기한 경과 품목 전량 폐기 및 신규 비상약품 세트 구매 비치, 월간 점검표 부착',
                'manager'         => '김남균 과장',
                'target_schedule' => '완료 (2025.12.12)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-12',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p2_image15.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p2_image14.png',
                'photo_before_cap'=> '유통기한 경과 약품 혼재된 구급상자',
                'photo_after_cap' => '신품 의약품 전면 교체 및 구급함 유효기간 관리대장 부착',
                'plan_reflection' => '2026년 안전보건활동계획서 제5편 응급의료 및 구급장비 관리계획 반영',
                'plan_chapter'    => '제5편 근로자 건강증진계획',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 11,
                'period'          => '2025년 하반기',
                'seq'             => 4,
                'location'        => '현장 TBM 장소',
                'finding'         => 'TBM 일지 운영 중 현장 위험요인 발굴에 작업 빈도·강도 연계 공유 확대 권고',
                'action_plan'     => 'TBM 일지 서식에 고위험 작업 빈도/강도 기재란 및 당일 핵심 유해위험요인 3대 수칙 전파 항목 추가',
                'manager'         => '김남균 과장',
                'target_schedule' => '완료 (2025.12.15)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-15',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p2_image17.jpeg',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p2_image19.png',
                'photo_before_cap'=> '단순 참석자 서명 위주의 기존 TBM 일지',
                'photo_after_cap' => '사고공유 및 위험도(빈도·강도) 기재 개편 TBM 일지',
                'plan_reflection' => '2026년 안전보건활동계획서 제3편 작업 전 안전점검회의(TBM) 운영지침 반영',
                'plan_chapter'    => '제3편 근로자 참여 및 TBM',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 12,
                'period'          => '2025년 하반기',
                'seq'             => 5,
                'location'        => '위험성평가 회의실',
                'finding'         => '아차사고 및 빈번하게 발생하는 고위험 작업에 대한 정기 위험성평가 연계 보완',
                'action_plan'     => '아차사고 발굴 내역(연간 12건)을 위험성평가 수시평가 안건으로 자동 상정하여 개선대책 수립 프로세스 정립',
                'manager'         => '김남균 과장',
                'target_schedule' => '완료 (2025.12.22)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-22',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p2_image21.jpeg',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p2_image23.png',
                'photo_before_cap'=> '아차사고 접수 건과 위험성평가 간 연계성 미흡',
                'photo_after_cap' => '아차사고 데이터 기반 정기·수시 위험성평가 매트릭스 반영',
                'plan_reflection' => '2026년 안전보건활동계획서 제3편 위험성평가 실행계획 (아차사고 연계 수시평가제 도입)',
                'plan_chapter'    => '제3편 위험성평가 실행계획',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 13,
                'period'          => '2025년 하반기',
                'seq'             => 6,
                'location'        => '제작장 메인 통로',
                'finding'         => '용접기 케이블이 작업장 통로에 늘어서 있어 통행 시 전도(넘어짐) 위험',
                'action_plan'     => '공중 가선 지주대 설치 및 이동형 케이블 릴 거치대를 구비하여 통로 간섭 요인 100% 제거',
                'manager'         => '진종철 부장',
                'target_schedule' => '완료 (2025.12.16)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-16',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p2_image25.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p2_image24.jpeg',
                'photo_before_cap'=> '바닥 통로에 용접선/전선 무단 노출 방치',
                'photo_after_cap' => '통로 상부 가선 및 케이블 거치대 정비로 전도 위험 차단',
                'plan_reflection' => '2026년 안전보건활동계획서 제8편 현장 3정5S 및 통로 안전확보 수칙 반영',
                'plan_chapter'    => '제8편 현장 3정5S 안전수칙',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
            [
                'id'              => 14,
                'period'          => '2025년 하반기',
                'seq'             => 7,
                'location'        => '제작장 및 야외장',
                'finding'         => '폐기물 보관소 분류 표지판 식별 미흡 및 3정5S 정리정돈 개선 필요',
                'action_plan'     => '“일반폐기물저장소-고철” 대형 규격 표지판 설치 및 고철·일반쓰레기 분리보관함 재배치',
                'manager'         => '진종철 부장',
                'target_schedule' => '완료 (2025.12.20)',
                'status'          => 'completed',
                'completed_date'  => '2025-12-20',
                'photo_before'    => 'storage/leadership_evidence/improvement_2025/p1_image13.png',
                'photo_after'     => 'storage/leadership_evidence/improvement_2025/p1_image12.png',
                'photo_before_cap'=> '표지 없이 야적된 폐기물 및 고철 더미',
                'photo_after_cap' => '규격 표지판 부착 및 분리수거 구역 구획 완료',
                'plan_reflection' => '2026년 안전보건활동계획서 제8편 폐기물 배출 및 환경안전관리 지침 반영',
                'plan_chapter'    => '제8편 환경안전관리 지침',
                'remark'          => '조치완료 100% / 당해년도 활동계획서 반영',
            ],
        ],

        'external_audits' => [
            [
                'id'                => 1,
                'agency'            => '고용노동부 강릉지청 / 안전보건공단',
                'audit_date'        => '2025-10-16',
                'audit_name'        => '위험성평가 우수사업장 사후관리 실사 및 유해위험요인 점검',
                'target_area'       => '제작장 전역 및 안전보건 서류',
                'finding'           => '제작장 내 고소작업 발판 단부 안전난간 체결 상태 보강 및 위험성평가 개선대책 이행확인표 현장 부착 요청',
                'corrective_action' => '단부 안전난간 규격 클램프 추가 보강 및 각 공정별 위험성평가 감소대책 이행확인표(QR 연동) 현장 부착 완료',
                'manager'           => '진종철 부장 / 김남균 과장',
                'completion_date'   => '2025-10-25',
                'status'            => 'completed',
                'result_cert'       => '위험성평가 우수사업장 인정 유지 판정 (공단 통보 접수)',
                'plan_reflection'   => '2026년 안전보건활동계획서 제3편 위험성평가 실행계획 (감소대책 현장 게시 일상점검화 반영)',
            ],
            [
                'id'                => 2,
                'agency'            => '한국가스안전공사 / 발주처(한라) 합동점검',
                'audit_date'        => '2025-11-20',
                'audit_name'        => '고압가스 및 위험물 저장시설 합동 특별안전진단',
                'target_area'       => '가스 실린더 보관소',
                'finding'           => 'LPG 및 산소용기 차광막 설치 상태 점검 및 가스누출감지 센서 교정필증 갱신 확인 요망',
                'corrective_action' => '불연 차광막 교체 및 가스감지센서 교정 검정 완료, 월간 점검표 갱신 관리',
                'manager'           => '진종철 부장',
                'completion_date'   => '2025-11-28',
                'status'            => 'completed',
                'result_cert'       => '적합 (시정요구사항 전수 개선 완료)',
                'plan_reflection'   => '2026년 안전보건활동계획서 제7편 및 제8편 고압가스 안전관리 세부수칙 반영',
            ],
        ],

        'plan_reflections' => [
            [
                'seq'               => 1,
                'source'            => '2025년 상반기 CSM 평가 지적사항',
                'source_item'       => '신규 입사자 교육 근로자 이해도 평가 도입',
                'plan_section'      => '제4편 안전보건교육계획 제2장 신규채용자 교육',
                'reflected_content' => '신규 교육 시 단순 강의형 탈피, 스마트폰 모바일 퀴즈를 통한 80점 이상 이수 기준 명문화 및 미달자 재교육 의무화',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
            [
                'seq'               => 2,
                'source'            => '2025년 상반기 CSM 평가 지적사항',
                'source_item'       => 'C1/D1 청력 유소견자 보호구 집중관리',
                'plan_section'      => '제5편 근로자 건강증진계획 제3장 직업병 유소견자 사후관리',
                'reflected_content' => '청력요관찰자 맞춤형 차음 귀마개(NRR 30dB) 지급 및 2026년 5월 건강진단 시 1:1 추적상담 편성',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
            [
                'seq'               => 3,
                'source'            => '2025년 상반기 CSM 평가 지적사항',
                'source_item'       => '산안위 근로자 의견청취 및 결과 전사 공유',
                'plan_section'      => '제2편 안전보건목표 및 제3편 산업안전보건위원회 운영규정',
                'reflected_content' => '매분기 회의록에 종사자 의견수렴란 신설, 안건 협의결과를 휴게실 전자게시판 및 오프라인 게시판에 의무 공지',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
            [
                'seq'               => 4,
                'source'            => '2025년 상반기 CSM 평가 지적사항',
                'source_item'       => '고장 Hoist 미사용 표식 및 안전인증 확인',
                'plan_section'      => '제7편 유해·위험기계기구 안전점검계획 제1장 양중기 관리',
                'reflected_content' => '양중기 안전인증 정기검사 라벨링 전수 확인 및 고장·정비 중 기구에 대한 표준 LOTO 미사용 Tag 절차 도입',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
            [
                'seq'               => 5,
                'source'            => '2025년 하반기 CSM 평가 지적사항',
                'source_item'       => '안전작업표준서 자체 최신화 및 허가서 개편',
                'plan_section'      => '제2편 안전보건관리체계 제4장 안전작업허가제도',
                'reflected_content' => '화기·고소·밀폐작업에 대해 현대기전 고유 현장 여건을 반영한 표준작업절차서 갱신 및 허가서 양식 전면 개정',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
            [
                'seq'               => 6,
                'source'            => '2025년 하반기 CSM 평가 지적사항',
                'source_item'       => 'TBM 일지 위험도(빈도·강도) 및 사고사례 전파',
                'plan_section'      => '제3편 근로자 참여 및 작업 전 안전점검회의(TBM)',
                'reflected_content' => 'TBM 시 당일 작업의 위험빈도·강도를 표시하고 최신 중대재해 사이렌 사고사례를 전파하도록 일지 서식 개편 반영',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
            [
                'seq'               => 7,
                'source'            => '외부평가 (노동부/공단/가스안전공사)',
                'source_item'       => '가스용기 격벽 분리 및 고소작업 발판 난간 보강',
                'plan_section'      => '제7편 및 제8편 현장 작업안전수칙 및 위험물질 관리지침',
                'reflected_content' => 'LPG-산소 격리보관 표준화 및 고소작업 발판 단부 규격품 클램프 체결 의무화, 위험성평가 현장 게시 점검표 정례화',
                'status'            => '반영완료',
                'verified_date'     => '2026-01-02',
            ],
        ],
    ];
}

function activity_improvement_records(PDO $db, int $year): ?array
{
    $query = $db->prepare("SELECT * FROM leadership_records WHERE item = '1.2.4' AND year = ? ORDER BY id DESC LIMIT 1");
    $query->execute([$year]);
    $record = $query->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        $defaultData = activity_improvement_default_data($year);
        $payloadJson = json_encode($defaultData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $insert = $db->prepare("
            INSERT INTO leadership_records (item, year, activity_date, title, payload, revision, updated_by, updated_at)
            VALUES ('1.2.4', ?, ?, ?, ?, 1, '안전담당자 김남균', datetime('now', 'localtime'))
        ");
        $insert->execute([$year, sprintf('%04d-01-02', $year), "{$year}년도 안전보건활동계획 전년도 개선계획 반영 및 개선완료 관리", $payloadJson]);
        $newId = (int)$db->lastInsertId();

        // Register default evidence files if in leadership_evidence
        $seedEvidence = [
            ['orig' => '[현대기전] 2025년 상반기 안전활동평가 개선완료 내역서.xlsx', 'kind' => 'csm_report', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['orig' => '[현대기전] 2024년 하반기 안전활동평가 개선완료 내역서.xlsx', 'kind' => 'csm_report', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['orig' => '[현대기전] CSM평가 연도별 종합개선현황.xlsx', 'kind' => 'csm_report', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['orig' => '[현대기전] 2025년 상반기 안전활동평가 개선결과 보고서.pptx', 'kind' => 'photo_evidence', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ['orig' => '[현대기전] 2025년 하반기 안전활동평가 개선결과 보고서.pptx', 'kind' => 'photo_evidence', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ['orig' => '[공문] 협력업체 안전보건 사후평가 및 개선계획 요청 공문.pdf', 'kind' => 'external_audit', 'mime' => 'application/pdf'],
        ];

        $insFile = $db->prepare("
            INSERT INTO leadership_files (record_id, original_name, stored_name, mime, size, file_kind)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $evidenceDir = __DIR__ . '/storage/leadership_evidence';
        if (is_dir($evidenceDir)) {
            $files = scandir($evidenceDir) ?: [];
            foreach ($seedEvidence as $s) {
                $ext = pathinfo($s['orig'], PATHINFO_EXTENSION);
                foreach ($files as $f) {
                    if (str_ends_with($f, '.' . $ext) && strlen($f) > 40) {
                        $fullPath = $evidenceDir . '/' . $f;
                        $size = filesize($fullPath);
                        // Check if file matches by size or heuristic
                        if ($size > 1000) {
                            $chk = $db->prepare("SELECT id FROM leadership_files WHERE record_id = ? AND original_name = ?");
                            $chk->execute([$newId, $s['orig']]);
                            if (!$chk->fetch()) {
                                $insFile->execute([$newId, $s['orig'], $f, $s['mime'], $size, $s['kind']]);
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Re-query newly created record
        $query->execute([$year]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
    }

    if (!$record) return null;

    try {
        $record['data'] = json_decode((string)$record['payload'], true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $record['data'] = activity_improvement_default_data($year);
    }

    $fileQuery = $db->prepare('SELECT * FROM leadership_files WHERE record_id = ? AND deleted_at IS NULL ORDER BY id ASC');
    $fileQuery->execute([(int)$record['id']]);
    $files = $fileQuery->fetchAll(PDO::FETCH_ASSOC);

    $record['files'] = $files;
    $record['files_by_kind'] = [];
    foreach ($files as $file) {
        $kind = $file['file_kind'] ?: 'csm_report';
        $record['files_by_kind'][$kind][] = $file;
    }

    return $record;
}

function activity_improvement_status(array $data, array $files): array
{
    $csmItems = $data['csm_improvements'] ?? [];
    $externalAudits = $data['external_audits'] ?? [];
    $reflections = $data['plan_reflections'] ?? [];

    $filesByKind = [];
    foreach ($files as $f) {
        if (!empty($f['deleted_at'])) continue;
        $kind = $f['file_kind'] ?: 'csm_report';
        $filesByKind[$kind] = ($filesByKind[$kind] ?? 0) + 1;
    }

    $totalCsm = count($csmItems);
    $completedCsm = 0;
    foreach ($csmItems as $item) {
        if (($item['status'] ?? '') === 'completed') {
            $completedCsm++;
        }
    }
    $csmRate = $totalCsm > 0 ? round(($completedCsm / $totalCsm) * 100, 1) : 0.0;

    $totalAudits = count($externalAudits);
    $completedAudits = 0;
    foreach ($externalAudits as $audit) {
        if (($audit['status'] ?? '') === 'completed') {
            $completedAudits++;
        }
    }

    $totalReflections = count($reflections);
    $activeFilesCount = count(array_filter($files, fn($f) => empty($f['deleted_at'])));

    // Scoring conditions (Item 1.2.4 배점 2.0점):
    // 1.0 (100% 만점, 2.0점): 노동부·공단 점검결과 관리 & 미흡사항 당해연도 계획 반영 & 개선관리 완료 (100%)
    // 0.9 (90%, 1.8점): 미흡사항 당해연도 계획 반영 (90% 이상 개선)
    // 0.8 (80%, 1.6점): 개선관리 (90% 이상)
    // 0.5 (50%, 1.0점): 개선관리 (80% 미만)
    // 0 (0%): 관리 안됨

    $hasExternalAudit = $totalAudits >= 1 && $completedAudits >= 1;
    $hasPlanReflections = $totalReflections >= 3;
    $isAllCompleted = $totalCsm > 0 && $completedCsm === $totalCsm;
    $hasEvidence = $activeFilesCount >= 1;

    $multiplier = 0.0;
    $statusText = '관리 안됨 (0점)';

    if ($hasExternalAudit && $hasPlanReflections && $isAllCompleted && $hasEvidence) {
        $multiplier = 1.0;
        $statusText = '충족 (100% 만점, 2.0점)';
    } elseif ($hasPlanReflections && $csmRate >= 90.0) {
        $multiplier = 0.9;
        $statusText = '계획반영 충족 (90%, 1.8점)';
    } elseif ($csmRate >= 90.0) {
        $multiplier = 0.8;
        $statusText = '개선관리 90% 이상 (80%, 1.6점)';
    } elseif ($csmRate >= 50.0 || $totalCsm > 0) {
        $multiplier = 0.5;
        $statusText = '개선관리 80% 미만 (50%, 1.0점)';
    }

    return [
        'multiplier'          => $multiplier,
        'status_text'         => $statusText,
        'total_csm'           => $totalCsm,
        'completed_csm'       => $completedCsm,
        'csm_rate'            => $csmRate,
        'total_audits'        => $totalAudits,
        'completed_audits'    => $completedAudits,
        'total_reflections'   => $totalReflections,
        'files_count'         => $activeFilesCount,
        'files_by_kind'       => $filesByKind,
        'has_external_audit'  => $hasExternalAudit,
        'has_plan_reflections'=> $hasPlanReflections,
        'is_all_completed'    => $isAllCompleted,
        'has_evidence'        => $hasEvidence,
    ];
}
