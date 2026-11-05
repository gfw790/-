const state = { kind: "form", documents: [], project: null, logs: [], entryDocument: null, entries: [], editingEntryId: null, activePart: null };
const statuses = [
  "미작성", "작성 중", "서명대기", "완료", "해당없음",
  "표기 확인", "표기 수정", "실적 보완", "현장정보 보완", "착수 후 갱신",
];
const $ = (selector) => document.querySelector(selector);

const equipmentInspectionItems = {
  "압축공기 압축기": ["본체·프레임 손상 및 누유 여부","압력계 지시 및 검교정 상태","안전밸브 설치·봉인 및 작동 상태","압력조정기·드레인밸브 상태","벨트·회전체 방호덮개 설치 상태","전원선·접지·누전차단기 상태","호스 연결부·커플링 체결 상태","시험운전 시 이상소음·진동·과열 여부"],
  "압축공기 호스·커플링": ["호스 마모·균열·찢김 여부","사용압력에 적합한 호스인지 확인","커플링 변형·마모·체결 상태","연결부 이탈방지 안전핀·와이어 설치","호스 꺾임·꼬임·눌림 여부","이동통로와 분리·정리 상태","사용 후 잔압 제거 및 보관 상태"],
  "Wire Brush·Scraper 수공구": ["손잡이 균열·파손·이탈 여부","브러시 철선 탈락·마모 상태","스크레이퍼 날 변형·균열 상태","작업용도와 크기에 적합한 공구인지 확인","기름·분진 오염 및 미끄럼 여부","작업방향과 주변 작업자 간격 확보","보안경·장갑 등 보호구 착용","사용 후 세척·정리 및 지정장소 보관"],
  "고압세척기": ["본체·프레임 손상 및 누유 여부","고압호스 마모·균열·꼬임 여부","호스 커플링·체결부 이탈방지 상태","압력계·안전밸브 정상 작동 여부","분사건 트리거·잠금장치 상태","전원선·접지·누전차단기 상태","시험운전 시 이상소음·진동·누수 여부","보안면·방수복·안전화 등 보호구 착용"],
  "산업용 진공청소기": ["본체·집진통·필터 손상 여부","흡입호스 균열·막힘·체결 상태","전원선·플러그·접지 상태","누전차단기 설치·작동 여부","필터·분진 회수통 청소 상태","흡입력 및 작동소음 이상 여부","습식 사용 가능 여부 및 용도 적합성","사용 후 폐기물 분리·보관 상태"],
  "이동식 전기공구": ["본체·손잡이·절연부 손상 여부","전원선·플러그·접지 상태","누전차단기 연결 여부","방호덮개·보조손잡이 설치 상태","날·비트·디스크 균열 및 체결 상태","작동·정지 스위치 정상 여부","공회전 시 이상소음·진동 여부","보안경·안면보호구·방진마스크 착용"],
  "용접기": ["용접기 본체·외함·접지 상태","전원선·홀더선·어스선 피복 손상 여부","홀더·접지클램프 체결 상태","자동전격방지기 설치·작동 여부","가스호스·조정기·역화방지기 상태","주변 가연물 제거 및 소화기 배치","용접면·용접장갑·방염복 착용","화기작업허가 및 화재감시자 배치"],
  "체인블록·호이스트": ["정격하중 표시 및 검사 유효 여부","후크 변형·균열 및 해지장치 상태","체인·와이어로프 마모·꼬임·부식 여부","브레이크·상하한 리미트 작동 여부","조작스위치·비상정지 상태","걸이부·지지점의 강도와 체결 상태","인양구역 출입통제 및 신호수 배치","시험인양 시 쏠림·이상음 여부"],
  "사다리·작업발판": ["사다리·발판 변형·균열·부식 여부","미끄럼방지 고무 및 벌어짐 방지장치 상태","답단·발판 고정 및 오염 여부","설치각도·수평·지반 상태 적정 여부","작업발판 폭과 틈새 적정 여부","난간·중간난간·발끝막이판 설치 여부","이동·전도 방지 고정조치 여부","추락방지대 걸이시설 및 보호구 상태"],
  "환기·송풍기": ["본체·팬·보호망 손상 여부","덕트 균열·막힘·연결 상태","전원선·접지·누전차단기 상태","흡입구·배출구 위치 적정 여부","회전방향과 풍량 정상 여부","이상소음·진동·과열 여부","방폭구역 사용 적합성 여부","예비 송풍기 및 정전 대비 여부"],
  "복합가스측정기": ["검교정 유효기간 확인","배터리 충전상태 확인","센서·흡입구 오염 및 손상 여부","전원·경보음·진동·표시창 작동 여부","사용 전 범프테스트 실시 여부","산소·가연성·유해가스 영점 확인","측정 대상가스와 센서 구성 적합 여부","측정값 기록 및 기준초과 대응 준비"],
  "이동식 조명": ["등기구·보호망·유리 손상 여부","방수·방진 및 밀폐공간용 전압 적합성","전원선·플러그 피복 손상 여부","접지 및 누전차단기 상태","연결부 방수·절연 상태","설치 고정 및 전도 방지 상태","가연물과 안전거리 확보 여부","점등상태·과열·깜빡임 여부"],
  "개인보호구": ["작업위험에 적합한 보호구 선정 여부","안전모 균열·턱끈·내피 상태","보안경·보안면 흠집·시야 상태","방진·방독마스크 필터와 유효기간","안전화 밑창·선심·미끄럼 상태","안전대 벨트·죔줄·후크 손상 여부","보호장갑·방수복 훼손·오염 여부","개인별 지급·착용 및 청결 보관 상태"],
  "기타 장비": ["외관 손상·변형·부식 여부","안전장치·방호덮개 설치 상태","전원·동력원 및 접지 상태","작동·정지·비상정지 기능","체결부 풀림·누유·이상소음 여부","검사·인증·사용기한 유효 여부","용도 적합성 및 사용설명서 비치","보호구 지급·착용 및 보관 상태"],
};

