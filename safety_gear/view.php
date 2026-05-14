<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function detail_value(array $item, string $key, string $fallback = '-'): string
{
    $value = sg_normalize_text($item[$key] ?? '');
    return $value !== '' ? $value : $fallback;
}

$pdo = sg_get_pdo();
$id = sg_normalize_text($_GET['id'] ?? '');
$identifier = sg_normalize_text($_GET['identifier'] ?? '');

$item = null;
if ($id !== '') {
    $item = sg_fetch_item_by_uid($pdo, $id);
}
if ($item === null && $identifier !== '') {
    $item = sg_fetch_item_by_identifier($pdo, $identifier);
}

$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? '')
    . ($_SERVER['REQUEST_URI'] ?? '');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>보호구 상세</title>
    <style>
        :root {
            --bg: #edf4f8;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #132238;
            --muted: #64748b;
            --accent: #0f766e;
            --soft: #f8fafc;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Malgun Gothic", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 20%),
                linear-gradient(180deg, #f8fbfd 0%, var(--bg) 100%);
        }

        .page {
            width: min(1100px, calc(100vw - 24px));
            margin: 18px auto 28px;
            display: grid;
            gap: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
            padding: 18px;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 240px;
            gap: 18px;
            align-items: center;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #ecfeff;
            color: #155e75;
            font-size: 12px;
            font-weight: 700;
        }

        h1 {
            margin: 10px 0 6px;
            font-size: 30px;
        }

        .lead {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .qr-wrap {
            display: grid;
            place-items: center;
            min-height: 220px;
            border: 1px dashed var(--line);
            border-radius: 18px;
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
            padding: 14px;
        }

        .qr-wrap img {
            width: 100%;
            max-width: 210px;
            height: auto;
            display: block;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--soft);
            padding: 14px;
        }

        .label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .value {
            font-size: 15px;
            line-height: 1.6;
            word-break: break-word;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            font-size: 14px;
        }

        .button.secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .history-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .history-item {
            border-left: 4px solid #14b8a6;
            background: #f8fafc;
            border-radius: 0 14px 14px 0;
            padding: 12px 14px;
        }

        .history-meta {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
            line-height: 1.8;
        }

        .url-box {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid var(--line);
            color: #334155;
            font-size: 12px;
            word-break: break-all;
        }

        @media (max-width: 820px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php if ($item === null): ?>
            <section class="panel">
                <div class="empty">
                    요청한 보호구 정보를 찾지 못했습니다.<br>
                    NFC 또는 QR에 저장된 주소가 올바른지 확인해 주세요.
                    <div class="actions" style="justify-content:center;">
                        <a class="button secondary" href="/safety_gear/index.php">관리 페이지로 이동</a>
                    </div>
                </div>
            </section>
        <?php else: ?>
            <section class="panel hero">
                <div>
                    <span class="eyebrow"><?= h(detail_value($item, 'identifier_type')) ?></span>
                    <h1><?= h(detail_value($item, 'gear_type')) ?></h1>
                    <p class="lead">
                        <?= h(detail_value($item, 'item_name')) ?>
                        <?php if (detail_value($item, 'model_name', '') !== ''): ?>
                            / <?= h(detail_value($item, 'model_name')) ?>
                        <?php endif; ?>
                    </p>
                    <div class="actions">
                        <a class="button" href="/safety_gear/index.php">관리 페이지</a>
                        <a class="button secondary" href="<?= h('/safety_gear/qr.php?data=' . rawurlencode($currentUrl)) ?>" target="_blank" rel="noopener">상세 페이지 QR 보기</a>
                    </div>
                    <div class="url-box"><?= h($currentUrl) ?></div>
                </div>
                <div class="qr-wrap">
                    <img src="<?= h('/safety_gear/qr.php?data=' . rawurlencode($currentUrl)) ?>" alt="상세 페이지 QR">
                </div>
            </section>

            <section class="panel">
                <div class="grid">
                    <div class="card">
                        <span class="label">관리키</span>
                        <div class="value"><?= h(detail_value($item, 'id')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">식별값</span>
                        <div class="value"><?= h(detail_value($item, 'identifier_value')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">품명</span>
                        <div class="value"><?= h(detail_value($item, 'item_name')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">모델</span>
                        <div class="value"><?= h(detail_value($item, 'model_name')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">구매처</span>
                        <div class="value"><?= h(detail_value($item, 'purchase_vendor')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">구매가격</span>
                        <div class="value"><?= h(detail_value($item, 'purchase_price')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">구매일</span>
                        <div class="value"><?= h(detail_value($item, 'purchased_at')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">상태</span>
                        <div class="value"><?= h(detail_value($item, 'status')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">지급자</span>
                        <div class="value"><?= h(detail_value($item, 'assigned_employee_name')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">지급팀</span>
                        <div class="value"><?= h(detail_value($item, 'assigned_team')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">지급일시</span>
                        <div class="value"><?= h(detail_value($item, 'assigned_at')) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">최종 수정일시</span>
                        <div class="value"><?= h(detail_value($item, 'updated_at')) ?></div>
                    </div>
                    <div class="card" style="grid-column: 1 / -1;">
                        <span class="label">메모</span>
                        <div class="value"><?= nl2br(h(detail_value($item, 'notes'))) ?></div>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2>추적 이력</h2>
                <div class="history-list">
                    <?php if (empty($item['history'])): ?>
                        <div class="history-item">
                            <div class="history-meta">이력이 없습니다.</div>
                            <div>아직 등록된 추적 이력이 없습니다.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($item['history'] as $history): ?>
                            <div class="history-item">
                                <div class="history-meta">
                                    <?= h(sg_normalize_text($history['timestamp'] ?? '')) ?>
                                    /
                                    <?= h(sg_normalize_text($history['type'] ?? '')) ?>
                                </div>
                                <div><?= nl2br(h(sg_normalize_text($history['note'] ?? ''))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
