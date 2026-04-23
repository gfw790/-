<?php
require 'risk_assessment/auth.php';
$accounts = auth_read_stored_accounts();
echo (is_array($accounts) && count($accounts) ? 'auth_read_stored_accounts(): accounts' : 'auth_read_stored_accounts(): empty array'), PHP_EOL;
json_decode(file_get_contents('risk_assessment/auth_users.json'), true);
echo 'json_last_error_msg(): ', json_last_error_msg(), PHP_EOL;