equipmentInspectionItems["비계·작업발판"] = equipmentInspectionItems["사다리·작업발판"];
const equipmentOptions = ["압축공기 압축기","산업용 진공청소기","압축공기 호스·커플링","Wire Brush·Scraper 수공구","비계·작업발판","환기·송풍기","복합가스측정기","이동식 조명","개인보호구","기타 장비"];
const equipmentInspectionMaps = {
  "압축공기 압축기": {
    image: "/static/assets/air-compressor-inspection.png",
    alt: "압축공기 압축기 점검부위 일러스트",
    parts: [
      { name: "본체·탱크", detail: "탱크와 프레임의 변형·균열·부식·누유 및 명판 상태를 확인합니다.", x: 35, y: 58, lx: 25, ly: 92 },
      { name: "압력계", detail: "지침 복귀, 표시범위, 파손 여부와 검교정 상태를 확인합니다.", x: 71, y: 30, lx: 48, ly: 6 },
      { name: "안전밸브", detail: "안전밸브의 설치·봉인·부식 여부와 정상 작동 상태를 확인합니다.", x: 84, y: 27, lx: 84, ly: 6 },
      { name: "압력조정기", detail: "조정기와 필터의 파손·누설 및 설정압력 유지 상태를 확인합니다.", x: 77, y: 33, lx: 66, ly: 6 },
      { name: "벨트 방호덮개", detail: "회전체 방호덮개의 설치, 고정, 변형 및 접촉 가능성을 확인합니다.", x: 37, y: 9, lx: 20, ly: 6 },
      { name: "전원·접지", detail: "전원선·플러그 피복, 접지선과 누전차단기 작동 상태를 확인합니다.", x: 5, y: 75, lx: 6, ly: 92 },
      { name: "호스 커플링", detail: "호스와 커플링의 마모·균열·체결 및 이탈방지 조치를 확인합니다.", x: 90, y: 38, lx: 92, ly: 15 },
      { name: "드레인밸브", detail: "응축수 배출밸브의 막힘·누설과 작업 후 배수 상태를 확인합니다.", x: 53, y: 80, lx: 53, ly: 92 },
    ],
  },
  "산업용 진공청소기": {
    image: "/static/assets/vacuum-inspection.png",
    alt: "산업용 진공청소기 점검부위 일러스트",
    parts: [
      { name: "헤드(모터부)", detail: "흡입력 저하, 작동 중 이상소음·과열 여부를 확인합니다.", x: 67, y: 10, lx: 99, ly: 4 },
      { name: "철망(프리필터)", detail: "철망의 파손·먼지 막힘 여부와 청소 상태를 확인합니다.", x: 67, y: 31, lx: 99, ly: 18 },
      { name: "필터", detail: "필터의 찢김·변형 여부와 분진 회수통 청소 상태를 확인합니다.", x: 67, y: 52, lx: 99, ly: 32 },
      { name: "흡입호스", detail: "호스의 균열·막힘 여부와 본체·작업대 연결부 체결 상태를 확인합니다.", x: 51, y: 55, lx: 35, ly: 30 },
      { name: "호스 커플링", detail: "호스와 본체 연결부(커플링)의 마모·파손 여부와 체결 상태를 확인합니다.", x: 58, y: 80, lx: 99, ly: 46 },
      { name: "본체·집진통", detail: "투명 집진통의 파손 여부와 사용 후 폐기물 분리·보관 상태를 확인합니다.", x: 68, y: 91, lx: 99, ly: 60 },
      { name: "누전차단기", detail: "전원코드에 설치된 누전차단기(또는 배선용 차단기)의 설치와 작동 여부를 확인합니다.", x: 88, y: 91, lx: 99, ly: 74 },
      { name: "전원선·플러그", detail: "전원선 피복 손상 여부와 플러그 접지핀 상태를 확인합니다.", x: 95, y: 90, lx: 99, ly: 88 },
    ],
  },
  "압축공기 호스·커플링": {
    image: "/static/assets/hose-coupling-inspection.png",
    alt: "압축공기 호스·커플링 점검부위 안내도",
    parts: [
      { name: "호스 외관", detail: "호스의 마모·미세균열·찢김 등 외관 손상 여부를 확인합니다.", x: 3, y: 5, lx: 3, ly: 5, box: [1, 1, 27, 44] },
      { name: "커플링 체결부", detail: "커플링의 변형·나사산 마모 여부와 체결 상태(체결완료/불량)를 확인합니다.", x: 33, y: 5, lx: 33, ly: 5, box: [29, 1, 69, 34] },
      { name: "호스 물리적 상태", detail: "호스의 꺾임·꼬임·눌림으로 인한 유량 감소 여부를 확인합니다.", x: 73, y: 5, lx: 73, ly: 5, box: [67, 1, 98, 45] },
      { name: "사용압력 적합성", detail: "호스의 규격 압력이 실사용 압력에 적합한지 확인하고, 압력 초과로 인한 층 분리 여부를 점검합니다.", x: 3, y: 54, lx: 3, ly: 54, box: [1, 50, 27, 98] },
      { name: "이탈방지 조치", detail: "연결부의 이탈방지 안전핀과 와이어가 정상 설치·체결되어 있는지 확인합니다.", x: 29, y: 70, lx: 29, ly: 70, box: [29, 65, 50, 98] },
      { name: "이동통로 정리", detail: "호스가 이동통로에 방치되지 않도록 분리·정리하고 전용 보관함 사용 여부를 확인합니다.", x: 52, y: 70, lx: 52, ly: 70, box: [51, 65, 73, 98] },
      { name: "사용 후 보관", detail: "사용 후 잔압을 완전히 제거하고, 직사광선을 피해 호스걸이·전용 보관대에 정리 보관합니다.", x: 74, y: 54, lx: 74, ly: 54, box: [74, 50, 98, 98] },
    ],
  },
  "Wire Brush·Scraper 수공구": {
    image: "/static/assets/wire-brush-scraper-inspection.png",
    alt: "Wire Brush·Scraper 수공구 점검부위 안내도",
    parts: [
      { name: "손잡이 상태", detail: "손잡이의 미세균열·파손 여부와 헤드 이탈 위험을 확인합니다.", x: 3, y: 6, lx: 3, ly: 6, box: [1, 4, 27, 48] },
      { name: "브러시 철선", detail: "철선의 탈락·마모 상태와 브러시 결속 상태를 확인합니다.", x: 31, y: 6, lx: 31, ly: 6, box: [29, 4, 49, 48] },
      { name: "스크레이퍼 날", detail: "날의 변형·미세균열과 무딘 날 여부를 확인합니다.", x: 53, y: 6, lx: 53, ly: 6, box: [51, 4, 71, 48] },
      { name: "공구 적합성", detail: "작업 용도와 크기에 맞는 공구인지 확인하고, 부적합 공구(너무 작음 등) 사용을 금지합니다.", x: 75, y: 6, lx: 75, ly: 6, box: [73, 4, 99, 48] },
      { name: "오염·미끄럼", detail: "기름·분진 오염과 미끄럼 위험 여부를 확인합니다.", x: 31, y: 55, lx: 31, ly: 55, box: [29, 53, 49, 96] },
      { name: "작업 간격", detail: "작업방향과 주변 작업자 간 최소 2m 이상 간격 확보 여부를 확인합니다.", x: 53, y: 55, lx: 53, ly: 55, box: [51, 53, 71, 96] },
      { name: "보호구 착용", detail: "작업 중 보안경(측면 보호·김서림 방지) 등 보호구 착용 여부를 확인합니다.", x: 3, y: 55, lx: 3, ly: 55, box: [1, 53, 27, 96] },
      { name: "세척·보관", detail: "사용 후 세척·건조 완료 및 지정 보관장소 정리 상태를 확인합니다.", x: 75, y: 55, lx: 75, ly: 55, box: [73, 53, 99, 96] },
    ],
  },
  "비계·작업발판": {
    image: "/static/assets/scaffold-platform-inspection.png",
    alt: "비계·작업발판 점검부위 안내도",
    parts: [
      { name: "변형·부식", detail: "사다리·발판의 변형·균열·부식 여부를 확인합니다.", x: 3, y: 6, lx: 3, ly: 6, box: [1, 4, 27, 48] },
      { name: "미끄럼·벌어짐 방지", detail: "미끄럼방지 고무 마모 상태와 벌어짐 방지장치의 고정 상태를 확인합니다.", x: 31, y: 6, lx: 31, ly: 6, box: [29, 4, 49, 48] },
      { name: "답단·발판 고정", detail: "답단·발판의 고정 상태와 오염 여부를 확인합니다.", x: 53, y: 6, lx: 53, ly: 6, box: [51, 4, 71, 48] },
      { name: "각도·수평·지반", detail: "설치각도, 수평 유지, 지반 상태가 적정한지 확인합니다.", x: 75, y: 6, lx: 75, ly: 6, box: [73, 4, 99, 48] },
      { name: "발판 폭·틈새", detail: "작업발판 폭(40cm 이상)과 틈새(3cm 미만)가 적정한지 확인합니다.", x: 3, y: 55, lx: 3, ly: 55, box: [1, 53, 27, 96] },
      { name: "난간·발끝막이판", detail: "난간·중간난간·발끝막이판 설치 상태를 확인합니다.", x: 31, y: 55, lx: 31, ly: 55, box: [29, 53, 49, 96] },
      { name: "이동·전도 방지", detail: "바퀴 브레이크 고정 등 이동·전도 방지 조치를 확인합니다.", x: 53, y: 55, lx: 53, ly: 55, box: [51, 53, 71, 96] },
      { name: "추락방지·보호구", detail: "추락방지대 걸이시설 설치 상태와 하네스·안전모 등 보호구 착용 상태를 확인합니다.", x: 75, y: 55, lx: 75, ly: 55, box: [73, 53, 99, 96] },
    ],
  },
  "환기·송풍기": {
    image: "/static/assets/ventilation-fan-inspection.png",
    alt: "환기·송풍기 점검부위 안내도",
    parts: [
      { name: "본체·팬·보호망", detail: "팬 블레이드 손상 여부와 보호망 파손 상태를 확인합니다.", x: 3, y: 6, lx: 3, ly: 6, box: [1, 4, 27, 48] },
      { name: "덕트 상태", detail: "덕트 내부 막힘 여부와 연결부 균열 상태를 확인합니다.", x: 31, y: 6, lx: 31, ly: 6, box: [29, 4, 49, 48] },
      { name: "전원·접지·차단기", detail: "전원선 피복 손상 여부와 접지·누전차단기 작동 상태를 확인합니다.", x: 53, y: 6, lx: 53, ly: 6, box: [51, 4, 71, 48] },
      { name: "흡입구·배출구 위치", detail: "흡입구·배출구 위치가 적정한지 확인합니다.", x: 75, y: 6, lx: 75, ly: 6, box: [73, 4, 99, 48] },
      { name: "회전방향·풍량", detail: "회전방향과 풍량이 정상인지 확인합니다.", x: 3, y: 55, lx: 3, ly: 55, box: [1, 53, 27, 96] },
      { name: "소음·진동·과열", detail: "이상소음·진동·과열 여부를 확인합니다.", x: 31, y: 55, lx: 31, ly: 55, box: [29, 53, 49, 96] },
      { name: "방폭구역 적합성", detail: "방폭구역 사용 적합성(방폭 모터·방폭 마크)을 확인합니다.", x: 53, y: 55, lx: 53, ly: 55, box: [51, 53, 71, 96] },
      { name: "예비·정전 대비", detail: "예비 송풍기 확보와 정전 대비 상태를 확인합니다.", x: 75, y: 55, lx: 75, ly: 55, box: [73, 53, 99, 96] },
    ],
  },
  "복합가스측정기": {
    image: "/static/assets/gas-detector-inspection.png",
    alt: "복합가스측정기 점검부위 안내도",
    parts: [
      { name: "검교정 유효기간", detail: "검교정 성적서와 유효기간 준수 여부를 확인합니다.", x: 3, y: 6, lx: 3, ly: 6, box: [1, 4, 27, 48] },
      { name: "배터리 충전상태", detail: "배터리 완전 충전 여부를 확인합니다.", x: 31, y: 6, lx: 31, ly: 6, box: [29, 4, 49, 48] },
      { name: "센서·흡입구 상태", detail: "센서 필터 점검과 흡입구 오염·손상 여부를 확인합니다.", x: 53, y: 6, lx: 53, ly: 6, box: [51, 4, 71, 48] },
      { name: "전원·경보·표시창", detail: "전원, 경보음, 진동, 표시창(LCD) 작동 여부를 확인합니다.", x: 75, y: 6, lx: 75, ly: 6, box: [73, 4, 99, 48] },
      { name: "범프테스트", detail: "사용 전 범프테스트 실시 및 센서 정상 반응 여부를 확인합니다.", x: 3, y: 55, lx: 3, ly: 55, box: [1, 53, 27, 96] },
      { name: "영점 확인", detail: "신선한 공기에서 산소·가연성·유해가스 영점(센서 초기화)을 확인합니다.", x: 31, y: 55, lx: 31, ly: 55, box: [29, 53, 49, 96] },
      { name: "센서 구성 적합성", detail: "측정 대상가스 종류와 센서 사양의 구성 적합 여부를 확인합니다.", x: 53, y: 55, lx: 53, ly: 55, box: [51, 53, 71, 96] },
      { name: "기록·대응 준비", detail: "측정값 기록·저장 상태와 기준초과 시 대응 절차 준비 여부를 확인합니다.", x: 75, y: 55, lx: 75, ly: 55, box: [73, 53, 99, 96] },
    ],
  },
  "이동식 조명": {
    image: "/static/assets/mobile-lighting-inspection.png",
    alt: "이동식 조명 점검부위 안내도",
    parts: [
      { name: "등기구·보호망·유리", detail: "등기구 외관, 보호망 파손, 유리 파손 여부를 확인합니다.", x: 3, y: 6, lx: 3, ly: 6, box: [1, 4, 27, 48] },
      { name: "방수·방진 등급", detail: "방수·방진 등급(IP)과 밀폐공간용 전압 적합성을 확인합니다.", x: 31, y: 6, lx: 31, ly: 6, box: [29, 4, 49, 48] },
      { name: "전원선·플러그", detail: "전원선 피복 손상 여부와 플러그 손상 상태를 확인합니다.", x: 53, y: 6, lx: 53, ly: 6, box: [51, 4, 71, 48] },
      { name: "접지·누전차단기", detail: "접지 상태와 누전차단기(ELB) 작동 여부를 확인합니다.", x: 75, y: 6, lx: 75, ly: 6, box: [73, 4, 99, 48] },
      { name: "연결부 방수·절연", detail: "플러그-콘센트 연결부의 방수·절연 상태를 확인합니다.", x: 3, y: 55, lx: 3, ly: 55, box: [1, 53, 27, 96] },
      { name: "설치 고정·전도 방지", detail: "삼각대 고정장치 상태와 전도 방지 조치 여부를 확인합니다.", x: 31, y: 55, lx: 31, ly: 55, box: [29, 53, 49, 96] },
      { name: "가연물 안전거리", detail: "가연물과의 안전거리(최소 1m) 확보 여부를 확인합니다.", x: 53, y: 55, lx: 53, ly: 55, box: [51, 53, 71, 96] },
      { name: "점등·과열·깜빡임", detail: "점등상태·과열·깜빡임 여부를 확인합니다.", x: 75, y: 55, lx: 75, ly: 55, box: [73, 53, 99, 96] },
    ],
  },
  "개인보호구": {
    image: "/static/assets/ppe-inspection.png",
    alt: "개인보호구(PPE) 점검부위 안내도",
    parts: [
      { name: "보호구 선정", detail: "작업위험에 적합한 보호구가 선정되었는지 확인합니다.", x: 3, y: 6, lx: 3, ly: 6, box: [1, 4, 27, 48] },
      { name: "안전모 상태", detail: "안전모 균열, 턱끈 고정, 내피 손상 여부를 확인합니다.", x: 31, y: 6, lx: 31, ly: 6, box: [29, 4, 49, 48] },
      { name: "보안경·보안면", detail: "렌즈 흠집·파손 여부와 시야 확보 상태를 확인합니다.", x: 53, y: 6, lx: 53, ly: 6, box: [51, 4, 71, 48] },
      { name: "방진·방독마스크", detail: "필터 등급·상태와 교체 주기(유효기간) 준수 여부를 확인합니다.", x: 75, y: 6, lx: 75, ly: 6, box: [73, 4, 99, 48] },
      { name: "안전화 상태", detail: "밑창 마모, 선심 변형·손상, 미끄럼 방지 기능을 확인합니다.", x: 3, y: 55, lx: 3, ly: 55, box: [1, 53, 27, 99] },
      { name: "안전대 상태", detail: "벨트·죔줄 상태와 후크·고리 작동 여부를 확인합니다.", x: 31, y: 55, lx: 31, ly: 55, box: [27, 53, 53, 99] },
      { name: "보호장갑·방수복", detail: "장갑 파손·오염 여부와 방수복 찢김·구멍을 확인합니다.", x: 53, y: 55, lx: 53, ly: 55, box: [51, 53, 77, 99] },
      { name: "지급·보관 상태", detail: "개인별 지급·착용 상태와 청결·위생 보관 상태를 확인합니다.", x: 75, y: 55, lx: 75, ly: 55, box: [72, 53, 100, 99] },
    ],
  },
};
equipmentInspectionMaps["사다리·작업발판"] = equipmentInspectionMaps["비계·작업발판"];

