<?php
require_once __DIR__ . "/config/db.php";

$message = "";
$ra_id = isset($_GET["ra_id"]) ? (int)$_GET["ra_id"] : 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ra_id = (int)($_POST["ra_id"] ?? 0);
    $targets = $_POST["target_ids"] ?? [];
    $envs = $_POST["env_ids"] ?? [];
    $tools = $_POST["tool_ids"] ?? [];
    $majors = $_POST["major_work_ids"] ?? [];

    if ($ra_id <= 0) {
        $message = "유효한 ra_id가 필요합니다.";
    } else {
        $conn->begin_transaction();

        try {
            $check_sql = "SELECT ra_id FROM ra_header WHERE ra_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $ra_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows === 0) {
                throw new Exception("해당 ra_id 문서가 존재하지 않습니다.");
            }
            $check_stmt->close();

            $conn->query("DELETE FROM ra_target WHERE ra_id = {$ra_id}");
            $conn->query("DELETE FROM ra_env WHERE ra_id = {$ra_id}");
            $conn->query("DELETE FROM ra_tool WHERE ra_id = {$ra_id}");
            $conn->query("DELETE FROM ra_major_work WHERE ra_id = {$ra_id}");

            if (!empty($targets)) {
                $stmt = $conn->prepare("INSERT INTO ra_target (ra_id, target_id) VALUES (?, ?)");
                foreach ($targets as $target_id) {
                    $target_id = (int)$target_id;
                    $stmt->bind_param("ii", $ra_id, $target_id);
                    $stmt->execute();
                }
                $stmt->close();
            }

            if (!empty($envs)) {
                $stmt = $conn->prepare("INSERT INTO ra_env (ra_id, env_id) VALUES (?, ?)");
                foreach ($envs as $env_id) {
                    $env_id = (int)$env_id;
                    $stmt->bind_param("ii", $ra_id, $env_id);
                    $stmt->execute();
                }
                $stmt->close();
            }

            if (!empty($tools)) {
                $stmt = $conn->prepare("INSERT INTO ra_tool (ra_id, tool_id) VALUES (?, ?)");
                foreach ($tools as $tool_id) {
                    $tool_id = (int)$tool_id;
                    $stmt->bind_param("ii", $ra_id, $tool_id);
                    $stmt->execute();
                }
                $stmt->close();
            }

            if (!empty($majors)) {
                $stmt = $conn->prepare("INSERT INTO ra_major_work (ra_id, major_work_id) VALUES (?, ?)");
                foreach ($majors as $major_id) {
                    $major_id = (int)$major_id;
                    $stmt->bind_param("ii", $ra_id, $major_id);
                    $stmt->execute();
                }
                $stmt->close();
            }

            $conn->commit();
            $message = "작업조건이 저장되었습니다.";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "저장 실패: " . $e->getMessage();
        }
    }
}

function get_master_list(mysqli $conn, string $sql): array {
    $data = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
}

$target_list = get_master_list($conn, "SELECT target_id, major_category, sub_category, work_type FROM work_target_master WHERE use_yn='Y' ORDER BY sort_no, target_id");
$env_list = get_master_list($conn, "SELECT env_id, env_name FROM env_master WHERE use_yn='Y' ORDER BY sort_no, env_id");
$tool_list = get_master_list($conn, "SELECT tool_id, tool_name FROM tool_master WHERE use_yn='Y' ORDER BY sort_no, tool_id");
$major_list = get_master_list($conn, "SELECT major_work_id, major_work_name FROM major_work_master WHERE use_yn='Y' ORDER BY sort_no, major_work_id");
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>작업조건 선택</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .group { margin-bottom: 24px; padding: 12px; border: 1px solid #ccc; }
        .item { margin: 4px 0; }
    </style>
</head>
<body>
    <h2>작업조건 선택 저장</h2>

    <?php if ($message !== ""): ?>
        <p><strong><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php endif; ?>

    <form method="post">
        <div class="group">
            <label>RA_ID</label><br>
            <input type="number" name="ra_id" value="<?php echo (int)$ra_id; ?>" required>
        </div>

        <div class="group">
            <h3>작업대상</h3>
            <?php foreach ($target_list as $row): ?>
                <div class="item">
                    <label>
                        <input type="checkbox" name="target_ids[]" value="<?php echo (int)$row["target_id"]; ?>">
                        <?php echo htmlspecialchars($row["major_category"] . " / " . $row["sub_category"] . " / " . $row["work_type"], ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="group">
            <h3>환경</h3>
            <?php foreach ($env_list as $row): ?>
                <div class="item">
                    <label>
                        <input type="checkbox" name="env_ids[]" value="<?php echo (int)$row["env_id"]; ?>">
                        <?php echo htmlspecialchars($row["env_name"], ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="group">
            <h3>공구</h3>
            <?php foreach ($tool_list as $row): ?>
                <div class="item">
                    <label>
                        <input type="checkbox" name="tool_ids[]" value="<?php echo (int)$row["tool_id"]; ?>">
                        <?php echo htmlspecialchars($row["tool_name"], ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="group">
            <h3>중대작업</h3>
            <?php foreach ($major_list as $row): ?>
                <div class="item">
                    <label>
                        <input type="checkbox" name="major_work_ids[]" value="<?php echo (int)$row["major_work_id"]; ?>">
                        <?php echo htmlspecialchars($row["major_work_name"], ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="submit">작업조건 저장</button>
        </p>
    </form>
</body>
</html>