<?php
require_once __DIR__ . '/../risk_assessment/db_config.php';

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

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
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

if ($id === false || $id === null) {
    $errorMessage = '유효한 업무일지 ID가 전달되지 않았습니다.';
} else {
    try {
        $pdo = getDB();

        // safety_manager_log에서 단일 항목을 조회합니다.
        $stmt = $pdo->prepare(
            'SELECT id, log_date, manager_name, site_name, work_location, weather, subject, summary, remark
             FROM safety_manager_log
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $log = $stmt->fetch();

        if (!$log) {
            $errorMessage = '요청하신 업무일지를 찾을 수 없습니다.';
            $log = null;
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
                'SELECT id, item_no, work_time, activity, description, status, photo_1, photo_2
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
}

function renderDetailRow(array $detail, int $index): string
{
    return sprintf(
        '<tr class="detail-row">
            <td>
                <input type="hidden" name="details[%1$d][item_no]" value="%2$d">
                <span class="form-control-plaintext">%2$d</span>
            </td>
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
            <td>
                <input type="file" name="details[%1$d][photo_1]" class="form-control">
                %10$s
            </td>
            <td>
                <input type="file" name="details[%1$d][photo_2]" class="form-control">
                %11$s
            </td>
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
        ($detail['status'] ?? '') === '후속조치필요' ? ' selected' : '',
        !empty($detail['photo_1']) ? '<div class="form-text">현재: ' . h($detail['photo_1']) . '</div>' : '',
        !empty($detail['photo_2']) ? '<div class="form-text">현재: ' . h($detail['photo_2']) . '</div>' : ''
    );
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>안전관리자 업무일지 수정</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-VnY9Xl60G7eusM0ZyEJ+X8LwKUQ/yqPn2rGHXeFQ0WlQg5KL6N37pP3cT7QeFk0I" crossorigin="anonymous">
    <style>
        .detail-table th, .detail-table td { vertical-align: middle; }
        .form-control-plaintext { margin-bottom: 0; }
    </style>
</head>
<body>
<div class="container py-4">
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
                                <?= renderDetailRow($detail, $index) ?>
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
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="view.php?id=<?= h($id) ?>" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<script>
    const detailBody = document.getElementById('details-body');
    const addDetailButton = document.getElementById('add-detail');

    function getDetailRowHtml(index, data = {}) {
        const workTime = data.work_time ?? '';
        const activity = data.activity ?? '';
        const description = data.description ?? '';
        const status = data.status ?? '';
        const currentPhoto1 = data.photo_1 ? `<div class="form-text">현재: ${data.photo_1}</div>` : '';
        const currentPhoto2 = data.photo_2 ? `<div class="form-text">현재: ${data.photo_2}</div>` : '';

        return `
            <tr class="detail-row">
                <td>
                    <input type="hidden" name="details[${index}][item_no]" value="${index + 1}">
                    <span class="form-control-plaintext">${index + 1}</span>
                </td>
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
                <td>
                    <input type="file" name="details[${index}][photo_1]" class="form-control">
                    ${currentPhoto1}
                </td>
                <td>
                    <input type="file" name="details[${index}][photo_2]" class="form-control">
                    ${currentPhoto2}
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
    });

    detailBody.addEventListener('click', (event) => {
        if (event.target.closest('.delete-row')) {
            const row = event.target.closest('.detail-row');
            if (row) {
                row.remove();
                reindexRows();
            }
        }
    });
</script>
</body>
</html>