const personnelColumns = [
  { key: "name", label: "성명" },
  { key: "affiliation", label: "소속" },
  { key: "role", label: "직종·역할" },
  { key: "task", label: "담당작업" },
  { key: "enter_time", label: "입장", type: "time" },
  { key: "exit_time", label: "퇴장", type: "time" },
  { key: "education", label: "교육·TBM", type: "checkbox" },
  { key: "ppe", label: "보호구", type: "checkbox" },
  { key: "health", label: "건강상태", type: "select", options: ["이상없음", "이상있음"] },
  { key: "remarks", label: "비고" },
];
const deployedEquipmentColumns = [
  { key: "category", label: "구분" },
  { key: "name", label: "장비·공구명" },
  { key: "spec", label: "규격·관리번호" },
  { key: "qty", label: "수량", type: "number" },
  { key: "operator", label: "운전자·사용자" },
  { key: "inout", label: "반입·반출" },
  { key: "check_state", label: "점검상태", type: "select", options: ["양호", "수리 필요", "사용금지"] },
  { key: "remarks", label: "비고" },
];

const formSchemas = {
  1: [["target_work","평가대상 작업"],["likelihood_rule","가능성 기준","textarea"],["severity_rule","중대성 기준","textarea"],["risk_rule","위험등급·허용기준","textarea"],["reviewer","검토자"],["approver","승인자"]],
  2: [["process","작업공정"],["work_detail","작업내용","textarea"],["hazard","유해·위험요인","textarea"],["current_measure","현재 안전조치","textarea"],["likelihood","가능성","number"],["severity","중대성","number"],["risk_level","위험도"],["additional_measure","추가 감소대책","textarea"],["owner","조치담당자"]],
  3: [["inspection_type","점검구분","select",["작업 전","작업 중","작업 후","정기점검"]],["location","점검장소"],["inspector","점검자"],["result","점검결과","select",["양호","보완 필요","부적합"]],["finding","지적사항","textarea"],["action","조치내용","textarea"],["checker","확인자"]],
  4: [["location","발생장소"],["hazard","위험요인·지적사항","textarea"],["required_action","개선 요구사항","textarea"],["owner","조치담당자"],["due_date","완료예정일","date"],["completion","완료내용","textarea"],["checker","완료확인자"]],
  5: [["education_type","교육구분","select",["신규교육","작업변경교육","특별교육","정기교육","TBM"]],["education_time","교육일시","datetime-local"],["location","교육장소"],["instructor","강사"],["content","교육내용","textarea"],["attendees","참석자 및 서명 확인","textarea"],["result","교육결과","textarea"]],
  6: [["work_name","작업명"],["location","작업장소"],["work_period","작업시간"],["permit_type","위험작업 종류","select",["화기","밀폐공간","고소","중량물","전기·LOTO","기타"]],["safety_measure","필수 안전조치","textarea"],["applicant","신청자"],["reviewer","검토자"],["approver","승인자"]],
  7: [["target_work","대상작업"],["signal_method","신호방법","textarea"],["signal_person","신호수"],["responsible_person","작업책임자"],["client_contact","발주처 연락처"],["emergency_contact","비상연락망","textarea"],["loto_method","LOTO 방법","textarea"]],
  8: [["equipment","설비·장비·공구·보호구명","select",equipmentOptions],["asset_no","관리번호"],["inspection_items","점검항목","checks",[]],["result","종합 점검결과","select",["양호","수리 필요","사용금지"]],["action","부적합 사항 및 조치내용","textarea"],["inspector","점검자"],["checker","확인자"]],
  9: [["drill_type","훈련유형"],["drill_time","훈련일시","datetime-local"],["location","장소"],["scenario","훈련 시나리오","textarea"],["participants","참여자","textarea"],["evaluation","평가결과","textarea"],["improvement","개선사항","textarea"]],
  10: [["name","성명"],["position","직책·직종"],["qualification","보유자격"],["certificate_no","자격번호"],["valid_until","유효기간","date"],["education","교육 이수현황","textarea"],["checker","확인자"]],
  11: [
    ["work_date","작업일자","date"],["shift","근무구분","select",["주간","야간"]],["work_location","작업장소"],
    ["target_facility","대상설비"],["work_responsible","작업책임자"],["planned_time","예정 작업시간"],
    ["personnel","투입인원 현황","table",personnelColumns],
    ["deployed_equipment","투입 장비·공구 현황","table",deployedEquipmentColumns],
    ["input_count","투입인원(명)","number"],["exit_confirm","퇴장확인","select",["전원 퇴장","미확인"]],
    ["remain_count","잔류인원(명)","number"],["personnel_checker","인원확인자"],
    ["equipment_in","반입장비(대)","number"],["equipment_out","반출장비(대)","number"],
    ["equipment_remain","잔류장비(대)","number"],["equipment_checker","장비확인자"],
    ["area_cleanup","작업구역 정리","select",["완료","미완료"]],["residual_material","설비 내부 잔류물","select",["없음","있음"]],
    ["remarks","특이사항·인수인계","textarea"],
  ],
  12: [["equipment","대상설비"],["energy_source","에너지원"],["isolation_point","차단지점"],["lock_no","잠금·태그번호"],["isolator","격리작업자"],["checker","격리확인자"],["result","확인결과","select",["격리 완료","보완 필요","해제 완료"]],["remarks","비고","textarea"]],
  13: [["employee","수령자"],["ppe","보호구명"],["quantity","지급수량","number"],["issue_date","지급일","date"],["inspection_result","점검결과","select",["양호","교체 필요","폐기"]],["receiver_confirm","수령확인"],["manager","관리자"]],
  14: [["proposer","제안자"],["received_date","접수일","date"],["opinion","의견내용","textarea"],["review","검토결과","textarea"],["action","조치내용","textarea"],["completed_date","완료일","date"],["checker","확인자"]],
  15: [["contact_type","구분","select",["현장","발주처","소방서","병원","고용노동부","지자체","기타"]],["contact_name","기관·성명"],["role","역할"],["phone","전화번호","tel"],["location","게시위치"],["remarks","비고","textarea"]],
  16: [["chemical","물질명"],["manufacturer","제조사"],["purpose","사용용도"],["received_date","반입일","date"],["msds_version","MSDS 개정일·버전"],["storage","보관장소"],["hazard","주요 위험성","textarea"],["measure","취급·보호조치","textarea"]],
  17: [["location","작업장소"],["permit_no","허가번호"],["work_time","작업시간"],["watcher","감시인"],["workers","출입 작업자","textarea"],["oxygen","산소농도(%)","number"],["gas_result","유해가스 측정결과"],["entry_log","출입시간 기록","textarea"],["emergency_measure","비상조치","textarea"]],
  18: [["exerciser","행사자"],["stop_time","중지일시","datetime-local"],["location","장소"],["reason","작업중지 사유","textarea"],["action","개선조치","textarea"],["restart_approver","재개 승인자"],["restart_time","재개일시","datetime-local"]],
  19: [["occurred_at","발생일시","datetime-local"],["location","발생장소"],["incident_type","구분","select",["사고","아차사고"]],["summary","발생개요","textarea"],["cause","원인분석","textarea"],["damage","피해현황","textarea"],["immediate_action","즉시조치","textarea"],["prevention","재발방지대책","textarea"],["reporter","보고자"]],
  20: [["inspection_time","점검일시","datetime-local"],["patroller","순찰자"],["area","점검구역"],["hazard","발견 위험요인","textarea"],["required_action","조치요구사항","textarea"],["owner","조치담당자"],["completion","완료여부","select",["미조치","조치 중","완료"]],["remarks","비고","textarea"]],
};

