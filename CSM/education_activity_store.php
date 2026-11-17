<?php
declare(strict_types=1);

require_once __DIR__ . '/leadership_store.php';

function education_csm_db(): ?PDO
{
    $path = __DIR__ . '/education/storage/db/csm.db';
    if (!is_file($path)) return null;
    try {
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $db->exec('PRAGMA busy_timeout = 5000');
        return $db;
    } catch (Throwable $e) {
        error_log('CSM education_csm_db error: ' . $e->getMessage());
        return null;
    }
}

function education_item_catalog(array $catalog): array
{
    $items = [];
    foreach ($catalog['modules'] as $module) {
        if ($module['group'] === '5') {
            foreach ($module['items'] as $item) {
                $items[$item['id']] = $item;
            }
        }
    }
    return $items;
}

function education_trainings(?PDO $csmDb, string $item, int $year): array
{
    if ($csmDb === null) return [];
    try {
        $stmt = $csmDb->prepare("
            SELECT t.*,
                   (SELECT COUNT(*) FROM csm_training_attendees a WHERE a.training_id = t.id) AS total_attendees,
                   (SELECT COUNT(*) FROM csm_training_attendees a WHERE a.training_id = t.id AND a.is_completed = 1) AS completed_attendees
            FROM csm_trainings t
            WHERE t.csm_item_code = :item
              AND strftime('%Y', t.training_date) = :year
            ORDER BY t.training_date DESC, t.id DESC
        ");
        $stmt->execute([':item' => $item, ':year' => (string)$year]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('CSM education_trainings error: ' . $e->getMessage());
        return [];
    }
}

function education_validate(array $input, string $item, int $year, array $allowedItems): array
{
    if (!in_array($item, $allowedItems, true)) {
        throw new InvalidArgumentException('올바른 안전보건교육 평가항목을 선택해 주세요.');
    }
    $date = leadership_date($input['activity_date'] ?? '', '활동일', true);
    if ((int)substr($date, 0, 4) !== $year) {
        throw new InvalidArgumentException('활동일은 선택한 연도(' . $year . '년)에 속해야 합니다.');
    }
    if ($date > (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d')) {
        throw new InvalidArgumentException('실제 활동일을 기록해 주세요. 미래 활동은 등록할 수 없습니다.');
    }
    $title = leadership_text($input['title'] ?? '', '활동명 / 교육과정명', 200, true);
    $payload = [
        'audience'   => leadership_text($input['audience'] ?? '', '교육 대상·이수자', 500, false),
        'instructor' => leadership_text($input['instructor'] ?? '', '강사·교육기관', 200, false),
        'hours'      => leadership_text($input['hours'] ?? '', '교육시간', 50, false),
        'location'   => leadership_text($input['location'] ?? '', '교육장소', 200, false),
        'status'     => in_array($input['status'] ?? '', ['completed', 'planned'], true) ? $input['status'] : 'completed',
        'notes'      => leadership_text($input['notes'] ?? '', '활동 내용 및 결과', 10000, false),
    ];
    return [
        'item'          => $item,
        'year'          => $year,
        'activity_date' => $date,
        'title'         => $title,
        'payload'       => $payload
    ];
}

function education_save_assessment(PDO $db, array $catalog, int $year, string $item, mixed $value, int $revision, string $editor): void
{
    $items = education_item_catalog($catalog);
    $criterion = $items[$item] ?? null;
    if (!$criterion || !is_string($value) || !is_numeric($value)) {
        throw new InvalidArgumentException('평가 수준을 선택해 주세요.');
    }
    $multiplier = (float)$value;
    $allowed = [1.0];
    foreach ($criterion['criteria'] as $level => $label) {
        if (is_numeric($level) && $label !== '') {
            $allowed[] = (float)$level;
        }
    }
    if (!in_array($multiplier, $allowed, true)) {
        throw new InvalidArgumentException('원본 평가 기준에 명시된 평가 수준을 선택해 주세요.');
    }
    
    $db->exec('BEGIN IMMEDIATE');
    try {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        if ($revision === 0) {
            $query = $db->prepare('INSERT OR IGNORE INTO leadership_assessments(year,item,multiplier,updated_by,updated_at) VALUES(?,?,?,?,?)');
            $query->execute([$year, $item, $multiplier, $editor, $now]);
        } else {
            $query = $db->prepare('UPDATE leadership_assessments SET multiplier=?,revision=revision+1,updated_by=?,updated_at=? WHERE year=? AND item=? AND revision=?');
            $query->execute([$multiplier, $editor, $now, $year, $item, $revision]);
        }
        if ($query->rowCount() !== 1) {
            throw new InvalidArgumentException('평가 정보가 변경되었습니다. 새로고침 후 다시 시도해 주세요.');
        }
        $query = $db->prepare('INSERT INTO leadership_assessment_history(year,item,multiplier,editor,saved_at) VALUES(?,?,?,?,?)');
        $query->execute([$year, $item, $multiplier, $editor, $now]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}
