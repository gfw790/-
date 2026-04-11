<?php
declare(strict_types=1);

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$carNumber  = trim($_POST['car_number'] ?? '');
$driverName = trim($_POST['driver_name'] ?? '');

$year = date('Y');

/* 좌표값 */
$leftForm = [
    'year'   => ['x' => 158,  'y' => 133],
    'car'    => ['x' => 188,  'y' => 174],
    'driver' => ['x' => 385,  'y' => 174],
];

$rightForm = [
    'year'   => ['x' => 700,  'y' => 133],
    'car'    => ['x' => 730,  'y' => 174],
    'driver' => ['x' => 920,  'y' => 174],
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>출입명부 출력</title>
<style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
        background: #f3f3f3;
    }

    .screen-wrap {
        max-width: 700px;
        margin: 40px auto;
        background: #fff;
        padding: 24px;
        border: 1px solid #ddd;
        border-radius: 10px;
    }

    .screen-wrap h1 {
        margin: 0 0 20px;
        font-size: 28px;
    }

    .form-group { margin-bottom: 14px; }

    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 700;
    }

    .form-group input {
        width: 100%;
        height: 44px;
        padding: 0 12px;
        font-size: 16px;
    }

    .btn-row {
        display: flex;
        gap: 10px;
        margin-top: 18px;
    }

    .btn {
        height: 44px;
        padding: 0 18px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn.secondary {
        background: #fff;
        color: #111;
    }

    .print-tools {
        text-align: center;
        padding: 16px 0;
    }

    .page {
        width: 297mm;
        height: 210mm;
        margin: 0 auto;
        position: relative;
        background: url('양식 출입명부001.png') no-repeat center center;
        background-size: 297mm 210mm;
        overflow: hidden;
    }

    .txt {
        position: absolute;
        color: #000;
        white-space: nowrap;
        line-height: 1;
    }

    .year {
        font-size: 18px;
        font-weight: 500;
        width: 60px;
        text-align: center;
    }

    .car, .driver {
        font-size: 18px;
        font-weight: 500;
    }

    @page {
        size: A4 landscape;
        margin: 0;
    }

    @media print {
        body { background: #fff; }
        .screen-wrap, .print-tools { display: none !important; }
        .page { margin: 0; }
    }
</style>
</head>
<body>

<?php if (!$isPost): ?>
    <div class="screen-wrap">
        <h1>출입명부 출력</h1>

        <form method="post">
            <div class="form-group">
                <label for="car_number">차량번호</label>
                <input type="text" id="car_number" name="car_number" placeholder="예: 3048">
            </div>

            <div class="form-group">
                <label for="driver_name">운전자</label>
                <input type="text" id="driver_name" name="driver_name" placeholder="예: 김남균">
            </div>

            <div class="btn-row">
                <button type="submit" class="btn">출력 화면 만들기</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="print-tools">
        <button class="btn" onclick="window.print()">인쇄하기</button>
        <a href="<?= h($_SERVER['PHP_SELF'] ?? 'print_register.php') ?>" class="btn secondary">다시 입력</a>
    </div>

    <div class="page">
        <!-- 왼쪽 -->
        <div class="txt year"
             style="left: <?= $leftForm['year']['x'] ?>px; top: <?= $leftForm['year']['y'] ?>px;">
            <?= h($year) ?>
        </div>

        <div class="txt car"
             style="left: <?= $leftForm['car']['x'] ?>px; top: <?= $leftForm['car']['y'] ?>px;">
            <?= h($carNumber) ?>
        </div>

        <div class="txt driver"
             style="left: <?= $leftForm['driver']['x'] ?>px; top: <?= $leftForm['driver']['y'] ?>px;">
            <?= h($driverName) ?>
        </div>

        <!-- 오른쪽 -->
        <div class="txt year"
             style="left: <?= $rightForm['year']['x'] ?>px; top: <?= $rightForm['year']['y'] ?>px;">
            <?= h($year) ?>
        </div>

        <div class="txt car"
             style="left: <?= $rightForm['car']['x'] ?>px; top: <?= $rightForm['car']['y'] ?>px;">
            <?= h($carNumber) ?>
        </div>

        <div class="txt driver"
             style="left: <?= $rightForm['driver']['x'] ?>px; top: <?= $rightForm['driver']['y'] ?>px;">
            <?= h($driverName) ?>
        </div>
    </div>
<?php endif; ?>

</body>
</html>