async function api(url, options = {}) {
  const response = await fetch(url, options);
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.error || "요청을 처리하지 못했습니다.");
  return payload;
}

function escapeHtml(value) {
  return String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
}

function toast(message, error = false) {
  const element = $("#toast");
  element.textContent = message;
  element.className = `toast show${error ? " error" : ""}`;
  setTimeout(() => element.classList.remove("show"), 2600);
}

async function refresh() {
  const [dashboard, project, documents] = await Promise.all([api("/api/dashboard"), api("/api/project"), api(`/api/documents?kind=${state.kind}`)]);
  state.project = project;
  state.documents = documents;
  $("#project-title").textContent = "안전보건 문서관리 시스템";
  renderSummary(dashboard);
  renderTable();
}

function renderSummary(data) {
  const cards = [
    ["실행양식", `${data.form_total}개`, `${data.form_done}개 완료`],
    ["평가자료", `${data.evidence_total}개`, "PDF·증빙 관리"],
    ["예상점수", `${data.current_score}/${data.max_score}`, "현재 내부 점검"],
    ["확인 필요", `${data.attention_count}건`, "작성·갱신·보완 포함"],
  ];
  $("#summary").innerHTML = cards.map(([label, value, hint]) => `<article class="summary-card"><span>${label}</span><strong>${value}</strong><small>${hint}</small></article>`).join("");
}

