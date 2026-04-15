<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db_config.php';

$user = auth_find_user('7204');
if (!$user) {
    echo "No user 7204\n";
    exit(1);
}
echo "User: " . json_encode($user, JSON_UNESCAPED_UNICODE) . "\n";
echo "Can manage: " . (auth_can_manage($user) ? 'yes' : 'no') . "\n";
echo "Team: " . auth_normalize_team_name((string)$user['team']) . "\n";
echo "Team key: " . auth_team_key((string)$user['team']) . "\n";
echo "Supervised teams: " . json_encode(auth_supervised_teams((string)$user['team']), JSON_UNESCAPED_UNICODE) . "\n";
echo "Visible teams: " . json_encode(auth_work_list_visible_teams($user), JSON_UNESCAPED_UNICODE) . "\n";

$pdo = getDB();
$stmt = $pdo->query('SELECT DISTINCT team_name FROM work_report');
$teamNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Distinct team_name values:\n";
foreach ($teamNames as $teamName) {
    $raw = $teamName === null ? 'NULL' : $teamName;
    echo "[" . $raw . "] normalized=[" . auth_normalize_team_name((string)$teamName) . "] key=[" . auth_team_key((string)$teamName) . "]\n";
}
