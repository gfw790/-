# TBM 일지 시스템 — 개선 변경 내역

## 변경 파일 요약

| 파일 | 추가 | 삭제 | 비고 |
|------|------|------|------|
| `tbm_db.php` | +34줄 | -7줄 | DB 설정 .env 이전 |
| `tbm_ai.php` | +155줄 | -89줄 | 핵심 엔진 개선 |
| `ajax_ai_generate.php` | +17줄 | -4줄 | 에러 응답 보안 강화 |
| `generate.php` | +8줄 | -5줄 | 코드 정리 |
| `index.php` | +6줄 | -3줄 | 함수 통일, UI 텍스트 |
| `.env.example` | 신규 | — | 설정 가이드 |

---

## 상세 변경 내역

### 1. 보안 개선

#### 1-1. DB 인증 정보 환경변수 이전 (`tbm_db.php`)
- **이전:** `root` / 빈 비밀번호가 소스코드에 하드코딩
- **이후:** `.env` 파일에서 로드, 없으면 기존 기본값 유지 (하위 호환)
- `.env.example` 신규 생성 — 설정 항목 가이드 제공

#### 1-2. 에러 응답 보안 강화 (`ajax_ai_generate.php`)
- **이전:** stack trace가 항상 JSON 응답에 포함
- **이후:** `APP_ENV=development` 또는 `APP_DEBUG=1`일 때만 trace 포함
- 운영 환경에서는 에러 메시지만 반환, 상세는 서버 로그에 기록

#### 1-3. OCR 경로 상수 환경변수 이전 (`tbm_ai.php`)
- `TESSERACT_PATH`, `PYTHON_PATH`, `EASYOCR_SCRIPT`를 `.env`에서 로드

---

### 2. 코드 품질 개선

#### 2-1. 중복 함수 통일 (`index.php`, `generate.php`)
- 3곳에 분산된 동일한 `h()` 함수를 `tbm_functions.php`의 `e()` 래퍼로 통일
- `function_exists` 가드로 중복 정의 방지

#### 2-2. 죽은 코드 제거 (`tbm_ai.php`)
- 주석 처리된 `tbm_ai_download_image_to_local()` (55줄) 제거
- 사용되지 않는 레거시 `load_used_articles()` / `save_used_articles()` 제거
  (DB 기반 `tbm_ai_get_recent_used_source_urls()`로 이미 대체됨)

#### 2-3. 들여쓰기 및 정렬 정리 (`generate.php`)
- `source_url` 처리 블록의 닫는 중괄호 들여쓰기 수정
- `$contentPayload` 배열 정렬 통일

#### 2-4. UI 텍스트 수정 (`index.php`)
- AI 로딩 오버레이: "Claude AI" → "AI" (실제 Gemini 사용)

---

### 3. 안정성 개선

#### 3-1. 후보 선택 가중 랜덤 (`tbm_ai.php` — `tbm_ai_pick_best_candidate`)
- **이전:** 상위 7개를 `shuffle`하여 첫 번째 선택 → 최고 점수 후보 탈락 가능
- **이후:** 점수 기반 **가중 랜덤** — 고득점 후보가 선택될 확률이 비례적으로 높음
- 최소 점수(10점) 미달 후보는 사전 제거

#### 3-2. 뉴스 제목 필터 완화 (`tbm_ai.php`)
- **이전:** 제목에 `사망|숨져|숨진`만 허용
- **이후:** `중태|중상|부상|심정지|의식불명` 추가
- 중대재해이지만 제목에 "사망"이 없는 기사도 수집 가능

#### 3-3. 누락 함수 구현 (`tbm_ai.php` — `tbm_ai_fetch_fallback_image`)
- 호출은 있으나 정의가 없던 함수를 구현
- 네이버 이미지 검색 API로 관련 이미지를 확보하는 fallback 로직

---

### 4. 운영 안정성

#### 4-1. 캐시 자동 정리 (`tbm_ai.php`)
- `tbm_ai_purge_old_cache(30)` — 30일 이전 캐시 파일 자동 삭제
- 콘텐츠 생성 시 자동 호출

#### 4-2. 디버그 로그 로테이션 (`tbm_ai.php`)
- 5MB 초과 시 `tbm_ai_debug_old.log`로 백업 후 새 파일 시작
- 디스크 공간 무한 사용 방지

---

## 적용 방법

1. 기존 파일을 백업합니다.
2. `tbm_improved/` 폴더의 파일을 프로젝트 루트에 덮어씌웁니다.
3. `.env.example`을 `.env`로 복사하고 실제 값을 입력합니다.
4. 기존 `.env`가 있다면 아래 항목만 추가합니다:

