# 착공 준비 1차 구현 보고

작성일: 2026-09-16. Python + SQLite + HTML + JavaScript 유지.

## 1. 새로 생성한 파일

- `preparation/__init__.py`: 모듈 정의
- `preparation/service.py`: 마이그레이션·회차·답변·조건·쟁점·첨부 처리
- `preparation/routes.py`: 착공 준비 전용 HTTP API
- `preparation/migrations/001_initial.sql`: 테이블·제약·인덱스
- `preparation/migrations/001_demo_questions.sql`: 검증용 질문 8개·선택지·조건 1개
- `static/preparation.js`: 단계 화면·문답 카드·회차 재개·쟁점 목록·첨부
- `static/preparation.css`: 신규 화면에 한정된 스타일·모바일 대응
- `tests/test_preparation.py`: 신규 단위·HTTP 통합 테스트 7개
- `tests/verify_preparation_browser.py`: 임시 DB·파일 복사본에서 브라우저 및 기존 기능 검증
- `tests/artifacts/preparation-390.png`, `preparation-1280.png`: 화면 검증 이미지
- `tests/artifacts/migration-verification.json`: 실제 DB 마이그레이션 보존 대조 결과
- `PREPARATION_V1.md`: 이 보고서
- `data/backups/preparation_v1_20260916_121347_cd74e5fb.db`: 실제 DB 마이그레이션 전 백업

첨부 파일 폴더 `storage/preparation/`는 최초 첨부 시 생성합니다.

## 2. 수정한 기존 파일

- `app.py`: 신규 API dispatcher 연결과 시작 시 마이그레이션 호출
- `static/index.html`: 상위 안전관리/착공 준비 메뉴, 신규 화면 영역·리소스 연결
- `README.md`: 사용·백업·마이그레이션 안내
- `data/risk_management.db`: 신규 테이블·인덱스·질문 데이터만 추가

기존 `db.py`, `static/app.js`, `static/styles.css`, 실행 배치 파일, 안전관리 서식은 수정하지 않았습니다.

## 3. 새로 생성한 DB 테이블

| 테이블 | 역할 |
|---|---|
| prep_questions | 질문 코드·단계·공종·안내·입력형식·필수·순서·활성·버전 |
| prep_question_options | 선택형 질문의 값·표시명·순서 |
| prep_question_rules | 후속 질문·선행 질문·허용 비교 연산자·JSON 비교값 |
| prep_sessions | 발주처 미팅 회차·제목·진행상태·현재 질문 |
| prep_answers | 질문별 답변·확인상태·메모·질문 스냅샷·수정 버전 |
| prep_issues | 답변별 1개 쟁점·미확인 상태·해결시각 |
| prep_attachments | 답변 연결·파일 경로·원본명·MIME·크기·설명·등록일 |

공종 코드: COMMON / BOILER / ECONOMIZER / SDR / BF / SCR.
답변 형식: text / textarea / number / date / datetime / yes_no / single_choice / multi_choice.
규칙: eq / ne / contains / in / gt / gte / lt / lte. 같은 질문의 여러 규칙은 AND로 평가합니다.
임의 코드 평가(eval)는 사용하지 않습니다. 선행 질문이 비활성이면 후속 질문도 비활성입니다.
조건 순환은 오류 처리합니다. 빈 답변은 조건을 만족하지 않습니다.

답변은 회차·질문 조합이 유일하며, revision으로 오래된 화면의 덮어쓰기를 차단합니다.
쟁점은 answer_id가 유일하여 재저장해도 중복되지 않습니다. 확인완료 시 해결 처리하고
다시 미확인으로 바꾸면 같은 쟁점을 재개합니다. 조건에서 제외된 답변·쟁점·첨부는 삭제하지 않습니다.
조건 제외 쟁점은 현재 확인 대상에서 구분해 표시합니다.

## 4. API 목록

기본 경로: `/api/preparation`

| 메서드 | 경로 | 기능 |
|---|---|---|
| GET | /questions | 질문·선택지·규칙 조회 |
| GET | /sessions | 저장된 미팅 목록 |
| POST | /sessions | 미팅 생성: title |
| GET | /sessions/{id} | 질문·답변·첨부·현재 위치·적용 질문 조회 |
| PUT | /sessions/{id} | current_question_id, status 변경 |
| PUT | /sessions/{id}/answers/{question_id} | value, status, note, revision 저장 |
| GET | /issues | 쟁점과 현재 적용 여부 조회 |
| POST | /answers/{answer_id}/attachments?filename=...&description=... | 파일 바이너리 업로드 |
| GET | /attachments/{id}/download | 첨부파일 다운로드 |

JSON 요청은 Content-Type: application/json. 첨부는 application/octet-stream.
답변 최초 revision은 0, 수정 시 마지막 조회 revision을 전송합니다.
확인상태: 확인완료 / 미확인 / 발주처 재확인 / 현장확인 필요 / 내부검토 필요 / 계획회의 필요 / 계획반영 완료.
회차 상태: in_progress / completed. 수정하면 작성 중으로 돌아갑니다.
400: 입력 오류, 404: 대상 없음, 409: 수정 충돌. 교차 사이트 브라우저 저장 요청은 403.

## 5. 화면 사용방법

