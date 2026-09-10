<?php
declare(strict_types=1);

function safety_policy_initialize(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_management_policies (
        policy_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
        policy_text MEDIUMTEXT NOT NULL,
        policy_date DATE NULL,
        goals_json MEDIUMTEXT NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!$db->query("SHOW COLUMNS FROM safety_management_policies LIKE 'policy_date'")->fetch()) {
        try {
            $db->exec('ALTER TABLE safety_management_policies ADD COLUMN policy_date DATE NULL AFTER policy_text');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }
}

function safety_policy_load(PDO $db, int $year): ?array
{
    $stmt = $db->prepare('SELECT policy_text, policy_date, goals_json FROM safety_management_policies WHERE policy_year = ?');
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['policy' => $row['policy_text'], 'date' => $row['policy_date'] ?? '', 'goals' => json_decode($row['goals_json'], true, 512, JSON_THROW_ON_ERROR)];
}

function safety_policy_validate(mixed $input): array
{
    if (!is_array($input) || !is_string($input['policy'] ?? null)
        || !is_array($input['goals'] ?? null) || count($input['goals']) < 5 || count($input['goals']) > 200) {
        throw new InvalidArgumentException('경영방침과 목표 입력을 확인해 주세요. 목표는 5개 이상, 최대 200개까지 저장할 수 있습니다.');
    }
    $policy = trim($input['policy']);
    if (mb_strlen($policy, 'UTF-8') > 20000) {
        throw new InvalidArgumentException('경영방침은 20,000자 이내로 입력해 주세요.');
    }
    $goals = [];
    foreach ($input['goals'] as $goal) {
        if (!is_string($goal) || mb_strlen($goal, 'UTF-8') > 2000) {
            throw new InvalidArgumentException('각 목표는 2,000자 이내로 입력해 주세요.');
        }
        $goals[] = trim($goal);
    }
    $date = $input['date'] ?? '';
    if (!is_string($date)) throw new InvalidArgumentException('날짜를 확인해 주세요.');
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int)$parsed->format('Y') < 1000) {
            throw new InvalidArgumentException('올바른 날짜를 입력해 주세요.');
        }
    }
    return ['policy' => $policy, 'date' => $date, 'goals' => $goals];
}

function safety_policy_save(PDO $db, array $records, string $editor): void
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO safety_management_policies
            (policy_year, policy_text, policy_date, goals_json, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE policy_text = VALUES(policy_text), goals_json = VALUES(goals_json),
                policy_date = VALUES(policy_date), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        foreach ($records as $year => $record) {
            $stmt->execute([$year, $record['policy'], ($record['date'] ?? '') !== '' ? $record['date'] : null, json_encode($record['goals'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $editor, $now]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
