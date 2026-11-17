<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/activity_monitor_store.php';
$template = [
    ['id' => 'a', 'name' => 'planned', 'unit' => '건'],
    ['id' => 'b', 'name' => 'no plan', 'unit' => '건'],
    ['id' => 'c', 'name' => 'no result', 'unit' => '건'],
    ['id' => 'd', 'name' => 'zero result', 'unit' => '건'],
    ['id' => 'e', 'name' => 'not applicable', 'unit' => '건'],
];
$values = [
    'a' => ['plan' => '3', 'p1' => '1', 'm1' => '2'],
    'b' => ['plan' => '3', 'p1' => '', 'm1' => '1'],
    'c' => ['plan' => '1', 'p1' => '1', 'm1' => ''],
    'd' => ['plan' => '0', 'p1' => '0', 'm1' => '0'],
    'e' => ['plan' => '2', 'p1' => '', 'm1' => '-'],
];
$rows = activity_monitor_month_rows($template, $values, 'm1');
if (array_column($rows, 'id') !== ['a', 'c', 'd'] || $rows[1]['value'] !== '') throw new RuntimeException('Monthly plan/result filter failed');
require_once dirname(__DIR__, 2) . '/safety_manual/quarter_plan_storage.php';
$quarterRows = quarter_plan_defaults(2026, 2);
$quarterRows['qr7']['p1'] = '1';
$quarterRows['qr7']['m1'] = '0';
$validated = quarter_plan_validate($quarterRows);
if ($validated['qr7']['p1'] !== '1' || $validated['qr7']['m1'] !== '0') throw new RuntimeException('Monthly plan validation failed');
unset($quarterRows['qr7']['p1']);
if (quarter_plan_validate($quarterRows)['qr7']['p1'] !== '') throw new RuntimeException('Legacy quarter rows must load without monthly plans');
if (quarter_plan_percent_display('1') !== '100' || quarter_plan_percent_store('100') !== '1') throw new RuntimeException('Percentage input conversion failed');
if (activity_monitor_display_quantity(['id' => 'qr8', 'value' => '1'], 'value') !== '100') throw new RuntimeException('Monthly percentage display failed');
if (activity_monitor_display_quantity(['name' => '안전활동평가(CSM) 개선율', 'result' => '1'], 'result') !== '100') throw new RuntimeException('Stored quarter percentage display failed');
echo "activity monitor monthly filter OK\n";
