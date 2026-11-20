<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/heavy_equipment_store.php';

$user = csm_require_auth();
$canManage = auth_can_manage($user);
$actor = (string)($user['name'] ?? '담당자');
$db = he_db();
$year = he_year($_GET['year'] ?? null);
$tabs = ['drivers' => '보유차량·운전자', 'inspect' => '점검·검사·정비', 'guards' => '기능·방호장치', 'evidence' => '통합 증빙·점수'];
$tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'drivers';
$baseUrl = 'heavy_equipment.php?year=' . $year . '&tab=' . $tab;
$items = he_items();
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!$isPost && (isset($_GET['view_photo']) || isset($_GET['download_evidence']))) {
    $photo = isset($_GET['view_photo']);
    $path = null;
    if ($photo && is_string($_GET['view_photo'])) {
        $name = $_GET['view_photo'];
        $path = he_stored_path($name, true);
        if ($path !== null && he_rows($db, 'SELECT 1 FROM he_guard_checks WHERE photo1 = ? OR photo2 = ? LIMIT 1', [$name, $name])) {
            $mime = @getimagesize($path)['mime'] ?? '';
            if (isset(he_image_types()[$mime])) {
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline');
                header('Content-Security-Policy: sandbox');
                readfile($path);
                exit;
            }
        }
    } elseif (!$photo && ($id = filter_var($_GET['download_evidence'], FILTER_VALIDATE_INT))) {
        $file = he_rows($db, 'SELECT * FROM he_evidence WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? null;
        if ($file !== null) {
            $path = he_stored_path($file['stored_name'], false);
            if ($path !== null && filesize($path) === (int)$file['size']) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
                header('Content-Length: ' . $file['size']);
                readfile($path);
                exit;
            }
        }
    }
    http_response_code(404);
    exit('첨부 파일을 찾을 수 없습니다.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['he_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['he_csrf'];
$success = $_SESSION['he_flash'] ?? '';
unset($_SESSION['he_flash']);
$error = '';

$checkOptions = ['적합', '보완필요', '해당없음'];
$assessmentOptions = ['auto' => '자동 (증빙 등록 시 100%)', '1.0' => '100%', '0.9' => '90%', '0.8' => '80%', '0.5' => '50%', '0.0' => '0%'];
$vehicles = he_rows($db, 'SELECT * FROM he_vehicles ORDER BY active DESC, sort_order, id');
$vehicleChoices = [];
foreach ($vehicles as $vehicle) {
    $vehicleChoices[$vehicle['id']] = he_vehicle_label($vehicle) . ((int)$vehicle['active'] === 1 ? '' : ' (비활성)');
}
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$defaultDate = (int)$today->format('Y') === $year ? $today->format('Y-m-d') : '';
$activeVehicles = array_column($vehicles, 'active', 'id');

// Field definitions are shared by the forms and server-side validation.
$commonCheckFields = [
    'vehicle_id' => ['차량·중장비', 'vehicle', true, '', $vehicleChoices],
    'check_date' => ['점검일', 'date', true, $defaultDate],
    'inspector' => ['점검자', 'text', true, $actor],
];
$forms = [
    'vehicle' => ['tab' => 'drivers', 'table' => 'he_vehicles', 'title' => '보유차량·중장비', 'fields' => [
        'sort_order' => ['표시 순서', 'number', true, '10'],
        'vehicle_type' => ['차종', 'select', true, '', he_vehicle_types()],
        'vehicle_name' => ['차량·장비명', 'text', true],
        'plate_no' => ['등록번호', 'text'],
        'reg_date' => ['등록일', 'date'],
        'driver_name' => ['담당 운전자', 'text'],
        'legal_inspect_due' => ['법정검사 예정일', 'date'],
        'insurance_info' => ['보험 정보', 'text'],
        'status' => ['운용 상태', 'select', true, '운용', ['운용', '정비 중', '운용 중지', '처분']],
        'active' => ['보유 목록 상태', 'select', true, '1', [1 => '활성', 0 => '비활성']],
        'note' => ['비고', 'textarea'],
    ]],
    'driver' => ['tab' => 'drivers', 'table' => 'he_drivers', 'title' => '운전자 자격·면허 및 규정위반 이력', 'fields' => [
        'worker_name' => ['성명', 'text', true],
        'team' => ['소속', 'text'],
        'license_type' => ['자격·면허 종류', 'text'],
        'license_no' => ['면허번호', 'text'],
        'acquire_date' => ['취득일', 'date'],
        'renewal_date' => ['갱신일·기한', 'date'],
        'assigned_vehicle' => ['배정 차량·장비', 'text'],
        'violation_count' => ['규정위반 건수', 'number', true, '0'],
        'violation_history' => ['위반일·위반 내용·조치 이력', 'textarea'],
        'status' => ['자격 상태', 'select', true, '확인 필요', ['확인 필요', '유효', '갱신 필요', '정지', '미배정']],
        'note' => ['비고', 'textarea'],
    ]],
    'inspect' => ['tab' => 'inspect', 'table' => 'he_inspections', 'title' => '차량·중장비 점검 및 법정검사·정비일지', 'fields' => [
        ...$commonCheckFields,
        'check_type' => ['점검 구분', 'select', true, '', he_inspection_types()],
        'mileage' => ['주행거리 (km)', 'number'],
        'shop_name' => ['검사·정비 업체', 'text'],
        'next_due_date' => ['차기 예정일', 'date'],
        'detail_note' => ['검사·정비 내용 및 조치 사항', 'textarea'],
    ]],
    'guards' => ['tab' => 'guards', 'table' => 'he_guard_checks', 'title' => '중장비 기능·방호장치 현장확인 점검표', 'fields' => [
        ...$commonCheckFields,
        'issue_note' => ['지적사항', 'textarea'],
        'action_note' => ['개선조치', 'textarea'],
    ]],
];
foreach (['inspect', 'guards'] as $name) {
    foreach (he_check_labels($name) as $column => $label) {
        $forms[$name]['fields'][$column] = [$label, 'select', true, '', $checkOptions];
    }
    $forms[$name]['fields']['overall_result'] = ['종합 결과', 'select', true, '', ['적합', '보완필요']];
}

function he_post(string $name): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('입력값 형식을 확인해 주세요.');
    }
    return trim($value);
}

