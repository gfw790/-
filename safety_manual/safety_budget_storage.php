<?php
declare(strict_types=1);

function safety_budget_categories(): array
{
    return [
        'safety_manager_wage' => '안전·보건관리자 임금 등',
        'safety_facility' => '안전시설비 등',
        'ppe' => '보호구 등',
        'diagnosis' => '안전보건진단비 등',
        'education' => '안전보건교육비 등',
        'health_prevention' => '근로자 건강장해예방비 등',
        'guidance_fee' => '건설재해예방전문지도기관 기술지도비',
        'hq_org_wage' => '본사 전담조직 근로자 임금 등',
        'risk_assessment_cost' => '위험성평가 등에 따른 소요비용',
    ];
}

function safety_budget_category_default_row_count(): int
{
    return 1;
}

function safety_budget_category_max_rows(): int
{
    return 200;
}

function safety_budget_initialize(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_budget_general_info (
        info_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
        company_name VARCHAR(200) NOT NULL DEFAULT \'\',
        plan_year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        period_start DATE NULL,
        period_end DATE NULL,
        prepared_date DATE NULL,
        total_planned_amount BIGINT NOT NULL DEFAULT 0,
        plan_type_annual TINYINT(1) NOT NULL DEFAULT 0,
        plan_type_change TINYINT(1) NOT NULL DEFAULT 0,
        preparer_name VARCHAR(100) NOT NULL DEFAULT \'\',
        reviewer_name VARCHAR(100) NOT NULL DEFAULT \'\',
        updated_by VARCHAR(100) NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $generalInfoColumns = [
        'company_name' => "VARCHAR(200) NOT NULL DEFAULT ''",
        'plan_year' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        'period_start' => 'DATE NULL',
        'period_end' => 'DATE NULL',
        'total_planned_amount' => 'BIGINT NOT NULL DEFAULT 0',
        'plan_type_annual' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'plan_type_change' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'preparer_name' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'reviewer_name' => "VARCHAR(100) NOT NULL DEFAULT ''",
    ];
    foreach ($generalInfoColumns as $columnName => $columnDefinition) {
        if (!$db->query("SHOW COLUMNS FROM safety_budget_general_info LIKE '$columnName'")->fetch()) {
            try {
                $db->exec("ALTER TABLE safety_budget_general_info ADD COLUMN $columnName $columnDefinition");
            } catch (PDOException $error) {
                if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
            }
        }
    }

    $db->exec('CREATE TABLE IF NOT EXISTS safety_budget_detail_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        detail_year SMALLINT UNSIGNED NOT NULL,
        category VARCHAR(40) NOT NULL,
        sort_order INT UNSIGNED NOT NULL,
        item_name VARCHAR(200) NOT NULL DEFAULT \'\',
        unit VARCHAR(40) NOT NULL DEFAULT \'\',
        quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
        unit_price BIGINT NOT NULL DEFAULT 0,
        amount BIGINT NOT NULL DEFAULT 0,
        calculation_note VARCHAR(500) NOT NULL DEFAULT \'\',
        usage_period VARCHAR(100) NOT NULL DEFAULT \'\',
        updated_by VARCHAR(100) NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY detail_year_category_idx (detail_year, category, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!$db->query("SHOW COLUMNS FROM safety_budget_detail_items LIKE 'unit_price'")->fetch()) {
        try {
            $db->exec('ALTER TABLE safety_budget_detail_items ADD COLUMN unit_price BIGINT NOT NULL DEFAULT 0 AFTER quantity');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }

    $db->exec('CREATE TABLE IF NOT EXISTS safety_budget_usage_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        usage_year SMALLINT UNSIGNED NOT NULL,
        usage_month TINYINT UNSIGNED NOT NULL,
        sort_order INT UNSIGNED NOT NULL,
        spent_date DATE NULL,
        item_name VARCHAR(200) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT \'\',
        amount BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(500) NOT NULL DEFAULT \'\',
        updated_by VARCHAR(100) NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY usage_month_idx (usage_year, usage_month, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function safety_budget_general_load(PDO $db, int $year): ?array
{
    $stmt = $db->prepare('SELECT company_name, plan_year, period_start, period_end, prepared_date, total_planned_amount, plan_type_annual, plan_type_change, preparer_name, reviewer_name FROM safety_budget_general_info WHERE info_year = ?');
    $stmt->execute([$year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['period_start'] = $row['period_start'] ?? '';
    $row['period_end'] = $row['period_end'] ?? '';
    $row['prepared_date'] = $row['prepared_date'] ?? '';
    $row['plan_type_annual'] = (bool)$row['plan_type_annual'];
    $row['plan_type_change'] = (bool)$row['plan_type_change'];
    $row['plan_year'] = (int)$row['plan_year'];
    $row['total_planned_amount'] = (int)$row['total_planned_amount'];
    return $row;
}

function safety_budget_general_defaults(int $year): array
{
    return [
        'company_name' => '(주)현대기전',
        'plan_year' => $year,
        'period_start' => '',
        'period_end' => '',
        'prepared_date' => '',
        'total_planned_amount' => 0,
        'plan_type_annual' => false,
        'plan_type_change' => false,
        'preparer_name' => '',
        'reviewer_name' => '',
    ];
}

function safety_budget_general_validate(mixed $input): array
{
    if (!is_array($input)) {
        throw new InvalidArgumentException('일반사항 입력을 확인해 주세요.');
    }
    $companyName = trim((string)($input['company_name'] ?? ''));
    if (mb_strlen($companyName, 'UTF-8') > 200) {
        throw new InvalidArgumentException('회사명은 200자 이내로 입력해 주세요.');
    }
    $planYear = filter_var($input['plan_year'] ?? 0, FILTER_VALIDATE_INT);
    if ($planYear === false || $planYear < 1000 || $planYear > 9999) {
        throw new InvalidArgumentException('계획년도를 올바르게 입력해 주세요.');
    }
    $dates = [];
    foreach (['period_start', 'period_end', 'prepared_date'] as $key) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$parsed || $parsed->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('날짜를 올바르게 입력해 주세요.');
            }
        }
        $dates[$key] = $value;
    }
    $totalPlannedAmount = filter_var($input['total_planned_amount'] ?? 0, FILTER_VALIDATE_INT);
    if ($totalPlannedAmount === false || $totalPlannedAmount < 0) {
        throw new InvalidArgumentException('총 계획금액을 올바르게 입력해 주세요.');
    }
    $preparerName = trim((string)($input['preparer_name'] ?? ''));
    $reviewerName = trim((string)($input['reviewer_name'] ?? ''));
    if (mb_strlen($preparerName, 'UTF-8') > 100 || mb_strlen($reviewerName, 'UTF-8') > 100) {
        throw new InvalidArgumentException('작성/검토자 정보는 100자 이내로 입력해 주세요.');
    }
    return [
        'company_name' => $companyName,
        'plan_year' => $planYear,
        'total_planned_amount' => $totalPlannedAmount,
        'plan_type_annual' => !empty($input['plan_type_annual']),
        'plan_type_change' => !empty($input['plan_type_change']),
        'preparer_name' => $preparerName,
        'reviewer_name' => $reviewerName,
    ] + $dates;
}

function safety_budget_general_save(PDO $db, int $year, array $data, string $editor): void
{
    $stmt = $db->prepare('INSERT INTO safety_budget_general_info
        (info_year, company_name, plan_year, period_start, period_end, prepared_date, total_planned_amount, plan_type_annual, plan_type_change, preparer_name, reviewer_name, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), plan_year = VALUES(plan_year),
            period_start = VALUES(period_start), period_end = VALUES(period_end), prepared_date = VALUES(prepared_date),
            total_planned_amount = VALUES(total_planned_amount), plan_type_annual = VALUES(plan_type_annual),
            plan_type_change = VALUES(plan_type_change), preparer_name = VALUES(preparer_name),
            reviewer_name = VALUES(reviewer_name), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    $stmt->execute([
        $year, $data['company_name'], $data['plan_year'],
        $data['period_start'] !== '' ? $data['period_start'] : null,
        $data['period_end'] !== '' ? $data['period_end'] : null,
        $data['prepared_date'] !== '' ? $data['prepared_date'] : null,
        $data['total_planned_amount'], $data['plan_type_annual'] ? 1 : 0, $data['plan_type_change'] ? 1 : 0,
        $data['preparer_name'], $data['reviewer_name'],
        $editor, $now,
    ]);
}

function safety_budget_category_totals(PDO $db, int $year): array
{
    $totals = array_fill_keys(array_keys(safety_budget_categories()), 0);
    $stmt = $db->prepare('SELECT category, SUM(amount) AS total FROM safety_budget_detail_items WHERE detail_year = ? GROUP BY category');
    $stmt->execute([$year]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['category'], $totals)) {
            $totals[$row['category']] = (int)$row['total'];
        }
    }
    return $totals;
}

function safety_budget_detail_load(PDO $db, int $year): array
{
    $categories = array_keys(safety_budget_categories());
    $defaultCount = safety_budget_category_default_row_count();
    $grid = [];
    foreach ($categories as $category) {
        $grid[$category] = [];
        for ($i = 0; $i < $defaultCount; $i++) {
            $grid[$category][$i] = ['item_name' => '', 'unit' => '', 'quantity' => 0, 'unit_price' => 0, 'amount' => 0, 'calculation_note' => '', 'usage_period' => ''];
        }
    }
    $stmt = $db->prepare('SELECT category, sort_order, item_name, unit, quantity, unit_price, amount, calculation_note, usage_period FROM safety_budget_detail_items WHERE detail_year = ? ORDER BY category ASC, sort_order ASC');
    $stmt->execute([$year]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $category = $row['category'];
        if (!isset($grid[$category])) continue;
        $sortOrder = (int)$row['sort_order'];
        // Make sure every slot up to this saved row exists, in case more rows were added than the default.
        for ($i = count($grid[$category]); $i <= $sortOrder; $i++) {
            $grid[$category][$i] = ['item_name' => '', 'unit' => '', 'quantity' => 0, 'unit_price' => 0, 'amount' => 0, 'calculation_note' => '', 'usage_period' => ''];
        }
        $grid[$category][$sortOrder] = [
            'item_name' => $row['item_name'],
            'unit' => $row['unit'],
            'quantity' => (float)$row['quantity'],
            'unit_price' => (int)$row['unit_price'],
            'amount' => (int)$row['amount'],
            'calculation_note' => $row['calculation_note'],
            'usage_period' => $row['usage_period'],
        ];
    }
    return $grid;
}

function safety_budget_detail_validate(mixed $input): array
{
    if (!is_array($input) || count($input) > 1000) {
        throw new InvalidArgumentException('세부 사용계획 입력을 확인해 주세요.');
    }
    $categories = safety_budget_categories();
    $maxRows = safety_budget_category_max_rows();
    $rows = [];
    foreach ($input as $row) {
        if (!is_array($row)) continue;
        $category = (string)($row['category'] ?? '');
        if (!array_key_exists($category, $categories)) {
            throw new InvalidArgumentException('항목 구분을 확인해 주세요.');
        }
        $sortOrder = filter_var($row['sort_order'] ?? -1, FILTER_VALIDATE_INT);
        if ($sortOrder === false || $sortOrder < 0 || $sortOrder >= $maxRows) {
            throw new InvalidArgumentException('세부항목 위치를 확인해 주세요.');
        }
        $itemName = trim((string)($row['item_name'] ?? ''));
        if ($itemName === '') continue;
        if (mb_strlen($itemName, 'UTF-8') > 200) {
            throw new InvalidArgumentException('세부항목명은 200자 이내로 입력해 주세요.');
        }
        $unit = trim((string)($row['unit'] ?? ''));
        if (mb_strlen($unit, 'UTF-8') > 40) {
            throw new InvalidArgumentException('단위는 40자 이내로 입력해 주세요.');
        }
        $quantity = filter_var($row['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($quantity === false || $quantity < 0) {
            throw new InvalidArgumentException('수량을 올바르게 입력해 주세요.');
        }
        $unitPrice = filter_var($row['unit_price'] ?? 0, FILTER_VALIDATE_INT);
        if ($unitPrice === false || $unitPrice < 0) {
            throw new InvalidArgumentException('단가를 올바르게 입력해 주세요.');
        }
        $amount = filter_var($row['amount'] ?? 0, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0) {
            throw new InvalidArgumentException('금액을 올바르게 입력해 주세요.');
        }
        $calculationNote = trim((string)($row['calculation_note'] ?? ''));
        if (mb_strlen($calculationNote, 'UTF-8') > 500) {
            throw new InvalidArgumentException('산출명세는 500자 이내로 입력해 주세요.');
        }
        $usagePeriod = trim((string)($row['usage_period'] ?? ''));
        if (mb_strlen($usagePeriod, 'UTF-8') > 100) {
            throw new InvalidArgumentException('사용시기는 100자 이내로 입력해 주세요.');
        }
        $rows[] = [
            'category' => $category, 'sort_order' => $sortOrder, 'item_name' => $itemName, 'unit' => $unit,
            'quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => $amount,
            'calculation_note' => $calculationNote, 'usage_period' => $usagePeriod,
        ];
    }
    return $rows;
}

function safety_budget_detail_save(PDO $db, int $year, array $rows, string $editor): void
{
    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM safety_budget_detail_items WHERE detail_year = ?');
        $delete->execute([$year]);
        $insert = $db->prepare('INSERT INTO safety_budget_detail_items
            (detail_year, category, sort_order, item_name, unit, quantity, unit_price, amount, calculation_note, usage_period, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $insert->execute([
                $year, $row['category'], $row['sort_order'], $row['item_name'], $row['unit'],
                $row['quantity'], $row['unit_price'], $row['amount'], $row['calculation_note'], $row['usage_period'], $editor, $now,
            ]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function safety_budget_usage_load(PDO $db, int $year, int $month): array
{
    $stmt = $db->prepare('SELECT spent_date, item_name, description, amount, note FROM safety_budget_usage_items WHERE usage_year = ? AND usage_month = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$year, $month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['spent_date'] = $row['spent_date'] ?? '';
    }
    unset($row);
    return $rows;
}

function safety_budget_usage_validate(mixed $input): array
{
    if (!is_array($input) || count($input) > 500) {
        throw new InvalidArgumentException('사용내역 입력을 확인해 주세요.');
    }
    $rows = [];
    foreach ($input as $row) {
        if (!is_array($row)) continue;
        $itemName = trim((string)($row['item_name'] ?? ''));
        if ($itemName === '') continue;
        if (mb_strlen($itemName, 'UTF-8') > 200) {
            throw new InvalidArgumentException('항목명은 200자 이내로 입력해 주세요.');
        }
        $date = trim((string)($row['spent_date'] ?? ''));
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('집행일자를 올바르게 입력해 주세요.');
            }
        }
        $description = trim((string)($row['description'] ?? ''));
        if (mb_strlen($description, 'UTF-8') > 500) {
            throw new InvalidArgumentException('내용은 500자 이내로 입력해 주세요.');
        }
        $amount = filter_var($row['amount'] ?? 0, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0) {
            throw new InvalidArgumentException('금액을 올바르게 입력해 주세요.');
        }
        $note = trim((string)($row['note'] ?? ''));
        if (mb_strlen($note, 'UTF-8') > 500) {
            throw new InvalidArgumentException('비고는 500자 이내로 입력해 주세요.');
        }
        $rows[] = ['spent_date' => $date, 'item_name' => $itemName, 'description' => $description, 'amount' => $amount, 'note' => $note];
    }
    return $rows;
}

function safety_budget_usage_save(PDO $db, int $year, int $month, array $rows, string $editor): void
{
    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM safety_budget_usage_items WHERE usage_year = ? AND usage_month = ?');
        $delete->execute([$year, $month]);
        $insert = $db->prepare('INSERT INTO safety_budget_usage_items
            (usage_year, usage_month, sort_order, spent_date, item_name, description, amount, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        foreach (array_values($rows) as $index => $row) {
            $insert->execute([$year, $month, $index, $row['spent_date'] !== '' ? $row['spent_date'] : null, $row['item_name'], $row['description'], $row['amount'], $row['note'], $editor, $now]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
