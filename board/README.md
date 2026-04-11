# 사내 게시판 (board) - risk_server 통합판

기존 risk_server 프로젝트의 사내 인증 시스템과 통합된 게시판입니다.

## 설치 위치

```
A:\risk_server\project\board\
```

## 기존 시스템 연동

이 게시판은 `A:\risk_server\project\auth.php`의 인증 시스템을 그대로 사용합니다.
별도 로그인이 필요 없으며, 기존 task_select.php에서 로그인된 사용자가 자동으로 인증됩니다.

### 권한 매핑

| 기존 역할 (role) | 라벨            | 게시판 권한 |
|------------------|-----------------|-------------|
| admin            | 운영자          | 관리자      |
| manager          | 관리감독자      | 관리자      |
| leader           | 작업지휘자      | 일반        |
| worker           | 일반작업자      | 일반        |

- **관리자**는 모든 게시글 수정/삭제, 카테고리 관리, 공지글 작성이 가능합니다.
- **일반 사용자**는 자신의 글만 수정/삭제할 수 있습니다.
- 사용자의 부서는 기존 시스템의 팀(공사팀-전기, 가스팀 등)이 자동 표시됩니다.

## 설치 순서

### 1. 파일 배치
이 폴더 전체를 `A:\risk_server\project\board\`에 복사합니다.

```
A:\risk_server\project\
├── auth.php              ← 기존 인증 시스템
├── auth_users.json
├── auth_teams.json
├── task_select.php       ← 기존 로그인 화면
└── board\                ← 새로 추가
    ├── index.php
    ├── includes\
    └── ...
```

### 2. 데이터베이스 생성
phpMyAdmin (`http://localhost/phpmyadmin`):
1. 상단 **SQL** 탭 클릭 (DB 선택 안 함)
2. `install.sql` 내용 복사 → 붙여넣기
3. **실행(Go)** 클릭

→ board 데이터베이스와 9개 테이블 생성됨.

### 3. DB 접속 정보 확인
`includes/config.php` 에서 MySQL 접속 정보를 확인합니다.
XAMPP 기본값(root / 빈 비밀번호)이라면 그대로 둡니다.

### 4. 접속 테스트

기존 시스템에 로그인된 상태에서:
```
http://localhost/project/board/
```

로그인 안 된 상태에서 접속하면 자동으로 task_select.php로 이동합니다.

### 5. 메인 화면에 링크 걸기

기존 task_select.php 또는 메인 화면에:
```html
<a href="board/">📋 사내 게시판</a>
```

## 기능

- 게시글 CRUD (작성/조회/수정/삭제)
- 카테고리 (공지사항/자유게시판/Q&A/자료실, 관리자가 추가/삭제 가능)
- 댓글 (1단계 대댓글)
- 검색 (제목/내용/작성자, 카테고리·기간 필터)
- 첨부파일 (다중, 위험 확장자 차단)
- 공지글 상단 고정
- 좋아요 (AJAX)
- 투표 (단일/복수, 익명, 마감일)
- 조회수 (세션 기반 중복 방지)
- 관리자 페이지 (통계, 카테고리 관리)

## 권한 체계

| 동작                       | worker | leader | manager | admin |
|----------------------------|:------:|:------:|:-------:|:-----:|
| 게시글 작성                |   ○    |   ○    |    ○    |   ○   |
| 공지글 작성                |   ✕    |   ✕    |    ○    |   ○   |
| 본인 글 수정/삭제          |   ○    |   ○    |    ○    |   ○   |
| 다른 사람 글 수정/삭제     |   ✕    |   ✕    |    ○    |   ○   |
| 카테고리 관리              |   ✕    |   ✕    |    ○    |   ○   |
| 관리자 페이지 접근         |   ✕    |   ✕    |    ○    |   ○   |

## 보안

- PDO Prepared Statement (SQL Injection 방어)
- htmlspecialchars 출력 (XSS 방어)
- CSRF 토큰 (모든 폼)
- uploads 폴더 PHP 실행 차단 (.htaccess 자동 적용)
- 위험 확장자 업로드 차단 (php, exe, sh 등)

## 트러블슈팅

**Q. "로그인이 필요합니다"가 계속 표시됩니다.**
→ 기존 task_select.php에서 먼저 로그인하세요. board는 기존 세션을 공유합니다.

**Q. 메인으로 돌아가는 링크가 안 됩니다.**
→ `includes/header.php`의 `../task_select.php` 경로를 환경에 맞게 수정하세요.

**Q. 한글이 깨집니다.**
→ MySQL 설정이 utf8mb4인지 확인하세요.

**Q. 운영 시작 전 체크리스트**
→ `includes/config.php`의 `DEBUG`를 `false`로 변경하세요.