function he_field_options(string $field, array $options): array
{
    return $field === 'active' || $field === 'vehicle_id' ? $options : array_combine($options, $options);
}

function he_record_data(PDO $db, array $form, array $existing, int $year): array
{
    $result = [];
    foreach ($form['fields'] as $field => $definition) {
        [$label, $type] = $definition;
        $value = he_post($field);
        $valid = $value !== '' || !($definition[2] ?? false);
        if ($value !== '') {
            $valid = match ($type) {
                'date' => preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) === 1
                    && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
                    && ($field !== 'check_date' || (int)$parts[1] === $year),
                'select' => array_key_exists($value, he_field_options($field, $definition[4])),
                'vehicle', 'number' => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false,
                default => mb_strlen($value, 'UTF-8') <= ($type === 'textarea' ? 2000 : 200),
            };
        }
        if (!$valid) {
            throw new InvalidArgumentException($label . ' 항목을 확인해 주세요.');
        }
        $result[$field] = $type === 'number' ? ($value === '' ? null : (int)$value) : $value;
    }
    if (isset($result['vehicle_id'])) {
        $vehicleId = (int)$result['vehicle_id'];
        $vehicle = he_rows($db, 'SELECT * FROM he_vehicles WHERE id = ?', [$vehicleId])[0] ?? null;
        $sameVehicle = isset($existing['vehicle_id']) && (int)$existing['vehicle_id'] === $vehicleId;
        if ($vehicle === null || (!$sameVehicle && !(int)$vehicle['active'])) {
            throw new InvalidArgumentException('운용 목록에서 차량·중장비를 선택해 주세요.');
        }
        $result['vehicle_id'] = $vehicleId;
        $result['vehicle_label'] = $sameVehicle ? $existing['vehicle_label'] : he_vehicle_label($vehicle);
        foreach (he_check_labels($form['tab']) as $field => $label) {
            if ($result[$field] === '보완필요') {
                $result['overall_result'] = '보완필요';
                break;
            }
        }
    }
    return $result;
}

