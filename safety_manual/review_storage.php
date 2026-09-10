<?php
declare(strict_types=1);
function safety_review_items(): array
{
    return ['안전하고 쾌적한 작업환경 조성 의지 표명', '유해위험요인제거, 위험성 감소 주관부서행 및 안전보건경영시스템 지속적 개선 의지 표명', '조직의 규모와 여건에 적합 여부', '법적요구사항 및 그 밖의 요구사항 준수의지 표명', '대표자의 안전보건 경영철학 및 근로자의 참여 및 협의에 대한 의지 표명', '안전보건방침 간결하게 문서화 여부', '방침에 서명과 시행일 명기 여부', '조직구성원 및 이해관계자가 쉽게 접할 수 있게 공개여부'];
}
function safety_review_empty(): array { return ['review_date'=>'', 'policy_text' => '', 'items' => array_fill(0, 8, ['status' => '', 'note' => ''])]; }
function safety_review_initialize(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS safety_policy_reviews (review_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY, policy_text MEDIUMTEXT NOT NULL, items_json MEDIUMTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, updated_by VARCHAR(100) NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if(!$db->query("SHOW COLUMNS FROM safety_policy_reviews LIKE 'review_date'")->fetch()){
        try{$db->exec('ALTER TABLE safety_policy_reviews ADD COLUMN review_date DATE NULL AFTER review_year');}
        catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1060)throw $error;}
    }
}
function safety_review_load(PDO $db, int $year): ?array
{
    $stmt = $db->prepare('SELECT * FROM safety_policy_reviews WHERE review_year = ?');
    $stmt->execute([$year]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ['year' => (int)$row['review_year'], 'review_date'=>$row['review_date']??'', 'policy_text' => $row['policy_text'], 'items' => json_decode($row['items_json'], true, 512, JSON_THROW_ON_ERROR), 'revision' => (int)$row['revision'], 'updated_at' => $row['updated_at']];
}
function safety_review_validate(mixed $input): array
{
    if (!is_array($input) || !is_string($input['policy_text'] ?? null) || !is_array($input['items'] ?? null) || count($input['items']) !== 8) throw new InvalidArgumentException('방침·목표와 8개 검토 항목을 확인해 주세요.');
    $policy = trim($input['policy_text']);
    if (mb_strlen($policy, 'UTF-8') > 20000) throw new InvalidArgumentException('방침·목표는 20,000자 이내로 입력해 주세요.');
    $items = [];
    foreach (array_values($input['items']) as $item) {
        if (!is_array($item) || !in_array($item['status'] ?? null, ['', '적합', '보통', '부적합'], true) || !is_string($item['note'] ?? null) || mb_strlen($item['note'], 'UTF-8') > 2000) throw new InvalidArgumentException('적합유무를 선택하고 비고는 항목별 2,000자 이내로 입력해 주세요.');
        $items[] = ['status' => $item['status'], 'note' => trim($item['note'])];
    }
    $date=$input['review_date']??'';
    if(!is_string($date))throw new InvalidArgumentException('검토일자를 확인해 주세요.');
    if($date!==''){
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||(int)$parsed->format('Y')<1000)throw new InvalidArgumentException('올바른 검토일자를 입력해 주세요.');
    }
    return ['review_date'=>$date, 'policy_text' => $policy, 'items' => $items];
}
function safety_review_save(PDO $db, int $year, array $data, int $revision, string $editor): void
{
    $json = json_encode($data['items'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    if ($revision === 0) {
        try {
            $stmt = $db->prepare('INSERT INTO safety_policy_reviews (review_year, policy_text, items_json, updated_by, updated_at, review_date) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$year, $data['policy_text'], $json, $editor, $now, ($data['review_date']??'')?:null]);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1062) throw new InvalidArgumentException('다른 창에서 보고서를 저장했습니다. 새로고침 후 내용을 확인해 주세요.');
            throw $error;
        }
    } else {
        $stmt = $db->prepare('UPDATE safety_policy_reviews SET policy_text = ?, items_json = ?, updated_by = ?, updated_at = ?, review_date = ?, revision = revision + 1 WHERE review_year = ? AND revision = ?');
        $stmt->execute([$data['policy_text'], $json, $editor, $now, ($data['review_date']??'')?:null, $year, $revision]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('다른 창에서 보고서가 변경되었습니다. 새로고침 후 내용을 확인해 주세요.');
    }
}
