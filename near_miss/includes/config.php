<?php
// ============================================
// 데이터베이스 접속 정보
// 환경에 맞게 수정하세요
// ============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'board');
define('DB_CHARSET', 'utf8mb4');

// 게시판 기본 설정
define('BOARD_TITLE', '아차사고 게시판');
define('POSTS_PER_PAGE', 20);
define('MAX_UPLOAD_SIZE', 20 * 1024 * 1024); // 20MB
define('ALLOWED_EXTENSIONS', 'jpg,jpeg,png,gif,bmp,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,hwp,hwpx,txt,zip,7z,csv');
define('BLOCKED_EXTENSIONS', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,sh,js,html,htm');
define('BOARD_UPLOAD_DIR_CHAT', 'A:\\risk_server\\uploads\\2026\\board-upload\\01_chat');
define('BOARD_UPLOAD_DIR_QNA', 'A:\\risk_server\\uploads\\2026\\board-upload\\02_Q&A');
define('BOARD_UPLOAD_DIR_DATA', 'A:\\risk_server\\uploads\\2026\\board-upload\\03_data');
define('BOARD_UPLOAD_DIR_DWG', 'A:\\risk_server\\uploads\\2026\\board-upload\\04_dwg');
define('BOARD_UPLOAD_DIR_NEAR_MISS', 'A:\\risk_server\\uploads\\2026\\board-upload\\05_naer_miss');

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