function filteredDocuments() {
  const query = $("#search").value.trim().toLowerCase();
  return query ? state.documents.filter((item) => [item.title, item.category, item.evaluation_item, item.note].join(" ").toLowerCase().includes(query)) : state.documents;
}

function renderTable() {
  if (state.kind === "logs") return renderLogs();
  const header = state.kind === "form"
    ? `<tr><th>No.</th><th>구분·양식명</th><th>평가항목</th><th>작성시점</th><th>서명·확인</th><th>인쇄</th><th>상태</th><th>파일</th><th>비고</th></tr>`
    : `<tr><th>번호</th><th>평가항목</th><th>점수</th><th>상태</th><th>파일</th><th>핵심 보완사항</th></tr>`;
  const rows = filteredDocuments().map((item) => state.kind === "form" ? formRow(item) : evidenceRow(item)).join("");
  $("#table-container").innerHTML = `<table><thead>${header}</thead><tbody>${rows || `<tr><td colspan="9" class="empty">검색 결과가 없습니다.</td></tr>`}</tbody></table>`;
  bindTableEvents();
}

function statusSelect(item) {
  return `<select class="status-select" data-id="${item.id}">${statuses.map((status) => `<option ${status === item.status ? "selected" : ""}>${status}</option>`).join("")}</select>`;
}

function formRow(item) {
  return `<tr><td class="center">${item.sequence}</td><td><span class="category">${escapeHtml(item.category)}</span><button class="form-open" data-open-form="${item.id}">${escapeHtml(item.title)}</button><small>클릭하여 입력·저장</small></td><td>${escapeHtml(item.evaluation_item)}</td><td>${escapeHtml(item.timing)}</td><td>${escapeHtml(item.signature_rule)}</td><td>${escapeHtml(item.print_rule)}</td><td>${statusSelect(item)}</td><td>${fileActions(item)}</td><td><textarea class="note-input" data-id="${item.id}" rows="2">${escapeHtml(item.note)}</textarea></td></tr>`;
}

function evidenceRow(item) {
  return `<tr><td class="center">${item.sequence}</td><td><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.source_file_name)}</small></td><td class="score-cell"><input class="score-input" data-id="${item.id}" type="number" min="0" max="${item.max_score}" value="${item.current_score ?? 0}"><span>/ ${item.max_score}</span></td><td>${statusSelect(item)}</td><td>${fileActions(item)}</td><td><textarea class="note-input" data-id="${item.id}" rows="2">${escapeHtml(item.note)}</textarea></td></tr>`;
}

function fileActions(item) {
  const download = item.stored_path ? `<a class="small-button" href="/api/documents/${item.id}/download">받기</a>` : `<span class="missing">파일 없음</span>`;
  return `<div class="file-actions">${download}<label class="small-button upload">교체<input data-upload="${item.id}" type="file"></label><button class="small-button versions" data-versions="${item.id}">이력</button></div>`;
}

function bindTableEvents() {
  document.querySelectorAll(".status-select").forEach((element) => element.addEventListener("change", () => updateDocument(element.dataset.id, { status: element.value })));
  document.querySelectorAll(".note-input").forEach((element) => element.addEventListener("change", () => updateDocument(element.dataset.id, { note: element.value })));
  document.querySelectorAll(".score-input").forEach((element) => element.addEventListener("change", () => updateDocument(element.dataset.id, { current_score: Number(element.value) })));
  document.querySelectorAll("[data-upload]").forEach((element) => element.addEventListener("change", () => uploadFile(element)));
  document.querySelectorAll("[data-versions]").forEach((element) => element.addEventListener("click", () => showVersions(element.dataset.versions)));
  document.querySelectorAll("[data-open-form]").forEach((element) => element.addEventListener("click", () => openEntryDialog(element.dataset.openForm)));
}

function inputControl([name, label, type = "text", options = []], value = "") {
  const safeValue = escapeHtml(value);
  if (type === "textarea") return `<label class="wide"><span>${label}</span><textarea name="${name}" rows="3">${safeValue}</textarea></label>`;
  if (type === "inspection-map") return inspectionMapControl(name, options, value);
  if (type === "table") return tableControl(name, label, options, Array.isArray(value) ? value : []);
  if (type === "checks") {
    const selected = Array.isArray(value) ? value : String(value || "").split("|").filter(Boolean);
    return `<fieldset class="wide checklist" data-checklist="${name}"><legend>${label}</legend>${options.length ? checklistOptions(name, options, selected) : `<p class="checklist-empty">장비를 선택하면 전용 점검항목이 표시됩니다.</p>`}</fieldset>`;
  }
  if (type === "select") return `<label><span>${label}</span><select name="${name}"><option value="">선택</option>${options.map((option) => `<option ${option === value ? "selected" : ""}>${escapeHtml(option)}</option>`).join("")}</select></label>`;
  return `<label><span>${label}</span><input name="${name}" type="${type}" value="${safeValue}"${type === "number" ? ' step="any"' : ""}></label>`;
}

