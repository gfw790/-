<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navItems = [
    'dashboard.php'      => '대시보드',
    'create.php'         => '업무일지 등록',
    'index.php'          => '업무일지 목록',
    'monthly_report.php' => '월별 집계',
    'monthly_print.php'  => '월간 출력',
];
?>
<div class="sl-topbar">
    <div class="sl-topbar-inner">
        <a class="sl-brand-title" href="dashboard.php">
            <div class="sl-brand-label">SAFETY LOG &middot; SYSTEM</div>
            안전관리자 <span>업무일지</span>
        </a>
        <button class="sl-nav-toggle" aria-label="메뉴 열기" onclick="
            var nav = document.getElementById('slNav');
            nav.classList.toggle('is-open');
        ">&#9776;</button>
        <nav class="sl-nav" id="slNav">
            <?php foreach ($navItems as $file => $label): ?>
                <a href="<?= h($file) ?>"
                   class="sl-nav-link<?= $currentPage === $file ? ' is-active' : '' ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
            <a href="../risk_assessment/work_list.php" class="sl-nav-link">
                &larr; 작업목록
            </a>
        </nav>
    </div>
</div>