function he_save_record(PDO $db, array $form, int $id, int $year, string $actor): void
{
    $table = $form['table'];
    $annual = $form['tab'] !== 'drivers';
    $where = $annual ? 'id = ? AND year = ?' : 'id = ?';
    $scope = $annual ? [$id, $year] : [$id];
    $newPhotos = [];
    $oldPhotos = [];
    $started = false;
    try {
        if ($form['tab'] === 'guards') {
            foreach (['photo1', 'photo2'] as $slot) {
                $photo = he_save_photo($_FILES[$slot] ?? null, he_post($slot . '_base64'));
                if ($photo !== '') {
                    $newPhotos[$slot] = $photo;
                }
            }
        }
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $existing = $id > 0 ? (he_rows($db, "SELECT * FROM $table WHERE $where", $scope)[0] ?? null) : [];
        if ($existing === null) {
            throw new InvalidArgumentException('수정할 기록을 찾을 수 없습니다.');
        }
        $fields = he_record_data($db, $form, $existing, $year);
        if ($form['tab'] === 'guards') {
            foreach (['photo1', 'photo2'] as $slot) {
                if (isset($newPhotos[$slot]) || he_post('remove_' . $slot) === '1') {
                    $fields[$slot] = $newPhotos[$slot] ?? '';
                    $oldPhotos[] = $existing[$slot] ?? '';
                }
            }
        }
        if ($id > 0) {
            $set = implode(', ', array_map(static fn(string $field): string => $field . ' = ?', array_keys($fields)));
            $db->prepare("UPDATE $table SET $set, updated_at = CURRENT_TIMESTAMP WHERE $where")
                ->execute([...array_values($fields), ...$scope]);
        } else {
            if ($annual) {
                $fields += ['year' => $year, 'created_by' => $actor];
            }
            $columns = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)")->execute(array_values($fields));
        }
        $db->exec('COMMIT');
    } catch (Throwable $failure) {
        if ($started) {
            $db->exec('ROLLBACK');
        }
        foreach ($newPhotos as $name) {
            he_discard_attachment($name, true);
        }
        throw $failure;
    }
    foreach ($oldPhotos as $name) {
        he_discard_attachment($name, true);
    }
}

if ($isPost) {
    $postedToken = $_POST['csrf'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        http_response_code(403);
        $error = '보안 토큰이 만료되었습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = '관리 권한이 필요합니다.';
    } else {
        try {
            $action = he_post('action');
            $record = he_post('record');
            $rawId = he_post('id');
            $id = $rawId === '' ? 0 : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException('기록 번호를 확인해 주세요.');
            }
            $item = he_post('item_code');
            if ($action === 'save_record' && isset($forms[$record]) && $forms[$record]['tab'] === $tab) {
                he_save_record($db, $forms[$record], $id, $year, $actor);
            } elseif ($action === 'delete_record' && in_array($tab, ['inspect', 'guards'], true) && $id > 0) {
                $table = $forms[$tab]['table'];
                $deleted = he_rows($db, "DELETE FROM $table WHERE id = ? AND year = ? RETURNING *", [$id, $year])[0] ?? [];
                foreach (['photo1', 'photo2'] as $slot) {
                    he_discard_attachment($deleted[$slot] ?? '', true);
                }
            } elseif ($action === 'delete_evidence' && $id > 0) {
                $db->prepare('UPDATE he_evidence SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND year = ? AND deleted_at IS NULL')
                    ->execute([$id, $year]);
            } elseif ($action === 'upload_evidence') {
                he_upload_evidence($db, $year, $item, he_post('kind'), $_FILES['evidence_file'] ?? null, he_post('evidence_base64'), he_post('note'), $actor);
            } elseif ($action === 'save_assessment' && isset($items[$item])) {
                $multiplier = he_post('multiplier');
                if (!isset($assessmentOptions[$multiplier])) {
                    throw new InvalidArgumentException('평가 비율을 확인해 주세요.');
                }
                if ($multiplier === 'auto') {
                    $db->prepare('DELETE FROM he_assessments WHERE year = ? AND item_code = ?')->execute([$year, $item]);
                } else {
                    $db->prepare('INSERT INTO he_assessments (year, item_code, multiplier, note, updated_by) VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT(year, item_code) DO UPDATE SET multiplier = excluded.multiplier,
                        note = excluded.note, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP')
                        ->execute([$year, $item, $multiplier, he_post('note'), $actor]);
                }
            } else {
                throw new InvalidArgumentException('요청한 작업을 확인해 주세요.');
            }
            $_SESSION['he_flash'] = str_starts_with($action, 'delete_') ? '삭제했습니다.' : '저장했습니다.';
            header('Location: ' . $baseUrl, true, 303);
            exit;
        } catch (InvalidArgumentException $failure) {
            http_response_code(422);
            $error = $failure->getMessage();
        } catch (Throwable $failure) {
            error_log('heavy_equipment: ' . $failure->getMessage());
            http_response_code(500);
            $error = '저장 중 오류가 발생했습니다. 다시 시도해 주세요.';
        }
    }
}

