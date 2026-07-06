<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = mm_authorized_user();
$pdo = mm_get_pdo();
$materials = mm_fetch_material_options($pdo);

$stmt = $pdo->query("
    SELECT
        SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) AS total_in,
        SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) AS total_out
    FROM material_management_movements
");
$movementSummary = $stmt->fetch() ?: [];

$activeCount = 0;
$stockSum = 0.0;
foreach ($materials as $material) {
    if ((int)($material['is_active'] ?? 1) === 1) {
        $activeCount += 1;
    }
    $stockSum += (float)($material['current_stock'] ?? 0);
}

mm_page_header('제품관리', '색상락카, 페인트, 신나, 징크코트락카, 윤활방청락카, 휘발유, 경유, LPG, 산소, 용접봉 같은 제품/자재 DB를 관리합니다.');
?>
<div class="summary-grid">
    <div class="summary-card">
        <strong>등록 품목</strong>
        <span><?= number_format(count($materials)) ?></span>
    </div>
    <div class="summary-card">
        <strong>사용중 품목</strong>
        <span><?= number_format($activeCount) ?></span>
    </div>
    <div class="summary-card">
        <strong>누적 입고량</strong>
        <span><?= mm_format_quantity($movementSummary['total_in'] ?? 0) ?></span>
    </div>
    <div class="summary-card">
        <strong>현재 총 재고</strong>
        <span><?= mm_format_quantity($stockSum) ?></span>
    </div>
</div>

<div class="cards">
    <a class="nav-card" href="/material_management/inbound.php">
        <h2>입고 페이지</h2>
        <p>새 품목 등록과 입고 수량 반영을 함께 처리합니다. 공급처와 입고일을 기록하며 문서번호는 저장 시 자동 생성됩니다.</p>
    </a>
    <a class="nav-card" href="/material_management/outbound.php">
        <h2>출고 페이지</h2>
        <p>등록된 품목을 선택하여 출고 수량을 기록합니다. 현재고보다 많이 출고되지 않도록 체크하며 문서번호는 저장 시 자동 생성됩니다.</p>
    </a>
    <a class="nav-card" href="/material_management/status.php">
        <h2>현황 페이지</h2>
        <p>현재 재고, 제품 기본정보, 최근 입출고 이력을 한 번에 조회합니다.</p>
    </a>
</div>
<?php
mm_page_footer();
