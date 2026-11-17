<?php
declare(strict_types=1);

function csm_headcount_date(string $value): ?string
{
    if (!preg_match('/^(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})\.?$/D', trim($value), $parts)) return null;
    if (!checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) return null;
    return sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
}

function csm_headcount_classify(array $employee, string $date): array
{
    $rawJoin = trim((string)($employee['join_date'] ?? ''));
    $rawExit = trim((string)($employee['retired_at'] ?? ''));
    $join = csm_headcount_date($rawJoin);
    $exit = csm_headcount_date($rawExit);
    $retired = ($employee['employment_status'] ?? '') === 'retired' || (int)($employee['is_active'] ?? 1) === 0;
    if ($join !== null && $exit !== null && $exit < $join) return ['state' => 'unknown', 'reason' => '퇴사일이 입사일보다 빠름'];
    if ($join !== null && $join > $date) return ['state' => 'excluded', 'reason' => '기준일 이후 입사'];
    if ($exit !== null && $exit <= $date) return ['state' => 'excluded', 'reason' => '기준일 이전 또는 당일 퇴사'];
    if ($join === null) return ['state' => 'unknown', 'reason' => $rawJoin === '' ? '입사일 미등록' : '입사일 형식 확인 필요'];
    if ($rawExit !== '' && $exit === null) return ['state' => 'unknown', 'reason' => '퇴사일 형식 확인 필요'];
    if ($retired && $exit === null) return ['state' => 'unknown', 'reason' => '퇴사 처리됨 · 퇴사일 미등록'];
    return ['state' => 'confirmed', 'reason' => '입사일·퇴사일 기준 재직 확인'];
}

function csm_headcount_rows(): array
{
    $path = dirname(__DIR__) . '/employees/employees.db';
    if (!is_file($path)) throw new RuntimeException('Employee database not found');
    $db = new PDO('sqlite:file:' . str_replace('\\', '/', $path) . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $db->query("SELECT id,employee_no,name,team,join_date,retired_at,employment_status,is_active FROM employees WHERE COALESCE(employee_no,'') <> 'admin01' ORDER BY team,name,id")->fetchAll();
}
