<?php
declare(strict_types=1);
require_once "a:/risk_server/project/risk_assessment/auth.php";
require_once "a:/risk_server/project/tbm/tbm_functions.php";

$user = auth_find_user('admin02');
if ($user === null) {
    echo "User not found\n";
    exit(1);
}
echo "User data: ";
var_dump($user);
$result = tbm_can_use_ai_generation($user);
echo "Result: " . ($result ? "true" : "false") . "\n";
