# 단위 위험성평가서 업로드 — 설치 및 설정 안내

## 📁 파일 구성

```
your-project/
├── upload.html                  ← 업로드 웹 화면
├── unit_ra_excel_upload.php     ← 업로드 처리 (POST 수신)
├── db_config.php                ← DB 연결 설정
└── vendor/                      ← Composer 설치 후 자동 생성
    └── autoload.php
```

---

## 1단계 — Composer 설치 (없는 경우)

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

---

## 2단계 — PhpSpreadsheet 설치

프로젝트 폴더에서 실행:

```bash
cd /your-project-path
composer require phpoffice/phpspreadsheet
```

설치 완료되면 `vendor/` 폴더가 생성됩니다.

---

## 3단계 — DB 설정 수정

`db_config.php` 파일에서 실제 값으로 변경:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'risk_assessment');  // DB명
define('DB_USER', 'your_db_user');     // DB 사용자명
define('DB_PASS', 'your_db_password'); // DB 비밀번호
```

---

## 4단계 — 브라우저에서 접속

```
http://localhost/your-project-path/upload.html
```

---

## 업로드 처리 흐름

```
upload.html
  → (POST multipart/form-data)
  → unit_ra_excel_upload.php
      → 엑셀 파싱 (PhpSpreadsheet)
      → 헤더 정보 읽기 (행5, 행7)
      → 항목 읽기 (행10~)
      → DB 트랜잭션:
          INSERT unit_ra_header  → unit_ra_id 획득
          INSERT unit_ra_item    → unit_ra_id 연결
      → JSON 응답 반환
  → 결과 화면 표시
```

---

## 응답 예시

### 성공
```json
{
  "success": true,
  "message": "업로드 완료",
  "data": {
    "unit_ra_id": 12,
    "unit_title": "고소작업 위험성평가",
    "item_count": 10
  }
}
```

### 실패
```json
{
  "success": false,
  "message": "단위평가서명(unit_title)은 필수 입력 항목입니다. 엑셀 D5 셀을 확인하세요.",
  "data": []
}
```

---

## 자주 묻는 오류

| 오류 메시지 | 원인 | 해결 |
|---|---|---|
| `"단위위험성평가서" 시트를 찾을 수 없습니다` | 시트명 변경됨 | 엑셀 시트명을 `단위위험성평가서`로 유지 |
| `vendor/autoload.php 없음` | Composer 미설치 | 2단계 실행 |
| `SQLSTATE[...] Access denied` | DB 계정 오류 | db_config.php 계정 확인 |
| `입력된 항목이 없습니다` | C열/D열 비어있음 | 10행부터 데이터 확인 |