$scores = [];
foreach ($items as $code => $meta) {
    $scores[$code] = csm_he_score_summary($year, $code, $meta['points']);
}
$edit = is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '';
$editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$hidden = static function (string $action, array $fields = []) use ($csrf, $e): void {
    foreach (['csrf' => $csrf, 'action' => $action, ...$fields] as $name => $value) {
        echo '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
    }
};
$attachmentControl = static function (string $name, string $base64, string $label, bool $photo) use ($e): void {
    ?>
    <div class="attachment">
      <label><?= $e($label) ?>
        <input type="file" name="<?= $e($name) ?>" accept="<?= $photo ? 'image/*' : '.pdf,.jpg,.jpeg,.png,.webp,.xlsx,.docx' ?>" <?= $photo ? 'capture="environment"' : '' ?>>
      </label>
      <button type="button" class="paste-zone" data-paste>이곳을 선택하고 Ctrl+V</button>
      <input type="hidden" name="<?= $e($base64) ?>">
      <button type="button" class="clear-paste" hidden>붙여넣기 취소</button>
      <span role="status"></span>
    </div>
    <?php
};
$renderTable = static function (array $rows, array $columns, ?callable $actions = null) use ($e): void {
    if (!$rows) {
        echo '<p class="muted">등록된 기록이 없습니다.</p>';
        return;
    }
    ?>
    <div class="table-scroll"><table><thead><tr>
      <?php foreach ($columns as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?>
      <?php if ($actions): ?><th scope="col">보기·관리</th><?php endif; ?>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <?php foreach ($columns as $field => $label): ?><td><?= $e(($row[$field] ?? '') === '' ? '—' : $row[$field]) ?></td><?php endforeach; ?>
        <?php if ($actions): ?><td class="row-actions"><?php $actions($row); ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
};
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>2.7 운송장비 및 중장비 · CSM</title>
<style>
  :root { color-scheme: light; --ink:#162a29; --muted:#4c6362; --line:#cbdad8; --green:#086457; --paper:#fff; }
  * { box-sizing:border-box; }
  body { margin:0; background:#edf4f2; color:var(--ink); font:15px/1.55 "Malgun Gothic",system-ui,sans-serif; }
  .shell { max-width:1440px; margin:auto; padding:24px; }
  header { background:#123c36; color:white; }
  header .shell { display:flex; gap:18px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
  header a { color:white; }
  h1,h2,h3 { line-height:1.35; margin:0 0 12px; }
  h1 { font-size:clamp(23px,4vw,34px); margin-bottom:0; }
  h2 { font-size:21px; } h3 { font-size:17px; }
  p { margin:8px 0; }
  a { color:var(--green); }
  .tools,.tabs,.row-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
  .tools { margin-bottom:16px; }
  .year { margin-left:auto; display:flex; gap:8px; align-items:center; }
  .year input { width:7em; }
  .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:18px; }
  .kpi,.panel { background:var(--paper); border:1px solid var(--line); border-radius:12px; }
  .kpi { display:block; text-decoration:none; color:var(--ink); padding:14px 16px; }
  .kpi strong { display:block; color:var(--green); font-size:24px; }
  .kpi span { display:block; }
  .kpi.total { background:#dceee9; }
  .tabs { border-bottom:1px solid var(--line); }
  .tabs a { padding:10px 14px; color:var(--ink); text-decoration:none; font-weight:700; border-bottom:3px solid transparent; }
  .tabs [aria-current] { color:var(--green); border-color:var(--green); }
  .panel { margin-top:16px; padding:18px; }
  .muted { color:var(--muted); }
  .flash { border-radius:8px; padding:10px 14px; background:#daeee5; }
  .flash.error { background:#ffebe6; color:#8a241a; }
  details { margin:0 0 16px; border:1px solid var(--line); border-radius:8px; padding:12px; }
  summary { color:var(--green); cursor:pointer; font-weight:700; }
  .form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(205px,1fr)); gap:12px; margin:12px 0; align-items:start; }
  label { display:block; font-weight:700; }
  input:not([type=hidden],[type=checkbox]),select,textarea { display:block; width:100%; margin-top:4px; padding:7px 8px; color:inherit; font:inherit; border:1px solid #a2b7b4; border-radius:6px; background:white; }
  textarea { min-height:72px; resize:vertical; }
  .wide { grid-column:1 / -1; }
  .button,button { display:inline-block; padding:6px 11px; border:1px solid var(--line); border-radius:6px; background:white; color:var(--green); cursor:pointer; text-decoration:none; font:inherit; }
  .primary { background:var(--green); border-color:var(--green); color:white; }
  .danger { color:#8a241a; }
  .table-scroll { overflow:auto; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th,td { padding:8px; border:1px solid var(--line); text-align:left; vertical-align:top; }
  th { background:#edf5f3; }
  td { min-width:64px; white-space:pre-wrap; overflow-wrap:anywhere; }
  td.row-actions { display:table-cell; min-width:145px; white-space:normal; }
  .row-actions a,.row-actions form { display:inline-block; margin:0 5px 4px 0; }
  .attachment { border:1px solid var(--line); border-radius:8px; padding:10px; min-width:0; }
  .attachment button { margin-top:6px; }
  .paste-zone:focus { outline:2px solid var(--green); }
  .attachment span { display:block; }
  .photo-links { margin:8px 0; }
  @media(max-width:650px) { .shell { padding:14px; } .panel { padding:12px; } .year { margin-left:0; } }
</style>
</head>
<body>
<header><div class="shell">
  <h1>2.7 운송장비 및 중장비</h1>
  <a href="area.php?group=2&amp;year=<?= $year ?>">2. 현장 안전관리</a>
</div></header>
<main class="shell">
  <div class="tools">
    <?php foreach (array_slice($tabs, 0, 3, true) as $mode => $label): ?>
      <a class="button" href="heavy_equipment_print.php?year=<?= $year ?>&amp;mode=<?= $e($mode) ?>" target="_blank" rel="noopener"><?= $e($label) ?> 인쇄</a>
    <?php endforeach; ?>
    <form class="year" method="get">
      <input type="hidden" name="tab" value="<?= $e($tab) ?>">
      <label for="year">평가연도</label>
      <input id="year" name="year" type="number" min="2020" max="2099" required value="<?= $year ?>">
      <button type="submit">조회</button>
    </form>
  </div>
  <section class="kpis" aria-label="2.7 평가 점수">
    <?php foreach ($items as $code => $meta): $score = $scores[$code]; ?>
      <a class="kpi" href="?year=<?= $year ?>&amp;tab=<?= $e($meta['tab']) ?>#evidence-<?= $e($code) ?>">
        <span><?= $e($code . ' ' . $meta['title']) ?></span>
        <strong><?= number_format($score['earned'], 1) ?> / <?= number_format($meta['points'], 1) ?>점</strong>
        <span class="muted">유효 증빙 <?= $score['evidence_count'] ?>건</span>
      </a>
    <?php endforeach; ?>
    <a class="kpi total" href="?year=<?= $year ?>&amp;tab=evidence">
      <span>2.7 합계</span>
      <strong><?= number_format(array_sum(array_column($scores, 'earned')), 1) ?> / 6.0점</strong>
      <span class="muted">유효 증빙 <?= array_sum(array_column($scores, 'evidence_count')) ?>건</span>
    </a>
  </section>
  <nav class="tabs" aria-label="운송장비 및 중장비 메뉴">
    <?php foreach ($tabs as $name => $label): ?>
      <a href="?year=<?= $year ?>&amp;tab=<?= $e($name) ?>" <?= $name === $tab ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($success !== ''): ?><p class="flash" role="status"><?= $e($success) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= $e($error) ?></p><?php endif; ?>

  <?php foreach ($forms as $name => $form): ?>
    <?php
    if ($tab !== $form['tab']) {
        continue;
    }
    $table = $form['table'];
    $rows = match ($name) {
        'vehicle' => $vehicles,
        'driver' => he_rows($db, 'SELECT * FROM he_drivers ORDER BY id'),
        default => he_rows($db, "SELECT * FROM $table WHERE year = ? ORDER BY check_date DESC, id DESC", [$year]),
    };
    $editing = $canManage && $edit === $name && $editId > 0
        ? (he_rows($db, "SELECT * FROM $table WHERE id = ?" . ($tab === 'drivers' ? '' : ' AND year = ?'), $tab === 'drivers' ? [$editId] : [$editId, $year])[0] ?? [])
        : [];
    $retry = $canManage && $error !== '' && ($_POST['csrf'] ?? '') === $csrf && ($_POST['action'] ?? '') === 'save_record' && ($_POST['record'] ?? '') === $name;
    $columns = [];
    foreach ($form['fields'] as $field => $definition) {
        $columns[$field === 'vehicle_id' ? 'vehicle_label' : $field] = $definition[0];
    }
    if ($name === 'vehicle') {
        foreach ($rows as &$row) {
            $row['active'] = (int)$row['active'] ? '활성' : '비활성';
        }
        unset($row);
    }
    ?>
    <section class="panel" id="record-<?= $e($name) ?>">
      <h2><?= $e($form['title']) ?></h2>
      <?php if ($canManage): ?>
        <details <?= $editing || $retry ? 'open' : '' ?>>
          <summary><?= $editing ? '기록 수정' : '새 기록 등록' ?></summary>
          <form method="post" enctype="multipart/form-data" action="<?= $e($baseUrl . ($editing ? '&edit=' . $name . '&id=' . $editing['id'] : '')) ?>">
            <?php $hidden('save_record', ['record' => $name, 'id' => $editing['id'] ?? '']); ?>
            <div class="form-grid">
              <?php foreach ($form['fields'] as $field => $definition): ?>
                <?php
                [$label, $type] = $definition;
                $default = $definition[3] ?? '';
                $value = $retry && is_string($_POST[$field] ?? null) ? $_POST[$field] : ($editing ? ($editing[$field] ?? '') : $default);
                $required = ($definition[2] ?? false) ? 'required' : '';
                ?>
                <label class="<?= $type === 'textarea' ? 'wide' : '' ?>"><?= $e($label) ?>
                  <?php if ($type === 'textarea'): ?>
                    <textarea name="<?= $e($field) ?>" maxlength="2000"><?= $e($value) ?></textarea>
                  <?php elseif ($type === 'select' || $type === 'vehicle'): ?>
                    <select name="<?= $e($field) ?>" <?= $required ?>>
                      <?php if ($default === ''): ?><option value="">선택</option><?php endif; ?>
                      <?php foreach (he_field_options($field, $definition[4]) as $option => $caption): ?>
                        <?php
                        if ($type === 'vehicle' && !(int)$activeVehicles[$option] && (int)($editing['vehicle_id'] ?? 0) !== (int)$option) {
                            continue;
                        }
                        ?>
                        <option value="<?= $e($option) ?>" <?= (string)$option === (string)$value ? 'selected' : '' ?>><?= $e($caption) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <input name="<?= $e($field) ?>" type="<?= $e($type) ?>" value="<?= $e($value) ?>" <?= $required ?>
                      <?= $type === 'number' ? 'min="0" step="1"' : ($type === 'text' ? 'maxlength="200"' : '') ?>
                      <?= $field === 'check_date' ? 'min="' . $year . '-01-01" max="' . $year . '-12-31"' : '' ?>>
                  <?php endif; ?>
                </label>
              <?php endforeach; ?>
              <?php if ($name === 'guards'): ?>
                <?php foreach (['photo1' => '현장 사진 1', 'photo2' => '현장 사진 2'] as $slot => $label): ?>
                  <div>
                    <?php $attachmentControl($slot, $slot . '_base64', $label, true); ?>
                    <?php if (!empty($editing[$slot])): ?>
                      <a href="<?= $e(he_photo_url($editing[$slot])) ?>" target="_blank" rel="noopener">현재 사진 보기</a>
                      <label><input type="checkbox" name="remove_<?= $e($slot) ?>" value="1"> 현재 사진 제거</label>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <button class="primary" type="submit">기록 저장</button>
            <?php if ($editing): ?><a class="button" href="<?= $e($baseUrl) ?>">취소</a><?php endif; ?>
          </form>
        </details>
      <?php endif; ?>
      <?php
      $renderTable($rows, $columns, ($canManage || $name === 'guards') ? static function (array $row) use ($name, $tab, $year, $baseUrl, $canManage, $e, $hidden): void {
          $id = (int)$row['id'];
          if ($name === 'guards') {
              foreach (['photo1' => '사진 1', 'photo2' => '사진 2'] as $slot => $caption) {
                  if (he_stored_path($row[$slot], true) !== null) {
                      echo '<a href="' . $e(he_photo_url($row[$slot])) . '" target="_blank" rel="noopener">' . $caption . '</a>';
                  }
              }
              echo '<a href="heavy_equipment_print.php?mode=guards&amp;year=' . $year . '&amp;check_id=' . $id . '" target="_blank" rel="noopener">점검표 인쇄</a>';
          }
          if ($canManage) {
              echo '<a href="' . $e($baseUrl . '&edit=' . $name . '&id=' . $id . '#record-' . $name) . '">수정</a>';
              if ($tab !== 'drivers') {
                  echo '<form method="post" action="' . $e($baseUrl) . '" data-confirm="이 점검 기록을 삭제하시겠습니까?">';
                  $hidden('delete_record', ['id' => $id]);
                  echo '<button type="submit" class="danger">삭제</button></form>';
              }
          }
      } : null);
      ?>
    </section>
  <?php endforeach; ?>

  <?php foreach ($items as $code => $meta): ?>
    <?php
    if ($tab !== 'evidence' && $tab !== $meta['tab']) {
        continue;
    }
    $score = $scores[$code];
    $assessment = $score['assessment'];
    $evidence = he_rows($db, 'SELECT * FROM he_evidence WHERE year = ? AND item_code = ? AND deleted_at IS NULL ORDER BY id DESC', [$year, $code]);
    ?>
    <section class="panel" id="evidence-<?= $e($code) ?>">
      <h2><?= $e($code) ?> 증빙·점수 · <?= $e($meta['title']) ?></h2>
      <p><strong><?= number_format($score['earned'], 1) ?> / <?= number_format($meta['points'], 1) ?>점</strong> · 유효 증빙 <?= $score['evidence_count'] ?>건
        <?php if ($code === '2.7.3'): ?><span class="muted">(현장확인 점검표 사진 포함)</span><?php endif; ?>
        · <?= $score['state'] === 'no_evidence' ? '유효 증빙 없음' : ($score['automatic'] ? '자동 평가' : '수동 평가') ?>
      </p>
      <?php if ($assessment !== null && $assessment['note'] !== ''): ?><p>평가 메모 · <?= $e($assessment['note']) ?></p><?php endif; ?>
      <?php if ($canManage): ?>
        <details>
          <summary>증빙 등록</summary>
          <form method="post" enctype="multipart/form-data" action="<?= $e($baseUrl) ?>">
            <?php $hidden('upload_evidence', ['item_code' => $code]); ?>
            <div class="form-grid">
              <label>증빙 구분<select name="kind" required>
                <?php foreach ($meta['kinds'] as $kind => $label): ?><option value="<?= $e($kind) ?>"><?= $e($label) ?></option><?php endforeach; ?>
              </select></label>
              <?php $attachmentControl('evidence_file', 'evidence_base64', '증빙 파일', false); ?>
              <label>비고<textarea name="note" maxlength="2000"></textarea></label>
            </div>
            <button class="primary" type="submit">증빙 저장</button>
          </form>
        </details>
        <details>
          <summary>평가 비율 설정</summary>
          <form method="post" action="<?= $e($baseUrl) ?>">
            <?php $hidden('save_assessment', ['item_code' => $code]); ?>
            <div class="form-grid">
              <label>평가 비율<select name="multiplier">
                <?php foreach ($assessmentOptions as $value => $label): ?>
                  <option value="<?= $e($value) ?>" <?= ($assessment === null ? $value === 'auto' : $value !== 'auto' && (float)$value === (float)$assessment['multiplier']) ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select></label>
              <label>평가 메모<textarea name="note" maxlength="2000"><?= $e($assessment['note'] ?? '') ?></textarea></label>
            </div>
            <button class="primary" type="submit">평가 저장</button>
          </form>
        </details>
      <?php endif; ?>
      <?php
      foreach ($evidence as &$file) {
          $file['kind_label'] = $meta['kinds'][$file['kind']] ?? $file['kind'];
      }
      unset($file);
      $renderTable($evidence, ['kind_label' => '구분', 'original_name' => '파일명', 'note' => '비고', 'uploaded_by' => '등록자', 'uploaded_at' => '등록일'],
          static function (array $file) use ($canManage, $baseUrl, $e, $hidden): void {
              $path = he_stored_path($file['stored_name'], false);
              if ($path !== null && filesize($path) === (int)$file['size']) {
                  echo '<a href="heavy_equipment.php?download_evidence=' . (int)$file['id'] . '">다운로드</a>';
              } else {
                  echo '<span class="muted">파일 없음</span> ';
              }
              if ($canManage) {
                  echo '<form method="post" action="' . $e($baseUrl) . '" data-confirm="이 증빙 기록을 삭제하시겠습니까?">';
                  $hidden('delete_evidence', ['id' => $file['id']]);
                  echo '<button type="submit" class="danger">삭제</button></form>';
              }
          });
      ?>
    </section>
  <?php endforeach; ?>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
document.querySelectorAll('.attachment').forEach(control => {
  const input = control.querySelector('input[type=file]');
  const clipboard = control.querySelector('input[type=hidden]');
  const clear = control.querySelector('.clear-paste');
  const status = control.querySelector('[role=status]');
  let reader = null;
  const reset = () => {
    if (reader?.readyState === FileReader.LOADING) reader.abort();
    clipboard.value = '';
    status.textContent = '';
    clear.hidden = true;
  };
  clear.addEventListener('click', reset);
  input.addEventListener('change', reset);
  control.querySelector('[data-paste]').addEventListener('paste', event => {
    const image = Array.from(event.clipboardData?.files ?? []).find(file => file.type.startsWith('image/'));
    if (!image) {
      status.textContent = '클립보드에 이미지가 없습니다.';
      return;
    }
    event.preventDefault();
    reset();
    const maxMb = input.hasAttribute('capture') ? 15 : 20;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(image.type) || image.size > maxMb * 1024 * 1024) {
      status.textContent = maxMb + 'MB 이하 JPG, PNG, WEBP 이미지를 사용해 주세요.';
      return;
    }
    reader = new FileReader();
    reader.addEventListener('load', () => {
      clipboard.value = reader.result;
      input.value = '';
      status.textContent = '스크린샷을 첨부했습니다.';
      clear.hidden = false;
    });
    reader.addEventListener('error', () => { status.textContent = '스크린샷을 읽을 수 없습니다.'; });
    reader.readAsDataURL(image);
  });
  control.closest('form').addEventListener('submit', event => {
    if (reader?.readyState === FileReader.LOADING) {
      event.preventDefault();
      status.textContent = '스크린샷을 읽는 중입니다. 잠시 후 다시 저장해 주세요.';
    }
  });
});
</script>
</body>
</html>
