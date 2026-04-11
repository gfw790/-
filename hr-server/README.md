# 인사관리 시스템 (HR Management System)

Laravel 11 + PHP 8.2 + MySQL (XAMPP)

---

## 설치 방법

### 1단계: 압축 해제

다운로드한 ZIP을 아래 경로에 압축 해제합니다:

    A:\risk_server\project\hr-server\

(composer.json 파일이 이 폴더 바로 안에 있어야 합니다)

### 2단계: Composer 의존성 설치

CMD를 열고 실행합니다:

    cd /d A:\risk_server\project\hr-server
    composer update

### 3단계: 데이터베이스 생성

XAMPP에서 MySQL을 Start한 후, phpMyAdmin에서 아래 SQL을 실행합니다:

    CREATE DATABASE hr_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

### 4단계: 테이블 생성 + 샘플 데이터

    cd /d A:\risk_server\project\hr-server
    A:\risk_server\xampp\php\php.exe artisan migrate
    A:\risk_server\xampp\php\php.exe artisan db:seed

### 5단계: 서버 실행

    A:\risk_server\xampp\php\php.exe artisan serve

http://localhost:8000 에 접속하면 완료!

---

## 문제 해결

- "php를 찾을 수 없습니다" → php 대신 A:\risk_server\xampp\php\php.exe 사용
- DB 연결 오류 → XAMPP에서 MySQL이 실행 중인지 확인
- 포트 충돌 → php artisan serve --port=9000
