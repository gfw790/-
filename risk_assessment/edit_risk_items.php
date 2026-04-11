<?php
require_once __DIR__ . "/config/db.php";

$ra_id = isset($_GET["ra_id"]) ? (int)$_GET["ra_id"] : 0;
$message = "";

if ($ra_id <= 0) {
    die("유효한 ra_id가 필요합니다.");
}

$header = null;
$header_stmt = $conn->prepare("SELECT ra_id, work_date, work_title, work_location FROM ra_header WHERE ra_id = ?");
$header_stmt->bind_param("i", $ra_id);
$header_stmt->execute();
$header_result = $header_stmt->get_result();
$header = $header_result->fetch_assoc();
$header_stmt->close();

if (!$header) {
    die("해당 문서를 찾을 수 없습니다.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $item_ids = $_POST["item_id"] ?? [];

    $sql = "
        UPDATE risk_assessment_item
        SET
            likelihood_before = ?,
            severity_before = ?,
            risk_score_before = ?,
            control_text = ?,
            likelihood_after = ?,
            severity_after = ?,
            risk_score_after = ?,
            improvement_plan = ?,
            improvement_due_date = ?,
            likelihood_final = ?,
            severity_final = ?,
            risk_score_final = ?
        WHERE id = ? AND ra_id = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("SQL 준비 실패: " . $conn->error);
    }

    for ($i = 0; $i < count($item_ids); $i++) {
        $item_id = (int)$item_ids[$i];

        $lb = ($_POST["likelihood_before"][$i] ?? "") !== "" ? (int)$_POST["likelihood_before"][$i] : null;
        $sb = ($_POST["severity_before"][$i] ?? "") !== "" ? (int)$_POST["severity_before"][$i] : null;
        $rb = ($lb !== null && $sb !== null) ? $lb * $sb : null;

        $control_text = trim($_POST["control_text"][$i] ?? "");

        $la = ($_POST["likelihood_after"][$i] ?? "") !== "" ? (int)$_POST["likelihood_after"][$i] : null;
        $sa = ($_POST["severity_after"][$i] ?? "") !== "" ? (int)$_POST["severity_after"][$i] : null;
        $ra = ($la !== null && $sa !== null) ? $la * $sa : null;

        $improvement_plan = trim($_POST["improvement_plan"][$i] ?? "");
        $improvement_due_date = trim($_POST["improvement_due_date"][$i] ?? "");
        $improvement_due_date = $improvement_due_date !== "" ? $improvement_due_date : null;

        $lf = ($_POST["likelihood_final"][$i] ?? "") !== "" ? (int)$_POST["likelihood_final"][$i] : null;
        $sf = ($_POST["severity_final"][$i] ?? "") !== "" ? (int)$_POST["severity_final"][$i] : null;
        $rf = ($lf !== null && $sf !== null) ? $lf * $sf : null;

        $stmt->bind_param(
            "iiisiiissiiiii",
            $lb,
            $sb,
            $rb,
            $control_text,
            $la,
            $sa,
            $ra,
            $improvement_plan,
            $improvement_due_date,
            $lf,
            $sf,
            $rf,
            $item_id,
            $ra_id
        );

        $stmt->execute();
    }

    $stmt->close();
    $message = "위험성평가 항목이 저장되었습니다.";
}

$item_sql = "
    SELECT
        id,
        hazard_id,
        hazard_text,
        likelihood_before,
        severity_before,
        risk_score_before,
        control_text,
        likelihood_after,
        severity_after,
        risk_score_after,
        improvement_plan,
        improvement_due_date,
        likelihood_final,
        severity_final,
        risk_score_final
    FROM risk_assessment_item
    WHERE ra_id = ?
    ORDER BY id ASC
";
$item_stmt = $conn->prepare($item_sql);
$item_stmt->bind_param("i", $ra_id);
$item_stmt->execute();
$item_result = $item_stmt->get_result();

$items = [];
while ($row = $item_result->fetch_assoc()) {
    $items[] = $row;
}
$item_stmt->close();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>위험성평가 입력</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; min-width: 1600px; }
        th, td { border: 1px solid #ccc; padding: 6px; vertical-align: top; }
        th { background: #f3f3f3; }
        input[type="number"] { width: 60px; }
        input[type="date"] { width: 140px; }
        textarea { width: 220px; height: 70px; }
        .wrap { overflow-x: auto; }
    </style>
</head>
<body>
    <h2>위험성평가 입력</h2>

    <p><strong>RA_ID:</strong> <?php echo (int)$header["ra_id"]; ?></p>
    <p><strong>작업일자:</strong> <?php echo h($header["work_date"]); ?></p>
    <p><strong>작업명:</strong> <?php echo h($header["work_title"]); ?></p>
    <p><strong>작업위치:</strong> <?php echo h($header["work_location"]); ?></p>

    <?php if ($message !== ""): ?>
        <p><strong><?php echo h($message); ?></strong></p>
    <?php endif; ?>

    <form method="post">
        <div class="wrap">
            <table>
                <thead>
                    <tr>
                        <th>항목ID</th>
                        <th>위험요인</th>
                        <th>개선전 P</th>
                        <th>개선전 D</th>
                        <th>개선전 점수</th>
                        <th>현재 조치내용</th>
                        <th>조치후 P</th>
                        <th>조치후 D</th>
                        <th>조치후 점수</th>
                        <th>추가 개선계획</th>
                        <th>개선일자</th>
                        <th>개선후 P</th>
                        <th>개선후 D</th>
                        <th>개선후 점수</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td>
                                <?php echo (int)$item["id"]; ?>
                                <input type="hidden" name="item_id[]" value="<?php echo (int)$item["id"]; ?>">
                            </td>
                            <td><?php echo h($item["hazard_text"]); ?></td>

                            <td><input type="number" name="likelihood_before[]" min="1" max="5" value="<?php echo h($item["likelihood_before"]); ?>"></td>
                            <td><input type="number" name="severity_before[]" min="1" max="4" value="<?php echo h($item["severity_before"]); ?>"></td>
                            <td><?php echo h($item["risk_score_before"]); ?></td>

                            <td><textarea name="control_text[]"><?php echo h($item["control_text"]); ?></textarea></td>

                            <td><input type="number" name="likelihood_after[]" min="1" max="5" value="<?php echo h($item["likelihood_after"]); ?>"></td>
                            <td><input type="number" name="severity_after[]" min="1" max="4" value="<?php echo h($item["severity_after"]); ?>"></td>
                            <td><?php echo h($item["risk_score_after"]); ?></td>

                            <td><textarea name="improvement_plan[]"><?php echo h($item["improvement_plan"]); ?></textarea></td>
                            <td><input type="date" name="improvement_due_date[]" value="<?php echo h($item["improvement_due_date"]); ?>"></td>

                            <td><input type="number" name="likelihood_final[]" min="1" max="5" value="<?php echo h($item["likelihood_final"]); ?>"></td>
                            <td><input type="number" name="severity_final[]" min="1" max="4" value="<?php echo h($item["severity_final"]); ?>"></td>
                            <td><?php echo h($item["risk_score_final"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p style="margin-top:16px;">
            <button type="submit">평가 저장</button>
        </p>
    </form>
</body>
</html>