function inspectionMapControl(name, map, value = {}) {
  const results = value && !Array.isArray(value) && typeof value === "object" ? value : {};
  const partState = (part) => typeof results[part.name] === "object" ? results[part.name] : { result: results[part.name] || "미점검", checked: Boolean(results[part.name] && results[part.name] !== "미점검") };
  const completedCount = map.parts.filter((part) => partState(part).checked).length;
  const leaders = map.parts.map((part, index) => { const saved = partState(part); const cls = saved.checked ? " completed" : ""; return `<line class="part-leader-line${cls}" data-part-index="${index}" x1="${part.lx}" y1="${part.ly}" x2="${part.x}" y2="${part.y}" marker-end="url(#part-arrowhead)"></line>`; }).join("");
  const hotspots = map.parts.map((part, index) => { const saved = partState(part); return `<button type="button" class="part-hotspot ${saved.checked ? "completed" : ""} ${saved.photo ? "has-photo" : ""}" style="left:${part.lx}%;top:${part.ly}%" data-part-index="${index}" aria-label="${escapeHtml(part.name)}">${index + 1}</button>`; }).join("");
  const stores = map.parts.map((part, index) => { const saved = partState(part); return `<span class="part-store" hidden><input type="checkbox" class="part-checked-store" name="${name}__${index}__checked" ${saved.checked ? "checked" : ""}>${["양호","보완","사용금지"].map((result) => `<input type="radio" name="${name}__${index}" value="${result}" ${saved.result === result ? "checked" : ""}>`).join("")}<input type="hidden" class="part-photo-store" name="${name}__${index}__photo" value="${escapeHtml(saved.photo || "")}"></span>`; }).join("");
  const svgOverlay = `<svg class="part-lines" viewBox="0 0 100 100" preserveAspectRatio="none"><defs><marker id="part-arrowhead" markerWidth="5.5" markerHeight="2.2" refX="4.6" refY="1.1" orient="auto" markerUnits="userSpaceOnUse"><path d="M0,0 L5.5,1.1 L0,2.2 L1.3,1.1 Z"></path></marker></defs>${leaders}</svg>`;
  return `<section class="wide inspection-map"><p class="inspection-map-hint">번호를 눌러 부위별 점검결과를 입력하세요. (점검완료 ${completedCount}/${map.parts.length})</p><div class="equipment-visual"><div class="equipment-frame"><img src="${map.image}" alt="${escapeHtml(map.alt || "점검부위 일러스트")}">${svgOverlay}${hotspots}</div></div>${stores}</section>`;
}

function tableCellControl(name, rowIndex, col, row) {
  const fieldName = `${name}__${rowIndex}__${col.key}`;
  const value = row[col.key] ?? "";
  if (col.type === "checkbox") return `<td class="center"><input type="checkbox" name="${fieldName}" ${value ? "checked" : ""}></td>`;
  if (col.type === "select") return `<td><select name="${fieldName}"><option value="">-</option>${col.options.map((option) => `<option ${option === value ? "selected" : ""}>${escapeHtml(option)}</option>`).join("")}</select></td>`;
  return `<td><input name="${fieldName}" type="${col.type || "text"}" value="${escapeHtml(value)}"${col.type === "number" ? ' step="any"' : ""}></td>`;
}

function tableRowHtml(name, columns, row, rowIndex) {
  return `<tr data-row-index="${rowIndex}">${columns.map((col) => tableCellControl(name, rowIndex, col, row)).join("")}<td class="center"><button type="button" class="entry-table-remove" data-table-remove="${name}" aria-label="행 삭제">×</button></td></tr>`;
}

function tableControl(name, label, columns, value = []) {
  const rows = value.length ? value : [{}, {}, {}];
  const rowsHtml = rows.map((row, index) => tableRowHtml(name, columns, row, index)).join("");
  return `<section class="wide entry-table" data-table="${name}" data-next-index="${rows.length}" data-columns='${escapeHtml(JSON.stringify(columns))}'>
    <div class="entry-table-head"><span>${escapeHtml(label)}</span><button type="button" class="small-button" data-table-add="${name}">+ 행 추가</button></div>
    <div class="entry-table-scroll"><table class="entry-table-grid"><thead><tr>${columns.map((col) => `<th>${escapeHtml(col.label)}</th>`).join("")}<th></th></tr></thead><tbody data-table-body="${name}">${rowsHtml}</tbody></table></div>
  </section>`;
}

function checklistOptions(name, options, selected = []) {
  return options.map((option) => `<label><input type="checkbox" name="${name}" value="${escapeHtml(option)}" ${selected.includes(option) ? "checked" : ""}><span>${escapeHtml(option)}</span></label>`).join("");
}

async function openEntryDialog(documentId) {
  try {
    state.entryDocument = state.documents.find((item) => item.id === Number(documentId));
    state.entries = await api(`/api/documents/${documentId}/entries`);
    $("#entry-category").textContent = `${state.entryDocument.category} · ${state.entryDocument.evaluation_item}`;
    $("#entry-heading").textContent = state.entryDocument.title;
    const ledgerExport = $("#entry-ledger-export");
    if (LEDGER_EXPORT_SEQUENCES.includes(state.entryDocument.sequence)) {
      ledgerExport.href = `/api/documents/${state.entryDocument.id}/export-xlsx`;
      ledgerExport.hidden = false;
    } else {
      ledgerExport.hidden = true;
    }
    renderEntryList();
    newEntry();
    $("#entry-dialog").showModal();
  } catch (error) { toast(error.message, true); }
}

function newEntry() {
  state.editingEntryId = null;
  const today = new Date().toISOString().slice(0, 10);
  $("#entry-date").value = today;
  $("#entry-title").value = `${state.entryDocument.title} - ${today}`;
  $("#entry-status").value = "작성 중";
  renderEntryFields({});
}

function renderEntryFields(data) {
  const schema = entrySchema(data);
  $("#entry-fields").innerHTML = schema.map((field) => inputControl(field, data[field[0]] ?? "")).join("");
  if (state.entryDocument.sequence === 8) {
    $("#entry-fields select[name='equipment']").addEventListener("change", (event) => {
      const formData = new FormData($("#entry-form"));
      const currentData = collectSchemaData(schema, formData);
      currentData.equipment = event.target.value;
      currentData.inspection_items = equipmentInspectionMaps[event.target.value] ? {} : [];
      renderEntryFields(currentData);
    });
  }
  document.querySelectorAll(".part-hotspot[data-part-index]").forEach((button) => button.addEventListener("click", () => openPartDialog(Number(button.dataset.partIndex))));
  document.querySelectorAll("[data-table-add]").forEach((button) => button.addEventListener("click", () => addTableRow(button.dataset.tableAdd)));
  document.querySelectorAll("[data-table-remove]").forEach((button) => button.addEventListener("click", () => removeTableRow(button)));
}

function addTableRow(name) {
  const section = document.querySelector(`[data-table="${name}"]`);
  const columns = JSON.parse(section.dataset.columns);
  const tbody = section.querySelector(`[data-table-body="${name}"]`);
  const nextIndex = Number(section.dataset.nextIndex || 0);
  tbody.insertAdjacentHTML("beforeend", tableRowHtml(name, columns, {}, nextIndex));
  section.dataset.nextIndex = nextIndex + 1;
  tbody.lastElementChild.querySelector("[data-table-remove]").addEventListener("click", (event) => removeTableRow(event.currentTarget));
}

function removeTableRow(button) {
  const tbody = button.closest("tbody");
  if (tbody.children.length <= 1) { toast("최소 1개 행은 남아 있어야 합니다.", true); return; }
  button.closest("tr").remove();
}

const ZOOM_FACTOR = 2.2;

