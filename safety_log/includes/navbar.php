<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navItems = [
    'dashboard.php' => '대시보드',
    'create.php' => '업무일지 등록',
    'index.php' => '업무일지 목록',
    'monthly_report.php' => '월별 집계',
    'monthly_print.php' => '월간 출력',
];
?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">업무일지</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#safetyLogNavbar" aria-controls="safetyLogNavbar" aria-expanded="false" aria-label="메뉴 토글">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="safetyLogNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($navItems as $file => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === $file ? 'active' : '' ?>" href="<?= h($file) ?>"><?= h($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>
