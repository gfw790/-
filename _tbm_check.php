<?php
declare(strict_types=1);
// Stub session so auth.php does not error in CLI
if (!function_exists('session_start')) {}

// We need auth_is_admin from auth.php - require it
require_once "a:/risk_server/project/risk_assessment/auth.php";
require_once "a:/risk_server/project/tbm/tbm_functions.php";

$user = auth_find_user('admin02');
if ($user === null) {
    echo "User not found\n";
    exit(1);
}

$result = tbm_can_use_ai_generation($user);
echo $result ? "true" : "false";
echo "\n";
