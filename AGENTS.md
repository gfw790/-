# AGENTS.md — (주)현대기전 통합 CSM 시스템 협업 및 개발 규격

이 문서는 **Antigravity (메인 에이전트)**와 **Codex (서브·병렬·검수 에이전트)**가 같은 저장소(`A:\risk_server\project`)에서 충돌 없이 일관된 품질로 협업하기 위한 공통 기준입니다.

---

## 1. 멀티 에이전트 역할 분담 및 충돌 방지 규칙

### 역할 분담
- **메인 (Antigravity)**
  - 전체 아키텍처, 공통 핵심 파일 관리, 최종 통합·점수 연동·`A:\CSM\dev\` 미러링·`origin main` 푸시 담당
  - Codex가 작성한 신규 모듈의 규격 검수 및 포털 연결
- **서브 (Codex)**
  - 독립된 신규 모듈 초안 제작 (예: `2.5`, `2.7`, `2.8`, `2.9`, `6.2` 등)
  - 기존 코드 보안·성능·SQLite 쿼리·엣지케이스 크로스 체크(코드 리뷰) 및 데이터 파싱 스크립트 작성

### 충돌 방지 원칙 (필수)
1. **공통 핵심 파일은 메인(Antigravity)만 수정**합니다. Codex는 아래 파일을 직접 수정하지 말고, 필요한 연동 코드/스니펫만 제안서로 남겨 메인에 핸드오프합니다:
   - `CSM/scoring.php`
   - `CSM/area.php`
   - `CSM/module_routes.php`
   - `CSM/radial_portal.php`
   - `CSM/evaluation_catalog.json`
2. **신규 모듈은 독립된 새 파일 세트로 작성**합니다 (`<module>_store.php`, `<module>.php`, `<module>_print.php`).
3. **동시 수정 금지**: 같은 파일을 두 에이전트가 동시에 편집하지 않습니다.
4. **최종 배포 및 미러링**: 모든 CSM 수정/신규 파일은 최종 검증 후 `A:\risk_server\project\CSM\` → `A:\CSM\dev\` 미러링 후 `origin main`에 커밋·푸시합니다.

---

## 2. 회사 정보, 용어 및 실행 환경

- **공식 사명**: `주식회사 현대기전` (약칭 `(주)현대기전`)
- **로고 경로**: `CSM/assets/policy-logo.png` (대체: `safety_manual/assets/policy-logo.png`)
- **기본 담당자**: 안전관리책임자 `김남균` / 대표이사 `정연탁` / 담당부서 `공사팀`
- **금지어**: **`모회사`** 표현 사용 금지 → 필요 시 `원청` 또는 `한라시멘트`로 표기
- **실행 환경**: Windows + PowerShell, PHP 8.x, SQLite (WAL 모드)
  - 프로덕션 경로: `A:\risk_server\project\CSM\`
  - 개발 미러 경로: `A:\CSM\dev\`
  - 참고 원본 자료: `D:\OneDrive\CSM(한라)\` (`2024`, `2025-CSM`, `2026-CSM`)

---

## 3. CSM 신규 모듈 구현 표준

### 파일 구조 (3-File Pattern)
1. `CSM/<module>_store.php`
   - SQLite DB: `CSM/storage/<module>.sqlite` (`PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;`)
   - 증빙 저장소: `CSM/storage/<module>_evidence/`, 사진 저장소: `CSM/storage/<module>_photos/`
   - 점수 집계 함수: `csm_<prefix>_score_summary(int $year, ...): array`
     - **반드시 Read-Only PDO** (`sqlite:file:...?mode=ro`)로 열며, 테이블 존재 여부를 확인해 미생성 상태에서도 안전하게 빈 배열 반환
     - 반환 키: `earned`, `evidence_count`, `state` (`no_evidence` | `pending` | `assessed` | `na`), `multiplier`, `revision`, `automatic`, `assessment`
2. `CSM/<module>.php`
   - 인증·권한 필수:
     ```php
     require_once __DIR__ . '/auth.php';
     $user = csm_require_auth();
     $canManage = auth_can_manage($user);
     ```
   - 모든 `POST` 요청에 세션 기반 CSRF 토큰 검증 적용
   - 증빙/사진 업로드 보안:
     - `finfo(FILEINFO_MIME_TYPE)` 검증, 이미지는 `getimagesize()`, Office 문서(`.xlsx`/`.docx`)는 `ZipArchive` 구조 검증
     - **클립보드 스크린샷(`Win+Shift+S` → `Ctrl+V`) 지원**: `DataTransfer` 대신 `FileReader` → Base64 hidden input + 백엔드 `base64_decode` + `getimagesizefromstring()` 방식 사용
     - 현장 사진 입력란은 모바일 카메라 촬영(`accept="image/*" capture="environment"`) 함께 지원
3. `CSM/<module>_print.php`
   - 아래 **4. 공식 A4 인쇄 규격**을 엄격히 준수

---

## 4. 공식 A4 인쇄 레이아웃 규격 (Print Layout Conventions)

신규 또는 수정되는 모든 인쇄 화면(`*_print.php`)은 아래 규격을 따릅니다:

- **여백 제어**: 브라우저 인쇄 대화상자에서 **여백 없음(None)** 사용을 전제로 `@page { size: A4 portrait; margin: 0; }` (가로 양식은 `landscape`) 설정. 모든 인쇄 여백은 `@page`가 아닌 `.sheet` 컨테이너의 CSS `padding`으로 제어합니다.
- **기본 편철 여백**: 상단 최소 **20 mm** (`padding: 20mm 12mm 12mm 12mm;`), 비인쇄 `.toolbar`에 3가지 여백 프리셋 제공:
  - `상단 편철` (기본 20 / 12 mm)
  - `좌측 편철` (좌 20 mm)
  - `여유 편철` (18 / 16 mm)
- **결재란 규격 (CSS Grid 금지)**:
  - Chrome/Edge 인쇄 미리보기에서 CSS Grid 테두리가 누락되는 문제가 있으므로, 반드시 `.approval-wrap` (`display: flex; border: 1pt solid #000;`) + `.ap-side` (`결<br>재`) + rowspan 없는 2×2 `<table class="ap-tbl">` (`작성 | 승인`, 각 `width: 50%`, `1pt solid #000`, 서명칸 공란 `&nbsp;`)로 작성합니다.
  - 상단 `.toolbar`에 **`결재란 ON / OFF`** 토글 버튼을 제공하며, 여백 프리셋 변경 시에도 토글 상태가 유지되어야 합니다.
- **문서 외곽 및 하단 회사 표기**:
  - 본문 영역은 명확한 표/테두리로 정리하고, 하단 푸터에 회사 로고(`CSM/assets/policy-logo.png`, `height: 6mm`)와 **`주식회사 현대기전`**(`10pt; font-weight: 800; letter-spacing: 2px`)을 나란히 배치합니다.
- **비인쇄 요소 숨김**: `@media print`에서 `.toolbar`와 버튼·네비게이션을 반드시 숨기고, `-webkit-print-color-adjust: exact; print-color-adjust: exact;`를 적용합니다.

---

## 5. 작업 완료 전 검증 체크리스트

1. 수정/생성한 모든 PHP 파일에 `php -l <file>` 문법 오류 없음 확인
2. CLI 스모크 테스트로 SQLite 테이블 생성, 초기 시드, `csm_<prefix>_score_summary()` read-only 조회 확인
3. `scoring.php` 연동 시 `csm_score_summary(csm_scoring_db(), $catalog, 2026)` 실행 오류 없음 확인
4. (메인 담당) `A:\CSM\dev\` 미러링 및 `git commit` / `git push origin main` 완료
