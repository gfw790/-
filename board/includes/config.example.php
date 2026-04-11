<?php
// ============================================
// 데이터베이스 접속 정보
// 환경에 맞게 수정하세요.
// ============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'board');
define('DB_CHARSET', 'utf8mb4');

// 게시판 기본 설정
define('BOARD_TITLE', '사내 게시판');
define('POSTS_PER_PAGE', 20);
define('MAX_UPLOAD_SIZE', 20 * 1024 * 1024); // 20MB
define('ALLOWED_EXTENSIONS', 'jpg,jpeg,png,gif,bmp,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,hwp,hwpx,txt,zip,7z,csv');
define('BLOCKED_EXTENSIONS', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,sh,js,html,htm');

// 시간대
date_default_timezone_set('Asia/Seoul');

// 에러 표시 (운영 시 false 권장)
define('DEBUG', true);
if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
