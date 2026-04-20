<?php
require_once __DIR__ . '/../../risk_server/db_config.php';
require_once __DIR__ . '/log_validation.php';

/**
 * HTML escape helper.
 *
 * @param mixed $value
 * @return string
 */
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safetyLogHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function parsePreventionData(?string $rawValue): array
{
    if (!is_string($rawValue) || trim($rawValue) === '') {
        return [
            'activity' => '',
            'process' => '',
            'items' => [],
        ];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [
            'activity' => '',
            'process' => '',
            'items' => [],
        ];
    }

    $items = [];
    foreach (($decoded['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $measure = trim((string)($item['measure'] ?? ''));
        if ($measure === '') {
            continue;
        }

        $items[] = [
            'work_key' => trim((string)($item['work_key'] ?? '')),
            'work_label' => trim((string)($item['work_label'] ?? '')),
            'measure' => $measure,
            'status' => trim((string)($item['status'] ?? '')),
        ];
    }

    return [
        'activity' => trim((string)($decoded['activity'] ?? '')),
        'process' => trim((string)($decoded['process'] ?? '')),
        'items' => $items,
    ];
}

$log = null;
$details = [];
$errorMessage = '';
$values = [
    'log_date' => '',
    'manager_name' => '',
    'site_name' => '',
    'work_location' => '',
    'weather' => '',
    'subject' => '',
    'summary' => '',
    'remark' => '',
];

try {
        $pdo = getDB();
        $id = getValidLogId($pdo);
    $hasPreventionDataColumn = safetyLogHasColumn($pdo, 'safety_manager_log_detail', 'prevention_data');
    $hasPhoto3Column = safetyLogHasColumn($pdo, 'safety_manager_log_detail', 'photo_3');

        // safety_manager_log에서 단일 항목을 조회합니다.
        $stmt = $pdo->prepare(
            'SELECT id, log_date, manager_name, site_name, work_location, weather, subject, summary, remark
             FROM safety_manager_log
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $log = $stmt->fetch();

        if (!$log) {
            // 이미 getValidLogId()에서 존재 여부를 확인했기 때문에 이 분기는 거의 발생하지 않습니다.
            redirectInvalidLog('존재하지 않는 업무일지입니다.');
        } else {
            $values = [
                'log_date' => $log['log_date'],
                'manager_name' => $log['manager_name'],
                'site_name' => $log['site_name'],
                'work_location' => $log['work_location'],
                'weather' => $log['weather'],
                'subject' => $log['subject'],
                'summary' => $log['summary'],
                'remark' => $log['remark'],
            ];

            // safety_manager_log_detail 목록을 조회합니다.
            $detailStmt = $pdo->prepare(
                'SELECT id, item_no, work_time, activity, description, '
                . ($hasPreventionDataColumn ? 'prevention_data' : 'NULL AS prevention_data') . ', photo_1, photo_2, '
                . ($hasPhoto3Column ? 'photo_3' : 'NULL AS photo_3') . '
                 FROM safety_manager_log_detail
                 WHERE log_id = :log_id
                 ORDER BY item_no ASC'
            );
            $detailStmt->execute([':log_id' => $id]);
            $details = $detailStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $errorMessage = '데이터를 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
        $log = null;
    }

function renderDetailRow(array $detail, int $index): string
{
    $preventionData = h((string)($detail['prevention_data'] ?? ''));

    return sprintf(
        '<tr class="detail-row">
            <td>
                <input type="hidden" name="details[%1$d][item_no]" value="%2$d">
                <input type="hidden" name="details[%1$d][prevention_data]" class="js-prevention-data-input" value="%9$s">
                <span class="form-control-plaintext">%2$d</span>
            </td>
            <td><input type="text" name="details[%1$d][work_time]" class="form-control" value="%3$s"></td>
            <td><input type="text" name="details[%1$d][activity]" class="form-control" value="%4$s"></td>
            <td><textarea name="details[%1$d][description]" class="form-control" rows="2">%5$s</textarea></td>
            <td>
                <input type="file" name="details[%1$d][photo_1]" class="form-control">
                %6$s
            </td>
            <td>
                <input type="file" name="details[%1$d][photo_2]" class="form-control">
                %7$s
            </td>
            <td>
                <input type="file" name="details[%1$d][photo_3]" class="form-control">
                %8$s
            </td>
            <td><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
        </tr>',
        $index,
        $detail['item_no'] ?? ($index + 1),
        h($detail['work_time'] ?? ''),
        h($detail['activity'] ?? ''),
        h($detail['description'] ?? ''),
        !empty($detail['photo_1']) ? '<div class="form-text">현재: ' . h($detail['photo_1']) . '</div>' : '',
        !empty($detail['photo_2']) ? '<div class="form-text">현재: ' . h($detail['photo_2']) . '</div>' : '',
        !empty($detail['photo_3']) ? '<div class="form-text">현재: ' . h($detail['photo_3']) . '</div>' : '',
        $preventionData
    );
}
?>
<?php
$pageTitle = '안전관리자 업무일지 수정';
$extraHead = '<style> .detail-table th, .detail-table td { vertical-align: middle; } .form-control-plaintext { margin-bottom: 0; } .form-select { color: #f8fafc; background-color: #162033; border-color: #8b6b2f; } .form-select:focus { color: #f8fafc; background-color: #162033; border-color: #d6a545; box-shadow: 0 0 0 0.2rem rgba(214, 165, 69, 0.2); } .form-select option, .form-select option:checked, .form-select option:hover { color: #0f172a !important; background-color: #f8fafc !important; } .form-select option[value=""] { color: #475569 !important; } .edit-prevention-card { border: 1px solid #334155; border-radius: 12px; padding: 16px; background: #0f172a; } .edit-prevention-card + .edit-prevention-card { margin-top: 12px; } .edit-prevention-title { font-weight: 700; margin-bottom: 10px; } </style>';
include __DIR__ . '/includes/header.php';
?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4">안전관리자 업무일지 수정</h1>
        <a href="view.php?id=<?= h($id) ?>" class="btn btn-secondary">상세보기</a>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-warning"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post" action="update.php" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= h($id) ?>">

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
                        <input type="text" name="manager_name" class="form-control" value="<?= h($values['manager_name']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">현장명</label>
                        <input type="text" name="site_name" class="form-control" value="<?= h($values['site_name']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">작업 위치</label>
                        <input type="text" name="work_location" class="form-control" value="<?= h($values['work_location']) ?>">
                    </div>
                </div>

                <div class="row g-3 mt-3">
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
                        <label class="form-label">작업자 의견사항</label>
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
                            <th style="width: 140px;">사진1</th>
                            <th style="width: 140px;">사진2</th>
                            <th style="width: 140px;">사진3</th>
                            <th style="width: 90px;">삭제</th>
                        </tr>
                        </thead>
                        <tbody id="details-body">
                        <?php if (empty($details)): ?>
                            <?= renderDetailRow([], 0) ?>
                        <?php else: ?>
                            <?php foreach ($details as $index => $detail): ?>
                                <?= renderDetailRow($detail, $index) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">예방대책 상태 수정</div>
            <div class="card-body">
                <div id="prevention-empty" class="text-muted">세부기록에 저장된 예방대책이 없습니다.</div>
                <div id="prevention-sections" class="d-flex flex-column gap-3"></div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label">비고</label>
            <textarea name="remark" class="form-control" rows="3"><?= h($values['remark']) ?></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="view.php?id=<?= h($id) ?>" class="btn btn-secondary">취소</a>
        </div>
    </form>

<script>
    const detailBody = document.getElementById('details-body');
    const addDetailButton = document.getElementById('add-detail');
    const preventionEmpty = document.getElementById('prevention-empty');
    const preventionSections = document.getElementById('prevention-sections');

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parsePreventionData(rawValue) {
        if (!rawValue) {
            return { activity: '', process: '', items: [] };
        }

        try {
            const parsed = JSON.parse(rawValue);
            return {
                activity: String(parsed.activity || ''),
                process: String(parsed.process || ''),
                items: Array.isArray(parsed.items) ? parsed.items.map((item) => ({
                    work_key: String(item.work_key || ''),
                    work_label: String(item.work_label || ''),
                    measure: String(item.measure || ''),
                    status: String(item.status || '')
                })).filter((item) => item.measure !== '') : []
            };
        } catch (error) {
            return { activity: '', process: '', items: [] };
        }
    }

    function updateRowPreventionInput(row, preventionData) {
        const preventionInput = row.querySelector('.js-prevention-data-input');
        if (!preventionInput) {
            return;
        }

        preventionInput.value = JSON.stringify(preventionData);
    }

    function renderPreventionSections() {
        if (!preventionSections || !preventionEmpty) {
            return;
        }

        const rows = Array.from(detailBody.querySelectorAll('.detail-row'));
        const sectionHtml = rows.map((row, index) => {
            const preventionInput = row.querySelector('.js-prevention-data-input');
            const preventionData = parsePreventionData(preventionInput ? preventionInput.value : '');

            if (!preventionData.items.length) {
                return '';
            }

            const rowsHtml = preventionData.items.map((item, preventionIndex) => `
                <tr>
                    <td>${preventionIndex + 1}</td>
                    <td>${escapeHtml(item.work_label || '') || '&nbsp;'}</td>
                    <td>${escapeHtml(item.measure)}</td>
                    <td>
                        <select class="form-select form-select-sm js-prevention-status-select" data-row-index="${index}" data-item-index="${preventionIndex}">
                            <option value="">선택</option>
                            <option value="양호"${item.status === '양호' ? ' selected' : ''}>양호</option>
                            <option value="불량"${item.status === '불량' ? ' selected' : ''}>불량</option>
                            <option value="조치완료"${item.status === '조치완료' ? ' selected' : ''}>조치완료</option>
                            <option value="후속조치필요"${item.status === '후속조치필요' ? ' selected' : ''}>후속조치필요</option>
                            <option value="[평가서 수정필요]"${item.status === '[평가서 수정필요]' ? ' selected' : ''}>[평가서 수정필요]</option>
                            <option value="해당없음"${item.status === '해당없음' ? ' selected' : ''}>해당없음</option>
                        </select>
                    </td>
                </tr>`).join('');

            return `
                <div class="edit-prevention-card">
                    <div class="edit-prevention-title">No.${index + 1} / ${escapeHtml(preventionData.activity || '')} / ${escapeHtml(preventionData.process || '')}</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">No.</th>
                                    <th style="width: 180px;">현장</th>
                                    <th>예방대책</th>
                                    <th style="width: 180px;">상태</th>
                                </tr>
                            </thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>
                    </div>
                </div>`;
        }).filter(Boolean);

        preventionSections.innerHTML = sectionHtml.join('');
        preventionEmpty.classList.toggle('d-none', sectionHtml.length > 0);
    }

    function getDetailRowHtml(index, data = {}) {
        const workTime = data.work_time ?? '';
        const activity = data.activity ?? '';
        const description = data.description ?? '';
        const preventionData = data.prevention_data ?? '';
        const currentPhoto1 = data.photo_1 ? `<div class="form-text">현재: ${data.photo_1}</div>` : '';
        const currentPhoto2 = data.photo_2 ? `<div class="form-text">현재: ${data.photo_2}</div>` : '';
        const currentPhoto3 = data.photo_3 ? `<div class="form-text">현재: ${data.photo_3}</div>` : '';

        return `
            <tr class="detail-row">
                <td>
                    <input type="hidden" name="details[${index}][item_no]" value="${index + 1}">
                    <input type="hidden" name="details[${index}][prevention_data]" class="js-prevention-data-input" value="${escapeHtml(preventionData)}">
                    <span class="form-control-plaintext">${index + 1}</span>
                </td>
                <td><input type="text" name="details[${index}][work_time]" class="form-control" value="${workTime}"></td>
                <td><input type="text" name="details[${index}][activity]" class="form-control" value="${activity}"></td>
                <td><textarea name="details[${index}][description]" class="form-control" rows="2">${description}</textarea></td>
                <td>
                    <input type="file" name="details[${index}][photo_1]" class="form-control">
                    ${currentPhoto1}
                </td>
                <td>
                    <input type="file" name="details[${index}][photo_2]" class="form-control">
                    ${currentPhoto2}
                </td>
                <td>
                    <input type="file" name="details[${index}][photo_3]" class="form-control">
                    ${currentPhoto3}
                </td>
                <td><button type="button" class="btn btn-danger btn-sm delete-row">삭제</button></td>
            </tr>`;
    }

    function reindexRows() {
        const rows = detailBody.querySelectorAll('.detail-row');
        rows.forEach((row, index) => {
            const itemNoInput = row.querySelector('input[type="hidden"][name$="[item_no]"]');
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

    addDetailButton.addEventListener('click', () => {
        const rowCount = detailBody.querySelectorAll('.detail-row').length;
        detailBody.insertAdjacentHTML('beforeend', getDetailRowHtml(rowCount));
        renderPreventionSections();
    });

    detailBody.addEventListener('click', (event) => {
        if (event.target.closest('.delete-row')) {
            const row = event.target.closest('.detail-row');
            if (row) {
                row.remove();
                reindexRows();
                renderPreventionSections();
            }
        }
    });

    preventionSections?.addEventListener('change', (event) => {
        if (!event.target.classList.contains('js-prevention-status-select')) {
            return;
        }

        const rowIndex = Number.parseInt(String(event.target.dataset.rowIndex || '-1'), 10);
        const itemIndex = Number.parseInt(String(event.target.dataset.itemIndex || '-1'), 10);
        const rows = Array.from(detailBody.querySelectorAll('.detail-row'));
        const row = rows[rowIndex] || null;
        if (!row || itemIndex < 0) {
            return;
        }

        const preventionInput = row.querySelector('.js-prevention-data-input');
        const preventionData = parsePreventionData(preventionInput ? preventionInput.value : '');
        if (!Array.isArray(preventionData.items) || !preventionData.items[itemIndex]) {
            return;
        }

        preventionData.items[itemIndex].status = String(event.target.value || '');
        updateRowPreventionInput(row, preventionData);
    });

    renderPreventionSections();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
