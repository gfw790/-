<?php
declare(strict_types=1);

// A:\risk_server\project\CSM\education\config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('CSM_ROOT', __DIR__);
define('CSM_DEV_ROOT', __DIR__); // Backward compatibility alias
define('CSM_DB_PATH', CSM_ROOT . '/storage/db/csm.db');
define('CSM_UPLOAD_PHOTOS_DIR', CSM_ROOT . '/storage/uploads/photos');
define('CSM_UPLOAD_SIGNS_DIR', CSM_ROOT . '/storage/uploads/signatures');
define('CSM_UPLOAD_SCANS_DIR', CSM_ROOT . '/storage/uploads/scanned_docs');
define('CSM_BACKUP_DIR', CSM_ROOT . '/storage/backups');
define('CSM_TEMPLATE_EXCEL', CSM_ROOT . '/templates/교육일지_서식사본.xlsx');

// External Operational Systems (Read-Only)
$projectRoot = dirname(__DIR__, 2);
define('EXT_EMP_DB_PATH', $projectRoot . '/employees/employees.db');
define('EXT_MSDS_JSON_PATH', $projectRoot . '/risk_assessment/msds_records.json');
define('EXT_AUTH_PATH', $projectRoot . '/risk_assessment/auth.php');

// Include existing auth if available
require_once dirname(__DIR__) . '/auth.php';

/**
 * Get CSM Application PDO Database Connection
 */
function get_csm_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . CSM_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA foreign_keys = ON;");
    }
    return $pdo;
}

/**
 * Get Existing Employee Database Connection (STRICTLY READ-ONLY)
 */
function get_ext_employees_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Strict read-only mode via URI
        $dsn = 'sqlite:file:' . EXT_EMP_DB_PATH . '?mode=ro';
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/**
 * Detect Python Executable Binary (Supports both CLI and Apache web service environments)
 */
function get_python_bin(): string {
    $candidates = [
        'C:/Python314/python.exe',
        'C:/Users/gfw79/AppData/Local/Programs/Python/Python38/python.exe',
        'C:/Users/gfw79/AppData/Local/Python/bin/python.exe',
        'python.exe',
        'python',
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            return $cand;
        }
    }
    return 'python';
}

/**
 * Check if user can edit/finalize
 */
function csm_can_manage(?array $u): bool {
    if ($u === null) return false;
    $role = (string)($u['role'] ?? '');
    return in_array($role, ['admin', 'manager', 'leader', 'safety_manager', 'supervisor'], true);
}
