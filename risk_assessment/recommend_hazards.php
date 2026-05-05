<?php
require_once __DIR__ . '/config/db.php';

$ra_id = isset($_GET['ra_id']) ? (int)$_GET['ra_id'] : 0;
$message = '';
$rows = [];
$header = null;

function get_ids(mysqli $conn, string $sql, int $ra_id, string $field): array
{
    $ids = [];
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $ra_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row[$field];
    }
    $stmt->close();
    return $ids;
}

if ($ra_id <= 0) {
    $message = '유효한 ra_id가 필요합니다.';
} else {
    $check_sql = "SELECT ra_id, work_date, work_title, work_location FROM ra_header WHERE ra_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('i', $ra_id);
    $check_stmt->execute();
    $header_result = $check_stmt->get_result();
    $header = $header_result->fetch_assoc();
    $check_stmt->close();

    if (!$header) {
        $message = '해당 문서를 찾을 수 없습니다.';
    } else {
        $target_ids = get_ids($conn, "SELECT target_id FROM ra_target WHERE ra_id = ?", $ra_id, 'target_id');
        $env_ids = get_ids($conn, "SELECT env_id FROM ra_env WHERE ra_id = ?", $ra_id, 'env_id');
        $tool_ids = get_ids($conn, "SELECT tool_id FROM ra_tool WHERE ra_id = ?", $ra_id, 'tool_id');
        $major_ids = get_ids($conn, "SELECT major_work_id FROM ra_major_work WHERE ra_id = ?", $ra_id, 'major_work_id');

        $conditions = [];
        $params = [];
        $types = '';

        foreach ([
            ['TARGET', $target_ids],
            ['ENV', $env_ids],
            ['TOOL', $tool_ids],
            ['MAJOR', $major_ids],
        ] as [$mappingType, $ids]) {
            if (empty($ids)) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $conditions[] = "(x.mapping_type = '{$mappingType}' AND x.mapping_id IN ({$placeholders}))";
            foreach ($ids as $id) {
                $params[] = $id;
                $types .= 'i';
            }
        }

        if (empty($conditions)) {
            $message = '선택된 작업 조건이 없습니다.';
        } else {
            $sql = "
                SELECT
                    hm.hazard_id,
                    hm.hazard_group,
                    hm.hazard_name,
                    hm.hazard_4m,
                    SUM(x.weight) AS total_weight
                FROM hazard_mapping x
                JOIN hazard_master hm
                    ON x.hazard_id = hm.hazard_id
                WHERE x.use_yn = 'Y'
                  AND (" . implode(' OR ', $conditions) . ")
                GROUP BY hm.hazard_id, hm.hazard_group, hm.hazard_name, hm.hazard_4m
                ORDER BY total_weight DESC, hm.hazard_id ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                die('SQL 준비 실패: ' . $conn->error);
            }

            if (!empty($params)) {
                $bind_names = [$types];
                for ($i = 0; $i < count($params); $i++) {
                    $bind_name = 'bind' . $i;
                    $$bind_name = $params[$i];
                    $bind_names[] = &$$bind_name;
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>추천 유해위험요인</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; max-width: 1100px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>
    <h2>추천 유해위험요인</h2>

    <?php if (!empty($header)): ?>
        <p><strong>RA_ID:</strong> <?php echo (int)$header['ra_id']; ?></p>
        <p><strong>작업일자:</strong> <?php echo htmlspecialchars((string)$header['work_date'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>작업명:</strong> <?php echo htmlspecialchars((string)$header['work_title'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>작업위치:</strong> <?php echo htmlspecialchars((string)$header['work_location'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <p><strong><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php endif; ?>

    <?php if (!empty($rows)): ?>
        <table>
            <thead>
                <tr>
                    <th>위험ID</th>
                    <th>위험그룹</th>
                    <th>4M분류</th>
                    <th>유해위험요인</th>
                    <th>추천점수</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo (int)$row['hazard_id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['hazard_group'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['hazard_4m'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['hazard_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$row['total_weight']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