1. 기존 방식으로 서버를 시작하고 브라우저에서 접속합니다. 실행 중이던 서버는 재시작합니다.
2. 상위 **착공 준비** → **발주처 미팅**에서 제목을 입력하고 **발주처 미팅 시작**을 누릅니다.
3. 질문 카드의 답변·확인상태·메모를 입력한 뒤 **현재 답변 저장** 또는 **저장 후 다음**을 누릅니다.
4. 미정인 필수 질문은 답변 없이 **미확인** 등 후속 확인 상태로 저장할 수 있습니다.
5. 동시작업 질문에 **예**를 저장하면 상세 질문이 나타납니다. **아니오**로 바꾸면 상세 답변을 보존하고 숨깁니다.
6. 첨부 파일 또는 카메라 사진과 설명을 선택한 뒤 **선택한 파일 첨부**를 누릅니다. 현재 답변도 저장합니다.
7. 브라우저를 닫았다 다시 접속하면 저장된 미팅의 **이어서 작성**으로 서버에 저장된 답변을 불러옵니다.
8. **진행 단계**를 펼쳐 **미확인·쟁점사항**에서 연결 목록을 확인하고 해당 미팅으로 이동합니다.
9. 마지막 **저장 후 문답 마침**은 문답 저장 완료입니다. 안전 확인 완료·착공 승인이 아닙니다.

## 6. 테스트 결과

- 기존 5개 + 신규 7개 자동 테스트: 총 12개 통과.
- 실제 DB: 기존 5개 테이블의 모든 행을 변경 전후 비교하여 동일함 확인.
- 기존 데이터: projects 1, documents 32, document_versions 32, form_entries 3, activity_logs 82 유지.
- 기존 storage 파일 32개: SHA-256 대조 결과 동일.
- 마이그레이션 전 백업의 기존 행이 원본과 동일하며 재실행 시 변경 없음.
- 실제 DB integrity_check=ok, foreign_key_check 오류 없음.
- 신규 실제 데이터: 질문 8개, 선택지 2개, 규칙 1개. 미팅·답변·쟁점·첨부는 0개로 시작.
- 임시 복사본 Chromium 테스트: 미팅 생성, 답변 저장·수정, 새 브라우저 컨텍스트에서 이어쓰기,
  조건부 표시·숨김, 미확인 쟁점, 사진 바이너리 업로드·다운로드, 문답 완료 통과.
- 320 / 390 / 768 / 1280px에서 가로 넘침 없음. 입력란 터치 높이 확인. JavaScript 오류 없음.
- 기존 화면: 실행양식 20개·평가자료 12개 조회 및 화면 복귀 정상.
- 기존 지원 엑셀 출력 16종: 생성 후 openpyxl로 재열기 성공.
  대상: 3,4,6,7,8,9,10,11,12,13,14,16,17,18,19,20.
- 기존 파일 교체·버전 증가·다운로드 경로 조회: 임시 복사본에서 통과.

브라우저 테스트는 운영 DB에 검증용 회차나 답변을 남기지 않습니다.
모바일은 Chromium 에뮬레이션 검사입니다. 실제 휴대폰 카메라 권한·iOS Safari·현장 통신 환경은 별도 확인이 필요합니다.
엑셀 검증은 파일 생성·재열기 검사이며 모든 인쇄 배치의 시각 검증은 아닙니다.

실행 명령:

```powershell
python -B -m unittest discover -s boiler_cleaning/tests -v
python -B boiler_cleaning/tests/verify_preparation_browser.py
```

두 번째 명령은 Playwright Chromium 및 Pillow가 설치된 개발 환경에서 실행합니다.
운영 서버에는 추가 브라우저 테스트 의존성이 필요하지 않습니다.

## 7. 기존 기능에 미친 영향

기존 데이터·파일·업무 로직은 유지하며, 기존 API보다 먼저 신규 prefix만 분기합니다.
기존 activity_logs는 변경하지 않고 신규 답변은 별도 테이블에서 관리합니다.
신규 CSS는 착공 준비 영역에 한정하고 모바일 헤더 조정도 착공 준비 표시 중에만 적용합니다.
신규 파일은 UUID로 저장하며 원본 파일명으로 저장 경로를 만들지 않습니다.
경로 문자 차단, 다운로드 경로 범위 검사, 확장자·파일 시그니처 검사, 용량 제한을 적용합니다.
첨부 쓰기 후 DB 저장이 실패하면 해당 신규 파일을 정리합니다.

## 8. 아직 구현하지 않은 기능

- 현장조사·계획회의·실행계획·예산·공정·인력·장비·안전계획 자동연결
- 착공 승인과 필수 준비항목 완료 판정
- 질문 마스터 관리자 편집 화면, 기존 미팅별 질문 세트 버전 고정
- 로그인·권한·전자서명·작성자별 감사 이력(기존 시스템에도 없음)
- 자동저장·오프라인 저장·사진 자동 압축·HEIC 첨부
- 첨부 삭제·교체, 미팅 삭제, 신규 미팅 엑셀 출력
- OR 조건 그룹 및 전문 규칙 편집 UI

입력 중 문답 내용은 저장 버튼으로 보관합니다. 기존의 사내망 사용 전제도 유지합니다.
현재 질문 스냅샷은 최초 답변의 근거 보존용이며, 기존 회차의 화면 전체를 과거 질문 버전에 고정하는 기능은 아닙니다.
정식 질문으로 교체하기 전에 회차별 질문 버전 정책을 먼저 확정해야 합니다.

## 9. 다음 구현 단계 제안

실제 과업지시서·원가내역·발주처 확인사항을 검토하여 정식 질문을 확정한 뒤,
질문 관리 화면과 회차별 질문 버전 고정을 우선 추가합니다.
그 다음 5개 공종별 현장조사로 확대하고, 결정사항·실행계획·기존 안전관리 연결은 별도 단계로 진행합니다.