function centerZoomImage() {
  if (!state.activePart) return;
  const { part } = state.activePart;
  const container = $("#part-zoom");
  const zoomImage = $("#part-image");
  if (!container.clientWidth || !zoomImage.naturalWidth) return;
  if (part.box) {
    // Card mode: fit the whole card/panel region inside the viewport (no cropping of the card).
    const [bx0, by0, bx1, by1] = part.box;
    const boxWidthFrac = (bx1 - bx0) / 100;
    const boxHeightFrac = (by1 - by0) / 100;
    const boxWidthPx = zoomImage.naturalWidth * boxWidthFrac;
    const boxHeightPx = zoomImage.naturalHeight * boxHeightFrac;
    const CARD_MARGIN = 0.92; // shrink slightly so the full card sits inside the view with a small safety margin
    const scale = Math.min(container.clientWidth / boxWidthPx, container.clientHeight / boxHeightPx) * CARD_MARGIN;
    const displayWidth = zoomImage.naturalWidth * scale;
    const displayHeight = zoomImage.naturalHeight * scale;
    const centerXFrac = (bx0 + bx1) / 2 / 100;
    const centerYFrac = (by0 + by1) / 2 / 100;
    zoomImage.style.width = `${displayWidth}px`;
    zoomImage.style.height = `${displayHeight}px`;
    zoomImage.style.left = `${container.clientWidth / 2 - centerXFrac * displayWidth}px`;
    zoomImage.style.top = `${container.clientHeight / 2 - centerYFrac * displayHeight}px`;
    return;
  }
  const displayWidth = container.clientWidth * ZOOM_FACTOR;
  const displayHeight = displayWidth * (zoomImage.naturalHeight / zoomImage.naturalWidth);
  zoomImage.style.width = `${displayWidth}px`;
  zoomImage.style.height = `${displayHeight}px`;
  zoomImage.style.left = `${container.clientWidth / 2 - (part.x / 100) * displayWidth}px`;
  zoomImage.style.top = `${container.clientHeight / 2 - (part.y / 100) * displayHeight}px`;
}

window.addEventListener("resize", centerZoomImage);

function openPartDialog(index) {
  const equipment = $("#entry-fields select[name='equipment']").value;
  const map = equipmentInspectionMaps[equipment];
  if (!map) return;
  const part = map.parts[index];
  state.activePart = { index, part, map };
  $("#part-title").textContent = `${index + 1}. ${part.name}`;
  $("#part-detail").textContent = part.detail;
  $("#part-zoom-number").textContent = index + 1;
  const zoomImage = $("#part-image");
  zoomImage.onload = centerZoomImage;
  zoomImage.src = map.image;
  $("#part-dialog").showModal();
  centerZoomImage();
  const storedCheck = $(`#entry-fields input[name='inspection_items__${index}__checked']`);
  $("#part-checked").checked = storedCheck.checked;
  const selected = $(`#entry-fields input[name='inspection_items__${index}']:checked`);
  document.querySelectorAll("#part-form input[name='part_result']").forEach((radio) => { radio.checked = selected?.value === radio.value; });
  refreshPartPhotoPreview(index);
}

function refreshPartPhotoPreview(index) {
  const photoInput = $(`#entry-fields input[name='inspection_items__${index}__photo']`);
  const photoValue = photoInput ? photoInput.value : "";
  const thumb = $("#part-photo-thumb");
  if (photoValue) {
    thumb.src = photoValue;
    thumb.hidden = false;
    $("#part-photo-open").textContent = "사진 변경";
  } else {
    thumb.hidden = true;
    thumb.removeAttribute("src");
    $("#part-photo-open").textContent = "사진 추가";
  }
}

function applyPartResult(index, result, checked) {
  const targetRadio = $(`#entry-fields input[name='inspection_items__${index}'][value='${result}']`);
  const targetCheck = $(`#entry-fields input[name='inspection_items__${index}__checked']`);
  if (!targetRadio || !targetCheck) return;
  targetRadio.checked = true;
  targetCheck.checked = checked;
  document.querySelectorAll(`[data-part-index='${index}']`).forEach((element) => element.classList.toggle("completed", checked));
  const completedCount = document.querySelectorAll("#entry-fields .part-checked-store:checked").length;
  const hint = $(".inspection-map-hint");
  if (hint) hint.textContent = hint.textContent.replace(/\(점검완료 \d+\/(\d+)\)/, (match, total) => `(점검완료 ${completedCount}/${total})`);
}

let pendingPhotoIndex = null;

function resizeImageToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const img = new Image();
      img.onload = () => {
        const maxWidth = 1280;
        const scale = Math.min(1, maxWidth / img.width);
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
        resolve(canvas.toDataURL("image/jpeg", 0.75));
      };
      img.onerror = reject;
      img.src = reader.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function openPhotoDialog(index) {
  pendingPhotoIndex = index;
  const part = state.activePart.map.parts[index];
  $("#photo-dialog-part").textContent = `${index + 1}. ${part.name}`;
  $("#photo-file-input").value = "";
  $("#photo-preview").hidden = true;
  $("#photo-preview-img").removeAttribute("src");
  const saveButton = $("#photo-save");
  saveButton.disabled = true;
  delete saveButton.dataset.photo;
  $("#part-photo-dialog").showModal();
}

function closePhotoDialog(alsoClosePartDialog) {
  $("#part-photo-dialog").close();
  if (alsoClosePartDialog) $("#part-dialog").close();
}

$("#photo-file-input").addEventListener("change", async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  try {
    const dataUrl = await resizeImageToDataUrl(file);
    $("#photo-preview-img").src = dataUrl;
    $("#photo-preview").hidden = false;
    const saveButton = $("#photo-save");
    saveButton.disabled = false;
    saveButton.dataset.photo = dataUrl;
  } catch (error) { toast("사진을 불러오지 못했습니다.", true); }
});

$("#photo-save").addEventListener("click", () => {
  const photoInput = $(`#entry-fields input[name='inspection_items__${pendingPhotoIndex}__photo']`);
  if (photoInput) photoInput.value = $("#photo-save").dataset.photo || "";
  document.querySelectorAll(`[data-part-index='${pendingPhotoIndex}']`).forEach((element) => element.classList.add("has-photo"));
  refreshPartPhotoPreview(pendingPhotoIndex);
  closePhotoDialog(true);
});

$("#photo-skip").addEventListener("click", () => closePhotoDialog(true));
document.querySelectorAll("[data-photo-close]").forEach((button) => button.addEventListener("click", () => closePhotoDialog(false)));
$("#part-photo-open").addEventListener("click", () => { if (state.activePart) openPhotoDialog(state.activePart.index); });

function entrySchema(data = {}) {
  const schema = formSchemas[state.entryDocument.sequence] || [["content","입력내용","textarea"]];
  if (state.entryDocument.sequence !== 8) return schema;
  return schema.map((field) => {
    if (field[0] !== "inspection_items") return field;
    const map = equipmentInspectionMaps[data.equipment];
    return map ? [field[0], field[1], "inspection-map", map] : [field[0], field[1], "checks", equipmentInspectionItems[data.equipment] || []];
  });
}

function collectSchemaData(schema, formData) {
  return Object.fromEntries(schema.map(([name, , type, options]) => {
    if (type === "checks") return [name, formData.getAll(name)];
    if (type === "inspection-map") return [name, Object.fromEntries(options.parts.map((part, index) => [part.name, { result: formData.get(`${name}__${index}`) || "미점검", checked: formData.has(`${name}__${index}__checked`), photo: formData.get(`${name}__${index}__photo`) || "" }]))];
    if (type === "table") {
      const rows = {};
      for (const key of formData.keys()) {
        if (!key.startsWith(`${name}__`)) continue;
        const [, rowIndex, colKey] = key.split("__");
        rows[rowIndex] = rows[rowIndex] || {};
        const col = options.find((c) => c.key === colKey);
        rows[rowIndex][colKey] = col?.type === "checkbox" ? formData.has(key) : formData.get(key) || "";
      }
      const hasValue = (row) => Object.values(row).some((v) => v !== "" && v !== false);
      return [name, Object.values(rows).filter(hasValue)];
    }
    return [name, formData.get(name) || ""];
  }));
}

