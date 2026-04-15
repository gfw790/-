<?php
require_once __DIR__ . '/../../risk_server/db_config.php';

function h($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function restructureFilesArray(array $files): array
{
    $result = [];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    foreach ($files['name'] as $index => $fieldValues) {
        foreach ($fieldValues as $fieldName => $value) {
            $result[$index][$fieldName] = [
                'name' => $files['name'][$index][$fieldName] ?? '',
                'type' => $files['type'][$index][$fieldName] ?? '',
                'tmp_name' => $files['tmp_name'][$index][$fieldName] ?? '',
                'error' => $files['error'][$index][$fieldName] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index][$fieldName] ?? 0,
            ];
        }
    }

    return $result;
}

function saveUploadedFile(array $file, string $uploadDir): string
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return '';
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = sprintf('%s_%s.%s', $safeName, uniqid('', true), $extension ?: 'dat');
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/safety_manager/' . $filename;
    }

    return '';
}

$message = '';
$error = '';
$values = [
    'log_date' => date('Y-m-d'),
    'manager_name' => '',
    'site_name' => '',
    'work_location' => '',
    'weather' => '',
    'subject' => '',
    'summary' => '',
    'remark' => '',
];
$details = [];

// ── 안전관리자 목록 (auth_users.json) ──────────────────────
$safetyManagers = [];
$authUsersFile = __DIR__ . '/../risk_assessment/auth_users.json';
if (is_file($authUsersFile)) {
    $stored = json_decode((string)file_get_contents($authUsersFile), true) ?? [];
    foreach ($stored as $account) {
        if (!is_array($account)) {
            continue;
        }
        if ((string)($account['role'] ?? '') === 'safety_manager') {
            $name = trim((string)($account['name'] ?? ''));
            if ($name !== '') {
                $safetyManagers[] = $name;
            }
        }
    }
    sort($safetyManagers);
}

// ── 금일 작업목록 (risk_assessment DB) ─────────────────────
$todayWorkList = [];
try {
    $raPdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=risk_assessment;charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $wStmt = $raPdo->prepare(
        'SELECT work_title, work_place, team_name
         FROM work_report
         WHERE work_date = :today
         GROUP BY work_title, work_place, team_name
         ORDER BY team_name, work_title'
    );
    $wStmt->execute([':today' => date('Y-m-d')]);
    $todayWorkList = $wStmt->fetchAll();
} catch (Throwable $e) {
    // risk_assessment DB 연결 실패 시 빈 배열 유지
}

function renderDetailRow(array $detail, int $index): string
{
    return sprintf(
        '<tr class="detail-row">
            <td><input type="hidden" name="details[%1$d][item_no]" value="%2$d"><span class="form-control-plaintext">%2$d</span></td>
            <td><input type="text" name="details[%1$d][work_time]" class="form-control" value="%3$s"></td>
            <td><input type="text" name="details[%1$d][activity]" class="form-control" value="%4$s"></td>
            <td><textarea name="details[%1$d][description]" class="form-control" rows="2">%5$s</textarea></td>
            <td>
                <select name="details[%1$d][status]" class="form-select">
                    <option value="">선택</option>
                    <option value="양호"%6$s>양호</option>
                    <option value="불량"%7$s>불량</option>
                    <option value="조치완료"%8$s>조치완료</option>
                    <option value="후속조치필요"%9$s>후속조치필요</option>
                </select>
            </td>
            <td><input type="file" name="details[%1$d][photo_1]" class="form-control"></td>
            <td><input type="file" name="details[%1$d][photo_2]" class="form-control"></td>
            <td><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
        </tr>',
        $index,
        $detail['item_no'] ?? ($index + 1),
        h($detail['work_time'] ?? ''),
        h($detail['activity'] ?? ''),
        h($detail['description'] ?? ''),
        ($detail['status'] ?? '') === '양호' ? ' selected' : '',
        ($detail['status'] ?? '') === '불량' ? ' selected' : '',
        ($detail['status'] ?? '') === '조치완료' ? ' selected' : '',
        ($detail['status'] ?? '') === '후속조치필요' ? ' selected' : ''
    );
}

