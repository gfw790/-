<?php
require_once __DIR__ . "/config/db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $work_date = $_POST["work_date"] ?? "";
    $work_title = trim($_POST["work_title"] ?? "");
    $work_location = trim($_POST["work_location"] ?? "");
    $contractor_name = trim($_POST["contractor_name"] ?? "");
    $manager_name = trim($_POST["manager_name"] ?? "");
    $leader_name = trim($_POST["leader_name"] ?? "");
    $remark = trim($_POST["remark"] ?? "");

    if ($work_date === "" || $work_title === "") {
        $message = "작업일자와 작업명은 필수입니다.";
    } else {
        $sql = "INSERT INTO ra_header (
                    work_date,
                    work_title,
                    work_location,
                    contractor_name,
                    manager_name,
                    leader_name,
                    remark
                ) VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            die("SQL 준비 실패: " . $conn->error);
        }

        $stmt->bind_param(
            "sssssss",
            $work_date,
            $work_title,
            $work_location,
            $contractor_name,
            $manager_name,
            $leader_name,
            $remark
        );

        if ($stmt->execute()) {
            $new_ra_id = $stmt->insert_id;
            $message = "위험성평가 문서가 생성되었습니다. RA_ID = " . $new_ra_id;
        } else {
            $message = "저장 실패: " . $stmt->error;
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>위험성평가 문서 생성</title>
</head>
<body>
    <h2>위험성평가 문서 생성</h2>

    <?php if ($message !== ""): ?>
        <p><strong><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php endif; ?>

    <form method="post">
        <p>
            <label>작업일자</label><br>
            <input type="date" name="work_date" required>
        </p>

        <p>
            <label>작업명</label><br>
            <input type="text" name="work_title" required style="width:300px;">
        </p>

        <p>
            <label>작업위치</label><br>
            <input type="text" name="work_location" style="width:300px;">
        </p>

        <p>
            <label>협력업체명</label><br>
            <input type="text" name="contractor_name" style="width:300px;">
        </p>

        <p>
            <label>관리자명</label><br>
            <input type="text" name="manager_name" style="width:300px;">
        </p>

        <p>
            <label>작업책임자명</label><br>
            <input type="text" name="leader_name" style="width:300px;">
        </p>

        <p>
            <label>비고</label><br>
            <textarea name="remark" rows="4" cols="60"></textarea>
        </p>

        <p>
            <button type="submit">문서 생성</button>
        </p>
    </form>
</body>
</html>