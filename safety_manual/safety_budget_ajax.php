<?php
declare(strict_types=1);

require_once __DIR__ . '/../risk_assessment/auth.php';
require_once __DIR__ . '/../risk_assessment/db_config.php';
require_once __DIR__ . '/safety_budget_storage.php';

header('Content-Type: application/json; charset=UTF-8');

function respond(bool $success, array $data = [], string $message = ''): never
{
    echo json_encode(['success' => $success, 'message' => $message] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_current_user();
if (!is_array($user)) {
    http_response_code(401);
    respond(false, [], '로그인이 필요합니다.');
}
$isAllowed = auth_can_manage($user) && trim((string)($user['login_id'] ?? '')) === '5878';
if (!$isAllowed) {
    http_response_code(403);
    respond(false, [], '이 기능은 지정된 관리자 계정만 사용할 수 있습니다.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['safety_budget_csrf'] ??= bin2hex(random_bytes(32));

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

try {
    $db = getDB();
    safety_budget_initialize($db);

    if ($action === 'load_general') {
        $year = filter_var($_GET['year'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1000 || $year > 9999) {
            respond(false, [], '올바른 연도를 선택해 주세요.');
        }
        $general = safety_budget_general_load($db, $year) ?? safety_budget_general_defaults($year);
        respond(true, ['general' => $general, 'category_totals' => safety_budget_category_totals($db, $year), 'csrf' => $_SESSION['safety_budget_csrf']]);
    }

    if ($action === 'load_detail') {
        $year = filter_var($_GET['year'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1000 || $year > 9999) {
            respond(false, [], '올바른 연도를 선택해 주세요.');
        }
        respond(true, ['grid' => safety_budget_detail_load($db, $year), 'csrf' => $_SESSION['safety_budget_csrf']]);
    }

    if ($action === 'load_usage') {
        $year = filter_var($_GET['year'] ?? 0, FILTER_VALIDATE_INT);
        $month = filter_var($_GET['month'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1000 || $year > 9999 || $month === false || $month < 1 || $month > 12) {
            respond(false, [], '올바른 연도와 월을 선택해 주세요.');
        }
        respond(true, ['rows' => safety_budget_usage_load($db, $year, $month), 'csrf' => $_SESSION['safety_budget_csrf']]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        respond(false, [], '잘못된 요청 방식입니다.');
    }

    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['safety_budget_csrf'], $csrf)) {
        respond(false, [], '요청을 확인할 수 없습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.');
    }

    if ($action === 'save_general') {
        $year = filter_var($_POST['year'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1000 || $year > 9999) {
            respond(false, [], '올바른 연도를 선택해 주세요.');
        }
        $rawData = json_decode((string)($_POST['general'] ?? '{}'), true);
        $data = safety_budget_general_validate($rawData);
        safety_budget_general_save($db, $year, $data, (string)$user['login_id']);
        respond(true, ['general' => safety_budget_general_load($db, $year), 'category_totals' => safety_budget_category_totals($db, $year)], '일반사항을 저장했습니다.');
    }

    if ($action === 'save_detail') {
        $year = filter_var($_POST['year'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1000 || $year > 9999) {
            respond(false, [], '올바른 연도를 선택해 주세요.');
        }
        $rawRows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        $rows = safety_budget_detail_validate($rawRows);
        safety_budget_detail_save($db, $year, $rows, (string)$user['login_id']);
        respond(true, ['grid' => safety_budget_detail_load($db, $year), 'category_totals' => safety_budget_category_totals($db, $year)], '세부 사용계획을 저장했습니다.');
    }

    if ($action === 'save_usage') {
        $year = filter_var($_POST['year'] ?? 0, FILTER_VALIDATE_INT);
        $month = filter_var($_POST['month'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1000 || $year > 9999 || $month === false || $month < 1 || $month > 12) {
            respond(false, [], '올바른 연도와 월을 선택해 주세요.');
        }
        $rawRows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        $rows = safety_budget_usage_validate($rawRows);
        safety_budget_usage_save($db, $year, $month, $rows, (string)$user['login_id']);
        respond(true, ['rows' => safety_budget_usage_load($db, $year, $month)], (string)$month . '월 사용내역서를 저장했습니다.');
    }

    respond(false, [], '알 수 없는 요청입니다.');
} catch (InvalidArgumentException $error) {
    respond(false, [], $error->getMessage());
} catch (Throwable $error) {
    error_log('Safety budget AJAX failure: ' . $error->getMessage());
    http_response_code(500);
    respond(false, [], 'DB 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.');
}