function formatWorkLabel(array $work): string
{
    $title = trim((string)($work['work_title'] ?? ''));
    $teamName = trim((string)($work['team_name'] ?? ''));

    if ($teamName === '') {
        return $title;
    }

    return $title . ' (' . $teamName . ')';
}
?>
<?php
$pageTitle = '안전관리자 업무일지 등록';
$extraHead = '<style>
    .detail-table th, .detail-table td { vertical-align: middle; }
    .site-visit-table th, .site-visit-table td { vertical-align: middle; }
    .form-control-plaintext { margin-bottom: 0; }
    .form-select {
        color: #f8fafc;
        background-color: #162033;
        border-color: #8b6b2f;
    }
    .form-select:focus {
        color: #f8fafc;
        background-color: #162033;
        border-color: #d6a545;
        box-shadow: 0 0 0 0.2rem rgba(214, 165, 69, 0.2);
    }
    .form-select option {
        color: #0f172a;
        background-color: #f8fafc;
    }
    .form-select option[value=""] {
        color: #475569;
    }
    .site-visit-meta {
        display: block;
        margin-top: 4px;
        color: #94a3b8;
        font-size: 12px;
    }
</style>';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4">안전관리자 업무일지 등록</h1>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="store.php" enctype="multipart/form-data">
        <input type="hidden" name="site_name" id="site_name" value="<?= h($values['site_name']) ?>">
        <input type="hidden" name="work_location" id="work_location" value="<?= h($values['work_location']) ?>">
        <input type="hidden" name="site_visit_data" id="site_visit_data" value="[]">
        <div class="card mb-4">
            <div class="card-header">기본 정보</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">날짜</label>
                        <input type="date" name="log_date" class="form-control" value="<?= h($values['log_date']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">작성자</label>
                        <?php if (!empty($safetyManagers)): ?>
                            <select name="manager_name" class="form-select" required>
                                <option value="">-- 작성자 선택 --</option>
                                <?php foreach ($safetyManagers as $mgr): ?>
                                    <option value="<?= h($mgr) ?>" <?= $values['manager_name'] === $mgr ? 'selected' : '' ?>>
                                        <?= h($mgr) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="manager_name" class="form-control" value="<?= h($values['manager_name']) ?>" required placeholder="작성자 이름">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">날씨</label>
                        <input type="text" name="weather" class="form-control" value="<?= h($values['weather']) ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">제목</label>
                        <input type="text" name="subject" class="form-control" value="<?= h($values['subject']) ?>" required>
                    </div>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-12">
                        <label class="form-label">현장명</label>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0 site-visit-table">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 72px;">선택</th>
                                    <th style="width: 34%;">작업명</th>
                                    <th style="width: 34%;">작업장소</th>
                                    <th>미방문사유</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($todayWorkList)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">조회된 작업이 없습니다.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($todayWorkList as $index => $w): ?>
                                        <tr class="site-visit-row">
                                            <td class="text-center">
                                                <input type="checkbox"
                                                       class="form-check-input js-site-visit-check"
                                                       id="site_visit_<?= (int)$index ?>"
                                                       data-title="<?= h((string)($w['work_title'] ?? '')) ?>"
                                                       data-place="<?= h((string)($w['work_place'] ?? '')) ?>"
                                                       data-team="<?= h((string)($w['team_name'] ?? '')) ?>">
                                            </td>
                                            <td>
                                                <label for="site_visit_<?= (int)$index ?>" class="mb-0 w-100">
                                                    <?= h((string)($w['work_title'] ?? '')) ?>
                                                    <?php if (!empty($w['team_name'])): ?>
                                                        <span class="site-visit-meta"><?= h((string)$w['team_name']) ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            </td>
                                            <td><?= h((string)($w['work_place'] ?? '')) ?></td>
                                            <td>
                                                <input type="text"
                                                       class="form-control form-control-sm js-site-visit-reason"
                                                       placeholder="미방문 시 사유 입력">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-12">
                        <label class="form-label">요약</label>
                        <textarea name="summary" class="form-control" rows="3"><?= h($values['summary']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>세부 기록</span>
                <button type="button" id="add-detail" class="btn btn-primary btn-sm">세부기록 추가</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0 detail-table">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">No.</th>
                            <th style="width: 120px;">시간</th>
                            <th style="width: 120px;">업무구분</th>
                            <th>내용</th>
                            <th style="width: 140px;">상태</th>
                            <th style="width: 140px;">사진1</th>
                            <th style="width: 140px;">사진2</th>
                            <th style="width: 90px;">삭제</th>
                        </tr>
                        </thead>
                        <tbody id="details-body">
                        <?php if (empty($details)): ?>
                            <?= renderDetailRow([], 0) ?>
                        <?php else: ?>
                            <?php foreach ($details as $index => $detail): ?>
                                <?= renderDetailRow($detail ?? [], $index) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label">비고</label>
            <textarea name="remark" class="form-control" rows="3"><?= h($values['remark']) ?></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">등록</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.reload()">취소</button>
        </div>
    </form>

<script>
    const detailBody = document.getElementById('details-body');
    const addDetailButton = document.getElementById('add-detail');
    const siteVisitRows = document.querySelectorAll('.site-visit-row');
    const siteNameInput = document.getElementById('site_name');
    const workLocationInput = document.getElementById('work_location');
    const siteVisitDataInput = document.getElementById('site_visit_data');

    function getDetailRowHtml(index, data = {}) {
        const workTime = data.work_time ?? '';
        const activity = data.activity ?? '';
        const description = data.description ?? '';
        const status = data.status ?? '';
        return `
            <tr class="detail-row">
                <td><input type="hidden" name="details[${index}][item_no]" value="${index + 1}"><span class="form-control-plaintext">${index + 1}</span></td>
                <td><input type="text" name="details[${index}][work_time]" class="form-control" value="${workTime}"></td>
                <td><input type="text" name="details[${index}][activity]" class="form-control" value="${activity}"></td>
                <td><textarea name="details[${index}][description]" class="form-control" rows="2">${description}</textarea></td>
                <td>
                    <select name="details[${index}][status]" class="form-select">
                        <option value="">선택</option>
                        <option value="양호" ${status === '양호' ? 'selected' : ''}>양호</option>
                        <option value="불량" ${status === '불량' ? 'selected' : ''}>불량</option>
                        <option value="조치완료" ${status === '조치완료' ? 'selected' : ''}>조치완료</option>
                        <option value="후속조치필요" ${status === '후속조치필요' ? 'selected' : ''}>후속조치필요</option>
                    </select>
                </td>
                <td><input type="file" name="details[${index}][photo_1]" class="form-control"></td>
                <td><input type="file" name="details[${index}][photo_2]" class="form-control"></td>
                <td><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
            </tr>`;
    }

    function reindexRows() {
        const rows = detailBody.querySelectorAll('.detail-row');
        rows.forEach((row, index) => {
            const itemNoInput = row.querySelector('input[type="hidden"][name^="details"][name$="[item_no]"]');
            if (itemNoInput) {
                itemNoInput.name = `details[${index}][item_no]`;
                itemNoInput.value = index + 1;
            }

            const inputs = row.querySelectorAll('input, textarea, select');
            inputs.forEach((input) => {
                const nameParts = input.name.match(/^details\[(\d+)\]\[(.+)\]$/);
                if (nameParts) {
                    input.name = `details[${index}][${nameParts[2]}]`;
                }
            });
        });
    }

    function addDetailRow(data = {}) {
        const index = detailBody.querySelectorAll('.detail-row').length;
        const row = document.createElement('tr');
        row.className = 'detail-row';
        row.innerHTML = getDetailRowHtml(index, data);
        detailBody.appendChild(row);
    }

    function onDeleteRow(event) {
        if (!event.target.classList.contains('delete-row')) {
            return;
        }
        const row = event.target.closest('tr');
        if (row) {
            row.remove();
            reindexRows();
        }
    }

    addDetailButton.addEventListener('click', () => {
        addDetailRow();
    });

    detailBody.addEventListener('click', onDeleteRow);

    function updateSiteVisitSummary() {
        const rows = Array.from(siteVisitRows).map((row) => {
            const checkbox = row.querySelector('.js-site-visit-check');
            const reasonInput = row.querySelector('.js-site-visit-reason');
            return {
                selected: checkbox ? checkbox.checked : false,
                work_title: checkbox ? checkbox.dataset.title || '' : '',
                work_place: checkbox ? checkbox.dataset.place || '' : '',
                team_name: checkbox ? checkbox.dataset.team || '' : '',
                non_visit_reason: reasonInput ? reasonInput.value.trim() : ''
            };
        });

        const selectedTitles = [];
        const selectedPlaces = [];

        rows.forEach((row) => {
            if (!row.selected) {
                return;
            }
            if (row.work_title && !selectedTitles.includes(row.work_title)) {
                selectedTitles.push(row.work_title);
            }
            if (row.work_place && !selectedPlaces.includes(row.work_place)) {
                selectedPlaces.push(row.work_place);
            }
        });

        siteNameInput.value = selectedTitles.join(', ');
        workLocationInput.value = selectedPlaces.join(', ');
        siteVisitDataInput.value = JSON.stringify(rows);
    }

    siteVisitRows.forEach((row) => {
        const checkbox = row.querySelector('.js-site-visit-check');
        const reasonInput = row.querySelector('.js-site-visit-reason');

        if (checkbox) {
            checkbox.addEventListener('change', updateSiteVisitSummary);
        }
        if (reasonInput) {
            reasonInput.addEventListener('input', updateSiteVisitSummary);
        }
    });

    const createForm = document.querySelector('form[action="store.php"]');
    if (createForm) {
        createForm.addEventListener('submit', updateSiteVisitSummary);
    }

    updateSiteVisitSummary();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
