<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db_config.php';

function auth_find_user(string $loginId): ?array {
    foreach (auth_accounts() as $user) {
        if ((string)$user['login_id'] === $loginId) {
            return $user;
        }
    }
    return null;
}

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
$stmt = $pdo->query('SELECT report_id,user_login_id,team_name,work_title FROM work_report ORDER BY report_id DESC LIMIT 50');
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
$visibleTeams = array_fill_keys(array_map('auth_team_key', auth_work_list_visible_teams($user)), true);
foreach ($reports as $report) {
    $reportTeam = auth_normalize_team_name((string)$report['team_name']);
    $visible = $reportTeam !== '' && isset($visibleTeams[auth_team_key($reportTeam)]);
    if ($visible) {
        echo "VISIBLE: {$report['report_id']} | {$report['user_login_id']} | {$reportTeam} | {$report['work_title']}\n";
    }
}