const EXCEL_EXPORT_SEQUENCES = [3, 6, 7, 8, 9, 11, 12, 17, 18, 19, 20];
const LEDGER_EXPORT_SEQUENCES = [4, 10, 13, 14, 16];

function renderEntryList() {
  const canExport = EXCEL_EXPORT_SEQUENCES.includes(state.entryDocument.sequence);
  $("#entry-list").innerHTML = state.entries.length ? state.entries.map((entry) => `<article class="entry-item ${entry.id === state.editingEntryId ? "active" : ""}"><button type="button" data-edit-entry="${entry.id}"><strong>${escapeHtml(entry.record_title)}</strong><span>${escapeHtml(entry.record_date)} · ${escapeHtml(entry.status)}</span></button>${canExport ? `<a class="entry-export" href="/api/entries/${entry.id}/export-xlsx" aria-label="엑셀로 내보내기" title="엑셀로 내보내기">⇩xlsx</a>` : ""}<button type="button" class="entry-delete" data-delete-entry="${entry.id}" aria-label="삭제">×</button></article>`).join("") : `<p class="history-empty">아직 저장된 기록이 없습니다.</p>`;
  document.querySelectorAll("[data-edit-entry]").forEach((button) => button.addEventListener("click", () => editEntry(Number(button.dataset.editEntry))));
  document.querySelectorAll("[data-delete-entry]").forEach((button) => button.addEventListener("click", () => deleteEntry(Number(button.dataset.deleteEntry))));
}

function editEntry(entryId) {
  const entry = state.entries.find((item) => item.id === entryId);
  if (!entry) return;
  state.editingEntryId = entryId;
  $("#entry-date").value = entry.record_date;
  $("#entry-title").value = entry.record_title;
  $("#entry-status").value = entry.status;
  renderEntryFields(entry.data);
  renderEntryList();
}

async function deleteEntry(entryId) {
  if (!confirm("이 입력기록을 삭제할까요?")) return;
  try {
    await api(`/api/entries/${entryId}`, { method: "DELETE" });
    state.entries = await api(`/api/documents/${state.entryDocument.id}/entries`);
    newEntry();
    renderEntryList();
    toast("입력기록을 삭제했습니다.");
  } catch (error) { toast(error.message, true); }
}

async function updateDocument(id, values) {
  try {
    await api(`/api/documents/${id}`, { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(values) });
    toast("저장했습니다.");
    await refresh();
  } catch (error) { toast(error.message, true); }
}

async function uploadFile(input) {
  const file = input.files[0];
  if (!file) return;
  try {
    await api(`/api/documents/${input.dataset.upload}/upload?filename=${encodeURIComponent(file.name)}`, { method: "POST", headers: { "Content-Type": "application/octet-stream" }, body: file });
    toast("새 버전으로 저장했습니다.");
    await refresh();
  } catch (error) { toast(error.message, true); }
}

async function showVersions(id) {
  try {
    const versions = await api(`/api/documents/${id}/versions`);
    alert(versions.map((version) => `v${version.version_number} · ${version.file_name} · ${version.uploaded_at}`).join("\n") || "저장된 이력이 없습니다.");
  } catch (error) { toast(error.message, true); }
}

async function renderLogs() {
  try {
    state.logs = await api("/api/logs");
    const rows = state.logs.map((log) => `<tr><td>${escapeHtml(log.created_at)}</td><td>${escapeHtml(log.document_title || "공통정보")}</td><td>${escapeHtml(log.action)}</td><td>${escapeHtml(log.details)}</td></tr>`).join("");
    $("#table-container").innerHTML = `<table><thead><tr><th>일시</th><th>대상</th><th>작업</th><th>내용</th></tr></thead><tbody>${rows || `<tr><td colspan="4" class="empty">변경기록이 없습니다.</td></tr>`}</tbody></table>`;
  } catch (error) { toast(error.message, true); }
}

const projectFields = [["company_name", "회사명"], ["project_name", "사업명"], ["project_stage", "현재 단계"], ["representative", "대표이사"], ["safety_manager", "안전보건업무 담당자"], ["site_manager", "현장책임자"], ["work_manager", "작업책임자·작업반장"], ["client_contact", "발주처 담당자"], ["site_address", "현장주소"], ["work_period", "작업기간"]];

function openProjectDialog() {
  $("#project-fields").innerHTML = projectFields.map(([name, label]) => `<label><span>${label}</span><input name="${name}" value="${escapeHtml(state.project[name] || "")}"></label>`).join("");
  $("#project-dialog").showModal();
}

$("#project-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  try {
    await api("/api/project", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) });
    $("#project-dialog").close();
    toast("공통정보를 저장했습니다.");
    await refresh();
  } catch (error) { toast(error.message, true); }
});

$("#entry-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(event.currentTarget);
  const schema = entrySchema({ equipment: formData.get("equipment") });
  const data = collectSchemaData(schema, formData);
  const payload = {
    record_date: formData.get("record_date"),
    record_title: formData.get("record_title"),
    status: formData.get("status"),
    data,
  };
  try {
    const url = state.editingEntryId ? `/api/entries/${state.editingEntryId}` : `/api/documents/${state.entryDocument.id}/entries`;
    const method = state.editingEntryId ? "PUT" : "POST";
    const saved = await api(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    state.editingEntryId = saved.id;
    state.entries = await api(`/api/documents/${state.entryDocument.id}/entries`);
    renderEntryList();
    toast("입력내용을 DB에 저장했습니다.");
    await refresh();
  } catch (error) { toast(error.message, true); }
});

$("#part-form").addEventListener("submit", (event) => {
  event.preventDefault();
  if (!state.activePart) return;
  const { index } = state.activePart;
  const result = new FormData(event.currentTarget).get("part_result");
  if (!result) { toast("점검 판정을 선택해주세요.", true); return; }
  applyPartResult(index, result, $("#part-checked").checked);
  $("#part-dialog").close();
});

document.querySelectorAll(".tab").forEach((tab) => tab.addEventListener("click", async () => {
  document.querySelectorAll(".tab").forEach((item) => item.classList.remove("active"));
  tab.classList.add("active");
  state.kind = tab.dataset.kind;
  $("#search").value = "";
  if (state.kind === "logs") await renderLogs(); else await refresh();
}));
$("#search").addEventListener("input", renderTable);
$("#project-button").addEventListener("click", openProjectDialog);
document.querySelectorAll("[data-close]").forEach((button) => button.addEventListener("click", () => $("#project-dialog").close()));
$("#new-entry").addEventListener("click", newEntry);
document.querySelectorAll("[data-entry-close]").forEach((button) => button.addEventListener("click", () => $("#entry-dialog").close()));
document.querySelectorAll("[data-part-close]").forEach((button) => button.addEventListener("click", () => $("#part-dialog").close()));
document.querySelectorAll("#part-form input[name='part_result']").forEach((radio) => radio.addEventListener("change", () => {
  $("#part-checked").checked = true;
  if (!state.activePart) return;
  applyPartResult(state.activePart.index, radio.value, true);
  openPhotoDialog(state.activePart.index);
}));
refresh().catch((error) => toast(error.message, true));
