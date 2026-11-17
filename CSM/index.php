<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
$currentUser = csm_require_auth();
header('Cache-Control: no-store');

// Fetch Education Metrics
$eduStatsAvailable = false;
$eduStats = [
    'total_trainings' => 0,
    'total_attendees' => 0,
    'planned_count' => 0
];

$eduDbPath = __DIR__ . '/education/storage/db/csm.db';
if (file_exists($eduDbPath)) {
    try {
        $db = new PDO('sqlite:' . $eduDbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $s1 = $db->query("SELECT COUNT(*) FROM csm_trainings WHERE is_demo = 0")->fetchColumn();
        $s2 = $db->query("SELECT COUNT(*) FROM csm_training_attendees a JOIN csm_trainings t ON a.training_id = t.id WHERE t.is_demo = 0 AND a.is_completed = 1")->fetchColumn();
        $s3 = $db->query("SELECT COUNT(*) FROM csm_training_plans WHERE status = 'PLANNED'")->fetchColumn();
        $eduStats['total_trainings'] = (int)$s1;
        $eduStats['total_attendees'] = (int)$s2;
        $eduStats['planned_count'] = (int)$s3;
        $eduStatsAvailable = true;
    } catch (Throwable $e) {
        // silent
    }
}

require __DIR__ . '/portal.php';
