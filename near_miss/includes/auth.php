<?php
// ============================================================
// 사내 게시판 - 인증 모듈 (사내 auth.php 연동 버전)
// ============================================================
// 기존 사내 시스템(A:\risk_server\project\risk_assessment\auth.php)과 연동되어
// $_SESSION['auth_user']를 그대로 사용합니다.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// ============================================================
// 사내 auth.php 로드
// 게시판은 A:\risk_server\project\board\ 에 있고
// 사내 auth.php는 A:\risk_server\project\risk_assessment\auth.php 에 있으므로
// 상위 폴더의 auth.php를 require 합니다.
// ============================================================
$_sharedAuthPath = __DIR__ . '/../../risk_assessment/auth.php';
if (file_exists($_sharedAuthPath)) {
    require_once $_sharedAuthPath;
}

/**
 * 현재 로그인한 사용자 정보를 게시판 표준 형식으로 반환합니다.
 *
 * 사내 시스템의 $_SESSION['auth_user']를 게시판이 사용하는
 * ['id', 'name', 'dept', 'role'] 형식으로 변환합니다.
 *
 * 권한 매핑:
 *   - admin   → admin (게시판 관리자)
 *   - manager → admin (관리감독자도 게시판 관리자 권한)
 *   - leader  → user  (작업지휘자)
 *   - worker  → user  (일반작업자)
 *
 * 관리자 권한 정책을 바꾸고 싶으면 아래 mapBoardRole 함수를 수정하세요.
 *
 * @return array|null
 */
function getCurrentUser() {
    // 사내 auth_current_user() 함수 사용
    if (!function_exists('auth_current_user')) {
        return null;
    }

    $sessionUser = auth_current_user();
    if (!$sessionUser) {
        return null;
    }

    $loginId = (string)($sessionUser['login_id'] ?? '');
    if ($loginId === '') {
        return null;
    }

    $user = [
        'id'   => $loginId,
        'name' => (string)($sessionUser['name'] ?? $loginId),
        'dept' => (string)($sessionUser['team'] ?? ''),
        'role' => mapBoardRole((string)($sessionUser['role'] ?? '')),
        // 게시판에서 추가로 활용 가능한 원본 정보
        'original_role'       => (string)($sessionUser['role'] ?? ''),
        'original_role_label' => (string)($sessionUser['role_label'] ?? ''),
    ];

    // users 테이블에 동기화 (작성자 캐시 및 통계용)
    syncUserToDb($user);

    return $user;
}

/**
 * 사내 역할(worker/leader/manager/admin)을 게시판 역할(user/admin)로 매핑
 *
 * 정책:
 *   - admin   → admin
 *   - manager → admin (관리감독자에게 게시판 관리 권한 부여)
 *   - leader  → user
 *   - worker  → user
 *
 * 변경하려면 아래 배열만 수정하면 됩니다.
 */
function mapBoardRole(string $companyRole): string {
    $adminRoles = ['admin', 'manager', 'safety_manager']; // 게시판 관리자로 인정할 사내 역할
    return in_array($companyRole, $adminRoles, true) ? 'admin' : 'user';
}

/**
 * 로그인이 필요한 페이지에서 호출
 */
function requireLogin() {
    $user = getCurrentUser();
    if (!$user) {
        // 사내 로그인 페이지로 이동
        $loginUrl = '../risk_assessment/task_select.php';
        echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>로그인 필요</title>';
        echo '<style>body{font-family:"맑은 고딕",sans-serif;text-align:center;padding:80px 20px;background:#f4f5f7;}';
        echo '.box{display:inline-block;background:#fff;border:1px solid #d8dde4;padding:40px 60px;}';
        echo 'h1{font-size:18px;color:#333;margin:0 0 12px;} p{color:#666;margin:0 0 20px;}';
        echo 'a{display:inline-block;padding:8px 20px;background:#1f3a5f;color:#fff;text-decoration:none;font-size:13px;}</style>';
        echo '</head><body><div class="box"><h1>로그인이 필요합니다</h1>';
        echo '<p>사내 계정으로 로그인 후 이용해주세요.</p>';
        echo '<a href="' . htmlspecialchars($loginUrl) . '">로그인 페이지로</a>';
        echo '</div></body></html>';
        exit;
    }
    return $user;
}

function requireAdmin() {
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('관리자 권한이 필요합니다.');
    }
    return $user;
}

function isAdmin() {
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

/**
 * 사용자 정보를 DB users 테이블에 동기화 (작성자 캐시용)
 */
function syncUserToDb($user) {
    try {
        $stmt = db()->prepare(
            "INSERT INTO users (id, name, dept, role, last_seen)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                dept = VALUES(dept),
                role = VALUES(role),
                last_seen = NOW()"
        );
        $stmt->execute([$user['id'], $user['name'], $user['dept'], $user['role']]);
    } catch (PDOException $e) {
        // 동기화 실패는 무시
    }
}
