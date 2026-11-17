<?php
declare(strict_types=1);

function hazard_participant_print_team(array $report): string
{
    $team = auth_normalize_team_name((string)($report['team_name'] ?? ''));
    if ($team !== '') return $team;
    $owner = auth_find_user((string)($report['user_login_id'] ?? ''));
    return auth_normalize_team_name((string)($owner['team'] ?? ''));
}

function hazard_participant_print_can_view(array $user, array $report): bool
{
    if (auth_is_worker($user) && (int)($report['leader_detail_count'] ?? 0) <= 0) return false;
    if (auth_is_admin($user) || in_array((string)($user['role'] ?? ''), ['safety_manager', 'administrator'], true)) return true;

    $visibleTeams = auth_work_list_visible_teams($user);
    if ($visibleTeams) {
        $teamKey = auth_team_key(hazard_participant_print_team($report));
        return $teamKey !== '' && in_array($teamKey, array_map('auth_team_key', $visibleTeams), true);
    }
    return trim((string)($user['login_id'] ?? '')) !== ''
        && (string)($report['user_login_id'] ?? '') === (string)$user['login_id'];
}
