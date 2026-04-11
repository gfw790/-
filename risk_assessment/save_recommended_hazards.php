<?php
require_once __DIR__ . "/config/db.php";

$ra_id = isset($_GET["ra_id"]) ? (int)$_GET["ra_id"] : 0;
$message = "";
$saved_count = 0;

if ($ra_id <= 0) {
    $message = "유효한 ra_id가 필요합니다.";
} else {
    $check_sql = "SELECT ra_id, work_title FROM ra_header WHERE ra_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $ra_id);
    $check_stmt->execute();
    $header_result = $check_stmt->get_result();
    $header = $header_result->fetch_assoc();
    $check_stmt->close();

    if (!$header) {
        $message = "해당 문서를 찾을 수 없습니다.";
    } else {
        function get_ids(mysqli $conn, string $sql, int $ra_id, string $field): array {
            $ids = [];
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $ra_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int)$row[$field];
            }
            $stmt->close();
            return $ids;
        }

        $target_ids = get_ids($conn, "SELECT target_id FROM ra_target WHERE ra_id = ?", $ra_id, "target_id");
        $env_ids = get_ids($conn, "SELECT env_id FROM ra_env WHERE ra_id = ?", $ra_id, "env_id");
        $tool_ids = get_ids($conn, "SELECT tool_id FROM ra_tool WHERE ra_id = ?", $ra_id, "tool_id");
        $major_ids = get_ids($conn, "SELECT major_work_id FROM ra_major_work WHERE ra_id = ?", $ra_id, "major_work_id");

        $conditions = [];
        $params = [];
        $types = "";

        if (!empty($target_ids)) {
            $placeholders = implode(",", array_fill(0, count($target_ids), "?"));
            $conditions[] = "(x.mapping_type = 'TARGET' AND x.mapping_id IN ($placeholders))";
            foreach ($target_ids as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        if (!empty($env_ids)) {
            $placeholders = implode(",", array_fill(0, count($env_ids), "?"));
            $conditions[] = "(x.mapping_type = 'ENV' AND x.mapping_id IN ($placeholders))";
            foreach ($env_ids as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        if (!empty($tool_ids)) {
            $placeholders = implode(",", array_fill(0, count($tool_ids), "?"));
            $conditions[] = "(x.mapping_type = 'TOOL' AND x.mapping_id IN ($placeholders))";
            foreach ($tool_ids as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        if (!empty($major_ids)) {
            $placeholders = implode(",", array_fill(0, count($major_ids), "?"));
            $conditions[] = "(x.mapping_type = 'MAJOR' AND x.mapping_id IN ($placeholders))";
            foreach ($major_ids as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        if (empty($conditions)) {
            $message = "선택된 작업조건이 없습니다.";
        } else {
            $sql = "
                SELECT
                    hm.hazard_id,
                    hm.hazard_name
                FROM hazard_mapping x
                JOIN hazard_master hm
                    ON x.hazard_id = hm.hazard_id
                WHERE x.use_yn = 'Y'
                  AND (" . implode(" OR ", $conditions) . ")
                GROUP BY hm.hazard_id, hm.hazard_name
                ORDER BY hm.hazard_id ASC
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                die("SQL 준비 실패: " . $conn->error);
            }

            if (!empty($params)) {
                $bind_names[] = $types;
                for ($i = 0; $i < count($params); $i++) {
                    $bind_name = 'bind' . $i;
                    $$bind_name = $params[$i];
                    $bind_names[] = &$$bind_name;
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            $insert_sql = "
                INSERT INTO risk_assessment_item (
                    ra_id,
                    hazard_id,
                    hazard_text
                ) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    hazard_text = VALUES(hazard_text)
            ";
            $insert_stmt = $conn->prepare($insert_sql);

            while ($row = $result->fetch_assoc()) {
                $hazard_id = (int)$row["hazard_id"];
                $hazard_text = $row["hazard_name"];

                $insert_stmt->bind_param("iis", $ra_id, $hazard_id, $hazard_text);
                $insert_stmt->execute();
                $saved_count++;
            }

            $insert_stmt->close();
            $stmt->close();

            $message = "추천 위험요인 저장 완료: " . $saved_count . "건";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>추천 위험요인 저장</title>
</head>
<body>
    <h2>추천 위험요인 저장</h2>

    <?php if (!empty($header)): ?>
        <p><strong>RA_ID:</strong> <?php echo (int)$header["ra_id"]; ?></p>
        <p><strong>작업명:</strong> <?php echo htmlspecialchars($header["work_title"], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <p><strong><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></strong></p>

    <?php if ($ra_id > 0): ?>
        <p>
            <a href="recommend_hazards.php?ra_id=<?php echo $ra_id; ?>">추천 목록 보기</a><br>
            <a href="edit_risk_items.php?ra_id=<?php echo $ra_id; ?>">위험성평가 입력하기</a>
        </p>
    <?php endif; ?>
</body>
</html>