```
TBM_DB_HOST=localhost
TBM_DB_PORT=3306
TBM_DB_NAME=tbm_db
TBM_DB_USER=root
TBM_DB_PASS=
APP_ENV=development
APP_DEBUG=1
```

5. 운영 배포 시 `APP_ENV=production`으로 변경합니다.

## 미변경 파일 (구조 양호, 수정 불필요)

- `tbm_functions.php` — 템플릿 렌더링, 변경 없음
- `tbm_news.php` — 뉴스 수집, 변경 없음
- `tbm_siren.php` — KOSHA 연동, 변경 없음
- `ocr_router.php` — OCR 라우팅, 변경 없음
- `ocr_easy.py` — EasyOCR 스크립트, 변경 없음
- `test_tbm_siren.php` — 테스트 도구, 변경 없음

---

## 추가 개선 (2차)

### 5. KOSHA 포스터 이미지 crop 정밀화 (`tbm_ai.php`)

**`tbm_ai_crop_kosha_preview_image` 함수 비율 수정:**

| 항목 | 기존 | 수정 |
|------|------|------|
| cropX | width × 0.08 | width × 0.07 |
| cropY | height × 0.22 | height × **0.455** |
| cropW | width × 0.84 | width × 0.86 |
| cropH | height × 0.34 | height × **0.255** |

- 기존: 사고 개요 텍스트 + 사진이 함께 잘림
- 수정: **사진(일러스트) 영역만** 정확히 추출

### 6. 기사 본문 추출 개선 (`tbm_news.php`)
- `tbm_news_extract_article_body`에 **DOMDocument 기반 2차 파싱** 추가
- 정규식으로 잡지 못하는 중첩 태그 구조도 처리 가능
- 기존 정규식 → DOMDocument → og:description 순서 3단계 폴백

### 8. AI 생성 글자 수 정밀 제어 (`tbm_ai.php`)

**`tbm_ai_build_article_prompt` / `tbm_ai_build_siren_prompt` 두 함수 프롬프트 수정:**

| 항목 | 기존 프롬프트 | 수정 프롬프트 | 실제 생성(수정 전) |
|------|-------------|-------------|-----------------|
| 사고내용 및 원인 | "300자 이하" | "280~300자 범위로 충분히 상세하게" | 160자 (부족) |
| 예방대책 | "200자 이하" | "180~200자 범위로 충분히 상세하게" | 139자 (부족) |
| 퀴즈 1+2+3 합계 | 글자 수 제한 없음 | "합계 550~600자 범위, 구체적이고 상세하게" | 311자 (부족) |

- "이하"만 지시하면 Gemini가 지나치게 짧게 생성하는 문제 해결
- 범위 지정 방식(하한~상한)으로 목표 분량에 근접하도록 유도
- `tbm_functions.php`의 `tbm_trim_body()`에서 300자/200자 상한 후처리가 있어 초과 시에도 안전

### 7. 기본 이미지 경로 개선 (`tbm_functions.php`)
- `tbm_build_article_image_url`의 기본 이미지 파일명에서 한글/공백 제거
- `TBM일지 26-03-24_hd1.png` → `TBM_default_image.png` (URL 인코딩 문제 방지)
- 주의: 기존 `template/TBM일지 26-03-24_hd1.png` 파일을 `TBM_default_image.png`로 복사/이름변경 필요

### 9. 미사용 후보 이미지 자동 정리 (`tbm_ai.php`, `tbm_siren.php`)

**문제:** 한 번 실행 시 KOSHA 후보 5개의 이미지가 모두 `output/images/`에 저장되어 쌓임
- `siren_xxx.jpg` (후보 5개 전부)
- `crop_xxx.jpg` (각 후보의 crop 버전)
- `crop_xxx_fit.jpg` (fit 변형)
- `article_xxx.jpg` (기사 이미지 / fallback)

**해결:**

| 수정 | 파일 | 내용 |
|------|------|------|
| 신규 함수 | `tbm_ai.php` | `tbm_ai_cleanup_unused_images()` — 선택되지 않은 후보의 이미지+crop+fit 파일 자동 삭제 |
| 호출 위치 1 | `tbm_ai.php` | 1순위 KOSHA 선택 성공 후 → 미사용 후보 이미지 정리 |
| 호출 위치 2 | `tbm_ai.php` | 1순위 실패 시 → 전체 후보 이미지 정리 (2순위로 넘어가므로 불필요) |
| 중복 방지 | `tbm_ai.php` | `tbm_ai_download_and_validate_image()` — 동일 URL 파일이 이미 존재하면 재다운로드 스킵 |
| 중복 방지 | `tbm_siren.php` | `tbm_siren_save_data_uri_image()` — 동일 이미지가 이미 저장되어 있으면 재저장 스킵 |

- 실행 후 `output/images/`에는 **최종 선택된 이미지 1개(+crop/fit 변형)**만 남음
